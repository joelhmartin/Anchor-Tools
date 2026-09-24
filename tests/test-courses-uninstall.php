<?php
/**
 * Anchor Courses - uninstall data policy (brief rule 9, design spec section 4).
 *
 * Deactivation and a default uninstall keep learner data. Only the explicit
 * `anchor_courses_delete_data_on_uninstall` flag drops the tables.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\Migrations;

/** @group courses */
class Test_Courses_Uninstall extends Anchor_Courses_TestCase {

	/**
	 * WP_UnitTestCase wraps every test in a transaction and rewrites
	 * CREATE/DROP TABLE into CREATE/DROP TEMPORARY TABLE so schema changes
	 * roll back with everything else. Our tables were installed as real
	 * (non-temporary) tables by the plugin bootstrap, which runs before any
	 * test's transaction exists - so that rewrite would silently no-op our
	 * DROP TABLE (nothing temporary exists to drop) and leave the real table
	 * standing. Remove the two rewriting filters so DDL here behaves exactly
	 * as it will in a real uninstall.
	 */
	private function allow_real_ddl(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down() {
		// Real DROP TABLE is DDL, not transactional, so it (and the recreate
		// below) run outside - and survive - the per-test rollback. Recreate
		// for real so later tests still see the tables.
		$this->allow_real_ddl();
		delete_option( Migrations::OPTION );
		Migrations::run();
		parent::tear_down();
	}

	private function table_exists( string $name ): bool {
		global $wpdb;
		$table = Migrations::table( $name );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function test_drop_helper_removes_every_courses_table() {
		global $wpdb;
		require_once ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses/uninstall-tables.php';
		$this->allow_real_ddl();

		$dropped = anchor_courses_drop_tables( $wpdb );

		$this->assertCount( 5, $dropped );
		foreach ( Migrations::TABLES as $name ) {
			$this->assertFalse( $this->table_exists( $name ), "{$name} should have been dropped" );
		}
	}

	public function test_drop_helper_touches_no_other_table() {
		global $wpdb;
		require_once ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses/uninstall-tables.php';
		$this->allow_real_ddl();

		anchor_courses_drop_tables( $wpdb );

		$this->assertSame(
			$wpdb->posts,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->posts ) ),
			'The drop helper must only touch anchor_courses_* tables.'
		);
	}

	public function test_uninstall_file_gates_the_drop_behind_the_opt_in_option() {
		$source = file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'uninstall.php' );
		$this->assertStringContainsString( "get_option( 'anchor_courses_delete_data_on_uninstall'", $source );
		$this->assertStringContainsString( 'anchor_courses_drop_tables', $source );
		$this->assertStringNotContainsString(
			'DROP TABLE IF EXISTS {$wpdb->prefix}anchor_courses_',
			$source,
			'The courses drop must go through the guarded helper, never an inline unconditional DROP.'
		);
	}
}
