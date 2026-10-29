<?php
// tests/test-checkout-questions-sets.php
use Anchor\CheckoutQuestions\Support\QuestionSets;

class Test_Checkout_Questions_Sets extends WP_UnitTestCase {

	private function new_set( string $status = 'publish', array $extra = [] ): int {
		return (int) self::factory()->post->create( array_merge( [ 'post_type' => QuestionSets::CPT, 'post_status' => $status, 'post_title' => 'Intake' ], $extra ) );
	}

	private function save_as_admin( int $set_id, array $post ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST = array_merge( [ 'anchor_cq_set_nonce' => wp_create_nonce( 'anchor_cq_save_set' ) ], $post );
		wp_update_post( [ 'ID' => $set_id, 'post_title' => 'Intake saved' ] );
		$_POST = [];
	}

	public function tear_down(): void {
		$_POST = [];
		parent::tear_down();
	}

	public function test_module_is_registered_and_cpt_exists() {
		$mods = anchor_tools_get_available_modules();
		$this->assertSame( '\\Anchor\\CheckoutQuestions\\Module', $mods['checkout_questions']['class'] );
		$this->assertTrue( post_type_exists( QuestionSets::CPT ) );
		$this->assertFalse( get_post_type_object( QuestionSets::CPT )->public );
	}

	public function test_save_stores_normalized_questions_products_and_categories() {
		$id = $this->new_set();
		$this->save_as_admin( $id, [
			'anchor_cq_questions_present' => '1',
			'anchor_cq_questions'         => [ [ 'label' => 'Company', 'type' => 'text', 'required' => '1' ], [ 'label' => '', 'type' => 'text' ] ],
			'anchor_cq_product_ids'       => [ '12', 0, 'x' ],
			'anchor_cq_category_ids'      => [ '5', '7', '-3' ],
		] );
		$qs = QuestionSets::questions( $id );
		$this->assertCount( 1, $qs );
		$this->assertSame( 'Company', $qs[0]['label'] );
		$this->assertTrue( (bool) $qs[0]['required'] );
		$this->assertSame( [ 12 ], QuestionSets::product_ids( $id ) );
		$this->assertSame( [ 5, 7 ], QuestionSets::category_ids( $id ) );
	}

	public function test_save_maps_variation_ids_to_parent_and_dedupes() {
		$parent = (int) self::factory()->post->create( [ 'post_type' => 'product' ] );
		$var    = (int) self::factory()->post->create( [ 'post_type' => 'product_variation', 'post_parent' => $parent ] );
		$id     = $this->new_set();
		$this->save_as_admin( $id, [ 'anchor_cq_product_ids' => [ (string) $var, (string) $parent ] ] );
		$this->assertSame( [ $parent ], QuestionSets::product_ids( $id ) );
		$this->assertSame( [ $id ], Anchor\CheckoutQuestions\Services\Matcher::for_lines( [ [ 'product_id' => $parent, 'variation_id' => $var ] ] ) );
	}

	public function test_picker_searches_parent_products_only() {
		$id   = $this->new_set();
		$html = $this->render_where_html( $id );
		$this->assertStringContainsString( 'data-action="woocommerce_json_search_products"', $html );
		$this->assertStringNotContainsString( 'products_and_variations', $html );
	}

	private function render_where_html( int $id ): string {
		ob_start();
		( new Anchor\CheckoutQuestions\Admin\SetEditor() )->render_where( get_post( $id ) );
		return ob_get_clean();
	}

	public function test_save_without_marker_or_rows_leaves_questions_untouched() {
		$id = $this->new_set();
		$this->save_as_admin( $id, [ 'anchor_cq_questions_present' => '1', 'anchor_cq_questions' => [ [ 'label' => 'Keep me', 'type' => 'text' ] ] ] );
		$this->save_as_admin( $id, [] );
		$this->assertSame( 'Keep me', QuestionSets::questions( $id )[0]['label'] );
		// Marker with no rows clears them.
		$this->save_as_admin( $id, [ 'anchor_cq_questions_present' => '1' ] );
		$this->assertSame( [], QuestionSets::questions( $id ) );
	}

	public function test_save_requires_nonce_and_capability() {
		$id = $this->new_set();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST = [ 'anchor_cq_questions_present' => '1', 'anchor_cq_questions' => [ [ 'label' => 'No nonce', 'type' => 'text' ] ] ];
		wp_update_post( [ 'ID' => $id, 'post_title' => 'x' ] );
		$this->assertSame( [], QuestionSets::questions( $id ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST['anchor_cq_set_nonce'] = wp_create_nonce( 'anchor_cq_save_set' );
		wp_update_post( [ 'ID' => $id, 'post_title' => 'y' ] );
		$this->assertSame( [], QuestionSets::questions( $id ) );
	}

	public function test_published_set_ids_excludes_drafts_and_orders_by_menu_order_then_id() {
		$a     = $this->new_set( 'publish', [ 'menu_order' => 5 ] );
		$b     = $this->new_set( 'publish', [ 'menu_order' => 1 ] );
		$c     = $this->new_set( 'publish', [ 'menu_order' => 1 ] );
		$draft = $this->new_set( 'draft' );
		$ids   = QuestionSets::published_set_ids();
		$this->assertNotContains( $draft, $ids );
		$this->assertSame( [ $b, $c, $a ], array_values( array_intersect( $ids, [ $a, $b, $c ] ) ) );
	}

	public function test_edit_screen_uses_shared_editor_and_neutral_add_button() {
		$id   = $this->new_set();
		$html = ( new \Anchor\CheckoutQuestions\Admin\SetEditor() )->questions_box_html( get_post( $id ) );
		$this->assertStringContainsString( 'name="anchor_cq_questions_present"', $html );
		$this->assertStringContainsString( 'class="button anchor-question-add"', $html );
		$this->assertStringNotContainsString( 'anchor-event-button-secondary', $html );
	}

	public function test_module_without_woocommerce_registers_only_a_notice() {
		$before = has_action( 'save_post_' . QuestionSets::CPT );
		new \Anchor\CheckoutQuestions\Module( false );
		$this->assertSame( $before, has_action( 'save_post_' . QuestionSets::CPT ) );
		$this->assertNotFalse( has_action( 'admin_notices', [ \Anchor\CheckoutQuestions\Module::class, 'missing_woocommerce_notice' ] ) );
		remove_action( 'admin_notices', [ \Anchor\CheckoutQuestions\Module::class, 'missing_woocommerce_notice' ] );
	}
}
