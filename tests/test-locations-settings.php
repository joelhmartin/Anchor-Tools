<?php
// tests/test-locations-settings.php
class LocationsSettingsTest extends WP_UnitTestCase {
    public function test_map_data_returns_published_located_markers_filtered_by_type() {
        $city = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh' ] );
        update_post_meta( $city, 'al_lat', '40.44' ); update_post_meta( $city, 'al_lng', '-79.99' ); update_post_meta( $city, 'al_type', 'city' );
        $county = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Allegheny' ] );
        update_post_meta( $county, 'al_lat', '40.46' ); update_post_meta( $county, 'al_lng', '-79.98' ); update_post_meta( $county, 'al_type', 'county' );
        $nocoords = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'NoCoords' ] );
        update_post_meta( $nocoords, 'al_type', 'city' );
        $mod = new \Anchor\Locations\Module();
        $markers = $mod->map_data( [ 'types' => [ 'city' ] ] );
        $titles = wp_list_pluck( $markers, 'title' );
        $this->assertContains( 'Pittsburgh', $titles );
        $this->assertNotContains( 'Allegheny', $titles );   // filtered by type
        $this->assertNotContains( 'NoCoords', $titles );     // no coords excluded
    }
}
