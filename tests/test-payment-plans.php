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

	public function test_one_time_price() {
		$this->assertSame( 6545.0, Payment_Plans::one_time_price( 7295, 750 ) );
		$this->assertSame( 7295.0, Payment_Plans::one_time_price( 7295, 0 ) );
		$this->assertSame( 0.0, Payment_Plans::one_time_price( 500, 750 ) );
		$this->assertSame( 7295.0, Payment_Plans::one_time_price( 7295, -50 ) );
	}

	/** Only a fresh line with no active plan is "pay in full". */
	public function test_is_one_time_line() {
		$this->assertTrue( Payment_Plans::is_one_time_line( [] ) );
		$this->assertTrue( Payment_Plans::is_one_time_line( [ 'wcsatt_data' => [ 'active_subscription_scheme' => false ] ] ) );
		$this->assertFalse( Payment_Plans::is_one_time_line( [ 'wcsatt_data' => [ 'active_subscription_scheme' => '1_month_3' ] ] ) );
		$this->assertFalse( Payment_Plans::is_one_time_line( [ 'subscription_renewal' => [ 'subscription_id' => 5 ] ] ) );
		$this->assertFalse( Payment_Plans::is_one_time_line( [ 'subscription_resubscribe' => [ 'subscription_id' => 5 ] ] ) );
	}

	/** A date with no amount of its own inherits its offering's; its own wins. */
	public function test_discount_inherits_from_offering() {
		$offering = self::factory()->post->create( [ 'post_type' => 'event' ] );
		$date     = self::factory()->post->create( [ 'post_type' => 'event' ] );
		$this->assertSame( 0.0, Payment_Plans::discount( $date, $offering ) );

		update_post_meta( $offering, Payment_Plans::DISCOUNT_META, '750' );
		$this->assertSame( 750.0, Payment_Plans::discount( $date, $offering ) );
		$this->assertSame( 0.0, Payment_Plans::discount( $date ) );

		update_post_meta( $date, Payment_Plans::DISCOUNT_META, '500' );
		$this->assertSame( 500.0, Payment_Plans::discount( $date, $offering ) );

		update_post_meta( $date, Payment_Plans::DISCOUNT_META, '0' );
		$this->assertSame( 0.0, Payment_Plans::discount( $date, $offering ), 'An explicit 0 turns it off for that date.' );
	}

	/** Blank clears the meta; absent field leaves it alone; negatives clamp. */
	public function test_save_discount() {
		$event = self::factory()->post->create( [ 'post_type' => 'event' ] );
		Payment_Plans::save_discount( $event, [ Payment_Plans::DISCOUNT_FIELD => '750' ] );
		$this->assertEquals( 750, get_post_meta( $event, Payment_Plans::DISCOUNT_META, true ) );

		Payment_Plans::save_discount( $event, [] );
		$this->assertEquals( 750, get_post_meta( $event, Payment_Plans::DISCOUNT_META, true ) );

		Payment_Plans::save_discount( $event, [ Payment_Plans::DISCOUNT_FIELD => '-20' ] );
		$this->assertEquals( 0, get_post_meta( $event, Payment_Plans::DISCOUNT_META, true ) );

		Payment_Plans::save_discount( $event, [ Payment_Plans::DISCOUNT_FIELD => '' ] );
		$this->assertSame( '', get_post_meta( $event, Payment_Plans::DISCOUNT_META, true ) );
	}

	/** No plan on the product → the ticket price is charged as-is. */
	public function test_cart_discount_inert_without_plans() {
		$this->require_wc();
		$product = new WC_Product_Simple();
		$product->set_regular_price( '7295' );
		$product->save();
		$line = [ 'anchor_event_id' => 99, 'product_id' => $product->get_id(), 'variation_id' => 0, 'data' => $product ];
		$cart = new class( [ $line ] ) {
			private $items;
			public function __construct( $items ) { $this->items = $items; }
			public function get_cart() { return $this->items; }
		};
		Payment_Plans::apply_cart_discounts( $cart, function () { return 750.0; } );
		$this->assertEquals( 7295, $product->get_price() );
	}
}
