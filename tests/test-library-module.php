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

		$this->assertTrue( \Anchor\Library\Module::legacy_active() );
		\Anchor\Library\Module::boot();

		$this->assertFalse( shortcode_exists( 'anchor_file_manager' ), 'module must not register the portal shortcode' );
		$this->assertNotFalse( has_action( 'admin_notices', [ \Anchor\Library\Module::class, 'render_legacy_notice' ] ) );

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
		$this->markTestIncomplete( 'Needs the Portal shortcodes from Task 4 (and a process without the legacy stand-in class).' );
	}
}
