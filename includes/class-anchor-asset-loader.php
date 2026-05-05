<?php
/**
 * Resolves plugin asset URLs/paths to minified versions when available.
 *
 * Source files (e.g. anchor-social-feed/assets/anchor-social-feed.css) are
 * authored in the repo. The release workflow generates *.min.css / *.min.js
 * siblings inside the release ZIP. This loader prefers the minified sibling
 * at runtime unless SCRIPT_DEBUG is on or the minified file isn't present
 * (e.g. when running from a fresh `git clone` without a build).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anchor_Asset_Loader {

    /**
     * Resolve a plugin-relative asset path to its public URL.
     *
     * @param string $relative Path relative to the plugin root, e.g. "anchor-social-feed/assets/anchor-social-feed.css".
     */
    public static function url( $relative ) {
        return ANCHOR_TOOLS_PLUGIN_URL . self::resolve( $relative );
    }

    /**
     * Resolve a plugin-relative asset path to its absolute filesystem path
     * (suitable for filemtime() cache-busting).
     */
    public static function path( $relative ) {
        return ANCHOR_TOOLS_PLUGIN_DIR . self::resolve( $relative );
    }

    private static function resolve( $relative ) {
        $relative = ltrim( $relative, '/' );

        if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
            return $relative;
        }

        $min = self::minified_variant( $relative );
        if ( $min !== null && file_exists( ANCHOR_TOOLS_PLUGIN_DIR . $min ) ) {
            return $min;
        }

        return $relative;
    }

    private static function minified_variant( $relative ) {
        if ( preg_match( '/\.min\.(css|js)$/i', $relative ) ) {
            return null;
        }
        if ( preg_match( '/\.(css|js)$/i', $relative, $m ) ) {
            return preg_replace( '/\.(css|js)$/i', '.min.' . strtolower( $m[1] ), $relative );
        }
        return null;
    }
}
