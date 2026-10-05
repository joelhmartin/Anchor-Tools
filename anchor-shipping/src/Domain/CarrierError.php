<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Anything a carrier refused or failed. The message is safe to show to staff.
 *
 * Code 'uncertain' means the carrier may have created (and billed) a label: never retry it
 * automatically. When the carrier's answer named what it created, $shipment_id /
 * $tracking_numbers carry it so LabelService can keep it on the order.
 */
final class CarrierError extends \RuntimeException {

	/** @param string[] $tracking_numbers */
	public function __construct(
		public readonly string $carrier_code,
		string $message,
		public readonly bool $retryable = false,
		public readonly string $shipment_id = '',
		public readonly array $tracking_numbers = []
	) {
		parent::__construct( $message );
	}
}
