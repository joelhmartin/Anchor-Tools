<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Json;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One learner's run at one quiz (brief 7.3, 8.4). Immutable. */
final class QuizAttempt {

	public const STATUSES = [ 'in_progress', 'submitted', 'graded', 'expired', 'abandoned' ];

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly int $quiz_id,
		public readonly int $attempt_number,
		public readonly string $status,
		public readonly ?float $score,
		public readonly ?float $points_earned,
		public readonly ?float $points_possible,
		public readonly bool $passed,
		public readonly string $started_at,
		public readonly ?string $submitted_at,
		public readonly ?int $duration_seconds,
		public readonly array $answers,
		public readonly array $grading_data,
		public readonly array $metadata,
		public readonly string $created_at,
		public readonly string $updated_at
	) {}

	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['user_id'] ?? 0 ),
			(int) ( $row['course_id'] ?? 0 ),
			(int) ( $row['quiz_id'] ?? 0 ),
			(int) ( $row['attempt_number'] ?? 0 ),
			(string) ( $row['status'] ?? 'in_progress' ),
			isset( $row['score'] ) && null !== $row['score'] ? (float) $row['score'] : null,
			isset( $row['points_earned'] ) && null !== $row['points_earned'] ? (float) $row['points_earned'] : null,
			isset( $row['points_possible'] ) && null !== $row['points_possible'] ? (float) $row['points_possible'] : null,
			! empty( $row['passed'] ),
			(string) ( $row['started_at'] ?? '' ),
			Clock::nullable( $row['submitted_at'] ?? null ),
			isset( $row['duration_seconds'] ) && null !== $row['duration_seconds'] ? (int) $row['duration_seconds'] : null,
			Json::decode( $row['answers'] ?? null ),
			Json::decode( $row['grading_data'] ?? null ),
			Json::decode( $row['metadata'] ?? null ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	public function is_open(): bool {
		return 'in_progress' === $this->status;
	}

	public function is_graded(): bool {
		return 'graded' === $this->status;
	}

	/**
	 * The ONLY projection a learner-facing surface may serialise.
	 *
	 * `answers` are the learner's own, so they are safe. `grading_data` holds
	 * per-question correctness (and may embed correct-answer ids) and is
	 * withheld unless the caller says the quiz allows showing correct answers
	 * AND the attempt is graded (brief 25, rule 8). `to_array()` below is NOT
	 * safe for a learner-facing response - it always includes grading_data.
	 */
	public function for_learner( bool $show_correct ): array {
		// metadata holds the pinned time_limit_seconds/on_timer_expiry (brief
		// T24 ruling R3) - internal timer bookkeeping, never learner-facing.
		$out = [
			'id'              => $this->id,
			'quiz_id'         => $this->quiz_id,
			'course_id'       => $this->course_id,
			'attempt_number'  => $this->attempt_number,
			'status'          => $this->status,
			'score'           => $this->score,
			'points_earned'   => $this->points_earned,
			'points_possible' => $this->points_possible,
			'passed'          => $this->passed,
			'started_at'      => $this->started_at,
			'submitted_at'    => $this->submitted_at,
			'answers'         => $this->answers,
		];

		if ( $show_correct && $this->is_graded() ) {
			$out['grading_data'] = $this->grading_data;
		}

		return $out;
	}

	public function to_array(): array {
		return [
			'id'               => $this->id,
			'user_id'          => $this->user_id,
			'course_id'        => $this->course_id,
			'quiz_id'          => $this->quiz_id,
			'attempt_number'   => $this->attempt_number,
			'status'           => $this->status,
			'score'            => $this->score,
			'points_earned'    => $this->points_earned,
			'points_possible'  => $this->points_possible,
			'passed'           => $this->passed,
			'started_at'       => $this->started_at,
			'submitted_at'     => $this->submitted_at,
			'duration_seconds' => $this->duration_seconds,
			'answers'          => $this->answers,
			'grading_data'     => $this->grading_data,
			'metadata'         => $this->metadata,
			'created_at'       => $this->created_at,
			'updated_at'       => $this->updated_at,
		];
	}
}
