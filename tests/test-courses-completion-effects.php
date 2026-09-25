<?php
/**
 * Audit F02 (docs/audits/2026-09-25-events-lms-audit.md): completion effects
 * are tracked one by one and a retry repairs exactly the missing ones.
 *
 * The regression-acceptance list: independently fail the credit insert, the
 * certificate insert, the credit/certificate link, the completion-role grant
 * and a completion-hook consumer; retry; verify complete, non-duplicated
 * outcomes. Failures are injected against the real test database - a `query`
 * filter turns the one targeted statement into invalid SQL, so $wpdb reports
 * a genuine error for that statement and nothing else.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Database\CertificateRepository;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CompletionService;
use Anchor\Courses\Services\CreditService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** Thrown from wp_redirect so the handler's exit() never runs. */
class Anchor_Courses_Effects_Redirected extends \Exception {}

/** @group courses */
class Test_Courses_Completion_Effects extends Anchor_Courses_TestCase {

	private CompletionService $completion;
	private ProgressService $progress;
	private EnrollmentService $enrollments;
	private int $user;
	private int $course;
	private int $lesson;

	/** @var callable|null */
	private $breaker = null;

	/** @var array<string,int> */
	private array $fired = [];

	/** A second real MySQL connection, standing in for a parallel pipeline (Codex review, finding 1). */
	private ?mysqli $other = null;

	public function set_up() {
		parent::set_up();
		$this->enrollments = new EnrollmentService();
		$this->progress    = new ProgressService( $this->enrollments );
		$this->completion  = new CompletionService( $this->enrollments, $this->progress );
		$this->progress->set_completion_service( $this->completion );

		$this->user   = $this->make_learner( [ 'display_name' => 'Ada' ] );
		$this->course = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '2', 'certificate_enabled' => 1 ], 'Laser Safety' );
		$this->lesson = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
		Roles::grant_access( $this->user, $this->course );

		$this->fired = [ 'credit' => 0, 'certificate' => 0, 'course' => 0 ];
		add_action( 'anchor_courses_ce_credit_awarded', function () { $this->fired['credit']++; } );
		add_action( 'anchor_courses_certificate_issued', function () { $this->fired['certificate']++; } );
		add_action( 'anchor_courses_course_completed', function () { $this->fired['course']++; } );
	}

	public function tear_down() {
		$this->heal();
		remove_all_actions( 'anchor_courses_course_completed' );
		remove_all_actions( 'anchor_courses_ce_credit_awarded' );
		remove_all_actions( 'anchor_courses_certificate_issued' );
		remove_all_actions( 'add_user_role' );
		remove_all_filters( 'anchor_courses_completion_lock_timeout' );
		if ( $this->other ) {
			$this->other->close();
			$this->other = null;
		}
		parent::tear_down();
	}

	/** Make every query matching $pattern fail at the database. */
	private function break_queries( string $pattern ): void {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$this->breaker = static function ( $query ) use ( $pattern ) {
			return preg_match( $pattern, (string) $query ) ? 'SELECT anchor_courses_injected_failure FROM no_such_table_anchor' : $query;
		};
		add_filter( 'query', $this->breaker );
	}

	private function heal(): void {
		global $wpdb;
		if ( $this->breaker ) {
			remove_filter( 'query', $this->breaker );
			$this->breaker = null;
		}
		$wpdb->suppress_errors( false );
	}

	private function finish_lesson(): void {
		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );
	}

	private function effects(): array {
		return $this->completion->effects( $this->user, $this->course );
	}

	private function assert_all_done_once(): void {
		$this->assertSame(
			[ 'credit' => 'done', 'certificate' => 'done', 'completion_role' => 'done', 'hook' => 'done' ],
			$this->effects()
		);
		$this->assertSame( 1, count( CreditRepository::for_course( $this->course ) ), 'One credit row.' );
		$certificate = CertificateRepository::find( $this->user, $this->course );
		$this->assertNotNull( $certificate );
		$this->assertSame( $certificate->id, CreditRepository::find( $this->user, $this->course )->certificate_id, 'Credit linked to certificate.' );
		$this->assertTrue( Roles::user_has( $this->user, Roles::completion_slug( $this->course ) ) );
		$this->assertSame( [ 'credit' => 1, 'certificate' => 1, 'course' => 1 ], $this->fired, 'Every hook fired exactly once across both calls.' );
	}

	/** The audit's reproduction: credit issuance fails after the flip; an ordinary retry must repair it. */
	public function test_a_failed_credit_insert_is_repaired_by_a_retry() {
		$this->break_queries( '/^INSERT IGNORE INTO \S*anchor_courses_ce_credits/' );
		$this->finish_lesson();
		$this->heal();

		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ), 'The atomic transition still happened.' );
		$this->assertNull( CreditRepository::find( $this->user, $this->course ) );
		$this->assertNull( CertificateRepository::find( $this->user, $this->course ), 'The certificate waits for the credit it vouches for.' );

		$this->assertFalse( $this->completion->complete( $this->user, $this->course ), 'Not a second transition...' );
		$this->assertNotNull( CreditRepository::find( $this->user, $this->course ), '...but the retry awards the missing credit.' );
		$this->assertEquals( 2.0, CertificateRepository::find( $this->user, $this->course )->metadata['credits'], 'The certificate vouches for the credit actually awarded.' );
		$this->assert_all_done_once();
	}

	public function test_a_failed_certificate_insert_is_repaired_by_a_retry() {
		$this->break_queries( '/^INSERT IGNORE INTO \S*anchor_courses_certificates/' );
		$this->finish_lesson();
		$this->heal();
		$this->assertNull( CertificateRepository::find( $this->user, $this->course ) );

		$this->completion->complete( $this->user, $this->course );
		$this->assertNotNull( CertificateRepository::find( $this->user, $this->course ), 'The retry issues the missing certificate.' );
		$this->assertSame( 'done', $this->effects()['credit'] );
		$this->assert_all_done_once();
	}

	public function test_a_failed_credit_certificate_link_is_repaired_by_a_retry() {
		$this->break_queries( '/^UPDATE `?\S*anchor_courses_ce_credits`? SET `certificate_id`/' );
		$this->finish_lesson();
		$this->heal();

		$this->assertSame( 0, CreditRepository::find( $this->user, $this->course )->certificate_id );
		$this->assertSame( 'failed', $this->effects()['certificate'], 'An unlinked certificate is not a finished certificate effect.' );

		$this->completion->complete( $this->user, $this->course );
		$this->assert_all_done_once();
	}

	public function test_a_failed_completion_role_grant_is_repaired_by_a_retry() {
		$slug  = Roles::completion_slug( $this->course );
		$throw = static function ( $user_id, $role ) use ( $slug ) {
			if ( $role === $slug ) {
				throw new \RuntimeException( 'injected role failure' );
			}
		};
		add_action( 'add_user_role', $throw, 10, 2 );
		$this->finish_lesson();
		remove_action( 'add_user_role', $throw, 10 );

		$this->assertSame( 'failed', $this->effects()['completion_role'] );
		$this->assertSame( 'done', $this->effects()['hook'], 'A failed effect does not stop the ones after it.' );

		$this->completion->complete( $this->user, $this->course );
		$this->assert_all_done_once();
	}

	public function test_a_throwing_completion_hook_consumer_is_retried_and_then_not_fired_again() {
		$armed = true;
		$throw = static function () use ( &$armed ) {
			if ( $armed ) {
				throw new \RuntimeException( 'injected consumer failure' );
			}
		};
		add_action( 'anchor_courses_course_completed', $throw, 20 );
		$this->finish_lesson();

		$this->assertSame( 'failed', $this->effects()['hook'] );
		$this->assertSame( 1, $this->fired['course'] );

		$armed = false;
		$this->completion->complete( $this->user, $this->course );
		$this->assertSame( 'done', $this->effects()['hook'] );
		$this->assertSame( 2, $this->fired['course'], 'A failed hook is fired again on repair.' );

		$this->completion->complete( $this->user, $this->course );
		$this->assertSame( 2, $this->fired['course'], 'Once done, never again.' );
	}

	/** Not-applicable is not failed: a zero-credit, no-certificate course has nothing to repair. */
	public function test_not_applicable_effects_are_recorded_as_such() {
		update_post_meta( $this->course, '_anchor_course_ce_credits', '0' );
		update_post_meta( $this->course, '_anchor_course_certificate_enabled', 0 );
		$this->finish_lesson();

		$this->assertSame(
			[ 'credit' => 'n/a', 'certificate' => 'n/a', 'completion_role' => 'done', 'hook' => 'done' ],
			$this->effects()
		);
		$this->completion->complete( $this->user, $this->course );
		$this->assertSame( 1, $this->fired['course'] );
	}

	/** A healthy first run is all done and a retry changes nothing. */
	public function test_a_healthy_completion_records_every_effect_done() {
		$this->finish_lesson();
		$this->completion->complete( $this->user, $this->course );
		$this->assert_all_done_once();
	}

	/**
	 * Re-review (Low): `save_effects()` - the write that records each effect's
	 * OUTCOME, not one of the tracked effects itself - must tell a genuine
	 * database error apart from success, the same way award()/issue() already
	 * do. Exercised directly (it is CompletionService's own private method)
	 * so this proves the exact contract, independent of which caller uses it.
	 */
	public function test_save_effects_distinguishes_a_database_error_from_success() {
		$enrollment = $this->enrollments->get( $this->user, $this->course );
		$method     = new ReflectionMethod( CompletionService::class, 'save_effects' );
		$method->setAccessible( true );
		$state = [ 'credit' => 'done', 'certificate' => 'done', 'completion_role' => 'done', 'hook' => 'done' ];

		$this->assertTrue( $method->invoke( $this->completion, $enrollment->id, $state ), 'A healthy write succeeds.' );

		$this->break_queries( '/^UPDATE `?\S*anchor_courses_enrollments`? SET `metadata`/' );
		$failed = $method->invoke( $this->completion, $enrollment->id, $state );
		$this->heal();

		$this->assertFalse( $failed, 'A database error must not look like a successful save.' );
	}

	/**
	 * CodeRabbit PR #32 (audit F02 re-review): a failed write of the tracking
	 * record must not permanently strand the row it describes. The
	 * transition and every effect's real side effect still happen (separate
	 * queries) - only the bookkeeping write is broken here - so the row ends
	 * up completed with a real credit/certificate/role/hook, but `effects()`
	 * reports nothing until a repair (with writes healthy) re-runs the
	 * idempotent effects and durably records them: `repaired`, not a
	 * distinct "nothing to verify" outcome, and no duplicate credit,
	 * certificate or role, and no second hook fire.
	 */
	public function test_a_failed_effects_metadata_write_leaves_the_row_untracked_but_still_completes_it() {
		$this->break_queries( '/^UPDATE `?\S*anchor_courses_enrollments`? SET `metadata`/' );
		$this->finish_lesson();
		$this->heal();

		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ), 'The atomic transition is a different write - unaffected.' );
		$this->assertSame( [], $this->effects(), 'The tracking write never landed.' );
		$this->assertNotNull( CreditRepository::find( $this->user, $this->course ), 'The credit effect itself still ran.' );
		$this->assertNotNull( CertificateRepository::find( $this->user, $this->course ) );
		$this->assertTrue( Roles::user_has( $this->user, Roles::completion_slug( $this->course ) ) );
		$this->assertSame( 1, $this->fired['course'], 'The hook still fired for real.' );

		// Repair now runs with a healthy database: the idempotent effects
		// re-run (no-ops - the row already has a credit, certificate and
		// role) and this time land durably, so this reports `repaired`.
		$this->assertStringContainsString( 'anchor_courses_admin_notice=repaired', $this->post_repair() );
		$this->assertSame( 1, count( CreditRepository::for_course( $this->course ) ), 'No duplicate credit from the re-run.' );
		$this->assertSame( 1, $this->fired['course'], 'The hook is never re-fired for an untracked row.' );
		$this->assertSame(
			[ 'credit' => 'done', 'certificate' => 'done', 'completion_role' => 'done', 'hook' => 'n/a' ],
			$this->effects(),
			'The repair now records real state - the hook as n/a, since it is never re-run for an untracked row.'
		);
	}

	/* --- award() / issue(): not-applicable (null + reason) vs failed (WP_Error) --- */

	public function test_award_distinguishes_nothing_to_award_from_a_failed_insert() {
		$credits = new CreditService();
		$free    = $this->make_course( [ 'ce_credits' => '0' ] );

		$this->assertNull( $credits->award( $this->user, $free ) );
		$this->assertSame( 'no_credits', $credits->last_skip_reason() );

		$this->break_queries( '/^INSERT IGNORE INTO \S*anchor_courses_ce_credits/' );
		$result = $credits->award( $this->user, $this->course );
		$this->heal();
		$this->assertWPError( $result );
		$this->assertSame( 'credit_insert_failed', $result->get_error_code() );
		$this->assertSame( '', $credits->last_skip_reason() );
	}

	public function test_issue_distinguishes_disabled_from_a_failed_insert() {
		$certificates = new CertificateService();
		$off          = $this->make_course( [ 'certificate_enabled' => 0 ] );

		$this->assertNull( $certificates->issue( $this->user, $off ) );
		$this->assertSame( 'certificates_disabled', $certificates->last_skip_reason() );

		$this->break_queries( '/^INSERT IGNORE INTO \S*anchor_courses_certificates/' );
		$result = $certificates->issue( $this->user, $this->course );
		$this->heal();
		$this->assertWPError( $result );
		$this->assertSame( 'certificate_insert_failed', $result->get_error_code() );
	}

	/* --- The admin "Repair completion" action (EnrollmentManager) --- */

	public function trap_redirect( $location ) {
		throw new Anchor_Courses_Effects_Redirected( (string) $location );
	}

	private function post_repair(): string {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST    = [
			'_wpnonce'              => wp_create_nonce( \Anchor\Courses\Admin\EnrollmentManager::NONCE . '_' . $this->course ),
			'course_id'             => (string) $this->course,
			'user_id'               => (string) $this->user,
			'anchor_courses_action' => 'repair',
		];
		$_REQUEST = $_POST;
		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		try {
			( new \Anchor\Courses\Admin\EnrollmentManager() )->handle_action();
		} catch ( Anchor_Courses_Effects_Redirected $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
			$_POST    = [];
			$_REQUEST = [];
		}
		$this->fail( 'Expected a redirect.' );
	}

	public function test_the_admin_repair_action_re_runs_failed_effects() {
		$this->assertStringContainsString( 'anchor_courses_admin_notice=repair_not_completed', $this->post_repair() );

		// The module's own services, as the handler uses them.
		$module = $this->courses();
		$this->break_queries( '/^INSERT IGNORE INTO \S*anchor_courses_ce_credits/' );
		$module->progress->complete_lesson( $this->user, $this->course, $this->lesson );
		$this->heal();
		$this->assertSame( 'failed', $module->completion->effects( $this->user, $this->course )['credit'] );

		$this->assertStringContainsString( 'anchor_courses_admin_notice=repaired', $this->post_repair() );
		$this->assertNotNull( CreditRepository::find( $this->user, $this->course ) );
	}

	/**
	 * CodeRabbit PR #32 (audit F02 re-review): a row completed before effect
	 * tracking existed at all (never went through run_effects(), so metadata
	 * has no completion_effects key, and none of credit/certificate/role
	 * were ever created either) has an UNKNOWN outcome, not a done one.
	 * Repair now re-runs the idempotent effects for real - creating the
	 * credit, certificate and role this row never got - and reports
	 * `repaired`, without ever firing the completion hook for a row that
	 * never went through the fresh pipeline.
	 */
	public function test_the_repair_action_reports_untracked_for_a_row_that_predates_effect_tracking() {
		$enrollment = $this->enrollments->get( $this->user, $this->course );
		EnrollmentRepository::complete( $enrollment->id, '2026-01-01 00:00:00' );
		$this->assertSame( [], $this->completion->effects( $this->user, $this->course ), 'Precondition: nothing was ever tracked.' );
		$this->assertNull( CreditRepository::find( $this->user, $this->course ), 'Precondition: no credit was ever created for this row.' );

		$this->assertStringContainsString( 'anchor_courses_admin_notice=repaired', $this->post_repair() );

		$this->assertNotNull( CreditRepository::find( $this->user, $this->course ), 'The repair created the credit this row never got.' );
		$this->assertNotNull( CertificateRepository::find( $this->user, $this->course ) );
		$this->assertTrue( Roles::user_has( $this->user, Roles::completion_slug( $this->course ) ) );
		$this->assertSame( 0, $this->fired['course'], 'The hook is never re-run for a row that never went through the fresh pipeline.' );
		$this->assertSame(
			[ 'credit' => 'done', 'certificate' => 'done', 'completion_role' => 'done', 'hook' => 'n/a' ],
			$this->completion->effects( $this->user, $this->course )
		);
	}

	/* --- Codex review, PR #32 finding 1: effect execution is serialised --- */

	/**
	 * A second caller that finds the row already complete - because the
	 * first caller's transition landed but its effects have not finished -
	 * must never run the same pending effects concurrently. Both reach for
	 * the same MySQL named lock; a real second connection holds it here,
	 * standing in for the still-running first pipeline, so this call must
	 * back off and run nothing.
	 */
	public function test_a_concurrent_caller_backs_off_while_another_pipeline_holds_the_completion_lock() {
		// Leave a real pending effect behind, exactly like the audit F02
		// reproduction: the credit insert fails once, healed afterward, so
		// the row is genuinely complete with credit still outstanding.
		$this->break_queries( '/^INSERT IGNORE INTO \S*anchor_courses_ce_credits/' );
		$this->finish_lesson();
		$this->heal();
		$this->assertSame( 'failed', $this->effects()['credit'], 'Precondition: a real effect is outstanding.' );

		$name = CompletionService::lock_name( $this->user, $this->course );
		$this->other = new mysqli();
		$host        = explode( ':', DB_HOST );
		$this->other->real_connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, isset( $host[1] ) ? (int) $host[1] : 3306 );
		$this->assertSame(
			'1',
			(string) $this->other->query( "SELECT GET_LOCK('" . $this->other->real_escape_string( $name ) . "', 0)" )->fetch_row()[0],
			'Precondition: the lock is held by the "other" pipeline.'
		);

		// `hook` already ran (and fired once) on the fresh transition above -
		// only `credit` (and, by dependency, `certificate`) is outstanding.
		// Snapshot the counts here so the assertion below is about what the
		// LOCKED-OUT call itself did, not the whole test.
		$before_fired = $this->fired;

		add_filter( 'anchor_courses_completion_lock_timeout', static fn () => 0 );
		$result = $this->completion->complete( $this->user, $this->course );

		$this->assertFalse( $result, 'A repair call must back off, not run effects, while the lock is held elsewhere.' );
		$this->assertNull( CreditRepository::find( $this->user, $this->course ), 'Nothing ran without the lock.' );
		$this->assertSame( $before_fired, $this->fired, 'No hook fired for the caller that could not get the lock.' );
		$this->assertSame( 'failed', $this->effects()['credit'], 'The stored state is untouched by the caller that never ran.' );

		// Release the lock: an ordinary retry now completes it, same as any
		// other repair.
		$this->other->query( "SELECT RELEASE_LOCK('" . $this->other->real_escape_string( $name ) . "')" );
		remove_all_filters( 'anchor_courses_completion_lock_timeout' );
		$this->completion->complete( $this->user, $this->course );
		$this->assert_all_done_once();
	}

	/**
	 * A `running` claim left behind by a crashed pipeline is exactly what
	 * `save_effects()` wrote right before the crash - stale once older than
	 * EFFECTS_RUNNING_STALE_SECONDS, so the next repair (itself holding the
	 * lock, so this can never be a second live pipeline) treats it as
	 * pending and finishes the job.
	 */
	public function test_a_stale_running_claim_is_treated_as_pending_and_repaired() {
		$enrollment = $this->enrollments->get( $this->user, $this->course );
		EnrollmentRepository::complete( $enrollment->id, gmdate( 'Y-m-d H:i:s' ) );
		EnrollmentRepository::update(
			$enrollment->id,
			[
				'metadata' => [
					CompletionService::EFFECTS_META         => [
						'credit'          => CompletionService::EFFECT_RUNNING,
						'certificate'     => CompletionService::EFFECT_PENDING,
						'completion_role' => CompletionService::EFFECT_DONE,
						'hook'            => CompletionService::EFFECT_DONE,
					],
					CompletionService::EFFECTS_CLAIMED_META => gmdate( 'Y-m-d H:i:s', time() - CompletionService::EFFECTS_RUNNING_STALE_SECONDS - 1 ),
				],
			]
		);

		$this->completion->complete( $this->user, $this->course );

		$this->assertSame(
			[ 'credit' => 'done', 'certificate' => 'done', 'completion_role' => 'done', 'hook' => 'done' ],
			$this->effects(),
			"A crashed pipeline's stale claim is re-run as pending, not left running forever."
		);
		$this->assertNotNull( CreditRepository::find( $this->user, $this->course ) );
		$this->assertNotNull( CertificateRepository::find( $this->user, $this->course ) );
		$this->assertSame( [ 'credit' => 1, 'certificate' => 1, 'course' => 0 ], $this->fired, 'Only the two re-run effects fire; completion_role/hook were already done and are untouched.' );
	}

	/**
	 * The counterpart: a claim younger than EFFECTS_RUNNING_STALE_SECONDS is
	 * left alone. The caller here holds the completion lock, so a `running`
	 * entry that fresh can only be this same claim, never a second
	 * concurrent pipeline - repair must not re-run it out from under
	 * whatever is presumed to still be settling it.
	 */
	public function test_a_fresh_running_claim_is_left_alone_by_repair() {
		$enrollment = $this->enrollments->get( $this->user, $this->course );
		EnrollmentRepository::complete( $enrollment->id, gmdate( 'Y-m-d H:i:s' ) );
		EnrollmentRepository::update(
			$enrollment->id,
			[
				'metadata' => [
					CompletionService::EFFECTS_META         => [
						'credit'          => CompletionService::EFFECT_RUNNING,
						'certificate'     => CompletionService::EFFECT_PENDING,
						'completion_role' => CompletionService::EFFECT_DONE,
						'hook'            => CompletionService::EFFECT_DONE,
					],
					CompletionService::EFFECTS_CLAIMED_META => gmdate( 'Y-m-d H:i:s', time() - 10 ),
				],
			]
		);

		$result = $this->completion->complete( $this->user, $this->course );

		$this->assertFalse( $result );
		$this->assertNull( CreditRepository::find( $this->user, $this->course ), 'A still-fresh claim is not re-run.' );
		$this->assertSame( [ 'credit' => 0, 'certificate' => 0, 'course' => 0 ], $this->fired );
		$this->assertSame(
			[ 'credit' => 'running', 'certificate' => 'pending', 'completion_role' => 'done', 'hook' => 'done' ],
			$this->effects(),
			'credit stays claimed; certificate - which depends on it - is put back to pending rather than left running for something it never got to do.'
		);
	}
}
