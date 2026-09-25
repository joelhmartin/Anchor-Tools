<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Domain\Enrollment;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Log;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Course completion: the once-only pipeline (brief 11, 26, rule 7).
 *
 * enrolment -> `completed` + `completed_at` -> award CE -> issue certificate
 * -> grant the completion role -> fire `anchor_courses_course_completed`.
 *
 * The idempotency guard is `EnrollmentRepository::complete()`'s atomic
 * conditional UPDATE (`WHERE id = %d AND status <> 'completed'`, checked via
 * `$wpdb->rows_affected`), not a read-then-write check in this class. Two
 * concurrent calls for the same user+course can therefore never both pass:
 * exactly one UPDATE matches the row, so exactly one caller ever reaches
 * `award()`/`issue()`/`grant_completed()`/the hooks. The earlier
 * `is_complete()` check below is a cheap short-circuit for the common case
 * (already done, nothing to evaluate) - it is not what makes this safe.
 *
 * This also means `CreditService::award()` and `CertificateService::issue()`
 * are relied on as primitives, not as their own concurrency guards: both
 * return an existing row (and fire nothing) on a second call, but their
 * `insert_ignore()` also fires the awarded/issued hook on ANY non-null
 * result - including a row a concurrent caller just inserted (Task 27
 * review). That double-fire can only happen if two callers reach `award()`/
 * `issue()` for the same (user, course) at the same time, and the pipeline
 * gate above prevents exactly that for course completion.
 *
 * Effects are tracked one by one (audit F02). Right after the flip the
 * enrolment's metadata gets `completion_effects` =
 * `{ credit, certificate, completion_role, hook }`, each `pending`, and each
 * effect then records `done`, `failed` or (credit/certificate only) `n/a`.
 * `complete()` on a row that is ALREADY completed re-runs exactly the effects
 * still `pending` or `failed` - the ordinary retry (the next lesson/quiz
 * record, the admin "Repair completion" action) is the repair path. Every
 * effect is idempotent on re-run: award()/issue() return the existing row,
 * the role grant is a no-op when held, and the hook - the one effect that
 * is not naturally idempotent - is re-fired only while it is not `done`.
 * A completed row with no `completion_effects` at all (CodeRabbit PR #32,
 * audit F02 re-review) has an UNKNOWN outcome, not a done one: it predates
 * tracking, or the tracking write itself failed while the effects ran for
 * real. Either way, `run_effects()` treats it like a fresh transition and
 * re-runs the idempotent effects (credit, certificate, completion role) so
 * one that genuinely never landed still gets created - but it never re-runs
 * the hook, which is not idempotent and may already have fired once for
 * this row with nothing recorded to prove it.
 * Repair is not itself serialised: two simultaneous repairs of a failed
 * hook could both fire it (the credit/certificate/role effects stay single
 * via their unique keys and idempotency).
 */
final class CompletionService {

	public function __construct(
		private EnrollmentService $enrollments,
		private ProgressService $progress,
		private ?CreditService $credits = null,
		private ?CertificateService $certificates = null
	) {
		$this->credits      = $credits ?? new CreditService();
		$this->certificates = $certificates ?? new CertificateService();
	}

	/** Has this course already been recorded as complete? */
	public function is_complete( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		return $enrollment instanceof Enrollment && $enrollment->is_complete();
	}

	/** Should it be? Pure read - no side effects. */
	public function evaluate( int $user_id, int $course_id ): bool {
		return $this->progress->get_course_progress( $user_id, $course_id )->complete;
	}

	/** Enrolment metadata key holding the per-effect state. */
	public const EFFECTS_META = 'completion_effects';

	/** The tracked effects, in pipeline order. */
	public const EFFECTS = [ 'credit', 'certificate', 'completion_role', 'hook' ];

	public const EFFECT_PENDING = 'pending';
	public const EFFECT_DONE    = 'done';
	public const EFFECT_FAILED  = 'failed';
	public const EFFECT_NA      = 'n/a';

	/**
	 * The per-effect completion state for this learner and course.
	 *
	 * @return array<string,string> effect => pending|done|failed|n/a; [] when the
	 *                              row is not completed or predates tracking.
	 */
	public function effects( int $user_id, int $course_id ): array {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment || ! $enrollment->is_complete() ) {
			return [];
		}
		$stored = $enrollment->metadata[ self::EFFECTS_META ] ?? [];
		return \is_array( $stored ) ? \array_map( 'strval', $stored ) : [];
	}

	/**
	 * Run the completion pipeline - or, on a row already completed, repair it.
	 *
	 * @return bool True only for the call that performed the transition. A
	 *              repair returns false; read effects() for its outcome.
	 */
	public function complete( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment ) {
			return false;
		}
		if ( $enrollment->is_complete() ) {
			// Not a second transition (see class docblock for what guards
			// that) - but any effect the first run left pending or failed is
			// re-run now (audit F02).
			if ( ! $this->run_effects( $enrollment, false ) ) {
				Log::write( 'completion_effects_untracked', [ 'user' => $user_id, 'course' => $course_id ] );
			}
			return false;
		}
		// Only a learner who is enrolled RIGHT NOW can complete (final review
		// C1): an active row is not enough. Under the default `keep` loss
		// policy a revoked learner's row stays active, so the row alone would
		// let a system caller (the quiz timer sweep grading an attempt opened
		// before the revoke) mint credit, certificate and completion role for
		// somebody who no longer holds the access role. is_enrolled() checks
		// the row, the role and expires_at together.
		if ( ! $enrollment->is_active() || ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			return false;
		}
		if ( ! $this->evaluate( $user_id, $course_id ) ) {
			return false;
		}

		$now = Clock::now();
		$from = $enrollment->status;

		// The gate (ruling R-once): only the caller whose UPDATE matches a
		// still-not-completed row proceeds past this line.
		if ( ! EnrollmentRepository::complete( $enrollment->id, $now ) ) {
			return false;
		}

		$updated = EnrollmentRepository::find_by_id( $enrollment->id );
		if ( ! $updated instanceof Enrollment ) {
			return false; // Defensive: the row we just updated should always be readable.
		}

		/** This action is documented in EnrollmentService::set_status(). */
		\do_action( 'anchor_courses_enrollment_status_changed', $user_id, $course_id, $from, 'completed' );

		if ( ! $this->run_effects( $updated, true ) ) {
			Log::write( 'completion_effects_untracked', [ 'user' => $user_id, 'course' => $course_id ] );
		}

		return true;
	}

	/**
	 * Run every effect that is not yet done (all of them on a fresh
	 * transition), recording each outcome as it lands so a crash mid-way
	 * leaves the rest `pending` for the next call.
	 *
	 * @return bool False when any `save_effects()` write in this call failed
	 *              (re-review, audit F02): the effects themselves still ran -
	 *              this only means their outcome was not durably recorded, so
	 *              the row can look completed with no tracked state. That
	 *              shape - and a row that predates tracking entirely - is
	 *              handled by the `$untracked` branch below, not treated as
	 *              "nothing to do" (CodeRabbit PR #32).
	 */
	private function run_effects( Enrollment $enrollment, bool $fresh ): bool {
		$stored = (array) ( $enrollment->metadata[ self::EFFECTS_META ] ?? [] );

		// An untracked completed row's real outcome is UNKNOWN, not done: it
		// predates tracking, or the previous call's save_effects() failed
		// while the effects themselves still ran. Treat it like a fresh
		// transition - so award()/issue()/grant_completed() get a real
		// chance to create what may genuinely be missing - except the hook,
		// which is never re-run here: it is not idempotent, and an untracked
		// row may already have fired it once with nothing recorded to prove
		// it (CodeRabbit PR #32, audit F02 re-review).
		$untracked = ! $fresh && [] === $stored;

		if ( $fresh || $untracked ) {
			$state = \array_fill_keys( self::EFFECTS, self::EFFECT_PENDING );
			if ( $untracked ) {
				$state['hook'] = self::EFFECT_NA;
			}
		} else {
			$state = $stored;
		}

		$todo = \array_values( \array_filter(
			self::EFFECTS,
			static fn ( string $effect ): bool => \in_array( $state[ $effect ] ?? '', [ self::EFFECT_PENDING, self::EFFECT_FAILED ], true )
		) );
		if ( [] === $todo ) {
			return true; // Nothing outstanding.
		}

		$tracked = true;
		if ( $fresh || $untracked ) {
			$tracked = $this->save_effects( $enrollment->id, $state );
		}

		$user_id   = $enrollment->user_id;
		$course_id = $enrollment->course_id;
		foreach ( $todo as $effect ) {
			// The certificate snapshots and links the awarded credit, so it
			// waits (stays pending) until the credit effect is settled - a
			// certificate issued past a failed credit would vouch for 0 CE
			// credits for good.
			if ( 'certificate' === $effect && ! \in_array( $state['credit'] ?? '', [ self::EFFECT_DONE, self::EFFECT_NA ], true ) ) {
				continue;
			}
			try {
				$outcome = $this->run_effect( $effect, $user_id, $course_id );
			} catch ( \Throwable $e ) {
				$outcome = self::EFFECT_FAILED;
			}
			if ( self::EFFECT_FAILED === $outcome ) {
				Log::write( 'completion_effect_failed', [ 'user' => $user_id, 'course' => $course_id, 'effect' => $effect ] );
			}
			$state[ $effect ] = $outcome;
			$tracked          = $this->save_effects( $enrollment->id, $state ) && $tracked;
		}
		return $tracked;
	}

	/** @return string done|failed|n/a */
	private function run_effect( string $effect, int $user_id, int $course_id ): string {
		switch ( $effect ) {
			case 'credit':
				$credit = $this->credits->award( $user_id, $course_id );
				if ( $credit instanceof Credit ) {
					return self::EFFECT_DONE;
				}
				return null === $credit ? self::EFFECT_NA : self::EFFECT_FAILED;

			case 'certificate':
				// Order matters (brief 12 step 3): the certificate links to the
				// credit record, and issue() only links a credit that already
				// exists - so credits are awarded first.
				$certificate = $this->certificates->issue( $user_id, $course_id );
				if ( null === $certificate ) {
					return self::EFFECT_NA;
				}
				if ( ! $certificate instanceof Certificate ) {
					return self::EFFECT_FAILED;
				}
				// Wire the link explicitly (ruling R-credit-link) rather than
				// relying solely on issue()'s own fallback, and VERIFY it: an
				// unlinked certificate is an unfinished effect.
				$credit = CreditRepository::find( $user_id, $course_id );
				if ( $credit instanceof Credit && $credit->certificate_id !== $certificate->id ) {
					if ( 0 !== $credit->certificate_id ) {
						return self::EFFECT_DONE; // Linked to another certificate on purpose - not ours to rewrite.
					}
					$linked = CreditRepository::attach_certificate( $credit->id, $certificate->id );
					if ( ! $linked instanceof Credit || $linked->certificate_id !== $certificate->id ) {
						return self::EFFECT_FAILED;
					}
				}
				return self::EFFECT_DONE;

			case 'completion_role':
				// Mint (lazily) and grant the COMPLETION role. This is the slug
				// another course or an event lists as a prerequisite (design
				// spec 3.1). It is a different role from the access role the
				// learner already holds, and granting it never touches their
				// enrolment - Support\Roles' listener matches the access slug only.
				Roles::grant_completed( $user_id, $course_id );
				return Roles::user_has( $user_id, Roles::completion_slug( $course_id ) ) ? self::EFFECT_DONE : self::EFFECT_FAILED;

			case 'hook':
				$enrollment = EnrollmentRepository::find( $user_id, $course_id );
				Log::write( 'course_completed', [ 'user' => $user_id, 'course' => $course_id ] );

				/**
				 * Fires once, when a learner completes a course. If a consumer
				 * throws, the effect is recorded `failed` and the action is
				 * fired again by the next complete() call (the repair path),
				 * so a consumer must tolerate being called again after it
				 * or a sibling threw.
				 *
				 * @param int        $user_id
				 * @param int        $course_id
				 * @param Enrollment $enrollment
				 */
				\do_action( 'anchor_courses_course_completed', $user_id, $course_id, $enrollment );
				return self::EFFECT_DONE;
		}
		return self::EFFECT_FAILED;
	}

	/**
	 * Merge the effect state into the row's metadata.
	 *
	 * @return bool False when EnrollmentRepository::update() reports a
	 *              database error (re-review, audit F02) - a fresh read of
	 *              the row with the OLD metadata would otherwise look
	 *              identical to a successful write.
	 */
	private function save_effects( int $enrollment_id, array $state ): bool {
		$current  = EnrollmentRepository::find_by_id( $enrollment_id );
		$metadata = $current instanceof Enrollment ? $current->metadata : [];
		$metadata[ self::EFFECTS_META ] = $state;

		if ( ! EnrollmentRepository::update( $enrollment_id, [ 'metadata' => $metadata ] ) instanceof Enrollment ) {
			Log::write( 'completion_effects_save_failed', [ 'enrollment' => $enrollment_id ] );
			return false;
		}
		return true;
	}

	/**
	 * Reverse the completion flag (admin action).
	 *
	 * Credits and certificates are NOT revoked, and the completion role is NOT
	 * removed: they are a record of something that happened, and quietly
	 * deleting them would rewrite history.
	 */
	public function uncomplete( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment || ! $enrollment->is_complete() ) {
			return false;
		}

		EnrollmentRepository::update( $enrollment->id, [ 'status' => 'in_progress', 'completed_at' => null ] );

		\do_action( 'anchor_courses_enrollment_status_changed', $user_id, $course_id, 'completed', 'in_progress' );

		return true;
	}
}
