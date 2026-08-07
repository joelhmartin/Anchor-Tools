<?php
/**
 * Anchor Compliance — geo ladder and posture resolution.
 */
class Test_Compliance_Geo extends WP_UnitTestCase {

	public function tear_down() {
		foreach ( array_keys( Anchor_Compliance_Geo::HEADERS ) as $h ) {
			unset( $_SERVER[ $h ] );
		}
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		parent::tear_down();
	}

	private function geo() {
		return new Anchor_Compliance_Geo();
	}

	public function test_cloudflare_header_wins() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'de';
		$g = $this->geo();
		$this->assertSame( 'DE', $g->country(), 'Country must be upper-cased.' );
		$this->assertSame( 'cf', $g->source() );
	}

	public function test_ladder_order_prefers_cloudflare_over_cloudfront() {
		$_SERVER['HTTP_CF_IPCOUNTRY']            = 'FR';
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'US';
		$this->assertSame( 'FR', $this->geo()->country() );
	}

	public function test_falls_through_to_cloudfront_when_cf_absent() {
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'us';
		$g = $this->geo();
		$this->assertSame( 'US', $g->country() );
		$this->assertSame( 'cloudfront', $g->source() );
	}

	public function test_cloudflare_xx_placeholder_is_not_a_country() {
		// Cloudflare sends XX for unknown and T1 for Tor.
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
		$this->assertNull( $this->geo()->country() );
		$this->assertSame( 'none', $this->geo()->source() );
	}

	public function test_malformed_header_is_rejected() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'NOT-A-COUNTRY';
		$this->assertNull( $this->geo()->country() );
	}

	public function test_malformed_earlier_header_falls_through_to_later_valid_one() {
		// A malformed value in an earlier rung must not abort the ladder —
		// aborting is the worst failure mode: one misconfigured edge header
		// would silently push every visitor to the fallback posture.
		$_SERVER['HTTP_CF_IPCOUNTRY']              = 'NOT-A-COUNTRY';
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'DE';
		$g = $this->geo();
		$this->assertSame( 'DE', $g->country() );
		$this->assertSame( 'cloudfront', $g->source() );
	}

	public function test_xx_placeholder_in_earlier_header_falls_through_to_later_valid_one() {
		$_SERVER['HTTP_CF_IPCOUNTRY']      = 'XX';
		$_SERVER['HTTP_X_VERCEL_IP_COUNTRY'] = 'US';
		$g = $this->geo();
		$this->assertSame( 'US', $g->country() );
		$this->assertSame( 'vercel', $g->source() );
	}

	public function test_eu_country_is_strict() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		$this->assertSame( 'strict', $this->geo()->posture() );
		$this->assertTrue( $this->geo()->is_strict() );
	}

	public function test_us_is_optout() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$this->assertSame( 'optout', $this->geo()->posture() );
	}

	public function test_uk_and_brazil_are_strict_by_default() {
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
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'regions' => [ 'strict_countries' => [ 'US' ] ] ], false );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$this->assertSame( 'strict', $this->geo()->posture() );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		$this->assertSame( 'optout', $this->geo()->posture() );
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
