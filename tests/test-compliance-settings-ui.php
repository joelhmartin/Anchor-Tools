<?php
/**
 * Anchor Compliance — settings screen rendering.
 */
class Test_Compliance_Settings_UI extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_X_GEO_COUNTRY'] );
		parent::tear_down();
	}

	private function render() {
		ob_start();
		( new Anchor_Compliance_Settings() )->render_tab_content();
		return ob_get_clean();
	}

	public function test_renders_every_section() {
		$html = $this->render();
		foreach ( [ 'General', 'Regions', 'Appearance', 'Content', 'Services', 'Custom Rules', 'Consent Log', 'Privacy Requests', 'Advanced' ] as $section ) {
			$this->assertStringContainsString( $section, $html, "Missing settings section: {$section}" );
		}
	}

	public function test_shows_the_detected_region_readout() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'regions' => [ 'trusted_proxy' => 'cloudflare' ] ], false );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$html = $this->render();
		$this->assertStringContainsString( 'US', $html );
		$this->assertStringContainsString( 'CF-IPCountry', $html, 'The active geo tier must be named.' );
	}

	public function test_readout_detects_region_from_an_edge_header_with_zero_config() {
		// D009 (revised): no stored option at all — the 'edge' default must
		// honor a CF-IPCountry header out of the box, so existing
		// Cloudflare-fronted installs need no migration step.
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$html = $this->render();
		$this->assertStringContainsString( 'Detected region: US', $html );
		$this->assertStringNotContainsString( 'No geo header detected', $html );
	}

	public function test_warns_when_no_geo_header_is_present() {
		$html = $this->render();
		$this->assertStringContainsString( 'No geo header detected', $html );
	}

	public function test_readout_names_the_active_trust_mode() {
		$html = $this->render();
		$this->assertStringContainsString( 'Active trust mode', $html );
		$this->assertStringContainsString( 'Edge CDN', $html, 'The default edge mode must be described.' );

		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'regions' => [ 'trusted_proxy' => 'none' ] ], false );
		$html = $this->render();
		$this->assertStringContainsString( 'Active trust mode', $html );
		$this->assertStringContainsString( 'hardened', $html, 'The none mode must be described as the hardened choice.' );
	}

	public function test_readout_explains_an_untrusted_geo_header() {
		// D009 (revised): under the default 'edge' mode the generic
		// X-Geo-Country header is deliberately ignored — the admin must be told
		// the header exists but is not honored, or the "no geo header" message
		// reads as a middlebox misconfiguration.
		$_SERVER['HTTP_X_GEO_COUNTRY'] = 'US';
		$html = $this->render();
		$this->assertStringContainsString( 'No geo header detected', $html );
		$this->assertStringContainsString( 'Trusted proxy', $html );
		$this->assertStringContainsString( 'being ignored', $html );
	}

	public function test_renders_the_trusted_proxy_field_with_all_four_modes() {
		$html = $this->render();
		$this->assertStringContainsString( 'name="' . Anchor_Compliance_Module::OPTION_KEY . '[regions][trusted_proxy]"', $html );
		foreach ( [ 'edge', 'none', 'cloudflare', 'other' ] as $mode ) {
			$this->assertStringContainsString( 'value="' . $mode . '"', $html );
		}
	}

	public function test_lists_every_registry_service_with_a_category_control() {
		$html = $this->render();
		foreach ( [ 'Meta Pixel', 'Hotjar', 'CallTrackingMetrics' ] as $name ) {
			$this->assertStringContainsString( $name, $html );
		}
		$this->assertStringContainsString( 'name="' . Anchor_Compliance_Module::OPTION_KEY . '[services][meta_pixel][category]"', $html );
	}

	public function test_field_names_are_namespaced_to_the_option() {
		$html = $this->render();
		$this->assertStringContainsString( 'name="' . Anchor_Compliance_Module::OPTION_KEY . '[general][privacy_policy_url]"', $html );
		$this->assertStringContainsString( 'name="' . Anchor_Compliance_Module::OPTION_KEY . '[advanced][buffer_enabled]"', $html );
	}

	public function test_includes_the_settings_nonce() {
		$this->assertStringContainsString( 'nonce', $this->render() );
	}

	/* ─── D007: the select can express every stored code ─── */

	public function test_stored_non_default_country_survives_a_render_resave_round_trip() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'regions' => [ 'strict_countries' => [ 'DE', 'CA' ] ] ], false );

		$html = $this->render();

		// CA is not in default_strict_countries() but must render, selected —
		// otherwise the next save silently drops it.
		$this->assertStringContainsString( '<option value="CA" selected="selected">CA</option>', $html );

		// Resave exactly what the rendered form would submit: the selected
		// option values plus the presence sentinel.
		preg_match_all( '/<option value="([A-Z]{2})" selected="selected">/', $html, $m );
		$out = Anchor_Compliance_Settings::sanitize( [
			'regions' => [ 'strict_countries' => $m[1], 'strict_countries_present' => '1' ],
		] );
		$this->assertContains( 'CA', $out['regions']['strict_countries'], 'A stored non-default code must survive the render+resave round trip.' );
		$this->assertContains( 'DE', $out['regions']['strict_countries'] );
	}

	public function test_renders_the_strict_countries_presence_sentinel() {
		// D008: the sentinel is what lets sanitize() see deselect-all as empty.
		$this->assertStringContainsString(
			'name="' . Anchor_Compliance_Module::OPTION_KEY . '[regions][strict_countries_present]"',
			$this->render()
		);
	}

	/* ─── F011: pill off ⇒ persistent shortcode reminder ─── */

	public function test_warns_when_the_pill_is_disabled() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'appearance' => [ 'show_pill' => false ] ], false );
		$html = $this->render();
		$this->assertStringContainsString( 'anchor-cmp-pill-warning', $html );
		$this->assertStringContainsString( '[anchor_consent_link]', $html, 'The warning must name the re-entry shortcode.' );
		$this->assertSame( 2, substr_count( $html, 'anchor-cmp-pill-warning' ), 'Once at the top of the tab, once beside the field.' );
	}

	public function test_no_pill_warning_when_the_pill_is_on() {
		$this->assertStringNotContainsString( 'anchor-cmp-pill-warning', $this->render(), 'show_pill defaults on — no warning.' );
	}

	/* ─── D006 (render half): repeater rows carry the data-index the JS counter seeds from ─── */

	public function test_custom_rule_rows_render_with_their_data_index() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'custom_rules' => [
				[ 'label' => 'A', 'url_pattern' => 'a.example', 'category' => 'marketing', 'cookie_patterns' => [] ],
				[ 'label' => 'B', 'url_pattern' => 'b.example', 'category' => 'marketing', 'cookie_patterns' => [] ],
			],
		], false );

		$html = $this->render();
		$this->assertStringContainsString( 'data-index="0"', $html );
		$this->assertStringContainsString( 'data-index="1"', $html );
		$this->assertStringContainsString( 'data-index="__INDEX__"', $html, 'The clone template must carry the placeholder index.' );
	}

	/* ─── B009: the repeater exposes purpose/duration ─── */

	public function test_custom_rule_row_has_purpose_and_duration_fields() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'custom_rules' => [
				[ 'label' => 'Acme', 'url_pattern' => 'acme.example', 'category' => 'analytics', 'cookie_patterns' => [ '_a*' ], 'purpose' => 'Heatmaps', 'duration' => '1 year' ],
			],
		], false );

		$html = $this->render();
		$this->assertStringContainsString( '[custom_rules][0][purpose]', $html );
		$this->assertStringContainsString( '[custom_rules][0][duration]', $html );
		$this->assertStringContainsString( 'value="Heatmaps"', $html );
		$this->assertStringContainsString( 'value="1 year"', $html );
	}

	/* ─── D024: reset-to-default affordance ─── */

	public function test_content_fields_have_a_reset_to_default_button() {
		$html = $this->render();
		$this->assertStringContainsString( 'anchor-cmp-content-reset', $html );
		$this->assertStringContainsString( 'data-target="anchor_cmp_content_heading"', $html );
	}
}
