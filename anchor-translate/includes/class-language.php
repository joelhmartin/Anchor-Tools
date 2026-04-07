<?php
/**
 * Anchor Translate — language registry and URL localization helpers.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Language {

    const QUERY_VAR_LANG = 'anchor_translate_lang';
    const QUERY_VAR_PATH = 'anchor_translate_path';

    private $options;
    private $languages;

    public function __construct( array $options ) {
        $this->options = $options;
    }

    public function get_current() {
        $code = get_query_var( self::QUERY_VAR_LANG );
        if ( $code && $this->is_enabled( $code ) ) {
            return $code;
        }

        return $this->get_default();
    }

    public function get_default() {
        return $this->options['default_language'] ?: 'en';
    }

    public function get_enabled() {
        if ( $this->languages !== null ) return $this->languages;

        $this->languages = [];
        $raw   = $this->options['languages'] ?? "en:English\nes:Español";
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

    public function is_enabled( $code ) {
        return array_key_exists( (string) $code, $this->get_enabled() );
    }

    public function is_default( $code = null ) {
        $code = $code ?: $this->get_current();
        return $code === $this->get_default();
    }

    public function get_request_path() {
        $path = get_query_var( self::QUERY_VAR_PATH );
        if ( $path !== '' && $path !== null ) {
            return trim( (string) $path, '/' );
        }

        $request = '';
        if ( isset( $GLOBALS['wp']->request ) ) {
            $request = (string) $GLOBALS['wp']->request;
        }

        return trim( $request, '/' );
    }

    public function get_source_url_for_current_request() {
        $path = $this->get_request_path();
        $url  = home_url( $path ? '/' . $path . '/' : '/' );
        $qs   = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';

        if ( $qs ) {
            parse_str( $qs, $params );
            unset( $params[ self::QUERY_VAR_LANG ], $params[ self::QUERY_VAR_PATH ] );
            if ( ! empty( $params ) ) {
                $url = add_query_arg( $params, $url );
            }
        }

        return $url;
    }

    public function get_current_url( $lang = null ) {
        return $this->localize_url( $this->get_source_url_for_current_request(), $lang ?: $this->get_current() );
    }

    public function localize_url( $url, $lang ) {
        $lang = sanitize_text_field( (string) $lang );
        if ( ! $this->is_enabled( $lang ) ) {
            return $url;
        }

        $home = trailingslashit( home_url( '/' ) );
        if ( ! $this->is_internal_url( $url ) ) {
            return $url;
        }

        $parts = wp_parse_url( $url );
        $path  = isset( $parts['path'] ) ? ltrim( (string) $parts['path'], '/' ) : '';
        $home_parts = wp_parse_url( $home );
        $home_path  = isset( $home_parts['path'] ) ? ltrim( (string) $home_parts['path'], '/' ) : '';

        if ( $home_path && strpos( $path, $home_path ) === 0 ) {
            $path = ltrim( substr( $path, strlen( $home_path ) ), '/' );
        }

        $segments = $path === '' ? [] : explode( '/', $path );
        if ( ! empty( $segments ) && $this->is_enabled( $segments[0] ) ) {
            array_shift( $segments );
        }

        if ( ! $this->is_default( $lang ) ) {
            array_unshift( $segments, $lang );
        }

        $localized = $home . implode( '/', array_filter( $segments, 'strlen' ) );
        $localized = trailingslashit( $localized );

        if ( ! empty( $parts['query'] ) ) {
            $localized .= '?' . $parts['query'];
        }
        if ( ! empty( $parts['fragment'] ) ) {
            $localized .= '#' . $parts['fragment'];
        }

        return $localized;
    }

    public function get_non_default_codes() {
        return array_values( array_filter( array_keys( $this->get_enabled() ), function( $code ) {
            return ! $this->is_default( $code );
        } ) );
    }

    private function is_internal_url( $url ) {
        if ( strpos( $url, '#' ) === 0 ) return false;
        if ( preg_match( '#^(mailto|tel|javascript):#i', $url ) ) return false;
        if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) return true;

        $target = wp_parse_url( $url );
        $home   = wp_parse_url( home_url( '/' ) );

        if ( empty( $target['host'] ) ) return true;
        if ( empty( $home['host'] ) ) return false;

        return strtolower( $target['host'] ) === strtolower( $home['host'] );
    }
}
