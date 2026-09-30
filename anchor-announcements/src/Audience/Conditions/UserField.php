<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class UserField implements Condition {
	private const COMPARES = [ '=' => '=', '!=' => '!=', 'contains' => 'LIKE', 'exists' => 'EXISTS', 'not_exists' => 'NOT EXISTS', '>' => '>', '<' => '<' ];

	public function key(): string { return 'user_field'; }
	public function label(): string { return \__( 'User profile field', 'anchor-schema' ); }
	public function group(): string { return \__( 'Site users', 'anchor-schema' ); }
	public function available(): bool { return true; }
	public function fields(): array {
		return [
			[ 'key' => 'key', 'type' => 'text', 'label' => \__( 'Field (meta key)', 'anchor-schema' ) ],
			[ 'key' => 'compare', 'type' => 'select', 'label' => \__( 'Compare', 'anchor-schema' ), 'options' => [ '=' => 'equals', '!=' => 'does not equal', 'contains' => 'contains', 'exists' => 'is set', 'not_exists' => 'is not set', '>' => 'greater than', '<' => 'less than' ], 'default' => '=' ],
			[ 'key' => 'value', 'type' => 'text', 'label' => \__( 'Value', 'anchor-schema' ) ],
		];
	}
	/** A key is always needed; a value too unless the compare is "is set" / "is not set". */
	public function complete( array $params ): bool {
		if ( '' === self::clean_key( $params ) ) {
			return false;
		}
		return \in_array( (string) ( $params['compare'] ?? '=' ), [ 'exists', 'not_exists' ], true ) || '' !== \trim( (string) ( $params['value'] ?? '' ) );
	}
	/** Meta keys are case-sensitive: strip unsafe characters only. */
	private static function clean_key( array $params ): string {
		return (string) \preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $params['key'] ?? '' ) );
	}
	public function match( array $params ): RecipientSet {
		$set = new RecipientSet();
		$key = self::clean_key( $params );
		$cmp = self::COMPARES[ (string) ( $params['compare'] ?? '=' ) ] ?? null;
		if ( '' === $key || null === $cmp ) {
			return $set;
		}
		$clause = [ 'key' => $key, 'compare' => $cmp ];
		if ( ! \in_array( $cmp, [ 'EXISTS', 'NOT EXISTS' ], true ) ) {
			$clause['value'] = (string) ( $params['value'] ?? '' );
			if ( \in_array( $cmp, [ '>', '<' ], true ) ) {
				$clause['type'] = 'NUMERIC';
			}
		}
		foreach ( \get_users( [ 'meta_query' => [ $clause ], 'fields' => [ 'ID', 'user_email', 'display_name' ] ] ) as $u ) {
			$set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name );
		}
		return $set;
	}
}
