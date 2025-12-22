<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface Anchor_Reviews_Provider {
    public function get_source_key();
    public function fetch( $place_id, $api_key );
}

class Anchor_Reviews_Google_Provider implements Anchor_Reviews_Provider {
    public function get_source_key() {
        return 'google';
    }

    public function fetch( $place_id, $api_key ) {
        if ( ! $api_key || ! $place_id ) {
            return new WP_Error( 'anchor_reviews_missing', 'Missing Google API key or Place ID.' );
        }

        $endpoint = add_query_arg(
            [
                'place_id' => $place_id,
                'fields'   => 'rating,user_ratings_total,reviews,url,name',
                'key'      => $api_key,
            ],
            'https://maps.googleapis.com/maps/api/place/details/json'
        );

        $resp = wp_remote_get( $endpoint, [ 'timeout' => 20 ] );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 ) {
            return new WP_Error( 'anchor_reviews_http', 'Google API HTTP ' . $code );
        }

        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $data['result'] ) || ( $data['status'] ?? '' ) !== 'OK' ) {
            $status = $data['status'] ?? 'unknown';
            return new WP_Error( 'anchor_reviews_api', 'Google API error: ' . $status );
        }

        $result = $data['result'];
        $reviews = [];
        if ( ! empty( $result['reviews'] ) && is_array( $result['reviews'] ) ) {
            foreach ( $result['reviews'] as $r ) {
                $timestamp = isset( $r['time'] ) ? (int) $r['time'] : 0;
                $reviews[] = [
                    'id'         => md5( ($r['author_name'] ?? '') . '|' . $timestamp . '|' . ($r['text'] ?? '') ),
                    'author'     => $r['author_name'] ?? '',
                    'rating'     => isset( $r['rating'] ) ? (int) $r['rating'] : 0,
                    'text'       => $r['text'] ?? '',
                    'date'       => $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '',
                    'author_url' => $r['author_url'] ?? '',
                    'avatar'     => $r['profile_photo_url'] ?? '',
                ];
            }
        }

        return [
            'source'        => 'google',
            'place_id'      => (string) $place_id,
            'business_name' => $result['name'] ?? '',
            'business_url'  => $result['url'] ?? '',
            'rating'        => isset( $result['rating'] ) ? (float) $result['rating'] : 0,
            'rating_count'  => isset( $result['user_ratings_total'] ) ? (int) $result['user_ratings_total'] : 0,
            'reviews'       => $reviews,
            'last_updated'  => gmdate( 'c' ),
            'is_compliant'  => true,
        ];
    }
}
