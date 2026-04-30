<?php
/**
 * Anchor Tools module: Anchor Accessibility Widget.
 * Adds a floating accessibility toolbar to the frontend with font sizing,
 * contrast, grayscale, link underlining, readable font, and more.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Accessibility_Module {

    const OPTION_KEY = 'anchor_accessibility_options';
    const PAGE_SLUG  = 'anchor-accessibility';

    /** Default option values. */
    private function defaults() {
        return [
            'widget_color'       => '#0073aa',
            'accent_color'       => '#005177',
            'position'           => 'bottom-left', // bottom-left | bottom-right
            'icon_size'          => '56',           // px
            'offset_x'           => '20',           // px from side edge
            'offset_y'           => '20',           // px from bottom edge

            // Tablet overrides (empty = inherit desktop)
            'tablet_breakpoint'  => '1024',
            'tablet_icon_size'   => '',
            'tablet_offset_x'    => '',
            'tablet_offset_y'    => '',

            // Mobile overrides (empty = inherit tablet/desktop)
            'mobile_breakpoint'  => '768',
            'mobile_icon_size'   => '',
            'mobile_offset_x'    => '',
            'mobile_offset_y'    => '',

            'enable_profiles'       => '1',
            'enable_screen_reader'  => '1',
            'enable_font_size'      => '1',
            'enable_contrast'       => '1',
            'enable_smart_contrast' => '1',
            'enable_grayscale'      => '1', // Used for saturation/desaturation controls.
            'enable_underline'      => '1', // Used for link highlighting controls.
            'enable_readable'       => '1',
            'enable_spacing'        => '1',
            'enable_hide_images'    => '1',
            'enable_big_cursor'     => '1',
            'enable_pause_anim'     => '1',
            'enable_highlight'      => '1',
            'enable_reading_guide'  => '1',
            'enable_reading_mask'   => '1',
            'enable_tooltips'       => '1',
            'enable_page_structure' => '1',
            'enable_line_height'    => '1',
            'enable_text_align'     => '1',
            'enable_dictionary'     => '1',
            'enable_move_widget'    => '1',
            'statement_url'      => '',
        ];
    }

    /** Merged options. */
    public function get_options() {
        return array_merge( $this->defaults(), (array) get_option( self::OPTION_KEY, [] ) );
    }

    /* ------------------------------------------------------------------ */
    /*  Bootstrap                                                         */
    /* ------------------------------------------------------------------ */

    public function __construct() {
        add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 95 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
        add_action( 'wp_footer', [ $this, 'render_widget' ] );

        // Purge page caches when settings change so inline styles update immediately.
        add_action( 'update_option_' . self::OPTION_KEY, [ $this, 'purge_page_caches' ] );
    }

    /** Flush known page caches so the new inline styles are served. */
    public function purge_page_caches() {
        // WordPress object cache.
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
        // Kinsta (MU-plugin).
        if ( class_exists( 'Developer_Tools_Settings' ) && function_exists( 'developer_tools_clear_cache' ) ) {
            developer_tools_clear_cache();
        }
        if ( class_exists( '\Jeedo\KinstaMUPlugins\Cache\CachePurge' ) ) {
            wp_remote_get( home_url( '/?kinsta-clear-cache-all' ), [ 'blocking' => false ] );
        }
        // WP Rocket.
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
        // WP Super Cache.
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
        }
        // W3 Total Cache.
        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
        }
        // LiteSpeed Cache.
        if ( class_exists( 'LiteSpeed\Purge' ) ) {
            do_action( 'litespeed_purge_all' );
        }
        // Generic: trigger the common purge action used by many cache plugins.
        do_action( 'anchor_cache_purged' );
    }

    /* ------------------------------------------------------------------ */
    /*  Admin: settings tab                                               */
    /* ------------------------------------------------------------------ */

    public function register_tab( $tabs ) {
        $tabs['accessibility'] = [
            'label'    => __( 'Accessibility', 'anchor-schema' ),
            'callback' => [ $this, 'render_tab_content' ],
        ];
        return $tabs;
    }

    public function register_settings() {
        register_setting( 'anchor_accessibility_group', self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_options' ],
            'default'           => [],
        ] );

        /* --- Appearance section --- */
        add_settings_section(
            'aa_appearance',
            __( 'Widget Appearance', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Customize the look and position of the floating accessibility widget.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'widget_color', __( 'Widget Color', 'anchor-schema' ), [ $this, 'field_color' ], self::PAGE_SLUG, 'aa_appearance', [ 'key' => 'widget_color' ] );
        add_settings_field( 'accent_color', __( 'Accent Color', 'anchor-schema' ), [ $this, 'field_color' ], self::PAGE_SLUG, 'aa_appearance', [ 'key' => 'accent_color' ] );
        add_settings_field( 'position', __( 'Position', 'anchor-schema' ), [ $this, 'field_position' ], self::PAGE_SLUG, 'aa_appearance', [ 'key' => 'position' ] );
        add_settings_field( 'icon_size', __( 'Button Size (px)', 'anchor-schema' ), [ $this, 'field_number' ], self::PAGE_SLUG, 'aa_appearance', [ 'key' => 'icon_size', 'min' => 40, 'max' => 80 ] );
        add_settings_field( 'offset_x', __( 'Horizontal Offset (px)', 'anchor-schema' ), [ $this, 'field_number' ], self::PAGE_SLUG, 'aa_appearance', [ 'key' => 'offset_x', 'min' => 0, 'max' => 500, 'suffix' => 'distance from side edge' ] );
        add_settings_field( 'offset_y', __( 'Vertical Offset (px)', 'anchor-schema' ), [ $this, 'field_number' ], self::PAGE_SLUG, 'aa_appearance', [ 'key' => 'offset_y', 'min' => 0, 'max' => 500, 'suffix' => 'distance from bottom' ] );

        /* --- Tablet section --- */
        add_settings_section(
            'aa_tablet',
            __( 'Tablet Settings (iPad)', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Override widget size and position for tablet devices. Leave blank to inherit desktop values.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'tablet_breakpoint', __( 'Breakpoint (px)', 'anchor-schema' ), [ $this, 'field_number' ], self::PAGE_SLUG, 'aa_tablet', [ 'key' => 'tablet_breakpoint', 'min' => 320, 'max' => 2560, 'suffix' => 'applies at this width and below' ] );
        add_settings_field( 'tablet_icon_size', __( 'Button Size (px)', 'anchor-schema' ), [ $this, 'field_number' ], self::PAGE_SLUG, 'aa_tablet', [ 'key' => 'tablet_icon_size', 'min' => 30, 'max' => 100, 'placeholder' => 'Inherit' ] );
        add_settings_field( 'tablet_offset_x', __( 'Horizontal Offset (px)', 'anchor-schema' ), [ $this, 'field_number' ], self::PAGE_SLUG, 'aa_tablet', [ 'key' => 'tablet_offset_x', 'min' => 0, 'max' => 500, 'placeholder' => 'Inherit' ] );
        add_settings_field( 'tablet_offset_y', __( 'Vertical Offset (px)', 'anchor-schema' ), [ $this, 'field_number' ], self::PAGE_SLUG, 'aa_tablet', [ 'key' => 'tablet_offset_y', 'min' => 0, 'max' => 500, 'placeholder' => 'Inherit' ] );

        /* --- Mobile section --- */
        add_settings_section(
            'aa_mobile',
            __( 'Mobile Settings', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Override widget size and position for mobile devices. Leave blank to inherit tablet or desktop values.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'mobile_breakpoint', __( 'Breakpoint (px)', 'anchor-schema' ), [ $this, 'field_number' ], self::PAGE_SLUG, 'aa_mobile', [ 'key' => 'mobile_breakpoint', 'min' => 320, 'max' => 2560, 'suffix' => 'applies at this width and below' ] );
        add_settings_field( 'mobile_icon_size', __( 'Button Size (px)', 'anchor-schema' ), [ $this, 'field_number' ], self::PAGE_SLUG, 'aa_mobile', [ 'key' => 'mobile_icon_size', 'min' => 30, 'max' => 100, 'placeholder' => 'Inherit' ] );
        add_settings_field( 'mobile_offset_x', __( 'Horizontal Offset (px)', 'anchor-schema' ), [ $this, 'field_number' ], self::PAGE_SLUG, 'aa_mobile', [ 'key' => 'mobile_offset_x', 'min' => 0, 'max' => 500, 'placeholder' => 'Inherit' ] );
        add_settings_field( 'mobile_offset_y', __( 'Vertical Offset (px)', 'anchor-schema' ), [ $this, 'field_number' ], self::PAGE_SLUG, 'aa_mobile', [ 'key' => 'mobile_offset_y', 'min' => 0, 'max' => 500, 'placeholder' => 'Inherit' ] );

        /* --- Features section --- */
        add_settings_section(
            'aa_features',
            __( 'Enabled Features', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Toggle individual accessibility features on or off.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        $features = [
            'enable_profiles'       => __( 'Accessibility Profiles', 'anchor-schema' ),
            'enable_screen_reader'  => __( 'Screen Reader', 'anchor-schema' ),
            'enable_font_size'      => __( 'Bigger Text', 'anchor-schema' ),
            'enable_contrast'       => __( 'Contrast Modes', 'anchor-schema' ),
            'enable_smart_contrast' => __( 'Smart Contrast', 'anchor-schema' ),
            'enable_grayscale'      => __( 'Saturation Controls', 'anchor-schema' ),
            'enable_underline'      => __( 'Highlight Links', 'anchor-schema' ),
            'enable_readable'       => __( 'Dyslexia Friendly Font', 'anchor-schema' ),
            'enable_spacing'        => __( 'Text Spacing', 'anchor-schema' ),
            'enable_hide_images'    => __( 'Hide Images', 'anchor-schema' ),
            'enable_big_cursor'     => __( 'Big Cursor', 'anchor-schema' ),
            'enable_pause_anim'     => __( 'Pause Animations', 'anchor-schema' ),
            'enable_highlight'      => __( 'Focus Highlight', 'anchor-schema' ),
            'enable_reading_guide'  => __( 'Reading Guide', 'anchor-schema' ),
            'enable_reading_mask'   => __( 'Reading Mask', 'anchor-schema' ),
            'enable_tooltips'       => __( 'Tooltips', 'anchor-schema' ),
            'enable_page_structure' => __( 'Page Structure', 'anchor-schema' ),
            'enable_line_height'    => __( 'Line Height', 'anchor-schema' ),
            'enable_text_align'     => __( 'Text Align', 'anchor-schema' ),
            'enable_dictionary'     => __( 'Dictionary', 'anchor-schema' ),
            'enable_move_widget'    => __( 'Move/Hide Widget', 'anchor-schema' ),
        ];

        foreach ( $features as $key => $label ) {
            add_settings_field( $key, $label, [ $this, 'field_checkbox' ], self::PAGE_SLUG, 'aa_features', [ 'key' => $key ] );
        }

        /* --- Extra section --- */
        add_settings_section(
            'aa_extra',
            __( 'Additional Settings', 'anchor-schema' ),
            null,
            self::PAGE_SLUG
        );

        add_settings_field( 'statement_url', __( 'Accessibility Statement URL', 'anchor-schema' ), [ $this, 'field_text' ], self::PAGE_SLUG, 'aa_extra', [ 'key' => 'statement_url', 'placeholder' => 'https://example.com/accessibility' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Field renderers                                                   */
    /* ------------------------------------------------------------------ */

    public function field_color( $args ) {
        $opts = $this->get_options();
        printf(
            '<input type="color" name="%s[%s]" value="%s" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            esc_attr( $opts[ $args['key'] ] )
        );
    }

    public function field_position( $args ) {
        $opts = $this->get_options();
        $val  = $opts['position'];
        $choices = [
            'bottom-left'  => __( 'Bottom Left', 'anchor-schema' ),
            'bottom-right' => __( 'Bottom Right', 'anchor-schema' ),
        ];
        foreach ( $choices as $k => $label ) {
            printf(
                '<label style="margin-right:16px;"><input type="radio" name="%s[position]" value="%s" %s /> %s</label>',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $k ),
                checked( $val, $k, false ),
                esc_html( $label )
            );
        }
    }

    public function field_number( $args ) {
        $opts  = $this->get_options();
        $value = $opts[ $args['key'] ] ?? '';
        printf(
            '<input type="number" name="%s[%s]" value="%s" min="%s" max="%s" class="small-text"%s />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            esc_attr( $value ),
            esc_attr( $args['min'] ?? 0 ),
            esc_attr( $args['max'] ?? 999 ),
            ! empty( $args['placeholder'] ) ? ' placeholder="' . esc_attr( $args['placeholder'] ) . '"' : ''
        );
        if ( ! empty( $args['suffix'] ) ) {
            echo ' <span class="description">' . esc_html( $args['suffix'] ) . '</span>';
        }
    }

    public function field_checkbox( $args ) {
        $opts = $this->get_options();
        printf(
            '<input type="hidden" name="%1$s[%2$s]" value="0" />'
            . '<input type="checkbox" name="%1$s[%2$s]" value="1" %3$s />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            checked( $opts[ $args['key'] ], '1', false )
        );
    }

    public function field_text( $args ) {
        $opts = $this->get_options();
        printf(
            '<input type="text" name="%s[%s]" value="%s" class="regular-text" placeholder="%s" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            esc_attr( $opts[ $args['key'] ] ),
            esc_attr( $args['placeholder'] ?? '' )
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Sanitize                                                          */
    /* ------------------------------------------------------------------ */

    public function sanitize_options( $input ) {
        $defs = $this->defaults();
        $out  = [];

        // Colors
        foreach ( [ 'widget_color', 'accent_color' ] as $k ) {
            $out[ $k ] = sanitize_hex_color( $input[ $k ] ?? $defs[ $k ] ) ?: $defs[ $k ];
        }

        // Position
        $out['position'] = in_array( $input['position'] ?? '', [ 'bottom-left', 'bottom-right' ], true )
            ? $input['position']
            : $defs['position'];

        // Icon size
        $out['icon_size'] = max( 40, min( 80, (int) ( $input['icon_size'] ?? $defs['icon_size'] ) ) );

        // Offsets
        $out['offset_x'] = max( 0, min( 500, (int) ( $input['offset_x'] ?? $defs['offset_x'] ) ) );
        $out['offset_y'] = max( 0, min( 500, (int) ( $input['offset_y'] ?? $defs['offset_y'] ) ) );

        // Tablet overrides (empty = inherit)
        $out['tablet_breakpoint'] = max( 320, min( 2560, (int) ( $input['tablet_breakpoint'] ?? $defs['tablet_breakpoint'] ) ) );
        $out['tablet_icon_size']  = ( $input['tablet_icon_size'] ?? '' ) !== '' ? (string) max( 30, min( 100, (int) $input['tablet_icon_size'] ) ) : '';
        $out['tablet_offset_x']   = ( $input['tablet_offset_x'] ?? '' ) !== '' ? (string) max( 0, min( 500, (int) $input['tablet_offset_x'] ) ) : '';
        $out['tablet_offset_y']   = ( $input['tablet_offset_y'] ?? '' ) !== '' ? (string) max( 0, min( 500, (int) $input['tablet_offset_y'] ) ) : '';

        // Mobile overrides (empty = inherit)
        $out['mobile_breakpoint'] = max( 320, min( 2560, (int) ( $input['mobile_breakpoint'] ?? $defs['mobile_breakpoint'] ) ) );
        $out['mobile_icon_size']  = ( $input['mobile_icon_size'] ?? '' ) !== '' ? (string) max( 30, min( 100, (int) $input['mobile_icon_size'] ) ) : '';
        $out['mobile_offset_x']   = ( $input['mobile_offset_x'] ?? '' ) !== '' ? (string) max( 0, min( 500, (int) $input['mobile_offset_x'] ) ) : '';
        $out['mobile_offset_y']   = ( $input['mobile_offset_y'] ?? '' ) !== '' ? (string) max( 0, min( 500, (int) $input['mobile_offset_y'] ) ) : '';

        // Checkboxes
        foreach ( $defs as $k => $v ) {
            if ( str_starts_with( $k, 'enable_' ) ) {
                $out[ $k ] = ! empty( $input[ $k ] ) ? '1' : '0';
            }
        }

        // Statement URL
        $out['statement_url'] = esc_url_raw( $input['statement_url'] ?? '' );

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Tab content (rendered inside the unified settings page)           */
    /* ------------------------------------------------------------------ */

    public function render_tab_content() {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'anchor_accessibility_group' );
            do_settings_sections( self::PAGE_SLUG );
            submit_button();
            ?>
        </form>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Frontend                                                          */
    /* ------------------------------------------------------------------ */

    public function enqueue_frontend() {
        if ( is_admin() ) return;
        if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) return;
        if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_pb_preview'] ) ) return;

        $base = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-accessibility/assets/';
        $dir  = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-accessibility/assets/';

        wp_enqueue_style(
            'anchor-accessibility',
            $base . 'frontend.css',
            [],
            filemtime( $dir . 'frontend.css' )
        );

        wp_enqueue_script(
            'anchor-accessibility',
            $base . 'frontend.js',
            [],
            filemtime( $dir . 'frontend.js' ),
            true
        );

        $opts = $this->get_options();

        wp_localize_script( 'anchor-accessibility', 'AnchorA11y', [
            'color'      => $opts['widget_color'],
            'accent'     => $opts['accent_color'],
            'position'   => $opts['position'],
            'size'       => (int) $opts['icon_size'],
            'statement'  => $opts['statement_url'],
            'features'   => [
                'profiles'       => $opts['enable_profiles'] === '1',
                'screenReader'   => $opts['enable_screen_reader'] === '1',
                'fontSize'       => $opts['enable_font_size'] === '1',
                'contrast'       => $opts['enable_contrast'] === '1',
                'smartContrast'  => $opts['enable_smart_contrast'] === '1',
                'saturation'     => $opts['enable_grayscale'] === '1',
                'highlightLinks' => $opts['enable_underline'] === '1',
                'readable'       => $opts['enable_readable'] === '1',
                'spacing'        => $opts['enable_spacing'] === '1',
                'hideImages'     => $opts['enable_hide_images'] === '1',
                'bigCursor'      => $opts['enable_big_cursor'] === '1',
                'pauseAnim'      => $opts['enable_pause_anim'] === '1',
                'highlight'      => $opts['enable_highlight'] === '1',
                'readingGuide'   => $opts['enable_reading_guide'] === '1',
                'readingMask'    => $opts['enable_reading_mask'] === '1',
                'tooltips'       => $opts['enable_tooltips'] === '1',
                'pageStructure'  => $opts['enable_page_structure'] === '1',
                'lineHeight'     => $opts['enable_line_height'] === '1',
                'textAlign'      => $opts['enable_text_align'] === '1',
                'dictionary'     => $opts['enable_dictionary'] === '1',
                'moveWidget'     => $opts['enable_move_widget'] === '1',
            ],
            'strings'    => [
                'menuTitle'           => __( 'Accessibility Menu', 'anchor-schema' ),
                'profilesTitle'       => __( 'Accessibility Profiles', 'anchor-schema' ),
                'dictionaryTitle'     => __( 'Dictionary', 'anchor-schema' ),
                'dictionaryEmpty'     => __( 'Select a single word or enter one below to look it up.', 'anchor-schema' ),
                'dictionaryMissing'   => __( 'No definition found for this word.', 'anchor-schema' ),
                'dictionaryError'     => __( 'Dictionary lookup failed.', 'anchor-schema' ),
                'screenReaderEnabled' => __( 'Screen reader mode enabled. Click or focus text to hear it read aloud.', 'anchor-schema' ),
                'screenReaderOff'     => __( 'Screen reader mode disabled.', 'anchor-schema' ),
            ],
        ] );
    }

    public function render_widget() {
        if ( is_admin() ) return;

        // Skip when Divi builder is active (visual builder or theme builder preview).
        if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) return;
        if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_pb_preview'] ) ) return;

        $opts   = $this->get_options();
        $pos    = $opts['position'];
        $size   = (int) $opts['icon_size'];
        $color  = esc_attr( $opts['widget_color'] );
        $accent = esc_attr( $opts['accent_color'] );

        $feature_flags = [
            'profiles'       => $opts['enable_profiles'] === '1',
            'screen-reader'  => $opts['enable_screen_reader'] === '1',
            'contrast'       => $opts['enable_contrast'] === '1',
            'smart-contrast' => $opts['enable_smart_contrast'] === '1',
            'highlight-links'=> $opts['enable_underline'] === '1',
            'font-size'      => $opts['enable_font_size'] === '1',
            'text-spacing'   => $opts['enable_spacing'] === '1',
            'pause-anim'     => $opts['enable_pause_anim'] === '1',
            'hide-images'    => $opts['enable_hide_images'] === '1',
            'readable'       => $opts['enable_readable'] === '1',
            'reading-guide'  => $opts['enable_reading_guide'] === '1',
            'reading-mask'   => $opts['enable_reading_mask'] === '1',
            'tooltips'       => $opts['enable_tooltips'] === '1',
            'page-structure' => $opts['enable_page_structure'] === '1',
            'line-height'    => $opts['enable_line_height'] === '1',
            'text-align'     => $opts['enable_text_align'] === '1',
            'dictionary'     => $opts['enable_dictionary'] === '1',
            'saturation'     => $opts['enable_grayscale'] === '1',
            'big-cursor'     => $opts['enable_big_cursor'] === '1',
            'focus-highlight'=> $opts['enable_highlight'] === '1',
            'move-widget'    => $opts['enable_move_widget'] === '1',
        ];

        $profiles = [
            [ 'key' => 'motor',     'label' => __( 'Motor Impaired', 'anchor-schema' ) ],
            [ 'key' => 'blind',     'label' => __( 'Blind', 'anchor-schema' ) ],
            [ 'key' => 'color',     'label' => __( 'Color Blind', 'anchor-schema' ) ],
            [ 'key' => 'dyslexia',  'label' => __( 'Dyslexia', 'anchor-schema' ) ],
            [ 'key' => 'vision',    'label' => __( 'Low Vision', 'anchor-schema' ) ],
            [ 'key' => 'cognitive', 'label' => __( 'Cognitive & Learning', 'anchor-schema' ) ],
            [ 'key' => 'seizure',   'label' => __( 'Seizure & Epileptic', 'anchor-schema' ) ],
            [ 'key' => 'adhd',      'label' => __( 'ADHD', 'anchor-schema' ) ],
        ];

        $cards = [
            [ 'key' => 'screen-reader',  'label' => __( 'Screen Reader', 'anchor-schema' ),      'mode' => 'toggle', 'flag' => 'screen-reader' ],
            [ 'key' => 'contrast',       'label' => __( 'Contrast +', 'anchor-schema' ),          'mode' => 'panel',  'flag' => 'contrast' ],
            [ 'key' => 'smart-contrast', 'label' => __( 'Smart Contrast', 'anchor-schema' ),      'mode' => 'toggle', 'flag' => 'smart-contrast' ],
            [ 'key' => 'highlight-links','label' => __( 'Highlight Links', 'anchor-schema' ),     'mode' => 'toggle', 'flag' => 'highlight-links' ],
            [ 'key' => 'font-size',      'label' => __( 'Bigger Text', 'anchor-schema' ),         'mode' => 'cycle',  'flag' => 'font-size' ],
            [ 'key' => 'text-spacing',   'label' => __( 'Text Spacing', 'anchor-schema' ),        'mode' => 'panel',  'flag' => 'text-spacing' ],
            [ 'key' => 'pause-anim',     'label' => __( 'Pause Animations', 'anchor-schema' ),    'mode' => 'toggle', 'flag' => 'pause-anim' ],
            [ 'key' => 'hide-images',    'label' => __( 'Hide Images', 'anchor-schema' ),         'mode' => 'toggle', 'flag' => 'hide-images' ],
            [ 'key' => 'readable',       'label' => __( 'Dyslexia Friendly', 'anchor-schema' ),   'mode' => 'toggle', 'flag' => 'readable' ],
            [ 'key' => 'reading-guide',  'label' => __( 'Reading Guide', 'anchor-schema' ),       'mode' => 'toggle', 'flag' => 'reading-guide' ],
            [ 'key' => 'reading-mask',   'label' => __( 'Reading Mask', 'anchor-schema' ),        'mode' => 'toggle', 'flag' => 'reading-mask' ],
            [ 'key' => 'tooltips',       'label' => __( 'Tooltips', 'anchor-schema' ),            'mode' => 'toggle', 'flag' => 'tooltips' ],
            [ 'key' => 'page-structure', 'label' => __( 'Page Structure', 'anchor-schema' ),      'mode' => 'modal',  'flag' => 'page-structure' ],
            [ 'key' => 'line-height',    'label' => __( 'Line Height', 'anchor-schema' ),         'mode' => 'panel',  'flag' => 'line-height' ],
            [ 'key' => 'text-align',     'label' => __( 'Text Align', 'anchor-schema' ),          'mode' => 'panel',  'flag' => 'text-align' ],
            [ 'key' => 'dictionary',     'label' => __( 'Dictionary', 'anchor-schema' ),          'mode' => 'modal',  'flag' => 'dictionary' ],
            [ 'key' => 'saturation',     'label' => __( 'Saturation', 'anchor-schema' ),          'mode' => 'panel',  'flag' => 'saturation' ],
            [ 'key' => 'big-cursor',     'label' => __( 'Big Cursor', 'anchor-schema' ),          'mode' => 'toggle', 'flag' => 'big-cursor' ],
            [ 'key' => 'focus-highlight','label' => __( 'Focus Highlight', 'anchor-schema' ),     'mode' => 'toggle', 'flag' => 'focus-highlight' ],
        ];

        ?>
        <div id="anchor-a11y-widget"
             class="anchor-a11y-widget anchor-a11y-<?php echo esc_attr( $pos ); ?>"
             style="--aa-color:<?php echo $color; ?>;--aa-accent:<?php echo $accent; ?>;"
             aria-label="<?php esc_attr_e( 'Accessibility options', 'anchor-schema' ); ?>"
             role="region">

            <button class="anchor-a11y-toggle" aria-expanded="false" aria-controls="anchor-a11y-panel"
                    aria-label="<?php esc_attr_e( 'Open accessibility menu', 'anchor-schema' ); ?>"
                    title="<?php esc_attr_e( 'Open accessibility menu', 'anchor-schema' ); ?>">
                <svg class="anchor-a11y-toggle-icon" aria-hidden="true" viewBox="0 0 512 512" fill="currentColor"><path d="M256 48c114.953 0 208 93.029 208 208 0 114.953-93.029 208-208 208-114.953 0-208-93.029-208-208 0-114.953 93.029-208 208-208m0-40C119.033 8 8 119.033 8 256s111.033 248 248 248 248-111.033 248-248S392.967 8 256 8zm0 56C149.961 64 64 149.961 64 256s85.961 192 192 192 192-85.961 192-192S362.039 64 256 64zm0 44c19.882 0 36 16.118 36 36s-16.118 36-36 36-36-16.118-36-36 16.118-36 36-36zm117.741 98.023c-28.712 6.779-55.511 12.748-82.14 15.807.851 101.023 12.306 123.052 25.037 155.621 3.617 9.26-.957 19.698-10.217 23.315-9.261 3.617-19.699-.957-23.316-10.217-8.705-22.308-17.086-40.636-22.261-78.549h-9.686c-5.167 37.851-13.534 56.208-22.262 78.549-3.615 9.255-14.05 13.836-23.315 10.217-9.26-3.617-13.834-14.056-10.217-23.315 12.713-32.541 24.185-54.541 25.037-155.621-26.629-3.058-53.428-9.027-82.141-15.807-8.6-2.031-13.926-10.648-11.895-19.249s10.647-13.926 19.249-11.895c96.686 22.829 124.283 22.783 220.775 0 8.599-2.03 17.218 3.294 19.249 11.895 2.029 8.601-3.297 17.219-11.897 19.249z"/></svg>
            </button>
            <button class="anchor-a11y-restore" type="button" hidden data-action="restore-widget" aria-label="<?php esc_attr_e( 'Show accessibility widget', 'anchor-schema' ); ?>" title="<?php esc_attr_e( 'Show accessibility widget', 'anchor-schema' ); ?>">
                <svg class="anchor-a11y-toggle-icon" aria-hidden="true" viewBox="0 0 512 512" fill="currentColor"><path d="M256 48c114.953 0 208 93.029 208 208 0 114.953-93.029 208-208 208-114.953 0-208-93.029-208-208 0-114.953 93.029-208 208-208m0-40C119.033 8 8 119.033 8 256s111.033 248 248 248 248-111.033 248-248S392.967 8 256 8zm0 56C149.961 64 64 149.961 64 256s85.961 192 192 192 192-85.961 192-192S362.039 64 256 64zm0 44c19.882 0 36 16.118 36 36s-16.118 36-36 36-36-16.118-36-36 16.118-36 36-36zm117.741 98.023c-28.712 6.779-55.511 12.748-82.14 15.807.851 101.023 12.306 123.052 25.037 155.621 3.617 9.26-.957 19.698-10.217 23.315-9.261 3.617-19.699-.957-23.316-10.217-8.705-22.308-17.086-40.636-22.261-78.549h-9.686c-5.167 37.851-13.534 56.208-22.262 78.549-3.615 9.255-14.05 13.836-23.315 10.217-9.26-3.617-13.834-14.056-10.217-23.315 12.713-32.541 24.185-54.541 25.037-155.621-26.629-3.058-53.428-9.027-82.141-15.807-8.6-2.031-13.926-10.648-11.895-19.249s10.647-13.926 19.249-11.895c96.686 22.829 124.283 22.783 220.775 0 8.599-2.03 17.218 3.294 19.249 11.895 2.029 8.601-3.297 17.219-11.897 19.249z"/></svg>
            </button>

            <div id="anchor-a11y-panel" class="anchor-a11y-panel" role="dialog" aria-modal="false" aria-label="<?php esc_attr_e( 'Accessibility Menu', 'anchor-schema' ); ?>" hidden>
                <div class="anchor-a11y-header">
                    <div class="anchor-a11y-title-wrap">
                        <strong><?php esc_html_e( 'Accessibility Menu', 'anchor-schema' ); ?></strong>
                        <small><?php esc_html_e( 'Ctrl+U', 'anchor-schema' ); ?></small>
                    </div>
                    <button class="anchor-a11y-close" aria-label="<?php esc_attr_e( 'Close', 'anchor-schema' ); ?>">&times;</button>
                </div>
                <div class="anchor-a11y-body">
                    <?php if ( $feature_flags['profiles'] ) : ?>
                    <section class="anchor-a11y-section">
                        <button class="anchor-a11y-section-toggle" type="button" data-section-toggle="profiles" aria-expanded="false" aria-controls="anchor-a11y-profiles">
                            <span><?php esc_html_e( 'Accessibility Profiles', 'anchor-schema' ); ?></span>
                            <span aria-hidden="true">▾</span>
                        </button>
                        <div id="anchor-a11y-profiles" class="anchor-a11y-profiles-grid" hidden>
                            <?php foreach ( $profiles as $profile ) : ?>
                            <button class="anchor-a11y-profile-card" type="button" data-profile="<?php echo esc_attr( $profile['key'] ); ?>">
                                <span class="anchor-a11y-profile-name"><?php echo esc_html( $profile['label'] ); ?></span>
                                <span class="anchor-a11y-check" aria-hidden="true">✓</span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <section class="anchor-a11y-section">
                        <div class="anchor-a11y-section-heading"><?php esc_html_e( 'Accessibility Settings', 'anchor-schema' ); ?></div>
                        <div class="anchor-a11y-card-grid">
                            <?php foreach ( $cards as $card ) : ?>
                                <?php if ( empty( $feature_flags[ $card['flag'] ] ) ) continue; ?>
                                <button
                                    class="anchor-a11y-card"
                                    type="button"
                                    data-card-key="<?php echo esc_attr( $card['key'] ); ?>"
                                    <?php if ( $card['mode'] === 'toggle' ) : ?>
                                        data-toggle-feature="<?php echo esc_attr( $card['key'] ); ?>"
                                    <?php elseif ( $card['mode'] === 'panel' ) : ?>
                                        data-open-panel="<?php echo esc_attr( $card['key'] ); ?>"
                                    <?php elseif ( $card['mode'] === 'modal' ) : ?>
                                        data-open-modal="<?php echo esc_attr( $card['key'] ); ?>"
                                    <?php else : ?>
                                        data-cycle-feature="<?php echo esc_attr( $card['key'] ); ?>"
                                    <?php endif; ?>
                                >
                                    <span class="anchor-a11y-card-title"><?php echo esc_html( $card['label'] ); ?></span>
                                    <span class="anchor-a11y-card-meta" data-card-meta="<?php echo esc_attr( $card['key'] ); ?>"></span>
                                    <?php if ( $card['key'] !== 'dictionary' && $card['mode'] !== 'modal' ) : ?>
                                        <span class="anchor-a11y-check" aria-hidden="true">✓</span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>

                <div class="anchor-a11y-footer">
                    <button class="anchor-a11y-reset" data-action="reset-all"><?php esc_html_e( 'Reset All', 'anchor-schema' ); ?></button>
                    <?php if ( $feature_flags['move-widget'] ) : ?>
                        <button class="anchor-a11y-linklike" type="button" data-open-modal="move-widget"><?php esc_html_e( 'Move/Hide Widget', 'anchor-schema' ); ?></button>
                    <?php endif; ?>
                    <?php if ( ! empty( $opts['statement_url'] ) ) : ?>
                        <a href="<?php echo esc_url( $opts['statement_url'] ); ?>" class="anchor-a11y-statement" target="_blank" rel="noopener">
                            <?php esc_html_e( 'Accessibility Statement', 'anchor-schema' ); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="anchor-a11y-popover" id="anchor-a11y-popover-contrast" hidden>
                <div class="anchor-a11y-popover-title"><?php esc_html_e( 'Contrast Modes', 'anchor-schema' ); ?></div>
                <div class="anchor-a11y-choice-list">
                    <button type="button" data-choice="contrast" data-value="off"><?php esc_html_e( 'Off', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="contrast" data-value="more"><?php esc_html_e( 'Contrast +', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="contrast" data-value="dark"><?php esc_html_e( 'Dark Contrast', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="contrast" data-value="light"><?php esc_html_e( 'Light Contrast', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="contrast" data-value="invert"><?php esc_html_e( 'Invert Colors', 'anchor-schema' ); ?></button>
                </div>
            </div>

            <div class="anchor-a11y-popover" id="anchor-a11y-popover-text-spacing" hidden>
                <div class="anchor-a11y-popover-title"><?php esc_html_e( 'Text Spacing', 'anchor-schema' ); ?></div>
                <div class="anchor-a11y-choice-list">
                    <button type="button" data-choice="spacing" data-value="0"><?php esc_html_e( 'Off', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="spacing" data-value="1"><?php esc_html_e( 'Light', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="spacing" data-value="2"><?php esc_html_e( 'Moderate', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="spacing" data-value="3"><?php esc_html_e( 'Heavy', 'anchor-schema' ); ?></button>
                </div>
            </div>

            <div class="anchor-a11y-popover" id="anchor-a11y-popover-line-height" hidden>
                <div class="anchor-a11y-popover-title"><?php esc_html_e( 'Line Height', 'anchor-schema' ); ?></div>
                <div class="anchor-a11y-choice-list">
                    <button type="button" data-choice="line-height" data-value="0"><?php esc_html_e( 'Default', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="line-height" data-value="1"><?php esc_html_e( 'Relaxed', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="line-height" data-value="2"><?php esc_html_e( 'Open', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="line-height" data-value="3"><?php esc_html_e( 'Loose', 'anchor-schema' ); ?></button>
                </div>
            </div>

            <div class="anchor-a11y-popover" id="anchor-a11y-popover-text-align" hidden>
                <div class="anchor-a11y-popover-title"><?php esc_html_e( 'Text Align', 'anchor-schema' ); ?></div>
                <div class="anchor-a11y-choice-list">
                    <button type="button" data-choice="text-align" data-value="off"><?php esc_html_e( 'Default', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="text-align" data-value="left"><?php esc_html_e( 'Left', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="text-align" data-value="center"><?php esc_html_e( 'Center', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="text-align" data-value="right"><?php esc_html_e( 'Right', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="text-align" data-value="justify"><?php esc_html_e( 'Justify', 'anchor-schema' ); ?></button>
                </div>
            </div>

            <div class="anchor-a11y-popover" id="anchor-a11y-popover-saturation" hidden>
                <div class="anchor-a11y-popover-title"><?php esc_html_e( 'Saturation', 'anchor-schema' ); ?></div>
                <div class="anchor-a11y-choice-list">
                    <button type="button" data-choice="saturation" data-value="off"><?php esc_html_e( 'Off', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="saturation" data-value="low"><?php esc_html_e( 'Low Saturation', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="saturation" data-value="high"><?php esc_html_e( 'High Saturation', 'anchor-schema' ); ?></button>
                    <button type="button" data-choice="saturation" data-value="mono"><?php esc_html_e( 'Monochrome', 'anchor-schema' ); ?></button>
                </div>
            </div>

            <div class="anchor-a11y-modal-backdrop" hidden></div>

            <div class="anchor-a11y-modal" id="anchor-a11y-modal-page-structure" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Page Structure', 'anchor-schema' ); ?>">
                <div class="anchor-a11y-modal-header">
                    <strong><?php esc_html_e( 'Page Structure', 'anchor-schema' ); ?></strong>
                    <button type="button" class="anchor-a11y-modal-close" data-close-modal>&times;</button>
                </div>
                <div class="anchor-a11y-modal-tabs">
                    <button type="button" class="is-active" data-structure-tab="headings"><?php esc_html_e( 'Headings', 'anchor-schema' ); ?></button>
                    <button type="button" data-structure-tab="landmarks"><?php esc_html_e( 'Landmarks', 'anchor-schema' ); ?></button>
                    <button type="button" data-structure-tab="links"><?php esc_html_e( 'Links', 'anchor-schema' ); ?></button>
                </div>
                <div class="anchor-a11y-modal-body">
                    <div class="anchor-a11y-structure-list" data-structure-list="headings"></div>
                    <div class="anchor-a11y-structure-list" data-structure-list="landmarks" hidden></div>
                    <div class="anchor-a11y-structure-list" data-structure-list="links" hidden></div>
                </div>
            </div>

            <div class="anchor-a11y-modal" id="anchor-a11y-modal-dictionary" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Dictionary', 'anchor-schema' ); ?>">
                <div class="anchor-a11y-modal-header">
                    <strong><?php esc_html_e( 'Dictionary', 'anchor-schema' ); ?></strong>
                    <button type="button" class="anchor-a11y-modal-close" data-close-modal>&times;</button>
                </div>
                <div class="anchor-a11y-modal-body anchor-a11y-dictionary-body">
                    <form class="anchor-a11y-dictionary-form" data-dictionary-form>
                        <input type="text" class="anchor-a11y-dictionary-input" data-dictionary-input placeholder="<?php esc_attr_e( 'Enter a word', 'anchor-schema' ); ?>" />
                        <button type="submit" class="anchor-a11y-dictionary-submit"><?php esc_html_e( 'Search', 'anchor-schema' ); ?></button>
                    </form>
                    <div class="anchor-a11y-dictionary-result" data-dictionary-result>
                        <p><?php esc_html_e( 'Select a single word or enter one below to look it up.', 'anchor-schema' ); ?></p>
                    </div>
                </div>
            </div>

            <div class="anchor-a11y-modal" id="anchor-a11y-modal-move-widget" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Move or Hide Widget', 'anchor-schema' ); ?>">
                <div class="anchor-a11y-modal-header">
                    <strong><?php esc_html_e( 'Move/Hide Widget', 'anchor-schema' ); ?></strong>
                    <button type="button" class="anchor-a11y-modal-close" data-close-modal>&times;</button>
                </div>
                <div class="anchor-a11y-modal-body">
                    <div class="anchor-a11y-choice-list">
                        <button type="button" data-choice="widget-position" data-value="left"><?php esc_html_e( 'Left', 'anchor-schema' ); ?></button>
                        <button type="button" data-choice="widget-position" data-value="right"><?php esc_html_e( 'Right', 'anchor-schema' ); ?></button>
                        <button type="button" data-choice="widget-position" data-value="hide"><?php esc_html_e( 'Hide', 'anchor-schema' ); ?></button>
                    </div>
                </div>
            </div>

            <div class="anchor-a11y-reading-guide" aria-hidden="true" hidden></div>
            <div class="anchor-a11y-reading-mask anchor-a11y-reading-mask-top" aria-hidden="true" hidden></div>
            <div class="anchor-a11y-reading-mask anchor-a11y-reading-mask-bottom" aria-hidden="true" hidden></div>
            <div class="anchor-a11y-tooltip" aria-hidden="true" hidden></div>
            <div class="anchor-a11y-live" aria-live="polite" aria-atomic="true"></div>
        </div>
        <?php
        $css = '#anchor-a11y-widget{--aa-size:' . $size . 'px;--aa-offset-x:' . (int) $opts['offset_x'] . 'px;--aa-offset-y:' . (int) $opts['offset_y'] . 'px}';

        $tablet_bp = (int) $opts['tablet_breakpoint'];
        $tablet_rules = [];
        if ( $opts['tablet_icon_size'] !== '' ) $tablet_rules[] = '--aa-size:' . (int) $opts['tablet_icon_size'] . 'px';
        if ( $opts['tablet_offset_x'] !== '' ) $tablet_rules[] = '--aa-offset-x:' . (int) $opts['tablet_offset_x'] . 'px';
        if ( $opts['tablet_offset_y'] !== '' ) $tablet_rules[] = '--aa-offset-y:' . (int) $opts['tablet_offset_y'] . 'px';
        if ( $tablet_rules ) {
            $css .= '@media(max-width:' . $tablet_bp . 'px){#anchor-a11y-widget{' . implode( ';', $tablet_rules ) . '}}';
        }

        $mobile_bp = (int) $opts['mobile_breakpoint'];
        $mobile_rules = [];
        if ( $opts['mobile_icon_size'] !== '' ) $mobile_rules[] = '--aa-size:' . (int) $opts['mobile_icon_size'] . 'px';
        if ( $opts['mobile_offset_x'] !== '' ) $mobile_rules[] = '--aa-offset-x:' . (int) $opts['mobile_offset_x'] . 'px';
        if ( $opts['mobile_offset_y'] !== '' ) $mobile_rules[] = '--aa-offset-y:' . (int) $opts['mobile_offset_y'] . 'px';
        if ( $mobile_rules ) {
            $css .= '@media(max-width:' . $mobile_bp . 'px){#anchor-a11y-widget{' . implode( ';', $mobile_rules ) . '}}';
        }

        $css .= '@media(max-width:' . $mobile_bp . 'px){.anchor-a11y-panel{width:min(420px,calc(100vw - 24px));max-height:75vh}.anchor-a11y-modal{width:min(720px,calc(100vw - 24px));max-height:80vh}}';

        echo '<style id="anchor-a11y-responsive">' . $css . '</style>';
    }
}
