<?php
/**
 * Order → seat reconcile + refund tests (require WooCommerce — skipped without WC).
 *
 * Builds orders/products through the public WC CRUD (no WC test helpers required).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Registrations;

/**
 * @group woocommerce
 * @group reconcile
 */
class Test_Reconcile extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
	}

	/**
	 * Build a paid event (single tier) + its managed variation.
	 *
	 * @return array{event_id:int,tier_id:string,variation_id:int}
	 */
	private function paid_event_with_variation() {
		$event_id = $this->make_event(
			[ 'title' => 'Reconcile Event', 'capacity' => 0 ], // unlimited.
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$tier_id      = $tiers[0]['id'];
		$variation_id = $this->product_sync()->variation_for_tier( $event_id, $tier_id );
		$this->assertGreaterThan( 0, $variation_id );

		return [
			'event_id'     => $event_id,
			'tier_id'      => $tier_id,
			'variation_id' => $variation_id,
		];
	}

	/**
	 * Create a processing order with one event line of $qty seats + attendee meta.
	 *
	 * @return array{order:WC_Order,item_id:int}
	 */
	private function make_order( $variation_id, $qty ) {
		$variation = wc_get_product( $variation_id );

		$item = new WC_Order_Item_Product();
		$item->set_product( $variation );
		$item->set_quantity( $qty );
		$item->set_subtotal( 10 * $qty );
		$item->set_total( 10 * $qty );

		// Per-seat attendee data keyed 1..qty (the shape persist_attendees writes).
		$attendees = [];
		for ( $i = 1; $i <= $qty; $i++ ) {
			$attendees[ $i ] = [
				'name'  => 'Attendee ' . $i,
				'email' => 'attendee' . $i . '@example.test',
				'phone' => '555-000' . $i,
			];
		}
		$item->add_meta_data( '_anchor_attendees', $attendees, true );

		$order = new WC_Order();
		$order->add_item( $item );
		$order->set_billing_email( 'buyer@example.test' );
		$order->set_billing_first_name( 'Buyer' );
		// Compute the order total from the line so the order has a refundable amount
		// (wc_create_refund rejects refunds exceeding get_remaining_refund_amount()).
		$order->calculate_totals( false );
		$order->save();

		// Move to processing AFTER the items (incl. attendee meta) are persisted.
		$order->set_status( 'processing' );
		$order->save();

		return [ 'order' => $order, 'item_id' => $item->get_id() ];
	}

	/** A processing order creates N confirmed seats tagged with the right tier. */
	public function test_processing_order_creates_confirmed_seats() {
		$ctx = $this->paid_event_with_variation();
		$res = $this->make_order( $ctx['variation_id'], 2 );

		$this->woocommerce()->reconcile_order( wc_get_order( $res['order']->get_id() ), 'test' );

		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
		$this->assertSame(
			2,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED, $ctx['tier_id'] ),
			'Both seats should be tagged with the purchased tier.'
		);
	}

	/** Re-running reconcile on a converged order creates no duplicate seats. */
	public function test_reconcile_is_idempotent() {
		$ctx = $this->paid_event_with_variation();
		$res = $this->make_order( $ctx['variation_id'], 2 );

		$this->woocommerce()->reconcile_order( wc_get_order( $res['order']->get_id() ), 'first' );
		$this->woocommerce()->reconcile_order( wc_get_order( $res['order']->get_id() ), 'second' );

		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
	}

	/** A line refund of qty 1 transitions exactly one seat to refunded; the rest stay. */
	public function test_partial_line_refund_refunds_one_seat() {
		$ctx      = $this->paid_event_with_variation();
		$res      = $this->make_order( $ctx['variation_id'], 2 );
		$order_id = $res['order']->get_id();
		$item_id  = $res['item_id'];

		// Create the seats first.
		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'initial' );
		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		// Refund one ticket (qty 1, $10).
		$refund = wc_create_refund(
			[
				'order_id'   => $order_id,
				'amount'     => 10,
				'line_items' => [
					$item_id => [ 'qty' => 1, 'refund_total' => 10 ],
				],
			]
		);
		$this->assertNotWPError( $refund );

		// Drive the refund reconcile (surplus active seats → refunded).
		$this->woocommerce()->on_order_refunded( $order_id, $refund->get_id() );

		$this->assertSame(
			1,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_REFUNDED ),
			'Exactly one seat should be refunded.'
		);
		$this->assertSame(
			1,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ),
			'The remaining seat should stay confirmed.'
		);

		// Re-firing the refund reconcile is a no-op (expected already lowered).
		$this->woocommerce()->on_order_refunded( $order_id, $refund->get_id() );
		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_REFUNDED ) );
		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
	}
}
