<?php
/**
 * JSON-LD `offers.availability` comes from the single purchasability
 * authority (RENDER-D3, RENDER-D4, RENDER-D5, RENDER-D7).
 *
 * Every Offer builder — wc, external, free — and the group-parent node used
 * to answer "can this be bought" for themselves: build_wc_offers() read
 * remaining_capacity() (blind to the sold_out flag, the registration window
 * and per-tier quotas), build_free_offer() hardcoded InStock, and
 * build_external_offer() emitted no availability at all. They now all route
 * through Module::bookability() via Event_Schema::availability_for().
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Event_Schema;

/**
 * @group schema
 */
class Test_Schema_Availability extends Anchor_Events_TestCase {

	/** @return Event_Schema */
	protected function schema() {
		return $this->module()->event_schema;
	}

	/* ------------------------------------------------------------------
	 * The mapping itself.
	 * ------------------------------------------------------------------ */

	public function test_availability_for_maps_the_three_purchasable_states() {
		$this->assertSame( 'https://schema.org/InStock', Event_Schema::availability_for( 'open' ) );
		$this->assertSame( 'https://schema.org/LimitedAvailability', Event_Schema::availability_for( 'waitlist' ) );
		$this->assertSame( 'https://schema.org/SoldOut', Event_Schema::availability_for( 'full' ) );
	}

	public function test_availability_for_returns_null_for_unbookable_states() {
		$this->assertNull( Event_Schema::availability_for( 'closed' ) );
		$this->assertNull( Event_Schema::availability_for( 'disabled' ) );
		$this->assertNull( Event_Schema::availability_for( 'parent' ) );
	}

	/* ------------------------------------------------------------------
	 * RENDER-D3 — the wc branch.
	 * ------------------------------------------------------------------ */

	/** The hand-set sold_out flag must reach the markup, not just the picker. */
	public function test_sold_out_child_emits_soldout_not_instock() {
		$event = $this->make_event(
			[
				'registration_enabled' => true,
				'registration_mode'    => 'wc',
				'sold_out'             => true,
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
			],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);

		$node = $this->schema()->for_event( $event );

		$this->assertSame( 'https://schema.org/SoldOut', $node['offers'][0]['availability'] );
	}

	/** A tier whose own quota is exhausted is SoldOut while its sibling stays InStock. */
	public function test_exhausted_tier_quota_is_soldout_per_offer() {
		$event = $this->make_event(
			[
				'registration_enabled' => true,
				'registration_mode'    => 'wc',
				'capacity'             => 100,
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
			],
			[
				[ 'label' => 'VIP', 'price' => '75', 'active' => 1, 'quota' => 1 ],
				[ 'label' => 'General', 'price' => '25', 'active' => 1 ],
			]
		);
		$tiers = $this->ticket_types()->get( $event );
		$vip   = $tiers[0];
		$this->make_seat( $event, [ 'ticket_type_id' => $vip['id'] ] );

		$node = $this->schema()->for_event( $event );

		$by_price = [];
		foreach ( $node['offers'] as $offer ) {
			$by_price[ (string) $offer['price'] ] = $offer['availability'];
		}
		$this->assertSame( 'https://schema.org/SoldOut', $by_price['75'] );
		$this->assertSame( 'https://schema.org/InStock', $by_price['25'] );
	}

	/** Waitlist on + full is LimitedAvailability, not SoldOut. */
	public function test_waitlist_event_is_limited_availability() {
		$event = $this->make_event(
			[
				'registration_enabled' => true,
				'registration_mode'    => 'wc',
				'capacity'             => 1,
				'waitlist'             => true,
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
			],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);
		$this->make_seat( $event );

		$node = $this->schema()->for_event( $event );

		$this->assertSame( 'https://schema.org/LimitedAvailability', $node['offers'][0]['availability'] );
	}

	/* ------------------------------------------------------------------
	 * RENDER-D4 — the free branch.
	 * ------------------------------------------------------------------ */

	/** A free event that already happened must not advertise a live Offer. */
	public function test_free_offer_is_omitted_for_a_finished_event() {
		$event = $this->make_event( [
			'registration_enabled' => true,
			'registration_mode'    => 'free',
			'start_date'           => '2020-01-01',
			'end_date'             => '2020-01-01',
			'timezone'             => 'UTC',
		] );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		update_post_meta( $event, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $event, '_anchor_event_end_ts', $ts['end'] );

		$node = $this->schema()->for_event( $event );

		$this->assertArrayNotHasKey( 'offers', $node );
	}

	/** A free event at capacity is SoldOut, not the old hardcoded InStock. */
	public function test_free_offer_is_soldout_at_capacity() {
		$event = $this->make_event( [
			'registration_enabled' => true,
			'registration_mode'    => 'free',
			'capacity'             => 1,
			'start_date'           => '2030-01-01',
			'timezone'             => 'UTC',
		] );
		$this->make_seat( $event );

		$node = $this->schema()->for_event( $event );

		$this->assertSame( 'https://schema.org/SoldOut', $node['offers'][0]['availability'] );
	}

	/* ------------------------------------------------------------------
	 * RENDER-D5 — the external branch.
	 * ------------------------------------------------------------------ */

	public function test_external_offer_carries_availability() {
		$event = $this->make_event( [
			'registration_enabled'   => true,
			'registration_mode'      => 'external',
			'external_url'           => 'https://example.com/register',
			'external_display_price' => '$495',
			'start_date'             => '2030-01-01',
			'timezone'               => 'UTC',
		] );

		$node = $this->schema()->for_event( $event );

		$this->assertSame( 'https://schema.org/InStock', $node['offers'][0]['availability'] );
	}

	/* ------------------------------------------------------------------
	 * RENDER-D7 — the group parent.
	 * ------------------------------------------------------------------ */

	/** A container has no seats of its own, so it advertises no Offer. */
	public function test_group_parent_node_has_no_offers() {
		$parent = $this->make_event( [
			'type'                 => 'offering',
			'registration_enabled' => true,
			'registration_mode'    => 'free',
			'timezone'             => 'UTC',
		] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-10-23', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0 ],
			[ 'date' => '2030-11-13', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'November', 'capacity' => 0 ],
		] );
		$this->module()->occurrences->reconcile( $parent );

		$node = $this->schema()->for_event( $parent );

		$this->assertArrayNotHasKey( 'offers', $node );
		// The children still carry their own — the parent is the only omission.
		$this->assertCount( 2, $node['subEvent'] );
		$this->assertArrayHasKey( 'offers', $node['subEvent'][0] );
	}

	/**
	 * Registration switched off is NOT the same as "nothing to sell".
	 *
	 * `disabled` is the default state of an event and the permanent state of
	 * every display-only site, so omitting the Offer for it stripped `offers`
	 * from the majority of events on such a site — the price stopped reaching
	 * search results because nobody had switched on a registration feature
	 * they do not use. The Offer is emitted with price / priceCurrency / url;
	 * only the `availability` claim is withheld, because there is no honest
	 * schema.org value for "not for sale".
	 */
	public function test_registration_disabled_emits_an_offer_without_availability() {
		$event = $this->make_event( [
			'registration_enabled' => false,
			'registration_mode'    => 'free',
			'start_date'           => '2030-01-01',
			'timezone'             => 'UTC',
		] );

		$node = $this->schema()->for_event( $event );

		$this->assertArrayHasKey( 'offers', $node, 'Registration off must not strip the price out of the markup.' );
		$this->assertCount( 1, $node['offers'] );
		$this->assertArrayNotHasKey( 'availability', $node['offers'][0], 'There is no honest availability value for an event that is not for sale.' );
		$this->assertSame( 0, $node['offers'][0]['price'] );
		$this->assertSame( get_permalink( $event ), $node['offers'][0]['url'] );
	}

	/** The same ruling on the wc branch: tier prices survive, availability does not. */
	public function test_registration_disabled_wc_offers_keep_their_prices() {
		$event = $this->make_event(
			[
				'registration_enabled' => false,
				'registration_mode'    => 'wc',
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
			],
			[
				[ 'label' => 'VIP', 'price' => '75', 'active' => 1 ],
				[ 'label' => 'General', 'price' => '25', 'active' => 1 ],
			]
		);

		$node = $this->schema()->for_event( $event );

		$this->assertArrayHasKey( 'offers', $node );
		$this->assertCount( 2, $node['offers'] );
		foreach ( $node['offers'] as $offer ) {
			$this->assertArrayNotHasKey( 'availability', $offer );
		}
		$prices = array_column( $node['offers'], 'price' );
		sort( $prices );
		$this->assertSame( [ 25, 75 ], $prices );
	}

	/** ...and on the external branch. */
	public function test_registration_disabled_external_offer_keeps_its_url_and_price() {
		$event = $this->make_event( [
			'registration_enabled'   => false,
			'registration_mode'      => 'external',
			'external_url'           => 'https://example.com/register',
			'external_display_price' => '$495',
			'start_date'             => '2030-01-01',
			'timezone'               => 'UTC',
		] );

		$node = $this->schema()->for_event( $event );

		$this->assertArrayHasKey( 'offers', $node );
		$this->assertArrayNotHasKey( 'availability', $node['offers'][0] );
		$this->assertSame( 'https://example.com/register', $node['offers'][0]['url'] );
		$this->assertSame( 495, $node['offers'][0]['price'] );
	}

	/**
	 * The states that DO still omit the Offer entirely stay omitted — this is
	 * the line between "no availability claim" and "nothing to advertise".
	 * A soft-closed occurrence is `closed`, not `disabled`.
	 */
	public function test_soft_closed_occurrence_still_emits_no_offers() {
		$parent = $this->make_event( [
			'type'                 => 'offering',
			'registration_enabled' => true,
			'registration_mode'    => 'free',
			'timezone'             => 'UTC',
		] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-10-23', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0 ],
			[ 'date' => '2030-11-13', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'November', 'capacity' => 0 ],
		] );
		$this->module()->occurrences->reconcile( $parent );

		$children = $this->module()->occurrences->children( $parent, true );
		$closed   = (int) $children[1];
		$this->make_seat( $closed );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-10-23', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0 ],
		] );
		$this->module()->occurrences->reconcile( $parent );
		$this->assertTrue( (bool) get_post_meta( $closed, '_anchor_event_occurrence_closed', true ) );

		$node = $this->schema()->for_event( $closed );

		$this->assertArrayNotHasKey( 'offers', $node, 'A cancelled date has nothing to advertise at all.' );
	}
}
