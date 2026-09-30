<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Support\Dates;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class EventRegistered implements Condition {
	public const SEAT_CPT = 'anchor_event_reg';

	public function key(): string { return 'event_registered'; }
	public function label(): string { return \__( 'Registered for an event', 'anchor-schema' ); }
	public function group(): string { return \__( 'Events', 'anchor-schema' ); }
	public function available(): bool { return \class_exists( '\Anchor\Events\Module' ); }
	public function fields(): array {
		return [
			[ 'key' => 'events', 'type' => 'search', 'search' => 'events', 'label' => \__( 'Any of these events (empty: any event)', 'anchor-schema' ) ],
			[ 'key' => 'statuses', 'type' => 'multiselect', 'label' => \__( 'Seat status', 'anchor-schema' ), 'options' => [ 'confirmed' => 'confirmed', 'pending' => 'pending', 'waitlist' => 'waitlist', 'cancelled' => 'cancelled', 'refunded' => 'refunded' ], 'default' => [ 'confirmed' ] ],
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'Registered from', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'Registered to', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		return self::seats( $params, false );
	}

	/** Seats matching $params; with $all, every seat with an email (the universe). */
	public static function seats( array $params, bool $all ): RecipientSet {
		global $wpdb;
		$pm   = $wpdb->postmeta;
		$sql  = "SELECT p.ID, em.meta_value AS email, nm.meta_value AS name, CAST(us.meta_value AS UNSIGNED) AS user_id
			FROM {$wpdb->posts} p
			JOIN {$pm} em ON em.post_id = p.ID AND em.meta_key = '_anchor_event_email'
			LEFT JOIN {$pm} nm ON nm.post_id = p.ID AND nm.meta_key = '_anchor_event_name'
			LEFT JOIN {$pm} us ON us.post_id = p.ID AND us.meta_key = '_anchor_event_user_id'
			LEFT JOIN {$pm} st ON st.post_id = p.ID AND st.meta_key = '_anchor_event_reg_status'
			LEFT JOIN {$pm} ev ON ev.post_id = p.ID AND ev.meta_key = '_anchor_event_id'
			WHERE p.post_type = %s AND p.post_status = 'publish'";
		$args = [ self::SEAT_CPT ];
		if ( ! $all ) {
			$statuses = \array_values( \array_filter( \array_map( 'sanitize_key', (array) ( $params['statuses'] ?? [] ) ) ) );
			$statuses = $statuses ? $statuses : [ 'confirmed' ];
			$sql     .= ' AND st.meta_value IN (' . \implode( ',', \array_fill( 0, \count( $statuses ), '%s' ) ) . ')';
			$args     = \array_merge( $args, $statuses );
			$events   = \array_values( \array_filter( \array_map( 'absint', (array) ( $params['events'] ?? [] ) ) ) );
			if ( $events ) {
				$sql .= ' AND CAST(ev.meta_value AS UNSIGNED) IN (' . \implode( ',', \array_fill( 0, \count( $events ), '%d' ) ) . ')';
				$args = \array_merge( $args, $events );
			}
			[ $from, $to ] = Dates::gmt_range( (string) ( $params['from'] ?? '' ), (string) ( $params['to'] ?? '' ) );
			if ( $from ) { $sql .= ' AND p.post_date_gmt >= %s'; $args[] = $from; }
			if ( $to ) { $sql .= ' AND p.post_date_gmt <= %s'; $args[] = $to; }
		}
		$set = new RecipientSet();
		foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
			$u = (int) $r->user_id > 0 ? \get_userdata( (int) $r->user_id ) : false;
			$u ? $set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name ) : $set->add( (string) $r->email, 0, (string) $r->name );
		}
		return $set;
	}
}
