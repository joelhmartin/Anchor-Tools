<?php
/** @group testimonials */
class Test_Testimonials_Render extends WP_UnitTestCase {
	public function test_grid_markup_escapes_and_has_video_button() {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial', 'post_content' => 'Life <b>changing</b><script>x</script>' ] );
		Anchor_Testimonial_Meta::save( $id, [ 'person_name' => 'Jane "J" Doe', 'video_url' => 'https://youtu.be/dwr8S2iOfs8' ] );
		$html = do_shortcode( '[anchor_testimonials layout="grid"]' );
		$this->assertStringContainsString( 'anchor-testimonials--grid', $html );
		$this->assertStringContainsString( 'data-video-id="dwr8S2iOfs8"', $html );
		$this->assertStringContainsString( 'Jane &quot;J&quot; Doe', $html );
		$this->assertStringNotContainsString( '<script>x</script>', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertTrue( wp_script_is( 'anchor-testimonials', 'enqueued' ) );
	}
	public function test_slider_has_controls_and_empty_returns_nothing() {
		$this->assertSame( '', do_shortcode( '[anchor_testimonials audience="nobody"]' ) );
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial', 'post_content' => 'Q' ] );
		$html = do_shortcode( '[anchor_testimonials layout="slider" columns="2"]' );
		$this->assertStringContainsString( 'anchor-testimonials__controls', $html );
		$this->assertStringContainsString( '--at-cols:2', $html );
	}

	/**
	 * PR #28 review finding E: the slider prev/next buttons had no text or
	 * icon content, so sighted visitors could not identify them. They must
	 * carry a visible (but aria-hidden) glyph while keeping the accessible
	 * aria-label.
	 */
	public function test_slider_controls_have_visible_glyphs_and_accessible_labels() {
		self::factory()->post->create( [ 'post_type' => 'anchor_testimonial', 'post_content' => 'Q' ] );
		$html = do_shortcode( '[anchor_testimonials layout="slider"]' );

		$this->assertMatchesRegularExpression(
			'~<button type="button" class="anchor-testimonials__prev" aria-label="Previous"><svg[^>]*aria-hidden="true"[^>]*>.*?</svg></button>~s',
			$html
		);
		$this->assertMatchesRegularExpression(
			'~<button type="button" class="anchor-testimonials__next" aria-label="Next"><svg[^>]*aria-hidden="true"[^>]*>.*?</svg></button>~s',
			$html
		);
	}

	/**
	 * Final whole-branch review finding 10: video-grid renders only the media
	 * button + name (no quote, no photo, no meta, no rating). A quote-only
	 * testimonial has no video and so no media button, and would render as an
	 * effectively empty card unless `type` defaults to `video` for this
	 * layout when the shortcode doesn't specify one explicitly.
	 *
	 * PR #28 review finding I: an explicit `type` attribute (e.g.
	 * `type="quote"` or `type="any"`) used to override that default, which
	 * let a quote-only testimonial reach video-grid where it rendered only
	 * the person's name (no quote, since video-grid never shows one).
	 * `type` is now forced to `video` for this layout unconditionally.
	 */
	public function test_video_grid_always_excludes_quote_only_testimonials_regardless_of_type() {
		$video_id = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial' ] );
		Anchor_Testimonial_Meta::save( $video_id, [ 'person_name' => 'Video Person', 'video_url' => 'https://youtu.be/dwr8S2iOfs8' ] );
		$quote_id = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial', 'post_content' => 'Quote only.' ] );
		Anchor_Testimonial_Meta::save( $quote_id, [ 'person_name' => 'Quote Person' ] );

		$html = do_shortcode( '[anchor_testimonials layout="video-grid"]' );
		$this->assertStringContainsString( 'Video Person', $html );
		$this->assertStringNotContainsString(
			'Quote Person',
			$html,
			'A quote-only testimonial renders as an empty card in video-grid; the default type must exclude it.'
		);

		// An explicit type attribute must NOT override the video-grid
		// filter: video-grid is always a video grid.
		$html_any = do_shortcode( '[anchor_testimonials layout="video-grid" type="any"]' );
		$this->assertStringNotContainsString( 'Quote Person', $html_any, 'An explicit type attribute must not override the video-grid filter.' );

		$html_quote = do_shortcode( '[anchor_testimonials layout="video-grid" type="quote"]' );
		$this->assertStringContainsString( 'Video Person', $html_quote, 'type="quote" is still forced to video in video-grid, so the video testimonial renders.' );
		$this->assertStringNotContainsString( 'Quote Person', $html_quote, 'type="quote" must not surface a quote-only testimonial in video-grid.' );
	}
}
