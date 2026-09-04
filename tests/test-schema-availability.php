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
use Anchor\Events\Module;

/**
 * @group schema
 */
class Test_Schema_Availability extends Anchor_Events_TestCase {

	public function tear_down() {
		unset( $_POST );
		remove_all_filters( 'anchor_events_schema_node' );
		parent::tear_down();
	}

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

	/* ------------------------------------------------------------------
	 * NEW-D2 — "not for sale" is not the same as "sold out" or "over".
	 *
	 * The disabled rulings above only hold for an event that is otherwise
	 * bookable. A sold-out or finished event whose switch is ALSO off (the
	 * normal shape: production child 7528) used to read 'disabled' and emit a
	 * priced Offer with no availability, while its own page said "Sold out".
	 * ------------------------------------------------------------------ */

	/** Sold out with the switch off is still SoldOut, not a bare price. */
	public function test_sold_out_with_registration_off_is_soldout() {
		$event = $this->make_event(
			[
				'registration_enabled' => false,
				'registration_mode'    => 'wc',
				'sold_out'             => true,
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
			],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);

		$node = $this->schema()->for_event( $event );

		$this->assertSame( 'https://schema.org/SoldOut', $node['offers'][0]['availability'] );
		$this->assertSame( 25, $node['offers'][0]['price'] );
	}

	/** A finished event with the switch off advertises nothing at all. */
	public function test_finished_event_with_registration_off_emits_no_offer() {
		$event = $this->make_event( [
			'registration_enabled' => false,
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

	/** A hand-cancelled event is cancelled AND unbookable in the same node. */
	public function test_manual_cancellation_emits_no_offer() {
		$event = $this->make_event(
			[
				'registration_enabled' => true,
				'registration_mode'    => 'wc',
				'status_mode'          => 'manual',
				'status'               => 'cancelled',
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
			],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);

		$node = $this->schema()->for_event( $event );

		$this->assertSame( 'https://schema.org/EventCancelled', $node['eventStatus'] );
		$this->assertArrayNotHasKey( 'offers', $node, 'A cancelled course has nothing to advertise.' );
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

	/* ------------------------------------------------------------------
	 * Group parents (MODEL-D4 / NEW-D1): the container's SPAN is the whole
	 * live set, past dates included — a course that ran in January and runs
	 * again in December really does span both, and its own status ('ongoing')
	 * is computed from exactly that. What changed is the parent's
	 * bookability, which is no longer an unconditional 'parent'; the node
	 * must still publish no Offer of its own whatever that answers.
	 * ------------------------------------------------------------------ */

	/**
	 * A reconciled offering parent with the given rows.
	 *
	 * @param array $rows Offering-dates rows.
	 * @return int Parent post id.
	 */
	protected function make_offering_parent( array $rows ) {
		$parent = $this->make_event( [
			'type'                 => 'offering',
			'registration_enabled' => true,
			'registration_mode'    => 'free',
			'timezone'             => 'UTC',
		] );
		update_post_meta( $parent, '_anchor_event_offering_dates', $rows );
		$this->module()->occurrences->reconcile( $parent );
		return (int) $parent;
	}

	public function test_group_parent_span_covers_every_live_child_including_a_past_one() {
		$parent = $this->make_offering_parent( [
			[ 'date' => '2020-01-06', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'Gone', 'capacity' => 0 ],
			[ 'date' => '2030-11-13', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'Ahead', 'capacity' => 0 ],
		] );

		$node = $this->schema()->for_event( $parent );

		$this->assertCount( 2, $node['subEvent'], 'Every LIVE child is a subEvent — the span is not the picker.' );
		$this->assertStringStartsWith( '2020-01-06T08:00', $node['startDate'] );
		$this->assertStringStartsWith( '2030-11-13T18:00', $node['endDate'] );
	}

	public function test_group_parent_with_only_sold_out_dates_still_publishes_no_offer() {
		$parent = $this->make_offering_parent( [
			[ 'date' => '2030-12-01', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'Full', 'capacity' => 1 ],
		] );
		$live = $this->module()->occurrences->children( $parent );
		$this->make_seat( (int) $live[0] );

		$this->assertSame( 'full', $this->module()->bookability( $parent ), 'Precondition: the container reads sold out.' );

		$node = $this->schema()->for_event( $parent );

		$this->assertArrayNotHasKey( 'offers', $node, 'A container never carries an Offer of its own, whatever its state.' );
		$this->assertSame( 'https://schema.org/SoldOut', $node['subEvent'][0]['offers'][0]['availability'] );
	}

	/* ------------------------------------------------------------------
	 * RENDER-D6 — the group-parent end-of-span must only ever come from a
	 * child that actually produced a subEvent node.
	 * ------------------------------------------------------------------ */

	/**
	 * A live-but-undated child (still returned by Occurrences::children(),
	 * but for_event() on it returns [] for lack of a usable start_ts) must
	 * not be able to push the parent's endDate out to a date that appears
	 * nowhere in subEvent[]. Mirrors the production shape on page 7258.
	 */
	public function test_group_parent_end_date_ignores_a_child_with_no_usable_node() {
		$parent = $this->make_offering_parent( [
			[ 'date' => '2026-09-12', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'A', 'capacity' => 0 ],
			[ 'date' => '2026-11-07', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'B', 'capacity' => 0 ],
		] );
		$children = $this->module()->occurrences->children( $parent );
		$this->assertCount( 2, $children );
		$b = (int) $children[1];

		// Blank B's OWN start date only (end_date/end_time are untouched, just
		// like a single-day occurrence row really would leave them) so
		// for_event( $b ) returns [] while $b remains published/non-closed and
		// still shows up in occurrences->children().
		update_post_meta( $b, '_anchor_event_start_date', '' );
		update_post_meta( $b, '_anchor_event_start_ts', 0 );
		$this->assertSame( [], $this->schema()->for_event( $b ), 'Precondition: the undated child produces no node of its own.' );

		$node = $this->schema()->for_event( $parent );

		$this->assertCount( 1, $node['subEvent'], 'Only the child that actually produced a node is advertised.' );
		$this->assertStringStartsWith( '2026-09-12T18:00', $node['endDate'], 'endDate must never come from a child with no corresponding subEvent.' );
	}

	/* ------------------------------------------------------------------
	 * RENDER-D9 — postponed / moved_online statuses + previousStartDate.
	 * ------------------------------------------------------------------ */

	public function test_status_options_include_postponed_and_moved_online() {
		$method = new ReflectionMethod( $this->module(), 'get_status_options' );
		$method->setAccessible( true );
		$options = $method->invoke( $this->module() );

		$this->assertArrayHasKey( 'postponed', $options );
		$this->assertArrayHasKey( 'moved_online', $options );
	}

	public function test_postponed_status_maps_to_event_postponed() {
		$event = $this->make_event( [
			'status_mode' => 'manual',
			'status'      => 'postponed',
			'start_date'  => '2030-01-01',
			'timezone'    => 'UTC',
		] );

		$node = $this->schema()->for_event( $event );

		$this->assertSame( 'https://schema.org/EventPostponed', $node['eventStatus'] );
	}

	public function test_moved_online_status_maps_to_event_moved_online() {
		$event = $this->make_event( [
			'status_mode' => 'manual',
			'status'      => 'moved_online',
			'start_date'  => '2030-01-01',
			'timezone'    => 'UTC',
		] );

		$node = $this->schema()->for_event( $event );

		$this->assertSame( 'https://schema.org/EventMovedOnline', $node['eventStatus'] );
	}

	/**
	 * finding-16 (carry-over, Task 34 review ruling) — a postponed event with
	 * OPEN SEATS must not publish InStock at the old date: bookability() now
	 * resolves 'closed' for a postponed event (same short-circuit cancelled
	 * already had), so the Offer is omitted entirely in the same node that
	 * says EventPostponed — never the InStock-while-postponed mismatch the
	 * audit found.
	 */
	public function test_postponed_event_with_open_seats_emits_no_offer_not_instock() {
		$event = $this->make_event(
			[
				'registration_enabled' => true,
				'registration_mode'    => 'wc',
				'status_mode'          => 'manual',
				'status'               => 'postponed',
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
			],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);

		$node = $this->schema()->for_event( $event );

		$this->assertSame( 'https://schema.org/EventPostponed', $node['eventStatus'] );
		$this->assertArrayNotHasKey( 'offers', $node, 'A postponed course has nothing to advertise at the old date.' );
	}

	/** The ruling's other half: 'moved_online' stays bookable, so its Offer is unaffected. */
	public function test_moved_online_event_with_open_seats_still_emits_instock() {
		$event = $this->make_event(
			[
				'registration_enabled' => true,
				'registration_mode'    => 'wc',
				'status_mode'          => 'manual',
				'status'               => 'moved_online',
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
			],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);

		$node = $this->schema()->for_event( $event );

		$this->assertSame( 'https://schema.org/EventMovedOnline', $node['eventStatus'] );
		$this->assertArrayHasKey( 'offers', $node );
		$this->assertSame( 'https://schema.org/InStock', $node['offers'][0]['availability'] );
	}

	/** Precondition guard: an ordinary scheduled event never gets a stray previousStartDate. */
	public function test_scheduled_event_has_no_previous_start_date() {
		$event = $this->make_event( [
			'start_date' => '2030-01-01',
			'timezone'   => 'UTC',
		] );

		$node = $this->schema()->for_event( $event );

		$this->assertArrayNotHasKey( 'previousStartDate', $node );
	}

	/** A stored previous-start meta reaches the node when the event is postponed. */
	public function test_previous_start_date_reaches_the_node_when_postponed() {
		$event = $this->make_event( [
			'status_mode' => 'manual',
			'status'      => 'postponed',
			'start_date'  => '2030-01-01',
			'timezone'    => 'UTC',
		] );
		update_post_meta( $event, '_anchor_event_previous_start', '2029-11-01' );

		$node = $this->schema()->for_event( $event );

		$this->assertSame( '2029-11-01', $node['previousStartDate'] );
	}

	/** ...and when the event is moved online, the same stored value applies. */
	public function test_previous_start_date_reaches_the_node_when_moved_online() {
		$event = $this->make_event( [
			'status_mode' => 'manual',
			'status'      => 'moved_online',
			'start_date'  => '2030-01-01',
			'timezone'    => 'UTC',
		] );
		update_post_meta( $event, '_anchor_event_previous_start', '2029-11-01' );

		$node = $this->schema()->for_event( $event );

		$this->assertSame( '2029-11-01', $node['previousStartDate'] );
	}

	/**
	 * The shared save path (persist_event_authoring(), via save_meta()) is the
	 * ONE writer of `_anchor_event_previous_start` — capturing the prior start
	 * the moment status transitions INTO postponed.
	 */
	public function test_previous_start_captured_when_event_becomes_postponed() {
		$event = $this->make_event( [
			'start_date' => '2026-08-01',
			'timezone'   => 'UTC',
		] );
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$_POST = [
			Module::NONCE             => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date' => '2026-09-15',
			'anchor_event_timezone'   => 'UTC',
			'anchor_event_status'     => 'postponed',
		];
		$this->module()->save_meta( $event );

		$this->assertSame( '2026-08-01', get_post_meta( $event, '_anchor_event_previous_start', true ) );

		$node = $this->schema()->for_event( $event );
		$this->assertSame( 'https://schema.org/EventPostponed', $node['eventStatus'] );
		$this->assertSame( '2026-08-01', $node['previousStartDate'] );
	}

	/** A further date change while STILL postponed updates the stored prior start. */
	public function test_previous_start_updates_when_postponed_date_changes_again() {
		$event = $this->make_event( [
			'start_date'  => '2026-08-01',
			'status_mode' => 'manual',
			'status'      => 'postponed',
			'timezone'    => 'UTC',
		] );
		update_post_meta( $event, '_anchor_event_previous_start', '2026-07-01' );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$_POST = [
			Module::NONCE             => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date' => '2026-09-15',
			'anchor_event_timezone'   => 'UTC',
			'anchor_event_status'     => 'postponed',
		];
		$this->module()->save_meta( $event );

		$this->assertSame(
			'2026-08-01',
			get_post_meta( $event, '_anchor_event_previous_start', true ),
			'The start just before THIS save becomes the new previous start, not the older snapshot.'
		);
	}

	/** A no-op re-save (same status, same date) must not clobber the true previous start. */
	public function test_previous_start_is_not_overwritten_by_a_no_op_resave() {
		$event = $this->make_event( [
			'start_date'  => '2026-09-15',
			'status_mode' => 'manual',
			'status'      => 'postponed',
			'timezone'    => 'UTC',
		] );
		update_post_meta( $event, '_anchor_event_previous_start', '2026-08-01' );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$_POST = [
			Module::NONCE             => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date' => '2026-09-15',
			'anchor_event_timezone'   => 'UTC',
			'anchor_event_status'     => 'postponed',
		];
		$this->module()->save_meta( $event );

		$this->assertSame(
			'2026-08-01',
			get_post_meta( $event, '_anchor_event_previous_start', true ),
			'A no-op resave must not clobber the true previous start with the current one.'
		);
	}

	/* ------------------------------------------------------------------
	 * RENDER-D10 — the anchor_events_schema_node filter.
	 * ------------------------------------------------------------------ */

	public function test_assemble_node_runs_through_anchor_events_schema_node_filter() {
		$event = $this->make_event( [ 'start_date' => '2030-01-01', 'timezone' => 'UTC' ] );

		$captured_id = null;
		add_filter( 'anchor_events_schema_node', function ( $node, $event_id ) use ( &$captured_id ) {
			$captured_id           = $event_id;
			$node['performer']     = [ '@type' => 'Person', 'name' => 'Injected' ];
			return $node;
		}, 10, 2 );

		$node = $this->schema()->for_event( $event );

		$this->assertSame( $event, $captured_id );
		$this->assertSame( 'Injected', $node['performer']['name'] );
	}

	/** Every live child of an offering parent is filtered individually, not just the container. */
	public function test_filter_runs_for_every_child_node_of_a_group_parent() {
		$parent = $this->make_offering_parent( [
			[ 'date' => '2030-10-23', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0 ],
		] );

		$seen = [];
		add_filter( 'anchor_events_schema_node', function ( $node, $event_id ) use ( &$seen ) {
			$seen[] = $event_id;
			return $node;
		}, 10, 2 );

		$this->schema()->for_event( $parent );

		$children = $this->module()->occurrences->children( $parent );
		$this->assertContains( $parent, $seen );
		$this->assertContains( (int) $children[0], $seen );
	}

	/* ------------------------------------------------------------------
	 * RENDER-D38 — superEvent, capacity pair, isAccessibleForFree.
	 * ------------------------------------------------------------------ */

	public function test_group_child_node_carries_super_event() {
		$parent = $this->make_offering_parent( [
			[ 'date' => '2030-10-23', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0 ],
		] );
		$children = $this->module()->occurrences->children( $parent );
		$child_id = (int) $children[0];

		$node = $this->schema()->for_event( $child_id );

		$this->assertArrayHasKey( 'superEvent', $node );
		$this->assertSame( 'Event', $node['superEvent']['@type'] );
		$this->assertSame( get_permalink( $parent ), $node['superEvent']['url'] );
	}

	/** A plain single event (never a group child) carries no superEvent at all. */
	public function test_single_event_has_no_super_event() {
		$event = $this->make_event( [ 'start_date' => '2030-01-01', 'timezone' => 'UTC' ] );

		$node = $this->schema()->for_event( $event );

		$this->assertArrayNotHasKey( 'superEvent', $node );
	}

	public function test_capacity_pair_reflects_authored_max_and_remaining() {
		$event = $this->make_event( [
			'registration_enabled' => true,
			'registration_mode'    => 'free',
			'capacity'             => 10,
			'start_date'           => '2030-01-01',
			'timezone'             => 'UTC',
		] );
		$this->make_seat( $event );

		$node = $this->schema()->for_event( $event );

		$this->assertSame( 10, $node['maximumAttendeeCapacity'] );
		$this->assertSame( 9, $node['remainingAttendeeCapacity'] );
	}

	/** Capacity 0 means unlimited — no false claim, same convention as choose_date_availability_hint(). */
	public function test_capacity_pair_omitted_when_unlimited() {
		$event = $this->make_event( [
			'registration_enabled' => true,
			'registration_mode'    => 'free',
			'capacity'             => 0,
			'start_date'           => '2030-01-01',
			'timezone'             => 'UTC',
		] );

		$node = $this->schema()->for_event( $event );

		$this->assertArrayNotHasKey( 'maximumAttendeeCapacity', $node );
		$this->assertArrayNotHasKey( 'remainingAttendeeCapacity', $node );
	}

	public function test_is_accessible_for_free_true_on_free_offer() {
		$event = $this->make_event( [
			'registration_enabled' => true,
			'registration_mode'    => 'free',
			'start_date'           => '2030-01-01',
			'timezone'             => 'UTC',
		] );

		$node = $this->schema()->for_event( $event );

		$this->assertTrue( $node['isAccessibleForFree'] );
	}

	/** wc-mode (ticketed) events make no free-admission claim. */
	public function test_is_accessible_for_free_absent_for_wc_events() {
		$event = $this->make_event(
			[
				'registration_enabled' => true,
				'registration_mode'    => 'wc',
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
			],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);

		$node = $this->schema()->for_event( $event );

		$this->assertArrayNotHasKey( 'isAccessibleForFree', $node );
	}

	/** No Offer at all (finished event) must not carry an isAccessibleForFree claim either. */
	public function test_is_accessible_for_free_absent_when_offer_omitted() {
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

		$this->assertArrayNotHasKey( 'isAccessibleForFree', $node );
		$this->assertArrayNotHasKey( 'offers', $node );
	}
}
