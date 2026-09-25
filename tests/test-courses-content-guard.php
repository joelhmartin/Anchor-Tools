<?php
/**
 * Anchor Courses - the lesson content guard (Task 18 review fix round).
 *
 * `anchor_lesson` is public + publicly_queryable so its single template still
 * resolves, but that means `the_content`/`the_excerpt`/`get_the_excerpt` and
 * anything that loops over the post (a feed, a search result before the CPT
 * was excluded, a theme template) can read the body directly, bypassing the
 * `$available` check the template itself computes. Frontend\ContentGuard is
 * the one place that answer is enforced for every one of those paths.
 *
 * The REST leak this closes: `anchor_lesson`/`anchor_quiz` used to register
 * `show_in_rest => true` with no permission callback, so
 * `GET /wp-json/wp/v2/anchor_lesson/{id}` returned the full body to an
 * anonymous caller. Task 26 owns permission-checked routes for this data;
 * core REST must never carry either post type again.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Content\QuizPostType;
use Anchor\Courses\Frontend\Access;
use Anchor\Courses\Frontend\ContentGuard;
use Anchor\Courses\Module;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Content_Guard extends Anchor_Courses_TestCase {

	private int $course;
	private int $lesson;

	public function tear_down() {
		unset( $_GET[ Access::COURSE_ARG ] );
		wp_reset_postdata();
		parent::tear_down();
	}

	public function set_up() {
		parent::set_up();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ], 'Guarded Course' );
		$this->lesson = $this->make_lesson( [], 'Secret Lesson Body' );
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'Module', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ]
		);
		// Not just post_content: the factory default post_excerpt would make
		// get_the_excerpt() return that manual excerpt untouched, never
		// falling through to wp_trim_excerpt()'s content-derived (and
		// the_content-filtered) path this guard depends on.
		wp_update_post( [ 'ID' => $this->lesson, 'post_content' => 'Top secret lesson body.', 'post_excerpt' => '' ] );
	}

	public function test_anonymous_rest_request_for_a_lesson_returns_404() {
		$base     = get_post_type_object( LessonPostType::CPT )->rest_base ?: LessonPostType::CPT;
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . $base . '/' . $this->lesson ) );

		$this->assertSame( 404, $response->get_status(), 'anchor_lesson must have no core REST route at all.' );
	}

	public function test_anonymous_rest_request_for_a_quiz_returns_404() {
		$quiz = $this->make_quiz( [], 'Secret Quiz' );
		$base = get_post_type_object( QuizPostType::CPT )->rest_base ?: QuizPostType::CPT;

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . $base . '/' . $quiz ) );

		$this->assertSame( 404, $response->get_status(), 'anchor_quiz must have no core REST route at all.' );
	}

	public function test_the_content_filter_replaces_the_body_for_a_non_enrolled_user() {
		wp_set_current_user( $this->make_learner() );
		global $post;
		$post = get_post( $this->lesson );

		$filtered = apply_filters( 'the_content', get_post_field( 'post_content', $this->lesson ) );

		$this->assertStringNotContainsString( 'Top secret lesson body.', $filtered );
		$this->assertStringContainsString( 'anchor-courses-notice', $filtered );
	}

	public function test_the_content_filter_passes_the_body_for_an_enrolled_user() {
		$user = $this->make_learner();
		wp_set_current_user( $user );
		Roles::grant_access( $user, $this->course );

		global $post;
		$post = get_post( $this->lesson );

		$filtered = apply_filters( 'the_content', get_post_field( 'post_content', $this->lesson ) );

		$this->assertStringContainsString( 'Top secret lesson body.', $filtered );
	}

	public function test_the_excerpt_filter_replaces_the_body_for_a_non_enrolled_user() {
		wp_set_current_user( $this->make_learner() );
		global $post;
		$post = get_post( $this->lesson );

		$excerpt = get_the_excerpt( $this->lesson );

		$this->assertStringNotContainsString( 'Top secret lesson body.', $excerpt );
	}

	public function test_the_excerpt_filter_passes_the_body_for_an_enrolled_user() {
		$user = $this->make_learner();
		wp_set_current_user( $user );
		Roles::grant_access( $user, $this->course );

		global $post;
		$post = get_post( $this->lesson );

		$excerpt = get_the_excerpt( $this->lesson );

		$this->assertStringContainsString( 'Top secret lesson body.', $excerpt );
	}

	public function test_the_content_filter_leaves_other_post_types_alone() {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_content' => 'Ordinary page body.' ] );
		global $post;
		$post = get_post( $page );

		$filtered = apply_filters( 'the_content', get_post_field( 'post_content', $page ) );

		$this->assertStringContainsString( 'Ordinary page body.', $filtered );
	}

	public function test_access_can_view_lesson_matches_the_enrolment_state() {
		$user = $this->make_learner();

		$this->assertFalse( Access::can_view_lesson( $this->lesson, $user ) );

		Roles::grant_access( $user, $this->course );

		$this->assertTrue( Access::can_view_lesson( $this->lesson, $user ) );
	}

	public function test_access_can_view_lesson_is_false_when_logged_out() {
		wp_set_current_user( 0 );
		$this->assertFalse( Access::can_view_lesson( $this->lesson ) );
	}

	/* ---------------------------------------------------------------------
	 * Final review I2 - which course a lesson is evaluated against
	 * ------------------------------------------------------------------- */

	/** Review probe: an older DRAFT copy listing the lesson used to win the lowest-id tie-break. */
	public function test_an_older_draft_course_does_not_lock_out_the_published_courses_learners() {
		$lesson = $this->make_lesson( [ 'completion_mode' => 'manual', 'required' => 1 ] );
		$draft  = self::factory()->post->create( [ 'post_type' => 'anchor_course', 'post_status' => 'draft' ] );
		Curriculum::save( $draft, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		$course = $this->make_course( [ 'progression_mode' => 'free' ] );
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		$user = $this->make_learner();
		Roles::grant_access( $user, $course, 'manual' );
		wp_set_current_user( $user );

		$this->assertSame( $course, Curriculum::course_for_item( $lesson, 'lesson' ), 'A draft course never owns an item.' );
		$this->assertTrue( Access::can_view_lesson( $lesson ), 'An enrolled learner must not be locked out by an older draft.' );
	}

	/** A lesson shared by two published courses resolves to the one the learner is enrolled in. */
	public function test_a_shared_lesson_resolves_to_the_course_the_learner_is_enrolled_in() {
		$lesson = $this->make_lesson();
		$first  = $this->make_course( [ 'progression_mode' => 'free' ], 'First' );
		$second = $this->make_course( [ 'progression_mode' => 'free' ], 'Second' );
		foreach ( [ $first, $second ] as $c ) {
			Curriculum::save( $c, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		}
		$user = $this->make_learner();
		Roles::grant_access( $user, $second, 'manual' );
		wp_set_current_user( $user );

		$this->assertSame( $second, Access::course_for_lesson( $lesson, $user ) );
		$this->assertTrue( Access::can_view_lesson( $lesson ) );

		global $post;
		$post = get_post( $lesson );
		$html = Module::instance()->shortcodes->render_lesson( $lesson );
		$this->assertStringContainsString( 'data-course="' . $second . '"', $html );
	}

	/** Explicit course context on the URL beats every fallback. */
	public function test_the_course_query_arg_is_the_explicit_context() {
		$lesson = $this->make_lesson();
		$first  = $this->make_course( [ 'progression_mode' => 'free' ], 'First' );
		$second = $this->make_course( [ 'progression_mode' => 'free' ], 'Second' );
		foreach ( [ $first, $second ] as $c ) {
			Curriculum::save( $c, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		}
		$user = $this->make_learner();
		Roles::grant_access( $user, $first, 'manual' );
		Roles::grant_access( $user, $second, 'manual' );

		$this->assertSame( $first, Access::course_for_lesson( $lesson, $user ) );
		$_GET[ Access::COURSE_ARG ] = (string) $second;
		$this->assertSame( $second, Access::course_for_lesson( $lesson, $user ) );

		// A course that does not list the lesson is ignored, not trusted.
		$_GET[ Access::COURSE_ARG ] = (string) $this->course;
		$this->assertSame( $first, Access::course_for_lesson( $lesson, $user ) );
	}

	/** Every lesson link the templates emit carries the course context. */
	public function test_lesson_links_carry_the_course_context() {
		$user = $this->make_learner();
		Roles::grant_access( $user, $this->course );
		wp_set_current_user( $user );

		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringContainsString( esc_url( Access::lesson_url( $this->lesson, $this->course ) ), $html );
		$this->assertStringContainsString( Access::COURSE_ARG . '=' . $this->course, $html );
	}

	/* ---------------------------------------------------------------------
	 * Final review I3 - embeds survive for the learner
	 * ------------------------------------------------------------------- */

	public function test_an_embedded_iframe_survives_for_an_enrolled_learner_and_not_for_a_stranger() {
		kses_remove_filters();
		wp_update_post( [ 'ID' => $this->lesson, 'post_content' => '<iframe src="https://player.vimeo.com/video/1" width="640" height="360"></iframe>' ] );
		kses_init_filters();
		global $post;
		$post = get_post( $this->lesson );

		$user = $this->make_learner();
		Roles::grant_access( $user, $this->course );
		wp_set_current_user( $user );
		$html = Module::instance()->shortcodes->render_lesson( $this->lesson );
		$this->assertStringContainsString( '<iframe', $html );
		$this->assertStringContainsString( 'src="https://player.vimeo.com/video/1"', $html );

		wp_set_current_user( $this->make_learner() );
		$html = Module::instance()->shortcodes->render_lesson( $this->lesson );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	/* ---------------------------------------------------------------------
	 * Final review I4 - preview bypass and honest messages
	 * ------------------------------------------------------------------- */

	public function test_an_administrator_can_preview_a_lesson_without_enrolling() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$this->assertTrue( Access::can_view_lesson( $this->lesson ) );

		global $post;
		$post = get_post( $this->lesson );
		$this->assertStringContainsString( 'Top secret lesson body.', apply_filters( 'the_content', get_post_field( 'post_content', $this->lesson ) ) );
	}

	public function test_a_non_enrolled_visitor_is_told_they_are_not_enrolled_not_to_finish_earlier_lessons() {
		wp_set_current_user( $this->make_learner() );
		global $post;
		$post = get_post( $this->lesson );

		$filtered = apply_filters( 'the_content', get_post_field( 'post_content', $this->lesson ) );

		$this->assertStringContainsString( 'You are not enrolled in this course.', $filtered );
		$this->assertStringContainsString( 'Ask us about access to this course.', $filtered, 'The anchor_courses_no_access_message text rides along.' );
		$this->assertStringNotContainsString( 'Finish the earlier lessons', $filtered );
	}

	public function test_an_enrolled_learner_on_a_locked_lesson_is_told_to_finish_the_earlier_ones() {
		$course = $this->make_course( [ 'progression_mode' => 'sequential' ] );
		$first  = $this->make_lesson( [ 'completion_mode' => 'manual', 'required' => 1 ] );
		$second = $this->make_lesson( [ 'completion_mode' => 'manual', 'required' => 1 ] );
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $first ], [ 'type' => 'lesson', 'id' => $second ] ] ] ] );
		$user = $this->make_learner();
		Roles::grant_access( $user, $course );
		wp_set_current_user( $user );
		global $post;
		$post = get_post( $second );

		$filtered = apply_filters( 'the_content', 'Body.' );

		$this->assertStringContainsString( 'Finish the earlier lessons to unlock this one.', $filtered );
		$this->assertStringNotContainsString( 'not enrolled', $filtered );
	}

	/* ---------------------------------------------------------------------
	 * Minors
	 * ------------------------------------------------------------------- */

	/** Next/Prev skip quiz items: a quiz has no URL of its own. */
	public function test_next_and_previous_skip_quiz_items() {
		$course = $this->make_course( [ 'progression_mode' => 'free' ] );
		$one    = $this->make_lesson( [], 'One' );
		$quiz   = $this->make_quiz( [], 'Between' );
		$two    = $this->make_lesson( [], 'Two' );
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [
			[ 'type' => 'lesson', 'id' => $one ], [ 'type' => 'quiz', 'id' => $quiz ], [ 'type' => 'lesson', 'id' => $two ],
		] ] ] );
		$user = $this->make_learner();
		Roles::grant_access( $user, $course );
		wp_set_current_user( $user );
		global $post;

		$post = get_post( $one );
		$html = Module::instance()->shortcodes->render_lesson( $one );
		$this->assertStringContainsString( esc_url( Access::lesson_url( $two, $course ) ), $html );
		$this->assertStringNotContainsString( get_permalink( $quiz ), $html );

		$post = get_post( $two );
		$html = Module::instance()->shortcodes->render_lesson( $two );
		$this->assertStringContainsString( esc_url( Access::lesson_url( $one, $course ) ), $html );
	}

	public function test_the_content_guard_tolerates_null_content() {
		$this->assertSame( '', ContentGuard::filter_content( null ) );
	}

	public function test_lessons_are_excluded_from_the_core_sitemap() {
		$types = apply_filters(
			'wp_sitemaps_post_types',
			[ 'post' => get_post_type_object( 'post' ), LessonPostType::CPT => get_post_type_object( LessonPostType::CPT ) ]
		);

		$this->assertArrayNotHasKey( LessonPostType::CPT, $types );
		$this->assertArrayHasKey( 'post', $types );
	}
}
