<?php
/**
 * Anchor Compliance — banner render and runtime payload.
 */
class Test_Compliance_Banner extends WP_UnitTestCase {

	public function tear_down() {
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_SEC_GPC'], $_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ] );
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		delete_option( 'anchor_site_config_options' );
		parent::tear_down();
	}

	private function banner() {
		$state = new Anchor_Compliance_Consent_State();
		$geo   = new Anchor_Compliance_Geo();
		return new Anchor_Compliance_Banner(
			$state,
			$geo,
			new Anchor_Compliance_Service_Registry(),
			new Anchor_Compliance_Consent_Mode( $state, $geo )
		);
	}

	public function test_payload_reports_strict_posture_for_eu() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		$p = $this->banner()->payload();
		$this->assertSame( 'strict', $p['posture'] );
		$this->assertFalse( $p['hasConsent'] );
		$this->assertFalse( $p['categories']['analytics'] );
		$this->assertTrue( $p['categories']['necessary'] );
	}

	public function test_payload_reports_optout_posture_for_us() {
		// D009 (Wave B): geo headers are only honored under a declared trusted proxy.
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'regions' => [ 'trusted_proxy' => 'cloudflare' ] ], false );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$p = $this->banner()->payload();
		$this->assertSame( 'optout', $p['posture'] );
		$this->assertTrue( $p['categories']['analytics'], 'Opt-out regions grant by default.' );
	}

	public function test_payload_exposes_cookie_patterns_per_category() {
		$p = $this->banner()->payload();
		$this->assertContains( '_fbp', $p['cookiePatterns']['marketing'] );
		$this->assertContains( '_ga', $p['cookiePatterns']['analytics'] );
		$this->assertArrayNotHasKey( 'necessary', $p['cookiePatterns'], 'Necessary cookies are never deleted.' );
	}

	public function test_payload_carries_the_consent_mode_signal_map() {
		$p = $this->banner()->payload();
		$this->assertSame( 'marketing', $p['signalMap']['ad_storage'] );
		$this->assertSame( 'analytics', $p['signalMap']['analytics_storage'] );
	}

	public function test_gpc_is_surfaced_to_the_runtime() {
		$_SERVER['HTTP_SEC_GPC'] = '1';
		$this->assertTrue( $this->banner()->payload()['gpc'] );
	}

	public function test_brand_colors_inherit_from_site_config() {
		update_option( 'anchor_site_config_options', [
			'colors' => [ 'primary' => '#123456', 'ink' => '#0a0a0a' ],
		], false );
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'appearance' => [ 'inherit_brand' => true ] ], false );

		$colors = $this->banner()->brand_colors();
		$this->assertSame( '#123456', $colors['accent'] );
		$this->assertSame( '#0a0a0a', $colors['text'] );
	}

	public function test_brand_inheritance_can_be_turned_off() {
		update_option( 'anchor_site_config_options', [ 'colors' => [ 'primary' => '#123456' ] ], false );
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'inherit_brand' => false, 'color_accent' => '#ff0000' ],
		], false );
		$this->assertSame( '#ff0000', $this->banner()->brand_colors()['accent'] );
	}

	public function test_render_outputs_all_three_buttons_in_strict_mode() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-anchor-action="accept-all"', $html );
		$this->assertStringContainsString( 'data-anchor-action="reject-all"', $html );
		$this->assertStringContainsString( 'data-anchor-action="customize"', $html );
		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
	}

	public function test_render_includes_a_toggle_for_each_non_necessary_category() {
		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		foreach ( [ 'functional', 'analytics', 'marketing' ] as $cat ) {
			$this->assertStringContainsString( 'data-anchor-category="' . $cat . '"', $html );
		}
		$this->assertStringContainsString( 'disabled', $html, 'Necessary must render as a locked toggle.' );
	}

	/**
	 * 2026-08 iteration: the banner row is Customize / Reject All / Accept
	 * All in that order — DOM order = visual order = focus order. (The
	 * preference footer keeps its own reject/save/accept order.)
	 */
	public function test_banner_action_row_order_is_customize_reject_accept() {
		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$customize = strpos( $html, 'data-anchor-action="customize"' );
		$reject    = strpos( $html, 'data-anchor-action="reject-all"' );
		$accept    = strpos( $html, 'data-anchor-action="accept-all"' );
		$this->assertNotFalse( $customize );
		$this->assertLessThan( $reject, $customize, 'Customize renders first in the banner row.' );
		$this->assertLessThan( $accept, $reject, 'Reject renders before Accept.' );
	}

	/**
	 * 2026-08 iteration: the body copy ends with a "Cookie Policy" link when
	 * general.cookie_policy_url is configured — and never renders a dead link
	 * when it is not.
	 */
	public function test_body_ends_with_cookie_policy_link_when_url_configured() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'general' => [ 'cookie_policy_url' => 'https://example.test/cookies/' ],
		], false );

		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<a href="https:\/\/example\.test\/cookies\/" class="anchor-cmp-cookie-policy-link">Cookie Policy<\/a><\/div>/',
			$html,
			'The Cookie Policy link must close out the body copy.'
		);
	}

	public function test_body_has_no_cookie_policy_link_without_url() {
		ob_start();
		$this->banner()->render();
		$this->assertStringNotContainsString( 'anchor-cmp-cookie-policy-link', ob_get_clean() );
	}

	public function test_render_is_suppressed_when_module_disabled() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'enabled' => false ] ], false );
		ob_start();
		$this->banner()->render();
		$this->assertSame( '', trim( ob_get_clean() ) );
	}

	public function test_do_not_sell_shortcode_renders_a_link() {
		$out = do_shortcode( '[anchor_do_not_sell]' );
		$this->assertStringContainsString( 'data-anchor-action="do-not-sell"', $out );
		$this->assertStringContainsString( 'Do Not Sell', $out );
	}

	public function test_brand_colors_use_module_defaults_when_site_config_is_absent() {
		// anchor_site_config_options was never saved — get_option() returns false.
		$this->assertFalse( get_option( 'anchor_site_config_options' ) );

		$defaults = Anchor_Compliance_Settings::defaults()['appearance'];
		$colors   = $this->banner()->brand_colors();

		$this->assertSame( $defaults['color_accent'], $colors['accent'] );
		$this->assertSame( $defaults['color_surface'], $colors['surface'] );
		$this->assertSame( $defaults['color_text'], $colors['text'] );
	}

	public function test_brand_colors_partial_site_config_fills_gaps_from_module_settings() {
		update_option( 'anchor_site_config_options', [ 'colors' => [ 'primary' => '#123456' ] ], false );
		// 2026-08 restyle: inherit_brand is opt-in now, so opt in.
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'appearance' => [ 'inherit_brand' => true ] ], false );

		$defaults = Anchor_Compliance_Settings::defaults()['appearance'];
		$colors   = $this->banner()->brand_colors();

		$this->assertSame( '#123456', $colors['accent'], 'Set key inherits from Site Config.' );
		$this->assertSame( $defaults['color_surface'], $colors['surface'], 'Missing key falls back to module default.' );
		$this->assertSame( $defaults['color_text'], $colors['text'], 'Missing key falls back to module default.' );
	}

	public function test_render_emits_white_ink_for_a_dark_accent() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'inherit_brand' => false, 'color_accent' => '#000000' ],
		], false );

		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( '--acmp-accent-ink:#ffffff', $html );
	}

	public function test_render_emits_black_ink_for_a_light_accent() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'inherit_brand' => false, 'color_accent' => '#ffffff' ],
		], false );

		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( '--acmp-accent-ink:#000000', $html );
	}

	public function test_enqueue_registers_source_assets_with_inline_payload_before_position() {
		wp_deregister_script( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );

		$this->banner()->enqueue();

		$this->assertTrue( wp_script_is( 'anchor-compliance', 'registered' ) );
		$this->assertTrue( wp_style_is( 'anchor-compliance', 'registered' ) );

		$script_src = wp_scripts()->registered['anchor-compliance']->src;
		$style_src  = wp_styles()->registered['anchor-compliance']->src;
		$this->assertStringNotContainsString( '.min.', $script_src, 'Never enqueue a gitignored, CI-generated .min. asset.' );
		$this->assertStringNotContainsString( '.min.', $style_src, 'Never enqueue a gitignored, CI-generated .min. asset.' );

		$before = wp_scripts()->get_data( 'anchor-compliance', 'before' );
		$this->assertNotEmpty( $before, 'Payload must be attached as an inline script in the "before" position.' );
		$this->assertStringContainsString( 'AnchorComplianceData', implode( '', (array) $before ) );

		wp_dequeue_script( 'anchor-compliance' );
		wp_deregister_script( 'anchor-compliance' );
		wp_dequeue_style( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );
	}

	public function test_render_pill_carries_position_modifier_class() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'show_pill' => true, 'pill_position' => 'bottom-left' ],
		], false );
		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'anchor-cmp-pill--bottom-left', $html );
		$this->assertStringNotContainsString( 'anchor-cmp-pill--bottom-right', $html );

		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'show_pill' => true, 'pill_position' => 'bottom-right' ],
		], false );
		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'anchor-cmp-pill--bottom-right', $html );
	}

	public function test_enqueue_registers_brand_tokens_at_root_scope() {
		wp_deregister_script( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );

		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'appearance' => [ 'inherit_brand' => false, 'color_accent' => '#123456' ],
		], false );

		$this->banner()->enqueue();

		$after = wp_styles()->get_data( 'anchor-compliance', 'after' );
		$this->assertNotEmpty( $after, 'Brand tokens must be attached as inline CSS.' );
		$css = implode( '', (array) $after );
		$this->assertStringContainsString( ':root{', $css );
		$this->assertStringContainsString( '--acmp-accent:#123456', $css );
		$this->assertStringContainsString( '--acmp-radius:', $css );

		wp_dequeue_script( 'anchor-compliance' );
		wp_deregister_script( 'anchor-compliance' );
		wp_dequeue_style( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );
	}

	/**
	 * Regression (C005): render() interpolates brand colors into a style
	 * attribute; a malicious value in the never-sanitized
	 * anchor_site_config_options option must be replaced by the safe
	 * fallback, exactly as enqueue() already does.
	 */
	public function test_render_sanitizes_malicious_site_config_colors() {
		update_option( 'anchor_site_config_options', [
			'colors' => [ 'primary' => 'red;background:url(//evil.example/beacon)' ],
		], false );
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'appearance' => [ 'inherit_brand' => true ] ], false );

		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'evil.example', $html );
		// 2026-08 restyle: the known-safe fallback is the neutral near-black
		// default accent (was the old gold #bf8f43).
		$this->assertStringContainsString( '--acmp-accent:#1a1a1a', $html, 'Invalid color falls back to the known-safe default.' );
	}

	/**
	 * Regression (C011): aria-modal belongs only to postures where a real
	 * focus trap exists. Strict: banner + prefs are both modal (2). Opt-out:
	 * the banner is a passive notice, only the prefs dialog stays modal (1).
	 */
	public function test_banner_aria_modal_only_in_strict_posture() {
		// D009 (Wave B): geo headers are only honored under a declared trusted proxy.
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'regions' => [ 'trusted_proxy' => 'cloudflare' ] ], false );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		ob_start();
		$this->banner()->render();
		$strict_html = ob_get_clean();
		$this->assertSame( 2, substr_count( $strict_html, 'aria-modal="true"' ) );

		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		ob_start();
		$this->banner()->render();
		$optout_html = ob_get_clean();
		$this->assertSame( 1, substr_count( $optout_html, 'aria-modal="true"' ) );
		$this->assertDoesNotMatchRegularExpression(
			'/id="anchor-cmp-banner"[^>]*aria-modal/',
			$optout_html,
			'The opt-out banner is a notice, not a modal.'
		);
	}

	/** Regression (F005): the dark_mode setting must reach the [data-acmp-scheme] hook frontend.css implements. */
	public function test_render_emits_scheme_attribute_when_dark_mode_forced() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'appearance' => [ 'dark_mode' => 'dark' ] ], false );
		ob_start();
		$this->banner()->render();
		$this->assertStringContainsString( 'data-acmp-scheme="dark"', ob_get_clean() );
	}

	public function test_render_omits_scheme_attribute_on_auto() {
		// 2026-08 restyle: 'auto' is no longer the shipped default ('light'
		// is, and it emits data-acmp-scheme="light"), so opt into auto.
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'appearance' => [ 'dark_mode' => 'auto' ] ], false );
		ob_start();
		$this->banner()->render();
		$this->assertStringNotContainsString( 'data-acmp-scheme', ob_get_clean() );
	}

	/** The new 'light' default must pin the scheme so OS dark mode cannot flip an unconfigured site. */
	public function test_render_emits_light_scheme_attribute_by_default() {
		ob_start();
		$this->banner()->render();
		$this->assertStringContainsString( 'data-acmp-scheme="light"', ob_get_clean() );
	}

	/** Regression (F006): a configured logo_id must actually render. */
	public function test_render_shows_logo_when_configured() {
		$attachment_id = self::factory()->attachment->create_object(
			'cmp-logo.png',
			0,
			[ 'post_mime_type' => 'image/png', 'post_type' => 'attachment' ]
		);
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'appearance' => [ 'logo_id' => $attachment_id ] ], false );

		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'anchor-cmp-logo', $html );
		$this->assertStringContainsString( 'cmp-logo.png', $html );
	}

	public function test_render_has_no_logo_markup_by_default() {
		ob_start();
		$this->banner()->render();
		$this->assertStringNotContainsString( 'anchor-cmp-logo', ob_get_clean() );
	}

	/**
	 * Regression (C010): a compliance module's own 365-day cookie belongs in
	 * its own disclosure tables. The entry arrives via the
	 * anchor_compliance_services filter registered by the module bootstrap.
	 */
	public function test_own_consent_cookie_is_disclosed_in_preference_center() {
		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'anchor_consent', $html, 'The module must disclose its own cookie.' );
		$this->assertStringContainsString( '365 days', $html, 'Disclosed duration mirrors the configured consent lifetime.' );
	}

	/**
	 * Regression (F020): the payload ships only the strings the runtime
	 * reads; server-rendered copy (heading, body, button labels) stays out.
	 * strictCountries stays — the relax tier consumes it.
	 */
	public function test_payload_i18n_ships_only_runtime_keys() {
		$p = $this->banner()->payload();

		$this->assertEqualsCanonicalizing(
			[
				'notice_body', 'dns_label', 'saved_message', 'unblocked_message',
				'gpc_message', 'dns_confirmation', 'placeholder_text', 'placeholder_button',
				'save_error',
			],
			array_keys( $p['i18n'] )
		);
		$this->assertArrayHasKey( 'strictCountries', $p );
	}

	/** Regression (F012): the asset version must move when the asset changes (mtime), not stay frozen at a constant. */
	public function test_enqueue_versions_assets_by_file_mtime() {
		wp_deregister_script( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );

		$this->banner()->enqueue();

		$expected = (string) filemtime( Anchor_Asset_Loader::path( 'anchor-compliance/assets/frontend.js' ) );
		$this->assertSame( $expected, (string) wp_scripts()->registered['anchor-compliance']->ver );

		wp_dequeue_script( 'anchor-compliance' );
		wp_deregister_script( 'anchor-compliance' );
		wp_dequeue_style( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );
	}

	/**
	 * Regression (C003): consent posture and GCM defaults are baked into the
	 * HTML, so consent-variant responses must opt out of shared page caches:
	 * always while the visitor has no valid consent cookie, always under
	 * strict posture — but a consented opt-out visitor stays cacheable.
	 */
	public function test_no_store_decision_matrix() {
		// D009 (Wave B): geo headers are only honored under a declared trusted proxy.
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'regions' => [ 'trusted_proxy' => 'cloudflare' ] ], false );
		$cookie = Anchor_Compliance_Consent_State::encode( [
			'id' => 'c0ffee00-0000-4000-8000-000000000000', 'ts' => time(), 'v' => 1, 'cats' => [ 'analytics' ],
		] );

		// No consent cookie, opt-out region: undecided visitor => no-store.
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$this->assertTrue( $this->banner()->should_send_no_store() );

		// Valid cookie, opt-out region: stable per-visitor page => cacheable.
		$_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ] = $cookie;
		$this->assertFalse( $this->banner()->should_send_no_store() );

		// Valid cookie, strict region: posture-variant HTML => no-store.
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		$this->assertTrue( $this->banner()->should_send_no_store() );
	}

	public function test_no_store_respects_the_opt_out_filter_and_disabled_module() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';

		add_filter( 'anchor_compliance_no_cache', '__return_false' );
		$this->assertFalse( $this->banner()->should_send_no_store(), 'Hosts that exclude the anchor_consent cookie from their cache can opt out.' );
		remove_filter( 'anchor_compliance_no_cache', '__return_false' );

		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'enabled' => false ] ], false );
		$this->assertFalse( $this->banner()->should_send_no_store() );
	}

	public function test_enqueue_no_ops_when_module_disabled() {
		wp_deregister_script( 'anchor-compliance' );
		wp_deregister_style( 'anchor-compliance' );
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'enabled' => false ] ], false );

		$this->banner()->enqueue();

		$this->assertFalse( wp_script_is( 'anchor-compliance', 'registered' ) );
		$this->assertFalse( wp_style_is( 'anchor-compliance', 'registered' ) );
	}

	/* -----------------------------------------------------------------
	 * Wave B additions (Fix-6). [C014] + [F017].
	 * ----------------------------------------------------------------- */

	/** [C014] Mirror of the [anchor_do_not_sell] coverage above. */
	public function test_consent_link_shortcode_renders_a_button() {
		$out = do_shortcode( '[anchor_consent_link]' );
		$this->assertStringContainsString( 'data-anchor-action="open-preferences"', $out );
		$this->assertStringContainsString( 'anchor-cmp-link', $out );
		$this->assertStringContainsString( 'Cookie Preferences', $out );
		$this->assertStringContainsString( '<button type="button"', $out, 'Must be a button, not a dead <a> — it opens a panel, it does not navigate.' );
	}

	/** [C014] The text attribute overrides the default label (escaped). */
	public function test_consent_link_shortcode_accepts_custom_text() {
		$out = do_shortcode( '[anchor_consent_link text="Manage <b>cookies</b>"]' );
		$this->assertStringContainsString( 'Manage', $out );
		$this->assertStringNotContainsString( '<b>', $out, 'Custom text must be esc_html()d.' );
	}

	/** [C014] Like every other public surface, it vanishes with the module off. */
	public function test_consent_link_shortcode_is_empty_when_module_disabled() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'enabled' => false ] ], false );
		$this->assertSame( '', do_shortcode( '[anchor_consent_link]' ) );
	}

	/** [F017] The honored-GPC notice renders only when the signal was seen. */
	public function test_render_shows_gpc_notice_when_signal_present() {
		$_SERVER['HTTP_SEC_GPC'] = '1';
		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'anchor-cmp-gpc-notice', $html );
		$this->assertStringContainsString( 'Global Privacy Control signal has been honored', $html );
	}

	public function test_render_has_no_gpc_notice_without_the_signal() {
		ob_start();
		$this->banner()->render();
		$this->assertStringNotContainsString( 'anchor-cmp-gpc-notice', ob_get_clean() );
	}

	/** [F017] Footer links render only for configured URLs, with their labels. */
	public function test_render_footer_links_only_for_configured_urls() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'general' => [
				'privacy_policy_url' => 'https://example.test/privacy/',
				'cookie_policy_url'  => 'https://example.test/cookies/',
				'terms_url'          => '',
			],
		], false );

		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'anchor-cmp-footer-links', $html );
		$this->assertStringContainsString( 'href="https://example.test/privacy/"', $html );
		$this->assertStringContainsString( '>Privacy Policy</a>', $html );
		$this->assertStringContainsString( 'href="https://example.test/cookies/"', $html );
		$this->assertStringContainsString( '>Cookie Policy</a>', $html );
		$this->assertStringNotContainsString( '>Terms</a>', $html, 'An empty URL must not render a dead link.' );
	}

	public function test_render_omits_footer_links_block_when_no_urls_configured() {
		ob_start();
		$this->banner()->render();
		$this->assertStringNotContainsString( 'anchor-cmp-footer-links', ob_get_clean() );
	}

	/** [F017] show_pill=false must suppress the re-entry pill entirely. */
	public function test_render_omits_pill_when_show_pill_disabled() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'appearance' => [ 'show_pill' => false ] ], false );
		ob_start();
		$this->banner()->render();
		$this->assertStringNotContainsString( 'anchor-cmp-pill', ob_get_clean() );
	}

	/* -----------------------------------------------------------------
	 * Review finding 3: blank-is-blank (D024) vs. accessibility.
	 * ----------------------------------------------------------------- */

	/**
	 * A cleared heading/body must not ship an empty strict modal: the <h2> is
	 * the dialog's aria-labelledby target, so heading and body copy fall back
	 * to the shipped defaults at RENDER time (storage keeps the blank).
	 */
	public function test_cleared_heading_and_body_fall_back_to_defaults_at_render() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'content' => [ 'heading' => '', 'body' => '', 'notice_body' => '' ],
		], false );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE'; // Strict — the modal posture.

		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$d = Anchor_Compliance_Settings::defaults()['content'];
		$this->assertStringContainsString( $d['heading'], $html, 'A cleared heading must render the shipped default.' );
		$this->assertMatchesRegularExpression(
			'/<h2 id="anchor-cmp-heading"[^>]*>[^<]+<\/h2>/',
			$html,
			'The aria-labelledby target must never render empty.'
		);
		$this->assertStringContainsString( $d['body'], $html, 'A cleared strict body must render the shipped default.' );
	}

	public function test_cleared_notice_body_falls_back_in_optout_posture() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'regions' => [ 'trusted_proxy' => 'cloudflare' ],
			'content' => [ 'notice_body' => '   ' ],
		], false );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US'; // Opt-out — the notice posture.

		ob_start();
		$this->banner()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString(
			Anchor_Compliance_Settings::defaults()['content']['notice_body'],
			$html,
			'Whitespace-only notice body counts as blank and falls back.'
		);
	}

	/** All other content strings keep D024's blank-is-blank contract. */
	public function test_cleared_optional_string_stays_blank() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'content' => [ 'dns_confirmation' => '' ],
		], false );

		$p = $this->banner()->payload();
		$this->assertSame( '', $p['i18n']['dns_confirmation'], 'A deliberately cleared optional string must stay blank — only the accessibility-critical heading/body fall back.' );
	}
}
