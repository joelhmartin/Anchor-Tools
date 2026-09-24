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

	/**
	 * The full `register_post_type()` `capabilities` map for a CPT that maps
	 * every primitive `map_meta_cap` can derive to a single capability.
	 *
	 * `capability_type` being a custom (non-"post") array means WordPress
	 * derives capabilities it isn't given explicitly (e.g.
	 * `delete_published_anchor_courses`) from that custom base rather than
	 * falling back to a real, granted capability - and `Capabilities::sync()`
	 * never grants anything with that derived name. Naming all eleven keys
	 * here, all pointing at the same $cap, is what makes edit/delete of a
	 * published OR private post resolve to a capability someone actually
	 * holds. Used by CoursePostType, LessonPostType and QuizPostType so the
	 * map can't drift out of sync between the three.
	 *
	 * @return array<string,string>
	 */
	public static function post_type_capabilities( string $cap ): array {
		return [
			'edit_posts'             => $cap,
			'edit_others_posts'      => $cap,
			'edit_private_posts'     => $cap,
			'edit_published_posts'   => $cap,
			'publish_posts'          => $cap,
			'read_private_posts'     => $cap,
			'create_posts'           => $cap,
			'delete_posts'           => $cap,
			'delete_others_posts'    => $cap,
			'delete_published_posts' => $cap,
			'delete_private_posts'   => $cap,
		];
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

	/**
	 * Strip every capability from every role. Uninstall only.
	 *
	 * Deliberately purges ALL roles, not just the ones sync()'s
	 * `anchor_courses_capability_roles` filter names — a role could have been
	 * granted a cap by a filter value that changed or was removed since, and
	 * uninstall must not leave any of the eight caps stranded on any role.
	 */
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
