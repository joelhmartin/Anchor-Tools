<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Admin\LessonEditor;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Database\ProgressRepository;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\CourseProgress;
use Anchor\Courses\Domain\Progress;
use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The one place course progress is calculated (brief section 10).
 *
 * Templates, shortcodes, REST controllers and the admin reports all call this;
 * none of them recomputes completion for itself. percent() is a pure static so
 * the formula is unit-tested without WordPress.
 */
final class ProgressService {

	private ?CompletionService $completion = null;

	public function __construct( private ?EnrollmentService $enrollments = null ) {
		$this->enrollments = $enrollments ?? new EnrollmentService();
	}

	/** Injected by the Module once CompletionService exists (Task 29). */
	public function set_completion_service( CompletionService $service ): void {
		$this->completion = $service;
	}

	/**
	 * completed required items / total required items * 100, 2dp.
	 *
	 * A course with nothing required is 100% - there is nothing left to do, and
	 * the alternative is a division by zero. Pure.
	 */
	public static function percent( int $completed_required, int $total_required ): float {
		if ( $total_required <= 0 ) {
			return 100.0;
		}
		$raw = ( \max( 0, $completed_required ) / $total_required ) * 100;
		return \round( \min( 100.0, $raw ), 2 );
	}

	public function get_course_progress( int $user_id, int $course_id ): CourseProgress {
		$required  = Curriculum::required_items( $course_id );
		$completed = ProgressRepository::completed_keys( $user_id, $course_id );

		$done = 0;
		foreach ( $required as $item ) {
			if ( \in_array( $item['type'] . ':' . $item['id'], $completed, true ) ) {
				$done++;
			}
		}

		$total   = \count( $required );
		$percent = self::percent( $done, $total );

		$mode     = (string) CourseEditor::setting( $course_id, 'completion_mode' );
		$complete = false;

		if ( 'all_required_items' === $mode ) {
			$complete = $total > 0 && $done >= $total;
		} elseif ( 'minimum_percentage' === $mode ) {
			$complete = $percent >= (float) CourseEditor::setting( $course_id, 'completion_percentage' );
		}
		// 'manual' never derives completion from items - an admin marks it.

		/**
		 * Filter whether this learner's course counts as complete.
		 *
		 * @param bool $complete
		 * @param int  $user_id
		 * @param int  $course_id
		 */
		$complete = (bool) \apply_filters( 'anchor_courses_course_completion_status', $complete, $user_id, $course_id );

		return new CourseProgress( $user_id, $course_id, $done, $total, $percent, $complete, $completed );
	}

	/** @return string[] "{type}:{id}" */
	public function get_completed_items( int $user_id, int $course_id ): array {
		return ProgressRepository::completed_keys( $user_id, $course_id );
	}

	/**
	 * May this learner open this item right now?
	 *
	 * Sequential progression requires every REQUIRED item before it to be
	 * complete; optional items never block.
	 *
	 * One exception (audit F04): a quiz is not blocked by its PARENT lesson -
	 * an earlier lesson whose completion_mode is `quiz_pass` with this quiz
	 * as its `quiz_id`. That lesson's completion depends on the quiz, not the
	 * other way round, so requiring it first deadlocked the natural "lesson,
	 * then its quiz" order. Every other earlier required item still gates,
	 * which includes everything before the parent lesson - so the quiz opens
	 * exactly when its lesson does.
	 */
	public function is_item_available( int $user_id, int $course_id, int $item_id, string $item_type = 'lesson' ): bool {
		$allowed = true;

		if ( ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			$allowed = false;
		} elseif ( ! Curriculum::contains( $course_id, $item_id, $item_type ) ) {
			$allowed = false;
		} elseif ( 'sequential' === (string) CourseEditor::setting( $course_id, 'progression_mode' ) ) {
			$completed = ProgressRepository::completed_keys( $user_id, $course_id );
			foreach ( Curriculum::items_before( $course_id, $item_id, $item_type ) as $earlier ) {
				if ( ! $earlier['required'] ) {
					continue;
				}
				if ( 'quiz' === $item_type && 'lesson' === $earlier['type'] && self::lesson_completes_by_quiz( (int) $earlier['id'], $item_id ) ) {
					continue; // The quiz's own parent lesson (see docblock).
				}
				if ( ! \in_array( $earlier['type'] . ':' . $earlier['id'], $completed, true ) ) {
					$allowed = false;
					break;
				}
			}
		}

		/**
		 * Filter access to one curriculum item.
		 *
		 * @param bool   $allowed
		 * @param int    $user_id
		 * @param int    $course_id
		 * @param int    $item_id
		 * @param string $item_type
		 */
		return (bool) \apply_filters( 'anchor_courses_can_access_lesson', $allowed, $user_id, $course_id, $item_id, $item_type );
	}

	/**
	 * Record a view. Starts the enrolment on first contact, and completes the
	 * lesson immediately when its completion mode is `view`.
	 */
	public function start_lesson( int $user_id, int $course_id, int $lesson_id ): ?Progress {
		if ( ! $this->is_item_available( $user_id, $course_id, $lesson_id, 'lesson' ) ) {
			return null;
		}

		$this->enrollments->start( $user_id, $course_id );

		$existing = ProgressRepository::find( $user_id, $course_id, $lesson_id, 'lesson' );
		$now      = Clock::now();
		$first    = ! $existing instanceof Progress;

		$data = [
			'user_id'        => $user_id,
			'course_id'      => $course_id,
			'item_id'        => $lesson_id,
			'item_type'      => 'lesson',
			'last_viewed_at' => $now,
		];
		if ( $first ) {
			$data['status']     = 'in_progress';
			$data['started_at'] = $now;
		}

		$progress = ProgressRepository::upsert( $data );

		if ( $first && $progress instanceof Progress ) {
			/**
			 * Fires the first time a learner opens a lesson.
			 *
			 * @param int      $user_id
			 * @param int      $course_id
			 * @param int      $lesson_id
			 * @param Progress $progress
			 */
			\do_action( 'anchor_courses_lesson_started', $user_id, $course_id, $lesson_id, $progress );
		}

		if ( 'view' === (string) LessonEditor::setting( $lesson_id, 'completion_mode' ) ) {
			$completed = $this->complete_lesson( $user_id, $course_id, $lesson_id );
			if ( $completed instanceof Progress ) {
				return $completed;
			}
		}

		return $progress;
	}

	/**
	 * Complete a lesson. Idempotent - a second call returns the existing row and
	 * fires nothing.
	 *
	 * @return Progress|\WP_Error
	 */
	public function complete_lesson( int $user_id, int $course_id, int $lesson_id ) {
		if ( ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			return new \WP_Error( 'not_enrolled', \__( 'You are not enrolled in this course.', 'anchor-schema' ) );
		}
		if ( ! Curriculum::contains( $course_id, $lesson_id, 'lesson' ) ) {
			return new \WP_Error( 'not_in_course', \__( 'That lesson is not part of this course.', 'anchor-schema' ) );
		}

		$existing = ProgressRepository::find( $user_id, $course_id, $lesson_id, 'lesson' );
		if ( $existing instanceof Progress && $existing->is_complete() ) {
			return $existing;
		}

		if ( ! $this->is_item_available( $user_id, $course_id, $lesson_id, 'lesson' ) ) {
			return new \WP_Error( 'locked', \__( 'Finish the earlier lessons first.', 'anchor-schema' ) );
		}

		// A quiz-gated lesson is completed by the quiz service, never by hand.
		if ( 'quiz_pass' === (string) LessonEditor::setting( $lesson_id, 'completion_mode' )
			&& ! $this->quiz_passed_for_lesson( $user_id, $course_id, $lesson_id ) ) {
			return new \WP_Error( 'quiz_required', \__( 'Pass this lesson\'s quiz to complete it.', 'anchor-schema' ) );
		}

		$progress = $this->record_item( $user_id, $course_id, $lesson_id, 'lesson', 'completed' );
		if ( ! $progress instanceof Progress ) {
			return new \WP_Error( 'save_failed', \__( 'The progress could not be saved.', 'anchor-schema' ) );
		}

		/**
		 * Fires once, the first time a lesson is completed.
		 *
		 * @param int      $user_id
		 * @param int      $course_id
		 * @param int      $lesson_id
		 * @param Progress $progress
		 */
		\do_action( 'anchor_courses_lesson_completed', $user_id, $course_id, $lesson_id, $progress );

		$this->recalculate_course( $user_id, $course_id );

		return $progress;
	}

	/** Is this lesson completed by passing exactly this quiz (`quiz_pass` + `quiz_id`)? */
	public static function lesson_completes_by_quiz( int $lesson_id, int $quiz_id ): bool {
		return 'quiz_pass' === (string) LessonEditor::setting( $lesson_id, 'completion_mode' )
			&& (int) LessonEditor::setting( $lesson_id, 'quiz_id' ) === $quiz_id
			&& $quiz_id > 0;
	}

	/**
	 * Required `quiz_pass` lessons whose quiz a learner could never reach in
	 * this course (audit F04, re-review): the quiz is absent from the
	 * curriculum (`quiz_absent`), or - sequential progression only - a
	 * required item sits strictly between the lesson and its quiz
	 * (`item_between`), which is a genuine deadlock: that item waits for the
	 * lesson (sequential gating), the quiz waits for that item (same gating,
	 * since the "skip the parent lesson" exception in is_item_available()
	 * only ever excuses the lesson itself), and the lesson waits for the quiz
	 * (`quiz_pass`). Neither can ever finish.
	 *
	 * A quiz placed BEFORE its lesson, with nothing required between them, is
	 * not a problem: the quiz opens with the earlier items (nothing gates it
	 * on the lesson), and passing it completes the lesson directly via
	 * `QuizService::complete_gated_lessons()` - so this is deliberately not
	 * reported, unlike the prior revision of this method.
	 *
	 * Surfaced as a warning when the curriculum is saved
	 * (Admin\CourseEditor::save_curriculum()).
	 *
	 * @return array<int,array{lesson_id:int,quiz_id:int,problem:string}>
	 */
	public static function quiz_link_problems( int $course_id ): array {
		$problems   = [];
		$items      = Curriculum::items( $course_id );
		$sequential = 'sequential' === (string) CourseEditor::setting( $course_id, 'progression_mode' );

		foreach ( $items as $item ) {
			if ( 'lesson' !== $item['type'] || ! $item['required'] ) {
				continue;
			}
			$lesson_id = (int) $item['id'];
			if ( 'quiz_pass' !== (string) LessonEditor::setting( $lesson_id, 'completion_mode' ) ) {
				continue;
			}
			$quiz_id  = (int) LessonEditor::setting( $lesson_id, 'quiz_id' );
			$position = $quiz_id > 0 ? Curriculum::position( $course_id, $quiz_id, 'quiz' ) : -1;
			if ( $position < 0 ) {
				$problems[] = [ 'lesson_id' => $lesson_id, 'quiz_id' => $quiz_id, 'problem' => 'quiz_absent' ];
			} elseif (
				$sequential
				// CodeRabbit PR #32: only when the LESSON precedes its quiz.
				// A quiz placed before its lesson opens with the earlier
				// items regardless of what sits between them - nothing gates
				// it on the lesson - so an intervening required item there is
				// not a deadlock (see the class docblock above).
				&& (int) $item['index'] < $position
				&& self::required_item_between( $items, (int) $item['index'], $position )
			) {
				$problems[] = [ 'lesson_id' => $lesson_id, 'quiz_id' => $quiz_id, 'problem' => 'item_between' ];
			}
		}
		return $problems;
	}

	/** Any required curriculum item strictly between two flattened positions? */
	private static function required_item_between( array $items, int $position_a, int $position_b ): bool {
		$low  = \min( $position_a, $position_b );
		$high = \max( $position_a, $position_b );
		foreach ( $items as $item ) {
			if ( $item['required'] && (int) $item['index'] > $low && (int) $item['index'] < $high ) {
				return true;
			}
		}
		return false;
	}

	/** Has the lesson's quiz been passed already? */
	private function quiz_passed_for_lesson( int $user_id, int $course_id, int $lesson_id ): bool {
		$quiz_id = (int) LessonEditor::setting( $lesson_id, 'quiz_id' );
		if ( $quiz_id <= 0 ) {
			return false;
		}
		$quiz_progress = ProgressRepository::find( $user_id, $course_id, $quiz_id, 'quiz' );
		return $quiz_progress instanceof Progress && $quiz_progress->is_complete();
	}

	/**
	 * Write one item's state. The single write path used by both the lesson
	 * flow and the quiz service.
	 *
	 * A completed item is never downgraded (final review I5, amending Task 24
	 * ruling R2): neither a retake's 'in_progress' bookkeeping write
	 * (QuizService::start_attempt()) nor a later FAILED attempt may replace a
	 * completed row - the best attempt counts, so the items after it stay
	 * unlocked. A repeat 'completed' write (a second pass) refreshes the row's
	 * metadata but keeps the original completed_at. Guarded here since this
	 * is the one write path both callers share; an admin reset deletes the
	 * rows outright (reset_course()) rather than writing over them.
	 */
	public function record_item(
		int $user_id,
		int $course_id,
		int $item_id,
		string $item_type,
		string $status,
		array $extra = []
	): ?Progress {
		if ( ! \in_array( $status, Progress::STATUSES, true ) ) {
			return null;
		}

		$existing = ProgressRepository::find( $user_id, $course_id, $item_id, $item_type );

		if ( $existing instanceof Progress && $existing->is_complete() ) {
			if ( 'completed' !== $status ) {
				return $existing;
			}
			$extra['completed_at'] = $extra['completed_at'] ?? $existing->completed_at;
		}

		$now  = Clock::now();
		$data = \array_merge(
			[
				'user_id'          => $user_id,
				'course_id'        => $course_id,
				'item_id'          => $item_id,
				'item_type'        => $item_type,
				'status'           => $status,
				'progress_percent' => 'completed' === $status ? 100.0 : 0.0,
				'last_viewed_at'   => $now,
			],
			$extra
		);

		if ( 'completed' === $status && ! isset( $data['completed_at'] ) ) {
			$data['completed_at'] = $now;
		}

		if ( ! $existing instanceof Progress ) {
			$data['started_at'] = $data['started_at'] ?? $now;
		}

		return ProgressRepository::upsert( $data );
	}

	/**
	 * Start a learner over on one course (the admin "Reset progress" action).
	 *
	 * The enrolment row is reopened, and its progress rows deleted and quiz
	 * attempts voided as `abandoned` (a non-counted status, so max_attempts
	 * is fully restored while the history stays on record - final review
	 * I6), under ONE HOLD of the same per-(user, course) completion lock
	 * `complete()`/`uncomplete()` take (Round 9, Codex + CodeRabbit Major, PR
	 * #32 finding 1 - supersedes Round 8, which held the lock for the reopen
	 * write alone and released it before clearing progress: a completion
	 * racing into that gap could complete the reopened row against the
	 * still-present OLD progress moments before this deleted it). Both
	 * halves run inside `CompletionService::reopen_for_reset()` - see its
	 * docblock for the race this closes. A busy lock, or a failed write,
	 * leaves the WHOLE reset un-run, never a half-reset with progress erased
	 * but the enrolment row untouched (or vice versa). Credits, certificates
	 * and the completion role are records of something that happened and are
	 * left alone.
	 *
	 * @return bool False when the completion lock is busy or the enrolment
	 *              write failed - nothing else is touched either
	 *              (`Admin\EnrollmentManager`'s `reset` action reports
	 *              `reset_busy`). True otherwise, including when there was
	 *              no enrolment to reset at all.
	 */
	public function reset_course( int $user_id, int $course_id ): bool {
		// Static (see CompletionService::reopen_for_reset()'s own docblock):
		// the lock it takes is server-wide, not tied to $this->completion -
		// this call must serialise against a real completion pipeline
		// running elsewhere even when THIS instance has none injected.
		return CompletionService::reopen_for_reset(
			$user_id,
			$course_id,
			[ 'status' => 'enrolled', 'started_at' => null, 'completed_at' => null ],
			static function () use ( $user_id, $course_id ): void {
				ProgressRepository::delete_for_course( $user_id, $course_id );
				QuizAttemptRepository::abandon_for_course( $user_id, $course_id );
			}
		);
	}

	/**
	 * Recompute the rolled-up progress and hand it to the completion service.
	 *
	 * Called after every write. CompletionService is responsible for the
	 * once-only side effects (credits, certificate, hooks).
	 */
	public function recalculate_course( int $user_id, int $course_id ): CourseProgress {
		$progress = $this->get_course_progress( $user_id, $course_id );

		if ( $progress->complete && $this->completion instanceof CompletionService ) {
			$this->completion->complete( $user_id, $course_id );
		}

		return $progress;
	}
}
