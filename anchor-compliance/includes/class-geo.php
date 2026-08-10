<?php
/**
 * Anchor Compliance — geo ladder.
 *
 * Tier 1 (here): edge headers, free and instant — but only the headers the
 *        configured regions.trusted_proxy actually produces (see TRUST_MODES);
 *        anything else is client-forgeable and ignored.
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

	/**
	 * Which geo-header sources each regions.trusted_proxy mode honors.
	 *
	 * Any HTTP_* entry in HEADERS is client-forgeable unless a proxy the site
	 * owner controls overwrites it in transit, so a header is only consulted
	 * when the admin has declared the proxy that produces it (D009).
	 *
	 * 'geoip' (GEOIP_COUNTRY_CODE) is not an HTTP header at all — it is a
	 * server environment variable set by mod_geoip / ngx_http_geoip on the
	 * host itself. PHP maps client-sent headers into $_SERVER under an HTTP_
	 * prefix, so a visitor cannot inject it; it is trusted in every mode.
	 */
	const TRUST_MODES = [
		'none'       => [ 'geoip' ],
		'cloudflare' => [ 'cf', 'geoip' ],
		'other'      => [ 'cloudfront', 'vercel', 'geoip', 'xgeo' ],
	];

	/**
	 * @param string $mode A regions.trusted_proxy value.
	 * @return string[] The HEADERS labels honored under that mode.
	 */
	public static function trusted_labels( $mode ) {
		return isset( self::TRUST_MODES[ $mode ] ) ? self::TRUST_MODES[ $mode ] : self::TRUST_MODES['none'];
	}

	/** Placeholders that are syntactically valid but mean "unknown". */
	const NON_COUNTRIES = [ 'XX', 'T1', 'ZZ', 'A1', 'A2', 'EU', 'AP' ];

	private $country = false; // false = not yet resolved
	private $source  = 'none';

	private function opts() {
		return Anchor_Compliance_Settings::get();
	}

	/** @return string 'none' | 'cloudflare' | 'other' */
	private function trusted_proxy() {
		$regions = $this->opts()['regions'];
		$mode    = isset( $regions['trusted_proxy'] ) ? (string) $regions['trusted_proxy'] : 'none';
		return isset( self::TRUST_MODES[ $mode ] ) ? $mode : 'none';
	}

	/**
	 * @param bool $skip_remote When true, resolve from edge headers (tier 1)
	 *                          only and never fall through to the metered
	 *                          tier-2 IP-API lookup. Used by contexts where
	 *                          the country is informational and the caller is
	 *                          untrusted (the public REST consent endpoint),
	 *                          so an attacker cannot mint outbound API calls.
	 *                          Note the result (including null) is memoized
	 *                          for the request either way.
	 * @return string|null Uppercase ISO-3166-1 alpha-2, or null.
	 */
	public function country( $skip_remote = false ) {
		if ( false !== $this->country ) {
			return $this->country;
		}

		$this->country = null;

		$trusted = self::trusted_labels( $this->trusted_proxy() );

		foreach ( self::HEADERS as $header => $label ) {
			// A geo header is only believed when the configured trusted proxy
			// is the thing that sets it — anything else is client-forgeable.
			if ( ! in_array( $label, $trusted, true ) ) {
				continue;
			}
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$code = $this->normalize( wp_unslash( $_SERVER[ $header ] ) );
			if ( null !== $code ) {
				$this->country = $code;
				$this->source  = $label;
				return $this->country;
			}
		}

		if ( $skip_remote ) {
			return $this->country;
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
		$mode = $this->trusted_proxy();

		// Client-IP headers are only honored when the proxy that sets them is
		// the one the admin declared trusted (D010) — a spoofed
		// CF-Connecting-IP / X-Real-IP must never drive metered Tier-2
		// lookups or mint fresh per-/24 cache entries.
		$candidates = [ 'REMOTE_ADDR' ];
		if ( 'cloudflare' === $mode ) {
			$candidates = [ 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ];
		} elseif ( 'other' === $mode ) {
			$candidates = [ 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
		}

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$ip = trim( (string) wp_unslash( $_SERVER[ $key ] ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		// X-Forwarded-For may be a list; take the first valid entry. Only a
		// declared reverse proxy makes this header meaningful.
		if ( 'other' === $mode && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			foreach ( explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}
		return '';
	}

	/**
	 * Cache key granularity: the /24 (v4) or /64 (v6) block, never the full IP.
	 *
	 * IPv6 truncation goes through the binary form rather than exploding on
	 * ':' — most real-world IPv6 addresses are written in compressed form
	 * (e.g. "2001:db8::1"), where the elided run collapses to a single empty
	 * token. String-splitting on ':' then grabs far fewer than 4 real
	 * hextets and bakes the host's interface identifier straight into the
	 * "block" key, defeating the truncation silently (no PHP warning).
	 */
	private function ip_block( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0";
		}
		$bin = inet_pton( $ip );
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			// Should not happen — $ip is already FILTER_VALIDATE_IP'd by the
			// caller — but never let a malformed address fall through to an
			// unsafe cache key.
			return $ip;
		}
		// Zero the low 8 bytes (the /64 host identifier), keep the network prefix.
		$network = substr( $bin, 0, 8 ) . str_repeat( "\0", 8 );
		return inet_ntop( $network );
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
