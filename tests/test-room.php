<?php
/**
 * The /live/ room: routing, headers, render branches, admin column
 * (virtual-events spec §5, §7).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Stream_State;

/**
 * @group room
 */
class Test_Room extends Anchor_Events_TestCase {

	private $minted = [];

	public function tear_down() {
		foreach ( $this->minted as $id ) {
			remove_role( 'anchor_event_' . $id );
		}
		$this->minted = [];
		parent::tear_down();
	}

	private function stream_event() {
		$start    = time() + ( 2 * HOUR_IN_SECONDS );
		$event_id = $this->make_event( [
			'registration_mode'  => 'free',
			'access_role_enabled' => true,
			'timezone'          => 'UTC',
			'start_date'        => gmdate( 'Y-m-d', $start ),
			'start_time'        => gmdate( 'H:i', $start ),
			'start_ts'          => $start,
			'end_ts'            => $start + 3600,
			'stream_default_modality' => 'virtual',
			'stream_embed'      => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/77', 'raw' => '' ],
		] );
		$this->minted[] = $event_id;
		return $event_id;
	}

	public function test_room_url_is_the_live_endpoint() {
		$event_id = $this->stream_event();
		$this->assertSame(
			trailingslashit( get_permalink( $event_id ) ) . 'live/',
			$this->module()->room_url( $event_id )
		);
	}

	/**
	 * The `live` endpoint is registered against the event permalink.
	 *
	 * register_room_endpoint() is re-invoked explicitly rather than relying on
	 * the module's real `init` registration: WP_UnitTestCase::set_permalink_
	 * structure(), called by an earlier test elsewhere in the suite (e.g.
	 * LocationsRewriteTest), resets $wp_rewrite->endpoints to [] via
	 * WP_Rewrite::init() — a WP test-harness artefact, since the `init` ACTION
	 * that adds the endpoint only ever fires once, at bootstrap. Calling the
	 * registrar again is what a real request does on every load; it is not a
	 * weaker assertion.
	 */
	public function test_live_endpoint_registered() {
		global $wp_rewrite;
		$this->module()->register_room_endpoint();
		$this->assertArrayHasKey( 'live', $wp_rewrite->endpoints ? array_column( $wp_rewrite->endpoints, 1, 1 ) : [] );
	}

	/** A group parent has no room of its own. */
	public function test_group_parent_has_no_room() {
		$parent_id = $this->make_event( [ 'type' => 'offering', 'registration_mode' => 'free', 'access_role_enabled' => true ] );
		update_post_meta( $parent_id, '_anchor_event_group_role', 'parent' );
		$this->assertSame( '', $this->module()->room_url( $parent_id ) );
	}

	/**
	 * Nor has an ordinary in-person event — which, since the switch defaults
	 * on (spec §4 preamble), is `enabled()` and still roomless. That is the
	 * whole reason room_url() asks has_stream() as well.
	 */
	public function test_plain_event_has_no_room() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );

		$this->assertTrue( $this->module()->stream_capable( $event_id ), 'It COULD hold a room…' );
		$this->assertTrue( $this->module()->entitlements->enabled( $event_id ), '…its attendees do get the event role…' );
		$this->assertFalse( $this->module()->has_stream( $event_id ), '…but nothing resolves to watch…' );
		$this->assertSame( '', $this->module()->room_url( $event_id ), '…so it has no room.' );
	}

	/** And neither has one whose operator switched access off. */
	public function test_switched_off_event_has_no_room() {
		$event_id = $this->make_event( [
			'registration_mode'   => 'free',
			'access_role_enabled' => false,
			'stream_default_modality' => 'virtual',
			'stream_embed'        => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/78', 'raw' => '' ],
		] );

		$this->assertTrue( $this->module()->has_stream( $event_id ), 'There is a stream…' );
		$this->assertSame( '', $this->module()->room_url( $event_id ), '…but the switch is off, so no room.' );
	}

	/** The Live column is a dash for a plain event, not a state. */
	public function test_admin_live_column_is_blank_for_a_plain_event() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		ob_start();
		$this->module()->render_column( 'anchor_event_live', $event_id );
		$this->assertSame( '&mdash;', trim( ob_get_clean() ) );
	}

	/** JSON-LD for a plain legacy virtual event is exactly what it is today. */
	public function test_schema_virtual_location_unchanged_for_a_plain_event() {
		$event_id = $this->make_event( [
			'registration_mode' => 'free',
			'virtual'           => true,
			'virtual_url'       => 'https://zoom.example/j/9',
			// A usable start date, or for_event() returns [] (no node at all)
			// before ever reaching the location fields — see
			// test-event-schema.php::test_no_start_date_returns_empty_array().
			'start_date'        => '2027-03-01',
		] );
		$node = $this->module()->event_schema->for_event( $event_id );
		$this->assertSame( 'https://zoom.example/j/9', $node['location']['url'] );
	}

	/** Logged out: the sign-in branch, and never the embed. */
	public function test_render_room_logged_out() {
		$event_id = $this->stream_event();
		wp_set_current_user( 0 );

		$html = $this->module()->render_room( $event_id );
		$this->assertStringContainsString( 'anchor-room--locked', $html );
		$this->assertStringContainsString( 'Sign in to join', $html );
		$this->assertStringNotContainsString( 'player.vimeo.com', $html );
	}

	/** Logged in but not entitled: the denial branch and its filter. */
	public function test_render_room_denied_and_filter() {
		$event_id = $this->stream_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$html = $this->module()->render_room( $event_id );
		$this->assertStringContainsString( "isn't registered", $html );

		$custom = function () { return 'Ring the front desk.'; };
		add_filter( 'anchor_events_room_denied_message', $custom );
		$html = $this->module()->render_room( $event_id );
		remove_filter( 'anchor_events_room_denied_message', $custom );

		$this->assertStringContainsString( 'Ring the front desk.', $html );
	}

	/** Entitled, before the window: countdown markup, no embed. */
	public function test_render_room_entitled_countdown_hides_the_embed() {
		$event_id = $this->stream_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'room@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'room@example.test' ] );
		wp_set_current_user( $user_id );

		$html = $this->module()->render_room( $event_id );
		$this->assertStringContainsString( 'anchor-room-state', $html );
		$this->assertStringContainsString( 'data-state="countdown"', $html );
		$this->assertStringContainsString( 'data-target-ts="', $html );
		$this->assertStringContainsString( 'data-server-now="', $html );
		$this->assertStringNotContainsString( 'player.vimeo.com', $html );
	}

	/** Entitled, inside the window: the embed is present. */
	public function test_render_room_live_shows_the_embed() {
		$event_id = $this->stream_event();
		update_post_meta( $event_id, '_anchor_event_start_ts', time() );
		update_post_meta( $event_id, '_anchor_event_end_ts', time() + 3600 );
		$user_id = self::factory()->user->create( [ 'user_email' => 'live@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'live@example.test' ] );
		wp_set_current_user( $user_id );

		$html = $this->module()->render_room( $event_id );
		$this->assertStringContainsString( 'data-state="live"', $html );
		$this->assertStringContainsString( 'https://player.vimeo.com/video/77', $html );
		$this->assertStringContainsString( 'allowfullscreen', $html );
	}

	/** The admin Live column reports the state. */
	public function test_admin_live_column() {
		$event_id = $this->stream_event();
		ob_start();
		$this->module()->render_column( 'anchor_event_live', $event_id );
		$this->assertStringContainsString( 'countdown', strtolower( ob_get_clean() ) );
	}

	/** JSON-LD points VirtualLocation at the room. */
	public function test_schema_virtual_location_is_the_room() {
		$event_id = $this->stream_event();
		update_post_meta( $event_id, '_anchor_event_virtual', 1 );
		$node = $this->module()->event_schema->for_event( $event_id );
		$this->assertSame( $this->module()->room_url( $event_id ), $node['location']['url'] );
	}
}
