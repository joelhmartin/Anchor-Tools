<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The single source of "now" for the module.
 *
 * Every stored datetime is UTC in MySQL format, so timers and expiry survive a
 * site timezone change. `anchor_courses_now` lets tests and the E2E suite
 * advance the clock without sleeping.
 */
final class Clock {

	/** MySQL's "no date" sentinel. Legacy rows hold it where NULL was meant. */
	public const ZERO_DATE = '0000-00-00 00:00:00';

	public static function timestamp(): int {
		return (int) \apply_filters( 'anchor_courses_now', \time() );
	}

	public static function now(): string {
		return \gmdate( 'Y-m-d H:i:s', self::timestamp() );
	}

	/** MySQL UTC datetime $seconds in the future (negative = past). */
	public static function offset( int $seconds ): string {
		return \gmdate( 'Y-m-d H:i:s', self::timestamp() + $seconds );
	}

	/**
	 * A nullable DATETIME column as read from the DB: null, '' and the zero-date
	 * all mean "unset" and come back as null.
	 */
	public static function nullable( $mysql ): ?string {
		if ( null === $mysql ) {
			return null;
		}
		$mysql = (string) $mysql;
		return ( '' === $mysql || self::ZERO_DATE === $mysql ) ? null : $mysql;
	}

	/** Parse a stored MySQL UTC datetime back to a timestamp; 0 when empty/invalid. */
	public static function to_timestamp( ?string $mysql ): int {
		$mysql = self::nullable( $mysql );
		if ( null === $mysql ) {
			return 0;
		}
		$ts = \strtotime( $mysql . ' UTC' );
		return false === $ts ? 0 : $ts;
	}
}
