<?php
/**
 * Hooks every Library AJAX handler as wp_ajax_{action}. The plugin registers no wp_ajax_nopriv_* handlers, so
 * every endpoint needs a signed-in user, and each handler also checks the nonce and its own permission.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library\Ajax;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Registry {

	/** Handler classes; each declares ACTIONS (action => static method). */
	public const HANDLERS = [ Folders::class, Files::class, Media::class, Sharing::class, Users::class, Account::class, ProductDocs::class ];

	public static function register(): void {
		foreach ( self::HANDLERS as $class ) {
			foreach ( $class::ACTIONS as $action => $method ) {
				\add_action( 'wp_ajax_' . $action, [ $class, $method ] );
			}
		}
	}
}
