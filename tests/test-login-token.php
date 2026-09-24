<?php
/**
 * Stateless room sign-in tokens (virtual-events spec §6.2).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Entitlements;

/**
 * @group login-token
 */
class Test_Login_Token extends Anchor_Events_TestCase {

	public function tear_down() {
		wp_set_current_user( 0 );
		// room_login_notice is request-scoped in production (a fresh Module
		// per request); the test suite's Module singleton lives across
		// tests, so it must be reset here or a rejected-token test would
		// leak its notice into a later, unrelated one.
		$prop = new \ReflectionProperty( \Anchor\Events\Module::class, 'room_login_notice' );
		$prop->setAccessible( true );
		$prop->setValue( $this->module(), '' );
		parent::tear_down();
	}

	/**
	 * room_url() only emits the pretty '/live/' path segment under pretty
	 * permalinks (a Plain-permalink site gets '?live=1' instead); this
	 * suite's sites default to Plain. Same re-registration dance as
	 * Test_Room::pretty_permalinks() — register_cpt() only wires the CPT's
	 * permastruct into $wp_rewrite when a non-empty structure is already the
	 * active option AT REGISTRATION TIME, and register_room_endpoint() needs
	 * to run again too since every test's tear_down() resets $wp to a bare
	 * instance.
	 */
	private function pretty_permalinks() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->module()->register_cpt();
		$this->module()->register_room_endpoint();
		flush_rewrite_rules();
	}

	private function event() {
		return $this->make_event( [
			'registration_mode' => 'free',
			// A stream is what opts an event in on the real save path; these
			// fixtures write meta directly, so they set the switch themselves.
			'access_role_enabled' => true,
			'timezone'          => 'UTC',
			'start_ts'          => time() + DAY_IN_SECONDS,
			'end_ts'            => time() + DAY_IN_SECONDS + 3600,
			'stream_default_modality' => 'virtual',
			'stream_embed' => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/3', 'raw' => '' ],
		] );
	}

	/**
	 * No room, no token. Both ways an event can lack a room: no stream (the
	 * common case, since the access switch defaults on), and the switch
	 * explicitly off despite a stream.
	 */
	public function test_no_token_for_an_event_with_no_room() {
		$user_id = self::factory()->user->create();

		$no_stream = $this->make_event( [ 'registration_mode' => 'free' ] );
		$this->assertSame( '', $this->module()->entitlements->room_url_for( $user_id, $no_stream ) );

		$switched_off = $this->make_event( [
			'registration_mode'       => 'free',
			'access_role_enabled'     => false,
			'stream_default_modality' => 'virtual',
			'stream_embed'            => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/3', 'raw' => '' ],
		] );
		$this->assertSame( '', $this->module()->entitlements->room_url_for( $user_id, $switched_off ) );
	}

	public function test_valid_token_verifies() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$ent      = $this->module()->entitlements;

		$this->assertSame( $user_id, $ent->verify_login_token( $ent->login_token( $user_id, $event_id ), $event_id ) );
	}

	/**
	 * Named to avoid starting with "test_room": PHPUnit's --filter is an
	 * unanchored substring match against "ClassName::methodName", and
	 * "Test_Room" (the sibling suite's --filter target) matches "test_room"
	 * case-insensitively anywhere in that string — so a method literally
	 * named test_room_url_for_carries_the_token gets pulled into a
	 * `--filter Test_Room` run alongside Test_Room's own tests and leaks its
	 * permalink-structure mutation into them.
	 */
	public function test_the_url_from_room_url_for_carries_a_sign_in_token() {
		$this->pretty_permalinks();
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$url      = $this->module()->entitlements->room_url_for( $user_id, $event_id );

		$this->assertStringContainsString( '/live/?aek=', $url );
	}

	public function test_expiry_is_last_session_end_plus_seven_days() {
		$event_id = $this->event();
		$this->assertSame(
			(int) get_post_meta( $event_id, '_anchor_event_end_ts', true ) + ( 7 * DAY_IN_SECONDS ),
			$this->module()->entitlements->token_expiry( $event_id )
		);
	}

	public function test_tampered_token_is_rejected() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$ent      = $this->module()->entitlements;
		$token    = $ent->login_token( $user_id, $event_id );

		$this->assertSame( 0, $ent->verify_login_token( $token . 'x', $event_id ) );
		$this->assertSame( 0, $ent->verify_login_token( $token, $event_id + 1 ), 'A token for one event must not open another.' );
	}

	public function test_expired_token_is_rejected() {
		$event_id = $this->event();
		update_post_meta( $event_id, '_anchor_event_end_ts', time() - ( 30 * DAY_IN_SECONDS ) );
		$user_id = self::factory()->user->create();
		$ent     = $this->module()->entitlements;

		$this->assertSame( 0, $ent->verify_login_token( $ent->login_token( $user_id, $event_id ), $event_id ) );
	}

	public function test_password_change_invalidates_the_token() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$ent      = $this->module()->entitlements;
		$token    = $ent->login_token( $user_id, $event_id );

		wp_set_password( 'a-brand-new-password', $user_id );
		clean_user_cache( $user_id );

		$this->assertSame( 0, $ent->verify_login_token( $token, $event_id ) );
	}

	/* -----------------------------------------------------------------
	 * Module::room_headers()'s aek branch — the actual sign-in wiring, not
	 * just the token primitives above. Reuses Test_Room's wp_redirect trap
	 * pattern (test-room.php, Task 11 fix round) since wp_safe_redirect()
	 * runs it before sending the header.
	 * --------------------------------------------------------------- */

	/**
	 * A valid token in the URL signs the visitor in and redirects to the
	 * CLEAN room URL — the query arg must not survive onto the redirect
	 * target (browser history, Referer header, analytics all see it).
	 */
	public function test_a_valid_token_signs_in_and_redirects_without_the_token_in_the_url() {
		$this->pretty_permalinks();
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$ent      = $this->module()->entitlements;

		wp_set_current_user( 0 );
		$room_url = $this->module()->room_url( $event_id );
		$this->go_to( $ent->room_url_for( $user_id, $event_id ) );
		$this->assertTrue( $this->module()->is_room_request() );

		$trap = static function ( $location ) {
			throw new Anchor_Dispatch_Redirected( (string) $location );
		};
		add_filter( 'wp_redirect', $trap );
		try {
			$this->module()->room_headers();
			$this->fail( 'A valid token did not redirect.' );
		} catch ( Anchor_Dispatch_Redirected $e ) {
			$this->assertSame( $room_url, $e->getMessage(), 'The redirect target must be the clean room URL.' );
			$this->assertStringNotContainsString( Entitlements::TOKEN_ARG . '=', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $trap );
		}

		$this->assertSame( $user_id, get_current_user_id(), 'The token owner must now be signed in.' );
	}

	/**
	 * A token must never switch an ALREADY-signed-in visitor to a different
	 * account — it only ever signs a logged-out visitor in.
	 */
	public function test_a_logged_in_visitor_is_never_switched_by_a_token() {
		$this->pretty_permalinks();
		$event_id    = $this->event();
		$token_owner = self::factory()->user->create();
		$viewer      = self::factory()->user->create();
		$ent         = $this->module()->entitlements;

		wp_set_current_user( $viewer );
		$this->go_to( $ent->room_url_for( $token_owner, $event_id ) );
		$this->module()->room_headers();

		$this->assertSame( $viewer, get_current_user_id(), 'An already-signed-in visitor must never be switched by a token in the URL.' );
	}

	/**
	 * Fix round 1: a rejected token must not leave the visitor with a plain,
	 * unexplained sign-in form — render_room()'s logged-out branch prints
	 * room_headers()'s notice above wp_login_form().
	 */
	public function test_a_tampered_token_renders_the_login_form_with_a_notice() {
		$this->pretty_permalinks();
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$ent      = $this->module()->entitlements;

		wp_set_current_user( 0 );
		$bad_token = $ent->login_token( $user_id, $event_id ) . 'x';
		$this->go_to( \add_query_arg( Entitlements::TOKEN_ARG, $bad_token, $this->module()->room_url( $event_id ) ) );

		$this->module()->room_headers();
		$html = $this->module()->render_room( $event_id );

		$this->assertStringContainsString( 'anchor-room--locked', $html );
		$this->assertStringContainsString( 'anchor-room-notice', $html );
		$this->assertStringContainsString( 'That sign-in link has expired', $html );
	}

	/** An ordinary logged-out visit (no aek at all) shows the form with no notice. */
	public function test_logged_out_with_no_token_renders_the_login_form_with_no_notice() {
		$this->pretty_permalinks();
		$event_id = $this->event();

		wp_set_current_user( 0 );
		$this->go_to( $this->module()->room_url( $event_id ) );

		$this->module()->room_headers();
		$html = $this->module()->render_room( $event_id );

		$this->assertStringContainsString( 'anchor-room--locked', $html );
		$this->assertStringNotContainsString( 'anchor-room-notice', $html );
	}
}
