<?php
/**
 * Session recordings as Library folders: one per event, a subfolder per online session, closed by date.
 *
 * @package Anchor\Events\Tests
 * @group library
 */

use Anchor\Events\Recording_Folders;
use Anchor\Library\Permissions;
use Anchor\Library\Provisioning;
use Anchor\Library\Repository;
use Anchor\Library\Schema;

class Test_Recording_Folders extends Anchor_Library_TestCase {

	use Anchor_Events_Fixtures;

	public function set_up() {
		parent::set_up();
		Permissions::reset_memo();
		add_filter( 'anchor_library_running', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'anchor_library_running', '__return_true' );
		parent::tear_down();
	}

	private function rf(): Recording_Folders {
		return $this->module()->recording_folders;
	}

	private function event( int $days = 30 ): int {
		$event_id = $this->make_event( [
			'type'                => 'multisession',
			'registration_mode'   => 'free',
			'access_role_enabled' => true,
			'timezone'            => 'UTC',
			'recording_days'      => $days,
		] );
		update_post_meta( $event_id, '_anchor_event_sessions', [
			[ 'date' => '2030-03-01', 'start_time' => '08:00', 'end_time' => '12:00', 'label' => 'Session 1', 'modality' => 'virtual' ],
			[ 'date' => '2030-03-08', 'start_time' => '08:00', 'end_time' => '12:00', 'label' => 'Session 2', 'modality' => 'virtual' ],
			[ 'date' => '2030-04-01', 'start_time' => '', 'end_time' => '', 'label' => 'Bootcamp', 'modality' => 'in_person' ],
		] );
		return $event_id;
	}

	private function session_folders( int $event_id ): array {
		$map = get_post_meta( $event_id, Recording_Folders::META_SESSIONS, true );
		return is_array( $map ) ? $map : [];
	}

	private function folder_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . Schema::table( 'folders' ) );
	}

	private function add_video( int $folder ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'videos' ), [
			'folder_id' => $folder, 'vimeo_id' => '123', 'title' => 'Clip', 'created_by' => 0,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	public function test_creates_a_folder_for_the_event_and_one_per_online_session() {
		$event_id = $this->event();

		$folder = $this->rf()->sync( $event_id );

		$this->assertGreaterThan( 0, $folder );
		$map = $this->session_folders( $event_id );
		$this->assertSame( [ '2030-03-01', '2030-03-08' ], array_keys( $map ), 'The in-person bootcamp gets no folder.' );
		$this->assertSame( '2030-03-31', Provisioning::available_until( $map['2030-03-01']['folder'] ) );
		$this->assertSame( '2030-04-07', Provisioning::available_until( $map['2030-03-08']['folder'] ) );
		$this->assertSame( $folder, (int) Repository::folder_row( $map['2030-03-01']['folder'] )->parent_id );
	}

	public function test_the_course_role_opens_it_and_nobody_else_does() {
		$event_id = $this->event();
		$folder   = $this->rf()->sync( $event_id );
		$map      = $this->session_folders( $event_id );

		$attendee = self::factory()->user->create( [ 'role' => 'customer' ] );
		get_user_by( 'id', $attendee )->add_role( $this->module()->entitlements->role_for( $event_id ) );
		$stranger = self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertTrue( Permissions::can_view_folder( $folder, $attendee ) );
		$this->assertTrue( Permissions::can_view_folder( $map['2030-03-01']['folder'], $attendee ) );
		$this->assertFalse( Permissions::can_view_folder( $folder, $stranger ) );
		$this->assertFalse( Permissions::can_view_folder( $map['2030-03-01']['folder'], $stranger ) );
	}

	public function test_running_it_again_creates_nothing_new() {
		$event_id = $this->event();
		$this->rf()->sync( $event_id );
		$before = $this->folder_count();
		$first  = $this->session_folders( $event_id );

		$this->rf()->sync( $event_id );

		$this->assertSame( $before, $this->folder_count() );
		$this->assertSame( $first, $this->session_folders( $event_id ) );
	}

	public function test_off_at_zero_days_and_when_the_library_is_not_running() {
		$before = $this->folder_count();
		$this->assertSame( 0, $this->rf()->sync( $this->event( 0 ) ) );

		remove_filter( 'anchor_library_running', '__return_true' );
		add_filter( 'anchor_library_running', '__return_false' );
		$this->assertSame( 0, $this->rf()->sync( $this->event( 30 ) ) );
		remove_filter( 'anchor_library_running', '__return_false' );

		$this->assertSame( $before, $this->folder_count() );
	}

	public function test_a_date_changed_by_hand_survives_but_the_rest_follow_a_new_day_count() {
		$event_id = $this->event();
		$this->rf()->sync( $event_id );
		$map = $this->session_folders( $event_id );
		Provisioning::set_available_until( $map['2030-03-01']['folder'], '2031-01-01' );

		update_post_meta( $event_id, '_anchor_event_recording_days', 10 );
		$this->rf()->sync( $event_id );

		$this->assertSame( '2031-01-01', Provisioning::available_until( $map['2030-03-01']['folder'] ) );
		$this->assertSame( '2030-03-18', Provisioning::available_until( $map['2030-03-08']['folder'] ) );

		$this->rf()->sync( $event_id );
		$this->rf()->sync( $event_id );
		$this->assertSame( '2031-01-01', Provisioning::available_until( $map['2030-03-01']['folder'] ), 'A date changed by hand must survive every later save, not just the next one.' );
	}

	public function test_zero_days_closes_access_and_turning_it_back_on_restores_it() {
		$event_id = $this->event();
		$folder   = $this->rf()->sync( $event_id );
		$attendee = self::factory()->user->create( [ 'role' => 'customer' ] );
		get_user_by( 'id', $attendee )->add_role( $this->module()->entitlements->role_for( $event_id ) );
		$this->assertTrue( Permissions::can_view_folder( $folder, $attendee ) );

		update_post_meta( $event_id, '_anchor_event_recording_days', 0 );
		$this->rf()->sync( $event_id );
		Permissions::reset_memo();
		$this->assertFalse( Permissions::can_view_folder( $folder, $attendee ) );
		$this->assertTrue( Provisioning::folder_exists( $folder ), 'Switching off deletes nothing.' );
		$this->assertSame( '', $this->rf()->url( $event_id ) );

		update_post_meta( $event_id, '_anchor_event_recording_days', 30 );
		$this->assertSame( $folder, $this->rf()->sync( $event_id ) );
		Permissions::reset_memo();
		$this->assertTrue( Permissions::can_view_folder( $folder, $attendee ) );
	}

	public function test_a_date_of_an_offering_gets_folders_once_its_meta_has_been_written() {
		$parent_id = $this->make_event( [
			'type'                    => 'offering',
			'timezone'                => 'UTC',
			'access_role_enabled'     => true,
			'stream_default_modality' => 'virtual',
			'recording_days'          => 30,
		] );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2030-06-01', 'start_time' => '09:00', 'end_time' => '12:00', 'label' => '' ],
		] );

		$live = $this->module()->occurrences->reconcile( $parent_id );

		$this->assertCount( 1, $live );
		$map = $this->session_folders( (int) $live[0] );
		$this->assertArrayHasKey( '2030-06-01', $map, 'The child must be synced after its date and shared meta are written.' );
		$this->assertSame( '2030-07-01', Provisioning::available_until( $map['2030-06-01']['folder'] ) );

		update_post_meta( $parent_id, '_anchor_event_recording_days', 10 );
		$this->module()->occurrences->reconcile( $parent_id );
		$this->assertSame( '2030-06-11', Provisioning::available_until( $map['2030-06-01']['folder'] ), 'A new day count on the main event reaches its dates.' );
	}

	public function test_portal_page_is_found_without_woocommerce() {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[anchor_account_portal]' ] );
		$this->assertSame( get_permalink( $page ), Provisioning::portal_page_url() );
	}

	public function test_a_session_off_the_schedule_loses_an_empty_folder_and_keeps_a_full_one() {
		$event_id = $this->event();
		$this->rf()->sync( $event_id );
		$map = $this->session_folders( $event_id );
		$this->add_video( $map['2030-03-08']['folder'] );

		update_post_meta( $event_id, '_anchor_event_sessions', [
			[ 'date' => '2030-05-01', 'start_time' => '08:00', 'end_time' => '12:00', 'label' => 'Session 3', 'modality' => 'virtual' ],
		] );
		$this->rf()->sync( $event_id );

		$after = $this->session_folders( $event_id );
		$this->assertFalse( Provisioning::folder_exists( $map['2030-03-01']['folder'] ) );
		$this->assertTrue( Provisioning::folder_exists( $map['2030-03-08']['folder'] ) );
		$this->assertSame( [ '2030-03-08', '2030-05-01' ], array_keys( $after ) );
	}

	public function test_two_events_with_one_title_get_their_own_folders() {
		$a = $this->event();
		$b = $this->event();
		wp_update_post( [ 'ID' => $a, 'post_title' => 'Same' ] );
		wp_update_post( [ 'ID' => $b, 'post_title' => 'Same' ] );
		delete_post_meta( $a, Recording_Folders::META_FOLDER );
		delete_post_meta( $b, Recording_Folders::META_FOLDER );
		delete_post_meta( $a, Recording_Folders::META_SESSIONS );
		delete_post_meta( $b, Recording_Folders::META_SESSIONS );

		$this->assertNotSame( $this->rf()->sync( $a ), $this->rf()->sync( $b ) );
	}

	public function test_room_link_points_at_the_event_folder() {
		$event_id = $this->event();
		$folder   = $this->rf()->sync( $event_id );
		add_filter( 'anchor_events_recordings_portal_url', static fn() => 'https://example.test/my-account/' );

		$this->assertSame( 'https://example.test/my-account/#afm-folder-' . $folder, $this->rf()->url( $event_id ) );
		$this->assertStringContainsString( '30 days', $this->rf()->render_room_link( $event_id ) );

		remove_all_filters( 'anchor_events_recordings_portal_url' );
	}

	public function test_report_lists_what_registrants_played_and_leaves_staff_out() {
		global $wpdb;
		$event_id = $this->event();
		$this->rf()->sync( $event_id );
		$map   = $this->session_folders( $event_id );
		$video = $this->add_video( $map['2030-03-01']['folder'] );

		$seated = self::factory()->user->create( [ 'user_email' => 'seated@example.test' ] );
		$this->make_seat( $event_id, [ 'name' => 'Seated', 'email' => 'seated@example.test' ] );
		$staff = self::factory()->user->create( [ 'role' => 'administrator' ] );
		foreach ( [ [ $seated, 72 ], [ $staff, 100 ] ] as [ $user, $percent ] ) {
			$wpdb->insert( Schema::table( 'video_views' ), [
				'source' => 'vimeo', 'video_id' => $video, 'user_id' => $user, 'percent' => $percent,
				'first_viewed_at' => current_time( 'mysql' ), 'last_viewed_at' => current_time( 'mysql' ),
			] );
		}

		$report = $this->rf()->report( $event_id );

		$this->assertCount( 1, $report );
		$this->assertSame( '2030-03-01', $report[0]['session'] );
		$this->assertSame( [ 'seated@example.test' ], array_column( $report[0]['views'], 'email' ) );
		$this->assertSame( 72, $report[0]['views'][0]['percent'] );
		$this->assertStringContainsString( 'Seated: 72%', $this->rf()->render_panel( $event_id ) );
	}

	public function test_until_date() {
		$this->assertSame( '2027-03-07', Recording_Folders::until_date( '2027-02-05', 30 ) );
		$this->assertSame( '', Recording_Folders::until_date( 'nonsense', 30 ) );
	}
}
