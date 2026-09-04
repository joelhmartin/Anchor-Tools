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

		$decision = $this->registrations()->capacity_decision( $event_id, $meta, 1 );
		$this->assertSame( Registrations::STATUS_WAITLIST, $decision );

		// REG-D52 — pinned deliberately: the one return value mixes the decision
		// vocabulary ('open'|'closed'|'full') with a SEAT STATUS. A caller that
		// reads it as a decision alone books a waitlist seat as confirmed. The
		// register's fix is a {decision, seat_status} pair, which would change
		// this signature, so the shape is recorded here instead of changed.
		$this->assertNotContains( $decision, [ 'open', 'closed', 'full' ] );
		$this->assertTrue( $this->registrations()->valid_status( $decision ) );
	}

	/**
	 * REG-D54 — one status set for the seat CPT. tier_has_seats() asked for
	 * 'any' while every counting query asks for 'publish', so a trashed seat
	 * stopped consuming capacity but still blocked Product_Sync from deleting
	 * the tier's managed variation.
	 */
	public function test_a_trashed_seat_is_invisible_to_every_seat_query_alike() {
		$event_id = $this->make_event( [ 'capacity' => 10 ] );
		$seat_id  = $this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED ] );

		$this->assertSame( 1, $this->registrations()->count_reserved_seats( $event_id ) );
		$this->assertTrue( $this->registrations()->tier_has_seats( $event_id, 'primary' ) );

		wp_trash_post( $seat_id );
		$this->registrations()->bust_cache( $event_id );

		$this->assertSame( 0, $this->registrations()->count_reserved_seats( $event_id ) );
		$this->assertFalse(
			$this->registrations()->tier_has_seats( $event_id, 'primary' ),
			'A seat the capacity count cannot see must not keep a tier alive either.'
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

	/* ------------------------------------------------------------------
	 * NEW-D2 — what the event IS outranks whether the button is on.
	 *
	 * bookability() used to answer 'disabled' the moment
	 * `registration_enabled` was unticked, before it had asked the seat layer
	 * anything. Production child 7528 (sold_out=1, registration_enabled=0)
	 * therefore emitted a JSON-LD Offer with a price and no availability while
	 * its own page said "Sold out", and the DEKA theme grew a SECOND capacity
	 * accessor to work around it. A sold-out / finished / cancelled course
	 * says so regardless of the switch; only an otherwise-bookable event
	 * reports 'disabled'.
	 * ------------------------------------------------------------------ */

	/** The hand-set sold_out flag outranks the registration switch. */
	public function test_bookability_reports_sold_out_before_disabled() {
		$event = $this->make_event( [
			'registration_enabled' => false,
			'capacity'             => 0,
			'sold_out'             => true,
			'start_date'           => '2030-01-01',
		] );

		$this->assertSame( 'full', $this->module()->bookability( $event ) );
	}

	/** So does the event having already finished. */
	public function test_bookability_reports_a_finished_event_before_disabled() {
		$event = $this->make_past_event( [ 'registration_enabled' => false, 'capacity' => 10 ] );

		$this->assertSame( 'closed', $this->module()->bookability( $event ) );
	}

	/** And so does a closed registration window. */
	public function test_bookability_reports_a_closed_window_before_disabled() {
		$event = $this->make_event( [
			'registration_enabled' => false,
			'start_date'           => '2030-01-01',
			'registration_close'   => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS * 2 ),
		] );

		$this->assertSame( 'closed', $this->module()->bookability( $event ) );
	}

	/** A hand-cancelled event is closed even with registration switched ON. */
	public function test_bookability_reports_a_manual_cancellation_as_closed() {
		$event = $this->make_event( [
			'registration_enabled' => true,
			'status_mode'          => 'manual',
			'status'               => 'cancelled',
			'start_date'           => '2030-01-01',
		] );

		$this->assertSame( 'closed', $this->module()->bookability( $event ) );
	}

	/**
	 * ...but a stale 'cancelled' row on an AUTO-mode event is not a
	 * cancellation — auto mode owns that row and recomputes it, which is
	 * exactly why the status is read through get_event_status().
	 */
	public function test_bookability_ignores_a_stale_cancelled_row_in_auto_mode() {
		$event = $this->make_event( [
			'registration_enabled' => true,
			'status_mode'          => 'auto',
			'status'               => 'cancelled',
			'start_date'           => '2030-01-01',
		] );

		$this->assertSame( 'open', $this->module()->bookability( $event ) );
	}

	/**
	 * The waitlist is a BOOKABLE state (is_bookable() accepts it, and the cart
	 * mints a real waitlist seat from it), so a sold-out event whose switch is
	 * off reports 'full', not 'waitlist' — nothing can be taken on an event
	 * with registration disabled, and 'waitlist' would make one purchasable
	 * from the managed product's permalink.
	 */
	public function test_bookability_does_not_offer_the_waitlist_when_registration_is_off() {
		$event = $this->make_event( [
			'registration_enabled' => false,
			'capacity'             => 0,
			'sold_out'             => true,
			'waitlist'             => true,
			'start_date'           => '2030-01-01',
		] );

		$this->assertSame( 'full', $this->module()->bookability( $event ) );
		$this->assertFalse( $this->module()->is_bookable( $this->module()->bookability( $event ) ) );
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

	/* -----------------------------------------------------------------
	 * change_status_with_capacity(): the two ceilings answer differently
	 * --------------------------------------------------------------- */

	/**
	 * A revive blocked by the EVENT total goes to the waitlist, as it always
	 * has — the event total is the only ceiling the waitlist answers to.
	 */
	public function test_a_revive_blocked_by_the_event_total_is_waitlisted() {
		$event_id = $this->make_event( [ 'capacity' => 1, 'waitlist' => true ] );
		$this->make_seat( $event_id );
		$seat_id = $this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CANCELLED ] );

		$out = $this->registrations()->change_status_with_capacity( $seat_id, Registrations::STATUS_CONFIRMED );

		$this->assertTrue( $out->is_sent() );
		$this->assertSame( 'waitlisted', $out->reason() );
		$this->assertSame(
			Registrations::STATUS_WAITLIST,
			get_post_meta( $seat_id, '_anchor_event_reg_status', true )
		);
	}

	/**
	 * A revive blocked ONLY by the tier quota is refused as `tier_full` and
	 * left where it was. It used to be waitlisted with "(event full —
	 * waitlisted instead)" on an event with 99 empty seats: `$fits` went false
	 * for both ceilings, so the tier shortage borrowed the event's answer.
	 * capacity_decision() and claim_seats() have always said a sold-out tier
	 * never waitlists.
	 */
	public function test_a_revive_blocked_only_by_the_tier_quota_is_refused_not_waitlisted() {
		$event_id = $this->make_event(
			[ 'capacity' => 100, 'waitlist' => true ],
			[ [ 'label' => 'Limited', 'price' => '0', 'active' => 1, 'quota' => 1 ] ]
		);
		$tier = $this->ticket_types()->get( $event_id )[0];

		$this->make_seat( $event_id, [ 'ticket_type_id' => $tier['id'] ] );
		$seat_id = $this->make_seat(
			$event_id,
			[ 'status' => Registrations::STATUS_CANCELLED, 'ticket_type_id' => $tier['id'] ]
		);

		$out = $this->registrations()->change_status_with_capacity( $seat_id, Registrations::STATUS_CONFIRMED );

		$this->assertTrue( $out->is_failed() );
		$this->assertSame( 'tier_full', $out->reason() );
		$this->assertSame(
			Registrations::STATUS_CANCELLED,
			get_post_meta( $seat_id, '_anchor_event_reg_status', true ),
			'A tier-only shortage moved the seat anyway.'
		);
		$this->assertSame(
			0,
			$this->registrations()->count_waitlist_seats( $event_id, true ),
			'A sold-out tier put a seat on the event waitlist.'
		);
	}

	/** The override still confirms straight past a tier-only shortage. */
	public function test_the_override_confirms_past_a_tier_only_shortage() {
		$event_id = $this->make_event(
			[ 'capacity' => 100, 'waitlist' => true ],
			[ [ 'label' => 'Limited', 'price' => '0', 'active' => 1, 'quota' => 1 ] ]
		);
		$tier = $this->ticket_types()->get( $event_id )[0];

		$this->make_seat( $event_id, [ 'ticket_type_id' => $tier['id'] ] );
		$seat_id = $this->make_seat(
			$event_id,
			[ 'status' => Registrations::STATUS_CANCELLED, 'ticket_type_id' => $tier['id'] ]
		);

		$out = $this->registrations()->change_status_with_capacity(
			$seat_id,
			Registrations::STATUS_CONFIRMED,
			'',
			'user:1',
			true // allow_over
		);

		$this->assertTrue( $out->is_sent() );
		$this->assertSame(
			Registrations::STATUS_CONFIRMED,
			get_post_meta( $seat_id, '_anchor_event_reg_status', true )
		);
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
