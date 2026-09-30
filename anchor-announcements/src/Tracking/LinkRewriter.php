<?php
declare(strict_types=1);

namespace Anchor\Announcements\Tracking;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Finds the trackable links in a body template and swaps each for its click URL. The
 * link list is captured once per announcement (at send) and is the ONLY source a click
 * redirect reads, so the click endpoint can never be used as an open redirect.
 */
final class LinkRewriter {

	private const HREF = '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is';

	/** @return list<string> Decoded href templates, in first-seen order. */
	public static function links( string $body ): array {
		$links = [];
		\preg_match_all( self::HREF, $body, $m );
		foreach ( $m[3] as $raw ) {
			$href = \html_entity_decode( \trim( (string) $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( self::trackable( $href ) && ! \in_array( $href, $links, true ) ) {
				$links[] = $href;
			}
		}
		return $links;
	}

	/** @param callable(int):string $url_for */
	public static function rewrite( string $html, array $links, callable $url_for ): string {
		return (string) \preg_replace_callback(
			self::HREF,
			static function ( $m ) use ( $links, $url_for ) {
				$href  = \html_entity_decode( \trim( (string) $m[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$index = \array_search( $href, $links, true );
				if ( false === $index || ! self::trackable( $href ) ) {
					return $m[0];
				}
				return $m[1] . '"' . \esc_attr( $url_for( (int) $index ) ) . '"';
			},
			$html
		);
	}

	private static function trackable( string $href ): bool {
		if ( '' === $href || \str_contains( $href, '{unsubscribe_url}' ) ) {
			return false;
		}
		return (bool) \preg_match( '#^(https?://|\{[a-z0-9_]+\})#i', $href );
	}
}
