<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers\Ups;

use Anchor\Shipping\Domain\CarrierError;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** OAuth client-credentials + JSON over wp_remote_request. Never logs bodies or credentials. */
final class UpsClient {

	public const HOSTS = [
		'sandbox'    => 'https://wwwcie.ups.com',
		'production' => 'https://onlinetools.ups.com',
	];

	public function __construct(
		private string $client_id,
		private string $client_secret,
		private string $environment
	) {}

	public function host(): string {
		return self::HOSTS[ $this->environment ] ?? self::HOSTS['sandbox'];
	}

	/**
	 * @param bool $idempotent false for calls that spend money (ship): a transport timeout or a
	 *                         5xx other than 503 then means "UPS may have acted", so it is
	 *                         'uncertain' and not retryable. Idempotent calls retry any 5xx.
	 */
	public function request( string $method, string $path, ?array $body = null, bool $idempotent = true ): array {
		$res = $this->send( $method, $path, $body, $this->token(), $idempotent );
		if ( 401 === $res['code'] ) {
			$this->forget_token();
			$res = $this->send( $method, $path, $body, $this->token(), $idempotent );
			if ( 401 === $res['code'] ) {
				throw new CarrierError( 'auth', \__( 'UPS rejected the API credentials. Check the Client ID and Secret.', 'anchor-schema' ) );
			}
		}
		// A purchase that hit a server error may still have gone through (500/502/504 can all
		// come after UPS acted). Only 503 and 429 mean "not processed", so only those retry.
		if ( ! $idempotent && $res['code'] >= 500 && 503 !== $res['code'] ) {
			throw $this->uncertain();
		}
		if ( $res['code'] >= 400 ) {
			throw $this->error( $res );
		}
		return $res['json'];
	}

	public function forget_token(): void {
		\delete_transient( $this->token_key() );
	}

	private function token_key(): string {
		return 'anchor_shipping_ups_token_' . md5( $this->environment . '|' . $this->client_id );
	}

	private function token(): string {
		$cached = \get_transient( $this->token_key() );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$raw = \wp_remote_post(
			$this->host() . '/security/v1/oauth/token',
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body'    => [ 'grant_type' => 'client_credentials' ],
			]
		);
		$res = $this->normalize( $raw, '/security/v1/oauth/token' );
		if ( 200 !== $res['code'] || empty( $res['json']['access_token'] ) ) {
			$e = $this->error( $res );
			throw new CarrierError( 'auth', $e->getMessage(), $e->retryable );
		}
		$ttl = max( 60, (int) ( $res['json']['expires_in'] ?? 3600 ) - 300 );
		\set_transient( $this->token_key(), (string) $res['json']['access_token'], $ttl );
		return (string) $res['json']['access_token'];
	}

	private function send( string $method, string $path, ?array $body, string $token, bool $idempotent = true ): array {
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Authorization'  => 'Bearer ' . $token,
				'Content-Type'   => 'application/json',
				'transId'        => \wp_generate_password( 16, false ),
				'transactionSrc' => 'anchor-shipping',
			],
		];
		if ( null !== $body ) {
			$args['body'] = \wp_json_encode( $body );
		}
		return $this->normalize( \wp_remote_request( $this->host() . $path, $args ), $path, $idempotent );
	}

	/** @param array|\WP_Error $raw */
	private function normalize( $raw, string $path, bool $idempotent = true ): array {
		if ( \is_wp_error( $raw ) ) {
			$this->log( 'error', $path . ' transport error: ' . $raw->get_error_message() );
			// cURL 6/7 = could not resolve/connect: the request never reached UPS, so retrying is safe.
			if ( ! $idempotent && ! preg_match( '/cURL error (6|7):/', $raw->get_error_message() ) ) {
				throw $this->uncertain();
			}
			throw new CarrierError( 'http', \__( 'Could not reach UPS. Try again in a few minutes.', 'anchor-schema' ), true );
		}
		$code = (int) \wp_remote_retrieve_response_code( $raw );
		$json = json_decode( (string) \wp_remote_retrieve_body( $raw ), true );
		$this->log( $code >= 400 ? 'warning' : 'debug', $path . ' → HTTP ' . $code );
		return [ 'code' => $code, 'json' => is_array( $json ) ? $json : [] ];
	}

	private function uncertain(): CarrierError {
		return new CarrierError( 'uncertain', \__( 'UPS did not confirm the result and may have created this label. Check Shipping History on ups.com before creating another.', 'anchor-schema' ), false );
	}

	private function error( array $res ): CarrierError {
		$first     = $res['json']['response']['errors'][0] ?? [];
		$code      = (string) ( $first['code'] ?? 'http_' . $res['code'] );
		$message   = (string) ( $first['message'] ?? sprintf( /* translators: %d: HTTP status */ \__( 'UPS returned HTTP %d.', 'anchor-schema' ), $res['code'] ) );
		$retryable = $res['code'] >= 500 || 429 === $res['code'];
		return new CarrierError( $code, $message, $retryable );
	}

	private function log( string $level, string $message ): void {
		if ( \function_exists( 'wc_get_logger' ) ) {
			\wc_get_logger()->log( $level, '[ups ' . $this->environment . '] ' . $message, [ 'source' => 'anchor-shipping' ] );
		}
	}
}
