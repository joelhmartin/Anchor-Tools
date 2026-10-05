<?php
/**
 * Native-registration entry points refuse external-signup events, and the
 * external form is rendered before the WooCommerce storefront seam can
 * offer a stale buy button.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group registration
 */
class Test_External_Signup_Guards extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		add_filter( 'wp_redirect', [ $this, 'trap' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', [ $this, 'trap' ] );
		remove_all_filters( 'anchor_events_registration_form' );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function trap( $location ) {
		throw new Anchor_Entitlements_Redirect_Signal( (string) $location );
	}

	private function external_event() {
		return $this->make_event( [
			'registration_mode' => 'external',
			'external_url'      => 'https://form.example/signup',
			'external_embed'    => '<iframe src="https://form.example/embed"></iframe>',
		] );
	}

	private function drive( callable $handler, array $post ) {
		$_POST    = $post;
		$_REQUEST = $post;
		try {
			$handler();
		} catch ( Anchor_Entitlements_Redirect_Signal $e ) {
			return rawurldecode( rawurldecode( $e->getMessage() ) );
		}
		$this->fail( 'Handler did not redirect.' );
	}

	public function test_roster_add_refuses() {
		$ext = $this->external_event();
		$loc = $this->drive( [ $this->module()->roster, 'handle_add' ], [
			'event_id'      => $ext,
			'roster_name'   => 'Jane Doe',
			'roster_email'  => 'jane@example.org',
			'roster_guests' => 0,
			'_wpnonce'      => wp_create_nonce( 'anchor_roster_add_' . $ext ),
		] );
		$this->assertStringContainsString( 'roster_type=error', $loc );
		$this->assertStringContainsString( 'external form', $loc );
		$this->assertSame( 0, $this->count_seats( $ext ) );
	}

	public function test_manual_role_grant_refuses_and_mints_no_account() {
		$ext  = $this->external_event();
		$seat = $this->make_seat( $ext, [ 'email' => 'legacy-grant@example.org' ] );
		$loc  = $this->drive( [ $this->module()->roster, 'handle_grant' ], [
			'event_id' => $ext,
			'seat_id'  => $seat,
			'_wpnonce' => wp_create_nonce( 'anchor_roster_edit_' . $ext ),
		] );
		$this->assertStringContainsString( 'roster_type=error', $loc );
		$this->assertFalse( get_user_by( 'email', 'legacy-grant@example.org' ), 'No account may be created for a grant that was refused.' );
	}

	public function test_external_branch_runs_before_the_storefront_seam() {
		$ext = $this->external_event();
		add_filter( 'anchor_events_registration_form', function () {
			return '<div class="stale-storefront">Buy</div>';
		}, 1 );
		$html = $this->module()->render_registration_form( $ext );
		$this->assertStringNotContainsString( 'stale-storefront', $html );
		$this->assertStringContainsString( 'anchor-event-registration-external', $html );
	}

	public function test_embed_wins_over_the_url() {
		$html = $this->module()->render_registration_form( $this->external_event() );
		$this->assertStringContainsString( '<iframe src="https://form.example/embed"', $html );
		$this->assertStringNotContainsString( 'https://form.example/signup', $html );
	}

	public function test_url_only_renders_the_register_button() {
		$ext = $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/signup' ] );
		$this->assertStringContainsString( 'href="https://form.example/signup"', $this->module()->render_registration_form( $ext ) );
	}
}
