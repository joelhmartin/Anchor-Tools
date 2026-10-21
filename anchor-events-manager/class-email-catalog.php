<?php
/**
 * The per-registrant "Emails" panel: one row per email this event has for a seat.
 *
 * Rows, in order: Order receipt (WooCommerce seats only), Registration
 * confirmation, one Reminder per configured offset, and Cancellation notice
 * (cancelled/refunded seats only). Each row says whether THIS system has sent
 * that email (a live or resend mail-log row; imported history from before the
 * Events Manager, and the scheduler's own markers, never count), its
 * delivery/open/click state from that send, a View link to the email as it
 * would be sent now (Email_Preview, never a stored copy), and a Send button
 * that runs the module's own sender for today's version of the email.
 *
 * Built on the generic mail log (Anchor_Mail_Log::query(), the arm-then-confirm
 * script and its CSS); everything events-specific lives here.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-email-preview.php';

class Email_Catalog {

    const AJAX_ACTION = 'anchor_events_email_send';

    /** Log kinds that count as each catalog row having been sent. */
    const RECEIPT_KINDS      = [ 'wc.customer_processing_order', 'wc.customer_completed_order' ];
    const CONFIRMATION_KINDS = [ 'events.confirmation', 'events.wc_confirmation' ];
    const CANCELLATION_KINDS = [ 'events.cancellation' ];
    const REMINDER_KIND      = 'events.reminder:';

    /** Mail-log sources that are sends by this system. 'import' (WP Mail Logging backfill) is not. */
    const OWN_SOURCES = [ 'live', 'resend' ];

    /** The WooCommerce email the Order receipt row sends. */
    const WC_RECEIPT_EMAIL = 'WC_Email_Customer_Processing_Order';

    /** @var Module */
    private $module;

    /** @var Email_Preview The View endpoint. */
    public $preview;

    public function __construct( Module $module ) {
        $this->module  = $module;
        $this->preview = new Email_Preview( $module );
        \add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_send' ] );
    }

    /* ───────── Catalog ───────── */

    /**
     * Every email this event has for one seat, with its sent state.
     *
     * @param int   $event_id
     * @param array $seat Seat DTO (Registrations::get_seat()).
     * @return array[] key, label, off (reason text, '' when on), sent (log row|null),
     *                 failed (log row|null). Only this system's own sends count.
     */
    public function entries( $event_id, array $seat ) {
        $event_id = (int) $event_id;
        $seat_id  = (int) $seat['id'];
        $order_id = (int) ( $seat['order_id'] ?? 0 );
        $logged   = \class_exists( '\Anchor_Mail_Log' ) ? \Anchor_Mail_Log::query( [
            'seat_or_order' => [ $seat_id, $order_id, $event_id ],
            'audience'      => 'attendee',
        ] ) : [];
        $logged   = \array_values( \array_filter( $logged, static function ( $row ) {
            return \in_array( (string) ( $row['source'] ?? '' ), self::OWN_SOURCES, true );
        } ) );
        $settings = $this->module->get_settings();
        $external = $this->module->uses_external_signup( $event_id );
        $out      = [];

        if ( $order_id > 0 ) {
            $wc    = self::wc_receipt_email();
            $out[] = $this->entry( 'receipt', \__( 'Order receipt', 'anchor-schema' ), $logged, self::RECEIPT_KINDS,
                $this->receipt_off_reason( $order_id, $wc ) );
        }

        $out[] = $this->entry( 'confirmation', \__( 'Registration confirmation', 'anchor-schema' ), $logged, self::CONFIRMATION_KINDS,
            $this->seat_off_reason( $seat ) ?: $this->off_reason( $event_id, 'confirmation', $external, ! empty( $settings['notify_user'] ) ) );

        $off = $this->seat_off_reason( $seat ) ?: $this->off_reason( $event_id, 'reminder', $external, true );
        foreach ( $this->module->effective_offsets( $event_id, $settings ) as $offset ) {
            $out[] = $this->entry( 'reminder:' . $offset, self::reminder_label( $offset ), $logged, [ self::REMINDER_KIND . $offset ], $off );
        }

        if ( \in_array( (string) ( $seat['status'] ?? '' ), Registrations::TERMINAL_STATUSES, true ) ) {
            $out[] = $this->entry( 'cancellation', \__( 'Cancellation notice', 'anchor-schema' ), $logged, self::CANCELLATION_KINDS,
                $this->off_reason( $event_id, 'cancellation', $external, ! empty( $settings['notify_cancellation'] ) ) );
        }
        return $out;
    }

    /** "Reminder · 7 days before" / "Reminder · 1 day before". */
    public static function reminder_label( $offset ) {
        $offset = (int) $offset;
        return \sprintf(
            /* translators: %s: "7 days before" */
            \__( 'Reminder · %s', 'anchor-schema' ),
            \sprintf(
                /* translators: %d: whole days before the event starts */
                \_n( '%d day before', '%d days before', $offset, 'anchor-schema' ),
                $offset
            )
        );
    }

    /** @param array[] $logged Rows newest first. */
    private function entry( $key, $label, array $logged, array $kinds, $off ) {
        $sent   = null;
        $failed = null;
        foreach ( $logged as $row ) {
            if ( ! \in_array( (string) $row['kind'], $kinds, true ) ) {
                continue;
            }
            if ( 'failed' === $row['status'] ) {
                $failed = $failed ? $failed : $row;
                continue;
            }
            $sent = $row;
            break;
        }
        return [
            'key'       => (string) $key,
            'label'     => (string) $label,
            'off'       => (string) $off,
            'sent'      => $sent,
            'failed'    => $failed,
        ];
    }

    /**
     * Confirmation and reminder mail tells the attendee they hold a seat (and the
     * reminder carries the join link), so it only goes to a confirmed seat.
     */
    private function seat_off_reason( array $seat ) {
        $status = (string) ( $seat['status'] ?? '' );
        if ( Registrations::STATUS_CONFIRMED === $status ) {
            return '';
        }
        /* translators: %s: seat status such as "cancelled" or "waitlist" */
        return \sprintf( \__( 'Seat is %s', 'anchor-schema' ), '' === $status ? \__( 'unknown', 'anchor-schema' ) : $status );
    }

    /** The WooCommerce receipt says "your order is being processed": only for processing/completed orders. */
    private function receipt_off_reason( $order_id, $wc ) {
        $order = \function_exists( 'wc_get_order' ) ? \wc_get_order( $order_id ) : null;
        if ( $order && ! $order->has_status( [ 'processing', 'completed' ] ) ) {
            /* translators: %s: WooCommerce order status such as "refunded" */
            return \sprintf( \__( 'Order is %s', 'anchor-schema' ), $order->get_status() );
        }
        return ( $wc && $wc->is_enabled() ) ? '' : \__( 'Off in WooCommerce', 'anchor-schema' );
    }

    /** Why an email type will not send for this event ('' = it will). */
    private function off_reason( $event_id, $type, $external, $site_on ) {
        if ( $external || ! $this->module->is_email_enabled( $event_id, $type ) ) {
            return \__( 'Off for this event', 'anchor-schema' );
        }
        return $site_on ? '' : \__( 'Off for this site', 'anchor-schema' );
    }

    /** @return \WC_Email|null The WooCommerce email the Order receipt row sends and previews. */
    public static function wc_receipt_email() {
        if ( ! \function_exists( 'WC' ) || ! \is_object( \WC() ) ) {
            return null;
        }
        $emails = \WC()->mailer()->get_emails();
        return isset( $emails[ self::WC_RECEIPT_EMAIL ] ) ? $emails[ self::WC_RECEIPT_EMAIL ] : null;
    }

    /* ───────── Rendering ───────── */

    /** The panel for a seat ('' when the seat is not in the event). */
    public function panel_for_seat( $event_id, $seat_id ) {
        $event_id = (int) $event_id;
        $seat     = Roster::seat_belongs_to_event( (int) $seat_id, $event_id ) ? $this->module->registrations->get_seat( (int) $seat_id ) : null;
        if ( ! $seat || ! \class_exists( '\Anchor_Mail_Log_Admin' ) ) {
            return '';
        }
        \Anchor_Mail_Log_Admin::enqueue();

        $out  = '<section class="anchor-mail-log anchor-email-catalog">';
        $out .= '<h3 class="anchor-mail-log__title">' . \esc_html__( 'Emails', 'anchor-schema' ) . '</h3>';
        $out .= '<div class="anchor-mail-log__scroll"><table class="anchor-mail-log__table"><thead><tr>';
        foreach ( [ \__( 'Email', 'anchor-schema' ), \__( 'Status', 'anchor-schema' ), \__( 'Delivered', 'anchor-schema' ), \__( 'Opened', 'anchor-schema' ), \__( 'Clicked', 'anchor-schema' ) ] as $h ) {
            $out .= '<th>' . \esc_html( $h ) . '</th>';
        }
        $out .= '<th><span class="screen-reader-text">' . \esc_html__( 'Actions', 'anchor-schema' ) . '</span></th></tr></thead><tbody>';
        foreach ( $this->entries( $event_id, $seat ) as $entry ) {
            $out .= $this->render_row( $event_id, (int) $seat['id'], $entry );
        }
        return $out . '</tbody></table></div></section>';
    }

    public function render_row( $event_id, $seat_id, array $entry ) {
        $sent = $entry['sent'];
        if ( $sent ) {
            $status = \sprintf( /* translators: %s: date and time */ \__( 'Sent %s', 'anchor-schema' ), \Anchor_Mail_Log_Admin::when( $sent['created_at'] ) );
        } elseif ( $entry['failed'] ) {
            $status = \sprintf( /* translators: %s: date and time */ \__( 'Failed %s', 'anchor-schema' ), \Anchor_Mail_Log_Admin::when( $entry['failed']['created_at'] ) );
        } else {
            $status = \__( 'Not sent', 'anchor-schema' );
        }
        [ $delivered, $badge ] = $sent ? \Anchor_Mail_Log_Admin::delivery( $sent ) : [ '—', '' ];
        $delivered_html        = '' === $badge ? \esc_html( $delivered )
            : '<span class="anchor-mail-log__badge anchor-mail-log__badge--' . \esc_attr( $badge ) . '" title="' . \esc_attr( (string) $sent['status_detail'] ) . '">' . \esc_html( $delivered ) . '</span>';

        $email = '<strong>' . \esc_html( $entry['label'] ) . '</strong>';
        if ( $sent && '' !== (string) $sent['recipient'] ) {
            $email .= '<br><span class="anchor-mail-log__to">' . \esc_html( $sent['recipient'] ) . '</span>';
        }

        $actions = '<a href="' . \esc_url( Email_Preview::url( $event_id, $seat_id, $entry['key'] ) ) . '" target="_blank" rel="noopener" title="' . \esc_attr__( 'This email as it would be sent to this registrant now', 'anchor-schema' ) . '">' . \esc_html__( 'View', 'anchor-schema' ) . '</a>';
        if ( '' !== $entry['off'] ) {
            $actions .= '<span class="anchor-mail-log__off">' . \esc_html( $entry['off'] ) . '</span>';
        } else {
            $args     = [ 'event_id' => (int) $event_id, 'seat_id' => (int) $seat_id, 'email' => $entry['key'] ];
            $label    = $sent ? \__( 'Send again', 'anchor-schema' ) : \__( 'Send', 'anchor-schema' );
            $actions .= '<button type="button" class="button anchor-mail-log__send" data-mail-action="' . \esc_attr( self::AJAX_ACTION ) . '" data-mail-args="' . \esc_attr( (string) \wp_json_encode( $args ) ) . '">' . \esc_html( $label ) . '</button>';
        }
        $actions .= '<span class="anchor-mail-log__msg" role="status"></span>';

        return '<tr class="anchor-mail-log__row" data-mail-row="' . \esc_attr( $entry['key'] ) . '">'
            . '<td>' . $email . '</td>'
            . '<td>' . \esc_html( $status ) . '</td>'
            . '<td>' . $delivered_html . '</td>'
            . '<td>' . \esc_html( $sent ? \Anchor_Mail_Log_Admin::engagement( $sent['opened_at'], $sent['open_count'] ) : '—' ) . '</td>'
            . '<td>' . \esc_html( $sent ? \Anchor_Mail_Log_Admin::engagement( $sent['clicked_at'], $sent['click_count'] ) : '—' ) . '</td>'
            . '<td class="anchor-mail-log__actions">' . $actions . '</td></tr>';
    }

    /* ───────── Sending ───────── */

    /**
     * Send one catalog email now, built from the event's current settings.
     *
     * @return Outcome
     */
    public function send( $event_id, array $seat, $key ) {
        $event_id = (int) $event_id;
        if ( 'receipt' === $key ) {
            return $this->send_receipt( (int) $seat['order_id'] );
        }
        if ( 'confirmation' === $key ) {
            return $this->module->send_confirmation_email(
                $event_id,
                (string) $seat['name'],
                (string) $seat['email'],
                (string) $seat['status'],
                (int) ( $seat['guests'] ?? 0 ),
                (int) $seat['id']
            );
        }
        if ( 'cancellation' === $key ) {
            return $this->send_cancellation( (int) $seat['id'] );
        }
        if ( \preg_match( '/^reminder:(\d+)$/', (string) $key, $m ) ) {
            return $this->send_reminder( $event_id, $seat, (int) $m[1] );
        }
        return Outcome::skipped( 'unknown_email' );
    }

    /**
     * WooCommerce's own processing-order email for the order. trigger() only
     * renders and mails (WC 10.9+ also adds its usual private "email sent" order
     * note); it changes no status or stock. Email_Log::wc_begin() logs it.
     */
    private function send_receipt( $order_id ) {
        $wc = self::wc_receipt_email();
        if ( ! $wc || $order_id <= 0 || ! \wc_get_order( $order_id ) ) {
            return Outcome::skipped( 'no_order' );
        }
        $sent  = null;
        $watch = static function ( $ok, $id = '' ) use ( &$sent ) {
            if ( 'customer_processing_order' === $id ) {
                $sent = (bool) $ok;
            }
        };
        \add_action( 'woocommerce_email_sent', $watch, 10, 2 );
        try {
            $wc->trigger( $order_id );
        } finally {
            \remove_action( 'woocommerce_email_sent', $watch, 10 );
        }
        if ( null === $sent ) {
            return Outcome::skipped( $wc->is_enabled() ? 'no_address' : 'disabled' );
        }
        return Outcome::from_bool( $sent, 'wp_mail' );
    }

    /**
     * An on-demand reminder leaves the scheduler's bookkeeping as it found it: a
     * failure queues no retry, and a send for an offset that is not yet due marks
     * nothing, so the scheduled reminder still goes out on time. A successful send
     * for an offset whose window is open now IS that reminder, so it is marked
     * sent (and any retry job for it is satisfied); otherwise the next hourly
     * sweep would mail it a second time.
     */
    private function send_reminder( $event_id, array $seat, $offset ) {
        $settings = $this->module->get_settings();
        if ( ! \in_array( $offset, $this->module->effective_offsets( $event_id, $settings ), true ) ) {
            return Outcome::skipped( 'unknown_email' );
        }
        $seat_id = (int) $seat['id'];
        $start   = (int) ( $this->module->get_meta( $event_id )['start_ts'] ?? 0 );
        $job     = \get_post_meta( $seat_id, Registrations::META_EMAIL_RETRY, true );
        $result  = $this->module->send_reminder_email( $seat, $event_id, $offset, $settings );
        $now     = \time();
        $due     = $start > 0 && ( $start - $offset * DAY_IN_SECONDS ) <= $now && $now < $start;
        $own_job = \is_array( $job ) && 'reminder' === ( $job['type'] ?? '' )
            && (int) ( $job['offset'] ?? 0 ) === $offset && (int) ( $job['start_ts'] ?? 0 ) === $start;

        if ( ! ( $result->is_sent() && $own_job ) ) {
            self::restore_meta( $seat_id, Registrations::META_EMAIL_RETRY, $job );
        }
        if ( $result->is_sent() && $due && (int) ( $this->module->reminder_markers( $seat_id, $start )[ $offset ] ?? 0 ) <= 0 ) {
            $this->module->mark_reminder_sent( $seat_id, $start, $offset, $now );
        }
        return $result;
    }

    /**
     * send_cancellation_email() refuses a seat already emailed about this
     * cancellation; an on-demand send clears that marker so it really sends (the
     * sender writes it again). If it does not send, the earlier marker and retry
     * state are put back so nothing is queued or re-sent behind the user's back.
     */
    private function send_cancellation( $seat_id ) {
        $prev = (int) \get_post_meta( $seat_id, Registrations::META_CANCEL_EMAILED, true );
        $job  = \get_post_meta( $seat_id, Registrations::META_EMAIL_RETRY, true );
        \delete_post_meta( $seat_id, Registrations::META_CANCEL_EMAILED );
        $result = $this->module->send_cancellation_email( $seat_id );
        if ( ! $result->is_sent() && $prev > 0 ) {
            \update_post_meta( $seat_id, Registrations::META_CANCEL_EMAILED, $prev );
            self::restore_meta( $seat_id, Registrations::META_EMAIL_RETRY, $job );
        }
        return $result;
    }

    private static function restore_meta( $post_id, $key, $value ) {
        if ( '' === $value || null === $value || false === $value ) {
            \delete_post_meta( $post_id, $key );
        } else {
            \update_post_meta( $post_id, $key, $value );
        }
    }

    /* ───────── AJAX ───────── */

    public function ajax_send() {
        // phpcs:disable WordPress.Security.NonceVerification -- verified just below.
        $event_id = isset( $_POST['event_id'] ) ? \absint( \wp_unslash( $_POST['event_id'] ) ) : 0;
        $seat_id  = isset( $_POST['seat_id'] ) ? \absint( \wp_unslash( $_POST['seat_id'] ) ) : 0;
        $key      = isset( $_POST['email'] ) ? (string) \wp_unslash( $_POST['email'] ) : '';
        // phpcs:enable
        if ( ! \check_ajax_referer( \Anchor_Mail_Log_Admin::NONCE, 'nonce', false ) || ! \current_user_can( Module::events_capability() ) ) {
            \wp_send_json_error( [ 'message' => \__( 'You are not allowed to do that.', 'anchor-schema' ) ], 403 );
        }
        $seat = Roster::seat_belongs_to_event( $seat_id, $event_id ) ? $this->module->registrations->get_seat( $seat_id ) : null;
        if ( ! $seat ) {
            \wp_send_json_error( [ 'message' => \__( 'That registrant is not on this event.', 'anchor-schema' ) ], 404 );
        }
        $entry = $this->find_entry( $event_id, $seat, $key );
        if ( ! $entry || '' !== $entry['off'] ) {
            \wp_send_json_error( [ 'message' => $entry ? $entry['off'] : \__( 'That email does not apply to this registrant.', 'anchor-schema' ) ], 400 );
        }

        $result = $this->send( $event_id, $seat, $key );
        $entry  = $this->find_entry( $event_id, $this->module->registrations->get_seat( $seat_id ), $key );
        $html   = $entry ? $this->render_row( $event_id, $seat_id, $entry ) : '';
        if ( $result->is_sent() ) {
            \wp_send_json_success( [ 'row_html' => $html, 'message' => \__( 'Sent', 'anchor-schema' ) ] );
        }
        \wp_send_json_error( [ 'row_html' => $html, 'message' => self::refusal( $result ) ], 502 );
    }

    private function find_entry( $event_id, $seat, $key ) {
        foreach ( \is_array( $seat ) ? $this->entries( $event_id, $seat ) : [] as $entry ) {
            if ( $entry['key'] === $key ) {
                return $entry;
            }
        }
        return null;
    }

    private static function refusal( Outcome $result ) {
        $messages = [
            'disabled'          => \__( 'This email is off for this event.', 'anchor-schema' ),
            'notifications_off' => \__( 'This email is off for this site.', 'anchor-schema' ),
            'no_address'        => \__( 'This registrant has no email address.', 'anchor-schema' ),
            'external_signup'   => \__( 'This event takes sign-ups on an external form.', 'anchor-schema' ),
        ];
        return $messages[ $result->reason() ] ?? \__( 'The email could not be sent.', 'anchor-schema' );
    }
}
