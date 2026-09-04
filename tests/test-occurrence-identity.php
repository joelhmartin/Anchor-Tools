<?php
/**
 * Occurrence identity + authored label (MODEL-D8, MODEL-D10, MODEL-D27,
 * RENDER-D22).
 *
 * Two facts are pinned here:
 *
 *   1. IDENTITY is the start date AND the start time. The key used to be the
 *      date alone, so two offering rows on one date (a morning and an
 *      afternoon session) collapsed to a single child: the parent kept both
 *      rows, get_offering_dates() dropped the second silently, and its
 *      tier/capacity/end_date simply vanished. Existing children stamped with
 *      the old date-only key must still MATCH (never duplicate), and editing a
 *      row's start time — an editable field, not identity in the authoring UI
 *      — must re-key the child in place rather than retire it and build a new
 *      one beside it.
 *
 *   2. The authored LABEL lives on the child as `_anchor_event_label`. It used
 *      to be recovered by string-slicing the child's post_title against the
 *      parent's title prefix, so renaming the parent (a quick-edit, which
 *      never reaches reconcile()) made every authored label disappear from
 *      "Choose a date" until someone re-saved the parent.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group occurrences
 */
class Test_Occurrence_Identity extends Anchor_Events_TestCase {

	/** @return \Anchor\Events\Occurrences */
	protected function occurrences() {
		return $this->module()->occurrences;
	}

	/**
	 * Group parent carrying the given offering-dates rows, built exactly the
	 * way test-event-grouping-frontend.php builds one.
	 *
	 * @param array $rows
	 * @param array $meta
	 * @return int
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

	/** Call a private/protected Module method (same idiom as Test_Event_Manager_Save). */
	protected function call_module( $method, array $args ) {
		$ref = new ReflectionMethod( Module::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->module(), $args );
	}

	/** The occurrence_key meta of a child. */
	protected function key_of( $child_id ) {
		return (string) get_post_meta( (int) $child_id, '_anchor_event_occurrence_key', true );
	}

	/** The one child of $parent_id carrying $key (fails the test when there isn't exactly one). */
	protected function child_for_key( $parent_id, $key ) {
		$found = [];
		foreach ( $this->occurrences()->children( $parent_id, true ) as $child_id ) {
			if ( $this->key_of( $child_id ) === $key ) {
				$found[] = (int) $child_id;
			}
		}
		$this->assertCount( 1, $found, "Expected exactly one child on {$key}." );
		return $found[0];
	}

	/** Seat post ids booked on an occurrence, ascending. */
	protected function seat_ids( $event_id ) {
		$result = $this->registrations()->query_seats( [ 'event_id' => (int) $event_id, 'status' => 'all' ] );
		$ids    = array_map( function ( $seat ) {
			return (int) $seat['id'];
		}, $result['items'] );
		sort( $ids );
		return $ids;
	}

	/* ------------------------------------------------------------------
	 * 1. Identity = date + start time.
	 * ------------------------------------------------------------------ */

	/** MODEL-D8: two rows on one date, different start times, are two occurrences. */
	public function test_two_rows_same_date_different_times_make_two_children() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-11-07', 'start_time' => '09:00', 'end_time' => '12:00', 'label' => 'Morning', 'capacity' => 10 ],
			[ 'date' => '2027-11-07', 'start_time' => '13:00', 'end_time' => '16:00', 'label' => 'Afternoon', 'capacity' => 20 ],
		] );

		$live = $this->occurrences()->reconcile( $parent_id );

		$this->assertCount( 2, $live, 'One date with two start times is two occurrences, not one.' );

		$keys = array_map( [ $this, 'key_of' ], $live );
		sort( $keys );
		$this->assertSame( [ '2027-11-07|09:00', '2027-11-07|13:00' ], $keys );

		$capacities = array_map( function ( $id ) {
			return (int) get_post_meta( $id, '_anchor_event_capacity', true );
		}, $live );
		sort( $capacities );
		$this->assertSame( [ 10, 20 ], $capacities, "The second row's capacity must not be dropped." );
	}

	/** Reconciling the same two-sessions-a-day parent twice creates nothing new. */
	public function test_same_date_two_times_is_idempotent() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-11-07', 'start_time' => '09:00', 'end_time' => '12:00', 'label' => 'Morning', 'capacity' => 10 ],
			[ 'date' => '2027-11-07', 'start_time' => '13:00', 'end_time' => '16:00', 'label' => 'Afternoon', 'capacity' => 20 ],
		] );

		$first  = $this->occurrences()->reconcile( $parent_id );
		$second = $this->occurrences()->reconcile( $parent_id );

		sort( $first );
		sort( $second );
		$this->assertSame( $first, $second, 'An unchanged desired set must produce no new posts.' );
	}

	/** A child stamped with the OLD date-only key is matched, re-keyed, never duplicated. */
	public function test_legacy_date_only_key_is_matched_not_duplicated() {
		$rows      = [ [ 'date' => '2027-09-14', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 5 ] ];
		$parent_id = $this->make_parent( $rows );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child     = (int) $live[0];

		// Downgrade to the pre-change storage format: the date alone.
		update_post_meta( $child, '_anchor_event_occurrence_key', '2027-09-14' );

		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertSame( [ $child ], $live2, 'A legacy date-only key must match its own child.' );
		$this->assertSame( '2027-09-14|09:00', $this->key_of( $child ), 'The matched child is re-keyed in place.' );
		$this->assertSame( 'publish', get_post_status( $child ) );
	}

	/** Editing a row's start time re-keys the existing child instead of duplicating the date. */
	public function test_editing_a_rows_start_time_rekeys_the_same_child() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-10-05', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 5 ],
		] );
		$live  = $this->occurrences()->reconcile( $parent_id );
		$child = (int) $live[0];

		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-10-05', 'start_time' => '13:00', 'end_time' => '15:00', 'label' => 'Session A', 'capacity' => 5 ],
		] );
		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertSame( [ $child ], $live2, 'Re-timing a date must keep its occurrence (and its roster).' );
		$this->assertSame( '2027-10-05|13:00', $this->key_of( $child ) );
		$this->assertSame( '13:00', get_post_meta( $child, '_anchor_event_start_time', true ) );
		$this->assertCount( 1, $this->occurrences()->children( $parent_id, true ) );
	}

	/**
	 * Two sessions on one day, ONE of them re-timed: the re-timed session keeps
	 * its own post and its own roster, and the untouched session is not
	 * disturbed. One candidate, one claimant — unambiguous.
	 */
	public function test_one_of_two_sessions_retimed_keeps_its_own_child_and_roster() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-10-05', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Morning', 'capacity' => 10 ],
			[ 'date' => '2027-10-05', 'start_time' => '13:00', 'end_time' => '15:00', 'label' => 'Afternoon', 'capacity' => 10 ],
		] );
		$this->occurrences()->reconcile( $parent_id );

		$morning   = $this->child_for_key( $parent_id, '2027-10-05|09:00' );
		$afternoon = $this->child_for_key( $parent_id, '2027-10-05|13:00' );
		$seat_id   = $this->make_seat( $morning );

		// Re-time the morning session only.
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-10-05', 'start_time' => '10:00', 'end_time' => '12:00', 'label' => 'Morning', 'capacity' => 10 ],
			[ 'date' => '2027-10-05', 'start_time' => '13:00', 'end_time' => '15:00', 'label' => 'Afternoon', 'capacity' => 10 ],
		] );
		$live = $this->occurrences()->reconcile( $parent_id );

		sort( $live );
		$expected = [ $morning, $afternoon ];
		sort( $expected );
		$this->assertSame( $expected, $live, 'Both occurrences keep their own posts.' );

		$this->assertSame( '2027-10-05|10:00', $this->key_of( $morning ), 'The re-timed session is re-keyed in place.' );
		$this->assertSame( '10:00', get_post_meta( $morning, '_anchor_event_start_time', true ) );
		$this->assertSame( '2027-10-05|13:00', $this->key_of( $afternoon ), 'The untouched session is left exactly as it was.' );
		$this->assertSame( '13:00', get_post_meta( $afternoon, '_anchor_event_start_time', true ) );

		// The roster stayed with the session it was booked on.
		$this->assertSame( [ $seat_id ], $this->seat_ids( $morning ) );
		$this->assertSame( [], $this->seat_ids( $afternoon ) );
	}

	/**
	 * BOTH sessions on one day re-timed at once is ambiguous: no roster may be
	 * re-pointed at the other session. The seated child is retired
	 * roster-safely (soft-closed, key untouched) and new children are created.
	 */
	public function test_ambiguous_double_retime_never_moves_a_roster() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-10-05', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Morning', 'capacity' => 10 ],
			[ 'date' => '2027-10-05', 'start_time' => '13:00', 'end_time' => '15:00', 'label' => 'Afternoon', 'capacity' => 10 ],
		] );
		$this->occurrences()->reconcile( $parent_id );

		$morning = $this->child_for_key( $parent_id, '2027-10-05|09:00' );
		$seat_id = $this->make_seat( $morning );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-10-05', 'start_time' => '10:00', 'end_time' => '12:00', 'label' => 'Morning', 'capacity' => 10 ],
			[ 'date' => '2027-10-05', 'start_time' => '14:00', 'end_time' => '16:00', 'label' => 'Afternoon', 'capacity' => 10 ],
		] );
		$live = $this->occurrences()->reconcile( $parent_id );

		$this->assertCount( 2, $live );
		$this->assertNotContains( $morning, $live, 'An ambiguous re-key must not adopt the seated child.' );

		// The seated occurrence keeps its own key, its seat and its post.
		$this->assertSame( '2027-10-05|09:00', $this->key_of( $morning ) );
		$this->assertSame( [ $seat_id ], $this->seat_ids( $morning ) );
		$this->assertSame( 'publish', get_post_status( $morning ) );
		$this->assertTrue( $this->occurrences()->is_closed( $morning ), 'A seated, no-longer-offered occurrence is soft-closed.' );

		// Neither new child inherited the roster.
		foreach ( $live as $child_id ) {
			$this->assertSame( [], $this->seat_ids( $child_id ) );
		}
	}

	/**
	 * One candidate but TWO claimants: a 09:00 session re-timed to 10:00 while
	 * an 11:00 session is added the same day. Whichever row ran first would
	 * otherwise take the existing child — and its roster.
	 */
	public function test_retime_plus_added_row_on_one_date_is_ambiguous() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-10-05', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Morning', 'capacity' => 10 ],
		] );
		$original = (int) $this->occurrences()->reconcile( $parent_id )[0];
		$seat_id  = $this->make_seat( $original );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-10-05', 'start_time' => '10:00', 'end_time' => '12:00', 'label' => 'Morning', 'capacity' => 10 ],
			[ 'date' => '2027-10-05', 'start_time' => '11:00', 'end_time' => '13:00', 'label' => 'Late morning', 'capacity' => 10 ],
		] );
		$live = $this->occurrences()->reconcile( $parent_id );

		$this->assertCount( 2, $live );
		$this->assertNotContains( $original, $live, 'Two rows competing for one child is a guess, not a re-key.' );
		$this->assertSame( '2027-10-05|09:00', $this->key_of( $original ) );
		$this->assertSame( [ $seat_id ], $this->seat_ids( $original ) );
		$this->assertTrue( $this->occurrences()->is_closed( $original ) );
	}

	/* ------------------------------------------------------------------
	 * 2. The authored label is the child's own meta.
	 * ------------------------------------------------------------------ */

	/** MODEL-D27 / RENDER-D22: the row's label is written to the child. */
	public function test_row_label_is_written_to_the_child_as_meta() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-08-02', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'October 23-24, 2026', 'capacity' => 5 ],
		] );
		$child = (int) $this->occurrences()->reconcile( $parent_id )[0];

		$this->assertSame( 'October 23-24, 2026', get_post_meta( $child, '_anchor_event_label', true ) );
	}

	/** Editing the row's label re-applies it to the already-materialized child. */
	public function test_editing_a_rows_label_updates_the_childs_label_meta() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-08-02', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Old label', 'capacity' => 5 ],
		] );
		$child = (int) $this->occurrences()->reconcile( $parent_id )[0];

		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-08-02', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'New label', 'capacity' => 5 ],
		] );
		$this->occurrences()->reconcile( $parent_id );

		$this->assertSame( 'New label', get_post_meta( $child, '_anchor_event_label', true ) );
	}

	/** MODEL-D10: renaming the parent's title leaves the authored labels intact. */
	public function test_renaming_the_parent_keeps_the_choose_date_labels() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 5 ],
			[ 'date' => '2027-05-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B', 'capacity' => 5 ],
		] );
		$this->occurrences()->reconcile( $parent_id );

		// A quick-edit rename: save_post fires, save_meta() returns early (no
		// nonce), so reconcile() never runs and the children keep their titles.
		wp_update_post( [ 'ID' => $parent_id, 'post_title' => 'Renamed Workshop' ] );

		$html = $this->module()->render_choose_date_list( $parent_id );

		$this->assertStringContainsString( '<span class="anchor-event-choose-date-label">Session A</span>', $html );
		$this->assertStringContainsString( '<span class="anchor-event-choose-date-label">Session B</span>', $html );
	}

	/** occurrence_label() reads the meta, and only the meta. */
	public function test_occurrence_label_returns_the_meta_label() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 5 ],
		] );
		$child = (int) $this->occurrences()->reconcile( $parent_id )[0];

		$label = $this->call_module( 'occurrence_label', [ $child, $this->module()->get_meta( $child ) ] );
		$this->assertSame( 'Session A', $label );
	}

	/** With no authored label anywhere, the formatted date/time is the fallback. */
	public function test_occurrence_label_falls_back_to_the_formatted_date() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => '', 'capacity' => 5 ],
		] );
		$child = (int) $this->occurrences()->reconcile( $parent_id )[0];

		$meta  = $this->module()->get_meta( $child );
		$label = $this->call_module( 'occurrence_label', [ $child, $meta ] );

		$this->assertSame( '', get_post_meta( $child, '_anchor_event_label', true ), 'An empty row label stores an empty label.' );
		$this->assertSame( $this->call_module( 'format_date_time', [ $meta, true ] ), $label );
	}

	/** An unlabelled occurrence prints its date once, not as date + "label". */
	public function test_unlabelled_row_does_not_print_the_date_twice() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => '', 'capacity' => 5 ],
		] );
		$this->occurrences()->reconcile( $parent_id );

		$html = $this->module()->render_choose_date_list( $parent_id );

		$this->assertStringContainsString( 'anchor-event-choose-date-date', $html );
		$this->assertStringNotContainsString( 'anchor-event-choose-date-label', $html );
	}

	/** A title carrying an old "<parent> — <label>" suffix is NOT a label source. */
	public function test_post_title_suffix_is_no_longer_read_as_a_label() {
		$parent_id = $this->make_parent( [
			[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => '', 'capacity' => 5 ],
		] );
		$child = (int) $this->occurrences()->reconcile( $parent_id )[0];

		wp_update_post( [ 'ID' => $child, 'post_title' => 'Workshop — Sliced From The Title' ] );

		$meta = $this->module()->get_meta( $child );
		$this->assertSame(
			$this->call_module( 'format_date_time', [ $meta, true ] ),
			$this->call_module( 'occurrence_label', [ $child, $meta ] ),
			'The title is presentation; it is never the storage for the authored label.'
		);
	}

	/** Legacy children that predate the label meta are back-filled from the parent's rows. */
	public function test_backfill_writes_missing_labels_from_the_parents_rows() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$parent_id = $this->make_parent( [
			[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 5 ],
		] );
		$child = (int) $this->occurrences()->reconcile( $parent_id )[0];

		// Pre-change state: the label lives only on the parent's row.
		delete_post_meta( $child, '_anchor_event_label' );
		delete_option( 'anchor_events_occurrence_labels_backfilled' );

		$this->module()->backfill_occurrence_labels();

		$this->assertSame( 'Session A', get_post_meta( $child, '_anchor_event_label', true ) );
		$this->assertSame( '1', (string) get_option( 'anchor_events_occurrence_labels_backfilled' ) );

		// Idempotent: a second pass is a no-op and never clobbers a live edit.
		update_post_meta( $child, '_anchor_event_label', 'Hand-edited' );
		$this->module()->backfill_occurrence_labels();
		$this->assertSame( 'Hand-edited', get_post_meta( $child, '_anchor_event_label', true ) );
	}

	/**
	 * The live FACE CODE group, reproduced exactly: parent 7909's three
	 * multi-day rows, children stamped with date-only keys and 08:00 start
	 * times and carrying NO label meta (verified on production 2026-09-03).
	 * The upgrade must keep those three children AND recover their labels.
	 */
	public function test_face_code_shaped_group_keeps_its_children_and_labels() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$rows = [
			[ 'date' => '2026-10-23', 'end_date' => '2026-10-24', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October 23-24, 2026', 'capacity' => 0 ],
			[ 'date' => '2026-11-13', 'end_date' => '2026-11-14', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'November 13-14, 2026', 'capacity' => 0 ],
			[ 'date' => '2026-12-11', 'end_date' => '2026-12-12', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'December 11-12, 2026', 'capacity' => 0 ],
		];
		$parent_id = $this->make_parent( $rows, [ 'title' => 'FACE CODE Masterclass' ] );
		$children  = $this->occurrences()->reconcile( $parent_id );
		$this->assertCount( 3, $children );

		// Wind the children back to the pre-upgrade storage format.
		foreach ( $children as $child_id ) {
			$date = (string) get_post_meta( $child_id, '_anchor_event_start_date', true );
			update_post_meta( $child_id, '_anchor_event_occurrence_key', $date );
			delete_post_meta( $child_id, '_anchor_event_label' );
		}
		delete_option( 'anchor_events_occurrence_labels_backfilled' );

		// The upgrade's own pass, with no parent save anywhere.
		$this->module()->backfill_occurrence_labels();

		$html = $this->module()->render_choose_date_list( $parent_id );
		foreach ( [ 'October 23-24, 2026', 'November 13-14, 2026', 'December 11-12, 2026' ] as $label ) {
			$this->assertStringContainsString( '<span class="anchor-event-choose-date-label">' . $label . '</span>', $html );
		}

		// And the next parent save matches the same three children — it never
		// builds a second set beside them.
		$after = $this->occurrences()->reconcile( $parent_id );
		sort( $children );
		sort( $after );
		$this->assertSame( $children, $after );
		$this->assertCount( 3, $this->occurrences()->children( $parent_id, true ) );
	}

	/**
	 * The back-fill resolves a parent's rows through desired_rows_by_key(), so
	 * a RECURRING parent's children are covered too — reading `offering_dates`
	 * directly would have skipped every one of them. Today an expanded row
	 * carries no label (nothing writes one onto the rule — audit MODEL-D35), so
	 * '' is written; the moment the rule does carry one, this pass restores it.
	 */
	public function test_backfill_covers_recurring_children() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$parent_id = $this->make_event( [ 'title' => 'Weekly Clinic', 'start_date' => '2027-03-01', 'timezone' => 'UTC' ] );
		update_post_meta( $parent_id, '_anchor_event_type', 'recurring' );
		update_post_meta( $parent_id, '_anchor_event_recurrence', [
			'freq' => 'weekly', 'interval' => 1, 'count' => 2,
			'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 5,
		] );

		$children = $this->occurrences()->reconcile( $parent_id );
		$this->assertCount( 2, $children );

		foreach ( $children as $child_id ) {
			$this->assertSame( '', get_post_meta( $child_id, '_anchor_event_label', true ) );
			delete_post_meta( $child_id, '_anchor_event_label' );
		}
		delete_option( 'anchor_events_occurrence_labels_backfilled' );

		$this->module()->backfill_occurrence_labels();

		foreach ( $children as $child_id ) {
			$this->assertSame( '', get_post_meta( $child_id, '_anchor_event_label', true ), 'A recurring occurrence has no authored label to restore.' );
		}
		$this->assertSame( '1', (string) get_option( 'anchor_events_occurrence_labels_backfilled' ), 'The pass must terminate, not leave recurring children in its window.' );

		// And when the rule DOES carry a label (a future MODEL-D35 fix), the
		// same pass recovers it — no second back-fill needed.
		update_post_meta( $parent_id, '_anchor_event_recurrence', [
			'freq' => 'weekly', 'interval' => 1, 'count' => 2, 'label' => 'Weekly clinic',
			'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 5,
		] );
		foreach ( $children as $child_id ) {
			delete_post_meta( $child_id, '_anchor_event_label' );
		}
		delete_option( 'anchor_events_occurrence_labels_backfilled' );

		$this->module()->backfill_occurrence_labels();

		foreach ( $children as $child_id ) {
			$this->assertSame( 'Weekly clinic', get_post_meta( $child_id, '_anchor_event_label', true ) );
		}
	}

	/* ------------------------------------------------------------------
	 * 3. The save path rejects a genuine duplicate row.
	 * ------------------------------------------------------------------ */

	/** A duplicate date+time row is persisted once and reported, not silently dropped on read. */
	public function test_duplicate_offering_row_is_rejected_with_a_notice() {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$event_id = $this->make_event();

		$_POST = [
			Module::NONCE                    => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date'        => '2027-04-01',
			'anchor_event_type'              => 'offering',
			'anchor_event_registration_mode' => 'free',
			'anchor_event_offering_dates'    => [
				[ 'date' => '2027-04-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 10 ],
				[ 'date' => '2027-04-01', 'start_time' => '09:00', 'end_time' => '16:00', 'label' => 'Duplicate', 'capacity' => 99 ],
			],
		];
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_offering_dates', true );
		$this->assertCount( 1, $stored, 'The duplicate key must be rejected at save time, not dropped on read.' );
		$this->assertSame( 'Session A', $stored[0]['label'] );

		$location = apply_filters( 'redirect_post_location', 'http://example.org/wp-admin/post.php?post=' . $event_id );
		$this->assertStringContainsString( 'anchor_event_notice=offering_duplicate_date', $location );

		$this->assertCount( 1, $this->occurrences()->children( $event_id ) );
		unset( $_POST );
	}

	/** Same date, different times, is NOT a duplicate on the save path either. */
	public function test_same_date_different_times_is_not_a_duplicate_row() {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$event_id = $this->make_event();

		$_POST = [
			Module::NONCE                    => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date'        => '2027-04-01',
			'anchor_event_type'              => 'offering',
			'anchor_event_registration_mode' => 'free',
			'anchor_event_offering_dates'    => [
				[ 'date' => '2027-04-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Morning', 'capacity' => 10 ],
				[ 'date' => '2027-04-01', 'start_time' => '13:00', 'end_time' => '16:00', 'label' => 'Afternoon', 'capacity' => 10 ],
			],
		];
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_offering_dates', true );
		$this->assertCount( 2, $stored );

		$location = apply_filters( 'redirect_post_location', 'http://example.org/wp-admin/post.php?post=' . $event_id );
		$this->assertStringNotContainsString( 'offering_duplicate_date', $location );

		$this->assertCount( 2, $this->occurrences()->children( $event_id ) );
		unset( $_POST );
	}
}
