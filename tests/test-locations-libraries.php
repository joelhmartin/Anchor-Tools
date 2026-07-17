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

	public function test_faqs_shortcode_renders_and_emits_faqpage_schema() {
		$loc  = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh' ] );
		$faq  = $this->make_item( 'anchor_faq', [ 'title' => 'How much does roofing cost?', 'locations' => [ $loc ] ] );
		update_post_meta( $faq, 'al_question', 'How much does roofing cost?' );
		update_post_meta( $faq, 'al_answer', '<p>It depends on the roof size.</p>' );

		// Simulate viewing the location page.
		$this->go_to( get_permalink( $loc ) );

		$html = do_shortcode( '[anchor_local_faqs id="' . $loc . '"]' );
		$this->assertStringContainsString( 'How much does roofing cost?', $html );
		$this->assertStringContainsString( 'It depends on the roof size.', $html );

		// FAQ schema is emitted by the Libraries wp_head callback.
		ob_start();
		$this->lib->print_faq_schema();
		$schema = ob_get_clean();
		$this->assertStringContainsString( 'FAQPage', $schema );
		$this->assertStringContainsString( 'How much does roofing cost?', $schema );
		// Safe encoding: no raw </ breakout.
		$this->assertStringNotContainsString( '</p>', $schema );
	}

	public function test_faq_schema_absent_when_no_faqs_rendered() {
		$loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $loc ) );
		ob_start();
		$this->lib->print_faq_schema();
		$schema = ob_get_clean();
		$this->assertSame( '', $schema );
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
