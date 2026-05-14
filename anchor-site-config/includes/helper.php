<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'anchor_site_config' ) ) {
    /**
     * Read a value from the site config by dotted path.
     *
     * @param string|null $path  e.g. 'colors.primary', 'fonts.heading.family', 'social.instagram'.
     *                           null returns the full nested array.
     * @return mixed             The value at the path (string, int, or array for branches),
     *                           '' for any unknown path, full nested array for null path.
     */
    function anchor_site_config( $path = null ) {
        static $cache = null;
        if ( null === $cache ) {
            if ( class_exists( 'Anchor_Site_Config_Module' ) ) {
                // Reuse the module's layered resolution so the helper sees the same
                // defaults → legacy → stored chain as everything else.
                $module = new Anchor_Site_Config_Module();
                $cache  = $module->get_options();
            } else {
                // Module disabled or not loaded — return empty so theme code
                // doesn't fatal.
                $cache = [];
            }
        }

        if ( $path === null ) {
            return $cache;
        }

        $segments = explode( '.', (string) $path );
        $cursor   = $cache;
        foreach ( $segments as $seg ) {
            if ( is_array( $cursor ) && array_key_exists( $seg, $cursor ) ) {
                $cursor = $cursor[ $seg ];
                continue;
            }
            return '';
        }
        return $cursor;
    }
}
