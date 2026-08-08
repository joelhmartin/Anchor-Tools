<?php
/**
 * Anchor Compliance — Code Snippets bridge.
 *
 * The `code_snippets` module is not enabled by tests/bootstrap.php (only
 * events_manager, locations, and compliance are), so Anchor_Code_Snippets_Module
 * is never require_once'd by the plugin's own module bootstrap in this suite.
 * Require it directly here — the file only declares the class (no top-level
 * hook registration), so this is side-effect-free — so the real CPT constant
 * is available and test fixtures exercise the actual registered slug rather
 * than a stand-in string.
 */
if ( ! class_exists( 'Anchor_Code_Snippets_Module' ) ) {
	require_once dirname( __DIR__ ) . '/anchor-code-snippets/anchor-code-snippets.php';
}

class Test_Compliance_Snippets_Bridge extends WP_UnitTestCase {

	private function bridge() {
		return new Anchor_Compliance_Snippets_Bridge(
			new Anchor_Compliance_Consent_State(),
			new Anchor_Compliance_Geo()
		);
	}

	private function snippet( $category, $location = 'wp_head' ) {
		$id = $this->factory->post->create( [ 'post_type' => Anchor_Code_Snippets_Module::CPT ] );
		update_post_meta( $id, 'acs_location', $location );
		update_post_meta( $id, 'anchor_consent_category', $category );
		return $id;
	}

	public function set_up() {
		parent::set_up();
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';

		// The `code_snippets` module isn't enabled by tests/bootstrap.php (see
		// the class docblock), so its CPT is never registered by the plugin's
		// own module bootstrap. parent::set_up() already reset WP's post type
		// registry for this test (reset_post_types()), so register it fresh
		// here — otherwise current_user_can( 'edit_post', ... ) in save() trips
		// WP's "post type not registered" _doing_it_wrong() notice below.
		( new Anchor_Code_Snippets_Module() )->register_cpt();
	}

	public function tear_down() {
		unset(
			$_SERVER['HTTP_CF_IPCOUNTRY'],
			$_COOKIE[ Anchor_Compliance_Consent_State::COOKIE ],
			$_POST[ Anchor_Compliance_Snippets_Bridge::NONCE ],
			$_POST[ Anchor_Compliance_Snippets_Bridge::META_KEY ]
		);
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
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

	/* ─── Fix 1 regression coverage: JSON-LD must never be rewritten ─── */

	/**
	 * PINS FIX 1. A prior version of neutralize_inline_scripts() had no
	 * is_executable_type() guard, so a gated snippet's own JSON-LD block got
	 * its type rewritten to text/plain even though
	 * Anchor_Compliance_Script_Blocker itself would never touch it —
	 * structured data is this plugin's flagship feature.
	 */
	public function test_json_ld_script_is_never_touched_even_in_a_gated_snippet() {
		$id   = $this->snippet( 'marketing' );
		$html = '<script type="application/ld+json">{"@type":"WebSite","name":"Example"}</script>';

		$this->assertSame(
			$html,
			$this->bridge()->filter_snippet_output( $html, $id ),
			'Structured data must never be gated, even when the snippet it lives in is denied.'
		);
	}

	/** Same as above, but alongside a genuinely executable script in the same snippet — only the executable one may be touched. */
	public function test_json_ld_survives_alongside_a_gated_inline_script() {
		$id   = $this->snippet( 'marketing' );
		$html = '<script type="application/ld+json">{"@type":"WebSite"}</script>'
			. '<script>fbq("init","1");</script>';

		$out = $this->bridge()->filter_snippet_output( $html, $id );

		$this->assertStringContainsString(
			'<script type="application/ld+json">{"@type":"WebSite"}</script>',
			$out,
			'JSON-LD must survive byte-for-byte.'
		);
		$this->assertSame(
			1,
			substr_count( $out, 'data-anchor-consent="marketing"' ),
			'Exactly the executable script — not the JSON-LD — must be gated.'
		);
	}

	/* ─── Fix 4: mixed-content coverage ─── */

	public function test_mixed_content_neutralizes_both_scripts_and_leaves_other_markup_untouched() {
		$id   = $this->snippet( 'marketing' );
		$html = '<script src="https://example.com/a.js"></script>'
			. '<script>console.log(2);</script>'
			. '<style>.acme{color:red}</style>'
			. '<div class="acme-banner">Hello</div>';

		$out = $this->bridge()->filter_snippet_output( $html, $id );

		$this->assertSame( 2, substr_count( $out, 'type="text/plain"' ), 'Both scripts must be neutralized.' );
		$this->assertSame( 2, substr_count( $out, 'data-anchor-consent="marketing"' ) );
		$this->assertStringContainsString( 'data-anchor-src="https://example.com/a.js"', $out );
		$this->assertStringContainsString( 'console.log(2);', $out, 'Inline body must be preserved verbatim.' );
		$this->assertStringContainsString( '<style>.acme{color:red}</style>', $out, 'A <style> block must be byte-identical.' );
		$this->assertStringContainsString( '<div class="acme-banner">Hello</div>', $out, 'Non-script markup must be byte-identical.' );
	}

	/* ─── Fix 4: parity pinned by full-string equality, not attribute sniffing ─── */

	private function blocker() {
		return new Anchor_Compliance_Script_Blocker(
			new Anchor_Compliance_Service_Registry(),
			new Anchor_Compliance_Consent_State(),
			new Anchor_Compliance_Geo()
		);
	}

	public function test_src_output_matches_the_script_blocker_byte_for_byte() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'custom_rules' => [
				[ 'label' => 'Parity', 'url_pattern' => 'parity-test.example', 'category' => 'marketing', 'cookie_patterns' => [] ],
			],
		], false );

		$html = '<script src="https://parity-test.example/x.js"></script>';
		$id   = $this->snippet( 'marketing' );

		$this->assertSame(
			$this->blocker()->rewrite( $html ),
			$this->bridge()->filter_snippet_output( $html, $id ),
			'A <script src> must be neutralized identically by the blocker and the bridge.'
		);
	}

	public function test_inline_output_matches_the_script_blocker_byte_for_byte() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [
			'custom_rules' => [
				[ 'label' => 'Parity Inline', 'url_pattern' => 'parityTestMarker', 'category' => 'marketing', 'cookie_patterns' => [] ],
			],
		], false );

		$html = '<script>parityTestMarker();</script>';
		$id   = $this->snippet( 'marketing' );

		$this->assertSame(
			$this->blocker()->rewrite( $html ),
			$this->bridge()->filter_snippet_output( $html, $id ),
			'An inline <script> must be neutralized identically by the blocker and the bridge.'
		);
	}

	/* ─── save(): nonce / autosave / capability / sanitize_category routing ─── */

	private function admin() {
		$id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $id );
		return $id;
	}

	public function test_save_rejects_an_invalid_nonce() {
		$id = $this->snippet( 'necessary' );
		$this->admin();

		$_POST[ Anchor_Compliance_Snippets_Bridge::NONCE ]    = 'not-a-real-nonce';
		$_POST[ Anchor_Compliance_Snippets_Bridge::META_KEY ] = 'marketing';

		$this->bridge()->save( $id );

		$this->assertSame( 'necessary', get_post_meta( $id, 'anchor_consent_category', true ) );
	}

	/**
	 * DOING_AUTOSAVE is a raw PHP constant with no WP-core filter wrapper, and
	 * once define()'d it can never be unset for the rest of the PHPUnit
	 * process — permanently flipping the bail branch for every other
	 * save_post-hooked test that runs afterward. Anchor_Compliance_Snippets_Bridge::is_autosave()
	 * exists specifically so this can be pinned via a test subclass override
	 * instead, with zero process-wide side effects.
	 */
	public function test_save_bails_on_autosave() {
		$id = $this->snippet( 'necessary' );
		$this->admin();

		$bridge = new class( new Anchor_Compliance_Consent_State(), new Anchor_Compliance_Geo() ) extends Anchor_Compliance_Snippets_Bridge {
			protected function is_autosave() {
				return true;
			}
		};

		$_POST[ Anchor_Compliance_Snippets_Bridge::NONCE ]    = wp_create_nonce( Anchor_Compliance_Snippets_Bridge::ACTION );
		$_POST[ Anchor_Compliance_Snippets_Bridge::META_KEY ] = 'marketing';

		$bridge->save( $id );

		$this->assertSame( 'necessary', get_post_meta( $id, 'anchor_consent_category', true ), 'save() must bail during an autosave request.' );
	}

	public function test_save_rejects_a_user_lacking_edit_post() {
		$id          = $this->snippet( 'necessary' );
		$subscriber  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$_POST[ Anchor_Compliance_Snippets_Bridge::NONCE ]    = wp_create_nonce( Anchor_Compliance_Snippets_Bridge::ACTION );
		$_POST[ Anchor_Compliance_Snippets_Bridge::META_KEY ] = 'marketing';

		$this->bridge()->save( $id );

		$this->assertSame( 'necessary', get_post_meta( $id, 'anchor_consent_category', true ), 'A user lacking edit_post must not be able to change the category.' );
	}

	public function test_save_persists_a_valid_category() {
		$id = $this->snippet( 'necessary' );
		$this->admin();

		$_POST[ Anchor_Compliance_Snippets_Bridge::NONCE ]    = wp_create_nonce( Anchor_Compliance_Snippets_Bridge::ACTION );
		$_POST[ Anchor_Compliance_Snippets_Bridge::META_KEY ] = 'analytics';

		$this->bridge()->save( $id );

		$this->assertSame( 'analytics', get_post_meta( $id, 'anchor_consent_category', true ) );
	}

	/** An invalid category must be routed through Anchor_Compliance_Settings::sanitize_category(), never stored raw. */
	public function test_save_routes_the_value_through_sanitize_category() {
		$id = $this->snippet( 'necessary' );
		$this->admin();

		$_POST[ Anchor_Compliance_Snippets_Bridge::NONCE ]    = wp_create_nonce( Anchor_Compliance_Snippets_Bridge::ACTION );
		$_POST[ Anchor_Compliance_Snippets_Bridge::META_KEY ] = 'not-a-real-category<script>';

		$this->bridge()->save( $id );

		$this->assertSame(
			'marketing',
			get_post_meta( $id, 'anchor_consent_category', true ),
			'An unrecognized category must fall back to sanitize_category()\'s default (marketing), never store the raw value.'
		);
	}
}
