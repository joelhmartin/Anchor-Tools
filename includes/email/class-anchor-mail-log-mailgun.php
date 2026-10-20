<?php
/**
 * Mailgun webhook receiver: folds delivery/open/click events into the mail log.
 *
 * Auth is the HMAC signature, not a WordPress login. Ignored events still answer
 * 200 because Mailgun retries non-2xx responses for hours.
 *
 * @package Anchor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Anchor_Mail_Log_Mailgun' ) ) {

    class Anchor_Mail_Log_Mailgun {

        const OPTION      = 'anchor_mail_log_settings';
        const MAX_SKEW    = 900;
        const ROUTE_NS    = 'anchor-tools/v1';
        const ROUTE_PATH  = '/mail-log/mailgun';

        public static function init() {
            add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        }

        public static function register_routes() {
            register_rest_route(
                self::ROUTE_NS,
                self::ROUTE_PATH,
                [
                    'methods'             => 'POST',
                    'callback'            => [ __CLASS__, 'handle' ],
                    'permission_callback' => '__return_true',
                ]
            );
        }

        public static function webhook_url() {
            return rest_url( self::ROUTE_NS . self::ROUTE_PATH );
        }

        public static function signing_key() {
            $opt = get_option( self::OPTION, [] );
            return is_array( $opt ) && isset( $opt['mailgun_signing_key'] ) ? (string) $opt['mailgun_signing_key'] : '';
        }

        public static function save_signing_key( $key ) {
            $opt = get_option( self::OPTION, [] );
            $opt = is_array( $opt ) ? $opt : [];
            $opt['mailgun_signing_key'] = trim( (string) $key );
            update_option( self::OPTION, $opt, false );
        }

        public static function verify( array $sig, $key, $now ) {
            if ( '' === (string) $key || ! isset( $sig['timestamp'], $sig['token'], $sig['signature'] ) ) {
                return false;
            }
            if ( ! is_scalar( $sig['timestamp'] ) || ! is_scalar( $sig['token'] ) || ! is_scalar( $sig['signature'] ) ) {
                return false;
            }
            $expected = hash_hmac( 'sha256', $sig['timestamp'] . $sig['token'], $key );
            if ( ! hash_equals( $expected, (string) $sig['signature'] ) ) {
                return false;
            }
            return abs( (int) $now - (int) $sig['timestamp'] ) <= self::MAX_SKEW;
        }

        public static function handle( WP_REST_Request $r ) {
            $body = json_decode( (string) $r->get_body(), true );
            $body = is_array( $body ) ? $body : [];
            $sig  = isset( $body['signature'] ) && is_array( $body['signature'] ) ? $body['signature'] : [];

            if ( ! self::verify( $sig, self::signing_key(), time() ) ) {
                return new WP_REST_Response( [ 'ok' => false ], 401 );
            }

            // Mailgun signs only timestamp+token, so a captured request could be replayed
            // with a different body: accept each token once inside the skew window.
            // INSERT IGNORE on the unique option_name is atomic, so two concurrent deliveries of one token
            // cannot both win. A seen token answers 200/applied:false: Mailgun retries
            // non-2xx, and a legitimate retry must not be refused forever.
            global $wpdb;
            $name = 'anchor_mg_tok_' . md5( (string) $sig['token'] );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( 'anchor_mg_tok_' ) . '%', time() - self::MAX_SKEW - 300 ) ); // phpcs:ignore
            $claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) ); // phpcs:ignore
            if ( 1 !== (int) $claimed ) {
                return new WP_REST_Response( [ 'ok' => true, 'applied' => false ], 200 );
            }

            $data    = isset( $body['event-data'] ) && is_array( $body['event-data'] ) ? $body['event-data'] : [];
            $applied = self::apply( $data );
            return new WP_REST_Response( [ 'ok' => true, 'applied' => $applied ], 200 );
        }

        /** Map and apply one verified event; false when it is not ours or not mappable. */
        private static function apply( array $data ) {
            $vars = isset( $data['user-variables'] ) && is_array( $data['user-variables'] ) ? $data['user-variables'] : [];
            $id   = isset( $vars['anchor_mail_id'] ) && is_scalar( $vars['anchor_mail_id'] ) ? (int) $vars['anchor_mail_id'] : 0;
            if ( $id <= 0 ) {
                return false;
            }
            // Staging shares the Mailgun domain: another host's events are not ours.
            $site = isset( $vars['anchor_site'] ) && is_scalar( $vars['anchor_site'] ) ? (string) $vars['anchor_site'] : '';
            if ( Anchor_Mail_Log::site_tag() !== $site ) {
                return false;
            }
            $type = self::map_type( $data );
            if ( '' === $type ) {
                return false;
            }
            $row = Anchor_Mail_Log::get( $id );
            if ( ! $row ) {
                return false;
            }
            // Staff Bcc copies carry the same variables but a different recipient.
            $recipient = isset( $data['recipient'] ) && is_scalar( $data['recipient'] ) ? strtolower( trim( (string) $data['recipient'] ) ) : '';
            if ( $recipient !== strtolower( (string) $row['recipient'] ) ) {
                return false;
            }

            $ts   = isset( $data['timestamp'] ) && is_numeric( $data['timestamp'] ) ? (int) floor( (float) $data['timestamp'] ) : time();
            $when = gmdate( 'Y-m-d H:i:s', $ts );
            return Anchor_Mail_Log::apply_event( $id, $type, $when, self::detail( $data ) );
        }

        private static function map_type( array $data ) {
            $event = isset( $data['event'] ) && is_scalar( $data['event'] ) ? (string) $data['event'] : '';
            if ( in_array( $event, [ 'delivered', 'opened', 'clicked', 'complained' ], true ) ) {
                return $event;
            }
            if ( 'failed' === $event ) {
                $severity = isset( $data['severity'] ) && is_scalar( $data['severity'] ) ? (string) $data['severity'] : '';
                return 'permanent' === $severity ? 'permanent_fail' : 'temporary_fail';
            }
            return '';
        }

        private static function detail( array $data ) {
            $ds    = isset( $data['delivery-status'] ) && is_array( $data['delivery-status'] ) ? $data['delivery-status'] : [];
            $parts = [];
            foreach ( [ 'message', 'description', 'code' ] as $k ) {
                if ( isset( $ds[ $k ] ) && is_scalar( $ds[ $k ] ) && '' !== (string) $ds[ $k ] ) {
                    $parts[] = (string) $ds[ $k ];
                }
            }
            return substr( implode( ' | ', $parts ), 0, 1000 );
        }
    }

    Anchor_Mail_Log_Mailgun::init();
}
