<?php
/**
 * Library copy: duplicates files (bytes and row), links, videos and whole folder trees into another folder.
 *
 * Ported from Anchor Private File Manager 2.17.0 (copy_* and count_folder_tree) with behaviour unchanged. Names are
 * made unique per destination by Anchor_FM_Copy_Namer; a copy keeps no permissions of its own.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Copier {

	public const MAX_NODES = 2000;
	public const MAX_DEPTH = 50;

	/** Display names of direct children of a folder (for collision checks). */
	/** Display names of direct children of a folder (for collision checks). */
	public static function gather_existing_names($folder_id) {
		global $wpdb;
		$folder_id = (int) $folder_id;
		$names = [];
		$folders = Schema::table('folders');
		$files = Schema::table('files');
		$links = Schema::table('links');
		$videos = Schema::table('videos');
		foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT name FROM {$folders} WHERE parent_id = %d", $folder_id)) as $n) { $names[] = $n; }
		foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT original_name FROM {$files} WHERE folder_id = %d", $folder_id)) as $n) { $names[] = $n; }
		foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT title FROM {$links} WHERE folder_id = %d", $folder_id)) as $n) { $names[] = $n; }
		foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT title FROM {$videos} WHERE folder_id = %d", $folder_id)) as $n) { $names[] = $n; }
		return $names;
	}

	/** Copy a link row into a target folder. Returns new link id. */
	public static function copy_link_row($link, $target_folder_id, array &$existing, $force_copy) {
		global $wpdb;
		$title = \Anchor_FM_Copy_Namer::resolve_unique($link->title, $existing, false, $force_copy);
		$now = \current_time('mysql');
		$wpdb->insert(Schema::table('links'), [
			'folder_id'  => (int) $target_folder_id,
			'title'      => $title,
			'url'        => $link->url,
			'created_by' => \get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		], ['%d','%s','%s','%d','%s','%s']);
		$existing[] = $title;
		return (int) $wpdb->insert_id;
	}

	/** Copy a video row (same vimeo_id) into a target folder. Returns new video id. */
	public static function copy_video_row($video, $target_folder_id, array &$existing, $force_copy) {
		global $wpdb;
		$title = \Anchor_FM_Copy_Namer::resolve_unique($video->title, $existing, false, $force_copy);
		$now = \current_time('mysql');
		$wpdb->insert(Schema::table('videos'), [
			'folder_id'     => (int) $target_folder_id,
			'vimeo_id'      => $video->vimeo_id,
			// Carry the privacy hash and thumbnail: a copy without the hash
			// would not play for unlisted videos.
			'vimeo_hash'    => isset($video->vimeo_hash) ? (string) $video->vimeo_hash : '',
			'title'         => $title,
			'thumbnail_url' => isset($video->thumbnail_url) ? (string) $video->thumbnail_url : '',
			'created_by'    => \get_current_user_id(),
			'created_at'    => $now,
			'updated_at'    => $now,
		], ['%d','%s','%s','%s','%s','%d','%s','%s']);
		$existing[] = $title;
		return (int) $wpdb->insert_id;
	}

	/** Copy a \file (disk bytes + DB row) into a target folder. Returns new id or \WP_Error. */
	public static function copy_file_row($file, $target_folder_id, array &$existing, $force_copy) {
		global $wpdb;
		$src_path = Storage::path_for($file);
		if (!\file_exists($src_path) || !\is_readable($src_path)) {
			return new \WP_Error('source_missing', 'Source file is missing on disk');
		}

		$target_dir = Storage::ensure_folder_dir($target_folder_id);
		$stored = Storage::reserve_stored_name($target_dir, $file->stored_name);
		if ($stored === false) {
			return new \WP_Error('copy_failed', 'Could not reserve a file name on disk');
		}
		$dest = \trailingslashit($target_dir) . $stored;
		if (!@\copy($src_path, $dest)) {
			@\unlink($dest);
			return new \WP_Error('copy_failed', 'Could not copy file on disk');
		}

		$original = \Anchor_FM_Copy_Namer::resolve_unique($file->original_name, $existing, true, $force_copy);
		$wpdb->insert(Schema::table('files'), [
			'folder_id'        => (int) $target_folder_id,
			'original_name'    => $original,
			'stored_name'      => $stored,
			'mime_type'        => $file->mime_type,
			'size'             => (int) $file->size,
			'sha1'             => $file->sha1,
			'uploader_user_id' => \get_current_user_id(),
			'created_at'       => \current_time('mysql'),
		], ['%d','%s','%s','%s','%d','%s','%d','%s']);
		if (!$wpdb->insert_id) {
			@\unlink($dest);
			return new \WP_Error('db_insert_failed', 'Could not save the copied file');
		}
		$existing[] = $original;
		return (int) $wpdb->insert_id;
	}

	/** Count nodes (folder+files+links+videos) in a subtree; short-circuits past the cap. */
	public static function count_folder_tree($folder_id, $depth = 0) {
		if ($depth > Copier::MAX_DEPTH) { return PHP_INT_MAX; }
		global $wpdb;
		$folder_id = (int) $folder_id;
		$count = 1; // this folder
		$count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM " . Schema::table('files') . " WHERE folder_id = %d", $folder_id));
		$count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM " . Schema::table('links') . " WHERE folder_id = %d", $folder_id));
		$count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM " . Schema::table('videos') . " WHERE folder_id = %d", $folder_id));
		$children = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . Schema::table('folders') . " WHERE parent_id = %d", $folder_id));
		foreach ((array) $children as $cid) {
			$count += Copier::count_folder_tree((int) $cid, $depth + 1);
			if ($count > Copier::MAX_NODES) { return $count; }
		}
		return $count;
	}

	/** Recursively copy a folder into $target_parent_id. Returns new folder id or \WP_Error. */
	public static function copy_folder_tree($folder, $target_parent_id, array &$existing, $force_copy, $depth = 0) {
		if ($depth > Copier::MAX_DEPTH) { return new \WP_Error('too_deep', 'Folder nesting too deep to copy'); }
		global $wpdb;

		$name = \Anchor_FM_Copy_Namer::resolve_unique($folder->name, $existing, false, $force_copy);
		$now = \current_time('mysql');
		$wpdb->insert(Schema::table('folders'), [
			'parent_id'     => (int) $target_parent_id,
			'name'          => $name,
			'owner_user_id' => 0,
			'is_private'    => 0,
			'created_by'    => \get_current_user_id(),
			'created_at'    => $now,
			'updated_at'    => $now,
		], ['%d','%s','%d','%d','%d','%s','%s']);
		$new_id = (int) $wpdb->insert_id;
		$existing[] = $name;

		// The new folder starts empty, so children never need forcing; track their
		// chosen names so two children with the same name don't collide.
		$child_existing = [];
		$src_id = (int) $folder->id;

		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . Schema::table('folders') . " WHERE parent_id = %d", $src_id)) as $sf) {
			Copier::copy_folder_tree($sf, $new_id, $child_existing, false, $depth + 1);
		}
		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . Schema::table('files') . " WHERE folder_id = %d", $src_id)) as $f) {
			Copier::copy_file_row($f, $new_id, $child_existing, false);
		}
		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . Schema::table('links') . " WHERE folder_id = %d", $src_id)) as $l) {
			Copier::copy_link_row($l, $new_id, $child_existing, false);
		}
		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . Schema::table('videos') . " WHERE folder_id = %d", $src_id)) as $v) {
			Copier::copy_video_row($v, $new_id, $child_existing, false);
		}
		return $new_id;
	}
}
