<?php
declare(strict_types=1);

namespace Anchor\Announcements\Rendering;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Support\Settings;
use Anchor\Announcements\Tracking\LinkRewriter;
use Anchor\Announcements\Tracking\Urls;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One announcement, one recipient: subject and full HTML, exactly as sent. */
final class Renderer {

	public static function tokens( array $recipient, string $send_token ): array {
		$user  = ! empty( $recipient['user_id'] ) ? \get_userdata( (int) $recipient['user_id'] ) : false;
		$name  = \trim( (string) ( $recipient['name'] ?? '' ) );
		$parts = '' !== $name ? \preg_split( '/\s+/', $name, 2 ) : [ '', '' ];
		$first = $user && '' !== (string) $user->first_name ? (string) $user->first_name : (string) ( $parts[0] ?? '' );
		$last  = $user && '' !== (string) $user->last_name ? (string) $user->last_name : (string) ( $parts[1] ?? '' );

		$tokens = [
			'first_name'      => $first,
			'last_name'       => $last,
			'display_name'    => $user ? (string) $user->display_name : $name,
			'username'        => $user ? (string) $user->user_login : '',
			'email'           => (string) $recipient['email'],
			'site_name'       => (string) \get_bloginfo( 'name' ),
			'site_url'        => \home_url( '/' ),
			'login_url'       => \wp_login_url(),
			'account_url'     => \function_exists( 'wc_get_page_permalink' ) ? (string) \wc_get_page_permalink( 'myaccount' ) : \admin_url( 'profile.php' ),
			'unsubscribe_url' => '' !== $send_token ? Urls::unsubscribe( $send_token ) : \home_url( '/' ),
		];

		/**
		 * Per-recipient tokens.
		 *
		 * @param array $tokens    [ key => value ].
		 * @param array $recipient email, user_id, name.
		 */
		return (array) \apply_filters( 'anchor_announcements_tokens', $tokens, $recipient );
	}

	public static function sample_recipient(): array {
		$u = \wp_get_current_user();
		return [ 'email' => (string) $u->user_email, 'user_id' => (int) $u->ID, 'name' => (string) $u->display_name ];
	}

	/**
	 * @param array|null $override subject, preheader, body (unsaved editor content).
	 * @return array{subject:string,html:string}
	 */
	public static function render( int $announcement_id, array $recipient, string $send_token, bool $track, ?array $override = null ): array {
		$subject   = null !== $override ? (string) ( $override['subject'] ?? '' ) : (string) \get_post_meta( $announcement_id, PT::META_SUBJECT, true );
		$preheader = null !== $override ? (string) ( $override['preheader'] ?? '' ) : (string) \get_post_meta( $announcement_id, PT::META_PREHEADER, true );
		$body      = \Anchor_Email_Sanitizer::body( null !== $override ? (string) ( $override['body'] ?? '' ) : (string) \get_post_meta( $announcement_id, PT::META_BODY, true ) );
		$tokens    = self::tokens( $recipient, $send_token );
		$settings  = Settings::get();

		if ( $track && '' !== $send_token ) {
			$links = (array) \get_post_meta( $announcement_id, PT::META_LINKS, true );
			$body  = LinkRewriter::rewrite( $body, $links, static fn( int $i ) => Urls::click( $send_token, $i ) );
		}

		$footer = '<p style="margin:0 0 6px;">' . \esc_html( (string) \get_bloginfo( 'name' ) ) . '<br />' . \nl2br( \esc_html( (string) $settings['footer_address'] ) ) . '</p>'
			. '<p style="margin:0;"><a href="{unsubscribe_url}" style="color:#777777;">' . \esc_html__( 'Unsubscribe', 'anchor-schema' ) . '</a></p>';

		$html = \Anchor_Email_Shell::render(
			[
				'title'       => \Anchor_Email_Tokens::expand( $subject, $tokens, true ),
				'preheader'   => \Anchor_Email_Tokens::expand( $preheader, $tokens, false ),
				'body'        => \Anchor_Email_Tokens::expand( $body, $tokens, true ),
				'footer'      => \Anchor_Email_Tokens::expand( $footer, $tokens, true ),
				'brand_color' => (string) $settings['brand_color'],
				'logo_url'    => (string) $settings['logo_url'],
			]
		);

		if ( $track && '' !== $send_token ) {
			$pixel = '<img src="' . \esc_url( Urls::open( $send_token ) ) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;" />';
			$html  = \str_replace( '</body>', $pixel . '</body>', $html );
		}

		// The subject is plain text: {site_name} comes from get_bloginfo(), which
		// WordPress stores entity-encoded ("Bob&#039;s Dental"), so decode once here.
		$subject = \html_entity_decode( \Anchor_Email_Tokens::expand( $subject, $tokens, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return [ 'subject' => $subject, 'html' => $html ];
	}
}
