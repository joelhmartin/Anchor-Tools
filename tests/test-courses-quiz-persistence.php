<?php
/**
 * Audit F03 (docs/audits/2026-09-25-events-lms-audit.md): a failed grading
 * write must never look successful or advance progress.
 *
 * Regression-acceptance list: fail ONLY the grading UPDATE after the claim
 * succeeds -> an error/retryable state, no quiz-pass hook, no completed quiz
 * progress, no dependent completion effects; a healthy retry grades once.
 * Plus the bounded recovery of a stale `submitted` claim (a process that died
 * between claim and grade).
 *
 * The failure is injected against the real test database: a `query` filter
 * turns exactly the grading UPDATE into invalid SQL, so $wpdb->update()
 * genuinely returns false while the old row stays readable - the audit's
 * exact precondition.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Database\ProgressRepository;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Services\CompletionService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Services\QuizService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Quiz_Persistence extends Anchor_Courses_TestCase {

	private QuizService $quizzes;
	private CompletionService $completion;
	private int $user;
	private int $course;
	private int $quiz;
	private string $q1;

	/** @var callable|null */
	private $breaker = null;

	/** @var string[] */
	private array $events = [];

	public function set_up() {
		parent::set_up();
		$enrollments      = new EnrollmentService();
		$progress         = new ProgressService( $enrollments );
		$this->completion = new CompletionService( $enrollments, $progress );
		$progress->set_completion_service( $this->completion );
		$this->quizzes = new QuizService( $progress, $enrollments );

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '1' ] );
		$this->quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 0 ] ] );
		$saved        = Questions::save( $this->quiz, [
			[ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
			  'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ],
		] );
		$this->q1 = $saved[0]['id'];
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		Roles::grant_access( $this->user, $this->course );

		foreach ( [ 'submitted', 'passed', 'failed' ] as $event ) {
			add_action( 'anchor_courses_quiz_' . $event, function () use ( $event ) { $this->events[] = $event; } );
		}
		add_action( 'anchor_courses_course_completed', function () { $this->events[] = 'course_completed'; } );
	}

	public function tear_down() {
		$this->heal();
		remove_all_filters( 'anchor_courses_now' );
		foreach ( [ 'submitted', 'passed', 'failed' ] as $event ) {
			remove_all_actions( 'anchor_courses_quiz_' . $event );
		}
		remove_all_actions( 'anchor_courses_course_completed' );
		parent::tear_down();
	}

	private function break_grading_update(): void {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$this->breaker = static function ( $query ) {
			return preg_match( "/^UPDATE `\\S*anchor_courses_quiz_attempts` SET `status` = 'graded'/", (string) $query )
				? 'SELECT anchor_courses_injected_failure FROM no_such_table_anchor'
				: $query;
		};
		add_filter( 'query', $this->breaker );
	}

	private function heal(): void {
		global $wpdb;
		if ( $this->breaker ) {
			remove_filter( 'query', $this->breaker );
			$this->breaker = null;
		}
		$wpdb->suppress_errors( false );
	}

	private function freeze( int $timestamp ): void {
		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn () => $timestamp );
	}

	/** The repository reports a database error as null; a no-change update is still a success. */
	public function test_update_returns_null_on_a_database_error_but_not_on_a_no_change_update() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$same = QuizAttemptRepository::update( $attempt->id, [ 'answers' => $attempt->answers ] );
		$this->assertInstanceOf( QuizAttempt::class, $same, 'Zero rows changed is not a failure.' );

		$this->break_grading_update();
		$this->assertNull( QuizAttemptRepository::update( $attempt->id, [ 'status' => 'graded' ] ) );
	}

	/** The audit's reproduction: the grading write fails after a successful claim. */
	public function test_a_failed_grading_write_is_an_error_and_advances_nothing() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$this->break_grading_update();
		$result = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2' ] );
		$this->heal();

		$this->assertWPError( $result, 'A grade that was not saved is not a result.' );
		$this->assertSame( 'save_failed', $result->get_error_code() );
		$this->assertSame( [], $this->events, 'No submitted/passed hook and no course completion.' );
		$quiz_progress = ProgressRepository::find( $this->user, $this->course, $this->quiz, 'quiz' );
		$this->assertFalse( $quiz_progress && $quiz_progress->is_complete(), 'No completed quiz progress.' );
		$this->assertFalse( $this->completion->is_complete( $this->user, $this->course ) );
		$this->assertSame( 'in_progress', QuizAttemptRepository::find( $attempt->id )->status, 'The claim is released - retryable.' );

		// A healthy retry grades exactly once.
		$graded = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2' ] );
		$this->assertInstanceOf( QuizAttempt::class, $graded );
		$this->assertSame( 'graded', $graded->status );
		$this->assertSame( [ 'submitted', 'passed', 'course_completed' ], $this->events );
		$this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2' ] );
		$this->assertSame( [ 'submitted', 'passed', 'course_completed' ], $this->events, 'Graded once.' );
	}

	/**
	 * A process that died between the claim and the grade leaves the attempt
	 * `submitted` forever: counted, never graded, invisible to the timer
	 * sweep. After 5 minutes the claim is stale and is re-opened, answers
	 * intact. A fresh claim (a submit still running) is left alone.
	 */
	public function test_a_stale_submitted_claim_is_reopened_with_its_answers() {
		$t0 = 1767225600; // 2026-01-01 00:00:00 UTC.
		$this->freeze( $t0 );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );
		$this->assertTrue( QuizAttemptRepository::transition( $attempt->id, 'in_progress', 'submitted' ) ); // ...then the process died.

		$this->freeze( $t0 + 4 * MINUTE_IN_SECONDS );
		$this->quizzes->sweep_expired_attempts();
		$this->assertSame( 'submitted', QuizAttemptRepository::find( $attempt->id )->status, 'Four minutes: maybe still grading.' );

		$this->freeze( $t0 + 6 * MINUTE_IN_SECONDS );
		$this->quizzes->sweep_expired_attempts();
		$reopened = QuizAttemptRepository::find( $attempt->id );
		$this->assertSame( 'in_progress', $reopened->status );
		$this->assertSame( [ $this->q1 => [ 'a2' ] ], $reopened->answers, 'The learner\'s stored answers survive.' );

		$graded = $this->quizzes->submit( $attempt->id );
		$this->assertSame( 'graded', $graded->status );
	}

	/** The learner's own next start recovers their stale claim without waiting for cron. */
	public function test_starting_again_resumes_a_stale_submitted_attempt() {
		$t0 = 1767225600;
		$this->freeze( $t0 );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		QuizAttemptRepository::transition( $attempt->id, 'in_progress', 'submitted' );

		$this->freeze( $t0 + 10 * MINUTE_IN_SECONDS );
		$again = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->assertSame( $attempt->id, $again->id, 'Resumed, not a second attempt.' );
		$this->assertSame( 'in_progress', $again->status );
	}

	/** A failed grade reaches the browser as a retryable server error, not a 400. */
	public function test_save_failed_maps_to_a_retryable_status() {
		$response = \Anchor\Courses\Rest\Routes::error_response( new WP_Error( 'save_failed', 'x' ) );
		$this->assertSame( 503, $response->get_status() );
	}
}
