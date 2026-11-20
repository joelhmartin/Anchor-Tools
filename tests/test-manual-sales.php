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
}
