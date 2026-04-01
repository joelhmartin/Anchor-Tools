<?php
/**
 * Anchor Tools module: Anchor Translate.
 *
 * Server-side page translation via Google Cloud Translation API.
 * Captures final rendered HTML, translates visible text, and caches
 * the result per page per language. Never modifies post content.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ANCHOR_TRANSLATE_VERSION', '1.0.0' );

class Anchor_Translate_Module {

    const OPTION_KEY = 'anchor_translate_options';
    const PAGE_SLUG  = 'anchor-translate';

    /* ------------------------------------------------------------------ */
    /*  Defaults                                                          */
    /* ------------------------------------------------------------------ */

    private function defaults() {
        return [
            'api_key'          => '',
            'default_language' => 'en',
            'languages'        => "en:English\nes:Español",
            'cache_enabled'    => '1',
            'noindex'          => '1',
            'exclude_selectors' => '',
            'preserve_phrases'  => '',
            'exclude_urls'      => '',
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
        add_action( 'admin_init', [ $this, 'handle_cache_clear' ] );
        add_action( 'wp_ajax_anchor_translate_precache', [ $this, 'ajax_precache' ] );

        // Frontend pipeline.
        $this->boot_frontend();
    }

    private function load_includes() {
        $dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-translate/includes/';
        require_once $dir . 'class-language.php';
        require_once $dir . 'class-shortcode.php';
        require_once $dir . 'class-cache.php';
        require_once $dir . 'class-google-provider.php';
        require_once $dir . 'class-dom-parser.php';
        require_once $dir . 'class-buffer.php';
        require_once $dir . 'class-hooks.php';
    }

    private function boot_frontend() {
        $opts = $this->get_options();

        // Language system (runs on every request for cookie/redirect).
        $language = new Anchor_Translate_Language( $opts );
        $language->init();

        // Shortcode (always available).
        $shortcode = new Anchor_Translate_Shortcode( $language );
        $shortcode->init();

        // Cache + hooks (always wired so invalidation works on admin saves too).
        $cache = new Anchor_Translate_Cache();
        $hooks = new Anchor_Translate_Hooks( $cache, $language, $opts );
        $hooks->init();

        // Translation buffer (frontend only, non-default language only).
        $api_key = $this->resolve_api_key( $opts );
        if ( ! is_admin() && $api_key !== '' ) {
            $exclude_selectors = $this->parse_lines( $opts['exclude_selectors'] );
            $preserve_phrases  = $this->parse_lines( $opts['preserve_phrases'] );

            $parser   = new Anchor_Translate_DOM_Parser( $exclude_selectors, $preserve_phrases );
            $provider = new Anchor_Translate_Google_Provider( $api_key );
            $buffer   = new Anchor_Translate_Buffer( $language, $parser, $provider, $cache, $opts );
            $buffer->init();
        }

        if ( ! is_admin() ) {
            $this->language = $language;
            // Language-persistence redirect: tiny <head> script that checks the
            // cookie and redirects to the prefixed URL before content renders.
            // This is the PRIMARY mechanism for language persistence across navigation.
            add_action( 'wp_head', [ $this, 'render_lang_redirect_script' ], 1 );
            // Belt-and-suspenders: also rewrite links on click (for smooth UX
            // without redirect flash on pages that already have the interceptor).
            add_action( 'wp_footer', [ $this, 'render_link_interceptor' ], 999 );
        }
    }

    /** @var Anchor_Translate_Language|null */
    private $language;

    /**
     * Inject a cookie-based redirect script in <head> on EVERY page.
     *
     * If the user previously chose Spanish (cookie = "es") and they land on
     * an English URL (/about/), this redirects to /es/about/ instantly —
     * before any content renders. This is the bulletproof mechanism that
     * makes language sticky regardless of how links are generated.
     *
     * Runs on English AND translated pages so the cookie check is universal.
     */
    public function render_lang_redirect_script() {
        if ( ! $this->language ) return;

        $default   = $this->language->get_default();
        $enabled   = $this->language->get_enabled();
        $home_path = wp_parse_url( home_url(), PHP_URL_PATH ) ?: '';

        // Build a JS array of valid non-default language codes.
        $codes = [];
        foreach ( $enabled as $code => $label ) {
            if ( $code !== $default ) $codes[] = $code;
        }
        if ( empty( $codes ) ) return;

        $codes_js = wp_json_encode( $codes );
        ?>
        <script data-no-translate="true">
        (function(){
            var m=document.cookie.match(/(?:^|;\s*)anchor_translate_lang=([^;]+)/);
            if(!m)return;
            var lang=m[1],codes=<?php echo $codes_js; ?>,hp='<?php echo esc_js( $home_path ); ?>';
            if(codes.indexOf(lang)===-1)return;
            var p=window.location.pathname,pfx=hp+'/'+lang;
            if(p.indexOf(pfx+'/')===0||p===pfx||p===pfx+'/')return;
            window.location.replace(pfx+(hp&&p.indexOf(hp)===0?p.substr(hp.length):p)+window.location.search+window.location.hash);
        })();
        </script>
        <?php
    }

    /**
     * Inject a tiny JS that intercepts clicks on internal links and adds
     * the language path prefix when missing. Runs on every frontend page
     * so navigation always stays in the chosen language.
     */
    public function render_link_interceptor() {
        if ( ! $this->language || $this->language->is_default() ) return;

        $prefix    = $this->language->get_prefix();
        $home_url  = home_url();
        $home_path = wp_parse_url( $home_url, PHP_URL_PATH ) ?: '';
        ?>
        <script data-no-translate="true">
        (function(){
            var p='<?php echo esc_js( $prefix ); ?>';
            var h='<?php echo esc_js( $home_url ); ?>';
            var hp='<?php echo esc_js( $home_path ); ?>';
            if(!p)return;
            document.addEventListener('click',function(e){
                var a=e.target.closest('a[href]');
                if(!a)return;
                var hr=a.getAttribute('href');
                if(!hr||hr.charAt(0)==='#')return;
                if(/^(javascript|mailto|tel|data):/i.test(hr))return;
                if(/\.(js|css|png|jpe?g|gif|svg|pdf|zip|mp[34]|webp|ico|woff2?|ttf|eot)(\?|#|$)/i.test(hr))return;
                if(/^\/(wp-admin|wp-content|wp-includes|wp-json|feed|xmlrpc)/i.test(hr))return;
                var abs=hr.indexOf(h)===0;
                var rel=!abs&&hr.charAt(0)==='/';
                if(!abs&&!rel)return;
                var full=hp+p;
                if(abs){var after=hr.substr(h.length)||'/';if(after.indexOf(p+'/')===0||after===p)return;a.setAttribute('href',h+p+after)}
                else if(rel){if(hp&&hr.indexOf(hp)===0){var r=hr.substr(hp.length)||'/';if(r.indexOf(p+'/')===0||r===p)return;a.setAttribute('href',hp+p+r)}else{if(hr.indexOf(p+'/')===0||hr===p)return;a.setAttribute('href',p+hr)}}
            },true);
        })();
        </script>
        <?php
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

        /* --- API section --- */
        add_settings_section(
            'at_api',
            __( 'Google Cloud Translation', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Configure the Google Cloud Translation API. Requires a project with the Cloud Translation API enabled.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'api_key', __( 'API Key', 'anchor-schema' ), [ $this, 'field_password' ], self::PAGE_SLUG, 'at_api', [ 'key' => 'api_key' ] );

        /* --- Languages section --- */
        add_settings_section(
            'at_languages',
            __( 'Languages', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Define the default language and all enabled languages. One per line in code:Label format (e.g. es:Español).', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'default_language', __( 'Default Language Code', 'anchor-schema' ), [ $this, 'field_text' ], self::PAGE_SLUG, 'at_languages', [ 'key' => 'default_language', 'placeholder' => 'en', 'class' => 'small-text' ] );
        add_settings_field( 'languages', __( 'Enabled Languages', 'anchor-schema' ), [ $this, 'field_textarea' ], self::PAGE_SLUG, 'at_languages', [ 'key' => 'languages', 'placeholder' => "en:English\nes:Español", 'rows' => 6 ] );

        /* --- Cache section --- */
        add_settings_section(
            'at_cache',
            __( 'Caching', 'anchor-schema' ),
            null,
            self::PAGE_SLUG
        );

        add_settings_field( 'cache_enabled', __( 'Enable Cache', 'anchor-schema' ), [ $this, 'field_checkbox' ], self::PAGE_SLUG, 'at_cache', [ 'key' => 'cache_enabled' ] );

        /* --- SEO section --- */
        add_settings_section(
            'at_seo',
            __( 'SEO', 'anchor-schema' ),
            null,
            self::PAGE_SLUG
        );

        add_settings_field( 'noindex', __( 'Noindex Translated Pages', 'anchor-schema' ), [ $this, 'field_checkbox' ], self::PAGE_SLUG, 'at_seo', [ 'key' => 'noindex', 'description' => 'Adds <code>&lt;meta name="robots" content="noindex, nofollow"&gt;</code> on non-default language pages.' ] );

        /* --- Exclusions section --- */
        add_settings_section(
            'at_exclusions',
            __( 'Exclusions', 'anchor-schema' ),
            fn() => print '<p>' . esc_html__( 'Control what gets translated. One entry per line.', 'anchor-schema' ) . '</p>',
            self::PAGE_SLUG
        );

        add_settings_field( 'exclude_selectors', __( 'Exclude CSS Selectors', 'anchor-schema' ), [ $this, 'field_textarea' ], self::PAGE_SLUG, 'at_exclusions', [ 'key' => 'exclude_selectors', 'placeholder' => ".my-brand-widget\n#untranslated-section", 'rows' => 4, 'description' => 'CSS class (.classname) or ID (#id) selectors. Content inside matching elements will not be translated.' ] );
        add_settings_field( 'preserve_phrases', __( 'Preserve Phrases', 'anchor-schema' ), [ $this, 'field_textarea' ], self::PAGE_SLUG, 'at_exclusions', [ 'key' => 'preserve_phrases', 'placeholder' => "Acme Corp\nPowered by Widget™", 'rows' => 4, 'description' => 'Branded terms or phrases that should never be translated.' ] );
        add_settings_field( 'exclude_urls', __( 'Exclude URL Patterns', 'anchor-schema' ), [ $this, 'field_textarea' ], self::PAGE_SLUG, 'at_exclusions', [ 'key' => 'exclude_urls', 'placeholder' => "/wp-admin/*\n/checkout/*", 'rows' => 4, 'description' => 'URL path patterns (supports * wildcards). Pages matching these patterns will not be translated.' ] );
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

    public function field_password( $args ) {
        $opts = $this->get_options();
        $val  = $opts[ $args['key'] ] ?? '';
        printf(
            '<input type="password" name="%s[%s]" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            esc_attr( $val )
        );
        // Show fallback status for the API key field.
        if ( $args['key'] === 'api_key' ) {
            $global = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
            $has_global = trim( $global['google_api_key'] ?? '' ) !== '';
            if ( $val === '' && $has_global ) {
                echo '<p class="description">' . esc_html__( 'Using the shared Google API key from the General tab. Override here if needed.', 'anchor-schema' ) . '</p>';
            } elseif ( $val === '' && ! $has_global ) {
                echo '<p class="description" style="color:#d63638;">' . esc_html__( 'No API key set. Enter one here or in the General tab under Google Cloud API Key.', 'anchor-schema' ) . '</p>';
            }
        }
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

    public function field_checkbox( $args ) {
        $opts = $this->get_options();
        printf(
            '<input type="hidden" name="%1$s[%2$s]" value="0" />'
            . '<input type="checkbox" name="%1$s[%2$s]" value="1" %3$s />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            checked( $opts[ $args['key'] ] ?? '1', '1', false )
        );
        if ( ! empty( $args['description'] ) ) {
            echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Sanitize                                                          */
    /* ------------------------------------------------------------------ */

    public function sanitize_options( $input ) {
        $defs = $this->defaults();
        $out  = [];

        $out['api_key']          = sanitize_text_field( $input['api_key'] ?? '' );
        $out['default_language'] = sanitize_text_field( $input['default_language'] ?? $defs['default_language'] );
        $out['languages']        = sanitize_textarea_field( $input['languages'] ?? $defs['languages'] );
        $out['cache_enabled']    = ! empty( $input['cache_enabled'] ) ? '1' : '0';
        $out['noindex']          = ! empty( $input['noindex'] ) ? '1' : '0';
        $out['exclude_selectors'] = sanitize_textarea_field( $input['exclude_selectors'] ?? '' );
        $out['preserve_phrases']  = sanitize_textarea_field( $input['preserve_phrases'] ?? '' );
        $out['exclude_urls']      = sanitize_textarea_field( $input['exclude_urls'] ?? '' );

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Tab content                                                       */
    /* ------------------------------------------------------------------ */

    public function render_tab_content() {
        $opts = $this->get_options();
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'anchor_translate_group' );
            do_settings_sections( self::PAGE_SLUG );
            submit_button();
            ?>
        </form>

        <hr />
        <h2><?php esc_html_e( 'Cache Management', 'anchor-schema' ); ?></h2>
        <?php
        $cache = new Anchor_Translate_Cache();
        $gv    = $cache->get_global_version();
        ?>
        <p><?php printf( esc_html__( 'Global version: %d', 'anchor-schema' ), $gv ); ?></p>
        <form method="post" action="">
            <?php wp_nonce_field( 'anchor_translate_clear_cache', '_at_clear_nonce' ); ?>
            <input type="hidden" name="at_clear_cache" value="1" />
            <?php submit_button( __( 'Clear All Translation Caches', 'anchor-schema' ), 'secondary', 'submit', false ); ?>
        </form>

        <hr />
        <h2><?php esc_html_e( 'Bulk Pre-Cache', 'anchor-schema' ); ?></h2>
        <p><?php esc_html_e( 'Pre-translate all published pages so visitors never wait for a first-time translation.', 'anchor-schema' ); ?></p>
        <button type="button" id="at-precache-btn" class="button button-secondary"><?php esc_html_e( 'Pre-Cache All Pages', 'anchor-schema' ); ?></button>
        <span id="at-precache-status" style="margin-left:12px;"></span>
        <div id="at-precache-log" style="max-height:200px;overflow-y:auto;margin-top:8px;font-family:monospace;font-size:12px;"></div>
        <script>
        (function(){
            var btn = document.getElementById('at-precache-btn');
            var status = document.getElementById('at-precache-status');
            var log = document.getElementById('at-precache-log');
            if (!btn) return;

            btn.addEventListener('click', function() {
                btn.disabled = true;
                status.textContent = 'Starting…';
                log.innerHTML = '';
                runNext(0);
            });

            function runNext(offset) {
                var fd = new FormData();
                fd.append('action', 'anchor_translate_precache');
                fd.append('_wpnonce', '<?php echo wp_create_nonce( 'anchor_translate_precache' ); ?>');
                fd.append('offset', offset);

                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.success) {
                            status.textContent = 'Error: ' + (data.data || 'Unknown');
                            btn.disabled = false;
                            return;
                        }
                        var d = data.data;
                        d.log.forEach(function(msg) {
                            log.innerHTML += msg + '<br>';
                            log.scrollTop = log.scrollHeight;
                        });
                        status.textContent = d.done + ' / ' + d.total + ' pages';
                        if (d.done < d.total) {
                            runNext(d.done);
                        } else {
                            status.textContent += ' — Complete!';
                            btn.disabled = false;
                        }
                    })
                    .catch(function(err) {
                        status.textContent = 'Request failed: ' + err;
                        btn.disabled = false;
                    });
            }
        })();
        </script>

        <hr />
        <h2><?php esc_html_e( 'Shortcode', 'anchor-schema' ); ?></h2>
        <p><?php esc_html_e( 'Place this shortcode anywhere to display the language switcher:', 'anchor-schema' ); ?></p>
        <code>[anchor_translate_switcher]</code>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Cache clear handler                                               */
    /* ------------------------------------------------------------------ */

    public function handle_cache_clear() {
        if ( empty( $_POST['at_clear_cache'] ) ) return;
        if ( ! isset( $_POST['_at_clear_nonce'] ) || ! wp_verify_nonce( $_POST['_at_clear_nonce'], 'anchor_translate_clear_cache' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $cache = new Anchor_Translate_Cache();
        $cache->flush_all();
        $cache->bump_global_version();

        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Translation cache cleared.', 'anchor-schema' ) . '</p></div>';
        } );
    }

    /* ------------------------------------------------------------------ */
    /*  Bulk pre-cache via AJAX                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Process a batch of pages: fetch their translated URLs server-to-server
     * so the translation pipeline runs and caches each one.
     */
    public function ajax_precache() {
        check_ajax_referer( 'anchor_translate_precache' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $offset = (int) ( $_POST['offset'] ?? 0 );
        $batch  = 5; // pages per AJAX request

        $post_ids = get_posts( [
            'post_type'      => [ 'page', 'post' ],
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ] );

        $total   = count( $post_ids );
        $chunk   = array_slice( $post_ids, $offset, $batch );
        $log     = [];
        $opts    = $this->get_options();
        $default = $opts['default_language'] ?: 'en';

        // Build list of non-default languages.
        $lang_obj  = new Anchor_Translate_Language( $opts );
        $languages = $lang_obj->get_enabled();
        unset( $languages[ $default ] );

        if ( empty( $languages ) ) {
            wp_send_json_error( 'No non-default languages enabled.' );
        }

        foreach ( $chunk as $pid ) {
            $url = get_permalink( $pid );
            if ( ! $url ) continue;
            $title = get_the_title( $pid );

            foreach ( $languages as $lang_code => $lang_label ) {
                // Build the translated URL: insert /es/ after home path.
                $home_url  = home_url();
                $home_path = wp_parse_url( $home_url, PHP_URL_PATH ) ?: '';

                if ( $home_path !== '' && strpos( $url, $home_url ) === 0 ) {
                    $after      = substr( $url, strlen( $home_url ) ) ?: '/';
                    $target_url = $home_url . '/' . $lang_code . $after;
                } else {
                    $parsed     = wp_parse_url( $url );
                    $path       = $parsed['path'] ?? '/';
                    $target_url = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' )
                                . '/' . $lang_code . $path;
                }

                // Fire a non-blocking GET to trigger the translation pipeline.
                $response = wp_remote_get( $target_url, [
                    'timeout'   => 60,
                    'sslverify' => false,
                    'cookies'   => [],
                ] );

                if ( is_wp_error( $response ) ) {
                    $log[] = '✗ ' . esc_html( $title ) . ' [' . $lang_code . '] — ' . esc_html( $response->get_error_message() );
                } else {
                    $code = wp_remote_retrieve_response_code( $response );
                    $log[] = '✓ ' . esc_html( $title ) . ' [' . $lang_code . '] — HTTP ' . $code;
                }
            }
        }

        wp_send_json_success( [
            'total' => $total,
            'done'  => min( $total, $offset + $batch ),
            'log'   => $log,
        ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Use the module's own API key if set, otherwise fall back to the shared
     * Google API key from the General tab (same GCP project, different APIs).
     */
    private function resolve_api_key( array $opts = [] ) {
        $key = trim( $opts['api_key'] ?? '' );
        if ( $key !== '' ) return $key;

        $global = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
        return trim( $global['google_api_key'] ?? '' );
    }

    private function parse_lines( $value ) {
        if ( ! is_string( $value ) || $value === '' ) return [];
        return array_filter( array_map( 'trim', preg_split( '/\r?\n/', $value ) ) );
    }
}
