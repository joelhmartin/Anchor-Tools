<?php
/**
 * sanitize_external_embed(): iframe embeds (one or several) survive with the
 * attributes real form providers emit; scripts are dropped WHOLE.
 *
 * wp_kses() removes a disallowed tag but keeps its text, so an inline
 * <script>…</script> used to come out as visible JavaScript on the page.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group event-save
 */
class Test_External_Embed_Sanitizer extends Anchor_Events_TestCase {

	const FORM = '<iframe id="FormIFrame-123456789012345" title="Registration" src="https://forms.example.com/123456789012345" allowtransparency="true" allow="geolocation; microphone; camera; fullscreen" frameborder="0" scrolling="no" style="min-width:100%;height:900px;border:none;"></iframe>';

	private function clean( $html ) {
		return $this->module()->sanitize_external_embed( $html, '_anchor_event_external_embed', 'post' );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_events_embed_allowed_html' );
		parent::tear_down();
	}

	public function test_inline_script_body_is_dropped_not_printed() {
		$out = $this->clean( self::FORM . "\n<script src=\"https://cdn.forms.example.com/embed-handler.js\"></script>\n<script>window.formEmbedHandler(\"iframe[id='FormIFrame-123456789012345']\", \"https://forms.example.com/\");</script>" );
		$this->assertStringNotContainsString( 'formEmbedHandler', $out );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringContainsString( 'src="https://forms.example.com/123456789012345"', $out );
	}

	public function test_provider_iframe_attributes_survive() {
		$out = $this->clean( self::FORM );
		$this->assertStringContainsString( 'id="FormIFrame-123456789012345"', $out );
		$this->assertStringContainsString( 'scrolling="no"', $out );
		$this->assertStringContainsString( 'allowtransparency="true"', $out );
	}

	public function test_several_iframes_one_per_session_all_survive() {
		$two = '<iframe src="https://forms.example.com/1"></iframe><iframe src="https://forms.example.com/2"></iframe>';
		$this->assertSame( 2, substr_count( $this->clean( $two ), '<iframe' ) );
	}

	public function test_style_element_is_dropped_whole() {
		$this->assertStringNotContainsString( 'color:red', $this->clean( '<style>.x{color:red}</style>' . self::FORM ) );
	}

	private function opt_scripts_in() {
		add_filter( 'anchor_events_embed_allowed_html', function ( $allowed ) {
			$allowed['script'] = [ 'src' => true ];
			return $allowed;
		} );
	}

	public function test_opt_in_keeps_scripts_for_a_user_with_unfiltered_html() {
		$this->opt_scripts_in();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		$this->assertStringContainsString( '<script src="https://cdn.forms.example.com/h.js">', $this->clean( '<script src="https://cdn.forms.example.com/h.js"></script>' ) );
	}

	public function test_opt_in_does_not_let_a_user_without_unfiltered_html_store_scripts() {
		$this->opt_scripts_in();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$out = $this->clean( '<script src="https://cdn.forms.example.com/h.js"></script><script>steal()</script>' . self::FORM );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( 'steal', $out );
	}

	public function test_event_handlers_are_still_stripped() {
		$this->assertStringNotContainsString( 'onload', $this->clean( '<iframe src="https://forms.example.com/1" onload="alert(1)"></iframe>' ) );
	}
}
