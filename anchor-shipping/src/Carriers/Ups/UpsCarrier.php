<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers\Ups;

use Anchor\Shipping\Carriers\CarrierInterface;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\LabelResult;
use Anchor\Shipping\Domain\ShipmentRequest;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class UpsCarrier implements CarrierInterface {
	public function id(): string { return 'ups'; }
	public function label(): string { return 'UPS'; }
	public function supports( string $feature ): bool { return false; }
	public function services(): array {
		return [
			'03' => 'UPS Ground',
			'12' => 'UPS 3 Day Select',
			'02' => 'UPS 2nd Day Air',
			'13' => 'UPS Next Day Air Saver',
			'01' => 'UPS Next Day Air',
			'14' => 'UPS Next Day Air Early',
		];
	}

	public function settings_fields(): array {
		return [
			'environment'   => [ 'label' => 'Environment', 'type' => 'select', 'options' => [ 'sandbox' => 'Sandbox (UPS test server — no charges)', 'production' => 'Production (real labels, billed)' ] ],
			'client_id'     => [ 'label' => 'Client ID', 'type' => 'text' ],
			'client_secret' => [ 'label' => 'Client Secret', 'type' => 'secret' ],
			'account'       => [ 'label' => 'Account (shipper) number', 'type' => 'text' ],
		];
	}
	public function create_label( ShipmentRequest $request ): LabelResult { throw new CarrierError( 'unsupported', 'Not implemented yet.' ); }
	public function void_label( string $shipment_id ): void { throw new CarrierError( 'unsupported', 'Not implemented yet.' ); }
	public function track( array $tracking_numbers ): array { throw new CarrierError( 'unsupported', 'Tracking arrives in phase 3.' ); }
	public function rate( ShipmentRequest $request ): array { throw new CarrierError( 'unsupported', 'Rates arrive in phase 4.' ); }
	public function tracking_url( string $tracking_number ): string { return 'https://www.ups.com/track?tracknum=' . rawurlencode( $tracking_number ); }
}
