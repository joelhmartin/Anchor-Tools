<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One learner's state on one curriculum item (brief 7.2). Immutable. */
final class Progress {

	public const STATUSES   = [ 'not_started', 'in_progress', 'completed', 'failed' ];
	public const ITEM_TYPES = [ 'lesson', 'quiz' ];

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly int $item_id,
		public readonly string $item_type,
		public readonly string $status,
		public readonly float $progress_percent,
		public readonly ?string $started_at,
		public readonly ?string $completed_at,
		public readonly ?string $last_viewed_at,
		public readonly int $time_spent_seconds,
		public readonly array $metadata,
		public readonly string $created_at,
		public readonly string $updated_at
	) {}

	public static function from_row( array $row ): self {
		$metadata = \json_decode( (string) ( $row['metadata'] ?? '' ), true );

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['user_id'] ?? 0 ),
			(int) ( $row['course_id'] ?? 0 ),
			(int) ( $row['item_id'] ?? 0 ),
			(string) ( $row['item_type'] ?? 'lesson' ),
			(string) ( $row['status'] ?? 'not_started' ),
			(float) ( $row['progress_percent'] ?? 0 ),
			Clock::nullable( $row['started_at'] ?? null ),
			Clock::nullable( $row['completed_at'] ?? null ),
			Clock::nullable( $row['last_viewed_at'] ?? null ),
			(int) ( $row['time_spent_seconds'] ?? 0 ),
			\is_array( $metadata ) ? $metadata : [],
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/** The key this item is addressed by everywhere in the module. */
	public function key(): string {
		return $this->item_type . ':' . $this->item_id;
	}

	public function is_complete(): bool {
		return 'completed' === $this->status;
	}

	public function to_array(): array {
		return [
			'id'                 => $this->id,
			'user_id'            => $this->user_id,
			'course_id'          => $this->course_id,
			'item_id'            => $this->item_id,
			'item_type'          => $this->item_type,
			'status'             => $this->status,
			'progress_percent'   => $this->progress_percent,
			'started_at'         => $this->started_at,
			'completed_at'       => $this->completed_at,
			'last_viewed_at'     => $this->last_viewed_at,
			'time_spent_seconds' => $this->time_spent_seconds,
			'metadata'           => $this->metadata,
			'created_at'         => $this->created_at,
			'updated_at'         => $this->updated_at,
		];
	}
}
