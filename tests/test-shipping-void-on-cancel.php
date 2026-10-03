<?php
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Module;

class Test_Shipping_Void_On_Cancel extends Anchor_Shipping_TestCase {

	private function labelled_order(): WC_Order {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		Module::instance()->labels->create_for_order( $order, [ new Parcel( 1, 10, 10, 10 ) ], 'manual' );
		return wc_get_order( $order->get_id() );
	}

	public function test_cancelling_voids_active_labels() {
		$order = $this->labelled_order();
		$this->queue_response( 200, $this->fixture( 'ups-void-ok' ) );
		$order->update_status( 'cancelled' );
		$this->assertSame( [], Module::instance()->shipments->active_for_order( $order->get_id() ) );
	}

	public function test_refusal_to_void_asks_a_person() {
		$order = $this->labelled_order();
		$this->queue_response( 400, [ 'response' => [ 'errors' => [ [ 'code' => '190102', 'message' => 'No shipment found within the allowed void period' ] ] ] ] );
		reset_phpmailer_instance();
		$order->update_status( 'refunded' );
		$problem = array_filter( tests_retrieve_phpmailer_instance()->mock_sent, static fn( $m ) => str_contains( $m['subject'], 'Shipping problem' ) );
		$this->assertCount( 1, $problem, 'staff get the problem email, not "ready to ship"' );
		$this->assertCount( 0, array_filter( tests_retrieve_phpmailer_instance()->mock_sent, static fn( $m ) => str_contains( $m['subject'], 'Ready to ship' ) ) );
		$this->assertCount( 1, Module::instance()->shipments->active_for_order( $order->get_id() ) );
		$this->assertSame( 'needs_attention', wc_get_order( $order->get_id() )->get_meta( '_anchor_shipping_state' ) );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( 'allowed void period', $notes );
	}

	public function test_unlabelled_orders_make_no_carrier_calls() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$order->update_status( 'cancelled' );
		$this->assertCount( 0, $this->requests );
	}

	public function test_any_failure_while_voiding_goes_to_the_problem_channel() {
		$order = $this->labelled_order();
		$this->queue_response( 200, $this->fixture( 'ups-void-ok' ) );
		$fail = static fn( $q ) => str_contains( $q, 'UPDATE `' . \Anchor\Shipping\Database\Migrations::table() . '`' ) ? 'SELECT 1 FROM nonexistent_anchor_table' : $q;
		global $wpdb;
		add_filter( 'query', $fail );
		$wpdb->suppress_errors( true );
		reset_phpmailer_instance();
		try {
			$order->update_status( 'cancelled' );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( false );
		}
		$this->assertSame( 'needs_attention', wc_get_order( $order->get_id() )->get_meta( '_anchor_shipping_state' ) );
		$problem = array_filter( tests_retrieve_phpmailer_instance()->mock_sent, static fn( $m ) => str_contains( $m['subject'], 'Shipping problem' ) );
		$this->assertCount( 1, $problem );
	}
}
