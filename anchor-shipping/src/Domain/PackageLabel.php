<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class PackageLabel {

	/** @param string $format 'GIF' or 'ZPL' — what the carrier returned, before LabelStore converts it. */
	public function __construct(
		public readonly string $tracking_number,
		public readonly string $bytes,
		public readonly string $format
	) {}
}
