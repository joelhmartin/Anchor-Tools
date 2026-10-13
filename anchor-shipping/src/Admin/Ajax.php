<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Services\LabelStore;
use Anchor\Shipping\Support\ParcelFactory;

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
		\add_action( 'wp_ajax_anchor_shipping_bulk_label', [ $this, 'ajax_bulk_download' ] );
	}

	public static function download_url( int $row_id ): string {
		return \add_query_arg( [ 'action' => 'anchor_shipping_label', 'id' => $row_id, '_wpnonce' => \wp_create_nonce( self::NONCE ) ], \admin_url( 'admin-ajax.php' ) );
	}

	/** Merged bulk file; `$rel` is the relative name BulkLabels::create() returned. */
	public static function bulk_download_url( string $rel ): string {
		return \add_query_arg( [ 'action' => 'anchor_shipping_bulk_label', 'file' => $rel, '_wpnonce' => \wp_create_nonce( self::NONCE ) ], \admin_url( 'admin-ajax.php' ) );
	}

	public function authorized(): bool {
		// One capability model with the console (filter anchor_shipping_console_capability, default edit_shop_orders).
		return \Anchor\Shipping\Frontend\ShopManager::can_use();
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
		$this->send_file( $dl );
	}

	public function ajax_bulk_download(): void {
		if ( ! \check_ajax_referer( self::NONCE, '_wpnonce', false ) || ! $this->authorized() ) {
			\wp_die( \esc_html__( 'You are not allowed to download this label.', 'anchor-schema' ), 403 );
		}
		try {
			$dl = $this->handle_bulk_download( \sanitize_file_name( (string) \wp_unslash( $_GET['file'] ?? '' ) ) );
		} catch ( \RuntimeException $e ) {
			\wp_die( \esc_html( $e->getMessage() ), 404 );
		}
		$this->send_file( $dl );
	}

	/** @param array{path:string, type:string, filename:string} $dl */
	private function send_file( array $dl ): void {
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
		$parcel = ParcelFactory::from_row( $post );

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
					'unrecorded_voided' => \sanitize_text_field( (string) ( $post['unrecorded_voided'] ?? '' ) ),
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

	/** @return array{path:string, type:string, filename:string} */
	public function handle_bulk_download( string $rel ): array {
		$path = LabelStore::is_bulk_name( $rel ) ? $this->store->absolute( $rel ) : null;
		if ( ! $path ) {
			throw new \RuntimeException( \__( 'Label file not found.', 'anchor-schema' ) );
		}
		$zpl = str_ends_with( $rel, '.zpl' );
		return [
			'path'     => $path,
			'type'     => $this->store->content_type( $zpl ? 'ZPL' : 'PDF' ),
			'filename' => 'labels-' . \substr( $rel, 5, 15 ) . ( $zpl ? '.zpl' : '.pdf' ),
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
