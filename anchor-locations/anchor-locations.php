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

    public function __construct() {
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

        \add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 65 );
        \add_action( 'admin_init', [ $this, 'register_settings' ] );
        \add_action( 'anchor_settings_enqueue_locations', [ $this, 'settings_assets' ] );
    }

    private $assets_enqueued = false;

    public function register_types() {
        \register_post_type( self::CPT_LOCATION, [
            'labels'       => [ 'name' => 'Locations', 'singular_name' => 'Location', 'menu_name' => 'Anchor Locations', 'add_new_item' => 'Add New Location', 'edit_item' => 'Edit Location' ],
            'public'       => true,
            'hierarchical' => true,
            'show_in_menu' => \apply_filters( 'anchor_locations_parent_menu', true ),
            'menu_icon'    => 'dashicons-location-alt',
            'supports'     => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
            'rewrite'      => [ 'slug' => $this->service_areas_base(), 'with_front' => false ],
            'has_archive'  => false,
        ] );

        \register_post_type( self::CPT_SERVICE, [
            'labels'       => [ 'name' => 'Service Pages', 'singular_name' => 'Service Page', 'add_new_item' => 'Add New Service Page', 'edit_item' => 'Edit Service Page' ],
            'public'       => true,
            'hierarchical' => false,
            'show_in_menu' => 'edit.php?post_type=' . self::CPT_LOCATION,
            'supports'     => [ 'title', 'editor', 'thumbnail' ],
            'rewrite'      => false,
            'has_archive'  => false,
        ] );

        \register_taxonomy( self::TAX_SERVICE, self::CPT_SERVICE, [
            'labels'       => [ 'name' => 'Services', 'singular_name' => 'Service' ],
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
        $loc = \get_page_by_path( $loc_slug, OBJECT, self::CPT_LOCATION );
        if ( ! $loc ) { return 0; }
        $q = new \WP_Query( [
            'post_type'      => self::CPT_SERVICE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => [ [ 'taxonomy' => self::TAX_SERVICE, 'field' => 'slug', 'terms' => $service_slug ] ],
            'meta_query'     => [ [ 'key' => 'al_location_id', 'value' => $loc->ID ] ],
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

    /** Enqueue the shared Monaco editor for the global wrapper HTML/CSS/JS fields. */
    public function settings_assets( $hook ) {
        \Anchor_Monaco::enqueue( 'anchor_locations_settings' );
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
        echo '<div class="anchor-monaco" data-anchor-monaco="' . \esc_attr( $spec ) . '">';
        echo '<textarea id="al_wrapper_html" name="' . \esc_attr( $opt ) . '[wrapper_html]" style="display:none">' . \esc_textarea( $s['wrapper_html'] ?? '' ) . '</textarea>';
        echo '<textarea id="al_wrapper_css" name="' . \esc_attr( $opt ) . '[wrapper_css]" style="display:none">' . \esc_textarea( $s['wrapper_css'] ?? '' ) . '</textarea>';
        echo '<textarea id="al_wrapper_js" name="' . \esc_attr( $opt ) . '[wrapper_js]" style="display:none">' . \esc_textarea( $s['wrapper_js'] ?? '' ) . '</textarea>';
        echo '</div>';

        \submit_button();
        echo '</form>';
    }

    /* ---- Admin: metaboxes, save, assets, columns ---- */

    public function add_metaboxes() {
        foreach ( [ self::CPT_LOCATION, self::CPT_SERVICE ] as $cpt ) {
            \add_meta_box( 'al_content', 'Content (HTML / CSS / JS)', [ $this, 'render_content_metabox' ], $cpt, 'normal', 'high' );
            \add_meta_box( 'al_details', 'Details', [ $this, 'render_details_metabox' ], $cpt, 'side', 'default' );
        }
    }

    public function render_content_metabox( $post ) {
        \wp_nonce_field( self::NONCE, self::NONCE );
        $html = \get_post_meta( $post->ID, 'al_html', true );
        $css  = \get_post_meta( $post->ID, 'al_css', true );
        $js   = \get_post_meta( $post->ID, 'al_js', true );
        $spec = [
            [ 'id' => 'al_html', 'label' => 'HTML', 'lang' => 'html' ],
            [ 'id' => 'al_css',  'label' => 'CSS',  'lang' => 'css'  ],
            [ 'id' => 'al_js',   'label' => 'JS',   'lang' => 'javascript' ],
        ];
        echo '<div class="anchor-monaco" data-anchor-monaco="' . \esc_attr( \wp_json_encode( $spec ) ) . '">';
        echo '<textarea id="al_html" name="al_html" style="display:none">' . \esc_textarea( $html ) . '</textarea>';
        echo '<textarea id="al_css" name="al_css" style="display:none">' . \esc_textarea( $css ) . '</textarea>';
        echo '<textarea id="al_js" name="al_js" style="display:none">' . \esc_textarea( $js ) . '</textarea>';
        echo '</div>';
        $dis = \get_post_meta( $post->ID, 'al_disable_wrapper', true );
        echo '<p><label><input type="checkbox" name="al_disable_wrapper" value="1" ' . \checked( $dis, '1', false ) . '> Disable global wrapper on this page (Divi/builder mode)</label></p>';
    }

    public function render_details_metabox( $post ) {
        if ( $post->post_type === self::CPT_SERVICE ) {
            $loc = (int) \get_post_meta( $post->ID, 'al_location_id', true );
            echo '<p><label>Linked Location (post ID)<br><input type="number" name="al_location_id" value="' . \esc_attr( $loc ) . '" class="widefat"></label></p>';
            echo '<p class="description">Set the Service term via the Services box. Both are required for a live URL.</p>';
            return;
        }
        $f = function( $k ) use ( $post ) { return \esc_attr( \get_post_meta( $post->ID, $k, true ) ); };
        $types = [ 'state','county','city','township','borough','neighborhood','region' ];
        echo '<p><label>Type<br><select name="al_type" class="widefat">';
        $cur = $f( 'al_type' );
        foreach ( $types as $t ) { echo '<option value="' . $t . '" ' . \selected( $cur, $t, false ) . '>' . \ucfirst( $t ) . '</option>'; }
        echo '</select></label></p>';
        echo '<p><label>Latitude<br><input type="text" name="al_lat" value="' . $f('al_lat') . '" class="widefat"></label></p>';
        echo '<p><label>Longitude<br><input type="text" name="al_lng" value="' . $f('al_lng') . '" class="widefat"></label></p>';
        echo '<p><label>State abbr<br><input type="text" name="al_state_abbr" value="' . $f('al_state_abbr') . '" class="widefat"></label></p>';
        echo '<p><label>Place ID<br><input type="text" name="al_place_id" value="' . $f('al_place_id') . '" class="widefat"></label></p>';
        echo '<p><label>Postal codes<br><input type="text" name="al_postal_codes" value="' . $f('al_postal_codes') . '" class="widefat"></label></p>';
        echo '<p><label>Marker icon URL<br><input type="text" name="al_marker_icon" value="' . $f('al_marker_icon') . '" class="widefat al-media"></label></p>';
        echo '<p><label>Boundary GeoJSON<br><textarea name="al_boundary" class="widefat" rows="3">' . \esc_textarea( \get_post_meta( $post->ID, 'al_boundary', true ) ) . '</textarea></label></p>';
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
        \delete_transient( 'anchor_locations_mapdata' );
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

    public function location_columns( $c ) { $c['al_type'] = 'Type'; return $c; }
    public function location_column( $col, $post_id ) { if ( $col === 'al_type' ) { echo \esc_html( \ucfirst( (string) \get_post_meta( $post_id, 'al_type', true ) ) ); } }
    public function service_columns( $c ) { $c['al_link'] = 'Service / Location'; return $c; }
    public function service_column( $col, $post_id ) {
        if ( $col !== 'al_link' ) { return; }
        $terms = \wp_get_object_terms( $post_id, self::TAX_SERVICE, [ 'fields' => 'names' ] );
        $loc   = (int) \get_post_meta( $post_id, 'al_location_id', true );
        if ( empty( $terms ) || ! $loc || ! \get_post( $loc ) ) { echo '⚠ incomplete'; return; }
        echo \esc_html( $terms[0] . ' — ' . \get_the_title( $loc ) );
    }

    /* ---- Frontend: body render, global wrapper, [anchor_page_content] ---- */

    /** Render a location/service page's Monaco HTML/CSS/JS, id-scoped so it's theme-agnostic. */
    public function render_body( $post_id ) {
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

    public function sc_child_locations( $atts ) {
        $id = $this->cur_id( $atts );
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
        $id = $this->cur_id( $atts );
        $p = (int) \get_post( $id )->post_parent;
        $html = ( $p && \get_post_status( $p ) === 'publish' ) ? '<a class="al-parent" href="' . \esc_url( \get_permalink( $p ) ) . '">' . \esc_html( \get_the_title( $p ) ) . '</a>' : '';
        return \apply_filters( 'anchor_locations_location_parent_html', $html, $id );
    }

    public function sc_nearby( $atts ) {
        $id = $this->cur_id( $atts );
        $parent = (int) \get_post( $id )->post_parent;
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
            foreach ( $anc as $aid ) { if ( \get_post_status( $aid ) !== 'publish' ) { continue; } $crumbs[] = '<a href="' . \esc_url( \get_permalink( $aid ) ) . '">' . \esc_html( \get_the_title( $aid ) ) . '</a>'; }
            if ( $loc && \get_post_status( $loc ) === 'publish' ) { $crumbs[] = '<a href="' . \esc_url( \get_permalink( $loc ) ) . '">' . \esc_html( \get_the_title( $loc ) ) . '</a>'; }
            $crumbs[] = \esc_html( \get_the_title( $id ) );
        } elseif ( $post ) {
            foreach ( \array_reverse( \get_post_ancestors( $id ) ) as $aid ) { if ( \get_post_status( $aid ) !== 'publish' ) { continue; } $crumbs[] = '<a href="' . \esc_url( \get_permalink( $aid ) ) . '">' . \esc_html( \get_the_title( $aid ) ) . '</a>'; }
            $crumbs[] = \esc_html( \get_the_title( $id ) );
        }
        $html = '<nav class="al-breadcrumbs">' . \implode( ' <span class="sep">&rsaquo;</span> ', $crumbs ) . '</nav>';
        return \apply_filters( 'anchor_locations_breadcrumbs_html', $html, $id );
    }

    public function sc_location_services( $atts ) {
        $id = $this->cur_id( $atts );
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
        $roots = \get_posts( [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'post_parent' => 0, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
        $html = '<div class="al-directory">' . $this->directory_branch( $roots ) . '</div>';
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

    public function map_data( $args = [] ) {
        $types  = isset( $args['types'] ) ? (array) $args['types'] : [];
        $parent = isset( $args['parent'] ) ? (int) $args['parent'] : 0;
        $q = [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'numberposts' => -1, 'meta_query' => [ [ 'key' => 'al_lat', 'value' => '', 'compare' => '!=' ] ] ];
        if ( $parent ) { $q['post_parent'] = $parent; }
        $out = [];
        foreach ( \get_posts( $q ) as $p ) {
            $lat = \get_post_meta( $p->ID, 'al_lat', true ); $lng = \get_post_meta( $p->ID, 'al_lng', true );
            if ( $lat === '' || $lng === '' ) { continue; }
            $type = (string) \get_post_meta( $p->ID, 'al_type', true );
            if ( $types && ! \in_array( $type, $types, true ) ) { continue; }
            $services = [];
            foreach ( \get_posts( [ 'post_type' => self::CPT_SERVICE, 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => 'al_location_id', 'meta_value' => $p->ID ] ) as $sp ) {
                $services[] = [ 'title' => \get_the_title( $sp ), 'url' => $this->service_page_url( $sp->ID ) ];
            }
            $icon = \get_post_meta( $p->ID, 'al_marker_icon', true );
            if ( ! $icon ) { $s = $this->settings(); $icon = $s['marker_icon'] ?? ''; }
            $out[] = [ 'id' => $p->ID, 'title' => \get_the_title( $p ), 'url' => \get_permalink( $p ), 'lat' => (float) $lat, 'lng' => (float) $lng, 'icon' => $icon, 'services' => $services ];
        }
        return $out;
    }

    public function sc_map( $atts ) {
        $a = \shortcode_atts( [ 'types' => '', 'parent' => 0, 'zoom' => '', 'height' => '480', 'center' => '' ], $atts, 'anchor_location_map' );
        $args = [];
        if ( $a['types'] !== '' ) { $args['types'] = \array_map( 'trim', \explode( ',', $a['types'] ) ); }
        if ( (int) $a['parent'] ) { $args['parent'] = (int) $a['parent']; }
        $markers = $this->map_data( $args );
        $s = $this->settings();
        $cfg = [
            'markers' => $markers,
            'zoom'    => $a['zoom'] !== '' ? (int) $a['zoom'] : (int) ( $s['map_zoom'] ?? 8 ),
            'center'  => $a['center'] !== '' ? $a['center'] : ( ( $s['map_center'] ?? '' ) ?: '' ),
        ];
        $this->enqueue_map_assets();
        $uid = 'al-map-' . \wp_rand( 1000, 9999 );
        $json = \esc_attr( \wp_json_encode( $cfg ) );
        return '<div id="' . $uid . '" class="al-map" style="height:' . (int) $a['height'] . 'px" data-al-map="' . $json . '"></div>';
    }

    /** Enqueue Maps + frontend JS directly (store-locator pattern). Shortcodes run before wp_footer. */
    public function enqueue_map_assets() {
        if ( $this->assets_enqueued ) { return; }
        $this->assets_enqueued = true;
        $dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-locations/assets/';
        \wp_enqueue_style( 'anchor-locations', \Anchor_Asset_Loader::url( 'anchor-locations/assets/frontend.css' ), [], (string) \filemtime( $dir . 'frontend.css' ) );
        $deps = [];
        $key = $this->get_google_api_key();
        if ( $key ) {
            \wp_enqueue_script( 'anchor-locations-gmaps', 'https://maps.googleapis.com/maps/api/js?key=' . \rawurlencode( $key ) . '&libraries=marker', [], null, true );
            $deps[] = 'anchor-locations-gmaps';
        }
        \wp_enqueue_script( 'anchor-locations-frontend', \Anchor_Asset_Loader::url( 'anchor-locations/assets/frontend.js' ), $deps, (string) \filemtime( $dir . 'frontend.js' ), true );
    }

    /** Map an al_type meta value to the schema.org Place subtype it should render as. */
    private function place_type( $al_type ) {
        switch ( $al_type ) {
            case 'state': case 'county': return 'AdministrativeArea';
            case 'city': case 'borough': case 'township': return 'City';
            default: return 'Place';
        }
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
                $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => \get_the_title( $aid ), 'item' => \get_permalink( $aid ) ];
            }
            if ( $loc && \get_post_status( $loc ) === 'publish' ) {
                $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => \get_the_title( $loc ), 'item' => \get_permalink( $loc ) ];
            }
            $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => \get_the_title( $post_id ), 'item' => $this->service_page_url( $post_id ) ];
        } else {
            foreach ( \array_reverse( \get_post_ancestors( $post_id ) ) as $aid ) {
                if ( \get_post_status( $aid ) !== 'publish' ) { continue; }
                $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => \get_the_title( $aid ), 'item' => \get_permalink( $aid ) ];
            }
            $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => \get_the_title( $post_id ), 'item' => \get_permalink( $post_id ) ];
        }
        $graph[] = [ '@type' => 'BreadcrumbList', 'itemListElement' => $items ];

        if ( $post->post_type === self::CPT_LOCATION ) {
            $lat = \get_post_meta( $post_id, 'al_lat', true ); $lng = \get_post_meta( $post_id, 'al_lng', true );
            $node = [ '@type' => $this->place_type( (string) \get_post_meta( $post_id, 'al_type', true ) ), 'name' => \get_the_title( $post_id ), 'url' => \get_permalink( $post_id ) ];
            if ( $lat !== '' && $lng !== '' ) { $node['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng ]; }
            $graph[] = $node;
        } else {
            $terms = \wp_get_object_terms( $post_id, self::TAX_SERVICE, [ 'fields' => 'names' ] );
            $loc = (int) \get_post_meta( $post_id, 'al_location_id', true );
            $node = [
                '@type'       => 'Service',
                'name'        => \get_the_title( $post_id ),
                'serviceType' => ! \is_wp_error( $terms ) && $terms ? $terms[0] : '',
                'url'         => $this->service_page_url( $post_id ),
                'provider'    => [ '@type' => 'Organization', 'name' => \get_bloginfo( 'name' ), 'url' => \home_url( '/' ) ],
            ];
            if ( $loc ) {
                // Deliberately no PostalAddress here: a service-area location is not
                // a staffed office, so areaServed only ever carries type + name.
                $node['areaServed'] = [ '@type' => $this->place_type( (string) \get_post_meta( $loc, 'al_type', true ) ), 'name' => \get_the_title( $loc ) ];
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
}
