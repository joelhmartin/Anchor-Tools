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
			// Disclosure-only entries (e.g. the module's own consent cookie,
			// added via the anchor_compliance_services filter) load nothing,
			// so they legitimately carry cookies but no URL patterns.
			if ( empty( $svc['patterns'] ) ) {
				$this->assertNotEmpty( $svc['cookies'], "{$key} has neither URL patterns nor cookies" );
			}
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

	/* ─── B008: normalize EVERY field consumers index, not just 'enabled'/'cookies' ─── */

	/**
	 * PINS FIX B008. A filter-added entry lacking 'category' produced
	 * undefined-index warnings in active_rules() and cookies_by_category()
	 * and filed its cookies under a bucket no policy table or consent state
	 * ever reads — silently undisclosed and ungated.
	 */
	public function test_filter_added_service_without_category_or_patterns_is_normalized() {
		add_filter( 'anchor_compliance_services', function ( $services ) {
			$services['bare'] = [
				'name'    => 'Bare', 'provider' => 'Bare Inc',
				// no 'category', no 'patterns', one cookie
				'cookies' => [ [ 'name' => '_bare', 'purpose' => 'p', 'duration' => 'd' ] ],
			];
			return $services;
		} );

		$all = $this->reg()->all();
		$this->assertSame( 'marketing', $all['bare']['category'], 'A missing category must default through sanitize_category().' );
		$this->assertSame( [], $all['bare']['patterns'], 'Missing patterns must normalize to an array.' );

		// The cookie must land in a bucket consumers actually read.
		$names = wp_list_pluck( $this->reg()->cookies_by_category()['marketing'], 'name' );
		$this->assertContains( '_bare', $names, 'The normalized entry\'s cookies must be disclosed under its (defaulted) category.' );
	}

	public function test_filter_added_service_with_invalid_category_is_sanitized() {
		add_filter( 'anchor_compliance_services', function ( $services ) {
			$services['weird'] = [
				'name' => 'Weird', 'provider' => 'W', 'category' => 'not-a-category',
				'patterns' => [ 'weird.example' ], 'cookies' => [],
			];
			return $services;
		} );
		$this->assertSame( 'marketing', $this->reg()->all()['weird']['category'] );
	}

	/* ─── B009: what the module deletes on withdrawal it must also disclose ─── */

	/**
	 * PINS FIX B009. cookie_patterns_for() already swept custom-rule cookies
	 * on withdrawal, but cookies_by_category() iterated services only — so
	 * [anchor_cookie_policy] under-disclosed exactly the site-specific
	 * trackers the admin bothered to register.
	 */
	public function test_cookies_by_category_includes_custom_rule_cookies() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'custom_rules' => [
				[ 'label' => 'Acme', 'url_pattern' => 'acme-tracker.com', 'category' => 'analytics', 'cookie_patterns' => [ '_acme*', '' ] ],
			],
		], false );

		$rows = $this->reg()->cookies_by_category()['analytics'];
		$acme = null;
		foreach ( $rows as $row ) {
			if ( '_acme*' === $row['name'] ) {
				$acme = $row;
			}
		}

		$this->assertNotNull( $acme, 'A custom rule\'s cookie must appear in the disclosure table data.' );
		$this->assertSame( 'Acme', $acme['provider'], 'The rule\'s label doubles as the provider.' );
		$this->assertSame( '—', $acme['purpose'], 'The repeater stores no purpose; an em dash, never a PHP notice.' );
		$this->assertSame( '—', $acme['duration'] );

		$names = wp_list_pluck( $rows, 'name' );
		$this->assertNotContains( '', $names, 'The empty cookie_patterns entry must be skipped, not emitted as a blank row.' );
		$this->assertCount( 1, array_keys( $names, '_acme*', true ), 'Exactly one row per custom cookie pattern.' );
	}

	/**
	 * B009 follow-up (Wave B): the repeater now stores optional purpose and
	 * duration fields; when present they must reach the disclosure table —
	 * the em dash (pinned above) remains only the blank fallback.
	 */
	public function test_custom_rule_purpose_and_duration_flow_into_the_disclosure_rows() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'custom_rules' => [
				[
					'label'           => 'Acme',
					'url_pattern'     => 'acme-tracker.com',
					'category'        => 'analytics',
					'cookie_patterns' => [ '_acme*' ],
					'purpose'         => 'Session heatmaps.',
					'duration'        => '1 year',
				],
				[
					'label'           => 'Beta',
					'url_pattern'     => 'beta-tracker.com',
					'category'        => 'analytics',
					'cookie_patterns' => [ '_beta*' ],
					'purpose'         => '   ', // whitespace-only stays an em dash
				],
			],
		], false );

		$rows  = [];
		foreach ( $this->reg()->cookies_by_category()['analytics'] as $row ) {
			$rows[ $row['name'] ] = $row;
		}

		$this->assertSame( 'Session heatmaps.', $rows['_acme*']['purpose'] );
		$this->assertSame( '1 year', $rows['_acme*']['duration'] );
		$this->assertSame( '—', $rows['_beta*']['purpose'], 'Blank/whitespace purpose keeps the em-dash fallback.' );
		$this->assertSame( '—', $rows['_beta*']['duration'], 'A rule saved before the field existed keeps the em-dash fallback.' );
	}

	/** Every consumer indexes four fixed keys per row; pin the shape directly (B017). */
	public function test_cookies_by_category_shape() {
		$out = $this->reg()->cookies_by_category();

		$this->assertSame( [ 'necessary', 'functional', 'analytics', 'marketing' ], array_keys( $out ), 'Every category bucket exists even when empty.' );
		foreach ( $out as $cat => $rows ) {
			foreach ( $rows as $row ) {
				$this->assertSame( [ 'name', 'provider', 'purpose', 'duration' ], array_keys( $row ), "A {$cat} row has the wrong shape." );
			}
		}
		$this->assertNotEmpty( $out['marketing'], 'Sanity: the builtin marketing services carry cookies.' );
	}

	/* ─── B010: regional vendor CDNs must match ─── */

	public function test_hubspot_patterns_match_eu_residency_hosts() {
		$r = $this->reg();
		$this->assertSame( 'marketing', $r->category_for_url( 'https://js-eu1.hs-scripts.com/12345.js' ), 'EU-data-residency portals load js-eu1.* — the strict-region installs where blocking is mandatory.' );
		$this->assertSame( 'marketing', $r->category_for_url( 'https://js.hs-scripts.com/12345.js' ), 'The classic host must still match.' );
		$this->assertSame( 'marketing', $r->category_for_url( 'https://js-eu1.hs-analytics.net/analytics/1.js' ) );
		$this->assertNull( $r->category_for_url( 'https://paths-scripts.com/x.js' ), 'The leading dot must prevent unrelated domains that merely end in the same bytes from matching.' );
	}

	/* ─── B011: per-rule contexts axis ─── */

	public function test_every_active_rule_carries_valid_contexts() {
		foreach ( $this->reg()->active_rules() as $rule ) {
			$this->assertArrayHasKey( 'contexts', $rule, "Rule for {$rule['pattern']} has no contexts." );
			$this->assertNotEmpty( $rule['contexts'] );
			foreach ( $rule['contexts'] as $ctx ) {
				$this->assertContains( $ctx, Anchor_Compliance_Service_Registry::RULE_CONTEXTS );
			}
		}
	}

	public function test_rules_for_context_separates_url_and_inline_patterns() {
		$r = $this->reg();

		$inline = wp_list_pluck( $r->rules_for_context( 'inline' ), 'pattern' );
		$this->assertContains( 'fbq(', $inline, 'A function-call-shaped pattern belongs to the inline context.' );
		// Tracker URL patterns gate inline bodies too — vendors ship paste-in
		// inline loader snippets whose bodies embed the CDN host as a string.
		$this->assertContains( 'static.hotjar.com', $inline, 'A tracker CDN pattern must gate inline loader snippets.' );
		$this->assertContains( 'clarity.ms', $inline );
		$this->assertContains( 'connect.facebook.net', $inline );
		// ...but the content-embed services declare src/iframe-only contexts:
		// a plain video/map/tweet link in a theme inline script is not a tracker.
		$this->assertNotContains( 'youtube.com/watch', $inline, 'A content-embed URL pattern must never gate inline bodies.' );
		$this->assertNotContains( 'youtu.be/', $inline );
		$this->assertNotContains( 'vimeo.com/video/', $inline );
		$this->assertNotContains( 'maps.googleapis.com', $inline );
		$this->assertNotContains( 'platform.twitter.com', $inline );

		$src = wp_list_pluck( $r->rules_for_context( 'src' ), 'pattern' );
		$this->assertContains( 'youtube.com/watch', $src );
		$this->assertContains( 'connect.facebook.net', $src );
		$this->assertNotContains( 'fbq(', $src, "'fbq(' can never appear in a URL." );

		$iframe = wp_list_pluck( $r->rules_for_context( 'iframe' ), 'pattern' );
		$this->assertContains( 'youtube.com/embed', $iframe );
		$this->assertNotContains( 'fbq(', $iframe );
	}

	/** A rule built without a contexts key (hand-rolled in a test, an old serialized copy) matches every context — the pre-axis behavior. */
	public function test_filter_rules_by_context_treats_missing_contexts_as_all() {
		$rules = [ [ 'pattern' => 'x.example', 'category' => 'marketing', 'key' => 'x', 'label' => 'X' ] ];
		foreach ( [ 'src', 'iframe', 'inline' ] as $ctx ) {
			$this->assertCount( 1, Anchor_Compliance_Service_Registry::filter_rules_by_context( $rules, $ctx ) );
		}
	}

	public function test_custom_rule_contexts_are_inferred_from_pattern_shape() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'custom_rules' => [
				[ 'label' => 'Url', 'url_pattern' => 'acme-tracker.com', 'category' => 'analytics', 'cookie_patterns' => [] ],
				[ 'label' => 'Call', 'url_pattern' => 'acmeTrack(', 'category' => 'analytics', 'cookie_patterns' => [] ],
			],
		], false );

		$by_pattern = [];
		foreach ( $this->reg()->active_rules() as $rule ) {
			$by_pattern[ $rule['pattern'] ] = $rule['contexts'];
		}

		$this->assertSame( [ 'src', 'iframe', 'inline' ], $by_pattern['acme-tracker.com'], 'A URL-shaped custom rule gates every context, inline included — an admin-registered tracker host inside an inline body is that tracker\'s loader.' );
		$this->assertSame( [ 'inline' ], $by_pattern['acmeTrack('] );
	}

	/* ─── B012: embeds widget split from the ad pixel ─── */

	public function test_twitter_embeds_are_a_separate_functional_entry() {
		$all = $this->reg()->all();

		$this->assertArrayHasKey( 'twitter_embeds', $all );
		$this->assertSame( 'functional', $all['twitter_embeds']['category'], 'Tweet embeds are a widget, not an ad pixel.' );
		$this->assertContains( 'platform.twitter.com', $all['twitter_embeds']['patterns'] );
		$this->assertNotEmpty( $all['twitter_embeds']['cookies'], 'The widget\'s own cookies must be disclosed.' );

		$this->assertNotContains( 'platform.twitter.com', $all['twitter']['patterns'], 'The pixel entry must no longer claim the widget loader.' );
		$this->assertSame( 'functional', $this->reg()->category_for_url( 'https://platform.twitter.com/widgets.js' ) );
	}

	/* ─── B014: a rule that can never deny must not ship ─── */

	public function test_necessary_custom_rule_is_not_emitted() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'custom_rules' => [
				[ 'label' => 'Own CDN', 'url_pattern' => 'cdn.own.example', 'category' => 'necessary', 'cookie_patterns' => [] ],
			],
		], false );

		$patterns = wp_list_pluck( $this->reg()->active_rules(), 'pattern' );
		$this->assertNotContains( 'cdn.own.example', $patterns, 'Necessary is never gated — matching the builtin branch, a necessary custom rule is a dead entry and must be skipped.' );
		$this->assertNull( $this->reg()->category_for_url( 'https://cdn.own.example/x.js' ) );
	}
}
