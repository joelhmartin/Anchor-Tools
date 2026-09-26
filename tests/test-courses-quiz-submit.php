<?php
/**
 * Anchor Courses - grading, timers and the quiz -> progress hand-off
 * (brief 8.4, 8.5, 9.2).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Services\QuizService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Quiz_Submit extends Anchor_Courses_TestCase {

	private QuizService $quizzes;
	private ProgressService $progress;
	private int $user;
	private int $course;
	private int $quiz;
	private string $q1;
	private string $q2;

	public function set_up() {
		parent::set_up();
		$this->progress = new ProgressService();
		$this->quizzes  = new QuizService( $this->progress );

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 2, 'show_correct_answers' => 1 ] ] );

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
		Roles::grant_access( $this->user, $this->course );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_now' );
		remove_all_filters( 'anchor_courses_quiz_result' );
		foreach ( [ 'submitted', 'passed', 'failed', 'expired' ] as $event ) {
			remove_all_actions( 'anchor_courses_quiz_' . $event );
		}
		parent::tear_down();
	}

	public function test_a_perfect_submission_passes_and_fires_passed() {
		$events  = [];
		add_action( 'anchor_courses_quiz_submitted', function () use ( &$events ) { $events[] = 'submitted'; }, 10, 4 );
		add_action( 'anchor_courses_quiz_passed', function () use ( &$events ) { $events[] = 'passed'; }, 10, 4 );
		add_action( 'anchor_courses_quiz_failed', function () use ( &$events ) { $events[] = 'failed'; }, 10, 4 );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$graded  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );

		$this->assertSame( 'graded', $graded->status );
		$this->assertSame( 100.0, $graded->score );
		$this->assertTrue( $graded->passed );
		$this->assertSame( [ 'submitted', 'passed' ], $events );
	}

	public function test_a_failing_submission_fires_failed_and_marks_progress_failed() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$graded  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a1', $this->q2 => 'b2' ] );

		$this->assertFalse( $graded->passed );
		$this->assertSame( 0.0, $graded->score );
		$this->assertSame( 0.0, $this->progress->get_course_progress( $this->user, $this->course )->percent );
	}

	/** 50% against a passing score of 80 is a fail. */
	public function test_partial_answers_are_graded_against_the_passing_score() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$graded  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2' ] );

		$this->assertSame( 50.0, $graded->score );
		$this->assertFalse( $graded->passed );
	}

	public function test_passing_completes_the_quiz_item_and_the_course() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );

		$progress = $this->progress->get_course_progress( $this->user, $this->course );
		$this->assertSame( 100.0, $progress->percent );
		$this->assertContains( 'quiz:' . $this->quiz, $progress->completed_item_keys );
	}

	public function test_passing_completes_a_lesson_that_requires_this_quiz() {
		$lesson = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $this->quiz ], 'Gated' );
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => $lesson ],
				[ 'type' => 'quiz', 'id' => $this->quiz ],
			] ] ]
		);

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );

		$this->assertContains(
			'lesson:' . $lesson,
			$this->progress->get_course_progress( $this->user, $this->course )->completed_item_keys
		);
	}

	/** Brief 26: submitting twice must not re-grade or re-fire. */
	public function test_submitting_a_graded_attempt_is_a_no_op() {
		$fired = 0;
		add_action( 'anchor_courses_quiz_submitted', function () use ( &$fired ) { $fired++; }, 10, 4 );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$first   = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );
		$second  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a1', $this->q2 => 'b2' ] );

		$this->assertSame( 100.0, $second->score, 'A resubmission must not overwrite a graded score.' );
		$this->assertSame( $first->submitted_at, $second->submitted_at );
		$this->assertSame( 1, $fired );
	}

	/** Brief 8.5: default policy is auto-submit whatever was saved. */
	public function test_an_expired_timer_auto_submits_saved_answers() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'auto_submit' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );
		$this->quizzes->save_answer( $attempt->id, $this->q2, 'b1' );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		$graded = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a1' ] );

		$this->assertSame( 'graded', $graded->status );
		$this->assertSame( 100.0, $graded->score, 'The saved answers are graded, not the late submission.' );
		$this->assertTrue( $graded->passed );
	}

	public function test_the_expire_policy_marks_the_attempt_expired_and_grades_nothing() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'expire' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		$result = $this->quizzes->submit( $attempt->id, [] );

		$this->assertSame( 'expired', $result->status );
		$this->assertFalse( $result->passed );
		$this->assertNull( $result->score );
	}

	/**
	 * Codex review, PR #32 finding 2: the `expire` policy claims the row
	 * ('in_progress' -> 'expired') with an atomic transition, THEN writes
	 * submitted_at/duration_seconds in a second statement. If that second
	 * write fails, QuizAttemptRepository::update()'s null-on-error contract
	 * must never make submit() fall back to the PRE-transition `in_progress`
	 * object it read at the top of the method - the transition already
	 * happened for real, so the returned object, the fired hook, a re-read
	 * of the row and the sweep's count must all agree it is `expired`.
	 */
	public function test_a_failed_expire_detail_write_still_reports_expired_not_the_stale_in_progress_object() {
		global $wpdb;
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'expire' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		$seen = [];
		add_action(
			'anchor_courses_quiz_expired',
			static function ( $expired ) use ( &$seen ) {
				$seen[] = $expired->status;
			}
		);

		// Break only the DETAIL write (submitted_at/duration_seconds) - the
		// earlier `in_progress -> expired` transition is a separate
		// statement and still succeeds for real.
		$wpdb->suppress_errors( true );
		$break = static function ( $query ) {
			return preg_match( '/^UPDATE `?\S*anchor_courses_quiz_attempts`? SET `?submitted_at`?/', (string) $query )
				? 'SELECT anchor_courses_injected_failure FROM no_such_table_anchor'
				: $query;
		};
		add_filter( 'query', $break );

		// Drive it through the sweep (audit F03's real caller for this path,
		// and the finding's own "the sweep's count is off" symptom) rather
		// than calling submit() directly, so the count itself is proven, not
		// just the return value.
		$closed = $this->quizzes->sweep_expired_attempts();

		remove_filter( 'query', $break );
		$wpdb->suppress_errors( false );

		$this->assertSame( 1, $closed, "The sweep's count must not be thrown off by the failed detail write." );
		$this->assertSame( [ 'expired' ], $seen, 'The expiry hook must see the real status, not the pre-transition one.' );

		$reread = QuizAttemptRepository::find( $attempt->id );
		$this->assertSame( 'expired', $reread->status, 'The row itself really did transition.' );
	}

	/**
	 * CodeRabbit Major, PR #32 re-review: the re-read 3288e9c added (right
	 * above this test) to fix the pre-transition fallback can itself fail -
	 * a transient blip on the SELECT, not on either write. Before this fix
	 * that null read fell straight back to $attempt, the object read at the
	 * very TOP of submit(), still `in_progress` even though the earlier
	 * atomic transition really did move the row to `expired`. The fix
	 * retries the read once; this proves the retry - not a stale fallback -
	 * is what recovers: the hook sees `expired`, no `in_progress` object
	 * escapes anywhere, and the sweep still counts the attempt as closed.
	 */
	public function test_a_failed_re_read_after_expiring_is_retried_once_and_never_falls_back_to_the_stale_object() {
		global $wpdb;
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'expire' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		$seen = [];
		add_action(
			'anchor_courses_quiz_expired',
			static function ( $expired ) use ( &$seen ) {
				$seen[] = $expired->status;
			}
		);

		// Fail exactly the FIRST read of the row that happens after the
		// in_progress -> expired transition and its detail write (both
		// succeed for real here): #1 is submit()'s own read at the top
		// (must succeed - it is what finds the deadline passed at all);
		// #2 is QuizAttemptRepository::update()'s own re-read once the
		// detail write succeeds. #3 - this fix's new re-read - is the one
		// broken here; letting #4 (the one retry) through is what proves
		// the retry recovers it, not a fallback to the stale object.
		$wpdb->suppress_errors( true );
		$hits  = 0;
		$break = static function ( $query ) use ( &$hits ) {
			if ( \preg_match( '/^SELECT \* FROM \S*anchor_courses_quiz_attempts WHERE id = \d+$/', (string) $query ) ) {
				$hits++;
				if ( 3 === $hits ) {
					return 'SELECT anchor_courses_injected_failure FROM no_such_table_anchor';
				}
			}
			return $query;
		};
		add_filter( 'query', $break );

		// Drive it through the sweep, same as the test above, so the
		// count itself is proven, not just the return value.
		$closed = $this->quizzes->sweep_expired_attempts();

		remove_filter( 'query', $break );
		$wpdb->suppress_errors( false );

		$this->assertSame( 1, $closed, "The sweep's count must reflect the real transition even though its first re-read failed." );
		$this->assertSame( [ 'expired' ], $seen, 'The expiry hook must see the real status - never the stale in_progress fallback.' );

		$reread = QuizAttemptRepository::find( $attempt->id );
		$this->assertSame( 'expired', $reread->status );
	}

	/**
	 * Round 10, Codex re-review, PR #32 finding: when BOTH post-transition
	 * reads fail (the confirming read and its one retry, above), the old
	 * shape returned `read_failed` before recording the failed quiz progress,
	 * firing `anchor_courses_quiz_expired`, or recalculating the course - and
	 * a retry cannot repair the omission: submit_tracked()'s own "already
	 * closed" short-circuit refuses any later call on this attempt (it is no
	 * longer `in_progress`), while the sweep already counted this call as
	 * closed via `transitioned`. The in_progress -> expired transition is the
	 * truth even though nothing could read it back just now, so these
	 * effects now run against a SYNTHESISED attempt built from the
	 * pre-transition object with its status overridden to `expired` - no
	 * `in_progress` object ever reaches the hook.
	 */
	public function test_both_post_transition_reads_failing_still_runs_the_expiry_effects_once() {
		global $wpdb;
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'expire' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		$seen = [];
		add_action(
			'anchor_courses_quiz_expired',
			static function ( $expired ) use ( &$seen ) {
				$seen[] = $expired->status;
			}
		);

		// #1 is submit_tracked()'s own top read (must succeed - it is what
		// finds the deadline passed at all); #2 is
		// QuizAttemptRepository::update()'s own re-read once the detail write
		// succeeds. #3 (the confirming read) AND #4 (its one retry) - BOTH
		// post-transition reads - are broken here, so neither ever confirms
		// the row.
		$wpdb->suppress_errors( true );
		$hits  = 0;
		$break = static function ( $query ) use ( &$hits ) {
			if ( \preg_match( '/^SELECT \* FROM \S*anchor_courses_quiz_attempts WHERE id = \d+$/', (string) $query ) ) {
				$hits++;
				if ( $hits >= 3 ) {
					return 'SELECT anchor_courses_injected_failure FROM no_such_table_anchor';
				}
			}
			return $query;
		};
		add_filter( 'query', $break );

		// Driven through the sweep, same as the tests above, so the count
		// itself is proven, not just the return value.
		$closed = $this->quizzes->sweep_expired_attempts();

		remove_filter( 'query', $break );
		$wpdb->suppress_errors( false );

		$this->assertGreaterThanOrEqual( 2, $hits, 'Precondition: both post-transition reads were reached.' );
		$this->assertSame( 1, $closed, 'The transition landed for real, so the sweep still counts it closed.' );
		$this->assertSame( [ 'expired' ], $seen, 'The expiry hook still fires once, with the real status - never in_progress.' );

		$progress_row = \Anchor\Courses\Database\ProgressRepository::find( $this->user, $this->course, $this->quiz, 'quiz' );
		$this->assertNotNull( $progress_row, 'The failed quiz progress is still recorded even though the row could not be read back.' );
		$this->assertSame( 'failed', $progress_row->status );

		$reread = QuizAttemptRepository::find( $attempt->id );
		$this->assertSame( 'expired', $reread->status, 'A later read shows the real, confirmed status.' );
	}

	public function test_an_in_window_submission_records_its_duration() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600 ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:04:00 UTC' ) );

		$graded = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );

		$this->assertSame( 240, $graded->duration_seconds );
	}

	public function test_grading_data_records_per_question_correctness() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$graded  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b2' ] );

		$this->assertTrue( $graded->grading_data[ $this->q1 ]['correct'] );
		$this->assertFalse( $graded->grading_data[ $this->q2 ]['correct'] );
		$this->assertSame( [ 'b1' ], $graded->grading_data[ $this->q2 ]['correct_ids'] );
	}

	public function test_the_quiz_result_filter_can_override_the_outcome() {
		add_filter(
			'anchor_courses_quiz_result',
			static function ( array $result ) {
				$result['passed'] = true;
				return $result;
			}
		);

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$graded  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a1', $this->q2 => 'b2' ] );

		$this->assertTrue( $graded->passed );
	}

	public function test_submit_refuses_an_unknown_attempt() {
		$this->assertSame( 'no_attempt', $this->quizzes->submit( 999999 )->get_error_code() );
	}

	public function test_sweep_expires_abandoned_timed_attempts() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'auto_submit' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-02 10:00:00 UTC' ) );

		$this->assertSame( 1, $this->quizzes->sweep_expired_attempts() );
		$this->assertNotSame( 'in_progress', QuizAttemptRepository::find( $attempt->id )->status );
	}

	/**
	 * Task 24 review, ruling R3: the effective time_limit_seconds and timer
	 * policy are pinned into the attempt at start(); a live settings edit
	 * mid-attempt must never move a running deadline.
	 */
	public function test_a_mid_attempt_settings_change_does_not_move_the_deadline() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600 ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$pinned_deadline = $this->quizzes->deadline( $attempt );
		$this->assertSame( strtotime( '2026-05-01 10:10:00 UTC' ), $pinned_deadline );

		// An admin doubles the time limit and flips the expiry policy mid-attempt.
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 1200, 'on_timer_expiry' => 'expire' ] );

		$attempt = QuizAttemptRepository::find( $attempt->id );
		$this->assertSame( $pinned_deadline, $this->quizzes->deadline( $attempt ), 'The deadline must stay pinned to what was in force at start.' );

		// The pinned policy ('auto_submit', the default in force at start) governs
		// too, not the 'expire' just written.
		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );
		$graded = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );
		$this->assertSame( 'graded', $graded->status, 'The pinned auto_submit policy must still apply, not the expire policy set after start.' );
	}

	/**
	 * Task 24 review, ruling R2: starting a retake must never regress a
	 * completed quiz's progress row back to in_progress.
	 */
	public function test_starting_a_retake_after_a_pass_does_not_regress_progress() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );

		$this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$this->assertContains(
			'quiz:' . $this->quiz,
			$this->progress->get_completed_items( $this->user, $this->course )
		);
	}

	/**
	 * Task 24 review, ruling R1: a genuine unique-key collision on the
	 * attempt-number allocation must recover the winner's row without
	 * re-firing start_attempt()'s side effects.
	 */
	public function test_a_start_collision_resolves_to_the_existing_attempt_without_refiring_started() {
		$fired = 0;
		add_action( 'anchor_courses_quiz_started', function () use ( &$fired ) { $fired++; }, 10, 4 );

		$winner = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->assertSame( 1, $fired );

		// Simulate the race: a second create() call computes the same next
		// attempt_number the winner already holds (brief T24 ruling R1).
		add_filter(
			'query',
			static function ( $sql ) {
				if ( is_string( $sql ) && false !== strpos( $sql, 'quiz_attempts' ) && false !== strpos( $sql, 'COALESCE(MAX' ) ) {
					remove_all_filters( 'query' );
					return (string) preg_replace( '/COALESCE\(MAX\(a\.attempt_number\), 0\) \+ 1/', '1', $sql );
				}
				return $sql;
			}
		);

		$collided = QuizAttemptRepository::create(
			[ 'user_id' => $this->user, 'course_id' => $this->course, 'quiz_id' => $this->quiz, 'points_possible' => 2.0 ]
		);

		$this->assertNull( $collided, 'A genuine unique-key collision must signal null, not silently resolve to a row.' );
		$this->assertSame( 1, $fired, 'No second quiz_started firing for a collision that never inserted.' );
		$this->assertSame( $winner->id, QuizAttemptRepository::open_attempt( $this->user, $this->quiz, $this->course )->id );
	}

	/**
	 * Final review I5 (amends T24 R2): a failed retake after a pass must not
	 * downgrade the completed quiz item - best attempt counts - so the items
	 * after it stay unlocked and completed_at is untouched.
	 */
	public function test_a_failed_retake_after_a_pass_never_downgrades_the_quiz_item() {
		$m      = $this->courses();
		$course = $this->make_course( [ 'progression_mode' => 'sequential', 'completion_mode' => 'all_required_items' ] );
		$quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 0 ] ] );
		$qs     = Questions::save( $quiz, [ [ 'type' => 'single_choice', 'prompt' => 'P', 'points' => 1,
			'answers' => [ [ 'id' => 'a1', 'text' => 'W', 'correct' => false ], [ 'id' => 'a2', 'text' => 'R', 'correct' => true ] ] ] ] );
		$q       = (string) $qs[0]['id'];
		$first   = $this->make_lesson( [ 'completion_mode' => 'manual', 'required' => 1 ] );
		$after   = $this->make_lesson( [ 'completion_mode' => 'manual', 'required' => 1 ] );
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [
			[ 'type' => 'lesson', 'id' => $first ], [ 'type' => 'quiz', 'id' => $quiz ], [ 'type' => 'lesson', 'id' => $after ],
		] ] ] );
		$user = $this->make_learner();
		Roles::grant_access( $user, $course, 'manual' );
		$m->progress->complete_lesson( $user, $course, $first );

		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$pass = $m->quizzes->start_attempt( $user, $quiz, $course );
		$m->quizzes->submit( $pass->id, [ $q => 'a2' ], $user );
		$this->assertTrue( $m->progress->is_item_available( $user, $course, $after, 'lesson' ) );
		$completed_at = \Anchor\Courses\Database\ProgressRepository::find( $user, $course, $quiz, 'quiz' )->completed_at;

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-02 10:00:00 UTC' ) );
		$retake = $m->quizzes->start_attempt( $user, $quiz, $course );
		$this->assertNotWPError( $retake );
		$failed = $m->quizzes->submit( $retake->id, [ $q => 'a1' ], $user );
		$this->assertFalse( $failed->passed );

		$row = \Anchor\Courses\Database\ProgressRepository::find( $user, $course, $quiz, 'quiz' );
		$this->assertSame( 'completed', $row->status, 'A failed retake must not downgrade a passed quiz.' );
		$this->assertSame( $completed_at, $row->completed_at, 'completed_at is the first pass, not the retake.' );
		$this->assertTrue( $m->progress->is_item_available( $user, $course, $after, 'lesson' ), 'The next lesson must stay unlocked.' );
	}

	/**
	 * Final review minor: submit() is atomic. A second submit racing the
	 * first (simulated by re-entering submit() from inside the grading
	 * filter, i.e. after the first call read the attempt as open) must find
	 * the attempt already claimed and grade nothing.
	 */
	public function test_concurrent_submits_grade_once() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$fired   = 0;
		add_action( 'anchor_courses_quiz_submitted', function () use ( &$fired ) { $fired++; }, 10, 4 );

		$nested  = null;
		$entered = false;
		add_filter(
			'anchor_courses_quiz_result',
			function ( $result ) use ( $attempt, &$nested, &$entered ) {
				if ( ! $entered ) {
					$entered = true; // Set BEFORE re-entering: without the claim, the nested call grades and re-enters this filter.
					$nested  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a1', $this->q2 => 'b2' ] );
				}
				return $result;
			}
		);

		$graded = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );

		$this->assertSame( 1, $fired, 'Two submits, one grading.' );
		$this->assertSame( 'graded', $graded->status );
		$this->assertSame( 100.0, $graded->score );
		$this->assertInstanceOf( QuizAttempt::class, $nested );
		$this->assertNotSame( 'graded', $nested->status, 'The losing submit returns the claimed attempt ungraded.' );
	}

	/** Final review minor: the `expire` timer path fires its own hook, once. */
	public function test_the_expire_policy_fires_quiz_expired_once() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'expire' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$seen = [];
		add_action(
			'anchor_courses_quiz_expired',
			function ( $a, $user_id, $quiz_id, $course_id ) use ( &$seen ) { $seen[] = [ $a->status, $user_id, $quiz_id, $course_id ]; },
			10,
			4
		);

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );
		$this->quizzes->submit( $attempt->id, [] );
		$this->quizzes->submit( $attempt->id, [] );

		$this->assertSame( [ [ 'expired', $this->user, $this->quiz, $this->course ] ], $seen );
	}

	/**
	 * Round 6 finding B: if the re-read right after a successful
	 * in_progress -> submitted claim fails, submit() must NOT grade its
	 * pre-claim snapshot - an autosave committed between the first read and
	 * the claim is missing from it and would be dropped from both the grade
	 * and the stored answers. It releases the claim and returns `read_failed`
	 * (503 at REST); the autosaved answer is intact and a retry grades it.
	 *
	 * Sequenced through the `query` filter: the autosave runs just before
	 * the claim UPDATE reaches MySQL (so after submit()'s first read), and the
	 * first read of the row after the claim is turned into a real database
	 * error, once.
	 */
	public function test_a_failed_read_after_the_claim_releases_it_and_never_grades_the_pre_claim_snapshot() {
		global $wpdb;
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );

		$fired = 0;
		add_action( 'anchor_courses_quiz_submitted', function () use ( &$fired ) { $fired++; }, 10, 4 );

		$autosaved = false;
		$claimed   = false;
		$broken    = false;
		$wpdb->suppress_errors( true );
		$break = function ( $query ) use ( $attempt, &$autosaved, &$claimed, &$broken ) {
			$query = (string) $query;
			if ( ! $autosaved && \preg_match( "/^UPDATE \\S*anchor_courses_quiz_attempts SET status = 'submitted'/", $query ) ) {
				$autosaved = true;
				$this->assertNotWPError( $this->quizzes->save_answer( $attempt->id, $this->q2, 'b1' ), 'The autosave between the first read and the claim is acknowledged.' );
				$claimed = true;
				return $query;
			}
			if ( $claimed && ! $broken && \preg_match( '/^SELECT \* FROM \S*anchor_courses_quiz_attempts WHERE id = \d+$/', $query ) ) {
				$broken = true;
				return 'SELECT anchor_courses_injected_failure FROM no_such_table_anchor';
			}
			return $query;
		};
		add_filter( 'query', $break );
		$result = $this->quizzes->submit( $attempt->id, [] );
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( false );

		$this->assertTrue( $autosaved && $broken, 'Precondition: the autosave and the failed re-read both happened.' );
		$this->assertWPError( $result );
		$this->assertSame( 'read_failed', $result->get_error_code() );
		$this->assertSame( 0, $fired, 'Nothing graded, nothing fired.' );

		$row = QuizAttemptRepository::find( $attempt->id );
		$this->assertSame( 'in_progress', $row->status, 'The claim is released.' );
		$this->assertSame( [ $this->q1 => [ 'a2' ], $this->q2 => [ 'b1' ] ], $row->answers, 'The autosaved answer is intact.' );

		$graded = $this->quizzes->submit( $attempt->id, [] );
		$this->assertSame( 'graded', $graded->status );
		$this->assertSame( 100.0, $graded->score, 'The retry grades the autosaved answer too.' );
		$this->assertSame( 1, $fired );
	}

	/**
	 * Round 7, finding 3: enforce_timer() must never fall back to the
	 * PRE-transition $attempt argument when submit() itself could not
	 * confirm the post-transition row. The `expire` branch commits `expired`
	 * to the database before either of its own two re-reads runs, so a
	 * `read_failed` here means the transition landed for real - reporting
	 * the caller's stale `in_progress` object (the bug) would tell a REST
	 * read, the sweep and every other caller the window is still open when
	 * it is not. enforce_timer() gets one more, authoritative re-read of its
	 * own; only when THAT also fails does it propagate the WP_Error, rather
	 * than ever handing back the stale object.
	 */
	public function test_enforce_timer_propagates_read_failed_instead_of_the_stale_pre_timer_attempt() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'expire' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		global $wpdb;
		$wpdb->suppress_errors( true );
		// The first read-by-id is submit()'s own, at the top of the method -
		// left alone so it reaches the `expire` branch at all. Every read
		// after that - submit()'s own two post-transition re-reads AND
		// enforce_timer()'s additional one - fails.
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
		$result = $this->quizzes->enforce_timer( $attempt );
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( false );

		$this->assertGreaterThan( 1, $reads, 'Precondition: every post-transition read on this attempt was reached and failed.' );
		$this->assertWPError( $result, 'The pre-timer $attempt argument (still in_progress) must never be returned as the truth.' );
		$this->assertSame( 'read_failed', $result->get_error_code() );

		$row = QuizAttemptRepository::find( $attempt->id );
		$this->assertSame( 'expired', $row->status, 'The transition landed for real even though nothing could confirm it a moment ago.' );
	}
}
