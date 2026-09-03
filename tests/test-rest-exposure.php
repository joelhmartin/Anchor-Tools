<?php
/**
 * Task 7 — Nothing private over REST (COORD-D2, COORD-D4, REG-D19).
 *
 * @package Anchor\Events\Tests
 */

/** @group rest */
class Test_Rest_Exposure extends Anchor_Events_TestCase {

	/**
	 * Fix round 1: WP_UnitTestCase_Base::tear_down() calls
	 * unregister_all_meta_keys() after EVERY test in the whole run, but
	 * register_meta() (the module's `init` callback) only fires once, during
	 * the WP test bootstrap. So from the second test executed anywhere in the
	 * whole suite onward, get_registered_meta_keys()/the REST meta field
	 * return nothing at all — not "everything but the private keys" — which
	 * let test_virtual_url_and_organizer_email_are_not_in_rest_meta pass
	 * vacuously (an empty meta array trivially has neither key) regardless of
	 * whether the leak was fixed. This is the same failure mode already
	 * documented and fixed the same way in Test_Event_Model::set_up() —
	 * re-run register_meta() before every test in this class so the schema
	 * assertions are deterministic no matter what ran before this class/test.
	 *
	 * (An earlier fix attempt reset $wp_rest_server to null before each
	 * dispatch, on the theory that a prior rest_get_server()->get_routes()
	 * call was the trigger. That was disproven: resetting the REST server
	 * singleton does not touch $wp_meta_keys, so the meta response stayed
	 * empty even with a freshly built server. Re-registering meta is the
	 * actual fix — see the RED proof in the commit message.)
	 */
	public function set_up() {
		parent::set_up();
		$this->module()->register_meta();
	}

	public function test_registrations_are_not_a_rest_route() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayNotHasKey( '/wp/v2/anchor_event_reg', $routes );
	}

	public function test_virtual_url_and_organizer_email_are_not_in_rest_meta() {
		$event = $this->make_event( [ 'virtual_url' => 'https://zoom.example/x', 'organizer_email' => 'o@example.com', 'start_date' => '2030-01-01' ] );
		$req = new WP_REST_Request( 'GET', '/wp/v2/event/' . $event );
		$data = rest_get_server()->dispatch( $req )->get_data();
		// Positive control: proves the meta field itself is populated, so an
		// empty/broken response (e.g. from the meta-key wipe above, or any
		// future regression that hides ALL meta) fails this test instead of
		// vacuously satisfying the assertArrayNotHasKey checks below.
		$this->assertArrayHasKey( '_anchor_event_start_date', $data['meta'] ?? [], 'Expected an allow-listed public meta key to be present — an empty meta array must not pass this test.' );
		$this->assertArrayNotHasKey( '_anchor_event_virtual_url', $data['meta'] ?? [] );
		$this->assertArrayNotHasKey( '_anchor_event_organizer_email', $data['meta'] ?? [] );
	}
}
