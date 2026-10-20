<?php
/**
 * Backfill of the mail log from the WP Mail Logging plugin ({prefix}wpml_mails).
 *
 * Generic primitive: reads WPML rows, cleans FunnelKit tracking out of stored
 * bodies and inserts them as source=import rows. Which rows belong to which
 * registrant is the caller's decision (see Anchor\Events\Email_Log_Import).
 *
 * @package Anchor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Anchor_Mail_Log_Import' ) ) {

    class Anchor_Mail_Log_Import {

        /** Subjects that are never registrant emails (account, password, signing, admin mail). */
        const EXCLUDE_PATTERN = '/account (has been )?created|password|Document Viewed|has been signed|^\[|new order/i';

        /** Headers dropped on import: dead FunnelKit endpoints, or set by the mailer on every send. */
        const DROPPED_HEADERS = [ 'mime-version', 'list-unsubscribe', 'list-unsubscribe-post' ];

        public static function wpml_table() {
            global $wpdb;
            return $wpdb->prefix . 'wpml_mails';
        }

        public static function wpml_exists() {
            global $wpdb;
            return self::wpml_table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::wpml_table() ) );
        }

        public static function is_excluded( $subject ) {
            return (bool) preg_match( self::EXCLUDE_PATTERN, (string) $subject );
        }

        public static function kind_for_subject( $subject ) {
            $subject = (string) $subject;
            if ( 0 === strcasecmp( trim( $subject ), 'Thank You For Registering!' ) ) {
                return 'wc.customer_processing_order';
            }
            if ( preg_match( '/^Mini Residency Course/i', $subject ) ) {
                return 'legacy.funnelkit';
            }
            if ( preg_match( '/^You.re registered/iu', $subject ) ) {
                return 'events.wc_confirmation';
            }
            return 'imported';
        }

        /**
         * Remove FunnelKit tracking from a stored body. Anything not named here is
         * left byte-identical.
         */
        public static function clean_html( $html ) {
            $html = (string) $html;

            // Unsubscribe anchors go entirely.
            $html = preg_replace_callback(
                '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>.*?</a\s*>#is',
                static function ( $m ) {
                    return false !== strpos( html_entity_decode( $m[2], ENT_QUOTES ), 'bwfan-action=unsubscribe' ) ? '' : $m[0];
                },
                $html
            );

            // Open pixels.
            $html = preg_replace_callback(
                '#<img\b[^>]*>#i',
                static function ( $m ) {
                    return preg_match( '/bwfan-(track-)?action=open/', html_entity_decode( $m[0], ENT_QUOTES ) ) ? '' : $m[0];
                },
                $html
            );

            // Click trackers and proofpoint wrappers (a link may be wrapped in both).
            return preg_replace_callback(
                '#(\bhref\s*=\s*)(["\'])(.*?)\2#is',
                static function ( $m ) {
                    $url = html_entity_decode( $m[3], ENT_QUOTES );
                    $new = self::unwrap_url( $url );
                    if ( $new === $url ) {
                        return $m[0];
                    }
                    return $m[1] . $m[2] . str_replace( [ '"', "'" ], [ '%22', '%27' ], $new ) . $m[2];
                },
                $html
            );
        }

        /** Resolve click-tracker and proofpoint wrappers to the real URL (unchanged URLs come back as-is). */
        private static function unwrap_url( $url ) {
            for ( $i = 0; $i < 4; $i++ ) {
                if ( false !== strpos( $url, 'bwfan-track-action=click' ) && preg_match( '/[?&]bwfan-link=([^&]*)/', $url, $m ) ) {
                    $url = rawurldecode( $m[1] );
                } elseif ( preg_match( '#urldefense\.proofpoint\.com/v2/url\?u=([^&]*)#', $url, $m ) ) {
                    $url = rawurldecode( strtr( $m[1], [ '-' => '%', '_' => '/' ] ) );
                } else {
                    break;
                }
            }
            return $url;
        }

        /**
         * WPML joins headers with ",\n" - stored as a literal backslash-n on the live
         * site, but split on real newlines too. Dead/mailer-set headers are dropped.
         *
         * @return string[]
         */
        public static function split_headers( $raw ) {
            $lines = preg_split( '/,?(?:\r?\n|\\\\n)/', (string) $raw );
            $out   = [];
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( '' === $line ) {
                    continue;
                }
                $name = strtolower( trim( strstr( $line, ':', true ) ) );
                if ( in_array( $name, self::DROPPED_HEADERS, true ) ) {
                    continue;
                }
                $out[] = $line;
            }
            return $out;
        }

        /**
         * WPML rows whose receiver contains the address, within a UTC window.
         * wpml_mails.timestamp is site-local time, so the bounds are converted to it.
         */
        public static function wpml_rows( $recipient, $from_utc, $to_utc ) {
            global $wpdb;
            if ( ! self::wpml_exists() ) {
                return [];
            }
            $table = self::wpml_table();
            $rows  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE receiver LIKE %s AND timestamp >= %s AND timestamp <= %s ORDER BY timestamp ASC, mail_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    '%' . $wpdb->esc_like( strtolower( trim( $recipient ) ) ) . '%',
                    get_date_from_gmt( $from_utc ),
                    get_date_from_gmt( $to_utc )
                ),
                ARRAY_A
            );
            if ( ! $rows ) {
                return [];
            }
            // The LIKE is only a prefilter: require exact address equality.
            $want = strtolower( trim( $recipient ) );
            return array_values(
                array_filter(
                    $rows,
                    static function ( $row ) use ( $want ) {
                        return in_array( $want, self::receiver_addresses( (string) $row['receiver'] ), true );
                    }
                )
            );
        }

        /**
         * Addresses in a WPML receiver value (separated by commas, semicolons, real or literal backslash-n newlines; each possibly `Name <addr>`).
         *
         * @return string[] lowercased.
         */
        public static function receiver_addresses( $receiver ) {
            $out = [];
            foreach ( preg_split( '/(?:\\\\n|[\r\n,;])+/', html_entity_decode( (string) $receiver, ENT_QUOTES, 'UTF-8' ) ) as $part ) {
                $part = trim( $part );
                if ( preg_match( '/<([^>]+)>/', $part, $m ) ) {
                    $part = $m[1];
                }
                $part = strtolower( trim( $part ) );
                if ( '' !== $part ) {
                    $out[] = $part;
                }
            }
            return $out;
        }

        /** Whether this WPML row is already imported for the recipient. */
        public static function exists( $mail_id, $recipient ) {
            global $wpdb;
            $table = Anchor_Mail_Log::table();
            return (bool) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE source = 'import' AND source_ref = %s AND recipient = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    (string) $mail_id,
                    strtolower( (string) $recipient )
                )
            );
        }

        /**
         * Import one WPML row.
         *
         * @param array $wpml WPML row.
         * @param array $ctx  recipient (required), event_id, seat_id, order_id, context, audience.
         * @return int|null New log id, or null when already imported / not inserted.
         */
        public static function import_row( array $wpml, array $ctx ) {
            $recipient = strtolower( trim( (string) ( $ctx['recipient'] ?? '' ) ) );
            if ( '' === $recipient || empty( $wpml['mail_id'] ) || self::exists( $wpml['mail_id'], $recipient ) ) {
                return null;
            }
            $subject = (string) ( $wpml['subject'] ?? '' );
            $id      = Anchor_Mail_Log::insert(
                [
                    'created_at' => get_gmt_from_date( (string) $wpml['timestamp'] ),
                    'context'    => (string) ( $ctx['context'] ?? 'events' ),
                    'kind'       => self::kind_for_subject( $subject ),
                    'audience'   => (string) ( $ctx['audience'] ?? 'attendee' ),
                    'event_id'   => (int) ( $ctx['event_id'] ?? 0 ),
                    'seat_id'    => (int) ( $ctx['seat_id'] ?? 0 ),
                    'order_id'   => (int) ( $ctx['order_id'] ?? 0 ),
                    'recipient'  => substr( $recipient, 0, 190 ),
                    'subject'    => $subject,
                    'html'       => self::clean_html( (string) ( $wpml['message'] ?? '' ) ),
                    'headers'    => wp_json_encode( self::split_headers( (string) ( $wpml['headers'] ?? '' ) ) ),
                    'source'     => 'import',
                    'source_ref' => (string) $wpml['mail_id'],
                    'status'     => 'sent',
                ]
            );
            return $id ? $id : null;
        }
    }
}
