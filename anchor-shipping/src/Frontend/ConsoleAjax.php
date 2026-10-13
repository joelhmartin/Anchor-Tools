<?php
declare(strict_types=1);

namespace Anchor\Shipping\Frontend;

use Anchor\Shipping\Admin\Ajax;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Console-only AJAX. Label create/void/print reuse Admin\Ajax; this adds the order status buttons and the product editor. */
final class ConsoleAjax {

	/** target status => statuses it may be reached from */
	private const TRANSITIONS = [
		'completed' => [ 'processing', 'on-hold' ],
		'cancelled' => [ 'pending', 'processing', 'on-hold' ],
	];

	public function __construct() {
		\add_action( 'wp_ajax_anchor_shipping_console_status', [ $this, 'ajax_status' ] );
		\add_action( 'wp_ajax_anchor_shipping_console_product_save', [ $this, 'ajax_product_save' ] );
	}

	public function ajax_status(): void {
		if ( ! \check_ajax_referer( Ajax::NONCE, 'nonce', false ) || ! ShopManager::can_use() ) {
			\wp_send_json_error( [ 'message' => \__( 'Your session expired or you lack permission. Reload the page.', 'anchor-schema' ) ], 403 );
		}
		try {
			\wp_send_json_success( $this->handle_status( \wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above
		} catch ( \RuntimeException $e ) {
			\wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	public function ajax_product_save(): void {
		if ( ! \check_ajax_referer( Ajax::NONCE, 'nonce', false ) || ! ConsoleProducts::can_use() ) {
			\wp_send_json_error( [ 'message' => \__( 'Your session expired or you lack permission. Reload the page.', 'anchor-schema' ) ], 403 );
		}
		try {
			\wp_send_json_success( $this->handle_product_save( \wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above
		} catch ( \RuntimeException $e ) {
			\wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	/** @return array{html:string} the re-rendered product row */
	public function handle_product_save( array $post ): array {
		return [ 'html' => ( new ConsoleProducts() )->save( $post ) ];
	}

	/** @return array{status:string, label:string} */
	public function handle_status( array $post ): array {
		$to = (string) ( $post['to'] ?? '' );
		if ( ! isset( self::TRANSITIONS[ $to ] ) ) {
			throw new \RuntimeException( \__( 'That status change is not allowed.', 'anchor-schema' ) );
		}
		$order = \wc_get_order( (int) ( $post['order_id'] ?? 0 ) );
		if ( ! $order instanceof \WC_Order ) {
			throw new \RuntimeException( \__( 'Order not found.', 'anchor-schema' ) );
		}
		if ( ! $order->has_status( self::TRANSITIONS[ $to ] ) ) {
			throw new \RuntimeException( \__( 'This order cannot be moved to that status from where it is now.', 'anchor-schema' ) );
		}
		// update_status() fires woocommerce_order_status_cancelled, which is what voids the labels.
		$order->update_status( $to, \__( 'Changed from the shipping console.', 'anchor-schema' ), true );
		$status = \wc_get_order( $order->get_id() )->get_status();
		return [ 'status' => $status, 'label' => \wc_get_order_status_name( $status ) ];
	}
}
