<?php
/**
 * Anchor Compliance — Code Snippets bridge.
 */
class Test_Compliance_Snippets_Bridge extends WP_UnitTestCase {

	private function bridge() {
		return new Anchor_Compliance_Snippets_Bridge(
			new Anchor_Compliance_Consent_State(),
			new Anchor_Compliance_Geo()
		);
	}

	private function snippet( $category, $location = 'wp_head' ) {
		$id = $this->factory->post->create( [ 'post_type' => 'anchor_code_snippet' ] );
		update_post_meta( $id, 'acs_location', $location );
		update_post_meta( $id, 'anchor_consent_category', $category );
		return $id;
	}

	public function set_up() {
		parent::set_up();
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
	}

	public function tear_down() {
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ] );
		parent::tear_down();
	}

	public function test_necessary_snippet_is_untouched() {
		$id   = $this->snippet( 'necessary' );
		$html = '<script src="https://example.com/x.js"></script>';
		$this->assertSame( $html, $this->bridge()->filter_snippet_output( $html, $id ) );
	}

	public function test_marketing_snippet_is_neutralized_when_denied() {
		$id  = $this->snippet( 'marketing' );
		$out = $this->bridge()->filter_snippet_output( '<script src="https://example.com/x.js"></script>', $id );

		$this->assertStringContainsString( 'type="text/plain"', $out );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out );
		$this->assertStringContainsString( 'data-anchor-src="https://example.com/x.js"', $out );
	}

	public function test_inline_snippet_is_neutralized() {
		$id  = $this->snippet( 'analytics' );
		$out = $this->bridge()->filter_snippet_output( '<script>console.log(1);</script>', $id );
		$this->assertStringContainsString( 'type="text/plain"', $out );
		$this->assertStringContainsString( 'data-anchor-consent="analytics"', $out );
		$this->assertStringContainsString( 'console.log(1);', $out, 'Inline body must be preserved verbatim.' );
	}

	public function test_granted_category_passes_through() {
		$_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ] = Anchor_Compliance_Consent_State::encode( [
			'id' => 'x', 'ts' => time(), 'v' => 1, 'cats' => [ 'marketing' ],
		] );
		$id   = $this->snippet( 'marketing' );
		$html = '<script src="https://example.com/x.js"></script>';
		$this->assertSame( $html, $this->bridge()->filter_snippet_output( $html, $id ) );
	}

	public function test_mu_plugin_snippet_is_not_gateable() {
		$id = $this->snippet( 'marketing', 'mu_plugin' );
		$this->assertFalse( $this->bridge()->is_gateable( $id ) );
	}

	public function test_mu_plugin_snippet_is_passed_through_unchanged() {
		$id   = $this->snippet( 'marketing', 'mu_plugin' );
		$html = '<script src="https://example.com/x.js"></script>';
		$this->assertSame(
			$html,
			$this->bridge()->filter_snippet_output( $html, $id ),
			'An ungateable snippet must not be silently half-processed.'
		);
	}

	public function test_header_snippet_is_gateable() {
		$this->assertTrue( $this->bridge()->is_gateable( $this->snippet( 'marketing', 'wp_head' ) ) );
	}
}
