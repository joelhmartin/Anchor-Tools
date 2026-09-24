<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Domain\Enrollment;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Log;

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
	 * @return string[]
	 */
	public function missing_prerequisites( int $user_id, int $course_id ): array {
		$required = (array) CourseEditor::setting( $course_id, 'prerequisites' );
		if ( [] === $required ) {
			return [];
		}

		$user = \get_userdata( $user_id );
		if ( ! $user ) {
			return [];
		}

		$held    = \array_map( 'strval', (array) $user->roles );
		$missing = [];

		foreach ( $required as $slug ) {
			$slug = (string) $slug;
			if ( \in_array( $slug, $held, true ) ) {
				continue;
			}
			$role      = \wp_roles()->roles[ $slug ] ?? null;
			$missing[] = $role ? (string) $role['name'] : $slug;
		}

		return $missing;
	}

	/**
	 * Enrol a user. Idempotent: an existing row is returned untouched.
	 *
	 * @param array $args source, source_id, metadata, bypass_checks.
	 * @return Enrollment|\WP_Error
	 */
	public function enroll( int $user_id, int $course_id, array $args = [] ) {
		$existing = EnrollmentRepository::find( $user_id, $course_id );
		if ( $existing instanceof Enrollment ) {
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

		$expiration_days = (int) CourseEditor::setting( $course_id, 'expiration_days' );

		$enrollment = EnrollmentRepository::insert_ignore(
			[
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'status'      => 'enrolled',
				'enrolled_at' => Clock::now(),
				'expires_at'  => $expiration_days > 0 ? Clock::offset( $expiration_days * DAY_IN_SECONDS ) : null,
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

	public function get( int $user_id, int $course_id ): ?Enrollment {
		return EnrollmentRepository::find( $user_id, $course_id );
	}

	public function is_enrolled( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		return $enrollment instanceof Enrollment && $enrollment->is_active();
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

	/** Daily sweep. @return int rows flipped to expired. */
	public function sweep_expired(): int {
		return EnrollmentRepository::expire_due( Clock::now() );
	}
}
