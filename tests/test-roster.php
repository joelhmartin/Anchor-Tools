<?php
/**
 * The roster's manual seat actions, told honestly.
 *
 * Wave 3, task 32 — REG-D38, REG-D39, REG-D24, REG-D22, REG-D34, REG-D40.
 *
 * The manual add used to be the one seat-creating path that never asked the
 * capacity authority: a course whose registration window had closed, or one
 * flagged sold out by hand, took a roster add without anybody ticking "Allow
 * over capacity", and an event asking two required questions got a seat with an
 * empty answer set. The roster edit form was the other half of the same hole —
 * cancelled → confirmed and waitlist → confirmed went straight to
 * update_status(), which never recounts and never asks about capacity.
 *
 * These tests pin the three refusals apart by CODE (closed | full | invalid),
 * because "could not add attendee" said the same thing for all of them.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Events_Log;
use Anchor\Events\Module;
use Anchor\Events\Registrations;

/** Thrown from the wp_redirect filter so the handlers' exit never runs. */
class Anchor_Roster_Redirect_Signal extends \Exception {}

/**
 * @group roster
 */
class Test_Roster extends Anchor_Events_TestCase {

	/** Every wp_mail() call made during the test. */
	private $sent = [];

	public function set_up() {
		parent::set_up();

		$this->sent = [];
		add_filter( 'wp_mail', [ $this, 'capture_mail' ] );
		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		delete_option( Events_Log::ERROR_OPTION );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		remove_filter( 'wp_mail', [ $this, 'capture_mail' ] );
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		delete_option( Module::OPTION_KEY );
		delete_option( Events_Log::ERROR_OPTION );
		$_POST    = [];
		$_GET     = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function capture_mail( $args ) {
		$this->sent[] = $args;
		$args['to']   = 'nobody@example.org';
		return $args;
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Roster_Redirect_Signal( (string) $location );
	}

	/* -----------------------------------------------------------------
	 * Helpers
	 * --------------------------------------------------------------- */

	/** Set only the notification switches; everything else keeps its default. */
	private function set_notifications( $notify_user, $notify_admin = false ) {
		$settings                 = $this->module()->get_settings();
		$settings['notify_user']  = $notify_user;
		$settings['notify_admin'] = $notify_admin;
		update_option( Module::OPTION_KEY, $settings, false );
	}

	/** Drive handle_add() for real and return the redirect it attempted. */
	private function post_add( $event_id, array $fields = [] ) {
		$_POST = array_merge(
			[
				'event_id'      => $event_id,
				'roster_name'   => 'Jane Doe',
				'roster_email'  => 'jane@example.org',
				'roster_guests' => 0,
				'_wpnonce'      => wp_create_nonce( 'anchor_roster_add_' . $event_id ),
			],
			$fields
		);
		$_REQUEST = $_POST;

		try {
			$this->module()->roster->handle_add();
		} catch ( Anchor_Roster_Redirect_Signal $e ) {
			return $e->getMessage();
		}
		$this->fail( 'handle_add() did not redirect.' );
	}

	/** Drive handle_edit() for real and return the redirect it attempted. */
	private function post_edit( $event_id, $seat_id, array $fields = [] ) {
		$_POST = array_merge(
			[
				'event_id'    => $event_id,
				'seat_id'     => $seat_id,
				'roster_name' => 'Jane Doe',
				'_wpnonce'    => wp_create_nonce( 'anchor_roster_edit_' . $event_id ),
			],
			$fields
		);
		$_REQUEST = $_POST;

		try {
			$this->module()->roster->handle_edit();
		} catch ( Anchor_Roster_Redirect_Signal $e ) {
			return $e->getMessage();
		}
		$this->fail( 'handle_edit() did not redirect.' );
	}

	/** The machine-readable refusal code carried back on the redirect. */
	private function code_of( $url ) {
		$q = [];
		parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $q );
		return (string) ( $q['roster_code'] ?? '' );
	}

	/** The human notice carried back on the redirect. */
	private function message_of( $url ) {
		$q = [];
		parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $q );
		return rawurldecode( (string) ( $q['roster_msg'] ?? '' ) );
	}

	/** Error-log codes recorded during the test. @return string[] */
	private function error_codes() {
		$log = get_option( Events_Log::ERROR_OPTION, [] );
		return is_array( $log ) ? array_column( $log, 'code' ) : [];
	}

	/** Mail addressed to $to, or null. */
	private function mail_to( $to ) {
		foreach ( $this->sent as $args ) {
			if ( in_array( $to, (array) $args['to'], true ) ) {
				return $args;
			}
		}
		return null;
	}

	/** An event asking one required text question and one optional textarea. */
	private function event_with_questions( array $meta = [] ) {
		$event_id = $this->make_event( $meta );
		update_post_meta(
			$event_id,
			Module::QUESTIONS_META,
			[
				[
					'key'      => 'practice_name',
					'label'    => 'Practice name',
					'type'     => 'text',
					'required' => true,
				],
				[
					'key'      => 'dietary',
					'label'    => 'Dietary needs',
					'type'     => 'textarea',
					'required' => false,
				],
			]
		);
		return $event_id;
	}

	/* -----------------------------------------------------------------
	 * REG-D39 — the manual add asks the capacity authority
	 * --------------------------------------------------------------- */

	public function test_manual_add_to_a_closed_registration_window_is_refused() {
		$event_id = $this->make_event(
			[ 'registration_close' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ) ]
		);

		$location = $this->post_add( $event_id );

		$this->assertSame( 'closed', $this->code_of( $location ) );
		$this->assertSame( 0, $this->count_seats( $event_id ), 'A closed event took a manual seat.' );
	}

	public function test_manual_add_to_a_hand_flagged_sold_out_event_is_refused_as_full() {
		$event_id = $this->make_event( [ 'sold_out' => 1 ] );

		$location = $this->post_add( $event_id );

		$this->assertSame( 'full', $this->code_of( $location ) );
		$this->assertSame( 0, $this->count_seats( $event_id ) );
	}

	public function test_manual_add_past_capacity_without_a_waitlist_is_refused_as_full() {
		$event_id = $this->make_event( [ 'capacity' => 1 ] );
		$this->make_seat( $event_id );

		$location = $this->post_add( $event_id );

		$this->assertSame( 'full', $this->code_of( $location ) );
		$this->assertSame( 1, $this->count_seats( $event_id ) );
	}

	public function test_the_override_checkbox_forces_a_closed_add_and_records_the_overfill() {
		$event_id = $this->make_event(
			[
				'capacity'           => 1,
				'registration_close' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
			]
		);
		$this->make_seat( $event_id );

		$location = $this->post_add( $event_id, [ 'roster_allow_over' => '1' ] );

		$this->assertSame( '', $this->code_of( $location ), 'The override add must not refuse.' );
		$this->assertSame( 2, $this->count_seats( $event_id, Registrations::STATUS_CONFIRMED ) );
		$this->assertContains( 'capacity_overfill', $this->error_codes() );
	}

	public function test_a_full_event_with_a_waitlist_still_waitlists_a_manual_add() {
		$event_id = $this->make_event(
			[
				'capacity' => 1,
				'waitlist' => true,
			]
		);
		$this->make_seat( $event_id );

		$location = $this->post_add( $event_id );

		$this->assertSame( '', $this->code_of( $location ) );
		$this->assertSame( 1, $this->count_seats( $event_id, Registrations::STATUS_WAITLIST ) );
		$this->assertStringContainsString( 'waitlist', strtolower( $this->message_of( $location ) ) );
	}

	/* -----------------------------------------------------------------
	 * REG-D33 — one status list, not two
	 * --------------------------------------------------------------- */

	public function test_the_status_select_offers_every_status_the_model_accepts() {
		$options = $this->module()->roster->status_options();

		$this->assertSame( Registrations::STATUSES, array_keys( $options ) );
		foreach ( Registrations::STATUSES as $status ) {
			$this->assertTrue( $this->registrations()->valid_status( $status ) );
			$this->assertNotSame( '', (string) $options[ $status ] );
		}
	}

	/* -----------------------------------------------------------------
	 * REG-D13 — the status filter links are actually rendered
	 * --------------------------------------------------------------- */

	/** Render the admin roster screen for $event_id and return its HTML. */
	private function render_roster_screen( $event_id ) {
		set_current_screen( 'edit-event' );
		try {
			$method = new ReflectionMethod( $this->module()->roster, 'render_roster' );
			$method->setAccessible( true );
			ob_start();
			$method->invoke( $this->module()->roster, $event_id );
			return (string) ob_get_clean();
		} finally {
			set_current_screen( 'front' );
		}
	}

	public function test_the_roster_screen_renders_the_status_filter_links() {
		$html = html_entity_decode( $this->render_roster_screen( $this->make_event() ) );

		$this->assertStringContainsString( 'subsubsub', $html, 'The roster screen never called $table->views().' );
		$this->assertStringContainsString( 'status=waitlist', $html );
		$this->assertStringContainsString( 'status=cancelled', $html );
	}

	/** REG-D14 — no row checkbox, because nothing consumes seat[]. */
	public function test_the_roster_table_has_no_checkbox_column_without_a_bulk_action() {
		$event_id = $this->make_event();
		$this->make_seat( $event_id, [ 'name' => 'Jane Doe', 'email' => 'jane@example.org' ] );

		$html = $this->render_roster_screen( $event_id );

		$this->assertStringNotContainsString( 'name="seat[]"', $html );
		$this->assertStringNotContainsString( 'check-column', $html );
		$this->assertStringContainsString( 'Jane Doe', $html );
	}

	/* -----------------------------------------------------------------
	 * REG-D39 — the manual add asks the event's own questions
	 * --------------------------------------------------------------- */

	public function test_the_admin_add_form_renders_the_event_questions() {
		$event_id = $this->event_with_questions();

		$method = new ReflectionMethod( $this->module()->roster, 'render_add_form' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( $this->module()->roster, $event_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'roster_field[practice_name]', $html );
		$this->assertStringContainsString( 'Practice name', $html );
		$this->assertMatchesRegularExpression( '/<textarea[^>]*name="roster_field\[dietary\]"/', $html );
	}

	public function test_the_frontend_add_form_renders_the_event_questions() {
		$event_id = $this->event_with_questions();

		$html = $this->module()->roster->render_frontend( $event_id, home_url( '/console/' ) );

		$this->assertStringContainsString( 'roster_field[practice_name]', $html );
	}

	public function test_manual_add_stores_the_answers_keyed_by_question_key() {
		$event_id = $this->event_with_questions();

		$this->post_add(
			$event_id,
			[
				'roster_field' => [
					'practice_name' => 'Anchor Dental',
					'dietary'       => "No nuts\nNo shellfish",
				],
			]
		);

		$seats = $this->registrations()->query_seats( [ 'event_id' => $event_id ] );
		$this->assertCount( 1, $seats['items'] );
		$fields = $seats['items'][0]['reg_fields'];
		$this->assertSame( 'Anchor Dental', $fields['practice_name'] ?? null );
		$this->assertStringContainsString(
			"\n",
			(string) ( $fields['dietary'] ?? '' ),
			'A textarea answer must keep its newlines on every write path.'
		);
	}

	public function test_manual_add_without_a_required_answer_is_refused_as_invalid() {
		$event_id = $this->event_with_questions();

		$location = $this->post_add( $event_id, [ 'roster_field' => [ 'practice_name' => '' ] ] );

		$this->assertSame( 'invalid', $this->code_of( $location ) );
		$this->assertSame( 0, $this->count_seats( $event_id ) );
	}

	public function test_manual_add_without_a_name_is_refused_as_invalid() {
		$event_id = $this->make_event();

		$location = $this->post_add( $event_id, [ 'roster_name' => '   ' ] );

		$this->assertSame( 'invalid', $this->code_of( $location ) );
		$this->assertSame( 0, $this->count_seats( $event_id ) );
	}

	public function test_manual_add_with_an_unusable_email_is_refused_as_invalid() {
		$event_id = $this->make_event();

		$location = $this->post_add( $event_id, [ 'roster_email' => 'not-an-address' ] );

		$this->assertSame( 'invalid', $this->code_of( $location ) );
		$this->assertSame( 0, $this->count_seats( $event_id ) );
	}

	/* -----------------------------------------------------------------
	 * REG-D34 — an empty name is a refusal, not a silent no-op
	 * --------------------------------------------------------------- */

	public function test_update_contact_refuses_an_empty_name() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat( $event_id, [ 'name' => 'Original Name' ] );

		$result = $this->registrations()->update_contact( $seat_id, [ 'name' => '' ] );

		$this->assertTrue( $result->is_failed(), 'A blank name must not report success.' );
		$this->assertSame( 'empty_name', $result->reason() );
		$this->assertSame( 'Original Name', get_post_meta( $seat_id, '_anchor_event_name', true ) );
	}

	public function test_the_roster_edit_says_a_blank_name_was_not_saved() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat(
			$event_id,
			[
				'name'  => 'Original Name',
				'email' => 'original@example.org',
			]
		);

		$location = $this->post_edit(
			$event_id,
			$seat_id,
			[
				'roster_name'  => '',
				'roster_email' => 'changed@example.org',
			]
		);

		$this->assertSame( 'invalid', $this->code_of( $location ) );
		$this->assertStringContainsString( 'name', strtolower( $this->message_of( $location ) ) );
		$this->assertSame( 'Original Name', get_post_meta( $seat_id, '_anchor_event_name', true ) );
		$this->assertSame(
			'original@example.org',
			get_post_meta( $seat_id, '_anchor_event_email', true ),
			'A refused edit must not half-save the other fields.'
		);
	}

	/* -----------------------------------------------------------------
	 * REG-D38 — reviving a seat goes through the capacity decision
	 * --------------------------------------------------------------- */

	public function test_promoting_a_waitlisted_seat_past_capacity_is_refused() {
		$event_id = $this->make_event(
			[
				'capacity' => 1,
				'waitlist' => true,
			]
		);
		$this->make_seat( $event_id );
		$seat_id = $this->make_seat( $event_id, [ 'status' => Registrations::STATUS_WAITLIST ] );

		$location = $this->post_edit(
			$event_id,
			$seat_id,
			[ 'roster_status' => Registrations::STATUS_CONFIRMED ]
		);

		$this->assertSame( 'full', $this->code_of( $location ) );
		$this->assertSame(
			Registrations::STATUS_WAITLIST,
			get_post_meta( $seat_id, '_anchor_event_reg_status', true ),
			'The promotion silently overbooked the event.'
		);
	}

	public function test_reviving_a_cancelled_seat_past_capacity_is_refused() {
		$event_id = $this->make_event( [ 'capacity' => 1 ] );
		$this->make_seat( $event_id );
		$seat_id = $this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CANCELLED ] );

		$location = $this->post_edit(
			$event_id,
			$seat_id,
			[ 'roster_status' => Registrations::STATUS_CONFIRMED ]
		);

		$this->assertSame( 'full', $this->code_of( $location ) );
		$this->assertSame(
			Registrations::STATUS_CANCELLED,
			get_post_meta( $seat_id, '_anchor_event_reg_status', true )
		);
	}

	public function test_the_override_confirms_past_capacity_and_records_the_overfill() {
		$event_id = $this->make_event( [ 'capacity' => 1 ] );
		$this->make_seat( $event_id );
		$seat_id = $this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CANCELLED ] );

		$this->post_edit(
			$event_id,
			$seat_id,
			[
				'roster_status'     => Registrations::STATUS_CONFIRMED,
				'roster_allow_over' => '1',
			]
		);

		$this->assertSame(
			Registrations::STATUS_CONFIRMED,
			get_post_meta( $seat_id, '_anchor_event_reg_status', true )
		);
		$this->assertContains( 'capacity_overfill', $this->error_codes() );
	}

	public function test_a_promotion_within_capacity_confirms_and_emails_the_attendee() {
		$this->set_notifications( true );
		$event_id = $this->make_event(
			[
				'title'    => 'Promotion Event',
				'capacity' => 5,
				'waitlist' => true,
			]
		);
		$seat_id = $this->make_seat(
			$event_id,
			[
				'status' => Registrations::STATUS_WAITLIST,
				'email'  => 'promoted@example.org',
			]
		);

		$location = $this->post_edit(
			$event_id,
			$seat_id,
			[
				'roster_status' => Registrations::STATUS_CONFIRMED,
				'roster_email'  => 'promoted@example.org',
			]
		);

		$this->assertSame( '', $this->code_of( $location ) );
		$this->assertSame(
			Registrations::STATUS_CONFIRMED,
			get_post_meta( $seat_id, '_anchor_event_reg_status', true )
		);
		$this->assertNotNull(
			$this->mail_to( 'promoted@example.org' ),
			'A waitlist promotion told nobody they got a seat.'
		);
	}

	/* -----------------------------------------------------------------
	 * REG-D22 — "N registrants" excludes cancelled seats
	 * --------------------------------------------------------------- */

	public function test_the_registrant_headline_excludes_cancelled_seats() {
		$event_id = $this->make_event( [ 'title' => 'Counted Event' ] );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->make_seat( $event_id );
		}
		for ( $i = 0; $i < 7; $i++ ) {
			$this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CANCELLED ] );
		}

		$counts = $this->module()->registrant_counts( $event_id );
		$this->assertSame( 3, $counts['active'] );
		$this->assertSame( 7, $counts['cancelled'] );

		$method = new ReflectionMethod( $this->module(), 'render_event_manager_item' );
		$method->setAccessible( true );
		$html = (string) $method->invoke( $this->module(), get_post( $event_id ) );
		$this->assertStringContainsString( '3 registrants', $html );
		$this->assertStringNotContainsString( '10 registrants', $html );
	}

	/* -----------------------------------------------------------------
	 * REG-D24 — a waitlisted registration is told so
	 * --------------------------------------------------------------- */

	public function test_a_waitlisted_public_registration_is_told_it_is_waitlisted() {
		$this->set_notifications( true );
		$event_id = $this->make_event(
			[
				'capacity' => 1,
				'waitlist' => true,
			]
		);
		$this->make_seat( $event_id );

		$_POST = [
			Module::REG_NONCE      => wp_create_nonce( Module::REG_NONCE ),
			'event_id'             => $event_id,
			'redirect_to'          => get_permalink( $event_id ),
			'anchor_event_name'    => 'Wait Lister',
			'anchor_event_email'   => 'waiting@example.org',
		];
		$_REQUEST = $_POST;

		$location = '';
		try {
			$this->module()->handle_registration();
		} catch ( Anchor_Roster_Redirect_Signal $e ) {
			$location = $e->getMessage();
		}

		$this->assertStringContainsString( 'registration_waitlisted', $location );

		$_GET['event_registration'] = 'registration_waitlisted';
		$notice                     = $this->module()->render_registration_notice();
		$this->assertStringContainsString( 'waitlist', strtolower( $notice ) );
	}

	public function test_the_notice_only_promises_an_email_when_one_was_sent() {
		$_GET['event_registration'] = 'registration_success';
		$quiet                      = $this->module()->render_registration_notice();
		$this->assertStringNotContainsString( 'Check your email', $quiet );

		$_GET['event_registration_email'] = '1';
		$loud                             = $this->module()->render_registration_notice();
		$this->assertStringContainsString( 'Check your email', $loud );
	}

	/* -----------------------------------------------------------------
	 * REG-D40 — each refusal names itself in the log
	 * --------------------------------------------------------------- */

	public function test_a_reminder_to_a_seat_with_no_address_logs_a_distinct_code() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat( $event_id, [ 'email' => '' ] );
		$seat     = $this->registrations()->get_seat( $seat_id );

		$result = $this->module()->send_reminder_email( $seat, $event_id, 1 );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'no_address', $result->reason() );
		$this->assertContains( 'reminder_no_address', $this->error_codes() );
	}

	public function test_a_switched_off_reminder_is_not_logged_as_an_error() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_email_off_reminder', '1' );
		$seat_id = $this->make_seat( $event_id );
		$seat    = $this->registrations()->get_seat( $seat_id );

		$this->module()->send_reminder_email( $seat, $event_id, 1 );

		$this->assertNotContains(
			'reminder_no_address',
			$this->error_codes(),
			'An organizer switching reminders off is a setting, not an error.'
		);
	}

	public function test_the_roster_digest_logs_a_distinct_code_for_a_bad_target() {
		$not_an_event = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$result = $this->module()->send_roster_email( $not_an_event );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'invalid_event', $result->reason() );
		$this->assertContains( 'roster_invalid_event', $this->error_codes() );
	}

	/**
	 * REG-D43 — the digest CTA is minted inside cron, with no logged-in user,
	 * so a nonce on it would be bound to user 0 and expire in 24 hours. The
	 * roster screen is capability-gated and read-only, so the link is a plain
	 * admin URL (REG-D15 removed the nonce roster_url() used to add).
	 */
	public function test_the_roster_digest_cta_is_a_plain_admin_link() {
		$event_id = $this->make_event();

		$this->assertStringNotContainsString( '_wpnonce', $this->module()->roster->roster_url( $event_id ) );

		$this->assertTrue( $this->module()->send_roster_email( $event_id )->is_sent() );
		$mail = $this->sent[0] ?? null;
		$this->assertNotNull( $mail );
		$this->assertStringNotContainsString( '_wpnonce', (string) $mail['message'] );
		$this->assertStringContainsString( 'page=anchor-event-roster', html_entity_decode( (string) $mail['message'] ) );
	}

	/**
	 * REG-D17 — a roster send with nowhere to send it logs its own code, and
	 * a roster switched off says so in the notice instead of pointing the
	 * operator at an error log that has nothing in it.
	 */
	public function test_a_roster_digest_with_no_recipient_logs_a_distinct_code() {
		$event_id = $this->make_event();
		// The site fallback is admin_email, and WP refuses to store an empty
		// one — filter it away for the length of the assertion instead.
		$blank = static function () {
			return '';
		};
		add_filter( 'option_admin_email', $blank );

		try {
			$result = $this->module()->send_roster_email( $event_id );
		} finally {
			remove_filter( 'option_admin_email', $blank );
		}

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'no_address', $result->reason() );
		$this->assertContains( 'roster_no_address', $this->error_codes() );
	}

	public function test_a_switched_off_roster_send_names_the_reason_in_the_notice() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_email_off_roster', '1' );

		$_POST    = [
			'event_id' => $event_id,
			'_wpnonce' => wp_create_nonce( 'anchor_events_send_roster_' . $event_id ),
		];
		$_REQUEST = $_POST;

		$location = '';
		try {
			$this->module()->roster->handle_send_roster();
		} catch ( Anchor_Roster_Redirect_Signal $e ) {
			$location = $e->getMessage();
		}

		$this->assertStringContainsString( 'switched off', $this->message_of( $location ) );
		$this->assertNotContains( 'roster_disabled', $this->error_codes() );
	}

}
