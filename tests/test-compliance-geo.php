<?php
/**
 * Anchor Compliance — geo ladder and posture resolution.
 *
 * D009/D010: geo and client-IP headers are only honored when the
 * regions.trusted_proxy setting names the proxy that produces them
 * ('none' | 'cloudflare' | 'other'; default 'none'). Tests that exercise a
 * header therefore declare the matching trust mode first — and the spoof
 * tests pin that an undeclared header is ignored.
 */
class Test_Compliance_Geo extends WP_UnitTestCase {

	public function tear_down() {
		foreach ( array_keys( Anchor_Compliance_Geo::HEADERS ) as $h ) {
			unset( $_SERVER[ $h ] );
		}
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		parent::tear_down();
	}

	private function geo() {
		return new Anchor_Compliance_Geo();
	}

	/** Store a regions section with the given trusted_proxy mode (plus extras). */
	private function trust( $mode, array $extra_regions = [] ) {
		update_option(
			Anchor_Compliance_Module::OPTION_KEY,
			[ 'regions' => array_merge( [ 'trusted_proxy' => $mode ], $extra_regions ) ],
			false
		);
	}

	/* ─── D009: trust gating ─── */

	public function test_geo_headers_are_ignored_by_default_spoof_attempt() {
		// No trusted_proxy configured (default 'none'): every client-forgeable
		// geo header must be ignored, no matter how many arrive.
		$_SERVER['HTTP_CF_IPCOUNTRY']              = 'US';
		$_SERVER['HTTP_X_GEO_COUNTRY']             = 'US';
		$_SERVER['HTTP_X_VERCEL_IP_COUNTRY']       = 'US';
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'US';

		$g = $this->geo();
		$this->assertNull( $g->country(), 'A spoofed geo header must not resolve a country under trusted_proxy=none.' );
		$this->assertSame( 'none', $g->source() );
		// And the posture stays at the (strict) unknown fallback — the spoof
		// cannot flip a strict-region visitor to opt-out.
		$this->assertSame( 'strict', $this->geo()->posture() );
	}

	public function test_server_side_geoip_is_trusted_even_with_no_proxy() {
		// GEOIP_COUNTRY_CODE is a server env var (mod_geoip), not an HTTP
		// header — PHP prefixes client headers with HTTP_, so it cannot be
		// injected remotely and is honored in every mode.
		$_SERVER['GEOIP_COUNTRY_CODE'] = 'FR';
		$g = $this->geo();
		$this->assertSame( 'FR', $g->country() );
		$this->assertSame( 'geoip', $g->source() );
	}

	public function test_cloudflare_mode_ignores_non_cloudflare_headers() {
		$this->trust( 'cloudflare' );
		$_SERVER['HTTP_X_GEO_COUNTRY']             = 'US';
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'US';
		$this->assertNull( $this->geo()->country(), 'Under cloudflare trust, only CF-IPCountry is believed.' );
	}

	public function test_other_mode_honors_reverse_proxy_geo_headers() {
		$this->trust( 'other' );
		$_SERVER['HTTP_X_GEO_COUNTRY'] = 'de';
		$g = $this->geo();
		$this->assertSame( 'DE', $g->country() );
		$this->assertSame( 'xgeo', $g->source() );
	}

	public function test_other_mode_ignores_the_cloudflare_header() {
		$this->trust( 'other' );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$this->assertNull( $this->geo()->country() );
	}

	public function test_unknown_trust_mode_falls_back_to_none() {
		$this->trust( 'totally-bogus' );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$this->assertNull( $this->geo()->country() );
	}

	/* ─── Header parsing / ladder (under a declared trust mode) ─── */

	public function test_cloudflare_header_wins() {
		$this->trust( 'cloudflare' );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'de';
		$g = $this->geo();
		$this->assertSame( 'DE', $g->country(), 'Country must be upper-cased.' );
		$this->assertSame( 'cf', $g->source() );
	}

	public function test_falls_through_to_cloudfront_when_trusted() {
		$this->trust( 'other' );
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'us';
		$g = $this->geo();
		$this->assertSame( 'US', $g->country() );
		$this->assertSame( 'cloudfront', $g->source() );
	}

	public function test_cloudflare_xx_placeholder_is_not_a_country() {
		$this->trust( 'cloudflare' );
		// Cloudflare sends XX for unknown and T1 for Tor.
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
		$this->assertNull( $this->geo()->country() );
		$this->assertSame( 'none', $this->geo()->source() );
	}

	public function test_malformed_header_is_rejected() {
		$this->trust( 'cloudflare' );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'NOT-A-COUNTRY';
		$this->assertNull( $this->geo()->country() );
	}

	public function test_malformed_earlier_header_falls_through_to_later_valid_one() {
		// A malformed value in an earlier trusted rung must not abort the
		// ladder — aborting is the worst failure mode: one misconfigured edge
		// header would silently push every visitor to the fallback posture.
		// Both rungs here belong to the 'other' trust mode.
		$this->trust( 'other' );
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'NOT-A-COUNTRY';
		$_SERVER['HTTP_X_VERCEL_IP_COUNTRY']       = 'DE';
		$g = $this->geo();
		$this->assertSame( 'DE', $g->country() );
		$this->assertSame( 'vercel', $g->source() );
	}

	public function test_xx_placeholder_in_earlier_header_falls_through_to_later_valid_one() {
		$this->trust( 'other' );
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'XX';
		$_SERVER['HTTP_X_VERCEL_IP_COUNTRY']       = 'US';
		$g = $this->geo();
		$this->assertSame( 'US', $g->country() );
		$this->assertSame( 'vercel', $g->source() );
	}

	/* ─── Posture ─── */

	public function test_eu_country_is_strict() {
		$this->trust( 'cloudflare' );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		$this->assertSame( 'strict', $this->geo()->posture() );
		$this->assertTrue( $this->geo()->is_strict() );
	}

	public function test_us_is_optout() {
		$this->trust( 'cloudflare' );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$this->assertSame( 'optout', $this->geo()->posture() );
	}

	public function test_uk_and_brazil_are_strict_by_default() {
		$this->trust( 'cloudflare' );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'GB';
		$this->assertSame( 'strict', $this->geo()->posture() );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'BR';
		$this->assertSame( 'strict', $this->geo()->posture() );
	}

	public function test_unknown_country_uses_configured_fallback() {
		$this->assertSame( 'strict', $this->geo()->posture(), 'Default fallback is strict.' );

		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'regions' => [ 'unknown_fallback' => 'optout' ] ], false );
		$this->assertSame( 'optout', $this->geo()->posture() );
	}

	public function test_strict_country_list_is_configurable() {
		$this->trust( 'cloudflare', [ 'strict_countries' => [ 'US' ] ] );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$this->assertSame( 'strict', $this->geo()->posture() );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		$this->assertSame( 'optout', $this->geo()->posture() );
	}

	/* ─── D010: client_ip trust gating (reflection — see ip_block note) ─── */

	private function client_ip( Anchor_Compliance_Geo $geo ) {
		$method = new ReflectionMethod( $geo, 'client_ip' );
		$method->setAccessible( true );
		return $method->invoke( $geo );
	}

	public function test_spoofed_connecting_ip_headers_are_ignored_without_a_trusted_proxy() {
		$remote                           = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']           = '203.0.113.7';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
		$_SERVER['HTTP_X_REAL_IP']        = '198.51.100.2';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.3';

		$this->assertSame( '203.0.113.7', $this->client_ip( $this->geo() ), 'Under trusted_proxy=none only REMOTE_ADDR counts — a spoofed IP header must not drive Tier-2 lookups or mint cache entries.' );

		$_SERVER['REMOTE_ADDR'] = $remote;
	}

	public function test_cloudflare_mode_prefers_cf_connecting_ip() {
		$this->trust( 'cloudflare' );
		$remote                           = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']           = '203.0.113.7';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
		$_SERVER['HTTP_X_REAL_IP']        = '198.51.100.2';

		$this->assertSame( '198.51.100.1', $this->client_ip( $this->geo() ) );

		$_SERVER['REMOTE_ADDR'] = $remote;
	}

	public function test_other_mode_prefers_x_real_ip_and_ignores_cf() {
		$this->trust( 'other' );
		$remote                           = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']           = '203.0.113.7';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
		$_SERVER['HTTP_X_REAL_IP']        = '198.51.100.2';

		$this->assertSame( '198.51.100.2', $this->client_ip( $this->geo() ) );

		$_SERVER['REMOTE_ADDR'] = $remote;
	}

	/**
	 * ip_block() is private, so this reaches it via reflection rather than
	 * driving the whole Tier-2 HTTP path (which would need to mock
	 * wp_remote_get and a configured provider/token just to exercise a pure
	 * string-transform helper). Reflection is the more honest test here: it
	 * pins the exact truncation behavior without coupling to unrelated HTTP
	 * plumbing.
	 */
	public function test_ipv6_cache_key_truncates_to_64_bit_prefix_even_when_compressed() {
		$geo    = $this->geo();
		$method = new ReflectionMethod( $geo, 'ip_block' );
		$method->setAccessible( true );

		// Compressed notation — the form virtually all real IPv6 addresses
		// arrive in. Must truncate to the /64 network, not swallow it whole.
		$this->assertSame( '2001:db8::', $method->invoke( $geo, '2001:db8::1' ) );
		$this->assertSame( 'fe80::', $method->invoke( $geo, 'fe80::1' ) );

		// Two different hosts in the same /64 must collapse to one key —
		// that's the entire point of truncating (privacy + cache economy).
		$this->assertSame(
			$method->invoke( $geo, '2001:db8::1' ),
			$method->invoke( $geo, '2001:db8::2' ),
			'Two hosts in the same /64 must hash to the same block.'
		);

		// A fully-expanded address must truncate identically to its
		// compressed equivalent.
		$this->assertSame(
			$method->invoke( $geo, '2001:db8::1' ),
			$method->invoke( $geo, '2001:0db8:0000:0000:0000:0000:0000:0001' )
		);
	}
}
