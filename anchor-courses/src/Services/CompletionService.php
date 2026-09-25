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
 * `uncomplete()` (an admin reversal) reopens the row but leaves
 * `completion_effects` untouched, so a `hook: done` it already recorded is
 * durable history, not a value the next completion starts fresh from (audit
 * finding c, 2026-09-25). A later `complete()` call therefore still counts
 * as a fresh transition for `EnrollmentRepository::complete()`'s purposes -
 * the row's status really did go from `in_progress` back to `completed` -
 * but `run_effects()` reads the preserved `hook: done` and treats it as a
 * RE-completion: the idempotent effects above it get their usual pass, but
 * `anchor_courses_course_completed` never fires a second time for the same
 * (user, course). `anchor_courses_course_recompleted` fires instead, for
 * integrations that want to know about a re-completion specifically.
 *
 * Effect execution itself IS serialised per (user, course) (Codex review,
 * PR #32 finding 1): a caller that flips a fresh row and a caller that then
 * finds it already complete and takes the repair branch can both reach
 * `run_effects()` for the same enrolment before the first one's effects
 * finish - the pipeline gate above guards the ROW transition, not that. Both
 * `complete()` branches take the same kind of MySQL named lock
 * `start_attempt()` uses (`GET_LOCK`, site-scoped name, short timeout,
 * released in `finally` - see `lock_name()`) around "read state -> run
 * pending effects -> save state"; a caller that cannot get it returns
 * `false` without running anything, because the pipeline that already holds
 * it owns this row's effects right now. Effects claimed for this run are
 * marked `running` with a timestamp before any of them execute, so a
 * pipeline that crashes mid-run leaves that shape behind rather than looking
 * untouched: the next call to hold the lock treats a `running` claim older
 * than `EFFECTS_RUNNING_STALE_SECONDS` as `pending` (the claimant is dead)
 * and a fresh one as still owned (left alone, since this caller already
 * holds the lock, that can only be its own claim).
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
			// re-run now (audit F02), serialised against the fresh pipeline
			// or another repair (Codex review, finding 1).
			if ( ! $this->run_effects_locked( $enrollment, false ) ) {
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

		// Serialised against a repair call that reads this same row as
		// already-complete a moment later (Codex review, finding 1): whichever
		// of the two gets the lock first runs the effects; the other backs off.
		if ( ! $this->run_effects_locked( $updated, true ) ) {
			Log::write( 'completion_effects_untracked', [ 'user' => $user_id, 'course' => $course_id ] );
		}

		return true;
	}

	/**
	 * `run_effects()`, serialised per (user, course) by a MySQL named lock
	 * (Codex review, PR #32 finding 1 - see class docblock and `lock_name()`).
	 * A caller that cannot get the lock within
	 * `anchor_courses_completion_lock_timeout` seconds runs nothing and
	 * returns false: the pipeline that holds the lock owns this row's effects
	 * right now, so re-running them here would double-fire `award()`/
	 * `issue()`'s hooks or the completion hook.
	 *
	 * @return bool False on a lock timeout, or whatever run_effects() returns.
	 */
	private function run_effects_locked( Enrollment $enrollment, bool $fresh ): bool {
		global $wpdb;

		$lock = self::lock_name( $enrollment->user_id, $enrollment->course_id );
		/**
		 * Seconds complete() waits for another pipeline's effects to finish
		 * for the same learner and course.
		 *
		 * @param int $seconds
		 * @param int $user_id
		 * @param int $course_id
		 */
		$timeout = \max( 0, (int) \apply_filters( 'anchor_courses_completion_lock_timeout', self::EFFECTS_LOCK_TIMEOUT, $enrollment->user_id, $enrollment->course_id ) );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, $timeout ) ) ) {
			Log::write( 'completion_effects_lock_busy', [ 'user' => $enrollment->user_id, 'course' => $enrollment->course_id ] );
			return false;
		}

		try {
			return $this->run_effects( $enrollment, $fresh );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
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
	 *              this enrolment's completion lock is held (`run_effects_locked()`).
	 */
	private function run_effects( Enrollment $enrollment, bool $fresh ): bool {
		$stored = (array) ( $enrollment->metadata[ self::EFFECTS_META ] ?? [] );

		// Durable history (audit finding c, 2026-09-25): uncomplete() reopens
		// the row (status back to `in_progress`) but never touches
		// completion_effects, so a `hook: done` recorded by an earlier
		// completion survives it. That means `$fresh` alone - true again for
		// the very next complete() call, since EnrollmentRepository::
		// complete()'s guard is the STATUS, not this metadata - can no
		// longer be read as "this (user, course) has never completed
		// before". Check the stored hook state before it gets overwritten
		// below.
		$previously_fired_hook = self::EFFECT_DONE === ( $stored['hook'] ?? '' );

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
			} elseif ( $previously_fired_hook ) {
				// A re-completion after uncomplete(), not a first-time one:
				// anchor_courses_course_completed already fired once for
				// this (user, course) and must never fire a second time
				// (documented "once per user/course"). The idempotent
				// effects above it (credit, certificate, completion role)
				// still get a pass in the loop below - a re-issued
				// certificate after a settings change, say - but `hook`
				// stays `done` and is never queued into $todo.
				$state['hook'] = self::EFFECT_DONE;
			}
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

		// The re-completion signal (audit finding c, 2026-09-25): fires
		// exactly once per actual re-completion, tied to the same one-caller
		// guarantee as `hook` above - `$fresh` is true only for the call
		// that just won EnrollmentRepository::complete()'s atomic UPDATE,
		// and $previously_fired_hook can only be true here on a row
		// uncomplete() reopened, never on this row's first-ever completion.
		// Kept outside the $todo loop (and untracked by completion_effects)
		// because it is not one of the once-per-lifetime pipeline effects -
		// it is allowed to fire again on every subsequent uncomplete() ->
		// complete() cycle.
		if ( $fresh && $previously_fired_hook ) {
			Log::write( 'course_recompleted', [ 'user' => $enrollment->user_id, 'course' => $enrollment->course_id ] );
			/**
			 * Fires when a learner completes a course they had already
			 * completed once before (and later uncompleted). Integrations
			 * that must react only once per lifetime should use
			 * `anchor_courses_course_completed`, which never fires again for
			 * the same (user, course); use this one to opt in to reacting on
			 * every re-completion instead.
			 *
			 * @param int        $user_id
			 * @param int        $course_id
			 * @param Enrollment $enrollment
			 */
			\do_action( 'anchor_courses_course_recompleted', $enrollment->user_id, $enrollment->course_id, $enrollment );
		}

		$todo = \array_values( \array_filter(
			self::EFFECTS,
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
	 * finding c, 2026-09-25): it is what tells a later `complete()` call this
	 * is a RE-completion, so `anchor_courses_course_completed` is not fired
	 * a second time for this (user, course).
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
