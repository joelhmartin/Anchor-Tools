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
 * soft_close() no-op'd (already "closed") and sync_child_from_parent() skipped
 * all four keys because they live in PER_OCCURRENCE_KEYS.
 *
 * These tests pin the repair: every writer of the closed state now asserts the
 * whole quartet, so the next reconcile() of a still-absent date normalises the
 * row whichever half survived, and doing it twice changes nothing. They also
 * pin the limit of that repair — `occurrence_closed` remains the ONLY trigger
 * for reopening a date, because the status half on its own is exactly what an
 * admin writes when they cancel a date by hand.
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
	 * (b) The mirror-image partial — flag cleared by hand, status half still
	 *     cancelled — is repaired by the CLOSING side while its date is
	 *     absent. It is deliberately NOT repaired by reviving: the status half
	 *     alone is indistinguishable from an admin cancelling a date, so
	 *     `occurrence_closed` stays the only reopening trigger (see the
	 *     negative tests below).
	 * ------------------------------------------------------------------ */

	public function test_flag_cleared_partial_is_re_closed_while_its_date_is_still_absent() {
		list( $parent_id, $child ) = $this->soft_closed_child();

		update_post_meta( $child, '_anchor_event_occurrence_closed', false );

		// The date is still gone — the next reconcile re-asserts the quartet.
		$this->occurrences()->reconcile( $parent_id );

		$this->assertSoftClosed( $child, 'A cleared flag on a still-absent date must be re-asserted.' );
	}

	/**
	 * A deliberately, manually cancelled LIVE date is not a partial and must
	 * never be reopened by reconcile(). This is the case that rules out
	 * inferring closure from the status half of the quartet: an admin who
	 * cancels a date AND unchecks registration writes exactly what soft_close()
	 * writes, minus the flag. Reviving on that would flip a real cancellation
	 * back to auto/upcoming on the next parent save and publish
	 * schema.org/EventScheduled for it.
	 */
	public function test_manually_cancelled_live_child_with_registration_off_is_not_revived() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		// The metabox / manager form's own output for "Cancelled + registration off".
		update_post_meta( $child, '_anchor_event_status_mode', 'manual' );
		update_post_meta( $child, '_anchor_event_status', 'cancelled' );
		update_post_meta( $child, '_anchor_event_registration_enabled', false );

		// The date is still offered, so reconcile() takes the matched branch.
		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertContains( $child, $live2, 'Precondition: the date is still live, so revive_if_closed() runs.' );
		$this->assertSame( 'manual', get_post_meta( $child, '_anchor_event_status_mode', true ) );
		$this->assertSame(
			'cancelled',
			get_post_meta( $child, '_anchor_event_status', true ),
			"An admin's cancellation must survive a parent save — only occurrence_closed reopens a date."
		);
		$this->assertFalse( (bool) get_post_meta( $child, '_anchor_event_registration_enabled', true ) );
	}

	/**
	 * The same, but the child never had to be touched at all: create_child()
	 * seeds registration_enabled from the parent, so on a parent whose own
	 * registration is off EVERY child carries a false flag from birth, and a
	 * plain "Cancelled" pick in the metabox is enough to complete the
	 * soft-close-looking triple.
	 */
	public function test_manually_cancelled_child_of_a_registration_off_parent_is_not_revived() {
		$parent_id = $this->make_parent( $this->two_rows(), [ 'registration_enabled' => false ] );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		$this->assertFalse(
			(bool) get_post_meta( $child, '_anchor_event_registration_enabled', true ),
			'Precondition: the child inherits the parent\'s disabled registration at creation.'
		);

		update_post_meta( $child, '_anchor_event_status_mode', 'manual' );
		update_post_meta( $child, '_anchor_event_status', 'cancelled' );

		$this->occurrences()->reconcile( $parent_id );

		$this->assertSame( 'manual', get_post_meta( $child, '_anchor_event_status_mode', true ) );
		$this->assertSame(
			'cancelled',
			get_post_meta( $child, '_anchor_event_status', true ),
			'A cancelled date on a registration-off parent must not be silently reopened.'
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

	/* ------------------------------------------------------------------
	 * (d) Children are found by GROUP IDENTITY, not by post_status
	 *     (MODEL-D9) — and two children sharing one occurrence_key no longer
	 *     collapse silently (MODEL-D39).
	 * ------------------------------------------------------------------ */

	/**
	 * Every child post of a parent, in ANY status including trash — the raw
	 * truth the map is supposed to describe.
	 *
	 * @param int $parent_id
	 * @return int[]
	 */
	protected function all_child_posts( $parent_id ) {
		return get_posts( [
			'post_type'      => \Anchor\Events\Module::CPT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => '_anchor_event_group_role', 'value' => 'child' ],
				[ 'key' => '_anchor_event_group_id', 'value' => (int) $parent_id, 'type' => 'NUMERIC' ],
			],
		] );
	}

	/** Error-log entries recorded under one code. */
	protected function log_entries( $code ) {
		$log = get_option( \Anchor\Events\Events_Log::ERROR_OPTION, [] );
		if ( ! is_array( $log ) ) {
			return [];
		}
		return array_values( array_filter( $log, function ( $entry ) use ( $code ) {
			return isset( $entry['code'] ) && $entry['code'] === $code;
		} ) );
	}

	/**
	 * Clone an existing child the way a duplicate-post plugin does: same
	 * group_id, same occurrence_key, its own post id.
	 *
	 * @param int $child_id
	 * @return int
	 */
	protected function clone_child( $child_id ) {
		$clone_id = self::factory()->post->create( [
			'post_type'   => \Anchor\Events\Module::CPT,
			'post_status' => 'publish',
			'post_title'  => get_the_title( $child_id ) . ' (copy)',
		] );
		foreach ( get_post_meta( $child_id ) as $key => $values ) {
			update_post_meta( $clone_id, $key, maybe_unserialize( $values[0] ) );
		}
		return $clone_id;
	}

	/**
	 * MODEL-D9: an admin hides one occurrence by setting it to Draft. The date
	 * is still offered, so the next reconcile must MATCH that child — not walk
	 * past it and insert a second published post for the same date.
	 */
	public function test_drafted_child_is_matched_not_duplicated() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		wp_update_post( [ 'ID' => $child, 'post_status' => 'draft' ] );

		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertCount(
			2,
			$this->all_child_posts( $parent_id ),
			'A drafted occurrence must be matched by its group identity, not duplicated.'
		);
		$this->assertContains( $child, $live2, 'The drafted child is still the occurrence for its date.' );
		$this->assertSame(
			'draft',
			get_post_status( $child ),
			"reconcile() must leave a matched child's post_status alone — the admin chose it."
		);
	}

	/** ...and the drafted child is still SYNCED like any other live child. */
	public function test_drafted_child_is_still_synced_from_the_parent() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		wp_update_post( [ 'ID' => $child, 'post_status' => 'draft' ] );
		update_post_meta( $parent_id, '_anchor_event_venue', 'Annex' );

		$this->occurrences()->reconcile( $parent_id );

		$this->assertSame( 'Annex', get_post_meta( $child, '_anchor_event_venue', true ) );
		$this->assertSame( 'draft', get_post_status( $child ) );
	}

	/** ...but it is NOT offered to the public: children() stays published-only. */
	public function test_drafted_child_is_not_offered_by_children() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		wp_update_post( [ 'ID' => $child, 'post_status' => 'draft' ] );
		$this->occurrences()->reconcile( $parent_id );

		$this->assertNotContains(
			$child,
			$this->occurrences()->children( $parent_id, false ),
			'An unpublished occurrence must not reach the public date picker.'
		);
		$this->assertNotContains(
			$child,
			$this->occurrences()->children( $parent_id, true ),
			'…including the include-closed listing, which feeds schema + archive exclusions.'
		);
	}

	/** A drafted child whose date is dropped is retired like any other child. */
	public function test_drafted_child_is_retired_when_its_date_is_dropped() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		wp_update_post( [ 'ID' => $child, 'post_status' => 'draft' ] );
		$this->drop_first_date( $parent_id );
		$this->occurrences()->reconcile( $parent_id );

		$this->assertSame(
			'trash',
			get_post_status( $child ),
			'An unseated, no-longer-offered occurrence is trashed whatever status it was hidden in.'
		);
	}

	/**
	 * MODEL-D39: two children carrying the same occurrence_key. One is kept as
	 * the canonical occurrence, the other is RETIRED (trashed, having no
	 * seats) and reported — instead of surviving as an invisible published
	 * orphan with its own product and its own seats.
	 */
	public function test_duplicate_occurrence_key_is_retired_and_logged() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		$clone = $this->clone_child( $child );
		$this->assertGreaterThan( $child, $clone, 'Precondition: the copy is the newer post.' );

		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertContains( $child, $live2, 'The original occurrence stays canonical.' );
		$this->assertNotContains( $clone, $live2 );
		$this->assertSame( 'trash', get_post_status( $clone ), 'An unseated duplicate is trashed, not left published.' );
		$this->assertSame( 'publish', get_post_status( $child ) );
		$this->assertNotContains(
			$clone,
			$this->occurrences()->children( $parent_id, true ),
			'A retired duplicate is not an occurrence of the group any more.'
		);

		$entries = $this->log_entries( 'duplicate_occurrence' );
		$this->assertNotEmpty( $entries, 'Collapsing a duplicate must never be silent.' );
		$context = $entries[0]['context'];
		$this->assertSame( $parent_id, (int) $context['parent_id'] );
		$this->assertSame( '2027-03-01|09:00', (string) $context['occurrence_key'] );
		$this->assertSame( $child, (int) $context['kept'] );
		$this->assertSame( $clone, (int) $context['retired'] );
	}

	/** A SEATED duplicate is soft-closed rather than trashed — roster-safe. */
	public function test_seated_duplicate_is_soft_closed_not_trashed() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		$clone = $this->clone_child( $child );
		$this->make_seat( $clone, [ 'status' => Registrations::STATUS_CONFIRMED ] );

		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertNotContains( $clone, $live2 );
		$this->assertSame( 'publish', get_post_status( $clone ), 'A duplicate holding a roster is preserved.' );
		$this->assertSoftClosed( $clone, 'A seated duplicate is soft-closed, exactly like a dropped seated date.' );
		$this->assertSame( 1, $this->count_seats( $clone ), 'The duplicate keeps its roster.' );
		$this->assertNotEmpty( $this->log_entries( 'duplicate_occurrence' ) );
	}

	/**
	 * A published child outranks an unpublished one for the same key, whichever
	 * id is lower — the live occurrence is the one the public already sees, so
	 * it is the one that survives. The loser is retired exactly like any other
	 * duplicate (trashed here, having no seats — recoverable, and logged).
	 */
	public function test_published_child_is_canonical_over_an_unpublished_duplicate() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		$clone = $this->clone_child( $child );          // newer, published
		wp_update_post( [ 'ID' => $child, 'post_status' => 'draft' ] ); // older, drafted

		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertContains( $clone, $live2, 'The published copy is the canonical occurrence.' );
		$this->assertSame( 'publish', get_post_status( $clone ) );
		$this->assertSame( 'trash', get_post_status( $child ), 'The unpublished duplicate is retired like any other.' );

		$entries = $this->log_entries( 'duplicate_occurrence' );
		$this->assertNotEmpty( $entries );
		$this->assertSame( $clone, (int) $entries[0]['context']['kept'] );
		$this->assertSame( $child, (int) $entries[0]['context']['retired'] );
	}

	/** Re-running reconcile after a collapse is quiet: nothing left to report. */
	public function test_duplicate_collapse_is_not_re_reported_on_every_save() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$this->clone_child( (int) $live[0] );

		$this->occurrences()->reconcile( $parent_id );
		$first = count( $this->log_entries( 'duplicate_occurrence' ) );

		$this->occurrences()->reconcile( $parent_id );

		$this->assertSame(
			$first,
			count( $this->log_entries( 'duplicate_occurrence' ) ),
			'An already-retired duplicate must not re-log on every parent save.'
		);
	}

	/**
	 * Regression guard for the trash inclusion the reconcile path depends on:
	 * a removed date's trashed occurrence still comes back — with its id — when
	 * the date returns, rather than acquiring a second post.
	 */
	public function test_trashed_child_is_still_revived_when_its_date_returns() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		$this->drop_first_date( $parent_id );
		$this->occurrences()->reconcile( $parent_id );
		$this->assertSame( 'trash', get_post_status( $child ), 'Precondition: the unseated dropped date is trashed.' );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', $this->two_rows() );
		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertContains( $child, $live2, 'The same occurrence must come back out of the trash.' );
		$this->assertSame( 'publish', get_post_status( $child ) );
		$this->assertCount( 2, $this->all_child_posts( $parent_id ), 'No second post for a revived date.' );
	}
}
