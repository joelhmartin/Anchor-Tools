<?php
/**
 * Shared GrapesJS loader — the visual builder behind the event email designer.
 *
 * Mirrors Anchor_Monaco: the library is pinned on jsDelivr rather than vendored,
 * and a thin local script does the wiring. Modules call
 * Anchor_Grapes::enqueue() and mount a container.
 *
 * GrapesJS is BSD-3-Clause; grapesjs-preset-newsletter, which supplies the
 * email-safe blocks and the table-based export, is BSD-3-Clause too. Neither
 * needs an account or a hosted service.
 *
 * A note on versions: the newsletter preset was last published in 2023 and
 * declares no peer dependency, so its pairing with a current GrapesJS is not
 * guaranteed by the package metadata. It was tested against the pinned version
 * below — the preset registers, its blocks load, arbitrary table HTML imports,
 * and {token} placeholders survive the round trip. Re-test that before moving
 * either pin.
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Grapes {

	const VERSION     = '1.0.0';
	const GRAPES_VER  = '0.23.6';
	const PRESET_VER  = '1.0.2';

	const GRAPES_JS   = 'https://cdn.jsdelivr.net/npm/grapesjs@0.23.6/dist/grapes.min.js';
	const GRAPES_CSS  = 'https://cdn.jsdelivr.net/npm/grapesjs@0.23.6/dist/css/grapes.min.css';
	const PRESET_JS   = 'https://cdn.jsdelivr.net/npm/grapesjs-preset-newsletter@1.0.2/dist/index.js';

	/** Register the library. Enqueued on demand by whatever mounts an editor. */
	public static function enqueue() {
		wp_enqueue_media();

		wp_enqueue_style( 'anchor-grapes-lib', self::GRAPES_CSS, array(), self::GRAPES_VER );
		wp_enqueue_script( 'anchor-grapes-lib', self::GRAPES_JS, array(), self::GRAPES_VER, true );
		wp_enqueue_script( 'anchor-grapes-newsletter', self::PRESET_JS, array( 'anchor-grapes-lib' ), self::PRESET_VER, true );
	}
}
