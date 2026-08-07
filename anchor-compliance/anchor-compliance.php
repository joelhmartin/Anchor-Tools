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
	}

	private function load_includes() {
		$dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-compliance/includes/';
		require_once $dir . 'class-settings.php';
		require_once $dir . 'class-consent-state.php';
		require_once $dir . 'class-geo.php';
	}
}
