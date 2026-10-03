<?php
use Anchor\Shipping\Carriers\Ups\UpsCarrier;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Domain\ShipmentRequest;
use Anchor\Shipping\Support\Settings;

class Test_Shipping_Ups_Carrier extends Anchor_Shipping_TestCase {

	private function request( ?array $parcels = null, float $declared = 0.0, bool $signature = false ): ShipmentRequest {
		$from = new Address( 'Shipping', 'DEKA Test', '400 North Ashley Drive', '', 'Tampa', 'FL', '33602', 'US', '8133208285' );
		$to   = new Address( 'Pat Doe', 'Doe Dental', '1 Infinite Loop', '', 'Cupertino', 'CA', '95014', 'US', '5555555555' );
		return new ShipmentRequest( $from, $to, $parcels ?? [ new Parcel( 1.0, 25.4, 20.32, 10.16, 'small' ) ], '03', 'Order 123', $declared, $signature );
	}

	public function test_ship_body_bills_the_account_and_never_emails_the_customer() {
		$this->configure_ups();
		$account = Settings::carrier( 'ups' )['account'];
		$body    = ( new UpsCarrier() )->build_ship_body( $this->request(), $account, 'GIF' );
		$ship    = $body['ShipmentRequest']['Shipment'];

		$this->assertSame( $account, $ship['Shipper']['ShipperNumber'] );
		$this->assertSame( $account, $ship['PaymentInformation']['ShipmentCharge']['BillShipper']['AccountNumber'] );
		$this->assertSame( '03', $ship['Service']['Code'] );
		$this->assertSame( '2.2', $ship['Package'][0]['PackageWeight']['Weight'] );
		$this->assertSame( [ '10', '8', '4' ], [ $ship['Package'][0]['Dimensions']['Length'], $ship['Package'][0]['Dimensions']['Width'], $ship['Package'][0]['Dimensions']['Height'] ] );
		$this->assertSame( 'Order 123', $ship['Package'][0]['ReferenceNumber']['Value'] );
		$this->assertArrayNotHasKey( 'EMailAddress', $ship['ShipTo'] );
		$this->assertArrayNotHasKey( 'ShipmentServiceOptions', $ship );
		$this->assertArrayHasKey( 'NegotiatedRatesIndicator', $ship['ShipmentRatingOptions'] );
		$this->assertSame( 'GIF', $body['ShipmentRequest']['LabelSpecification']['LabelImageFormat']['Code'] );
	}

	public function test_declared_value_and_signature_become_package_service_options() {
		$body = ( new UpsCarrier() )->build_ship_body( $this->request( null, 1250.0, true ), 'K877V9', 'ZPL' );
		$pso  = $body['ShipmentRequest']['Shipment']['Package'][0]['PackageServiceOptions'];
		$this->assertSame( '1250.00', $pso['DeclaredValue']['MonetaryValue'] );
		$this->assertSame( '2', $pso['DeliveryConfirmation']['DCISType'] );
		$this->assertSame( 'ZPL', $body['ShipmentRequest']['LabelSpecification']['LabelImageFormat']['Code'] );
		$this->assertSame( [ 'Height' => '6', 'Width' => '4' ], $body['ShipmentRequest']['LabelSpecification']['LabelStockSize'] );
	}

	public function test_declared_value_on_multi_parcel_is_split_across_packages() {
		$parcels = [ new Parcel( 1, 10, 10, 10 ), new Parcel( 1, 10, 10, 10 ) ];
		$body    = ( new UpsCarrier() )->build_ship_body( $this->request( $parcels, 1001.0 ), 'K877V9', 'GIF' );
		$this->assertSame( '500.50', $body['ShipmentRequest']['Shipment']['Package'][0]['PackageServiceOptions']['DeclaredValue']['MonetaryValue'] );
		$this->assertSame( '500.50', $body['ShipmentRequest']['Shipment']['Package'][1]['PackageServiceOptions']['DeclaredValue']['MonetaryValue'] );
	}

	public function test_create_label_parses_single_package_with_negotiated_cost() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		$r = ( new UpsCarrier() )->create_label( $this->request() );
		$this->assertSame( '1ZK877V90300000001', $r->shipment_id );
		$this->assertCount( 1, $r->packages );
		$this->assertSame( 'GIF', $r->packages[0]->format );
		$this->assertStringStartsWith( 'GIF89a', $r->packages[0]->bytes );
		$this->assertSame( 11.66, $r->cost );
		$this->assertStringEndsWith( '/api/shipments/v2409/ship', $this->requests[1]['url'] );
	}

	public function test_create_label_parses_multi_package_list() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-multi' ) );
		$r = ( new UpsCarrier() )->create_label( $this->request( [ new Parcel( 1, 10, 10, 10 ), new Parcel( 1, 10, 10, 10 ) ] ) );
		$this->assertSame( [ '1ZK877V90300000010', '1ZK877V90300000028' ], array_map( static fn( $p ) => $p->tracking_number, $r->packages ) );
		$this->assertSame( 40.10, $r->cost, 'falls back to published total when no negotiated rate' );
	}

	public function test_missing_credentials_fail_before_any_http() {
		$this->expectException( CarrierError::class );
		try {
			( new UpsCarrier() )->create_label( $this->request() );
		} finally {
			$this->assertCount( 0, $this->requests );
		}
	}

	public function test_void_calls_the_cancel_endpoint() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-void-ok' ) );
		( new UpsCarrier() )->void_label( '1ZK877V90300000001' );
		$this->assertSame( 'DELETE', $this->requests[1]['args']['method'] );
		$this->assertStringEndsWith( '/api/shipments/v2409/void/cancel/1ZK877V90300000001', $this->requests[1]['url'] );
	}

	public function test_label_without_image_is_rejected_with_the_shipment_id() {
		$this->configure_ups();
		$this->queue_token();
		$json = $this->fixture( 'ups-ship-single' );
		unset( $json['ShipmentResponse']['ShipmentResults']['PackageResults']['ShippingLabel']['GraphicImage'] );
		$this->queue_response( 200, $json );
		try {
			( new UpsCarrier() )->create_label( $this->request() );
			$this->fail( 'Expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertSame( 'parse', $e->carrier_code );
			$this->assertStringContainsString( '1ZK877V90300000001', $e->getMessage() );
		}
	}

	public function test_package_count_mismatch_is_rejected() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		try {
			( new UpsCarrier() )->create_label( $this->request( [ new Parcel( 1, 10, 10, 10 ), new Parcel( 1, 10, 10, 10 ) ] ) );
			$this->fail( 'Expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertSame( 'parse', $e->carrier_code );
			$this->assertStringContainsString( '1ZK877V90300000001', $e->getMessage() );
		}
	}
}
