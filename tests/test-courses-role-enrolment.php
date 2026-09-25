<?php
/**
 * Anchor Courses - the access role IS the enrolment (design spec 3.1).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Role_Enrolment extends Anchor_Courses_TestCase {

	private EnrollmentService $enrollments;
	private int $user;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->enrollments = new EnrollmentService();
		$this->user        = $this->make_learner();
		$this->course      = $this->make_course( [], 'Laser Safety' );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_role_loss_policy' );
		remove_all_actions( 'anchor_courses_access_granted' );
		remove_all_actions( 'anchor_courses_access_revoked' );
		parent::tear_down();
	}

	public function test_grant_access_adds_the_role_and_creates_the_row() {
		$this->assertTrue( Roles::grant_access( $this->user, $this->course, 'manual', '7' ) );

		$this->assertTrue( Roles::user_has( $this->user, Roles::access_slug( $this->course ) ) );
		$this->assertContains( 'subscriber', get_userdata( $this->user )->roles, 'The grant is additive.' );

		$enrollment = $this->enrollments->get( $this->user, $this->course );
		$this->assertNotNull( $enrollment );
		$this->assertSame( 'enrolled', $enrollment->status );
		$this->assertSame( 'manual', $enrollment->source );
		$this->assertSame( '7', $enrollment->source_id );
	}

	/**
	 * The point of the whole design: a role added from anywhere at all enrols.
	 * This is the wp-admin user screen, WP-CLI and every other plugin.
	 */
	public function test_a_plain_add_user_role_enrols_with_the_role_source() {
		get_user_by( 'id', $this->user )->add_role( Roles::access_slug( $this->course ) );

		$enrollment = $this->enrollments->get( $this->user, $this->course );
		$this->assertNotNull( $enrollment, 'A bare add_user_role() must enrol.' );
		$this->assertSame( 'role', $enrollment->source, 'Nothing said why, so the source is the role itself.' );
		$this->assertSame( '', $enrollment->source_id );
	}

	/** set_user_role REPLACES the roles, and WordPress fires it for every new account. */
	public function test_set_user_role_also_enrols() {
		wp_update_user( [ 'ID' => $this->user, 'role' => Roles::access_slug( $this->course ) ] );

		$this->assertTrue( $this->enrollments->is_enrolled( $this->user, $this->course ) );
	}

	/** The completion role is not the access role, and must not enrol anybody. */
	public function test_the_completion_role_does_not_enrol() {
		Roles::grant_completed( $this->user, $this->course );

		$this->assertNull(
			$this->enrollments->get( $this->user, $this->course ),
			'anchor_course_{id}_completed must slide past the listener untouched.'
		);
	}

	/** Neither does an event role, or any other role on the site. */
	public function test_unrelated_roles_do_not_enrol() {
		add_role( 'anchor_event_42', 'Event: Summit', [] );

		$user = get_user_by( 'id', $this->user );
		$user->add_role( 'anchor_event_42' );
		$user->add_role( 'editor' );

		$this->assertNull( $this->enrollments->get( $this->user, $this->course ) );

		remove_role( 'anchor_event_42' );
	}

	/** Brief 26: granting twice must not duplicate the row or the action. */
	public function test_granting_twice_is_idempotent() {
		$fired = 0;
		add_action( 'anchor_courses_enrolled', function () use ( &$fired ) { $fired++; }, 10, 3 );

		Roles::grant_access( $this->user, $this->course, 'manual' );
		Roles::grant_access( $this->user, $this->course, 'manual' );
		get_user_by( 'id', $this->user )->add_role( Roles::access_slug( $this->course ) );

		$this->assertSame( 1, $fired );

		remove_all_actions( 'anchor_courses_enrolled' );
	}

	/** The source of the FIRST grant is the one that sticks. */
	public function test_a_later_grant_does_not_rewrite_the_original_source() {
		Roles::grant_access( $this->user, $this->course, 'woocommerce', '4242' );
		Roles::revoke_access( $this->user, $this->course, 'woocommerce', '4242' );
		Roles::grant_access( $this->user, $this->course, 'manual' );

		$this->assertSame( 'woocommerce', $this->enrollments->get( $this->user, $this->course )->source );
		$this->assertSame( '4242', $this->enrollments->get( $this->user, $this->course )->source_id );
	}

	/** An unmet prerequisite refuses the grant - the role is never added. */
	public function test_grant_access_refuses_an_unmet_prerequisite() {
		add_role( 'anchor_course_555_completed', 'Completed: Required First', [] );
		update_post_meta( $this->course, '_anchor_course_prerequisites', [ 'anchor_course_555_completed' ] );

		$result = Roles::grant_access( $this->user, $this->course, 'manual' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_prerequisite', $result->get_error_code() );
		$this->assertFalse( Roles::user_has( $this->user, Roles::access_slug( $this->course ) ) );
		$this->assertNull( $this->enrollments->get( $this->user, $this->course ) );

		get_user_by( 'id', $this->user )->add_role( 'anchor_course_555_completed' );
		$this->assertTrue( Roles::grant_access( $this->user, $this->course, 'manual' ) );

		remove_role( 'anchor_course_555_completed' );
	}

	/** Both actions fire with the documented arguments. */
	public function test_the_access_actions_fire() {
		$seen = [];
		add_action( 'anchor_courses_access_granted', function ( $u, $c, $s, $sid ) use ( &$seen ) { $seen[] = [ 'granted', $u, $c, $s, $sid ]; }, 10, 4 );
		add_action( 'anchor_courses_access_revoked', function ( $u, $c, $s ) use ( &$seen ) { $seen[] = [ 'revoked', $u, $c, $s ]; }, 10, 3 );

		Roles::grant_access( $this->user, $this->course, 'manual', '9' );
		Roles::revoke_access( $this->user, $this->course, 'admin' );

		$this->assertSame(
			[
				[ 'granted', $this->user, $this->course, 'manual', '9' ],
				[ 'revoked', $this->user, $this->course, 'admin' ],
			],
			$seen
		);
	}

	/** Design spec 3.1: access outlives the thing that granted it. */
	public function test_losing_the_role_keeps_the_enrolment_by_default() {
		Roles::grant_access( $this->user, $this->course, 'manual' );

		Roles::revoke_access( $this->user, $this->course, 'admin' );

		$this->assertFalse( Roles::user_has( $this->user, Roles::access_slug( $this->course ) ) );
		$this->assertSame( 'enrolled', $this->enrollments->get( $this->user, $this->course )->status );
	}

	public function test_the_loss_policy_can_cancel_or_expire() {
		Roles::grant_access( $this->user, $this->course, 'manual' );

		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );
		Roles::revoke_access( $this->user, $this->course, 'admin' );
		$this->assertSame( 'cancelled', $this->enrollments->get( $this->user, $this->course )->status );

		remove_all_filters( 'anchor_courses_role_loss_policy' );
		Roles::grant_access( $this->user, $this->course, 'manual' );

		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'expire', 10, 4 );
		get_user_by( 'id', $this->user )->remove_role( Roles::access_slug( $this->course ) );
		// A context-less raw removal is queued (final review I8) and resolved
		// at shutdown - resolved here directly.
		Roles::resolve_pending_losses();
		$this->assertSame( 'expired', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/** `keep` leaves the progress rows, so re-granting resumes the learner. */
	public function test_re_granting_after_a_loss_resumes_the_same_enrolment() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$first = $this->enrollments->get( $this->user, $this->course )->id;

		Roles::revoke_access( $this->user, $this->course, 'admin' );
		Roles::grant_access( $this->user, $this->course, 'manual' );

		$this->assertSame( $first, $this->enrollments->get( $this->user, $this->course )->id );
	}

	/**
	 * A row that was actually CLOSED (cancel/expire) must not sit there closed
	 * forever while the user holds the role again - that is the "holding the
	 * role IS enrolment" invariant the whole task is named for. Re-granting
	 * resumes it to 'enrolled', same row, same id.
	 */
	public function test_re_granting_after_a_cancellation_reactivates_the_row() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$first = $this->enrollments->get( $this->user, $this->course )->id;

		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );
		Roles::revoke_access( $this->user, $this->course, 'admin' );
		$this->assertSame( 'cancelled', $this->enrollments->get( $this->user, $this->course )->status );

		remove_all_filters( 'anchor_courses_role_loss_policy' );
		Roles::grant_access( $this->user, $this->course, 'manual' );

		$enrollment = $this->enrollments->get( $this->user, $this->course );
		$this->assertSame( $first, $enrollment->id, 'Same row, not a duplicate.' );
		$this->assertSame( 'enrolled', $enrollment->status, 'Holding the role again must read back as enrolled.' );
	}

	/* ---------------------------------------------------------------------
	 * Final review I8 - set_role churn is not a loss
	 *
	 * Core WP_User::set_role() fires remove_user_role for EVERY role it strips,
	 * even when the primary role is unchanged - so an ordinary admin profile
	 * save used to run a non-`keep` loss policy on every course the learner
	 * held. Context-less losses are now queued and resolved by the
	 * set_user_role listener (role re-applied -> dropped) or at shutdown.
	 * ------------------------------------------------------------------- */

	public function test_a_profile_save_with_the_same_primary_role_keeps_every_enrolment_under_cancel() {
		$second = $this->make_course( [], 'Second' );
		Roles::grant_access( $this->user, $this->course, 'manual' );
		Roles::grant_access( $this->user, $second, 'manual' );
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		wp_update_user( [ 'ID' => $this->user, 'role' => 'subscriber' ] );
		Roles::resolve_pending_losses(); // Nothing may be left for shutdown to cancel either.

		foreach ( [ $this->course, $second ] as $course ) {
			$this->assertTrue( Roles::user_has( $this->user, Roles::access_slug( $course ) ) );
			$this->assertSame( 'enrolled', $this->enrollments->get( $this->user, $course )->status );
			$this->assertTrue( $this->enrollments->is_enrolled( $this->user, $course ) );
		}
	}

	public function test_changing_the_primary_role_keeps_every_enrolment_under_cancel() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		wp_update_user( [ 'ID' => $this->user, 'role' => 'customer' ] );
		Roles::resolve_pending_losses();

		$this->assertTrue( Roles::user_has( $this->user, Roles::access_slug( $this->course ) ) );
		$this->assertSame( 'enrolled', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/** Under the default policy the role and the row survive exactly as before. */
	public function test_set_user_role_keeps_the_role_and_enrolment_active_under_the_default_policy() {
		Roles::grant_access( $this->user, $this->course, 'manual' );

		wp_update_user( [ 'ID' => $this->user, 'role' => 'customer' ] );

		$this->assertTrue( Roles::user_has( $this->user, Roles::access_slug( $this->course ) ) );
		$this->assertSame( 'enrolled', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/** An explicit revoke carries a reason, so the policy still applies at once. */
	public function test_an_explicit_revoke_under_cancel_still_cancels_immediately() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		Roles::revoke_access( $this->user, $this->course, 'admin' );

		$this->assertSame( 'cancelled', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/** A raw remove_role() with no set_role behind it is a real loss - resolved at shutdown. */
	public function test_a_raw_remove_role_under_cancel_cancels_once_the_queue_resolves() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		get_user_by( 'id', $this->user )->remove_role( Roles::access_slug( $this->course ) );
		$this->assertSame( 'enrolled', $this->enrollments->get( $this->user, $this->course )->status, 'Queued, not applied yet.' );
		$this->assertNotFalse( has_action( 'shutdown', [ Roles::class, 'resolve_pending_losses' ] ) );

		Roles::resolve_pending_losses();

		$this->assertSame( 'cancelled', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/** A queued loss whose role came back before shutdown is dropped. */
	public function test_a_queued_loss_is_dropped_when_the_role_comes_back() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		$user = get_user_by( 'id', $this->user );
		$user->remove_role( Roles::access_slug( $this->course ) );
		$user->add_role( Roles::access_slug( $this->course ) );
		Roles::resolve_pending_losses();

		$this->assertSame( 'enrolled', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/** Deleting the role strips every holder, and each loss runs the policy. */
	public function test_deleting_the_access_role_applies_the_policy_to_every_holder() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		Roles::delete_role( Roles::access_slug( $this->course ) );

		$this->assertSame( 'cancelled', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/** The grant context never survives the call that set it (deviation D15). */
	public function test_the_grant_context_does_not_leak_to_the_next_grant() {
		$second = $this->make_course( [], 'Second' );

		Roles::grant_access( $this->user, $this->course, 'woocommerce', '4242' );
		get_user_by( 'id', $this->user )->add_role( Roles::access_slug( $second ) );

		$this->assertSame( 'role', $this->enrollments->get( $this->user, $second )->source );
		$this->assertSame( '', $this->enrollments->get( $this->user, $second )->source_id );
	}

	/** A course role naming a course that no longer exists must not blow up. */
	public function test_a_deleted_courses_role_is_handled_without_error() {
		$ghost = $this->make_course( [], 'Ghost' );
		$slug  = Roles::access_slug( $ghost );

		wp_delete_post( $ghost, true );

		get_user_by( 'id', $this->user )->add_role( $slug );

		$this->assertNull( $this->enrollments->get( $this->user, $ghost ) );

		get_user_by( 'id', $this->user )->remove_role( $slug );
		remove_role( $slug );
	}

	/** Wiring: the three core hooks are actually registered. */
	public function test_the_three_listeners_are_wired(): void {
		$this->assertNotFalse( has_action( 'add_user_role', [ Roles::class, 'on_role_added' ] ) );
		$this->assertNotFalse( has_action( 'set_user_role', [ Roles::class, 'on_set_user_role' ] ) );
		$this->assertNotFalse( has_action( 'remove_user_role', [ Roles::class, 'on_role_removed' ] ) );
	}
}
