<?php
/**
 * Untrash / trash-mirror tests (audit MODEL-D15, WOO-D17, WOO-D34, WOO-D52, WOO-D53).
 *
 * Every trash hook the module registers had to grow the restore half, because
 * WordPress fires `untrashed_post` — never `save_post` — when a post comes back
 * out of the trash, so nothing that keys off a save ever ran again:
 *   - a restored group parent left its children retired (MODEL-D15);
 *   - a restored order left every seat cancelled (WOO-D17);
 *   - a restored event left its managed product a draft (WOO-D34);
 *   - trashing an order on legacy (posts) order storage never released its
 *     seats at all, because `woocommerce_trash_order` only fires from the WC
 *     data store, not from the list table's `wp_trash_post()` (WOO-D52);
 *   - deleting a refund gave the money back without giving the seat back
 *     (WOO-D53).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Registrations;

/**
 * @group untrash
 */
class Test_Untrash extends Anchor_Events_TestCase {

	/** @return \Anchor\Events\Occurrences */
	protected function occurrences() {
		return $this->module()->occurrences;
	}

	/* ------------------------------------------------------------------
	 * MODEL-D15 — group parent trash/untrash.
	 * ------------------------------------------------------------------ */

	/**
	 * Create + reconcile a group-parent offering with two dates.
	 *
	 * @return array{0:int,1:int[]} [ parent_id, live child ids (date-ascending) ]
	 */
	private function make_offering() {
		$parent_id = $this->make_event( [ 'title' => 'Offering', 'timezone' => 'UTC' ] );
		update_post_meta(
			$parent_id,
			'_anchor_event_offering_dates',
			[
				[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 5 ],
				[ 'date' => '2027-05-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B', 'capacity' => 5 ],
			]
		);
		return [ $parent_id, $this->occurrences()->reconcile( $parent_id ) ];
	}

	/** Untrashing a group parent brings its trashed (unseated) children back published. */
	public function test_untrashing_parent_republishes_trashed_children() {
		[ $parent_id, $children ] = $this->make_offering();
		$this->assertCount( 2, $children );

		wp_trash_post( $parent_id );
		foreach ( $children as $child_id ) {
			$this->assertSame( 'trash', get_post_status( $child_id ), 'An unseated child is trashed with its parent.' );
		}

		wp_untrash_post( $parent_id );

		foreach ( $children as $child_id ) {
			$this->assertSame(
				'publish',
				get_post_status( $child_id ),
				'Restoring the parent must restore its occurrences.'
			);
		}
	}

	/** ...and the parent's date picker lists them again. */
	public function test_untrashed_parent_picker_lists_children_again() {
		[ $parent_id, $children ] = $this->make_offering();

		wp_trash_post( $parent_id );
		$this->assertStringNotContainsString(
			esc_url( get_permalink( $children[0] ) ),
			$this->module()->render_choose_date_list( $parent_id ),
			'A retired occurrence must not still be offered.'
		);

		wp_untrash_post( $parent_id );

		$html = $this->module()->render_choose_date_list( $parent_id );
		foreach ( $children as $child_id ) {
			$this->assertStringContainsString( esc_url( get_permalink( $child_id ) ), $html );
		}
		$this->assertStringContainsString( 'May 1, 2027', $html );
		$this->assertStringContainsString( 'May 8, 2027', $html );
	}

	/** A SEATED child is soft-closed by the trash, and reopened by the untrash. */
	public function test_untrashing_parent_reopens_soft_closed_child() {
		[ $parent_id, $children ] = $this->make_offering();
		$seated = (int) $children[0];
		$this->make_seat( $seated );

		wp_trash_post( $parent_id );
		$this->assertSame( 'publish', get_post_status( $seated ), 'A seated child is preserved, not trashed.' );
		$this->assertTrue( $this->occurrences()->is_closed( $seated ), 'A seated child is soft-closed.' );

		wp_untrash_post( $parent_id );

		$this->assertFalse(
			$this->occurrences()->is_closed( $seated ),
			'Restoring the parent must reopen a soft-closed occurrence.'
		);
		$this->assertNotSame(
			'cancelled',
			get_post_meta( $seated, '_anchor_event_status', true ),
			'The reopened occurrence must not stay cancelled.'
		);
	}

	/** A plain (non-parent) event untrash must not be turned into a group parent. */
	public function test_untrashing_plain_event_does_not_create_occurrences() {
		$event_id = $this->make_event( [ 'title' => 'Standalone' ] );

		wp_trash_post( $event_id );
		wp_untrash_post( $event_id );

		$this->assertSame(
			'',
			(string) get_post_meta( $event_id, '_anchor_event_group_role', true ),
			'An ordinary event must never be stamped as a group parent by an untrash.'
		);
	}

	/* ------------------------------------------------------------------
	 * WOO-D17 / WOO-D52 — order trash/untrash on legacy (posts) storage.
	 * ------------------------------------------------------------------ */

	/**
	 * Build a paid event + its managed variation.
	 *
	 * @return array{event_id:int,tier_id:string,variation_id:int}
	 */
	private function paid_event_with_variation() {
		$event_id = $this->make_event(
			[ 'title' => 'Untrash Event', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$tier_id      = $tiers[0]['id'];
		$variation_id = $this->product_sync()->variation_for_tier( $event_id, $tier_id );
		$this->assertGreaterThan( 0, $variation_id );

		return [ 'event_id' => $event_id, 'tier_id' => $tier_id, 'variation_id' => $variation_id ];
	}

	/**
	 * A processing order with one event line of $qty seats + attendee meta.
	 *
	 * @return array{order:WC_Order,item_id:int}
	 */
	private function make_order( $variation_id, $qty ) {
		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( $qty );
		$item->set_subtotal( 10 * $qty );
		$item->set_total( 10 * $qty );

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
		$order->calculate_totals( false );
		$order->save();

		$order->set_status( 'processing' );
		$order->save();

		return [ 'order' => $order, 'item_id' => $item->get_id() ];
	}

	/**
	 * Force legacy (posts) order storage for this test — the path under test,
	 * and the one this store actually runs
	 * (woocommerce_custom_orders_table_enabled = no).
	 *
	 * WooCommerce resolves the order data store from that option on every
	 * WC_Data_Store::load('order'), so flipping it before any order is built is
	 * enough; the option write is rolled back with the rest of the test's
	 * transaction. Set explicitly rather than skipped-when-absent because the
	 * suite's earlier tests boot WC far enough for its installer to turn HPOS
	 * on, which would silently skip exactly the regression this file exists for.
	 */
	private function require_cpt_order_storage() {
		$this->require_wc();
		update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
		if (
			class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			$this->markTestSkipped( 'Legacy (posts) order storage could not be selected in this run.' );
		}
	}

	/** WOO-D52: trashing an order on legacy storage releases its seats. */
	public function test_trashing_legacy_order_releases_seats() {
		$this->require_cpt_order_storage();

		$ctx      = $this->paid_event_with_variation();
		$res      = $this->make_order( $ctx['variation_id'], 2 );
		$order_id = (int) $res['order']->get_id();

		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'initial' );
		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		wp_trash_post( $order_id );

		$this->assertSame(
			0,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ),
			'Trashing the order from the posts list must release its capacity.'
		);
		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CANCELLED ) );
	}

	/** WOO-D17: restoring a trashed processing order confirms its seats again. */
	public function test_untrashing_legacy_order_restores_seats() {
		$this->require_cpt_order_storage();

		$ctx      = $this->paid_event_with_variation();
		$res      = $this->make_order( $ctx['variation_id'], 2 );
		$order_id = (int) $res['order']->get_id();

		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'initial' );
		wp_trash_post( $order_id );
		$this->assertSame( 0, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		wp_untrash_post( $order_id );

		$this->assertSame(
			'processing',
			wc_get_order( $order_id )->get_status(),
			'WooCommerce restores the order to its pre-trash status.'
		);
		$this->assertSame(
			2,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ),
			'Restoring the order must restore its seats.'
		);
		$this->assertSame( 0, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CANCELLED ) );
	}

	/** Trashing an ordinary (non-event) order is a complete no-op. */
	public function test_trashing_non_event_order_is_a_no_op() {
		$this->require_cpt_order_storage();

		$product = new WC_Product_Simple();
		$product->set_name( 'Plain Widget' );
		$product->set_regular_price( '5' );
		$product->save();

		$order = new WC_Order();
		$order->add_product( $product, 1 );
		$order->calculate_totals( false );
		$order->save();
		$order->set_status( 'processing' );
		$order->save();
		$order_id = (int) $order->get_id();

		wp_trash_post( $order_id );
		wp_untrash_post( $order_id );

		$this->assertSame( '', (string) get_post_meta( $order_id, '_anchor_event_sync_log', true ) );
	}

	/* ------------------------------------------------------------------
	 * WOO-D53 — refund deleted.
	 * ------------------------------------------------------------------ */

	/**
	 * Deleting a refund gives the seat back: the line's active seat count
	 * returns to the ordered quantity and the released capacity is re-taken.
	 */
	public function test_deleting_refund_restores_the_released_seat() {
		$this->require_wc();

		$ctx      = $this->paid_event_with_variation();
		$res      = $this->make_order( $ctx['variation_id'], 2 );
		$order_id = (int) $res['order']->get_id();
		$item_id  = (int) $res['item_id'];

		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'initial' );
		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		$refund = wc_create_refund(
			[
				'order_id'   => $order_id,
				'amount'     => 10,
				'line_items' => [ $item_id => [ 'qty' => 1, 'refund_total' => 10 ] ],
			]
		);
		$this->assertNotWPError( $refund );
		$refund_id = (int) $refund->get_id();

		$this->woocommerce()->on_order_refunded( $order_id, $refund_id );
		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_REFUNDED ) );

		// WooCommerce deletes the refund, then fires the hook (WC_AJAX::delete_refund).
		$refund->delete( true );
		do_action( 'woocommerce_refund_deleted', $refund_id, $order_id );

		$this->assertSame(
			2,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ),
			'Deleting the refund must give the seat back.'
		);
		$this->assertSame(
			2,
			$this->registrations()->count_reserved_seats( $ctx['event_id'], true ),
			'…and re-take the capacity the refund released.'
		);
	}

	/* ------------------------------------------------------------------
	 * WOO-D34 — event trash/untrash vs. the managed product.
	 * ------------------------------------------------------------------ */

	/** Untrashing a paid event republishes the managed product the trash drafted. */
	public function test_untrashing_event_republishes_managed_product() {
		$this->require_wc();

		$ctx        = $this->paid_event_with_variation();
		$event_id   = $ctx['event_id'];
		$product_id = $this->product_sync()->managed_product_id( $event_id );
		$this->assertGreaterThan( 0, $product_id );
		$this->assertSame( 'publish', wc_get_product( $product_id )->get_status() );

		wp_trash_post( $event_id );
		$this->assertSame( 'draft', wc_get_product( $product_id )->get_status() );

		wp_untrash_post( $event_id );

		$this->assertSame(
			'publish',
			wc_get_product( $product_id )->get_status(),
			'Restoring the event must republish its managed product.'
		);
		$this->assertSame(
			$product_id,
			$this->product_sync()->managed_product_id( $event_id ),
			'…the same product, not a second one.'
		);
	}
}
