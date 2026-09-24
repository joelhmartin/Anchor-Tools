<?php
/**
 * Plugin-level front-end primitives shared by several modules (one lightbox,
 * one carousel). Modules enqueue these by handle instead of shipping copies.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Shared_Assets {
	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register' ], 5 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'register' ], 5 );
	}

	public static function register() {
		foreach ( [ 'anchor-lightbox', 'anchor-carousel' ] as $handle ) {
			$js  = 'assets/shared/' . $handle . '.js';
			$css = 'assets/shared/' . $handle . '.css';
			if ( file_exists( ANCHOR_TOOLS_PLUGIN_DIR . $js ) ) {
				wp_register_script( $handle, Anchor_Asset_Loader::url( $js ), [], self::asset_version( $js ), true );
			}
			if ( file_exists( ANCHOR_TOOLS_PLUGIN_DIR . $css ) ) {
				wp_register_style( $handle, Anchor_Asset_Loader::url( $css ), [], self::asset_version( $css ) );
			}
		}
	}

	/**
	 * Cache-busting version for a shared asset. Versions by the mtime of
	 * whichever file (source or `.min`) Anchor_Asset_Loader actually resolves
	 * and serves, so a rebuilt `.min` sibling always changes the version even
	 * when the source file on disk didn't. Falls back to the plugin version
	 * if the resolved file can't be stat'd.
	 *
	 * @param string $relative Path under the plugin root.
	 * @return string
	 */
	private static function asset_version( $relative ) {
		$path = Anchor_Asset_Loader::path( $relative );
		$time = is_readable( $path ) ? filemtime( $path ) : false;
		return $time ? (string) $time : ( defined( 'ANCHOR_TOOLS_VERSION' ) ? ANCHOR_TOOLS_VERSION : '1' );
	}
}
Anchor_Shared_Assets::init();
