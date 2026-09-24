<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

use Anchor\Courses\Content\CoursePostType;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The two roles every course owns (design spec 3.1).
 *
 *   anchor_course_{id}            "Course: {title}"     ACCESS - holding it is enrolment.
 *   anchor_course_{id}_completed  "Completed: {title}"  COMPLETION - what others name as a prerequisite.
 *
 * Neither carries a capability. They are membership tags, exactly like the
 * events module's `anchor_event_{id}` (anchor-events-manager/class-entitlements.php),
 * so adding one can never widen what a user may do - and so any plugin, any
 * admin and WP-CLI can all grant access without knowing this module exists.
 *
 * The access role is minted EAGERLY, on publish, because a plain
 * `add_user_role()` from wp-admin is a supported way in and that call cannot
 * mint anything - the role has to be waiting. The completion role is minted
 * LAZILY, on the first completion, because nothing can hold it before then.
 *
 * Neither is ever deleted automatically. A trashed or deleted course keeps both
 * so the owner can go on granting after the fact; the course editor's Course
 * Role panel is the only thing that removes one.
 *
 * This class does NOT grant or revoke the access role, and does not listen for
 * it being granted - that is Task 20 (`EnrollmentService::enroll( bypass_checks )`
 * on a gain, a role-loss policy on a loss). What it DOES guarantee is that
 * once granted, a course role survives everything except that one delete
 * action - including an admin changing a learner's PRIMARY role, which core
 * `WP_User::set_role()` would otherwise silently strip (see
 * reapply_after_set_role()).
 */
final class Roles {

	public const ACCESS_PREFIX    = 'anchor_course_';
	public const COMPLETED_SUFFIX = '_completed';

	/* ---------------------------------------------------------------------
	 * Slugs and names
	 * ------------------------------------------------------------------- */

	public static function access_slug( int $course_id ): string {
		return self::ACCESS_PREFIX . $course_id;
	}

	public static function completion_slug( int $course_id ): string {
		return self::ACCESS_PREFIX . $course_id . self::COMPLETED_SUFFIX;
	}

	public static function access_name( int $course_id ): string {
		return \sprintf(
			/* translators: %s: course title. */
			\__( 'Course: %s', 'anchor-schema' ),
			(string) \get_the_title( $course_id )
		);
	}

	public static function completion_name( int $course_id ): string {
		return \sprintf(
			/* translators: %s: course title. */
			\__( 'Completed: %s', 'anchor-schema' ),
			(string) \get_the_title( $course_id )
		);
	}

	/**
	 * Is this slug a course's ACCESS role, and if so whose?
	 *
	 * The listener's whole safety net, so it is deliberately strict:
	 *
	 *   - anchored at both ends, so `xanchor_course_12` and `anchor_course_12x`
	 *     are not access slugs;
	 *   - digits only, so the `_completed` variant does not match - which is
	 *     what stops completing a course from re-enrolling the learner;
	 *   - the id must really be a course, so a role somebody hand-made called
	 *     `anchor_course_999999` enrols nobody into nothing.
	 *
	 * @return int|null The course id, or null.
	 */
	public static function is_access_slug( string $slug ): ?int {
		if ( 1 !== \preg_match( '/^' . self::ACCESS_PREFIX . '(\d+)$/', $slug, $m ) ) {
			return null;
		}

		$course_id = (int) $m[1];

		return CoursePostType::CPT === \get_post_type( $course_id ) ? $course_id : null;
	}

	/**
	 * Is this slug EITHER of a course's own roles (access or completion)?
	 *
	 * Broader than is_access_slug() on purpose: reapply_after_set_role() must
	 * restore a completion role too, and unlike is_access_slug() it does not
	 * need to rule the completion variant out - just recognise "this is a
	 * course role, of some kind" so it never touches an unrelated role like
	 * `anchor_event_12` or `editor`.
	 */
	private static function is_course_role_slug( string $slug ): bool {
		return 1 === \preg_match( '/^' . self::ACCESS_PREFIX . '\d+(?:' . self::COMPLETED_SUFFIX . ')?$/', $slug );
	}

	/* ---------------------------------------------------------------------
	 * Minting
	 * ------------------------------------------------------------------- */

	public static function exists( string $slug ): bool {
		return '' !== $slug && null !== \get_role( $slug );
	}

	/**
	 * Mint the access role. Idempotent; returns the slug either way.
	 *
	 * Hooked to save_post_anchor_course. Only a published course gets one: a
	 * draft has nobody to admit yet, and minting on every autosave would litter
	 * the roles option with roles for posts that never ship.
	 */
	public static function ensure_access_role( int $course_id ): string {
		$slug = self::access_slug( $course_id );

		if ( self::exists( $slug ) ) {
			return $slug;
		}
		if ( 'publish' !== \get_post_status( $course_id ) ) {
			return '';
		}

		\add_role( $slug, self::access_name( $course_id ), [] );

		Log::write( 'access_role_minted', [ 'course' => $course_id ] );

		return self::exists( $slug ) ? $slug : '';
	}

	/** Mint the completion role. Idempotent; returns the slug. */
	public static function ensure_completion_role( int $course_id ): string {
		$slug = self::completion_slug( $course_id );

		if ( ! self::exists( $slug ) ) {
			\add_role( $slug, self::completion_name( $course_id ), [] );
		}

		return self::exists( $slug ) ? $slug : '';
	}

	/**
	 * `save_post_anchor_course`: mint the access role, and keep both display
	 * names in step with the title.
	 */
	public static function rename_on_title_change( int $post_id, \WP_Post $post ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( \wp_is_post_revision( $post_id ) ) {
			return;
		}

		self::ensure_access_role( $post_id );

		self::rename( self::access_slug( $post_id ), self::access_name( $post_id ) );
		self::rename( self::completion_slug( $post_id ), self::completion_name( $post_id ) );
	}

	/** Rename an existing role. Never mints one. */
	private static function rename( string $slug, string $name ): void {
		if ( ! self::exists( $slug ) ) {
			return;
		}

		$roles = \wp_roles();
		if ( ( $roles->roles[ $slug ]['name'] ?? '' ) === $name ) {
			return;
		}

		$roles->roles[ $slug ]['name'] = $name;
		$roles->role_names[ $slug ]    = $name;
		\update_option( $roles->role_key, $roles->roles, false );
	}

	/**
	 * Operator-only: strip a role from every holder and delete it.
	 *
	 * The ONE path that deletes a role. Nothing automatic calls it.
	 *
	 * @return int Holders it was stripped from.
	 */
	public static function delete_role( string $slug ): int {
		if ( ! self::exists( $slug ) ) {
			return 0;
		}

		$holders = \get_users( [ 'role' => $slug, 'fields' => 'ID', 'number' => -1 ] );

		foreach ( $holders as $user_id ) {
			$user = \get_userdata( (int) $user_id );
			if ( $user instanceof \WP_User ) {
				$user->remove_role( $slug );
			}
		}

		\remove_role( $slug );

		Log::write( 'role_deleted', [ 'role' => $slug, 'holders' => \count( $holders ) ] );

		return \count( $holders );
	}

	public static function holders( string $slug ): int {
		if ( ! self::exists( $slug ) ) {
			return 0;
		}
		return \count( \get_users( [ 'role' => $slug, 'fields' => 'ID', 'number' => -1 ] ) );
	}

	/* ---------------------------------------------------------------------
	 * Completion grants
	 * ------------------------------------------------------------------- */

	/**
	 * Give a user the COMPLETION role.
	 *
	 * Additive, idempotent, and invisible to the access listener: the slug ends
	 * in `_completed`, so is_access_slug() returns null for it and no enrolment
	 * row is touched.
	 */
	public static function grant_completed( int $user_id, int $course_id ): bool {
		$user = \get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$slug = self::ensure_completion_role( $course_id );
		if ( '' === $slug ) {
			return false;
		}

		if ( ! self::user_has( $user_id, $slug ) ) {
			$user->add_role( $slug );
			Log::write( 'completion_role_granted', [ 'user' => $user_id, 'course' => $course_id ] );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------- */

	public static function user_has( int $user_id, string $role ): bool {
		$user = \get_userdata( $user_id );
		return $user instanceof \WP_User && \in_array( $role, \array_map( 'strval', (array) $user->roles ), true );
	}

	/**
	 * Which of these role slugs does the user NOT hold?
	 *
	 * @param string[] $role_slugs
	 * @return string[] Display names, in the order given.
	 */
	public static function missing( int $user_id, array $role_slugs ): array {
		$user = \get_userdata( $user_id );
		$held = $user instanceof \WP_User ? \array_map( 'strval', (array) $user->roles ) : [];

		$missing = [];
		foreach ( $role_slugs as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug || \in_array( $slug, $held, true ) ) {
				continue;
			}
			$role      = \wp_roles()->roles[ $slug ] ?? null;
			$missing[] = $role ? (string) $role['name'] : $slug;
		}

		return $missing;
	}

	/* ---------------------------------------------------------------------
	 * Surviving an unrelated primary-role change
	 * ------------------------------------------------------------------- */

	/**
	 * `set_user_role`: re-apply any course role a user held before an
	 * UNRELATED primary-role change stripped it.
	 *
	 * Core `WP_User::set_role()` (the single-role setter wp-admin's user list
	 * "Change role to..." bulk action, and the user-edit screen, both call)
	 * REPLACES the user's entire roles array rather than adding to it - unlike
	 * `add_role()`/`remove_role()`, which are additive. Without this, giving a
	 * learner an unrelated new primary role would silently un-enrol them and
	 * erase anything they had completed, which contradicts "holding it IS
	 * enrolment, and neither role is ever deleted automatically" (design spec
	 * 3.1). This is a course-role integrity guarantee, not the Task 20
	 * grant/revoke listener - it never calls EnrollmentService and does not
	 * care whether the role is being gained or lost, only that a role already
	 * held does not vanish as a side effect of a different change.
	 *
	 * @param int      $user_id
	 * @param string   $new_role
	 * @param string[] $old_roles The roles the user held a moment ago.
	 */
	public static function reapply_after_set_role( int $user_id, string $new_role, array $old_roles ): void {
		$stripped = \array_values( \array_filter( \array_map( 'strval', $old_roles ), [ self::class, 'is_course_role_slug' ] ) );
		if ( [] === $stripped ) {
			return;
		}

		$user = \get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$held = \array_map( 'strval', (array) $user->roles );
		foreach ( $stripped as $slug ) {
			if ( ! \in_array( $slug, $held, true ) ) {
				$user->add_role( $slug );
				Log::write( 'role_reapplied_after_set_role', [ 'user' => $user_id, 'role' => $slug ] );
			}
		}
	}
}
