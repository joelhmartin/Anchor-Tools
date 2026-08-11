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

	/* ─── B017: pins for the shortcode's own options ─── */

	/** Disable every functional service that carries cookies so the functional bucket is genuinely empty. */
	private function empty_out_functional() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'services' => [
				'intercom'       => [ 'enabled' => false, 'category' => 'functional' ],
				'drift'          => [ 'enabled' => false, 'category' => 'functional' ],
				'twitter_embeds' => [ 'enabled' => false, 'category' => 'functional' ],
			],
		], false );
	}

	public function test_empty_category_is_skipped_by_default() {
		$this->empty_out_functional();
		$out = do_shortcode( '[anchor_cookie_policy categories="functional"]' );
		$this->assertStringNotContainsString( '<h3>Functional</h3>', $out, 'An empty category renders nothing unless show_empty is set.' );
		$this->assertStringNotContainsString( '<table', $out );
	}

	public function test_show_empty_renders_the_heading_and_an_empty_table() {
		$this->empty_out_functional();
		$out = do_shortcode( '[anchor_cookie_policy categories="functional" show_empty="yes"]' );
		$this->assertStringContainsString( '<h3>Functional</h3>', $out );
		$this->assertStringContainsString( '<tbody></tbody>', $out, 'The table renders with headers and no rows.' );
	}

	/** An unrecognised categories value must not produce an empty policy page. */
	public function test_invalid_categories_fall_back_to_all() {
		$out = do_shortcode( '[anchor_cookie_policy categories="bogus, also-not-real"]' );
		$this->assertStringContainsString( '<h3>Analytics</h3>', $out );
		$this->assertStringContainsString( '<h3>Marketing</h3>', $out );
		$this->assertStringContainsString( '_fbp', $out );
	}

	/** The caller's requested order is preserved. */
	public function test_categories_attribute_order_is_respected() {
		$out = do_shortcode( '[anchor_cookie_policy categories="marketing,analytics"]' );

		$marketing = strpos( $out, '<h3>Marketing</h3>' );
		$analytics = strpos( $out, '<h3>Analytics</h3>' );
		$this->assertNotFalse( $marketing );
		$this->assertNotFalse( $analytics );
		$this->assertLessThan( $analytics, $marketing, 'categories="marketing,analytics" must render Marketing first.' );
	}

	/* ─── B009: custom-rule cookies are disclosed in the rendered table ─── */

	public function test_custom_rule_cookies_render_with_the_rule_label_as_provider() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'custom_rules' => [
				[ 'label' => 'Acme Heatmaps', 'url_pattern' => 'acme-heat.example', 'category' => 'analytics', 'cookie_patterns' => [ '_acme_hm*' ] ],
			],
		], false );

		$out = do_shortcode( '[anchor_cookie_policy categories="analytics"]' );

		$this->assertStringContainsString( '_acme_hm*', $out, 'The swept custom-rule cookie must also be disclosed.' );
		$this->assertStringContainsString( 'Acme Heatmaps', $out, 'The rule label doubles as the provider.' );
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
