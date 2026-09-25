<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Template resolution.
 *
 * A theme overrides any template by dropping `anchor-courses/{name}.php` into
 * its root - the same per-module convention `anchor-events-manager` uses for
 * its own `events/{name}.php` lookup (there is no shared locator in includes/
 * to call instead; each module owns its own). The plugin templates are
 * theme-agnostic: markup plus escaping, no business logic (brief rule 3).
 */
final class Templates {

	public function __construct() {
		\add_filter( 'template_include', [ $this, 'template_include' ] );

		// ContentGuard has no constructor call site of its own: it would
		// naturally sit beside `new Frontend\Templates()` in
		// Module::__construct() (anchor-courses.php), but that file is
		// locked to this task while a sibling worktree edits it, so its
		// registration rides along with the other Frontend class already
		// constructed unconditionally on every request. FOLLOW-UP: give it
		// its own `new`/call site in Module::__construct() once that file is
		// free to touch again (Task 18 review fix round, ruling (b)).
		ContentGuard::register();
	}

	public static function locate( string $name ): string {
		$override = \locate_template( [ 'anchor-courses/' . $name . '.php' ] );
		if ( '' !== $override ) {
			return $override;
		}
		return Module::dir() . 'templates/' . $name . '.php';
	}

	/** Render a template to a string. $vars become local variables. */
	public static function render( string $name, array $vars = [] ): string {
		$file = self::locate( $name );
		if ( ! \file_exists( $file ) ) {
			return '';
		}

		\extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		\ob_start();
		include $file;
		return (string) \ob_get_clean();
	}

	/** Use the plugin's single templates unless the theme already has one. */
	public function template_include( string $template ): string {
		if ( \is_singular( CoursePostType::CPT ) ) {
			$file = self::locate( 'single-course' );
			return \file_exists( $file ) ? $file : $template;
		}
		if ( \is_singular( LessonPostType::CPT ) ) {
			$file = self::locate( 'single-lesson' );
			return \file_exists( $file ) ? $file : $template;
		}
		return $template;
	}
}
