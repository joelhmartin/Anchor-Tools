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
	 * defines the class itself, and an autoload attempt could only slow the check down. Filterable
	 * (`anchor_library_legacy_active`) so a test process, where a stand-in class cannot be undeclared, can answer.
	 */
	public static function legacy_active(): bool {
		return (bool) \apply_filters( 'anchor_library_legacy_active', \class_exists( self::LEGACY_CLASS, false ) );
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
		self::register_hooks();
		Schema::maybe_upgrade();
		Schema::schedule_events();
	}

	/** Every hook the plugin registered in its constructor (see tests/fixtures/library-hooks.txt). */
	private static function register_hooks(): void {
		Portal::register();
		Settings::register();
		StorageMonitor::register();
		Users::register();
		Ajax\Registry::register();
		\add_action( Schema::CRON_PRUNE_RESUME, [ Ajax\Media::class, 'cron_prune_resume' ] );
	}

	public static function render_legacy_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . \esc_html__( 'Anchor Library is waiting: deactivate the Anchor Private File Manager plugin to switch to the built-in Library.', 'anchor-schema' ) . '</p></div>';
	}
}
