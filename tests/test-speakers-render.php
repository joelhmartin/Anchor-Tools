<?php
/** @group speakers */
class Test_Speakers_Render extends WP_UnitTestCase {
	public function test_featured_filter_and_order() {
		$a = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. B', 'menu_order' => 2 ] );
		$b = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. A', 'menu_order' => 1 ] );
		$c = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. C' ] );
		Anchor_Speaker_Meta::save( $a, [ 'featured' => '1', 'credentials' => 'DDS' ] );
		Anchor_Speaker_Meta::save( $b, [ 'featured' => '1' ] );
		$html = do_shortcode( '[anchor_speakers featured="1"]' );
		$this->assertLessThan( strpos( $html, 'Dr. B' ), strpos( $html, 'Dr. A' ) );
		$this->assertStringNotContainsString( 'Dr. C', $html );
		$this->assertStringContainsString( 'anchor-speaker__credentials">DDS', $html );
	}
	public function test_ids_order_and_link_off() {
		$a = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'One' ] );
		$b = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Two' ] );
		$html = do_shortcode( "[anchor_speakers ids=\"$b,$a\" link=\"0\"]" );
		$this->assertLessThan( strpos( $html, 'One' ), strpos( $html, 'Two' ) );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	/**
	 * An auto-generated excerpt's trailing "more" text is the literal entity
	 * "&hellip;", not a raw ellipsis character, so the excerpt is already
	 * HTML. Asserts it is never double-encoded into visible "&amp;hellip;"
	 * text (WordPress' esc_html() already guards against this by default,
	 * so this alone would pass either way; see the second assertion below
	 * for a case that actually distinguishes wp_kses_post() from esc_html()).
	 */
	public function test_auto_excerpt_does_not_double_encode_entities() {
		$id = self::factory()->post->create( [
			'post_type'    => 'anchor_speaker',
			'post_title'   => 'Dr. Long Bio',
			'post_content' => str_repeat( 'word ', 60 ),
			'post_excerpt' => '',
		] );
		$html = do_shortcode( "[anchor_speakers ids=\"$id\"]" );
		$this->assertStringContainsString( 'anchor-speaker__excerpt', $html );
		$this->assertStringNotContainsString( '&amp;hellip;', $html );
	}

	/**
	 * A manual excerpt is already HTML and may intentionally contain simple
	 * inline markup (the same convention testimonials uses for post_content
	 * via wp_kses_post()). esc_html() would strip that down to visible,
	 * escaped tag text instead of rendering it; this is the case that
	 * actually distinguishes the two.
	 */
	public function test_manual_excerpt_keeps_allowed_inline_markup() {
		$id = self::factory()->post->create( [
			'post_type'    => 'anchor_speaker',
			'post_title'   => 'Dr. Rich Excerpt',
			'post_excerpt' => 'Board-certified in <strong>orofacial pain</strong>.',
		] );
		$html = do_shortcode( "[anchor_speakers ids=\"$id\"]" );
		$this->assertStringContainsString( '<strong>orofacial pain</strong>', $html );
	}

	/**
	 * PR #28 review finding A: an `event` attribute that resolves to no
	 * linked speakers (a real event with none linked, or the Events module
	 * being inactive so event_speaker_ids() always returns []) must render
	 * nothing, not fall through to the unscoped query and list every
	 * published speaker.
	 */
	public function test_event_with_no_linked_speakers_renders_nothing() {
		self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. Everyone' ] );
		$event = self::factory()->post->create( [ 'post_type' => 'post', 'post_title' => 'Some Event' ] );
		$html  = do_shortcode( "[anchor_speakers event=\"$event\"]" );
		$this->assertSame( '', $html );
	}

	/**
	 * An invalid/non-numeric event id (resolves to 0) must behave the same
	 * as a valid event with no linked speakers: nothing renders.
	 */
	public function test_invalid_event_id_renders_nothing() {
		self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. Everyone' ] );
		$html = do_shortcode( '[anchor_speakers event="not-a-real-id"]' );
		$this->assertSame( '', $html );
	}

	/**
	 * PR #28 review finding K (Codex P2): [anchor_speakers] embedded on a
	 * non-speaker page normally runs during the_content, after wp_head has
	 * already printed the style queue, so speakers.css would only be picked
	 * up via print_late_styles() in the footer. maybe_enqueue_frontend_assets(),
	 * hooked on wp_enqueue_scripts (before wp_head), covers a singular
	 * post/page whose content literally contains the shortcode, in addition
	 * to the existing is_singular(self::CPT) case.
	 */
	public function test_maybe_enqueue_frontend_assets_enqueues_early_on_a_singular_page_with_the_shortcode() {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_content' => 'Meet our team. [anchor_speakers]' ] );
		$this->go_to( get_permalink( $page ) );

		( new Anchor_Speakers_Module() )->maybe_enqueue_frontend_assets();

		$this->assertTrue( wp_style_is( 'anchor-speakers', 'enqueued' ) );
	}

	public function test_maybe_enqueue_frontend_assets_is_a_noop_on_a_singular_page_without_the_shortcode() {
		// The style queue is process-global, not reset between test methods,
		// so start from a known-clean state (same convention as
		// tests/test-compliance-banner.php) rather than relying on test order.
		wp_dequeue_style( 'anchor-speakers' );
		wp_deregister_style( 'anchor-speakers' );

		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_content' => 'Nothing to see here.' ] );
		$this->go_to( get_permalink( $page ) );

		( new Anchor_Speakers_Module() )->maybe_enqueue_frontend_assets();

		$this->assertFalse( wp_style_is( 'anchor-speakers', 'enqueued' ) );
	}
}
