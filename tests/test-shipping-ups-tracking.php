<?php
use Anchor\Shipping\Carriers\Ups\UpsCarrier;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\TrackingResult;

class Test_Shipping_Ups_Tracking extends Anchor_Shipping_TestCase {

	private const NUMBER = '1ZK877V90300000001';

	public function test_each_fixture_maps_to_its_status() {
		$this->configure_ups();
		$cases = [
			'label-created'    => 'label_created',
			'in-transit'       => 'in_transit',
			'out-for-delivery' => 'out_for_delivery',
			'delivered'        => 'delivered',
			'exception'        => 'exception',
			'returned'         => 'returned',
		];
		foreach ( $cases as $fixture => $status ) {
			$this->queue_token();
			$this->queue_response( 200, $this->fixture( 'ups-track-' . $fixture ) );
			$out = ( new UpsCarrier() )->track( [ self::NUMBER ] );
			$this->assertInstanceOf( TrackingResult::class, $out[ self::NUMBER ], $fixture );
			$this->assertSame( $status, $out[ self::NUMBER ]->status, $fixture );
			$this->assertNotSame( '', $out[ self::NUMBER ]->detail, $fixture );
			delete_transient( 'anchor_shipping_ups_token_' . md5( 'sandbox|cid' ) );
		}
	}

	public function test_event_at_is_the_latest_activity_in_utc() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-track-delivered' ) );
		$r = ( new UpsCarrier() )->track( [ self::NUMBER ] )[ self::NUMBER ];
		$this->assertSame( gmmktime( 13, 42, 0, 10, 13, 2026 ), $r->event_at );
	}

	public function test_request_shape() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-track-delivered' ) );
		( new UpsCarrier() )->track( [ self::NUMBER ] );
		$req = $this->requests[1];
		$this->assertSame( 'GET', $req['args']['method'] );
		$this->assertStringContainsString( '/api/track/v1/details/' . self::NUMBER, $req['url'] );
		$this->assertSame( 'anchor-shipping', $req['args']['headers']['transactionSrc'] );
		$this->assertNotSame( '', $req['args']['headers']['transId'] );
	}

	public function test_not_found_is_unknown_on_404_and_on_200() {
		$this->configure_ups();
		foreach ( [ 404, 200 ] as $http ) {
			$this->queue_token();
			$this->queue_response( $http, $this->fixture( 'ups-track-not-found' ) );
			$r = ( new UpsCarrier() )->track( [ self::NUMBER ] )[ self::NUMBER ];
			$this->assertSame( 'unknown', $r->status, "HTTP $http" );
			$this->assertNull( $r->event_at );
			delete_transient( 'anchor_shipping_ups_token_' . md5( 'sandbox|cid' ) );
		}
	}

	public function test_two_numbers_make_two_requests() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-track-delivered' ) );
		$this->queue_response( 200, $this->fixture( 'ups-track-in-transit' ) );
		$out = ( new UpsCarrier() )->track( [ self::NUMBER, '1ZK877V90300000002' ] );
		$this->assertSame( [ self::NUMBER, '1ZK877V90300000002' ], array_keys( $out ) );
		$this->assertCount( 3, $this->requests, 'token + two lookups' );
		$this->assertStringContainsString( '1ZK877V90300000002', $this->requests[2]['url'] );
	}

	public function test_network_failure_is_a_retryable_carrier_error() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 0, new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );
		try {
			( new UpsCarrier() )->track( [ self::NUMBER ] );
			$this->fail( 'Expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertTrue( $e->retryable );
		}
	}

	public function test_auth_failure_is_a_carrier_error() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 401, [ 'response' => [ 'errors' => [ [ 'code' => '250002', 'message' => 'Invalid Authentication Information' ] ] ] ] );
		$this->queue_token();
		$this->queue_response( 401, [] );
		$this->expectException( CarrierError::class );
		( new UpsCarrier() )->track( [ self::NUMBER ] );
	}

	private function track_with_type( string $type, string $code = 'XX' ): TrackingResult {
		$fx = $this->fixture( 'ups-track-in-transit' );
		$fx['trackResponse']['shipment'][0]['package'][0]['currentStatus'] = [ 'description' => 'x', 'type' => $type, 'code' => $code ];
		$this->queue_token();
		$this->queue_response( 200, $fx );
		$r = ( new UpsCarrier() )->track( [ self::NUMBER ] )[ self::NUMBER ];
		delete_transient( 'anchor_shipping_ups_token_' . md5( 'sandbox|cid' ) );
		return $r;
	}

	public function test_mv_is_voided_and_an_unmapped_type_is_unknown_type() {
		$this->configure_ups();
		$this->assertSame( 'voided', $this->track_with_type( 'MV' )->status );
		$this->assertSame( 'label_created', $this->track_with_type( 'M' )->status );
		$this->assertSame( 'unknown_type', $this->track_with_type( 'ZZ' )->status );
	}
}
