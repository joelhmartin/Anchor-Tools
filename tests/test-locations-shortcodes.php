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
    /**
     * Helper: make $id the "current post" the way the loop would, so
     * get_the_ID() inside a shortcode resolves to it.
     */
    private function with_current_post( $id, callable $fn ) {
        global $post;
        $prev = $post;
        $post = get_post( $id );
        setup_postdata( $post );
        try { return $fn(); } finally { wp_reset_postdata(); $post = $prev; }
    }

    public function test_location_services_on_service_page_resolves_to_linked_location() {
        $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_name' => 'pittsburgh-pa' ] );
        $t1  = wp_insert_term( 'Roofing', 'service' );
        $t2  = wp_insert_term( 'Siding', 'service' );
        $sp1 = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Roofing in Pittsburgh' ] );
        $sp2 = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Siding in Pittsburgh' ] );
        wp_set_object_terms( $sp1, [ (int) $t1['term_id'] ], 'service' );
        wp_set_object_terms( $sp2, [ (int) $t2['term_id'] ], 'service' );
        update_post_meta( $sp1, 'al_location_id', $loc );
        update_post_meta( $sp2, 'al_location_id', $loc );

        $out = $this->with_current_post( $sp1, function () {
            return do_shortcode( '[anchor_location_services]' );
        } );
        $this->assertStringContainsString( 'Roofing in Pittsburgh', $out );
        $this->assertStringContainsString( 'Siding in Pittsburgh', $out );
    }

    public function test_nearby_on_service_page_resolves_to_linked_location_siblings() {
        $county = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Allegheny County' ] );
        $loc    = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh', 'post_parent' => $county ] );
        self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Bethel Park', 'post_parent' => $county ] );
        $sp = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Roofing in Pittsburgh' ] );
        update_post_meta( $sp, 'al_location_id', $loc );

        $out = $this->with_current_post( $sp, function () {
            return do_shortcode( '[anchor_nearby_locations]' );
        } );
        $this->assertStringContainsString( 'Bethel Park', $out );
        $this->assertStringNotContainsString( 'Pittsburgh', $out );
    }

    public function test_child_locations_and_parent_on_service_page_resolve_to_location() {
        $county = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Allegheny County' ] );
        $loc    = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh', 'post_parent' => $county ] );
        self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Squirrel Hill', 'post_parent' => $loc ] );
        $sp = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Roofing in Pittsburgh' ] );
        update_post_meta( $sp, 'al_location_id', $loc );

        $kids = $this->with_current_post( $sp, function () { return do_shortcode( '[anchor_child_locations]' ); } );
        $this->assertStringContainsString( 'Squirrel Hill', $kids );
        $parent = $this->with_current_post( $sp, function () { return do_shortcode( '[anchor_location_parent]' ); } );
        $this->assertStringContainsString( 'Allegheny County', $parent );
    }

    public function test_explicit_id_attribute_still_overrides_on_service_page() {
        $locA = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_name' => 'pittsburgh-pa' ] );
        $locB = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_name' => 'erie-pa' ] );
        $term = wp_insert_term( 'Roofing', 'service' );
        $spA  = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Roofing in Pittsburgh' ] );
        $spB  = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Roofing in Erie' ] );
        wp_set_object_terms( $spA, [ (int) $term['term_id'] ], 'service' );
        wp_set_object_terms( $spB, [ (int) $term['term_id'] ], 'service' );
        update_post_meta( $spA, 'al_location_id', $locA );
        update_post_meta( $spB, 'al_location_id', $locB );

        $out = $this->with_current_post( $spA, function () use ( $locB ) {
            return do_shortcode( '[anchor_location_services id="' . $locB . '"]' );
        } );
        $this->assertStringContainsString( 'Roofing in Erie', $out );
        $this->assertStringNotContainsString( 'Roofing in Pittsburgh', $out );
    }

    public function test_service_page_with_missing_location_meta_returns_empty_safely() {
        $sp = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Orphan Service' ] );
        $out = $this->with_current_post( $sp, function () { return do_shortcode( '[anchor_location_services]' ); } );
        $this->assertIsString( $out );
        $this->assertSame( '', $out );

        update_post_meta( $sp, 'al_location_id', 99999999 );
        $out2 = $this->with_current_post( $sp, function () {
            return do_shortcode( '[anchor_location_services]' ) . do_shortcode( '[anchor_nearby_locations]' ) . do_shortcode( '[anchor_child_locations]' ) . do_shortcode( '[anchor_location_parent]' );
        } );
        $this->assertSame( '', $out2 );
    }

    public function test_service_locations_still_keys_off_current_service_page() {
        $locA = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_name' => 'pittsburgh-pa' ] );
        $locB = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_name' => 'erie-pa' ] );
        $term = wp_insert_term( 'Roofing', 'service' );
        $spA  = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Roofing in Pittsburgh' ] );
        $spB  = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Roofing in Erie' ] );
        wp_set_object_terms( $spA, [ (int) $term['term_id'] ], 'service' );
        wp_set_object_terms( $spB, [ (int) $term['term_id'] ], 'service' );
        update_post_meta( $spA, 'al_location_id', $locA );
        update_post_meta( $spB, 'al_location_id', $locB );

        $out = $this->with_current_post( $spA, function () { return do_shortcode( '[anchor_service_locations]' ); } );
        $this->assertStringContainsString( 'Roofing in Erie', $out );
        $this->assertStringNotContainsString( 'Roofing in Pittsburgh', $out );
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
