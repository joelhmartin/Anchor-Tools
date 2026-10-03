<?php
/**
 * Anchor Tools module: Anchor Agreements.
 * Bootstrap only; everything else is PSR-4 under src/. No strict_types here on purpose.
 */

namespace Anchor\Agreements;

use Anchor\Agreements\Database\Migrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {

	const VERSION = '1.0.0';

	private static ?Module $instance = null;

	public static function instance(): ?Module {
		return self::$instance;
	}

	public static function url( string $path ): string {
		return \plugins_url( $path, __FILE__ );
	}

	public static function dir(): string {
		return __DIR__;
	}

	public function __construct() {
		self::$instance = $this;
		Migrations::maybe_migrate();
		\add_action( 'admin_init', [ Migrations::class, 'maybe_migrate' ] );
		\add_action( 'init', [ Content\AgreementPostType::class, 'register' ] );
		// Later tasks register their services here (see each task's "wire it" step).
	}
}
