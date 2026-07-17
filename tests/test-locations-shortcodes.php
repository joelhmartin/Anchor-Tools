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
    public function test_parent_shortcode_hides_unpublished_parent() {
        $parent = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'draft', 'post_title' => 'Hidden Parent' ] );
        $child  = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Visible Child', 'post_parent' => $parent ] );
        $out = do_shortcode( '[anchor_location_parent id="' . $child . '"]' );
        $this->assertStringNotContainsString( 'Hidden Parent', $out );
        $this->assertStringNotContainsString( '<a', $out );
    }
    public function test_render_body_recursion_guard_prevents_infinite_loop() {
        $id = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
        // al_html embeds [anchor_page_content] pointed at its own post — without a
        // guard, render_body() -> do_shortcode() -> shortcode_page_content() ->
        // render_body() recurses until the request dies.
        update_post_meta( $id, 'al_html', '<p>Before</p>[anchor_page_content id="' . $id . '"]<p>After</p>' );
        $mod = new \Anchor\Locations\Module();
        $out = $mod->render_body( $id );
        $this->assertStringContainsString( '<p>Before</p>', $out );
        $this->assertStringContainsString( '<p>After</p>', $out );
        // The nested self-reference must resolve to empty (guarded), not recurse.
        $this->assertSame( 1, substr_count( $out, '<p>Before</p>' ) );
    }
    public function test_parent_shortcode_bogus_id_does_not_fatal() {
        $out = do_shortcode( '[anchor_location_parent id="99999999"]' );
        $this->assertIsString( $out );
        $this->assertSame( '', $out );
    }
    public function test_nearby_shortcode_bogus_id_does_not_fatal() {
        $out = do_shortcode( '[anchor_nearby_locations id="99999999"]' );
        $this->assertIsString( $out );
        $this->assertSame( '', $out );
    }
    public function test_breadcrumbs_skips_unpublished_ancestor() {
        $root   = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pennsylvania' ] );
        $middle = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'draft', 'post_title' => 'Hidden County', 'post_parent' => $root ] );
        $grand  = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh', 'post_parent' => $middle ] );
        $out = do_shortcode( '[anchor_breadcrumbs id="' . $grand . '"]' );
        $this->assertStringNotContainsString( 'Hidden County', $out );
        $this->assertStringContainsString( 'Pennsylvania', $out );
        $this->assertStringContainsString( 'Pittsburgh', $out );
    }
}
