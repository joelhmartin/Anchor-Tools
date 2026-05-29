<?php
/**
 * Anchor Post Display — editable display CPT.
 *
 * Each anchor_post_display post is a reusable, configurable post grid/slider,
 * edited through the shared gallery-style builder UI and embedded with
 * [anchor_post_grid id="123"]. Mirrors the anchor_gallery CPT pattern.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_APD_Display_CPT {

    const CPT     = 'anchor_post_display';
    const NONCE   = 'apd_cpt_nonce';
    const VERSION = '2.0.0';

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_filter( 'manage_' . self::CPT . '_posts_columns', [ $this, 'admin_columns' ] );
        add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'admin_column_content' ], 10, 2 );
    }

    /* ================================================================
       CPT registration
       ================================================================ */

    public function register_cpt() {
        register_post_type( self::CPT, [
            'labels' => [
                'name'          => 'Post Displays',
                'singular_name' => 'Post Display',
                'add_new_item'  => 'Add New Post Display',
                'edit_item'     => 'Edit Post Display',
                'menu_name'     => 'Post Displays',
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => apply_filters( 'anchor_post_display_parent_menu', true ),
            'menu_icon'    => 'dashicons-grid-view',
            'supports'     => [ 'title' ],
        ] );
    }

    /* ================================================================
       Admin columns
       ================================================================ */

    public function admin_columns( $columns ) {
        $new = [];
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( 'title' === $k ) {
                $new['apd_layout']    = 'Layout';
                $new['apd_shortcode'] = 'Shortcode';
            }
        }
        return $new;
    }

    public function admin_column_content( $column, $post_id ) {
        if ( 'apd_layout' === $column ) {
            echo esc_html( ucfirst( get_post_meta( $post_id, 'apd_layout', true ) ?: 'grid' ) );
        } elseif ( 'apd_shortcode' === $column ) {
            echo '<code>[anchor_post_grid id="' . intval( $post_id ) . '"]</code>';
        }
    }
}
