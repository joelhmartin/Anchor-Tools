<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Database\ProgressRepository;
use Anchor\Courses\Module;
use Anchor\Courses\Services\CompletionService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Capabilities;
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
 * `complete` and `uncomplete` prefer Services\CompletionService when it is
 * available: `Module::$completion` is read with `isset()` first, the same
 * forward-reference shape ProgressService/CertificateService already use in
 * this module for a sibling task's class before it has joined the branch.
 * Per the progress ledger ("T31 needs 21+27+28", not 29), CompletionService
 * (Task 29) has NOT joined this worktree yet, so both verbs fall back to
 * driving the enrolment status directly through EnrollmentService - never the
 * repository (ROLES ARE ENROLMENT ruling: a status change that is not an
 * access change goes through the service, not Database\EnrollmentRepository).
 * Once Task 29 lands, `Module::$completion` starts existing and this class
 * needs no change to pick it up.
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

		$nonce = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['_wpnonce'] ?? '' ) ) );
		if ( ! \wp_verify_nonce( $nonce, self::NONCE . '_' . $course_id ) ) {
			$this->redirect( 'bad_nonce', $course_id );
		}
		if ( ! Capabilities::current_user_can( 'enrollments' ) ) {
			$this->redirect( 'forbidden', $course_id );
		}
		if ( $user_id <= 0 || ! \get_userdata( $user_id ) ) {
			$this->redirect( 'no_user', $course_id );
		}
		if ( ! \in_array( $action, self::ACTIONS, true ) ) {
			$this->redirect( 'error', $course_id );
		}

		$module      = Module::instance();
		$enrollments = $module ? $module->enrollments : new EnrollmentService();

		// isset() first: Module::$completion does not exist as a property at
		// all until Task 29 lands, and evaluating it directly (without isset)
		// would be an undefined-property read. instanceof against a class
		// that does not exist yet simply evaluates false - it does not fatal
		// (the same tolerance ProgressService::recalculate_course() already
		// relies on).
		$completion = ( $module && isset( $module->completion ) && $module->completion instanceof CompletionService )
			? $module->completion
			: null;

		switch ( $action ) {
			case 'cancel':
				// Take the access away AND close the row. The loss policy is
				// not consulted: it exists to decide what an *incidental* role
				// loss means, and there is nothing incidental about an operator
				// choosing "Cancel enrolment".
				Roles::revoke_access( $user_id, $course_id, 'admin' );
				$enrollments->cancel( $user_id, $course_id );
				$this->redirect( 'cancelled', $course_id );
				break;

			case 'reset':
				( $module ? $module->progress : new \Anchor\Courses\Services\ProgressService( $enrollments ) )->reset_course( $user_id, $course_id );
				$this->redirect( 'reset', $course_id );
				break;

			case 'complete':
				// Marking someone complete implies they had access. Grant the
				// role (idempotent); the listener creates the enrolment row.
				Roles::grant_access( $user_id, $course_id, 'manual' );

				if ( null !== $completion ) {
					// Force the transition even when items are outstanding: an
					// admin saying "complete" is the manual completion mode.
					\add_filter( 'anchor_courses_course_completion_status', '__return_true', 99 );
					$completion->complete( $user_id, $course_id );
					\remove_filter( 'anchor_courses_course_completion_status', '__return_true', 99 );
				} else {
					// CompletionService has not joined this branch yet - drive
					// the enrolment status directly so the action still works.
					// Credits/certificates are the completion pipeline's job
					// once it lands; this fallback never touches either table.
					$enrollments->set_status( $user_id, $course_id, 'completed' );
				}
				$this->redirect( 'completed', $course_id );
				break;

			case 'uncomplete':
				if ( null !== $completion ) {
					$completion->uncomplete( $user_id, $course_id );
				} else {
					$enrollments->set_status( $user_id, $course_id, 'enrolled' );
				}
				$this->redirect( 'uncompleted', $course_id );
				break;
		}
	}

	private function redirect( string $code, int $course_id ): void {
		$target = $course_id > 0
			? (string) \get_edit_post_link( $course_id, 'raw' )
			: \admin_url();

		\wp_safe_redirect( \add_query_arg( 'anchor_courses_admin_notice', $code, $target ) );
		exit;
	}
}
