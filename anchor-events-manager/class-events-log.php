<?php
/**
 * Events logging + needs-review helper.
 *
 * Phase 0 of the WooCommerce-integrated registration system. Provides a single
 * place for: a site-wide error log (option-backed, capped), a per-order sync log
 * (order meta, HPOS-safe via WC CRUD), and per-order "needs review" flags.
 *
 * The per-event activity roll-up (Events_Log::event) is intentionally a no-op in
 * MVP — the activity log + Activity panel are deferred (spec finding #20). The
 * method exists so callers don't branch and so a future build can fill it in.
 *
 * @package AnchorTools\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Events_Log {

    /** Site-wide error log option (autoload=false). */
    const ERROR_OPTION = 'anchor_events_error_log';

    /** Max entries kept in the site-wide error log. */
    const ERROR_CAP = 200;

    /** Order meta key: capped sync-log ring buffer. */
    const ORDER_LOG_META = '_anchor_event_sync_log';

    /** Order meta key: needs-review flags. */
    const ORDER_REVIEW_META = '_anchor_event_needs_review';

    /** Max entries kept in a single order's sync log. */
    const ORDER_LOG_CAP = 50;

    /**
     * Record an error. Forwards to Anchor_Schema_Logger (when debug logging is on)
     * and always appends to the capped site-wide option log so failures are
     * inspectable without enabling global debug.
     *
     * @param string $code    Short machine code, e.g. 'email_failed', 'lock_unavailable'.
     * @param array  $context Arbitrary context (kept small; not escaped).
     */
    public static function error( $code, array $context = [] ) {
        // The error log is persisted to an option that is rendered back to editors,
        // and mail failures can carry attendee PII (recipient/subject/body). Redact
        // before storing so no reversible PII lands in the log (CodeRabbit).
        $context = self::redact( $context );

        if ( \class_exists( '\\Anchor_Schema_Logger' ) ) {
            \Anchor_Schema_Logger::log( 'events:' . $code, $context );
        }

        $log = \get_option( self::ERROR_OPTION, [] );
        if ( ! \is_array( $log ) ) {
            $log = [];
        }
        $log[] = [
            'code'    => (string) $code,
            'time'    => \time(),
            'context' => $context,
        ];
        if ( \count( $log ) > self::ERROR_CAP ) {
            $log = \array_slice( $log, -self::ERROR_CAP );
        }
        \update_option( self::ERROR_OPTION, $log, false );
    }

    /**
     * Append an entry to an order's sync log (HPOS-safe via WC CRUD).
     * No-ops cleanly when WooCommerce is absent or the order can't be loaded.
     *
     * @param int    $order_id WooCommerce order ID.
     * @param string $message  Human-readable note.
     * @param array  $context  Optional structured context.
     */
    public static function order( $order_id, $message, array $context = [] ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 || ! \function_exists( 'wc_get_order' ) ) {
            return;
        }
        $order = \wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $log = $order->get_meta( self::ORDER_LOG_META );
        if ( ! \is_array( $log ) ) {
            $log = [];
        }
        $log[] = [
            'time'    => \time(),
            'message' => (string) $message,
            'context' => $context,
        ];
        if ( \count( $log ) > self::ORDER_LOG_CAP ) {
            $log = \array_slice( $log, -self::ORDER_LOG_CAP );
        }
        $order->update_meta_data( self::ORDER_LOG_META, $log );
        self::safe_save( $order );
    }

    /**
     * Flag an order as needing manual review (deduped by reason).
     *
     * @param int    $order_id WooCommerce order ID.
     * @param string $reason   Machine reason, e.g. 'amount_only_refund', 'capacity_overfill'.
     * @param string $detail   Optional human detail.
     */
    public static function flag_review( $order_id, $reason, $detail = '' ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 || ! \function_exists( 'wc_get_order' ) ) {
            return;
        }
        $order = \wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $flags = $order->get_meta( self::ORDER_REVIEW_META );
        if ( ! \is_array( $flags ) ) {
            $flags = [];
        }
        foreach ( $flags as $flag ) {
            if ( isset( $flag['reason'] ) && $flag['reason'] === $reason ) {
                return; // Already flagged for this reason.
            }
        }
        $flags[] = [
            'reason' => (string) $reason,
            'detail' => (string) $detail,
            'time'   => \time(),
        ];
        $order->update_meta_data( self::ORDER_REVIEW_META, $flags );
        self::safe_save( $order );
    }

    /**
     * Clear all needs-review flags from an order.
     *
     * @param int $order_id WooCommerce order ID.
     */
    public static function clear_review( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 || ! \function_exists( 'wc_get_order' ) ) {
            return;
        }
        $order = \wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $order->delete_meta_data( self::ORDER_REVIEW_META );
        self::safe_save( $order );
    }

    /**
     * Per-event activity roll-up. DEFERRED in MVP (spec finding #20): no-op.
     * Reserved so callers don't need to branch and a future build can populate it.
     *
     * @param int    $event_id Event post ID.
     * @param string $type     Activity type.
     * @param array  $context  Optional context.
     */
    public static function event( $event_id, $type, array $context = [] ) {
        // Intentionally empty — activity log deferred (spec §2, §11.6).
    }

    /**
     * Persist an order, failing soft. These are auxiliary logging/review paths,
     * so a WooCommerce persistence exception must never bubble up and take down
     * the surrounding checkout/admin request (CodeRabbit).
     *
     * @param \WC_Order $order
     */
    /**
     * Redact PII from a log context array. Drops/masks values under sensitive
     * keys (recipient, subject, body, etc.) and masks any email-looking string,
     * so the editor-visible error log can't leak attendee data. Recurses one level.
     *
     * @param mixed $context
     * @return mixed
     */
    private static function redact( $context ) {
        if ( \is_array( $context ) ) {
            $sensitive = [ 'to', 'recipient', 'recipients', 'cc', 'bcc', 'email', 'subject', 'body', 'message', 'headers' ];
            $out = [];
            foreach ( $context as $key => $value ) {
                if ( \is_string( $key ) && \in_array( \strtolower( $key ), $sensitive, true ) ) {
                    $out[ $key ] = \is_string( $value ) ? self::mask_value( $value ) : '[redacted]';
                } else {
                    $out[ $key ] = self::redact( $value );
                }
            }
            return $out;
        }
        if ( \is_string( $context ) ) {
            return self::mask_value( $context );
        }
        return $context;
    }

    /** Mask any email addresses inside a string (keeps the domain for debugging). */
    private static function mask_value( $value ) {
        return \preg_replace_callback(
            '/[^\s@]+@([^\s@]+)/',
            function ( $m ) {
                return '***@' . $m[1];
            },
            (string) $value
        );
    }

    private static function safe_save( $order ) {
        try {
            $order->save();
        } catch ( \Throwable $e ) {
            if ( \class_exists( '\\Anchor_Schema_Logger' ) ) {
                \Anchor_Schema_Logger::log( 'events:order_save_failed', [ 'message' => $e->getMessage() ] );
            }
        }
    }
}
