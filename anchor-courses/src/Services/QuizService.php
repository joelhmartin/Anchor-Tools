<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\QuizEditor;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
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

		// An attempt already open always resumes, whatever the allowance says.
		if ( QuizAttemptRepository::open_attempt( $user_id, $quiz_id ) instanceof QuizAttempt ) {
			return true;
		}

		if ( 0 === $this->attempts_remaining( $user_id, $quiz_id ) ) {
			return new \WP_Error( 'no_attempts_remaining', \__( 'You have used all your attempts.', 'anchor-schema' ) );
		}

		$retry_at = $this->retry_available_at( $user_id, $quiz_id );
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

		$open = QuizAttemptRepository::open_attempt( $user_id, $quiz_id );
		if ( $open instanceof QuizAttempt ) {
			return $open;
		}

		$attempt = QuizAttemptRepository::create(
			[
				'user_id'         => $user_id,
				'course_id'       => $course_id,
				'quiz_id'         => $quiz_id,
				'points_possible' => Questions::points_possible( $quiz_id ),
			]
		);

		if ( ! $attempt instanceof QuizAttempt ) {
			return new \WP_Error( 'attempt_failed', \__( 'The attempt could not be started.', 'anchor-schema' ) );
		}

		$this->enrollments->start( $user_id, $course_id );
		$this->progress->record_item( $user_id, $course_id, $quiz_id, 'quiz', 'in_progress' );

		Log::write( 'quiz_started', [ 'user' => $user_id, 'quiz' => $quiz_id, 'attempt' => $attempt->id ] );

		/**
		 * Fires when a new quiz attempt begins (never on a resume - see
		 * start_attempt()'s open-attempt short-circuit above).
		 *
		 * @param QuizAttempt $attempt
		 * @param int         $user_id
		 * @param int         $quiz_id
		 * @param int         $course_id
		 */
		\do_action( 'anchor_courses_quiz_started', $attempt, $user_id, $quiz_id, $course_id );

		return $attempt;
	}

	public function attempts_used( int $user_id, int $quiz_id ): int {
		return QuizAttemptRepository::count_for_quiz( $user_id, $quiz_id );
	}

	/** @return int Remaining attempts, or -1 for unlimited (max_attempts === 0, brief 8.1). */
	public function attempts_remaining( int $user_id, int $quiz_id ): int {
		$max = (int) $this->settings( $quiz_id )['max_attempts'];
		if ( $max <= 0 ) {
			return -1;
		}
		return \max( 0, $max - $this->attempts_used( $user_id, $quiz_id ) );
	}

	/** Unix timestamp when the next attempt unlocks; 0 when it already has. */
	public function retry_available_at( int $user_id, int $quiz_id ): int {
		$delay = (int) $this->settings( $quiz_id )['retry_delay_seconds'];
		if ( $delay <= 0 ) {
			return 0;
		}

		$last = QuizAttemptRepository::last_for_quiz( $user_id, $quiz_id );
		if ( ! $last instanceof QuizAttempt || null === $last->submitted_at ) {
			return 0;
		}

		return Clock::to_timestamp( $last->submitted_at ) + $delay;
	}

	public function get_attempt( int $attempt_id ): ?QuizAttempt {
		return QuizAttemptRepository::find( $attempt_id );
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

	/** Unix timestamp the attempt must be submitted by; 0 when untimed. */
	public function deadline( QuizAttempt $attempt ): int {
		$limit = (int) $this->settings( $attempt->quiz_id )['time_limit_seconds'];
		if ( $limit <= 0 ) {
			return 0;
		}
		return Clock::to_timestamp( $attempt->started_at ) + $limit;
	}

	/**
	 * Store one answer on an open attempt.
	 *
	 * @param mixed $value Answer id, or an array of ids.
	 * @return true|\WP_Error
	 */
	public function save_answer( int $attempt_id, string $question_id, $value ) {
		$attempt = QuizAttemptRepository::find( $attempt_id );
		if ( ! $attempt instanceof QuizAttempt ) {
			return new \WP_Error( 'no_attempt', \__( 'That attempt does not exist.', 'anchor-schema' ) );
		}
		if ( ! $attempt->is_open() ) {
			return new \WP_Error( 'attempt_closed', \__( 'This attempt is already finished.', 'anchor-schema' ) );
		}

		$type = '';
		foreach ( Questions::get( $attempt->quiz_id ) as $question ) {
			if ( (string) $question['id'] === $question_id ) {
				$type = (string) $question['type'];
				break;
			}
		}
		if ( '' === $type ) {
			return new \WP_Error( 'unknown_question', \__( 'That question is not part of this quiz.', 'anchor-schema' ) );
		}

		$answers                 = $attempt->answers;
		$answers[ $question_id ] = Grading::normalize_answer( $type, $value );

		QuizAttemptRepository::update( $attempt_id, [ 'answers' => $answers ] );

		return true;
	}
}
