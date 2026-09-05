<?php
/**
 * Occurrences engine tests — parent→child reconcile for Pick-one offerings
 * (spec Phase 2, Task 2.1).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Series;
use Anchor\Events\Registrations;

/**
 * @group occurrences
 */
class Test_Occurrences extends Anchor_Events_TestCase {

	/** @return \Anchor\Events\Occurrences */
	protected function occurrences() {
		return $this->module()->occurrences;
	}

	/**
	 * Create a group-parent event with the given offering-dates rows.
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

	/* ------------------------------------------------------------------
	 * 1. Creation + inheritance
	 * ------------------------------------------------------------------ */

	public function test_reconcile_creates_one_child_per_offering_date_with_inherited_fields() {
		$parent_id = $this->make_parent( $this->two_rows(), [
			'registration_mode' => 'free',
		] );

		$live = $this->occurrences()->reconcile( $parent_id );

		$this->assertCount( 2, $live );
		$this->assertCount( 2, $this->occurrences()->children( $parent_id ) );
		$this->assertTrue( $this->occurrences()->is_group_parent( $parent_id ) );

		foreach ( $live as $child_id ) {
			$this->assertTrue( $this->occurrences()->is_group_child( $child_id ) );
			$this->assertSame( $parent_id, $this->occurrences()->parent_of( $child_id ) );
			$this->assertSame( $parent_id, (int) get_post_meta( $child_id, '_anchor_event_group_id', true ) );

			$meta = $this->module()->get_meta( $child_id );
			$this->assertContains( $meta['start_date'], [ '2027-03-01', '2027-03-08' ] );
			// Identity is the date AND the start time (MODEL-D8) — two sessions
			// on one day are two occurrences, so the date alone cannot be the key.
			$this->assertSame( $meta['start_date'] . '|09:00', get_post_meta( $child_id, '_anchor_event_occurrence_key', true ) );
			$this->assertGreaterThan( 0, $meta['start_ts'] );

			// Inherited shared fields.
			$this->assertSame( 'Main Hall', $meta['venue'] );
			$this->assertSame( 'free', $meta['registration_mode'] );
			$this->assertSame( 'single', $meta['type'] );
		}
	}

	/* ------------------------------------------------------------------
	 * 2. Idempotency
	 * ------------------------------------------------------------------ */

	public function test_reconcile_is_idempotent_with_unchanged_dates() {
		$parent_id = $this->make_parent( $this->two_rows() );

		$first  = $this->occurrences()->reconcile( $parent_id );
		$before = get_posts( [ 'post_type' => Module::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] );

		$second = $this->occurrences()->reconcile( $parent_id );
		$after  = get_posts( [ 'post_type' => Module::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] );

		sort( $first );
		sort( $second );
		$this->assertSame( $first, $second, 'Second reconcile must return the same child ids.' );
		$this->assertSame( count( $before ), count( $after ), 'Second reconcile must not create any new posts.' );
		$this->assertCount( 2, $this->occurrences()->children( $parent_id, true ), 'No closures should have happened.' );
	}

	/* ------------------------------------------------------------------
	 * 3. Remove a date with no seats -> trash
	 * ------------------------------------------------------------------ */

	public function test_removing_unseated_date_trashes_its_child() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$dropped   = $live[0]; // 2027-03-01, no seats.

		update_post_meta( $parent_id, '_anchor_event_offering_dates', [ $this->two_rows()[1] ] );
		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertNotContains( $dropped, $live2 );
		$this->assertSame( 'trash', get_post_status( $dropped ) );
		$this->assertCount( 1, $this->occurrences()->children( $parent_id, true ) );
	}

	/* ------------------------------------------------------------------
	 * 4. Remove a date with seats -> soft-close, roster preserved
	 * ------------------------------------------------------------------ */

	public function test_removing_seated_date_soft_closes_and_preserves_roster() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$seated    = $live[0]; // 2027-03-01

		$seat_id = $this->make_seat( $seated, [ 'name' => 'Jane Roe', 'email' => 'jane@example.test' ] );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', [ $this->two_rows()[1] ] );
		$live2 = $this->occurrences()->reconcile( $parent_id );

		// Excluded from the active set, but the post survives (not trashed).
		$this->assertNotContains( $seated, $live2 );
		$this->assertNotContains( $seated, $this->occurrences()->children( $parent_id, false ) );
		$this->assertContains( $seated, $this->occurrences()->children( $parent_id, true ) );
		$this->assertNotSame( 'trash', get_post_status( $seated ) );

		// Soft-close representation.
		$this->assertSame( 'cancelled', get_post_meta( $seated, '_anchor_event_status', true ) );
		$this->assertSame( 'manual', get_post_meta( $seated, '_anchor_event_status_mode', true ) );
		$this->assertFalse( (bool) get_post_meta( $seated, '_anchor_event_registration_enabled', true ) );
		$this->assertTrue( (bool) get_post_meta( $seated, '_anchor_event_occurrence_closed', true ) );

		// Roster preserved: the seat is still queryable.
		$result = $this->registrations()->query_seats( [ 'event_id' => $seated, 'status' => 'all' ] );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $seat_id, $result['items'][0]['id'] );
	}

	/* ------------------------------------------------------------------
	 * 5. Re-add a soft-closed date -> revive same child, no duplicate
	 * ------------------------------------------------------------------ */

	public function test_readding_soft_closed_date_revives_same_child() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$seated    = $live[0];
		$seat_id   = $this->make_seat( $seated );

		// Drop it -> soft-close.
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [ $this->two_rows()[1] ] );
		$this->occurrences()->reconcile( $parent_id );
		$this->assertTrue( (bool) get_post_meta( $seated, '_anchor_event_occurrence_closed', true ) );

		// Re-add it -> revive, not duplicate.
		update_post_meta( $parent_id, '_anchor_event_offering_dates', $this->two_rows() );
		$live3 = $this->occurrences()->reconcile( $parent_id );

		$this->assertContains( $seated, $live3, 'The revived child must be the SAME post id.' );
		$this->assertCount( 2, $live3 );
		$this->assertFalse( (bool) get_post_meta( $seated, '_anchor_event_occurrence_closed', true ) );
		$this->assertNotSame( 'trash', get_post_status( $seated ) );

		// Historical roster retained.
		$result = $this->registrations()->query_seats( [ 'event_id' => $seated, 'status' => 'all' ] );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $seat_id, $result['items'][0]['id'] );
	}

	/* ------------------------------------------------------------------
	 * 6. Shared field propagation without touching date/seats
	 * ------------------------------------------------------------------ */

	public function test_shared_field_change_propagates_without_touching_date_or_seats() {
		$parent_id = $this->make_parent( $this->two_rows(), [ 'registration_mode' => 'free' ] );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child_id  = $live[0];
		$seat_id   = $this->make_seat( $child_id );

		$before_meta = $this->module()->get_meta( $child_id );

		// Change a shared field on the parent.
		update_post_meta( $parent_id, '_anchor_event_venue', 'Updated Hall' );
		update_post_meta( $parent_id, '_anchor_event_registration_mode', 'free' );
		$this->ticket_types()->save( $parent_id, [
			[ 'label' => 'General', 'price' => '15', 'active' => 1 ],
		] );

		$live2 = $this->occurrences()->reconcile( $parent_id );
		$this->assertContains( $child_id, $live2 );

		$after_meta = $this->module()->get_meta( $child_id );

		// Shared field propagated.
		$this->assertSame( 'Updated Hall', $after_meta['venue'] );
		$child_tiers = $this->ticket_types()->get( $child_id );
		$this->assertSame( 'General', $child_tiers[0]['label'] );

		// Per-occurrence fields + seats untouched.
		$this->assertSame( $before_meta['start_date'], $after_meta['start_date'] );
		$this->assertSame( $before_meta['start_ts'], $after_meta['start_ts'] );
		$this->assertSame( $before_meta['capacity'], $after_meta['capacity'] );
		$result = $this->registrations()->query_seats( [ 'event_id' => $child_id, 'status' => 'all' ] );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $seat_id, $result['items'][0]['id'] );
	}

	/* ------------------------------------------------------------------
	 * 6b. Matched-child editable field propagation (Fix 2.1a)
	 * ------------------------------------------------------------------ */

	public function test_matched_child_editable_fields_propagate_from_parent_row() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$child_id  = $live[0]; // 2027-03-01, Session A, 09:00-11:00, capacity 10.
		$seat_id   = $this->make_seat( $child_id );

		$before_meta = $this->module()->get_meta( $child_id );
		$before_key  = get_post_meta( $child_id, '_anchor_event_occurrence_key', true );

		// Edit that row's start_time/capacity/label in the parent's offering_dates.
		$rows                   = $this->two_rows();
		$rows[0]['start_time']  = '14:00';
		$rows[0]['capacity']    = 25;
		$rows[0]['label']       = 'Session A (Updated)';
		update_post_meta( $parent_id, '_anchor_event_offering_dates', $rows );

		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertContains( $child_id, $live2, 'The SAME child id must be reused (matched, not recreated).' );

		$after_meta = $this->module()->get_meta( $child_id );

		// Non-identity per-occurrence fields propagated from the edited row.
		$this->assertSame( '14:00', $after_meta['start_time'] );
		$this->assertSame( 25, $after_meta['capacity'] );
		$this->assertStringContainsString( 'Session A (Updated)', get_the_title( $child_id ) );
		$this->assertGreaterThan( 0, $after_meta['start_ts'] );
		$this->assertNotSame( $before_meta['start_ts'], $after_meta['start_ts'], 'start_ts must be recomputed from the new start_time.' );

		// Date identity untouched.
		$this->assertSame( $before_meta['start_date'], $after_meta['start_date'] );
		$this->assertSame( '2027-03-01', $after_meta['start_date'] );
		// The DATE identity is untouched; the key follows the row's new start
		// time, because the start time is part of the identity now (MODEL-D8).
		// The child is re-keyed in place — the point of the assertions above is
		// that it is the same post, with the same roster, not a replacement.
		$this->assertSame( '2027-03-01|09:00', $before_key );
		$this->assertSame( '2027-03-01|14:00', get_post_meta( $child_id, '_anchor_event_occurrence_key', true ) );

		// Seat preserved.
		$result = $this->registrations()->query_seats( [ 'event_id' => $child_id, 'status' => 'all' ] );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $seat_id, $result['items'][0]['id'] );
	}

	/* ------------------------------------------------------------------
	 * 7. Series term shared by parent + live children
	 * ------------------------------------------------------------------ */

	public function test_parent_and_live_children_share_one_series_term() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );

		$parent_terms = wp_get_object_terms( $parent_id, Series::TAXONOMY, [ 'fields' => 'ids' ] );
		$this->assertCount( 1, $parent_terms );

		foreach ( $live as $child_id ) {
			$child_terms = wp_get_object_terms( $child_id, Series::TAXONOMY, [ 'fields' => 'ids' ] );
			$this->assertSame( $parent_terms, $child_terms );
		}
	}

	/* ------------------------------------------------------------------
	 * 8. siblings()
	 * ------------------------------------------------------------------ */

	public function test_siblings_returns_other_live_children() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );

		$this->assertCount( 2, $live );
		[ $a, $b ] = $live;

		$this->assertSame( [ $b ], $this->occurrences()->siblings( $a ) );
		$this->assertSame( [ $a ], $this->occurrences()->siblings( $b ) );

		// A non-child post has no siblings.
		$this->assertSame( [], $this->occurrences()->siblings( $parent_id ) );
	}

	/* ------------------------------------------------------------------
	 * 9. Revive respects parent's registration_enabled (Fix 2.1b)
	 * ------------------------------------------------------------------ */

	public function test_revive_respects_parent_registration_enabled_false() {
		$parent_id = $this->make_parent( $this->two_rows(), [ 'registration_enabled' => false ] );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$seated    = $live[0];
		$this->make_seat( $seated );

		// Drop its date -> soft-close (roster-preserving).
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [ $this->two_rows()[1] ] );
		$this->occurrences()->reconcile( $parent_id );
		$this->assertTrue( (bool) get_post_meta( $seated, '_anchor_event_occurrence_closed', true ) );

		// Parent's registration stays disabled throughout.
		update_post_meta( $parent_id, '_anchor_event_registration_enabled', false );

		// Re-add the date -> revive the same child.
		update_post_meta( $parent_id, '_anchor_event_offering_dates', $this->two_rows() );
		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertContains( $seated, $live2, 'The SAME child id must be revived.' );
		$this->assertFalse( (bool) get_post_meta( $seated, '_anchor_event_occurrence_closed', true ) );

		// Read RAW meta (bypassing get_meta()'s '' -> default-true quirk) so a
		// stored `false` isn't masked by the schema default.
		$raw = get_post_meta( $seated, '_anchor_event_registration_enabled', true );
		$this->assertFalse(
			(bool) $raw,
			'revive_if_closed() must not force registration_enabled=true; the parent-synced value (false) must win.'
		);
	}

	/* ------------------------------------------------------------------
	 * Recurrence generator (Phase 2, Task 2.2) — pure expand_recurrence().
	 * ------------------------------------------------------------------ */

	/**
	 * Create a `recurring`-type parent event whose recurrence rule + anchor
	 * (start_date) drive Occurrences::reconcile() via expand_recurrence().
	 *
	 * @param array $rule Recurrence rule.
	 * @param array $meta Additional parent meta overrides (e.g. start_date).
	 * @return int Parent event post id.
	 */
	protected function make_recurring_parent( array $rule, array $meta = [] ) {
		$parent_id = $this->make_event( array_merge( [
			'title'    => 'Weekly Class',
			'venue'    => 'Main Hall',
			'timezone' => 'UTC',
			'type'     => 'recurring',
		], $meta ) );
		update_post_meta( $parent_id, '_anchor_event_recurrence', $rule );
		return $parent_id;
	}

	/**
	 * MODEL-D35 — a generated recurrence row must not be structurally poorer
	 * than a hand-authored offering row: span_days becomes a real end_date,
	 * tier_id and label are copied onto every row (a single rule generates
	 * the whole series, so unlike an offering row, every generated row shares
	 * ONE tier link and ONE label).
	 */
	public function test_expand_recurrence_applies_span_days_tier_id_and_label_to_every_row() {
		$rule = [
			'freq'      => 'weekly',
			'interval'  => 1,
			'count'     => 2,
			'span_days' => 2,
			'tier_id'   => 'general',
			'label'     => 'Weekend Intensive',
		];

		$rows = $this->occurrences()->expand_recurrence( $rule, '2027-03-01' ); // a Monday.

		$this->assertSame( [ '2027-03-01', '2027-03-08' ], \array_column( $rows, 'date' ) );
		foreach ( $rows as $row ) {
			// span_days=2: the occurrence runs date -> date+2.
			$this->assertSame( \date( 'Y-m-d', \strtotime( $row['date'] ) + 2 * DAY_IN_SECONDS ), $row['end_date'] );
			$this->assertSame( 'general', $row['tier_id'] );
			$this->assertSame( 'Weekend Intensive', $row['label'] );
		}
	}

	/**
	 * CodeRabbit finding-7 (PR #20, 2nd round): `$ts + $span_days *
	 * DAY_IN_SECONDS` is fixed-length (86400s/day) arithmetic. A span that
	 * fully contains a DST fall-back (the "gained" hour lands the day AFTER
	 * the transition, not on it) undershoots the next local midnight by
	 * exactly that gained hour once reformatted in the site's own
	 * timezone — landing a calendar day EARLY. 2027-11-07 02:00 America/
	 * Chicago falls back to 01:00 the same morning; a span from 2027-11-06
	 * to +2 days must land on 2027-11-08, not 2027-11-07.
	 *
	 * PHP's own default timezone is UTC in this test run (as it always is
	 * under WordPress), so the OLD `\date()` call — which reads back in
	 * PHP's DEFAULT timezone, not the SITE's — never actually saw this
	 * bug under normal operation; it only surfaces once the arithmetic is
	 * done in a REAL DST-observing zone, which is exactly what a site
	 * configured for America/Chicago (the ordinary case this code exists
	 * for) needs. Setting PHP's runtime default to America/Chicago for
	 * this one test reproduces that: it makes the fixed-seconds defect
	 * concretely observable, and proves the fix is anchored to the SITE's
	 * configured zone (event_timezone()) rather than incidentally correct
	 * only when the runtime default happens to match it.
	 */
	public function test_expand_recurrence_span_days_end_date_survives_a_dst_fall_back() {
		update_option( 'timezone_string', 'America/Chicago' );
		$previous_tz = \date_default_timezone_get();
		\date_default_timezone_set( 'America/Chicago' );

		try {
			$rule = [
				'freq'      => 'weekly',
				'interval'  => 1,
				'count'     => 1,
				'span_days' => 2,
			];
			$rows = $this->occurrences()->expand_recurrence( $rule, '2027-11-06' ); // a Saturday.
		} finally {
			\date_default_timezone_set( $previous_tz );
			delete_option( 'timezone_string' );
		}

		$this->assertSame( '2027-11-06', $rows[0]['date'] );
		$this->assertSame(
			'2027-11-08',
			$rows[0]['end_date'],
			'A 2-day span starting 2027-11-06 must end 2027-11-08 — the DST fall-back on 2027-11-07 must not shift it a day early.'
		);
	}

	/** span_days=0 (the default) means single-day — same convention offering rows use: empty end_date. */
	public function test_expand_recurrence_omits_end_date_when_span_days_is_zero() {
		$rule = [ 'freq' => 'weekly', 'interval' => 1, 'count' => 2 ];

		$rows = $this->occurrences()->expand_recurrence( $rule, '2027-03-01' );

		foreach ( $rows as $row ) {
			$this->assertSame( '', $row['end_date'] );
			$this->assertSame( '', $row['tier_id'] );
		}
	}

	public function test_expand_weekly_interval_1_count_4_yields_consecutive_mondays() {
		$rule = [
			'freq'     => 'weekly',
			'interval' => 1,
			'count'    => 4,
			'start_time' => '09:00',
			'end_time'   => '10:00',
			'capacity'   => 5,
		];

		$rows = $this->occurrences()->expand_recurrence( $rule, '2027-03-01' ); // a Monday.

		$this->assertSame(
			[ '2027-03-01', '2027-03-08', '2027-03-15', '2027-03-22' ],
			array_column( $rows, 'date' )
		);
		foreach ( $rows as $row ) {
			$this->assertSame( '09:00', $row['start_time'] );
			$this->assertSame( '10:00', $row['end_time'] );
			$this->assertSame( 5, $row['capacity'] );
		}
	}

	public function test_expand_weekly_interval_2_count_3_yields_biweekly_dates() {
		$rule = [ 'freq' => 'weekly', 'interval' => 2, 'count' => 3 ];

		$rows  = $this->occurrences()->expand_recurrence( $rule, '2027-03-01' );
		$dates = array_column( $rows, 'date' );

		$this->assertSame( [ '2027-03-01', '2027-03-15', '2027-03-29' ], $dates );
		$this->assertSame( 14, ( strtotime( $dates[1] ) - strtotime( $dates[0] ) ) / DAY_IN_SECONDS );
		$this->assertSame( 14, ( strtotime( $dates[2] ) - strtotime( $dates[1] ) ) / DAY_IN_SECONDS );
	}

	public function test_expand_weekly_multi_weekday_orders_dates_across_weeks() {
		$rule = [
			'freq'     => 'weekly',
			'interval' => 1,
			'weekdays' => [ 1, 3 ], // Mon, Wed.
			'count'    => 4,
		];

		$rows = $this->occurrences()->expand_recurrence( $rule, '2027-03-01' ); // a Monday.

		$this->assertSame(
			[ '2027-03-01', '2027-03-03', '2027-03-08', '2027-03-10' ],
			array_column( $rows, 'date' )
		);
	}

	public function test_expand_monthly_interval_1_count_3_same_day_of_month() {
		$rule = [ 'freq' => 'monthly', 'interval' => 1, 'count' => 3 ];

		$rows = $this->occurrences()->expand_recurrence( $rule, '2027-03-15' );

		$this->assertSame(
			[ '2027-03-15', '2027-04-15', '2027-05-15' ],
			array_column( $rows, 'date' )
		);
	}

	public function test_expand_monthly_day_31_skips_short_months_without_rolling_over() {
		// April 2027 has 30 days, June 2027 has 30 days — both must be
		// skipped entirely rather than rolling the occurrence to the 1st (or
		// last day) of the short month.
		$rule = [ 'freq' => 'monthly', 'interval' => 1, 'count' => 3 ];

		$rows = $this->occurrences()->expand_recurrence( $rule, '2027-03-31' );

		$this->assertSame(
			[ '2027-03-31', '2027-05-31', '2027-07-31' ],
			array_column( $rows, 'date' )
		);
	}

	public function test_expand_weekly_until_stops_inclusive() {
		$rule = [ 'freq' => 'weekly', 'interval' => 1, 'until' => '2027-03-22' ];

		$rows = $this->occurrences()->expand_recurrence( $rule, '2027-03-01' );

		$this->assertSame(
			[ '2027-03-01', '2027-03-08', '2027-03-15', '2027-03-22' ],
			array_column( $rows, 'date' )
		);
	}

	public function test_expand_safety_cap_never_exceeds_hard_max() {
		$rule = [ 'freq' => 'weekly', 'interval' => 1, 'count' => 10000 ];
		$rows = $this->occurrences()->expand_recurrence( $rule, '2027-01-04' );
		$this->assertLessThanOrEqual( 104, count( $rows ) );
		$this->assertSame( 104, count( $rows ), 'An unbounded request must stop exactly at the documented cap.' );

		// No terminator at all (neither count nor until) must also be capped,
		// not loop unbounded.
		$rule_no_terminator = [ 'freq' => 'weekly', 'interval' => 1 ];
		$rows2 = $this->occurrences()->expand_recurrence( $rule_no_terminator, '2027-01-04' );
		$this->assertSame( 104, count( $rows2 ) );
	}

	public function test_expand_recurrence_is_deterministic() {
		$rule = [ 'freq' => 'weekly', 'interval' => 1, 'count' => 6, 'weekdays' => [ 2, 5 ] ];

		$first  = $this->occurrences()->expand_recurrence( $rule, '2027-03-01' );
		$second = $this->occurrences()->expand_recurrence( $rule, '2027-03-01' );

		$this->assertSame( $first, $second );
	}

	/* ------------------------------------------------------------------
	 * Recurrence wiring into reconcile() — same guarantees as offerings.
	 * ------------------------------------------------------------------ */

	public function test_recurring_parent_reconciles_via_expand_recurrence() {
		$rule = [
			'freq'       => 'weekly',
			'interval'   => 1,
			'count'      => 4,
			'start_time' => '09:00',
			'end_time'   => '10:00',
			'capacity'   => 8,
		];
		$parent_id = $this->make_recurring_parent( $rule, [ 'start_date' => '2027-03-01' ] );

		$live = $this->occurrences()->reconcile( $parent_id );

		$this->assertCount( 4, $live );
		$this->assertCount( 4, $this->occurrences()->children( $parent_id ) );

		$dates = [];
		foreach ( $live as $child_id ) {
			$meta     = $this->module()->get_meta( $child_id );
			$dates[]  = $meta['start_date'];
			$this->assertSame( 'single', $meta['type'] );
			$this->assertSame( 8, $meta['capacity'] );
		}
		sort( $dates );
		$this->assertSame( [ '2027-03-01', '2027-03-08', '2027-03-15', '2027-03-22' ], $dates );

		// Idempotent: a second reconcile creates no new children.
		$live2 = $this->occurrences()->reconcile( $parent_id );
		sort( $live );
		sort( $live2 );
		$this->assertSame( $live, $live2 );
		$this->assertCount( 4, $this->occurrences()->children( $parent_id, true ) );
	}

	public function test_recurring_parent_reduce_count_soft_closes_seated_child_and_trashes_unseated() {
		$rule = [ 'freq' => 'weekly', 'interval' => 1, 'count' => 4 ];
		$parent_id = $this->make_recurring_parent( $rule, [ 'start_date' => '2027-03-01' ] );

		$live = $this->occurrences()->reconcile( $parent_id );
		$this->assertCount( 4, $live );

		usort( $live, function ( $a, $b ) {
			return get_post_meta( $a, '_anchor_event_start_date', true ) <=> get_post_meta( $b, '_anchor_event_start_date', true );
		} );
		[ $keep1, $keep2, $drop_seated, $drop_unseated ] = $live;

		// Give one of the soon-to-be-dropped children a seat.
		$seat_id = $this->make_seat( $drop_seated );

		// Reduce the rule's count to 2 -> only the first two Mondays remain desired.
		update_post_meta( $parent_id, '_anchor_event_recurrence', [ 'freq' => 'weekly', 'interval' => 1, 'count' => 2 ] );
		$live2 = $this->occurrences()->reconcile( $parent_id );

		$this->assertCount( 2, $live2 );
		$this->assertContains( $keep1, $live2 );
		$this->assertContains( $keep2, $live2 );
		$this->assertNotContains( $drop_seated, $live2 );
		$this->assertNotContains( $drop_unseated, $live2 );

		// Unseated dropped child -> trashed.
		$this->assertSame( 'trash', get_post_status( $drop_unseated ) );

		// Seated dropped child -> soft-closed, roster preserved (same Task 2.1 guarantee).
		$this->assertNotSame( 'trash', get_post_status( $drop_seated ) );
		$this->assertTrue( (bool) get_post_meta( $drop_seated, '_anchor_event_occurrence_closed', true ) );
		$this->assertSame( 'cancelled', get_post_meta( $drop_seated, '_anchor_event_status', true ) );
		$result = $this->registrations()->query_seats( [ 'event_id' => $drop_seated, 'status' => 'all' ] );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $seat_id, $result['items'][0]['id'] );
	}

	/* ------------------------------------------------------------------
	 * Trashing the group PARENT retires its children (spec Phase 2, Task
	 * 2.3 FIX 2). wp_trash_post() fires NO save_post hook, so without a
	 * dedicated wp_trash_post handler a trashed parent's children would be
	 * left live, published, and bookable while pointing at a trashed
	 * parent. Module::retire_children_on_parent_trash() (hooked on the
	 * generic `wp_trash_post` action) is exercised here via the real
	 * wp_trash_post() call — not by invoking Occurrences::retire_all_children()
	 * directly — so the hook wiring itself is under test, not just the
	 * engine method.
	 * ------------------------------------------------------------------ */

	/** Trashing a group parent soft-closes a seated child (roster preserved) and trashes an unseated one. */
	public function test_trashing_group_parent_retires_children_roster_safely() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		[ $seated, $unseated ] = $live;

		$seat_id = $this->make_seat( $seated, [ 'name' => 'Jane Roe', 'email' => 'jane@example.test' ] );

		wp_trash_post( $parent_id );

		$this->assertSame( 'trash', get_post_status( $parent_id ) );

		// Seated child: soft-closed, NOT trashed, roster still queryable.
		$this->assertNotSame( 'trash', get_post_status( $seated ), 'A seated child must be soft-closed, not trashed, when its parent is trashed.' );
		$this->assertTrue( (bool) get_post_meta( $seated, '_anchor_event_occurrence_closed', true ) );
		$this->assertSame( 'cancelled', get_post_meta( $seated, '_anchor_event_status', true ) );
		$this->assertFalse( (bool) get_post_meta( $seated, '_anchor_event_registration_enabled', true ) );
		$result = $this->registrations()->query_seats( [ 'event_id' => $seated, 'status' => 'all' ] );
		$this->assertSame( 1, $result['total'], 'The seat must still be queryable — roster preserved.' );
		$this->assertSame( $seat_id, $result['items'][0]['id'] );

		// Unseated child: trashed.
		$this->assertSame( 'trash', get_post_status( $unseated ), 'An unseated child must be trashed when its parent is trashed.' );
	}

	/**
	 * No infinite recursion: retire_all_children() trashes the unseated
	 * child via wp_trash_post(), which re-fires the SAME `wp_trash_post`
	 * action for that child post id. Proven by asserting the total event
	 * post count is unchanged (trashing only flips post_status on EXISTING
	 * posts, it never creates new ones) and that the handler completes
	 * synchronously without hitting PHP's call-stack/time limits.
	 */
	public function test_trashing_group_parent_does_not_recurse() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$this->make_seat( $live[0] ); // one seated, one unseated child.

		// NOTE: post_status='any' deliberately EXCLUDES 'trash' (core behavior),
		// so both counts here explicitly include it — the point is proving no
		// NEW posts appear, trashed or not.
		$before = count( get_posts( [
			'post_type'      => Module::CPT,
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] ) );

		wp_trash_post( $parent_id );

		$after = get_posts( [
			'post_type'      => Module::CPT,
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$this->assertCount( $before, $after, 'Trashing the parent must only change post_status on existing posts — a recursive retire would not create new posts either, but would loop/hang instead of completing.' );
	}

	/** Trashing an ordinary event that was never a group parent has nothing to retire (no-op, no error). */
	public function test_trashing_ordinary_event_is_a_retirement_noop() {
		$event_id = $this->make_event();

		wp_trash_post( $event_id );

		$this->assertSame( 'trash', get_post_status( $event_id ) );
	}

	/** Trashing a group CHILD directly (not its parent) must not touch any sibling. */
	public function test_trashing_a_child_directly_does_not_retire_siblings() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		[ $child_a, $child_b ] = $live;

		wp_trash_post( $child_a );

		$this->assertSame( 'trash', get_post_status( $child_a ) );
		$this->assertNotSame( 'trash', get_post_status( $child_b ), 'A child is never itself a group parent, so trashing it must not retire its sibling.' );
	}

	/* ------------------------------------------------------------------
	 * 9. MODEL-D34 — closed_children() / delete_closed_occurrence(): a
	 *    reachable terminal state for a soft-closed, now-seatless child.
	 * ------------------------------------------------------------------ */

	/** A soft-closed child (seats since removed) is found; a live one is not. */
	public function test_closed_children_finds_soft_closed_occurrences_across_parents() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$seated    = $live[0];
		$this->make_seat( $seated );

		// Drop the seated date -> soft-close (roster preserved).
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [ $this->two_rows()[1] ] );
		$this->occurrences()->reconcile( $parent_id );
		$this->assertTrue( $this->occurrences()->is_closed( $seated ) );

		$closed = $this->occurrences()->closed_children();

		$this->assertContains( $seated, $closed );
		// The still-live sibling must not appear.
		$live_sibling = $this->occurrences()->children( $parent_id );
		foreach ( $live_sibling as $id ) {
			$this->assertNotContains( $id, $closed );
		}
	}

	/** A plain (never-grouped) event carries no occurrence_closed row and is never listed. */
	public function test_closed_children_excludes_ordinary_events() {
		$this->make_event();
		$this->assertSame( [], $this->occurrences()->closed_children() );
	}

	/**
	 * MODEL-D34 fix round 1 — delete_closed_occurrence() REFUSES a closed
	 * occurrence that still holds an ACTIVE seat (confirmed, here). The
	 * whole reason retire_child() preserved this post instead of trashing it
	 * is a roster somebody still has to see/email/refund; deleting it out
	 * from under that roster is exactly the "surprise-trashing" it was
	 * written to avoid, just one click later.
	 */
	public function test_delete_closed_occurrence_refuses_when_active_seats_remain() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$seated    = $live[0];
		$this->make_seat( $seated ); // confirmed by default — an ACTIVE seat.

		update_post_meta( $parent_id, '_anchor_event_offering_dates', [ $this->two_rows()[1] ] );
		$this->occurrences()->reconcile( $parent_id );
		$this->assertTrue( $this->occurrences()->is_closed( $seated ) );

		$result = $this->module()->delete_closed_occurrence( $seated );

		$this->assertFalse( $result['deleted'] );
		$this->assertSame( 1, $result['active_seats'] );
		$this->assertNotSame( 'trash', get_post_status( $seated ), 'A closed occurrence with an active seat must not be trashed.' );
	}

	/**
	 * Pending and waitlist seats block deletion exactly like confirmed —
	 * ACTIVE means Registrations::RESERVING_STATUSES + WAITLIST_STATUSES,
	 * not just `confirmed`.
	 */
	public function test_delete_closed_occurrence_refuses_when_only_a_waitlist_seat_remains() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$seated    = $live[0];
		$this->make_seat( $seated, [ 'status' => \Anchor\Events\Registrations::STATUS_WAITLIST ] );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', [ $this->two_rows()[1] ] );
		$this->occurrences()->reconcile( $parent_id );

		$result = $this->module()->delete_closed_occurrence( $seated );

		$this->assertFalse( $result['deleted'] );
		$this->assertSame( 1, $result['active_seats'] );
	}

	/**
	 * Zero active seats — none at all — permits deletion. A cancelled/
	 * refunded/failed seat is history, not a live roster, so it must not
	 * count toward the refusal either.
	 */
	public function test_delete_closed_occurrence_permits_deletion_with_zero_active_seats() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$seated    = $live[0];
		$this->make_seat( $seated, [ 'status' => \Anchor\Events\Registrations::STATUS_CANCELLED ] );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', [ $this->two_rows()[1] ] );
		$this->occurrences()->reconcile( $parent_id );
		$this->assertTrue( $this->occurrences()->is_closed( $seated ) );

		$result = $this->module()->delete_closed_occurrence( $seated );

		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 0, $result['active_seats'] );
		$this->assertSame( 'trash', get_post_status( $seated ) );
	}

	/**
	 * The object check: delete_closed_occurrence() refuses an event that is
	 * not currently closed — this is not a generic "delete any event" path.
	 */
	public function test_delete_closed_occurrence_refuses_a_live_event() {
		$event_id = $this->make_event();

		$result = $this->module()->delete_closed_occurrence( $event_id );

		$this->assertFalse( $result['deleted'] );
		$this->assertSame( 0, $result['active_seats'] );
		$this->assertNotSame( 'trash', get_post_status( $event_id ) );
	}

	/** And refuses a non-event post id / a post that doesn't exist. */
	public function test_delete_closed_occurrence_refuses_a_non_event_id() {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->assertFalse( $this->module()->delete_closed_occurrence( $page_id )['deleted'] );
		$this->assertFalse( $this->module()->delete_closed_occurrence( 0 )['deleted'] );
		$this->assertFalse( $this->module()->delete_closed_occurrence( PHP_INT_MAX )['deleted'] );
	}

	/**
	 * handle_delete_closed_occurrence() is the real admin-post entry point;
	 * order is nonce -> capability -> object (delete_closed_occurrence()'s
	 * own is_closed() check). wp_die() is intercepted by WP's test suite as
	 * WPDieException instead of terminating the process, so — like
	 * handle_event_manager_save()'s nonce test — both gates ARE directly
	 * testable even though the success path's exit() is not.
	 */
	public function test_handle_delete_closed_occurrence_dies_on_invalid_nonce() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST    = [ 'event_id' => 1, '_wpnonce' => 'invalid-nonce' ];
		// CodeRabbit finding-11 (PR #20, 2nd round): check_admin_referer()
		// reads $_REQUEST, not $_POST — a manually assigned $_POST in a test
		// does not also populate $_REQUEST, so without this the nonce check
		// could be seeing a stale/empty $_REQUEST['_wpnonce'] regardless of
		// what $_POST actually holds. Harmless here either way (the nonce is
		// deliberately invalid) but kept consistent with the capability test
		// below, where it matters.
		$_REQUEST = $_POST;

		$this->expectException( WPDieException::class );
		$this->module()->handle_delete_closed_occurrence();
	}

	/** A valid nonce is not enough — the module capability gate still applies. */
	public function test_handle_delete_closed_occurrence_dies_when_user_lacks_capability() {
		$parent_id = $this->make_parent( $this->two_rows() );
		$live      = $this->occurrences()->reconcile( $parent_id );
		$seated    = $live[0];
		$this->make_seat( $seated );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [ $this->two_rows()[1] ] );
		$this->occurrences()->reconcile( $parent_id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST = [
			'event_id' => $seated,
			'_wpnonce' => wp_create_nonce( 'anchor_events_delete_closed_occurrence_' . $seated ),
		];
		// CodeRabbit finding-11 (PR #20, 2nd round): check_admin_referer()
		// reads $_REQUEST['_wpnonce'], not $_POST. Without also setting
		// $_REQUEST here, the nonce check itself fails FIRST — the test
		// still gets a WPDieException and still passes, but for the wrong
		// reason, never actually reaching (or proving) the capability gate
		// it claims to test. Setting $_REQUEST lets the nonce pass, and
		// asserting the die MESSAGE (not just the exception class) is what
		// actually distinguishes "refused by capability" from "refused by
		// nonce" — both throw the identical WPDieException class.
		$_REQUEST = $_POST;

		try {
			$this->module()->handle_delete_closed_occurrence();
			$this->fail( 'handle_delete_closed_occurrence() did not die for a user lacking the capability.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'You are not allowed to do this.', $e->getMessage() );
		}
	}
}
