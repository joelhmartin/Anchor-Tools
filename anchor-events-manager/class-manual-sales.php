<?php
/**
 * Manual sales: turn a hand-added seat that was paid for offline (cheque, card
 * over the phone, bank transfer, cash) into a real WooCommerce order, so the
 * sale shows in WooCommerce orders and revenue reports like a checkout sale.
 *
 * The seat is created first by the roster's normal manual add (which always
 * goes through). record() then builds an order for the event's own product and
 * the seat's ticket tier at the amount staff typed, links the seat to the
 * order's line, and completes the order. Nothing is charged, and WooCommerce
 * sends none of its own emails for it.
 *
 * The order → seat reconcile (WooCommerce::reconcile_order()) sees one line
 * that expects one seat and already has one, so it changes nothing. From then
 * on the seat is order-owned: refunding or cancelling the order releases it,
 * exactly as for a checkout sale.
 *
 * Loaded only when WooCommerce is active (Module::__construct()).
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class Manual_Sales {

    /** `created_via` stamped on every order made here. */
    const CREATED_VIA = 'anchor_events_manual';

    /** Payment method id stored on the order (not a registered gateway). */
    const PAYMENT_METHOD = 'anchor_manual';

    /** @var Module */
    private $module;

    /** @var Registrations */
    private $registrations;

    public function __construct( Module $module, Registrations $registrations ) {
        $this->module        = $module;
        $this->registrations = $registrations;
    }

    /**
     * How the money was taken: key => label. One list for both roster screens,
     * the handler's validation and the order's payment-method title.
     *
     * @return array<string,string>
     */
    public static function methods() {
        $methods = [
            'check'    => \__( 'Check', 'anchor-schema' ),
            'card'     => \__( 'Card (phone or terminal)', 'anchor-schema' ),
            'transfer' => \__( 'Bank transfer', 'anchor-schema' ),
            'cash'     => \__( 'Cash', 'anchor-schema' ),
            'other'    => \__( 'Other', 'anchor-schema' ),
        ];
        /**
         * Filter the offline payment methods offered when recording a manual sale.
         *
         * @param array<string,string> $methods key => label.
         */
        $filtered = \apply_filters( 'anchor_events_manual_sale_methods', $methods );
        return ( \is_array( $filtered ) && ! empty( $filtered ) ) ? $filtered : $methods;
    }

    /**
     * Can a sale be recorded for this event at all? Needs the event's managed
     * WooCommerce product: without one there is nothing to put on the order.
     *
     * @param int $event_id
     * @return bool
     */
    public function available( $event_id ) {
        return $this->module->product_sync
            && \function_exists( 'wc_create_order' )
            && $this->module->product_sync->managed_product_id( (int) $event_id ) > 0;
    }

    /**
     * Can a sale still be recorded against this seat? Only a seat with no
     * order behind it, on an event that sells through WooCommerce.
     *
     * @param int   $event_id The seat's event.
     * @param array $seat     Seat DTO (Registrations::get_seat()).
     * @return bool
     */
    public function can_record_for_seat( $event_id, array $seat ) {
        return (int) ( $seat['order_id'] ?? 0 ) <= 0
            && (string) ( $seat['source'] ?? '' ) !== 'woocommerce'
            && \in_array( (string) ( $seat['status'] ?? '' ), [ Registrations::STATUS_CONFIRMED, Registrations::STATUS_PENDING ], true )
            && $this->available( (int) $event_id );
    }

    /**
     * Read the sale fields off a roster form post.
     *
     * A blank amount means "no sale to record" (a comp, a speaker, a staff
     * seat): `amount` is null and the caller carries on as before.
     *
     * @return array{amount:float|null,method:string,date:string,error:string}
     */
    public static function from_request() {
        $out = [ 'amount' => null, 'method' => 'other', 'date' => '', 'error' => '' ];

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the roster handler verified the nonce.
        $raw = isset( $_POST['roster_sale_amount'] ) ? \trim( (string) \wp_unslash( $_POST['roster_sale_amount'] ) ) : '';
        if ( $raw === '' ) {
            return $out;
        }
        // Accept "$2,195.00" as typed; refuse anything that is not a number.
        $clean = \str_replace( [ ',', ' ' ], '', \ltrim( $raw, '$' ) );
        if ( ! \is_numeric( $clean ) || (float) $clean < 0 ) {
            $out['error'] = \__( 'The amount paid is not a valid number.', 'anchor-schema' );
            return $out;
        }
        $out['amount'] = \round( (float) $clean, 2 );

        $method = isset( $_POST['roster_sale_method'] ) ? \sanitize_key( (string) \wp_unslash( $_POST['roster_sale_method'] ) ) : '';
        if ( isset( self::methods()[ $method ] ) ) {
            $out['method'] = $method;
        }

        $date = isset( $_POST['roster_sale_date'] ) ? \sanitize_text_field( (string) \wp_unslash( $_POST['roster_sale_date'] ) ) : '';
        // phpcs:enable
        if ( $date !== '' ) {
            $parsed = \DateTime::createFromFormat( '!Y-m-d', $date, \wp_timezone() );
            if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date || $parsed->getTimestamp() > \time() + DAY_IN_SECONDS ) {
                $out['error'] = \__( 'The payment date is not a valid past date.', 'anchor-schema' );
                return $out;
            }
            $out['date'] = $date;
        }
        return $out;
    }

    /**
     * Record an offline sale for an existing seat: create the order, link the
     * seat to it, complete it.
     *
     * @param int    $seat_id
     * @param float  $amount  What was collected, in the store currency. 0 is allowed (a recorded comp).
     * @param string $method  A key of methods().
     * @param array  $args {
     *   @type string $date  'Y-m-d' the money was taken (site time zone). Default today.
     *   @type string $actor History actor, e.g. 'user:12'.
     * }
     * @return Outcome sent = order created (reason is the order id); failed = nothing was created.
     */
    public function record( $seat_id, $amount, $method, array $args = [] ) {
        $seat_id = (int) $seat_id;
        $amount  = \round( (float) $amount, 2 );
        $seat    = $this->registrations->get_seat( $seat_id );
        if ( ! \is_array( $seat ) ) {
            return Outcome::failed( 'seat_missing' );
        }
        if ( $amount < 0 ) {
            return Outcome::failed( 'bad_amount' );
        }
        if ( (int) $seat['order_id'] > 0 || (string) $seat['source'] === 'woocommerce' ) {
            return Outcome::failed( 'already_has_order' );
        }
        // A sale against a seat that holds no place would put revenue on the
        // books for somebody who is not coming.
        if ( ! \in_array( (string) $seat['status'], [ Registrations::STATUS_CONFIRMED, Registrations::STATUS_PENDING ], true ) ) {
            return Outcome::failed( 'seat_not_active' );
        }
        // The seat DTO carries no event id; the meta is the source of truth.
        $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );
        if ( ! $this->available( $event_id ) ) {
            return Outcome::failed( 'no_product' );
        }

        $tier_id      = (string) ( $seat['ticket_type_id'] ?? '' );
        $variation_id = $tier_id !== '' ? (int) $this->module->product_sync->variation_for_tier( $event_id, $tier_id ) : 0;
        $product_id   = (int) $this->module->product_sync->managed_product_id( $event_id );
        $product      = \wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
        // A variable product cannot be an order line by itself, and a line on
        // the wrong tier would resolve to the wrong ticket type on reconcile.
        if ( ! $product || $product->is_type( 'variable' ) ) {
            return Outcome::failed( 'no_ticket_product' );
        }

        $methods      = self::methods();
        $method       = isset( $methods[ $method ] ) ? (string) $method : 'other';
        $actor        = (string) ( $args['actor'] ?? ( 'user:' . \get_current_user_id() ) );
        $user_id      = (int) \get_post_meta( $seat_id, '_anchor_event_user_id', true );
        $paid_ts      = $this->paid_timestamp( (string) ( $args['date'] ?? '' ) );

        try {
            $order = \wc_create_order( [
                'customer_id' => $user_id > 0 ? $user_id : 0,
                'created_via' => self::CREATED_VIA,
            ] );
            if ( \is_wp_error( $order ) || ! $order instanceof \WC_Order ) {
                return Outcome::failed( 'order_create_failed' );
            }

            $item = new \WC_Order_Item_Product();
            $item->set_product( $product );
            $item->set_quantity( 1 );
            $item->set_subtotal( $amount );
            $item->set_total( $amount );
            // Same shape WooCommerce::persist_attendees_to_line_item() writes at
            // checkout, so the reconcile reads this line as complete.
            $item->add_meta_data( '_anchor_attendees', [
                1 => [
                    'name'   => (string) $seat['name'],
                    'email'  => (string) $seat['email'],
                    'phone'  => (string) $seat['phone'],
                    'fields' => \is_array( $seat['reg_fields'] ?? null ) ? $seat['reg_fields'] : [],
                ],
            ], true );
            $item->add_meta_data( '_anchor_event_id', $event_id, true );
            $order->add_item( $item );

            $this->set_billing_from_seat( $order, $seat );
            $order->set_payment_method( self::PAYMENT_METHOD );
            /* translators: %s: how the money was taken, e.g. "Check". */
            $order->set_payment_method_title( \sprintf( \__( 'Manual: %s', 'anchor-schema' ), $methods[ $method ] ) );
            $order->update_meta_data( '_anchor_manual_sale_method', $method );
            $order->update_meta_data( '_anchor_manual_sale_seat', $seat_id );
            $order->set_date_created( $paid_ts );
            $order->set_date_paid( $paid_ts );
            // The typed amount IS the total: no tax is added on top of it.
            $order->calculate_totals( false );
            $order->save();

            $order_id = (int) $order->get_id();
            $item_id  = (int) $item->get_id();
            if ( $order_id <= 0 || $item_id <= 0 ) {
                $this->discard( $order );
                return Outcome::failed( 'order_create_failed' );
            }

            // Link BEFORE the order turns active: the reconcile that the status
            // change fires must find this seat on the line, or it would mint a
            // second one for the same person.
            $linked = $this->registrations->link_order( $seat_id, [
                'order_id'      => $order_id,
                'order_item_id' => $item_id,
                'product_id'    => (int) $item->get_product_id(),
                'variation_id'  => (int) $item->get_variation_id(),
                'customer_id'   => (int) $order->get_customer_id(),
            ], 'manual sale recorded as order #' . $order_id, $actor );
            if ( $linked->is_failed() ) {
                $this->discard( $order );
                return Outcome::failed( 'link_failed' );
            }

            $staff = \wp_get_current_user();
            $note  = \sprintf(
                /* translators: 1: staff display name, 2: payment method label. */
                \__( 'Recorded from the event roster by %1$s. Paid offline (%2$s); no card was charged here.', 'anchor-schema' ),
                ( $staff && $staff->exists() ) ? $staff->display_name : \__( 'the system', 'anchor-schema' ),
                $methods[ $method ]
            );

            $this->without_wc_emails( static function () use ( $order, $note ) {
                $order->update_status( 'completed', $note, true );
            } );

            // WooCommerce stamps "completed" with now; a back-dated sale
            // should read as completed on the day it was paid.
            $fresh = \wc_get_order( $order_id );
            if ( $fresh ) {
                $fresh->set_date_completed( $paid_ts );
                $fresh->save();
            }

            return Outcome::sent( (string) $order_id );
        } catch ( \Throwable $e ) {
            Events_Log::error( 'manual_sale_failed', [ 'seat' => $seat_id, 'message' => $e->getMessage() ] );
            return Outcome::failed( 'exception' );
        }
    }

    /** Human message for a record() failure, for the roster notice. */
    public static function failure_message( $reason ) {
        switch ( (string) $reason ) {
            case 'already_has_order':
                return \__( 'this seat already has an order', 'anchor-schema' );
            case 'seat_not_active':
                return \__( 'the seat is not confirmed', 'anchor-schema' );
            case 'no_product':
            case 'no_ticket_product':
                return \__( 'this ticket type has no WooCommerce product to put on an order', 'anchor-schema' );
            default:
                return \__( 'WooCommerce could not create the order', 'anchor-schema' );
        }
    }

    /* ---------------------------------------------------------------------
     * Form fragments (markup differs per roster screen; the fields do not)
     * ------------------------------------------------------------------- */

    /**
     * wp-admin form-table rows.
     *
     * @param bool $with_date Offer a payment date (the seat edit form, for back-dating).
     */
    public function admin_rows( $with_date = false ) {
        echo '<tr><th scope="row"><label for="roster_sale_amount">' . \esc_html__( 'Amount paid', 'anchor-schema' ) . '</label></th><td>';
        echo '<input type="text" inputmode="decimal" name="roster_sale_amount" id="roster_sale_amount" class="regular-text" value="" placeholder="0.00" />';
        echo '<p class="description">' . \esc_html( self::help_text() ) . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="roster_sale_method">' . \esc_html__( 'Paid by', 'anchor-schema' ) . '</label></th><td>';
        echo '<select name="roster_sale_method" id="roster_sale_method">';
        foreach ( self::methods() as $key => $label ) {
            echo '<option value="' . \esc_attr( $key ) . '">' . \esc_html( $label ) . '</option>';
        }
        echo '</select></td></tr>';

        if ( $with_date ) {
            echo '<tr><th scope="row"><label for="roster_sale_date">' . \esc_html__( 'Payment date', 'anchor-schema' ) . '</label></th><td>';
            echo '<input type="date" name="roster_sale_date" id="roster_sale_date" value="" max="' . \esc_attr( \wp_date( 'Y-m-d' ) ) . '" />';
            echo '<p class="description">' . \esc_html__( 'Leave blank for today.', 'anchor-schema' ) . '</p>';
            echo '</td></tr>';
        }
    }

    /**
     * Front-end console fields (inside an `.anchor-event-grid`).
     *
     * @param callable $field_id  fn( string $base ): string — the console's unique-id helper.
     * @param bool     $with_date Offer a payment date.
     */
    public function frontend_fields( callable $field_id, $with_date = false ) {
        $amount_id = (string) $field_id( 'roster_sale_amount' );
        $method_id = (string) $field_id( 'roster_sale_method' );
        ?>
        <div class="anchor-event-field">
            <label for="<?php echo \esc_attr( $amount_id ); ?>"><?php \esc_html_e( 'Amount paid', 'anchor-schema' ); ?></label>
            <input type="text" inputmode="decimal" id="<?php echo \esc_attr( $amount_id ); ?>" name="roster_sale_amount" placeholder="0.00" />
            <small class="anchor-roster-fe-help"><?php echo \esc_html( self::help_text() ); ?></small>
        </div>
        <div class="anchor-event-field">
            <label for="<?php echo \esc_attr( $method_id ); ?>"><?php \esc_html_e( 'Paid by', 'anchor-schema' ); ?></label>
            <select id="<?php echo \esc_attr( $method_id ); ?>" name="roster_sale_method">
                <?php foreach ( self::methods() as $key => $label ) : ?>
                    <option value="<?php echo \esc_attr( $key ); ?>"><?php echo \esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ( $with_date ) :
            $date_id = (string) $field_id( 'roster_sale_date' ); ?>
        <div class="anchor-event-field">
            <label for="<?php echo \esc_attr( $date_id ); ?>"><?php \esc_html_e( 'Payment date', 'anchor-schema' ); ?></label>
            <input type="date" id="<?php echo \esc_attr( $date_id ); ?>" name="roster_sale_date" max="<?php echo \esc_attr( \wp_date( 'Y-m-d' ) ); ?>" />
            <small class="anchor-roster-fe-help"><?php \esc_html_e( 'Leave blank for today.', 'anchor-schema' ); ?></small>
        </div>
        <?php endif;
    }

    private static function help_text() {
        return \__( 'Fill this in when the person paid outside the website. It creates a completed WooCommerce order for that amount so the sale shows in your reports. Nothing is charged and no receipt is emailed. Leave blank for a free seat.', 'anchor-schema' );
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------- */

    /** Noon, site time, on the given 'Y-m-d' (today when blank or today's date). */
    private function paid_timestamp( $date ) {
        if ( $date === '' || $date === \wp_date( 'Y-m-d' ) ) {
            return \time();
        }
        $parsed = \DateTime::createFromFormat( '!Y-m-d', $date, \wp_timezone() );
        return $parsed ? $parsed->getTimestamp() + 12 * HOUR_IN_SECONDS : \time();
    }

    private function set_billing_from_seat( \WC_Order $order, array $seat ) {
        $name  = \trim( (string) $seat['name'] );
        $parts = \preg_split( '/\s+/', $name, 2 );
        $order->set_billing_first_name( (string) ( $parts[0] ?? '' ) );
        $order->set_billing_last_name( (string) ( $parts[1] ?? '' ) );
        if ( (string) $seat['email'] !== '' ) {
            $order->set_billing_email( (string) $seat['email'] );
        }
        $order->set_billing_phone( (string) $seat['phone'] );
    }

    /** Remove a half-built order so a failed record() leaves nothing behind. */
    private function discard( \WC_Order $order ) {
        try {
            $order->delete( true );
        } catch ( \Throwable $e ) {
            Events_Log::error( 'manual_sale_cleanup_failed', [ 'order' => (int) $order->get_id(), 'message' => $e->getMessage() ] );
        }
    }

    /**
     * Run $fn with every WooCommerce transactional email switched off. The
     * money was taken offline, so a "your order is complete" receipt and a
     * "new order" admin alert would both describe a checkout that never
     * happened. The registration email is the roster's own, separate control.
     */
    private function without_wc_emails( callable $fn ) {
        $ids = [];
        if ( \function_exists( 'WC' ) && \WC()->mailer() ) {
            foreach ( (array) \WC()->mailer()->get_emails() as $email ) {
                if ( \is_object( $email ) && ! empty( $email->id ) ) {
                    $ids[] = (string) $email->id;
                }
            }
        }
        foreach ( $ids as $id ) {
            \add_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 9999 );
        }
        try {
            $fn();
        } finally {
            foreach ( $ids as $id ) {
                \remove_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 9999 );
            }
        }
    }
}
