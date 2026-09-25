<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Json;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One issued certificate (brief 7.5, 13). Immutable. */
final class Certificate {

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly string $certificate_number,
		public readonly string $issued_at,
		public readonly ?string $expires_at,
		public readonly string $file_path,
		public readonly string $verification_token,
		public readonly array $metadata,
		public readonly string $created_at
	) {}

	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['user_id'] ?? 0 ),
			(int) ( $row['course_id'] ?? 0 ),
			(string) ( $row['certificate_number'] ?? '' ),
			(string) ( $row['issued_at'] ?? '' ),
			Clock::nullable( $row['expires_at'] ?? null ),
			(string) ( $row['file_path'] ?? '' ),
			(string) ( $row['verification_token'] ?? '' ),
			Json::decode( $row['metadata'] ?? null ),
			(string) ( $row['created_at'] ?? '' )
		);
	}

	/** The public HTML certificate / verification URL (Task 30 registers the route). */
	public function url(): string {
		return \home_url( '/certificate/' . \rawurlencode( $this->verification_token ) . '/' );
	}

	public function to_array(): array {
		return [
			'id'                 => $this->id,
			'user_id'            => $this->user_id,
			'course_id'          => $this->course_id,
			'certificate_number' => $this->certificate_number,
			'issued_at'          => $this->issued_at,
			'expires_at'         => $this->expires_at,
			'file_path'          => $this->file_path,
			'verification_token' => $this->verification_token,
			'metadata'           => $this->metadata,
			'created_at'         => $this->created_at,
			'url'                => $this->url(),
		];
	}
}
