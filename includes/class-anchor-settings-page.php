<?php
/**
 * Anchor Tools — Unified Tabbed Settings Page.
 *
 * Registers a single Settings > Anchor Tools page with tab navigation.
 * Modules add their own tabs via the 'anchor_settings_tabs' filter.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Settings_Page {

    const PAGE_SLUG = 'anchor-schema';

    /** @var array Cached tabs array. */
    private $tabs = null;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ], 5 );
        add_action( 'admin_init', [ $this, 'handle_legacy_redirects' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_tab_assets' ] );
    }

    /**
     * Register the single settings page.
     */
    public function register_page() {
        add_options_page(
            __( 'Anchor Tools Settings', 'anchor-schema' ),
            __( 'Anchor Tools', 'anchor-schema' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /**
     * Get all registered tabs.
     *
     * @return array [ 'tab_key' => [ 'label' => '', 'callback' => callable ] ]
     */
    public function get_tabs() {
        if ( null === $this->tabs ) {
            $this->tabs = apply_filters( 'anchor_settings_tabs', [] );
        }
        return $this->tabs;
    }

    /**
     * Get the currently active tab key.
     *
     * @return string
     */
    public function get_current_tab() {
        $tabs = $this->get_tabs();
        $current = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
        if ( ! $current || ! isset( $tabs[ $current ] ) ) {
            $keys = array_keys( $tabs );
            $current = $keys ? $keys[0] : 'general';
        }
        return $current;
    }

    /**
     * Render the tabbed settings page.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tabs    = $this->get_tabs();
        $current = $this->get_current_tab();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Anchor Tools Settings', 'anchor-schema' ) . '</h1>';

        // Tab navigation.
        if ( count( $tabs ) > 1 ) {
            echo '<h2 class="nav-tab-wrapper">';
            foreach ( $tabs as $key => $tab ) {
                $url   = add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $key ], admin_url( 'options-general.php' ) );
                $class = ( $key === $current ) ? 'nav-tab nav-tab-active' : 'nav-tab';
                printf(
                    '<a href="%s" class="%s">%s</a>',
                    esc_url( $url ),
                    esc_attr( $class ),
                    esc_html( $tab['label'] )
                );
            }
            echo '</h2>';
        }

        // Render the active tab content.
        if ( isset( $tabs[ $current ] ) && is_callable( $tabs[ $current ]['callback'] ) ) {
            call_user_func( $tabs[ $current ]['callback'] );
        }

        echo '</div>';
    }

    /**
     * Fire per-tab asset enqueue action so modules can load assets only on their tab.
     */
    public function maybe_enqueue_tab_assets( $hook ) {
        if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }
        $current = $this->get_current_tab();
        do_action( 'anchor_settings_enqueue_' . $current, $hook );
    }

    /**
     * Redirect old standalone settings page slugs to the unified tabbed page.
     */
    public function handle_legacy_redirects() {
        if ( ! is_admin() || ! isset( $_GET['page'] ) ) {
            return;
        }

        $legacy_map = [
            'anchor-reviews'       => 'reviews',
            'anchor-shortcodes'    => 'shortcodes',
            'anchor-ctm-forms'     => 'ctm_forms',
            'anchor-events'        => 'events',
            'anchor-webinars'      => 'webinars',
            'anchor-store-locator' => 'store_locator',
            'anchor-optimize'      => 'optimize',
            'anchor-post-display'  => 'post_display',
            'asf-credentials'      => 'social_feed',
        ];

        $page = sanitize_text_field( $_GET['page'] );

        if ( isset( $legacy_map[ $page ] ) ) {
            $url = add_query_arg(
                [ 'page' => self::PAGE_SLUG, 'tab' => $legacy_map[ $page ] ],
                admin_url( 'options-general.php' )
            );
            wp_safe_redirect( $url );
            exit;
        }
    }
}
