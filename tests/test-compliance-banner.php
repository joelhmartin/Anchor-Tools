<?php
/**
 * Anchor Compliance — banner render and runtime payload.
 */
class Test_Compliance_Banner extends WP_UnitTestCase {

	public function tear_down() {
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_SEC_GPC'], $_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ] );
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		delete_option( 'anchor_site_config_options' );
		parent::tear_down();
	}

	private function banner() {
		$state = new Anchor_Compliance_Consent_State();
		$geo   = new Anchor_Compliance_Geo();
		return new Anchor_Compliance_Banner(
			$state,
			$geo,
			new Anchor_Compliance_Service_Registry(),
			new Anchor_Compliance_Consent_Mode( $state, $geo )
		);
	}

	public function test_payload_reports_strict_posture_for_eu() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		$p = $this->banner()->payload();
		$this->assertSame( 'strict', $p['posture'] );
		$this->assertFalse( $p['hasConsent'] );
		$this->assertFalse( $p['categories']['analytics'] );
		$this->assertTrue( $p['categories']['necessary'] );
	}

	public function test_payload_reports_optout_posture_for_us() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$p = $this->banner()->payload();
		$this->assertSame( 'optout', $p['posture'] );
		$this->assertTrue( $p['categories']['analytics'], 'Opt-out regions grant by default.' );
	}

	public function test_payload_exposes_cookie_patterns_per_category() {
		$p = $this->banner()->payload();
		$this->assertContains( '_fbp', $p['cookiePatterns']['marketing'] );
		$this->assertContains( '_ga', $p['cookiePatterns']['analytics'] );
		$this->assertArrayNotHasKey( 'necessary', $p['cookiePatterns'], 'Necessary cookies are never deleted.' );
	}

	public function test_payload_carries_the_consent_mode_signal_map() {
		$p = $this->banner()->payload();
		$this->assertSame( 'marketing', $p['signalMap']['ad_storage'] );
		$this->assertSame( 'analytics', $p['signalMap']['analytics_storage'] );
	}

	public function test_gpc_is_surfaced_to_the_runtime() {
		$_SERVER['HTTP_SEC_GPC'] = '1';
		$this->assertTrue( $this->banner()->payload()['gpc'] );
	}

	public function test_brand_colors_inherit_from_site_config() {
		update_option( 'anchor_site_config_options', [
			'colors' => [ 'primary' => '#123456', 'ink' => '#0a0a0a' ],
		], false );
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'appearance' => [ 'inherit_brand' => true ] ], false );

		$colors = $this->banner()->brand_colors();
		$this->assertSame( '#123456', $colors['accent'] );
		$this->assertSame( '#0a0a0a', $colors['text'] );
	}

	public function test_brand_inheritance_can_be_turned_off() {
		update_option( 'anchor_site_config_options', [ 'colors' => [ 'primary' => '#123456' ] ], false );
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'inherit_brand' => false, 'color_accent' => '#ff0000' ],
		], false );
		$this->assertSame( '#ff0000', $this->banner()->brand_colors()['accent'] );
	}

	public function test_render_outputs_all_three_buttons_in_strict_mode() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-anchor-action="accept-all"', $html );
		$this->assertStringContainsString( 'data-anchor-action="reject-all"', $html );
		$this->assertStringContainsString( 'data-anchor-action="customize"', $html );
		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
	}

	public function test_render_includes_a_toggle_for_each_non_necessary_category() {
		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		foreach ( [ 'functional', 'analytics', 'marketing' ] as $cat ) {
			$this->assertStringContainsString( 'data-anchor-category="' . $cat . '"', $html );
		}
		$this->assertStringContainsString( 'disabled', $html, 'Necessary must render as a locked toggle.' );
	}

	public function test_render_is_suppressed_when_module_disabled() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'enabled' => false ] ], false );
		ob_start();
		$this->banner()->render();
		$this->assertSame( '', trim( ob_get_clean() ) );
	}

	public function test_do_not_sell_shortcode_renders_a_link() {
		$out = do_shortcode( '[anchor_do_not_sell]' );
		$this->assertStringContainsString( 'data-anchor-action="do-not-sell"', $out );
		$this->assertStringContainsString( 'Do Not Sell', $out );
	}
}
