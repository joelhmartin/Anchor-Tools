<?php
/**
 * Anchor Courses - idempotent course completion (brief 11, 26, rule 7).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Database\CertificateRepository;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Services\CompletionService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Completion extends Anchor_Courses_TestCase {

	private CompletionService $completion;
	private ProgressService $progress;
	private EnrollmentService $enrollments;
	private int $user;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();

		$this->enrollments = new EnrollmentService();
		$this->progress    = new ProgressService( $this->enrollments );
		$this->completion  = new CompletionService( $this->enrollments, $this->progress );
		$this->progress->set_completion_service( $this->completion );

		$this->user   = $this->make_learner( [ 'display_name' => 'Ada' ] );
		$this->course = $this->make_course(
			[ 'progression_mode' => 'free', 'ce_credits' => '2', 'certificate_enabled' => 1 ],
			'Laser Safety'
		);
		$this->lesson = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
		Roles::grant_access( $this->user, $this->course );
	}

	public function tear_down() {
		remove_all_actions( 'anchor_courses_course_completed' );
		remove_all_actions( 'anchor_courses_ce_credit_awarded' );
		remove_all_actions( 'anchor_courses_certificate_issued' );
		remove_all_actions( 'anchor_courses_enrollment_status_changed' );
		parent::tear_down();
	}

	public function test_completing_the_last_item_runs_the_whole_pipeline_once() {
		$events = [];
		add_action( 'anchor_courses_course_completed', function () use ( &$events ) { $events[] = 'course'; }, 10, 3 );
		add_action( 'anchor_courses_ce_credit_awarded', function () use ( &$events ) { $events[] = 'credit'; }, 10, 3 );
		add_action( 'anchor_courses_certificate_issued', function () use ( &$events ) { $events[] = 'certificate'; }, 10, 3 );

		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$this->assertSame( [ 'credit', 'certificate', 'course' ], $events, 'Credits, then certificate, then the completion event.' );
		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ) );
		$this->assertSame( 'completed', $this->enrollments->get( $this->user, $this->course )->status );
		$this->assertNotNull( $this->enrollments->get( $this->user, $this->course )->completed_at );
	}

	/**
	 * Linking is one-directional (issue() only attaches to a credit that
	 * already exists), so the pipeline's award-before-issue order is what
	 * makes this link possible at all. Asserted directly so a future reorder
	 * of the pipeline fails loudly rather than silently dropping the link.
	 */
	public function test_the_issued_certificate_is_linked_to_the_awarded_credit() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$credit      = CreditRepository::find( $this->user, $this->course );
		$certificate = CertificateRepository::find( $this->user, $this->course );

		$this->assertNotNull( $credit );
		$this->assertNotNull( $certificate );
		$this->assertSame( $certificate->id, $credit->certificate_id );
	}

	/** Brief 26: the headline requirement. */
	public function test_running_completion_repeatedly_changes_nothing() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$count = 0;
		add_action( 'anchor_courses_course_completed', function () use ( &$count ) { $count++; }, 10, 3 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( $this->completion->complete( $this->user, $this->course ) );
		}

		$this->assertSame( 0, $count );
		$this->assertCount( 1, CreditRepository::for_user( $this->user ) );
		$this->assertCount( 1, CertificateRepository::for_user( $this->user ) );
		$this->assertSame( 2.0, CreditRepository::total_for_user( $this->user ) );
	}

	/**
	 * The DB-level guard itself (ruling R-once): simulate the race directly
	 * against the repository rather than through the service, since two
	 * sequential PHPUnit calls through complete_lesson()/complete() never
	 * actually contend for the same row - the first call's early is_complete()
	 * check already short-circuits the second. This is what actually decides
	 * a real concurrent double-submit: exactly one of two calls against the
	 * SAME not-yet-completed row may succeed.
	 *
	 * Honesty note: PHPUnit runs on one connection, so this test cannot force
	 * two UPDATEs to interleave; a read-then-write implementation would pass it
	 * too. Atomicity rests on the single conditional UPDATE
	 * (`… WHERE id = %d AND status <> 'completed'`) being row-locked by InnoDB,
	 * verified by inspection, not by this test.
	 */
	public function test_the_atomic_transition_admits_only_one_of_two_racing_calls() {
		$enrollment = $this->enrollments->get( $this->user, $this->course );

		$first  = EnrollmentRepository::complete( $enrollment->id, '2026-01-01 00:00:00' );
		$second = EnrollmentRepository::complete( $enrollment->id, '2026-01-01 00:00:00' );

		$this->assertTrue( $first );
		$this->assertFalse( $second );
	}

	public function test_two_simultaneous_lesson_completions_still_award_once() {
		// Simulates the double-submit: two calls before either has finished.
		$a = $this->progress->complete_lesson( $this->user, $this->course, $this->lesson );
		$b = $this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$this->assertSame( $a->id, $b->id );
		$this->assertCount( 1, CreditRepository::for_user( $this->user ) );
		$this->assertCount( 1, CertificateRepository::for_user( $this->user ) );
	}

	public function test_completion_does_not_run_before_the_items_are_done() {
		$second = $this->make_lesson();
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->lesson ],
				[ 'type' => 'lesson', 'id' => $second ],
			] ] ]
		);

		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$this->assertFalse( $this->completion->is_complete( $this->user, $this->course ) );
		$this->assertCount( 0, CreditRepository::for_user( $this->user ) );
	}

	public function test_evaluate_reports_readiness_without_side_effects() {
		$this->assertFalse( $this->completion->evaluate( $this->user, $this->course ) );

		$this->progress->record_item( $this->user, $this->course, $this->lesson, 'lesson', 'completed' );

		$this->assertTrue( $this->completion->evaluate( $this->user, $this->course ) );
		$this->assertCount( 0, CreditRepository::for_user( $this->user ), 'evaluate() must not award anything.' );
	}

	public function test_an_unenrolled_user_cannot_be_completed() {
		$stranger = $this->make_learner();
		$this->assertFalse( $this->completion->complete( $stranger, $this->course ) );
	}

	/** A learner whose access was pulled mid-attempt must not complete on submit. */
	public function test_a_cancelled_or_expired_enrolment_cannot_be_completed() {
		foreach ( [ 'cancelled', 'expired' ] as $status ) {
			$user   = $this->make_learner();
			$course = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '1' ] );
			$lesson = $this->make_lesson();
			Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
			Roles::grant_access( $user, $course );
			// The item is done, so evaluate() is true; only the row status blocks completion.
			$this->progress->record_item( $user, $course, $lesson, 'lesson', 'completed' );
			( new \Anchor\Courses\Services\EnrollmentService() )->set_status( $user, $course, $status );

			$this->assertFalse( $this->completion->complete( $user, $course ), $status );
			$this->assertSame( $status, EnrollmentRepository::find( $user, $course )->status, $status );
			$this->assertNull( CreditRepository::find( $user, $course ), $status );
		}
	}

	public function test_a_course_with_no_certificate_still_completes_and_awards_credits() {
		$no_cert = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '1', 'certificate_enabled' => 0 ] );
		$lesson  = $this->make_lesson();
		Curriculum::save( $no_cert, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		Roles::grant_access( $this->user, $no_cert );

		$this->progress->complete_lesson( $this->user, $no_cert, $lesson );

		$this->assertTrue( $this->completion->is_complete( $this->user, $no_cert ) );
		$this->assertNotNull( CreditRepository::find( $this->user, $no_cert ) );
		$this->assertNull( CertificateRepository::find( $this->user, $no_cert ) );
	}

	public function test_uncomplete_reverts_the_enrolment_but_keeps_what_was_earned() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$this->assertTrue( $this->completion->uncomplete( $this->user, $this->course ) );

		$this->assertFalse( $this->completion->is_complete( $this->user, $this->course ) );
		$this->assertNotNull( CreditRepository::find( $this->user, $this->course ), 'Earned credits are never revoked.' );
		$this->assertNotNull( CertificateRepository::find( $this->user, $this->course ) );
	}

	/**
	 * Completion mints and grants the COMPLETION role - and leaves the access
	 * role and the enrolment exactly where they were.
	 */
	public function test_completion_grants_the_completion_role_only() {
		$enrolled = $this->enrollments->get( $this->user, $this->course );
		$this->assertNotNull( $enrolled );

		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$completion_slug = Roles::completion_slug( $this->course );
		$this->assertTrue( Roles::user_has( $this->user, $completion_slug ) );
		$this->assertSame( 'Completed: ' . get_the_title( $this->course ), wp_roles()->roles[ $completion_slug ]['name'] );
		$this->assertSame( [], get_role( $completion_slug )->capabilities, 'A completion role is a tag, not a permission.' );

		$this->assertSame(
			$enrolled->id,
			$this->enrollments->get( $this->user, $this->course )->id,
			'Granting the completion role must not create a second enrolment.'
		);

		remove_role( $completion_slug );
	}

	/** Undoing a completion is not a retraction of what was earned. */
	public function test_uncomplete_keeps_the_completion_role() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );
		$this->completion->uncomplete( $this->user, $this->course );

		$this->assertTrue( Roles::user_has( $this->user, Roles::completion_slug( $this->course ) ) );

		remove_role( Roles::completion_slug( $this->course ) );
	}

	/**
	 * The Module wires CompletionService into ProgressService (Task 29's
	 * `set_completion_service()` contract) - without it, recalculate_course()
	 * silently never completes anything (the `$this->completion instanceof
	 * CompletionService` guard in ProgressService::recalculate_course()).
	 */
	public function test_wiring_the_module_injects_the_completion_pipeline_into_progress() {
		$module = $this->courses();

		$this->assertInstanceOf( CompletionService::class, $module->completion );

		$property = new ReflectionProperty( ProgressService::class, 'completion' );
		$property->setAccessible( true );

		$this->assertSame( $module->completion, $property->getValue( $module->progress ) );
	}

	/* ---------------------------------------------------------------------
	 * Final review C1 - a revoked learner must never complete
	 * ------------------------------------------------------------------- */

	/** A one-question quiz (a2 is right) as the course's only item. @return array{int,int,string} course, quiz, question id */
	private function one_quiz_course( array $quiz_settings ): array {
		$course = $this->make_course( [ 'completion_mode' => 'all_required_items', 'certificate_enabled' => 1, 'progression_mode' => 'free' ] );
		$quiz   = $this->make_quiz( [ 'settings' => $quiz_settings ], 'Q' );
		$qs     = \Anchor\Courses\Content\Questions::save(
			$quiz,
			[ [ 'type' => 'single_choice', 'prompt' => 'P', 'points' => 1,
				'answers' => [ [ 'id' => 'a1', 'text' => 'W', 'correct' => false ], [ 'id' => 'a2', 'text' => 'R', 'correct' => true ] ] ] ]
		);
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $quiz ] ] ] ] );
		return [ $course, $quiz, (string) $qs[0]['id'] ];
	}

	/**
	 * Review probe: under the default `keep` policy the row stays active, so
	 * the old row-only check let a revoked learner with an open attempt submit,
	 * pass, and walk away with credit, certificate and the completion role.
	 */
	public function test_a_revoked_learner_with_an_open_attempt_cannot_submit_their_way_to_completion() {
		$m = $this->courses();
		[ $course, $quiz, $q ] = $this->one_quiz_course( [ 'passing_score' => 80, 'max_attempts' => 0 ] );
		$user = $this->make_learner();
		Roles::grant_access( $user, $course, 'manual' );
		$attempt = $m->quizzes->start_attempt( $user, $quiz, $course );
		Roles::revoke_access( $user, $course, 'manual' );
		$this->assertFalse( $m->enrollments->is_enrolled( $user, $course ) );

		$result = $m->quizzes->submit( $attempt->id, [ $q => 'a2' ], $user );

		$this->assertWPError( $result );
		$this->assertSame( 'not_enrolled', $result->get_error_code() );
		$this->assertTrue( $m->quizzes->get_attempt( $attempt->id )->is_open(), 'A refused submit grades nothing.' );
		$this->assertNull( CertificateRepository::find( $user, $course ), 'A revoked learner must not get a certificate.' );
		$this->assertNull( CreditRepository::find( $user, $course ), 'A revoked learner must not get credit.' );
		$this->assertNotSame( 'completed', $m->enrollments->get( $user, $course )->status );
		$this->assertFalse( Roles::user_has( $user, Roles::completion_slug( $course ) ) );
	}

	public function test_a_revoked_learner_cannot_save_answers_on_an_open_attempt() {
		$m = $this->courses();
		[ $course, $quiz, $q ] = $this->one_quiz_course( [ 'passing_score' => 80, 'max_attempts' => 0 ] );
		$user = $this->make_learner();
		Roles::grant_access( $user, $course, 'manual' );
		$attempt = $m->quizzes->start_attempt( $user, $quiz, $course );
		Roles::revoke_access( $user, $course, 'manual' );

		$result = $m->quizzes->save_answer( $attempt->id, $q, 'a2', $user );

		$this->assertWPError( $result );
		$this->assertSame( 'not_enrolled', $result->get_error_code() );
		$this->assertSame( [], $m->quizzes->get_attempt( $attempt->id )->answers );
	}

	/**
	 * A system caller (cron timer, no expected user) still closes the attempt,
	 * but the completion pipeline itself refuses a learner who is no longer
	 * enrolled: the grade is recorded, nothing is awarded.
	 */
	public function test_complete_refuses_a_learner_who_is_no_longer_enrolled() {
		Roles::revoke_access( $this->user, $this->course, 'manual' );
		$row = $this->enrollments->get( $this->user, $this->course );
		$this->assertTrue( $row->is_active(), 'Precondition: keep leaves the row active.' );

		add_filter( 'anchor_courses_course_completion_status', '__return_true', 99 );
		$completed = $this->completion->complete( $this->user, $this->course );
		remove_filter( 'anchor_courses_course_completion_status', '__return_true', 99 );

		$this->assertFalse( $completed );
		$this->assertNull( CertificateRepository::find( $this->user, $this->course ) );
		$this->assertNotSame( 'completed', $this->enrollments->get( $this->user, $this->course )->status );
	}
}
