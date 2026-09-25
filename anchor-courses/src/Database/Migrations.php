<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Versioned schema for the five learner tables (brief section 7).
 *
 * Anchor Tools has no per-module activation hook - anchor_tools_bootstrap_modules()
 * only requires the file and instantiates the class on plugins_loaded:25 - so this
 * runs from the Module constructor AND on admin_init (brief section 28). dbDelta is
 * whitespace- and case-sensitive: two spaces after PRIMARY KEY, one index per
 * line, uppercase types. Do not reformat the SQL below.
 */
final class Migrations {

	public const DB_VERSION = '1.1.0';
	public const OPTION     = 'anchor_courses_db_version';

	public const TABLES = [ 'enrollments', 'progress', 'quiz_attempts', 'ce_credits', 'certificates' ];

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'anchor_courses_' . $name;
	}

	public static function installed_version(): string {
		return (string) \get_option( self::OPTION, '' );
	}

	public static function maybe_migrate(): void {
		if ( self::installed_version() === self::DB_VERSION ) {
			return;
		}
		self::run();
	}

	public static function run(): void {
		$from = self::installed_version();

		if ( \version_compare( $from, '1.0.0', '<' ) ) {
			self::migrate_1_0_0();
		}

		if ( \version_compare( $from, '1.1.0', '<' ) ) {
			self::migrate_1_1_0();
		}

		\update_option( self::OPTION, self::DB_VERSION, false );
	}

	/** 1.0.0 - initial schema. */
	private static function migrate_1_0_0(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		\dbDelta(
			"CREATE TABLE " . self::table( 'enrollments' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				course_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(30) NOT NULL,
				enrolled_at DATETIME NOT NULL,
				started_at DATETIME NULL,
				completed_at DATETIME NULL,
				expires_at DATETIME NULL,
				source VARCHAR(100) NULL,
				source_id VARCHAR(191) NULL,
				metadata LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_course (user_id,course_id),
				KEY course_status (course_id,status),
				KEY user_status (user_id,status)
			) {$charset};"
		);

		\dbDelta(
			"CREATE TABLE " . self::table( 'progress' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				course_id BIGINT UNSIGNED NOT NULL,
				item_id BIGINT UNSIGNED NOT NULL,
				item_type VARCHAR(20) NOT NULL,
				status VARCHAR(30) NOT NULL,
				progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
				started_at DATETIME NULL,
				completed_at DATETIME NULL,
				last_viewed_at DATETIME NULL,
				time_spent_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
				metadata LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_course_item (user_id,course_id,item_id,item_type),
				KEY course_item (course_id,item_id),
				KEY user_course_status (user_id,course_id,status)
			) {$charset};"
		);

		\dbDelta(
			"CREATE TABLE " . self::table( 'quiz_attempts' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				course_id BIGINT UNSIGNED NOT NULL,
				quiz_id BIGINT UNSIGNED NOT NULL,
				attempt_number INT UNSIGNED NOT NULL,
				status VARCHAR(30) NOT NULL,
				score DECIMAL(6,2) NULL,
				points_earned DECIMAL(8,2) NULL,
				points_possible DECIMAL(8,2) NULL,
				passed TINYINT(1) NOT NULL DEFAULT 0,
				started_at DATETIME NOT NULL,
				submitted_at DATETIME NULL,
				duration_seconds INT UNSIGNED NULL,
				answers LONGTEXT NULL,
				grading_data LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_quiz_attempt (user_id,quiz_id,attempt_number),
				KEY quiz_status (quiz_id,status),
				KEY user_course (user_id,course_id)
			) {$charset};"
		);

		\dbDelta(
			"CREATE TABLE " . self::table( 'ce_credits' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				course_id BIGINT UNSIGNED NOT NULL,
				credits DECIMAL(6,2) NOT NULL,
				credit_type VARCHAR(100) NULL,
				awarded_at DATETIME NOT NULL,
				expires_at DATETIME NULL,
				certificate_id BIGINT UNSIGNED NULL,
				metadata LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_course (user_id,course_id),
				KEY course_awarded (course_id,awarded_at)
			) {$charset};"
		);

		\dbDelta(
			"CREATE TABLE " . self::table( 'certificates' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				course_id BIGINT UNSIGNED NOT NULL,
				certificate_number VARCHAR(100) NOT NULL,
				issued_at DATETIME NOT NULL,
				expires_at DATETIME NULL,
				file_path TEXT NULL,
				verification_token VARCHAR(191) NULL,
				metadata LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_course (user_id,course_id),
				UNIQUE KEY certificate_number (certificate_number),
				KEY verification_token (verification_token)
			) {$charset};"
		);

		// Brief section 24: map the eight capabilities to administrator on first
		// install. Added by Task 3; the call site lives here so one migration owns
		// the whole 1.0.0 installation.
		if ( \class_exists( Capabilities::class ) ) {
			Capabilities::sync();
		}
	}

	/**
	 * 1.1.0 - quiz_attempts gains `metadata` (Task 25, ruling R3): the
	 * time_limit_seconds and on_timer_expiry policy in force when an attempt
	 * starts are pinned here, so a later settings edit can never move a
	 * running deadline. dbDelta is given the table's full definition and
	 * ALTERs in only what is missing - do not reformat the SQL below.
	 */
	private static function migrate_1_1_0(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		\dbDelta(
			"CREATE TABLE " . self::table( 'quiz_attempts' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				course_id BIGINT UNSIGNED NOT NULL,
				quiz_id BIGINT UNSIGNED NOT NULL,
				attempt_number INT UNSIGNED NOT NULL,
				status VARCHAR(30) NOT NULL,
				score DECIMAL(6,2) NULL,
				points_earned DECIMAL(8,2) NULL,
				points_possible DECIMAL(8,2) NULL,
				passed TINYINT(1) NOT NULL DEFAULT 0,
				started_at DATETIME NOT NULL,
				submitted_at DATETIME NULL,
				duration_seconds INT UNSIGNED NULL,
				answers LONGTEXT NULL,
				grading_data LONGTEXT NULL,
				metadata LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_quiz_attempt (user_id,quiz_id,attempt_number),
				KEY quiz_status (quiz_id,status),
				KEY user_course (user_id,course_id)
			) {$charset};"
		);
	}
}
