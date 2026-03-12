<?php
/**
 * Anchor Tools module: Anchor Reviews.
 * CPT-based Google Reviews display with slider, grid, masonry, and list layouts.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Reviews_Display_Module {
    const CPT     = 'anchor_reviews';
    const NONCE   = 'ard_nonce';
    const VERSION = '1.0.0';

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes',        [ $this, 'add_metaboxes' ] );
        add_action( 'save_post',             [ $this, 'save_meta' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue' ] );
        add_action( 'wp_enqueue_scripts',    [ $this, 'frontend_enqueue' ] );

        add_shortcode( 'anchor_reviews',        [ $this, 'shortcode_handler' ] );
        add_shortcode( 'anchor_reviews_google',  [ $this, 'shortcode_handler' ] );
        add_shortcode( 'anchor_reviews_display', [ $this, 'shortcode_handler' ] );

        add_filter( 'manage_' . self::CPT . '_posts_columns',      [ $this, 'admin_columns' ] );
        add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'admin_column_content' ], 10, 2 );
    }

    /* ══════════════════════════════════════════════════════════
       CPT
       ══════════════════════════════════════════════════════════ */

    public function register_cpt() {
        register_post_type( self::CPT, [
            'labels' => [
                'name'               => __( 'Review Displays', 'anchor-schema' ),
                'singular_name'      => __( 'Review Display', 'anchor-schema' ),
                'add_new'            => __( 'Add New Display', 'anchor-schema' ),
                'add_new_item'       => __( 'Add New Review Display', 'anchor-schema' ),
                'edit_item'          => __( 'Edit Review Display', 'anchor-schema' ),
                'new_item'           => __( 'New Review Display', 'anchor-schema' ),
                'view_item'          => __( 'View Review Display', 'anchor-schema' ),
                'search_items'       => __( 'Search Review Displays', 'anchor-schema' ),
                'not_found'          => __( 'No displays found.', 'anchor-schema' ),
                'not_found_in_trash' => __( 'No displays found in Trash.', 'anchor-schema' ),
                'menu_name'          => __( 'Review Displays', 'anchor-schema' ),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => apply_filters( 'anchor_reviews_display_parent_menu', true ),
            'supports'     => [ 'title' ],
            'menu_icon'    => 'dashicons-star-filled',
            'has_archive'  => false,
            'rewrite'      => false,
        ] );
    }

    /* ══════════════════════════════════════════════════════════
       Setting Definitions
       ══════════════════════════════════════════════════════════ */

    private function get_setting_defs() {
        return [
            'ard_layout' => [
                'type' => 'select', 'label' => 'Layout', 'default' => 'grid',
                'options' => [
                    'grid'    => 'Grid',
                    'slider'  => 'Slider',
                    'masonry' => 'Masonry',
                    'list'    => 'List',
                ],
            ],
            'ard_theme' => [
                'type' => 'select', 'label' => 'Theme', 'default' => 'light',
                'options' => [ 'light' => 'Light', 'dark' => 'Dark' ],
            ],
            'ard_columns_desktop' => [
                'type' => 'number', 'label' => 'Desktop Columns', 'default' => 3,
                'min' => 1, 'max' => 6, 'show_for_layout' => 'grid,masonry',
            ],
            'ard_columns_tablet' => [
                'type' => 'number', 'label' => 'Tablet Columns', 'default' => 2,
                'min' => 1, 'max' => 4, 'show_for_layout' => 'grid,masonry',
            ],
            'ard_columns_mobile' => [
                'type' => 'number', 'label' => 'Mobile Columns', 'default' => 1,
                'min' => 1, 'max' => 2, 'show_for_layout' => 'grid,masonry',
            ],
            'ard_gap' => [
                'type' => 'number', 'label' => 'Gap (px)', 'default' => 16,
                'min' => 0, 'max' => 60, 'step' => 4,
            ],
            'ard_min_rating' => [
                'type' => 'select', 'label' => 'Star Filter', 'default' => '1',
                'options' => [
                    '1' => 'All Reviews',
                    '3' => '3, 4 & 5 Star',
                    '4' => '4 & 5 Star',
                    '5' => '5 Star Only',
                ],
            ],
            'ard_max_reviews' => [
                'type' => 'number', 'label' => 'Max Reviews', 'default' => '',
                'min' => 1, 'max' => 50,
            ],
            'ard_show_header' => [
                'type' => 'checkbox', 'label' => 'Show Business Header', 'default' => 1,
            ],
            'ard_show_date' => [
                'type' => 'checkbox', 'label' => 'Show Review Date', 'default' => 1,
            ],
            'ard_show_avatar' => [
                'type' => 'checkbox', 'label' => 'Show Reviewer Avatar', 'default' => 1,
            ],
            'ard_excerpt_words' => [
                'type' => 'number', 'label' => 'Excerpt Words (0 = full)', 'default' => 0,
                'min' => 0, 'max' => 200,
            ],
            'ard_slider_autoplay' => [
                'type' => 'checkbox', 'label' => 'Autoplay Slider', 'default' => 1,
                'show_for_layout' => 'slider',
            ],
            'ard_slider_speed' => [
                'type' => 'number', 'label' => 'Autoplay Speed (ms)', 'default' => 5000,
                'min' => 1000, 'max' => 15000, 'step' => 500,
                'show_for_layout' => 'slider',
            ],
            'ard_slider_per_view' => [
                'type' => 'number', 'label' => 'Slides per View', 'default' => 1,
                'min' => 1, 'max' => 4,
                'show_for_layout' => 'slider',
            ],
            'ard_show_cta' => [
                'type' => 'checkbox', 'label' => 'Show "Write a Review" CTA', 'default' => 0,
            ],
            'ard_cta_text' => [
                'type' => 'text', 'label' => 'CTA Button Text', 'default' => 'Write us a review',
            ],
            'ard_custom_css' => [
                'type' => 'textarea', 'label' => 'Custom CSS', 'default' => '',
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
       Metaboxes
       ══════════════════════════════════════════════════════════ */

    public function add_metaboxes() {
        add_meta_box( 'ard_source',   __( 'Review Source', 'anchor-schema' ),    [ $this, 'metabox_source' ],   self::CPT, 'normal', 'high' );
        add_meta_box( 'ard_settings', __( 'Display Settings', 'anchor-schema' ), [ $this, 'metabox_settings' ], self::CPT, 'side',   'default' );
    }

    public function metabox_source( $post ) {
        wp_nonce_field( 'ard_save', self::NONCE );

        $place_id = get_post_meta( $post->ID, 'ard_place_id', true );

        // Try to pull the global Place ID as default.
        if ( ! $place_id && class_exists( 'Anchor_Schema_Admin' ) ) {
            $global = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
            $place_id = trim( $global['reviews_google_place_id'] ?? '' );
        }

        echo '<p><label><strong>' . esc_html__( 'Google Place ID', 'anchor-schema' ) . '</strong></label><br/>';
        echo '<input type="text" name="ard_place_id" value="' . esc_attr( $place_id ) . '" class="widefat" />';
        echo '<span class="description">' . esc_html__( 'Leave blank to use the global Place ID from Settings > Anchor Tools > Reviews.', 'anchor-schema' ) . '</span></p>';

        // Show cached data status.
        $effective_place_id = $this->resolve_place_id( $post->ID );
        if ( $effective_place_id && class_exists( 'Anchor_Reviews_Manager' ) ) {
            $cache = Anchor_Reviews_Manager::get_cache( 'google', $effective_place_id );
            if ( $cache && is_array( $cache ) ) {
                $count = count( $cache['reviews'] ?? [] );
                $name  = $cache['business_name'] ?? '';
                echo '<div class="ard-cache-status" style="margin-top:12px;padding:10px;background:#f0f6fc;border-left:4px solid #2271b1;">';
                printf( '<strong>%s</strong> — %.1f stars, %d total reviews, %d cached reviews.',
                    esc_html( $name ),
                    (float) ( $cache['rating'] ?? 0 ),
                    (int) ( $cache['rating_count'] ?? 0 ),
                    $count
                );
                $last = Anchor_Reviews_Manager::get_last_fetch( 'google', $effective_place_id );
                if ( $last ) {
                    echo '<br/><small>' . esc_html__( 'Last fetched: ', 'anchor-schema' ) . esc_html( $last ) . '</small>';
                }
                echo '</div>';
            } else {
                echo '<div class="ard-cache-status" style="margin-top:12px;padding:10px;background:#fcf0f0;border-left:4px solid #d63638;">';
                echo esc_html__( 'No cached reviews found. Go to Settings > Anchor Tools > Reviews and refresh.', 'anchor-schema' );
                echo '</div>';
            }
        }
    }

    public function metabox_settings( $post ) {
        $defs = $this->get_setting_defs();
        foreach ( $defs as $key => $def ) {
            $this->render_meta_field_from_def( $post->ID, $key, $def );
        }
    }

    private function render_meta_field_from_def( $post_id, $key, $def ) {
        $val = get_post_meta( $post_id, $key, true );
        if ( $val === '' && isset( $def['default'] ) ) $val = $def['default'];

        $attrs = '';
        if ( ! empty( $def['show_for_layout'] ) ) $attrs .= ' data-show-for-layout="' . esc_attr( $def['show_for_layout'] ) . '"';

        echo '<div class="ard-setting-row"' . $attrs . '>';
        echo '<label>' . esc_html( $def['label'] ) . '</label> ';

        switch ( $def['type'] ) {
            case 'select':
                echo '<select name="' . esc_attr( $key ) . '">';
                foreach ( $def['options'] as $k => $l ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $val, $k, false ), esc_html( $l ) );
                }
                echo '</select>';
                break;
            case 'number':
                $extra = '';
                if ( isset( $def['min'] ) )  $extra .= ' min="' . intval( $def['min'] ) . '"';
                if ( isset( $def['max'] ) )  $extra .= ' max="' . intval( $def['max'] ) . '"';
                if ( isset( $def['step'] ) ) $extra .= ' step="' . intval( $def['step'] ) . '"';
                echo '<input type="number" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '" class="small-text"' . $extra . ' />';
                break;
            case 'checkbox':
                echo '<input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( $val, 1, false ) . ' />';
                break;
            case 'textarea':
                echo '<textarea name="' . esc_attr( $key ) . '" rows="4" class="widefat">' . esc_textarea( $val ) . '</textarea>';
                break;
            default:
                echo '<input type="text" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '" class="regular-text" />';
        }

        if ( ! empty( $def['description'] ) ) {
            echo '<p class="description">' . esc_html( $def['description'] ) . '</p>';
        }
        echo '</div>';
    }

    /* ══════════════════════════════════════════════════════════
       Save
       ══════════════════════════════════════════════════════════ */

    public function save_meta( $post_id, $post ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( $_POST[ self::NONCE ], 'ard_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( $post->post_type !== self::CPT ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Place ID.
        if ( isset( $_POST['ard_place_id'] ) ) {
            update_post_meta( $post_id, 'ard_place_id', sanitize_text_field( $_POST['ard_place_id'] ) );
        }

        // Setting defs.
        foreach ( $this->get_setting_defs() as $key => $def ) {
            switch ( $def['type'] ) {
                case 'select':
                    $val = sanitize_text_field( $_POST[ $key ] ?? '' );
                    if ( isset( $def['options'][ $val ] ) ) {
                        update_post_meta( $post_id, $key, $val );
                    }
                    break;
                case 'number':
                    $val = $_POST[ $key ] ?? '';
                    update_post_meta( $post_id, $key, $val !== '' ? intval( $val ) : '' );
                    break;
                case 'checkbox':
                    update_post_meta( $post_id, $key, ! empty( $_POST[ $key ] ) ? 1 : 0 );
                    break;
                case 'textarea':
                    if ( isset( $_POST[ $key ] ) ) {
                        update_post_meta( $post_id, $key, wp_strip_all_tags( $_POST[ $key ] ) );
                    }
                    break;
                default:
                    if ( isset( $_POST[ $key ] ) ) {
                        update_post_meta( $post_id, $key, sanitize_text_field( $_POST[ $key ] ) );
                    }
            }
        }
    }

    /* ══════════════════════════════════════════════════════════
       Admin Columns
       ══════════════════════════════════════════════════════════ */

    public function admin_columns( $columns ) {
        $new = [];
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'title' ) {
                $new['ard_shortcode'] = __( 'Shortcode', 'anchor-schema' );
                $new['ard_layout']    = __( 'Layout', 'anchor-schema' );
            }
        }
        unset( $new['date'] );
        return $new;
    }

    public function admin_column_content( $column, $post_id ) {
        if ( $column === 'ard_shortcode' ) {
            echo '<code>[anchor_reviews id="' . intval( $post_id ) . '"]</code>';
        }
        if ( $column === 'ard_layout' ) {
            echo esc_html( ucfirst( get_post_meta( $post_id, 'ard_layout', true ) ?: 'grid' ) );
        }
    }

    /* ══════════════════════════════════════════════════════════
       Assets
       ══════════════════════════════════════════════════════════ */

    private function get_assets_url() {
        return ANCHOR_TOOLS_PLUGIN_URL . 'anchor-reviews/assets/';
    }

    public function admin_enqueue( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== self::CPT ) return;

        wp_enqueue_style( 'ard-admin', $this->get_assets_url() . 'admin.css', [], self::VERSION );
        wp_enqueue_script( 'ard-admin', $this->get_assets_url() . 'admin.js', [ 'jquery' ], self::VERSION, true );
    }

    public function frontend_enqueue() {
        wp_register_style( 'ard-frontend', $this->get_assets_url() . 'frontend.css', [], self::VERSION );
        wp_register_script( 'ard-frontend', $this->get_assets_url() . 'frontend.js', [], self::VERSION, true );
    }

    /* ══════════════════════════════════════════════════════════
       Shortcode
       ══════════════════════════════════════════════════════════ */

    public function shortcode_handler( $atts = [] ) {
        $atts = shortcode_atts( [
            'id'   => '',
            'slug' => '',
        ], $atts );

        $post_id = 0;

        if ( ! empty( $atts['id'] ) ) {
            $post_id = intval( $atts['id'] );
        } elseif ( ! empty( $atts['slug'] ) ) {
            $found = get_posts( [
                'post_type'   => self::CPT,
                'name'        => sanitize_title( $atts['slug'] ),
                'numberposts' => 1,
                'fields'      => 'ids',
            ] );
            $post_id = $found ? $found[0] : 0;
        }

        if ( ! $post_id || get_post_type( $post_id ) !== self::CPT ) {
            return '<p class="ard-note">Review display not found.</p>';
        }

        return $this->render( $post_id );
    }

    /* ══════════════════════════════════════════════════════════
       Render
       ══════════════════════════════════════════════════════════ */

    private function resolve_place_id( $post_id ) {
        $place_id = trim( get_post_meta( $post_id, 'ard_place_id', true ) );
        if ( $place_id ) return $place_id;

        if ( class_exists( 'Anchor_Schema_Admin' ) ) {
            $global = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
            return trim( $global['reviews_google_place_id'] ?? '' );
        }
        return '';
    }

    private function get_settings( $post_id ) {
        $defs = $this->get_setting_defs();
        $settings = [];
        foreach ( $defs as $key => $def ) {
            $val = get_post_meta( $post_id, $key, true );
            $settings[ $key ] = ( $val !== '' ) ? $val : ( $def['default'] ?? '' );
        }
        return $settings;
    }

    private function render( $post_id ) {
        $place_id = $this->resolve_place_id( $post_id );
        if ( ! $place_id ) {
            return '<p class="ard-note">No Google Place ID configured.</p>';
        }

        if ( ! class_exists( 'Anchor_Reviews_Manager' ) ) {
            return '<p class="ard-note">Reviews manager not available.</p>';
        }

        $cache = Anchor_Reviews_Manager::get_cache( 'google', $place_id );
        if ( empty( $cache ) || ! is_array( $cache ) ) {
            return '<div class="ard-empty">Reviews unavailable. Please refresh from settings.</div>';
        }

        wp_enqueue_style( 'ard-frontend' );
        wp_enqueue_script( 'ard-frontend' );

        $s = $this->get_settings( $post_id );
        $layout     = sanitize_key( $s['ard_layout'] );
        $theme      = sanitize_key( $s['ard_theme'] );
        $min_rating = max( 1, intval( $s['ard_min_rating'] ) );
        $max        = intval( $s['ard_max_reviews'] );
        $show_header = intval( $s['ard_show_header'] );
        $show_date   = intval( $s['ard_show_date'] );
        $show_avatar = intval( $s['ard_show_avatar'] );
        $excerpt     = intval( $s['ard_excerpt_words'] );
        $cols_d      = max( 1, intval( $s['ard_columns_desktop'] ) );
        $cols_t      = max( 1, intval( $s['ard_columns_tablet'] ) );
        $cols_m      = max( 1, intval( $s['ard_columns_mobile'] ) );
        $gap         = intval( $s['ard_gap'] );
        $autoplay    = intval( $s['ard_slider_autoplay'] );
        $speed       = max( 1000, intval( $s['ard_slider_speed'] ) );
        $per_view    = max( 1, intval( $s['ard_slider_per_view'] ) );
        $custom_css  = trim( $s['ard_custom_css'] );

        // Filter reviews.
        $reviews = $cache['reviews'] ?? [];
        $reviews = array_filter( $reviews, function( $r ) use ( $min_rating ) {
            return ( (int) ( $r['rating'] ?? 0 ) ) >= $min_rating;
        } );
        if ( $max > 0 ) {
            $reviews = array_slice( $reviews, 0, $max );
        }

        if ( empty( $reviews ) ) {
            return '<div class="ard-empty">No reviews match the current filters.</div>';
        }

        $uid = 'ard-' . $post_id;

        // Build inline CSS vars for columns/gap.
        $style_vars = sprintf(
            '--ard-cols-d:%d;--ard-cols-t:%d;--ard-cols-m:%d;--ard-gap:%dpx;',
            $cols_d, $cols_t, $cols_m, $gap
        );

        // Data attrs for slider JS.
        $data_attrs = '';
        if ( $layout === 'slider' ) {
            $data_attrs = sprintf(
                ' data-autoplay="%d" data-speed="%d" data-per-view="%d"',
                $autoplay, $speed, $per_view
            );
        }

        ob_start();

        if ( $custom_css ) {
            echo '<style>' . wp_strip_all_tags( $custom_css ) . '</style>';
        }

        echo '<div id="' . esc_attr( $uid ) . '" class="ard-wrap ard-layout-' . esc_attr( $layout ) . ' ard-theme-' . esc_attr( $theme ) . '" style="' . esc_attr( $style_vars ) . '"' . $data_attrs . '>';

        // Business header.
        if ( $show_header ) {
            $this->render_header( $cache );
        }

        // Reviews container.
        if ( $layout === 'slider' ) {
            echo '<div class="ard-slider-viewport">';
            echo '<div class="ard-slider-track">';
        } else {
            echo '<div class="ard-reviews">';
        }

        foreach ( $reviews as $review ) {
            $this->render_card( $review, $show_date, $show_avatar, $excerpt );
        }

        if ( $layout === 'slider' ) {
            echo '</div>'; // .ard-slider-track
            echo '</div>'; // .ard-slider-viewport
            echo '<div class="ard-slider-nav">';
            echo '<button type="button" class="ard-slider-prev" aria-label="Previous">&lsaquo;</button>';
            echo '<button type="button" class="ard-slider-next" aria-label="Next">&rsaquo;</button>';
            echo '</div>';
        } else {
            echo '</div>'; // .ard-reviews
        }

        // CTA block.
        if ( intval( $s['ard_show_cta'] ) ) {
            $this->render_cta( $cache, $s['ard_cta_text'] );
        }

        echo '</div>'; // .ard-wrap

        return ob_get_clean();
    }

    private function render_header( $cache ) {
        $name   = $cache['business_name'] ?? '';
        $rating = (float) ( $cache['rating'] ?? 0 );
        $count  = (int) ( $cache['rating_count'] ?? 0 );
        $url    = $cache['business_url'] ?? '';

        echo '<div class="ard-header">';
        echo '<div class="ard-header-info">';
        echo '<div class="ard-header-icon"><svg viewBox="0 0 24 24" width="28" height="28"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg></div>';
        echo '<div class="ard-header-text">';
        if ( $name ) {
            echo '<div class="ard-header-name">' . esc_html( $name ) . '</div>';
        }
        echo '<div class="ard-header-rating">';
        echo $this->render_stars( $rating );
        echo '<span class="ard-header-score">' . esc_html( number_format( $rating, 1 ) ) . '</span>';
        echo '<span class="ard-header-count">(' . esc_html( number_format( $count ) ) . ' reviews)</span>';
        echo '</div>';
        echo '</div>'; // .ard-header-text
        echo '</div>'; // .ard-header-info
        if ( $url ) {
            echo '<a class="ard-header-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">View on Google</a>';
        }
        echo '</div>'; // .ard-header
    }

    private function render_cta( $cache, $button_text ) {
        $name   = $cache['business_name'] ?? '';
        $rating = (float) ( $cache['rating'] ?? 0 );
        $place_id = $cache['place_id'] ?? '';
        $btn    = trim( $button_text ) ?: 'Write us a review';

        // Google review URL uses place_id.
        $review_url = $place_id
            ? 'https://search.google.com/local/writereview?placeid=' . urlencode( $place_id )
            : ( $cache['business_url'] ?? '' );

        if ( ! $review_url ) return;

        $label = $this->rating_label( $rating );

        echo '<div class="ard-cta">';
        echo '<div class="ard-cta-inner">';
        echo '<div class="ard-cta-info">';
        if ( $name ) {
            echo '<div class="ard-cta-name">' . esc_html( $name ) . '</div>';
        }
        echo '<div class="ard-cta-rating">';
        echo '<span class="ard-cta-label">' . esc_html( $label ) . '</span>';
        echo $this->render_stars( $rating );
        echo '<span class="ard-cta-score">' . esc_html( number_format( $rating, 1 ) ) . '</span>';
        echo '</div>';
        echo '</div>'; // .ard-cta-info
        echo '<a class="ard-cta-button" href="' . esc_url( $review_url ) . '" target="_blank" rel="noopener">' . esc_html( $btn ) . '</a>';
        echo '</div>'; // .ard-cta-inner
        echo '</div>'; // .ard-cta
    }

    private function rating_label( $rating ) {
        if ( $rating >= 4.5 ) return 'Excellent';
        if ( $rating >= 4.0 ) return 'Very Good';
        if ( $rating >= 3.5 ) return 'Good';
        if ( $rating >= 2.5 ) return 'Average';
        if ( $rating >= 1.5 ) return 'Below Average';
        return 'Poor';
    }

    private function render_card( $review, $show_date, $show_avatar, $excerpt_words ) {
        $author = $review['author'] ?? '';
        $rating = (int) ( $review['rating'] ?? 0 );
        $text   = $review['text'] ?? '';
        $date   = $review['date'] ?? '';
        $avatar = $review['avatar'] ?? '';
        $author_url = $review['author_url'] ?? '';

        echo '<div class="ard-card">';

        // Author row.
        echo '<div class="ard-card-author">';
        if ( $show_avatar ) {
            if ( $avatar ) {
                echo '<img class="ard-avatar" src="' . esc_url( $avatar ) . '" alt="' . esc_attr( $author ) . '" width="40" height="40" loading="lazy" referrerpolicy="no-referrer" />';
            } else {
                $initial = mb_strtoupper( mb_substr( $author, 0, 1 ) );
                echo '<span class="ard-avatar ard-avatar-initial">' . esc_html( $initial ) . '</span>';
            }
        }
        echo '<div class="ard-card-author-info">';
        if ( $author_url ) {
            echo '<a class="ard-card-name" href="' . esc_url( $author_url ) . '" target="_blank" rel="noopener">' . esc_html( $author ) . '</a>';
        } else {
            echo '<span class="ard-card-name">' . esc_html( $author ) . '</span>';
        }
        if ( $show_date && $date ) {
            $ts = strtotime( $date );
            $formatted = $ts ? date_i18n( get_option( 'date_format' ), $ts ) : $date;
            echo '<span class="ard-card-date">' . esc_html( $formatted ) . '</span>';
        }
        echo '</div>'; // .ard-card-author-info
        echo '</div>'; // .ard-card-author

        // Stars.
        echo '<div class="ard-card-stars">' . $this->render_stars( $rating ) . '</div>';

        // Review text.
        if ( $text ) {
            $display_text = $text;
            if ( $excerpt_words > 0 ) {
                $words = explode( ' ', $text );
                if ( count( $words ) > $excerpt_words ) {
                    $display_text = implode( ' ', array_slice( $words, 0, $excerpt_words ) ) . "\u{2026}";
                }
            }
            echo '<div class="ard-card-text">' . esc_html( $display_text ) . '</div>';
        }

        // Google icon.
        echo '<div class="ard-card-source"><svg viewBox="0 0 24 24" width="16" height="16"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg></div>';

        echo '</div>'; // .ard-card
    }

    /**
     * Render Font Awesome star icons for a rating (supports partial stars).
     */
    private function render_stars( $rating ) {
        $rating = max( 0, min( 5, (float) $rating ) );
        $full   = (int) floor( $rating );
        $frac   = $rating - $full;
        $empty  = 5 - $full - ( $frac > 0 ? 1 : 0 );

        $html = '<span class="ard-stars" aria-label="' . esc_attr( $rating . ' out of 5 stars' ) . '">';

        // Full stars.
        for ( $i = 0; $i < $full; $i++ ) {
            $html .= '<svg class="ard-star ard-star-full" viewBox="0 0 576 512" width="18" height="18"><path fill="currentColor" d="M316.9 18C311.6 7 300.4 0 288.1 0s-23.4 7-28.8 18L195 150.3 51.4 171.5c-12 1.8-22 10.2-25.7 21.7s-.7 24.2 7.9 32.7L137.8 329l-24.6 145.7c-2 12 3 24.2 12.9 31.3s23 8 33.8 2.3L288.1 439.8l128.2 68.5c10.8 5.7 23.9 4.9 33.8-2.3s14.9-19.3 12.9-31.3L438.5 329l104.2-103.1c8.6-8.5 11.7-21.2 7.9-32.7s-13.7-19.9-25.7-21.7L382.9 150.3 316.9 18z"/></svg>';
        }

        // Half / partial star.
        if ( $frac > 0 ) {
            $pct = round( $frac * 100 );
            $uid = 'ard-grad-' . str_replace( '.', '', (string) $rating ) . '-' . wp_unique_id();
            $html .= '<svg class="ard-star ard-star-partial" viewBox="0 0 576 512" width="18" height="18">';
            $html .= '<defs><linearGradient id="' . esc_attr( $uid ) . '"><stop offset="' . $pct . '%" stop-color="currentColor"/><stop offset="' . $pct . '%" stop-color="#d0d0d0"/></linearGradient></defs>';
            $html .= '<path fill="url(#' . esc_attr( $uid ) . ')" d="M316.9 18C311.6 7 300.4 0 288.1 0s-23.4 7-28.8 18L195 150.3 51.4 171.5c-12 1.8-22 10.2-25.7 21.7s-.7 24.2 7.9 32.7L137.8 329l-24.6 145.7c-2 12 3 24.2 12.9 31.3s23 8 33.8 2.3L288.1 439.8l128.2 68.5c10.8 5.7 23.9 4.9 33.8-2.3s14.9-19.3 12.9-31.3L438.5 329l104.2-103.1c8.6-8.5 11.7-21.2 7.9-32.7s-13.7-19.9-25.7-21.7L382.9 150.3 316.9 18z"/></svg>';
        }

        // Empty stars.
        for ( $i = 0; $i < $empty; $i++ ) {
            $html .= '<svg class="ard-star ard-star-empty" viewBox="0 0 576 512" width="18" height="18"><path fill="#d0d0d0" d="M316.9 18C311.6 7 300.4 0 288.1 0s-23.4 7-28.8 18L195 150.3 51.4 171.5c-12 1.8-22 10.2-25.7 21.7s-.7 24.2 7.9 32.7L137.8 329l-24.6 145.7c-2 12 3 24.2 12.9 31.3s23 8 33.8 2.3L288.1 439.8l128.2 68.5c10.8 5.7 23.9 4.9 33.8-2.3s14.9-19.3 12.9-31.3L438.5 329l104.2-103.1c8.6-8.5 11.7-21.2 7.9-32.7s-13.7-19.9-25.7-21.7L382.9 150.3 316.9 18z"/></svg>';
        }

        $html .= '</span>';
        return $html;
    }
}
