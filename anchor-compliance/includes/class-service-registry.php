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

	/**
	 * The contexts a rule pattern can be matched in:
	 *  - 'src'    — a resource URL attribute (<script src>, <img src>,
	 *               <link href> resource hints).
	 *  - 'iframe' — an <iframe src> embed URL.
	 *  - 'inline' — the body of an inline <script>.
	 *
	 * Every rule emitted by active_rules() carries a 'contexts' list drawn
	 * from these values, and the blocker consults it per pass. Without this
	 * axis every pattern was matched against every haystack, so a URL-only
	 * pattern like 'youtube.com/watch' neutralized any theme inline script
	 * that merely contained a plain video link as a string — the same defect
	 * class as the pinned vimeo trailing-slash fix, but structural rather
	 * than per-pattern.
	 */
	const RULE_CONTEXTS = [ 'src', 'iframe', 'inline' ];

	/**
	 * Sane default contexts for a pattern that does not declare its own:
	 * a function-call-shaped pattern ('fbq(') can only ever appear inside an
	 * inline script body — it is not a URL; a URL-shaped pattern is only
	 * meaningful where a URL appears (src attributes, iframe embeds, link
	 * hints), never as a substring of an inline body.
	 *
	 * A service entry (builtin or filter-added) may override this by
	 * declaring its own 'contexts' list; unknown values are discarded.
	 *
	 * @param string $pattern
	 * @return string[] Subset of RULE_CONTEXTS.
	 */
	public static function default_contexts_for_pattern( $pattern ) {
		return ( false !== strpos( (string) $pattern, '(' ) ) ? [ 'inline' ] : [ 'src', 'iframe' ];
	}

	/**
	 * Filter an already-fetched rules list down to one matching context.
	 * Static so the blocker can reuse the rules array it fetched once per
	 * request instead of re-running active_rules() per pass. A rule with no
	 * 'contexts' key (e.g. built by hand in a test) matches every context —
	 * the pre-axis behavior.
	 *
	 * @param array  $rules   Result of active_rules().
	 * @param string $context One of RULE_CONTEXTS.
	 * @return array
	 */
	public static function filter_rules_by_context( array $rules, $context ) {
		$out = [];
		foreach ( $rules as $rule ) {
			$contexts = ( isset( $rule['contexts'] ) && [] !== (array) $rule['contexts'] )
				? (array) $rule['contexts']
				: self::RULE_CONTEXTS;
			if ( in_array( $context, $contexts, true ) ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/**
	 * Public accessor for context-scoped rules — this is what payload
	 * builders (e.g. the banner's iframeRules / a script-src rule set for
	 * the client observer) should consume, so inline-only patterns like
	 * 'fbq(' never ship to a consumer that can only ever see URLs.
	 *
	 * @param string $context One of RULE_CONTEXTS.
	 * @return array
	 */
	public function rules_for_context( $context ) {
		return self::filter_rules_by_context( $this->active_rules(), $context );
	}

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
				'patterns' => [ 'youtube.com/embed', 'youtube-nocookie.com/embed', 'youtube.com/iframe_api', 'youtube.com/watch', 'youtu.be/' ],
				'cookies' => [
					[ 'name' => 'VISITOR_INFO1_LIVE', 'purpose' => 'Estimates bandwidth and player preferences.', 'duration' => '6 months' ],
					[ 'name' => 'YSC', 'purpose' => 'Tracks views of embedded videos.', 'duration' => 'Session' ],
				],
			],
			'vimeo' => [
				'name' => 'Vimeo', 'provider' => 'Vimeo', 'category' => 'marketing',
				'patterns' => [ 'player.vimeo.com', 'vimeo.com/api', 'vimeo.com/video/' ],
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
				'patterns' => [ 'static.ads-twitter.com' ],
				'cookies' => [
					[ 'name' => 'personalization_id', 'purpose' => 'Ad personalization.', 'duration' => '2 years' ],
				],
			],
			// platform.twitter.com is the embedded-tweets WIDGET loader, not the
			// ad pixel — filing it under the Pixel entry told visitors a blocked
			// tweet embed was an ad tracker and disclosed the wrong cookies.
			'twitter_embeds' => [
				'name' => 'X (Twitter) Embeds', 'provider' => 'X Corp', 'category' => 'functional',
				'patterns' => [ 'platform.twitter.com' ],
				'cookies' => [
					[ 'name' => 'guest_id', 'purpose' => 'Identifies the browser to X for embedded content.', 'duration' => '2 years' ],
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
				// Leading dot, no 'js.' prefix: EU-data-residency portals load
				// from js-eu1.hs-scripts.com / js-eu1.hs-analytics.net, which a
				// 'js.'-prefixed pattern never substring-matched — precisely
				// the strict-region installs where blocking is mandatory. The
				// dot is kept (rather than a bare 'hs-scripts.com') so an
				// unrelated domain that merely ENDS in those bytes (e.g.
				// 'paths-scripts.com' contains 'hs-scripts.com') cannot
				// false-positive; HubSpot always serves from a subdomain.
				'patterns' => [ '.hs-scripts.com', '.hsadspixel.net', '.hs-analytics.net' ],
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

		// A filter-added entry may omit fields; normalize EVERY field a
		// consumer indexes, not just the ones that have bitten. An entry
		// without 'category' used to warn in active_rules() and
		// cookies_by_category() and filed its cookies under a bucket no
		// policy table or consent state ever reads — silently undisclosed
		// and ungated.
		foreach ( $services as $key => &$svc ) {
			if ( ! isset( $svc['enabled'] ) ) {
				$svc['enabled'] = true;
			}
			if ( ! isset( $svc['cookies'] ) ) {
				$svc['cookies'] = [];
			}
			$svc['category'] = Anchor_Compliance_Settings::sanitize_category( $svc['category'] ?? 'marketing' );
			$svc['patterns'] = isset( $svc['patterns'] ) ? (array) $svc['patterns'] : [];
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
			// Necessary is never gated, so — exactly like the builtin branch
			// below — a custom rule saved with category 'necessary' can never
			// deny and must not ship a dead entry to every rewrite() scan and
			// every page's iframeRules payload.
			if ( 'necessary' === ( $rule['category'] ?? '' ) ) {
				continue;
			}
			$rules[] = [
				'pattern'  => $rule['url_pattern'],
				'category' => $rule['category'],
				'key'      => 'custom',
				'label'    => $rule['label'],
				'contexts' => self::default_contexts_for_pattern( $rule['url_pattern'] ),
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
			// A service may declare its own contexts (applied to all its
			// patterns); anything else falls back to per-pattern inference.
			$declared = array_values( array_intersect( (array) ( $svc['contexts'] ?? [] ), self::RULE_CONTEXTS ) );
			foreach ( (array) $svc['patterns'] as $pattern ) {
				$rules[] = [
					'pattern'  => $pattern,
					'category' => $svc['category'],
					'key'      => $key,
					'label'    => $svc['name'],
					'contexts' => $declared ? $declared : self::default_contexts_for_pattern( $pattern ),
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

		// Custom-rule cookies too: cookie_patterns_for() already SWEEPS these
		// on withdrawal, so leaving them out here made [anchor_cookie_policy]
		// under-disclose exactly the site-specific trackers the admin
		// bothered to register — what the module deletes it must also
		// disclose. Provider is the rule's label; the repeater's optional
		// purpose/duration fields flow through when filled, an em dash when
		// blank (or for rules saved before the fields existed).
		foreach ( (array) Anchor_Compliance_Settings::get()['custom_rules'] as $rule ) {
			$category = Anchor_Compliance_Settings::sanitize_category( $rule['category'] ?? 'marketing' );
			$purpose  = trim( (string) ( $rule['purpose'] ?? '' ) );
			$duration = trim( (string) ( $rule['duration'] ?? '' ) );
			foreach ( (array) ( $rule['cookie_patterns'] ?? [] ) as $name ) {
				$name = trim( (string) $name );
				if ( '' === $name ) {
					continue;
				}
				$out[ $category ][] = [
					'name'     => $name,
					'provider' => (string) ( $rule['label'] ?? '' ),
					'purpose'  => '' !== $purpose ? $purpose : '—',
					'duration' => '' !== $duration ? $duration : '—',
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
