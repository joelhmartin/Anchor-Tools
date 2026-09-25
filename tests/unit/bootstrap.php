<?php
/**
 * Bootstrap for the WordPress-free unit suite.
 *
 * Pure-logic classes (Clock, Uuid, Grading, Curriculum::sanitize, Questions)
 * must be testable without booting WordPress. They still guard on ABSPATH and
 * call a handful of WordPress functions, so this file defines exactly those and
 * nothing more - a class that needs anything else does not belong here.
 *
 * @package Anchor\Courses\Tests\Unit
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * No-op filter shim. `anchor_courses_now` is answered from a global so the
	 * unit tests can freeze time without a hook system.
	 *
	 * @param string $hook
	 * @param mixed  $value
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		if ( 'anchor_courses_now' === $hook && isset( $GLOBALS['anchor_courses_unit_now'] ) ) {
			return (int) $GLOBALS['anchor_courses_unit_now'];
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {} // phpcs:ignore
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $str ) {
		return (string) $str;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) {
		return abs( (int) $n );
	}
}
