<?php
/**
 * Anchor Tools module: Anchor Translate.
 *
 * Client-side translation via Google Cloud Translation API.
 * Language preference is persisted with a cookie while cached HTML
 * remains identical for every visitor.
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

        $this->provider = new Anchor_Translate_Google_Provider();

        // Admin.
        add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 100 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_post_anchor_translate_test_api', [ $this, 'handle_api_test' ] );

        // AJAX translation endpoint.
        add_action( 'wp_ajax_anchor_translate_translate', [ $this, 'ajax_translate' ] );
        add_action( 'wp_ajax_nopriv_anchor_translate_translate', [ $this, 'ajax_translate' ] );

        // Frontend.
        if ( ! is_admin() ) {
            $this->boot_frontend();
        }

        // One-time cleanup of old server-side translation cache.
        $this->maybe_cleanup_legacy();
    }

    private function load_includes() {
        $dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-translate/includes/';
        require_once $dir . 'class-google-provider.php';
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
    }

    /** @var Anchor_Translate_Language|null */
    private $language;
    /** @var array */
    private $opts = [];
    /** @var Anchor_Translate_Google_Provider */
    private $provider;

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
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'anchor_translate_nonce' ),
            'hasApiKey'        => $this->provider->has_api_key(),
            'messages'         => [
                'missingApiKey'   => __( 'Google Cloud API key is not configured for Anchor Translate.', 'anchor-schema' ),
                'translateFailed' => __( 'Translation failed. Check the Google Cloud Translation API key and configuration.', 'anchor-schema' ),
            ],
        ] );
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

        add_settings_section(
            'at_api',
            __( 'Google Cloud Translation API', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Anchor Translate uses the shared Google Cloud API key from the General tab and sends translation requests through your WordPress site instead of the legacy Google widget.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'api_status', __( 'Shared API Key', 'anchor-schema' ), [ $this, 'field_api_status' ], self::PAGE_SLUG, 'at_api' );

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

    public function field_api_status() {
        $has_key = $this->provider->has_api_key();
        $status  = $has_key
            ? __( 'Configured in Anchor Tools > General.', 'anchor-schema' )
            : __( 'Missing. Add a Google Cloud API key in Anchor Tools > General.', 'anchor-schema' );

        echo '<p><strong>' . esc_html( $status ) . '</strong></p>';

        if ( $has_key ) {
            $url = wp_nonce_url(
                add_query_arg(
                    [
                        'action' => 'anchor_translate_test_api',
                    ],
                    admin_url( 'admin-post.php' )
                ),
                'anchor_translate_test_api'
            );
            echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Test Translation API', 'anchor-schema' ) . '</a></p>';
        }

        echo '<p class="description">' . esc_html__( 'The API key must allow server-side requests and have Cloud Translation API enabled in Google Cloud.', 'anchor-schema' ) . '</p>';
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
        $api_test = isset( $_GET['anchor_translate_api_test'] ) ? sanitize_text_field( $_GET['anchor_translate_api_test'] ) : '';
        $message  = isset( $_GET['anchor_translate_message'] ) ? sanitize_text_field( wp_unslash( $_GET['anchor_translate_message'] ) ) : '';

        if ( $api_test ) {
            $class = $api_test === 'success' ? 'notice notice-success' : 'notice notice-error';
            echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
        }

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

    public function handle_api_test() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'anchor-schema' ) );
        }

        check_admin_referer( 'anchor_translate_test_api' );

        $opts      = $this->get_options();
        $language  = new Anchor_Translate_Language( $opts );
        $enabled   = array_keys( $language->get_enabled() );
        $default   = $language->get_default();
        $target    = $default === 'es' ? 'en' : 'es';

        foreach ( $enabled as $code ) {
            if ( $code !== $default ) {
                $target = $code;
                break;
            }
        }

        $result = $this->provider->test_connection( $default, $target );
        if ( is_wp_error( $result ) ) {
            $status  = 'error';
            $message = $result->get_error_message();
        } else {
            $status  = 'success';
            $message = sprintf(
                /* translators: 1: language code, 2: translated sample text */
                __( 'Translation API is working. Sample response for %1$s: %2$s', 'anchor-schema' ),
                $target,
                $result
            );
        }

        $url = add_query_arg(
            [
                'page'                       => 'anchor-schema',
                'tab'                        => 'translate',
                'anchor_translate_api_test'  => $status,
                'anchor_translate_message'   => $message,
            ],
            admin_url( 'options-general.php' )
        );

        wp_safe_redirect( $url );
        exit;
    }

    public function ajax_translate() {
        check_ajax_referer( 'anchor_translate_nonce', 'nonce' );

        $opts     = $this->get_options();
        $language = new Anchor_Translate_Language( $opts );
        $source   = sanitize_text_field( wp_unslash( $_POST['source'] ?? $language->get_default() ) );
        $target   = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
        $texts    = isset( $_POST['texts'] ) ? (array) wp_unslash( $_POST['texts'] ) : [];

        if ( ! $language->is_enabled( $target ) ) {
            wp_send_json_error( [ 'message' => __( 'Requested language is not enabled.', 'anchor-schema' ) ], 400 );
        }
        if ( $source && ! $language->is_enabled( $source ) ) {
            wp_send_json_error( [ 'message' => __( 'Source language is not enabled.', 'anchor-schema' ) ], 400 );
        }

        $texts = array_values( array_filter( array_map( 'strval', $texts ), static function( $text ) {
            return $text !== '';
        } ) );

        if ( empty( $texts ) ) {
            wp_send_json_success( [ 'translations' => [] ] );
        }
        if ( count( $texts ) > 100 ) {
            wp_send_json_error( [ 'message' => __( 'Too many text fragments requested at once.', 'anchor-schema' ) ], 400 );
        }

        $total_chars = array_sum( array_map( 'mb_strlen', $texts ) );
        if ( $total_chars > 20000 ) {
            wp_send_json_error( [ 'message' => __( 'Requested translation payload is too large.', 'anchor-schema' ) ], 400 );
        }

        $result = $this->provider->translate_texts( $texts, $target, $source );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 502 );
        }

        wp_send_json_success( [ 'translations' => $result ] );
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
