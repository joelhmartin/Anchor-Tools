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

	/** Completion ends the work, not the access: a completed learner can still open lessons. */
	public function test_a_completed_learner_is_still_enrolled_while_holding_the_role() {
		Roles::grant_access( $this->user, $this->course );
		$this->enrollments->set_status( $this->user, $this->course, 'completed' );

		$this->assertTrue( $this->enrollments->is_enrolled( $this->user, $this->course ) );

		Roles::revoke_access( $this->user, $this->course );
		$this->assertFalse( $this->enrollments->is_enrolled( $this->user, $this->course ), 'without the role, completed or not, the door is shut.' );
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
		$this->assertSame( [], Roles::grant_record( $this->user, $this->course ) );
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

	/* ---------------------------------------------------------------------
	 * R4 - the source reaches the policy; the grants map
	 * ------------------------------------------------------------------- */

	public function test_the_loss_policy_filter_receives_the_revoke_source() {
		$seen = [];
		add_filter(
			'anchor_courses_role_loss_policy',
			static function ( $policy, $u, $c, $role, $source, $source_id ) use ( &$seen ) {
				$seen[] = [ $source, $source_id ];
				return $policy;
			},
			10,
			6
		);

		Roles::grant_access( $this->user, $this->course, 'woocommerce', '4242' );
		Roles::revoke_access( $this->user, $this->course, 'woocommerce', '4242' );

		get_userdata( $this->user )->add_role( $this->slug() );
		get_userdata( $this->user )->remove_role( $this->slug() );
		Roles::resolve_pending_losses(); // Context-less: queued until shutdown (final review I8).

		$this->assertSame( [ [ 'woocommerce', '4242' ], [ 'role', '' ] ], $seen );
	}

	public function test_grant_writes_and_revoke_clears_the_grants_record() {
		$this->freeze( '2026-02-02 10:00:00 UTC' );
		Roles::grant_access( $this->user, $this->course, 'woocommerce', '4242' );

		$this->assertSame(
			[ 'source' => 'woocommerce', 'source_id' => '4242', 'granted_at' => '2026-02-02 10:00:00' ],
			Roles::grant_record( $this->user, $this->course )
		);
		$this->assertArrayHasKey( $this->course, get_user_meta( $this->user, Roles::GRANTS_META, true ) );

		Roles::revoke_access( $this->user, $this->course, 'woocommerce', '4242' );

		$this->assertSame( [], Roles::grant_record( $this->user, $this->course ) );
	}

	public function test_a_raw_add_role_is_recorded_with_the_role_source() {
		get_userdata( $this->user )->add_role( $this->slug() );

		$this->assertSame( 'role', Roles::grant_record( $this->user, $this->course )['source'] ?? null );
	}

	/** Task 27's contract: a manual grant outranks a purchase and is never downgraded. */
	public function test_a_manual_grant_upgrades_the_record_and_is_never_downgraded() {
		Roles::grant_access( $this->user, $this->course, 'woocommerce', '4242' );
		Roles::grant_access( $this->user, $this->course, 'manual', '1' );
		$this->assertSame( 'manual', Roles::grant_record( $this->user, $this->course )['source'] );

		Roles::grant_access( $this->user, $this->course, 'woocommerce', '5555' );
		$this->assertSame( 'manual', Roles::grant_record( $this->user, $this->course )['source'] );
		$this->assertSame( '1', Roles::grant_record( $this->user, $this->course )['source_id'] );
	}

	/** set_role strips then restores the role; the record must come back unchanged. */
	public function test_the_grants_record_survives_a_primary_role_change() {
		$this->freeze( '2026-02-02 10:00:00 UTC' );
		Roles::grant_access( $this->user, $this->course, 'woocommerce', '4242' );
		$before = Roles::grant_record( $this->user, $this->course );

		$this->freeze( '2026-04-04 10:00:00 UTC' );
		wp_update_user( [ 'ID' => $this->user, 'role' => 'editor' ] );

		$this->assertSame( $before, Roles::grant_record( $this->user, $this->course ) );
	}

	/* ---------------------------------------------------------------------
	 * R5 - deleted users, removal from a site, one policy call per role
	 * ------------------------------------------------------------------- */

	public function test_deleting_the_user_cancels_their_active_rows() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$second = $this->make_course( [], 'Second' );
		Roles::grant_access( $this->user, $this->course, 'manual' );
		Roles::grant_access( $this->user, $second, 'manual' );
		$this->enrollments->set_status( $this->user, $second, 'completed' );

		wp_delete_user( $this->user );

		$this->assertSame( 'cancelled', $this->enrollments->get( $this->user, $this->course )->status );
		$this->assertSame( 'completed', $this->enrollments->get( $this->user, $second )->status, 'History is not rewritten.' );
	}

	public function test_removal_from_the_site_runs_the_loss_policy() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$sources = [];
		add_filter(
			'anchor_courses_role_loss_policy',
			static function ( $policy, $u, $c, $role, $source ) use ( &$sources ) {
				$sources[] = $source;
				return 'cancel';
			},
			10,
			5
		);

		// Multisite core fires this before it deletes the user's capabilities
		// for the site - no remove_user_role follows.
		do_action( 'remove_user_from_blog', $this->user, get_current_blog_id(), 0 );

		$this->assertSame( [ 'removed_from_site' ], $sources );
		$this->assertSame( 'cancelled', $this->enrollments->get( $this->user, $this->course )->status );
		$this->assertSame( [], Roles::grant_record( $this->user, $this->course ) );
	}

	public function test_the_new_listeners_are_wired() {
		$this->assertNotFalse( has_action( 'deleted_user', [ Roles::class, 'on_user_deleted' ] ) );
		$this->assertNotFalse( has_action( 'remove_user_from_blog', [ Roles::class, 'on_removed_from_site' ] ) );
	}

	/**
	 * The set_role/keep path is the one that re-enters enroll() (reapply puts
	 * the role back -> add_user_role -> enroll). Nothing may fire twice, and
	 * since final review I8 the policy is not consulted at all: the stripped
	 * role is re-applied, so its queued loss is dropped.
	 */
	public function test_set_role_under_keep_consults_no_policy_and_fires_nothing() {
		Roles::grant_access( $this->user, $this->course, 'manual' );

		$policy = 0;
		$fired  = [ 'enrolled' => 0, 'status' => 0, 'granted' => 0 ];
		add_filter(
			'anchor_courses_role_loss_policy',
			static function ( $p ) use ( &$policy ) {
				$policy++;
				return $p;
			}
		);
		add_action( 'anchor_courses_enrolled', function () use ( &$fired ) { $fired['enrolled']++; } );
		add_action( 'anchor_courses_enrollment_status_changed', function () use ( &$fired ) { $fired['status']++; } );
		add_action( 'anchor_courses_access_granted', function () use ( &$fired ) { $fired['granted']++; } );

		wp_update_user( [ 'ID' => $this->user, 'role' => 'editor' ] );

		$this->assertSame( 0, $policy, 'set_role churn is not a loss: the role comes straight back.' );
		$this->assertSame( [ 'enrolled' => 0, 'status' => 0, 'granted' => 0 ], $fired );
		$this->assertTrue( Roles::user_has( $this->user, $this->slug() ) );
		$this->assertTrue( $this->enrollments->is_enrolled( $this->user, $this->course ) );
	}

	/** Final review I8: a primary-role change under `cancel` is not a cancellation. */
	public function test_set_role_under_cancel_consults_no_policy_and_cancels_nothing() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$policy = 0;
		add_filter(
			'anchor_courses_role_loss_policy',
			static function () use ( &$policy ) {
				$policy++;
				return 'cancel';
			}
		);

		wp_update_user( [ 'ID' => $this->user, 'role' => 'editor' ] );
		Roles::resolve_pending_losses();

		$this->assertSame( 0, $policy );
		$this->assertSame( 'enrolled', $this->enrollments->get( $this->user, $this->course )->status );
		$this->assertTrue( Roles::user_has( $this->user, $this->slug() ) );
	}
}
