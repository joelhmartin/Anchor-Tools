<?php
/**
 * Livestream attendance: who was in the /live/ room, per session.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Entitlements;
use Anchor\Events\Module;
use Anchor\Events\Room_Attendance;

/**
 * @group room
 */
class Test_Room_Attendance extends Anchor_Events_TestCase {

	private $start;

	private function att() {
		return $this->module()->room_attendance;
	}

	/** A multisession event: session 0 live now-ish, session 1 in person. */
	private function event() {
		$this->start = strtotime( '2030-03-01 08:00:00 UTC' );
		$event_id    = $this->make_event( [
			'type'                => 'multisession',
			'registration_mode'   => 'free',
			'access_role_enabled' => true,
			'timezone'            => 'UTC',
			'stream_embed'        => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/event/1/embed' ],
		] );
		update_post_meta( $event_id, '_anchor_event_sessions', [
			[ 'date' => '2030-03-01', 'start_time' => '08:00', 'end_time' => '12:00', 'label' => 'Session 1', 'modality' => 'virtual' ],
			[ 'date' => '2030-04-01', 'start_time' => '08:00', 'end_time' => '17:00', 'label' => 'Bootcamp', 'modality' => 'in_person' ],
		] );
		$this->att()->ensure_table();
		return $event_id;
	}

	private function seconds( $event_id, $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT seconds FROM ' . Room_Attendance::table() . ' WHERE event_id = %d AND user_id = %d',
			$event_id,
			$user_id
		) );
	}

	public function test_first_ping_opens_the_row_and_later_pings_add_the_gap() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$this->assertTrue( $this->att()->record( $event_id, 0, $user_id, $this->start + 60 ) );
		$this->assertSame( 0, $this->seconds( $event_id, $user_id ) );
		$this->att()->record( $event_id, 0, $user_id, $this->start + 120 );
		$this->assertSame( 60, $this->seconds( $event_id, $user_id ) );
	}

	public function test_a_long_gap_means_they_left_and_earns_nothing() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$this->att()->record( $event_id, 0, $user_id, $this->start );
		$this->att()->record( $event_id, 0, $user_id, $this->start + Room_Attendance::MAX_CREDIT_SECONDS + 1 );
		$this->assertSame( 0, $this->seconds( $event_id, $user_id ) );
	}

	public function test_a_second_tab_cannot_add_time() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		foreach ( [ 0, 30, 30, 60, 60 ] as $offset ) {
			$this->att()->record( $event_id, 0, $user_id, $this->start + $offset );
		}
		$this->assertSame( 60, $this->seconds( $event_id, $user_id ) );
	}

	public function test_time_outside_the_session_is_not_credited() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$this->att()->record( $event_id, 0, $user_id, $this->start - 120 );
		$this->att()->record( $event_id, 0, $user_id, $this->start - 60 );
		$this->assertSame( 0, $this->seconds( $event_id, $user_id ) );
		$end = $this->start + ( 4 * HOUR_IN_SECONDS );
		$this->att()->record( $event_id, 0, $user_id, $end + 60 );
		$this->att()->record( $event_id, 0, $user_id, $end + 120 );
		$this->assertSame( 0, $this->seconds( $event_id, $user_id ) );
	}

	public function test_report_lists_every_confirmed_seat_and_only_streamed_sessions() {
		$event_id = $this->event();
		$came     = self::factory()->user->create( [ 'user_email' => 'came@example.test' ] );
		$this->make_seat( $event_id, [ 'name' => 'Came', 'email' => 'came@example.test' ] );
		$this->make_seat( $event_id, [ 'name' => 'Absent', 'email' => 'absent@example.test' ] );
		$this->att()->record( $event_id, 0, $came, $this->start );
		$this->att()->record( $event_id, 0, $came, $this->start + 60 );

		$report = $this->att()->report( $event_id );
		$this->assertCount( 1, $report['sessions'], 'The in-person bootcamp is not a streamed session.' );
		$by_email = array_column( $report['people'], null, 'email' );
		$this->assertSame( 60, $by_email['came@example.test']['seconds'] );
		$this->assertSame( 1, $by_email['came@example.test']['joined'] );
		$this->assertSame( 0, $by_email['absent@example.test']['joined'] );
	}

	public function test_staff_without_a_seat_stay_out_of_the_report() {
		$event_id = $this->event();
		$admin    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->att()->record( $event_id, 0, $admin, $this->start );
		$this->assertSame( [], $this->att()->report( $event_id )['people'] );
	}

	public function test_deleting_a_user_deletes_their_attendance() {
		global $wpdb;
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$this->att()->record( $event_id, 0, $user_id, $this->start );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Room_Attendance::table() . ' WHERE user_id = %d', $user_id ) ) );
	}

	public function test_ping_records_only_for_someone_entitled_while_live() {
		$event_id = $this->event();
		$now      = $this->start + 60;
		$clock    = static function () use ( &$now ) {
			return $now;
		};
		add_filter( 'anchor_events_stream_now', $clock );

		$request = new WP_REST_Request( 'POST', '/' . Module::REST_NS . '/events/' . $event_id . '/room/ping' );
		$request->set_param( 'id', $event_id );

		$stranger = self::factory()->user->create();
		wp_set_current_user( $stranger );
		$this->assertInstanceOf( WP_Error::class, $this->att()->rest_ping( $request ) );

		$attendee = self::factory()->user->create( [ 'user_email' => 'seat@example.test' ] );
		$seat     = $this->make_seat( $event_id, [ 'name' => 'Seat', 'email' => 'seat@example.test' ] );
		update_post_meta( $seat, Entitlements::SEAT_USER_META, $attendee );
		$this->module()->entitlements->grant( $event_id, $attendee, Entitlements::SOURCE_SEAT );
		wp_set_current_user( $attendee );
		$this->assertTrue( $this->att()->rest_ping( $request )->get_data()['recorded'] );

		$now  = $this->start + ( 20 * DAY_IN_SECONDS );
		$data = $this->att()->rest_ping( $request )->get_data();
		$this->assertFalse( $data['recorded'], 'Outside a live window nothing is written.' );
		$this->assertSame( 'ended', $data['state'] );

		remove_filter( 'anchor_events_stream_now', $clock );
	}
}
