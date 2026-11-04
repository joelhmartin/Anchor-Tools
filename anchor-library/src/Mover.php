<?php
/**
 * Library move: relocates files (bytes and row), links, videos and folders. One implementation behind the
 * single-item handlers (anchor_fm_move_file / anchor_fm_move_folder) and the batch endpoint (anchor_fm_move_items).
 *
 * A move leaves grants alone: an item with no explicit rows simply inherits its new folder, and one with its own
 * explicit rows keeps them, except a file carrying a legacy snapshot of its source folder (see release_snapshot) (permissions are read from the tables on every request, so there is no index to update).
 * A name that already exists in the destination is made unique with Anchor_FM_Copy_Namer, as copy does.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Mover {

	public const KINDS       = [ 'file', 'link', 'video', 'folder' ];
	public const MAX_ITEMS   = 500;
	public const TOP_LEVEL   = 'Only folders can go at the top level';

	/**
	 * Refusal for a destination, or null when items may be moved into it (0 = top level, where only folders live).
	 *
	 * @return array{0:string,1:int}|null [message, HTTP status]
	 */
	public static function target_error( int $target, int $user_id ): ?array {
		if ( $target < 0 ) {
			return [ 'Missing target folder', 400 ];
		}
		if ( ! Permissions::can_upload_to_folder( $target, $user_id ) ) {
			return [ 'Forbidden', 403 ];
		}
		if ( $target > 0 ) {
			$err = Repository::write_target_error( $target );
			if ( $err ) {
				return 404 === $err[1] ? [ 'Target not found', 404 ] : $err;
			}
		}
		return null;
	}

	/**
	 * Move one item.
	 *
	 * @param array $existing Display names already in the destination; updated with the final name.
	 * @param string $rename Name to restore (Undo); used when free in the destination, else the usual de-dup applies.
	 * @return array{fromFolderId:int,fromName:string,name:string}|\WP_Error Error data carries the HTTP status for single-item callers.
	 */
	public static function move( string $kind, int $id, int $target, array &$existing, int $user_id, string $rename = '' ) {
		if ( $id <= 0 || ! \in_array( $kind, self::KINDS, true ) ) {
			return self::fail( 'Invalid item', 400 );
		}
		if ( $target === 0 && 'folder' !== $kind ) {
			return self::fail( self::TOP_LEVEL, 400 );
		}
		switch ( $kind ) {
			case 'file':
				return self::move_file( $id, $target, $existing, $user_id, $rename );
			case 'folder':
				return self::move_folder( $id, $target, $existing, $user_id, $rename );
			default:
				return self::move_row( $kind, $id, $target, $existing, $user_id, $rename );
		}
	}

	/**
	 * Move a list of {kind, id} items to $target and describe each outcome. Target-level refusals are the caller's
	 * (see target_error()); everything here is per item, and one failure never stops the rest.
	 *
	 * @return array{moved:int,errors:int,items:array,targetFolderId:int} Item status is moved, unchanged or error.
	 */
	public static function move_items( array $items, int $target, int $user_id ): array {
		$existing = Copier::gather_existing_names( $target );
		$results  = [];
		$moved    = 0;
		$errors   = 0;
		$seen     = [];
		foreach ( $items as $it ) {
			$kind = \is_array( $it ) && isset( $it['kind'] ) ? \sanitize_key( (string) $it['kind'] ) : '';
			$id   = \is_array( $it ) && isset( $it['id'] ) ? (int) $it['id'] : 0;
			if ( $id > 0 && isset( $seen[ $kind . ':' . $id ] ) ) {
				continue; // The same item twice in one batch is one move.
			}
			$seen[ $kind . ':' . $id ] = true;
			$res  = [ 'kind' => $kind, 'sourceId' => $id, 'fromFolderId' => 0, 'status' => 'error', 'message' => '', 'name' => '', 'fromName' => '' ];
			$want = \is_array( $it ) && isset( $it['name'] ) ? \trim( \str_replace( [ '/', '\\' ], '', \sanitize_text_field( (string) $it['name'] ) ) ) : '';
			$out  = self::move( $kind, $id, $target, $existing, $user_id, $want );
			if ( \is_wp_error( $out ) ) {
				$res['message'] = $out->get_error_message();
				++$errors;
			} else {
				$res['fromFolderId'] = $out['fromFolderId'];
				$res['name']         = $out['name'];
				$res['fromName']     = $out['fromName'];
				if ( ! empty( $out['keptPermissions'] ) ) {
					$res['keptPermissions'] = true;
				}
				// Already in the destination: nothing changed, so it is neither counted nor undoable.
				$res['status'] = ! empty( $out['unchanged'] ) ? 'unchanged' : 'moved';
				if ( 'moved' === $res['status'] ) {
					++$moved;
				}
			}
			$results[] = $res;
		}
		Activity::log( 'move_items', 'folder', $target, [ 'moved' => $moved, 'errors' => $errors, 'count' => \count( $items ) ], $user_id );
		return [ 'moved' => $moved, 'errors' => $errors, 'items' => $results, 'targetFolderId' => $target ];
	}

	// ---- Kinds ----------------------------------------------------------------------------------------------

	private static function move_file( int $id, int $target, array &$existing, int $user_id, string $rename ) {
		$file = Repository::file_row( $id );
		if ( ! $file ) {
			return self::fail( 'Not found', 404 );
		}
		if ( ! Permissions::can_manage_file( $id, $user_id ) ) {
			return self::fail( 'Forbidden', 403 );
		}
		$from = (int) $file->folder_id;
		if ( $from === $target ) {
			return [ 'fromFolderId' => $from, 'name' => (string) $file->original_name, 'fromName' => (string) $file->original_name, 'unchanged' => true ];
		}
		if ( Repository::is_product_docs( $from ) ) {
			return self::fail( Repository::PD_MANAGED_MESSAGE, 403 );
		}
		$current_path = Storage::path_for( $file );
		if ( ! \file_exists( $current_path ) ) {
			return self::fail( 'File missing on disk', 404 );
		}

		// Never reuse the source's stored name blindly: a same-named file in the destination would be overwritten,
		// and two rows would then share one path (AFM-05).
		$target_dir = Storage::ensure_folder_dir( $target );
		$stored     = Storage::reserve_stored_name( $target_dir, $file->stored_name );
		if ( false === $stored ) {
			return self::fail( 'Could not move file', 500 );
		}
		$dest = \trailingslashit( $target_dir ) . $stored;
		if ( ! @\rename( $current_path, $dest ) ) {
			@\unlink( $dest );
			return self::fail( 'Could not move file', 500 );
		}

		$name = \Anchor_FM_Copy_Namer::resolve_unique( '' !== $rename ? $rename : $file->original_name, $existing, true, false );
		global $wpdb;
		$updated = $wpdb->update( Schema::table( 'files' ), [
			'folder_id'     => $target,
			'stored_name'   => $stored,
			'original_name' => $name,
		], [ 'id' => $id ], [ '%d', '%s', '%s' ], [ '%d' ] );
		if ( false === $updated ) {
			@\rename( $dest, $current_path ); // Put the bytes back where the row still says they are.
			return self::fail( 'Could not move file', 500 );
		}
		$existing[] = $name;
		// A legacy snapshot of the source folder's grants must not follow the file into its new home.
		$kept = PermissionWrites::release_snapshot( $id, $from );
		Activity::log( 'move_file', 'file', $id, [ 'from' => $from, 'to' => $target ], $user_id );
		return [ 'fromFolderId' => $from, 'fromName' => (string) $file->original_name, 'name' => $name, 'keptPermissions' => $kept ];
	}

	/** Links and videos: a folder_id change, no bytes. */
	private static function move_row( string $kind, int $id, int $target, array &$existing, int $user_id, string $rename ) {
		$link = 'link' === $kind;
		$row  = $link ? Repository::link_row( $id ) : Repository::video_row( $id );
		if ( ! $row ) {
			return self::fail( 'Not found', 404 );
		}
		$can = $link ? Permissions::can_manage_link( $id, $user_id ) : Permissions::can_manage_video( $id, $user_id );
		if ( ! $can ) {
			return self::fail( 'Forbidden', 403 );
		}
		$from = (int) $row->folder_id;
		if ( $from === $target ) {
			return [ 'fromFolderId' => $from, 'name' => (string) $row->title, 'fromName' => (string) $row->title, 'unchanged' => true ];
		}
		if ( Repository::is_product_docs( $from ) ) {
			return self::fail( Repository::PD_MANAGED_MESSAGE, 403 );
		}
		$name = \Anchor_FM_Copy_Namer::resolve_unique( '' !== $rename ? $rename : $row->title, $existing, false, false );
		global $wpdb;
		$updated = $wpdb->update( Schema::table( $link ? 'links' : 'videos' ), [
			'folder_id'  => $target,
			'title'      => $name,
			'updated_at' => \current_time( 'mysql' ),
		], [ 'id' => $id ], [ '%d', '%s', '%s' ], [ '%d' ] );
		if ( false === $updated ) {
			return self::fail( 'Could not move item', 500 );
		}
		$existing[] = $name;
		Activity::log( 'move_' . $kind, $kind, $id, [ 'from' => $from, 'to' => $target ], $user_id );
		return [ 'fromFolderId' => $from, 'fromName' => (string) $row->title, 'name' => $name ];
	}

	private static function move_folder( int $id, int $target, array &$existing, int $user_id, string $rename ) {
		if ( ! \user_can( $user_id, 'administrator' ) ) {
			return self::fail( 'Forbidden', 403 );
		}
		if ( Repository::is_product_docs( $id ) ) {
			return self::fail( Repository::PD_MANAGED_MESSAGE, 403 );
		}
		$folder = Repository::folder_row( $id );
		if ( ! $folder ) {
			return self::fail( 'Folder not found', 404 );
		}
		if ( $id === $target ) {
			return self::fail( 'Cannot move into itself', 400 );
		}
		if ( Repository::is_descendant( $target, $id ) ) {
			return self::fail( 'Cannot move into a child folder', 400 );
		}
		$from = (int) $folder->parent_id;
		if ( $from === $target ) {
			return [ 'fromFolderId' => $from, 'name' => (string) $folder->name, 'fromName' => (string) $folder->name, 'unchanged' => true ];
		}
		if ( Repository::is_product_docs( $from ) ) {
			return self::fail( Repository::PD_MANAGED_MESSAGE, 403 );
		}
		$name = \Anchor_FM_Copy_Namer::resolve_unique( '' !== $rename ? $rename : $folder->name, $existing, false, false );
		global $wpdb;
		$updated = $wpdb->update( Schema::table( 'folders' ), [
			'parent_id'  => $target,
			'name'       => $name,
			'updated_at' => \current_time( 'mysql' ),
		], [ 'id' => $id ], [ '%d', '%s', '%s' ], [ '%d' ] );
		if ( false === $updated ) {
			return self::fail( 'Could not move folder', 500 );
		}
		$existing[] = $name;
		Activity::log( 'move_folder', 'folder', $id, [ 'from' => $from, 'to' => $target ], $user_id );
		return [ 'fromFolderId' => $from, 'fromName' => (string) $folder->name, 'name' => $name ];
	}

	private static function fail( string $message, int $status ): \WP_Error {
		return new \WP_Error( 'afm_move', $message, [ 'status' => $status ] );
	}

	/** Http status carried by a move() error (400 when absent). */
	public static function status_of( \WP_Error $error ): int {
		$data = $error->get_error_data();
		return \is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
	}
}
