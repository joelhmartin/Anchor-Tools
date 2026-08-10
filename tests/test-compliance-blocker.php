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

	/**
	 * The placeholder copy must come from the admin's settings, not from a
	 * hardcoded English literal. This matters beyond the server: the JS
	 * runtime prefers placeholder copy already present on the page when it
	 * synthesizes one for a CLIENT-built embed, so a hardcoded string here
	 * would silently override the configured value for those too.
	 */
	public function test_placeholder_copy_comes_from_settings() {
		$opts = Anchor_Compliance_Settings::get();
		$opts['content']['placeholder_text']   = 'Custom blocked notice';
		$opts['content']['placeholder_button'] = 'Load it';
		update_option( Anchor_Compliance_Module::OPTION_KEY, $opts, false );

		$out = $this->blocker()->rewrite( '<iframe src="https://www.youtube.com/embed/abc123"></iframe>' );

		$this->assertStringContainsString( 'Custom blocked notice', $out );
		$this->assertStringContainsString( '>Load it</button>', $out );
		$this->assertStringNotContainsString( 'This content is blocked until you accept', $out );
	}

	/**
	 * wp_kses_post() entity-encodes the "&" in the "Accept & Load" default the
	 * first time the settings form posts that field. The blocker must decode
	 * once before esc_html() re-encodes, or the visitor sees a literal
	 * "&amp;amp;" rendered as "&amp;" on screen.
	 */
	public function test_placeholder_copy_is_not_double_encoded() {
		$opts = Anchor_Compliance_Settings::get();
		$opts['content']['placeholder_button'] = 'Accept &amp; Load'; // as wp_kses_post stores it
		update_option( Anchor_Compliance_Module::OPTION_KEY, $opts, false );

		$out = $this->blocker()->rewrite( '<iframe src="https://www.youtube.com/embed/abc123"></iframe>' );

		$this->assertStringContainsString( '>Accept &amp; Load</button>', $out );
		$this->assertStringNotContainsString( '&amp;amp;', $out, 'Decode once, escape once — never double-encode.' );
	}

	/**
	 * PINS FIX B. A bare `vimeo.com/video` pattern also matched
	 * api.vimeo.com/videoS/ and vimeo.com/videoSCHOOL. Because patterns are
	 * matched against inline SCRIPT BODIES too, that neutralized any inline
	 * script merely mentioning the Vimeo REST API — a URL
	 * anchor-webinars/anchor-webinars.php:171 constructs verbatim.
	 */
	public function test_vimeo_pattern_does_not_match_the_rest_api_or_videoschool() {
		$api = '<body><script>fetch("https://api.vimeo.com/videos/123");</script></body>';
		$this->assertSame(
			$api,
			$this->blocker()->rewrite( $api ),
			'An inline script referencing the Vimeo REST API must not be gated.'
		);

		$out = $this->blocker()->rewrite( '<body><iframe src="https://api.vimeo.com/videos/123"></iframe></body>' );
		$this->assertStringNotContainsString( 'data-anchor-consent', $out, 'api.vimeo.com/videos/ is not an embed.' );

		$out = $this->blocker()->rewrite( '<body><iframe src="https://vimeo.com/videoschool"></iframe></body>' );
		$this->assertStringNotContainsString( 'data-anchor-consent', $out, 'vimeo.com/videoschool is a content page.' );

		$out = $this->blocker()->rewrite( '<body><iframe src="https://vimeo.com/video/123"></iframe></body>' );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out, 'A real Vimeo embed must still be gated.' );
	}

	/** PINS FIX C. youtube.com/watch must be gated by the server too. */
	public function test_youtube_watch_and_short_links_are_gated() {
		$out = $this->blocker()->rewrite( '<body><iframe src="https://www.youtube.com/watch?v=abc"></iframe></body>' );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out );

		$out = $this->blocker()->rewrite( '<body><iframe src="https://youtu.be/abc"></iframe></body>' );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out );
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

	// --- Round 4: the QUOTED type= strip pass was not quote-aware either ---

	/**
	 * Round 3 made the UNQUOTED strip pass quote-aware but left the quoted
	 * pass (`#\stype\s*=\s*(["\'])...\1#i`) running ahead of it with no
	 * notion of quote context. A quoted `type="..."` sitting INSIDE another
	 * attribute's value was therefore deleted outright, taking the text
	 * around it with it. Quote parity survives (a matched pair is removed),
	 * so this was silent text corruption rather than structural breakage —
	 * and it is reachable with content the site owner does not control: a
	 * blocked YouTube embed's `title` is the video's own title.
	 */
	public function test_quoted_type_inside_another_attributes_value_is_never_deleted() {
		$html = '<iframe src="https://www.youtube.com/embed/a" title="Battery type=\'AA\' cells"></iframe>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertStringContainsString( 'title="Battery type=\'AA\' cells"', $out, 'A title containing a quoted type= must survive byte-for-byte — the old quoted pass deleted it, silently producing title="Battery cells".' );
		$this->assertStringNotContainsString( 'Battery cells', $out, 'The corrupted form must not appear at all.' );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out, 'The tag must still be blocked and discoverable.' );
	}

	/** Same defect with the quote styles swapped: a double-quoted type= inside a single-quoted value. */
	public function test_quoted_type_inside_a_single_quoted_attributes_value_is_never_deleted() {
		$html = '<iframe src="https://www.youtube.com/embed/a" data-x=\'a type="b" c\'></iframe>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertStringContainsString( 'data-x=\'a type="b" c\'', $out, 'A single-quoted value containing a double-quoted type= must survive byte-for-byte — the old quoted pass reduced it to \'a c\'.' );
		$this->assertStringNotContainsString( "data-x='a c'", $out, 'The corrupted form must not appear at all.' );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out, 'The tag must still be blocked and discoverable.' );
	}

	/**
	 * PINS AN ACCEPTED FAIL-OPEN — DO NOT "FIX" THIS INTO A STRIP.
	 *
	 * When a tag's attribute string has unbalanced quotes, the quote-aware
	 * alternation in strip_type_attribute() pairs a quoted span straight
	 * across the real `type=`, consuming it atomically, so the executable
	 * type is NOT removed and the tracker can still run. That is the
	 * deliberate trade: on malformed markup, the only way to reach that
	 * `type=` is to abandon quote awareness at exactly the position where
	 * quote awareness is what protects the surrounding content, which
	 * corrupts well-formed tags that merely look malformed to a dumber
	 * pattern. A missed tracker on broken HTML is the cheaper failure — and
	 * an earlier round that did strip it here still left the tag's quotes
	 * broken, so it bought nothing. This test exists so a future round
	 * cannot silently turn that trade back into content corruption.
	 */
	public function test_unbalanced_quotes_decline_to_strip_type_rather_than_corrupt() {
		// Attributes reaching strip_type_attribute() here are the tag's
		// surviving ones with src removed: ` title="27" monitor"
		// type=text/javascript alt="x"` — an odd number of quote characters,
		// so a quoted span pairs from the stray `"` after `monitor` across
		// the real `type=` and on to `alt="`.
		$html = '<script title="27" monitor" type=text/javascript src="https://connect.facebook.net/en_US/fbevents.js" alt="x"></script>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertStringContainsString( 'title="27" monitor"', $out, 'The malformed attribute text must be passed through untouched, not rewritten or partially deleted.' );
		$this->assertStringContainsString( 'type=text/javascript', $out, 'ACCEPTED FAIL-OPEN: with unbalanced quotes the real type= is deliberately left in place. Do not change this to a strip — doing so means giving up quote awareness and corrupting well-formed content.' );
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

	// --- B016: pins for the unquoted-src branch and multi-tag blocked_count() ---

	/** The unquoted branch `([^\s>]+)` exists for minifier output (see the pattern walkthrough); pin it for scripts. */
	public function test_unquoted_script_src_is_blocked() {
		$html = '<body><script src=https://connect.facebook.net/en_US/fbevents.js></script></body>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertStringContainsString( 'type="text/plain"', $out );
		$this->assertStringContainsString( 'data-anchor-src="https://connect.facebook.net/en_US/fbevents.js"', $out );
		$this->assertStringNotContainsString( ' src=https://connect.facebook.net', $out, 'The live unquoted src must be removed.' );
	}

	/** Same minifier case for iframes — the placeholder machinery must engage too. */
	public function test_unquoted_iframe_src_is_blocked() {
		$html = '<body><iframe src=https://www.youtube.com/embed/abc123 width=560></iframe></body>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out );
		$this->assertStringContainsString( 'data-anchor-src="https://www.youtube.com/embed/abc123"', $out );
		$this->assertStringContainsString( 'anchor-cmp-placeholder', $out, 'An unquoted-src iframe must still get its placeholder.' );
	}

	/** blocked_count() must count every blocked tag across every pass on a multi-tag page. */
	public function test_blocked_count_counts_every_blocked_tag() {
		$html = '<body>'
			. '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>'
			. '<script>fbq("init","1");</script>'
			. '<iframe src="https://www.youtube.com/embed/a"></iframe>'
			. '<img src="https://www.facebook.com/tr?id=1" height="1" width="1">'
			. '<link rel="preconnect" href="https://static.hotjar.com">'
			. '</body>';

		$b = $this->blocker();
		$b->rewrite( $html );

		$this->assertSame( 5, $b->blocked_count(), 'One count per blocked tag: script src, inline, iframe, img pixel, link hint.' );
	}

	// --- B002: <img> pixels (the noscript half of e.g. the Meta Pixel) ---

	public function test_img_pixel_is_neutralized() {
		$html = '<body><noscript><img src="https://www.facebook.com/tr?id=123&amp;ev=PageView" height="1" width="1"></noscript></body>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertStringNotContainsString( ' src="https://www.facebook.com/tr', $out, 'The pixel request must be prevented.' );
		$this->assertStringContainsString( 'data-anchor-src="https://www.facebook.com/tr?id=123&amp;ev=PageView"', $out, 'The original src must be carried for activation, entity round-trip intact.' );
		$this->assertStringContainsString( 'data-anchor-consent="marketing"', $out );
		$this->assertStringContainsString( 'height="1"', $out, 'Other img attributes must survive.' );
		$this->assertStringNotContainsString( 'anchor-cmp-placeholder', $out, 'A blocked img is placeholder-free — the only job is preventing the request.' );
	}

	public function test_ordinary_images_are_untouched() {
		$html = '<body><img src="/wp-content/uploads/photo.jpg" alt="Team photo"></body>';
		$this->assertSame( $html, $this->blocker()->rewrite( $html ) );
	}

	// --- B003: <link> resource hints to gated hosts ---

	public function test_link_hints_to_gated_hosts_are_neutralized() {
		$html = '<head>'
			. '<link rel="preconnect" href="https://static.hotjar.com">'
			. '<link rel="preload" as="script" href="https://static.hotjar.com/c/hotjar-1.js">'
			. '</head>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertSame( 0, preg_match( '#<link[^>]*\shref\s*=#i', $out ), 'No gated hint may keep a live href.' );
		$this->assertStringContainsString( 'data-anchor-href="https://static.hotjar.com"', $out );
		$this->assertStringContainsString( 'data-anchor-consent="analytics"', $out );
	}

	public function test_stylesheet_and_unmatched_links_are_untouched() {
		// A stylesheet is page furniture even when its href matches a rule;
		// only fetch-hint rels are gated. An unmatched hint is untouched too.
		$html = '<head>'
			. '<link rel="stylesheet" href="https://static.hotjar.com/style.css">'
			. '<link rel="preload" as="script" href="/wp-content/themes/x/theme.js">'
			. '<link rel="canonical" href="https://example.com/page/">'
			. '</head>';
		$this->assertSame( $html, $this->blocker()->rewrite( $html ) );
	}

	public function test_link_hint_rewrite_is_idempotent() {
		$html  = '<head><link rel="preconnect" href="https://static.hotjar.com"></head>';
		$b     = $this->blocker();
		$once  = $b->rewrite( $html );
		$this->assertSame( $once, $b->rewrite( $once ), 'A neutralized hint (data-anchor-href, no href) must never be re-processed.' );
	}

	// --- B005: existing style attribute must not defeat display:none ---

	/**
	 * PINS FIX B005. The original attributes used to be emitted AHEAD of the
	 * injected style="display:none"; per the HTML tokenizer the FIRST
	 * duplicate attribute wins, so an embed already carrying
	 * style="width:100%" stayed visible as an empty box beside the
	 * placeholder. The existing style is now merged (author declarations
	 * first, display:none last — activation clears only the display
	 * property, restoring the author's styling intact).
	 */
	public function test_blocked_iframe_with_existing_style_is_actually_hidden() {
		$html = '<body><iframe src="https://www.youtube.com/embed/a" style="width:100%;height:360px" title="Vid"></iframe></body>';
		$out  = $this->blocker()->rewrite( $html );

		$this->assertSame( 1, preg_match( '#<iframe[^>]*>#', $out, $m ), 'Expected the rewritten iframe tag.' );
		$tag = $m[0];

		$this->assertSame( 1, substr_count( $tag, 'style=' ), 'Exactly one style attribute — a duplicate would let the first (visible) one win.' );
		$this->assertStringContainsString( 'width:100%;height:360px;display:none', $tag, 'Author declarations survive, display:none is appended last.' );
		$this->assertStringContainsString( 'title="Vid"', $tag, 'Other attributes must survive the style extraction.' );
	}

	/** The server-side iframe must carry aria-hidden="true" — its JS twin (neutralizeIframe) always has; activation removes it. */
	public function test_blocked_iframe_emits_aria_hidden() {
		$out = $this->blocker()->rewrite( '<body><iframe src="https://www.youtube.com/embed/a" width="560"></iframe></body>' );

		$this->assertSame( 1, preg_match( '#<iframe[^>]*>#', $out, $m ) );
		$this->assertStringContainsString( 'aria-hidden="true"', $m[0] );
		$this->assertStringContainsString( 'style="display:none"', $m[0] );
	}

	// --- B011: URL-only patterns must not gate inline script bodies ---

	/**
	 * PINS FIX B011 (the structural fix for the same class the vimeo test
	 * above pins per-pattern). 'youtube.com/watch' / 'youtu.be/' are URL
	 * patterns; matched against inline BODIES they neutralized any theme
	 * script that merely mentioned a video link as a string. Rules now carry
	 * a 'contexts' axis and the inline pass only sees inline-context rules
	 * (function-call-shaped patterns like 'fbq('), so a plain URL string in
	 * an inline body is never gated — while the same URL as an iframe src
	 * still is (test_youtube_watch_and_short_links_are_gated above).
	 */
	public function test_url_pattern_in_an_inline_script_body_is_not_gated() {
		$html = '<body><script>var share = "https://www.youtube.com/watch?v=abc"; var s2 = "https://youtu.be/abc";</script></body>';
		$this->assertSame( $html, $this->blocker()->rewrite( $html ), 'A theme inline script mentioning a video URL as a string is not a tracker.' );
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
