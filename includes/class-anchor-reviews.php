<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Reviews_Manager {
    const CRON_HOOK = 'anchor_reviews_refresh_cron';
    const LAST_FETCH_OPTION = 'anchor_reviews_last_fetch';

    private $providers = [];

    public function __construct() {
        add_shortcode( 'anchor_reviews', [ $this, 'shortcode' ] );
        add_shortcode( 'anchor_reviews_google', [ $this, 'shortcode_google' ] );
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );
        add_action( 'init', [ $this, 'register_cron' ] );
        add_action( self::CRON_HOOK, [ $this, 'cron_refresh' ] );
        add_action( 'admin_post_anchor_reviews_refresh', [ $this, 'handle_manual_refresh' ] );
        add_action( 'update_option_' . Anchor_Schema_Admin::OPTION_KEY, [ $this, 'reschedule_cron' ], 10, 2 );

        $this->register_provider( new Anchor_Reviews_Google_Provider() );
    }

    private function register_provider( $provider ) {
        $this->providers[ $provider->get_source_key() ] = $provider;
    }

    private function get_settings() {
        return get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
    }

    private function get_cache_hours() {
        $opts = $this->get_settings();
        $hours = isset( $opts['reviews_cache_hours'] ) ? (int) $opts['reviews_cache_hours'] : 24;
        return max( 1, min( $hours, 168 ) );
    }

    private function get_place_id() {
        $opts = $this->get_settings();
        return trim( $opts['reviews_google_place_id'] ?? '' );
    }

    private function get_api_key() {
        $opts = $this->get_settings();
        return trim( $opts['google_api_key'] ?? '' );
    }

    public function add_cron_schedule( $schedules ) {
        $interval = $this->get_cache_hours() * HOUR_IN_SECONDS;
        $schedules['anchor_reviews_interval'] = [
            'interval' => max( HOUR_IN_SECONDS, $interval ),
            'display'  => 'Anchor Reviews Refresh',
        ];
        return $schedules;
    }

    public function register_cron() {
        if ( ! $this->get_place_id() ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'anchor_reviews_interval', self::CRON_HOOK );
        }
    }

    public function reschedule_cron( $old, $new ) {
        $old_place = trim( $old['reviews_google_place_id'] ?? '' );
        $new_place = trim( $new['reviews_google_place_id'] ?? '' );
        $old_hours = isset( $old['reviews_cache_hours'] ) ? (int) $old['reviews_cache_hours'] : 24;
        $new_hours = isset( $new['reviews_cache_hours'] ) ? (int) $new['reviews_cache_hours'] : 24;

        if ( $old_place === $new_place && $old_hours === $new_hours ) {
            return;
        }
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
        if ( $new_place ) {
            wp_schedule_event( time() + 60, 'anchor_reviews_interval', self::CRON_HOOK );
        }
    }

    public function cron_refresh() {
        $place_id = $this->get_place_id();
        if ( ! $place_id ) {
            return;
        }
        $provider = $this->providers['google'] ?? null;
        if ( ! $provider ) {
            return;
        }
        $api_key = $this->get_api_key();
        $data = $provider->fetch( $place_id, $api_key );
        if ( is_wp_error( $data ) ) {
            return;
        }
        self::store_cache( 'google', $place_id, $data, $this->get_cache_hours() );
    }

    public static function cache_key( $source, $place_id ) {
        $safe = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $place_id );
        return 'anchor_reviews_' . $source . '_' . $safe;
    }

    public static function store_cache( $source, $place_id, $data, $hours ) {
        $key = self::cache_key( $source, $place_id );
        set_transient( $key, $data, max( 1, (int) $hours ) * HOUR_IN_SECONDS );

        $last = get_option( self::LAST_FETCH_OPTION, [] );
        if ( ! is_array( $last ) ) {
            $last = [];
        }
        $last[ $source . ':' . $place_id ] = $data['last_updated'] ?? gmdate( 'c' );
        update_option( self::LAST_FETCH_OPTION, $last, false );
    }

    public static function get_cache( $source, $place_id ) {
        $key = self::cache_key( $source, $place_id );
        return get_transient( $key );
    }

    public static function get_last_fetch( $source, $place_id ) {
        $last = get_option( self::LAST_FETCH_OPTION, [] );
        if ( ! is_array( $last ) ) {
            return '';
        }
        return $last[ $source . ':' . $place_id ] ?? '';
    }

    public function handle_manual_refresh() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'anchor_reviews_refresh' );

        $place_id = $this->get_place_id();
        $api_key = $this->get_api_key();
        $provider = $this->providers['google'] ?? null;
        $status = 'fail';

        if ( $place_id && $provider ) {
            $data = $provider->fetch( $place_id, $api_key );
            if ( ! is_wp_error( $data ) ) {
                self::store_cache( 'google', $place_id, $data, $this->get_cache_hours() );
                $status = 'success';
            }
        }

        $url = add_query_arg( [ 'anchor_reviews_refresh' => $status ], wp_get_referer() ?: admin_url( 'options-general.php?page=anchor-schema' ) );
        wp_safe_redirect( $url );
        exit;
    }

    public function shortcode( $atts ) {
        $atts = shortcode_atts(
            [
                'source'   => 'google',
                'place_id' => '',
            ],
            $atts
        );

        $source = sanitize_key( $atts['source'] );
        $place_id = $this->get_place_id();
        if ( ! $place_id ) {
            $place_id = trim( $atts['place_id'] );
        }
        if ( ! $source || ! $place_id ) {
            return '';
        }

        $data = self::get_cache( $source, $place_id );
        if ( empty( $data ) || ! is_array( $data ) ) {
            return '<div class="anchor-reviews anchor-reviews-empty">Reviews unavailable.</div>';
        }

        $html = '<div class="anchor-reviews" data-source="' . esc_attr( $source ) . '">';
        $html .= '<div class="anchor-reviews-summary">';
        $html .= '<span class="anchor-reviews-rating">' . esc_html( $data['rating'] ) . '</span>';
        $html .= '<span class="anchor-reviews-count">(' . esc_html( $data['rating_count'] ) . ' reviews)</span>';
        if ( ! empty( $data['business_url'] ) ) {
            $html .= ' <a class="anchor-reviews-link" href="' . esc_url( $data['business_url'] ) . '" target="_blank" rel="noopener">View on Google</a>';
        }
        $html .= '</div>';

        if ( ! empty( $data['reviews'] ) && is_array( $data['reviews'] ) ) {
            $html .= '<div class="anchor-reviews-list">';
            foreach ( $data['reviews'] as $review ) {
                $html .= '<div class="anchor-review">';
                $html .= '<div class="anchor-review-meta">';
                $html .= '<span class="anchor-review-author">' . esc_html( $review['author'] ?? '' ) . '</span>';
                $html .= '<span class="anchor-review-rating">' . esc_html( $review['rating'] ?? '' ) . '</span>';
                if ( ! empty( $review['date'] ) ) {
                    $html .= '<span class="anchor-review-date">' . esc_html( $review['date'] ) . '</span>';
                }
                $html .= '</div>';
                $html .= '<div class="anchor-review-text">' . esc_html( $review['text'] ?? '' ) . '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '<div class="anchor-reviews-attrib">Powered by Google</div>';
        $html .= '</div>';
        return $html;
    }

    public function shortcode_google( $atts ) {
        return $this->shortcode( array_merge( (array) $atts, [ 'source' => 'google' ] ) );
    }
}
