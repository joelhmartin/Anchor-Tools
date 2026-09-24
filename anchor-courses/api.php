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
 * Idempotent: enrolling an already-enrolled user returns the existing record.
 *
 * @param int   $user_id
 * @param int   $course_id
 * @param array $args source, source_id, metadata, bypass_checks.
 * @return \Anchor\Courses\Domain\Enrollment|WP_Error
 */
function anchor_courses_enroll_user( $user_id, $course_id, array $args = [] ) {
	return ( new \Anchor\Courses\Services\EnrollmentService() )->enroll( (int) $user_id, (int) $course_id, $args );
}
