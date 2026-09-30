<?php
declare(strict_types=1);

namespace Anchor\Announcements\Privacy;

use Anchor\Announcements\Database\Migrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Tools > Export / Erase Personal Data support. An opt-out is kept on erase (so it keeps working). */
final class Privacy {

	public function __construct() {
		\add_filter( 'wp_privacy_personal_data_exporters', static function ( $e ) { $e['anchor-announcements'] = [ 'exporter_friendly_name' => \__( 'Announcements', 'anchor-schema' ), 'callback' => [ self::class, 'export' ] ]; return $e; } );
		\add_filter( 'wp_privacy_personal_data_erasers', static function ( $e ) { $e['anchor-announcements'] = [ 'eraser_friendly_name' => \__( 'Announcements', 'anchor-schema' ), 'callback' => [ self::class, 'erase' ] ]; return $e; } );
	}

	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$email = \strtolower( \trim( $email ) );
		$data  = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'sends' ) . ' WHERE email = %s', $email ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$data[] = [
				'group_id'    => 'anchor-announcements',
				'group_label' => \__( 'Announcements received', 'anchor-schema' ),
				'item_id'     => 'aa-send-' . $r->id,
				'data'        => [
					[ 'name' => \__( 'Announcement', 'anchor-schema' ), 'value' => \get_the_title( (int) $r->announcement_id ) ],
					[ 'name' => \__( 'Status', 'anchor-schema' ), 'value' => $r->status ],
					[ 'name' => \__( 'Sent', 'anchor-schema' ), 'value' => (string) $r->sent_at ],
					[ 'name' => \__( 'Opens', 'anchor-schema' ), 'value' => (string) $r->open_count ],
					[ 'name' => \__( 'Clicks', 'anchor-schema' ), 'value' => (string) $r->click_count ],
				],
			];
		}
		$sup = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'suppressions' ) . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $sup ) {
			$data[] = [ 'group_id' => 'anchor-announcements-optout', 'group_label' => \__( 'Announcement opt-out', 'anchor-schema' ), 'item_id' => 'aa-optout', 'data' => [ [ 'name' => \__( 'Reason', 'anchor-schema' ), 'value' => $sup->reason ], [ 'name' => \__( 'Since', 'anchor-schema' ), 'value' => $sup->created_at ] ] ];
		}
		return [ 'data' => $data, 'done' => true ];
	}

	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$email = \strtolower( \trim( $email ) );
		$sends = Migrations::table( 'sends' );
		$ids   = \array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$sends} WHERE email = %s", $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $ids ) {
			$wpdb->query( 'DELETE FROM ' . Migrations::table( 'events' ) . ' WHERE send_id IN (' . \implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ints.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$sends} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$kept = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Migrations::table( 'suppressions' ) . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return [
			'items_removed'  => (bool) $ids,
			'items_retained' => $kept,
			'messages'       => $kept ? [ \__( 'The announcement opt-out for this address was kept so it is never emailed again.', 'anchor-schema' ) ] : [],
			'done'           => true,
		];
	}
}
