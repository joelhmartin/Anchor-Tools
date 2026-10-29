<?php
// tests/test-checkout-questions-checkout.php
use Anchor\CheckoutQuestions\Frontend\Checkout;
use Anchor\CheckoutQuestions\Support\QuestionSets;

class Test_Checkout_Questions_Checkout extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->cart = new WC_Cart();
		$_POST = [];
	}

	public function tear_down(): void {
		$_POST = [];
		parent::tear_down();
	}

	private function q( string $key, string $type = 'text', bool $required = false, string $label = '' ): array {
		return [ 'key' => $key, 'label' => $label ?: ucfirst( $key ), 'type' => $type, 'options' => [], 'required' => $required, 'admin_email' => false ];
	}

	private function product(): int {
		$p = new WC_Product_Simple();
		$p->set_name( 'P' );
		$p->set_regular_price( '10' );
		$p->save();
		return $p->get_id();
	}

	/** A published set attached to $product, in the cart. */
	private function set_for( int $product, array $questions, string $title = 'Intake' ): int {
		$id = (int) self::factory()->post->create( [ 'post_type' => QuestionSets::CPT, 'post_status' => 'publish', 'post_title' => $title ] );
		update_post_meta( $id, QuestionSets::META_QUESTIONS, $questions );
		update_post_meta( $id, QuestionSets::META_PRODUCTS, [ $product ] );
		return $id;
	}

	private function checkout(): Checkout {
		return new Checkout();
	}

	private function errors_for(): WP_Error {
		$e = new WP_Error();
		$this->checkout()->validate( [], $e );
		return $e;
	}

	private function order_for( int $product ): WC_Order {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->save();
		return $order;
	}

	public function test_required_text_blank_gives_one_error_naming_it() {
		$p = $this->product();
		$s = $this->set_for( $p, [ $this->q( 'name', 'text', true, 'Full name' ) ] );
		WC()->cart->add_to_cart( $p );
		$_POST['anchor_cq'] = [ $s => [ 'name' => '  ' ] ];
		$e = $this->errors_for();
		$this->assertCount( 1, $e->get_error_messages() );
		$this->assertStringContainsString( 'Full name', $e->get_error_message() );
		$this->assertStringContainsString( 'is a required field', $e->get_error_message() );
	}

	public function test_required_checkbox_unticked_errors() {
		$p = $this->product();
		$s = $this->set_for( $p, [ $this->q( 'agree', 'checkbox', true ) ] );
		WC()->cart->add_to_cart( $p );
		$this->assertCount( 1, $this->errors_for()->get_error_messages() );
		$_POST['anchor_cq'] = [ $s => [ 'agree' => 'yes' ] ];
		$this->assertCount( 0, $this->errors_for()->get_error_messages() );
	}

	public function test_all_answered_no_errors() {
		$p = $this->product();
		$s = $this->set_for( $p, [ $this->q( 'a', 'text', true ), $this->q( 'b', 'textarea', true ) ] );
		WC()->cart->add_to_cart( $p );
		$_POST['anchor_cq'] = [ $s => [ 'a' => 'x', 'b' => 'y' ] ];
		$this->assertCount( 0, $this->errors_for()->get_error_messages() );
	}

	public function test_store_snapshots_set_title_and_questions_even_when_blank() {
		$p = $this->product();
		$s = $this->set_for( $p, [ $this->q( 'a' ) ], 'My set' );
		WC()->cart->add_to_cart( $p );
		$order = $this->order_for( $p );
		$this->checkout()->store( $order, [] );
		$meta = $order->get_meta( '_anchor_cq_answers' );
		$this->assertCount( 1, $meta );
		$this->assertSame( $s, $meta[0]['set_id'] );
		$this->assertSame( 'My set', $meta[0]['set_title'] );
		$this->assertSame( 'a', $meta[0]['questions'][0]['key'] );
		$this->assertSame( [ 'a' => '' ], $meta[0]['answers'] );
	}

	public function test_stored_entry_survives_set_rename_edit_and_delete() {
		$p = $this->product();
		$s = $this->set_for( $p, [ $this->q( 'a', 'text', false, 'Old label' ) ], 'Old title' );
		WC()->cart->add_to_cart( $p );
		$_POST['anchor_cq'] = [ $s => [ 'a' => 'hello' ] ];
		$order = $this->order_for( $p );
		$this->checkout()->store( $order, [] );
		$order->save();
		$before = wc_get_order( $order->get_id() )->get_meta( '_anchor_cq_answers' );

		wp_update_post( [ 'ID' => $s, 'post_title' => 'New title' ] );
		update_post_meta( $s, QuestionSets::META_QUESTIONS, [ $this->q( 'a', 'text', false, 'New label' ) ] );
		wp_delete_post( $s, true );

		$after = wc_get_order( $order->get_id() )->get_meta( '_anchor_cq_answers' );
		$this->assertSame( $before, $after );
		$this->assertSame( 'Old title', $after[0]['set_title'] );
		$this->assertSame( 'Old label', $after[0]['questions'][0]['label'] );
		$this->assertSame( 'hello', $after[0]['answers']['a'] );
	}

	public function test_two_sets_with_same_key_keep_separate_answers() {
		$p  = $this->product();
		$s1 = $this->set_for( $p, [ $this->q( 'phone' ) ], 'One' );
		$s2 = $this->set_for( $p, [ $this->q( 'phone' ) ], 'Two' );
		WC()->cart->add_to_cart( $p );
		$_POST['anchor_cq'] = [ $s1 => [ 'phone' => '111' ], $s2 => [ 'phone' => '222' ] ];
		$order = $this->order_for( $p );
		$this->checkout()->store( $order, [] );
		$by = [];
		foreach ( $order->get_meta( '_anchor_cq_answers' ) as $e ) {
			$by[ $e['set_id'] ] = $e['answers']['phone'];
		}
		$this->assertSame( [ $s1 => '111', $s2 => '222' ], $by );
	}

	public function test_textarea_newlines_survive() {
		$p = $this->product();
		$s = $this->set_for( $p, [ $this->q( 'n', 'textarea' ) ] );
		WC()->cart->add_to_cart( $p );
		$_POST['anchor_cq'] = [ $s => [ 'n' => "line1\nline2" ] ];
		$order = $this->order_for( $p );
		$this->checkout()->store( $order, [] );
		$this->assertSame( "line1\nline2", $order->get_meta( '_anchor_cq_answers' )[0]['answers']['n'] );
	}

	public function test_posted_answers_for_a_set_not_in_the_cart_are_ignored() {
		$p     = $this->product();
		$other = $this->product();
		$s     = $this->set_for( $p, [ $this->q( 'a' ) ] );
		$away  = $this->set_for( $other, [ $this->q( 'a', 'text', true ) ] );
		WC()->cart->add_to_cart( $p );
		$_POST['anchor_cq'] = [ $s => [ 'a' => 'x' ], $away => [ 'a' => 'sneaky' ] ];
		$this->assertCount( 0, $this->errors_for()->get_error_messages() );
		$order = $this->order_for( $p );
		$this->checkout()->store( $order, [] );
		$meta = $order->get_meta( '_anchor_cq_answers' );
		$this->assertCount( 1, $meta );
		$this->assertSame( $s, $meta[0]['set_id'] );
	}

	public function test_render_outputs_namespaced_control_and_title() {
		$p = $this->product();
		$s = $this->set_for( $p, [ $this->q( 'a' ) ], 'Intake <b>' );
		WC()->cart->add_to_cart( $p );
		$_POST['anchor_cq'] = [ $s => [ 'a' => 'kept' ] ];
		ob_start();
		$this->checkout()->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'class="anchor-cq-set"', $html );
		$this->assertStringContainsString( 'name="anchor_cq[' . $s . '][a]"', $html );
		$this->assertStringContainsString( 'value="kept"', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_render_prints_nothing_without_sets() {
		WC()->cart->add_to_cart( $this->product() );
		ob_start();
		$this->checkout()->render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_store_api_guard() {
		$p = $this->product();
		$this->set_for( $p, [ $this->q( 'a', 'text', true ) ] );
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		$this->expectExceptionMessage( 'classic checkout' );
		$this->checkout()->guard_block_checkout( $this->order_for( $p ) );
	}

	public function test_store_api_guard_silent_for_optional_or_no_set() {
		$p = $this->product();
		$this->set_for( $p, [ $this->q( 'a' ) ] );
		$this->checkout()->guard_block_checkout( $this->order_for( $p ) );
		$this->checkout()->guard_block_checkout( $this->order_for( $this->product() ) );
		$this->assertTrue( true );
	}

	public function test_hooks_are_wired() {
		$c = $this->checkout();
		$this->assertNotFalse( has_action( 'woocommerce_after_order_notes', [ $c, 'render' ] ) );
		$this->assertNotFalse( has_action( 'woocommerce_after_checkout_validation', [ $c, 'validate' ] ) );
		$this->assertNotFalse( has_action( 'woocommerce_checkout_create_order', [ $c, 'store' ] ) );
		$this->assertNotFalse( has_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $c, 'guard_block_checkout' ] ) );
	}
}
