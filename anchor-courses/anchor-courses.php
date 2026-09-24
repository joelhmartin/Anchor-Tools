<?php
/**
 * Anchor Tools module: Anchor Courses.
 *
 * Bootstrap only. Everything else lives under src/ and is PSR-4 autoloaded
 * (composer.json -> autoload.psr-4 "Anchor\\Courses\\").
 *
 * No declare(strict_types=1) here on purpose: WordPress calls this file's
 * hooks with loose values.
 */

namespace Anchor\Courses;

use Anchor\Courses\Database\Migrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/api.php';

class Module {

	const VERSION = '1.0.0';

	private static ?Module $instance = null;

	public function __construct() {
		self::$instance = $this;

		// No per-module activation hook exists (anchor_tools_bootstrap_modules()
		// only requires + instantiates). Run on load and again on admin_init so a
		// plugin upgrade that never touches wp-admin still converges. Brief section 28.
		Migrations::maybe_migrate();
		\add_action( 'admin_init', [ Migrations::class, 'maybe_migrate' ] );

		\add_action( 'init', [ Content\CoursePostType::class, 'register' ] );
		\add_action( 'init', [ Content\LessonPostType::class, 'register' ] );
		\add_action( 'init', [ Content\QuizPostType::class, 'register' ] );
	}

	public static function instance(): ?Module {
		return self::$instance;
	}

	/** Absolute URL of this module's assets directory. */
	public static function assets_url(): string {
		return ANCHOR_TOOLS_PLUGIN_URL . 'anchor-courses/assets/';
	}

	/** Absolute path of this module's directory. */
	public static function dir(): string {
		return ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses/';
	}
}
