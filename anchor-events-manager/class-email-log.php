<?php
/**
 * Events side of the mail log.
 *
 * Wires Anchor Events into the generic Anchor_Mail_Log primitive: labels for the
 * kinds Events logs, the capability that lets event managers see/resend those
 * rows, WooCommerce customer-email capture for event orders, the per-seat panel
 * and the Mailgun signing-key setting.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

defined( 'ABSPATH' ) || exit;

class Email_Log {

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
        \add_filter( 'anchor_mail_log_can_manage', [ $this, 'filter_can_manage' ], 10, 2 );
        \add_filter( 'anchor_mail_log_kind_labels', [ $this, 'filter_kind_labels' ] );
        \add_filter( 'woocommerce_mail_callback_params', [ $this, 'wc_begin' ], 10, 2 );
        \add_action( 'woocommerce_email_sent', [ $this, 'wc_finish' ], 10, 3 );
    }

    /** @return array<string,string> kind => human label. */
    public static function kind_labels() {
        return [
            'events.confirmation'     => \__( 'Registration confirmation', 'anchor-schema' ),
            'events.reminder'         => \__( 'Event reminder', 'anchor-schema' ),
            'events.cancellation'     => \__( 'Cancellation notice', 'anchor-schema' ),
            'events.roster'           => \__( 'Roster to organizer', 'anchor-schema' ),
            'events.wc_confirmation'  => \__( 'Order confirmation (events)', 'anchor-schema' ),
            'events.organizer_notice' => \__( 'Organizer notice', 'anchor-schema' ),
            'wc.customer_processing_order' => \__( 'Order received (WooCommerce)', 'anchor-schema' ),
            'wc.customer_completed_order'  => \__( 'Order complete (WooCommerce)', 'anchor-schema' ),
            'wc.customer_invoice'     => \__( 'Invoice (WooCommerce)', 'anchor-schema' ),
            'legacy.funnelkit'        => \__( 'Course details (old FunnelKit email)', 'anchor-schema' ),
            'imported'                => \__( 'Imported email', 'anchor-schema' ),
        ];
    }

    public function filter_kind_labels( $labels ) {
        return \array_merge( \is_array( $labels ) ? $labels : [], self::kind_labels() );
    }

    /** Events rows are managed by anyone who can manage events, not just admins. */
    public function filter_can_manage( $can, $row ) {
        if ( \is_array( $row ) && ( $row['context'] ?? '' ) === 'events' ) {
            return \current_user_can( Module::events_capability() );
        }
        return $can;
    }

    /**
     * Arm capture for a WooCommerce customer email about an order holding seats.
     *
     * If WooCommerce never reaches woocommerce_email_sent, the next begin() simply
     * replaces this context, and the log's capture() only claims the first wp_mail.
     */
    public function wc_begin( $params, $email ) {
        if ( ! \is_object( $email ) || ! \class_exists( '\Anchor_Mail_Log' ) || ! \is_callable( [ $email, 'is_customer_email' ] ) || ! $email->is_customer_email() ) {
            return $params;
        }
        $order = $email->object ?? null;
        if ( ! $order instanceof \WC_Order ) {
            return $params;
        }
        $seat_ids = $this->module->registrations->get_seats_for_order( $order->get_id() );
        if ( ! $seat_ids ) {
            return $params;
        }
        $events = [];
        foreach ( $seat_ids as $sid ) {
            $events[ (int) \get_post_meta( $sid, '_anchor_event_id', true ) ] = true;
        }
        \Anchor_Mail_Log::begin( [
            'context'  => 'events',
            'kind'     => 'wc.' . $email->id,
            'audience' => 'attendee',
            'event_id' => 1 === \count( $events ) ? (int) \key( $events ) : 0,
            'order_id' => (int) $order->get_id(),
        ] );
        return $params;
    }

    public function wc_finish( $sent, $id = '', $email = null ) {
        if ( \class_exists( '\Anchor_Mail_Log' ) ) {
            \Anchor_Mail_Log::finish( (bool) $sent );
        }
    }

    /** Arm capture for send_html_email(); returns whether anything was armed. */
    public static function begin_for_send( array $log, $event_id, array $identity ) {
        if ( empty( $log['kind'] ) || ! \class_exists( '\Anchor_Mail_Log' ) ) {
            return false;
        }
        \Anchor_Mail_Log::begin( [
            'context'  => 'events',
            'kind'     => (string) $log['kind'],
            'audience' => $log['audience'] ?? 'attendee',
            'event_id' => (int) $event_id,
            'seat_id'  => (int) ( $identity['seat'] ?? 0 ),
            'order_id' => (int) ( $identity['order'] ?? 0 ),
        ] );
        return true;
    }

    /** Admin panel of every attendee email for a seat (empty if seat is not in the event). */
    public function panel_for_seat( $event_id, $seat_id ) {
        $event_id = (int) $event_id;
        $seat_id  = (int) $seat_id;
        if ( ! \class_exists( '\Anchor_Mail_Log_Admin' ) || ! Roster::seat_belongs_to_event( $seat_id, $event_id ) ) {
            return '';
        }
        $seat = $this->module->registrations->get_seat( $seat_id );
        if ( ! $seat ) {
            return '';
        }
        $rows = \Anchor_Mail_Log::query( [
            'seat_or_order' => [ $seat_id, (int) $seat['order_id'], $event_id ],
            'audience'      => 'attendee',
        ] );
        \Anchor_Mail_Log_Admin::enqueue();
        return \Anchor_Mail_Log_Admin::render_panel( $rows, self::kind_labels() );
    }

    /** Persist the posted signing key (blank = keep the saved one). */
    public static function save_signing_key_from_input( $input ) {
        $key = \is_array( $input ) ? \trim( (string) ( $input['mailgun_signing_key'] ?? '' ) ) : '';
        if ( '' !== $key && \class_exists( '\Anchor_Mail_Log_Mailgun' ) ) {
            \Anchor_Mail_Log_Mailgun::save_signing_key( \sanitize_text_field( $key ) );
        }
    }

    /** Settings field: write-only key input plus the read-only webhook URL. */
    public static function render_signing_key_field() {
        if ( ! \class_exists( '\Anchor_Mail_Log_Mailgun' ) ) {
            return;
        }
        $has = '' !== \Anchor_Mail_Log_Mailgun::signing_key();
        \printf(
            '<input type="password" name="%1$s[mailgun_signing_key]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s" />',
            \esc_attr( Module::OPTION_KEY ),
            \esc_attr( $has ? '••••••••' : '' )
        );
        if ( $has ) {
            echo ' <em>' . \esc_html__( 'Saved ••••', 'anchor-schema' ) . '</em>';
        }
        echo '<p class="description">' . \esc_html__( 'Mailgun webhook URL (delivered, opened, clicked, failed):', 'anchor-schema' )
            . ' <code>' . \esc_html( \Anchor_Mail_Log_Mailgun::webhook_url() ) . '</code></p>';
    }
}
