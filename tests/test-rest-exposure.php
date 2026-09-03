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

	/**
	 * REST_PUBLIC_META must not carry entries the per-key schema overrides.
	 *
	 * register_meta() does array_merge( [ 'show_in_rest' => in_array( $key,
	 * REST_PUBLIC_META ) ], $schema ) — the per-key $schema comes SECOND, so a
	 * key that sets its own 'show_in_rest' => false wins and its allow-list
	 * entry does nothing at all. `type` and `external_display_price` were both
	 * listed and both overridden: the constant read as if two metabox-owned
	 * keys were public while the schema kept them hidden, which is the kind of
	 * disagreement that gets "fixed" in the wrong direction later.
	 *
	 * This asserts the invariant rather than the two names, so a future
	 * addition to the list cannot quietly become dead in the same way.
	 */
	public function test_rest_public_meta_holds_no_entries_the_schema_overrides() {
		$schema = new ReflectionMethod( \Anchor\Events\Module::class, 'get_meta_schema' );
		$schema->setAccessible( true );
		$keys = $schema->invoke( $this->module() );

		$dead = [];
		foreach ( \Anchor\Events\Module::REST_PUBLIC_META as $key ) {
			$this->assertArrayHasKey( $key, $keys, "REST_PUBLIC_META lists '$key', which is not a get_meta_schema() key at all." );
			if ( array_key_exists( 'show_in_rest', $keys[ $key ] ) ) {
				$dead[] = $key;
			}
		}

		$this->assertSame( [], $dead, 'These allow-list entries are overridden by their own schema entry and therefore do nothing. Remove them from REST_PUBLIC_META, or drop the per-key show_in_rest — do not leave both.' );
	}

	/** The two removed keys are, and stay, hidden — the overrides are the intent. */
	public function test_metabox_owned_keys_are_not_in_rest_meta() {
		$event = $this->make_event( [ 'type' => 'single', 'external_display_price' => '$495', 'start_date' => '2030-01-01' ] );
		$req   = new WP_REST_Request( 'GET', '/wp/v2/event/' . $event );
		$data  = rest_get_server()->dispatch( $req )->get_data();

		$this->assertArrayHasKey( '_anchor_event_start_date', $data['meta'] ?? [], 'Positive control: an allow-listed key must be present.' );
		$this->assertArrayNotHasKey( '_anchor_event_type', $data['meta'] ?? [] );
		$this->assertArrayNotHasKey( '_anchor_event_external_display_price', $data['meta'] ?? [] );
	}
}
