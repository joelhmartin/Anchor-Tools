<?php
/**
 * Anchor Tools module: Anchor Locations.
 * Service-area & service-location pages with a linked Google map, hierarchy, and internal linking.
 */
namespace Anchor\Locations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Module {
    const CPT_LOCATION = 'anchor_location';
    const CPT_SERVICE  = 'anchor_service_page';
    const TAX_SERVICE  = 'service';
    const OPTION       = 'anchor_locations_settings';
    const NONCE        = 'anchor_locations_nonce';

    /** The live Module instance (set in __construct); reused by Dashboard to avoid re-instantiating. */
    private static $instance = null;

    /** @return self|null The constructed Module instance, or null if none has been built yet. */
    public static function instance() { return self::$instance; }

    public function __construct() {
        self::$instance = $this;
        \add_action( 'init', [ $this, 'register_types' ] );
        \add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        \add_filter( 'query_vars', [ $this, 'query_vars' ] );
        \add_action( 'parse_request', [ $this, 'resolve_service_request' ] );
        \add_filter( 'post_type_link', [ $this, 'service_permalink' ], 10, 2 );
        \add_action( 'init', [ $this, 'maybe_flush' ], 99 );

        \add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
        \add_action( 'save_post', [ $this, 'save_meta' ] );
        \add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        \add_filter( 'manage_' . self::CPT_LOCATION . '_posts_columns', [ $this, 'location_columns' ] );
        \add_action( 'manage_' . self::CPT_LOCATION . '_posts_custom_column', [ $this, 'location_column' ], 10, 2 );
        \add_filter( 'manage_' . self::CPT_SERVICE . '_posts_columns', [ $this, 'service_columns' ] );
        \add_action( 'manage_' . self::CPT_SERVICE . '_posts_custom_column', [ $this, 'service_column' ], 10, 2 );

        \add_filter( 'the_content', [ $this, 'the_content_render' ], 9 );
        \add_shortcode( 'anchor_page_content', [ $this, 'shortcode_page_content' ] );

        \add_shortcode( 'anchor_breadcrumbs', [ $this, 'sc_breadcrumbs' ] );
        \add_shortcode( 'anchor_child_locations', [ $this, 'sc_child_locations' ] );
        \add_shortcode( 'anchor_location_parent', [ $this, 'sc_parent' ] );
        \add_shortcode( 'anchor_nearby_locations', [ $this, 'sc_nearby' ] );
        \add_shortcode( 'anchor_location_services', [ $this, 'sc_location_services' ] );
        \add_shortcode( 'anchor_service_locations', [ $this, 'sc_service_locations' ] );
        \add_shortcode( 'anchor_service_area_directory', [ $this, 'sc_directory' ] );
        \add_shortcode( 'anchor_location_map', [ $this, 'sc_map' ] );

        \add_action( 'wp_head', [ $this, 'print_schema' ], 20 );
        \add_filter( 'template_include', [ $this, 'fullwidth_template' ] );

        \add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 65 );
        \add_action( 'admin_init', [ $this, 'register_settings' ] );
        \add_action( 'anchor_settings_enqueue_locations', [ $this, 'settings_assets' ] );

        // Phase 2–8 sub-classes, each in its own file. Loaded defensively: a
        // missing file degrades that ONE phase gracefully (it simply doesn't
        // load) instead of fataling the whole plugin, and instantiation is
        // gated on the class actually existing after the require.
        //   Phase 2  Sections   — free-form per-page Monaco HTML sections (FAQ/testimonials/projects).
        //   Phase 5  Dashboard  — read-only Coverage Matrix + SEO Reports (navigation only).
        //   Phase 6  IO         — JSON/CSV import/export (upsert-by-slug, never deletes).
        //   Phase 8  Integrity  — data-integrity nudges + versioned cache invalidation.
        $phases = [
            'class-sections.php'  => __NAMESPACE__ . '\\Sections',
            'class-dashboard.php' => __NAMESPACE__ . '\\Dashboard',
            'class-io.php'        => __NAMESPACE__ . '\\IO',
            'class-integrity.php' => __NAMESPACE__ . '\\Integrity',
        ];
        foreach ( $phases as $file => $class ) {
            $path = __DIR__ . '/' . $file;
            if ( ! \file_exists( $path ) ) { continue; }
            require_once $path;
            if ( \class_exists( $class ) ) { new $class(); }
        }
    }

    private $assets_enqueued = false;
    private $rendering       = []; // post_id => true (recursion guard for [anchor_page_content])
    private static $map_seq  = 0;  // per-request counter for unique [anchor_location_map] container ids

    public function register_types() {
        \register_post_type( self::CPT_LOCATION, [
            'labels'       => [
                'name'          => \__( 'Locations', 'anchor-schema' ),
                'singular_name' => \__( 'Location', 'anchor-schema' ),
                'menu_name'     => \__( 'Anchor Locations', 'anchor-schema' ),
                'add_new_item'  => \__( 'Add New Location', 'anchor-schema' ),
                'edit_item'     => \__( 'Edit Location', 'anchor-schema' ),
            ],
            'public'       => true,
            'hierarchical' => true,
            'show_in_menu' => \apply_filters( 'anchor_locations_parent_menu', true ),
            'menu_icon'    => 'dashicons-location-alt',
            'supports'     => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
            'rewrite'      => [ 'slug' => $this->service_areas_base(), 'with_front' => false ],
            'has_archive'  => false,
        ] );

        \register_post_type( self::CPT_SERVICE, [
            'labels'       => [
                'name'          => \__( 'Service Pages', 'anchor-schema' ),
                'singular_name' => \__( 'Service Page', 'anchor-schema' ),
                'add_new_item'  => \__( 'Add New Service Page', 'anchor-schema' ),
                'edit_item'     => \__( 'Edit Service Page', 'anchor-schema' ),
            ],
            'public'       => true,
            'hierarchical' => false,
            'show_in_menu' => 'edit.php?post_type=' . self::CPT_LOCATION,
            'supports'     => [ 'title', 'editor', 'thumbnail' ],
            'rewrite'      => false,
            'has_archive'  => false,
        ] );

        \register_taxonomy( self::TAX_SERVICE, self::CPT_SERVICE, [
            'labels'       => [ 'name' => \__( 'Services', 'anchor-schema' ), 'singular_name' => \__( 'Service', 'anchor-schema' ) ],
            'public'       => false,
            'show_ui'      => true,
            'hierarchical' => true,
            'rewrite'      => false,
        ] );
    }

    public function add_rewrite_rules() {
        $base = $this->services_base();
        \add_rewrite_rule( '^' . $base . '/([^/]+)/([^/]+)/?$', 'index.php?al_service=$matches[1]&al_loc=$matches[2]', 'top' );
    }
    public function query_vars( $vars ) { $vars[] = 'al_service'; $vars[] = 'al_loc'; return $vars; }

    public function resolve_service_request( $wp ) {
        if ( empty( $wp->query_vars['al_service'] ) || empty( $wp->query_vars['al_loc'] ) ) { return; }
        $service = \sanitize_title( $wp->query_vars['al_service'] );
        $loc     = \sanitize_title( $wp->query_vars['al_loc'] );
        $post_id = $this->find_service_page( $service, $loc );
        if ( $post_id ) {
            $wp->query_vars = [ 'post_type' => self::CPT_SERVICE, 'p' => $post_id ];
        } else {
            $wp->query_vars = [ 'error' => '404' ];
        }
    }

    /** Find a published service page by service term slug + linked location slug. */
    private function find_service_page( $service_slug, $loc_slug ) {
        // Location slugs are globally unique (state-abbr suffix), so resolve by
        // post_name directly. get_page_by_path() treats hierarchical CPT slugs as
        // a full ancestor path and only matches top-level posts, which breaks
        // lookup for nested locations (e.g. a city under a county). Resolving by
        // post_name mirrors how service_page_url() builds the URL, keeping
        // inbound routing and outbound link generation symmetric.
        $loc_ids = \get_posts( [
            'post_type'      => self::CPT_LOCATION,
            'name'           => $loc_slug,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        if ( empty( $loc_ids ) ) { return 0; }
        $loc_id = (int) $loc_ids[0];

        $q = new \WP_Query( [
            'post_type'      => self::CPT_SERVICE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => [ [ 'taxonomy' => self::TAX_SERVICE, 'field' => 'slug', 'terms' => $service_slug ] ],
            'meta_query'     => [ [ 'key' => 'al_location_id', 'value' => $loc_id ] ],
        ] );
        return $q->have_posts() ? (int) $q->posts[0] : 0;
    }

    public function service_page_url( $post_id ) {
        $loc_id = (int) \get_post_meta( $post_id, 'al_location_id', true );
        if ( ! $loc_id ) { return '#'; }
        $loc = \get_post( $loc_id );
        $terms = \wp_get_object_terms( $post_id, self::TAX_SERVICE, [ 'fields' => 'slugs' ] );
        if ( ! $loc || \is_wp_error( $terms ) || empty( $terms ) ) { return '#'; }
        return \home_url( '/' . $this->services_base() . '/' . $terms[0] . '/' . $loc->post_name . '/' );
    }

    public function service_permalink( $url, $post ) {
        if ( \is_object( $post ) && $post->post_type === self::CPT_SERVICE ) {
            return $this->service_page_url( $post->ID );
        }
        return $url;
    }

    public static function activate() { \flush_rewrite_rules(); }

    public function maybe_flush() {
        $s = $this->settings();
        $sig = ( $s['services_base'] ?? 'services' ) . '|' . ( $s['service_areas_base'] ?? 'service-areas' ) . '|v1';
        if ( \get_option( 'anchor_locations_rw_sig' ) !== $sig ) {
            $this->add_rewrite_rules();
            \flush_rewrite_rules( false );
            \update_option( 'anchor_locations_rw_sig', $sig, false );
        }
    }

    private function settings() {
        $o = \get_option( self::OPTION, [] );
        return \is_array( $o ) ? $o : [];
    }
    private function service_areas_base() {
        $s = $this->settings();
        return ! empty( $s['service_areas_base'] ) ? \sanitize_title( $s['service_areas_base'] ) : 'service-areas';
    }
    private function services_base() {
        $s = $this->settings();
        return ! empty( $s['services_base'] ) ? \sanitize_title( $s['services_base'] ) : 'services';
    }

    /* ---- Admin: Settings > Anchor Tools > Locations tab ---- */

    /** Register the "Locations" tab on the shared Settings > Anchor Tools page. */
    public function register_tab( $tabs ) {
        $tabs['locations'] = [
            'label'    => \__( 'Locations', 'anchor-schema' ),
            'callback' => [ $this, 'render_tab' ],
        ];
        return $tabs;
    }

    /**
     * Register the settings option with the WP Settings API so the tab can
     * post to options.php, matching the working sibling pattern (ctm_forms,
     * webinars) rather than a bespoke admin-post handler.
     */
    public function register_settings() {
        \register_setting( 'anchor_locations_group', self::OPTION, [ $this, 'sanitize_settings' ] );
    }

    /**
     * Sanitize + default the settings array. Also the single choke point where
     * a base-slug change invalidates the cached rewrite signature so
     * maybe_flush() reflushes rewrite rules on the next request.
     */
    public function sanitize_settings( $in ) {
        $out = [];
        $out['services_base']      = ! empty( $in['services_base'] ) ? \sanitize_title( $in['services_base'] ) : 'services';
        $out['service_areas_base'] = ! empty( $in['service_areas_base'] ) ? \sanitize_title( $in['service_areas_base'] ) : 'service-areas';
        $out['marker_icon']        = isset( $in['marker_icon'] ) ? \esc_url_raw( $in['marker_icon'] ) : '';
        $out['map_center']         = isset( $in['map_center'] ) ? \sanitize_text_field( $in['map_center'] ) : '';
        $out['map_zoom']           = isset( $in['map_zoom'] ) ? (int) $in['map_zoom'] : 8;
        $out['wrapper_html']       = isset( $in['wrapper_html'] ) ? (string) $in['wrapper_html'] : '';
        $out['wrapper_css']        = isset( $in['wrapper_css'] ) ? (string) $in['wrapper_css'] : '';
        $out['wrapper_js']         = isset( $in['wrapper_js'] ) ? (string) $in['wrapper_js'] : '';
        $out['fullwidth_template'] = ! empty( $in['fullwidth_template'] ) ? '1' : '';
        \delete_option( 'anchor_locations_rw_sig' ); // force rewrite reflush on base change
        return $out;
    }

    /** Enqueue the shared Monaco editor for the global wrapper HTML/CSS/JS fields, plus the media picker for the marker-icon field. */
    public function settings_assets( $hook ) {
        \Anchor_Monaco::enqueue( 'anchor_locations_settings' );
        \wp_enqueue_media();
        $dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-locations/assets/';
        \wp_enqueue_script( 'anchor-locations-admin', \Anchor_Asset_Loader::url( 'anchor-locations/assets/admin.js' ), [ 'jquery' ], (string) \filemtime( $dir . 'admin.js' ), true );
    }

    /** Render the "Locations" settings tab content. */
    public function render_tab() {
        $s = $this->settings();
        $g = function( $k, $d = '' ) use ( $s ) { return \esc_attr( $s[ $k ] ?? $d ); };
        $opt = self::OPTION;
        $spec = \wp_json_encode( [
            [ 'id' => 'al_wrapper_html', 'label' => \__( 'Wrapper HTML', 'anchor-schema' ), 'lang' => 'html' ],
            [ 'id' => 'al_wrapper_css',  'label' => \__( 'Wrapper CSS', 'anchor-schema' ),  'lang' => 'css' ],
            [ 'id' => 'al_wrapper_js',   'label' => \__( 'Wrapper JS', 'anchor-schema' ),   'lang' => 'javascript' ],
        ] );

        echo '<form method="post" action="' . \esc_url( \admin_url( 'options.php' ) ) . '">';
        \settings_fields( 'anchor_locations_group' );

        echo '<h2>' . \esc_html__( 'Map & URLs', 'anchor-schema' ) . '</h2>';
        echo '<p><label>' . \esc_html__( 'Default marker icon URL', 'anchor-schema' ) . ' <input type="text" class="regular-text al-media" name="' . \esc_attr( $opt ) . '[marker_icon]" value="' . $g( 'marker_icon' ) . '"></label></p>';
        echo '<p><label>' . \esc_html__( 'Service-area base', 'anchor-schema' ) . ' <input type="text" name="' . \esc_attr( $opt ) . '[service_areas_base]" value="' . $g( 'service_areas_base', 'service-areas' ) . '"></label> ';
        echo '<label>' . \esc_html__( 'Services base', 'anchor-schema' ) . ' <input type="text" name="' . \esc_attr( $opt ) . '[services_base]" value="' . $g( 'services_base', 'services' ) . '"></label></p>';
        echo '<p><label>' . \esc_html__( 'Map center (lat,lng)', 'anchor-schema' ) . ' <input type="text" name="' . \esc_attr( $opt ) . '[map_center]" value="' . $g( 'map_center' ) . '"></label> ';
        echo '<label>' . \esc_html__( 'Default zoom', 'anchor-schema' ) . ' <input type="number" name="' . \esc_attr( $opt ) . '[map_zoom]" value="' . $g( 'map_zoom', '8' ) . '"></label></p>';
        echo '<p><label><input type="checkbox" name="' . \esc_attr( $opt ) . '[fullwidth_template]" value="1" ' . \checked( $s['fullwidth_template'] ?? '', '1', false ) . '> ' . \esc_html__( 'Use plugin full-width single template when the theme lacks one', 'anchor-schema' ) . '</label></p>';

        echo '<h2>' . \esc_html__( 'Global Wrapper Template', 'anchor-schema' ) . '</h2>';
        echo '<p class="description">' . \sprintf(
            /* translators: %s is the literal `{{content}}` placeholder token used inside the wrapper HTML. */
            \esc_html__( 'Wraps every location/service page. Include %s where the page body goes. Leave blank to disable.', 'anchor-schema' ),
            '<code>{{content}}</code>'
        ) . '</p>';
        echo '<div class="anchor-monaco" data-anchor-monaco=\'' . \esc_attr( $spec ) . '\'>';
        echo '<textarea id="al_wrapper_html" name="' . \esc_attr( $opt ) . '[wrapper_html]" style="display:none">' . \esc_textarea( $s['wrapper_html'] ?? '' ) . '</textarea>';
        echo '<textarea id="al_wrapper_css" name="' . \esc_attr( $opt ) . '[wrapper_css]" style="display:none">' . \esc_textarea( $s['wrapper_css'] ?? '' ) . '</textarea>';
        echo '<textarea id="al_wrapper_js" name="' . \esc_attr( $opt ) . '[wrapper_js]" style="display:none">' . \esc_textarea( $s['wrapper_js'] ?? '' ) . '</textarea>';
        echo '</div>';

        \submit_button();
        echo '</form>';

        $this->render_shortcode_reference();
    }

    /**
     * Print the on-page shortcode reference. Read-only docs so an operator can
     * place the module's content anywhere shortcodes run — a page-builder module,
     * a widget, a template — without leaving the settings screen.
     */
    private function render_shortcode_reference() {
        $sv = $this->services_base();

        echo '<hr style="margin:2em 0">';
        echo '<h2>' . \esc_html__( 'Shortcodes: place the content anywhere', 'anchor-schema' ) . '</h2>';

        echo '<p class="description" style="max-width:52em">' . \sprintf(
            /* translators: 1: [anchor_page_content] shortcode, 2: the_content, 3: id */
            \esc_html__( 'By default a Location or Service page swaps its %2$s for the body you author in the Content metabox, optionally wrapped in the Global Wrapper Template above. That path is optional: every piece below is also a shortcode, so it runs anywhere WordPress processes shortcodes — a page-builder module, a widget, or a template. The key one is %1$s — with no %3$s it renders the current page\'s own body and skips the wrapper entirely, so the surrounding layout provides the chrome.', 'anchor-schema' ),
            '<code>[anchor_page_content]</code>',
            '<code>the_content</code>',
            '<code>id</code>'
        ) . '</p>';

        echo '<p class="description" style="max-width:52em"><strong>' . \esc_html__( 'Note:', 'anchor-schema' ) . '</strong> ' . \sprintf(
            /* translators: 1: [anchor_page_content], 2: the_content */
            \esc_html__( 'Don\'t render the page body twice. If the surrounding layout already outputs %2$s (e.g. a "post content" element in a builder or theme template), that alone shows the body plus wrapper — adding %1$s in the same layout renders it a second time. Use one or the other.', 'anchor-schema' ),
            '<code>[anchor_page_content]</code>',
            '<code>the_content</code>'
        ) . '</p>';

        // [ shortcode, attributes, description ] grouped by purpose.
        $groups = [
            \__( 'Page content', 'anchor-schema' ) => [
                [ '[anchor_page_content]', 'id', \__( "Renders a page's authored HTML/CSS/JS body. Defaults to the current page; pass an id to embed another page's body.", 'anchor-schema' ) ],
            ],
            \__( 'Internal linking', 'anchor-schema' ) => [
                [ '[anchor_breadcrumbs]', 'id', \__( 'Home → ancestors → (for service pages) location chain → current title.', 'anchor-schema' ) ],
                [ '[anchor_child_locations]', 'id', \__( 'List of the location\'s direct published children.', 'anchor-schema' ) ],
                [ '[anchor_location_parent]', 'id', \__( 'Link to the location\'s parent, if published.', 'anchor-schema' ) ],
                [ '[anchor_nearby_locations]', 'id', \__( 'Up to 12 published sibling locations (same parent).', 'anchor-schema' ) ],
                [ '[anchor_location_services]', 'id', \__( 'All published service pages linked to this location.', 'anchor-schema' ) ],
                [ '[anchor_service_locations]', 'id', \__( 'Other locations offering this page\'s service (same service term).', 'anchor-schema' ) ],
                [ '[anchor_service_area_directory]', '—', \__( 'The full published location hierarchy as nested lists.', 'anchor-schema' ) ],
            ],
            \__( 'Map', 'anchor-schema' ) => [
                [ '[anchor_location_map]', 'types, parent, zoom, height, center, service, cluster, filters, focus, iconsize', \__( 'A Google Map with a pin per located location (info windows link to the page and its services; draws boundary polygons where set). On a location/service page it frames the current area by default (override with focus="none" or focus="123"); iconsize caps custom pin images (px, default 40). Requires the Google Maps API key in the main Anchor Tools settings.', 'anchor-schema' ) ],
            ],
            \__( 'Page sections', 'anchor-schema' ) => [
                [ '[anchor_local_faqs]', 'id', \__( 'Renders this page\'s FAQ section (the FAQ tab of Content Sections). id targets another page.', 'anchor-schema' ) ],
                [ '[anchor_local_testimonials]', 'id', \__( 'Renders this page\'s Testimonials section. id targets another page.', 'anchor-schema' ) ],
                [ '[anchor_local_projects]', 'id', \__( 'Renders this page\'s Projects section. id targets another page.', 'anchor-schema' ) ],
            ],
        ];

        echo '<h3>' . \esc_html__( 'Shortcode reference', 'anchor-schema' ) . '</h3>';
        echo '<table class="widefat striped" style="max-width:60em">';
        echo '<thead><tr><th style="width:16em">' . \esc_html__( 'Shortcode', 'anchor-schema' ) . '</th><th style="width:14em">' . \esc_html__( 'Attributes', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'What it outputs', 'anchor-schema' ) . '</th></tr></thead><tbody>';
        foreach ( $groups as $label => $rows ) {
            echo '<tr><td colspan="3" style="background:#f0f0f1"><strong>' . \esc_html( $label ) . '</strong></td></tr>';
            foreach ( $rows as $r ) {
                echo '<tr><td><code>' . \esc_html( $r[0] ) . '</code></td><td>' . \esc_html( $r[1] ) . '</td><td>' . \esc_html( $r[2] ) . '</td></tr>';
            }
        }
        echo '</tbody></table>';

        echo '<p class="description" style="max-width:60em">' . \sprintf(
            /* translators: 1: id attribute, 2: sample service-page URL */
            \esc_html__( 'Every shortcode defaults to the current page, so one shared layout works for every location. The %1$s attribute lets you pin a shortcode to a specific location/service page from anywhere on the site (e.g. a homepage map or directory). On a service page such as %2$s, the location-oriented shortcodes automatically describe the linked location, not the service page itself.', 'anchor-schema' ),
            '<code>id</code>',
            '<code>/' . \esc_html( $sv ) . '/roofing/pittsburgh-pa/</code>'
        ) . '</p>';
    }

    /* ---- Admin: metaboxes, save, assets, columns ---- */

    public function add_metaboxes() {
        foreach ( [ self::CPT_LOCATION, self::CPT_SERVICE ] as $cpt ) {
            \add_meta_box( 'al_content', \__( 'Content (HTML / CSS / JS)', 'anchor-schema' ), [ $this, 'render_content_metabox' ], $cpt, 'normal', 'high' );
            \add_meta_box( 'al_details', \__( 'Details', 'anchor-schema' ), [ $this, 'render_details_metabox' ], $cpt, 'side', 'default' );
        }
    }

    public function render_content_metabox( $post ) {
        \wp_nonce_field( self::NONCE, self::NONCE );
        $html = \get_post_meta( $post->ID, 'al_html', true );
        $css  = \get_post_meta( $post->ID, 'al_css', true );
        $js   = \get_post_meta( $post->ID, 'al_js', true );
        $spec = [
            [ 'id' => 'al_html', 'label' => \__( 'HTML', 'anchor-schema' ), 'lang' => 'html' ],
            [ 'id' => 'al_css',  'label' => \__( 'CSS', 'anchor-schema' ),  'lang' => 'css'  ],
            [ 'id' => 'al_js',   'label' => \__( 'JS', 'anchor-schema' ),   'lang' => 'javascript' ],
        ];
        echo '<div class="anchor-monaco" data-anchor-monaco=\'' . \esc_attr( \wp_json_encode( $spec ) ) . '\'>';
        echo '<textarea id="al_html" name="al_html" style="display:none">' . \esc_textarea( $html ) . '</textarea>';
        echo '<textarea id="al_css" name="al_css" style="display:none">' . \esc_textarea( $css ) . '</textarea>';
        echo '<textarea id="al_js" name="al_js" style="display:none">' . \esc_textarea( $js ) . '</textarea>';
        echo '</div>';
        $dis = \get_post_meta( $post->ID, 'al_disable_wrapper', true );
        echo '<p><label><input type="checkbox" name="al_disable_wrapper" value="1" ' . \checked( $dis, '1', false ) . '> ' . \esc_html__( 'Disable global wrapper on this page (page-builder mode)', 'anchor-schema' ) . '</label></p>';
    }

    public function render_details_metabox( $post ) {
        if ( $post->post_type === self::CPT_SERVICE ) {
            $loc = (int) \get_post_meta( $post->ID, 'al_location_id', true );
            // Phase 5: pre-fill from the Coverage matrix "Add" link on a brand-new
            // (auto-draft) service page. Render-side default only — nothing is
            // written until the human saves; validated as an int.
            if ( $loc === 0 && $post->post_status === 'auto-draft' && isset( $_GET['al_prefill_location'] ) ) {
                $loc = (int) $_GET['al_prefill_location'];
            }
            echo '<p><label>' . \esc_html__( 'Linked Location (post ID)', 'anchor-schema' ) . '<br><input type="number" name="al_location_id" value="' . \esc_attr( $loc ) . '" class="widefat"></label></p>';
            echo '<p class="description">' . \esc_html__( 'Set the Service term via the Services box. Both are required for a live URL.', 'anchor-schema' ) . '</p>';
            return;
        }
        $f = function( $k ) use ( $post ) { return \esc_attr( \get_post_meta( $post->ID, $k, true ) ); };
        $types = [ 'state','county','city','township','borough','neighborhood','region' ];
        echo '<p><label>' . \esc_html__( 'Type', 'anchor-schema' ) . '<br><select name="al_type" class="widefat">';
        $cur = $f( 'al_type' );
        foreach ( $types as $t ) { echo '<option value="' . $t . '" ' . \selected( $cur, $t, false ) . '>' . \ucfirst( $t ) . '</option>'; }
        echo '</select></label></p>';
        echo '<p><label>' . \esc_html__( 'Latitude', 'anchor-schema' ) . '<br><input type="text" name="al_lat" value="' . $f('al_lat') . '" class="widefat"></label></p>';
        echo '<p><label>' . \esc_html__( 'Longitude', 'anchor-schema' ) . '<br><input type="text" name="al_lng" value="' . $f('al_lng') . '" class="widefat"></label></p>';
        echo '<p><label>' . \esc_html__( 'State abbr', 'anchor-schema' ) . '<br><input type="text" name="al_state_abbr" value="' . $f('al_state_abbr') . '" class="widefat"></label></p>';
        echo '<p><label>' . \esc_html__( 'Place ID', 'anchor-schema' ) . '<br><input type="text" name="al_place_id" value="' . $f('al_place_id') . '" class="widefat"></label></p>';
        echo '<p><label>' . \esc_html__( 'Postal codes', 'anchor-schema' ) . '<br><input type="text" name="al_postal_codes" value="' . $f('al_postal_codes') . '" class="widefat"></label></p>';
        echo '<p><label>' . \esc_html__( 'Marker icon URL', 'anchor-schema' ) . '<br><input type="text" name="al_marker_icon" value="' . $f('al_marker_icon') . '" class="widefat al-media"></label></p>';
        echo '<p><label>' . \esc_html__( 'Boundary GeoJSON', 'anchor-schema' ) . '<br><textarea name="al_boundary" class="widefat" rows="3">' . \esc_textarea( \get_post_meta( $post->ID, 'al_boundary', true ) ) . '</textarea></label></p>';
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! \wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) { return; }
        if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! \current_user_can( 'edit_post', $post_id ) ) { return; }
        $raw = [ 'al_html', 'al_css', 'al_js' ];               // code fields: keep as-is (unslashed)
        foreach ( $raw as $k ) { if ( isset( $_POST[ $k ] ) ) { \update_post_meta( $post_id, $k, \wp_unslash( $_POST[ $k ] ) ); } }
        $text = [ 'al_type','al_lat','al_lng','al_place_id','al_state_abbr','al_county','al_postal_codes','al_marker_icon' ];
        foreach ( $text as $k ) { if ( isset( $_POST[ $k ] ) ) { \update_post_meta( $post_id, $k, \sanitize_text_field( \wp_unslash( $_POST[ $k ] ) ) ); } }
        if ( isset( $_POST['al_location_id'] ) ) { \update_post_meta( $post_id, 'al_location_id', (int) $_POST['al_location_id'] ); }
        if ( isset( $_POST['al_boundary'] ) ) { \update_post_meta( $post_id, 'al_boundary', \wp_unslash( $_POST['al_boundary'] ) ); }
        \update_post_meta( $post_id, 'al_disable_wrapper', isset( $_POST['al_disable_wrapper'] ) ? '1' : '' );
    }

    public function admin_assets( $hook ) {
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) { return; }
        $screen = \get_current_screen();
        if ( ! $screen || ! \in_array( $screen->post_type, [ self::CPT_LOCATION, self::CPT_SERVICE ], true ) ) { return; }
        \Anchor_Monaco::enqueue( $screen->post_type );
        if ( \class_exists( '\\Anchor_Preview_CSS' ) ) { \Anchor_Preview_CSS::enqueue_for_admin(); }  // static; registers 'anchor-preview'
        $dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-locations/assets/';
        \wp_enqueue_style( 'anchor-locations-admin', \Anchor_Asset_Loader::url( 'anchor-locations/assets/admin.css' ), [], (string) \filemtime( $dir . 'admin.css' ) );
        \wp_enqueue_script( 'anchor-locations-admin', \Anchor_Asset_Loader::url( 'anchor-locations/assets/admin.js' ), [ 'jquery', 'anchor-monaco', 'anchor-preview' ], (string) \filemtime( $dir . 'admin.js' ), true );
    }

    public function location_columns( $c ) { $c['al_type'] = \__( 'Type', 'anchor-schema' ); return $c; }
    public function location_column( $col, $post_id ) { if ( $col === 'al_type' ) { echo \esc_html( \ucfirst( (string) \get_post_meta( $post_id, 'al_type', true ) ) ); } }
    public function service_columns( $c ) { $c['al_link'] = \__( 'Service / Location', 'anchor-schema' ); return $c; }
    public function service_column( $col, $post_id ) {
        if ( $col !== 'al_link' ) { return; }
        $terms = \wp_get_object_terms( $post_id, self::TAX_SERVICE, [ 'fields' => 'names' ] );
        $loc   = (int) \get_post_meta( $post_id, 'al_location_id', true );
        if ( empty( $terms ) || ! $loc || ! \get_post( $loc ) ) { echo \esc_html__( '⚠ incomplete', 'anchor-schema' ); return; }
        echo \esc_html( $terms[0] . ' — ' . \get_the_title( $loc ) );
    }

    /* ---- Frontend: body render, global wrapper, [anchor_page_content] ---- */

    /** Render a location/service page's Monaco HTML/CSS/JS, id-scoped so it's theme-agnostic. */
    public function render_body( $post_id ) {
        // Recursion guard: an operator's al_html can contain [anchor_page_content]
        // (no id, or an id resolving back to this same post), which would otherwise
        // recurse forever via do_shortcode() -> shortcode_page_content() -> render_body().
        if ( ! empty( $this->rendering[ $post_id ] ) ) { return ''; }
        $this->rendering[ $post_id ] = true;

        $html = (string) \get_post_meta( $post_id, 'al_html', true );
        $css  = (string) \get_post_meta( $post_id, 'al_css', true );
        $js   = (string) \get_post_meta( $post_id, 'al_js', true );
        $scope = 'al-page-' . (int) $post_id;
        $out = '<div class="anchor-locations-page ' . \esc_attr( $scope ) . '">';
        if ( $css !== '' ) {
            $scoped = \preg_replace( '/(^|\})\s*([^@\}\{]+)\{/', '$1 .' . $scope . ' $2{', $css );
            $out .= '<style>' . $scoped . '</style>';
        }
        $out .= \do_shortcode( $html );
        if ( $js !== '' ) { $out .= '<script>(function(){' . $js . '})();</script>'; }
        $out .= '</div>';

        unset( $this->rendering[ $post_id ] );
        return $out;
    }

    /** Wrap a rendered body in the global settings-defined wrapper template, unless disabled per-page. */
    public function apply_wrapper( $body, $post_id ) {
        if ( \get_post_meta( $post_id, 'al_disable_wrapper', true ) === '1' ) { return $body; }
        $s = $this->settings();
        $tpl_html = $s['wrapper_html'] ?? '';
        if ( \trim( $tpl_html ) === '' ) { return $body; }
        $tpl_css = $s['wrapper_css'] ?? '';
        $tpl_js  = $s['wrapper_js'] ?? '';
        $out = '';
        if ( \trim( $tpl_css ) !== '' ) { $out .= '<style>' . $tpl_css . '</style>'; }
        $filled = \str_replace( '{{content}}', $body, $tpl_html );
        $filled = \str_replace( '[anchor_page_content]', $body, $filled );
        $out .= \do_shortcode( $filled );
        if ( \trim( $tpl_js ) !== '' ) { $out .= '<script>(function(){' . $tpl_js . '})();</script>'; }
        return $out;
    }

    /** Replace the_content on location/service singular views with our rendered body + wrapper. */
    public function the_content_render( $content ) {
        if ( ! \is_singular( [ self::CPT_LOCATION, self::CPT_SERVICE ] ) || ! \in_the_loop() || ! \is_main_query() ) { return $content; }
        $post_id = \get_the_ID();
        $body = $this->render_body( $post_id );
        return $this->apply_wrapper( $body, $post_id );
    }

    /** [anchor_page_content id="N"] escape hatch for embedding a page's rendered body elsewhere (e.g. inside a wrapper template). */
    public function shortcode_page_content( $atts ) {
        $atts = \shortcode_atts( [ 'id' => 0 ], $atts, 'anchor_page_content' );
        $id = (int) $atts['id'] ? (int) $atts['id'] : \get_the_ID();
        if ( ! $id ) { return ''; }
        return $this->render_body( $id );
    }

    /* ---- Frontend: internal-linking, directory & breadcrumb shortcodes ---- */

    private function cur_id( $atts ) { $a = \shortcode_atts( [ 'id' => 0 ], $atts ); return (int) $a['id'] ? (int) $a['id'] : (int) \get_the_ID(); }

    /**
     * Resolve the LOCATION a location-oriented shortcode should describe.
     *
     * An explicit `id` attribute always wins. Otherwise, when the shortcode runs
     * on a service page (e.g. /services/roofing/pittsburgh-pa/), the subject is
     * the location that page is linked to — not the service page itself — so we
     * follow `al_location_id`. On a location page (or anywhere else) this is
     * just the current post.
     *
     * Returns 0 when there is nothing sensible to resolve (e.g. a service page
     * whose `al_location_id` is missing); callers must treat 0 as "no location"
     * and return empty rather than querying for it.
     */
    private function resolve_location_id( $atts ) {
        $a = \shortcode_atts( [ 'id' => 0 ], $atts );
        if ( (int) $a['id'] ) { return (int) $a['id']; }
        $id = (int) \get_the_ID();
        if ( $id && \get_post_type( $id ) === self::CPT_SERVICE ) {
            $loc = (int) \get_post_meta( $id, 'al_location_id', true );
            // Guard against a dangling link: an id pointing at a deleted or
            // non-location post must resolve to 0, not be queried for. A meta
            // lookup on a stale id would otherwise match this very service page
            // (its own al_location_id holds that value) and self-link it.
            return ( $loc && \get_post_type( $loc ) === self::CPT_LOCATION ) ? $loc : 0;
        }
        return $id;
    }

    /**
     * Label for a post's breadcrumb crumb and BreadcrumbList name: the post title.
     * Returns raw text — callers escape for their context.
     */
    public function crumb_label( $id ) {
        return (string) \get_the_title( $id );
    }

    public function sc_child_locations( $atts ) {
        $id = $this->resolve_location_id( $atts );
        if ( ! $id ) { return \apply_filters( 'anchor_locations_child_locations_html', '', $id ); }
        $kids = \get_posts( [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'post_parent' => $id, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
        $html = '';
        if ( $kids ) {
            $html = '<ul class="al-child-locations">';
            foreach ( $kids as $k ) { $html .= '<li><a href="' . \esc_url( \get_permalink( $k ) ) . '">' . \esc_html( \get_the_title( $k ) ) . '</a></li>'; }
            $html .= '</ul>';
        }
        return \apply_filters( 'anchor_locations_child_locations_html', $html, $id );
    }

    public function sc_parent( $atts ) {
        $id = $this->resolve_location_id( $atts );
        $post = $id ? \get_post( $id ) : null;
        if ( ! $post ) { return \apply_filters( 'anchor_locations_location_parent_html', '', $id ); }
        $p = (int) $post->post_parent;
        $html = ( $p && \get_post_status( $p ) === 'publish' ) ? '<a class="al-parent" href="' . \esc_url( \get_permalink( $p ) ) . '">' . \esc_html( \get_the_title( $p ) ) . '</a>' : '';
        return \apply_filters( 'anchor_locations_location_parent_html', $html, $id );
    }

    public function sc_nearby( $atts ) {
        $id = $this->resolve_location_id( $atts );
        $post = $id ? \get_post( $id ) : null;
        if ( ! $post ) { return \apply_filters( 'anchor_locations_nearby_locations_html', '', $id ); }
        $parent = (int) $post->post_parent;
        $sibs = $parent ? \get_posts( [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'post_parent' => $parent, 'exclude' => [ $id ], 'numberposts' => 12, 'orderby' => 'title', 'order' => 'ASC' ] ) : [];
        $html = '';
        if ( $sibs ) {
            $html = '<ul class="al-nearby">';
            foreach ( $sibs as $s ) { $html .= '<li><a href="' . \esc_url( \get_permalink( $s ) ) . '">' . \esc_html( \get_the_title( $s ) ) . '</a></li>'; }
            $html .= '</ul>';
        }
        return \apply_filters( 'anchor_locations_nearby_locations_html', $html, $id );
    }

    public function sc_breadcrumbs( $atts ) {
        $id = $this->cur_id( $atts );
        $crumbs = [ '<a href="' . \esc_url( \home_url( '/' ) ) . '">' . \esc_html__( 'Home', 'anchor-schema' ) . '</a>' ];
        $post = \get_post( $id );
        if ( $post && $post->post_type === self::CPT_SERVICE ) {
            $loc = (int) \get_post_meta( $id, 'al_location_id', true );
            $anc = $loc ? \array_reverse( \get_post_ancestors( $loc ) ) : [];
            foreach ( $anc as $aid ) { if ( \get_post_status( $aid ) !== 'publish' ) { continue; } $crumbs[] = '<a href="' . \esc_url( \get_permalink( $aid ) ) . '">' . \esc_html( $this->crumb_label( $aid ) ) . '</a>'; }
            if ( $loc && \get_post_status( $loc ) === 'publish' ) { $crumbs[] = '<a href="' . \esc_url( \get_permalink( $loc ) ) . '">' . \esc_html( $this->crumb_label( $loc ) ) . '</a>'; }
            $crumbs[] = \esc_html( $this->crumb_label( $id ) );
        } elseif ( $post ) {
            foreach ( \array_reverse( \get_post_ancestors( $id ) ) as $aid ) { if ( \get_post_status( $aid ) !== 'publish' ) { continue; } $crumbs[] = '<a href="' . \esc_url( \get_permalink( $aid ) ) . '">' . \esc_html( $this->crumb_label( $aid ) ) . '</a>'; }
            $crumbs[] = \esc_html( $this->crumb_label( $id ) );
        }
        $html = '<nav class="al-breadcrumbs">' . \implode( ' <span class="sep">&rsaquo;</span> ', $crumbs ) . '</nav>';
        return \apply_filters( 'anchor_locations_breadcrumbs_html', $html, $id );
    }

    public function sc_location_services( $atts ) {
        $id = $this->resolve_location_id( $atts );
        if ( ! $id ) { return \apply_filters( 'anchor_locations_location_services_html', '', $id ); }
        $pages = \get_posts( [ 'post_type' => self::CPT_SERVICE, 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => 'al_location_id', 'meta_value' => $id ] );
        $html = '';
        if ( $pages ) {
            $html = '<ul class="al-location-services">';
            foreach ( $pages as $p ) { $html .= '<li><a href="' . \esc_url( $this->service_page_url( $p->ID ) ) . '">' . \esc_html( \get_the_title( $p ) ) . '</a></li>'; }
            $html .= '</ul>';
        }
        return \apply_filters( 'anchor_locations_location_services_html', $html, $id );
    }

    public function sc_service_locations( $atts ) {
        $id = $this->cur_id( $atts );
        $terms = \wp_get_object_terms( $id, self::TAX_SERVICE, [ 'fields' => 'ids' ] );
        $html = '';
        if ( ! \is_wp_error( $terms ) && $terms ) {
            $pages = \get_posts( [ 'post_type' => self::CPT_SERVICE, 'post_status' => 'publish', 'numberposts' => -1, 'exclude' => [ $id ], 'tax_query' => [ [ 'taxonomy' => self::TAX_SERVICE, 'field' => 'term_id', 'terms' => $terms ] ] ] );
            if ( $pages ) {
                $html = '<ul class="al-service-locations">';
                foreach ( $pages as $p ) { $html .= '<li><a href="' . \esc_url( $this->service_page_url( $p->ID ) ) . '">' . \esc_html( \get_the_title( $p ) ) . '</a></li>'; }
                $html .= '</ul>';
            }
        }
        return \apply_filters( 'anchor_locations_service_locations_html', $html, $id );
    }

    public function sc_directory( $atts ) {
        // Cache only the expensive recursive tree build; the filter still runs on
        // every call so dynamically-attached filters keep working uncached.
        $html = $this->cached( 'al_dir_', (array) $atts, function () {
            $roots = \get_posts( [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'post_parent' => 0, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
            return '<div class="al-directory">' . $this->directory_branch( $roots ) . '</div>';
        } );
        return \apply_filters( 'anchor_locations_service_area_directory_html', $html, 0 );
    }
    private function directory_branch( $nodes ) {
        if ( ! $nodes ) { return ''; }
        $out = '<ul>';
        foreach ( $nodes as $n ) {
            $kids = \get_posts( [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'post_parent' => $n->ID, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
            $out .= '<li><a href="' . \esc_url( \get_permalink( $n ) ) . '">' . \esc_html( \get_the_title( $n ) ) . '</a>' . $this->directory_branch( $kids ) . '</li>';
        }
        return $out . '</ul>';
    }

    /* ---- Frontend: Google map [anchor_location_map] ---- */

    public function get_google_api_key() {
        if ( ! \class_exists( '\\Anchor_Schema_Admin' ) ) { return ''; }
        $opts = \get_option( \Anchor_Schema_Admin::OPTION_KEY, [] );
        return isset( $opts['google_api_key'] ) ? \sanitize_text_field( $opts['google_api_key'] ) : '';
    }

    /* ---- Phase 8: versioned relationship-query caching ---- */

    /** Short TTL backstop; correctness comes from the version-busted key, not expiry. */
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /** Build a versioned transient key from a base prefix + the call's args. */
    private static function cache_key( $base, array $args ) {
        $ver = \class_exists( '\\Anchor\\Locations\\Integrity' ) ? Integrity::cache_version() : 0;
        return $base . $ver . '_' . \md5( (string) \wp_json_encode( $args ) );
    }

    /** Public seam so tests can assert a query cached/read under the current version. */
    public function map_cache_key( array $args = [] ) { return self::cache_key( 'al_mapdata_', $args ); }
    public function directory_cache_key( array $atts = [] ) { return self::cache_key( 'al_dir_', $atts ); }

    /**
     * Versioned transient wrapper. Bypasses the cache entirely when the version
     * option is absent (version 0), so behavior is identical to pre-Phase-8 until
     * the first relationship-graph write bumps the version. get_transient()'s
     * false-miss sentinel is unambiguous here: callers only ever cache arrays
     * (map_data) or strings (directory), never a literal false.
     */
    private function cached( $base, array $args, callable $compute ) {
        $ver = \class_exists( '\\Anchor\\Locations\\Integrity' ) ? Integrity::cache_version() : 0;
        if ( $ver <= 0 ) { return $compute(); }
        $key = $base . $ver . '_' . \md5( (string) \wp_json_encode( $args ) );
        $hit = \get_transient( $key );
        if ( $hit !== false ) { return $hit; }
        $val = $compute();
        \set_transient( $key, $val, self::CACHE_TTL );
        return $val;
    }

    public function map_data( $args = [] ) {
        return $this->cached( 'al_mapdata_', (array) $args, function () use ( $args ) {
            return $this->compute_map_data( $args );
        } );
    }

    /** Uncached body of map_data() — the expensive location×service graph walk. */
    private function compute_map_data( $args = [] ) {
        $types  = isset( $args['types'] ) ? (array) $args['types'] : [];
        $parent = isset( $args['parent'] ) ? (int) $args['parent'] : 0;

        // Resolve the optional service filter to a term slug. Accepts a slug or a
        // numeric term id; anything that doesn't resolve leaves $service_slug empty
        // (== no filter) rather than filtering everything out.
        $service_slug = '';
        if ( isset( $args['service'] ) && $args['service'] !== '' ) {
            $raw = $args['service'];
            if ( \is_numeric( $raw ) ) {
                $term = \get_term( (int) $raw, self::TAX_SERVICE );
                $service_slug = ( $term && ! \is_wp_error( $term ) ) ? $term->slug : '';
            } else {
                $service_slug = \sanitize_title( (string) $raw );
            }
        }

        $q = [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'numberposts' => -1, 'meta_query' => [ [ 'key' => 'al_lat', 'value' => '', 'compare' => '!=' ] ] ];
        if ( $parent ) { $q['post_parent'] = $parent; }
        $out = [];
        foreach ( \get_posts( $q ) as $p ) {
            $lat = \get_post_meta( $p->ID, 'al_lat', true ); $lng = \get_post_meta( $p->ID, 'al_lng', true );
            if ( $lat === '' || $lng === '' ) { continue; }
            $type = (string) \get_post_meta( $p->ID, 'al_type', true );
            if ( $types && ! \in_array( $type, $types, true ) ) { continue; }
            $services = [];
            $matches_service = false;
            foreach ( \get_posts( [ 'post_type' => self::CPT_SERVICE, 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => 'al_location_id', 'meta_value' => $p->ID ] ) as $sp ) {
                $slugs = \wp_get_object_terms( $sp->ID, self::TAX_SERVICE, [ 'fields' => 'slugs' ] );
                if ( \is_wp_error( $slugs ) ) { $slugs = []; }
                if ( $service_slug !== '' && \in_array( $service_slug, $slugs, true ) ) { $matches_service = true; }
                $services[] = [ 'title' => \get_the_title( $sp ), 'url' => $this->service_page_url( $sp->ID ), 'service_slugs' => \array_values( $slugs ) ];
            }
            // Server-side service pre-filter: drop locations with no matching page.
            if ( $service_slug !== '' && ! $matches_service ) { continue; }

            $icon = \get_post_meta( $p->ID, 'al_marker_icon', true );
            if ( ! $icon ) { $s = $this->settings(); $icon = $s['marker_icon'] ?? ''; }
            $marker = [ 'id' => $p->ID, 'title' => \get_the_title( $p ), 'url' => \get_permalink( $p ), 'lat' => (float) $lat, 'lng' => (float) $lng, 'icon' => $icon, 'type' => $type, 'services' => $services ];

            // Attach the saved boundary GeoJSON only when it parses to valid JSON;
            // invalid strings are skipped so a bad paste never breaks the map.
            $boundary_raw = \get_post_meta( $p->ID, 'al_boundary', true );
            if ( \is_string( $boundary_raw ) && \trim( $boundary_raw ) !== '' ) {
                $decoded = \json_decode( $boundary_raw, true );
                if ( $decoded !== null && \json_last_error() === JSON_ERROR_NONE ) {
                    $marker['boundary'] = $decoded;
                }
            }

            $out[] = $marker;
        }
        return $out;
    }

    // Pinned @googlemaps/markerclusterer UMD build (jsDelivr). Not vendored because
    // *.min.js is gitignored; loaded from CDN only when a map opts into clustering.
    const MARKERCLUSTERER_VER = '2.5.3';
    const MARKERCLUSTERER_URL  = 'https://cdn.jsdelivr.net/npm/@googlemaps/markerclusterer@2.5.3/dist/index.min.js';

    public function sc_map( $atts ) {
        $a = \shortcode_atts( [ 'types' => '', 'parent' => 0, 'zoom' => '', 'height' => '480', 'center' => '', 'cluster' => '', 'service' => '', 'filters' => '', 'focus' => '', 'iconsize' => '' ], $atts, 'anchor_location_map' );
        $args = [];
        if ( $a['types'] !== '' ) { $args['types'] = \array_map( 'trim', \explode( ',', $a['types'] ) ); }
        if ( (int) $a['parent'] ) { $args['parent'] = (int) $a['parent']; }
        if ( $a['service'] !== '' ) { $args['service'] = $a['service']; }
        $markers = $this->map_data( $args );
        $s = $this->settings();

        $cluster = \filter_var( $a['cluster'], FILTER_VALIDATE_BOOLEAN );
        $filters = [];
        if ( $a['filters'] !== '' ) {
            foreach ( \array_map( 'trim', \explode( ',', $a['filters'] ) ) as $f ) {
                if ( \in_array( $f, [ 'service', 'type' ], true ) ) { $filters[] = $f; }
            }
        }

        $cfg = [
            'markers'   => $markers,
            'zoom'      => $a['zoom'] !== '' ? (int) $a['zoom'] : (int) ( $s['map_zoom'] ?? 8 ),
            'center'    => $a['center'] !== '' ? $a['center'] : ( ( $s['map_center'] ?? '' ) ?: '' ),
            'cluster'   => $cluster,
            'filters'   => $filters,
            'focus'     => $this->resolve_map_focus( $a['focus'] ),
            'icon_size' => $this->map_icon_size( $a['iconsize'] ),
        ];
        $this->enqueue_map_assets( $cluster );
        $uid = 'al-map-' . ( ++self::$map_seq );
        $json = \esc_attr( \wp_json_encode( $cfg ) );
        return '<div id="' . $uid . '" class="al-map" style="height:' . (int) $a['height'] . 'px" data-al-map="' . $json . '"></div>';
    }

    /**
     * Resolve the viewport-focus location for a map — the area the map should
     * frame. Returns [ lat, lng, zoom, boundary? ] or null for "frame all markers".
     *
     * Default (empty `focus` attr): on a singular location/service page, focus the
     * current page's location (a service page follows its linked location via
     * resolve_location_id()), so the map frames the area the page is about instead
     * of the whole marker set. `focus="none"` opts out; `focus="123"` targets a
     * specific location by ID (handy for a homepage/overview map).
     *
     * Zoom is derived from the location's type (a state frames wider than a city);
     * when the location has a boundary polygon the client fits to that instead.
     */
    private function resolve_map_focus( $att ) {
        $att = \trim( (string) $att );
        $id  = 0;
        if ( $att === '' ) {
            if ( \is_singular( [ self::CPT_LOCATION, self::CPT_SERVICE ] ) ) { $id = $this->resolve_location_id( [] ); }
        } elseif ( \is_numeric( $att ) ) {
            $id = (int) $att;
        } elseif ( \in_array( \strtolower( $att ), [ 'current', 'auto', '1', 'true', 'yes' ], true ) ) {
            $id = $this->resolve_location_id( [] );
        }
        // 'none'/'0'/'false'/unrecognized => 0 (frame all markers).
        if ( ! $id || \get_post_type( $id ) !== self::CPT_LOCATION ) { return null; }

        $lat = \get_post_meta( $id, 'al_lat', true );
        $lng = \get_post_meta( $id, 'al_lng', true );
        if ( $lat === '' || $lng === '' ) { return null; }

        $type  = (string) \get_post_meta( $id, 'al_type', true );
        $zooms = [ 'state' => 7, 'region' => 9, 'county' => 9, 'city' => 11, 'township' => 11, 'borough' => 11, 'neighborhood' => 13 ];
        $focus = [ 'lat' => (float) $lat, 'lng' => (float) $lng, 'zoom' => $zooms[ $type ] ?? 10 ];

        $b = \get_post_meta( $id, 'al_boundary', true );
        if ( \is_string( $b ) && \trim( $b ) !== '' ) {
            $dec = \json_decode( $b, true );
            if ( $dec !== null && \json_last_error() === JSON_ERROR_NONE ) { $focus['boundary'] = $dec; }
        }
        return $focus;
    }

    /**
     * Clamp the marker-icon max dimension (px). Empty/out-of-range => 40, a sane
     * default that keeps a brand pin from dominating the map (custom marker images
     * are otherwise rendered at their full natural size).
     */
    private function map_icon_size( $att ) {
        $n = (int) $att;
        return ( $n >= 8 && $n <= 200 ) ? $n : 40;
    }

    private $cluster_enqueued = false;

    /**
     * Enqueue Maps + frontend JS directly (store-locator pattern). Shortcodes run
     * before wp_footer. When any map on the page requests clustering, the
     * MarkerClusterer UMD is enqueued from the CDN as a dependency of frontend.js.
     */
    public function enqueue_map_assets( $cluster = false ) {
        // The clustering library may need enqueuing even if base assets already
        // were (an earlier non-cluster map on the same page), so guard it separately.
        if ( $cluster && ! $this->cluster_enqueued ) {
            $this->cluster_enqueued = true;
            \wp_enqueue_script( 'anchor-locations-markerclusterer', self::MARKERCLUSTERER_URL, [], self::MARKERCLUSTERER_VER, true );
        }
        if ( $this->assets_enqueued ) {
            if ( $cluster ) {
                // Ensure the already-registered frontend script depends on the clusterer.
                $frontend = \wp_scripts()->query( 'anchor-locations-frontend' );
                if ( $frontend && ! \in_array( 'anchor-locations-markerclusterer', (array) $frontend->deps, true ) ) {
                    $frontend->deps[] = 'anchor-locations-markerclusterer';
                }
            }
            return;
        }
        $this->assets_enqueued = true;
        $dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-locations/assets/';
        \wp_enqueue_style( 'anchor-locations', \Anchor_Asset_Loader::url( 'anchor-locations/assets/frontend.css' ), [], (string) \filemtime( $dir . 'frontend.css' ) );
        $deps = [];
        $key = $this->get_google_api_key();
        if ( $key ) {
            \wp_enqueue_script( 'anchor-locations-gmaps', 'https://maps.googleapis.com/maps/api/js?key=' . \rawurlencode( $key ) . '&libraries=marker', [], null, true );
            $deps[] = 'anchor-locations-gmaps';
        }
        if ( $cluster ) { $deps[] = 'anchor-locations-markerclusterer'; }
        \wp_enqueue_script( 'anchor-locations-frontend', \Anchor_Asset_Loader::url( 'anchor-locations/assets/frontend.js' ), $deps, (string) \filemtime( $dir . 'frontend.js' ), true );
    }

    /** Map an al_type meta value to the schema.org Place subtype it should render as. */
    private static function place_type( $al_type ) {
        switch ( $al_type ) {
            case 'state': case 'county': return 'AdministrativeArea';
            case 'city': case 'borough': case 'township': return 'City';
            default: return 'Place';
        }
    }

    /**
     * Stable JSON-LD `@id` for a location/service page's main entity node.
     *
     * Used by build_schema() for the main Place/Service node on wp_head.
     * (Formerly also shared with the now-removed content-library Review/
     * AggregateRating node — that schema is no longer emitted here; the site's
     * SEO plugin is responsible for review-schema output.)
     *
     * @param int $post_id Location or service-page post ID.
     * @return string e.g. "https://site/foo/#service" or ".../#place".
     */
    public static function entity_id( int $post_id ): string {
        $frag = ( \get_post_type( $post_id ) === self::CPT_SERVICE ) ? 'service' : 'place';
        return \get_permalink( $post_id ) . '#' . $frag;
    }

    /**
     * The schema.org `@type` of a page's main entity node — 'Service' for a
     * service page, else the location's Place subtype. Kept in lockstep with
     * build_schema() so the review node (which reuses this) shares the type.
     *
     * @param int $post_id Location or service-page post ID.
     * @return string
     */
    public static function entity_type( int $post_id ): string {
        if ( \get_post_type( $post_id ) === self::CPT_SERVICE ) { return 'Service'; }
        return self::place_type( (string) \get_post_meta( $post_id, 'al_type', true ) );
    }

    /**
     * Build the `@graph` array (BreadcrumbList + Service/Place|City|AdministrativeArea)
     * for a location or service page. Public so it can be unit tested directly.
     */
    public function build_schema( $post_id ) {
        $post = \get_post( $post_id );
        if ( ! $post ) { return []; }
        $graph = [];

        // Breadcrumb
        $items = []; $pos = 1;
        $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => 'Home', 'item' => \home_url( '/' ) ];
        if ( $post->post_type === self::CPT_SERVICE ) {
            $loc = (int) \get_post_meta( $post_id, 'al_location_id', true );
            $chain = $loc ? \array_reverse( \get_post_ancestors( $loc ) ) : [];
            foreach ( $chain as $aid ) {
                if ( \get_post_status( $aid ) !== 'publish' ) { continue; }
                $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => $this->crumb_label( $aid ), 'item' => \get_permalink( $aid ) ];
            }
            if ( $loc && \get_post_status( $loc ) === 'publish' ) {
                $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => $this->crumb_label( $loc ), 'item' => \get_permalink( $loc ) ];
            }
            $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => $this->crumb_label( $post_id ), 'item' => $this->service_page_url( $post_id ) ];
        } else {
            foreach ( \array_reverse( \get_post_ancestors( $post_id ) ) as $aid ) {
                if ( \get_post_status( $aid ) !== 'publish' ) { continue; }
                $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => $this->crumb_label( $aid ), 'item' => \get_permalink( $aid ) ];
            }
            $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => $this->crumb_label( $post_id ), 'item' => \get_permalink( $post_id ) ];
        }
        $graph[] = [ '@type' => 'BreadcrumbList', 'itemListElement' => $items ];

        if ( $post->post_type === self::CPT_LOCATION ) {
            $lat = \get_post_meta( $post_id, 'al_lat', true ); $lng = \get_post_meta( $post_id, 'al_lng', true );
            $node = [ '@type' => self::place_type( (string) \get_post_meta( $post_id, 'al_type', true ) ), '@id' => self::entity_id( $post_id ), 'name' => \get_the_title( $post_id ), 'url' => \get_permalink( $post_id ) ];
            if ( $lat !== '' && $lng !== '' ) { $node['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng ]; }
            $graph[] = $node;
        } else {
            $terms = \wp_get_object_terms( $post_id, self::TAX_SERVICE, [ 'fields' => 'names' ] );
            $loc = (int) \get_post_meta( $post_id, 'al_location_id', true );
            $node = [
                '@type'       => 'Service',
                '@id'         => self::entity_id( $post_id ),
                'name'        => \get_the_title( $post_id ),
                'serviceType' => ! \is_wp_error( $terms ) && $terms ? $terms[0] : '',
                'url'         => $this->service_page_url( $post_id ),
                'provider'    => [ '@type' => 'Organization', 'name' => \get_bloginfo( 'name' ), 'url' => \home_url( '/' ) ],
            ];
            if ( $loc ) {
                // Deliberately no PostalAddress here: a service-area location is not
                // a staffed office, so areaServed only ever carries type + name.
                $node['areaServed'] = [ '@type' => self::place_type( (string) \get_post_meta( $loc, 'al_type', true ) ), 'name' => \get_the_title( $loc ) ];
            }
            $graph[] = $node;
        }
        return $graph;
    }

    /** Echo the JSON-LD `<script>` block for location/service pages on wp_head. */
    public function print_schema() {
        if ( ! \is_singular( [ self::CPT_LOCATION, self::CPT_SERVICE ] ) ) { return; }
        $graph = $this->build_schema( \get_the_ID() );
        if ( ! $graph ) { return; }
        $doc = [ '@context' => 'https://schema.org', '@graph' => $graph ];
        // security: deliberately no JSON_UNESCAPED_SLASHES — see the fix in
        // includes/class-anchor-schema-helper.php (commit 677b598) and the same
        // pattern in anchor-events-manager.php. The default `/` -> `\/` escaping
        // is what keeps a literal "</script>" in a post title / term name from
        // breaking out of the inline <script type="application/ld+json"> tag
        // this is echoed into; it decodes back to plain "/" for any JSON
        // consumer, so it doesn't affect the parsed values.
        $json = \wp_json_encode( $doc, JSON_UNESCAPED_UNICODE );
        // Defensive final guard in case any future value source bypasses the
        // slash-escaping above (matches Anchor_Schema_Render's belt-and-braces).
        $json = \str_replace( '</', '<\/', $json );
        echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n";
    }

    /**
     * Serve the plugin's full-width single template for location/service pages when
     * the `fullwidth_template` setting is on (relocated from the removed SEO class —
     * it is layout, not SEO). Falls through to the theme's template otherwise.
     */
    public function fullwidth_template( $template ) {
        $s = $this->settings();
        if ( empty( $s['fullwidth_template'] ) ) { return $template; }
        if ( ! \is_singular( [ self::CPT_LOCATION, self::CPT_SERVICE ] ) ) { return $template; }
        $tpl = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-locations/templates/single-anchor-fullwidth.php';
        return \file_exists( $tpl ) ? $tpl : $template;
    }
}
