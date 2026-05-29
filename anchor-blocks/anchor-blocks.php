<?php
/**
 * Anchor Tools module: Anchor Blocks.
 *
 * Reusable HTML/CSS/JS content blocks placed via [anchor_block id=N].
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Blocks_Module {
    const CPT          = 'anchor_block';
    const NONCE        = 'anchor_block_nonce';
    const OPTION_KEY   = 'anchor_blocks_settings';
    const ASSET_VER    = '1.0.0';

    /** Per-request render state. */
    private $queued       = [];   // id => ['css' => string, 'js' => string]
    private $instances    = [];   // id => int (placement counter)
    private $printed_base = false;

    public function __construct() {
        add_action( 'init',                 [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes',       [ $this, 'add_metaboxes' ] );
        add_action( 'save_post',            [ $this, 'save_meta' ] );
        add_action( 'admin_enqueue_scripts',[ $this, 'admin_assets' ] );
        add_action( 'wp_footer',            [ $this, 'print_footer_assets' ], 20 );

        add_shortcode( 'anchor_block', [ $this, 'shortcode_render' ] );

        add_filter( 'manage_' . self::CPT . '_posts_columns',       [ $this, 'add_admin_columns' ] );
        add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );

        // Settings tab (site-wide preview stylesheet URLs).
        add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 95 );
        add_action( 'admin_init',           [ $this, 'register_settings' ] );
    }

    public function register_cpt() {
        $labels = [
            'name'          => 'Anchor Blocks',
            'singular_name' => 'Anchor Block',
            'add_new_item'  => 'Add New Block',
            'edit_item'     => 'Edit Block',
            'menu_name'     => 'Anchor Blocks',
        ];
        register_post_type( self::CPT, [
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => apply_filters( 'anchor_blocks_parent_menu', true ),
            'menu_icon'    => 'dashicons-layout',
            'supports'     => [ 'title' ],
        ] );
    }

    /* Metaboxes, save, assets, shortcode, columns, settings — added in later tasks. */
    public function add_metaboxes() {}
    public function save_meta( $post_id ) {}
    public function admin_assets( $hook ) {}
    public function print_footer_assets() {}
    public function shortcode_render( $atts ) { return ''; }
    public function add_admin_columns( $columns ) { return $columns; }
    public function render_admin_column( $column, $post_id ) {}
    public function register_tab( $tabs ) { return $tabs; }
    public function register_settings() {}
}
