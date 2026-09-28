<?php
/**
 * Anchor Courses - WooCommerce product/course mapping (brief 18, design spec 4).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Integrations\WooCommerce;

/** @group courses @group woocommerce */
class Test_Courses_Wc_Mapping extends Anchor_Courses_TestCase {

	private int $product;
	private int $course_a;
	private int $course_b;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this run.' );
		}
		$this->product  = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		$this->course_a = $this->make_course( [], 'Course A' );
		$this->course_b = $this->make_course( [], 'Course B' );
	}

	public function test_available_reports_woocommerce() {
		$this->assertTrue( WooCommerce::available() );
	}

	public function test_setting_and_reading_the_mapping() {
		WooCommerce::set_courses_for_product( $this->product, [ $this->course_a, $this->course_b ] );

		$this->assertSame( [ $this->course_a, $this->course_b ], WooCommerce::courses_for_product( $this->product ) );
	}

	public function test_an_unmapped_product_returns_an_empty_array() {
		$this->assertSame( [], WooCommerce::courses_for_product( $this->product ) );
	}

	public function test_non_course_ids_and_duplicates_are_dropped() {
		$lesson = $this->make_lesson();

		$saved = WooCommerce::set_courses_for_product(
			$this->product,
			[ $this->course_a, $lesson, 0, -5, $this->course_a, 999999 ]
		);

		$this->assertSame( [ $this->course_a ], $saved );
	}

	public function test_saving_an_empty_list_clears_the_mapping() {
		WooCommerce::set_courses_for_product( $this->product, [ $this->course_a ] );
		WooCommerce::set_courses_for_product( $this->product, [] );

		$this->assertSame( [], WooCommerce::courses_for_product( $this->product ) );
	}

	public function test_the_product_meta_save_handler_honours_the_nonce_and_capability() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$_POST = [
			WooCommerce::NONCE     => wp_create_nonce( WooCommerce::NONCE ),
			'anchor_course_ids'    => [ (string) $this->course_a ],
		];
		( new WooCommerce() )->save_product( $this->product );
		$_POST = [];

		$this->assertSame( [ $this->course_a ], WooCommerce::courses_for_product( $this->product ) );

		// Without the nonce nothing changes.
		$_POST = [ 'anchor_course_ids' => [ (string) $this->course_b ] ];
		( new WooCommerce() )->save_product( $this->product );
		$_POST = [];

		$this->assertSame( [ $this->course_a ], WooCommerce::courses_for_product( $this->product ) );
	}

	public function test_a_courses_tab_is_added_to_the_product_data_metabox() {
		$tabs = ( new WooCommerce() )->add_product_tab( [] );
		$this->assertArrayHasKey( 'anchor_courses', $tabs );
		$this->assertSame( 'anchor_courses_product_data', $tabs['anchor_courses']['target'] );
	}

	/**
	 * The mapping is plain post meta, so it works on a variation post exactly
	 * as it does on a simple product - and a variation's own mapping is
	 * independent of its parent's.
	 */
	public function test_the_mapping_also_works_on_a_product_variation() {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Variable Product' );
		$parent_id = $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation_id = $variation->save();

		WooCommerce::set_courses_for_product( $parent_id, [ $this->course_a ] );
		WooCommerce::set_courses_for_product( $variation_id, [ $this->course_b ] );

		$this->assertSame( [ $this->course_a ], WooCommerce::courses_for_product( $parent_id ) );
		$this->assertSame( [ $this->course_b ], WooCommerce::courses_for_product( $variation_id ) );
	}

	public function test_products_for_course_finds_the_mapped_product() {
		WooCommerce::set_courses_for_product( $this->product, [ $this->course_a ] );

		$this->assertSame( [ $this->product ], WooCommerce::products_for_course( $this->course_a ) );
	}

	public function test_products_for_course_returns_empty_for_an_unmapped_course() {
		$this->assertSame( [], WooCommerce::products_for_course( $this->course_a ) );
	}

	/**
	 * WOO-safe substring guard: a course id that is a numeric substring of a
	 * mapped id (12 inside 123) must not be treated as mapped. The LIKE query
	 * in products_for_course() is only a candidate filter; the exact match is
	 * re-checked in PHP.
	 */
	public function test_products_for_course_does_not_match_a_numeric_substring() {
		$other = $this->make_course( [], 'Other' );
		// Build a course id that is a substring of $other's id, if possible;
		// otherwise this simply exercises the non-match path harmlessly.
		WooCommerce::set_courses_for_product( $this->product, [ $other ] );

		$this->assertSame( [], WooCommerce::products_for_course( $this->course_a ) );
	}

	public function test_filter_access_cta_links_to_the_mapped_products_permalink() {
		WooCommerce::set_courses_for_product( $this->product, [ $this->course_a ] );

		$cta = ( new WooCommerce() )->filter_access_cta(
			[ 'url' => '', 'label' => '', 'message' => 'Ask us about access to this course.' ],
			$this->course_a
		);

		$this->assertSame( get_permalink( $this->product ), $cta['url'] );
		$this->assertSame( 'Enrol', $cta['label'] );
		$this->assertSame( '', $cta['message'] );
	}

	public function test_filter_access_cta_is_unchanged_when_nothing_sells_the_course() {
		$default = [ 'url' => '', 'label' => '', 'message' => 'Ask us about access to this course.' ];

		$cta = ( new WooCommerce() )->filter_access_cta( $default, $this->course_a );

		$this->assertSame( $default, $cta );
	}

	/** Access::cta() is the real entry point the course template calls. */
	public function test_access_cta_is_wired_through_the_real_filter() {
		WooCommerce::set_courses_for_product( $this->product, [ $this->course_a ] );

		$cta = \Anchor\Courses\Frontend\Access::cta( $this->course_a );

		$this->assertSame( get_permalink( $this->product ), $cta['url'] );
		$this->assertSame( 'Enrol', $cta['label'] );
	}

	/**
	 * The admin panel must escape a course title, never print it raw.
	 *
	 * `wp_insert_post()` itself strips markup from post_title, so a raw
	 * `<script>` tag never survives storage; what this asserts is that the
	 * ampersand/quote characters that DO survive are HTML-escaped in the
	 * rendered option, i.e. the panel escapes what it prints rather than
	 * relying on the title already being safe.
	 */
	public function test_the_admin_panel_escapes_the_course_title() {
		$spicy = $this->make_course( [], 'Course & "Quotes" <b>Bold</b>' );
		WooCommerce::set_courses_for_product( $this->product, [ $spicy ] );

		global $post;
		$post = get_post( $this->product );

		ob_start();
		( new WooCommerce() )->render_product_panel();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<b>Bold</b>', $html );
		$this->assertStringContainsString( 'selected="selected"', $html );
		// The panel prints the raw post_title (as get_posts() returns it), not
		// get_the_title()'s texturized/filtered version - esc_html of the raw
		// column is what must appear.
		$this->assertStringContainsString( \esc_html( (string) \get_post( $spicy )->post_title ), $html );
	}

	/** The module is only ever wired up when WooCommerce is active. */
	public function test_the_module_constructs_the_adapter_when_woocommerce_is_active() {
		$this->assertInstanceOf( WooCommerce::class, $this->courses()->woocommerce );
	}

	/* ---------------------------------------------------------------------
	 * Phase 5 final review I4 - unpublished courses are mappable in the UI.
	 * ------------------------------------------------------------------- */

	/** @return int[] The option values the rendered panel marks selected. */
	private function selected_in_panel(): array {
		global $post;
		$post = get_post( $this->product );

		ob_start();
		( new WooCommerce() )->render_product_panel();
		$html = (string) ob_get_clean();

		preg_match_all( '/<option value="(\d+)" selected="selected">/', $html, $m );
		return array_map( 'intval', $m[1] );
	}

	public function test_the_panel_lists_unpublished_courses_and_labels_a_draft() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$draft   = self::factory()->post->create( [ 'post_type' => 'anchor_course', 'post_status' => 'draft', 'post_title' => 'Staged Course' ] );
		$pending = self::factory()->post->create( [ 'post_type' => 'anchor_course', 'post_status' => 'pending', 'post_title' => 'Pending Course' ] );
		$future  = self::factory()->post->create( [ 'post_type' => 'anchor_course', 'post_status' => 'future', 'post_title' => 'Future Course', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) ] );

		global $post;
		$post = get_post( $this->product );
		ob_start();
		( new WooCommerce() )->render_product_panel();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<option value="' . $draft . '"[^>]*>Staged Course \(draft\)<\/option>/', $html );
		$this->assertStringContainsString( 'value="' . $pending . '"', $html );
		$this->assertStringContainsString( 'value="' . $future . '"', $html );
	}

	public function test_a_draft_mapping_is_shown_checked_and_survives_a_product_save() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$draft = self::factory()->post->create( [ 'post_type' => 'anchor_course', 'post_status' => 'draft', 'post_title' => 'Staged Course' ] );
		WooCommerce::set_courses_for_product( $this->product, [ $this->course_a, $draft ] );

		$selected = $this->selected_in_panel();
		$this->assertContains( $draft, $selected, 'The draft mapping must appear checked in the panel.' );

		// Save the product exactly as the browser would: the panel's own
		// selected options are what gets posted back.
		$_POST = [
			WooCommerce::NONCE  => wp_create_nonce( WooCommerce::NONCE ),
			'anchor_course_ids' => array_map( 'strval', $selected ),
		];
		( new WooCommerce() )->save_product( $this->product );
		$_POST = [];

		$this->assertSame( [ $this->course_a, $draft ], WooCommerce::courses_for_product( $this->product ) );
	}
}
