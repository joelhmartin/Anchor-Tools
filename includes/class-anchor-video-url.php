<?php
/**
 * Parses YouTube/Vimeo URLs into a provider/id/start tuple, and resolves a
 * thumbnail URL for a parsed provider/id pair. Shared by the gallery and by
 * the testimonials/speakers modules so URL handling only lives in one place.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Video_URL {

	/**
	 * @param string $url
	 * @return array{provider:string,id:string,start:int}|null
	 */
	public static function parse( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' ) return null;

		$start = 0;
		$query = [];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		if ( isset( $query['t'] ) ) {
			$start = self::seconds( $query['t'] );
		} elseif ( isset( $query['start'] ) ) {
			$start = (int) $query['start'];
		}

		if ( preg_match( '~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:embed/|shorts/|live/|v/|watch\?v=))([A-Za-z0-9_-]{6,})~', $url, $m ) ) {
			return [ 'provider' => 'youtube', 'id' => $m[1], 'start' => $start ];
		}
		// $query['v'] can carry trailing junk from a malformed/copy-pasted URL
		// (a stray `?t=5`, a trailing `/`, `)`, `.`, ...) that parse_str() folds
		// into the value rather than splitting it off; match only the leading
		// run of id characters instead of requiring the whole value to be
		// clean, so those still resolve the same id the old gallery regex did.
		if ( preg_match( '~youtube\.com/~', $url ) && ! empty( $query['v'] ) && preg_match( '~^[A-Za-z0-9_-]{6,}~', $query['v'], $vm ) ) {
			return [ 'provider' => 'youtube', 'id' => $vm[0], 'start' => $start ];
		}
		// Anchor the id with a trailing (?![0-9]) instead of consuming a
		// leading (?:.*/)? greedily: the greedy form let a second all-digit
		// path segment (an unlisted-video hash, a review-link id, ...) win
		// over the actual video id. Only a short, known set of path prefixes
		// (plus the bare vimeo.com/<id> and player.vimeo.com/video/<id> forms)
		// are recognized, so the FIRST numeric segment after vimeo.com/ always
		// wins, exactly like the old `vimeo\.com/(?:video/)?([0-9]+)` regex.
		if ( preg_match( '~vimeo\.com/(?:(?:video|channels/[^/]+|showcase/[0-9]+/video|groups/[^/]+/videos|album/[0-9]+/video)/)?([0-9]+)(?![0-9])~', $url, $m ) ) {
			return [ 'provider' => 'vimeo', 'id' => $m[1], 'start' => $start ];
		}
		return null;
	}

	private static function seconds( $t ) {
		if ( is_numeric( $t ) ) return (int) $t;
		$s = 0;
		if ( preg_match( '~(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?~', (string) $t, $m ) ) {
			$s = ( (int) ( $m[1] ?? 0 ) ) * 3600 + ( (int) ( $m[2] ?? 0 ) ) * 60 + (int) ( $m[3] ?? 0 );
		}
		return $s;
	}

	/**
	 * Resolves a thumbnail URL for a parsed provider/id pair. YouTube is a
	 * predictable static URL; Vimeo requires an oEmbed round trip, so the
	 * result is cached in a transient for a week (and briefly on failure, so
	 * a broken/private video doesn't re-hit the API on every request).
	 *
	 * $id must be the id returned by parse(), not raw user input: it is used
	 * unescaped to build the Vimeo oEmbed lookup URL and the transient key.
	 *
	 * @param string $provider 'youtube'|'vimeo'
	 * @param string $id id from parse(), not a raw URL or user-supplied value
	 * @return string
	 */
	public static function thumbnail( $provider, $id ) {
		if ( $provider === 'youtube' ) return 'https://i.ytimg.com/vi/' . rawurlencode( $id ) . '/hqdefault.jpg';
		if ( $provider !== 'vimeo' ) return '';

		$key = 'anchor_vimeo_thumb_' . $id;
		$cached = get_transient( $key );
		if ( $cached !== false ) return (string) $cached;

		$res = wp_remote_get( 'https://vimeo.com/api/oembed.json?url=' . rawurlencode( 'https://vimeo.com/' . $id ), [ 'timeout' => 5 ] );
		$thumb = '';
		if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			$thumb = isset( $body['thumbnail_url'] ) ? esc_url_raw( $body['thumbnail_url'] ) : '';
		}
		set_transient( $key, $thumb, $thumb ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );
		return $thumb;
	}
}
