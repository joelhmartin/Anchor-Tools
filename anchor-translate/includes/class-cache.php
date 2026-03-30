<?php
/**
 * Anchor Translate — Per-page per-language translation cache.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Cache {

    const GLOBAL_VERSION_KEY = 'anchor_translate_global_version';
    const TRANSIENT_PREFIX   = 'at_';

    /** Retrieve cached translated HTML, or false if stale/missing. */
    public function get( $post_id, $lang ) {
        $key    = $this->build_key( $post_id, $lang );
        $cached = get_transient( $key );

        if ( ! is_array( $cached ) || ! isset( $cached['hash'], $cached['html'] ) ) {
            return false;
        }

        if ( $cached['hash'] !== $this->compute_hash( $post_id ) ) {
            delete_transient( $key );
            return false;
        }

        return $cached['html'];
    }

    /** Store translated HTML. */
    public function set( $post_id, $lang, $html ) {
        set_transient(
            $this->build_key( $post_id, $lang ),
            [
                'hash' => $this->compute_hash( $post_id ),
                'html' => $html,
            ],
            30 * DAY_IN_SECONDS
        );
    }

    /** Delete all language caches for a single post. */
    public function invalidate_post( $post_id, array $language_codes ) {
        foreach ( $language_codes as $code ) {
            delete_transient( $this->build_key( $post_id, $code ) );
        }
    }

    /** Increment global version so all caches become stale. */
    public function bump_global_version() {
        $v = (int) get_option( self::GLOBAL_VERSION_KEY, 0 );
        update_option( self::GLOBAL_VERSION_KEY, $v + 1, false );
    }

    /** Get current global version number. */
    public function get_global_version() {
        return (int) get_option( self::GLOBAL_VERSION_KEY, 0 );
    }

    /** Delete every translation transient in the database. */
    public function flush_all() {
        global $wpdb;
        $prefix = $wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $prefix . '%',
                '_transient_timeout_' . $wpdb->esc_like( self::TRANSIENT_PREFIX ) . '%'
            )
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Internals                                                         */
    /* ------------------------------------------------------------------ */

    private function build_key( $post_id, $lang ) {
        return self::TRANSIENT_PREFIX . $post_id . '_' . $lang;
    }

    private function compute_hash( $post_id ) {
        $post = get_post( $post_id );
        $modified = $post ? $post->post_modified_gmt : '';
        $gv = $this->get_global_version();
        return md5( $modified . '|' . $gv . '|' . ANCHOR_TRANSLATE_VERSION );
    }
}
