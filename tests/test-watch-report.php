<?php
/**
 * Livestream watch report: room attendance e-mailed to the organizer list.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Watch_Report;

/**
 * @group room
 */
class Test_Watch_Report extends Anchor_Events_TestCase {

	/** @var array[] wp_mail() atts captured by pre_wp_mail. */
	private $mails = [];

	/** @var bool[] Whether each captured attachment existed when wp_mail() ran. */
	private $attachment_existed = [];

	private $s1;
	private $s2;

	public function set_up() {
		parent::set_up();
		$this->mails              = [];
		$this->attachment_existed = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		delete_option( Module::OPTION_KEY );
		parent::tear_down();
	}

	public function capture_mail( $short, $atts ) {
		$this->mails[] = $atts;
		foreach ( (array) ( $atts['attachments'] ?? [] ) as $file ) {
			$this->attachment_existed[] = is_file( $file );
		}
		return true;
	}

	private function wr(): Watch_Report {
		return $this->module()->watch_report;
	}

	private function settings( array $over ) {
		$opts = array_merge( $this->module()->get_settings(), $over );
		update_option( Module::OPTION_KEY, $opts );
	}

	/** Two streamed sessions a week apart and an in-person day, with an organizer list. */
	private function event() {
		$this->s1 = strtotime( '2030-03-01 08:00:00 UTC' );
		$this->s2 = strtotime( '2030-03-08 08:00:00 UTC' );
		$event_id = $this->make_event( [
			'title'               => 'Hybrid Course',
			'type'                => 'multisession',
			'registration_mode'   => 'free',
			'access_role_enabled' => true,
			'timezone'            => 'UTC',
			'organizer_email'     => 'education@example.test, ops@example.test',
			'stream_embed'        => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://vimeo.com/event/1/embed' ],
			'start_ts'            => $this->s1,
			'end_ts'              => strtotime( '2030-04-01 17:00:00 UTC' ),
		] );
		update_post_meta( $event_id, '_anchor_event_sessions', [
			[ 'date' => '2030-03-01', 'start_time' => '08:00', 'end_time' => '12:00', 'label' => 'Session 1', 'modality' => 'virtual' ],
			[ 'date' => '2030-03-08', 'start_time' => '08:00', 'end_time' => '12:00', 'label' => 'Session 2', 'modality' => 'virtual' ],
			[ 'date' => '2030-04-01', 'start_time' => '08:00', 'end_time' => '17:00', 'label' => 'Bootcamp', 'modality' => 'in_person' ],
		] );
		$this->module()->room_attendance->ensure_table();

		$came = self::factory()->user->create( [ 'user_email' => 'came@example.test' ] );
		$this->make_seat( $event_id, [ 'name' => 'Dr Came', 'email' => 'came@example.test' ] );
		$this->make_seat( $event_id, [ 'name' => 'Dr Absent', 'email' => 'absent@example.test' ] );
		$att = $this->module()->room_attendance;
		$att->record( $event_id, 0, $came, $this->s1 );
		$att->record( $event_id, 0, $came, $this->s1 + 60 );
		return $event_id;
	}

	private function after( $session_start ) {
		return $session_start + 4 * HOUR_IN_SECONDS + Watch_Report::GRACE_SECONDS + 60;
	}

	public function test_end_timing_waits_for_the_last_streamed_session_then_sends_once() {
		$event_id = $this->event();

		$this->assertSame( [], $this->wr()->run_due( $this->after( $this->s1 ) ), 'Session 2 has not happened yet.' );
		$this->assertSame( [], $this->wr()->run_due( $this->s2 + 4 * HOUR_IN_SECONDS + 60 ), 'Still inside the grace hour.' );

		$this->assertSame( [ $event_id ], $this->wr()->run_due( $this->after( $this->s2 ) ) );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( [], $this->wr()->run_due( $this->after( $this->s2 ) + HOUR_IN_SECONDS ), 'Only once.' );
		$this->assertCount( 1, $this->mails );

		$mail = $this->mails[0];
		$this->assertStringContainsString( 'education@example.test', (string) $mail['to'] );
		$this->assertStringContainsString( 'ops@example.test', (string) $mail['to'] );
		$this->assertSame( 'Watch report: Hybrid Course', $mail['subject'] );
		$this->assertStringContainsString( 'Dr Came (came@example.test) — 1 of 2 sessions, 1m in the room', $mail['message'] );
		$this->assertStringContainsString( 'Dr Absent (absent@example.test) — not recorded in the room', $mail['message'] );
		$this->assertStringContainsString( 'not proof of watching', $mail['message'] );
		$this->assertStringNotContainsString( 'Bootcamp', $mail['message'], 'The in-person day is not a streamed session.' );
		$this->assertSame( [ true ], $this->attachment_existed, 'The CSV is on disk while wp_mail() runs.' );
		$this->assertFileDoesNotExist( $mail['attachments'][0], 'And removed afterwards.' );
	}

	public function test_each_timing_sends_after_every_session() {
		$event_id = $this->event();
		$this->settings( [ 'watch_report_timing' => 'each' ] );

		$this->assertSame( [ $event_id ], $this->wr()->run_due( $this->after( $this->s1 ) ) );
		$this->assertStringStartsWith( 'Watch report so far:', $this->mails[0]['subject'] );
		$this->assertStringContainsString( '1 of 1 sessions', $this->mails[0]['message'], 'Session 2 has not happened, so it does not count yet.' );

		$this->assertSame( [], $this->wr()->run_due( $this->after( $this->s1 ) + HOUR_IN_SECONDS ) );
		$this->assertSame( [ $event_id ], $this->wr()->run_due( $this->after( $this->s2 ) ) );
		$this->assertSame( 'Watch report: Hybrid Course', $this->mails[1]['subject'] );
		$this->assertCount( 2, $this->mails );
	}

	public function test_nothing_for_a_session_that_ended_days_ago() {
		$this->event();
		$this->assertSame( [], $this->wr()->run_due( $this->s2 + Watch_Report::LOOKBACK_SECONDS + DAY_IN_SECONDS ) );
		$this->assertSame( [], $this->mails );
	}

	public function test_switched_off_sends_nothing() {
		$this->event();
		$this->settings( [ 'watch_report_email' => false ] );
		$this->assertSame( [], $this->wr()->run_due( $this->after( $this->s2 ) ) );
		$this->assertSame( [], $this->mails );
	}

	public function test_an_event_without_a_room_is_ignored() {
		$event_id = $this->event();
		update_post_meta( $event_id, '_anchor_event_access_role_enabled', false );
		$this->assertSame( [], $this->wr()->run_due( $this->after( $this->s2 ) ) );
		$this->assertSame( [], $this->mails );
	}

	public function test_send_now_does_not_use_up_the_scheduled_send() {
		$event_id = $this->event();
		$this->assertTrue( $this->wr()->send( $event_id )->is_sent() );
		$this->assertSame( [], $this->wr()->markers( $event_id ) );
		$this->assertSame( [ $event_id ], $this->wr()->run_due( $this->after( $this->s2 ) ) );
	}

	public function test_the_report_never_says_watched() {
		$event_id = $this->event();
		$this->wr()->run_due( $this->after( $this->s2 ) );
		$text = strtolower( wp_strip_all_tags( $this->mails[0]['message'] ) );
		$this->assertStringNotContainsString( 'watched', $text );
		$this->assertStringNotContainsString( 'attended', $text );
	}
}
