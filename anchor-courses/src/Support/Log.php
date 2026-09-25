<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Debug logging.
 *
 * Deliberately NOT \Anchor\Events\Events_Log: that class has no info()/write()
 * method (it exposes error(), order(), flag_review(), clear_review() only), and
 * courses must not depend on the events module for logging.
 */
final class Log {

	public static function write( string $event, array $context = [] ): void {
		if ( \class_exists( 'Anchor_Schema_Logger' ) ) {
			\Anchor_Schema_Logger::log( 'courses.' . $event, $context );
		}
	}
}
