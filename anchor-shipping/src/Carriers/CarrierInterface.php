<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers;

use Anchor\Shipping\Domain\LabelResult;
use Anchor\Shipping\Domain\ShipmentRequest;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * A shipping carrier. Register more with the `anchor_shipping_carriers` filter:
 *   add_filter( 'anchor_shipping_carriers', fn( $c ) => $c + [ 'fedex' => new FedexCarrier() ] );
 * Methods throw Domain\CarrierError on any carrier-side failure.
 */
interface CarrierInterface {

	public function id(): string;

	public function label(): string;

	/** 'labels' | 'void' | 'track' | 'rates' */
	public function supports( string $feature ): bool;

	/** @return array<string, string> service code => label */
	public function services(): array;

	/**
	 * Fields for the settings page, keyed by setting name.
	 * Each: [ 'label' => string, 'type' => 'text'|'secret'|'select', 'options' => array (select only) ].
	 *
	 * @return array<string, array>
	 */
	public function settings_fields(): array;

	public function create_label( ShipmentRequest $request ): LabelResult;

	public function void_label( string $shipment_id ): void;

	/** Phase 3. @return array */
	public function track( array $tracking_numbers ): array;

	/** Phase 4. @return array */
	public function rate( ShipmentRequest $request ): array;

	/** Public tracking page for a number. */
	public function tracking_url( string $tracking_number ): string;
}
