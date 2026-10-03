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

	public const VERSION = '1.0.0';

	public ShipmentRepository $shipments;

	public Carriers\CarrierRegistry $carriers;

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
		if ( \is_admin() ) {
			new Admin\SettingsPage( $this->carriers );
			new Admin\ProductFields();
		}
	}
}
