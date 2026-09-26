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
 *
 * Cycles (Round 6, PR #32). `uncomplete()` (an admin reversal) reopens the
 * row, bumps `completion_cycle` in its metadata and leaves
 * `completion_effects` otherwise untouched, so a `hook: done` it already
 * recorded is durable history. The next transition is a RE-completion (a
 * cycle marker, or any stored effect state, says so) and starts a NEW CYCLE:
 * credit, certificate and completion role reset to `pending` (idempotent
 * no-ops when already present), `hook` - `anchor_courses_course_completed`,
 * once per (user, course) lifetime - keeps `done` (or `n/a`) forever, and a
 * `recompleted_hook` effect is added as `pending`, so
 * `anchor_courses_course_recompleted` is a tracked effect a repair can resume
 * like any other.
 *
 * Serialisation (Codex review, PR #32 finding 1; Round 6). Every `complete()`
 * and `uncomplete()` takes a per-(user, course) MySQL named lock (`GET_LOCK`,
 * site-scoped name, short timeout, released in `finally` - see `lock_name()`)
 * BEFORE the completed-transition, and holds it across flip + effects. The
 * row and its effect state are (re)read only once the lock is held - never
 * acted on from a pre-lock snapshot. A caller that cannot get the lock
 * returns `false` and changes nothing: a repair runs no effects, and a FRESH
 * completion does NOT flip the row (the learner's next progress
 * recalculation, or the admin Repair/Complete action, retries it) - so a
 * committed transition can never be stranded without its effects cycle.
 * Effects claimed for a run are marked `running` with a timestamp before any
 * of them execute, so a pipeline that crashes mid-run leaves that shape
 * behind: the next lock holder treats a `running` claim older than
 * `EFFECTS_RUNNING_STALE_SECONDS` as `pending` (the claimant is dead) and a
 * fresh one as still owned (left alone - since this caller holds the lock it
 * can only be its own re-entrant claim).
 *
 * Hook containment (Round 6). Every effect, both hooks included, runs inside
 * try/catch: a throwing listener marks that effect `failed` and is logged
 * (`completion_effect_failed`), never escapes after the row is committed,
 * and the other effects still run.
 *
 * Eligibility is re-checked under the lock (Round 7, PR #32 finding 1). The
 * pre-lock `eligible()` call above is a short-circuit for the common case
 * only - a caller that WAITS for the lock can find the learner has since
 * lost the access role (kept active under the default `keep` loss policy;
 * `is_active()` alone would miss this) or had progress reset. `complete()`
 * re-runs the full `eligible()` check against the row it just re-read under
 * the lock, immediately before the flip, and refuses the transition if it no
 * longer holds.
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

	/** Enrolment metadata key holding when the current `running` claim was made. */
	public const EFFECTS_CLAIMED_META = 'completion_effects_claimed_at';

	/** The tracked effects, in pipeline order. */
	public const EFFECTS = [ 'credit', 'certificate', 'completion_role', 'hook' ];

	/**
	 * The re-completion hook effect (Round 6): present in the map only for a
	 * cycle that started after uncomplete(), and run after EFFECTS.
	 */
	public const EFFECT_RECOMPLETED_HOOK = 'recompleted_hook';

	/** Enrolment metadata key: how many times uncomplete() has reopened this row. */
	public const CYCLE_META = 'completion_cycle';

	public const EFFECT_PENDING = 'pending';
	public const EFFECT_RUNNING = 'running';
	public const EFFECT_DONE    = 'done';
	public const EFFECT_FAILED  = 'failed';
	public const EFFECT_NA      = 'n/a';

	/**
	 * How long a `running` claim may stand before a repair treats it as
	 * orphaned by a crashed pipeline and re-runs it as `pending` (Codex
	 * review, finding 1). Running the whole effects pipeline is one request;
	 * five minutes - the same window `QuizService::STALE_CLAIM_SECONDS` uses
	 * for an orphaned submit claim - is far past any real one.
	 */
	public const EFFECTS_RUNNING_STALE_SECONDS = 300;

	/** Seconds complete() waits for the per-(user,course) effects lock (filterable). */
	public const EFFECTS_LOCK_TIMEOUT = 5;

	/**
	 * The MySQL named-lock key for one learner's completion effects pipeline
	 * in one course (Codex review, PR #32 finding 1) - the same shape as
	 * `QuizService::attempt_lock_name()` (audit F07): `GET_LOCK()` names are
	 * server-wide, not scoped by database, so the site is folded into the key
	 * or two installs sharing one MySQL server would contend on it whenever a
	 * learner and course happen to share ids. MySQL lock names are at most 64
	 * characters.
	 */
	public static function lock_name( int $user_id, int $course_id ): string {
		global $wpdb;
		$site = \md5( DB_NAME . $wpdb->prefix );
		$name = 'anchor_courses_completion_' . $site . '_' . $user_id . '_' . $course_id;
		return \strlen( $name ) <= 64 ? $name : 'anchor_courses_completion_' . \md5( $name );
	}

	/**
	 * The per-effect completion state for this learner and course.
	 *
	 * @return array<string,string> effect => pending|running|done|failed|n/a
	 *                              (`running` = claimed by a pipeline that has
	 *                              not yet settled it - see EFFECTS_CLAIMED_META,
	 *                              Codex review finding 1); [] when the row is
	 *                              not completed or predates tracking.
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
	 *              repair returns false; read effects() for its outcome. A
	 *              fresh completion that cannot get the effects lock also
	 *              returns false WITHOUT flipping the row (Round 6 ruling 1):
	 *              the next progress recalculation retries it.
	 */
	public function complete( int $user_id, int $course_id ): bool {
		// Pre-lock read: only a cheap short-circuit so an ineligible learner
		// (the common case on every lesson record) never waits on the lock.
		// Nothing below acts on it - the row is re-read once the lock is held.
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment ) {
			return false;
		}
		if ( ! $enrollment->is_complete() && ! $this->eligible( $enrollment ) ) {
			return false;
		}

		$lock = self::acquire_lock( $user_id, $course_id );
		if ( null === $lock ) {
			return false; // Another pipeline owns this row right now; nothing flipped, nothing run.
		}
		try {
			// Round 6 ruling 2: the row, its status and its effect state are
			// (re)loaded only now that the lock is held.
			$enrollment = EnrollmentRepository::find( $user_id, $course_id );
			if ( ! $enrollment instanceof Enrollment ) {
				return false;
			}
			if ( $enrollment->is_complete() ) {
				// Not a second transition (see class docblock for what guards
				// that) - but any effect a run left pending or failed is
				// re-run now (audit F02).
				if ( ! $this->run_effects( $enrollment, false ) ) {
					Log::write( 'completion_effects_untracked', [ 'user' => $user_id, 'course' => $course_id ] );
				}
				return false;
			}
			if ( ! $this->eligible( $enrollment ) ) {
				// Round 7 finding 1: the pre-lock eligible() above is only a
				// short-circuit - this caller may have WAITED for the lock, and
				// the learner can lose the access role (kept active under the
				// default `keep` loss policy - is_active() alone would miss
				// this) or have progress reset while it waited. The full check
				// is re-run now, against the row just re-read under the lock,
				// so a caller that becomes ineligible in that window is
				// refused here rather than flipping the row on stale grounds.
				return false;
			}

			$from = $enrollment->status;

			// The gate (ruling R-once): only the caller whose UPDATE matches a
			// still-not-completed row proceeds past this line.
			if ( ! EnrollmentRepository::complete( $enrollment->id, Clock::now() ) ) {
				return false;
			}

			$updated = EnrollmentRepository::find_by_id( $enrollment->id );
			if ( ! $updated instanceof Enrollment ) {
				return false; // Defensive: the row we just updated should always be readable.
			}

			// The row is committed as completed from here on: a throwing
			// listener must not skip this cycle's effects (Round 6).
			try {
				/** This action is documented in EnrollmentService::set_status(). */
				\do_action( 'anchor_courses_enrollment_status_changed', $user_id, $course_id, $from, 'completed' );
			} catch ( \Throwable $e ) {
				Log::write( 'completion_status_hook_failed', [ 'user' => $user_id, 'course' => $course_id, 'error' => $e->getMessage() ] );
			}

			if ( ! $this->run_effects( $updated, true ) ) {
				Log::write( 'completion_effects_untracked', [ 'user' => $user_id, 'course' => $course_id ] );
			}
			return true;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * May this learner complete this course right now? Only a learner who is
	 * enrolled RIGHT NOW can (final review C1): an active row is not enough.
	 * Under the default `keep` loss policy a revoked learner's row stays
	 * active, so the row alone would let a system caller (the quiz timer
	 * sweep grading an attempt opened before the revoke) mint credit,
	 * certificate and completion role for somebody who no longer holds the
	 * access role. is_enrolled() checks the row, the role and expires_at.
	 */
	private function eligible( Enrollment $enrollment ): bool {
		return $enrollment->is_active()
			&& $this->enrollments->is_enrolled( $enrollment->user_id, $enrollment->course_id )
			&& $this->evaluate( $enrollment->user_id, $enrollment->course_id );
	}

	/**
	 * Take the per-(user, course) completion lock (see class docblock and
	 * `lock_name()`). Re-entrant on the same connection (MySQL 5.7+), so a
	 * hook listener that recalculates progress from inside a running
	 * pipeline gets it again and finds the row already complete.
	 *
	 * @return string|null The lock name to release, or null on a timeout.
	 */
	private static function acquire_lock( int $user_id, int $course_id ): ?string {
		global $wpdb;

		$lock = self::lock_name( $user_id, $course_id );
		/**
		 * Seconds complete()/uncomplete() wait for another pipeline holding
		 * the same learner's completion lock for this course.
		 *
		 * @param int $seconds
		 * @param int $user_id
		 * @param int $course_id
		 */
		$timeout = \max( 0, (int) \apply_filters( 'anchor_courses_completion_lock_timeout', self::EFFECTS_LOCK_TIMEOUT, $user_id, $course_id ) );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, $timeout ) ) ) {
			Log::write( 'completion_effects_lock_busy', [ 'user' => $user_id, 'course' => $course_id ] );
			return null;
		}
		return $lock;
	}

	private static function release_lock( string $lock ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}

	/**
	 * Is every tracked effect in this map confirmed settled (`done`/`n/a`)?
	 * The four EFFECTS must all be present; any other key present (the
	 * cycle's `recompleted_hook`) must be settled too. The one definition of
	 * "fully repaired" - the admin Repair action reports from it.
	 *
	 * @param array<string,string> $effects As returned by effects().
	 */
	public static function is_settled( array $effects ): bool {
		foreach ( \array_unique( \array_merge( self::EFFECTS, \array_keys( $effects ) ) ) as $effect ) {
			if ( ! \in_array( $effects[ $effect ] ?? '', [ self::EFFECT_DONE, self::EFFECT_NA ], true ) ) {
				return false;
			}
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
	 *              "nothing to do" (CodeRabbit PR #32). Called only while
	 *              this enrolment's completion lock is held (`complete()`).
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

		if ( $fresh ) {
			$state = \array_fill_keys( self::EFFECTS, self::EFFECT_PENDING );

			// A re-completion starts a NEW CYCLE (Round 6 ruling 3): the row
			// was reopened by uncomplete() (cycle marker) or carries a
			// previous cycle's effect state (rows reopened before the marker
			// existed).
			$cycle = (int) ( $enrollment->metadata[ self::CYCLE_META ] ?? 0 );
			if ( $cycle > 0 || [] !== $stored ) {
				// `anchor_courses_course_completed` is once per lifetime: a
				// `done` (or `n/a`) from an earlier cycle is kept forever. A
				// previous cycle with no record of it predates tracking - its
				// outcome is unknown, so it is never re-run (`n/a`). Only a
				// hook the earlier cycle never confirmed (pending, failed or
				// an orphaned running claim - this caller holds the lock) is
				// still owed, and runs in this cycle.
				$prior         = (string) ( $stored['hook'] ?? '' );
				$state['hook'] = \in_array( $prior, [ self::EFFECT_DONE, self::EFFECT_NA ], true )
					? $prior
					: ( '' === $prior ? self::EFFECT_NA : self::EFFECT_PENDING );
				$state[ self::EFFECT_RECOMPLETED_HOOK ] = self::EFFECT_PENDING;
			}
		} elseif ( $untracked ) {
			$state         = \array_fill_keys( self::EFFECTS, self::EFFECT_PENDING );
			$state['hook'] = self::EFFECT_NA;
		} else {
			$state = $stored;

			// A `running` effect left behind by a pipeline that never
			// finished (Codex review, finding 1): only a caller holding the
			// completion lock ever reaches this branch, so a `running` entry
			// here is never a second concurrent pipeline - it is either this
			// same claim continuing after a nested call (left alone; still
			// fresh) or a prior claimant that crashed before clearing it
			// (stale past EFFECTS_RUNNING_STALE_SECONDS - re-run as pending).
			$claimed_at = (string) ( $enrollment->metadata[ self::EFFECTS_CLAIMED_META ] ?? '' );
			$stale      = '' === $claimed_at || Clock::to_timestamp( $claimed_at ) <= Clock::timestamp() - self::EFFECTS_RUNNING_STALE_SECONDS;
			if ( $stale ) {
				foreach ( $state as $effect => $status ) {
					if ( self::EFFECT_RUNNING === $status ) {
						$state[ $effect ] = self::EFFECT_PENDING;
					}
				}
			}
		}

		$order = self::EFFECTS;
		if ( \array_key_exists( self::EFFECT_RECOMPLETED_HOOK, $state ) ) {
			$order[] = self::EFFECT_RECOMPLETED_HOOK;
		}
		$todo = \array_values( \array_filter(
			$order,
			static fn ( string $effect ): bool => \in_array( $state[ $effect ] ?? '', [ self::EFFECT_PENDING, self::EFFECT_FAILED ], true )
		) );
		if ( [] === $todo ) {
			return true; // Nothing outstanding - including a still-fresh `running` claim, left alone above.
		}

		// Claim what is about to run as `running`, timestamped, BEFORE any of
		// it executes (Codex review, finding 1): a crash partway through the
		// loop below leaves exactly this shape - some effects settled, the
		// rest still marked `running` - for the staleness check above to
		// resolve on the next call.
		foreach ( $todo as $effect ) {
			$state[ $effect ] = self::EFFECT_RUNNING;
		}
		$tracked = $this->save_effects( $enrollment->id, $state, Clock::now() );

		$user_id   = $enrollment->user_id;
		$course_id = $enrollment->course_id;
		foreach ( $todo as $effect ) {
			// The certificate snapshots and links the awarded credit, so it
			// waits (stays pending) until the credit effect is settled - a
			// certificate issued past a failed credit would vouch for 0 CE
			// credits for good. It was claimed `running` above on the
			// assumption it would run this pass; put it back since it did not.
			if ( 'certificate' === $effect && ! \in_array( $state['credit'] ?? '', [ self::EFFECT_DONE, self::EFFECT_NA ], true ) ) {
				$state[ $effect ] = self::EFFECT_PENDING;
				$tracked          = $this->save_effects( $enrollment->id, $state ) && $tracked;
				continue;
			}
			try {
				$outcome = $this->run_effect( $effect, $user_id, $course_id );
			} catch ( \Throwable $e ) {
				// Contained (Round 6 ruling 4): the row is already committed,
				// so a throwing listener - either hook included - fails only
				// its own effect, which the next repair re-runs.
				$outcome = self::EFFECT_FAILED;
				Log::write( 'completion_effect_exception', [ 'user' => $user_id, 'course' => $course_id, 'effect' => $effect, 'error' => $e->getMessage() ] );
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
				 * Fires once per (user, course) lifetime, when a learner
				 * first completes a course - including after an
				 * uncomplete()/complete() cycle, which never re-fires this
				 * (see `anchor_courses_course_recompleted` for that case). If
				 * a consumer throws, the effect is recorded `failed` and the
				 * action is fired again by the next complete() call (the
				 * repair path), so a consumer must tolerate being called
				 * again after it or a sibling threw.
				 *
				 * @param int        $user_id
				 * @param int        $course_id
				 * @param Enrollment $enrollment
				 */
				\do_action( 'anchor_courses_course_completed', $user_id, $course_id, $enrollment );
				return self::EFFECT_DONE;

			case self::EFFECT_RECOMPLETED_HOOK:
				$enrollment = EnrollmentRepository::find( $user_id, $course_id );
				Log::write( 'course_recompleted', [ 'user' => $user_id, 'course' => $course_id ] );

				/**
				 * Fires when a learner completes a course they had already
				 * completed once before (and an admin later uncompleted) -
				 * once per re-completion cycle. Integrations that must react
				 * only once per lifetime should use
				 * `anchor_courses_course_completed`, which never fires again
				 * for the same (user, course). A tracked effect (Round 6): if
				 * a consumer throws it is recorded `failed` and re-fired by
				 * the next complete() call, so consumers must tolerate a
				 * repeat after a throw.
				 *
				 * @param int        $user_id
				 * @param int        $course_id
				 * @param Enrollment $enrollment
				 */
				\do_action( 'anchor_courses_course_recompleted', $user_id, $course_id, $enrollment );
				return self::EFFECT_DONE;
		}
		return self::EFFECT_FAILED;
	}

	/**
	 * Merge the effect state into the row's metadata.
	 *
	 * @param string|null $claimed_at When given, also (re)stamps
	 *                                EFFECTS_CLAIMED_META - the moment a
	 *                                `running` claim in $state was made
	 *                                (Codex review, finding 1). Omitted on
	 *                                every other save, which leaves whatever
	 *                                claim timestamp is already stored alone.
	 * @return bool False when EnrollmentRepository::update() reports a
	 *              database error (re-review, audit F02) - a fresh read of
	 *              the row with the OLD metadata would otherwise look
	 *              identical to a successful write.
	 */
	private function save_effects( int $enrollment_id, array $state, ?string $claimed_at = null ): bool {
		$current  = EnrollmentRepository::find_by_id( $enrollment_id );
		$metadata = $current instanceof Enrollment ? $current->metadata : [];
		$metadata[ self::EFFECTS_META ] = $state;
		if ( null !== $claimed_at ) {
			$metadata[ self::EFFECTS_CLAIMED_META ] = $claimed_at;
		}

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
	 * deleting them would rewrite history. `completion_effects` (in
	 * particular `hook: done`) is left alone for the same reason (audit
	 * finding c, 2026-09-25), and `completion_cycle` is bumped (Round 6): the
	 * next complete() reads it as a RE-completion and starts a new effects
	 * cycle, without firing `anchor_courses_course_completed` a second time.
	 * Takes the completion lock, so it never interleaves with a running
	 * pipeline's metadata writes.
	 *
	 * @return bool False when the row is not completed, the lock is busy, or
	 *              the write failed.
	 */
	public function uncomplete( int $user_id, int $course_id ): bool {
		$lock = self::acquire_lock( $user_id, $course_id );
		if ( null === $lock ) {
			return false;
		}
		try {
			$enrollment = EnrollmentRepository::find( $user_id, $course_id );
			if ( ! $enrollment instanceof Enrollment || ! $enrollment->is_complete() ) {
				return false;
			}

			$metadata                     = $enrollment->metadata;
			$metadata[ self::CYCLE_META ] = (int) ( $metadata[ self::CYCLE_META ] ?? 0 ) + 1;
			$updated                      = EnrollmentRepository::update(
				$enrollment->id,
				[ 'status' => 'in_progress', 'completed_at' => null, 'metadata' => $metadata ]
			);
			if ( ! $updated instanceof Enrollment ) {
				Log::write( 'completion_uncomplete_failed', [ 'user' => $user_id, 'course' => $course_id ] );
				return false;
			}

			// The reopen is already committed; a throwing listener must not mask it
			// (the admin handler would never redirect). Same containment as complete().
			try {
				\do_action( 'anchor_courses_enrollment_status_changed', $user_id, $course_id, 'completed', 'in_progress' );
			} catch ( \Throwable $e ) {
				Log::write( 'completion_status_hook_failed', [ 'user' => $user_id, 'course' => $course_id, 'error' => $e->getMessage() ] );
			}
			return true;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Reopen a row for the admin "Reset progress" action
	 * (`Services\ProgressService::reset_course()`, `Admin\EnrollmentManager`'s
	 * `reset` action), under the SAME per-(user, course) completion lock
	 * `complete()`/`uncomplete()` take (Round 8, Codex + CodeRabbit Major,
	 * PR #32 finding 1).
	 *
	 * Before this method existed, `EnrollmentService::restart()` read the
	 * row's metadata, bumped `completion_cycle` in its OWN copy, and wrote
	 * status + metadata back with no coordination with a completion pipeline
	 * that might be mid-run for the same (user, course) at the same moment: a
	 * stale write here could restore `hook: failed` (so the lifetime
	 * `anchor_courses_course_completed` fires again next time) or clobber a
	 * newer effects map or claim `complete()`/`uncomplete()` wrote in
	 * between. This is now the ONE place that reads and rewrites a completed
	 * row's completion metadata for a reset - `EnrollmentService` no longer
	 * touches it at all.
	 *
	 * $data is applied via `EnrollmentRepository::update()` exactly as given
	 * (the reset's `status`/`started_at`/`completed_at`) - never `metadata`;
	 * the cycle bump below is the only metadata this call ever writes, and it
	 * merges into the row's CURRENT metadata, read only once the lock is
	 * held, never a caller's pre-lock snapshot.
	 *
	 * Static (like `lock_name()`/`is_settled()`): the lock is a server-wide
	 * MySQL named lock, not per-instance state, and this method touches
	 * nothing else CompletionService carries (no injected EnrollmentService/
	 * CreditService/CertificateService) - a caller with no wired
	 * CompletionService INSTANCE (several tests construct `ProgressService`
	 * bare) must still serialise a reset against a real completion pipeline
	 * running elsewhere in the same MySQL server.
	 *
	 * @param array $data status/started_at/completed_at (never `metadata`).
	 * @return bool False when the lock is busy or the write failed - nothing
	 *              is read or changed either way. True when there was
	 *              nothing to reset (no enrolment for this user/course) or
	 *              the update succeeded.
	 */
	public static function reopen_for_reset( int $user_id, int $course_id, array $data ): bool {
		$lock = self::acquire_lock( $user_id, $course_id );
		if ( null === $lock ) {
			return false;
		}
		try {
			$enrollment = EnrollmentRepository::find( $user_id, $course_id );
			if ( ! $enrollment instanceof Enrollment ) {
				return true; // Nothing to reset.
			}

			$from = $enrollment->status;
			if ( 'completed' === $from ) {
				// Same marker uncomplete() bumps (Round 6/7 rulings, see the
				// class docblock and COURSES.md): a reset of a completed row
				// is a RE-completion the next time round, not a first one.
				$metadata                      = $enrollment->metadata;
				$metadata[ self::CYCLE_META ] = (int) ( $metadata[ self::CYCLE_META ] ?? 0 ) + 1;
				$data['metadata']              = $metadata;
			}

			$updated = EnrollmentRepository::update( $enrollment->id, $data );
			if ( ! $updated instanceof Enrollment ) {
				Log::write( 'completion_reset_failed', [ 'user' => $user_id, 'course' => $course_id ] );
				return false;
			}

			// The reset is already committed; a throwing listener must not
			// mask it (same containment as complete()/uncomplete()).
			if ( $from !== $updated->status ) {
				try {
					/** This action is documented in EnrollmentService::set_status(). */
					\do_action( 'anchor_courses_enrollment_status_changed', $user_id, $course_id, $from, $updated->status );
				} catch ( \Throwable $e ) {
					Log::write( 'completion_status_hook_failed', [ 'user' => $user_id, 'course' => $course_id, 'error' => $e->getMessage() ] );
				}
			}

			return true;
		} finally {
			self::release_lock( $lock );
		}
	}
}
