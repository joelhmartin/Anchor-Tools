<?php
use Anchor\Shipping\Carriers\Ups\UpsCarrier;
use Anchor\Shipping\Checkout\RateMethod;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Domain\ShipmentRequest;
use Anchor\Shipping\Packing\Packer;

class Test_Shipping_Rates extends Anchor_Shipping_TestCase {

	public function set_up() {
		parent::set_up();
		update_option( 'woocommerce_weight_unit', 'kg' );
		update_option( 'woocommerce_dimension_unit', 'cm' );
		$this->configure_ups();
	}

	private function rate_request(): ShipmentRequest {
		$from = new Address( 'Shipping', 'DEKA Test', '400 North Ashley Drive', '', 'Tampa', 'FL', '33602', 'US', '8133208285' );
		$to   = new Address( 'Customer', '', '', '', 'Cupertino', 'CA', '95014', 'US' );
		return new ShipmentRequest( $from, $to, [ new Parcel( 1.0, 25.4, 20.32, 10.16, 'small' ) ], '', '' );
	}

	/** A WC shipping package for $product_ids x1 shipped to California. */
	private function package( array $product_ids, string $postcode = '95014' ): array {
		$contents = [];
		foreach ( $product_ids as $i => $pid ) {
			$p                    = wc_get_product( $pid );
			$contents[ 'k' . $i ] = [ 'key' => 'k' . $i, 'product_id' => $pid, 'variation_id' => 0, 'quantity' => 1, 'data' => $p, 'line_subtotal' => (float) $p->get_price(), 'line_total' => (float) $p->get_price() ];
		}
		return [ 'contents' => $contents, 'destination' => [ 'country' => 'US', 'state' => 'CA', 'postcode' => $postcode, 'city' => 'Cupertino', 'address' => '1 Infinite Loop', 'address_1' => '1 Infinite Loop', 'address_2' => '' ] ];
	}

	private function method( array $settings = [] ): RateMethod {
		$m = new RateMethod( 1 );
		$m->instance_settings = array_merge( [ 'services' => [ '03', '02' ], 'markup_type' => 'none', 'markup_amount' => '0' ], $settings );
		return $m;
	}

	private function amounts( RateMethod $m ): array {
		$out = [];
		foreach ( $m->rates as $r ) {
			$out[ $r->get_meta_data()['service'] ?? $r->get_id() ] = (float) $r->get_cost();
		}
		return $out;
	}

	public function test_shop_request_asks_ups_for_negotiated_rates_on_the_same_shipment_description_as_labels() {
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-rate-shop' ) );
		( new UpsCarrier() )->rate( $this->rate_request() );
		$this->assertSame( 6, (int) $this->requests[0]['args']['timeout'], 'token fetch bounded' );
		$this->assertSame( 6, (int) $this->requests[1]['args']['timeout'], 'rating call bounded' );
		$this->assertStringEndsWith( '/api/rating/v2409/Shop', $this->requests[1]['url'] );
		$body = json_decode( $this->requests[1]['args']['body'], true )['RateRequest'];
		$this->assertSame( 'Shop', $body['Request']['RequestOption'] );
		$s = $body['Shipment'];
		$this->assertArrayHasKey( 'NegotiatedRatesIndicator', $s['ShipmentRatingOptions'] );
		$this->assertSame( 'K877V9', $s['Shipper']['ShipperNumber'] );
		$this->assertSame( '95014', $s['ShipTo']['Address']['PostalCode'] );
		$this->assertSame( '02', $s['Package'][0]['PackagingType']['Code'] );
		$this->assertSame( '2.2', $s['Package'][0]['PackageWeight']['Weight'] );
		$this->assertArrayNotHasKey( 'ReferenceNumber', $s['Package'][0] );
	}

	public function test_negotiated_amount_is_preferred_and_published_is_the_fallback() {
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-rate-shop' ) );
		$q = ( new UpsCarrier() )->rate( $this->rate_request() );
		$this->assertCount( 3, $q );
		$this->assertSame( [ '03', 9.87, true, 'UPS Ground' ], [ $q[0]->service, $q[0]->amount, $q[0]->negotiated, $q[0]->label ] );
		$this->assertSame( [ '01', 64.0, false ], [ $q[2]->service, $q[2]->amount, $q[2]->negotiated ] );
	}

	public function test_pack_cart_uses_the_same_rule_as_pack_order() {
		$a = $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] );
		$b = $this->make_product( [ 'weight' => 0.4, 'box' => 'small' ] );
		$from_cart  = ( new Packer() )->pack_cart( $this->package( [ $a, $b ] )['contents'] );
		$from_order = ( new Packer() )->pack_order( $this->make_order( [ $a, $b ] ) );
		$this->assertTrue( $from_cart->ok() );
		$this->assertEquals( $from_order->parcels, $from_cart->parcels );
		$bad = $this->make_product( [ 'name' => 'No weight', 'box' => 'small' ] );
		$this->assertFalse( ( new Packer() )->pack_cart( $this->package( [ $bad ] )['contents'] )->ok() );
	}

	public function test_method_offers_only_selected_services_at_negotiated_cost() {
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-rate-shop' ) );
		$m = $this->method();
		$m->calculate_shipping( $this->package( [ $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] ) ] ) );
		$this->assertSame( [ '03' => 9.87, '02' => 17.25 ], $this->amounts( $m ) );
	}

	public function test_flat_and_percent_markup() {
		$pkg = $this->package( [ $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] ) ] );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-rate-shop' ) );
		$m = $this->method( [ 'markup_type' => 'flat', 'markup_amount' => '2.50' ] );
		$m->calculate_shipping( $pkg );
		$this->assertEqualsWithDelta( 12.37, $this->amounts( $m )['03'], 0.001 );

		delete_transient( $m->cache_key( $pkg ) );
		$this->queue_response( 200, $this->fixture( 'ups-rate-shop' ) );
		$m = $this->method( [ 'markup_type' => 'percent', 'markup_amount' => '10' ] );
		$m->calculate_shipping( $pkg );
		$this->assertEqualsWithDelta( 10.86, $this->amounts( $m )['03'], 0.001 );
	}

	public function test_rates_are_cached_per_cart_and_destination() {
		$pkg = $this->package( [ $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] ) ] );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-rate-shop' ) );
		$this->method()->calculate_shipping( $pkg );
		$n = count( $this->requests );
		$m = $this->method();
		$m->calculate_shipping( $pkg );
		$this->assertSame( $n, count( $this->requests ), 'second call served from the transient' );
		$this->assertCount( 2, $m->rates );

		$this->queue_response( 200, $this->fixture( 'ups-rate-shop' ) );
		$other = $pkg;
		$other['destination']['postcode'] = '10001';
		$this->method()->calculate_shipping( $other );
		$this->assertSame( $n + 1, count( $this->requests ), 'a different destination is a different key' );
	}

	public function test_unpackable_cart_offers_nothing_and_never_calls_ups() {
		$m = $this->method();
		$m->calculate_shipping( $this->package( [ $this->make_product( [ 'name' => 'No weight', 'box' => 'small' ] ) ] ) );
		$this->assertSame( [], $m->rates );
		$this->assertSame( [], $this->requests );
	}

	public function test_carrier_error_offers_nothing_and_is_negatively_cached() {
		$pkg = $this->package( [ $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] ) ] );
		$this->queue_token();
		$this->queue_response( 500, '{"response":{"errors":[{"code":"250002","message":"Invalid Authentication Information"}]}}' );
		$m = $this->method();
		$m->calculate_shipping( $pkg );
		$this->assertSame( [], $m->rates );

		$n = count( $this->requests );
		$m = $this->method();
		$m->calculate_shipping( $pkg );
		$this->assertSame( [], $m->rates );
		$this->assertSame( $n, count( $this->requests ), 'failure is negatively cached' );
	}

	public function test_incomplete_destination_offers_nothing() {
		$m   = $this->method();
		$pkg = $this->package( [ $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] ) ], '' );
		$m->calculate_shipping( $pkg );
		$this->assertSame( [], $m->rates );
		$this->assertSame( [], $this->requests );
	}

	public function test_method_is_registered_but_in_no_zone() {
		$this->assertArrayHasKey( 'anchor_shipping_rates', WC()->shipping()->get_shipping_method_class_names() );
		foreach ( WC_Data_Store::load( 'shipping-zone' )->get_zones() as $z ) {
			foreach ( ( new WC_Shipping_Zone( $z['id'] ) )->get_shipping_methods() as $method ) {
				$this->assertNotSame( 'anchor_shipping_rates', $method->id );
			}
		}
	}

	public function test_instance_settings_save_hook_is_registered() {
		$m = new RateMethod( 7 );
		$this->assertNotFalse( has_action( 'woocommerce_update_options_shipping_' . RateMethod::ID, [ $m, 'process_admin_options' ] ) );
	}

	public function test_cache_key_changes_with_product_weight_and_ship_from() {
		$pid = $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] );
		$m   = $this->method();
		$k1  = $m->cache_key( $this->package( [ $pid ] ) );
		$this->assertSame( $k1, $m->cache_key( $this->package( [ $pid ] ) ), 'stable' );

		$p = wc_get_product( $pid );
		$p->set_weight( '0.9' );
		$p->save();
		$k2 = $m->cache_key( $this->package( [ $pid ] ) );
		$this->assertNotSame( $k1, $k2, 'weight is part of the key' );

		$s                       = \Anchor\Shipping\Support\Settings::all();
		$s['ship_from']['line1'] = '1 Different Street';
		update_option( \Anchor\Shipping\Support\Settings::OPTION, $s );
		$this->assertNotSame( $k2, $m->cache_key( $this->package( [ $pid ] ) ), 'ship-from is part of the key' );
	}

	public function test_negative_markup_is_rejected_on_save_and_clamped_at_use() {
		$m = $this->method();
		$this->assertSame( '0', $m->validate_markup_amount_field( 'markup_amount', '-5' ) );
		$this->assertSame( '2.5', $m->validate_markup_amount_field( 'markup_amount', '2.5' ) );

		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-rate-shop' ) );
		$m = $this->method( [ 'markup_type' => 'flat', 'markup_amount' => '-3' ] );
		$m->calculate_shipping( $this->package( [ $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] ) ] ) );
		$this->assertEqualsWithDelta( 9.87, $this->amounts( $m )['03'], 0.001 );
	}

	public function test_a_cart_that_makes_parcel_throw_offers_no_rates_without_throwing() {
		$this->configure_ups( [ 'boxes' => [ [ 'id' => 'tiny', 'name' => 'Tiny', 'length' => 10, 'width' => 10, 'height' => 10, 'empty_weight' => 0, 'max_weight' => 5 ] ] ] );
		$pid = $this->make_product( [ 'weight' => 0.0004, 'box' => 'tiny' ] ); // box+item round to 0 kg: Parcel throws
		$pkg = $this->package( [ $pid ] );
		$m   = $this->method();
		$m->calculate_shipping( $pkg );
		$this->assertSame( [], $m->rates );
		$this->assertSame( [], $this->requests );
		$this->assertIsString( $m->cache_key( $pkg ) );
	}
}
