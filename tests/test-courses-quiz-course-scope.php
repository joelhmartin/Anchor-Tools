<?php
/**
 * Audit F05 (docs/audits/2026-09-25-events-lms-audit.md): a quiz shared by two
 * courses keeps a separate attempt lifecycle per course.
 *
 * Regression-acceptance list: an open, exhausted, reset or completed attempt
 * in course A must not silently change course B's lifecycle - verified in
 * both directions, for the SAME learner - and the REST routes carry course
 * context.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Database\ProgressRepository;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Rest\Routes;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Services\QuizService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Quiz_Course_Scope extends Anchor_Courses_TestCase {

	private QuizService $quizzes;
	private ProgressService $progress;
	private int $user;
	private int $a;
	private int $b;
	private int $quiz;
	private string $q1;

	public function set_up() {
		parent::set_up();
		$enrollments    = new EnrollmentService();
		$this->progress = new ProgressService( $enrollments );
		$this->quizzes  = new QuizService( $this->progress, $enrollments );

		$this->user = $this->make_learner();
		$this->a    = $this->make_course( [ 'progression_mode' => 'free' ], 'Course A' );
		$this->b    = $this->make_course( [ 'progression_mode' => 'free' ], 'Course B' );
		$this->quiz = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 1 ] ] );
		$saved      = Questions::save( $this->quiz, [
			[ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
			  'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ],
		] );
		$this->q1 = $saved[0]['id'];
		foreach ( [ $this->a, $this->b ] as $course ) {
			Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
			Roles::grant_access( $this->user, $course );
		}
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_now' );
		parent::tear_down();
	}

	/** @return array{0:int,1:int} */
	public function directions(): array {
		return [ 'A then B' => [ 'a', 'b' ], 'B then A' => [ 'b', 'a' ] ];
	}

	/**
	 * The audit's reproduction: an open attempt started in one course must
	 * not be what the other course resumes.
	 *
	 * @dataProvider directions
	 */
	public function test_an_open_attempt_in_one_course_is_not_resumed_by_the_other( $first, $second ) {
		$in_first  = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->{$first} );
		$in_second = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->{$second} );

		$this->assertNotWPError( $in_second );
		$this->assertNotSame( $in_first->id, $in_second->id, 'A separate attempt, not the other course\'s.' );
		$this->assertSame( $this->{$second}, $in_second->course_id );

		// Submission records progress against the course it was started in.
		$this->quizzes->submit( $in_second->id, [ $this->q1 => 'a2' ] );
		$this->assertTrue( ProgressRepository::find( $this->user, $this->{$second}, $this->quiz, 'quiz' )->is_complete() );
		$first_progress = ProgressRepository::find( $this->user, $this->{$first}, $this->quiz, 'quiz' );
		$this->assertFalse( $first_progress && $first_progress->is_complete(), 'The other course\'s progress is untouched.' );
		$this->assertSame( 'in_progress', QuizAttemptRepository::find( $in_first->id )->status );
	}

	/** @dataProvider directions */
	public function test_exhausting_attempts_in_one_course_does_not_exhaust_the_other( $first, $second ) {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->{$first} );
		$this->quizzes->submit( $attempt->id, [ $this->q1 => 'a1' ] ); // Fail - max_attempts is 1.

		$this->assertSame( 'no_attempts_remaining', $this->quizzes->start_attempt( $this->user, $this->quiz, $this->{$first} )->get_error_code() );
		$this->assertSame( 0, $this->quizzes->attempts_remaining( $this->user, $this->quiz, $this->{$first} ) );
		$this->assertSame( 1, $this->quizzes->attempts_remaining( $this->user, $this->quiz, $this->{$second} ) );
		$this->assertNotWPError( $this->quizzes->start_attempt( $this->user, $this->quiz, $this->{$second} ) );
	}

	/** @dataProvider directions */
	public function test_a_reset_in_one_course_leaves_the_others_attempts_alone( $first, $second ) {
		$other = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->{$second} );
		$this->quizzes->start_attempt( $this->user, $this->quiz, $this->{$first} );

		$this->progress->reset_course( $this->user, $this->{$first} );

		$this->assertSame( 'in_progress', QuizAttemptRepository::find( $other->id )->status );
		$this->assertSame( 1, $this->quizzes->attempts_used( $this->user, $this->quiz, $this->{$second} ) );
		$this->assertSame( 0, $this->quizzes->attempts_used( $this->user, $this->quiz, $this->{$first} ) );
	}

	/** @dataProvider directions */
	public function test_retry_delay_and_best_attempt_are_per_course( $first, $second ) {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'max_attempts' => 0, 'retry_delay_seconds' => 3600 ] );
		add_filter( 'anchor_courses_now', static fn () => 1767225600 );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->{$first} );
		$this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2' ] );

		$this->assertGreaterThan( 0, $this->quizzes->retry_available_at( $this->user, $this->quiz, $this->{$first} ) );
		$this->assertSame( 0, $this->quizzes->retry_available_at( $this->user, $this->quiz, $this->{$second} ) );
		$this->assertNotNull( $this->quizzes->best_attempt( $this->user, $this->quiz, $this->{$first} ) );
		$this->assertNull( $this->quizzes->best_attempt( $this->user, $this->quiz, $this->{$second} ), 'A completed attempt in one course is not the other\'s best.' );
	}

	/** Attempt numbers run per (user, course, quiz): attempt 1 exists once in each course. */
	public function test_attempt_numbers_are_per_course() {
		$in_a = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->a );
		$in_b = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->b );
		$this->assertSame( 1, $in_a->attempt_number );
		$this->assertSame( 1, $in_b->attempt_number );
	}

	/* --- REST: course context rides along ---------------------------------- */

	private function rest( string $method, string $route, array $body = [] ): WP_REST_Response {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( $method, '/' . Routes::NAMESPACE . $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $wp_rest_server->dispatch( $request );
	}

	public function test_rest_refuses_to_act_on_an_attempt_from_another_course() {
		wp_set_current_user( $this->user );
		$start = $this->rest( 'POST', '/quizzes/' . $this->quiz . '/attempts', [ 'course_id' => $this->a ] );
		$this->assertSame( 201, $start->get_status() );
		$id = $start->get_data()['attempt']['id'];

		$answer = $this->rest( 'POST', '/quiz-attempts/' . $id . '/answer', [ 'question_id' => $this->q1, 'value' => 'a2', 'course_id' => $this->b ] );
		$this->assertSame( 409, $answer->get_status() );
		$this->assertSame( 'attempt_course_mismatch', $answer->get_data()['code'] );

		$submit = $this->rest( 'POST', '/quiz-attempts/' . $id . '/submit', [ 'answers' => [ $this->q1 => 'a2' ], 'course_id' => $this->b ] );
		$this->assertSame( 409, $submit->get_status() );
		$this->assertSame( 'in_progress', QuizAttemptRepository::find( $id )->status, 'Nothing was graded into the wrong course.' );

		$read = $this->rest( 'GET', '/quiz-attempts/' . $id, [ 'course_id' => $this->b ] );
		$this->assertSame( 409, $read->get_status() );

		// The matching course (or none - the row's own course is authoritative) works.
		$this->assertSame( 200, $this->rest( 'POST', '/quiz-attempts/' . $id . '/answer', [ 'question_id' => $this->q1, 'value' => 'a2', 'course_id' => $this->a ] )->get_status() );
		$this->assertSame( 200, $this->rest( 'GET', '/quiz-attempts/' . $id )->get_status() );
	}
}
