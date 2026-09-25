<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** RFC 4122 version 4 identifiers for curriculum modules and quiz questions. */
final class Uuid {

	public static function v4(): string {
		$bytes    = \random_bytes( 16 );
		$bytes[6] = \chr( ( \ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = \chr( ( \ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return \vsprintf( '%s%s-%s-%s-%s-%s%s%s', \str_split( \bin2hex( $bytes ), 4 ) );
	}

	/** True for a lower-case RFC 4122 v4 string, the only id shape sanitisers keep. */
	public static function is_v4( string $id ): bool {
		return 1 === \preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id );
	}
}
