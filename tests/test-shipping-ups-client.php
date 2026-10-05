<?php
use Anchor\Shipping\Carriers\Ups\UpsClient;
use Anchor\Shipping\Domain\CarrierError;

class Test_Shipping_Ups_Client extends Anchor_Shipping_TestCase {

	private function client( string $env = 'sandbox' ): UpsClient {
		return new UpsClient( 'cid', 'csecret', $env );
	}

	public function test_fetches_and_caches_a_token_against_the_sandbox_host() {
		$this->queue_token();
		$this->queue_response( 200, [ 'ok' => 1 ] );
		$this->queue_response( 200, [ 'ok' => 2 ] );
		$c = $this->client();
		$this->assertSame( [ 'ok' => 1 ], $c->request( 'GET', '/api/x' ) );
		$this->assertSame( [ 'ok' => 2 ], $c->request( 'GET', '/api/x' ) );
		$this->assertCount( 3, $this->requests, 'one token call, two API calls' );
		$this->assertSame( 'https://wwwcie.ups.com/security/v1/oauth/token', $this->requests[0]['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'cid:csecret' ), $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'https://wwwcie.ups.com/api/x', $this->requests[1]['url'] );
		$this->assertSame( 'Bearer tok-0', $this->requests[1]['args']['headers']['Authorization'] );
	}

	public function test_production_uses_the_production_host() {
		$this->assertSame( 'https://onlinetools.ups.com', $this->client( 'production' )->host() );
		$this->assertSame( 'https://wwwcie.ups.com', $this->client( 'bogus' )->host(), 'unknown env falls back to sandbox' );
	}

	public function test_401_refreshes_token_and_retries_once() {
		$this->queue_token();
		$this->queue_response( 401, [ 'response' => [ 'errors' => [ [ 'code' => '250002', 'message' => 'Invalid Authentication Information.' ] ] ] ] );
		$this->queue_token();
		$this->queue_response( 200, [ 'ok' => true ] );
		$this->assertSame( [ 'ok' => true ], $this->client()->request( 'POST', '/api/y', [ 'a' => 1 ] ) );
		$this->assertCount( 4, $this->requests );
	}

	public function test_second_401_is_a_non_retryable_auth_error() {
		$this->queue_token();
		$this->queue_response( 401, '{}' );
		$this->queue_token();
		$this->queue_response( 401, '{}' );
		try {
			$this->client()->request( 'GET', '/api/z' );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertSame( 'auth', $e->carrier_code );
			$this->assertFalse( $e->retryable );
			$this->assertStringNotContainsString( 'csecret', $e->getMessage() );
		}
	}

	public function test_ups_error_body_becomes_a_carrier_error_and_5xx_is_retryable() {
		$this->queue_token();
		$this->queue_response( 400, [ 'response' => [ 'errors' => [ [ 'code' => '120100', 'message' => 'Missing or invalid shipper number' ] ] ] ] );
		try {
			$this->client()->request( 'POST', '/api/ship', [] );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertSame( '120100', $e->carrier_code );
			$this->assertSame( 'Missing or invalid shipper number', $e->getMessage() );
			$this->assertFalse( $e->retryable );
		}
		$this->queue_response( 503, 'Service Unavailable' );
		try {
			$this->client()->request( 'POST', '/api/ship', [] ); // token still cached
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertTrue( $e->retryable );
		}
	}

	public function test_failed_token_call_is_an_auth_error() {
		$this->queue_response( 401, [ 'response' => [ 'errors' => [ [ 'code' => '10401', 'message' => 'ClientId is Invalid' ] ] ] ] );
		$this->expectException( CarrierError::class );
		$this->expectExceptionMessage( 'ClientId is Invalid' );
		$this->client()->request( 'GET', '/api/x' );
	}

	public function test_non_idempotent_timeout_is_uncertain_and_not_retryable() {
		$this->queue_token();
		$this->queue_response( 0, new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds' ) );
		try {
			$this->client()->request( 'POST', '/api/ship', [], false );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertSame( 'uncertain', $e->carrier_code );
			$this->assertFalse( $e->retryable );
			$this->assertStringContainsString( 'may have created this label', $e->getMessage() );
		}
	}

	public function test_non_idempotent_connect_failure_is_retryable_because_it_never_reached_ups() {
		$this->queue_token();
		$this->queue_response( 0, new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to wwwcie.ups.com' ) );
		try {
			$this->client()->request( 'POST', '/api/ship', [], false );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertSame( 'http', $e->carrier_code );
			$this->assertTrue( $e->retryable );
		}
	}

	public function test_idempotent_timeout_stays_retryable() {
		$this->queue_token();
		$this->queue_response( 0, new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
		try {
			$this->client()->request( 'DELETE', '/api/void' );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertSame( 'http', $e->carrier_code );
			$this->assertTrue( $e->retryable );
		}
	}

	public function test_non_idempotent_504_is_uncertain_but_503_stays_retryable() {
		$this->queue_token();
		$this->queue_response( 504, 'Gateway Timeout' );
		try {
			$this->client()->request( 'POST', '/api/ship', [], false );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertSame( 'uncertain', $e->carrier_code );
			$this->assertFalse( $e->retryable );
		}
		$this->queue_response( 503, 'Unavailable' );
		try {
			$this->client()->request( 'POST', '/api/ship', [], false );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertTrue( $e->retryable );
		}
	}

	/** 500/502 can come after UPS acted on a purchase: never retried. 503/429 mean "not processed". */
	public function test_non_idempotent_500_and_502_are_uncertain_but_429_stays_retryable() {
		$this->queue_token();
		foreach ( [ 500, 502 ] as $code ) {
			$this->queue_response( $code, 'Server Error' );
			try {
				$this->client()->request( 'POST', '/api/ship', [], false );
				$this->fail( 'expected CarrierError' );
			} catch ( CarrierError $e ) {
				$this->assertSame( 'uncertain', $e->carrier_code, "HTTP {$code}" );
				$this->assertFalse( $e->retryable, "HTTP {$code}" );
			}
		}
		$this->queue_response( 429, 'Too Many Requests' );
		try {
			$this->client()->request( 'POST', '/api/ship', [], false );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertNotSame( 'uncertain', $e->carrier_code );
			$this->assertTrue( $e->retryable );
		}
		$this->assertCount( 4, $this->requests, 'one token + one request each, nothing re-sent' );
	}

	public function test_idempotent_500_stays_retryable() {
		$this->queue_token();
		$this->queue_response( 500, 'Server Error' );
		try {
			$this->client()->request( 'DELETE', '/api/void' );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertNotSame( 'uncertain', $e->carrier_code );
			$this->assertTrue( $e->retryable );
		}
	}
}
