<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Declared value + signature: which mode applies, what the customer pays, what the label carries. */
final class Protection {

	public const META_INSURE    = '_anchor_shipping_insure';
	public const META_SIGNATURE = '_anchor_shipping_signature';

	/** @return array{declared_value: float, signature: bool} */
	public static function for_order( \WC_Order $order, float $shippable_subtotal ): array {
		$s       = Settings::all();
		$insured = match ( $s['insurance']['mode'] ) {
			'auto'     => $shippable_subtotal > (float) $s['insurance']['threshold'],
			'customer' => 'yes' === $order->get_meta( self::META_INSURE ),
			default    => false,
		};
		$sign    = match ( $s['signature']['mode'] ) {
			'auto'     => $shippable_subtotal > (float) $s['signature']['threshold'],
			'customer' => 'yes' === $order->get_meta( self::META_SIGNATURE ),
			default    => false,
		};
		return [ 'declared_value' => $insured ? $shippable_subtotal : 0.0, 'signature' => $sign ];
	}

	public static function offered( string $option ): bool {
		$s   = Settings::all()[ $option ] ?? [];
		$fee = 'insurance' === $option ? ( $s['fee_per_100'] ?? '' ) : ( $s['fee'] ?? '' );
		return 'customer' === ( $s['mode'] ?? 'off' ) && '' !== (string) $fee;
	}

	/** rate × each started $100 above the first $100 (which UPS covers free). */
	public static function insurance_fee( float $subtotal ): float {
		$rate = (float) Settings::all()['insurance']['fee_per_100'];
		return $subtotal <= 100 ? 0.0 : round( $rate * ceil( ( $subtotal - 100 ) / 100 ), 2 );
	}

	public static function signature_fee(): float {
		return round( (float) Settings::all()['signature']['fee'], 2 );
	}
}
