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

	/* -----------------------------------------------------------------
	 * Wave B additions (Fix-6). [D027] sanitize(): the services and
	 * custom_rules sections. Only these two sections are asserted on —
	 * a sibling task is concurrently adding regions fields, and these
	 * sections are list/map-shaped (replaced wholesale by get()), so
	 * their sanitize contract is the stable part.
	 * ----------------------------------------------------------------- */

	public function test_sanitize_services_valid_row_round_trips() {
		$out = Anchor_Compliance_Settings::sanitize( [
			'services' => [
				'google_analytics' => [ 'enabled' => '1', 'category' => 'analytics' ],
				'youtube'          => [ 'category' => 'functional' ], // enabled omitted
			],
		] );

		$this->assertSame(
			[ 'enabled' => true, 'category' => 'analytics' ],
			$out['services']['google_analytics']
		);
		$this->assertFalse( $out['services']['youtube']['enabled'], 'An omitted enabled flag means disabled.' );
		$this->assertSame( 'functional', $out['services']['youtube']['category'] );
	}

	public function test_sanitize_services_invalid_category_falls_back_to_marketing() {
		$out = Anchor_Compliance_Settings::sanitize( [
			'services' => [ 'meta_pixel' => [ 'enabled' => 1, 'category' => 'totally-bogus' ] ],
		] );
		$this->assertSame( 'marketing', $out['services']['meta_pixel']['category'], 'Marketing is the conservative fallback for a service.' );
	}

	public function test_sanitize_services_keys_are_sanitized_and_empty_keys_dropped() {
		$out = Anchor_Compliance_Settings::sanitize( [
			'services' => [
				'Google Analytics!' => [ 'enabled' => 1, 'category' => 'analytics' ],
				''                  => [ 'enabled' => 1, 'category' => 'marketing' ],
			],
		] );

		$this->assertArrayHasKey( 'googleanalytics', $out['services'], 'Keys go through sanitize_key().' );
		$this->assertArrayNotHasKey( '', $out['services'] );
		$this->assertCount( 1, $out['services'] );
	}

	public function test_sanitize_services_survives_malformed_shapes() {
		// A whole-section string and a non-array row must neither fatal nor
		// smuggle anything through.
		$out = Anchor_Compliance_Settings::sanitize( [ 'services' => 'garbage' ] );
		$this->assertSame( [], $out['services'] );

		$out = Anchor_Compliance_Settings::sanitize( [ 'services' => [ 'foo' => 'not-an-array' ] ] );
		$this->assertSame(
			[ 'enabled' => false, 'category' => 'marketing' ],
			$out['services']['foo'],
			'A malformed row degrades to the safest shape: disabled, marketing.'
		);
	}

	public function test_sanitize_custom_rules_valid_row_round_trips() {
		$out = Anchor_Compliance_Settings::sanitize( [
			'custom_rules' => [
				[
					'label'           => 'My Widget',
					'url_pattern'     => '  example.com/widget.js  ',
					'category'        => 'functional',
					'cookie_patterns' => '_wa, _wb  _wc',
				],
			],
		] );

		$this->assertCount( 1, $out['custom_rules'] );
		$rule = $out['custom_rules'][0];
		$this->assertSame( 'My Widget', $rule['label'] );
		$this->assertSame( 'example.com/widget.js', $rule['url_pattern'], 'Pattern is trimmed.' );
		$this->assertSame( 'functional', $rule['category'] );
		$this->assertSame( [ '_wa', '_wb', '_wc' ], $rule['cookie_patterns'], 'Patterns split on commas and whitespace.' );
	}

	public function test_sanitize_custom_rules_label_defaults_to_pattern_and_bad_category_falls_back() {
		$out = Anchor_Compliance_Settings::sanitize( [
			'custom_rules' => [
				[ 'url_pattern' => 'cdn.example.com/x.js', 'category' => 'nope' ],
			],
		] );

		$rule = $out['custom_rules'][0];
		$this->assertSame( 'cdn.example.com/x.js', $rule['label'], 'Missing label falls back to the pattern.' );
		$this->assertSame( 'marketing', $rule['category'] );
		$this->assertSame( [], $rule['cookie_patterns'] );
	}

	public function test_sanitize_custom_rules_drops_rows_without_a_pattern_and_malformed_rows() {
		$out = Anchor_Compliance_Settings::sanitize( [
			'custom_rules' => [
				[ 'label' => 'No pattern', 'category' => 'analytics' ],
				[ 'url_pattern' => '   ' ],
				'garbage-string-row',
				[ 'url_pattern' => 'keep.example.com/y.js' ],
			],
		] );

		$this->assertCount( 1, $out['custom_rules'], 'Pattern-less and non-array rows are dropped, never stored.' );
		$this->assertSame( 'keep.example.com/y.js', $out['custom_rules'][0]['url_pattern'] );
		$this->assertSame( [ 0 ], array_keys( $out['custom_rules'] ), 'Surviving rules re-index from zero.' );
	}

	public function test_sanitize_custom_rules_whole_section_garbage_yields_empty_list() {
		$out = Anchor_Compliance_Settings::sanitize( [ 'custom_rules' => 'garbage' ] );
		$this->assertSame( [], $out['custom_rules'] );
	}

	/* -----------------------------------------------------------------
	 * Wave B additions (Fix-5): trusted proxy (D009), strict-country
	 * sentinel (D008) + non-default codes (D007), blank content (D024),
	 * custom-rule purpose/duration (B009), export audit (D028).
	 * ----------------------------------------------------------------- */

	public function test_trusted_proxy_defaults_to_none_and_sanitizes() {
		$this->assertSame( 'none', Anchor_Compliance_Settings::defaults()['regions']['trusted_proxy'] );

		$out = Anchor_Compliance_Settings::sanitize( [ 'regions' => [ 'trusted_proxy' => 'cloudflare' ] ] );
		$this->assertSame( 'cloudflare', $out['regions']['trusted_proxy'] );

		$out = Anchor_Compliance_Settings::sanitize( [ 'regions' => [ 'trusted_proxy' => 'evil-mode' ] ] );
		$this->assertSame( 'none', $out['regions']['trusted_proxy'], 'An unrecognized mode must fall back to none.' );
	}

	public function test_deselecting_every_strict_country_stores_an_empty_list() {
		// D008: a fully-deselected multi-select submits no strict_countries
		// key at all; the hidden sentinel is what still arrives.
		$out = Anchor_Compliance_Settings::sanitize( [ 'regions' => [ 'strict_countries_present' => '1' ] ] );
		$this->assertSame( [], $out['regions']['strict_countries'], 'Deselect-all must persist as empty, not re-impose the 33 defaults.' );
	}

	public function test_absent_regions_section_still_restores_default_countries() {
		// No sentinel, no key: a programmatic partial write keeps defaults.
		$out = Anchor_Compliance_Settings::sanitize( [] );
		$this->assertSame(
			Anchor_Compliance_Settings::default_strict_countries(),
			$out['regions']['strict_countries']
		);
	}

	public function test_non_default_country_code_survives_sanitize() {
		// D007 (sanitize half — the render half is pinned in the UI tests).
		$out = Anchor_Compliance_Settings::sanitize( [ 'regions' => [ 'strict_countries' => [ 'CA', 'DE' ] ] ] );
		$this->assertSame( [ 'CA', 'DE' ], $out['regions']['strict_countries'] );
	}

	public function test_blank_content_field_stays_blank() {
		// D024: blank is a value.
		$out = Anchor_Compliance_Settings::sanitize( [ 'content' => [ 'heading' => '', 'notice_body' => '   ' ] ] );
		$this->assertSame( '', $out['content']['heading'], 'A deliberately emptied field must stay empty.' );
		$this->assertSame( '', $out['content']['notice_body'], 'Whitespace-only counts as blank.' );
		// Keys not submitted at all (programmatic write) keep their defaults.
		$this->assertNotSame( '', $out['content']['accept_label'] );
	}

	public function test_custom_rule_sanitize_carries_purpose_and_duration() {
		// B009: the repeater's optional disclosure copy.
		$out = Anchor_Compliance_Settings::sanitize( [
			'custom_rules' => [
				[
					'label'           => 'Acme',
					'url_pattern'     => 'acme.example',
					'category'        => 'analytics',
					'cookie_patterns' => '_acme*',
					'purpose'         => '<b>Heatmaps</b>',
					'duration'        => '1 year',
				],
				[ 'label' => 'Bare', 'url_pattern' => 'bare.example', 'category' => 'marketing', 'cookie_patterns' => '' ],
			],
		] );

		$this->assertSame( 'Heatmaps', $out['custom_rules'][0]['purpose'], 'Purpose is plain text — markup stripped.' );
		$this->assertSame( '1 year', $out['custom_rules'][0]['duration'] );
		$this->assertSame( '', $out['custom_rules'][1]['purpose'], 'Absent purpose sanitizes to an empty string (em-dash fallback happens at render).' );
		$this->assertSame( '', $out['custom_rules'][1]['duration'] );
	}

	public function test_export_audit_records_who_when_and_row_count() {
		// D028: bulk PII egress leaves a trace.
		$admin = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		( new Anchor_Compliance_Settings() )->record_export_audit( 42 );

		$audit = get_option( Anchor_Compliance_Settings::EXPORT_AUDIT_OPTION );
		$this->assertIsArray( $audit );
		$this->assertCount( 1, $audit );
		$entry = $audit[0];
		$this->assertSame( $admin, $entry['user_id'] );
		$this->assertSame( get_userdata( $admin )->user_login, $entry['user_login'] );
		$this->assertSame( 42, $entry['rows'] );
		$this->assertEqualsWithDelta( time(), $entry['time'], 5 );
	}

	public function test_export_audit_is_capped_at_the_last_twenty() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$settings = new Anchor_Compliance_Settings();

		for ( $i = 1; $i <= Anchor_Compliance_Settings::EXPORT_AUDIT_MAX + 5; $i++ ) {
			$settings->record_export_audit( $i );
		}

		$audit = get_option( Anchor_Compliance_Settings::EXPORT_AUDIT_OPTION );
		$this->assertCount( Anchor_Compliance_Settings::EXPORT_AUDIT_MAX, $audit );
		$this->assertSame( 6, $audit[0]['rows'], 'Oldest entries roll off first.' );
		$this->assertSame( Anchor_Compliance_Settings::EXPORT_AUDIT_MAX + 5, end( $audit )['rows'] );
	}
}
