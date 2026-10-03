<?php
// tests/test-agreements-signing.php
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Frontend\SigningEndpoint;
use Anchor\Agreements\Services\SignatureCheck;

class Test_Agreements_Signing extends WP_UnitTestCase {
	const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
	private int $agreement;
	private WC_Product_Simple $product;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->cart = new WC_Cart();
		$this->agreement = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'Cancellation Policy', 'post_content' => 'Terms' ] );
		$this->product   = new WC_Product_Simple();
		$this->product->set_regular_price( '10' );
		$this->product->update_meta_data( '_anchor_agreement_required', 'yes' );
		$this->product->update_meta_data( '_anchor_agreement_id', $this->agreement );
		$this->product->save();
		WC()->cart->add_to_cart( $this->product->get_id() );
	}

	private function input( array $over = [] ): array {
		return array_merge( [ 'agreement_id' => $this->agreement, 'name' => 'Pari Example', 'method' => 'draw', 'font' => '', 'image' => self::PNG, 'consent' => '1' ], $over );
	}

	public function test_valid_signature_is_stored_and_remembered() {
		$res = ( new SigningEndpoint() )->handle( $this->input() );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 0, $res['remaining'] );
		$this->assertSame( [ $this->agreement => $res['signature_id'] ], SignatureCheck::session_map() );
		$this->assertSame( [], ( new SignatureCheck() )->unsigned( [ $this->agreement => $this->product->get_id() ] ) );
	}

	/** @dataProvider rejects */
	public function test_rejects_bad_input( array $over, string $error ) {
		$res = ( new SigningEndpoint() )->handle( $this->input( $over ) );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( $error, $res['error'] );
	}

	public function rejects(): array {
		return [
			'no consent'       => [ [ 'consent' => '' ], 'consent' ],
			'empty name'       => [ [ 'name' => '  ' ], 'name' ],
			'bad method'       => [ [ 'method' => 'stamp' ], 'method' ],
			'bad font'         => [ [ 'method' => 'generate', 'font' => 'comic-sans' ], 'font' ],
			'bad image'        => [ [ 'image' => 'data:image/svg+xml;base64,PHN2Zz4=' ], 'image' ],
			'not in cart'      => [ [ 'agreement_id' => 999999 ], 'agreement' ],
		];
	}

	public function test_rate_limit() {
		$ep = new SigningEndpoint();
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( $ep->handle( $this->input() )['ok'] );
		}
		$this->assertSame( 'rate_limited', $ep->handle( $this->input() )['error'] );
	}

	public function test_signature_older_than_window_is_unsigned() {
		global $wpdb;
		$res = ( new SigningEndpoint() )->handle( $this->input() );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \Anchor\Agreements\Database\Migrations::table( 'signatures' ) . ' SET signed_at = %s WHERE id = %d', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), $res['signature_id'] ) );
		$this->assertSame( [ $this->agreement ], ( new SignatureCheck() )->unsigned( [ $this->agreement => 1 ] ) );
	}

	public function test_signature_attached_to_awaiting_order_still_counts() {
		$res = ( new SigningEndpoint() )->handle( $this->input() );
		( new SignatureRepository() )->attach( $res['signature_id'], 555 );
		$check = new SignatureCheck();
		$this->assertSame( [], $check->unsigned( [ $this->agreement => 1 ], 555 ) );
		$this->assertSame( [ $this->agreement ], $check->unsigned( [ $this->agreement => 1 ], 0 ) );
	}
}
