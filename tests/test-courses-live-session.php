<?php
/**
 * Anchor Courses - the live_session lesson type (design spec 3.3).
 *
 * Deviation D6 in the original task-36 draft is FALSE on this base
 * (progress.md, binding, 2026-09-28): `Module::room_url()`,
 * `Module::resolved_sessions()`, `Stream_State::for_event()` and
 * `Entitlements::can_access_stream()` all exist, and `events_manager` is
 * enabled in tests/bootstrap.php alongside `courses`. These are real
 * integration tests against the live events module, not a stub pinning a
 * degraded path - the one true "module absent" case is simulated via
 * reflection (nulling `Module::$instance`), since the shared bootstrap
 * always boots both modules together and there is no per-test toggle.
 *
 * Enrolment fixtures use `Roles::grant_access()` - the only enrol path
 * (progress.md ruling; `is_enrolled()` requires BOTH an open row and the
 * access role, and `EnrollmentService::enroll()` alone only writes the row).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Integrations\Events;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Live_Session extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		$this->require_events();

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->lesson = $this->make_lesson(
			[ 'type' => 'live_session', 'event_id' => 0, 'session_index' => 0, 'completion_mode' => 'manual' ],
			'Day 1 Livestream'
		);
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );

		Roles::grant_access( $this->user, $this->course );
		wp_set_current_user( $this->user );

		global $post;
		$post = get_post( $this->lesson );
	}

	/** A real, published `event` post with no stream at all. */
	private function plain_event(): int {
		return (int) self::factory()->post->create( [ 'post_type' => 'event', 'post_status' => 'publish' ] );
	}

	/** A real streamed event (mirrors tests/test-room.php's stream_event() fixture). */
	private function streamed_event(): int {
		$start    = time() + ( 2 * HOUR_IN_SECONDS );
		$event_id = (int) self::factory()->post->create( [ 'post_type' => 'event', 'post_status' => 'publish' ] );

		$meta = [
			'registration_mode'       => 'free',
			'access_role_enabled'     => true,
			'timezone'                => 'UTC',
			'start_date'              => gmdate( 'Y-m-d', $start ),
			'start_time'              => gmdate( 'H:i', $start ),
			'start_ts'                => $start,
			'end_ts'                  => $start + HOUR_IN_SECONDS,
			'stream_default_modality' => 'virtual',
			'stream_embed'            => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/77', 'raw' => '' ],
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $event_id, '_anchor_event_' . $key, $value );
		}

		return $event_id;
	}

	/* ---------------------------------------------------------------------
	 * Integrations\Events - a real reader against the live events module.
	 * ------------------------------------------------------------------- */

	public function test_sessions_returns_an_empty_list_for_an_unknown_event() {
		$this->assertSame( [], Events::sessions( 999999 ) );
	}

	public function test_sessions_returns_an_empty_list_for_event_id_zero() {
		$this->assertSame( [], Events::sessions( 0 ) );
	}

	public function test_sessions_resolves_the_implicit_single_session_for_a_plain_event() {
		$event_id = $this->streamed_event();

		$sessions = Events::sessions( $event_id );

		$this->assertCount( 1, $sessions, 'A single event always resolves to one implicit session (Module::resolved_sessions()).' );
		$this->assertSame( 'virtual', $sessions[0]['modality'] );
		$this->assertGreaterThan( 0, $sessions[0]['start_ts'] );
	}

	public function test_sessions_resolves_multisession_rows() {
		$event_id = $this->plain_event();
		update_post_meta( $event_id, '_anchor_event_type', 'multisession' );
		update_post_meta(
			$event_id,
			'_anchor_event_sessions',
			[
				[ 'date' => '2026-10-01', 'start_time' => '09:00', 'end_time' => '12:00', 'label' => 'Day 1' ],
				[ 'date' => '2026-10-02', 'start_time' => '09:00', 'end_time' => '12:00', 'label' => 'Day 2' ],
			]
		);

		$sessions = Events::sessions( $event_id );

		$this->assertCount( 2, $sessions );
		$this->assertSame( 'Day 1', $sessions[0]['label'] );
		$this->assertSame( 'Day 2', $sessions[1]['label'] );
		$this->assertGreaterThan( 0, $sessions[0]['start_ts'] );
	}

	public function test_event_exists_is_true_only_for_a_real_event_post() {
		$event_id = $this->plain_event();

		$this->assertTrue( Events::event_exists( $event_id ) );
		$this->assertFalse( Events::event_exists( 999999 ) );
		$this->assertFalse( Events::event_exists( $this->lesson ), 'A lesson post is not an event.' );
		$this->assertFalse( Events::event_exists( 0 ) );
	}

	public function test_room_url_is_empty_for_an_event_with_no_stream() {
		$this->assertSame( '', Events::room_url( $this->plain_event() ) );
	}

	public function test_room_url_matches_the_real_events_module_for_a_streamed_event() {
		$event_id = $this->streamed_event();

		$room = Events::room_url( $event_id );

		$this->assertNotSame( '', $room );
		$this->assertSame( \Anchor\Events\Module::instance()->room_url( $event_id ), $room );
	}

	public function test_stream_state_matches_the_real_state_machine() {
		$event_id = $this->streamed_event();

		// A resolved, embedded stream two hours out is 'countdown'
		// (Stream_State::decide() - 'pending' is reserved for a session that
		// resolves no embed at all, not for "far in the future").
		$this->assertSame( \Anchor\Events\Stream_State::COUNTDOWN, Events::stream_state( $event_id )['state'] );
		$this->assertSame(
			\Anchor\Events\Stream_State::for_event( $event_id, Clock::timestamp() )['state'],
			Events::stream_state( $event_id )['state']
		);
	}

	public function test_stream_state_is_unknown_for_a_missing_event() {
		$this->assertSame( 'unknown', Events::stream_state( 0 )['state'] );
		$this->assertSame( 'unknown', Events::stream_state( 999999 )['state'] );
	}

	/* ---------------------------------------------------------------------
	 * Degraded paths: no fatals, ever (brief + TDD spec).
	 * ------------------------------------------------------------------- */

	public function test_everything_degrades_gracefully_when_the_events_module_is_absent() {
		$prop = new ReflectionProperty( \Anchor\Events\Module::class, 'instance' );
		$prop->setAccessible( true );
		$real = $prop->getValue();
		$prop->setValue( null, null );

		try {
			$this->assertFalse( Events::available() );
			$this->assertSame( [], Events::sessions( $this->streamed_event() ) );
			$this->assertSame( '', Events::room_url( 1 ) );
			$this->assertSame( 'unknown', Events::stream_state( 1 )['state'] );

			update_post_meta( $this->lesson, '_anchor_lesson_event_id', 4242 );
			$html = $this->courses()->shortcodes->render_live_session( $this->lesson, $this->course );
			$this->assertStringContainsString( 'unavailable', strtolower( $html ) );
		} finally {
			$prop->setValue( null, $real );
		}
	}

	public function test_the_lesson_renders_the_unavailable_message_when_no_event_is_picked() {
		// event_id is 0 by default (set_up()) - nothing picked yet.
		$html = $this->courses()->shortcodes->render_live_session( $this->lesson, $this->course );

		$this->assertStringContainsString( 'unavailable', strtolower( $html ) );
	}

	/**
	 * PR36 round 3 (Codex): a deleted/non-event id must show the
	 * "unavailable" branch, never the "will appear later" branch - that
	 * wording implies the event still exists and just hasn't started, which
	 * is false for an id `Events::event_exists()` cannot confirm.
	 */
	public function test_the_lesson_renders_gracefully_when_the_picked_event_no_longer_exists() {
		update_post_meta( $this->lesson, '_anchor_lesson_event_id', 999999 );

		$html = $this->courses()->shortcodes->render_live_session( $this->lesson, $this->course );

		$this->assertStringContainsString( 'Live session unavailable.', $html );
		$this->assertStringNotContainsString( 'Join the livestream', $html );
		$this->assertStringNotContainsString(
			'The livestream link will appear here',
			$html,
			'A non-existent event id must never show "will appear later" - that implies the event still exists.'
		);
	}

	/** Same guard, but the event was real and got deleted AFTER the lesson saved it (PR36 round 3, Codex). */
	public function test_the_lesson_shows_unavailable_after_the_saved_event_is_deleted() {
		$event_id = $this->streamed_event();
		update_post_meta( $this->lesson, '_anchor_lesson_event_id', $event_id );

		wp_delete_post( $event_id, true );

		$html = $this->courses()->shortcodes->render_live_session( $this->lesson, $this->course );

		$this->assertStringContainsString( 'Live session unavailable.', $html );
		$this->assertStringNotContainsString( 'Join the livestream', $html );
	}

	/* ---------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------- */

	public function test_the_lesson_renders_a_schedule_and_a_join_button_for_an_enrolled_learner() {
		$event_id = $this->streamed_event();
		update_post_meta( $this->lesson, '_anchor_lesson_event_id', $event_id );

		$html = $this->courses()->shortcodes->render_live_session( $this->lesson, $this->course );

		$this->assertStringContainsString( 'anchor-live-session', $html );
		$this->assertStringContainsString( 'anchor-live-session-schedule', $html );
		$this->assertStringContainsString( 'Join the livestream', $html );
		$this->assertStringNotContainsString( '<iframe', $html, 'Courses never embeds the stream; the room is the only player.' );
	}

	public function test_the_room_link_is_withheld_from_a_learner_who_is_not_enrolled() {
		$event_id = $this->streamed_event();
		update_post_meta( $this->lesson, '_anchor_lesson_event_id', $event_id );

		$stranger = $this->make_learner();
		wp_set_current_user( $stranger );

		$html = $this->courses()->shortcodes->render_live_session( $this->lesson, $this->course );

		$this->assertStringNotContainsString( 'Join the livestream', $html, 'The room link is a course access decision, and this learner is not enrolled.' );
		$this->assertStringContainsString( 'The livestream link will appear here', $html );
		// The schedule itself is not an access secret - it still renders.
		$this->assertStringContainsString( 'anchor-live-session-schedule', $html );
	}

	public function test_a_non_enrolled_visitor_sees_the_course_notice_not_the_lesson_body() {
		wp_update_post( [ 'ID' => $this->lesson, 'post_content' => 'Secret livestream prep notes.', 'post_excerpt' => '' ] );
		$stranger = $this->make_learner();
		wp_set_current_user( $stranger );

		$html = $this->courses()->shortcodes->render_lesson( $this->lesson );

		$this->assertStringContainsString( 'You are not enrolled in this course.', $html );
		$this->assertStringNotContainsString( 'Secret livestream prep notes.', $html );
	}

	public function test_a_live_session_lesson_is_still_markable_complete() {
		$progress = $this->courses()->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$this->assertNotWPError( $progress );
		$this->assertSame( 'completed', $progress->status );
	}

	public function test_render_lesson_routes_a_live_session_lesson_to_the_live_template() {
		$event_id = $this->streamed_event();
		update_post_meta( $this->lesson, '_anchor_lesson_event_id', $event_id );

		$html = $this->courses()->shortcodes->render_lesson( $this->lesson );

		$this->assertStringContainsString( 'anchor-live-session', $html );
	}

	public function test_a_content_lesson_is_unaffected() {
		$plain = $this->make_lesson( [], 'Plain' );
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $plain ] ] ] ]
		);
		global $post;
		$post = get_post( $plain );

		$html = $this->courses()->shortcodes->render_lesson( $plain );

		$this->assertStringNotContainsString( 'anchor-live-session', $html );
		$this->assertStringContainsString( 'anchor-lesson', $html );
	}

	/* ---------------------------------------------------------------------
	 * Phase 5 final review I3 - the Join button respects the pre-work veto.
	 * ------------------------------------------------------------------- */

	public function test_a_learner_the_prework_veto_blocks_sees_the_notice_instead_of_join() {
		$event_id = $this->streamed_event();
		$prework  = $this->make_lesson( [], 'Read this first' );
		update_post_meta( $this->lesson, '_anchor_lesson_event_id', $event_id );
		update_post_meta( $this->lesson, '_anchor_lesson_require_prior_items', 1 );
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => $prework ],
				[ 'type' => 'lesson', 'id' => $this->lesson ],
			] ] ]
		);

		$html = $this->courses()->shortcodes->render_live_session( $this->lesson, $this->course );

		$this->assertStringNotContainsString( 'Join the livestream', $html );
		$this->assertStringContainsString( 'Finish the earlier lessons in', $html );
		$this->assertStringContainsString( str_replace( '&#038;', '&amp;', esc_url( \Anchor\Courses\Frontend\Access::lesson_url( $prework, $this->course ) ) ), $html ); // wp_kses_post() normalises the entity.

		$this->courses()->progress->complete_lesson( $this->user, $this->course, $prework );
		Events::flush();

		$html = $this->courses()->shortcodes->render_live_session( $this->lesson, $this->course );
		$this->assertStringContainsString( 'Join the livestream', $html, 'Pre-work done: Join comes back.' );
		$this->assertStringNotContainsString( 'Finish the earlier lessons in', $html );
	}
}
