<?php
/**
 * Anchor Tools module: Anchor Gallery.
 *
 * Custom Post Type based gallery with multiple display modes.
 * Supports: videos (YouTube/Vimeo), images (WP media library).
 * Layouts: slider, grid, carousel, masonry, logo_carousel.
 * Popup styles: lightbox, inline, theater, side panel, none.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Anchor_Gallery_Module {
    const CPT        = 'anchor_gallery';
    const OLD_CPT    = 'anchor_video_gallery';
    const NONCE      = 'avg_nonce';
    const LEGACY_KEY = 'anchor_video_slider_items';

    private $sample_videos = [
        ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'label' => 'Sample Video 1', 'thumb' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', 'duration' => '3:33', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'jNQXAC9IVRw', 'label' => 'Sample Video 2', 'thumb' => 'https://img.youtube.com/vi/jNQXAC9IVRw/hqdefault.jpg', 'duration' => '0:19', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => '9bZkp7q19f0', 'label' => 'Sample Video 3', 'thumb' => 'https://img.youtube.com/vi/9bZkp7q19f0/hqdefault.jpg', 'duration' => '4:13', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'kJQP7kiw5Fk', 'label' => 'Sample Video 4', 'thumb' => 'https://img.youtube.com/vi/kJQP7kiw5Fk/hqdefault.jpg', 'duration' => '4:42', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'RgKAFK5djSk', 'label' => 'Sample Video 5', 'thumb' => 'https://img.youtube.com/vi/RgKAFK5djSk/hqdefault.jpg', 'duration' => '4:57', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'JGwWNGJdvx8', 'label' => 'Sample Video 6', 'thumb' => 'https://img.youtube.com/vi/JGwWNGJdvx8/hqdefault.jpg', 'duration' => '4:24', 'channel' => 'Demo Channel'],
    ];

    private $default_settings = [
        'layout' => 'slider',
        'columns_desktop' => 4,
        'columns_tablet' => 3,
        'columns_mobile' => 1,
        'gap' => 16,
        'pagination_enabled' => false,
        'videos_per_page' => 12,
        'pagination_style' => 'numbered',
        'popup_style' => 'lightbox',
        'autoplay' => true,
        'theme' => 'dark',
        'tile_style' => 'card',
        'thumb_aspect_ratio' => '16:9',
        'show_title' => true,
        'show_duration' => true,
        'show_channel' => false,
        'hover_effect' => 'lift',
        'play_button_style' => 'circle',
        'border_radius' => 12,
        'slider_arrows' => true,
        'slider_dots' => false,
        'slider_autoplay' => false,
        'slider_autoplay_speed' => 5000,
        'carousel_loop' => true,
        'carousel_center' => false,
        'equal_height' => false,
        'max_height' => 0,
        'thumb_size' => 'maxres',
        'object_fit' => 'cover',
        'title_position' => 'hidden',
        'marquee_speed' => 30,
        'marquee_gap' => 40,
        'marquee_item_width' => 150,
        'marquee_pause_on_hover' => true,
        'marquee_direction' => 'left',
        'marquee_reverse_row' => false,
        'marquee_item_height' => 80,
        'marquee_item_width_mobile' => 0,
        'marquee_item_height_mobile' => 0,
        'marquee_gap_mobile' => 0,
        'marquee_speed_mobile' => 0,
        'marquee_align' => 'center',
        'marquee_grayscale' => false,
        'marquee_eager_count' => 6,
        'eager_load_count' => 4,
        'gap_mobile' => 0,
        'tile_shadow' => 'soft',
    ];

    /**
     * Setting definitions for metabox rendering & save (Phase 3 schema).
     *
     * Supported per-field keys:
     *  - label       (string)  Human-readable label.
     *  - type        (string)  'select' | 'number' | 'checkbox' | 'text' | 'textarea' | 'color'.
     *  - default     (mixed)   Default value (also drawn from $this->default_settings).
     *  - section     (string)  Section/tab key (layout|style|content|behavior|responsive|advanced).
     *  - applies_to  (array)   Layout keys this setting affects. Empty/absent = global.
     *                          Hidden in editor when current layout isn't in the list.
     *  - forced_by   (array)   Map of layout_key => forced_value. When current layout matches,
     *                          field is shown but disabled, set to forced value, and annotated.
     *                          Optional 'forced_by_note' is a parallel map of layout_key => note string.
     *  - depends_on  (array)   Map of setting_key => required_value (or true for truthy). Hidden
     *                          unless every dependency is met.
     *  - responsive  (bool)    If true, declare desktop/tablet/mobile triplet support (Phase 5).
     *                          Phase 3 only adds schema support — no triplets are migrated yet.
     *  - help        (string)  One short sentence shown beneath the control.
     *  - priority    (int)     Sort order within section (lower first). Default 50.
     *  - show_for    (string)  DEPRECATED — comma-separated layout list. Falls back to applies_to.
     */
    private function get_setting_defs() {
        $core_layouts = ['slider','grid','carousel','masonry','gallery','filterable','paginated','bento','thumbnail_gallery','card_carousel','lightbox_grid'];
        $tile_layouts = ['slider','grid','carousel','masonry','filterable','paginated','bento','card_carousel','lightbox_grid'];
        $col_layouts  = ['grid','masonry','carousel','filterable','paginated','bento','card_carousel','lightbox_grid'];
        $pag_layouts  = ['grid','masonry','filterable','paginated'];
        $slider_layouts = ['slider','carousel','card_carousel'];
        $carousel_loop_layouts = ['carousel','card_carousel'];
        $marquee_layouts = ['logo_carousel'];

        return [
            'layout' => ['type' => 'select', 'label' => 'Layout', 'section' => 'layout', 'priority' => 10, 'options' => ['grid' => 'Grid', 'masonry' => 'Masonry', 'carousel' => 'Carousel', 'slider' => 'Horizontal Scroll', 'gallery' => 'Gallery (Featured + Thumbs)', 'logo_carousel' => 'Logo Carousel', 'filterable' => 'Filterable Grid'], 'help' => 'Pick a base layout. Variants like Paginated/Lightbox/Card/Bento are available as Presets.'],
            'popup_style' => ['type' => 'select', 'label' => 'Popup Style', 'section' => 'behavior', 'priority' => 10, 'options' => ['lightbox' => 'Lightbox', 'inline' => 'Inline Expand', 'theater' => 'Theater Mode', 'side_panel' => 'Side Panel', 'none' => 'Direct Link'], 'applies_to' => $core_layouts,
                'forced_by' => ['lightbox_grid' => 'lightbox'],
                'forced_by_note' => ['lightbox_grid' => 'Locked by Lightbox Grid layout — change layout to edit.'],
            ],
            'theme' => ['type' => 'select', 'label' => 'Theme', 'section' => 'style', 'priority' => 10, 'options' => ['dark' => 'Dark', 'light' => 'Light', 'auto' => 'Auto'], 'applies_to' => $core_layouts],
            'tile_style' => ['type' => 'select', 'label' => 'Tile Style', 'section' => 'style', 'priority' => 20, 'options' => ['card' => 'Card', 'minimal' => 'Minimal', 'overlay' => 'Overlay', 'cinematic' => 'Cinematic'], 'applies_to' => $tile_layouts],
            'thumb_aspect_ratio' => ['type' => 'select', 'label' => 'Thumbnail Aspect Ratio', 'section' => 'style', 'options' => [
                '16:9' => '16:9 (Widescreen)',
                '4:3'  => '4:3 (Classic)',
                '1:1'  => '1:1 (Square)',
                '9:16' => '9:16 (Portrait)',
                '21:9' => '21:9 (Cinematic)',
            ], 'applies_to' => $tile_layouts],
            'hover_effect' => ['type' => 'select', 'label' => 'Hover Effect', 'section' => 'style', 'options' => ['lift' => 'Lift', 'zoom' => 'Zoom', 'glow' => 'Glow', 'none' => 'None'], 'applies_to' => $tile_layouts],
            'play_button_style' => ['type' => 'select', 'label' => 'Play Button', 'section' => 'style', 'options' => ['circle' => 'Circle', 'square' => 'Square', 'youtube' => 'YouTube', 'minimal' => 'Minimal', 'none' => 'Hidden'], 'applies_to' => $core_layouts],
            'border_radius' => ['type' => 'number', 'label' => 'Border Radius (px)', 'section' => 'style', 'min' => 0, 'max' => 32, 'step' => 2],
            'columns_desktop' => ['type' => 'number', 'label' => 'Desktop Columns', 'section' => 'layout', 'priority' => 20, 'min' => 1, 'max' => 6, 'applies_to' => $col_layouts],
            'columns_tablet' => ['type' => 'number', 'label' => 'Tablet Columns', 'section' => 'responsive', 'min' => 1, 'max' => 4, 'applies_to' => $col_layouts],
            'columns_mobile' => ['type' => 'number', 'label' => 'Mobile Columns', 'section' => 'responsive', 'min' => 1, 'max' => 2, 'applies_to' => $col_layouts],
            'gap' => ['type' => 'number', 'label' => 'Gap (px)', 'section' => 'layout', 'min' => 0, 'max' => 60, 'step' => 4],
            'show_title' => ['type' => 'checkbox', 'label' => 'Show Title', 'section' => 'content', 'priority' => 10, 'applies_to' => $core_layouts],
            'show_duration' => ['type' => 'checkbox', 'label' => 'Show Duration', 'section' => 'content', 'applies_to' => array_values(array_diff($core_layouts, ['thumbnail_gallery']))],
            'show_channel' => ['type' => 'checkbox', 'label' => 'Show Channel', 'section' => 'content', 'applies_to' => ['slider','grid','carousel','masonry','filterable','paginated','bento','card_carousel']],
            'title_position' => ['type' => 'select', 'label' => 'Title Position', 'section' => 'content', 'options' => ['hidden' => 'Hidden', 'below' => 'Below Image', 'overlay' => 'Overlay on Image'], 'applies_to' => $tile_layouts],
            'equal_height' => ['type' => 'checkbox', 'label' => 'Equal Height Tiles', 'section' => 'layout', 'applies_to' => array_values(array_diff($tile_layouts, ['bento']))],
            'max_height' => ['type' => 'number', 'label' => 'Max Thumbnail Height (px)', 'section' => 'layout', 'min' => 0, 'max' => 1200, 'step' => 10, 'applies_to' => $tile_layouts],
            'thumb_size' => ['type' => 'select', 'label' => 'Thumbnail Resolution', 'section' => 'advanced', 'options' => [
                'maxres'   => 'Max (1280×720)',
                'standard' => 'Standard (640×480)',
                'high'     => 'High (480×360)',
                'medium'   => 'Medium (320×180)',
            ], 'applies_to' => $core_layouts],
            'object_fit' => ['type' => 'select', 'label' => 'Object Fit', 'section' => 'style', 'options' => ['cover' => 'Cover', 'contain' => 'Contain', 'fill' => 'Fill', 'scale-down' => 'Scale Down', 'none' => 'None']],
            'autoplay' => ['type' => 'checkbox', 'label' => 'Autoplay on popup open', 'section' => 'behavior', 'applies_to' => $core_layouts],
            'pagination_enabled' => ['type' => 'checkbox', 'label' => 'Enable Pagination', 'section' => 'behavior', 'applies_to' => $pag_layouts, 'help' => 'Used by Grid, Masonry, and Filterable Grid layouts.',
                'forced_by' => ['paginated' => '1'],
                'forced_by_note' => ['paginated' => 'Locked by Paginated Grid layout — change layout to edit.'],
            ],
            'videos_per_page' => ['type' => 'number', 'label' => 'Items Per Page', 'section' => 'behavior', 'min' => 1, 'max' => 100, 'applies_to' => $pag_layouts, 'depends_on' => ['pagination_enabled' => true]],
            'pagination_style' => ['type' => 'select', 'label' => 'Pagination Style', 'section' => 'behavior', 'options' => ['numbered' => 'Numbered', 'load_more' => 'Load More', 'infinite' => 'Infinite Scroll'], 'applies_to' => $pag_layouts, 'depends_on' => ['pagination_enabled' => true]],
            'slider_arrows' => ['type' => 'checkbox', 'label' => 'Navigation Arrows', 'section' => 'behavior', 'applies_to' => $slider_layouts],
            'slider_dots' => ['type' => 'checkbox', 'label' => 'Dots Navigation', 'section' => 'behavior', 'applies_to' => $slider_layouts],
            'slider_autoplay' => ['type' => 'checkbox', 'label' => 'Auto-advance', 'section' => 'behavior', 'applies_to' => $slider_layouts],
            'slider_autoplay_speed' => ['type' => 'number', 'label' => 'Autoplay Speed (ms)', 'section' => 'behavior', 'min' => 1000, 'max' => 15000, 'step' => 500, 'applies_to' => $slider_layouts, 'depends_on' => ['slider_autoplay' => true]],
            'carousel_loop' => ['type' => 'checkbox', 'label' => 'Loop Continuously', 'section' => 'behavior', 'applies_to' => $carousel_loop_layouts],
            'carousel_center' => ['type' => 'checkbox', 'label' => 'Center Active Slide', 'section' => 'behavior', 'applies_to' => $carousel_loop_layouts],
            'marquee_speed' => ['type' => 'number', 'label' => 'Scroll Speed (seconds)', 'section' => 'behavior', 'min' => 5, 'max' => 120, 'step' => 5, 'applies_to' => $marquee_layouts],
            'marquee_gap' => ['type' => 'number', 'label' => 'Item Gap (px)', 'section' => 'layout', 'min' => 0, 'max' => 120, 'step' => 4, 'applies_to' => $marquee_layouts],
            'marquee_item_width' => ['type' => 'number', 'label' => 'Item Width (px)', 'section' => 'layout', 'min' => 50, 'max' => 400, 'step' => 10, 'applies_to' => $marquee_layouts],
            'marquee_pause_on_hover' => ['type' => 'checkbox', 'label' => 'Pause on Hover', 'section' => 'behavior', 'applies_to' => $marquee_layouts],
            'marquee_direction' => ['type' => 'select', 'label' => 'Scroll Direction', 'section' => 'behavior', 'options' => ['left' => 'Left', 'right' => 'Right'], 'applies_to' => $marquee_layouts],
            'marquee_reverse_row' => ['type' => 'checkbox', 'label' => 'Add Reverse Row', 'section' => 'layout', 'applies_to' => $marquee_layouts],
            'marquee_item_height' => ['type' => 'number', 'label' => 'Item Max Height (px)', 'section' => 'layout', 'min' => 20, 'max' => 400, 'step' => 5, 'applies_to' => $marquee_layouts],
            'marquee_item_width_mobile' => ['type' => 'number', 'label' => 'Mobile Item Width (px, 0 = use Item Width)', 'section' => 'responsive', 'min' => 0, 'max' => 400, 'step' => 5, 'applies_to' => $marquee_layouts],
            'marquee_item_height_mobile' => ['type' => 'number', 'label' => 'Mobile Item Height (px, 0 = use Item Max Height)', 'section' => 'responsive', 'min' => 0, 'max' => 400, 'step' => 5, 'applies_to' => $marquee_layouts],
            'marquee_gap_mobile' => ['type' => 'number', 'label' => 'Mobile Item Gap (px, 0 = use Item Gap)', 'section' => 'responsive', 'min' => 0, 'max' => 120, 'step' => 4, 'applies_to' => $marquee_layouts],
            'marquee_speed_mobile' => ['type' => 'number', 'label' => 'Mobile Scroll Speed (s, 0 = use Scroll Speed)', 'section' => 'responsive', 'min' => 0, 'max' => 120, 'step' => 5, 'applies_to' => $marquee_layouts],
            'marquee_align' => ['type' => 'select', 'label' => 'Item Alignment', 'section' => 'layout', 'options' => ['start' => 'Top', 'center' => 'Center', 'end' => 'Bottom'], 'applies_to' => $marquee_layouts],
            'marquee_grayscale' => ['type' => 'checkbox', 'label' => 'Grayscale (color on hover)', 'section' => 'style', 'applies_to' => $marquee_layouts],
            'marquee_eager_count' => ['type' => 'number', 'label' => 'Eager-Load First N Logos', 'section' => 'advanced', 'min' => 0, 'max' => 30, 'step' => 1, 'applies_to' => $marquee_layouts],
            'eager_load_count' => ['type' => 'number', 'label' => 'Eager-Load First N Thumbnails', 'section' => 'advanced', 'min' => 0, 'max' => 24, 'step' => 1, 'applies_to' => $core_layouts],
            'gap_mobile' => ['type' => 'number', 'label' => 'Mobile Gap (px, 0 = use Gap)', 'section' => 'responsive', 'min' => 0, 'max' => 60, 'step' => 2],
            'tile_shadow' => ['type' => 'select', 'label' => 'Tile Shadow', 'section' => 'style', 'options' => ['none' => 'None', 'soft' => 'Soft', 'medium' => 'Medium', 'strong' => 'Strong'], 'applies_to' => $core_layouts],
        ];
    }

    /**
     * Build a layout => { setting_key => { value, note } } map from the
     * `forced_by` keys in the schema. Used by the editor JS to disable +
     * annotate forced controls.
     */
    private function get_forced_by_layout_map() {
        $defs = $this->get_setting_defs();
        $default_note = 'Locked by current layout — change layout to edit.';
        $map = [];
        foreach ( $defs as $key => $def ) {
            if ( empty( $def['forced_by'] ) || ! is_array( $def['forced_by'] ) ) continue;
            $notes = isset( $def['forced_by_note'] ) && is_array( $def['forced_by_note'] ) ? $def['forced_by_note'] : [];
            foreach ( $def['forced_by'] as $layout => $value ) {
                $map[ $layout ][ $key ] = [
                    'value' => (string) $value,
                    'note'  => isset( $notes[ $layout ] ) ? $notes[ $layout ] : $default_note,
                ];
            }
        }
        return $map;
    }

    /**
     * Visual presets — "starting points" that override a handful of settings
     * to match a common use case. Applied via the Preset tab in the builder.
     */
    private function get_presets() {
        return [
            'simple_slider' => [
                'label'       => 'Simple Slider',
                'category'    => 'Sliders',
                'description' => 'Classic image/video slider with arrows + dots',
                'overrides'   => [
                    'avg_layout'        => 'slider',
                    'avg_tile_style'    => 'minimal',
                    'avg_slider_arrows' => 1,
                    'avg_slider_dots'   => 1,
                    'avg_popup_style'   => 'lightbox',
                ],
            ],
            'card_carousel' => [
                'label'       => 'Card Carousel',
                'category'    => 'Sliders',
                'description' => 'Cards-per-view carousel for services or testimonials',
                'overrides'   => [
                    'avg_layout'         => 'carousel',
                    'avg_tile_style'     => 'card',
                    'avg_columns_desktop'=> 3,
                    'avg_slider_arrows'  => 1,
                    'avg_slider_dots'    => 1,
                    'avg_carousel_loop'  => 1,
                ],
            ],
            'paginated_grid' => [
                'label'       => 'Paginated Grid',
                'category'    => 'Galleries',
                'description' => 'Grid with numbered pagination for long item lists',
                'overrides'   => [
                    'avg_layout'             => 'grid',
                    'avg_pagination_enabled' => 1,
                    'avg_pagination_style'   => 'numbered',
                    'avg_videos_per_page'    => 12,
                    'avg_columns_desktop'    => 4,
                ],
            ],
            'logo_reel' => [
                'label'       => 'Logo Reel',
                'category'    => 'Logo Displays',
                'description' => 'Auto-scrolling marquee for partner logos',
                'overrides'   => [
                    'avg_layout'             => 'logo_carousel',
                    'avg_marquee_speed'      => 30,
                    'avg_marquee_pause_on_hover' => 1,
                    'avg_marquee_grayscale'  => 1,
                ],
            ],
            'masonry_gallery' => [
                'label'       => 'Masonry Gallery',
                'category'    => 'Galleries',
                'description' => 'Mixed-height image grid',
                'overrides'   => [
                    'avg_layout'         => 'masonry',
                    'avg_tile_style'     => 'card',
                    'avg_columns_desktop'=> 3,
                    'avg_popup_style'    => 'lightbox',
                ],
            ],
            'bento_gallery' => [
                'label'       => 'Bento Gallery',
                'category'    => 'Galleries',
                'description' => 'Modern mixed-size grid',
                'overrides'   => [
                    'avg_layout'         => 'grid',
                    'avg_tile_style'     => 'card',
                    'avg_columns_desktop'=> 4,
                    'avg_popup_style'    => 'lightbox',
                ],
            ],
            'feature_gallery' => [
                'label'       => 'Featured + Thumbs',
                'category'    => 'Galleries',
                'description' => 'Big featured image with thumbnail strip',
                'overrides'   => [
                    'avg_layout'      => 'gallery',
                    'avg_popup_style' => 'lightbox',
                ],
            ],
            'filterable_grid' => [
                'label'       => 'Filterable Grid',
                'category'    => 'Advanced',
                'description' => 'Grid with category filter buttons',
                'overrides'   => [
                    'avg_layout'         => 'filterable',
                    'avg_columns_desktop'=> 3,
                    'avg_tile_style'     => 'card',
                ],
            ],
            'lightbox_grid' => [
                'label'       => 'Lightbox Grid',
                'category'    => 'Galleries',
                'description' => 'Plain grid that opens images in a lightbox',
                'overrides'   => [
                    'avg_layout'         => 'grid',
                    'avg_popup_style'    => 'lightbox',
                    'avg_columns_desktop'=> 4,
                ],
            ],
        ];
    }

    /**
     * Group settings by section. Settings without an explicit section land in 'advanced'.
     * Within each section, fields are sorted by `priority` ascending (default 50),
     * preserving original definition order as the tiebreaker.
     */
    private function get_settings_by_section() {
        $defs = $this->get_setting_defs();
        $grouped = [];
        $order = 0;
        foreach ( $defs as $key => $def ) {
            $section = isset( $def['section'] ) ? $def['section'] : 'advanced';
            $priority = isset( $def['priority'] ) ? (int) $def['priority'] : 50;
            $grouped[ $section ][ $key ] = [ 'def' => $def, 'priority' => $priority, 'order' => $order++ ];
        }
        $out = [];
        foreach ( $grouped as $section => $items ) {
            uasort( $items, function( $a, $b ) {
                if ( $a['priority'] !== $b['priority'] ) return $a['priority'] - $b['priority'];
                return $a['order'] - $b['order'];
            } );
            $out[ $section ] = [];
            foreach ( $items as $k => $v ) {
                $out[ $section ][ $k ] = $v['def'];
            }
        }
        return $out;
    }

    /* ══════════════════════════════════════════════════════════
       Constructor & hooks
       ══════════════════════════════════════════════════════════ */

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_metaboxes']);
        add_action('edit_form_after_title', [$this, 'render_builder_after_title']);
        add_action('save_post', [$this, 'save_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_avg_preview', [$this, 'ajax_preview']);
        add_shortcode('anchor_video_slider', [$this, 'render_gallery']);
        add_shortcode('anchor_video_gallery', [$this, 'render_gallery']);
        add_shortcode('anchor_gallery', [$this, 'render_gallery']);

        add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'admin_column_content'], 10, 2);
    }

    /* ══════════════════════════════════════════════════════════
       CPT Registration
       ══════════════════════════════════════════════════════════ */

    public function register_cpt() {
        $this->migrate_cpt_slug();
        $this->migrate_legacy_data();

        register_post_type(self::CPT, [
            'labels' => [
                'name'          => 'Anchor Galleries',
                'singular_name' => 'Anchor Gallery',
                'add_new_item'  => 'Add New Gallery',
                'edit_item'     => 'Edit Gallery',
                'menu_name'     => 'Anchor Galleries',
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => apply_filters('anchor_video_gallery_parent_menu', true),
            'menu_icon'    => 'dashicons-format-gallery',
            'supports'     => ['title'],
        ]);
    }

    /* ══════════════════════════════════════════════════════════
       CPT Slug Migration (anchor_video_gallery → anchor_gallery)
       ══════════════════════════════════════════════════════════ */

    private function migrate_cpt_slug() {
        if (get_option('avg_cpt_migrated')) return;

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
            self::OLD_CPT
        ));

        if ($count > 0) {
            $wpdb->update(
                $wpdb->posts,
                ['post_type' => self::CPT],
                ['post_type' => self::OLD_CPT],
                ['%s'],
                ['%s']
            );
            clean_post_cache(0);
        }

        update_option('avg_cpt_migrated', 1, false);
    }

    /* ══════════════════════════════════════════════════════════
       Admin Columns
       ══════════════════════════════════════════════════════════ */

    public function admin_columns($columns) {
        $new = [];
        foreach ($columns as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['avg_shortcode'] = 'Shortcode';
                $new['avg_layout']    = 'Layout';
                $new['avg_items']     = 'Items';
            }
        }
        return $new;
    }

    public function admin_column_content($column, $post_id) {
        if ($column === 'avg_shortcode') {
            echo '<code>[anchor_gallery id="' . esc_attr($post_id) . '"]</code>';
        } elseif ($column === 'avg_layout') {
            echo esc_html(ucfirst(str_replace('_', ' ', get_post_meta($post_id, 'avg_layout', true) ?: 'slider')));
        } elseif ($column === 'avg_items') {
            $videos = get_post_meta($post_id, 'avg_videos', true);
            echo is_array($videos) ? count($videos) : 0;
        }
    }

    /* ══════════════════════════════════════════════════════════
       Metaboxes
       ══════════════════════════════════════════════════════════ */

    public function add_metaboxes() {
        // The new builder UI (rendered via edit_form_after_title) replaces
        // the previous three metaboxes (avg_videos, avg_settings, avg_preview).
        // No metaboxes are registered for this CPT.
    }

    /**
     * Render the full-width builder UI. Hooked on edit_form_after_title for
     * this CPT only.
     */
    public function render_builder_after_title( $post ) {
        if ( ! ( $post instanceof WP_Post ) || $post->post_type !== self::CPT ) {
            return;
        }

        wp_nonce_field( self::NONCE, self::NONCE );

        $sections = [
            'content'    => 'Content',
            'preset'     => 'Preset',
            'layout'     => 'Layout',
            'style'      => 'Style',
            'behavior'   => 'Behavior',
            'responsive' => 'Responsive',
            'advanced'   => 'Advanced',
        ];

        $panels = [];
        foreach ( $sections as $key => $label ) {
            if ( $key === 'content' ) {
                $panels[ $key ] = [ $this, 'render_pane_content' ];
            } elseif ( $key === 'preset' ) {
                $panels[ $key ] = [ $this, 'render_pane_preset' ];
            } else {
                $panels[ $key ] = function ( $p ) use ( $key ) {
                    $this->render_pane_section( $p, $key );
                };
            }
        }

        Anchor_Builder_Shell::render( [
            'id'        => 'anchor-gallery-builder',
            'post'      => $post,
            'title'     => $post->post_title ?: 'Untitled gallery',
            'shortcode' => '[anchor_gallery id="' . $post->ID . '"]',
            'view_url'  => get_permalink( $post ),
            'tabs'      => $sections,
            'panels'    => $panels,
            'preview'   => [ $this, 'render_pane_preview' ],
            'utility'   => [ $this, 'render_pane_utility' ],
        ] );
    }

    /**
     * Content tab: item list (same data as the legacy items metabox).
     */
    public function render_pane_content( $post ) {
        $this->render_box_videos( $post );
    }

    /**
     * Preset tab: visual cards.
     */
    public function render_pane_preset( $post ) {
        $current = get_post_meta( $post->ID, 'avg_preset', true );
        Anchor_Builder_Preset_Picker::render( $this->get_presets(), 'avg_preset', $current );
    }

    /**
     * Settings panes: render only fields for the given section.
     */
    public function render_pane_section( $post, $section ) {
        $grouped = $this->get_settings_by_section();
        if ( empty( $grouped[ $section ] ) ) {
            echo '<p class="anchor-builder__empty">No settings in this section.</p>';
            return;
        }
        foreach ( $grouped[ $section ] as $key => $def ) {
            $meta_key = 'avg_' . $key;
            $saved    = get_post_meta( $post->ID, $meta_key, true );
            $value    = ( $saved !== '' && $saved !== false ) ? $saved : ( $this->default_settings[ $key ] ?? '' );
            Anchor_Builder_Shell::render_field( $key, $def, $value, $meta_key );
        }
    }

    /**
     * Center preview pane.
     */
    public function render_pane_preview( $post ) {
        $this->render_box_preview( $post );
    }

    /**
     * Right utility panel.
     */
    public function render_pane_utility( $post ) {
        $items = get_post_meta( $post->ID, 'avg_videos', true );
        $count = is_array( $items ) ? count( $items ) : 0;
        $layout = get_post_meta( $post->ID, 'avg_layout', true ) ?: 'slider';
        ?>
        <div class="anchor-builder__util-row">
            <span class="anchor-builder__util-label">Status</span>
            <span class="anchor-builder__util-value"><?php echo esc_html( ucfirst( $post->post_status ) ); ?></span>
        </div>
        <div class="anchor-builder__util-row">
            <span class="anchor-builder__util-label">Items</span>
            <span class="anchor-builder__util-value"><?php echo intval( $count ); ?></span>
        </div>
        <div class="anchor-builder__util-row">
            <span class="anchor-builder__util-label">Layout</span>
            <span class="anchor-builder__util-value"><?php echo esc_html( $layout ); ?></span>
        </div>
        <div class="anchor-builder__util-row">
            <span class="anchor-builder__util-label">ID</span>
            <span class="anchor-builder__util-value"><?php echo intval( $post->ID ); ?></span>
        </div>
        <?php
    }

    public function render_box_videos($post) {
        wp_nonce_field(self::NONCE, self::NONCE);
        $items = get_post_meta($post->ID, 'avg_videos', true);
        if (!is_array($items)) $items = [];
        ?>
        <div class="avg-bulk-wrap">
            <textarea id="avg-bulk-urls" rows="3" placeholder="Paste video URLs here, one per line (YouTube &amp; Vimeo)"></textarea>
            <button type="button" class="button button-primary" id="avg-bulk-import">Import URLs</button>
        </div>

        <div id="avg-video-list" class="anchor-builder__item-list">
            <?php foreach ($items as $i => $v) {
                echo $this->render_item_card( $i, $v );
            } ?>
        </div>

        <p class="anchor-builder__add-item">
            <button type="button" class="button" id="avg-add-video">+ Add Video</button>
            <button type="button" class="button" id="avg-add-image">+ Add Image</button>
            <button type="button" class="button" id="avg-add-html">+ Add Custom HTML</button>
        </p>
        <p class="description" style="margin-top:8px">Drag cards to reorder. Click <strong>Edit</strong> to open the inspector. Hold &#8984; (Mac) or Ctrl (Windows) when clicking thumbnails in the media library to multi-select.</p>

        <?php
        // Side-panel inspector (hidden until opened by JS).
        $this->render_item_inspector_template();
    }

    /**
     * Render a single item card row (markup also used by JS to clone new items).
     * Includes the visible card AND all hidden inputs the save handler reads.
     */
    public function render_item_card( $i, $v ) {
        $type        = $v['type'] ?? 'video';
        $att_id      = intval( $v['attachment_id'] ?? 0 );
        $custom_thb  = intval( $v['custom_thumbnail_id'] ?? 0 );
        $url         = (string) ( $v['url'] ?? '' );
        $title       = (string) ( $v['title'] ?? '' );
        $alt         = (string) ( $v['alt'] ?? '' );
        $caption     = (string) ( $v['caption'] ?? '' );
        $html        = (string) ( $v['html'] ?? '' );
        $link_url    = (string) ( $v['link_url'] ?? '' );
        $link_target = (string) ( $v['link_target'] ?? '_self' );
        $cats        = $v['categories'] ?? [];
        if ( is_string( $cats ) ) {
            $cats = array_filter( array_map( 'trim', explode( ',', $cats ) ) );
        } elseif ( ! is_array( $cats ) ) {
            $cats = [];
        }
        $cats_str = implode( ', ', $cats );

        // Resolve preview thumbnail
        $thumb_url = '';
        if ( $custom_thb ) {
            $thumb_url = wp_get_attachment_image_url( $custom_thb, 'thumbnail' ) ?: '';
        }
        if ( $thumb_url === '' ) {
            if ( $type === 'image' && $att_id ) {
                $thumb_url = wp_get_attachment_image_url( $att_id, 'thumbnail' ) ?: '';
            } elseif ( $type === 'video' && $url ) {
                if ( preg_match( '~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{6,})~', $url, $m ) ) {
                    $thumb_url = 'https://img.youtube.com/vi/' . $m[1] . '/mqdefault.jpg';
                }
            }
        }

        $type_label = $type === 'image' ? 'Image' : ( $type === 'html' ? 'HTML' : 'Video' );
        $display_title = $title !== '' ? $title : ( $type === 'html' ? '(HTML block)' : '(untitled)' );

        ob_start();
        ?>
        <div class="avg-video-row anchor-builder__item-card" data-index="<?php echo esc_attr( $i ); ?>" draggable="true">
            <span class="anchor-builder__item-handle" aria-hidden="true">&#x2630;</span>
            <div class="anchor-builder__item-thumb avg-card-thumb" data-type-icon="<?php echo esc_attr( $type ); ?>"
                 <?php if ( $thumb_url ): ?>style="background-image:url('<?php echo esc_url( $thumb_url ); ?>')"<?php endif; ?>>
                <?php if ( ! $thumb_url ): ?><span class="avg-card-thumb-icon"><?php echo $type === 'html' ? '&lt;/&gt;' : ( $type === 'image' ? '&#128247;' : '&#9658;' ); ?></span><?php endif; ?>
            </div>
            <div class="anchor-builder__item-body">
                <div class="anchor-builder__item-title avg-card-title"><?php echo esc_html( $display_title ); ?></div>
                <div class="anchor-builder__item-meta">
                    <span class="avg-type-badge avg-type-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $type_label ); ?></span>
                    <span class="avg-card-cats">
                        <?php foreach ( $cats as $cat ): ?>
                            <span class="avg-card-cat-chip"><?php echo esc_html( $cat ); ?></span>
                        <?php endforeach; ?>
                    </span>
                </div>
            </div>
            <div class="anchor-builder__item-actions">
                <button type="button" class="button avg-edit-item">Edit</button>
                <button type="button" class="button avg-duplicate-item" aria-label="Duplicate">&#x2398;</button>
                <button type="button" class="button button-link-delete avg-remove-video" aria-label="Remove">&times;</button>
            </div>

            <!-- Hidden inputs (legacy + new). Save handler reads these directly. -->
            <select name="avg_videos[<?php echo esc_attr( $i ); ?>][type]" class="avg-item-type" style="display:none">
                <option value="video"<?php selected( $type, 'video' ); ?>>Video</option>
                <option value="image"<?php selected( $type, 'image' ); ?>>Image</option>
                <option value="html"<?php selected( $type, 'html' ); ?>>Custom HTML</option>
            </select>
            <input type="url"    name="avg_videos[<?php echo esc_attr( $i ); ?>][url]"                  value="<?php echo esc_attr( $url ); ?>"         class="avg-video-url"     style="display:none" />
            <input type="hidden" name="avg_videos[<?php echo esc_attr( $i ); ?>][attachment_id]"        value="<?php echo esc_attr( $att_id ); ?>"      class="avg-attachment-id" />
            <input type="hidden" name="avg_videos[<?php echo esc_attr( $i ); ?>][custom_thumbnail_id]"  value="<?php echo esc_attr( $custom_thb ); ?>"  class="avg-custom-thumb-id" />
            <textarea name="avg_videos[<?php echo esc_attr( $i ); ?>][html]"     class="avg-item-html"     style="display:none"><?php echo esc_textarea( $html ); ?></textarea>
            <input type="text" name="avg_videos[<?php echo esc_attr( $i ); ?>][title]"        value="<?php echo esc_attr( $title ); ?>"   class="avg-video-title"   style="display:none" />
            <input type="text" name="avg_videos[<?php echo esc_attr( $i ); ?>][alt]"          value="<?php echo esc_attr( $alt ); ?>"     class="avg-item-alt"      style="display:none" />
            <input type="text" name="avg_videos[<?php echo esc_attr( $i ); ?>][caption]"      value="<?php echo esc_attr( $caption ); ?>" class="avg-item-caption"  style="display:none" />
            <input type="text" name="avg_videos[<?php echo esc_attr( $i ); ?>][categories]"   value="<?php echo esc_attr( $cats_str ); ?>" class="avg-item-cats"    style="display:none" />
            <input type="url"  name="avg_videos[<?php echo esc_attr( $i ); ?>][link_url]"     value="<?php echo esc_attr( $link_url ); ?>" class="avg-item-link-url"  style="display:none" />
            <input type="text" name="avg_videos[<?php echo esc_attr( $i ); ?>][link_target]"  value="<?php echo esc_attr( $link_target ); ?>" class="avg-item-link-target" style="display:none" />
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Side-panel inspector markup. Fields are wired up by admin.js when a
     * card's Edit button is clicked.
     */
    public function render_item_inspector_template() {
        ?>
        <div id="avg-inspector" class="anchor-builder__side-panel" aria-hidden="true">
            <div class="anchor-builder__side-panel-header">
                <h3 class="anchor-builder__side-panel-title">Edit Item</h3>
                <button type="button" class="anchor-builder__side-panel-close" aria-label="Close">&times;</button>
            </div>
            <div class="anchor-builder__side-panel-body">
                <h4 class="avg-insp-section-title">Source</h4>
                <p>
                    <label><strong>Type</strong></label>
                    <select class="avg-insp-type widefat">
                        <option value="video">Video</option>
                        <option value="image">Image</option>
                        <option value="html">Custom HTML</option>
                    </select>
                </p>
                <p class="avg-insp-row-video">
                    <label><strong>Video URL</strong></label>
                    <input type="url" class="avg-insp-url widefat" placeholder="https://youtube.com/watch?v=..." />
                </p>
                <p class="avg-insp-row-image">
                    <label><strong>Image</strong></label><br>
                    <button type="button" class="button avg-insp-choose-image">Choose Image</button>
                    <span class="avg-insp-image-preview"></span>
                </p>
                <p class="avg-insp-row-html">
                    <label><strong>HTML</strong></label>
                    <textarea class="avg-insp-html widefat" rows="5" placeholder="HTML or shortcodes"></textarea>
                </p>

                <h4 class="avg-insp-section-title">Display</h4>
                <p>
                    <label><strong>Title</strong></label>
                    <input type="text" class="avg-insp-title widefat" />
                </p>
                <p>
                    <label><strong>Caption</strong></label>
                    <input type="text" class="avg-insp-caption widefat" placeholder="Optional caption shown below the title" />
                </p>
                <p>
                    <label><strong>Alt text</strong></label>
                    <input type="text" class="avg-insp-alt widefat" placeholder="Image alt text (falls back to title)" />
                </p>

                <h4 class="avg-insp-section-title">Categories</h4>
                <p>
                    <input type="text" class="avg-insp-cats widefat" placeholder="Comma-separated tags (used by Filterable Grid)" />
                    <span class="description">e.g. Tutorials, Customer Stories</span>
                </p>

                <h4 class="avg-insp-section-title">Custom Thumbnail</h4>
                <p>
                    <button type="button" class="button avg-insp-choose-thumb">Choose Custom Thumbnail</button>
                    <button type="button" class="button-link avg-insp-reset-thumb">Reset to default</button>
                    <br>
                    <span class="avg-insp-thumb-preview"></span>
                </p>

                <h4 class="avg-insp-section-title">Link</h4>
                <p>
                    <label><strong>Link URL</strong></label>
                    <input type="url" class="avg-insp-link-url widefat" placeholder="https://... (used when popups are disabled)" />
                </p>
                <p>
                    <label><strong>Open in</strong></label>
                    <select class="avg-insp-link-target widefat">
                        <option value="_self">Same tab</option>
                        <option value="_blank">New tab</option>
                    </select>
                </p>
            </div>
            <div class="anchor-builder__side-panel-footer">
                <button type="button" class="button button-link-delete avg-insp-remove">Remove</button>
                <button type="button" class="button avg-insp-duplicate">Duplicate</button>
                <button type="button" class="button button-primary anchor-builder__side-panel-close">Done</button>
            </div>
        </div>
        <?php
    }

    public function render_box_settings($post) {
        $defs = $this->get_setting_defs();
        foreach ($defs as $key => $def) {
            $meta_key = 'avg_' . $key;
            $saved = get_post_meta($post->ID, $meta_key, true);
            $value = ($saved !== '' && $saved !== false) ? $saved : $this->default_settings[$key];
            // Phase 3: prefer applies_to over deprecated show_for.
            $applies_to = [];
            if ( ! empty( $def['applies_to'] ) && is_array( $def['applies_to'] ) ) {
                $applies_to = $def['applies_to'];
            } elseif ( ! empty( $def['show_for'] ) ) {
                $applies_to = array_filter( array_map( 'trim', explode( ',', $def['show_for'] ) ) );
            }
            $show_for = $applies_to ? implode( ',', $applies_to ) : '';
            $depends_on = ( ! empty( $def['depends_on'] ) && is_array( $def['depends_on'] ) ) ? $def['depends_on'] : [];
            $wrap_class = 'avg-setting-row';
            if ($show_for) {
                $wrap_class .= ' avg-show-for';
            }
            if ($depends_on) {
                $wrap_class .= ' avg-depends';
            }
            ?>
            <p class="<?php echo esc_attr($wrap_class); ?>"
                <?php if ($show_for): ?> data-show-for="<?php echo esc_attr($show_for); ?>" data-applies-to="<?php echo esc_attr($show_for); ?>"<?php endif; ?>
                <?php if ($depends_on): ?> data-depends-on="<?php echo esc_attr( wp_json_encode( $depends_on ) ); ?>"<?php endif; ?>
                data-setting-key="<?php echo esc_attr( $key ); ?>">
            <?php if ($def['type'] === 'select'): ?>
                <label for="<?php echo esc_attr($meta_key); ?>"><strong><?php echo esc_html($def['label']); ?></strong></label><br>
                <select name="<?php echo esc_attr($meta_key); ?>" id="<?php echo esc_attr($meta_key); ?>" class="widefat avg-setting">
                    <?php foreach ($def['options'] as $opt_val => $opt_label): ?>
                        <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($value, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ($def['type'] === 'number'): ?>
                <label for="<?php echo esc_attr($meta_key); ?>"><strong><?php echo esc_html($def['label']); ?></strong></label><br>
                <input type="number" name="<?php echo esc_attr($meta_key); ?>" id="<?php echo esc_attr($meta_key); ?>" value="<?php echo esc_attr($value); ?>" min="<?php echo esc_attr($def['min'] ?? 0); ?>" max="<?php echo esc_attr($def['max'] ?? 999); ?>" step="<?php echo esc_attr($def['step'] ?? 1); ?>" class="widefat avg-setting" />
            <?php elseif ($def['type'] === 'checkbox'): ?>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr($meta_key); ?>" value="1" <?php checked($value); ?> class="avg-setting" />
                    <strong><?php echo esc_html($def['label']); ?></strong>
                </label>
            <?php endif; ?>
            <?php if ( ! empty( $def['help'] ) ): ?>
                <span class="description" style="display:block; font-size:11px; color:#666; margin-top:4px;"><?php echo esc_html( $def['help'] ); ?></span>
            <?php endif; ?>
            </p>
            <?php
        }
    }

    public function render_box_preview($post) {
        $raw_items = get_post_meta( $post->ID, 'avg_videos', true );
        $items     = is_array( $raw_items ) ? $raw_items : [];

        $using_samples = false;
        if ( empty( $items ) ) {
            $using_samples  = true;
            $preview_videos = $this->sample_videos;
            foreach ( $preview_videos as &$v ) {
                $v['raw_url'] = 'https://youtube.com/watch?v=' . $v['id'];
            }
            unset( $v );
        } else {
            $preview_videos = $this->parse_videos_from_rows( $items );
        }

        $settings = [];
        foreach ($this->default_settings as $key => $default) {
            $saved = get_post_meta($post->ID, 'avg_' . $key, true);
            $settings[$key] = ($saved !== '' && $saved !== false) ? $saved : $default;
            if (is_bool($default)) {
                $settings[$key] = (bool) $settings[$key];
            } elseif (is_int($default)) {
                $settings[$key] = (int) $settings[$key];
            }
        }

        $preview_videos = $this->hydrate_video_metadata( $preview_videos, $settings['thumb_size'] ?? 'maxres' );
        ?>
        <div class="avg-preview-wrap">
            <div class="avg-preview-content">
                <?php echo $this->render_dispatch('avg-preview-init', $preview_videos, $settings); ?>
            </div>
            <p class="avg-preview-note">
                <?php if ( $using_samples ) : ?>
                    No items yet — preview is showing sample videos. Add items in the Content tab.
                <?php else : ?>
                    Live preview of <?php echo count( $preview_videos ); ?> item<?php echo count( $preview_videos ) === 1 ? '' : 's'; ?>. Front-end output may differ for video providers fetching live metadata.
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       Save Meta
       ══════════════════════════════════════════════════════════ */

    public function save_meta($post_id) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Save items (videos + images + html). Re-indexed in displayed order
        // because cards may have been drag-reordered before submit.
        $raw_items = isset($_POST['avg_videos']) && is_array($_POST['avg_videos']) ? $_POST['avg_videos'] : [];
        $items = [];
        foreach ($raw_items as $v) {
            if (!is_array($v)) continue;
            $type = sanitize_text_field($v['type'] ?? 'video');
            if (!in_array($type, ['video', 'image', 'html'], true)) $type = 'video';

            // Common new fields
            $title       = sanitize_text_field( wp_unslash( $v['title']       ?? '' ) );
            $alt         = sanitize_text_field( wp_unslash( $v['alt']         ?? '' ) );
            $caption     = wp_kses_post( wp_unslash( $v['caption']     ?? '' ) );
            $custom_thb  = absint( $v['custom_thumbnail_id'] ?? 0 );
            $link_url    = esc_url_raw( wp_unslash( $v['link_url']    ?? '' ) );
            $link_target = sanitize_text_field( $v['link_target'] ?? '_self' );
            if ( ! in_array( $link_target, [ '_self', '_blank' ], true ) ) $link_target = '_self';

            // Categories: array OR comma-separated string
            $raw_cats = $v['categories'] ?? [];
            if ( is_string( $raw_cats ) ) {
                $raw_cats = explode( ',', wp_unslash( $raw_cats ) );
            } elseif ( ! is_array( $raw_cats ) ) {
                $raw_cats = [];
            }
            $categories = [];
            foreach ( $raw_cats as $c ) {
                $c = sanitize_text_field( trim( (string) $c ) );
                if ( $c !== '' && ! in_array( $c, $categories, true ) ) {
                    $categories[] = $c;
                }
            }

            $base = [
                'type'                => $type,
                'url'                 => '',
                'attachment_id'       => 0,
                'title'               => $title,
                'alt'                 => $alt,
                'caption'             => $caption,
                'categories'          => $categories,
                'custom_thumbnail_id' => $custom_thb,
                'link_url'            => $link_url,
                'link_target'         => $link_target,
            ];

            if ($type === 'html') {
                $html = wp_kses_post( wp_unslash( $v['html'] ?? '' ) );
                if ( trim( $html ) === '' ) continue;
                $items[] = array_merge( $base, [ 'html' => $html ] );
                continue;
            }

            if ($type === 'video') {
                $url = esc_url_raw( wp_unslash( trim( $v['url'] ?? '' ) ) );
                if ($url === '') continue;
                $items[] = array_merge( $base, [ 'url' => $url ] );
            } else {
                $att_id = absint($v['attachment_id'] ?? 0);
                if ($att_id === 0) continue;
                $items[] = array_merge( $base, [ 'attachment_id' => $att_id ] );
            }
        }
        $items = array_values( $items );
        update_post_meta($post_id, 'avg_videos', $items);

        // Save settings
        $defs = $this->get_setting_defs();
        foreach ($defs as $key => $def) {
            $meta_key = 'avg_' . $key;
            if ($def['type'] === 'checkbox') {
                $val = isset($_POST[$meta_key]) ? '1' : '0';
            } elseif ($def['type'] === 'number') {
                $val = isset($_POST[$meta_key]) ? intval($_POST[$meta_key]) : $this->default_settings[$key];
                if (isset($def['min'])) $val = max($def['min'], $val);
                if (isset($def['max'])) $val = min($def['max'], $val);
            } elseif ($def['type'] === 'select') {
                $val = isset($_POST[$meta_key]) ? sanitize_text_field($_POST[$meta_key]) : $this->default_settings[$key];
                if (isset($def['options']) && !array_key_exists($val, $def['options'])) {
                    $val = $this->default_settings[$key];
                }
            } else {
                $val = isset($_POST[$meta_key]) ? sanitize_text_field($_POST[$meta_key]) : '';
            }
            update_post_meta($post_id, $meta_key, $val);
        }

        // Save preset selection (informational — actual settings are stored in avg_* keys)
        if ( isset( $_POST['avg_preset'] ) ) {
            update_post_meta( $post_id, 'avg_preset', sanitize_text_field( $_POST['avg_preset'] ) );
        }

        // Clear cached thumbnails so the next page load re-fetches at the
        // current resolution and picks up any changes on the provider side.
        $this->clear_video_transients($post_id);
    }

    private function clear_video_transients($post_id) {
        $items = get_post_meta($post_id, 'avg_videos', true);
        if (!is_array($items)) return;

        $sizes = ['maxres', 'standard', 'high', 'medium', 'default'];

        foreach ($items as $item) {
            $parsed = $this->normalize_video_url($item['url'] ?? '');
            if (!$parsed || empty($parsed['id'])) continue;
            $id       = $parsed['id'];
            $provider = $parsed['provider'];

            foreach ($sizes as $size) {
                if ($provider === 'youtube') {
                    delete_transient('anchor_vs_yt_' . $size . '_' . $id);
                } elseif ($provider === 'vimeo') {
                    delete_transient('anchor_vs_vm_' . $size . '_' . md5($id));
                }
            }
        }
    }

    /* ══════════════════════════════════════════════════════════
       Admin Assets (proven post.php / post-new.php pattern)
       ══════════════════════════════════════════════════════════ */

    public function enqueue_admin_assets($hook) {
        global $post;
        if (($hook === 'post-new.php' || $hook === 'post.php') && isset($post) && $post->post_type === self::CPT) {
            wp_enqueue_media();

            $base_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-gallery/assets/';
            $ver = filemtime($base_dir . 'admin.js');

            // Frontend styles/script for preview
            wp_enqueue_style('anchor-video-gallery', Anchor_Asset_Loader::url('anchor-gallery/assets/anchor-video-slider.css'), [], filemtime($base_dir . 'anchor-video-slider.css'));
            wp_enqueue_script('anchor-video-gallery', Anchor_Asset_Loader::url('anchor-gallery/assets/anchor-video-slider.js'), [], filemtime($base_dir . 'anchor-video-slider.js'), true);

            // Admin
            wp_enqueue_style('anchor-video-gallery-admin', Anchor_Asset_Loader::url('anchor-gallery/assets/admin.css'), [], $ver);
            wp_enqueue_script('anchor-video-gallery-admin', Anchor_Asset_Loader::url('anchor-gallery/assets/admin.js'), ['jquery'], $ver, true);
            wp_localize_script('anchor-video-gallery-admin', 'AVG', [
                'ajaxUrl'        => admin_url('admin-ajax.php'),
                'nonce'          => wp_create_nonce('avg_preview'),
                'forcedByLayout' => $this->get_forced_by_layout_map(),
            ]);

            // Shared builder chrome
            $builder_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'includes/builder/assets/';
            wp_enqueue_style('anchor-builder', Anchor_Asset_Loader::url('includes/builder/assets/builder.css'), [], filemtime($builder_dir . 'builder.css'));
            wp_enqueue_script('anchor-builder', Anchor_Asset_Loader::url('includes/builder/assets/builder.js'), ['jquery'], filemtime($builder_dir . 'builder.js'), true);
        }
    }

    /* ══════════════════════════════════════════════════════════
       Frontend Assets
       ══════════════════════════════════════════════════════════ */

    public function enqueue_assets() {
        $base_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-gallery/assets/';

        $up_css_path = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-universal-popups/assets/frontend.css';
        if (file_exists($up_css_path)) {
            wp_enqueue_style('up-frontend', Anchor_Asset_Loader::url('anchor-universal-popups/assets/frontend.css'), [], filemtime($up_css_path));
        }

        wp_enqueue_style('anchor-video-gallery', Anchor_Asset_Loader::url('anchor-gallery/assets/anchor-video-slider.css'), [], filemtime($base_dir . 'anchor-video-slider.css'));
        wp_enqueue_script('anchor-video-gallery', Anchor_Asset_Loader::url('anchor-gallery/assets/anchor-video-slider.js'), [], filemtime($base_dir . 'anchor-video-slider.js'), true);
    }

    /* ══════════════════════════════════════════════════════════
       AJAX Preview
       ══════════════════════════════════════════════════════════ */

    public function ajax_preview() {
        check_ajax_referer('avg_preview', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $posted = isset($_POST['settings']) && is_array($_POST['settings']) ? $_POST['settings'] : [];
        $settings = [];
        foreach ($this->default_settings as $key => $default) {
            if (isset($posted[$key])) {
                $value = $posted[$key];
                if (is_bool($default)) {
                    $settings[$key] = ($value === '1' || $value === 'true' || $value === true);
                } elseif (is_int($default)) {
                    $settings[$key] = intval($value);
                } else {
                    $settings[$key] = sanitize_text_field($value);
                }
            } else {
                $settings[$key] = $default;
            }
        }

        // Prefer items posted from the editor (unsaved state) so the live
        // preview matches what the user is currently arranging. Fall back
        // to saved meta if the form snapshot is missing.
        $live_items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : null;
        if ( is_array( $live_items ) ) {
            $items = [];
            foreach ( $live_items as $row ) {
                if ( ! is_array( $row ) ) continue;
                $type = sanitize_text_field( $row['type'] ?? 'video' );
                if ( ! in_array( $type, [ 'video', 'image', 'html' ], true ) ) $type = 'video';
                $raw_cats = $row['categories'] ?? [];
                if ( is_string( $raw_cats ) ) {
                    $raw_cats = explode( ',', $raw_cats );
                } elseif ( ! is_array( $raw_cats ) ) {
                    $raw_cats = [];
                }
                $cats = [];
                foreach ( $raw_cats as $c ) {
                    $c = sanitize_text_field( trim( (string) $c ) );
                    if ( $c !== '' ) $cats[] = $c;
                }
                $link_target = sanitize_text_field( $row['link_target'] ?? '_self' );
                if ( ! in_array( $link_target, [ '_self', '_blank' ], true ) ) $link_target = '_self';

                $items[] = [
                    'type'                => $type,
                    'url'                 => esc_url_raw( $row['url'] ?? '' ),
                    'title'               => sanitize_text_field( $row['title'] ?? '' ),
                    'attachment_id'       => absint( $row['attachment_id'] ?? 0 ),
                    'html'                => wp_kses_post( $row['html'] ?? '' ),
                    'alt'                 => sanitize_text_field( $row['alt'] ?? '' ),
                    'caption'             => wp_kses_post( $row['caption'] ?? '' ),
                    'categories'          => $cats,
                    'custom_thumbnail_id' => absint( $row['custom_thumbnail_id'] ?? 0 ),
                    'link_url'            => esc_url_raw( $row['link_url'] ?? '' ),
                    'link_target'         => $link_target,
                ];
            }
        } else {
            $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
            $saved   = $post_id ? get_post_meta( $post_id, 'avg_videos', true ) : [];
            $items   = is_array( $saved ) ? $saved : [];
        }

        if ( empty( $items ) ) {
            $videos = $this->sample_videos;
            foreach ( $videos as &$v ) {
                $v['raw_url'] = 'https://youtube.com/watch?v=' . $v['id'];
            }
            unset( $v );
        } else {
            $videos = $this->parse_videos_from_rows( $items );
            if ( empty( $videos ) ) {
                // All items were placeholders/empty — fall back to samples
                // so the user still sees something rather than a blank box.
                $videos = $this->sample_videos;
                foreach ( $videos as &$v ) {
                    $v['raw_url'] = 'https://youtube.com/watch?v=' . $v['id'];
                }
                unset( $v );
            }
        }

        $videos = $this->hydrate_video_metadata( $videos, $settings['thumb_size'] ?? 'maxres' );

        $html = $this->render_dispatch('avg-preview-' . uniqid(), $videos, $settings);
        wp_send_json_success(['html' => $html]);
    }

    /* ══════════════════════════════════════════════════════════
       Shortcode
       ══════════════════════════════════════════════════════════ */

    public function render_gallery($atts) {
        $atts = shortcode_atts([
            'id'       => '',
            'videos'   => '',
            'autoplay' => '',
            'layout'   => '',
            'columns'  => '',
            'theme'    => '',
            'popup'    => '',
        ], $atts);

        $gallery_id = trim((string) $atts['id']);
        $videos = [];
        $settings = $this->default_settings;

        if ($gallery_id !== '') {
            // Resolve CPT post: by ID, slug, or legacy option fallback
            $post = null;
            if (is_numeric($gallery_id)) {
                $post = $this->get_renderable_gallery_post((int) $gallery_id);
            }
            if (!$post) {
                $found = get_posts([
                    'post_type'      => [self::CPT, self::OLD_CPT],
                    'name'           => $gallery_id,
                    'posts_per_page' => 1,
                    'post_status'    => 'publish',
                ]);
                $post = $found[0] ?? null;
            }

            // Legacy option fallback
            if (!$post) {
                $legacy = get_option(self::LEGACY_KEY, []);
                if (is_array($legacy) && isset($legacy[$gallery_id])) {
                    $gallery = $legacy[$gallery_id];
                    $videos  = $this->parse_videos_from_rows($gallery['videos'] ?? []);
                    $settings = wp_parse_args($gallery, $this->default_settings);
                    return $this->apply_shortcode_overrides_and_render($atts, $videos, $settings);
                }
                return '';
            }

            // Load from CPT meta
            $raw_videos = get_post_meta($post->ID, 'avg_videos', true);
            $videos = $this->parse_videos_from_rows(is_array($raw_videos) ? $raw_videos : []);
            foreach ($this->default_settings as $key => $default) {
                $saved = get_post_meta($post->ID, 'avg_' . $key, true);
                if ($saved !== '' && $saved !== false) {
                    if (is_bool($default)) {
                        $settings[$key] = (bool) $saved;
                    } elseif (is_int($default)) {
                        $settings[$key] = (int) $saved;
                    } else {
                        $settings[$key] = $saved;
                    }
                }
            }
        } else {
            // Inline videos mode
            $videos_raw = trim((string) $atts['videos']);
            if ($videos_raw === '') return '';
            $videos = $this->parse_videos($videos_raw);
        }

        return $this->apply_shortcode_overrides_and_render($atts, $videos, $settings);
    }

    private function get_renderable_gallery_post($post_id) {
        $post = get_post((int) $post_id);
        if (!$post || !in_array($post->post_type, [self::CPT, self::OLD_CPT], true) || $post->post_status !== 'publish') {
            return null;
        }
        return $post;
    }

    private function apply_shortcode_overrides_and_render($atts, $videos, $settings) {
        if (empty($videos)) return '';

        if ($atts['autoplay'] !== '') {
            $settings['autoplay'] = ($atts['autoplay'] === '1' || $atts['autoplay'] === 'true');
        }
        if ($atts['layout'] !== '' && in_array($atts['layout'], ['slider', 'grid', 'carousel', 'masonry', 'logo_carousel'])) {
            $settings['layout'] = $atts['layout'];
        }
        if ($atts['columns'] !== '') {
            $settings['columns_desktop'] = max(2, min(6, intval($atts['columns'])));
        }
        if ($atts['theme'] !== '' && in_array($atts['theme'], ['light', 'dark', 'auto'])) {
            $settings['theme'] = $atts['theme'];
        }
        if ($atts['popup'] !== '' && in_array($atts['popup'], ['lightbox', 'inline', 'theater', 'side_panel', 'none'])) {
            $settings['popup_style'] = $atts['popup'];
        }

        $videos = $this->hydrate_video_metadata($videos, $settings['thumb_size'] ?? 'maxres');

        return $this->render_dispatch('avg-' . uniqid(), $videos, $settings);
    }

    /* ══════════════════════════════════════════════════════════
       Legacy Data Migration
       ══════════════════════════════════════════════════════════ */

    private function migrate_legacy_data() {
        $legacy = get_option(self::LEGACY_KEY, []);
        if (!is_array($legacy) || empty($legacy)) return;

        // Only migrate once — check transient flag
        if (get_transient('avg_migrated')) return;

        foreach ($legacy as $id => $gallery) {
            // Skip if a CPT with this slug already exists
            $existing = get_posts([
                'post_type'      => self::CPT,
                'name'           => sanitize_title($id),
                'posts_per_page' => 1,
                'post_status'    => 'any',
            ]);
            if (!empty($existing)) continue;

            $post_id = wp_insert_post([
                'post_type'   => self::CPT,
                'post_title'  => $gallery['title'] ?: $id,
                'post_name'   => sanitize_title($id),
                'post_status' => 'publish',
            ]);

            if (!$post_id || is_wp_error($post_id)) continue;

            // Save videos
            $videos = [];
            foreach (($gallery['videos'] ?? []) as $v) {
                if (!is_array($v) || empty($v['url'])) continue;
                $videos[] = [
                    'type'          => 'video',
                    'url'           => $v['url'],
                    'title'         => $v['title'] ?? '',
                    'attachment_id' => 0,
                ];
            }
            update_post_meta($post_id, 'avg_videos', $videos);

            // Save each setting
            foreach ($this->default_settings as $key => $default) {
                if (isset($gallery[$key])) {
                    $val = $gallery[$key];
                    if (is_bool($default)) {
                        $val = $val ? '1' : '';
                    }
                    update_post_meta($post_id, 'avg_' . $key, $val);
                }
            }
        }

        delete_option(self::LEGACY_KEY);
        set_transient('avg_migrated', 1, HOUR_IN_SECONDS);
    }

    /* ══════════════════════════════════════════════════════════
       Frontend Rendering: Standard Layouts
       ══════════════════════════════════════════════════════════ */

    private function render_output($uid, $videos, $settings) {
        $layout = $settings['layout'];
        $classes = [
            'anchor-video-gallery',
            'avg-layout-' . $layout,
            'avg-theme-' . $settings['theme'],
            'avg-tiles-' . $settings['tile_style'],
            'avg-hover-' . $settings['hover_effect'],
            'avg-play-' . $settings['play_button_style'],
        ];

        if (!empty($settings['equal_height'])) {
            $classes[] = 'avg-equal-height';
        }

        $title_position = $settings['title_position'] ?? 'hidden';

        $data_attrs = [
            'data-autoplay' => $settings['autoplay'] ? '1' : '0',
            'data-popup'    => $settings['popup_style'],
            'data-layout'   => $layout,
        ];

        if (in_array($layout, ['grid', 'masonry'])) {
            $data_attrs['data-columns-desktop'] = $settings['columns_desktop'];
            $data_attrs['data-columns-tablet']  = $settings['columns_tablet'];
            $data_attrs['data-columns-mobile']  = $settings['columns_mobile'];
            $data_attrs['data-gap']             = $settings['gap'];

            if ($settings['pagination_enabled']) {
                $data_attrs['data-pagination'] = $settings['pagination_style'];
                $data_attrs['data-per-page']   = $settings['videos_per_page'];
            }
        }

        if (in_array($layout, ['slider', 'carousel'])) {
            $data_attrs['data-arrows']           = $settings['slider_arrows'] ? '1' : '0';
            $data_attrs['data-dots']             = $settings['slider_dots'] ? '1' : '0';
            $data_attrs['data-slider-autoplay']  = $settings['slider_autoplay'] ? '1' : '0';
            $data_attrs['data-autoplay-speed']   = $settings['slider_autoplay_speed'];

            if ($layout === 'carousel') {
                $data_attrs['data-loop']         = $settings['carousel_loop'] ? '1' : '0';
                $data_attrs['data-center']       = $settings['carousel_center'] ? '1' : '0';
                $data_attrs['data-cols-desktop'] = $settings['columns_desktop'];
                $data_attrs['data-cols-tablet']  = $settings['columns_tablet'];
                $data_attrs['data-cols-mobile']  = $settings['columns_mobile'];
            }
        }

        // Aspect ratio: cinematic tile style forces 2.35:1, otherwise use setting
        $ratio_map = ['16:9' => '16 / 9', '4:3' => '4 / 3', '1:1' => '1 / 1', '9:16' => '9 / 16', '21:9' => '21 / 9'];
        $thumb_ratio = ($settings['tile_style'] === 'cinematic')
            ? '2.35 / 1'
            : ($ratio_map[$settings['thumb_aspect_ratio']] ?? '16 / 9');

        $gap_mobile = intval($settings['gap_mobile'] ?? 0);
        if ($gap_mobile <= 0) {
            $gap_mobile = intval($settings['gap']);
        }

        $style_vars = [
            '--avg-gap: ' . intval($settings['gap']) . 'px',
            '--avg-gap-mobile: ' . $gap_mobile . 'px',
            '--avg-radius: ' . intval($settings['border_radius']) . 'px',
            '--avg-cols-desktop: ' . intval($settings['columns_desktop']),
            '--avg-cols-tablet: ' . intval($settings['columns_tablet']),
            '--avg-cols-mobile: ' . intval($settings['columns_mobile']),
            '--avg-thumb-ratio: ' . $thumb_ratio,
            '--avg-object-fit: ' . esc_attr($settings['object_fit']),
        ];

        $max_height = intval($settings['max_height']);
        if ($max_height > 0) {
            $style_vars[] = '--avg-max-height: ' . $max_height . 'px';
            $classes[] = 'avg-has-max-height';
        }

        $shadow = !empty($settings['tile_shadow']) ? $settings['tile_shadow'] : 'soft';
        $classes[] = 'avg-shadow-' . sanitize_html_class($shadow);

        $eager_count = max(0, intval($settings['eager_load_count'] ?? 4));

        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>"
             id="<?php echo esc_attr($uid); ?>"
             <?php foreach ($data_attrs as $key => $val): ?>
                <?php echo esc_attr($key); ?>="<?php echo esc_attr($val); ?>"
             <?php endforeach; ?>
             style="<?php echo esc_attr(implode('; ', $style_vars)); ?>">

            <?php if (in_array($layout, ['slider', 'carousel']) && $settings['slider_arrows']): ?>
            <button type="button" class="avg-nav avg-nav-prev" aria-label="<?php esc_attr_e('Previous', 'anchor-schema'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15,6 9,12 15,18"></polyline></svg>
            </button>
            <?php endif; ?>

            <div class="avg-track">
                <?php
                $total = count($videos);
                $paginate = $settings['pagination_enabled'] && in_array($layout, ['grid', 'masonry']);
                $per_page = $paginate ? $settings['videos_per_page'] : $total;

                foreach ($videos as $i => $video):
                    $hidden = $paginate && $i >= $per_page;
                    $provider  = $video['provider'] ?? 'video';
                    $is_html   = $provider === 'html';
                    $is_image  = $provider === 'image';
                    $item_type = $is_html ? 'html' : ( $is_image ? 'image' : 'video' );

                    // Build category slug list (space-separated) for filterable.
                    $cat_slug_attr = '';
                    $cats_arr = $video['categories'] ?? [];
                    if ( ! is_array( $cats_arr ) ) $cats_arr = [];
                    if ( empty( $cats_arr ) && ! empty( $video['category'] ) ) {
                        $cats_arr = [ $video['category'] ];
                    }
                    if ( ! empty( $cats_arr ) ) {
                        $slugs = array_filter( array_map( 'sanitize_title', $cats_arr ) );
                        if ( $slugs ) $cat_slug_attr = implode( ' ', $slugs );
                    }

                    // Per-item link override (only honored when popup_style is 'none').
                    $item_link    = trim( (string) ( $video['link_url'] ?? '' ) );
                    $item_target  = (string) ( $video['link_target'] ?? '_self' );
                    $use_link     = ( $item_link !== '' && ( $settings['popup_style'] ?? '' ) === 'none' );
                ?>
                <?php if ( $is_html ) : ?>
                <div class="avg-tile avg-tile-html<?php echo $hidden ? ' avg-hidden' : ''; ?>"
                     data-index="<?php echo esc_attr( $i ); ?>"
                     data-type="html"
                     <?php if ( $cat_slug_attr !== '' ) : ?>data-category="<?php echo esc_attr( $cat_slug_attr ); ?>"<?php endif; ?>>
                    <div class="avg-tile-inner">
                        <div class="avg-html-content"><?php echo do_shortcode( wp_kses_post( $video['html'] ?? '' ) ); ?></div>
                    </div>
                </div>
                <?php continue; endif; ?>
                <?php if ( $use_link ) : ?>
                <a class="avg-tile avg-tile-linked<?php echo $hidden ? ' avg-hidden' : ''; ?>"
                   href="<?php echo esc_url( $item_link ); ?>"
                   target="<?php echo esc_attr( $item_target ); ?>"
                   <?php if ( $item_target === '_blank' ) : ?>rel="noopener"<?php endif; ?>
                   data-index="<?php echo esc_attr( $i ); ?>"
                   data-type="<?php echo esc_attr( $item_type ); ?>"
                   <?php if ( $cat_slug_attr !== '' ) : ?>data-category="<?php echo esc_attr( $cat_slug_attr ); ?>"<?php endif; ?>>
                <?php else : ?>
                <div class="avg-tile<?php echo $hidden ? ' avg-hidden' : ''; ?>"
                     data-index="<?php echo esc_attr($i); ?>"
                     data-type="<?php echo esc_attr($item_type); ?>"
                     <?php if ( $cat_slug_attr !== '' ): ?>data-category="<?php echo esc_attr( $cat_slug_attr ); ?>"<?php endif; ?>
                     <?php if (!$is_image): ?>
                     data-provider="<?php echo esc_attr($video['provider']); ?>"
                     data-video-id="<?php echo esc_attr($video['id']); ?>"
                     data-url="<?php echo esc_attr($video['raw_url'] ?? ''); ?>"
                     <?php if (!empty($video['duration'])): ?>data-duration="<?php echo esc_attr($video['duration']); ?>"<?php endif; ?>
                     <?php else: ?>
                     data-full-url="<?php echo esc_url($video['full_url'] ?? $video['thumb']); ?>"
                     <?php endif; ?>>
                <?php endif; ?>
                    <div class="avg-tile-inner">
                        <div class="avg-thumb">
                            <?php if (!empty($video['thumb'])):
                                $is_eager = $i < $eager_count;
                                $alt_text = ! empty( $video['alt'] ) ? $video['alt'] : ( ! empty( $video['label'] ) ? $video['label'] : '' );
                            ?>
                            <img class="avg-thumb-img"
                                 src="<?php echo esc_url($video['thumb']); ?>"
                                 alt="<?php echo esc_attr($alt_text); ?>"
                                 loading="<?php echo $is_eager ? 'eager' : 'lazy'; ?>"
                                 decoding="async"
                                 <?php if ($is_eager && $i === 0): ?>fetchpriority="high"<?php endif; ?> />
                            <?php endif; ?>
                            <?php if (!$is_image && $settings['play_button_style'] !== 'none'): ?>
                            <span class="avg-play" aria-hidden="true">
                                <?php echo $this->get_play_button_svg($settings['play_button_style']); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!$is_image && $settings['show_duration'] && !empty($video['duration'])): ?>
                            <span class="avg-duration"><?php echo esc_html($video['duration']); ?></span>
                            <?php endif; ?>
                            <?php if ($title_position === 'overlay' && !empty($video['label'])): ?>
                            <div class="avg-title-overlay">
                                <span class="avg-title"><?php echo esc_html($video['label']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php
                        $show_meta_below = false;
                        if ($title_position === 'below' && !empty($video['label'])) {
                            $show_meta_below = true;
                        } elseif ($title_position === 'hidden' && ($settings['show_title'] || $settings['show_channel'])) {
                            $show_meta_below = true;
                        }
                        ?>
                        <?php if ($show_meta_below): ?>
                        <div class="avg-meta">
                            <?php if ($title_position === 'below'): ?>
                            <span class="avg-title"><?php echo esc_html($video['label']); ?></span>
                            <?php else: ?>
                                <?php if ($settings['show_title']): ?>
                                <span class="avg-title"><?php echo esc_html($video['label']); ?></span>
                                <?php endif; ?>
                                <?php if ($settings['show_channel'] && !empty($video['channel'])): ?>
                                <span class="avg-channel"><?php echo esc_html($video['channel']); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ( ! empty( $video['caption'] ) ) : ?>
                            <span class="avg-caption-wrap"><span class="avg-caption"><?php echo wp_kses_post( $video['caption'] ); ?></span></span>
                            <?php endif; ?>
                        </div>
                        <?php elseif ( ! empty( $video['caption'] ) ) : ?>
                        <div class="avg-meta avg-meta-caption-only">
                            <span class="avg-caption-wrap"><span class="avg-caption"><?php echo wp_kses_post( $video['caption'] ); ?></span></span>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php if ( $use_link ) : ?>
                </a>
                <?php else : ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <?php if (in_array($layout, ['slider', 'carousel']) && $settings['slider_arrows']): ?>
            <button type="button" class="avg-nav avg-nav-next" aria-label="<?php esc_attr_e('Next', 'anchor-schema'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9,6 15,12 9,18"></polyline></svg>
            </button>
            <?php endif; ?>

            <?php if (in_array($layout, ['slider', 'carousel']) && $settings['slider_dots']): ?>
            <div class="avg-dots"></div>
            <?php endif; ?>

            <?php if ($paginate && $total > $per_page): ?>
            <div class="avg-pagination" data-total="<?php echo esc_attr($total); ?>" data-per-page="<?php echo esc_attr($per_page); ?>">
                <?php if ($settings['pagination_style'] === 'numbered'): ?>
                    <div class="avg-pagination-numbers"></div>
                <?php elseif ($settings['pagination_style'] === 'load_more'): ?>
                    <button type="button" class="avg-load-more"><?php esc_html_e('Load More', 'anchor-schema'); ?></button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ══════════════════════════════════════════════════════════
       Frontend Rendering: Dispatch
       ══════════════════════════════════════════════════════════ */

    private function render_dispatch($uid, $videos, $settings) {
        $layout = $settings['layout'] ?? 'slider';

        if ( $layout === 'logo_carousel' ) {
            return $this->render_logo_carousel( $uid, $videos, $settings );
        }
        if ( $layout === 'gallery' || $layout === 'thumbnail_gallery' ) {
            return $this->render_gallery_layout( $uid, $videos, $settings );
        }
        if ( $layout === 'filterable' ) {
            return $this->render_filterable_layout( $uid, $videos, $settings );
        }
        if ( $layout === 'paginated' ) {
            // Force pagination on + treat as grid for the underlying renderer.
            $settings['pagination_enabled'] = 1;
            $settings['layout']             = 'grid';
            $output                         = $this->render_output( $uid, $videos, $settings );
            // Re-tag with paginated layout class so CSS can target it.
            return str_replace( 'avg-layout-grid', 'avg-layout-grid avg-layout-paginated', $output );
        }
        if ( $layout === 'lightbox_grid' ) {
            $settings['popup_style'] = 'lightbox';
            $settings['layout']      = 'grid';
            $output                  = $this->render_output( $uid, $videos, $settings );
            return str_replace( 'avg-layout-grid', 'avg-layout-grid avg-layout-lightbox-grid', $output );
        }
        if ( $layout === 'card_carousel' ) {
            $settings['layout'] = 'carousel';
            $output             = $this->render_output( $uid, $videos, $settings );
            return str_replace( 'avg-layout-carousel', 'avg-layout-carousel avg-layout-card-carousel', $output );
        }
        if ( $layout === 'bento' ) {
            $settings['layout'] = 'grid';
            $output             = $this->render_output( $uid, $videos, $settings );
            return str_replace( 'avg-layout-grid', 'avg-layout-grid avg-layout-bento', $output );
        }
        return $this->render_output( $uid, $videos, $settings );
    }

    /**
     * Filterable grid: renders category filter buttons above a grid where
     * each tile carries a data-category attribute. Categories come from
     * each video item's optional 'category' field.
     */
    private function render_filterable_layout( $uid, $videos, $settings ) {
        if ( empty( $videos ) ) {
            return '';
        }

        // Collect distinct categories from each item's `categories` array
        // (with single-string `category` as fallback). First-seen order.
        $cats = [];
        foreach ( $videos as $v ) {
            $list = $v['categories'] ?? [];
            if ( ! is_array( $list ) ) $list = [];
            if ( empty( $list ) && ! empty( $v['category'] ) ) {
                $list = [ $v['category'] ];
            }
            foreach ( $list as $c ) {
                $c = trim( (string) $c );
                if ( $c !== '' && ! in_array( $c, $cats, true ) ) {
                    $cats[] = $c;
                }
            }
        }

        // Render the underlying grid with category tags applied
        $original_layout    = $settings['layout'];
        $settings['layout'] = 'grid';
        $grid_html          = $this->render_output( $uid, $videos, $settings );
        $settings['layout'] = $original_layout;

        // Re-tag with filterable layout class
        $grid_html = str_replace( 'avg-layout-grid', 'avg-layout-grid avg-layout-filterable', $grid_html );

        // Inject data-category attributes onto tiles. Simple heuristic:
        // wrap the grid in a filterable shell with buttons. Tiles already
        // exist; clients that want category filtering can use the data-*
        // attribute that tiles emit (added below in render_output via
        // item data — for now categories are stored per item but the
        // grid tiles need the attribute applied via a small hook).
        ob_start();
        ?>
        <div class="avg-filterable-shell">
            <?php if ( ! empty( $cats ) ) : ?>
                <div class="avg-filter-bar" role="tablist">
                    <button type="button" class="avg-filter is-active" data-filter="*">All</button>
                    <?php foreach ( $cats as $cat ) : ?>
                        <button type="button" class="avg-filter" data-filter="<?php echo esc_attr( sanitize_title( $cat ) ); ?>"><?php echo esc_html( $cat ); ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php echo $grid_html; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ══════════════════════════════════════════════════════════
       Frontend Rendering: Gallery Layout (Featured + Strip)
       ══════════════════════════════════════════════════════════ */

    private function render_gallery_layout($uid, $videos, $settings) {
        if (empty($videos)) return '';

        $classes = [
            'anchor-video-gallery',
            'avg-layout-gallery',
            'avg-theme-' . $settings['theme'],
            'avg-play-' . $settings['play_button_style'],
        ];

        $style_vars = [
            '--avg-gap: '    . intval($settings['gap']) . 'px',
            '--avg-radius: ' . intval($settings['border_radius']) . 'px',
        ];

        $data_attrs = [
            'data-layout'   => 'gallery',
            'data-popup'    => $settings['popup_style'],
            'data-autoplay' => $settings['autoplay'] ? '1' : '0',
        ];

        $first      = $videos[0];
        $first_type = $first['provider'] === 'image' ? 'image' : 'video';
        $first_img  = $first_type === 'image';

        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>"
             id="<?php echo esc_attr($uid); ?>"
             <?php foreach ($data_attrs as $key => $val): ?>
                <?php echo esc_attr($key); ?>="<?php echo esc_attr($val); ?>"
             <?php endforeach; ?>
             style="<?php echo esc_attr(implode('; ', $style_vars)); ?>">

            <!-- Featured video -->
            <div class="avg-tile avg-gallery-featured"
                 tabindex="0" role="button"
                 data-index="0"
                 data-type="<?php echo esc_attr($first_type); ?>"
                 <?php if (!$first_img): ?>
                 data-provider="<?php echo esc_attr($first['provider']); ?>"
                 data-video-id="<?php echo esc_attr($first['id']); ?>"
                 data-url="<?php echo esc_attr($first['raw_url'] ?? ''); ?>"
                 <?php else: ?>
                 data-full-url="<?php echo esc_url($first['full_url'] ?? $first['thumb']); ?>"
                 <?php endif; ?>>
                <div class="avg-thumb">
                    <?php if (!empty($first['thumb'])): ?>
                    <img class="avg-thumb-img"
                         src="<?php echo esc_url($first['thumb']); ?>"
                         alt="<?php echo esc_attr($first['label'] ?? ''); ?>"
                         loading="eager"
                         decoding="async"
                         fetchpriority="high" />
                    <?php endif; ?>
                    <?php if (!$first_img && $settings['play_button_style'] !== 'none'): ?>
                    <span class="avg-play" aria-hidden="true">
                        <?php echo $this->get_play_button_svg($settings['play_button_style']); ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!$first_img && $settings['show_duration'] && !empty($first['duration'])): ?>
                    <span class="avg-duration"><?php echo esc_html($first['duration']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($settings['show_title'] && !empty($first['label'])): ?>
                <div class="avg-gallery-featured-title"><?php echo esc_html($first['label']); ?></div>
                <?php endif; ?>
            </div>

            <!-- Thumbnail strip -->
            <div class="avg-gallery-strip-wrapper">
                <button type="button" class="avg-nav avg-nav-prev" aria-label="<?php esc_attr_e('Previous', 'anchor-schema'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15,6 9,12 15,18"></polyline></svg>
                </button>
                <div class="avg-gallery-strip">
                    <?php foreach ($videos as $i => $video):
                        $item_type = $video['provider'] === 'image' ? 'image' : 'video';
                        $is_image  = $item_type === 'image';
                    ?>
                    <div class="avg-gallery-thumb<?php echo $i === 0 ? ' active' : ''; ?>"
                         data-index="<?php echo esc_attr($i); ?>"
                         data-type="<?php echo esc_attr($item_type); ?>"
                         <?php if (!$is_image): ?>
                         data-provider="<?php echo esc_attr($video['provider']); ?>"
                         data-video-id="<?php echo esc_attr($video['id']); ?>"
                         data-url="<?php echo esc_attr($video['raw_url'] ?? ''); ?>"
                         <?php else: ?>
                         data-full-url="<?php echo esc_url($video['full_url'] ?? $video['thumb']); ?>"
                         <?php endif; ?>
                         data-thumb="<?php echo esc_url($video['thumb'] ?? ''); ?>"
                         data-label="<?php echo esc_attr($video['label'] ?? ''); ?>"
                         data-duration="<?php echo esc_attr($video['duration'] ?? ''); ?>"
                         title="<?php echo esc_attr($video['label'] ?? ''); ?>">
                        <div class="avg-gallery-thumb-img">
                            <?php if (!empty($video['thumb'])): ?>
                            <img class="avg-thumb-img"
                                 src="<?php echo esc_url($video['thumb']); ?>"
                                 alt="<?php echo esc_attr($video['label'] ?? ''); ?>"
                                 loading="<?php echo $i < 6 ? 'eager' : 'lazy'; ?>"
                                 decoding="async" />
                            <?php endif; ?>
                            <?php if (!$is_image && $settings['play_button_style'] !== 'none'): ?>
                            <span class="avg-play" aria-hidden="true">
                                <?php echo $this->get_play_button_svg($settings['play_button_style']); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!$is_image && $settings['show_duration'] && !empty($video['duration'])): ?>
                            <span class="avg-duration"><?php echo esc_html($video['duration']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="avg-nav avg-nav-next" aria-label="<?php esc_attr_e('Next', 'anchor-schema'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9,6 15,12 9,18"></polyline></svg>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ══════════════════════════════════════════════════════════
       Frontend Rendering: Logo Carousel (Marquee)
       ══════════════════════════════════════════════════════════ */

    private function render_logo_carousel($uid, $items, $settings) {
        if (empty($items)) return '';

        $pause_class = !empty($settings['marquee_pause_on_hover']) ? ' avg-marquee-pause' : '';
        $direction = ($settings['marquee_direction'] === 'right') ? 'reverse' : 'normal';

        $width_mobile  = intval($settings['marquee_item_width_mobile'] ?? 0)  ?: intval($settings['marquee_item_width']);
        $height        = intval($settings['marquee_item_height'] ?? 80);
        $height_mobile = intval($settings['marquee_item_height_mobile'] ?? 0) ?: $height;
        $gap_mobile    = intval($settings['marquee_gap_mobile'] ?? 0)         ?: intval($settings['marquee_gap']);
        $speed_mobile  = intval($settings['marquee_speed_mobile'] ?? 0)       ?: intval($settings['marquee_speed']);
        $align         = !empty($settings['marquee_align']) ? $settings['marquee_align'] : 'center';
        $grayscale     = !empty($settings['marquee_grayscale']);
        $eager_count   = max(0, intval($settings['marquee_eager_count'] ?? 6));

        $style_vars = [
            '--avg-marquee-speed: ' . intval($settings['marquee_speed']) . 's',
            '--avg-marquee-speed-mobile: ' . $speed_mobile . 's',
            '--avg-marquee-gap: ' . intval($settings['marquee_gap']) . 'px',
            '--avg-marquee-gap-mobile: ' . $gap_mobile . 'px',
            '--avg-marquee-item-width: ' . intval($settings['marquee_item_width']) . 'px',
            '--avg-marquee-item-width-mobile: ' . $width_mobile . 'px',
            '--avg-marquee-item-height: ' . $height . 'px',
            '--avg-marquee-item-height-mobile: ' . $height_mobile . 'px',
            '--avg-marquee-align: ' . esc_attr($align),
            '--avg-radius: ' . intval($settings['border_radius']) . 'px',
            '--avg-object-fit: ' . esc_attr($settings['object_fit']),
        ];

        $extra_class = $grayscale ? ' avg-marquee-grayscale' : '';

        ob_start();
        ?>
        <div class="anchor-video-gallery avg-layout-logo-carousel<?php echo esc_attr($extra_class); ?>"
             id="<?php echo esc_attr($uid); ?>"
             data-layout="logo_carousel"
             style="<?php echo esc_attr(implode('; ', $style_vars)); ?>">

            <div class="avg-marquee-row<?php echo esc_attr($pause_class); ?>">
                <div class="avg-marquee" style="animation-direction: <?php echo esc_attr($direction); ?>">
                    <div class="avg-marquee-group">
                        <?php foreach ($items as $idx => $item): ?>
                        <div class="avg-marquee-item">
                            <?php if (!empty($item['thumb'])): $is_eager = $idx < $eager_count; ?>
                            <img src="<?php echo esc_url($item['thumb']); ?>" alt="<?php echo esc_attr($item['label'] ?? ''); ?>" loading="<?php echo $is_eager ? 'eager' : 'lazy'; ?>" decoding="async" />
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="avg-marquee-group" aria-hidden="true">
                        <?php foreach ($items as $item): ?>
                        <div class="avg-marquee-item">
                            <?php if (!empty($item['thumb'])): ?>
                            <img src="<?php echo esc_url($item['thumb']); ?>" alt="" loading="lazy" decoding="async" />
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($settings['marquee_reverse_row'])): ?>
            <div class="avg-marquee-row avg-marquee-reverse<?php echo esc_attr($pause_class); ?>">
                <div class="avg-marquee" style="animation-direction: <?php echo esc_attr($direction === 'normal' ? 'reverse' : 'normal'); ?>">
                    <div class="avg-marquee-group">
                        <?php foreach ($items as $idx => $item): ?>
                        <div class="avg-marquee-item">
                            <?php if (!empty($item['thumb'])): $is_eager = $idx < $eager_count; ?>
                            <img src="<?php echo esc_url($item['thumb']); ?>" alt="<?php echo esc_attr($item['label'] ?? ''); ?>" loading="<?php echo $is_eager ? 'eager' : 'lazy'; ?>" decoding="async" />
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="avg-marquee-group" aria-hidden="true">
                        <?php foreach ($items as $item): ?>
                        <div class="avg-marquee-item">
                            <?php if (!empty($item['thumb'])): ?>
                            <img src="<?php echo esc_url($item['thumb']); ?>" alt="" loading="lazy" decoding="async" />
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    private function get_play_button_svg($style) {
        switch ($style) {
            case 'youtube':
                return '<svg viewBox="0 0 68 48"><path fill="#f00" d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55c-2.93.78-4.63 3.26-5.42 6.19C.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.63-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z"/><path fill="#fff" d="M45 24L27 14v20"/></svg>';
            case 'square':
                return '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" fill="currentColor" opacity="0.8"/><polygon points="10,8 16,12 10,16" fill="#fff"/></svg>';
            case 'minimal':
                return '<svg viewBox="0 0 24 24"><polygon points="8,5 19,12 8,19" fill="currentColor"/></svg>';
            case 'circle':
            default:
                return '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="11" fill="currentColor" opacity="0.85"/><polygon points="10,8 16,12 10,16" fill="#fff"/></svg>';
        }
    }

    /* ══════════════════════════════════════════════════════════
       Video Parsing & Hydration
       ══════════════════════════════════════════════════════════ */

    private function parse_videos($raw) {
        $urls = preg_split('/[\r\n,]+/', $raw);
        $out = [];
        foreach ($urls as $row) {
            $row = trim((string) $row);
            if ($row === '') continue;
            $parts = array_map('trim', explode('|', $row));
            $url = $parts[0] ?? '';
            if ($url === '') continue;
            $video = $this->normalize_video_url($url);
            if (!$video) continue;
            if (isset($parts[1]) && $parts[1] !== '') $video['custom_thumb'] = $parts[1];
            if (isset($parts[2]) && $parts[2] !== '') $video['custom_title'] = $parts[2];
            $out[] = $video;
        }
        return $out;
    }

    private function parse_videos_from_rows($rows) {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $type = $row['type'] ?? 'video';

            // Common new fields. `category` (singular) is kept for back-compat
            // with the existing tile renderer; `categories` carries the full
            // array used by the filterable layout.
            $alt         = trim( (string) ( $row['alt']         ?? '' ) );
            $caption     = (string) ( $row['caption']     ?? '' );
            $custom_thb  = absint( $row['custom_thumbnail_id'] ?? 0 );
            $link_url    = trim( (string) ( $row['link_url']    ?? '' ) );
            $link_target = (string) ( $row['link_target'] ?? '_self' );
            if ( ! in_array( $link_target, [ '_self', '_blank' ], true ) ) $link_target = '_self';

            $raw_cats = $row['categories'] ?? ( $row['category'] ?? [] );
            if ( is_string( $raw_cats ) ) {
                $raw_cats = explode( ',', $raw_cats );
            } elseif ( ! is_array( $raw_cats ) ) {
                $raw_cats = [];
            }
            $categories = [];
            foreach ( $raw_cats as $c ) {
                $c = trim( (string) $c );
                if ( $c !== '' && ! in_array( $c, $categories, true ) ) $categories[] = $c;
            }

            $extras = [
                'alt'                 => $alt,
                'caption'             => $caption,
                'custom_thumbnail_id' => $custom_thb,
                'link_url'            => $link_url,
                'link_target'         => $link_target,
                'categories'          => $categories,
                'category'            => $categories ? $categories[0] : ( (string) ( $row['category'] ?? '' ) ),
            ];

            if ( $type === 'html' ) {
                $html = (string) ( $row['html'] ?? '' );
                if ( trim( $html ) === '' ) continue;
                $out[] = array_merge( [
                    'provider' => 'html',
                    'id'       => 'html-' . md5( $html ),
                    'thumb'    => '',
                    'full_url' => '',
                    'label'    => trim( (string) ( $row['title'] ?? '' ) ),
                    'raw_url'  => '',
                    'duration' => '',
                    'channel'  => '',
                    'html'     => $html,
                ], $extras );
                continue;
            }

            if ($type === 'image') {
                $att_id = absint($row['attachment_id'] ?? 0);
                if ($att_id === 0) continue;
                $img_url = wp_get_attachment_image_url($att_id, 'large');
                $full_url = wp_get_attachment_image_url($att_id, 'full');
                if (!$img_url) continue;
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    $title = get_the_title($att_id) ?: basename(get_attached_file($att_id) ?: '');
                }
                $out[] = array_merge( [
                    'provider'  => 'image',
                    'id'        => (string) $att_id,
                    'thumb'     => $img_url,
                    'full_url'  => $full_url ?: $img_url,
                    'label'     => $title,
                    'raw_url'   => '',
                    'duration'  => '',
                    'channel'   => '',
                ], $extras );
                continue;
            }

            // Video type
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') continue;
            $video = $this->normalize_video_url($url);
            if (!$video) continue;
            $title = trim((string) ($row['title'] ?? ''));
            if ($title !== '') $video['custom_title'] = $title;
            $thumb = trim((string) ($row['thumb'] ?? ''));
            if ($thumb !== '') $video['custom_thumb'] = $thumb;
            $desc = trim((string) ($row['description'] ?? ''));
            if ($desc !== '') $video['description'] = $desc;
            $out[] = array_merge( $video, $extras );
        }
        return $out;
    }

    private function normalize_video_url($url) {
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\\?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{6,})~', $url, $matches)) {
            return [
                'provider'       => 'youtube',
                'id'             => $matches[1],
                'thumb'          => '',
                'fallback_thumb' => 'https://img.youtube.com/vi/' . $matches[1] . '/hqdefault.jpg',
                'label'          => 'YouTube Video',
                'raw_url'        => $url,
                'duration'       => '',
                'channel'        => '',
            ];
        }

        if (preg_match('~vimeo\.com/(?:video/)?([0-9]+)~', $url, $matches)) {
            return [
                'provider'       => 'vimeo',
                'id'             => $matches[1],
                'thumb'          => '',
                'fallback_thumb' => '',
                'label'          => 'Vimeo Video',
                'raw_url'        => $url,
                'duration'       => '',
                'channel'        => '',
            ];
        }

        return null;
    }

    private function hydrate_video_metadata($videos, $thumb_size = 'maxres') {
        if (empty($videos)) return [];

        $yt_ids = [];
        $vm_ids = [];
        foreach ($videos as $video) {
            if ( in_array( $video['provider'] ?? '', [ 'image', 'html' ], true ) ) continue;
            if ($video['provider'] === 'youtube') $yt_ids[] = $video['id'];
            elseif ($video['provider'] === 'vimeo') $vm_ids[] = $video['id'];
        }

        $yt_details = $this->fetch_youtube_details($yt_ids, $thumb_size);
        $vm_details = $this->fetch_vimeo_details($vm_ids, $thumb_size);

        foreach ($videos as &$video) {
            // Custom thumbnail attachment applies to ALL types (including image/html).
            $custom_thb_id = absint( $video['custom_thumbnail_id'] ?? 0 );
            if ( $custom_thb_id ) {
                $resolved = wp_get_attachment_image_url( $custom_thb_id, 'large' );
                if ( $resolved ) {
                    $video['thumb'] = $resolved;
                    if ( ! empty( $video['provider'] ) && $video['provider'] === 'image' ) {
                        $full = wp_get_attachment_image_url( $custom_thb_id, 'full' );
                        if ( $full ) $video['full_url'] = $full;
                    }
                }
            }

            if ( in_array( $video['provider'] ?? '', [ 'image', 'html' ], true ) ) continue;

            $custom_title = isset($video['custom_title']) ? (string) $video['custom_title'] : '';
            $custom_thumb = isset($video['custom_thumb']) ? (string) $video['custom_thumb'] : '';

            if ($custom_thumb !== '' && empty( $video['thumb'] ) ) {
                $video['thumb'] = $this->resolve_custom_thumb($custom_thumb);
            }

            $details = [];
            if ($video['provider'] === 'youtube' && isset($yt_details[$video['id']])) {
                $details = $yt_details[$video['id']];
            } elseif ($video['provider'] === 'vimeo' && isset($vm_details[$video['id']])) {
                $details = $vm_details[$video['id']];
            }

            if ($details) {
                if ($video['thumb'] === '') $video['thumb'] = $details['thumb'] ?? '';
                if ($custom_title === '') $video['label'] = $details['title'] ?? $video['label'];
                if (!empty($details['duration'])) $video['duration'] = $details['duration'];
                if (!empty($details['channel'])) $video['channel'] = $details['channel'];
            }

            if ($custom_title !== '') $video['label'] = $custom_title;
            if ($video['thumb'] === '' && !empty($video['fallback_thumb'])) $video['thumb'] = $video['fallback_thumb'];
        }
        unset($video);
        return $videos;
    }

    private function resolve_custom_thumb($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        if (ctype_digit($value)) {
            $url = wp_get_attachment_image_url((int) $value, 'large');
            return $url ? $url : '';
        }
        if (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0) return $value;
        return '';
    }

    private function fetch_youtube_details($ids, $thumb_size = 'maxres') {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) return [];
        $api_key = $this->get_google_api_key();

        // YouTube direct-URL filenames per size (used for fallback without API key)
        $yt_filenames = [
            'maxres'   => 'maxresdefault.jpg',
            'standard' => 'sddefault.jpg',
            'high'     => 'hqdefault.jpg',
            'medium'   => 'mqdefault.jpg',
        ];
        $fallback_file = $yt_filenames[$thumb_size] ?? 'maxresdefault.jpg';

        if (!$api_key) {
            $out = [];
            foreach ($ids as $id) {
                $out[$id] = ['thumb' => 'https://img.youtube.com/vi/' . $id . '/' . $fallback_file, 'title' => '', 'duration' => '', 'channel' => ''];
            }
            return $out;
        }

        // Check per-video cache first, collect what's missing
        $out = [];
        $uncached = [];
        foreach ($ids as $id) {
            $cached = get_transient('anchor_vs_yt_' . $thumb_size . '_' . $id);
            if (is_array($cached)) {
                $out[$id] = $cached;
            } else {
                $uncached[] = $id;
            }
        }

        // Fetch uncached IDs in batches of 50 (API limit)
        foreach (array_chunk($uncached, 50) as $chunk) {
            $url = add_query_arg([
                'part' => 'snippet,contentDetails',
                'id'   => implode(',', $chunk),
                'key'  => $api_key,
            ], 'https://www.googleapis.com/youtube/v3/videos');

            $res  = wp_remote_get($url, ['timeout' => 12]);
            $data = !is_wp_error($res) ? json_decode(wp_remote_retrieve_body($res), true) : [];

            $priority = ['maxres', 'standard', 'high', 'medium', 'default'];
            $start    = array_search($thumb_size, $priority, true) ?: 0;

            foreach (($data['items'] ?? []) as $item) {
                $sn  = $item['snippet'] ?? [];
                $cd  = $item['contentDetails'] ?? [];
                $vid = $item['id'] ?? '';
                if (!$vid) continue;

                $thumb = '';
                for ($pi = $start; $pi < count($priority); $pi++) {
                    if (!empty($sn['thumbnails'][$priority[$pi]]['url'])) {
                        $thumb = $sn['thumbnails'][$priority[$pi]]['url'];
                        break;
                    }
                }
                $meta = [
                    'title'    => $sn['title'] ?? '',
                    'thumb'    => $thumb ?: 'https://img.youtube.com/vi/' . $vid . '/' . $fallback_file,
                    'duration' => !empty($cd['duration']) ? $this->format_iso_duration($cd['duration']) : '',
                    'channel'  => $sn['channelTitle'] ?? '',
                ];
                $out[$vid] = $meta;
                set_transient('anchor_vs_yt_' . $thumb_size . '_' . $vid, $meta, 12 * HOUR_IN_SECONDS);
            }

            // Any IDs the API didn't return get the direct-URL fallback
            foreach ($chunk as $vid) {
                if (!isset($out[$vid])) {
                    $meta = ['title' => '', 'thumb' => 'https://img.youtube.com/vi/' . $vid . '/' . $fallback_file, 'duration' => '', 'channel' => ''];
                    $out[$vid] = $meta;
                    set_transient('anchor_vs_yt_' . $thumb_size . '_' . $vid, $meta, 12 * HOUR_IN_SECONDS);
                }
            }
        }
        return $out;
    }

    private function fetch_vimeo_details($ids, $thumb_size = 'maxres') {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) return [];

        $vimeo_widths = [
            'maxres'   => '1280',
            'standard' => '640',
            'high'     => '480',
            'medium'   => '320',
            'default'  => '120',
        ];
        $width = $vimeo_widths[$thumb_size] ?? '1280';

        $out = [];
        foreach ($ids as $id) {
            $cache_key = 'anchor_vs_vm_' . $thumb_size . '_' . md5($id);
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $out[$id] = $cached;
                continue;
            }

            $oembed = add_query_arg([
                'url'   => 'https://vimeo.com/' . rawurlencode($id),
                'width' => $width,
                'dnt'   => '1',
            ], 'https://vimeo.com/api/oembed.json');

            $res = wp_remote_get($oembed, ['timeout' => 12]);
            if (is_wp_error($res)) continue;
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (!is_array($data)) continue;

            $thumb_url = $data['thumbnail_url'] ?? '';
            if ($thumb_url) {
                // Vimeo thumbnail URLs end with _WIDTHxHEIGHT — replace with requested width
                $thumb_url = preg_replace('/_\d+x\d+/', '_' . $width, $thumb_url);
            }

            $meta = [
                'title'    => $data['title'] ?? '',
                'thumb'    => $thumb_url,
                'duration' => !empty($data['duration']) ? $this->format_seconds_duration((int) $data['duration']) : '',
                'channel'  => $data['author_name'] ?? '',
            ];
            $out[$id] = $meta;
            set_transient($cache_key, $meta, 24 * HOUR_IN_SECONDS);
        }
        return $out;
    }

    private function format_iso_duration($iso) {
        preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m);
        $h = (int) ($m[1] ?? 0);
        $min = (int) ($m[2] ?? 0);
        $s = (int) ($m[3] ?? 0);
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $min, $s) : sprintf('%d:%02d', $min, $s);
    }

    private function format_seconds_duration($seconds) {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    private function get_google_api_key() {
        if (class_exists('Anchor_Schema_Admin')) {
            $opts = get_option(Anchor_Schema_Admin::OPTION_KEY, []);
            $key = trim($opts['google_api_key'] ?? '');
            if ($key !== '') return $key;
            $legacy = trim($opts['youtube_api_key'] ?? '');
            if ($legacy !== '') return $legacy;
        }
        return '';
    }
}
