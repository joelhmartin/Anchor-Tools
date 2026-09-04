<?php
/**
 * Lifecycle-email delivery state: what the hourly sweep actually sends, and
 * what it remembers about having sent it.
 *
 * Audit entries closed here:
 *   REG-D3   — the sweep sent EVERY offset whose window had opened rather than
 *              the one that just came due, so a registration made 12 hours
 *              before a course with offsets "7,1" (the production value) got
 *              both reminders back to back in the same minute.
 *   MODEL-D16 / REG-D42 — `_anchor_event_reminders_sent` and
 *              `_anchor_event_roster_sent` were one-way latches keyed to
 *              nothing, so a rescheduled event reminded nobody about its new
 *              date and its organizer never got a digest for the date they
 *              actually needed.
 *   REG-D4   — `_anchor_event_cancel_emailed` was a write-once boolean with no
 *              clear path, so a seat cancelled → restored → cancelled again
 *              never told the attendee, and send_cancellation_email() returned
 *              TRUE for a mail it had not sent.
 *   REG-D5   — a cancellation whose wp_mail() returned false was dropped: the
 *              queue was already empty, the marker was unwritten, and only a
 *              fresh live→terminal transition could ever re-enqueue it.
 *
 * Every case drives the public surface (run_reminder_sweep(),
 * Registrations::update_status(), flush_cancellation_emails()) and asserts on
 * mail that actually left, so a green suite here is a promise about delivery,
 * not about internal bookkeeping.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Events_Log;
use Anchor\Events\Module;
use Anchor\Events\Registrations;

/**
 * @group email
 * @group reminders
 */
class Test_Reminders extends Anchor_Events_TestCase {

	public function tear_down() {
		delete_option( Module::OPTION_KEY );
		delete_option( Events_Log::ERROR_OPTION );
		parent::tear_down();
	}

	/* ------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/** Merge sweep settings over the resolved defaults (same shape get_settings() returns). */
	private function configure( array $overrides ) {
		update_option( Module::OPTION_KEY, array_merge(
			$this->module()->get_settings(),
			$overrides
		), false );
	}

	/**
	 * A published, live, standalone event whose start_ts is $start_ts.
	 *
	 * @param int    $start_ts
	 * @param string $title
	 * @return int Event post id.
	 */
	private function future_event( $start_ts, $title = 'Reminder course' ) {
		$event_id = $this->make_event( [
			'title'      => $title,
			'timezone'   => 'UTC',
			'start_date' => gmdate( 'Y-m-d', $start_ts ),
			'start_time' => gmdate( 'H:i', $start_ts ),
		] );
		$this->reschedule( $event_id, $start_ts );
		return $event_id;
	}

	/** Move an event to a new start_ts the way any save path would. */
	private function reschedule( $event_id, $start_ts ) {
		update_post_meta( $event_id, '_anchor_event_start_ts', (int) $start_ts );
	}

	/** Capture every wp_mail() recipient without sending. Returns nothing; fills $sent. */
	private function capture_mail( array &$sent ) {
		add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$sent ) {
			$sent[] = $atts['to'];
			return true;
		}, 10, 2 );
	}

	/** Make every wp_mail() report failure, exactly as an SMTP blip does. */
	private function fail_mail() {
		$fail = function () {
			return false;
		};
		add_filter( 'pre_wp_mail', $fail, 10, 2 );
		return $fail;
	}

	/** The seat's reminder markers for one start_ts ([] when nothing was ever written). */
	private function markers( $seat_id, $start_ts ) {
		$map = get_post_meta( $seat_id, '_anchor_event_reminders_sent', true );
		if ( '' === $map ) {
			return [];
		}
		$this->assertIsArray( $map, 'Reminder markers must be stored as an array.' );
		return isset( $map[ (int) $start_ts ] ) ? $map[ (int) $start_ts ] : [];
	}

	/** Pretend an hour has passed so a queued retry is due. */
	private function make_retry_due( $seat_id ) {
		$job = get_post_meta( $seat_id, '_anchor_event_email_retry', true );
		$this->assertIsArray( $job, 'Expected a queued retry job on seat ' . $seat_id . '.' );
		$job['next_at'] = time() - 1;
		update_post_meta( $seat_id, '_anchor_event_email_retry', $job );
	}

	/** How many error-log rows carry this code. */
	private function log_count( $code ) {
		$log = get_option( Events_Log::ERROR_OPTION, [] );
		$n   = 0;
		foreach ( is_array( $log ) ? $log : [] as $row ) {
			if ( ( $row['code'] ?? '' ) === $code ) {
				$n++;
			}
		}
		return $n;
	}

	/* ------------------------------------------------------------------
	 * REG-D3 — one reminder per seat per sweep
	 * ------------------------------------------------------------------ */

	public function test_late_registration_gets_only_the_nearest_due_reminder() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '7,1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 12 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'late@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );

		$this->module()->run_reminder_sweep();

		$this->assertSame( [ 'late@example.com' ], $sent, 'Both windows are open, but only the 1-day reminder is the one that just came due.' );

		$markers = $this->markers( $seat_id, $start_ts );
		$this->assertGreaterThan( 0, (int) ( $markers[1] ?? 0 ), 'The 1-day offset is marked with the time it was sent.' );
		$this->assertArrayHasKey( 7, $markers, 'The superseded 7-day offset is marked so it can never fire late.' );
		$this->assertSame( 0, (int) $markers[7], 'A superseded offset is marked with 0 — recorded, but never reported as sent.' );
	}

	/**
	 * REG-D29 — a delivered reminder records itself in the reminders_sent map
	 * and nowhere else. The old _anchor_event_attendee_notified flag was
	 * written here and read by nothing, under a name that claimed the
	 * confirmation had gone out.
	 */
	public function test_a_delivered_reminder_writes_no_dead_attendee_notified_flag() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 12 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'flagged@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );
		$this->module()->run_reminder_sweep();

		$this->assertSame( [ 'flagged@example.com' ], $sent );
		$this->assertGreaterThan( 0, (int) ( $this->markers( $seat_id, $start_ts )[1] ?? 0 ) );
		$this->assertSame( [], get_post_meta( $seat_id, '_anchor_event_attendee_notified' ) );
	}

	/* ------------------------------------------------------------------
	 * REG-D36 — the hourly sweep's scan is bounded
	 * ------------------------------------------------------------------ */

	/**
	 * The per-event override scan used to be "every event that has ever set
	 * reminder_offsets", with no date bound, run every hour. One archived
	 * course with a 365-day offset widened the main scan to the whole next
	 * year of the calendar.
	 */
	public function test_the_override_scan_only_asks_about_events_still_ahead() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '7,1', 'organizer_roster_email' => false ] );

		$past = $this->future_event( time() - 30 * DAY_IN_SECONDS, 'Archived course' );
		update_post_meta( $past, '_anchor_event_reminder_offsets', '365' );

		// get_posts() suppresses the posts_* filters, so read the query vars
		// the sweep actually asks for.
		$meta_queries = [];
		$spy          = function ( $q ) use ( &$meta_queries ) {
			$mq = $q->get( 'meta_query' );
			if ( is_array( $mq ) ) {
				$meta_queries[] = $mq;
			}
		};
		add_action( 'pre_get_posts', $spy );
		try {
			$this->module()->run_reminder_sweep();
		} finally {
			remove_action( 'pre_get_posts', $spy );
		}

		$keys_of = function ( array $mq ) {
			$keys = [];
			array_walk_recursive( $mq, function ( $v, $k ) use ( &$keys ) {
				if ( 'key' === $k ) {
					$keys[] = $v;
				}
			} );
			return $keys;
		};

		$override_scans = 0;
		foreach ( $meta_queries as $mq ) {
			$keys = $keys_of( $mq );
			if ( ! in_array( '_anchor_event_reminder_offsets', $keys, true ) ) {
				continue;
			}
			$override_scans++;
			$this->assertContains(
				'_anchor_event_start_ts',
				$keys,
				'The override scan must be bounded by the event start, not run over every event that ever had one.'
			);
		}

		$this->assertSame( 1, $override_scans, 'The sweep still folds in per-event offsets, exactly once.' );
	}

	/**
	 * finding-7 — the override scan (above) carried no_found_rows but no row
	 * ceiling, so a site with a very large number of in-window overrides ran
	 * a fully unbounded query every hour. A hit ceiling must be logged once
	 * per sweep, not once per event, via the reminder_scan_truncated code.
	 */
	public function test_a_hit_scan_ceiling_is_logged_once_per_sweep() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '7,1', 'organizer_roster_email' => false ] );

		$first  = $this->future_event( time() + 5 * DAY_IN_SECONDS, 'Override A' );
		$second = $this->future_event( time() + 6 * DAY_IN_SECONDS, 'Override B' );
		update_post_meta( $first, '_anchor_event_reminder_offsets', '3' );
		update_post_meta( $second, '_anchor_event_reminder_offsets', '3' );

		$cap = static function () {
			return 1; // Smaller than the two matching events above.
		};
		add_filter( 'anchor_events_reminder_override_scan_limit', $cap );
		try {
			$this->module()->run_reminder_sweep();
		} finally {
			remove_filter( 'anchor_events_reminder_override_scan_limit', $cap );
		}

		$log   = get_option( Events_Log::ERROR_OPTION, [] );
		$codes = is_array( $log ) ? array_column( $log, 'code' ) : [];
		$this->assertSame( 1, count( array_keys( $codes, 'reminder_scan_truncated', true ) ), 'Exactly one truncation entry per sweep.' );
	}

	/** A scan that never hits the ceiling logs nothing. */
	public function test_a_scan_under_the_ceiling_logs_no_truncation() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '7,1', 'organizer_roster_email' => false ] );

		$event_id = $this->future_event( time() + 5 * DAY_IN_SECONDS, 'Override A' );
		update_post_meta( $event_id, '_anchor_event_reminder_offsets', '3' );

		$this->module()->run_reminder_sweep();

		$log   = get_option( Events_Log::ERROR_OPTION, [] );
		$codes = is_array( $log ) ? array_column( $log, 'code' ) : [];
		$this->assertNotContains( 'reminder_scan_truncated', $codes );
	}

	/**
	 * ...and an offset outside the horizon is not stored in the first place,
	 * so what an author sees saved is what the sweep will honour rather than a
	 * number that silently means "no reminder".
	 */
	public function test_an_offset_outside_the_horizon_is_not_stored() {
		$too_far = Module::REMINDER_HORIZON_DAYS + 1;

		$saved = $this->module()->sanitize_settings( [ 'reminder_offsets' => '7,1,' . $too_far ] );

		$this->assertSame( '7,1', $saved['reminder_offsets'] );
		$this->assertStringNotContainsString( (string) $too_far, $saved['reminder_offsets'] );
		// The boundary itself is legal.
		$this->assertStringContainsString(
			(string) Module::REMINDER_HORIZON_DAYS,
			$this->module()->sanitize_settings( [ 'reminder_offsets' => (string) Module::REMINDER_HORIZON_DAYS ] )['reminder_offsets']
		);
	}

	/** No offset, however large, makes the sweep look further ahead than the cap. */
	public function test_an_absurd_offset_cannot_widen_the_scan_past_the_horizon() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '7,1', 'organizer_roster_email' => false ] );

		$days     = Module::REMINDER_HORIZON_DAYS + 14;
		$start_ts = time() + $days * DAY_IN_SECONDS;
		$event_id = $this->future_event( $start_ts, 'Very distant course' );
		update_post_meta( $event_id, '_anchor_event_reminder_offsets', (string) ( $days + 20 ) );
		$this->make_seat( $event_id, [ 'email' => 'distant@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );
		$this->module()->run_reminder_sweep();

		$this->assertSame( [], $sent, 'An event beyond the horizon is not scanned at all.' );
	}

	public function test_a_second_sweep_an_hour_later_sends_nothing() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '7,1', 'organizer_roster_email' => false ] );

		$event_id = $this->future_event( time() + 12 * HOUR_IN_SECONDS );
		$this->make_seat( $event_id, [ 'email' => 'late@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );

		$this->module()->run_reminder_sweep();
		$this->module()->run_reminder_sweep();

		$this->assertSame( [ 'late@example.com' ], $sent, 'The 7-day window is still open on the next sweep; it must not fire behind the 1-day.' );
	}

	public function test_each_offset_still_fires_in_turn_on_its_own_day() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '7,1', 'organizer_roster_email' => false ] );

		$event_id = $this->future_event( time() + 6 * DAY_IN_SECONDS );
		$this->make_seat( $event_id, [ 'email' => 'seat@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );

		// Inside the 7-day window only.
		$this->module()->run_reminder_sweep();
		$this->assertSame( [ 'seat@example.com' ], $sent );

		// The course draws nearer: the 1-day window opens and fires on its own.
		$this->reschedule( $event_id, time() + 12 * HOUR_IN_SECONDS );
		$this->module()->run_reminder_sweep();
		$this->assertSame( [ 'seat@example.com', 'seat@example.com' ], $sent, 'Moving the same seat into the 1-day window must send the 1-day reminder.' );
	}

	/* ------------------------------------------------------------------
	 * MODEL-D16 — markers are keyed to the date they were sent about
	 * ------------------------------------------------------------------ */

	public function test_rescheduling_re_arms_the_seven_day_reminder() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '7,1', 'organizer_roster_email' => false ] );

		$event_id = $this->future_event( time() + 12 * HOUR_IN_SECONDS );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'moved@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );

		$this->module()->run_reminder_sweep();
		$this->assertCount( 1, $sent );

		// Postponed by two months: nothing is due yet, and nothing may be sent.
		$new_ts = time() + 60 * DAY_IN_SECONDS;
		$this->reschedule( $event_id, $new_ts );
		$this->module()->run_reminder_sweep();
		$this->assertCount( 1, $sent, 'A date two months out is outside every reminder window.' );

		// Time passes; the new date's 7-day window opens.
		$due_ts = time() + 6 * DAY_IN_SECONDS;
		$this->reschedule( $event_id, $due_ts );
		$this->module()->run_reminder_sweep();

		$this->assertSame( [ 'moved@example.com', 'moved@example.com' ], $sent, 'The new date must earn its own 7-day reminder.' );
		$this->assertGreaterThan( 0, (int) ( $this->markers( $seat_id, $due_ts )[7] ?? 0 ) );
	}

	public function test_rescheduling_back_to_a_date_already_reminded_does_not_resend() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );

		$original = time() + 20 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $original );
		$this->make_seat( $event_id, [ 'email' => 'boomerang@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );

		$this->module()->run_reminder_sweep();
		$this->assertCount( 1, $sent );

		$this->reschedule( $event_id, time() + 18 * HOUR_IN_SECONDS );
		$this->module()->run_reminder_sweep();
		$this->assertCount( 2, $sent, 'The moved date is a different date and re-arms.' );

		$this->reschedule( $event_id, $original );
		$this->module()->run_reminder_sweep();
		$this->assertCount( 2, $sent, 'Keying by start_ts means moving BACK finds the original marker still standing.' );
	}

	public function test_a_legacy_flat_marker_counts_as_sent_for_the_current_date_and_is_rewritten() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 20 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'legacy@example.com' ] );

		// The pre-upgrade shape: [ offset => sent_at ], with no date attached.
		update_post_meta( $seat_id, '_anchor_event_reminders_sent', [ 1 => time() - 60 ] );

		$sent = [];
		$this->capture_mail( $sent );

		$this->module()->run_reminder_sweep();

		$this->assertSame( [], $sent, 'A legacy marker reads as "sent for the date this event is on now".' );
		$map = get_post_meta( $seat_id, '_anchor_event_reminders_sent', true );
		$this->assertArrayHasKey( $start_ts, $map, 'The legacy shape is rewritten under the current start_ts.' );
		$this->assertArrayNotHasKey( 1, $map, '…and the flat offset key is gone.' );
	}

	/* ------------------------------------------------------------------
	 * REG-D42 — the organizer digest re-arms too
	 * ------------------------------------------------------------------ */

	public function test_roster_digest_re_arms_when_the_event_moves() {
		$this->configure( [
			'reminder_enabled'       => false,
			'organizer_roster_email' => true,
			'roster_auto_offset'     => 1,
			'organizer_email'        => 'organizer@example.com',
		] );

		$original = time() + 20 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $original, 'Digest course' );
		$this->make_seat( $event_id, [ 'email' => 'a@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );

		$this->module()->run_reminder_sweep();
		$this->assertCount( 1, $sent, 'The digest goes out once for the original date.' );

		$this->module()->run_reminder_sweep();
		$this->assertCount( 1, $sent, '…and only once.' );

		$moved = time() + 18 * HOUR_IN_SECONDS;
		$this->reschedule( $event_id, $moved );
		$this->module()->run_reminder_sweep();
		$this->assertCount( 2, $sent, 'A moved date earns the organizer a digest for the date they actually need.' );

		$map = get_post_meta( $event_id, '_anchor_event_roster_sent', true );
		$this->assertIsArray( $map, 'roster_sent is keyed by the start_ts it was sent for.' );
		$this->assertArrayHasKey( $original, $map );
		$this->assertArrayHasKey( $moved, $map );
	}

	public function test_a_legacy_roster_marker_counts_as_sent_for_the_current_date() {
		$this->configure( [
			'reminder_enabled'       => false,
			'organizer_roster_email' => true,
			'roster_auto_offset'     => 1,
			'organizer_email'        => 'organizer@example.com',
		] );

		$start_ts = time() + 20 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts, 'Legacy digest' );
		update_post_meta( $event_id, '_anchor_event_roster_sent', time() - 3600 ); // pre-upgrade int.

		$sent = [];
		$this->capture_mail( $sent );

		$this->module()->run_reminder_sweep();

		$this->assertSame( [], $sent, 'A legacy int marker must not produce a duplicate digest on upgrade.' );
		$this->assertArrayHasKey( $start_ts, (array) get_post_meta( $event_id, '_anchor_event_roster_sent', true ) );
	}

	/* ------------------------------------------------------------------
	 * REG-D4 — the cancellation marker is per transition
	 * ------------------------------------------------------------------ */

	public function test_recancelling_a_restored_seat_emails_again() {
		$this->configure( [ 'notify_cancellation' => true ] );

		$event_id = $this->future_event( time() + 10 * DAY_IN_SECONDS );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'cancel@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );

		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CANCELLED );
		$this->module()->flush_cancellation_emails();
		$this->assertSame( [ 'cancel@example.com' ], $sent );
		$this->assertGreaterThan( 0, (int) get_post_meta( $seat_id, '_anchor_event_cancel_emailed', true ), 'The marker records WHEN, not merely THAT.' );

		// A roster edit puts the seat back — a legal transition.
		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CONFIRMED );
		$this->assertSame( '', get_post_meta( $seat_id, '_anchor_event_cancel_emailed', true ), 'Leaving a terminal status clears the marker.' );

		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CANCELLED );
		$this->module()->flush_cancellation_emails();

		$this->assertSame( [ 'cancel@example.com', 'cancel@example.com' ], $sent, 'The attendee must be told about the second cancellation too.' );
	}

	public function test_send_cancellation_email_reports_a_skip_as_a_skip() {
		$this->configure( [ 'notify_cancellation' => true ] );

		$event_id = $this->future_event( time() + 10 * DAY_IN_SECONDS );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'skip@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );

		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CANCELLED );
		$this->assertTrue( $this->module()->send_cancellation_email( $seat_id )->is_sent(), 'The first send reports the wp_mail() result.' );
		$second = $this->module()->send_cancellation_email( $seat_id );
		$this->assertFalse( $second->is_sent(), 'A skipped send is not a successful send.' );
		$this->assertTrue( $second->is_skipped(), 'Nor is it a failure — nothing went wrong (audit WOO-D14).' );
		$this->assertCount( 1, $sent );
	}

	/* ------------------------------------------------------------------
	 * REG-D5 — a failed send is retried, not dropped
	 * ------------------------------------------------------------------ */

	public function test_a_failed_cancellation_is_queued_and_drained_by_the_next_sweep() {
		$this->configure( [ 'notify_cancellation' => true, 'reminder_enabled' => false, 'organizer_roster_email' => false ] );

		$event_id = $this->future_event( time() + 10 * DAY_IN_SECONDS );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'retry@example.com' ] );

		$fail = $this->fail_mail();
		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CANCELLED );
		$this->module()->flush_cancellation_emails();
		remove_filter( 'pre_wp_mail', $fail, 10 );

		$job = get_post_meta( $seat_id, '_anchor_event_email_retry', true );
		$this->assertIsArray( $job, 'A dropped cancellation must leave a retry job behind.' );
		$this->assertSame( 'cancellation', $job['type'] );
		$this->assertSame( 1, (int) $job['attempts'] );
		$this->assertSame( '', get_post_meta( $seat_id, '_anchor_event_cancel_emailed', true ), 'Nothing was sent, so nothing is marked.' );

		$sent = [];
		$this->capture_mail( $sent );
		$this->make_retry_due( $seat_id );
		$this->module()->run_reminder_sweep();

		$this->assertSame( [ 'retry@example.com' ], $sent, 'The sweep drains the retry queue even with reminders switched off.' );
		$this->assertSame( '', get_post_meta( $seat_id, '_anchor_event_email_retry', true ), 'A delivered retry is cleared.' );
		$this->assertGreaterThan( 0, (int) get_post_meta( $seat_id, '_anchor_event_cancel_emailed', true ) );
	}

	public function test_a_retry_gives_up_after_three_attempts_with_a_log_entry() {
		$this->configure( [ 'notify_cancellation' => true, 'reminder_enabled' => false, 'organizer_roster_email' => false ] );

		$event_id = $this->future_event( time() + 10 * DAY_IN_SECONDS );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'doomed@example.com' ] );

		$this->fail_mail();

		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CANCELLED );
		$this->module()->flush_cancellation_emails();      // attempt 1
		$this->make_retry_due( $seat_id );
		$this->module()->run_reminder_sweep();             // attempt 2
		$this->make_retry_due( $seat_id );
		$this->module()->run_reminder_sweep();             // attempt 3 — the last

		$this->assertSame( '', get_post_meta( $seat_id, '_anchor_event_email_retry', true ), 'After three attempts the job is abandoned, not retried for ever.' );
		$this->assertSame( 1, $this->log_count( 'email_retry_abandoned' ), 'Giving up must leave a trace an operator can find.' );
	}

	/**
	 * finding-12 (carry-over) — a job no retry can ever satisfy (a switch
	 * flipped off since it was queued) must still be retired so it does not
	 * sit in the queue being re-read for ever, but retiring a SKIP is not a
	 * defect: this used to be inferred from the attempt counter not moving
	 * and logged as `email_retry_undeliverable`, an error-level entry for a
	 * deliberate site setting. drain_email_retry_queue() now consumes the
	 * sender's own Outcome instead, so the retirement is silent.
	 */
	public function test_a_retry_no_send_can_ever_satisfy_is_retired_not_left_in_the_queue() {
		$this->configure( [ 'notify_cancellation' => true, 'reminder_enabled' => false, 'organizer_roster_email' => false ] );

		$event_id = $this->future_event( time() + 10 * DAY_IN_SECONDS );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'switched-off@example.com' ] );

		$fail = $this->fail_mail();
		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CANCELLED );
		$this->module()->flush_cancellation_emails();
		remove_filter( 'pre_wp_mail', $fail, 10 );
		$this->assertIsArray( get_post_meta( $seat_id, '_anchor_event_email_retry', true ) );

		// The site turns cancellation emails off before the retry comes due.
		$this->configure( [ 'notify_cancellation' => false ] );
		$this->make_retry_due( $seat_id );
		$this->module()->run_reminder_sweep();

		$this->assertSame( '', get_post_meta( $seat_id, '_anchor_event_email_retry', true ), 'A job no send can satisfy must leave the queue.' );
		$this->assertSame(
			0,
			$this->log_count( 'email_retry_undeliverable' ),
			'A deliberate site setting is a skip, not a defect — retiring it must not raise an error-level log.'
		);
	}

	/**
	 * The ceiling has to hold for reminders too. The drain runs BEFORE the
	 * reminder pass, and the pass skips a seat only while a reminder job
	 * exists — so an abandoned job that left the offset unmarked would be
	 * picked straight back up by the same sweep, sent, and re-queued at one
	 * attempt, for ever. Abandoning marks the offset superseded instead.
	 */
	public function test_a_permanently_failing_reminder_stops_after_three_attempts() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 20 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'never@example.com' ] );

		$attempts = 0;
		add_filter( 'pre_wp_mail', function () use ( &$attempts ) {
			$attempts++;
			return false;
		}, 10, 2 );

		$this->module()->run_reminder_sweep();   // attempt 1 (the pass)
		$this->assertSame( 1, $attempts );

		$this->make_retry_due( $seat_id );
		$this->module()->run_reminder_sweep();   // attempt 2 (the drain)
		$this->assertSame( 2, $attempts );

		$this->make_retry_due( $seat_id );
		$this->module()->run_reminder_sweep();   // attempt 3 — and the last
		$this->assertSame( 3, $attempts, 'The abandoning sweep must not send again from its own reminder pass.' );

		$this->module()->run_reminder_sweep();
		$this->module()->run_reminder_sweep();
		$this->assertSame( 3, $attempts, 'After giving up, the sweep is silent about this offset for ever.' );

		$this->assertSame( '', get_post_meta( $seat_id, '_anchor_event_email_retry', true ) );
		// finding-14 — abandoned after MAX_EMAIL_ATTEMPTS is its OWN sentinel
		// now, distinct from a plain supersession's 0, so the Upcoming Sends
		// panel can tell the two apart; either way the offset is marked
		// (not left open) so the sweep never re-attempts it.
		$this->assertSame(
			Module::REMINDER_ABANDONED_MARKER,
			(int) ( $this->markers( $seat_id, $start_ts )[1] ?? 1 ),
			'The abandoned offset is marked with the abandoned sentinel, not left open.'
		);
		$this->assertSame( 1, $this->log_count( 'email_retry_abandoned' ), 'One abandon entry — not one per hour for ever.' );
	}

	/**
	 * One seat holds one job, so attempts are counted per job TYPE. A seat
	 * that has burned two reminder attempts must still get three goes at
	 * telling its attendee they were cancelled.
	 */
	public function test_a_cancellation_does_not_inherit_a_reminders_spent_attempts() {
		$this->configure( [
			'reminder_enabled'       => true,
			'reminder_offsets'       => '1',
			'organizer_roster_email' => false,
			'notify_cancellation'    => true,
		] );

		$event_id = $this->future_event( time() + 20 * HOUR_IN_SECONDS );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'both@example.com' ] );

		$this->fail_mail();
		$this->module()->run_reminder_sweep();  // reminder attempt 1
		$this->make_retry_due( $seat_id );
		$this->module()->run_reminder_sweep();  // reminder attempt 2
		$this->assertSame( 2, (int) get_post_meta( $seat_id, '_anchor_event_email_retry', true )['attempts'] );

		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CANCELLED );
		$this->module()->flush_cancellation_emails();

		$job = get_post_meta( $seat_id, '_anchor_event_email_retry', true );
		$this->assertIsArray( $job, 'The cancellation must not be abandoned on its first failure.' );
		$this->assertSame( 'cancellation', $job['type'] );
		$this->assertSame( 1, (int) $job['attempts'], 'A different job type starts its own count.' );
		$this->assertSame( 0, $this->log_count( 'email_retry_abandoned' ) );
	}

	/**
	 * …and for the same reason a delivered email may only clear its OWN job.
	 * The cancellation job here is written directly: reaching this state
	 * through the status API is impossible by design (update_status() drops a
	 * cancellation retry when a seat is restored), which is exactly why the
	 * guard has to be asserted rather than assumed.
	 */
	public function test_a_delivered_reminder_does_not_clear_a_cancellation_retry() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 20 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'mixed@example.com' ] );

		update_post_meta( $seat_id, '_anchor_event_email_retry', [
			'type'     => 'cancellation',
			'attempts' => 2,
			'next_at'  => time() + HOUR_IN_SECONDS, // not due this sweep
		] );

		$sent = [];
		$this->capture_mail( $sent );
		$this->module()->run_reminder_sweep();

		$this->assertSame( [ 'mixed@example.com' ], $sent, 'A reminder job is what the pass skips — a cancellation job is not.' );
		$job = get_post_meta( $seat_id, '_anchor_event_email_retry', true );
		$this->assertIsArray( $job, 'The cancellation retry must survive an unrelated successful send.' );
		$this->assertSame( 2, (int) $job['attempts'] );
	}

	/* ------------------------------------------------------------------
	 * Offset selection edge cases
	 * ------------------------------------------------------------------ */

	public function test_a_blocked_nearest_offset_falls_through_to_the_next_one() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '7,1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 12 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'filtered@example.com' ] );

		add_filter( 'anchor_events_should_send_reminder', function ( $send, $seat, $offset ) {
			return 1 === (int) $offset ? false : $send;
		}, 10, 3 );

		$sent = [];
		$this->capture_mail( $sent );
		$this->module()->run_reminder_sweep();

		$this->assertSame( [ 'filtered@example.com' ], $sent, 'Blocking the 1-day reminder must not silence the seat entirely.' );
		$markers = $this->markers( $seat_id, $start_ts );
		$this->assertGreaterThan( 0, (int) ( $markers[7] ?? 0 ), 'The 7-day reminder is what went out.' );
		$this->assertArrayNotHasKey( 1, $markers, 'A blocked offset stays unmarked — the filter may allow it later.' );
	}

	/**
	 * An event moved EARLIER than every start already in its marker map is the
	 * oldest key in that map. A plain "keep the newest ten" would delete the
	 * marker the sweep had just written and re-mail the whole roster.
	 */
	public function test_pruning_never_drops_the_date_the_marker_was_just_written_for() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 20 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'crowded@example.com' ] );

		// Ten later dates already on record — the map is full before this send.
		$existing = [];
		for ( $i = 1; $i <= 10; $i++ ) {
			$existing[ $start_ts + $i * DAY_IN_SECONDS ] = [ 1 => time() - $i ];
		}
		update_post_meta( $seat_id, '_anchor_event_reminders_sent', $existing );

		$sent = [];
		$this->capture_mail( $sent );

		$this->module()->run_reminder_sweep();
		$this->assertSame( [ 'crowded@example.com' ], $sent );

		$map = get_post_meta( $seat_id, '_anchor_event_reminders_sent', true );
		$this->assertCount( 10, $map, 'The map is still capped.' );
		$this->assertGreaterThan( 0, (int) ( $map[ $start_ts ][1] ?? 0 ), 'The date just mailed about must be one of the ten kept.' );

		$this->module()->run_reminder_sweep();
		$this->assertCount( 1, $sent, 'Because the marker survived, the next sweep sends nothing.' );
	}

	public function test_a_failed_reminder_is_retried_by_the_same_mechanism() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 20 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'blip@example.com' ] );

		$fail = $this->fail_mail();
		$this->module()->run_reminder_sweep();
		remove_filter( 'pre_wp_mail', $fail, 10 );

		$job = get_post_meta( $seat_id, '_anchor_event_email_retry', true );
		$this->assertIsArray( $job, 'A reminder that failed to send must be queued like a cancellation.' );
		$this->assertSame( 'reminder', $job['type'] );
		$this->assertSame( 1, (int) $job['offset'] );
		$this->assertSame( [], $this->markers( $seat_id, $start_ts ), 'Nothing sent, nothing marked.' );

		$sent = [];
		$this->capture_mail( $sent );
		$this->make_retry_due( $seat_id );
		$this->module()->run_reminder_sweep();

		$this->assertSame( [ 'blip@example.com' ], $sent, 'The drain sends it once — not once from the drain and once from the sweep.' );
		$this->assertGreaterThan( 0, (int) ( $this->markers( $seat_id, $start_ts )[1] ?? 0 ) );
		$this->assertSame( '', get_post_meta( $seat_id, '_anchor_event_email_retry', true ) );
	}

	/**
	 * finding-12 (carry-over) — a queued reminder retry that no longer
	 * applies (the event moved off the date the job was queued for) is a
	 * retry_reminder() skip, retired without an error-level log — same rule
	 * as the cancellation side above.
	 */
	public function test_a_reminder_retry_rescheduled_away_is_retired_without_an_error_log() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 20 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'moved@example.com' ] );

		$fail = $this->fail_mail();
		$this->module()->run_reminder_sweep(); // queues the retry job.
		remove_filter( 'pre_wp_mail', $fail, 10 );
		$this->assertIsArray( get_post_meta( $seat_id, '_anchor_event_email_retry', true ) );

		// The event moves to a different start before the retry comes due —
		// the queued job's start_ts no longer matches.
		$this->reschedule( $event_id, $start_ts + 30 * DAY_IN_SECONDS );
		$this->make_retry_due( $seat_id );
		$this->module()->run_reminder_sweep();

		$this->assertSame( '', get_post_meta( $seat_id, '_anchor_event_email_retry', true ), 'A retry the reschedule invalidated must not sit in the queue.' );
		$this->assertSame(
			0,
			$this->log_count( 'email_retry_undeliverable' ),
			'The event moving on is not a mailer defect — retiring the stale job must not raise an error-level log.'
		);
	}

	/* ------------------------------------------------------------------
	 * MODEL-D17 (widened) — `postponed` is as closed as `cancelled`
	 *
	 * The sweep's date-is-off guard and the queued-retry eligibility guard
	 * both checked `'cancelled' === get_event_status()` only, so a postponed
	 * event with confirmed seats kept sending/retrying "…is coming up" for a
	 * date that was off. Both now call status_is_closed(), the same
	 * cancelled|postponed set bookability() and the registration guards
	 * already agreed on; `moved_online` stays out of that set on purpose.
	 * ------------------------------------------------------------------ */

	/** Force an event's manual status, the way the admin "Event Status" field would. */
	private function set_manual_status( $event_id, $status ) {
		update_post_meta( $event_id, '_anchor_event_status_mode', 'manual' );
		update_post_meta( $event_id, '_anchor_event_status', $status );
	}

	public function test_a_postponed_event_sends_no_reminders() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 12 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$this->set_manual_status( $event_id, 'postponed' );
		$seat_id = $this->make_seat( $event_id, [ 'email' => 'postponed@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );
		$this->module()->run_reminder_sweep();

		$this->assertSame( [], $sent, 'A postponed event must not remind attendees about a date that is off.' );
		$this->assertSame(
			[],
			$this->markers( $seat_id, $start_ts ),
			'The guard is a bare continue, same as the cancelled path — it writes no offset marker while closed.'
		);
	}

	/**
	 * Nothing is marked while an event is closed, so the 1-day offset that
	 * never fired is still due the moment the event stops being postponed.
	 */
	public function test_un_postponing_an_event_resumes_reminders_on_the_next_sweep() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 12 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$this->set_manual_status( $event_id, 'postponed' );
		$seat_id = $this->make_seat( $event_id, [ 'email' => 'resumed@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );
		$this->module()->run_reminder_sweep();
		$this->assertSame( [], $sent, 'Still postponed on the first sweep — nothing goes out.' );

		// Un-postpone: back to auto status, which computes 'upcoming' for a
		// future start_date, same as clearing the manual override in the admin.
		update_post_meta( $event_id, '_anchor_event_status_mode', 'auto' );
		$this->module()->run_reminder_sweep();

		$this->assertSame(
			[ 'resumed@example.com' ],
			$sent,
			'Un-postponing must let the still-unfired 1-day offset send on the next sweep.'
		);
		$this->assertGreaterThan( 0, (int) ( $this->markers( $seat_id, $start_ts )[1] ?? 0 ) );
	}

	/**
	 * The control: `moved_online` is deliberately NOT in status_is_closed()
	 * — the event still happens, on the same date, just virtually — so it
	 * must keep sending, unlike cancelled/postponed above.
	 */
	public function test_a_moved_online_event_still_sends_reminders() {
		$this->configure( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );

		$start_ts = time() + 12 * HOUR_IN_SECONDS;
		$event_id = $this->future_event( $start_ts );
		$this->set_manual_status( $event_id, 'moved_online' );
		$this->make_seat( $event_id, [ 'email' => 'online@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );
		$this->module()->run_reminder_sweep();

		$this->assertSame( [ 'online@example.com' ], $sent, 'moved_online is not a closed status — reminders keep going out.' );
	}
}
