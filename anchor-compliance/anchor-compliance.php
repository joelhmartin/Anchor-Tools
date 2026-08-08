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
	const VERSION    = '1.0.0';

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

		$this->consent_mode = new Anchor_Compliance_Consent_Mode( $this->state, $this->geo );
		$this->blocker      = new Anchor_Compliance_Script_Blocker( $this->registry, $this->state, $this->geo );
		$this->log          = new Anchor_Compliance_Consent_Log();
		$this->rest         = new Anchor_Compliance_Rest( $this->log, $this->geo );
		$this->banner       = new Anchor_Compliance_Banner( $this->state, $this->geo, $this->registry, $this->consent_mode );

		add_action( 'rest_api_init', [ $this->rest, 'register_routes' ] );
		add_action( 'admin_init', [ 'Anchor_Compliance_Consent_Log', 'maybe_install' ] );
		add_action( Anchor_Compliance_Consent_Log::CRON_HOOK, [ $this->log, 'purge' ] );

		if ( ! wp_next_scheduled( Anchor_Compliance_Consent_Log::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Anchor_Compliance_Consent_Log::CRON_HOOK );
		}

		add_shortcode( 'anchor_consent_link', [ $this->banner, 'shortcode_consent_link' ] );
		add_shortcode( 'anchor_do_not_sell', [ $this->banner, 'shortcode_do_not_sell' ] );

		if ( ! is_admin() ) {
			add_action( 'wp_head', [ $this->consent_mode, 'emit_defaults' ], 1 );
			add_action( 'template_redirect', [ $this->blocker, 'maybe_start_buffer' ], 1 );
			add_action( 'wp_enqueue_scripts', [ $this->banner, 'enqueue' ] );
			add_action( 'wp_footer', [ $this->banner, 'render' ], 5 );
		}
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
	}
}
