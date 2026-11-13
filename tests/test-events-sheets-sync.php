<?php
/**
 * Sheets_Sync: targets, debounce and the run (in-memory adapter, no HTTP).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Sheets_Sync;
use Anchor\Events\Registrations;

/** In-memory spreadsheet. */
class Anchor_Fake_Sheet_Adapter implements Anchor_Sheet_Adapter {
	public $grid = array();
	public $fail = null;
	public $reads = 0;

	public function resolve( $url ) {
		return array( 'provider' => 'google', 'id' => 'x', 'tab' => 't' );
	}
	public function read( array $ref ) {
		++$this->reads;
		return $this->fail ? $this->fail : $this->grid;
	}
	public function write_cells( array $ref, array $cells ) {
		foreach ( $cells as $c ) {
			list( $r, $col, $v ) = $c;
			$r = $r - 1;
			while ( count( $this->grid ) <= $r ) {
				$this->grid[] = array();
			}
			while ( count( $this->grid[ $r ] ) <= $col ) {
				$this->grid[ $r ][] = '';
			}
			$this->grid[ $r ][ $col ] = (string) $v;
		}
		return true;
	}
	public function append_rows( array $ref, array $rows ) {
		foreach ( $rows as $row ) {
			$this->grid[] = array_map( 'strval', $row );
		}
		return true;
	}
	public function ensure_columns( array $ref, $count ) {
		return true;
	}
}

/** In-memory spreadsheet that also records share() calls. */
class Anchor_Fake_Shareable_Adapter extends Anchor_Fake_Sheet_Adapter {
	public $shares = array();
	public $share_result = null;
	public function share( array $ref, array $emails, $role, $notify ) {
		$this->shares[] = array( 'emails' => $emails, 'role' => $role, 'notify' => $notify );
		return $this->share_result ? $this->share_result : $emails;
	}
}

/** Shareable fake that can also create spreadsheets. */
class Anchor_Fake_Creator_Adapter extends Anchor_Fake_Shareable_Adapter {
	public $creates = array();
	public $create_error = null;
	public $create_warning = '';
	public function create( $title, $folder_id = '' ) {
		if ( $this->create_error ) {
			return $this->create_error;
		}
		$this->creates[] = array( 'title' => $title, 'folder' => $folder_id );
		return array( 'url' => 'https://docs.google.com/spreadsheets/d/NEW/edit', 'ref' => array( 'provider' => 'google', 'id' => 'NEW', 'tab' => 'Sheet1' ), 'warning' => $this->create_warning );
	}
}

/** Adapter whose read() throws (a Throwable mid-sync). */
class Anchor_Fake_Throwing_Adapter extends Anchor_Fake_Sheet_Adapter {
	public function read( array $ref ) {
		throw new \RuntimeException( 'SECRET-IN-MESSAGE' );
	}
}

/**
 * @group sheets
 */
class Test_Events_Sheets_Sync extends Anchor_Events_TestCase {

	private $fake;
	private $http_calls = 0;

	public function set_up() {
		parent::set_up();
		$this->fake       = new Anchor_Fake_Sheet_Adapter();
		$this->http_calls = 0;
		add_filter( 'anchor_sheet_adapter_for_ref', array( $this, 'inject' ), 10, 2 );
		add_filter( 'pre_http_request', array( $this, 'spy' ), 10, 3 );
		delete_option( \Anchor\Events\Events_Log::ERROR_OPTION );
		delete_option( \Anchor_Sheet_Google::SETTINGS_OPTION );
	}

	public function tear_down() {
		remove_filter( 'anchor_sheet_adapter_for_ref', array( $this, 'inject' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'spy' ), 10 );
		_set_cron_array( array() );
		parent::tear_down();
	}

	public function inject( $o, $ref ) {
		return $this->fake;
	}
	public function spy( $pre, $args, $url ) {
		++$this->http_calls;
		return new WP_Error( 'blocked', 'no network in tests' );
	}

	private function sync() {
		return $this->module()->sheets_sync;
	}

	private function with_sheet( $event_id ) {
		update_post_meta( $event_id, Sheets_Sync::META_URL, 'https://docs.google.com/spreadsheets/d/x/edit' );
		update_post_meta( $event_id, Sheets_Sync::META_REF, array( 'provider' => 'google', 'id' => 'x', 'tab' => 't' ) );
		return $event_id;
	}

	private function queued( $target ) {
		return wp_next_scheduled( Sheets_Sync::HOOK, array( $target ) );
	}

	private function group() {
		$rows = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$rows[] = array( 'date' => sprintf( '2027-11-%02d', 13 + $i * 7 ), 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'S' . $i, 'capacity' => 10 );
		}
		$main = $this->make_event( array( 'title' => 'Workshop', 'timezone' => 'UTC', 'start_date' => '2027-11-13' ) );
		update_post_meta( $main, '_anchor_event_offering_dates', $rows );
		$dates = array_map( 'intval', $this->module()->occurrences->reconcile( $main ) );
		return array( $main, $dates );
	}

	public function test_target_is_event_then_group_parent_then_zero() {
		list( $main, $dates ) = $this->group();
		$this->assertSame( 0, $this->sync()->target_for_event( $dates[0] ) );
		$this->with_sheet( $main );
		$this->assertSame( $main, $this->sync()->target_for_event( $dates[0] ) );
		$this->with_sheet( $dates[0] );
		$this->assertSame( $dates[0], $this->sync()->target_for_event( $dates[0] ) );
		$this->assertSame( $main, $this->sync()->target_for_event( $main ) );
		$this->assertSame( 0, $this->sync()->target_for_event( $this->make_event() ) );
	}

	public function test_seat_created_schedules_one_sync() {
		$e = $this->with_sheet( $this->make_event() );
		$this->assertFalse( $this->queued( $e ) );
		$this->make_seat( $e );
		$this->assertNotFalse( $this->queued( $e ) );
	}

	public function test_status_change_schedules() {
		$e    = $this->with_sheet( $this->make_event() );
		$seat = $this->make_seat( $e );
		_set_cron_array( array() );
		$this->assertFalse( $this->queued( $e ) );
		do_action( 'anchor_events_seat_status_changed', $seat, 'confirmed', 'cancelled', 'test' );
		$this->assertNotFalse( $this->queued( $e ) );
	}

	public function test_burst_of_seat_changes_queues_one_sync_and_no_http() {
		$e = $this->with_sheet( $this->make_event() );
		$last = 0;
		for ( $i = 0; $i < 5; $i++ ) {
			$last = $this->make_seat( $e, array( 'email' => "a$i@example.test" ) );
		}
		do_action( 'anchor_events_seat_status_changed', $last, 'confirmed', 'cancelled', 'test' );
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( isset( $hooks[ Sheets_Sync::HOOK ] ) ) {
				$count += count( $hooks[ Sheets_Sync::HOOK ] );
			}
		}
		$this->assertSame( 1, $count );
		$this->assertSame( 0, $this->http_calls );
	}

	public function test_event_without_sheet_schedules_nothing() {
		$e = $this->make_event();
		$this->make_seat( $e );
		$this->assertFalse( $this->queued( $e ) );
	}

	public function test_run_backfills_then_updates_cancelled_row_in_place() {
		$e  = $this->with_sheet( $this->make_event() );
		$s1 = $this->make_seat( $e, array( 'email' => 'one@example.test' ) );
		$this->make_seat( $e, array( 'email' => 'two@example.test' ) );

		$state = $this->sync()->run( $e );
		$this->assertTrue( $state['ok'] );
		$this->assertCount( 3, $this->fake->grid, 'header + 2 rows' );
		$header = $this->fake->grid[0];
		$this->assertContains( 'Seat ID', $header );

		$this->registrations()->update_status( $s1, Registrations::STATUS_CANCELLED );
		$state = $this->sync()->run( $e );
		$this->assertTrue( $state['ok'] );
		$this->assertCount( 3, $this->fake->grid, 'still 2 data rows' );
		$sid = array_search( 'Seat ID', $header, true );
		$st  = array_search( 'Status', $header, true );
		$found = null;
		foreach ( array_slice( $this->fake->grid, 1 ) as $row ) {
			if ( (string) $row[ $sid ] === (string) $s1 ) {
				$found = $row;
			}
		}
		$this->assertNotNull( $found );
		$this->assertSame( 'cancelled', strtolower( $found[ $st ] ) );
		$this->assertSame( 2, $this->sync()->get_state( $e )['rows'] );
	}

	public function test_group_parent_sheet_gets_child_seats_with_date_column() {
		list( $main, $dates ) = $this->group();
		$this->with_sheet( $main );
		$this->make_seat( $dates[0], array( 'email' => 'c@example.test' ) );
		$this->assertNotFalse( $this->queued( $main ), 'child seat schedules the parent target' );
		$state = $this->sync()->run( $main );
		$this->assertTrue( $state['ok'], $state['message'] );
		$this->assertSame( 'Date', $this->fake->grid[0][0] );
		$this->assertCount( 2, $this->fake->grid );
	}

	public function test_run_records_failure_state_and_logs_without_secret() {
		$e = $this->with_sheet( $this->make_event() );
		$this->make_seat( $e );
		$this->fake->fail = new WP_Error( 'x', 'Sheet is gone' );
		$state = $this->sync()->run( $e );
		$this->assertFalse( $state['ok'] );
		$this->assertStringContainsString( 'Sheet is gone', $state['message'] );
		$this->assertSame( $state, $this->sync()->get_state( $e ) );
		$log = wp_json_encode( get_option( \Anchor\Events\Events_Log::ERROR_OPTION ) );
		$this->assertStringContainsString( 'sheet_sync_failed', $log );
		$this->assertDoesNotMatchRegularExpression( '/ya29|refresh_token|access_token|Bearer/i', $log );
	}

	public function test_lock_prevents_concurrent_run() {
		$e = $this->with_sheet( $this->make_event() );
		$this->make_seat( $e );
		add_option( 'anchor_ev_sheet_lock_' . $e, time(), '', false );
		$this->sync()->run( $e );
		$this->assertSame( 0, $this->fake->reads );
		update_option( 'anchor_ev_sheet_lock_' . $e, time() - 3600, false ); // Stale: taken over.
		$this->sync()->run( $e );
		$this->assertSame( 1, $this->fake->reads );
		$this->assertFalse( get_option( 'anchor_ev_sheet_lock_' . $e ), 'lock released' );
	}

	public function test_external_signup_event_does_not_sync() {
		$e = $this->with_sheet( $this->make_event( array( 'registration_mode' => 'external' ) ) );
		$this->assertTrue( $this->module()->uses_external_signup( $e ) );
		$this->make_seat( $e );
		$this->assertFalse( $this->queued( $e ) );
		$this->sync()->run( $e );
		$this->assertSame( 0, $this->fake->reads );
	}

	private function shareable( $scope = true ) {
		$this->fake = new Anchor_Fake_Shareable_Adapter();
		update_option( 'anchor_connector_google', array( 'scope' => $scope ? 'openid https://www.googleapis.com/auth/drive' : 'openid https://www.googleapis.com/auth/spreadsheets' ), false );
		foreach ( get_users( array( 'capability' => \Anchor\Events\Module::events_capability() ) ) as $u ) {
			wp_delete_user( $u->ID );
		}
	}

	public function test_run_shares_with_events_capability_holders_plus_extra_emails() {
		$this->shareable();
		$mgr = self::factory()->user->create( array( 'role' => 'shop_manager', 'user_email' => 'Mgr@example.test' ) );
		self::factory()->user->create( array( 'role' => 'subscriber', 'user_email' => 'sub@example.test' ) );
		$o = get_option( 'anchor_connector_google' );
		$o['share_emails'] = array( 'extra@example.test' );
		update_option( 'anchor_connector_google', $o, false );
		$e = $this->with_sheet( $this->make_event() );
		$state = $this->sync()->run( $e );
		$this->assertTrue( $state['ok'] );
		$this->assertSame( '', $state['message'] );
		$this->assertCount( 1, $this->fake->shares );
		$this->assertSame( array( 'extra@example.test', 'mgr@example.test' ), $this->fake->shares[0]['emails'] );
		$this->assertSame( 'writer', $this->fake->shares[0]['role'] );
		$this->assertTrue( $this->fake->shares[0]['notify'] );
		delete_option( 'anchor_connector_google' );
	}

	public function test_run_skips_share_when_set_unchanged() {
		$this->shareable();
		self::factory()->user->create( array( 'role' => 'shop_manager', 'user_email' => 'm@example.test' ) );
		$e = $this->with_sheet( $this->make_event() );
		$this->sync()->run( $e );
		$this->sync()->run( $e );
		$this->assertCount( 1, $this->fake->shares );
		delete_option( 'anchor_connector_google' );
	}

	public function test_new_manager_triggers_share_on_next_run() {
		$this->shareable();
		self::factory()->user->create( array( 'role' => 'shop_manager', 'user_email' => 'm@example.test' ) );
		$e = $this->with_sheet( $this->make_event() );
		$this->sync()->run( $e );
		self::factory()->user->create( array( 'role' => 'shop_manager', 'user_email' => 'new@example.test' ) );
		$this->sync()->run( $e );
		$this->assertCount( 2, $this->fake->shares );
		$this->assertSame( array( 'm@example.test', 'new@example.test' ), $this->fake->shares[1]['emails'] );
		delete_option( 'anchor_connector_google' );
	}

	public function test_share_failure_is_a_warning_not_a_failed_sync() {
		$this->shareable();
		self::factory()->user->create( array( 'role' => 'shop_manager', 'user_email' => 'm@example.test' ) );
		$this->fake->share_result = new WP_Error( 'boom', 'Drive said no' );
		$e = $this->with_sheet( $this->make_event() );
		$this->make_seat( $e );
		$state = $this->sync()->run( $e );
		$this->assertTrue( $state['ok'] );
		$this->assertStringContainsString( 'Drive said no', $state['message'] );
		$this->assertCount( 2, $this->fake->grid, 'rows still written' );
		$this->assertSame( '', (string) get_post_meta( $e, Sheets_Sync::META_SHARED, true ), 'retried next run' );
		delete_option( 'anchor_connector_google' );
	}

	public function test_missing_drive_scope_warns_and_does_not_share() {
		$this->shareable( false );
		self::factory()->user->create( array( 'role' => 'shop_manager', 'user_email' => 'm@example.test' ) );
		$e = $this->with_sheet( $this->make_event() );
		$state = $this->sync()->run( $e );
		$this->assertTrue( $state['ok'] );
		$this->assertSame( 'Reconnect to enable auto-share', $state['message'] );
		$this->assertCount( 0, $this->fake->shares );
		delete_option( 'anchor_connector_google' );
	}

	public function test_share_disabled_does_nothing() {
		$this->shareable();
		$o = get_option( 'anchor_connector_google' );
		$o['share_managers'] = false;
		update_option( 'anchor_connector_google', $o, false );
		$e = $this->with_sheet( $this->make_event() );
		$state = $this->sync()->run( $e );
		$this->assertSame( '', $state['message'] );
		$this->assertCount( 0, $this->fake->shares );
		delete_option( 'anchor_connector_google' );
	}

	/* ---- Auto-create ---- */

	private function autocreate( $on = true, $folder = '' ) {
		$this->fake = new Anchor_Fake_Creator_Adapter();
		update_option( 'anchor_connector_google', array( 'autocreate' => $on, 'autocreate_folder_id' => $folder, 'scope' => 'openid https://www.googleapis.com/auth/drive', 'share_managers' => false ), false );
	}

	private function draft_event( $title = 'Spring Gala' ) {
		return (int) self::factory()->post->create( array( 'post_type' => \Anchor\Events\Module::CPT, 'post_status' => 'draft', 'post_title' => $title ) );
	}

	public function test_first_publish_with_autocreate_marks_pending_and_schedules() {
		$this->autocreate();
		$e = $this->draft_event();
		$this->http_calls = 0;
		wp_update_post( array( 'ID' => $e, 'post_status' => 'publish' ) );
		$this->assertSame( 'pending', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
		$this->assertNotFalse( $this->queued( $e ) );
		$this->assertSame( 0, $this->http_calls );
		$this->assertSame( array(), $this->fake->creates );
	}

	public function test_autocreate_skips_external_signup_group_children_and_events_with_a_url() {
		$this->autocreate();
		$ext = $this->draft_event();
		update_post_meta( $ext, '_anchor_event_registration_mode', 'external' );
		$this->assertTrue( $this->module()->uses_external_signup( $ext ), 'fixture must be external-signup' );
		wp_update_post( array( 'ID' => $ext, 'post_status' => 'publish' ) );
		$this->assertSame( '', get_post_meta( $ext, Sheets_Sync::META_AUTOCREATE, true ) );

		$has = $this->draft_event();
		update_post_meta( $has, Sheets_Sync::META_URL, 'https://docs.google.com/spreadsheets/d/x/edit' );
		wp_update_post( array( 'ID' => $has, 'post_status' => 'publish' ) );
		$this->assertSame( '', get_post_meta( $has, Sheets_Sync::META_AUTOCREATE, true ) );

		// Group children are created by the real reconcile path, which publishes
		// them BEFORE it tags them as children; the run must still never create.
		list( $main, $dates ) = $this->group();
		foreach ( $dates as $child ) {
			$this->assertTrue( $this->module()->occurrences->is_group_child( $child ) );
			$this->sync()->run( $child );
			$this->assertSame( '', get_post_meta( $child, Sheets_Sync::META_AUTOCREATE, true ), 'group child pending cleared' );
		}
		// An event switched to external sign-up after it went pending.
		$late = $this->draft_event();
		wp_update_post( array( 'ID' => $late, 'post_status' => 'publish' ) );
		$this->assertSame( 'pending', get_post_meta( $late, Sheets_Sync::META_AUTOCREATE, true ) );
		update_post_meta( $late, '_anchor_event_registration_mode', 'external' );
		$this->sync()->run( $late );
		$this->assertSame( '', get_post_meta( $late, Sheets_Sync::META_AUTOCREATE, true ) );
		$this->assertSame( array(), $this->fake->creates, 'no spreadsheet for children or external sign-up' );
	}

	public function test_replacement_sheet_is_shared_even_with_same_recipients() {
		$this->shareable();
		self::factory()->user->create( array( 'role' => 'shop_manager', 'user_email' => 'm@example.test' ) );
		$e = $this->with_sheet( $this->make_event() );
		$this->sync()->run( $e );
		update_post_meta( $e, Sheets_Sync::META_REF, array( 'provider' => 'google', 'id' => 'OTHER', 'tab' => 't' ) );
		$this->sync()->run( $e );
		$this->assertCount( 2, $this->fake->shares );
		delete_option( 'anchor_connector_google' );
	}

	public function test_create_is_refused_for_pasted_url_and_group_children() {
		$this->autocreate();
		$e = $this->draft_event();
		update_post_meta( $e, Sheets_Sync::META_URL, 'https://docs.google.com/spreadsheets/d/PASTED/edit' );
		$this->assertFalse( $this->sync()->can_create( $e ) );
		$this->assertSame( array(), $this->sync()->create_now( $e ) );
		list( $main, $dates ) = $this->group();
		$this->assertFalse( $this->sync()->can_create( $dates[0] ) );
		$this->assertSame( array(), $this->sync()->create_now( $dates[0] ) );
		$this->assertTrue( $this->sync()->can_create( $main ) );
		$this->assertSame( array(), $this->fake->creates );
	}

	public function test_pending_with_unresolved_pasted_url_never_creates() {
		$this->autocreate();
		$e = $this->draft_event();
		update_post_meta( $e, Sheets_Sync::META_AUTOCREATE, 'pending' );
		update_post_meta( $e, Sheets_Sync::META_URL, 'https://docs.google.com/spreadsheets/d/PASTED/edit' );
		$this->sync()->run( $e );
		$this->assertSame( array(), $this->fake->creates );
		$this->assertSame( 'done', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
	}

	public function test_google_connecting_queues_waiting_auto_creates() {
		$this->autocreate();
		$e = $this->draft_event();
		$other = $this->draft_event();
		update_post_meta( $e, Sheets_Sync::META_AUTOCREATE, 'pending' );
		_set_cron_array( array() );
		do_action( 'anchor_connector_connected', 'microsoft' );
		$this->assertFalse( $this->queued( $e ) );
		do_action( 'anchor_connector_connected', 'google' );
		$this->assertNotFalse( $this->queued( $e ) );
		$this->assertFalse( $this->queued( $other ) );
	}

	public function test_autocreate_off_does_nothing() {
		$this->autocreate( false );
		$e = $this->draft_event();
		wp_update_post( array( 'ID' => $e, 'post_status' => 'publish' ) );
		$this->assertSame( '', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
		$this->assertFalse( $this->queued( $e ) );
	}

	public function test_republish_or_update_does_not_create_again() {
		$this->autocreate();
		$e = $this->draft_event();
		wp_update_post( array( 'ID' => $e, 'post_status' => 'publish' ) );
		$this->sync()->run( $e );
		$this->assertSame( 'done', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
		delete_post_meta( $e, Sheets_Sync::META_URL );
		delete_post_meta( $e, Sheets_Sync::META_REF );
		wp_update_post( array( 'ID' => $e, 'post_status' => 'draft' ) );
		wp_update_post( array( 'ID' => $e, 'post_status' => 'publish' ) );
		$this->sync()->run( $e );
		$this->assertSame( '', get_post_meta( $e, Sheets_Sync::META_URL, true ) );
		$this->assertCount( 1, $this->fake->creates );
	}

	public function test_run_with_pending_creates_shares_and_syncs() {
		$this->autocreate( true, 'FOLDER1' );
		$o = get_option( 'anchor_connector_google' );
		$o['share_emails'] = array( 'extra@example.test' );
		update_option( 'anchor_connector_google', $o, false );
		$e = $this->draft_event( 'Spring Gala' );
		wp_update_post( array( 'ID' => $e, 'post_status' => 'publish' ) );
		$this->make_seat( $e );
		$state = $this->sync()->run( $e );
		$this->assertCount( 1, $this->fake->creates );
		$this->assertSame( "Spring Gala \xE2\x80\x94 Registrants", $this->fake->creates[0]['title'] );
		$this->assertSame( 'FOLDER1', $this->fake->creates[0]['folder'] );
		$this->assertSame( 'NEW', get_post_meta( $e, Sheets_Sync::META_REF, true )['id'] );
		$this->assertSame( 'https://docs.google.com/spreadsheets/d/NEW/edit', get_post_meta( $e, Sheets_Sync::META_URL, true ) );
		$this->assertSame( 'done', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
		$this->assertTrue( $state['ok'] );
		$this->assertSame( 1, $state['rows'] );
		$this->assertGreaterThan( 1, count( $this->fake->grid ) );
		$this->assertSame( array( 'extra@example.test' ), $this->fake->shares[0]['emails'] );
	}

	public function test_pending_without_connection_waits_and_reports() {
		$this->autocreate();
		$this->fake->create_error = new WP_Error( 'anchor_sheet_not_connected', 'Google is not connected.' );
		$e = $this->draft_event();
		wp_update_post( array( 'ID' => $e, 'post_status' => 'publish' ) );
		$state = $this->sync()->run( $e );
		$this->assertStringContainsString( 'Google', $state['message'] );
		$this->assertSame( 'pending', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
		$this->assertSame( '', get_post_meta( $e, Sheets_Sync::META_URL, true ) );
		// Connected later: the next run creates it.
		$this->fake->create_error = null;
		$this->sync()->run( $e );
		$this->assertSame( 'done', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
		$this->assertCount( 1, $this->fake->creates );
	}

	public function test_seat_on_pending_event_schedules_a_run() {
		$this->autocreate();
		$e = $this->draft_event();
		update_post_meta( $e, Sheets_Sync::META_AUTOCREATE, 'pending' );
		_set_cron_array( array() );
		$this->make_seat( $e );
		$this->assertNotFalse( $this->queued( $e ) );
	}

	public function test_editor_publish_with_empty_url_field_keeps_pending_and_creates_once() {
		$this->autocreate();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$e = $this->draft_event();
		$_POST = array(
			\Anchor\Events\Sheets_Sync_Admin::NONCE_FIELD => wp_create_nonce( \Anchor\Events\Sheets_Sync_Admin::NONCE_ACTION ),
			\Anchor\Events\Sheets_Sync_Admin::URL_FIELD   => '',
		);
		wp_update_post( array( 'ID' => $e, 'post_status' => 'publish' ) );
		$_POST = array();
		$this->assertSame( 'pending', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
		$this->assertNotFalse( $this->queued( $e ) );
		$this->sync()->run( $e );
		$this->sync()->run( $e );
		$this->assertCount( 1, $this->fake->creates );
	}

	public function test_pending_with_existing_ref_does_not_create() {
		$this->autocreate();
		$e = $this->with_sheet( $this->draft_event() );
		update_post_meta( $e, Sheets_Sync::META_AUTOCREATE, 'pending' );
		$this->sync()->run( $e );
		$this->assertSame( array(), $this->fake->creates );
		$this->assertSame( 'done', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
	}

	public function test_folder_move_failure_is_a_state_warning() {
		$this->autocreate( true, 'F1' );
		$this->fake->create_warning = 'anchor_oauth_http';
		$e = $this->draft_event();
		update_post_meta( $e, Sheets_Sync::META_AUTOCREATE, 'pending' );
		$state = $this->sync()->run( $e );
		$this->assertTrue( $state['ok'] );
		$this->assertStringContainsString( "couldn't move it into the chosen folder", $state['message'] );
		$this->assertSame( 'NEW', get_post_meta( $e, Sheets_Sync::META_REF, true )['id'] );
	}

	public function test_orphan_create_with_no_ref_saves_state_without_fatal() {
		$this->autocreate();
		$e = $this->draft_event();
		update_post_meta( $e, Sheets_Sync::META_AUTOCREATE, 'pending' );
		update_post_meta( $e, Sheets_Sync::META_URL, 'https://docs.google.com/spreadsheets/d/PASTED/edit' );
		$state = $this->sync()->run( $e );
		$this->assertTrue( $state['ok'] );
		$this->assertSame( $state, $this->sync()->get_state( $e ) );
		$this->assertSame( 'done', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
		$this->assertSame( 'https://docs.google.com/spreadsheets/d/PASTED/edit', get_post_meta( $e, Sheets_Sync::META_URL, true ) );
		$this->assertFalse( get_option( 'anchor_ev_sheet_lock_' . $e ), 'lock released' );
	}

	public function test_run_that_hits_the_lock_requeues_itself() {
		$e = $this->with_sheet( $this->make_event() );
		_set_cron_array( array() );
		add_option( 'anchor_ev_sheet_lock_' . $e, time(), '', false );
		$this->sync()->run( $e );
		$this->assertSame( 0, $this->fake->reads );
		$this->assertNotFalse( $this->queued( $e ), 'trigger is kept for a later run' );
		delete_option( 'anchor_ev_sheet_lock_' . $e );
	}

	public function test_throwable_during_sync_records_failed_state_and_releases_lock() {
		$this->fake = new Anchor_Fake_Throwing_Adapter();
		$e = $this->with_sheet( $this->make_event() );
		$state = $this->sync()->run( $e );
		$this->assertFalse( $state['ok'] );
		$this->assertSame( $state, $this->sync()->get_state( $e ) );
		$this->assertFalse( get_option( 'anchor_ev_sheet_lock_' . $e ), 'lock released' );
		$log = wp_json_encode( get_option( \Anchor\Events\Events_Log::ERROR_OPTION ) );
		$this->assertStringContainsString( 'sheet_sync_failed', $log );
		$this->assertStringNotContainsString( 'SECRET-IN-MESSAGE', $log );
		$this->assertStringNotContainsString( 'SECRET-IN-MESSAGE', $state['message'] );
	}

	public function test_child_with_own_sheet_also_refreshes_parent_all_dates_sheet() {
		list( $main, $dates ) = $this->group();
		$this->with_sheet( $main );
		$this->with_sheet( $dates[0] );
		_set_cron_array( array() );
		$this->make_seat( $dates[0], array( 'email' => 'c@example.test' ) );
		$this->assertNotFalse( $this->queued( $dates[0] ) );
		$this->assertNotFalse( $this->queued( $main ), 'parent all-dates sheet refreshed too' );
	}

	public function test_duplicated_event_does_not_inherit_the_originals_sheet() {
		$this->autocreate();
		$orig = $this->make_event();
		update_post_meta( $orig, Sheets_Sync::META_URL, 'https://docs.google.com/spreadsheets/d/x/edit' );
		update_post_meta( $orig, Sheets_Sync::META_REF, array( 'provider' => 'google', 'id' => 'x', 'tab' => 't', 'event_id' => $orig ) );
		// A duplicate-post plugin copies every meta key onto a new draft.
		$copy = $this->draft_event( 'Copy' );
		foreach ( array( Sheets_Sync::META_URL, Sheets_Sync::META_REF, Sheets_Sync::META_SHARED, Sheets_Sync::META_AUTOCREATE ) as $k ) {
			update_post_meta( $copy, $k, Sheets_Sync::META_SHARED === $k ? 'hash' : ( Sheets_Sync::META_AUTOCREATE === $k ? 'done' : get_post_meta( $orig, $k, true ) ) );
		}
		$this->assertSame( $orig, $this->sync()->target_for_event( $orig ) );
		$this->assertSame( 0, $this->sync()->target_for_event( $copy ) );
		$this->assertSame( array(), $this->sync()->run( $copy ) );
		$this->assertSame( 0, $this->fake->reads, 'copy never writes into the original sheet' );
		foreach ( array( Sheets_Sync::META_URL, Sheets_Sync::META_REF, Sheets_Sync::META_SHARED, Sheets_Sync::META_AUTOCREATE ) as $k ) {
			$this->assertSame( '', get_post_meta( $copy, $k, true ), $k . ' cleared on the copy' );
		}
		// Publishing the copy now auto-creates its own spreadsheet.
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'publish' ) );
		$this->assertSame( 'pending', get_post_meta( $copy, Sheets_Sync::META_AUTOCREATE, true ) );
		$this->sync()->run( $copy );
		$this->assertSame( $copy, get_post_meta( $copy, Sheets_Sync::META_REF, true )['event_id'], 'created ref is bound to its event' );
		$this->assertSame( 'x', get_post_meta( $orig, Sheets_Sync::META_REF, true )['id'] );
	}
}
