<?php
/**
 * A date inherits every email setting from its main event and can override any
 * one field of it.
 *
 * The model this replaces COPIED the main event's email rows down onto every
 * date on each main-event save, and DELETED a date's row the main event did not
 * have. Toronto (2026-10-20) held its custom confirmation wording on the date
 * only, so the main event's editor showed blank fields, and saving that blank
 * screen would have wiped live wording. Now nothing is copied: a date with no
 * row of its own reads the main event's at send time, and a date's own non-empty
 * row is an override for that one field.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Email_Inheritance;
use Anchor\Events\Module;

/**
 * @group occurrences
 * @group email
 */
class Test_Events_Email_Inheritance extends Anchor_Events_TestCase {

	const SUBJECT = '_anchor_event_email_subject_confirmation';
	const INTRO   = '_anchor_event_email_intro_confirmation';

	/** Every wp_mail() call made during the test. */
	private $sent = [];

	public function set_up() {
		parent::set_up();
		$this->sent = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		delete_option( Module::OPTION_KEY );
		delete_option( Email_Inheritance::MIGRATION_OPTION );
		unset( $_POST );
		parent::tear_down();
	}

	public function capture_mail( $short_circuit, $atts ) {
		$this->sent[] = $atts;
		return true;
	}

	private function inheritance() {
		return $this->module()->email_inheritance;
	}

	/**
	 * A main event with $count dates. Email rows are written AFTER the
	 * reconcile, so each test states exactly which post holds which row.
	 *
	 * @return array{0:int,1:int[]} [ main id, date ids ]
	 */
	private function group( $count = 1 ) {
		$rows = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$rows[] = [ 'date' => sprintf( '2027-05-%02d', 4 + $i * 7 ), 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session ' . $i, 'capacity' => 10 ];
		}
		$main = $this->make_event( [ 'title' => 'Workshop', 'timezone' => 'UTC' ] );
		update_post_meta( $main, '_anchor_event_offering_dates', $rows );
		$dates = array_map( 'intval', $this->module()->occurrences->reconcile( $main ) );
		$this->assertCount( $count, $dates );
		return [ $main, $dates ];
	}

	/** Run the console save for one event with the given posted fields. */
	private function console_save( $event_id, array $post ) {
		$_POST  = $post;
		$method = new ReflectionMethod( Module::class, 'save_event_manager_fields' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $event_id, '2027-05-04', $this->module()->registration_mode( $event_id ) );
		unset( $_POST );
	}

	private function render_form( $event_id ) {
		$method = new ReflectionMethod( Module::class, 'render_event_manager_form' );
		$method->setAccessible( true );
		return (string) $method->invoke( $this->module(), $event_id );
	}

	public function test_date_label_uses_the_event_timezone() {
		update_option( 'timezone_string', 'UTC' );
		update_option( Module::OPTION_KEY, [ 'timezone_mode' => 'event' ], false );
		$this->module()->clear_caches();
		$id = $this->make_event( [ 'timezone' => 'America/Toronto' ] );
		$ts = ( new DateTimeImmutable( '2026-11-13 21:00', new DateTimeZone( 'America/Toronto' ) ) )->getTimestamp();
		update_post_meta( $id, $this->module()->meta_key( 'start_ts' ), $ts );
		$this->assertSame( 'Nov 13, 2026', $this->inheritance()->date_label( $id ) );
	}

	/* ------------------------------------------------------------------ resolver */

	public function test_date_without_row_inherits_main() {
		list( $main, $dates ) = $this->group();
		update_post_meta( $main, self::SUBJECT, 'Main subject' );

		$this->assertSame( [ 'value' => 'Main subject', 'source' => 'main' ], $this->inheritance()->resolve( $dates[0], self::SUBJECT ) );
		$this->assertSame( 'Main subject', $this->module()->email_meta( $dates[0], self::SUBJECT ) );
		$this->assertSame( 'Main subject', $this->module()->get_email_field( $dates[0], 'confirmation', 'subject', 'SITE' ) );
	}

	public function test_date_override_wins() {
		list( $main, $dates ) = $this->group();
		update_post_meta( $main, self::SUBJECT, 'Main subject' );
		update_post_meta( $dates[0], self::SUBJECT, 'Date subject' );

		$this->assertSame( [ 'value' => 'Date subject', 'source' => 'own' ], $this->inheritance()->resolve( $dates[0], self::SUBJECT ) );
		$this->assertSame( 'Main subject', $this->module()->email_meta( $main, self::SUBJECT ), 'The main event reads its own row.' );
	}

	public function test_non_group_event_reads_own_row() {
		$event = $this->make_event();
		update_post_meta( $event, self::SUBJECT, 'Standalone' );

		$this->assertSame( [ 'value' => 'Standalone', 'source' => 'own' ], $this->inheritance()->resolve( $event, self::SUBJECT ) );
		$this->assertSame( [ 'value' => '', 'source' => 'none' ], $this->inheritance()->resolve( $event, self::INTRO ) );
	}

	public function test_override_subject_only_inherits_intro() {
		list( $main, $dates ) = $this->group();
		update_post_meta( $main, self::SUBJECT, 'Main subject' );
		update_post_meta( $main, self::INTRO, 'Main opening lines' );
		update_post_meta( $dates[0], self::SUBJECT, 'Date subject' );

		$this->assertSame( 'Date subject', $this->module()->get_email_field( $dates[0], 'confirmation', 'subject', 'SITE' ) );
		$this->assertSame( 'Main opening lines', $this->module()->get_email_field( $dates[0], 'confirmation', 'intro', 'SITE' ) );
	}

	public function test_off_switch_inherits_and_overrides() {
		list( $main, $dates ) = $this->group();
		$date = $dates[0];

		$this->assertTrue( $this->module()->is_email_enabled( $date, 'reminder' ), 'On by default.' );

		update_post_meta( $main, '_anchor_event_email_off_reminder', '1' );
		$this->assertFalse( $this->module()->is_email_enabled( $date, 'reminder' ), 'A date with no row follows the main event.' );

		delete_post_meta( $main, '_anchor_event_email_off_reminder' );
		update_post_meta( $date, '_anchor_event_email_off_reminder', '1' );
		$this->assertFalse( $this->module()->is_email_enabled( $date, 'reminder' ), 'A date\'s own "1" turns it off.' );
		$this->assertTrue( $this->module()->is_email_enabled( $main, 'reminder' ), '…for that date only.' );

		// A date can also be switched back ON while its main event is off.
		update_post_meta( $main, '_anchor_event_email_off_reminder', '1' );
		update_post_meta( $date, '_anchor_event_email_off_reminder', '0' );
		$this->assertTrue( $this->module()->is_email_enabled( $date, 'reminder' ) );
	}

	public function test_sender_headers_inherit() {
		list( $main, $dates ) = $this->group();
		update_post_meta( $main, '_anchor_event_email_reply_to_address', 'help@example.test' );
		update_post_meta( $main, '_anchor_event_email_bcc', 'archive@example.test' );

		$headers = $this->module()->email_headers( [], $dates[0] );
		$this->assertContains( 'Reply-To: help@example.test', $headers );
		$this->assertContains( 'Bcc: archive@example.test', $headers );
	}

	public function test_cta_inherits_and_main_can_still_remove_the_button() {
		list( $main, $dates ) = $this->group();
		update_post_meta( $main, '_anchor_event_email_cta2_label_confirmation', 'Book the hotel' );
		update_post_meta( $main, '_anchor_event_email_cta2_url_confirmation', 'https://hotel.example.test' );
		$this->assertSame(
			[ 'label' => 'Book the hotel', 'url' => 'https://hotel.example.test' ],
			$this->module()->get_email_cta( $dates[0], 'confirmation', 2 )
		);

		// The main event clearing slot 1 is still "deliberately no button", and
		// its dates follow it.
		update_post_meta( $main, '_anchor_event_email_cta_label_confirmation', '' );
		$this->assertSame( '', $this->module()->get_email_cta( $dates[0], 'confirmation', 1, [ 'label' => 'View', 'url' => 'https://x.test' ] )['label'] );
		$this->assertSame( '', $this->module()->get_email_cta( $main, 'confirmation', 1, [ 'label' => 'View', 'url' => 'https://x.test' ] )['label'] );
	}

	public function test_template_inherits() {
		list( $main, $dates ) = $this->group();
		update_post_meta( $main, '_anchor_event_email_tpl_confirmation', '<div id="main-tpl">{intro}</div>' );

		$this->assertStringContainsString( 'main-tpl', $this->module()->resolve_email_template( 'confirmation', $dates[0] ) );
	}

	/* ------------------------------------------------------------------ saves */

	public function test_main_save_no_longer_touches_dates() {
		list( $main, $dates ) = $this->group();
		update_post_meta( $dates[0], self::SUBJECT, 'Date subject' );
		update_post_meta( $dates[0], '_anchor_event_email_from_name', 'Date Desk' );

		$this->console_save( $main, [
			'anchor_event_type'                       => 'offering',
			'anchor_event_registration_mode'          => 'free',
			'anchor_event_offering_dates'             => [ [ 'date' => '2027-05-04', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session 0', 'capacity' => 10 ] ],
			'anchor_event_email_subject_confirmation' => '',
			'anchor_event_sender_present'             => '1',
			'anchor_event_email_from_name'            => '',
		] );

		$this->assertSame( 'Date subject', get_post_meta( $dates[0], self::SUBJECT, true ), 'The date\'s override survives a main-event save.' );
		$this->assertSame( 'Date subject', $this->module()->get_email_field( $dates[0], 'confirmation', 'subject', 'SITE' ) );
		$this->assertSame( 'Date Desk', get_post_meta( $dates[0], '_anchor_event_email_from_name', true ) );
	}

	public function test_main_save_does_not_copy_rows_down() {
		list( $main, $dates ) = $this->group();
		update_post_meta( $main, self::SUBJECT, 'Main subject' );
		$this->module()->occurrences->reconcile( $main );

		$this->assertFalse( metadata_exists( 'post', $dates[0], self::SUBJECT ), 'Nothing is copied; the date reads the main event at send time.' );
	}

	public function test_date_save_empty_reverts_to_inherit() {
		list( $main, $dates ) = $this->group();
		$date = $dates[0];
		update_post_meta( $main, self::SUBJECT, 'Main subject' );
		update_post_meta( $date, self::SUBJECT, 'Date subject' );
		update_post_meta( $date, '_anchor_event_email_reply_to_address', 'date@example.test' );

		$this->console_save( $date, [
			'anchor_event_registration_mode'          => 'free',
			'anchor_event_email_subject_confirmation' => '',
			'anchor_event_email_intro_confirmation'   => 'Main subject is not this',
			'anchor_event_sender_present'             => '1',
			'anchor_event_email_reply_to_address'     => '',
		] );

		$this->assertFalse( metadata_exists( 'post', $date, self::SUBJECT ), 'An empty input deletes the date\'s own row.' );
		$this->assertSame( 'Main subject', $this->module()->get_email_field( $date, 'confirmation', 'subject', 'SITE' ) );
		$this->assertFalse( metadata_exists( 'post', $date, '_anchor_event_email_reply_to_address' ) );
		$this->assertSame( 'Main subject is not this', get_post_meta( $date, self::INTRO, true ) );

		// A typed value equal to the main event's is still stored: the person typed it.
		$this->console_save( $date, [
			'anchor_event_registration_mode'          => 'free',
			'anchor_event_email_subject_confirmation' => 'Main subject',
		] );
		$this->assertSame( 'Main subject', get_post_meta( $date, self::SUBJECT, true ) );
	}

	public function test_date_save_of_inherited_template_stores_no_copy() {
		list( $main, $dates ) = $this->group();
		$date = $dates[0];
		update_post_meta( $main, '_anchor_event_email_tpl_confirmation', '<div id="main-tpl">{intro}</div>' );
		update_post_meta( $main, '_anchor_event_email_cta2_label_confirmation', 'Book the hotel' );

		// The editor posts back exactly what it was pre-filled with.
		$this->console_save( $date, [
			'anchor_event_registration_mode'           => 'free',
			'anchor_email_tpl_confirmation'            => $this->module()->resolve_email_template( 'confirmation', $date ),
			'anchor_event_email_cta_present'           => '1',
			'anchor_event_email_cta2_label_confirmation' => '',
			'anchor_event_email_switches_present'      => '1',
			'anchor_event_email_on_confirmation'       => '1',
			'anchor_event_email_on_reminder'           => '1',
			'anchor_event_email_on_cancellation'       => '1',
			'anchor_event_email_on_roster'             => '1',
		] );

		foreach ( [ '_anchor_event_email_tpl_confirmation', '_anchor_event_email_cta2_label_confirmation', '_anchor_event_email_off_confirmation' ] as $key ) {
			$this->assertFalse( metadata_exists( 'post', $date, $key ), $key . ' must stay inherited.' );
		}
		$this->assertSame( 'Book the hotel', $this->module()->get_email_cta( $date, 'confirmation', 2 )['label'] );
	}

	/* ------------------------------------------------------------------ editor */

	public function test_date_editor_field_empty_with_main_as_placeholder() {
		list( $main, $dates ) = $this->group();
		$date = $dates[0];
		update_post_meta( $main, self::SUBJECT, 'Main subject' );
		update_post_meta( $main, '_anchor_event_email_from_name', 'Main Desk' );
		update_post_meta( $date, self::INTRO, '<p>Date opening</p>' );

		$html = $this->render_form( $date );

		$this->assertMatchesRegularExpression(
			'#name="anchor_event_email_subject_confirmation"\s+value=""\s+placeholder="Main subject"#',
			$html,
			'An inherited field is empty, with the main event\'s value as its placeholder.'
		);
		$this->assertMatchesRegularExpression( '#name="anchor_event_email_from_name"\s+value=""\s+placeholder="Main Desk"#', $html );
		$this->assertStringContainsString( '&lt;p&gt;Date opening&lt;/p&gt;</textarea>', $html, 'An override shows its own value.' );

		$this->assertMatchesRegularExpression(
			'#<button[^>]*aria-label="Reset to main event"[^>]*data-reset-for="anchor_event_email_intro_confirmation"#',
			$html,
			'An overridden field has the reset button.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'#data-reset-for="anchor_event_email_intro_confirmation"[^>]*hidden#',
			$html,
			'…and it is visible.'
		);
		$this->assertMatchesRegularExpression(
			'#data-reset-for="anchor_event_email_subject_confirmation"[^>]*hidden#',
			$html,
			'An inherited field\'s reset button is hidden until there is something to reset.'
		);
	}

	public function test_main_editor_is_unchanged() {
		$event = $this->make_event();
		update_post_meta( $event, self::SUBJECT, 'Standalone subject' );

		$html = $this->render_form( $event );
		$this->assertMatchesRegularExpression( '#name="anchor_event_email_subject_confirmation"\s+value="Standalone subject"#', $html );
		$this->assertStringNotContainsString( 'Reset to main event', $html );
	}

	/* ------------------------------------------------------------------ send */

	public function test_confirmation_send_uses_inherited_wording() {
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), [ 'notify_user' => true, 'notify_admin' => false ] ), false );
		list( $main, $dates ) = $this->group();
		update_post_meta( $main, self::INTRO, 'Welcome from the main event' );
		update_post_meta( $main, self::SUBJECT, 'Main event booked' );
		$seat = $this->make_seat( $dates[0], [ 'email' => 'guest@example.test' ] );
		$this->sent = [];

		$this->module()->send_confirmation_email( $dates[0], 'Guest', 'guest@example.test', 'confirmed', 0, $seat );

		$this->assertNotEmpty( $this->sent );
		$mail = end( $this->sent );
		$this->assertSame( 'Main event booked', $mail['subject'] );
		$this->assertStringContainsString( 'Welcome from the main event', $mail['message'] );
	}

	/* ------------------------------------------------------------------ migration */

	public function test_migration_removes_copies_keeps_overrides_lifts_agreed() {
		list( $main, $dates ) = $this->group( 2 );
		list( $a, $b )        = $dates;

		// 1. A sync copy on both dates: removed.
		update_post_meta( $main, self::SUBJECT, 'Same' );
		update_post_meta( $a, self::SUBJECT, 'Same' );
		update_post_meta( $b, self::SUBJECT, 'Same' );

		// 2. A real override on one date: kept; the other date's copy goes.
		update_post_meta( $main, self::INTRO, 'Main intro' );
		update_post_meta( $a, self::INTRO, 'Different' );
		update_post_meta( $b, self::INTRO, 'Main intro' );

		// 3. Main has none, every date agrees: lifted to the main event (backslash intact).
		update_post_meta( $a, '_anchor_event_email_preheader_confirmation', wp_slash( 'Suite C:\Rooms' ) );
		update_post_meta( $b, '_anchor_event_email_preheader_confirmation', wp_slash( 'Suite C:\Rooms' ) );

		// 4. Main has none, dates disagree: both kept.
		update_post_meta( $a, '_anchor_event_email_from_name', 'Desk A' );
		update_post_meta( $b, '_anchor_event_email_from_name', 'Desk B' );

		$result = $this->inheritance()->migrate();

		$this->assertFalse( metadata_exists( 'post', $a, self::SUBJECT ) );
		$this->assertFalse( metadata_exists( 'post', $b, self::SUBJECT ) );
		$this->assertSame( 'Same', get_post_meta( $main, self::SUBJECT, true ) );

		$this->assertSame( 'Different', get_post_meta( $a, self::INTRO, true ) );
		$this->assertFalse( metadata_exists( 'post', $b, self::INTRO ) );

		$this->assertSame( 'Suite C:\Rooms', get_post_meta( $main, '_anchor_event_email_preheader_confirmation', true ) );
		$this->assertFalse( metadata_exists( 'post', $a, '_anchor_event_email_preheader_confirmation' ) );
		$this->assertFalse( metadata_exists( 'post', $b, '_anchor_event_email_preheader_confirmation' ) );

		$this->assertSame( 'Desk A', get_post_meta( $a, '_anchor_event_email_from_name', true ) );
		$this->assertSame( 'Desk B', get_post_meta( $b, '_anchor_event_email_from_name', true ) );
		$this->assertFalse( metadata_exists( 'post', $main, '_anchor_event_email_from_name' ) );

		$this->assertSame( 3, $result['removed'], 'Two subject copies and one intro copy.' );
		$this->assertSame( 1, $result['lifted'] );
		$this->assertSame( 3, $result['kept'], 'One intro override and two disagreeing From names.' );

		$snapshot = [ get_post_meta( $main ), get_post_meta( $a ), get_post_meta( $b ) ];
		$again    = $this->inheritance()->migrate();
		$this->assertSame( 0, $again['removed'] );
		$this->assertSame( 0, $again['lifted'] );
		$this->assertSame( $snapshot, [ get_post_meta( $main ), get_post_meta( $a ), get_post_meta( $b ) ], 'A second run changes nothing.' );
	}

	public function test_migration_dry_run_writes_nothing() {
		list( $main, $dates ) = $this->group();
		update_post_meta( $main, self::SUBJECT, 'Same' );
		update_post_meta( $dates[0], self::SUBJECT, 'Same' );

		$result = $this->inheritance()->migrate( true );

		$this->assertSame( 1, $result['removed'] );
		$this->assertSame( 'Same', get_post_meta( $dates[0], self::SUBJECT, true ) );
	}

	public function test_manual_run_ignores_the_option_and_dry_run_writes_nothing() {
		list( $main, $dates ) = $this->group();
		update_post_meta( $main, self::SUBJECT, 'Same' );
		update_post_meta( $dates[0], self::SUBJECT, 'Same' );
		update_option( Email_Inheritance::MIGRATION_OPTION, [ 'time' => 'deferred' ], false );

		$dry = $this->inheritance()->run_manual( true );
		$this->assertSame( 1, $dry['removed'] );
		$this->assertSame( 'Same', get_post_meta( $dates[0], self::SUBJECT, true ) );
		$this->assertSame( [ 'time' => 'deferred' ], get_option( Email_Inheritance::MIGRATION_OPTION ), 'Dry run leaves the option alone.' );

		$real = $this->inheritance()->run_manual();
		$this->assertSame( 1, $real['removed'] );
		$this->assertFalse( metadata_exists( 'post', $dates[0], self::SUBJECT ) );
		$opt = get_option( Email_Inheritance::MIGRATION_OPTION );
		$this->assertNotSame( 'deferred', $opt['time'] );
		$this->assertSame( 1, $opt['removed'] );
	}

	public function test_migration_runs_once_behind_its_option() {
		list( $main, $dates ) = $this->group();
		update_post_meta( $main, self::SUBJECT, 'Same' );
		update_post_meta( $dates[0], self::SUBJECT, 'Same' );

		$this->inheritance()->maybe_migrate();
		$this->assertFalse( metadata_exists( 'post', $dates[0], self::SUBJECT ) );
		$this->assertNotEmpty( get_option( Email_Inheritance::MIGRATION_OPTION ) );

		// A copy made after the flag is set is not touched by admin_init again.
		update_post_meta( $dates[0], self::SUBJECT, 'Same' );
		$this->inheritance()->maybe_migrate();
		$this->assertSame( 'Same', get_post_meta( $dates[0], self::SUBJECT, true ) );
	}
}
