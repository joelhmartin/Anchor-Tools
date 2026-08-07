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

	// --- Regression tests from the adversarial review (Critical 1-3, Important 4-6) ---

	public function test_pcre_backtrack_failure_fails_open() {
		$prev = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '100' );

		// An unterminated <script src="..." quote followed by a long run of
		// ordinary markup — exactly the shape the reviewer measured as
		// blanking a real page.
		$html = '<script src="https://connect.facebook.net/x' . str_repeat( '<p>filler</p>', 500 );

		try {
			$out = $this->blocker()->rewrite( $html );
		} finally {
			ini_set( 'pcre.backtrack_limit', $prev );
		}

		$this->assertSame( $html, $out, 'On a PCRE failure the original markup must be returned unmodified, never blanked.' );
	}

	public function test_unterminated_quote_does_not_swallow_following_markup() {
		$html = '<script src="https://connect.facebook.net/x.js>' . "\n"
			. '<h1>My Page</h1><p>Important content</p><a href="/contact">Contact</a>';
		$out = $this->blocker()->rewrite( $html );

		$this->assertStringContainsString( '<h1>My Page</h1>', $out, 'Heading must survive an unterminated src quote.' );
		$this->assertStringContainsString( '<p>Important content</p>', $out, 'Paragraph must survive an unterminated src quote.' );
		$this->assertStringContainsString( '<a href="/contact">Contact</a>', $out, 'Link must survive an unterminated src quote.' );
	}

	public function test_idempotent_on_already_blocked_markup() {
		$html  = '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>'
			. '<iframe src="https://www.youtube.com/embed/abc123" width="560"></iframe>';
		$b     = $this->blocker();
		$once  = $b->rewrite( $html );
		$twice = $b->rewrite( $once );

		$this->assertSame( $once, $twice, 'Rewriting already-blocked markup a second time must be a no-op.' );
	}

	public function test_data_src_attribute_is_not_mistaken_for_src() {
		$script_html = '<script data-src="https://connect.facebook.net/en_US/fbevents.js"></script>';
		$this->assertSame( $script_html, $this->blocker()->rewrite( $script_html ), 'data-src on a script must never be treated as src.' );

		$iframe_html = '<iframe data-src="https://www.youtube.com/embed/abc123" src="about:blank"></iframe>';
		$this->assertSame( $iframe_html, $this->blocker()->rewrite( $iframe_html ), 'A lazy-loaded iframe with data-src + harmless src="about:blank" must pass through untouched.' );
	}

	public function test_json_ld_is_never_touched() {
		$html = '<script type="application/ld+json">{"embedUrl":"https://www.youtube.com/embed/abc"}</script>';
		$this->assertSame( $html, $this->blocker()->rewrite( $html ), 'Structured data must never be gated, even when it contains a tracked URL.' );
	}

	public function test_text_template_script_is_never_touched() {
		$html = '<script type="text/template"><iframe src="https://www.youtube.com/embed/abc"></iframe></script>';
		$this->assertSame( $html, $this->blocker()->rewrite( $html ), 'An inert client-side template must never have its contents rewritten as if it were live markup.' );
	}

	public function test_data_anchor_src_round_trips_through_html_entity_decode() {
		$html = '<iframe src="https://www.youtube.com/embed/x?rel=0&amp;autoplay=1" width="560"></iframe>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertSame( 1, preg_match( '/data-anchor-src="([^"]*)"/', $out, $m ), 'Expected a data-anchor-src attribute in the output.' );
		$decoded = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		$this->assertSame( 'https://www.youtube.com/embed/x?rel=0&autoplay=1', $decoded, 'One round of entity-decoding the stored attribute must restore exactly the original URL — not "&amp;amp;".' );
	}

	public function test_iframe_srcdoc_is_untouched() {
		$html = '<iframe srcdoc="<p>hi</p>"></iframe>';
		$this->assertSame( $html, $this->blocker()->rewrite( $html ), 'srcdoc is not src and must never be mistaken for it.' );
	}

	public function test_module_script_with_src_has_exactly_one_type_attribute() {
		$html = '<script type="module" src="https://connect.facebook.net/en_US/fbevents.js"></script>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertSame( 1, substr_count( $out, 'type=' ), 'A blocked tag must end up with exactly one type= attribute.' );
		$this->assertStringContainsString( 'type="text/plain"', $out );
	}

	public function test_single_quoted_src_is_blocked() {
		$html = "<script src='https://connect.facebook.net/en_US/fbevents.js'></script>";
		$out  = $this->blocker()->rewrite( $html );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out );
	}

	public function test_irregular_whitespace_around_equals_is_blocked() {
		$html = '<script src  =   "https://connect.facebook.net/en_US/fbevents.js"></script>';
		$out  = $this->blocker()->rewrite( $html );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out );
	}

	public function test_uppercase_tag_and_attribute_are_blocked() {
		$html = '<SCRIPT SRC="https://connect.facebook.net/en_US/fbevents.js"></SCRIPT>';
		$out  = $this->blocker()->rewrite( $html );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out );
	}

	public function test_blocked_count_resets_on_early_exit() {
		$b = $this->blocker();
		$b->rewrite( '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>' );
		$this->assertSame( 1, $b->blocked_count() );

		// A non-HTML payload takes the early-exit path; blocked_count() must not carry over.
		$b->rewrite( '{"src":"https://connect.facebook.net/en_US/fbevents.js"}' );
		$this->assertSame( 0, $b->blocked_count(), 'blocked_count() must reset even when rewrite() exits early.' );
	}

	/**
	 * REST_REQUEST and WP_CLI are intentionally NOT exercised via the real
	 * PHP constants here. Both are define()-once-per-process; defining
	 * either would permanently flip should_run()'s check for every other
	 * test that runs afterward in this same PHPUnit process, including
	 * anchor-optimize's and anchor-translate's own unrelated
	 * `defined('REST_REQUEST')` guards (see class-script-blocker.php's
	 * should_run() for the full reasoning, and tests/test-email-builder.php
	 * for a prior, independently-discovered instance of the same hazard with
	 * DOING_AJAX). should_run() now checks these through the
	 * 'anchor_compliance_is_rest_request' / 'anchor_compliance_is_wp_cli'
	 * filters instead — defaulting to the real constant check in production,
	 * but safely overridable here, with WP_UnitTestCase automatically
	 * undoing the add_filter() between tests.
	 *
	 * is_customize_preview() is also not exercised: WP_Customize_Manager is
	 * declared `final`, so it cannot be stubbed/subclassed, and constructing
	 * a real instance runs a heavy constructor with admin-context
	 * dependencies and side effects (registers panels/sections/controls)
	 * that are unsafe to invoke inside this suite. should_run() calling
	 * is_customize_preview() is a straight pass-through to that WP core
	 * conditional tag, not module-specific logic.
	 */
	public function test_should_not_run_for_rest_wp_cli_cron_embed_or_preview() {
		$b = $this->blocker();

		add_filter( 'anchor_compliance_is_rest_request', '__return_true' );
		$this->assertFalse( $b->should_run(), 'A REST request must never be rewritten.' );
		remove_filter( 'anchor_compliance_is_rest_request', '__return_true' );

		add_filter( 'anchor_compliance_is_wp_cli', '__return_true' );
		$this->assertFalse( $b->should_run(), 'A WP-CLI run must never be rewritten.' );
		remove_filter( 'anchor_compliance_is_wp_cli', '__return_true' );

		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertFalse( $b->should_run(), 'A cron run must never be rewritten.' );
		remove_filter( 'wp_doing_cron', '__return_true' );

		global $wp_query;
		$prev_embed             = $wp_query->is_embed;
		$wp_query->is_embed     = true;
		$this->assertFalse( $b->should_run(), 'An oEmbed response must never be rewritten.' );
		$wp_query->is_embed = $prev_embed;

		$prev_preview            = $wp_query->is_preview;
		$wp_query->is_preview    = true;
		$this->assertFalse( $b->should_run(), 'A draft/revision preview must never be rewritten.' );
		$wp_query->is_preview = $prev_preview;
	}
}
