<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Json;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One CE award (brief 7.4, 12). Immutable. */
final class Credit {

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly float $credits,
		public readonly string $credit_type,
		public readonly string $awarded_at,
		public readonly ?string $expires_at,
		public readonly int $certificate_id,
		public readonly array $metadata,
		public readonly string $created_at
	) {}

	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['user_id'] ?? 0 ),
			(int) ( $row['course_id'] ?? 0 ),
			(float) ( $row['credits'] ?? 0 ),
			(string) ( $row['credit_type'] ?? '' ),
			(string) ( $row['awarded_at'] ?? '' ),
			Clock::nullable( $row['expires_at'] ?? null ),
			(int) ( $row['certificate_id'] ?? 0 ),
			Json::decode( $row['metadata'] ?? null ),
			(string) ( $row['created_at'] ?? '' )
		);
	}

	public function is_expired( int $now ): bool {
		if ( null === $this->expires_at ) {
			return false;
		}
		return $now > Clock::to_timestamp( $this->expires_at );
	}

	public function to_array(): array {
		return [
			'id'             => $this->id,
			'user_id'        => $this->user_id,
			'course_id'      => $this->course_id,
			'credits'        => $this->credits,
			'credit_type'    => $this->credit_type,
			'awarded_at'     => $this->awarded_at,
			'expires_at'     => $this->expires_at,
			'certificate_id' => $this->certificate_id,
			'metadata'       => $this->metadata,
			'created_at'     => $this->created_at,
		];
	}
}
