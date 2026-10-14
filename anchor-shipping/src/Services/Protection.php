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

	/**
	 * Is this option decided by the order rather than by staff at label time? True unless the mode is
	 * 'off': 'customer' = what the customer chose (and paid for) at checkout, 'auto' = the threshold rule.
	 * The label form then shows the decision read-only and label creation ignores anything posted.
	 */
	public static function locked( string $option ): bool {
		return 'off' !== ( Settings::all()[ $option ]['mode'] ?? 'off' );
	}

	public static function offered( string $option ): bool {
		$s   = Settings::all()[ $option ] ?? [];
		$fee = 'insurance' === $option ? ( $s['fee_per_100'] ?? '' ) : ( $s['fee'] ?? '' );
		return 'customer' === ( $s['mode'] ?? 'off' ) && '' !== (string) $fee;
	}

	/**
	 * UPS's declared-value shape: the first $100 is covered free; $100.01–$300 is one flat charge
	 * (fee_to_300); above $300 it is fee_per_100 × every started $100 of the WHOLE value. A blank
	 * fee_to_300 applies the per-$100 rule from $100.01 too.
	 */
	public static function insurance_fee( float $subtotal ): float {
		$s    = Settings::all()['insurance'];
		$rate = (float) $s['fee_per_100'];
		if ( $subtotal <= 100 ) {
			return 0.0;
		}
		if ( $subtotal <= 300 && '' !== (string) ( $s['fee_to_300'] ?? '' ) ) {
			return round( (float) $s['fee_to_300'], 2 );
		}
		return round( $rate * ceil( $subtotal / 100 ), 2 );
	}

	public static function signature_fee(): float {
		return round( (float) Settings::all()['signature']['fee'], 2 );
	}
}
