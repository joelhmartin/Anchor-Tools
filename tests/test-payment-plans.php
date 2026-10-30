<?php
/**
 * Payment_Plans: labels, and inert without WC Subscriptions plans.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Payment_Plans;

class Test_Payment_Plans extends Anchor_Events_TestCase {

	public function test_labels() {
		$this->assertSame( '3 monthly payments', Payment_Plans::label( 3, 'month', 1 ) );
		$this->assertSame( 'Monthly payments', Payment_Plans::label( 0, 'month', 1 ) );
		$this->assertStringStartsWith( '3 payments, every', Payment_Plans::label( 3, 'week', 2 ) );
	}

	/** A product with no plan renders nothing and never sets the APFS field. */
	public function test_inert_without_plans() {
		$this->require_wc();
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->save();
		$pid = $product->get_id();

		$this->assertSame( '', Payment_Plans::render( $pid ) );

		$_POST['plan'] = 'anything';
		$this->assertSame( '', Payment_Plans::apply_posted( $pid ) );
		$this->assertArrayNotHasKey( 'convert_to_sub_' . $pid, $_REQUEST );
		unset( $_POST['plan'] );
	}
}
