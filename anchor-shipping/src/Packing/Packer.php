<?php
declare(strict_types=1);

namespace Anchor\Shipping\Packing;

use Anchor\Shipping\Admin\ProductFields;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * One box per order: items' weights + the largest of their default boxes. Anything
 * it can't size safely returns a reason instead of a guess.
 */
final class Packer {

	/** @param array|null $boxes null = read Settings::boxes() at pack time, so settings saved later in the request apply */
	public function __construct( private ?array $boxes = null ) {}

	/** @return array<int, array{product:\WC_Product, qty:int, value:float}> */
	public function shippable_items( \WC_Order $order ): array {
		$out = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			if ( $product && $product->needs_shipping() ) {
				$out[] = [ 'product' => $product, 'qty' => (int) $item->get_quantity(), 'value' => (float) $item->get_subtotal() ];
			}
		}
		return $out;
	}

	/**
	 * Pre-discount value of the lines that need shipping (item subtotal). Must agree with
	 * ProtectionFields::cart_shippable_subtotal(), which uses the cart's line_subtotal:
	 * the checkout fee and the label's declared value are computed from the same number.
	 */
	public function shippable_subtotal( \WC_Order $order ): float {
		return round( array_sum( array_column( $this->shippable_items( $order ), 'value' ) ), 2 );
	}

	public function pack_order( \WC_Order $order ): PackResult {
		$boxes    = $this->boxes ?? Settings::boxes();
		$items    = $this->shippable_items( $order );
		$problems = [];
		$weight   = 0.0; // store units
		$box      = null;

		foreach ( $items as $it ) {
			$product = $it['product'];
			$name    = $product->get_name();
			$w       = (float) $product->get_weight();
			$box_id  = (string) \get_post_meta( $product->get_parent_id() ?: $product->get_id(), ProductFields::META, true );

			if ( $w <= 0 ) {
				/* translators: %s: product name */
				$problems[] = sprintf( \__( '“%s” has no weight.', 'anchor-schema' ), $name );
			}
			if ( '' === $box_id ) {
				/* translators: %s: product name */
				$problems[] = sprintf( \__( '“%s” has no default box.', 'anchor-schema' ), $name );
			} elseif ( ! isset( $boxes[ $box_id ] ) ) {
				/* translators: %s: product name */
				$problems[] = sprintf( \__( '“%s” uses a box that no longer exists.', 'anchor-schema' ), $name );
			} elseif ( ! $box || $this->volume( $boxes[ $box_id ] ) > $this->volume( $box ) ) {
				$box = $boxes[ $box_id ];
			}
			$weight += $w * $it['qty'];
		}

		if ( ! $items ) {
			return new PackResult( [], \__( 'Nothing in this order needs shipping.', 'anchor-schema' ), null );
		}

		$suggestion = $box ? [ 'box' => (string) $box['id'], 'weight' => round( $weight + (float) $box['empty_weight'], 3 ) ] : null;

		if ( $box && ! $problems && (float) $box['max_weight'] > 0 && $weight + (float) $box['empty_weight'] > (float) $box['max_weight'] ) {
			/* translators: %s: box name */
			$problems[] = sprintf( \__( 'Too heavy for one %s box.', 'anchor-schema' ), $box['name'] );
		}
		if ( $problems ) {
			return new PackResult( [], implode( ' ', $problems ), $suggestion );
		}

		return new PackResult(
			[ Parcel::from_store_units( $suggestion['weight'], (float) $box['length'], (float) $box['width'], (float) $box['height'], (string) $box['id'] ) ],
			'',
			$suggestion
		);
	}

	private function volume( array $b ): float {
		return (float) $b['length'] * (float) $b['width'] * (float) $b['height'];
	}
}
