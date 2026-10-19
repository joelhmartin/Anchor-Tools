<?php
/**
 * Short-lived, user- and file-bound download links for the member app.
 *
 * Token = base64url("user_id.file_id.disposition.exp") . "." . hex(HMAC-SHA256('anchor-library-link|' . encoded payload, wp_salt('auth'))).
 * The token is the only credential on /files/{id}/content: it names who may read which one file until it expires,
 * and nothing else. It carries no password or session. The view right is NOT baked in; the content route re-checks
 * it for the token's user, so revoking a grant stops a link that has not yet expired.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library\Rest;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class LinkToken {

	/** Seconds a link stays valid. */
	public const TTL = 600;

	private const MAC_DOMAIN = 'anchor-library-link|';

	private const DISPOSITIONS = [ 'inline', 'attachment' ];

	public static function issue( int $user_id, int $file_id, string $disposition, int $now ): string {
		$disposition = \in_array( $disposition, self::DISPOSITIONS, true ) ? $disposition : 'attachment';
		$payload     = self::b64( $user_id . '.' . $file_id . '.' . $disposition . '.' . ( $now + self::TTL ) );
		return $payload . '.' . self::mac( $payload );
	}

	/** Expiry (unix time) a token issued at $now carries. */
	public static function expiry( int $now ): int {
		return $now + self::TTL;
	}

	/**
	 * @return array{user_id:int,disposition:string}|null Null for anything malformed, forged, expired or issued for another file.
	 */
	public static function verify( string $token, int $file_id, int $now ): ?array {
		$parts = \explode( '.', $token );
		if ( 2 !== \count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}
		if ( ! \hash_equals( self::mac( $parts[0] ), $parts[1] ) ) {
			return null;
		}
		$raw = \base64_decode( \strtr( $parts[0], '-_', '+/' ), true );
		if ( false === $raw || ! \preg_match( '/^(\d+)\.(\d+)\.(inline|attachment)\.(\d+)$/', $raw, $m ) ) {
			return null;
		}
		if ( (int) $m[2] !== $file_id || (int) $m[4] < $now || (int) $m[1] <= 0 ) {
			return null;
		}
		return [ 'user_id' => (int) $m[1], 'disposition' => $m[3] ];
	}

	/** Domain-separated: the prefix means a MAC minted for any other feature over the same salt can never verify here. */
	private static function mac( string $encoded_payload ): string {
		return \hash_hmac( 'sha256', self::MAC_DOMAIN . $encoded_payload, \wp_salt( 'auth' ) );
	}

	private static function b64( string $raw ): string {
		return \rtrim( \strtr( \base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
