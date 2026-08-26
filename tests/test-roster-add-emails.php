<?php
/**
 * Manual roster adds notify the attendee.
 *
 * Adding an attendee by hand from the roster screen used to be silent: the
 * seat appeared, the person never heard about it, and the organizer had no way
 * to tell which of their attendees had been emailed. handle_add() now sends the
 * same confirmation/waitlist email the self-service path sends.
 *
 * That makes a roster click send real mail to a real person, so these tests pin
 * the three things that matter: the confirmed path emails, the waitlisted path
 * emails (and says waitlist, not confirmed), and the `notify_user` setting is
 * still honored — an organizer who turned attendee email OFF must not start
 * receiving support tickets because of a roster add.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/** Thrown from the wp_redirect filter so handle_add()'s exit never runs. */
class Anchor_Roster_Redirected extends \Exception {}

/**
 * @group email
 * @group roster
 */
class Test_Roster_Add_Emails extends Anchor_Events_TestCase {

	/** Every wp_mail() call made during the test: [ to, subject, message ]. */
	private $sent = [];

	public function set_up() {
		parent::set_up();

		$this->sent = [];
		add_filter( 'wp_mail', [ $this, 'capture_mail' ] );
		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		remove_filter( 'wp_mail', [ $this, 'capture_mail' ] );
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		delete_option( Module::OPTION_KEY );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function capture_mail( $args ) {
		$this->sent[] = $args;
		// Swallow the send itself — PHPMailer has nowhere to deliver to in the
		// test env, and a failed send would log noise we are not asserting on.
		$args['to'] = 'nobody@example.org';
		return $args;
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Roster_Redirected( (string) $location );
	}

	/** Set only the notification switches; everything else keeps its default. */
	private function set_notifications( $notify_user, $notify_admin = false ) {
		$settings = $this->module()->get_settings();
		$settings['notify_user']  = $notify_user;
		$settings['notify_admin'] = $notify_admin;
		update_option( Module::OPTION_KEY, $settings, false );
	}

	/**
	 * Drive the real admin-post handler, nonce and all, and return the redirect
	 * target it tried to send the organizer to.
	 */
	private function add_attendee( $event_id, array $fields ) {
		$_POST = array_merge(
			[
				'event_id'     => $event_id,
				'roster_name'  => 'Jane Doe',
				'roster_email' => 'jane@example.org',
				'roster_guests' => 0,
				'_wpnonce'     => wp_create_nonce( 'anchor_roster_add_' . $event_id ),
			],
			$fields
		);
		$_REQUEST = $_POST;

		try {
			$this->module()->roster->handle_add();
		} catch ( Anchor_Roster_Redirected $e ) {
			return $e->getMessage();
		}

		$this->fail( 'handle_add() did not redirect — the add was never completed.' );
	}

	/** Mail addressed to $to, or null. */
	private function mail_to( $to ) {
		foreach ( $this->sent as $args ) {
			$recipients = (array) $args['to'];
			if ( in_array( $to, $recipients, true ) ) {
				return $args;
			}
		}
		return null;
	}

	public function test_manual_add_emails_the_attendee() {
		$this->set_notifications( true );
		$event_id = $this->make_event( [ 'title' => 'Roster Email Event' ] );

		$this->add_attendee( $event_id, [] );

		$mail = $this->mail_to( 'jane@example.org' );
		$this->assertNotNull( $mail, 'The manually added attendee was never emailed.' );
		$this->assertStringContainsString( 'Roster Email Event', $mail['subject'] );
		$this->assertStringContainsString( 'registered', strtolower( $mail['subject'] ) );
	}

	/**
	 * A full event puts the add on the waitlist — the attendee still hears
	 * about it, and must not be told they are confirmed.
	 */
	public function test_manual_add_to_a_full_event_emails_the_waitlist_notice() {
		$this->set_notifications( true );
		$event_id = $this->make_event(
			[
				'title'    => 'Full Event',
				'capacity' => 1,
				'waitlist' => true,
			]
		);
		$this->make_seat( $event_id, [ 'status' => 'confirmed' ] );

		$this->add_attendee( $event_id, [ 'roster_email' => 'waitlisted@example.org' ] );

		$mail = $this->mail_to( 'waitlisted@example.org' );
		$this->assertNotNull( $mail, 'The waitlisted attendee was never emailed.' );
		$this->assertStringContainsString( 'waitlist', strtolower( $mail['message'] ) );
	}

	/** notify_user off means off, on the roster path too. */
	public function test_manual_add_respects_notify_user_off() {
		$this->set_notifications( false );
		$event_id = $this->make_event( [ 'title' => 'Quiet Event' ] );

		$this->add_attendee( $event_id, [ 'roster_email' => 'quiet@example.org' ] );

		$this->assertNull(
			$this->mail_to( 'quiet@example.org' ),
			'An attendee email went out even though notify_user is disabled.'
		);
	}
}
