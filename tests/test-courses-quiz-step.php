<?php
/**
 * Anchor Courses - the quiz step: a quiz taken inside its course at
 * /courses/{course}/quiz/{quiz}/ (Frontend\QuizStep, Access::quiz_url(),
 * Shortcodes::render_quiz_step(), templates/quiz-step.php), in the same
 * outline-and-content frame as a lesson.
 *
 * Also ProgressService::availability(), the batched is_item_available() the
 * outline and the course page read (PR #40 review).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Frontend\Access;
use Anchor\Courses\Frontend\Assets;
use Anchor\Courses\Frontend\CourseOutline;
use Anchor\Courses\Frontend\QuizStep;
use Anchor\Courses\Frontend\Templates;
use Anchor\Courses\Module;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** Thrown by the wp_redirect filter so a test can read where QuizStep sent it. */
class Anchor_Courses_Test_Redirect extends Exception {}

/** @group courses */
class Test_Courses_Quiz_Step extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $one;
	private int $quiz;
	private int $two;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'sequential' ], 'Micro Course' );
		$this->one    = $this->make_lesson( [], 'Lesson One' );
		$this->quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 3 ] ], 'Certification Exam' );
		$this->two    = $this->make_lesson( [], 'Lesson Two' );
		Questions::save(
			$this->quiz,
			[ [ 'type' => 'single_choice', 'prompt' => 'Which one?', 'points' => 1,
			    'answers' => [ [ 'id' => 'a1', 'text' => 'Wrong', 'correct' => false ], [ 'id' => 'a2', 'text' => 'Right', 'correct' => true ] ] ] ]
		);
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'Module', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->one ],
				[ 'type' => 'quiz', 'id' => $this->quiz ],
				[ 'type' => 'lesson', 'id' => $this->two ],
			] ] ]
		);
		Roles::grant_access( $this->user, $this->course );
		wp_set_current_user( $this->user );
	}

	public function tear_down() {
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'anchor_courses_can_access_lesson' );
		parent::tear_down();
	}

	private function progress(): ProgressService {
		return $this->courses()->progress;
	}

	private function step( int $quiz_id = 0, int $course_id = 0 ): string {
		return Module::instance()->shortcodes->render_quiz_step( $quiz_id ?: $this->quiz, $course_id ?: $this->course );
	}

	private function pass_quiz( int $course_id = 0 ): void {
		$course_id = $course_id ?: $this->course;
		$quizzes   = Module::instance()->quizzes;
		$attempt   = $quizzes->start_attempt( $this->user, $this->quiz, $course_id );
		$this->assertNotWPError( $attempt );
		$graded = $quizzes->submit( $attempt->id, [ Questions::get( $this->quiz )[0]['id'] => 'a2' ] );
		$this->assertTrue( $graded->passed );
	}

	/**
	 * Pretty permalinks, with this module's rules in the flushed set. A post
	 * type only adds its permastruct when permalinks are on at registration,
	 * so the course type registers again, then the step's rule, then flush.
	 */
	private function pretty(): void {
		$this->set_permalink_structure( '/%postname%/' );
		\Anchor\Courses\Content\CoursePostType::register();
		( new QuizStep() )->add_rewrite();
		flush_rewrite_rules( false );
	}

	/* ---------------------------------------------------------------- route */

	public function test_the_quiz_url_is_the_course_url_plus_quiz_and_its_slug() {
		$this->pretty();

		$this->assertSame(
			home_url( '/courses/micro-course/quiz/certification-exam/' ),
			Access::quiz_url( $this->quiz, $this->course )
		);
		$this->assertSame( Access::quiz_url( $this->quiz, $this->course ), Access::item_url( 'quiz', $this->quiz, $this->course ) );
		$this->assertSame( Access::lesson_url( $this->one, $this->course ), Access::item_url( 'lesson', $this->one, $this->course ) );
	}

	public function test_the_pretty_url_routes_to_the_course_with_the_quiz_step() {
		$this->pretty();

		$this->go_to( Access::quiz_url( $this->quiz, $this->course ) );

		$this->assertTrue( is_singular( 'anchor_course' ) );
		$this->assertSame( $this->course, get_queried_object_id() );
		$this->assertSame( 'certification-exam', get_query_var( QuizStep::QUERY_VAR ) );
		$this->assertSame( $this->quiz, QuizStep::current_quiz() );
		$this->assertStringEndsWith( 'templates/single-quiz.php', ( new Templates() )->template_include( 'theme-single.php' ) );
	}

	public function test_plain_permalinks_use_a_query_arg_on_the_course_url() {
		$url = Access::quiz_url( $this->quiz, $this->course );

		$this->assertStringContainsString( QuizStep::QUERY_VAR . '=certification-exam', $url );

		$this->go_to( $url );
		$this->assertSame( $this->quiz, QuizStep::current_quiz() );
	}

	public function test_the_course_page_itself_is_not_a_quiz_step() {
		$this->go_to( get_permalink( $this->course ) );

		$this->assertSame( 0, QuizStep::current_quiz() );
		$this->assertStringEndsWith( 'templates/single-course.php', ( new Templates() )->template_include( 'theme-single.php' ) );
	}

	public function test_a_quiz_the_course_does_not_list_is_a_404() {
		$this->pretty();
		$stranger = $this->make_quiz( [], 'Somebody Else' );

		$this->go_to( home_url( '/courses/micro-course/quiz/somebody-else/' ) );
		( new QuizStep() )->maybe_redirect();
		$this->assertTrue( is_404(), 'A real quiz this course does not list.' );
		$this->assertSame( 0, QuizStep::current_quiz() );

		$this->go_to( home_url( '/courses/micro-course/quiz/no-such-quiz/' ) );
		( new QuizStep() )->maybe_redirect();
		$this->assertTrue( is_404(), 'No quiz at all.' );
		$this->assertGreaterThan( 0, $stranger );
	}

	public function test_the_quiz_step_is_a_courses_screen_so_quiz_js_loads() {
		$this->go_to( Access::quiz_url( $this->quiz, $this->course ) );

		( new Assets() )->enqueue();

		$this->assertTrue( wp_script_is( 'anchor-courses-quiz', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'anchor-courses-frontend', 'enqueued' ) );
	}

	/* ------------------------------------------------------ access, locking */

	public function test_an_open_quiz_renders_in_the_lesson_frame_with_its_title_once() {
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );

		$html = $this->step();

		$this->assertStringContainsString( 'anchor-lesson-layout', $html );
		$this->assertStringContainsString( 'aria-label="Course outline"', $html );
		$this->assertStringContainsString( 'class="anchor-quiz"', $html );
		$this->assertStringContainsString( 'anchor-quiz-start', $html );
		$this->assertStringContainsString( 'data-course="' . $this->course . '"', $html );

		// One heading names the quiz: the page H1. The quiz box has none.
		$this->assertSame( 1, preg_match_all( '/<h1[^>]*>\s*Certification Exam\s*<\/h1>/', $html ) );
		$this->assertStringNotContainsString( 'anchor-quiz-title', $html );
		$this->assertSame( 0, preg_match( '/<h[2-6][^>]*>\s*Certification Exam/', $html ) );
	}

	public function test_a_locked_quiz_gets_the_locked_lesson_notice_and_no_quiz() {
		$html = $this->step(); // Lesson one is not complete; the course is sequential.

		$this->assertStringContainsString( 'Finish the earlier lessons to unlock this one.', $html );
		$this->assertStringNotContainsString( 'class="anchor-quiz"', $html );
		$this->assertStringNotContainsString( 'anchor-quiz-start', $html );
		$this->assertStringContainsString( 'aria-label="Course outline"', $html, 'Still inside the course.' );
	}

	public function test_a_learner_not_enrolled_gets_the_not_enrolled_notice_and_the_call_to_action() {
		$stranger = $this->make_learner();
		wp_set_current_user( $stranger );

		$html = $this->step();

		$this->assertStringContainsString( 'You are not enrolled in this course.', $html );
		$this->assertStringContainsString( 'Ask us about access to this course.', $html );
		$this->assertStringNotContainsString( 'class="anchor-quiz"', $html );
	}

	public function test_a_visitor_gets_the_not_enrolled_notice() {
		wp_set_current_user( 0 );

		$html = $this->step();

		$this->assertStringContainsString( 'You are not enrolled in this course.', $html );
		$this->assertStringNotContainsString( 'class="anchor-quiz"', $html );
	}

	public function test_the_step_asks_the_same_authority_a_lesson_does() {
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );
		add_filter(
			'anchor_courses_can_access_lesson',
			fn( $allowed, $user, $course, $item, $type ) => 'quiz' === $type ? false : $allowed,
			10,
			5
		);

		$html = $this->step();

		$this->assertStringContainsString( 'Finish the earlier lessons to unlock this one.', $html );
		$this->assertStringNotContainsString( 'anchor-quiz-start', $html );
	}

	/* ------------------------------------------------- outline and the walk */

	public function test_the_outline_marks_the_quiz_current_and_links_it_to_its_step() {
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );

		$html = $this->step();

		$this->assertMatchesRegularExpression(
			'/href="' . preg_quote( esc_url( Access::quiz_url( $this->quiz, $this->course ) ), '/' ) . '" aria-current="page"/',
			$html
		);
		$this->assertStringContainsString( '(Current step)', $html );
		$this->assertSame( 2, substr_count( $html, 'aria-current="page"' ), 'The breadcrumb and exactly one outline item.' );
	}

	public function test_outline_states_for_a_quiz_locked_available_and_done() {
		$outline = fn() => ( new CourseOutline( $this->progress() ) )->build(
			$this->course,
			$this->user,
			$this->one,
			$this->progress()->get_course_progress( $this->user, $this->course )
		);
		$quiz_row = static function ( array $outline ): array {
			foreach ( $outline['modules'][0]['items'] as $item ) {
				if ( 'quiz' === $item['type'] ) {
					return $item;
				}
			}
			return [];
		};

		$row = $quiz_row( $outline() );
		$this->assertSame( CourseOutline::STATE_LOCKED, $row['state'] );
		$this->assertSame( '', $row['url'], 'A locked quiz is not linked.' );

		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );
		$row = $quiz_row( $outline() );
		$this->assertSame( CourseOutline::STATE_AVAILABLE, $row['state'] );
		$this->assertSame( Access::quiz_url( $this->quiz, $this->course ), $row['url'] );

		$this->pass_quiz();
		$row = $quiz_row( $outline() );
		$this->assertSame( CourseOutline::STATE_DONE, $row['state'] );
		$this->assertTrue( $row['complete'] );
	}

	public function test_the_walk_steps_lesson_one_then_the_quiz_then_lesson_two() {
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );

		// Lesson one: Next is the quiz.
		global $post;
		$post        = get_post( $this->one );
		$lesson_html = Module::instance()->shortcodes->render_lesson( $this->one );
		$this->assertMatchesRegularExpression(
			'/<a class="anchor-courses-button anchor-lesson-nav__link anchor-lesson-nav__link--next[^"]*" href="' . preg_quote( esc_url( Access::quiz_url( $this->quiz, $this->course ) ), '/' ) . '" rel="next">\s*<span class="anchor-lesson-nav__label">Next:<\/span>\s*<span class="anchor-lesson-nav__title">Certification Exam <span class="anchor-lesson-nav__tag">Quiz<\/span><\/span>/',
			$lesson_html
		);

		// The quiz: Previous is lesson one, Next is lesson two (locked until the quiz is passed).
		$html = $this->step();
		$this->assertMatchesRegularExpression( '/rel="prev">\s*<span class="anchor-lesson-nav__label">Previous:<\/span>\s*<span class="anchor-lesson-nav__title">Lesson One<\/span>/', $html );
		$this->assertMatchesRegularExpression( '/<span class="anchor-courses-button [^"]*anchor-lesson-nav__link--next[^"]*is-locked" aria-disabled="true">\s*<span class="anchor-lesson-nav__label">Next:<\/span>\s*<span class="anchor-lesson-nav__title">Lesson Two<\/span>/', $html );

		// Passing it opens Next and says so in the footer.
		$this->pass_quiz();
		$html = $this->step();
		$this->assertMatchesRegularExpression(
			'/href="' . preg_quote( esc_url( Access::lesson_url( $this->two, $this->course ) ), '/' ) . '" rel="next">\s*<span class="anchor-lesson-nav__label">Next:<\/span>\s*<span class="anchor-lesson-nav__title">Lesson Two<\/span>/',
			$html
		);
		$this->assertStringContainsString( '<p class="anchor-lesson-done">Passed</p>', $html );

		// Lesson two: Previous is the quiz.
		$post = get_post( $this->two );
		$this->assertMatchesRegularExpression(
			'/href="' . preg_quote( esc_url( Access::quiz_url( $this->quiz, $this->course ) ), '/' ) . '" rel="prev">\s*<span class="anchor-lesson-nav__label">Previous:<\/span>\s*<span class="anchor-lesson-nav__title">Certification Exam/',
			Module::instance()->shortcodes->render_lesson( $this->two )
		);
	}

	public function test_a_quiz_as_the_last_step_offers_back_to_course() {
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'Module', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->one ],
				[ 'type' => 'quiz', 'id' => $this->quiz ],
			] ] ]
		);
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );

		$html = $this->step();

		$this->assertStringContainsString( 'Back to course', $html );
		$this->assertStringNotContainsString( 'rel="next"', $html );
	}

	/* ------------------------------------------------------- shared quizzes */

	public function test_a_quiz_shared_by_two_courses_is_evaluated_against_the_course_in_the_url() {
		$second = $this->make_course( [ 'progression_mode' => 'free' ], 'Second Course' );
		Curriculum::save( $second, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		$learner = $this->make_learner();
		Roles::grant_access( $learner, $second );
		wp_set_current_user( $learner );

		$this->assertNotSame( Access::quiz_url( $this->quiz, $this->course ), Access::quiz_url( $this->quiz, $second ) );

		$html = $this->step( $this->quiz, $second );
		$this->assertStringContainsString( 'data-course="' . $second . '"', $html );
		$this->assertStringContainsString( 'anchor-quiz-start', $html, 'Free progression in the second course: open.' );
		$this->assertStringContainsString( 'Second Course', $html );
		$this->assertStringNotContainsString( 'Lesson One', $html, 'The outline is the second course\'s.' );

		// The first course's step, for somebody enrolled only in the second.
		$html = $this->step( $this->quiz, $this->course );
		$this->assertStringContainsString( 'You are not enrolled in this course.', $html );
	}

	public function test_a_learner_enrolled_only_in_the_other_course_is_sent_to_its_step() {
		$second = $this->make_course( [ 'progression_mode' => 'free' ], 'Second Course' );
		Curriculum::save( $second, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		$learner = $this->make_learner();
		Roles::grant_access( $learner, $second );
		wp_set_current_user( $learner );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Anchor_Courses_Test_Redirect( (string) $location );
			}
		);

		$this->go_to( Access::quiz_url( $this->quiz, $this->course ) );
		try {
			( new QuizStep() )->maybe_redirect();
			$this->fail( 'Expected a redirect to the second course\'s step.' );
		} catch ( Anchor_Courses_Test_Redirect $redirect ) {
			$this->assertSame( Access::quiz_url( $this->quiz, $second ), $redirect->getMessage() );
		}
	}

	public function test_a_visitor_enrolled_nowhere_stays_on_the_course_they_asked_for() {
		$second = $this->make_course( [ 'progression_mode' => 'free' ], 'Second Course' );
		Curriculum::save( $second, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		wp_set_current_user( $this->make_learner() );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Anchor_Courses_Test_Redirect( (string) $location );
			}
		);

		$this->go_to( Access::quiz_url( $this->quiz, $second ) );
		( new QuizStep() )->maybe_redirect();

		$this->assertFalse( is_404() );
		$this->assertSame( $this->quiz, QuizStep::current_quiz() );
	}

	/* ---------------------------------------------------------- course page */

	public function test_the_course_page_links_an_open_quiz_to_its_step_instead_of_rendering_it() {
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );

		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringContainsString( 'href="' . esc_url( Access::quiz_url( $this->quiz, $this->course ) ) . '"', $html );
		$this->assertStringNotContainsString( 'class="anchor-quiz"', $html );
		$this->assertStringNotContainsString( 'anchor-quiz-start', $html );
		$this->assertStringContainsString( 'anchor-course-item-type', $html, 'The item says it is a quiz.' );
	}

	public function test_the_course_page_does_not_link_a_locked_quiz_or_one_a_visitor_cannot_open() {
		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );
		$this->assertStringNotContainsString( QuizStep::QUERY_VAR, $html, 'Locked by progression: not linked.' );

		wp_set_current_user( 0 );
		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );
		$this->assertStringNotContainsString( QuizStep::QUERY_VAR, $html );
		$this->assertStringContainsString( 'Certification Exam', $html, 'Still listed.' );
	}

	/* ------------------------------------------------------------ the rest */

	public function test_render_quiz_keeps_its_title_unless_asked_not_to() {
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );
		$shortcodes = Module::instance()->shortcodes;

		$this->assertStringContainsString( 'anchor-quiz-title', $shortcodes->render_quiz( $this->quiz, $this->course ) );
		$this->assertStringNotContainsString( 'anchor-quiz-title', $shortcodes->render_quiz( $this->quiz, $this->course, false ) );
	}

	public function test_availability_matches_is_item_available_item_by_item() {
		$optional = $this->make_quiz( [], 'Optional Quiz' );
		$draft    = $this->make_lesson( [], 'Draft' );
		wp_update_post( [ 'ID' => $draft, 'post_status' => 'draft' ] );
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'Module', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->one ],
				[ 'type' => 'lesson', 'id' => $draft ],
				[ 'type' => 'quiz', 'id' => $optional, 'required' => false ],
				[ 'type' => 'quiz', 'id' => $this->quiz ],
				[ 'type' => 'lesson', 'id' => $this->two ],
			] ] ]
		);
		add_filter(
			'anchor_courses_can_access_lesson',
			fn( $allowed, $user, $course, $item ) => $item === $optional ? false : $allowed,
			10,
			4
		);

		foreach ( [ 'before' => null, 'after lesson one' => $this->one ] as $label => $complete ) {
			if ( $complete ) {
				$this->progress()->complete_lesson( $this->user, $this->course, $complete );
			}
			$map = $this->progress()->availability( $this->user, $this->course );
			foreach ( Curriculum::items( $this->course ) as $item ) {
				$this->assertSame(
					$this->progress()->is_item_available( $this->user, $this->course, (int) $item['id'], $item['type'] ),
					$map[ $item['type'] . ':' . $item['id'] ],
					$label . ': ' . $item['type'] . ':' . $item['id']
				);
			}
		}
		$this->assertTrue( $map[ 'quiz:' . $this->quiz ], 'The draft lesson never gates.' );
		$this->assertFalse( $map[ 'quiz:' . $optional ], 'The filter still runs per item.' );
		$this->assertFalse( $map[ 'lesson:' . $draft ] );

		$this->assertSame( [], array_filter( $this->progress()->availability( $this->make_learner(), $this->course ) ), 'Not enrolled: nothing is open.' );
	}

	public function test_availability_reads_enrolment_and_progress_once_not_per_item() {
		global $wpdb;
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );
		$this->progress()->availability( $this->user, $this->course ); // Warm the post and meta caches.

		$before = $wpdb->num_queries;
		$this->progress()->availability( $this->user, $this->course );
		$batched = $wpdb->num_queries - $before;

		$before = $wpdb->num_queries;
		foreach ( Curriculum::items( $this->course ) as $item ) {
			$this->progress()->is_item_available( $this->user, $this->course, (int) $item['id'], $item['type'] );
		}
		$per_item = $wpdb->num_queries - $before;

		$this->assertLessThan( $per_item, $batched );
	}

	public function test_the_new_templates_are_theme_overridable() {
		foreach ( [ 'single-quiz', 'quiz-step' ] as $name ) {
			$this->assertFileExists( Templates::locate( $name ), $name );
			$this->assertStringContainsString( 'anchor-courses/templates/' . $name . '.php', Templates::locate( $name ) );
		}
	}
}
