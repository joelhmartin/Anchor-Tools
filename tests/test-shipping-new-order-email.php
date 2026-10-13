<?php
use Anchor\Shipping\Module;
use Anchor\Shipping\Support\Settings;
use Anchor\Shipping\Support\Urls;

class Test_Shipping_New_Order_Email extends Anchor_Shipping_TestCase {

	public function set_up() {
		parent::set_up();
		delete_option( 'woocommerce_new_order_settings' );
		// Another test may have switched this shared email object off.
		WC()->mailer()->emails['Anchor_Shipping_Ready_To_Ship_Email']->enabled = 'yes';
		reset_phpmailer_instance();
	}

	private function sent(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	private function send_new_order( WC_Order $order ): string {
		reset_phpmailer_instance();
		$email = WC()->mailer()->emails['WC_Email_New_Order'];
		$email->enabled   = 'yes';
		$email->recipient = 'staff@example.com';
		$email->trigger( $order->get_id(), $order );
		$sent = $this->sent();
		return $sent ? $sent[0]['body'] : '';
	}

	public function test_eligible_order_gets_the_create_label_block_with_the_console_url() {
		$this->configure_ups();
		$order = $this->make_order( [], 'pending' );
		$body  = $this->send_new_order( $order );
		$this->assertStringContainsString( 'Create shipping label', $body );
		$this->assertStringContainsString( str_replace( '&', '&amp;', Urls::order( $order ) ), $body );
	}

	public function test_ineligible_orders_get_no_block() {
		$this->configure_ups();
		$virtual = $this->make_order( [ $this->make_product( [ 'virtual' => true ] ) ], 'pending', false );
		$this->assertStringNotContainsString( 'Create shipping label', $this->send_new_order( $virtual ) );
		$noline = $this->make_order( [], 'pending', false );
		$this->assertStringNotContainsString( 'Create shipping label', $this->send_new_order( $noline ) );
	}

	public function test_other_emails_never_get_the_block() {
		$this->configure_ups();
		$order = $this->make_order( [], 'pending' );
		reset_phpmailer_instance();
		$email = WC()->mailer()->emails['WC_Email_Customer_Processing_Order'];
		$email->trigger( $order->get_id(), $order );
		$sent = $this->sent();
		$this->assertNotEmpty( $sent );
		$this->assertStringNotContainsString( 'Create shipping label', $sent[0]['body'] );
	}

	public function test_setting_off_removes_the_block() {
		$this->configure_ups( [ 'new_order_button' => false ] );
		$order = $this->make_order( [], 'pending' );
		$this->assertStringNotContainsString( 'Create shipping label', $this->send_new_order( $order ) );
	}

	public function test_labelled_eligible_order_still_gets_the_block() {
		$this->configure_ups();
		$order = $this->make_order( [], 'pending' );
		$order->update_meta_data( \Anchor\Shipping\Services\LabelService::UNRECORDED_META, 'x' );
		$order->save();
		$this->assertStringContainsString( 'Create shipping label', $this->send_new_order( $order ) );
	}

	public function test_url_falls_back_to_edit_screen_then_uses_console_page() {
		$order = $this->make_order( [], 'pending' );
		$this->assertSame( $order->get_edit_order_url(), Urls::order( $order ) );
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		$this->configure_ups( [ 'console_page' => $page ] );
		$this->assertSame( add_query_arg( 'order', $order->get_id(), get_permalink( $page ) ), Urls::order( $order ) );
		wp_update_post( [ 'ID' => $page, 'post_status' => 'draft' ] );
		$this->assertSame( $order->get_edit_order_url(), Urls::order( $order ) );
	}

	public function test_new_order_recipients_lowercased_and_empty_when_disabled() {
		update_option( 'woocommerce_new_order_settings', [ 'enabled' => 'yes', 'recipient' => 'A@Example.com, b@example.com' ] );
		$this->assertSame( [ 'a@example.com', 'b@example.com' ], Urls::new_order_recipients() );
		update_option( 'woocommerce_new_order_settings', [ 'enabled' => 'no', 'recipient' => 'a@example.com' ] );
		$this->assertSame( [], Urls::new_order_recipients() );
	}

	private function ready_to_ship_recipients( WC_Order $order ): array {
		reset_phpmailer_instance();
		Module::instance()->labels->mark_needs_attention( $order, 'x' );
		$sent = $this->sent();
		return $sent ? array_map( static fn( $t ) => $t[0], $sent[0]['to'] ) : [];
	}

	public function test_ready_to_ship_skips_addresses_that_get_new_order() {
		$this->configure_ups( [ 'inbox' => 'shipping@example.com, Staff@example.com' ] );
		update_option( 'woocommerce_new_order_settings', [ 'enabled' => 'yes', 'recipient' => 'staff@example.com' ] );
		$order = $this->make_order( [], 'pending' );
		$this->assertSame( [ 'shipping@example.com' ], $this->ready_to_ship_recipients( $order ) );
	}

	public function test_ready_to_ship_not_sent_when_all_covered_but_state_still_flagged() {
		$this->configure_ups( [ 'inbox' => 'staff@example.com' ] );
		update_option( 'woocommerce_new_order_settings', [ 'enabled' => 'yes', 'recipient' => 'staff@example.com' ] );
		$order = $this->make_order( [], 'pending' );
		$this->assertSame( [], $this->ready_to_ship_recipients( $order ) );
		$this->assertSame( 'needs_attention', wc_get_order( $order->get_id() )->get_meta( \Anchor\Shipping\Services\LabelService::STATE_META ) );
	}

	public function test_setting_off_sends_ready_to_ship_to_the_full_inbox() {
		$this->configure_ups( [ 'inbox' => 'shipping@example.com, staff@example.com', 'new_order_button' => false ] );
		update_option( 'woocommerce_new_order_settings', [ 'enabled' => 'yes', 'recipient' => 'staff@example.com' ] );
		$order = $this->make_order( [], 'pending' );
		$this->assertSame( [ 'shipping@example.com', 'staff@example.com' ], $this->ready_to_ship_recipients( $order ) );
	}
}
