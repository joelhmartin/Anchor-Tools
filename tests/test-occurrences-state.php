<?php
/**
 * Soft-closed occurrence state: idempotent writes + self-repair (MODEL-D6,
 * WOO-D35).
 *
 * "Soft-closed" is a FOUR-field state (status_mode=manual, status=cancelled,
 * registration_enabled=false, occurrence_closed=true). Every writer of that
 * state used to guard itself with an early return on the flag alone, so a row
 * carrying only PART of the quartet — production child 7530 had
 * occurrence_closed=1 and registration_enabled=0 but status='past', because a
 * later save recomputed the status — could never be repaired by any code path:
 * soft_close() no-op'd (already "closed"), revive_if_closed() no-op'd (not
 * "closed" once the flag was cleared by hand), and sync_child_from_parent()
 * skipped all four keys because they live in PER_OCCURRENCE_KEYS.
 *
 * These tests pin the repair: whichever half of the state survives, the next
 * reconcile() writes the whole quartet back — closed if the date is gone,
 * revived if the date is back — and doing it twice changes nothing.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Registrations;

/**
 * @group occurrences
 */
class Test_Occurrences_State extends Anchor_Events_TestCase {

	/** @return \Anchor\Events\Occurrences */
	protected function occurrences() {
		return $this->module()->occurrences;
	}

	/**
	 * Create a group-parent event with the given offering-dates rows, exactly
	 * the way test-event-grouping-frontend.php does.
	 *
	 * @param array $rows Offering-dates rows.
	 * @param array $meta Additional parent meta overrides.
	 * @return int Parent event post id.
	 */
	protected function make_parent( array $rows, array $meta = [] ) {
		$parent_id = $this->make_event( array_merge( [
			'title'    => 'Workshop',
			'venue'    => 'Main Hall',
			'timezone' => 'UTC',
		], $meta ) );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', $rows );
		return $parent_id;
	}

	protected function two_rows() {
		return [
			[ 'date' => '2027-03-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 10 ],
			[ 'date' => '2027-03-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B', 'capacity' => 10 ],
		];
	}

	/** Keep only the second row (drops the 2027-03-01 date). */
	protected function drop_first_date( $parent_id ) {
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [ $this->two_rows()[1] ] );
	}

	/** Assert the full soft-closed quartet is present on a child. */
	protected function assertSoftClosed( $child_id, $message = '' ) {
		$this->assertSame( 'manual', get_post_meta( $child_id, '_anchor_event_status_mode', true ), $message );
		$this->assertSame( 'cancelled', get_post_meta( $child_id, '_anchor_event_status', true ), $message );
		$this->assertFalse( (bool) get_post_meta( $child_id, '_anchor_event_registration_enabled', true ), $message );
		$this->assertTrue( (bool) get_post_meta( $child_id, '_anchor_event_occurrence_closed', true ), $message );
	}

	/**
	 * Build a parent whose first date has been dropped while holding a seat,
	 * i.e. a genuinely soft-closed child.
	 *
	 * @return array{0:int,1:int} [ parent_id, soft-closed child id ]
	 */
	protected function soft_closed_child( array $meta = [] ) {
		$parent_id = $this->make_parent( $this->two_rows(), $meta );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0]; // 2027-03-01

		$this->make_seat( $child, [ 'status' => Registrations::STATUS_CONFIRMED ] );
		$this->drop_first_date( $parent_id );
		$this->occurrences()->reconcile( $parent_id );

		$this->assertSoftClosed( $child, 'Precondition: the dropped seated date must be soft-closed.' );

		return [ $parent_id, $child ];
	}

	/* ------------------------------------------------------------------
	 * (a) The 7530 partial: flag set, status recomputed by a later save.
	 * ------------------------------------------------------------------ */

	public function test_partial_closed_child_with_seats_is_normalised_on_reconcile() {
		list( $parent_id, $child ) = $this->soft_closed_child();

		// A later save (metabox / front-end manager) recomputes the status and
		// flips the mode back to auto while leaving occurrence_closed=1.
		update_post_meta( $child, '_anchor_event_status_mode', 'auto' );
		update_post_meta( $child, '_anchor_event_status', 'past' );

		$this->occurrences()->reconcile( $parent_id );

		$this->assertSoftClosed( $child, 'A partially-written closed row must be repaired by the next reconcile.' );
	}

	/**
	 * The production instance verbatim: 7530 holds no seats at all (zero
	 * anchor_event_reg posts exist on that site), so the repair cannot depend
	 * on the roster-preserving branch of retire_child().
	 */
	public function test_partial_closed_seatless_child_is_normalised_on_reconcile() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		// Hand-build the 7530 state on a seatless child, then drop its date.
		update_post_meta( $child, '_anchor_event_occurrence_closed', true );
		update_post_meta( $child, '_anchor_event_registration_enabled', false );
		update_post_meta( $child, '_anchor_event_status_mode', 'auto' );
		update_post_meta( $child, '_anchor_event_status', 'past' );

		$this->drop_first_date( $parent_id );
		$this->occurrences()->reconcile( $parent_id );

		$this->assertSame(
			'publish',
			get_post_status( $child ),
			'An already-closed child with no seats must still be preserved, not surprise-trashed.'
		);
		$this->assertSoftClosed( $child, 'The seatless 7530 partial must be repaired, not left alone.' );
	}

	/* ------------------------------------------------------------------
	 * (b) The mirror-image partial: flag cleared by hand, status still
	 *     cancelled. Repaired both ways — revived when the date comes back,
	 *     re-closed while it is still absent.
	 * ------------------------------------------------------------------ */

	public function test_flag_cleared_partial_is_revived_when_its_date_is_re_added() {
		list( $parent_id, $child ) = $this->soft_closed_child();

		// Someone clears the engine flag by hand; the status half survives.
		update_post_meta( $child, '_anchor_event_occurrence_closed', false );

		// The date comes back.
		update_post_meta( $parent_id, '_anchor_event_offering_dates', $this->two_rows() );
		$live = $this->occurrences()->reconcile( $parent_id );

		$this->assertContains( $child, $live, 'The revived child must be the SAME post id.' );
		$this->assertFalse( (bool) get_post_meta( $child, '_anchor_event_occurrence_closed', true ) );
		$this->assertSame(
			'auto',
			get_post_meta( $child, '_anchor_event_status_mode', true ),
			'A revived occurrence goes back to auto status.'
		);
		$this->assertSame(
			'upcoming',
			get_post_meta( $child, '_anchor_event_status', true ),
			'A revived 2027 date is upcoming, not cancelled.'
		);
	}

	/**
	 * Reviving must still NOT force registration back on — that field is a
	 * separate decision (documented in revive_if_closed()), and the mirror
	 * partial must not become a back door for re-enabling sales.
	 */
	public function test_revive_of_flag_cleared_partial_does_not_force_registration_on() {
		list( $parent_id, $child ) = $this->soft_closed_child( [ 'registration_enabled' => false ] );

		update_post_meta( $child, '_anchor_event_occurrence_closed', false );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', $this->two_rows() );
		$this->occurrences()->reconcile( $parent_id );

		$raw = get_post_meta( $child, '_anchor_event_registration_enabled', true );
		$this->assertFalse(
			(bool) $raw,
			'revive must not force registration_enabled=true.'
		);
	}

	public function test_flag_cleared_partial_is_re_closed_while_its_date_is_still_absent() {
		list( $parent_id, $child ) = $this->soft_closed_child();

		update_post_meta( $child, '_anchor_event_occurrence_closed', false );

		// The date is still gone — the next reconcile re-asserts the quartet.
		$this->occurrences()->reconcile( $parent_id );

		$this->assertSoftClosed( $child, 'A cleared flag on a still-absent date must be re-asserted.' );
	}

	/**
	 * A deliberately, manually cancelled LIVE date is not a partial: its
	 * registration flag was never turned off by soft_close(), so the repair
	 * must leave the admin's choice alone.
	 */
	public function test_manually_cancelled_live_child_is_not_treated_as_a_partial() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		update_post_meta( $child, '_anchor_event_status_mode', 'manual' );
		update_post_meta( $child, '_anchor_event_status', 'cancelled' );

		$this->occurrences()->reconcile( $parent_id );

		$this->assertSame( 'manual', get_post_meta( $child, '_anchor_event_status_mode', true ) );
		$this->assertSame(
			'cancelled',
			get_post_meta( $child, '_anchor_event_status', true ),
			'A hand-cancelled live date keeps its manual status; only the soft-close signature is repaired.'
		);
	}

	/* ------------------------------------------------------------------
	 * (c) Idempotency: closing twice changes nothing.
	 * ------------------------------------------------------------------ */

	public function test_soft_closing_twice_is_a_no_op_the_second_time() {
		list( $parent_id, $child ) = $this->soft_closed_child();

		$before = get_post_meta( $child );
		$seats  = $this->count_seats( $child );
		$status = get_post_status( $child );

		// Second reconcile with the same (still absent) date.
		$this->occurrences()->reconcile( $parent_id );

		$this->assertSame( $before, get_post_meta( $child ), 'Re-closing an already-closed occurrence must not change any meta.' );
		$this->assertSame( $seats, $this->count_seats( $child ), 'Re-closing must not touch the roster.' );
		$this->assertSame( $status, get_post_status( $child ) );
	}
}
