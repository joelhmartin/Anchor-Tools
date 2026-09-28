<?php
/**
 * Anchor Courses - dataLayer events (brief 19).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Integrations\Analytics;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Analytics extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner( [ 'display_name' => 'Ada Lovelace', 'user_email' => 'ada@example.test' ] );
		$this->course = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '2', 'certificate_enabled' => 1 ], 'Laser Safety' );
		$this->lesson = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
		wp_set_current_user( $this->user );
	}

	public function tear_down() {
		Analytics::flush( $this->user );
		remove_all_filters( 'anchor_courses_datalayer_event' );
		remove_all_filters( 'anchor_courses_analytics_enabled' );
		parent::tear_down();
	}

	public function test_every_brief_event_name_is_mapped() {
		$this->assertSame(
			[ 'course_enrolled', 'course_started', 'lesson_started', 'lesson_completed', 'quiz_started',
			  'quiz_completed', 'quiz_passed', 'quiz_failed', 'course_completed', 'ce_credit_awarded',
			  'certificate_generated' ],
			array_values( Analytics::EVENTS )
		);
	}

	public function test_hooks_are_wired_by_the_module_bootstrap() {
		foreach ( array_keys( Analytics::EVENTS ) as $hook ) {
			$this->assertNotFalse( has_action( $hook ), "Analytics did not attach a listener to {$hook}." );
		}
		$this->assertGreaterThan( 0, has_action( 'wp_footer' ), 'Nothing is listening on wp_footer at all.' );
	}

	public function test_enrolling_queues_a_course_enrolled_event() {
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		$queued = Analytics::pending( $this->user );

		$this->assertSame( 'course_enrolled', $queued[0]['event'] );
		$this->assertSame( $this->course, $queued[0]['course_id'] );
	}

	public function test_completing_a_lesson_queues_lesson_and_course_events() {
		$enrollments = new EnrollmentService();
		$progress    = new ProgressService( $enrollments );
		$progress->set_completion_service( $this->courses()->completion );

		// Roles::grant_access(), not EnrollmentService::enroll() directly:
		// "holding the role IS enrolment" (design spec 3.1, house rule) -
		// grant_access() both mints the role AND (reactively, via the
		// add_user_role listener) creates the enrollment row; enroll() alone
		// only does the latter, so is_enrolled() - which checks the role -
		// would still say no and complete_lesson() would fail not_enrolled.
		// Same pattern as tests/test-courses-completion-effects.php.
		Roles::grant_access( $this->user, $this->course );
		$progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$names = array_column( Analytics::pending( $this->user ), 'event' );

		$this->assertContains( 'lesson_completed', $names );
		$this->assertContains( 'course_completed', $names );
		$this->assertContains( 'ce_credit_awarded', $names );
		$this->assertContains( 'certificate_generated', $names );
	}

	/** Brief 19: the hard rule. */
	public function test_no_payload_ever_contains_personal_data() {
		$enrollments = new EnrollmentService();
		$progress    = new ProgressService( $enrollments );
		$progress->set_completion_service( $this->courses()->completion );
		Roles::grant_access( $this->user, $this->course );
		$progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$json = (string) wp_json_encode( Analytics::pending( $this->user ) );

		$this->assertStringNotContainsString( 'Ada Lovelace', $json );
		$this->assertStringNotContainsString( 'ada@example.test', $json );
		$this->assertStringNotContainsString( 'Laser Safety', $json );
		$this->assertStringNotContainsString( 'user_id', $json );

		foreach ( Analytics::pending( $this->user ) as $payload ) {
			foreach ( array_keys( $payload ) as $key ) {
				$this->assertContains(
					$key,
					[ 'event', 'course_id', 'lesson_id', 'quiz_id', 'attempt_id', 'score', 'credits', 'certificate_id' ],
					"Unexpected dataLayer key {$key}"
				);
			}
		}
	}

	public function test_flush_empties_the_queue() {
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		$this->assertCount( 1, Analytics::flush( $this->user ) );
		$this->assertSame( [], Analytics::pending( $this->user ) );
	}

	public function test_the_footer_prints_one_script_with_the_pushes() {
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		ob_start();
		( new Analytics() )->print_events();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'window.dataLayer', $html );
		$this->assertStringContainsString( '"course_enrolled"', $html );
		$this->assertSame( 1, substr_count( $html, '<script' ) );
	}

	/** Every push must be one syntactically valid JSON object, not just a substring match. */
	public function test_the_printed_pushes_are_each_valid_json() {
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		ob_start();
		( new Analytics() )->print_events();
		$html = (string) ob_get_clean();

		preg_match_all( '/window\.dataLayer\.push\((\{.*?\})\);/', $html, $matches );
		$this->assertNotEmpty( $matches[1], 'No dataLayer.push(...) calls found in the printed script.' );

		foreach ( $matches[1] as $json ) {
			$decoded = json_decode( $json, true );
			$this->assertSame( JSON_ERROR_NONE, json_last_error(), "Invalid JSON in a dataLayer push: {$json}" );
			$this->assertIsArray( $decoded );
			$this->assertArrayHasKey( 'event', $decoded );
		}
	}

	public function test_the_footer_prints_nothing_when_the_queue_is_empty() {
		ob_start();
		( new Analytics() )->print_events();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	public function test_the_filter_can_drop_an_event() {
		add_filter(
			'anchor_courses_datalayer_event',
			static fn( $payload, $event ) => 'course_enrolled' === $event ? null : $payload,
			10,
			3
		);

		( new EnrollmentService() )->enroll( $this->user, $this->course );

		$this->assertSame( [], Analytics::pending( $this->user ) );
	}

	public function test_nothing_is_queued_for_a_logged_out_visitor() {
		wp_set_current_user( 0 );
		( new EnrollmentService() )->enroll( $this->make_learner(), $this->course );

		ob_start();
		( new Analytics() )->print_events();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	/** An admin acting on a learner's behalf must not have the learner's event attributed to the admin's own session. */
	public function test_an_admin_enrolling_a_learner_does_not_queue_it_for_the_admin() {
		$admin   = $this->make_learner( [ 'role' => 'administrator' ] );
		$learner = $this->make_learner();
		wp_set_current_user( $admin );

		( new EnrollmentService() )->enroll( $learner, $this->course );

		$this->assertSame( [], Analytics::pending( $admin ), 'The event leaked onto the acting admin\'s queue.' );
		$this->assertNotSame( [], Analytics::pending( $learner ), 'The event was not attributed to the learner it is actually about.' );

		Analytics::flush( $admin );
		Analytics::flush( $learner );
	}

	public function test_the_queue_is_bounded() {
		for ( $i = 0; $i < 60; $i++ ) {
			Analytics::queue( 'course_enrolled', [ 'course_id' => $i ] );
		}

		$queued = Analytics::pending( $this->user );

		$this->assertLessThanOrEqual( 50, count( $queued ) );
		// The oldest entries are dropped first, so the newest survive.
		$this->assertSame( 59, end( $queued )['course_id'] );
	}

	public function test_the_toggle_off_stops_new_events_from_being_queued() {
		add_filter( 'anchor_courses_analytics_enabled', '__return_false' );

		( new EnrollmentService() )->enroll( $this->user, $this->course );

		$this->assertSame( [], Analytics::pending( $this->user ) );
	}

	public function test_the_toggle_off_stops_the_footer_from_printing_anything() {
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		add_filter( 'anchor_courses_analytics_enabled', '__return_false' );

		ob_start();
		( new Analytics() )->print_events();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	/* ---------------------------------------------------------------------
	 * Phase 5 final review M1 - the allow-list runs after the filter, and on queue().
	 * ------------------------------------------------------------------- */

	public function test_a_filter_cannot_add_pii_to_a_payload() {
		add_filter(
			'anchor_courses_datalayer_event',
			static function ( $payload ) {
				$payload['email']        = 'ada@example.test';
				$payload['display_name'] = 'Ada Lovelace';
				return $payload;
			}
		);

		( new EnrollmentService() )->enroll( $this->user, $this->course );

		$queued = Analytics::pending( $this->user );
		$this->assertSame( 'course_enrolled', $queued[0]['event'] );
		$this->assertArrayNotHasKey( 'email', $queued[0], 'The allow-list must run AFTER the filter.' );
		$this->assertArrayNotHasKey( 'display_name', $queued[0] );
	}

	public function test_queue_applies_the_allow_list_too() {
		Analytics::queue( 'custom_event', [ 'course_id' => 5, 'email' => 'ada@example.test', 'user_id' => $this->user ] );

		$queued = Analytics::pending( $this->user );
		$this->assertSame( [ 'course_id' => 5, 'event' => 'custom_event' ], $queued[0] );
	}
}
