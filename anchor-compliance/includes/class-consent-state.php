<?php
/**
 * Anchor Compliance — consent state.
 *
 * Resolves what the current visitor has allowed, from (in precedence order)
 * the Global Privacy Control signal, the stored consent cookie, and the
 * caller-supplied posture default.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Consent_State {

	const COOKIE = 'anchor_consent';

	/** @var array|null Memoized decoded cookie payload. */
	private $decoded = null;

	/** @var bool Whether $decoded has been computed. */
	private $decoded_ready = false;

	public static function all_categories() {
		return [ 'necessary', 'functional', 'analytics', 'marketing' ];
	}

	/**
	 * JSON, base64url-encoded so the value is cookie-safe. Not encrypted —
	 * this is the visitor's own choice, not a secret; integrity is not a
	 * security boundary because a forged cookie only affects that visitor.
	 */
	public static function encode( array $payload ) {
		return rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
	}

	/** @return array|null Null when absent, malformed, or not the expected shape. */
	public static function decode( $raw ) {
		$raw = (string) $raw;
		if ( '' === $raw ) {
			return null;
		}
		$json = base64_decode( strtr( $raw, '-_', '+/' ), true );
		if ( false === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['cats'] ) || ! is_array( $data['cats'] ) ) {
			return null;
		}
		return $data;
	}

	private function opts() {
		return Anchor_Compliance_Settings::get();
	}

	/**
	 * The decoded cookie, or null when it is absent, malformed, expired, or
	 * was recorded under an older policy version.
	 */
	public function stored() {
		if ( $this->decoded_ready ) {
			return $this->decoded;
		}
		$this->decoded_ready = true;
		$this->decoded       = null;

		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		$data = self::decode( $raw );
		if ( null === $data ) {
			return null;
		}

		$opts = $this->opts();

		if ( (int) ( $data['v'] ?? 0 ) !== (int) $opts['general']['policy_version'] ) {
			return null;
		}

		$age = time() - (int) ( $data['ts'] ?? 0 );
		if ( $age > (int) $opts['general']['consent_lifetime_days'] * DAY_IN_SECONDS ) {
			return null;
		}

		$this->decoded = $data;
		return $this->decoded;
	}

	public function has_stored_consent() {
		return null !== $this->stored();
	}

	/**
	 * True when the visitor's browser sent a Global Privacy Control signal and
	 * the site is configured to honor it. California's CPPA requires honoring
	 * GPC, so this takes precedence over any stored opt-in.
	 */
	public function is_gpc() {
		$opts = $this->opts();
		if ( empty( $opts['advanced']['honor_gpc'] ) ) {
			return false;
		}
		return isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === (string) wp_unslash( $_SERVER['HTTP_SEC_GPC'] );
	}

	/**
	 * The effective grant map for this request.
	 *
	 * @param bool $default_grant What non-necessary categories default to when
	 *                            there is no stored consent — true in opt-out
	 *                            regions, false in strict regions.
	 * @return array<string,bool>
	 */
	public function categories( $default_grant = false ) {
		$out = [];
		foreach ( self::all_categories() as $cat ) {
			$out[ $cat ] = ( 'necessary' === $cat );
		}

		$stored = $this->stored();
		if ( null !== $stored ) {
			foreach ( (array) $stored['cats'] as $cat ) {
				$cat = Anchor_Compliance_Settings::sanitize_category( $cat );
				$out[ $cat ] = true;
			}
		} elseif ( $default_grant ) {
			foreach ( $out as $cat => $_ ) {
				$out[ $cat ] = true;
			}
		}

		// GPC is a sale/share opt-out: it revokes analytics and marketing but
		// leaves functional storage alone. It wins over anything above.
		if ( $this->is_gpc() ) {
			$out['analytics'] = false;
			$out['marketing'] = false;
		}

		return $out;
	}

	public function allows( $category, $default_grant = false ) {
		$cats = $this->categories( $default_grant );
		return ! empty( $cats[ $category ] );
	}
}
