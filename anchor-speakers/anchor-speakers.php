<?php
/**
 * Anchor Tools module: Anchor Speakers.
 * Speaker/faculty CPT with a configurable URL base and optional Events linkage.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Speakers_Module {
	const CPT              = 'anchor_speaker';
	const OPTION           = 'anchor_speakers_options';
	const SIGNATURE_OPTION = 'anchor_speakers_rules_signature';

	public static function options() {
		$o = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $o ) ? $o : [], [ 'base' => 'speakers', 'archive' => false ] );
	}

	public static function base() {
		$b = trim( sanitize_title_with_dashes( (string) self::options()['base'] ), '/' );
		return $b !== '' ? $b : 'speakers';
	}

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'init', [ $this, 'maybe_flush' ], 99 );
		add_filter( 'request', [ $this, 'page_fallback' ] );
		add_action( 'admin_menu', [ $this, 'settings_menu' ] );
		add_action( 'admin_init', [ $this, 'settings_register' ] );
	}

	public function register() {
		register_post_type( self::CPT, [
			'labels'       => [
				'name'          => __( 'Speakers', 'anchor-schema' ),
				'singular_name' => __( 'Speaker', 'anchor-schema' ),
				'add_new_item'  => __( 'Add New Speaker', 'anchor-schema' ),
				'edit_item'     => __( 'Edit Speaker', 'anchor-schema' ),
			],
			'public'       => true,
			'show_in_menu' => apply_filters( 'anchor_speakers_parent_menu', true ),
			'menu_icon'    => 'dashicons-businessperson',
			'supports'     => [ 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions' ],
			'has_archive'  => (bool) self::options()['archive'],
			'rewrite'      => [ 'slug' => self::base(), 'with_front' => false ],
			'show_in_rest' => true,
		] );
	}

	/**
	 * When the configured base matches a real page's path, the CPT's rewrite
	 * rules sit above the page rules and would otherwise swallow requests for
	 * that page's own non-speaker children (base/child-slug). This checks
	 * whether the matched request is an actual speaker first; if not, and a
	 * page exists at that same path, it hands the request back to that page.
	 *
	 * Reads the raw matched path from $wp->request (set by WP before this
	 * filter runs) rather than the query vars alone: a request nested two
	 * segments below the base (base/child/grandchild) matches WordPress' own
	 * auto-generated "attachment under this post type" rewrite rule, which
	 * only exposes the last segment as `attachment`, discarding the middle
	 * one, so the full path is not otherwise recoverable here.
	 */
	public function page_fallback( $vars ) {
		global $wp;
		$base    = self::base();
		$request = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
		if ( $request === '' || strpos( $request, $base . '/' ) !== 0 ) return $vars;
		$path = substr( $request, strlen( $base ) + 1 );
		if ( $path === '' ) return $vars;

		if ( strpos( $path, '/' ) === false
			&& ! empty( $vars['post_type'] ) && $vars['post_type'] === self::CPT
			&& ! empty( $vars[ self::CPT ] )
			&& get_page_by_path( $path, OBJECT, self::CPT )
		) {
			return $vars;
		}

		$page = get_page_by_path( $base . '/' . $path );
		if ( ! $page ) return $vars;
		return [ 'pagename' => $base . '/' . $path ];
	}

	/**
	 * One-time rewrite flush keyed on a signature of the settings that shape
	 * the rewrite rules (base + archive flag), rather than a save-triggered
	 * flag: this also covers the module's first load (no stored signature
	 * yet) and any base change made outside the settings page (e.g. a
	 * direct update_option() call, as tests do), not just a settings-page
	 * save. A settings-page save still flushes here too, since the option
	 * is already updated by the time this runs on the next request.
	 */
	public function maybe_flush() {
		$signature = self::rules_signature();
		if ( get_option( self::SIGNATURE_OPTION ) === $signature ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::SIGNATURE_OPTION, $signature, false );
	}

	private static function rules_signature() {
		$o = self::options();
		return md5( self::base() . '|' . ( $o['archive'] ? '1' : '0' ) );
	}

	public function settings_menu() {
		add_options_page( __( 'Anchor Speakers', 'anchor-schema' ), __( 'Anchor Speakers', 'anchor-schema' ), 'manage_options', 'anchor-speakers', [ $this, 'settings_page' ] );
	}

	public function settings_register() {
		register_setting( 'anchor_speakers', self::OPTION, [ 'sanitize_callback' => [ $this, 'sanitize' ] ] );
	}

	/**
	 * No explicit flush flag here: maybe_flush() on the next 'init' compares
	 * a signature of the (already-updated) option against what it last
	 * flushed for, and flushes when they differ. That covers a settings-page
	 * save the same way it covers any other change to the option.
	 */
	public function sanitize( $in ) {
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( self::OPTION, false );
		}
		return [
			'base'    => sanitize_title_with_dashes( $in['base'] ?? 'speakers' ) ?: 'speakers',
			'archive' => ! empty( $in['archive'] ),
		];
	}

	public function settings_page() {
		$o = self::options();
		echo '<div class="wrap"><h1>' . esc_html__( 'Anchor Speakers', 'anchor-schema' ) . '</h1><form method="post" action="options.php">';
		settings_fields( 'anchor_speakers' );
		echo '<table class="form-table"><tr><th><label for="as-base">' . esc_html__( 'URL base', 'anchor-schema' ) . '</label></th><td><code>' . esc_html( home_url( '/' ) ) . '</code><input id="as-base" name="' . esc_attr( self::OPTION ) . '[base]" value="' . esc_attr( $o['base'] ) . '" class="regular-text"><code>/speaker-name/</code><p class="description">' . esc_html__( 'May match an existing page path (for example about-us); child pages that are not speakers keep working.', 'anchor-schema' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Archive page', 'anchor-schema' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[archive]" value="1" ' . checked( $o['archive'], true, false ) . '> ' . esc_html__( 'Enable an archive at the base URL', 'anchor-schema' ) . '</label></td></tr></table>';
		submit_button();
		echo '</form></div>';
	}
}
