<?php
/**
 * Anchor Courses - quiz attempt lifecycle (brief 8.1, 8.4).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Services\QuizService;

/** @group courses */
class Test_Courses_Quiz_Lifecycle extends Anchor_Courses_TestCase {

	private QuizService $quizzes;
	private int $user;
	private int $course;
	private int $quiz;

	public function set_up() {
		parent::set_up();
		$this->quizzes = new QuizService();

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 2 ] ] );

		Questions::save(
			$this->quiz,
			[
				[ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
				  'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ],
			]
		);

		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		// Through the one door: is_enrolled() needs the row AND the access role.
		\Anchor\Courses\Support\Roles::grant_access( $this->user, $this->course );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_can_start_quiz' );
		remove_all_filters( 'anchor_courses_now' );
		remove_all_actions( 'anchor_courses_quiz_started' );
		parent::tear_down();
	}

	public function test_start_creates_attempt_one_and_fires_the_action() {
		$fired = 0;
		add_action( 'anchor_courses_quiz_started', function () use ( &$fired ) { $fired++; }, 10, 4 );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$this->assertInstanceOf( QuizAttempt::class, $attempt );
		$this->assertSame( 1, $attempt->attempt_number );
		$this->assertSame( 'in_progress', $attempt->status );
		$this->assertSame( 1.0, $attempt->points_possible );
		$this->assertSame( 1, $fired );
	}

	/** Brief 26: a reload must resume, not consume another attempt. */
	public function test_starting_again_while_open_returns_the_same_attempt() {
		$first  = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$second = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( 1, $this->quizzes->attempts_used( $this->user, $this->quiz, $this->course ) );
	}

	public function test_max_attempts_is_enforced() {
		$a = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		QuizAttemptRepository::update( $a->id, [ 'status' => 'graded', 'score' => 0.0 ] );
		$b = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		QuizAttemptRepository::update( $b->id, [ 'status' => 'graded', 'score' => 0.0 ] );

		$refused = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$this->assertWPError( $refused );
		$this->assertSame( 'no_attempts_remaining', $refused->get_error_code() );
		$this->assertSame( 0, $this->quizzes->attempts_remaining( $this->user, $this->quiz, $this->course ) );
	}

	public function test_zero_max_attempts_means_unlimited() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'max_attempts' => 0 ] );

		for ( $i = 0; $i < 3; $i++ ) {
			$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
			$this->assertInstanceOf( QuizAttempt::class, $attempt );
			QuizAttemptRepository::update( $attempt->id, [ 'status' => 'graded', 'score' => 0.0 ] );
		}

		$this->assertSame( -1, $this->quizzes->attempts_remaining( $this->user, $this->quiz, $this->course ) );
	}

	/**
	 * Closes the Task 18 layering exception's second call site (pre-gate
	 * cleanup round): Frontend\Shortcodes::render_quiz() read
	 * QuizAttemptRepository::best_for_quiz() directly. Thin wrapper - the
	 * repository already picks "best" (highest graded score).
	 */
	public function test_best_attempt_returns_the_highest_graded_score() {
		$low  = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		QuizAttemptRepository::update( $low->id, [ 'status' => 'graded', 'score' => 0.5 ] );
		$high = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		QuizAttemptRepository::update( $high->id, [ 'status' => 'graded', 'score' => 1.0 ] );

		$best = $this->quizzes->best_attempt( $this->user, $this->quiz, $this->course );

		$this->assertInstanceOf( QuizAttempt::class, $best );
		$this->assertSame( $high->id, $best->id );
	}

	/** No graded attempt yet: nothing to report as "best". */
	public function test_best_attempt_is_null_with_no_graded_attempt() {
		$this->assertNull( $this->quizzes->best_attempt( $this->user, $this->quiz, $this->course ) );
	}

	public function test_retry_delay_blocks_an_immediate_second_attempt() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'max_attempts' => 0, 'retry_delay_seconds' => 3600 ] );

		$first = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		QuizAttemptRepository::update( $first->id, [ 'status' => 'graded', 'submitted_at' => '2026-05-01 10:05:00', 'score' => 0.0 ] );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		$refused = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->assertSame( 'retry_delay', $refused->get_error_code() );
		$this->assertSame( strtotime( '2026-05-01 11:05:00 UTC' ), $this->quizzes->retry_available_at( $this->user, $this->quiz, $this->course ) );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 11:06:00 UTC' ) );

		$this->assertInstanceOf( QuizAttempt::class, $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course ) );
	}

	public function test_an_unenrolled_user_cannot_start() {
		$stranger = $this->make_learner();
		$this->assertSame(
			'not_enrolled',
			$this->quizzes->start_attempt( $stranger, $this->quiz, $this->course )->get_error_code()
		);
	}

	public function test_a_quiz_outside_the_course_cannot_be_started() {
		$orphan = $this->make_quiz();
		$this->assertSame(
			'not_in_course',
			$this->quizzes->start_attempt( $this->user, $orphan, $this->course )->get_error_code()
		);
	}

	public function test_a_quiz_with_no_questions_cannot_be_started() {
		$empty = $this->make_quiz();
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $empty ] ] ] ]
		);
		$this->assertSame(
			'no_questions',
			$this->quizzes->start_attempt( $this->user, $empty, $this->course )->get_error_code()
		);
	}

	public function test_the_can_start_filter_can_veto() {
		add_filter(
			'anchor_courses_can_start_quiz',
			static fn( $allowed ) => new WP_Error( 'prework', 'Finish the pre-work.' )
		);
		$this->assertSame( 'prework', $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course )->get_error_code() );
	}

	/** Brief 8.3 / rule 8: never, under any circumstances. */
	public function test_questions_for_learner_never_expose_correct() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$payload = $this->quizzes->questions_for_learner( $this->quiz, $attempt );

		$this->assertStringNotContainsString( 'correct', (string) wp_json_encode( $payload ) );
		$this->assertSame( 'One?', $payload[0]['prompt'] );
	}

	public function test_save_answer_stores_only_known_question_ids() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$qid     = Questions::get( $this->quiz )[0]['id'];

		$this->assertTrue( $this->quizzes->save_answer( $attempt->id, $qid, 'a2' ) );
		$this->assertWPError( $this->quizzes->save_answer( $attempt->id, 'not-a-question', 'a2' ) );

		$stored = QuizAttemptRepository::find( $attempt->id );
		$this->assertSame( [ $qid => [ 'a2' ] ], $stored->answers );
	}

	public function test_save_answer_refuses_a_closed_attempt() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$qid     = Questions::get( $this->quiz )[0]['id'];
		QuizAttemptRepository::update( $attempt->id, [ 'status' => 'graded' ] );

		$this->assertSame( 'attempt_closed', $this->quizzes->save_answer( $attempt->id, $qid, 'a2' )->get_error_code() );
	}

	public function test_owns_attempt_is_false_for_another_learner() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->assertTrue( $this->quizzes->owns_attempt( $this->user, $attempt->id ) );
		$this->assertFalse( $this->quizzes->owns_attempt( $this->make_learner(), $attempt->id ) );
	}

	public function test_deadline_is_zero_when_untimed_and_computed_when_timed() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->assertSame( 0, $this->quizzes->deadline( $attempt ) );

		// Task 24 review, ruling R3: time_limit_seconds is pinned into the
		// attempt at start(), so a settings change afterwards is invisible to
		// deadline() for THIS attempt - it only affects the next one started.
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600 ] );
		$this->assertSame( 0, $this->quizzes->deadline( $attempt ), 'Untimed was pinned at start; a later edit must not retroactively time this attempt.' );

		$second_learner = $this->make_learner();
		\Anchor\Courses\Support\Roles::grant_access( $second_learner, $this->course );
		$timed = $this->quizzes->start_attempt( $second_learner, $this->quiz, $this->course );
		$this->assertSame( strtotime( '2026-05-01 10:10:00 UTC' ), $this->quizzes->deadline( $timed ), 'A NEW attempt started after the edit pins the new limit.' );
	}

	/**
	 * Round 7, finding 3: sweep_expired_attempts() must count an attempt it
	 * closed even when the row's post-transition read-back failed - it
	 * counts by comparing $after->status against the pre-timer status, but
	 * enforce_timer() can now hand back a `read_failed` WP_Error instead of
	 * a QuizAttempt (never the stale pre-timer object - see
	 * Test_Courses_Quiz_Submit's sibling test). The transition it performed
	 * still counts, whether or not anything readable confirmed it.
	 */
	public function test_sweep_counts_an_attempt_whose_post_transition_read_failed() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'expire' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		global $wpdb;
		$wpdb->suppress_errors( true );
		// The sweep's own bulk SELECT (WHERE status = 'in_progress') is a
		// different statement and is left alone; only reads BY ID for this
		// attempt are targeted, and the first of those - submit()'s own, at
		// the top of the method - is left alone so it reaches the `expire`
		// branch at all.
		$reads = 0;
		$break = function ( $query ) use ( $attempt, &$reads ) {
			$query = (string) $query;
			if ( \preg_match( '/^SELECT \* FROM \S*anchor_courses_quiz_attempts WHERE id = ' . $attempt->id . '$/', $query ) ) {
				$reads++;
				if ( $reads > 1 ) {
					return 'SELECT anchor_courses_injected_failure FROM no_such_table_anchor';
				}
			}
			return $query;
		};
		add_filter( 'query', $break );
		$closed = $this->quizzes->sweep_expired_attempts();
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( false );

		$this->assertGreaterThan( 1, $reads, 'Precondition: the sweep reached this attempt and every read-back on it failed.' );
		$this->assertSame( 1, $closed, 'Counted even though nothing could read the row back afterwards.' );
		$this->assertSame( 'expired', QuizAttemptRepository::find( $attempt->id )->status, 'The transition landed for real - a later, unimpeded read shows it.' );
	}

	/**
	 * Round 8, CodeRabbit: the generic claim-then-grade path (any timer
	 * policy other than `expire`) ROLLS BACK its own `in_progress ->
	 * submitted` claim before returning `read_failed` when the post-claim
	 * re-read fails - the row ends up exactly where it started. Unlike the
	 * `expire` branch's read_failed (previous test), this one must NOT count
	 * as closed: nothing durable changed.
	 */
	public function test_sweep_counts_zero_for_a_rolled_back_claim_under_auto_submit() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'auto_submit' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		global $wpdb;
		$wpdb->suppress_errors( true );
		$claimed = false;
		$broken  = false;
		$break   = function ( $query ) use ( $attempt, &$claimed, &$broken ) {
			$query = (string) $query;
			if ( ! $claimed && \preg_match( "/^UPDATE \\S*anchor_courses_quiz_attempts SET status = 'submitted'/", $query ) ) {
				$claimed = true;
				return $query;
			}
			if ( $claimed && ! $broken && \preg_match( '/^SELECT \* FROM \S*anchor_courses_quiz_attempts WHERE id = ' . $attempt->id . '$/', $query ) ) {
				$broken = true;
				return 'SELECT anchor_courses_injected_failure FROM no_such_table_anchor';
			}
			return $query;
		};
		add_filter( 'query', $break );
		$closed = $this->quizzes->sweep_expired_attempts();
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( false );

		$this->assertTrue( $claimed && $broken, 'Precondition: the claim landed and its post-claim re-read failed.' );
		$this->assertSame( 0, $closed, 'The rolled-back claim never stuck - nothing to count as closed.' );
		$this->assertSame( 'in_progress', QuizAttemptRepository::find( $attempt->id )->status, 'The claim is released - retryable, unchanged from the sweep\'s point of view.' );
	}
}
