<?php
/**
 * Anchor Tools module: Anchor Translate.
 *
 * Client-side translation via the Google Translate widget.
 * Language preference persisted with a cookie; same cached HTML
 * served to every visitor — fully compatible with Kinsta / Cloudflare
 * full-page caches.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Module {

    const OPTION_KEY = 'anchor_translate_options';
    const PAGE_SLUG  = 'anchor-translate';

    /* ------------------------------------------------------------------ */
    /*  Defaults                                                          */
    /* ------------------------------------------------------------------ */

    private function defaults() {
        return [
            'default_language'  => 'en',
            'languages'         => "en:English\nes:Español",
            'exclude_selectors' => '',
            'preserve_phrases'  => '',
        ];
    }

    public function get_options() {
        return array_merge( $this->defaults(), (array) get_option( self::OPTION_KEY, [] ) );
    }

    /* ------------------------------------------------------------------ */
    /*  Bootstrap                                                         */
    /* ------------------------------------------------------------------ */

    public function __construct() {
        $this->load_includes();

        // Admin.
        add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 100 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Frontend.
        if ( ! is_admin() ) {
            $this->boot_frontend();
        }

        // One-time cleanup of old server-side translation cache.
        $this->maybe_cleanup_legacy();
    }

    private function load_includes() {
        $dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-translate/includes/';
        require_once $dir . 'class-language.php';
        require_once $dir . 'class-shortcode.php';
    }

    private function boot_frontend() {
        $opts     = $this->get_options();
        $language = new Anchor_Translate_Language( $opts );

        $shortcode = new Anchor_Translate_Shortcode( $language );
        $shortcode->init();

        // Store for enqueue callback.
        $this->language = $language;
        $this->opts     = $opts;

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
        add_action( 'wp_footer',          [ $this, 'render_widget_container' ], 5 );
    }

    /** @var Anchor_Translate_Language|null */
    private $language;
    /** @var array */
    private $opts = [];

    /* ------------------------------------------------------------------ */
    /*  Frontend assets                                                   */
    /* ------------------------------------------------------------------ */

    public function enqueue_frontend() {
        $base = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-translate/assets/';
        $dir  = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-translate/assets/';

        $css_file = $dir . 'anchor-translate.css';
        $js_file  = $dir . 'anchor-translate.js';
        $css_ver  = file_exists( $css_file ) ? filemtime( $css_file ) : time();
        $js_ver   = file_exists( $js_file )  ? filemtime( $js_file )  : time();

        wp_enqueue_style(
            'anchor-translate',
            $base . 'anchor-translate.css',
            [],
            $css_ver
        );

        wp_enqueue_script(
            'anchor-translate',
            $base . 'anchor-translate.js',
            [ 'jquery' ],
            $js_ver,
            true
        );

        wp_localize_script( 'anchor-translate', 'anchorTranslateConfig', [
            'defaultLang'      => $this->language->get_default(),
            'languages'        => $this->language->get_enabled(),
            'languageCodes'    => array_keys( $this->language->get_enabled() ),
            'excludeSelectors' => $this->parse_lines( $this->opts['exclude_selectors'] ?? '' ),
            'preservePhrases'  => $this->parse_lines( $this->opts['preserve_phrases'] ?? '' ),
        ] );

        // Google Translate Element API.
        wp_enqueue_script(
            'google-translate-element',
            '//translate.google.com/translate_a/element.js?cb=anchorTranslateInit',
            [ 'anchor-translate' ],
            null,
            true
        );
    }

    /**
     * Hidden container required by Google Translate Element API.
     */
    public function render_widget_container() {
        echo '<div id="anchor_translate_element" class="skiptranslate notranslate"></div>';
    }

    /* ------------------------------------------------------------------ */
    /*  Admin: settings tab                                               */
    /* ------------------------------------------------------------------ */

    public function register_tab( $tabs ) {
        $tabs['translate'] = [
            'label'    => __( 'Translate', 'anchor-schema' ),
            'callback' => [ $this, 'render_tab_content' ],
        ];
        return $tabs;
    }

    public function register_settings() {
        register_setting( 'anchor_translate_group', self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_options' ],
            'default'           => [],
        ] );

        /* --- Languages section --- */
        add_settings_section(
            'at_languages',
            __( 'Languages', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Define the default language and all enabled languages. One per line in code:Label format (e.g. es:Español).', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'default_language', __( 'Default Language Code', 'anchor-schema' ), [ $this, 'field_text' ], self::PAGE_SLUG, 'at_languages', [ 'key' => 'default_language', 'placeholder' => 'en', 'class' => 'small-text' ] );
        add_settings_field( 'languages', __( 'Enabled Languages', 'anchor-schema' ), [ $this, 'field_textarea' ], self::PAGE_SLUG, 'at_languages', [ 'key' => 'languages', 'placeholder' => "en:English\nes:Español", 'rows' => 6 ] );

        /* --- Exclusions section --- */
        add_settings_section(
            'at_exclusions',
            __( 'Exclusions', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Control what gets translated. One entry per line. These elements will be marked with the "notranslate" class so Google Translate skips them.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'exclude_selectors', __( 'Exclude CSS Selectors', 'anchor-schema' ), [ $this, 'field_textarea' ], self::PAGE_SLUG, 'at_exclusions', [ 'key' => 'exclude_selectors', 'placeholder' => ".my-brand-widget\n#untranslated-section", 'rows' => 4, 'description' => 'CSS class (.classname) or ID (#id) selectors. Content inside matching elements will not be translated.' ] );
        add_settings_field( 'preserve_phrases', __( 'Preserve Phrases', 'anchor-schema' ), [ $this, 'field_textarea' ], self::PAGE_SLUG, 'at_exclusions', [ 'key' => 'preserve_phrases', 'placeholder' => "Acme Corp\nPowered by Widget™", 'rows' => 4, 'description' => 'Branded terms or phrases that should never be translated.' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Field renderers                                                   */
    /* ------------------------------------------------------------------ */

    public function field_text( $args ) {
        $opts  = $this->get_options();
        $class = $args['class'] ?? 'regular-text';
        printf(
            '<input type="text" name="%s[%s]" value="%s" class="%s" placeholder="%s" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            esc_attr( $opts[ $args['key'] ] ?? '' ),
            esc_attr( $class ),
            esc_attr( $args['placeholder'] ?? '' )
        );
    }

    public function field_textarea( $args ) {
        $opts = $this->get_options();
        $rows = $args['rows'] ?? 5;
        printf(
            '<textarea name="%s[%s]" class="large-text" rows="%d" placeholder="%s">%s</textarea>',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            (int) $rows,
            esc_attr( $args['placeholder'] ?? '' ),
            esc_textarea( $opts[ $args['key'] ] ?? '' )
        );
        if ( ! empty( $args['description'] ) ) {
            echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Sanitize                                                          */
    /* ------------------------------------------------------------------ */

    public function sanitize_options( $input ) {
        return [
            'default_language'  => sanitize_text_field( $input['default_language'] ?? 'en' ),
            'languages'         => sanitize_textarea_field( $input['languages'] ?? "en:English\nes:Español" ),
            'exclude_selectors' => sanitize_textarea_field( $input['exclude_selectors'] ?? '' ),
            'preserve_phrases'  => sanitize_textarea_field( $input['preserve_phrases'] ?? '' ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Tab content                                                       */
    /* ------------------------------------------------------------------ */

    public function render_tab_content() {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'anchor_translate_group' );
            do_settings_sections( self::PAGE_SLUG );
            submit_button();
            ?>
        </form>

        <hr />
        <h2><?php esc_html_e( 'Shortcode', 'anchor-schema' ); ?></h2>
        <p><?php esc_html_e( 'Place this shortcode anywhere to display the language switcher:', 'anchor-schema' ); ?></p>
        <code>[anchor_translate_switcher]</code>
        <p class="description"><?php esc_html_e( 'Styles: flags (default), text, both, code, flags_code. Example: [anchor_translate_switcher style="both"]', 'anchor-schema' ); ?></p>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Legacy cleanup                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Remove old server-side translation cache on first load after upgrade.
     */
    private function maybe_cleanup_legacy() {
        if ( get_option( 'anchor_translate_global_version' ) === false ) return;

        global $wpdb;
        $prefix = $wpdb->esc_like( '_transient_at_' );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $prefix . '%',
                '_transient_timeout_' . $wpdb->esc_like( 'at_' ) . '%'
            )
        );
        delete_option( 'anchor_translate_global_version' );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                           */
    /* ------------------------------------------------------------------ */

    private function parse_lines( $value ) {
        if ( ! is_string( $value ) || $value === '' ) return [];
        return array_filter( array_map( 'trim', preg_split( '/\r?\n/', $value ) ) );
    }
}
