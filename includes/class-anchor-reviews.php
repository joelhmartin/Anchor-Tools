<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Reviews_Manager {
    const CRON_HOOK = 'anchor_reviews_refresh_cron';
    const LAST_FETCH_OPTION = 'anchor_reviews_last_fetch';

    private $providers = [];

    public function __construct() {
        add_shortcode( 'anchor_reviews', [ $this, 'shortcode' ] );
        add_shortcode( 'anchor_reviews_google', [ $this, 'shortcode_google' ] );
        add_shortcode( 'anchor_reviews_widget', [ $this, 'shortcode_widget' ] );
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

        $url = add_query_arg( [ 'anchor_reviews_refresh' => $status ], wp_get_referer() ?: admin_url( 'options-general.php?page=anchor-reviews' ) );
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

    public function shortcode_widget( $atts ) {
        $atts = shortcode_atts(
            [
                'place_id' => '',
            ],
            $atts
        );

        $place_id = $this->get_place_id();
        if ( ! $place_id ) {
            $place_id = trim( $atts['place_id'] );
        }
        if ( ! $place_id ) {
            return '';
        }

        $data = self::get_cache( 'google', $place_id );
        if ( empty( $data ) || ! is_array( $data ) ) {
            return '';
        }

        $name   = trim( (string) ( $data['business_name'] ?? '' ) );
        $rating = isset( $data['rating'] ) ? (float) $data['rating'] : 0;
        $count  = isset( $data['rating_count'] ) ? (int) $data['rating_count'] : 0;

        if ( ! $name && ! $rating && ! $count ) {
            return '';
        }

        $rating_display = number_format_i18n( $rating, 1 );
        $count_display  = number_format_i18n( $count );
        $stars_html     = self::render_stars_svg( $rating );

        $html  = '<div class="anchor-reviews-widget" style="display:inline-flex;align-items:center;gap:16px;background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:1rem 1.25rem">';
        $html .= '<svg width="36" height="36" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.1 0 5.8 1.1 8 2.8l6-6C34.3 3.3 29.5 1 24 1 14.8 1 6.9 6.6 3.3 14.6l7 5.4C12.2 13.7 17.6 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v8.5h12.7c-.6 3-2.3 5.5-4.9 7.2l7.5 5.8C43.7 37.1 46.5 31.3 46.5 24.5z"/><path fill="#FBBC04" d="M10.3 28.6c-.5-1.5-.8-3.1-.8-4.6s.3-3.1.8-4.6l-7-5.4C1.8 17.1 1 20.5 1 24s.8 6.9 2.3 9.9l7-5.3z"/><path fill="#34A853" d="M24 47c5.5 0 10.1-1.8 13.4-4.9l-7.5-5.8c-1.9 1.3-4.3 2-5.9 2-6.4 0-11.8-4.3-13.7-10.1l-7 5.4C6.9 41.4 14.8 47 24 47z"/></svg>';
        $html .= '<div style="width:1px;height:48px;background:#e0e0e0;flex-shrink:0"></div>';
        $html .= '<div style="display:flex;flex-direction:column;gap:4px">';
        if ( $name ) {
            $html .= '<div style="font-size:13px;font-weight:600;color:#1a1a1a;line-height:1;">' . esc_html( $name ) . '</div>';
        }
        $html .= '<div style="display:flex;align-items:center;gap:6px">';
        $html .= '<span style="font-size:18px;font-weight:600;color:#1a1a1a;line-height:1;">' . esc_html( $rating_display ) . '</span>';
        $html .= '<div style="display:flex;gap:2px">' . $stars_html . '</div>';
        $html .= '</div>';
        $html .= '<div style="font-size:12px;color:#666;line-height:1;">' . esc_html( $count_display ) . ' Google reviews</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function render_stars_svg( $rating ) {
        $rating = max( 0, min( 5, (float) $rating ) );
        $uid    = wp_unique_id( 'anchor-rw-' );
        $html   = '';

        for ( $i = 0; $i < 5; $i++ ) {
            $remaining = $rating - $i;
            if ( $remaining >= 1 ) {
                $fill = '#FBBC04';
            } elseif ( $remaining <= 0 ) {
                $fill = '#E0E0E0';
            } else {
                $fill = false;
            }

            if ( false !== $fill ) {
                $html .= '<svg style="width:16px;height:16px" viewBox="0 0 20 20" aria-hidden="true"><polygon points="10,1.5 12.6,7.2 19,7.9 14.3,12.3 15.7,18.5 10,15.3 4.3,18.5 5.7,12.3 1,7.9 7.4,7.2" fill="' . esc_attr( $fill ) . '"/></svg>';
                continue;
            }

            $offset = (int) round( $remaining * 100 );
            $grad_id = $uid . '-' . $i;
            $html .= '<svg style="width:16px;height:16px" viewBox="0 0 20 20" aria-hidden="true">';
            $html .= '<defs><linearGradient id="' . esc_attr( $grad_id ) . '"><stop offset="' . esc_attr( $offset ) . '%" stop-color="#FBBC04"/><stop offset="' . esc_attr( $offset ) . '%" stop-color="#E0E0E0"/></linearGradient></defs>';
            $html .= '<polygon points="10,1.5 12.6,7.2 19,7.9 14.3,12.3 15.7,18.5 10,15.3 4.3,18.5 5.7,12.3 1,7.9 7.4,7.2" fill="url(#' . esc_attr( $grad_id ) . ')"/>';
            $html .= '</svg>';
        }

        return $html;
    }
}
