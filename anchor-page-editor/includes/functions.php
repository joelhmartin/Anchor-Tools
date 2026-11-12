<?php
/**
 * Anchor Page Editor: public template tags.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'anchor_page_content' ) ) {
	/**
	 * Print a page body saved by the page editor, verbatim: shortcodes run, no wpautop/wptexturize.
	 * Same output as Anchor Framework's ac_render_raw_content(), and runs the same 'ac_raw_content'
	 * filter so existing child-theme filters keep working.
	 *
	 * @param int|null $post_id Post ID; defaults to the current post.
	 */
	function anchor_page_content( $post_id = null ) {
		$post_id = $post_id ? (int) $post_id : get_the_ID();
		$out     = do_shortcode( (string) get_post_field( 'post_content', $post_id ) );
		$out     = apply_filters( 'ac_raw_content', $out, $post_id );
		$out     = apply_filters( 'anchor_page_editor_content', $out, $post_id );
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional raw HTML page body, authored by editors.
	}
}
