<?php
/**
 * Events whose sign-ups happen on an external form have no roster: no roster
 * email (scheduled or by hand), no roster row in Upcoming Sends, no roster
 * screen or console panel. Module::roster_capable() is the one answer.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group email
 */
class Test_Roster_Capable extends Anchor_Events_TestCase {

	private $sent = [];

	public function set_up() {
		parent::set_up();
		$this->sent = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
		update_option( Module::OPTION_KEY, [ 'organizer_roster_email' => true, 'roster_auto_offset' => 2, 'organizer_email' => 'org@example.org', 'notify_admin' => false, 'notify_user' => false ], false );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		remove_all_filters( 'anchor_events_roster_capable' );
		delete_option( Module::OPTION_KEY );
		parent::tear_down();
	}

	public function capture_mail( $short_circuit, $atts ) {
		$this->sent[] = $atts;
		return true;
	}

	public function test_native_events_keep_their_roster() {
		$free = $this->make_event( [ 'registration_mode' => 'free' ] );
		$this->assertTrue( $this->module()->roster_capable( $free ) );
		$this->assertTrue( $this->module()->send_roster_email( $free )->is_sent() );
		$this->assertCount( 1, $this->sent );
	}

	public function test_external_registration_sends_no_roster_email() {
		$ext = $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/x' ] );
		$this->assertFalse( $this->module()->roster_capable( $ext ) );

		$out = $this->module()->send_roster_email( $ext );
		$this->assertTrue( $out->is_skipped() );
		$this->assertSame( 'no_roster', $out->reason() );
		$this->assertCount( 0, $this->sent );
	}

	public function test_a_site_can_mark_other_events_roster_less() {
		$free = $this->make_event( [ 'registration_mode' => 'free' ] );
		add_filter( 'anchor_events_roster_capable', function ( $capable, $event_id ) use ( $free ) {
			return $event_id === $free ? false : $capable;
		}, 10, 2 );

		$this->assertFalse( $this->module()->roster_capable( $free ) );
		$this->assertTrue( $this->module()->send_roster_email( $free )->is_skipped() );
		$this->assertCount( 0, $this->sent );
	}

	public function test_scheduled_digest_never_fires_and_upcoming_sends_lists_no_roster() {
		$start = time() + DAY_IN_SECONDS; // inside the 2-day roster window
		$ext   = $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/x', 'start_ts' => $start ] );

		$this->module()->maybe_send_scheduled_roster( $ext, $this->module()->get_meta( $ext ), $this->module()->get_settings(), time() );
		$this->assertCount( 0, $this->sent );

		$rows = $this->module()->compute_email_schedule( $ext )['rows'];
		$this->assertSame( [], array_values( array_filter( $rows, function ( $r ) { return 'roster' === $r['type']; } ) ) );
	}
}
