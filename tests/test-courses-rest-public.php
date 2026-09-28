<?php
/**
 * Anchor Courses - public and learner REST routes (brief 14, 25).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Rest\Routes;
use Anchor\Courses\Services\CreditService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Rest_Public extends Anchor_Courses_TestCase {

	private WP_REST_Server $server;
	private int $user;
	private int $course;
	private int $lesson;
	private int $quiz;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '2' ], 'Laser Safety' );
		$this->lesson = $this->make_lesson( [], 'Optics' );
		$this->quiz   = $this->make_quiz( [], 'Final Quiz' );

		Questions::save(
			$this->quiz,
			[ [ 'type' => 'true_false', 'prompt' => 'Secret?', 'points' => 1,
			    'answers' => [ [ 'text' => 'True', 'correct' => true ], [ 'text' => 'False', 'correct' => false ] ] ] ]
		);

		Curriculum::save(
			$this->course,
			[ [ 'title' => 'Fundamentals', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->lesson ],
				[ 'type' => 'quiz', 'id' => $this->quiz ],
			] ] ]
		);
	}

	public function tear_down() {
		remove_role( Roles::access_slug( $this->course ) );
		parent::tear_down();
	}

	private function request( string $method, string $route, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . Routes::NAMESPACE . $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	public function test_every_brief_route_is_registered() {
		$routes = array_keys( $this->server->get_routes() );
		foreach ( [
			'/courses', '/courses/(?P<id>\d+)', '/courses/(?P<id>\d+)/curriculum',
			'/me/courses', '/me/courses/(?P<id>\d+)/progress', '/lessons/(?P<id>\d+)/start',
			'/lessons/(?P<id>\d+)/complete', '/me/certificates', '/me/credits',
		] as $route ) {
			$this->assertContains( '/' . Routes::NAMESPACE . $route, $routes, "Missing route {$route}" );
		}

		$this->assertNotContains(
			'/' . Routes::NAMESPACE . '/courses/(?P<id>\d+)/enroll',
			$routes,
			'A REST enrol route is self-enrolment with a JSON body (design spec 7).'
		);
	}

	public function test_the_course_index_lists_published_courses() {
		$response = $this->request( 'GET', '/courses' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Laser Safety', $response->get_data()[0]['title'] );
		$this->assertSame( 2.0, $response->get_data()[0]['ce_credits'] );
	}

	public function test_the_curriculum_route_never_leaks_quiz_questions() {
		$response = $this->request( 'GET', "/courses/{$this->course}/curriculum" );
		$json     = (string) wp_json_encode( $response->get_data() );

		$this->assertStringContainsString( 'Optics', $json );
		$this->assertStringContainsString( 'Final Quiz', $json );
		$this->assertStringNotContainsString( 'Secret?', $json, 'Quiz prompts belong to an attempt, not the curriculum.' );
		$this->assertStringNotContainsString( 'correct', $json );
	}

	/** POSTing to the route that used to exist must 404, not enrol anybody. */
	public function test_there_is_no_enrol_endpoint() {
		wp_set_current_user( $this->user );

		$response = $this->request( 'POST', "/courses/{$this->course}/enroll" );

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( ( new EnrollmentService() )->is_enrolled( $this->user, $this->course ) );
	}

	public function test_me_courses_only_ever_returns_the_signed_in_learner() {
		$other = $this->make_learner();
		Roles::grant_access( $other, $this->course, 'manual' );

		wp_set_current_user( $this->user );
		$response = $this->request( 'GET', '/me/courses', [ 'user_id' => $other ] );

		$this->assertSame( [], $response->get_data(), 'A user_id parameter must be ignored.' );
	}

	public function test_lesson_start_and_complete_move_progress() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		wp_set_current_user( $this->user );

		$this->assertSame( 200, $this->request( 'POST', "/lessons/{$this->lesson}/start", [ 'course_id' => $this->course ] )->get_status() );

		$done = $this->request( 'POST', "/lessons/{$this->lesson}/complete", [ 'course_id' => $this->course ] );
		$this->assertSame( 200, $done->get_status() );

		$progress = $this->request( 'GET', "/me/courses/{$this->course}/progress" );
		$this->assertSame( 50.0, $progress->get_data()['percent'] );
	}

	/** Task 33 review: progress for a course you are not enrolled in is 403; an unknown/draft course is 404. */
	public function test_progress_is_403_when_unenrolled_and_404_for_an_invisible_course() {
		wp_set_current_user( $this->make_learner() );
		$this->assertSame( 403, $this->request( 'GET', "/me/courses/{$this->course}/progress" )->get_status() );
		$draft = self::factory()->post->create( [ 'post_type' => \Anchor\Courses\Content\CoursePostType::CPT, 'post_status' => 'draft' ] );
		$this->assertSame( 404, $this->request( 'GET', "/me/courses/{$draft}/progress" )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/me/courses/999999/progress' )->get_status() );
	}

	/** Task 33 review: a draft lesson staged into a live course does not surface its title publicly. */
	public function test_curriculum_hides_unpublished_items() {
		$draft = self::factory()->post->create( [ 'post_type' => \Anchor\Courses\Content\LessonPostType::CPT, 'post_status' => 'draft', 'post_title' => 'Secret Draft Lesson' ] );
		$modules = \Anchor\Courses\Content\Curriculum::get( $this->course );
		$modules[0]['items'][] = [ 'type' => 'lesson', 'id' => $draft, 'required' => true ];
		\Anchor\Courses\Content\Curriculum::save( $this->course, $modules );

		wp_set_current_user( 0 );
		$body = wp_json_encode( $this->request( 'GET', "/courses/{$this->course}/curriculum" )->get_data() );
		$this->assertStringNotContainsString( 'Secret Draft Lesson', $body );
		$this->assertStringNotContainsString( (string) $draft, $body );
	}

	/**
	 * PR36 bot review finding f: `summary()`'s `item_count` must use the
	 * same `publish` filter `curriculum()` applies, so the catalogue count
	 * can never disagree with - or reveal the existence of - a draft item
	 * the curriculum route itself hides.
	 */
	public function test_item_count_excludes_unpublished_items() {
		$draft   = self::factory()->post->create( [ 'post_type' => \Anchor\Courses\Content\LessonPostType::CPT, 'post_status' => 'draft', 'post_title' => 'Secret Draft Lesson' ] );
		$modules = \Anchor\Courses\Content\Curriculum::get( $this->course );
		$modules[0]['items'][] = [ 'type' => 'lesson', 'id' => $draft, 'required' => true ];
		\Anchor\Courses\Content\Curriculum::save( $this->course, $modules );

		$response = $this->request( 'GET', "/courses/{$this->course}" );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			2,
			$response->get_data()['item_count'],
			'The draft lesson must not inflate item_count past the two published items curriculum() itself lists.'
		);
	}

	public function test_completing_a_lesson_while_unenrolled_is_403() {
		wp_set_current_user( $this->make_learner() );
		$response = $this->request( 'POST', "/lessons/{$this->lesson}/complete", [ 'course_id' => $this->course ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'not_enrolled', $response->get_data()['code'] );
	}

	/**
	 * PR36 round 3 (Codex): an enrolled learner who knows or guesses a
	 * draft lesson's id in their own course must not be able to create
	 * progress against it through either write route - `ProgressService`'s
	 * shared publish guard refuses before enrolment/curriculum are even
	 * consulted, so both routes 404 with `no_lesson` rather than the
	 * generic 403 `locked`.
	 */
	public function test_start_and_complete_404_for_a_draft_lesson_in_the_learners_own_course() {
		$draft   = self::factory()->post->create( [ 'post_type' => \Anchor\Courses\Content\LessonPostType::CPT, 'post_status' => 'draft', 'post_title' => 'Draft Lesson' ] );
		$modules = Curriculum::get( $this->course );
		$modules[0]['items'][] = [ 'type' => 'lesson', 'id' => $draft, 'required' => false ];
		Curriculum::save( $this->course, $modules );

		Roles::grant_access( $this->user, $this->course, 'manual' );
		wp_set_current_user( $this->user );

		$start = $this->request( 'POST', "/lessons/{$draft}/start", [ 'course_id' => $this->course ] );
		$this->assertSame( 404, $start->get_status() );
		$this->assertSame( 'no_lesson', $start->get_data()['code'] );

		$complete = $this->request( 'POST', "/lessons/{$draft}/complete", [ 'course_id' => $this->course ] );
		$this->assertSame( 404, $complete->get_status() );
		$this->assertSame( 'no_lesson', $complete->get_data()['code'] );

		$this->assertNull(
			\Anchor\Courses\Database\ProgressRepository::find( $this->user, $this->course, $draft, 'lesson' ),
			'Neither write route may create a progress row for unpublished content.'
		);
	}

	/** Same guard, a `private` lesson rather than `draft` (PR36 round 3, Codex). */
	public function test_start_and_complete_404_for_a_private_lesson_in_the_learners_own_course() {
		$private = self::factory()->post->create( [ 'post_type' => \Anchor\Courses\Content\LessonPostType::CPT, 'post_status' => 'private', 'post_title' => 'Private Lesson' ] );
		$modules = Curriculum::get( $this->course );
		$modules[0]['items'][] = [ 'type' => 'lesson', 'id' => $private, 'required' => false ];
		Curriculum::save( $this->course, $modules );

		Roles::grant_access( $this->user, $this->course, 'manual' );
		wp_set_current_user( $this->user );

		$start = $this->request( 'POST', "/lessons/{$private}/start", [ 'course_id' => $this->course ] );
		$this->assertSame( 404, $start->get_status() );
		$this->assertSame( 'no_lesson', $start->get_data()['code'] );

		$complete = $this->request( 'POST', "/lessons/{$private}/complete", [ 'course_id' => $this->course ] );
		$this->assertSame( 404, $complete->get_status() );
		$this->assertSame( 'no_lesson', $complete->get_data()['code'] );

		$this->assertNull(
			\Anchor\Courses\Database\ProgressRepository::find( $this->user, $this->course, $private, 'lesson' ),
			'Neither write route may create a progress row for unpublished content.'
		);
	}

	public function test_me_credits_and_certificates_are_scoped_to_the_learner() {
		( new CreditService() )->award( $this->user, $this->course );

		wp_set_current_user( $this->user );
		$this->assertCount( 1, $this->request( 'GET', '/me/credits' )->get_data()['credits'] );
		$this->assertSame( 2.0, $this->request( 'GET', '/me/credits' )->get_data()['total'] );

		wp_set_current_user( $this->make_learner() );
		$this->assertCount( 0, $this->request( 'GET', '/me/credits' )->get_data()['credits'] );
		$this->assertCount( 0, $this->request( 'GET', '/me/certificates' )->get_data() );
	}

	public function test_a_single_course_response_carries_no_learner_data_when_logged_out() {
		wp_set_current_user( 0 );
		$data = $this->request( 'GET', "/courses/{$this->course}" )->get_data();

		$this->assertArrayNotHasKey( 'progress', $data );
		$this->assertArrayNotHasKey( 'enrollment', $data );
	}

	/** brief 25: no route in this controller pair may expose a lesson's body or a quiz's correct-answer data. */
	public function test_no_response_in_this_suite_ever_leaks_post_content_or_a_correct_key() {
		wp_set_current_user( $this->user );
		Roles::grant_access( $this->user, $this->course, 'manual' );

		$responses = [
			$this->request( 'GET', '/courses' ),
			$this->request( 'GET', "/courses/{$this->course}" ),
			$this->request( 'GET', "/courses/{$this->course}/curriculum" ),
			$this->request( 'GET', '/me/courses' ),
			$this->request( 'GET', "/me/courses/{$this->course}/progress" ),
			$this->request( 'GET', '/me/certificates' ),
			$this->request( 'GET', '/me/credits' ),
		];

		foreach ( $responses as $response ) {
			$json = (string) wp_json_encode( $response->get_data() );
			$this->assertStringNotContainsString( 'post_content', $json );
			$this->assertStringNotContainsString( '"correct"', $json );
			$this->assertStringNotContainsString( 'Secret?', $json );
		}
	}

	public function test_me_routes_require_login() {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( 'GET', '/me/courses' )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', "/me/courses/{$this->course}/progress" )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', "/lessons/{$this->lesson}/start", [ 'course_id' => $this->course ] )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', "/lessons/{$this->lesson}/complete", [ 'course_id' => $this->course ] )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', '/me/certificates' )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', '/me/credits' )->get_status() );
	}

	public function test_unknown_course_ids_404() {
		$missing = $this->course + 999999;
		$this->assertSame( 404, $this->request( 'GET', "/courses/{$missing}" )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', "/courses/{$missing}/curriculum" )->get_status() );
	}
}
