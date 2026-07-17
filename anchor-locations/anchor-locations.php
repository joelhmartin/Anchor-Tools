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
}
