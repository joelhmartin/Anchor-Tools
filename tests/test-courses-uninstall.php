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
use Anchor\Courses\Support\Capabilities;

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
	 *
	 * Caveat for anyone adding fixture-creating tests to this class: once
	 * called, every CREATE/DROP TABLE for the rest of THIS test runs as real,
	 * implicit-commit DDL that survives the tear_down() ROLLBACK - it is not
	 * undone when the test ends, only overwritten by whatever tear_down()
	 * does next (see below).
	 */
	private function allow_real_ddl(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Define the constant uninstall.php's own top guard requires, so `require`ing
	 * it here doesn't `exit` the test process.
	 *
	 * `@runInSeparateProcess` (the usual way to isolate a process-wide constant)
	 * is not viable in this suite - PHPUnit's process isolation fails outright
	 * ("Serialization of 'Closure' is not allowed") given the closures and live
	 * DB connection objects this suite's fixtures involve (see
	 * tests/test-email-builder.php's ensure_ajax_die_is_catchable() docblock).
	 * WP_UNINSTALL_PLUGIN therefore stays defined for the rest of the PHP
	 * process once set here; that's harmless because uninstall.php is its only
	 * reader anywhere in the codebase (verified: `grep -r WP_UNINSTALL_PLUGIN`).
	 */
	private function define_wp_uninstall_plugin(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
	}

	public function tear_down() {
		// Real DROP TABLE is DDL, not transactional, so it (and the recreate
		// below) run outside - and survive - the per-test rollback. Recreate
		// for real so later tests still see the tables.
		$this->allow_real_ddl();
		delete_option( Migrations::OPTION );
		Migrations::run();

		// The end-to-end tests below `require` the whole uninstall.php, whose
		// Compliance section drops anchor_consent_log / anchor_privacy_requests
		// unconditionally (no option gate - see that section's own docblock).
		// With the temp-table filters removed those drops are real too, so put
		// the compliance tables back for whichever suite runs next.
		if ( class_exists( 'Anchor_Compliance_Consent_Log' ) ) {
			Anchor_Compliance_Consent_Log::install();
		}
		if ( class_exists( 'Anchor_Compliance_Dsar' ) ) {
			Anchor_Compliance_Dsar::install();
		}

		/*
		 * Every install() above ends with an update_option() call for its DB
		 * version, issued AFTER that install's last CREATE TABLE - i.e. after
		 * the implicit commit that made the tables real. Being ordinary DML,
		 * that trailing option write is NOT covered by the same commit and
		 * would otherwise be undone by WP_UnitTestCase's tear_down() ROLLBACK,
		 * leaving real tables paired with a stale/empty version option for
		 * whichever test runs next. Commit explicitly to close that gap. This
		 * adds no new leakage: the CREATE TABLE calls just above already
		 * force-committed everything pending at that point (including this
		 * test's own fixtures, if any), so this only carries the version
		 * writes across the same boundary.
		 */
		self::commit_transaction();

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

	/** End-to-end: no opt-in flag means uninstall.php must leave everything standing. */
	public function test_running_uninstall_without_the_option_keeps_tables_and_options() {
		$this->define_wp_uninstall_plugin();
		delete_option( 'anchor_courses_delete_data_on_uninstall' );

		require ANCHOR_TOOLS_PLUGIN_DIR . 'uninstall.php';

		foreach ( Migrations::TABLES as $name ) {
			$this->assertTrue( $this->table_exists( $name ), "{$name} should survive an uninstall with no opt-in." );
		}
		$this->assertSame( Migrations::DB_VERSION, Migrations::installed_version() );
		$this->assertTrue(
			get_role( 'administrator' )->has_cap( Capabilities::cap( 'manage' ) ),
			'Capabilities must survive an uninstall with no opt-in.'
		);
	}

	/** End-to-end: the opt-in flag must make uninstall.php actually drop everything. */
	public function test_running_uninstall_with_the_option_drops_tables_options_and_capabilities() {
		$this->define_wp_uninstall_plugin();
		$this->allow_real_ddl();
		update_option( 'anchor_courses_delete_data_on_uninstall', 1, false );

		require ANCHOR_TOOLS_PLUGIN_DIR . 'uninstall.php';

		foreach ( Migrations::TABLES as $name ) {
			$this->assertFalse( $this->table_exists( $name ), "{$name} should have been dropped by a real uninstall." );
		}
		$this->assertFalse( get_option( 'anchor_courses_db_version' ), 'The version option must be deleted.' );
		$this->assertFalse( get_option( 'anchor_courses_delete_data_on_uninstall' ), 'The opt-in flag itself must be deleted.' );

		$administrator = get_role( 'administrator' );
		foreach ( Capabilities::all() as $cap ) {
			$this->assertFalse( $administrator->has_cap( $cap ), "administrator should have lost {$cap}." );
		}
	}

	/**
	 * Final review minor: the opt-in uninstall also removes every minted
	 * course role (access and completion) and the grants user meta - and
	 * nothing that merely looks similar.
	 */
	public function test_uninstall_with_the_option_removes_course_roles_and_grant_meta() {
		$this->define_wp_uninstall_plugin();
		$this->allow_real_ddl();
		add_role( 'anchor_course_4242', 'Course: X', [] );
		add_role( 'anchor_course_4242_completed', 'Completed: X', [] );
		add_role( 'anchor_course_manager', 'Not a course role', [] );
		$user = self::factory()->user->create();
		update_user_meta( $user, '_anchor_course_grants', [ 4242 => [ 'source' => 'manual' ] ] );
		update_option( 'anchor_courses_delete_data_on_uninstall', 1, false );

		require ANCHOR_TOOLS_PLUGIN_DIR . 'uninstall.php';

		$this->assertNull( get_role( 'anchor_course_4242' ) );
		$this->assertNull( get_role( 'anchor_course_4242_completed' ) );
		$this->assertNotNull( get_role( 'anchor_course_manager' ), 'Only anchor_course_{id}[_completed] slugs are course roles.' );
		$this->assertSame( '', get_user_meta( $user, '_anchor_course_grants', true ) );
		remove_role( 'anchor_course_manager' );
	}

	public function test_uninstall_without_the_option_keeps_course_roles() {
		$this->define_wp_uninstall_plugin();
		delete_option( 'anchor_courses_delete_data_on_uninstall' );
		add_role( 'anchor_course_4243', 'Course: Y', [] );

		require ANCHOR_TOOLS_PLUGIN_DIR . 'uninstall.php';

		$this->assertNotNull( get_role( 'anchor_course_4243' ) );
		remove_role( 'anchor_course_4243' );
	}
}
