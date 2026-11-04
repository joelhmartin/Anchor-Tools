<?php
/**
 * Library AJAX: folder tree, listings, search and folder operations (create, rename, move, copy, delete, zip download).
 *
 * Ported from Anchor Private File Manager 2.17.0. Listings, tree and search are served by Repository, the same
 * implementation the REST routes use; request/response shapes, nonce and permission checks are unchanged.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library\Ajax;

use Anchor\Library\Activity;
use Anchor\Library\Copier;
use Anchor\Library\Http;
use Anchor\Library\Mover;
use Anchor\Library\Permissions;
use Anchor\Library\Purger;
use Anchor\Library\Repository;
use Anchor\Library\Schema;
use Anchor\Library\Storage;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Folders {

	/** AJAX action => handler; Ajax\Registry hooks each as wp_ajax_{action}. */
	public const ACTIONS = [
		'anchor_fm_bootstrap' => 'ajax_bootstrap',
		'anchor_fm_list' => 'ajax_list',
		'anchor_fm_search' => 'ajax_search',
		'anchor_fm_create_folder' => 'ajax_create_folder',
		'anchor_fm_rename_folder' => 'ajax_rename_folder',
		'anchor_fm_delete_folder' => 'ajax_delete_folder',
		'anchor_fm_move_folder' => 'ajax_move_folder',
		'anchor_fm_move_items' => 'ajax_move_items',
		'anchor_fm_copy_items' => 'ajax_copy_items',
		'anchor_fm_download_folder' => 'ajax_download_folder',
	];

	private const DUPLICATE_FOLDER = 'A folder with that name already exists here';

	public static function ajax_bootstrap() {
		Http::auth();

		$user_id = \get_current_user_id();
		$product_docs_id = Repository::product_docs_folder_id();
		if ( $product_docs_id === 0 && \user_can( $user_id, 'administrator' ) ) {
			$product_docs_id = (int) Schema::ensure_product_docs_folder();
		}

		// Every folder in the tree is permission-checked, and the checks share ancestor chains (Repository::tree
		// opens the memo scope). No contents map here on purpose: the client calls ajax_list for the default folder
		// the moment this returns, and that response carries one.
		Http::success( [
			'tree' => Repository::tree( $user_id ),
			'defaultFolderId' => 0,
			'productDocsFolderId' => $product_docs_id,
		] );
	}

	public static function ajax_list() {
		Http::auth();

		$user_id = \get_current_user_id();
		$folder_id = isset( $_POST['folder_id'] ) ? (int) $_POST['folder_id'] : 0;
		if ( $folder_id < 0 ) Http::error( 'Invalid folder_id' );
		$product_docs_id = Repository::product_docs_folder_id();
		if ( $product_docs_id > 0 && $folder_id === $product_docs_id ) {
			Http::error( 'Forbidden', 403 );
		}
		if ( $folder_id > 0 && ! Permissions::can_view_folder( $folder_id, $user_id ) ) {
			Http::error( 'Forbidden', 403 );
		}

		// Read-only from here to the response: the memo scope never spans a write.
		$payload = Permissions::with_memo( static function () use ( $folder_id, $user_id, $product_docs_id ) {
			$lists = Repository::children( $folder_id, $user_id );
			$cap = $folder_id === 0
				? ( \user_can( $user_id, 'administrator' ) ? 'manage' : 'view' )
				: Permissions::folder_capability( $folder_id, $user_id );
			$payload = [
				'folderId' => $folder_id,
				'breadcrumbs' => $folder_id === 0 ? [] : Repository::visible_breadcrumbs( $folder_id, $user_id ),
				'folders' => $lists['folders'],
				'links' => $lists['links'],
				'files' => $lists['files'],
				'videos' => $lists['videos'],
				'capability' => $cap,
				'isProductDocs' => $product_docs_id > 0 && $folder_id === $product_docs_id,
				// Every other folder this user may open, so expanding one costs no request at all. Sent on every
				// listing, which is what keeps it fresh: the client's copy is never older than its last navigation.
				'contents' => Repository::contents_map( $user_id ),
			];
			return $payload;
		} );
		Http::success( $payload );
	}

	public static function ajax_search() {
		Http::auth();
		Http::success( Repository::search( isset( $_POST['term'] ) ? (string) $_POST['term'] : '', \get_current_user_id() ) );
	}

	public static function ajax_create_folder() {
		Http::auth();

		$user_id = \get_current_user_id();
		$parent_id = isset($_POST['parent_id']) ? (int) $_POST['parent_id'] : 0;
		$name = isset($_POST['name']) ? \sanitize_text_field((string) $_POST['name']) : '';
		if ($parent_id < 0 || $name === '') Http::error('Missing fields');

		if (!\user_can($user_id, 'administrator')) Http::error('Forbidden', 403);
		if ($parent_id > 0 && ($err = Repository::write_target_error($parent_id))) Http::error($err[0], $err[1]);
		if (self::sibling_folder_exists($parent_id, $name)) Http::error(self::DUPLICATE_FOLDER, 400);

		global $wpdb;
		$folders = Schema::table('folders');
		$now = \current_time('mysql');
		$wpdb->insert($folders, [
			'parent_id' => $parent_id,
			'name' => $name,
			'owner_user_id' => 0,
			'is_private' => 0,
			'created_by' => $user_id,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$folder_id = (int) $wpdb->insert_id;
		Activity::log('create_folder', 'folder', $folder_id, ['parent_id' => $parent_id, 'name' => $name], $user_id);

		Http::success(['folderId' => $folder_id]);
	}

	public static function ajax_rename_folder() {
		Http::auth();

		$user_id = \get_current_user_id();
		$folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
		$name = isset($_POST['name']) ? \sanitize_text_field((string) $_POST['name']) : '';
		if ($folder_id <= 0 || $name === '') Http::error('Missing fields');

		if (!\user_can($user_id, 'administrator')) Http::error('Forbidden', 403);
		if ($err = Repository::write_target_error($folder_id)) Http::error($err[0], $err[1]);
		if (self::sibling_folder_exists((int) Repository::folder_row($folder_id)->parent_id, $name, $folder_id)) {
			Http::error(self::DUPLICATE_FOLDER, 400);
		}

		global $wpdb;
		$folders = Schema::table('folders');
		$wpdb->update($folders, [
			'name' => $name,
			'updated_at' => \current_time('mysql'),
		], ['id' => $folder_id], ['%s','%s'], ['%d']);
		Activity::log('rename_folder', 'folder', $folder_id, ['name' => $name], $user_id);

		Http::success(['folderId' => $folder_id]);
	}

	public static function ajax_delete_folder() {
		Http::auth();

		$user_id = \get_current_user_id();
		$folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
		if ($folder_id <= 0) Http::error('Missing folder_id');

		if (!\user_can($user_id, 'administrator')) Http::error('Forbidden', 403);
		if (Repository::is_product_docs($folder_id)) Http::error(Repository::PD_MANAGED_MESSAGE, 403);

		$result = self::delete_folder_recursive($folder_id, $user_id);
		if (!$result['ok']) {
			Http::error($result['message'], 400);
		}

		Http::success(['folderId' => $folder_id]);
	}

	/** Whether $parent_id already holds a folder called $name (other than $exclude_id). */
	private static function sibling_folder_exists(int $parent_id, string $name, int $exclude_id = 0): bool {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(1) FROM ' . Schema::table('folders') . ' WHERE parent_id = %d AND name = %s AND id <> %d',
			$parent_id, $name, $exclude_id
		)) > 0;
	}

	/**
	 * Delete a folder and everything under it. Database rows go first in one transaction (a failed statement rolls
	 * everything back and leaves the bytes alone), then the bytes and directories are removed.
	 */
	private static function delete_folder_recursive($folder_id, $actor_user_id) {
		global $wpdb;
		if (!Repository::folder_row($folder_id)) {
			return ['ok' => false, 'message' => 'Folder not found'];
		}

		$children = [];
		foreach ((array) $wpdb->get_results('SELECT id, parent_id FROM ' . Schema::table('folders')) as $r) {
			$children[(int) $r->parent_id][] = (int) $r->id;
		}
		$folder_ids = [];
		$stack = [(int) $folder_id];
		$seen = [];
		while ($stack) {
			$id = (int) \array_pop($stack);
			if (isset($seen[$id])) continue;
			$seen[$id] = true;
			$folder_ids[] = $id;
			foreach (($children[$id] ?? []) as $cid) {
				$stack[] = (int) $cid;
			}
		}

		$dph = \implode(',', \array_fill(0, \count($folder_ids), '%d'));
		$file_rows = (array) $wpdb->get_results(\call_user_func_array([$wpdb, 'prepare'], \array_merge(
			['SELECT id, folder_id, stored_name FROM ' . Schema::table('files') . " WHERE folder_id IN ({$dph})"],
			$folder_ids
		)));
		$file_ids = \array_map(static fn($f) => (int) $f->id, $file_rows);
		$video_ids = \array_map('intval', (array) $wpdb->get_col(\call_user_func_array([$wpdb, 'prepare'], \array_merge(
			['SELECT id FROM ' . Schema::table('videos') . " WHERE folder_id IN ({$dph})"],
			$folder_ids
		))));

		// Every statement, in order: [sql template, args]. A false from $wpdb->query is a failure (it never throws).
		$stmts = [];
		$ph = static fn(array $ids) => \implode(',', \array_fill(0, \count($ids), '%d'));
		if ($file_ids) {
			$fph = $ph($file_ids);
			$stmts[] = ['DELETE FROM ' . Schema::table('files') . " WHERE id IN ({$fph})", $file_ids];
			$stmts[] = ['DELETE FROM ' . Schema::table('permissions') . " WHERE entity_type = 'file' AND entity_id IN ({$fph})", $file_ids];
			// Uploaded video files carry watch history too; prune it with the same source discriminator so Vimeo rows are untouched.
			$stmts[] = ['DELETE FROM ' . Schema::table('video_views') . " WHERE source = %s AND video_id IN ({$fph})", \array_merge([\Anchor_FM_Media_Progress::SOURCE_FILE], $file_ids)];
		}
		$stmts[] = ['DELETE FROM ' . Schema::table('permissions') . " WHERE entity_type = 'folder' AND entity_id IN ({$dph})", $folder_ids];
		$stmts[] = ['DELETE FROM ' . Schema::table('links') . " WHERE folder_id IN ({$dph})", $folder_ids];
		if ($video_ids) {
			$vph = $ph($video_ids);
			// source is load-bearing: without it this also deletes the watch history of uploaded files whose files.id collides with a videos.id.
			$stmts[] = ['DELETE FROM ' . Schema::table('video_views') . " WHERE source = %s AND video_id IN ({$vph})", \array_merge([\Anchor_FM_Media_Progress::SOURCE_VIMEO], $video_ids)];
			$stmts[] = ['DELETE FROM ' . Schema::table('videos') . " WHERE id IN ({$vph})", $video_ids];
		}
		$stmts[] = ['DELETE FROM ' . Schema::table('folders') . " WHERE id IN ({$dph})", $folder_ids];

		$ok = false !== $wpdb->query('START TRANSACTION');
		foreach ($stmts as [$sql, $args]) {
			if (!$ok) break;
			$ok = false !== $wpdb->query(\call_user_func_array([$wpdb, 'prepare'], \array_merge([$sql], $args)));
		}
		$ok = $ok && Purger::delete_policies('folder', $folder_ids) && Purger::delete_policies('file', $file_ids);
		if (!$ok) {
			$wpdb->query('ROLLBACK');
			return ['ok' => false, 'message' => 'Failed to delete folder'];
		}
		$wpdb->query('COMMIT');

		// Rows are gone: now the bytes and each folder's directory (guard files included).
		foreach ($file_rows as $f) {
			$path = \trailingslashit(Storage::base_dir()) . ((int) $f->folder_id) . '/' . $f->stored_name;
			if (\is_file($path)) {
				@\unlink($path);
			}
		}
		foreach ($folder_ids as $fid) {
			Purger::remove_folder_dir((int) $fid);
		}
		Purger::strip_product_docs($file_ids);

		Activity::log('delete_folder_recursive', 'folder', $folder_id, [
			'deleted_folder_ids' => \count($folder_ids),
			'deleted_file_ids' => \count($file_ids),
		], $actor_user_id);

		return ['ok' => true, 'message' => 'Deleted'];
	}

	public static function ajax_move_folder() {
		Http::auth();
		$user_id = \get_current_user_id();
		if (!\user_can($user_id, 'administrator')) Http::error('Forbidden', 403);

		$folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
		$target_id = isset($_POST['target_folder_id']) ? (int) $_POST['target_folder_id'] : 0;
		if ($folder_id <= 0 || $target_id < 0) Http::error('Missing fields');

		if ($err = Mover::target_error($target_id, $user_id)) Http::error($err[0], $err[1]);
		$existing = Copier::gather_existing_names($target_id);
		$res = Mover::move('folder', $folder_id, $target_id, $existing, $user_id);
		if (\is_wp_error($res)) Http::error($res->get_error_message(), Mover::status_of($res));
		Http::success(['moved' => true, 'name' => $res['name'], 'fromFolderId' => $res['fromFolderId']]);
	}

	/** Batch move: POST items = JSON [{kind, id}], target_folder_id. Per-item outcomes; Undo uses fromFolderId. */
	public static function ajax_move_items() {
		Http::auth();
		$user_id = \get_current_user_id();
		if (!\user_can($user_id, 'administrator')) Http::error('Forbidden', 403);

		$target = isset($_POST['target_folder_id']) ? (int) $_POST['target_folder_id'] : 0;
		$items = \json_decode((string) (isset($_POST['items']) ? \wp_unslash($_POST['items']) : ''), true);
		if (!\is_array($items) || !$items) Http::error('No items to move');
		if (\count($items) > Mover::MAX_ITEMS) Http::error('Too many items', 400);
		if ($err = Mover::target_error($target, $user_id)) Http::error($err[0], $err[1]);

		Http::success(Mover::move_items($items, $target, $user_id));
	}

	public static function ajax_copy_items() {
		Http::auth();
		$user_id = \get_current_user_id();

		$target = isset($_POST['target_folder_id']) ? (int) $_POST['target_folder_id'] : 0;
		$raw = isset($_POST['items']) ? \wp_unslash($_POST['items']) : '';
		$items = \json_decode((string) $raw, true);
		if (!\is_array($items) || !$items) Http::error('No items to copy');
		// 0 is the top level: folders may be copied there (as they may be moved
		// there); files, links and videos always live inside a folder.
		if ($target < 0) Http::error('Missing target folder');

		if ($target > 0 && ($err = Repository::write_target_error($target))) Http::error($err[0], $err[1]);
		if (!Permissions::can_upload_to_folder($target, $user_id)) Http::error('Forbidden', 403);

		$existing = Copier::gather_existing_names($target);
		$results = [];
		$copied = 0;
		$errors = 0;

		foreach ($items as $it) {
			$kind = isset($it['kind']) ? \sanitize_key((string) $it['kind']) : '';
			$id = isset($it['id']) ? (int) $it['id'] : 0;
			$res = ['kind' => $kind, 'sourceId' => $id, 'status' => 'error', 'message' => ''];

			if ($id <= 0 || !\in_array($kind, ['file', 'link', 'video', 'folder'], true)) {
				$res['message'] = 'Invalid item';
				$errors++; $results[] = $res; continue;
			}
			if ($target === 0 && $kind !== 'folder') {
				$res['message'] = 'Only folders can go at the top level';
				$errors++; $results[] = $res; continue;
			}

			$new = null;
			if ($kind === 'file') {
				$row = Repository::file_row($id);
				if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
				if (Repository::is_product_docs((int) $row->folder_id)) { $res['message'] = Repository::PD_MANAGED_MESSAGE; $errors++; $results[] = $res; continue; }
				if (!Permissions::can_manage_file($id, $user_id)) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
				$new = Copier::copy_file_row($row, $target, $existing, ((int) $row->folder_id === $target));
			} elseif ($kind === 'link') {
				$row = Repository::link_row($id);
				if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
				if (Repository::is_product_docs((int) $row->folder_id)) { $res['message'] = Repository::PD_MANAGED_MESSAGE; $errors++; $results[] = $res; continue; }
				if (!Permissions::can_manage_link($id, $user_id)) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
				$new = Copier::copy_link_row($row, $target, $existing, ((int) $row->folder_id === $target));
			} elseif ($kind === 'video') {
				$row = Repository::video_row($id);
				if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
				if (Repository::is_product_docs((int) $row->folder_id)) { $res['message'] = Repository::PD_MANAGED_MESSAGE; $errors++; $results[] = $res; continue; }
				if (!Permissions::can_manage_video($id, $user_id)) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
				$new = Copier::copy_video_row($row, $target, $existing, ((int) $row->folder_id === $target));
			} else { // folder
				if (!\current_user_can('administrator')) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
				$row = Repository::folder_row($id);
				if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
				if (Repository::is_product_docs($id) || Repository::is_product_docs((int) $row->parent_id)) { $res['message'] = Repository::PD_MANAGED_MESSAGE; $errors++; $results[] = $res; continue; }
				if ($id === $target || Repository::is_descendant($target, $id)) {
					$res['message'] = 'Cannot copy a folder into itself or its own subfolder';
					$errors++; $results[] = $res; continue;
				}
				if (Copier::count_folder_tree($id) > Copier::MAX_NODES) {
					$res['message'] = 'Folder is too large to copy';
					$errors++; $results[] = $res; continue;
				}
				$failed = 0;
				$new = Copier::copy_folder_tree($row, $target, $existing, ((int) $row->parent_id === $target), 0, $failed);
				if (!\is_wp_error($new) && $failed > 0) {
					$res['newId'] = (int) $new;
					$res['message'] = \sprintf('Copied, but %d item(s) inside could not be copied', $failed);
					$errors++; $results[] = $res; continue;
				}
			}

			if (\is_wp_error($new)) {
				$res['message'] = $new->get_error_message();
				$errors++; $results[] = $res; continue;
			}
			$res['status'] = 'copied';
			$res['newId'] = (int) $new;
			$copied++;
			$results[] = $res;
		}

		Activity::log('copy_items', 'folder', $target, [
			'copied' => $copied,
			'errors' => $errors,
			'count'  => \count($items),
		], $user_id);
		Http::success([
			'copied'         => $copied,
			'errors'         => $errors,
			'items'          => $results,
			'targetFolderId' => $target,
		]);
	}

	public static function ajax_download_folder() {
		Http::auth();

		$user_id = \get_current_user_id();
		$folder_id = isset($_REQUEST['folder_id']) ? (int) $_REQUEST['folder_id'] : 0;
		if ($folder_id <= 0) Http::error('Missing folder_id');
		if (!Permissions::can_view_folder($folder_id, $user_id)) Http::error('Forbidden', 403);

		$entries = Permissions::with_memo( static function () use ( $folder_id, $user_id ) {
			return self::zip_entries( $folder_id, $user_id );
		} );
		if ( ! $entries ) {
			Http::error( 'No files to download', 400 );
		}
		if ( ! \class_exists( '\\ZipArchive' ) ) {
			Http::error( 'Zip downloads are not available on this server', 500 );
		}
		// check=1 lets the client learn about a refusal as JSON before it navigates the browser to the zip.
		if ( ! empty( $_REQUEST['check'] ) ) {
			Http::success( [ 'files' => \count( $entries['files'] ) ] );
		}

		$tmp = \wp_tempnam( 'anchor-folder-zip' );
		if ( ! $tmp ) Http::error( 'Could not create temp file', 500 );

		$zip = new \ZipArchive();
		if ( $zip->open( $tmp, \ZipArchive::OVERWRITE ) !== true ) {
			@\unlink( $tmp );
			Http::error( 'Could not create zip', 500 );
		}
		foreach ( $entries['files'] as $entry ) {
			$zip->addFile( $entry['path'], $entry['entry'] );
		}
		$zip->close();

		\header( 'Content-Type: application/zip' );
		\header( Http::content_disposition( 'attachment', $entries['name'] . '.zip' ) );
		\header( 'Content-Length: ' . \filesize( $tmp ) );
		\readfile( $tmp );
		@\unlink( $tmp );
		Http::halt();
	}

	/**
	 * The files a zip of $folder_id would hold: every viewable, readable file in the subtree, each with a sanitized
	 * entry name. Call inside Permissions::with_memo(): it permission-checks every file.
	 *
	 * @return array{name:string,files:array<int,array{path:string,entry:string}>}|array{} Empty when nothing qualifies.
	 */
	public static function zip_entries( int $folder_id, int $user_id ): array {
		global $wpdb;
		$folders_table = Schema::table( 'folders' );
		$files_table   = Schema::table( 'files' );

		$children = [];
		foreach ( (array) $wpdb->get_results( "SELECT id, parent_id FROM {$folders_table}" ) as $row ) {
			$children[ (int) $row->parent_id ][] = (int) $row->id;
		}
		$folder_ids = [];
		$stack      = [ $folder_id ];
		$seen       = [];
		while ( $stack ) {
			$id = (int) \array_pop( $stack );
			if ( isset( $seen[ $id ] ) ) continue;
			$seen[ $id ]  = true;
			$folder_ids[] = $id;
			foreach ( ( $children[ $id ] ?? [] ) as $cid ) {
				$stack[] = $cid;
			}
		}
		$placeholders = \implode( ',', \array_fill( 0, \count( $folder_ids ), '%d' ) );
		$query        = "SELECT id, folder_id, original_name, stored_name FROM {$files_table} WHERE folder_id IN ({$placeholders})";
		$file_rows    = (array) $wpdb->get_results( \call_user_func_array( [ $wpdb, 'prepare' ], \array_merge( [ $query ], $folder_ids ) ) );

		// Entry names are relative to the downloaded folder, so ancestor names the user may not see never leak.
		$base_parts = Repository::folder_path_names( $folder_id );
		$base_depth = \count( $base_parts );
		$base       = $base_parts ? $base_parts : [ 'folder-' . $folder_id ];
		$files      = [];
		$used       = [];
		foreach ( $file_rows as $row ) {
			if ( ! Permissions::can_view_file( (int) $row->id, $user_id ) ) continue;
			$path = Storage::path_for( $row );
			if ( ! \file_exists( $path ) || ! \is_readable( $path ) ) continue;
			$parts   = \array_slice( Repository::folder_path_names( (int) $row->folder_id ), $base_depth );
			$files[] = [ 'path' => $path, 'entry' => self::zip_entry_path( $parts, (string) $row->original_name, $used ) ];
		}
		if ( ! $files ) {
			return [];
		}
		return [ 'name' => self::zip_segment( (string) \end( $base ), 'folder-' . $folder_id ), 'files' => $files ];
	}

	/**
	 * Entry name for a zip member: every path segment and the file name go through sanitize_file_name(), so a
	 * folder or file called "../x" or with control characters cannot write outside the extraction directory.
	 * Names that collapse to the same entry get a numeric suffix so nothing is silently shadowed.
	 *
	 * @param string[]            $folder_parts Folder names from the root down.
	 * @param array<string,true>  $used         Entries already taken; updated.
	 */
	public static function zip_entry_path( array $folder_parts, string $name, array &$used ): string {
		$dir = [];
		foreach ( $folder_parts as $part ) {
			$dir[] = self::zip_segment( (string) $part, 'folder' );
		}
		$file  = self::zip_segment( $name, 'file' );
		$stem  = \pathinfo( $file, PATHINFO_FILENAME );
		$ext   = \pathinfo( $file, PATHINFO_EXTENSION );
		$n     = 1;
		$entry = \implode( '/', \array_merge( $dir, [ $file ] ) );
		while ( isset( $used[ \strtolower( $entry ) ] ) ) {
			$entry = \implode( '/', \array_merge( $dir, [ $stem . '-' . ( ++$n ) . ( '' !== $ext ? '.' . $ext : '' ) ] ) );
		}
		$used[ \strtolower( $entry ) ] = true;
		return $entry;
	}

	private static function zip_segment( string $raw, string $fallback ): string {
		$clean = \trim( \sanitize_file_name( $raw ), '.' );
		return '' !== $clean ? $clean : $fallback;
	}
}
