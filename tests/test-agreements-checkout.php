<?php
// tests/test-agreements-checkout.php
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Frontend\Checkout;
use Anchor\Agreements\Frontend\SigningEndpoint;

class Test_Agreements_Checkout extends WP_UnitTestCase {
	const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	public function set_up(): void {
		parent::set_up();
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->session->set_customer_session_cookie( true );
		WC()->cart = new WC_Cart();
	}

	private function product_with_agreement( string $title ): array {
		$aid = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<p>Body of ' . $title . '</p><script>x</script>' ] );
		$p   = new WC_Product_Simple();
		$p->set_regular_price( '10' );
		$p->update_meta_data( '_anchor_agreement_required', 'yes' );
		$p->update_meta_data( '_anchor_agreement_id', $aid );
		$p->save();
		return [ $aid, $p ];
	}

	private function sign( int $aid ): int {
		return ( new SigningEndpoint() )->handle( [ 'agreement_id' => $aid, 'name' => 'A B', 'method' => 'draw', 'font' => '', 'image' => self::PNG, 'consent' => '1' ] )['signature_id'];
	}

	private function render(): string {
		ob_start();
		( new Checkout() )->render();
		return (string) ob_get_clean();
	}

	public function test_render_nothing_when_no_requirement() {
		$p = new WC_Product_Simple();
		$p->set_regular_price( '1' );
		$p->save();
		WC()->cart->add_to_cart( $p->get_id() );
		$this->assertSame( '', $this->render() );
	}

	public function test_render_unchecked_then_checked_after_signing() {
		[ $aid, $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$html = $this->render();
		$this->assertStringContainsString( 'data-complete="0"', $html );
		$this->assertStringContainsString( 'I have read and signed the Cancellation Policy', $html );
		$this->assertStringNotContainsString( '<script>x</script>', $html );
		$this->sign( $aid );
		$this->assertStringContainsString( 'data-complete="1"', $this->render() );
	}

	public function test_render_shows_checked_after_signing() {
		// Fragment refresh = render() called again on a fresh request; state must come from the session.
		[ $aid, $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$this->sign( $aid );
		$this->render();
		$this->assertStringContainsString( 'checked', $this->render() );
	}

	public function test_two_documents_use_count_label() {
		[ , $p1 ] = $this->product_with_agreement( 'Policy A' );
		[ , $p2 ] = $this->product_with_agreement( 'Policy B' );
		WC()->cart->add_to_cart( $p1->get_id() );
		WC()->cart->add_to_cart( $p2->get_id() );
		$this->assertStringContainsString( 'I have read and signed 2 required agreements', $this->render() );
	}

	public function test_validate_blocks_unsigned_and_passes_signed() {
		[ $aid, $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$errors = new WP_Error();
		( new Checkout() )->validate( [], $errors );
		$this->assertContains( 'anchor_agreements_unsigned', $errors->get_error_codes() );
		$this->sign( $aid );
		$errors = new WP_Error();
		( new Checkout() )->validate( [], $errors );
		$this->assertSame( [], $errors->get_error_codes() );
	}

	public function test_only_required_signatures_are_attached() {
		[ $a1, $p1 ] = $this->product_with_agreement( 'Policy A' );
		[ $a2, $p2 ] = $this->product_with_agreement( 'Policy B' );
		WC()->cart->add_to_cart( $p1->get_id() );
		WC()->cart->add_to_cart( $p2->get_id() );
		$s1 = $this->sign( $a1 );
		$s2 = $this->sign( $a2 );
		// Buyer removes product 2 before ordering.
		$order = wc_create_order();
		$order->add_product( $p1, 1 );
		$order->save();
		( new Checkout() )->attach( $order );
		$repo = new SignatureRepository();
		$this->assertSame( $order->get_id(), $repo->get( $s1 )['order_id'] );
		$this->assertNull( $repo->get( $s2 )['order_id'] );
		$this->assertSame( [ $s1 ], wc_get_order( $order->get_id() )->get_meta( '_anchor_agreement_signature_ids' ) );
	}

	public function test_store_api_guard_throws_when_required() {
		[ , $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		$order   = wc_create_order();
		$order->add_product( $p, 1 );
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		( new Checkout() )->guard_store_api( $order );
	}
}
