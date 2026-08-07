<?php
/**
 * Anchor Compliance — geo ladder.
 *
 * Tier 1 (here): edge headers, free and instant.
 * Tier 2 (here): an optional IP API, cached per /24 block.
 * Tier 3 (assets/frontend.js): client-side timezone, which may only relax
 *        strict -> optout, never the reverse.
 * Tier 4: the configured fallback, default strict.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Geo {

	/**
	 * Edge headers in ladder order. First one that yields a valid country wins.
	 *
	 * CF-IPCountry only appears when Cloudflare's "Add visitor location
	 * headers" managed transform is on. On Kinsta that toggle lives in Kinsta's
	 * Cloudflare account, so its presence must be verified per site — which is
	 * why the settings screen surfaces source().
	 */
	const HEADERS = [
		'HTTP_CF_IPCOUNTRY'                => 'cf',
		'HTTP_CLOUDFRONT_VIEWER_COUNTRY'   => 'cloudfront',
		'HTTP_X_VERCEL_IP_COUNTRY'         => 'vercel',
		'GEOIP_COUNTRY_CODE'               => 'geoip',
		'HTTP_X_GEO_COUNTRY'               => 'xgeo',
	];

	/** Placeholders that are syntactically valid but mean "unknown". */
	const NON_COUNTRIES = [ 'XX', 'T1', 'ZZ', 'A1', 'A2', 'EU', 'AP' ];

	private $country = false; // false = not yet resolved
	private $source  = 'none';

	private function opts() {
		return Anchor_Compliance_Settings::get();
	}

	/** @return string|null Uppercase ISO-3166-1 alpha-2, or null. */
	public function country() {
		if ( false !== $this->country ) {
			return $this->country;
		}

		$this->country = null;

		foreach ( self::HEADERS as $header => $label ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$code = $this->normalize( $_SERVER[ $header ] );
			if ( null !== $code ) {
				$this->country = $code;
				$this->source  = $label;
				return $this->country;
			}
		}

		$code = $this->lookup_via_api();
		if ( null !== $code ) {
			$this->country = $code;
			$this->source  = 'ipapi';
		}

		return $this->country;
	}

	public function source() {
		$this->country(); // resolve
		return $this->source;
	}

	private function normalize( $raw ) {
		$code = strtoupper( trim( sanitize_text_field( (string) $raw ) ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return null;
		}
		if ( in_array( $code, self::NON_COUNTRIES, true ) ) {
			return null;
		}
		return $code;
	}

	/**
	 * Tier 2. Off unless a provider and token are configured. Cached by /24
	 * block so a whole neighbourhood of visitors costs one lookup, and a
	 * failure is cached briefly too so an outage cannot stampede the API.
	 */
	private function lookup_via_api() {
		$opts     = $this->opts();
		$provider = $opts['regions']['ip_api_provider'];
		$token    = $opts['regions']['ip_api_token'];

		if ( '' === $provider || '' === $token ) {
			return null;
		}

		$ip = $this->client_ip();
		if ( '' === $ip ) {
			return null;
		}

		$key    = 'anchor_cmp_geo_' . md5( $provider . '|' . $this->ip_block( $ip ) );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return 'none' === $cached ? null : $cached;
		}

		$url = 'ipinfo' === $provider
			? "https://ipinfo.io/{$ip}/country?token=" . rawurlencode( $token )
			: "https://ipapi.co/{$ip}/country/?key=" . rawurlencode( $token );

		$res = wp_remote_get( $url, [ 'timeout' => 2, 'redirection' => 1 ] );

		$code = null;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$code = $this->normalize( wp_remote_retrieve_body( $res ) );
		}

		set_transient( $key, null === $code ? 'none' : $code, null === $code ? 5 * MINUTE_IN_SECONDS : WEEK_IN_SECONDS );

		return $code;
	}

	private function client_ip() {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$ip = trim( (string) wp_unslash( $_SERVER[ $key ] ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		// X-Forwarded-For may be a list; take the first valid entry.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			foreach ( explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}
		return '';
	}

	/** Cache key granularity: the /24 (v4) or /64 (v6) block, never the full IP. */
	private function ip_block( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0";
		}
		$parts = explode( ':', $ip );
		return implode( ':', array_slice( $parts, 0, 4 ) ) . '::';
	}

	/** @return string 'strict' | 'optout' */
	public function posture() {
		$country = $this->country();
		if ( null === $country ) {
			$fallback = $this->opts()['regions']['unknown_fallback'];
			return 'optout' === $fallback ? 'optout' : 'strict';
		}
		$strict = (array) $this->opts()['regions']['strict_countries'];
		return in_array( $country, $strict, true ) ? 'strict' : 'optout';
	}

	public function is_strict() {
		return 'strict' === $this->posture();
	}
}
