<?php
/**
 * The per-registrant Emails panel (Email_Catalog): one row per email the event
 * has for a seat, its sent state, and on-demand Send.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Email_Catalog;
use Anchor\Events\Module;
use Anchor\Events\Registrations;

/**
 * @group email
 * @group mail-log
 */
class Test_Events_Email_Catalog extends WP_Ajax_UnitTestCase {

	use Anchor_Events_Fixtures;

	public function set_up() {
		parent::set_up();
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		Anchor_Mail_Log::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Anchor_Mail_Log::table() ); // phpcs:ignore
		add_filter( 'pre_wp_mail', [ $this, 'fake_send' ], 10, 2 );
		$this->_setRole( 'administrator' );
		$_POST = [];
	}

	public function tear_down() {
		remove_all_filters( 'pre_wp_mail' );
		delete_option( Module::OPTION_KEY );
		$_POST = [];
		parent::tear_down();
	}

	/** Run the capture filter as wp_mail() would, then pretend it sent. */
	public function fake_send( $short, $atts ) {
		apply_filters( 'wp_mail', $atts );
		return true;
	}

	private function catalog() {
		return $this->module()->email_log->catalog;
	}

	private function event( $start_in = 10 * DAY_IN_SECONDS ) {
		$start    = time() + $start_in;
		$event_id = $this->make_event( [
			'title'            => 'Catalog Event',
			'timezone'         => 'UTC',
			'start_date'       => gmdate( 'Y-m-d', $start ),
			'start_time'       => gmdate( 'H:i', $start ),
			'reminder_offsets' => '7,1',
		] );
		update_post_meta( $event_id, '_anchor_event_start_ts', $start );
		return $event_id;
	}

	private function wc_seat( $event_id, array $extra = [] ) {
		$order = wc_create_order();
		$order->set_billing_email( 'buyer@example.test' );
		$order->set_status( 'processing' );
		$order->save();
		return [ $order, $this->make_seat( $event_id, array_merge( [ 'order_id' => $order->get_id(), 'email' => 'pat@example.test', 'name' => 'Pat' ], $extra ) ) ];
	}

	private function rows( $kind = null ) {
		$rows = Anchor_Mail_Log::query( [] );
		return null === $kind ? $rows : array_values( array_filter( $rows, static function ( $r ) use ( $kind ) { return $r['kind'] === $kind; } ) );
	}

	private function send( $event_id, $seat_id, $email, $nonce = true ) {
		$this->_last_response = '';
		$_POST                = [
			'action'   => Email_Catalog::AJAX_ACTION,
			'event_id' => $event_id,
			'seat_id'  => $seat_id,
			'email'    => $email,
		];
		if ( $nonce ) {
			$_POST['nonce'] = wp_create_nonce( Anchor_Mail_Log_Admin::NONCE );
		}
		try {
			$this->_handleAjax( Email_Catalog::AJAX_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		} catch ( WPAjaxDieStopException $e ) {
			// Expected on error paths.
		}
		return json_decode( (string) strstr( $this->_last_response, '{' ), true );
	}

	private function markers( $seat_id, $event_id ) {
		return $this->module()->reminder_markers( $seat_id, (int) get_post_meta( $event_id, '_anchor_event_start_ts', true ) );
	}

	public function test_wc_seat_lists_receipt_confirmation_and_one_row_per_offset() {
		$this->require_wc();
		$event_id       = $this->event();
		list( , $seat ) = $this->wc_seat( $event_id );
		$html           = $this->catalog()->panel_for_seat( $event_id, $seat );

		$labels = [ 'Order receipt', 'Registration confirmation', 'Reminder · 7 days before', 'Reminder · 1 day before' ];
		$last   = -1;
		foreach ( $labels as $label ) {
			$pos = strpos( $html, esc_html( $label ) );
			$this->assertNotFalse( $pos, $label );
			$this->assertGreaterThan( $last, $pos, "$label is out of order" );
			$last = $pos;
		}
		$this->assertStringNotContainsString( 'Cancellation notice', $html );
		$this->assertSame( 4, substr_count( $html, '>Not sent<' ) );
		$this->assertSame( 4, substr_count( $html, 'data-mail-action=' ) );
		$this->assertSame( 4, substr_count( $html, '>Send<' ) );
		$this->assertSame( '', $this->catalog()->panel_for_seat( $event_id + 999, $seat ) );
	}

	public function test_send_confirmation_logs_and_returns_sent_row() {
		$this->require_wc();
		$event_id       = $this->event();
		list( , $seat ) = $this->wc_seat( $event_id );

		$out = $this->send( $event_id, $seat, 'confirmation' );
		$this->assertTrue( $out['success'] );
		$rows = $this->rows( 'events.confirmation' );
		$this->assertCount( 1, $rows );
		$this->assertSame( $seat, (int) $rows[0]['seat_id'] );
		$this->assertStringContainsString( 'data-mail-row="confirmation"', $out['data']['row_html'] );
		$this->assertStringContainsString( '>Sent ', $out['data']['row_html'] );
		$this->assertStringContainsString( 'Send again', $out['data']['row_html'] );
		$this->assertStringContainsString( 'action=' . \Anchor\Events\Email_Preview::AJAX_ACTION, $out['data']['row_html'] );
	}

	public function test_send_receipt_triggers_woocommerce_email_without_touching_the_order() {
		$this->require_wc();
		$event_id           = $this->event();
		list( $order, $seat ) = $this->wc_seat( $event_id );
		$status             = $order->get_status();

		$out = $this->send( $event_id, $seat, 'receipt' );
		$this->assertTrue( $out['success'] );
		$rows = $this->rows( 'wc.customer_processing_order' );
		$this->assertCount( 1, $rows );
		$this->assertSame( $order->get_id(), (int) $rows[0]['order_id'] );
		$this->assertSame( $status, wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( '>Sent ', $out['data']['row_html'] );
	}

	public function test_send_reminder_logs_offset_kind_and_leaves_a_future_offset_unmarked() {
		$this->require_wc();
		$event_id       = $this->event();
		list( , $seat ) = $this->wc_seat( $event_id );

		$out = $this->send( $event_id, $seat, 'reminder:7' );
		$this->assertTrue( $out['success'] );
		$this->assertCount( 1, $this->rows( 'events.reminder:7' ) );
		$this->assertSame( [], $this->markers( $seat, $event_id ), 'A not-yet-due reminder must still go out on schedule.' );

		$html = $this->catalog()->panel_for_seat( $event_id, $seat );
		$this->assertMatchesRegularExpression( '#data-mail-row="reminder:7">.*?>Sent #s', $html );
		$this->assertMatchesRegularExpression( '#data-mail-row="reminder:1">(?:(?!</tr>).)*>Not sent<#s', $html );
	}

	public function test_send_of_a_due_reminder_marks_it_and_a_failure_queues_no_retry() {
		$this->require_wc();
		$event_id       = $this->event( 12 * HOUR_IN_SECONDS );
		list( , $seat ) = $this->wc_seat( $event_id );

		remove_all_filters( 'pre_wp_mail' );
		add_filter( 'pre_wp_mail', '__return_false' );
		$out = $this->send( $event_id, $seat, 'reminder:1' );
		$this->assertFalse( $out['success'] );
		$this->assertSame( '', get_post_meta( $seat, Registrations::META_EMAIL_RETRY, true ) );
		$this->assertStringContainsString( 'Failed ', $out['data']['row_html'] );

		remove_all_filters( 'pre_wp_mail' );
		add_filter( 'pre_wp_mail', [ $this, 'fake_send' ], 10, 2 );
		$this->assertTrue( $this->send( $event_id, $seat, 'reminder:1' )['success'] );
		$this->assertGreaterThan( 0, (int) ( $this->markers( $seat, $event_id )[1] ?? 0 ) );
	}

	/**
	 * Reminder markers predate the mail log (2026-09-04 vs 2026-10-20), so a marker
	 * alone proves nothing this panel can show: only a logged send counts.
	 */
	public function test_scheduler_marker_without_a_log_row_is_not_sent() {
		$this->require_wc();
		$event_id       = $this->event();
		list( , $seat ) = $this->wc_seat( $event_id );
		$this->module()->mark_reminder_sent( $seat, (int) get_post_meta( $event_id, '_anchor_event_start_ts', true ), 7, time() - 60 );

		$html = $this->catalog()->panel_for_seat( $event_id, $seat );
		$this->assertMatchesRegularExpression( '#data-mail-row="reminder:7">(?:(?!</tr>).)*>Not sent<(?:(?!</tr>).)*>Send<#s', $html );
	}

	public function test_cancellation_row_only_for_cancelled_seat_and_sends_despite_marker() {
		$this->require_wc();
		$event_id = $this->event();
		$live     = $this->make_seat( $event_id );
		$this->assertStringNotContainsString( 'Cancellation notice', $this->catalog()->panel_for_seat( $event_id, $live ) );

		list( , $seat ) = $this->wc_seat( $event_id, [ 'status' => Registrations::STATUS_CANCELLED ] );
		$was            = time() - 3600;
		update_post_meta( $seat, Registrations::META_CANCEL_EMAILED, $was );
		$html = $this->catalog()->panel_for_seat( $event_id, $seat );
		$this->assertStringContainsString( 'Cancellation notice', $html );
		$this->assertMatchesRegularExpression( '#data-mail-row="cancellation">(?:(?!</tr>).)*>Not sent<#s', $html, 'The marker alone is not a logged send.' );

		$out = $this->send( $event_id, $seat, 'cancellation' );
		$this->assertTrue( $out['success'] );
		$this->assertStringContainsString( '>Sent ', $out['data']['row_html'] );
		$this->assertCount( 1, $this->rows( 'events.cancellation' ) );
		$this->assertGreaterThanOrEqual( $was, (int) get_post_meta( $seat, Registrations::META_CANCEL_EMAILED, true ) );
	}

	public function test_refusals_send_nothing() {
		$this->require_wc();
		$event_id       = $this->event();
		list( , $seat ) = $this->wc_seat( $event_id );
		$other          = $this->event();

		$this->assertFalse( $this->send( $event_id, $seat, 'confirmation', false )['success'], 'no nonce' );
		$this->assertFalse( $this->send( $other, $seat, 'confirmation' )['success'], 'seat of another event' );
		$this->assertFalse( $this->send( $event_id, $seat, 'reminder:3' )['success'], 'offset the event does not have' );
		$this->_setRole( 'subscriber' );
		$this->assertFalse( $this->send( $event_id, $seat, 'confirmation' )['success'], 'no capability' );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_unconfirmed_seat_gets_no_confirmation_or_reminder_send() {
		$event_id = $this->event();
		foreach ( [ 'cancelled', 'refunded', 'waitlist', 'pending' ] as $status ) {
			$seat = $this->make_seat( $event_id, [ 'status' => $status ] );
			$html = $this->catalog()->panel_for_seat( $event_id, $seat );
			$this->assertSame( 3, substr_count( $html, 'Seat is ' . $status ), $status . ': confirmation + 2 reminders' );
			$this->assertStringNotContainsString( 'data-mail-action=', preg_replace( '#data-mail-row="cancellation".*#s', '', $html ) );
			foreach ( [ 'confirmation', 'reminder:7', 'reminder:1' ] as $key ) {
				$out = $this->send( $event_id, $seat, $key );
				$this->assertFalse( $out['success'], "$status $key" );
				$this->assertSame( 'Seat is ' . $status, $out['data']['message'] );
			}
		}
		$this->assertCount( 0, $this->rows() );
		$this->assertTrue( $this->send( $event_id, $this->make_seat( $event_id ), 'confirmation' )['success'], 'confirmed seat still sends' );
	}

	public function test_receipt_send_only_for_processing_or_completed_orders() {
		$this->require_wc();
		$event_id = $this->event();
		foreach ( [ 'refunded', 'cancelled', 'failed', 'pending', 'on-hold' ] as $status ) {
			list( $order, $seat ) = $this->wc_seat( $event_id );
			$order->set_status( $status );
			$order->save();
			$GLOBALS['wpdb']->query( 'DELETE FROM ' . Anchor_Mail_Log::table() ); // phpcs:ignore -- WooCommerce mails its own status emails on save.
			$html = $this->catalog()->panel_for_seat( $event_id, $seat );
			$this->assertMatchesRegularExpression( '#data-mail-row="receipt">(?:(?!</tr>).)*Order is ' . preg_quote( $status, '#' ) . '#s', $html, $status );
			$this->assertDoesNotMatchRegularExpression( '#data-mail-row="receipt">(?:(?!</tr>).)*data-mail-action#s', $html, $status );
			$out = $this->send( $event_id, $seat, 'receipt' );
			$this->assertFalse( $out['success'], $status );
			$this->assertSame( 'Order is ' . $status, $out['data']['message'] );
			$this->assertCount( 0, $this->rows(), $status );
		}
		list( $order, $seat ) = $this->wc_seat( $event_id );
		$order->set_status( 'completed' );
		$order->save();
		$this->assertMatchesRegularExpression( '#data-mail-row="receipt">(?:(?!</tr>).)*data-mail-action#s', $this->catalog()->panel_for_seat( $event_id, $seat ) );
	}

	public function test_email_template_textareas_render_enabled_with_data_name_and_no_name() {
		$event_id = $this->event();
		ob_start();
		$this->module()->render_email_builder_metabox( get_post( $event_id ) );
		$html = ob_get_clean();
		$this->assertMatchesRegularExpression( '#<textarea id="anchor_email_tpl_confirmation" data-name="anchor_email_tpl_confirmation"(?![^>]*\bdisabled)#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<textarea[^>]* name="anchor_email_tpl_#', $html );
	}

	public function test_switched_off_type_has_no_send_button() {
		$this->require_wc();
		$event_id       = $this->event();
		list( , $seat ) = $this->wc_seat( $event_id );
		update_post_meta( $event_id, '_anchor_event_email_off_reminder', '1' );

		$html = $this->catalog()->panel_for_seat( $event_id, $seat );
		$this->assertSame( 2, substr_count( $html, 'Off for this event' ) );
		$this->assertSame( 2, substr_count( $html, 'data-mail-action=' ), 'receipt + confirmation only' );
		$this->assertFalse( $this->send( $event_id, $seat, 'reminder:7' )['success'] );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_imported_send_is_ignored() {
		$this->require_wc();
		$event_id           = $this->event();
		list( $order, $seat ) = $this->wc_seat( $event_id );
		Anchor_Mail_Log::insert( [
			'context'   => 'events',
			'kind'      => 'events.wc_confirmation',
			'audience'  => 'attendee',
			'event_id'  => $event_id,
			'order_id'  => $order->get_id(),
			'recipient' => 'pat@example.test',
			'subject'   => 'You are registered',
			'source'    => 'import',
			'status'    => 'delivered',
		] );
		$html = $this->catalog()->panel_for_seat( $event_id, $seat );
		$this->assertMatchesRegularExpression( '#data-mail-row="confirmation">(?:(?!</tr>).)*>Not sent<(?:(?!</tr>).)*<td>—</td><td>—</td><td>—</td>(?:(?!</tr>).)*View(?:(?!</tr>).)*>Send<#s', $html );
		$this->assertStringNotContainsString( 'pat@example.test', $html, 'No recipient from an imported row.' );
	}

	public function test_live_send_counts_as_sent_with_its_tracking() {
		$this->require_wc();
		$event_id             = $this->event();
		list( $order, $seat ) = $this->wc_seat( $event_id );
		Anchor_Mail_Log::insert( [
			'context'      => 'events',
			'kind'         => 'events.confirmation',
			'audience'     => 'attendee',
			'event_id'     => $event_id,
			'seat_id'      => $seat,
			'order_id'     => $order->get_id(),
			'recipient'    => 'pat@example.test',
			'subject'      => 'You are registered',
			'source'       => 'live',
			'status'       => 'delivered',
			'delivered_at' => '2026-10-20 20:00:00',
		] );
		$html = $this->catalog()->panel_for_seat( $event_id, $seat );
		$this->assertMatchesRegularExpression( '#data-mail-row="confirmation">(?:(?!</tr>).)*>Sent (?:(?!</tr>).)*<td>Yes · (?:(?!</tr>).)*Send again#s', $html );
	}

	public function test_every_row_links_view_to_the_live_preview_never_a_stored_copy() {
		$this->require_wc();
		$event_id       = $this->event();
		list( , $seat ) = $this->wc_seat( $event_id, [ 'status' => Registrations::STATUS_CANCELLED ] );
		$html           = $this->catalog()->panel_for_seat( $event_id, $seat );
		$this->assertSame( 5, substr_count( $html, 'action=' . \Anchor\Events\Email_Preview::AJAX_ACTION ), 'receipt, confirmation, 2 reminders, cancellation — including switched-off rows' );
		$this->assertSame( 5, substr_count( $html, 'target="_blank"' ) );
		$this->assertStringNotContainsString( 'anchor_mail_log_view', $html );
	}
}
