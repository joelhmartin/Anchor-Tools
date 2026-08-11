<?php
/**
 * Anchor Tools — Compliance Module.
 *
 * Geo-aware cookie consent: banner + preference center, three-layer script
 * blocking, Google Consent Mode v2, consent records, cookie policy, and
 * privacy (DSAR) requests.
 *
 * @package Anchor\Compliance
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Module {

	const OPTION_KEY = 'anchor_compliance_options';

	/**
	 * Module version. Front-end enqueues cache-bust via the asset file's
	 * mtime (see Anchor_Compliance_Banner::asset_version()); this constant is
	 * only the fallback when a file can't be stat'd — but bump it anyway when
	 * assets change, so the fallback path still busts caches.
	 */
	const VERSION = '1.0.1';

	/** @var self|null */
	private static $instance = null;

	/** @var Anchor_Compliance_Settings */
	public $settings;

	/** @var Anchor_Compliance_Consent_State */
	public $state;

	/** @var Anchor_Compliance_Geo */
	public $geo;

	/** @var Anchor_Compliance_Service_Registry */
	public $registry;

	/** @var Anchor_Compliance_Consent_Mode */
	public $consent_mode;

	/** @var Anchor_Compliance_Script_Blocker */
	public $blocker;

	/** @var Anchor_Compliance_Consent_Log */
	public $log;

	/** @var Anchor_Compliance_Rest */
	public $rest;

	/** @var Anchor_Compliance_Banner */
	public $banner;

	/** @var Anchor_Compliance_Snippets_Bridge */
	public $snippets;

	/** @var Anchor_Compliance_Cookie_Policy */
	public $cookie_policy;

	/** @var Anchor_Compliance_Dsar */
	public $dsar;

	/**
	 * The booted module instance. Constructing a second module would duplicate
	 * every hook (the banner would render twice), so collaborators must reach
	 * the module through here rather than instantiating it. This is a passive
	 * getter — anchor-tools.php always constructs the module directly via
	 * `new $module['class']()`, so instance() must never construct one itself
	 * or an early call could silently build a second, orphaned module.
	 */
	public static function instance() {
		return self::$instance;
	}

	public function __construct() {
		$this->load_includes();

		self::$instance = $this;

		$this->settings = new Anchor_Compliance_Settings();
		$this->state    = new Anchor_Compliance_Consent_State();
		$this->geo      = new Anchor_Compliance_Geo();
		$this->registry = new Anchor_Compliance_Service_Registry();

		$this->consent_mode  = new Anchor_Compliance_Consent_Mode( $this->state, $this->geo );
		$this->blocker       = new Anchor_Compliance_Script_Blocker( $this->registry, $this->state, $this->geo );
		$this->log           = new Anchor_Compliance_Consent_Log();
		$this->rest          = new Anchor_Compliance_Rest( $this->log, $this->geo );
		$this->banner        = new Anchor_Compliance_Banner( $this->state, $this->geo, $this->registry, $this->consent_mode );
		$this->snippets      = new Anchor_Compliance_Snippets_Bridge( $this->state, $this->geo );
		$this->cookie_policy = new Anchor_Compliance_Cookie_Policy();
		$this->dsar          = new Anchor_Compliance_Dsar();

		add_action( 'rest_api_init', [ $this->rest, 'register_routes' ] );
		add_action( 'admin_init', [ 'Anchor_Compliance_Consent_Log', 'maybe_install' ] );
		add_action( 'admin_init', [ 'Anchor_Compliance_Dsar', 'maybe_install' ] );
		add_action( Anchor_Compliance_Consent_Log::CRON_HOOK, [ $this->log, 'purge' ] );
		add_action( Anchor_Compliance_Consent_Log::CRON_HOOK, [ 'Anchor_Compliance_Dsar', 'purge_expired' ] );

		add_action( 'admin_post_nopriv_anchor_compliance_dsar_verify', [ $this->dsar, 'handle_verify' ] );
		add_action( 'admin_post_anchor_compliance_dsar_verify', [ $this->dsar, 'handle_verify' ] );

		add_action( 'add_meta_boxes', [ $this->snippets, 'add_metabox' ] );
		add_action( 'save_post', [ $this->snippets, 'save' ] );
		add_action( 'admin_notices', [ $this->snippets, 'admin_notices' ] );
		add_filter( 'anchor_code_snippet_output', [ $this->snippets, 'filter_snippet_output' ], 10, 2 );

		if ( ! wp_next_scheduled( Anchor_Compliance_Consent_Log::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Anchor_Compliance_Consent_Log::CRON_HOOK );
		}

		// The scheduling above only runs while the module is loaded, so
		// nothing inside the module can unschedule after it is turned off —
		// the request that turns it OFF is therefore the last chance. The
		// module toggle lives in anchor_schema_settings (Anchor_Schema_Admin),
		// and on the request that saves it the module is still loaded (the
		// bootstrap read the OLD value at plugins_loaded), so this hook fires
		// with both values in hand. Plugin-wide deactivation is covered by
		// the deactivation hook, and uninstall by uninstall.php.
		add_action( 'update_option_' . Anchor_Schema_Admin::OPTION_KEY, [ __CLASS__, 'maybe_unschedule_on_disable' ], 10, 2 );
		register_deactivation_hook( ANCHOR_TOOLS_PLUGIN_FILE, [ __CLASS__, 'clear_scheduled_events' ] );

		// The module's own consent cookie belongs in its own disclosure
		// tables (preference center + [anchor_cookie_policy]) like any other
		// cookie it knows about.
		add_filter( 'anchor_compliance_services', [ __CLASS__, 'register_self_disclosure' ] );

		add_shortcode( 'anchor_consent_link', [ $this->banner, 'shortcode_consent_link' ] );
		add_shortcode( 'anchor_do_not_sell', [ $this->banner, 'shortcode_do_not_sell' ] );
		add_shortcode( 'anchor_cookie_policy', [ $this->cookie_policy, 'render' ] );
		add_shortcode( 'anchor_privacy_request', [ $this->dsar, 'shortcode' ] );

		add_action( 'admin_post_nopriv_anchor_compliance_dsar', [ $this->dsar, 'handle_submit' ] );
		add_action( 'admin_post_anchor_compliance_dsar', [ $this->dsar, 'handle_submit' ] );
		add_action( 'admin_menu', [ $this->dsar, 'register_menu' ] );

		if ( ! is_admin() ) {
			// Consent-variant responses must not be replayed by a shared page
			// cache; send_headers runs before any output.
			add_action( 'send_headers', [ $this->banner, 'maybe_send_no_cache_headers' ] );

			add_action( 'wp_head', [ $this->consent_mode, 'emit_defaults' ], 1 );

			// Priority 0, deliberately BEFORE anchor-optimize's rewriter
			// (template_redirect priority 1): with nested output buffers the
			// later-started (inner) buffer flushes first, so starting ours
			// earlier makes it the OUTER buffer — its callback then sees the
			// final HTML including optimize's <picture>/img rewrites, and any
			// tracking markup optimize touches still gets consent-gated.
			// Previously both hooked priority 1 and the nesting depended on
			// module-registry registration order. anchor-translate also hooks
			// priority 0 but starts no buffer, so ordering against it is moot.
			add_action( 'template_redirect', [ $this->blocker, 'maybe_start_buffer' ], 0 );

			add_action( 'wp_enqueue_scripts', [ $this->banner, 'enqueue' ] );
			add_action( 'wp_footer', [ $this->banner, 'render' ], 5 );
		}
	}

	/**
	 * Every cron hook this module schedules. uninstall.php mirrors this list
	 * with literal strings (no plugin code loads there) — keep the two in
	 * sync. The DSAR purge cron, when it lands, gets appended here and its
	 * callback wired in __construct() next to the consent-log one.
	 *
	 * @return string[]
	 */
	private static function cron_hooks() {
		return [
			Anchor_Compliance_Consent_Log::CRON_HOOK,
		];
	}

	/** Unschedules every cron event this module owns. */
	public static function clear_scheduled_events() {
		foreach ( self::cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * update_option_{anchor_schema_settings} listener: when this save turns
	 * the compliance module off, its cron events would otherwise be orphaned
	 * forever (the purge callback is only registered while the module is
	 * loaded, so the retention promise would silently stop being kept).
	 *
	 * @param mixed $old_value Previous anchor_schema_settings value.
	 * @param mixed $value     New anchor_schema_settings value.
	 */
	public static function maybe_unschedule_on_disable( $old_value, $value ) {
		$was_on = ! empty( $old_value['modules']['compliance'] );
		$is_on  = ! empty( $value['modules']['compliance'] );

		if ( $was_on && ! $is_on ) {
			self::clear_scheduled_events();
		}
	}

	/**
	 * anchor_compliance_services filter: discloses the module's own consent
	 * cookie in the same tables that disclose every third-party cookie. The
	 * duration mirrors the configured consent lifetime. Necessary category,
	 * no patterns — it is never script-gated and never swept on withdrawal.
	 *
	 * @param array $services Registry services.
	 * @return array
	 */
	public static function register_self_disclosure( $services ) {
		$days = (int) Anchor_Compliance_Settings::get()['general']['consent_lifetime_days'];

		$services['anchor_compliance'] = [
			'name'     => __( 'Anchor Compliance (this website)', 'anchor-schema' ),
			'provider' => __( 'This website', 'anchor-schema' ),
			'category' => 'necessary',
			'patterns' => [],
			'cookies'  => [
				[
					'name'     => Anchor_Compliance_Consent_State::COOKIE,
					'purpose'  => __( 'Stores your cookie consent choices so you are not asked again.', 'anchor-schema' ),
					'duration' => sprintf(
						/* translators: %d: number of days the consent cookie persists. */
						_n( '%d day', '%d days', $days, 'anchor-schema' ),
						$days
					),
				],
			],
		];

		return $services;
	}

	private function load_includes() {
		$dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-compliance/includes/';
		require_once $dir . 'class-settings.php';
		require_once $dir . 'class-consent-state.php';
		require_once $dir . 'class-geo.php';
		require_once $dir . 'class-service-registry.php';
		require_once $dir . 'class-consent-mode.php';
		require_once $dir . 'class-script-blocker.php';
		require_once $dir . 'class-consent-log.php';
		require_once $dir . 'class-rest.php';
		require_once $dir . 'class-banner.php';
		require_once $dir . 'class-snippets-bridge.php';
		require_once $dir . 'class-cookie-policy.php';
		require_once $dir . 'class-dsar.php';
	}
}
