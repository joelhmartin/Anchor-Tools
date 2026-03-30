<?php
/**
 * Anchor Translate — Google Cloud Translation v2 provider.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Google_Provider {

    private $api_key;
    private $batch_size = 100;
    private $max_chars  = 5000;

    public function __construct( $api_key ) {
        $this->api_key = $api_key;
    }

    /**
     * Translate an array of strings.
     *
     * @param string[] $strings  Text strings to translate.
     * @param string   $source   Source language code (e.g. 'en').
     * @param string   $target   Target language code (e.g. 'es').
     * @return string[] Translated strings in the same order. Originals on failure.
     */
    public function translate( array $strings, $source, $target ) {
        if ( empty( $strings ) || ! $this->api_key || $source === $target ) {
            return $strings;
        }

        $batches = $this->create_batches( $strings );
        $results = [];

        foreach ( $batches as $batch ) {
            $translated = $this->translate_batch( $batch, $source, $target );
            foreach ( $translated as $t ) {
                $results[] = $t;
            }
        }

        return $results;
    }

    /* ------------------------------------------------------------------ */
    /*  Batching                                                          */
    /* ------------------------------------------------------------------ */

    private function create_batches( array $strings ) {
        $batches       = [];
        $current_batch = [];
        $current_chars = 0;

        foreach ( $strings as $str ) {
            $len = mb_strlen( $str, 'UTF-8' );

            if ( ! empty( $current_batch )
                && ( count( $current_batch ) >= $this->batch_size || $current_chars + $len > $this->max_chars )
            ) {
                $batches[]     = $current_batch;
                $current_batch = [];
                $current_chars = 0;
            }

            $current_batch[] = $str;
            $current_chars  += $len;
        }

        if ( ! empty( $current_batch ) ) {
            $batches[] = $current_batch;
        }

        return $batches;
    }

    /* ------------------------------------------------------------------ */
    /*  API call                                                          */
    /* ------------------------------------------------------------------ */

    private function translate_batch( array $strings, $source, $target ) {
        $url = add_query_arg( 'key', $this->api_key, 'https://translation.googleapis.com/language/translate/v2' );

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'body'    => wp_json_encode( [
                'q'      => array_values( $strings ),
                'source' => $source,
                'target' => $target,
                'format' => 'text',
            ] ),
            'headers' => [ 'Content-Type' => 'application/json' ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[Anchor Translate] API error: ' . $response->get_error_message() );
            return $strings;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $body['error']['message'] ?? 'HTTP ' . $code;
            error_log( '[Anchor Translate] API error: ' . $msg );
            return $strings;
        }

        $translations = $body['data']['translations'] ?? [];
        $out = [];

        foreach ( $strings as $i => $original ) {
            if ( isset( $translations[ $i ]['translatedText'] ) ) {
                $out[] = html_entity_decode( $translations[ $i ]['translatedText'], ENT_QUOTES, 'UTF-8' );
            } else {
                $out[] = $original;
            }
        }

        return $out;
    }
}
