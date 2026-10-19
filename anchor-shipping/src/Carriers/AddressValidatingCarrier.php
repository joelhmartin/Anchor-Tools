<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers;

use Anchor\Shipping\Domain\Address;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Optional capability: a carrier that can check a ship-to address. Callers also gate on supports('address_validation'). */
interface AddressValidatingCarrier {

	/** @return array{valid:bool, ambiguous:bool, candidates:Address[]} */
	public function validate_address( Address $address ): array;
}
