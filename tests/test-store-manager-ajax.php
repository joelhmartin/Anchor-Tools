<?php
/**
 * Anchor Store Locator — front-end manager: AJAX endpoints.
 *
 * Covers the write side of [anchor_store_manager]. These are the paths that
 * carried the bugs this module was tuned up to fix:
 *
 *  - saving a pending store silently demoted it to draft;
 *  - a failed geocode overwrote good coordinates with 0,0;
 *  - the admin metabox and the manager disagreed about whether an address
 *    had changed, because only one of them wrote _anchor_store_address_prev;
 *  - delete and bulk actions checked only a blanket capability, never the
 *    per-post one.
 *
 * @package Anchor\StoreLocator\Tests
 */

class Test_Store_Manager_Ajax extends WP_Ajax_UnitTestCase {

	/** @var \Anchor\StoreLocator\Module */
	private $module;

	/**
	 * Build the module fresh for every test.
	 *
	 * WP_UnitTestCase snapshots $wp_filter once for the whole run and restores
	 * that snapshot after each test, so the wp_ajax_* handlers registered in
	 * wpSetUpBeforeClass would be stripped after the class's first test — and
	 * an unregistered action produces no output at all, which decodes to null
	 * and makes assertions pass or fail for the wrong reason.
	 */
	public function set_up() {
		parent::set_up();

		require_once dirname( __DIR__ ) . '/anchor-store-locator/anchor-store-locator.php';

		$this->module = new \Anchor\StoreLocator\Module();
		$this->module->register_cpt();

		// _handleAjax() fires do_action('admin_init'). WP_Ajax_UnitTestCase
		// detaches the update checks in set_up_before_class, but the global
		// hook snapshot is restored after every test and puts them back — and
		// a failed update check prints warnings straight into the output
		// buffer, corrupting the JSON body under test.
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );

		$this->_setRole( 'administrator' );
		$_POST = [];
		$this->purge_stores();
	}

	private function manager() {
		return $this->module->manager();
	}

	/**
	 * Start every test from an empty store list.
	 *
	 * WP_Ajax_UnitTestCase does not roll these back reliably between tests in
	 * the way the plain test case does, and several assertions here are about
	 * exact counts.
	 */
	private function purge_stores() {
		$existing = get_posts(
			[
				'post_type'      => \Anchor\StoreLocator\Module::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		foreach ( $existing as $id ) {
			wp_delete_post( $id, true );
		}
	}

	public function tear_down() {
		$_POST = [];
		delete_option( 'anchor_schema_settings' );
		parent::tear_down();
	}

	/* ─── Helpers ─── */

	private function make_store( array $args = [], array $meta = [] ) {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_type'   => \Anchor\StoreLocator\Module::CPT,
					'post_status' => 'publish',
					'post_title'  => 'Test Store',
				],
				$args
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, '_anchor_store_' . $key, $value );
		}

		return $post_id;
	}

	/**
	 * Fire an AJAX action and return the decoded response.
	 */
	private function call( $action, array $data = [] ) {
		// WP_Ajax_UnitTestCase's die handler *appends* to _last_response, so a
		// second call in the same test would otherwise concatenate two JSON
		// documents into something json_decode() rejects.
		$this->_last_response = '';
		$_GET                 = [];

		$_POST          = $data;
		$_POST['nonce'] = wp_create_nonce( \Anchor\StoreLocator\Store_Manager::NONCE );
		$_POST['action'] = $action;

		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected: wp_send_json_* dies once the payload is emitted.
		} catch ( WPAjaxDieStopException $e ) {
			// Also expected for the error paths that set a status code.
		}

		$decoded = json_decode( $this->_last_response, true );

		$this->assertNotNull(
			$decoded,
			"AJAX action {$action} did not return valid JSON. Raw response: "
				. substr( $this->_last_response, 0, 800 )
		);

		return $decoded;
	}

	/**
	 * Intercept only Google Maps traffic; hard-block everything else.
	 *
	 * _handleAjax() fires do_action('admin_init'), which can reach
	 * wp_update_plugins(). A blanket pre_http_request stub would hand that
	 * function a geocoding payload and blow up inside core, so non-Maps
	 * requests get a WP_Error — the shape core already knows how to bail on,
	 * and one that guarantees no real network access from the test suite.
	 */
	private function stub_geocode( array $body ) {
		$this->set_api_key();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $body ) {
				if ( strpos( (string) $url, 'maps.googleapis.com' ) === false ) {
					return new WP_Error( 'http_request_blocked', 'Outbound HTTP blocked in tests.' );
				}

				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( $body ),
				];
			},
			10,
			3
		);
	}

	/** Force geocoding to succeed with fixed coordinates. */
	private function stub_geocode_success( $lat = 51.5, $lng = -0.12 ) {
		$this->stub_geocode(
			[
				'status'  => 'OK',
				'results' => [ [ 'geometry' => [ 'location' => [ 'lat' => $lat, 'lng' => $lng ] ] ] ],
			]
		);
	}

	/** Force geocoding to fail at the API level. */
	private function stub_geocode_failure() {
		$this->stub_geocode( [ 'status' => 'ZERO_RESULTS', 'results' => [] ] );
	}

	private function set_api_key() {
		$settings = get_option( 'anchor_schema_settings', [] );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}
		$settings['google_api_key'] = 'test-key';
		update_option( 'anchor_schema_settings', $settings, false );
	}

	/* ─── Nonce and capability ─── */

	public function test_bad_nonce_is_rejected() {
		$_POST = [ 'action' => 'anchor_store_manager_list', 'nonce' => 'not-a-real-nonce' ];

		try {
			$this->_handleAjax( 'anchor_store_manager_list' );
		} catch ( WPAjaxDieContinueException $e ) {
			// fall through
		} catch ( WPAjaxDieStopException $e ) {
			// fall through
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'expired_nonce', $response['data']['code'] );
	}

	public function test_subscriber_cannot_list_stores() {
		$this->_setRole( 'subscriber' );

		$response = $this->call( 'anchor_store_manager_list' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'no_cap', $response['data']['code'] );
	}

	/* ─── Save: status handling ─── */

	public function test_saving_a_pending_store_without_a_status_keeps_it_pending() {
		// Regression: the old form had no "pending" option, so the select
		// resolved to an empty value and the server defaulted to 'draft' —
		// silently demoting the store on every edit.
		$store_id = $this->make_store( [ 'post_status' => 'pending' ] );

		$response = $this->call(
			'anchor_store_manager_save',
			[
				'store_id' => $store_id,
				'title'    => 'Test Store',
				'address'  => '1 Main St',
				'status'   => '',
			]
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'pending', get_post_status( $store_id ) );
	}

	public function test_pending_can_be_saved_explicitly() {
		$store_id = $this->make_store( [ 'post_status' => 'draft' ] );

		$this->call(
			'anchor_store_manager_save',
			[
				'store_id' => $store_id,
				'title'    => 'Test Store',
				'address'  => '1 Main St',
				'status'   => 'pending',
			]
		);

		$this->assertSame( 'pending', get_post_status( $store_id ) );
	}

	public function test_unknown_status_falls_back_to_the_existing_one() {
		$store_id = $this->make_store( [ 'post_status' => 'publish' ] );

		$this->call(
			'anchor_store_manager_save',
			[
				'store_id' => $store_id,
				'title'    => 'Test Store',
				'address'  => '1 Main St',
				'status'   => 'private',
			]
		);

		$this->assertSame( 'publish', get_post_status( $store_id ) );
	}

	public function test_new_stores_default_to_draft() {
		$response = $this->call(
			'anchor_store_manager_save',
			[
				'title'   => 'Brand New Store',
				'address' => '1 Main St',
				'status'  => 'nonsense',
			]
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'draft', get_post_status( $response['data']['id'] ) );
	}

	/* ─── Save: validation ─── */

	public function test_save_requires_a_title() {
		$response = $this->call( 'anchor_store_manager_save', [ 'title' => '', 'address' => '1 Main St' ] );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'title', $response['data']['field'] );
	}

	public function test_save_requires_an_address() {
		$response = $this->call( 'anchor_store_manager_save', [ 'title' => 'Store', 'address' => '' ] );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'address', $response['data']['field'] );
	}

	/* ─── Save: owner ─── */

	public function test_owner_is_saved_and_returned() {
		$response = $this->call(
			'anchor_store_manager_save',
			[
				'title'   => 'Owned Store',
				'address' => '1 Main St',
				'owner'   => 'Dana Reyes',
			]
		);

		$store_id = $response['data']['id'];
		$this->assertSame( 'Dana Reyes', get_post_meta( $store_id, '_anchor_store_owner', true ) );

		$read = $this->call( 'anchor_store_manager_get', [ 'store_id' => $store_id ] );
		$this->assertSame( 'Dana Reyes', $read['data']['owner'] );
	}

	public function test_owner_is_sanitized() {
		$response = $this->call(
			'anchor_store_manager_save',
			[
				'title'   => 'Owned Store',
				'address' => '1 Main St',
				'owner'   => '<script>alert(1)</script>Dana',
			]
		);

		$owner = get_post_meta( $response['data']['id'], '_anchor_store_owner', true );

		$this->assertStringNotContainsString( '<script>', $owner );
	}

	public function test_owner_can_be_cleared() {
		$store_id = $this->make_store( [], [ 'owner' => 'Dana Reyes' ] );

		$this->call(
			'anchor_store_manager_save',
			[
				'store_id' => $store_id,
				'title'    => 'Test Store',
				'address'  => '1 Main St',
				'owner'    => '',
			]
		);

		$this->assertSame( '', get_post_meta( $store_id, '_anchor_store_owner', true ) );
	}

	/* ─── Save: geocoding ─── */

	public function test_geocode_populates_coordinates_for_a_new_address() {
		$this->stub_geocode_success( 51.5, -0.12 );

		$response = $this->call(
			'anchor_store_manager_save',
			[
				'title'   => 'Geo Store',
				'address' => '10 Downing Street, London',
			]
		);

		$store_id = $response['data']['id'];

		$this->assertSame( 'ok', $response['data']['geocode'] );
		$this->assertEquals( 51.5, (float) get_post_meta( $store_id, '_anchor_store_lat', true ) );
		$this->assertEquals( -0.12, (float) get_post_meta( $store_id, '_anchor_store_lng', true ) );
	}

	public function test_failed_geocode_does_not_zero_existing_coordinates() {
		// The bug: a failed lookup wrote 0,0, dropping the map pin into the
		// Atlantic. Good coordinates must survive a failed re-geocode.
		$store_id = $this->make_store( [], [ 'lat' => 40.7128, 'lng' => -74.0060, 'address' => 'Old Address' ] );

		$this->stub_geocode_failure();

		$response = $this->call(
			'anchor_store_manager_save',
			[
				'store_id' => $store_id,
				'title'    => 'Test Store',
				'address'  => 'A Totally Different Address',
			]
		);

		$this->assertSame( 'failed', $response['data']['geocode'] );
		$this->assertEquals( 40.7128, (float) get_post_meta( $store_id, '_anchor_store_lat', true ) );
		$this->assertEquals( -74.0060, (float) get_post_meta( $store_id, '_anchor_store_lng', true ) );
	}

	public function test_missing_api_key_is_reported_rather_than_silently_ignored() {
		delete_option( 'anchor_schema_settings' );

		$response = $this->call(
			'anchor_store_manager_save',
			[
				'title'   => 'No Key Store',
				'address' => '1 Main St',
			]
		);

		$this->assertSame( 'no_key', $response['data']['geocode'] );
	}

	public function test_explicit_coordinates_are_kept_when_the_address_is_unchanged() {
		$store_id = $this->make_store( [], [ 'address' => '1 Main St' ] );
		update_post_meta( $store_id, '_anchor_store_address_prev', '1 Main St' );

		$response = $this->call(
			'anchor_store_manager_save',
			[
				'store_id' => $store_id,
				'title'    => 'Test Store',
				'address'  => '1 Main St',
				'lat'      => '12.5',
				'lng'      => '34.5',
			]
		);

		$this->assertSame( 'manual', $response['data']['geocode'] );
		$this->assertEquals( 12.5, (float) get_post_meta( $store_id, '_anchor_store_lat', true ) );
		$this->assertEquals( 34.5, (float) get_post_meta( $store_id, '_anchor_store_lng', true ) );
	}

	public function test_successful_geocode_records_the_previous_address() {
		// The admin metabox decides whether to re-geocode by comparing against
		// _anchor_store_address_prev. If the manager never writes it, the two
		// save paths disagree and the metabox re-geocodes on every save.
		$this->stub_geocode_success();

		$response = $this->call(
			'anchor_store_manager_save',
			[
				'title'   => 'Geo Store',
				'address' => '10 Downing Street, London',
			]
		);

		$this->assertSame(
			'10 Downing Street, London',
			get_post_meta( $response['data']['id'], '_anchor_store_address_prev', true )
		);
	}

	/* ─── Save: place_id ─── */

	public function test_place_id_is_cleared_when_not_submitted() {
		$store_id = $this->make_store( [], [ 'place_id' => 'ChIJ_old_place' ] );

		$this->call(
			'anchor_store_manager_save',
			[
				'store_id' => $store_id,
				'title'    => 'Test Store',
				'address'  => '1 Main St',
				'place_id' => '',
			]
		);

		$this->assertSame( '', get_post_meta( $store_id, '_anchor_store_place_id', true ) );
	}

	/* ─── Read ─── */

	public function test_get_returns_a_missing_store_as_an_error() {
		$response = $this->call( 'anchor_store_manager_get', [ 'store_id' => 999999 ] );

		$this->assertFalse( $response['success'] );
	}

	public function test_get_rejects_a_post_of_the_wrong_type() {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$response = $this->call( 'anchor_store_manager_get', [ 'store_id' => $page_id ] );

		$this->assertFalse( $response['success'] );
	}

	/* ─── Delete / restore ─── */

	public function test_delete_moves_the_store_to_trash() {
		$store_id = $this->make_store();

		$response = $this->call( 'anchor_store_manager_delete', [ 'store_id' => $store_id ] );

		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['data']['permanent'] );
		$this->assertSame( 'trash', get_post_status( $store_id ) );
	}

	public function test_restore_brings_a_trashed_store_back() {
		$store_id = $this->make_store();
		wp_trash_post( $store_id );

		$response = $this->call( 'anchor_store_manager_restore', [ 'store_id' => $store_id ] );

		$this->assertTrue( $response['success'] );
		$this->assertContains(
			get_post_status( $store_id ),
			array_keys( $this->manager()->editable_statuses() ),
			'A restored store must land on a status the manager actually lists.'
		);
	}

	public function test_permanent_delete_removes_the_store() {
		$store_id = $this->make_store();

		$response = $this->call(
			'anchor_store_manager_delete',
			[ 'store_id' => $store_id, 'permanent' => 1 ]
		);

		$this->assertTrue( $response['success'] );
		$this->assertNull( get_post( $store_id ) );
	}

	public function test_deleting_an_already_trashed_store_removes_it_permanently() {
		$store_id = $this->make_store();
		wp_trash_post( $store_id );

		$this->call( 'anchor_store_manager_delete', [ 'store_id' => $store_id ] );

		$this->assertNull( get_post( $store_id ) );
	}

	/* ─── Duplicate ─── */

	public function test_duplicate_copies_meta_including_owner() {
		$store_id = $this->make_store(
			[ 'post_title' => 'Original Store' ],
			[
				'owner'   => 'Dana Reyes',
				'address' => '1 Main St',
				'phone'   => '555-0134',
			]
		);

		$response = $this->call( 'anchor_store_manager_duplicate', [ 'store_id' => $store_id ] );
		$new_id   = $response['data']['id'];

		$this->assertTrue( $response['success'] );
		$this->assertNotSame( $store_id, $new_id );
		$this->assertSame( 'Dana Reyes', get_post_meta( $new_id, '_anchor_store_owner', true ) );
		$this->assertSame( '1 Main St', get_post_meta( $new_id, '_anchor_store_address', true ) );
		$this->assertSame( '555-0134', get_post_meta( $new_id, '_anchor_store_phone', true ) );
	}

	public function test_duplicate_is_a_draft_with_a_marked_title() {
		$store_id = $this->make_store( [ 'post_title' => 'Original Store' ] );

		$response = $this->call( 'anchor_store_manager_duplicate', [ 'store_id' => $store_id ] );
		$new_id   = $response['data']['id'];

		$this->assertSame( 'draft', get_post_status( $new_id ) );
		$this->assertStringContainsString( 'Original Store', get_post( $new_id )->post_title );
		$this->assertStringContainsString( 'Copy', get_post( $new_id )->post_title );
	}

	/* ─── Bulk ─── */

	public function test_bulk_publish_updates_every_selected_store() {
		$a = $this->make_store( [ 'post_status' => 'draft' ] );
		$b = $this->make_store( [ 'post_status' => 'draft' ] );

		$response = $this->call(
			'anchor_store_manager_bulk',
			[ 'bulk_action' => 'publish', 'ids' => [ $a, $b ] ]
		);

		$this->assertSame( 2, $response['data']['done'] );
		$this->assertSame( 'publish', get_post_status( $a ) );
		$this->assertSame( 'publish', get_post_status( $b ) );
	}

	public function test_bulk_trash_and_restore_round_trip() {
		$a = $this->make_store();

		$this->call( 'anchor_store_manager_bulk', [ 'bulk_action' => 'trash', 'ids' => [ $a ] ] );
		$this->assertSame( 'trash', get_post_status( $a ) );

		$this->call( 'anchor_store_manager_bulk', [ 'bulk_action' => 'restore', 'ids' => [ $a ] ] );
		$this->assertNotSame( 'trash', get_post_status( $a ) );
	}

	public function test_bulk_rejects_an_unknown_action() {
		$a = $this->make_store();

		$response = $this->call(
			'anchor_store_manager_bulk',
			[ 'bulk_action' => 'explode', 'ids' => [ $a ] ]
		);

		$this->assertFalse( $response['success'] );
	}

	public function test_bulk_requires_a_selection() {
		$response = $this->call( 'anchor_store_manager_bulk', [ 'bulk_action' => 'publish', 'ids' => [] ] );

		$this->assertFalse( $response['success'] );
	}

	public function test_bulk_skips_posts_of_the_wrong_type() {
		$store = $this->make_store( [ 'post_status' => 'draft' ] );
		$page  = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$response = $this->call(
			'anchor_store_manager_bulk',
			[ 'bulk_action' => 'publish', 'ids' => [ $store, $page ] ]
		);

		$this->assertSame( 1, $response['data']['done'] );
		$this->assertSame( 1, $response['data']['skipped'] );
	}

	public function test_bulk_skips_stores_the_user_cannot_edit() {
		$store = $this->make_store( [ 'post_status' => 'publish' ] );

		// A contributor holds edit_posts (so passes the manager gate) but
		// cannot edit someone else's published post.
		$this->_setRole( 'contributor' );

		$response = $this->call(
			'anchor_store_manager_bulk',
			[ 'bulk_action' => 'draft', 'ids' => [ $store ] ]
		);

		$this->assertSame( 0, $response['data']['done'] );
		$this->assertSame( 1, $response['data']['skipped'] );
		$this->assertSame( 'publish', get_post_status( $store ) );
	}

	/* ─── List ─── */

	public function test_list_returns_rendered_markup_and_counts() {
		$this->make_store( [ 'post_title' => 'Riverside Clinic' ] );

		$response = $this->call(
			'anchor_store_manager_list',
			[ 'status' => 'any', 'columns' => 'name,owner' ]
		);

		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( 'Riverside Clinic', $response['data']['rows'] );
		$this->assertArrayHasKey( 'head', $response['data'] );
		$this->assertArrayHasKey( 'tabs', $response['data'] );
		$this->assertArrayHasKey( 'bulk', $response['data'] );
		$this->assertArrayHasKey( 'pagination', $response['data'] );
		$this->assertSame( 1, $response['data']['total'] );
	}

	public function test_list_honours_search_over_owner() {
		$this->make_store( [ 'post_title' => 'Store One' ], [ 'owner' => 'Dana Reyes' ] );
		$this->make_store( [ 'post_title' => 'Store Two' ], [ 'owner' => 'Sam Fields' ] );

		$response = $this->call( 'anchor_store_manager_list', [ 's' => 'Dana' ] );

		$this->assertSame( 1, $response['data']['total'] );
		$this->assertStringContainsString( 'Store One', $response['data']['rows'] );
		$this->assertStringNotContainsString( 'Store Two', $response['data']['rows'] );
	}

	/* ─── Heartbeat ─── */

	public function test_heartbeat_returns_a_fresh_nonce() {
		$response = $this->manager()->heartbeat_received( [], [ 'anchor_store_manager' => true ] );

		$this->assertArrayHasKey( 'anchor_store_manager', $response );
		$this->assertNotEmpty( $response['anchor_store_manager']['nonce'] );
	}

	public function test_heartbeat_ignores_unrelated_ticks() {
		$response = $this->manager()->heartbeat_received( [ 'existing' => 1 ], [] );

		$this->assertArrayNotHasKey( 'anchor_store_manager', $response );
	}
}
