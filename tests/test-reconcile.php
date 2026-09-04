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
	 * Create an order with one event line of $qty seats + attendee meta.
	 *
	 * @param int    $variation_id Variation (or plain product) the line buys.
	 * @param int    $qty
	 * @param string $status       Order status to leave it in ('pending' = placed
	 *                             but unpaid, so the reconcile creates no seats).
	 * @return array{order:WC_Order,item_id:int}
	 */
	private function make_order( $variation_id, $qty, $status = 'processing' ) {
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

		// Move to the target status AFTER the items (incl. attendee meta) are
		// persisted.
		$order->set_status( $status );
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

	/* ------------------------------------------------------------------
	 * WOO-D42 — classify_refund() compares integer minor units, not floats.
	 * ------------------------------------------------------------------ */

	/** classify_refund() via reflection (private on purpose — internal only). */
	private function classify_refund( $order, $refund_id ) {
		$method = new ReflectionMethod( get_class( $this->woocommerce() ), 'classify_refund' );
		$method->setAccessible( true );
		return $method->invoke( $this->woocommerce(), $order, $refund_id );
	}

	/**
	 * The audit's concrete failure: a line total carrying WooCommerce's
	 * higher internal (sub-cent) precision landed the refund amount 0.0104
	 * away from the summed line total once represented as a float — a plain
	 * line refund with no extra amount, which the old `> 0.01` float compare
	 * misclassified as 'mixed' (and permanently flagged
	 * `mixed_refund_extra_amount` on an order with nothing to review).
	 */
	public function test_a_sub_cent_precision_line_total_is_not_misclassified_as_mixed() {
		$ctx = $this->paid_event_with_variation();

		$variation = wc_get_product( $ctx['variation_id'] );
		$item      = new WC_Order_Item_Product();
		$item->set_product( $variation );
		$item->set_quantity( 1 );
		$item->set_subtotal( 30 );
		$item->set_total( 30 );
		$order = new WC_Order();
		$order->add_item( $item );
		$order->set_billing_email( 'buyer@example.test' );
		$order->calculate_totals( false );
		$order->save();
		$order->set_status( 'processing' );
		$order->save();

		$refund = wc_create_refund( [
			'order_id'   => $order->get_id(),
			'amount'     => 23.44,
			'line_items' => [ $item->get_id() => [ 'qty' => -1, 'refund_total' => 23.4296 ] ],
		] );
		$this->assertNotWPError( $refund );

		$this->assertSame(
			'line',
			$this->classify_refund( wc_get_order( $order->get_id() ), $refund->get_id() ),
			'A sub-cent rounding artifact must not read as an unexplained extra amount.'
		);
	}

	/** A GENUINE extra amount beyond the line totals is still caught. */
	public function test_a_real_extra_amount_is_still_classified_as_mixed() {
		$ctx = $this->paid_event_with_variation();

		$variation = wc_get_product( $ctx['variation_id'] );
		$item      = new WC_Order_Item_Product();
		$item->set_product( $variation );
		$item->set_quantity( 1 );
		$item->set_subtotal( 30 );
		$item->set_total( 30 );
		$order = new WC_Order();
		$order->add_item( $item );
		$order->set_billing_email( 'buyer@example.test' );
		$order->calculate_totals( false );
		$order->save();
		$order->set_status( 'processing' );
		$order->save();

		// $10 of real, unexplained extra amount beyond the $20 line refund.
		$refund = wc_create_refund( [
			'order_id'   => $order->get_id(),
			'amount'     => 30.00,
			'line_items' => [ $item->get_id() => [ 'qty' => -1, 'refund_total' => 20.00 ] ],
		] );
		$this->assertNotWPError( $refund );

		$this->assertSame(
			'mixed',
			$this->classify_refund( wc_get_order( $order->get_id() ), $refund->get_id() )
		);
	}

	/* ------------------------------------------------------------------
	 * WOO-D58 (quota half) — the order's tier row was removed from the event.
	 * ------------------------------------------------------------------ */

	/**
	 * A two-tier paid event, synced. @return array{event_id:int,tiers:array}
	 */
	private function two_tier_event() {
		$event_id = $this->make_event(
			[ 'title' => 'Two Tier Reconcile', 'capacity' => 0 ],
			[
				[ 'label' => 'General', 'price' => '10', 'active' => 1 ],
				[ 'label' => 'VIP', 'price' => '10', 'active' => 1 ],
			]
		);
		$this->product_sync()->sync_event( $event_id );
		return [ 'event_id' => $event_id, 'tiers' => $this->ticket_types()->get( $event_id ) ];
	}

	/** Needs-review reasons currently flagged on an order. @return string[] */
	private function review_reasons( $order_id ) {
		$flags = wc_get_order( $order_id )->get_meta( \Anchor\Events\Events_Log::ORDER_REVIEW_META );
		return is_array( $flags ) ? array_column( $flags, 'reason' ) : [];
	}

	/** Error-log codes recorded site-wide. @return string[] */
	private function error_codes() {
		$log = get_option( \Anchor\Events\Events_Log::ERROR_OPTION, [] );
		return is_array( $log ) ? array_column( $log, 'code' ) : [];
	}

	/**
	 * The organizer deletes a tier row that has an order against it. A missing
	 * tier used to read as quota 0 = UNLIMITED, so the reconcile happily minted
	 * seats no quota could bind. It must mint none, log, and flag instead.
	 */
	public function test_order_for_a_removed_tier_creates_no_seats_and_flags_review() {
		$ctx      = $this->two_tier_event();
		$event_id = $ctx['event_id'];
		$vip      = $ctx['tiers'][1];
		$vip_vid  = (int) $this->product_sync()->variation_for_tier( $event_id, $vip['id'] );
		$this->assertGreaterThan( 0, $vip_vid );

		// Remove the VIP row from the Tickets metabox FIRST (the variation is
		// untouched, so a line bought against it still resolves to the now-dead
		// tier id) — then the order arrives and reconciles.
		$this->ticket_types()->save(
			$event_id,
			[ [ 'id' => $ctx['tiers'][0]['id'], 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->assertNull( $this->ticket_types()->find( $event_id, $vip['id'] ) );

		delete_option( \Anchor\Events\Events_Log::ERROR_OPTION );
		$res      = $this->make_order( $vip_vid, 2 );
		$order_id = $res['order']->get_id();
		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'test' );

		$this->assertSame( 0, $this->count_seats( $event_id, null, $vip['id'] ), 'No seat on a retired tier.' );
		$this->assertSame( 0, $this->count_seats( $event_id, Registrations::STATUS_CONFIRMED ) );
		$this->assertContains( 'retired_tier', $this->review_reasons( $order_id ) );
		$this->assertContains( 'retired_tier', $this->error_codes() );
	}

	/** …while an order whose tier still exists reconciles exactly as before. */
	public function test_order_for_a_live_tier_still_reconciles() {
		$ctx      = $this->two_tier_event();
		$event_id = $ctx['event_id'];
		$ga       = $ctx['tiers'][0];
		$ga_vid   = (int) $this->product_sync()->variation_for_tier( $event_id, $ga['id'] );

		$res      = $this->make_order( $ga_vid, 2 );
		$order_id = $res['order']->get_id();

		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'test' );

		$this->assertSame( 2, $this->count_seats( $event_id, Registrations::STATUS_CONFIRMED, $ga['id'] ) );
		$this->assertNotContains( 'retired_tier', $this->review_reasons( $order_id ) );
	}

	/**
	 * Seats already sold on a tier survive its removal and keep consuming the
	 * EVENT capacity — the retirement blocks new seats, it does not release old
	 * ones.
	 */
	public function test_existing_seats_on_a_removed_tier_survive_and_still_count() {
		$ctx      = $this->two_tier_event();
		$event_id = $ctx['event_id'];
		$vip      = $ctx['tiers'][1];
		$vip_vid  = (int) $this->product_sync()->variation_for_tier( $event_id, $vip['id'] );

		$res      = $this->make_order( $vip_vid, 2 );
		$order_id = $res['order']->get_id();
		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'initial' );
		$this->assertSame( 2, $this->count_seats( $event_id, Registrations::STATUS_CONFIRMED, $vip['id'] ) );

		$this->ticket_types()->save(
			$event_id,
			[ [ 'id' => $ctx['tiers'][0]['id'], 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);

		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'after retirement' );

		$this->assertSame(
			2,
			$this->count_seats( $event_id, Registrations::STATUS_CONFIRMED, $vip['id'] ),
			'Converged seats are left alone; nothing is removed by the retirement.'
		);
		$this->assertSame(
			2,
			(int) $this->registrations()->count_reserved_seats( $event_id, true ),
			'…and they still consume the event capacity.'
		);
	}

	/**
	 * WOO-D58 false positive: "the tier was retired" is only true when the id
	 * came from the LINE's own variation. A line with no variation resolves its
	 * tier through primary_id(), which returns the SYNTHETIC 'primary' id once
	 * every authored row is deactivated — an id find() can never match while
	 * rows exist. Reading that as a retirement refused seats to a buyer who had
	 * already paid, on an event where nobody had deleted anything.
	 */
	public function test_all_tiers_inactive_is_not_a_retired_tier() {
		$event_id = $this->make_event(
			[ 'title' => 'Linked Product Event', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$tier_id = $this->ticket_types()->get( $event_id )[0]['id'];

		// A manually LINKED simple product: its order line carries no variation,
		// so the reconcile resolves the tier through primary_id().
		$product = new WC_Product_Simple();
		$product->set_name( 'Course Seat' );
		$product->set_regular_price( '10' );
		$product->save();
		$product_id = (int) $product->get_id();
		update_post_meta( $product_id, \Anchor\Events\WooCommerce::META_ENABLED, '1' );
		update_post_meta( $product_id, \Anchor\Events\WooCommerce::META_EVENT_ID, $event_id );

		// The order is placed and sits awaiting payment — no seats yet.
		$res      = $this->make_order( $product_id, 2, 'pending' );
		$order_id = (int) $res['order']->get_id();
		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'placed' );
		$this->assertSame( 0, $this->count_seats( $event_id, Registrations::STATUS_CONFIRMED ) );

		// The organizer closes sales by deactivating every tier row. Nothing is
		// retired: the rows are all still there, and primary_id() now falls
		// through to the implicit-primary id.
		$this->ticket_types()->save(
			$event_id,
			[ [ 'id' => $tier_id, 'label' => 'General', 'price' => '10', 'active' => 0 ] ]
		);
		$this->assertSame(
			\Anchor\Events\Ticket_Types::PRIMARY_ID,
			$this->ticket_types()->primary_id( $event_id ),
			'Precondition: with no active tier the primary id is the synthetic one.'
		);
		$this->assertNull( $this->ticket_types()->find( $event_id, \Anchor\Events\Ticket_Types::PRIMARY_ID ) );

		delete_option( \Anchor\Events\Events_Log::ERROR_OPTION );

		// Payment lands.
		$paid = wc_get_order( $order_id );
		$paid->set_status( 'processing' );
		$paid->save();
		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'paid' );

		$this->assertSame(
			2,
			$this->count_seats( $event_id, Registrations::STATUS_CONFIRMED ),
			'A paid line must still get its seats when no tier was ever retired.'
		);
		$this->assertNotContains( 'retired_tier', $this->review_reasons( $order_id ) );
		$this->assertNotContains( 'retired_tier', $this->error_codes() );
	}

	/**
	 * …and neither is a variation that belongs to a DIFFERENT event. Tier ids
	 * are unique within an event, not across the site, so a product re-pointed
	 * at another event (the link metabox, a duplicated product) hands back an id
	 * naming the other event's tier — a tier this event has never had, which is
	 * indistinguishable from a deleted one. The line falls back to the
	 * event-level path.
	 */
	public function test_a_variation_owned_by_another_event_is_not_a_retired_tier() {
		// The event the ORDER is for, with its own live tier.
		$host_id = $this->make_event(
			[ 'title' => 'Host Event', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$host_tier = $this->ticket_types()->get( $host_id )[0]['id'];

		// A DIFFERENT event's managed product, whose variation carries that
		// event's tier id.
		$other      = $this->two_tier_event();
		$other_tier = $other['tiers'][1];
		$other_vid  = (int) $this->product_sync()->variation_for_tier( $other['event_id'], $other_tier['id'] );
		$this->assertGreaterThan( 0, $other_vid );
		$this->assertNull(
			$this->ticket_types()->find( $host_id, $other_tier['id'] ),
			'Precondition: the host event has never had that tier.'
		);

		// Somebody points that variation's line at the host event.
		update_post_meta( $other_vid, \Anchor\Events\WooCommerce::META_EVENT_ID, $host_id );

		delete_option( \Anchor\Events\Events_Log::ERROR_OPTION );
		$res      = $this->make_order( $other_vid, 2 );
		$order_id = (int) $res['order']->get_id();
		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'test' );

		$this->assertSame(
			2,
			$this->count_seats( $host_id, Registrations::STATUS_CONFIRMED, $host_tier ),
			'The line reconciles against the host event\'s own primary tier.'
		);
		$this->assertNotContains( 'retired_tier', $this->review_reasons( $order_id ) );
		$this->assertNotContains( 'retired_tier', $this->error_codes() );
	}
}
