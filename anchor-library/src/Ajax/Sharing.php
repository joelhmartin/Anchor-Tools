<?php
/**
 * Library AJAX: who may see a folder or file (roles, users, rules) and the "request access" email.
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
use Anchor\Library\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Sharing {

	/** AJAX action => handler; Ajax\Registry hooks each as wp_ajax_{action}. */
	public const ACTIONS = [
		'anchor_fm_get_permissions' => 'ajax_get_permissions',
		'anchor_fm_set_permissions' => 'ajax_set_permissions',
		'anchor_fm_request_access' => 'ajax_request_access',
	];

	public static function ajax_get_permissions() {
		Http::auth();

		$user_id = \get_current_user_id();
		$entity_type = isset($_POST['entity_type']) ? \sanitize_key((string) $_POST['entity_type']) : '';
		$entity_id = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
		if (!\in_array($entity_type, ['file', 'folder'], true) || $entity_id <= 0) Http::error('Missing fields');

		$allowed = $entity_type === 'file'
			? Permissions::can_manage_file($entity_id, $user_id)
			: Permissions::can_manage_folder($entity_id, $user_id);
		if (!$allowed) Http::error('Forbidden', 403);

		global $wpdb;
		$perms = Schema::table('permissions');
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT subject_type, subject_key FROM {$perms} WHERE entity_type = %s AND entity_id = %d AND capability = 'view' ORDER BY subject_type, subject_key",
			$entity_type,
			$entity_id
		));

		$roles = [];
		$users = [];
		foreach ((array) $rows as $r) {
			if ($r->subject_type === 'role') {
				$roles[] = \sanitize_key((string) $r->subject_key);
			} elseif ($r->subject_type === 'user') {
				$uid = (int) $r->subject_key;
				$u = \get_user_by('id', $uid);
				$users[] = [
					'id' => (string) $uid,
					'name' => $u ? $u->display_name : (string) $uid,
				];
			}
		}

		Http::success([
			'roles' => \array_values(\array_unique($roles)),
			'users' => $users,
			'policy' => PermissionWrites::enrich_policy(Permissions::policy($entity_type, $entity_id, 'view')),
		]);
	}

	public static function ajax_set_permissions() {
		Http::auth();

		$user_id = \get_current_user_id();
		$entity_type = isset($_POST['entity_type']) ? \sanitize_key((string) $_POST['entity_type']) : '';
		$entity_id = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
		$roles = isset($_POST['roles']) ? (array) $_POST['roles'] : [];
		$users = isset($_POST['users']) ? (array) $_POST['users'] : [];
		$policy_input = [];
		if (isset($_POST['policy'])) {
			$policy_input = \wp_unslash($_POST['policy']);
			if (\is_string($policy_input)) {
				$decoded = \json_decode($policy_input, true);
				$policy_input = \is_array($decoded) ? $decoded : [];
			}
		}
		if (!\in_array($entity_type, ['file', 'folder'], true) || $entity_id <= 0) Http::error('Missing fields');

		$allowed = $entity_type === 'file'
			? Permissions::can_manage_file($entity_id, $user_id)
			: Permissions::can_manage_folder($entity_id, $user_id);
		if (!$allowed) Http::error('Forbidden', 403);

		$valid_roles = PermissionWrites::valid_role_keys();

		// Reject a bad rule before touching any stored permission, so a
		// refused save leaves the previous grants exactly as they were.
		$policy_check = \Anchor_FM_Permission_Policy::validate_for_write($policy_input, $valid_roles);
		if (!$policy_check['ok']) Http::error($policy_check['error']);
		$policy_input = $policy_check['policy'];

		$normalized = [];
		foreach ($roles as $role) {
			$role = \sanitize_key((string) $role);
			if (!$role) continue;
			if (!\in_array($role, $valid_roles, true)) continue;
			$normalized[] = $role;
		}
		$normalized = \array_values(\array_unique($normalized));

		$normalized_users = [];
		foreach ($users as $u) {
			$uid = \is_array($u) && isset($u['id']) ? (int) $u['id'] : (int) $u;
			if ($uid <= 0) continue;
			$wp_user = \get_user_by('id', $uid);
			$normalized_users[$uid] = [
				'id' => (string) $uid,
				'name' => $wp_user ? $wp_user->display_name : (\is_array($u) && isset($u['name']) ? \sanitize_text_field((string) $u['name']) : (string) $uid),
			];
		}
		$users = \array_values($normalized_users);

		global $wpdb;
		$perms = Schema::table('permissions');
		$wpdb->delete($perms, [
			'entity_type' => $entity_type,
			'entity_id' => $entity_id,
			'subject_type' => 'role',
			'capability' => 'view',
		], ['%s','%d','%s','%s']);
		$wpdb->delete($perms, [
			'entity_type' => $entity_type,
			'entity_id' => $entity_id,
			'subject_type' => 'user',
			'capability' => 'view',
		], ['%s','%d','%s','%s']);

		$now = \current_time('mysql');
		foreach ($normalized as $role) {
			$wpdb->insert($perms, [
				'entity_type' => $entity_type,
				'entity_id' => $entity_id,
				'subject_type' => 'role',
				'subject_key' => $role,
				'capability' => 'view',
				'created_by' => $user_id,
				'created_at' => $now,
			], ['%s','%d','%s','%s','%s','%d','%s']);
		}

		foreach ($users as $u) {
			$uid = (int) $u['id'];
			if ($uid <= 0) continue;
			$wpdb->insert($perms, [
				'entity_type' => $entity_type,
				'entity_id' => $entity_id,
				'subject_type' => 'user',
				'subject_key' => (string) $uid,
				'capability' => 'view',
				'created_by' => $user_id,
				'created_at' => $now,
			], ['%s','%d','%s','%s','%s','%d','%s']);
		}

		$policy = PermissionWrites::save_policy($entity_type, $entity_id, 'view', $policy_input, $user_id);

		Activity::log('set_permissions', $entity_type, $entity_id, ['roles' => $normalized, 'users' => $users, 'policy' => $policy], $user_id);
		Http::success([
			'saved' => true,
			'roles' => $normalized,
			'users' => $users,
			'policy' => PermissionWrites::enrich_policy($policy),
		]);
	}

	public static function ajax_request_access() {
		Http::auth();
		$user_id = \get_current_user_id();

		$entity_type = isset($_POST['entity_type']) ? \sanitize_key((string) $_POST['entity_type']) : '';
		$entity_id = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
		$label = isset($_POST['label']) ? \sanitize_text_field((string) $_POST['label']) : '';
		if (!\in_array($entity_type, ['file','folder','video','link'], true) || $entity_id <= 0) {
			Http::error('Invalid request');
		}

		// Resolve the entity and confirm it exists, then confirm the requester
		// genuinely lacks view access. This is the "access denied for a real
		// item" flow — refusing arbitrary ids prevents using it for mail spam.
		$exists = false;
		$can_view = false;
		switch ($entity_type) {
			case 'file':
				$exists = (bool) Repository::file_row($entity_id);
				$can_view = $exists && Permissions::can_view_file($entity_id, $user_id);
				break;
			case 'folder':
				$exists = (bool) Repository::folder_row($entity_id);
				$can_view = $exists && Permissions::can_view_folder($entity_id, $user_id);
				break;
			case 'video':
				$exists = (bool) Repository::video_row($entity_id);
				$can_view = $exists && Permissions::can_view_video($entity_id, $user_id);
				break;
			case 'link':
				$exists = (bool) Repository::link_row($entity_id);
				$can_view = $exists && Permissions::can_view_link($entity_id, $user_id);
				break;
		}
		if (!$exists) Http::error('Not found', 404);
		if ($can_view) {
			// Already has access — nothing to request, nothing to email.
			Http::success(['sent' => false, 'alreadyHasAccess' => true]);
		}

		$rate_key = 'afm_reqacc_' . $user_id . '_' . $entity_type . '_' . $entity_id;
		if (\get_transient($rate_key)) {
			Http::success(['sent' => true, 'throttled' => true]);
		}

		$user = \wp_get_current_user();
		$to = Settings::request_access_email();
		$site = \wp_specialchars_decode(\get_bloginfo('name'), ENT_QUOTES);
		$subject = \sprintf('[%s] Access request from %s', $site, $user->display_name);
		$body  = "A user has requested access to a document.\n\n";
		$body .= "User: {$user->display_name} ({$user->user_email})\n";
		$body .= "Item: {$label} ({$entity_type} #{$entity_id})\n";
		$body .= "Time: " . \current_time('mysql') . "\n";

		$sent = \wp_mail($to, $subject, $body);
		if (!$sent) {
			// Don't throttle or report success on delivery failure, so the user can retry.
			Http::error('Could not send the request right now. Please try again later.', 500);
		}
		\set_transient($rate_key, 1, HOUR_IN_SECONDS);
		Activity::log('request_access', $entity_type, $entity_id, ['to' => $to], $user_id);

		Http::success(['sent' => true, 'throttled' => false]);
	}
}
