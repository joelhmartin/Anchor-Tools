<?php
/**
 * Anchor Compliance — cookie policy shortcode.
 */
class Test_Compliance_Cookie_Policy extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		parent::tear_down();
	}

	public function test_renders_a_table_per_category() {
		$out = do_shortcode( '[anchor_cookie_policy]' );
		$this->assertStringContainsString( 'anchor-cmp-policy', $out );
		$this->assertStringContainsString( 'Analytics', $out );
		$this->assertStringContainsString( 'Marketing', $out );
		$this->assertStringContainsString( '_fbp', $out );
		$this->assertStringContainsString( 'Meta', $out );
	}

	public function test_categories_attribute_limits_output() {
		$out = do_shortcode( '[anchor_cookie_policy categories="analytics"]' );
		$this->assertStringContainsString( '_ga', $out );
		$this->assertStringNotContainsString( '_fbp', $out, 'Marketing cookies must be excluded.' );
	}

	public function test_disabled_service_is_omitted() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'services' => [ 'meta_pixel' => [ 'enabled' => false, 'category' => 'marketing' ] ],
		], false );
		$this->assertStringNotContainsString( '_fbp', do_shortcode( '[anchor_cookie_policy]' ) );
	}

	public function test_output_is_escaped() {
		add_filter( 'anchor_compliance_services', function ( $s ) {
			$s['evil'] = [
				'name' => '<script>alert(1)</script>', 'provider' => 'X', 'category' => 'analytics',
				'patterns' => [ 'evil.test' ],
				'cookies' => [ [ 'name' => '<img src=x onerror=alert(1)>', 'purpose' => 'p', 'duration' => 'd' ] ],
			];
			return $s;
		} );
		$out = do_shortcode( '[anchor_cookie_policy]' );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $out );
		$this->assertStringNotContainsString( '<img src=x', $out );
		$this->assertStringContainsString( '&lt;', $out );
		remove_all_filters( 'anchor_compliance_services' );
	}
}
