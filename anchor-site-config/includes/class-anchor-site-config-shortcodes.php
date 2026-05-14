<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Site_Config_Shortcodes {

    /** @var Anchor_Site_Config_Module */
    private $module;

    public function __construct( Anchor_Site_Config_Module $module ) {
        $this->module = $module;
        add_action( 'init', [ $this, 'register_shortcodes' ] );
    }

    public function register_shortcodes() {
        // Capture module for closures.
        $module = $this->module;

        // ─── Business identity ───
        $business_name = function() use ( $module ) {
            $opts = $module->get_options();
            $name = $opts['business']['name'] ?: get_bloginfo( 'name' );
            return esc_html( $name );
        };
        add_shortcode( 'business_name', $business_name );

        add_shortcode( 'business_tagline', function() use ( $module ) {
            return esc_html( $module->get_options()['business']['tagline'] );
        } );

        $business_phone = function() use ( $module ) {
            return esc_html( $module->get_options()['business']['phone'] );
        };
        add_shortcode( 'business_phone', $business_phone );
        add_shortcode( 'phone',          $business_phone ); // legacy alias

        $business_phone_href = function() use ( $module ) {
            $raw  = $module->get_options()['business']['phone'];
            $tel  = preg_replace( '/[^0-9+]/', '', $raw );
            return $tel ? esc_url( 'tel:' . $tel ) : '';
        };
        add_shortcode( 'business_phone_href', $business_phone_href );
        add_shortcode( 'phone_href',          $business_phone_href ); // legacy alias

        $business_email = function() use ( $module ) {
            $email = $module->get_options()['business']['email'] ?: get_option( 'admin_email' );
            return esc_html( $email );
        };
        add_shortcode( 'business_email', $business_email );
        add_shortcode( 'email',          $business_email ); // legacy alias

        $business_address = function() use ( $module ) {
            $loc   = $module->get_options()['location'];
            $parts = array_filter( [
                $loc['line1'],
                $loc['line2'],
                trim( "{$loc['city']}, {$loc['state']} {$loc['postal']}", " ,\t\n" ),
                $loc['country'],
            ] );
            return wp_kses_post( implode( '<br>', array_map( 'esc_html', $parts ) ) );
        };
        add_shortcode( 'business_address', $business_address );
        add_shortcode( 'address',          $business_address ); // legacy alias

        $business_hours = function() use ( $module ) {
            $hours = $module->get_options()['hours'];
            $days  = [
                'monday'    => 'Mon',
                'tuesday'   => 'Tue',
                'wednesday' => 'Wed',
                'thursday'  => 'Thu',
                'friday'    => 'Fri',
                'saturday'  => 'Sat',
                'sunday'    => 'Sun',
            ];
            $lines = [];
            foreach ( $days as $day => $label ) {
                $row = $hours[ $day ] ?? [];
                if ( ! empty( $row['closed'] ) ) {
                    $lines[] = esc_html( "{$label}: Closed" );
                } elseif ( $row['open'] && $row['close'] ) {
                    $lines[] = esc_html( "{$label}: {$row['open']}–{$row['close']}" );
                }
            }
            return wp_kses_post( implode( '<br>', $lines ) );
        };
        add_shortcode( 'business_hours', $business_hours );

        // ─── Brand assets ───
        $brand_logo = function( $atts ) use ( $module ) {
            $atts    = shortcode_atts( [ 'variant' => 'primary_logo' ], $atts );
            $brand   = $module->get_options()['brand'];
            $att_id  = absint( $brand[ $atts['variant'] ] ?? 0 );
            return $att_id ? esc_url( wp_get_attachment_url( $att_id ) ) : '';
        };
        add_shortcode( 'brand_logo', $brand_logo );

        // Legacy aliases — all map onto specific brand variants per spec §8.3.
        add_shortcode( 'site_image_url',              function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo' ] ); } );
        add_shortcode( 'site_image_horizontal',       function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo' ] ); } );
        add_shortcode( 'site_image_horizontal_white', function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo_white' ] ); } );
        add_shortcode( 'site_image_white',            function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo_white' ] ); } );

        add_shortcode( 'site_icon_url', function() use ( $module ) {
            $favicon = absint( $module->get_options()['brand']['favicon'] );
            if ( $favicon ) {
                return esc_url( wp_get_attachment_url( $favicon ) );
            }
            return esc_url( get_site_icon_url() );
        } );

        // ─── Social ───
        add_shortcode( 'social_link', function( $atts ) use ( $module ) {
            $atts     = shortcode_atts( [ 'platform' => '', 'label' => '' ], $atts );
            $platform = sanitize_key( $atts['platform'] );
            $url      = $module->get_options()['social'][ $platform ] ?? '';
            if ( $url === '' ) return '';
            $label = $atts['label'] !== '' ? $atts['label'] : ucfirst( $platform );
            return sprintf(
                '<a href="%1$s" rel="noopener" target="_blank">%2$s</a>',
                esc_url( $url ),
                esc_html( $label )
            );
        } );

        // ─── Utility (absorbed from anchor-shortcodes) ───
        add_shortcode( 'current_year', function() {
            return esc_html( date_i18n( 'Y' ) );
        } );
        add_shortcode( 'site_title', function() {
            return esc_html( get_bloginfo( 'name' ) );
        } );
        add_shortcode( 'page_title', function() {
            return esc_html( wp_get_document_title() );
        } );

        // ─── Custom shortcodes from the repeater ───
        $custom = $this->module->get_options()['custom_shortcodes'];
        foreach ( $custom as $row ) {
            $tag     = $row['shortcode'];
            $content = $row['content'];
            if ( $tag === '' ) continue;
            add_shortcode( $tag, function() use ( $content ) {
                return esc_html( $content );
            } );
        }
    }
}
