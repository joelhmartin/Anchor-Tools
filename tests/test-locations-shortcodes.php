<?php
// tests/test-locations-shortcodes.php
class LocationsShortcodesTest extends WP_UnitTestCase {
    public function test_render_body_outputs_scoped_html_css_js() {
        $id = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
        update_post_meta( $id, 'al_html', '<h1>Pittsburgh</h1>' );
        update_post_meta( $id, 'al_css', 'h1{color:red}' );
        update_post_meta( $id, 'al_js', 'console.log(1)' );
        $mod = new \Anchor\Locations\Module();
        $out = $mod->render_body( $id );
        $this->assertStringContainsString( '<h1>Pittsburgh</h1>', $out );
        $this->assertStringContainsString( 'color:red', $out );
        $this->assertStringContainsString( 'console.log(1)', $out );
    }
    public function test_page_content_shortcode_by_id() {
        $id = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
        update_post_meta( $id, 'al_html', '<p>Body</p>' );
        $out = do_shortcode( '[anchor_page_content id="' . $id . '"]' );
        $this->assertStringContainsString( '<p>Body</p>', $out );
    }
}
