<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\LessonEditor;
use Anchor\Courses\Admin\QuizEditor;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Database\Migrations;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Grading;
use Anchor\Courses\Support\Log;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The quiz engine (brief section 8).
 *
 * Everything authoritative about starting/resuming a quiz attempt lives here:
 * who may start, how many attempts remain, when a retry unlocks, and what the
 * learner is allowed to see. Task 25 adds submit()/enforce_timer()/
 * sweep_expired_attempts() to this same class - grading (Support\Grading) is
 * deliberately untouched here so those seams stay clean.
 *
 * Nothing in JavaScript is trusted (rule 4), and no learner-facing surface may
 * read Questions::get() or QuizAttempt::to_array() directly (rule 8) - only
 * questions_for_learner() / QuizAttempt::for_learner() leave this module
 * toward a learner.
 */
final class QuizService {

	/**
	 * How long a `submitted` claim may stand before it is treated as orphaned
	 * by a crashed request and re-opened (audit F03). Grading is one request;
	 * five minutes is far past any real one.
	 */
	public const STALE_CLAIM_SECONDS = 300;

	public function __construct(
		private ?ProgressService $progress = null,
		private ?EnrollmentService $enrollments = null
	) {
		$this->progress    = $progress ?? new ProgressService();
		$this->enrollments = $enrollments ?? new EnrollmentService();
	}

	public function settings( int $quiz_id ): array {
		return QuizEditor::settings( $quiz_id );
	}

	/** @return true|\WP_Error */
	public function can_start( int $user_id, int $quiz_id, int $course_id ) {
		$result = $this->check_start( $user_id, $quiz_id, $course_id );

		/**
		 * Filter whether this learner may begin (or resume) an attempt.
		 *
		 * @param true|\WP_Error $result
		 * @param int            $user_id
		 * @param int            $quiz_id
		 * @param int            $course_id
		 */
		return \apply_filters( 'anchor_courses_can_start_quiz', $result, $user_id, $quiz_id, $course_id );
	}

	/** @return true|\WP_Error */
	private function check_start( int $user_id, int $quiz_id, int $course_id ) {
		if ( ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			return new \WP_Error( 'not_enrolled', \__( 'You are not enrolled in this course.', 'anchor-schema' ) );
		}
		if ( ! Curriculum::contains( $course_id, $quiz_id, 'quiz' ) ) {
			return new \WP_Error( 'not_in_course', \__( 'That quiz is not part of this course.', 'anchor-schema' ) );
		}
		if ( [] === Questions::get( $quiz_id ) ) {
			return new \WP_Error( 'no_questions', \__( 'This quiz has no questions yet.', 'anchor-schema' ) );
		}
		if ( ! $this->progress->is_item_available( $user_id, $course_id, $quiz_id, 'quiz' ) ) {
			return new \WP_Error( 'locked', \__( 'Finish the earlier items first.', 'anchor-schema' ) );
		}

		// A claim orphaned by a crashed submit is this learner's open attempt
		// again (audit F03) - recovered here too, not only by the daily sweep.
		$this->reopen_stale_claims( $user_id, $quiz_id, $course_id );

		// An attempt already open IN THIS COURSE always resumes, whatever the
		// allowance says (audit F05: another course's open attempt is not it).
		if ( QuizAttemptRepository::open_attempt( $user_id, $quiz_id, $course_id ) instanceof QuizAttempt ) {
			return true;
		}

		if ( 0 === $this->attempts_remaining( $user_id, $quiz_id, $course_id ) ) {
			return new \WP_Error( 'no_attempts_remaining', \__( 'You have used all your attempts.', 'anchor-schema' ) );
		}

		$retry_at = $this->retry_available_at( $user_id, $quiz_id, $course_id );
		if ( $retry_at > Clock::timestamp() ) {
			return new \WP_Error(
				'retry_delay',
				\sprintf(
					/* translators: %s: human-readable time difference, e.g. "35 mins". */
					\__( 'You can retry in %s.', 'anchor-schema' ),
					\human_time_diff( Clock::timestamp(), $retry_at )
				)
			);
		}

		return true;
	}

	/** Seconds start_attempt() waits for the per-learner lock (filterable). */
	public const ATTEMPT_LOCK_TIMEOUT = 5;

	/**
	 * The MySQL named-lock key for one learner's starts at one quiz in one
	 * course (audit F07). `GET_LOCK()` names are server-wide, not scoped by
	 * database, so two WordPress installs sharing one MySQL server - or two
	 * subsites of one multisite, which share the server but not the table
	 * prefix - are folded into the key (re-review): otherwise they would
	 * contend for the same lock whenever a learner, course and quiz happen to
	 * share ids across sites. MySQL lock names are at most 64 characters.
	 */
	public static function attempt_lock_name( int $user_id, int $course_id, int $quiz_id ): string {
		global $wpdb;
		$site = \md5( DB_NAME . $wpdb->prefix );
		$name = 'anchor_courses_attempt_' . $site . '_' . $user_id . '_' . $course_id . '_' . $quiz_id;
		return \strlen( $name ) <= 64 ? $name : 'anchor_courses_attempt_' . \md5( $name );
	}

	/**
	 * Begin (or resume) an attempt.
	 *
	 * Serialised per (user, course, quiz) by a MySQL named lock (audit F07):
	 * the preflight (can_start(): open attempt, allowance, retry delay) and
	 * the create run inside one critical section, so two tabs or two direct
	 * requests cannot both pass the preflight and both create. A caller that
	 * cannot get the lock within `anchor_courses_attempt_lock_timeout`
	 * seconds gets WP_Error('attempt_busy') (REST 409) - a controlled
	 * conflict, never a second attempt. QuizAttemptRepository::create()'s
	 * conditional INSERT is the database-level backstop. The started side
	 * effects run after the lock is released.
	 *
	 * @return QuizAttempt|\WP_Error
	 */
	public function start_attempt( int $user_id, int $quiz_id, int $course_id ) {
		global $wpdb;

		$lock = self::attempt_lock_name( $user_id, $course_id, $quiz_id );
		/**
		 * Seconds to wait for another start of the same attempt to finish.
		 *
		 * @param int $seconds
		 * @param int $user_id
		 * @param int $quiz_id
		 * @param int $course_id
		 */
		$timeout = \max( 0, (int) \apply_filters( 'anchor_courses_attempt_lock_timeout', self::ATTEMPT_LOCK_TIMEOUT, $user_id, $quiz_id, $course_id ) );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, $timeout ) ) ) {
			return new \WP_Error( 'attempt_busy', \__( 'This quiz is already being started. Please try again.', 'anchor-schema' ) );
		}

		try {
			$result = $this->start_attempt_locked( $user_id, $quiz_id, $course_id );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		if ( ! \is_array( $result ) ) {
			return $result; // WP_Error.
		}
		[ $attempt, $created ] = $result;
		if ( ! $created ) {
			return $attempt;
		}

		$this->enrollments->start( $user_id, $course_id );
		$this->progress->record_item( $user_id, $course_id, $quiz_id, 'quiz', 'in_progress' );

		Log::write( 'quiz_started', [ 'user' => $user_id, 'quiz' => $quiz_id, 'attempt' => $attempt->id ] );

		/**
		 * Fires when a new quiz attempt begins (never on a resume - see
		 * start_attempt_locked()'s open-attempt short-circuit, nor on a
		 * collision resolving to another request's winning row).
		 *
		 * @param QuizAttempt $attempt
		 * @param int         $user_id
		 * @param int         $quiz_id
		 * @param int         $course_id
		 */
		\do_action( 'anchor_courses_quiz_started', $attempt, $user_id, $quiz_id, $course_id );

		return $attempt;
	}

	/**
	 * The critical section of start_attempt(): preflight, resume or create.
	 *
	 * @return array{0:QuizAttempt,1:bool}|\WP_Error [ attempt, newly created ].
	 */
	private function start_attempt_locked( int $user_id, int $quiz_id, int $course_id ) {
		$allowed = $this->can_start( $user_id, $quiz_id, $course_id );
		if ( \is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$open = QuizAttemptRepository::open_attempt( $user_id, $quiz_id, $course_id );
		if ( $open instanceof QuizAttempt ) {
			// Scoped by the query; asserted anyway so no future change to the
			// lookup can hand course B a course-A attempt (audit F05).
			return $open->course_id === $course_id
				? [ $open, false ]
				: new \WP_Error( 'attempt_course_mismatch', \__( 'That attempt belongs to a different course.', 'anchor-schema' ) );
		}

		$settings = $this->settings( $quiz_id );

		$attempt = QuizAttemptRepository::create(
			[
				'user_id'         => $user_id,
				'course_id'       => $course_id,
				'quiz_id'         => $quiz_id,
				'points_possible' => Questions::points_possible( $quiz_id ),
				'max_attempts'    => (int) $settings['max_attempts'],
				// Pinned now, read by deadline()/enforce_timer()/submit() for the
				// life of this attempt - never the live setting again (Task 24
				// review, ruling R3).
				'metadata'        => [
					'time_limit_seconds' => (int) $settings['time_limit_seconds'],
					'on_timer_expiry'    => (string) $settings['on_timer_expiry'],
				],
			]
		);

		// A genuine insert vs. nothing inserted: a unique-key collision
		// (Task 24 review, ruling R1) or create()'s own guard finding an open
		// attempt or a used-up allowance written by a start that raced past
		// the preflight above (audit F07). Only a genuine insert gets the
		// side effects; otherwise the winner's open row is resumed exactly
		// like the ordinary resume path - and if there is none, the allowance
		// is what refused it.
		if ( $attempt instanceof QuizAttempt ) {
			return [ $attempt, true ];
		}

		$attempt = QuizAttemptRepository::open_attempt( $user_id, $quiz_id, $course_id );
		if ( $attempt instanceof QuizAttempt ) {
			return [ $attempt, false ];
		}
		if ( 0 === $this->attempts_remaining( $user_id, $quiz_id, $course_id ) ) {
			return new \WP_Error( 'no_attempts_remaining', \__( 'You have used all your attempts.', 'anchor-schema' ) );
		}
		return new \WP_Error( 'attempt_failed', \__( 'The attempt could not be started.', 'anchor-schema' ) );
	}

	/** Attempts counted against max_attempts in THIS course (audit F05). */
	public function attempts_used( int $user_id, int $quiz_id, int $course_id ): int {
		return QuizAttemptRepository::count_for_quiz( $user_id, $quiz_id, $course_id );
	}

	/** @return int Remaining attempts in this course, or -1 for unlimited (max_attempts === 0, brief 8.1). */
	public function attempts_remaining( int $user_id, int $quiz_id, int $course_id ): int {
		$max = (int) $this->settings( $quiz_id )['max_attempts'];
		if ( $max <= 0 ) {
			return -1;
		}
		return \max( 0, $max - $this->attempts_used( $user_id, $quiz_id, $course_id ) );
	}

	/** Unix timestamp when the next attempt in this course unlocks; 0 when it already has. */
	public function retry_available_at( int $user_id, int $quiz_id, int $course_id ): int {
		$delay = (int) $this->settings( $quiz_id )['retry_delay_seconds'];
		if ( $delay <= 0 ) {
			return 0;
		}

		$last = QuizAttemptRepository::last_for_quiz( $user_id, $quiz_id, $course_id );
		// An attempt voided by an admin reset (`abandoned`) imposes no delay:
		// the reset is a fresh start (final review I6).
		if ( ! $last instanceof QuizAttempt || null === $last->submitted_at || 'abandoned' === $last->status ) {
			return 0;
		}

		return Clock::to_timestamp( $last->submitted_at ) + $delay;
	}

	public function get_attempt( int $attempt_id ): ?QuizAttempt {
		return QuizAttemptRepository::find( $attempt_id );
	}

	/**
	 * The learner's best (highest-scoring, graded) attempt at this quiz in
	 * this course, or null if none is graded yet.
	 *
	 * Thin wrapper over QuizAttemptRepository::best_for_quiz() (closes the
	 * second Task 18 layering-exception call site - pre-gate cleanup round:
	 * Frontend\Shortcodes::render_quiz() read the repository directly).
	 */
	public function best_attempt( int $user_id, int $quiz_id, int $course_id ): ?QuizAttempt {
		return QuizAttemptRepository::best_for_quiz( $user_id, $quiz_id, $course_id );
	}

	public function owns_attempt( int $user_id, int $attempt_id ): bool {
		$attempt = QuizAttemptRepository::find( $attempt_id );
		return $attempt instanceof QuizAttempt && $attempt->user_id === $user_id;
	}

	/**
	 * The question payload a learner may see (brief 8.3, rule 8).
	 *
	 * The attempt id seeds the shuffle, so reloading mid-attempt keeps the same
	 * order without storing one.
	 */
	public function questions_for_learner( int $quiz_id, QuizAttempt $attempt ): array {
		$settings = $this->settings( $quiz_id );

		return Questions::for_learner(
			Questions::get( $quiz_id ),
			1 === (int) $settings['shuffle_questions'],
			1 === (int) $settings['shuffle_answers'],
			'attempt-' . $attempt->id
		);
	}

	/**
	 * Unix timestamp the attempt must be submitted by; 0 when untimed.
	 *
	 * Reads the time_limit_seconds pinned into the attempt at start(), never
	 * the quiz's live setting (Task 24 review, ruling R3) - so a mid-attempt
	 * settings edit cannot move a deadline already in force.
	 */
	public function deadline( QuizAttempt $attempt ): int {
		$limit = (int) ( $attempt->metadata['time_limit_seconds'] ?? 0 );
		if ( $limit <= 0 ) {
			return 0;
		}
		return Clock::to_timestamp( $attempt->started_at ) + $limit;
	}

	/**
	 * The on_timer_expiry policy pinned into the attempt at start(); never the
	 * live setting (ruling R3, same reasoning as deadline()). Falls back to
	 * QuizEditor's default for an attempt with no pinned policy (none should
	 * exist post-migration, but a stray legacy row must not fatal).
	 */
	private function timer_policy( QuizAttempt $attempt ): string {
		$policy = (string) ( $attempt->metadata['on_timer_expiry'] ?? '' );
		return \in_array( $policy, QuizEditor::TIMER_POLICIES, true )
			? $policy
			: (string) QuizEditor::defaults()['on_timer_expiry'];
	}

	/**
	 * Store one answer on an open attempt.
	 *
	 * @param mixed    $value             Answer id, or an array of ids.
	 * @param int|null $expected_user_id  Defence in depth (Task 26 review, REST
	 *                                    ownership ruling): when given, the
	 *                                    attempt must belong to this user or the
	 *                                    call is refused before anything else
	 *                                    runs. The REST controller passes the
	 *                                    current user here in addition to its own
	 *                                    `owns_attempt()` permission check. Left
	 *                                    null (the default) for every
	 *                                    system/cron caller, which is
	 *                                    deliberately user-agnostic.
	 * @return true|\WP_Error
	 */
	public function save_answer( int $attempt_id, string $question_id, $value, ?int $expected_user_id = null ) {
		$attempt = QuizAttemptRepository::find( $attempt_id );
		if ( ! $attempt instanceof QuizAttempt ) {
			return new \WP_Error( 'no_attempt', \__( 'That attempt does not exist.', 'anchor-schema' ) );
		}
		if ( null !== $expected_user_id && $expected_user_id !== $attempt->user_id ) {
			return new \WP_Error( 'attempt_not_yours', \__( 'That attempt belongs to someone else.', 'anchor-schema' ) );
		}
		$not_enrolled = $this->refuse_unenrolled( $attempt, $expected_user_id );
		if ( null !== $not_enrolled ) {
			return $not_enrolled;
		}
		if ( ! $attempt->is_open() ) {
			return new \WP_Error( 'attempt_closed', \__( 'This attempt is already finished.', 'anchor-schema' ) );
		}

		// The window closes HERE, not at the next submit() (Task 26 review,
		// CRITICAL): submit()'s own late-handling only protects the $answers
		// argument passed to IT - it was never a guard on save_answer(), so a
		// late POST /answer was landing straight in $attempt->answers and a
		// subsequent auto_submit graded it as if it had arrived in time. A
		// late save is refused outright and the attempt is closed right now,
		// via the pinned deadline/policy (enforce_timer() -> submit()), so
		// only what was saved inside the window is ever graded.
		$deadline = $this->deadline( $attempt );
		if ( $deadline > 0 && Clock::timestamp() > $deadline ) {
			$this->enforce_timer( $attempt );
			return new \WP_Error( 'attempt_closed', \__( 'The time limit for this attempt has passed.', 'anchor-schema' ) );
		}

		$question = null;
		foreach ( Questions::get( $attempt->quiz_id ) as $candidate ) {
			if ( (string) $candidate['id'] === $question_id ) {
				$question = $candidate;
				break;
			}
		}
		if ( null === $question ) {
			return new \WP_Error( 'unknown_question', \__( 'That question is not part of this quiz.', 'anchor-schema' ) );
		}

		// Only ids that are actually on this question may ever be stored
		// (Task 26 review, IMPORTANT): a garbage/oversized payload is reduced
		// to the intersection with the question's real answer ids, and
		// single_choice/true_false keep at most one (Grading::normalize_answer()).
		$valid_ids  = \array_column( (array) $question['answers'], 'id' );
		$normalized = Grading::normalize_answer( (string) $question['type'], $value, $valid_ids );

		// Compare-and-swap (audit F06): the whole map is written only if
		// nobody wrote it since this request read it, and only while the
		// attempt is still open. Losing the race means another save (or the
		// submit claim) got there first: re-read and try ONCE more on top of
		// what is now stored; a second loss is reported, never swallowed.
		for ( $try = 0; $try < 2; $try++ ) {
			$answers                 = $attempt->answers;
			$answers[ $question_id ] = $normalized;

			$written = QuizAttemptRepository::save_answers( $attempt_id, $answers, $attempt->revision );
			if ( true === $written ) {
				return true;
			}
			if ( null === $written ) {
				Log::write( 'quiz_answer_save_failed', [ 'attempt' => $attempt_id ] );
				return new \WP_Error( 'save_failed', \__( 'Your answer could not be saved. Please try again.', 'anchor-schema' ) );
			}

			$attempt = QuizAttemptRepository::find( $attempt_id );
			if ( ! $attempt instanceof QuizAttempt || ! $attempt->is_open() ) {
				return new \WP_Error( 'attempt_closed', \__( 'This attempt is already finished.', 'anchor-schema' ) );
			}
		}

		return new \WP_Error( 'save_conflict', \__( 'Your answer was changed elsewhere at the same time. Please check it and save again.', 'anchor-schema' ) );
	}

	/**
	 * A learner acting on their own attempt must still be enrolled in its
	 * course (final review C1): the attempt was opened while they had access,
	 * but a revoke, a cancel or an expiry since then closes the door on
	 * answering and submitting too. Only learner-initiated calls (an
	 * `$expected_user_id` was given - the REST controller) are refused; a
	 * system caller (timer sweep) may still close the attempt, and
	 * CompletionService::complete() independently refuses to award anything
	 * to somebody who is not enrolled.
	 */
	private function refuse_unenrolled( QuizAttempt $attempt, ?int $expected_user_id ): ?\WP_Error {
		if ( null === $expected_user_id || $this->enrollments->is_enrolled( $attempt->user_id, $attempt->course_id ) ) {
			return null;
		}
		return new \WP_Error( 'not_enrolled', \__( 'You are not enrolled in this course.', 'anchor-schema' ) );
	}

	/**
	 * Grade and close an attempt (brief 8.4).
	 *
	 * Idempotent: an attempt that is no longer in progress is returned as-is -
	 * a second submit() neither re-grades nor re-fires (brief 26). Atomic
	 * too: the attempt is claimed with a conditional UPDATE
	 * (QuizAttemptRepository::transition()) before it is graded or expired,
	 * so two concurrent submits grade it exactly once.
	 *
	 * The timer is checked BEFORE the submitted answers are accepted, so a late
	 * POST can never overwrite what was saved inside the window (brief 8.5).
	 * Both the deadline and the on_timer_expiry policy come from the attempt's
	 * pinned metadata, never the quiz's live settings (ruling R3).
	 *
	 * @param array    $answers          question_id => answer id | id[] (merged
	 *                                   over saved answers; ignored entirely on
	 *                                   a late auto_submit - only what was saved
	 *                                   inside the window is graded).
	 * @param int|null $expected_user_id Defence in depth (Task 26 review, REST
	 *                                   ownership ruling): when given, the
	 *                                   attempt must belong to this user or the
	 *                                   call is refused before anything else
	 *                                   runs - including before the idempotent
	 *                                   "already closed" short-circuit below, so
	 *                                   a stranger guessing a graded attempt's id
	 *                                   still gets refused rather than a read of
	 *                                   someone else's result. The REST
	 *                                   controller passes the current user here
	 *                                   in addition to its own `owns_attempt()`
	 *                                   permission check. Left null (the
	 *                                   default) for every system/cron caller
	 *                                   (`enforce_timer()`,
	 *                                   `sweep_expired_attempts()`), which is
	 *                                   deliberately user-agnostic.
	 * @return QuizAttempt|\WP_Error
	 */
	public function submit( int $attempt_id, array $answers = [], ?int $expected_user_id = null ) {
		return $this->submit_tracked( $attempt_id, $answers, $expected_user_id )['result'];
	}

	/**
	 * submit()'s real implementation, plus an explicit signal of whether THIS
	 * call durably transitioned the attempt's status (Round 8, CodeRabbit,
	 * COURSES.md ~:350 / `sweep_expired_attempts()`).
	 *
	 * Neither the result's TYPE nor a before/after status compare says this
	 * safely on its own: a `WP_Error` does not always mean nothing happened -
	 * the `expire` policy's `read_failed` follows a transition that already
	 * committed, before either of its own re-reads runs - and a `QuizAttempt`
	 * result does not always mean THIS call transitioned anything either -
	 * the generic claim-then-grade path's `read_failed`/`save_failed` ROLLS
	 * BACK its own `in_progress -> submitted` claim before returning (net: no
	 * change), and a lost-race short-circuit returns the winner's row without
	 * this call having touched it at all. `sweep_expired_attempts()` reads
	 * `transitioned` instead of inferring it, either of which double-counts a
	 * rollback as closed.
	 *
	 * @return array{result: QuizAttempt|\WP_Error, transitioned: bool}
	 */
	private function submit_tracked( int $attempt_id, array $answers, ?int $expected_user_id ): array {
		$attempt = QuizAttemptRepository::find( $attempt_id );
		if ( ! $attempt instanceof QuizAttempt ) {
			return [ 'result' => new \WP_Error( 'no_attempt', \__( 'That attempt does not exist.', 'anchor-schema' ) ), 'transitioned' => false ];
		}
		if ( null !== $expected_user_id && $expected_user_id !== $attempt->user_id ) {
			return [ 'result' => new \WP_Error( 'attempt_not_yours', \__( 'That attempt belongs to someone else.', 'anchor-schema' ) ), 'transitioned' => false ];
		}
		if ( ! $attempt->is_open() ) {
			return [ 'result' => $attempt, 'transitioned' => false ]; // Already graded / expired: nothing to do, nothing to fire.
		}
		$not_enrolled = $this->refuse_unenrolled( $attempt, $expected_user_id );
		if ( null !== $not_enrolled ) {
			return [ 'result' => $not_enrolled, 'transitioned' => false ];
		}

		$settings = $this->settings( $attempt->quiz_id );
		$deadline = $this->deadline( $attempt );
		$now      = Clock::timestamp();
		$late     = $deadline > 0 && $now > $deadline;
		$policy   = $this->timer_policy( $attempt );

		if ( $late && 'expire' === $policy ) {
			// Claim the attempt first (atomic): a concurrent submit or sweep
			// that got here too loses the UPDATE and returns the winner's row.
			if ( ! QuizAttemptRepository::transition( $attempt_id, 'in_progress', 'expired' ) ) {
				return [ 'result' => QuizAttemptRepository::find( $attempt_id ) ?? $attempt, 'transitioned' => false ]; // Lost the race - another caller transitioned it, not this one.
			}
			// Committed from here on: whatever follows (a failed detail write,
			// a failed re-read), THIS call is the one that closed the attempt.
			$saved = QuizAttemptRepository::update(
				$attempt_id,
				[
					'submitted_at'     => Clock::now(),
					'duration_seconds' => \max( 0, $now - Clock::to_timestamp( $attempt->started_at ) ),
				]
			);
			if ( ! $saved instanceof QuizAttempt ) {
				Log::write( 'quiz_expire_detail_save_failed', [ 'attempt' => $attempt_id ] );
			}
			// The transition just above is the truth (Codex review, finding
			// 2): re-read the row regardless of whether the detail write
			// landed, so a failed second write never substitutes the
			// PRE-transition `in_progress` $attempt for a row that IS now
			// `expired` - the expiry hook, REST and the sweep must all see
			// the real status even when submitted_at/duration_seconds did
			// not get durably recorded.
			$expired = QuizAttemptRepository::find( $attempt_id );
			if ( ! $expired instanceof QuizAttempt ) {
				// A transient read failure right after a write that just
				// succeeded (CodeRabbit Major, PR #32 re-review): one retry,
				// since the row this call itself transitioned a moment ago
				// should normally be readable now. Falling back to the
				// PRE-transition $attempt here - the bug this replaces -
				// would publish `in_progress` for a row that really is
				// `expired`, to the hook, to REST and to
				// sweep_expired_attempts()'s before/after status compare
				// (which would then undercount).
				$expired = QuizAttemptRepository::find( $attempt_id );
			}
			if ( ! $expired instanceof QuizAttempt ) {
				// Both reads failed: there is no safe object to publish as
				// `expired` (and definitely not the stale `in_progress`
				// one). The transition above landed for real (Codex, Round
				// 8) - and a retry cannot repair a skipped effect here
				// (Round 10, Codex re-review, PR #32 finding): the "already
				// closed" short-circuit at the top of this method refuses
				// any later call on this attempt (it is no longer
				// `in_progress`), while the sweep already counts this call
				// as `transitioned` below. So the effects a confirmed expiry
				// always runs - record the failed quiz progress, fire the
				// hook, recalculate the course - still run here, against a
				// SYNTHESISED attempt built from the pre-transition object
				// with its status overridden to `expired`: the transition is
				// the truth, even though nothing could confirm it just now.
				// No `in_progress` object ever reaches the hook.
				Log::write( 'quiz_expire_read_failed', [ 'attempt' => $attempt_id ] );
				$expired = new QuizAttempt(
					$attempt->id,
					$attempt->user_id,
					$attempt->course_id,
					$attempt->quiz_id,
					$attempt->attempt_number,
					'expired',
					$attempt->score,
					$attempt->points_earned,
					$attempt->points_possible,
					$attempt->passed,
					$attempt->started_at,
					$attempt->submitted_at,
					$attempt->duration_seconds,
					$attempt->answers,
					$attempt->grading_data,
					$attempt->metadata,
					$attempt->created_at,
					$attempt->updated_at,
					$attempt->revision
				);
				$this->fire_expiry_effects( $expired, $attempt->user_id, $attempt->quiz_id, $attempt->course_id );
				// The transition above landed for real (Codex, Round 8): this
				// still counts, even though nothing could read it back.
				return [ 'result' => new \WP_Error( 'read_failed', \__( 'The attempt expired, but its record could not be read back. Try again.', 'anchor-schema' ) ), 'transitioned' => true ];
			}
			$this->fire_expiry_effects( $expired, $attempt->user_id, $attempt->quiz_id, $attempt->course_id );
			return [ 'result' => $expired, 'transitioned' => true ];
		}

		// Claim the attempt before grading (final review: atomic submit). Two
		// submits that both read it as open cannot both grade it: only the
		// one whose conditional UPDATE moves it in_progress -> submitted goes
		// on; the other returns the row as the winner left it.
		if ( ! QuizAttemptRepository::transition( $attempt_id, 'in_progress', 'submitted' ) ) {
			return [ 'result' => QuizAttemptRepository::find( $attempt_id ) ?? $attempt, 'transitioned' => false ]; // Lost the race - another caller transitioned it, not this one.
		}

		// Re-read AFTER the claim (audit F06): an answer save acknowledged
		// between this request's first read and its claim is in the row now,
		// and no save can land after the claim (save_answers() requires
		// `in_progress` on the write) - so what is graded below is exactly
		// every acknowledged save plus this request's own answers.
		$claimed = QuizAttemptRepository::find( $attempt_id );
		if ( ! $claimed instanceof QuizAttempt ) {
			// Never grade the PRE-claim snapshot (Codex, PR #32 Round 6): an
			// autosave committed between the first read and the claim is not
			// in it, so grading it would drop that answer from the grade AND
			// overwrite it in the stored answers. Release the claim - the
			// row still holds every acknowledged save - and let the learner's
			// retry (or the sweep) grade the real row.
			QuizAttemptRepository::transition( $attempt_id, 'submitted', 'in_progress' );
			Log::write( 'quiz_submit_read_failed', [ 'attempt' => $attempt_id ] );
			// The claim is ROLLED BACK above - the row is back exactly where
			// it started, so this call transitioned nothing durable (Round 8).
			return [ 'result' => new \WP_Error( 'read_failed', \__( 'The attempt could not be read back for grading. Please submit again.', 'anchor-schema' ) ), 'transitioned' => false ];
		}
		$attempt = $claimed;

		// In-window: merge the submitted answers over what was saved.
		// Late + auto_submit: grade ONLY what was saved before the deadline.
		// Starting from what was already saved (not an empty array) is what
		// makes a missing key in $answers mean "keep the saved answer" rather
		// than "clear it" (Task 26 review, rule 6): only a question the
		// client actually sent a key for is touched below.
		$final_answers = $attempt->answers;
		if ( ! $late ) {
			$questions_by_id = [];
			foreach ( Questions::get( $attempt->quiz_id ) as $question ) {
				$questions_by_id[ (string) $question['id'] ] = $question;
			}
			foreach ( $answers as $question_id => $value ) {
				$question_id = (string) $question_id;
				if ( ! isset( $questions_by_id[ $question_id ] ) ) {
					continue;
				}
				$question                      = $questions_by_id[ $question_id ];
				$valid_ids                     = \array_column( (array) $question['answers'], 'id' );
				$final_answers[ $question_id ] = Grading::normalize_answer( (string) $question['type'], $value, $valid_ids );
			}
		}

		$graded = Grading::grade( Questions::get( $attempt->quiz_id ), $final_answers );

		$result = \array_merge(
			$graded,
			[
				'passed'        => Grading::passed( $graded['score'], (int) $settings['passing_score'] ),
				'passing_score' => (int) $settings['passing_score'],
			]
		);

		/**
		 * Filter the graded result before it is stored.
		 *
		 * @param array       $result points_earned, points_possible, score,
		 *                            per_question, passed, passing_score.
		 * @param QuizAttempt $attempt
		 */
		$result = (array) \apply_filters( 'anchor_courses_quiz_result', $result, $attempt );

		$passed = ! empty( $result['passed'] );

		$saved = QuizAttemptRepository::update(
			$attempt_id,
			[
				'status'           => 'graded',
				'score'            => (float) $result['score'],
				'points_earned'    => (float) $result['points_earned'],
				'points_possible'  => (float) $result['points_possible'],
				'passed'           => $passed ? 1 : 0,
				'submitted_at'     => Clock::now(),
				'duration_seconds' => \max( 0, $now - Clock::to_timestamp( $attempt->started_at ) ),
				'answers'          => $final_answers,
				'grading_data'     => (array) $result['per_question'],
			]
		);

		// Only a grade that is durably SAVED may move progress or fire the
		// outcome hooks (audit F03). update() returns null on a database
		// error; a row that reads back as anything but `graded` is the same
		// failure. Release the claim so the attempt is not stuck in
		// `submitted` (counted, never graded, invisible to the sweep) - the
		// learner's retry grades it; a crash before this line is recovered by
		// reopen_stale_claims().
		if ( ! $saved instanceof QuizAttempt || ! $saved->is_graded() ) {
			QuizAttemptRepository::transition( $attempt->id, 'submitted', 'in_progress' );
			Log::write( 'quiz_grade_save_failed', [ 'attempt' => $attempt->id ] );
			// The claim is ROLLED BACK above - net no change (Round 8).
			return [ 'result' => new \WP_Error( 'save_failed', \__( 'The attempt could not be graded. Please submit again.', 'anchor-schema' ) ), 'transitioned' => false ];
		}

		$this->progress->record_item(
			$saved->user_id,
			$saved->course_id,
			$saved->quiz_id,
			'quiz',
			$passed ? 'completed' : 'failed',
			[ 'metadata' => [ 'attempt_id' => $saved->id, 'score' => $saved->score ] ]
		);

		Log::write( 'quiz_submitted', [ 'attempt' => $saved->id, 'score' => $saved->score, 'passed' => $passed ] );

		/**
		 * Fires after an attempt is graded, pass or fail.
		 *
		 * @param QuizAttempt $saved
		 * @param int         $user_id
		 * @param int         $quiz_id
		 * @param int         $course_id
		 */
		\do_action( 'anchor_courses_quiz_submitted', $saved, $saved->user_id, $saved->quiz_id, $saved->course_id );
		\do_action(
			$passed ? 'anchor_courses_quiz_passed' : 'anchor_courses_quiz_failed',
			$saved,
			$saved->user_id,
			$saved->quiz_id,
			$saved->course_id
		);

		if ( $passed ) {
			$this->complete_gated_lessons( $saved );
		}

		$this->progress->recalculate_course( $saved->user_id, $saved->course_id );

		return [ 'result' => $saved, 'transitioned' => true ];
	}

	/**
	 * Run a confirmed `expire` transition's effects: record the failed quiz
	 * progress, log and fire `anchor_courses_quiz_expired`, then recalculate
	 * the course. Shared (Round 10, Codex re-review, PR #32 finding) by the
	 * ordinary path in `submit_tracked()`'s `expire` branch above (a real
	 * re-read confirms the row) and its double-read-failure path, which
	 * passes a SYNTHESISED attempt built from the pre-transition object with
	 * status overridden to `expired` - the `in_progress -> expired`
	 * transition already committed for real, and this is the one chance
	 * these effects get to run: neither a learner retry (the attempt is no
	 * longer `in_progress`) nor the sweep's own count (already `transitioned`)
	 * gives them a second one.
	 */
	private function fire_expiry_effects( QuizAttempt $expired, int $user_id, int $quiz_id, int $course_id ): void {
		$this->progress->record_item( $user_id, $course_id, $quiz_id, 'quiz', 'failed' );

		Log::write( 'quiz_expired', [ 'attempt' => $expired->id ] );

		/**
		 * Fires once, when a timed attempt is closed by the `expire`
		 * timer policy (nothing graded). A graded attempt fires
		 * anchor_courses_quiz_submitted + _passed/_failed instead.
		 *
		 * @param QuizAttempt $expired
		 * @param int         $user_id
		 * @param int         $quiz_id
		 * @param int         $course_id
		 */
		\do_action( 'anchor_courses_quiz_expired', $expired, $user_id, $quiz_id, $course_id );

		$this->progress->recalculate_course( $user_id, $course_id );
	}

	/**
	 * Complete any lesson in this course whose completion_mode is quiz_pass and
	 * whose quiz_id is the quiz just passed (brief 9.2).
	 */
	private function complete_gated_lessons( QuizAttempt $attempt ): void {
		foreach ( Curriculum::items( $attempt->course_id ) as $item ) {
			if ( 'lesson' !== $item['type'] ) {
				continue;
			}
			$lesson_id = (int) $item['id'];
			if ( 'quiz_pass' !== (string) LessonEditor::setting( $lesson_id, 'completion_mode' ) ) {
				continue;
			}
			if ( (int) LessonEditor::setting( $lesson_id, 'quiz_id' ) !== $attempt->quiz_id ) {
				continue;
			}
			$this->progress->complete_lesson( $attempt->user_id, $attempt->course_id, $lesson_id );
		}
	}

	/**
	 * Apply the timer policy to an attempt without a learner request.
	 *
	 * Used by the cron sweep and by any read path that wants a truthful status;
	 * returns the attempt unchanged when untimed or still inside the window.
	 *
	 * @return QuizAttempt|\WP_Error A `read_failed` WP_Error only when submit()
	 *                               itself could not hand back a row AND a
	 *                               fresh, authoritative re-read also failed
	 *                               (Round 7, finding 3). Never the
	 *                               PRE-transition `$attempt` argument on that
	 *                               path: submit()'s `read_failed` can follow
	 *                               a transition that DID land (the `expire`
	 *                               branch commits `expired` before either of
	 *                               its own re-reads runs), so that argument
	 *                               may already be stale by the time this
	 *                               method would have returned it. Re-reading
	 *                               here gets every caller (REST read/answer/
	 *                               submit, the sweep) the true current row -
	 *                               `expired` for that case, `in_progress` for
	 *                               submit()'s OTHER `read_failed` (the
	 *                               grading claim, which rolls itself back) -
	 *                               without this method having to know which
	 *                               of the two happened.
	 */
	public function enforce_timer( QuizAttempt $attempt ) {
		return $this->enforce_timer_tracked( $attempt )['result'];
	}

	/**
	 * enforce_timer()'s real implementation, plus the same explicit
	 * `transitioned` signal submit_tracked() carries (Round 8) - threaded
	 * through unchanged by the fallback re-read below, which is about
	 * getting a truthful OBJECT back to the caller, not about whether a
	 * transition happened at all.
	 *
	 * @return array{result: QuizAttempt|\WP_Error, transitioned: bool}
	 */
	private function enforce_timer_tracked( QuizAttempt $attempt ): array {
		if ( ! $attempt->is_open() ) {
			return [ 'result' => $attempt, 'transitioned' => false ];
		}
		$deadline = $this->deadline( $attempt );
		if ( 0 === $deadline || Clock::timestamp() <= $deadline ) {
			return [ 'result' => $attempt, 'transitioned' => false ];
		}

		$outcome = $this->submit_tracked( $attempt->id, [], null );
		if ( $outcome['result'] instanceof QuizAttempt ) {
			return $outcome;
		}

		$current = QuizAttemptRepository::find( $attempt->id );
		return [
			'result'       => $current instanceof QuizAttempt ? $current : $outcome['result'],
			'transitioned' => $outcome['transitioned'],
		];
	}

	/**
	 * Re-open `submitted` claims older than STALE_CLAIM_SECONDS (audit F03).
	 *
	 * @param int $user_id   0 = everyone (the daily sweep).
	 * @param int $quiz_id   0 = every quiz.
	 * @param int $course_id 0 = every course.
	 * @return int Attempts re-opened.
	 */
	public function reopen_stale_claims( int $user_id = 0, int $quiz_id = 0, int $course_id = 0 ): int {
		return QuizAttemptRepository::reopen_stale_submitted(
			Clock::offset( -self::STALE_CLAIM_SECONDS ),
			$user_id,
			$quiz_id,
			$course_id
		);
	}

	/**
	 * Close out timed attempts whose window has passed but whose learner never
	 * came back.
	 *
	 * Counts only CONFIRMED expiry transitions (Round 8, CodeRabbit,
	 * COURSES.md ~:350): `enforce_timer_tracked()`'s explicit `transitioned`
	 * flag, never a `WP_Error` result or a before/after status compare. Both
	 * of those over-count: the generic claim-then-grade path (any timer
	 * policy other than `expire`) ROLLS BACK its own claim before returning
	 * `read_failed`/`save_failed`, so the row never actually changed even
	 * though the result is an error and (with a before/after compare) may
	 * transiently differ from the pre-sweep snapshot.
	 *
	 * @return int attempts closed.
	 */
	public function sweep_expired_attempts(): int {
		global $wpdb;

		// Orphaned claims first, so a re-opened timed attempt is also closed
		// by the timer pass below if its window has passed.
		$this->reopen_stale_claims();

		$rows = $wpdb->get_results(
			'SELECT * FROM ' . Migrations::table( 'quiz_attempts' ) . " WHERE status = 'in_progress'", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		$closed = 0;
		foreach ( (array) $rows as $row ) {
			$attempt = QuizAttempt::from_row( $row );
			if ( $this->enforce_timer_tracked( $attempt )['transitioned'] ) {
				$closed++;
			}
		}

		return $closed;
	}
}
