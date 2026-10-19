<?php
/**
 * Events feed — Application Passwords hardening for the member app
 * (wp_authorize_application_password_request_errors; setting "Member app URL schemes").
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\App_Auth;

/** @group events-feed */
class Test_Events_Feed_App_Auth extends Anchor_Events_TestCase {

	private function set_schemes( $value ) {
		update_option( 'anchor_events_settings', [ 'app_schemes' => $value ], false );
	}

	private function run_filter( $request, $user ) {
		$error = new WP_Error();
		$this->module()->app_auth->filter_request_errors( $error, $request, $user );
		return $error;
	}

	private function centre() {
		return self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
	}

	private function admin() {
		return self::factory()->user->create_and_get( [ 'role' => 'administrator' ] );
	}

	public function test_hooked_to_core_action() {
		$this->assertNotFalse( has_action( 'wp_authorize_application_password_request_errors', [ $this->module()->app_auth, 'filter_request_errors' ] ) );
	}

	public function test_empty_setting_adds_nothing() {
		delete_option( 'anchor_events_settings' );
		$this->assertSame( '', $this->module()->get_settings()['app_schemes'] );
		$error = $this->run_filter( [ 'success_url' => 'weirdapp://auth' ], $this->admin() );
		$this->assertFalse( $error->has_errors() );
	}

	public function test_allowlisted_scheme_with_centre_user_is_ok() {
		$this->set_schemes( 'tmjcentres' );
		$error = $this->run_filter( [ 'success_url' => 'tmjcentres://auth', 'reject_url' => 'tmjcentres://auth?success=false' ], $this->centre() );
		$this->assertFalse( $error->has_errors() );
	}

	public function test_allowlisted_scheme_with_admin_is_refused() {
		$this->set_schemes( 'tmjcentres' );
		$error = $this->run_filter( [ 'success_url' => 'tmjcentres://auth' ], $this->admin() );
		$this->assertSame( [ 'anchor_events_app_admin' ], $error->get_error_codes() );
	}

	public function test_scheme_match_is_case_insensitive() {
		$this->set_schemes( 'tmjcentres' );
		$error = $this->run_filter( [ 'success_url' => 'TMJCentres://auth' ], $this->admin() );
		$this->assertSame( [ 'anchor_events_app_admin' ], $error->get_error_codes() );
	}

	public function test_unknown_custom_scheme_is_refused() {
		$this->set_schemes( 'tmjcentres' );
		$error = $this->run_filter( [ 'success_url' => 'evilapp://auth' ], $this->centre() );
		$this->assertSame( [ 'anchor_events_app_scheme' ], $error->get_error_codes() );
	}

	public function test_unknown_scheme_in_reject_url_only_is_refused() {
		$this->set_schemes( 'tmjcentres' );
		$error = $this->run_filter( [ 'success_url' => 'tmjcentres://auth', 'reject_url' => 'evilapp://no' ], $this->centre() );
		$this->assertSame( [ 'anchor_events_app_scheme' ], $error->get_error_codes() );
	}

	// A third-party https callback is how a phishing link would collect a password through the consent screen.
	public function test_https_callback_is_refused_when_schemes_are_set() {
		$this->set_schemes( 'tmjcentres' );
		$error = $this->run_filter( [ 'success_url' => 'https://example.com/cb', 'reject_url' => 'https://example.com/cb?success=false' ], $this->centre() );
		$this->assertSame( [ 'anchor_events_app_scheme' ], $error->get_error_codes() );
	}

	public function test_https_callback_is_ok_when_setting_is_empty() {
		$this->set_schemes( '' );
		$error = $this->run_filter( [ 'success_url' => 'https://example.com/cb' ], $this->admin() );
		$this->assertFalse( $error->has_errors() );
	}

	public function test_no_urls_is_ok() {
		$this->set_schemes( 'tmjcentres' );
		$this->assertFalse( $this->run_filter( [ 'app_name' => 'x' ], $this->admin() )->has_errors() );
	}

	public function test_logged_out_user_gets_no_admin_error() {
		$this->set_schemes( 'tmjcentres' );
		$error = $this->run_filter( [ 'success_url' => 'tmjcentres://auth' ], new WP_User( 0 ) );
		$this->assertFalse( $error->has_errors() );
	}

	public function test_works_through_core_validator() {
		$this->set_schemes( 'tmjcentres' );
		$ok = wp_is_authorize_application_password_request_valid( [ 'success_url' => 'tmjcentres://auth' ], $this->centre() );
		$this->assertTrue( $ok );
		$bad = wp_is_authorize_application_password_request_valid( [ 'success_url' => 'tmjcentres://auth' ], $this->admin() );
		$this->assertWPError( $bad );
		$this->assertSame( 'anchor_events_app_admin', $bad->get_error_code() );
	}

	public function test_never_filters_availability() {
		$this->set_schemes( 'tmjcentres' );
		$this->assertFalse( has_filter( 'wp_is_application_passwords_available' ) );
		$this->assertFalse( has_filter( 'wp_is_application_passwords_available_for_user' ) );
	}

	public function test_sanitize_normalizes_schemes() {
		$out = $this->module()->sanitize_settings( [ 'app_schemes' => " TMJCentres, my-app  bad_scheme\n9lives, tmjcentres, a+b.c" ] );
		$this->assertSame( 'tmjcentres, my-app, a+b.c', $out['app_schemes'] );
	}

	public function test_sanitize_missing_key_saves_empty() {
		$this->assertSame( '', $this->module()->sanitize_settings( [] )['app_schemes'] );
	}

	public function test_parse_drops_invalid_tokens() {
		$this->assertSame( [ 'ok' ], App_Auth::parse_schemes( 'ok, 1bad, :x, http://y' ) );
	}
}
