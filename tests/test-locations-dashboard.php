<?php
/**
 * Tests for anchor-locations Coverage Matrix Dashboard.
 *
 * Covers the pure data builders — coverage_matrix() status resolution
 * (published / draft / missing), quality_score() weighting — and the
 * read-only invariant that building the reports creates zero content.
 * Also guards that the removed SEO Reports page stays removed.
 *
 * @package Anchor\Tests
 */

class LocationsDashboardTest extends WP_UnitTestCase {

	/** @var \Anchor\Locations\Dashboard */
	private $dash;

	public function set_up() {
		parent::set_up();
		$this->dash = new \Anchor\Locations\Dashboard();
	}

	private function make_location( $title = 'Pittsburgh', array $meta = [] ) {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => $title ] );
		foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
		return $id;
	}

	/** Create a `service` term, returning its term_id. */
	private function make_service_term( $name ) {
		$t = wp_insert_term( $name, 'service' );
		return is_wp_error( $t ) ? (int) get_term_by( 'name', $name, 'service' )->term_id : (int) $t['term_id'];
	}

	/** Create a service page linked to $loc_id + tagged $term_id. */
	private function make_service_page( $loc_id, $term_id, $status = 'publish', array $meta = [] ) {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => $status, 'post_title' => 'Service Page' ] );
		update_post_meta( $id, 'al_location_id', $loc_id );
		if ( $term_id ) { wp_set_object_terms( $id, [ (int) $term_id ], 'service' ); }
		foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
		return $id;
	}

	/* ---- A. coverage_matrix() ---- */

	public function test_coverage_matrix_statuses() {
		$loc     = $this->make_location( 'Pittsburgh' );
		$roofing = $this->make_service_term( 'Roofing' );
		$gutters = $this->make_service_term( 'Gutters' );
		$siding  = $this->make_service_term( 'Siding' );

		$this->make_service_page( $loc, $roofing, 'publish' );
		$this->make_service_page( $loc, $gutters, 'draft' );
		// No siding page at all.

		$m = $this->dash->coverage_matrix();

		$this->assertArrayHasKey( $loc, $m );
		$this->assertSame( 'published', $m[ $loc ][ $roofing ]['status'] );
		$this->assertSame( 'draft', $m[ $loc ][ $gutters ]['status'] );
		$this->assertSame( 'missing', $m[ $loc ][ $siding ]['status'] );

		// Missing cell routes to the pre-filled Add New screen (no create).
		$add = $m[ $loc ][ $siding ]['add'];
		$this->assertStringContainsString( 'post-new.php', $add );
		$this->assertStringContainsString( 'al_prefill_location=' . $loc, $add );
		$this->assertStringContainsString( 'al_prefill_service=' . $siding, $add );

		// Published cell exposes a front-end view URL and an edit URL.
		$this->assertNotSame( '', $m[ $loc ][ $roofing ]['view'] );
		$this->assertStringContainsString( 'action=edit', $m[ $loc ][ $roofing ]['edit'] );
	}

	public function test_coverage_matrix_ignores_stale_seo_meta() {
		// A pre-simplification site may still carry stale al_robots_noindex meta
		// (the SEO metabox that wrote it is gone). It must no longer suppress
		// the published status of the matrix cell.
		$loc     = $this->make_location( 'Cleveland' );
		$roofing = $this->make_service_term( 'Roofing' );
		$this->make_service_page( $loc, $roofing, 'publish', [ 'al_robots_noindex' => '1' ] );

		$m = $this->dash->coverage_matrix();
		$this->assertSame( 'published', $m[ $loc ][ $roofing ]['status'] );
	}

	public function test_coverage_matrix_type_filter() {
		$city   = $this->make_location( 'Akron', [ 'al_type' => 'city' ] );
		$county = $this->make_location( 'Summit County', [ 'al_type' => 'county' ] );
		$this->make_service_term( 'Roofing' );

		$m = $this->dash->coverage_matrix( [ 'type' => 'city' ] );
		$this->assertArrayHasKey( $city, $m );
		$this->assertArrayNotHasKey( $county, $m );
	}

	/* ---- B. quality_score() ---- */

	public function test_quality_score_full_page_is_100() {
		$loc  = $this->make_location( 'Columbus', [ 'al_lat' => '39.96', 'al_lng' => '-83.0' ] );
		$term = $this->make_service_term( 'Roofing' );
		$body = str_repeat( 'Quality roofing content for Columbus homeowners. ', 20 ); // > 300 chars
		$body .= '[anchor_location_services]';
		$page = $this->make_service_page( $loc, $term, 'publish', [ 'al_html' => $body ] );

		// body>=300 (40) + coords via location (30) + internal shortcode (30) = 100.
		$this->assertSame( 100, $this->dash->quality_score( $page ) );
	}

	public function test_quality_score_empty_draft_is_low() {
		$page = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'draft' ] );
		$this->assertLessThanOrEqual( 10, $this->dash->quality_score( $page ) );
	}

	public function test_quality_score_partial_without_linking_shortcode() {
		$loc  = $this->make_location( 'Toledo', [ 'al_lat' => '41.6', 'al_lng' => '-83.5' ] );
		$term = $this->make_service_term( 'Gutters' );
		$page = $this->make_service_page( $loc, $term, 'publish', [
			'al_html' => str_repeat( 'x', 300 ), // long enough, no internal-linking shortcode
		] );
		// body>=300 (40) + coords via location (30) = 70; no internal-linking shortcode (0).
		$this->assertSame( 70, $this->dash->quality_score( $page ) );
	}

	/* ---- C. read-only invariant ---- */

	public function test_builders_create_no_content() {
		$loc  = $this->make_location( 'Erie', [ 'al_lat' => '42.1', 'al_lng' => '-80.0' ] );
		$term = $this->make_service_term( 'Roofing' );
		$this->make_service_page( $loc, $term, 'publish', [ 'al_html' => str_repeat( 'c', 400 ) ] );

		$count = function () {
			return array_sum( (array) wp_count_posts( 'anchor_service_page' ) ) + array_sum( (array) wp_count_posts( 'anchor_location' ) );
		};
		$before = $count();
		$this->dash->coverage_matrix();
		$this->dash->quality_score( $loc );
		$this->assertSame( $before, $count(), 'Building reports must not create or delete any posts.' );
	}

	/* ---- E. SEO Reports removal guard ---- */

	public function test_seo_reports_removed() {
		$dash = new \Anchor\Locations\Dashboard();
		$this->assertFalse( method_exists( $dash, 'seo_issues' ), 'SEO Reports builder should be gone.' );
		$this->assertFalse( method_exists( $dash, 'render_seo_page' ), 'SEO Reports page should be gone.' );
	}
}
