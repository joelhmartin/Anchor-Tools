<?php
declare(strict_types=1);

namespace Anchor\Shipping\Support;

use Anchor\Shipping\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Renders a module template (templates/<path>.php) with the given variables in scope. */
final class Template {

	public static function render( string $path, array $vars ): string {
		\ob_start();
		( static function ( string $file, array $vars ): void {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $file;
		} )( Module::dir() . '/templates/' . $path . '.php', $vars );
		return (string) \ob_get_clean();
	}
}
