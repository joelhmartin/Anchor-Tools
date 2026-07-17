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
    }

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
}
