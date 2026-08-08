<?php
/**
 * Anchor Compliance — curated third-party service and cookie database.
 *
 * Read by the script blocker (what to gate), the preference center (what to
 * disclose), the cookie policy shortcode (the public table), and the runtime
 * (which cookies to delete on withdrawal).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Service_Registry {

	/** @var array|null */
	private $resolved = null;

	public static function builtin() {
		return [
			'google_tag_manager' => [
				'name' => 'Google Tag Manager', 'provider' => 'Google', 'category' => 'analytics',
				'consent_mode' => true,
				'patterns' => [ 'googletagmanager.com/gtm.js', 'googletagmanager.com/gtag/js' ],
				'cookies' => [
					[ 'name' => '_ga', 'purpose' => 'Distinguishes visitors.', 'duration' => '2 years' ],
				],
			],
			'google_analytics' => [
				'name' => 'Google Analytics 4', 'provider' => 'Google', 'category' => 'analytics',
				'consent_mode' => true,
				'patterns' => [ 'google-analytics.com', 'analytics.google.com' ],
				'cookies' => [
					[ 'name' => '_ga', 'purpose' => 'Distinguishes visitors.', 'duration' => '2 years' ],
					[ 'name' => '_ga_*', 'purpose' => 'Persists session state.', 'duration' => '2 years' ],
					[ 'name' => '_gid', 'purpose' => 'Distinguishes visitors.', 'duration' => '24 hours' ],
					[ 'name' => '_gat*', 'purpose' => 'Throttles request rate.', 'duration' => '1 minute' ],
				],
			],
			'google_ads' => [
				'name' => 'Google Ads', 'provider' => 'Google', 'category' => 'marketing',
				'consent_mode' => true,
				'patterns' => [ 'googleadservices.com', 'googlesyndication.com', 'doubleclick.net' ],
				'cookies' => [
					[ 'name' => '_gcl_*', 'purpose' => 'Conversion attribution for Google Ads.', 'duration' => '90 days' ],
					[ 'name' => 'IDE', 'purpose' => 'Ad measurement and targeting.', 'duration' => '13 months' ],
				],
			],
			'google_maps' => [
				'name' => 'Google Maps', 'provider' => 'Google', 'category' => 'functional',
				'patterns' => [ 'maps.googleapis.com', 'maps.google.com/maps/embed' ],
				'cookies' => [],
			],
			'recaptcha' => [
				'name' => 'reCAPTCHA', 'provider' => 'Google', 'category' => 'necessary',
				'patterns' => [ 'google.com/recaptcha', 'gstatic.com/recaptcha' ],
				'cookies' => [
					[ 'name' => '_GRECAPTCHA', 'purpose' => 'Spam and abuse prevention.', 'duration' => '6 months' ],
				],
			],
			'youtube' => [
				'name' => 'YouTube', 'provider' => 'Google', 'category' => 'marketing',
				'patterns' => [ 'youtube.com/embed', 'youtube-nocookie.com/embed', 'youtube.com/iframe_api', 'youtu.be/' ],
				'cookies' => [
					[ 'name' => 'VISITOR_INFO1_LIVE', 'purpose' => 'Estimates bandwidth and player preferences.', 'duration' => '6 months' ],
					[ 'name' => 'YSC', 'purpose' => 'Tracks views of embedded videos.', 'duration' => 'Session' ],
				],
			],
			'vimeo' => [
				'name' => 'Vimeo', 'provider' => 'Vimeo', 'category' => 'marketing',
				'patterns' => [ 'player.vimeo.com', 'vimeo.com/api', 'vimeo.com/video' ],
				'cookies' => [
					[ 'name' => 'vuid', 'purpose' => 'Player analytics.', 'duration' => '2 years' ],
				],
			],
			'meta_pixel' => [
				'name' => 'Meta Pixel', 'provider' => 'Meta', 'category' => 'marketing',
				// 'fbq(' is not a URL — it is the inline bootstrap call
				// (`!function(f,b){...}fbq('init',...)`) that WordPress themes
				// commonly paste inline rather than load from a src. The script
				// blocker matches patterns against both <script src> URLs and
				// inline script bodies via the same substring check, so this
				// entry is what lets an inline Pixel snippet get caught at all.
				'patterns' => [ 'connect.facebook.net', 'facebook.com/tr', 'fbq(' ],
				'cookies' => [
					[ 'name' => '_fbp', 'purpose' => 'Ad delivery and measurement.', 'duration' => '90 days' ],
					[ 'name' => '_fbc', 'purpose' => 'Stores the last ad click.', 'duration' => '90 days' ],
				],
			],
			'linkedin_insight' => [
				'name' => 'LinkedIn Insight Tag', 'provider' => 'LinkedIn', 'category' => 'marketing',
				'patterns' => [ 'snap.licdn.com', 'ads.linkedin.com' ],
				'cookies' => [
					[ 'name' => 'li_sugr', 'purpose' => 'Probabilistic visitor matching.', 'duration' => '90 days' ],
					[ 'name' => 'li_*', 'purpose' => 'Ad targeting and measurement.', 'duration' => 'Up to 2 years' ],
				],
			],
			'tiktok_pixel' => [
				'name' => 'TikTok Pixel', 'provider' => 'TikTok', 'category' => 'marketing',
				'patterns' => [ 'analytics.tiktok.com' ],
				'cookies' => [
					[ 'name' => '_ttp', 'purpose' => 'Ad measurement.', 'duration' => '13 months' ],
				],
			],
			'twitter' => [
				'name' => 'X (Twitter) Pixel', 'provider' => 'X Corp', 'category' => 'marketing',
				'patterns' => [ 'static.ads-twitter.com', 'platform.twitter.com' ],
				'cookies' => [
					[ 'name' => 'personalization_id', 'purpose' => 'Ad personalization.', 'duration' => '2 years' ],
				],
			],
			'pinterest' => [
				'name' => 'Pinterest Tag', 'provider' => 'Pinterest', 'category' => 'marketing',
				'patterns' => [ 's.pinimg.com/ct' ],
				'cookies' => [
					[ 'name' => '_pinterest_*', 'purpose' => 'Conversion tracking.', 'duration' => '1 year' ],
				],
			],
			'microsoft_ads' => [
				'name' => 'Microsoft Advertising (UET)', 'provider' => 'Microsoft', 'category' => 'marketing',
				'patterns' => [ 'bat.bing.com' ],
				'cookies' => [
					[ 'name' => '_uetsid', 'purpose' => 'Session identifier for ad conversion.', 'duration' => '1 day' ],
					[ 'name' => '_uetvid', 'purpose' => 'Visitor identifier for ad conversion.', 'duration' => '13 months' ],
				],
			],
			'hotjar' => [
				'name' => 'Hotjar', 'provider' => 'Hotjar', 'category' => 'analytics',
				'patterns' => [ 'static.hotjar.com', 'script.hotjar.com' ],
				'cookies' => [
					[ 'name' => '_hj*', 'purpose' => 'Session recording and heatmaps.', 'duration' => 'Up to 1 year' ],
				],
			],
			'clarity' => [
				'name' => 'Microsoft Clarity', 'provider' => 'Microsoft', 'category' => 'analytics',
				'patterns' => [ 'clarity.ms' ],
				'cookies' => [
					[ 'name' => '_clck', 'purpose' => 'Persists a Clarity user ID.', 'duration' => '1 year' ],
					[ 'name' => '_clsk', 'purpose' => 'Groups pageviews into a session.', 'duration' => '1 day' ],
				],
			],
			'hubspot' => [
				'name' => 'HubSpot', 'provider' => 'HubSpot', 'category' => 'marketing',
				'patterns' => [ 'js.hs-scripts.com', 'js.hsadspixel.net', 'js.hs-analytics.net' ],
				'cookies' => [
					[ 'name' => 'hubspotutk', 'purpose' => 'Tracks a visitor across sessions.', 'duration' => '6 months' ],
					[ 'name' => '__hs*', 'purpose' => 'Session and analytics state.', 'duration' => 'Up to 6 months' ],
				],
			],
			'intercom' => [
				'name' => 'Intercom', 'provider' => 'Intercom', 'category' => 'functional',
				'patterns' => [ 'widget.intercom.io', 'js.intercomcdn.com' ],
				'cookies' => [
					[ 'name' => 'intercom-*', 'purpose' => 'Live chat session state.', 'duration' => 'Up to 9 months' ],
				],
			],
			'drift' => [
				'name' => 'Drift', 'provider' => 'Drift', 'category' => 'functional',
				'patterns' => [ 'js.driftt.com', 'driftt.com' ],
				'cookies' => [
					[ 'name' => 'drift_*', 'purpose' => 'Live chat session state.', 'duration' => 'Up to 1 year' ],
				],
			],
			'mailchimp' => [
				'name' => 'Mailchimp', 'provider' => 'Intuit', 'category' => 'marketing',
				'patterns' => [ 'chimpstatic.com', 'list-manage.com' ],
				'cookies' => [
					[ 'name' => '_mcid', 'purpose' => 'Campaign attribution.', 'duration' => '1 year' ],
				],
			],
			'calltrackingmetrics' => [
				'name' => 'CallTrackingMetrics', 'provider' => 'CallTrackingMetrics', 'category' => 'marketing',
				'patterns' => [ 'tctm.co', 'calltrackingmetrics.com/t.js' ],
				'cookies' => [
					[ 'name' => '__ctmid', 'purpose' => 'Links a call to the visit that produced it.', 'duration' => '30 days' ],
					[ 'name' => '__ctm*', 'purpose' => 'Session and attribution state for number swapping.', 'duration' => 'Up to 30 days' ],
				],
			],
			'callrail' => [
				'name' => 'CallRail', 'provider' => 'CallRail', 'category' => 'marketing',
				'patterns' => [ 'cdn.callrail.com' ],
				'cookies' => [
					[ 'name' => 'rc_*', 'purpose' => 'Call attribution and number swapping.', 'duration' => 'Up to 1 year' ],
				],
			],
		];
	}

	/** Builtins with settings overrides applied, then the filter. */
	public function all() {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		$opts      = Anchor_Compliance_Settings::get();
		$overrides = (array) $opts['services'];
		$services  = self::builtin();

		foreach ( $services as $key => &$svc ) {
			$svc['enabled']  = isset( $overrides[ $key ]['enabled'] ) ? (bool) $overrides[ $key ]['enabled'] : true;
			$svc['category'] = isset( $overrides[ $key ]['category'] )
				? Anchor_Compliance_Settings::sanitize_category( $overrides[ $key ]['category'] )
				: $svc['category'];
			$svc['cookies']  = isset( $svc['cookies'] ) ? (array) $svc['cookies'] : [];
		}
		unset( $svc );

		/**
		 * Filter the service registry.
		 *
		 * @param array $services service_key => [name, provider, category, patterns, cookies]
		 */
		$services = (array) apply_filters( 'anchor_compliance_services', $services );

		// A filter-added entry may omit 'enabled'; normalize so callers can rely on it.
		foreach ( $services as $key => &$svc ) {
			if ( ! isset( $svc['enabled'] ) ) {
				$svc['enabled'] = true;
			}
			if ( ! isset( $svc['cookies'] ) ) {
				$svc['cookies'] = [];
			}
		}
		unset( $svc );

		$this->resolved = $services;
		return $this->resolved;
	}

	/**
	 * Flat match list. Custom rules come first so a site-specific rule wins
	 * over a builtin that would otherwise match the same URL.
	 */
	public function active_rules() {
		$rules = [];

		foreach ( (array) Anchor_Compliance_Settings::get()['custom_rules'] as $rule ) {
			$rules[] = [
				'pattern'  => $rule['url_pattern'],
				'category' => $rule['category'],
				'key'      => 'custom',
				'label'    => $rule['label'],
			];
		}

		$consent_mode_on = ! empty( Anchor_Compliance_Settings::get()['advanced']['consent_mode_enabled'] );

		foreach ( $this->all() as $key => $svc ) {
			if ( empty( $svc['enabled'] ) ) {
				continue;
			}
			// Necessary services are never gated, so they need no rule.
			if ( 'necessary' === $svc['category'] ) {
				continue;
			}
			// Services governed by Consent Mode must NOT be hard-blocked — Google
			// requires its tags to load with denied consent so conversion modeling
			// and Ads audiences survive. Blocking them defeats the whole mechanism.
			// If the site has turned Consent Mode off, nothing else would gate
			// them, so they fall back to hard blocking.
			if ( $consent_mode_on && ! empty( $svc['consent_mode'] ) ) {
				continue;
			}
			foreach ( (array) $svc['patterns'] as $pattern ) {
				$rules[] = [
					'pattern'  => $pattern,
					'category' => $svc['category'],
					'key'      => $key,
					'label'    => $svc['name'],
				];
			}
		}

		return $rules;
	}

	/** @return string|null The category gating this URL, or null when unmatched. */
	public function category_for_url( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return null;
		}
		foreach ( $this->active_rules() as $rule ) {
			if ( '' !== $rule['pattern'] && false !== stripos( $url, $rule['pattern'] ) ) {
				return $rule['category'];
			}
		}
		return null;
	}

	/** @return array category => list of cookie rows, for the policy table. */
	public function cookies_by_category() {
		$out = array_fill_keys( Anchor_Compliance_Consent_State::all_categories(), [] );

		foreach ( $this->all() as $svc ) {
			if ( empty( $svc['enabled'] ) ) {
				continue;
			}
			foreach ( (array) $svc['cookies'] as $cookie ) {
				$out[ $svc['category'] ][] = [
					'name'     => $cookie['name'],
					'provider' => $svc['provider'],
					'purpose'  => $cookie['purpose'],
					'duration' => $cookie['duration'],
				];
			}
		}

		return $out;
	}

	/** @return string[] Cookie-name patterns belonging to the given categories. */
	public function cookie_patterns_for( array $categories ) {
		$patterns = [];

		foreach ( $this->all() as $svc ) {
			if ( empty( $svc['enabled'] ) || ! in_array( $svc['category'], $categories, true ) ) {
				continue;
			}
			foreach ( (array) $svc['cookies'] as $cookie ) {
				$patterns[] = $cookie['name'];
			}
		}

		foreach ( (array) Anchor_Compliance_Settings::get()['custom_rules'] as $rule ) {
			if ( in_array( $rule['category'], $categories, true ) ) {
				foreach ( (array) $rule['cookie_patterns'] as $p ) {
					$patterns[] = $p;
				}
			}
		}

		return array_values( array_unique( $patterns ) );
	}
}
