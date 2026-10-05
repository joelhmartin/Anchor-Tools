<?php
declare(strict_types=1);

namespace Anchor\Agreements\Services;

use Anchor\Agreements\Database\SignatureRepository;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Cleanup {

	public const HOOK = 'anchor_agreements_cleanup';

	public function __construct() {
		\add_action( self::HOOK, [ self::class, 'run' ] );
		if ( ! \wp_next_scheduled( self::HOOK ) ) {
			\wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function run(): int {
		return ( new SignatureRepository() )->purge_unattached( \Anchor\Agreements\Support\Settings::purge_days() * DAY_IN_SECONDS );
	}
}
