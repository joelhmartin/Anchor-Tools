<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers\Ups;

use Anchor\Shipping\Carriers\AddressValidatingCarrier;
use Anchor\Shipping\Carriers\CarrierInterface;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\LabelResult;
use Anchor\Shipping\Domain\PackageLabel;
use Anchor\Shipping\Domain\ShipmentRequest;
use Anchor\Shipping\Domain\TrackingResult;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** UPS REST (Shipping v2409). Never sends the customer's email or UPS notifications. */
final class UpsCarrier implements CarrierInterface, AddressValidatingCarrier {

	public function __construct( private ?UpsClient $client = null ) {}

	public function id(): string {
		return 'ups';
	}

	public function label(): string {
		return 'UPS';
	}

	public function supports( string $feature ): bool {
		return in_array( $feature, [ 'labels', 'void', 'track', 'address_validation' ], true );
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
		$json   = $this->client()->request( 'POST', '/api/shipments/v2409/ship', $this->build_ship_body( $request, $creds['account'], $format ), false );

		// From here UPS answered 2xx to a purchase: whatever is wrong with the body, a label
		// may exist and be billed, so every failure below is 'uncertain', never 'parse'.
		$results     = $json['ShipmentResponse']['ShipmentResults'] ?? null;
		$results     = is_array( $results ) ? $results : [];
		$pkgs        = $results['PackageResults'] ?? [];
		$pkgs        = is_array( $pkgs ) && isset( $pkgs['TrackingNumber'] ) ? [ $pkgs ] : ( is_array( $pkgs ) ? array_values( $pkgs ) : [] );
		$shipment_id = is_scalar( $results['ShipmentIdentificationNumber'] ?? null ) ? trim( (string) $results['ShipmentIdentificationNumber'] ) : '';
		$packages    = [];
		$numbers     = [];
		$usable      = '' !== $shipment_id && count( $pkgs ) === count( $request->parcels );
		foreach ( $pkgs as $p ) {
			$tracking = is_array( $p ) && is_scalar( $p['TrackingNumber'] ?? null ) ? trim( (string) $p['TrackingNumber'] ) : '';
			$image    = is_array( $p ) ? ( $p['ShippingLabel']['GraphicImage'] ?? '' ) : '';
			$bytes    = is_string( $image ) ? base64_decode( $image, true ) : false;
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
			throw $this->billed_but_unusable( $shipment_id, $numbers );
		}
		$charge = $results['NegotiatedRateCharges']['TotalCharge'] ?? $results['ShipmentCharges']['TotalCharges'] ?? null;

		return new LabelResult(
			$shipment_id,
			$packages,
			isset( $charge['MonetaryValue'] ) ? (float) $charge['MonetaryValue'] : null,
			(string) ( $charge['CurrencyCode'] ?? 'USD' )
		);
	}

	/** UPS answered the purchase but not with a usable label: keep whatever it said it created. */
	private function billed_but_unusable( string $shipment_id, array $numbers ): CarrierError {
		if ( '' === $shipment_id && ! $numbers ) {
			$message = \__( 'UPS accepted the label request but its answer could not be read, so a label may have been created and billed. Check Shipping History on ups.com before creating another.', 'anchor-schema' );
		} else {
			$message = sprintf(
				/* translators: 1: UPS shipment number, 2: tracking numbers */
				\__( 'UPS created shipment %1$s%2$s but returned an unusable label. Void it before creating another.', 'anchor-schema' ),
				'' !== $shipment_id ? $shipment_id : \__( '(number not returned)', 'anchor-schema' ),
				$numbers ? ' (' . implode( ', ', $numbers ) . ')' : ''
			);
		}
		return new CarrierError( 'uncertain', $message, false, $shipment_id, $numbers );
	}

	public function void_label( string $shipment_id ): void {
		$this->client()->request( 'DELETE', '/api/shipments/v2409/void/cancel/' . rawurlencode( $shipment_id ) );
	}

	/** UPS error codes meaning "no such shipment (yet)": reported as 'unknown', not as a failure. */
	private const NOT_FOUND_CODES = [ 'TV1002', '151018', '151044', 'http_404' ];

	public function track( array $tracking_numbers ): array {
		$out = [];
		foreach ( $tracking_numbers as $number ) {
			$number = trim( (string) $number );
			if ( '' === $number ) {
				continue;
			}
			try {
				$json = $this->client()->request( 'GET', '/api/track/v1/details/' . rawurlencode( $number ) . '?locale=en_US' );
			} catch ( CarrierError $e ) {
				if ( ! in_array( $e->carrier_code, self::NOT_FOUND_CODES, true ) ) {
					throw $e;
				}
				$out[ $number ] = new TrackingResult( $number, 'unknown', $e->getMessage(), null );
				continue;
			}
			$out[ $number ] = $this->parse_tracking( $number, $json );
		}
		return $out;
	}

	private function parse_tracking( string $number, array $json ): TrackingResult {
		$errors = $json['response']['errors'][0] ?? null;
		if ( is_array( $errors ) ) {
			return new TrackingResult( $number, 'unknown', (string) ( $errors['message'] ?? '' ), null );
		}
		$pkg        = $json['trackResponse']['shipment'][0]['package'][0] ?? null;
		$activities = is_array( $pkg ) && is_array( $pkg['activity'] ?? null ) ? $pkg['activity'] : [];

		$latest    = null;
		$latest_at = null;
		foreach ( $activities as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$at = $this->activity_time( $a );
			if ( null === $latest || ( null !== $at && ( null === $latest_at || $at > $latest_at ) ) ) {
				$latest    = $a;
				$latest_at = $at ?? $latest_at;
			}
		}

		$current = is_array( $pkg ) && is_array( $pkg['currentStatus'] ?? null ) ? $pkg['currentStatus'] : ( is_array( $latest ) && is_array( $latest['status'] ?? null ) ? $latest['status'] : [] );
		$type    = strtoupper( (string) ( $current['type'] ?? '' ) );
		$code    = strtoupper( (string) ( $current['code'] ?? '' ) );
		$desc    = trim( (string) ( $current['description'] ?? '' ) );

		return new TrackingResult( $number, $this->map_status( $type, $code, $desc ), $desc, $latest_at );
	}

	/** UPS activity date (YYYYMMDD) + time (HHMMSS) as a UTC timestamp. */
	private function activity_time( array $a ): ?int {
		$date = (string) ( $a['date'] ?? '' );
		$time = str_pad( (string) ( $a['time'] ?? '' ), 6, '0', STR_PAD_LEFT );
		if ( ! preg_match( '/^\d{8}$/', $date ) || ! preg_match( '/^\d{6}$/', $time ) ) {
			return null;
		}
		$ts = gmmktime( (int) substr( $time, 0, 2 ), (int) substr( $time, 2, 2 ), (int) substr( $time, 4, 2 ), (int) substr( $date, 4, 2 ), (int) substr( $date, 6, 2 ), (int) substr( $date, 0, 4 ) );
		return false === $ts ? null : $ts;
	}

	private function map_status( string $type, string $code, string $desc ): string {
		if ( 'O' === $type || 'OT' === $code || false !== stripos( $desc, 'out for delivery' ) ) {
			return 'out_for_delivery';
		}
		switch ( $type ) {
			case 'M':
			case 'MV':
				return 'label_created';
			case 'I':
			case 'P':
				return 'in_transit';
			case 'D':
				return 'delivered';
			case 'X':
				return 'exception';
			case 'RS':
				return 'returned';
		}
		return 'unknown';
	}

	public function validate_address( Address $address ): array {
		$key = [
			'ConsigneeName'      => substr( '' !== $address->company ? $address->company : $address->name, 0, 35 ),
			'AddressLine'        => array_values( array_filter( [ $address->line1, $address->line2 ] ) ),
			'PoliticalDivision2' => $address->city,
			'PoliticalDivision1' => $address->state,
			'PostcodePrimaryLow' => $address->postcode,
			'CountryCode'        => $address->country,
		];
		$json = $this->client()->request( 'POST', '/api/addressvalidation/v2/1', [ 'XAVRequest' => [ 'AddressKeyFormat' => $key ] ] );
		$res  = $json['XAVResponse'] ?? null;
		if ( ! is_array( $res ) ) {
			throw new CarrierError( 'parse', \__( 'UPS address validation returned an unreadable answer.', 'anchor-schema' ) );
		}
		// UPS signals the outcome with empty-string indicator keys, so test presence, not truthiness.
		$valid      = array_key_exists( 'ValidAddressIndicator', $res );
		$ambiguous  = array_key_exists( 'AmbiguousAddressIndicator', $res );
		$candidates = [];
		if ( $ambiguous ) {
			$list = $res['Candidate'] ?? [];
			$list = is_array( $list ) && isset( $list['AddressKeyFormat'] ) ? [ $list ] : ( is_array( $list ) ? $list : [] );
			foreach ( $list as $c ) {
				$k = is_array( $c ) ? ( $c['AddressKeyFormat'] ?? null ) : null;
				if ( ! is_array( $k ) ) {
					continue;
				}
				$lines        = (array) ( $k['AddressLine'] ?? [] );
				$ext          = (string) ( $k['PostcodeExtendedLow'] ?? '' );
				$candidates[] = new Address(
					$address->name,
					$address->company,
					(string) ( $lines[0] ?? '' ),
					(string) ( $lines[1] ?? '' ),
					(string) ( $k['PoliticalDivision2'] ?? '' ),
					(string) ( $k['PoliticalDivision1'] ?? '' ),
					(string) ( $k['PostcodePrimaryLow'] ?? '' ) . ( '' !== $ext ? '-' . $ext : '' ),
					(string) ( $k['CountryCode'] ?? $address->country ),
					$address->phone
				);
			}
		}
		return [ 'valid' => $valid, 'ambiguous' => $ambiguous, 'candidates' => $candidates ];
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
