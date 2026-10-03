<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers\Ups;

use Anchor\Shipping\Carriers\CarrierInterface;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\LabelResult;
use Anchor\Shipping\Domain\PackageLabel;
use Anchor\Shipping\Domain\ShipmentRequest;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** UPS REST (Shipping v2409). Never sends the customer's email or UPS notifications. */
final class UpsCarrier implements CarrierInterface {

	public function __construct( private ?UpsClient $client = null ) {}

	public function id(): string {
		return 'ups';
	}

	public function label(): string {
		return 'UPS';
	}

	public function supports( string $feature ): bool {
		return in_array( $feature, [ 'labels', 'void' ], true );
	}

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

	public function tracking_url( string $tracking_number ): string {
		return 'https://www.ups.com/track?tracknum=' . rawurlencode( $tracking_number );
	}

	public function create_label( ShipmentRequest $request ): LabelResult {
		$creds  = Settings::carrier( 'ups' );
		$format = 'ZPL' === Settings::all()['label_format'] ? 'ZPL' : 'GIF';
		$json   = $this->client()->request( 'POST', '/api/shipments/v2409/ship', $this->build_ship_body( $request, $creds['account'], $format ) );

		$results  = $json['ShipmentResponse']['ShipmentResults'] ?? null;
		if ( ! is_array( $results ) || empty( $results['ShipmentIdentificationNumber'] ) ) {
			throw new CarrierError( 'parse', \__( 'UPS returned an unexpected response for the label.', 'anchor-schema' ) );
		}
		$pkgs     = $results['PackageResults'] ?? [];
		$pkgs     = isset( $pkgs['TrackingNumber'] ) ? [ $pkgs ] : (array) $pkgs;
		$shipment_id = (string) $results['ShipmentIdentificationNumber'];
		$packages    = [];
		$numbers     = [];
		$usable      = count( $pkgs ) === count( $request->parcels );
		foreach ( $pkgs as $p ) {
			$tracking = is_array( $p ) ? (string) ( $p['TrackingNumber'] ?? '' ) : '';
			$bytes    = is_array( $p ) ? base64_decode( (string) ( $p['ShippingLabel']['GraphicImage'] ?? '' ), true ) : false;
			if ( '' !== $tracking ) {
				$numbers[] = $tracking;
			}
			if ( '' === $tracking || false === $bytes || '' === $bytes ) {
				$usable = false;
				continue;
			}
			$packages[] = new PackageLabel( $tracking, $bytes, (string) ( $p['ShippingLabel']['ImageFormat']['Code'] ?? $format ) );
		}
		if ( ! $usable ) {
			// UPS has already billed this shipment, so staff need its numbers to void it.
			throw new CarrierError(
				'parse',
				sprintf(
					/* translators: 1: UPS shipment number, 2: tracking numbers */
					\__( 'UPS created shipment %1$s%2$s but returned an unusable label. Void it at ups.com or retry.', 'anchor-schema' ),
					$shipment_id,
					$numbers ? ' (' . implode( ', ', $numbers ) . ')' : ''
				)
			);
		}
		$charge = $results['NegotiatedRateCharges']['TotalCharge'] ?? $results['ShipmentCharges']['TotalCharges'] ?? null;

		return new LabelResult(
			$shipment_id,
			$packages,
			isset( $charge['MonetaryValue'] ) ? (float) $charge['MonetaryValue'] : null,
			(string) ( $charge['CurrencyCode'] ?? 'USD' )
		);
	}

	public function void_label( string $shipment_id ): void {
		$this->client()->request( 'DELETE', '/api/shipments/v2409/void/cancel/' . rawurlencode( $shipment_id ) );
	}

	public function track( array $tracking_numbers ): array {
		throw new CarrierError( 'unsupported', \__( 'Tracking arrives in phase 3.', 'anchor-schema' ) );
	}

	public function rate( ShipmentRequest $request ): array {
		throw new CarrierError( 'unsupported', \__( 'Rates arrive in phase 4.', 'anchor-schema' ) );
	}

	public function build_ship_body( ShipmentRequest $r, string $account, string $format ): array {
		$per_pkg_value = $r->declared_value > 0 ? round( $r->declared_value / count( $r->parcels ), 2 ) : 0.0;
		$packages      = [];
		foreach ( $r->parcels as $parcel ) {
			[ $l, $w, $h ] = $parcel->dims_in();
			$pkg           = [
				'Packaging'       => [ 'Code' => '02' ],
				'Dimensions'      => [ 'UnitOfMeasurement' => [ 'Code' => 'IN' ], 'Length' => (string) $l, 'Width' => (string) $w, 'Height' => (string) $h ],
				'PackageWeight'   => [ 'UnitOfMeasurement' => [ 'Code' => 'LBS' ], 'Weight' => (string) $parcel->weight_lb() ],
				'ReferenceNumber' => [ 'Value' => substr( $r->reference, 0, 35 ) ],
			];
			$options = [];
			if ( $per_pkg_value > 0 ) {
				$options['DeclaredValue'] = [ 'CurrencyCode' => $r->currency, 'MonetaryValue' => number_format( $per_pkg_value, 2, '.', '' ) ];
			}
			if ( $r->signature ) {
				$options['DeliveryConfirmation'] = [ 'DCISType' => '2' ];
			}
			if ( $options ) {
				$pkg['PackageServiceOptions'] = $options;
			}
			$packages[] = $pkg;
		}

		$label = 'ZPL' === $format
			? [ 'LabelImageFormat' => [ 'Code' => 'ZPL' ], 'LabelStockSize' => [ 'Height' => '6', 'Width' => '4' ] ]
			: [ 'LabelImageFormat' => [ 'Code' => 'GIF' ], 'HTTPUserAgent' => 'Mozilla/4.5' ];

		return [
			'ShipmentRequest' => [
				'Request'            => [ 'RequestOption' => 'nonvalidate' ],
				'Shipment'           => [
					'Description'           => substr( $r->reference, 0, 50 ),
					'Shipper'               => $this->party( $r->from ) + [ 'ShipperNumber' => $account ],
					'ShipFrom'              => $this->party( $r->from ),
					'ShipTo'                => $this->party( $r->to ),
					'PaymentInformation'    => [ 'ShipmentCharge' => [ 'Type' => '01', 'BillShipper' => [ 'AccountNumber' => $account ] ] ],
					'Service'               => [ 'Code' => $r->service ],
					'ShipmentRatingOptions' => [ 'NegotiatedRatesIndicator' => '' ],
					'Package'               => $packages,
				],
				'LabelSpecification' => $label,
			],
		];
	}

	/** Name + address + phone. Deliberately no EMailAddress (UPS would email the recipient). */
	private function party( Address $a ): array {
		$party = [
			'Name'    => substr( '' !== $a->company ? $a->company : $a->name, 0, 35 ),
			'Address' => [
				'AddressLine'       => array_values( array_filter( [ $a->line1, $a->line2 ] ) ),
				'City'              => $a->city,
				'StateProvinceCode' => $a->state,
				'PostalCode'        => $a->postcode,
				'CountryCode'       => $a->country,
			],
		];
		if ( '' !== $a->company && '' !== $a->name ) {
			$party['AttentionName'] = substr( $a->name, 0, 35 );
		}
		if ( '' !== $a->phone ) {
			$party['Phone'] = [ 'Number' => preg_replace( '/\D+/', '', $a->phone ) ];
		}
		return $party;
	}

	private function client(): UpsClient {
		if ( $this->client ) {
			return $this->client;
		}
		$c = Settings::carrier( 'ups' );
		if ( '' === ( $c['client_id'] ?? '' ) || '' === ( $c['client_secret'] ?? '' ) || '' === ( $c['account'] ?? '' ) ) {
			throw new CarrierError( 'not_configured', \__( 'UPS is not set up: add the Client ID, Secret and account number under WooCommerce > Anchor Shipping.', 'anchor-schema' ) );
		}
		return new UpsClient( $c['client_id'], $c['client_secret'], $c['environment'] ?? 'sandbox' );
	}
}
