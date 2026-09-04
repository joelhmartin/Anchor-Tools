<?php
/**
 * Cart-wide capacity enforcement (WOO-D26, WOO-D27).
 *
 * validate_add_to_cart() only ever compared the INCOMING quantity against
 * remaining capacity, ignoring how many seats for the SAME event were
 * already sitting in the cart from an earlier add — so two individually
 * valid adds could together overfill a capacity-limited event, each one
 * reporting success. notice_over_capacity_cart_items() detected that kind of
 * overfill (however it got there) but only left a notice, with no path back
 * to a valid cart except the shopper's own stock cart form.
 *
 * Both checks are advisory (cart validation, no lock) — reconcile under the
 * per-event lock at payment time remains the authority that cannot be raced.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group woocommerce
 */
class Test_WooCommerce_Cart_Capacity extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
		WC()->cart->empty_cart();
	}

	/** A paid event with a fixed capacity, synced, waitlist off. */
	private function make_capped_event( $capacity ) {
		$event_id = $this->make_event(
			[ 'title' => 'Capped Event', 'timezone' => 'UTC', 'capacity' => $capacity, 'waitlist' => false ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers = $this->ticket_types()->get( $event_id );
		$vid   = (int) $this->product_sync()->variation_for_tier( $event_id, $tiers[0]['id'] );
		$this->assertGreaterThan( 0, $vid );
		$product_id = (int) wc_get_product( $vid )->get_parent_id();
		return [ $event_id, $product_id, $vid ];
	}

	/**
	 * WOO-D26: a second add-to-cart that individually fits within $remaining
	 * must still be refused once it would push the CART's total for this
	 * event past capacity.
	 *
	 * validate_add_to_cart() is called directly here — in the installed
	 * WooCommerce version the `woocommerce_add_to_cart_validation` filter is
	 * applied by WC_Form_Handler/WC_AJAX around a native add-to-cart request,
	 * NOT inside WC_Cart::add_to_cart() itself, so exercising it end-to-end
	 * through WC()->cart->add_to_cart() would not reach it at all.
	 */
	public function test_a_second_add_that_would_overfill_the_cart_is_refused() {
		list( $event_id, $product_id, $vid ) = $this->make_capped_event( 5 );

		$key1 = WC()->cart->add_to_cart( $product_id, 3, $vid );
		$this->assertNotFalse( $key1, 'The first add (3 of 5) must succeed.' );
		$this->assertSame( 3, WC()->cart->get_cart_contents_count() );

		$this->assertFalse(
			$this->woocommerce()->validate_add_to_cart( true, $product_id, 3, $vid ),
			'3 already in the cart + 3 more against capacity 5 must be refused.'
		);
	}

	/** A second add that still fits within the remaining room is allowed. */
	public function test_a_second_add_that_still_fits_is_allowed() {
		list( $event_id, $product_id, $vid ) = $this->make_capped_event( 5 );

		WC()->cart->add_to_cart( $product_id, 3, $vid );

		$this->assertTrue( $this->woocommerce()->validate_add_to_cart( true, $product_id, 2, $vid ) );
	}

	/**
	 * WOO-D27: notice_over_capacity_cart_items() must CLAMP an over-capacity
	 * line to what remains, not just notify and leave it broken.
	 */
	public function test_over_capacity_line_is_clamped_not_just_flagged() {
		list( $event_id, $product_id, $vid ) = $this->make_capped_event( 5 );

		// One single add of 8 against a capacity of 5: is_purchasable() only
		// asks whether the EVENT still has room at all (it does — remaining
		// capacity is 5), not whether this specific quantity fits, so the add
		// itself succeeds. This is the shape notice_over_capacity_cart_items()
		// exists to catch.
		$key = WC()->cart->add_to_cart( $product_id, 8, $vid );
		$this->assertNotFalse( $key );
		$this->assertSame( 8, WC()->cart->get_cart_contents_count() );

		$this->woocommerce()->notice_over_capacity_cart_items();

		$this->assertSame(
			5,
			WC()->cart->get_cart_contents_count(),
			'The line must be clamped down to the event\'s remaining capacity.'
		);
	}

	/** Clamping to zero removes the line entirely rather than leaving a 0-qty row. */
	public function test_over_capacity_line_is_removed_when_nothing_remains() {
		list( $event_id, $product_id, $vid ) = $this->make_capped_event( 5 );

		$key = WC()->cart->add_to_cart( $product_id, 3, $vid );
		$this->assertNotFalse( $key );

		// Capacity is fully consumed by real seats created AFTER the cart add
		// (e.g. another buyer completed checkout in the meantime) — remaining
		// drops to 0 while the cart line is untouched.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_seat( $event_id );
		}

		$this->woocommerce()->notice_over_capacity_cart_items();

		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
	}
}
