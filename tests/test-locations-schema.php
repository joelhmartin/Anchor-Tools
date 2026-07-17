<?php
/**
 * Tests for anchor-locations JSON-LD schema (BreadcrumbList + Service/Place).
 *
 * @package Anchor\Tests
 */

class LocationsSchemaTest extends WP_UnitTestCase {
	public function test_location_schema_has_place_and_geo() {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh' ] );
		update_post_meta( $id, 'al_type', 'city' );
		update_post_meta( $id, 'al_lat', '40.44' );
		update_post_meta( $id, 'al_lng', '-79.99' );
		$graph = ( new \Anchor\Locations\Module() )->build_schema( $id );
		$types = array_column( $graph, '@type' );
		$this->assertContains( 'City', $types );
		$hasGeo = false;
		foreach ( $graph as $n ) {
			if ( isset( $n['geo']['latitude'] ) && (float) $n['geo']['latitude'] === 40.44 ) {
				$hasGeo = true;
			}
		}
		$this->assertTrue( $hasGeo );
	}

	public function test_service_page_schema_has_service_areaserved_no_postaladdress() {
		$loc  = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh', 'post_name' => 'pittsburgh-pa' ] );
		update_post_meta( $loc, 'al_type', 'city' );
		$term = wp_insert_term( 'Roofing', 'service' );
		$sp   = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Roofing in Pittsburgh' ] );
		wp_set_object_terms( $sp, [ (int) $term['term_id'] ], 'service' );
		update_post_meta( $sp, 'al_location_id', $loc );
		$graph = ( new \Anchor\Locations\Module() )->build_schema( $sp );
		$json = wp_json_encode( $graph );
		$this->assertStringContainsString( '"Service"', $json );
		$this->assertStringContainsString( 'areaServed', $json );
		$this->assertStringNotContainsString( 'PostalAddress', $json );
	}

	public function test_breadcrumbs_exclude_unpublished_ancestor() {
		$root = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pennsylvania' ] );
		$draft_middle = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'draft', 'post_title' => 'SecretCounty', 'post_parent' => $root ] );
		$leaf = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh', 'post_parent' => $draft_middle ] );
		update_post_meta( $leaf, 'al_type', 'city' );

		$graph = ( new \Anchor\Locations\Module() )->build_schema( $leaf );
		$breadcrumbs = null;
		foreach ( $graph as $node ) {
			if ( isset( $node['@type'] ) && $node['@type'] === 'BreadcrumbList' ) {
				$breadcrumbs = $node;
				break;
			}
		}
		$this->assertNotNull( $breadcrumbs );
		$names = array_column( $breadcrumbs['itemListElement'], 'name' );
		$this->assertNotContains( 'SecretCounty', $names );
		$this->assertContains( 'Pennsylvania', $names );
		$this->assertContains( 'Pittsburgh', $names );
	}
}
