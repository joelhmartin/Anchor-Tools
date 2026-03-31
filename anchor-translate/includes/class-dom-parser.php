<?php
/**
 * Anchor Translate — Regex-based HTML text extraction and rebuild.
 *
 * Splits HTML into tag/text segments, identifies translatable text,
 * and reassembles with translated text. Never modifies HTML structure.
 *
 * Raw-content blocks (script, style, svg, noscript, textarea, head) are
 * pre-extracted before the tag/text split so that operators like <= inside
 * inline JS can never corrupt the <[^>]+> tag regex.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_DOM_Parser {

    /**
     * Tags whose entire block is extracted before parsing.
     * Matched with: <tag ...>...</tag> (non-greedy, case-insensitive).
     */
    private static $raw_extract_tags = [
        'script', 'style', 'svg', 'noscript', 'textarea',
    ];

    /**
     * Structural skip tags: their subtree IS valid HTML so we depth-track
     * open/close to know when we exit, but we never translate their text.
     */
    private static $structural_skip_tags = [
        'code', 'pre', 'head', 'iframe', 'object', 'canvas', 'math',
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

    /* ------------------------------------------------------------------ */
    /*  Public API                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Parse HTML, extract translatable text, translate via callback, reassemble.
     *
     * @param string   $html         Full page HTML.
     * @param callable $translate_fn Receives string[] → returns string[] (same order).
     * @return string Translated HTML.
     */
    public function translate_html( $html, callable $translate_fn ) {

        // 1. Pre-extract raw-content blocks so the tag regex never sees
        //    characters like < inside JS/CSS that would corrupt the split.
        $raw_blocks = [];
        $safe_html  = $this->extract_raw_blocks( $html, $raw_blocks );

        // 2. Parse the sanitised HTML.
        list( $parts, $segments ) = $this->parse( $safe_html );

        if ( empty( $segments ) ) {
            // Nothing to translate — restore raw blocks and return.
            return $this->restore_raw_blocks( $safe_html, $raw_blocks );
        }

        // 3. Collect strings for translation.
        $strings = [];
        foreach ( $segments as $seg ) {
            $strings[] = $seg['text'];
        }

        // 4. Protect preserved phrases with placeholders.
        $placeholders = [];
        if ( ! empty( $this->preserve_phrases ) ) {
            list( $strings, $placeholders ) = $this->protect_phrases( $strings );
        }

        // 5. Translate.
        $translated = $translate_fn( $strings );

        // 6. Restore preserved phrases.
        if ( ! empty( $placeholders ) ) {
            $translated = $this->restore_phrases( $translated, $placeholders );
        }

        // 7. Rebuild HTML with translated text.
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

        $result = implode( '', $parts );

        // 8. Restore raw-content blocks.
        return $this->restore_raw_blocks( $result, $raw_blocks );
    }

    /* ------------------------------------------------------------------ */
    /*  Raw-block pre-extraction                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Replace every <script>...</script>, <style>...</style>, etc. with an
     * HTML-comment placeholder. Returns the cleaned HTML; populates $blocks
     * with the original content keyed by placeholder index.
     *
     * Uses a regex that matches the opening tag, then non-greedily captures
     * everything up to the matching close tag. The `s` flag lets `.` match
     * newlines. This runs BEFORE the tag-splitter, so the `<[^>]+>` regex
     * never encounters bare `<` inside JS/CSS.
     */
    private function extract_raw_blocks( $html, array &$blocks ) {
        $tags_pattern = implode( '|', self::$raw_extract_tags );

        return preg_replace_callback(
            '/<(' . $tags_pattern . ')\b[^>]*>.*?<\/\1\s*>/si',
            function ( $m ) use ( &$blocks ) {
                $idx = count( $blocks );
                $blocks[ $idx ] = $m[0];
                return '<!--ATRAW:' . $idx . '-->';
            },
            $html
        );
    }

    /**
     * Put the original raw blocks back, replacing each placeholder comment.
     */
    private function restore_raw_blocks( $html, array $blocks ) {
        if ( empty( $blocks ) ) return $html;

        return preg_replace_callback(
            '/<!--ATRAW:(\d+)-->/',
            function ( $m ) use ( $blocks ) {
                return $blocks[ (int) $m[1] ] ?? $m[0];
            },
            $html
        );
    }

    /* ------------------------------------------------------------------ */
    /*  HTML Parsing                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Split HTML into segments and identify translatable text.
     *
     * At this point, all raw-content blocks (script, style, svg, etc.)
     * have already been replaced with safe <!--ATRAW:N--> placeholders,
     * so the <[^>]+> regex only sees well-formed HTML tags.
     *
     * Structural skip (code, pre, head, iframe, …) still uses depth
     * tracking since those tags contain valid nested HTML.
     *
     * @return array [ $parts, $segments ]
     */
    private function parse( $html ) {
        $parts = preg_split( '/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

        $structural_depth = 0;
        $segments         = [];

        foreach ( $parts as $i => $part ) {
            // Is this an HTML tag?
            if ( isset( $part[0] ) && $part[0] === '<'
                && preg_match( '/^<(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)/s', $part, $tm )
            ) {
                $is_closing = ( $tm[1] === '/' );
                $tag_name   = strtolower( $tm[2] );
                $is_void    = in_array( $tag_name, self::$void_tags, true );

                /* ── Inside a structural skip ─────────────────────── */
                if ( $structural_depth > 0 ) {
                    if ( $is_closing ) {
                        $structural_depth--;
                    } elseif ( ! $is_void ) {
                        $structural_depth++;
                    }
                    continue;
                }

                /* ── Check if this opening tag starts a skip ─────── */
                if ( ! $is_closing && ! $is_void ) {
                    if ( $this->should_skip_tag( $tag_name, $part ) ) {
                        $structural_depth = 1;
                        continue;
                    }
                }

                continue;
            }

            /* ── Text segment ─────────────────────────────────────── */
            if ( $structural_depth > 0 ) continue;

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

    private function restore_phrases( array $strings, array $map ) {
        $reverse = array_flip( $map );

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
