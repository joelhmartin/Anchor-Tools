<?php
/**
 * Library reads: row lookups, permission-filtered listings, search, folder tree and breadcrumbs.
 *
 * Ported from the Anchor Private File Manager 2.17.0 listing code (build_folder_tree, build_contents_map,
 * ajax_list, ajax_search) with the SQL and filtering unchanged and the request/response plumbing removed, so the
 * AJAX handlers and the REST routes can share one implementation. Every method takes an explicit user id.
 * The hidden Product Docs container folder never appears in a listing, tree or search hit.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Repository {

	/** Search hit ceiling per kind. */
	private const SEARCH_LIMIT = 200;

	// ---- Rows -----------------------------------------------------------------------------------------------

	/** Memoized inside a Permissions memo scope, because the ancestor walk and breadcrumbs re-read the same rows. */
	public static function folder_row( int $folder_id ): ?\stdClass {
		return Permissions::memoized( 'folder:' . $folder_id, static function () use ( $folder_id ) {
			global $wpdb;
			$folders = Schema::table( 'folders' );
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$folders} WHERE id = %d", $folder_id ) );
		} );
	}

	public static function file_row( int $file_id ): ?\stdClass {
		global $wpdb;
		$files = Schema::table( 'files' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$files} WHERE id = %d", $file_id ) );
	}

	public static function link_row( int $link_id ): ?\stdClass {
		global $wpdb;
		$links = Schema::table( 'links' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$links} WHERE id = %d", $link_id ) );
	}

	public static function video_row( int $video_id ): ?\stdClass {
		global $wpdb;
		$videos = Schema::table( 'videos' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$videos} WHERE id = %d", $video_id ) );
	}

	/** Id of the hidden Product Docs container, or 0 when it has not been created. */
	public static function product_docs_folder_id(): int {
		return (int) \get_option( Schema::OPT_PD_FOLDER_ID, 0 );
	}

	// ---- Row shapes (shared by the listing, the preload and the REST layer) -------------------------------

	public static function shape_folder( $f ): array {
		return [
			'id'          => (int) $f->id,
			'name'        => $f->name,
			'isPrivate'   => 1 === (int) $f->is_private,
			'ownerUserId' => ! empty( $f->owner_user_id ) ? (int) $f->owner_user_id : 0,
		];
	}

	public static function shape_file( $r ): array {
		return [
			'id'         => (int) $r->id,
			'name'       => $r->original_name,
			'mime'       => $r->mime_type,
			'size'       => (int) $r->size,
			'uploadedBy' => ! empty( $r->uploader_user_id ) ? (int) $r->uploader_user_id : 0,
			'createdAt'  => $r->created_at,
		];
	}

	public static function shape_link( $l ): array {
		return [
			'id'        => (int) $l->id,
			'title'     => $l->title,
			'url'       => $l->url,
			'createdBy' => ! empty( $l->created_by ) ? (int) $l->created_by : 0,
			'createdAt' => $l->created_at,
		];
	}

	public static function shape_video( $v ): array {
		return [
			'id'           => (int) $v->id,
			'title'        => $v->title,
			'vimeoId'      => $v->vimeo_id,
			'vimeoHash'    => isset( $v->vimeo_hash ) ? (string) $v->vimeo_hash : '',
			'thumbnailUrl' => isset( $v->thumbnail_url ) ? (string) $v->thumbnail_url : '',
			'createdBy'    => ! empty( $v->created_by ) ? (int) $v->created_by : 0,
			'createdAt'    => $v->created_at,
		];
	}

	// ---- Listings -------------------------------------------------------------------------------------------

	/**
	 * What the user may see directly inside a folder (0 = root, which holds folders only). Returns empty lists for
	 * a folder the user cannot view and for the Product Docs container, so a caller that forgets its own check
	 * still leaks nothing; callers answer 403/404 themselves. Media rows carry the user's own watchPercent.
	 *
	 * @return array{folders:array,links:array,files:array,videos:array}
	 */
	public static function children( int $folder_id, int $user_id ): array {
		$out = [ 'folders' => [], 'links' => [], 'files' => [], 'videos' => [] ];
		$pd  = self::product_docs_folder_id();
		if ( $folder_id < 0 || ( $pd > 0 && $folder_id === $pd ) ) {
			return $out;
		}
		return Permissions::with_memo( static function () use ( $folder_id, $user_id, $pd, $out ) {
			if ( $folder_id > 0 && ! Permissions::can_view_folder( $folder_id, $user_id ) ) {
				return $out;
			}
			global $wpdb;
			$folders = Schema::table( 'folders' );
			$rows    = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, parent_id, name, owner_user_id, is_private FROM {$folders} WHERE parent_id = %d AND is_private = 0 ORDER BY name ASC",
				$folder_id
			) );
			foreach ( (array) $rows as $f ) {
				if ( (int) $f->id === $pd || ! Permissions::can_view_folder( (int) $f->id, $user_id ) ) {
					continue;
				}
				$out['folders'][] = self::shape_folder( $f );
			}

			if ( $folder_id > 0 ) {
				$out['files']  = self::visible_rows( 'files', 'id, folder_id, original_name, mime_type, size, uploader_user_id, created_at', $folder_id, $user_id, 'file' );
				$out['links']  = self::visible_rows( 'links', 'id, folder_id, title, url, created_by, created_at', $folder_id, $user_id, 'link' );
				$out['videos'] = self::visible_rows( 'videos', 'id, folder_id, vimeo_id, vimeo_hash, title, thumbnail_url, created_by, created_at', $folder_id, $user_id, 'video' );
			}

			$video_ids = [];
			$file_ids  = [];
			self::watch_ids_for( $out['videos'], $out['files'], $video_ids, $file_ids );
			self::apply_watch_percents( $out['videos'], $out['files'], self::watch_percent_map( $video_ids, $file_ids, $user_id ) );
			return $out;
		} );
	}

	/** Folders the user may open at the top level. */
	public static function roots( int $user_id ): array {
		return self::children( 0, $user_id )['folders'];
	}

	/**
	 * Name search across folders, files, links and videos, permission-filtered, up to 200 hits per kind. A term
	 * shorter than two characters finds nothing.
	 *
	 * @return array{results:array,truncated:bool}
	 */
	public static function search( string $q, int $user_id ): array {
		$term = \sanitize_text_field( $q );
		if ( '' === $term || \mb_strlen( $term ) < 2 ) {
			return [ 'results' => [], 'truncated' => false ];
		}
		return Permissions::with_memo( static function () use ( $term, $user_id ) {
			global $wpdb;
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$pd   = self::product_docs_folder_id();
			$cap  = self::SEARCH_LIMIT;
			$res  = [];

			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, parent_id, name FROM ' . Schema::table( 'folders' ) . ' WHERE is_private = 0 AND name LIKE %s ORDER BY name ASC LIMIT %d',
				$like, $cap
			) );
			foreach ( (array) $rows as $r ) {
				if ( (int) $r->id === $pd || ! Permissions::can_view_folder( (int) $r->id, $user_id ) ) {
					continue;
				}
				$res[] = [ 'kind' => 'folder', 'id' => (int) $r->id, 'name' => $r->name, 'folderId' => (int) $r->parent_id, 'path' => self::folder_path_string( (int) $r->parent_id ) ];
			}

			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, folder_id, original_name, mime_type, size FROM ' . Schema::table( 'files' ) . ' WHERE original_name LIKE %s ORDER BY original_name ASC LIMIT %d',
				$like, $cap
			) );
			foreach ( (array) $rows as $r ) {
				if ( (int) $r->folder_id === $pd || ! Permissions::can_view_file( (int) $r->id, $user_id ) ) {
					continue;
				}
				$res[] = [
					'kind' => 'file', 'id' => (int) $r->id, 'name' => $r->original_name, 'mime' => $r->mime_type, 'size' => (int) $r->size,
					'folderId' => (int) $r->folder_id, 'path' => self::folder_path_string( (int) $r->folder_id ),
				];
			}

			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, folder_id, title, url FROM ' . Schema::table( 'links' ) . ' WHERE title LIKE %s ORDER BY title ASC LIMIT %d',
				$like, $cap
			) );
			foreach ( (array) $rows as $r ) {
				if ( ! Permissions::can_view_link( (int) $r->id, $user_id ) ) {
					continue;
				}
				$res[] = [ 'kind' => 'link', 'id' => (int) $r->id, 'name' => $r->title, 'url' => $r->url, 'folderId' => (int) $r->folder_id, 'path' => self::folder_path_string( (int) $r->folder_id ) ];
			}

			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, folder_id, title, vimeo_id, vimeo_hash, thumbnail_url FROM ' . Schema::table( 'videos' ) . ' WHERE title LIKE %s ORDER BY title ASC LIMIT %d',
				$like, $cap
			) );
			foreach ( (array) $rows as $r ) {
				if ( ! Permissions::can_view_video( (int) $r->id, $user_id ) ) {
					continue;
				}
				$res[] = [
					'kind' => 'video', 'id' => (int) $r->id, 'name' => $r->title, 'vimeoId' => $r->vimeo_id,
					'vimeoHash' => isset( $r->vimeo_hash ) ? (string) $r->vimeo_hash : '',
					'thumbnailUrl' => isset( $r->thumbnail_url ) ? (string) $r->thumbnail_url : '',
					'folderId' => (int) $r->folder_id, 'path' => self::folder_path_string( (int) $r->folder_id ),
				];
			}

			$truncated = \count( $res ) > $cap;
			return [ 'results' => $truncated ? \array_slice( $res, 0, $cap ) : $res, 'truncated' => $truncated ];
		} );
	}

	/** Root-to-leaf [id, name] trail for a folder; bounded and cycle-safe. */
	public static function breadcrumbs( int $folder_id ): array {
		$crumbs  = [];
		$seen    = [];
		$current = self::folder_row( $folder_id );
		$depth   = 0;
		while ( $current && $depth < 50 ) {
			++$depth;
			$id = (int) $current->id;
			if ( isset( $seen[ $id ] ) ) {
				break;
			}
			$seen[ $id ] = true;
			$crumbs[]    = [ 'id' => $id, 'name' => $current->name ];
			$current     = ! empty( $current->parent_id ) ? self::folder_row( (int) $current->parent_id ) : null;
		}
		return \array_reverse( $crumbs );
	}

	/** Whether $possible_ancestor_id is somewhere above $folder_id (bounded and cycle-safe). */
	public static function is_descendant( int $folder_id, int $possible_ancestor_id ): bool {
		if ( $folder_id <= 0 || $possible_ancestor_id <= 0 ) {
			return false;
		}
		$current = self::folder_row( $folder_id );
		$seen    = [];
		$depth   = 0;
		while ( $current && $depth < 100 ) {
			++$depth;
			$cid = (int) $current->id;
			if ( isset( $seen[ $cid ] ) ) {
				break;
			}
			$seen[ $cid ] = true;
			if ( (int) $current->parent_id === $possible_ancestor_id ) {
				return true;
			}
			$current = ! empty( $current->parent_id ) ? self::folder_row( (int) $current->parent_id ) : null;
		}
		return false;
	}

	/** "A › B › C" for a folder id; empty for the root. */
	public static function folder_path_string( int $folder_id ): string {
		return $folder_id <= 0 ? '' : \implode( ' › ', \array_column( self::breadcrumbs( $folder_id ), 'name' ) );
	}

	/** Slugged names along a folder's path (used to name download archives). */
	public static function folder_path_names( int $folder_id ): array {
		$names = [];
		foreach ( self::breadcrumbs( $folder_id ) as $c ) {
			$names[] = \sanitize_title( $c['name'] ) ?: 'folder-' . (int) $c['id'];
		}
		return $names;
	}

	/** The user's visible folder tree: folders they may view plus the ancestors that lead to them. */
	public static function tree( int $user_id ): array {
		return Permissions::with_memo( static function () use ( $user_id ) {
			global $wpdb;
			$folders = Schema::table( 'folders' );
			$all     = $wpdb->get_results( "SELECT id, parent_id, name, owner_user_id, is_private FROM {$folders} WHERE is_private = 0 ORDER BY name ASC" );
			if ( ! $all ) {
				return [];
			}
			$pd    = self::product_docs_folder_id();
			$by_id = [];
			foreach ( $all as $row ) {
				if ( (int) $row->id !== $pd ) {
					$by_id[ (int) $row->id ] = $row;
				}
			}

			$visible = [];
			foreach ( $by_id as $id => $row ) {
				if ( ! Permissions::can_view_folder( $id, $user_id ) ) {
					continue;
				}
				$visible[ $id ] = true;
				$parent         = ! empty( $row->parent_id ) ? (int) $row->parent_id : 0;
				$depth          = 0;
				while ( $parent && $depth < 50 ) {
					++$depth;
					if ( ! isset( $by_id[ $parent ] ) ) {
						break;
					}
					$visible[ $parent ] = true;
					$parent             = ! empty( $by_id[ $parent ]->parent_id ) ? (int) $by_id[ $parent ]->parent_id : 0;
				}
			}

			$children = [];
			foreach ( \array_keys( $visible ) as $id ) {
				$children[ ! empty( $by_id[ $id ]->parent_id ) ? (int) $by_id[ $id ]->parent_id : 0 ][] = $id;
			}

			$build = static function ( int $parent_id ) use ( &$build, $children, $by_id, $pd ): array {
				$nodes = [];
				foreach ( $children[ $parent_id ] ?? [] as $id ) {
					$row     = $by_id[ $id ];
					$nodes[] = [
						'id'            => (int) $row->id,
						'parentId'      => ! empty( $row->parent_id ) ? (int) $row->parent_id : 0,
						'name'          => $row->name,
						'isPrivate'     => 1 === (int) $row->is_private,
						'ownerUserId'   => ! empty( $row->owner_user_id ) ? (int) $row->owner_user_id : 0,
						'isProductDocs' => (int) $row->id === $pd,
						'children'      => $build( (int) $row->id ),
					];
				}
				return $nodes;
			};
			return $build( 0 );
		} );
	}

	/**
	 * Every viewable folder's contents in one pass, so expanding a folder costs no request (a round trip is a full
	 * WordPress bootstrap). Returns folderId => ['folders','links','files','videos'], or null when the store is
	 * above the preload ceiling. Every row is checked with the same can_view_* calls children() uses: a different
	 * query plan, not a different permission model.
	 */
	public static function contents_map( int $user_id ): ?array {
		if ( Permissions::store_exceeds_preload_max() ) {
			return null;
		}
		return Permissions::with_memo( static function () use ( $user_id ) {
			global $wpdb;
			$pd      = self::product_docs_folder_id();
			$empty   = [ 'folders' => [], 'links' => [], 'files' => [], 'videos' => [] ];
			$visible = [ 0 => true ]; // Root is addressable but is not a row; it holds folders only.
			$map     = [ 0 => $empty ];

			$shaped = [];
			$rows   = $wpdb->get_results( 'SELECT id, parent_id, name, owner_user_id, is_private FROM ' . Schema::table( 'folders' ) . ' WHERE is_private = 0 ORDER BY name ASC' );
			foreach ( (array) $rows as $f ) {
				$fid = (int) $f->id;
				if ( $fid === $pd || ! Permissions::can_view_folder( $fid, $user_id ) ) {
					continue;
				}
				$visible[ $fid ] = true;
				$map[ $fid ]     = $empty;
				$shaped[]        = [ (int) $f->parent_id, self::shape_folder( $f ) ];
			}
			// Second pass: a child can sort before its parent, so parent visibility is only known once all are checked.
			foreach ( $shaped as [ $parent_id, $row ] ) {
				if ( isset( $visible[ $parent_id ] ) ) {
					$map[ $parent_id ]['folders'][] = $row;
				}
			}

			$kinds = [
				'files'  => [ 'files', 'id, folder_id, original_name, mime_type, size, uploader_user_id, created_at', 'file' ],
				'links'  => [ 'links', 'id, folder_id, title, url, created_by, created_at', 'link' ],
				'videos' => [ 'videos', 'id, folder_id, vimeo_id, vimeo_hash, title, thumbnail_url, created_by, created_at', 'video' ],
			];
			foreach ( $kinds as $key => [ $table, $cols, $type ] ) {
				$rows = $wpdb->get_results( "SELECT {$cols} FROM " . Schema::table( $table ) . ' ORDER BY created_at DESC' );
				foreach ( (array) $rows as $r ) {
					$fid = (int) $r->folder_id;
					if ( $fid <= 0 || ! isset( $visible[ $fid ] ) || ! self::can_view_entity( $type, (int) $r->id, $user_id ) ) {
						continue;
					}
					$map[ $fid ][ $key ][] = self::shape_row( $type, $r );
				}
			}

			// One watch lookup for the whole store rather than one per folder.
			$video_ids = [];
			$file_ids  = [];
			foreach ( $map as $lists ) {
				self::watch_ids_for( $lists['videos'], $lists['files'], $video_ids, $file_ids );
			}
			if ( $video_ids || $file_ids ) {
				$watch = self::watch_percent_map( $video_ids, $file_ids, $user_id );
				foreach ( \array_keys( $map ) as $fid ) {
					self::apply_watch_percents( $map[ $fid ]['videos'], $map[ $fid ]['files'], $watch );
				}
			}
			return $map;
		} );
	}

	/**
	 * The user's own watch percentage for a batch of media, keyed "<source>:<id>". One query for the whole listing.
	 * Only ever reads the named user's rows.
	 *
	 * @return array<string,int>
	 */
	public static function watch_percent_map( array $video_ids, array $file_ids, int $user_id ): array {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return [];
		}
		$video_ids = \array_values( \array_filter( \array_map( 'intval', $video_ids ) ) );
		$file_ids  = \array_values( \array_filter( \array_map( 'intval', $file_ids ) ) );
		if ( ! $video_ids && ! $file_ids ) {
			return [];
		}

		$where  = [];
		$params = [ $user_id ];
		foreach ( [ [ \Anchor_FM_Media_Progress::SOURCE_VIMEO, $video_ids ], [ \Anchor_FM_Media_Progress::SOURCE_FILE, $file_ids ] ] as [ $source, $ids ] ) {
			if ( ! $ids ) {
				continue;
			}
			$where[]  = '(source = %s AND video_id IN (' . \implode( ',', \array_fill( 0, \count( $ids ), '%d' ) ) . '))';
			$params[] = $source;
			foreach ( $ids as $id ) {
				$params[] = $id;
			}
		}

		$views = Schema::table( 'video_views' );
		$sql   = "SELECT source, video_id, percent FROM {$views} WHERE user_id = %d AND (" . \implode( ' OR ', $where ) . ')';
		$rows  = $wpdb->get_results( \call_user_func_array( [ $wpdb, 'prepare' ], \array_merge( [ $sql ], $params ) ) );

		$map = [];
		foreach ( (array) $rows as $r ) {
			$map[ $r->source . ':' . (int) $r->video_id ] = (int) $r->percent;
		}
		return $map;
	}

	// ---- Internals ------------------------------------------------------------------------------------------

	private static function can_view_entity( string $type, int $id, int $user_id ): bool {
		switch ( $type ) {
			case 'file':
				return Permissions::can_view_file( $id, $user_id );
			case 'link':
				return Permissions::can_view_link( $id, $user_id );
			default:
				return Permissions::can_view_video( $id, $user_id );
		}
	}

	private static function shape_row( string $type, $row ): array {
		switch ( $type ) {
			case 'file':
				return self::shape_file( $row );
			case 'link':
				return self::shape_link( $row );
			default:
				return self::shape_video( $row );
		}
	}

	/** One folder's rows of a kind, newest first, filtered by view right and shaped. */
	private static function visible_rows( string $table, string $columns, int $folder_id, int $user_id, string $type ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT {$columns} FROM " . Schema::table( $table ) . ' WHERE folder_id = %d ORDER BY created_at DESC',
			$folder_id
		) );
		$out = [];
		foreach ( (array) $rows as $r ) {
			if ( self::can_view_entity( $type, (int) $r->id, $user_id ) ) {
				$out[] = self::shape_row( $type, $r );
			}
		}
		return $out;
	}

	/** Attach watch percentages from a prebuilt map. Only media rows carry one. */
	private static function apply_watch_percents( array &$video_list, array &$file_list, array $watch ): void {
		foreach ( $video_list as $i => $v ) {
			$key = \Anchor_FM_Media_Progress::SOURCE_VIMEO . ':' . (int) $v['id'];
			if ( isset( $watch[ $key ] ) ) {
				$video_list[ $i ]['watchPercent'] = $watch[ $key ];
			}
		}
		foreach ( $file_list as $i => $f ) {
			if ( 0 !== \strpos( (string) $f['mime'], 'video/' ) ) {
				continue;
			}
			$key = \Anchor_FM_Media_Progress::SOURCE_FILE . ':' . (int) $f['id'];
			if ( isset( $watch[ $key ] ) ) {
				$file_list[ $i ]['watchPercent'] = $watch[ $key ];
			}
		}
	}

	/** Media ids in a listing that can carry a watch percentage. */
	private static function watch_ids_for( array $video_list, array $file_list, array &$video_ids, array &$file_ids ): void {
		foreach ( $video_list as $v ) {
			$video_ids[] = (int) $v['id'];
		}
		foreach ( $file_list as $f ) {
			if ( 0 === \strpos( (string) $f['mime'], 'video/' ) ) {
				$file_ids[] = (int) $f['id'];
			}
		}
	}
}
