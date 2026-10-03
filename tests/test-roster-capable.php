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
		$this->assertSame( 'external_signup', $out->reason() );
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

	public function test_one_predicate_answers_roster_and_stream() {
		$ext  = $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/x' ] );
		$free = $this->make_event( [ 'registration_mode' => 'free' ] );

		$this->assertTrue( $this->module()->uses_external_signup( $ext ) );
		$this->assertFalse( $this->module()->uses_external_signup( $free ) );
		$this->assertFalse( $this->module()->roster_capable( $ext ) );
		$this->assertFalse( $this->module()->stream_capable( $ext ) );
		$this->assertTrue( $this->module()->roster_capable( $free ) );
		$this->assertTrue( $this->module()->stream_capable( $free ) );
	}

	public function test_upcoming_sends_says_why_nothing_is_scheduled() {
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), [ 'reminder_enabled' => true, 'organizer_roster_email' => true ] ), false );
		$ext = $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/x', 'start_ts' => time() + DAY_IN_SECONDS ] );
		$this->make_seat( $ext ); // a legacy seat from before the switch

		$schedule = $this->module()->compute_email_schedule( $ext );
		$this->assertSame( 'external_signup', $schedule['notice'] );
		$this->assertSame( [], $schedule['rows'], 'No reminder rows either — reminders do not go to external-signup events.' );
	}

	public function test_send_roster_by_hand_says_why_for_an_external_event() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$ext   = $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/x' ] );
		$_POST = [ 'event_id' => $ext, '_wpnonce' => wp_create_nonce( 'anchor_events_send_roster_' . $ext ) ];
		$_REQUEST = $_POST;
		$trap = function ( $location ) {
			throw new Anchor_Entitlements_Redirect_Signal( (string) $location );
		};
		add_filter( 'wp_redirect', $trap );
		try {
			$this->module()->roster->handle_send_roster();
			$this->fail( 'handle_send_roster() did not redirect.' );
		} catch ( Anchor_Entitlements_Redirect_Signal $e ) {
			$this->assertStringContainsString( 'external form', rawurldecode( rawurldecode( $e->getMessage() ) ) );
		} finally {
			remove_filter( 'wp_redirect', $trap );
			$_POST    = [];
			$_REQUEST = [];
		}
		$this->assertCount( 0, $this->sent );
	}
}
