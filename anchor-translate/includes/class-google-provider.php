<?php
/**
 * Anchor Translate — Google Cloud Translation API provider.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Google_Provider {

    const CACHE_PREFIX = 'anchor_translate_api_';

    /**
     * Per-phrase cache lifetime. A source sentence's Spanish rendering does not
     * expire — only an edit to that sentence invalidates it, and an edit mints a
     * different key anyway. The old DAY_IN_SECONDS TTL was the single largest
     * cost driver: it guaranteed a full re-translation of every page every 24h
     * no matter how static the site was, which is exactly the flat ~700k
     * characters/day the billing showed.
     */
    const PHRASE_TTL = MONTH_IN_SECONDS * 6;

    /** Bump to invalidate every cached phrase (e.g. if decoding changes). */
    const PHRASE_VERSION = '1';

    /** Strings per HTTP request. Google's v2 limit is 128 segments. */
    const BATCH_SIZE = 100;

    public function get_api_key() {
        if ( ! class_exists( 'Anchor_Schema_Admin' ) ) {
            return '';
        }

        $opts = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
        return trim( $opts['google_api_key'] ?? '' );
    }

    public function has_api_key() {
        return $this->get_api_key() !== '';
    }

    /**
     * Translate a batch, caching EACH PHRASE independently.
     *
     * The previous implementation hashed the whole ordered batch into a single
     * transient. That meant one changed sentence — or merely a shifted chunk
     * boundary caused by inserting a node earlier in the page — invalidated all
     * 40 strings and re-billed every one of them. Caching per phrase means an
     * edit only ever costs the phrases that actually changed, and identical
     * boilerplate (nav, footer, CTAs) is paid for once site-wide instead of
     * once per page.
     *
     * Returns translations index-aligned with $texts. Entries that normalise to
     * empty are passed through untouched rather than dropped — the old code
     * filtered + reindexed them away, which silently shifted every later
     * translation onto the wrong DOM node.
     */
    public function translate_texts( array $texts, $target, $source = '' ) {
        $api_key = $this->get_api_key();
        $target  = sanitize_text_field( (string) $target );
        $source  = sanitize_text_field( (string) $source );

        if ( ! $api_key ) {
            return new WP_Error( 'anchor_translate_missing_key', 'Google Cloud API key is missing.' );
        }
        if ( ! $target ) {
            return new WP_Error( 'anchor_translate_missing_target', 'Target language is required.' );
        }
        if ( empty( $texts ) ) {
            return [];
        }

        $out     = [];
        $pending = [];

        foreach ( $texts as $index => $text ) {
            $text = (string) $text;
            $out[ $index ] = $text;

            if ( $this->normalize_text( $text ) === '' ) {
                continue; // nothing translatable — keep the original in place.
            }

            $cached = get_transient( $this->phrase_key( $text, $target, $source ) );
            if ( is_string( $cached ) ) {
                $out[ $index ] = $cached;
                continue;
            }

            $pending[ $index ] = $text;
        }

        if ( empty( $pending ) ) {
            return $out;
        }

        // Only unique strings need to cross the wire; duplicates on the page
        // (repeated CTAs, aria-labels) collapse to one billed unit.
        $unique = array_values( array_unique( $pending ) );

        foreach ( array_chunk( $unique, self::BATCH_SIZE ) as $chunk ) {
            $rows = $this->request_translations( $chunk, $target, $source, $api_key );
            if ( is_wp_error( $rows ) ) {
                return $rows;
            }

            foreach ( $chunk as $offset => $original ) {
                if ( ! isset( $rows[ $offset ] ) ) {
                    return new WP_Error( 'anchor_translate_parse', 'Unexpected response from Google Translation API.' );
                }
                set_transient( $this->phrase_key( $original, $target, $source ), $rows[ $offset ], self::PHRASE_TTL );
            }
        }

        // Re-read through the cache so every pending index picks up its result.
        foreach ( $pending as $index => $text ) {
            $cached = get_transient( $this->phrase_key( $text, $target, $source ) );
            if ( is_string( $cached ) ) {
                $out[ $index ] = $cached;
            }
        }

        return $out;
    }

    private function phrase_key( $text, $target, $source ) {
        return self::CACHE_PREFIX . md5( self::PHRASE_VERSION . '|' . $target . '|' . $source . '|' . $text );
    }

    /**
     * One HTTP round trip. Returns a list of decoded strings positionally
     * matching $texts, or WP_Error.
     */
    private function request_translations( array $texts, $target, $source, $api_key ) {
        $endpoint = add_query_arg(
            [ 'key' => $api_key ],
            'https://translation.googleapis.com/language/translate/v2'
        );

        $body = [
            'q'      => array_values( $texts ),
            'target' => $target,
            'format' => 'text',
        ];

        if ( $source ) {
            $body['source'] = $source;
        }

        if ( class_exists( 'Anchor_Schema_Logger' ) ) {
            Anchor_Schema_Logger::log( 'translate:request', [
                'target'     => $target,
                'source'     => $source,
                'text_count' => count( $texts ),
                'characters' => array_sum( array_map( 'mb_strlen', $texts ) ),
            ] );
        }

        Anchor_Translate_Budget::record( array_sum( array_map( 'mb_strlen', $texts ) ) );

        $response = wp_remote_post( $endpoint, [
            'timeout' => 25,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );
        $data   = json_decode( $raw, true );

        if ( $status < 200 || $status >= 300 ) {
            $message = $data['error']['message'] ?? ( 'Google Translation API HTTP ' . $status );
            return new WP_Error( 'anchor_translate_http', $message );
        }

        $rows = $data['data']['translations'] ?? [];
        if ( ! is_array( $rows ) || count( $rows ) !== count( $texts ) ) {
            return new WP_Error( 'anchor_translate_parse', 'Unexpected response from Google Translation API.' );
        }

        $translated = [];
        foreach ( $rows as $row ) {
            $translated[] = html_entity_decode( (string) ( $row['translatedText'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }

        return $translated;
    }

    public function test_connection( $source, $target ) {
        $result = $this->translate_texts( [ 'Hello world' ], $target, $source );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( empty( $result[0] ) ) {
            return new WP_Error( 'anchor_translate_empty_test', 'Google Translation API returned an empty response.' );
        }

        return $result[0];
    }

    private function normalize_text( $text ) {
        return trim( wp_strip_all_tags( (string) $text, false ) ) === '' ? '' : (string) $text;
    }
}
