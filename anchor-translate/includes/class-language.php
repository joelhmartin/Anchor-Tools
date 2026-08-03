<?php
/**
 * Anchor Translate — language registry and URL localization helpers.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Language {

    const QUERY_VAR_LANG = 'anchor_translate_lang';
    const QUERY_VAR_PATH = 'anchor_translate_path';

    /**
     * Query parameters that carry attribution only and never change what the
     * page renders. These are stripped from the fetched source URL, the render
     * cache key, the canonical, the hreflang set and rewritten internal links.
     *
     * Why this matters: every distinct permutation used to mint its own render
     * cache entry AND its own self-canonicalising indexable URL. A single ad
     * campaign could therefore bill hundreds of full-page translations of one
     * unchanged page and spray duplicate /es/?utm_* URLs into the index.
     * Params NOT on this list (s, p, page, paged, filters…) are preserved,
     * because those do change the rendered output and must stay cache-distinct.
     */
    const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_id', 'utm_source_platform', 'utm_creative_format', 'utm_marketing_tactic',
        'gclid', 'gclsrc', 'gbraid', 'wbraid', 'dclid', 'gad_source',
        'fbclid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id', 'igshid', 'epik',
        'mc_cid', 'mc_eid', 'yclid', '_ga', '_gl', 'vero_id', 'oly_enc_id', 'oly_anon_id',
        'hsa_acc', 'hsa_cam', 'hsa_grp', 'hsa_ad', 'hsa_src', 'hsa_tgt',
        'hsa_kw', 'hsa_mt', 'hsa_net', 'hsa_ver',
    ];

    private $options;
    private $languages;

    /**
     * Remove attribution-only parameters from a URL, preserving every other
     * query arg (and their order) plus any fragment.
     */
    public static function strip_tracking_params( $url ) {
        $url = (string) $url;
        if ( $url === '' || strpos( $url, '?' ) === false ) {
            return $url;
        }

        $parts = wp_parse_url( $url );
        if ( empty( $parts['query'] ) ) {
            return $url;
        }

        parse_str( $parts['query'], $params );
        foreach ( self::TRACKING_PARAMS as $drop ) {
            unset( $params[ $drop ] );
        }

        $base = strtok( $url, '?' );
        $clean = empty( $params ) ? $base : $base . '?' . http_build_query( $params );

        if ( ! empty( $parts['fragment'] ) ) {
            $clean .= '#' . $parts['fragment'];
        }

        return $clean;
    }

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

        if ( get_query_var( self::QUERY_VAR_LANG ) ) {
            return '';
        }

        $request = '';
        if ( isset( $GLOBALS['wp']->request ) ) {
            $request = (string) $GLOBALS['wp']->request;
        }

        return trim( $request, '/' );
    }

    public function get_source_url_for_current_request() {
        $path = $this->get_request_path();
        if ( $this->is_front_page_path( $path ) ) {
            $url = home_url( '/' );
        } else {
            $url = home_url( $path ? '/' . $path . '/' : '/' );
        }
        $qs   = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';

        if ( $qs ) {
            parse_str( $qs, $params );
            unset( $params[ self::QUERY_VAR_LANG ], $params[ self::QUERY_VAR_PATH ] );
            foreach ( self::TRACKING_PARAMS as $drop ) {
                unset( $params[ $drop ] );
            }
            if ( ! empty( $params ) ) {
                $url = add_query_arg( $params, $url );
            }
        }

        return $url;
    }

    /**
     * Drop attribution-only args from a raw query string, returning '' when
     * nothing survives (so callers can omit the '?' entirely).
     */
    public static function filter_query_string( $query ) {
        $query = (string) $query;
        if ( $query === '' ) {
            return '';
        }

        parse_str( $query, $params );
        foreach ( self::TRACKING_PARAMS as $drop ) {
            unset( $params[ $drop ] );
        }
        unset( $params[ self::QUERY_VAR_LANG ], $params[ self::QUERY_VAR_PATH ] );

        return empty( $params ) ? '' : http_build_query( $params );
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

        if ( $this->is_front_page_url( $url ) ) {
            $localized = $this->is_default( $lang )
                ? $home
                : trailingslashit( $home . $lang );

            $parts = wp_parse_url( $url );
            $query = self::filter_query_string( $parts['query'] ?? '' );
            if ( $query !== '' ) {
                $localized .= '?' . $query;
            }
            if ( ! empty( $parts['fragment'] ) ) {
                $localized .= '#' . $parts['fragment'];
            }

            return $localized;
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

    private function is_front_page_url( $url ) {
        $normalized = $this->normalize_internal_path( $url );
        if ( $normalized === '' ) {
            return true;
        }

        $front_path = $this->get_front_page_path();
        return $front_path !== '' && $normalized === $front_path;
    }

    private function is_front_page_path( $path ) {
        $normalized = trim( (string) $path, '/' );
        if ( $normalized === '' ) {
            return true;
        }

        $front_path = $this->get_front_page_path();
        return $front_path !== '' && $normalized === $front_path;
    }

    private function get_front_page_path() {
        $page_on_front = (int) get_option( 'page_on_front' );
        if ( ! $page_on_front ) {
            return '';
        }

        $permalink = get_permalink( $page_on_front );
        if ( ! $permalink ) {
            return '';
        }

        return $this->normalize_internal_path( $permalink );
    }

    private function normalize_internal_path( $url ) {
        $parts = wp_parse_url( $url );
        $path  = isset( $parts['path'] ) ? ltrim( (string) $parts['path'], '/' ) : '';
        $home_parts = wp_parse_url( home_url( '/' ) );
        $home_path  = isset( $home_parts['path'] ) ? ltrim( (string) $home_parts['path'], '/' ) : '';

        if ( $home_path && strpos( $path, $home_path ) === 0 ) {
            $path = ltrim( substr( $path, strlen( $home_path ) ), '/' );
        }

        return trim( $path, '/' );
    }
}
