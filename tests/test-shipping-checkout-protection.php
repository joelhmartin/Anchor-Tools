<?php
use Anchor\Shipping\Checkout\ProtectionFields;
use Anchor\Shipping\Services\Protection;

class Test_Shipping_Checkout_Protection extends Anchor_Shipping_TestCase {

	public function set_up() {
		parent::set_up();
		WC()->frontend_includes();
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->cart = new WC_Cart();
		WC()->customer = new WC_Customer( 0, true );
		// The test DB has no shipping methods, and WC_Cart::needs_shipping() is false without one: give the "rest of the world" zone a flat rate.
		$zone = new WC_Shipping_Zone( 0 );
		$zone->add_shipping_method( 'flat_rate' );
		delete_transient( 'wc_shipping_method_count' );
		WC_Cache_Helper::get_transient_version( 'shipping', true );
	}

	public function tear_down() {
		WC()->cart->empty_cart();
		parent::tear_down();
	}

	private function fees(): array {
		WC()->cart->calculate_totals();
		$fees = array_map( static fn( $f ) => [ $f->name, (float) $f->amount ], array_values( WC()->cart->get_fees() ) );
		sort( $fees ); // Woo orders fees by id, not by the order they were added.
		return $fees;
	}

	public function test_chosen_options_add_fees_from_the_shippable_subtotal() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ], 'signature' => [ 'mode' => 'customer', 'fee' => '7.70' ] ] );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 450, 'weight' => 0.5 ] ) );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 2500, 'virtual' => true ] ) );
		WC()->session->set( 'anchor_shipping_insure', 'yes' );
		WC()->session->set( 'anchor_shipping_signature', 'yes' );

		$this->assertSame( 450.0, ( new ProtectionFields() )->cart_shippable_subtotal( WC()->cart ) );
		$this->assertSame( [ [ 'Shipping insurance', 5.0 ], [ 'Signature on delivery', 7.7 ] ], $this->fees() );
	}

	public function test_no_fee_when_not_offered_or_not_chosen() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'auto', 'fee_per_100' => '1.25' ] ] );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 450, 'weight' => 0.5 ] ) );
		WC()->session->set( 'anchor_shipping_insure', 'yes' );
		$this->assertSame( [], $this->fees(), 'auto mode never charges the customer' );

		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ] ] );
		WC()->session->set( 'anchor_shipping_insure', '' );
		$this->assertSame( [], $this->fees() );
	}

	public function test_render_hides_unoffered_options() {
		$this->configure_ups( [ 'signature' => [ 'mode' => 'customer', 'fee' => '7.70' ] ] );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 450, 'weight' => 0.5 ] ) );
		ob_start();
		( new ProtectionFields() )->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="anchor_shipping_signature"', $html );
		$this->assertStringNotContainsString( 'name="anchor_shipping_insure"', $html );
	}

	public function test_order_records_the_choice_and_session_clears_when_the_cart_empties() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ] ] );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 450, 'weight' => 0.5 ] ) );
		WC()->session->set( 'anchor_shipping_insure', 'yes' );
		$order = wc_create_order();
		( new ProtectionFields() )->save_to_order( $order );
		$this->assertSame( 'yes', $order->get_meta( Protection::META_INSURE ) );
		$this->assertSame( '', $order->get_meta( Protection::META_SIGNATURE ) );
		$this->assertSame( 'yes', WC()->session->get( 'anchor_shipping_insure' ), 'a declined payment must keep the choice' );
		WC()->cart->empty_cart(); // what Woo does after a successful order
		$this->assertSame( '', WC()->session->get( 'anchor_shipping_insure' ) );
		$this->assertSame( '', WC()->session->get( 'anchor_shipping_signature' ) );
	}

	public function test_no_choice_recorded_when_the_fee_would_be_zero() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ] ] );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 80, 'weight' => 0.5 ] ) );
		WC()->session->set( 'anchor_shipping_insure', 'yes' );
		$order = wc_create_order();
		( new ProtectionFields() )->save_to_order( $order );
		$this->assertSame( '', $order->get_meta( Protection::META_INSURE ) );
	}

	/** Place order re-reads the boxes before Woo totals the cart: the submitted choice wins over a stale session. */
	public function test_final_checkout_charges_and_records_what_was_submitted() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ], 'signature' => [ 'mode' => 'customer', 'fee' => '7.70' ] ] );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 450, 'weight' => 0.5 ] ) );
		$fields = new ProtectionFields();

		// Last order-review refresh had insurance ticked; the customer then unticked it and ticked signature.
		WC()->session->set( 'anchor_shipping_insure', 'yes' );
		WC()->session->set( 'anchor_shipping_signature', '' );
		$_POST = [ 'anchor_shipping_signature' => 'yes' ];
		do_action( 'woocommerce_checkout_process' ); // what WC_Checkout::process_checkout() fires before it totals the cart
		$this->assertSame( [ [ 'Signature on delivery', 7.7 ] ], $this->fees() );
		$order = wc_create_order();
		$fields->save_to_order( $order );
		$this->assertSame( '', $order->get_meta( Protection::META_INSURE ) );
		$this->assertSame( 'yes', $order->get_meta( Protection::META_SIGNATURE ) );
		$_POST = [];
	}

	/** Protection::offered() counts a '0' fee as set, so a free option is shown and must reach the order. */
	public function test_zero_fee_choice_is_offered_and_recorded() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '0' ], 'signature' => [ 'mode' => 'customer', 'fee' => '0' ] ] );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 450, 'weight' => 0.5 ] ) );
		$fields = new ProtectionFields();
		ob_start();
		$fields->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="anchor_shipping_insure"', $html );
		$this->assertStringContainsString( 'name="anchor_shipping_signature"', $html );
		WC()->session->set( 'anchor_shipping_insure', 'yes' );
		WC()->session->set( 'anchor_shipping_signature', 'yes' );
		$this->assertSame( [], $this->fees(), 'free: no fee lines' );
		$order = wc_create_order();
		$fields->save_to_order( $order );
		$this->assertSame( 'yes', $order->get_meta( Protection::META_INSURE ) );
		$this->assertSame( 'yes', $order->get_meta( Protection::META_SIGNATURE ) );
	}
}
