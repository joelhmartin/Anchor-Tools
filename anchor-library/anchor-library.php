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

// Ported helper classes, kept as-is (global, unprefixed-namespace) so their behaviour and tests carry over unchanged.
foreach ( [ 'range', 'coverage', 'watch-math', 'media-progress', 'permission-policy', 'permission-index', 'vimeo', 'copy-namer', 'upload-types', 'user-import', 'user-admin' ] as $anchor_library_helper ) {
	require_once ANCHOR_LIBRARY_DIR . 'includes/class-afm-' . $anchor_library_helper . '.php';
}
unset( $anchor_library_helper );
