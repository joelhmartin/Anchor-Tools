<?php
declare(strict_types=1);

namespace Anchor\Agreements\Services;

use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Requirements {

	private static function owner( \WC_Product $product ): ?\WC_Product {
		if ( $product->is_type( 'variation' ) ) {
			$parent = \wc_get_product( $product->get_parent_id() );
			return $parent ?: null;
		}
		return $product;
	}

	private static function is_required( \WC_Product $product ): bool {
		$owner = self::owner( $product );
		return $owner && 'yes' === $owner->get_meta( '_anchor_agreement_required' );
	}

	public static function for_product( \WC_Product $product ): int {
		if ( ! self::is_required( $product ) ) {
			return 0;
		}
		$id = (int) self::owner( $product )->get_meta( '_anchor_agreement_id' );
		$id = $id ?: Settings::default_agreement_id();
		return AgreementPostType::is_usable( $id ) ? $id : 0;
	}

	public static function is_misconfigured( \WC_Product $product ): bool {
		return self::is_required( $product ) && 0 === self::for_product( $product );
	}

	/** @return array<int,int> agreement_id => first product_id that needs it. */
	public static function for_cart( ?\WC_Cart $cart = null ): array {
		$cart = $cart ?: ( \function_exists( 'WC' ) ? \WC()->cart : null );
		$out  = [];
		if ( ! $cart ) {
			return $out;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}
			$aid = self::for_product( $item['data'] );
			if ( $aid && ! isset( $out[ $aid ] ) ) {
				$out[ $aid ] = (int) $item['data']->get_id();
			}
		}
		return $out;
	}

	/** @return array<int,int> agreement_id => first product_id that needs it. */
	public static function for_order( \WC_Order $order ): array {
		$out = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			$aid     = $product ? self::for_product( $product ) : 0;
			if ( $aid && ! isset( $out[ $aid ] ) ) {
				$out[ $aid ] = (int) $product->get_id();
			}
		}
		return $out;
	}
}
