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
    public function test_child_locations_lists_only_published_children() {
        $county = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Allegheny County' ] );
        $city   = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh', 'post_parent' => $county ] );
        self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'draft', 'post_title' => 'Hidden', 'post_parent' => $county ] );
        $out = do_shortcode( '[anchor_child_locations id="' . $county . '"]' );
        $this->assertStringContainsString( 'Pittsburgh', $out );
        $this->assertStringNotContainsString( 'Hidden', $out );
    }
    public function test_location_services_lists_service_pages_for_location() {
        $loc  = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_name' => 'pittsburgh-pa' ] );
        $term = wp_insert_term( 'Roofing', 'service' );
        $sp   = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Roofing in Pittsburgh' ] );
        wp_set_object_terms( $sp, [ (int) $term['term_id'] ], 'service' );
        update_post_meta( $sp, 'al_location_id', $loc );
        $out = do_shortcode( '[anchor_location_services id="' . $loc . '"]' );
        $this->assertStringContainsString( 'Roofing in Pittsburgh', $out );
        $this->assertStringContainsString( '/services/roofing/pittsburgh-pa/', $out );
    }
}
