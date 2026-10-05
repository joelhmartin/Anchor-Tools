<?php
declare(strict_types=1);

namespace Anchor\Shipping\Database;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The shipments table, created or upgraded when the stored version is behind. */
final class Migrations {

	public const VERSION = '1';
	public const OPTION  = 'anchor_shipping_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'anchor_shipments';
	}

	public static function maybe_migrate(): void {
		if ( self::VERSION === (string) \get_option( self::OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		\dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT UNSIGNED NOT NULL,
				carrier VARCHAR(32) NOT NULL,
				service VARCHAR(32) NOT NULL DEFAULT '',
				shipment_id VARCHAR(64) NOT NULL DEFAULT '',
				tracking_number VARCHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'label_created',
				status_detail VARCHAR(255) NOT NULL DEFAULT '',
				last_event_at DATETIME NULL,
				delivered_at DATETIME NULL,
				cost DECIMAL(10,2) NULL,
				currency CHAR(3) NOT NULL DEFAULT '',
				declared_value DECIMAL(10,2) NOT NULL DEFAULT 0,
				signature TINYINT(1) NOT NULL DEFAULT 0,
				label_path VARCHAR(255) NOT NULL DEFAULT '',
				label_format VARCHAR(8) NOT NULL DEFAULT '',
				box VARCHAR(64) NOT NULL DEFAULT '',
				weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
				dims_cm VARCHAR(64) NOT NULL DEFAULT '',
				source VARCHAR(10) NOT NULL DEFAULT 'manual',
				created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				voided_at DATETIME NULL,
				last_polled_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY tracking_number (tracking_number),
				KEY status (status),
				KEY carrier_shipment (carrier,shipment_id)
			) $charset;"
		);
		// Only record the version once the table exists, so a failed migration retries.
		if ( self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) ) {
			\update_option( self::OPTION, self::VERSION, true ); // autoloaded: the per-request version check costs no query
		}
	}
}
