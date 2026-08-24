<?php
/**
 * Global theme template tags for the Anchor Events module.
 *
 * Deliberately a separate, un-namespaced file: anchor-events-manager.php opens
 * with an unbracketed `namespace Anchor\Events;`, and PHP forbids mixing that
 * with a bracketed `namespace { }` block in the same file. Root-namespace
 * functions therefore have to live here.
 *
 * @package Anchor\Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'anchor_event_label' ) ) {
	/**
	 * The value of one event label, or '' when the event has no such label.
	 *
	 * Duration is authored text, never derived from the event's dates: the same
	 * two-date span can be a "1.5 Day Course" or a "2 Day Course", and
	 * "2.5 Day Course" cannot be computed from dates at all.
	 *
	 *     $duration = anchor_event_label( get_the_ID(), 'duration' );
	 *     if ( $duration ) {
	 *         echo '<span class="course-badge">' . esc_html( $duration ) . '</span>';
	 *     }
	 *
	 * Duplicate keys are permitted and the first match wins.
	 *
	 * @param int    $post_id Event post ID.
	 * @param string $key     One of duration|credits|format|level|custom.
	 * @return string Plain text — escape at the point of output.
	 */
	function anchor_event_label( $post_id, $key ) {
		if ( ! class_exists( '\Anchor\Events\Module' ) ) {
			return '';
		}
		$module = \Anchor\Events\Module::instance();

		return $module ? $module->get_label( $post_id, $key ) : '';
	}
}

if ( ! function_exists( 'anchor_event_labels' ) ) {
	/**
	 * Every label row for an event, in author order.
	 *
	 * Each row is { key, label, value, caption }: `caption` is the resolved
	 * display name ("Duration"), `value` the authored text ("2 Day Course").
	 *
	 * @param int $post_id Event post ID.
	 * @return array<int,array{key:string,label:string,value:string,caption:string}>
	 */
	function anchor_event_labels( $post_id ) {
		if ( ! class_exists( '\Anchor\Events\Module' ) ) {
			return [];
		}
		$module = \Anchor\Events\Module::instance();

		return $module ? $module->get_labels( $post_id ) : [];
	}
}
