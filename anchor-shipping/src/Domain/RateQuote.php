<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One carrier service's price for a shipment. `negotiated` = the account's own rate, not the published one. */
final class RateQuote {

	public function __construct(
		public readonly string $service,
		public readonly string $label,
		public readonly float $amount,
		public readonly string $currency = 'USD',
		public readonly bool $negotiated = false
	) {}
}
