<?php
/**
 * The Emails panel's View: one catalog email as it would be sent to this
 * registrant now, rendered on demand and never mailed.
 *
 * Each row type renders through the same builder its sender uses
 * (WooCommerce::preview_customer_confirmation() for a WooCommerce seat's
 * buyer confirmation, Module::build_confirmation_email(), build_reminder_email(),
 * build_cancellation_email(), or WooCommerce's own processing-order email), so
 * the preview cannot drift from the send. Rendering has no side effects: no
 * wp_mail(), no mail-log row, no order note, no reminder/cancellation marker,
 * no retry job, no account created for the seat (Module::render_without_side_effects()).
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

defined( 'ABSPATH' ) || exit;

class Email_Preview {

    const AJAX_ACTION = 'anchor_events_email_preview';

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
        \add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_view' ] );
    }

    /** Signed URL of the preview of one catalog email for one seat. */
    public static function url( $event_id, $seat_id, $key ) {
        return \add_query_arg(
            [
                'action'   => self::AJAX_ACTION,
                'event_id' => (int) $event_id,
                'seat_id'  => (int) $seat_id,
                'email'    => (string) $key,
                '_wpnonce' => \wp_create_nonce( \Anchor_Mail_Log_Admin::NONCE ),
            ],
            \admin_url( 'admin-ajax.php' )
        );
    }

    /**
     * Build one catalog email for a seat without sending it.
     *
     * @param int    $event_id
     * @param array  $seat Seat DTO (Registrations::get_seat()).
     * @param string $key  Catalog row key: receipt | confirmation | reminder:N | cancellation.
     * @return array{subject:string,html:string}|null Null when the email cannot be built.
     */
    public function render( $event_id, array $seat, $key ) {
        $event_id = (int) $event_id;
        $key      = (string) $key;
        return $this->module->render_without_side_effects( function () use ( $event_id, $seat, $key ) {
            if ( 'receipt' === $key ) {
                return $this->render_receipt( (int) ( $seat['order_id'] ?? 0 ) );
            }
            if ( 'confirmation' === $key && Email_Catalog::buyer_order_id( $this->module, $seat ) > 0 ) {
                return $this->module->woocommerce->preview_customer_confirmation( (int) $seat['order_id'], $event_id );
            }
            if ( 'confirmation' === $key ) {
                return $this->module->build_confirmation_email(
                    $event_id,
                    (string) $seat['name'],
                    (string) $seat['email'],
                    (string) $seat['status'],
                    (int) ( $seat['guests'] ?? 0 ),
                    (int) $seat['id']
                );
            }
            if ( 'cancellation' === $key ) {
                $info = $this->module->registrations->get_seat_info( (int) $seat['id'] );
                return \is_array( $info ) ? $this->module->build_cancellation_email( $info ) : null;
            }
            if ( \preg_match( '/^reminder:(\d+)$/', $key, $m )
                && \in_array( (int) $m[1], $this->module->effective_offsets( $event_id, $this->module->get_settings() ), true ) ) {
                return $this->module->build_reminder_email( $seat, $event_id );
            }
            return null;
        } );
    }

    /**
     * WooCommerce's processing-order email content for the order, rendered the
     * way WC_Email::send() renders it (style_inline() + woocommerce_mail_content)
     * but without trigger()/send(): no mail, no "email sent" order note. The
     * shared WC_Email instance is put back exactly as it was found.
     */
    private function render_receipt( $order_id ) {
        $wc    = Email_Catalog::wc_receipt_email();
        $order = ( $order_id > 0 && \function_exists( 'wc_get_order' ) ) ? \wc_get_order( $order_id ) : null;
        if ( ! $wc || ! $order ) {
            return null;
        }
        $saved = [ $wc->object, $wc->recipient, $wc->placeholders ];
        $wc->setup_locale();
        try {
            $wc->object                         = $order;
            $wc->recipient                      = $order->get_billing_email();
            $wc->placeholders['{order_date}']   = \wc_format_datetime( $order->get_date_created() );
            $wc->placeholders['{order_number}'] = $order->get_order_number();
            $content                            = $wc->get_content();
            $wc->sending                        = false; // get_content() arms WC's phpmailer hook for a send that never comes.
            $html                               = (string) \apply_filters( 'woocommerce_mail_content', $wc->style_inline( $content ) );
            if ( 'text/html' !== $wc->get_content_type() && 'multipart/alternative' !== $wc->get_content_type() ) {
                $html = '<pre>' . \esc_html( $html ) . '</pre>';
            }
            return [ 'subject' => (string) $wc->get_subject(), 'html' => $html ];
        } finally {
            $wc->sending = false;
            list( $wc->object, $wc->recipient, $wc->placeholders ) = $saved;
            $wc->restore_locale();
        }
    }

    /* ───────── AJAX ───────── */

    public function ajax_view() {
        // phpcs:disable WordPress.Security.NonceVerification -- verified just below.
        $event_id = isset( $_GET['event_id'] ) ? \absint( \wp_unslash( $_GET['event_id'] ) ) : 0;
        $seat_id  = isset( $_GET['seat_id'] ) ? \absint( \wp_unslash( $_GET['seat_id'] ) ) : 0;
        $key      = isset( $_GET['email'] ) ? \sanitize_text_field( (string) \wp_unslash( $_GET['email'] ) ) : '';
        // phpcs:enable
        if ( ! \check_ajax_referer( \Anchor_Mail_Log_Admin::NONCE, '_wpnonce', false ) || ! \current_user_can( Module::events_capability() ) ) {
            \status_header( 403 );
            \wp_die( \esc_html__( 'You are not allowed to view this email.', 'anchor-schema' ), '', [ 'response' => 403 ] );
        }
        $seat  = Roster::seat_belongs_to_event( $seat_id, $event_id ) ? $this->module->registrations->get_seat( $seat_id ) : null;
        $built = $seat ? $this->render( $event_id, $seat, $key ) : null;
        if ( ! $built ) {
            \status_header( 404 );
            \wp_die( \esc_html__( 'That email does not apply to this registrant.', 'anchor-schema' ), '', [ 'response' => 404 ] );
        }
        \Anchor_Mail_Log_Admin::serve_sandboxed( self::with_subject_line( $built['subject'], $built['html'] ) );
    }

    /** Show the subject above the email body (inside <body> when there is one). */
    private static function with_subject_line( $subject, $html ) {
        $line = '<div style="font:13px/1.4 -apple-system,BlinkMacSystemFont,sans-serif;padding:10px 16px;background:#f3f4f6;color:#111;border-bottom:1px solid #d1d5db">'
            . '<strong>' . \esc_html__( 'Subject:', 'anchor-schema' ) . '</strong> '
            . \esc_html( \html_entity_decode( (string) $subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . '</div>';
        $html = (string) $html;
        if ( \preg_match( '/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
            $at = $m[0][1] + \strlen( $m[0][0] );
            return \substr( $html, 0, $at ) . $line . \substr( $html, $at );
        }
        return $line . $html;
    }
}
