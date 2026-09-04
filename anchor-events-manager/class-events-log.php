<?php
/**
 * Events logging + needs-review helper.
 *
 * Phase 0 of the WooCommerce-integrated registration system. Provides a single
 * place for: a site-wide error log (option-backed, capped), a per-order sync log
 * (order meta, HPOS-safe via WC CRUD), and per-order "needs review" flags.
 *
 * There is deliberately NO per-event activity roll-up here. One existed as an
 * empty Events_Log::event() with a full docblock, so any caller would have
 * looked like it was recording activity and recorded nothing; REG-D30 removed
 * it, along with the reserved 'activity' event meta key, until the Activity
 * panel that would read them actually ships.
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

    /**
     * Archive of cleared error-log entries (REG-D31). "Clear error log" is a
     * tidy-up button, not a destruction order: the entries it removes are the
     * only record of failed sends, illegal transitions and lock degradations,
     * so they move here instead of being deleted.
     */
    const ERROR_ARCHIVE_OPTION = 'anchor_events_error_log_archive';

    /** Max entries kept in the archive (autoload=false, still bounded). */
    const ERROR_ARCHIVE_CAP = 500;

    /**
     * Max entries any ONE code may hold in the error log (REG-D46). The global
     * cap alone let a single repeating failure evict every other code, taking
     * the seat_insert_failed / illegal_transition row an operator needs with it.
     */
    const ERROR_CODE_CAP = 25;

    /**
     * Window in which a repeat of the same (code, subject) collapses into the
     * existing row instead of appending a new one (REG-D46). Measured from the
     * row's LAST sighting, so an ongoing failure stays one counted row while a
     * failure that returns after a day of quiet is recorded as news.
     */
    const ERROR_DEDUPE_WINDOW = 86400; // DAY_IN_SECONDS, without the load-order bet.

    /**
     * Context keys that identify WHAT a failure was about. Two entries sharing a
     * code but naming different subjects are different failures and must not
     * collapse into each other (REG-D46) — the same email failing for order 12
     * and order 13 is two problems, not one seen twice.
     *
     * Beyond the entity ids, three keys name WHICH failure about a subject:
     * `from` (the transition a seat was refused), `to` (what it was refused TO
     * — two rejected targets on one seat are two bugs, and on a mail failure it
     * is the redacted recipient, so a send failing for two people is two
     * problems) and `exception` (two different throws from one event's product
     * sync are two faults).
     */
    const ERROR_IDENTITY_KEYS = [ 'order', 'event', 'seat', 'item', 'tier', 'parent_id', 'occurrence_key', 'type', 'source', 'from', 'to', 'exception' ];

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
     * @param string $code    Short machine code, e.g. 'email_send_returned_false',
     *                        'capacity_lock_unavailable'.
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

        $code = (string) $code;
        $log  = \get_option( self::ERROR_OPTION, [] );
        if ( ! \is_array( $log ) ) {
            $log = [];
        }

        $now      = \time();
        $identity = self::error_identity( $code, $context );

        // REG-D46 — collapse a repeat of the same (code, subject) seen inside the
        // window into the existing row: {first_time, time (last seen), count}.
        // The row moves to the end so "most recent first" stays honest.
        foreach ( $log as $i => $entry ) {
            if ( ! \is_array( $entry ) || ( $entry['code'] ?? '' ) !== $code ) {
                continue;
            }
            $last = (int) ( $entry['time'] ?? 0 );
            if ( $now - $last > self::ERROR_DEDUPE_WINDOW ) {
                continue; // Outside the window: a genuinely new failure, never swallowed.
            }
            if ( self::error_identity( $code, (array) ( $entry['context'] ?? [] ) ) !== $identity ) {
                continue;
            }
            unset( $log[ $i ] );
            $log    = \array_values( $log );
            $log[]  = [
                'code'       => $code,
                'first_time' => (int) ( $entry['first_time'] ?? $last ),
                'time'       => $now,
                'count'      => (int) ( $entry['count'] ?? 1 ) + 1,
                'context'    => $context,
            ];
            \update_option( self::ERROR_OPTION, $log, false );
            return;
        }

        $log[] = [
            'code'       => $code,
            'first_time' => $now,
            'time'       => $now,
            'count'      => 1,
            'context'    => $context,
        ];
        $log = self::enforce_code_cap( $log, $code );
        if ( \count( $log ) > self::ERROR_CAP ) {
            $log = \array_slice( $log, -self::ERROR_CAP );
        }
        \update_option( self::ERROR_OPTION, $log, false );
    }

    /**
     * The subject a failure is about, as a dedupe key. Codes that carry no
     * subject id at all (email_send_returned_false, say) collapse by code alone
     * — deliberately: those are exactly the floods REG-D46 is about, and the
     * count plus the latest context still say what is happening.
     *
     * @param string $code
     * @param array  $context
     * @return string
     */
    private static function error_identity( $code, array $context ) {
        $parts = [ (string) $code ];
        foreach ( self::ERROR_IDENTITY_KEYS as $key ) {
            if ( isset( $context[ $key ] ) && \is_scalar( $context[ $key ] ) ) {
                $parts[] = $key . '=' . (string) $context[ $key ];
            }
        }
        return \implode( '|', $parts );
    }

    /**
     * Drop the oldest rows of ONE code once it exceeds its per-code allowance,
     * so a flood evicts only itself (REG-D46).
     *
     * @param array  $log
     * @param string $code
     * @return array
     */
    private static function enforce_code_cap( array $log, $code ) {
        $positions = [];
        foreach ( $log as $i => $entry ) {
            if ( \is_array( $entry ) && ( $entry['code'] ?? '' ) === $code ) {
                $positions[] = $i;
            }
        }
        $excess = \count( $positions ) - self::ERROR_CODE_CAP;
        if ( $excess <= 0 ) {
            return $log;
        }
        foreach ( \array_slice( $positions, 0, $excess ) as $i ) {
            unset( $log[ $i ] );
        }
        return \array_values( $log );
    }

    /**
     * Move the site-wide error log into the archive and empty it (REG-D31).
     * Called by the "Clear error log" button so tidying the panel never destroys
     * the only record of a failure.
     *
     * @return int Number of entries archived.
     */
    public static function archive_and_clear() {
        $log = \get_option( self::ERROR_OPTION, [] );
        if ( ! \is_array( $log ) ) {
            $log = [];
        }
        if ( ! empty( $log ) ) {
            $archive = \get_option( self::ERROR_ARCHIVE_OPTION, [] );
            if ( ! \is_array( $archive ) ) {
                $archive = [];
            }
            foreach ( $log as $entry ) {
                $archive[] = $entry;
            }
            if ( \count( $archive ) > self::ERROR_ARCHIVE_CAP ) {
                $archive = \array_slice( $archive, -self::ERROR_ARCHIVE_CAP );
            }
            \update_option( self::ERROR_ARCHIVE_OPTION, $archive, false );
        }
        \delete_option( self::ERROR_OPTION );
        return \count( $log );
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
        self::bust_needs_review_cache();
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
        self::bust_needs_review_cache();
    }

    /**
     * WOO-D16 — invalidate the cached needs-review count the admin notice reads.
     *
     * apply_review_flags() already does this for flags raised inside reconcile;
     * these static entry points (guard_block_checkout, the amount-only refund
     * branch, a failed manual resend) did not, so a count of 0 cached moments
     * earlier hid a freshly flagged order for the transient's five minutes.
     * Uses WooCommerce::NEEDS_REVIEW_TRANSIENT — the same key, not a new one.
     */
    private static function bust_needs_review_cache() {
        if ( \function_exists( 'delete_transient' ) && \class_exists( __NAMESPACE__ . '\\WooCommerce' ) ) {
            \delete_transient( WooCommerce::NEEDS_REVIEW_TRANSIENT );
        }
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
