<?php
/**
 * Library permission writes: saving a policy, copying view grants, and the role lists the permission pickers offer.
 *
 * The write half of Anchor Private File Manager 2.17.0's permission code (save_permission_policy,
 * copy_view_permissions, enrich_permission_policy_for_response, get_editable_roles_for_permissions), unchanged in
 * behaviour. Reads live in Permissions; these never run inside a Permissions memo scope.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class PermissionWrites {

	public static function save_policy($entity_type, $entity_id, $capability, $policy, $user_id) {
		global $wpdb;
		$policies = Schema::table('permission_policies');
		$policy = \Anchor_FM_Permission_Policy::normalize($policy);

		if (\Anchor_FM_Permission_Policy::rule_count($policy) <= 0) {
			$wpdb->delete($policies, [
				'entity_type' => $entity_type,
				'entity_id' => $entity_id,
				'capability' => $capability,
			], ['%s','%d','%s']);
			return $policy;
		}

		$now = \current_time('mysql');
		$wpdb->replace($policies, [
			'entity_type' => $entity_type,
			'entity_id' => $entity_id,
			'capability' => $capability,
			'policy' => \wp_json_encode($policy),
			'created_by' => $user_id,
			'created_at' => $now,
			'updated_by' => $user_id,
			'updated_at' => $now,
		], ['%s','%d','%s','%s','%d','%s','%d','%s']);

		return $policy;
	}

	public static function enrich_policy(array $policy) {
		foreach ($policy['rules'] as $rule_index => $rule) {
			foreach ($rule['conditions'] as $condition_index => $condition) {
				if (isset($condition['type']) && $condition['type'] === 'user') {
					$uid = isset($condition['userId']) ? (int) $condition['userId'] : 0;
					$u = $uid > 0 ? \get_user_by('id', $uid) : null;
					$policy['rules'][$rule_index]['conditions'][$condition_index]['name'] = $u ? $u->display_name : (string) $uid;
				}
			}
		}
		return $policy;
	}

	public static function copy_view($from_type, $from_id, $to_type, $to_id, $overwrite = false) {
		global $wpdb;
		$perms = Schema::table('permissions');
		$policies = Schema::table('permission_policies');

		if (!$overwrite && Permissions::entity_has_view_permissions($to_type, $to_id)) {
			return;
		}

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT subject_type, subject_key FROM {$perms} WHERE entity_type = %s AND entity_id = %d AND capability = 'view'",
			$from_type,
			$from_id
		));

		$wpdb->delete($perms, [
			'entity_type' => $to_type,
			'entity_id' => $to_id,
			'capability' => 'view',
		], ['%s','%d','%s']);
		$wpdb->delete($policies, [
			'entity_type' => $to_type,
			'entity_id' => $to_id,
			'capability' => 'view',
		], ['%s','%d','%s']);

		$now = \current_time('mysql');
		foreach ((array) $rows as $r) {
			$wpdb->insert($perms, [
				'entity_type' => $to_type,
				'entity_id' => $to_id,
				'subject_type' => $r->subject_type,
				'subject_key' => $r->subject_key,
				'capability' => 'view',
				'created_by' => \get_current_user_id(),
				'created_at' => $now,
			], ['%s','%d','%s','%s','%s','%d','%s']);
		}

		$policy = $wpdb->get_var($wpdb->prepare(
			"SELECT policy FROM {$policies} WHERE entity_type = %s AND entity_id = %d AND capability = 'view'",
			$from_type,
			$from_id
		));
		$policy = \Anchor_FM_Permission_Policy::normalize($policy ?: []);
		if (\Anchor_FM_Permission_Policy::rule_count($policy) > 0) {
			$wpdb->insert($policies, [
				'entity_type' => $to_type,
				'entity_id' => $to_id,
				'capability' => 'view',
				'policy' => \wp_json_encode($policy),
				'created_by' => \get_current_user_id(),
				'created_at' => $now,
				'updated_by' => \get_current_user_id(),
				'updated_at' => $now,
			], ['%s','%d','%s','%s','%d','%s','%d','%s']);
		}
	}

	/** The view grants an entity carries directly: sorted "type:key" subjects and its normalized policy JSON. */
	private static function direct_view_grants( string $type, int $id ): array {
		global $wpdb;
		$subjects = [];
		foreach ( (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT subject_type, subject_key FROM ' . Schema::table( 'permissions' ) . " WHERE entity_type = %s AND entity_id = %d AND capability = 'view'",
			$type, $id
		) ) as $r ) {
			$subjects[] = $r->subject_type . ':' . $r->subject_key;
		}
		\sort( $subjects );
		$raw = $wpdb->get_var( $wpdb->prepare(
			'SELECT policy FROM ' . Schema::table( 'permission_policies' ) . " WHERE entity_type = %s AND entity_id = %d AND capability = 'view'",
			$type, $id
		) );
		$policy = \Anchor_FM_Permission_Policy::normalize( $raw ?: [] );
		return [ $subjects, \Anchor_FM_Permission_Policy::rule_count( $policy ) > 0 ? \wp_json_encode( $policy ) : '' ];
	}

	/**
	 * A file whose view grants (role rows, user rows and policy) exactly equal its source folder's direct grants is a
	 * legacy snapshot taken by an older move. Delete them so it inherits its new folder.
	 *
	 * @return bool True when the file has its own, different grants, which stay.
	 */
	public static function release_snapshot( int $file_id, int $source_folder_id ): bool {
		$own = self::direct_view_grants( 'file', $file_id );
		if ( ! $own[0] && '' === $own[1] ) {
			return false;
		}
		if ( $own !== self::direct_view_grants( 'folder', $source_folder_id ) ) {
			return true;
		}
		global $wpdb;
		$wpdb->delete( Schema::table( 'permissions' ), [ 'entity_type' => 'file', 'entity_id' => $file_id, 'capability' => 'view' ], [ '%s', '%d', '%s' ] );
		$wpdb->delete( Schema::table( 'permission_policies' ), [ 'entity_type' => 'file', 'entity_id' => $file_id, 'capability' => 'view' ], [ '%s', '%d', '%s' ] );
		return false;
	}

	/** Role keys a folder or file may be shared with: every registered role except administrator. */
	public static function valid_role_keys() {
		$roles = \array_keys((array) \wp_roles()->roles);
		$roles = \array_map('sanitize_key', $roles);
		return \array_values(\array_filter(\array_unique($roles), function ($role) {
			return $role !== '' && $role !== 'administrator';
		}));
	}

	/** The role picker's list ({key, label}), administrator excluded. */
	public static function editable_roles() {
		$roles = (array) \wp_roles()->roles;
		$out = [];
		foreach ($roles as $key => $meta) {
			$key = \sanitize_key((string) $key);
			if ($key === 'administrator') continue;
			$out[] = [
				'key' => $key,
				'label' => isset($meta['name']) ? $meta['name'] : $key,
			];
		}
		return $out;
	}
}
