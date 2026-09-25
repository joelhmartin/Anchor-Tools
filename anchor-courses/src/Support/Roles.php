<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Services\EnrollmentService;

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
 * This class also owns the ONE listener that turns a role change into an
 * enrolment row (Task 20): `register_listeners()` hooks core's `add_user_role`,
 * `set_user_role` and `remove_user_role`, so a grant from anywhere - the
 * Learners tab, WooCommerce, WP-CLI, wp-admin's user screen, another plugin -
 * calls `EnrollmentService::enroll( bypass_checks )`, and a loss runs the
 * `anchor_courses_role_loss_policy` filter (default `keep`: access outlives
 * the thing that granted it). `grant_access()`/`revoke_access()` are the
 * supported way in and out; everything else that merely adds/removes the raw
 * role is still caught by the listener, just recorded with `source = 'role'`.
 *
 * A course role survives everything except a deliberate `delete_role()` call
 * or a `cancel`/`expire` loss policy - including an admin changing a
 * learner's PRIMARY role, which core `WP_User::set_role()` would otherwise
 * silently strip (see reapply_after_set_role(), which now defers to whatever
 * the loss policy just decided rather than blindly restoring the role).
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
		// No autoload argument: WP_Roles expects wp_user_roles autoloaded, and an
		// explicit false here would flip the option out of alloptions (core's own
		// remove_role() passes none either).
		\update_option( $roles->role_key, $roles->roles );
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
	 * Enrolment - the role listener (Task 20)
	 * ------------------------------------------------------------------- */

	/**
	 * Why the role currently being added was added.
	 *
	 * WordPress's role hooks carry no reason (deviation D15), so grant_access()
	 * parks it here for the length of one add_role() call and the listener -
	 * which runs INSIDE that call - reads it. Always cleared in a finally, so a
	 * value can never attach itself to somebody else's grant.
	 *
	 * @var array{source:string,source_id:string,row_closed?:bool}|null
	 */
	private static ?array $context = null;

	/** @var EnrollmentService|null Set once, by register_listeners(). */
	private static ?EnrollmentService $enrollments = null;

	/**
	 * Start listening to WordPress's role changes.
	 *
	 * These three core actions are the module's ONE enrolment entry point. A
	 * purchase, an admin, WP-CLI and the wp-admin user screen all arrive here.
	 */
	public static function register_listeners( EnrollmentService $enrollments ): void {
		self::$enrollments = $enrollments;

		\add_action( 'add_user_role', [ self::class, 'on_role_added' ], 10, 2 );
		\add_action( 'set_user_role', [ self::class, 'on_set_user_role' ], 10, 3 );
		\add_action( 'remove_user_role', [ self::class, 'on_role_removed' ], 10, 2 );
	}

	private static function enrollments(): EnrollmentService {
		return self::$enrollments ?? ( self::$enrollments = new EnrollmentService() );
	}

	/**
	 * Run $fn with the grant context set, and ALWAYS clear it afterwards.
	 *
	 * The one writer of self::$context (deviation D15). `$extra` carries flags
	 * for the listener, e.g. `row_closed` from remove_for_closed_row().
	 */
	private static function with_context( string $source, string $source_id, callable $fn, array $extra = [] ): void {
		$outer         = self::$context; // Restored, not nulled: a nested call must not wipe its caller's.
		self::$context = [ 'source' => $source, 'source_id' => $source_id ] + $extra;
		try {
			$fn();
		} finally {
			self::$context = $outer;
		}
	}

	/**
	 * Take the access role from somebody whose row is ALREADY closed.
	 *
	 * The expiry sweep's half of "expired means no access" (R2): the row has
	 * been decided, so the remove_user_role listener must neither consult the
	 * loss policy nor change the row a second time. "Roles are never deleted
	 * automatically" is about role DEFINITIONS; a user's holding follows the row.
	 */
	public static function remove_for_closed_row( int $user_id, int $course_id, string $source ): void {
		$user = \get_userdata( $user_id );
		$slug = self::access_slug( $course_id );
		if ( ! $user instanceof \WP_User || ! self::user_has( $user_id, $slug ) ) {
			return;
		}

		self::with_context( $source, '', static fn() => $user->remove_role( $slug ), [ 'row_closed' => true ] );

		Log::write( 'access_role_removed_for_closed_row', [ 'user' => $user_id, 'course' => $course_id, 'source' => $source ] );
	}

	/**
	 * Give a user access to a course, and say why.
	 *
	 * The supported way to enrol somebody from anywhere. Asks can_enroll()
	 * first, so an unmet prerequisite refuses the grant rather than letting
	 * somebody in and hoping - which is what makes prerequisites bind on the
	 * Learners tab and at the checkout alike.
	 *
	 * @param string $source    manual|woocommerce|role|... - recorded on the row.
	 * @param string $source_id Order id, actor id, whatever identifies it.
	 * @return true|\WP_Error
	 */
	public static function grant_access( int $user_id, int $course_id, string $source = 'manual', string $source_id = '' ) {
		$user = \get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'no_user', \__( 'That user does not exist.', 'anchor-schema' ) );
		}

		$slug = self::ensure_access_role( $course_id );
		if ( '' === $slug ) {
			return new \WP_Error( 'no_course', \__( 'That course has no access role - is it published?', 'anchor-schema' ) );
		}

		if ( self::user_has( $user_id, $slug ) ) {
			// Already in (brief 26) - but the row may have been closed out from
			// under the role (a direct cancel()/expire(), a legacy expired row).
			// Run the listener's enrol step anyway: an active row is returned
			// untouched, a closed one is reactivated (R2). No add_user_role
			// fires, so nothing else happens and no action is repeated.
			self::with_context( $source, $source_id, static fn() => self::enroll_for_role( $user_id, $slug ) );
			return true;
		}

		$allowed = self::enrollments()->can_enroll( $user_id, $course_id );
		if ( \is_wp_error( $allowed ) ) {
			return $allowed;
		}

		// Fires add_user_role -> on_role_added() inside the context.
		self::with_context( $source, $source_id, static fn() => $user->add_role( $slug ) );

		Log::write( 'access_granted', [ 'user' => $user_id, 'course' => $course_id, 'source' => $source ] );

		/**
		 * A user just gained access to a course.
		 *
		 * @param int    $user_id
		 * @param int    $course_id
		 * @param string $source
		 * @param string $source_id
		 */
		\do_action( 'anchor_courses_access_granted', $user_id, $course_id, $source, $source_id );

		return true;
	}

	/**
	 * Take access away.
	 *
	 * Removes the role; what that means for the enrolment row is decided in one
	 * place, by the loss policy in on_role_removed(), whoever took it away.
	 */
	public static function revoke_access( int $user_id, int $course_id, string $source = 'manual', string $source_id = '' ): bool {
		$user = \get_userdata( $user_id );
		$slug = self::access_slug( $course_id );

		if ( ! $user instanceof \WP_User || ! self::user_has( $user_id, $slug ) ) {
			return false;
		}

		// Fires remove_user_role -> on_role_removed() inside the context.
		self::with_context( $source, $source_id, static fn() => $user->remove_role( $slug ) );

		Log::write( 'access_revoked', [ 'user' => $user_id, 'course' => $course_id, 'source' => $source ] );

		/**
		 * A user just lost access to a course.
		 *
		 * @param int    $user_id
		 * @param int    $course_id
		 * @param string $source
		 */
		\do_action( 'anchor_courses_access_revoked', $user_id, $course_id, $source );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * The listeners
	 * ------------------------------------------------------------------- */

	/** Core `add_user_role( $user_id, $role )`. */
	public static function on_role_added( $user_id, $role ): void {
		self::enroll_for_role( (int) $user_id, (string) $role );
	}

	/**
	 * Core `set_user_role( $user_id, $role, $old_roles )` - the user's roles
	 * were REPLACED, so this is a gain and a pile of losses at once.
	 */
	public static function on_set_user_role( $user_id, $role, $old_roles = [] ): void {
		$user_id = (int) $user_id;
		$role    = (string) $role;

		self::enroll_for_role( $user_id, $role );

		foreach ( (array) $old_roles as $lost ) {
			$lost = (string) $lost;
			if ( $lost === $role ) {
				continue;
			}
			$course_id = self::is_access_slug( $lost );
			if ( null !== $course_id ) {
				self::apply_loss_policy( $user_id, $course_id, $lost );
			}
		}
	}

	/** Core `remove_user_role( $user_id, $role )`. */
	public static function on_role_removed( $user_id, $role ): void {
		$course_id = self::is_access_slug( (string) $role );
		if ( null === $course_id ) {
			return;
		}
		if ( ! empty( self::$context['row_closed'] ) ) {
			return; // remove_for_closed_row(): the row is already decided.
		}
		self::apply_loss_policy( (int) $user_id, $course_id, (string) $role );
	}

	/**
	 * A user now holds this role. If it is an access role, that IS enrolment.
	 *
	 * bypass_checks is deliberate: by the time this runs the role is held, and
	 * refusing here would leave somebody with access and no row - the worst of
	 * both answers. The gate lives in grant_access(), before the role is added.
	 */
	private static function enroll_for_role( int $user_id, string $role ): void {
		$course_id = self::is_access_slug( $role );
		if ( null === $course_id || $user_id <= 0 ) {
			return;
		}

		$context = self::$context ?? [ 'source' => 'role', 'source_id' => '' ];

		$result = self::enrollments()->enroll(
			$user_id,
			$course_id,
			[
				'bypass_checks' => true,
				'source'        => (string) $context['source'],
				'source_id'     => (string) $context['source_id'],
			]
		);

		if ( \is_wp_error( $result ) ) {
			Log::write( 'role_enroll_failed', [ 'user' => $user_id, 'course' => $course_id, 'code' => $result->get_error_code() ] );
		}
	}

	/**
	 * What losing the access role does to the enrolment.
	 *
	 * Default `keep`: access outlives the thing that granted it, and the
	 * progress rows stay, so re-adding the role resumes the learner exactly
	 * where they stopped (design spec 3.1).
	 */
	public static function apply_loss_policy( int $user_id, int $course_id, string $role ): void {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return;
		}

		// Only an ACTIVE row has anything to decide. A completed row keeps its
		// history whatever the policy says; a closed one is already closed.
		$enrollment = self::enrollments()->get( $user_id, $course_id );
		if ( null === $enrollment || ! $enrollment->is_active() ) {
			return;
		}

		/**
		 * What happens to an enrolment when its access role is lost.
		 *
		 * @param string $policy   keep|expire|cancel. Default 'keep'.
		 * @param int    $user_id
		 * @param int    $course_id
		 * @param string $role
		 */
		$policy = (string) \apply_filters( 'anchor_courses_role_loss_policy', 'keep', $user_id, $course_id, $role );

		if ( 'cancel' === $policy ) {
			self::enrollments()->cancel( $user_id, $course_id );
		} elseif ( 'expire' === $policy ) {
			self::enrollments()->expire( $user_id, $course_id );
		}
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
	 * 3.1).
	 *
	 * This runs on the SAME `set_user_role` firing as on_set_user_role() (Task
	 * 20), which is registered second and so runs after this - but the loss
	 * policy for a role dropped by set_role() has already been decided by the
	 * time either listener runs: core's own set_role() fires `remove_user_role`
	 * for every stripped role, synchronously, BEFORE it fires `set_user_role`
	 * (see class-wp-user.php), and on_role_removed() is what applies the policy.
	 * That means for an ACCESS role this method only needs to read the row's
	 * status: `keep` (the default) leaves it active, and a completed row is
	 * never touched by a policy, so the role is restored exactly as before;
	 * a site-configured `cancel`/`expire` has already closed it, so this
	 * defers rather than fighting that decision back on. A COMPLETION role carries no enrolment and is always restored -
	 * nothing governs its loss.
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
			if ( \in_array( $slug, $held, true ) ) {
				continue;
			}

			// Read the ROW, not is_enrolled(): the role is already gone by now,
			// so is_enrolled() is false for everybody (R1). Only a row the loss
			// policy just closed stays stripped; active and completed learners
			// (and a holder with no row yet) get the role back.
			$course_id = self::is_access_slug( $slug );
			if ( null !== $course_id ) {
				$enrollment = self::enrollments()->get( $user_id, $course_id );
				if ( null !== $enrollment && \in_array( $enrollment->status, EnrollmentService::CLOSED_STATUSES, true ) ) {
					continue; // The loss was intentional (a cancel/expire policy) - do not put it back.
				}
			}

			$user->add_role( $slug );
			Log::write( 'role_reapplied_after_set_role', [ 'user' => $user_id, 'role' => $slug ] );
		}
	}
}
