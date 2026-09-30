<?php
declare(strict_types=1);

namespace Anchor\Announcements\Sending;

use Anchor\Announcements\Rendering\Renderer;
use Anchor\Announcements\Support\Settings;
use Anchor\Announcements\Tracking\Urls;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Renders and hands one email to wp_mail(): whatever transport the site uses delivers it. */
final class Mailer {

	/** @return true|\WP_Error */
	public static function send( int $announcement_id, array $recipient, string $token, bool $track, string $subject_prefix = '' ) {
		$mail    = Renderer::render( $announcement_id, $recipient, $token, $track );
		$error   = null;
		$catch   = static function ( \WP_Error $e ) use ( &$error ) { $error = $e; };
		\add_action( 'wp_mail_failed', $catch );
		try {
			$ok = \wp_mail( (string) $recipient['email'], $subject_prefix . $mail['subject'], $mail['html'], self::headers( '' !== $token ? Urls::unsubscribe( $token ) : '' ) );
		} catch ( \Throwable $t ) {
			$ok    = false;
			$error = new \WP_Error( 'mail_exception', $t->getMessage() );
		} finally {
			\remove_action( 'wp_mail_failed', $catch );
		}
		return $ok ? true : ( $error ?? new \WP_Error( 'mail_failed', \__( 'wp_mail() returned false.', 'anchor-schema' ) ) );
	}

	public static function headers( string $unsubscribe_url ): array {
		$s       = Settings::get();
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		if ( '' !== $s['from_email'] ) {
			$headers[] = 'From: ' . \str_replace( [ "\r", "\n", '"' ], '', (string) $s['from_name'] ) . ' <' . $s['from_email'] . '>';
		}
		if ( '' !== $s['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $s['reply_to'];
		}
		if ( '' !== $unsubscribe_url ) {
			$headers[] = 'List-Unsubscribe: <' . $unsubscribe_url . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}
		// Our own open/click tracking is already in the HTML; stop Mailgun rewriting it again. Other providers ignore this header.
		$headers[] = 'X-Mailgun-Track: no';

		/**
		 * Filter announcement mail headers.
		 *
		 * @param string[] $headers
		 */
		return (array) \apply_filters( 'anchor_announcements_mail_headers', $headers );
	}
}
