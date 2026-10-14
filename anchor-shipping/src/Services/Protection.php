<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Declared value + signature: which mode applies, what the customer pays, what the label carries. */
final class Protection {

	public const META_INSURE    = '_anchor_shipping_insure';
	public const META_SIGNATURE = '_anchor_shipping_signature';

	private const META = [ 'insurance' => self::META_INSURE, 'signature' => self::META_SIGNATURE ];

	/** Did the customer choose (and pay for) this option at checkout? Recorded on the order, so it outlives any later settings change. */
	public static function paid_for( \WC_Order $order, string $option ): bool {
		return 'yes' === $order->get_meta( self::META[ $option ] ?? '' );
	}

	/** @return array{declared_value: float, signature: bool} */
	public static function for_order( \WC_Order $order, float $shippable_subtotal ): array {
		$applies = static function ( string $option ) use ( $order, $shippable_subtotal ): bool {
			if ( self::paid_for( $order, $option ) ) {
				return true; // paid for at checkout: honoured whatever the mode is now
			}
			$s = Settings::all()[ $option ];
			return 'auto' === $s['mode'] && $shippable_subtotal > (float) $s['threshold'];
		};
		return [ 'declared_value' => $applies( 'insurance' ) ? $shippable_subtotal : 0.0, 'signature' => $applies( 'signature' ) ];
	}

	/**
	 * Is this option decided by the order rather than by staff at label time? Yes when the customer paid
	 * for it, or when the mode is not 'off' ('customer' = their checkout choice, 'auto' = the threshold
	 * rule). The label form then shows it read-only and label creation ignores anything posted.
	 */
	public static function locked( string $option, ?\WC_Order $order = null ): bool {
		return ( $order && self::paid_for( $order, $option ) ) || 'off' !== ( Settings::all()[ $option ]['mode'] ?? 'off' );
	}

	/** Why a locked option is what it is, for the label form; '' when staff choose. */
	public static function lock_reason( string $option, \WC_Order $order ): string {
		if ( self::paid_for( $order, $option ) ) {
			return \__( 'paid for at checkout', 'anchor-schema' );
		}
		return match ( Settings::all()[ $option ]['mode'] ?? 'off' ) {
			'customer' => \__( 'not chosen at checkout', 'anchor-schema' ),
			'auto'     => \__( 'set by the order-value rule', 'anchor-schema' ),
			default    => '',
		};
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
