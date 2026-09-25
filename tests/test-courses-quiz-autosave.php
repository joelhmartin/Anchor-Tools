<?php
/**
 * Audit F06 (docs/audits/2026-09-25-events-lms-audit.md): overlapping answer
 * saves must not lose or overwrite saved state, and a save must never land
 * after the grade.
 *
 * Regression-acceptance list: save two questions concurrently; change one
 * answer twice with the responses reordered; overlap save and submit; expire
 * an attempt with a failed save. No accepted answer may disappear silently,
 * and a graded answer record must not diverge from its grade.
 *
 * LIMIT OF THIS PROOF: PHPUnit runs one PHP process against one MySQL
 * connection, so two requests cannot truly run in parallel here. Each race is
 * a SEQUENCED SIMULATION instead: a one-shot `query` filter fires the moment
 * request A's write reaches $wpdb, runs request B to completion (B's write is
 * committed first), then lets A's write through. That is exactly the losing
 * interleaving - A read the row before B wrote it - and the assertions are
 * about what A's write does next. A real parallel HTTP/MySQL test remains the
 * stronger evidence and is not claimed here.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Services\QuizService;
use Anchor\Courses\Support\Grading;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Quiz_Autosave extends Anchor_Courses_TestCase {

	private QuizService $quizzes;
	private int $user;
	private int $course;
	private int $quiz;
	private string $q1;
	private string $q2;

	/** @var callable[] */
	private array $filters = [];

	public function set_up() {
		parent::set_up();
		$this->quizzes = new QuizService( new ProgressService() );
		$this->user    = $this->make_learner();
		$this->course  = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->quiz    = $this->make_quiz( [ 'settings' => [ 'passing_score' => 50 ] ] );
		$saved         = Questions::save( $this->quiz, [
			[ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
			  'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ],
			[ 'type' => 'single_choice', 'prompt' => 'Two?', 'points' => 1,
			  'answers' => [ [ 'id' => 'b1', 'text' => 'A', 'correct' => true ], [ 'id' => 'b2', 'text' => 'B', 'correct' => false ] ] ],
		] );
		$this->q1 = $saved[0]['id'];
		$this->q2 = $saved[1]['id'];
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		Roles::grant_access( $this->user, $this->course );
	}

	public function tear_down() {
		global $wpdb;
		foreach ( $this->filters as $filter ) {
			remove_filter( 'query', $filter );
		}
		$this->filters = [];
		$wpdb->suppress_errors( false );
		remove_all_filters( 'anchor_courses_now' );
		parent::tear_down();
	}

	/**
	 * The next $times queries matching $pattern first run $other to completion.
	 */
	private function interleave( string $pattern, callable $other, int $times = 1 ): void {
		$left   = $times;
		$busy   = false; // $other's own queries pass straight through.
		$filter = function ( $query ) use ( $pattern, $other, &$left, &$busy ) {
			if ( ! $busy && $left > 0 && preg_match( $pattern, (string) $query ) ) {
				$left--;
				$busy = true;
				try {
					$other();
				} finally {
					$busy = false;
				}
			}
			return $query;
		};
		$this->filters[] = $filter;
		add_filter( 'query', $filter );
	}

	/** Any write of the answers column (the old unconditional update() or the new CAS). */
	private const ANSWERS_WRITE = "/^UPDATE `?\\S*anchor_courses_quiz_attempts`? SET `?answers`?/";

	private function start(): QuizAttempt {
		return $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
	}

	private function answers( int $attempt_id ): array {
		return QuizAttemptRepository::find( $attempt_id )->answers;
	}

	/** Two different questions saved at once: both survive. */
	public function test_two_interleaved_saves_of_different_questions_both_survive() {
		$attempt = $this->start();
		$this->interleave( self::ANSWERS_WRITE, function () use ( $attempt ) {
			$this->assertTrue( $this->quizzes->save_answer( $attempt->id, $this->q2, 'b1' ) );
		} );

		$this->assertTrue( $this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' ) );

		$this->assertSame( [ $this->q2 => [ 'b1' ], $this->q1 => [ 'a2' ] ], $this->answers( $attempt->id ), 'Neither accepted save was overwritten.' );
		$this->assertSame( 2, QuizAttemptRepository::find( $attempt->id )->revision );
	}

	/**
	 * One question changed twice, the second response committed first: each
	 * write is applied atomically in commit order and neither is lost
	 * silently (the browser queue in quiz.js is what keeps a learner's own
	 * two changes from reordering at all).
	 */
	public function test_reordered_saves_of_one_question_apply_in_commit_order() {
		$attempt = $this->start();
		$this->interleave( self::ANSWERS_WRITE, function () use ( $attempt ) {
			$this->assertTrue( $this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' ) );
		} );

		$this->assertTrue( $this->quizzes->save_answer( $attempt->id, $this->q1, 'a1' ) );

		$after = QuizAttemptRepository::find( $attempt->id );
		$this->assertSame( [ $this->q1 => [ 'a1' ] ], $after->answers, 'The write that committed last is the answer.' );
		$this->assertSame( 2, $after->revision, 'Both writes were applied, in order - neither was blindly overwritten.' );
	}

	/** A save that passed its open check before the grade must not land after it. */
	public function test_a_save_racing_a_submit_cannot_rewrite_a_graded_attempt() {
		$attempt = $this->start();
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );
		$this->quizzes->save_answer( $attempt->id, $this->q2, 'b1' );

		$graded = null;
		$this->interleave( self::ANSWERS_WRITE, function () use ( $attempt, &$graded ) {
			$graded = $this->quizzes->submit( $attempt->id );
		} );
		$late = $this->quizzes->save_answer( $attempt->id, $this->q1, 'a1' );

		$this->assertWPError( $late );
		$this->assertSame( 'attempt_closed', $late->get_error_code() );
		$row = QuizAttemptRepository::find( $attempt->id );
		$this->assertSame( 'graded', $row->status );
		$this->assertSame( [ $this->q1 => [ 'a2' ], $this->q2 => [ 'b1' ] ], $row->answers, 'The graded record is what was graded.' );
		$this->assertSame( 100.0, $row->score );
		$this->assertSame( $graded->score, $row->score );
	}

	/** A save acknowledged before the submit's claim is part of the grade. */
	public function test_a_save_committed_before_the_claim_is_graded() {
		$attempt = $this->start();
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );

		// Submit read the attempt; before its claim lands, a save for q2 commits.
		$this->interleave( "/^UPDATE \\S*anchor_courses_quiz_attempts SET status = 'submitted'/", function () use ( $attempt ) {
			$this->assertTrue( $this->quizzes->save_answer( $attempt->id, $this->q2, 'b1' ) );
		} );
		$graded = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2' ] );

		$this->assertSame( [ $this->q1 => [ 'a2' ], $this->q2 => [ 'b1' ] ], $graded->answers, 'The acknowledged q2 save was not dropped.' );
		$this->assertSame( 100.0, $graded->score );
	}

	/** Conflict on the retry too: reported, never silently dropped. */
	public function test_a_second_conflict_is_reported_as_save_conflict() {
		$attempt = $this->start();
		$this->interleave( self::ANSWERS_WRITE, function () use ( $attempt ) {
			// Bypass the service so this inner write is not itself interleaved.
			QuizAttemptRepository::save_answers( $attempt->id, [ 'x' => [ 'y' ] ] + QuizAttemptRepository::find( $attempt->id )->answers, QuizAttemptRepository::find( $attempt->id )->revision );
		}, 2 );

		$result = $this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );
		$this->assertWPError( $result );
		$this->assertSame( 'save_conflict', $result->get_error_code() );
		$this->assertSame( 409, \Anchor\Courses\Rest\Routes::error_response( $result )->get_status() );
	}

	/**
	 * A timed attempt whose last save FAILED at the database, then expires:
	 * the failure is reported (not acknowledged), and the auto-submitted
	 * grade matches exactly the answers it stored.
	 */
	public function test_an_expired_attempt_with_a_failed_save_grades_what_it_stored() {
		global $wpdb;
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 50, 'time_limit_seconds' => 60, 'on_timer_expiry' => 'auto_submit' ] );
		$t0 = 1767225600;
		add_filter( 'anchor_courses_now', static fn () => $t0 );
		$attempt = $this->start();
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );

		$wpdb->suppress_errors( true );
		$break = static function ( $query ) {
			return preg_match( self::ANSWERS_WRITE, (string) $query ) ? 'SELECT anchor_courses_injected_failure FROM no_such_table_anchor' : $query;
		};
		$this->filters[] = $break;
		add_filter( 'query', $break );
		$failed = $this->quizzes->save_answer( $attempt->id, $this->q2, 'b1' );
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( false );

		$this->assertWPError( $failed, 'A save that did not persist is not acknowledged.' );
		$this->assertSame( 'save_failed', $failed->get_error_code() );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn () => $t0 + 120 );
		$this->quizzes->sweep_expired_attempts();

		$row      = QuizAttemptRepository::find( $attempt->id );
		$regraded = Grading::grade( Questions::get( $this->quiz ), $row->answers );
		$this->assertSame( 'graded', $row->status );
		$this->assertSame( [ $this->q1 => [ 'a2' ] ], $row->answers );
		$this->assertEquals( $regraded['score'], $row->score, 'The stored answers and the stored grade agree.' );
	}

	/* --- quiz.js: the browser half (serialise, coalesce, report, drain) ---- */

	/**
	 * Runs quiz.js's save queue under Node (tests/js/quiz-save-queue-harness.js)
	 * with every request held open by the harness, so overlap and ordering
	 * are controlled exactly.
	 */
	public function test_quiz_js_serialises_coalesces_reports_and_drains_saves() {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'node is not installed.' );
		}
		$root = dirname( __DIR__ );
		$out  = (string) shell_exec(
			escapeshellarg( $node ) . ' ' . escapeshellarg( $root . '/tests/js/quiz-save-queue-harness.js' )
			. ' ' . escapeshellarg( $root . '/anchor-courses/assets/quiz.js' ) . ' 2>&1'
		);
		$data = json_decode( $out, true );
		$this->assertIsArray( $data, 'Harness output: ' . $out );

		$this->assertSame( 1, $data['two_questions']['maxInFlight'], 'One save in flight at a time.' );
		$this->assertSame( [ 'q1=a', 'q2=b' ], $data['two_questions']['sent'] );
		$this->assertSame( 1, $data['same_question']['maxInFlight'] );
		$this->assertSame( [ 'q1=a', 'q1=c' ], $data['same_question']['sent'], 'Coalesced to the latest value, never reordered.' );
		$this->assertSame( 'error', $data['failure']['afterFailure'], 'A failed save is reported, not silent.' );
		$this->assertSame( 'saved', $data['failure']['afterRetry'] );
		$this->assertSame( 0, $data['drain']['beforeFinish'], 'Submit waits for the queue.' );
		$this->assertSame( 0, $data['drain']['afterOne'] );
		$this->assertSame( [ false ], $data['drain']['result'], 'Drain reports that a save failed.' );
		$this->assertTrue( $data['drain']['immediate'] );
	}
}
