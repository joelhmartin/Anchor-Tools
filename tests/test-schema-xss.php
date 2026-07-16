<?php
/**
 * Regression test for a HIGH-severity stored XSS in the Schema JSON-LD
 * feature: `Anchor_Schema_Helper::minify_json()` / `validate_schema_json()`
 * encoded with JSON_UNESCAPED_SLASHES, so a stored string value containing a
 * literal "</script>" closed the inline
 * `<script type="application/ld+json">` tag that
 * `Anchor_Schema_Render::output_active_schemas()` echoes on wp_head,
 * allowing injected markup for every visitor of the affected post.
 *
 * @package Anchor\Tests
 */

/**
 * @group security
 * @group schema-xss
 */
class Test_Schema_Xss extends WP_UnitTestCase {

	/**
	 * A stored value containing a </script> breakout attempt followed by an
	 * injected <script> tag, as a Contributor could submit via
	 * Anchor_Schema_Admin::ajax_upload()/ajax_generate()/ajax_update_item().
	 */
	const PAYLOAD = '</script><script>alert(1)</script>';

	public function tear_down() {
		delete_option( Anchor_Schema_Admin::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * minify_json() must slash-escape so a literal "</script>" in a decoded
	 * value cannot re-appear raw in the re-encoded JSON string.
	 */
	public function test_minify_json_escapes_script_breakout() {
		// Built by hand (not via json_encode()) so the literal, unescaped
		// slashes reach minify_json() exactly as an attacker-controlled
		// value would — JSON does not require slashes to be escaped, so
		// this is valid input.
		$input = '{"@context":"https://schema.org","@type":"Article","name":"' . self::PAYLOAD . '"}';

		$output = Anchor_Schema_Helper::minify_json( $input );

		$this->assertNotSame( '', $output, 'minify_json() should successfully parse valid JSON.' );
		$this->assertStringNotContainsString(
			'</script>',
			$output,
			'A raw </script> in the encoded output would break out of the inline <script> tag it is echoed into.'
		);
		$this->assertStringContainsString( '<\/script>', $output, 'Slashes must be escaped so </script> cannot appear raw.' );

		// Escaping is transparent to any JSON consumer: the decoded value
		// must round-trip to the original, unescaped string.
		$decoded = json_decode( $output, true );
		$this->assertSame( self::PAYLOAD, $decoded['name'], 'Slash-escaping must not change the semantic JSON value.' );
	}

	/**
	 * validate_schema_json() (used by ajax_upload()'s file-upload path) has
	 * the same encode call and must have the same fix.
	 */
	public function test_validate_schema_json_escapes_script_breakout() {
		$input = '{"@context":"https://schema.org","@type":"Article","name":"' . self::PAYLOAD . '"}';

		$result = Anchor_Schema_Helper::validate_schema_json( $input );

		$this->assertSame( [], $result['errors'], 'A well-formed schema object should validate without errors.' );
		$this->assertStringNotContainsString(
			'</script>',
			$result['normalized_json'],
			'A raw </script> in normalized_json would break out of the inline <script> tag it is echoed into.'
		);
		$this->assertStringContainsString( '<\/script>', $result['normalized_json'] );

		$decoded = json_decode( $result['normalized_json'], true );
		$this->assertSame( self::PAYLOAD, $decoded['name'] );
	}

	/**
	 * Belt-and-suspenders: even a legacy/unescaped value already sitting in
	 * post_meta (e.g. stored before this fix shipped) must not survive the
	 * render-time echo with a raw </script> in it, thanks to the
	 * str_replace() guard in Anchor_Schema_Render::output_active_schemas().
	 */
	public function test_render_output_neutralizes_unescaped_legacy_value() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// Simulate a pre-fix stored value: built by hand with raw slashes,
		// bypassing minify_json() entirely, so this test exercises ONLY the
		// render-site guard.
		$legacy_min_json = '{"@context":"https://schema.org","@type":"Article","name":"' . self::PAYLOAD . '"}';

		update_post_meta(
			$post_id,
			Anchor_Schema_Admin::META_KEY,
			[
				[
					'id'       => 'legacy-1',
					'enabled'  => true,
					'min_json' => $legacy_min_json,
				],
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular(), 'Precondition: go_to() must land on a singular post for output_active_schemas() to run.' );

		$render = new Anchor_Schema_Render();
		ob_start();
		$render->output_active_schemas();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'application/ld+json', $output, 'Precondition: the schema script tag should have printed.' );
		$this->assertSame(
			1,
			substr_count( $output, '</script>' ),
			'Only the real closing </script> tag should appear — the payload\'s </script> must be neutralized.'
		);
		$this->assertStringContainsString( '<\/script>', $output );
	}
}
