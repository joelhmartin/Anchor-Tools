<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Site_Config_Shortcodes {

    /** @var Anchor_Site_Config_Module */
    private $module;

    public function __construct( Anchor_Site_Config_Module $module ) {
        $this->module = $module;
        add_action( 'init', [ $this, 'register_shortcodes' ] );
    }

    private function format_time( $time ) {
        $time = trim( (string) $time );
        if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m ) ) {
            return $time;
        }

        $hour   = (int) $m[1];
        $minute = $m[2];
        $suffix = $hour >= 12 ? 'PM' : 'AM';
        $hour   = $hour % 12;
        if ( 0 === $hour ) {
            $hour = 12;
        }

        return $minute === '00'
            ? sprintf( '%d %s', $hour, $suffix )
            : sprintf( '%d:%s %s', $hour, $minute, $suffix );
    }

    private function config_tag( $tag ) {
        $tag = sanitize_key( (string) $tag );
        if ( $tag === '' ) {
            return '';
        }
        return strpos( $tag, 'config_' ) === 0 ? $tag : 'config_' . $tag;
    }

    private function add_shortcode_once( $tag, $callback ) {
        $tag = sanitize_key( (string) $tag );
        if ( $tag !== '' && ! shortcode_exists( $tag ) ) {
            add_shortcode( $tag, $callback );
        }
    }

    private function days() {
        return [
            'monday'    => 'Mon',
            'tuesday'   => 'Tue',
            'wednesday' => 'Wed',
            'thursday'  => 'Thu',
            'friday'    => 'Fri',
            'saturday'  => 'Sat',
            'sunday'    => 'Sun',
        ];
    }

    private function normalize_day( $day ) {
        $day = sanitize_key( (string) $day );
        $aliases = [
            'mon' => 'monday',
            'tue' => 'tuesday',
            'tues' => 'tuesday',
            'wed' => 'wednesday',
            'thu' => 'thursday',
            'thur' => 'thursday',
            'thurs' => 'thursday',
            'fri' => 'friday',
            'sat' => 'saturday',
            'sun' => 'sunday',
        ];
        $day = $aliases[ $day ] ?? $day;
        return array_key_exists( $day, $this->days() ) ? $day : '';
    }

    private function show_label( $value ) {
        return ! in_array( strtolower( (string) $value ), [ '0', 'false', 'no', 'off' ], true );
    }

    private function format_hours_row( $row, $label = '', $show_label = true ) {
        if ( ! is_array( $row ) ) {
            return '';
        }

        if ( ! empty( $row['closed'] ) ) {
            $hours = 'Closed';
        } elseif ( ! empty( $row['open'] ) && ! empty( $row['close'] ) ) {
            $hours = $this->format_time( $row['open'] ) . ' - ' . $this->format_time( $row['close'] );
        } else {
            return '';
        }

        return ( $show_label && $label !== '' ) ? "{$label}: {$hours}" : $hours;
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
        add_shortcode( 'config_business_name', $business_name );
        $this->add_shortcode_once( 'business_name', $business_name );

        $business_tagline = function() use ( $module ) {
            return esc_html( $module->get_options()['business']['tagline'] );
        };
        add_shortcode( 'config_business_tagline', $business_tagline );
        $this->add_shortcode_once( 'business_tagline', $business_tagline );

        $business_phone = function() use ( $module ) {
            return esc_html( $module->get_options()['business']['phone'] );
        };
        add_shortcode( 'config_business_phone', $business_phone );
        add_shortcode( 'config_phone',          $business_phone );
        $this->add_shortcode_once( 'business_phone', $business_phone );
        $this->add_shortcode_once( 'phone',          $business_phone );

        $business_phone_href = function() use ( $module ) {
            $raw  = $module->get_options()['business']['phone'];
            $tel  = preg_replace( '/[^0-9+]/', '', $raw );
            return $tel ? esc_url( 'tel:' . $tel ) : '';
        };
        add_shortcode( 'config_business_phone_href', $business_phone_href );
        add_shortcode( 'config_phone_href',          $business_phone_href );
        $this->add_shortcode_once( 'business_phone_href', $business_phone_href );
        $this->add_shortcode_once( 'phone_href',          $business_phone_href );

        $business_email = function() use ( $module ) {
            $email = $module->get_options()['business']['email'] ?: get_option( 'admin_email' );
            return esc_html( $email );
        };
        add_shortcode( 'config_business_email', $business_email );
        add_shortcode( 'config_email',          $business_email );
        $this->add_shortcode_once( 'business_email', $business_email );
        $this->add_shortcode_once( 'email',          $business_email );

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
        add_shortcode( 'config_business_address', $business_address );
        add_shortcode( 'config_address',          $business_address );
        $this->add_shortcode_once( 'business_address', $business_address );
        $this->add_shortcode_once( 'address',          $business_address );

        $business_hours = function( $atts = [] ) use ( $module ) {
            $atts = shortcode_atts(
                [
                    'day'        => '',
                    // A single requested day shows just the times by default;
                    // the full-week output below always keeps day labels.
                    'show_label' => '0',
                ],
                $atts,
                'config_business_hours'
            );
            $hours = $module->get_options()['hours'];
            $days  = $this->days();
            $day   = $this->normalize_day( $atts['day'] );

            if ( $day ) {
                return esc_html( $this->format_hours_row( $hours[ $day ] ?? [], $days[ $day ], $this->show_label( $atts['show_label'] ) ) );
            }

            $lines = [];
            foreach ( $days as $day => $label ) {
                $line = $this->format_hours_row( $hours[ $day ] ?? [], $label, true );
                if ( $line !== '' ) {
                    $lines[] = esc_html( $line );
                }
            }
            return wp_kses_post( implode( '<br>', $lines ) );
        };
        add_shortcode( 'config_business_hours', $business_hours );
        $this->add_shortcode_once( 'business_hours', $business_hours );

        foreach ( array_keys( $this->days() ) as $day ) {
            add_shortcode( 'config_business_hours_' . $day, function( $atts = [] ) use ( $module, $day ) {
                $atts = shortcode_atts(
                    [ 'show_label' => '0' ],
                    $atts,
                    'config_business_hours_' . $day
                );
                $hours = $module->get_options()['hours'];
                $days  = $this->days();
                return esc_html( $this->format_hours_row( $hours[ $day ] ?? [], $days[ $day ], $this->show_label( $atts['show_label'] ) ) );
            } );
        }

        // ─── Brand assets ───
        $brand_logo = function( $atts ) use ( $module ) {
            $atts    = shortcode_atts( [ 'variant' => 'primary_logo' ], $atts );
            $brand   = $module->get_options()['brand'];
            $att_id  = absint( $brand[ $atts['variant'] ] ?? 0 );
            return $att_id ? esc_url( wp_get_attachment_url( $att_id ) ) : '';
        };
        add_shortcode( 'config_brand_logo', $brand_logo );
        $this->add_shortcode_once( 'brand_logo', $brand_logo );

        add_shortcode( 'config_site_image_url',              function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo' ] ); } );
        add_shortcode( 'config_site_image_horizontal',       function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo' ] ); } );
        add_shortcode( 'config_site_image_horizontal_white', function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo_white' ] ); } );
        add_shortcode( 'config_site_image_white',            function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo_white' ] ); } );
        $this->add_shortcode_once( 'site_image_url',              function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo' ] ); } );
        $this->add_shortcode_once( 'site_image_horizontal',       function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo' ] ); } );
        $this->add_shortcode_once( 'site_image_horizontal_white', function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo_white' ] ); } );
        $this->add_shortcode_once( 'site_image_white',            function() use ( $brand_logo ) { return $brand_logo( [ 'variant' => 'primary_logo_white' ] ); } );

        $site_icon_url = function() use ( $module ) {
            $favicon = absint( $module->get_options()['brand']['favicon'] );
            if ( $favicon ) {
                return esc_url( wp_get_attachment_url( $favicon ) );
            }
            return esc_url( get_site_icon_url() );
        };
        add_shortcode( 'config_site_icon_url', $site_icon_url );
        $this->add_shortcode_once( 'site_icon_url', $site_icon_url );

        // ─── Social ───
        $social_link = function( $atts ) use ( $module ) {
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
        };
        add_shortcode( 'config_social_link', $social_link );
        $this->add_shortcode_once( 'social_link', $social_link );

        // ─── Utility (absorbed from anchor-shortcodes) ───
        $current_year = function() {
            return esc_html( date_i18n( 'Y' ) );
        };
        add_shortcode( 'config_current_year', $current_year );
        $this->add_shortcode_once( 'current_year', $current_year );

        $site_title = function() {
            return esc_html( get_bloginfo( 'name' ) );
        };
        add_shortcode( 'config_site_title', $site_title );
        $this->add_shortcode_once( 'site_title', $site_title );

        $page_title = function() {
            // Match anchor-shortcodes' original context-aware behavior: bare titles per
            // context rather than wp_get_document_title()'s "Title – Site" composite.
            if ( is_singular() ) {
                return esc_html( get_the_title() );
            }
            if ( is_post_type_archive() ) {
                return esc_html( post_type_archive_title( '', false ) );
            }
            if ( is_category() || is_tag() || is_tax() ) {
                return esc_html( single_term_title( '', false ) );
            }
            if ( is_author() ) {
                return esc_html( get_the_author() );
            }
            if ( is_search() ) {
                return esc_html( get_search_query() );
            }
            if ( is_home() && get_option( 'page_for_posts' ) ) {
                return esc_html( get_the_title( get_option( 'page_for_posts' ) ) );
            }
            return esc_html( wp_get_document_title() );
        };
        add_shortcode( 'config_page_title', $page_title );
        $this->add_shortcode_once( 'page_title', $page_title );

        // ─── Custom shortcodes from the repeater ───
        // Register custom Site Config values under config_* names so they can
        // coexist with Anchor Shortcodes or any other module.
        $custom = $this->module->get_options()['custom_shortcodes'];
        foreach ( $custom as $row ) {
            $raw_tag    = sanitize_key( $row['shortcode'] ?? '' );
            $config_tag = $this->config_tag( $raw_tag );
            $content    = $row['content'] ?? '';
            if ( $config_tag === '' ) continue;

            $callback = function() use ( $content ) {
                return esc_html( $content );
            };
            $this->add_shortcode_once( $config_tag, $callback );
            if ( $raw_tag !== $config_tag ) {
                $this->add_shortcode_once( $raw_tag, $callback );
            }
        }
    }
}
