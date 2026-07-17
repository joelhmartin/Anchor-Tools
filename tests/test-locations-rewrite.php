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
}
