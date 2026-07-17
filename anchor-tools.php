<?php
/**
 * Plugin Name: Anchor Tools
 * Description: A set of tools provided by Anchor Corps. Lightweight Mega Menu, Popups, schema, galleries, forms, and content utilities.
 * Version: 3.9.15
 * Author: Anchor Corps
 * Text Domain: anchor-tools
 */

use Dotenv\Dotenv;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ANCHOR_TOOLS_PLUGIN_FILE', __FILE__ );
define( 'ANCHOR_TOOLS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANCHOR_TOOLS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );


if ( ! defined( 'ANCHOR_SCHEMA_VERSION' ) ) {
    define( 'ANCHOR_SCHEMA_VERSION', '1.0.3' );
}
if ( ! defined( 'ANCHOR_SCHEMA_DIR' ) ) {
    define( 'ANCHOR_SCHEMA_DIR', ANCHOR_TOOLS_PLUGIN_DIR );
}
if ( ! defined( 'ANCHOR_SCHEMA_URL' ) ) {
    define( 'ANCHOR_SCHEMA_URL', ANCHOR_TOOLS_PLUGIN_URL );
}

/**
 * Declare WooCommerce HPOS (custom order tables) compatibility.
 *
 * Registered at file scope (NOT inside the priority-25 module bootstrap) because
 * `before_woocommerce_init` can fire before that bootstrap. Self-no-ops when
 * WooCommerce / the FeaturesUtil class is absent.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            ANCHOR_TOOLS_PLUGIN_FILE,
            true
        );
    }
} );

$acg_autoload = ANCHOR_TOOLS_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $acg_autoload ) ) {
    require_once $acg_autoload;
}

if ( class_exists( Dotenv::class ) && file_exists( ANCHOR_TOOLS_PLUGIN_DIR . '.env' ) ) {
    $dotenv = Dotenv::createImmutable( ANCHOR_TOOLS_PLUGIN_DIR );
    $dotenv->safeLoad();
    
}

if ( ! class_exists( 'Anchor_Asset_Loader' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-asset-loader.php';
}
if ( ! class_exists( 'Anchor_Monaco' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-monaco.php';
}
if ( ! class_exists( 'Anchor_Groups' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-groups.php';
}
if ( ! class_exists( 'Anchor_Schema_Logger' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-schema-logger.php';
}
if ( ! class_exists( 'Anchor_Preview_CSS' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-preview-css.php';
}
if ( ! class_exists( 'Anchor_Schema_Helper' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-schema-helper.php';
}
if ( ! class_exists( 'Anchor_Settings_Page' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-settings-page.php';
}
if ( ! class_exists( 'Anchor_Schema_Admin' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-schema-admin.php';
}
if ( ! class_exists( 'Anchor_Schema_Render' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-schema-render.php';
}
if ( ! class_exists( 'Anchor_Reviews_Google_Provider' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-reviews-provider-google.php';
}
if ( ! class_exists( 'Anchor_Reviews_Manager' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-reviews.php';
}
if ( ! class_exists( 'Anchor_Builder_Shell' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/builder/class-anchor-builder-device-toolbar.php';
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/builder/class-anchor-builder-preset-picker.php';
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/builder/class-anchor-builder-shell.php';
}

if ( class_exists( PucFactory::class ) ) {
    $anchor_tools_update = PucFactory::buildUpdateChecker(
        'https://github.com/joelhmartin/Anchor-Tools/',
        __FILE__,
        'anchor-tools'
    );
    $anchor_tools_update->setBranch( 'main' );

    $anchor_tools_token = $_ENV['GITHUB_ACCESS_TOKEN']
        ?? getenv( 'GITHUB_ACCESS_TOKEN' )
        ?: ( defined( 'GITHUB_ACCESS_TOKEN' ) ? GITHUB_ACCESS_TOKEN : null );

    if ( $anchor_tools_token ) {
        $anchor_tools_update->setAuthentication( $anchor_tools_token );
    }

    $anchor_tools_directory_name  = basename( rtrim( ANCHOR_TOOLS_PLUGIN_DIR, '/\\' ) );
    $anchor_tools_package_name    = ( 'Anchor-Tools' === $anchor_tools_directory_name ) ? 'anchor-tools-Anchor-Tools.zip' : 'anchor-tools.zip';
    $anchor_tools_package_pattern = '/^' . preg_quote( $anchor_tools_package_name, '/' ) . '$/';

    $anchor_tools_vcs = method_exists( $anchor_tools_update, 'getVcsApi' ) ? $anchor_tools_update->getVcsApi() : null;
    if ( $anchor_tools_vcs && method_exists( $anchor_tools_vcs, 'enableReleaseAssets' ) ) {
        $anchor_tools_vcs->enableReleaseAssets(
            $anchor_tools_package_pattern,
            \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\Api::REQUIRE_RELEASE_ASSETS
        );
    }

    add_filter(
        'puc_vcs_update_detection_strategies-anchor-tools',
        function( $strategies ) {
            $release_strategy = \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\Api::STRATEGY_LATEST_RELEASE;

            if ( isset( $strategies[ $release_strategy ] ) ) {
                return array( $release_strategy => $strategies[ $release_strategy ] );
            }

            return $strategies;
        }
    );
}

add_action(
    'init',
    function() {
        load_plugin_textdomain( 'anchor-schema', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }
);

add_filter(
    'plugin_action_links_' . plugin_basename( __FILE__ ),
    function( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=anchor-schema' ) ) . '">' . __( 'Settings', 'anchor-schema' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
);

add_action(
    'plugins_loaded',
    function() {
        if ( is_admin() && class_exists( 'Anchor_Settings_Page' ) ) {
            new Anchor_Settings_Page();
        }
        if ( is_admin() && class_exists( 'Anchor_Schema_Admin' ) ) {
            new Anchor_Schema_Admin();
        }
        if ( is_admin() && class_exists( 'Anchor_Preview_CSS' ) ) {
            new Anchor_Preview_CSS();
        }
        if ( class_exists( 'Anchor_Schema_Render' ) ) {
            new Anchor_Schema_Render();
        }
        if ( class_exists( 'Anchor_Reviews_Manager' ) ) {
            new Anchor_Reviews_Manager();
        }
    }
);


if ( ! function_exists( 'anchor_tools_get_available_modules' ) ) {
    /**
     * Return bundled Anchor Tools submodules.
     *
     * @return array
     */
    function anchor_tools_get_available_modules() {
        return [
            'social_feed' => [
                'label'       => __( 'Anchor Social Feed', 'anchor-schema' ),
                'description' => __( 'Display curated social feeds via shortcode.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-social-feed/anchor-social-feed.php',
                'class'       => 'Anchor_Social_Feed_Module',
            ],
            'mega_menu' => [
                'label'       => __( 'Anchor Mega Menu', 'anchor-schema' ),
                'description' => __( 'Create reusable mega menu snippets.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-mega-menu/anchor-mega-menu.php',
                'class'       => 'Anchor_Mega_Menu_Module',
            ],
            'events_manager' => [
                'label'       => __( 'Anchor Events Manager', 'anchor-schema' ),
                'description' => __( 'Manage events, calendars, and registrations.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-events-manager/anchor-events-manager.php',
                'class'       => '\\Anchor\\Events\\Module',
            ],
            'store_locator' => [
                'label'       => __( 'Anchor Store Locator', 'anchor-schema' ),
                'description' => __( 'Add a map-based store locator with search and proximity filtering.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-store-locator/anchor-store-locator.php',
                'class'       => '\\Anchor\\StoreLocator\\Module',
            ],
            'webinars' => [
                'label'       => __( 'Anchor Webinars', 'anchor-schema' ),
                'description' => __( 'Publish gated webinars with Vimeo watch tracking.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-webinars/anchor-webinars.php',
                'class'       => '\\Anchor\\Webinars\\Module',
            ],
            'universal_popups' => [
                'label'       => __( 'Anchor Universal Popups', 'anchor-schema' ),
                'description' => __( 'Build reusable HTML/video popups with triggers.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-universal-popups/anchor-universal-popups.php',
                'class'       => 'Anchor_Universal_Popups_Module',
            ],
            'shortcodes' => [
                'label'       => __( 'Anchor Shortcodes', 'anchor-schema' ),
                'description' => __( 'Manage general business info + custom shortcodes.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-shortcodes/anchor-shortcodes.php',
                'class'       => 'Anchor_Shortcodes_Module',
            ],
            'site_config' => [
                'label'       => __( 'Anchor Site Config', 'anchor-schema' ),
                'description' => __( 'Brand colors, fonts, logos, business info, hours, social links + custom shortcodes. Replaces Anchor Shortcodes.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-site-config/anchor-site-config.php',
                'class'       => 'Anchor_Site_Config_Module',
            ],
            'video_slider' => [
                'label'       => __( 'Anchor Gallery', 'anchor-schema' ),
                'description' => __( 'Create galleries with slider, grid, carousel, masonry, and logo marquee layouts.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-gallery/anchor-gallery.php',
                'class'       => 'Anchor_Gallery_Module',
            ],
            'slider' => [
                'label'       => __( 'Anchor Slider', 'anchor-schema' ),
                'description' => __( 'Slide decks with HTML, video, or image slides; full-width and backgrounds.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-slider/anchor-slider.php',
                'class'       => 'Anchor_Slider_Module',
            ],
            'quick_edit' => [
                'label'       => __( 'Anchor Quick Edit', 'anchor-schema' ),
                'description' => __( 'Quick Edit fields for Yoast SEO and featured image editing.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-quick-edit/anchor-quick-edit.php',
                'class'       => 'Anchor_Quick_Edit_Module',
            ],
            'ctm_forms' => [
                'label'       => __( 'Anchor CTM Forms', 'anchor-schema' ),
                'description' => __( 'Create custom forms that submit to CallTrackingMetrics FormReactors.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-ctm-forms/anchor-ctm-forms.php',
                'class'       => 'Anchor_CTM_Forms_Module',
            ],
            'code_snippets' => [
                'label'       => __( 'Anchor Code Snippets', 'anchor-schema' ),
                'description' => __( 'Insert code snippets into header, body, or footer globally or per page.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-code-snippets/anchor-code-snippets.php',
                'class'       => 'Anchor_Code_Snippets_Module',
            ],
            'blocks' => [
                'label'       => __( 'Anchor Blocks', 'anchor-schema' ),
                'description' => __( 'Reusable HTML/CSS/JS content blocks placed via shortcode. From a button to a full-width section.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-blocks/anchor-blocks.php',
                'class'       => 'Anchor_Blocks_Module',
            ],
            'optimize' => [
                'label'       => __( 'Anchor Optimize', 'anchor-schema' ),
                'description' => __( 'Local image compression + WebP/AVIF conversion on upload. No external APIs.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-optimize/anchor-optimize.php',
                'class'       => 'Anchor_Optimize_Module',
            ],
            'post_display' => [
                'label'       => __( 'Anchor Post Display', 'anchor-schema' ),
                'description' => __( 'Search forms and post grids with AJAX live search and pagination.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-post-display/anchor-post-display.php',
                'class'       => 'Anchor_Post_Display_Module',
            ],
            'reviews_display' => [
                'label'       => __( 'Anchor Reviews Display', 'anchor-schema' ),
                'description' => __( 'Display Google Reviews with slider, grid, masonry, and list layouts.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-reviews/anchor-reviews.php',
                'class'       => 'Anchor_Reviews_Display_Module',
            ],
            'accessibility' => [
                'label'       => __( 'Anchor Accessibility', 'anchor-schema' ),
                'description' => __( 'Floating accessibility toolbar with font sizing, contrast, grayscale, and more.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-accessibility/anchor-accessibility.php',
                'class'       => 'Anchor_Accessibility_Module',
            ],
            'translate' => [
                'label'       => __( 'Anchor Translate', 'anchor-schema' ),
                'description' => __( 'Client-side translation using Google Cloud Translation API with cookie-based language persistence.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-translate/anchor-translate.php',
                'class'       => 'Anchor_Translate_Module',
            ],
        ];
    }
}

if ( ! function_exists( 'anchor_tools_is_module_enabled' ) ) {
    /**
     * Determine if a module is enabled via settings.
     *
     * @param string $module_key
     * @return bool
     */
    function anchor_tools_is_module_enabled( $module_key ) {
        $settings = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
        if ( empty( $settings['modules'] ) || ! is_array( $settings['modules'] ) ) {
            return false;
        }
        if ( ! array_key_exists( $module_key, $settings['modules'] ) ) {
            return false;
        }
        return (bool) $settings['modules'][ $module_key ];
    }
}

if ( ! function_exists( 'anchor_tools_bootstrap_modules' ) ) {
    /**
     * Load enabled modules and instantiate their classes.
     *
     * @return void
     */
    function anchor_tools_bootstrap_modules() {
        $modules = anchor_tools_get_available_modules();
        foreach ( $modules as $key => $module ) {
            if ( ! anchor_tools_is_module_enabled( $key ) ) {
                continue;
            }

            if ( isset( $module['setup'] ) && is_callable( $module['setup'] ) ) {
                call_user_func( $module['setup'] );
            }

            if ( isset( $module['path'] ) && file_exists( $module['path'] ) ) {
                require_once $module['path'];
            }

            if ( isset( $module['class'] ) && class_exists( $module['class'] ) ) {
                new $module['class']();
            }

            if ( isset( $module['loader'] ) && is_callable( $module['loader'] ) ) {
                call_user_func( $module['loader'] );
            }
        }
    }

    add_action( 'plugins_loaded', 'anchor_tools_bootstrap_modules', 25 );
}
