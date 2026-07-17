<?php
/**
 * PHPUnit bootstrap for the Anchor Events Manager integration suite.
 *
 * Boots the real WordPress test library, makes WooCommerce available (so the
 * WC-gated classes load), loads the Anchor Tools plugin, and enables the events
 * module before the plugin's priority-25 module bootstrap runs.
 *
 * WooCommerce path contract (must match .github/workflows/tests.yml):
 *   WooCommerce is required from WP_CONTENT_DIR . '/plugins/woocommerce/woocommerce.php'.
 *   WP_CONTENT_DIR resolves to {WP_CORE_DIR}/wp-content (the core test install),
 *   so the workflow unzips WooCommerce into {WP_CORE_DIR}/wp-content/plugins/.
 *   The require is file_exists-guarded, so a WC-less run still boots — the
 *   WC-dependent tests then markTestSkipped().
 *
 * @package Anchor\Events\Tests
 */

// Locate the WP test library (includes/ + data/).
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

$_functions = $_tests_dir . '/includes/functions.php';
if ( ! file_exists( $_functions ) ) {
	fwrite(
		STDERR,
		"Could not find {$_functions}\n" .
		"Run bin/install-wp-tests.sh first (or set WP_TESTS_DIR).\n"
	);
	exit( 1 );
}

// Give access to tests_add_filter().
require_once $_functions;

/**
 * Make WooCommerce available, then load the Anchor Tools plugin.
 *
 * Runs on muplugins_loaded so the WooCommerce class is defined before the
 * events module's priority-25 bootstrap evaluates class_exists('WooCommerce').
 */
function _anchor_events_manually_load_plugins() {
	// (a) WooCommerce — load its main file so class_exists('WooCommerce') is true.
	//     Guarded: a no-WC run still boots and the WC tests skip.
	$wc_main = WP_CONTENT_DIR . '/plugins/woocommerce/woocommerce.php';
	if ( file_exists( $wc_main ) ) {
		require $wc_main;
	}

	// (b) The Anchor Tools plugin (loads vendor/autoload + core classes).
	require dirname( __DIR__ ) . '/anchor-tools.php';
}
tests_add_filter( 'muplugins_loaded', '_anchor_events_manually_load_plugins' );

/**
 * Enable the events module before anchor_tools_bootstrap_modules() runs at
 * plugins_loaded priority 25. Priority 1 guarantees the option is set first.
 */
tests_add_filter(
	'plugins_loaded',
	function () {
		update_option(
			'anchor_schema_settings',
			[ 'modules' => [ 'events_manager' => true, 'locations' => true ] ],
			false
		);
	},
	1
);

// Boot the WP test environment.
require $_tests_dir . '/includes/bootstrap.php';

// Install WooCommerce's tables once (only when WC is present). DDL is not
// transactional, so the tables persist across the per-test transaction reset.
if ( class_exists( 'WooCommerce' ) && class_exists( 'WC_Install' ) ) {
	WC_Install::install();

	// Reload capabilities after WC adds its roles/caps (mirrors WC's own bootstrap).
	$GLOBALS['wp_roles'] = null;
	if ( function_exists( 'wp_roles' ) ) {
		wp_roles();
	}
}

// Shared base test case.
require __DIR__ . '/class-anchor-events-testcase.php';
