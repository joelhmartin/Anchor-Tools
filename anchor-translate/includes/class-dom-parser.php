<?php
/**
 * Anchor Translate — Regex-based HTML text extraction and rebuild.
 *
 * Splits HTML into tag/text segments, identifies translatable text,
 * and reassembles with translated text. Never modifies HTML structure.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_DOM_Parser {

    /**
     * Raw-content tags: their inner content is NOT HTML and must not be
     * parsed for nested tags. We skip everything until the matching close
     * tag, ignoring any tag-like patterns inside (e.g. HTML strings in JS).
     */
    private static $raw_skip_tags = [
        'script', 'style', 'svg', 'noscript', 'textarea', 'head',
    ];

    /**
     * Structural skip tags: their subtree IS valid HTML so we depth-track
     * open/close to know when we exit, but we never translate their text.
     */
    private static $structural_skip_tags = [
        'code', 'pre', 'iframe', 'object', 'canvas', 'math',
        'select', 'option',
    ];

    /** Void (self-closing) elements that don't push nesting depth. */
    private static $void_tags = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
        'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private $exclude_classes  = [];
    private $exclude_ids      = [];
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
     * Uses two distinct skip modes:
     *
     *   1. RAW SKIP — for script, style, svg, noscript, textarea, head.
     *      Content is NOT HTML; we ignore everything until the exact
     *      matching close tag (e.g. </script>). This prevents Divi's
     *      inline JS strings like '<div>' from corrupting the parser.
     *
     *   2. STRUCTURAL SKIP — for code, pre, iframe, select, etc.
     *      Content IS valid HTML; we depth-track open/close tags to
     *      know when we exit the subtree, but never translate text.
     *
     * @return array [ $parts, $segments ]
     */
    private function parse( $html ) {
        $parts = preg_split( '/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

        $raw_skip_tag      = '';   // Non-empty when inside a raw-content skip.
        $structural_depth  = 0;    // > 0 when inside a structural skip subtree.
        $segments          = [];

        foreach ( $parts as $i => $part ) {
            // Is this an HTML tag?
            if ( isset( $part[0] ) && $part[0] === '<'
                && preg_match( '/^<(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)/s', $part, $tm )
            ) {
                $is_closing = ( $tm[1] === '/' );
                $tag_name   = strtolower( $tm[2] );
                $is_void    = in_array( $tag_name, self::$void_tags, true );

                /* ── Mode 1: inside a raw-content skip ────────────── */
                if ( $raw_skip_tag !== '' ) {
                    // Only the matching close tag exits the skip.
                    if ( $is_closing && $tag_name === $raw_skip_tag ) {
                        $raw_skip_tag = '';
                    }
                    // Everything else (tags, text) is ignored.
                    continue;
                }

                /* ── Mode 2: inside a structural skip ─────────────── */
                if ( $structural_depth > 0 ) {
                    if ( $is_closing ) {
                        $structural_depth--;
                    } elseif ( ! $is_void ) {
                        $structural_depth++;
                    }
                    continue;
                }

                /* ── Not skipping — check if this tag starts a skip ─ */
                if ( ! $is_closing && ! $is_void ) {
                    // Raw-content tags: skip until matching close tag.
                    if ( in_array( $tag_name, self::$raw_skip_tags, true ) ) {
                        $raw_skip_tag = $tag_name;
                        continue;
                    }

                    // Structural skip tags, data-no-translate, excluded selectors.
                    if ( $this->should_skip_tag( $tag_name, $part ) ) {
                        $structural_depth = 1;
                        continue;
                    }
                }

                continue;
            }

            /* ── Text segment ─────────────────────────────────────── */
            if ( $raw_skip_tag !== '' || $structural_depth > 0 ) continue;

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
     * Decide whether an opening tag should trigger a structural skip.
     * (Raw-content tags are handled before this method is called.)
     */
    private function should_skip_tag( $tag_name, $tag_html ) {
        // Structural skip tags.
        if ( in_array( $tag_name, self::$structural_skip_tags, true ) ) return true;

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
