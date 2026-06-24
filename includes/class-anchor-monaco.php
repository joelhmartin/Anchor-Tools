<?php
/**
 * Shared Monaco editor loader for Anchor Tools code metaboxes.
 *
 * Modules that want a tabbed Monaco editor call Anchor_Monaco::enqueue( CPT )
 * from their admin_enqueue_scripts handler (already guarded to the CPT edit
 * screen) and wrap their code textareas in:
 *   <div class="anchor-monaco" data-anchor-monaco='[{"id":"x","label":"HTML","lang":"html"}]'>
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Monaco {

	const VERSION     = '1.0.0';
	const MONACO_VER  = '0.52.2';
	const MONACO_BASE = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.52.2/min';

	/**
	 * Enqueue Monaco loader + glue on the current admin screen.
	 * Caller is responsible for restricting to the right CPT/post.php screen.
	 *
	 * @param string $cpt Post type the editor is being mounted for.
	 */
	public static function enqueue( $cpt ) {
		wp_enqueue_media();

		wp_enqueue_script(
			'anchor-monaco-loader',
			self::MONACO_BASE . '/vs/loader.js',
			array(),
			self::MONACO_VER,
			true
		);

		wp_enqueue_style(
			'anchor-monaco',
			Anchor_Asset_Loader::url( 'assets/anchor-monaco.css' ),
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'anchor-monaco',
			Anchor_Asset_Loader::url( 'assets/anchor-monaco.js' ),
			array( 'jquery', 'anchor-monaco-loader' ),
			self::VERSION,
			true
		);

		$post_id = isset( $GLOBALS['post'] ) && $GLOBALS['post'] ? (int) $GLOBALS['post']->ID : 0;

		wp_localize_script(
			'anchor-monaco',
			'AnchorMonaco',
			array(
				'monacoBase' => self::MONACO_BASE,
				'mediaTitle' => __( 'Select or upload media', 'anchor-schema' ),
				'mediaBtn'   => __( 'Use this URL', 'anchor-schema' ),
				'postId'     => $post_id,
				'cpt'        => (string) $cpt,
			)
		);
	}
}
