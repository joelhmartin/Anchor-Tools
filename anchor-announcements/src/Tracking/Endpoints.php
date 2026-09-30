<?php
declare(strict_types=1);

namespace Anchor\Announcements\Tracking;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Rendering\Renderer;
use Anchor\Announcements\Suppression\Suppressions;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** ?anchor_aa=o|c|u&t=<token>[&l=<index>] on the home URL. */
final class Endpoints {

	private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	public function __construct() {
		\add_action( 'template_redirect', [ $this, 'handle' ], 0 );
	}

	public static function find_send( string $token ): ?object {
		if ( ! \preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		global $wpdb;
		$t   = Migrations::table( 'sends' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	public static function record( object $send, string $type, ?int $link_index = null, bool $scanner = false ): void {
		global $wpdb;
		$now = \current_time( 'mysql', true );
		$ua  = \substr( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$wpdb->insert( Migrations::table( 'events' ), [ 'send_id' => (int) $send->id, 'type' => $type, 'link_index' => $link_index, 'scanner' => $scanner ? 1 : 0, 'user_agent' => $ua, 'created_at' => $now ] );
		$t = Migrations::table( 'sends' );
		if ( 'open' === $type ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET open_count = open_count + 1, first_opened_at = COALESCE(first_opened_at, %s) WHERE id = %d", $now, (int) $send->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( 'click' === $type && ! $scanner ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET click_count = click_count + 1, first_clicked_at = COALESCE(first_clicked_at, %s) WHERE id = %d", $now, (int) $send->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	public static function click_target( object $send, int $index ): string {
		$links = (array) \get_post_meta( (int) $send->announcement_id, PT::META_LINKS, true );
		if ( ! isset( $links[ $index ] ) ) {
			return '';
		}
		$recipient = [ 'email' => (string) $send->email, 'user_id' => (int) $send->user_id, 'name' => (string) $send->name ];
		$url       = \Anchor_Email_Tokens::expand( (string) $links[ $index ], Renderer::tokens( $recipient, (string) $send->token ), false );
		return \preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	public static function is_scanner_click( object $send ): bool {
		if ( empty( $send->sent_at ) || (int) $send->open_count > 0 ) {
			return false;
		}
		return ( \time() - (int) \strtotime( $send->sent_at . ' UTC' ) ) < 10;
	}

	public static function unsubscribe( object $send ): void {
		Suppressions::add( (string) $send->email, 'unsubscribed', (int) $send->announcement_id );
		self::record( $send, 'unsubscribe' );
	}

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- token-authenticated, no session.
		$kind = isset( $_GET[ Urls::QUERY_VAR ] ) ? \sanitize_key( \wp_unslash( $_GET[ Urls::QUERY_VAR ] ) ) : '';
		if ( '' === $kind ) {
			return;
		}
		$send = self::find_send( isset( $_GET['t'] ) ? \sanitize_key( \wp_unslash( $_GET['t'] ) ) : '' );
		\nocache_headers();

		if ( 'o' === $kind ) {
			if ( $send ) {
				self::record( $send, 'open' );
			}
			\header( 'Content-Type: image/gif' );
			echo \base64_decode( self::GIF ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a fixed 1x1 GIF.
			exit;
		}

		if ( 'c' === $kind ) {
			$index  = isset( $_GET['l'] ) ? \absint( $_GET['l'] ) : -1;
			$target = $send && $index >= 0 ? self::click_target( $send, $index ) : '';
			if ( '' === $target ) {
				\wp_safe_redirect( \home_url( '/' ) );
				exit;
			}
			self::record( $send, 'click', $index, self::is_scanner_click( $send ) );
			\wp_redirect( $target, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- target comes only from the announcement's stored link list.
			exit;
		}

		if ( 'u' === $kind ) {
			$done = false;
			if ( $send && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				self::unsubscribe( $send );
				$done = true;
			}
			\status_header( $send ? 200 : 404 );
			$email = $send ? (string) $send->email : '';
			include \dirname( __DIR__, 2 ) . '/templates/unsubscribe.php';
			exit;
		}
		// phpcs:enable
	}
}
