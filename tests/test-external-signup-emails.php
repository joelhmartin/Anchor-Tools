<?php
/**
 * External-signup events send no lifecycle email: no confirmation, no
 * organizer "new registration" notice, no reminder (sweep or retry), no
 * cancellation. Seats can only exist on such an event from before the box
 * was ticked; they must stay silent, and the reminder sweep must not mark
 * them, or unticking the box later would silently lose the reminder.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;

/**
 * @group email
 */
class Test_External_Signup_Emails extends Anchor_Events_TestCase {

	private $sent = [];

	public function set_up() {
		parent::set_up();
		$this->sent = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), [
			'notify_user'            => true,
			'notify_admin'           => true,
			'admin_email'            => 'org@example.org',
			'notify_cancellation'    => true,
			'reminder_enabled'       => true,
			'reminder_offsets'       => '1',
			'organizer_roster_email' => false,
		] ), false );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		delete_option( Module::OPTION_KEY );
		parent::tear_down();
	}

	public function capture_mail( $short_circuit, $atts ) {
		$this->sent[] = $atts;
		return true;
	}

	private function external_event( array $meta = [] ) {
		return $this->make_event( array_merge( [
			'registration_mode' => 'external',
			'external_embed'    => '<iframe src="https://form.example/1"></iframe>',
		], $meta ) );
	}

	public function test_no_confirmation_and_no_organizer_notice() {
		$out = $this->module()->send_registration_emails( $this->external_event(), 'Jane', 'jane@example.org', Registrations::STATUS_CONFIRMED );
		$this->assertTrue( $out->is_skipped() );
		$this->assertSame( 'external_signup', $out->reason() );
		$this->assertCount( 0, $this->sent, 'Neither the attendee nor the organizer copy may go out.' );
	}

	public function test_attendee_only_path_refuses_too() {
		// The waitlist promotion calls this half on its own.
		$out = $this->module()->send_confirmation_email( $this->external_event(), 'Jane', 'jane@example.org', Registrations::STATUS_CONFIRMED );
		$this->assertSame( 'external_signup', $out->reason() );
		$this->assertCount( 0, $this->sent );
	}

	public function test_reminder_sweep_skips_legacy_seats_and_writes_no_markers() {
		$start = time() + 12 * HOUR_IN_SECONDS;
		$ext   = $this->external_event( [
			'timezone'   => 'UTC',
			'start_date' => gmdate( 'Y-m-d', $start ),
			'start_time' => gmdate( 'H:i', $start ),
			'start_ts'   => $start,
		] );
		$seat = $this->make_seat( $ext, [ 'email' => 'legacy@example.org' ] );

		$this->module()->run_reminder_sweep();

		$this->assertCount( 0, $this->sent );
		$this->assertEmpty( get_post_meta( $seat, Registrations::META_REMINDERS_SENT, true ), 'No markers: unticking the box later must still let the reminder go.' );
	}

	public function test_reminder_retry_path_refuses() {
		$ext  = $this->external_event();
		$seat = $this->make_seat( $ext, [ 'email' => 'legacy@example.org' ] );
		$out  = $this->module()->send_reminder_email( [ 'id' => $seat, 'email' => 'legacy@example.org', 'name' => 'Legacy' ], $ext, 1 );
		$this->assertSame( 'external_signup', $out->reason() );
		$this->assertCount( 0, $this->sent );
	}

	public function test_cancellation_email_refuses() {
		$seat = $this->make_seat( $this->external_event(), [ 'email' => 'legacy@example.org' ] );
		$out  = $this->module()->send_cancellation_email( $seat );
		$this->assertSame( 'external_signup', $out->reason() );
		$this->assertCount( 0, $this->sent );
	}

	/** Control: a guard that blocks everything would pass every test above. */
	public function test_native_event_still_sends_confirmation_and_notice() {
		$free = $this->make_event( [ 'registration_mode' => 'free' ] );
		$out  = $this->module()->send_registration_emails( $free, 'Jane', 'jane@example.org', Registrations::STATUS_CONFIRMED );
		$this->assertTrue( $out->is_sent() );
		$this->assertCount( 2, $this->sent, 'Organizer notice + attendee confirmation.' );
	}
}
