<?php
/**
 * Anchor Courses - quiz attempt table access (brief 7.3, 8.4, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\Migrations;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\QuizAttempt;

/** @group courses */
class Test_Courses_Attempt_Repo extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $quiz;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course();
		$this->quiz   = $this->make_quiz();
	}

	private function create( array $over = [] ): ?QuizAttempt {
		return QuizAttemptRepository::create(
			array_merge(
				[ 'user_id' => $this->user, 'course_id' => $this->course, 'quiz_id' => $this->quiz,
				  'points_possible' => 10.0 ],
				$over
			)
		);
	}

	public function test_attempt_numbers_increment_from_one() {
		$first  = $this->create();
		QuizAttemptRepository::update( $first->id, [ 'status' => 'graded' ] );
		$second = $this->create();

		$this->assertSame( 1, $first->attempt_number );
		$this->assertSame( 2, $second->attempt_number );
		$this->assertSame( 'in_progress', $first->status );
	}

	public function test_attempt_numbers_are_per_user_and_per_quiz() {
		$this->create();
		$other_user = QuizAttemptRepository::create(
			[ 'user_id' => $this->make_learner(), 'course_id' => $this->course, 'quiz_id' => $this->quiz ]
		);
		$other_quiz = $this->create( [ 'quiz_id' => $this->make_quiz() ] );

		$this->assertSame( 1, $other_user->attempt_number );
		$this->assertSame( 1, $other_quiz->attempt_number );
	}

	public function test_open_attempt_finds_only_in_progress_rows() {
		$attempt = $this->create();
		$this->assertSame( $attempt->id, QuizAttemptRepository::open_attempt( $this->user, $this->quiz )->id );

		QuizAttemptRepository::update( $attempt->id, [ 'status' => 'graded' ] );
		$this->assertNull( QuizAttemptRepository::open_attempt( $this->user, $this->quiz ) );
	}

	public function test_answers_and_grading_data_round_trip_as_arrays() {
		$attempt = $this->create();

		$updated = QuizAttemptRepository::update(
			$attempt->id,
			[ 'answers' => [ 'q1' => [ 'a2' ] ], 'grading_data' => [ 'q1' => [ 'correct' => true, 'points' => 1.0 ] ] ]
		);

		$this->assertSame( [ 'q1' => [ 'a2' ] ], $updated->answers );
		$this->assertSame( 1.0, $updated->grading_data['q1']['points'] );
	}

	public function test_count_ignores_abandoned_attempts() {
		$a = $this->create();
		QuizAttemptRepository::update( $a->id, [ 'status' => 'abandoned' ] );
		$b = $this->create();
		QuizAttemptRepository::update( $b->id, [ 'status' => 'graded' ] );

		$this->assertSame( 1, QuizAttemptRepository::count_for_quiz( $this->user, $this->quiz ) );
	}

	public function test_best_for_quiz_returns_the_highest_graded_score() {
		$a = $this->create();
		QuizAttemptRepository::update( $a->id, [ 'status' => 'graded', 'score' => 40.0, 'passed' => 0 ] );
		$b = $this->create();
		QuizAttemptRepository::update( $b->id, [ 'status' => 'graded', 'score' => 90.0, 'passed' => 1 ] );

		$best = QuizAttemptRepository::best_for_quiz( $this->user, $this->quiz );
		$this->assertSame( 90.0, $best->score );
		$this->assertTrue( $best->passed );
	}

	public function test_last_for_quiz_returns_the_newest_attempt() {
		$a = $this->create();
		QuizAttemptRepository::update( $a->id, [ 'status' => 'graded' ] );
		$b = $this->create();

		$this->assertSame( $b->id, QuizAttemptRepository::last_for_quiz( $this->user, $this->quiz )->id );
	}

	/** Brief 25: even the DTO must not hand a learner the answer key. */
	public function test_for_learner_hides_grading_data_until_allowed() {
		$attempt = $this->create();
		$graded  = QuizAttemptRepository::update(
			$attempt->id,
			[ 'status' => 'graded', 'score' => 80.0, 'passed' => 1,
			  'grading_data' => [ 'q1' => [ 'correct_ids' => [ 'a2' ] ] ] ]
		);

		$hidden = $graded->for_learner( false );
		$this->assertArrayNotHasKey( 'grading_data', $hidden );
		$this->assertSame( 80.0, $hidden['score'] );

		$shown = $graded->for_learner( true );
		$this->assertArrayHasKey( 'grading_data', $shown );
	}

	/**
	 * Ruling (progress.md, carried from Task 15): repositories guard enum
	 * invariants themselves since the status column has no CHECK constraint.
	 * Mirrors ProgressRepository::test_upsert_rejects_an_unknown_status().
	 */
	public function test_update_rejects_an_unknown_status() {
		$attempt = $this->create();
		$this->expectException( InvalidArgumentException::class );
		QuizAttemptRepository::update( $attempt->id, [ 'status' => 'bogus' ] );
	}

	/** update() only ever writes the documented allowlist; identity columns cannot be reassigned. */
	public function test_update_ignores_identity_columns() {
		$attempt = $this->create();
		$other   = $this->create( [ 'quiz_id' => $this->make_quiz() ] );

		$updated = QuizAttemptRepository::update(
			$attempt->id,
			[ 'user_id' => 999999, 'quiz_id' => $other->quiz_id, 'attempt_number' => 99, 'status' => 'submitted' ]
		);

		$this->assertSame( $this->user, $updated->user_id );
		$this->assertSame( $this->quiz, $updated->quiz_id );
		$this->assertSame( 1, $updated->attempt_number );
		$this->assertSame( 'submitted', $updated->status );
	}
	private function is_null_in_db( int $id, string $column ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT {$column} IS NULL FROM " . Migrations::table( 'quiz_attempts' ) . ' WHERE id = %d', $id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/** Task 14 review (HIGH), checked on this table too: submitted_at is SQL NULL until set, and after a clear. */
	public function test_submitted_at_is_sql_null_until_set_and_after_a_clear() {
		$attempt = $this->create();
		$this->assertTrue( $this->is_null_in_db( $attempt->id, 'submitted_at' ) );
		$this->assertNull( $attempt->submitted_at );

		QuizAttemptRepository::update( $attempt->id, [ 'submitted_at' => '2026-04-04 04:04:04' ] );
		$cleared = QuizAttemptRepository::update( $attempt->id, [ 'submitted_at' => null ] );

		$this->assertTrue( $this->is_null_in_db( $attempt->id, 'submitted_at' ) );
		$this->assertNull( $cleared->submitted_at );
	}

	public function test_a_legacy_zero_date_submitted_at_reads_as_null() {
		global $wpdb;
		$attempt = $this->create();
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::table( 'quiz_attempts' ) . " SET submitted_at = '0000-00-00 00:00:00' WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$attempt->id
			)
		);

		$this->assertNull( QuizAttemptRepository::find( $attempt->id )->submitted_at );
	}

	/** Task 31 N+1 ruling: one query for a whole page of user ids, keyed by user_id, highest score wins. */
	public function test_best_scores_for_users_in_course_batches_a_page_of_learners() {
		$withA   = $this->user;
		$withB   = $this->make_learner();
		$without = $this->make_learner();

		$first = $this->create();
		QuizAttemptRepository::update( $first->id, [ 'status' => 'graded', 'score' => 60.0 ] );
		$retake = $this->create(); // Same user+quiz - a legitimate retake, attempt_number 2.
		QuizAttemptRepository::update( $retake->id, [ 'status' => 'graded', 'score' => 90.0 ] );

		$other = QuizAttemptRepository::create(
			[ 'user_id' => $withB, 'course_id' => $this->course, 'quiz_id' => $this->quiz, 'points_possible' => 10.0 ]
		);
		QuizAttemptRepository::update( $other->id, [ 'status' => 'graded', 'score' => 75.0 ] );

		// A scored attempt in a DIFFERENT course must not leak into this course's map.
		$other_course = $this->make_course();
		$elsewhere    = QuizAttemptRepository::create(
			[ 'user_id' => $withA, 'course_id' => $other_course, 'quiz_id' => $this->make_quiz(), 'points_possible' => 10.0 ]
		);
		QuizAttemptRepository::update( $elsewhere->id, [ 'status' => 'graded', 'score' => 100.0 ] );

		$map = QuizAttemptRepository::best_scores_for_users_in_course( [ $withA, $withB, $without ], $this->course );

		$this->assertSame( 90.0, $map[ $withA ] );
		$this->assertSame( 75.0, $map[ $withB ] );
		$this->assertArrayNotHasKey( $without, $map );
	}

	public function test_best_scores_for_users_in_course_with_no_user_ids_makes_no_query() {
		$this->assertSame( [], QuizAttemptRepository::best_scores_for_users_in_course( [], $this->course ) );
	}
}
