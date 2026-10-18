<?php
/**
 * Shared base case for the Library suite.
 *
 * The library tables are real tables, not the per-test TEMPORARY ones WP_UnitTestCase would substitute:
 * Schema checks information_schema (which cannot see temporary tables) and dbDelta needs real DDL. They are
 * created once and survive across tests; rows are rolled back by the normal per-test transaction.
 *
 * @package Anchor\Library\Tests
 */

use Anchor\Library\Schema;

abstract class Anchor_Library_TestCase extends WP_UnitTestCase {

	/** Options the module writes outside the transaction when a test runs DDL (DDL implicitly commits). */
	private const OPTIONS = [
		'anchor_fm_db_version', 'anchor_fm_pd_folder_id', 'anchor_fm_email_on_upload', 'anchor_fm_request_access_email',
		'anchor_fm_portal_logo', 'anchor_fm_storage_check',
	];

	public function set_up() {
		parent::set_up();
		require_once ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/anchor-library.php';
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		$this->ensure_tables();
	}

	public function tear_down() {
		wp_clear_scheduled_hook( Schema::CRON_PRUNE_RESUME );
		wp_clear_scheduled_hook( Schema::CRON_STORAGE_CHECK );
		parent::tear_down();
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	protected function ensure_tables(): void {
		global $wpdb;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( 'folders' ) ) ) !== Schema::table( 'folders' ) ) {
			Schema::create_tables(); // DDL commits; autocommit stays off, so the next statement opens a fresh transaction.
		}
	}

	protected function drop_all_tables(): void {
		global $wpdb;
		foreach ( Schema::TABLES as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table( $name ) ); // phpcs:ignore
		}
	}

	protected function table_exists( string $name ): bool {
		global $wpdb;
		return Schema::table( $name ) === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( $name ) ) );
	}
}
