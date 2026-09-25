<?php
/**
 * Anchor Courses - the brief section 38 milestone path, in one test.
 *
 * If this passes, the engine works: authoring, enrolment, progression, a failed
 * quiz attempt, a retry, a pass, 100% progress, completion, CE credits and a
 * certificate. Everything else in the module is a layer around this path.
 *
 * This is Task 32, the Phase 4 GATE. It drives the module the way production
 * does - through api.php's public functions, Support\Roles::grant_access()
 * (the ONLY way anyone is ever enrolled, per design spec 3.1/7) and the real
 * services off Module::instance() - never a repository directly except to
 * assert on a row the module itself wrote.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Database\CertificateRepository;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Support\Roles;

/** @group courses @group milestone */
class Test_Courses_Milestone extends Anchor_Courses_TestCase {

	public function test_the_first_usable_milestone_path() {
		$module = $this->courses();

		// --- Admin authors the course -------------------------------------
		$course = $this->make_course(
			[
				'progression_mode'    => 'sequential',
				'completion_mode'     => 'all_required_items',
				'ce_credits'          => '2',
				'ce_type'             => 'Dental CE',
				'ce_provider_name'    => 'DEKA Academy',
				'certificate_enabled' => 1,
			],
			'Course A'
		);

		$lesson = $this->make_lesson( [ 'completion_mode' => 'manual', 'required' => 1 ], 'Lesson 1' );
		wp_update_post( [ 'ID' => $lesson, 'post_content' => 'Read this lesson, then take the quiz.' ] );
		$quiz   = $this->make_quiz(
			[ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 2, 'show_correct_answers' => 1 ] ],
			'Quiz 1'
		);

		$questions = Questions::save(
			$quiz,
			[
				[ 'type' => 'single_choice', 'prompt' => 'Q1', 'points' => 1,
				  'answers' => [ [ 'id' => 'a1', 'text' => 'Wrong', 'correct' => false ], [ 'id' => 'a2', 'text' => 'Right', 'correct' => true ] ] ],
				[ 'type' => 'single_choice', 'prompt' => 'Q2', 'points' => 1,
				  'answers' => [ [ 'id' => 'b1', 'text' => 'Right', 'correct' => true ], [ 'id' => 'b2', 'text' => 'Wrong', 'correct' => false ] ] ],
			]
		);
		$q1 = $questions[0]['id'];
		$q2 = $questions[1]['id'];

		Curriculum::save(
			$course,
			[ [ 'title' => 'Module 1', 'items' => [
				[ 'type' => 'lesson', 'id' => $lesson ],
				[ 'type' => 'quiz', 'id' => $quiz ],
			] ] ]
		);

		$this->assertSame( 2, count( Curriculum::required_items( $course ) ) );

		// --- Learner is given access (the role IS the enrolment) ----------
		$user = $this->make_learner( [ 'display_name' => 'Milestone Learner' ] );

		$this->assertTrue( Roles::grant_access( $user, $course, 'manual' ) );
		$this->assertTrue( Roles::user_has( $user, Roles::access_slug( $course ) ) );

		$enrollment = $module->enrollments->get( $user, $course );
		$this->assertNotNull( $enrollment, 'The role listener must have created the enrolment row.' );
		$this->assertSame( 'enrolled', $enrollment->status );
		$this->assertSame( 'manual', $enrollment->source );

		// --- Sequential progression locks the quiz ------------------------
		$this->assertFalse( $module->progress->is_item_available( $user, $course, $quiz, 'quiz' ) );
		$this->assertSame(
			'locked',
			$module->quizzes->start_attempt( $user, $quiz, $course )->get_error_code()
		);

		// --- Learner reads and completes Lesson 1 -------------------------
		$module->progress->start_lesson( $user, $course, $lesson );
		$this->assertNotWPError( anchor_courses_complete_lesson( $user, $course, $lesson ) );
		$this->assertSame( 50.0, anchor_courses_get_progress( $user, $course )->percent );

		// --- Attempt 1: fails ---------------------------------------------
		$attempt1 = $module->quizzes->start_attempt( $user, $quiz, $course );
		$this->assertInstanceOf( QuizAttempt::class, $attempt1 );
		$this->assertSame( 1, $attempt1->attempt_number );

		$failed = $module->quizzes->submit( $attempt1->id, [ $q1 => 'a1', $q2 => 'b2' ] );
		$this->assertSame( 0.0, $failed->score );
		$this->assertFalse( $failed->passed );
		$this->assertSame( 50.0, anchor_courses_get_progress( $user, $course )->percent );
		$this->assertNull( CreditRepository::find( $user, $course ), 'A failed attempt must award nothing.' );

		// --- Attempt 2: passes --------------------------------------------
		$this->assertSame( 1, $module->quizzes->attempts_remaining( $user, $quiz ) );

		$attempt2 = $module->quizzes->start_attempt( $user, $quiz, $course );
		$this->assertSame( 2, $attempt2->attempt_number );

		$passed = $module->quizzes->submit( $attempt2->id, [ $q1 => 'a2', $q2 => 'b1' ] );
		$this->assertSame( 100.0, $passed->score );
		$this->assertTrue( $passed->passed );

		// --- Course reaches 100% and completes ----------------------------
		$progress = anchor_courses_get_progress( $user, $course );
		$this->assertSame( 100.0, $progress->percent );
		$this->assertTrue( $progress->complete );
		$this->assertTrue( $module->completion->is_complete( $user, $course ) );
		$this->assertSame( 'completed', $module->enrollments->get( $user, $course )->status );

		// --- 2 CE credits awarded, exactly once ---------------------------
		$credit = CreditRepository::find( $user, $course );
		$this->assertNotNull( $credit );
		$this->assertSame( 2.0, $credit->credits );
		$this->assertSame( 'Dental CE', $credit->credit_type );
		$this->assertCount( 1, CreditRepository::for_user( $user ) );

		// --- Certificate generated, exactly once --------------------------
		$certificate = CertificateRepository::find( $user, $course );
		$this->assertNotNull( $certificate );
		$this->assertMatchesRegularExpression( '/^AC-\d{4}-\d{8}$/', $certificate->certificate_number );
		$this->assertSame( $certificate->id, $credit->id > 0 ? CreditRepository::find( $user, $course )->certificate_id : 0 );
		$this->assertCount( 1, CertificateRepository::for_user( $user ) );

		// --- A third attempt is refused -----------------------------------
		$this->assertSame(
			'no_attempts_remaining',
			$module->quizzes->start_attempt( $user, $quiz, $course )->get_error_code()
		);

		// --- The completion role is now a prerequisite others can use -----
		$this->assertTrue( Roles::user_has( $user, Roles::completion_slug( $course ) ) );

		// --- Re-running everything changes nothing (brief 26) -------------
		// Only through the public surfaces - completion is never driven by
		// hand here (final review I1): the API must reach the pipeline itself.
		$completed_events = did_action( 'anchor_courses_course_completed' );
		Roles::grant_access( $user, $course, 'manual' );
		anchor_courses_enroll_user( $user, $course );
		anchor_courses_complete_lesson( $user, $course, $lesson );
		$this->assertSame( $completed_events, did_action( 'anchor_courses_course_completed' ), 'Re-running the API must not complete twice.' );

		$this->assertCount( 1, CreditRepository::for_user( $user ) );
		$this->assertCount( 1, CertificateRepository::for_user( $user ) );
		$this->assertSame( 2.0, CreditRepository::total_for_user( $user ) );

		// --- Negative space -------------------------------------------------

		// Frontend\ContentGuard resolves the post via get_post() (global $post),
		// exactly as a real single-lesson template does inside The Loop - so
		// the_content must be exercised the same way here, not called bare.
		global $post;
		$post = get_post( $lesson );
		setup_postdata( $post );

		// Positive control: the enrolled learner sees the real body through the
		// same the_content filter the guard hooks (Task 18 review fix round,
		// ruling (b) - one filter, not a template-only check).
		wp_set_current_user( $user );
		$visible = apply_filters( 'the_content', get_post_field( 'post_content', $lesson ) );
		$this->assertStringContainsString( 'Read this lesson', $visible );

		// A non-enrolled visitor cannot read a lesson via the_content or REST.
		$stranger = $this->make_learner( [ 'display_name' => 'Stranger' ] );
		wp_set_current_user( $stranger );
		$guarded = apply_filters( 'the_content', get_post_field( 'post_content', $lesson ) );
		$this->assertStringNotContainsString( 'Read this lesson', $guarded );

		wp_reset_postdata();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$rest_request  = new WP_REST_Request( 'GET', '/wp/v2/anchor_lesson/' . $lesson );
		$rest_response = rest_do_request( $rest_request );
		$this->assertTrue(
			$rest_response->is_error() || 404 === $rest_response->get_status(),
			'anchor_lesson must not be readable via core REST for a non-enrolled user.'
		);
		wp_set_current_user( 0 );

		// A wrong verification token shows nothing.
		$this->assertNull( ( new \Anchor\Courses\Services\CertificateService() )->get_by_token( 'not-a-real-token' ) );

		// A second completion is a no-op (already proven above by the
		// unchanged credit/certificate counts and the single
		// anchor_courses_course_completed firing).
		$this->assertSame( $completed_events, did_action( 'anchor_courses_course_completed' ) );

		remove_role( Roles::access_slug( $course ) );
		remove_role( Roles::completion_slug( $course ) );
	}
}
