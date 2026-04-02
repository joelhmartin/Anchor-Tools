<?php
/**
 * Anchor Translate — Language registry and cookie persistence.
 *
 * Simplified for client-side translation. No URL prefix detection,
 * no REQUEST_URI manipulation, no redirects. Cookie is the only
 * server-side signal (used to set the active class on the switcher).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Language {

    const COOKIE_NAME = 'anchor_translate_lang';

    private $options;
    private $languages;

    public function __construct( array $options ) {
        $this->options = $options;
    }

    /* ------------------------------------------------------------------ */
    /*  Getters                                                           */
    /* ------------------------------------------------------------------ */

    /** Current language from cookie, or default. */
    public function get_current() {
        if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            $c = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
            if ( $this->is_enabled( $c ) ) {
                return $c;
            }
        }
        return $this->get_default();
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
}
