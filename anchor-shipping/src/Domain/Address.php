<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Address {

	public function __construct(
		public readonly string $name,
		public readonly string $company,
		public readonly string $line1,
		public readonly string $line2,
		public readonly string $city,
		public readonly string $state,
		public readonly string $postcode,
		public readonly string $country,
		public readonly string $phone = ''
	) {}

	/** Shipping address, or billing when the order has none. Phone falls back to billing. */
	public static function from_order_shipping( \WC_Order $o ): self {
		$type = '' !== $o->get_shipping_address_1() ? 'shipping' : 'billing';
		$get  = static fn( string $f ): string => (string) $o->{"get_{$type}_{$f}"}();
		return new self(
			trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) ),
			$get( 'company' ),
			$get( 'address_1' ),
			$get( 'address_2' ),
			$get( 'city' ),
			$get( 'state' ),
			$get( 'postcode' ),
			$get( 'country' ),
			(string) ( $o->get_shipping_phone() ?: $o->get_billing_phone() )
		);
	}

	public function is_complete(): bool {
		return '' !== $this->name && '' !== $this->line1 && '' !== $this->city && '' !== $this->postcode && '' !== $this->country;
	}
}
