<?php
/**
 * Mail log: records opted-in wp_mail() sends and tags them for Mailgun event matching.
 *
 * Generic primitive; knows nothing about any module. A caller arms capture with
 * begin(), calls wp_mail(), then closes with finish(). Mailgun webhook events are
 * folded back into the row through apply_event().
 *
 * @package Anchor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Anchor_Mail_Log' ) ) {

    class Anchor_Mail_Log {

        const DB_VERSION        = '1';
        const DB_VERSION_OPTION = 'anchor_mail_log_db_version';

        /** Status precedence: a lower rank never overwrites a higher one. */
        const RANKS = [
            'sent'       => 0,
            'deferred'   => 1,
            'delivered'  => 2,
            'failed'     => 3,
            'bounced'    => 3,
            'complained' => 3,
        ];

        /** @var array|null Armed capture context. */
        private static $ctx = null;

        /** @var int Row id created for the armed context (0 = none yet). */
        private static $row_id = 0;

        /** @var string Last wp_mail_failed message for the armed context. */
        private static $error = '';

        public static function init() {
            add_filter( 'wp_mail', [ __CLASS__, 'capture' ], 999 );
            add_action( 'wp_mail_failed', [ __CLASS__, 'remember_error' ] );
            add_action( 'plugins_loaded', [ __CLASS__, 'maybe_install' ] );
        }

        public static function table() {
            global $wpdb;
            return $wpdb->prefix . 'anchor_mail_log';
        }

        public static function maybe_install() {
            if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
                self::install();
            }
        }

        public static function install() {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $table   = self::table();
            $charset = $wpdb->get_charset_collate();

            dbDelta(
                "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                context VARCHAR(40) NOT NULL DEFAULT '',
                kind VARCHAR(60) NOT NULL DEFAULT '',
                audience VARCHAR(20) NOT NULL DEFAULT '',
                event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                seat_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                recipient VARCHAR(190) NOT NULL DEFAULT '',
                subject TEXT NULL,
                html LONGTEXT NULL,
                headers LONGTEXT NULL,
                source VARCHAR(10) NOT NULL DEFAULT 'live',
                source_ref VARCHAR(64) NOT NULL DEFAULT '',
                resend_of BIGINT UNSIGNED NOT NULL DEFAULT 0,
                sent_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'sent',
                status_detail TEXT NULL,
                delivered_at DATETIME NULL,
                opened_at DATETIME NULL,
                open_count INT UNSIGNED NOT NULL DEFAULT 0,
                clicked_at DATETIME NULL,
                click_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_event_at DATETIME NULL,
                PRIMARY KEY  (id),
                KEY event_seat (event_id,seat_id),
                KEY order_id (order_id),
                KEY recipient (recipient),
                KEY source_ref (source_ref)
            ) {$charset};"
            );

            update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
        }

        public static function site_tag() {
            return (string) wp_parse_url( home_url(), PHP_URL_HOST );
        }

        /** Arm capture for the next wp_mail() call. */
        public static function begin( array $ctx ) {
            self::$ctx    = wp_parse_args(
                $ctx,
                [
                    'context'    => '',
                    'kind'       => '',
                    'audience'   => '',
                    'event_id'   => 0,
                    'seat_id'    => 0,
                    'order_id'   => 0,
                    'source'     => 'live',
                    'source_ref' => '',
                    'resend_of'  => 0,
                    'sent_by'    => 0,
                ]
            );
            self::$row_id = 0;
            self::$error  = '';
        }

        /**
         * Close capture. Marks the armed row sent/failed; if the wp_mail filter never
         * ran (e.g. an early failure) the row is created from the armed context.
         *
         * @return int|null Row id, or null when nothing was armed.
         */
        public static function finish( $sent, $error = '' ) {
            if ( null === self::$ctx ) {
                return null;
            }
            $ctx    = self::$ctx;
            $id     = self::$row_id;
            $detail = '' !== $error ? $error : self::$error;
            if ( ! $sent && '' === $detail ) {
                $detail = 'wp_mail returned false';
            }

            self::$ctx    = null;
            self::$row_id = 0;
            self::$error  = '';

            if ( ! $id ) {
                $id = self::insert( self::row_from_ctx( $ctx ) );
            }
            if ( ! $id ) {
                return null;
            }

            global $wpdb;
            $wpdb->update(
                self::table(),
                [
                    'status'        => $sent ? 'sent' : 'failed',
                    'status_detail' => $sent ? '' : $detail,
                ],
                [ 'id' => $id ]
            );
            return (int) $id;
        }

        /** wp_mail filter: log and tag the armed send. */
        public static function capture( $atts ) {
            if ( null === self::$ctx || self::$row_id || ! is_array( $atts ) ) {
                return $atts;
            }

            $headers = self::normalise_headers( isset( $atts['headers'] ) ? $atts['headers'] : '' );
            $headers = array_values(
                array_filter(
                    $headers,
                    static function ( $line ) {
                        return ! preg_match( '/^\s*X-Mailgun-(Variables|Track)/i', $line );
                    }
                )
            );

            $to  = isset( $atts['to'] ) ? $atts['to'] : '';
            $row = self::row_from_ctx( self::$ctx );

            $row['recipient'] = substr( self::first_address( $to ), 0, 190 );
            $row['subject']   = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
            $row['html']      = isset( $atts['message'] ) ? (string) $atts['message'] : '';
            $row['headers']   = wp_json_encode( $headers );

            $id = self::insert( $row );
            if ( ! $id ) {
                return $atts;
            }
            self::$row_id = $id;

            $headers[]       = 'X-Mailgun-Variables: ' . wp_json_encode(
                [
                    'anchor_mail_id' => $id,
                    'anchor_site'    => self::site_tag(),
                ]
            );
            $headers[]       = 'X-Mailgun-Track: yes';
            $headers[]       = 'X-Mailgun-Track-Clicks: htmlonly';
            $headers[]       = 'X-Mailgun-Track-Opens: yes';
            $atts['headers'] = $headers;
            return $atts;
        }

        public static function remember_error( $error ) {
            if ( null !== self::$ctx && $error instanceof WP_Error ) {
                self::$error = $error->get_error_message();
            }
        }

        public static function insert( array $row ) {
            global $wpdb;
            $defaults = [
                'created_at' => gmdate( 'Y-m-d H:i:s' ),
                'status'     => 'sent',
                'source'     => 'live',
            ];
            $row = array_merge( $defaults, $row );
            if ( false === $wpdb->insert( self::table(), $row ) ) {
                return 0;
            }
            return (int) $wpdb->insert_id;
        }

        public static function get( $id ) {
            global $wpdb;
            $table = self::table();
            $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
            return $row ? $row : null;
        }

        /**
         * @param array $where seat_id, order_id, event_ids (int[]), audience,
         *                     seat_or_order ([seat_id, order_id, event_id]).
         * @return array[]
         */
        public static function query( array $where ) {
            global $wpdb;
            $table  = self::table();
            $parts  = [ '1=1' ];
            $params = [];

            if ( ! empty( $where['seat_id'] ) ) {
                $parts[]  = 'seat_id = %d';
                $params[] = (int) $where['seat_id'];
            }
            if ( ! empty( $where['order_id'] ) ) {
                $parts[]  = 'order_id = %d';
                $params[] = (int) $where['order_id'];
            }
            if ( ! empty( $where['event_ids'] ) ) {
                $ids      = array_map( 'intval', (array) $where['event_ids'] );
                $parts[]  = 'event_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
                $params   = array_merge( $params, $ids );
            }
            if ( ! empty( $where['audience'] ) ) {
                $parts[]  = 'audience = %s';
                $params[] = (string) $where['audience'];
            }
            if ( ! empty( $where['seat_or_order'] ) ) {
                list( $seat, $order, $event ) = array_map( 'intval', array_pad( (array) $where['seat_or_order'], 3, 0 ) );
                $parts[]  = '(seat_id = %d OR (order_id = %d AND seat_id = 0 AND event_id IN (%d, 0)))';
                $params[] = $seat;
                $params[] = $order;
                $params[] = $event;
            }

            $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $parts ) . ' ORDER BY created_at DESC, id DESC';
            if ( $params ) {
                $sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
            $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return $rows ? $rows : [];
        }

        /**
         * Fold a Mailgun event into a row with ONE atomic UPDATE, so concurrent webhooks
         * cannot lose a count or let a lower-rank status overwrite a higher one. Opens
         * and clicks never change status. Every call stamps last_event_at.
         *
         * MySQL evaluates SET assignments left to right and sees already-updated
         * columns, so every assignment that reads `status` comes before the status one.
         *
         * @param string $type delivered|opened|clicked|temporary_fail|permanent_fail|complained
         * @return bool True when a row matched.
         */
        public static function apply_event( $id, $type, $when_utc, $detail = '' ) {
            global $wpdb;
            $table = self::table();
            $sets  = [];
            $args  = [];

            $status_for = [
                'delivered'      => 'delivered',
                'temporary_fail' => 'deferred',
                'permanent_fail' => 'bounced',
                'complained'     => 'complained',
            ];

            if ( 'opened' === $type ) {
                $sets[] = 'open_count = open_count + 1';
                $sets[] = 'opened_at = COALESCE(opened_at, %s)';
                $args[] = $when_utc;
            } elseif ( 'clicked' === $type ) {
                $sets[] = 'click_count = click_count + 1';
                $sets[] = 'clicked_at = COALESCE(clicked_at, %s)';
                $args[] = $when_utc;
            } elseif ( isset( $status_for[ $type ] ) ) {
                $status = $status_for[ $type ];
                $rank   = (int) self::RANKS[ $status ];
                $lower  = '(CASE status WHEN \'sent\' THEN 0 WHEN \'deferred\' THEN 1 WHEN \'delivered\' THEN 2 ELSE 3 END) < ' . $rank;
                if ( 'delivered' === $status ) {
                    $sets[] = "delivered_at = IF({$lower}, COALESCE(delivered_at, %s), delivered_at)";
                    $args[] = $when_utc;
                }
                $sets[] = "status_detail = IF({$lower}, %s, status_detail)";
                $args[] = $detail;
                $sets[] = "status = IF({$lower}, %s, status)";
                $args[] = $status;
            } else {
                return false;
            }

            $sets[] = 'last_event_at = %s';
            $args[] = $when_utc;
            $args[] = (int) $id;

            $sql    = $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE id = %d', $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ( false === $result ) {
                return false;
            }
            if ( $result > 0 ) {
                return true;
            }
            // 0 changed rows: still a match if the row exists (identical values rewritten).
            return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", (int) $id ) );
        }

        private static function row_from_ctx( array $ctx ) {
            return [
                'context'    => (string) $ctx['context'],
                'kind'       => (string) $ctx['kind'],
                'audience'   => (string) $ctx['audience'],
                'event_id'   => (int) $ctx['event_id'],
                'seat_id'    => (int) $ctx['seat_id'],
                'order_id'   => (int) $ctx['order_id'],
                'source'     => (string) $ctx['source'],
                'source_ref' => (string) $ctx['source_ref'],
                'resend_of'  => (int) $ctx['resend_of'],
                'sent_by'    => (int) $ctx['sent_by'],
                'status'     => 'sent',
            ];
        }

        /** wp_mail headers arrive as string or array; return a clean array of lines. */
        private static function normalise_headers( $headers ) {
            if ( ! is_array( $headers ) ) {
                $headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
            }
            $out = [];
            foreach ( $headers as $line ) {
                $line = trim( (string) $line );
                if ( '' !== $line ) {
                    $out[] = $line;
                }
            }
            return $out;
        }

        /** First address of a wp_mail $to value, lowercased. */
        private static function first_address( $to ) {
            if ( is_array( $to ) ) {
                $to = reset( $to );
            }
            $to    = (string) $to;
            $parts = explode( ',', $to );
            $first = trim( $parts[0] );
            if ( preg_match( '/<([^>]+)>/', $first, $m ) ) {
                $first = $m[1];
            }
            return strtolower( trim( $first ) );
        }
    }

    Anchor_Mail_Log::init();
}
