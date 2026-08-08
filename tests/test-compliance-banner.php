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

	public function test_brand_colors_use_module_defaults_when_site_config_is_absent() {
		// anchor_site_config_options was never saved — get_option() returns false.
		$this->assertFalse( get_option( 'anchor_site_config_options' ) );

		$defaults = Anchor_Compliance_Settings::defaults()['appearance'];
		$colors   = $this->banner()->brand_colors();

		$this->assertSame( $defaults['color_accent'], $colors['accent'] );
		$this->assertSame( $defaults['color_surface'], $colors['surface'] );
		$this->assertSame( $defaults['color_text'], $colors['text'] );
	}

	public function test_brand_colors_partial_site_config_fills_gaps_from_module_settings() {
		update_option( 'anchor_site_config_options', [ 'colors' => [ 'primary' => '#123456' ] ], false );

		$defaults = Anchor_Compliance_Settings::defaults()['appearance'];
		$colors   = $this->banner()->brand_colors();

		$this->assertSame( '#123456', $colors['accent'], 'Set key inherits from Site Config.' );
		$this->assertSame( $defaults['color_surface'], $colors['surface'], 'Missing key falls back to module default.' );
		$this->assertSame( $defaults['color_text'], $colors['text'], 'Missing key falls back to module default.' );
	}

	public function test_render_emits_white_ink_for_a_dark_accent() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'inherit_brand' => false, 'color_accent' => '#000000' ],
		], false );

		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( '--acmp-accent-ink:#ffffff', $html );
	}

	public function test_render_emits_black_ink_for_a_light_accent() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'inherit_brand' => false, 'color_accent' => '#ffffff' ],
		], false );

		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( '--acmp-accent-ink:#000000', $html );
	}

	public function test_enqueue_registers_source_assets_with_inline_payload_before_position() {
		wp_deregister_script( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );

		$this->banner()->enqueue();

		$this->assertTrue( wp_script_is( 'anchor-compliance', 'registered' ) );
		$this->assertTrue( wp_style_is( 'anchor-compliance', 'registered' ) );

		$script_src = wp_scripts()->registered['anchor-compliance']->src;
		$style_src  = wp_styles()->registered['anchor-compliance']->src;
		$this->assertStringNotContainsString( '.min.', $script_src, 'Never enqueue a gitignored, CI-generated .min. asset.' );
		$this->assertStringNotContainsString( '.min.', $style_src, 'Never enqueue a gitignored, CI-generated .min. asset.' );

		$before = wp_scripts()->get_data( 'anchor-compliance', 'before' );
		$this->assertNotEmpty( $before, 'Payload must be attached as an inline script in the "before" position.' );
		$this->assertStringContainsString( 'AnchorComplianceData', implode( '', (array) $before ) );

		wp_dequeue_script( 'anchor-compliance' );
		wp_deregister_script( 'anchor-compliance' );
		wp_dequeue_style( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );
	}

	public function test_render_pill_carries_position_modifier_class() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'show_pill' => true, 'pill_position' => 'bottom-left' ],
		], false );
		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'anchor-cmp-pill--bottom-left', $html );
		$this->assertStringNotContainsString( 'anchor-cmp-pill--bottom-right', $html );

		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'show_pill' => true, 'pill_position' => 'bottom-right' ],
		], false );
		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'anchor-cmp-pill--bottom-right', $html );
	}

	public function test_enqueue_registers_brand_tokens_at_root_scope() {
		wp_deregister_script( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );

		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'inherit_brand' => false, 'color_accent' => '#123456' ],
		], false );

		$this->banner()->enqueue();

		$after = wp_styles()->get_data( 'anchor-compliance', 'after' );
		$this->assertNotEmpty( $after, 'Brand tokens must be attached as inline CSS.' );
		$css = implode( '', (array) $after );
		$this->assertStringContainsString( ':root{', $css );
		$this->assertStringContainsString( '--acmp-accent:#123456', $css );
		$this->assertStringContainsString( '--acmp-radius:', $css );

		wp_dequeue_script( 'anchor-compliance' );
		wp_deregister_script( 'anchor-compliance' );
		wp_dequeue_style( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );
	}

	public function test_enqueue_no_ops_when_module_disabled() {
		wp_deregister_script( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'enabled' => false ] ], false );

		$this->banner()->enqueue();

		$this->assertFalse( wp_script_is( 'anchor-compliance', 'registered' ) );
		$this->assertFalse( wp_style_is( 'anchor-compliance', 'registered' ) );
	}
}
