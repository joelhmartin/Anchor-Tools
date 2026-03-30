<?php
/**
 * Anchor Translate — [anchor_translate_switcher] shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Shortcode {

    private $language;

    public function __construct( Anchor_Translate_Language $language ) {
        $this->language = $language;
    }

    public function init() {
        add_shortcode( 'anchor_translate_switcher', [ $this, 'render' ] );
        add_shortcode( 'site_language_switcher', [ $this, 'render' ] );
    }

    public function render( $atts = [] ) {
        $languages = $this->language->get_enabled();
        $current   = $this->language->get_current();

        if ( count( $languages ) < 2 ) return '';

        $links = [];
        foreach ( $languages as $code => $label ) {
            $active = ( $code === $current ) ? ' active' : '';
            $url    = esc_url( $this->language->get_switch_url( $code ) );
            $links[] = sprintf(
                '<a class="anchor-translate-link%s" href="%s" data-lang="%s">%s</a>',
                $active,
                $url,
                esc_attr( $code ),
                esc_html( $label )
            );
        }

        return '<div class="anchor-translate-switcher" data-no-translate="true">'
            . implode( ' ', $links )
            . '</div>';
    }
}
