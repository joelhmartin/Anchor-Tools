<?php
/**
 * The email-safe wp_kses() allowlist: tables, inline styles, images and links, no
 * script, forms or embeds. Ported from the events module's template allowlist so
 * both modules accept the same markup.
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Email_Sanitizer {

	/**
	 * Sanitize an email body. `{token}` placeholders survive, including inside href
	 * and src (wp_kses would otherwise drop a scheme-less "{login_url}" value).
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function body( $html ) {
		$html      = preg_replace_callback(
			'/\b(href|src)=(["\'])\{([a-z0-9_]+)\}\2/i',
			static function ( $m ) {
				return $m[1] . '=' . $m[2] . 'anchor-token://' . $m[3] . $m[2];
			},
			(string) $html
		);
		$clean     = wp_kses( $html, self::allowed_html(), array_merge( wp_allowed_protocols(), array( 'anchor-token' ) ) );
		return preg_replace( '#anchor-token://([a-z0-9_]+)#i', '{$1}', $clean );
	}

	/** @return array wp_kses() allowed_html. */
	public static function allowed_html() {
		$allowed = array(
			'table'  => array( 'role' => true, 'width' => true, 'cellpadding' => true, 'cellspacing' => true, 'style' => true, 'align' => true, 'border' => true ),
			'thead'  => array(),
			'tbody'  => array(),
			'tr'     => array( 'style' => true ),
			'td'     => array( 'style' => true, 'align' => true, 'valign' => true, 'width' => true, 'colspan' => true ),
			'th'     => array( 'style' => true, 'align' => true, 'valign' => true, 'width' => true, 'colspan' => true ),
			'div'    => array( 'style' => true, 'class' => true, 'id' => true, 'align' => true ),
			'span'   => array( 'style' => true, 'class' => true, 'id' => true ),
			'p'      => array( 'style' => true, 'class' => true, 'align' => true ),
			'br'     => array(),
			'hr'     => array( 'style' => true ),
			'h1'     => array( 'style' => true ),
			'h2'     => array( 'style' => true ),
			'h3'     => array( 'style' => true ),
			'h4'     => array( 'style' => true ),
			'a'      => array( 'href' => true, 'style' => true, 'target' => true, 'rel' => true, 'class' => true, 'id' => true ),
			'img'    => array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'style' => true, 'class' => true ),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'u'      => array(),
			'ul'     => array( 'style' => true ),
			'ol'     => array( 'style' => true ),
			'li'     => array( 'style' => true ),
			'blockquote' => array( 'style' => true ),
		);

		/**
		 * Filter the email body allowlist.
		 *
		 * @param array $allowed wp_kses() allowed_html.
		 */
		return (array) apply_filters( 'anchor_email_allowed_html', $allowed );
	}
}
