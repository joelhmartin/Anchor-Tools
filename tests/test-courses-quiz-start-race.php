<?php
/**
 * Audit F07 (docs/audits/2026-09-25-events-lms-audit.md): simultaneous starts
 * must not produce two open attempts or exceed the attempt allowance.
 *
 * Regression-acceptance list: simultaneous starts with a one-attempt limit
 * and no preexisting attempt -> both callers receive the same open attempt,
 * or one receives a controlled conflict; exactly one counted attempt exists.
 *
 * LIMIT OF THIS PROOF: PHPUnit drives one PHP process, so two start requests
 * cannot run truly in parallel. Two techniques stand in for it:
 *  - SEQUENCED SIMULATION: a one-shot `query` filter runs request B to
 *    completion at the instant request A's INSERT reaches $wpdb - B passed
 *    its preflight before A wrote and B's row is committed first, the
 *    audit's exact interleaving. (Both run on one MySQL session, where
 *    GET_LOCK is re-entrant, so this exercises the database-level guard in
 *    QuizAttemptRepository::create(), not the lock.)
 *  - A SECOND MySQL CONNECTION holds the named lock, standing in for a
 *    parallel request inside its critical section, to prove start_attempt()
 *    really takes the lock and fails with a controlled conflict when it
 *    cannot.
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
class Test_Courses_Quiz_Start_Race extends Anchor_Courses_TestCase {

	private QuizService $quizzes;
	private int $user;
	private int $course;
	private int $quiz;
	private string $q1;

	/** @var callable[] */
	private array $filters = [];

	private ?mysqli $other = null;

	public function set_up() {
		parent::set_up();
		$this->quizzes = new QuizService( new ProgressService() );
		$this->user    = $this->make_learner();
		$this->course  = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->quiz    = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 1 ] ] );
		$saved         = Questions::save( $this->quiz, [
			[ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
			  'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ],
		] );
		$this->q1 = $saved[0]['id'];
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		Roles::grant_access( $this->user, $this->course );
	}

	public function tear_down() {
		foreach ( $this->filters as $filter ) {
			remove_filter( 'query', $filter );
		}
		$this->filters = [];
		remove_all_filters( 'anchor_courses_attempt_lock_timeout' );
		remove_all_actions( 'anchor_courses_quiz_started' );
		if ( $this->other ) {
			$this->other->close();
			$this->other = null;
		}
		parent::tear_down();
	}

	/** At the first attempt INSERT, run $request_b to completion first. */
	private function before_the_first_insert( callable $request_b ): void {
		$fired  = false;
		$filter = function ( $query ) use ( $request_b, &$fired ) {
			if ( ! $fired && preg_match( '/^INSERT IGNORE INTO \S*anchor_courses_quiz_attempts/', ltrim( (string) $query ) ) ) {
				$fired = true;
				$request_b();
			}
			return $query;
		};
		$this->filters[] = $filter;
		add_filter( 'query', $filter );
	}

	/** The audit's interleaving: both pass preflight, B commits first, then A inserts. */
	public function test_two_simultaneous_starts_share_one_attempt() {
		$started = 0;
		add_action( 'anchor_courses_quiz_started', function () use ( &$started ) { $started++; } );

		$b = null;
		$this->before_the_first_insert( function () use ( &$b ) {
			$b = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		} );
		$a = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$this->assertInstanceOf( QuizAttempt::class, $a );
		$this->assertInstanceOf( QuizAttempt::class, $b );
		$this->assertSame( $b->id, $a->id, 'Both callers get the same open attempt.' );
		$this->assertSame( 1, $this->quizzes->attempts_used( $this->user, $this->quiz, $this->course ), 'Exactly one counted attempt.' );
		$this->assertSame( 1, $started, 'One attempt started, once.' );
	}

	/** B starts AND finishes its only allowed attempt before A inserts: A gets a controlled refusal. */
	public function test_a_start_racing_an_exhausted_allowance_is_refused() {
		$this->before_the_first_insert( function () {
			$b = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
			$this->quizzes->submit( $b->id, [ $this->q1 => 'a1' ] );
		} );
		$a = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$this->assertWPError( $a );
		$this->assertSame( 'no_attempts_remaining', $a->get_error_code() );
		$this->assertSame( 1, $this->quizzes->attempts_used( $this->user, $this->quiz, $this->course ), 'The allowance was not exceeded.' );
	}

	/** After the first caller created the attempt, the second resumes it (the ruling's sequenced case). */
	public function test_a_second_start_after_the_first_created_resumes_it() {
		$a = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$b = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->assertSame( $a->id, $b->id );
		$this->assertSame( 1, $this->quizzes->attempts_used( $this->user, $this->quiz, $this->course ) );
	}

	/**
	 * Re-review (Low): MySQL named locks are server-wide, not scoped by
	 * database - two WordPress installs sharing one MySQL server (or two
	 * subsites of one multisite, which share the server but not the table
	 * prefix) must not contend for the same lock just because a learner,
	 * course and quiz happen to have the same ids on both.
	 */
	public function test_the_lock_name_is_scoped_to_this_site() {
		global $wpdb;
		$original_prefix = $wpdb->prefix;

		$here = QuizService::attempt_lock_name( $this->user, $this->course, $this->quiz );

		$wpdb->prefix = $original_prefix . '_another_site_';
		$there        = QuizService::attempt_lock_name( $this->user, $this->course, $this->quiz );

		$wpdb->prefix = $original_prefix;

		$this->assertNotSame( $here, $there, 'Two sites sharing a MySQL server must not collide on the same lock name.' );
		$this->assertLessThanOrEqual( 64, \strlen( $here ), 'MySQL lock names are at most 64 characters.' );
		$this->assertLessThanOrEqual( 64, \strlen( $there ), 'MySQL lock names are at most 64 characters.' );
	}

	/** A parallel request holding the lock (a second MySQL session) makes this one wait, then back off cleanly. */
	public function test_start_takes_the_named_lock_and_backs_off_with_a_conflict() {
		$this->other = new mysqli();
		$host        = explode( ':', DB_HOST );
		$this->other->real_connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, isset( $host[1] ) ? (int) $host[1] : 3306 );
		$name = QuizService::attempt_lock_name( $this->user, $this->course, $this->quiz );
		$this->assertLessThanOrEqual( 64, strlen( $name ), 'MySQL lock names are at most 64 characters.' );
		$this->assertSame( '1', (string) $this->other->query( "SELECT GET_LOCK('" . $this->other->real_escape_string( $name ) . "', 0)" )->fetch_row()[0] );

		add_filter( 'anchor_courses_attempt_lock_timeout', static fn () => 0 );
		$busy = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->assertWPError( $busy );
		$this->assertSame( 'attempt_busy', $busy->get_error_code() );
		$this->assertSame( 409, \Anchor\Courses\Rest\Routes::error_response( $busy )->get_status() );
		$this->assertSame( 0, $this->quizzes->attempts_used( $this->user, $this->quiz, $this->course ), 'Nothing was created without the lock.' );

		$this->other->query( "SELECT RELEASE_LOCK('" . $this->other->real_escape_string( $name ) . "')" );
		$this->assertInstanceOf( QuizAttempt::class, $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course ) );

		global $wpdb;
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) ), 'The lock is released after a start.' );
	}
}
