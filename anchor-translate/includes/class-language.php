<?php
/**
 * Anchor Translate — Language registry, detection, and cookie persistence.
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
        add_action( 'init', [ $this, 'handle_switch' ], 1 );
    }

    /**
     * If ?lang=xx is present and valid, set cookie and redirect to clean URL.
     */
    public function handle_switch() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

        $lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
        if ( $lang === '' || ! $this->is_enabled( $lang ) ) return;

        $this->set_cookie( $lang );

        $clean = remove_query_arg( 'lang' );
        wp_redirect( $clean, 302 );
        exit;
    }

    /* ------------------------------------------------------------------ */
    /*  Getters                                                           */
    /* ------------------------------------------------------------------ */

    /** Current active language code. */
    public function get_current() {
        if ( $this->current ) return $this->current;

        // 1. Query param (before redirect fires)
        if ( isset( $_GET['lang'] ) ) {
            $q = sanitize_text_field( wp_unslash( $_GET['lang'] ) );
            if ( $this->is_enabled( $q ) ) {
                $this->current = $q;
                return $q;
            }
        }

        // 2. Cookie
        if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            $c = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
            if ( $this->is_enabled( $c ) ) {
                $this->current = $c;
                return $c;
            }
        }

        // 3. Default
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

    /** Build a URL to switch to the given language on the current page. */
    public function get_switch_url( $code ) {
        return add_query_arg( 'lang', $code );
    }

    /* ------------------------------------------------------------------ */
    /*  Cookie                                                            */
    /* ------------------------------------------------------------------ */

    private function set_cookie( $lang ) {
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
}
