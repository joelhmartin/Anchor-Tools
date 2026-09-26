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
use Anchor\Courses\Admin\LessonEditor;
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

	/**
	 * Audit F04 re-review: a quiz placed immediately before its lesson is NOT
	 * a problem (it opens with the earlier items and completes the lesson via
	 * `complete_gated_lessons()` when passed - see
	 * `test_a_required_lesson_its_quiz_and_the_next_lesson_are_completable()`
	 * and the reverse-order sibling below). The real deadlock is a REQUIRED
	 * item strictly between the lesson and its quiz: in sequential mode that
	 * item waits on the lesson, the quiz waits on that item, and the lesson
	 * waits on the quiz.
	 */
	public function test_quiz_link_problems_names_a_deadlocking_gap_and_a_missing_quiz() {
		$before  = $this->quiz();
		$missing = $this->quiz();
		$good    = $this->quiz();
		$gapped  = $this->quiz();
		$l1      = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $before ] );
		$l2      = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $missing ] );
		$l3      = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $good ] );
		$l4      = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $gapped ] );
		$between = $this->make_lesson();
		$opt     = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $missing ] );
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [
			[ 'type' => 'quiz', 'id' => $before ],
			[ 'type' => 'lesson', 'id' => $l1 ],
			[ 'type' => 'lesson', 'id' => $l2 ],
			[ 'type' => 'lesson', 'id' => $l3 ],
			[ 'type' => 'quiz', 'id' => $good ],
			[ 'type' => 'lesson', 'id' => $l4 ],
			[ 'type' => 'lesson', 'id' => $between ],
			[ 'type' => 'quiz', 'id' => $gapped ],
			[ 'type' => 'lesson', 'id' => $opt, 'required' => false ],
		] ] ] );

		$problems = ProgressService::quiz_link_problems( $this->course );
		$this->assertSame(
			[
				[ 'lesson_id' => $l2, 'quiz_id' => $missing, 'problem' => 'quiz_absent' ],
				[ 'lesson_id' => $l4, 'quiz_id' => $gapped, 'problem' => 'item_between' ],
			],
			$problems,
			'A quiz immediately before its lesson works and is not flagged; a required item ' .
			'strictly between a lesson and its quiz deadlocks and is flagged; a correctly placed quiz is fine.'
		);
	}

	/**
	 * CodeRabbit PR #32 (audit F04 re-review): the deadlock only exists when
	 * the LESSON precedes its quiz (`[L, X, Q]`) - a required item X between
	 * them then waits on the lesson (sequential gating), the quiz waits on
	 * X, and the lesson waits on the quiz: nobody can move. The mirror order
	 * (`[Q, X, L]`) has no cycle: the quiz opens with the earlier items
	 * regardless of what sits between it and its lesson, and passing it
	 * completes the lesson directly - so it must never be flagged.
	 */
	public function test_item_between_only_deadlocks_when_the_lesson_precedes_its_quiz() {
		$quiz_lq = $this->quiz();
		$x1      = $this->make_lesson();
		$l_lq    = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $quiz_lq ] );

		$quiz_ql = $this->quiz();
		$x2      = $this->make_lesson();
		$l_ql    = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $quiz_ql ] );

		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [
			// [L, X, Q]: X waits on L, Q waits on X, L waits on Q - deadlock.
			[ 'type' => 'lesson', 'id' => $l_lq ],
			[ 'type' => 'lesson', 'id' => $x1 ],
			[ 'type' => 'quiz', 'id' => $quiz_lq ],
			// [Q, X, L]: Q opens with the earlier items; passing it completes L - fine.
			[ 'type' => 'quiz', 'id' => $quiz_ql ],
			[ 'type' => 'lesson', 'id' => $x2 ],
			[ 'type' => 'lesson', 'id' => $l_ql ],
		] ] ] );

		$this->assertSame(
			[ [ 'lesson_id' => $l_lq, 'quiz_id' => $quiz_lq, 'problem' => 'item_between' ] ],
			ProgressService::quiz_link_problems( $this->course ),
			'[L, X, Q] deadlocks and is flagged; [Q, X, L] is not.'
		);
	}

	/** The `item_between` check only applies under sequential progression - free progression never gates on order. */
	public function test_a_gap_between_lesson_and_quiz_is_not_flagged_under_free_progression() {
		$free   = $this->make_course( [ 'progression_mode' => 'free' ] );
		$quiz   = $this->quiz();
		$lesson = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $quiz ] );
		$other  = $this->make_lesson();
		Curriculum::save( $free, [ [ 'title' => 'M', 'items' => [
			[ 'type' => 'lesson', 'id' => $lesson ],
			[ 'type' => 'lesson', 'id' => $other ],
			[ 'type' => 'quiz', 'id' => $quiz ],
		] ] ] );

		$this->assertSame( [], ProgressService::quiz_link_problems( $free ) );
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

	/* --- lesson-save warning (Round 8, Codex, PR #32 finding 3) ------------- */

	private function save_lesson( int $lesson_id, array $fields ): void {
		$_POST = [
			LessonEditor::NONCE => wp_create_nonce( LessonEditor::NONCE ),
			'anchor_lesson'     => $fields,
		];
		( new LessonEditor() )->save( $lesson_id );
		$_POST = [];
	}

	/**
	 * quiz_link_problems() used to run only on curriculum save
	 * (CourseEditor::save_curriculum()). A course saved clean as
	 * `[L(manual), X, Q]` never has its curriculum re-saved, so flipping L to
	 * `quiz_pass` + this quiz here must still warn - naming the course, since
	 * the lesson screen has no course of its own in view.
	 */
	public function test_saving_a_lesson_that_newly_deadlocks_its_course_warns_naming_the_course() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$quiz    = $this->quiz();
		$lesson  = $this->make_lesson( [ 'completion_mode' => 'manual' ] );
		$between = $this->make_lesson();
		$this->curriculum( [
			[ 'type' => 'lesson', 'id' => $lesson ],
			[ 'type' => 'lesson', 'id' => $between ],
			[ 'type' => 'quiz', 'id' => $quiz ],
		] );
		$this->assertSame( [], ProgressService::quiz_link_problems( $this->course ), 'Precondition: [L(manual), X, Q] is clean.' );

		$this->save_lesson( $lesson, [ 'completion_mode' => 'quiz_pass', 'quiz_id' => (string) $quiz ] );

		$this->assertNotSame( [], ProgressService::quiz_link_problems( $this->course ), 'The lesson save itself now deadlocks the course.' );

		$location = apply_filters( 'redirect_post_location', 'https://example.org/wp-admin/post.php?post=' . $lesson, $lesson );
		$this->assertStringContainsString( Notices::QUERY_ARG . '=lesson_quiz_link', $location );

		$query = [];
		\parse_str( (string) \wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( 'Test Course', $query[ Notices::COURSES_QUERY_ARG ] ?? null, 'The affected course is named.' );
	}

	/** A lesson save that keeps every course sound must add no warning at all. */
	public function test_saving_a_lesson_with_no_dependency_problem_adds_no_warning() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$quiz   = $this->quiz();
		$lesson = $this->make_lesson( [ 'completion_mode' => 'manual' ] );
		$this->curriculum( [
			[ 'type' => 'lesson', 'id' => $lesson ],
			[ 'type' => 'quiz', 'id' => $quiz ],
		] );

		$this->save_lesson( $lesson, [ 'completion_mode' => 'quiz_pass', 'quiz_id' => (string) $quiz ] );

		$this->assertSame( [], ProgressService::quiz_link_problems( $this->course ) );
		$location = apply_filters( 'redirect_post_location', 'https://example.org/wp-admin/post.php?post=' . $lesson, $lesson );
		$this->assertStringNotContainsString( Notices::QUERY_ARG, $location );
	}

	/** A lesson that belongs to no course at all must not error. */
	public function test_saving_an_orphan_lesson_adds_no_warning() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson( [ 'completion_mode' => 'manual' ] );

		$this->save_lesson( $lesson, [ 'completion_mode' => 'view' ] );

		$location = apply_filters( 'redirect_post_location', 'https://example.org/wp-admin/post.php?post=' . $lesson, $lesson );
		$this->assertStringNotContainsString( Notices::QUERY_ARG, $location );
	}
}
