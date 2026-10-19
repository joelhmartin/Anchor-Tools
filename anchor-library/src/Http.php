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
	 * The one Content-Disposition line for every file we send (REST content, AJAX stream, folder zip).
	 *
	 * `filename` is an ASCII fallback for old clients (sanitize_file_name(), then anything outside printable ASCII
	 * becomes "_"); `filename*` (RFC 5987/6266) carries the real name, percent-encoded, so non-ASCII names survive
	 * and no CR/LF or quote can reach the header. Anything but 'inline' is an attachment.
	 */
	public static function content_disposition( string $disposition, string $name ): string {
		$disp     = 'inline' === $disposition ? 'inline' : 'attachment';
		$fallback = (string) \preg_replace( '/[^\x20-\x7e]/', '_', \sanitize_file_name( $name ) );
		$fallback = \str_replace( [ '"', '\\' ], '_', $fallback );
		if ( '' === \trim( $fallback, '._' ) ) {
			$fallback = 'download';
		}
		return 'Content-Disposition: ' . $disp . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . \rawurlencode( $name );
	}

	/**
	 * End a request that has sent its own headers and body (file streaming, zip download, bare status refusals).
	 * Fires `anchor_library_halt` first.
	 *
	 * `anchor_library_halt` is a TEST SEAM (it lets a test turn the exit into an exception), not an extension
	 * point: nothing in production should hook it, and its name and timing may change without notice.
	 */
	public static function halt(): void {
		\do_action( 'anchor_library_halt' );
		exit;
	}
}
