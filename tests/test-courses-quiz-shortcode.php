<?php
/**
 * Anchor Courses - Frontend\Shortcodes::render_quiz() and its assets/wiring
 * (Task 26; mirrors the render_lesson() coverage in test-courses-shortcodes.php
 * added for the Task 18 review fix round).
 *
 * A non-enrolled or locked learner must see the notice from can_start(), never
 * the quiz markup - the same "notice, not the content" invariant ContentGuard
 * enforces for a lesson body, applied here at the shortcode/template level
 * because QuizPostType has no post of its own to guard (it is never a
 * standalone URL; see QuizPostType.php).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Frontend\Templates;
use Anchor\Courses\Module;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Quiz_Shortcode extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $quiz;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ], 'Laser Safety' );
		$this->quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 3 ] ], 'Final Exam' );

		Questions::save(
			$this->quiz,
			[ [ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
			    'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ] ]
		);
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
	}

	private function render(): string {
		return Module::instance()->shortcodes->render_quiz( $this->quiz );
	}

	public function test_a_logged_out_visitor_gets_nothing() {
		wp_set_current_user( 0 );
		$this->assertSame( '', $this->render() );
	}

	public function test_a_non_enrolled_learner_sees_the_notice_and_no_quiz_markup() {
		wp_set_current_user( $this->user );

		$html = $this->render();

		$this->assertStringContainsString( 'You are not enrolled in this course.', $html );
		$this->assertStringNotContainsString( 'anchor-quiz-start', $html );
		$this->assertStringNotContainsString( 'attempt remaining', $html );
	}

	public function test_an_enrolled_learner_sees_the_start_button_and_the_quiz_shell() {
		wp_set_current_user( $this->user );
		Roles::grant_access( $this->user, $this->course );

		$html = $this->render();

		$this->assertStringContainsString( 'data-quiz="' . $this->quiz . '"', $html );
		$this->assertStringContainsString( 'data-course="' . $this->course . '"', $html );
		$this->assertStringContainsString( 'anchor-quiz-start', $html );
		$this->assertStringContainsString( 'Final Exam', $html );
		$this->assertStringNotContainsString( 'You are not enrolled', $html );
	}

	public function test_the_shell_carries_no_question_or_answer_data() {
		wp_set_current_user( $this->user );
		Roles::grant_access( $this->user, $this->course );

		$html = $this->render();

		// Questions are fetched over REST only after "Start" is clicked
		// (brief 8.3) - the server-rendered shell must never embed them.
		$this->assertStringNotContainsString( 'One?', $html );
		$this->assertStringNotContainsString( 'correct', $html );
	}

	public function test_attempts_remaining_is_shown_for_a_limited_quiz() {
		wp_set_current_user( $this->user );
		Roles::grant_access( $this->user, $this->course );

		$this->assertStringContainsString( '3 attempts remaining', $this->render() );
	}

	public function test_a_sequentially_locked_quiz_shows_its_own_notice() {
		$course = $this->make_course( [ 'progression_mode' => 'sequential' ], 'Sequential' );
		$lesson = $this->make_lesson( [], 'First' );
		$quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80 ] ], 'Gate' );
		Questions::save(
			$quiz,
			[ [ 'type' => 'true_false', 'prompt' => 'T?', 'points' => 1,
			    'answers' => [ [ 'id' => 'true', 'text' => 'True', 'correct' => true ], [ 'id' => 'false', 'text' => 'False', 'correct' => false ] ] ] ]
		);
		Curriculum::save(
			$course,
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => $lesson ],
				[ 'type' => 'quiz', 'id' => $quiz ],
			] ] ]
		);
		Roles::grant_access( $this->user, $course );
		wp_set_current_user( $this->user );

		$html = Module::instance()->shortcodes->render_quiz( $quiz );

		$this->assertStringContainsString( 'Finish the earlier items first.', $html );
		$this->assertStringNotContainsString( 'anchor-quiz-start', $html );
	}

	/**
	 * Task 26 review, IMPORTANT: render_quiz() used to resolve the course via
	 * Curriculum::course_for_item() (deterministic but arbitrary - lowest
	 * course id), regardless of which course it was actually being rendered
	 * for. A learner enrolled only in the SECOND course sharing this quiz
	 * must still see the quiz shell (evaluated against that second course),
	 * not the "not enrolled" notice course_for_item() would have produced by
	 * silently checking the first course instead.
	 */
	public function test_a_quiz_shared_by_two_courses_renders_for_the_explicit_course_not_the_default_owner() {
		$second = $this->make_course( [ 'progression_mode' => 'free' ], 'Second Course' );
		Curriculum::save( $second, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );

		// $this->course (from set_up()) was created first, so course_for_item()
		// would resolve to IT by default - the learner is enrolled only in $second.
		$this->assertLessThan( $second, $this->course );
		Roles::grant_access( $this->user, $second );
		wp_set_current_user( $this->user );

		$html = Module::instance()->shortcodes->render_quiz( $this->quiz, $second );

		$this->assertStringContainsString( 'data-course="' . $second . '"', $html );
		$this->assertStringContainsString( 'anchor-quiz-start', $html );
		$this->assertStringNotContainsString( 'You are not enrolled', $html );
	}

	/** Omitting $course_id (the default 0) must still fall back to course_for_item(), unchanged. */
	public function test_omitting_the_course_id_falls_back_to_course_for_item() {
		Roles::grant_access( $this->user, $this->course );
		wp_set_current_user( $this->user );

		$html = Module::instance()->shortcodes->render_quiz( $this->quiz );

		$this->assertStringContainsString( 'data-course="' . $this->course . '"', $html );
	}

	/** Task 26 review, MINOR: the "Best score" line must respect show_score, same as the REST payload. */
	public function test_best_score_is_hidden_when_show_score_is_off() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'show_score' => 0 ] );
		Roles::grant_access( $this->user, $this->course );
		wp_set_current_user( $this->user );

		$quiz_service = Module::instance()->quizzes;
		$attempt      = $quiz_service->start_attempt( $this->user, $this->quiz, $this->course );
		$quiz_service->submit( $attempt->id, [ Questions::get( $this->quiz )[0]['id'] => 'a2' ] );

		$html = $this->render();

		$this->assertStringNotContainsString( 'Best score', $html );
		$this->assertStringNotContainsString( 'anchor-quiz-passed', $html );
	}

	/** The flip side is real, not just always-off. */
	public function test_best_score_is_shown_when_show_score_is_on() {
		Roles::grant_access( $this->user, $this->course );
		wp_set_current_user( $this->user );

		$quiz_service = Module::instance()->quizzes;
		$attempt      = $quiz_service->start_attempt( $this->user, $this->quiz, $this->course );
		$quiz_service->submit( $attempt->id, [ Questions::get( $this->quiz )[0]['id'] => 'a2' ] );

		$html = $this->render();

		$this->assertStringContainsString( 'Best score', $html );
	}

	public function test_templates_locate_finds_the_plugin_quiz_template() {
		$path = Templates::locate( 'quiz' );
		$this->assertStringContainsString( 'anchor-courses/templates/quiz.php', $path );
		$this->assertFileExists( $path );
	}

	public function test_frontend_assets_enqueue_the_quiz_script_with_its_nonce_on_a_course_page() {
		wp_set_current_user( $this->user );
		$this->go_to( get_permalink( $this->course ) );

		( new \Anchor\Courses\Frontend\Assets() )->enqueue();

		$this->assertTrue( wp_script_is( 'anchor-courses-quiz', 'enqueued' ) );

		$localized = wp_scripts()->get_data( 'anchor-courses-quiz', 'data' );
		$this->assertIsString( $localized );
		$this->assertStringContainsString( 'anchorCoursesQuizRuntime', $localized );
		$this->assertStringContainsString( 'restUrl', $localized );
		$this->assertStringContainsString( 'nonce', $localized );
	}
}
