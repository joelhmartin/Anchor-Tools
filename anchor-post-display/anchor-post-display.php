<?php
/**
 * Anchor Tools module: Anchor Post Display.
 *
 * Search forms and post grids with AJAX live search and pagination.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/class-apd-renderer.php';
require_once __DIR__ . '/includes/class-apd-display-cpt.php';

class Anchor_Post_Display_Module {

    const OPTION_KEY = 'anchor_post_display_options';
    const PAGE_SLUG  = 'anchor-post-display';
    const VERSION    = '1.2.0';

    private $defaults = [
        'placeholder'  => 'Search...',
        'button_text'  => 'Search',
        'live_search'  => 'yes',
        'min_chars'    => 3,
        'show_icon'    => 'yes',
        'columns'      => 3,
        'layout'       => 'grid',
        'pagination'   => 'none',
        'pagination_window' => 7,
        'orderby'      => 'date',
        'order'        => 'DESC',
        'posts_per_page' => 12,
        'max_posts'    => 0,
        'image_size'   => 'medium',
        'show_date'    => 'no',
        'show_type'    => 'no',
        'no_results'   => 'No results found.',
        'teaser_words' => 26,
        'slider_autoplay' => 'no',
        'slider_speed' => 5000,
        'slider_per_view' => 3,
    ];

    private $did_enqueue = false;

    /** @var Anchor_APD_Display_CPT */
    private $cpt;

    public function __construct() {
        // Editable display CPT (gallery-style builder).
        $this->cpt = new Anchor_APD_Display_CPT();

        // Admin.
        add_filter( 'anchor_settings_tabs',  [ $this, 'register_tab' ], 90 );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );

        // Frontend assets — register early, enqueue lazily in shortcode callbacks.
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );

        // Shortcodes.
        add_shortcode( 'anchor_search',    [ $this, 'shortcode_search' ] );
        add_shortcode( 'anchor_post_grid', [ $this, 'shortcode_grid' ] );

        // Legacy aliases.
        add_shortcode( 'simple_search', [ $this, 'shortcode_search' ] );
        add_shortcode( 'post_grid',     [ $this, 'shortcode_grid' ] );

        // AJAX endpoints.
        add_action( 'wp_ajax_anchor_post_display_search',        [ $this, 'ajax_search' ] );
        add_action( 'wp_ajax_nopriv_anchor_post_display_search', [ $this, 'ajax_search' ] );
        add_action( 'wp_ajax_anchor_post_display_load',          [ $this, 'ajax_load' ] );
        add_action( 'wp_ajax_nopriv_anchor_post_display_load',   [ $this, 'ajax_load' ] );
    }

    /* ================================================================
       Options helper
       ================================================================ */

    private function get_option( $key = null ) {
        $opts = get_option( self::OPTION_KEY, [] );
        $opts = wp_parse_args( $opts, $this->defaults );
        return null === $key ? $opts : ( $opts[ $key ] ?? $this->defaults[ $key ] ?? '' );
    }

    /* ================================================================
       Admin — Settings page
       ================================================================ */

    public function register_tab( $tabs ) {
        $tabs['post_display'] = [
            'label'    => __( 'Post Display', 'anchor-schema' ),
            'callback' => [ $this, 'render_tab_content' ],
        ];
        return $tabs;
    }

    public function register_settings() {
        register_setting( 'anchor_post_display_group', self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_options' ],
            'default'           => [],
        ] );

        /* Search defaults */
        add_settings_section( 'apd_search', __( 'Search Defaults', 'anchor-schema' ), '__return_false', self::PAGE_SLUG );
        $this->add_field( 'placeholder',  'Placeholder text', 'text',   'apd_search' );
        $this->add_field( 'button_text',  'Button text',      'text',   'apd_search' );
        $this->add_field( 'live_search',  'Live search',      'yesno',  'apd_search' );
        $this->add_field( 'min_chars',    'Min characters',   'number', 'apd_search' );
        $this->add_field( 'show_icon',    'Show search icon', 'yesno',  'apd_search' );

        /* Display defaults */
        add_settings_section( 'apd_grid', __( 'Post Display Defaults', 'anchor-schema' ), '__return_false', self::PAGE_SLUG );
        $this->add_field( 'columns',        'Columns',         'number',   'apd_grid' );
        $this->add_field( 'layout',         'Layout',          'select',   'apd_grid', [ 'grid' => 'Grid', 'list' => 'List', 'slider' => 'Slider' ] );
        $this->add_field( 'pagination',     'Pagination',      'select',   'apd_grid', [ 'none' => 'None', 'numbered' => 'Numbered', 'load_more' => 'Load More' ] );
        $this->add_field( 'pagination_window', 'Page button limit', 'number', 'apd_grid' );
        $this->add_field( 'orderby',        'Order by',        'select',   'apd_grid', [ 'date' => 'Date', 'title' => 'Title', 'menu_order' => 'Menu order', 'rand' => 'Random' ] );
        $this->add_field( 'order',          'Order',           'select',   'apd_grid', [ 'DESC' => 'Descending', 'ASC' => 'Ascending' ] );
        $this->add_field( 'posts_per_page', 'Posts per page',  'number',   'apd_grid' );
        $this->add_field( 'max_posts',      'Max posts',       'number',   'apd_grid' );
        $this->add_field( 'image_size',     'Image size',      'text',     'apd_grid' );
        $this->add_field( 'show_date',      'Show date',       'yesno',    'apd_grid' );
        $this->add_field( 'show_type',      'Show post type',  'yesno',    'apd_grid' );
        $this->add_field( 'no_results',     'No results text', 'text',     'apd_grid' );
        $this->add_field( 'teaser_words',   'Teaser word limit', 'number', 'apd_grid' );

        /* Slider defaults — only apply when Layout is set to "Slider" (or layout="slider" on the shortcode) */
        add_settings_section( 'apd_slider', __( 'Slider Settings', 'anchor-schema' ), [ $this, 'render_slider_section_intro' ], self::PAGE_SLUG );
        $this->add_field( 'slider_per_view', 'Slides per view',   'number', 'apd_slider' );
        $this->add_field( 'slider_autoplay', 'Autoplay',          'yesno',  'apd_slider' );
        $this->add_field( 'slider_speed',   'Autoplay speed (ms)', 'number', 'apd_slider' );
    }

    public function render_slider_section_intro() {
        echo '<p class="description">';
        echo wp_kses_post( __( 'These defaults apply only when a post grid uses the <strong>Slider</strong> layout (set Layout to &ldquo;Slider&rdquo; above, or add <code>layout="slider"</code> to the shortcode). Each value can be overridden per shortcode with <code>slider_per_view</code>, <code>slider_autoplay</code>, and <code>slider_speed</code>.', 'anchor-schema' ) );
        echo '</p>';
    }

    private function add_field( $key, $label, $type, $section, $choices = [] ) {
        add_settings_field( $key, $label, [ $this, 'render_field' ], self::PAGE_SLUG, $section, [
            'key'     => $key,
            'type'    => $type,
            'choices' => $choices,
        ] );
    }

    public function render_field( $args ) {
        $key   = $args['key'];
        $type  = $args['type'];
        $val   = $this->get_option( $key );
        $name  = self::OPTION_KEY . '[' . esc_attr( $key ) . ']';

        if ( 'select' === $type ) {
            echo '<select name="' . $name . '">';
            foreach ( $args['choices'] as $v => $lbl ) {
                echo '<option value="' . esc_attr( $v ) . '"' . selected( $val, $v, false ) . '>' . esc_html( $lbl ) . '</option>';
            }
            echo '</select>';
        } elseif ( 'yesno' === $type ) {
            echo '<select name="' . $name . '">';
            echo '<option value="yes"' . selected( $val, 'yes', false ) . '>Yes</option>';
            echo '<option value="no"' . selected( $val, 'no', false ) . '>No</option>';
            echo '</select>';
        } elseif ( 'number' === $type ) {
            echo '<input type="number" name="' . $name . '" value="' . esc_attr( $val ) . '" class="small-text">';
        } else {
            echo '<input type="text" name="' . $name . '" value="' . esc_attr( $val ) . '" class="regular-text">';
        }
    }

    public function sanitize_options( $input ) {
        $clean = [];
        foreach ( $this->defaults as $key => $default ) {
            if ( isset( $input[ $key ] ) ) {
                $clean[ $key ] = is_numeric( $default )
                    ? intval( $input[ $key ] )
                    : sanitize_text_field( $input[ $key ] );
            } else {
                $clean[ $key ] = $default;
            }
        }
        return $clean;
    }

    public function render_tab_content() {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'anchor_post_display_group' );
            do_settings_sections( self::PAGE_SLUG );
            submit_button();
            ?>
        </form>
        <hr>
        <h2><?php esc_html_e( 'Shortcode Reference', 'anchor-schema' ); ?></h2>
        <p><strong><?php esc_html_e( 'Post Displays (new):', 'anchor-schema' ); ?></strong>
        <?php
        printf(
            /* translators: %s: example shortcode */
            esc_html__( 'Build reusable, fully-configurable displays under %1$s in the admin menu, then embed one with %2$s. Each display has its own layout (grid, list, slider, carousel), style, and desktop/tablet/mobile responsive settings.', 'anchor-schema' ),
            '<em>Post Displays</em>',
            '<code>[anchor_post_grid id="123"]</code>'
        );
        ?></p>
        <?php $this->render_shortcode_reference(); ?>
        <?php
    }

    private function render_shortcode_reference() {
        $search_atts = [
            [ 'placeholder', 'Search...', 'Placeholder text' ],
            [ 'button',      'Search',    'Button label' ],
            [ 'class',       'anchor-search', 'Wrapper CSS class' ],
            [ 'post_types',  '(all)',     'Comma-separated post types' ],
            [ 'autofocus',   'no',        'Autofocus the input' ],
            [ 'live',        'yes',       'Enable AJAX live search' ],
            [ 'min_chars',   '3',         'Min chars before AJAX fires' ],
            [ 'target',      '(none)',    'CSS selector of an [anchor_post_grid] to filter' ],
            [ 'show_icon',   'yes',       'Show inline SVG search icon' ],
        ];
        $grid_atts = [
            [ 'post_type',         '(all searchable)', 'Comma-separated post types' ],
            [ 'taxonomy',          'category',          'Include taxonomy slug' ],
            [ 'terms',             '(none)',            'Comma-separated term slugs to include' ],
            [ 'exclude_taxonomy',  'category',          'Exclude taxonomy slug' ],
            [ 'exclude_terms',     '(none)',            'Comma-separated term slugs to exclude' ],
            [ 'image_size',        'medium',            'WordPress image size' ],
            [ 'posts',             '12',                'Posts per page' ],
            [ 'search',            '(none)',            'Force a search term' ],
            [ 'columns',           '3',                 'Grid columns (1-4)' ],
            [ 'layout',            'grid',              'grid, list, or slider' ],
            [ 'pagination',        'none',              'none, numbered, or load_more' ],
            [ 'pagination_window',  '7',                 'Max numbered page buttons before dots' ],
            [ 'max_posts',          '0',                 'Total result cap; 0 means no cap' ],
            [ 'orderby',           'date',              'date, title, menu_order, rand' ],
            [ 'order',             'DESC',              'ASC or DESC' ],
            [ 'show_date',         'no',                'Show date on cards' ],
            [ 'show_type',         'no',                'Show post type badge' ],
            [ 'no_results',        'No results found.', 'No results message' ],
            [ 'id',                '(auto)',            'Render a saved Post Display by numeric ID or slug; inline atts override it. (When not a Post Display, used as the HTML id for search targeting.)' ],
            [ 'teaser_words',      '26',                'Excerpt word limit' ],
            [ 'fields',            '(default)',         'Comma-separated field names &amp; display order (see below)' ],
            [ 'slider_autoplay',    'no',                'Autoplay when layout is slider' ],
            [ 'slider_speed',       '5000',              'Autoplay speed in milliseconds' ],
            [ 'slider_per_view',    '3',                 'Visible cards in slider layout' ],
        ];
        ?>
        <h3><code>[anchor_search]</code></h3>
        <table class="apd-shortcode-ref widefat">
            <thead><tr><th>Attribute</th><th>Default</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ( $search_atts as $row ) : ?>
                <tr><td><code><?php echo esc_html( $row[0] ); ?></code></td><td><?php echo esc_html( $row[1] ); ?></td><td><?php echo esc_html( $row[2] ); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <h3><code>[anchor_post_grid]</code></h3>
        <table class="apd-shortcode-ref widefat">
            <thead><tr><th>Attribute</th><th>Default</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ( $grid_atts as $row ) : ?>
                <tr><td><code><?php echo esc_html( $row[0] ); ?></code></td><td><?php echo esc_html( $row[1] ); ?></td><td><?php echo esc_html( $row[2] ); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <h3>Custom Fields (<code>fields</code> attribute)</h3>
        <p>Use the <code>fields</code> attribute to control <strong>which fields appear</strong> on each card and <strong>in what order</strong>. Provide a comma-separated list of field names.</p>

        <table class="apd-shortcode-ref widefat">
            <thead><tr><th>Built-in Token</th><th>Renders</th></tr></thead>
            <tbody>
                <tr><td><code>image</code></td><td>Featured image</td></tr>
                <tr><td><code>title</code></td><td>Post title</td></tr>
                <tr><td><code>date</code></td><td>Publish date</td></tr>
                <tr><td><code>type</code></td><td>Post type badge</td></tr>
                <tr><td><code>excerpt</code></td><td>Teaser / excerpt text</td></tr>
            </tbody>
        </table>

        <p>Any other name is looked up as an <strong>ACF field</strong> first, then as raw <code>post_meta</code>. If the field is empty or doesn&rsquo;t exist, it is silently skipped.</p>

        <p><strong>ACF field types supported:</strong></p>
        <ul style="list-style:disc;margin-left:20px;">
            <li><strong>Image fields</strong> (array or ID return format) &mdash; rendered as an <code>&lt;img&gt;</code></li>
            <li><strong>Text, textarea, WYSIWYG, URL, email</strong> &mdash; rendered as text content</li>
            <li><strong>Any scalar value</strong> &mdash; rendered as-is</li>
        </ul>

        <p><strong>Examples:</strong></p>
        <p><code>[anchor_post_grid fields="image,title,team_member_title,excerpt"]</code><br>
        Shows the featured image, title, a custom &ldquo;Team Member Title&rdquo; ACF field, then the excerpt.</p>

        <p><code>[anchor_post_grid fields="image,title,date"]</code><br>
        Shows only the image, title, and date &mdash; no excerpt or type badge.</p>

        <p><code>[anchor_post_grid fields="title,team_photo,team_member_title"]</code><br>
        Title first, then a custom ACF image field, then a custom text field.</p>

        <p>When <code>fields</code> is omitted, cards render in the default order: image &rarr; title &rarr; date/type &rarr; excerpt.</p>

        <p style="margin-top:12px;"><strong>CSS targeting:</strong> Each custom field gets a class of <code>anchor-post-grid-field-{name}</code> for styling, e.g. <code>.anchor-post-grid-field-team_member_title</code>.</p>

        <hr>
        <p><strong>Legacy aliases:</strong> <code>[simple_search]</code> and <code>[post_grid]</code> also work.</p>
        <?php
    }

    public function admin_assets( $hook ) {
        if ( 'settings_page_anchor-schema' !== $hook
            || ! isset( $_GET['tab'] ) || $_GET['tab'] !== 'post_display' ) return;
        wp_enqueue_style( 'apd-admin', Anchor_Asset_Loader::url( 'anchor-post-display/assets/admin.css' ), [], self::VERSION );
    }

    /* ================================================================
       Frontend — Assets
       ================================================================ */

    public function register_assets() {
        wp_register_style(
            'anchor-post-display',
            Anchor_Asset_Loader::url( 'anchor-post-display/assets/frontend.css' ),
            [],
            self::VERSION
        );
        wp_register_script(
            'anchor-post-display',
            Anchor_Asset_Loader::url( 'anchor-post-display/assets/frontend.js' ),
            [],
            self::VERSION,
            true
        );
    }

    private function enqueue_assets() {
        if ( $this->did_enqueue ) return;
        $this->did_enqueue = true;
        wp_enqueue_style( 'anchor-post-display' );
        wp_enqueue_script( 'anchor-post-display' );
        wp_localize_script( 'anchor-post-display', 'ANCHOR_POST_DISPLAY', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'anchor_post_display' ),
        ] );
    }

    /* ================================================================
       Shortcode: [anchor_search]
       ================================================================ */

    public function shortcode_search( $atts = [] ) {
        $opts = $this->get_option();
        $atts = shortcode_atts( [
            'placeholder' => $opts['placeholder'],
            'button'      => $opts['button_text'],
            'class'       => 'anchor-search',
            'post_types'  => '',
            'autofocus'   => 'no',
            'live'        => $opts['live_search'],
            'min_chars'   => $opts['min_chars'],
            'target'      => '',
            'show_icon'   => $opts['show_icon'],
        ], $atts, 'anchor_search' );

        $this->enqueue_assets();

        $is_live   = ( 'yes' === strtolower( $atts['live'] ) );
        $has_icon  = ( 'yes' === strtolower( $atts['show_icon'] ) );
        $autofocus = ( 'yes' === strtolower( $atts['autofocus'] ) ) ? ' autofocus' : '';
        $has_btn   = ! empty( trim( $atts['button'] ) );
        $class     = esc_attr( $atts['class'] );
        if ( $has_btn ) $class .= ' has-btn';

        $data = '';
        if ( $is_live ) {
            $data .= ' data-min-chars="' . intval( $atts['min_chars'] ) . '"';
            $data .= ' data-post-types="' . esc_attr( $atts['post_types'] ) . '"';
        }
        if ( ! empty( $atts['target'] ) ) {
            $data .= ' data-target="' . esc_attr( $atts['target'] ) . '"';
        }

        $icon_svg = '';
        if ( $has_icon ) {
            $icon_svg = '<svg class="anchor-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
        }

        ob_start();
        ?>
        <form class="<?php echo $class; ?>" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>"<?php echo $data; ?>>
            <label class="screen-reader-text" for="anchor-search-<?php echo esc_attr( wp_unique_id() ); ?>"><?php esc_html_e( 'Search for:', 'anchor-schema' ); ?></label>
            <?php echo $icon_svg; ?>
            <input class="anchor-search-field" type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"<?php echo $autofocus; ?>>
            <?php if ( ! empty( $atts['post_types'] ) ) :
                $types = array_filter( array_map( 'trim', explode( ',', $atts['post_types'] ) ) );
                foreach ( $types as $t ) : ?>
                    <input type="hidden" name="post_type[]" value="<?php echo esc_attr( $t ); ?>">
                <?php endforeach;
            endif; ?>
            <div class="anchor-search-spinner"></div>
            <?php if ( $has_btn ) : ?>
                <button class="anchor-search-btn" type="submit"><?php echo esc_html( $atts['button'] ); ?></button>
            <?php endif; ?>
            <?php if ( $is_live ) : ?>
                <div class="anchor-search-results"></div>
            <?php endif; ?>
        </form>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
       Shortcode: [anchor_post_grid]
       ================================================================ */

    public function shortcode_grid( $atts = [] ) {
        $opts = $this->get_option();
        $user_atts = is_array( $atts ) ? $atts : [];
        $atts = shortcode_atts( [
            'post_type'        => '',
            'taxonomy'         => 'category',
            'terms'            => '',
            'exclude_taxonomy' => 'category',
            'exclude_terms'    => '',
            'image_size'       => $opts['image_size'],
            'posts'            => $opts['posts_per_page'],
            'search'           => '',
            'columns'          => $opts['columns'],
            'layout'           => $opts['layout'],
            'pagination'       => $opts['pagination'],
            'pagination_window'=> $opts['pagination_window'],
            'orderby'          => $opts['orderby'],
            'order'            => $opts['order'],
            'max_posts'        => $opts['max_posts'],
            'show_date'        => $opts['show_date'],
            'show_type'        => $opts['show_type'],
            'no_results'       => $opts['no_results'],
            'id'               => '',
            'teaser_words'     => $opts['teaser_words'],
            'fields'           => '',
            'slider_autoplay'  => $opts['slider_autoplay'],
            'slider_speed'     => $opts['slider_speed'],
            'slider_per_view'  => $opts['slider_per_view'],
        ], $atts, 'anchor_post_grid' );

        // CPT mode: [anchor_post_grid id="123"] or id="my-slug". Only hijacks `id`
        // when it resolves to a published Post Display; otherwise the legacy
        // inline behavior (id = HTML id for search targeting) still applies.
        $raw_id = isset( $atts['id'] ) ? trim( (string) $atts['id'] ) : '';
        if ( $raw_id !== '' ) {
            $resolved = null;
            if ( ctype_digit( $raw_id ) ) {
                $resolved = Anchor_APD_Display_CPT::build_params_for_post( (int) $raw_id );
            }
            if ( ! $resolved ) {
                $by_slug = get_posts( [
                    'post_type'      => Anchor_APD_Display_CPT::CPT,
                    'name'           => $raw_id,
                    'posts_per_page' => 1,
                    'post_status'    => 'publish',
                ] );
                if ( $by_slug ) {
                    $resolved = Anchor_APD_Display_CPT::build_params_for_post( $by_slug[0]->ID );
                }
            }
            if ( $resolved ) {
                // Inline atts the user actually typed still override the saved display.
                foreach ( [ 'layout', 'columns', 'posts', 'orderby', 'order', 'fields', 'pagination', 'search' ] as $ov ) {
                    if ( isset( $user_atts[ $ov ] ) && $user_atts[ $ov ] !== '' ) {
                        $resolved[ $ov ] = $user_atts[ $ov ];
                    }
                }
                return $this->render_resolved_display( $resolved );
            }
        }

        $this->enqueue_assets();

        $params = $this->normalize_params( $atts );
        $grid_id = ! empty( $params['id'] ) ? $params['id'] : 'apd-' . wp_unique_id();

        // Read search term from URL if not forced.
        if ( empty( $params['search'] ) && isset( $_GET['s'] ) ) {
            $params['search'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        }

        $query = new WP_Query( Anchor_APD_Renderer::build_query_args( $params, 1 ) );

        // Data attributes for JS pagination / search filtering.
        $data_attrs = $this->build_data_attrs( $params );
        $scoped_css = Anchor_APD_Renderer::build_scoped_css( $grid_id, $params );

        ob_start();
        echo $scoped_css;
        echo '<div class="anchor-post-grid-wrap anchor-post-grid-wrap--' . esc_attr( $params['layout'] ) . '" data-layout="' . esc_attr( $params['layout'] ) . '">';
        echo Anchor_APD_Renderer::render_layout_open( $grid_id, $params, $data_attrs );
        echo Anchor_APD_Renderer::render_grid_items( $query, $params );
        echo Anchor_APD_Renderer::render_layout_close( $params );
        echo Anchor_APD_Renderer::render_pagination( $query, $params, 1 );
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    /**
     * Render a display resolved from the CPT (params already built by
     * Anchor_APD_Display_CPT::build_params_for_post). Shares the renderer's
     * markup helpers + scoped responsive CSS with the inline path.
     */
    private function render_resolved_display( $params ) {
        $this->enqueue_assets();
        $grid_id = ! empty( $params['id'] ) ? sanitize_html_class( $params['id'] ) : 'apd-' . wp_unique_id();

        if ( empty( $params['search'] ) && isset( $_GET['s'] ) ) {
            $params['search'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        }

        $query      = new WP_Query( Anchor_APD_Renderer::build_query_args( $params, 1 ) );
        $data_attrs = $this->build_data_attrs( $params );
        $scoped_css = Anchor_APD_Renderer::build_scoped_css( $grid_id, $params );

        ob_start();
        echo $scoped_css;
        echo '<div class="anchor-post-grid-wrap anchor-post-grid-wrap--' . esc_attr( $params['layout'] ) . '" data-layout="' . esc_attr( $params['layout'] ) . '">';
        echo Anchor_APD_Renderer::render_layout_open( $grid_id, $params, $data_attrs );
        echo Anchor_APD_Renderer::render_grid_items( $query, $params );
        echo Anchor_APD_Renderer::render_layout_close( $params );
        echo Anchor_APD_Renderer::render_pagination( $query, $params, 1 );
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    /* ================================================================
       AJAX: Live search
       ================================================================ */

    public function ajax_search() {
        check_ajax_referer( 'anchor_post_display', 'nonce' );

        $term = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
        if ( strlen( $term ) < 2 ) {
            wp_send_json_success( [] );
        }

        $post_types = ! empty( $_POST['post_types'] )
            ? array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['post_types'] ) ) ) ) )
            : $this->get_searchable_types();

        $query = new WP_Query( [
            'post_type'        => $post_types,
            'posts_per_page'   => 8,
            'post_status'      => 'publish',
            's'                => $term,
            'suppress_filters' => true,
        ] );

        $results = [];
        while ( $query->have_posts() ) {
            $query->the_post();
            $pid   = get_the_ID();
            $thumb = get_the_post_thumbnail_url( $pid, 'thumbnail' );
            $pto   = get_post_type_object( get_post_type() );

            $results[] = [
                'title' => esc_html( get_the_title() ),
                'url'   => esc_url( get_permalink() ),
                'thumb' => $thumb ? esc_url( $thumb ) : '',
                'type'  => $pto ? esc_html( $pto->labels->singular_name ) : '',
            ];
        }
        wp_reset_postdata();
        wp_send_json_success( $results );
    }

    /* ================================================================
       AJAX: Load grid page
       ================================================================ */

    public function ajax_load() {
        check_ajax_referer( 'anchor_post_display', 'nonce' );

        $params = $this->normalize_params( $_POST );
        $page   = max( 1, intval( $_POST['page'] ?? 1 ) );

        $query = new WP_Query( Anchor_APD_Renderer::build_query_args( $params, $page ) );

        wp_send_json_success( [
            'html'            => Anchor_APD_Renderer::render_grid_items( $query, $params ),
            'pagination_html' => Anchor_APD_Renderer::render_pagination( $query, $params, $page ),
            'total_pages'     => Anchor_APD_Renderer::get_total_pages( $query, $params ),
            'current_page'    => $page,
        ] );
    }

    /* ================================================================
       Helpers
       ================================================================ */

    private function normalize_params( $input ) {
        $opts = $this->get_option();
        return [
            'post_type'        => sanitize_text_field( $input['post_type'] ?? '' ),
            'taxonomy'         => sanitize_text_field( $input['taxonomy'] ?? 'category' ),
            'terms'            => sanitize_text_field( $input['terms'] ?? '' ),
            'exclude_taxonomy' => sanitize_text_field( $input['exclude_taxonomy'] ?? 'category' ),
            'exclude_terms'    => sanitize_text_field( $input['exclude_terms'] ?? '' ),
            'image_size'       => sanitize_text_field( $input['image_size'] ?? $opts['image_size'] ),
            'posts'            => Anchor_APD_Renderer::normalize_post_count( $input['posts'] ?? $opts['posts_per_page'] ),
            'search'           => sanitize_text_field( $input['search'] ?? '' ),
            'columns'          => max( 1, min( 4, intval( $input['columns'] ?? $opts['columns'] ) ) ),
            'layout'           => in_array( ( $input['layout'] ?? '' ), [ 'grid', 'list', 'slider', 'carousel' ], true ) ? $input['layout'] : $opts['layout'],
            'pagination'       => in_array( ( $input['pagination'] ?? '' ), [ 'none', 'numbered', 'load_more' ], true ) ? $input['pagination'] : $opts['pagination'],
            'pagination_window'=> max( 1, min( 20, intval( $input['pagination_window'] ?? $opts['pagination_window'] ) ) ),
            'orderby'          => sanitize_key( $input['orderby'] ?? $opts['orderby'] ),
            'order'            => in_array( strtoupper( $input['order'] ?? '' ), [ 'ASC', 'DESC' ], true ) ? strtoupper( $input['order'] ) : $opts['order'],
            'max_posts'        => max( 0, intval( $input['max_posts'] ?? $opts['max_posts'] ) ),
            'show_date'        => ( 'yes' === ( $input['show_date'] ?? '' ) ) ? 'yes' : 'no',
            'show_type'        => ( 'yes' === ( $input['show_type'] ?? '' ) ) ? 'yes' : 'no',
            'no_results'       => sanitize_text_field( $input['no_results'] ?? $opts['no_results'] ),
            'id'               => sanitize_html_class( $input['id'] ?? '' ),
            'teaser_words'     => max( 1, intval( $input['teaser_words'] ?? $opts['teaser_words'] ) ),
            'fields'           => sanitize_text_field( $input['fields'] ?? '' ),
            'slider_autoplay'  => ( 'yes' === strtolower( $input['slider_autoplay'] ?? $opts['slider_autoplay'] ) ) ? 'yes' : 'no',
            'slider_speed'     => max( 1000, intval( $input['slider_speed'] ?? $opts['slider_speed'] ) ),
            'slider_per_view'  => max( 1, min( 6, intval( $input['slider_per_view'] ?? $opts['slider_per_view'] ) ) ),
        ];
    }

    private function build_data_attrs( $params ) {
        $attrs = '';
        $keys  = [
            'post_type'  => 'post-type',
            'posts'      => 'posts',
            'max_posts'  => 'max-posts',
            'orderby'    => 'orderby',
            'order'      => 'order',
            'search'     => 'search',
            'show_date'  => 'show-date',
            'show_type'  => 'show-type',
            'no_results' => 'no-results',
            'image_size' => 'image-size',
            'teaser_words' => 'teaser-words',
            'taxonomy'   => 'taxonomy',
            'terms'      => 'terms',
            'exclude_taxonomy' => 'exclude-taxonomy',
            'exclude_terms'    => 'exclude-terms',
            'pagination' => 'pagination',
            'pagination_window' => 'pagination-window',
            'fields'     => 'fields',
            'slider_autoplay' => 'slider-autoplay',
            'slider_speed' => 'slider-speed',
            'slider_per_view' => 'slider-per-view',
            'slider_per_view_tablet' => 'slider-per-view-tablet',
            'slider_per_view_mobile' => 'slider-per-view-mobile',
            'carousel_loop' => 'carousel-loop',
            'carousel_arrows' => 'carousel-arrows',
            'carousel_dots' => 'carousel-dots',
            'carousel_pause_on_hover' => 'carousel-pause-on-hover',
        ];
        foreach ( $keys as $param_key => $data_key ) {
            if ( ! array_key_exists( $param_key, $params ) ) continue;
            $val = $params[ $param_key ];
            if ( is_int( $val ) ) $val = (string) $val;
            $attrs .= ' data-' . $data_key . '="' . esc_attr( $val ) . '"';
        }
        return $attrs;
    }

}
