<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers;

use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\LabelResult;
use Anchor\Shipping\Domain\ShipmentRequest;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * A carrier the store can name on a manually entered tracking number but has no account with:
 * it only knows its name and its public tracking page. Supports nothing, so labels, voids, rates
 * and polling all skip it. Registered through CarrierRegistry::tracking_only(), never all().
 */
final class TrackingOnlyCarrier implements CarrierInterface {

	/** @param string $url_template public tracking page with `%s` where the number goes */
	public function __construct( private string $id, private string $label, private string $url_template ) {}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return $this->label;
	}

	public function supports( string $feature ): bool {
		return false;
	}

	public function services(): array {
		return [];
	}

	public function settings_fields(): array {
		return [];
	}

	public function create_label( ShipmentRequest $request ): LabelResult {
		throw $this->unsupported();
	}

	public function void_label( string $shipment_id ): void {
		throw $this->unsupported();
	}

	public function track( array $tracking_numbers ): array {
		throw $this->unsupported();
	}

	public function rate( ShipmentRequest $request ): array {
		throw $this->unsupported();
	}

	public function tracking_url( string $tracking_number ): string {
		return sprintf( $this->url_template, rawurlencode( $tracking_number ) );
	}

	private function unsupported(): CarrierError {
		/* translators: %s: carrier name */
		return new CarrierError( 'unsupported', sprintf( \__( '%s is set up for tracking links only.', 'anchor-schema' ), $this->label ) );
	}
}
