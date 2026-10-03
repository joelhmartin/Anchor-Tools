<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Anything a carrier refused or failed. The message is safe to show to staff. */
final class CarrierError extends \RuntimeException {

	public function __construct(
		public readonly string $carrier_code,
		string $message,
		public readonly bool $retryable = false
	) {
		parent::__construct( $message );
	}
}
