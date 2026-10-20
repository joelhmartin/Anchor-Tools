<?php
/**
 * Events integration of the mail log.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group email
 * @group mail-log
 */
class Test_Events_Email_Log extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		Anchor_Mail_Log::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Anchor_Mail_Log::table() );
		add_filter( 'pre_wp_mail', [ $this, 'fake_send' ], 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'fake_send' ], 10 );
		delete_option( 'anchor_mail_log_settings' );
		parent::tear_down();
	}

	/** Run the capture filter as wp_mail() would, then pretend it sent. */
	public function fake_send( $short, $atts ) {
		apply_filters( 'wp_mail', $atts );
		return true;
	}

	private function rows() {
		return Anchor_Mail_Log::query( [] );
	}

	public function test_confirmation_is_logged_against_seat() {
		$event_id = $this->make_event( [ 'title' => 'Log Event', 'start_date' => '2030-10-23' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'pat@example.test' ] );
		$this->module()->send_confirmation_email( $event_id, 'Pat', 'pat@example.test', \Anchor\Events\Registrations::STATUS_CONFIRMED, 0, $seat_id );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'events.confirmation', $rows[0]['kind'] );
		$this->assertSame( $seat_id, (int) $rows[0]['seat_id'] );
		$this->assertSame( $event_id, (int) $rows[0]['event_id'] );
		$this->assertSame( 'attendee', $rows[0]['audience'] );
		$this->assertSame( 'sent', $rows[0]['status'] );
	}

	public function test_unlogged_call_unchanged() {
		$this->assertTrue( $this->module()->send_html_email( 'x@example.test', 'Hi', '<p>x</p>' ) );
		$this->assertCount( 0, $this->rows() );
	}

	private function event_order( $event_id, $seat_extra = [] ) {
		$order = wc_create_order();
		$order->set_billing_email( 'buyer@example.test' );
		$order->save();
		$seat = $this->make_seat( $event_id, array_merge( [ 'order_id' => $order->get_id() ], $seat_extra ) );
		return [ $order, $seat ];
	}

	public function test_wc_customer_email_logged_for_event_order() {
		$this->require_wc();
		$event_id = $this->make_event( [ 'title' => 'WC Event', 'start_date' => '2030-10-23' ] );
		list( $order ) = $this->event_order( $event_id );
		WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger( $order->get_id() );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'wc.customer_processing_order', $rows[0]['kind'] );
		$this->assertSame( $order->get_id(), (int) $rows[0]['order_id'] );
		$this->assertSame( 0, (int) $rows[0]['seat_id'] );
		$this->assertSame( $event_id, (int) $rows[0]['event_id'] );
	}

	public function test_wc_email_for_non_event_order_not_logged() {
		$this->require_wc();
		$order = wc_create_order();
		$order->set_billing_email( 'buyer@example.test' );
		$order->save();
		WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger( $order->get_id() );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_panel_for_seat_lists_seat_and_order_rows() {
		$this->require_wc();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$event_id = $this->make_event( [ 'title' => 'Panel Event', 'start_date' => '2030-10-23' ] );
		list( $order, $seat ) = $this->event_order( $event_id );
		$other = $this->make_seat( $event_id, [ 'email' => 'other@example.test' ] );
		Anchor_Mail_Log::insert( [ 'context' => 'events', 'kind' => 'events.confirmation', 'audience' => 'attendee', 'event_id' => $event_id, 'seat_id' => $seat, 'recipient' => 'seatrow@example.test', 'subject' => 'S' ] );
		Anchor_Mail_Log::insert( [ 'context' => 'events', 'kind' => 'wc.customer_processing_order', 'audience' => 'attendee', 'event_id' => $event_id, 'order_id' => $order->get_id(), 'recipient' => 'orderrow@example.test', 'subject' => 'S' ] );
		Anchor_Mail_Log::insert( [ 'context' => 'events', 'kind' => 'events.confirmation', 'audience' => 'attendee', 'event_id' => $event_id, 'seat_id' => $other, 'recipient' => 'otherrow@example.test', 'subject' => 'S' ] );
		$html = $this->module()->email_log->panel_for_seat( $event_id, $seat );
		$this->assertStringContainsString( 'seatrow@example.test', $html );
		$this->assertStringContainsString( 'orderrow@example.test', $html );
		$this->assertStringNotContainsString( 'otherrow@example.test', $html );
		$this->assertSame( '', $this->module()->email_log->panel_for_seat( $event_id + 999, $seat ) );
	}

	public function test_events_capability_gates_rows() {
		$cap  = Module::events_capability();
		$user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_user_by( 'id', $user )->add_cap( $cap );
		wp_set_current_user( $user );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertTrue( Anchor_Mail_Log_Admin::can_manage( [ 'context' => 'events' ] ) );
		$this->assertFalse( Anchor_Mail_Log_Admin::can_manage( [ 'context' => 'other' ] ) );
	}

	public function test_kind_labels_filter_includes_events_kinds() {
		$labels = apply_filters( 'anchor_mail_log_kind_labels', [] );
		$this->assertSame( 'Order received (WooCommerce)', $labels['wc.customer_processing_order'] );
		$this->assertArrayHasKey( 'events.confirmation', $labels );
	}

	public function test_signing_key_saved_not_echoed() {
		$this->module()->sanitize_settings( [ 'mailgun_signing_key' => 'key-abc' ] );
		$this->assertSame( 'key-abc', Anchor_Mail_Log_Mailgun::signing_key() );
		$this->assertStringNotContainsString( 'key-abc', (string) wp_json_encode( get_option( Module::OPTION_KEY ) ) );
		ob_start();
		\Anchor\Events\Email_Log::render_signing_key_field();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'key-abc', $html );
		$this->assertStringContainsString( 'Saved', $html );
	}

	public function test_exception_in_send_does_not_leak_context() {
		add_filter( 'pre_wp_mail', function () { throw new RuntimeException( 'boom' ); }, 1 );
		try {
			$this->module()->send_html_email( 'x@example.test', 'S', '<p>x</p>', [], 0, [], [ 'kind' => 'events.reminder' ] );
			$this->fail( 'expected exception' );
		} catch ( RuntimeException $e ) {
			// expected
		}
		remove_all_filters( 'pre_wp_mail' );
		add_filter( 'pre_wp_mail', [ $this, 'fake_send' ], 10, 2 );
		wp_mail( 'later@example.test', 'Unrelated', 'x' );
		$subjects = wp_list_pluck( $this->rows(), 'subject' );
		$this->assertNotContains( 'Unrelated', $subjects );
	}
}
