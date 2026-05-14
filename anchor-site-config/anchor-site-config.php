<?php
/**
 * Anchor Tools — Site Config Module
 *
 * Owns site-identity data (brand colors, fonts, logos, business info,
 * hours, social links) plus a custom-shortcodes repeater. Replaces the
 * legacy anchor-shortcodes module — toggle that off once this is verified.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Site_Config_Module {

    const OPTION_KEY   = 'anchor_site_config_options';
    const OPTION_GROUP = 'anchor_site_config_group';

    private $admin;
    private $shortcodes;
    private $output;

    public function __construct() {
        require_once ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-site-config/includes/class-anchor-site-config-admin.php';
        require_once ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-site-config/includes/class-anchor-site-config-shortcodes.php';
        require_once ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-site-config/includes/class-anchor-site-config-output.php';
        require_once ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-site-config/includes/helper.php';

        $this->admin      = new Anchor_Site_Config_Admin( $this );
        $this->shortcodes = new Anchor_Site_Config_Shortcodes( $this );
        $this->output     = new Anchor_Site_Config_Output( $this );
    }

    /**
     * The default values for every field. Returned by reference so the
     * sanitizer can use it as a fallback target.
     */
    public static function get_defaults() {
        return [
            'colors' => [
                'primary'   => '#bf8f43',
                'secondary' => '#4a3a26',
                'accent'    => '#c4a875',
                'ink'       => '#1a1a1a',
                'ivory'     => '#fafafa',
                'muted'     => '#6b6b6b',
                'success'   => '#2e7d32',
                'warn'      => '#ed6c02',
                'error'     => '#c62828',
            ],
            'fonts' => [
                'heading' => [ 'family' => 'Inter',   'source' => 'google' ],
                'body'    => [ 'family' => 'Inter',   'source' => 'google' ],
                'accent'  => [ 'family' => 'Georgia', 'source' => 'system' ],
            ],
            'brand' => [
                'primary_logo'         => 0,
                'secondary_logo'       => 0,
                'primary_logo_white'   => 0,
                'secondary_logo_white' => 0,
                'favicon'              => 0,
                'og_image'             => 0,
            ],
            'business' => [
                'name'    => '',
                'tagline' => '',
                'phone'   => '',
                'email'   => '',
            ],
            'location' => [
                'line1'   => '',
                'line2'   => '',
                'city'    => '',
                'state'   => '',
                'postal'  => '',
                'country' => '',
            ],
            'hours' => [
                'monday'    => [ 'open' => '09:00', 'close' => '17:00', 'closed' => false ],
                'tuesday'   => [ 'open' => '09:00', 'close' => '17:00', 'closed' => false ],
                'wednesday' => [ 'open' => '09:00', 'close' => '17:00', 'closed' => false ],
                'thursday'  => [ 'open' => '09:00', 'close' => '17:00', 'closed' => false ],
                'friday'    => [ 'open' => '09:00', 'close' => '17:00', 'closed' => false ],
                'saturday'  => [ 'open' => '', 'close' => '', 'closed' => true ],
                'sunday'    => [ 'open' => '', 'close' => '', 'closed' => true ],
            ],
            'social' => [
                'facebook'  => '',
                'instagram' => '',
                'twitter'   => '',
                'linkedin'  => '',
                'youtube'   => '',
                'tiktok'    => '',
            ],
            'custom_shortcodes' => [],
        ];
    }

    /**
     * Read the stored option, merged over defaults. Always returns a
     * fully-populated array (no missing top-level keys).
     */
    public function get_options() {
        $stored   = get_option( self::OPTION_KEY, [] );
        $defaults = self::get_defaults();
        if ( ! is_array( $stored ) ) {
            return $defaults;
        }
        // Two-level deep merge so per-group partial saves don't wipe
        // sibling keys (e.g. saving only the Colors section).
        $out = $defaults;
        foreach ( $stored as $group => $values ) {
            if ( ! isset( $defaults[ $group ] ) ) {
                continue; // ignore unknown groups
            }
            if ( $group === 'custom_shortcodes' ) {
                // Repeater is a list, not a keyed array — replace whole.
                $out[ $group ] = is_array( $values ) ? $values : [];
                continue;
            }
            if ( is_array( $values ) ) {
                $out[ $group ] = array_merge( $defaults[ $group ], $values );
            }
        }
        return $out;
    }
}
