<?php
/**
 * Member app — branded /app-connect/ page (replaces wp-admin/authorize-application.php).
 *
 * @package Anchor\Events\Tests
 */

/** @group events-feed */
class Test_App_Connect extends Anchor_Events_TestCase {

	const APP_ID  = '6f5902ac-237c-4f45-8e0d-6b5b5b0d6a01';
	const SUCCESS = 'tmjcentres://auth';

	private function connect() {
		return $this->module()->app_connect;
	}

	private function set_schemes( $value ) {
		update_option( 'anchor_events_settings', [ 'app_schemes' => $value ], false );
	}

	private function login( $role = 'subscriber' ) {
		$id = self::factory()->user->create( [ 'role' => $role ] );
		wp_set_current_user( $id );
		return get_userdata( $id );
	}

	private function input( array $over = [] ) {
		return array_merge(
			[ 'app_name' => 'TMJ Centres', 'app_id' => self::APP_ID, 'success_url' => self::SUCCESS ],
			$over
		);
	}

	private function post( array $over = [] ) {
		return $this->input( array_merge( [ '_anchor_app_nonce' => wp_create_nonce( 'anchor_app_connect' ) ], $over ) );
	}

	public function set_up() {
		parent::set_up();
		$this->set_schemes( 'tmjcentres' );
		// Core only offers Application Passwords over HTTPS (or a local environment type); the test site is plain HTTP.
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	public function test_refused_when_application_passwords_unavailable() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		$this->login();
		$result = $this->connect()->dispatch( $this->input(), false );
		$this->assertSame( 'error', $result['type'] );
		$this->assertContains( 'anchor_app_connect_unavailable', $result['errors']->get_error_codes() );
	}

	public function test_hooked_and_registered() {
		$this->assertNotNull( $this->connect() );
		$this->assertNotFalse( has_action( 'template_redirect', [ $this->connect(), 'maybe_render' ] ) );
	}

	public function test_route_inactive_when_setting_empty() {
		$this->set_schemes( '' );
		$this->assertFalse( $this->connect()->enabled() );
		global $wp_rewrite;
		$before = $wp_rewrite->extra_rules_top;
		$this->connect()->add_rewrite();
		$this->assertSame( $before, $wp_rewrite->extra_rules_top );

		// A request carrying the query var renders nothing and does not exit.
		set_query_var( 'anchor_app_connect', '1' );
		$this->connect()->maybe_render();
		$this->assertTrue( true, 'maybe_render returned without exit/redirect' );
	}

	public function test_rewrite_rule_added_when_enabled() {
		global $wp_rewrite;
		$this->connect()->add_rewrite();
		$this->assertArrayHasKey( '^app-connect/?$', $wp_rewrite->extra_rules_top );
	}

	public function test_flush_signature_tracks_enabled_state() {
		delete_option( 'anchor_events_app_connect_rw' );
		$this->connect()->maybe_flush_rewrites();
		$this->assertSame( 'v1|on', get_option( 'anchor_events_app_connect_rw' ) );
		$this->set_schemes( '' );
		$this->connect()->maybe_flush_rewrites();
		$this->assertSame( 'v1|off', get_option( 'anchor_events_app_connect_rw' ) );
	}

	public function test_logged_out_redirects_to_login_preserving_params() {
		wp_set_current_user( 0 );
		$result = $this->connect()->dispatch( $this->input( [ 'reject_url' => 'tmjcentres://auth?success=false' ] ), false );
		$this->assertSame( 'redirect', $result['type'] );

		$login = $result['url'];
		$this->assertSame( 0, strpos( $login, $this->wc_active() && wc_get_page_permalink( 'myaccount' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url() ) );

		parse_str( (string) wp_parse_url( $login, PHP_URL_QUERY ), $q );
		$return = $q['redirect_to'];
		$this->assertTrue( $this->connect()->is_connect_url( $return ) );
		parse_str( (string) wp_parse_url( $return, PHP_URL_QUERY ), $back );
		$this->assertSame( 'TMJ Centres', $back['app_name'] );
		$this->assertSame( self::APP_ID, $back['app_id'] );
		$this->assertSame( self::SUCCESS, $back['success_url'] );
		$this->assertSame( 'tmjcentres://auth?success=false', $back['reject_url'] );
	}

	public function test_login_form_gets_hidden_redirect_field_for_connect_urls_only() {
		$return = $this->connect()->connect_url( $this->connect()->read_request( $this->input() ) );

		$_GET['redirect_to'] = $return;
		ob_start();
		$this->connect()->emit_return_field();
		$this->assertStringContainsString( 'name="redirect"', ob_get_clean() );

		$_GET['redirect_to'] = 'https://evil.example/app-connect/';
		ob_start();
		$this->connect()->emit_return_field();
		$this->assertSame( '', ob_get_clean() );

		$_GET['redirect_to'] = home_url( '/my-account/' );
		ob_start();
		$this->connect()->emit_return_field();
		$this->assertSame( '', ob_get_clean() );
		unset( $_GET['redirect_to'] );
	}

	public function test_logged_in_get_shows_consent_with_no_side_effects() {
		$user   = $this->login();
		$result = $this->connect()->dispatch( $this->input(), false );
		$this->assertSame( 'consent', $result['type'] );
		$this->assertSame( [], WP_Application_Passwords::get_user_application_passwords( $user->ID ) );
	}

	public function test_administrator_is_refused_with_back_button_to_app() {
		$this->login( 'administrator' );
		$result = $this->connect()->dispatch( $this->input(), false );
		$this->assertSame( 'error', $result['type'] );
		$this->assertContains( 'anchor_events_app_admin', $result['errors']->get_error_codes() );
		$this->assertSame( 'tmjcentres://auth?success=false', $result['back'] );
	}

	public function test_disallowed_scheme_is_refused_and_has_no_back_button() {
		$this->login();
		$result = $this->connect()->dispatch( $this->input( [ 'success_url' => 'evilapp://x' ] ), false );
		$this->assertSame( 'error', $result['type'] );
		$this->assertContains( 'anchor_events_app_scheme', $result['errors']->get_error_codes() );
		$this->assertSame( '', $result['back'] );
	}

	public function test_disallowed_scheme_is_refused_even_when_logged_out() {
		wp_set_current_user( 0 );
		$result = $this->connect()->dispatch( $this->input( [ 'success_url' => 'https://example.com/cb' ] ), false );
		$this->assertSame( 'error', $result['type'] );
	}

	public function test_bad_app_id_is_refused() {
		$this->login();
		$result = $this->connect()->dispatch( $this->input( [ 'app_id' => 'not-a-uuid' ] ), false );
		$this->assertContains( 'invalid_app_id', $result['errors']->get_error_codes() );
	}

	public function test_missing_success_url_is_refused() {
		$this->login();
		$in = $this->input();
		unset( $in['success_url'] );
		$result = $this->connect()->dispatch( $in, false );
		$this->assertContains( 'anchor_app_connect_no_return', $result['errors']->get_error_codes() );
	}

	public function test_allow_creates_application_password_and_redirects_with_credentials() {
		$user   = $this->login();
		$result = $this->connect()->dispatch( $this->post( [ 'approve' => '1' ] ), true );

		$this->assertSame( 'redirect', $result['type'] );
		$this->assertSame( 0, strpos( $result['url'], 'tmjcentres://auth?' ) );
		parse_str( (string) wp_parse_url( $result['url'], PHP_URL_QUERY ), $q );
		$this->assertSame( site_url(), urldecode( $q['site_url'] ) );
		$this->assertSame( $user->user_login, urldecode( $q['user_login'] ) );
		$this->assertNotEmpty( $q['password'] );

		$passwords = WP_Application_Passwords::get_user_application_passwords( $user->ID );
		$this->assertCount( 1, $passwords );
		$this->assertSame( 'TMJ Centres', $passwords[0]['name'] );
		$this->assertSame( self::APP_ID, $passwords[0]['app_id'] );

		// The returned password is the one stored (hash matches).
		$raw = preg_replace( '/[^a-z\d]/i', '', urldecode( $q['password'] ) );
		$this->assertTrue(
			function_exists( 'wp_verify_fast_hash' )
				? wp_verify_fast_hash( $raw, $passwords[0]['password'] )
				: wp_check_password( $raw, $passwords[0]['password'] )
		);
	}

	public function test_cancel_goes_to_reject_url_without_creating_password() {
		$user   = $this->login();
		$result = $this->connect()->dispatch( $this->post( [ 'reject' => '1', 'reject_url' => 'tmjcentres://auth?success=false&x=1' ] ), true );
		$this->assertSame( [ 'type' => 'redirect', 'url' => 'tmjcentres://auth?success=false&x=1' ], $result );
		$this->assertSame( [], WP_Application_Passwords::get_user_application_passwords( $user->ID ) );
	}

	public function test_cancel_defaults_to_success_url_with_success_false() {
		$this->login();
		$result = $this->connect()->dispatch( $this->post( [ 'reject' => '1' ] ), true );
		$this->assertSame( 'tmjcentres://auth?success=false', $result['url'] );
	}

	public function test_bad_nonce_is_refused_and_creates_nothing() {
		$user   = $this->login();
		$result = $this->connect()->dispatch( $this->post( [ 'approve' => '1', '_anchor_app_nonce' => 'bogus' ] ), true );
		$this->assertSame( 'error', $result['type'] );
		$this->assertSame( 403, $result['status'] );
		$this->assertContains( 'anchor_app_connect_nonce', $result['errors']->get_error_codes() );
		$this->assertSame( [], WP_Application_Passwords::get_user_application_passwords( $user->ID ) );
	}

	public function test_missing_nonce_is_refused() {
		$this->login();
		$in = $this->input( [ 'approve' => '1' ] );
		$this->assertSame( 'error', $this->connect()->dispatch( $in, true )['type'] );
	}

	public function test_post_cannot_approve_via_get() {
		$user   = $this->login();
		$result = $this->connect()->dispatch( $this->post( [ 'approve' => '1' ] ), false );
		$this->assertSame( 'consent', $result['type'] );
		$this->assertSame( [], WP_Application_Passwords::get_user_application_passwords( $user->ID ) );
	}

	public function test_consent_and_error_markup_is_escaped() {
		$user = $this->login();
		$user->display_name = '<b>Bob</b>';
		wp_update_user( [ 'ID' => $user->ID, 'display_name' => '<b>Bob</b>' ] );
		wp_set_current_user( $user->ID );
		$request = $this->connect()->read_request( $this->input( [ 'app_name' => '<script>alert(1)</script>' ] ) );

		$m = new ReflectionMethod( $this->connect(), 'render_consent' );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( $this->connect(), $request );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>Bob', $html );
		$this->assertStringContainsString( 'name="approve"', $html );
		$this->assertStringContainsString( 'name="reject"', $html );
		$this->assertStringContainsString( 'action=logout', $html );
	}

	public function test_core_screen_redirects_to_branded_page_for_allowlisted_scheme() {
		global $pagenow;
		$pagenow                   = 'authorize-application.php';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = $this->input();
		set_current_screen( 'dashboard' );

		$target = null;
		add_filter( 'wp_redirect', function ( $location ) use ( &$target ) {
			$target = $location;
			throw new Anchor_Entitlements_Redirect_Signal();
		} );
		try {
			$this->connect()->redirect_core_screen();
			$this->fail( 'expected a redirect' );
		} catch ( Anchor_Entitlements_Redirect_Signal $e ) {
			$this->assertSame( 0, strpos( $target, $this->connect()->base_url() ) );
			$this->assertStringContainsString( 'success_url=tmjcentres%3A%2F%2Fauth', $target );
		}

		// A foreign success_url is left to core (which refuses it).
		$_GET   = $this->input( [ 'success_url' => 'https://example.com/cb' ] );
		$target = null;
		$this->connect()->redirect_core_screen();
		$this->assertNull( $target );
		$_GET = [];
	}
}
