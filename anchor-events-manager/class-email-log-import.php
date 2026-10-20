<?php
/**
 * Events side of the WP Mail Logging backfill: decides which WPML messages belong
 * to which registrant, and exposes `wp anchor-events email-log import-wpml`.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

defined( 'ABSPATH' ) || exit;

class Email_Log_Import {

    /** Order-time window: 5 minutes before to 6 hours after the order was placed. */
    const WINDOW_BEFORE = 300;
    const WINDOW_AFTER  = 21600;

    /**
     * Plan (and optionally apply) the backfill.
     *
     * @param array $args  event (int, 0 = all events), before (UTC 'Y-m-d H:i:s', default earliest live row or now).
     * @param bool  $apply Insert rows when true; dry-run otherwise.
     * @return array { rows: array[], created: int, before: string }
     */
    public function run( array $args, $apply ) {
        $before = ! empty( $args['before'] ) ? (string) $args['before'] : $this->default_before();
        $out    = [ 'rows' => [], 'created' => 0, 'before' => $before ];
        if ( ! \class_exists( '\Anchor_Mail_Log_Import' ) ) {
            return $out;
        }
        $claimed = [];
        foreach ( $this->seat_ids( (int) ( $args['event'] ?? 0 ) ) as $seat_id ) {
            $info = $this->module_seat( $seat_id );
            foreach ( $this->candidates( $info, $before ) as $cand ) {
                $key = $cand['wpml']['mail_id'] . '|' . $cand['address'];
                if ( isset( $claimed[ $key ] ) ) {
                    continue; // Each message is imported at most once per recipient.
                }
                $claimed[ $key ] = true;
                $subject         = (string) $cand['wpml']['subject'];
                $kind            = \Anchor_Mail_Log_Import::kind_for_subject( $subject );
                if ( \Anchor_Mail_Log_Import::is_excluded( $subject ) ) {
                    $action = 'SKIP-EXCLUDED';
                } elseif ( \Anchor_Mail_Log_Import::exists( $cand['wpml']['mail_id'], $cand['address'] ) ) {
                    $action = 'SKIP-DUP';
                } else {
                    $action = 'CREATE';
                    if ( $apply ) {
                        $new = \Anchor_Mail_Log_Import::import_row( $cand['wpml'], $cand['ctx'] );
                        $out['created'] += $new ? 1 : 0;
                    }
                }
                $out['rows'][] = [
                    'seat'      => $seat_id,
                    'recipient' => $cand['address'],
                    'wpml_id'   => (int) $cand['wpml']['mail_id'],
                    'local'     => (string) $cand['wpml']['timestamp'],
                    'subject'   => $subject,
                    'kind'      => $kind,
                    'action'    => $action,
                    'seat_id'   => (int) $cand['ctx']['seat_id'],
                ];
            }
        }
        return $out;
    }

    /** Earliest source=live row in the log (rows from then on were captured natively), else now. */
    public function default_before() {
        global $wpdb;
        $table = \Anchor_Mail_Log::table();
        $min   = $wpdb->get_var( "SELECT MIN(created_at) FROM {$table} WHERE source = 'live'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $min ? (string) $min : \gmdate( 'Y-m-d H:i:s' );
    }

    /** @return int[] */
    private function seat_ids( $event_id ) {
        $q = [
            'post_type'      => Module::REG_CPT,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        if ( $event_id > 0 ) {
            $q['meta_query'] = [ [ 'key' => '_anchor_event_id', 'value' => $event_id, 'type' => 'NUMERIC' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        }
        return \array_map( 'intval', ( new \WP_Query( $q ) )->posts );
    }

    private function module_seat( $seat_id ) {
        $post = \get_post( $seat_id );
        return [
            'id'       => $seat_id,
            'email'    => \strtolower( \trim( (string) \get_post_meta( $seat_id, '_anchor_event_email', true ) ) ),
            'event_id' => (int) \get_post_meta( $seat_id, '_anchor_event_id', true ),
            'order_id' => (int) \get_post_meta( $seat_id, '_anchor_event_order_id', true ),
            'created'  => $post ? (string) $post->post_date_gmt : '',
        ];
    }

    /**
     * WPML messages that belong to a seat, with the context to import them under.
     *
     * Window A needs the order's own date: seats minted at a cutover carry the
     * cutover date, not the purchase date.
     *
     * @return array[] { wpml, address, ctx }
     */
    private function candidates( array $seat, $before ) {
        $order     = ( $seat['order_id'] > 0 && \function_exists( 'wc_get_order' ) ) ? \wc_get_order( $seat['order_id'] ) : null;
        $billing   = $order ? \strtolower( \trim( (string) $order->get_billing_email() ) ) : '';
        $addresses = \array_values( \array_unique( \array_filter( [ $seat['email'], $billing ] ) ) );
        $needles   = $this->event_needles( $seat['event_id'] );
        $out       = [];

        foreach ( $addresses as $address ) {
            $found = [];
            if ( $order && $order->get_date_created() ) {
                $at = $order->get_date_created()->getTimestamp();
                $to = \min( \gmdate( 'Y-m-d H:i:s', $at + self::WINDOW_AFTER ), $before );
                foreach ( \Anchor_Mail_Log_Import::wpml_rows( $address, \gmdate( 'Y-m-d H:i:s', $at - self::WINDOW_BEFORE ), $to ) as $w ) {
                    $found[ $w['mail_id'] ] = $w;
                }
            }
            if ( '' !== $seat['created'] && '0000-00-00 00:00:00' !== $seat['created'] ) {
                foreach ( \Anchor_Mail_Log_Import::wpml_rows( $address, $seat['created'], $before ) as $w ) {
                    if ( ! isset( $found[ $w['mail_id'] ] ) && $this->mentions_event( $w, $needles ) ) {
                        $found[ $w['mail_id'] ] = $w;
                    }
                }
            }
            \ksort( $found );
            foreach ( $found as $w ) {
                $out[] = [
                    'wpml'    => $w,
                    'address' => $address,
                    'ctx'     => [
                        'recipient' => $address,
                        'event_id'  => $seat['event_id'],
                        'order_id'  => $seat['order_id'],
                        // Mail to the billing address (not the seat's) is about the order, not this seat.
                        'seat_id'   => $address === $seat['email'] ? $seat['id'] : 0,
                    ],
                ];
            }
        }
        return $out;
    }

    /** @return array{title:string,url:string} */
    private function event_needles( $event_id ) {
        return [
            'title' => \trim( \html_entity_decode( (string) \get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ) ),
            'url'   => (string) \get_permalink( $event_id ),
        ];
    }

    private function mentions_event( array $wpml, array $needles ) {
        $title = $needles['title'];
        if ( '' !== $title && ( false !== \stripos( (string) $wpml['subject'], $title ) || false !== \stripos( (string) $wpml['message'], $title ) ) ) {
            return true;
        }
        return '' !== $needles['url'] && false !== \strpos( (string) $wpml['message'], $needles['url'] );
    }
}

if ( \defined( 'WP_CLI' ) && \WP_CLI ) {

    /**
     * Email log tools for Anchor Events.
     */
    class Email_Log_Import_Command {

        /**
         * Backfill registrant emails from the WP Mail Logging plugin as source=import rows.
         *
         * Dry-run by default: prints what would be created. Matching per seat uses the
         * seat email and the order billing email, within the order-time window or (for
         * later mail) a message that names the event.
         *
         * ## OPTIONS
         *
         * [--event=<id>]
         * : Only seats of this event.
         *
         * [--before=<datetime>]
         * : UTC cut-off, 'Y-m-d H:i:s'. Defaults to the earliest source=live row in the log, or now.
         *
         * [--apply]
         * : Insert the rows. Without it nothing is written.
         *
         * ## EXAMPLES
         *
         *     wp anchor-events email-log import-wpml --event=1119878
         *     wp anchor-events email-log import-wpml --event=1119878 --apply
         *
         * @subcommand import-wpml
         */
        public function import_wpml( $args, $assoc_args ) {
            global $wpdb;
            $apply    = ! empty( $assoc_args['apply'] );
            $importer = new Email_Log_Import();
            $table    = \Anchor_Mail_Log::table();
            $count    = static function () use ( $wpdb, $table ) {
                return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE source = 'import'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            };
            $before = $apply ? $count() : 0;

            $res = $importer->run(
                [
                    'event'  => isset( $assoc_args['event'] ) ? (int) $assoc_args['event'] : 0,
                    'before' => isset( $assoc_args['before'] ) ? (string) $assoc_args['before'] : '',
                ],
                $apply
            );

            \WP_CLI\Utils\format_items( 'table', $res['rows'], [ 'seat', 'recipient', 'wpml_id', 'local', 'subject', 'kind', 'action' ] );
            $tally = [ 'CREATE' => 0, 'SKIP-DUP' => 0, 'SKIP-EXCLUDED' => 0 ];
            foreach ( $res['rows'] as $r ) {
                $tally[ $r['action'] ]++;
            }
            \WP_CLI::log( \sprintf( 'Before (UTC): %s. CREATE %d, SKIP-DUP %d, SKIP-EXCLUDED %d.', $res['before'], $tally['CREATE'], $tally['SKIP-DUP'], $tally['SKIP-EXCLUDED'] ) );
            if ( $apply ) {
                \WP_CLI::success( \sprintf( 'Imported %d. Import rows before: %d, after: %d.', $res['created'], $before, $count() ) );
            } else {
                \WP_CLI::log( 'Dry run. Re-run with --apply to write.' );
            }
        }
    }

    \WP_CLI::add_command( 'anchor-events email-log', __NAMESPACE__ . '\\Email_Log_Import_Command' );
}
