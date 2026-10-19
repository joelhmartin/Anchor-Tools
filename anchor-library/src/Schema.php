<?php
/**
 * Library database: table names, the install (the plugin's activate() schema work) and the version-gated upgrader
 * (the plugin's maybe_upgrade_db()). Same tables, options and cron hooks as the Anchor Private File Manager
 * plugin, so enabling the module on a site that ran the plugin adopts its data untouched.
 *
 * Ported from Anchor Private File Manager 2.17.0; the pin is DB_VERSION.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Schema {

	/**
	 * Version stamped into anchor_fm_db_version. Equal to the plugin's VERSION at the source pin (2.17.0, which has
	 * no schema change over 2.16.x: the last real DB steps are 2.15.0's host pins and 2.12.0's coverage columns).
	 */
	public const DB_VERSION = '2.17.0';

	public const OPT_DB_VERSION            = 'anchor_fm_db_version';
	public const OPT_EMAIL_ON_UPLOAD       = 'anchor_fm_email_on_upload';
	public const OPT_PD_FOLDER_ID          = 'anchor_fm_pd_folder_id';
	public const OPT_REQUEST_ACCESS_EMAIL  = 'anchor_fm_request_access_email';
	public const OPT_PORTAL_LOGO           = 'anchor_fm_portal_logo';
	public const OPT_VIMEO_TOKEN           = 'anchor_fm_vimeo_token';
	/** Keys of the roles created from the Users tab: the only ones it may rename or delete. */
	public const OPT_PORTAL_ROLES          = 'anchor_fm_portal_roles';
	/** Last result of StorageGuard::check(). */
	public const OPT_STORAGE_CHECK         = 'anchor_fm_storage_check';

	public const CRON_PRUNE_RESUME  = 'anchor_fm_prune_resume';
	public const CRON_STORAGE_CHECK = 'anchor_fm_storage_check';

	/**
	 * International ran on this hardcoded default before it became a setting. Only the 2.15.0 upgrade uses it, to
	 * pin that behavior on existing installs.
	 */
	public const LEGACY_REQUEST_ACCESS_EMAIL = 'tiffany@tmjtherapycentre.com';

	/** International's hardcoded favicon before the logo became a setting. Only the 2.15.0 upgrade uses it. */
	public const LEGACY_PORTAL_LOGO = 'https://tmjtherapycentre.com/wp-content/uploads/2023/02/TMJ_INT_Favicon_96x96.png';

	/** Table suffixes. (The plugin dropped its comments table in 2.1; it is not part of a new install.) */
	public const TABLES = [ 'folders', 'files', 'permissions', 'permission_policies', 'activity', 'links', 'videos', 'video_views' ];

	/** {prefix}anchor_fm_{name}. */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'anchor_fm_' . $name;
	}

	/**
	 * Everything the plugin's activate() did: tables, default option, store directory, Product Docs folder, and the
	 * version stamp. Modules have no activation hook, so maybe_upgrade() calls this for a site with no stamp.
	 *
	 * @return bool Whether the schema landed and the version was stamped.
	 */
	public static function install(): bool {
		if ( \get_option( self::OPT_EMAIL_ON_UPLOAD, null ) === null ) {
			// Disabled by default; keep notification logic available for later.
			\add_option( self::OPT_EMAIL_ON_UPLOAD, 0 );
		}

		$ok = self::create_tables();

		Storage::ensure();
		self::ensure_product_docs_folder();
		// Stamp only after the schema work, and only if it succeeded (see maybe_upgrade()). A premature bump strands
		// the site on the legacy (video_id, user_id) unique key with no retry.
		if ( $ok ) {
			\update_option( self::OPT_DB_VERSION, self::DB_VERSION );
		}
		return $ok;
	}

	/**
	 * Create or repair every table via dbDelta.
	 *
	 * @return bool True when the policies table exists and the video_views key and coverage columns all landed.
	 */
	public static function create_tables(): bool {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$folders  = self::table( 'folders' );
		$files    = self::table( 'files' );
		$perms    = self::table( 'permissions' );
		$activity = self::table( 'activity' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		\dbDelta( "
			CREATE TABLE {$folders} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				parent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				name VARCHAR(190) NOT NULL,
				owner_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				is_private TINYINT(1) NOT NULL DEFAULT 0,
				created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY parent_id (parent_id),
				KEY owner_user_id (owner_user_id)
			) {$charset_collate};
		" );

		\dbDelta( "
			CREATE TABLE {$files} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				folder_id BIGINT(20) UNSIGNED NOT NULL,
				original_name VARCHAR(255) NOT NULL,
				stored_name VARCHAR(255) NOT NULL,
				mime_type VARCHAR(120) NOT NULL,
				size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				sha1 CHAR(40) NULL,
				uploader_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY folder_id (folder_id),
				KEY uploader_user_id (uploader_user_id)
			) {$charset_collate};
		" );

		\dbDelta( "
			CREATE TABLE {$perms} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				entity_type VARCHAR(10) NOT NULL,
				entity_id BIGINT(20) UNSIGNED NOT NULL,
				subject_type VARCHAR(10) NOT NULL,
				subject_key VARCHAR(191) NOT NULL,
				capability VARCHAR(10) NOT NULL,
				created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY entity (entity_type, entity_id),
				KEY subject (subject_type, subject_key),
				KEY capability (capability)
			) {$charset_collate};
		" );

		\dbDelta( "
			CREATE TABLE {$activity} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				actor_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				action VARCHAR(40) NOT NULL,
				entity_type VARCHAR(10) NOT NULL,
				entity_id BIGINT(20) UNSIGNED NOT NULL,
				meta LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY actor_user_id (actor_user_id),
				KEY entity (entity_type, entity_id),
				KEY created_at (created_at)
			) {$charset_collate};
		" );

		self::ensure_links_table();
		$policies_ok = self::ensure_permission_policies_table();
		return $policies_ok && self::ensure_videos_table();
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(1) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s',
			$table
		) ) > 0;
	}

	private static function ensure_permission_policies_table(): bool {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$policies        = self::table( 'permission_policies' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		\dbDelta( "
			CREATE TABLE {$policies} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				entity_type VARCHAR(10) NOT NULL,
				entity_id BIGINT(20) UNSIGNED NOT NULL,
				capability VARCHAR(10) NOT NULL,
				policy LONGTEXT NULL,
				created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY entity_capability (entity_type, entity_id, capability),
				KEY entity (entity_type, entity_id),
				KEY capability (capability)
			) {$charset_collate};
		" );

		return self::table_exists( $policies );
	}

	/** The hidden folder that holds product documents. Created once; its id lives in an option. */
	public static function ensure_product_docs_folder(): int {
		$folder_id = (int) \get_option( self::OPT_PD_FOLDER_ID, 0 );
		if ( $folder_id > 0 ) {
			return $folder_id;
		}

		global $wpdb;
		$now = \current_time( 'mysql' );
		$wpdb->insert( self::table( 'folders' ), [
			'parent_id'     => 0,
			'name'          => 'Product Docs',
			'owner_user_id' => 0,
			'is_private'    => 0,
			'created_by'    => \get_current_user_id() ?: 0,
			'created_at'    => $now,
			'updated_at'    => $now,
		] );
		$folder_id = (int) $wpdb->insert_id;
		\update_option( self::OPT_PD_FOLDER_ID, $folder_id );
		return $folder_id;
	}

	private static function ensure_links_table(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$links           = self::table( 'links' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		\dbDelta( "
			CREATE TABLE {$links} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				folder_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				title VARCHAR(255) NOT NULL,
				url TEXT NOT NULL,
				created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY folder_id (folder_id)
			) {$charset_collate};
		" );
	}

	/**
	 * Whether a named index currently exists on the video_views table. Used both to gate dropping the legacy key and
	 * to decide whether the stored db version may advance.
	 */
	private static function views_index_exists( string $index_name ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
			self::table( 'video_views' ),
			$index_name
		) ) > 0;
	}

	/**
	 * Whether a named column currently exists on the video_views table. The index check alone cannot gate the 2.12.0
	 * upgrade: source_video_user is already present on every 2.11.x site, so it proves nothing about the columns
	 * coverage tracking needs.
	 */
	private static function views_column_exists( string $column ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(1) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
			self::table( 'video_views' ),
			$column
		) ) > 0;
	}

	/**
	 * @return bool True when the (source, video_id, user_id) unique key AND the coverage columns all exist after this
	 *              call. False means dbDelta did not manage to apply the schema on this host, and the caller MUST NOT
	 *              record the upgrade as done: otherwise the migration never retries, every file-source progress
	 *              write collides with the legacy key, and every heartbeat 500s against the missing coverage columns.
	 */
	private static function ensure_videos_table(): bool {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$videos          = self::table( 'videos' );
		$views           = self::table( 'video_views' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		\dbDelta( "
			CREATE TABLE {$videos} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				folder_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				vimeo_id VARCHAR(32) NOT NULL,
				vimeo_hash VARCHAR(64) NOT NULL DEFAULT '',
				title VARCHAR(255) NOT NULL,
				thumbnail_url VARCHAR(255) NOT NULL DEFAULT '',
				created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY folder_id (folder_id)
			) {$charset_collate};
		" );

		\dbDelta( "
			CREATE TABLE {$views} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				source VARCHAR(10) NOT NULL DEFAULT 'vimeo',
				video_id BIGINT(20) UNSIGNED NOT NULL,
				user_id BIGINT(20) UNSIGNED NOT NULL,
				furthest_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
				total_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
				resume_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
				watched_bits MEDIUMBLOB NULL,
				duration_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
				percent TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
				sessions INT(10) UNSIGNED NOT NULL DEFAULT 0,
				first_viewed_at DATETIME NOT NULL,
				last_viewed_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_video_user (source, video_id, user_id),
				KEY video_id (video_id),
				KEY user_id (user_id)
			) {$charset_collate};
		" );

		// Order matters: drop_legacy_views_key() first, so the legacy key is never dropped before its replacement
		// exists, then confirm the coverage columns actually landed.
		$key_ok = self::drop_legacy_views_key();

		return $key_ok
			&& self::views_column_exists( 'watched_bits' )
			&& self::views_column_exists( 'duration_seconds' );
	}

	/**
	 * Drop the pre-2.11.0 UNIQUE KEY (video_id, user_id). It is replaced by (source, video_id, user_id); leaving it
	 * in place would wrongly forbid a file row and a Vimeo row that happen to share an id. Runs only after dbDelta
	 * has created the replacement, so the table is never left without a uniqueness guard. Idempotent.
	 *
	 * @return bool Whether source_video_user exists, i.e. whether the schema migration actually landed.
	 */
	private static function drop_legacy_views_key(): bool {
		global $wpdb;
		$views = self::table( 'video_views' );

		if ( ! self::views_index_exists( 'source_video_user' ) ) {
			return false; // Replacement missing: leave the old key alone.
		}
		if ( self::views_index_exists( 'video_user' ) ) {
			$wpdb->query( "ALTER TABLE {$views} DROP INDEX video_user" ); // phpcs:ignore WordPress.DB
		}
		return true;
	}

	/**
	 * Clear pre-coverage watch percentages.
	 *
	 * Before 2.12.0 `percent` measured the furthest point the scrubber reached, and `total_seconds` counted time
	 * elapsed in the player. Neither can be converted into "which seconds were actually played", so both are reset
	 * rather than carried forward as an invented number. Everyone reads 0% until they watch again: intended, and
	 * chosen deliberately over seeding coverage as 0..furthest_seconds, which would fabricate data.
	 * resume_seconds, furthest_seconds, sessions and the timestamps are left untouched.
	 *
	 * @return bool False when the UPDATE failed (an unknown column on a host where dbDelta did not apply cleanly).
	 *              The caller must not bump the version on a false, or the reset never retries.
	 */
	private static function reset_pre_coverage_watch_stats(): bool {
		global $wpdb;
		$views  = self::table( 'video_views' );
		$result = $wpdb->query( "UPDATE {$views} SET percent = 0, total_seconds = 0, watched_bits = NULL" ); // phpcs:ignore WordPress.DB
		// query() returns int 0 when no rows matched (an empty table), which is success. Only an exact false fails.
		return false !== $result;
	}

	/**
	 * True only for International's own host (production or a staging copy that still resolves under its domain).
	 * Gates the 2.15.0 pin so it touches nobody else's site, even other installs that ran pre-2.15 releases.
	 */
	public static function is_legacy_international_host(): bool {
		$host   = \strtolower( (string) \wp_parse_url( \home_url(), PHP_URL_HOST ) );
		$suffix = '.tmjtherapycentre.com';
		return 'tmjtherapycentre.com' === $host || \substr( $host, -\strlen( $suffix ) ) === $suffix;
	}

	/**
	 * Bring an existing database up to DB_VERSION. Runs from Module::boot() on every request and is a no-op once the
	 * stamp is current, so the 2.15.0 pins and the 2.12.0 reset each run at most once.
	 *
	 * Live TMJ is at 2.14.0 (it ran the plugin). Its first module load takes the "installed < DB_VERSION" branch:
	 * the 2.15.0 host-gated pins are written (International's access-request recipient and logo, only when those
	 * options are unset and only on a tmjtherapycentre.com host), the links/policies/videos tables are re-checked,
	 * and the stamp moves 2.14.0 -> 2.17.0. 2.14.0 is past 2.12.0, so watch stats are NOT reset.
	 *
	 * A site with no stamp at all is a fresh install, which the plugin handled in activate() and the module has no
	 * activation hook for: install() does that work and the 2.15.0 pins never apply to it.
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) \get_option( self::OPT_DB_VERSION, '0' );
		if ( ! \version_compare( $installed, self::DB_VERSION, '<' ) ) {
			return;
		}
		if ( '0' === $installed ) {
			self::install();
			return;
		}

		// Whether this site predates coverage tracking. Must be captured before the option is bumped, applied once.
		$pre_coverage = \version_compare( $installed, '2.12.0', '<' );

		// International (and only International, identified by host) ran on hardcoded pre-2.15 defaults for the
		// access-request recipient and the sidebar logo. This pins both to their old values so the 2.15.0 upgrade
		// doesn't silently reroute requests to the site admin or swap the logo out from under them. It is host-gated
		// rather than "any install below 2.15.0" because other sites may also have run 2.14 or earlier and must NOT
		// inherit International's hardcoded values: they only get the new admin-email / site-icon defaults.
		if ( \version_compare( $installed, '2.15.0', '<' ) && self::is_legacy_international_host() ) {
			if ( \get_option( self::OPT_REQUEST_ACCESS_EMAIL, null ) === null ) {
				\update_option( self::OPT_REQUEST_ACCESS_EMAIL, self::LEGACY_REQUEST_ACCESS_EMAIL );
			}
			if ( \get_option( self::OPT_PORTAL_LOGO, null ) === null && '' === \get_site_icon_url( 96 ) ) {
				\update_option( self::OPT_PORTAL_LOGO, self::LEGACY_PORTAL_LOGO );
			}
		}

		self::ensure_links_table();
		$policies_ok = self::ensure_permission_policies_table();
		$views_ok    = self::ensure_videos_table();

		if ( $views_ok && $pre_coverage ) {
			$views_ok = self::reset_pre_coverage_watch_stats();
		}
		if ( $views_ok && $policies_ok ) {
			\update_option( self::OPT_DB_VERSION, self::DB_VERSION );
		}
	}

	/** Daily prune and storage-check events, scheduled if missing (the plugin did this in its constructor). */
	public static function schedule_events(): void {
		if ( ! \wp_next_scheduled( self::CRON_PRUNE_RESUME ) ) {
			\wp_schedule_event( \time() + HOUR_IN_SECONDS, 'daily', self::CRON_PRUNE_RESUME );
		}
		if ( ! \wp_next_scheduled( self::CRON_STORAGE_CHECK ) ) {
			// First run soon, so a site learns its exposure the day it is switched on.
			\wp_schedule_event( \time() + MINUTE_IN_SECONDS, 'daily', self::CRON_STORAGE_CHECK );
		}
	}

	/** Unschedule both events (the plugin's deactivate()). */
	public static function clear_events(): void {
		\wp_clear_scheduled_hook( self::CRON_PRUNE_RESUME );
		\wp_clear_scheduled_hook( self::CRON_STORAGE_CHECK );
	}
}
