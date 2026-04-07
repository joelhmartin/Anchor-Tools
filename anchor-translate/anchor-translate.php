<?php
/**
 * Anchor Tools module: Anchor Translate.
 *
 * Server-rendered translation via Google Cloud Translation API
 * using language-specific URLs.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Module {

    const OPTION_KEY       = 'anchor_translate_options';
    const PAGE_SLUG        = 'anchor-translate';
    const REWRITE_VERSION  = '3';
    const REWRITE_OPTION   = 'anchor_translate_rewrite_version';

    private $language;
    private $provider;
    private $translator;
    private $opts = [];

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

    public function __construct() {
        $this->load_includes();

        $this->opts      = $this->get_options();
        $this->language  = new Anchor_Translate_Language( $this->opts );
        $this->provider  = new Anchor_Translate_Google_Provider();
        $this->translator = new Anchor_Translate_Response_Translator( $this->provider, $this->language, $this->opts );

        add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 100 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_post_anchor_translate_test_api', [ $this, 'handle_api_test' ] );
        add_action( 'update_option_' . self::OPTION_KEY, [ $this, 'purge_page_caches' ] );

        add_action( 'init', [ $this, 'register_rewrite_rules' ] );
        add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 20 );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
        add_filter( 'redirect_canonical', [ $this, 'disable_canonical_redirect' ], 10, 2 );

        if ( ! is_admin() ) {
            $this->boot_frontend();
        }

        $this->maybe_cleanup_legacy();
    }

    private function load_includes() {
        $dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-translate/includes/';
        require_once $dir . 'class-google-provider.php';
        require_once $dir . 'class-language.php';
        require_once $dir . 'class-shortcode.php';
        require_once $dir . 'class-response-translator.php';
    }

    private function boot_frontend() {
        $shortcode = new Anchor_Translate_Shortcode( $this->language );
        $shortcode->init();

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
        add_action( 'template_redirect', [ $this, 'handle_translated_request' ], 0 );
    }

    public function enqueue_frontend() {
        $base = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-translate/assets/';
        $dir  = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-translate/assets/';

        $css_file = $dir . 'anchor-translate.css';
        $css_ver  = file_exists( $css_file ) ? filemtime( $css_file ) : time();

        wp_enqueue_style(
            'anchor-translate',
            $base . 'anchor-translate.css',
            [],
            $css_ver
        );
    }

    public function register_rewrite_rules() {
        $codes = $this->language->get_non_default_codes();
        if ( empty( $codes ) ) {
            return;
        }

        $pattern = implode( '|', array_map( static function( $code ) {
            return preg_quote( $code, '#' );
        }, $codes ) );

        add_rewrite_rule(
            '^(' . $pattern . ')(?:/(.*))?/?$',
            'index.php?' . Anchor_Translate_Language::QUERY_VAR_LANG . '=$matches[1]&' . Anchor_Translate_Language::QUERY_VAR_PATH . '=$matches[2]',
            'top'
        );
    }

    public function maybe_flush_rewrite_rules() {
        if ( get_option( self::REWRITE_OPTION ) === self::REWRITE_VERSION ) {
            return;
        }

        flush_rewrite_rules( false );
        update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
    }

    public function purge_page_caches() {
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }

        if ( class_exists( 'Developer_Tools_Settings' ) && function_exists( 'developer_tools_clear_cache' ) ) {
            developer_tools_clear_cache();
        }
        if ( class_exists( '\Jeedo\KinstaMUPlugins\Cache\CachePurge' ) ) {
            wp_remote_get( home_url( '/?kinsta-clear-cache-all' ), [ 'blocking' => false ] );
        }

        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
        }
        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
        }
        if ( class_exists( 'LiteSpeed\Purge' ) ) {
            do_action( 'litespeed_purge_all' );
        }

        do_action( 'anchor_cache_purged' );
    }

    public function register_query_vars( $vars ) {
        $vars[] = Anchor_Translate_Language::QUERY_VAR_LANG;
        $vars[] = Anchor_Translate_Language::QUERY_VAR_PATH;
        return $vars;
    }

    public function disable_canonical_redirect( $redirect, $requested ) {
        if ( ! $this->language->is_default() ) {
            return false;
        }
        return $redirect;
    }

    public function handle_translated_request() {
        if ( $this->language->is_default() ) {
            return;
        }
        if ( $this->is_internal_fetch() ) {
            return;
        }
        if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
        if ( $method !== 'GET' ) {
            return;
        }

        $source_url = $this->language->get_source_url_for_current_request();
        $response   = wp_remote_get( $source_url, [
            'timeout'     => 45,
            'redirection' => 5,
            'headers'     => [
                'X-Anchor-Translate-Internal' => '1',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return;
        }

        $status       = (int) wp_remote_retrieve_response_code( $response );
        $content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
        $body         = (string) wp_remote_retrieve_body( $response );

        if ( $body === '' ) {
            status_header( $status ?: 200 );
            exit;
        }

        if ( stripos( $content_type, 'text/html' ) === false && stripos( $body, '<html' ) === false ) {
            status_header( $status ?: 200 );
            if ( $content_type ) {
                header( 'Content-Type: ' . $content_type );
            }
            echo $body;
            exit;
        }

        $translated = $this->translator->translate_html( $body, $this->language->get_current(), $source_url );

        status_header( $status ?: 200 );
        header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
        echo $translated;
        exit;
    }

    private function is_internal_fetch() {
        return ! empty( $_SERVER['HTTP_X_ANCHOR_TRANSLATE_INTERNAL'] );
    }

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

        add_settings_section(
            'at_languages',
            __( 'Languages', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Define the default language and enabled translated languages. The default language uses normal URLs, translated languages use language-prefixed URLs.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'default_language', __( 'Default Language Code', 'anchor-schema' ), [ $this, 'field_text' ], self::PAGE_SLUG, 'at_languages', [ 'key' => 'default_language', 'placeholder' => 'en', 'class' => 'small-text' ] );
        add_settings_field( 'languages', __( 'Enabled Languages', 'anchor-schema' ), [ $this, 'field_textarea' ], self::PAGE_SLUG, 'at_languages', [ 'key' => 'languages', 'placeholder' => "en:English\nes:Español", 'rows' => 6 ] );

        add_settings_section(
            'at_api',
            __( 'Google Cloud Translation API', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Anchor Translate fetches the original page server-side, translates the final HTML, and serves a translated version from a language-specific URL.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'api_status', __( 'Shared API Key', 'anchor-schema' ), [ $this, 'field_api_status' ], self::PAGE_SLUG, 'at_api' );

        add_settings_section(
            'at_exclusions',
            __( 'Exclusions', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Control what gets translated. One entry per line. These elements will be skipped by the server-side translator.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'exclude_selectors', __( 'Exclude CSS Selectors', 'anchor-schema' ), [ $this, 'field_textarea' ], self::PAGE_SLUG, 'at_exclusions', [ 'key' => 'exclude_selectors', 'placeholder' => ".my-brand-widget\n#untranslated-section", 'rows' => 4, 'description' => 'CSS class (.classname) or ID (#id) selectors. Content inside matching elements will not be translated.' ] );
        add_settings_field( 'preserve_phrases', __( 'Preserve Phrases', 'anchor-schema' ), [ $this, 'field_textarea' ], self::PAGE_SLUG, 'at_exclusions', [ 'key' => 'preserve_phrases', 'placeholder' => "Acme Corp\nPowered by Widget™", 'rows' => 4, 'description' => 'Branded terms or phrases that should never be translated.' ] );
    }

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

    public function sanitize_options( $input ) {
        return [
            'default_language'  => sanitize_text_field( $input['default_language'] ?? 'en' ),
            'languages'         => sanitize_textarea_field( $input['languages'] ?? "en:English\nes:Español" ),
            'exclude_selectors' => sanitize_textarea_field( $input['exclude_selectors'] ?? '' ),
            'preserve_phrases'  => sanitize_textarea_field( $input['preserve_phrases'] ?? '' ),
        ];
    }

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

        $enabled = array_keys( $this->language->get_enabled() );
        $default = $this->language->get_default();
        $target  = $default === 'es' ? 'en' : 'es';

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
                __( 'Translation API is working. Sample response for %1$s: %2$s', 'anchor-schema' ),
                $target,
                $result
            );
        }

        $url = add_query_arg(
            [
                'page'                      => 'anchor-schema',
                'tab'                       => 'translate',
                'anchor_translate_api_test' => $status,
                'anchor_translate_message'  => $message,
            ],
            admin_url( 'options-general.php' )
        );

        wp_safe_redirect( $url );
        exit;
    }

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
}
