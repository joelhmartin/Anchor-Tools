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
use Anchor\Library\Permissions;
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
		'anchor_fm_copy_items' => 'ajax_copy_items',
		'anchor_fm_download_folder' => 'ajax_download_folder',
	];

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
		if ( $folder_id === $product_docs_id ) {
			Http::error( 'Forbidden', 403 );
		}
		if ( $folder_id > 0 && ! Permissions::can_view_folder( $folder_id, $user_id ) ) {
			Http::error( 'Forbidden', 403 );
		}

		// Read-only from here to the response: the memo scope never spans a write.
		Permissions::begin_memo();
		$lists = Repository::children( $folder_id, $user_id );
		$cap = $folder_id === 0
			? ( \user_can( $user_id, 'administrator' ) ? 'manage' : 'view' )
			: Permissions::folder_capability( $folder_id, $user_id );
		$payload = [
			'folderId' => $folder_id,
			'breadcrumbs' => $folder_id === 0 ? [] : Repository::breadcrumbs( $folder_id ),
			'folders' => $lists['folders'],
			'links' => $lists['links'],
			'files' => $lists['files'],
			'videos' => $lists['videos'],
			'capability' => $cap,
			'isProductDocs' => $folder_id === $product_docs_id,
			// Every other folder this user may open, so expanding one costs no request at all. Sent on every
			// listing, which is what keeps it fresh: the client's copy is never older than its last navigation.
			'contents' => Repository::contents_map( $user_id ),
		];
		Permissions::end_memo();
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

		$result = self::delete_folder_recursive($folder_id, $user_id);
		if (!$result['ok']) {
			Http::error($result['message'], 400);
		}

		Http::success(['folderId' => $folder_id]);
	}

	private static function delete_folder_recursive($folder_id, $actor_user_id) {
		global $wpdb;
		$folders_table = Schema::table('folders');
		$files_table = Schema::table('files');
		$perms_table = Schema::table('permissions');
		$links_table = Schema::table('links');
		$videos_table = Schema::table('videos');
		$video_views_table = Schema::table('video_views');

		$folder = Repository::folder_row($folder_id);
		if (!$folder) {
			return ['ok' => false, 'message' => 'Folder not found'];
		}

		// Collect folder IDs (folder + all descendants)
		$rows = $wpdb->get_results("SELECT id, parent_id FROM {$folders_table}");
		$children = [];
		foreach ((array) $rows as $r) {
			$pid = (int) $r->parent_id;
			if (!isset($children[$pid])) $children[$pid] = [];
			$children[$pid][] = (int) $r->id;
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

		if (!$folder_ids) {
			return ['ok' => false, 'message' => 'Nothing to delete'];
		}

		// Fetch and delete files on disk
		$placeholders = \implode(',', \array_fill(0, \count($folder_ids), '%d'));
		$query = "SELECT id, folder_id, stored_name, original_name FROM {$files_table} WHERE folder_id IN ({$placeholders})";
		$args = \array_merge([$query], $folder_ids);
		$sql = \call_user_func_array([$wpdb, 'prepare'], $args);
		$file_rows = $wpdb->get_results($sql);

		$file_ids = [];
		foreach ((array) $file_rows as $f) {
			$file_ids[] = (int) $f->id;
			$path = \trailingslashit(Storage::base_dir()) . ((int) $f->folder_id) . '/' . $f->stored_name;
			if (\file_exists($path) && \is_file($path)) {
				@\unlink($path);
			}
		}

		// Delete DB rows
		$wpdb->query("START TRANSACTION");
		try {
			if ($file_ids) {
				$fph = \implode(',', \array_fill(0, \count($file_ids), '%d'));
				$wpdb->query(\call_user_func_array([$wpdb, 'prepare'], \array_merge(
					["DELETE FROM {$files_table} WHERE id IN ({$fph})"],
					$file_ids
				)));

				$wpdb->query(\call_user_func_array([$wpdb, 'prepare'], \array_merge(
					["DELETE FROM {$perms_table} WHERE entity_type = 'file' AND entity_id IN ({$fph})"],
					$file_ids
				)));

				// Uploaded video files carry watch history too; prune it with
				// the same source discriminator so Vimeo rows are untouched.
				$wpdb->query(\call_user_func_array([$wpdb, 'prepare'], \array_merge(
					["DELETE FROM {$video_views_table} WHERE source = %s AND video_id IN ({$fph})"],
					[\Anchor_FM_Media_Progress::SOURCE_FILE],
					$file_ids
				)));
			}

			$dph = \implode(',', \array_fill(0, \count($folder_ids), '%d'));
			$wpdb->query(\call_user_func_array([$wpdb, 'prepare'], \array_merge(
				["DELETE FROM {$perms_table} WHERE entity_type = 'folder' AND entity_id IN ({$dph})"],
				$folder_ids
			)));

			// Remove links in these folders.
			$wpdb->query(\call_user_func_array([$wpdb, 'prepare'], \array_merge(
				["DELETE FROM {$links_table} WHERE folder_id IN ({$dph})"],
				$folder_ids
			)));

			// Remove videos in these folders and their watch-history rows.
			$video_ids = $wpdb->get_col(\call_user_func_array([$wpdb, 'prepare'], \array_merge(
				["SELECT id FROM {$videos_table} WHERE folder_id IN ({$dph})"],
				$folder_ids
			)));
			if ($video_ids) {
				$vph = \implode(',', \array_fill(0, \count($video_ids), '%d'));
				// source is load-bearing: without it this also deletes the
				// watch history of uploaded files whose files.id collides
				// with one of these videos.id values.
				$wpdb->query(\call_user_func_array([$wpdb, 'prepare'], \array_merge(
					["DELETE FROM {$video_views_table} WHERE source = %s AND video_id IN ({$vph})"],
					[\Anchor_FM_Media_Progress::SOURCE_VIMEO],
					\array_map('intval', $video_ids)
				)));
				$wpdb->query(\call_user_func_array([$wpdb, 'prepare'], \array_merge(
					["DELETE FROM {$videos_table} WHERE id IN ({$vph})"],
					\array_map('intval', $video_ids)
				)));
			}

			$wpdb->query(\call_user_func_array([$wpdb, 'prepare'], \array_merge(
				["DELETE FROM {$folders_table} WHERE id IN ({$dph})"],
				$folder_ids
			)));

			$wpdb->query("COMMIT");
		} catch (\Throwable $e) {
			$wpdb->query("ROLLBACK");
			return ['ok' => false, 'message' => 'Failed to delete folder'];
		}

		// Best-effort: remove now-empty storage dirs for folders deleted.
		foreach ($folder_ids as $fid) {
			$dir = \trailingslashit(Storage::base_dir()) . ((int) $fid);
			if (\is_dir($dir)) {
				@\rmdir($dir);
			}
		}

		Activity::log('delete_folder_recursive', 'folder', $folder_id, [
			'deleted_folder_ids' => \count($folder_ids),
			'deleted_file_ids' => \count($file_ids),
		], $actor_user_id);

		return ['ok' => true, 'message' => 'Deleted'];
	}

	public static function ajax_move_folder() {
		Http::auth();
		if (!\current_user_can('administrator')) Http::error('Forbidden', 403);

		$folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
		$target_id = isset($_POST['target_folder_id']) ? (int) $_POST['target_folder_id'] : 0;
		if ($folder_id <= 0 || $target_id < 0) Http::error('Missing fields');
		if ($folder_id === $target_id) Http::error('Cannot move into itself', 400);

		if (Repository::is_descendant($target_id, $folder_id)) {
			Http::error('Cannot move into a child folder', 400);
		}

		global $wpdb;
		$folders = Schema::table('folders');
		$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$folders} WHERE id = %d", $folder_id));
		if (!$exists) Http::error('Folder not found', 404);

		$target_exists = $target_id === 0 ? 1 : (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$folders} WHERE id = %d", $target_id));
		if (!$target_exists) Http::error('Target not found', 404);

		$wpdb->update($folders, [
			'parent_id' => $target_id,
			'updated_at' => \current_time('mysql'),
		], ['id' => $folder_id], ['%d','%s'], ['%d']);

		Activity::log('move_folder', 'folder', $folder_id, ['to' => $target_id], \get_current_user_id());
		Http::success(['moved' => true]);
	}

	public static function ajax_copy_items() {
		Http::auth();
		$user_id = \get_current_user_id();

		$target = isset($_POST['target_folder_id']) ? (int) $_POST['target_folder_id'] : 0;
		$raw = isset($_POST['items']) ? \wp_unslash($_POST['items']) : '';
		$items = \json_decode((string) $raw, true);
		if (!\is_array($items) || !$items) Http::error('No items to copy');
		if ($target <= 0) Http::error('Missing target folder');

		if (!Repository::folder_row($target)) Http::error('Target folder not found', 404);
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

			$new = null;
			if ($kind === 'file') {
				$row = Repository::file_row($id);
				if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
				if (!Permissions::can_manage_file($id, $user_id)) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
				$new = Copier::copy_file_row($row, $target, $existing, ((int) $row->folder_id === $target));
			} elseif ($kind === 'link') {
				$row = Repository::link_row($id);
				if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
				if (!Permissions::can_manage_link($id, $user_id)) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
				$new = Copier::copy_link_row($row, $target, $existing, ((int) $row->folder_id === $target));
			} elseif ($kind === 'video') {
				$row = Repository::video_row($id);
				if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
				if (!Permissions::can_manage_video($id, $user_id)) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
				$new = Copier::copy_video_row($row, $target, $existing, ((int) $row->folder_id === $target));
			} else { // folder
				if (!\current_user_can('administrator')) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
				$row = Repository::folder_row($id);
				if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
				if ($id === $target || Repository::is_descendant($target, $id)) {
					$res['message'] = 'Cannot copy a folder into itself or its own subfolder';
					$errors++; $results[] = $res; continue;
				}
				if (Copier::count_folder_tree($id) > Copier::MAX_NODES) {
					$res['message'] = 'Folder is too large to copy';
					$errors++; $results[] = $res; continue;
				}
				$new = Copier::copy_folder_tree($row, $target, $existing, ((int) $row->parent_id === $target));
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

		// Walks the whole subtree permission-checking every file; see begin_perm_memo().
		Permissions::begin_memo();

		global $wpdb;
		$folders_table = Schema::table('folders');
		$files_table = Schema::table('files');

		$all_folders = $wpdb->get_results("SELECT id, parent_id, name FROM {$folders_table}");
		$children = [];
		foreach ((array) $all_folders as $row) {
			$pid = (int) $row->parent_id;
			if (!isset($children[$pid])) $children[$pid] = [];
			$children[$pid][] = (int) $row->id;
		}

		$folder_ids = [];
		$stack = [$folder_id];
		$seen = [];
		while ($stack) {
			$id = (int) \array_pop($stack);
			if (isset($seen[$id])) continue;
			$seen[$id] = true;
			$folder_ids[] = $id;
			foreach (($children[$id] ?? []) as $cid) {
				$stack[] = $cid;
			}
		}

		$file_rows = [];
		if ($folder_ids) {
			$placeholders = \implode(',', \array_fill(0, \count($folder_ids), '%d'));
			$query = "SELECT id, folder_id, original_name, stored_name, mime_type, size FROM {$files_table} WHERE folder_id IN ({$placeholders})";
			$args = \array_merge([$query], $folder_ids);
			$sql = \call_user_func_array([$wpdb, 'prepare'], $args);
			$file_rows = $wpdb->get_results($sql);
		}

		if (!$file_rows) {
			Http::error('No files to download', 400);
		}

		$tmp = \wp_tempnam('anchor-folder-zip');
		if (!$tmp) Http::error('Could not create temp file', 500);

		$zip = new \ZipArchive();
		if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
			@\unlink($tmp);
			Http::error('Could not create zip', 500);
		}

		$folder_name_parts = Repository::folder_path_names($folder_id);
		$folder_base = $folder_name_parts ? \implode('/', $folder_name_parts) : ('folder-' . $folder_id);

		foreach ($file_rows as $row) {
			if (!Permissions::can_view_file((int) $row->id, $user_id)) continue;
			$path = Storage::path_for($row);
			if (!\file_exists($path) || !\is_readable($path)) continue;
			$relative_parts = Repository::folder_path_names((int) $row->folder_id);
			$rel_dir = $relative_parts ? \implode('/', $relative_parts) : $folder_base;
			$zip_path = $rel_dir . '/' . $row->original_name;
			$zip->addFile($path, $zip_path);
		}

		$zip->close();

		$download_name = $folder_base . '.zip';
		\header('Content-Type: application/zip');
		\header('Content-Disposition: attachment; filename="' . $download_name . '"');
		\header('Content-Length: ' . \filesize($tmp));
		\readfile($tmp);
		@\unlink($tmp);
		Http::halt();
	}
}
