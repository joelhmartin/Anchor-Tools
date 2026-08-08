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
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
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
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$html = $this->render();
		$this->assertStringContainsString( 'US', $html );
		$this->assertStringContainsString( 'CF-IPCountry', $html, 'The active geo tier must be named.' );
	}

	public function test_warns_when_no_geo_header_is_present() {
		$html = $this->render();
		$this->assertStringContainsString( 'No geo header detected', $html );
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
}
