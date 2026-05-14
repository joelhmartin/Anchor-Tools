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
     * Read the stored option, merged over defaults, with legacy
     * anchor-shortcodes data layered in as a fallback.
     *
     * Layer order (later wins): defaults → legacy → stored.
     * Always returns a fully-populated array.
     */
    public function get_options() {
        $defaults = self::get_defaults();
        $legacy   = self::map_legacy_to_site_config();
        $stored   = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        // Start from defaults, layer in legacy mappings, then stored values.
        $out = $defaults;
        foreach ( [ $legacy, $stored ] as $layer ) {
            foreach ( $layer as $group => $values ) {
                if ( ! isset( $defaults[ $group ] ) ) continue;
                if ( $group === 'custom_shortcodes' ) {
                    $out[ $group ] = is_array( $values ) ? $values : [];
                    continue;
                }
                if ( is_array( $values ) ) {
                    $out[ $group ] = array_merge( $out[ $group ], $values );
                }
            }
        }
        return $out;
    }

    /**
     * Map legacy anchor-shortcodes (and cgsl_options legacy-of-legacy) data
     * onto this module's nested-array schema. Best-effort, only for fields
     * that map cleanly. Schema mismatches (free-text address/hours, URL→ID
     * for brand assets) are handled in shortcode callbacks rather than via
     * this map.
     *
     * @return array Partial site-config-shaped array (only filled groups present).
     */
    public static function map_legacy_to_site_config() {
        $legacy = get_option( 'anchor_shortcodes_options', null );
        if ( empty( $legacy ) ) {
            $legacy = get_option( 'cgsl_options', [] );
        }
        if ( ! is_array( $legacy ) || empty( $legacy ) ) {
            return [];
        }

        $out = [];

        // Business identity: legacy uses either business_phone or phone, etc.
        $business = [];
        if ( ! empty( $legacy['business_name'] ) ) {
            $business['name'] = (string) $legacy['business_name'];
        }
        foreach ( [ 'business_phone' => 'phone', 'business_email' => 'email' ] as $canonical => $alias ) {
            $sc_key = str_replace( 'business_', '', $canonical );
            if ( ! empty( $legacy[ $canonical ] ) ) {
                $business[ $sc_key ] = (string) $legacy[ $canonical ];
            } elseif ( ! empty( $legacy[ $alias ] ) ) {
                $business[ $sc_key ] = (string) $legacy[ $alias ];
            }
        }
        if ( $business ) {
            $out['business'] = $business;
        }

        // Brand assets: legacy stores URLs, site-config expects attachment IDs.
        // Convert via attachment_url_to_postid; ID 0 if not in the media library.
        $brand_map = [
            'site_image_url'              => 'primary_logo',
            'site_image_horizontal'       => 'primary_logo',
            'site_image_horizontal_white' => 'primary_logo_white',
            'site_image_white'            => 'primary_logo_white',
            'site_icon_url'               => 'favicon',
        ];
        $brand = [];
        foreach ( $brand_map as $legacy_key => $sc_key ) {
            if ( empty( $legacy[ $legacy_key ] ) || isset( $brand[ $sc_key ] ) ) continue;
            $id = function_exists( 'attachment_url_to_postid' )
                ? attachment_url_to_postid( $legacy[ $legacy_key ] )
                : 0;
            if ( $id ) {
                $brand[ $sc_key ] = $id;
            }
        }
        if ( $brand ) {
            $out['brand'] = $brand;
        }

        // Custom shortcodes — direct copy (same shape in both schemas).
        if ( ! empty( $legacy['custom_shortcodes'] ) && is_array( $legacy['custom_shortcodes'] ) ) {
            $out['custom_shortcodes'] = $legacy['custom_shortcodes'];
        }

        return $out;
    }
}
