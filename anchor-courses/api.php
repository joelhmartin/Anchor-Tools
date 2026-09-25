<?php
/**
 * Anchor Courses - public PHP API (brief section 15).
 *
 * Root namespace on purpose: integrators call these without a use statement.
 * Every function is a thin wrapper over one of the MODULE'S OWN services
 * (`\Anchor\Courses\Module::instance()->...`), never a freshly constructed
 * one: a bare `new ProgressService()` has no completion pipeline injected,
 * so finishing the last item through it would never complete the course
 * (final review I1). None of these functions contains logic.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The booted courses module, or a WP_Error when it is not loaded yet (a call
 * made before anchor_tools_bootstrap_modules() ran).
 *
 * Internal to this file - not part of the public API.
 *
 * @return \Anchor\Courses\Module|WP_Error
 */
function _anchor_courses_module() {
	$module = \Anchor\Courses\Module::instance();
	return $module ?? new WP_Error( 'courses_not_loaded', __( 'Anchor Courses is not loaded yet.', 'anchor-schema' ) );
}

/**
 * Record a CE credit award (brief section 15).
 *
 * Idempotent per (user, course): a second call returns the existing record.
 * Returns null when there is nothing to award - a non-course, a non-existent
 * user, a zero amount, or the module not loaded yet are all the same
 * non-outcome - and WP_Error `credit_insert_failed` when a credit was due but
 * could not be saved (audit F02).
 *
 * @param int   $user_id
 * @param int   $course_id
 * @param float $credits
 * @return \Anchor\Courses\Domain\Credit|\WP_Error|null
 */
function anchor_courses_award_ce_credit( $user_id, $course_id, $credits ) {
	$module = _anchor_courses_module();
	if ( is_wp_error( $module ) ) {
		return null;
	}
	return $module->credits->award( (int) $user_id, (int) $course_id, (float) $credits );
}

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
	$module = _anchor_courses_module();
	if ( is_wp_error( $module ) ) {
		return $module;
	}
	$granted = \Anchor\Courses\Support\Roles::grant_access(
		(int) $user_id,
		(int) $course_id,
		(string) ( $args['source'] ?? 'api' ),
		(string) ( $args['source_id'] ?? '' )
	);
	if ( is_wp_error( $granted ) ) {
		return $granted;
	}
	$enrollment = $module->enrollments->get( (int) $user_id, (int) $course_id );
	return $enrollment ?? new WP_Error( 'not_enrolled', __( 'The enrolment could not be recorded.', 'anchor-schema' ) );
}

/**
 * Mark a lesson complete for a user (brief section 15).
 *
 * Idempotent, and subject to enrolment + progression rules. Completing the
 * last required item runs the course completion pipeline (credits,
 * certificate, completion role, `anchor_courses_course_completed`).
 *
 * @param int $user_id
 * @param int $course_id
 * @param int $lesson_id
 * @return \Anchor\Courses\Domain\Progress|WP_Error
 */
function anchor_courses_complete_lesson( $user_id, $course_id, $lesson_id ) {
	$module = _anchor_courses_module();
	if ( is_wp_error( $module ) ) {
		return $module;
	}
	return $module->progress->complete_lesson( (int) $user_id, (int) $course_id, (int) $lesson_id );
}

/**
 * Rolled-up progress for one learner on one course (brief section 15).
 *
 * @param int $user_id
 * @param int $course_id
 * @return \Anchor\Courses\Domain\CourseProgress|WP_Error WP_Error only when the module is not loaded yet.
 */
function anchor_courses_get_progress( $user_id, $course_id ) {
	$module = _anchor_courses_module();
	if ( is_wp_error( $module ) ) {
		return $module;
	}
	return $module->progress->get_course_progress( (int) $user_id, (int) $course_id );
}
