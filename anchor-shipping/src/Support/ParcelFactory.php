<?php
declare(strict_types=1);

namespace Anchor\Shipping\Support;

use Anchor\Shipping\Domain\Parcel;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Turns a form row (box preset or custom size, plus weight in store units) into a Parcel. Shared by the single-label and bulk paths. */
final class ParcelFactory {

	/**
	 * @param array $row keys: weight, box ('custom' or a preset id), length/width/height for custom
	 * @throws \RuntimeException with a message fit to show staff
	 */
	public static function from_row( array $row ): Parcel {
		$weight = (float) ( $row['weight'] ?? 0 );
		if ( $weight <= 0 ) {
			throw new \RuntimeException( \__( 'Enter the package weight.', 'anchor-schema' ) );
		}
		$box_id = (string) ( $row['box'] ?? 'custom' );
		$boxes  = Settings::boxes();
		if ( 'custom' !== $box_id && isset( $boxes[ $box_id ] ) ) {
			$b = $boxes[ $box_id ];
			return Parcel::from_store_units( $weight, (float) $b['length'], (float) $b['width'], (float) $b['height'], $box_id );
		}
		$dims = array_map( static fn( $k ) => (float) ( $row[ $k ] ?? 0 ), [ 'length', 'width', 'height' ] );
		if ( min( $dims ) <= 0 ) {
			throw new \RuntimeException( \__( 'Enter length, width and height for a custom box.', 'anchor-schema' ) );
		}
		return Parcel::from_store_units( $weight, $dims[0], $dims[1], $dims[2] );
	}
}
