<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class CarrierRegistry {

	/** @var array<string, CarrierInterface>|null */
	private ?array $carriers = null;

	/** @var array<string, CarrierInterface>|null */
	private ?array $tracking_only = null;

	/** Carriers the store has an account with: labels, rates, voids, polling, settings. @return array<string, CarrierInterface> */
	public function all(): array {
		if ( null === $this->carriers ) {
			$list           = (array) \apply_filters( 'anchor_shipping_carriers', [ 'ups' => new Ups\UpsCarrier() ] );
			$this->carriers = array_filter( $list, static fn( $c ) => $c instanceof CarrierInterface );
		}
		return $this->carriers;
	}

	/**
	 * Carriers a manually entered tracking number may name without an account (tracking link only).
	 * Kept out of all() so settings, labels and rates never offer them. Extend with
	 * `anchor_shipping_tracking_only_carriers`.
	 *
	 * @return array<string, CarrierInterface>
	 */
	public function tracking_only(): array {
		if ( null === $this->tracking_only ) {
			$list = (array) \apply_filters( 'anchor_shipping_tracking_only_carriers', [
				'fedex' => new TrackingOnlyCarrier( 'fedex', 'FedEx', 'https://www.fedex.com/fedextrack/?trknbr=%s' ),
				'usps'  => new TrackingOnlyCarrier( 'usps', 'USPS', 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s' ),
			] );
			$this->tracking_only = array_diff_key( array_filter( $list, static fn( $c ) => $c instanceof CarrierInterface ), $this->all() );
		}
		return $this->tracking_only;
	}

	/** Every carrier a tracking number can be entered for: account carriers first. @return array<string, CarrierInterface> */
	public function for_tracking(): array {
		return $this->all() + $this->tracking_only();
	}

	/** Account carriers, then tracking-only ones, so rows naming either resolve their name and tracking link. */
	public function get( string $id ): ?CarrierInterface {
		return $this->all()[ $id ] ?? $this->tracking_only()[ $id ] ?? null;
	}

	public function reset(): void {
		$this->carriers      = null;
		$this->tracking_only = null;
	}
}
