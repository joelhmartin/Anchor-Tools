<?php
declare(strict_types=1);

namespace Anchor\Shipping\Frontend;

use Anchor\Shipping\Admin\Ajax;
use Anchor\Shipping\Module;
use Anchor\Shipping\Services\BulkLabels;
use Anchor\Shipping\Support\Urls;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Console-only AJAX. Label create/void/print reuse Admin\Ajax; this adds the order status buttons and the product editor. */
final class ConsoleAjax {

	/** target status => statuses it may be reached from */
	private const TRANSITIONS = [
		'completed' => [ 'processing', 'on-hold' ],
		'cancelled' => [ 'pending', 'processing', 'on-hold' ],
	];

	/** Orders labelled per request: each is a carrier round-trip, so a longer run is chunked by the JS to stay inside PHP/proxy timeouts. */
	public const BULK_MAX = 10;

	/** Orders that can be selected for one bulk run (the JS sends them BULK_MAX at a time). */
	public const BULK_SELECT_MAX = 50;

	public function __construct( private ?BulkLabels $bulk = null ) {
		\add_action( 'wp_ajax_anchor_shipping_bulk_create', [ $this, 'ajax_bulk_create' ] );
		\add_action( 'wp_ajax_anchor_shipping_bulk_finish', [ $this, 'ajax_bulk_finish' ] );
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

	public function ajax_bulk_create(): void {
		if ( ! \check_ajax_referer( Ajax::NONCE, 'nonce', false ) || ! ShopManager::can_use() ) {
			\wp_send_json_error( [ 'message' => \__( 'Your session expired or you lack permission. Reload the page.', 'anchor-schema' ) ], 403 );
		}
		try {
			\wp_send_json_success( $this->handle_bulk_create( \wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above
		} catch ( \RuntimeException $e ) {
			\wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	public function ajax_bulk_finish(): void {
		if ( ! \check_ajax_referer( Ajax::NONCE, 'nonce', false ) || ! ShopManager::can_use() ) {
			\wp_send_json_error( [ 'message' => \__( 'Your session expired or you lack permission. Reload the page.', 'anchor-schema' ) ], 403 );
		}
		try {
			\wp_send_json_success( $this->handle_bulk_finish( \wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above
		} catch ( \RuntimeException $e ) {
			\wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	/**
	 * Without `chunk`: one self-contained request (labels, merged file, result HTML).
	 * With `chunk`: labels only, for the JS to send BULK_MAX orders at a time; it ends with handle_bulk_finish().
	 *
	 * @return array{created:array,skipped:array,failed:array,pdf:?string,download:string,html:string}
	 */
	public function handle_bulk_create( array $post ): array {
		$rows = array_values( array_filter( (array) ( $post['rows'] ?? [] ), 'is_array' ) );
		if ( ! $rows ) {
			throw new \RuntimeException( \__( 'Select at least one order.', 'anchor-schema' ) );
		}
		if ( count( $rows ) > self::BULK_MAX ) {
			/* translators: %d: maximum orders per batch */
			throw new \RuntimeException( sprintf( \__( 'Label at most %d orders at a time.', 'anchor-schema' ), self::BULK_MAX ) );
		}
		$bulk = $this->bulk ?? Module::instance()->bulk;
		if ( ! empty( $post['chunk'] ) ) {
			return $bulk->create( $rows, false ) + [ 'download' => '', 'html' => '' ];
		}
		$res  = $bulk->create( $rows );
		$res['download'] = $res['pdf'] ? Ajax::bulk_download_url( $res['pdf'] ) : '';
		$res['html']     = ShopManager::render_template( 'bulk', [ 'result' => $res, 'back' => Urls::console() ] );
		return $res;
	}

	/**
	 * Last request of a chunked run: merges the labels created across all chunks (shipment row ids per order)
	 * into one file and returns the combined result screen.
	 *
	 * @return array{created:array,skipped:array,failed:array,pdf:?string,download:string,html:string}
	 */
	public function handle_bulk_finish( array $post ): array {
		$map = static function ( $raw, callable $clean ): array {
			$out = [];
			foreach ( is_array( $raw ) ? $raw : [] as $id => $v ) {
				if ( (int) $id > 0 ) {
					$out[ (int) $id ] = $clean( $v );
				}
			}
			return $out;
		};
		$res = [
			'created' => $map( $post['created'] ?? [], static fn( $ids ): array => array_values( array_filter( array_map( 'intval', (array) $ids ) ) ) ),
			'skipped' => $map( $post['skipped'] ?? [], static fn( $m ): string => \sanitize_text_field( (string) $m ) ),
			'failed'  => $map( $post['failed'] ?? [], static fn( $m ): string => \sanitize_text_field( (string) $m ) ),
		];
		$res['created'] = array_filter( $res['created'] );
		if ( ! $res['created'] && ! $res['skipped'] && ! $res['failed'] ) {
			throw new \RuntimeException( \__( 'Nothing to report.', 'anchor-schema' ) );
		}
		$bulk = $this->bulk ?? Module::instance()->bulk;
		$res  = $bulk->merge( $res );
		$res['download'] = $res['pdf'] ? Ajax::bulk_download_url( $res['pdf'] ) : '';
		$res['html']     = ShopManager::render_template( 'bulk', [ 'result' => $res, 'back' => Urls::console() ] );
		return $res;
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
