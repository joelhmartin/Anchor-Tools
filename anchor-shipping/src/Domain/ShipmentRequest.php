<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class ShipmentRequest {

	/** @param Parcel[] $parcels */
	public function __construct(
		public readonly Address $from,
		public readonly Address $to,
		public readonly array $parcels,
		public readonly string $service,
		public readonly string $reference,
		public readonly float $declared_value = 0.0,
		public readonly bool $signature = false,
		public readonly string $currency = 'USD'
	) {
		if ( ! $parcels ) {
			throw new \InvalidArgumentException( 'A shipment needs at least one parcel.' );
		}
	}
}
