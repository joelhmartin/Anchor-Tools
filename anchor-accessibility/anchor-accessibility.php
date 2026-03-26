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
            'position'           => 'bottom-right', // bottom-left | bottom-right
            'icon_size'          => '56',            // px
            'enable_font_size'   => '1',
            'enable_contrast'    => '1',
            'enable_grayscale'   => '1',
            'enable_underline'   => '1',
            'enable_readable'    => '1',
            'enable_spacing'     => '1',
            'enable_hide_images' => '1',
            'enable_big_cursor'  => '1',
            'enable_pause_anim'  => '1',
            'enable_highlight'   => '1',
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

        /* --- Features section --- */
        add_settings_section(
            'aa_features',
            __( 'Enabled Features', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Toggle individual accessibility features on or off.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        $features = [
            'enable_font_size'   => __( 'Font Size Adjustment', 'anchor-schema' ),
            'enable_contrast'    => __( 'High Contrast', 'anchor-schema' ),
            'enable_grayscale'   => __( 'Grayscale', 'anchor-schema' ),
            'enable_underline'   => __( 'Underline Links', 'anchor-schema' ),
            'enable_readable'    => __( 'Readable Font (dyslexia-friendly)', 'anchor-schema' ),
            'enable_spacing'     => __( 'Text Spacing', 'anchor-schema' ),
            'enable_hide_images' => __( 'Hide Images', 'anchor-schema' ),
            'enable_big_cursor'  => __( 'Big Cursor', 'anchor-schema' ),
            'enable_pause_anim'  => __( 'Pause Animations', 'anchor-schema' ),
            'enable_highlight'   => __( 'Keyboard Navigation Highlight', 'anchor-schema' ),
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
        $opts = $this->get_options();
        printf(
            '<input type="number" name="%s[%s]" value="%s" min="%d" max="%d" class="small-text" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            esc_attr( $opts[ $args['key'] ] ),
            (int) ( $args['min'] ?? 0 ),
            (int) ( $args['max'] ?? 999 )
        );
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
            'color'    => $opts['widget_color'],
            'accent'   => $opts['accent_color'],
            'position' => $opts['position'],
            'size'     => (int) $opts['icon_size'],
            'features' => array_filter( [
                'font_size'   => $opts['enable_font_size'],
                'contrast'    => $opts['enable_contrast'],
                'grayscale'   => $opts['enable_grayscale'],
                'underline'   => $opts['enable_underline'],
                'readable'    => $opts['enable_readable'],
                'spacing'     => $opts['enable_spacing'],
                'hide_images' => $opts['enable_hide_images'],
                'big_cursor'  => $opts['enable_big_cursor'],
                'pause_anim'  => $opts['enable_pause_anim'],
                'highlight'   => $opts['enable_highlight'],
            ] ),
            'statement' => $opts['statement_url'],
        ] );
    }

    public function render_widget() {
        if ( is_admin() ) return;

        $opts     = $this->get_options();
        $pos      = $opts['position'];
        $size     = (int) $opts['icon_size'];
        $color    = esc_attr( $opts['widget_color'] );
        $accent   = esc_attr( $opts['accent_color'] );
        $features = $opts;

        ?>
        <div id="anchor-a11y-widget"
             class="anchor-a11y-widget anchor-a11y-<?php echo esc_attr( $pos ); ?>"
             style="--aa-color:<?php echo $color; ?>;--aa-accent:<?php echo $accent; ?>;--aa-size:<?php echo $size; ?>px;"
             aria-label="<?php esc_attr_e( 'Accessibility options', 'anchor-schema' ); ?>"
             role="region">

            <!-- Toggle button -->
            <button class="anchor-a11y-toggle" aria-expanded="false" aria-controls="anchor-a11y-panel"
                    title="<?php esc_attr_e( 'Open accessibility menu', 'anchor-schema' ); ?>">
                <svg aria-hidden="true" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="4.5" r="2.5"/>
                    <path d="M12 7v4"/>
                    <path d="M7.5 9.5L12 11l4.5-1.5"/>
                    <path d="M9 21l3-8 3 8"/>
                </svg>
            </button>

            <!-- Panel -->
            <div id="anchor-a11y-panel" class="anchor-a11y-panel" role="dialog" aria-label="<?php esc_attr_e( 'Accessibility tools', 'anchor-schema' ); ?>" hidden>
                <div class="anchor-a11y-header">
                    <strong><?php esc_html_e( 'Accessibility', 'anchor-schema' ); ?></strong>
                    <button class="anchor-a11y-close" aria-label="<?php esc_attr_e( 'Close', 'anchor-schema' ); ?>">&times;</button>
                </div>
                <div class="anchor-a11y-body">

                    <?php if ( $features['enable_font_size'] === '1' ) : ?>
                    <div class="anchor-a11y-item anchor-a11y-font-size">
                        <span class="anchor-a11y-label"><?php esc_html_e( 'Font Size', 'anchor-schema' ); ?></span>
                        <div class="anchor-a11y-btn-group">
                            <button data-action="font-decrease" aria-label="<?php esc_attr_e( 'Decrease font size', 'anchor-schema' ); ?>">A&minus;</button>
                            <button data-action="font-reset" aria-label="<?php esc_attr_e( 'Reset font size', 'anchor-schema' ); ?>">A</button>
                            <button data-action="font-increase" aria-label="<?php esc_attr_e( 'Increase font size', 'anchor-schema' ); ?>">A+</button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php
                    $toggles = [];
                    if ( $features['enable_contrast']    === '1' ) $toggles[] = [ 'contrast',    __( 'High Contrast', 'anchor-schema' ),    'anchor-a11y-contrast' ];
                    if ( $features['enable_grayscale']   === '1' ) $toggles[] = [ 'grayscale',   __( 'Grayscale', 'anchor-schema' ),         'anchor-a11y-grayscale' ];
                    if ( $features['enable_underline']   === '1' ) $toggles[] = [ 'underline',   __( 'Underline Links', 'anchor-schema' ),   'anchor-a11y-underline' ];
                    if ( $features['enable_readable']    === '1' ) $toggles[] = [ 'readable',    __( 'Readable Font', 'anchor-schema' ),     'anchor-a11y-readable' ];
                    if ( $features['enable_spacing']     === '1' ) $toggles[] = [ 'spacing',     __( 'Text Spacing', 'anchor-schema' ),      'anchor-a11y-spacing' ];
                    if ( $features['enable_hide_images'] === '1' ) $toggles[] = [ 'hide-images', __( 'Hide Images', 'anchor-schema' ),       'anchor-a11y-hide-images' ];
                    if ( $features['enable_big_cursor']  === '1' ) $toggles[] = [ 'big-cursor',  __( 'Big Cursor', 'anchor-schema' ),        'anchor-a11y-big-cursor' ];
                    if ( $features['enable_pause_anim']  === '1' ) $toggles[] = [ 'pause-anim',  __( 'Pause Animations', 'anchor-schema' ),  'anchor-a11y-pause-anim' ];
                    if ( $features['enable_highlight']   === '1' ) $toggles[] = [ 'highlight',   __( 'Focus Highlight', 'anchor-schema' ),   'anchor-a11y-highlight' ];

                    foreach ( $toggles as $t ) : ?>
                    <div class="anchor-a11y-item">
                        <button class="anchor-a11y-feature" data-action="<?php echo esc_attr( $t[0] ); ?>" aria-pressed="false">
                            <?php echo esc_html( $t[1] ); ?>
                        </button>
                    </div>
                    <?php endforeach; ?>

                </div>

                <div class="anchor-a11y-footer">
                    <button class="anchor-a11y-reset" data-action="reset-all"><?php esc_html_e( 'Reset All', 'anchor-schema' ); ?></button>
                    <?php if ( ! empty( $opts['statement_url'] ) ) : ?>
                        <a href="<?php echo esc_url( $opts['statement_url'] ); ?>" class="anchor-a11y-statement" target="_blank" rel="noopener">
                            <?php esc_html_e( 'Accessibility Statement', 'anchor-schema' ); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
