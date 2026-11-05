<?php
/**
 * Folders other modules set up for themselves, without an admin in the portal: find or create a folder, grant a
 * role view access, set its "available until" date, and read back who played what inside it.
 *
 * The portal's own AJAX handlers stay the way an admin does these things; the deadline write is shared with them
 * so there is one place that decides what a valid date is.
 *
 * @package AnchorTools\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Provisioning {

	/** The folder with this exact name under the parent, created if there is none. Returns 0 on failure. */
	public static function find_or_create_folder( int $parent_id, string $name ): int {
		$existing = self::folder_id_by_name( $parent_id, $name );
		return $existing > 0 ? $existing : self::create_folder( $parent_id, $name );
	}

	/** The folder with this exact name under the parent, or 0. */
	public static function folder_id_by_name( int $parent_id, string $name ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . Schema::table( 'folders' ) . ' WHERE parent_id = %d AND name = %s ORDER BY id ASC LIMIT 1',
			$parent_id,
			self::folder_name( $name )
		) );
	}

	public static function create_folder( int $parent_id, string $name ): int {
		global $wpdb;
		$now = \current_time( 'mysql' );
		$ok  = $wpdb->insert( Schema::table( 'folders' ), [
			'parent_id'     => $parent_id,
			'name'          => self::folder_name( $name ),
			'owner_user_id' => 0,
			'is_private'    => 0,
			'created_by'    => \get_current_user_id(),
			'created_at'    => $now,
			'updated_at'    => $now,
		] );
		if ( ! $ok ) {
			return 0;
		}
		$folder_id = (int) $wpdb->insert_id;
		Activity::log( 'create_folder', 'folder', $folder_id, [ 'parent_id' => $parent_id, 'name' => $name ], \get_current_user_id() );
		return $folder_id;
	}

	/** Let a role view the folder and everything beneath it. Other grants are left alone. */
	public static function grant_role_view( int $folder_id, string $role ): bool {
		global $wpdb;
		$role  = \sanitize_key( $role );
		$table = Schema::table( 'permissions' );
		$have  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(1) FROM {$table} WHERE entity_type = 'folder' AND entity_id = %d AND subject_type = 'role' AND subject_key = %s AND capability = 'view'",
			$folder_id,
			$role
		) );
		if ( $have > 0 ) {
			return true;
		}
		return (bool) $wpdb->insert( $table, [
			'entity_type'  => 'folder',
			'entity_id'    => $folder_id,
			'subject_type' => 'role',
			'subject_key'  => $role,
			'capability'   => 'view',
			'created_by'   => \get_current_user_id(),
			'created_at'   => \current_time( 'mysql' ),
		] );
	}

	public static function revoke_role_view( int $folder_id, string $role ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'permissions' ), [
			'entity_type'  => 'folder',
			'entity_id'    => $folder_id,
			'subject_type' => 'role',
			'subject_key'  => \sanitize_key( $role ),
			'capability'   => 'view',
		] );
		Permissions::reset_memo();
	}

	/** First published page carrying a portal shortcode, or ''. For sites with no WooCommerce account page. */
	public static function portal_page_url(): string {
		global $wpdb;
		$id = (int) $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'
			AND ( post_content LIKE '%[anchor\\_account\\_portal%' OR post_content LIKE '%[anchor\\_file\\_manager%' ) ORDER BY ID ASC LIMIT 1"
		);
		return $id > 0 ? (string) \get_permalink( $id ) : '';
	}

	/**
	 * Set the last day a folder is available; '' clears it. Returns false for anything that is not a real date, so a
	 * typo can never quietly remove a deadline.
	 */
	public static function set_available_until( int $folder_id, string $until ): bool {
		global $wpdb;
		$until = \trim( $until );
		if ( '' !== $until && ! self::is_date( $until ) ) {
			return false;
		}
		$written = $wpdb->update(
			Schema::table( 'folders' ),
			[ 'available_until' => '' === $until ? null : $until, 'updated_at' => \current_time( 'mysql' ) ],
			[ 'id' => $folder_id ]
		);
		Permissions::reset_memo();
		return false !== $written;
	}

	public static function available_until( int $folder_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT available_until FROM ' . Schema::table( 'folders' ) . ' WHERE id = %d',
			$folder_id
		) );
	}

	public static function folder_exists( int $folder_id ): bool {
		global $wpdb;
		return $folder_id > 0 && (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . Schema::table( 'folders' ) . ' WHERE id = %d',
			$folder_id
		) );
	}

	/** Delete a folder only if nothing has been put in it. Returns whether it was deleted. */
	public static function delete_if_empty( int $folder_id ): bool {
		global $wpdb;
		foreach ( [ 'folders' => 'parent_id', 'files' => 'folder_id', 'links' => 'folder_id', 'videos' => 'folder_id' ] as $table => $column ) {
			$count = $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(1) FROM ' . Schema::table( $table ) . " WHERE {$column} = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$folder_id
			) );
			if ( null === $count || (int) $count > 0 ) {
				return false;
			}
		}
		$wpdb->delete( Schema::table( 'permissions' ), [ 'entity_type' => 'folder', 'entity_id' => $folder_id ] );
		$wpdb->delete( Schema::table( 'permission_policies' ), [ 'entity_type' => 'folder', 'entity_id' => $folder_id ] );
		return (bool) $wpdb->delete( Schema::table( 'folders' ), [ 'id' => $folder_id ] );
	}

	/**
	 * Videos directly inside these folders, with each person's watch record.
	 *
	 * @param int[] $folder_ids
	 * @return array<int,array{id:int,folder_id:int,title:string,views:array<int,array{user_id:int,percent:int,last_viewed_at:string}>}>
	 */
	public static function videos_with_views( array $folder_ids ): array {
		global $wpdb;
		$folder_ids = \array_values( \array_filter( \array_map( 'intval', $folder_ids ) ) );
		if ( ! $folder_ids ) {
			return [];
		}
		$in   = \implode( ',', \array_fill( 0, \count( $folder_ids ), '%d' ) );
		$rows = $wpdb->get_results( \call_user_func_array( [ $wpdb, 'prepare' ], \array_merge(
			[ 'SELECT id, folder_id, title FROM ' . Schema::table( 'videos' ) . " WHERE folder_id IN ({$in}) ORDER BY title ASC" ],
			$folder_ids
		) ) );
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->id ] = [ 'id' => (int) $r->id, 'folder_id' => (int) $r->folder_id, 'title' => (string) $r->title, 'views' => [] ];
		}
		if ( ! $out ) {
			return [];
		}
		$in    = \implode( ',', \array_fill( 0, \count( $out ), '%d' ) );
		$views = $wpdb->get_results( \call_user_func_array( [ $wpdb, 'prepare' ], \array_merge(
			[ 'SELECT video_id, user_id, percent, last_viewed_at FROM ' . Schema::table( 'video_views' ) . " WHERE source = 'vimeo' AND video_id IN ({$in})" ],
			\array_keys( $out )
		) ) );
		foreach ( (array) $views as $v ) {
			$out[ (int) $v->video_id ]['views'][] = [ 'user_id' => (int) $v->user_id, 'percent' => (int) $v->percent, 'last_viewed_at' => (string) $v->last_viewed_at ];
		}
		return \array_values( $out );
	}

	private static function folder_name( string $name ): string {
		return \mb_substr( \sanitize_text_field( $name ), 0, 190 );
	}

	private static function is_date( string $value ): bool {
		if ( ! \preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return \checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}
}
