<?php
/**
 * Anchor Courses - the optional pre-work veto on stream access
 * (design spec 3.2, events spec 4.5).
 *
 * Deviation D6 as written in the original task brief is FALSE on this base
 * (progress.md, binding, 2026-09-28): `anchor_events_can_access_stream`
 * already exists and already fires from `Entitlements::can_access_stream()`
 * - this is the real integration, never a stub.
 *
 * Enrolment fixtures use `Roles::grant_access()`, not
 * `EnrollmentService::enroll()` directly - the latter only opens the
 * enrolment ROW; `is_enrolled()` also requires the `anchor_course_{id}`
 * ROLE (`EnrollmentService::is_enrolled()`), and only the role-change
 * listener `Support\Roles` grants that role (progress.md ruling:
 * "Roles::grant_access is the only enrol path, is_enrolled = role + open
 * row"). A test that calls `enroll()` alone builds a learner
 * `is_enrolled()` never agrees is enrolled.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Integrations\Events;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Stream_Veto extends Anchor_Courses_TestCase {

	private const EVENT_ID = 4242;

	private ProgressService $progress;
	private int $user;
	private int $course;
	private int $prework;
	private int $live;

	public function set_up() {
		parent::set_up();
		$enrollments    = new EnrollmentService();
		$this->progress = new ProgressService( $enrollments );

		$this->user    = $this->make_learner();
		$this->course  = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->prework = $this->make_lesson( [], 'Pre-work' );
		$this->live    = $this->make_lesson(
			[ 'type' => 'live_session', 'event_id' => self::EVENT_ID, 'session_index' => 0, 'require_prior_items' => 1 ],
			'Day 1 Livestream'
		);

		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->prework ],
				[ 'type' => 'lesson', 'id' => $this->live ],
			] ] ]
		);

		Roles::grant_access( $this->user, $this->course );
	}

	public function test_live_lessons_for_event_finds_only_gated_lessons() {
		$ungated = $this->make_lesson( [ 'type' => 'live_session', 'event_id' => self::EVENT_ID, 'require_prior_items' => 0 ], 'Ungated' );
		$course  = $this->make_course();
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $ungated ] ] ] ] );

		$rows = Events::live_lessons_for_event( self::EVENT_ID );

		$this->assertSame( [ $this->live ], array_column( $rows, 'lesson_id' ) );
	}

	public function test_access_is_vetoed_until_the_prework_is_complete() {
		$this->assertFalse(
			Events::veto_stream_access( true, self::EVENT_ID, 0, $this->user ),
			'Unfinished pre-work must block the stream.'
		);

		$this->progress->complete_lesson( $this->user, $this->course, $this->prework );

		$this->assertTrue( Events::veto_stream_access( true, self::EVENT_ID, 0, $this->user ) );
	}

	public function test_the_veto_never_grants_access_that_was_already_refused() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->prework );

		$this->assertFalse(
			Events::veto_stream_access( false, self::EVENT_ID, 0, $this->user ),
			'A false stays false: courses may only subtract access.'
		);
	}

	public function test_an_ungated_live_lesson_never_vetoes() {
		update_post_meta( $this->live, '_anchor_lesson_require_prior_items', 0 );

		$this->assertTrue( Events::veto_stream_access( true, self::EVENT_ID, 0, $this->user ) );
	}

	public function test_an_event_with_no_live_lessons_is_untouched() {
		$this->assertTrue( Events::veto_stream_access( true, 999999, 0, $this->user ) );
	}

	public function test_a_learner_who_is_not_enrolled_is_not_vetoed() {
		$stranger = $this->make_learner();

		$this->assertTrue(
			Events::veto_stream_access( true, self::EVENT_ID, 0, $stranger ),
			'Someone who is not on the course has no pre-work to finish.'
		);
	}

	public function test_the_session_index_is_respected() {
		update_post_meta( $this->live, '_anchor_lesson_session_index', 1 );

		$this->assertTrue( Events::veto_stream_access( true, self::EVENT_ID, 0, $this->user ), 'Session 0 has no gated lesson.' );
		$this->assertFalse( Events::veto_stream_access( true, self::EVENT_ID, 1, $this->user ) );
	}

	public function test_the_filter_is_registered_so_events_can_call_it() {
		$this->assertNotFalse(
			has_filter( 'anchor_events_can_access_stream' ),
			'The veto must be attached even before the events module fires it.'
		);
	}

	public function test_a_zero_user_id_is_treated_as_the_current_user() {
		wp_set_current_user( $this->user );
		$this->assertFalse( Events::veto_stream_access( true, self::EVENT_ID, 0, 0 ) );

		$this->progress->complete_lesson( $this->user, $this->course, $this->prework );
		$this->assertTrue( Events::veto_stream_access( true, self::EVENT_ID, 0, 0 ) );
	}

	/**
	 * Staff - anyone who can edit the live_session lesson post itself - are
	 * never vetoed by their own unfinished "pre-work" (progress.md ruling).
	 * An administrator holds `edit_anchor_lessons` (Capabilities::sync()),
	 * which LessonPostType's own `map_meta_cap` resolves for any lesson.
	 */
	public function test_staff_who_can_edit_the_lesson_are_not_vetoed() {
		$staff = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->assertTrue( user_can( $staff, 'edit_post', $this->live ), 'Fixture check: an administrator must be able to edit the lesson.' );

		$this->assertTrue( Events::veto_stream_access( true, self::EVENT_ID, 0, $staff ) );
	}

	/**
	 * The same lesson shared by a second published course: the veto denies
	 * as soon as ANY applicable course blocks, even though the learner has
	 * finished the pre-work in the first course (progress.md ruling -
	 * `live_lessons_for_event()` returns one row per (lesson, course)).
	 */
	public function test_it_denies_when_any_applicable_course_blocks() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->prework );
		$this->assertTrue( Events::veto_stream_access( true, self::EVENT_ID, 0, $this->user ), 'Sanity: the first course is now clear.' );

		$other_prework = $this->make_lesson( [], 'Other course pre-work' );
		$other_course  = $this->make_course( [ 'progression_mode' => 'free' ], 'Other Course' );
		Curriculum::save(
			$other_course,
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => $other_prework ],
				[ 'type' => 'lesson', 'id' => $this->live ],
			] ] ]
		);
		Roles::grant_access( $this->user, $other_course );

		$this->assertFalse(
			Events::veto_stream_access( true, self::EVENT_ID, 0, $this->user ),
			'The second course\'s own pre-work is not done yet.'
		);
	}
}
