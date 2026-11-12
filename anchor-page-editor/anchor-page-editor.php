<?php
/**
 * Anchor Tools module: Anchor Page Editor.
 *
 * The raw-HTML page editor from the Anchor Framework theme, as a plugin module so any theme can use it:
 *  - Monaco editor (HTML / CSS / JS tabs, Format Code, Insert from Media Library) replaces the
 *    block/classic editor for the managed post types.
 *  - Per-page CSS (printed in <head>) and JS (printed in the footer).
 *  - The AI Assistant panel + its settings page and REST endpoint.
 *  - Raw output: pages render their HTML verbatim (shortcodes run; no wpautop/wptexturize).
 *
 * It decides nothing about how pages are laid out: the theme still owns the header, footer,
 * templates and CSS. Themes print the body with the_content() (this module turns off the
 * auto-formatting for managed posts) or with anchor_page_content().
 *
 * Data is identical to Anchor Framework's (post_content, _ac_custom_css, _ac_custom_js, the
 * ac_ai_settings option, the same nonce), so switching between the two needs no migration. When
 * this module is enabled on a site that runs Anchor Framework, it unhooks the framework's copy so
 * the editor never loads twice. When the module is disabled, nothing here runs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ai-tools.php';
require_once __DIR__ . '/includes/ai-providers.php';

class Anchor_Page_Editor_Module {

	const VERSION       = '1.0.0';
	const MONACO_VER    = '0.52.2';
	const NONCE_ACTION  = 'ac_editor_save';   // Same as Anchor Framework: an editor screen rendered by either saves through either.
	const NONCE_FIELD   = 'ac_editor_nonce';
	const META_CSS      = '_ac_custom_css';
	const META_JS       = '_ac_custom_js';
	const AI_OPTION     = 'ac_ai_settings';
	const AI_PAGE       = 'anchor-page-editor-ai';
	const REST_NS       = 'anchor-tools/v1';
	const REST_ROUTE    = '/page-editor/ai';

	/** Filters removed for the current raw render, restored afterwards. */
	private $suspended = array();

	public function __construct() {
		// Anchor Framework registers the same features from the theme; theme code loads after plugins,
		// so take them over once the theme is loaded.
		add_action( 'after_setup_theme', array( $this, 'take_over_from_framework' ), 99 );

		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 10, 2 );
		add_action( 'init', array( $this, 'remove_default_editor' ), 100 );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'edit_form_after_title', array( $this, 'render_editor' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'save_post', array( $this, 'save_page_assets' ) );

		add_action( 'wp_head', array( $this, 'print_page_css' ), 99 );
		add_action( 'wp_footer', array( $this, 'print_page_js' ), 99 );

		add_filter( 'the_content', array( $this, 'raw_content_begin' ), -1000 );
		add_filter( 'the_content', array( $this, 'raw_content_end' ), PHP_INT_MAX );
		add_filter( 'run_wptexturize', array( $this, 'block_theme_no_texturize' ) );

		add_action( 'admin_menu', array( $this, 'ai_settings_menu' ) );
		add_action( 'admin_init', array( $this, 'ai_settings_register' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/* ------------------------------------------------------------------ setup */

	/**
	 * Post types edited as raw HTML. Honours Anchor Framework's filter so child themes that add their
	 * CPTs there keep working.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = apply_filters( 'ac_monaco_post_types', array( 'page', 'post' ) );
		return (array) apply_filters( 'anchor_page_editor_post_types', $types );
	}

	/**
	 * Unhook Anchor Framework's editor, page assets and AI features (identical data, so nothing is lost).
	 * Its ac_render_raw_content() template tag stays: framework templates keep rendering pages with it.
	 */
	public function take_over_from_framework() {
		$hooks = array(
			array( 'use_block_editor_for_post_type', 'ac_disable_block_editor', 10 ),
			array( 'init', 'ac_remove_default_editor', 100 ),
			array( 'edit_form_after_title', 'ac_render_monaco_editor', 10 ),
			array( 'admin_enqueue_scripts', 'ac_monaco_admin_assets', 10 ),
			array( 'init', 'ac_register_page_assets_meta', 10 ),
			array( 'save_post', 'ac_save_page_assets', 10 ),
			array( 'wp_head', 'ac_print_page_css', 99 ),
			array( 'wp_footer', 'ac_print_page_js', 99 ),
			array( 'admin_menu', 'ac_ai_settings_menu', 10 ),
			array( 'admin_init', 'ac_ai_settings_register', 10 ),
			array( 'rest_api_init', 'ac_ai_register_route', 10 ),
		);
		foreach ( $hooks as list( $hook, $callback, $priority ) ) {
			if ( function_exists( $callback ) ) {
				remove_filter( $hook, $callback, $priority );
			}
		}
	}

	public function disable_block_editor( $use_block_editor, $post_type ) {
		return in_array( $post_type, self::post_types(), true ) ? false : $use_block_editor;
	}

	public function remove_default_editor() {
		foreach ( self::post_types() as $pt ) {
			remove_post_type_support( $pt, 'editor' );
		}
	}

	public function register_meta() {
		foreach ( self::post_types() as $pt ) {
			foreach ( array( self::META_CSS, self::META_JS ) as $key ) {
				register_post_meta(
					$pt,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'default'           => '',
						'show_in_rest'      => false,
						'sanitize_callback' => array( __CLASS__, 'sanitize_page_asset' ),
						'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', $post_id );
						},
					)
				);
			}
		}
	}

	/**
	 * Keep raw code, but neutralize closing-tag breakouts from the <style>/<script> wrapper.
	 */
	public static function sanitize_page_asset( $value ) {
		return preg_replace( '#</\s*(style|script)#i', '<\\/$1', (string) $value );
	}

	/* ------------------------------------------------------------------ admin editor */

	public function render_editor( $post ) {
		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$css    = get_post_meta( $post->ID, self::META_CSS, true );
		$js     = get_post_meta( $post->ID, self::META_JS, true );
		$ai     = get_option( self::AI_OPTION, array() );
		$active = isset( $ai['active'] ) ? $ai['active'] : '';
		include __DIR__ . '/includes/editor-screen.php';
	}

	public function admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::post_types(), true ) ) {
			return;
		}
		$base   = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-page-editor/assets/';
		$monaco = 'https://cdn.jsdelivr.net/npm/monaco-editor@' . self::MONACO_VER . '/min';

		wp_enqueue_media();
		wp_enqueue_style( 'anchor-page-editor', $base . 'editor.css', array(), self::VERSION );
		wp_enqueue_script( 'anchor-page-editor-monaco', $monaco . '/vs/loader.js', array(), self::MONACO_VER, true );
		wp_enqueue_script( 'anchor-page-editor', $base . 'editor.js', array( 'anchor-page-editor-monaco', 'jquery' ), self::VERSION, true );

		$ai = get_option( self::AI_OPTION, array() );
		wp_localize_script(
			'anchor-page-editor',
			'ACEditor', // Same global as Anchor Framework's editor script, which this file is a copy of.
			array(
				'monacoBase' => $monaco,
				'mediaTitle' => __( 'Select or upload media', 'anchor-schema' ),
				'mediaBtn'   => __( 'Use this URL', 'anchor-schema' ),
				'aiEnabled'  => ! empty( $ai['active'] ),
				'restUrl'    => esc_url_raw( rest_url( self::REST_NS . self::REST_ROUTE ) ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'postId'     => isset( $GLOBALS['post'] ) && $GLOBALS['post'] ? (int) $GLOBALS['post']->ID : 0,
				'i18n'       => array(
					'thinking'  => __( 'Thinking…', 'anchor-schema' ),
					'accept'    => __( 'Accept', 'anchor-schema' ),
					'reject'    => __( 'Reject', 'anchor-schema' ),
					'acceptAll' => __( 'Accept all', 'anchor-schema' ),
					'rejectAll' => __( 'Reject all', 'anchor-schema' ),
					'error'     => __( 'AI request failed.', 'anchor-schema' ),
					'noChange'  => __( 'The AI did not propose any changes.', 'anchor-schema' ),
				),
			)
		);
	}

	/**
	 * Save per-page CSS/JS. Nonce + capability verified.
	 */
	public function save_page_assets( $post_id ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$map = array(
			'ac_custom_css' => self::META_CSS,
			'ac_custom_js'  => self::META_JS,
		);
		foreach ( $map as $field => $meta_key ) {
			if ( isset( $_POST[ $field ] ) ) {
				// wp_unslash only (sanitize_text_field would strip the code); the meta sanitize_callback
				// runs the breakout guard. wp_slash() because update_post_meta() unslashes again — without
				// it every backslash in the code was stripped on each save (TMJ 2026-10-10).
				update_post_meta( $post_id, $meta_key, wp_slash( wp_unslash( $_POST[ $field ] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}
	}

	/* ------------------------------------------------------------------ front end */

	public function print_page_css() {
		if ( ! is_singular( self::post_types() ) ) {
			return;
		}
		$css = get_post_meta( get_queried_object_id(), self::META_CSS, true );
		if ( '' === trim( (string) $css ) ) {
			return;
		}
		echo "\n<style id=\"ac-page-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-authored, breakout-guarded on save.
	}

	public function print_page_js() {
		if ( ! is_singular( self::post_types() ) ) {
			return;
		}
		$js = get_post_meta( get_queried_object_id(), self::META_JS, true );
		if ( '' === trim( (string) $js ) ) {
			return;
		}
		echo "\n<script id=\"ac-page-js\">\n" . $js . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-authored, breakout-guarded on save.
	}

	/**
	 * Raw output through the_content(): for managed post types, suspend core's auto-formatting
	 * (wpautop, wptexturize, convert_smilies, shortcode_unautop, capital_P_dangit, do_blocks) for this
	 * one call. Shortcodes and other plugins' filters still run. Skipped on Anchor Framework, whose
	 * templates render with ac_render_raw_content() and never call the_content() for page bodies.
	 * Turn off per site with add_filter( 'anchor_page_editor_raw_output', '__return_false' ).
	 */
	public function raw_content_begin( $content ) {
		$this->suspended = array();
		if ( function_exists( 'ac_render_raw_content' ) || ! apply_filters( 'anchor_page_editor_raw_output', true ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return $content;
		}
		foreach ( array( 'do_blocks', 'wptexturize', 'wpautop', 'shortcode_unautop', 'convert_smilies', 'capital_P_dangit' ) as $fn ) {
			$priority = has_filter( 'the_content', $fn );
			if ( false !== $priority ) {
				remove_filter( 'the_content', $fn, $priority );
				$this->suspended[ $fn ] = $priority;
			}
		}
		return $content;
	}

	/**
	 * Block themes texturize the whole rendered template, outside the_content(). On a managed page,
	 * switch texturizing off for that request so the page body stays verbatim there too.
	 */
	public function block_theme_no_texturize( $run ) {
		if ( ! $run || ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! apply_filters( 'anchor_page_editor_raw_output', true ) ) {
			return $run;
		}
		return is_singular( self::post_types() ) ? false : $run;
	}

	public function raw_content_end( $content ) {
		if ( $this->suspended ) {
			foreach ( $this->suspended as $fn => $priority ) {
				add_filter( 'the_content', $fn, $priority );
			}
			$this->suspended = array();
			$content         = apply_filters( 'anchor_page_editor_content', $content, get_the_ID() );
		}
		return $content;
	}

	/* ------------------------------------------------------------------ AI assistant */

	public function ai_settings_menu() {
		add_options_page(
			__( 'AI Page Assistant', 'anchor-schema' ),
			__( 'AI Page Assistant', 'anchor-schema' ),
			'manage_options',
			self::AI_PAGE,
			array( $this, 'ai_settings_render' )
		);
	}

	public static function ai_settings_url() {
		return admin_url( 'options-general.php?page=' . self::AI_PAGE );
	}

	public static function ai_providers() {
		return array(
			'anthropic' => array( 'Anthropic (Claude)', 'claude-sonnet-4-6' ),
			'openai'    => array( 'OpenAI (GPT)', 'gpt-4o' ),
			'gemini'    => array( 'Google (Gemini)', 'gemini-2.0-flash' ),
		);
	}

	public function ai_settings_register() {
		register_setting(
			'anchor_page_editor_ai_group',
			self::AI_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'ai_settings_sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Empty key fields keep the stored key.
	 */
	public function ai_settings_sanitize( $input ) {
		$existing  = get_option( self::AI_OPTION, array() );
		$providers = self::ai_providers();
		$active    = isset( $input['active'] ) ? sanitize_text_field( $input['active'] ) : '';
		$clean     = array( 'active' => array_key_exists( $active, $providers ) ? $active : '' );
		foreach ( $providers as $key => $def ) {
			$model   = isset( $input[ $key ]['model'] ) ? sanitize_text_field( $input[ $key ]['model'] ) : $def[1];
			$new_key = isset( $input[ $key ]['api_key'] ) ? trim( (string) $input[ $key ]['api_key'] ) : '';
			if ( '' === $new_key && ! empty( $existing[ $key ]['api_key'] ) ) {
				$new_key = $existing[ $key ]['api_key'];
			}
			$clean[ $key ] = array(
				'api_key' => $new_key,
				'model'   => '' !== $model ? $model : $def[1],
			);
		}
		return $clean;
	}

	public function ai_settings_render() {
		$saved     = get_option( self::AI_OPTION, array() );
		$providers = self::ai_providers();
		$active    = isset( $saved['active'] ) ? $saved['active'] : '';
		include __DIR__ . '/includes/ai-settings-screen.php';
	}

	public function register_rest_route() {
		register_rest_route(
			self::REST_NS,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ai_handle_request' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_pages' );
				},
				'args'                => array(
					'post_id' => array( 'type' => 'integer', 'required' => false ),
					'prompt'  => array( 'type' => 'string', 'required' => true ),
					'html'    => array( 'type' => 'string', 'required' => false ),
					'css'     => array( 'type' => 'string', 'required' => false ),
					'js'      => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
	}

	private static function ai_system_prompt() {
		return implode(
			"\n",
			array(
				'You are an expert front-end developer embedded in a WordPress site.',
				'You edit ONE page at a time. The page consists of three parts the user can change:',
				'- HTML: the page body markup, rendered between the theme\'s shared header and footer.',
				'- CSS: custom CSS for this page only (injected in <style> in the head).',
				'- JS: custom JavaScript for this page only (injected in <script> in the footer).',
				'The theme may define CSS variables (for example --anchor-color-primary, --primary-color, --accent-color). Prefer the theme\'s variables over hard-coded colors.',
				'You may call read_theme_file to inspect theme files (e.g. style.css, header.php) before editing. Only read what you need.',
				'When you are finished, call submit_edits with ONLY the fields you changed (html, css, and/or js) plus a short explanation.',
				'Return complete file contents for any field you include (not a diff/patch). Keep changes minimal and consistent with the existing code style.',
			)
		);
	}

	/**
	 * Run the provider's tool loop (max 6 steps) and return the proposed edits.
	 */
	public function ai_handle_request( $req ) {
		$provider = anchor_pe_ai_make_provider();
		if ( ! $provider ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => __( 'No AI provider is configured.', 'anchor-schema' ) ), 200 );
		}
		$tools    = anchor_pe_ai_tools();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => "Here is the current page.\n\n=== HTML ===\n" . (string) $req->get_param( 'html' )
					. "\n\n=== CSS ===\n" . (string) $req->get_param( 'css' )
					. "\n\n=== JS ===\n" . (string) $req->get_param( 'js' )
					. "\n\n=== REQUEST ===\n" . (string) $req->get_param( 'prompt' ),
			),
		);
		$trace = array();

		for ( $i = 0; $i < 6; $i++ ) {
			$resp = $provider->chat( self::ai_system_prompt(), $messages, $tools );
			if ( 'error' === $resp['type'] ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => $resp['error'], 'trace' => $trace ), 200 );
			}
			if ( 'text' === $resp['type'] ) {
				return new WP_REST_Response( array( 'ok' => true, 'edits' => new stdClass(), 'explanation' => $resp['text'], 'trace' => $trace ), 200 );
			}
			$messages[] = array( 'role' => 'assistant', 'content' => '', 'tool_calls' => $resp['raw_assistant'], 'raw_assistant' => $resp['raw_assistant'] );
			$submit     = null;
			foreach ( $resp['calls'] as $call ) {
				$name    = $call['name'];
				$args    = $call['args'];
				$trace[] = array( 'tool' => $name, 'args' => ( 'read_theme_file' === $name ? $args : array_keys( $args ) ) );
				if ( 'submit_edits' === $name ) {
					$submit     = $args;
					$messages[] = array( 'role' => 'tool', 'tool_call_id' => $call['id'], 'name' => $name, 'content' => 'OK' );
					continue;
				}
				$result     = ( isset( $tools[ $name ]['callback'] ) && is_callable( $tools[ $name ]['callback'] ) )
					? call_user_func( $tools[ $name ]['callback'], $args )
					: 'ERROR: unknown tool ' . $name;
				$messages[] = array( 'role' => 'tool', 'tool_call_id' => $call['id'], 'name' => $name, 'content' => (string) $result );
			}
			if ( null !== $submit ) {
				$edits = array();
				foreach ( array( 'html', 'css', 'js' ) as $k ) {
					if ( isset( $submit[ $k ] ) && is_string( $submit[ $k ] ) ) {
						$edits[ $k ] = $submit[ $k ];
					}
				}
				return new WP_REST_Response(
					array(
						'ok'          => true,
						'edits'       => $edits ? $edits : new stdClass(),
						'explanation' => isset( $submit['explanation'] ) ? (string) $submit['explanation'] : '',
						'trace'       => $trace,
					),
					200
				);
			}
		}
		return new WP_REST_Response( array( 'ok' => false, 'error' => __( 'The AI did not finish within the step limit. Try a simpler request.', 'anchor-schema' ), 'trace' => $trace ), 200 );
	}
}
