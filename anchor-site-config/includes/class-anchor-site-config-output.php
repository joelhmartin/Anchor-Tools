<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Site_Config_Output {

    /** @var Anchor_Site_Config_Module */
    private $module;

    public function __construct( Anchor_Site_Config_Module $module ) {
        $this->module = $module;
        add_action( 'wp_head', [ $this, 'render_google_fonts' ], 3 );
        add_action( 'wp_head', [ $this, 'render_css_vars'    ], 5 );
    }

    public function render_css_vars() {
        // Guard against double output if two Output instances ever get hooked
        // (defense-in-depth alongside the Module::instance() fix).
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        $opts   = $this->module->get_options();
        $colors = $opts['colors'];
        $fonts  = $opts['fonts'];

        $css = ":root {\n";
        foreach ( $colors as $key => $val ) {
            $css .= "  --anchor-color-{$key}: {$val};\n";
        }
        // Append a generic fallback per font role so var() resolves to something
        // sensible if the family is empty.
        $generic = [ 'heading' => 'sans-serif', 'body' => 'sans-serif', 'accent' => 'serif' ];
        foreach ( $fonts as $role => $f ) {
            $family   = $f['family'] !== '' ? $f['family'] : '';
            $rendered = $family !== '' ? "'{$family}', {$generic[$role]}" : $generic[ $role ];
            $css .= "  --anchor-font-{$role}: {$rendered};\n";
        }
        $css .= '}';

        echo "<style id=\"anchor-site-config-vars\">{$css}</style>\n";
    }

    public function render_google_fonts() {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        $opts     = $this->module->get_options();
        $families = [];
        foreach ( $opts['fonts'] as $role => $f ) {
            if ( ( $f['source'] ?? '' ) === 'google' && $f['family'] !== '' ) {
                $families[ $f['family'] ] = true;
            }
        }
        if ( empty( $families ) ) {
            return;
        }

        // Build a single googleapis URL with all families and weights 400;600.
        $parts = [];
        foreach ( array_keys( $families ) as $family ) {
            $parts[] = 'family=' . rawurlencode( $family ) . ':wght@400;600';
        }
        $url = 'https://fonts.googleapis.com/css2?' . implode( '&', $parts ) . '&display=swap';

        // Non-blocking load: the fonts CSS was the single largest render-blocking
        // resource on client sites (~900ms on throttled mobile). display=swap is
        // already in the URL, so text renders in fallback fonts immediately and
        // swaps when the woff2 arrives; the noscript link covers no-JS visitors.
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        echo '<link rel="preload" as="style" href="' . esc_url( $url ) . '">' . "\n";
        echo '<link rel="stylesheet" href="' . esc_url( $url ) . '" media="print" onload="this.media=\'all\';this.onload=null;">' . "\n";
        echo '<noscript><link rel="stylesheet" href="' . esc_url( $url ) . '"></noscript>' . "\n";
    }
}
