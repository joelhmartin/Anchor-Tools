<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Module;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Enrolment maintenance on the course screen (design ruling, Task 31/32).
 *
 * Four verbs, all of them about an enrolment that already exists. ADDING a
 * learner is not here: that is the Learners tab's add form (Task 21), which
 * grants the access role through Support\Roles like every other grant. Two
 * ways to enrol from one screen would be two chances to skip the prerequisite
 * check - `ACTIONS` deliberately has no `enroll`.
 *
 * `complete`/`uncomplete` go through Services\CompletionService, the
 * once-only pipeline; `reset` through ProgressService::reset_course(). Every
 * refusal is reported as its own notice code, never as success.
 */
final class EnrollmentManager {

	public const NONCE = 'anchor_courses_enrollment_nonce';

	public const ACTIONS = [ 'cancel', 'reset', 'complete', 'uncomplete' ];

	public function __construct() {
		\add_action( 'admin_post_anchor_courses_manage_enrollment', [ $this, 'handle_action' ] );

		// This class's own notice vocabulary. CourseEditor's and
		// LearnerReports' codes are registered by Notices itself (Task 31
		// ruling - see its class docblock); this is what registering a
		// handler's own codes from the handler actually looks like.
		Notices::register( 'cancelled', Notices::TYPE_SUCCESS, \__( 'Enrolment cancelled and access removed.', 'anchor-schema' ) );
		Notices::register( 'reset', Notices::TYPE_SUCCESS, \__( 'Progress reset.', 'anchor-schema' ) );
		Notices::register( 'completed', Notices::TYPE_SUCCESS, \__( 'Course marked complete.', 'anchor-schema' ) );
		Notices::register( 'uncompleted', Notices::TYPE_SUCCESS, \__( 'Completion undone. Credits and certificates were kept.', 'anchor-schema' ) );
		Notices::register( 'complete_failed', Notices::TYPE_ERROR, \__( 'The course could not be marked complete: the learner has no current access to it.', 'anchor-schema' ) );
	}

	public function render_form( int $course_id ): void {
		echo '<h4>' . \esc_html__( 'Manage enrolment', 'anchor-schema' ) . '</h4>';
		\printf(
			'<form method="post" action="%s" class="anchor-courses-enrollment-form">',
			\esc_url( \admin_url( 'admin-post.php' ) )
		);
		\wp_nonce_field( self::NONCE . '_' . $course_id );
		echo '<input type="hidden" name="action" value="anchor_courses_manage_enrollment" />';
		\printf( '<input type="hidden" name="course_id" value="%d" />', $course_id );

		echo '<p>';
		\wp_dropdown_users(
			[
				'name'              => 'user_id',
				'show_option_none'  => \__( '- select a user -', 'anchor-schema' ),
				'option_none_value' => '0',
				'number'            => 200,
			]
		);
		echo ' <select name="anchor_courses_action">';
		$labels = [
			'cancel'     => \__( 'Cancel enrolment (removes access)', 'anchor-schema' ),
			'reset'      => \__( 'Reset progress', 'anchor-schema' ),
			'complete'   => \__( 'Mark complete', 'anchor-schema' ),
			'uncomplete' => \__( 'Undo completion', 'anchor-schema' ),
		];
		foreach ( $labels as $value => $label ) {
			\printf( '<option value="%s">%s</option>', \esc_attr( $value ), \esc_html( $label ) );
		}
		echo '</select> ';
		\printf( '<button type="submit" class="button">%s</button>', \esc_html__( 'Apply', 'anchor-schema' ) );
		echo '</p></form>';
	}

	public function handle_action(): void {
		$course_id = \absint( $_POST['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$user_id   = \absint( $_POST['user_id'] ?? 0 );   // phpcs:ignore WordPress.Security.NonceVerification
		$action    = \sanitize_key( \wp_unslash( (string) ( $_POST['anchor_courses_action'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$target    = Notices::course_url( $course_id );

		$refusal = Notices::authorisation_error( self::NONCE . '_' . $course_id, 'enrollments' );
		if ( '' !== $refusal ) {
			Notices::redirect( $refusal, $target );
		}
		if ( $user_id <= 0 || ! \get_userdata( $user_id ) ) {
			Notices::redirect( 'no_user', $target );
		}
		if ( ! \in_array( $action, self::ACTIONS, true ) ) {
			Notices::redirect( 'error', $target );
		}

		$module = Module::instance();
		if ( ! $module instanceof Module ) {
			Notices::redirect( 'error', $target );
		}

		switch ( $action ) {
			case 'cancel':
				// Take the access away AND close the row. The loss policy is
				// not consulted: it exists to decide what an *incidental* role
				// loss means, and there is nothing incidental about an operator
				// choosing "Cancel enrolment".
				Roles::revoke_access( $user_id, $course_id, 'admin' );
				$module->enrollments->cancel( $user_id, $course_id );
				Notices::redirect( 'cancelled', $target );
				break;

			case 'reset':
				$module->progress->reset_course( $user_id, $course_id );
				Notices::redirect( 'reset', $target );
				break;

			case 'complete':
				// Marking someone complete implies they had access. Grant the
				// role (idempotent); the listener creates the enrolment row. A
				// refused grant (unmet prerequisite, unpublished course) is
				// reported as its own code - never as "completed".
				$granted = Roles::grant_access( $user_id, $course_id, 'manual' );
				if ( \is_wp_error( $granted ) ) {
					Notices::redirect( (string) $granted->get_error_code(), $target );
				}

				// Force the transition even when items are outstanding: an
				// admin saying "complete" is the manual completion mode.
				\add_filter( 'anchor_courses_course_completion_status', '__return_true', 99 );
				$module->completion->complete( $user_id, $course_id );
				\remove_filter( 'anchor_courses_course_completion_status', '__return_true', 99 );

				// complete() is false both for a refusal and for "already
				// complete"; only the first is a failure.
				Notices::redirect( $module->completion->is_complete( $user_id, $course_id ) ? 'completed' : 'complete_failed', $target );
				break;

			case 'uncomplete':
				$module->completion->uncomplete( $user_id, $course_id );
				Notices::redirect( 'uncompleted', $target );
				break;
		}
	}
}
