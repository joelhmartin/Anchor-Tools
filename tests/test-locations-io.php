<?php
/**
 * Tests for anchor-locations Phase 6: Import / Export (\Anchor\Locations\IO).
 *
 * Covers the JSON round-trip (hierarchy + location/service meta incl. the
 * content-section keys + service-term linkage), idempotency, dry-run writing
 * nothing, CSV formula-injection guard, the never-delete invariant,
 * upsert-by-slug, malformed-row isolation, and CSV scalar round-trip.
 *
 * @package Anchor\Tests
 */

class LocationsIoTest extends WP_UnitTestCase {

	/** @var \Anchor\Locations\IO */
	private $io;

	public function set_up() {
		parent::set_up();
		$this->io = new \Anchor\Locations\IO();
	}

	/* ---- helpers ---- */

	private function make_location( $title, $slug, array $meta = [], $parent = 0 ) {
		$id = self::factory()->post->create( [
			'post_type'   => 'anchor_location',
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_name'   => $slug,
			'post_parent' => $parent,
		] );
		foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
		return $id;
	}

	private function make_service_term( $name, $slug = '' ) {
		$args = $slug ? [ 'slug' => $slug ] : [];
		$t = wp_insert_term( $name, 'service', $args );
		return is_wp_error( $t ) ? (int) get_term_by( 'name', $name, 'service' )->term_id : (int) $t['term_id'];
	}

	private function make_service_page( $title, $slug, $loc_id, $term_id, array $meta = [] ) {
		$id = self::factory()->post->create( [
			'post_type'   => 'anchor_service_page',
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_name'   => $slug,
		] );
		update_post_meta( $id, 'al_location_id', $loc_id );
		if ( $term_id ) { wp_set_object_terms( $id, [ (int) $term_id ], 'service' ); }
		foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
		return $id;
	}

	private function by_slug( $type, $slug ) {
		$ids = get_posts( [ 'post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ] );
		return $ids ? (int) $ids[0] : 0;
	}

	private function count_all() {
		$n = 0;
		foreach ( [ 'anchor_location', 'anchor_service_page' ] as $t ) {
			$n += array_sum( (array) wp_count_posts( $t ) );
		}
		return $n;
	}

	/** Build the canonical fixture; return the created ids. */
	private function seed_fixture() {
		$county = $this->make_location( 'Allegheny County', 'allegheny-county-pa', [ 'al_type' => 'county', 'al_lat' => '40.44', 'al_lng' => '-79.99', 'al_faq_html' => '<div>Allegheny FAQ</div>' ] );
		$city   = $this->make_location( 'Pittsburgh', 'pittsburgh-pa', [ 'al_type' => 'city', 'al_html' => str_repeat( 'Roofing content. ', 30 ) ], $county );
		$term   = $this->make_service_term( 'Roofing', 'roofing' );
		$sp     = $this->make_service_page( 'Roofing in Pittsburgh', 'roofing-pittsburgh-pa', $city, $term, [ 'al_testimonials_html' => '<blockquote>Roofing PGH testimonial</blockquote>' ] );
		return compact( 'county', 'city', 'term', 'sp' );
	}

	private function wipe( $ids ) {
		wp_delete_post( $ids['sp'], true );
		wp_delete_post( $ids['city'], true );
		wp_delete_post( $ids['county'], true );
		wp_delete_term( $ids['term'], 'service' );
	}

	/* ---- 1. Round trip ---- */

	public function test_round_trip_reconstructs_hierarchy_and_links() {
		$ids  = $this->seed_fixture();
		$data = $this->io->export_json();

		$this->assertSame( 'anchor-locations', $data['format'] );
		$this->assertSame( 1, $data['version'] );

		$this->wipe( $ids );
		$this->assertSame( 0, $this->by_slug( 'anchor_location', 'pittsburgh-pa' ) );

		$summary = $this->io->import_json( $data );
		$this->assertSame( [], $summary['errors'] );

		$county = $this->by_slug( 'anchor_location', 'allegheny-county-pa' );
		$city   = $this->by_slug( 'anchor_location', 'pittsburgh-pa' );
		$this->assertGreaterThan( 0, $county );
		$this->assertGreaterThan( 0, $city );
		$this->assertSame( $county, (int) get_post( $city )->post_parent );
		$this->assertSame( 'county', get_post_meta( $county, 'al_type', true ) );
		$this->assertSame( '<div>Allegheny FAQ</div>', get_post_meta( $county, 'al_faq_html', true ) );

		$sp = $this->by_slug( 'anchor_service_page', 'roofing-pittsburgh-pa' );
		$this->assertGreaterThan( 0, $sp );
		$this->assertSame( $city, (int) get_post_meta( $sp, 'al_location_id', true ) );
		$slugs = wp_get_object_terms( $sp, 'service', [ 'fields' => 'slugs' ] );
		$this->assertContains( 'roofing', $slugs );
		$this->assertSame( '<blockquote>Roofing PGH testimonial</blockquote>', get_post_meta( $sp, 'al_testimonials_html', true ) );
	}

	/* ---- 2. Idempotency ---- */

	public function test_import_is_idempotent() {
		$ids  = $this->seed_fixture();
		$data = $this->io->export_json();
		$this->wipe( $ids );

		$s1 = $this->io->import_json( $data );
		$this->assertGreaterThan( 0, $s1['created'] );

		$before = $this->count_all();
		$s2 = $this->io->import_json( $data );
		$after = $this->count_all();

		$this->assertSame( 0, $s2['created'], 'Re-import must not create duplicates.' );
		$this->assertGreaterThan( 0, $s2['updated'] );
		$this->assertSame( $before, $after, 'Re-import must keep post counts stable.' );
	}

	/* ---- 3. Dry run ---- */

	public function test_dry_run_writes_nothing() {
		$ids  = $this->seed_fixture();
		$data = $this->io->export_json();
		$this->wipe( $ids );

		$before  = $this->count_all();
		$summary = $this->io->import_json( $data, [ 'dry_run' => true ] );
		$after   = $this->count_all();

		$this->assertSame( $before, $after, 'Dry run must not write any posts.' );
		$this->assertGreaterThan( 0, $summary['created'], 'Dry run still reports what it would create.' );
		$this->assertSame( 0, $this->by_slug( 'anchor_location', 'pittsburgh-pa' ) );
	}

	/* ---- 4. CSV formula-injection guard ---- */

	public function test_csv_export_escapes_formula_injection() {
		$this->make_location( '=SUM(A1)', 'danger-pa', [ 'al_type' => 'city' ] );
		$csv = $this->io->export_locations_csv();
		$this->assertStringContainsString( "'=SUM(A1)", $csv, 'A leading = must be prefixed with a quote.' );
		$this->assertStringNotContainsString( ',=SUM(A1)', $csv );
	}

	/* ---- 5. Never delete ---- */

	public function test_import_never_deletes_omitted_items() {
		$keep = $this->make_location( 'Keep Me', 'keep-pa', [ 'al_type' => 'city' ] );
		$data = [
			'format'   => 'anchor-locations',
			'version'  => 1,
			'locations' => [
				[ 'title' => 'Other', 'slug' => 'other-pa', 'status' => 'publish', 'parent_slug' => '', 'meta' => [] ],
			],
		];
		$this->io->import_json( $data );
		$this->assertSame( $keep, $this->by_slug( 'anchor_location', 'keep-pa' ), 'Omitted location must survive import.' );
		$this->assertGreaterThan( 0, $this->by_slug( 'anchor_location', 'other-pa' ) );
	}

	/* ---- 6. Malformed row isolation ---- */

	public function test_bad_service_page_row_recorded_not_fatal() {
		$data = [
			'format'   => 'anchor-locations',
			'version'  => 1,
			'locations' => [
				[ 'title' => 'Good Loc', 'slug' => 'good-pa', 'status' => 'publish', 'parent_slug' => '', 'meta' => [] ],
			],
			'service_pages' => [
				[ 'title' => 'Bad SP', 'slug' => 'bad-sp', 'status' => 'publish', 'location_slug' => 'nonexistent-xyz', 'service_slugs' => [], 'meta' => [] ],
				[ 'title' => 'Good SP', 'slug' => 'good-sp', 'status' => 'publish', 'location_slug' => 'good-pa', 'service_slugs' => [], 'meta' => [] ],
			],
		];
		$summary = $this->io->import_json( $data );

		$this->assertNotEmpty( $summary['errors'], 'Unknown location_slug must be recorded.' );
		$this->assertGreaterThanOrEqual( 1, $summary['skipped'] );
		$this->assertGreaterThan( 0, $this->by_slug( 'anchor_service_page', 'good-sp' ), 'Valid row still imports.' );
		$this->assertSame( 0, $this->by_slug( 'anchor_service_page', 'bad-sp' ), 'Bad row not created.' );
	}

	/* ---- 8. Cache-version single-bump (Fix B) ---- */

	/**
	 * A real import performs N posts × M meta writes, each of which would bump the
	 * versioned map/directory cache on its own. With a live Integrity instance
	 * (so those save_post / meta / term hooks are active), the whole import must
	 * collapse to exactly ONE bump — invalidating the cache once, not N times.
	 */
	public function test_import_bumps_cache_version_once() {
		$integrity = new \Anchor\Locations\Integrity();

		$ids  = $this->seed_fixture();
		$data = $this->io->export_json();
		$this->wipe( $ids );

		$before  = \Anchor\Locations\Integrity::cache_version();
		$summary = $this->io->import_json( $data );
		$after   = \Anchor\Locations\Integrity::cache_version();

		$this->assertSame( [], $summary['errors'] );
		$this->assertGreaterThan( 0, $summary['created'] );
		$this->assertSame( $before + 1, $after, 'A real import must bump the cache version exactly once (not once per meta write).' );

		unset( $integrity );
	}

	/** A dry run writes nothing, so it must not bump the cache version at all. */
	public function test_dry_run_does_not_bump_cache_version() {
		$integrity = new \Anchor\Locations\Integrity();

		$ids  = $this->seed_fixture();
		$data = $this->io->export_json();
		$this->wipe( $ids );

		$before = \Anchor\Locations\Integrity::cache_version();
		$this->io->import_json( $data, [ 'dry_run' => true ] );
		$after  = \Anchor\Locations\Integrity::cache_version();

		$this->assertSame( $before, $after, 'Dry run writes nothing and must not bump the cache version.' );

		unset( $integrity );
	}

	/* ---- 7. CSV scalar round-trip ---- */

	public function test_csv_locations_round_trip() {
		$loc = $this->make_location( 'Butler', 'butler-pa', [ 'al_type' => 'county', 'al_state_abbr' => 'PA' ] );
		$csv = $this->io->export_locations_csv();
		wp_delete_post( $loc, true );
		$this->assertSame( 0, $this->by_slug( 'anchor_location', 'butler-pa' ) );

		$summary = $this->io->import_csv( $csv );
		$this->assertSame( [], $summary['errors'] );

		$id = $this->by_slug( 'anchor_location', 'butler-pa' );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'county', get_post_meta( $id, 'al_type', true ) );
		$this->assertSame( 'PA', get_post_meta( $id, 'al_state_abbr', true ) );
	}

	public function test_csv_service_pages_round_trip() {
		$loc  = $this->make_location( 'Erie', 'erie-pa', [ 'al_type' => 'city' ] );
		$term = $this->make_service_term( 'Gutters', 'gutters' );
		$sp   = $this->make_service_page( 'Gutters in Erie', 'gutters-erie-pa', $loc, $term, [ 'al_disable_wrapper' => '1' ] );

		$csv = $this->io->export_service_pages_csv();
		wp_delete_post( $sp, true );
		$this->assertSame( 0, $this->by_slug( 'anchor_service_page', 'gutters-erie-pa' ) );

		$summary = $this->io->import_csv( $csv );
		$this->assertSame( [], $summary['errors'] );

		$id = $this->by_slug( 'anchor_service_page', 'gutters-erie-pa' );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( $loc, (int) get_post_meta( $id, 'al_location_id', true ) );
		$slugs = wp_get_object_terms( $id, 'service', [ 'fields' => 'slugs' ] );
		$this->assertContains( 'gutters', $slugs );
		$this->assertSame( '1', get_post_meta( $id, 'al_disable_wrapper', true ) );
	}

	/* ---- 9. Content-section keys round trip; SEO + library keys dropped ---- */

	public function test_section_keys_round_trip_and_seo_keys_dropped() {
		$io  = new \Anchor\Locations\IO();
		$loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Greenville' ] );
		update_post_meta( $loc, 'al_faq_html', '<div class="q">How much?</div>' );
		update_post_meta( $loc, 'al_testimonials_html', '<blockquote>Great</blockquote>' );
		update_post_meta( $loc, 'al_projects_html', '<div>Driveway</div>' );
		update_post_meta( $loc, 'al_h1', 'legacy value' ); // must NOT export anymore

		$data = $io->export_json();
		$json = wp_json_encode( $data );
		$this->assertStringContainsString( 'al_faq_html', $json );
		$this->assertStringContainsString( 'al_testimonials_html', $json );
		$this->assertStringContainsString( 'al_projects_html', $json );
		$this->assertStringNotContainsString( 'al_h1', $json );
		$this->assertStringNotContainsString( 'al_seo_title', $json );
		$this->assertStringNotContainsString( '"projects"', $json );
		$this->assertStringNotContainsString( '"faqs"', $json );

		// Re-import into a fresh post via a second slug and confirm sections restore.
		$data['locations'][0]['slug']  = 'greenville-2';
		$data['locations'][0]['title'] = 'Greenville 2';
		$summary = $io->import_json( $data );
		$this->assertSame( [], $summary['errors'] );

		$new_id = $this->by_slug( 'anchor_location', 'greenville-2' );
		$this->assertGreaterThan( 0, $new_id );
		$this->assertSame( '<div class="q">How much?</div>', get_post_meta( $new_id, 'al_faq_html', true ) );
		$this->assertSame( '<blockquote>Great</blockquote>', get_post_meta( $new_id, 'al_testimonials_html', true ) );
		$this->assertSame( '<div>Driveway</div>', get_post_meta( $new_id, 'al_projects_html', true ) );
		$this->assertSame( '', get_post_meta( $new_id, 'al_h1', true ) );
	}
}
