<?php
/**
 * Anchor Translate — Language registry, URL-based detection, and routing.
 *
 * Language is determined by URL path prefix (/es/, /fr/, etc.).
 * The prefix is stripped from REQUEST_URI so WordPress resolves the correct page.
 * This approach works with full-page caches (Kinsta, Cloudflare, etc.) because
 * each language variant lives at its own URL.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Language {

    const COOKIE_NAME = 'anchor_translate_lang';

    private $options;
    private $current;
    private $languages;

    public function __construct( array $options ) {
        $this->options = $options;
    }

    /* ------------------------------------------------------------------ */
    /*  Bootstrap                                                         */
    /* ------------------------------------------------------------------ */

    public function init() {
        $this->detect_and_strip_prefix();
        add_action( 'init', [ $this, 'handle_switch' ], 1 );
        add_filter( 'redirect_canonical', [ $this, 'fix_canonical_redirect' ], 10, 2 );
    }

    /**
     * Detect language from URL path prefix and strip it from REQUEST_URI
     * so WordPress resolves the correct page.
     *
     * Runs at plugins_loaded time (before WordPress parses the request).
     */
    private function detect_and_strip_prefix() {
        if ( $this->is_non_frontend() ) return;

        $uri       = $_SERVER['REQUEST_URI'] ?? '';
        $path      = parse_url( $uri, PHP_URL_PATH ) ?: '';
        $home_path = parse_url( home_url(), PHP_URL_PATH ) ?: '';
        $default   = $this->get_default();

        foreach ( $this->get_enabled() as $code => $label ) {
            if ( $code === $default ) continue;
            $lang_prefix = $home_path . '/' . $code;
            if ( preg_match( '#^' . preg_quote( $lang_prefix, '#' ) . '(?=/|$)#', $path ) ) {
                $this->current = $code;
                $this->set_cookie( $code );
                // Strip the /xx segment, keep home_path.
                $_SERVER['REQUEST_URI'] = preg_replace(
                    '#^(' . preg_quote( $home_path, '#' ) . ')/' . preg_quote( $code, '#' ) . '(?=/|$)#',
                    '$1',
                    $uri,
                    1
                );
                if ( $_SERVER['REQUEST_URI'] === '' ) {
                    $_SERVER['REQUEST_URI'] = '/';
                }
                break;
            }
        }
    }

    /**
     * Handle ?lang=xx — redirect to /xx/ prefixed URL (backward compat).
     */
    public function handle_switch() {
        if ( $this->is_non_frontend() ) return;

        $lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
        if ( $lang === '' || ! $this->is_enabled( $lang ) ) return;

        $clean = remove_query_arg( 'lang' );
        $path  = parse_url( $clean, PHP_URL_PATH ) ?: '/';
        $query = parse_url( $clean, PHP_URL_QUERY );

        if ( $lang === $this->get_default() ) {
            $this->clear_cookie();
            $url = $path;
        } else {
            $this->set_cookie( $lang );
            $home_path = parse_url( home_url(), PHP_URL_PATH ) ?: '';
            if ( $home_path !== '' && strpos( $path, $home_path ) === 0 ) {
                $relative = substr( $path, strlen( $home_path ) ) ?: '/';
                $url = $home_path . '/' . $lang . $relative;
            } else {
                $url = '/' . $lang . $path;
            }
        }

        if ( $query ) $url .= '?' . $query;

        wp_redirect( $url, 302 );
        exit;
    }

    /**
     * When WordPress issues a canonical redirect (e.g. trailing-slash fix),
     * re-add the language prefix so the browser stays on the correct URL.
     */
    public function fix_canonical_redirect( $redirect_url, $requested_url ) {
        $prefix = $this->get_prefix();
        if ( $prefix === '' ) return $redirect_url;

        $parsed = parse_url( $redirect_url );
        $path   = $parsed['path'] ?? '/';

        $home_path = parse_url( home_url(), PHP_URL_PATH ) ?: '';
        $full_prefix = $home_path . $prefix;

        // Already has language prefix.
        if ( strpos( $path, $full_prefix . '/' ) === 0 || $path === $full_prefix ) {
            return $redirect_url;
        }

        // Insert language segment after home_path.
        if ( $home_path !== '' && strpos( $path, $home_path ) === 0 ) {
            $relative = substr( $path, strlen( $home_path ) ) ?: '/';
            $new_path = $home_path . $prefix . $relative;
        } else {
            $new_path = $prefix . $path;
        }

        return ( isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' . ( $parsed['host'] ?? '' ) : '' )
             . $new_path
             . ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' )
             . ( isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '' );
    }

    /* ------------------------------------------------------------------ */
    /*  Getters                                                           */
    /* ------------------------------------------------------------------ */

    /** Current active language code. */
    public function get_current() {
        if ( $this->current ) return $this->current;

        // Query param (before redirect fires).
        if ( isset( $_GET['lang'] ) ) {
            $q = sanitize_text_field( wp_unslash( $_GET['lang'] ) );
            if ( $this->is_enabled( $q ) ) {
                $this->current = $q;
                return $q;
            }
        }

        // Default — no language prefix in URL.
        $this->current = $this->get_default();
        return $this->current;
    }

    /** Default (source) language code. */
    public function get_default() {
        return $this->options['default_language'] ?: 'en';
    }

    /**
     * Enabled languages as [ 'en' => 'English', 'es' => 'Español', … ].
     */
    public function get_enabled() {
        if ( $this->languages !== null ) return $this->languages;

        $this->languages = [];
        $raw = $this->options['languages'] ?? "en:English\nes:Español";
        $lines = preg_split( '/\r?\n/', trim( $raw ) );

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            $parts = explode( ':', $line, 2 );
            $code  = sanitize_text_field( trim( $parts[0] ) );
            $label = isset( $parts[1] ) ? trim( $parts[1] ) : strtoupper( $code );
            if ( $code !== '' ) {
                $this->languages[ $code ] = $label;
            }
        }

        if ( empty( $this->languages ) ) {
            $this->languages = [ 'en' => 'English' ];
        }

        return $this->languages;
    }

    /** Whether a language code is in the enabled list. */
    public function is_enabled( $code ) {
        return array_key_exists( $code, $this->get_enabled() );
    }

    /** Whether the current language is the default. */
    public function is_default() {
        return $this->get_current() === $this->get_default();
    }

    /**
     * URL path segment for a language code (e.g. '/es'), or '' for default.
     */
    public function get_prefix( $code = null ) {
        $code = $code ?: $this->get_current();
        return ( $code === $this->get_default() ) ? '' : '/' . $code;
    }

    /**
     * Build a URL to switch to the given language on the current page.
     *
     * Returns ?lang=xx format. This is intentional — the link rewriter
     * only touches path-based href values, so ?lang=xx links survive
     * untouched. handle_switch() redirects ?lang=xx to the correct
     * /xx/path/ URL on click.
     */
    public function get_switch_url( $code ) {
        return '?lang=' . urlencode( $code );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                           */
    /* ------------------------------------------------------------------ */

    private function set_cookie( $lang ) {
        if ( headers_sent() ) return;
        setcookie( self::COOKIE_NAME, $lang, [
            'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly'  => false,
            'samesite' => 'Lax',
        ] );
        $_COOKIE[ self::COOKIE_NAME ] = $lang;
    }

    private function clear_cookie() {
        if ( headers_sent() ) return;
        setcookie( self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly'  => false,
            'samesite' => 'Lax',
        ] );
        unset( $_COOKIE[ self::COOKIE_NAME ] );
    }

    private function is_non_frontend() {
        if ( is_admin() )      return true;
        if ( wp_doing_ajax() ) return true;
        if ( wp_doing_cron() ) return true;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return true;
        return false;
    }
}
