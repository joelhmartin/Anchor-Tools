<?php
/**
 * Anchor Compliance — service registry.
 */
class Test_Compliance_Registry extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		remove_all_filters( 'anchor_compliance_services' );
		parent::tear_down();
	}

	private function reg() {
		return new Anchor_Compliance_Service_Registry();
	}

	public function test_ships_expected_services() {
		$all = $this->reg()->all();
		foreach ( [ 'google_analytics', 'google_tag_manager', 'meta_pixel', 'linkedin_insight', 'hotjar', 'youtube', 'calltrackingmetrics' ] as $key ) {
			$this->assertArrayHasKey( $key, $all, "Registry is missing {$key}" );
		}
	}

	public function test_every_entry_is_well_formed() {
		foreach ( $this->reg()->all() as $key => $svc ) {
			$this->assertArrayHasKey( 'name', $svc, "{$key} has no name" );
			$this->assertArrayHasKey( 'provider', $svc, "{$key} has no provider" );
			$this->assertNotEmpty( $svc['patterns'], "{$key} has no URL patterns" );
			$this->assertContains(
				$svc['category'],
				[ 'necessary', 'functional', 'analytics', 'marketing' ],
				"{$key} has an invalid category"
			);
			foreach ( $svc['cookies'] as $cookie ) {
				$this->assertArrayHasKey( 'name', $cookie );
				$this->assertArrayHasKey( 'purpose', $cookie );
				$this->assertArrayHasKey( 'duration', $cookie );
			}
		}
	}

	public function test_ctm_defaults_to_marketing() {
		$all = $this->reg()->all();
		$this->assertSame( 'marketing', $all['calltrackingmetrics']['category'] );
	}

	public function test_settings_override_a_service_category() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'services' => [ 'calltrackingmetrics' => [ 'enabled' => true, 'category' => 'functional' ] ],
		], false );
		$this->assertSame( 'functional', $this->reg()->all()['calltrackingmetrics']['category'] );
	}

	public function test_disabled_service_is_excluded_from_active_rules() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'services' => [ 'hotjar' => [ 'enabled' => false, 'category' => 'analytics' ] ],
		], false );
		$keys = wp_list_pluck( $this->reg()->active_rules(), 'key' );
		$this->assertNotContains( 'hotjar', $keys );
		$this->assertContains( 'meta_pixel', $keys, 'Other services stay active.' );
	}

	public function test_category_for_url_matches_by_substring() {
		$r = $this->reg();
		$this->assertSame( 'marketing', $r->category_for_url( 'https://connect.facebook.net/en_US/fbevents.js' ) );
		$this->assertSame( 'analytics', $r->category_for_url( 'https://static.hotjar.com/c/hotjar-123.js' ) );
		$this->assertNull( $r->category_for_url( 'https://example.com/theme/app.js' ) );
	}

	public function test_consent_mode_services_are_excluded_from_blocking_rules() {
		// Google tags must load with denied consent, not be hard-blocked, or
		// conversion modeling and Ads audiences break.
		$keys = wp_list_pluck( $this->reg()->active_rules(), 'key' );
		$this->assertNotContains( 'google_tag_manager', $keys );
		$this->assertNotContains( 'google_analytics', $keys );
		$this->assertNotContains( 'google_ads', $keys );
		$this->assertContains( 'meta_pixel', $keys, 'Non-Google services are still blocked.' );

		$this->assertNull(
			$this->reg()->category_for_url( 'https://www.googletagmanager.com/gtm.js?id=GTM-X' ),
			'GTM must not resolve to a blocking category while Consent Mode is on.'
		);
	}

	public function test_disabling_consent_mode_falls_back_to_blocking_google() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'advanced' => [ 'consent_mode_enabled' => false ],
		], false );

		$keys = wp_list_pluck( $this->reg()->active_rules(), 'key' );
		$this->assertContains(
			'google_tag_manager',
			$keys,
			'With Consent Mode off, nothing else would gate Google tags, so they must be blocked.'
		);
	}

	public function test_custom_rules_are_matched_and_win_over_builtins() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'custom_rules' => [
				[ 'label' => 'Acme', 'url_pattern' => 'acme-tracker.com', 'category' => 'analytics', 'cookie_patterns' => [ '_acme*' ] ],
			],
		], false );
		$this->assertSame( 'analytics', $this->reg()->category_for_url( 'https://cdn.acme-tracker.com/t.js' ) );
	}

	public function test_filter_can_add_a_service() {
		add_filter( 'anchor_compliance_services', function ( $services ) {
			$services['bespoke'] = [
				'name' => 'Bespoke', 'provider' => 'Bespoke Inc', 'category' => 'analytics',
				'patterns' => [ 'bespoke.example' ], 'cookies' => [],
			];
			return $services;
		} );
		$this->assertArrayHasKey( 'bespoke', $this->reg()->all() );
		$this->assertSame( 'analytics', $this->reg()->category_for_url( 'https://bespoke.example/x.js' ) );
	}

	public function test_cookie_patterns_for_marketing_include_meta_and_google_ads() {
		$patterns = $this->reg()->cookie_patterns_for( [ 'marketing' ] );
		$this->assertContains( '_fbp', $patterns );
		$this->assertContains( '_gcl_*', $patterns );
		$this->assertNotContains( '_ga', $patterns, 'GA cookies belong to analytics, not marketing.' );
	}
}
