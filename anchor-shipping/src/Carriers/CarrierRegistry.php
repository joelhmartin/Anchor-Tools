<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class CarrierRegistry {

	/** @var array<string, CarrierInterface>|null */
	private ?array $carriers = null;

	/** @return array<string, CarrierInterface> */
	public function all(): array {
		if ( null === $this->carriers ) {
			$list           = (array) \apply_filters( 'anchor_shipping_carriers', [ 'ups' => new Ups\UpsCarrier() ] );
			$this->carriers = array_filter( $list, static fn( $c ) => $c instanceof CarrierInterface );
		}
		return $this->carriers;
	}

	public function get( string $id ): ?CarrierInterface {
		return $this->all()[ $id ] ?? null;
	}

	public function reset(): void {
		$this->carriers = null;
	}
}
