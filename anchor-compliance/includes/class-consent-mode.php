<?php
/**
 * Anchor Compliance — Google Consent Mode v2.
 *
 * Google tags are deliberately NOT blocked. Since March 2024 Google requires
 * Consent Mode for EEA traffic; hard-blocking GTM instead breaks conversion
 * modeling and Ads remarketing audiences. Instead we publish denied defaults
 * before any tag can read them, and the runtime pushes an update on consent.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Consent_Mode {

	/** @var Anchor_Compliance_Consent_State */
	private $state;

	/** @var Anchor_Compliance_Geo */
	private $geo;

	public function __construct( $state, $geo ) {
		$this->state = $state;
		$this->geo   = $geo;
	}

	/** Google's seven v2 signals mapped onto our four categories. */
	public static function signal_map() {
		return [
			'security_storage'        => 'necessary',
			'functionality_storage'   => 'functional',
			'personalization_storage' => 'functional',
			'analytics_storage'       => 'analytics',
			'ad_storage'              => 'marketing',
			'ad_user_data'            => 'marketing',
			'ad_personalization'      => 'marketing',
		];
	}

	/** @return array<string,string> signal => 'granted'|'denied' */
	public function defaults_payload() {
		$default_grant = ! $this->geo->is_strict();
		$cats          = $this->state->categories( $default_grant );

		$payload = [];
		foreach ( self::signal_map() as $signal => $category ) {
			$payload[ $signal ] = ! empty( $cats[ $category ] ) ? 'granted' : 'denied';
		}
		return $payload;
	}

	public function emit_defaults() {
		$opts = Anchor_Compliance_Settings::get();
		if ( empty( $opts['general']['enabled'] ) || empty( $opts['advanced']['consent_mode_enabled'] ) ) {
			return;
		}

		$payload = $this->defaults_payload();
		$redact  = 'denied' === $payload['ad_storage'] ? 'true' : 'false';

		$payload['wait_for_update'] = (int) $opts['advanced']['wait_for_update'];

		printf(
			"<!-- Anchor Compliance: Google Consent Mode v2 -->\n" .
			"<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}" .
			"gtag('consent','default',%s);" .
			"gtag('set','ads_data_redaction',%s);" .
			"gtag('set','url_passthrough',true);</script>\n",
			wp_json_encode( $payload ),
			$redact
		);
	}
}
