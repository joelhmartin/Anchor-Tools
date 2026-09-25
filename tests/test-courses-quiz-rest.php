<?php
/**
 * Anchor Courses - quiz REST surface (brief 14, 25; progress.md rule 8).
 *
 * QuizController is a thin boundary: every assertion here is either about the
 * HTTP contract (status codes, route registration, permission callbacks) or
 * about the one thing a REST layer must never get wrong for a graded quiz -
 * that a `correct`/`correct_ids` key never rides out to a learner except
 * exactly when the quiz's own show_correct_answers setting says it may.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Rest\Routes;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Quiz_Rest extends Anchor_Courses_TestCase {

	private WP_REST_Server $server;
	private int $user;
	private int $course;
	private int $quiz;
	private string $q1;
	private string $q2;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 2 ] ] );

		$saved = Questions::save(
			$this->quiz,
			[
				[ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
				  'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ],
				[ 'type' => 'single_choice', 'prompt' => 'Two?', 'points' => 1,
				  'answers' => [ [ 'id' => 'b1', 'text' => 'A', 'correct' => true ], [ 'id' => 'b2', 'text' => 'B', 'correct' => false ] ] ],
			]
		);
		$this->q1 = $saved[0]['id'];
		$this->q2 = $saved[1]['id'];

		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );

		// Roles::grant_access(), not EnrollmentService::enroll(): is_enrolled()
		// requires the access role, and enroll() alone does not currently grant
		// it (a sibling worktree is fixing that; see task-26-report.md).
		Roles::grant_access( $this->user, $this->course );
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

	/** Recursively assert that no array key anywhere in $data is 'correct' or 'correct_ids'. */
	private function assertNoCorrectKeyAnywhere( $data, string $path = '$' ): void {
		if ( ! is_array( $data ) ) {
			return;
		}
		foreach ( $data as $key => $value ) {
			$this->assertNotSame( 'correct', $key, "Found a 'correct' key at {$path}.{$key}" );
			$this->assertNotSame( 'correct_ids', $key, "Found a 'correct_ids' key at {$path}.{$key}" );
			$this->assertNoCorrectKeyAnywhere( $value, "{$path}.{$key}" );
		}
	}

	public function test_all_four_quiz_routes_are_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/' . Routes::NAMESPACE . '/quizzes/(?P<id>\d+)/attempts', $routes );
		$this->assertArrayHasKey( '/' . Routes::NAMESPACE . '/quiz-attempts/(?P<id>\d+)', $routes );
		$this->assertArrayHasKey( '/' . Routes::NAMESPACE . '/quiz-attempts/(?P<id>\d+)/answer', $routes );
		$this->assertArrayHasKey( '/' . Routes::NAMESPACE . '/quiz-attempts/(?P<id>\d+)/submit', $routes );
	}

	/** Brief 25: no route in this namespace may be wide open. */
	public function test_no_route_uses_return_true_as_its_permission_callback() {
		foreach ( $this->server->get_routes() as $route => $handlers ) {
			if ( 0 !== strpos( $route, '/' . Routes::NAMESPACE ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$this->assertNotSame(
					'__return_true',
					$handler['permission_callback'] ?? null,
					"Route {$route} is unprotected."
				);
			}
		}
	}

	public function test_starting_an_attempt_requires_login() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_status() );
	}

	public function test_a_learner_can_start_an_attempt() {
		wp_set_current_user( $this->user );
		$response = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['attempt']['attempt_number'] );
		$this->assertCount( 2, $data['questions'] );
		$this->assertArrayHasKey( 'deadline', $data );
		$this->assertArrayHasKey( 'server_now', $data );
		$this->assertArrayHasKey( 'attempts_remaining', $data );
	}

	/** The single most important assertion in the module. */
	public function test_the_start_response_never_contains_correct() {
		wp_set_current_user( $this->user );
		$response = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] );

		$this->assertNoCorrectKeyAnywhere( $response->get_data() );
	}

	public function test_a_refusal_becomes_a_403_with_the_service_error_code() {
		wp_set_current_user( $this->make_learner() ); // not enrolled
		$response = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'not_enrolled', $response->get_data()['code'] );
	}

	public function test_reading_another_learners_attempt_is_403_with_a_named_code() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		wp_set_current_user( $this->make_learner() );
		$response = $this->request( 'GET', "/quiz-attempts/{$attempt_id}" );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'attempt_not_yours', $response->get_data()['code'] );
	}

	public function test_answering_another_learners_attempt_is_403() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		wp_set_current_user( $this->make_learner() );
		$response = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/answer", [ 'question_id' => $this->q1, 'value' => 'a2' ] );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_submitting_another_learners_attempt_is_403() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		wp_set_current_user( $this->make_learner() );
		$response = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/submit", [] );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_saving_an_answer_then_submitting_grades_server_side() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		$saved = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/answer", [ 'question_id' => $this->q1, 'value' => 'a2' ] );
		$this->assertSame( 200, $saved->get_status() );

		$submitted = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/submit", [ 'answers' => [ $this->q2 => 'b1' ] ] );
		$this->assertSame( 200, $submitted->get_status() );
		$this->assertSame( 100.0, $submitted->get_data()['attempt']['score'] );
		$this->assertTrue( $submitted->get_data()['attempt']['passed'] );
	}

	public function test_answering_an_unknown_question_is_400() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		$response = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/answer", [ 'question_id' => 'not-a-real-question', 'value' => 'x' ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unknown_question', $response->get_data()['code'] );
	}

	public function test_answering_after_grading_is_409() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];
		$this->request( 'POST', "/quiz-attempts/{$attempt_id}/submit", [ 'answers' => [ $this->q1 => 'a2', $this->q2 => 'b1' ] ] );

		$response = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/answer", [ 'question_id' => $this->q1, 'value' => 'a1' ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'attempt_closed', $response->get_data()['code'] );
	}

	public function test_a_submitted_score_from_the_client_is_ignored() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		$submitted = $this->request(
			'POST',
			"/quiz-attempts/{$attempt_id}/submit",
			[ 'answers' => [ $this->q1 => 'a1' ], 'score' => 100, 'passed' => true ]
		);

		$this->assertSame( 0.0, $submitted->get_data()['attempt']['score'], 'Only the server may score an attempt.' );
		$this->assertFalse( $submitted->get_data()['attempt']['passed'] );
	}

	/**
	 * QuizService::submit() is idempotent (Task 25, ruling: a second submit()
	 * neither re-grades nor re-fires) - it returns the graded attempt
	 * unchanged, not a WP_Error. The REST layer therefore returns 200 with
	 * the original graded result on a resubmission, not 409: there is no
	 * error to report, and the client's second POST is treated the same as
	 * a read of the outcome it already produced.
	 */
	public function test_a_second_submit_is_a_200_with_the_original_graded_result_not_an_error() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		$first  = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/submit", [ 'answers' => [ $this->q1 => 'a2', $this->q2 => 'b1' ] ] );
		$second = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/submit", [ 'answers' => [ $this->q1 => 'a1', $this->q2 => 'b2' ] ] );

		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $first->get_data()['attempt']['score'], $second->get_data()['attempt']['score'] );
		$this->assertSame( 100.0, $second->get_data()['attempt']['score'], 'A resubmission must not overwrite a graded score.' );
	}

	public function test_a_graded_attempt_hides_grading_data_when_the_quiz_says_so() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'show_correct_answers' => 0 ] );
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];
		$this->request( 'POST', "/quiz-attempts/{$attempt_id}/submit", [ 'answers' => [ $this->q1 => 'a2', $this->q2 => 'b1' ] ] );

		$read = $this->request( 'GET', "/quiz-attempts/{$attempt_id}" );

		$this->assertArrayNotHasKey( 'grading_data', $read->get_data()['attempt'] );
		$this->assertNoCorrectKeyAnywhere( $read->get_data() );
	}

	public function test_a_graded_attempt_includes_grading_data_when_the_quiz_allows_it() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'show_correct_answers' => 1 ] );
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];
		$submitted  = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/submit", [ 'answers' => [ $this->q1 => 'a2', $this->q2 => 'b2' ] ] );

		$this->assertArrayHasKey( 'grading_data', $submitted->get_data()['attempt'] );
		$this->assertTrue( $submitted->get_data()['attempt']['grading_data'][ $this->q1 ]['correct'] );
	}

	public function test_an_unknown_attempt_is_404() {
		wp_set_current_user( $this->user );
		$this->assertSame( 404, $this->request( 'GET', '/quiz-attempts/999999' )->get_status() );
	}

	/** Brief 8.5: after the pinned deadline, the attempt's timer policy decides the outcome, server-side. */
	public function test_submitting_after_the_deadline_applies_the_expire_policy() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'expire' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		$response = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/submit", [ 'answers' => [ $this->q1 => 'a2', $this->q2 => 'b1' ] ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'expired', $response->get_data()['attempt']['status'] );
		$this->assertNull( $response->get_data()['attempt']['score'] );
	}

	public function test_a_no_answer_submit_body_defaults_to_an_empty_object() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		$response = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/submit" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0.0, $response->get_data()['attempt']['score'] );
	}
}
