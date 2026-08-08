<?php
/**
 * Anchor Compliance — consent banner, preference center, and the runtime
 * payload the front-end JS (Task 10) consumes.
 *
 * No styling here — Task 11 supplies anchor-compliance/assets/frontend.css.
 * This class only emits semantic, accessible markup and the data the runtime
 * needs to make decisions (categories, cookie patterns, REST URL, etc).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Banner {

	/** @var Anchor_Compliance_Consent_State */
	private $state;

	/** @var Anchor_Compliance_Geo */
	private $geo;

	/** @var Anchor_Compliance_Service_Registry */
	private $registry;

	/** @var Anchor_Compliance_Consent_Mode */
	private $consent_mode;

	public function __construct( $state, $geo, $registry, $consent_mode ) {
		$this->state        = $state;
		$this->geo          = $geo;
		$this->registry     = $registry;
		$this->consent_mode = $consent_mode;
	}

	private function opts() {
		return Anchor_Compliance_Settings::get();
	}

	/**
	 * The effective category grant map for this request/response — the same
	 * default-grant rule used everywhere else in the module: opt-out regions
	 * default open, strict regions default closed.
	 */
	private function categories() {
		return $this->state->categories( ! $this->geo->is_strict() );
	}

	/**
	 * Human labels/descriptions for the four fixed categories. Not part of
	 * the `content` settings section (that holds banner copy, not per-category
	 * text), so they live here and are filterable for site-specific wording.
	 */
	private function category_labels() {
		return (array) apply_filters( 'anchor_compliance_category_labels', [
			'necessary'  => __( 'Necessary', 'anchor-schema' ),
			'functional' => __( 'Functional', 'anchor-schema' ),
			'analytics'  => __( 'Analytics', 'anchor-schema' ),
			'marketing'  => __( 'Marketing', 'anchor-schema' ),
		] );
	}

	private function category_descriptions() {
		return (array) apply_filters( 'anchor_compliance_category_descriptions', [
			'necessary'  => __( 'Required for the site to function and cannot be switched off.', 'anchor-schema' ),
			'functional' => __( 'Enables enhanced functionality such as live chat and embedded maps.', 'anchor-schema' ),
			'analytics'  => __( 'Helps us understand how visitors use the site so we can improve it.', 'anchor-schema' ),
			'marketing'  => __( 'Used to deliver more relevant ads and measure campaign performance.', 'anchor-schema' ),
		] );
	}

	/**
	 * The `AnchorComplianceData` payload the front-end runtime (Task 10) reads.
	 * Every key here is part of the documented contract in the task brief —
	 * do not rename, remove, or change the type of an existing key.
	 *
	 * @return array
	 */
	public function payload() {
		$opts       = $this->opts();
		$categories = $this->categories();

		$cookie_patterns = [];
		foreach ( [ 'functional', 'analytics', 'marketing' ] as $cat ) {
			$cookie_patterns[ $cat ] = $this->registry->cookie_patterns_for( [ $cat ] );
		}

		$services = $this->registry->all();
		$ctm      = isset( $services['calltrackingmetrics'] ) ? $services['calltrackingmetrics'] : null;

		// Gating rules for the runtime's client-side iframe guard. Three
		// sibling modules build YouTube/Vimeo iframes in the browser, which
		// never pass through the output-buffer blocker, so the runtime has to
		// recognise them itself. Emitting the registry's own rules here keeps
		// that list from drifting away from the server's — a hardcoded JS copy
		// would silently stop matching the moment an admin adds a custom rule
		// or re-categorises a service.
		//
		// Only pattern + category are exposed: the runtime has no use for the
		// service key or label, and a smaller payload is on every page.
		// Duplicates are collapsed because several services legitimately share
		// a pattern once categories are overridden.
		$iframe_rules = [];
		$seen_rules   = [];
		foreach ( (array) $this->registry->active_rules() as $rule ) {
			$pattern = isset( $rule['pattern'] ) ? (string) $rule['pattern'] : '';
			if ( '' === $pattern ) {
				continue;
			}
			$key = $pattern . '|' . $rule['category'];
			if ( isset( $seen_rules[ $key ] ) ) {
				continue;
			}
			$seen_rules[ $key ] = true;
			$iframe_rules[]     = [
				'pattern'  => $pattern,
				'category' => $rule['category'],
			];
		}

		return [
			'posture'          => $this->geo->posture(),
			'gpc'              => $this->state->is_gpc(),
			'hasConsent'       => $this->state->has_stored_consent(),
			'categories'       => $categories,
			'policyVersion'    => (int) $opts['general']['policy_version'],
			'lifetimeDays'     => (int) $opts['general']['consent_lifetime_days'],
			'cookieName'       => Anchor_Compliance_Consent_State::COOKIE,
			'restUrl'          => rest_url( Anchor_Compliance_Rest::NAMESPACE . '/consent' ),
			'strictCountries'  => array_values( (array) $opts['regions']['strict_countries'] ),
			'allowClientRelax' => ! empty( $opts['regions']['allow_client_relax'] ),
			'consentMode'      => ! empty( $opts['advanced']['consent_mode_enabled'] ),
			'signalMap'        => Anchor_Compliance_Consent_Mode::signal_map(),
			'cookiePatterns'   => $cookie_patterns,
			'iframeRules'      => $iframe_rules,
			'ctm'              => [
				'enabled'  => $ctm ? (bool) $ctm['enabled'] : false,
				'category' => $ctm ? $ctm['category'] : 'marketing',
			],
			'i18n'             => $opts['content'],
		];
	}

	/**
	 * Resolved brand colors: ['accent','surface','text']. When
	 * appearance.inherit_brand is on, reads Anchor Site Config's
	 * `anchor_site_config_options` option (which may not exist at all) and
	 * maps colors.primary -> accent, colors.ivory -> surface,
	 * colors.ink -> text, falling back to this module's own color_* settings
	 * for anything Site Config doesn't supply.
	 *
	 * @return array{accent:string,surface:string,text:string}
	 */
	public function brand_colors() {
		$appearance = $this->opts()['appearance'];

		$fallback = [
			'accent'  => $appearance['color_accent'],
			'surface' => $appearance['color_surface'],
			'text'    => $appearance['color_text'],
		];

		if ( empty( $appearance['inherit_brand'] ) ) {
			return $fallback;
		}

		$site_config = get_option( 'anchor_site_config_options' );
		$colors      = ( is_array( $site_config ) && isset( $site_config['colors'] ) && is_array( $site_config['colors'] ) )
			? $site_config['colors']
			: [];

		return [
			'accent'  => ! empty( $colors['primary'] ) ? $colors['primary'] : $fallback['accent'],
			'surface' => ! empty( $colors['ivory'] ) ? $colors['ivory'] : $fallback['surface'],
			'text'    => ! empty( $colors['ink'] ) ? $colors['ink'] : $fallback['text'],
		];
	}

	/**
	 * Black or white — whichever gives better WCAG contrast against $hex.
	 * Standard relative-luminance / contrast-ratio calculation (WCAG 2.x).
	 *
	 * @param string $hex e.g. "#bf8f43"
	 * @return string "#000000" or "#ffffff"
	 */
	private function contrast_ink( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#000000';
		}

		$channel = static function ( $c ) {
			$c = $c / 255;
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};

		$r = $channel( hexdec( substr( $hex, 0, 2 ) ) );
		$g = $channel( hexdec( substr( $hex, 2, 2 ) ) );
		$b = $channel( hexdec( substr( $hex, 4, 2 ) ) );

		$luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

		$contrast_with_white = 1.05 / ( $luminance + 0.05 );
		$contrast_with_black = ( $luminance + 0.05 ) / 0.05;

		return $contrast_with_white >= $contrast_with_black ? '#ffffff' : '#000000';
	}

	/**
	 * Re-validates a value as a CSS hex color, independent of whatever
	 * validation (if any) it already passed on the way in. `brand_colors()`
	 * can return a value pulled straight from Anchor Site Config's
	 * `anchor_site_config_options` option, which this module never sanitizes
	 * on write — so it must be treated as untrusted here, at the point it is
	 * interpolated into a CSS declaration, not assumed safe because it once
	 * passed through `sanitize_hex_color()` elsewhere.
	 *
	 * @param mixed  $value    Candidate color.
	 * @param string $fallback Known-safe hex to use when $value is not one.
	 * @return string A value `sanitize_hex_color()` accepts as-is.
	 */
	private function safe_hex( $value, $fallback ) {
		$clean = sanitize_hex_color( (string) $value );
		return $clean ? $clean : $fallback;
	}

	/**
	 * Registers (does not print) the front-end assets and attaches the
	 * runtime payload. Hooked to wp_enqueue_scripts.
	 */
	public function enqueue() {
		$opts = $this->opts();
		if ( empty( $opts['general']['enabled'] ) ) {
			return;
		}

		$base = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-compliance/assets/';

		wp_enqueue_style( 'anchor-compliance', $base . 'frontend.css', [], Anchor_Compliance_Module::VERSION );
		wp_enqueue_script( 'anchor-compliance', $base . 'frontend.js', [], Anchor_Compliance_Module::VERSION, true );

		wp_add_inline_script(
			'anchor-compliance',
			'window.AnchorComplianceData=' . wp_json_encode( $this->payload() ) . ';',
			'before'
		);

		// The same brand tokens `render()` prints as an inline style on
		// #anchor-cmp, also registered at :root scope so contexts that render
		// outside that element (the blocked-iframe placeholder, the cookie
		// policy shortcode table, [anchor_consent_link]/[anchor_do_not_sell])
		// can resolve them instead of always falling back to currentColor.
		// The inline attribute on #anchor-cmp stays put and, being more
		// specific, still overrides this for anything inside it.
		//
		// Built defensively rather than interpolated directly: every value is
		// re-validated at the point of use (sanitize_hex_color()/int cast) so
		// a malformed stored value can't break out of the declaration.
		$colors     = $this->brand_colors();
		$accent_ink = $this->contrast_ink( $colors['accent'] );

		$root_vars = sprintf(
			':root{--acmp-accent:%s;--acmp-surface:%s;--acmp-text:%s;--acmp-radius:%dpx;--acmp-accent-ink:%s;}',
			$this->safe_hex( $colors['accent'], '#bf8f43' ),
			$this->safe_hex( $colors['surface'], '#ffffff' ),
			$this->safe_hex( $colors['text'], '#1a1a1a' ),
			(int) $opts['appearance']['radius'],
			$this->safe_hex( $accent_ink, '#000000' )
		);

		wp_add_inline_style( 'anchor-compliance', $root_vars );
	}

	/**
	 * Prints the banner + preference-center markup into the footer.
	 * Hooked to wp_footer, priority 5 (ahead of most theme/plugin output).
	 */
	public function render() {
		$opts = $this->opts();
		if ( empty( $opts['general']['enabled'] ) ) {
			return;
		}

		$appearance = $opts['appearance'];
		$content    = $opts['content'];
		$colors     = $this->brand_colors();
		$radius     = (int) $appearance['radius'];
		$accent_ink = $this->contrast_ink( $colors['accent'] );

		$strict       = $this->geo->is_strict();
		$body_copy    = $strict ? $content['body'] : $content['notice_body'];
		$reject_label = $strict ? $content['reject_label'] : $content['dns_label'];

		$categories = $this->categories();
		$labels     = $this->category_labels();
		$descs      = $this->category_descriptions();
		$cookies    = $this->registry->cookies_by_category();

		$root_style = sprintf(
			'--acmp-accent:%s;--acmp-surface:%s;--acmp-text:%s;--acmp-radius:%dpx;--acmp-accent-ink:%s;',
			$colors['accent'],
			$colors['surface'],
			$colors['text'],
			$radius,
			$accent_ink
		);

		printf(
			'<div id="anchor-cmp" class="anchor-cmp anchor-cmp--%1$s anchor-cmp--%2$s" style="%3$s" hidden>',
			esc_attr( $appearance['layout'] ),
			esc_attr( $appearance['position'] ),
			esc_attr( $root_style )
		);

		// ── Banner panel ──────────────────────────────────────────────
		printf(
			'<div id="anchor-cmp-banner" class="anchor-cmp-banner" role="dialog" aria-modal="true" aria-labelledby="anchor-cmp-heading">'
		);
		printf( '<h2 id="anchor-cmp-heading" class="anchor-cmp-heading">%s</h2>', wp_kses_post( $content['heading'] ) );
		printf( '<div class="anchor-cmp-body">%s</div>', wp_kses_post( $body_copy ) );

		if ( $this->state->is_gpc() ) {
			printf(
				'<p class="anchor-cmp-gpc-notice">%s</p>',
				esc_html__( 'Your Global Privacy Control signal has been honored.', 'anchor-schema' )
			);
		}

		echo '<div class="anchor-cmp-actions">';
		printf(
			'<button type="button" class="anchor-cmp-btn anchor-cmp-btn--reject" data-anchor-action="reject-all">%s</button>',
			wp_kses_post( $reject_label )
		);
		printf(
			'<button type="button" class="anchor-cmp-btn anchor-cmp-btn--customize" data-anchor-action="customize" aria-controls="anchor-cmp-prefs">%s</button>',
			wp_kses_post( $content['customize_label'] )
		);
		printf(
			'<button type="button" class="anchor-cmp-btn anchor-cmp-btn--accept" data-anchor-action="accept-all">%s</button>',
			wp_kses_post( $content['accept_label'] )
		);
		echo '</div>'; // .anchor-cmp-actions
		echo '</div>'; // #anchor-cmp-banner

		// ── Preference panel ──────────────────────────────────────────
		printf(
			'<div id="anchor-cmp-prefs" class="anchor-cmp-prefs" role="dialog" aria-modal="true" aria-labelledby="anchor-cmp-prefs-heading" hidden>'
		);
		printf( '<h2 id="anchor-cmp-prefs-heading">%s</h2>', esc_html( $content['customize_label'] ) );
		printf(
			'<button type="button" class="anchor-cmp-prefs-close" data-anchor-action="close" aria-label="%s">&times;</button>',
			esc_attr__( 'Close', 'anchor-schema' )
		);

		echo '<div class="anchor-cmp-categories">';
		foreach ( Anchor_Compliance_Consent_State::all_categories() as $cat ) {
			$is_necessary = ( 'necessary' === $cat );
			$checked      = $is_necessary || ! empty( $categories[ $cat ] );
			$desc_id      = 'anchor-cmp-cat-' . $cat . '-desc';
			$input_id     = 'anchor-cmp-cat-' . $cat;

			echo '<div class="anchor-cmp-category">';
			echo '<div class="anchor-cmp-category-header">';
			printf(
				'<input type="checkbox" id="%1$s" data-anchor-category="%2$s"%3$s%4$s aria-describedby="%5$s">',
				esc_attr( $input_id ),
				esc_attr( $cat ),
				$checked ? ' checked' : '',
				$is_necessary ? ' disabled' : '',
				esc_attr( $desc_id )
			);
			printf(
				' <label for="%1$s">%2$s</label>',
				esc_attr( $input_id ),
				esc_html( isset( $labels[ $cat ] ) ? $labels[ $cat ] : ucfirst( $cat ) )
			);
			echo '</div>'; // .anchor-cmp-category-header

			printf(
				'<p id="%1$s" class="anchor-cmp-category-desc">%2$s</p>',
				esc_attr( $desc_id ),
				esc_html( isset( $descs[ $cat ] ) ? $descs[ $cat ] : '' )
			);

			$rows = isset( $cookies[ $cat ] ) ? $cookies[ $cat ] : [];
			printf(
				'<details class="anchor-cmp-category-cookies"><summary>%s</summary>',
				esc_html(
					sprintf(
						/* translators: %d: number of cookies in this category. */
						_n( '%d cookie', '%d cookies', count( $rows ), 'anchor-schema' ),
						count( $rows )
					)
				)
			);
			echo '<table class="anchor-cmp-cookie-table"><thead><tr>'
				. '<th>' . esc_html__( 'Name', 'anchor-schema' ) . '</th>'
				. '<th>' . esc_html__( 'Provider', 'anchor-schema' ) . '</th>'
				. '<th>' . esc_html__( 'Purpose', 'anchor-schema' ) . '</th>'
				. '<th>' . esc_html__( 'Duration', 'anchor-schema' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr>'
					. '<td>' . esc_html( $row['name'] ) . '</td>'
					. '<td>' . esc_html( $row['provider'] ) . '</td>'
					. '<td>' . esc_html( $row['purpose'] ) . '</td>'
					. '<td>' . esc_html( $row['duration'] ) . '</td>'
					. '</tr>';
			}
			echo '</tbody></table></details>';

			echo '</div>'; // .anchor-cmp-category
		}
		echo '</div>'; // .anchor-cmp-categories

		$links = [
			'privacy_policy_url' => __( 'Privacy Policy', 'anchor-schema' ),
			'cookie_policy_url'  => __( 'Cookie Policy', 'anchor-schema' ),
			'terms_url'          => __( 'Terms', 'anchor-schema' ),
		];
		$link_markup = '';
		foreach ( $links as $key => $label ) {
			if ( '' === trim( (string) $opts['general'][ $key ] ) ) {
				continue;
			}
			$link_markup .= sprintf(
				'<a href="%1$s" class="anchor-cmp-footer-link">%2$s</a>',
				esc_url( $opts['general'][ $key ] ),
				esc_html( $label )
			);
		}
		if ( '' !== $link_markup ) {
			echo '<div class="anchor-cmp-footer-links">' . $link_markup . '</div>';
		}

		echo '<div class="anchor-cmp-prefs-actions">';
		printf(
			'<button type="button" class="anchor-cmp-btn anchor-cmp-btn--reject" data-anchor-action="reject-all">%s</button>',
			wp_kses_post( $reject_label )
		);
		printf(
			'<button type="button" class="anchor-cmp-btn anchor-cmp-btn--save" data-anchor-action="save-preferences">%s</button>',
			wp_kses_post( $content['save_label'] )
		);
		printf(
			'<button type="button" class="anchor-cmp-btn anchor-cmp-btn--accept" data-anchor-action="accept-all">%s</button>',
			wp_kses_post( $content['accept_label'] )
		);
		echo '</div>'; // .anchor-cmp-prefs-actions

		echo '</div>'; // #anchor-cmp-prefs

		// ── Floating re-entry pill ────────────────────────────────────
		if ( ! empty( $appearance['show_pill'] ) ) {
			printf(
				'<button type="button" class="anchor-cmp-pill anchor-cmp-pill--%2$s" data-anchor-action="open-preferences" aria-label="%1$s"><span class="anchor-cmp-sr-only">%1$s</span></button>',
				esc_attr__( 'Cookie Settings', 'anchor-schema' ),
				esc_attr( $appearance['pill_position'] )
			);
		}

		// Announcements for the JS runtime (e.g. "Preferences saved").
		echo '<div id="anchor-cmp-live" class="anchor-cmp-sr-only" aria-live="polite" aria-atomic="true"></div>';

		echo '</div>'; // #anchor-cmp
	}

	/**
	 * [anchor_consent_link] — a generic "manage my cookie preferences" link,
	 * for placement in a footer menu or a page.
	 */
	public function shortcode_consent_link( $atts ) {
		if ( empty( $this->opts()['general']['enabled'] ) ) {
			return '';
		}

		$atts = shortcode_atts(
			[ 'text' => __( 'Cookie Preferences', 'anchor-schema' ) ],
			(array) $atts,
			'anchor_consent_link'
		);
		$text = apply_filters( 'anchor_compliance_consent_link_text', $atts['text'] );

		return sprintf(
			'<button type="button" class="anchor-cmp-link" data-anchor-action="open-preferences">%s</button>',
			esc_html( $text )
		);
	}

	/**
	 * [anchor_do_not_sell] — the CCPA/CPRA-style "Do Not Sell or Share My
	 * Personal Information" link.
	 */
	public function shortcode_do_not_sell( $atts ) {
		$opts = $this->opts();
		if ( empty( $opts['general']['enabled'] ) ) {
			return '';
		}

		$atts = shortcode_atts(
			[ 'text' => $opts['content']['dns_label'] ],
			(array) $atts,
			'anchor_do_not_sell'
		);

		return sprintf(
			'<button type="button" class="anchor-cmp-link" data-anchor-action="do-not-sell">%s</button>',
			esc_html( $atts['text'] )
		);
	}
}
