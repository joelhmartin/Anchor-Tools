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
