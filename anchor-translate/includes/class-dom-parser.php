<?php
/**
 * Anchor Translate — Regex-based HTML text extraction and rebuild.
 *
 * Splits HTML into tag/text segments, identifies translatable text,
 * and reassembles with translated text. Never modifies HTML structure.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_DOM_Parser {

    /** Tags whose entire subtree is never translated. */
    private static $skip_tags = [
        'script', 'style', 'code', 'pre', 'svg', 'noscript',
        'textarea', 'input', 'select', 'option', 'head',
        'iframe', 'object', 'embed', 'canvas', 'math',
    ];

    /** Void (self-closing) elements that don't push nesting depth. */
    private static $void_tags = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
        'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private $exclude_classes = [];
    private $exclude_ids     = [];
    private $preserve_phrases = [];

    public function __construct( array $exclude_selectors = [], array $preserve_phrases = [] ) {
        foreach ( $exclude_selectors as $sel ) {
            $sel = trim( $sel );
            if ( $sel === '' ) continue;
            if ( $sel[0] === '.' ) {
                $this->exclude_classes[] = substr( $sel, 1 );
            } elseif ( $sel[0] === '#' ) {
                $this->exclude_ids[] = substr( $sel, 1 );
            }
        }
        $this->preserve_phrases = array_filter( array_map( 'trim', $preserve_phrases ) );
    }

    /**
     * Parse HTML, extract translatable text, translate via callback, reassemble.
     *
     * @param string   $html         Full page HTML.
     * @param callable $translate_fn Receives string[] → returns string[] (same order).
     * @return string Translated HTML.
     */
    public function translate_html( $html, callable $translate_fn ) {
        list( $parts, $segments ) = $this->parse( $html );

        if ( empty( $segments ) ) return $html;

        // Collect strings for translation.
        $strings = [];
        foreach ( $segments as $seg ) {
            $strings[] = $seg['text'];
        }

        // Protect preserved phrases with placeholders.
        $placeholders = [];
        if ( ! empty( $this->preserve_phrases ) ) {
            list( $strings, $placeholders ) = $this->protect_phrases( $strings );
        }

        // Translate.
        $translated = $translate_fn( $strings );

        // Restore preserved phrases.
        if ( ! empty( $placeholders ) ) {
            $translated = $this->restore_phrases( $translated, $placeholders );
        }

        // Rebuild HTML.
        foreach ( $segments as $j => $seg ) {
            $original = $seg['original'];
            $new_text = $translated[ $j ] ?? $seg['text'];

            // Preserve leading/trailing whitespace from the original segment.
            $leading  = '';
            $trailing = '';
            if ( preg_match( '/^(\s+)/', $original, $m ) ) $leading  = $m[1];
            if ( preg_match( '/(\s+)$/', $original, $m ) ) $trailing = $m[1];

            $parts[ $seg['index'] ] = $leading . $new_text . $trailing;
        }

        return implode( '', $parts );
    }

    /* ------------------------------------------------------------------ */
    /*  HTML Parsing                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Split HTML into segments and identify translatable text.
     *
     * @return array [ $parts, $segments ]
     *   $parts    — alternating tag/text pieces (array).
     *   $segments — [ { index, text, original } ] for translatable pieces.
     */
    private function parse( $html ) {
        $parts = preg_split( '/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

        $skip_depth = 0;
        $segments   = [];

        foreach ( $parts as $i => $part ) {
            // Is this an HTML tag?
            if ( isset( $part[0] ) && $part[0] === '<' && preg_match( '/^<(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)/s', $part, $tm ) ) {
                $is_closing = ( $tm[1] === '/' );
                $tag_name   = strtolower( $tm[2] );
                $is_void    = in_array( $tag_name, self::$void_tags, true );

                if ( $skip_depth > 0 ) {
                    // Inside a skipped subtree: track nesting.
                    if ( $is_closing ) {
                        $skip_depth--;
                    } elseif ( ! $is_void ) {
                        $skip_depth++;
                    }
                    continue;
                }

                // Not in skip region — check if this opening tag starts one.
                if ( ! $is_closing && ! $is_void ) {
                    if ( $this->should_skip_tag( $tag_name, $part ) ) {
                        $skip_depth = 1;
                    }
                }

                continue;
            }

            // Text segment.
            if ( $skip_depth > 0 ) continue;

            $trimmed = trim( $part );
            if ( $trimmed === '' ) continue;
            // Must contain at least one letter.
            if ( ! preg_match( '/\pL/u', $trimmed ) ) continue;
            // Skip pure numeric / punctuation.
            if ( preg_match( '/^[\d\s\p{P}\p{S}]+$/u', $trimmed ) ) continue;

            $segments[] = [
                'index'    => $i,
                'text'     => $trimmed,
                'original' => $part,
            ];
        }

        return [ $parts, $segments ];
    }

    /**
     * Decide whether an opening tag should trigger a skip region.
     */
    private function should_skip_tag( $tag_name, $tag_html ) {
        // Hardcoded skip tags.
        if ( in_array( $tag_name, self::$skip_tags, true ) ) return true;

        // data-no-translate attribute.
        if ( stripos( $tag_html, 'data-no-translate' ) !== false ) return true;

        // Excluded classes.
        if ( ! empty( $this->exclude_classes ) && preg_match( '/class\s*=\s*["\']([^"\']*)["\']/', $tag_html, $cm ) ) {
            $classes = $cm[1];
            foreach ( $this->exclude_classes as $exc_class ) {
                if ( preg_match( '/\b' . preg_quote( $exc_class, '/' ) . '\b/', $classes ) ) {
                    return true;
                }
            }
        }

        // Excluded IDs.
        if ( ! empty( $this->exclude_ids ) && preg_match( '/id\s*=\s*["\']([^"\']*)["\']/', $tag_html, $im ) ) {
            $id = $im[1];
            if ( in_array( $id, $this->exclude_ids, true ) ) return true;
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /*  Preserve-phrase placeholders                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Replace preserve-phrases with numbered placeholders before translation.
     */
    private function protect_phrases( array $strings ) {
        $map = [];
        foreach ( $this->preserve_phrases as $idx => $phrase ) {
            $map[ $phrase ] = '[[AP' . $idx . ']]';
        }

        $out = [];
        foreach ( $strings as $str ) {
            foreach ( $map as $phrase => $placeholder ) {
                $str = str_ireplace( $phrase, $placeholder, $str );
            }
            $out[] = $str;
        }

        return [ $out, $map ];
    }

    /**
     * Restore preserve-phrase placeholders after translation.
     */
    private function restore_phrases( array $strings, array $map ) {
        $reverse = array_flip( $map ); // placeholder => phrase

        $out = [];
        foreach ( $strings as $str ) {
            foreach ( $reverse as $placeholder => $phrase ) {
                $str = str_replace( $placeholder, $phrase, $str );
            }
            $out[] = $str;
        }

        return $out;
    }
}
