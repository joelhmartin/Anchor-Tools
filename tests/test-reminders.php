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

	public function test_send_cancellation_email_returns_false_when_it_skips() {
		$this->configure( [ 'notify_cancellation' => true ] );

		$event_id = $this->future_event( time() + 10 * DAY_IN_SECONDS );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'skip@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );

		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CANCELLED );
		$this->assertTrue( $this->module()->send_cancellation_email( $seat_id ), 'The first send reports the wp_mail() result.' );
		$this->assertFalse( $this->module()->send_cancellation_email( $seat_id ), 'A skipped send is not a successful send.' );
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
		$this->assertSame( 1, $this->log_count( 'email_retry_undeliverable' ) );
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
}
