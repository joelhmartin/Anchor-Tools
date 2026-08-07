<?php
/**
 * Anchor Compliance — module boot and settings.
 */
class Test_Compliance_Settings extends WP_UnitTestCase {

	public function test_module_boots() {
		$this->assertInstanceOf(
			Anchor_Compliance_Module::class,
			Anchor_Compliance_Module::instance(),
			'The compliance module did not bootstrap — check tests/bootstrap.php enables it.'
		);
	}

	public function test_defaults_have_required_keys() {
		$d = Anchor_Compliance_Settings::defaults();
		foreach ( [ 'general', 'regions', 'appearance', 'content', 'services', 'custom_rules', 'log', 'dsar', 'advanced' ] as $section ) {
			$this->assertArrayHasKey( $section, $d, "Missing default section: {$section}" );
		}
		$this->assertSame( 1, $d['general']['policy_version'] );
		$this->assertSame( 365, $d['general']['consent_lifetime_days'] );
	}

	public function test_get_merges_stored_over_defaults() {
		update_option(
			Anchor_Compliance_Module::OPTION_KEY,
			[ 'general' => [ 'policy_version' => 4 ] ],
			false
		);
		$opts = Anchor_Compliance_Settings::get();
		$this->assertSame( 4, $opts['general']['policy_version'] );
		// Untouched sibling keys survive the merge.
		$this->assertSame( 365, $opts['general']['consent_lifetime_days'] );
		$this->assertArrayHasKey( 'appearance', $opts );
	}

	public function test_sanitize_clamps_and_casts() {
		$out = Anchor_Compliance_Settings::sanitize( [
			'general' => [
				'policy_version'        => '7',
				'consent_lifetime_days' => '99999',
				'privacy_policy_url'    => 'javascript:alert(1)',
			],
			'advanced' => [ 'buffer_enabled' => 'yes' ],
		] );
		$this->assertSame( 7, $out['general']['policy_version'] );
		$this->assertSame( 730, $out['general']['consent_lifetime_days'], 'Lifetime must clamp to 730 days.' );
		$this->assertSame( '', $out['general']['privacy_policy_url'], 'Non-http(s) URL must be rejected.' );
		$this->assertTrue( $out['advanced']['buffer_enabled'] );
	}

	public function test_registers_settings_tab() {
		$tabs = apply_filters( 'anchor_settings_tabs', [] );
		$this->assertArrayHasKey( 'compliance', $tabs );
		$this->assertSame( 'Compliance', $tabs['compliance']['label'] );
		$this->assertTrue( is_callable( $tabs['compliance']['callback'] ) );
	}
}
