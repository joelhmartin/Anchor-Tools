<?php
/**
 * Plugin Name: Anchor Tools
 * Description: Generate, upload, validate, bulk create, edit, and serve JSON-LD schema from a meta box and a bulk wizard. Auto detects content from Divi modules and ACF fields. Includes OpenAI settings and debug logging.
 * Version: 3.0.0
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

if ( ! function_exists( 'acg_get_available_modules' ) ) {
	/**
	 * Return configuration for bundled Anchor modules.
	 *
	 * @return array
	 */
	function acg_get_available_modules() {
		return [
			'social_feed' => [
				'label' => __( 'Anchor Social Feed', 'anchor-tools' ),
				'description' => __( 'Display curated social feeds for YouTube, Facebook, X, and Spotify.', 'anchor-tools' ),
				'path' => ACG_DIR . 'anchor-social-feed/anchor-social-feed.php',
				'class' => 'Anchor_Social_Feed_Module',
				'setup' => function() {
					add_filter( 'anchor_social_feed_parent_menu_slug', function() {
						return 'anchor-tools';
					});
					add_filter( 'anchor_social_feed_menu_title', function() {
						return __( 'Anchor Social Feed', 'anchor-tools' );
					});
				},
			],
			'mega_menu' => [
				'label' => __( 'Anchor Mega Menu', 'anchor-tools' ),
				'description' => __( 'Build reusable mega menu panels with HTML, CSS, and JavaScript.', 'anchor-tools' ),
				'path' => ACG_DIR . 'anchor-mega-menu/anchor-mega-menu.php',
				'class' => 'Anchor_Mega_Menu_Module',
				'setup' => function() {
					add_filter( 'anchor_mega_menu_parent_menu', function() {
						return 'anchor-tools';
					});
				},
			],
			'universal_popups' => [
				'label' => __( 'Anchor Universal Popups', 'anchor-tools' ),
				'description' => __( 'Create reusable popups triggered by page load, class, or ID.', 'anchor-tools' ),
				'path' => ACG_DIR . 'anchor-universal-popups/anchor-universal-popups.php',
				'class' => 'Anchor_Universal_Popups_Module',
				'setup' => function() {
					add_filter( 'anchor_universal_popups_parent_menu', function() {
						return 'anchor-tools';
					});
				},
			],
			'shortcodes' => [
				'label' => __( 'Anchor Shortcodes', 'anchor-tools' ),
				'description' => __( 'Manage general business info and lightweight custom shortcodes.', 'anchor-tools' ),
				'path' => ACG_DIR . 'anchor-shortcodes/anchor-shortcodes.php',
				'class' => 'Anchor_Shortcodes_Module',
				'setup' => function() {
					add_filter( 'anchor_shortcodes_parent_menu_slug', function() {
						return 'anchor-tools';
					});
					add_filter( 'anchor_shortcodes_menu_title', function() {
						return __( 'Anchor Shortcodes', 'anchor-tools' );
					});
				},
			],
		];
	}
}

if ( ! function_exists( 'acg_is_module_enabled' ) ) {
	/**
	 * Determine whether a module is enabled in the plugin settings.
	 *
	 * @param string $module_key
	 * @return bool
	 */
	function acg_is_module_enabled( $module_key ) {
		$settings = get_option( 'acg_settings', [] );
		if ( empty( $settings ) ) {
			$legacy = get_option( 'anchor_schema_settings', [] );
			if ( ! empty( $legacy ) ) {
				$settings = $legacy;
			}
		}

		if ( empty( $settings['modules'] ) || ! is_array( $settings['modules'] ) ) {
			return true;
		}

		if ( ! array_key_exists( $module_key, $settings['modules'] ) ) {
			return true;
		}

		return ! empty( $settings['modules'][ $module_key ] );
	}
}

if ( ! function_exists( 'acg_bootstrap_modules' ) ) {
	/**
	 * Load and instantiate enabled modules.
	 *
	 * @return void
	 */
	function acg_bootstrap_modules() {
		$modules = acg_get_available_modules();
		foreach ( $modules as $key => $module ) {
			if ( ! acg_is_module_enabled( $key ) ) {
				continue;
			}

			if ( isset( $module['setup'] ) && is_callable( $module['setup'] ) ) {
				call_user_func( $module['setup'] );
			}

			if ( isset( $module['path'] ) && file_exists( $module['path'] ) ) {
				require_once $module['path'];
			}

			if ( isset( $module['loader'] ) && is_callable( $module['loader'] ) ) {
				call_user_func( $module['loader'] );
			} elseif ( isset( $module['class'] ) && class_exists( $module['class'] ) ) {
				new $module['class']();
			}
		}
	}

	add_action( 'plugins_loaded', 'acg_bootstrap_modules', 5 );
}

add_action( 'plugins_loaded', function(){
    load_plugin_textdomain( 'anchor-tools', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
});

add_action( 'init', function(){
    if ( is_admin() ) { new ACG_Admin(); }
    new ACG_Render();
});
