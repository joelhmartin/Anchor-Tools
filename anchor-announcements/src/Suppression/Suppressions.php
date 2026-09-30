<?php
declare(strict_types=1);

namespace Anchor\Announcements\Suppression;

use Anchor\Announcements\Database\Migrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Addresses that must never be sent an announcement (unsubscribed, bounced, complained, manual). */
final class Suppressions {

	public const REASONS = [ 'unsubscribed', 'bounced', 'complained', 'manual' ];

	public static function is_suppressed( string $email ): bool {
		global $wpdb;
		$t = Migrations::table( 'suppressions' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE email = %s", \strtolower( \trim( $email ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
	}

	public static function add( string $email, string $reason, int $announcement_id = 0 ): void {
		global $wpdb;
		$email = \strtolower( \trim( $email ) );
		if ( ! \is_email( $email ) ) {
			return;
		}
		$reason = \in_array( $reason, self::REASONS, true ) ? $reason : 'manual';
		$t      = Migrations::table( 'suppressions' );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (email, reason, source_announcement_id, created_at) VALUES (%s, %s, %d, %s)", $email, $reason, $announcement_id, \current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function remove( string $email ): void {
		global $wpdb;
		$wpdb->delete( Migrations::table( 'suppressions' ), [ 'email' => \strtolower( \trim( $email ) ) ] );
	}

	/** @return array{rows:list<array>,total:int} */
	public static function list( string $search = '', int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$t     = Migrations::table( 'suppressions' );
		$where = '' !== $search ? $wpdb->prepare( 'WHERE email LIKE %s', '%' . $wpdb->esc_like( \strtolower( $search ) ) . '%' ) : '';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, \max( 0, $page - 1 ) * $per_page ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [ 'rows' => $rows, 'total' => $total ];
	}
}
