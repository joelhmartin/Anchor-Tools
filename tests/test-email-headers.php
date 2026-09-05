<?php
/**
 * send_html_email() Content-Type header tests (Task 0.3).
 *
 * Lifecycle emails are full HTML but were being sent without a
 * `Content-Type: text/html` header, so clients rendered raw markup.
 * These tests assert the header is always present, exactly once, and
 * that caller-supplied headers (From / Reply-To / BCC / etc.) are never
 * clobbered.
 *
 * Wave 3 (WOO-D14, WOO-D15, REG-D6, REG-D37, REG-D41) extends the file with the
 * dispatch tri-state: every sender answers `sent | skipped | failed`, so a
 * deliberate switch-off is never logged as a failure and a no-op is never
 * counted as a send.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Events_Log;
use Anchor\Events\Outcome;
use Anchor\Events\Registrations;

/** Thrown from the wp_redirect filter so a handler's exit() never runs. */
class Anchor_Dispatch_Redirected extends \Exception {}

/**
 * @group email
 */
class Test_Email_Headers extends Anchor_Events_TestCase {

	/** Captured `headers` arg from the most recent wp_mail() call. */
	private $captured_headers;

	/** Every wp_mail() attempt made during the test (the raw $atts array). */
	private $mails = [];

	/** When true, every wp_mail() reports failure — exactly as an SMTP blip does. */
	private $fail_mail = false;

	public function set_up() {
		parent::set_up();
		$this->captured_headers = null;
		$this->mails            = [];
		$this->fail_mail        = false;
		add_filter( 'wp_mail', [ $this, 'capture_wp_mail_args' ] );
		add_filter( 'pre_wp_mail', [ $this, 'record_or_fail_mail' ], 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'wp_mail', [ $this, 'capture_wp_mail_args' ] );
		remove_filter( 'pre_wp_mail', [ $this, 'record_or_fail_mail' ], 10 );
		delete_option( Module::OPTION_KEY );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	/**
	 * pre_wp_mail: record the attempt, then either let wp_mail run for real
	 * (null = no short-circuit, so the header tests above still see the
	 * `wp_mail` filter fire) or short-circuit with a delivery failure.
	 */
	public function record_or_fail_mail( $short_circuit, $atts ) {
		$this->mails[] = $atts;
		return $this->fail_mail ? false : $short_circuit;
	}

	/** The captured mails whose subject contains $needle. */
	private function mails_matching( $needle ) {
		return array_values(
			array_filter(
				$this->mails,
				static function ( $atts ) use ( $needle ) {
					return strpos( (string) $atts['subject'], $needle ) !== false;
				}
			)
		);
	}

	/** Merge overrides into the stored settings (defaults for everything else). */
	private function set_settings( array $overrides ) {
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), $overrides ), false );
	}

	/** Turn one email type off for one event, the way the Emails builder does. */
	private function switch_email_off( $event_id, $type ) {
		update_post_meta( $event_id, '_anchor_event_email_off_' . $type, '1' );
	}

	/**
	 * Run an admin-post handler and return the notice it redirected with.
	 * The handlers exit after redirecting, so the filter throws instead.
	 *
	 * @param callable $handler
	 * @return array{type:string,message:string,url:string}
	 */
	private function capture_redirect( callable $handler ) {
		$trap = static function ( $location ) {
			throw new Anchor_Dispatch_Redirected( (string) $location );
		};
		add_filter( 'wp_redirect', $trap );
		try {
			$handler();
			$this->fail( 'The handler did not redirect.' );
		} catch ( Anchor_Dispatch_Redirected $e ) {
			$url = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $trap );
		}

		$this->assertStringNotContainsString(
			'&amp;',
			$url,
			'An HTML-escaped separator in a Location header hides every argument after the first.'
		);

		$query = [];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return [
			'type'    => (string) ( $query['roster_type'] ?? '' ),
			'message' => rawurldecode( (string) ( $query['roster_msg'] ?? '' ) ),
			'url'     => $url,
		];
	}

	/** Call one of the WooCommerce integration's private senders. */
	private function invoke_wc( $method, array $args ) {
		$wc  = $this->woocommerce();
		$ref = new ReflectionMethod( get_class( $wc ), $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $wc, $args );
	}

	/** A paid event with one tier, synced to a managed variation. */
	private function paid_event() {
		$event_id = $this->make_event(
			[ 'title' => 'Tri-state Event', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers = $this->ticket_types()->get( $event_id );

		return [
			'event_id'     => $event_id,
			'variation_id' => $this->product_sync()->variation_for_tier( $event_id, $tiers[0]['id'] ),
		];
	}

	/**
	 * An unpaid order for one seat. Left in 'pending' on purpose: the
	 * 'processing' transition reconciles (and therefore sends) synchronously,
	 * so the test controls when that happens via place_order().
	 */
	private function make_order( $variation_id, $qty = 1 ) {
		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( $qty );
		$item->set_subtotal( 10 * $qty );
		$item->set_total( 10 * $qty );
		$attendees = [];
		for ( $i = 1; $i <= $qty; $i++ ) {
			$attendees[ $i ] = [ 'name' => 'Attendee ' . $i, 'email' => 'attendee' . $i . '@example.test' ];
		}
		$item->add_meta_data( '_anchor_attendees', $attendees, true );

		$order = new WC_Order();
		$order->add_item( $item );
		$order->set_billing_email( 'buyer@example.test' );
		$order->set_billing_first_name( 'Buyer' );
		$order->calculate_totals( false );
		$order->save();

		return $order;
	}

	/** Move the order to 'processing' — this is what creates the seats and sends. */
	private function place_order( \WC_Order $order ) {
		$order->set_status( 'processing' );
		$order->save();
	}

	/** The per-event customer emails-sent gate stored on an order. */
	private function customer_gate( $order_id ) {
		$order = wc_get_order( $order_id );
		$sent  = $order->get_meta( '_anchor_event_emails_sent' );
		return is_array( $sent ) ? $sent : [];
	}

	/** The needs-review reasons flagged on an order. */
	private function review_reasons( $order_id ) {
		$order  = wc_get_order( $order_id );
		$flags  = $order->get_meta( Events_Log::ORDER_REVIEW_META );
		$out    = [];
		foreach ( is_array( $flags ) ? $flags : [] as $flag ) {
			$out[] = is_array( $flag ) ? ( $flag['reason'] ?? '' ) : (string) $flag;
		}
		return $out;
	}

	/** The order sync-log messages, oldest first. */
	private function sync_log( $order_id ) {
		$order = wc_get_order( $order_id );
		$log   = $order->get_meta( Events_Log::ORDER_LOG_META );
		$out   = [];
		foreach ( is_array( $log ) ? $log : [] as $row ) {
			$out[] = is_array( $row ) ? (string) ( $row['message'] ?? '' ) : (string) $row;
		}
		return $out;
	}

	/** Whether any sync-log line contains $needle. */
	private function log_contains( $order_id, $needle ) {
		foreach ( $this->sync_log( $order_id ) as $line ) {
			if ( strpos( $line, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/** wp_mail filter callback: record the headers arg, then pass through unchanged. */
	public function capture_wp_mail_args( $args ) {
		$this->captured_headers = $args['headers'];
		return $args;
	}

	/** Normalize a string|array headers value to a single string for assertions. */
	private function headers_to_string( $headers ) {
		if ( is_array( $headers ) ) {
			return implode( "\n", $headers );
		}
		return (string) $headers;
	}

	/** No caller headers: the Content-Type header must still be added. */
	public function test_default_call_includes_html_content_type() {
		$sent = $this->module()->send_html_email( 'x@example.com', 'subj', '<b>hi</b>' );

		$this->assertTrue( $sent );
		$this->assertNotNull( $this->captured_headers, 'wp_mail was not invoked.' );

		$headers = $this->headers_to_string( $this->captured_headers );
		$this->assertStringContainsString( 'Content-Type: text/html', $headers );
	}

	/** Caller-supplied headers (e.g. Bcc) must survive alongside the Content-Type header. */
	public function test_caller_headers_are_preserved_alongside_content_type() {
		$sent = $this->module()->send_html_email(
			'x@example.com',
			's',
			'<b>h</b>',
			[ 'Bcc: boss@example.com' ]
		);

		$this->assertTrue( $sent );
		$this->assertNotNull( $this->captured_headers, 'wp_mail was not invoked.' );

		$headers = $this->headers_to_string( $this->captured_headers );
		$this->assertStringContainsString( 'Content-Type: text/html', $headers );
		$this->assertStringContainsString( 'Bcc: boss@example.com', $headers );
	}

	/** A caller-supplied Content-Type header must not be duplicated. */
	public function test_caller_supplied_content_type_is_not_duplicated() {
		$sent = $this->module()->send_html_email(
			'x@example.com',
			's',
			'<b>h</b>',
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);

		$this->assertTrue( $sent );
		$headers = $this->headers_to_string( $this->captured_headers );
		$this->assertSame( 1, substr_count( $headers, 'Content-Type:' ) );
	}

	/* ---------------------------------------------------------------------
	 * The tri-state itself
	 * ------------------------------------------------------------------- */

	/** Each state answers exactly one predicate, and carries its reason. */
	public function test_outcome_reports_exactly_one_of_three_states() {
		$sent    = Outcome::sent();
		$skipped = Outcome::skipped( 'disabled' );
		$failed  = Outcome::failed( 'wp_mail' );

		$this->assertTrue( $sent->is_sent() );
		$this->assertFalse( $sent->is_skipped() );
		$this->assertFalse( $sent->is_failed() );
		$this->assertSame( Outcome::SENT, $sent->status() );

		$this->assertTrue( $skipped->is_skipped() );
		$this->assertFalse( $skipped->is_sent() );
		$this->assertFalse( $skipped->is_failed() );
		$this->assertSame( 'disabled', $skipped->reason() );

		$this->assertTrue( $failed->is_failed() );
		$this->assertFalse( $failed->is_sent() );
		$this->assertSame( 'wp_mail', $failed->reason() );
		$this->assertSame( 'failed:wp_mail', (string) $failed );
		$this->assertSame( 'skipped:disabled', (string) $skipped );
	}

	/* ---------------------------------------------------------------------
	 * WOO-D15 / REG-D41 — a no-op is not a send
	 * ------------------------------------------------------------------- */

	/** No active seats: the confirmation is skipped, not reported as sent. */
	public function test_customer_confirmation_with_no_active_seats_is_skipped() {
		$this->require_wc();
		$order = new WC_Order();
		$order->set_billing_email( 'buyer@example.test' );
		$order->save();

		$result = $this->invoke_wc( 'send_customer_confirmation', [ $order, $this->module()->get_settings() ] );

		$this->assertInstanceOf( Outcome::class, $result );
		$this->assertTrue( $result->is_skipped(), 'Nothing to confirm is a skip, not a send.' );
		$this->assertSame( 'nothing_to_send', $result->reason() );
		$this->assertSame( [], $this->mails_matching( 'registration' ), 'Nothing may be mailed.' );
	}

	/** A skipped no-op must not stamp the per-event gate that suppresses later sends. */
	public function test_dispatch_does_not_stamp_the_gate_for_a_no_op() {
		$this->require_wc();
		$this->set_settings( [ 'wc_notify_customer' => true, 'wc_notify_organizer' => false ] );
		$event_id = $this->make_event( [ 'title' => 'Gate Event' ] );

		$order = new WC_Order();
		$order->set_billing_email( 'buyer@example.test' );
		$order->save();

		$log     = [];
		$flags   = [];
		$args    = [ $order, [ $event_id => [ 'confirmed' => 1 ] ], &$log, &$flags ];
		$dirty   = $this->invoke_wc( 'dispatch_emails', $args );

		$this->assertFalse( $dirty, 'A skip changes no gate, so there is nothing to persist.' );

		$sent = $order->get_meta( '_anchor_event_emails_sent' );
		$sent = is_array( $sent ) ? $sent : [];
		$this->assertArrayNotHasKey(
			'customer:' . $event_id,
			$sent,
			'Stamping the gate for a no-op is what makes the real confirmation unsendable for ever.'
		);
		$this->assertSame( [], $flags, 'A no-op is not a defect — nothing to review.' );

		$messages = array_map(
			static function ( $entry ) {
				return (string) ( $entry['message'] ?? '' );
			},
			$log
		);
		$this->assertNotEmpty( $messages );
		$this->assertStringContainsString( 'skipped', strtolower( implode( ' | ', $messages ) ) );
		$this->assertStringNotContainsString( 'email sent', strtolower( implode( ' | ', $messages ) ) );
	}

	/* ---------------------------------------------------------------------
	 * WOO-D14 / REG-D6 — a deliberate switch-off is not a failure
	 * ------------------------------------------------------------------- */

	/** Confirmation switched off for the event: skipped, gated, never flagged. */
	public function test_disabled_confirmation_is_skipped_not_flagged() {
		$this->require_wc();
		$this->set_settings( [ 'wc_notify_customer' => true, 'wc_notify_organizer' => false ] );
		$ctx = $this->paid_event();
		$this->switch_email_off( $ctx['event_id'], 'confirmation' );
		$order    = $this->make_order( $ctx['variation_id'] );
		$order_id = $order->get_id();

		$this->place_order( $order );

		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
		$this->assertNotContains(
			'customer_email_failed',
			$this->review_reasons( $order_id ),
			'An organizer switching the email off must never flag the order for review.'
		);
		$this->assertFalse(
			$this->log_contains( $order_id, 'FAILED' ),
			'A switched-off email is not a failed one.'
		);
		$this->assertTrue(
			$this->log_contains( $order_id, 'skipped' ),
			'The skip is what the log should say happened.'
		);
		$this->assertArrayHasKey(
			'customer:' . $ctx['event_id'],
			$this->customer_gate( $order_id ),
			'A deliberate switch-off is settled: the next reconcile must not re-decide it.'
		);
	}

	/** A genuine delivery failure still flags the order and leaves the gate open. */
	public function test_a_real_send_failure_still_flags_the_order() {
		$this->require_wc();
		$this->set_settings( [ 'wc_notify_customer' => true, 'wc_notify_organizer' => false ] );
		$ctx      = $this->paid_event();
		$order    = $this->make_order( $ctx['variation_id'] );
		$order_id = $order->get_id();

		$this->fail_mail = true;
		$this->place_order( $order );
		$this->fail_mail = false;

		$this->assertContains(
			'customer_email_failed',
			$this->review_reasons( $order_id ),
			'A wp_mail() failure is the one case that should raise the flag.'
		);
		$this->assertArrayNotHasKey(
			'customer:' . $ctx['event_id'],
			$this->customer_gate( $order_id ),
			'A failed send must leave the gate open so a later pass can try again.'
		);
	}

	/* ---------------------------------------------------------------------
	 * REG-D37 — a same-status write is a no-op, not a change
	 * ------------------------------------------------------------------- */

	/** update_status() distinguishes a change, a no-op and a rejection. */
	public function test_update_status_reports_a_no_op_distinctly_from_a_change() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED ] );
		$reg      = $this->registrations();

		$before = get_post_meta( $seat_id, '_anchor_event_history', true );

		$noop = $reg->update_status( $seat_id, Registrations::STATUS_CONFIRMED );
		$this->assertInstanceOf( Outcome::class, $noop );
		$this->assertTrue( $noop->is_skipped(), 'Asking for the status a seat already holds changes nothing.' );
		$this->assertSame( 'same_status', $noop->reason() );
		$this->assertSame(
			$before,
			get_post_meta( $seat_id, '_anchor_event_history', true ),
			'A no-op appends no history, so it cannot honestly be reported as a change.'
		);

		$noted = $reg->update_status( $seat_id, Registrations::STATUS_CONFIRMED, 'roster cancel' );
		$this->assertTrue(
			$noted->is_skipped(),
			'A note is recorded, but the status did not move — the roster passes a note on every click.'
		);

		$changed = $reg->update_status( $seat_id, Registrations::STATUS_CANCELLED );
		$this->assertTrue( $changed->is_sent(), 'A real transition is a change.' );

		$rejected = $reg->update_status( $seat_id, 'bogus' );
		$this->assertTrue( $rejected->is_failed(), 'An unknown status is rejected, not skipped.' );
		$this->assertSame( 'invalid_status', $rejected->reason() );

		// Refunded is terminal with no way out — the transition table's own rule.
		$this->assertTrue( $reg->update_status( $seat_id, Registrations::STATUS_CONFIRMED )->is_sent() );
		$this->assertTrue( $reg->update_status( $seat_id, Registrations::STATUS_REFUNDED )->is_sent() );
		$illegal = $reg->update_status( $seat_id, Registrations::STATUS_CONFIRMED );
		$this->assertTrue( $illegal->is_failed(), 'A forbidden transition is a rejection, not a no-op.' );
		$this->assertSame( 'illegal_transition', $illegal->reason() );
	}

	/**
	 * finding-8 — anchor_events_seat_status_changed must fire on a real
	 * transition and NEVER on a same-status call, note or no note. The
	 * roster's Cancel action passes a note on every click, so a click on an
	 * already-cancelled row reaches update_status() with $from === $to.
	 */
	public function test_seat_status_changed_action_fires_only_on_a_real_transition() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED ] );
		$reg      = $this->registrations();

		$fired = [];
		$spy   = function ( $sid, $from, $to, $actor ) use ( &$fired ) {
			$fired[] = [ $sid, $from, $to, $actor ];
		};
		add_action( 'anchor_events_seat_status_changed', $spy, 10, 4 );

		try {
			$reg->update_status( $seat_id, Registrations::STATUS_CONFIRMED );
			$this->assertSame( [], $fired, 'A same-status no-note call must never fire the action.' );

			$reg->update_status( $seat_id, Registrations::STATUS_CONFIRMED, 'roster cancel' );
			$this->assertSame( [], $fired, 'A same-status note-only call must never fire the action either.' );

			$reg->update_status( $seat_id, Registrations::STATUS_CANCELLED );
			$this->assertCount( 1, $fired, 'A real transition must fire the action exactly once.' );
			$this->assertSame( [ $seat_id, Registrations::STATUS_CONFIRMED, Registrations::STATUS_CANCELLED, 'system' ], $fired[0] );
		} finally {
			remove_action( 'anchor_events_seat_status_changed', $spy, 10 );
		}
	}

	/* ---------------------------------------------------------------------
	 * The tri-state composes with the Wave 3 retry queue (Task 25)
	 * ------------------------------------------------------------------- */

	/** Reminders switched off for the event: skipped, and no retry is queued. */
	public function test_disabled_reminder_is_skipped_and_queues_no_retry() {
		$event_id = $this->make_event( [ 'title' => 'Reminder Event' ] );
		update_post_meta( $event_id, '_anchor_event_start_ts', time() + 2 * DAY_IN_SECONDS );
		$seat_id = $this->make_seat( $event_id, [ 'email' => 'seat@example.test' ] );
		$this->switch_email_off( $event_id, 'reminder' );

		$seat   = $this->registrations()->get_seat( $seat_id );
		$result = $this->module()->send_reminder_email( $seat, $event_id, 1 );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'disabled', $result->reason() );
		$this->assertSame(
			'',
			get_post_meta( $seat_id, '_anchor_event_email_retry', true ),
			'A skip must not enqueue a retry — no hourly sweep can fix a setting.'
		);
	}

	/** A reminder that wp_mail() rejects is failed, and IS queued for retry. */
	public function test_failed_reminder_is_failed_and_queues_a_retry() {
		$event_id = $this->make_event( [ 'title' => 'Reminder Event' ] );
		update_post_meta( $event_id, '_anchor_event_start_ts', time() + 2 * DAY_IN_SECONDS );
		$seat_id = $this->make_seat( $event_id, [ 'email' => 'seat@example.test' ] );

		$this->fail_mail = true;
		$seat            = $this->registrations()->get_seat( $seat_id );
		$result          = $this->module()->send_reminder_email( $seat, $event_id, 1 );
		$this->fail_mail = false;

		$this->assertTrue( $result->is_failed() );
		$job = get_post_meta( $seat_id, '_anchor_event_email_retry', true );
		$this->assertIsArray( $job, 'Only a failure earns a retry.' );
		$this->assertSame( 'reminder', $job['type'] );
	}

	/** The sweep writes no markers at all for an event whose reminders are off. */
	public function test_reminder_sweep_marks_nothing_when_the_event_switched_reminders_off() {
		$this->set_settings( [ 'reminder_enabled' => true, 'reminder_offsets' => '7,1', 'organizer_roster_email' => false ] );
		$start_ts = time() + 12 * HOUR_IN_SECONDS;
		$event_id = $this->make_event(
			[
				'title'      => 'Quiet course',
				'timezone'   => 'UTC',
				'start_date' => gmdate( 'Y-m-d', $start_ts ),
				'start_time' => gmdate( 'H:i', $start_ts ),
			]
		);
		update_post_meta( $event_id, '_anchor_event_start_ts', $start_ts );
		$seat_id = $this->make_seat( $event_id, [ 'email' => 'quiet@example.test' ] );
		$this->switch_email_off( $event_id, 'reminder' );

		$this->module()->run_reminder_sweep();

		$this->assertSame( [], $this->mails_matching( 'coming up' ) );
		$this->assertSame(
			'',
			get_post_meta( $seat_id, '_anchor_event_reminders_sent', true ),
			'A switched-off reminder is reversible: burning the offsets would silence it for good.'
		);
	}

	/** The roster digest reports a deliberate switch-off as a skip. */
	public function test_disabled_roster_digest_is_skipped() {
		$event_id = $this->make_event( [ 'title' => 'Roster Event' ] );
		$this->switch_email_off( $event_id, 'roster' );

		$result = $this->module()->send_roster_email( $event_id );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'disabled', $result->reason() );
	}

	/** Cancellation switched off for the event: skipped, and no retry is queued. */
	public function test_disabled_cancellation_is_skipped_and_queues_no_retry() {
		$this->set_settings( [ 'notify_cancellation' => true ] );
		$event_id = $this->make_event( [ 'title' => 'Cancellation Event' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'bye@example.test' ] );
		$this->switch_email_off( $event_id, 'cancellation' );
		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CANCELLED );

		$result = $this->module()->send_cancellation_email( $seat_id );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'disabled', $result->reason() );
		$this->assertSame(
			'',
			get_post_meta( $seat_id, '_anchor_event_email_retry', true ),
			'A skip must not enqueue a retry.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Fix round 1 — the organizer notice answers the same tri-state
	 * ------------------------------------------------------------------- */

	/** A switched-off organizer notice is logged as a skip and settles its gate. */
	public function test_disabled_organizer_notice_is_skipped_not_flagged() {
		$this->require_wc();
		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => true, 'organizer_email' => 'organizer@example.org' ] );
		$ctx = $this->paid_event();
		$this->switch_email_off( $ctx['event_id'], 'confirmation' );
		$order    = $this->make_order( $ctx['variation_id'] );
		$order_id = $order->get_id();

		$this->place_order( $order );

		$this->assertTrue( $this->log_contains( $order_id, 'Organizer notice skipped.' ) );
		$this->assertFalse(
			$this->log_contains( $order_id, 'Organizer notice FAILED.' ),
			'A type the organizer switched off is not a failed send.'
		);
		$this->assertSame( [], $this->review_reasons( $order_id ) );
		$this->assertArrayHasKey(
			'organizer:' . $ctx['event_id'],
			$this->customer_gate( $order_id ),
			'The switch-off is settled — the next reconcile must not re-decide it.'
		);
	}

	/** A wp_mail() failure on the organizer notice still logs FAILED and leaves the gate open. */
	public function test_failed_organizer_notice_still_logs_failed() {
		$this->require_wc();
		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => true, 'organizer_email' => 'organizer@example.org' ] );
		$ctx      = $this->paid_event();
		$order    = $this->make_order( $ctx['variation_id'] );
		$order_id = $order->get_id();

		$this->fail_mail = true;
		$this->place_order( $order );
		$this->fail_mail = false;

		$this->assertTrue( $this->log_contains( $order_id, 'Organizer notice FAILED.' ) );
		$this->assertArrayNotHasKey(
			'organizer:' . $ctx['event_id'],
			$this->customer_gate( $order_id ),
			'A failed send must leave the gate open so a later pass can try again.'
		);
	}

	/* ---------------------------------------------------------------------
	 * finding-1 (carry-over) — each event resolves its OWN confirmation
	 * switch, so a disabled event never starves an enabled one
	 * ------------------------------------------------------------------- */

	/**
	 * A mixed order (confirmation disabled on event A, enabled on event B)
	 * used to never confirm B: the sender resolved a single "primary" event
	 * for the whole order and, once that primary was the disabled one,
	 * returned skipped('disabled') forever — B's open gate was never reached.
	 * Each event must now resolve its own switch: A settles as a deliberate
	 * skip, B actually sends.
	 */
	public function test_disabled_event_a_still_confirms_enabled_event_b() {
		$this->require_wc();
		// Place the order with every notification off, so the seats exist before
		// anything is decided about emails.
		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => false ] );
		$a     = $this->paid_event();
		$b     = $this->paid_event();
		$order = $this->make_order( $a['variation_id'] );

		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $b['variation_id'] ) );
		$item->set_quantity( 1 );
		$item->set_subtotal( 10 );
		$item->set_total( 10 );
		$item->add_meta_data( '_anchor_attendees', [ 1 => [ 'name' => 'B Attendee', 'email' => 'b@example.test' ] ], true );
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();
		$this->place_order( $order );

		$order_id = $order->get_id();
		$this->assertSame( 1, $this->count_seats( $a['event_id'], Registrations::STATUS_CONFIRMED ) );
		$this->assertSame( 1, $this->count_seats( $b['event_id'], Registrations::STATUS_CONFIRMED ) );

		// A is disabled, B stays enabled — A is seen FIRST in $email_events so
		// the old bug (a single primary resolved once) would have starved B.
		$by_event = $this->invoke_wc( 'collect_order_seats', [ $order_id ] );
		$ids      = array_map( 'intval', array_keys( $by_event ) );
		$this->assertCount( 2, $ids );
		$disabled = $ids[0];
		$enabled  = $ids[1];
		$this->switch_email_off( $disabled, 'confirmation' );

		$this->set_settings( [ 'wc_notify_customer' => true, 'wc_notify_organizer' => false ] );
		$fresh = wc_get_order( $order_id );
		$fresh->update_meta_data( '_anchor_event_emails_sent', [] );
		$fresh->save();
		$this->mails = []; // Isolate from WooCommerce's own order-status-change emails.

		$log   = [];
		$flags = [];
		$args  = [
			$fresh,
			[ $disabled => [ 'confirmed' => 1 ], $enabled => [ 'confirmed' => 1 ] ],
			&$log,
			&$flags,
		];
		$this->invoke_wc( 'dispatch_emails', $args );

		$sent = $fresh->get_meta( '_anchor_event_emails_sent' );
		$sent = is_array( $sent ) ? $sent : [];
		$this->assertArrayHasKey( 'customer:' . $disabled, $sent, 'The switched-off event is settled, not re-decided forever.' );
		$this->assertArrayHasKey(
			'customer:' . $enabled,
			$sent,
			'finding-1: the enabled event must confirm even though the disabled event is seen first.'
		);
		$this->assertSame( [], $flags );
		$this->assertCount(
			1,
			$this->mails,
			'B\'s buyer confirmation must actually be sent, not just gated (organizer notices are off in this test).'
		);
	}

	/** Two enabled events on the same order both get their own confirmation. */
	public function test_two_enabled_events_are_both_confirmed() {
		$this->require_wc();
		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => false ] );
		$a     = $this->paid_event();
		$b     = $this->paid_event();
		$order = $this->make_order( $a['variation_id'] );

		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $b['variation_id'] ) );
		$item->set_quantity( 1 );
		$item->set_subtotal( 10 );
		$item->set_total( 10 );
		$item->add_meta_data( '_anchor_attendees', [ 1 => [ 'name' => 'B Attendee', 'email' => 'b@example.test' ] ], true );
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();
		$this->place_order( $order );

		$order_id = $order->get_id();
		$by_event = $this->invoke_wc( 'collect_order_seats', [ $order_id ] );
		$ids      = array_map( 'intval', array_keys( $by_event ) );
		$this->assertCount( 2, $ids );

		$this->set_settings( [ 'wc_notify_customer' => true, 'wc_notify_organizer' => false ] );
		$fresh = wc_get_order( $order_id );
		$fresh->update_meta_data( '_anchor_event_emails_sent', [] );
		$fresh->save();
		$this->mails = []; // Isolate from WooCommerce's own order-status-change emails.

		$log   = [];
		$flags = [];
		$args  = [
			$fresh,
			[ $ids[0] => [ 'confirmed' => 1 ], $ids[1] => [ 'confirmed' => 1 ] ],
			&$log,
			&$flags,
		];
		$this->invoke_wc( 'dispatch_emails', $args );

		$sent = $fresh->get_meta( '_anchor_event_emails_sent' );
		$sent = is_array( $sent ) ? $sent : [];
		$this->assertArrayHasKey( 'customer:' . $ids[0], $sent, 'Both events must be covered.' );
		$this->assertArrayHasKey( 'customer:' . $ids[1], $sent, 'Both events must be covered.' );
		$this->assertSame( [], $flags );
		$this->assertCount(
			2,
			$this->mails,
			'Each enabled event gets its own confirmation email (organizer notices are off in this test).'
		);
	}

	/* ---------------------------------------------------------------------
	 * Fix round 1 — the remaining caller-side wording
	 * ------------------------------------------------------------------- */

	/** A seat with no address is recorded as superseded (0), not left to retry every sweep. */
	public function test_sweep_marks_a_seat_with_no_address_as_superseded() {
		$this->set_settings( [ 'reminder_enabled' => true, 'reminder_offsets' => '7,1', 'organizer_roster_email' => false ] );
		$start_ts = time() + 12 * HOUR_IN_SECONDS;
		$event_id = $this->make_event(
			[
				'title'      => 'No-address course',
				'timezone'   => 'UTC',
				'start_date' => gmdate( 'Y-m-d', $start_ts ),
				'start_time' => gmdate( 'H:i', $start_ts ),
			]
		);
		update_post_meta( $event_id, '_anchor_event_start_ts', $start_ts );
		$seat_id = $this->make_seat( $event_id, [ 'email' => '' ] );

		$this->module()->run_reminder_sweep();

		$markers = get_post_meta( $seat_id, '_anchor_event_reminders_sent', true );
		$this->assertIsArray( $markers );
		$this->assertArrayHasKey( $start_ts, $markers );
		$this->assertArrayHasKey( 1, $markers[ $start_ts ], 'The offset must be recorded, or every sweep re-attempts it.' );
		$this->assertSame( 0, (int) $markers[ $start_ts ][1], 'Superseded is 0 — recorded, never reported as sent.' );
		$this->assertSame(
			'',
			get_post_meta( $seat_id, '_anchor_event_email_retry', true ),
			'A skip earns no retry job.'
		);
	}

	/** "Resend confirmation" on an order with nothing active logs a skip, not a send. */
	public function test_resend_on_an_order_with_nothing_active_logs_a_skip() {
		$this->require_wc();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$order = new WC_Order();
		$order->set_billing_email( 'buyer@example.test' );
		$order->save();
		$order_id = $order->get_id();

		$_POST    = [ 'order_id' => $order_id, '_wpnonce' => wp_create_nonce( 'anchor_events_resend_' . $order_id ) ];
		$_REQUEST = $_POST;

		$this->capture_redirect(
			function () {
				$this->woocommerce()->handle_resend_confirmation();
			}
		);

		$this->assertTrue( $this->log_contains( $order_id, 'Customer confirmation re-send skipped.' ) );
		$this->assertFalse(
			$this->log_contains( $order_id, 're-sent (manual)' ),
			'A fully refunded order has nothing to confirm — the log must not claim a send.'
		);
		$this->assertSame( [], $this->review_reasons( $order_id ), 'Nothing went wrong, so nothing needs review.' );
	}

	/**
	 * "Resend confirmation" on a mixed order (one event's confirmation
	 * switched off, one left on) must resend only the enabled event,
	 * while still settling and logging BOTH — the manual resend path
	 * follows the same per-event rule dispatch_emails() does (finding-1),
	 * so it needed its own direct coverage of handle_resend_confirmation()
	 * rather than only the reconcile-path tests above.
	 */
	public function test_resend_on_a_mixed_order_sends_only_the_enabled_events_confirmation() {
		$this->require_wc();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => false ] );
		$a     = $this->paid_event();
		$b     = $this->paid_event();
		$order = $this->make_order( $a['variation_id'] );

		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $b['variation_id'] ) );
		$item->set_quantity( 1 );
		$item->set_subtotal( 10 );
		$item->set_total( 10 );
		$item->add_meta_data( '_anchor_attendees', [ 1 => [ 'name' => 'B Attendee', 'email' => 'b@example.test' ] ], true );
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();
		$this->place_order( $order );

		$order_id = $order->get_id();
		$this->assertSame( 1, $this->count_seats( $a['event_id'], Registrations::STATUS_CONFIRMED ) );
		$this->assertSame( 1, $this->count_seats( $b['event_id'], Registrations::STATUS_CONFIRMED ) );

		$disabled = (int) $a['event_id'];
		$enabled  = (int) $b['event_id'];
		$this->switch_email_off( $disabled, 'confirmation' );

		$this->mails = []; // Isolate from WooCommerce's own order-status-change emails.

		$_POST    = [ 'order_id' => $order_id, '_wpnonce' => wp_create_nonce( 'anchor_events_resend_' . $order_id ) ];
		$_REQUEST = $_POST;

		$this->capture_redirect(
			function () {
				$this->woocommerce()->handle_resend_confirmation();
			}
		);

		$this->assertTrue(
			$this->log_contains( $order_id, 'Customer confirmation re-send skipped.' ),
			'The disabled event must log a skip.'
		);
		$this->assertTrue(
			$this->log_contains( $order_id, 'Customer confirmation re-sent (manual).' ),
			'The enabled event must log a send.'
		);

		$gate = $this->customer_gate( $order_id );
		$this->assertArrayHasKey( 'customer:' . $disabled, $gate, 'The disabled event still settles its own gate.' );
		$this->assertArrayHasKey( 'customer:' . $enabled, $gate, 'The enabled event stamps its own gate.' );

		$this->assertCount( 1, $this->mails, 'Only the enabled event actually sends a confirmation.' );
		$this->assertSame( [], $this->review_reasons( $order_id ), 'Neither outcome is a defect.' );
	}

	/**
	 * CodeRabbit finding-4 (PR #20, 2nd round): the save at the end of
	 * handle_resend_confirmation() used to be gated on $any_sent alone — an
	 * order whose events ALL resolve to 'disabled' stamps every gate in
	 * $sent (settled, same as a real send — audit REG-D6) but never
	 * actually sends anything, so $any_sent stays false and the save never
	 * runs. Every gate stamp was silently discarded, and the next automatic
	 * reconcile pass re-attempted the exact same disabled sends. Both gates
	 * must persist even though nothing was ever sent.
	 */
	public function test_resend_on_an_all_disabled_order_still_persists_the_gate_stamps() {
		$this->require_wc();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => false ] );
		$a     = $this->paid_event();
		$b     = $this->paid_event();
		$order = $this->make_order( $a['variation_id'] );

		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $b['variation_id'] ) );
		$item->set_quantity( 1 );
		$item->set_subtotal( 10 );
		$item->set_total( 10 );
		$item->add_meta_data( '_anchor_attendees', [ 1 => [ 'name' => 'B Attendee', 'email' => 'b@example.test' ] ], true );
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();
		$this->place_order( $order );

		$order_id = $order->get_id();
		$this->assertSame( 1, $this->count_seats( $a['event_id'], Registrations::STATUS_CONFIRMED ) );
		$this->assertSame( 1, $this->count_seats( $b['event_id'], Registrations::STATUS_CONFIRMED ) );

		$this->switch_email_off( (int) $a['event_id'], 'confirmation' );
		$this->switch_email_off( (int) $b['event_id'], 'confirmation' );

		$this->mails = []; // Isolate from WooCommerce's own order-status-change emails.

		$_POST    = [ 'order_id' => $order_id, '_wpnonce' => wp_create_nonce( 'anchor_events_resend_' . $order_id ) ];
		$_REQUEST = $_POST;

		$this->capture_redirect(
			function () {
				$this->woocommerce()->handle_resend_confirmation();
			}
		);

		$this->assertCount( 0, $this->mails, 'Precondition: nothing was actually sent — every event is disabled.' );

		$gate = $this->customer_gate( $order_id );
		$this->assertArrayHasKey(
			'customer:' . (int) $a['event_id'],
			$gate,
			'A disabled event still settles its own gate even when no send in this pass succeeded at all.'
		);
		$this->assertArrayHasKey(
			'customer:' . (int) $b['event_id'],
			$gate,
			'A disabled event still settles its own gate even when no send in this pass succeeded at all.'
		);
	}

	/** Roster cancel: changed, already-cancelled and rejected each get their own notice. */
	public function test_roster_cancel_notice_says_which_of_the_three_happened() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat( $event_id );

		$cancel = function () use ( $event_id, $seat_id ) {
			$_REQUEST = [
				'event_id' => $event_id,
				'seat_id'  => $seat_id,
				'_wpnonce' => wp_create_nonce( 'anchor_roster_cancel_' . $event_id ),
			];
			$_POST = $_REQUEST;
			return $this->capture_redirect(
				function () {
					$this->module()->roster->handle_cancel();
				}
			);
		};

		$changed = $cancel();
		$this->assertSame( 'success', $changed['type'] );
		$this->assertSame( 'Seat cancelled.', $changed['message'] );

		$noop = $cancel();
		$this->assertSame( 'success', $noop['type'], 'Nothing went wrong — it was already cancelled.' );
		$this->assertStringContainsString( 'already cancelled', $noop['message'] );
		$this->assertStringNotContainsString(
			'Seat cancelled.',
			$noop['message'],
			'Reporting a cancellation that did not happen is the defect (audit REG-D37).'
		);

		// Refunded is terminal with no way out, so the cancel is rejected.
		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CONFIRMED );
		$this->registrations()->update_status( $seat_id, Registrations::STATUS_REFUNDED );
		$rejected = $cancel();
		$this->assertSame( 'error', $rejected['type'] );
		$this->assertStringContainsString( 'Could not cancel', $rejected['message'] );
	}

	/** A switched-off roster digest is not an error the operator should hunt in a log. */
	public function test_roster_send_skip_uses_the_ordinary_notice_channel() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$event_id = $this->make_event();
		$this->switch_email_off( $event_id, 'roster' );

		$_POST    = [ 'event_id' => $event_id, '_wpnonce' => wp_create_nonce( 'anchor_events_send_roster_' . $event_id ) ];
		$_REQUEST = $_POST;

		$notice = $this->capture_redirect(
			function () {
				$this->module()->roster->handle_send_roster();
			}
		);

		$this->assertSame( 'success', $notice['type'], 'Nothing went wrong, so this is not the error channel.' );
		$this->assertStringContainsString( 'switched off', $notice['message'] );
	}
}
