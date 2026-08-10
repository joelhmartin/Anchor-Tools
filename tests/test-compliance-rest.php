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
		// D009 (Wave B): geo headers are only honored under a declared trusted proxy.
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'regions' => [ 'trusted_proxy' => 'cloudflare' ] ], false );
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

	/**
	 * Finding 5 (supersedes the C007 header-only pin): the audit row must
	 * record the region/posture the visitor was actually SERVED, so the
	 * record path resolves the FULL geo ladder — tier 2 included — exactly
	 * like the page render did. C007's original quota-drain attack (spoofed
	 * proxy headers minting metered lookups per POST) is closed inside the
	 * geo class now: without a declared trusted proxy, both the geo headers
	 * and the client-IP headers are ignored and the tier-2 lookup keys off
	 * REMOTE_ADDR — which a POSTing attacker cannot rotate — with results
	 * cached per /24 block. This test pins both halves: the lookup happens
	 * (and lands in the row), and it is keyed off REMOTE_ADDR, not off the
	 * spoofed header.
	 */
	public function test_consent_record_resolves_region_through_the_full_geo_ladder() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'regions' => [ 'ip_api_provider' => 'ipinfo', 'ip_api_token' => 'test-token' ],
		], false );
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
		$prev_remote                      = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']           = '203.0.113.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9'; // spoofed — trusted_proxy is 'none'

		$urls = [];
		$mock = function ( $pre, $args, $url ) use ( &$urls ) {
			$urls[] = $url;
			return [ 'headers' => [], 'body' => 'DE', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );

		// A fresh Rest + Geo pair (nothing memoized from earlier tests),
		// invoked directly so the geo path itself is what's exercised.
		$rest = new Anchor_Compliance_Rest( new Anchor_Compliance_Consent_Log(), new Anchor_Compliance_Geo() );
		$req  = new WP_REST_Request( 'POST', '/anchor-compliance/v1/consent' );
		$req->set_param( 'consent_id', '88888888-0000-4000-8000-000000000000' );
		$req->set_param( 'categories', [ 'necessary' ] );
		$req->set_param( 'method', 'banner' );
		$this->posted_consent_ids[] = '88888888-0000-4000-8000-000000000000';

		try {
			$res = $rest->handle_consent( $req );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
			delete_transient( 'anchor_cmp_geo_' . md5( 'ipinfo|203.0.113.0' ) );
			unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
			if ( null === $prev_remote ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $prev_remote;
			}
		}

		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 1, $urls, 'Exactly one tier-2 lookup (memoized for posture()).' );
		$this->assertStringContainsString( '203.0.113.5', $urls[0], 'The lookup must key off REMOTE_ADDR.' );
		$this->assertStringNotContainsString( '198.51.100.9', $urls[0], 'A spoofed client-IP header must never drive the lookup without a declared trusted proxy.' );

		$rows = ( new Anchor_Compliance_Consent_Log() )->query();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'DE', $rows[0]->region, 'The audit row must carry the same full-ladder region the page render would use.' );
		$this->assertSame( 'strict', $rows[0]->posture, 'Posture must match the served experience (DE is in the default strict set).' );
	}

	/** Regression (LAW-5): CPRA opt-out and placeholder grants are honest, recordable methods. */
	public function test_do_not_sell_and_placeholder_methods_are_accepted() {
		foreach ( [ 'do_not_sell' => '99999999-1111-4000-8000-000000000000', 'placeholder' => '99999999-2222-4000-8000-000000000000' ] as $method => $consent_id ) {
			$res = $this->post( [
				'consent_id' => $consent_id,
				'categories' => [ 'necessary' ],
				'method'     => $method,
			] );
			$this->assertSame( 200, $res->get_status(), "Method {$method} must be accepted." );
		}

		$log = new Anchor_Compliance_Consent_Log();
		$this->assertCount( 1, $log->query( [ 'method' => 'do_not_sell' ] ) );
		$this->assertCount( 1, $log->query( [ 'method' => 'placeholder' ] ) );
	}

	/**
	 * Regression (C016): the client's policyVersion is what the visitor
	 * consented under; when supplied it must reach the audit row.
	 */
	public function test_policy_version_param_is_recorded() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'policy_version' => 7 ] ], false );

		$res = $this->post( [
			'consent_id'     => 'aaaa1111-0000-4000-8000-000000000000',
			'categories'     => [ 'necessary' ],
			'method'         => 'banner',
			'policy_version' => 2,
		] );

		$this->assertSame( 200, $res->get_status() );
		$rows = ( new Anchor_Compliance_Consent_Log() )->query();
		$this->assertSame( '2', $rows[0]->policy_version );
	}

	public function test_negative_policy_version_is_rejected() {
		$res = $this->post( [
			'consent_id'     => 'bbbb1111-0000-4000-8000-000000000000',
			'categories'     => [ 'necessary' ],
			'method'         => 'banner',
			'policy_version' => -1,
		] );
		$this->assertSame( 400, $res->get_status() );
	}

	/**
	 * Finding 4: policy_version 0 means "unknown" (a stale-full-page-cache
	 * payload the client normalized to 0). It used to 400 the whole POST,
	 * costing the audit record of a real consent choice. It must be accepted
	 * and recorded under the CURRENT settings version — the same fallback as
	 * an absent key.
	 */
	public function test_policy_version_zero_is_accepted_and_falls_back_to_settings_version() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'policy_version' => 7 ] ], false );

		$res = $this->post( [
			'consent_id'     => 'cccc1111-0000-4000-8000-000000000000',
			'categories'     => [ 'necessary' ],
			'method'         => 'banner',
			'policy_version' => 0,
		] );

		$this->assertSame( 200, $res->get_status(), 'A 0 ("unknown") policy_version must never cost the audit row.' );
		$rows = ( new Anchor_Compliance_Consent_Log() )->query();
		$this->assertCount( 1, $rows );
		$this->assertSame( '7', $rows[0]->policy_version, 'Unknown falls back to the current settings version.' );
	}

	/** An absent policy_version key takes the same settings fallback. */
	public function test_absent_policy_version_falls_back_to_settings_version() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'policy_version' => 7 ] ], false );

		$res = $this->post( [
			'consent_id' => 'dddd1111-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ],
			'method'     => 'banner',
		] );

		$this->assertSame( 200, $res->get_status() );
		$rows = ( new Anchor_Compliance_Consent_Log() )->query();
		$this->assertSame( '7', $rows[0]->policy_version );
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
