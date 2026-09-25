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

	/**
	 * Begin (or resume) an attempt.
	 *
	 * @return QuizAttempt|\WP_Error
	 */
	public function start_attempt( int $user_id, int $quiz_id, int $course_id ) {
		$allowed = $this->can_start( $user_id, $quiz_id, $course_id );
		if ( \is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$open = QuizAttemptRepository::open_attempt( $user_id, $quiz_id, $course_id );
		if ( $open instanceof QuizAttempt ) {
			// Scoped by the query; asserted anyway so no future change to the
			// lookup can hand course B a course-A attempt (audit F05).
			return $open->course_id === $course_id
				? $open
				: new \WP_Error( 'attempt_course_mismatch', \__( 'That attempt belongs to a different course.', 'anchor-schema' ) );
		}

		$settings = $this->settings( $quiz_id );

		$attempt = QuizAttemptRepository::create(
			[
				'user_id'         => $user_id,
				'course_id'       => $course_id,
				'quiz_id'         => $quiz_id,
				'points_possible' => Questions::points_possible( $quiz_id ),
				// Pinned now, read by deadline()/enforce_timer()/submit() for the
				// life of this attempt - never the live setting again (Task 24
				// review, ruling R3).
				'metadata'        => [
					'time_limit_seconds' => (int) $settings['time_limit_seconds'],
					'on_timer_expiry'    => (string) $settings['on_timer_expiry'],
				],
			]
		);

		// A genuine insert vs. a unique-key collision with a concurrent start on
		// the same (user, quiz) that raced past the open_attempt() check above
		// (Task 24 review, ruling R1). Only a genuine insert gets the
		// side effects below; a collision resolves to the winner's row exactly
		// like the ordinary resume path.
		$created = $attempt instanceof QuizAttempt;
		if ( ! $created ) {
			$attempt = QuizAttemptRepository::open_attempt( $user_id, $quiz_id, $course_id );
		}

		if ( ! $attempt instanceof QuizAttempt ) {
			return new \WP_Error( 'attempt_failed', \__( 'The attempt could not be started.', 'anchor-schema' ) );
		}

		if ( ! $created ) {
			return $attempt;
		}

		$this->enrollments->start( $user_id, $course_id );
		$this->progress->record_item( $user_id, $course_id, $quiz_id, 'quiz', 'in_progress' );

		Log::write( 'quiz_started', [ 'user' => $user_id, 'quiz' => $quiz_id, 'attempt' => $attempt->id ] );

		/**
		 * Fires when a new quiz attempt begins (never on a resume - see
		 * start_attempt()'s open-attempt short-circuit above, nor on a
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
		$valid_ids               = \array_column( (array) $question['answers'], 'id' );
		$answers                 = $attempt->answers;
		$answers[ $question_id ] = Grading::normalize_answer( (string) $question['type'], $value, $valid_ids );

		QuizAttemptRepository::update( $attempt_id, [ 'answers' => $answers ] );

		return true;
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
		$attempt = QuizAttemptRepository::find( $attempt_id );
		if ( ! $attempt instanceof QuizAttempt ) {
			return new \WP_Error( 'no_attempt', \__( 'That attempt does not exist.', 'anchor-schema' ) );
		}
		if ( null !== $expected_user_id && $expected_user_id !== $attempt->user_id ) {
			return new \WP_Error( 'attempt_not_yours', \__( 'That attempt belongs to someone else.', 'anchor-schema' ) );
		}
		if ( ! $attempt->is_open() ) {
			return $attempt; // Already graded / expired: nothing to do, nothing to fire.
		}
		$not_enrolled = $this->refuse_unenrolled( $attempt, $expected_user_id );
		if ( null !== $not_enrolled ) {
			return $not_enrolled;
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
				return QuizAttemptRepository::find( $attempt_id ) ?? $attempt;
			}
			$expired = QuizAttemptRepository::update(
				$attempt_id,
				[
					'submitted_at'     => Clock::now(),
					'duration_seconds' => \max( 0, $now - Clock::to_timestamp( $attempt->started_at ) ),
				]
			);
			$expired = $expired instanceof QuizAttempt ? $expired : $attempt;
			$this->progress->record_item( $attempt->user_id, $attempt->course_id, $attempt->quiz_id, 'quiz', 'failed' );

			Log::write( 'quiz_expired', [ 'attempt' => $attempt_id ] );

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
			\do_action( 'anchor_courses_quiz_expired', $expired, $attempt->user_id, $attempt->quiz_id, $attempt->course_id );

			$this->progress->recalculate_course( $attempt->user_id, $attempt->course_id );
			return $expired;
		}

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

		// Claim the attempt before grading (final review: atomic submit). Two
		// submits that both read it as open cannot both grade it: only the
		// one whose conditional UPDATE moves it in_progress -> submitted goes
		// on; the other returns the row as the winner left it.
		if ( ! QuizAttemptRepository::transition( $attempt_id, 'in_progress', 'submitted' ) ) {
			return QuizAttemptRepository::find( $attempt_id ) ?? $attempt;
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
			return new \WP_Error( 'save_failed', \__( 'The attempt could not be graded. Please submit again.', 'anchor-schema' ) );
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

		return $saved;
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
	 */
	public function enforce_timer( QuizAttempt $attempt ): QuizAttempt {
		if ( ! $attempt->is_open() ) {
			return $attempt;
		}
		$deadline = $this->deadline( $attempt );
		if ( 0 === $deadline || Clock::timestamp() <= $deadline ) {
			return $attempt;
		}

		$result = $this->submit( $attempt->id );
		return $result instanceof QuizAttempt ? $result : $attempt;
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
			$after   = $this->enforce_timer( $attempt );
			if ( $after->status !== $attempt->status ) {
				$closed++;
			}
		}

		return $closed;
	}
}
