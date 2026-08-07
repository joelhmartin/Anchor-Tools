<?php
/**
 * Anchor Compliance — consent state resolution.
 */
class Test_Compliance_Consent_State extends WP_UnitTestCase {

	public function tear_down() {
		unset( $_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ], $_SERVER['HTTP_SEC_GPC'] );
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		parent::tear_down();
	}

	private function state() {
		return new Anchor_Compliance_Consent_State();
	}

	private function set_cookie( array $cats, $version = 1, $ts = null ) {
		$_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ] = Anchor_Compliance_Consent_State::encode( [
			'id'   => 'c0ffee00-0000-4000-8000-000000000000',
			'ts'   => $ts ?: time(),
			'v'    => $version,
			'cats' => $cats,
		] );
	}

	public function test_no_cookie_means_no_stored_consent() {
		$s = $this->state();
		$this->assertFalse( $s->has_stored_consent() );
		$this->assertNull( $s->stored() );
	}

	public function test_encode_decode_round_trip() {
		$payload = [ 'id' => 'abc', 'ts' => 1750000000, 'v' => 2, 'cats' => [ 'analytics' ] ];
		$decoded = Anchor_Compliance_Consent_State::decode(
			Anchor_Compliance_Consent_State::encode( $payload )
		);
		$this->assertSame( $payload, $decoded );
	}

	public function test_malformed_cookie_decodes_to_null() {
		$this->assertNull( Anchor_Compliance_Consent_State::decode( 'not-base64-$$$' ) );
		$this->assertNull( Anchor_Compliance_Consent_State::decode( base64_encode( 'plain string' ) ) );
		$this->assertNull( Anchor_Compliance_Consent_State::decode( '' ) );
	}

	public function test_stored_consent_grants_named_categories() {
		$this->set_cookie( [ 'analytics' ] );
		$s = $this->state();
		$this->assertTrue( $s->has_stored_consent() );
		$this->assertTrue( $s->allows( 'necessary' ), 'Necessary is always granted.' );
		$this->assertTrue( $s->allows( 'analytics' ) );
		$this->assertFalse( $s->allows( 'marketing' ) );
		$this->assertFalse( $s->allows( 'functional' ) );
	}

	public function test_stale_policy_version_invalidates_consent() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'policy_version' => 3 ] ], false );
		$this->set_cookie( [ 'analytics', 'marketing' ], 1 ); // recorded under v1
		$s = $this->state();
		$this->assertFalse( $s->has_stored_consent(), 'A policy-version bump must re-prompt.' );
		$this->assertFalse( $s->allows( 'analytics' ) );
	}

	public function test_expired_consent_is_invalid() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'consent_lifetime_days' => 30 ] ], false );
		$this->set_cookie( [ 'marketing' ], 1, time() - ( 31 * DAY_IN_SECONDS ) );
		$this->assertFalse( $this->state()->has_stored_consent() );
	}

	public function test_gpc_header_denies_analytics_and_marketing() {
		$_SERVER['HTTP_SEC_GPC'] = '1';
		$s = $this->state();
		$this->assertTrue( $s->is_gpc() );
		$cats = $s->categories();
		$this->assertTrue( $cats['necessary'] );
		$this->assertFalse( $cats['analytics'] );
		$this->assertFalse( $cats['marketing'] );
	}

	public function test_gpc_overrides_a_permissive_stored_cookie() {
		$this->set_cookie( [ 'analytics', 'marketing', 'functional' ] );
		$_SERVER['HTTP_SEC_GPC'] = '1';
		$s = $this->state();
		$this->assertFalse( $s->allows( 'marketing' ), 'GPC must win over a stored opt-in.' );
		$this->assertFalse( $s->allows( 'analytics' ) );
		$this->assertTrue( $s->allows( 'functional' ), 'GPC covers sale/share, not functional storage.' );
	}

	public function test_gpc_can_be_disabled_in_settings() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'advanced' => [ 'honor_gpc' => false ] ], false );
		$_SERVER['HTTP_SEC_GPC'] = '1';
		$this->assertFalse( $this->state()->is_gpc() );
	}
}
