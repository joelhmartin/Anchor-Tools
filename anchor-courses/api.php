<?php
/**
 * Anchor Courses - public PHP API (brief section 15).
 *
 * Root namespace on purpose: integrators call these without a use statement.
 * Every function is a thin wrapper over a service; none contains logic.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Functions are added by Tasks 14, 17 and 27.

/**
 * Enrol a user in a course (brief section 15).
 *
 * Holding the course's access role IS enrolment (spec 3.1), so this grants the
 * role; the role listener writes the enrolment row. Idempotent: an already
 * enrolled user keeps their record, a cancelled/expired one is reactivated.
 *
 * @param int   $user_id
 * @param int   $course_id
 * @param array $args source, source_id. `metadata` and `bypass_checks` are
 *                    ignored: Roles::grant_access() refuses an unpublished
 *                    course (no access role exists yet) and then runs
 *                    can_enroll() (prerequisites held) before granting, the
 *                    same gate every other grant path goes through.
 * @return \Anchor\Courses\Domain\Enrollment|WP_Error
 */
function anchor_courses_enroll_user( $user_id, $course_id, array $args = [] ) {
	$granted = \Anchor\Courses\Support\Roles::grant_access(
		(int) $user_id,
		(int) $course_id,
		(string) ( $args['source'] ?? 'api' ),
		(string) ( $args['source_id'] ?? '' )
	);
	if ( is_wp_error( $granted ) ) {
		return $granted;
	}
	$enrollment = ( new \Anchor\Courses\Services\EnrollmentService() )->get( (int) $user_id, (int) $course_id );
	return $enrollment ?? new WP_Error( 'not_enrolled', __( 'The enrolment could not be recorded.', 'anchor-schema' ) );
}

/**
 * Mark a lesson complete for a user (brief section 15).
 *
 * Idempotent, and subject to enrolment + progression rules.
 *
 * @param int $user_id
 * @param int $course_id
 * @param int $lesson_id
 * @return \Anchor\Courses\Domain\Progress|WP_Error
 */
function anchor_courses_complete_lesson( $user_id, $course_id, $lesson_id ) {
	return ( new \Anchor\Courses\Services\ProgressService() )
		->complete_lesson( (int) $user_id, (int) $course_id, (int) $lesson_id );
}

/**
 * Rolled-up progress for one learner on one course (brief section 15).
 *
 * @param int $user_id
 * @param int $course_id
 * @return \Anchor\Courses\Domain\CourseProgress
 */
function anchor_courses_get_progress( $user_id, $course_id ) {
	return ( new \Anchor\Courses\Services\ProgressService() )
		->get_course_progress( (int) $user_id, (int) $course_id );
}
