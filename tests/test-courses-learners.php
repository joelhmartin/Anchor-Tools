<?php
/**
 * Anchor Courses - the Learners tab and account resolution (design spec 3.1).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\LearnerReports;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Accounts;
use Anchor\Courses\Support\Roles;

/** Thrown from wp_redirect so the handler's exit() never runs. */
class Anchor_Courses_Learners_Redirected extends \Exception {}

/** @group courses */
class Test_Courses_Learners extends Anchor_Courses_TestCase {

	private EnrollmentService $enrollments;
	private int $admin;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->enrollments = new EnrollmentService();
		$this->admin       = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->course      = $this->make_course( [], 'Laser Safety' );

		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		remove_all_filters( 'anchor_courses_create_account' );
		remove_all_filters( 'anchor_courses_role_loss_policy' );
		$_POST    = [];
		$_REQUEST = [];
		$_GET     = [];
		parent::tear_down();
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Courses_Learners_Redirected( (string) $location );
	}

	/** @return string The redirect URL the handler tried to send. */
	private function post_learner_action( string $action, array $fields ): string {
		$_POST = array_merge(
			$fields,
			[ '_wpnonce' => wp_create_nonce( 'anchor_courses_learners_' . $this->course ), 'course_id' => (string) $this->course ]
		);
		$_REQUEST = $_POST;

		try {
			'add' === $action
				? ( new LearnerReports() )->handle_add_learner()
				: ( new LearnerReports() )->handle_revoke();
		} catch ( Anchor_Courses_Learners_Redirected $e ) {
			return $e->getMessage();
		}

		$this->fail( 'Expected a redirect.' );
	}

	/* ----- Support\Accounts ------------------------------------------- */

	public function test_ensure_user_returns_an_existing_account_by_email() {
		$existing = self::factory()->user->create( [ 'user_email' => 'known@example.test' ] );

		$this->assertSame( $existing, Accounts::ensure_user( 'Someone Else', 'known@example.test' ) );
	}

	public function test_ensure_user_creates_an_account_without_emailing() {
		$mails = [];
		$spy   = function ( $args ) use ( &$mails ) { $mails[] = $args; return $args; };
		add_filter( 'wp_mail', $spy );

		$user_id = Accounts::ensure_user( 'Grace Hopper', 'grace@example.test' );

		remove_filter( 'wp_mail', $spy );

		$this->assertGreaterThan( 0, $user_id );
		$this->assertSame( 'grace@example.test', ( new WP_User( $user_id ) )->user_email );
		$this->assertSame( 'Grace Hopper', ( new WP_User( $user_id ) )->display_name );
		$this->assertSame( [], $mails, 'Adding a learner must not send a new-account email.' );
	}

	public function test_ensure_user_refuses_a_bad_email_and_can_be_opted_out() {
		$this->assertSame( 0, Accounts::ensure_user( 'Nobody', 'not-an-email' ) );

		add_filter( 'anchor_courses_create_account', '__return_false' );
		$this->assertSame( 0, Accounts::ensure_user( 'Nobody', 'nope@example.test' ) );
		$this->assertNull( get_user_by( 'email', 'nope@example.test' ) ?: null );
	}

	/* ----- Adding a learner -------------------------------------------- */

	public function test_add_creates_the_account_grants_the_role_and_records_the_row() {
		wp_set_current_user( $this->admin );

		$url = $this->post_learner_action( 'add', [ 'learner_name' => 'Ada Lovelace', 'learner_email' => 'ada@example.test' ] );
		$this->assertStringContainsString( 'anchor_courses_admin_notice=learner_added', $url );

		$user = get_user_by( 'email', 'ada@example.test' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertTrue( Roles::user_has( (int) $user->ID, Roles::access_slug( $this->course ) ) );

		$enrollment = $this->enrollments->get( (int) $user->ID, $this->course );
		$this->assertNotNull( $enrollment );
		$this->assertSame( 'manual', $enrollment->source );
		$this->assertSame( (string) $this->admin, $enrollment->source_id, 'The actor is recorded, so "who let them in" is answerable.' );
	}

	public function test_adding_the_same_person_twice_changes_nothing() {
		wp_set_current_user( $this->admin );
		$this->post_learner_action( 'add', [ 'learner_name' => 'Ada', 'learner_email' => 'ada@example.test' ] );
		$first = $this->enrollments->get( (int) get_user_by( 'email', 'ada@example.test' )->ID, $this->course )->id;

		$this->post_learner_action( 'add', [ 'learner_name' => 'Ada', 'learner_email' => 'ada@example.test' ] );

		$this->assertCount( 1, LearnerReports::rows( $this->course ) );
		$this->assertSame( $first, $this->enrollments->get( (int) get_user_by( 'email', 'ada@example.test' )->ID, $this->course )->id );
	}

	public function test_add_refuses_an_unmet_prerequisite() {
		add_role( 'anchor_course_555_completed', 'Completed: Required First', [] );
		update_post_meta( $this->course, '_anchor_course_prerequisites', [ 'anchor_course_555_completed' ] );
		wp_set_current_user( $this->admin );

		$url = $this->post_learner_action( 'add', [ 'learner_name' => 'Ada', 'learner_email' => 'ada@example.test' ] );

		$this->assertStringContainsString( 'anchor_courses_admin_notice=missing_prerequisite', $url );

		$user = get_user_by( 'email', 'ada@example.test' );
		$this->assertInstanceOf( WP_User::class, $user, 'The account is still created; only the access is refused.' );
		$this->assertFalse( Roles::user_has( (int) $user->ID, Roles::access_slug( $this->course ) ) );
		$this->assertNull( $this->enrollments->get( (int) $user->ID, $this->course ) );

		remove_role( 'anchor_course_555_completed' );
	}

	public function test_add_refuses_without_the_enrollments_capability() {
		wp_set_current_user( $this->make_learner() );

		$url = $this->post_learner_action( 'add', [ 'learner_name' => 'Ada', 'learner_email' => 'ada@example.test' ] );

		$this->assertStringContainsString( 'anchor_courses_admin_notice=forbidden', $url );
		$this->assertNull( get_user_by( 'email', 'ada@example.test' ) ?: null );
	}

	public function test_add_refuses_a_bad_nonce() {
		wp_set_current_user( $this->admin );
		$_POST    = [ 'course_id' => (string) $this->course, 'learner_name' => 'Ada', 'learner_email' => 'ada@example.test', '_wpnonce' => 'nope' ];
		$_REQUEST = $_POST;

		try {
			( new LearnerReports() )->handle_add_learner();
			$this->fail( 'Expected a redirect.' );
		} catch ( Anchor_Courses_Learners_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_admin_notice=bad_nonce', $e->getMessage() );
		}

		$this->assertNull( get_user_by( 'email', 'ada@example.test' ) ?: null );
	}

	/* ----- Revoking ----------------------------------------------------- */

	public function test_revoke_removes_the_role_and_applies_the_loss_policy() {
		$learner = $this->make_learner();
		Roles::grant_access( $learner, $this->course, 'manual' );
		wp_set_current_user( $this->admin );

		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		$url = $this->post_learner_action( 'revoke', [ 'user_id' => (string) $learner ] );

		$this->assertStringContainsString( 'anchor_courses_admin_notice=access_revoked', $url );
		$this->assertFalse( Roles::user_has( $learner, Roles::access_slug( $this->course ) ) );
		$this->assertSame( 'cancelled', $this->enrollments->get( $learner, $this->course )->status );
	}

	/** Roles::revoke_access() returns false for a user who never held the role - the redirect must say so, not lie with "access_revoked". */
	public function test_revoke_reports_failure_when_the_user_never_held_the_role() {
		$bystander = $this->make_learner();
		wp_set_current_user( $this->admin );

		$url = $this->post_learner_action( 'revoke', [ 'user_id' => (string) $bystander ] );

		$this->assertStringContainsString( 'anchor_courses_admin_notice=revoke_failed', $url );
	}

	/* ----- The table ----------------------------------------------------- */

	public function test_rows_carry_the_phase_two_columns() {
		$learner = $this->make_learner( [ 'display_name' => 'Ada Lovelace' ] );
		Roles::grant_access( $learner, $this->course, 'manual' );

		$rows = LearnerReports::rows( $this->course );

		$this->assertCount( 1, $rows );
		foreach ( [ 'user_id', 'display_name', 'user_email', 'enrolled_at', 'percent', 'status', 'completed_at', 'has_access' ] as $column ) {
			$this->assertArrayHasKey( $column, $rows[0], "Missing column {$column}" );
		}
		$this->assertSame( 'Ada Lovelace', $rows[0]['display_name'] );
		// make_course() gives this course no curriculum, so total_required is 0
		// and ProgressService::percent() returns 100.0 by contract ("a course
		// with nothing required is 100%" - see its docblock, and
		// test_a_new_learner_is_at_zero_percent() for the populated-curriculum
		// case, which is 0.0). This assertion follows that existing contract
		// rather than the brief's literal 0.0 - rows()'s numeric 'percent' is
		// unchanged by the fix-round ruling below. What changed is the
		// RENDERED table: it shows an em dash instead of "100%" for this same
		// empty-curriculum case, because 100% reads as "finished" to a human
		// looking at the Learners tab (fix round 1 review ruling). See
		// test_progress_cell_shows_an_em_dash_for_a_course_with_no_curriculum().
		$this->assertSame( 100.0, $rows[0]['percent'] );
		$this->assertTrue( $rows[0]['has_access'] );
	}

	/** A learner whose role was taken away but whose row was kept reads as "no access". */
	public function test_a_kept_enrolment_without_the_role_reports_no_access() {
		$learner = $this->make_learner();
		Roles::grant_access( $learner, $this->course, 'manual' );
		Roles::revoke_access( $learner, $this->course, 'admin' );

		$rows = LearnerReports::rows( $this->course );

		$this->assertCount( 1, $rows, 'The row is kept by default (anchor_courses_role_loss_policy).' );
		$this->assertFalse( $rows[0]['has_access'] );
	}

	/** design brief: rows() paginates but render_learners() never surfaced it - a 51st learner was silently truncated. */
	public function test_the_learners_table_paginates_at_fifty_rows() {
		for ( $i = 0; $i < 51; $i++ ) {
			Roles::grant_access( $this->make_learner(), $this->course, 'manual' );
		}
		wp_set_current_user( $this->admin );

		// Page 1: 50 rows, a next link, the running total.
		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$page1 = (string) ob_get_clean();
		$this->assertSame( 50, substr_count( $page1, '<tr><td' ) );
		$this->assertStringContainsString( 'of 51', $page1 );
		$this->assertStringContainsString( 'anchor_learners_page=2', $page1 );

		// Page 2: the one remaining row, a prev link, and no next link (last page).
		$_GET['anchor_learners_page'] = '2';
		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$page2 = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $page2, '<tr><td' ) );
		$this->assertStringContainsString( 'of 51', $page2 );
		$this->assertStringContainsString( 'anchor_learners_page=1', $page2 );
		$this->assertStringNotContainsString( 'anchor_learners_page=3', $page2 );

		// A page number of 0, negative, or non-numeric all fall back to page 1.
		foreach ( [ '0', '-4', 'nope' ] as $garbage ) {
			$_GET['anchor_learners_page'] = $garbage;
			ob_start();
			( new LearnerReports() )->render_learners( get_post( $this->course ) );
			$html = (string) ob_get_clean();
			$this->assertSame( 50, substr_count( $html, '<tr><td' ), "page={$garbage} should behave like page 1" );
		}
	}

	/** Ruling (fix round 1 review): 100% reads as "finished", so an empty curriculum must not render as one. */
	public function test_progress_cell_shows_an_em_dash_for_a_course_with_no_curriculum() {
		Roles::grant_access( $this->make_learner(), $this->course, 'manual' );
		wp_set_current_user( $this->admin );

		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '—', $html );
		$this->assertStringNotContainsString( '100%', $html );
	}

	public function test_progress_cell_shows_a_percentage_once_the_one_required_item_is_complete() {
		$lesson = $this->make_lesson( [], 'L1' );
		Curriculum::save( $this->course, [ [ 'title' => 'M1', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );

		$learner = $this->make_learner();
		Roles::grant_access( $learner, $this->course, 'manual' );
		( new ProgressService() )->complete_lesson( $learner, $this->course, $lesson );

		wp_set_current_user( $this->admin );
		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '100%', $html );
		$this->assertStringNotContainsString( '—', $html );
	}

	public function test_the_metabox_needs_the_reports_capability_and_escapes_its_output() {
		$nasty = $this->make_learner( [ 'display_name' => '<script>alert(1)</script>' ] );
		Roles::grant_access( $nasty, $this->course, 'manual' );

		wp_set_current_user( $this->make_learner() );
		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$denied = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'alert(1)', $denied );

		wp_set_current_user( $this->admin );
		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$allowed = (string) ob_get_clean();
		$this->assertStringContainsString( 'anchor_courses_add_learner', $allowed );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $allowed );
	}
}
