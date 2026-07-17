<?php
/**
 * Tests for anchor-locations Phase 8: Hardening.
 *
 * Covers the pure data-integrity predicates (slug collision, orphan, duplicate
 * combination, missing coords) and the versioned transient caching for
 * map_data() + the directory shortcode (identical results, served-from-transient,
 * and version-bump invalidation).
 *
 * @package Anchor\Tests
 */

class LocationsIntegrityTest extends WP_UnitTestCase {

	/** @var \Anchor\Locations\Integrity */
	private $integ;
	/** @var \Anchor\Locations\Module */
	private $mod;

	public function set_up() {
		parent::set_up();
		$this->integ = new \Anchor\Locations\Integrity();
		$this->mod   = new \Anchor\Locations\Module();
	}

	private function make_location( $args = [], array $meta = [] ) {
		$id = self::factory()->post->create( array_merge(
			[ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Loc' ],
			$args
		) );
		foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
		return $id;
	}

	/** Force a post_name directly, bypassing wp_unique_post_slug — simulates an external/DB import collision. */
	private function force_slug( $post_id, $slug ) {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_name' => $slug ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
	}

	private function make_term( $name ) {
		$t = wp_insert_term( $name, 'service' );
		return is_wp_error( $t ) ? (int) get_term_by( 'name', $name, 'service' )->term_id : (int) $t['term_id'];
	}

	private function make_service_page( $loc_id, $term_id, $status = 'publish' ) {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => $status, 'post_title' => 'Service' ] );
		update_post_meta( $id, 'al_location_id', $loc_id );
		if ( $term_id ) { wp_set_object_terms( $id, [ (int) $term_id ], 'service' ); }
		return $id;
	}

	/* ---- A. Slug collision ---- */

	public function test_slug_collision_returns_other_published_id() {
		$a = $this->make_location( [ 'post_name' => 'pittsburgh-pa' ] );
		$b = $this->make_location();
		$this->force_slug( $b, 'pittsburgh-pa' );

		$this->assertSame( $b, $this->integ->location_slug_collision( $a ) );
		$this->assertSame( $a, $this->integ->location_slug_collision( $b ) );
	}

	public function test_slug_collision_zero_when_unique() {
		$a = $this->make_location( [ 'post_name' => 'unique-slug-pa' ] );
		$this->assertSame( 0, $this->integ->location_slug_collision( $a ) );
	}

	public function test_slug_collision_ignores_draft_partner() {
		$a = $this->make_location( [ 'post_name' => 'shared-slug' ] );
		$b = $this->make_location( [ 'post_status' => 'draft' ] );
		$this->force_slug( $b, 'shared-slug' );
		// The draft twin is not routable, so it is not a collision.
		$this->assertSame( 0, $this->integ->location_slug_collision( $a ) );
	}

	public function test_slug_collision_ignores_self() {
		$a = $this->make_location( [ 'post_name' => 'solo-pa' ] );
		$this->assertSame( 0, $this->integ->location_slug_collision( $a ) );
	}

	/* ---- B. Data-quality predicates ---- */

	public function test_duplicate_combo_flags_second_page() {
		$loc  = $this->make_location();
		$term = $this->make_term( 'Roofing' );
		$spA  = $this->make_service_page( $loc, $term );
		$spB  = $this->make_service_page( $loc, $term );

		$this->assertSame( $spB, $this->integ->service_duplicate_combo( $spA ) );
		$this->assertSame( $spA, $this->integ->service_duplicate_combo( $spB ) );
	}

	public function test_duplicate_combo_zero_for_unique() {
		$loc   = $this->make_location();
		$roof  = $this->make_term( 'Roofing' );
		$gutt  = $this->make_term( 'Gutters' );
		$only  = $this->make_service_page( $loc, $roof );
		$other = $this->make_service_page( $loc, $gutt ); // same loc, different term

		$this->assertSame( 0, $this->integ->service_duplicate_combo( $only ) );
		$this->assertSame( 0, $this->integ->service_duplicate_combo( $other ) );
	}

	public function test_service_orphan_detection() {
		$loc   = $this->make_location();
		$term  = $this->make_term( 'Roofing' );
		$valid = $this->make_service_page( $loc, $term );
		$this->assertFalse( $this->integ->service_orphan( $valid ) );

		$missing = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish' ] );
		$this->assertTrue( $this->integ->service_orphan( $missing ) );

		$bad = $this->make_service_page( 999999, $term ); // points nowhere
		$this->assertTrue( $this->integ->service_orphan( $bad ) );

		$draft_loc = $this->make_location( [ 'post_status' => 'draft' ] );
		$to_draft  = $this->make_service_page( $draft_loc, $term );
		$this->assertTrue( $this->integ->service_orphan( $to_draft ) );
	}

	public function test_location_missing_coords() {
		$with = $this->make_location( [], [ 'al_lat' => '40.4', 'al_lng' => '-79.9' ] );
		$this->assertFalse( $this->integ->location_missing_coords( $with ) );

		$without = $this->make_location();
		$this->assertTrue( $this->integ->location_missing_coords( $without ) );
	}

	/* ---- C. Cache versioning ---- */

	public function test_cache_version_bumps_on_location_save() {
		$before = \Anchor\Locations\Integrity::cache_version();
		$this->make_location();
		$after = \Anchor\Locations\Integrity::cache_version();
		$this->assertGreaterThan( $before, $after );
	}

	public function test_map_data_identical_and_served_from_transient() {
		$loc = $this->make_location( [ 'post_title' => 'CacheCity' ], [ 'al_lat' => '40.1', 'al_lng' => '-79.1', 'al_type' => 'city' ] );

		// First call computes + caches; second call must equal it (behavior-preserving).
		$r1 = $this->mod->map_data();
		$r2 = $this->mod->map_data();
		$this->assertEquals( $r1, $r2 );
		$this->assertContains( 'CacheCity', wp_list_pluck( $r1, 'title' ) );

		// Prove the second read comes from the transient: poison it and confirm the
		// poisoned value flows straight back out (no recompute) at the same version.
		$key = $this->mod->map_cache_key( [] );
		$this->assertNotFalse( get_transient( $key ), 'map_data() should have populated its transient.' );
		set_transient( $key, [ [ 'title' => 'FROM_CACHE' ] ], HOUR_IN_SECONDS );
		$poisoned = $this->mod->map_data();
		$this->assertSame( 'FROM_CACHE', $poisoned[0]['title'] );
	}

	public function test_map_data_cache_invalidated_by_save_post() {
		$loc = $this->make_location( [ 'post_title' => 'FirstCity' ], [ 'al_lat' => '40.1', 'al_lng' => '-79.1', 'al_type' => 'city' ] );
		$this->mod->map_data(); // populate cache at current version
		// Poison the current-version entry so a stale read would be visible.
		set_transient( $this->mod->map_cache_key( [] ), [ [ 'title' => 'STALE' ] ], HOUR_IN_SECONDS );

		// A new location save bumps the version -> new key -> recompute.
		$this->make_location( [ 'post_title' => 'SecondCity' ], [ 'al_lat' => '40.2', 'al_lng' => '-79.2', 'al_type' => 'city' ] );

		$titles = wp_list_pluck( $this->mod->map_data(), 'title' );
		$this->assertNotContains( 'STALE', $titles, 'Bumped version must not serve the old poisoned entry.' );
		$this->assertContains( 'FirstCity', $titles );
		$this->assertContains( 'SecondCity', $titles );
	}

	public function test_directory_cache_served_and_invalidated() {
		$this->make_location( [ 'post_title' => 'RootCity', 'post_parent' => 0 ] );

		$out1 = $this->mod->sc_directory( [] );
		$out2 = $this->mod->sc_directory( [] );
		$this->assertSame( $out1, $out2 );
		$this->assertStringContainsString( 'RootCity', $out1 );

		// Poison proves the cached tree is what gets returned.
		$key = $this->mod->directory_cache_key( [] );
		$this->assertNotFalse( get_transient( $key ) );
		set_transient( $key, '<div class="al-directory">POISONED_DIR</div>', HOUR_IN_SECONDS );
		$this->assertStringContainsString( 'POISONED_DIR', $this->mod->sc_directory( [] ) );

		// A new root location bumps the version -> recompute, poison gone.
		$this->make_location( [ 'post_title' => 'SecondRoot', 'post_parent' => 0 ] );
		$out3 = $this->mod->sc_directory( [] );
		$this->assertStringNotContainsString( 'POISONED_DIR', $out3 );
		$this->assertStringContainsString( 'SecondRoot', $out3 );
		$this->assertStringContainsString( 'RootCity', $out3 );
	}

	public function test_cache_bypassed_when_version_absent() {
		// With the version option deleted, cache_version() is 0 and map_data() must
		// compute uncached (no transient written) — the pre-Phase-8 behavior.
		delete_option( \Anchor\Locations\Integrity::CACHE_VER_OPTION );
		$loc = $this->make_location_no_bump( [ 'post_title' => 'BypassCity' ], [ 'al_lat' => '40.9', 'al_lng' => '-79.9', 'al_type' => 'city' ] );
		delete_option( \Anchor\Locations\Integrity::CACHE_VER_OPTION ); // ensure absent after the save bump

		$this->assertSame( 0, \Anchor\Locations\Integrity::cache_version() );
		$titles = wp_list_pluck( $this->mod->map_data(), 'title' );
		$this->assertContains( 'BypassCity', $titles );
		$this->assertFalse( get_transient( $this->mod->map_cache_key( [] ) ) );
	}

	/** Create a location without letting a residual bump matter (option deleted right after). */
	private function make_location_no_bump( $args = [], array $meta = [] ) {
		return $this->make_location( $args, $meta );
	}
}
