<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One physical package, in kg/cm. Adapters convert to their carrier's units. */
final class Parcel {

	public function __construct(
		public readonly float $weight_kg,
		public readonly float $length_cm,
		public readonly float $width_cm,
		public readonly float $height_cm,
		public readonly string $box_id = ''
	) {
		if ( $weight_kg <= 0 ) {
			throw new \InvalidArgumentException( 'Parcel weight must be greater than zero.' );
		}
	}

	/** Values in the store's configured weight/dimension units. */
	public static function from_store_units( float $weight, float $l, float $w, float $h, string $box_id = '' ): self {
		return new self(
			(float) \wc_get_weight( $weight, 'kg' ),
			(float) \wc_get_dimension( $l, 'cm' ),
			(float) \wc_get_dimension( $w, 'cm' ),
			(float) \wc_get_dimension( $h, 'cm' ),
			$box_id
		);
	}

	public function weight_lb(): float {
		return max( 0.1, round( $this->weight_kg * 2.20462262, 1 ) );
	}

	/** @return array{0:int,1:int,2:int} whole inches, rounded up (rounded to 0.01 first so 25.4cm is 10in, not 11). */
	public function dims_in(): array {
		return array_map(
			static fn( float $cm ): int => (int) max( 1, ceil( round( $cm / 2.54, 2 ) ) ),
			[ $this->length_cm, $this->width_cm, $this->height_cm ]
		);
	}

	public function dims_label(): string {
		return implode( '×', array_map( static fn( float $v ) => (string) round( $v, 1 ), [ $this->length_cm, $this->width_cm, $this->height_cm ] ) );
	}
}
