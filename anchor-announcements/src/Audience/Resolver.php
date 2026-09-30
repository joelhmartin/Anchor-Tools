<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Groups OR'd, conditions in a group AND'd, negated conditions subtracted. */
final class Resolver {

	public function __construct( private Registry $registry ) {}

	/** @param mixed $raw JSON string or array. */
	public function sanitize( $raw ): array {
		if ( \is_string( $raw ) ) {
			$raw = \json_decode( $raw, true );
		}
		$out = [ 'groups' => [] ];
		foreach ( (array) ( \is_array( $raw ) ? ( $raw['groups'] ?? [] ) : [] ) as $group ) {
			$conditions = [];
			foreach ( (array) ( $group['conditions'] ?? [] ) as $c ) {
				$type = \sanitize_key( (string) ( $c['type'] ?? '' ) );
				if ( null === $this->registry->get( $type ) ) {
					continue;
				}
				$conditions[] = [
					'type'   => $type,
					'negate' => ! empty( $c['negate'] ),
					'params' => \is_array( $c['params'] ?? null ) ? $c['params'] : [],
				];
			}
			if ( $conditions ) {
				$out['groups'][] = [ 'conditions' => $conditions ];
			}
		}
		return $out;
	}

	public function resolve( array $rules ): RecipientSet {
		$result   = new RecipientSet();
		$universe = null;
		foreach ( $this->sanitize( $rules )['groups'] as $group ) {
			$set      = null;
			$negated  = [];
			foreach ( $group['conditions'] as $c ) {
				$matches = $this->registry->get( $c['type'] )->match( $c['params'] );
				if ( $c['negate'] ) {
					$negated[] = $matches;
					continue;
				}
				$set = null === $set ? $matches : $set->intersect( $matches );
			}
			if ( null === $set ) {
				$universe = $universe ?? $this->universe();
				$set      = $universe;
			}
			foreach ( $negated as $n ) {
				$set = $set->diff( $n );
			}
			$result = $result->union( $set );
		}
		return $result;
	}

	/** Everyone the site knows: users, WooCommerce buyers, event registrants. */
	public function universe(): RecipientSet {
		$set = new RecipientSet();
		foreach ( \get_users( [ 'fields' => [ 'ID', 'user_email', 'display_name' ] ] ) as $u ) {
			$set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name );
		}
		/**
		 * Add non-user contacts to the universe (WooCommerce guests, event seats).
		 * Tasks 6 and 7 hook this.
		 *
		 * @param RecipientSet $set
		 */
		return \apply_filters( 'anchor_announcements_universe', $set );
	}
}
