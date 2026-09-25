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
use Anchor\Courses\Services\EnrollmentService;

/** @group courses */
class Test_Courses_Content_Guard extends Anchor_Courses_TestCase {

	private int $course;
	private int $lesson;

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
		( new EnrollmentService() )->enroll( $user, $this->course );

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
		( new EnrollmentService() )->enroll( $user, $this->course );

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

		( new EnrollmentService() )->enroll( $user, $this->course );

		$this->assertTrue( Access::can_view_lesson( $this->lesson, $user ) );
	}

	public function test_access_can_view_lesson_is_false_when_logged_out() {
		wp_set_current_user( 0 );
		$this->assertFalse( Access::can_view_lesson( $this->lesson ) );
	}
}
