<?php
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Module;

class Test_Shipping_Emails extends Anchor_Shipping_TestCase {

	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
	}

	private function sent(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	public function test_label_email_goes_to_the_inbox_with_the_label_attached_and_nothing_to_the_customer() {
		$this->configure_ups();
		$order = $this->make_order( [], 'pending' );
		reset_phpmailer_instance();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		Module::instance()->labels->create_for_order( $order, [ new Parcel( 1, 10, 10, 10 ) ], 'manual' );

		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'shipping@example.com', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( (string) $order->get_order_number(), $sent[0]['subject'] );
		$this->assertStringContainsString( '1ZK877V90300000001', $sent[0]['body'] );
		$attachments = tests_retrieve_phpmailer_instance()->getAttachments();
		$this->assertCount( 1, $attachments );
		$this->assertStringEndsWith( '.pdf', $attachments[0][0] );
	}

	public function test_ready_to_ship_email_carries_reason_and_create_link() {
		$this->configure_ups();
		$order = $this->make_order( [], 'pending' );
		reset_phpmailer_instance();
		Module::instance()->labels->mark_needs_attention( $order, 'Banner Stand has no weight.' );

		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'shipping@example.com', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'Banner Stand has no weight.', $sent[0]['body'] );
		$this->assertStringContainsString( '#anchor-shipping', $sent[0]['body'] );
		$this->assertStringContainsString( 'Cupertino', $sent[0]['body'] );
	}

	public function test_disabled_email_sends_nothing() {
		$this->configure_ups();
		update_option( 'woocommerce_anchor_shipping_ready_to_ship_settings', [ 'enabled' => 'no' ] );
		// The shared mailer built (and hooked) its email objects before this option existed.
		WC()->mailer()->emails['Anchor_Shipping_Ready_To_Ship_Email']->enabled = 'no';
		$order = $this->make_order( [], 'pending' );
		reset_phpmailer_instance();
		Module::instance()->labels->mark_needs_attention( $order, 'x' );
		$this->assertCount( 0, $this->sent() );
	}
}
