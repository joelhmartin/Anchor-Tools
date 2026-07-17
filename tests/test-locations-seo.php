<?php
/**
 * Tests for anchor-locations Phase 4: SEO controls + schema.
 *
 * Covers per-page SEO meta save/sanitize, the wp_robots callback, own-output
 * (no SEO plugin) title/meta, Yoast/RankMath feed callbacks, core sitemap
 * exclusion, the al_h1 shortcode + breadcrumb_title override, Review /
 * AggregateRating JSON-LD from rated testimonials, and the fullwidth template
 * wiring.
 *
 * @package Anchor\Tests
 */

class LocationsSeoTest extends WP_UnitTestCase {

	/** @var \Anchor\Locations\SEO */
	private $seo;
	/** @var \Anchor\Locations\Libraries */
	private $lib;

	public function set_up() {
		parent::set_up();
		$this->seo = new \Anchor\Locations\SEO();
		$this->lib = new \Anchor\Locations\Libraries();
	}

	private function make_location( $title = 'Pittsburgh' ) {
		return self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => $title ] );
	}

	private function capture_footer() {
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
		ob_start();
		do_action( 'wp_footer' );
		return ob_get_clean();
	}

	/* ---- A. meta save / sanitize ---- */

	public function test_save_seo_meta_round_trip_and_sanitize() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$id = $this->make_location();

		$_POST = [
			\Anchor\Locations\SEO::NONCE => wp_create_nonce( \Anchor\Locations\SEO::NONCE ),
			'al_seo_title'        => '  Best Roofing | Pittsburgh  ',
			'al_seo_desc'         => "Line one\nLine two <script>evil()</script>",
			'al_canonical'        => 'https://example.com/roofing/',
			'al_robots_noindex'   => '1',
			'al_robots_nofollow'  => '',
			'al_og_title'         => 'OG Roofing',
			'al_og_desc'          => 'OG description',
			'al_og_image'         => 'https://example.com/og.jpg',
			'al_breadcrumb_title' => 'Roofing',
			'al_h1'               => 'Roofing in Pittsburgh',
			'al_sitemap_exclude'  => '1',
		];
		$this->seo->save_seo_meta( $id );

		$this->assertSame( 'Best Roofing | Pittsburgh', get_post_meta( $id, 'al_seo_title', true ) );
		$this->assertStringNotContainsString( '<script>', get_post_meta( $id, 'al_seo_desc', true ) );
		$this->assertSame( 'https://example.com/roofing/', get_post_meta( $id, 'al_canonical', true ) );
		$this->assertSame( '1', get_post_meta( $id, 'al_robots_noindex', true ) );
		$this->assertSame( '', get_post_meta( $id, 'al_robots_nofollow', true ) );
		$this->assertSame( 'OG Roofing', get_post_meta( $id, 'al_og_title', true ) );
		$this->assertSame( 'OG description', get_post_meta( $id, 'al_og_desc', true ) );
		$this->assertSame( 'https://example.com/og.jpg', get_post_meta( $id, 'al_og_image', true ) );
		$this->assertSame( 'Roofing', get_post_meta( $id, 'al_breadcrumb_title', true ) );
		$this->assertSame( 'Roofing in Pittsburgh', get_post_meta( $id, 'al_h1', true ) );
		$this->assertSame( '1', get_post_meta( $id, 'al_sitemap_exclude', true ) );

		$_POST = [];
	}

	public function test_save_seo_meta_rejects_without_nonce() {
		$id = $this->make_location();
		$_POST = [ 'al_seo_title' => 'no nonce' ];
		$this->seo->save_seo_meta( $id );
		$this->assertSame( '', get_post_meta( $id, 'al_seo_title', true ) );
		$_POST = [];
	}

	/* ---- B. robots ---- */

	public function test_wp_robots_adds_noindex_nofollow_when_flagged() {
		$id = $this->make_location();
		update_post_meta( $id, 'al_robots_noindex', '1' );
		update_post_meta( $id, 'al_robots_nofollow', '1' );
		$this->go_to( get_permalink( $id ) );
		$this->assertTrue( is_singular() );

		$out = $this->seo->filter_robots( [ 'index' => true, 'follow' => true ] );
		$this->assertArrayHasKey( 'noindex', $out );
		$this->assertTrue( $out['noindex'] );
		$this->assertArrayHasKey( 'nofollow', $out );
		$this->assertArrayNotHasKey( 'index', $out );
		$this->assertArrayNotHasKey( 'follow', $out );
	}

	public function test_wp_robots_untouched_when_not_flagged() {
		$id = $this->make_location();
		$this->go_to( get_permalink( $id ) );
		$out = $this->seo->filter_robots( [ 'index' => true, 'follow' => true ] );
		$this->assertArrayNotHasKey( 'noindex', $out );
		$this->assertArrayNotHasKey( 'nofollow', $out );
	}

	/* ---- B. own output (no SEO plugin active) ---- */

	public function test_own_document_title_uses_al_seo_title() {
		$id = $this->make_location();
		update_post_meta( $id, 'al_seo_title', 'Custom Title' );
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( 'Custom Title', $this->seo->own_document_title( 'Theme Default' ) );
	}

	public function test_own_document_title_passthrough_when_unset() {
		$id = $this->make_location();
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( 'Theme Default', $this->seo->own_document_title( 'Theme Default' ) );
	}

	public function test_print_head_meta_emits_canonical_description_og() {
		$id = $this->make_location();
		update_post_meta( $id, 'al_seo_desc', 'The meta description.' );
		update_post_meta( $id, 'al_canonical', 'https://example.com/canon/' );
		update_post_meta( $id, 'al_og_title', 'OG Title Here' );
		update_post_meta( $id, 'al_og_image', 'https://example.com/img.jpg' );
		$this->go_to( get_permalink( $id ) );

		ob_start();
		$this->seo->print_head_meta();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="description"', $html );
		$this->assertStringContainsString( 'The meta description.', $html );
		$this->assertStringContainsString( 'rel="canonical"', $html );
		$this->assertStringContainsString( 'https://example.com/canon/', $html );
		$this->assertStringContainsString( 'og:title', $html );
		$this->assertStringContainsString( 'og:image', $html );
	}

	/* ---- B. SEO-plugin feed callbacks ---- */

	public function test_yoast_callbacks_override_only_when_field_set() {
		$id = $this->make_location();
		update_post_meta( $id, 'al_seo_title', 'Yoast Title' );
		update_post_meta( $id, 'al_canonical', 'https://example.com/c/' );
		$this->go_to( get_permalink( $id ) );

		$this->assertSame( 'Yoast Title', $this->seo->yoast_title( 'orig' ) );
		$this->assertSame( 'https://example.com/c/', $this->seo->yoast_canonical( 'orig' ) );
		// metadesc unset -> passthrough
		$this->assertSame( 'orig-desc', $this->seo->yoast_metadesc( 'orig-desc' ) );
	}

	public function test_rankmath_callbacks_override_only_when_field_set() {
		$id = $this->make_location();
		update_post_meta( $id, 'al_seo_desc', 'RM description' );
		$this->go_to( get_permalink( $id ) );

		$this->assertSame( 'RM description', $this->seo->rankmath_description( 'orig' ) );
		$this->assertSame( 'orig-title', $this->seo->rankmath_title( 'orig-title' ) );
	}

	/* ---- C. sitemap exclusion ---- */

	public function test_sitemap_query_excludes_flagged_posts() {
		$keep = $this->make_location( 'Keep' );
		$drop = $this->make_location( 'Drop' );
		update_post_meta( $drop, 'al_sitemap_exclude', '1' );

		$args = $this->seo->filter_sitemap_query( [], 'anchor_location' );
		$this->assertArrayHasKey( 'post__not_in', $args );
		$this->assertContains( $drop, $args['post__not_in'] );
		$this->assertNotContains( $keep, $args['post__not_in'] );
	}

	public function test_sitemap_query_untouched_for_other_post_types() {
		$args = $this->seo->filter_sitemap_query( [ 'x' => 1 ], 'post' );
		$this->assertArrayNotHasKey( 'post__not_in', $args );
	}

	/* ---- B. al_h1 shortcode + breadcrumb_title ---- */

	public function test_h1_shortcode_uses_meta_then_title() {
		$id = $this->make_location( 'Fallback Title' );
		$this->go_to( get_permalink( $id ) );
		$this->assertStringContainsString( 'Fallback Title', do_shortcode( '[anchor_h1 id="' . $id . '"]' ) );
		update_post_meta( $id, 'al_h1', 'Explicit H1' );
		$this->assertStringContainsString( 'Explicit H1', do_shortcode( '[anchor_h1 id="' . $id . '"]' ) );
	}

	public function test_breadcrumb_title_used_in_breadcrumbs_and_schema() {
		$mod  = new \Anchor\Locations\Module();
		$root = $this->make_location( 'Pennsylvania' );
		$leaf = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh City', 'post_parent' => $root ] );
		update_post_meta( $leaf, 'al_breadcrumb_title', 'Pittsburgh' );

		$crumbs = $mod->sc_breadcrumbs( [ 'id' => $leaf ] );
		$this->assertStringContainsString( 'Pittsburgh', $crumbs );
		$this->assertStringNotContainsString( 'Pittsburgh City', $crumbs );

		$graph = $mod->build_schema( $leaf );
		$bc = null;
		foreach ( $graph as $n ) { if ( ( $n['@type'] ?? '' ) === 'BreadcrumbList' ) { $bc = $n; } }
		$names = array_column( $bc['itemListElement'], 'name' );
		$this->assertContains( 'Pittsburgh', $names );
		$this->assertNotContains( 'Pittsburgh City', $names );
	}

	/* ---- D. Review / AggregateRating schema ---- */

	private function make_testimonial( $loc, $quote, $author, $rating ) {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial', 'post_status' => 'publish' ] );
		update_post_meta( $id, 'al_quote', $quote );
		update_post_meta( $id, 'al_author', $author );
		update_post_meta( $id, 'al_rating', $rating );
		update_post_meta( $id, 'al_location_ids', [ (int) $loc ] );
		return $id;
	}

	public function test_review_schema_emitted_for_rated_testimonials() {
		$loc = $this->make_location();
		$this->make_testimonial( $loc, 'Fantastic work.', 'Jane Doe', 5 );
		$this->make_testimonial( $loc, 'Very good.', 'John Roe', 4 );
		$this->go_to( get_permalink( $loc ) );

		$html = do_shortcode( '[anchor_local_testimonials id="' . $loc . '"]' );
		$this->assertStringContainsString( 'Jane Doe', $html );

		$schema = $this->capture_footer();
		$this->assertStringContainsString( 'AggregateRating', $schema );
		$this->assertStringContainsString( '"Review"', $schema );
		$this->assertStringContainsString( 'Jane Doe', $schema );
		$this->assertStringContainsString( '"reviewCount":2', $schema );
		$this->assertStringContainsString( '"ratingValue":4.5', $schema );
	}

	public function test_review_schema_absent_without_ratings() {
		$loc = $this->make_location();
		$this->make_testimonial( $loc, 'No stars here.', 'Anon', 0 );
		$this->go_to( get_permalink( $loc ) );
		do_shortcode( '[anchor_local_testimonials id="' . $loc . '"]' );
		$schema = $this->capture_footer();
		$this->assertStringNotContainsString( 'AggregateRating', $schema );
	}

	/* ---- E. fullwidth template ---- */

	public function test_fullwidth_template_used_when_enabled_and_singular() {
		update_option( \Anchor\Locations\Module::OPTION, [ 'fullwidth_template' => '1' ], false );
		$id = $this->make_location();
		$this->go_to( get_permalink( $id ) );
		$out = $this->seo->fullwidth_template( '/theme/single.php' );
		$this->assertStringContainsString( 'single-anchor-fullwidth.php', $out );
		update_option( \Anchor\Locations\Module::OPTION, [], false );
	}

	public function test_fullwidth_template_passthrough_when_disabled() {
		update_option( \Anchor\Locations\Module::OPTION, [ 'fullwidth_template' => '' ], false );
		$id = $this->make_location();
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( '/theme/single.php', $this->seo->fullwidth_template( '/theme/single.php' ) );
	}
}
