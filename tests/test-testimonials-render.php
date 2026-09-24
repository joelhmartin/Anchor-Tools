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
}
