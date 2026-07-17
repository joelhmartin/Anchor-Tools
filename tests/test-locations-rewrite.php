<?php
// tests/test-locations-rewrite.php
class LocationsRewriteTest extends WP_UnitTestCase {
    public function test_post_types_and_taxonomy_registered() {
        $this->assertTrue( post_type_exists( 'anchor_location' ) );
        $this->assertTrue( post_type_exists( 'anchor_service_page' ) );
        $this->assertTrue( taxonomy_exists( 'service' ) );
        $this->assertTrue( is_post_type_hierarchical( 'anchor_location' ) );
        $this->assertFalse( is_post_type_hierarchical( 'anchor_service_page' ) );
        $this->assertTrue( is_taxonomy_hierarchical( 'service' ) );
    }

    public function test_service_permalink_built_from_service_and_location() {
        $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_name' => 'pittsburgh-pa', 'post_status' => 'publish' ] );
        $term = wp_insert_term( 'Roofing', 'service' );
        $sp = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_name' => 'roofing-pittsburgh-pa', 'post_status' => 'publish' ] );
        wp_set_object_terms( $sp, [ (int) $term['term_id'] ], 'service' );
        update_post_meta( $sp, 'al_location_id', $loc );

        $url = get_permalink( $sp );
        $this->assertStringContainsString( '/services/roofing/pittsburgh-pa/', $url );
    }
    public function test_service_permalink_is_hash_when_link_missing() {
        $sp = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish' ] );
        $this->assertSame( '#', get_permalink( $sp ) );
    }

    /**
     * Regression test for the nested-location 404 bug: find_service_page() used
     * get_page_by_path(), which for a hierarchical CPT only matches top-level
     * posts (post_parent = 0). A city location nested under a county parent was
     * never found by its bare slug, so /services/roofing/pittsburgh-pa/ 404'd
     * even though service_page_url() happily generated that exact link.
     */
    public function test_inbound_request_resolves_nested_location_service_page() {
        $this->set_permalink_structure( '/%postname%/' );
        // The module's add_rewrite_rules() runs on init; flush so the custom
        // al_service/al_loc rewrite rule is registered before we go_to() it.
        flush_rewrite_rules();

        $county = self::factory()->post->create( [
            'post_type'   => 'anchor_location',
            'post_name'   => 'allegheny-county-pa',
            'post_status' => 'publish',
            'post_parent' => 0,
        ] );
        $city = self::factory()->post->create( [
            'post_type'   => 'anchor_location',
            'post_name'   => 'pittsburgh-pa',
            'post_status' => 'publish',
            'post_parent' => $county,
        ] );

        $term = wp_insert_term( 'Roofing', 'service' );
        $service_page = self::factory()->post->create( [
            'post_type'   => 'anchor_service_page',
            'post_name'   => 'roofing-pittsburgh-pa',
            'post_status' => 'publish',
        ] );
        wp_set_object_terms( $service_page, [ (int) $term['term_id'] ], 'service' );
        update_post_meta( $service_page, 'al_location_id', $city );

        $this->go_to( home_url( '/services/roofing/pittsburgh-pa/' ) );

        $this->assertTrue( is_singular( 'anchor_service_page' ), 'Inbound nested-location request should resolve to the service page, not 404.' );
        $this->assertSame( $service_page, get_queried_object_id() );
    }
}
