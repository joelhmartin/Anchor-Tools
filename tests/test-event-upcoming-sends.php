<?php
/**
 * Task 3.3 — read-only "upcoming sends" schedule panel.
 *
 * Covers Module::compute_email_schedule() ONLY (the render method is a thin,
 * escaped presentation layer over it — no independent logic to test). Every
 * case here mirrors an input the real sweep (run_reminder_sweep() /
 * maybe_send_scheduled_roster()) reads, so a green suite here is a promise
 * that the panel can never show something the sweep wouldn't actually do.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;

/**
 * @group email
 * @group upcoming-sends
 */
class Test_Event_Upcoming_Sends extends Anchor_Events_TestCase {

	/** @return \Anchor\Events\Occurrences */
	protected function occurrences() {
		return $this->module()->occurrences;
	}

	public function tear_down() {
		delete_option( Module::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * Turns on the lifecycle-email settings under test. Goes through the real
	 * registered `sanitize_option_{OPTION_KEY}` filter (register_setting()'s
	 * sanitize_callback fires on every update_option() call, not just
	 * options.php submissions) so the fixture proves the same shape
	 * get_settings() will hand back at sweep/panel time.
	 *
	 * @param array $overrides
	 */
	protected function configure_settings( array $overrides = [] ) {
		update_option( Module::OPTION_KEY, $overrides, false );
	}

	/* ------------------------------------------------------------------
	 * Reminders
	 * ------------------------------------------------------------------ */

	public function test_reminders_produce_one_scheduled_row_per_offset_with_confirmed_count() {
		$this->configure_settings( [
			'reminder_enabled' => true,
			'reminder_offsets' => '7,1',
		] );

		$start_ts = time() + ( 10 * DAY_IN_SECONDS );
		$event_id = $this->make_event( [ 'start_ts' => $start_ts ] );
		$this->make_seat( $event_id );
		$this->make_seat( $event_id );
		$this->make_seat( $event_id );

		$schedule = $this->module()->compute_email_schedule( $event_id );

		$this->assertSame( '', $schedule['notice'] );
		$reminder_rows = array_values( array_filter( $schedule['rows'], function ( $r ) {
			return $r['type'] === 'reminder';
		} ) );
		$this->assertCount( 2, $reminder_rows );

		// Ordered ascending by scheduled_ts: -7d comes before -1d.
		$this->assertSame( 7, $reminder_rows[0]['offset_days'] );
		$this->assertSame( $start_ts - ( 7 * DAY_IN_SECONDS ), $reminder_rows[0]['scheduled_ts'] );
		$this->assertSame( 1, $reminder_rows[1]['offset_days'] );
		$this->assertSame( $start_ts - ( 1 * DAY_IN_SECONDS ), $reminder_rows[1]['scheduled_ts'] );

		foreach ( $reminder_rows as $row ) {
			$this->assertSame( 3, $row['total_count'] );
			$this->assertSame( 0, $row['sent_count'] );
			$this->assertSame( 'scheduled', $row['state'] );
		}
	}

	public function test_reminder_row_shows_sent_state_once_all_confirmed_seats_carry_the_offset_marker() {
		$this->configure_settings( [
			'reminder_enabled' => true,
			'reminder_offsets' => '7,1',
		] );

		$start_ts = time() + ( 10 * DAY_IN_SECONDS );
		$event_id = $this->make_event( [ 'start_ts' => $start_ts ] );
		$seat_a   = $this->make_seat( $event_id );
		$seat_b   = $this->make_seat( $event_id );

		// Mark the offset=7 marker as sent for both confirmed seats — exactly
		// what run_reminder_sweep() writes after a successful send.
		foreach ( [ $seat_a, $seat_b ] as $seat_id ) {
			update_post_meta( $seat_id, '_anchor_event_reminders_sent', [ 7 => time() ] );
		}

		$schedule = $this->module()->compute_email_schedule( $event_id );
		$rows     = $schedule['rows'];

		$row_7 = current( array_filter( $rows, function ( $r ) { return $r['type'] === 'reminder' && $r['offset_days'] === 7; } ) );
		$row_1 = current( array_filter( $rows, function ( $r ) { return $r['type'] === 'reminder' && $r['offset_days'] === 1; } ) );

		$this->assertSame( 'sent', $row_7['state'] );
		$this->assertSame( 2, $row_7['sent_count'] );
		$this->assertSame( 2, $row_7['total_count'] );

		$this->assertSame( 'scheduled', $row_1['state'] );
		$this->assertSame( 0, $row_1['sent_count'] );
	}

	public function test_reminder_row_shows_partial_state_when_only_some_confirmed_seats_are_marked_sent() {
		$this->configure_settings( [
			'reminder_enabled' => true,
			'reminder_offsets' => '3',
		] );

		$start_ts = time() + ( 5 * DAY_IN_SECONDS );
		$event_id = $this->make_event( [ 'start_ts' => $start_ts ] );
		$sent_seat   = $this->make_seat( $event_id );
		$unsent_seat = $this->make_seat( $event_id );
		update_post_meta( $sent_seat, '_anchor_event_reminders_sent', [ 3 => time() ] );

		$schedule = $this->module()->compute_email_schedule( $event_id );
		$row      = $schedule['rows'][0];

		$this->assertSame( 'partial', $row['state'] );
		$this->assertSame( 1, $row['sent_count'] );
		$this->assertSame( 2, $row['total_count'] );
	}

	public function test_reminder_row_shows_past_not_sent_when_window_elapsed_before_send() {
		$this->configure_settings( [
			'reminder_enabled' => true,
			'reminder_offsets' => '1',
		] );

		// Start is only a few hours out — the -1d offset window is already
		// behind "now" (e.g. reminders were switched on late).
		$start_ts = time() + ( 3 * HOUR_IN_SECONDS );
		$event_id = $this->make_event( [ 'start_ts' => $start_ts ] );
		$this->make_seat( $event_id );

		$schedule = $this->module()->compute_email_schedule( $event_id );
		$row      = $schedule['rows'][0];

		$this->assertSame( 'past', $row['state'] );
		$this->assertSame( 0, $row['sent_count'] );
	}

	public function test_per_event_reminder_offsets_override_wins_over_global() {
		$this->configure_settings( [
			'reminder_enabled' => true,
			'reminder_offsets' => '7,1',
		] );

		$start_ts = time() + ( 10 * DAY_IN_SECONDS );
		$event_id = $this->make_event( [
			'start_ts'         => $start_ts,
			'reminder_offsets' => '2',
		] );

		$schedule = $this->module()->compute_email_schedule( $event_id );
		$reminder_rows = array_values( array_filter( $schedule['rows'], function ( $r ) {
			return $r['type'] === 'reminder';
		} ) );

		$this->assertCount( 1, $reminder_rows );
		$this->assertSame( 2, $reminder_rows[0]['offset_days'] );
	}

	/* ------------------------------------------------------------------
	 * Roster digest
	 * ------------------------------------------------------------------ */

	public function test_roster_digest_row_scheduled_at_start_minus_auto_offset_to_organizer() {
		$this->configure_settings( [
			'organizer_roster_email' => true,
			'roster_auto_offset'     => 2,
			'organizer_email'        => 'organizer@example.test',
		] );

		$start_ts = time() + ( 5 * DAY_IN_SECONDS );
		$event_id = $this->make_event( [ 'start_ts' => $start_ts ] );

		$schedule = $this->module()->compute_email_schedule( $event_id );
		$roster_rows = array_values( array_filter( $schedule['rows'], function ( $r ) {
			return $r['type'] === 'roster';
		} ) );

		$this->assertCount( 1, $roster_rows );
		$this->assertSame( $start_ts - ( 2 * DAY_IN_SECONDS ), $roster_rows[0]['scheduled_ts'] );
		$this->assertSame( 'organizer@example.test', $roster_rows[0]['recipient'] );
		$this->assertSame( 'scheduled', $roster_rows[0]['state'] );
	}

	public function test_roster_digest_row_shows_sent_state_from_roster_sent_marker() {
		$this->configure_settings( [
			'organizer_roster_email' => true,
			'roster_auto_offset'     => 1,
		] );

		$start_ts = time() + ( 5 * DAY_IN_SECONDS );
		$event_id = $this->make_event( [ 'start_ts' => $start_ts ] );
		update_post_meta( $event_id, '_anchor_event_roster_sent', time() );

		$schedule = $this->module()->compute_email_schedule( $event_id );
		$roster_rows = array_values( array_filter( $schedule['rows'], function ( $r ) {
			return $r['type'] === 'roster';
		} ) );

		$this->assertSame( 'sent', $roster_rows[0]['state'] );
	}

	/* ------------------------------------------------------------------
	 * Disabled / empty states
	 * ------------------------------------------------------------------ */

	public function test_reminders_and_roster_both_off_returns_disabled_notice_and_no_rows() {
		$this->configure_settings( [
			'reminder_enabled'       => false,
			'organizer_roster_email' => false,
		] );

		$event_id = $this->make_event( [ 'start_ts' => time() + DAY_IN_SECONDS ] );

		$schedule = $this->module()->compute_email_schedule( $event_id );

		$this->assertSame( 'disabled', $schedule['notice'] );
		$this->assertSame( [], $schedule['rows'] );
	}

	public function test_no_valid_start_ts_returns_no_start_notice() {
		$this->configure_settings( [ 'reminder_enabled' => true ] );

		$event_id = $this->make_event(); // no start_ts override -> defaults to 0.

		$schedule = $this->module()->compute_email_schedule( $event_id );

		$this->assertSame( 'no_start', $schedule['notice'] );
		$this->assertSame( [], $schedule['rows'] );
	}

	/* ------------------------------------------------------------------
	 * Grouped events
	 * ------------------------------------------------------------------ */

	public function test_group_parent_returns_per_date_notice_instead_of_rows() {
		$this->configure_settings( [
			'reminder_enabled' => true,
			'reminder_offsets' => '1',
		] );

		$parent_id = $this->make_event( [
			'title' => 'Workshop Series',
			'type'  => 'offering',
		] );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => gmdate( 'Y-m-d', time() + ( 20 * DAY_IN_SECONDS ) ), 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 10 ],
		] );
		$this->occurrences()->reconcile( $parent_id );

		$this->assertTrue( $this->occurrences()->is_group_parent( $parent_id ) );

		$schedule = $this->module()->compute_email_schedule( $parent_id );

		$this->assertSame( 'group_parent', $schedule['notice'] );
		$this->assertSame( [], $schedule['rows'] );
	}

	public function test_group_child_computes_its_own_schedule_normally() {
		$this->configure_settings( [
			'reminder_enabled' => true,
			'reminder_offsets' => '1',
		] );

		$parent_id = $this->make_event( [
			'title' => 'Workshop Series',
			'type'  => 'offering',
		] );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => gmdate( 'Y-m-d', time() + ( 20 * DAY_IN_SECONDS ) ), 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 10 ],
		] );
		$live = $this->occurrences()->reconcile( $parent_id );
		$this->assertNotEmpty( $live );
		$child_id = (int) $live[0];

		$this->make_seat( $child_id );

		$schedule = $this->module()->compute_email_schedule( $child_id );

		$this->assertSame( '', $schedule['notice'] );
		$reminder_rows = array_values( array_filter( $schedule['rows'], function ( $r ) {
			return $r['type'] === 'reminder';
		} ) );
		$this->assertCount( 1, $reminder_rows );
		$this->assertSame( 1, $reminder_rows[0]['total_count'] );
	}
}
