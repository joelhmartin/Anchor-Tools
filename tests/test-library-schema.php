<?php
/**
 * Library schema: install, and the version-gated upgrader that must carry live TMJ (2.14.0) to the pinned version.
 *
 * @package Anchor\Library\Tests
 * @group library
 */

use Anchor\Library\Schema;

class Anchor_Library_Schema_Test extends Anchor_Library_TestCase {

	/** @var array<string,int> option name => times it was added or updated */
	private array $writes = [];

	public function set_up() {
		parent::set_up();
		$this->writes = [];
		$count        = function ( $option ) {
			if ( 0 === strpos( $option, 'anchor_fm_' ) ) {
				$this->writes[ $option ] = ( $this->writes[ $option ] ?? 0 ) + 1;
			}
		};
		add_action( 'added_option', $count );
		add_action( 'updated_option', $count );
	}

	public function tear_down() {
		$this->ensure_tables();
		remove_all_filters( 'home_url' );
		parent::tear_down();
	}

	private function as_tmj_host(): void {
		add_filter( 'home_url', static fn() => 'https://tmjtherapycentre.com' );
	}

	private function live_2_14_state(): void {
		update_option( Schema::OPT_DB_VERSION, '2.14.0' );
		delete_option( Schema::OPT_REQUEST_ACCESS_EMAIL );
		delete_option( Schema::OPT_PORTAL_LOGO );
		$this->writes = [];
	}

	public function test_pinned_db_version_matches_the_plugin_at_the_source_pin() {
		$this->assertSame( '2.17.0', Schema::DB_VERSION );
	}

	public function test_install_creates_all_tables() {
		$this->drop_all_tables();
		foreach ( [ 'folders', 'files', 'permissions', 'permission_policies', 'activity', 'links', 'videos', 'video_views' ] as $name ) {
			$this->assertFalse( $this->table_exists( $name ), "$name should be gone before install" );
		}

		$this->assertTrue( Schema::install() );

		foreach ( [ 'folders', 'files', 'permissions', 'permission_policies', 'activity', 'links', 'videos', 'video_views' ] as $name ) {
			$this->assertTrue( $this->table_exists( $name ), "anchor_fm_$name should exist" );
		}
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPT_DB_VERSION ) );
		$this->assertGreaterThan( 0, (int) get_option( Schema::OPT_PD_FOLDER_ID ), 'Product Docs folder is created' );
		$this->assertSame( 0, (int) get_option( Schema::OPT_EMAIL_ON_UPLOAD, null ) );
	}

	public function test_table_name_uses_prefix() {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'anchor_fm_folders', Schema::table( 'folders' ) );
	}

	public function test_upgrade_from_2_14_runs_each_step_once() {
		$this->as_tmj_host();
		$this->live_2_14_state();

		Schema::maybe_upgrade();
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPT_DB_VERSION ) );
		$this->assertSame( Schema::LEGACY_REQUEST_ACCESS_EMAIL, get_option( Schema::OPT_REQUEST_ACCESS_EMAIL ) );
		$this->assertSame( Schema::LEGACY_PORTAL_LOGO, get_option( Schema::OPT_PORTAL_LOGO ) );
		$this->assertSame( 1, $this->writes[ Schema::OPT_REQUEST_ACCESS_EMAIL ] ?? 0 );
		$this->assertSame( 1, $this->writes[ Schema::OPT_PORTAL_LOGO ] ?? 0 );
		$this->assertSame( 1, $this->writes[ Schema::OPT_DB_VERSION ] ?? 0 );

		// A repeated request (or the module being toggled off and on) must not run the steps again.
		Schema::maybe_upgrade();
		Schema::maybe_upgrade();
		$this->assertSame( 1, $this->writes[ Schema::OPT_REQUEST_ACCESS_EMAIL ] );
		$this->assertSame( 1, $this->writes[ Schema::OPT_PORTAL_LOGO ] );
		$this->assertSame( 1, $this->writes[ Schema::OPT_DB_VERSION ] );
	}

	public function test_2_15_pins_apply_only_to_the_international_host() {
		$this->live_2_14_state();
		Schema::maybe_upgrade();
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPT_DB_VERSION ) );
		$this->assertNull( get_option( Schema::OPT_REQUEST_ACCESS_EMAIL, null ), 'other sites get the new defaults, not International\'s' );
		$this->assertNull( get_option( Schema::OPT_PORTAL_LOGO, null ) );
	}

	public function test_2_15_pins_keep_values_an_admin_already_set_and_a_site_icon() {
		$this->as_tmj_host();
		$this->live_2_14_state();
		update_option( Schema::OPT_REQUEST_ACCESS_EMAIL, 'ops@example.com' );
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		update_option( 'site_icon', $attachment );

		Schema::maybe_upgrade();

		$this->assertSame( 'ops@example.com', get_option( Schema::OPT_REQUEST_ACCESS_EMAIL ) );
		$this->assertNull( get_option( Schema::OPT_PORTAL_LOGO, null ), 'a configured site icon wins over the hardcoded logo' );
	}

	public function test_2_15_host_gate_accepts_staging_subdomains_only() {
		foreach ( [ 'https://stg-tmjtherapycentre.kinsta.cloud' => false, 'https://staging.tmjtherapycentre.com' => true, 'https://nottmjtherapycentre.com' => false ] as $url => $expected ) {
			remove_all_filters( 'home_url' );
			add_filter( 'home_url', static fn() => $url );
			$this->assertSame( $expected, Schema::is_legacy_international_host(), $url );
		}
	}

	public function test_fresh_site_installs_instead_of_running_upgrade_steps() {
		$this->drop_all_tables();
		delete_option( Schema::OPT_DB_VERSION );
		$this->as_tmj_host(); // would be pinned if the upgrade path ran for a fresh install.

		Schema::maybe_upgrade();

		$this->assertTrue( $this->table_exists( 'folders' ) );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPT_DB_VERSION ) );
		$this->assertNull( get_option( Schema::OPT_REQUEST_ACCESS_EMAIL, null ), 'a fresh install is never pinned' );
	}

	public function test_pre_2_12_resets_watch_stats_once_and_2_14_does_not() {
		global $wpdb;
		$insert = static function () use ( $wpdb ) {
			$wpdb->insert( Schema::table( 'video_views' ), [
				'source' => 'vimeo', 'video_id' => 1, 'user_id' => 1, 'furthest_seconds' => 90, 'total_seconds' => 80,
				'resume_seconds' => 70, 'percent' => 55, 'sessions' => 2,
				'first_viewed_at' => '2026-01-01 00:00:00', 'last_viewed_at' => '2026-01-01 00:00:00',
			] );
		};

		$insert();
		update_option( Schema::OPT_DB_VERSION, '2.14.0' );
		Schema::maybe_upgrade();
		$this->assertSame( '55', $wpdb->get_var( 'SELECT percent FROM ' . Schema::table( 'video_views' ) ), '2.14 data already has coverage stats' );

		update_option( Schema::OPT_DB_VERSION, '2.11.0' );
		Schema::maybe_upgrade();
		$row = $wpdb->get_row( 'SELECT percent, total_seconds, resume_seconds, sessions FROM ' . Schema::table( 'video_views' ) );
		$this->assertSame( [ '0', '0', '70', '2' ], [ $row->percent, $row->total_seconds, $row->resume_seconds, $row->sessions ] );
	}

	public function test_events_are_scheduled_once_and_cleared() {
		Schema::schedule_events();
		Schema::schedule_events();
		$prune = wp_next_scheduled( Schema::CRON_PRUNE_RESUME );
		$check = wp_next_scheduled( Schema::CRON_STORAGE_CHECK );
		$this->assertNotFalse( $prune );
		$this->assertNotFalse( $check );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn( $hooks ) => isset( $hooks[ Schema::CRON_PRUNE_RESUME ] ) ) );

		Schema::clear_events();
		$this->assertFalse( wp_next_scheduled( Schema::CRON_PRUNE_RESUME ) );
		$this->assertFalse( wp_next_scheduled( Schema::CRON_STORAGE_CHECK ) );
	}

	public function test_switching_the_module_off_clears_its_events() {
		Schema::schedule_events();
		$on  = [ 'modules' => [ 'library' => true ] ];
		$off = [ 'modules' => [ 'library' => false ] ];
		anchor_tools_library_maybe_clear_events( $on, $on );
		$this->assertNotFalse( wp_next_scheduled( Schema::CRON_PRUNE_RESUME ) );
		anchor_tools_library_maybe_clear_events( $on, $off );
		$this->assertFalse( wp_next_scheduled( Schema::CRON_PRUNE_RESUME ) );
	}
}
