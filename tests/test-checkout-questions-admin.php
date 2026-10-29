<?php
// tests/test-checkout-questions-admin.php
use Anchor\CheckoutQuestions\Admin\OrderMetabox;
use Anchor\CheckoutQuestions\Admin\ProductNotice;
use Anchor\CheckoutQuestions\Admin\BlockCheckoutNotice;
use Anchor\CheckoutQuestions\Support\QuestionSets;

class Test_Checkout_Questions_Admin extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		unset( $_GET['id'], $_GET['post'], $GLOBALS['post'] ); // Other suites leave these behind.
	}

	public function tear_down(): void {
		unset( $_GET['id'], $_GET['post'], $GLOBALS['post'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function snapshot(): array {
		return [
			[
				'set_id'    => 11,
				'set_title' => 'Q & A: Don\'t',
				'questions' => [
					[ 'key' => 'phone', 'label' => 'Phone <b>no</b>', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => false ],
					[ 'key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'options' => [], 'required' => false, 'admin_email' => false ],
					[ 'key' => 'skip', 'label' => 'Skipped', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => false ],
				],
				'answers'   => [ 'phone' => '<script>alert(1)</script> & "q"', 'notes' => "line1\nline2" ],
			],
		];
	}

	private function order( bool $with_answers ): WC_Order {
		$o = wc_create_order();
		if ( $with_answers ) {
			$o->update_meta_data( '_anchor_cq_answers', $this->snapshot() );
		}
		$o->save();
		return $o;
	}

	private function render( WC_Order $o ): string {
		ob_start();
		( new OrderMetabox() )->render( $o );
		return ob_get_clean();
	}

	public function test_render_shows_snapshot_escaped_with_dash_for_blank() {
		$html = $this->render( $this->order( true ) );
		$this->assertStringContainsString( '<h4>Q &amp; A: Don&#039;t</h4>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;q&quot;', $html );
		$this->assertStringNotContainsString( '<b>no</b>', $html );
		$this->assertStringContainsString( 'line1<br', $html );
		$this->assertStringContainsString( 'Skipped', $html );
		$this->assertStringContainsString( '—', $html );
	}

	public function test_render_uses_snapshot_not_live_set() {
		$set = (int) self::factory()->post->create( [ 'post_type' => QuestionSets::CPT, 'post_status' => 'publish', 'post_title' => 'Renamed' ] );
		$snap = $this->snapshot();
		$snap[0]['set_id'] = $set;
		$o = wc_create_order();
		$o->update_meta_data( '_anchor_cq_answers', $snap );
		$o->save();
		wp_delete_post( $set, true );
		$html = $this->render( $o );
		$this->assertStringContainsString( 'Phone &lt;b&gt;no&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( 'Renamed', $html );
	}

	public function test_no_box_registered_when_meta_empty_hpos_style() {
		global $wp_meta_boxes;
		$screen = wc_get_page_screen_id( 'shop-order' );
		unset( $wp_meta_boxes[ $screen ] );
		$_GET['id'] = $this->order( false )->get_id();
		( new OrderMetabox() )->register();
		$this->assertArrayNotHasKey( 'anchor-cq-answers', $wp_meta_boxes[ $screen ]['normal']['default'] ?? [] );
	}

	public function test_box_registered_when_meta_present() {
		global $wp_meta_boxes;
		$screen = wc_get_page_screen_id( 'shop-order' );
		unset( $wp_meta_boxes[ $screen ] );
		$_GET['id'] = $this->order( true )->get_id();
		( new OrderMetabox() )->register();
		$this->assertArrayHasKey( 'anchor-cq-answers', $wp_meta_boxes[ $screen ]['normal']['default'] );
	}

	public function test_legacy_global_post_is_resolved() {
		global $wp_meta_boxes;
		$screen = wc_get_page_screen_id( 'shop-order' );
		unset( $wp_meta_boxes[ $screen ] );
		$id = $this->order( true )->get_id();
		$GLOBALS['post'] = (object) [ 'ID' => $id ];
		( new OrderMetabox() )->register();
		$this->assertArrayHasKey( 'anchor-cq-answers', $wp_meta_boxes[ $screen ]['normal']['default'] ?? [] );
		unset( $wp_meta_boxes[ $screen ] );
		$GLOBALS['post'] = (object) [ 'ID' => $this->order( false )->get_id() ];
		( new OrderMetabox() )->register();
		$this->assertArrayNotHasKey( 'anchor-cq-answers', $wp_meta_boxes[ $screen ]['normal']['default'] ?? [] );
	}

	public function test_no_box_when_no_order_resolvable() {
		global $wp_meta_boxes;
		$screen = wc_get_page_screen_id( 'shop-order' );
		unset( $wp_meta_boxes[ $screen ] );
		( new OrderMetabox() )->register();
		$this->assertArrayNotHasKey( 'anchor-cq-answers', $wp_meta_boxes[ $screen ]['normal']['default'] ?? [] );
	}

	private function published_set( string $title, int $product ): int {
		$id = (int) self::factory()->post->create( [ 'post_type' => QuestionSets::CPT, 'post_status' => 'publish', 'post_title' => $title ] );
		update_post_meta( $id, QuestionSets::META_PRODUCTS, [ $product ] );
		return $id;
	}

	public function test_product_notice_lists_matching_sets_with_links() {
		$p = new WC_Product_Simple();
		$p->set_name( 'P' );
		$p->save();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) ); // Edit links need edit_post.
		$a = $this->published_set( 'Set <A>', $p->get_id() );
		$GLOBALS['post'] = get_post( $p->get_id() );
		ob_start();
		( new ProductNotice() )->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Checkout questions:', $html );
		$this->assertStringContainsString( 'post=' . $a, $html );
		$this->assertStringContainsString( 'Set &lt;A&gt;', $html );
	}

	public function test_product_notice_silent_when_no_sets() {
		$p = new WC_Product_Simple();
		$p->set_name( 'P2' );
		$p->save();
		$GLOBALS['post'] = get_post( $p->get_id() );
		ob_start();
		( new ProductNotice() )->render();
		$this->assertSame( '', ob_get_clean() );
	}

	private function checkout_page( string $content ): void {
		$id = (int) self::factory()->post->create( [ 'post_type' => 'page', 'post_content' => $content ] );
		update_option( 'woocommerce_checkout_page_id', $id );
	}

	private function notice(): string {
		ob_start();
		BlockCheckoutNotice::render();
		return ob_get_clean();
	}

	public function test_block_notice_only_with_block_and_published_set_and_cap() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		grant_super_admin( get_current_user_id() );
		$this->checkout_page( '<!-- wp:woocommerce/checkout --><div></div><!-- /wp:woocommerce/checkout -->' );
		$this->assertSame( '', $this->notice(), 'no published set' );
		$this->published_set( 'S', 1 );
		$this->assertStringContainsString( 'classic checkout only', $this->notice() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( '', $this->notice(), 'no capability' );
	}

	public function test_block_notice_silent_on_classic_checkout() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->checkout_page( '[woocommerce_checkout]' );
		$this->published_set( 'S', 1 );
		$this->assertSame( '', $this->notice() );
	}
}
