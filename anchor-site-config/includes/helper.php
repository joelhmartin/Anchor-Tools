<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'anchor_site_config' ) ) {
    /**
     * Read a value from the site config by dotted path.
     *
     * @param string|null $path  e.g. 'colors.primary', 'fonts.heading.family', 'social.instagram'.
     *                           null returns the full nested array.
     * @return mixed             string/int value at leaf, array at branch, '' for unknown leaf,
     *                           [] for unknown branch, full array for null path.
     */
    function anchor_site_config( $path = null ) {
        static $cache = null;
        if ( null === $cache ) {
            if ( class_exists( 'Anchor_Site_Config_Module' ) ) {
                $stored = get_option( Anchor_Site_Config_Module::OPTION_KEY, [] );
                $cache  = is_array( $stored )
                    ? array_replace_recursive( Anchor_Site_Config_Module::get_defaults(), $stored )
                    : Anchor_Site_Config_Module::get_defaults();
            } else {
                // Module disabled or not loaded — return defaults so theme code
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
            } else {
                // Differentiate leaf vs branch unknowns: if cursor is array and
                // segment is missing, it's an unknown branch.
                return is_array( $cursor ) ? [] : '';
            }
        }
        return $cursor;
    }
}
