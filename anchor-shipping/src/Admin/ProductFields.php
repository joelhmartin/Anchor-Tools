<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** "Default box" select on the product Shipping tab. Weight uses Woo's own field. */
final class ProductFields {

	public const META = '_anchor_shipping_box';

	public function __construct() {
		\add_action( 'woocommerce_product_options_shipping', [ $this, 'render' ] );
		\add_action( 'woocommerce_admin_process_product_object', [ $this, 'save' ] );
	}

	public function render(): void {
		$options = [ '' => \__( '— none —', 'anchor-schema' ) ];
		foreach ( Settings::boxes() as $id => $b ) {
			$options[ $id ] = $b['name'];
		}
		\woocommerce_wp_select(
			[
				'id'          => self::META,
				'label'       => \__( 'Default box', 'anchor-schema' ),
				'options'     => $options,
				'desc_tip'    => true,
				'description' => \__( 'Used to size labels automatically. Set the weight above too.', 'anchor-schema' ),
			]
		);
	}

	public function save( \WC_Product $product ): void {
		if ( isset( $_POST[ self::META ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Woo verified the product save nonce.
			$product->update_meta_data( self::META, \sanitize_key( \wp_unslash( $_POST[ self::META ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}
}
