<?php
/**
 * Whether a WooCommerce Subscriptions renewal order should create event seats.
 *
 * A subscription can mean two different things for an event registration:
 *
 *  - a RECURRING purchase (no end date), where each renewal buys the next
 *    period and so should create that period's seats; and
 *  - an INSTALLMENT plan ("3 monthly payments"), a fixed-length subscription
 *    whose renewals are the remaining payments for the seat the FIRST order
 *    already created. Treating those renewals as new registrations gives one
 *    attendee a seat per payment, eats capacity, and re-sends the
 *    registration emails.
 *
 * A subscription with an end date is an installment plan; its renewals create
 * no seats. Sites that sell a fixed-length subscription that really does buy a
 * new seat each period can override with the `anchor_events_renewal_creates_seats`
 * filter.
 *
 * Everything is function_exists-guarded, so without WC Subscriptions every
 * order "creates seats" and reconcile behaves exactly as before.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class Subscription_Renewals {

    /**
     * Should this order's line for $event_id create/keep seats?
     *
     * @param int $order_id
     * @param int $event_id
     * @return bool True for every non-renewal order.
     */
    public static function creates_seats( $order_id, $event_id ) {
        if ( ! self::is_renewal( $order_id ) ) {
            return true;
        }
        $subs = \function_exists( 'wcs_get_subscriptions_for_renewal_order' )
            ? \wcs_get_subscriptions_for_renewal_order( (int) $order_id )
            : [];
        $subs = \is_array( $subs ) ? $subs : [];

        /**
         * Filter whether a subscription renewal order creates event seats.
         *
         * @param bool  $creates  Default: false for an installment plan (a
         *                        subscription with an end date), true otherwise.
         * @param int   $order_id Renewal order id.
         * @param int   $event_id Event the line resolves to.
         * @param array $subs     The renewal's subscriptions.
         */
        return (bool) \apply_filters(
            'anchor_events_renewal_creates_seats',
            ! self::is_installment_plan( $subs ),
            (int) $order_id,
            (int) $event_id,
            $subs
        );
    }

    /**
     * @param int $order_id
     * @return bool
     */
    public static function is_renewal( $order_id ) {
        return \function_exists( 'wcs_order_contains_renewal' ) && \wcs_order_contains_renewal( (int) $order_id );
    }

    /**
     * True when any of the subscriptions has an end date (a fixed number of
     * payments). An open-ended subscription reports 0.
     *
     * @param array $subs Subscription objects (anything with get_time()).
     * @return bool
     */
    public static function is_installment_plan( array $subs ) {
        foreach ( $subs as $sub ) {
            if ( \is_object( $sub ) && \method_exists( $sub, 'get_time' ) && (int) $sub->get_time( 'end' ) > 0 ) {
                return true;
            }
        }
        return false;
    }
}
