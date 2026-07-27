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

        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";

        // Preferred path: inline the @font-face CSS. The external stylesheet was
        // the single largest render-blocking resource on client sites (~900ms on
        // throttled mobile), but loading it async instead caused layout-shift
        // (CLS) when the real fonts swapped in after first paint. Inlining puts
        // the @font-face rules in the initial HTML so font requests start
        // immediately and are usually ready by first paint — no blocking request,
        // no late swap.
        $css = $this->get_remote_fonts_css( $url );
        if ( '' !== $css ) {
            echo '<style id="anchor-site-config-fonts">' . $css . '</style>' . "\n";
            return;
        }

        // Fallback (remote fetch failed): non-blocking external stylesheet.
        // display=swap is already in the URL, so text renders in fallback fonts
        // immediately; the noscript link covers no-JS visitors.
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '<link rel="preload" as="style" href="' . esc_url( $url ) . '">' . "\n";
        echo '<link rel="stylesheet" href="' . esc_url( $url ) . '" media="print" onload="this.media=\'all\';this.onload=null;">' . "\n";
        echo '<noscript><link rel="stylesheet" href="' . esc_url( $url ) . '"></noscript>' . "\n";
    }

    /**
     * Fetch (and transient-cache) the Google Fonts CSS for inlining.
     *
     * A modern-Chrome User-Agent makes the API return woff2 + unicode-range
     * subsets — the same payload every current browser would fetch itself.
     * Failures cache a short-lived empty string so a Google hiccup can't add
     * a 5s remote request to every uncached page render.
     */
    private function get_remote_fonts_css( $url ) {
        $key = 'ansc_gfonts_css_' . md5( $url );
        $css = get_transient( $key );
        if ( false !== $css ) {
            return (string) $css;
        }

        $res  = wp_remote_get( $url, [
            'timeout'    => 5,
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ] );
        $body = ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) )
            ? wp_remote_retrieve_body( $res )
            : '';

        if ( '' === $body || false === strpos( $body, '@font-face' ) ) {
            set_transient( $key, '', HOUR_IN_SECONDS );
            return '';
        }

        // Defense-in-depth for inlining into a <style> block.
        $body = str_ireplace( '</style', '', $body );

        set_transient( $key, $body, WEEK_IN_SECONDS );
        return $body;
    }
}
