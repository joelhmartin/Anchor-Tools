<?php
/**
 * Anchor Courses - defence-in-depth ownership guard on QuizService::submit()
 * and QuizService::save_answer() (Task 25 review ruling, folded into Task 26).
 *
 * QuizController's REST permission layer already refuses a mismatched
 * attempt with owns_attempt() before either service method is ever called;
 * this suite covers the service's OWN guard, which exists so a future
 * caller that skips the REST layer cannot read or mutate another learner's
 * attempt just because it knows the numeric id. A system/cron caller (the
 * timer sweep) passes no user id at all and must keep working exactly as
 * before - that is $expected_user_id's null default.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Services\QuizService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Quiz_Ownership extends Anchor_Courses_TestCase {

	private QuizService $quizzes;
	private int $owner;
	private int $stranger;
	private int $course;
	private int $quiz;
	private string $q1;

	public function set_up() {
		parent::set_up();
		$this->quizzes = new QuizService();

		$this->owner    = $this->make_learner();
		$this->stranger = $this->make_learner();
		$this->course   = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->quiz     = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80 ] ] );

		$saved = Questions::save(
			$this->quiz,
			[ [ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
			    'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ] ]
		);
		$this->q1 = $saved[0]['id'];

		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		// Roles::grant_access(), not EnrollmentService::enroll() - see progress.md
		// / the coordinator's note: is_enrolled() requires the access role, and
		// enroll() alone does not currently grant it (sibling worktree fix in
		// flight).
		Roles::grant_access( $this->owner, $this->course );
	}

	public function test_save_answer_refuses_a_mismatched_expected_user_id() {
		$attempt = $this->quizzes->start_attempt( $this->owner, $this->quiz, $this->course );

		$result = $this->quizzes->save_answer( $attempt->id, $this->q1, 'a2', $this->stranger );

		$this->assertSame( 'attempt_not_yours', $result->get_error_code() );
	}

	public function test_save_answer_succeeds_when_expected_user_id_matches() {
		$attempt = $this->quizzes->start_attempt( $this->owner, $this->quiz, $this->course );

		$result = $this->quizzes->save_answer( $attempt->id, $this->q1, 'a2', $this->owner );

		$this->assertTrue( $result );
	}

	public function test_save_answer_is_unchanged_when_no_expected_user_id_is_given() {
		$attempt = $this->quizzes->start_attempt( $this->owner, $this->quiz, $this->course );

		$result = $this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );

		$this->assertTrue( $result, 'A system caller that passes no expected user id must keep working.' );
	}

	public function test_submit_refuses_a_mismatched_expected_user_id() {
		$attempt = $this->quizzes->start_attempt( $this->owner, $this->quiz, $this->course );

		$result = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2' ], $this->stranger );

		$this->assertSame( 'attempt_not_yours', $result->get_error_code() );
	}

	/**
	 * The ownership check runs BEFORE the idempotent "already closed"
	 * short-circuit, so a stranger guessing a graded attempt's id is refused
	 * rather than handed the owner's graded result.
	 */
	public function test_submit_refuses_a_stranger_even_after_the_attempt_is_already_graded() {
		$attempt = $this->quizzes->start_attempt( $this->owner, $this->quiz, $this->course );
		$graded  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2' ] );
		$this->assertSame( 'graded', $graded->status );

		$result = $this->quizzes->submit( $attempt->id, [], $this->stranger );

		$this->assertSame( 'attempt_not_yours', $result->get_error_code() );
	}

	public function test_submit_succeeds_when_expected_user_id_matches() {
		$attempt = $this->quizzes->start_attempt( $this->owner, $this->quiz, $this->course );

		$result = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2' ], $this->owner );

		$this->assertSame( 'graded', $result->status );
	}

	/** enforce_timer()/sweep_expired_attempts() call submit() with no user id at all. */
	public function test_enforce_timer_still_works_with_no_expected_user_id() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 1, 'on_timer_expiry' => 'expire' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->owner, $this->quiz, $this->course );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:05:00 UTC' ) );

		$result = $this->quizzes->enforce_timer( $attempt );

		$this->assertSame( 'expired', $result->status );
		remove_all_filters( 'anchor_courses_now' );
	}
}
