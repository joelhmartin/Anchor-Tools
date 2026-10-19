<?php
/**
 * Library permissions: who may view or manage a folder, file, link or video.
 *
 * Ported from Anchor Private File Manager 2.17.0 (get_effective_capability and friends) with the algorithm, SQL and
 * policy behaviour unchanged. The one structural difference: every method takes an explicit user id (null means the
 * current user), so REST requests authenticated by Application Password get the right answer without a cookie.
 *
 * Resolution: administrators manage everything; a folder's owner manages it; otherwise the best grant found while
 * walking from the folder up to the root (capped at 50 levels, cycle-safe) wins. A file's own grant, when present,
 * takes precedence over its folder's answer. Grants only ever add rights: there is no deny row.
 *
 * Memo. Listing a folder resolves a capability for every row, and each resolution walks the same ancestor chain.
 * with_memo() opens a scope (begin_memo()/end_memo() are private) inside which answers are cached and the whole permission model is preloaded
 * into an Anchor_FM_Permission_Index. It is deliberately NOT a request-wide cache: it is opened only around loops
 * that read, so a write in the same request can never be shadowed by a stale answer. Every key that depends on the
 * user carries the user id, so a memo warmed for one user can never answer for another.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Permissions {

	public const META_PRODUCT_DOCS = '_anchor_pd_docs';

	/** Ancestor-walk depth cap. */
	private const MAX_DEPTH = 50;

	/** @var array<string,mixed> */
	private static array $memo = [];
	private static bool $memo_on = false;

	/** Loaded for the life of a memo scope only. */
	private static ?\Anchor_FM_Permission_Index $index = null;

	/** Cached result of store_exceeds_preload_max() for this request (null = not asked yet). */
	private static ?bool $store_too_large = null;

	/** file id => list of [product_id, expires] entries; built once per request (see can_view_file_via_product). */
	private static ?array $product_map = null;

	// ---- Public API -----------------------------------------------------------------------------------------

	/** 'none' | 'view' | 'manage' for a folder. */
	public static function folder_capability( int $folder_id, ?int $user_id = null ): string {
		return self::effective_capability( self::uid( $user_id ), 'folder', $folder_id );
	}

	/** 'none' | 'view' | 'manage' for a file, from the file's own grant or its folder. Does not include product grants. */
	public static function file_capability( int $file_id, ?int $user_id = null ): string {
		return self::effective_capability( self::uid( $user_id ), 'file', $file_id );
	}

	public static function can_view_folder( int $folder_id, ?int $user_id = null ): bool {
		return self::rank( self::folder_capability( $folder_id, $user_id ) ) >= 1;
	}

	public static function can_manage_folder( int $folder_id, ?int $user_id = null ): bool {
		return self::rank( self::folder_capability( $folder_id, $user_id ) ) >= 3;
	}

	/** Uploading is an administrator right in the plugin; the folder id is accepted for a future per-folder rule. */
	public static function can_upload_to_folder( int $folder_id, ?int $user_id = null ): bool {
		return self::is_admin( self::uid( $user_id ) );
	}

	/** Folder or file grant, or a paid Woo product that lists the file as a document. */
	public static function can_view_file( int $file_id, ?int $user_id = null ): bool {
		$uid = self::uid( $user_id );
		if ( self::rank( self::effective_capability( $uid, 'file', $file_id ) ) >= 1 ) {
			return true;
		}
		return self::can_view_file_via_product( $uid, $file_id );
	}

	public static function can_manage_file( int $file_id, ?int $user_id = null ): bool {
		return self::rank( self::file_capability( $file_id, $user_id ) ) >= 3;
	}

	public static function can_view_link( int $link_id, ?int $user_id = null ): bool {
		$folder_id = self::entity_folder_id( 'link', $link_id );
		return null !== $folder_id && self::can_view_folder( $folder_id, $user_id );
	}

	public static function can_manage_link( int $link_id, ?int $user_id = null ): bool {
		$folder_id = self::entity_folder_id( 'link', $link_id );
		return null !== $folder_id && self::can_manage_folder( $folder_id, $user_id );
	}

	public static function can_view_video( int $video_id, ?int $user_id = null ): bool {
		$folder_id = self::entity_folder_id( 'video', $video_id );
		return null !== $folder_id && self::can_view_folder( $folder_id, $user_id );
	}

	public static function can_manage_video( int $video_id, ?int $user_id = null ): bool {
		$folder_id = self::entity_folder_id( 'video', $video_id );
		return null !== $folder_id && self::can_manage_folder( $folder_id, $user_id );
	}

	/** Whether the user has any view grant at all (user row, role row or matching policy); gates asset loading. */
	public static function can_access_anything( ?int $user_id = null ): bool {
		global $wpdb;
		$uid = self::uid( $user_id );
		if ( self::is_admin( $uid ) ) {
			return true;
		}
		$perms = Schema::table( 'permissions' );
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(1) FROM {$perms} WHERE subject_type = 'user' AND capability = 'view' AND subject_key = %s",
			(string) $uid
		) );
		if ( $count > 0 ) {
			return true;
		}

		$role_keys = \array_map( 'sanitize_key', self::user_roles_lower( $uid ) );
		if ( $role_keys ) {
			$placeholders = \implode( ',', \array_fill( 0, \count( $role_keys ), '%s' ) );
			$query        = "SELECT COUNT(1) FROM {$perms} WHERE subject_type = 'role' AND capability = 'view' AND subject_key IN ({$placeholders})";
			$count        = (int) $wpdb->get_var( \call_user_func_array( [ $wpdb, 'prepare' ], \array_merge( [ $query ], $role_keys ) ) );
			if ( $count > 0 ) {
				return true;
			}
		}

		$policies = Schema::table( 'permission_policies' );
		foreach ( (array) $wpdb->get_col( "SELECT policy FROM {$policies} WHERE capability = 'view'" ) as $raw ) {
			if ( \Anchor_FM_Permission_Policy::evaluate( \Anchor_FM_Permission_Policy::normalize( $raw ), self::policy_context( $uid ), \current_time( 'Y-m-d' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Stored view/manage policy for an entity, canonical form (never filtered against current roles: AFM-02). */
	public static function policy( string $entity_type, int $entity_id, string $capability = 'view' ): array {
		return self::memoized( 'policy:' . $entity_type . ':' . $entity_id . ':' . $capability, static function () use ( $entity_type, $entity_id, $capability ) {
			if ( self::$index ) {
				return \Anchor_FM_Permission_Policy::normalize( self::$index->policy( $entity_type, $entity_id, $capability ) ?: [] );
			}
			global $wpdb;
			$policies = Schema::table( 'permission_policies' );
			$raw      = $wpdb->get_var( $wpdb->prepare(
				"SELECT policy FROM {$policies} WHERE entity_type = %s AND entity_id = %d AND capability = %s",
				$entity_type, $entity_id, $capability
			) );
			return \Anchor_FM_Permission_Policy::normalize( $raw ?: [] );
		} );
	}

	/** Whether any view permission row or policy exists on an entity. */
	public static function entity_has_view_permissions( string $entity_type, int $entity_id ): bool {
		global $wpdb;
		$perms = Schema::table( 'permissions' );
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(1) FROM {$perms} WHERE entity_type = %s AND entity_id = %d AND capability = 'view'",
			$entity_type, $entity_id
		) );
		if ( $count > 0 ) {
			return true;
		}
		$policies = Schema::table( 'permission_policies' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(1) FROM {$policies} WHERE entity_type = %s AND entity_id = %d AND capability = 'view'",
			$entity_type, $entity_id
		) ) > 0;
	}

	// ---- Memo scope -----------------------------------------------------------------------------------------

	/** Open a read-only memo scope and preload the permission model. Private: with_memo() is the only entry, so a scope cannot be left open. */
	private static function begin_memo(): void {
		self::$memo    = [];
		self::$memo_on = true;
		self::load_index();
	}

	private static function end_memo(): void {
		self::$memo    = [];
		self::$memo_on = false;
		self::$index   = null;
	}

	public static function memo_active(): bool {
		return self::$memo_on;
	}

	/** Run $fn inside a memo scope, opening one only if none is open (so callers can nest). */
	public static function with_memo( callable $fn ) {
		if ( self::$memo_on ) {
			return $fn();
		}
		self::begin_memo();
		try {
			return $fn();
		} finally {
			self::end_memo();
		}
	}

	/** Drop the memo scope and every per-request cache. For tests and after bulk writes. */
	public static function reset_memo(): void {
		self::end_memo();
		self::$store_too_large = null;
		self::$product_map     = null;
	}

	/** Resolve $key through the memo when a scope is open, otherwise just compute. Shared with Repository. */
	public static function memoized( string $key, callable $compute ) {
		if ( ! self::$memo_on ) {
			return $compute();
		}
		if ( ! \array_key_exists( $key, self::$memo ) ) {
			self::$memo[ $key ] = $compute();
		}
		return self::$memo[ $key ];
	}

	/**
	 * Whether the store is too big to hold per-entity data in memory. Counted through a bounded subquery rather
	 * than COUNT(*): the only question is whether the ceiling is crossed.
	 */
	public static function store_exceeds_preload_max(): bool {
		if ( null !== self::$store_too_large ) {
			return self::$store_too_large;
		}
		global $wpdb;
		$cap   = self::contents_preload_max();
		$limit = $cap + 1;
		$total = 0;
		foreach ( [ 'folders', 'files', 'links', 'videos' ] as $t ) {
			$table  = Schema::table( $t );
			$total += (int) $wpdb->get_var( "SELECT COUNT(1) FROM (SELECT 1 FROM {$table} LIMIT {$limit}) c" );
			if ( $total > $cap ) {
				break;
			}
		}
		self::$store_too_large = $total > $cap;
		return self::$store_too_large;
	}

	/** Ceiling on the preload, in rows across the whole store. Filterable because the right number is per site. */
	public static function contents_preload_max(): int {
		return (int) \apply_filters( 'anchor_fm_contents_preload_max', 5000 );
	}

	// ---- Product documents ---------------------------------------------------------------------------------

	/** Documents attached to a product: list of [fileId, title, expires]. */
	public static function product_docs( int $product_id ): array {
		$docs = \get_post_meta( $product_id, self::META_PRODUCT_DOCS, true );
		if ( ! \is_array( $docs ) ) {
			return [];
		}
		$out = [];
		foreach ( $docs as $doc ) {
			if ( ! \is_array( $doc ) ) {
				continue;
			}
			$file_id = isset( $doc['fileId'] ) ? (int) $doc['fileId'] : 0;
			if ( $file_id <= 0 ) {
				continue;
			}
			$out[] = [
				'fileId'  => $file_id,
				'title'   => isset( $doc['title'] ) ? \sanitize_text_field( (string) $doc['title'] ) : '',
				'expires' => isset( $doc['expires'] ) ? \sanitize_text_field( (string) $doc['expires'] ) : '',
			];
		}
		return $out;
	}

	public static function doc_is_expired( $expires ): bool {
		if ( empty( $expires ) ) {
			return false;
		}
		$ts = \strtotime( $expires . ' 23:59:59' );
		return $ts ? $ts < \current_time( 'timestamp' ) : false; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	}

	/**
	 * Order statuses that entitle a buyer to a product's documents: paid ones only. "On hold" means payment is not
	 * confirmed yet, so it does not unlock documents (AFM-04). A site that wants access earlier says so via filter.
	 *
	 * @return string[]
	 */
	public static function entitled_order_statuses(): array {
		$statuses = (array) \apply_filters( 'anchor_fm_entitled_order_statuses', [ 'wc-completed', 'wc-processing' ] );
		return \array_values( \array_filter( \array_map( 'strval', $statuses ) ) );
	}

	public static function user_has_product( int $user_id, int $product_id ): bool {
		if ( ! \function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		$statuses = self::entitled_order_statuses();
		if ( ! $statuses ) {
			return false;
		}
		$orders = \wc_get_orders( [ 'customer_id' => $user_id, 'limit' => -1, 'status' => $statuses ] );
		foreach ( (array) $orders as $order ) {
			if ( ! \is_a( $order, 'WC_Order' ) ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				if ( ! \is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				if ( (int) $item->get_product_id() === $product_id || (int) $item->get_variation_id() === $product_id ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function can_view_file_via_product( int $user_id, int $file_id ): bool {
		if ( ! \function_exists( 'wc_get_products' ) ) {
			return false;
		}
		if ( null === self::$product_map ) {
			self::$product_map = [];
			$products = \get_posts( [
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'meta_key'       => self::META_PRODUCT_DOCS, // phpcs:ignore WordPress.DB.SlowDBQuery
				'post_status'    => 'publish',
			] );
			foreach ( (array) $products as $p ) {
				foreach ( self::product_docs( (int) $p->ID ) as $doc ) {
					self::$product_map[ (int) $doc['fileId'] ][] = [ 'product_id' => (int) $p->ID, 'expires' => $doc['expires'] ];
				}
			}
		}
		foreach ( self::$product_map[ $file_id ] ?? [] as $entry ) {
			if ( ! self::doc_is_expired( $entry['expires'] ) && self::user_has_product( $user_id, (int) $entry['product_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	// ---- Resolution -----------------------------------------------------------------------------------------

	private static function uid( ?int $user_id ): int {
		return null === $user_id ? (int) \get_current_user_id() : $user_id;
	}

	private static function is_admin( int $user_id ): bool {
		return $user_id > 0 && \user_can( $user_id, 'administrator' );
	}

	private static function rank( string $cap ): int {
		switch ( $cap ) {
			case 'manage':
				return 3;
			case 'view':
				return 1;
			default:
				return 0;
		}
	}

	private static function rank_to_cap( int $rank ): string {
		if ( $rank >= 3 ) {
			return 'manage';
		}
		return $rank >= 1 ? 'view' : 'none';
	}

	/** @return string[] */
	private static function user_roles_lower( int $user_id ): array {
		$u = \get_user_by( 'id', $user_id );
		return $u ? \array_values( \array_map( 'strtolower', (array) $u->roles ) ) : [];
	}

	private static function policy_context( int $user_id ): array {
		return [ 'userId' => $user_id, 'roles' => \array_map( 'sanitize_key', self::user_roles_lower( $user_id ) ) ];
	}

	private static function policy_matches( int $user_id, string $entity_type, int $entity_id ): bool {
		$policy = self::policy( $entity_type, $entity_id, 'view' );
		if ( \Anchor_FM_Permission_Policy::rule_count( $policy ) <= 0 ) {
			return false;
		}
		return \Anchor_FM_Permission_Policy::evaluate( $policy, self::policy_context( $user_id ), \current_time( 'Y-m-d' ) );
	}

	private static function effective_capability( int $user_id, string $entity_type, int $entity_id ): string {
		if ( self::is_admin( $user_id ) ) {
			return 'manage';
		}

		if ( 'folder' === $entity_type ) {
			$folder = self::folder_node( $entity_id );
			if ( ! $folder ) {
				return 'none';
			}
			if ( ! empty( $folder->owner_user_id ) && (int) $folder->owner_user_id === $user_id ) {
				return 'manage';
			}
			return self::folder_cap_memo( $user_id, $folder );
		}

		if ( 'file' === $entity_type ) {
			$folder_id = self::entity_folder_id( 'file', $entity_id );
			if ( null === $folder_id ) {
				return 'none';
			}
			// A file-level grant takes precedence over folder inheritance when present.
			$cap = self::direct_cap_memo( $user_id, 'file', $entity_id );
			if ( 'none' !== $cap ) {
				return $cap;
			}
			$folder = self::folder_node( $folder_id );
			return $folder ? self::folder_cap_memo( $user_id, $folder ) : 'none';
		}

		return 'none';
	}

	private static function folder_cap_memo( int $user_id, \stdClass $folder ): string {
		return self::memoized( 'foldercap:' . $user_id . ':' . (int) $folder->id, static function () use ( $user_id, $folder ) {
			return self::folder_cap_walk( $user_id, $folder );
		} );
	}

	/**
	 * Walk a folder to the root taking the best capability found on the way. The depth cap and cycle guard both
	 * matter: a parent_id loop in the data would otherwise spin here whether or not the rows come from memory.
	 */
	private static function folder_cap_walk( int $user_id, \stdClass $folder ): string {
		$seen   = [];
		$best   = 0;
		$depth  = 0;
		$fid    = (int) $folder->id;
		$parent = (int) $folder->parent_id;
		$owner  = (int) $folder->owner_user_id;
		$have   = true;

		while ( $have && $depth < self::MAX_DEPTH ) {
			++$depth;
			if ( isset( $seen[ $fid ] ) ) {
				break;
			}
			$seen[ $fid ] = true;

			if ( $owner && $owner === $user_id ) {
				$best = \max( $best, 3 );
				break;
			}

			$best = \max( $best, self::rank( self::direct_cap_memo( $user_id, 'folder', $fid ) ) );

			if ( $parent ) {
				$next = self::folder_ancestry( $parent );
				if ( null === $next ) {
					break;
				}
				$fid    = $parent;
				$parent = (int) $next['parent'];
				$owner  = (int) $next['owner'];
			} else {
				$have = false;
			}
		}
		return self::rank_to_cap( $best );
	}

	private static function direct_cap_memo( int $user_id, string $entity_type, int $entity_id ): string {
		return self::memoized( 'direct:' . $user_id . ':' . $entity_type . ':' . $entity_id, static function () use ( $user_id, $entity_type, $entity_id ) {
			return self::direct_cap( $user_id, $entity_type, $entity_id );
		} );
	}

	private static function direct_cap( int $user_id, string $entity_type, int $entity_id ): string {
		$role_keys = \array_map( 'sanitize_key', self::user_roles_lower( $user_id ) );
		$best      = 0;

		// A user row granting 'manage' also grants view; ranking sorts it out, so no capability filter here.
		foreach ( self::direct_user_capabilities( $entity_type, $entity_id, (string) $user_id ) as $cap ) {
			$best = \max( $best, self::rank( $cap ) );
		}
		if ( self::policy_matches( $user_id, $entity_type, $entity_id ) ) {
			$best = \max( $best, 1 );
		}
		foreach ( self::direct_role_view_capabilities( $entity_type, $entity_id, $role_keys ) as $cap ) {
			$best = \max( $best, self::rank( $cap ) );
		}
		return self::rank_to_cap( $best );
	}

	/** Index when loaded, otherwise the query it stands in for. */
	private static function direct_user_capabilities( string $entity_type, int $entity_id, string $user_key ): array {
		if ( self::$index ) {
			return self::$index->user_capabilities( $entity_type, $entity_id, $user_key );
		}
		global $wpdb;
		$perms = Schema::table( 'permissions' );
		return (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT capability FROM {$perms} WHERE entity_type = %s AND entity_id = %d AND subject_type = 'user' AND subject_key = %s",
			$entity_type, $entity_id, $user_key
		) );
	}

	/** Index when loaded, otherwise the query it stands in for. */
	private static function direct_role_view_capabilities( string $entity_type, int $entity_id, array $role_keys ): array {
		if ( ! $role_keys ) {
			return [];
		}
		if ( self::$index ) {
			return self::$index->role_view_capabilities( $entity_type, $entity_id, $role_keys );
		}
		global $wpdb;
		$perms        = Schema::table( 'permissions' );
		$placeholders = \implode( ',', \array_fill( 0, \count( $role_keys ), '%s' ) );
		$query        = "SELECT capability FROM {$perms} WHERE entity_type = %s AND entity_id = %d AND subject_type = 'role' AND capability = 'view' AND subject_key IN ({$placeholders})";
		return (array) $wpdb->get_col( \call_user_func_array( [ $wpdb, 'prepare' ], \array_merge( [ $query, $entity_type, $entity_id ], $role_keys ) ) );
	}

	/**
	 * Folder an entity inherits from, or null when it does not exist. The index is trusted only for a type it holds
	 * in full; otherwise a type it does not track would read as "no such file" and deny access.
	 */
	private static function entity_folder_id( string $entity_type, int $entity_id ): ?int {
		if ( self::$index && self::$index->tracks( $entity_type ) ) {
			return self::$index->entity_folder( $entity_type, $entity_id );
		}
		switch ( $entity_type ) {
			case 'file':
				$row = Repository::file_row( $entity_id );
				break;
			case 'link':
				$row = Repository::link_row( $entity_id );
				break;
			case 'video':
				$row = Repository::video_row( $entity_id );
				break;
			default:
				return null;
		}
		return $row ? (int) $row->folder_id : null;
	}

	/** The three fields the capability walk reads, without fetching a whole row. */
	private static function folder_node( int $folder_id ): ?\stdClass {
		$node = self::folder_ancestry( $folder_id );
		if ( null === $node ) {
			return null;
		}
		$obj                = new \stdClass();
		$obj->id            = $folder_id;
		$obj->parent_id     = (int) $node['parent'];
		$obj->owner_user_id = (int) $node['owner'];
		return $obj;
	}

	/** @return array{parent:int,owner:int}|null */
	private static function folder_ancestry( int $folder_id ): ?array {
		if ( self::$index ) {
			$node = self::$index->folder( $folder_id );
			if ( null !== $node ) {
				return $node;
			}
			// Index miss: a folder created since it was built. Fall through to the row.
		}
		$row = Repository::folder_row( $folder_id );
		return $row ? [ 'parent' => (int) $row->parent_id, 'owner' => (int) $row->owner_user_id ] : null;
	}

	/**
	 * Pull the whole permission model into memory in a handful of queries. Safe to hold for a scope's lifetime for
	 * the same reason the memo is: it is loaded only around loops that read. A failed load abandons the index (an
	 * index built from a failed query would confidently say "no permissions exist" and deny everyone).
	 */
	private static function load_index(): void {
		global $wpdb;
		$index = new \Anchor_FM_Permission_Index();

		$rows = $wpdb->get_results( 'SELECT id, parent_id, owner_user_id FROM ' . Schema::table( 'folders' ) );
		if ( $wpdb->last_error ) {
			self::$index = null;
			return;
		}
		foreach ( (array) $rows as $r ) {
			$index->add_folder( (int) $r->id, (int) $r->parent_id, (int) $r->owner_user_id );
		}

		$rows = $wpdb->get_results( 'SELECT entity_type, entity_id, subject_type, subject_key, capability FROM ' . Schema::table( 'permissions' ) );
		if ( $wpdb->last_error ) {
			self::$index = null;
			return;
		}
		foreach ( (array) $rows as $r ) {
			$index->add_permission( $r->entity_type, (int) $r->entity_id, $r->subject_type, $r->subject_key, $r->capability );
		}

		// Tolerant, unlike the two above: the per-entity lookup this replaces also treated a missing policies table
		// as "no policy", so an error here is the existing behaviour rather than a new denial.
		$rows = $wpdb->get_results( 'SELECT entity_type, entity_id, capability, policy FROM ' . Schema::table( 'permission_policies' ) );
		foreach ( (array) $rows as $r ) {
			$index->add_policy( $r->entity_type, (int) $r->entity_id, $r->capability, $r->policy );
		}

		// Which folder each entity inherits from. The one part that grows with content, so it has a ceiling: above
		// it the maps are not built and entity_folder_id() queries per entity (the pre-2.14.0 behaviour).
		if ( ! self::store_exceeds_preload_max() ) {
			foreach ( [ 'file' => 'files', 'link' => 'links', 'video' => 'videos' ] as $type => $table ) {
				$rows = $wpdb->get_results( 'SELECT id, folder_id FROM ' . Schema::table( $table ) );
				if ( $wpdb->last_error ) {
					continue; // Left untracked: tracking it would make every entity of this type read as missing.
				}
				foreach ( (array) $rows as $r ) {
					$index->add_entity_folder( $type, (int) $r->id, (int) $r->folder_id );
				}
				$index->mark_tracked( $type );
			}
		}
		self::$index = $index;
	}
}
