<?php
/**
 * Anchor Compliance — Google Consent Mode v2 emission.
 */
class Test_Compliance_Consent_Mode extends WP_UnitTestCase {

	public function tear_down() {
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_SEC_GPC'], $_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ] );
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		parent::tear_down();
	}

	private function mode() {
		return new Anchor_Compliance_Consent_Mode(
			new Anchor_Compliance_Consent_State(),
			new Anchor_Compliance_Geo()
		);
	}

	public function test_signal_map_covers_all_seven_v2_signals() {
		$map = Anchor_Compliance_Consent_Mode::signal_map();
		foreach ( [
			'ad_storage', 'ad_user_data', 'ad_personalization',
			'analytics_storage', 'functionality_storage',
			'personalization_storage', 'security_storage',
		] as $signal ) {
			$this->assertArrayHasKey( $signal, $map, "Missing Consent Mode v2 signal: {$signal}" );
		}
	}

	public function test_strict_region_denies_everything_except_security() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		$payload = $this->mode()->defaults_payload();
		$this->assertSame( 'granted', $payload['security_storage'] );
		$this->assertSame( 'denied', $payload['ad_storage'] );
		$this->assertSame( 'denied', $payload['ad_user_data'] );
		$this->assertSame( 'denied', $payload['ad_personalization'] );
		$this->assertSame( 'denied', $payload['analytics_storage'] );
		$this->assertSame( 'denied', $payload['functionality_storage'] );
	}

	public function test_optout_region_grants_by_default() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$payload = $this->mode()->defaults_payload();
		$this->assertSame( 'granted', $payload['ad_storage'] );
		$this->assertSame( 'granted', $payload['analytics_storage'] );
	}

	public function test_gpc_denies_ads_and_analytics_even_in_us() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$_SERVER['HTTP_SEC_GPC']      = '1';
		$payload = $this->mode()->defaults_payload();
		$this->assertSame( 'denied', $payload['ad_storage'] );
		$this->assertSame( 'denied', $payload['analytics_storage'] );
		$this->assertSame( 'granted', $payload['functionality_storage'], 'GPC does not revoke functional storage.' );
	}

	public function test_stored_consent_is_reflected() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		$_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ] = Anchor_Compliance_Consent_State::encode( [
			'id' => 'x', 'ts' => time(), 'v' => 1, 'cats' => [ 'analytics' ],
		] );
		$payload = $this->mode()->defaults_payload();
		$this->assertSame( 'granted', $payload['analytics_storage'] );
		$this->assertSame( 'denied', $payload['ad_storage'] );
	}

	public function test_emit_outputs_gtag_stub_before_defaults() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		ob_start();
		$this->mode()->emit_defaults();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'window.dataLayer', $html );
		$this->assertStringContainsString( "'consent'", $html );
		$this->assertStringContainsString( "'default'", $html );
		$this->assertStringContainsString( 'wait_for_update', $html );
		$this->assertStringContainsString( 'ads_data_redaction', $html );
		$this->assertStringContainsString( 'url_passthrough', $html );

		// The gtag() shim must be defined before the consent default call.
		$this->assertLessThan(
			strpos( $html, "'default'" ),
			strpos( $html, 'function gtag' ),
			'gtag() must be defined before the consent default is pushed.'
		);
	}

	public function test_emit_is_suppressed_when_disabled() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'advanced' => [ 'consent_mode_enabled' => false ] ], false );
		ob_start();
		$this->mode()->emit_defaults();
		$this->assertSame( '', trim( ob_get_clean() ) );
	}
}
