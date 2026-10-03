<?php
/**
 * Shared base case for the Anchor Shipping suite: a fake HTTP layer, a configured
 * UPS sandbox account, and product/order factories.
 *
 * @package Anchor\Shipping\Tests
 */

use Anchor\Shipping\Database\Migrations;

abstract class Anchor_Shipping_TestCase extends WP_UnitTestCase {

	/** @var array<int, array{code:int, body:string}> */
	protected array $http_queue = [];

	/** @var array<int, array{url:string, args:array}> */
	protected array $requests = [];

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed in the test environment.' );
		}
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Migrations::table() ); // phpcs:ignore
		delete_option( 'anchor_shipping_settings' );
		foreach ( [ 'sandbox', 'production' ] as $env ) {
			delete_transient( 'anchor_shipping_ups_token_' . md5( $env . '|cid' ) );
		}
		$this->http_queue = [];
		$this->requests   = [];
		add_filter( 'pre_http_request', [ $this, 'fake_http' ], 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'fake_http' ], 10 );
		parent::tear_down();
	}

	/** @param array|string $body */
	protected function queue_response( int $code, $body ): void {
		$this->http_queue[] = [ 'code' => $code, 'body' => is_string( $body ) ? $body : wp_json_encode( $body ) ];
	}

	protected function queue_token(): void {
		$this->queue_response( 200, [ 'access_token' => 'tok-' . count( $this->requests ), 'expires_in' => '14399', 'status' => 'approved' ] );
	}

	/** @return array|WP_Error */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = [ 'url' => $url, 'args' => $args ];
		$next             = array_shift( $this->http_queue );
		if ( null === $next ) {
			return new WP_Error( 'unexpected_http', 'No fake response queued for ' . $url );
		}
		return [
			'headers'  => [],
			'body'     => $next['body'],
			'response' => [ 'code' => $next['code'], 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	protected function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/shipping/' . $name . '.json' ), true );
	}

	/** Saves a complete sandbox UPS setup with one box preset. Requires Task 3. */
	protected function configure_ups( array $overrides = [] ): void {
		\Anchor\Shipping\Support\Settings::save(
			array_replace_recursive(
				[
					'carriers'  => [ 'ups' => [ 'environment' => 'sandbox', 'client_id' => 'cid', 'client_secret' => 'csecret', 'account' => 'K877V9' ] ],
					'ship_from' => [ 'name' => 'Shipping Dept', 'company' => 'DEKA Test', 'phone' => '8133208285', 'line1' => '400 North Ashley Drive', 'line2' => '', 'city' => 'Tampa', 'state' => 'FL', 'postcode' => '33602', 'country' => 'US' ],
					'boxes'     => [ [ 'id' => 'small', 'name' => 'Small', 'length' => 25.4, 'width' => 20.32, 'height' => 10.16, 'empty_weight' => 0.2, 'max_weight' => 10 ] ],
					'inbox'     => 'shipping@example.com',
				],
				$overrides
			)
		);
	}

	protected function make_product( array $args = [] ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $args['name'] ?? 'Tip pack' );
		$p->set_regular_price( (string) ( $args['price'] ?? '110' ) );
		$p->set_virtual( (bool) ( $args['virtual'] ?? false ) );
		if ( isset( $args['weight'] ) ) {
			$p->set_weight( (string) $args['weight'] );
		}
		$p->save();
		if ( isset( $args['box'] ) ) {
			update_post_meta( $p->get_id(), '_anchor_shipping_box', $args['box'] );
		}
		return $p->get_id();
	}

	/** Order with a US shipping address and a flat-rate shipping line. */
	protected function make_order( array $product_ids = [], string $status = 'pending', bool $with_shipping_line = true ): WC_Order {
		$order = wc_create_order();
		foreach ( $product_ids ?: [ $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] ) ] as $pid ) {
			$order->add_product( wc_get_product( $pid ), 1 );
		}
		$order->set_address(
			[ 'first_name' => 'Pat', 'last_name' => 'Doe', 'company' => 'Doe Dental', 'address_1' => '1 Infinite Loop', 'city' => 'Cupertino', 'state' => 'CA', 'postcode' => '95014', 'country' => 'US', 'phone' => '5555555555', 'email' => 'pat@example.com' ],
			'billing'
		);
		$order->set_address(
			[ 'first_name' => 'Pat', 'last_name' => 'Doe', 'company' => 'Doe Dental', 'address_1' => '1 Infinite Loop', 'city' => 'Cupertino', 'state' => 'CA', 'postcode' => '95014', 'country' => 'US' ],
			'shipping'
		);
		if ( $with_shipping_line ) {
			$rate = new WC_Shipping_Rate( 'flat_rate:2', 'Flat rate', '19.95', [], 'flat_rate', 2 );
			$item = new WC_Order_Item_Shipping();
			$item->set_shipping_rate( $rate );
			$order->add_item( $item );
		}
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();
		return $order;
	}
}
