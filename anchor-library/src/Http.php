<?php
/**
 * Request plumbing shared by every Library AJAX handler: the nonce, the JSON envelope, and the one exit point.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Http {

	/** Nonce action the portal's JS (AnchorFM.nonce / AnchorAP.nonce) sends as `nonce`. */
	public const NONCE_ACTION = 'anchor_fm_nonce';

	/** Verify the nonce (dies on failure). */
	public static function nonce(): void {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/** Nonce, then a signed-in user: the preamble of nearly every handler. */
	public static function auth(): void {
		self::nonce();
		if ( ! \is_user_logged_in() ) {
			self::error( 'Unauthorized', 401 );
		}
	}

	public static function error( string $message, int $code = 400 ): void {
		\wp_send_json_error( [ 'message' => $message ], $code );
	}

	public static function success( $data = [] ): void {
		\wp_send_json_success( $data );
	}

	/**
	 * End a request that has sent its own headers and body (file streaming, zip download, bare status refusals).
	 * Fires `anchor_library_halt` first, the one seam tests use to turn the exit into an exception.
	 */
	public static function halt(): void {
		\do_action( 'anchor_library_halt' );
		exit;
	}
}
