<?php
declare(strict_types=1);

namespace Anchor\Announcements\Tracking;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Query-var tracking URLs on the home page: work with REST restricted and no login. */
final class Urls {

	public const QUERY_VAR = 'anchor_aa';

	public static function open( string $token ): string {
		return \add_query_arg( [ self::QUERY_VAR => 'o', 't' => $token ], \home_url( '/' ) );
	}

	public static function click( string $token, int $index ): string {
		return \add_query_arg( [ self::QUERY_VAR => 'c', 't' => $token, 'l' => $index ], \home_url( '/' ) );
	}

	public static function unsubscribe( string $token ): string {
		return \add_query_arg( [ self::QUERY_VAR => 'u', 't' => $token ], \home_url( '/' ) );
	}
}
