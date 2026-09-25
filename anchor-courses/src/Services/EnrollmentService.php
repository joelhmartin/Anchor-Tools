<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Domain\Enrollment;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Log;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Who is enrolled in what, and why (brief 15, 16, 26).
 *
 * This service owns the `wp_anchor_courses_enrollments` row and its status
 * machine only. It does NOT grant or revoke the `anchor_course_{id}` access
 * role - per the design spec (section 3.1) that role is minted and listened
 * for by `Support\Roles` (Task 19/20, not yet built): a `set_user_role`-style
 * listener calls `enroll()` with `bypass_checks` once the role is already
 * held ("holding it IS enrolment"), and a role-loss policy decides whether to
 * call `cancel()`/`expire()`/leave the row alone when the role is removed.
 * `can_enroll()` is what `Support\Roles::grant_access()` will ask BEFORE
 * granting that role, so a missing prerequisite refuses the grant itself.
 * Keeping the role write and the row write in that one listener - rather than
 * here - is what keeps them one operation instead of two independent sources
 * of truth: nothing grants the role without going through the listener that
 * also writes this row, and nothing writes this row (outside the bypass path)
 * without the role decision already made.
 */
final class EnrollmentService {

	public const CRON_HOOK = 'anchor_courses_expire_sweep';

	/**
	 * May this user be given access to this course right now?
	 *
	 * Asked by Support\Roles::grant_access() before it hands out the access
	 * role, so the Learners tab and the WooCommerce adapter refuse together.
	 * Never asked on the front end: nobody enrols themselves.
	 *
	 * @return true|\WP_Error
	 */
	public function can_enroll( int $user_id, int $course_id ) {
		$result = $this->check( $user_id, $course_id );

		/**
		 * Filter the enrolment decision.
		 *
		 * @param true|\WP_Error $result
		 * @param int            $user_id
		 * @param int            $course_id
		 */
		return \apply_filters( 'anchor_courses_can_enroll', $result, $user_id, $course_id );
	}

	/** @return true|\WP_Error */
	private function check( int $user_id, int $course_id ) {
		if ( $user_id <= 0 || ! \get_userdata( $user_id ) ) {
			return new \WP_Error( 'no_user', \__( 'That user does not exist.', 'anchor-schema' ) );
		}
		if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			return new \WP_Error( 'no_course', \__( 'That course does not exist.', 'anchor-schema' ) );
		}

		$now  = Clock::timestamp();
		$from = (string) CourseEditor::setting( $course_id, 'available_from' );
		$to   = (string) CourseEditor::setting( $course_id, 'available_until' );

		if ( '' !== $from && $now < (int) \strtotime( $from . ' 00:00:00 UTC' ) ) {
			return new \WP_Error( 'not_available_yet', \__( 'This course is not open yet.', 'anchor-schema' ) );
		}
		if ( '' !== $to && $now > (int) \strtotime( $to . ' 23:59:59 UTC' ) ) {
			return new \WP_Error( 'no_longer_available', \__( 'This course has closed.', 'anchor-schema' ) );
		}

		$missing = $this->missing_prerequisites( $user_id, $course_id );
		if ( [] !== $missing ) {
			return new \WP_Error(
				'missing_prerequisite',
				\sprintf(
					/* translators: %s: comma-separated list of role display names. */
					\__( 'This course requires: %s', 'anchor-schema' ),
					\implode( ', ', $missing )
				)
			);
		}

		return true;
	}

	/**
	 * Prerequisite roles (course-completion or event-attendance slugs) the user
	 * does not hold, by display name.
	 *
	 * Thin wrapper: "which of these role slugs is the user missing, by display
	 * name" is a role question, not an enrolment one, and `Support\Roles`
	 * already answers it generically for the Learners tab and the listener's
	 * own reapply guard. This is the ONE place that decides what "a course's
	 * prerequisites" means (its `_anchor_course_prerequisites` meta); Roles is
	 * the one place that decides what "missing" means.
	 *
	 * @return string[]
	 */
	public function missing_prerequisites( int $user_id, int $course_id ): array {
		$required = \array_map( 'strval', (array) CourseEditor::setting( $course_id, 'prerequisites' ) );

		return Roles::missing( $user_id, $required );
	}

	/** Statuses a re-grant brings back to life (reactivate()). */
	public const CLOSED_STATUSES = [ 'cancelled', 'expired' ];

	/**
	 * Enrol a user. Idempotent: an existing ACTIVE or COMPLETED row is returned
	 * untouched; a CLOSED one (cancelled/expired) is reactivated.
	 *
	 * @param array $args source, source_id, metadata, bypass_checks.
	 * @return Enrollment|\WP_Error
	 */
	public function enroll( int $user_id, int $course_id, array $args = [] ) {
		$existing = EnrollmentRepository::find( $user_id, $course_id );
		if ( $existing instanceof Enrollment ) {
			// A row that was closed (cancel/expire policy, the expiry sweep) must
			// not sit there closed while the user holds the role again - "holding
			// it IS enrolment" (design spec 3.1) cuts both ways. `completed` is
			// left alone: finishing a course is not undone by a role round-trip.
			if ( \in_array( $existing->status, self::CLOSED_STATUSES, true ) ) {
				return $this->reactivate( $existing );
			}
			return $existing;
		}

		if ( empty( $args['bypass_checks'] ) ) {
			$allowed = $this->can_enroll( $user_id, $course_id );
			if ( \is_wp_error( $allowed ) ) {
				return $allowed;
			}
		} elseif ( CoursePostType::CPT !== \get_post_type( $course_id ) || ! \get_userdata( $user_id ) ) {
			// Even the bypass path must name a real user and a real course.
			return new \WP_Error( 'no_course', \__( 'That course does not exist.', 'anchor-schema' ) );
		}

		$enrollment = EnrollmentRepository::insert_ignore(
			[
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'status'      => 'enrolled',
				'enrolled_at' => Clock::now(),
				'expires_at'  => $this->expires_at_from_now( $course_id ),
				'source'      => (string) ( $args['source'] ?? 'manual' ),
				'source_id'   => (string) ( $args['source_id'] ?? '' ),
				'metadata'    => (array) ( $args['metadata'] ?? [] ),
			]
		);

		if ( ! $enrollment instanceof Enrollment ) {
			return new \WP_Error( 'enroll_failed', \__( 'The enrolment could not be saved.', 'anchor-schema' ) );
		}

		Log::write( 'enrolled', [ 'user' => $user_id, 'course' => $course_id, 'source' => $enrollment->source ] );

		/**
		 * Fires once, the first time a user is enrolled in a course.
		 *
		 * @param Enrollment $enrollment
		 * @param int        $user_id
		 * @param int        $course_id
		 */
		\do_action( 'anchor_courses_enrolled', $enrollment, $user_id, $course_id );

		return $enrollment;
	}

	/**
	 * Bring a closed row back (Task 20 fix round, R3).
	 *
	 * A learner who had started resumes as `in_progress`, one who never opened
	 * anything as `enrolled`. The access window restarts from now - keeping the
	 * old expires_at would have the next daily sweep close the row again.
	 * Fires `anchor_courses_enrollment_status_changed`, never
	 * `anchor_courses_enrolled`, which stays a true one-time event.
	 */
	private function reactivate( Enrollment $existing ): Enrollment {
		$status  = null !== $existing->started_at ? 'in_progress' : 'enrolled';
		$updated = EnrollmentRepository::update(
			$existing->id,
			[ 'status' => $status, 'expires_at' => $this->expires_at_from_now( $existing->course_id ) ]
		);

		Log::write( 'enrollment_reactivated', [ 'user' => $existing->user_id, 'course' => $existing->course_id, 'from' => $existing->status ] );

		/** This action is documented in set_status(). */
		\do_action( 'anchor_courses_enrollment_status_changed', $existing->user_id, $existing->course_id, $existing->status, $status );

		return $updated ?? $existing;
	}

	/** The course's access window measured from now, or null when it never expires. */
	private function expires_at_from_now( int $course_id ): ?string {
		$days = (int) CourseEditor::setting( $course_id, 'expiration_days' );
		return $days > 0 ? Clock::offset( $days * DAY_IN_SECONDS ) : null;
	}

	public function get( int $user_id, int $course_id ): ?Enrollment {
		return EnrollmentRepository::find( $user_id, $course_id );
	}

	/**
	 * Every enrolment row a user holds, optionally filtered by status.
	 *
	 * Thin wrapper over EnrollmentRepository::for_user() (closes the Task 18
	 * layering exception - Frontend\Shortcodes::my_courses() read the
	 * repository directly while this service was locked mid-review; see
	 * progress.md). Side-effect-free, so there is nothing here beyond naming
	 * the read as the service's own.
	 *
	 * @param string[] $statuses Optional status filter; [] returns every row.
	 * @return Enrollment[]
	 */
	public function get_for_user( int $user_id, array $statuses = [] ): array {
		return EnrollmentRepository::for_user( $user_id, $statuses );
	}

	/**
	 * May this learner use the course right now?
	 *
	 * BOTH halves are required (Task 20 fix round, R1): an open row AND the
	 * `anchor_course_{id}` access role. The role is the door - revoking it
	 * shuts access under every loss policy, including the default `keep`,
	 * which only preserves the row (and so the learner's progress) for a
	 * later re-grant. The row is the record - a role held over a closed row
	 * (cancelled/expired) does not re-open the course by itself. A completed
	 * learner is still enrolled: completion ends the work, not the access,
	 * so they can revisit every lesson for as long as they hold the role.
	 *
	 * An ACTIVE row past its `expires_at` is not enrolment either, even before
	 * the daily sweep flips it to `expired` (final review ruling): access ends
	 * at the expiry instant, the sweep only tidies the row and the role up.
	 * A completed row is exempt - the sweep never expires it, and completion
	 * keeps access for as long as the role is held.
	 */
	public function is_enrolled( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment || ! ( $enrollment->is_active() || $enrollment->is_complete() ) ) {
			return false;
		}
		if ( $enrollment->is_active() && null !== $enrollment->expires_at && Clock::to_timestamp( $enrollment->expires_at ) <= Clock::timestamp() ) {
			return false;
		}
		return Roles::user_has( $user_id, Roles::access_slug( $course_id ) );
	}

	/** Mark the course started. No-op (returns the row) if it already was. */
	public function start( int $user_id, int $course_id ): ?Enrollment {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment ) {
			return null;
		}
		if ( null !== $enrollment->started_at ) {
			return $enrollment;
		}

		$updated = EnrollmentRepository::update(
			$enrollment->id,
			[ 'status' => 'in_progress', 'started_at' => Clock::now() ]
		);

		/**
		 * Fires the first time a learner opens an item in a course.
		 *
		 * @param int        $user_id
		 * @param int        $course_id
		 * @param Enrollment $enrollment
		 */
		\do_action( 'anchor_courses_course_started', $user_id, $course_id, $updated );

		return $updated;
	}

	/**
	 * Return a row to "never opened": status `enrolled`, started_at cleared,
	 * so the next item opened fires anchor_courses_course_started again.
	 * Used by ProgressService::reset_course(). completed_at is cleared too -
	 * credits/certificates are separate records and stay.
	 */
	public function restart( int $user_id, int $course_id ): ?Enrollment {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment ) {
			return null;
		}

		$updated = EnrollmentRepository::update(
			$enrollment->id,
			[ 'status' => 'enrolled', 'started_at' => null, 'completed_at' => null ]
		);

		if ( 'enrolled' !== $enrollment->status ) {
			/** This action is documented in set_status(). */
			\do_action( 'anchor_courses_enrollment_status_changed', $user_id, $course_id, $enrollment->status, 'enrolled' );
		}

		return $updated;
	}

	public function set_status( int $user_id, int $course_id, string $status ): ?Enrollment {
		if ( ! \in_array( $status, Enrollment::STATUSES, true ) ) {
			return null;
		}
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment || $enrollment->status === $status ) {
			return $enrollment;
		}

		$from    = $enrollment->status;
		$updated = EnrollmentRepository::update( $enrollment->id, [ 'status' => $status ] );

		/**
		 * Fires whenever an enrolment's status changes.
		 *
		 * @param int    $user_id
		 * @param int    $course_id
		 * @param string $from
		 * @param string $to
		 */
		\do_action( 'anchor_courses_enrollment_status_changed', $user_id, $course_id, $from, $status );

		return $updated;
	}

	public function cancel( int $user_id, int $course_id ): ?Enrollment {
		return $this->set_status( $user_id, $course_id, 'cancelled' );
	}

	public function expire( int $user_id, int $course_id ): ?Enrollment {
		return $this->set_status( $user_id, $course_id, 'expired' );
	}

	/**
	 * Cancel every ACTIVE row a user has - their account is going away.
	 * Completed and already-closed rows are history and are left alone.
	 *
	 * @return int Rows cancelled.
	 */
	public function cancel_all_for_user( int $user_id ): int {
		$cancelled = 0;
		foreach ( EnrollmentRepository::for_user( $user_id, Enrollment::ACTIVE_STATUSES ) as $enrollment ) {
			if ( null !== $this->cancel( $user_id, $enrollment->course_id ) ) {
				$cancelled++;
			}
		}
		return $cancelled;
	}

	/**
	 * Daily sweep. @return int rows flipped to expired.
	 *
	 * Expired means no access, so every learner whose row the sweep closes
	 * also loses the access role (R2). The role goes AFTER the row is closed,
	 * through Roles::remove_for_closed_row(), which tells the role listener
	 * the row has already been decided so the loss policy is not consulted a
	 * second time.
	 */
	public function sweep_expired(): int {
		$now     = Clock::now();
		$due     = EnrollmentRepository::due_for_expiry( $now );
		$flipped = EnrollmentRepository::expire_due( $now );

		foreach ( $due as $enrollment ) {
			Roles::remove_for_closed_row( $enrollment->user_id, $enrollment->course_id, 'expiry' );
		}

		return $flipped;
	}
}
