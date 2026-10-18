<?php
/**
 * Library module entry point: the legacy-plugin guard and the wiring of every component.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {

	/** The old plugin's class. While it exists, the plugin owns the data and the hooks. */
	public const LEGACY_CLASS = 'Anchor_Private_File_Manager';

	/** The registry in anchor-tools.php instantiates the class; booting is the constructor's whole job. */
	public function __construct() {
		self::boot();
	}

	/**
	 * Whether the Anchor Private File Manager plugin is loaded. The autoloader is not consulted: the plugin
	 * defines the class itself, and an autoload attempt could only slow the check down.
	 */
	public static function legacy_active(): bool {
		return \class_exists( self::LEGACY_CLASS, false );
	}

	/**
	 * Wire every component, unless the old plugin is active: two copies of the same shortcodes, AJAX actions and
	 * cron hooks would double everything, so the module then stays inert and says why.
	 * Safe to call more than once: all callbacks are static, which WordPress de-duplicates.
	 */
	public static function boot(): void {
		if ( self::legacy_active() ) {
			\add_action( 'admin_notices', [ self::class, 'render_legacy_notice' ] );
			return;
		}
		// Components are wired here as they are ported.
	}

	public static function render_legacy_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . \esc_html__( 'Anchor Library is waiting: deactivate the Anchor Private File Manager plugin to switch to the built-in Library.', 'anchor-schema' ) . '</p></div>';
	}
}
