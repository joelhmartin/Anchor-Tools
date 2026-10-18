<?php
/**
 * Library AJAX: the Users tab (people, passwords, multiple roles, bulk CSV import) and the portal roles it manages.
 *
 * Ported from Anchor Private File Manager 2.17.0; request and response shapes, nonce and permission checks unchanged.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library\Ajax;

use Anchor\Library\Activity;
use Anchor\Library\Http;
use Anchor\Library\Schema;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Users {

	/** AJAX action => handler; Ajax\Registry hooks each as wp_ajax_{action}. */
	public const ACTIONS = [
		'anchor_fm_user_search' => 'ajax_user_search',
		'anchor_fm_bulk_import_users' => 'ajax_bulk_import_users',
		'anchor_fm_users_list' => 'ajax_users_list',
		'anchor_fm_user_create' => 'ajax_user_create',
		'anchor_fm_user_set_role' => 'ajax_user_set_role',
		'anchor_fm_user_set_password' => 'ajax_user_set_password',
		'anchor_fm_user_send_reset' => 'ajax_user_send_reset',
		'anchor_fm_user_delete' => 'ajax_user_delete',
		'anchor_fm_roles_list' => 'ajax_roles_list',
		'anchor_fm_role_create' => 'ajax_role_create',
		'anchor_fm_role_rename' => 'ajax_role_rename',
		'anchor_fm_role_delete' => 'ajax_role_delete',
	];

	public static function ajax_user_search() {
		Http::auth();
		if (!\current_user_can('administrator')) Http::error('Forbidden', 403);

		$term = isset($_POST['term']) ? \sanitize_text_field((string) $_POST['term']) : '';
		$term = \trim($term);
		if ($term === '') Http::success(['users' => []]);

		$users = \get_users([
			'search' => '*' . $term . '*',
			'search_columns' => ['user_login', 'user_email', 'display_name'],
			'number' => 10,
			'fields' => ['ID', 'display_name', 'user_email'],
		]);
		$out = [];
		foreach ((array) $users as $u) {
			$out[] = [
				'id' => (int) $u->ID,
				'displayName' => $u->display_name,
				'email' => $u->user_email,
			];
		}
		Http::success(['users' => $out]);
	}

	public static function ajax_bulk_import_users() {
		Http::auth();
		if (!\current_user_can('administrator')) Http::error('Forbidden', 403);

		// Roles (the same set for the whole batch; administrator not allowed).
		$roles = self::posted_roles();
		$send_email = !empty($_POST['send_email']) && $_POST['send_email'] !== '0';

		// A bad batch default would fail every row the same way; refuse up front.
		$default_password = isset($_POST['default_password']) ? \trim((string) \wp_unslash($_POST['default_password'])) : '';
		if ($default_password !== '') {
			$dv = \Anchor_FM_User_Admin::validate_password($default_password);
			if (!$dv['ok']) Http::error($dv['error']);
		}

		// Uploaded CSV.
		if (empty($_FILES['csv']) || !isset($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
			Http::error('No CSV file was uploaded.');
		}
		if ((int) $_FILES['csv']['size'] > 2 * 1024 * 1024) {
			Http::error('CSV file is too large (max 2 MB).');
		}
		$raw = \file_get_contents($_FILES['csv']['tmp_name']);
		if ($raw === false || \trim($raw) === '') {
			Http::error('The CSV file is empty.');
		}

		$parsed = \Anchor_FM_User_Import::parse($raw);
		$rows = $parsed['rows'];
		if (\count($rows) > \Anchor_FM_User_Import::MAX_ROWS) {
			Http::error(\sprintf('Too many rows (%d). Maximum is %d.', \count($rows), \Anchor_FM_User_Import::MAX_ROWS));
		}

		$created = 0; $skipped = 0; $errors = 0;
		$report = [];
		$seen_emails = [];
		$batch_usernames = [];

		foreach ($rows as $row) {
			$r = \Anchor\Library\Users::create_portal_user($row, $roles, $default_password, $send_email, $batch_usernames, $seen_emails);
			if ($r['status'] === 'created') $created++;
			elseif ($r['status'] === 'skipped') $skipped++;
			else $errors++;
			$report[] = ['line' => (int) $row['line'], 'username' => $r['username'], 'email' => $r['email'], 'status' => $r['status'], 'message' => $r['message']];
		}

		Activity::log('bulk_import', 'user', 0, [
			'created' => $created,
			'skipped' => $skipped,
			'errors'  => $errors,
			'roles'   => $roles,
			'emailed' => $send_email,
			'default_password' => $default_password !== '',
		], \get_current_user_id());

		Http::success([
			'created' => $created,
			'skipped' => $skipped,
			'errors'  => $errors,
			'rows'    => $report,
		]);
	}

	private static function require_admin_ajax() {
		Http::auth();
		if (!\current_user_can('administrator')) Http::error('Forbidden', 403);
	}

	/**
	 * The roles the Users tab posted (roles[], or a single legacy role),
	 * validated against what it may assign; a 400 when none or any is bad.
	 */
	private static function posted_roles() {
		$raw = isset($_POST['roles']) ? (array) $_POST['roles'] : (isset($_POST['role']) ? [$_POST['role']] : []);
		$roles = \Anchor_FM_User_Admin::valid_roles(\array_map(function ($r) { return \sanitize_key((string) $r); }, $raw), \Anchor\Library\Users::assignable_role_keys());
		if (!$roles) Http::error('Please choose at least one valid role.');
		return $roles;
	}

	/** The posted user_id as a \WP_User the current admin may act on, or a 403/404. */
	private static function manageable_target() {
		$id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
		$u = $id > 0 ? \get_user_by('id', $id) : false;
		if (!$u) Http::error('User not found', 404);
		if (!\Anchor\Library\Users::is_manageable($u)) Http::error('This account cannot be changed here.', 403);
		return $u;
	}

	public static function ajax_users_list() {
		self::require_admin_ajax();
		$search = isset($_POST['search']) ? \trim(\sanitize_text_field(\wp_unslash((string) $_POST['search']))) : '';
		$role = isset($_POST['role']) ? \sanitize_key((string) $_POST['role']) : '';
		$page = \max(1, isset($_POST['page']) ? (int) $_POST['page'] : 1);
		$per_page = 25;

		$args = [
			'number' => $per_page,
			'paged' => $page,
			'count_total' => true,
			'orderby' => 'registered',
			'order' => 'DESC',
		];
		if ($search !== '') {
			$args['search'] = '*' . $search . '*';
			$args['search_columns'] = ['user_login', 'user_email', 'display_name'];
		}
		if ($role !== '' && \in_array($role, \Anchor\Library\Users::assignable_role_keys(), true)) {
			$args['role'] = $role;
		}
		// Staff (administrators, editors, authors, shop managers, …) are
		// managed in wp-admin; the portal list is for portal users only.
		$staff = \Anchor\Library\Users::staff_role_keys();
		if ($staff) $args['role__not_in'] = $staff;

		$q = new \WP_User_Query($args);
		$users = (array) $q->get_results();
		$last = \Anchor\Library\Users::last_watched_map(\array_map(function ($u) { return $u->ID; }, $users));
		$total = (int) $q->get_total();

		Http::success([
			'users' => \array_map(function ($u) use ($last) { return \Anchor\Library\Users::shape($u, $last); }, $users),
			'total' => $total,
			'page' => $page,
			'pages' => \max(1, (int) \ceil($total / $per_page)),
		]);
	}

	public static function ajax_user_create() {
		self::require_admin_ajax();
		$roles = self::posted_roles();
		$row = [
			'username' => isset($_POST['username']) ? (string) \wp_unslash($_POST['username']) : '',
			'first_name' => isset($_POST['first_name']) ? (string) \wp_unslash($_POST['first_name']) : '',
			'last_name' => isset($_POST['last_name']) ? (string) \wp_unslash($_POST['last_name']) : '',
			'email' => isset($_POST['email']) ? (string) \wp_unslash($_POST['email']) : '',
			'password' => isset($_POST['password']) ? (string) \wp_unslash($_POST['password']) : '',
		];
		$send_email = !empty($_POST['send_email']) && $_POST['send_email'] !== '0';
		$batch = []; $seen = [];
		$r = \Anchor\Library\Users::create_portal_user($row, $roles, '', $send_email, $batch, $seen);
		if ($r['status'] !== 'created') {
			Http::error($r['message'] ?: 'Could not create the user.');
		}
		Activity::log('user_create', 'user', $r['user_id'], [
			'roles' => $roles, 'emailed' => $send_email, 'explicit_password' => \trim($row['password']) !== '',
		], \get_current_user_id());
		$u = \get_user_by('id', $r['user_id']);
		Http::success(['user' => \Anchor\Library\Users::shape($u, [])]);
	}

	public static function ajax_user_set_role() {
		self::require_admin_ajax();
		$u = self::manageable_target();
		$roles = self::posted_roles();
		$u->set_role($roles[0]);
		\Anchor\Library\Users::add_extra_roles($u, $roles);
		Activity::log('user_set_role', 'user', (int) $u->ID, ['roles' => $roles], \get_current_user_id());
		$fresh = \get_user_by('id', (int) $u->ID);
		Http::success(['user' => \Anchor\Library\Users::shape($fresh, \Anchor\Library\Users::last_watched_map([(int) $u->ID]))]);
	}

	public static function ajax_user_set_password() {
		self::require_admin_ajax();
		$u = self::manageable_target();
		$pw = isset($_POST['password']) ? \trim((string) \wp_unslash($_POST['password'])) : '';
		$pv = \Anchor_FM_User_Admin::validate_password($pw);
		if (!$pv['ok']) Http::error($pv['error']);
		\wp_set_password($pw, (int) $u->ID);
		Activity::log('user_set_password', 'user', (int) $u->ID, [], \get_current_user_id());
		Http::success(['saved' => true]);
	}

	public static function ajax_user_send_reset() {
		self::require_admin_ajax();
		$u = self::manageable_target();
		$sent = \Anchor\Library\Users::send_password_reset_email($u);
		if (\is_wp_error($sent)) Http::error($sent->get_error_message(), 400);
		Activity::log('user_send_reset', 'user', (int) $u->ID, [], \get_current_user_id());
		Http::success(['sent' => true]);
	}

	public static function ajax_user_delete() {
		self::require_admin_ajax();
		$u = self::manageable_target();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$id = (int) $u->ID;
		$email = $u->user_email;
		if (!\wp_delete_user($id, \get_current_user_id())) Http::error('Could not remove the user.', 500);
		Activity::log('user_delete', 'user', $id, ['email' => $email], \get_current_user_id());
		Http::success(['deleted' => true]);
	}

	/** The posted role key, if it is one the portal created; otherwise a 403/404. */
	private static function portal_role_target() {
		$key = isset($_POST['key']) ? \sanitize_key((string) $_POST['key']) : '';
		if ($key === '' || !\get_role($key)) Http::error('Role not found', 404);
		if (!\in_array($key, \Anchor\Library\Users::portal_role_keys(), true)) Http::error('Only roles created here can be changed.', 403);
		return $key;
	}

	public static function ajax_roles_list() {
		self::require_admin_ajax();
		Http::success(\Anchor\Library\Users::roles_payload());
	}

	public static function ajax_role_create() {
		self::require_admin_ajax();
		$name = \Anchor_FM_User_Admin::normalize_role_name(isset($_POST['name']) ? \wp_unslash((string) $_POST['name']) : '');
		$name = \sanitize_text_field($name);
		$key = \Anchor_FM_User_Admin::role_key_from_name($name);
		if ($name === '' || $key === '') Http::error('Enter a role name using letters or numbers.');
		if (\Anchor_FM_User_Admin::is_reserved_role_key($key) || \get_role($key)) {
			Http::error('A role with that name already exists.');
		}
		// Subscriber-level: patients can log in and see the portal, nothing in wp-admin.
		if (!\add_role($key, $name, ['read' => true])) Http::error('Could not create the role.', 500);

		$owned = (array) \get_option(Schema::OPT_PORTAL_ROLES, []);
		$owned[] = $key;
		\update_option(Schema::OPT_PORTAL_ROLES, \array_values(\array_unique(\array_map('strval', $owned))), false);

		Activity::log('role_create', 'role', 0, ['key' => $key, 'name' => $name], \get_current_user_id());
		Http::success(\array_merge(\Anchor\Library\Users::roles_payload(), ['created' => $key]));
	}

	public static function ajax_role_rename() {
		self::require_admin_ajax();
		$key = self::portal_role_target();
		$name = \sanitize_text_field(\Anchor_FM_User_Admin::normalize_role_name(isset($_POST['name']) ? \wp_unslash((string) $_POST['name']) : ''));
		if ($name === '') Http::error('Enter a role name.');

		// WP_Roles has no rename API; the display name lives in the roles option.
		$wp_roles = \wp_roles();
		$wp_roles->roles[$key]['name'] = $name;
		$wp_roles->role_names[$key] = $name;
		if (isset($wp_roles->role_objects[$key])) $wp_roles->role_objects[$key]->name = $name;
		\update_option($wp_roles->role_key, $wp_roles->roles);

		Activity::log('role_rename', 'role', 0, ['key' => $key, 'name' => $name], \get_current_user_id());
		Http::success(\Anchor\Library\Users::roles_payload());
	}

	public static function ajax_role_delete() {
		self::require_admin_ajax();
		$key = self::portal_role_target();
		$check = \Anchor_FM_User_Admin::can_delete_role($key, \Anchor\Library\Users::portal_role_keys(), \Anchor\Library\Users::role_user_count($key));
		if (!$check['ok']) Http::error($check['error']);

		// Drop the role's folder grants and rule conditions so re-creating the
		// same name later doesn't silently bring old access back. Conditions
		// on the role are rewritten as always-false and simplified, so an
		// "all" rule that needed the role is dropped rather than widened.
		\Anchor\Library\Users::strip_role_from_policies($key);
		global $wpdb;
		$wpdb->delete(Schema::table('permissions'), ['subject_type' => 'role', 'subject_key' => $key], ['%s', '%s']);
		\remove_role($key);
		\update_option(Schema::OPT_PORTAL_ROLES, \array_values(\array_diff(\Anchor\Library\Users::portal_role_keys(), [$key])), false);

		Activity::log('role_delete', 'role', 0, ['key' => $key], \get_current_user_id());
		Http::success(\Anchor\Library\Users::roles_payload());
	}
}
