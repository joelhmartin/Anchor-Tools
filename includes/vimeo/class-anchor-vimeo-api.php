<?php
/**
 * Shared Vimeo API client: one place that knows the access token and how to ask Vimeo for downloadable renditions.
 *
 * Callers: the Library REST routes (app video downloads) and the Webinars thumbnail fetch (token only).
 * Never logs or returns the token, and renditions() results carry signed links, so callers must not log them either.
 *
 * @package Anchor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Vimeo_Api {

	/** Library setting holding the downloads token (write-only in the UI). */
	const OPTION = 'anchor_vimeo_token';

	/** Transient prefix for cached rendition lists, one per video id. */
	const CACHE_PREFIX = 'anchor_vimeo_dl_';

	/** Cache lifetime, and the minimum remaining life every link needs for a list to be cached. */
	const CACHE_TTL      = 30 * MINUTE_IN_SECONDS;
	const MIN_LINK_LIFE  = 2 * HOUR_IN_SECONDS;

	/** Token precedence: ANCHOR_VIMEO_TOKEN constant, the Library option, then the Webinars API key. '' when none. */
	public static function token(): string {
		if ( defined( 'ANCHOR_VIMEO_TOKEN' ) && is_string( ANCHOR_VIMEO_TOKEN ) && '' !== trim( ANCHOR_VIMEO_TOKEN ) ) {
			return trim( ANCHOR_VIMEO_TOKEN );
		}
		$opt = get_option( self::OPTION, '' );
		if ( is_string( $opt ) && '' !== trim( $opt ) ) {
			return trim( $opt );
		}
		$webinars = get_option( 'anchor_webinars_settings', [] );
		if ( is_array( $webinars ) && ! empty( $webinars['vimeo_api_key'] ) && is_string( $webinars['vimeo_api_key'] ) ) {
			return trim( $webinars['vimeo_api_key'] );
		}
		return '';
	}

	/**
	 * wp_default_autoload_value filter (WP 6.6+): the token is created with autoload off however it is first saved
	 * (settings form, WP-CLI without --autoload), so the secret never rides along in alloptions.
	 */
	public static function default_autoload( $autoload, $option ) {
		return self::OPTION === $option ? false : $autoload;
	}

	public static function is_configured(): bool {
		return '' !== self::token();
	}

	/**
	 * MP4 renditions for a video, highest resolution first.
	 *
	 * @return array<int,array{rendition:string,size:int,url:string,expires:int}>|WP_Error
	 */
	public static function renditions( string $vimeo_id ) {
		$token = self::token();
		if ( '' === $token ) {
			return new WP_Error( 'anchor_vimeo_unconfigured', __( 'No Vimeo token is configured.', 'anchor-schema' ) );
		}
		if ( ! preg_match( '/^\d+$/', $vimeo_id ) ) {
			return new WP_Error( 'anchor_vimeo_http', __( 'Invalid Vimeo video id.', 'anchor-schema' ), [ 'status' => 400 ] );
		}

		$cache_key = self::CACHE_PREFIX . $vimeo_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.vimeo.com/videos/' . $vimeo_id . '?fields=download',
			[
				'timeout' => 8,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/vnd.vimeo.*+json;version=3.4',
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'anchor_vimeo_http', __( 'Could not reach Vimeo.', 'anchor-schema' ), [ 'status' => 0 ] );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'anchor_vimeo_http', __( 'Vimeo returned an error.', 'anchor-schema' ), [ 'status' => $status ] );
		}

		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$list  = self::parse_renditions( is_array( $body ) ? ( $body['download'] ?? [] ) : [] );

		if ( $list ) {
			$soonest = min( array_column( $list, 'expires' ) );
			if ( $soonest - time() > self::MIN_LINK_LIFE ) {
				set_transient( $cache_key, $list, self::CACHE_TTL );
			}
		}
		return $list;
	}

	/** Keep MP4 renditions named like "540p" with a link; sort by height, highest first. */
	private static function parse_renditions( $download ): array {
		$out = [];
		foreach ( is_array( $download ) ? $download : [] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$rendition = (string) ( $item['rendition'] ?? '' );
			$link      = (string) ( $item['link'] ?? '' );
			if ( ! preg_match( '/^\d{3,4}p$/', $rendition ) || 'video/mp4' !== ( $item['type'] ?? '' ) || '' === $link ) {
				continue;
			}
			$expires = isset( $item['expires'] ) ? strtotime( (string) $item['expires'] ) : false;
			$out[]   = [
				'rendition' => $rendition,
				'size'      => (int) ( $item['size'] ?? 0 ),
				'url'       => $link,
				'expires'   => $expires ? (int) $expires : 0,
			];
		}
		usort( $out, static fn( $a, $b ) => (int) $b['rendition'] <=> (int) $a['rendition'] );
		return $out;
	}
}
