<?php
/**
 * Anchor Locations — Phase 7: Search Console + GA4 Reporting.
 *
 * Pulls per-page Google Search Console (GSC) + GA4 metrics for the module's
 * location and service pages and surfaces them in an admin-only "Analytics"
 * report. Auth is server-to-server via a Google **service account** — the admin
 * pastes the service-account JSON key; there is NO interactive OAuth redirect.
 *
 * Deliberately dependency-free: the JWT is built and signed with `openssl_sign`
 * (RS256), HTTP goes through `wp_remote_post` / `wp_remote_get`, and JSON uses
 * core `json_encode` / `json_decode`. No `google/apiclient`.
 *
 * Graceful dormancy: with no credentials, `is_configured()` is false, the report
 * page shows a "configure" notice, and NO HTTP is ever attempted. Every network
 * path returns a `WP_Error` on failure — never fatal, never poisons the cache.
 *
 * The service-account private key is sensitive: stored `autoload=false`,
 * capability-gated (`manage_options`), never echoed back into the form (a
 * "configured ✓" note is shown instead), and never emitted on the front end.
 *
 * Kept in its own class to keep anchor-locations.php lean; instantiated from
 * Module::__construct.
 *
 * @package Anchor\Locations
 */
namespace Anchor\Locations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Analytics {

	/** Option storing parsed service-account creds + GSC/GA4 targets (autoload=false). */
	const OPTION = 'anchor_locations_analytics';

	/** Nonce action for the config form + Refresh handler. */
	const NONCE = 'anchor_locations_analytics_cfg';

	/** Default Google OAuth2 token endpoint (overridable from the key's token_uri). */
	const TOKEN_URI = 'https://oauth2.googleapis.com/token';

	/** Read-only OAuth scopes. */
	const SCOPE_GSC = 'https://www.googleapis.com/auth/webmasters.readonly';
	const SCOPE_GA4 = 'https://www.googleapis.com/auth/analytics.readonly';

	/** Transient prefixes. */
	const TOKEN_TRANSIENT = 'anchor_al_analytics_tok_';
	const GSC_TRANSIENT   = 'anchor_al_analytics_gsc';
	const GA4_TRANSIENT   = 'anchor_al_analytics_ga4';

	/** Normalized-metrics cache lifetime (seconds). 12h. */
	const CACHE_TTL = 43200;

	/** Report flag thresholds. */
	const OPPORTUNITY_MIN_IMPRESSIONS = 10;
	const OPPORTUNITY_POSITION_FLOOR  = 10.0; // avg position poorer than this = opportunity.

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'register_pages' ], 50 );
		\add_action( 'admin_post_anchor_locations_analytics_save', [ $this, 'handle_save' ] );
		\add_action( 'admin_post_anchor_locations_analytics_refresh', [ $this, 'handle_refresh' ] );
	}

	/* ================================================================
	 * A. Config
	 * ================================================================ */

	/** @return array The parsed settings array (may be empty). */
	public function settings(): array {
		$o = \get_option( self::OPTION, [] );
		return \is_array( $o ) ? $o : [];
	}

	/** The GSC site string (`sc-domain:...` or a URL prefix), or ''. */
	public function gsc_site(): string {
		return (string) ( $this->settings()['gsc_site'] ?? '' );
	}

	/** The GA4 property id (numeric string), or ''. */
	public function ga4_property(): string {
		return (string) ( $this->settings()['ga4_property'] ?? '' );
	}

	private function client_email(): string {
		return (string) ( $this->settings()['client_email'] ?? '' );
	}
	private function private_key(): string {
		return (string) ( $this->settings()['private_key'] ?? '' );
	}
	private function token_uri(): string {
		$u = (string) ( $this->settings()['token_uri'] ?? '' );
		return $u !== '' ? $u : self::TOKEN_URI;
	}

	/**
	 * Configured = we have a usable service account (client_email + private_key)
	 * AND at least one report target (a GSC site or a GA4 property).
	 */
	public function is_configured(): bool {
		$s = $this->settings();
		$has_key    = ! empty( $s['client_email'] ) && ! empty( $s['private_key'] );
		$has_target = ! empty( $s['gsc_site'] ) || ! empty( $s['ga4_property'] );
		return $has_key && $has_target;
	}

	/**
	 * Parse + persist a config submission. Pure enough to unit-test directly.
	 *
	 * @param string $json_key     Pasted service-account JSON (may be '' to keep the existing key).
	 * @param string $gsc_site     GSC site (`sc-domain:example.com` or URL prefix).
	 * @param string $ga4_property GA4 numeric property id.
	 * @return true|\WP_Error      True on save; WP_Error when a non-empty key won't parse.
	 */
	public function save_config( string $json_key, string $gsc_site, string $ga4_property ) {
		$existing = $this->settings();
		$out = [
			'gsc_site'     => $this->sanitize_gsc_site( $gsc_site ),
			'ga4_property' => \preg_replace( '/[^0-9]/', '', $ga4_property ),
			'client_email' => (string) ( $existing['client_email'] ?? '' ),
			'private_key'  => (string) ( $existing['private_key'] ?? '' ),
			'token_uri'    => (string) ( $existing['token_uri'] ?? self::TOKEN_URI ),
		];

		$json_key = \trim( $json_key );
		if ( $json_key !== '' ) {
			$parsed = \json_decode( $json_key, true );
			if ( ! \is_array( $parsed ) || empty( $parsed['client_email'] ) || empty( $parsed['private_key'] ) ) {
				return new \WP_Error(
					'anchor_al_bad_key',
					\__( 'That does not look like a valid service-account JSON key (missing client_email / private_key).', 'anchor-schema' )
				);
			}
			$out['client_email'] = (string) $parsed['client_email'];
			$out['private_key']  = (string) $parsed['private_key'];
			$out['token_uri']    = ! empty( $parsed['token_uri'] ) ? (string) $parsed['token_uri'] : self::TOKEN_URI;
		}

		$out['key_present'] = ( $out['client_email'] !== '' && $out['private_key'] !== '' );

		\update_option( self::OPTION, $out, false ); // sensitive: never autoload.
		// A creds/target change invalidates any cached token + metrics.
		$this->clear_caches();
		return true;
	}

	/** Normalize the GSC site value; keep `sc-domain:` verbatim, else trim. */
	private function sanitize_gsc_site( string $site ): string {
		$site = \trim( $site );
		if ( $site === '' ) { return ''; }
		if ( \strpos( $site, 'sc-domain:' ) === 0 ) {
			return 'sc-domain:' . \strtolower( \trim( \substr( $site, 10 ) ) );
		}
		return \esc_url_raw( $site );
	}

	/* ================================================================
	 * B. Auth — service-account JWT → access token
	 * ================================================================ */

	/** URL-safe base64 without padding (JWT segment encoding). */
	public static function base64url( string $bin ): string {
		return \rtrim( \strtr( \base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/**
	 * Build a signed RS256 JWT asserting the given scopes. Pure aside from time().
	 *
	 * @param string[] $scopes
	 * @return string|\WP_Error The compact JWT, or WP_Error if signing is impossible.
	 */
	public function build_jwt( array $scopes ) {
		$email = $this->client_email();
		$key   = $this->private_key();
		if ( $email === '' || $key === '' ) {
			return new \WP_Error( 'anchor_al_no_key', \__( 'No service-account key configured.', 'anchor-schema' ) );
		}
		if ( ! \function_exists( 'openssl_sign' ) ) {
			return new \WP_Error( 'anchor_al_no_openssl', \__( 'OpenSSL is not available on this server.', 'anchor-schema' ) );
		}

		$now    = \time();
		$header = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
		$claims = [
			'iss'   => $email,
			'scope' => \implode( ' ', $scopes ),
			'aud'   => $this->token_uri(),
			'iat'   => $now,
			'exp'   => $now + 3600,
		];

		$signing_input = self::base64url( (string) \wp_json_encode( $header ) )
			. '.' . self::base64url( (string) \wp_json_encode( $claims ) );

		$sig = '';
		$ok  = \openssl_sign( $signing_input, $sig, $key, \OPENSSL_ALGO_SHA256 );
		if ( ! $ok || $sig === '' ) {
			return new \WP_Error( 'anchor_al_sign_failed', \__( 'Could not sign the request. Check the service-account private key.', 'anchor-schema' ) );
		}
		return $signing_input . '.' . self::base64url( $sig );
	}

	/**
	 * Get (and cache) an OAuth2 access token for the given scopes.
	 *
	 * @param string[] $scopes
	 * @return string|\WP_Error Bearer token, or WP_Error on any failure.
	 */
	public function get_access_token( array $scopes ) {
		\sort( $scopes );
		$cache_key = self::TOKEN_TRANSIENT . \md5( \implode( ' ', $scopes ) );
		$cached    = \get_transient( $cache_key );
		if ( \is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$jwt = $this->build_jwt( $scopes );
		if ( \is_wp_error( $jwt ) ) { return $jwt; }

		$resp = \wp_remote_post( $this->token_uri(), [
			'timeout' => 20,
			'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
			'body'    => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			],
		] );
		if ( \is_wp_error( $resp ) ) { return $resp; }

		$code = (int) \wp_remote_retrieve_response_code( $resp );
		$body = \json_decode( (string) \wp_remote_retrieve_body( $resp ), true );
		if ( $code !== 200 || ! \is_array( $body ) || empty( $body['access_token'] ) ) {
			$detail = \is_array( $body ) && ! empty( $body['error_description'] ) ? (string) $body['error_description'] : ( 'HTTP ' . $code );
			return new \WP_Error( 'anchor_al_token_failed', \sprintf(
				/* translators: %s: error detail from the token endpoint. */
				\__( 'Token request failed: %s', 'anchor-schema' ), $detail
			) );
		}

		$token   = (string) $body['access_token'];
		$expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		$ttl     = \max( 60, $expires - 60 );
		\set_transient( $cache_key, $token, $ttl );
		return $token;
	}

	/* ================================================================
	 * C. Fetch + normalize
	 * ================================================================ */

	/**
	 * Normalize a decoded GSC Search Analytics response into a per-path map.
	 * Pure: no WP calls beyond URL parsing.
	 *
	 * @param array $body Decoded response (expects `rows` of {keys:[url], clicks, impressions, ctr, position}).
	 * @return array<string,array{clicks:int,impressions:int,ctr:float,position:float}> keyed by URL path.
	 */
	public function normalize_gsc( array $body ): array {
		$out = [];
		$rows = isset( $body['rows'] ) && \is_array( $body['rows'] ) ? $body['rows'] : [];
		foreach ( $rows as $row ) {
			$url  = isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';
			if ( $url === '' ) { continue; }
			$path = $this->path_of( $url );
			if ( $path === '' ) { continue; }
			$out[ $path ] = [
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'ctr'         => (float) ( $row['ctr'] ?? 0 ),
				'position'    => (float) ( $row['position'] ?? 0 ),
			];
		}
		return $out;
	}

	/**
	 * Normalize a decoded GA4 runReport response into a per-path map.
	 * Metric order follows the request: [sessions, conversions].
	 *
	 * @param array $body Decoded response (expects `rows` of {dimensionValues:[{value}], metricValues:[{value},{value}]}).
	 * @return array<string,array{sessions:int,conversions:float}> keyed by pagePath.
	 */
	public function normalize_ga4( array $body ): array {
		$out = [];
		$rows = isset( $body['rows'] ) && \is_array( $body['rows'] ) ? $body['rows'] : [];
		foreach ( $rows as $row ) {
			$path = isset( $row['dimensionValues'][0]['value'] ) ? (string) $row['dimensionValues'][0]['value'] : '';
			if ( $path === '' ) { continue; }
			$path = $this->normalize_path( $path );
			$sessions    = isset( $row['metricValues'][0]['value'] ) ? (int) $row['metricValues'][0]['value'] : 0;
			$conversions = isset( $row['metricValues'][1]['value'] ) ? (float) $row['metricValues'][1]['value'] : 0.0;
			$out[ $path ] = [ 'sessions' => $sessions, 'conversions' => $conversions ];
		}
		return $out;
	}

	/** Extract + normalize the path component of a full URL. */
	private function path_of( string $url ): string {
		$path = (string) \wp_parse_url( $url, \PHP_URL_PATH );
		return $this->normalize_path( $path !== '' ? $path : $url );
	}

	/** Canonical path form: leading slash, no query/fragment, no trailing slash (except root). */
	private function normalize_path( string $path ): string {
		$path = \strtok( $path, '?' ); // drop query.
		$path = \strtok( $path, '#' ); // drop fragment.
		if ( $path === false || $path === '' ) { return '/'; }
		if ( $path[0] !== '/' ) { $path = '/' . $path; }
		$path = \rtrim( $path, '/' );
		return $path === '' ? '/' : $path;
	}

	/**
	 * Fetch + cache the normalized GSC per-path map.
	 *
	 * @return array|\WP_Error Normalized map on success; WP_Error on failure (cache untouched).
	 */
	public function fetch_gsc( string $start, string $end ) {
		$site = $this->gsc_site();
		if ( $site === '' ) {
			return new \WP_Error( 'anchor_al_no_gsc', \__( 'No Search Console site configured.', 'anchor-schema' ) );
		}
		$token = $this->get_access_token( [ self::SCOPE_GSC ] );
		if ( \is_wp_error( $token ) ) { return $token; }

		$endpoint = 'https://searchconsole.googleapis.com/webmasters/v3/sites/'
			. \rawurlencode( $site ) . '/searchAnalytics/query';
		$resp = \wp_remote_post( $endpoint, [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => (string) \wp_json_encode( [
				'startDate'  => $start,
				'endDate'    => $end,
				'dimensions' => [ 'page' ],
				'rowLimit'   => 25000,
			] ),
		] );
		$decoded = $this->decode_response( $resp );
		if ( \is_wp_error( $decoded ) ) { return $decoded; }

		$map = $this->normalize_gsc( $decoded );
		\set_transient( self::GSC_TRANSIENT, $map, self::CACHE_TTL );
		return $map;
	}

	/**
	 * Fetch + cache the normalized GA4 per-path map.
	 *
	 * @return array|\WP_Error Normalized map on success; WP_Error on failure (cache untouched).
	 */
	public function fetch_ga4( string $start, string $end ) {
		$prop = $this->ga4_property();
		if ( $prop === '' ) {
			return new \WP_Error( 'anchor_al_no_ga4', \__( 'No GA4 property configured.', 'anchor-schema' ) );
		}
		$token = $this->get_access_token( [ self::SCOPE_GA4 ] );
		if ( \is_wp_error( $token ) ) { return $token; }

		$endpoint = 'https://analyticsdata.googleapis.com/v1beta/properties/'
			. \rawurlencode( $prop ) . ':runReport';
		$resp = \wp_remote_post( $endpoint, [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => (string) \wp_json_encode( [
				'dateRanges' => [ [ 'startDate' => $start, 'endDate' => $end ] ],
				'dimensions' => [ [ 'name' => 'pagePath' ] ],
				'metrics'    => [ [ 'name' => 'sessions' ], [ 'name' => 'conversions' ] ],
				'limit'      => 100000,
			] ),
		] );
		$decoded = $this->decode_response( $resp );
		if ( \is_wp_error( $decoded ) ) { return $decoded; }

		$map = $this->normalize_ga4( $decoded );
		\set_transient( self::GA4_TRANSIENT, $map, self::CACHE_TTL );
		return $map;
	}

	/**
	 * Decode a wp_remote_* response body to an array, or a WP_Error on transport /
	 * HTTP / JSON failure. Central so a failed fetch never poisons the cache.
	 *
	 * @param array|\WP_Error $resp
	 * @return array|\WP_Error
	 */
	private function decode_response( $resp ) {
		if ( \is_wp_error( $resp ) ) { return $resp; }
		$code = (int) \wp_remote_retrieve_response_code( $resp );
		$raw  = (string) \wp_remote_retrieve_body( $resp );
		if ( $code !== 200 ) {
			$body = \json_decode( $raw, true );
			$msg  = \is_array( $body ) && ! empty( $body['error']['message'] ) ? (string) $body['error']['message'] : ( 'HTTP ' . $code );
			return new \WP_Error( 'anchor_al_api_error', $msg, [ 'status' => $code ] );
		}
		$body = \json_decode( $raw, true );
		if ( ! \is_array( $body ) ) {
			return new \WP_Error( 'anchor_al_bad_json', \__( 'API returned a response that could not be parsed.', 'anchor-schema' ) );
		}
		return $body;
	}

	/**
	 * Re-fetch both sources over the given window (defaults: last 28 days).
	 * Returns per-source WP_Errors so the caller can report partial failure; a
	 * failed source leaves its cache intact.
	 *
	 * @return array{gsc:true|\WP_Error,ga4:true|\WP_Error}
	 */
	public function refresh( string $start = '', string $end = '' ): array {
		if ( $start === '' ) { $start = \gmdate( 'Y-m-d', \time() - 28 * DAY_IN_SECONDS ); }
		if ( $end === '' )   { $end   = \gmdate( 'Y-m-d', \time() - DAY_IN_SECONDS ); }

		$out = [ 'gsc' => true, 'ga4' => true ];
		if ( $this->gsc_site() !== '' ) {
			$r = $this->fetch_gsc( $start, $end );
			$out['gsc'] = \is_wp_error( $r ) ? $r : true;
		}
		if ( $this->ga4_property() !== '' ) {
			$r = $this->fetch_ga4( $start, $end );
			$out['ga4'] = \is_wp_error( $r ) ? $r : true;
		}
		return $out;
	}

	/** The cached GSC per-path map (may be empty). */
	private function gsc_map(): array {
		$m = \get_transient( self::GSC_TRANSIENT );
		return \is_array( $m ) ? $m : [];
	}
	/** The cached GA4 per-path map (may be empty). */
	private function ga4_map(): array {
		$m = \get_transient( self::GA4_TRANSIENT );
		return \is_array( $m ) ? $m : [];
	}

	/**
	 * Merged metrics for a single URL (matched by path). Always returns a full
	 * shape (missing values default to 0), so callers never null-check.
	 *
	 * @return array{clicks:int,impressions:int,ctr:float,position:float,sessions:int,conversions:float}
	 */
	public function metrics_for( string $url ): array {
		$path = $this->path_of( $url );
		$g    = $this->gsc_map();
		$a    = $this->ga4_map();
		$gsc  = $g[ $path ] ?? ( $g[ $path . '/' ] ?? [] );
		$ga4  = $a[ $path ] ?? ( $a[ $path . '/' ] ?? [] );
		return [
			'clicks'      => (int) ( $gsc['clicks'] ?? 0 ),
			'impressions' => (int) ( $gsc['impressions'] ?? 0 ),
			'ctr'         => (float) ( $gsc['ctr'] ?? 0 ),
			'position'    => (float) ( $gsc['position'] ?? 0 ),
			'sessions'    => (int) ( $ga4['sessions'] ?? 0 ),
			'conversions' => (float) ( $ga4['conversions'] ?? 0 ),
		];
	}

	/** Drop cached token(s) + metrics maps. */
	public function clear_caches() {
		\delete_transient( self::GSC_TRANSIENT );
		\delete_transient( self::GA4_TRANSIENT );
		\delete_transient( self::TOKEN_TRANSIENT . \md5( self::SCOPE_GSC ) );
		\delete_transient( self::TOKEN_TRANSIENT . \md5( self::SCOPE_GA4 ) );
	}

	/* ================================================================
	 * D. Surface — report rows + admin page
	 * ================================================================ */

	/**
	 * Build the report: published locations + service pages, each with merged
	 * metrics + flags. Pure (reads cache + posts, writes nothing).
	 *
	 * @return array<int,array{id:int,title:string,type:string,url:string,path:string,metrics:array,flags:string[]}>
	 */
	public function report_rows(): array {
		$rows  = [];
		$posts = \get_posts( [
			'post_type'      => [ Module::CPT_LOCATION, Module::CPT_SERVICE ],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );

		$mod = Module::instance();
		foreach ( $posts as $p ) {
			$is_service = ( $p->post_type === Module::CPT_SERVICE );
			if ( $is_service ) {
				$url = ( $mod ) ? $mod->service_page_url( $p->ID ) : '';
				if ( $url === '' || $url === '#' ) { $url = (string) \get_permalink( $p ); }
			} else {
				$url = (string) \get_permalink( $p );
			}
			$m     = $this->metrics_for( $url );
			$rows[] = [
				'id'      => (int) $p->ID,
				'title'   => (string) \get_the_title( $p ),
				'type'    => $is_service ? 'service' : 'location',
				'url'     => $url,
				'path'    => $this->path_of( $url ),
				'metrics' => $m,
				'flags'   => $this->flags_for( $m ),
			];
		}
		return $rows;
	}

	/**
	 * Classify a metrics row.
	 *  - 'opportunity': real impressions but a poor average position (ranking just
	 *    off page one) — a page worth improving.
	 *  - 'zero_traffic': no clicks and no sessions at all.
	 *
	 * @return string[]
	 */
	public function flags_for( array $m ): array {
		$flags = [];
		if ( (int) ( $m['impressions'] ?? 0 ) >= self::OPPORTUNITY_MIN_IMPRESSIONS
			&& (float) ( $m['position'] ?? 0 ) > self::OPPORTUNITY_POSITION_FLOOR ) {
			$flags[] = 'opportunity';
		}
		if ( (int) ( $m['clicks'] ?? 0 ) === 0 && (int) ( $m['sessions'] ?? 0 ) === 0 ) {
			$flags[] = 'zero_traffic';
		}
		return $flags;
	}

	/* ---- Admin page ---- */

	public function register_pages() {
		\add_submenu_page(
			'edit.php?post_type=' . Module::CPT_LOCATION,
			\__( 'Analytics', 'anchor-schema' ),
			\__( 'Analytics', 'anchor-schema' ),
			'manage_options',
			'anchor-locations-analytics',
			[ $this, 'render_page' ]
		);
	}

	private function page_url( array $args = [] ): string {
		$base = \admin_url( 'edit.php?post_type=' . Module::CPT_LOCATION . '&page=anchor-locations-analytics' );
		return $args ? \add_query_arg( $args, $base ) : $base;
	}

	public function render_page() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to view this page.', 'anchor-schema' ) );
		}

		echo '<div class="wrap anchor-locations-analytics">';
		echo '<h1>' . \esc_html__( 'Locations Analytics', 'anchor-schema' ) . '</h1>';

		$this->maybe_render_notice();
		$this->render_config_form();

		if ( ! $this->is_configured() ) {
			echo '<p class="description">' . \esc_html__( 'Paste a Google service-account JSON key and set a Search Console site and/or GA4 property above to start pulling metrics. Until then, no data is fetched.', 'anchor-schema' ) . '</p>';
			echo '</div>';
			return;
		}

		// Refresh button.
		echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" style="margin:16px 0;">';
		echo '<input type="hidden" name="action" value="anchor_locations_analytics_refresh">';
		\wp_nonce_field( self::NONCE );
		echo '<button type="submit" class="button">' . \esc_html__( 'Refresh metrics now', 'anchor-schema' ) . '</button>';
		echo '</form>';

		$this->render_report_table();
		echo '</div>';
	}

	private function render_config_form() {
		$s = $this->settings();
		$key_present = ! empty( $s['client_email'] ) && ! empty( $s['private_key'] );

		echo '<h2>' . \esc_html__( 'Configuration', 'anchor-schema' ) . '</h2>';
		echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="anchor_locations_analytics_save">';
		\wp_nonce_field( self::NONCE );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . \esc_html__( 'Service-account JSON key', 'anchor-schema' ) . '</th><td>';
		// SECURITY: never echo the stored private key back; only a status note.
		if ( $key_present ) {
			echo '<p><strong>' . \esc_html__( 'Configured ✓', 'anchor-schema' ) . '</strong> — '
				. \esc_html( (string) $s['client_email'] ) . '<br>'
				. \esc_html__( 'Leave the box blank to keep the current key, or paste a new key to replace it.', 'anchor-schema' ) . '</p>';
		}
		echo '<textarea name="json_key" rows="6" class="large-text code" autocomplete="off" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, ... }"></textarea>';
		echo '<p class="description">' . \esc_html__( 'Paste the full JSON key file. Grant this service account read access in Search Console and GA4.', 'anchor-schema' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . \esc_html__( 'Search Console site', 'anchor-schema' ) . '</th><td>';
		echo '<input type="text" name="gsc_site" value="' . \esc_attr( (string) ( $s['gsc_site'] ?? '' ) ) . '" class="regular-text" placeholder="sc-domain:example.com">';
		echo '<p class="description">' . \esc_html__( 'A domain property (sc-domain:example.com) or a URL-prefix property (https://example.com/).', 'anchor-schema' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . \esc_html__( 'GA4 property ID', 'anchor-schema' ) . '</th><td>';
		echo '<input type="text" name="ga4_property" value="' . \esc_attr( (string) ( $s['ga4_property'] ?? '' ) ) . '" class="regular-text" placeholder="123456789">';
		echo '<p class="description">' . \esc_html__( 'The numeric GA4 property ID (Admin → Property Settings).', 'anchor-schema' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		\submit_button( \__( 'Save configuration', 'anchor-schema' ) );
		echo '</form>';
	}

	private function render_report_table() {
		$rows = $this->report_rows();
		echo '<h2>' . \esc_html__( 'Page metrics', 'anchor-schema' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p>' . \esc_html__( 'No published locations or service pages to report on yet.', 'anchor-schema' ) . '</p>';
			return;
		}
		echo '<p class="description">' . \esc_html__( 'Metrics are cached (up to 12 hours). Search Console data typically lags ~2 days. Blank rows mean Google has no data for that URL in the window.', 'anchor-schema' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [
			\__( 'Page', 'anchor-schema' ), \__( 'Type', 'anchor-schema' ),
			\__( 'Clicks', 'anchor-schema' ), \__( 'Impressions', 'anchor-schema' ),
			\__( 'Avg position', 'anchor-schema' ), \__( 'CTR', 'anchor-schema' ),
			\__( 'Sessions', 'anchor-schema' ), \__( 'Conversions', 'anchor-schema' ),
			\__( 'Flags', 'anchor-schema' ),
		] as $h ) { echo '<th>' . \esc_html( $h ) . '</th>'; }
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$m = $r['metrics'];
			echo '<tr>';
			echo '<td><a href="' . \esc_url( \admin_url( 'post.php?post=' . $r['id'] . '&action=edit' ) ) . '">' . \esc_html( $r['title'] ) . '</a></td>';
			echo '<td>' . \esc_html( $r['type'] ) . '</td>';
			echo '<td>' . (int) $m['clicks'] . '</td>';
			echo '<td>' . (int) $m['impressions'] . '</td>';
			echo '<td>' . \esc_html( $m['position'] > 0 ? \number_format_i18n( $m['position'], 1 ) : '—' ) . '</td>';
			echo '<td>' . \esc_html( $m['impressions'] > 0 ? \number_format_i18n( $m['ctr'] * 100, 1 ) . '%' : '—' ) . '</td>';
			echo '<td>' . (int) $m['sessions'] . '</td>';
			echo '<td>' . \esc_html( \number_format_i18n( $m['conversions'] ) ) . '</td>';
			echo '<td>' . $this->render_flags( $r['flags'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_flags( array $flags ): string {
		$labels = [
			'opportunity'  => [ \__( 'Opportunity', 'anchor-schema' ), '#fef7e0' ],
			'zero_traffic' => [ \__( 'No traffic', 'anchor-schema' ), '#fce8e6' ],
		];
		$out = '';
		foreach ( $flags as $f ) {
			if ( ! isset( $labels[ $f ] ) ) { continue; }
			list( $label, $bg ) = $labels[ $f ];
			$out .= '<span style="background:' . \esc_attr( $bg ) . ';padding:2px 6px;margin-right:4px;white-space:nowrap;">' . \esc_html( $label ) . '</span>';
		}
		return $out !== '' ? $out : '—';
	}

	private function maybe_render_notice() {
		if ( empty( $_GET['al_analytics_msg'] ) ) { return; }
		$key = \sanitize_key( \wp_unslash( $_GET['al_analytics_msg'] ) );
		$map = [
			'saved'       => [ 'success', \__( 'Configuration saved.', 'anchor-schema' ) ],
			'bad_key'     => [ 'error', \__( 'That does not look like a valid service-account JSON key.', 'anchor-schema' ) ],
			'refreshed'   => [ 'success', \__( 'Metrics refreshed.', 'anchor-schema' ) ],
			'refresh_err' => [ 'warning', \__( 'Refresh failed for one or more sources. Check the credentials and API access.', 'anchor-schema' ) ],
		];
		if ( ! isset( $map[ $key ] ) ) { return; }
		list( $type, $msg ) = $map[ $key ];
		echo '<div class="notice notice-' . \esc_attr( $type ) . '"><p>' . \esc_html( $msg ) . '</p></div>';
	}

	/* ---- admin-post handlers ---- */

	public function handle_save() {
		if ( ! \current_user_can( 'manage_options' ) ) { \wp_die( \esc_html__( 'Insufficient permissions.', 'anchor-schema' ) ); }
		\check_admin_referer( self::NONCE );

		$json = isset( $_POST['json_key'] ) ? \trim( (string) \wp_unslash( $_POST['json_key'] ) ) : '';
		$site = isset( $_POST['gsc_site'] ) ? \sanitize_text_field( \wp_unslash( $_POST['gsc_site'] ) ) : '';
		$prop = isset( $_POST['ga4_property'] ) ? \sanitize_text_field( \wp_unslash( $_POST['ga4_property'] ) ) : '';

		$res = $this->save_config( $json, $site, $prop );
		$msg = \is_wp_error( $res ) ? 'bad_key' : 'saved';
		\wp_safe_redirect( $this->page_url( [ 'al_analytics_msg' => $msg ] ) );
		exit;
	}

	public function handle_refresh() {
		if ( ! \current_user_can( 'manage_options' ) ) { \wp_die( \esc_html__( 'Insufficient permissions.', 'anchor-schema' ) ); }
		\check_admin_referer( self::NONCE );

		$res = $this->refresh();
		$err = \is_wp_error( $res['gsc'] ) || \is_wp_error( $res['ga4'] );
		\wp_safe_redirect( $this->page_url( [ 'al_analytics_msg' => $err ? 'refresh_err' : 'refreshed' ] ) );
		exit;
	}
}
