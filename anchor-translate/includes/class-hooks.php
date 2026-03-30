<?php
/**
 * Anchor Translate — Cache invalidation hooks and SEO meta.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Hooks {

    private $cache;
    private $language;
    private $options;

    public function __construct(
        Anchor_Translate_Cache    $cache,
        Anchor_Translate_Language $language,
        array                     $options
    ) {
        $this->cache    = $cache;
        $this->language = $language;
        $this->options  = $options;
    }

    public function init() {
        // Per-page invalidation.
        add_action( 'save_post', [ $this, 'on_save_post' ], 20 );

        // Global version bumps.
        add_action( 'wp_update_nav_menu',         [ $this, 'bump_global' ] );
        add_action( 'customize_save_after',        [ $this, 'bump_global' ] );
        add_action( 'switch_theme',                [ $this, 'bump_global' ] );
        add_action( 'update_option_sidebars_widgets', [ $this, 'bump_global' ] );

        // SEO noindex on translated pages.
        add_action( 'wp_head', [ $this, 'maybe_noindex' ], 1 );
    }

    /* ------------------------------------------------------------------ */
    /*  Per-page invalidation                                             */
    /* ------------------------------------------------------------------ */

    public function on_save_post( $post_id ) {
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( wp_is_post_autosave( $post_id ) ) return;

        $codes = array_keys( $this->language->get_enabled() );
        $this->cache->invalidate_post( $post_id, $codes );
    }

    /* ------------------------------------------------------------------ */
    /*  Global version                                                    */
    /* ------------------------------------------------------------------ */

    public function bump_global() {
        $this->cache->bump_global_version();
    }

    /* ------------------------------------------------------------------ */
    /*  SEO                                                               */
    /* ------------------------------------------------------------------ */

    public function maybe_noindex() {
        if ( is_admin() ) return;
        if ( $this->language->is_default() ) return;
        if ( ( $this->options['noindex'] ?? '1' ) === '0' ) return;

        echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
    }
}
