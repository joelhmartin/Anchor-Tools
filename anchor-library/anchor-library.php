<?php
/**
 * Anchor Tools module: Anchor Library.
 *
 * The Anchor Private File Manager, folded into Anchor Tools. Same tables, options, shortcodes, AJAX actions and
 * JS globals as the plugin, so cutover is "deactivate the plugin, enable the module". Bootstrap only: the code is
 * PSR-4 under src/ (\Anchor\Library\), the legacy helper classes sit in includes/.
 * See docs/superpowers/specs/2026-10-18-library-module-design.md.
 *
 * @package Anchor\Library
 */

if ( ! \defined( 'ABSPATH' ) ) { exit; }

if ( ! \defined( 'ANCHOR_LIBRARY_DIR' ) ) {
	\define( 'ANCHOR_LIBRARY_DIR', ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/' );
}

// Ported helper classes, kept as-is (global classes) so their behaviour and tests carry over unchanged. The old
// Anchor Private File Manager plugin declares the same classes from its own path, byte for byte; when it is active
// its copy is reused, because requiring ours would be a fatal "Cannot redeclare class" before the legacy guard
// in Module::boot() can run.
foreach ( [ 'range', 'coverage', 'watch-math', 'media-progress', 'permission-policy', 'permission-index', 'vimeo', 'copy-namer', 'upload-types', 'user-import', 'user-admin' ] as $anchor_library_helper ) {
	$anchor_library_class = 'Anchor_FM_' . \implode( '_', \array_map( 'ucfirst', \explode( '-', $anchor_library_helper ) ) );
	if ( ! \class_exists( $anchor_library_class, false ) ) {
		require_once ANCHOR_LIBRARY_DIR . 'includes/class-afm-' . $anchor_library_helper . '.php';
	}
}
unset( $anchor_library_helper, $anchor_library_class );
