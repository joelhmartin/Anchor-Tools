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
		remove_all_actions( 'anchor_courses_course_recompleted' );
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

		$this->hold_lock_elsewhere();

		// `hook` already ran (and fired once) on the fresh transition above -
		// only `credit` (and, by dependency, `certificate`) is outstanding.
		// Snapshot the counts here so the assertion below is about what the
		// LOCKED-OUT call itself did, not the whole test.
		$before_fired = $this->fired;

		$result = $this->completion->complete( $this->user, $this->course );

		$this->assertFalse( $result, 'A repair call must back off, not run effects, while the lock is held elsewhere.' );
		$this->assertNull( CreditRepository::find( $this->user, $this->course ), 'Nothing ran without the lock.' );
		$this->assertSame( $before_fired, $this->fired, 'No hook fired for the caller that could not get the lock.' );
		$this->assertSame( 'failed', $this->effects()['credit'], 'The stored state is untouched by the caller that never ran.' );

		// Release the lock: an ordinary retry now completes it, same as any
		// other repair.
		$this->release_lock_elsewhere();
		$this->completion->complete( $this->user, $this->course );
		$this->assert_all_done_once();
	}

	/**
	 * Take this learner's completion lock on a second, real MySQL connection
	 * - standing in for another request's pipeline - and make complete()
	 * give up on it immediately instead of waiting the default timeout.
	 */
	private function hold_lock_elsewhere(): void {
		$name        = CompletionService::lock_name( $this->user, $this->course );
		$this->other = new mysqli();
		$host        = explode( ':', DB_HOST );
		$this->other->real_connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, isset( $host[1] ) ? (int) $host[1] : 3306 );
		$this->assertSame(
			'1',
			(string) $this->other->query( "SELECT GET_LOCK('" . $this->other->real_escape_string( $name ) . "', 0)" )->fetch_row()[0],
			'Precondition: the lock is held by the "other" pipeline.'
		);
		add_filter( 'anchor_courses_completion_lock_timeout', static fn () => 0 );
	}

	private function release_lock_elsewhere(): void {
		$name = CompletionService::lock_name( $this->user, $this->course );
		$this->other->query( "SELECT RELEASE_LOCK('" . $this->other->real_escape_string( $name ) . "')" );
		remove_all_filters( 'anchor_courses_completion_lock_timeout' );
	}

	/* --- Round 6 (Codex final pass + CodeRabbit, PR #32) --- */

	/**
	 * Finding A: effect state is read only AFTER the lock is held. Two
	 * repairs both see `hook: failed`; the one that gets the lock first fires
	 * the hook and records `done`. The second, which read the row BEFORE it
	 * waited for the lock, must re-read it once it holds the lock and fire
	 * nothing. The first repair is run from inside the lock-timeout filter -
	 * applied right before GET_LOCK, i.e. after the second caller's own
	 * pre-lock read - which is exactly the interleaving of two requests.
	 */
	public function test_a_repair_that_waited_for_the_lock_re_reads_state_and_never_refires_the_hook() {
		$armed = true;
		$throw = static function () use ( &$armed ) {
			if ( $armed ) {
				throw new \RuntimeException( 'injected consumer failure' );
			}
		};
		add_action( 'anchor_courses_course_completed', $throw, 20 );
		$this->finish_lesson();
		$this->assertSame( 'failed', $this->effects()['hook'], 'Precondition: both repairs will read hook: failed.' );
		$armed = false;

		$entered = false;
		add_filter(
			'anchor_courses_completion_lock_timeout',
			function ( $timeout ) use ( &$entered ) {
				if ( ! $entered ) {
					$entered = true;
					$this->completion->complete( $this->user, $this->course ); // The repair that wins the lock.
				}
				return $timeout;
			}
		);

		$this->completion->complete( $this->user, $this->course );

		$this->assertTrue( $entered );
		$this->assertSame( 2, $this->fired['course'], 'The original (throwing) fire plus ONE repair - the waiting caller must not fire it again.' );
		$this->assertSame( 'done', $this->effects()['hook'] );
	}

	/**
	 * Finding C: the lock is taken BEFORE the completed-transition. An admin
	 * uncompletes, then the learner re-completes while another pipeline holds
	 * the lock: complete() must return false WITHOUT flipping the row (the
	 * next progress recalculation retries), rather than committing a
	 * transition whose effects cycle never runs. Once the lock is free the
	 * retry flips it and runs the new cycle: `course_recompleted` once,
	 * `course_completed` still once for the lifetime.
	 */
	public function test_a_re_completion_that_cannot_get_the_lock_does_not_flip_the_row_and_the_retry_runs_the_new_cycle() {
		$recompleted = 0;
		add_action( 'anchor_courses_course_recompleted', function () use ( &$recompleted ) { $recompleted++; } );

		$this->finish_lesson();
		$this->assertTrue( $this->completion->uncomplete( $this->user, $this->course ) );

		$this->hold_lock_elsewhere();
		$this->assertFalse( $this->completion->complete( $this->user, $this->course ), 'No lock, no transition.' );
		$this->assertFalse( $this->completion->is_complete( $this->user, $this->course ), 'The row is NOT flipped while the lock is held elsewhere.' );
		$this->assertSame( 0, $recompleted );

		$this->release_lock_elsewhere();
		$this->assertTrue( $this->completion->complete( $this->user, $this->course ), 'The retry performs the transition.' );
		$this->assertSame( 1, $recompleted, 'The new cycle fires course_recompleted once.' );
		$this->assertSame( 1, $this->fired['course'], 'course_completed stays once per lifetime.' );
		$this->assertSame(
			[ 'credit' => 'done', 'certificate' => 'done', 'completion_role' => 'done', 'hook' => 'done', 'recompleted_hook' => 'done' ],
			$this->effects()
		);

		$this->completion->complete( $this->user, $this->course ); // An ordinary repair afterwards.
		$this->assertSame( 1, $recompleted, 'A settled cycle is never re-fired.' );
		$this->assertSame( 1, count( CreditRepository::for_course( $this->course ) ), 'Still one credit row.' );
	}

	/**
	 * Finding D: a throwing `anchor_courses_course_recompleted` listener is
	 * contained - the row is committed as completed, the effect records
	 * `failed`, nothing escapes complete() - and it is a tracked effect, so
	 * the admin Repair reports incomplete while it keeps failing and re-fires
	 * it (once) when it no longer does.
	 */
	public function test_a_throwing_recompleted_listener_is_contained_recorded_failed_and_re_fired_by_repair() {
		$calls = 0;
		$armed = true;
		add_action(
			'anchor_courses_course_recompleted',
			static function () use ( &$calls, &$armed ) {
				$calls++;
				if ( $armed ) {
					throw new \RuntimeException( 'injected recompleted failure' );
				}
			}
		);

		$this->finish_lesson();
		$this->completion->uncomplete( $this->user, $this->course );

		$this->assertTrue( $this->completion->complete( $this->user, $this->course ), 'The transition is reported; the exception does not escape.' );
		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ) );
		$this->assertSame( 1, $calls );
		$this->assertSame(
			[ 'credit' => 'done', 'certificate' => 'done', 'completion_role' => 'done', 'hook' => 'done', 'recompleted_hook' => 'failed' ],
			$this->effects(),
			"This cycle's other effects still ran; the recompleted hook is recorded failed, not the previous cycle's done."
		);

		$this->assertStringContainsString( 'anchor_courses_admin_notice=repair_incomplete', $this->post_repair(), 'Still throwing: not repaired.' );
		$this->assertSame( 2, $calls );

		$armed = false;
		$this->assertStringContainsString( 'anchor_courses_admin_notice=repaired', $this->post_repair() );
		$this->assertSame( 3, $calls, 'Re-fired once by the repair that succeeded.' );
		$this->assertSame( 'done', $this->effects()['recompleted_hook'] );

		$this->completion->complete( $this->user, $this->course );
		$this->assertSame( 3, $calls, 'Once done, never again this cycle.' );
		$this->assertSame( 1, $this->fired['course'], 'course_completed never re-fires.' );
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

	/* --- Codex P1 + CodeRabbit, PR #32 re-review: the admin Repair action must not report success too eagerly --- */

	/**
	 * A fresh `running` claim (a pipeline that has not been idle long enough
	 * to count as crashed - see EFFECTS_RUNNING_STALE_SECONDS) is neither
	 * `pending` nor `failed`, so the old check
	 * (`array_intersect($effects, [pending, failed]) === []`) reported
	 * `repaired` for a row where `certificate`/`completion_role`/`hook` were
	 * all still unconfirmed `running` claims from a pipeline that may not
	 * even be this process. The fix must never report success until every
	 * one of the four tracked effects reads back `done` or `n/a` - and must
	 * not disturb the fresh claim to get there (that is `run_effects()`'s
	 * job, guarded by the staleness window, not this admin action's).
	 */
	public function test_repair_reports_incomplete_for_a_fresh_running_claim_and_leaves_the_map_untouched() {
		$enrollment = $this->enrollments->get( $this->user, $this->course );
		EnrollmentRepository::complete( $enrollment->id, gmdate( 'Y-m-d H:i:s' ) );
		$seed = [
			'credit'          => CompletionService::EFFECT_DONE,
			'certificate'     => CompletionService::EFFECT_RUNNING,
			'completion_role' => CompletionService::EFFECT_RUNNING,
			'hook'            => CompletionService::EFFECT_RUNNING,
		];
		EnrollmentRepository::update(
			$enrollment->id,
			[
				'metadata' => [
					CompletionService::EFFECTS_META         => $seed,
					CompletionService::EFFECTS_CLAIMED_META => gmdate( 'Y-m-d H:i:s', time() - 10 ),
				],
			]
		);

		$this->assertStringContainsString( 'anchor_courses_admin_notice=repair_incomplete', $this->post_repair() );
		$this->assertSame( $seed, $this->effects(), 'A fresh claim is left exactly as it was - not reset to pending, not reported done.' );
		$this->assertNull( CreditRepository::find( $this->user, $this->course ) );
		$this->assertSame( [ 'credit' => 0, 'certificate' => 0, 'course' => 0 ], $this->fired, 'Nothing re-ran while the claim is still fresh.' );
	}

	/**
	 * The counterpart: once the same shape of claim is stale (older than
	 * EFFECTS_RUNNING_STALE_SECONDS), the admin Repair action re-runs it for
	 * real and - now that every effect actually lands - reports `repaired`,
	 * not `repair_incomplete`.
	 */
	public function test_repair_reports_repaired_after_a_stale_running_claim_is_re_run() {
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

		$this->assertStringContainsString( 'anchor_courses_admin_notice=repaired', $this->post_repair() );
		$this->assertSame(
			[ 'credit' => 'done', 'certificate' => 'done', 'completion_role' => 'done', 'hook' => 'done' ],
			$this->effects()
		);
		$this->assertNotNull( CreditRepository::find( $this->user, $this->course ) );
		$this->assertNotNull( CertificateRepository::find( $this->user, $this->course ) );
	}

	/* --- Round 7, PR #32 audit re-review --- */

	/**
	 * Finding 1: the pre-lock eligible() check in complete() is only a
	 * short-circuit. A caller that WAITS for the completion lock must
	 * re-check eligibility against the row it re-reads once it holds the
	 * lock, not act on its pre-lock snapshot: is_active() alone is not
	 * enough, because under the default `keep` loss policy a revoked
	 * learner's row stays active.
	 *
	 * Simulated without real concurrency, the same way the class's other
	 * "waited for the lock" tests are (see the docblock on the Round 6
	 * findings below): a second connection holds the lock; the
	 * `anchor_courses_completion_lock_timeout` filter runs immediately
	 * before this caller's own GET_LOCK, so revoking the role and releasing
	 * the other connection's lock from inside it reproduces exactly "became
	 * ineligible while waiting, then the lock came free" in one thread.
	 */
	public function test_complete_revalidates_eligibility_after_waiting_for_the_lock() {
		// Reach "eligible, not yet completed" without a fresh transition
		// already having happened: hold the lock elsewhere FIRST (its
		// timeout=0 filter makes any complete() fail fast), then finish the
		// lesson - the progress write lands for real, but the automatic
		// recalculate_course() -> complete() this triggers finds the lock
		// busy and backs off, leaving the row un-flipped with a fresh
		// transition still ahead of it.
		$this->hold_lock_elsewhere();
		$this->finish_lesson();
		$this->assertFalse( $this->completion->is_complete( $this->user, $this->course ), 'Precondition: the automatic completion backed off, lock busy.' );
		$this->assertTrue( $this->completion->evaluate( $this->user, $this->course ), 'Precondition: the curriculum is done.' );

		// Swap the lock-busy filter for one that simulates "waited, then it
		// came free, but eligibility changed in the meantime": while THIS
		// caller waits for the lock, the learner's access role is revoked
		// elsewhere - the row itself stays ACTIVE under the default `keep`
		// loss policy - then the other connection's lock is released so
		// this caller's own GET_LOCK (about to run) succeeds.
		remove_all_filters( 'anchor_courses_completion_lock_timeout' );
		add_filter(
			'anchor_courses_completion_lock_timeout',
			function ( $timeout ) {
				Roles::revoke_access( $this->user, $this->course, 'test' );
				$name = CompletionService::lock_name( $this->user, $this->course );
				$this->other->query( "SELECT RELEASE_LOCK('" . $this->other->real_escape_string( $name ) . "')" );
				return $timeout;
			}
		);

		$result = $this->completion->complete( $this->user, $this->course );

		$this->assertFalse( $result, 'The learner lost eligibility while this caller waited for the lock.' );
		$this->assertFalse( $this->completion->is_complete( $this->user, $this->course ), 'The row must never flip for a learner no longer eligible.' );
		$this->assertNull( CreditRepository::find( $this->user, $this->course ), 'Nothing awarded.' );
		$this->assertSame( 0, $this->fired['course'], 'The completed hook never fires for an ineligible learner.' );

		// Sanity check on the precondition this finding is about: the row
		// alone is still active - is_active() by itself would have let this
		// through, which is exactly the bug.
		$enrollment = $this->enrollments->get( $this->user, $this->course );
		$this->assertTrue( $enrollment->is_active() );
	}

	/**
	 * Finding 2: a completed row that predates effect tracking entirely -
	 * completed directly at the repository here, exactly as one from before
	 * `completion_effects` existed would read - has NO cycle marker and NO
	 * effects map when EnrollmentService::restart() (the admin "Reset
	 * progress" action, via ProgressService::reset_course()) reopens it.
	 * Without a bumped `completion_cycle`, CompletionService::run_effects()
	 * cannot tell this apart from a first-ever completion, so the next
	 * completion re-fires `anchor_courses_course_completed` for a learner
	 * who already completed this course once.
	 */
	public function test_admin_reset_of_a_legacy_completed_row_preserves_the_cycle_marker() {
		$recompleted = 0;
		add_action( 'anchor_courses_course_recompleted', function () use ( &$recompleted ) { $recompleted++; } );

		// A legacy row: completed directly at the repository, bypassing
		// CompletionService entirely, so it carries no completion_effects
		// and no completion_cycle - exactly what a row completed before
		// either existed looks like.
		$enrollment = $this->enrollments->get( $this->user, $this->course );
		EnrollmentRepository::complete( $enrollment->id, gmdate( 'Y-m-d H:i:s' ) );
		$this->assertSame( [], $this->completion->effects( $this->user, $this->course ), 'Precondition: no tracked effect state at all.' );
		$this->assertSame( 0, $this->fired['course'], 'Precondition: the hook never fired through this bypass.' );

		// The admin "Reset progress" action - ProgressService::reset_course(),
		// never CompletionService::uncomplete().
		$this->progress->reset_course( $this->user, $this->course );
		$this->assertFalse( $this->completion->is_complete( $this->user, $this->course ) );

		$this->finish_lesson(); // Re-complete the course.

		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ) );
		$this->assertSame(
			0,
			$this->fired['course'],
			'A legacy completion of unknown outcome must never be treated as the first - course_completed must not (re)fire.'
		);
		$this->assertSame( 1, $recompleted, 'The reopened row starts a new cycle: course_recompleted fires once.' );
	}

	/* --- Round 8, Codex + CodeRabbit Major, PR #32 finding 1: the admin
	   reset is serialised with the completion pipeline --------------------- */

	/**
	 * A second connection holds this learner's completion lock, standing in
	 * for a still-running complete()/uncomplete() pipeline: the reset must
	 * back off entirely - not the enrolment row, not the progress rows, not
	 * the quiz attempts - and report false, never a half-reset.
	 */
	public function test_reset_backs_off_entirely_while_the_completion_lock_is_held_elsewhere() {
		$this->finish_lesson();
		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ), 'fixture: completed' );

		$this->hold_lock_elsewhere();

		$result = $this->progress->reset_course( $this->user, $this->course );
		$this->assertTrue( is_wp_error( $result ), 'A busy completion lock refuses the whole reset.' );
		$this->assertSame( 'reset_busy', $result->get_error_code() );

		$enrollment = $this->enrollments->get( $this->user, $this->course );
		$this->assertSame( 'completed', $enrollment->status, 'The row is untouched while the lock is busy.' );
		$this->assertNotNull(
			\Anchor\Courses\Database\ProgressRepository::find( $this->user, $this->course, $this->lesson, 'lesson' ),
			'Progress rows are untouched too - the reset never half-runs.'
		);

		$this->release_lock_elsewhere();
		$this->assertTrue( $this->progress->reset_course( $this->user, $this->course ), 'The retry succeeds once the lock is free.' );
		$this->assertSame( 'enrolled', $this->enrollments->get( $this->user, $this->course )->status );
		$this->assertNull( \Anchor\Courses\Database\ProgressRepository::find( $this->user, $this->course, $this->lesson, 'lesson' ) );
	}

	/**
	 * The reset's cycle bump and its status/timestamp update are ONE
	 * lock-scoped read-then-write (CompletionService::reopen_for_reset()):
	 * metadata another pipeline wrote while the lock was held elsewhere -
	 * simulating a completion pipeline's own effects write landing in that
	 * window - is read fresh once the lock is free, never overwritten by a
	 * stale pre-lock snapshot.
	 */
	public function test_reset_bumps_the_cycle_without_clobbering_effect_state_written_while_the_lock_was_busy() {
		$this->finish_lesson();
		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ), 'fixture: completed' );
		$before_cycle = (int) ( $this->enrollments->get( $this->user, $this->course )->metadata['completion_cycle'] ?? 0 );

		$this->hold_lock_elsewhere();
		$busy = $this->progress->reset_course( $this->user, $this->course );
		$this->assertTrue( is_wp_error( $busy ) && 'reset_busy' === $busy->get_error_code() );

		// Simulate another pipeline's own metadata write landing in this
		// window - never something the reset itself may act on.
		$enrollment = $this->enrollments->get( $this->user, $this->course );
		$metadata   = $enrollment->metadata;
		$metadata['completion_effects']['hook'] = 'done';
		$metadata['probe']                      = 'written-while-locked';
		EnrollmentRepository::update( $enrollment->id, [ 'metadata' => $metadata ] );

		$this->release_lock_elsewhere();
		$this->assertTrue( $this->progress->reset_course( $this->user, $this->course ) );

		$after = $this->enrollments->get( $this->user, $this->course );
		$this->assertSame( $before_cycle + 1, (int) ( $after->metadata['completion_cycle'] ?? 0 ), 'The cycle marker is bumped.' );
		$this->assertSame(
			'written-while-locked',
			$after->metadata['probe'] ?? null,
			'Metadata written by another pipeline while the lock was busy is preserved, not clobbered by a stale pre-lock snapshot.'
		);
	}

	/** CodeRabbit: a throwing status listener must not escape uncomplete() once the reopen is committed. */
	public function test_uncomplete_contains_a_throwing_status_listener() {
		$this->finish_lesson();
		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ), 'fixture: completed' );
		add_action( 'anchor_courses_enrollment_status_changed', static function () { throw new \RuntimeException( 'listener boom' ); } );

		$result = $this->completion->uncomplete( $this->user, $this->course );

		$this->assertTrue( $result, 'uncomplete() reports the committed reopen despite the listener' );
		$this->assertSame( 'in_progress', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/* --- Round 9, PR #32 finding 1: the reset holds ONE lock across reopen
	   AND clearing progress/attempts ---------------------------------------- */

	/**
	 * Before this fix, `CompletionService::reopen_for_reset()` released the
	 * completion lock the moment its OWN write landed, and
	 * `ProgressService::reset_course()` then deleted progress rows and
	 * abandoned attempts with no lock held at all. A completion racing into
	 * that window would find the reopened (no longer `completed`) row,
	 * re-evaluate it against the OLD progress (still reporting complete) and
	 * flip it straight back to `completed` with effects awarded - right
	 * before this deleted that same progress out from under it. Proven here
	 * by probing the real MySQL lock, from a second real connection, at the
	 * exact moment the progress DELETE fires: it must still find the lock
	 * held by the reset's own connection.
	 */
	public function test_reset_holds_the_completion_lock_through_the_progress_delete() {
		$this->finish_lesson();
		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ), 'fixture: completed' );

		$name  = CompletionService::lock_name( $this->user, $this->course );
		$probe = new mysqli();
		$host  = explode( ':', DB_HOST );
		$probe->real_connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, isset( $host[1] ) ? (int) $host[1] : 3306 );

		$observed = null;
		$watcher  = function ( $query ) use ( &$observed, $probe, $name ) {
			if ( null === $observed && \preg_match( '/^DELETE FROM \S*anchor_courses_progress\b/i', (string) $query ) ) {
				$got = (string) $probe->query( "SELECT GET_LOCK('" . $probe->real_escape_string( $name ) . "', 0)" )->fetch_row()[0];
				if ( '1' === $got ) {
					// The probe wrongly acquired it - the lock was NOT held
					// by the reset's own connection. Release immediately so
					// this leftover claim cannot linger past the test.
					$probe->query( "SELECT RELEASE_LOCK('" . $probe->real_escape_string( $name ) . "')" );
				}
				$observed = '0' === $got;
			}
			return $query;
		};
		add_filter( 'query', $watcher );

		$result = $this->progress->reset_course( $this->user, $this->course );

		remove_filter( 'query', $watcher );
		$probe->close();

		$this->assertTrue( true === $result, 'The reset itself succeeds (lock was never contended).' );
		$this->assertNotNull( $observed, 'Precondition: the progress DELETE actually ran and was observed by the watcher.' );
		$this->assertTrue(
			$observed,
			'The completion lock must still be held by the reset\'s own connection at the moment it deletes progress - ' .
			'reopen and clearing must be ONE lock hold, not two.'
		);
	}

	/**
	 * The write-failure path (Round 9, PR #32 finding 2): a genuine database
	 * error on the reopen write is a DIFFERENT failure from a busy lock, and
	 * must report its own code - `reset_busy`'s message ("try again shortly")
	 * is actively wrong advice for an error that waiting will never fix.
	 * Nothing is cleared either: `$clear()` (progress deletion, attempt
	 * abandonment) must never run once the reopen write itself failed.
	 */
	public function test_reset_reports_reset_failed_and_clears_nothing_when_the_reopen_write_fails() {
		$this->finish_lesson();
		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ), 'fixture: completed' );

		$this->break_queries( '/^UPDATE \S*anchor_courses_enrollments\b/i' );
		$result = $this->progress->reset_course( $this->user, $this->course );
		$this->heal();

		$this->assertTrue( is_wp_error( $result ), 'A genuine write failure must be reported, not swallowed as busy.' );
		$this->assertSame( 'reset_failed', $result->get_error_code() );
		$this->assertSame( 'completed', $this->enrollments->get( $this->user, $this->course )->status, 'Nothing was touched.' );
		$this->assertNotNull(
			\Anchor\Courses\Database\ProgressRepository::find( $this->user, $this->course, $this->lesson, 'lesson' ),
			'clear() must never run when the reopen write itself failed.'
		);
	}

	/* --- Round 10, CodeRabbit Major, PR #32 finding 1: a half-reset must be
	   RECOVERABLE, never a silently-reported failure that leaves a stale
	   completed row completable again on the progress that failed to clear
	   ------------------------------------------------------------------- */

	/**
	 * `$clear()` failing after the reopen write commits used to be invisible:
	 * `reopen_for_reset()` ignored its result and reported success, with the
	 * enrolment reopened but the old progress still in place. Reopen now
	 * persists a `reset_pending_at` marker in the SAME write as the reopen,
	 * BEFORE `$clear()` ever runs, and lifts it only once `$clear()` succeeds -
	 * so a failed clear is reported as `reset_failed`, the row is left with
	 * the marker set, and `complete()` refuses it outright rather than
	 * completing it against progress that never actually got deleted. A later
	 * Reset, once the underlying failure is healed, retries the clearing and
	 * lifts the marker.
	 */
	public function test_a_failed_progress_clear_sets_a_recoverable_marker_and_blocks_completion() {
		$this->finish_lesson();
		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ), 'fixture: completed' );

		$this->break_queries( '/^DELETE FROM \S*anchor_courses_progress\b/i' );
		$result = $this->progress->reset_course( $this->user, $this->course );
		$this->heal();

		$this->assertTrue( is_wp_error( $result ), 'A failed clear must be reported, never swallowed as success.' );
		$this->assertSame( 'reset_failed', $result->get_error_code() );

		$enrollment = $this->enrollments->get( $this->user, $this->course );
		$this->assertNotSame( 'completed', $enrollment->status, 'The reopen write itself still committed.' );
		$this->assertNotEmpty( $enrollment->metadata['reset_pending_at'] ?? null, 'The recoverable marker is set.' );
		$this->assertNotNull(
			\Anchor\Courses\Database\ProgressRepository::find( $this->user, $this->course, $this->lesson, 'lesson' ),
			'The failed delete really did leave the stale progress in place.'
		);

		// The learner's stale progress still reports the course complete; the
		// marker must refuse this outright, never complete it on that stale
		// progress.
		$this->assertFalse( $this->completion->complete( $this->user, $this->course ), 'complete() must refuse a row with a pending reset.' );
		$this->assertFalse( $this->completion->is_complete( $this->user, $this->course ), 'Still not completed - the refusal changed nothing.' );

		// Healing: the clear can now run for real. A later Reset retries it -
		// zero-row deletes are not failures, so this is a real success - and
		// lifts the marker.
		$this->assertTrue( $this->progress->reset_course( $this->user, $this->course ), 'A re-run of Reset retries the clearing and clears the marker.' );
		$healed = $this->enrollments->get( $this->user, $this->course );
		$this->assertArrayNotHasKey( 'reset_pending_at', $healed->metadata, 'The marker is lifted once the clear succeeds.' );
		$this->assertNull( \Anchor\Courses\Database\ProgressRepository::find( $this->user, $this->course, $this->lesson, 'lesson' ) );

		// And completion works normally again.
		$this->finish_lesson();
		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ) );
	}

	/** Zero rows to clear (a learner who never recorded any progress at all) is a real success, never `reset_failed`. */
	public function test_reset_of_a_learner_with_no_progress_at_all_still_succeeds() {
		$this->assertTrue( $this->progress->reset_course( $this->user, $this->course ) );
		$enrollment = $this->enrollments->get( $this->user, $this->course );
		$this->assertSame( 'enrolled', $enrollment->status );
		$this->assertArrayNotHasKey( 'reset_pending_at', $enrollment->metadata, 'The marker never outlives a successful clear.' );
	}
}
