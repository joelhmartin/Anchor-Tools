<?php
/**
 * Anchor Courses - enrolment rules and events (brief 15, 16, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Domain\Enrollment;
use Anchor\Courses\Services\EnrollmentService;

/** @group courses */
class Test_Courses_Enrollment_Service extends Anchor_Courses_TestCase {

	private EnrollmentService $service;

	public function set_up() {
		parent::set_up();
		$this->service = new EnrollmentService();
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_can_enroll' );
		remove_all_actions( 'anchor_courses_enrolled' );
		remove_all_actions( 'anchor_courses_course_started' );
		remove_all_filters( 'anchor_courses_now' );
		parent::tear_down();
	}

	public function test_enroll_creates_one_row_and_fires_the_action() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$fired  = [];
		add_action( 'anchor_courses_enrolled', function ( $e, $u, $c ) use ( &$fired ) { $fired[] = [ $u, $c ]; }, 10, 3 );

		$enrollment = $this->service->enroll( $user, $course );

		$this->assertInstanceOf( Enrollment::class, $enrollment );
		$this->assertSame( 'enrolled', $enrollment->status );
		$this->assertSame( [ [ $user, $course ] ], $fired );
	}

	/** Brief 26: a second enrol returns the same row and fires nothing. */
	public function test_enroll_is_idempotent() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$count  = 0;
		add_action( 'anchor_courses_enrolled', function () use ( &$count ) { $count++; }, 10, 3 );

		$first  = $this->service->enroll( $user, $course );
		$second = $this->service->enroll( $user, $course );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( 1, $count, 'The enrolled action must fire once per enrolment.' );
	}

	/** The source travels with the enrolment, whichever door it came through. */
	public function test_the_bypass_path_records_its_source() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$allowed = $this->service->enroll( $user, $course, [ 'bypass_checks' => true, 'source' => 'woocommerce', 'source_id' => '4242' ] );
		$this->assertInstanceOf( Enrollment::class, $allowed );
		$this->assertSame( 'woocommerce', $allowed->source );
		$this->assertSame( '4242', $allowed->source_id );
	}

	/** There is no access type, so there is no "closed" refusal to make. */
	public function test_there_is_no_course_closed_refusal() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$this->assertTrue( $this->service->can_enroll( $user, $course ) );
	}

	public function test_availability_window_is_enforced() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-06-15 12:00:00 UTC' ) );
		$user = $this->make_learner();

		$future = $this->make_course( [ 'available_from' => '2026-09-01' ] );
		$past   = $this->make_course( [ 'available_until' => '2026-01-31' ] );
		$open   = $this->make_course( [ 'available_from' => '2026-01-01', 'available_until' => '2026-12-31' ] );

		$this->assertSame( 'not_available_yet', $this->service->enroll( $user, $future )->get_error_code() );
		$this->assertSame( 'no_longer_available', $this->service->enroll( $user, $past )->get_error_code() );
		$this->assertInstanceOf( Enrollment::class, $this->service->enroll( $user, $open ) );
	}

	public function test_expiration_days_sets_expires_at() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-01-01 00:00:00 UTC' ) );
		$user   = $this->make_learner();
		$course = $this->make_course( [ 'expiration_days' => '30' ] );

		$enrollment = $this->service->enroll( $user, $course );

		$this->assertSame( '2026-01-31 00:00:00', $enrollment->expires_at );
	}

	public function test_prerequisite_roles_block_enrolment_until_held() {
		$user   = $this->make_learner();
		$course = $this->make_course( [ 'prerequisites' => [ 'anchor_course_999_completed' ] ] );

		$refused = $this->service->enroll( $user, $course );
		$this->assertSame( 'missing_prerequisite', $refused->get_error_code() );

		add_role( 'anchor_course_999_completed', 'Completed: Prereq', [] );
		get_user_by( 'id', $user )->add_role( 'anchor_course_999_completed' );

		$this->assertInstanceOf( Enrollment::class, $this->service->enroll( $user, $course ) );

		remove_role( 'anchor_course_999_completed' );
	}

	public function test_the_can_enroll_filter_can_veto() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		add_filter(
			'anchor_courses_can_enroll',
			static fn( $allowed, $u, $c ) => new WP_Error( 'nope', 'Blocked by a test.' ),
			10,
			3
		);

		$this->assertSame( 'nope', $this->service->enroll( $user, $course )->get_error_code() );
	}

	public function test_start_sets_started_at_once_and_fires_course_started() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$this->service->enroll( $user, $course );
		$fired = 0;
		add_action( 'anchor_courses_course_started', function () use ( &$fired ) { $fired++; }, 10, 3 );

		$first = $this->service->start( $user, $course );
		$again = $this->service->start( $user, $course );

		$this->assertSame( 'in_progress', $first->status );
		$this->assertNotNull( $first->started_at );
		$this->assertSame( $first->started_at, $again->started_at );
		$this->assertSame( 1, $fired );
	}

	public function test_is_enrolled_is_false_for_cancelled_and_expired() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$this->service->enroll( $user, $course );
		$this->assertTrue( $this->service->is_enrolled( $user, $course ) );

		$this->service->cancel( $user, $course );
		$this->assertFalse( $this->service->is_enrolled( $user, $course ) );
	}

	public function test_sweep_expired_flips_due_rows() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-01-01 00:00:00 UTC' ) );
		$user   = $this->make_learner();
		$course = $this->make_course( [ 'expiration_days' => '1' ] );
		$this->service->enroll( $user, $course );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-03-01 00:00:00 UTC' ) );

		$this->assertSame( 1, $this->service->sweep_expired() );
		$this->assertSame( 'expired', $this->service->get( $user, $course )->status );
	}

	public function test_the_public_api_function_delegates_to_the_service() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$enrollment = anchor_courses_enroll_user( $user, $course, [ 'source' => 'api' ] );

		$this->assertInstanceOf( Enrollment::class, $enrollment );
		$this->assertSame( 'api', $enrollment->source );
	}

	public function test_enroll_refuses_a_non_course_post() {
		$user = $this->make_learner();
		$this->assertSame( 'no_course', $this->service->enroll( $user, $this->make_lesson() )->get_error_code() );
	}

	/**
	 * House pattern (progress.md): hook wiring is asserted via has_action(),
	 * cron scheduling via wp_next_scheduled() - not exercised indirectly.
	 */
	public function test_the_expiry_sweep_is_wired_and_scheduled() {
		$module = $this->courses();

		$this->assertNotFalse(
			\has_action( EnrollmentService::CRON_HOOK, [ $module->enrollments, 'sweep_expired' ] ),
			'The cron hook must call EnrollmentService::sweep_expired() on the module\'s own instance.'
		);
		$this->assertNotFalse(
			\wp_next_scheduled( EnrollmentService::CRON_HOOK ),
			'The daily expiry sweep must be scheduled once the module boots.'
		);
	}
	/** Task 14 review (HIGH): a course with no expiration_days must survive every sweep. */
	public function test_an_enrollment_with_no_expiration_is_never_swept() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-01-01 00:00:00 UTC' ) );
		$user   = $this->make_learner();
		$course = $this->make_course();
		$this->service->enroll( $user, $course );
		$this->assertNull( $this->service->get( $user, $course )->expires_at );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2036-01-01 00:00:00 UTC' ) );

		$this->assertSame( 0, $this->service->sweep_expired() );
		$this->assertSame( 'enrolled', $this->service->get( $user, $course )->status );
	}
}
