<?php
/**
 * {token} placeholders for Anchor Tools emails: expansion with context-aware escaping,
 * and the registry the email builder's token palette reads. One implementation for
 * every module that sends email (announcements today; events moves onto it later).
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Email_Tokens {

	/** @var array<string,array{label:string,group:string}> */
	private static $registry = array();

	/**
	 * Replace {key} with $tokens[key]. In HTML context values are escaped: keys ending
	 * in `_url` with esc_url(), everything else with esc_html(). In text context (a
	 * subject line) values go in raw. Unknown placeholders are left untouched.
	 *
	 * @param string $template Template text.
	 * @param array  $tokens   [ key => value ].
	 * @param bool   $html     True for an HTML body, false for plain text.
	 * @return string
	 */
	public static function expand( $template, array $tokens, $html = true ) {
		$map = array();
		foreach ( $tokens as $key => $value ) {
			$value = (string) $value;
			if ( $html ) {
				$value = '_url' === substr( (string) $key, -4 ) ? esc_url( $value ) : esc_html( $value );
			}
			$map[ '{' . $key . '}' ] = $value;
		}
		return strtr( (string) $template, $map );
	}

	/**
	 * Add a token to the builder palette.
	 *
	 * @param string $key   Token name without braces.
	 * @param string $label Human label.
	 * @param string $group Palette group heading.
	 */
	public static function register( $key, $label, $group ) {
		self::$registry[ (string) $key ] = array( 'label' => (string) $label, 'group' => (string) $group );
	}

	/** @return array<string,array{label:string,group:string}> */
	public static function registered() {
		return self::$registry;
	}

	/** Empty the registry (tests). */
	public static function reset() {
		self::$registry = array();
	}
}
