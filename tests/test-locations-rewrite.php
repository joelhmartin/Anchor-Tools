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
}
