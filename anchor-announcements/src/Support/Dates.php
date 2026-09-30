<?php
declare(strict_types=1);

namespace Anchor\Announcements\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Dates {

	/**
	 * Site-timezone calendar days (inclusive) to GMT bounds for SQL.
	 *
	 * @return array{0:?string,1:?string}
	 */
	public static function gmt_range( string $from, string $to ): array {
		return [ self::bound( $from, '00:00:00' ), self::bound( $to, '23:59:59' ) ];
	}

	private static function bound( string $day, string $time ): ?string {
		if ( ! \preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			return null;
		}
		$local = \date_create_immutable( $day . ' ' . $time, \wp_timezone() );
		return $local ? $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null;
	}
}
