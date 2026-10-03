<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Services\LabelStore;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** wp_ajax wrappers do nonce + capability; handle_* do the work and are unit-tested directly. */
final class Ajax {

	public const NONCE = 'anchor_shipping';

	public function __construct(
		private LabelService $labels,
		private ShipmentRepository $shipments,
		private LabelStore $store,
		private Packer $packer,
		private CarrierRegistry $carriers
	) {
		\add_action( 'wp_ajax_anchor_shipping_create_label', [ $this, 'ajax_create' ] );
		\add_action( 'wp_ajax_anchor_shipping_void_label', [ $this, 'ajax_void' ] );
		\add_action( 'wp_ajax_anchor_shipping_label', [ $this, 'ajax_download' ] );
	}

	public static function download_url( int $row_id ): string {
		return \add_query_arg( [ 'action' => 'anchor_shipping_label', 'id' => $row_id, '_wpnonce' => \wp_create_nonce( self::NONCE ) ], \admin_url( 'admin-ajax.php' ) );
	}

	public function authorized(): bool {
		return \current_user_can( 'edit_shop_orders' );
	}

	public function ajax_create(): void {
		$this->json( fn() => $this->handle_create( \wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in json()
	}

	public function ajax_void(): void {
		$this->json( fn() => $this->handle_void( \wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in json()
	}

	public function ajax_download(): void {
		if ( ! \check_ajax_referer( self::NONCE, '_wpnonce', false ) || ! $this->authorized() ) {
			\wp_die( \esc_html__( 'You are not allowed to download this label.', 'anchor-schema' ), 403 );
		}
		try {
			$dl = $this->handle_download( (int) ( $_GET['id'] ?? 0 ) );
		} catch ( \RuntimeException $e ) {
			\wp_die( \esc_html( $e->getMessage() ), 404 );
		}
		\nocache_headers();
		header( 'Content-Type: ' . $dl['type'] );
		header( 'Content-Disposition: inline; filename="' . $dl['filename'] . '"' );
		readfile( $dl['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public function handle_create( array $post ): array {
		$order = \wc_get_order( (int) ( $post['order_id'] ?? 0 ) );
		if ( ! $order ) {
			throw new \RuntimeException( \__( 'Order not found.', 'anchor-schema' ) );
		}
		$weight = (float) ( $post['weight'] ?? 0 );
		if ( $weight <= 0 ) {
			throw new \RuntimeException( \__( 'Enter the package weight.', 'anchor-schema' ) );
		}
		$box_id = (string) ( $post['box'] ?? 'custom' );
		$boxes  = Settings::boxes();
		if ( 'custom' !== $box_id && isset( $boxes[ $box_id ] ) ) {
			$b      = $boxes[ $box_id ];
			$parcel = Parcel::from_store_units( $weight, (float) $b['length'], (float) $b['width'], (float) $b['height'], $box_id );
		} else {
			$dims = array_map( static fn( $k ) => (float) ( $post[ $k ] ?? 0 ), [ 'length', 'width', 'height' ] );
			if ( min( $dims ) <= 0 ) {
				throw new \RuntimeException( \__( 'Enter length, width and height for a custom box.', 'anchor-schema' ) );
			}
			$parcel = Parcel::from_store_units( $weight, $dims[0], $dims[1], $dims[2] );
		}

		$subtotal = $this->packer->shippable_subtotal( $order );
		try {
			$this->labels->create_for_order(
				$order,
				[ $parcel ],
				'manual',
				[
					'carrier'        => \sanitize_key( (string) ( $post['carrier'] ?? '' ) ) ?: null,
					'service'        => \sanitize_text_field( (string) ( $post['service'] ?? '' ) ) ?: null,
					'additional'     => ! empty( $post['additional'] ),
					'declared_value' => ! empty( $post['insure'] ) ? $subtotal : 0.0,
					'signature'      => ! empty( $post['signature'] ),
				]
			);
		} catch ( \RuntimeException | \LogicException $e ) {
			throw new \RuntimeException( $e->getMessage() );
		}
		return [ 'html' => $this->panel( $order ) ];
	}

	public function handle_void( array $post ): array {
		$row = $this->shipments->find( (int) ( $post['id'] ?? 0 ) );
		if ( ! $row ) {
			throw new \RuntimeException( \__( 'Label not found.', 'anchor-schema' ) );
		}
		try {
			$this->labels->void_shipment( (int) $row['id'] );
		} catch ( CarrierError $e ) {
			throw new \RuntimeException( $e->getMessage() );
		}
		return [ 'html' => $this->panel( \wc_get_order( (int) $row['order_id'] ) ) ];
	}

	/** @return array{path:string, type:string, filename:string} */
	public function handle_download( int $row_id ): array {
		$row  = $this->shipments->find( $row_id );
		$path = $row ? $this->store->absolute( $row['label_path'] ) : null;
		if ( ! $row || ! $path ) {
			throw new \RuntimeException( \__( 'Label file not found.', 'anchor-schema' ) );
		}
		return [
			'path'     => $path,
			'type'     => $this->store->content_type( $row['label_format'] ),
			'filename' => 'label-' . preg_replace( '/[^A-Za-z0-9]/', '', $row['tracking_number'] ) . ( 'ZPL' === $row['label_format'] ? '.zpl' : '.pdf' ),
		];
	}

	private function panel( \WC_Order $order ): string {
		return ( new OrderPanel( $this->shipments, $this->packer, $this->carriers ) )->render_panel( $order );
	}

	private function json( callable $fn ): void {
		if ( ! \check_ajax_referer( self::NONCE, 'nonce', false ) || ! $this->authorized() ) {
			\wp_send_json_error( [ 'message' => \__( 'Your session expired or you lack permission. Reload the page.', 'anchor-schema' ) ], 403 );
		}
		try {
			\wp_send_json_success( $fn() );
		} catch ( \RuntimeException $e ) {
			\wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}
}
