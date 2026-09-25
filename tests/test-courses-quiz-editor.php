<?php
/**
 * Anchor Courses - question editor persistence.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\QuizEditor;
use Anchor\Courses\Content\Questions;

/** @group courses */
class Test_Courses_Quiz_Editor extends Anchor_Courses_TestCase {

	private function tf( string $prompt ): array {
		return [
			'type' => 'true_false', 'prompt' => $prompt, 'points' => 1,
			'answers' => [ [ 'text' => 'True', 'correct' => true ], [ 'text' => 'False', 'correct' => false ] ],
		];
	}

	private function submit( int $quiz_id, $payload ): void {
		$_POST = [
			QuizEditor::NONCE       => wp_create_nonce( QuizEditor::NONCE ),
			'anchor_quiz_questions' => wp_slash( is_string( $payload ) ? $payload : (string) wp_json_encode( $payload ) ),
		];
		( new QuizEditor() )->save_questions( $quiz_id );
		$_POST = [];
	}

	public function test_posted_questions_round_trip() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();

		$this->submit(
			$quiz,
			[
				[ 'type' => 'single_choice', 'prompt' => 'Q1', 'points' => 1,
				  'answers' => [ [ 'text' => 'A', 'correct' => false ], [ 'text' => 'B', 'correct' => true ] ] ],
				array_merge( $this->tf( 'Q2' ), [ 'points' => 2 ] ),
			]
		);

		$stored = Questions::get( $quiz );
		$this->assertCount( 2, $stored );
		$this->assertSame( 'Q1', $stored[0]['prompt'] );
		$this->assertSame( 3.0, Questions::points_possible( $quiz ) );
	}

	public function test_malformed_json_leaves_questions_alone() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();
		Questions::save( $quiz, [ $this->tf( 'Keep' ) ] );

		$this->submit( $quiz, '[[[' );

		$this->assertSame( 'Keep', Questions::get( $quiz )[0]['prompt'] );
	}

	public function test_a_learner_cannot_rewrite_questions() {
		$quiz = $this->make_quiz();
		Questions::save( $quiz, [ $this->tf( 'Keep' ) ] );

		wp_set_current_user( $this->make_learner() );
		$this->submit( $quiz, [ $this->tf( 'Hijack' ) ] );

		$this->assertSame( 'Keep', Questions::get( $quiz )[0]['prompt'] );
	}

	public function test_question_editor_script_depends_on_sortable() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'anchor_quiz' );
		$GLOBALS['post'] = get_post( $this->make_quiz() );
		( new QuizEditor() )->assets( 'post.php' );

		$script = wp_scripts()->registered['anchor-courses-quiz-admin'] ?? null;
		$this->assertNotNull( $script );
		$this->assertContains( 'jquery-ui-sortable', $script->deps );
	}

	/* ---------------------------------------------------------------------
	 * House patterns (progress.md): negative-nonce test, has_action() wiring
	 * assertion at the documented priority (11, one tick after save()), and
	 * one save routed through the real save_post hook. Mirrors
	 * tests/test-courses-quiz-settings.php and
	 * tests/test-courses-curriculum-builder.php.
	 * ------------------------------------------------------------------- */

	public function test_save_questions_bails_without_a_valid_nonce() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();
		Questions::save( $quiz, [ $this->tf( 'Keep' ) ] );

		$_POST = [ 'anchor_quiz_questions' => wp_slash( (string) wp_json_encode( [ $this->tf( 'Hijack' ) ] ) ) ];
		( new QuizEditor() )->save_questions( $quiz );
		$_POST = [];

		$this->assertSame( 'Keep', Questions::get( $quiz )[0]['prompt'] );
	}

	public function test_save_questions_is_wired_at_priority_11() {
		$editor = new QuizEditor();

		$this->assertSame(
			11,
			has_action( 'save_post_' . \Anchor\Courses\Content\QuizPostType::CPT, [ $editor, 'save_questions' ] ),
			'QuizEditor::save_questions() must run one tick after save() (priority 11), same convention as CourseEditor::save_curriculum().'
		);
	}

	/** Fails if the `save_post_anchor_quiz` -> save_questions() wiring is ever removed. */
	public function test_save_questions_runs_through_the_real_save_post_hook() {
		new QuizEditor();
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();

		$_POST = [
			QuizEditor::NONCE       => wp_create_nonce( QuizEditor::NONCE ),
			'anchor_quiz_questions' => wp_slash( (string) wp_json_encode( [ $this->tf( 'Via hook' ) ] ) ),
		];
		do_action( 'save_post_' . \Anchor\Courses\Content\QuizPostType::CPT, $quiz, get_post( $quiz ), true );
		$_POST = [];

		$this->assertSame( 'Via hook', Questions::get( $quiz )[0]['prompt'] );
	}

	public function test_saving_a_revision_id_writes_no_questions() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();
		Questions::save( $quiz, [ $this->tf( 'Keep' ) ] );

		$revision_id = wp_save_post_revision( $quiz );
		$this->assertIsInt( $revision_id, 'wp_save_post_revision() must return a revision id for this test to be meaningful.' );
		$this->assertNotSame( 0, $revision_id );

		$this->submit( $revision_id, [ $this->tf( 'Hijack' ) ] );

		$this->assertSame( 'Keep', Questions::get( $quiz )[0]['prompt'], 'A revision id must never overwrite the real quiz\'s meta.' );
	}
}
