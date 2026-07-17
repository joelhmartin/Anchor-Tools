<?php
/**
 * Tests for anchor-locations Phase 5: Coverage Matrix + SEO Quality Dashboard.
 *
 * Covers the pure data builders — coverage_matrix() status resolution
 * (published / draft / missing / noindex), quality_score() weighting,
 * seo_issues() detection (thin content, missing coords, orphan, duplicate) — and
 * the read-only invariant that building the reports creates zero content.
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

	public function test_coverage_matrix_noindex() {
		$loc     = $this->make_location( 'Cleveland' );
		$roofing = $this->make_service_term( 'Roofing' );
		$this->make_service_page( $loc, $roofing, 'publish', [ 'al_robots_noindex' => '1' ] );

		$m = $this->dash->coverage_matrix();
		$this->assertSame( 'noindex', $m[ $loc ][ $roofing ]['status'] );
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
		$page = $this->make_service_page( $loc, $term, 'publish', [
			'al_html'      => $body,
			'al_seo_title' => 'Roofing in Columbus',
			'al_seo_desc'  => 'The best roofing in Columbus.',
			'al_h1'        => 'Roofing in Columbus',
		] );

		$this->assertSame( 100, $this->dash->quality_score( $page ) );
	}

	public function test_quality_score_empty_draft_is_low() {
		$page = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'draft' ] );
		$this->assertLessThanOrEqual( 10, $this->dash->quality_score( $page ) );
	}

	public function test_quality_score_h1_detected_in_html() {
		$loc  = $this->make_location( 'Toledo', [ 'al_lat' => '41.6', 'al_lng' => '-83.5' ] );
		$term = $this->make_service_term( 'Gutters' );
		$page = $this->make_service_page( $loc, $term, 'publish', [
			'al_html' => '<h1>Gutters in Toledo</h1>' . str_repeat( 'x', 300 ) . '[anchor_breadcrumbs]',
		] );
		// H1 (10) + body>=300 (20) + coords via location (15) + internal shortcode (15) + not noindex (10) = 70.
		$this->assertSame( 70, $this->dash->quality_score( $page ) );
	}

	/* ---- C. seo_issues() ---- */

	public function test_seo_issues_detects_thin_missing_coords_orphan_duplicate() {
		// Thin content page (published, tiny body) + missing SEO + missing H1.
		$loc_ok = $this->make_location( 'Dayton', [ 'al_lat' => '39.7', 'al_lng' => '-84.1' ] );
		$roof   = $this->make_service_term( 'Roofing' );
		$thin   = $this->make_service_page( $loc_ok, $roof, 'publish', [ 'al_html' => 'Too short.' ] );

		// Location missing coordinates.
		$loc_nocoords = $this->make_location( 'Marion' ); // no al_lat/al_lng

		// Orphan service page — al_location_id points nowhere.
		$orphan = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish' ] );
		update_post_meta( $orphan, 'al_location_id', 999999 );
		wp_set_object_terms( $orphan, [ $roof ], 'service' );

		// Duplicate combination — two service pages, same term + same location.
		$dupA = $this->make_service_page( $loc_ok, $roof, 'publish', [ 'al_html' => str_repeat( 'a', 400 ) ] );
		$dupB = $this->make_service_page( $loc_ok, $roof, 'publish', [ 'al_html' => str_repeat( 'b', 400 ) ] );

		$issues = $this->dash->seo_issues();

		$this->assertArrayHasKey( 'high', $issues );
		$types_high = wp_list_pluck( $issues['high'], 'type' );
		$this->assertContains( 'thin_content', $types_high );
		$this->assertContains( 'missing_coords', $types_high );
		$this->assertContains( 'orphan_service', $types_high );
		$this->assertContains( 'duplicate_combo', $types_high );

		// Thin page appears in the thin_content post list.
		$thin_issue = $this->issue_by_type( $issues['high'], 'thin_content' );
		$this->assertContains( $thin, wp_list_pluck( $thin_issue['posts'], 'id' ) );

		// Missing-coords location appears.
		$mc = $this->issue_by_type( $issues['high'], 'missing_coords' );
		$this->assertContains( $loc_nocoords, wp_list_pluck( $mc['posts'], 'id' ) );

		// Orphan appears.
		$orph = $this->issue_by_type( $issues['high'], 'orphan_service' );
		$this->assertContains( $orphan, wp_list_pluck( $orph['posts'], 'id' ) );

		// Medium bucket carries missing-seo issues for the thin page.
		$this->assertArrayHasKey( 'medium', $issues );
		$types_med = wp_list_pluck( $issues['medium'], 'type' );
		$this->assertContains( 'missing_seo_title', $types_med );
		$this->assertContains( 'missing_seo_desc', $types_med );
	}

	private function issue_by_type( array $bucket, $type ) {
		foreach ( $bucket as $i ) { if ( $i['type'] === $type ) { return $i; } }
		return [ 'posts' => [] ];
	}

	/* ---- D. read-only invariant ---- */

	public function test_builders_create_no_content() {
		$loc  = $this->make_location( 'Erie', [ 'al_lat' => '42.1', 'al_lng' => '-80.0' ] );
		$term = $this->make_service_term( 'Roofing' );
		$this->make_service_page( $loc, $term, 'publish', [ 'al_html' => str_repeat( 'c', 400 ) ] );

		$count = function () {
			return array_sum( (array) wp_count_posts( 'anchor_service_page' ) ) + array_sum( (array) wp_count_posts( 'anchor_location' ) );
		};
		$before = $count();
		$this->dash->coverage_matrix();
		$this->dash->seo_issues();
		$this->dash->quality_score( $loc );
		$this->assertSame( $before, $count(), 'Building reports must not create or delete any posts.' );
	}
}
