<?php
/**
 * Anchor Compliance — consent REST endpoint.
 */
class Test_Compliance_Rest extends WP_UnitTestCase {

	protected $server;

	/** consent_ids used by post() in this test run, so tear_down() can clear their dedupe transients. */
	private $posted_consent_ids = [];

	public function set_up() {
		parent::set_up();
		Anchor_Compliance_Consent_Log::install();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
		$GLOBALS['wpdb']->query( 'TRUNCATE TABLE ' . Anchor_Compliance_Consent_Log::table() );
	}

	public function tear_down() {
		// The dedupe transient and any settings override a test made must
		// never leak into a sibling test — WP_UnitTestCase's per-test
		// transaction rollback covers option rows, but be explicit rather
		// than relying on that for a public, unauthenticated write path.
		foreach ( $this->posted_consent_ids as $consent_id ) {
			delete_transient( 'anchor_cmp_consent_' . md5( $consent_id ) );
		}
		$this->posted_consent_ids = [];
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		parent::tear_down();
	}

	private function post( array $body ) {
		if ( isset( $body['consent_id'] ) ) {
			$this->posted_consent_ids[] = $body['consent_id'];
		}
		$req = new WP_REST_Request( 'POST', '/anchor-compliance/v1/consent' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return $this->server->dispatch( $req );
	}

	public function test_route_is_registered() {
		$this->assertArrayHasKey( '/anchor-compliance/v1/consent', $this->server->get_routes() );
	}

	public function test_valid_post_records_consent() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$res = $this->post( [
			'consent_id' => '11111111-0000-4000-8000-000000000000',
			'categories' => [ 'necessary', 'analytics' ],
			'method'     => 'banner',
		] );

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ok'] );

		$rows = ( new Anchor_Compliance_Consent_Log() )->query();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'US', $rows[0]->region );
		$this->assertSame( 'optout', $rows[0]->posture );
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
	}

	public function test_invalid_category_is_rejected() {
		$res = $this->post( [
			'consent_id' => '22222222-0000-4000-8000-000000000000',
			'categories' => [ 'necessary', 'evil' ],
			'method'     => 'banner',
		] );
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_missing_consent_id_is_rejected() {
		$res = $this->post( [ 'categories' => [ 'necessary' ], 'method' => 'banner' ] );
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_malformed_consent_id_is_rejected() {
		$res = $this->post( [
			'consent_id' => 'not a uuid',
			'categories' => [ 'necessary' ],
			'method'     => 'banner',
		] );
		$this->assertSame( 400, $res->get_status() );
	}

	/**
	 * Intent: a debounced repeat of the SAME consent_id is, by contract, not
	 * a new decision — a real client mints a fresh UUIDv4 per decision, so a
	 * repeat can only be a retry, a double-fired listener, or a replay. The
	 * hook should therefore fire exactly ONCE, on the first (recorded)
	 * request, not once per HTTP call. If a visitor genuinely changes their
	 * mind twice within the debounce window, the client is expected to send
	 * a NEW consent_id for the second decision, which is a distinct dedupe
	 * key and is not exercised by this test.
	 */
	public function test_repeat_consent_id_is_deduped_and_hook_fires_once() {
		$consent_id = '33333333-0000-4000-8000-000000000000';
		$body       = [
			'consent_id' => $consent_id,
			'categories' => [ 'necessary', 'analytics' ],
			'method'     => 'banner',
		];

		$fired    = 0;
		$listener = function () use ( &$fired ) {
			$fired++;
		};
		add_action( 'anchor_compliance_consent_changed', $listener );

		$first = $this->post( $body );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( [ 'ok' => true, 'logged' => true ], $first->get_data() );

		$second = $this->post( $body );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( [ 'ok' => true, 'logged' => false ], $second->get_data() );

		$rows = ( new Anchor_Compliance_Consent_Log() )->query();
		$this->assertCount( 1, $rows );

		$this->assertSame( 1, $fired );

		remove_action( 'anchor_compliance_consent_changed', $listener );
	}

	public function test_disabled_log_returns_200_with_logged_false_and_no_row() {
		$opts                    = Anchor_Compliance_Settings::get();
		$opts['log']['enabled']  = false;
		update_option( Anchor_Compliance_Module::OPTION_KEY, $opts, false );

		$res = $this->post( [
			'consent_id' => '44444444-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ],
			'method'     => 'banner',
		] );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( [ 'ok' => true, 'logged' => false ], $res->get_data() );

		$rows = ( new Anchor_Compliance_Consent_Log() )->query();
		$this->assertCount( 0, $rows );
	}

	public function test_hook_fires_with_categories_and_consent_id_in_order() {
		$consent_id = '55555555-0000-4000-8000-000000000000';
		$categories = [ 'necessary', 'marketing' ];

		$received = null;
		$listener = function ( $cats, $id ) use ( &$received ) {
			$received = [ $cats, $id ];
		};
		add_action( 'anchor_compliance_consent_changed', $listener, 10, 2 );

		$res = $this->post( [
			'consent_id' => $consent_id,
			'categories' => $categories,
			'method'     => 'gpc',
		] );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( [ $categories, $consent_id ], $received );

		remove_action( 'anchor_compliance_consent_changed', $listener, 10 );
	}

	public function test_invalid_method_is_rejected() {
		$res = $this->post( [
			'consent_id' => '66666666-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ],
			'method'     => 'bogus',
		] );
		$this->assertSame( 400, $res->get_status() );
	}

	/** Explicit, not incidental: an empty selection (essentials-only) is a valid consent choice. */
	public function test_empty_categories_array_is_accepted() {
		$res = $this->post( [
			'consent_id' => '77777777-0000-4000-8000-000000000000',
			'categories' => [],
			'method'     => 'banner',
		] );

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ok'] );
	}
}
