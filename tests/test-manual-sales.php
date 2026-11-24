<?php
/**
 * Manual sales: an offline payment recorded against a hand-added seat becomes
 * a real, completed WooCommerce order (requires WooCommerce — skipped without).
 *
 * What these pin: the order carries the typed amount and the seat's ticket
 * tier; the seat is linked to it and NOT duplicated by the order → seat
 * reconcile; refunding the order releases the seat; WooCommerce sends none of
 * its own emails; and a second attempt on the same seat is refused.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Manual_Sales;
use Anchor\Events\Registrations;

/**
 * @group woocommerce
 * @group roster
 */
class Test_Manual_Sales extends Anchor_Events_TestCase {

	/** Every wp_mail() call made during the test. */
	private $sent = [];

	public function set_up() {
		parent::set_up();
		$this->require_wc();
		$this->sent = [];
		add_filter( 'wp_mail', [ $this, 'capture_mail' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		remove_filter( 'wp_mail', [ $this, 'capture_mail' ] );
		$_POST = [];
		parent::tear_down();
	}

	public function capture_mail( $args ) {
		$this->sent[] = $args;
		$args['to']   = 'nobody@example.org';
		return $args;
	}

	private function manual_sales() {
		return $this->module()->manual_sales;
	}

	/**
	 * A paid two-tier event with its managed product, plus one hand-added seat
	 * on the second tier.
	 *
	 * @return array{event_id:int,tier_id:string,variation_id:int,seat_id:int}
	 */
	private function event_with_manual_seat() {
		$event_id = $this->make_event(
			[ 'title' => 'Manual Sale Event', 'capacity' => 0 ],
			[
				[ 'label' => 'Dentist', 'price' => '2195', 'active' => 1 ],
				[ 'label' => 'Staff', 'price' => '950', 'active' => 1 ],
			]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$tier_id      = $tiers[1]['id'];
		$variation_id = $this->product_sync()->variation_for_tier( $event_id, $tier_id );
		$this->assertGreaterThan( 0, $variation_id );

		$seat_id = $this->make_seat( $event_id, [
			'name'           => 'Pat Example',
			'email'          => 'pat@example.test',
			'phone'          => '555-0100',
			'source'         => 'manual',
			'ticket_type_id' => $tier_id,
		] );

		return [
			'event_id'     => $event_id,
			'tier_id'      => $tier_id,
			'variation_id' => $variation_id,
			'seat_id'      => $seat_id,
		];
	}

	public function test_module_exposes_manual_sales_when_wc_is_active() {
		$this->assertInstanceOf( Manual_Sales::class, $this->manual_sales() );
	}

	public function test_record_creates_completed_order_for_the_seats_tier() {
		$ctx = $this->event_with_manual_seat();

		$out = $this->manual_sales()->record( $ctx['seat_id'], 800.50, 'check' );

		$this->assertTrue( $out->is_sent(), 'reason: ' . $out->reason() );
		$order = wc_get_order( (int) $out->reason() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertEqualsWithDelta( 800.50, (float) $order->get_total(), 0.001 );
		$this->assertSame( Manual_Sales::CREATED_VIA, $order->get_created_via() );
		$this->assertSame( Manual_Sales::PAYMENT_METHOD, $order->get_payment_method() );
		$this->assertStringContainsString( 'Check', $order->get_payment_method_title() );
		$this->assertSame( 'pat@example.test', $order->get_billing_email() );
		$this->assertNotNull( $order->get_date_paid() );
		$this->assertSame( 'utm', $order->get_meta( '_wc_order_attribution_source_type' ) );
		$this->assertSame( Manual_Sales::ATTRIBUTION_SOURCE, $order->get_meta( '_wc_order_attribution_utm_source' ) );
		$this->assertSame( 'check', $order->get_meta( '_wc_order_attribution_utm_medium' ) );

		$items = array_values( $order->get_items() );
		$this->assertCount( 1, $items );
		$this->assertSame( $ctx['variation_id'], (int) $items[0]->get_variation_id(), 'The line buys the seat\'s own ticket tier.' );
		$this->assertSame( 1, (int) $items[0]->get_quantity() );
	}

	public function test_record_links_the_seat_and_reconcile_does_not_duplicate_it() {
		$ctx = $this->event_with_manual_seat();

		$out      = $this->manual_sales()->record( $ctx['seat_id'], 950, 'card' );
		$order_id = (int) $out->reason();

		$seat = $this->registrations()->get_seat( $ctx['seat_id'] );
		$this->assertSame( $order_id, $seat['order_id'] );
		$this->assertGreaterThan( 0, $seat['order_item_id'] );
		$this->assertSame( 'manual', $seat['source'], 'It stays a manual seat that now has an order.' );
		$this->assertSame( Registrations::STATUS_CONFIRMED, $seat['status'] );

		// The status change already reconciled; a further pass must converge too.
		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'test' );

		$this->assertSame( 1, $this->count_seats( $ctx['event_id'] ), 'One person, one seat.' );
		$this->assertSame( [ $ctx['seat_id'] ], array_map( 'intval', $this->registrations()->get_seats_for_order( $order_id ) ) );
	}

	public function test_record_sends_no_email() {
		$ctx = $this->event_with_manual_seat();

		$this->manual_sales()->record( $ctx['seat_id'], 950, 'check' );

		$this->assertSame( [], $this->sent, 'No WooCommerce receipt, admin alert or event email for an offline sale.' );
	}

	public function test_refunding_the_order_releases_the_seat() {
		$ctx      = $this->event_with_manual_seat();
		$order_id = (int) $this->manual_sales()->record( $ctx['seat_id'], 950, 'check' )->reason();

		$order = wc_get_order( $order_id );
		$order->update_status( 'refunded' );

		$seat = $this->registrations()->get_seat( $ctx['seat_id'] );
		$this->assertNotSame( Registrations::STATUS_CONFIRMED, $seat['status'] );
		$this->assertSame( 0, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
	}

	public function test_record_twice_is_refused_and_creates_no_second_order() {
		$ctx   = $this->event_with_manual_seat();
		$first = $this->manual_sales()->record( $ctx['seat_id'], 950, 'check' );
		$this->assertTrue( $first->is_sent() );

		$second = $this->manual_sales()->record( $ctx['seat_id'], 950, 'check' );

		$this->assertTrue( $second->is_failed() );
		$this->assertSame( 'already_has_order', $second->reason() );
		$orders = wc_get_orders( [ 'created_via' => Manual_Sales::CREATED_VIA, 'limit' => -1, 'return' => 'ids' ] );
		$this->assertCount( 1, $orders );
	}

	public function test_record_refuses_a_cancelled_seat() {
		$ctx = $this->event_with_manual_seat();
		$this->registrations()->update_status( $ctx['seat_id'], Registrations::STATUS_CANCELLED, 'test' );

		$out = $this->manual_sales()->record( $ctx['seat_id'], 950, 'check' );

		$this->assertTrue( $out->is_failed() );
		$this->assertSame( 'seat_not_active', $out->reason() );
	}

	public function test_record_backdates_the_order_to_the_payment_date() {
		$ctx  = $this->event_with_manual_seat();
		$date = wp_date( 'Y-m-d', time() - 20 * DAY_IN_SECONDS );

		$order_id = (int) $this->manual_sales()->record( $ctx['seat_id'], 950, 'check', [ 'date' => $date ] )->reason();

		$order = wc_get_order( $order_id );
		$this->assertSame( $date, $order->get_date_created()->date_i18n( 'Y-m-d' ) );
		$this->assertSame( $date, $order->get_date_paid()->date_i18n( 'Y-m-d' ) );
		$this->assertSame( $date, $order->get_date_completed()->date_i18n( 'Y-m-d' ) );
	}

	public function test_event_without_a_product_is_not_available() {
		$event_id = $this->make_event( [ 'title' => 'Free Event' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'source' => 'manual' ] );

		$this->assertFalse( $this->manual_sales()->available( $event_id ) );
		$out = $this->manual_sales()->record( $seat_id, 100, 'check' );
		$this->assertTrue( $out->is_failed() );
		$this->assertSame( 'no_product', $out->reason() );
	}

	/**
	 * A simple product linked to an event by hand (the product's Event tab),
	 * the way pre-existing course products are linked.
	 */
	private function linked_product( $event_id, $name, $price ) {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( (string) $price );
		$product->set_status( 'publish' );
		$product->set_virtual( true );
		$product->update_meta_data( \Anchor\Events\WooCommerce::META_ENABLED, '1' );
		$product->update_meta_data( \Anchor\Events\WooCommerce::META_EVENT_ID, $event_id );
		$product->save();
		return (int) $product->get_id();
	}

	public function test_hand_linked_event_records_against_the_chosen_product() {
		$event_id = $this->make_event( [ 'title' => 'Linked Event' ] );
		$dentist  = $this->linked_product( $event_id, 'Course - Dentist', 2995 );
		$staff    = $this->linked_product( $event_id, 'Course - Staff', 950 );
		$seat_id  = $this->make_seat( $event_id, [ 'source' => 'manual', 'name' => 'Lee Example', 'email' => 'lee@example.test' ] );

		$this->assertTrue( $this->manual_sales()->available( $event_id ) );
		$this->assertSame( [ $dentist, $staff ], array_keys( $this->manual_sales()->line_choices( $event_id ) ) );

		$none = $this->manual_sales()->record( $seat_id, 950, 'check' );
		$this->assertSame( 'pick_product', $none->reason(), 'Two possible tickets and none chosen: refuse, do not guess.' );

		$foreign = $this->manual_sales()->record( $seat_id, 950, 'check', [ 'product' => $this->linked_product( $this->make_event(), 'Other', 5 ) ] );
		$this->assertSame( 'pick_product', $foreign->reason(), 'A product linked to another event is not a choice.' );

		$out = $this->manual_sales()->record( $seat_id, 950, 'check', [ 'product' => $staff ] );
		$this->assertTrue( $out->is_sent(), 'reason: ' . $out->reason() );
		$order = wc_get_order( (int) $out->reason() );
		$items = array_values( $order->get_items() );
		$this->assertSame( $staff, (int) $items[0]->get_product_id() );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 1, $this->count_seats( $event_id ), 'The reconcile kept the one seat.' );
		$this->assertSame( (int) $out->reason(), $this->registrations()->get_seat( $seat_id )['order_id'] );
	}

	public function test_hand_linked_event_with_one_product_needs_no_choice() {
		$event_id = $this->make_event( [ 'title' => 'Single Linked Event' ] );
		$only     = $this->linked_product( $event_id, 'Course', 500 );
		$seat_id  = $this->make_seat( $event_id, [ 'source' => 'manual' ] );

		$out = $this->manual_sales()->record( $seat_id, 500, 'cash' );

		$this->assertTrue( $out->is_sent(), 'reason: ' . $out->reason() );
		$items = array_values( wc_get_order( (int) $out->reason() )->get_items() );
		$this->assertSame( $only, (int) $items[0]->get_product_id() );
	}

	public function test_from_request_reads_amount_method_and_date() {
		$_POST = [ 'roster_sale_amount' => '$2,195.00', 'roster_sale_method' => 'transfer', 'roster_sale_date' => wp_date( 'Y-m-d' ) ];
		$sale  = Manual_Sales::from_request();
		$this->assertSame( '', $sale['error'] );
		$this->assertSame( 2195.0, $sale['amount'] );
		$this->assertSame( 'transfer', $sale['method'] );

		$_POST = [ 'roster_sale_amount' => '' ];
		$this->assertNull( Manual_Sales::from_request()['amount'], 'Blank = no sale.' );

		$_POST = [ 'roster_sale_amount' => 'abc' ];
		$this->assertNotSame( '', Manual_Sales::from_request()['error'] );

		$_POST = [ 'roster_sale_amount' => '10', 'roster_sale_date' => '2999-01-01' ];
		$this->assertNotSame( '', Manual_Sales::from_request()['error'], 'A future payment date is refused.' );
	}

	public function test_from_request_refuses_tomorrow() {
		$_POST = [ 'roster_sale_amount' => '10', 'roster_sale_date' => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) ];
		$this->assertNotSame( '', Manual_Sales::from_request()['error'], 'Tomorrow is a future date too.' );
	}

	/** An order that cannot be completed leaves no order behind and the seat free to try again. */
	public function test_failed_completion_rolls_back_the_order_and_the_link() {
		$ctx  = $this->event_with_manual_seat();
		$boom = static function () {
			throw new \RuntimeException( 'status hook failed' );
		};
		// Before WooCommerce commits the new status, so the order never completes.
		add_action( 'woocommerce_before_order_object_save', $boom_on_complete = static function ( $order ) use ( $boom ) {
			if ( $order->get_status() === 'completed' ) {
				$boom();
			}
		} );

		$out = $this->manual_sales()->record( $ctx['seat_id'], 950, 'check' );

		remove_action( 'woocommerce_before_order_object_save', $boom_on_complete );
		$this->assertTrue( $out->is_failed() );
		$seat = $this->registrations()->get_seat( $ctx['seat_id'] );
		$this->assertSame( 0, $seat['order_id'], 'The seat is detached again.' );
		$this->assertSame( Registrations::STATUS_CONFIRMED, $seat['status'], 'Rolling back must not cancel the seat.' );
		$orders = wc_get_orders( [ 'created_via' => Manual_Sales::CREATED_VIA, 'limit' => -1, 'return' => 'ids', 'status' => 'any' ] );
		$this->assertCount( 0, $orders, 'No half-made order is left behind.' );

		$retry = $this->manual_sales()->record( $ctx['seat_id'], 950, 'check' );
		$this->assertTrue( $retry->is_sent(), 'The same seat can be recorded once the fault is gone.' );
	}
}
