<?php
/**
 * Anchor Courses - resuming a quiz: an open attempt reads "Resume quiz" on the
 * quiz step, "In progress" in the outline and on the course page, and
 * starting again returns that same attempt (answers and pinned deadline
 * intact) instead of opening a second one. An attempt past its time limit is
 * closed by the module's own timer policy before anything offers to resume it.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Frontend\CourseOutline;
use Anchor\Courses\Module;
use Anchor\Courses\Rest\Routes;
use Anchor\Courses\Services\QuizService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Quiz_Resume extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $lesson;
	private int $quiz;
	private string $q1;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ], 'Resume Course' );
		$this->lesson = $this->make_lesson( [], 'Reading' );
		$this->quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 2 ] ], 'Final Exam' );
		$saved        = Questions::save(
			$this->quiz,
			[
				[ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
				  'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ],
				[ 'type' => 'single_choice', 'prompt' => 'Two?', 'points' => 1,
				  'answers' => [ [ 'id' => 'b1', 'text' => 'A', 'correct' => true ], [ 'id' => 'b2', 'text' => 'B', 'correct' => false ] ] ],
			]
		);
		$this->q1 = $saved[0]['id'];
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ], [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ]
		);
		Roles::grant_access( $this->user, $this->course );
		wp_set_current_user( $this->user );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_now' );
		parent::tear_down();
	}

	private function quizzes(): QuizService {
		return Module::instance()->quizzes;
	}

	private function step(): string {
		return Module::instance()->shortcodes->render_quiz_step( $this->quiz, $this->course );
	}

	private function start_via_rest(): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . Routes::NAMESPACE . '/quizzes/' . $this->quiz . '/attempts' );
		$request->set_param( 'course_id', $this->course );
		return rest_get_server()->dispatch( $request );
	}

	private function timed( string $policy ): void {
		update_post_meta(
			$this->quiz,
			'_anchor_quiz_settings',
			[ 'passing_score' => 80, 'max_attempts' => 2, 'time_limit_seconds' => 60, 'on_timer_expiry' => $policy ]
		);
	}

	/* --------------------------------------------------------- detection */

	public function test_no_open_attempt_means_nothing_to_resume_and_start_quiz() {
		$this->assertNull( $this->quizzes()->resumable_attempt( $this->user, $this->quiz, $this->course ) );
		$this->assertSame( [], $this->quizzes()->open_quiz_ids( $this->user, $this->course ) );

		$html = $this->step();
		$this->assertStringContainsString( 'Start quiz', $html );
		$this->assertStringNotContainsString( 'Resume quiz', $html );
	}

	public function test_an_open_attempt_is_detected_and_the_step_says_resume_quiz() {
		$attempt = $this->quizzes()->start_attempt( $this->user, $this->quiz, $this->course );

		$this->assertSame( $attempt->id, $this->quizzes()->resumable_attempt( $this->user, $this->quiz, $this->course )->id );
		$this->assertSame( [ $this->quiz ], $this->quizzes()->open_quiz_ids( $this->user, $this->course ) );

		$html = $this->step();
		$this->assertStringContainsString( 'Resume quiz', $html );
		$this->assertStringContainsString( 'anchor-quiz-resume', $html );
		$this->assertStringContainsString( 'Your saved answers are kept.', $html );
		$this->assertStringNotContainsString( 'Start quiz', $html );
	}

	public function test_an_open_attempt_in_another_course_is_not_this_courses_to_resume() {
		$other = $this->make_course( [ 'progression_mode' => 'free' ], 'Other' );
		Curriculum::save( $other, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		Roles::grant_access( $this->user, $other );
		$this->quizzes()->start_attempt( $this->user, $this->quiz, $other );

		$this->assertNull( $this->quizzes()->resumable_attempt( $this->user, $this->quiz, $this->course ) );
		$this->assertStringContainsString( 'Start quiz', $this->step() );
	}

	public function test_a_graded_attempt_is_not_resumable() {
		$attempt = $this->quizzes()->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes()->submit( $attempt->id, [ $this->q1 => 'a1' ] );

		$this->assertNull( $this->quizzes()->resumable_attempt( $this->user, $this->quiz, $this->course ) );
		$this->assertStringContainsString( 'Start quiz', $this->step() );
	}

	/* ------------------------------------------ start returns the same one */

	public function test_starting_again_returns_the_open_attempt_with_its_saved_answers() {
		$first = $this->quizzes()->start_attempt( $this->user, $this->quiz, $this->course );
		$this->assertTrue( $this->quizzes()->save_answer( $first->id, $this->q1, 'a2', $this->user ) );

		$again = $this->quizzes()->start_attempt( $this->user, $this->quiz, $this->course );

		$this->assertSame( $first->id, $again->id );
		$this->assertSame( [ 'a2' ], (array) $again->answers[ $this->q1 ] );
		$this->assertSame( 1, $this->quizzes()->attempts_used( $this->user, $this->quiz, $this->course ), 'No second attempt was opened.' );
	}

	public function test_the_rest_start_route_resumes_the_same_attempt_with_answers_and_the_same_deadline() {
		$this->timed( 'auto_submit' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );

		$first = $this->start_via_rest()->get_data();
		$this->quizzes()->save_answer( (int) $first['attempt']['id'], $this->q1, 'a2', $this->user );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:30 UTC' ) );
		$second = $this->start_via_rest()->get_data();

		$this->assertSame( $first['attempt']['id'], $second['attempt']['id'] );
		$this->assertSame( [ 'a2' ], (array) $second['attempt']['answers'][ $this->q1 ] );
		$this->assertSame( $first['deadline'], $second['deadline'], 'The timer continues; it does not restart.' );
		$this->assertSame( strtotime( '2026-05-01 10:01:00 UTC' ), $second['deadline'] );
	}

	/* ------------------------------------------------------------- expiry */

	public function test_an_attempt_past_its_limit_is_auto_submitted_by_its_policy_not_offered_for_resume() {
		$this->timed( 'auto_submit' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes()->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes()->save_answer( $attempt->id, $this->q1, 'a2', $this->user );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:05:00 UTC' ) );

		$html = $this->step();

		$this->assertStringNotContainsString( 'Resume quiz', $html );
		$this->assertStringContainsString( 'Start quiz', $html, 'One attempt left.' );
		$this->assertSame( 'graded', $this->quizzes()->get_attempt( $attempt->id )->status, 'auto_submit graded the saved answers.' );
	}

	public function test_an_attempt_past_its_limit_under_the_expire_policy_is_closed_as_expired() {
		$this->timed( 'expire' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes()->start_attempt( $this->user, $this->quiz, $this->course );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:05:00 UTC' ) );

		$this->assertNull( $this->quizzes()->resumable_attempt( $this->user, $this->quiz, $this->course ) );
		$this->assertSame( 'expired', $this->quizzes()->get_attempt( $attempt->id )->status );
	}

	/* ------------------------------------------ outline and course page */

	public function test_the_outline_and_the_course_page_mark_a_started_quiz_in_progress() {
		$this->quizzes()->start_attempt( $this->user, $this->quiz, $this->course );
		$progress = $this->courses()->progress->get_course_progress( $this->user, $this->course );

		$outline = ( new CourseOutline( $this->courses()->progress ) )->build( $this->course, $this->user, $this->lesson, $progress );
		$quiz    = $outline['modules'][0]['items'][1];
		$this->assertSame( CourseOutline::STATE_IN_PROGRESS, $quiz['state'] );
		$this->assertTrue( $quiz['in_progress'] );
		$this->assertFalse( $outline['modules'][0]['items'][0]['in_progress'], 'Lessons never carry it.' );

		global $post;
		$post = get_post( $this->lesson );
		$html = Module::instance()->shortcodes->render_lesson( $this->lesson );
		$this->assertMatchesRegularExpression( '/anchor-course-outline__item--quiz is-in-progress is-started/', $html );
		$this->assertStringContainsString( '(In progress)', $html );

		$course_html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );
		$this->assertMatchesRegularExpression( '/anchor-course-item--quiz[^"]*is-started/', $course_html );
		$this->assertStringContainsString( 'anchor-course-item-status', $course_html );
	}

	public function test_the_current_quiz_step_keeps_current_and_announces_in_progress() {
		$this->quizzes()->start_attempt( $this->user, $this->quiz, $this->course );

		$html = $this->step();

		$this->assertStringContainsString( '(Current step, In progress)', $html );
		$this->assertMatchesRegularExpression( '/anchor-course-outline__item--quiz is-current is-started/', $html );
	}

	public function test_in_progress_is_one_query_for_the_whole_course() {
		global $wpdb;
		$this->quizzes()->start_attempt( $this->user, $this->quiz, $this->course );

		$before = $wpdb->num_queries;
		$this->quizzes()->open_quiz_ids( $this->user, $this->course );
		$this->assertSame( 1, $wpdb->num_queries - $before );
	}
}
