<?php
/**
 * Anchor Courses - "no role, no access" holds on every path (Task 20 fix round,
 * rulings R1-R5 of the Opus review).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Services\QuizService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Role_Access_Invariant extends Anchor_Courses_TestCase {

	private EnrollmentService $enrollments;
	private int $user;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		$this->enrollments = new EnrollmentService();
		$this->user        = $this->make_learner();
		$this->course      = $this->make_course( [ 'progression_mode' => 'free' ], 'Laser Safety' );
		$this->lesson      = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_role_loss_policy' );
		remove_all_filters( 'anchor_courses_now' );
		remove_all_actions( 'anchor_courses_enrolled' );
		remove_all_actions( 'anchor_courses_enrollment_status_changed' );
		remove_all_actions( 'anchor_courses_access_granted' );
		parent::tear_down();
	}

	private function slug(): string {
		return Roles::access_slug( $this->course );
	}

	private function freeze( string $when ): void {
		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( $when ) );
	}

	/* ---------------------------------------------------------------------
	 * R1 - is_enrolled() = active row AND the access role is held
	 * ------------------------------------------------------------------- */

	/** The refund case: revoke under the default `keep` must shut the door. */
	public function test_revoke_under_keep_denies_access_to_progress_and_quizzes() {
		$quiz = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80 ] ] );
		Questions::save(
			$quiz,
			[ [ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
			    'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => true ], [ 'id' => 'a2', 'text' => 'B', 'correct' => false ] ] ] ]
		);
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ], [ 'type' => 'quiz', 'id' => $quiz ] ] ] ] );

		Roles::grant_access( $this->user, $this->course, 'woocommerce', '4242' );
		$this->assertTrue( $this->enrollments->is_enrolled( $this->user, $this->course ) );

		Roles::revoke_access( $this->user, $this->course, 'woocommerce', '4242' );

		$this->assertSame( 'enrolled', $this->enrollments->get( $this->user, $this->course )->status, '`keep` leaves the row.' );
		$this->assertFalse( $this->enrollments->is_enrolled( $this->user, $this->course ), 'No role, no access.' );

		$progress = new ProgressService( $this->enrollments );
		$this->assertFalse( $progress->is_item_available( $this->user, $this->course, $this->lesson ) );
		$this->assertSame( 'not_enrolled', $progress->complete_lesson( $this->user, $this->course, $this->lesson )->get_error_code() );

		$quizzes = new QuizService( $progress, $this->enrollments );
		$this->assertSame( 'not_enrolled', $quizzes->can_start( $this->user, $quiz, $this->course )->get_error_code() );
	}

	/** A row written straight through the service, with no role, admits nobody. */
	public function test_a_row_without_the_role_is_not_enrolment() {
		$this->enrollments->enroll( $this->user, $this->course );

		$this->assertFalse( $this->enrollments->is_enrolled( $this->user, $this->course ) );
	}

	/** A bare add_user_role() from anywhere is enough (the one door). */
	public function test_a_direct_add_role_admits() {
		get_userdata( $this->user )->add_role( $this->slug() );

		$this->assertTrue( $this->enrollments->is_enrolled( $this->user, $this->course ) );
	}

	/** Finding 4: a completed learner's access role survives a primary-role change. */
	public function test_a_completed_learner_keeps_the_access_role_through_set_role() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$this->enrollments->set_status( $this->user, $this->course, 'completed' );

		wp_update_user( [ 'ID' => $this->user, 'role' => 'editor' ] );

		$this->assertTrue( Roles::user_has( $this->user, $this->slug() ), 'reapply must restore the role of a completed learner.' );
		$this->assertSame( 'completed', $this->enrollments->get( $this->user, $this->course )->status );
	}

	public function test_an_in_progress_learner_keeps_the_access_role_through_set_role() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$this->enrollments->start( $this->user, $this->course );

		wp_update_user( [ 'ID' => $this->user, 'role' => 'editor' ] );

		$this->assertTrue( Roles::user_has( $this->user, $this->slug() ) );
		$this->assertSame( 'in_progress', $this->enrollments->get( $this->user, $this->course )->status );
		$this->assertTrue( $this->enrollments->is_enrolled( $this->user, $this->course ) );
	}

	/** A loss policy never un-completes a finished learner. */
	public function test_a_cancel_policy_does_not_touch_a_completed_row() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$this->enrollments->set_status( $this->user, $this->course, 'completed' );
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel' );

		Roles::revoke_access( $this->user, $this->course, 'admin' );

		$this->assertSame( 'completed', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/* ---------------------------------------------------------------------
	 * R2 - the sweep removes the role; a re-grant repairs a closed row
	 * ------------------------------------------------------------------- */

	public function test_the_sweep_removes_the_access_role_without_consulting_the_loss_policy() {
		update_post_meta( $this->course, '_anchor_course_expiration_days', '1' );
		$this->freeze( '2026-01-01 00:00:00 UTC' );
		Roles::grant_access( $this->user, $this->course, 'manual' );

		$policy_calls = 0;
		add_filter(
			'anchor_courses_role_loss_policy',
			static function () use ( &$policy_calls ) {
				$policy_calls++;
				return 'cancel';
			}
		);

		$this->freeze( '2026-03-01 00:00:00 UTC' );
		$this->assertSame( 1, $this->enrollments->sweep_expired() );

		$this->assertFalse( Roles::user_has( $this->user, $this->slug() ), 'Expired means no access - the role goes.' );
		$this->assertSame( 'expired', $this->enrollments->get( $this->user, $this->course )->status, 'Not re-closed as cancelled.' );
		$this->assertSame( 0, $policy_calls, 'The row is already closed; the loss policy has nothing to decide.' );
	}

	/** Also R3: the reactivated row gets a fresh expires_at, not the swept one. */
	public function test_re_granting_after_the_sweep_reactivates_the_row_with_a_fresh_expiry() {
		update_post_meta( $this->course, '_anchor_course_expiration_days', '10' );
		$this->freeze( '2026-01-01 00:00:00 UTC' );
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$id = $this->enrollments->get( $this->user, $this->course )->id;

		$this->freeze( '2026-03-01 00:00:00 UTC' );
		$this->enrollments->sweep_expired();

		$this->assertTrue( Roles::grant_access( $this->user, $this->course, 'manual' ) );

		$row = $this->enrollments->get( $this->user, $this->course );
		$this->assertSame( $id, $row->id );
		$this->assertSame( 'enrolled', $row->status );
		$this->assertSame( '2026-03-11 00:00:00', $row->expires_at, 'expires_at is recomputed from expiration_days at reactivation.' );
		$this->assertTrue( $this->enrollments->is_enrolled( $this->user, $this->course ) );

		$this->assertSame( 0, $this->enrollments->sweep_expired(), 'The next day\'s sweep must not close it again.' );
	}

	/** The role is still held but the row was closed out from under it. */
	public function test_grant_access_repairs_a_closed_row_when_the_role_is_already_held() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$this->enrollments->expire( $this->user, $this->course );
		$this->assertTrue( Roles::user_has( $this->user, $this->slug() ) );

		$this->assertTrue( Roles::grant_access( $this->user, $this->course, 'manual' ) );

		$this->assertSame( 'enrolled', $this->enrollments->get( $this->user, $this->course )->status );
		$this->assertTrue( $this->enrollments->is_enrolled( $this->user, $this->course ) );
	}

	/* ---------------------------------------------------------------------
	 * R3 - reactivation status, expiry and action
	 * ------------------------------------------------------------------- */

	public function test_reactivation_returns_a_started_learner_to_in_progress_and_fires_status_changed() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$this->enrollments->start( $this->user, $this->course );

		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel' );
		Roles::revoke_access( $this->user, $this->course, 'admin' );
		remove_all_filters( 'anchor_courses_role_loss_policy' );

		$enrolled = 0;
		$changes  = [];
		add_action( 'anchor_courses_enrolled', function () use ( &$enrolled ) { $enrolled++; } );
		add_action(
			'anchor_courses_enrollment_status_changed',
			function ( $u, $c, $from, $to ) use ( &$changes ) { $changes[] = [ $from, $to ]; },
			10,
			4
		);

		Roles::grant_access( $this->user, $this->course, 'manual' );

		$this->assertSame( 'in_progress', $this->enrollments->get( $this->user, $this->course )->status );
		$this->assertSame( 0, $enrolled, 'anchor_courses_enrolled is a one-time event.' );
		$this->assertSame( [ [ 'cancelled', 'in_progress' ] ], $changes );
	}

	public function test_reactivation_clears_expires_at_when_the_course_no_longer_expires() {
		update_post_meta( $this->course, '_anchor_course_expiration_days', '5' );
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$this->assertNotNull( $this->enrollments->get( $this->user, $this->course )->expires_at );

		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'expire' );
		Roles::revoke_access( $this->user, $this->course, 'admin' );
		remove_all_filters( 'anchor_courses_role_loss_policy' );
		update_post_meta( $this->course, '_anchor_course_expiration_days', '0' );

		Roles::grant_access( $this->user, $this->course, 'manual' );

		$this->assertNull( $this->enrollments->get( $this->user, $this->course )->expires_at );
	}
}
