<?php
/**
 * Anchor Tools module: Anchor Shipping.
 *
 * Carrier labels for WooCommerce orders. Carriers are adapters behind
 * Carriers\CarrierInterface (UPS first); everything Woo-facing lives in
 * Services\ and Admin\. Bootstrap only; see the spec in docs/superpowers/specs.
 *
 * @package Anchor\Shipping
 */

namespace Anchor\Shipping;

use Anchor\Shipping\Database\Migrations;
use Anchor\Shipping\Database\ShipmentRepository;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {

	public const VERSION = '1.1.0';

	public ShipmentRepository $shipments;

	public Carriers\CarrierRegistry $carriers;

	public Services\LabelStore $store;

	public Packing\Packer $packer;

	public Services\LabelService $labels;

	public Services\PaidOrderHandler $paid_orders;

	public Services\TrackingPoller $tracking;

	public Services\BulkLabels $bulk;

	public Frontend\ShopManager $console;

	private static ?Module $instance = null;

	public static function instance(): ?Module {
		return self::$instance;
	}

	public static function url( string $path ): string {
		return \plugins_url( $path, __FILE__ );
	}

	public static function dir(): string {
		return __DIR__;
	}

	public function __construct() {
		self::$instance = $this;
		Migrations::maybe_migrate();
		\add_action( 'admin_init', [ Migrations::class, 'maybe_migrate' ] );
		\add_action( 'admin_init', [ Support\Settings::class, 'ensure_labels_since' ] );
		$this->shipments = new ShipmentRepository();

		if ( ! \class_exists( 'WooCommerce' ) ) {
			\add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-warning"><p>' . \esc_html__( 'Anchor Shipping needs WooCommerce to be active.', 'anchor-schema' ) . '</p></div>';
				}
			);
			return;
		}
		// Later tasks register their services below this line.
		$this->carriers = new Carriers\CarrierRegistry();
		$this->store    = new Services\LabelStore();
		$this->packer   = new Packing\Packer();
		$this->labels   = new Services\LabelService( $this->carriers, $this->shipments, $this->store, $this->packer );
		$this->paid_orders = new Services\PaidOrderHandler( $this->labels, $this->packer );
		$this->tracking = new Services\TrackingPoller( $this->carriers, $this->shipments, $this->labels );
		new Services\VoidOnCancel( $this->labels, $this->shipments );
		new Checkout\ProtectionFields();
		\add_filter( 'woocommerce_shipping_methods', [ Checkout\RateMethod::class, 'register' ] ); // dormant until an admin adds it to a zone
		new Frontend\AccountTracking( new Support\TrackingLinks( $this->shipments, $this->carriers ) );
		new Emails\CreateLabelBlock( $this->labels );
		\add_filter( 'woocommerce_email_classes', [ Emails\Registry::class, 'register' ] );
		\add_filter( 'woocommerce_email_actions', [ Emails\Registry::class, 'actions' ] );
		if ( \is_admin() ) {
			new Admin\SettingsPage( $this->carriers );
			new Admin\ProductFields();
		}
		// The panel and its AJAX endpoints also serve the front-end console; the metabox hooks only ever fire in wp-admin.
		new Admin\OrderPanel( $this->shipments, $this->packer, $this->carriers );
		new Admin\Ajax( $this->labels, $this->shipments, $this->store, $this->packer, $this->carriers );
		$this->bulk    = new Services\BulkLabels( $this->labels, $this->shipments, $this->store );
		$this->console = new Frontend\ShopManager( $this->labels, $this->shipments, $this->carriers, $this->packer );
		new Frontend\ConsoleAjax( $this->bulk );
		if ( \is_admin() ) {
			new Admin\OrdersColumn( new Support\TrackingLinks( $this->shipments, $this->carriers ), $this->labels );
			new Admin\BulkActions( $this->console );
		}
	}
}
