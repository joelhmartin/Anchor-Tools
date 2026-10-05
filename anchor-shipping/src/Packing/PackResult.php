<?php
declare(strict_types=1);

namespace Anchor\Shipping\Packing;

use Anchor\Shipping\Domain\Parcel;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class PackResult {

	/**
	 * @param Parcel[]   $parcels
	 * @param string     $reason     why the order cannot be sized automatically ('' when it can)
	 * @param array|null $suggestion ['box' => id, 'weight' => float in store units] for prefilling forms
	 */
	public function __construct(
		public readonly array $parcels,
		public readonly string $reason,
		public readonly ?array $suggestion
	) {}

	public function ok(): bool {
		return '' === $this->reason && [] !== $this->parcels;
	}
}
