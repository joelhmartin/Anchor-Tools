<?php
declare(strict_types=1);

namespace Anchor\Shipping\Checkout;

use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Domain\ShipmentRequest;
use Anchor\Shipping\Module;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Live carrier rates at checkout. Dormant: it does nothing until an admin adds
 * "Anchor Shipping live rates" to a shipping zone. Any problem (cart that can't be
 * sized, incomplete destination, carrier error) offers no rate, so the zone's flat
 * rate stays what the customer sees. Insurance/signature are not part of a rate quote.
 */
class RateMethod extends \WC_Shipping_Method {

	public const ID        = 'anchor_shipping_rates';
	public const CACHE_TTL = 600;
	public const FAILURE_TTL = 60;

	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::ID;
		$this->instance_id        = \absint( $instance_id );
		$this->method_title       = \__( 'Anchor Shipping live rates', 'anchor-schema' );
		$this->method_description = \__( 'Live carrier rates at checkout. If the carrier cannot quote, nothing is offered and the zone\'s other methods (e.g. flat rate) apply.', 'anchor-schema' );
		$this->supports           = [ 'shipping-zones', 'instance-settings' ];
		$this->init_form_fields();
		$this->init_settings();
		$this->title = \__( 'Shipping', 'anchor-schema' );
		\add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/** Registered on `woocommerce_shipping_methods`. */
	public static function register( array $methods ): array {
		$methods[ self::ID ] = self::class;
		return $methods;
	}

	public function init_form_fields(): void {
		$services = [];
		foreach ( $this->carrier()?->services() ?? [] as $code => $label ) {
			$services[ (string) $code ] = $label;
		}
		$this->instance_form_fields = [
			'services'      => [ 'title' => \__( 'Services to offer', 'anchor-schema' ), 'type' => 'multiselect', 'class' => 'wc-enhanced-select', 'options' => $services, 'default' => [ '03' ] ],
			'markup_type'   => [ 'title' => \__( 'Markup', 'anchor-schema' ), 'type' => 'select', 'default' => 'none', 'options' => [ 'none' => \__( 'None', 'anchor-schema' ), 'flat' => \__( 'Flat amount', 'anchor-schema' ), 'percent' => \__( 'Percent', 'anchor-schema' ) ] ],
			'markup_amount' => [ 'title' => \__( 'Markup amount', 'anchor-schema' ), 'type' => 'text', 'default' => '0', 'description' => \__( 'Currency amount for flat, a number for percent.', 'anchor-schema' ), 'desc_tip' => true ],
		];
	}

	/** Settings save hook: a markup can never be negative. */
	public function validate_markup_amount_field( $key, $value ): string {
		$amount = (float) \wc_format_decimal( (string) $value );
		if ( $amount < 0 ) {
			\WC_Admin_Settings::add_error( \__( 'Markup amount cannot be negative; it was set to 0.', 'anchor-schema' ) );
			$amount = 0.0;
		}
		return (string) $amount;
	}

	public function calculate_shipping( $package = [] ): void {
		$quotes   = $this->quotes( (array) $package );
		$selected = array_map( 'strval', (array) $this->get_option( 'services', [] ) );
		foreach ( $quotes as $q ) {
			if ( ! in_array( $q['service'], $selected, true ) ) {
				continue;
			}
			$this->add_rate(
				[
					'id'        => $this->get_rate_id( $q['service'] ),
					'label'     => $q['label'],
					'cost'      => $this->with_markup( (float) $q['amount'] ),
					'package'   => $package,
					'meta_data' => [ 'service' => $q['service'] ],
				]
			);
		}
	}

	/**
	 * Transient key: everything the quote depends on, so a changed weight, box, ship-from address,
	 * carrier account/environment or currency can never be answered from a stale quote. The packed
	 * parcels already carry product weights, default boxes and the configured box dimensions.
	 */
	public function cache_key( array $package ): string {
		$pack = ( Module::instance()?->packer ?? new Packer() )->pack_cart( (array) ( $package['contents'] ?? [] ) );
		return $this->key_for( $package, $pack );
	}

	private function key_for( array $package, \Anchor\Shipping\Packing\PackResult $pack ): string {
		$dest = (array) ( $package['destination'] ?? [] );
		ksort( $dest );
		$id   = (string) ( Settings::all()['default_carrier'] ?? 'ups' );
		$cfg  = Settings::carrier( $id );
		return 'anchor_shipping_rates_' . md5( (string) \wp_json_encode( [
			$pack->ok() ? $pack->parcels : $pack->reason,
			Settings::ship_from(),
			$id,
			(string) ( $cfg['account'] ?? '' ),
			(string) ( $cfg['environment'] ?? '' ),
			\get_woocommerce_currency(),
			$dest,
		] ) );
	}

	/** @return array<int, array{service:string,label:string,amount:float}> carrier quotes, unfiltered; [] on any problem */
	private function quotes( array $package ): array {
		$to = $this->destination( (array) ( $package['destination'] ?? [] ) );
		if ( ! $to ) {
			return [];
		}
		$pack = ( Module::instance()?->packer ?? new Packer() )->pack_cart( (array) ( $package['contents'] ?? [] ) );
		if ( ! $pack->ok() ) {
			return [];
		}
		$key    = $this->key_for( $package, $pack );
		$cached = \get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$carrier = $this->carrier();
			if ( ! $carrier ) {
				return [];
			}
			$currency = \get_woocommerce_currency();
			$out      = [];
			foreach ( $carrier->rate( new ShipmentRequest( Settings::ship_from(), $to, $pack->parcels, '', '', 0.0, false, $currency ) ) as $q ) {
				if ( $q->currency === $currency ) { // never present a foreign-currency figure as store currency
					$out[] = [ 'service' => $q->service, 'label' => $q->label, 'amount' => $q->amount ];
				}
			}
			if ( ! $out ) {
				\set_transient( $key, [], self::FAILURE_TTL );
				return [];
			}
		} catch ( \Throwable $e ) {
			if ( \function_exists( 'wc_get_logger' ) ) {
				\wc_get_logger()->warning( 'Live rates unavailable: ' . $e->getMessage(), [ 'source' => 'anchor-shipping' ] );
			}
			\set_transient( $key, [], self::FAILURE_TTL ); // an outage costs one UPS attempt a minute per cart, not one per refresh
			return [];
		}
		\set_transient( $key, $out, self::CACHE_TTL );
		return $out;
	}

	private function destination( array $d ): ?Address {
		$postcode = trim( (string) ( $d['postcode'] ?? '' ) );
		$country  = trim( (string) ( $d['country'] ?? '' ) );
		if ( '' === $postcode || '' === $country ) {
			return null;
		}
		return new Address(
			\__( 'Customer', 'anchor-schema' ),
			'',
			(string) ( $d['address_1'] ?? $d['address'] ?? '' ),
			(string) ( $d['address_2'] ?? '' ),
			(string) ( $d['city'] ?? '' ),
			(string) ( $d['state'] ?? '' ),
			$postcode,
			$country
		);
	}

	private function with_markup( float $amount ): float {
		$markup = max( 0.0, (float) $this->get_option( 'markup_amount', '0' ) );
		switch ( $this->get_option( 'markup_type', 'none' ) ) {
			case 'flat':
				$amount += $markup;
				break;
			case 'percent':
				$amount *= 1 + $markup / 100;
				break;
		}
		return round( max( 0.0, $amount ), 2 );
	}

	private function carrier(): ?\Anchor\Shipping\Carriers\CarrierInterface {
		$registry = Module::instance()?->carriers ?? new \Anchor\Shipping\Carriers\CarrierRegistry();
		$carrier  = $registry->get( (string) ( Settings::all()['default_carrier'] ?? 'ups' ) ) ?? $registry->get( 'ups' );
		return $carrier && $carrier->supports( 'rates' ) ? $carrier : null;
	}
}
