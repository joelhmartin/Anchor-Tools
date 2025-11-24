<?php
/**
 * Plugin Name: Anchor Tools
 * Description: Generate, upload, validate, bulk create, edit, and serve JSON-LD schema from a meta box and a bulk wizard. Auto detects content from Divi modules and ACF fields. Includes OpenAI settings and debug logging.
 * Version: 2.0.4
 * Author: Anchor Corps
 * License: GPLv2 or later
 * Text Domain: anchor-tools
 */

use Dotenv\Dotenv;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$acg_autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $acg_autoload ) ) {
	require_once $acg_autoload;
} else {
	add_action(
		'admin_notices',
		function() use ( $acg_autoload ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'Anchor Tools is missing its Composer autoloader. Run composer install to continue. Missing: ' . $acg_autoload ) . '</p></div>';
		}
	);
	return;
}

define( 'ACG_VERSION', '1.2.0' );
define( 'ACG_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACG_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/.env' ) ) {
	$dotenv = Dotenv::createImmutable( __DIR__ );
	$dotenv->safeLoad();
}

$acg_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/joelhmartin/Anchor-Tools/',
	__FILE__,
	'anchor-tools'
);

$acg_github_token = $_ENV['GITHUB_ACCESS_TOKEN']
	?? getenv( 'GITHUB_ACCESS_TOKEN' )
	?: ( defined( 'GITHUB_ACCESS_TOKEN' ) ? GITHUB_ACCESS_TOKEN : null );

if ( $acg_github_token ) {
	$acg_update_checker->setAuthentication( $acg_github_token );
}

$acg_update_checker->setBranch( 'main' );

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	error_log( '[PUC] Anchor Tools bootstrap loaded' );
	error_log( '[PUC] Token present? ' . ( ! empty( $acg_github_token ) ? 'yes' : 'no' ) );
}

add_filter(
	'upgrader_pre_download',
	function( $reply, $package ) {
		error_log( '[UPGRADER] pre_download package=' . $package );
		return $reply;
	},
	10,
	2
);

add_filter(
	'upgrader_source_selection',
	function( $source ) {
		error_log( '[UPGRADER] source_selection source=' . $source );
		return $source;
	},
	10,
	1
);

add_action(
	'admin_init',
	function() use ( $acg_github_token ) {
		if ( isset( $_GET['puc_debug_token'] ) ) {
			echo 'Token loaded? ' . ( ! empty( $acg_github_token ) ? 'yes' : 'no' );
			exit;
		}
	}
);

spl_autoload_register(function($class){
    if (strpos($class, 'ACG_') === 0) {
        $file = ACG_DIR . 'includes/' . 'class-' . strtolower(str_replace('_', '-', $class)) . '.php';
        if ( file_exists( $file ) ) { require_once $file; }
    }
});

add_action( 'plugins_loaded', function(){
    load_plugin_textdomain( 'anchor-tools', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
});

add_action( 'init', function(){
    if ( is_admin() ) { new ACG_Admin(); }
    new ACG_Render();
});
