<?php
declare(strict_types=1);

namespace Anchor\Agreements\Database;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Versioned schema. Anchor Tools has no per-module activation hook, so this runs
 * from Module::__construct() and again on admin_init (same as anchor-courses).
 * dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one key per line.
 */
final class Migrations {

	public const DB_VERSION = '1.0.0';
	public const OPTION     = 'anchor_agreements_db_version';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'anchor_agreement_' . $name;
	}

	public static function maybe_migrate(): void {
		if ( (string) \get_option( self::OPTION, '' ) === self::DB_VERSION ) {
			return;
		}
		self::run();
	}

	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate    = $wpdb->get_charset_collate();
		$versions   = self::table( 'versions' );
		$signatures = self::table( 'signatures' );

		\dbDelta( "CREATE TABLE {$versions} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  agreement_id BIGINT(20) UNSIGNED NOT NULL,
  content_hash CHAR(64) NOT NULL,
  title TEXT NOT NULL,
  content LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY agreement_hash (agreement_id,content_hash)
) {$collate};" );

		\dbDelta( "CREATE TABLE {$signatures} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  token CHAR(36) NOT NULL,
  version_id BIGINT(20) UNSIGNED NOT NULL,
  agreement_id BIGINT(20) UNSIGNED NOT NULL,
  order_id BIGINT(20) UNSIGNED DEFAULT NULL,
  product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  user_id BIGINT(20) UNSIGNED DEFAULT NULL,
  signer_name VARCHAR(120) NOT NULL,
  signer_email VARCHAR(190) NOT NULL DEFAULT '',
  method VARCHAR(10) NOT NULL,
  font VARCHAR(40) DEFAULT NULL,
  image MEDIUMBLOB NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  session_key VARCHAR(64) NOT NULL DEFAULT '',
  signed_at DATETIME NOT NULL,
  attached_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY order_id (order_id),
  KEY agreement_id (agreement_id),
  KEY user_id (user_id),
  KEY session_key (session_key),
  KEY signed_at (signed_at)
) {$collate};" );

		\update_option( self::OPTION, self::DB_VERSION, false );
	}
}
