<?php
/**
 * Tests for anchor-locations Phase 2 content libraries
 * (projects / testimonials / FAQs): specificity resolver, shortcodes,
 * FAQ JSON-LD, and the save handler.
 *
 * @package Anchor\Tests
 */

class LocationsLibrariesTest extends WP_UnitTestCase {

	/** @var \Anchor\Locations\Libraries */
	private $lib;

	public function set_up() {
		parent::set_up();
		$this->lib = new \Anchor\Locations\Libraries();
	}

	/**
	 * Fire wp_footer and return what our callbacks printed. Detaches the core
	 * block-theme skip-link callback first: it triggers an unrelated 6.4
	 * deprecation notice that WP_UnitTestCase would otherwise fail on, and it
	 * has nothing to do with the FAQ schema under test.
	 */
	private function capture_footer() {
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
		ob_start();
		do_action( 'wp_footer' );
		return ob_get_clean();
	}

	/** Helper: create a published library item with assignment meta. */
	private function make_item( $cpt, $args = [] ) {
		$id = self::factory()->post->create( [
			'post_type'   => $cpt,
			'post_status' => isset( $args['status'] ) ? $args['status'] : 'publish',
			'post_title'  => isset( $args['title'] ) ? $args['title'] : 'Item',
		] );
		if ( ! empty( $args['locations'] ) ) {
			update_post_meta( $id, 'al_location_ids', array_map( 'intval', $args['locations'] ) );
		}
		if ( ! empty( $args['global'] ) ) {
			update_post_meta( $id, 'al_global', '1' );
		}
		if ( ! empty( $args['service'] ) ) {
			wp_set_object_terms( $id, [ (int) $args['service'] ], 'service' );
		}
		return $id;
	}

	public function test_match_items_orders_both_over_location_over_global() {
		$county = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Allegheny County' ] );
		$city   = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh', 'post_parent' => $county ] );
		$term   = (int) wp_insert_term( 'Roofing', 'service' )['term_id'];

		$both     = $this->make_item( 'anchor_project', [ 'title' => 'Both',     'locations' => [ $city ], 'service' => $term ] );
		$loc_only = $this->make_item( 'anchor_project', [ 'title' => 'LocOnly',  'locations' => [ $city ] ] );
		$global   = $this->make_item( 'anchor_project', [ 'title' => 'Global',   'global' => true ] );
		// Irrelevant item: neither location, service, nor global -> excluded.
		$this->make_item( 'anchor_project', [ 'title' => 'Nope' ] );

		$ids = $this->lib->match_items( 'anchor_project', $city, $term );
		$this->assertSame( [ $both, $loc_only, $global ], $ids );
	}

	public function test_match_items_excludes_drafts() {
		$loc  = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
		$this->make_item( 'anchor_project', [ 'title' => 'Draft', 'locations' => [ $loc ], 'status' => 'draft' ] );
		$pub  = $this->make_item( 'anchor_project', [ 'title' => 'Pub', 'locations' => [ $loc ] ] );
		$ids  = $this->lib->match_items( 'anchor_project', $loc );
		$this->assertSame( [ $pub ], $ids );
	}

	public function test_county_item_surfaces_on_child_city() {
		$county = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Allegheny County' ] );
		$city   = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh', 'post_parent' => $county ] );
		// Item assigned to the COUNTY only.
		$item   = $this->make_item( 'anchor_testimonial', [ 'title' => 'CountyTestimonial', 'locations' => [ $county ] ] );
		// Query from the CHILD city — should match via ancestor walk.
		$ids = $this->lib->match_items( 'anchor_testimonial', $city );
		$this->assertContains( $item, $ids );
	}

	public function test_service_only_scores_above_global_only() {
		$loc  = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
		$term = (int) wp_insert_term( 'Siding', 'service' )['term_id'];
		$svc    = $this->make_item( 'anchor_faq', [ 'title' => 'Svc',    'service' => $term ] );
		$global = $this->make_item( 'anchor_faq', [ 'title' => 'Global', 'global' => true ] );
		$ids = $this->lib->match_items( 'anchor_faq', $loc, $term );
		$this->assertSame( [ $svc, $global ], $ids );
	}

	public function test_limit_caps_results() {
		$loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_item( 'anchor_project', [ 'title' => "P$i", 'locations' => [ $loc ] ] );
		}
		$ids = $this->lib->match_items( 'anchor_project', $loc, 0, 2 );
		$this->assertCount( 2, $ids );
	}

	/**
	 * Exercises the REAL hook ordering: the body renders the shortcode (filling
	 * the per-request collector), THEN wp_footer fires and prints the schema.
	 * Because set_up() constructs a Libraries instance, its print_faq_schema is
	 * attached to wp_footer — so this test would FAIL if the callback were still
	 * hooked to wp_head (nothing would answer do_action('wp_footer')).
	 */
	public function test_faqpage_schema_emitted_on_wp_footer_after_body_renders() {
		$loc  = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh' ] );
		$faq  = $this->make_item( 'anchor_faq', [ 'title' => 'How much does roofing cost?', 'locations' => [ $loc ] ] );
		update_post_meta( $faq, 'al_question', 'How much does roofing cost?' );
		update_post_meta( $faq, 'al_answer', '<p>It depends on the roof size.</p>' );

		// Singular location view: is_singular() must be true so print_faq_schema runs.
		$this->go_to( get_permalink( $loc ) );
		$this->assertTrue( is_singular(), 'Expected a singular location query.' );

		// Body render fills the collector (this is what the_content does live).
		$html = do_shortcode( '[anchor_local_faqs id="' . $loc . '"]' );
		$this->assertStringContainsString( 'How much does roofing cost?', $html );
		$this->assertStringContainsString( 'It depends on the roof size.', $html );

		// Now the footer fires — schema must be present via the real hook.
		$schema = $this->capture_footer();

		$this->assertStringContainsString( 'FAQPage', $schema );
		$this->assertStringContainsString( 'Question', $schema );
		$this->assertStringContainsString( 'How much does roofing cost?', $schema );
		// Safe encoding: no raw </ breakout.
		$this->assertStringNotContainsString( '</p>', $schema );
	}

	/** A page without the FAQ shortcode must emit no FAQPage block on wp_footer. */
	public function test_faqpage_schema_absent_without_shortcode() {
		$loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $loc ) );

		// No shortcode rendered -> collector stays empty.
		$schema = $this->capture_footer();

		$this->assertStringNotContainsString( 'FAQPage', $schema );
	}

	/** The same FAQ rendered by two shortcode calls yields a single Question entry. */
	public function test_faqpage_schema_dedupes_repeated_shortcode() {
		$loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh' ] );
		$faq = $this->make_item( 'anchor_faq', [ 'title' => 'Do you offer warranties?', 'locations' => [ $loc ] ] );
		update_post_meta( $faq, 'al_question', 'Do you offer warranties?' );
		update_post_meta( $faq, 'al_answer', 'Yes.' );

		$this->go_to( get_permalink( $loc ) );

		// Render the shortcode twice on the same page.
		do_shortcode( '[anchor_local_faqs id="' . $loc . '"]' );
		do_shortcode( '[anchor_local_faqs id="' . $loc . '"]' );

		$schema = $this->capture_footer();

		$this->assertSame( 1, substr_count( $schema, '"Question"' ), 'Duplicate FAQ should appear once.' );
	}

	/**
	 * Fix A (off-CPT schema gating): rendering [anchor_local_faqs] on a normal
	 * WordPress Page fills the collector, but the FAQPage JSON-LD must NOT be
	 * emitted — a standalone FAQPage keyed to a non-location/service page is
	 * improper structured data. The visible HTML still renders anywhere.
	 */
	public function test_faqpage_schema_not_emitted_on_non_cpt_singular() {
		$loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
		$faq = $this->make_item( 'anchor_faq', [ 'title' => 'Q on a page?', 'locations' => [ $loc ] ] );
		update_post_meta( $faq, 'al_question', 'Q on a page?' );
		update_post_meta( $faq, 'al_answer', 'An answer.' );

		// A regular WordPress Page — singular, but NOT a location/service page.
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $page ) );
		$this->assertTrue( is_singular(), 'Expected a singular page query.' );
		$this->assertFalse( is_singular( [ 'anchor_location', 'anchor_service_page' ] ) );

		// Shortcode renders (visible HTML + collector fills) ...
		$html = do_shortcode( '[anchor_local_faqs id="' . $loc . '"]' );
		$this->assertStringContainsString( 'Q on a page?', $html );

		// ... but the footer must NOT print FAQPage JSON-LD off the module CPTs.
		$schema = $this->capture_footer();
		$this->assertStringNotContainsString( 'FAQPage', $schema );
	}

	/** Review + AggregateRating JSON-LD IS emitted on a location (module CPT) page. */
	public function test_review_schema_emitted_on_cpt_page() {
		$loc   = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh' ] );
		$testi = $this->make_item( 'anchor_testimonial', [ 'title' => 'Client', 'locations' => [ $loc ] ] );
		update_post_meta( $testi, 'al_quote', 'Excellent service.' );
		update_post_meta( $testi, 'al_author', 'Bob' );
		update_post_meta( $testi, 'al_rating', 5 );

		$this->go_to( get_permalink( $loc ) );
		$this->assertTrue( is_singular( [ 'anchor_location', 'anchor_service_page' ] ) );

		do_shortcode( '[anchor_local_testimonials id="' . $loc . '"]' );
		$schema = $this->capture_footer();

		$this->assertStringContainsString( 'AggregateRating', $schema );
		$this->assertStringContainsString( '"Review"', $schema );
		$this->assertStringContainsString( 'Excellent service.', $schema );
	}

	/** Fix A: the Review/AggregateRating block must NOT emit on a non-CPT Page. */
	public function test_review_schema_not_emitted_on_non_cpt_singular() {
		$loc   = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
		$testi = $this->make_item( 'anchor_testimonial', [ 'title' => 'Client', 'locations' => [ $loc ] ] );
		update_post_meta( $testi, 'al_quote', 'Excellent service.' );
		update_post_meta( $testi, 'al_author', 'Bob' );
		update_post_meta( $testi, 'al_rating', 5 );

		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $page ) );
		$this->assertFalse( is_singular( [ 'anchor_location', 'anchor_service_page' ] ) );

		do_shortcode( '[anchor_local_testimonials id="' . $loc . '"]' );
		$schema = $this->capture_footer();

		$this->assertStringNotContainsString( 'AggregateRating', $schema );
	}

	public function test_save_handler_sanitizes_meta() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
		$id  = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial', 'post_status' => 'publish' ] );

		$_POST = [
			\Anchor\Locations\Libraries::NONCE => wp_create_nonce( \Anchor\Locations\Libraries::NONCE ),
			'al_quote'        => '<strong>Great work</strong><script>evil()</script>',
			'al_author'       => '  Jane Doe  ',
			'al_rating'       => '9',
			'al_location_ids' => [ (string) $loc, 'abc', '0' ],
			'al_global'       => '1',
		];
		$this->lib->save_meta( $id );

		$this->assertSame( 5, (int) get_post_meta( $id, 'al_rating', true ) );
		$this->assertStringNotContainsString( '<script>', get_post_meta( $id, 'al_quote', true ) );
		$this->assertSame( 'Jane Doe', get_post_meta( $id, 'al_author', true ) );
		$this->assertSame( [ $loc ], get_post_meta( $id, 'al_location_ids', true ) );
		$this->assertSame( '1', get_post_meta( $id, 'al_global', true ) );

		$_POST = [];
	}

	public function test_save_handler_rejects_without_nonce() {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_faq', 'post_status' => 'publish' ] );
		$_POST = [ 'al_answer' => 'no nonce' ];
		$this->lib->save_meta( $id );
		$this->assertSame( '', get_post_meta( $id, 'al_answer', true ) );
		$_POST = [];
	}
}
