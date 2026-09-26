<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Module;
use Anchor\Courses\Services\CompletionService;
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
 * refusal is reported as its own notice code, never as success. `repair`
 * (audit F02) is CompletionService::complete() on an already-completed row:
 * it re-runs only the completion effects left pending or failed. Its OWN
 * success is reported only once every tracked effect reads back `done`/`n/a`
 * (Codex P1 + CodeRabbit, PR #32 re-review) - not merely "nothing pending or
 * failed", which a fresh `running` claim or an empty (lock-busy) map also
 * satisfy without anything actually being confirmed. Anything short of that
 * is `repair_incomplete`, never `repaired`.
 */
final class EnrollmentManager {

	public const NONCE = 'anchor_courses_enrollment_nonce';

	public const ACTIONS = [ 'cancel', 'reset', 'complete', 'uncomplete', 'repair' ];

	public function __construct() {
		\add_action( 'admin_post_anchor_courses_manage_enrollment', [ $this, 'handle_action' ] );

		// This class's own notice vocabulary. CourseEditor's and
		// LearnerReports' codes are registered by Notices itself (Task 31
		// ruling - see its class docblock); this is what registering a
		// handler's own codes from the handler actually looks like.
		Notices::register( 'cancelled', Notices::TYPE_SUCCESS, \__( 'Enrolment cancelled and access removed.', 'anchor-schema' ) );
		Notices::register( 'reset', Notices::TYPE_SUCCESS, \__( 'Progress reset.', 'anchor-schema' ) );
		Notices::register( 'reset_busy', Notices::TYPE_ERROR, \__( 'Progress could not be reset: this course\'s completion is still processing from a moment ago. Try again shortly.', 'anchor-schema' ) );
		Notices::register( 'reset_failed', Notices::TYPE_ERROR, \__( 'Progress could not be reset: the update failed. Check the log, then try again.', 'anchor-schema' ) );
		Notices::register( 'completed', Notices::TYPE_SUCCESS, \__( 'Course marked complete.', 'anchor-schema' ) );
		Notices::register( 'uncompleted', Notices::TYPE_SUCCESS, \__( 'Completion undone. Credits and certificates were kept.', 'anchor-schema' ) );
		Notices::register( 'uncomplete_failed', Notices::TYPE_ERROR, \__( 'Completion could not be undone: it is not marked complete, or its completion steps are still running. Try again in a moment.', 'anchor-schema' ) );
		Notices::register( 'complete_failed', Notices::TYPE_ERROR, \__( 'The course could not be marked complete: the learner has no current access to it.', 'anchor-schema' ) );
		Notices::register( 'repaired', Notices::TYPE_SUCCESS, \__( 'Completion repaired: credits, certificate, completion role and notifications are all in place.', 'anchor-schema' ) );
		Notices::register( 'repair_incomplete', Notices::TYPE_ERROR, \__( 'Completion is not fully confirmed yet - a step failed, or is still finishing from a moment ago. Check the log, then try the repair again in a few minutes.', 'anchor-schema' ) );
		Notices::register( 'repair_not_completed', Notices::TYPE_ERROR, \__( 'There is nothing to repair: this learner has not completed the course.', 'anchor-schema' ) );
	}

	/**
	 * This metabox body renders inside WordPress's own post-edit <form>, so
	 * this can only print the VISIBLE controls, each bound via `form="…"` to
	 * the real admin-post <form> that MetaboxForms prints from admin_footer
	 * (CodeRabbit PR #29 - a nested <form> start tag is dropped by the
	 * browser, and its own _wpnonce field can shadow the post form's).
	 */
	public function render_form( int $course_id ): void {
		$form_id = 'anchor-courses-manage-enrollment-' . $course_id;

		echo '<h4>' . \esc_html__( 'Manage enrolment', 'anchor-schema' ) . '</h4>';
		echo '<p>';

		// wp_dropdown_users() has no way to add a form="…" attribute itself,
		// so it is generated off-form and the attribute is spliced into its
		// one <select> tag.
		$dropdown = (string) \wp_dropdown_users(
			[
				'name'              => 'user_id',
				'show_option_none'  => \__( '- select a user -', 'anchor-schema' ),
				'option_none_value' => '0',
				'number'            => 200,
				'echo'              => false,
			]
		);
		echo \str_replace( '<select ', '<select form="' . \esc_attr( $form_id ) . '" ', $dropdown );

		\printf( ' <select name="anchor_courses_action" form="%s">', \esc_attr( $form_id ) );
		$labels = [
			'cancel'     => \__( 'Cancel enrolment (removes access)', 'anchor-schema' ),
			'reset'      => \__( 'Reset progress', 'anchor-schema' ),
			'complete'   => \__( 'Mark complete', 'anchor-schema' ),
			'uncomplete' => \__( 'Undo completion', 'anchor-schema' ),
			'repair'     => \__( 'Repair completion (re-run failed steps)', 'anchor-schema' ),
		];
		foreach ( $labels as $value => $label ) {
			\printf( '<option value="%s">%s</option>', \esc_attr( $value ), \esc_html( $label ) );
		}
		echo '</select> ';
		\printf( '<button type="submit" class="button" form="%s">%s</button>', \esc_attr( $form_id ), \esc_html__( 'Apply', 'anchor-schema' ) );
		echo '</p>';

		MetaboxForms::queue_form(
			$form_id,
			'anchor_courses_manage_enrollment',
			[ 'course_id' => (string) $course_id ],
			self::NONCE . '_' . $course_id
		);
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
				// A WP_Error distinguishes a busy completion lock
				// (`reset_busy` - a completion/uncomplete pipeline is
				// mid-run for this row; nothing touched, retry shortly) from
				// a genuine write failure (`reset_failed` - also nothing
				// touched, but retrying immediately will not help; Round 9,
				// PR #32 finding 2 - the old boolean false could not tell
				// these apart, so a real error was reported as "try again").
				$reset = $module->progress->reset_course( $user_id, $course_id );
				Notices::redirect( \is_wp_error( $reset ) ? $reset->get_error_code() : 'reset', $target );
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
				// false: not completed, the completion lock is busy (a
				// pipeline is mid-run), or the write failed (Round 6).
				Notices::redirect( $module->completion->uncomplete( $user_id, $course_id ) ? 'uncompleted' : 'uncomplete_failed', $target );
				break;

			case 'repair':
				if ( ! $module->completion->is_complete( $user_id, $course_id ) ) {
					Notices::redirect( 'repair_not_completed', $target );
				}
				// CodeRabbit PR #32 (audit F02 re-review): a completed row
				// with no tracked effect state - predating tracking, or a
				// previous tracking write that failed - is no longer treated
				// as "nothing to verify". complete() now re-runs the
				// idempotent effects for exactly that row (run_effects()'s
				// `$untracked` branch), so this call always has a real
				// chance to create what was actually missing; the
				// `repair_not_tracked` shape it used to report can no longer
				// occur through this path.
				$module->completion->complete( $user_id, $course_id );

				// `repaired` only when EVERY tracked effect is confirmed
				// settled - `done` or `n/a` (Codex P1 + CodeRabbit, PR #32
				// re-review). The old check reported success whenever
				// nothing was `pending`/`failed`, which two other shapes
				// also satisfy without anything actually being confirmed:
				// a fresh `running` claim (a pipeline that crashed or is
				// still mid-flight, less than
				// CompletionService::EFFECTS_RUNNING_STALE_SECONDS old -
				// run_effects() deliberately leaves that alone rather than
				// re-running it, see its class docblock) is neither pending
				// nor failed; an empty map (this complete() call could not
				// get the completion lock, so it ran nothing at all) isn't
				// either. Checking every expected key explicitly - rather
				// than filtering for the two "bad" statuses - also catches
				// a map that is simply missing a key. A re-completion cycle's
				// `recompleted_hook` is included (Round 6) - see
				// CompletionService::is_settled().
				$repaired = CompletionService::is_settled( $module->completion->effects( $user_id, $course_id ) );
				Notices::redirect( $repaired ? 'repaired' : 'repair_incomplete', $target );
				break;
		}
	}
}
