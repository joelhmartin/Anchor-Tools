<?php
/**
 * Every purchase-path reader asks Module::bookability() (WOO-D2, WOO-D3,
 * WOO-D37, RENDER-D32).
 *
 * The storefront row, `is_purchasable` and the AJAX add-to-cart endpoint used
 * to hold three different capacity tests, so one event could read "Join
 * waitlist" on the page, refuse the sale at the cart, and stay purchasable
 * from the managed product's permalink after it had sold out.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group woocommerce
 * @group bookability
 */
class Test_Storefront_Bookability extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
	}

	/**
	 * A paid event with one active tier, synced to its managed product.
	 *
	 * @param array $meta  Event meta overrides.
	 * @param array|null $tiers Ticket-tier rows (default: one $25 tier).
	 * @return array{0:int,1:array,2:int} [ event_id, tiers, product_id ]
	 */
	private function make_ticketed_event( array $meta = [], $tiers = null ) {
		$tiers = $tiers ?? [ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ];
		$event = $this->make_event(
			array_merge( [
				'title'                => 'Ticketed Course',
				'registration_enabled' => true,
				'registration_mode'    => 'wc',
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
			], $meta ),
			$tiers
		);
		$product_id = (int) $this->product_sync()->sync_event( $event );
		return [ $event, $this->ticket_types()->get( $event ), $product_id ];
	}

	/** The variation WC_Product for one tier. */
	private function variation( $event_id, array $tier ) {
		$vid = (int) $this->product_sync()->variation_for_tier( $event_id, $tier['id'] );
		$this->assertGreaterThan( 0, $vid );
		return wc_get_product( $vid );
	}

	/* ------------------------------------------------------------------
	 * WOO-D2 — is_purchasable is the capacity authority's answer.
	 * ------------------------------------------------------------------ */

	/**
	 * The audit's concrete failure: marked "Sold out" by hand, waitlist off,
	 * capacity 0 (unlimited) → remaining_capacity() returns PHP_INT_MAX, so
	 * the old hand-rolled test said "purchasable" and the managed product's
	 * permalink sold the ticket.
	 */
	public function test_hand_flagged_sold_out_event_is_not_purchasable() {
		list( $event, $tiers ) = $this->make_ticketed_event( [ 'capacity' => 0, 'sold_out' => true ] );

		$this->assertFalse(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) )
		);
	}

	/** An event whose registration_close date has passed is not purchasable. */
	public function test_event_past_its_registration_close_is_not_purchasable() {
		list( $event, $tiers ) = $this->make_ticketed_event( [
			'registration_close' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS * 2 ),
		] );

		$this->assertFalse(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) )
		);
	}

	/**
	 * The registration_enabled and end_ts gates moved out of
	 * filter_is_purchasable() and into the shared authority — they must still
	 * be enforced here.
	 */
	public function test_registration_disabled_event_is_not_purchasable() {
		list( $event, $tiers ) = $this->make_ticketed_event();
		update_post_meta( $event, '_anchor_event_registration_enabled', false );

		$this->assertFalse(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) )
		);
	}

	public function test_finished_event_is_not_purchasable() {
		list( $event, $tiers ) = $this->make_ticketed_event( [
			'start_date' => '2020-01-01',
			'end_date'   => '2020-01-01',
		] );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		update_post_meta( $event, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $event, '_anchor_event_end_ts', $ts['end'] );

		$this->assertFalse(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) )
		);
	}

	/** A live, in-window event is still purchasable — the gate is not a blanket no. */
	public function test_open_event_stays_purchasable() {
		list( $event, $tiers ) = $this->make_ticketed_event();

		$this->assertTrue(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) )
		);
	}

	/**
	 * WOO-D3, the purchase-path half: a tier whose own quota is exhausted is
	 * not purchasable even though the EVENT still has room.
	 */
	public function test_exhausted_tier_quota_is_not_purchasable_while_the_event_has_room() {
		list( $event, $tiers ) = $this->make_ticketed_event(
			[ 'capacity' => 100 ],
			[
				[ 'label' => 'VIP', 'price' => '75', 'active' => 1, 'quota' => 1 ],
				[ 'label' => 'General', 'price' => '25', 'active' => 1 ],
			]
		);
		$this->make_seat( $event, [ 'ticket_type_id' => $tiers[0]['id'] ] );

		$this->assertFalse(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) ),
			'The exhausted VIP tier must not be purchasable.'
		);
		$this->assertTrue(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[1] ) ),
			'Its sibling tier still has room.'
		);
	}

	/* ------------------------------------------------------------------
	 * WOO-D3 — the storefront row states.
	 * ------------------------------------------------------------------ */

	/** The row and the cart agree: an exhausted tier quota reads "Sold out". */
	public function test_storefront_row_says_sold_out_for_an_exhausted_tier_quota() {
		list( $event, $tiers ) = $this->make_ticketed_event(
			[ 'capacity' => 100, 'waitlist' => true ],
			[ [ 'label' => 'VIP', 'price' => '75', 'active' => 1, 'quota' => 1 ] ]
		);
		$this->make_seat( $event, [ 'ticket_type_id' => $tiers[0]['id'] ] );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

		$this->assertStringContainsString( 'Sold out', $html );
		$this->assertStringNotContainsString( 'Join waitlist', $html );
		$this->assertStringNotContainsString( 'data-add-to-cart', $html, 'Nothing is sellable → no button that would only fail.' );
	}

	/** The event total (not the tier) being full with waitlist on still offers the waitlist. */
	public function test_storefront_row_offers_the_waitlist_when_the_event_total_is_full() {
		list( $event ) = $this->make_ticketed_event( [ 'capacity' => 1, 'waitlist' => true ] );
		$this->make_seat( $event );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

		$this->assertStringContainsString( 'Join waitlist', $html );
		$this->assertStringContainsString( 'data-add-to-cart', $html );
	}

	/** An event past its registration window renders a closed row, not a quantity box. */
	public function test_storefront_row_is_closed_outside_the_registration_window() {
		list( $event ) = $this->make_ticketed_event( [
			'registration_close' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS * 2 ),
		] );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

		$this->assertStringContainsString( 'Registration closed', $html );
		$this->assertStringNotContainsString( 'anchor-event-ticket-qty', $html );
		$this->assertStringNotContainsString( 'data-add-to-cart', $html );
	}

	/* ------------------------------------------------------------------
	 * RENDER-D32 / MODEL-D42 — the picker and the series archive.
	 * ------------------------------------------------------------------ */

	/** The hand-set sold_out flag reaches the choose-a-date hint and CTA. */
	public function test_choose_date_row_reports_a_hand_flagged_sold_out_date() {
		$parent = $this->make_event( [
			'type'                 => 'offering',
			'registration_enabled' => true,
			'timezone'             => 'UTC',
		] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-10-23', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0 ],
		] );
		$live = $this->module()->occurrences->reconcile( $parent );
		update_post_meta( $live[0], '_anchor_event_sold_out', true );

		$html = $this->module()->render_choose_date_list( $parent );

		$this->assertStringContainsString( 'Sold out', $html );
		$this->assertStringContainsString( 'Details', $html );
		$this->assertStringNotContainsString( '>Register<', $html );
	}
}
