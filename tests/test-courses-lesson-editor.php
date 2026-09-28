<?php
/**
 * Anchor Courses - lesson settings (brief section 9, design spec 3.3).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\LessonEditor;
use Anchor\Courses\Content\LessonPostType;

/** @group courses */
class Test_Courses_Lesson_Editor extends Anchor_Courses_TestCase {

	private function submit( int $lesson_id, array $fields ): void {
		$_POST = [
			LessonEditor::NONCE => wp_create_nonce( LessonEditor::NONCE ),
			'anchor_lesson'     => $fields,
		];
		( new LessonEditor() )->save( $lesson_id );
		$_POST = [];
	}

	/** A real `event` post - Task 36's validation requires event_id to name one. */
	private function make_event( array $meta = [], string $title = 'Test Event' ): int {
		$event_id = (int) self::factory()->post->create( [ 'post_type' => 'event', 'post_status' => 'publish', 'post_title' => $title ] );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $event_id, '_anchor_event_' . $key, $value );
		}
		return $event_id;
	}

	public function test_defaults() {
		$d = LessonEditor::defaults();
		$this->assertSame( 'manual', $d['completion_mode'] );
		$this->assertSame( 'content', $d['type'] );
		$this->assertArrayNotHasKey( 'required', $d, 'Requiredness lives on the curriculum item, not a lesson setting.' );
	}

	public function test_save_persists_completion_mode_and_quiz_link() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();
		$quiz   = $this->make_quiz();

		$this->submit( $lesson, [ 'completion_mode' => 'quiz_pass', 'quiz_id' => (string) $quiz ] );

		$this->assertSame( 'quiz_pass', get_post_meta( $lesson, '_anchor_lesson_completion_mode', true ) );
		$this->assertSame( $quiz, (int) get_post_meta( $lesson, '_anchor_lesson_quiz_id', true ) );
	}

	/**
	 * Audit finding (d), 2026-09-25: the metabox's own "Required for course
	 * completion" checkbox was removed - it was saved but nothing ever read
	 * it. A save must no longer write `_anchor_lesson_required` at all.
	 */
	public function test_required_is_no_longer_a_writable_setting() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();

		$this->submit( $lesson, [ 'completion_mode' => 'manual', 'required' => '1' ] );

		$this->assertSame( '', get_post_meta( $lesson, '_anchor_lesson_required', true ) );
	}

	/** The removed checkbox must not linger in the rendered metabox either. */
	public function test_render_no_longer_shows_the_required_checkbox() {
		$lesson = $this->make_lesson();
		$html   = $this->render_html( $lesson );

		$this->assertStringNotContainsString( 'anchor_lesson[required]', $html );
		$this->assertStringContainsString( "Curriculum builder", $html, 'A hint must point authors at where requiredness actually lives.' );
	}

	public function test_quiz_id_must_reference_a_real_quiz() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson     = $this->make_lesson();
		$not_a_quiz = $this->make_lesson();

		$this->submit( $lesson, [ 'quiz_id' => (string) $not_a_quiz ] );

		$this->assertSame( 0, (int) get_post_meta( $lesson, '_anchor_lesson_quiz_id', true ) );
	}

	public function test_unknown_completion_mode_falls_back_to_manual() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();
		$this->submit( $lesson, [ 'completion_mode' => 'video_percentage' ] );
		$this->assertSame( 'manual', get_post_meta( $lesson, '_anchor_lesson_completion_mode', true ) );
	}

	/**
	 * `event_id` must round-trip only for a REAL event with a session index
	 * that is actually in range for it (Task 36 validation). A plain single
	 * event always resolves to exactly one implicit session
	 * (`Module::resolved_sessions()`), so index 0 is the only valid choice
	 * here.
	 */
	public function test_live_session_fields_persist() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();
		$event  = $this->make_event();

		$this->submit( $lesson, [ 'type' => 'live_session', 'event_id' => (string) $event, 'session_index' => '0', 'require_prior_items' => '1' ] );

		$this->assertSame( 'live_session', get_post_meta( $lesson, '_anchor_lesson_type', true ) );
		$this->assertSame( $event, (int) get_post_meta( $lesson, '_anchor_lesson_event_id', true ) );
		$this->assertSame( 0, (int) get_post_meta( $lesson, '_anchor_lesson_session_index', true ) );
		$this->assertSame( 1, (int) get_post_meta( $lesson, '_anchor_lesson_require_prior_items', true ) );
	}

	/* ---------------------------------------------------------------------
	 * Task 36: the event picker and session index are active, and validated
	 * against the real events module (progress.md - deviation D6 is FALSE
	 * on this base; Module::resolved_sessions() etc. all exist).
	 * ------------------------------------------------------------------- */

	public function test_save_rejects_an_event_id_that_names_no_real_post() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();

		$this->submit( $lesson, [ 'event_id' => '999999', 'session_index' => '0' ] );

		$this->assertSame( 0, (int) get_post_meta( $lesson, '_anchor_lesson_event_id', true ) );
	}

	public function test_save_rejects_an_event_id_that_is_not_an_event_post() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson    = $this->make_lesson();
		$not_event = $this->make_quiz();

		$this->submit( $lesson, [ 'event_id' => (string) $not_event, 'session_index' => '0' ] );

		$this->assertSame( 0, (int) get_post_meta( $lesson, '_anchor_lesson_event_id', true ) );
	}

	/** A plain (non-multisession) event resolves to exactly one implicit session: index 0. */
	public function test_save_rejects_an_out_of_range_session_index_for_a_single_event() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();
		$event  = $this->make_event();

		$this->submit( $lesson, [ 'event_id' => (string) $event, 'session_index' => '3' ] );

		$this->assertSame( $event, (int) get_post_meta( $lesson, '_anchor_lesson_event_id', true ) );
		$this->assertSame( 0, (int) get_post_meta( $lesson, '_anchor_lesson_session_index', true ), 'Out of range must reset to 0, not store the posted value.' );
	}

	public function test_save_keeps_an_in_range_session_index_for_a_multisession_event() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();
		$event  = $this->make_event(
			[
				'type'     => 'multisession',
				'sessions' => [
					[ 'date' => '2026-10-01', 'start_time' => '09:00', 'end_time' => '12:00', 'label' => 'Day 1' ],
					[ 'date' => '2026-10-02', 'start_time' => '09:00', 'end_time' => '12:00', 'label' => 'Day 2' ],
				],
			]
		);

		$this->submit( $lesson, [ 'event_id' => (string) $event, 'session_index' => '1' ] );

		$this->assertSame( 1, (int) get_post_meta( $lesson, '_anchor_lesson_session_index', true ) );
	}

	public function test_save_discards_the_session_index_when_no_event_is_picked() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson( [ 'event_id' => 0 ] );

		$this->submit( $lesson, [ 'event_id' => '0', 'session_index' => '5' ] );

		$this->assertSame( 0, (int) get_post_meta( $lesson, '_anchor_lesson_session_index', true ) );
	}

	/**
	 * When the events module cannot resolve the event's real session count
	 * (it is inactive right now), Events::sessions() degrades to [] and the
	 * posted session_index is kept rather than guessed at - a value
	 * authored while the module was active must never be silently
	 * clobbered by a request where it just happens to be off.
	 */
	public function test_save_keeps_the_session_index_when_the_events_module_cannot_verify_it() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();
		$event  = $this->make_event();

		$prop = new ReflectionProperty( \Anchor\Events\Module::class, 'instance' );
		$prop->setAccessible( true );
		$real = $prop->getValue();
		$prop->setValue( null, null );

		try {
			$this->submit( $lesson, [ 'event_id' => (string) $event, 'session_index' => '7' ] );
		} finally {
			$prop->setValue( null, $real );
		}

		$this->assertSame( $event, (int) get_post_meta( $lesson, '_anchor_lesson_event_id', true ), 'event_exists() does not require the module to be booted.' );
		$this->assertSame( 7, (int) get_post_meta( $lesson, '_anchor_lesson_session_index', true ) );
	}

	public function test_save_requires_the_lesson_capability() {
		$lesson = $this->make_lesson( [ 'completion_mode' => 'view' ] );
		wp_set_current_user( $this->make_learner() );
		$this->submit( $lesson, [ 'completion_mode' => 'manual' ] );
		$this->assertSame( 'view', get_post_meta( $lesson, '_anchor_lesson_completion_mode', true ) );
	}

	/* ---------------------------------------------------------------------
	 * Hook wiring: the save() handler must actually be attached to
	 * save_post_anchor_lesson, and driving it through the real save_post
	 * hook (not calling save() directly) must persist. Mirrors
	 * tests/test-courses-curriculum-builder.php's hook-wiring coverage for
	 * CourseEditor.
	 * ------------------------------------------------------------------- */

	public function test_save_bails_without_a_valid_nonce() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson( [ 'completion_mode' => 'view' ] );

		$_POST = [ 'anchor_lesson' => [ 'completion_mode' => 'manual' ] ];
		( new LessonEditor() )->save( $lesson );
		$_POST = [];

		$this->assertSame( 'view', get_post_meta( $lesson, '_anchor_lesson_completion_mode', true ) );
	}

	public function test_save_is_wired_to_the_save_post_lesson_hook() {
		$editor = new LessonEditor();

		$this->assertNotFalse(
			has_action( 'save_post_' . LessonPostType::CPT, [ $editor, 'save' ] ),
			'LessonEditor::save() must be wired to save_post_anchor_lesson.'
		);
	}

	public function test_save_runs_through_the_real_save_post_hook() {
		new LessonEditor();
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();

		$_POST = [
			LessonEditor::NONCE => wp_create_nonce( LessonEditor::NONCE ),
			'anchor_lesson'     => [ 'completion_mode' => 'view' ],
		];
		do_action( 'save_post_' . LessonPostType::CPT, $lesson, get_post( $lesson ), true );
		$_POST = [];

		$this->assertSame( 'view', get_post_meta( $lesson, '_anchor_lesson_completion_mode', true ) );
	}

	/* ---------------------------------------------------------------------
	 * Revision guard: WordPress's own update_post_meta() already redirects a
	 * revision's post id to its PARENT, so without a wp_is_post_revision()
	 * bail, saving against a revision id silently overwrites the real
	 * lesson's meta instead. Mirrors CourseEditor's guard (progress.md
	 * ruling: the guard is now the house pattern for every save handler).
	 * ------------------------------------------------------------------- */

	public function test_saving_a_revision_id_writes_no_settings() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson( [ 'completion_mode' => 'view' ] );

		$revision_id = wp_save_post_revision( $lesson );
		$this->assertIsInt( $revision_id, 'wp_save_post_revision() must return a revision id for this test to be meaningful.' );
		$this->assertNotSame( 0, $revision_id );

		$this->submit( $revision_id, [ 'completion_mode' => 'manual' ] );

		$this->assertSame( 'view', get_post_meta( $lesson, '_anchor_lesson_completion_mode', true ), 'A revision id must never overwrite the real lesson\'s meta.' );
		$this->assertSame( '', get_post_meta( $revision_id, '_anchor_lesson_completion_mode', true ), 'Nothing may be written against the revision id itself either.' );
	}

	/* -----------------------------------------------------------------
	 * Task 36: the event picker and session index are ACTIVE - the audit's
	 * blanket "not active" notice is gone for those two. Task 37 activates
	 * the third: the pre-work-veto checkbox (require_prior_items) is now a
	 * normal, enabled checkbox with no narrowing notice, because
	 * Integrations\Events::veto_stream_access() reads it for real.
	 * --------------------------------------------------------------- */

	private function render_html( int $lesson_id ): string {
		ob_start();
		( new LessonEditor() )->render( get_post( $lesson_id ) );
		return (string) ob_get_clean();
	}

	private function dom( string $html ): DOMDocument {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		return $doc;
	}

	public function test_the_old_blanket_not_active_notice_is_gone() {
		$lesson = $this->make_lesson();
		$html   = $this->render_html( $lesson );

		$this->assertStringNotContainsString( 'Not active until the live-session adapter ships', $html );
	}

	public function test_the_event_picker_and_session_index_are_enabled() {
		$event  = $this->make_event();
		$lesson = $this->make_lesson( [ 'event_id' => $event, 'session_index' => 0 ] );
		$doc    = $this->dom( $this->render_html( $lesson ) );

		$selects = $doc->getElementsByTagName( 'select' );
		$found   = false;
		foreach ( $selects as $select ) {
			if ( 'anchor_lesson[event_id]' !== $select->getAttribute( 'name' ) ) {
				continue;
			}
			$found = true;
			$this->assertFalse( $select->hasAttribute( 'disabled' ), 'The event picker must not be disabled.' );
		}
		$this->assertTrue( $found, 'The event picker <select> must be rendered.' );

		foreach ( $doc->getElementsByTagName( 'input' ) as $input ) {
			if ( 'anchor_lesson[session_index]' === $input->getAttribute( 'name' ) ) {
				$this->assertFalse( $input->hasAttribute( 'disabled' ), 'Session index must not be disabled.' );
			}
		}
	}

	public function test_the_event_picker_lists_published_events_and_selects_the_stored_one() {
		$event  = $this->make_event( [], 'The Real Event' );
		$other  = $this->make_event( [], 'Some Other Event' );
		$lesson = $this->make_lesson( [ 'event_id' => $event ] );
		$doc    = $this->dom( $this->render_html( $lesson ) );

		$selected = null;
		foreach ( $doc->getElementsByTagName( 'option' ) as $option ) {
			if ( $option->hasAttribute( 'selected' ) ) {
				$selected = $option;
			}
		}
		$this->assertNotNull( $selected );
		$this->assertSame( (string) $event, $selected->getAttribute( 'value' ) );
	}

	public function test_the_pre_work_veto_checkbox_is_active() {
		$lesson = $this->make_lesson( [ 'require_prior_items' => 1 ] );
		$html   = $this->render_html( $lesson );

		$this->assertStringNotContainsString( 'not active until the pre-work veto ships', $html );

		$doc   = $this->dom( $html );
		$found = false;
		foreach ( $doc->getElementsByTagName( 'input' ) as $input ) {
			if ( 'checkbox' === $input->getAttribute( 'type' ) && 'anchor_lesson[require_prior_items]' === $input->getAttribute( 'name' ) ) {
				$found = true;
				$this->assertFalse( $input->hasAttribute( 'disabled' ), 'The pre-work veto checkbox must not be disabled (Task 37).' );
				$this->assertTrue( $input->hasAttribute( 'checked' ) );
			}
		}
		$this->assertTrue( $found, 'The require_prior_items checkbox must be rendered.' );
	}

	/**
	 * Submitting the editor as rendered round-trips all three live-session
	 * fields, including the now-active pre-work-veto checkbox (Task 37 - a
	 * real, checked/unchecked <input type="checkbox"> is posted like any
	 * other field, no hidden mirror needed any more).
	 */
	public function test_saving_the_rendered_form_round_trips_all_three_live_session_fields() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$event  = $this->make_event(
			[
				'type'     => 'multisession',
				'sessions' => [
					[ 'date' => '2026-10-01', 'start_time' => '09:00', 'end_time' => '12:00', 'label' => 'Day 1' ],
					[ 'date' => '2026-10-02', 'start_time' => '09:00', 'end_time' => '12:00', 'label' => 'Day 2' ],
				],
			]
		);
		$lesson = $this->make_lesson( [ 'event_id' => $event, 'session_index' => 1, 'require_prior_items' => 1 ] );

		$doc    = $this->dom( $this->render_html( $lesson ) );
		$posted = [];

		foreach ( $doc->getElementsByTagName( 'select' ) as $select ) {
			if ( 'anchor_lesson[event_id]' !== $select->getAttribute( 'name' ) ) {
				continue;
			}
			foreach ( $select->getElementsByTagName( 'option' ) as $option ) {
				if ( $option->hasAttribute( 'selected' ) ) {
					$posted['event_id'] = $option->getAttribute( 'value' );
				}
			}
		}

		foreach ( $doc->getElementsByTagName( 'input' ) as $input ) {
			$name = $input->getAttribute( 'name' );
			if ( ! preg_match( '/^anchor_lesson\[(\w+)\]$/', $name, $m ) ) {
				continue;
			}
			if ( $input->hasAttribute( 'disabled' ) ) {
				continue; // Never posted by a real browser.
			}
			if ( 'checkbox' === $input->getAttribute( 'type' ) && ! $input->hasAttribute( 'checked' ) ) {
				continue;
			}
			$posted[ $m[1] ] = $input->getAttribute( 'value' );
		}

		$this->submit( $lesson, $posted + [ 'completion_mode' => 'manual' ] );

		$this->assertSame( $event, (int) get_post_meta( $lesson, '_anchor_lesson_event_id', true ) );
		$this->assertSame( 1, (int) get_post_meta( $lesson, '_anchor_lesson_session_index', true ) );
		// require_prior_items is a real, enabled checkbox now (Task 37): the
		// rendered "checked" state is what actually posts and round-trips.
		$this->assertSame( 1, (int) get_post_meta( $lesson, '_anchor_lesson_require_prior_items', true ) );
	}
}
