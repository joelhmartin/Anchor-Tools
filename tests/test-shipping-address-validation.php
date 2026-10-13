<?php
use Anchor\Shipping\Admin\OrderPanel;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Module;

class Test_Shipping_Address_Validation extends Anchor_Shipping_TestCase {

	private function panel( WC_Order $order ): string {
		$m = Module::instance();
		return ( new OrderPanel( $m->shipments, $m->packer, $m->carriers ) )->render_panel( $order );
	}

	private function queue_xav( string $fixture ): void {
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( $fixture ) );
	}

	private function xav_requests(): int {
		return count( array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], 'addressvalidation' ) ) );
	}

	public function test_carrier_maps_the_three_outcomes() {
		$this->configure_ups();
		$a = new Address( 'Pat Doe', '', '1 Infinite Loop', '', 'Cupertino', 'CA', '95014', 'US' );
		$c = new \Anchor\Shipping\Carriers\Ups\UpsCarrier();
		$this->assertTrue( $c->supports( 'address_validation' ) );

		$this->queue_xav( 'ups-xav-valid' );
		$this->assertSame( [ 'valid' => true, 'ambiguous' => false, 'candidates' => [] ], $c->validate_address( $a ) );

		delete_transient( 'anchor_shipping_ups_token_' . md5( 'sandbox|cid' ) );
		$this->queue_xav( 'ups-xav-ambiguous' );
		$r = $c->validate_address( $a );
		$this->assertFalse( $r['valid'] );
		$this->assertTrue( $r['ambiguous'] );
		$this->assertCount( 2, $r['candidates'] );
		$this->assertSame( '400 N ASHLEY DR', $r['candidates'][0]->line1 );
		$this->assertSame( '33602-5200', $r['candidates'][0]->postcode );

		delete_transient( 'anchor_shipping_ups_token_' . md5( 'sandbox|cid' ) );
		$this->queue_xav( 'ups-xav-invalid' );
		$this->assertSame( [ 'valid' => false, 'ambiguous' => false, 'candidates' => [] ], $c->validate_address( $a ) );
	}

	public function test_request_shape() {
		$this->configure_ups();
		$this->queue_xav( 'ups-xav-valid' );
		( new \Anchor\Shipping\Carriers\Ups\UpsCarrier() )->validate_address( new Address( 'Pat Doe', '', '1 Infinite Loop', 'Ste 2', 'Cupertino', 'CA', '95014', 'US' ) );
		$req  = end( $this->requests );
		$body = json_decode( $req['args']['body'], true );
		$this->assertStringEndsWith( '/api/addressvalidation/v2/1', $req['url'] );
		$k = $body['XAVRequest']['AddressKeyFormat'];
		$this->assertSame( [ '1 Infinite Loop', 'Ste 2' ], $k['AddressLine'] );
		$this->assertSame( 'Cupertino', $k['PoliticalDivision2'] );
		$this->assertSame( '95014', $k['PostcodePrimaryLow'] );
	}

	public function test_invalid_address_shows_warning_and_create_button() {
		$this->configure_ups();
		$order = $this->make_order();
		$this->queue_xav( 'ups-xav-invalid' );
		$html = $this->panel( $order );
		$this->assertStringContainsString( 'anchor-shipping-address-warning', $html );
		$this->assertStringContainsString( 'anchor-shipping-submit', $html );
	}

	public function test_ambiguous_warning_lists_suggestions() {
		$this->configure_ups();
		$order = $this->make_order();
		$this->queue_xav( 'ups-xav-ambiguous' );
		$html = $this->panel( $order );
		$this->assertStringContainsString( 'anchor-shipping-address-warning', $html );
		$this->assertStringContainsString( '400 N ASHLEY DR', $html );
		$this->assertStringContainsString( 'anchor-shipping-submit', $html );
	}

	public function test_valid_address_shows_no_warning() {
		$this->configure_ups();
		$order = $this->make_order();
		$this->queue_xav( 'ups-xav-valid' );
		$this->assertStringNotContainsString( 'anchor-shipping-address-warning', $this->panel( $order ) );
	}

	public function test_result_is_cached_and_revalidated_when_address_changes() {
		$this->configure_ups();
		$order = $this->make_order();
		$this->queue_xav( 'ups-xav-invalid' );
		$this->panel( $order );
		$this->assertSame( 1, $this->xav_requests() );

		$order = wc_get_order( $order->get_id() );
		$this->assertIsArray( $order->get_meta( '_anchor_shipping_xav' ) );
		$this->assertStringContainsString( 'anchor-shipping-address-warning', $this->panel( $order ), 'warning served from cache' );
		$this->assertSame( 1, $this->xav_requests(), 'no second UPS call' );

		$order->set_shipping_address_1( '2 Infinite Loop' );
		$order->save();
		$this->queue_xav( 'ups-xav-valid' );
		$this->assertStringNotContainsString( 'anchor-shipping-address-warning', $this->panel( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 2, $this->xav_requests() );
	}

	public function test_carrier_error_shows_no_warning_and_does_not_throw() {
		$this->configure_ups();
		$order = $this->make_order();
		$this->queue_token();
		$this->queue_response( 500, '{"response":{"errors":[{"code":"250002","message":"Invalid Authentication Information"}]}}' );
		$html = $this->panel( $order );
		$this->assertStringNotContainsString( 'anchor-shipping-address-warning', $html );
		$this->assertStringContainsString( 'anchor-shipping-submit', $html );
	}

	public function test_unconfigured_carrier_and_non_us_skip_validation() {
		$order = $this->make_order();
		$this->assertStringNotContainsString( 'anchor-shipping-address-warning', $this->panel( $order ) );
		$this->configure_ups();
		$order->set_shipping_country( 'CA' );
		$order->save();
		$this->panel( wc_get_order( $order->get_id() ) );
		$this->assertSame( 0, $this->xav_requests() );
	}
}
