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
				'response_days'    => 45,
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
		$c = isset( $input['content'] ) ? (array) $input['content'] : [];
		foreach ( $d['content'] as $k => $default ) {
			$val = isset( $c[ $k ] ) ? (string) $c[ $k ] : '';
			$out['content'][ $k ] = '' === trim( $val ) ? $default : wp_kses_post( $val );
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
		$out['dsar']['enabled']       = ! empty( $s['enabled'] );
		$out['dsar']['notify_email']  = sanitize_email( (string) ( $s['notify_email'] ?? '' ) );
		$out['dsar']['response_days'] = min( 90, max( 1, (int) ( $s['response_days'] ?? 45 ) ) );

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

	/** Filled in by Task 13. */
	public function render_tab_content() {
		echo '<h2>' . esc_html__( 'Compliance', 'anchor-schema' ) . '</h2>';
	}
}
