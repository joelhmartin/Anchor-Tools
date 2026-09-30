<?php
declare(strict_types=1);

namespace Anchor\Announcements\Database;

use Anchor\Announcements\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Tables and the send capability, created or upgraded when the stored version is behind. */
final class Migrations {

	public const VERSION = '1';
	public const OPTION  = 'anchor_announcements_db_version';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'anchor_announce_' . $name;
	}

	public static function maybe_migrate(): void {
		if ( self::VERSION === (string) \get_option( self::OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		\dbDelta(
			'CREATE TABLE ' . self::table( 'sends' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				announcement_id BIGINT UNSIGNED NOT NULL,
				email VARCHAR(191) NOT NULL,
				user_id BIGINT UNSIGNED NULL,
				name VARCHAR(191) NOT NULL DEFAULT '',
				token CHAR(32) NOT NULL,
				status VARCHAR(20) NOT NULL,
				skip_reason VARCHAR(40) NOT NULL DEFAULT '',
				error TEXT NULL,
				attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
				queued_at DATETIME NOT NULL,
				claimed_at DATETIME NULL,
				sent_at DATETIME NULL,
				first_opened_at DATETIME NULL,
				open_count INT UNSIGNED NOT NULL DEFAULT 0,
				first_clicked_at DATETIME NULL,
				click_count INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY announcement_email (announcement_id,email),
				UNIQUE KEY token (token),
				KEY status_queued (status,queued_at)
			) $charset;"
		);
		\dbDelta(
			'CREATE TABLE ' . self::table( 'events' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				send_id BIGINT UNSIGNED NOT NULL,
				type VARCHAR(20) NOT NULL,
				link_index SMALLINT UNSIGNED NULL,
				scanner TINYINT(1) NOT NULL DEFAULT 0,
				user_agent VARCHAR(255) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY send_type (send_id,type)
			) $charset;"
		);
		\dbDelta(
			'CREATE TABLE ' . self::table( 'suppressions' ) . " (
				email VARCHAR(191) NOT NULL,
				reason VARCHAR(20) NOT NULL,
				source_announcement_id BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (email)
			) $charset;"
		);

		$admin = \get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( Module::CAP );
		}
		\update_option( self::OPTION, self::VERSION, true ); // Read on every request: keep it autoloaded.
	}
}
