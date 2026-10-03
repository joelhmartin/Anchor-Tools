<?php
// tests/test-agreements-checkout.php
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Frontend\Checkout;
use Anchor\Agreements\Frontend\SigningEndpoint;
use Anchor\Agreements\Services\SignatureCheck;

class Test_Agreements_Checkout extends WP_UnitTestCase {
	const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->session->set_customer_session_cookie( true );
		WC()->cart = new WC_Cart();
	}

	public function tear_down(): void {
		remove_filter( 'send_auth_cookies', '__return_false' );
		unset( $_COOKIE[ $this->cookie_name() ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function cookie_name(): string {
		return (string) apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH );
	}

	/** What the browser sends back: WC only migrates a guest session it can read from the cookie. */
	private function send_session_cookie(): void {
		$s   = WC()->session;
		$exp = ( fn() => $this->_session_expiration )->call( $s );
		$ing = ( fn() => $this->_session_expiring )->call( $s );
		$id  = $s->get_customer_id();
		$msg = $id . '|' . $exp;
		$_COOKIE[ $this->cookie_name() ] = $msg . '|' . $ing . '|' . hash_hmac( 'md5', $msg, wp_hash( $msg ) );
	}

	/** WC_Checkout::process_customer() for "create an account": create, log in, migrate the session. */
	private function create_account_at_checkout(): int {
		WC()->session->save_data();
		$this->send_session_cookie();
		$user = self::factory()->user->create( [ 'user_email' => 'new@example.com' ] );
		add_filter( 'send_auth_cookies', '__return_false' );
		wc_set_customer_auth_cookie( $user );
		return $user;
	}

	private function checkout_data(): array {
		return [ 'payment_method' => 'bacs', 'billing_email' => 'buyer@example.com', 'billing_first_name' => 'A', 'billing_last_name' => 'B' ];
	}

	/** The real WC_Checkout::create_order(), which fires woocommerce_checkout_order_created. */
	private function create_order() {
		return WC()->checkout()->create_order( $this->checkout_data() );
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

	public function test_unsigned_error_names_the_document() {
		[ , $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$errors = new WP_Error();
		( new Checkout() )->validate( [], $errors );
		$this->assertSame( 'Please read and sign the Cancellation Policy before placing your order.', $errors->get_error_message( 'anchor_agreements_unsigned' ) );
		[ , $p2 ] = $this->product_with_agreement( 'Privacy Notice' );
		WC()->cart->add_to_cart( $p2->get_id() );
		$errors = new WP_Error();
		( new Checkout() )->validate( [], $errors );
		$this->assertSame( 'Please read and sign the required agreements before placing your order.', $errors->get_error_message( 'anchor_agreements_unsigned' ) );
	}

	/** C1: "Create an account" at checkout switches the session to the user id before the order exists. */
	public function test_account_created_at_checkout_keeps_signature() {
		[ $aid, $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$sig   = $this->sign( $aid );
		$guest = WC()->session->get_customer_id();
		$this->assertStringStartsWith( 't_', $guest );
		$errors = new WP_Error();
		( new Checkout() )->validate( [], $errors );
		$this->assertSame( [], $errors->get_error_codes() );

		$user = $this->create_account_at_checkout();
		$this->assertSame( (string) $user, WC()->session->get_customer_id() );
		// The session map moved with the session, so the checkbox stays ticked after the switch.
		$this->assertSame( $sig, SignatureCheck::session_map()[ $aid ] ?? 0 );
		$this->assertStringContainsString( 'data-complete="1"', $this->render() );

		$order_id = $this->create_order();
		$this->assertIsInt( $order_id, is_wp_error( $order_id ) ? $order_id->get_error_message() : '' );
		$row = ( new SignatureRepository() )->get( $sig );
		$this->assertSame( $order_id, $row['order_id'] );
		$this->assertSame( (string) $user, $row['session_key'] );
		$this->assertSame( $user, $row['user_id'] );
		$this->assertSame( [ $sig ], wc_get_order( $order_id )->get_meta( '_anchor_agreement_signature_ids' ) );
	}

	/** C1 belt and braces: attach() uses what validate() approved in the same request. */
	public function test_attach_reuses_ids_validate_approved() {
		[ $aid, $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$sig      = $this->sign( $aid );
		$checkout = new Checkout();
		$errors   = new WP_Error();
		$checkout->validate( [], $errors );
		$this->assertSame( [], $errors->get_error_codes() );
		// Anything that changes the session key between the two hooks.
		global $wpdb;
		$wpdb->update( \Anchor\Agreements\Database\Migrations::table( 'signatures' ), [ 'session_key' => 'somewhere-else' ], [ 'id' => $sig ] );
		$order = wc_create_order();
		$order->add_product( $p, 1 );
		$order->save();
		$checkout->attach( $order );
		$this->assertSame( $order->get_id(), ( new SignatureRepository() )->get( $sig )['order_id'] );
	}

	/** C2: the awaiting order was abandoned (cart changed), WooCommerce makes order B; B gets the signature. */
	public function test_new_order_after_cart_change_takes_signature_from_unpaid_order() {
		[ $aid, $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$sig = $this->sign( $aid );
		$a   = $this->create_order();
		$this->assertIsInt( $a );
		$this->assertSame( $a, ( new SignatureRepository() )->get( $sig )['order_id'] );
		wc_get_order( $a )->update_status( 'failed' );
		WC()->session->set( 'order_awaiting_payment', $a );

		WC()->cart->add_to_cart( $p->get_id() ); // Quantity 2: cart hash changes, so WC will not resume A.
		$errors = new WP_Error();
		( new Checkout() )->validate( [], $errors );
		$this->assertSame( [], $errors->get_error_codes() );
		$b = $this->create_order();
		$this->assertIsInt( $b, is_wp_error( $b ) ? $b->get_error_message() : '' );
		$this->assertNotSame( $a, $b );

		$this->assertSame( $b, ( new SignatureRepository() )->get( $sig )['order_id'] );
		$this->assertSame( [ $sig ], wc_get_order( $b )->get_meta( '_anchor_agreement_signature_ids' ) );
		$this->assertSame( '', wc_get_order( $a )->get_meta( '_anchor_agreement_signature_ids' ) );
	}

	public function test_cancelled_awaiting_order_releases_signature_too() {
		[ $aid, $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$sig = $this->sign( $aid );
		$a   = $this->create_order();
		wc_get_order( $a )->update_status( 'cancelled' ); // Hold-stock timeout.
		WC()->session->set( 'order_awaiting_payment', $a );
		$b = $this->create_order(); // Same cart, but WC only resumes pending/failed.
		$this->assertIsInt( $b, is_wp_error( $b ) ? $b->get_error_message() : '' );
		$this->assertNotSame( $a, $b );
		$this->assertSame( $b, ( new SignatureRepository() )->get( $sig )['order_id'] );
	}

	/** Backstop: a signature on a PAID order is never moved, and the new order is refused, not sold unsigned. */
	public function test_order_is_refused_when_signature_belongs_to_paid_order() {
		[ $aid, $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$sig = $this->sign( $aid );
		$a   = $this->create_order();
		wc_get_order( $a )->payment_complete();
		WC()->session->set( 'order_awaiting_payment', $a );

		$errors = new WP_Error();
		( new Checkout() )->validate( [], $errors );
		$this->assertContains( 'anchor_agreements_unsigned', $errors->get_error_codes() );

		// Even if validation were bypassed, create_order() comes back as a checkout error.
		$b = $this->create_order();
		$this->assertWPError( $b );
		$this->assertSame( 'checkout-error', $b->get_error_code() );
		$this->assertSame( 'Please read and sign the Cancellation Policy before placing your order.', $b->get_error_message() );
		$this->assertSame( $a, ( new SignatureRepository() )->get( $sig )['order_id'] );
	}

	public function test_attach_throws_and_notes_order_when_unsigned() {
		[ , $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		$order = wc_create_order();
		$order->add_product( $p, 1 );
		$order->save();
		try {
			( new Checkout() )->attach( $order );
			$this->fail( 'attach() must refuse an order it cannot sign.' );
		} catch ( \Exception $e ) {
			$this->assertSame( 'Please read and sign the Cancellation Policy before placing your order.', $e->getMessage() );
		}
		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$this->assertStringContainsString( 'Checkout stopped', $notes[0]->content );
	}

	public function test_guest_session_migration_rekeys_signatures() {
		$repo = new SignatureRepository();
		$id   = $repo->insert( [ 'version_id' => 1, 'agreement_id' => 1, 'user_id' => null, 'signer_name' => 'A', 'method' => 'draw', 'image' => 'x', 'session_key' => 't_guest' ] );
		$other = $repo->insert( [ 'version_id' => 1, 'agreement_id' => 1, 'user_id' => null, 'signer_name' => 'A', 'method' => 'draw', 'image' => 'x', 'session_key' => 't_someone' ] );
		do_action( 'woocommerce_guest_session_to_user_id', 't_guest', '42' );
		$this->assertSame( '42', $repo->get( $id )['session_key'] );
		$this->assertSame( 42, $repo->get( $id )['user_id'] );
		$this->assertSame( 't_someone', $repo->get( $other )['session_key'] );
		$this->assertNull( $repo->get( $other )['user_id'] );
	}
}
