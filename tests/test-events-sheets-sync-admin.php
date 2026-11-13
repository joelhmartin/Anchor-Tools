<?php
/**
 * Sheets_Sync_Admin: metabox, save handler and Sync now (no network).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Sheets_Sync;
use Anchor\Events\Sheets_Sync_Admin;

class Anchor_Sheet_Admin_Redirect_Signal extends \Exception {}

/** In-memory sheet for the Sync now run. */
class Anchor_Sheet_Admin_Memory implements Anchor_Sheet_Adapter {
	public $grid = array();
	public function resolve( $url ) {
		return array( 'provider' => 'google', 'id' => 'x', 'tab' => 't' );
	}
	public function read( array $ref ) {
		return $this->grid;
	}
	public function write_cells( array $ref, array $cells ) {
		foreach ( $cells as $c ) {
			list( $r, $col, $v ) = $c;
			while ( count( $this->grid ) < $r ) {
				$this->grid[] = array();
			}
			while ( count( $this->grid[ $r - 1 ] ) <= $col ) {
				$this->grid[ $r - 1 ][] = '';
			}
			$this->grid[ $r - 1 ][ $col ] = (string) $v;
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

/** In-memory sheet that can also create spreadsheets. */
class Anchor_Sheet_Admin_Creator extends Anchor_Sheet_Admin_Memory {
	public $creates = array();
	public function create( $title, $folder_id = '' ) {
		$this->creates[] = $title;
		return array( 'url' => 'https://docs.google.com/spreadsheets/d/NEW/edit', 'ref' => array( 'provider' => 'google', 'id' => 'NEW', 'tab' => 'Sheet1' ) );
	}
}

/** Adapter whose resolve() is scripted. */
class Anchor_Fake_Resolve_Google extends Anchor_Sheet_Google {
	public static $result;
	public function resolve( $url ) {
		return self::$result;
	}
}

class Anchor_Fake_Resolve_Excel extends Anchor_Sheet_Excel {
	public function resolve( $url ) {
		return array( 'provider' => 'microsoft', 'drive_id' => 'd', 'item_id' => 'i', 'worksheet' => 'w' );
	}
}

/**
 * @group sheets
 */
class Test_Events_Sheets_Sync_Admin extends Anchor_Events_TestCase {

	const URL = 'https://docs.google.com/spreadsheets/d/abc/edit';

	private $fake;

	public function set_up() {
		parent::set_up();
		Anchor_Fake_Resolve_Google::$result = array( 'provider' => 'google', 'id' => 'abc', 'tab' => 'T' );
		add_filter( 'anchor_events_sheet_adapter_for_url', array( $this, 'adapter' ), 10, 2 );
		$this->fake = new Anchor_Sheet_Admin_Memory();
		add_filter( 'anchor_sheet_adapter_for_ref', array( $this, 'inject' ), 10, 2 );
		add_filter( 'pre_http_request', array( $this, 'block' ), 10, 3 );
		delete_option( 'anchor_connector_google' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		remove_filter( 'anchor_events_sheet_adapter_for_url', array( $this, 'adapter' ), 10 );
		remove_filter( 'anchor_sheet_adapter_for_ref', array( $this, 'inject' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'block' ), 10 );
		delete_option( 'anchor_connector_google' );
		delete_option( 'anchor_connector_microsoft' );
		_set_cron_array( array() );
		$_POST = $_REQUEST = array();
		parent::tear_down();
	}

	public function adapter( $a, $url ) {
		if ( false !== strpos( $url, 'onedrive.live.com' ) ) {
			return new Anchor_Fake_Resolve_Excel();
		}
		return false !== strpos( $url, 'docs.google.com' ) ? new Anchor_Fake_Resolve_Google() : $a;
	}
	public function inject( $o, $ref ) {
		return $this->fake;
	}
	public function block() {
		return new WP_Error( 'blocked', 'no network' );
	}

	private function admin() {
		return $this->module()->sheets_sync_admin;
	}

	private function connect() {
		update_option( 'anchor_connector_google', array( 'refresh_token' => 'x', 'account' => 'me@example.test' ), false );
	}

	private function save( $event_id, $url, $nonce = true ) {
		$_POST = array( Sheets_Sync_Admin::URL_FIELD => $url );
		if ( $nonce ) {
			$_POST[ Sheets_Sync_Admin::NONCE_FIELD ] = wp_create_nonce( Sheets_Sync_Admin::NONCE_ACTION );
		}
		$this->admin()->save( $event_id );
		$_POST = array();
	}

	private function render( $event_id ) {
		ob_start();
		$this->admin()->render( get_post( $event_id ) );
		return ob_get_clean();
	}

	public function test_save_resolves_url_and_schedules_sync() {
		$this->connect();
		$e = $this->make_event();
		$this->save( $e, self::URL );
		$this->assertSame( self::URL, get_post_meta( $e, Sheets_Sync::META_URL, true ) );
		$this->assertSame( 'abc', get_post_meta( $e, Sheets_Sync::META_REF, true )['id'] );
		$this->assertNotFalse( wp_next_scheduled( Sheets_Sync::HOOK, array( $e ) ) );
	}

	public function test_save_with_unconnected_provider_keeps_url_and_shows_connect_message() {
		$e = $this->make_event();
		$this->save( $e, self::URL );
		$this->assertSame( self::URL, get_post_meta( $e, Sheets_Sync::META_URL, true ) );
		$this->assertEmpty( get_post_meta( $e, Sheets_Sync::META_REF, true ) );
		$html = $this->render( $e );
		$this->assertStringContainsString( 'Connect Google', $html );
		$this->assertStringContainsString( 'Connectors', $html );
	}

	public function test_resolve_failure_keeps_previous_ref() {
		$this->connect();
		$e   = $this->make_event();
		$old = array( 'provider' => 'google', 'id' => 'old', 'tab' => 'T' );
		update_post_meta( $e, Sheets_Sync::META_URL, 'https://docs.google.com/spreadsheets/d/old/edit' );
		update_post_meta( $e, Sheets_Sync::META_REF, $old );
		Anchor_Fake_Resolve_Google::$result = new WP_Error( 'anchor_sheet_forbidden', 'nope' );
		$this->save( $e, self::URL );
		$this->assertSame( $old, get_post_meta( $e, Sheets_Sync::META_REF, true ) );
		$html = $this->render( $e );
		$this->assertStringContainsString( "can&#039;t open this spreadsheet", str_replace( '&#8217;', '&#039;', $html ) );
		$this->assertStringContainsString( 'me@example.test', $html );
	}

	public function test_clearing_url_removes_ref_and_unschedules() {
		$this->connect();
		$e = $this->make_event();
		$this->save( $e, self::URL );
		$this->assertNotFalse( wp_next_scheduled( Sheets_Sync::HOOK, array( $e ) ) );
		$this->save( $e, '' );
		$this->assertSame( '', get_post_meta( $e, Sheets_Sync::META_URL, true ) );
		$this->assertSame( '', get_post_meta( $e, Sheets_Sync::META_REF, true ) );
		$this->assertFalse( wp_next_scheduled( Sheets_Sync::HOOK, array( $e ) ) );
	}

	public function test_save_requires_nonce_and_capability() {
		$this->connect();
		$e = $this->make_event();
		$this->save( $e, self::URL, false );
		$this->assertSame( '', get_post_meta( $e, Sheets_Sync::META_URL, true ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->save( $e, self::URL );
		$this->assertSame( '', get_post_meta( $e, Sheets_Sync::META_URL, true ) );
	}

	public function test_sync_now_runs_inline_and_redirects_with_state() {
		$this->connect();
		$e = $this->make_event();
		$this->save( $e, self::URL );
		$this->make_seat( $e );
		$_REQUEST = array( 'event_id' => $e, '_wpnonce' => wp_create_nonce( 'anchor_events_sheet_sync_now_' . $e ) );
		$trap     = function ( $location ) {
			throw new Anchor_Sheet_Admin_Redirect_Signal( (string) $location );
		};
		add_filter( 'wp_redirect', $trap );
		$location = '';
		try {
			$this->admin()->handle_sync_now();
			$this->fail( 'No redirect.' );
		} catch ( Anchor_Sheet_Admin_Redirect_Signal $s ) {
			$location = $s->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $trap );
		}
		$this->assertStringContainsString( 'post.php', $location );
		$state = $this->module()->sheets_sync->get_state( $e );
		$this->assertTrue( $state['ok'] );
		$this->assertSame( 1, $state['rows'] );
		$this->assertGreaterThan( 1, count( $this->fake->grid ) );
	}

	public function test_metabox_shows_phi_note_and_last_sync_state() {
		$this->connect();
		$e = $this->make_event();
		$this->save( $e, self::URL );
		update_post_meta( $e, Sheets_Sync::META_STATE, array( 'last_sync' => time(), 'ok' => true, 'message' => '', 'rows' => 7 ) );
		$html = $this->render( $e );
		$this->assertStringContainsString( 'Every registration field and answer is copied to this spreadsheet.', $html );
		$this->assertStringContainsString( '7 rows', $html );
		$this->assertStringContainsString( 'me@example.test', $html );
		$this->assertStringContainsString( 'Sync now', $html );

		update_post_meta( $e, Sheets_Sync::META_STATE, array( 'last_sync' => time(), 'ok' => false, 'message' => 'Boom', 'rows' => 0 ) );
		$this->assertStringContainsString( 'Boom', $this->render( $e ) );

		$x = $this->make_event( array( 'registration_mode' => 'external' ) );
		$this->assertStringContainsString( 'nothing to sync', $this->render( $x ) );
	}

	public function test_switching_to_unconnected_provider_drops_old_ref_then_resolves_after_connect() {
		$this->connect();
		$e = $this->make_event();
		$this->save( $e, self::URL );
		$this->assertNotFalse( wp_next_scheduled( Sheets_Sync::HOOK, array( $e ) ) );
		update_post_meta( $e, Sheets_Sync::META_STATE, array( 'last_sync' => time(), 'ok' => true, 'message' => '', 'rows' => 1 ) );

		$ms = 'https://onedrive.live.com/edit?resid=1';
		$this->save( $e, $ms );
		$this->assertSame( $ms, get_post_meta( $e, Sheets_Sync::META_URL, true ) );
		$this->assertEmpty( get_post_meta( $e, Sheets_Sync::META_REF, true ) );
		$this->assertEmpty( get_post_meta( $e, Sheets_Sync::META_STATE, true ) );
		$this->assertFalse( wp_next_scheduled( Sheets_Sync::HOOK, array( $e ) ) );
		$html = $this->render( $e );
		$this->assertStringContainsString( 'Connect Microsoft', $html );
		$this->assertStringNotContainsString( 'Connected', $html );

		update_option( 'anchor_connector_microsoft', array( 'refresh_token' => 'x', 'account' => 'ms@example.test' ), false );
		$this->save( $e, $ms );
		$this->assertSame( 'microsoft', get_post_meta( $e, Sheets_Sync::META_REF, true )['provider'] );
	}

	private function press_sync_now( $event_id, $nonce ) {
		$_REQUEST = array( 'event_id' => $event_id, '_wpnonce' => $nonce );
		$trap     = function ( $location ) {
			throw new Anchor_Sheet_Admin_Redirect_Signal( (string) $location );
		};
		add_filter( 'wp_redirect', $trap );
		add_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
		try {
			$this->admin()->handle_sync_now();
			return 'none';
		} catch ( Anchor_Sheet_Admin_Redirect_Signal $s ) {
			return 'redirect';
		} catch ( \WPDieException $d ) {
			return 'die';
		} finally {
			remove_filter( 'wp_redirect', $trap );
			remove_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
		}
	}

	public function die_handler() {
		return function ( $m ) {
			throw new \WPDieException( is_string( $m ) ? $m : 'die' );
		};
	}

	public function test_sync_now_rejects_bad_nonce_and_missing_capability() {
		$this->connect();
		$e = $this->make_event();
		$this->save( $e, self::URL );
		$this->assertSame( 'die', $this->press_sync_now( $e, 'bogus' ) );
		$this->assertSame( array(), $this->module()->sheets_sync->get_state( $e ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'die', $this->press_sync_now( $e, wp_create_nonce( 'anchor_events_sheet_sync_now_' . $e ) ) );
		$this->assertSame( array(), $this->module()->sheets_sync->get_state( $e ) );
	}

	public function test_sync_now_with_nothing_to_sync_sets_notice() {
		$e = $this->make_event();
		$this->assertSame( 'redirect', $this->press_sync_now( $e, wp_create_nonce( 'anchor_events_sheet_sync_now_' . $e ) ) );
		update_post_meta( $e, Sheets_Sync::META_URL, self::URL );
		update_post_meta( $e, Sheets_Sync::META_REF, array( 'provider' => 'google', 'id' => 'abc', 'tab' => 'T' ) );
		$this->assertStringContainsString( 'Nothing to sync', (string) get_transient( 'anchor_ev_sheet_notice_' . $e . '_' . get_current_user_id() ) );
	}

	public function test_create_spreadsheet_button_creates_inline_and_redirects() {
		$this->connect();
		$this->fake = new Anchor_Sheet_Admin_Creator();
		$e          = $this->make_event( array( 'title' => 'Spring Gala' ) );
		$this->assertStringContainsString( 'Create spreadsheet', $this->render( $e ) );
		$_REQUEST = array( 'event_id' => $e, '_wpnonce' => wp_create_nonce( 'anchor_events_sheet_create_' . $e ) );
		$trap     = function ( $location ) {
			throw new Anchor_Sheet_Admin_Redirect_Signal( (string) $location );
		};
		add_filter( 'wp_redirect', $trap );
		$location = '';
		try {
			$this->admin()->handle_create();
			$this->fail( 'No redirect.' );
		} catch ( Anchor_Sheet_Admin_Redirect_Signal $s ) {
			$location = $s->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $trap );
		}
		$this->assertStringContainsString( 'post.php', $location );
		$this->assertSame( array( "Spring Gala \xE2\x80\x94 Registrants" ), $this->fake->creates );
		$this->assertSame( 'NEW', get_post_meta( $e, Sheets_Sync::META_REF, true )['id'] );
		$this->assertSame( 'done', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
		$this->assertTrue( $this->module()->sheets_sync->get_state( $e )['ok'] );
		$html = $this->render( $e );
		$this->assertStringContainsString( 'Sync now', $html );
		$this->assertStringNotContainsString( 'Create spreadsheet', $html );
	}

	public function test_create_spreadsheet_rejects_bad_nonce_and_missing_capability() {
		$this->connect();
		$this->fake = new Anchor_Sheet_Admin_Creator();
		$e          = $this->make_event();
		$call       = function ( $nonce ) use ( $e ) {
			$_REQUEST = array( 'event_id' => $e, '_wpnonce' => $nonce );
			add_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
			try {
				$this->admin()->handle_create();
				return 'none';
			} catch ( \WPDieException $d ) {
				return 'die';
			} finally {
				remove_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
			}
		};
		$this->assertSame( 'die', $call( 'bogus' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'die', $call( wp_create_nonce( 'anchor_events_sheet_create_' . $e ) ) );
		$this->assertSame( array(), $this->fake->creates );
	}

	public function test_ok_state_with_message_renders_as_a_warning() {
		$this->connect();
		$e = $this->make_event();
		$this->save( $e, self::URL );
		update_post_meta( $e, Sheets_Sync::META_STATE, array( 'last_sync' => time(), 'ok' => true, 'message' => 'Reconnect to enable auto-share', 'rows' => 2 ) );
		$html = $this->render( $e );
		$this->assertStringContainsString( '2 rows', $html );
		$this->assertStringContainsString( 'Reconnect to enable auto-share', $html );
		$this->assertStringNotContainsString( 'Last sync failed', $html );
	}

	public function test_pending_event_shows_waiting_message_and_create_button() {
		$e = $this->make_event();
		update_post_meta( $e, Sheets_Sync::META_AUTOCREATE, 'pending' );
		update_post_meta( $e, Sheets_Sync::META_STATE, array( 'last_sync' => time(), 'ok' => true, 'message' => 'Waiting to create: connect Google', 'rows' => 0 ) );
		$html = $this->render( $e );
		$this->assertStringContainsString( 'connect Google', $html );
		$this->assertStringContainsString( 'Create spreadsheet', $html );
		$this->assertStringNotContainsString( 'Last sync:', $html );
	}

	public function test_pasting_a_url_ends_a_pending_auto_create() {
		$this->connect();
		$e = $this->make_event();
		update_post_meta( $e, Sheets_Sync::META_AUTOCREATE, 'pending' );
		$this->save( $e, self::URL );
		$this->assertSame( 'done', get_post_meta( $e, Sheets_Sync::META_AUTOCREATE, true ) );
	}
}
