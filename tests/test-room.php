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

	/**
	 * Switch to pretty permalinks AND re-run everything that only ever runs
	 * once, at bootstrap, under whatever structure was active then (Plain,
	 * in this suite):
	 *   - register_cpt()'s register_post_type() call only adds the CPT's own
	 *     permastruct to $wp_rewrite when get_option('permalink_structure')
	 *     is already non-empty AT REGISTRATION TIME (a WP core guard) — so
	 *     get_permalink() keeps returning the plain '?event=slug' form until
	 *     register_cpt() runs again with the new structure in place.
	 *   - add_rewrite_endpoint() registers 'live' as a public query var on
	 *     the CURRENT $wp object, and every test's tear_down() resets $wp to
	 *     a bare instance (abstract-testcase.php), so 'live' must be
	 *     re-registered in every test that calls go_to() on a room URL —
	 *     go_to() carries forward whatever $wp->public_query_vars holds at
	 *     the moment it runs.
	 * Neither is a production bug: a real request re-fires `init` and
	 * re-registers both every time.
	 */
	private function pretty_permalinks() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->module()->register_cpt();
		$this->module()->register_room_endpoint();
		flush_rewrite_rules();
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

	/** Pretty permalinks: the room URL is the endpoint's own path segment. */
	public function test_room_url_is_the_live_endpoint_on_pretty_permalinks() {
		$this->pretty_permalinks();

		$event_id = $this->stream_event();
		$this->assertSame(
			trailingslashit( get_permalink( $event_id ) ) . 'live/',
			$this->module()->room_url( $event_id )
		);
	}

	/**
	 * Plain permalinks (or any CPT URL WordPress never rewrote) are
	 * THEMSELVES a query string — trailingslashit()+'live/' on
	 * '?event=slug' yields '?event=slug/live/', which sets no query var at
	 * all, so is_room_request() could never be true for it.
	 * add_rewrite_endpoint() registers 'live' as a public query var for
	 * exactly this case, so the URL room_url() mints under Plain permalinks
	 * must itself resolve back to a room request.
	 */
	public function test_room_url_uses_the_live_query_var_on_plain_permalinks() {
		$this->set_permalink_structure( '' );
		flush_rewrite_rules();

		$event_id = $this->stream_event();
		$room     = $this->module()->room_url( $event_id );
		$this->assertStringContainsString( 'live=1', $room );

		$this->module()->register_room_endpoint();
		$this->go_to( $room );
		$this->assertTrue(
			$this->module()->is_room_request(),
			'The URL room_url() mints must itself satisfy is_room_request().'
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

	/* -----------------------------------------------------------------
	 * room_header_list() — the pure header/redirect decision, unit-tested
	 * without a real HTTP response.
	 * --------------------------------------------------------------- */

	/** A live room request: nocache, the two headers, no redirect. */
	public function test_room_header_list_for_a_live_room() {
		$this->pretty_permalinks();
		$event_id = $this->stream_event();

		$this->go_to( $this->module()->room_url( $event_id ) );
		$this->assertTrue( $this->module()->is_room_request() );

		$decision = $this->module()->room_header_list( $event_id );
		$this->assertTrue( $decision['nocache'] );
		$this->assertSame( 'private, no-store, max-age=0', $decision['headers']['Cache-Control'] );
		$this->assertSame( 'noindex, nofollow', $decision['headers']['X-Robots-Tag'] );
		$this->assertSame( '', $decision['redirect'] );
	}

	/**
	 * A roomless event's /live/ URL — is_room_request() is structural (the
	 * query var's presence), so it is true even though room_url() is ''.
	 * The decision is a redirect to the event page, not a set of headers.
	 */
	public function test_room_header_list_for_a_roomless_event() {
		$this->pretty_permalinks();
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$this->assertSame( '', $this->module()->room_url( $event_id ), 'Sanity: no stream, no room.' );

		$this->go_to( trailingslashit( get_permalink( $event_id ) ) . 'live/' );
		$this->assertTrue( $this->module()->is_room_request() );

		$decision = $this->module()->room_header_list( $event_id );
		$this->assertSame( [], $decision['headers'] );
		// Final review I6: the redirect itself is uncached, so a page cache
		// cannot pin a stale "no room here" 302 onto an event that later
		// gains a stream.
		$this->assertTrue( $decision['nocache'] );
		$this->assertSame( get_permalink( $event_id ), $decision['redirect'] );
	}

	/** Any other page: empty decision, nothing to apply. */
	public function test_room_header_list_for_a_non_room_request() {
		$event_id = $this->stream_event();
		$this->go_to( get_permalink( $event_id ) );
		$this->assertFalse( $this->module()->is_room_request() );

		$this->assertSame(
			[ 'headers' => [], 'nocache' => false, 'redirect' => '' ],
			$this->module()->room_header_list( $event_id )
		);
	}

	/* -----------------------------------------------------------------
	 * room_headers() — the thin wrapper: the redirect it actually issues,
	 * and the wp_head robots meta it actually prints.
	 * --------------------------------------------------------------- */

	/** The roomless-event redirect, captured via the wp_redirect filter. */
	public function test_room_headers_redirects_a_roomless_event() {
		$this->pretty_permalinks();
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );

		$this->go_to( trailingslashit( get_permalink( $event_id ) ) . 'live/' );

		$trap = static function ( $location ) {
			throw new Anchor_Dispatch_Redirected( (string) $location );
		};
		add_filter( 'wp_redirect', $trap );
		try {
			$this->module()->room_headers();
			$this->fail( 'A roomless event did not redirect.' );
		} catch ( Anchor_Dispatch_Redirected $e ) {
			$this->assertSame( get_permalink( $event_id ), $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $trap );
		}
	}

	/** The robots meta prints on wp_head for a room request... */
	public function test_room_headers_prints_robots_meta_for_a_room_request() {
		$this->pretty_permalinks();
		$event_id = $this->stream_event();

		$this->go_to( $this->module()->room_url( $event_id ) );
		$this->module()->room_headers();

		ob_start();
		do_action( 'wp_head' );
		$head = ob_get_clean();
		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow" />', $head );
	}

	/** ...and never for an ordinary page. */
	public function test_room_headers_prints_nothing_for_a_non_room_request() {
		$event_id = $this->stream_event();
		$this->go_to( get_permalink( $event_id ) );
		$this->module()->room_headers();

		ob_start();
		do_action( 'wp_head' );
		$head = ob_get_clean();
		$this->assertStringNotContainsString( 'noindex, nofollow', $head );
	}

	/**
	 * Task 23: under the PHPUnit CLI SAPI, output has almost always already
	 * started by the time this test runs (WordPress's own bootstrap and the
	 * rest of the suite print ahead of it), so header()/nocache_headers()
	 * would otherwise raise "Cannot modify header information - headers
	 * already sent" warnings — exactly what a full `composer test` run
	 * showed for this method before the headers_sent() guard was added.
	 * Same pattern as
	 * test_sanitize_settings_with_a_missing_key_warns_never_and_defaults_correctly()
	 * in test-event-model.php: a custom error handler captures anything
	 * PHP would have warned about, so the assertion is on real interpreter
	 * behaviour rather than a mock. The wp_head robots-meta hook — which
	 * the guard must leave alone — is asserted separately, so a guard that
	 * over-broadly skipped everything would still fail this test.
	 */
	public function test_room_headers_never_warns_when_headers_already_sent() {
		$this->pretty_permalinks();
		$event_id = $this->stream_event();
		$this->go_to( $this->module()->room_url( $event_id ) );

		$warnings = [];
		set_error_handler( static function ( $errno, $errstr ) use ( &$warnings ) {
			$warnings[] = $errstr;
			return true;
		}, E_WARNING | E_NOTICE | E_DEPRECATED );

		try {
			$this->module()->room_headers();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( [], $warnings, 'room_headers() must never raise a PHP warning, headers already sent or not.' );

		ob_start();
		do_action( 'wp_head' );
		$head = ob_get_clean();
		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow" />', $head );
	}

	private function room_request( $event_id ) {
		$req = new WP_REST_Request( 'GET', '/anchor-events/v1/events/' . $event_id . '/room' );
		return rest_get_server()->dispatch( $req );
	}

	public function test_rest_401_logged_out() {
		do_action( 'rest_api_init' );
		$event_id = $this->stream_event();
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->room_request( $event_id )->get_status() );
	}

	public function test_rest_403_not_entitled() {
		do_action( 'rest_api_init' );
		$event_id = $this->stream_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$res = $this->room_request( $event_id );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'anchor_events_room_denied', $res->as_error()->get_error_code() );
	}

	/**
	 * A roomless event (enabled, but no stream — same shape as
	 * test_plain_event_has_no_room()) must 404 for a signed-in, non-entitled
	 * visitor rather than 403: the endpoint must not reveal a room exists at
	 * all for an event that has none.
	 */
	public function test_rest_404_before_403_for_a_roomless_event() {
		do_action( 'rest_api_init' );
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$this->assertSame( '', $this->module()->room_url( $event_id ), 'Sanity: this event has no room.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$res = $this->room_request( $event_id );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'anchor_events_room_missing', $res->as_error()->get_error_code() );
	}

	public function test_rest_omits_the_embed_outside_the_window_and_includes_it_inside() {
		do_action( 'rest_api_init' );
		$event_id = $this->stream_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'rest@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'rest@example.test' ] );
		wp_set_current_user( $user_id );

		$res = $this->room_request( $event_id );
		$out = $res->get_data();
		$this->assertSame( 'countdown', $out['state'] );
		$this->assertStringNotContainsString( 'player.vimeo.com', $out['html'] );

		update_post_meta( $event_id, '_anchor_event_start_ts', time() );
		update_post_meta( $event_id, '_anchor_event_end_ts', time() + 3600 );
		$res     = $this->room_request( $event_id );
		$out     = $res->get_data();
		$headers = $res->get_headers();
		$this->assertSame( 'live', $out['state'] );
		$this->assertStringContainsString( 'player.vimeo.com/video/77', $out['html'] );
		$this->assertSame( 'private, no-store, max-age=0', $headers['Cache-Control'] );
	}
}
