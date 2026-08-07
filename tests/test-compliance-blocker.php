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

	// --- Round 2 of the adversarial review: NEW-1, NEW-2 ---

	/**
	 * NEW-1 (High). Before the fix, mask_inert_scripts() built its token
	 * from a fixed literal plus a counter that restarts at 0 on every call:
	 * "\x01ANCHOR_CMP_MASK_0\x01". Restoration used strtr(), which replaces
	 * EVERY occurrence of a token string in the document — including one
	 * that mask_inert_scripts() never produced. 0x01 is valid UTF-8 and
	 * survives esc_attr()/esc_html()/sanitize_text_field() untouched, so an
	 * attacker who can get arbitrary text reflected onto the page (e.g. a
	 * search query echoed back) could plant the literal token bytes
	 * themselves; strtr() would then splice a real, unrelated masked script
	 * into their chosen spot — including breaking out of an attribute
	 * value. Anchor Tools always emits JSON-LD, so the masking path is
	 * always live on every page.
	 *
	 * This test plants a forged, predictable-format token (as the old code
	 * would have generated it) both in ordinary body text and inside an
	 * attribute value, alongside a real inert script that masking WILL
	 * process, and asserts the forged text passes through completely
	 * unchanged — proving the real (randomly-nonced) tokens never collide
	 * with attacker-controlled text.
	 */
	public function test_forged_mask_token_is_not_exploitable() {
		$forged = "\x01ANCHOR_CMP_MASK_0\x01";
		$html   = '<h1>Search results for: ' . $forged . '</h1>'
			. '<input type="search" value="' . $forged . '">'
			. '<script type="application/ld+json">{"@type":"WebSite","name":"Example"}</script>';

		$out = $this->blocker()->rewrite( $html );

		$this->assertStringContainsString( '<h1>Search results for: ' . $forged . '</h1>', $out, 'A forged token in ordinary body text must survive byte-for-byte.' );
		$this->assertStringContainsString( 'value="' . $forged . '"', $out, 'A forged token inside an attribute value must not be replaced — doing so would break out of the attribute.' );
	}

	/**
	 * NEW-2 (Medium). strip_type_attribute() only stripped a QUOTED
	 * type="...". The unquoted src branch was added on the rationale that
	 * an HTML minifier can strip attribute quoting — but a minifier that
	 * unquotes src unquotes type too, and the old strip_type_attribute()
	 * left an unquoted type=text/javascript in place while ALSO appending
	 * type="text/plain", producing a duplicate attribute. Per the HTML
	 * tokenizer the FIRST occurrence of a duplicate attribute wins, so the
	 * tag kept its original type and the browser still executed the body —
	 * the "blocked" marker present in the markup but not actually in
	 * effect.
	 */
	public function test_unquoted_type_attribute_is_fully_stripped_before_blocking() {
		$html = '<script type=text/javascript>fbq("init","1");</script>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertSame( 1, substr_count( $out, 'type=' ), 'An unquoted type= must be fully removed, not left duplicated alongside the new type="text/plain".' );
		$this->assertStringNotContainsString( 'type=text/javascript', $out, 'The original unquoted executable type must not survive — it would win over the appended type="text/plain" as the first duplicate attribute.' );
		$this->assertStringContainsString( 'type="text/plain"', $out );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out );
	}

	// --- Round 3 of the adversarial review: NEW-A ---

	/**
	 * NEW-A (Medium). The round-2 unquoted-type strip pass,
	 * `#\stype\s*=\s*[^\s>]+#i`, only required whitespace before "type" — so
	 * it also fired on a "type=" substring sitting inside some OTHER
	 * attribute's quoted VALUE, and its value class would then swallow that
	 * value's own closing quote, leaving the rebuilt tag with an unbalanced
	 * quote (which then corrupts/hides every attribute after it, including
	 * data-anchor-consent — the exact attribute Task 10's runtime needs to
	 * find the tag). This is reachable on any blocked iframe whose `title`
	 * text (e.g. a YouTube video title, which a site owner does not
	 * control) happens to contain the literal substring "type=".
	 */
	public function test_type_inside_another_attributes_value_is_never_touched() {
		$html = '<iframe src="https://www.youtube.com/embed/a" title="Battery type=AA"></iframe>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertSame( substr_count( $out, '"' ) % 2, 0, 'Quotes in the rebuilt tag must be balanced.' );
		$this->assertStringContainsString( 'title="Battery type=AA"', $out, 'A title containing the literal substring "type=" must survive completely intact.' );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out, 'The real data-anchor-consent attribute must still be discoverable — an unbalanced quote earlier in the tag would hide it.' );
	}

	public function test_type_inside_a_data_attributes_value_is_never_touched() {
		$html = '<script type="text/javascript" data-x="a type=b" src="https://connect.facebook.net/en_US/fbevents.js"></script>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertSame( substr_count( $out, '"' ) % 2, 0, 'Quotes in the rebuilt tag must be balanced.' );
		$this->assertStringContainsString( 'data-x="a type=b"', $out, 'A data attribute containing the literal substring "type=" must survive completely intact.' );
		// Exactly two "type=" substrings survive: the untouched one embedded
		// in data-x's value, and our own fresh type="text/plain" — never a
		// third, corrupted occurrence, and never zero (which would mean
		// data-x itself got mangled).
		$this->assertSame( 2, substr_count( $out, 'type=' ) );
		$this->assertStringContainsString( 'type="text/plain"', $out );
	}

	/** The real, trailing, unquoted type= must still be stripped even while an unrelated quoted attribute containing "type=" text is left completely alone. */
	public function test_real_trailing_unquoted_type_is_stripped_while_title_stays_intact() {
		$html = '<script title="Pick type=A batteries" type=text/javascript src="https://connect.facebook.net/en_US/fbevents.js"></script>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertSame( substr_count( $out, '"' ) % 2, 0, 'Quotes in the rebuilt tag must be balanced.' );
		$this->assertStringContainsString( 'title="Pick type=A batteries"', $out, 'The title must survive completely intact, unchanged by the trailing type= being stripped.' );
		$this->assertStringNotContainsString( 'type=text/javascript', $out, 'The real, executable, unquoted type= must still be stripped.' );
		$this->assertStringContainsString( 'type="text/plain"', $out );
	}

	// --- should_run(): single 'anchor_compliance_should_run' filter (Round 2 API cleanup) ---

	/**
	 * wp_doing_cron() and is_embed()/is_preview() are exercised at the
	 * branch level because they are safely fakeable without any
	 * process-global side effect: wp_doing_cron() is filterable in WP core
	 * (like wp_doing_ajax(), already used above), and is_embed()/
	 * is_preview() just read mutable properties on the $wp_query global,
	 * which this test sets and restores within itself. go_to() a plain URL
	 * first so the starting state is a known, neutral query rather than
	 * whatever a previous test in this file left behind.
	 */
	public function test_should_not_run_for_cron_embed_or_preview() {
		$this->go_to( home_url( '/' ) );
		$b = $this->blocker();

		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertFalse( $b->should_run(), 'A cron run must never be rewritten.' );
		remove_filter( 'wp_doing_cron', '__return_true' );

		global $wp_query;
		$prev_embed          = $wp_query->is_embed;
		$wp_query->is_embed  = true;
		$this->assertFalse( $b->should_run(), 'An oEmbed response must never be rewritten.' );
		$wp_query->is_embed = $prev_embed;

		$prev_preview           = $wp_query->is_preview;
		$wp_query->is_preview   = true;
		$this->assertFalse( $b->should_run(), 'A draft/revision preview must never be rewritten.' );
		$wp_query->is_preview = $prev_preview;
	}

	/**
	 * should_run()'s decision funnels through a single
	 * 'anchor_compliance_should_run' filter applied to the FINAL boolean,
	 * rather than one filter per internal WP conditional — a filter
	 * promising to answer "is this a REST request?" that actually just
	 * disables blocking would be misleading. This is the one, honestly-named
	 * override point, and it is what a test (or a site) uses to force
	 * should_run() false for any reason, including REST, WP-CLI, or a
	 * Customizer preview, without touching a process-global PHP constant.
	 *
	 * is_customize_preview() is not exercised at the branch level:
	 * WP_Customize_Manager is declared `final` in WP core, so it cannot be
	 * stubbed/subclassed to satisfy the instanceof check that function
	 * performs. should_run()'s call to it is a straight pass-through to that
	 * core conditional tag, not module-specific logic, so it is not
	 * independently worth covering — this filter test already proves
	 * should_run() honors an external override for any reason, which is the
	 * mechanism a site would actually use to suspend blocking during a
	 * Customizer preview.
	 */
	public function test_should_run_filter_overrides_the_final_decision() {
		$this->go_to( home_url( '/' ) );
		$b = $this->blocker();

		$this->assertTrue( $b->should_run(), 'Sanity check: should_run() is true with no overrides in play.' );

		add_filter( 'anchor_compliance_should_run', '__return_false' );
		$this->assertFalse( $b->should_run(), 'anchor_compliance_should_run must be able to veto the decision for any reason.' );
		remove_filter( 'anchor_compliance_should_run', '__return_false' );

		$this->assertTrue( $b->should_run(), 'Removing the filter must restore the default decision.' );
	}
}
