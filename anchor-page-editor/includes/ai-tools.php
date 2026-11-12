<?php
/**
 * AI tool registry. Each tool: name, description, parameters (JSON Schema), callback.
 * The callback receives an associative args array and returns a string result.
 *
 * @package AnchorTools (Page Editor module; ported from Anchor Framework 1.2.1 inc/ai-tools.php)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the registered tools.
 *
 * @return array<string,array>
 */
function anchor_pe_ai_tools() {
	$tools = array(
		'read_theme_file' => array(
			'description' => 'Read a file from the active theme for reference (CSS, JS, or PHP template). Use this to inspect existing styles/markup before proposing edits. Paths are relative to the theme root, e.g. "assets/css/custom.css" or "header.php".',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'path' => array(
						'type'        => 'string',
						'description' => 'Theme-relative file path, e.g. assets/css/custom.css',
					),
				),
				'required'   => array( 'path' ),
			),
			'callback'    => 'anchor_pe_ai_tool_read_theme_file',
		),
		'submit_edits'    => array(
			'description' => 'Submit your final proposed changes to the page. Include ONLY the fields you want to change (html, css, and/or js). Always include a short explanation. Call this exactly once when you are done.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'html'        => array(
						'type'        => 'string',
						'description' => 'The full new page body HTML (only if changing it).',
					),
					'css'         => array(
						'type'        => 'string',
						'description' => 'The full new custom CSS (only if changing it).',
					),
					'js'          => array(
						'type'        => 'string',
						'description' => 'The full new custom JS (only if changing it).',
					),
					'explanation' => array(
						'type'        => 'string',
						'description' => 'A one or two sentence summary of what you changed and why.',
					),
				),
				'required'   => array( 'explanation' ),
			),
			'callback'    => null, // Terminal tool; handled by the loop.
		),
	);

	/**
	 * Allow future tools (SEMrush, MCP bridge, etc.) to register.
	 *
	 * @param array $tools Tool registry.
	 */
	$tools = apply_filters( 'ac_ai_tools', $tools ); // Same filter name as Anchor Framework, so existing extensions keep working.
	return apply_filters( 'anchor_pe_ai_tools', $tools );
}

/**
 * read_theme_file implementation. Realpath-confined to the theme directory.
 *
 * @param array $args { path: string }.
 * @return string File contents or an error message.
 */
function anchor_pe_ai_tool_read_theme_file( $args ) {
	$rel = isset( $args['path'] ) ? (string) $args['path'] : '';
	if ( '' === $rel ) {
		return 'ERROR: no path provided.';
	}
	$theme_dir = realpath( get_template_directory() );
	$target    = realpath( $theme_dir . '/' . ltrim( $rel, '/' ) );

	if ( false === $target || false === $theme_dir ) {
		return 'ERROR: file not found: ' . $rel;
	}
	// Confine to theme dir (prevent traversal).
	if ( 0 !== strpos( $target, $theme_dir . DIRECTORY_SEPARATOR ) ) {
		return 'ERROR: access denied (outside theme): ' . $rel;
	}
	$ext     = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
	$allowed = array( 'css', 'js', 'php', 'html', 'txt' );
	if ( ! in_array( $ext, $allowed, true ) ) {
		return 'ERROR: file type not allowed: .' . $ext;
	}
	$size = filesize( $target );
	if ( $size > 200 * 1024 ) {
		return 'ERROR: file too large (' . $size . ' bytes).';
	}
	$contents = file_get_contents( $target );
	if ( false === $contents ) {
		return 'ERROR: could not read file.';
	}
	return $contents;
}
