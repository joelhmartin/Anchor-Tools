<?php
/**
 * Capacity / reservation math tests (no WooCommerce required).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Registrations;

/**
 * @group capacity
 */
class Test_Capacity extends Anchor_Events_TestCase {

	/** count_reserved_seats sums confirmed + pending, weighted by guests. */
	public function test_count_reserved_seats() {
		$event_id = $this->make_event( [ 'capacity' => 50 ] );

		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED ] );
		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_PENDING ] );
		// +2 guests => weight 3.
		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED, 'guests' => 2 ] );
		// Cancelled does not count.
		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CANCELLED ] );
		// Waitlist counts separately, not toward reserved.
		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_WAITLIST ] );

		$this->assertSame( 5, $this->registrations()->count_reserved_seats( $event_id, true ) );
		$this->assertSame( 1, $this->registrations()->count_waitlist_seats( $event_id, true ) );
		$this->assertSame( 45, $this->registrations()->remaining_capacity( $event_id, 50, true ) );
	}

	/** Per-tier reserved counts + tier_remaining (min of event + tier quota). */
	public function test_per_tier_counts_and_remaining() {
		$event_id = $this->make_event( [ 'capacity' => 10 ] );
		$tiers    = $this->ticket_types()->save(
			$event_id,
			[
				[ 'label' => 'GA', 'price' => '0', 'active' => 1, 'quota' => 3 ],
				[ 'label' => 'VIP', 'price' => '0', 'active' => 1, 'quota' => 0 ],
			]
		);
		$ga  = $tiers[0];
		$vip = $tiers[1];

		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED, 'ticket_type_id' => $ga['id'] ] );
		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED, 'ticket_type_id' => $ga['id'] ] );
		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED, 'ticket_type_id' => $vip['id'] ] );

		$this->assertSame( 2, $this->registrations()->count_reserved_for_tier( $event_id, $ga['id'], true ) );
		$this->assertSame( 1, $this->registrations()->count_reserved_for_tier( $event_id, $vip['id'], true ) );

		// GA quota 3, 2 reserved → tier remaining 1 (below the event remaining of 7).
		$this->assertSame( 1, $this->registrations()->tier_remaining( $event_id, $ga, true ) );
		// VIP has no quota → bounded only by the event remaining (10 - 3 = 7).
		$this->assertSame( 7, $this->registrations()->tier_remaining( $event_id, $vip, true ) );
	}

	/** Legacy seats with no tier meta count under the implicit 'primary' tier. */
	public function test_legacy_seats_count_under_primary() {
		$event_id = $this->make_event( [ 'capacity' => 10 ] );
		$seat_id  = $this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED ] );

		// Simulate a pre-tier (legacy) seat: remove the tier meta entirely.
		delete_post_meta( $seat_id, '_anchor_event_ticket_type_id' );

		$this->assertSame(
			1,
			$this->registrations()->count_reserved_for_tier( $event_id, 'primary', true )
		);
	}

	/** capacity_decision: open below capacity, full at capacity (no waitlist). */
	public function test_capacity_decision_event_total() {
		$event_id = $this->make_event( [ 'capacity' => 2, 'waitlist' => false ] );
		$meta     = $this->module()->get_meta( $event_id );
		$reg      = $this->registrations();

		$this->assertSame( 'open', $reg->capacity_decision( $event_id, $meta, 1 ) );

		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED ] );
		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED ] );

		$this->assertSame( 'full', $reg->capacity_decision( $event_id, $meta, 1 ) );
	}

	/** capacity_decision: a full event with the waitlist toggle on returns waitlist. */
	public function test_capacity_decision_waitlist() {
		$event_id = $this->make_event( [ 'capacity' => 1, 'waitlist' => true ] );
		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED ] );
		$meta = $this->module()->get_meta( $event_id );

		$this->assertSame(
			Registrations::STATUS_WAITLIST,
			$this->registrations()->capacity_decision( $event_id, $meta, 1 )
		);
	}

	/** capacity_decision: a tier quota exhausted (event has room) returns 'full', no waitlist. */
	public function test_capacity_decision_tier_quota() {
		$event_id = $this->make_event( [ 'capacity' => 100, 'waitlist' => true ] );
		$tiers    = $this->ticket_types()->save(
			$event_id,
			[ [ 'label' => 'Limited', 'price' => '0', 'active' => 1, 'quota' => 1 ] ]
		);
		$tier = $tiers[0];
		$meta = $this->module()->get_meta( $event_id );
		$reg  = $this->registrations();

		$this->assertSame( 'open', $reg->capacity_decision( $event_id, $meta, 1, $tier ) );

		$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED, 'ticket_type_id' => $tier['id'] ] );

		// Tier sold out while the event still has room → 'full' (tier never waitlists).
		$this->assertSame( 'full', $reg->capacity_decision( $event_id, $meta, 1, $tier ) );
	}

	/** claim_seats honors the event capacity ceiling (partial fill, no waitlist). */
	public function test_claim_seats_event_capacity() {
		$event_id = $this->make_event( [ 'capacity' => 2, 'waitlist' => false ] );
		$meta     = $this->module()->get_meta( $event_id );

		$result = $this->registrations()->claim_seats(
			$event_id,
			$meta,
			3,
			[ 'source' => 'internal', 'name' => 'A', 'email' => 'a@example.test' ]
		);

		$this->assertCount( 2, $result['created'] );
		$this->assertCount( 0, $result['waitlisted'] );
		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( 2, $this->registrations()->count_reserved_seats( $event_id, true ) );
	}

	/** claim_seats overflows surplus to the waitlist when the toggle is on. */
	public function test_claim_seats_waitlist_overflow() {
		$event_id = $this->make_event( [ 'capacity' => 2, 'waitlist' => true ] );
		$meta     = $this->module()->get_meta( $event_id );

		$result = $this->registrations()->claim_seats(
			$event_id,
			$meta,
			3,
			[ 'source' => 'internal', 'name' => 'A', 'email' => 'a@example.test' ]
		);

		$this->assertCount( 2, $result['created'] );
		$this->assertCount( 1, $result['waitlisted'] );
		$this->assertSame( 1, $this->registrations()->count_waitlist_seats( $event_id, true ) );
	}

	/** claim_seats respects a per-tier quota nested under the event total. */
	public function test_claim_seats_tier_quota() {
		$event_id = $this->make_event( [ 'capacity' => 100, 'waitlist' => false ] );
		$tiers    = $this->ticket_types()->save(
			$event_id,
			[ [ 'label' => 'Limited', 'price' => '0', 'active' => 1, 'quota' => 1 ] ]
		);
		$tier = $tiers[0];
		$meta = $this->module()->get_meta( $event_id );

		$result = $this->registrations()->claim_seats(
			$event_id,
			$meta,
			3,
			[ 'source' => 'internal', 'name' => 'A', 'email' => 'a@example.test', 'ticket_type_id' => $tier['id'] ],
			$tier
		);

		// Tier quota 1 → only one seat created; the rest are dropped (tier never waitlists).
		$this->assertCount( 1, $result['created'] );
		$this->assertCount( 0, $result['waitlisted'] );
		$this->assertSame(
			1,
			$this->registrations()->count_reserved_for_tier( $event_id, $tier['id'], true )
		);
	}

	/** claim_seats with $allow_over bypasses both the event ceiling and the tier quota. */
	public function test_claim_seats_allow_over_bypass() {
		$event_id = $this->make_event( [ 'capacity' => 1, 'waitlist' => false ] );
		$tiers    = $this->ticket_types()->save(
			$event_id,
			[ [ 'label' => 'Limited', 'price' => '0', 'active' => 1, 'quota' => 1 ] ]
		);
		$tier = $tiers[0];
		$meta = $this->module()->get_meta( $event_id );

		$result = $this->registrations()->claim_seats(
			$event_id,
			$meta,
			3,
			[ 'source' => 'manual', 'name' => 'A', 'email' => 'a@example.test', 'ticket_type_id' => $tier['id'] ],
			$tier,
			true // allow_over
		);

		$this->assertCount( 3, $result['created'] );
		$this->assertCount( 0, $result['waitlisted'] );
		// All three are confirmed and consume capacity past the ceiling.
		$this->assertSame( 3, $this->registrations()->count_reserved_seats( $event_id, true ) );
	}

	/* ------------------------------------------------------------------
	 * MODEL-D5 / WOO-D2 — capacity_decision() is the single purchasability
	 * authority, and Module::bookability() is the one predicate every reader
	 * (storefront, cart, date picker, series archive, schema) asks.
	 * ------------------------------------------------------------------ */

	/**
	 * MODEL-D5: an event that has already finished is closed, however much
	 * room it has left. Without this the picker printed "Open"/"Register" and
	 * handle_registration() minted a real seat on a course that already ran.
	 *
	 * The `_ts` rows are written the way every production save path writes
	 * them (compute_timestamps()); make_event() only sets the date meta.
	 */
	public function test_past_event_is_closed_even_with_room() {
		$event = $this->make_past_event( [ 'capacity' => 10 ] );

		$this->assertSame(
			'closed',
			$this->registrations()->capacity_decision( $event, $this->module()->get_meta( $event ) )
		);
	}

	/** An event with no end_ts row is UNDATED, not past (RENDER-D31) — it stays open. */
	public function test_event_with_no_end_ts_is_not_treated_as_past() {
		$event = $this->make_event( [ 'capacity' => 10, 'start_date' => '2020-01-01' ] );
		delete_post_meta( $event, '_anchor_event_end_ts' );

		$this->assertSame(
			'open',
			$this->registrations()->capacity_decision( $event, $this->module()->get_meta( $event ) )
		);
	}

	/** bookability(): the hand-set "sold out" flag reaches every reader as 'full'. */
	public function test_bookability_reports_sold_out_flag() {
		$event = $this->make_event( [
			'capacity'             => 0,
			'registration_enabled' => true,
			'sold_out'             => true,
			'start_date'           => '2030-01-01',
		] );

		$this->assertSame( 'full', $this->module()->bookability( $event ) );
	}

	/** bookability(): sold out + waitlist on is 'waitlist', not 'full'. */
	public function test_bookability_reports_waitlist() {
		$event = $this->make_event( [
			'capacity'             => 1,
			'registration_enabled' => true,
			'waitlist'             => true,
			'start_date'           => '2030-01-01',
		] );
		$this->make_seat( $event );

		$this->assertSame( 'waitlist', $this->module()->bookability( $event ) );
	}

	/** bookability(): registration switched off is 'disabled', not 'open'. */
	public function test_bookability_reports_disabled_registration() {
		$event = $this->make_event( [ 'registration_enabled' => false, 'start_date' => '2030-01-01' ] );

		$this->assertSame( 'disabled', $this->module()->bookability( $event ) );
	}

	/** bookability(): a finished event is 'closed' on every route. */
	public function test_bookability_reports_past_event_as_closed() {
		$event = $this->make_past_event( [ 'capacity' => 10 ] );

		$this->assertSame( 'closed', $this->module()->bookability( $event ) );
	}

	/** bookability(): a per-tier quota that is exhausted is 'full' for that tier only. */
	public function test_bookability_reports_tier_quota_as_full() {
		$event = $this->make_event(
			[ 'capacity' => 100, 'waitlist' => true, 'start_date' => '2030-01-01' ],
			[ [ 'label' => 'Limited', 'price' => '0', 'active' => 1, 'quota' => 1 ] ]
		);
		$tier = $this->ticket_types()->get( $event )[0];

		$this->assertSame( 'open', $this->module()->bookability( $event, $tier ) );

		$this->make_seat( $event, [ 'ticket_type_id' => $tier['id'] ] );

		$this->assertSame( 'full', $this->module()->bookability( $event, $tier ) );
		// The event itself still has room.
		$this->assertSame( 'open', $this->module()->bookability( $event ) );
	}

	/** bookability(): a group PARENT is a container, never a seat — 'parent'. */
	public function test_bookability_reports_group_parent() {
		$parent = $this->make_event( [
			'type'                 => 'offering',
			'registration_enabled' => true,
			'timezone'             => 'UTC',
		] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-10-23', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0 ],
		] );
		$this->module()->occurrences->reconcile( $parent );

		$this->assertSame( 'parent', $this->module()->bookability( $parent ) );
	}

	/** bookability(): a soft-closed occurrence is 'closed' even reached by direct URL. */
	public function test_bookability_reports_soft_closed_child_as_closed() {
		$rows = [
			[ 'date' => '2030-10-23', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0 ],
			[ 'date' => '2030-11-13', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'November', 'capacity' => 0 ],
		];
		$parent = $this->make_event( [
			'type'                 => 'offering',
			'registration_enabled' => true,
			'timezone'             => 'UTC',
		] );
		update_post_meta( $parent, '_anchor_event_offering_dates', $rows );
		$live = $this->module()->occurrences->reconcile( $parent );

		$closed = $live[0];
		$this->make_seat( $closed ); // Seated → soft-closed rather than trashed.
		update_post_meta( $parent, '_anchor_event_offering_dates', array_slice( $rows, 1 ) );
		$this->module()->occurrences->reconcile( $parent );

		$this->assertSame( 'closed', $this->module()->bookability( $closed ) );
	}

	/**
	 * An event dated in the past, with the `_ts` rows every production save
	 * path writes.
	 *
	 * @param array $meta Extra event meta.
	 * @return int
	 */
	protected function make_past_event( array $meta = [] ) {
		$event = $this->make_event( array_merge( [
			'registration_enabled' => true,
			'start_date'           => '2020-01-01',
			'end_date'             => '2020-01-01',
			'timezone'             => 'UTC',
		], $meta ) );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		update_post_meta( $event, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $event, '_anchor_event_end_ts', $ts['end'] );
		return $event;
	}
}
