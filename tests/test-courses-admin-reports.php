<?php
/**
 * Anchor Courses - admin reporting and enrolment management (brief 21.3, 24, 31/32).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\EnrollmentManager;
use Anchor\Courses\Admin\LearnerReports;
use Anchor\Courses\Admin\Notices;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CreditService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** Thrown from wp_redirect so the handler's exit() never runs. */
class Anchor_Courses_Admin_Redirected extends \Exception {}

/** @group courses */
class Test_Courses_Admin_Reports extends Anchor_Courses_TestCase {

	private int $admin;
	private int $learner;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		$this->admin   = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$this->learner = $this->make_learner( [ 'display_name' => 'Ada Lovelace' ] );
		$this->course  = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '2', 'certificate_enabled' => 1 ], 'Laser Safety' );
		$this->lesson  = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );

		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		remove_all_filters( 'anchor_courses_role_loss_policy' );
		$_POST    = [];
		$_REQUEST = [];
		$_GET     = [];
		parent::tear_down();
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Courses_Admin_Redirected( (string) $location );
	}

	/** @return string The redirect URL the handler tried to send. */
	private function post_enrollment_action( array $fields ): string {
		$_POST = array_merge(
			[ '_wpnonce' => wp_create_nonce( EnrollmentManager::NONCE . '_' . $this->course ), 'course_id' => (string) $this->course ],
			$fields
		);
		$_REQUEST = $_POST;

		try {
			( new EnrollmentManager() )->handle_action();
		} catch ( Anchor_Courses_Admin_Redirected $e ) {
			return $e->getMessage();
		}

		$this->fail( 'Expected a redirect.' );
	}

	/* ----- LearnerReports::rows() / render_learners() ------------------ */

	public function test_rows_report_one_line_per_learner_with_the_brief_columns() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );

		$rows = LearnerReports::rows( $this->course );

		$this->assertCount( 1, $rows );
		foreach ( [ 'user_id', 'display_name', 'user_email', 'enrolled_at', 'percent', 'last_activity',
		            'best_score', 'status', 'completed_at', 'credits', 'certificate_number', 'certificate_url', 'has_access' ] as $column ) {
			$this->assertArrayHasKey( $column, $rows[0], "Missing report column {$column}" );
		}
		$this->assertSame( 'Ada Lovelace', $rows[0]['display_name'] );
		$this->assertSame( 0.0, $rows[0]['percent'] );
		$this->assertSame( '', $rows[0]['last_activity'] );
		$this->assertNull( $rows[0]['best_score'] );
	}

	/**
	 * Services\CompletionService (Task 29) has not joined this branch yet
	 * (progress ledger: "T31 needs 21+27+28", not 29) - the credit/certificate/
	 * completed-status side effects it will eventually drive automatically are
	 * produced directly here, exactly as that pipeline will do on its own once
	 * it lands. This test is about rows() REPORTING those values correctly,
	 * not about what triggers them.
	 */
	public function test_rows_reflect_completion_credits_and_certificate() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );

		( new ProgressService() )->complete_lesson( $this->learner, $this->course, $this->lesson );
		( new EnrollmentService() )->set_status( $this->learner, $this->course, 'completed' );
		( new CreditService() )->award( $this->learner, $this->course );
		( new CertificateService() )->issue( $this->learner, $this->course );

		$row = LearnerReports::rows( $this->course )[0];

		$this->assertSame( 100.0, $row['percent'] );
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( 2.0, $row['credits'] );
		$this->assertMatchesRegularExpression( '/^AC-\d{4}-\d{8}$/', $row['certificate_number'] );
		$this->assertNotSame( '', $row['certificate_url'] );
	}

	/**
	 * Task 21 review N+1 ruling, closed here: the three new report columns
	 * (credits, certificate, best quiz score) plus last_activity must be one
	 * query for the WHOLE PAGE, not one per learner. Spies on the `query`
	 * filter rather than a raw $wpdb->num_queries delta, so the assertion does
	 * not depend on the pre-existing, out-of-scope get_course_progress()
	 * per-row cost (or WP's post-meta caching behaviour) staying constant.
	 */
	public function test_rows_batch_loads_the_new_report_columns_for_a_whole_page() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		Roles::grant_access( $this->make_learner(), $this->course, 'manual' );
		Roles::grant_access( $this->make_learner(), $this->course, 'manual' );

		$queries = [];
		$spy     = static function ( $sql ) use ( &$queries ) {
			$queries[] = (string) $sql;
			return $sql;
		};
		add_filter( 'query', $spy );
		LearnerReports::rows( $this->course );
		remove_filter( 'query', $spy );

		$matching = static function ( string $needle, ?string $also = null ) use ( $queries ): int {
			return count(
				array_filter(
					$queries,
					static fn( $sql ) => false !== strpos( $sql, $needle ) && ( null === $also || false !== strpos( $sql, $also ) )
				)
			);
		};

		$this->assertSame( 1, $matching( 'anchor_courses_ce_credits' ), 'Credits must be ONE query for the whole page.' );
		$this->assertSame( 1, $matching( 'anchor_courses_certificates' ), 'Certificates must be ONE query for the whole page.' );
		$this->assertSame( 1, $matching( 'anchor_courses_quiz_attempts' ), 'Best quiz scores must be ONE query for the whole page.' );
		$this->assertSame(
			1,
			$matching( 'anchor_courses_progress', 'GROUP BY' ),
			'Last activity must be ONE batched GROUP BY query for the whole page, not one per learner.'
		);
	}

	public function test_user_rows_list_every_course_for_one_learner() {
		$second = $this->make_course( [], 'Second' );
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		Roles::grant_access( $this->learner, $second, 'manual' );

		$rows = LearnerReports::user_rows( $this->learner );

		$this->assertCount( 2, $rows );
		$this->assertArrayHasKey( 'attempts', $rows[0] );
	}

	public function test_the_learner_metabox_renders_only_with_the_reports_capability() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );

		wp_set_current_user( $this->learner );
		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$denied = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'Ada Lovelace', $denied );

		wp_set_current_user( $this->admin );
		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$allowed = (string) ob_get_clean();
		$this->assertStringContainsString( 'Ada Lovelace', $allowed );
	}

	public function test_learner_emails_are_escaped_in_the_report() {
		$nasty = $this->make_learner( [ 'display_name' => '<script>alert(1)</script>' ] );
		Roles::grant_access( $nasty, $this->course, 'manual' );
		wp_set_current_user( $this->admin );

		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	/* ----- EnrollmentManager -------------------------------------------- */

	/** Adding a learner belongs to the Learners tab; this screen never duplicates it. */
	public function test_the_enrolment_manager_offers_no_enrol_action() {
		$this->assertNotContains( 'enroll', EnrollmentManager::ACTIONS );

		wp_set_current_user( $this->admin );

		$redirect = $this->post_enrollment_action(
			[ 'anchor_courses_action' => 'enroll', 'user_id' => (string) $this->learner ]
		);

		$this->assertStringContainsString( 'anchor_courses_admin_notice=error', $redirect );
		$this->assertFalse( ( new EnrollmentService() )->is_enrolled( $this->learner, $this->course ) );
	}

	public function test_a_learner_cannot_drive_the_enrolment_manager() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		wp_set_current_user( $this->learner );

		$redirect = $this->post_enrollment_action(
			[ 'anchor_courses_action' => 'cancel', 'user_id' => (string) $this->learner ]
		);

		$this->assertStringContainsString( 'anchor_courses_admin_notice=forbidden', $redirect );
		$this->assertTrue( ( new EnrollmentService() )->is_enrolled( $this->learner, $this->course ) );
	}

	public function test_a_bad_nonce_is_refused() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		wp_set_current_user( $this->admin );

		$_POST = [
			'anchor_courses_action' => 'cancel',
			'course_id'             => (string) $this->course,
			'user_id'               => (string) $this->learner,
			'_wpnonce'              => 'nope',
		];
		$_REQUEST = $_POST;

		try {
			( new EnrollmentManager() )->handle_action();
			$this->fail( 'Expected a redirect.' );
		} catch ( Anchor_Courses_Admin_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_admin_notice=bad_nonce', $e->getMessage() );
		}

		$this->assertTrue( ( new EnrollmentService() )->is_enrolled( $this->learner, $this->course ) );
	}

	public function test_reset_clears_progress_but_keeps_the_enrolment() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		$progress = new ProgressService();
		$progress->complete_lesson( $this->learner, $this->course, $this->lesson );

		wp_set_current_user( $this->admin );
		$redirect = $this->post_enrollment_action(
			[ 'anchor_courses_action' => 'reset', 'user_id' => (string) $this->learner ]
		);

		$this->assertStringContainsString( 'anchor_courses_admin_notice=reset', $redirect );
		$this->assertSame( 0.0, $progress->get_course_progress( $this->learner, $this->course )->percent );
		$this->assertTrue( ( new EnrollmentService() )->is_enrolled( $this->learner, $this->course ) );
	}

	/**
	 * Final review I6: reset used to delete progress rows only, leaving the
	 * attempts (and started_at) behind - with max_attempts=1 the learner was
	 * reset into a quiz they could never take again.
	 */
	public function test_reset_voids_the_attempts_and_clears_started_at() {
		$quiz = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 1 ] ] );
		$qs   = \Anchor\Courses\Content\Questions::save( $quiz, [ [ 'type' => 'single_choice', 'prompt' => 'P', 'points' => 1,
			'answers' => [ [ 'id' => 'a1', 'text' => 'W', 'correct' => false ], [ 'id' => 'a2', 'text' => 'R', 'correct' => true ] ] ] ] );
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $quiz ] ] ] ] );
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		$m       = $this->courses();
		$attempt = $m->quizzes->start_attempt( $this->learner, $quiz, $this->course );
		$m->quizzes->submit( $attempt->id, [ (string) $qs[0]['id'] => 'a1' ], $this->learner );
		$this->assertWPError( $m->quizzes->can_start( $this->learner, $quiz, $this->course ), 'Precondition: the one attempt is used.' );

		wp_set_current_user( $this->admin );
		$redirect = $this->post_enrollment_action(
			[ 'anchor_courses_action' => 'reset', 'user_id' => (string) $this->learner ]
		);

		$this->assertStringContainsString( 'anchor_courses_admin_notice=reset', $redirect );
		$this->assertTrue( $m->quizzes->can_start( $this->learner, $quiz, $this->course ), 'A reset learner must be able to retake.' );
		$this->assertSame( 'abandoned', $m->quizzes->get_attempt( $attempt->id )->status, 'The attempt is voided, not deleted.' );
		$enrollment = $m->enrollments->get( $this->learner, $this->course );
		$this->assertSame( 'enrolled', $enrollment->status );
		$this->assertNull( $enrollment->started_at );
	}

	/* --- Round 9, PR #32 finding 2: `reset_busy` must not misreport a
	   genuine write failure ------------------------------------------------ */

	/**
	 * A busy completion lock (a real completion/uncomplete pipeline mid-run
	 * for this row, standing in for a second real MySQL connection here)
	 * still reports `reset_busy` - the message that tells the operator to
	 * try again shortly is the right advice for exactly this case.
	 */
	public function test_reset_reports_reset_busy_while_the_completion_lock_is_held() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		$progress = new ProgressService();
		$progress->complete_lesson( $this->learner, $this->course, $this->lesson );

		$name  = \Anchor\Courses\Services\CompletionService::lock_name( $this->learner, $this->course );
		$other = new mysqli();
		$host  = explode( ':', DB_HOST );
		$other->real_connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, isset( $host[1] ) ? (int) $host[1] : 3306 );
		$this->assertSame(
			'1',
			(string) $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" )->fetch_row()[0],
			'Precondition: the lock is held by the "other" pipeline.'
		);
		add_filter( 'anchor_courses_completion_lock_timeout', static fn () => 0 );

		wp_set_current_user( $this->admin );
		$redirect = $this->post_enrollment_action(
			[ 'anchor_courses_action' => 'reset', 'user_id' => (string) $this->learner ]
		);

		remove_all_filters( 'anchor_courses_completion_lock_timeout' );
		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $name ) . "')" );
		$other->close();

		$this->assertStringContainsString( 'anchor_courses_admin_notice=reset_busy', $redirect );
	}

	/**
	 * A genuine write failure - the lock is free, the update itself fails at
	 * the database - is a DIFFERENT problem from a busy lock and must report
	 * its own code. Reporting `reset_busy` here would tell the operator to
	 * wait, which will never fix a real database error.
	 */
	public function test_reset_reports_reset_failed_when_the_enrolment_write_fails() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		$progress = new ProgressService();
		$progress->complete_lesson( $this->learner, $this->course, $this->lesson );

		global $wpdb;
		$wpdb->suppress_errors( true );
		$breaker = static function ( $query ) {
			return \preg_match( '/^UPDATE \S*anchor_courses_enrollments\b/i', (string) $query )
				? 'SELECT anchor_courses_injected_failure FROM no_such_table_anchor'
				: $query;
		};
		add_filter( 'query', $breaker );

		wp_set_current_user( $this->admin );
		$redirect = $this->post_enrollment_action(
			[ 'anchor_courses_action' => 'reset', 'user_id' => (string) $this->learner ]
		);

		remove_filter( 'query', $breaker );
		$wpdb->suppress_errors( false );

		$this->assertStringContainsString( 'anchor_courses_admin_notice=reset_failed', $redirect );
	}

	/**
	 * Minor from the final review: "complete" must report a refusal, not
	 * claim success. A learner with an unmet prerequisite cannot be granted
	 * access, so they cannot be marked complete either.
	 */
	public function test_complete_reports_a_refused_grant_instead_of_success() {
		add_role( 'anchor_course_999_completed', 'Completed: prereq', [] );
		update_post_meta( $this->course, '_anchor_course_prerequisites', [ 'anchor_course_999_completed' ] );

		wp_set_current_user( $this->admin );
		$redirect = $this->post_enrollment_action(
			[ 'anchor_courses_action' => 'complete', 'user_id' => (string) $this->learner ]
		);
		remove_role( 'anchor_course_999_completed' );

		$this->assertStringContainsString( 'anchor_courses_admin_notice=missing_prerequisite', $redirect );
		$this->assertStringNotContainsString( 'anchor_courses_admin_notice=completed', $redirect );
		$this->assertNull( ( new EnrollmentService() )->get( $this->learner, $this->course ) );
	}

	/** A pipeline refusal (e.g. no longer enrolled after the grant) reports complete_failed. */
	public function test_complete_reports_a_refused_completion() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		// Make is_enrolled() false while grant_access() still succeeds: an
		// expired window (expires_at in the past) is exactly that state.
		global $wpdb;
		$wpdb->update(
			\Anchor\Courses\Database\Migrations::table( 'enrollments' ),
			[ 'expires_at' => '2000-01-01 00:00:00' ],
			[ 'user_id' => $this->learner, 'course_id' => $this->course ]
		);

		wp_set_current_user( $this->admin );
		$redirect = $this->post_enrollment_action(
			[ 'anchor_courses_action' => 'complete', 'user_id' => (string) $this->learner ]
		);

		$this->assertStringContainsString( 'anchor_courses_admin_notice=complete_failed', $redirect );
		$this->assertNotSame( 'completed', ( new EnrollmentService() )->get( $this->learner, $this->course )->status );
	}

	/** Admin\Notices::redirect() only ever sends a registered code. */
	public function test_notices_redirect_whitelists_the_code() {
		try {
			Notices::redirect( 'not-a-registered-code', admin_url() );
		} catch ( Anchor_Courses_Admin_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_admin_notice=error', $e->getMessage() );
			return;
		}
		$this->fail( 'Expected a redirect.' );
	}

	public function test_notices_authorisation_reports_nonce_then_capability() {
		$_REQUEST = [ '_wpnonce' => 'bogus' ];
		wp_set_current_user( $this->admin );
		$this->assertSame( 'bad_nonce', Notices::authorisation_error( 'some_action', 'enrollments' ) );

		$_REQUEST = [ '_wpnonce' => wp_create_nonce( 'some_action' ) ];
		$this->assertSame( '', Notices::authorisation_error( 'some_action', 'enrollments' ) );

		wp_set_current_user( $this->learner );
		$_REQUEST = [ '_wpnonce' => wp_create_nonce( 'some_action' ) ];
		$this->assertSame( 'forbidden', Notices::authorisation_error( 'some_action', 'enrollments' ) );
	}

	/** Cancel takes the access role away too, whatever the loss policy says. */
	public function test_cancel_revokes_the_role_and_cancels_the_row() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'keep', 10, 4 );

		wp_set_current_user( $this->admin );
		$redirect = $this->post_enrollment_action(
			[ 'anchor_courses_action' => 'cancel', 'user_id' => (string) $this->learner ]
		);

		$this->assertStringContainsString( 'anchor_courses_admin_notice=cancelled', $redirect );
		$this->assertSame( 'cancelled', ( new EnrollmentService() )->get( $this->learner, $this->course )->status );
		$this->assertFalse( Roles::user_has( $this->learner, Roles::access_slug( $this->course ) ) );
	}

	/**
	 * The manual "complete" override runs the REAL once-only pipeline
	 * (Services\CompletionService), not a bare status write: the completion
	 * role, the CE credit and the certificate must all appear.
	 */
	public function test_complete_runs_the_completion_pipeline() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );

		wp_set_current_user( $this->admin );
		$redirect = $this->post_enrollment_action(
			[ 'anchor_courses_action' => 'complete', 'user_id' => (string) $this->learner ]
		);

		$this->assertStringContainsString( 'anchor_courses_admin_notice=completed', $redirect );
		$this->assertSame( 'completed', ( new EnrollmentService() )->get( $this->learner, $this->course )->status );
		$this->assertTrue( Roles::user_has( $this->learner, Roles::access_slug( $this->course ) ) );
		$this->assertTrue( Roles::user_has( $this->learner, Roles::completion_slug( $this->course ) ), 'pipeline grants the completion role' );
		$this->assertNotNull( \Anchor\Courses\Database\CreditRepository::find( $this->learner, $this->course ), 'pipeline awards the credit' );
	}

	public function test_uncomplete_reopens_the_enrollment() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		( new EnrollmentService() )->set_status( $this->learner, $this->course, 'completed' );

		wp_set_current_user( $this->admin );
		$redirect = $this->post_enrollment_action(
			[ 'anchor_courses_action' => 'uncomplete', 'user_id' => (string) $this->learner ]
		);

		$this->assertStringContainsString( 'anchor_courses_admin_notice=uncompleted', $redirect );
		// CompletionService::uncomplete() reopens to in_progress (the work had started), never to bare enrolled.
		$this->assertSame( 'in_progress', ( new EnrollmentService() )->get( $this->learner, $this->course )->status );
	}

	/* ----- Notices -------------------------------------------------------- */

	public function test_an_unregistered_notice_code_renders_nothing() {
		$_GET['anchor_courses_admin_notice'] = 'anchor_test_totally_unknown_code_31';
		set_current_screen( CoursePostType::CPT );

		ob_start();
		( new Notices() )->render();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	public function test_a_registered_notice_code_renders_its_message_escaped() {
		Notices::register( 'anchor_test_registered_code_31', Notices::TYPE_SUCCESS, '<script>alert(1)</script>' );
		$_GET['anchor_courses_admin_notice'] = 'anchor_test_registered_code_31';
		set_current_screen( CoursePostType::CPT );

		ob_start();
		( new Notices() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	/** EnrollmentManager registers its own codes into the shared registry on construction. */
	public function test_the_enrolment_managers_own_codes_render_through_notices() {
		new EnrollmentManager();
		$_GET['anchor_courses_admin_notice'] = 'cancelled';
		set_current_screen( CoursePostType::CPT );

		ob_start();
		( new Notices() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Enrolment cancelled', $html );
	}

	/** CourseEditor's and LearnerReports' pre-existing, previously-unrendered codes. */
	public function test_pre_existing_codes_from_other_admin_classes_render() {
		$_GET['anchor_courses_admin_notice'] = 'role_deleted';
		set_current_screen( CoursePostType::CPT );

		ob_start();
		( new Notices() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Role deleted', $html );
	}

	public function test_a_notice_only_renders_on_an_allowed_screen() {
		Notices::register( 'anchor_test_screen_gated_code_31', Notices::TYPE_SUCCESS, 'Screen-gated message' );
		$_GET['anchor_courses_admin_notice'] = 'anchor_test_screen_gated_code_31';

		set_current_screen( 'dashboard' );
		ob_start();
		( new Notices() )->render();
		$wrong_screen = (string) ob_get_clean();
		$this->assertSame( '', $wrong_screen, 'A registered code must not render on an unrelated admin screen.' );

		set_current_screen( CoursePostType::CPT );
		ob_start();
		( new Notices() )->render();
		$right_screen = (string) ob_get_clean();
		$this->assertStringContainsString( 'Screen-gated message', $right_screen );

		set_current_screen( 'profile' );
		ob_start();
		( new Notices() )->render();
		$profile_screen = (string) ob_get_clean();
		$this->assertStringContainsString( 'Screen-gated message', $profile_screen );
	}
}
