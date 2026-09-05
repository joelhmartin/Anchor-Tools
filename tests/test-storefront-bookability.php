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
	 * The tier's own SALE WINDOW is part of "can this be bought".
	 *
	 * render_ticket_row() and ajax_add_to_cart() both refuse a tier outside
	 * its sale_start/sale_end, but filter_is_purchasable() only asked
	 * bookability() — which deliberately does not answer the window, because
	 * the schema builders want an Offer with `validFrom` for a tier whose
	 * sales open later, not an omitted Offer. The result was the third
	 * disagreement this file exists to prevent: a tier whose sale opens next
	 * month was still buyable straight from the variable product's own
	 * add-to-cart form, with the event page saying "Sales open <date>".
	 * ------------------------------------------------------------------ */

	public function test_tier_before_its_sale_window_is_not_purchasable() {
		list( $event, $tiers ) = $this->make_ticketed_event( [], [
			[
				'label'      => 'Early bird',
				'price'      => '25',
				'active'     => 1,
				'sale_start' => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			],
		] );

		// The event itself is bookable — only the tier's window is shut, which
		// is exactly the case the bookability-only check could not see.
		$this->assertTrue( $this->module()->is_bookable( $this->module()->bookability( $event, $tiers[0] ) ) );

		$this->assertFalse(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) ),
			'A tier whose sale opens next month must not be buyable from the product permalink.'
		);
	}

	public function test_tier_after_its_sale_window_is_not_purchasable() {
		list( $event, $tiers ) = $this->make_ticketed_event( [], [
			[
				'label'    => 'Early bird',
				'price'    => '25',
				'active'   => 1,
				'sale_end' => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ),
			],
		] );

		$this->assertFalse(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) )
		);
	}

	public function test_same_tier_is_purchasable_once_its_sale_window_is_open() {
		list( $event, $tiers ) = $this->make_ticketed_event( [], [
			[
				'label'      => 'Early bird',
				'price'      => '25',
				'active'     => 1,
				'sale_start' => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ),
				'sale_end'   => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			],
		] );

		$this->assertTrue(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) ),
			'An open window must not be turned into a blanket refusal.'
		);
	}

	/**
	 * The window is a TIER fact, so it must not leak to the event level: a
	 * simple/legacy product resolves no tier and keeps the event-level answer.
	 */
	public function test_the_sale_window_does_not_bind_a_product_with_no_resolved_tier() {
		list( $event, $tiers, $product_id ) = $this->make_ticketed_event( [], [
			[
				'label'      => 'Early bird',
				'price'      => '25',
				'active'     => 1,
				'sale_start' => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			],
		] );

		$this->assertTrue(
			$this->woocommerce()->filter_is_purchasable( true, wc_get_product( $product_id ) ),
			'The parent variable product resolves no tier, so only the event-level gate applies to it.'
		);
	}

	/**
	 * WOO-D4: a tier whose sale window has ALREADY ENDED must read "Sales
	 * closed", never "Sales open <date>" — the old message came from checking
	 * only "is sale_start non-empty", which can't tell a closed window from
	 * one that hasn't opened.
	 */
	public function test_storefront_row_says_closed_not_open_for_an_ended_sale_window() {
		list( $event, $tiers ) = $this->make_ticketed_event( [], [
			[
				'label'      => 'Early bird',
				'price'      => '25',
				'active'     => 1,
				'sale_start' => '2026-01-01',
				'sale_end'   => '2026-02-01',
			],
		] );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

		$this->assertStringContainsString( 'Sales closed', $html );
		$this->assertStringNotContainsString( 'Sales open', $html );
	}

	/**
	 * WOO-D38: a tier whose managed variation was deleted must not render a
	 * quantity box that the AJAX endpoint would then refuse — it renders
	 * "Unavailable" instead.
	 */
	public function test_storefront_row_is_unavailable_when_its_variation_is_gone() {
		list( $event, $tiers ) = $this->make_ticketed_event();
		$vid = (int) $this->product_sync()->variation_for_tier( $event, $tiers[0]['id'] );
		$this->assertGreaterThan( 0, $vid );
		wc_get_product( $vid )->delete( true );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

		$this->assertStringContainsString( 'Unavailable', $html );
		$this->assertStringNotContainsString( 'anchor-event-ticket-qty', $html );
		$this->assertStringNotContainsString(
			'data-add-to-cart',
			$html,
			'The only tier has no variation to sell — no button that would only fail.'
		);
	}

	/**
	 * WOO-D39 (already closed, pinned here): when every tier is outside its
	 * sale window (or sold out with no waitlist) the "Register / Add to cart"
	 * button is omitted, not rendered to fail with "Please choose at least
	 * one ticket."
	 */
	public function test_no_add_to_cart_button_when_no_tier_is_sellable() {
		list( $event ) = $this->make_ticketed_event( [], [
			[
				'label'      => 'Early bird',
				'price'      => '25',
				'active'     => 1,
				'sale_start' => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			],
		] );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

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

	/**
	 * NEW-D2: the production shape — sold out AND switched off. The hint used
	 * to need a local "ask the seat layer anyway" workaround here because
	 * bookability() answered 'disabled' first; it now answers 'full', and the
	 * row must still read "Sold out" without that workaround.
	 */
	public function test_choose_date_row_reports_a_sold_out_date_with_registration_off() {
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
		update_post_meta( $live[0], '_anchor_event_registration_enabled', false );

		$html = $this->module()->render_choose_date_list( $parent );

		$this->assertStringContainsString( 'Sold out', $html );
		$this->assertStringNotContainsString( 'Registration closed', $html );
		$this->assertStringNotContainsString( '>Register<', $html );
	}

	/** NEW-D2 / THEME-D25: a hand-cancelled date is closed on every reader. */
	public function test_choose_date_row_reports_a_manually_cancelled_date() {
		$parent = $this->make_event( [
			'type'                 => 'offering',
			'registration_enabled' => true,
			'timezone'             => 'UTC',
		] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-10-23', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0 ],
		] );
		$live = $this->module()->occurrences->reconcile( $parent );
		update_post_meta( $live[0], '_anchor_event_status_mode', 'manual' );
		update_post_meta( $live[0], '_anchor_event_status', 'cancelled' );

		$html = $this->module()->render_choose_date_list( $parent );

		$this->assertStringContainsString( 'Registration closed', $html );
		$this->assertStringContainsString( 'Details', $html );
		$this->assertStringNotContainsString( '>Register<', $html );
	}

	/** A hand-cancelled course cannot be bought from the product permalink. */
	public function test_manually_cancelled_event_is_not_purchasable() {
		list( $event, $tiers ) = $this->make_ticketed_event();
		update_post_meta( $event, '_anchor_event_status_mode', 'manual' );
		update_post_meta( $event, '_anchor_event_status', 'cancelled' );

		$this->assertFalse(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) )
		);
	}

	/** ...and its storefront row says so instead of offering a quantity box. */
	public function test_storefront_row_is_closed_for_a_manually_cancelled_event() {
		list( $event ) = $this->make_ticketed_event();
		update_post_meta( $event, '_anchor_event_status_mode', 'manual' );
		update_post_meta( $event, '_anchor_event_status', 'cancelled' );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

		$this->assertStringContainsString( 'Registration closed', $html );
		$this->assertStringNotContainsString( 'data-add-to-cart', $html );
	}

	/**
	 * finding-16 (carry-over, Task 34 review ruling) — a postponed course
	 * sells nothing: the original date is off and no new one is known yet.
	 * Before this, bookability() never consulted 'postponed' and a postponed
	 * event with open seats still resolved 'open', so it stayed purchasable
	 * while its own JSON-LD said EventPostponed.
	 */
	public function test_postponed_event_is_not_purchasable() {
		list( $event, $tiers ) = $this->make_ticketed_event();
		update_post_meta( $event, '_anchor_event_status_mode', 'manual' );
		update_post_meta( $event, '_anchor_event_status', 'postponed' );

		$this->assertFalse(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) )
		);
	}

	/** ...and its storefront row says so instead of offering a quantity box. */
	public function test_storefront_row_is_closed_for_a_postponed_event() {
		list( $event ) = $this->make_ticketed_event();
		update_post_meta( $event, '_anchor_event_status_mode', 'manual' );
		update_post_meta( $event, '_anchor_event_status', 'postponed' );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

		$this->assertStringContainsString( 'Registration closed', $html );
		$this->assertStringNotContainsString( 'data-add-to-cart', $html );
	}

	/**
	 * The ruling's other half: 'moved_online' stays bookable — same date,
	 * still happens, just virtually. Only 'postponed' is excluded from
	 * bookability()'s status short-circuit.
	 */
	public function test_moved_online_event_stays_purchasable() {
		list( $event, $tiers ) = $this->make_ticketed_event();
		update_post_meta( $event, '_anchor_event_status_mode', 'manual' );
		update_post_meta( $event, '_anchor_event_status', 'moved_online' );

		$this->assertTrue(
			$this->woocommerce()->filter_is_purchasable( true, $this->variation( $event, $tiers[0] ) )
		);
	}

	/* ------------------------------------------------------------------
	 * WOO-D2 — the legacy manually-linked-product escape hatch.
	 * ------------------------------------------------------------------ */

	/**
	 * Create a plain WooCommerce product hand-linked to $event_id, the way the
	 * pre-tier "escape hatch" workflow does (parent toggle + event id meta).
	 *
	 * @param int   $event_id
	 * @param float $price
	 * @return int Product id.
	 */
	private function link_legacy_product( $event_id, $price = 500 ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Legacy Course Ticket' );
		$product->set_regular_price( (string) $price );
		$product->set_status( 'publish' );
		$product_id = $product->save();

		update_post_meta( $product_id, \Anchor\Events\WooCommerce::META_ENABLED, '1' );
		update_post_meta( $product_id, \Anchor\Events\WooCommerce::META_EVENT_ID, (int) $event_id );

		return (int) $product_id;
	}

	/**
	 * The escape hatch used to read remaining_capacity() only, so a
	 * hand-flagged sold-out event with capacity 0 (unlimited) rendered
	 * "Register — $500" pointing at a product page the cart now refuses.
	 */
	public function test_legacy_product_link_is_sold_out_for_a_hand_flagged_event() {
		$event = $this->make_event( [
			'registration_enabled' => true,
			'capacity'             => 0,
			'sold_out'             => true,
			'start_date'           => '2030-01-01',
			'timezone'             => 'UTC',
		] );
		$this->link_legacy_product( $event );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

		$this->assertStringContainsString( 'Sold out', $html );
		$this->assertStringNotContainsString( 'Register — ', $html );
		$this->assertStringNotContainsString( '<a class="anchor-event-button', $html );
	}

	/** An event past its registration window gets a disabled button, not a link. */
	public function test_legacy_product_link_is_closed_outside_the_registration_window() {
		$event = $this->make_event( [
			'registration_enabled' => true,
			'registration_close'   => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS * 2 ),
			'start_date'           => '2030-01-01',
			'timezone'             => 'UTC',
		] );
		$this->link_legacy_product( $event );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

		$this->assertStringContainsString( 'Registration closed', $html );
		$this->assertStringNotContainsString( '<a class="anchor-event-button', $html );
	}

	/** A bookable event still gets the real "Register — $price" link. */
	public function test_legacy_product_link_still_renders_for_a_bookable_event() {
		$event = $this->make_event( [
			'registration_enabled' => true,
			'start_date'           => '2030-01-01',
			'timezone'             => 'UTC',
		] );
		$product_id = $this->link_legacy_product( $event );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

		$this->assertStringContainsString( 'Register', $html );
		$this->assertStringContainsString( esc_url( get_permalink( $product_id ) ), $html );
	}

	/* ------------------------------------------------------------------
	 * The two link metas can disagree.
	 * ------------------------------------------------------------------ */

	/**
	 * `filter_is_purchasable()` resolves the event from the admin-editable
	 * `_anchor_evt_link_event_id` on the VARIATION; `tier_for_variation()`
	 * reads the managed event off the WC PARENT. Re-point the dropdown and
	 * they differ — looking the tier up under the wrong event yields a quota
	 * counting seats nobody can hold, i.e. a quota that silently stops
	 * binding. On a mismatch we fall back to the EVENT-level answer, which
	 * still enforces capacity, sold_out, the window and the past.
	 */
	public function test_a_repointed_variation_falls_back_to_the_event_level_gate() {
		list( $event_b, $tiers_b ) = $this->make_ticketed_event(
			[ 'title' => 'Event B', 'capacity' => 100 ],
			[ [ 'label' => 'VIP', 'price' => '75', 'active' => 1, 'quota' => 1 ] ]
		);
		$variation = $this->variation( $event_b, $tiers_b[0] );

		// Event A is sold out at the EVENT level; it has no tier of its own.
		$event_a = $this->make_event( [
			'registration_enabled' => true,
			'capacity'             => 1,
			'start_date'           => '2030-01-01',
			'timezone'             => 'UTC',
		] );
		$this->make_seat( $event_a );

		// Re-point the variation's link meta at event A (the admin dropdown).
		update_post_meta( $variation->get_id(), \Anchor\Events\WooCommerce::META_EVENT_ID, $event_a );

		$this->assertSame(
			$event_a,
			$this->woocommerce()->event_for_variation( $variation->get_id() ),
			'Sanity: the line now registers for event A.'
		);

		// Event A's own capacity still binds — the foreign tier cannot mask it.
		$this->assertFalse(
			$this->woocommerce()->filter_is_purchasable( true, $variation )
		);
	}

	/**
	 * The observable half of the same bug: a FOREIGN tier's quota must not be
	 * consulted against this event's seats. Tier ids are per-event, so the
	 * same id can exist on two events with different quotas — before the fix
	 * the wrong event's quota decided, which refuses a sale as readily as it
	 * permits one.
	 */
	public function test_a_repointed_variation_does_not_consult_the_other_events_tier_quota() {
		list( $event_b, $tiers_b ) = $this->make_ticketed_event(
			[ 'title' => 'Event B', 'capacity' => 100 ],
			[ [ 'label' => 'VIP', 'price' => '75', 'active' => 1, 'quota' => 1 ] ]
		);
		$variation = $this->variation( $event_b, $tiers_b[0] );

		// Event A has unlimited capacity and no tiers of its own, but carries a
		// seat tagged with the same tier id (a legacy seat, or a collided id).
		$event_a = $this->make_event( [
			'registration_enabled' => true,
			'capacity'             => 0,
			'start_date'           => '2030-01-01',
			'timezone'             => 'UTC',
		] );
		$this->make_seat( $event_a, [ 'ticket_type_id' => $tiers_b[0]['id'] ] );

		update_post_meta( $variation->get_id(), \Anchor\Events\WooCommerce::META_EVENT_ID, $event_a );

		$this->assertTrue(
			$this->woocommerce()->filter_is_purchasable( true, $variation ),
			"Event B's quota of 1 must not be applied to event A's seats."
		);
	}

	/**
	 * CodeRabbit finding-6 (PR #20, 2nd round): render_ticket_row()'s
	 * blank-label fallback was a second, hand-typed literal ("Ticket") that
	 * drifted from \Anchor\Events\Ticket_Types::default_label()
	 * ("Registration") — the one place the app defines what a nameless tier
	 * is called. A blank-labeled tier's storefront row must use the SAME
	 * word every other blank-label surface (Product_Sync's synced variation
	 * name, per test-product-sync.php) already uses.
	 */
	public function test_storefront_row_uses_the_shared_default_label_for_a_blank_tier_label() {
		// Ticket_Types::save() (via make_ticketed_event()/make_event()) would
		// itself substitute a real label for a blank one at write time — the
		// raw row is written directly here (same technique as
		// test-product-sync.php's sibling test) so a genuinely blank label
		// survives to reach render_ticket_row() itself.
		$event = $this->make_event( [ 'title' => 'Blank Label Event' ] );
		update_post_meta(
			$event,
			\Anchor\Events\Ticket_Types::META_KEY,
			[ [ 'id' => 'blank1', 'label' => '', 'price' => '25', 'quota' => 0, 'sale_start' => '', 'sale_end' => '', 'active' => true, 'wc_variation_id' => 0 ] ]
		);
		$this->product_sync()->sync_event( $event );
		$tiers = $this->ticket_types()->get( $event );
		$this->assertSame( '', $tiers[0]['label'], 'Sanity: the tier really is stored with a blank label.' );

		$html = $this->woocommerce()->filter_registration_form( '', $event, $this->module()->get_meta( $event ) );

		$this->assertStringContainsString( \Anchor\Events\Ticket_Types::default_label(), $html );
		$this->assertStringNotContainsString( 'Ticket<', $html, 'Must not fall back to a second, drifted literal.' );
	}
}
