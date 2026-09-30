<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** A set of recipients keyed by lowercased email. A user's data beats a guest's. */
final class RecipientSet {

	/** @var array<string,array{email:string,user_id:int,name:string}> */
	private array $rows = [];

	public function add( string $email, int $user_id = 0, string $name = '' ): void {
		$email = \strtolower( \trim( $email ) );
		if ( ! \is_email( $email ) ) {
			return;
		}
		$existing = $this->rows[ $email ] ?? null;
		if ( null === $existing || ( $user_id > 0 && 0 === $existing['user_id'] ) ) {
			$this->rows[ $email ] = [ 'email' => $email, 'user_id' => \max( 0, $user_id ), 'name' => $name ];
			return;
		}
		if ( '' === $existing['name'] && '' !== $name ) {
			$this->rows[ $email ]['name'] = $name;
		}
	}

	public static function from_user( \WP_User $u ): array {
		return [ 'email' => (string) $u->user_email, 'user_id' => (int) $u->ID, 'name' => (string) $u->display_name ];
	}

	private function add_row( array $row ): void {
		$this->add( $row['email'], (int) $row['user_id'], (string) $row['name'] );
	}

	public function union( RecipientSet $other ): RecipientSet {
		$out = clone $this;
		foreach ( $other->rows as $row ) { $out->add_row( $row ); }
		return $out;
	}

	public function intersect( RecipientSet $other ): RecipientSet {
		$out = new RecipientSet();
		foreach ( $this->rows as $email => $row ) {
			if ( isset( $other->rows[ $email ] ) ) {
				$out->add_row( $row );
				$out->add_row( $other->rows[ $email ] );
			}
		}
		return $out;
	}

	public function diff( RecipientSet $other ): RecipientSet {
		$out = new RecipientSet();
		foreach ( $this->rows as $email => $row ) {
			if ( ! isset( $other->rows[ $email ] ) ) { $out->add_row( $row ); }
		}
		return $out;
	}

	public function count(): int { return \count( $this->rows ); }

	public function has( string $email ): bool { return isset( $this->rows[ \strtolower( \trim( $email ) ) ] ); }

	public function get( string $email ): ?array { return $this->rows[ \strtolower( \trim( $email ) ) ] ?? null; }

	/** @return list<array{email:string,user_id:int,name:string}> */
	public function all(): array {
		$rows = $this->rows;
		\ksort( $rows );
		return \array_values( $rows );
	}
}
