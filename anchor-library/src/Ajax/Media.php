<?php
/**
 * Library AJAX: Vimeo videos, watch progress and resume points, and the daily resume-position prune.
 *
 * Ported from Anchor Private File Manager 2.17.0; request and response shapes, nonce and permission checks unchanged.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library\Ajax;

use Anchor\Library\Activity;
use Anchor\Library\Http;
use Anchor\Library\Permissions;
use Anchor\Library\Repository;
use Anchor\Library\Schema;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Media {

	/** AJAX action => handler; Ajax\Registry hooks each as wp_ajax_{action}. */
	public const ACTIONS = [
		'anchor_fm_vimeo_get' => 'ajax_vimeo_get',
		'anchor_fm_vimeo_resolve' => 'ajax_vimeo_resolve',
		'anchor_fm_vimeo_add' => 'ajax_vimeo_add',
		'anchor_fm_vimeo_update' => 'ajax_vimeo_update',
		'anchor_fm_vimeo_delete' => 'ajax_vimeo_delete',
		'anchor_fm_vimeo_progress' => 'ajax_vimeo_progress',
		'anchor_fm_media_progress' => 'ajax_media_progress',
		'anchor_fm_media_resume' => 'ajax_media_resume',
		'anchor_fm_vimeo_history' => 'ajax_vimeo_history',
	];

	/** Ceiling on one bulk video import: each entry costs an outbound oEmbed call. */
	private const VIMEO_BULK_MAX = 50;

	public static function ajax_vimeo_get() {
		Http::auth();
		$user_id = \get_current_user_id();

		$video_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
		if ($video_id <= 0) Http::error('Missing video_id');

		$video = Repository::video_row($video_id);
		if (!$video) Http::error('Not found', 404);
		if (!Permissions::can_view_video($video_id, $user_id)) Http::error('Forbidden', 403);

		Http::success(['video' => [
			'id' => (int) $video->id,
			'title' => $video->title,
			'vimeoId' => $video->vimeo_id,
			'vimeoHash' => isset($video->vimeo_hash) ? (string) $video->vimeo_hash : '',
			'thumbnailUrl' => isset($video->thumbnail_url) ? (string) $video->thumbnail_url : '',
			'folderId' => (int) $video->folder_id,
		]]);
	}

	/**
	 * Resolve a pasted blob of Vimeo references into reviewable entries.
	 * Read-only: writes nothing, so the UI can show titles before committing.
	 */
	public static function ajax_vimeo_resolve() {
		Http::auth();
		$user_id = \get_current_user_id();

		$folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
		$raw = isset($_POST['refs']) ? (string) \wp_unslash($_POST['refs']) : '';

		if ($folder_id <= 0) Http::error('Pick a folder first');
		if (!\user_can($user_id, 'administrator') || !Permissions::can_manage_folder($folder_id, $user_id)) {
			Http::error('Forbidden', 403);
		}

		$refs = \Anchor_FM_Vimeo::split_refs($raw);
		if (empty($refs)) Http::error('Paste at least one Vimeo link or ID');
		if (\count($refs) > self::VIMEO_BULK_MAX) {
			Http::error(\sprintf('Too many at once — %d max, got %d', self::VIMEO_BULK_MAX, \count($refs)));
		}

		$entries = [];
		$seen = [];
		foreach ($refs as $ref) {
			$parsed = \Anchor_FM_Vimeo::parse_ref($ref);
			if ($parsed['id'] === '') {
				$entries[] = [
					'input' => $ref, 'vimeoId' => '', 'hash' => '', 'title' => '',
					'thumbnailUrl' => '', 'error' => 'Could not read a Vimeo ID from this',
				];
				continue;
			}
			// Same video pasted twice: keep one row rather than silently
			// importing a duplicate.
			if (isset($seen[$parsed['id']])) {
				$entries[] = [
					'input' => $ref, 'vimeoId' => $parsed['id'], 'hash' => $parsed['hash'],
					'title' => '', 'thumbnailUrl' => '', 'error' => 'Duplicate of another entry in this list',
				];
				continue;
			}
			$seen[$parsed['id']] = true;

			$meta = \Anchor_FM_Vimeo::fetch_meta($parsed['id'], $parsed['hash']);
			if (\is_wp_error($meta)) {
				// Non-fatal: importable, just un-describable. Title falls back
				// and the UI surfaces why so it can be corrected by hand.
				$entries[] = [
					'input' => $ref, 'vimeoId' => $parsed['id'], 'hash' => $parsed['hash'],
					'title' => \Anchor_FM_Vimeo::fallback_title($parsed['id']),
					'thumbnailUrl' => '', 'error' => '',
					'warning' => $meta->get_error_message(),
				];
				continue;
			}
			$entries[] = [
				'input' => $ref, 'vimeoId' => $parsed['id'], 'hash' => $parsed['hash'],
				'title' => $meta['title'], 'thumbnailUrl' => $meta['thumbnail_url'], 'error' => '',
			];
		}

		Http::success(['entries' => $entries]);
	}

	public static function ajax_vimeo_add() {
		Http::auth();
		$user_id = \get_current_user_id();

		$folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
		if ($folder_id <= 0) Http::error('Pick a folder first');
		if (!\user_can($user_id, 'administrator') || !Permissions::can_manage_folder($folder_id, $user_id)) {
			Http::error('Forbidden', 403);
		}

		// Accepts either a bulk `videos` array or the single-video
		// {title, vimeo} shape the old modal posted.
		$incoming = [];
		if (isset($_POST['videos']) && \is_array($_POST['videos'])) {
			$incoming = $_POST['videos'];
		} elseif (isset($_POST['vimeo'])) {
			$incoming = [[
				'vimeo' => (string) $_POST['vimeo'],
				'title' => isset($_POST['title']) ? (string) $_POST['title'] : '',
			]];
		}
		if (empty($incoming)) Http::error('Nothing to add');
		if (\count($incoming) > self::VIMEO_BULK_MAX) {
			Http::error(\sprintf('Too many at once — %d max, got %d', self::VIMEO_BULK_MAX, \count($incoming)));
		}

		global $wpdb;
		$videos = Schema::table('videos');
		$now = \current_time('mysql');

		$added = [];
		$failed = [];
		foreach ($incoming as $row) {
			if (!\is_array($row)) continue;
			$raw    = isset($row['vimeo']) && \is_scalar($row['vimeo']) ? (string) \wp_unslash($row['vimeo']) : '';
			$title  = isset($row['title']) && \is_scalar($row['title']) ? \sanitize_text_field((string) \wp_unslash($row['title'])) : '';
			$parsed = \Anchor_FM_Vimeo::parse_ref($raw);

			if ($parsed['id'] === '') {
				$failed[] = ['input' => $raw, 'message' => 'Could not read a Vimeo ID from that input'];
				continue;
			}
			// An explicit hash from the client wins; it may carry a hash the
			// bare id in `vimeo` cannot express.
			$hash = isset($row['hash']) && \is_scalar($row['hash'])
				? \preg_replace('/[^A-Za-z0-9]/', '', (string) \wp_unslash($row['hash']))
				: '';
			if ($hash === '') { $hash = $parsed['hash']; }

			// Title is optional now — it defaults to the Vimeo title, and to a
			// readable placeholder when Vimeo can't be reached.
			$thumb = isset($row['thumbnailUrl']) && \is_scalar($row['thumbnailUrl'])
				? \esc_url_raw((string) \wp_unslash($row['thumbnailUrl']))
				: '';
			if ($title === '') {
				$meta = \Anchor_FM_Vimeo::fetch_meta($parsed['id'], $hash);
				if (!\is_wp_error($meta)) {
					$title = \sanitize_text_field($meta['title']);
					if ($thumb === '') { $thumb = \esc_url_raw($meta['thumbnail_url']); }
				}
				if ($title === '') { $title = \Anchor_FM_Vimeo::fallback_title($parsed['id']); }
			}

			$ok = $wpdb->insert($videos, [
				'folder_id'     => $folder_id,
				'vimeo_id'      => $parsed['id'],
				'vimeo_hash'    => $hash,
				'title'         => $title,
				'thumbnail_url' => $thumb,
				'created_by'    => $user_id,
				'created_at'    => $now,
				'updated_at'    => $now,
			], ['%d','%s','%s','%s','%s','%d','%s','%s']);

			if ($ok === false) {
				$failed[] = ['input' => $raw, 'message' => 'Database error saving this video'];
				continue;
			}

			$video_id = (int) $wpdb->insert_id;
			Activity::log('create_video', 'video', $video_id, ['folder_id' => $folder_id, 'vimeo_id' => $parsed['id']], $user_id);
			$added[] = [
				'videoId' => $video_id, 'vimeoId' => $parsed['id'],
				'title' => $title, 'thumbnailUrl' => $thumb,
			];
		}

		// Every row failing is an error; a partial import is a success that
		// reports its casualties.
		if (empty($added)) {
			$first = !empty($failed) ? $failed[0]['message'] : 'Nothing could be added';
			Http::error($first);
		}

		Http::success([
			'added'  => $added,
			'failed' => $failed,
			// Single-video callers still read these.
			'videoId' => $added[0]['videoId'],
			'vimeoId' => $added[0]['vimeoId'],
		]);
	}

	public static function ajax_vimeo_update() {
		Http::auth();
		$user_id = \get_current_user_id();

		$video_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
		$title = isset($_POST['title']) ? \sanitize_text_field((string) $_POST['title']) : '';
		if ($video_id <= 0 || $title === '') Http::error('Missing fields');
		if (!\user_can($user_id, 'administrator') || !Permissions::can_manage_video($video_id, $user_id)) {
			Http::error('Forbidden', 403);
		}

		global $wpdb;
		$videos = Schema::table('videos');
		$wpdb->update($videos, ['title' => $title, 'updated_at' => \current_time('mysql')], ['id' => $video_id], ['%s','%s'], ['%d']);
		Activity::log('rename_video', 'video', $video_id, ['title' => $title], $user_id);
		Http::success(['videoId' => $video_id]);
	}

	public static function ajax_vimeo_delete() {
		Http::auth();
		$user_id = \get_current_user_id();

		$video_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
		if ($video_id <= 0) Http::error('Missing video_id');
		if (!\user_can($user_id, 'administrator') || !Permissions::can_manage_video($video_id, $user_id)) {
			Http::error('Forbidden', 403);
		}

		global $wpdb;
		$videos = Schema::table('videos');
		$views = Schema::table('video_views');
		// video_views.video_id is polysemous: it holds a videos.id OR a
		// files.id, told apart only by source. Deleting on video_id alone
		// would wipe the watch history of the uploaded file that happens to
		// share this id -- the two tables auto-increment independently, so
		// low ids overlap almost completely.
		$wpdb->delete(
			$views,
			['source' => \Anchor_FM_Media_Progress::SOURCE_VIMEO, 'video_id' => $video_id],
			['%s','%d']
		);
		$wpdb->delete($videos, ['id' => $video_id], ['%d']);
		Activity::log('delete_video', 'video', $video_id, [], $user_id);
		Http::success(['videoId' => $video_id]);
	}

	/**
	 * Resolve and authorize a (source, item) pair for the current user.
	 * Sends a JSON error and exits on anything invalid.
	 */
	private static function require_media_access($source, $item_id, $user_id) {
		if (!\Anchor_FM_Media_Progress::valid_source($source)) Http::error('Bad source');
		if ($item_id <= 0) Http::error('Missing item_id');

		$ok = ($source === \Anchor_FM_Media_Progress::SOURCE_VIMEO)
			? Permissions::can_view_video($item_id, $user_id)
			: Permissions::can_view_file($item_id, $user_id);

		if (!$ok) Http::error('Forbidden', 403);
	}

	private static function handle_progress($source, $item_id) {
		if (!\is_user_logged_in()) Http::error('Unauthorized', 401);
		$user_id = \get_current_user_id();

		self::require_media_access($source, $item_id, $user_id);

		$raw_segments = isset($_POST['segments']) ? (string) \wp_unslash($_POST['segments']) : '';
		$segments = $raw_segments !== '' ? \json_decode($raw_segments, true) : [];
		if (!\is_array($segments)) $segments = [];

		$saved = \Anchor_FM_Media_Progress::record(
			Schema::table('video_views'),
			$source,
			$item_id,
			$user_id,
			$segments,
			isset($_POST['point']) ? (int) $_POST['point'] : 0,
			isset($_POST['duration']) ? (int) $_POST['duration'] : 0,
			!empty($_POST['ended']),
			!empty($_POST['new_session']),
			\current_time('mysql'),
			!empty($_POST['reset'])
		);

		if ($saved === false) Http::error('Could not save progress', 500);

		Http::success(['saved' => true]);
	}

	public static function ajax_media_progress() {
		Http::nonce();
		$source  = isset($_POST['source']) ? \sanitize_key((string) $_POST['source']) : '';
		$item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
		self::handle_progress($source, $item_id);
	}

	/**
	 * Back-compat shim. file-manager.js is cache-busted by filemtime, but a
	 * browser holding the old bundle across a plugin update would otherwise
	 * fail every heartbeat. Remove one release after 2.11.0.
	 */
	public static function ajax_vimeo_progress() {
		Http::nonce();
		$item_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
		self::handle_progress(\Anchor_FM_Media_Progress::SOURCE_VIMEO, $item_id);
	}

	public static function ajax_media_resume() {
		Http::auth();
		$user_id = \get_current_user_id();

		$source  = isset($_POST['source']) ? \sanitize_key((string) $_POST['source']) : '';
		$item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

		self::require_media_access($source, $item_id, $user_id);

		Http::success([
			'resumeSeconds' => \Anchor_FM_Media_Progress::read_resume(
				Schema::table('video_views'),
				$source,
				$item_id,
				$user_id,
				\current_time('timestamp')
			),
		]);
	}

	/**
	 * Zero out playback positions that have aged past the TTL.
	 *
	 * Housekeeping only — the authoritative expiry is the staleness check on
	 * the read path, because WP-Cron fires on page loads and a private portal
	 * can go untouched for weeks.
	 *
	 * Batched so a large table is never held under one long write lock.
	 */
	public static function cron_prune_resume() {
		global $wpdb;
		$views = Schema::table('video_views');
		$ttl   = (int) \Anchor_FM_Watch_Math::RESUME_TTL_DAYS;
		$batch = 5000;

		for ($i = 0; $i < 10; $i++) {
			$affected = $wpdb->query($wpdb->prepare(
				"UPDATE {$views} SET resume_seconds = 0
				 WHERE resume_seconds > 0
				   AND last_viewed_at < DATE_SUB(%s, INTERVAL %d DAY)
				 LIMIT %d",
				\current_time('mysql'), $ttl, $batch
			));
			if ($affected === false || (int) $affected < $batch) break;
		}
	}

	public static function ajax_vimeo_history() {
		Http::auth();
		$user_id = \get_current_user_id();
		if (!\user_can($user_id, 'administrator')) Http::error('Forbidden', 403);

		$source  = isset($_POST['source']) ? \sanitize_key((string) $_POST['source']) : \Anchor_FM_Media_Progress::SOURCE_VIMEO;
		$item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

		// Back-compat with callers still sending video_id.
		if ($item_id <= 0 && isset($_POST['video_id'])) {
			$item_id = (int) $_POST['video_id'];
		}

		if (!\Anchor_FM_Media_Progress::valid_source($source)) Http::error('Bad source');
		if ($item_id <= 0) Http::error('Missing item_id');

		global $wpdb;
		$views = Schema::table('video_views');
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT user_id, furthest_seconds, total_seconds, percent, sessions, last_viewed_at
			 FROM {$views} WHERE source = %s AND video_id = %d ORDER BY last_viewed_at DESC LIMIT 500",
			$source, $item_id
		));

		$out = [];
		foreach ((array) $rows as $r) {
			$u = \get_user_by('id', (int) $r->user_id);
			$out[] = [
				'userId' => (int) $r->user_id,
				'name' => $u ? $u->display_name : ('User #' . (int) $r->user_id),
				'percent' => (int) $r->percent,
				'totalSeconds' => (int) $r->total_seconds,
				'sessions' => (int) $r->sessions,
				'lastViewedAt' => $r->last_viewed_at,
			];
		}
		Http::success(['history' => $out]);
	}
}
