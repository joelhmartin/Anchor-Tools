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
}
