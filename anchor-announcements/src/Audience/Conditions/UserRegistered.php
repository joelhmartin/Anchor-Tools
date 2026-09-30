<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Support\Dates;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class UserRegistered implements Condition {
	public function key(): string { return 'user_registered'; }
	public function label(): string { return \__( 'Account created', 'anchor-schema' ); }
	public function group(): string { return \__( 'Site users', 'anchor-schema' ); }
	public function available(): bool { return true; }
	public function fields(): array {
		return [
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'From', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'To', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		global $wpdb;
		[ $from, $to ] = Dates::gmt_range( (string) ( $params['from'] ?? '' ), (string) ( $params['to'] ?? '' ) );
		$where = [ '1=1' ];
		$args  = [];
		if ( $from ) { $where[] = 'user_registered >= %s'; $args[] = $from; }
		if ( $to ) { $where[] = 'user_registered <= %s'; $args[] = $to; }
		$sql  = "SELECT ID, user_email, display_name FROM {$wpdb->users} WHERE " . \implode( ' AND ', $where );
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
		$set  = new RecipientSet();
		foreach ( $rows as $r ) {
			$set->add( (string) $r->user_email, (int) $r->ID, (string) $r->display_name );
		}
		return $set;
	}
}
