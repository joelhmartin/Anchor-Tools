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

    /** Helper: create a published service page linked to $loc_id with one or more service term slugs. */
    private function make_service_page( $loc_id, $term_slugs, $title = 'Roofing' ) {
        $term_ids = [];
        foreach ( (array) $term_slugs as $term_slug ) {
            $term = get_term_by( 'slug', $term_slug, 'service' );
            if ( ! $term ) {
                $t = wp_insert_term( ucfirst( $term_slug ), 'service', [ 'slug' => $term_slug ] );
                $term_ids[] = is_wp_error( $t ) ? 0 : (int) $t['term_id'];
            } else {
                $term_ids[] = (int) $term->term_id;
            }
        }
        $sp = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => $title ] );
        update_post_meta( $sp, 'al_location_id', $loc_id );
        wp_set_object_terms( $sp, $term_ids, 'service' );
        return $sp;
    }

    public function test_map_data_service_filter_includes_only_matching_service_pages() {
        $a = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'HasRoofing' ] );
        update_post_meta( $a, 'al_lat', '40.1' ); update_post_meta( $a, 'al_lng', '-79.1' ); update_post_meta( $a, 'al_type', 'city' );
        $this->make_service_page( $a, 'roofing' );

        $b = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'NoRoofing' ] );
        update_post_meta( $b, 'al_lat', '40.2' ); update_post_meta( $b, 'al_lng', '-79.2' ); update_post_meta( $b, 'al_type', 'city' );
        $this->make_service_page( $b, 'plumbing', 'Plumbing' );

        $mod = new \Anchor\Locations\Module();
        $markers = $mod->map_data( [ 'service' => 'roofing' ] );
        $titles = wp_list_pluck( $markers, 'title' );
        $this->assertContains( 'HasRoofing', $titles );
        $this->assertNotContains( 'NoRoofing', $titles );

        // Service entries carry all of the page's term slugs so the client can filter.
        $marker = null;
        foreach ( $markers as $m ) { if ( $m['title'] === 'HasRoofing' ) { $marker = $m; } }
        $this->assertNotNull( $marker );
        $this->assertNotEmpty( $marker['services'] );
        $this->assertArrayHasKey( 'service_slugs', $marker['services'][0] );
        $this->assertContains( 'roofing', $marker['services'][0]['service_slugs'] );
    }

    public function test_map_data_service_entry_exposes_all_term_slugs() {
        $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'MultiService' ] );
        update_post_meta( $loc, 'al_lat', '40.3' ); update_post_meta( $loc, 'al_lng', '-79.3' ); update_post_meta( $loc, 'al_type', 'city' );
        // A page tagged with two service terms, roofing listed second.
        $this->make_service_page( $loc, [ 'plumbing', 'roofing' ], 'Plumbing & Roofing' );

        $mod = new \Anchor\Locations\Module();

        // Both slugs are exposed on the service entry regardless of term order.
        $markers = $mod->map_data();
        $marker  = null;
        foreach ( $markers as $m ) { if ( $m['title'] === 'MultiService' ) { $marker = $m; } }
        $this->assertNotNull( $marker );
        $this->assertContains( 'roofing', $marker['services'][0]['service_slugs'] );
        $this->assertContains( 'plumbing', $marker['services'][0]['service_slugs'] );

        // And filtering by the non-first term still includes the location.
        $filtered = wp_list_pluck( $mod->map_data( [ 'service' => 'roofing' ] ), 'title' );
        $this->assertContains( 'MultiService', $filtered );
    }

    public function test_map_data_markers_include_type() {
        $city = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Erie' ] );
        update_post_meta( $city, 'al_lat', '42.12' ); update_post_meta( $city, 'al_lng', '-80.08' ); update_post_meta( $city, 'al_type', 'city' );
        $mod = new \Anchor\Locations\Module();
        $markers = $mod->map_data();
        $this->assertNotEmpty( $markers );
        foreach ( $markers as $m ) {
            $this->assertArrayHasKey( 'type', $m );
            if ( $m['title'] === 'Erie' ) { $this->assertSame( 'city', $m['type'] ); }
        }
    }

    public function test_map_data_includes_valid_boundary_and_skips_invalid() {
        $geojson = '{"type":"Polygon","coordinates":[[[-80,40],[-80,41],[-79,41],[-79,40],[-80,40]]]}';
        $good = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'GoodBoundary' ] );
        update_post_meta( $good, 'al_lat', '40.5' ); update_post_meta( $good, 'al_lng', '-79.5' ); update_post_meta( $good, 'al_type', 'county' );
        update_post_meta( $good, 'al_boundary', $geojson );

        $bad = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'BadBoundary' ] );
        update_post_meta( $bad, 'al_lat', '40.6' ); update_post_meta( $bad, 'al_lng', '-79.6' ); update_post_meta( $bad, 'al_type', 'county' );
        update_post_meta( $bad, 'al_boundary', 'not json{' );

        $mod = new \Anchor\Locations\Module();
        $markers = $mod->map_data();
        $byTitle = [];
        foreach ( $markers as $m ) { $byTitle[ $m['title'] ] = $m; }

        $this->assertArrayHasKey( 'boundary', $byTitle['GoodBoundary'] );
        $this->assertSame( 'Polygon', $byTitle['GoodBoundary']['boundary']['type'] );
        $this->assertArrayNotHasKey( 'boundary', $byTitle['BadBoundary'] );
    }

    public function test_sanitize_settings_persists_expected_keys() {
        $mod = new \Anchor\Locations\Module();
        $clean = $mod->sanitize_settings( [
            'services_base' => 'Services', 'service_areas_base' => 'Service Areas',
            'map_zoom' => '9', 'marker_icon' => 'https://x/i.svg', 'wrapper_html' => '<div>{{content}}</div>',
        ] );
        $this->assertSame( 'services', $clean['services_base'] );
        $this->assertSame( 'service-areas', $clean['service_areas_base'] );
        $this->assertSame( 9, $clean['map_zoom'] );
        $this->assertStringContainsString( '{{content}}', $clean['wrapper_html'] );
    }
}
