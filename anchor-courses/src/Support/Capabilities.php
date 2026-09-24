<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The eight courses capabilities (brief section 24), mapped to administrator by
 * migration 1.0.0.
 *
 * Unlike \Anchor\Events\Roster::cap(), which returns an EXISTING WordPress
 * capability, these are minted. They live in the wp_user_roles option, so they
 * survive disabling the module; only the guarded uninstall branch removes them.
 */
final class Capabilities {

	public const CAPS = [
		'manage'       => 'manage_anchor_courses',
		'edit_courses' => 'edit_anchor_courses',
		'edit_lessons' => 'edit_anchor_lessons',
		'edit_quizzes' => 'edit_anchor_quizzes',
		'reports'      => 'view_anchor_course_reports',
		'enrollments'  => 'manage_anchor_enrollments',
		'credits'      => 'manage_anchor_credits',
		'certificates' => 'manage_anchor_certificates',
	];

	/** @return string[] */
	public static function all(): array {
		return \array_values( self::CAPS );
	}

	public static function cap( string $key ): string {
		return self::CAPS[ $key ] ?? 'do_not_allow';
	}

	public static function current_user_can( string $key ): bool {
		return \current_user_can( self::cap( $key ) );
	}

	/** Grant every capability to administrator. Idempotent. */
	public static function sync(): void {
		$roles = \apply_filters( 'anchor_courses_capability_roles', [ 'administrator' ] );
		foreach ( (array) $roles as $role_slug ) {
			$role = \get_role( (string) $role_slug );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/** Strip every capability from every role. Uninstall only. */
	public static function remove(): void {
		$wp_roles = \wp_roles();
		foreach ( \array_keys( $wp_roles->roles ) as $role_slug ) {
			$role = \get_role( (string) $role_slug );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
