<?php
/**
 * Anchor Compliance — output-buffer script blocker.
 */
class Test_Compliance_Blocker extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE'; // strict, so nothing is pre-granted
	}

	public function tear_down() {
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ] );
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		parent::tear_down();
	}

	private function blocker() {
		return new Anchor_Compliance_Script_Blocker(
			new Anchor_Compliance_Service_Registry(),
			new Anchor_Compliance_Consent_State(),
			new Anchor_Compliance_Geo()
		);
	}

	public function test_blocks_a_marketing_script() {
		$html = '<html><head><script src="https://connect.facebook.net/en_US/fbevents.js"></script></head><body></body></html>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertStringContainsString( 'type="text/plain"', $out );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out );
		$this->assertStringContainsString( 'data-anchor-src="https://connect.facebook.net/en_US/fbevents.js"', $out );
		$this->assertStringNotContainsString( ' src="https://connect.facebook.net', $out, 'The live src must be removed.' );
	}

	public function test_leaves_unmatched_scripts_alone() {
		$html = '<script src="https://example.com/theme/app.js"></script>';
		$this->assertSame( $html, $this->blocker()->rewrite( $html ) );
	}

	public function test_never_blocks_google_tag_manager() {
		// GTM is handled by Consent Mode, not by blocking.
		$html = '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-XXX"></script>';
		$out  = $this->blocker()->rewrite( $html );
		$this->assertStringNotContainsString( 'text/plain', $out, 'GTM must never be hard-blocked.' );
	}

	public function test_replaces_an_existing_type_attribute() {
		$html = '<script type="text/javascript" src="https://static.hotjar.com/c/hotjar-1.js"></script>';
		$out  = $this->blocker()->rewrite( $html );
		$this->assertStringContainsString( 'type="text/plain"', $out );
		$this->assertStringNotContainsString( 'type="text/javascript"', $out );
	}

	public function test_blocks_inline_script_matching_a_pattern() {
		$html = '<script>!function(f,b){f.fbq=b}(window);fbq("init","123");</script>';
		$out  = $this->blocker()->rewrite( $html );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out );
		$this->assertStringContainsString( 'type="text/plain"', $out );
	}

	public function test_blocks_an_iframe_and_emits_a_placeholder() {
		$html = '<iframe src="https://www.youtube.com/embed/abc123" width="560"></iframe>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertStringContainsString( 'data-anchor-src="https://www.youtube.com/embed/abc123"', $out );
		$this->assertStringNotContainsString( ' src="https://www.youtube.com/embed', $out );
		$this->assertStringContainsString( 'anchor-cmp-placeholder', $out, 'A blocked iframe must leave a visible placeholder.' );
		$this->assertStringContainsString( 'width="560"', $out, 'Other iframe attributes must survive.' );
	}

	public function test_granted_category_is_not_blocked() {
		$_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ] = Anchor_Compliance_Consent_State::encode( [
			'id' => 'x', 'ts' => time(), 'v' => 1, 'cats' => [ 'marketing' ],
		] );
		$html = '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>';
		$this->assertSame( $html, $this->blocker()->rewrite( $html ) );
	}

	public function test_optout_region_blocks_nothing_by_default() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$html = '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>';
		$this->assertSame( $html, $this->blocker()->rewrite( $html ) );
	}

	public function test_custom_rule_blocks_its_url() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'custom_rules' => [
				[ 'label' => 'Acme', 'url_pattern' => 'acme-tracker.com', 'category' => 'analytics', 'cookie_patterns' => [] ],
			],
		], false );
		$out = $this->blocker()->rewrite( '<script src="https://cdn.acme-tracker.com/t.js"></script>' );
		$this->assertStringContainsString( 'data-anchor-consent="analytics"', $out );
	}

	public function test_kill_switch_disables_rewriting() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'advanced' => [ 'buffer_enabled' => false ] ], false );
		$html = '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>';
		$this->assertSame( $html, $this->blocker()->rewrite( $html ) );
	}

	public function test_non_html_payload_is_untouched() {
		$json = '{"src":"https://connect.facebook.net/en_US/fbevents.js"}';
		$this->assertSame( $json, $this->blocker()->rewrite( $json ) );
	}

	public function test_should_not_run_for_ajax_rest_or_feeds() {
		$b = $this->blocker();

		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( $b->should_run() );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->go_to( home_url( '/?feed=rss2' ) );
		$this->assertFalse( $b->should_run(), 'Feeds must never be rewritten.' );
	}
}
