<?php
/**
 * Anchor Translate — [anchor_translate_switcher] shortcode.
 *
 * Renders flag icons that trigger client-side Google Translate widget.
 * Protected from translation via skiptranslate / notranslate classes.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Shortcode {

    private $language;

    /** Language code → country code for flag images. */
    private static $flag_map = [
        'en' => 'us', 'es' => 'es', 'fr' => 'fr', 'de' => 'de',
        'it' => 'it', 'pt' => 'br', 'nl' => 'nl', 'ru' => 'ru',
        'ja' => 'jp', 'ko' => 'kr', 'zh' => 'cn', 'ar' => 'sa',
        'hi' => 'in', 'pl' => 'pl', 'sv' => 'se', 'da' => 'dk',
        'fi' => 'fi', 'no' => 'no', 'tr' => 'tr', 'el' => 'gr',
        'he' => 'il', 'th' => 'th', 'vi' => 'vn', 'uk' => 'ua',
        'cs' => 'cz', 'ro' => 'ro', 'hu' => 'hu', 'id' => 'id',
        'ms' => 'my', 'tl' => 'ph', 'bn' => 'bd', 'ca' => 'es',
        'hr' => 'hr', 'sk' => 'sk', 'bg' => 'bg', 'sr' => 'rs',
        'sl' => 'si', 'lt' => 'lt', 'lv' => 'lv', 'et' => 'ee',
    ];

    public function __construct( Anchor_Translate_Language $language ) {
        $this->language = $language;
    }

    public function init() {
        add_shortcode( 'anchor_translate_switcher', [ $this, 'render' ] );
        add_shortcode( 'site_language_switcher', [ $this, 'render' ] );
    }

    public function render( $atts = [] ) {
        $atts = shortcode_atts( [
            'style' => 'flags', // flags | text | both | code | flags_code
        ], $atts );

        $languages = $this->language->get_enabled();
        $current   = $this->language->get_current();
        $style     = $atts['style'];

        if ( count( $languages ) < 2 ) return '';

        $links = [];
        foreach ( $languages as $code => $label ) {
            $active = ( $code === $current ) ? ' active' : '';

            $show_flag = in_array( $style, [ 'flags', 'both', 'flags_code' ], true );
            $show_full = in_array( $style, [ 'text', 'both' ], true );
            $show_code = in_array( $style, [ 'code', 'flags_code' ], true );

            $flag_html = '';
            if ( $show_flag ) {
                $country = self::$flag_map[ $code ] ?? $code;
                $flag_url = 'https://flagcdn.com/24x18/' . $country . '.png';
                $flag_2x  = 'https://flagcdn.com/48x36/' . $country . '.png';
                $flag_html = sprintf(
                    '<img class="anchor-translate-flag" src="%s" srcset="%s 2x" width="24" height="18" alt="%s" loading="lazy" />',
                    esc_url( $flag_url ),
                    esc_url( $flag_2x ),
                    esc_attr( $label )
                );
            }

            $label_html = '';
            if ( $show_full ) {
                $label_html = '<span class="anchor-translate-label">' . esc_html( $label ) . '</span>';
            } elseif ( $show_code ) {
                $label_html = '<span class="anchor-translate-label">' . esc_html( strtoupper( $code ) ) . '</span>';
            }

            $links[] = sprintf(
                '<a class="anchor-translate-link%s" href="#" onclick="anchorTranslateSwitchLang(\'%s\'); return false;" data-lang="%s" title="%s">%s%s</a>',
                $active,
                esc_js( $code ),
                esc_attr( $code ),
                esc_attr( $label ),
                $flag_html,
                $label_html
            );
        }

        return '<div class="anchor-translate-switcher skiptranslate notranslate">'
            . implode( '', $links )
            . '</div>';
    }
}
