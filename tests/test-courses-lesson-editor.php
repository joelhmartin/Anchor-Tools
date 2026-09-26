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

	public function test_live_session_fields_persist() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();

		$this->submit( $lesson, [ 'type' => 'live_session', 'event_id' => '42', 'session_index' => '1', 'require_prior_items' => '1' ] );

		$this->assertSame( 'live_session', get_post_meta( $lesson, '_anchor_lesson_type', true ) );
		$this->assertSame( 42, (int) get_post_meta( $lesson, '_anchor_lesson_event_id', true ) );
		$this->assertSame( 1, (int) get_post_meta( $lesson, '_anchor_lesson_session_index', true ) );
		$this->assertSame( 1, (int) get_post_meta( $lesson, '_anchor_lesson_require_prior_items', true ) );
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
	 * Audit UI item: the live-session controls are not wired yet (plan
	 * Phase 5), so the editor shows them disabled with a notice instead of
	 * implying protection that does not exist - and still keeps whatever
	 * is stored.
	 * --------------------------------------------------------------- */

	private function render_html( int $lesson_id ): string {
		ob_start();
		( new LessonEditor() )->render( get_post( $lesson_id ) );
		return (string) ob_get_clean();
	}

	/** @return array<string,DOMElement> name => the visible (non-hidden) input */
	private function visible_live_inputs( string $html ): array {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		$out = [];
		foreach ( $doc->getElementsByTagName( 'input' ) as $input ) {
			$name = $input->getAttribute( 'name' );
			if ( 'hidden' !== $input->getAttribute( 'type' ) && preg_match( '/^anchor_lesson\[(event_id|session_index|require_prior_items)\]$/', $name, $m ) ) {
				$out[ $m[1] ] = $input;
			}
		}
		return $out;
	}

	public function test_live_session_controls_are_disabled_with_a_notice() {
		$lesson = $this->make_lesson( [ 'event_id' => 42, 'session_index' => 1, 'require_prior_items' => 1 ] );
		$html   = $this->render_html( $lesson );

		$this->assertStringContainsString( 'Not active until the live-session adapter ships (plan Phase 5)', $html );
		$inputs = $this->visible_live_inputs( $html );
		$this->assertSame( [ 'event_id', 'session_index', 'require_prior_items' ], array_keys( $inputs ) );
		foreach ( $inputs as $key => $input ) {
			$this->assertTrue( $input->hasAttribute( 'disabled' ), $key . ' must be disabled.' );
		}
		$this->assertStringContainsString( 'Block stream access until earlier items are complete', $html, 'The setting is still shown, not hidden.' );
	}

	/** Saving the editor as rendered (disabled inputs are never posted) keeps the stored values. */
	public function test_saving_the_rendered_form_keeps_the_stored_live_session_settings() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson( [ 'event_id' => 42, 'session_index' => 1, 'require_prior_items' => 1 ] );

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $this->render_html( $lesson ) . '</body></html>' );
		libxml_clear_errors();
		$posted = [];
		foreach ( $doc->getElementsByTagName( 'input' ) as $input ) {
			if ( $input->hasAttribute( 'disabled' ) || ! preg_match( '/^anchor_lesson\[(\w+)\]$/', $input->getAttribute( 'name' ), $m ) ) {
				continue;
			}
			if ( 'checkbox' === $input->getAttribute( 'type' ) && ! $input->hasAttribute( 'checked' ) ) {
				continue;
			}
			$posted[ $m[1] ] = $input->getAttribute( 'value' );
		}
		$this->submit( $lesson, $posted + [ 'completion_mode' => 'manual' ] );

		$this->assertSame( 42, (int) get_post_meta( $lesson, '_anchor_lesson_event_id', true ) );
		$this->assertSame( 1, (int) get_post_meta( $lesson, '_anchor_lesson_session_index', true ) );
		$this->assertSame( 1, (int) get_post_meta( $lesson, '_anchor_lesson_require_prior_items', true ) );
	}
}
