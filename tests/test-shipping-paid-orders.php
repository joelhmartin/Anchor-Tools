<?php
use Anchor\Shipping\Module;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Services\PaidOrderHandler;

class Test_Shipping_Paid_Orders extends Anchor_Shipping_TestCase {

	private function handler(): PaidOrderHandler {
		return Module::instance()->paid_orders;
	}

	private function state( int $order_id ): string {
		return (string) wc_get_order( $order_id )->get_meta( LabelService::STATE_META );
	}

	public function test_paying_queues_one_async_job_and_skips_orders_without_shipping() {
		$this->configure_ups();
		$order = $this->make_order( [], 'pending' );
		$order->update_status( 'processing' );
		$this->assertTrue( (bool) as_has_scheduled_action( PaidOrderHandler::ACTION, [ $order->get_id(), 1 ], PaidOrderHandler::GROUP ) );

		$ticket_only = $this->make_order( [ $this->make_product( [ 'virtual' => true ] ) ], 'pending', false );
		$ticket_only->update_status( 'processing' );
		$this->assertFalse( (bool) as_has_scheduled_action( PaidOrderHandler::ACTION, [ $ticket_only->get_id(), 1 ], PaidOrderHandler::GROUP ) );
	}

	public function test_checkbox_off_sends_ready_to_ship_even_when_sizeable() {
		$this->configure_ups( [ 'auto_label' => false ] );
		$order = $this->make_order( [], 'processing' );
		$this->handler()->run( $order->get_id() );
		$this->assertSame( 'needs_attention', $this->state( $order->get_id() ) );
		$this->assertCount( 0, $this->requests, 'no carrier call' );
	}

	public function test_checkbox_on_and_sizeable_creates_the_label() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		$this->handler()->run( $order->get_id() );
		$this->assertSame( 'labelled', $this->state( $order->get_id() ) );
		$this->assertSame( 'auto', Module::instance()->shipments->for_order( $order->get_id() )[0]['source'] );
	}

	public function test_checkbox_on_but_unsizeable_falls_back_to_ready_to_ship() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [ $this->make_product( [ 'name' => 'Banner Stand' ] ) ], 'processing' );
		$this->handler()->run( $order->get_id() );
		$this->assertSame( 'needs_attention', $this->state( $order->get_id() ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_running_the_job_twice_creates_one_label() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		$this->handler()->run( $order->get_id() );
		$this->handler()->run( $order->get_id() );
		$this->assertCount( 1, Module::instance()->shipments->for_order( $order->get_id() ) );
		$this->assertCount( 2, $this->requests );
	}

	public function test_retryable_error_reschedules_then_gives_up_to_a_person() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 503, 'down' );
		$this->handler()->run( $order->get_id(), 1 );
		$this->assertTrue( (bool) as_has_scheduled_action( PaidOrderHandler::ACTION, [ $order->get_id(), 2 ], PaidOrderHandler::GROUP ) );
		$this->assertNotSame( 'needs_attention', $this->state( $order->get_id() ) );

		$this->queue_response( 503, 'down' ); // token cached
		$this->handler()->run( $order->get_id(), PaidOrderHandler::MAX_ATTEMPTS );
		$this->assertSame( 'needs_attention', $this->state( $order->get_id() ) );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( 'could not be reached after 3 attempts', $notes );
	}

	public function test_non_retryable_error_goes_straight_to_a_person_with_the_carrier_message() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 400, [ 'response' => [ 'errors' => [ [ 'code' => '120802', 'message' => 'Address validation failed' ] ] ] ] );
		$this->handler()->run( $order->get_id() );
		$this->assertSame( 'needs_attention', $this->state( $order->get_id() ) );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( 'Address validation failed', $notes );
		$this->assertStringContainsString( 'The carrier refused the label', $notes );
	}

	public function test_post_billing_save_failure_is_not_retried_and_flags_once() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture_with_unreadable_label() );
		reset_phpmailer_instance();
		$this->handler()->run( $order->get_id() );
		$this->assertSame( 'needs_attention', $this->state( $order->get_id() ) );
		$this->assertFalse( (bool) as_has_scheduled_action( PaidOrderHandler::ACTION, [ $order->get_id(), 2 ], PaidOrderHandler::GROUP ), 'no retry' );
		$this->assertCount( 2, $this->requests, 'one token + one label purchase only' );
		$notes = wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' );
		$this->assertCount( 1, array_filter( $notes, static fn( $n ) => str_contains( $n, 'could not be saved' ) ), 'noted once' );
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'Shipping problem', $sent[0]['subject'] );
	}

	public function test_unexpected_exception_still_flags_the_order() {
		$this->configure_ups( [ 'auto_label' => true, 'default_carrier' => 'boom' ] );
		$order = $this->make_order( [], 'processing' );
		reset_phpmailer_instance();
		$boom  = new class() implements \Anchor\Shipping\Carriers\CarrierInterface {
			public function id(): string { return 'boom'; }
			public function label(): string { return 'Boom'; }
			public function supports( string $feature ): bool { return 'labels' === $feature; }
			public function services(): array { return [ 'x' => 'X' ]; }
			public function settings_fields(): array { return []; }
			public function create_label( \Anchor\Shipping\Domain\ShipmentRequest $request ): \Anchor\Shipping\Domain\LabelResult { throw new \TypeError( 'boom' ); }
			public function void_label( string $shipment_id ): void {}
			public function track( array $tracking_numbers ): array { return []; }
			public function rate( \Anchor\Shipping\Domain\ShipmentRequest $request ): array { return []; }
			public function tracking_url( string $tracking_number ): string { return ''; }
		};
		$add = static function ( $list ) use ( $boom ) {
			$list['boom'] = $boom;
			return $list;
		};
		add_filter( 'anchor_shipping_carriers', $add );
		Module::instance()->carriers->reset();
		try {
			$this->handler()->run( $order->get_id() );
		} finally {
			remove_filter( 'anchor_shipping_carriers', $add );
			Module::instance()->carriers->reset();
		}
		$this->assertSame( 'needs_attention', $this->state( $order->get_id() ) );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( 'Automatic label failed: boom', $notes );
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'Shipping problem', $sent[0]['subject'] );
	}

	public function test_job_does_nothing_for_an_order_cancelled_after_queueing() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [], 'processing' );
		$this->handler()->queue( $order->get_id() );
		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'cancelled' );
		reset_phpmailer_instance();
		$this->handler()->run( $order->get_id() );
		$this->assertCount( 0, $this->requests );
		$this->assertNotSame( 'needs_attention', $this->state( $order->get_id() ) );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}
}
