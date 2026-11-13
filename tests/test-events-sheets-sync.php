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
		set_transient( 'anchor_ev_sheet_lock_' . $e, 1, 120 );
		$this->sync()->run( $e );
		$this->assertSame( 0, $this->fake->reads );
		delete_transient( 'anchor_ev_sheet_lock_' . $e );
		$this->sync()->run( $e );
		$this->assertSame( 1, $this->fake->reads );
		$this->assertFalse( get_transient( 'anchor_ev_sheet_lock_' . $e ), 'lock released' );
	}

	public function test_external_signup_event_does_not_sync() {
		$e = $this->with_sheet( $this->make_event( array( 'registration_mode' => 'external' ) ) );
		$this->assertTrue( $this->module()->uses_external_signup( $e ) );
		$this->make_seat( $e );
		$this->assertFalse( $this->queued( $e ) );
		$this->sync()->run( $e );
		$this->assertSame( 0, $this->fake->reads );
	}
}
