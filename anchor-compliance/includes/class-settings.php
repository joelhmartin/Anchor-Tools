<?php
/**
 * Anchor Compliance — settings: defaults, sanitize, and the admin tab.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Settings {

	const OPTION_GROUP = 'anchor_compliance_group';

	public function __construct() {
		add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 15 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'anchor_settings_enqueue_compliance', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_anchor_compliance_export_log', [ $this, 'handle_export_log' ] );
	}

	/**
	 * Every setting the module understands, with its default.
	 */
	public static function defaults() {
		return [
			'general' => [
				'enabled'               => true,
				'policy_version'        => 1,
				'consent_lifetime_days' => 365,
				'privacy_policy_url'    => '',
				'cookie_policy_url'     => '',
				'terms_url'             => '',
				'company_name'          => '',
			],
			'regions' => [
				'strict_countries'  => self::default_strict_countries(),
				'unknown_fallback'  => 'strict', // strict | optout
				'ip_api_provider'   => '',       // '' | ipinfo | ipapi
				'ip_api_token'      => '',
				'allow_client_relax' => true,
			],
			'appearance' => [
				'layout'          => 'floating', // bar | floating | modal | corner
				'position'        => 'bottom-left',
				'inherit_brand'   => true,
				'color_accent'    => '#bf8f43',
				'color_surface'   => '#ffffff',
				'color_text'      => '#1a1a1a',
				'radius'          => 16,
				'dark_mode'       => 'auto', // auto | light | dark
				'logo_id'         => 0,
				'show_pill'       => true,
				'pill_position'   => 'bottom-left',
			],
			'content' => [
				'heading'         => __( 'We value your privacy', 'anchor-schema' ),
				'body'            => __( 'We use cookies to improve your experience, analyze site traffic, and personalize content. You can choose which categories to allow.', 'anchor-schema' ),
				'accept_label'    => __( 'Accept All', 'anchor-schema' ),
				'reject_label'    => __( 'Essential Only', 'anchor-schema' ),
				'customize_label' => __( 'Customize', 'anchor-schema' ),
				'save_label'      => __( 'Save Preferences', 'anchor-schema' ),
				'notice_body'     => __( 'We use cookies and similar technologies. You may opt out of the sale or sharing of your personal information.', 'anchor-schema' ),
				'dns_label'       => __( 'Do Not Sell or Share My Personal Information', 'anchor-schema' ),

				// Read by the front-end runtime only (never server-rendered).
				// They reach it through payload()['i18n'], which is this whole
				// section verbatim, so they must live here to be translatable
				// and editable rather than hardcoded in frontend.js.
				'saved_message'     => __( 'Your privacy preferences have been saved.', 'anchor-schema' ),
				'unblocked_message' => __( 'Content unblocked.', 'anchor-schema' ),
				'gpc_message'       => __( 'Your Global Privacy Control signal has been honored.', 'anchor-schema' ),
				'dns_confirmation'  => __( 'You have opted out of the sale or sharing of your personal information.', 'anchor-schema' ),
				'placeholder_text'  => __( 'This content is blocked until you accept the related cookies.', 'anchor-schema' ),
				'placeholder_button' => __( 'Accept & Load', 'anchor-schema' ),
			],
			'services'     => [], // service_key => [ 'enabled' => bool, 'category' => string ]
			'custom_rules' => [], // [ [ 'label','url_pattern','category','cookie_patterns' ], ... ]
			'log' => [
				'enabled'        => true,
				'retention_days' => 730,
			],
			'dsar' => [
				'enabled'          => true,
				'notify_email'     => '',
				// 30 satisfies GDPR Art. 12(3) (one month); 45 is CCPA-only.
				'response_days'    => 30,
				// Closed (completed/rejected) requests older than this are
				// purged daily; 0 keeps them forever.
				'retention_days'   => 365,
			],
			'advanced' => [
				'buffer_enabled'       => true,
				'consent_mode_enabled' => true,
				'wait_for_update'      => 500,
				'honor_gpc'            => true,
				'debug'                => false,
			],
		];
	}

	/**
	 * EEA + UK + Switzerland + Brazil. These are the regions where consent must
	 * precede storage; everything else is treated as opt-out.
	 */
	public static function default_strict_countries() {
		return [
			'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE',
			'IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
			'IS','LI','NO', // EEA non-EU
			'GB','CH','BR',
		];
	}

	/**
	 * Stored option merged over defaults, section by section, so a partial
	 * stored option never drops sibling defaults.
	 */
	public static function get() {
		$stored   = (array) get_option( Anchor_Compliance_Module::OPTION_KEY, [] );
		$defaults = self::defaults();
		$out      = $defaults;

		foreach ( $defaults as $section => $section_defaults ) {
			if ( ! isset( $stored[ $section ] ) || ! is_array( $stored[ $section ] ) ) {
				continue;
			}
			// List-shaped sections replace wholesale; map-shaped sections merge.
			if ( in_array( $section, [ 'services', 'custom_rules' ], true ) ) {
				$out[ $section ] = $stored[ $section ];
			} else {
				$out[ $section ] = array_merge( $section_defaults, $stored[ $section ] );
			}
		}

		return $out;
	}

	public function register_settings() {
		register_setting( self::OPTION_GROUP, Anchor_Compliance_Module::OPTION_KEY, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
			'default'           => [],
		] );
	}

	/**
	 * Coerce every incoming value to its declared type and clamp ranges.
	 * Anything not recognised is dropped rather than stored.
	 */
	public static function sanitize( $input ) {
		$input = (array) $input;
		$d     = self::defaults();
		$out   = $d;

		// --- general ---
		$g = isset( $input['general'] ) ? (array) $input['general'] : [];
		$out['general']['enabled']               = ! empty( $g['enabled'] );
		$out['general']['policy_version']        = max( 1, (int) ( $g['policy_version'] ?? 1 ) );
		$out['general']['consent_lifetime_days'] = min( 730, max( 1, (int) ( $g['consent_lifetime_days'] ?? 365 ) ) );
		foreach ( [ 'privacy_policy_url', 'cookie_policy_url', 'terms_url' ] as $k ) {
			$out['general'][ $k ] = esc_url_raw( (string) ( $g[ $k ] ?? '' ), [ 'http', 'https' ] );
		}
		$out['general']['company_name'] = sanitize_text_field( (string) ( $g['company_name'] ?? '' ) );

		// --- regions ---
		$r = isset( $input['regions'] ) ? (array) $input['regions'] : [];
		$countries = (array) ( $r['strict_countries'] ?? $d['regions']['strict_countries'] );
		$out['regions']['strict_countries'] = array_values( array_unique( array_filter( array_map(
			static function ( $c ) {
				$c = strtoupper( sanitize_text_field( (string) $c ) );
				return preg_match( '/^[A-Z]{2}$/', $c ) ? $c : '';
			},
			$countries
		) ) ) );
		$unknown_fallback = $r['unknown_fallback'] ?? '';
		$out['regions']['unknown_fallback']   = in_array( $unknown_fallback, [ 'strict', 'optout' ], true ) ? $unknown_fallback : 'strict';
		$ip_api_provider = $r['ip_api_provider'] ?? '';
		$out['regions']['ip_api_provider']    = in_array( $ip_api_provider, [ '', 'ipinfo', 'ipapi' ], true ) ? $ip_api_provider : '';
		$out['regions']['ip_api_token']       = sanitize_text_field( (string) ( $r['ip_api_token'] ?? '' ) );
		$out['regions']['allow_client_relax'] = ! empty( $r['allow_client_relax'] );

		// --- appearance ---
		$a = isset( $input['appearance'] ) ? (array) $input['appearance'] : [];
		$out['appearance']['layout']        = in_array( $a['layout'] ?? '', [ 'bar', 'floating', 'modal', 'corner' ], true ) ? $a['layout'] : 'floating';
		$out['appearance']['position']      = in_array( $a['position'] ?? '', [ 'bottom-left', 'bottom-right', 'bottom-center', 'top' ], true ) ? $a['position'] : 'bottom-left';
		$out['appearance']['inherit_brand'] = ! empty( $a['inherit_brand'] );
		foreach ( [ 'color_accent', 'color_surface', 'color_text' ] as $k ) {
			$hex = sanitize_hex_color( (string) ( $a[ $k ] ?? '' ) );
			$out['appearance'][ $k ] = $hex ? $hex : $d['appearance'][ $k ];
		}
		$out['appearance']['radius']        = min( 40, max( 0, (int) ( $a['radius'] ?? 16 ) ) );
		$out['appearance']['dark_mode']     = in_array( $a['dark_mode'] ?? '', [ 'auto', 'light', 'dark' ], true ) ? $a['dark_mode'] : 'auto';
		$out['appearance']['logo_id']       = max( 0, (int) ( $a['logo_id'] ?? 0 ) );
		$out['appearance']['show_pill']     = ! empty( $a['show_pill'] );
		$out['appearance']['pill_position'] = in_array( $a['pill_position'] ?? '', [ 'bottom-left', 'bottom-right' ], true ) ? $a['pill_position'] : 'bottom-left';

		// --- content ---
		// Six of these keys are plain-text runtime strings read by frontend.js
		// and the script blocker via textContent (never rendered as markup),
		// so running them through wp_kses_post() only entity-encoded them for
		// nothing — "Accept & Load" became "Accept &amp; Load" and rendered
		// literally. Those keep sanitize_text_field(); the genuinely rich
		// fields (headings, body copy, and button labels that may legitimately
		// carry inline markup) keep wp_kses_post().
		$c = isset( $input['content'] ) ? (array) $input['content'] : [];
		$plain_text_content_keys = [
			'saved_message',
			'gpc_message',
			'unblocked_message',
			'dns_confirmation',
			'placeholder_text',
			'placeholder_button',
		];
		foreach ( $d['content'] as $k => $default ) {
			$val = isset( $c[ $k ] ) ? (string) $c[ $k ] : '';
			if ( '' === trim( $val ) ) {
				$out['content'][ $k ] = $default;
				continue;
			}
			$out['content'][ $k ] = in_array( $k, $plain_text_content_keys, true )
				? sanitize_text_field( $val )
				: wp_kses_post( $val );
		}

		// --- services ---
		$out['services'] = [];
		foreach ( (array) ( $input['services'] ?? [] ) as $key => $svc ) {
			$key = sanitize_key( $key );
			if ( ! $key ) { continue; }
			$out['services'][ $key ] = [
				'enabled'  => ! empty( $svc['enabled'] ),
				'category' => self::sanitize_category( $svc['category'] ?? 'marketing' ),
			];
		}

		// --- custom rules ---
		$out['custom_rules'] = [];
		foreach ( (array) ( $input['custom_rules'] ?? [] ) as $rule ) {
			$pattern = trim( sanitize_text_field( (string) ( $rule['url_pattern'] ?? '' ) ) );
			if ( '' === $pattern ) { continue; }
			$out['custom_rules'][] = [
				'label'           => sanitize_text_field( (string) ( $rule['label'] ?? $pattern ) ),
				'url_pattern'     => $pattern,
				'category'        => self::sanitize_category( $rule['category'] ?? 'marketing' ),
				'cookie_patterns' => array_values( array_filter( array_map(
					'sanitize_text_field',
					preg_split( '/[\s,]+/', (string) ( $rule['cookie_patterns'] ?? '' ) ) ?: []
				) ) ),
			];
		}

		// --- log ---
		$l = isset( $input['log'] ) ? (array) $input['log'] : [];
		$out['log']['enabled']        = ! empty( $l['enabled'] );
		$out['log']['retention_days'] = min( 3650, max( 30, (int) ( $l['retention_days'] ?? 730 ) ) );

		// --- dsar ---
		$s = isset( $input['dsar'] ) ? (array) $input['dsar'] : [];
		$out['dsar']['enabled']        = ! empty( $s['enabled'] );
		$out['dsar']['notify_email']   = sanitize_email( (string) ( $s['notify_email'] ?? '' ) );
		$out['dsar']['response_days']  = min( 90, max( 1, (int) ( $s['response_days'] ?? 30 ) ) );
		$out['dsar']['retention_days'] = min( 3650, max( 0, (int) ( $s['retention_days'] ?? 365 ) ) );

		// --- advanced ---
		$v = isset( $input['advanced'] ) ? (array) $input['advanced'] : [];
		$out['advanced']['buffer_enabled']       = ! empty( $v['buffer_enabled'] );
		$out['advanced']['consent_mode_enabled'] = ! empty( $v['consent_mode_enabled'] );
		$out['advanced']['wait_for_update']      = min( 5000, max( 0, (int) ( $v['wait_for_update'] ?? 500 ) ) );
		$out['advanced']['honor_gpc']            = ! empty( $v['honor_gpc'] );
		$out['advanced']['debug']                = ! empty( $v['debug'] );

		return $out;
	}

	/** @return string One of the four category slugs. */
	public static function sanitize_category( $cat ) {
		$cat = sanitize_key( (string) $cat );
		return in_array( $cat, [ 'necessary', 'functional', 'analytics', 'marketing' ], true ) ? $cat : 'marketing';
	}

	public function register_tab( $tabs ) {
		$tabs['compliance'] = [
			'label'    => __( 'Compliance', 'anchor-schema' ),
			'callback' => [ $this, 'render_tab_content' ],
		];
		return $tabs;
	}

	/**
	 * Enqueue admin assets. Loaded only on this tab, via the
	 * anchor_settings_enqueue_compliance action.
	 */
	public function enqueue_assets() {
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_media();

		$base = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-compliance/assets/';

		wp_enqueue_style( 'anchor-compliance-admin', $base . 'admin.css', [ 'wp-color-picker' ], Anchor_Compliance_Module::VERSION );
		wp_enqueue_script( 'anchor-compliance-admin', $base . 'admin.js', [ 'jquery', 'wp-color-picker' ], Anchor_Compliance_Module::VERSION, true );
	}

	/**
	 * `admin_post_anchor_compliance_export_log`. Anchor_Compliance_Consent_Log::export_csv()
	 * deliberately does not self-guard (see its docblock) — verifying capability
	 * and nonce here, before ever calling it, is this handler's entire job.
	 */
	public function handle_export_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export the consent log.', 'anchor-schema' ) );
		}
		check_admin_referer( 'anchor_cmp_export_log' );
		( new Anchor_Compliance_Consent_Log() )->export_csv();
	}

	/* ---------------------------------------------------------------------
	 * Admin screen
	 * ------------------------------------------------------------------- */

	/**
	 * Renders the nine settings sections.
	 *
	 * A single <form action="options.php"> carries every field that maps
	 * into the stored option, so a partial submit never wipes a sibling
	 * section's stored values (sanitize() rebuilds every section from
	 * defaults() on each save, so an omitted section round-trips as its
	 * defaults). The Consent Log's filter (GET) and export (POST to
	 * admin-post.php) controls need their own <form>s with a different
	 * method/target; nested <form> elements are invalid HTML and the parser
	 * drops the inner one, which would silently break both controls. They
	 * are rendered after this form closes instead.
	 */
	public function render_tab_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opt = Anchor_Compliance_Module::OPTION_KEY;
		$s   = self::get();

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::OPTION_GROUP );

		$this->render_general_section( $opt, $s );
		$this->render_regions_section( $opt, $s );
		$this->render_appearance_section( $opt, $s );
		$this->render_content_section( $opt, $s );
		$this->render_services_section( $opt );
		$this->render_custom_rules_section( $opt, $s );
		$this->render_consent_log_settings_and_viewer( $opt, $s );
		$this->render_privacy_requests_section( $opt, $s );
		$this->render_advanced_section( $opt, $s );

		submit_button( __( 'Save Compliance Settings', 'anchor-schema' ) );
		echo '</form>';

		$this->render_consent_log_filters_and_export();
	}

	/* --- General ------------------------------------------------------- */

	private function render_general_section( $opt, $s ) {
		$g = $s['general'];
		?>
		<h2><?php esc_html_e( 'General', 'anchor-schema' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable cookie consent', 'anchor-schema' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[general][enabled]" value="1" <?php checked( $g['enabled'] ); ?> />
						<?php esc_html_e( 'Show the consent banner and enforce script blocking.', 'anchor-schema' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="anchor_cmp_policy_version"><?php esc_html_e( 'Policy version', 'anchor-schema' ); ?></label></th>
				<td>
					<input type="number" min="1" step="1" id="anchor_cmp_policy_version" name="<?php echo esc_attr( $opt ); ?>[general][policy_version]" value="<?php echo esc_attr( $g['policy_version'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Increasing this re-prompts every visitor.', 'anchor-schema' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="anchor_cmp_consent_lifetime_days"><?php esc_html_e( 'Consent lifetime (days)', 'anchor-schema' ); ?></label></th>
				<td><input type="number" min="1" max="730" id="anchor_cmp_consent_lifetime_days" name="<?php echo esc_attr( $opt ); ?>[general][consent_lifetime_days]" value="<?php echo esc_attr( $g['consent_lifetime_days'] ); ?>" class="small-text" /></td>
			</tr>
			<?php
			foreach ( [
				'privacy_policy_url' => __( 'Privacy policy URL', 'anchor-schema' ),
				'cookie_policy_url'  => __( 'Cookie policy URL', 'anchor-schema' ),
				'terms_url'          => __( 'Terms URL', 'anchor-schema' ),
			] as $key => $label ) :
				$field_id = 'anchor_cmp_' . $key;
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<input type="url" class="regular-text anchor-cmp-url-input" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $opt ); ?>[general][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_url( $g[ $key ] ); ?>" />
						<select class="anchor-cmp-page-picker" data-target="<?php echo esc_attr( $field_id ); ?>">
							<?php echo $this->page_picker_options( $g[ $key ] ); ?>
						</select>
						<p class="description"><?php esc_html_e( 'Or pick a published page — its permalink fills the field above.', 'anchor-schema' ); ?></p>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><label for="anchor_cmp_company_name"><?php esc_html_e( 'Company name', 'anchor-schema' ); ?></label></th>
				<td><input type="text" class="regular-text" id="anchor_cmp_company_name" name="<?php echo esc_attr( $opt ); ?>[general][company_name]" value="<?php echo esc_attr( $g['company_name'] ); ?>" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param string $current_url
	 * @return string Pre-escaped <option> markup for a "select a page" dropdown.
	 */
	private function page_picker_options( $current_url ) {
		$pages = get_pages( [ 'sort_column' => 'post_title', 'post_status' => 'publish' ] );
		$html  = '<option value="">' . esc_html__( '— Select a page —', 'anchor-schema' ) . '</option>';
		foreach ( $pages as $page ) {
			$permalink = get_permalink( $page );
			if ( ! $permalink ) {
				continue;
			}
			$is_current = '' !== trim( (string) $current_url ) && untrailingslashit( $permalink ) === untrailingslashit( (string) $current_url );
			$html      .= sprintf(
				'<option value="%1$s" data-url="%2$s"%3$s>%4$s</option>',
				esc_attr( $page->ID ),
				esc_url( $permalink ),
				$is_current ? ' selected="selected"' : '',
				esc_html( $page->post_title )
			);
		}
		return $html;
	}

	/* --- Regions -------------------------------------------------------- */

	private function render_regions_section( $opt, $s ) {
		$r       = $s['regions'];
		$geo     = new Anchor_Compliance_Geo();
		$country = $geo->country();
		$source  = $geo->source();
		?>
		<h2><?php esc_html_e( 'Regions', 'anchor-schema' ); ?></h2>

		<div class="anchor-cmp-geo-readout notice notice-info inline">
			<?php if ( 'none' === $source ) : ?>
				<p>
					<strong><?php esc_html_e( 'No geo header detected', 'anchor-schema' ); ?></strong>
					&#8212; <?php esc_html_e( 'falling back to your Unknown-region setting; the client-side timezone check will still relax for non-EU visitors.', 'anchor-schema' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'This usually means your host or CDN is not sending a geo header. On Kinsta, Cloudflare\'s CF-IPCountry header is only added by a "managed transform" that lives in Kinsta\'s own Cloudflare account, not your site\'s wp-admin — ask Kinsta support to enable it if you want automatic region detection.', 'anchor-schema' ); ?>
				</p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: 1: detected two-letter country code, 2: geo signal name (e.g. CF-IPCountry) */
						esc_html__( 'Detected region: %1$s — via %2$s', 'anchor-schema' ),
						esc_html( (string) $country ),
						esc_html( $this->geo_source_label( $source ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Strict-consent countries', 'anchor-schema' ); ?></th>
				<td>
					<select multiple="multiple" size="10" class="anchor-cmp-country-select" name="<?php echo esc_attr( $opt ); ?>[regions][strict_countries][]">
						<?php foreach ( self::default_strict_countries() as $code ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php echo in_array( $code, (array) $r['strict_countries'], true ) ? 'selected="selected"' : ''; ?>><?php echo esc_html( $code ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Visitors from a selected country must opt in before non-essential cookies load.', 'anchor-schema' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Unknown-region fallback', 'anchor-schema' ); ?></th>
				<td>
					<?php
					foreach ( [
						'strict' => __( 'Treat as strict — require consent (safer default)', 'anchor-schema' ),
						'optout' => __( 'Treat as opt-out — US-style', 'anchor-schema' ),
					] as $value => $label ) :
						?>
						<label>
							<input type="radio" name="<?php echo esc_attr( $opt ); ?>[regions][unknown_fallback]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $r['unknown_fallback'], $value ); ?> />
							<?php echo esc_html( $label ); ?>
						</label><br />
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="anchor_cmp_ip_api_provider"><?php esc_html_e( 'IP API provider', 'anchor-schema' ); ?></label></th>
				<td>
					<select id="anchor_cmp_ip_api_provider" name="<?php echo esc_attr( $opt ); ?>[regions][ip_api_provider]">
						<?php foreach ( [ '' => __( 'None (headers only)', 'anchor-schema' ), 'ipinfo' => 'ipinfo.io', 'ipapi' => 'ipapi.co' ] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $r['ip_api_provider'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[regions][ip_api_token]" value="<?php echo esc_attr( $r['ip_api_token'] ); ?>" placeholder="<?php esc_attr_e( 'API token', 'anchor-schema' ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional Tier-2 lookup, used only when no geo header is present. Cached per /24 (or /64) block.', 'anchor-schema' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Client-side relaxation', 'anchor-schema' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[regions][allow_client_relax]" value="1" <?php checked( $r['allow_client_relax'] ); ?> />
						<?php esc_html_e( 'Allow the browser timezone check to relax strict to opt-out for non-EU visitors when no server-side signal is available.', 'anchor-schema' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	/** @return string Human-readable name for a Anchor_Compliance_Geo::source() value. */
	private function geo_source_label( $source ) {
		$labels = [
			'cf'         => 'CF-IPCountry',
			'cloudfront' => 'CloudFront-Viewer-Country',
			'vercel'     => 'X-Vercel-IP-Country',
			'geoip'      => 'GEOIP_COUNTRY_CODE',
			'xgeo'       => 'X-Geo-Country',
			'ipapi'      => __( 'IP API lookup', 'anchor-schema' ),
		];
		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}

	/* --- Appearance ------------------------------------------------------ */

	private function render_appearance_section( $opt, $s ) {
		$a = $s['appearance'];
		$d = self::defaults()['appearance'];
		?>
		<h2><?php esc_html_e( 'Appearance', 'anchor-schema' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="anchor_cmp_layout"><?php esc_html_e( 'Layout', 'anchor-schema' ); ?></label></th>
				<td>
					<select id="anchor_cmp_layout" name="<?php echo esc_attr( $opt ); ?>[appearance][layout]">
						<?php foreach ( [ 'bar', 'floating', 'modal', 'corner' ] as $value ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $a['layout'], $value ); ?>><?php echo esc_html( ucfirst( $value ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="anchor_cmp_position"><?php esc_html_e( 'Position', 'anchor-schema' ); ?></label></th>
				<td>
					<select id="anchor_cmp_position" name="<?php echo esc_attr( $opt ); ?>[appearance][position]">
						<?php foreach ( [ 'bottom-left', 'bottom-right', 'bottom-center', 'top' ] as $value ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $a['position'], $value ); ?>><?php echo esc_html( ucwords( str_replace( '-', ' ', $value ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Brand colors', 'anchor-schema' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[appearance][inherit_brand]" value="1" <?php checked( $a['inherit_brand'] ); ?> />
						<?php esc_html_e( 'Inherit colors from Anchor Site Config', 'anchor-schema' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'When enabled, the colors below are used only as a fallback if Anchor Site Config has no brand palette set.', 'anchor-schema' ); ?></p>
				</td>
			</tr>
			<?php
			foreach ( [
				'color_accent'  => __( 'Accent color', 'anchor-schema' ),
				'color_surface' => __( 'Surface color', 'anchor-schema' ),
				'color_text'    => __( 'Text color', 'anchor-schema' ),
			] as $key => $label ) :
				?>
				<tr>
					<th scope="row"><label for="anchor_cmp_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><input type="text" class="anchor-cmp-color-field" id="anchor_cmp_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $opt ); ?>[appearance][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $a[ $key ] ); ?>" data-default-color="<?php echo esc_attr( $d[ $key ] ); ?>" /></td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><label for="anchor_cmp_radius"><?php esc_html_e( 'Corner radius', 'anchor-schema' ); ?></label></th>
				<td>
					<input type="range" min="0" max="40" id="anchor_cmp_radius" name="<?php echo esc_attr( $opt ); ?>[appearance][radius]" value="<?php echo esc_attr( $a['radius'] ); ?>" oninput="this.nextElementSibling.textContent=this.value+'px'" />
					<output><?php echo esc_html( $a['radius'] ); ?>px</output>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="anchor_cmp_dark_mode"><?php esc_html_e( 'Dark mode', 'anchor-schema' ); ?></label></th>
				<td>
					<select id="anchor_cmp_dark_mode" name="<?php echo esc_attr( $opt ); ?>[appearance][dark_mode]">
						<?php foreach ( [ 'auto', 'light', 'dark' ] as $value ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $a['dark_mode'], $value ); ?>><?php echo esc_html( ucfirst( $value ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Logo', 'anchor-schema' ); ?></th>
				<td>
					<input type="hidden" id="anchor_cmp_logo_id" name="<?php echo esc_attr( $opt ); ?>[appearance][logo_id]" value="<?php echo esc_attr( $a['logo_id'] ); ?>" />
					<span id="anchor_cmp_logo_preview" class="anchor-cmp-logo-preview">
						<?php
						if ( $a['logo_id'] ) {
							$src = wp_get_attachment_image_url( (int) $a['logo_id'], 'thumbnail' );
							if ( $src ) {
								echo '<img src="' . esc_url( $src ) . '" alt="" />';
							}
						}
						?>
					</span>
					<button type="button" class="button anchor-cmp-logo-select"><?php esc_html_e( 'Select Logo', 'anchor-schema' ); ?></button>
					<button type="button" class="button anchor-cmp-logo-remove"><?php esc_html_e( 'Remove Logo', 'anchor-schema' ); ?></button>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Consent pill', 'anchor-schema' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[appearance][show_pill]" value="1" <?php checked( $a['show_pill'] ); ?> />
						<?php esc_html_e( 'Show a persistent re-open pill after the banner is dismissed', 'anchor-schema' ); ?>
					</label>
					<select name="<?php echo esc_attr( $opt ); ?>[appearance][pill_position]">
						<?php foreach ( [ 'bottom-left', 'bottom-right' ] as $value ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $a['pill_position'], $value ); ?>><?php echo esc_html( ucwords( str_replace( '-', ' ', $value ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/* --- Content ---------------------------------------------------------- */

	private function render_content_section( $opt, $s ) {
		$c             = $s['content'];
		$d             = self::defaults()['content'];
		$textarea_keys = [ 'body', 'notice_body' ];
		?>
		<h2><?php esc_html_e( 'Content', 'anchor-schema' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( $d as $key => $default ) : ?>
				<?php
				$value    = isset( $c[ $key ] ) ? (string) $c[ $key ] : '';
				$label    = ucwords( str_replace( '_', ' ', $key ) );
				$field_id = 'anchor_cmp_content_' . $key;
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<?php if ( in_array( $key, $textarea_keys, true ) ) : ?>
							<textarea id="<?php echo esc_attr( $field_id ); ?>" class="large-text" rows="3" name="<?php echo esc_attr( $opt ); ?>[content][<?php echo esc_attr( $key ); ?>]" placeholder="<?php echo esc_attr( $default ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
						<?php else : ?>
							<input type="text" id="<?php echo esc_attr( $field_id ); ?>" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[content][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $default ); ?>" />
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/* --- Services ----------------------------------------------------------- */

	private function render_services_section( $opt ) {
		$registry = new Anchor_Compliance_Service_Registry();
		$services = $registry->all();
		?>
		<h2><?php esc_html_e( 'Services', 'anchor-schema' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Every third-party service Anchor Compliance recognizes automatically. Disable a row to stop gating it, or re-categorize it.', 'anchor-schema' ); ?></p>
		<table class="widefat striped anchor-cmp-services-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Enabled', 'anchor-schema' ); ?></th>
					<th><?php esc_html_e( 'Service', 'anchor-schema' ); ?></th>
					<th><?php esc_html_e( 'Provider', 'anchor-schema' ); ?></th>
					<th><?php esc_html_e( 'Category', 'anchor-schema' ); ?></th>
					<th><?php esc_html_e( 'Cookies', 'anchor-schema' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $services as $key => $svc ) : ?>
					<tr>
						<td><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[services][<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $svc['enabled'] ) ); ?> /></td>
						<td><?php echo esc_html( $svc['name'] ); ?></td>
						<td><?php echo esc_html( $svc['provider'] ); ?></td>
						<td>
							<select name="<?php echo esc_attr( $opt ); ?>[services][<?php echo esc_attr( $key ); ?>][category]">
								<?php foreach ( [ 'necessary', 'functional', 'analytics', 'marketing' ] as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $svc['category'], $cat ); ?>><?php echo esc_html( ucfirst( $cat ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><?php echo esc_html( (string) count( (array) $svc['cookies'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* --- Custom Rules --------------------------------------------------------- */

	private function render_custom_rules_section( $opt, $s ) {
		$rules = (array) $s['custom_rules'];
		?>
		<h2><?php esc_html_e( 'Custom Rules', 'anchor-schema' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Gate a script or embed the built-in registry does not know about. A rule matches when a script URL contains the pattern.', 'anchor-schema' ); ?></p>
		<div id="anchor-cmp-custom-rules">
			<?php foreach ( $rules as $i => $rule ) : ?>
				<?php echo $this->custom_rule_row_markup( $opt, $i, $rule ); ?>
			<?php endforeach; ?>
		</div>
		<p><button type="button" class="button" id="anchor-cmp-rule-add"><?php esc_html_e( 'Add rule', 'anchor-schema' ); ?></button></p>
		<script type="text/html" id="anchor-cmp-rule-template"><?php echo $this->custom_rule_row_markup( $opt, '__INDEX__', [] ); ?></script>
		<?php
	}

	/**
	 * One repeater row. Safe both for direct render (real index, real values)
	 * and for the JS clone template (index literal "__INDEX__", empty values)
	 * — admin.js string-replaces "__INDEX__" with a running counter on clone.
	 *
	 * @return string Pre-escaped row markup.
	 */
	private function custom_rule_row_markup( $opt, $index, array $rule ) {
		$rule = array_merge( [ 'label' => '', 'url_pattern' => '', 'category' => 'marketing', 'cookie_patterns' => [] ], $rule );
		$name = esc_attr( $opt ) . '[custom_rules][' . esc_attr( $index ) . ']';

		ob_start();
		?>
		<div class="anchor-cmp-rule-row" data-index="<?php echo esc_attr( $index ); ?>">
			<input type="text" class="regular-text" name="<?php echo $name; ?>[label]" value="<?php echo esc_attr( $rule['label'] ); ?>" placeholder="<?php esc_attr_e( 'Label', 'anchor-schema' ); ?>" />
			<input type="text" class="regular-text" name="<?php echo $name; ?>[url_pattern]" value="<?php echo esc_attr( $rule['url_pattern'] ); ?>" placeholder="<?php esc_attr_e( 'URL pattern, e.g. example.com/widget.js', 'anchor-schema' ); ?>" />
			<select name="<?php echo $name; ?>[category]">
				<?php foreach ( [ 'necessary', 'functional', 'analytics', 'marketing' ] as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $rule['category'], $cat ); ?>><?php echo esc_html( ucfirst( $cat ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" class="regular-text" name="<?php echo $name; ?>[cookie_patterns]" value="<?php echo esc_attr( implode( ', ', (array) $rule['cookie_patterns'] ) ); ?>" placeholder="<?php esc_attr_e( 'Cookie name patterns, comma separated', 'anchor-schema' ); ?>" />
			<button type="button" class="button anchor-cmp-rule-remove"><?php esc_html_e( 'Remove', 'anchor-schema' ); ?></button>
		</div>
		<?php
		return ob_get_clean();
	}

	/* --- Consent Log ------------------------------------------------------------ */

	/**
	 * The log.enabled / log.retention_days settings (must live inside the
	 * main form so they round-trip on every save) plus the read-only viewer
	 * (total count, most recent 50 rows). No <form> of its own is needed for
	 * either, so both are safe inside the surrounding settings form.
	 */
	private function render_consent_log_settings_and_viewer( $opt, $s ) {
		$l = $s['log'];

		$filters = $this->consent_log_filters_from_request();
		$log     = new Anchor_Compliance_Consent_Log();
		$total   = $log->count();
		$rows    = $log->query( array_merge( $filters, [ 'limit' => 50 ] ) );
		?>
		<h2><?php esc_html_e( 'Consent Log', 'anchor-schema' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Logging', 'anchor-schema' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[log][enabled]" value="1" <?php checked( $l['enabled'] ); ?> />
						<?php esc_html_e( 'Record every consent decision (required to demonstrate consent under GDPR Art. 7(1)).', 'anchor-schema' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="anchor_cmp_log_retention"><?php esc_html_e( 'Retention (days)', 'anchor-schema' ); ?></label></th>
				<td><input type="number" min="30" max="3650" id="anchor_cmp_log_retention" name="<?php echo esc_attr( $opt ); ?>[log][retention_days]" value="<?php echo esc_attr( $l['retention_days'] ); ?>" class="small-text" /></td>
			</tr>
		</table>

		<p>
			<?php
			printf(
				/* translators: %d: number of consent records currently stored */
				esc_html__( 'Total consent records logged: %d', 'anchor-schema' ),
				(int) $total
			);
			?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Recorded (UTC)', 'anchor-schema' ); ?></th>
					<th><?php esc_html_e( 'Region', 'anchor-schema' ); ?></th>
					<th><?php esc_html_e( 'Posture', 'anchor-schema' ); ?></th>
					<th><?php esc_html_e( 'Categories', 'anchor-schema' ); ?></th>
					<th><?php esc_html_e( 'Method', 'anchor-schema' ); ?></th>
					<th><?php esc_html_e( 'Policy version', 'anchor-schema' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No consent records yet.', 'anchor-schema' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td><?php echo esc_html( $row->region ); ?></td>
							<td><?php echo esc_html( $row->posture ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) json_decode( (string) $row->categories, true ) ) ); ?></td>
							<td><?php echo esc_html( $row->method ); ?></td>
							<td><?php echo esc_html( $row->policy_version ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Read-only filter args for the log viewer. Never mutates state, so it
	 * needs no nonce — only the export action below does.
	 *
	 * @return array method?, region?, since?
	 */
	private function consent_log_filters_from_request() {
		$args = [];
		if ( ! empty( $_GET['log_method'] ) ) {
			$args['method'] = sanitize_key( wp_unslash( $_GET['log_method'] ) );
		}
		if ( ! empty( $_GET['log_region'] ) ) {
			$args['region'] = strtoupper( sanitize_text_field( wp_unslash( $_GET['log_region'] ) ) );
		}
		if ( ! empty( $_GET['log_since'] ) ) {
			$args['since'] = sanitize_text_field( wp_unslash( $_GET['log_since'] ) );
		}
		return $args;
	}

	/**
	 * The Consent Log's filter (GET, read-only) and export (POST to
	 * admin-post.php) controls. Deliberately outside the settings <form> —
	 * see render_tab_content()'s docblock for why.
	 */
	private function render_consent_log_filters_and_export() {
		$filters = $this->consent_log_filters_from_request();
		?>
		<h3><?php esc_html_e( 'Filter & Export Consent Log', 'anchor-schema' ); ?></h3>
		<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" class="anchor-cmp-log-filter-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( Anchor_Settings_Page::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="compliance" />
			<select name="log_method">
				<option value=""><?php esc_html_e( 'Any method', 'anchor-schema' ); ?></option>
				<?php foreach ( [ 'banner', 'preference_center', 'api' ] as $method ) : ?>
					<option value="<?php echo esc_attr( $method ); ?>" <?php selected( $filters['method'] ?? '', $method ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $method ) ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" name="log_region" maxlength="8" placeholder="<?php esc_attr_e( 'Region, e.g. US', 'anchor-schema' ); ?>" value="<?php echo esc_attr( $filters['region'] ?? '' ); ?>" />
			<input type="date" name="log_since" value="<?php echo esc_attr( $filters['since'] ?? '' ); ?>" />
			<?php submit_button( __( 'Filter', 'anchor-schema' ), 'secondary', '', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="anchor-cmp-log-export-form">
			<input type="hidden" name="action" value="anchor_compliance_export_log" />
			<?php wp_nonce_field( 'anchor_cmp_export_log' ); ?>
			<?php submit_button( __( 'Export CSV', 'anchor-schema' ), 'secondary', '', false ); ?>
		</form>
		<?php
	}

	/* --- Privacy Requests -------------------------------------------------------- */

	private function render_privacy_requests_section( $opt, $s ) {
		$dsar = $s['dsar'];
		?>
		<h2><?php esc_html_e( 'Privacy Requests', 'anchor-schema' ); ?></h2>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . Anchor_Compliance_Dsar::MENU_SLUG ) ); ?>">
				<?php esc_html_e( 'View Privacy Request Queue', 'anchor-schema' ); ?>
			</a>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Online requests', 'anchor-schema' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[dsar][enabled]" value="1" <?php checked( $dsar['enabled'] ); ?> />
						<?php esc_html_e( 'Accept privacy requests via the [anchor_privacy_request] form', 'anchor-schema' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="anchor_cmp_dsar_notify_email"><?php esc_html_e( 'Notify email', 'anchor-schema' ); ?></label></th>
				<td><input type="email" id="anchor_cmp_dsar_notify_email" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[dsar][notify_email]" value="<?php echo esc_attr( $dsar['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="anchor_cmp_dsar_response_days"><?php esc_html_e( 'Response deadline (days)', 'anchor-schema' ); ?></label></th>
				<td>
					<input type="number" min="1" max="90" id="anchor_cmp_dsar_response_days" name="<?php echo esc_attr( $opt ); ?>[dsar][response_days]" value="<?php echo esc_attr( $dsar['response_days'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'GDPR requires a response within one month — keep this at 30 for any site with EEA/UK visitors. 45 is appropriate only for CCPA-only (US) sites.', 'anchor-schema' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="anchor_cmp_dsar_retention_days"><?php esc_html_e( 'Retention for closed requests (days)', 'anchor-schema' ); ?></label></th>
				<td>
					<input type="number" min="0" max="3650" id="anchor_cmp_dsar_retention_days" name="<?php echo esc_attr( $opt ); ?>[dsar][retention_days]" value="<?php echo esc_attr( $dsar['retention_days'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Completed and rejected requests older than this are deleted automatically each day — the request records themselves contain personal data and must not be kept forever. Set 0 to keep them indefinitely.', 'anchor-schema' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/* --- Advanced ------------------------------------------------------------------ */

	private function render_advanced_section( $opt, $s ) {
		$v = $s['advanced'];
		?>
		<h2><?php esc_html_e( 'Advanced', 'anchor-schema' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Script blocking', 'anchor-schema' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[advanced][buffer_enabled]" value="1" <?php checked( $v['buffer_enabled'] ); ?> />
						<?php esc_html_e( 'Buffer and gate scripts until consent is given', 'anchor-schema' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Kill switch. Turning this off stops all script blocking immediately — use it if the buffer ever breaks a page.', 'anchor-schema' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Google Consent Mode', 'anchor-schema' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[advanced][consent_mode_enabled]" value="1" <?php checked( $v['consent_mode_enabled'] ); ?> />
						<?php esc_html_e( 'Emit Google Consent Mode v2 signals instead of hard-blocking Google tags', 'anchor-schema' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="anchor_cmp_wait_for_update"><?php esc_html_e( 'wait_for_update (ms)', 'anchor-schema' ); ?></label></th>
				<td><input type="number" min="0" max="5000" id="anchor_cmp_wait_for_update" name="<?php echo esc_attr( $opt ); ?>[advanced][wait_for_update]" value="<?php echo esc_attr( $v['wait_for_update'] ); ?>" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Global Privacy Control', 'anchor-schema' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[advanced][honor_gpc]" value="1" <?php checked( $v['honor_gpc'] ); ?> />
						<?php esc_html_e( "Honor the browser's GPC signal as an opt-out request", 'anchor-schema' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Debug', 'anchor-schema' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[advanced][debug]" value="1" <?php checked( $v['debug'] ); ?> />
						<?php esc_html_e( 'Log verbose script-blocker activity to the browser console', 'anchor-schema' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}
}
