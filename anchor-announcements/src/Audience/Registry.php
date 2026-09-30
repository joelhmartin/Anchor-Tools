<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Registry {

	/** @var array<string,Condition> */
	private array $conditions = [];

	public function register( Condition $c ): void {
		$this->conditions[ $c->key() ] = $c;
	}

	/** @return array<string,Condition> Available conditions only. */
	public function all(): array {
		/**
		 * Add or remove audience conditions.
		 *
		 * @param array<string,Condition> $conditions Keyed by Condition::key().
		 */
		$all = (array) \apply_filters( 'anchor_announcements_conditions', $this->conditions );
		return \array_filter( $all, static fn( $c ) => $c instanceof Condition && $c->available() );
	}

	public function get( string $key ): ?Condition {
		return $this->all()[ $key ] ?? null;
	}
}
