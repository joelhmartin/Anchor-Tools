<?php
/**
 * Events side of the mail log.
 *
 * Wires Anchor Events into the generic Anchor_Mail_Log primitive: WooCommerce
 * customer-email capture for event orders, the per-seat email catalog (Email_Catalog) and the Mailgun
 * signing-key setting.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-email-catalog.php';

class Email_Log {

    /** @var Module */
    private $module;

    /** @var Email_Catalog The per-seat "Emails" panel and its Send endpoint. */
    public $catalog;

    public function __construct( Module $module ) {
        $this->module  = $module;
        $this->catalog = new Email_Catalog( $module );
        \add_filter( 'woocommerce_mail_callback_params', [ $this, 'wc_begin' ], 10, 2 );
        \add_action( 'woocommerce_email_sent', [ $this, 'wc_finish' ], 10, 3 );
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
