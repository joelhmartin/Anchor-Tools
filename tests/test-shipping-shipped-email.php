<?php
use Anchor\Shipping\Module;

class Test_Shipping_Shipped_Email extends Anchor_Shipping_TestCase {

	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
	}

	private function sent(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	private function add_row( WC_Order $order, string $tracking, string $status = 'in_transit' ): array {
		$repo = Module::instance()->shipments;
		$id   = $repo->insert( [ 'order_id' => $order->get_id(), 'carrier' => 'ups', 'tracking_number' => $tracking, 'status' => $status, 'shipment_id' => 'S' . $tracking ] );
		return $repo->find( $id );
	}

	public function test_first_scan_sends_once_to_the_billing_email_with_tracking_links_and_address() {
		$order = $this->make_order( [], 'processing' );
		$row   = $this->add_row( $order, '1ZK877V90300000001' );
		$this->add_row( $order, '1ZK877V90300000002', 'label_created' );
		$this->add_row( $order, '1ZVOIDED', 'voided' );
		reset_phpmailer_instance();

		do_action( 'anchor_shipping_first_scan', $order->get_id(), $row );

		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'pat@example.com', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'has shipped', $sent[0]['subject'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), $sent[0]['subject'] );
		$this->assertStringContainsString( 'tracknum=1ZK877V90300000001', $sent[0]['body'] );
		$this->assertStringContainsString( '1ZK877V90300000002', $sent[0]['body'] );
		$this->assertStringNotContainsString( '1ZVOIDED', $sent[0]['body'] );
		$this->assertStringContainsString( 'Cupertino', $sent[0]['body'] );
	}

	public function test_no_customer_email_at_label_creation() {
		$this->configure_ups();
		$order = $this->make_order( [], 'pending' );
		reset_phpmailer_instance();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		Module::instance()->labels->create_for_order( $order, [ new Anchor\Shipping\Domain\Parcel( 1, 10, 10, 10 ) ], 'manual' );
		foreach ( $this->sent() as $mail ) {
			$this->assertNotSame( 'pat@example.com', $mail['to'][0][0] );
		}
	}

	public function test_account_tracking_prints_links_for_active_rows_only() {
		$order = $this->make_order( [], 'processing' );
		$this->add_row( $order, '1ZK877V90300000001' );
		$this->add_row( $order, '1ZVOIDED', 'voided' );
		ob_start();
		do_action( 'woocommerce_order_details_after_order_table', $order );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'tracknum=1ZK877V90300000001', $html );
		$this->assertStringNotContainsString( '1ZVOIDED', $html );
		$this->assertStringContainsString( 'In transit', $html );
	}

	public function test_account_tracking_prints_nothing_without_shipments() {
		$order = $this->make_order( [], 'processing' );
		ob_start();
		do_action( 'woocommerce_order_details_after_order_table', $order );
		$this->assertSame( '', ob_get_clean() );
	}
}
