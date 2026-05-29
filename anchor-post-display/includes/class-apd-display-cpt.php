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

        add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
        add_action( 'edit_form_after_title', [ $this, 'render_builder_after_title' ] );
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

    /* ================================================================
       Settings schema
       ================================================================ */

    private function get_setting_defs() {
        $col_layouts    = [ 'grid' ];
        $slider_layouts = [ 'slider', 'carousel' ];
        $pag_layouts    = [ 'grid', 'list' ];
        $carousel_only  = [ 'carousel' ];

        return [
            // Content
            'fields'       => [ 'type' => 'text',    'label' => 'Fields (comma-separated)', 'section' => 'content', 'priority' => 10, 'help' => 'image,title,date,type,excerpt or any ACF/meta key. Empty = default order.' ],
            'show_date'    => [ 'type' => 'checkbox', 'label' => 'Show date',         'section' => 'content', 'priority' => 20 ],
            'show_type'    => [ 'type' => 'checkbox', 'label' => 'Show post type',    'section' => 'content', 'priority' => 30 ],
            'teaser_words' => [ 'type' => 'number',   'label' => 'Teaser word limit', 'section' => 'content', 'priority' => 40, 'min' => 1, 'max' => 200 ],
            'image_size'   => [ 'type' => 'text',     'label' => 'Image size',        'section' => 'content', 'priority' => 50 ],

            // Layout
            'layout'          => [ 'type' => 'select', 'label' => 'Layout', 'section' => 'layout', 'priority' => 10, 'options' => [ 'grid' => 'Grid', 'list' => 'List', 'slider' => 'Slider', 'carousel' => 'Carousel' ] ],
            'columns_desktop' => [ 'type' => 'number', 'label' => 'Desktop columns', 'section' => 'layout', 'priority' => 20, 'min' => 1, 'max' => 6, 'applies_to' => $col_layouts ],
            'gap'             => [ 'type' => 'number', 'label' => 'Gap (px)', 'section' => 'layout', 'priority' => 30, 'min' => 0, 'max' => 60, 'step' => 2 ],
            'card_style'      => [ 'type' => 'select', 'label' => 'Card style', 'section' => 'layout', 'priority' => 40, 'options' => [ 'card' => 'Card', 'minimal' => 'Minimal', 'bordered' => 'Bordered' ] ],

            // Style
            'border_radius' => [ 'type' => 'number', 'label' => 'Border radius (px)', 'section' => 'style', 'min' => 0, 'max' => 32, 'step' => 1 ],
            'tile_shadow'   => [ 'type' => 'select', 'label' => 'Card shadow', 'section' => 'style', 'options' => [ 'none' => 'None', 'soft' => 'Soft', 'medium' => 'Medium', 'strong' => 'Strong' ] ],
            'wrapper_bg'    => [ 'type' => 'color',  'label' => 'Background', 'section' => 'style' ],
            'title_color'   => [ 'type' => 'color',  'label' => 'Title color', 'section' => 'style' ],
            'title_size'    => [ 'type' => 'number', 'label' => 'Title size (px, 0=auto)', 'section' => 'style', 'min' => 0, 'max' => 40, 'step' => 1 ],
            'title_weight'  => [ 'type' => 'select', 'label' => 'Title weight', 'section' => 'style', 'options' => [ '400' => 'Normal', '500' => 'Medium', '600' => 'Semi-bold', '700' => 'Bold' ] ],

            // Behavior
            'pagination'        => [ 'type' => 'select', 'label' => 'Pagination', 'section' => 'behavior', 'priority' => 10, 'options' => [ 'none' => 'None', 'numbered' => 'Numbered', 'load_more' => 'Load More' ], 'applies_to' => $pag_layouts ],
            'pagination_window' => [ 'type' => 'number', 'label' => 'Page button limit', 'section' => 'behavior', 'priority' => 11, 'min' => 1, 'max' => 20, 'applies_to' => $pag_layouts, 'depends_on' => [ 'pagination' => [ 'numbered' ] ] ],
            'slider_per_view'   => [ 'type' => 'number', 'label' => 'Slides per view (desktop)', 'section' => 'behavior', 'priority' => 20, 'min' => 1, 'max' => 6, 'applies_to' => $slider_layouts ],
            'slider_autoplay'   => [ 'type' => 'checkbox', 'label' => 'Autoplay', 'section' => 'behavior', 'priority' => 21, 'applies_to' => $slider_layouts ],
            'slider_speed'      => [ 'type' => 'number', 'label' => 'Autoplay speed (ms)', 'section' => 'behavior', 'priority' => 22, 'min' => 1000, 'max' => 15000, 'step' => 500, 'applies_to' => $slider_layouts, 'depends_on' => [ 'slider_autoplay' => true ] ],
            'carousel_loop'     => [ 'type' => 'checkbox', 'label' => 'Loop continuously', 'section' => 'behavior', 'priority' => 30, 'applies_to' => $carousel_only ],
            'carousel_arrows'   => [ 'type' => 'checkbox', 'label' => 'Navigation arrows', 'section' => 'behavior', 'priority' => 31, 'applies_to' => $slider_layouts ],
            'carousel_dots'     => [ 'type' => 'checkbox', 'label' => 'Dots navigation', 'section' => 'behavior', 'priority' => 32, 'applies_to' => $slider_layouts ],
            'carousel_pause_on_hover' => [ 'type' => 'checkbox', 'label' => 'Pause on hover', 'section' => 'behavior', 'priority' => 33, 'applies_to' => $carousel_only, 'depends_on' => [ 'slider_autoplay' => true ] ],

            // Responsive
            'columns_tablet'         => [ 'type' => 'number', 'label' => 'Tablet columns', 'section' => 'responsive', 'min' => 1, 'max' => 4, 'applies_to' => $col_layouts ],
            'columns_mobile'         => [ 'type' => 'number', 'label' => 'Mobile columns', 'section' => 'responsive', 'min' => 1, 'max' => 2, 'applies_to' => $col_layouts ],
            'slider_per_view_tablet' => [ 'type' => 'number', 'label' => 'Slides per view (tablet)', 'section' => 'responsive', 'min' => 1, 'max' => 4, 'applies_to' => $slider_layouts ],
            'slider_per_view_mobile' => [ 'type' => 'number', 'label' => 'Slides per view (mobile)', 'section' => 'responsive', 'min' => 1, 'max' => 3, 'applies_to' => $slider_layouts ],
            'gap_mobile'             => [ 'type' => 'number', 'label' => 'Mobile gap (px, 0=use Gap)', 'section' => 'responsive', 'min' => 0, 'max' => 60, 'step' => 2 ],

            // Advanced
            'no_results'  => [ 'type' => 'text',     'label' => 'No results text', 'section' => 'advanced' ],
            'custom_css'  => [ 'type' => 'textarea', 'label' => 'Custom CSS', 'section' => 'advanced', 'help' => 'Scope rules to #apd-UID or your HTML Anchor.' ],
            'html_anchor' => [ 'type' => 'text',     'label' => 'HTML Anchor (wrapper id)', 'section' => 'advanced' ],
        ];
    }

    /**
     * Per-display defaults: schema defaults merged with the global Post Display
     * option, so a new display inherits the site-wide look.
     */
    private function default_settings() {
        $globals = get_option( Anchor_Post_Display_Module::OPTION_KEY, [] );
        if ( ! is_array( $globals ) ) $globals = [];
        $defs = $this->get_setting_defs();
        $out  = [];
        foreach ( $defs as $key => $def ) {
            if ( isset( $globals[ $key ] ) && $globals[ $key ] !== '' ) {
                $out[ $key ] = $globals[ $key ];
                continue;
            }
            switch ( $def['type'] ) {
                case 'checkbox': $out[ $key ] = 0; break;
                case 'number':   $out[ $key ] = isset( $def['min'] ) ? (int) $def['min'] : 0; break;
                case 'select':   $out[ $key ] = ! empty( $def['options'] ) ? array_key_first( $def['options'] ) : ''; break;
                default:         $out[ $key ] = ''; break;
            }
        }
        // Sensible non-zero seeds when globals are absent.
        $out['layout']                 = $out['layout'] ?: 'grid';
        $out['columns_desktop']        = $out['columns_desktop'] ?: 3;
        $out['columns_tablet']         = $out['columns_tablet'] ?: 2;
        $out['columns_mobile']         = $out['columns_mobile'] ?: 1;
        $out['slider_per_view']        = $out['slider_per_view'] ?: 3;
        $out['slider_per_view_tablet'] = $out['slider_per_view_tablet'] ?: 2;
        $out['slider_per_view_mobile'] = $out['slider_per_view_mobile'] ?: 1;
        $out['slider_speed']           = $out['slider_speed'] ?: 5000;
        $out['gap']                    = $out['gap'] !== '' ? $out['gap'] : 16;
        $out['teaser_words']           = $out['teaser_words'] ?: 26;
        $out['image_size']             = $out['image_size'] ?: 'medium';
        $out['no_results']             = $out['no_results'] ?: 'No results found.';
        return $out;
    }

    private function get_settings_by_section() {
        $defs = $this->get_setting_defs();
        $grouped = []; $order = 0;
        foreach ( $defs as $key => $def ) {
            $section  = $def['section'] ?? 'advanced';
            $priority = isset( $def['priority'] ) ? (int) $def['priority'] : 50;
            $grouped[ $section ][ $key ] = [ 'def' => $def, 'priority' => $priority, 'order' => $order++ ];
        }
        $out = [];
        foreach ( $grouped as $section => $items ) {
            uasort( $items, function ( $a, $b ) {
                return $a['priority'] !== $b['priority'] ? $a['priority'] - $b['priority'] : $a['order'] - $b['order'];
            } );
            $out[ $section ] = [];
            foreach ( $items as $k => $v ) { $out[ $section ][ $k ] = $v['def']; }
        }
        return $out;
    }

    /* ================================================================
       Builder UI
       ================================================================ */

    public function add_metaboxes() {
        // The builder UI (edit_form_after_title) replaces metaboxes for this CPT.
    }

    public function render_builder_after_title( $post ) {
        if ( ! ( $post instanceof WP_Post ) || $post->post_type !== self::CPT ) return;

        wp_nonce_field( self::NONCE, self::NONCE );

        $sections = [
            'source'     => 'Source',
            'content'    => 'Content',
            'layout'     => 'Layout',
            'style'      => 'Style',
            'behavior'   => 'Behavior',
            'responsive' => 'Responsive',
            'advanced'   => 'Advanced',
        ];

        $panels = [];
        foreach ( $sections as $key => $label ) {
            if ( 'source' === $key ) {
                $panels[ $key ] = [ $this, 'render_pane_source' ];
            } else {
                $panels[ $key ] = function ( $p ) use ( $key ) { $this->render_pane_section( $p, $key ); };
            }
        }

        Anchor_Builder_Shell::render( [
            'id'        => 'anchor-post-display-builder',
            'post'      => $post,
            'title'     => $post->post_title ?: 'Untitled post display',
            'shortcode' => '[anchor_post_grid id="' . $post->ID . '"]',
            'view_url'  => '',
            'tabs'      => $sections,
            'panels'    => $panels,
            'preview'   => [ $this, 'render_pane_preview' ],
            'utility'   => [ $this, 'render_pane_utility' ],
        ] );
    }

    public function render_pane_source( $post ) {
        $get = function ( $k, $d = '' ) use ( $post ) {
            $v = get_post_meta( $post->ID, 'apd_src_' . $k, true );
            return ( $v === '' || $v === false ) ? $d : $v;
        };
        $selected_types = get_post_meta( $post->ID, 'apd_src_post_types', true );
        if ( ! is_array( $selected_types ) ) {
            $selected_types = array_filter( array_map( 'trim', explode( ',', (string) $selected_types ) ) );
        }

        $types = get_post_types( [ 'public' => true ], 'objects' );
        unset( $types['attachment'] );
        ?>
        <div class="apd-source">
            <fieldset class="apd-source__group">
                <legend><strong>Post types</strong></legend>
                <?php foreach ( $types as $t ) : ?>
                    <label class="apd-source__check">
                        <input type="checkbox" name="apd_src_post_types[]" value="<?php echo esc_attr( $t->name ); ?>" <?php checked( in_array( $t->name, $selected_types, true ) ); ?>>
                        <?php echo esc_html( $t->labels->singular_name ); ?>
                    </label>
                <?php endforeach; ?>
                <p class="description">None checked = all searchable types.</p>
            </fieldset>

            <p><label>Include taxonomy<br><input type="text" name="apd_src_taxonomy" value="<?php echo esc_attr( $get( 'taxonomy', 'category' ) ); ?>" class="regular-text"></label></p>
            <p><label>Include terms (slugs, comma-separated)<br><input type="text" name="apd_src_terms" value="<?php echo esc_attr( $get( 'terms' ) ); ?>" class="regular-text"></label></p>
            <p><label>Exclude taxonomy<br><input type="text" name="apd_src_exclude_taxonomy" value="<?php echo esc_attr( $get( 'exclude_taxonomy', 'category' ) ); ?>" class="regular-text"></label></p>
            <p><label>Exclude terms (slugs, comma-separated)<br><input type="text" name="apd_src_exclude_terms" value="<?php echo esc_attr( $get( 'exclude_terms' ) ); ?>" class="regular-text"></label></p>

            <p><label>Order by
                <select name="apd_src_orderby">
                    <?php foreach ( [ 'date' => 'Date', 'title' => 'Title', 'menu_order' => 'Menu order', 'rand' => 'Random' ] as $v => $l ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $get( 'orderby', 'date' ), $v ); ?>><?php echo esc_html( $l ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label> Order
                <select name="apd_src_order">
                    <option value="DESC" <?php selected( $get( 'order', 'DESC' ), 'DESC' ); ?>>Descending</option>
                    <option value="ASC" <?php selected( $get( 'order', 'DESC' ), 'ASC' ); ?>>Ascending</option>
                </select>
            </label></p>

            <p><label>Posts per page <input type="number" name="apd_src_posts" value="<?php echo esc_attr( $get( 'posts', 12 ) ); ?>" class="small-text" min="1" max="100"></label>
            <label> Max posts (0 = no cap) <input type="number" name="apd_src_max_posts" value="<?php echo esc_attr( $get( 'max_posts', 0 ) ); ?>" class="small-text" min="0"></label></p>

            <p><label>Forced search term (optional)<br><input type="text" name="apd_src_search" value="<?php echo esc_attr( $get( 'search' ) ); ?>" class="regular-text"></label></p>
        </div>
        <?php
    }

    public function render_pane_section( $post, $section ) {
        $grouped  = $this->get_settings_by_section();
        $defaults = $this->default_settings();
        if ( empty( $grouped[ $section ] ) ) {
            echo '<p class="anchor-builder__empty">No settings in this section.</p>';
            return;
        }
        foreach ( $grouped[ $section ] as $key => $def ) {
            $meta_key = 'apd_' . $key;
            $saved    = get_post_meta( $post->ID, $meta_key, true );
            $value    = ( $saved !== '' && $saved !== false ) ? $saved : ( $defaults[ $key ] ?? '' );
            Anchor_Builder_Shell::render_field( $key, $def, $value, $meta_key );
        }
    }

    public function render_pane_preview( $post ) {
        echo '<div id="apd-preview" class="apd-preview" data-post-id="' . intval( $post->ID ) . '">';
        echo '<p class="apd-preview__hint">Save the display to refresh this preview.</p>';
        echo '</div>';
    }

    public function render_pane_utility( $post ) {
        $layout = get_post_meta( $post->ID, 'apd_layout', true ) ?: 'grid';
        ?>
        <div class="anchor-builder__util-row"><span class="anchor-builder__util-label">Status</span><span class="anchor-builder__util-value"><?php echo esc_html( ucfirst( $post->post_status ) ); ?></span></div>
        <div class="anchor-builder__util-row"><span class="anchor-builder__util-label">Layout</span><span class="anchor-builder__util-value"><?php echo esc_html( $layout ); ?></span></div>
        <div class="anchor-builder__util-row"><span class="anchor-builder__util-label">ID</span><span class="anchor-builder__util-value"><?php echo intval( $post->ID ); ?></span></div>
        <?php
    }
}
