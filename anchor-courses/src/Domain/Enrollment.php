<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One learner's relationship to one course (brief 7.1). Immutable. */
final class Enrollment {

	public const STATUSES = [ 'enrolled', 'in_progress', 'completed', 'expired', 'cancelled' ];

	/** Statuses that still grant access to the course. */
	public const ACTIVE_STATUSES = [ 'enrolled', 'in_progress' ];

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly string $status,
		public readonly string $enrolled_at,
		public readonly ?string $started_at,
		public readonly ?string $completed_at,
		public readonly ?string $expires_at,
		public readonly string $source,
		public readonly string $source_id,
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
			(string) ( $row['status'] ?? 'enrolled' ),
			(string) ( $row['enrolled_at'] ?? '' ),
			isset( $row['started_at'] ) ? (string) $row['started_at'] : null,
			isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null,
			isset( $row['expires_at'] ) ? (string) $row['expires_at'] : null,
			(string) ( $row['source'] ?? '' ),
			(string) ( $row['source_id'] ?? '' ),
			\is_array( $metadata ) ? $metadata : [],
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	public function is_active(): bool {
		return \in_array( $this->status, self::ACTIVE_STATUSES, true );
	}

	public function is_complete(): bool {
		return 'completed' === $this->status;
	}

	public function to_array(): array {
		return [
			'id'           => $this->id,
			'user_id'      => $this->user_id,
			'course_id'    => $this->course_id,
			'status'       => $this->status,
			'enrolled_at'  => $this->enrolled_at,
			'started_at'   => $this->started_at,
			'completed_at' => $this->completed_at,
			'expires_at'   => $this->expires_at,
			'source'       => $this->source,
			'source_id'    => $this->source_id,
			'metadata'     => $this->metadata,
			'created_at'   => $this->created_at,
			'updated_at'   => $this->updated_at,
		];
	}
}
