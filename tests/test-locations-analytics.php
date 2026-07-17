<?php
/**
 * Tests for anchor-locations Phase 7: Search Console + GA4 Reporting.
 *
 * Covers the network-independent surface — dormancy (no HTTP when unconfigured),
 * config round-trip (autoload=false, private key never echoed), the RS256 JWT +
 * token exchange (in-test RSA key, mocked token endpoint), the pure normalizers,
 * fetch cache population + failure handling (no cache poisoning), metrics_for()
 * path matching, and report-row flagging.
 *
 * All HTTP is mocked via the `pre_http_request` filter. Live GSC/GA4 calls need
 * the owner's real key + granted API access and are verified manually.
 *
 * @package Anchor\Tests
 */

class LocationsAnalyticsTest extends WP_UnitTestCase {

	/** @var \Anchor\Locations\Analytics */
	private $an;

	public function set_up() {
		parent::set_up();
		delete_option( \Anchor\Locations\Analytics::OPTION );
		delete_transient( \Anchor\Locations\Analytics::GSC_TRANSIENT );
		delete_transient( \Anchor\Locations\Analytics::GA4_TRANSIENT );
		$this->an = new \Anchor\Locations\Analytics();
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/** Install a pre_http_request filter that fails the test if ANY HTTP is attempted. */
	private function forbid_http() {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			$this->fail( 'No HTTP should be attempted, but a request went to: ' . $url );
		}, 10, 3 );
	}

	/** Install a pre_http_request stub returning a canned JSON body + status. */
	private function stub_http( array $routes ) {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$routes ) {
			foreach ( $routes as $needle => $resp ) {
				if ( strpos( $url, $needle ) !== false ) {
					if ( $resp instanceof WP_Error ) { return $resp; }
					return [
						'headers'  => [],
						'body'     => is_string( $resp['body'] ) ? $resp['body'] : wp_json_encode( $resp['body'] ),
						'response' => [ 'code' => $resp['code'] ?? 200, 'message' => 'OK' ],
						'cookies'  => [],
						'filename' => null,
					];
				}
			}
			return $pre;
		}, 10, 3 );
	}

	/** Generate an in-test RSA keypair; store the private key + creds in the option. */
	private function configure_with_key( array $extra = [] ) {
		$res = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $res, $private_key );
		$opt = array_merge( [
			'client_email' => 'svc@example-project.iam.gserviceaccount.com',
			'private_key'  => $private_key,
			'token_uri'    => \Anchor\Locations\Analytics::TOKEN_URI,
			'gsc_site'     => 'sc-domain:example.com',
			'ga4_property' => '123456789',
			'key_present'  => true,
		], $extra );
		update_option( \Anchor\Locations\Analytics::OPTION, $opt, false );
	}

	/* ---- A. Dormancy ---- */

	public function test_is_configured_false_when_unset_and_no_http() {
		$this->forbid_http();
		$this->assertFalse( $this->an->is_configured() );
		// Even if something tries to fetch while dormant, it must not hit the network.
		$err = $this->an->fetch_gsc( '2026-01-01', '2026-01-28' );
		$this->assertWPError( $err );
	}

	public function test_is_configured_true_when_key_and_target_present() {
		$this->configure_with_key();
		$this->assertTrue( $this->an->is_configured() );
	}

	public function test_is_configured_false_with_key_but_no_target() {
		$this->configure_with_key( [ 'gsc_site' => '', 'ga4_property' => '' ] );
		$this->assertFalse( $this->an->is_configured() );
	}

	/* ---- A. Config round-trip ---- */

	public function test_save_config_parses_key_and_stores_autoload_false() {
		openssl_pkey_export( openssl_pkey_new( [ 'private_key_bits' => 2048 ] ), $pk );
		$json = wp_json_encode( [
			'type'         => 'service_account',
			'client_email' => 'bot@proj.iam.gserviceaccount.com',
			'private_key'  => $pk,
			'token_uri'    => 'https://oauth2.googleapis.com/token',
		] );

		$ok = $this->an->save_config( $json, 'sc-domain:Example.com', 'abc123456def' );
		$this->assertTrue( $ok );

		$opt = get_option( \Anchor\Locations\Analytics::OPTION );
		$this->assertSame( 'bot@proj.iam.gserviceaccount.com', $opt['client_email'] );
		$this->assertSame( $pk, $opt['private_key'] );
		$this->assertSame( 'sc-domain:example.com', $opt['gsc_site'] ); // lowercased domain.
		$this->assertSame( '123456', $opt['ga4_property'] );             // digits only.

		// autoload=false — the option must not be in the autoloaded set.
		$autoload = wp_load_alloptions();
		$this->assertArrayNotHasKey( \Anchor\Locations\Analytics::OPTION, $autoload );
	}

	public function test_save_config_blank_key_preserves_existing() {
		$this->configure_with_key();
		$before = get_option( \Anchor\Locations\Analytics::OPTION )['private_key'];

		$ok = $this->an->save_config( '', 'sc-domain:new.com', '987654321' );
		$this->assertTrue( $ok );
		$opt = get_option( \Anchor\Locations\Analytics::OPTION );
		$this->assertSame( $before, $opt['private_key'] ); // key kept.
		$this->assertSame( 'sc-domain:new.com', $opt['gsc_site'] );
		$this->assertSame( '987654321', $opt['ga4_property'] );
	}

	public function test_save_config_rejects_garbage_key() {
		$err = $this->an->save_config( 'not json at all', 'sc-domain:x.com', '1' );
		$this->assertWPError( $err );
	}

	public function test_private_key_never_rendered_in_config_form() {
		$this->configure_with_key( [ 'private_key' => "-----BEGIN PRIVATE KEY-----\nSUPERSECRETKEYMATERIAL\n-----END PRIVATE KEY-----" ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$ref = new ReflectionMethod( $this->an, 'render_config_form' );
		$ref->setAccessible( true );
		ob_start();
		$ref->invoke( $this->an );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'SUPERSECRETKEYMATERIAL', $html );
		$this->assertStringContainsString( 'Configured', $html );
		$this->assertStringContainsString( 'svc@example-project.iam.gserviceaccount.com', $html );
	}

	/* ---- B. JWT + token exchange ---- */

	public function test_get_access_token_returns_token_and_caches() {
		$this->configure_with_key();
		$hits = 0;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$hits ) {
			if ( strpos( $url, 'oauth2.googleapis.com/token' ) !== false ) {
				$hits++;
				// Capture + validate the JWT that was sent.
				$this->assertArrayHasKey( 'assertion', $args['body'] );
				$parts = explode( '.', $args['body']['assertion'] );
				$this->assertCount( 3, $parts );
				$header = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
				$claims = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
				$this->assertSame( 'RS256', $header['alg'] );
				$this->assertSame( 'JWT', $header['typ'] );
				$this->assertSame( 'svc@example-project.iam.gserviceaccount.com', $claims['iss'] );
				$this->assertSame( \Anchor\Locations\Analytics::TOKEN_URI, $claims['aud'] );
				$this->assertStringContainsString( 'webmasters.readonly', $claims['scope'] );
				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'access_token' => 'x', 'expires_in' => 3600, 'token_type' => 'Bearer' ] ),
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [], 'filename' => null,
				];
			}
			return $pre;
		}, 10, 3 );

		$t1 = $this->an->get_access_token( [ \Anchor\Locations\Analytics::SCOPE_GSC ] );
		$this->assertSame( 'x', $t1 );
		$t2 = $this->an->get_access_token( [ \Anchor\Locations\Analytics::SCOPE_GSC ] );
		$this->assertSame( 'x', $t2 );
		$this->assertSame( 1, $hits, 'Second call should be served from the transient cache.' );
	}

	public function test_get_access_token_error_on_non_200() {
		$this->configure_with_key();
		$this->stub_http( [ 'oauth2.googleapis.com/token' => [ 'code' => 401, 'body' => [ 'error' => 'invalid_grant', 'error_description' => 'bad' ] ] ] );
		$err = $this->an->get_access_token( [ \Anchor\Locations\Analytics::SCOPE_GSC ] );
		$this->assertWPError( $err );
	}

	/* ---- C. Normalizers (pure) ---- */

	public function test_normalize_gsc_maps_by_path() {
		$body = [ 'rows' => [
			[ 'keys' => [ 'https://example.com/service-areas/pittsburgh/' ], 'clicks' => 12, 'impressions' => 340, 'ctr' => 0.0353, 'position' => 8.2 ],
			[ 'keys' => [ 'https://example.com/services/roofing/pittsburgh/' ], 'clicks' => 3, 'impressions' => 90, 'ctr' => 0.0333, 'position' => 14.7 ],
		] ];
		$map = $this->an->normalize_gsc( $body );
		$this->assertSame( 12, $map['/service-areas/pittsburgh']['clicks'] );
		$this->assertSame( 340, $map['/service-areas/pittsburgh']['impressions'] );
		$this->assertEqualsWithDelta( 8.2, $map['/service-areas/pittsburgh']['position'], 0.001 );
		$this->assertSame( 3, $map['/services/roofing/pittsburgh']['clicks'] );
	}

	public function test_normalize_ga4_maps_by_path() {
		$body = [ 'rows' => [
			[ 'dimensionValues' => [ [ 'value' => '/service-areas/pittsburgh/' ] ], 'metricValues' => [ [ 'value' => '55' ], [ 'value' => '4' ] ] ],
			[ 'dimensionValues' => [ [ 'value' => '/services/roofing/pittsburgh' ] ], 'metricValues' => [ [ 'value' => '9' ], [ 'value' => '0' ] ] ],
		] ];
		$map = $this->an->normalize_ga4( $body );
		$this->assertSame( 55, $map['/service-areas/pittsburgh']['sessions'] );
		$this->assertSame( 4.0, $map['/service-areas/pittsburgh']['conversions'] );
		$this->assertSame( 9, $map['/services/roofing/pittsburgh']['sessions'] );
	}

	/* ---- C. Fetch cache + failure handling ---- */

	public function test_fetch_gsc_populates_cache() {
		$this->configure_with_key();
		$this->stub_http( [
			'oauth2.googleapis.com/token'          => [ 'code' => 200, 'body' => [ 'access_token' => 'x', 'expires_in' => 3600 ] ],
			'searchconsole.googleapis.com'         => [ 'code' => 200, 'body' => [ 'rows' => [
				[ 'keys' => [ 'https://example.com/services/roofing/akron/' ], 'clicks' => 7, 'impressions' => 200, 'ctr' => 0.035, 'position' => 12.0 ],
			] ] ],
		] );
		$map = $this->an->fetch_gsc( '2026-01-01', '2026-01-28' );
		$this->assertIsArray( $map );
		$this->assertArrayHasKey( '/services/roofing/akron', $map );

		$cached = get_transient( \Anchor\Locations\Analytics::GSC_TRANSIENT );
		$this->assertIsArray( $cached );
		$this->assertSame( 7, $cached['/services/roofing/akron']['clicks'] );
	}

	public function test_fetch_gsc_error_does_not_poison_cache() {
		$this->configure_with_key();
		// Prime a good cache first.
		set_transient( \Anchor\Locations\Analytics::GSC_TRANSIENT, [ '/x' => [ 'clicks' => 1, 'impressions' => 1, 'ctr' => 0.0, 'position' => 1.0 ] ], HOUR_IN_SECONDS );

		$this->stub_http( [
			'oauth2.googleapis.com/token'  => [ 'code' => 200, 'body' => [ 'access_token' => 'x', 'expires_in' => 3600 ] ],
			'searchconsole.googleapis.com' => [ 'code' => 500, 'body' => [ 'error' => [ 'message' => 'backend error' ] ] ],
		] );
		$err = $this->an->fetch_gsc( '2026-01-01', '2026-01-28' );
		$this->assertWPError( $err );

		// Old cache remains intact.
		$cached = get_transient( \Anchor\Locations\Analytics::GSC_TRANSIENT );
		$this->assertSame( 1, $cached['/x']['clicks'] );
	}

	public function test_fetch_ga4_error_on_wp_error_transport() {
		$this->configure_with_key();
		$this->stub_http( [
			'oauth2.googleapis.com/token'      => [ 'code' => 200, 'body' => [ 'access_token' => 'x', 'expires_in' => 3600 ] ],
			'analyticsdata.googleapis.com'     => new WP_Error( 'http_request_failed', 'timeout' ),
		] );
		$err = $this->an->fetch_ga4( '2026-01-01', '2026-01-28' );
		$this->assertWPError( $err );
		$this->assertFalse( get_transient( \Anchor\Locations\Analytics::GA4_TRANSIENT ) );
	}

	/* ---- C. metrics_for() merge by path ---- */

	public function test_metrics_for_merges_gsc_and_ga4_by_path() {
		set_transient( \Anchor\Locations\Analytics::GSC_TRANSIENT, [
			'/services/roofing/akron' => [ 'clicks' => 7, 'impressions' => 200, 'ctr' => 0.035, 'position' => 12.0 ],
		], HOUR_IN_SECONDS );
		set_transient( \Anchor\Locations\Analytics::GA4_TRANSIENT, [
			'/services/roofing/akron' => [ 'sessions' => 40, 'conversions' => 3.0 ],
		], HOUR_IN_SECONDS );

		$m = $this->an->metrics_for( 'https://example.com/services/roofing/akron/' );
		$this->assertSame( 7, $m['clicks'] );
		$this->assertSame( 200, $m['impressions'] );
		$this->assertSame( 40, $m['sessions'] );
		$this->assertSame( 3.0, $m['conversions'] );
	}

	public function test_metrics_for_defaults_to_zero_when_no_data() {
		$m = $this->an->metrics_for( 'https://example.com/nope/' );
		$this->assertSame( 0, $m['clicks'] );
		$this->assertSame( 0, $m['sessions'] );
		$this->assertSame( 0.0, $m['position'] );
	}

	/* ---- D. Report rows + flags ---- */

	public function test_flags_for_opportunity_and_zero_traffic() {
		$opp  = $this->an->flags_for( [ 'clicks' => 2, 'impressions' => 150, 'ctr' => 0.01, 'position' => 15.0, 'sessions' => 5, 'conversions' => 0 ] );
		$this->assertContains( 'opportunity', $opp );
		$this->assertNotContains( 'zero_traffic', $opp );

		$zero = $this->an->flags_for( [ 'clicks' => 0, 'impressions' => 3, 'ctr' => 0, 'position' => 5.0, 'sessions' => 0, 'conversions' => 0 ] );
		$this->assertContains( 'zero_traffic', $zero );
		$this->assertNotContains( 'opportunity', $zero );

		$good = $this->an->flags_for( [ 'clicks' => 20, 'impressions' => 500, 'ctr' => 0.04, 'position' => 3.0, 'sessions' => 60, 'conversions' => 5 ] );
		$this->assertSame( [], $good );
	}

	public function test_report_rows_lists_published_pages_with_metrics() {
		$loc  = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Akron' ] );
		$path = rtrim( (string) wp_parse_url( get_permalink( $loc ), PHP_URL_PATH ), '/' );
		if ( $path === '' ) { $path = '/'; }

		set_transient( \Anchor\Locations\Analytics::GSC_TRANSIENT, [
			$path => [ 'clicks' => 9, 'impressions' => 111, 'ctr' => 0.08, 'position' => 4.0 ],
		], HOUR_IN_SECONDS );

		$rows = $this->an->report_rows();
		$this->assertNotEmpty( $rows );
		$row = null;
		foreach ( $rows as $r ) { if ( $r['id'] === $loc ) { $row = $r; } }
		$this->assertNotNull( $row );
		$this->assertSame( 'location', $row['type'] );
		$this->assertSame( 9, $row['metrics']['clicks'] );
	}
}
