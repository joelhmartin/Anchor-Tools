<?php
/**
 * Anchor Translate — Google Cloud Translation API provider.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Google_Provider {

    const CACHE_PREFIX = 'anchor_translate_api_';

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

    public function translate_texts( array $texts, $target, $source = '' ) {
        $api_key = $this->get_api_key();
        $target  = sanitize_text_field( (string) $target );
        $source  = sanitize_text_field( (string) $source );
        $texts   = array_values( array_filter( array_map( [ $this, 'normalize_text' ], $texts ), 'strlen' ) );

        if ( ! $api_key ) {
            return new WP_Error( 'anchor_translate_missing_key', 'Google Cloud API key is missing.' );
        }
        if ( ! $target ) {
            return new WP_Error( 'anchor_translate_missing_target', 'Target language is required.' );
        }
        if ( empty( $texts ) ) {
            return [];
        }

        $cache_key = self::CACHE_PREFIX . md5( wp_json_encode( [ $texts, $target, $source ] ) );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $endpoint = add_query_arg(
            [ 'key' => $api_key ],
            'https://translation.googleapis.com/language/translate/v2'
        );

        $body = [
            'q'      => $texts,
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
            ] );
        }

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

        set_transient( $cache_key, $translated, DAY_IN_SECONDS );
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
