<?php
/**
 * Anchor Optimize — Settings Page.
 *
 * Admin settings page under Settings > Anchor Optimize.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Optimize_Settings {

    const OPTION_KEY = 'anchor_optimize_settings';
    const PAGE_SLUG  = 'anchor-optimize';

    /** @var array Default settings. */
    private static $defaults = [
        'mode'             => 'smart',
        'quality'          => 82,
        'png_quality'      => 9,
        'strip_metadata'   => true,
        'max_width'        => 2560,
        'webp_enabled'     => true,
        'webp_quality'     => 80,
        'avif_enabled'     => false,
        'avif_quality'     => 65,
        'backup_originals' => true,
    ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* ────────────────────────────────────────────────────────
       Getters
       ──────────────────────────────────────────────────────── */

    /**
     * Get all settings merged with defaults.
     *
     * @return array
     */
    public static function get_settings() {
        return wp_parse_args( get_option( self::OPTION_KEY, [] ), self::$defaults );
    }

    /**
     * Get a single setting value.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function get( $key, $default = null ) {
        $settings = self::get_settings();
        return $settings[ $key ] ?? $default;
    }

    /* ────────────────────────────────────────────────────────
       Admin Menu & Assets
       ──────────────────────────────────────────────────────── */

    public function add_settings_page() {
        add_options_page(
            __( 'Anchor Optimize', 'anchor-schema' ),
            __( 'Anchor Optimize', 'anchor-schema' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'anchor-optimize-admin',
            ANCHOR_TOOLS_PLUGIN_URL . 'anchor-optimize/assets/admin.css',
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'anchor-optimize-admin',
            ANCHOR_TOOLS_PLUGIN_URL . 'anchor-optimize/assets/admin.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );
    }

    /* ────────────────────────────────────────────────────────
       Settings API
       ──────────────────────────────────────────────────────── */

    public function register_settings() {
        register_setting( 'anchor_optimize_group', self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize' ],
            'default'           => self::$defaults,
        ] );

        /* -- Section: Compression ----------------------------------- */
        add_settings_section( 'ao_compression', __( 'Compression', 'anchor-schema' ), function () {
            echo '<p>' . esc_html__( 'Configure how images are compressed on upload.', 'anchor-schema' ) . '</p>';
        }, self::PAGE_SLUG );

        add_settings_field( 'mode', __( 'Compression Mode', 'anchor-schema' ), [ $this, 'field_mode' ], self::PAGE_SLUG, 'ao_compression' );
        add_settings_field( 'quality', __( 'JPEG / WebP Quality', 'anchor-schema' ), [ $this, 'field_quality' ], self::PAGE_SLUG, 'ao_compression' );
        add_settings_field( 'png_quality', __( 'PNG Compression Level', 'anchor-schema' ), [ $this, 'field_png_quality' ], self::PAGE_SLUG, 'ao_compression' );
        add_settings_field( 'strip_metadata', __( 'Strip Metadata', 'anchor-schema' ), [ $this, 'field_strip_metadata' ], self::PAGE_SLUG, 'ao_compression' );
        add_settings_field( 'max_width', __( 'Max Width (px)', 'anchor-schema' ), [ $this, 'field_max_width' ], self::PAGE_SLUG, 'ao_compression' );
        add_settings_field( 'backup_originals', __( 'Backup Originals', 'anchor-schema' ), [ $this, 'field_backup_originals' ], self::PAGE_SLUG, 'ao_compression' );

        /* -- Section: Next-Gen Formats ------------------------------ */
        add_settings_section( 'ao_nextgen', __( 'Next-Gen Formats', 'anchor-schema' ), function () {
            echo '<p>' . esc_html__( 'Generate WebP and/or AVIF copies of every uploaded image.', 'anchor-schema' ) . '</p>';
        }, self::PAGE_SLUG );

        add_settings_field( 'webp_enabled', __( 'WebP Conversion', 'anchor-schema' ), [ $this, 'field_webp_enabled' ], self::PAGE_SLUG, 'ao_nextgen' );
        add_settings_field( 'webp_quality', __( 'WebP Quality', 'anchor-schema' ), [ $this, 'field_webp_quality' ], self::PAGE_SLUG, 'ao_nextgen' );
        add_settings_field( 'avif_enabled', __( 'AVIF Conversion', 'anchor-schema' ), [ $this, 'field_avif_enabled' ], self::PAGE_SLUG, 'ao_nextgen' );
        add_settings_field( 'avif_quality', __( 'AVIF Quality', 'anchor-schema' ), [ $this, 'field_avif_quality' ], self::PAGE_SLUG, 'ao_nextgen' );

        /* -- Section: Server Info ----------------------------------- */
        add_settings_section( 'ao_server', __( 'Server Capabilities', 'anchor-schema' ), [ $this, 'render_server_info' ], self::PAGE_SLUG );
    }

    public function sanitize( $input ) {
        $out = [];

        $out['mode'] = in_array( $input['mode'] ?? '', [ 'smart', 'lossless', 'custom' ], true )
            ? $input['mode'] : 'smart';

        $out['quality']      = max( 1, min( 100, (int) ( $input['quality'] ?? 82 ) ) );
        $out['png_quality']  = max( 0, min( 9, (int) ( $input['png_quality'] ?? 9 ) ) );
        $out['strip_metadata'] = ! empty( $input['strip_metadata'] );
        $out['max_width']    = max( 0, (int) ( $input['max_width'] ?? 2560 ) );
        $out['backup_originals'] = ! empty( $input['backup_originals'] );

        $out['webp_enabled'] = ! empty( $input['webp_enabled'] );
        $out['webp_quality'] = max( 1, min( 100, (int) ( $input['webp_quality'] ?? 80 ) ) );
        $out['avif_enabled'] = ! empty( $input['avif_enabled'] );
        $out['avif_quality'] = max( 1, min( 100, (int) ( $input['avif_quality'] ?? 65 ) ) );

        return $out;
    }

    /* ────────────────────────────────────────────────────────
       Field Renderers
       ──────────────────────────────────────────────────────── */

    public function field_mode() {
        $val = self::get( 'mode' );
        $modes = [
            'smart'    => __( 'Smart — auto-balanced quality (recommended)', 'anchor-schema' ),
            'lossless' => __( 'Lossless — recompress without quality loss', 'anchor-schema' ),
            'custom'   => __( 'Custom — manual quality settings', 'anchor-schema' ),
        ];
        echo '<select name="' . self::OPTION_KEY . '[mode]" id="ao-mode">';
        foreach ( $modes as $k => $label ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $val, $k, false ), esc_html( $label ) );
        }
        echo '</select>';
    }

    public function field_quality() {
        $val = self::get( 'quality' );
        printf(
            '<div class="anchor-optimize-quality-slider" data-target="ao-quality">'
            . '<input type="range" min="1" max="100" value="%1$d" id="ao-quality-range" />'
            . '<input type="number" name="%2$s[quality]" id="ao-quality" min="1" max="100" value="%1$d" class="small-text" />'
            . '</div>'
            . '<p class="description">%3$s</p>',
            (int) $val,
            self::OPTION_KEY,
            esc_html__( 'Used for JPEG and WebP source compression. Smart mode uses 82.', 'anchor-schema' )
        );
    }

    public function field_png_quality() {
        $val = self::get( 'png_quality' );
        printf(
            '<div class="anchor-optimize-quality-slider" data-target="ao-png-quality">'
            . '<input type="range" min="0" max="9" value="%1$d" id="ao-png-quality-range" />'
            . '<input type="number" name="%2$s[png_quality]" id="ao-png-quality" min="0" max="9" value="%1$d" class="small-text" />'
            . '</div>'
            . '<p class="description">%3$s</p>',
            (int) $val,
            self::OPTION_KEY,
            esc_html__( 'PNG compression level (0 = none, 9 = max). Lossless.', 'anchor-schema' )
        );
    }

    public function field_strip_metadata() {
        $val = self::get( 'strip_metadata' );
        printf(
            '<label><input type="checkbox" name="%s[strip_metadata]" value="1" %s /> %s</label>'
            . '<p class="description">%s</p>',
            self::OPTION_KEY,
            checked( $val, true, false ),
            esc_html__( 'Remove EXIF, IPTC, and XMP data from images.', 'anchor-schema' ),
            esc_html__( 'Reduces file size and removes camera/GPS metadata.', 'anchor-schema' )
        );
    }

    public function field_max_width() {
        $val = self::get( 'max_width' );
        printf(
            '<input type="number" name="%s[max_width]" value="%d" min="0" max="10000" class="small-text" />'
            . '<p class="description">%s</p>',
            self::OPTION_KEY,
            (int) $val,
            esc_html__( 'Downscale the original if wider than this value. 0 = no resize. WordPress default is 2560.', 'anchor-schema' )
        );
    }

    public function field_backup_originals() {
        $val = self::get( 'backup_originals' );
        printf(
            '<label><input type="checkbox" name="%s[backup_originals]" value="1" %s /> %s</label>'
            . '<p class="description">%s</p>',
            self::OPTION_KEY,
            checked( $val, true, false ),
            esc_html__( 'Keep a copy of the original full-size image before compression.', 'anchor-schema' ),
            esc_html__( 'Stored in wp-content/uploads/anchor-optimize-backups/.', 'anchor-schema' )
        );
    }

    public function field_webp_enabled() {
        $val = self::get( 'webp_enabled' );
        $supported = Anchor_Optimize_WebP_Converter::supports_webp();
        printf(
            '<label><input type="checkbox" name="%s[webp_enabled]" value="1" %s %s id="ao-webp-enabled" /> %s</label>',
            self::OPTION_KEY,
            checked( $val, true, false ),
            $supported ? '' : 'disabled',
            $supported
                ? esc_html__( 'Generate a .webp copy for every image and thumbnail.', 'anchor-schema' )
                : esc_html__( 'WebP not supported on this server.', 'anchor-schema' )
        );
    }

    public function field_webp_quality() {
        $val = self::get( 'webp_quality' );
        printf(
            '<div class="anchor-optimize-quality-slider" data-target="ao-webp-quality">'
            . '<input type="range" min="1" max="100" value="%1$d" id="ao-webp-quality-range" />'
            . '<input type="number" name="%2$s[webp_quality]" id="ao-webp-quality" min="1" max="100" value="%1$d" class="small-text" />'
            . '</div>',
            (int) $val,
            self::OPTION_KEY
        );
    }

    public function field_avif_enabled() {
        $val = self::get( 'avif_enabled' );
        $supported = Anchor_Optimize_WebP_Converter::supports_avif();
        printf(
            '<label><input type="checkbox" name="%s[avif_enabled]" value="1" %s %s id="ao-avif-enabled" /> %s</label>',
            self::OPTION_KEY,
            checked( $val, true, false ),
            $supported ? '' : 'disabled',
            $supported
                ? esc_html__( 'Generate a .avif copy for every image and thumbnail.', 'anchor-schema' )
                : esc_html__( 'AVIF not supported on this server (requires Imagick with AVIF delegate or PHP 8.1+ GD).', 'anchor-schema' )
        );
    }

    public function field_avif_quality() {
        $val = self::get( 'avif_quality' );
        printf(
            '<div class="anchor-optimize-quality-slider" data-target="ao-avif-quality">'
            . '<input type="range" min="1" max="100" value="%1$d" id="ao-avif-quality-range" />'
            . '<input type="number" name="%2$s[avif_quality]" id="ao-avif-quality" min="1" max="100" value="%1$d" class="small-text" />'
            . '</div>',
            (int) $val,
            self::OPTION_KEY
        );
    }

    /* ────────────────────────────────────────────────────────
       Server Info Panel
       ──────────────────────────────────────────────────────── */

    public function render_server_info() {
        $engine  = Anchor_Optimize_Optimizer::detect_engine();
        $webp    = Anchor_Optimize_WebP_Converter::supports_webp();
        $avif    = Anchor_Optimize_WebP_Converter::supports_avif();
        $cwebp   = function_exists( 'exec' );

        $yes = '<span style="color:#46b450;">&#10003;</span>';
        $no  = '<span style="color:#dc3232;">&#10007;</span>';

        echo '<div class="anchor-optimize-server-info">';
        echo '<table class="widefat fixed" style="max-width:500px;">';
        echo '<tbody>';
        printf( '<tr><td>%s</td><td><strong>%s</strong></td></tr>',
            esc_html__( 'Image Engine', 'anchor-schema' ),
            'none' === $engine ? esc_html__( 'None detected', 'anchor-schema' ) : strtoupper( $engine )
        );
        printf( '<tr><td>%s</td><td>%s %s</td></tr>',
            esc_html__( 'Imagick Extension', 'anchor-schema' ),
            extension_loaded( 'imagick' ) ? $yes : $no,
            extension_loaded( 'imagick' ) ? esc_html__( 'Loaded', 'anchor-schema' ) : esc_html__( 'Not available', 'anchor-schema' )
        );
        printf( '<tr><td>%s</td><td>%s %s</td></tr>',
            esc_html__( 'GD Library', 'anchor-schema' ),
            function_exists( 'imagecreatefromjpeg' ) ? $yes : $no,
            function_exists( 'imagecreatefromjpeg' ) ? esc_html__( 'Available', 'anchor-schema' ) : esc_html__( 'Not available', 'anchor-schema' )
        );
        printf( '<tr><td>%s</td><td>%s</td></tr>',
            esc_html__( 'WebP Support', 'anchor-schema' ),
            $webp ? $yes . ' ' . esc_html__( 'Supported', 'anchor-schema' ) : $no . ' ' . esc_html__( 'Not supported', 'anchor-schema' )
        );
        printf( '<tr><td>%s</td><td>%s</td></tr>',
            esc_html__( 'AVIF Support', 'anchor-schema' ),
            $avif ? $yes . ' ' . esc_html__( 'Supported', 'anchor-schema' ) : $no . ' ' . esc_html__( 'Not supported', 'anchor-schema' )
        );
        printf( '<tr><td>%s</td><td>%s</td></tr>',
            'PHP',
            esc_html( PHP_VERSION )
        );
        echo '</tbody></table>';
        echo '</div>';
    }

    /* ────────────────────────────────────────────────────────
       Settings Page Render
       ──────────────────────────────────────────────────────── */

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Anchor Optimize', 'anchor-schema' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'anchor_optimize_group' );
                do_settings_sections( self::PAGE_SLUG );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
