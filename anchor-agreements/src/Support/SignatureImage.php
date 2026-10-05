<?php
declare(strict_types=1);

namespace Anchor\Agreements\Support;

/**
 * The one gate between a browser-supplied data URL and the DB. WordPress-free on purpose
 * (unit-tested). The ABSPATH guard is deliberately omitted so the unit suite can load it.
 */
final class SignatureImage {

	public const MAX_BYTES = 204800;
	public const MAX_W     = 2000;
	public const MAX_H     = 1000;
	private const PREFIX   = 'data:image/png;base64,';

	public static function decode( string $data_url ): ?string {
		if ( 0 !== strpos( $data_url, self::PREFIX ) ) {
			return null;
		}
		$b64 = substr( $data_url, strlen( self::PREFIX ) );
		if ( strlen( $b64 ) > (int) ceil( self::MAX_BYTES * 4 / 3 ) + 4 ) {
			return null;
		}
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			return null;
		}
		if ( 0 !== strncmp( $bytes, "\x89PNG\r\n\x1a\n", 8 ) ) {
			return null;
		}
		$info = @getimagesizefromstring( $bytes );
		if ( ! is_array( $info ) || IMAGETYPE_PNG !== ( $info[2] ?? null ) ) {
			return null;
		}
		if ( $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_W || $info[1] > self::MAX_H ) {
			return null;
		}
		return $bytes;
	}
}
