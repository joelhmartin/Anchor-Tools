<?php
/**
 * Library module: registry entry, default-off state and the legacy-plugin guard.
 *
 * @package Anchor\Library\Tests
 * @group library
 */

class Anchor_Library_Module_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		require_once ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/anchor-library.php';
	}

	public function test_tests_load_this_worktrees_plugin() {
		$this->assertSame( \dirname( __DIR__ ) . '/', ANCHOR_TOOLS_PLUGIN_DIR );
	}

	public function test_registry_lists_library_disabled_by_default() {
		$modules = anchor_tools_get_available_modules();
		$this->assertArrayHasKey( 'library', $modules );
		$this->assertSame( '\\Anchor\\Library\\Module', $modules['library']['class'] );
		$this->assertSame( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/anchor-library.php', $modules['library']['path'] );
		$this->assertFileExists( $modules['library']['path'] );

		// Nothing stored for the key at all: the real fallback returns false.
		$settings = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
		unset( $settings['modules']['library'] );
		update_option( Anchor_Schema_Admin::OPTION_KEY, $settings, false );
		$this->assertFalse( anchor_tools_is_module_enabled( 'library' ) );

		// And an empty option (a fresh site) is off too.
		delete_option( Anchor_Schema_Admin::OPTION_KEY );
		$this->assertFalse( anchor_tools_is_module_enabled( 'library' ) );
	}

	public function test_legacy_plugin_blocks_boot() {
		if ( ! class_exists( 'Anchor_Private_File_Manager', false ) ) {
			// Stand-in for the old plugin; it cannot be undefined again, so test_boots_without_legacy skips when it ran first.
			eval( 'class Anchor_Private_File_Manager {}' ); // phpcs:ignore Squiz.PHP.Eval
		}
		remove_all_actions( 'admin_notices' );
		remove_shortcode( 'anchor_file_manager' );
		wp_clear_scheduled_hook( 'anchor_fm_prune_resume' );
		wp_clear_scheduled_hook( 'anchor_fm_storage_check' );
		update_option( 'anchor_fm_db_version', '2.14.0' ); // what a live plugin site holds

		$this->assertTrue( \Anchor\Library\Module::legacy_active() );
		\Anchor\Library\Module::boot();

		$this->assertFalse( shortcode_exists( 'anchor_file_manager' ), 'module must not register the portal shortcode' );
		$this->assertNotFalse( has_action( 'admin_notices', [ \Anchor\Library\Module::class, 'render_legacy_notice' ] ) );
		$this->assertSame( '2.14.0', get_option( 'anchor_fm_db_version' ), 'the plugin owns the schema: no upgrade ran' );
		$this->assertFalse( wp_next_scheduled( 'anchor_fm_prune_resume' ), 'no cron scheduled while the plugin is active' );
		$this->assertFalse( wp_next_scheduled( 'anchor_fm_storage_check' ) );
		delete_option( 'anchor_fm_db_version' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		ob_start();
		\Anchor\Library\Module::render_legacy_notice();
		$this->assertSame( '', ob_get_clean(), 'only administrators see the notice' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		\Anchor\Library\Module::render_legacy_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Anchor Library is waiting: deactivate the Anchor Private File Manager plugin to switch to the built-in Library.', $html );
	}

	public function test_boots_without_legacy() {
		// The legacy stand-in class may already exist in this process (it cannot be undeclared), so the guard's
		// answer is overridden through its filter; the default answer is the class_exists check asserted above.
		$this->assertSame( class_exists( 'Anchor_Private_File_Manager', false ), \Anchor\Library\Module::legacy_active() );
		add_filter( 'anchor_library_legacy_active', '__return_false' );
		$this->assertFalse( \Anchor\Library\Module::legacy_active() );

		remove_shortcode( 'anchor_file_manager' );
		wp_clear_scheduled_hook( 'anchor_fm_prune_resume' );
		wp_clear_scheduled_hook( 'anchor_fm_storage_check' );
		\Anchor\Library\Module::boot();

		$this->assertTrue( shortcode_exists( 'anchor_file_manager' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_anchor_fm_list' ) );
		$this->assertNotFalse( has_action( 'anchor_fm_prune_resume' ) );
		$this->assertNotFalse( has_action( 'anchor_fm_storage_check' ) );
		$this->assertNotFalse( wp_next_scheduled( 'anchor_fm_prune_resume' ) );
		$this->assertFalse( has_action( 'admin_notices', [ \Anchor\Library\Module::class, 'render_legacy_notice' ] ) );
		wp_clear_scheduled_hook( 'anchor_fm_prune_resume' );
		wp_clear_scheduled_hook( 'anchor_fm_storage_check' );
		delete_option( 'anchor_fm_db_version' );
	}

	/**
	 * The old plugin declares the same global helper classes from its own path. Loading the module bootstrap after
	 * that must reuse them, not fatal with "Cannot redeclare class". A class cannot be undeclared, so this runs in a
	 * fresh PHP process: the plugin's copies (byte-identical files at another path) first, the bootstrap second.
	 */
	public function test_bootstrap_reuses_helper_classes_the_legacy_plugin_already_declared() {
		$tmp = sys_get_temp_dir() . '/anchor-library-legacy-' . getmypid() . '-' . wp_rand();
		mkdir( $tmp );
		foreach ( glob( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/includes/class-afm-*.php' ) as $file ) {
			copy( $file, $tmp . '/' . basename( $file ) );
		}
		$script = '$_SERVER["REQUEST_METHOD"]="GET"; define("ABSPATH","/x/"); define("ANCHOR_TOOLS_PLUGIN_DIR",' . var_export( ANCHOR_TOOLS_PLUGIN_DIR, true ) . ');'
			. 'foreach (glob(' . var_export( $tmp, true ) . '."/class-afm-*.php") as $f) { require $f; }'
			. 'require ANCHOR_TOOLS_PLUGIN_DIR."anchor-library/anchor-library.php";'
			. 'echo "OK ", (new ReflectionClass("Anchor_FM_Range"))->getFileName() === ' . var_export( realpath( $tmp ) . '/class-afm-range.php', true ) . ' ? "reused" : "replaced";';
		exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -r ' . escapeshellarg( $script ) . ' 2>&1', $out, $code );
		exec( 'rm -rf ' . escapeshellarg( $tmp ) );
		$this->assertSame( 0, $code, implode( "\n", $out ) );
		$this->assertSame( 'OK reused', trim( implode( "\n", $out ) ) );
	}

	public function test_switching_the_module_off_clears_both_cron_hooks() {
		$settings = (array) get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
		$settings['modules']['library'] = 1;
		update_option( Anchor_Schema_Admin::OPTION_KEY, $settings, false );
		wp_schedule_event( time() + 3600, 'daily', 'anchor_fm_prune_resume' );
		wp_schedule_event( time() + 3600, 'daily', 'anchor_fm_storage_check' );
		$this->assertNotFalse( wp_next_scheduled( 'anchor_fm_prune_resume' ) );

		$settings['modules']['library'] = 0;
		update_option( Anchor_Schema_Admin::OPTION_KEY, $settings, false );
		$this->assertFalse( wp_next_scheduled( 'anchor_fm_prune_resume' ) );
		$this->assertFalse( wp_next_scheduled( 'anchor_fm_storage_check' ) );
		delete_option( Anchor_Schema_Admin::OPTION_KEY );
	}
}
