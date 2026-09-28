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

use Anchor\Courses\Admin\LessonEditor;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Integrations\Events;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Stream_Veto extends Anchor_Courses_TestCase {

	// Events::make_event()/make_seat()/module()/ticket_types() - the events
	// suite's own fixture idiom (tests/class-anchor-events-testcase.php),
	// never raw `update_post_meta()` from inside a test. Fix round 1 (task
	// 37): everything above this point in the file talks to
	// Events::veto_stream_access() directly against a FAKE event id
	// (EVENT_ID below is never a real `event` post); the tests below instead
	// go through a REAL event post and the REAL dispatch,
	// Entitlements::can_access_stream() -> apply_filters(
	// 'anchor_events_can_access_stream' ) -> Events::veto_stream_access() -
	// proving the listener this class's constructor attaches is the thing
	// Entitlements actually consults, not just that the callback works in
	// isolation.
	use Anchor_Events_Fixtures;

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

	/* ---------------------------------------------------------------------
	 * Fix round 1 (task 37) - the real dispatch, end to end.
	 * ------------------------------------------------------------------- */

	/**
	 * A real, streamable `event` post with a confirmed virtual seat -
	 * `make_event()`/`ticket_types()->save()`, the exact fixture shape
	 * `Test_Entitlements::stream_event()` (tests/test-entitlements.php) and
	 * `Test_Room::stream_event()` (tests/test-room.php) both use for their
	 * own `can_access_stream()` coverage.
	 *
	 * @return array{0:int,1:string} [event_id, virtual tier id]
	 */
	private function real_stream_event(): array {
		$start    = time() + HOUR_IN_SECONDS;
		$event_id = $this->make_event(
			[
				'title'                    => 'Real Stream Event',
				'registration_mode'        => 'free',
				'access_role_enabled'      => true,
				'timezone'                 => 'UTC',
				'start_date'               => gmdate( 'Y-m-d', $start ),
				'start_time'               => gmdate( 'H:i', $start ),
				'start_ts'                 => $start,
				'end_ts'                   => $start + 3600,
				'stream_default_modality'  => 'hybrid',
				'stream_embed'             => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/37501', 'raw' => '' ],
			]
		);
		$tiers = $this->ticket_types()->save(
			$event_id,
			[
				[ 'label' => 'In person', 'price' => '0', 'active' => 1, 'modality' => 'in_person' ],
				[ 'label' => 'Livestream', 'price' => '0', 'active' => 1, 'modality' => 'virtual' ],
			]
		);
		return [ $event_id, $tiers[1]['id'] ];
	}

	/**
	 * Builds a course whose curriculum gates a real event's session 0 on a
	 * pre-work lesson, exactly like `set_up()` above but against the real
	 * event id the caller built with `real_stream_event()` rather than the
	 * fake `EVENT_ID` constant.
	 *
	 * @return array{0:int,1:int} [course_id, prework_lesson_id]
	 */
	private function real_gated_course( int $event_id, string $suffix ): array {
		$course  = $this->make_course( [ 'progression_mode' => 'free' ], "Real Course $suffix" );
		$prework = $this->make_lesson( [], "Real Pre-work $suffix" );
		$live    = $this->make_lesson(
			[ 'type' => 'live_session', 'event_id' => $event_id, 'session_index' => 0, 'require_prior_items' => 1 ],
			"Real Day 1 Livestream $suffix"
		);
		Curriculum::save(
			$course,
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => $prework ],
				[ 'type' => 'lesson', 'id' => $live ],
			] ] ]
		);
		return [ $course, $prework ];
	}

	/**
	 * The real dispatch: `Entitlements::can_access_stream()` ->
	 * `apply_filters( 'anchor_events_can_access_stream' )` ->
	 * `Events::veto_stream_access()`. Not `Events::veto_stream_access()`
	 * called directly, as every test above this section does - this proves
	 * the listener `Events::__construct()` attaches is the thing
	 * `Entitlements` actually consults for a learner who holds a real,
	 * confirmed seat on a real event.
	 */
	public function test_the_real_entitlements_dispatch_denies_then_grants_as_the_prework_completes() {
		$this->require_events();

		[ $event_id, $virtual_tier ] = $this->real_stream_event();

		$learner = $this->make_learner( [ 'user_email' => 'e2e-veto@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'e2e-veto@example.test', 'ticket_type_id' => $virtual_tier ] );

		[ $course, $prework ] = $this->real_gated_course( $event_id, 'A' );
		Roles::grant_access( $learner, $course );

		$entitlements = $this->module()->entitlements;

		$this->assertFalse(
			$entitlements->can_access_stream( $event_id, 0, $learner ),
			'The real Entitlements::can_access_stream() dispatch must be vetoed while the pre-work is unfinished.'
		);

		$this->progress->complete_lesson( $learner, $course, $prework );

		$this->assertTrue(
			$entitlements->can_access_stream( $event_id, 0, $learner ),
			'Once the pre-work is complete the real dispatch must grant access again.'
		);
	}

	/**
	 * Proves the LISTENER - not some other branch of `can_access_stream()`
	 * - is what denies: detach it and the identical call, against the same
	 * unfinished pre-work, flips to true.
	 */
	public function test_removing_the_veto_listener_restores_access_while_the_prework_is_unfinished() {
		$this->require_events();

		[ $event_id, $virtual_tier ] = $this->real_stream_event();

		$learner = $this->make_learner( [ 'user_email' => 'e2e-veto-2@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'e2e-veto-2@example.test', 'ticket_type_id' => $virtual_tier ] );

		[ $course ] = $this->real_gated_course( $event_id, 'B' );
		Roles::grant_access( $learner, $course );

		$entitlements = $this->module()->entitlements;

		$this->assertFalse(
			$entitlements->can_access_stream( $event_id, 0, $learner ),
			'Sanity: the listener is attached and denies as usual.'
		);

		remove_filter( 'anchor_events_can_access_stream', [ Events::class, 'veto_stream_access' ], 10 );
		try {
			$this->assertTrue(
				$entitlements->can_access_stream( $event_id, 0, $learner ),
				'With the listener detached, unfinished pre-work must no longer block the stream - the listener, not the events module itself, is what was denying it.'
			);
		} finally {
			add_filter( 'anchor_events_can_access_stream', [ Events::class, 'veto_stream_access' ], 10, 4 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Fix round 1 (task 37) - live_lessons_for_event()'s per-request memo.
	 * ------------------------------------------------------------------- */

	/** Submits the lesson settings metabox exactly as Test_Courses_Lesson_Editor does. */
	private function submit_lesson_settings( int $lesson_id, array $fields ): void {
		$_POST = [
			LessonEditor::NONCE => wp_create_nonce( LessonEditor::NONCE ),
			'anchor_lesson'     => $fields,
		];
		( new LessonEditor() )->save( $lesson_id );
		$_POST = [];
	}

	/**
	 * A second call in the same request must not touch the database again:
	 * `live_lessons_for_event()` runs `get_posts()` plus a
	 * `Curriculum::courses_for_item()` scan per lesson found, and nothing
	 * about the answer can change without a save this same request makes.
	 */
	public function test_live_lessons_for_event_is_memoised_for_the_rest_of_the_request() {
		global $wpdb;

		Events::live_lessons_for_event( self::EVENT_ID ); // Prime it.

		$before = $wpdb->num_queries;
		$second = Events::live_lessons_for_event( self::EVENT_ID );
		$delta  = $wpdb->num_queries - $before;

		$this->assertSame( 0, $delta, 'A repeat call in the same request must issue no new queries.' );
		$this->assertSame( [ $this->live ], array_column( $second, 'lesson_id' ) );
	}

	/**
	 * Saving a lesson through the real editor path (`LessonEditor::save()`,
	 * the same `submit()` idiom `Test_Courses_Lesson_Editor` uses) must drop
	 * the memo - a newly gated `live_session` lesson must be visible to the
	 * very next `live_lessons_for_event()` call, not held back by a stale
	 * answer cached before the save.
	 *
	 * A REAL event post (`real_stream_event()`), not the fake `EVENT_ID`
	 * constant: `LessonEditor::save()` zeroes out a picked `event_id` that
	 * `Events::event_exists()` cannot confirm, and `EVENT_ID` deliberately
	 * names no real `event` post (see this file's own docblock).
	 */
	public function test_saving_a_lesson_invalidates_the_memo() {
		$this->require_events();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		[ $event_id ] = $this->real_stream_event();

		// live_lessons_for_event() only reports a lesson that is BOTH
		// gated (event_id + require_prior_items) AND a member of a
		// published course's curriculum (Curriculum::courses_for_item()) -
		// both lessons need a curriculum slot from the start, so the only
		// thing changing between the two live_lessons_for_event() calls
		// below is $not_yet_gated's own settings.
		$course        = $this->make_course( [ 'progression_mode' => 'free' ], 'Cache Course' );
		$prework       = $this->make_lesson( [], 'Cache Pre-work' );
		$already_gated = $this->make_lesson(
			[ 'type' => 'live_session', 'event_id' => $event_id, 'session_index' => 0, 'require_prior_items' => 1 ],
			'Already Gated'
		);
		$not_yet_gated = $this->make_lesson( [], 'Not Yet Gated' );

		Curriculum::save(
			$course,
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => $prework ],
				[ 'type' => 'lesson', 'id' => $already_gated ],
				[ 'type' => 'lesson', 'id' => $not_yet_gated ],
			] ] ]
		);

		// Prime the memo with the current state: only $already_gated qualifies.
		$this->assertNotContains( $not_yet_gated, array_column( Events::live_lessons_for_event( $event_id ), 'lesson_id' ) );

		$this->submit_lesson_settings(
			$not_yet_gated,
			[ 'type' => 'live_session', 'event_id' => (string) $event_id, 'session_index' => '0', 'require_prior_items' => '1' ]
		);

		$ids = array_column( Events::live_lessons_for_event( $event_id ), 'lesson_id' );
		$this->assertContains( $already_gated, $ids, 'The originally gated lesson must still be reported.' );
		$this->assertContains( $not_yet_gated, $ids, 'The save must have invalidated the memo, not just added to it.' );
	}
}
