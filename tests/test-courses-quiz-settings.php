<?php
/**
 * Anchor Courses - quiz configuration (brief sections 8.1, 8.5).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\QuizEditor;
use Anchor\Courses\Content\QuizPostType;

/** @group courses */
class Test_Courses_Quiz_Settings extends Anchor_Courses_TestCase {

	private function submit( int $quiz_id, array $fields ): void {
		$_POST = [ QuizEditor::NONCE => wp_create_nonce( QuizEditor::NONCE ), 'anchor_quiz' => $fields ];
		( new QuizEditor() )->save( $quiz_id );
		$_POST = [];
	}

	public function test_defaults_cover_every_brief_setting() {
		$this->assertSame(
			[ 'passing_score', 'max_attempts', 'time_limit_seconds', 'shuffle_questions', 'shuffle_answers',
			  'show_correct_answers', 'show_score', 'allow_review', 'retry_delay_seconds', 'required',
			  'on_timer_expiry' ],
			array_keys( QuizEditor::defaults() )
		);
		$this->assertSame( 'auto_submit', QuizEditor::defaults()['on_timer_expiry'] );
		$this->assertSame( 0, QuizEditor::defaults()['max_attempts'], '0 means unlimited (brief 8.1).' );
	}

	public function test_settings_merge_stored_values_over_defaults() {
		$quiz = $this->make_quiz( [ 'settings' => [ 'passing_score' => 60 ] ] );
		$s    = QuizEditor::settings( $quiz );
		$this->assertSame( 60, $s['passing_score'] );
		$this->assertSame( 1, $s['show_score'] );
	}

	public function test_save_persists_and_clamps() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();

		$this->submit(
			$quiz,
			[ 'passing_score' => '150', 'max_attempts' => '2', 'time_limit_seconds' => '600',
			  'shuffle_questions' => '1', 'retry_delay_seconds' => '3600', 'on_timer_expiry' => 'expire' ]
		);

		$s = QuizEditor::settings( $quiz );
		$this->assertSame( 100, $s['passing_score'], 'passing_score is a percentage, clamped to 1..100.' );
		$this->assertSame( 2, $s['max_attempts'] );
		$this->assertSame( 600, $s['time_limit_seconds'] );
		$this->assertSame( 1, $s['shuffle_questions'] );
		$this->assertSame( 3600, $s['retry_delay_seconds'] );
		$this->assertSame( 'expire', $s['on_timer_expiry'] );
	}

	public function test_unknown_timer_policy_falls_back_to_auto_submit() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();
		$this->submit( $quiz, [ 'on_timer_expiry' => 'explode' ] );
		$this->assertSame( 'auto_submit', QuizEditor::settings( $quiz )['on_timer_expiry'] );
	}

	public function test_unchecked_boxes_store_zero_not_the_default() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();
		$this->submit( $quiz, [ 'passing_score' => '80' ] ); // no checkboxes posted at all
		$s = QuizEditor::settings( $quiz );
		$this->assertSame( 0, $s['show_correct_answers'] );
		$this->assertSame( 0, $s['allow_review'] );
	}

	/**
	 * Blank or garbage input is not "as little as possible" - it is unset, so
	 * it falls back to the authored default, not the absint('') === 0 floor.
	 * An explicit numeric 0 is still honoured where the range allows it.
	 * Mirrors CourseEditor::sanitize_value()'s completion_percentage rule.
	 */
	public function test_blank_or_garbage_integer_fields_fall_back_to_the_default_not_the_floor() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();

		$this->submit( $quiz, [ 'passing_score' => '' ] );
		$this->assertSame( 80, QuizEditor::settings( $quiz )['passing_score'], "Blank passing_score falls back to the default (80)." );

		$this->submit( $quiz, [ 'passing_score' => 'abc' ] );
		$this->assertSame( 80, QuizEditor::settings( $quiz )['passing_score'], "Non-numeric passing_score falls back to the default (80)." );

		$this->submit( $quiz, [ 'passing_score' => '0' ] );
		$this->assertSame( 1, QuizEditor::settings( $quiz )['passing_score'], "An explicit numeric 0 is honoured, then clamped to the 1..100 floor." );

		$this->submit( $quiz, [ 'passing_score' => '150' ] );
		$this->assertSame( 100, QuizEditor::settings( $quiz )['passing_score'], "Out-of-range passing_score clamps to the ceiling." );

		$this->submit( $quiz, [ 'max_attempts' => '' ] );
		$this->assertSame( 0, QuizEditor::settings( $quiz )['max_attempts'], "Blank max_attempts falls back to the default (0 = unlimited)." );

		$this->submit( $quiz, [ 'max_attempts' => '0' ] );
		$this->assertSame( 0, QuizEditor::settings( $quiz )['max_attempts'], "An explicit numeric 0 stays 0 (unlimited is within range)." );

		$this->submit( $quiz, [ 'max_attempts' => '5' ] );
		$this->assertSame( 5, QuizEditor::settings( $quiz )['max_attempts'] );
	}

	public function test_save_requires_the_quiz_capability() {
		$quiz = $this->make_quiz( [ 'settings' => [ 'passing_score' => 70 ] ] );
		wp_set_current_user( $this->make_learner() );
		$this->submit( $quiz, [ 'passing_score' => '1' ] );
		$this->assertSame( 70, QuizEditor::settings( $quiz )['passing_score'] );
	}

	/* ---------------------------------------------------------------------
	 * House patterns (progress.md): negative-nonce test, has_action() wiring
	 * assertion, one save routed through the real save_post hook, and the
	 * wp_is_post_revision() guard test. Mirrors
	 * tests/test-courses-lesson-editor.php.
	 * ------------------------------------------------------------------- */

	public function test_save_bails_without_a_valid_nonce() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz( [ 'settings' => [ 'passing_score' => 70 ] ] );

		$_POST = [ 'anchor_quiz' => [ 'passing_score' => '1' ] ];
		( new QuizEditor() )->save( $quiz );
		$_POST = [];

		$this->assertSame( 70, QuizEditor::settings( $quiz )['passing_score'] );
	}

	public function test_save_is_wired_to_the_save_post_quiz_hook() {
		$editor = new QuizEditor();

		$this->assertNotFalse(
			has_action( 'save_post_' . QuizPostType::CPT, [ $editor, 'save' ] ),
			'QuizEditor::save() must be wired to save_post_anchor_quiz.'
		);
	}

	public function test_save_runs_through_the_real_save_post_hook() {
		new QuizEditor();
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();

		$_POST = [
			QuizEditor::NONCE => wp_create_nonce( QuizEditor::NONCE ),
			'anchor_quiz'     => [ 'passing_score' => '65' ],
		];
		do_action( 'save_post_' . QuizPostType::CPT, $quiz, get_post( $quiz ), true );
		$_POST = [];

		$this->assertSame( 65, QuizEditor::settings( $quiz )['passing_score'] );
	}

	public function test_saving_a_revision_id_writes_no_settings() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz( [ 'settings' => [ 'passing_score' => 70 ] ] );

		$revision_id = wp_save_post_revision( $quiz );
		$this->assertIsInt( $revision_id, 'wp_save_post_revision() must return a revision id for this test to be meaningful.' );
		$this->assertNotSame( 0, $revision_id );

		$this->submit( $revision_id, [ 'passing_score' => '1' ] );

		$this->assertSame( 70, QuizEditor::settings( $quiz )['passing_score'], 'A revision id must never overwrite the real quiz\'s meta.' );
		$this->assertSame( '', get_post_meta( $revision_id, QuizPostType::meta_key( 'settings' ), true ), 'Nothing may be written against the revision id itself either.' );
	}
}
