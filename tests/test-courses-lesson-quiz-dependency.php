<?php
/**
 * Audit F04 (docs/audits/2026-09-25-events-lms-audit.md): a required lesson
 * that completes when its quiz passes, followed by that quiz, must not
 * deadlock under sequential progression.
 *
 * Regression-acceptance list: required lesson -> its quiz -> next lesson is
 * completable, while a quiz linked to a later locked lesson stays
 * inaccessible. Plus the curriculum-save warning for a linked quiz placed
 * before its lesson or absent from the course.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Admin\Notices;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Services\CompletionService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Services\QuizService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Lesson_Quiz_Dependency extends Anchor_Courses_TestCase {

	private ProgressService $progress;
	private QuizService $quizzes;
	private int $user;
	private int $course;
	private string $q1 = '';

	public function set_up() {
		parent::set_up();
		$enrollments    = new EnrollmentService();
		$this->progress = new ProgressService( $enrollments );
		$completion     = new CompletionService( $enrollments, $this->progress );
		$this->progress->set_completion_service( $completion );
		$this->quizzes = new QuizService( $this->progress, $enrollments );

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'sequential' ] );
	}

	public function tear_down() {
		$_POST = [];
		remove_all_filters( 'redirect_post_location' );
		parent::tear_down();
	}

	private function quiz(): int {
		$quiz     = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80 ] ] );
		$saved    = Questions::save( $quiz, [
			[ 'type' => 'true_false', 'prompt' => 'Yes?', 'points' => 1,
			  'answers' => [ [ 'id' => 'true', 'text' => 'True', 'correct' => true ], [ 'id' => 'false', 'text' => 'False', 'correct' => false ] ] ],
		] );
		$this->q1 = $saved[0]['id'];
		return $quiz;
	}

	private function curriculum( array $items ): void {
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => $items ] ] );
		Roles::grant_access( $this->user, $this->course );
	}

	private function pass( int $quiz ): void {
		$attempt = $this->quizzes->start_attempt( $this->user, $quiz, $this->course );
		$this->assertNotWPError( $attempt, 'The quiz could be started.' );
		$graded = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'true' ] );
		$this->assertTrue( $graded->passed );
	}

	/** The audit's reproduction, then the fix: lesson -> its quiz -> next lesson. */
	public function test_a_required_lesson_its_quiz_and_the_next_lesson_are_completable() {
		$quiz   = $this->quiz();
		$lesson = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $quiz ] );
		$next   = $this->make_lesson();
		$this->curriculum( [
			[ 'type' => 'lesson', 'id' => $lesson ],
			[ 'type' => 'quiz', 'id' => $quiz ],
			[ 'type' => 'lesson', 'id' => $next ],
		] );

		$this->assertTrue( $this->progress->is_item_available( $this->user, $this->course, $lesson, 'lesson' ) );
		$this->assertTrue(
			$this->progress->is_item_available( $this->user, $this->course, $quiz, 'quiz' ),
			'The lesson\'s own quiz is part of its completion, so it opens with the lesson.'
		);
		$this->assertSame( 'quiz_required', $this->progress->complete_lesson( $this->user, $this->course, $lesson )->get_error_code() );
		$this->assertFalse( $this->progress->is_item_available( $this->user, $this->course, $next, 'lesson' ) );

		$this->pass( $quiz );

		$this->assertContains( 'lesson:' . $lesson, $this->progress->get_completed_items( $this->user, $this->course ), 'Passing completed the lesson.' );
		$this->assertTrue( $this->progress->is_item_available( $this->user, $this->course, $next, 'lesson' ) );
		$this->assertNotWPError( $this->progress->complete_lesson( $this->user, $this->course, $next ) );
		$this->assertTrue( $this->progress->get_course_progress( $this->user, $this->course )->complete );
	}

	/** Unrelated earlier requirements still gate: a quiz linked to a later, locked lesson stays locked. */
	public function test_a_quiz_linked_to_a_later_locked_lesson_stays_inaccessible() {
		$quiz   = $this->quiz();
		$first  = $this->make_lesson();
		$lesson = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $quiz ] );
		$this->curriculum( [
			[ 'type' => 'lesson', 'id' => $first ],
			[ 'type' => 'lesson', 'id' => $lesson ],
			[ 'type' => 'quiz', 'id' => $quiz ],
		] );

		$this->assertFalse( $this->progress->is_item_available( $this->user, $this->course, $lesson, 'lesson' ) );
		$this->assertFalse( $this->progress->is_item_available( $this->user, $this->course, $quiz, 'quiz' ) );
		$this->assertSame( 'locked', $this->quizzes->start_attempt( $this->user, $quiz, $this->course )->get_error_code() );

		$this->progress->complete_lesson( $this->user, $this->course, $first );
		$this->assertTrue( $this->progress->is_item_available( $this->user, $this->course, $quiz, 'quiz' ) );
	}

	/** A lesson that merely LINKS a quiz but completes by hand is not a parent: it still gates. */
	public function test_a_manual_lesson_with_a_quiz_link_still_gates_the_quiz() {
		$quiz   = $this->quiz();
		$lesson = $this->make_lesson( [ 'completion_mode' => 'manual', 'quiz_id' => $quiz ] );
		$this->curriculum( [
			[ 'type' => 'lesson', 'id' => $lesson ],
			[ 'type' => 'quiz', 'id' => $quiz ],
		] );
		$this->assertFalse( $this->progress->is_item_available( $this->user, $this->course, $quiz, 'quiz' ) );
	}

	/* --- curriculum-save warning -------------------------------------------- */

	public function test_quiz_link_problems_names_misplaced_and_missing_quizzes() {
		$before  = $this->quiz();
		$missing = $this->quiz();
		$good    = $this->quiz();
		$l1      = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $before ] );
		$l2      = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $missing ] );
		$l3      = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $good ] );
		$opt     = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $missing ] );
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [
			[ 'type' => 'quiz', 'id' => $before ],
			[ 'type' => 'lesson', 'id' => $l1 ],
			[ 'type' => 'lesson', 'id' => $l2 ],
			[ 'type' => 'lesson', 'id' => $l3 ],
			[ 'type' => 'quiz', 'id' => $good ],
			[ 'type' => 'lesson', 'id' => $opt, 'required' => false ],
		] ] ] );

		$problems = ProgressService::quiz_link_problems( $this->course );
		$this->assertSame(
			[
				[ 'lesson_id' => $l1, 'quiz_id' => $before, 'problem' => 'quiz_before_lesson' ],
				[ 'lesson_id' => $l2, 'quiz_id' => $missing, 'problem' => 'quiz_absent' ],
			],
			$problems,
			'Only required quiz_pass lessons are checked; a correctly placed quiz is fine.'
		);
	}

	public function test_saving_a_curriculum_with_a_quiz_link_problem_redirects_with_a_warning() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$quiz   = $this->quiz();
		$lesson = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $quiz ] );
		$_POST  = [
			CourseEditor::NONCE        => wp_create_nonce( CourseEditor::NONCE ),
			'anchor_course_curriculum' => wp_slash( wp_json_encode( [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] ) ),
		];
		( new CourseEditor() )->save_curriculum( $this->course );

		$location = apply_filters( 'redirect_post_location', 'https://example.org/wp-admin/post.php?post=' . $this->course, $this->course );
		$this->assertStringContainsString( Notices::QUERY_ARG . '=curriculum_quiz_link', $location );
	}

	public function test_saving_a_sound_curriculum_adds_no_warning() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$quiz   = $this->quiz();
		$lesson = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $quiz ] );
		$_POST  = [
			CourseEditor::NONCE        => wp_create_nonce( CourseEditor::NONCE ),
			'anchor_course_curriculum' => wp_slash( wp_json_encode( [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ], [ 'type' => 'quiz', 'id' => $quiz ] ] ] ] ) ),
		];
		( new CourseEditor() )->save_curriculum( $this->course );

		$location = apply_filters( 'redirect_post_location', 'https://example.org/wp-admin/post.php?post=' . $this->course, $this->course );
		$this->assertStringNotContainsString( Notices::QUERY_ARG, $location );
	}
}
