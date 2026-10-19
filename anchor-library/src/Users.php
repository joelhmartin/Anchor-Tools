<?php
/**
 * Library users: the roles and people the portal's Users tab manages, shared by its AJAX handlers.
 *
 * Ported from Anchor Private File Manager 2.17.0 (incl. 2.17.0 multi-role assignment). The pure rules (who counts
 * as staff, which roles are assignable, password and import validation) live in the Anchor_FM_User_Admin and
 * Anchor_FM_User_Import helpers; this class adds the WordPress side.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Users {

	public static function register(): void {
		\add_action( 'deleted_user', [ self::class, 'on_deleted_user' ] );
	}

	/** Role keys whose capabilities make their holders staff (see \Anchor_FM_User_Admin::STAFF_CAPABILITIES). */
	public static function staff_role_keys() {
		$keys = [];
		foreach ((array) \wp_roles()->roles as $key => $meta) {
			$caps = isset($meta['capabilities']) ? (array) $meta['capabilities'] : [];
			if (\Anchor_FM_User_Admin::is_staff_capabilities($caps)) $keys[] = (string) $key;
		}
		return $keys;
	}

	/**
	 * Roles the Users tab may assign: every non-administrator role minus
	 * staff roles. Folder Permissions still offer all roles; only user
	 * management is limited, so the portal can never make someone an editor.
	 */
	public static function role_options() {
		$staff = Users::staff_role_keys();
		return \array_values(\array_filter(PermissionWrites::editable_roles(), function ($r) use ($staff) {
			return !\in_array($r['key'], $staff, true);
		}));
	}

	public static function assignable_role_keys() {
		return \array_column(Users::role_options(), 'key');
	}

	/** Give a user every role after the first (the first is set by insert/set_role). */
	public static function add_extra_roles(\WP_User $u, array $roles) {
		foreach (\array_slice($roles, 1) as $role) $u->add_role($role);
	}

	public static function is_manageable(\WP_User $u) {
		return \Anchor_FM_User_Admin::is_manageable((array) $u->roles, (int) $u->ID, \get_current_user_id())
			&& !\user_can($u, 'administrator')
			&& !\Anchor_FM_User_Admin::is_staff_capabilities((array) $u->allcaps);
	}

	/** user_id => latest last_viewed_at, in one query for a page of users. */
	public static function last_watched_map(array $user_ids) {
		$user_ids = \array_values(\array_filter(\array_map('intval', $user_ids)));
		if (!$user_ids) return [];
		global $wpdb;
		$views = Schema::table('video_views');
		$in = \implode(',', $user_ids); // ints only, cast above
		$rows = $wpdb->get_results("SELECT user_id, MAX(last_viewed_at) AS last FROM {$views} WHERE user_id IN ({$in}) GROUP BY user_id");
		$out = [];
		foreach ((array) $rows as $r) $out[(int) $r->user_id] = $r->last;
		return $out;
	}

	public static function shape(\WP_User $u, array $last_watched) {
		return [
			'id' => (int) $u->ID,
			'displayName' => $u->display_name,
			'email' => $u->user_email,
			'username' => $u->user_login,
			'roles' => \array_values((array) $u->roles),
			'registered' => $u->user_registered,
			'lastWatched' => isset($last_watched[(int) $u->ID]) ? $last_watched[(int) $u->ID] : null,
			'manageable' => Users::is_manageable($u),
		];
	}

	/**
	 * One user through the same pipeline whether they came from a CSV row or
	 * the "Add person" form: sanitize, validate, de-duplicate, derive a unique
	 * username, resolve the password, insert, optionally notify.
	 */
	public static function create_portal_user(array $row, array $roles, $default_password, $send_email, array &$batch_usernames, array &$seen_emails) {
		$row = \array_merge(['username' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'password' => ''], $row);
		$row['first_name'] = \sanitize_text_field($row['first_name']);
		$row['last_name']  = \sanitize_text_field($row['last_name']);
		$row['email']      = \Anchor_FM_User_Import::normalize_email($row['email']);
		$row['username']   = \Anchor_FM_User_Import::sanitize_username($row['username']);
		$out = ['status' => 'error', 'message' => '', 'username' => $row['username'], 'email' => $row['email'], 'user_id' => 0];

		$v = \Anchor_FM_User_Import::validate($row);
		if (!$v['ok']) { $out['message'] = $v['error']; return $out; }

		$pw = \Anchor_FM_User_Admin::resolve_password($row['password'], $default_password);
		if ($pw['source'] === 'row') {
			$pv = \Anchor_FM_User_Admin::validate_password($pw['password']);
			if (!$pv['ok']) { $out['message'] = $pv['error']; return $out; }
		}
		$password = $pw['source'] === 'generate' ? \wp_generate_password(16, true, false) : $pw['password'];

		if (isset($seen_emails[$row['email']]) || \email_exists($row['email'])) {
			$out['status'] = 'skipped';
			$out['message'] = 'Email already exists';
			return $out;
		}

		$base = $row['username'] !== '' ? $row['username'] : \Anchor_FM_User_Import::derive_username($row['first_name'], $row['last_name']);
		$username = \Anchor_FM_User_Import::make_unique($base, function ($name) use ($batch_usernames) {
			return isset($batch_usernames[$name]) || \username_exists($name);
		});
		$out['username'] = $username;

		$user_id = \wp_insert_user([
			'user_login'   => $username,
			'user_email'   => $row['email'],
			'user_pass'    => $password,
			'first_name'   => $row['first_name'],
			'last_name'    => $row['last_name'],
			'display_name' => \trim($row['first_name'] . ' ' . $row['last_name']),
			'role'         => $roles[0],
		]);
		if (\is_wp_error($user_id)) { $out['message'] = $user_id->get_error_message(); return $out; }
		Users::add_extra_roles(new \WP_User($user_id), $roles);

		$batch_usernames[$username] = true;
		$seen_emails[$row['email']] = true;
		if ($send_email) {
			\wp_new_user_notification($user_id, null, 'user');
		}
		$out['status'] = 'created';
		$out['user_id'] = (int) $user_id;
		return $out;
	}

	/** The password-reset email the Security tab and the Users manager both send. */
	public static function send_password_reset_email(\WP_User $user) {
		if (empty($user->user_email)) return new \WP_Error('no_email', 'No email on account');
		$key = \get_password_reset_key($user);
		if (\is_wp_error($key)) return $key;

		$reset_url = \network_site_url('wp-login.php?action=rp&key=' . \rawurlencode($key) . '&login=' . \rawurlencode($user->user_login), 'login');
		$subject = \sprintf('[%s] Password reset', \wp_specialchars_decode(\get_bloginfo('name'), ENT_QUOTES));
		$message = "A password reset was requested for your account.\n\n";
		$message .= "Reset your password:\n{$reset_url}\n\n";
		$message .= "If you didn’t request this, you can ignore this email.\n";

		return \wp_mail($user->user_email, $subject, $message) ? true : new \WP_Error('mail_failed', 'Could not send the email right now.');
	}

	/**
	 * Fires for deletions from here and from wp-admin. User conditions inside
	 * permission_policies JSON are left alone: they match nobody once the user
	 * is gone, and MySQL does not reuse user IDs.
	 */
	public static function on_deleted_user($user_id) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ($user_id <= 0) return;
		$wpdb->delete(Schema::table('permissions'), ['subject_type' => 'user', 'subject_key' => (string) $user_id], ['%s', '%s']);
		$wpdb->delete(Schema::table('video_views'), ['user_id' => $user_id], ['%d']);
	}

	/** Portal-created role keys that still exist as WordPress roles. */
	public static function portal_role_keys() {
		$keys = \array_map('strval', (array) \get_option(Schema::OPT_PORTAL_ROLES, []));
		return \array_values(\array_filter($keys, function ($k) { return (bool) \get_role($k); }));
	}

	public static function role_user_count($key) {
		$q = new \WP_User_Query(['role' => $key, 'fields' => 'ID', 'number' => 1, 'count_total' => true]);
		return (int) $q->get_total();
	}

	/**
	 * What every role-changing response returns, so the page can swap its one
	 * shared role list in place: every dropdown and permission picker then
	 * offers the change immediately, with no reload.
	 */
	public static function roles_payload() {
		$portal = [];
		$names = \wp_roles()->get_names();
		foreach (Users::portal_role_keys() as $key) {
			$portal[] = [
				'key' => $key,
				'label' => isset($names[$key]) ? $names[$key] : $key,
				'users' => Users::role_user_count($key),
			];
		}
		return [
			'roles' => PermissionWrites::editable_roles(),
			'userRoles' => Users::role_options(),
			'portalRoles' => $portal,
		];
	}

	public static function strip_role_from_policies($key) {
		global $wpdb;
		$policies = Schema::table('permission_policies');
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, policy FROM {$policies} WHERE policy LIKE %s",
			'%' . $wpdb->esc_like('"' . $key . '"') . '%'
		));
		foreach ((array) $rows as $row) {
			$before = \Anchor_FM_Permission_Policy::normalize($row->policy);
			$after = \Anchor_FM_Permission_Policy::without_role($before, $key);
			if ($after === $before) continue;
			if (\Anchor_FM_Permission_Policy::rule_count($after) <= 0) {
				$wpdb->delete($policies, ['id' => (int) $row->id], ['%d']);
			} else {
				$wpdb->update($policies, [
					'policy' => \wp_json_encode($after),
					'updated_by' => \get_current_user_id(),
					'updated_at' => \current_time('mysql'),
				], ['id' => (int) $row->id], ['%s', '%d', '%s'], ['%d']);
			}
		}
	}
}
