<?php
/**
 * Anchor Translate — Output buffering pipeline.
 *
 * Captures the final rendered HTML on non-default-language requests,
 * checks cache, and runs the translation pipeline on cache miss.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Buffer {

    private $language;
    private $parser;
    private $provider;
    private $cache;
    private $options;

    public function __construct(
        Anchor_Translate_Language        $language,
        Anchor_Translate_DOM_Parser      $parser,
        Anchor_Translate_Google_Provider $provider,
        Anchor_Translate_Cache           $cache,
        array                            $options
    ) {
        $this->language = $language;
        $this->parser   = $parser;
        $this->provider = $provider;
        $this->cache    = $cache;
        $this->options  = $options;
    }

    public function init() {
        // Nothing to do for default-language visitors.
        if ( $this->language->is_default() ) return;

        add_action( 'template_redirect', [ $this, 'maybe_start' ], 1 );
    }

    /* ------------------------------------------------------------------ */
    /*  Start / process                                                   */
    /* ------------------------------------------------------------------ */

    public function maybe_start() {
        if ( $this->should_skip() ) return;

        ob_start( [ $this, 'process' ] );
    }

    /**
     * Output-buffer callback — receives the full rendered HTML.
     */
    public function process( $html ) {
        // Sanity: must be a real HTML page.
        if ( empty( $html ) || strlen( $html ) < 200 ) return $html;
        if ( strpos( $html, '</html>' ) === false && strpos( $html, '</HTML>' ) === false ) return $html;

        $post_id = get_queried_object_id();
        $lang    = $this->language->get_current();

        // Cache check.
        if ( ( $this->options['cache_enabled'] ?? '1' ) !== '0' && $post_id ) {
            $cached = $this->cache->get( $post_id, $lang );
            if ( $cached !== false ) return $cached;
        }

        // Translate.
        $source = $this->language->get_default();
        $translated_html = $this->parser->translate_html(
            $html,
            function ( array $strings ) use ( $source, $lang ) {
                return $this->provider->translate( $strings, $source, $lang );
            }
        );

        // Store in cache.
        if ( ( $this->options['cache_enabled'] ?? '1' ) !== '0' && $post_id ) {
            $this->cache->set( $post_id, $lang, $translated_html );
        }

        return $translated_html;
    }

    /* ------------------------------------------------------------------ */
    /*  Skip conditions                                                   */
    /* ------------------------------------------------------------------ */

    private function should_skip() {
        if ( is_admin() )           return true;
        if ( wp_doing_ajax() )      return true;
        if ( wp_doing_cron() )      return true;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return true;
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) return true;
        if ( is_feed() )            return true;
        if ( is_robots() )          return true;
        if ( is_preview() )         return true;
        if ( is_customize_preview() ) return true;

        // Login / register pages.
        $pagenow = $GLOBALS['pagenow'] ?? '';
        if ( in_array( $pagenow, [ 'wp-login.php', 'wp-register.php' ], true ) ) return true;

        // XML sitemaps.
        if ( isset( $_SERVER['REQUEST_URI'] ) && preg_match( '/sitemap.*\.xml/i', $_SERVER['REQUEST_URI'] ) ) return true;

        // Excluded URL patterns.
        $path     = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?: '';
        $excludes = $this->parse_lines( $this->options['exclude_urls'] ?? '' );
        foreach ( $excludes as $pattern ) {
            if ( $pattern !== '' && fnmatch( $pattern, $path ) ) return true;
        }

        return false;
    }

    private function parse_lines( $value ) {
        if ( ! is_string( $value ) || $value === '' ) return [];
        return array_filter( array_map( 'trim', preg_split( '/\r?\n/', $value ) ) );
    }
}
