<?php
declare(strict_types=1);

namespace Anchor\Shipping\Support;

use Anchor\Shipping\Domain\Address;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The module's single option. Carrier credentials can be pinned in wp-config.php
 * as ANCHOR_SHIPPING_{CARRIER}_{KEY}; a defined constant always wins.
 */
final class Settings {

	public const OPTION = 'anchor_shipping_settings';

	public static function defaults(): array {
		return [
			'default_carrier' => 'ups',
			'default_service' => '03',
			'label_format'    => 'GIF',
			'inbox'           => '',
			'auto_label'      => false,
			'ship_from'       => [ 'name' => '', 'company' => '', 'phone' => '', 'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => '' ],
			'boxes'           => [],
			'carriers'        => [ 'ups' => [ 'environment' => 'sandbox', 'client_id' => '', 'client_secret' => '', 'account' => '' ] ],
			'insurance'       => [ 'mode' => 'off', 'threshold' => 100.0, 'fee_per_100' => '' ],
			'signature'       => [ 'mode' => 'off', 'threshold' => 0.0, 'fee' => '' ],
		];
	}

	public static function all(): array {
		$stored = \get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$all    = array_replace_recursive( self::defaults(), $stored );
		if ( isset( $stored['boxes'] ) ) {
			$all['boxes'] = array_values( (array) $stored['boxes'] ); // a list: never merge by index
		}
		$all['auto_label'] = (bool) $all['auto_label'];
		return $all;
	}

	public static function save( array $settings ): void {
		\update_option( self::OPTION, $settings, false );
	}

	public static function constant_name( string $carrier, string $key ): string {
		return strtoupper( "ANCHOR_SHIPPING_{$carrier}_{$key}" );
	}

	public static function is_constant( string $carrier, string $key ): bool {
		return \defined( self::constant_name( $carrier, $key ) );
	}

	public static function carrier( string $id ): array {
		$c = self::all()['carriers'][ $id ] ?? [];
		foreach ( array_keys( $c + [ 'environment' => '', 'client_id' => '', 'client_secret' => '', 'account' => '' ] ) as $key ) {
			if ( self::is_constant( $id, $key ) ) {
				$c[ $key ] = (string) \constant( self::constant_name( $id, $key ) );
			}
		}
		if ( self::environment_forced( $id ) ) {
			$c['environment'] = 'sandbox';
		}
		return array_map( 'strval', $c );
	}

	/** wp_get_environment_type(), behind a filter so tests (and odd hosts) can override it. */
	public static function environment_type(): string {
		return (string) \apply_filters( 'anchor_shipping_environment_type', \wp_get_environment_type() );
	}

	/**
	 * A staging/dev copy of the site must never buy real labels just because the
	 * database was cloned with "production" saved: only a wp-config constant overrides.
	 */
	public static function environment_forced( string $carrier ): bool {
		return 'production' !== self::environment_type() && ! self::is_constant( $carrier, 'environment' );
	}

	/** Configured ship-from, each blank field filled from the Woo store address. */
	public static function ship_from(): Address {
		$s                 = self::all()['ship_from'];
		[ $country, $state ] = array_pad( explode( ':', (string) \get_option( 'woocommerce_default_country', '' ) ), 2, '' );
		$pick              = static fn( string $k, string $fallback ): string => '' !== (string) ( $s[ $k ] ?? '' ) ? (string) $s[ $k ] : $fallback;
		return new Address(
			$pick( 'name', 'Shipping' ),
			$pick( 'company', (string) \get_option( 'blogname', '' ) ),
			$pick( 'line1', (string) \get_option( 'woocommerce_store_address', '' ) ),
			$pick( 'line2', (string) \get_option( 'woocommerce_store_address_2', '' ) ),
			$pick( 'city', (string) \get_option( 'woocommerce_store_city', '' ) ),
			$pick( 'state', $state ),
			$pick( 'postcode', (string) \get_option( 'woocommerce_store_postcode', '' ) ),
			$pick( 'country', $country ),
			$pick( 'phone', '' )
		);
	}

	/** @return array<string, array> keyed by box id */
	public static function boxes(): array {
		$out = [];
		foreach ( self::all()['boxes'] as $b ) {
			if ( ! empty( $b['id'] ) ) {
				$out[ (string) $b['id'] ] = $b;
			}
		}
		return $out;
	}

	public static function auto_label(): bool {
		return self::all()['auto_label'];
	}

	public static function inbox(): string {
		$inbox = trim( (string) self::all()['inbox'] );
		return '' !== $inbox ? $inbox : (string) \get_option( 'admin_email' );
	}
}
