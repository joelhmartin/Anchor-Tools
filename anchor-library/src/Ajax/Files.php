<?php
/**
 * Library AJAX: file upload, rename, move, delete, preview, streaming, and external links.
 *
 * Ported from Anchor Private File Manager 2.17.0; request and response shapes, nonce and permission checks unchanged.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library\Ajax;

use Anchor\Library\Activity;
use Anchor\Library\Http;
use Anchor\Library\Permissions;
use Anchor\Library\PermissionWrites;
use Anchor\Library\Repository;
use Anchor\Library\Schema;
use Anchor\Library\Storage;
use Anchor\Library\StorageMonitor;
use Anchor\Library\Uploads;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Files {

	/** AJAX action => handler; Ajax\Registry hooks each as wp_ajax_{action}. */
	public const ACTIONS = [
		'anchor_fm_upload' => 'ajax_upload',
		'anchor_fm_delete_file' => 'ajax_delete_file',
		'anchor_fm_rename_file' => 'ajax_rename_file',
		'anchor_fm_move_file' => 'ajax_move_file',
		'anchor_fm_preview' => 'ajax_preview',
		'anchor_fm_stream' => 'ajax_stream',
		'anchor_fm_create_link' => 'ajax_create_link',
		'anchor_fm_update_link' => 'ajax_update_link',
		'anchor_fm_delete_link' => 'ajax_delete_link',
	];

	public static function ajax_upload() {
		Http::auth();

		$user_id = \get_current_user_id();
		$folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
		if ($folder_id <= 0) Http::error('Missing folder_id');
		if (!Permissions::can_upload_to_folder($folder_id, $user_id)) Http::error('Forbidden', 403);

		if (empty($_FILES['files'])) Http::error('No files');
		StorageMonitor::require_not_exposed();

		$folder_dir = Storage::ensure_folder_dir($folder_id);

		global $wpdb;
		$files_table = Schema::table('files');

		$uploaded = [];
		$rejected = [];
		$names = (array) $_FILES['files']['name'];
		$tmp_names = (array) $_FILES['files']['tmp_name'];
		$sizes = (array) $_FILES['files']['size'];
		$errors = (array) $_FILES['files']['error'];

		for ($i = 0; $i < \count($names); $i++) {
			if (!isset($tmp_names[$i])) continue;
			if ((int) $errors[$i] !== UPLOAD_ERR_OK) continue;

			$original = (string) $names[$i];
			$tmp = (string) $tmp_names[$i];
			$size = (int) $sizes[$i];

			$valid = Uploads::validate_type($tmp, \sanitize_file_name($original));
			if ($valid === false) {
				$rejected[] = $original;
				continue;
			}
			$mime = $valid['type'];

			$unique = Storage::reserve_stored_name($folder_dir, $valid['filename']);
			if ($unique === false) {
				$rejected[] = $original;
				continue;
			}
			$dest = \trailingslashit($folder_dir) . $unique;
			if (!@\move_uploaded_file($tmp, $dest)) {
				@\unlink($dest);
				continue;
			}

			$sha1 = @\sha1_file($dest) ?: null;
			$now = \current_time('mysql');
			$wpdb->insert($files_table, [
				'folder_id' => $folder_id,
				'original_name' => $original,
				'stored_name' => $unique,
				'mime_type' => $mime,
				'size' => $size,
				'sha1' => $sha1,
				'uploader_user_id' => $user_id,
				'created_at' => $now,
			], ['%d','%s','%s','%s','%d','%s','%d','%s']);

			$file_id = (int) $wpdb->insert_id;
			$row = Repository::file_row($file_id);
			$uploaded[] = [
				'id' => $file_id,
				'name' => $original,
				'mime' => $mime,
				'size' => $size,
			];

			Activity::log('upload_file', 'file', $file_id, ['folder_id' => $folder_id, 'name' => $original], $user_id);
			if ($row) Uploads::notify($row, $user_id);
		}

		Http::success(['uploaded' => $uploaded, 'rejected' => $rejected]);
	}

	public static function ajax_delete_file() {
		Http::auth();

		$user_id = \get_current_user_id();
		$file_id = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
		if ($file_id <= 0) Http::error('Missing file_id');
		if (!Permissions::can_manage_file($file_id, $user_id)) Http::error('Forbidden', 403);

		$file = Repository::file_row($file_id);
		if (!$file) Http::error('Not found', 404);

		$path = Storage::path_for($file);
		if (\file_exists($path)) {
			@\unlink($path);
		}

		global $wpdb;
		$files_table = Schema::table('files');
		$perms_table = Schema::table('permissions');
		$wpdb->delete($files_table, ['id' => $file_id], ['%d']);
		$wpdb->delete($perms_table, ['entity_type' => 'file', 'entity_id' => $file_id], ['%s','%d']);
		// Uploaded videos accumulate watch history under source='file'.
		// Without this the rows outlive the file forever, keyed to an id
		// that will eventually be reused.
		$wpdb->delete(
			Schema::table('video_views'),
			['source' => \Anchor_FM_Media_Progress::SOURCE_FILE, 'video_id' => $file_id],
			['%s','%d']
		);

		Activity::log('delete_file', 'file', $file_id, ['name' => $file->original_name], $user_id);
		Http::success(['fileId' => $file_id]);
	}

	public static function ajax_rename_file() {
		Http::auth();
		$user_id = \get_current_user_id();

		$file_id = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
		$name = isset($_POST['name']) ? \sanitize_file_name((string) $_POST['name']) : '';
		if ($file_id <= 0 || $name === '') Http::error('Missing fields');
		if (!\user_can($user_id, 'administrator') || !Permissions::can_manage_file($file_id, $user_id)) {
			Http::error('Forbidden', 403);
		}

		global $wpdb;
		$files = Schema::table('files');
		// Only the display/original name changes; stored_name on disk is untouched.
		$wpdb->update($files, ['original_name' => $name], ['id' => $file_id], ['%s'], ['%d']);
		Activity::log('rename_file', 'file', $file_id, ['name' => $name], $user_id);
		Http::success(['fileId' => $file_id, 'name' => $name]);
	}

	public static function ajax_move_file() {
		Http::auth();

		$user_id = \get_current_user_id();
		$file_id = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
		$target_folder = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
		if ($file_id <= 0 || $target_folder <= 0) Http::error('Missing fields');

		if (!Permissions::can_manage_file($file_id, $user_id)) Http::error('Forbidden', 403);
		if (!Permissions::can_upload_to_folder($target_folder, $user_id)) Http::error('Forbidden', 403);

		$file = Repository::file_row($file_id);
		if (!$file) Http::error('Not found', 404);

		if ((int) $file->folder_id === $target_folder) {
			Http::success(['moved' => true]);
		}

		$current_path = Storage::path_for($file);
		if (!\file_exists($current_path)) Http::error('File missing on disk', 404);

		// Never reuse the source's stored name blindly: a same-named file in
		// the destination would be overwritten, and two rows would then share
		// one path (AFM-05).
		$target_dir = Storage::ensure_folder_dir($target_folder);
		$stored = Storage::reserve_stored_name($target_dir, $file->stored_name);
		if ($stored === false) Http::error('Could not move file', 500);
		$dest = \trailingslashit($target_dir) . $stored;
		if (!@\rename($current_path, $dest)) {
			@\unlink($dest);
			Http::error('Could not move file', 500);
		}

		global $wpdb;
		$files_table = Schema::table('files');
		$updated = $wpdb->update($files_table, [
			'folder_id' => $target_folder,
			'stored_name' => $stored,
		], ['id' => $file_id], ['%d', '%s'], ['%d']);
		if ($updated === false) {
			// Put the bytes back where the row still says they are.
			@\rename($dest, $current_path);
			Http::error('Could not move file', 500);
		}

		// Ensure view permissions follow the destination folder (when no explicit file perms exist).
		PermissionWrites::copy_view('folder', $target_folder, 'file', $file_id, false);

		Activity::log('move_file', 'file', $file_id, ['from' => (int) $file->folder_id, 'to' => $target_folder], $user_id);
		Http::success(['moved' => true]);
	}

	public static function ajax_preview() {
		Http::auth();

		$user_id = \get_current_user_id();
		$file_id = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
		if ($file_id <= 0) Http::error('Missing file_id');
		if (!Permissions::can_view_file($file_id, $user_id)) Http::error('Forbidden', 403);

		$file = Repository::file_row($file_id);
		if (!$file) Http::error('Not found', 404);

		// The row alone is not enough to promise a viewer. Every preview type
		// below renders by pointing the browser at ajax_stream(), so if the
		// bytes are unreachable the client paints a blank <iframe>/<img> and a
		// Download button that 404s -- which is how a whole-store outage read
		// to users as "the preview is just broken". Check once, here, and say so.
		$path = Storage::path_for($file);
		$bytes_readable = @\file_exists($path) && @\is_readable($path);
		if (!$bytes_readable) {
			Storage::log_refusal($file_id, 'preview: ' . Storage::describe_unreadable($path));
		}

		$mime = (string) $file->mime_type;
		$type = 'none';

		if (\strpos($mime, 'image/') === 0) {
			$type = 'image';
		} elseif ($mime === 'application/pdf') {
			$type = 'pdf';
		} elseif (\strpos($mime, 'video/') === 0) {
			// Covers every video extension on the upload allow-list
			// (mp4, mov, m4v, webm). Browser-level playability varies —
			// .mov in particular fails in Firefox — so the client falls back
			// to the download view on a media error.
			$type = 'video';
		} elseif (\in_array($mime, ['text/plain', 'text/csv', 'application/json'], true)) {
			$type = 'text';
		}

		$nonce = \wp_create_nonce('anchor_fm_stream_' . $file_id);
		$inline_url = \add_query_arg([
			'action' => 'anchor_fm_stream',
			'file_id' => $file_id,
			'disposition' => 'inline',
			'nonce' => $nonce,
		], \admin_url('admin-ajax.php'));
		$download_url = \add_query_arg([
			'action' => 'anchor_fm_stream',
			'file_id' => $file_id,
			'disposition' => 'attachment',
			'nonce' => $nonce,
		], \admin_url('admin-ajax.php'));

		$text_excerpt = null;
		if ($type === 'text' && $bytes_readable) {
			$raw = @\file_get_contents($path, false, null, 0, 4000);
			if (\is_string($raw)) {
				$text_excerpt = \wp_strip_all_tags($raw);
			}
		}

		Http::success([
			'file' => [
				'id' => (int) $file->id,
				'name' => $file->original_name,
				'mime' => $file->mime_type,
				'size' => (int) $file->size,
				'createdAt' => $file->created_at,
				'uploadedBy' => !empty($file->uploader_user_id) ? (int) $file->uploader_user_id : 0,
			],
			'preview' => [
				'type' => $bytes_readable ? $type : 'unavailable',
				'available' => $bytes_readable,
				'inlineUrl' => $inline_url,
				'downloadUrl' => $download_url,
				'textExcerpt' => $text_excerpt,
			],
			'capability' => Permissions::file_capability($file_id, $user_id),
		]);
	}

	public static function ajax_stream() {
		if (!\is_user_logged_in()) {
			\status_header(401);
			Http::halt();
		}

		$file_id = isset($_GET['file_id']) ? (int) $_GET['file_id'] : 0;
		$nonce = isset($_GET['nonce']) ? (string) $_GET['nonce'] : '';
		$disposition = isset($_GET['disposition']) ? (string) $_GET['disposition'] : 'attachment';

		if ($file_id <= 0 || !$nonce || !\wp_verify_nonce($nonce, 'anchor_fm_stream_' . $file_id)) {
			Storage::log_refusal($file_id, 'bad or expired nonce');
			\status_header(403);
			Http::halt();
		}

		$user_id = \get_current_user_id();
		if (!Permissions::can_view_file($file_id, $user_id)) {
			Storage::log_refusal($file_id, 'user ' . $user_id . ' lacks view capability');
			\status_header(403);
			Http::halt();
		}

		$file = Repository::file_row($file_id);
		if (!$file) {
			Storage::log_refusal($file_id, 'no database row');
			\status_header(404);
			Http::halt();
		}

		// Silenced for the same reason as Storage::describe_unreadable(): a store outside open_basedir warns on
		// every stat, and warning text in front of a file body corrupts the download. The refusal is logged.
		$path = Storage::path_for($file);
		if (!@\file_exists($path) || !@\is_readable($path)) {
			Storage::log_refusal($file_id, Storage::describe_unreadable($path));
			\status_header(404);
			Http::halt();
		}

		$disp = $disposition === 'inline' ? 'inline' : 'attachment';
		$plan = Storage::plan(isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : '', (int) \filesize($path));

		// One inline playthrough issues dozens of range requests, so for previews log only the opening one or the
		// activity table fills with noise. Attachments are NOT deduplicated: they generate no range storm, and
		// suppressing them would let `Range: bytes=1-` pull a whole document with zero download_file rows.
		if ($disp === 'attachment' || $plan['opening']) {
			Activity::log($disp === 'inline' ? 'preview_file' : 'download_file', 'file', $file_id, [], $user_id);
		}

		Storage::stream($path, $file->mime_type, $disp, $file->original_name, Storage::discard_buffers());
	}

	public static function ajax_create_link() {
		Http::auth();

		$user_id = \get_current_user_id();
		if (!\current_user_can('administrator')) Http::error('Forbidden', 403);

		$folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
		$title = isset($_POST['title']) ? \sanitize_text_field((string) $_POST['title']) : '';
		$url = isset($_POST['url']) ? \esc_url_raw((string) $_POST['url']) : '';
		if ($folder_id <= 0 || $title === '' || $url === '') Http::error('Missing fields');
		if (!Permissions::can_manage_folder($folder_id, $user_id)) Http::error('Forbidden', 403);

		global $wpdb;
		$links = Schema::table('links');
		$now = \current_time('mysql');
		$wpdb->insert($links, [
			'folder_id' => $folder_id,
			'title' => $title,
			'url' => $url,
			'created_by' => $user_id,
			'created_at' => $now,
			'updated_at' => $now,
		], ['%d','%s','%s','%d','%s','%s']);
		$link_id = (int) $wpdb->insert_id;

		Activity::log('create_link', 'link', $link_id, ['folder_id' => $folder_id, 'title' => $title], $user_id);
		Http::success(['linkId' => $link_id]);
	}

	public static function ajax_update_link() {
		Http::auth();

		$user_id = \get_current_user_id();
		if (!\current_user_can('administrator')) Http::error('Forbidden', 403);

		$link_id = isset($_POST['link_id']) ? (int) $_POST['link_id'] : 0;
		$title = isset($_POST['title']) ? \sanitize_text_field((string) $_POST['title']) : '';
		$url = isset($_POST['url']) ? \esc_url_raw((string) $_POST['url']) : '';
		if ($link_id <= 0 || $title === '' || $url === '') Http::error('Missing fields');
		if (!Permissions::can_manage_link($link_id, $user_id)) Http::error('Forbidden', 403);

		global $wpdb;
		$links = Schema::table('links');
		$wpdb->update($links, [
			'title' => $title,
			'url' => $url,
			'updated_at' => \current_time('mysql'),
		], ['id' => $link_id], ['%s','%s','%s'], ['%d']);

		Activity::log('update_link', 'link', $link_id, ['title' => $title], $user_id);
		Http::success(['linkId' => $link_id]);
	}

	public static function ajax_delete_link() {
		Http::auth();

		$user_id = \get_current_user_id();
		if (!\current_user_can('administrator')) Http::error('Forbidden', 403);

		$link_id = isset($_POST['link_id']) ? (int) $_POST['link_id'] : 0;
		if ($link_id <= 0) Http::error('Missing link_id');
		if (!Permissions::can_manage_link($link_id, $user_id)) Http::error('Forbidden', 403);

		global $wpdb;
		$links = Schema::table('links');
		$wpdb->delete($links, ['id' => $link_id], ['%d']);

		Activity::log('delete_link', 'link', $link_id, [], $user_id);
		Http::success(['linkId' => $link_id]);
	}
}
