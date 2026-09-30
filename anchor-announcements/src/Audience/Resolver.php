<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Groups OR'd, conditions in a group AND'd, negated conditions subtracted. */
final class Resolver {

	public function __construct( private Registry $registry ) {}

	/**
	 * Structural clean-up only: keeps every condition that names a type (known or not,
	 * available or not, filled in or not) so an author never loses a rule on save. Whether
	 * a condition can actually be used is problems()' job, and resolve() fails closed on it.
	 *
	 * @param mixed $raw JSON string or array.
	 */
	public function sanitize( $raw ): array {
		if ( \is_string( $raw ) ) {
			$raw = \json_decode( $raw, true );
		}
		$out = [ 'groups' => [] ];
		foreach ( (array) ( \is_array( $raw ) ? ( $raw['groups'] ?? [] ) : [] ) as $group ) {
			$conditions = [];
			foreach ( (array) ( \is_array( $group ) ? ( $group['conditions'] ?? [] ) : [] ) as $c ) {
				$type = \is_array( $c ) && \is_string( $c['type'] ?? null ) ? \sanitize_key( $c['type'] ) : '';
				if ( '' === $type ) {
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

	/**
	 * Human-readable reasons the rules cannot be used as written: a condition that is not
	 * available on this site (unknown type, WooCommerce off) or not filled in. Sending
	 * refuses while any exist, and resolve() gives such a group no recipients.
	 *
	 * @return list<string>
	 */
	public function problems( array $rules ): array {
		$out = [];
		foreach ( $this->sanitize( $rules )['groups'] as $gi => $group ) {
			foreach ( $this->group_problems( $group, $gi + 1 ) as $msg ) {
				$out[] = $msg;
			}
		}
		return $out;
	}

	/** @return list<string> */
	private function group_problems( array $group, int $number ): array {
		$out = [];
		foreach ( $group['conditions'] as $c ) {
			$cond = $this->registry->get( $c['type'] );
			if ( null === $cond ) {
				/* translators: 1: group number, 2: condition type */
				$out[] = \sprintf( \__( 'Group %1$d: "%2$s" is not available on this site.', 'anchor-schema' ), $number, $c['type'] );
			} elseif ( ! self::configured( $cond, $c['params'] ) ) {
				/* translators: 1: group number, 2: condition label */
				$out[] = \sprintf( \__( 'Group %1$d: "%2$s" is not filled in.', 'anchor-schema' ), $number, $cond->label() );
			}
		}
		return $out;
	}

	/**
	 * Optional condition methods (not on the interface, so third-party conditions keep working):
	 * required(): list of param keys, any one of which must be non-empty;
	 * complete( array $params ): bool for rules a key list cannot express.
	 */
	private static function configured( Condition $cond, array $params ): bool {
		if ( \method_exists( $cond, 'required' ) ) {
			$keys = (array) $cond->required();
			if ( $keys ) {
				$ok = false;
				foreach ( $keys as $k ) {
					$v = $params[ $k ] ?? null;
					if ( \is_array( $v ) ? (bool) \array_filter( $v, static fn( $x ) => '' !== \trim( (string) $x ) ) : '' !== \trim( (string) $v ) ) {
						$ok = true;
						break;
					}
				}
				if ( ! $ok ) {
					return false;
				}
			}
		}
		return ! \method_exists( $cond, 'complete' ) || (bool) $cond->complete( $params );
	}

	public function resolve( array $rules ): RecipientSet {
		$result   = new RecipientSet();
		$universe = null;
		foreach ( $this->sanitize( $rules )['groups'] as $gi => $group ) {
			if ( $this->group_problems( $group, $gi + 1 ) ) {
				continue; // Fail closed: a group with an unusable condition contributes nobody.
			}
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
