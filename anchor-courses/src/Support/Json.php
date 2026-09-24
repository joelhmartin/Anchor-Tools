<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The one JSON codec for the module's LONGTEXT array columns (metadata,
 * answers, grading_data).
 *
 * JSON_PRESERVE_ZERO_FRACTION keeps an integral float such as 1.0 a float on
 * the way back, so value objects never hand callers an int where they promised
 * a float. decode() always returns an array: a NULL, empty or corrupt column
 * reads as [].
 */
final class Json {

	public static function encode( array $value ): string {
		return (string) \wp_json_encode( $value, JSON_PRESERVE_ZERO_FRACTION );
	}

	public static function decode( ?string $raw ): array {
		if ( null === $raw || '' === $raw ) {
			return [];
		}
		$decoded = \json_decode( $raw, true );
		return \is_array( $decoded ) ? $decoded : [];
	}
}
