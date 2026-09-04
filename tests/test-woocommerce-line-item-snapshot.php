<?php
/**
 * The order-line / cart-line event snapshot (WOO-D7, WOO-D18).
 *
 * persist_attendees_to_line_item() writes a link snapshot onto the order line
 * so reconcile can resolve the event even after a later un-link. Two defects
 * lived here: two extra meta keys nobody ever read (WOO-D7), and the CART
 * item itself carrying no snapshot at all, so a live link removed while the
 * cart is still open silently stopped being an event line — no attendee
 * fields, no capacity check, and a paid order reconcile skipped with no trace
 * (WOO-D18).
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group woocommerce
 */
class Test_WooCommerce_Line_Item_Snapshot extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
	}

	/** A paid event synced to a managed product + its sole variation id. */
	private function make_ticketed_event() {
		$event_id = $this->make_event(
			[ 'title' => 'Snapshot Event', 'registration_mode' => 'wc' ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$variation_id = (int) $this->product_sync()->variation_for_tier( $event_id, $tiers[0]['id'] );
		$this->assertGreaterThan( 0, $variation_id );
		return [ $event_id, $variation_id ];
	}

	/**
	 * WOO-D7: `_anchor_product_id` / `_anchor_variation_id` are no longer
	 * written — the order item already carries its own product/variation id,
	 * and grepping the whole plugin + theme found zero readers of either key.
	 */
	public function test_persist_attendees_does_not_write_the_unused_product_variation_snapshot_keys() {
		list( $event_id, $variation_id ) = $this->make_ticketed_event();

		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( 1 );

		$order = new WC_Order();
		$order->add_item( $item );

		$_POST = [];
		$this->woocommerce()->persist_attendees_to_line_item( $item, 'cartkey', [], $order );

		$this->assertSame( $event_id, (int) $item->get_meta( '_anchor_event_id' ) );
		$this->assertSame( '', $item->get_meta( '_anchor_product_id' ) );
		$this->assertSame( '', $item->get_meta( '_anchor_variation_id' ) );
	}

	/**
	 * WOO-D18: the cart line's event id is snapshotted at add-to-cart time
	 * (woocommerce_add_cart_item_data) and preferred over the live resolver,
	 * so removing the product's link while the cart is open does not turn a
	 * paid seat into a silent no-op at reconcile.
	 */
	public function test_cart_line_keeps_its_event_after_the_live_link_is_removed() {
		list( $event_id, $variation_id ) = $this->make_ticketed_event();

		$cart_item_data = $this->woocommerce()->snapshot_event_on_add_to_cart(
			[],
			(int) wc_get_product( $variation_id )->get_parent_id(),
			$variation_id
		);
		$this->assertSame( $event_id, (int) ( $cart_item_data['anchor_event_id'] ?? 0 ) );

		// Simulate the live link being removed mid-cart (admin unticks the
		// product's "Register buyer for an event", or trashes the event) —
		// event_for_line() now resolves to 0.
		update_post_meta( $variation_id, \Anchor\Events\WooCommerce::META_EVENT_ID, 0 );
		$this->assertSame( 0, $this->woocommerce()->event_for_variation( $variation_id ) );

		// persist_attendees_to_line_item() receives the cart item's $values
		// (which still carry the snapshot) and must still resolve the event.
		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( 1 );
		$order = new WC_Order();
		$order->add_item( $item );

		$_POST = [];
		$this->woocommerce()->persist_attendees_to_line_item( $item, 'cartkey', $cart_item_data, $order );

		$this->assertSame(
			$event_id,
			(int) $item->get_meta( '_anchor_event_id' ),
			'The order line must still snapshot the event even though the live link is gone.'
		);
	}
}
