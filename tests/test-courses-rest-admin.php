<?php
/**
 * Anchor Courses - admin REST routes and their capability gates (brief 14, 24, Task 34).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Rest\Routes;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Rest_Admin extends Anchor_Courses_TestCase {

	private WP_REST_Server $server;
	private int $admin;
	private int $learner;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->admin   = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$this->learner = $this->make_learner( [ 'display_name' => 'Ada' ] );
		$this->course  = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '2', 'certificate_enabled' => 1 ], 'Laser Safety' );
		$this->lesson  = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_now' );
		parent::tear_down();
	}

	private function request( string $method, string $route, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . Routes::NAMESPACE . $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Roles::grant_access() is the one enrol path (progress.md ruling) - the
	 * enrolment row it writes via the add_user_role listener is what makes
	 * is_enrolled() true, which complete_lesson() requires. Calling
	 * EnrollmentService::enroll() directly, bypassing the role, leaves the
	 * learner "enrolled" in the row but not is_enrolled() - complete_lesson()
	 * then silently refuses (not_enrolled) and nothing completes.
	 */
	private function complete_the_course(): void {
		Roles::grant_access( $this->learner, $this->course, 'manual' );

		$progress = new ProgressService( new EnrollmentService() );
		$progress->set_completion_service( $this->courses()->completion );
		$progress->complete_lesson( $this->learner, $this->course, $this->lesson );
	}

	/** Same as complete_the_course(), but with the clock pinned so completed_at/awarded_at are known. */
	private function complete_the_course_on( string $mysql_datetime_utc ): void {
		\add_filter( 'anchor_courses_now', static fn() => \strtotime( $mysql_datetime_utc . ' UTC' ) );
		$this->complete_the_course();
		remove_all_filters( 'anchor_courses_now' );
	}

	public function test_the_four_admin_routes_are_registered() {
		$routes = array_keys( $this->server->get_routes() );
		foreach ( [
			'/admin/courses/(?P<id>\d+)/learners',
			'/admin/users/(?P<id>\d+)/courses',
			'/admin/reports/completions',
			'/admin/reports/credits',
		] as $route ) {
			$this->assertContains( '/' . Routes::NAMESPACE . $route, $routes, "Missing route {$route}" );
		}
	}

	public function test_a_learner_is_refused_every_admin_route() {
		wp_set_current_user( $this->learner );
		foreach ( [
			"/admin/courses/{$this->course}/learners",
			"/admin/users/{$this->learner}/courses",
			'/admin/reports/completions',
			'/admin/reports/credits',
		] as $route ) {
			$this->assertSame( 403, $this->request( 'GET', $route )->get_status(), "Route {$route} was not gated." );
		}
	}

	public function test_a_logged_out_visitor_gets_401() {
		wp_set_current_user( 0 );
		foreach ( [
			"/admin/courses/{$this->course}/learners",
			"/admin/users/{$this->learner}/courses",
			'/admin/reports/completions',
			'/admin/reports/credits',
		] as $route ) {
			$this->assertSame( 401, $this->request( 'GET', $route )->get_status(), "Route {$route} did not 401 logged out." );
		}
	}

	public function test_an_admin_sees_the_learner_roster_for_a_course() {
		$this->complete_the_course();
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', "/admin/courses/{$this->course}/learners" )->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Ada', $data[0]['display_name'] );
		$this->assertSame( 100.0, $data[0]['percent'] );
		$this->assertSame( 2.0, $data[0]['credits'] );
	}

	public function test_the_learners_route_404s_for_an_unknown_course() {
		wp_set_current_user( $this->admin );
		$missing = $this->course + 999999;

		$response = $this->request( 'GET', "/admin/courses/{$missing}/learners" );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'no_course', $response->get_data()['code'] );
	}

	public function test_an_admin_sees_one_users_courses() {
		$this->complete_the_course();
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', "/admin/users/{$this->learner}/courses" )->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Laser Safety', $data[0]['course_title'] );
	}

	public function test_the_user_courses_route_404s_for_an_unknown_user() {
		wp_set_current_user( $this->admin );

		$response = $this->request( 'GET', '/admin/users/999999/courses' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'no_user', $response->get_data()['code'] );
	}

	public function test_the_completions_report_counts_completed_enrolments() {
		$this->complete_the_course();
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', '/admin/reports/completions', [ 'course_id' => $this->course ] )->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertCount( 1, $data['rows'] );
		$this->assertSame( 'Ada', $data['rows'][0]['display_name'] );
		$this->assertSame( 'Laser Safety', $data['rows'][0]['course_title'] );
	}

	public function test_the_completions_report_is_scoped_by_date_range() {
		$this->complete_the_course_on( '2026-06-15 12:00:00' );
		wp_set_current_user( $this->admin );

		$in_range = $this->request(
			'GET',
			'/admin/reports/completions',
			[ 'from' => '2026-06-01', 'to' => '2026-06-30' ]
		)->get_data();
		$this->assertSame( 1, $in_range['total'] );

		$out_of_range = $this->request(
			'GET',
			'/admin/reports/completions',
			[ 'from' => '2026-07-01', 'to' => '2026-07-31' ]
		)->get_data();
		$this->assertSame( 0, $out_of_range['total'] );
	}

	public function test_the_credits_report_totals_awarded_credits() {
		$this->complete_the_course();
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', '/admin/reports/credits', [ 'course_id' => $this->course ] )->get_data();

		$this->assertSame( 2.0, $data['total_credits'] );
		$this->assertCount( 1, $data['rows'] );
		$this->assertSame( 'Ada', $data['rows'][0]['display_name'] );
	}

	public function test_the_credits_report_covers_every_course_when_none_is_named() {
		$second_learner = $this->make_learner( [ 'display_name' => 'Bea' ] );
		$second_course  = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '3', 'certificate_enabled' => 1 ], 'Second Course' );
		$second_lesson  = $this->make_lesson();
		Curriculum::save( $second_course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $second_lesson ] ] ] ] );

		Roles::grant_access( $second_learner, $second_course, 'manual' );

		$progress = new ProgressService( new EnrollmentService() );
		$progress->set_completion_service( $this->courses()->completion );
		$progress->complete_lesson( $second_learner, $second_course, $second_lesson );

		$this->complete_the_course();
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', '/admin/reports/credits' )->get_data();

		$this->assertSame( 5.0, $data['total_credits'] );
		$this->assertCount( 2, $data['rows'] );

		// $second_course's role is not in $this->minted_courses (make_course()
		// tracked it, so the base tear_down()'s course-role regex still strips
		// it - no manual remove_role() needed here).
	}

	public function test_the_credits_report_is_scoped_by_date_range() {
		$this->complete_the_course_on( '2026-06-15 12:00:00' );
		wp_set_current_user( $this->admin );

		$in_range = $this->request(
			'GET',
			'/admin/reports/credits',
			[ 'from' => '2026-06-01', 'to' => '2026-06-30' ]
		)->get_data();
		$this->assertSame( 2.0, $in_range['total_credits'] );

		$out_of_range = $this->request(
			'GET',
			'/admin/reports/credits',
			[ 'from' => '2026-07-01', 'to' => '2026-07-31' ]
		)->get_data();
		$this->assertSame( 0.0, $out_of_range['total_credits'] );
		$this->assertCount( 0, $out_of_range['rows'] );
	}

	public function test_the_credits_report_uses_the_credits_capability_not_reports() {
		$reporter = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		get_user_by( 'id', $reporter )->add_cap( 'view_anchor_course_reports' );
		wp_set_current_user( $reporter );

		$this->assertSame( 200, $this->request( 'GET', '/admin/reports/completions' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/admin/reports/credits' )->get_status() );
	}

	/** brief 25: no admin report may leak a lesson body or a quiz's correct-answer data. */
	public function test_no_admin_report_response_leaks_post_content_or_a_correct_key() {
		update_post_meta( $this->lesson, '_anchor_lesson_body_secret_marker', 'unused' );
		wp_update_post( [ 'ID' => $this->lesson, 'post_content' => 'SECRET LESSON BODY' ] );

		$this->complete_the_course();
		wp_set_current_user( $this->admin );

		$responses = [
			$this->request( 'GET', "/admin/courses/{$this->course}/learners" ),
			$this->request( 'GET', "/admin/users/{$this->learner}/courses" ),
			$this->request( 'GET', '/admin/reports/completions' ),
			$this->request( 'GET', '/admin/reports/credits' ),
		];

		foreach ( $responses as $response ) {
			$json = (string) wp_json_encode( $response->get_data() );
			$this->assertStringNotContainsString( 'post_content', $json );
			$this->assertStringNotContainsString( 'SECRET LESSON BODY', $json );
			$this->assertStringNotContainsString( '"correct"', $json );
		}
	}
}
