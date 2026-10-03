<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class LabelResult {

	/** @param PackageLabel[] $packages same order as ShipmentRequest::$parcels */
	public function __construct(
		public readonly string $shipment_id,
		public readonly array $packages,
		public readonly ?float $cost,
		public readonly string $currency
	) {}
}
