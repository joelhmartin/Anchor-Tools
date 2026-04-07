<?php
/**
 * Anchor Translate — translate full HTML responses server-side.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Response_Translator {

    const CACHE_PREFIX = 'anchor_translate_render_';

    private $provider;
    private $language;
    private $options;

    public function __construct( Anchor_Translate_Google_Provider $provider, Anchor_Translate_Language $language, array $options ) {
        $this->provider = $provider;
        $this->language = $language;
        $this->options  = $options;
    }

    public function translate_html( $html, $target_lang, $source_url ) {
        if ( ! is_string( $html ) || stripos( $html, '<html' ) === false ) {
            return $html;
        }

        $cache_key = self::CACHE_PREFIX . md5( wp_json_encode( [
            'lang'      => $target_lang,
            'source'    => $source_url,
            'content'   => md5( $html ),
            'phrases'   => $this->options['preserve_phrases'] ?? '',
            'exclusion' => $this->options['exclude_selectors'] ?? '',
        ] ) );

        $cached = get_transient( $cache_key );
        if ( is_string( $cached ) && $cached !== '' ) {
            return $cached;
        }

        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT | LIBXML_PARSEHUGE );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            return $html;
        }

        $xpath = new DOMXPath( $dom );
        $this->translate_document( $xpath, $target_lang );
        $this->rewrite_internal_urls( $xpath, $target_lang );
        $this->update_document_metadata( $dom, $xpath, $target_lang, $source_url );

        $translated = $dom->saveHTML();
        if ( is_string( $translated ) && $translated !== '' ) {
            set_transient( $cache_key, $translated, WEEK_IN_SECONDS );
            return $translated;
        }

        return $html;
    }

    private function translate_document( DOMXPath $xpath, $target_lang ) {
        $items = [];

        foreach ( $xpath->query( '//text()' ) as $node ) {
            if ( ! $node instanceof DOMText ) continue;

            $text = $node->nodeValue;
            if ( trim( preg_replace( '/\s+/u', ' ', $text ) ) === '' ) continue;
            if ( $this->should_skip_node( $node ) ) continue;

            $items[] = [
                'type'    => 'text',
                'node'    => $node,
                'value'   => $text,
            ];
        }

        $attribute_queries = [
            '//*[@placeholder]' => 'placeholder',
            '//*[@title]'       => 'title',
            '//*[@aria-label]'  => 'aria-label',
            '//img[@alt]'       => 'alt',
            '//meta[@name="description"]' => 'content',
            '//meta[@property="og:title"]' => 'content',
            '//meta[@property="og:description"]' => 'content',
            '//meta[@name="twitter:title"]' => 'content',
            '//meta[@name="twitter:description"]' => 'content',
            '//title' => '__text__',
        ];

        foreach ( $attribute_queries as $query => $attr ) {
            foreach ( $xpath->query( $query ) as $node ) {
                if ( ! $node instanceof DOMNode ) continue;
                if ( $this->should_skip_node( $node ) ) continue;

                if ( $attr === '__text__' ) {
                    $value = $node->textContent;
                    if ( trim( $value ) === '' ) continue;
                    $items[] = [
                        'type'  => 'title',
                        'node'  => $node,
                        'value' => $value,
                    ];
                    continue;
                }

                if ( ! $node instanceof DOMElement ) continue;

                $value = $node->getAttribute( $attr );
                if ( trim( $value ) === '' ) continue;

                $items[] = [
                    'type'  => 'attr',
                    'node'  => $node,
                    'attr'  => $attr,
                    'value' => $value,
                ];
            }
        }

        if ( empty( $items ) ) {
            return;
        }

        $preserve = $this->parse_lines( $this->options['preserve_phrases'] ?? '' );
        $meta = [];
        $payload = [];

        foreach ( $items as $index => $item ) {
            $tokenized = $this->tokenize_preserve_phrases( $item['value'], $preserve );
            $payload[] = $tokenized['text'];
            $meta[ $index ] = $tokenized['replacements'];
        }

        $chunks = array_chunk( $payload, 40 );
        $translated = [];
        foreach ( $chunks as $chunk ) {
            $result = $this->provider->translate_texts( $chunk, $target_lang, $this->language->get_default() );
            if ( is_wp_error( $result ) ) {
                return;
            }
            $translated = array_merge( $translated, $result );
        }

        foreach ( $items as $index => $item ) {
            $value = $translated[ $index ] ?? $item['value'];
            $value = $this->restore_preserve_phrases( $value, $meta[ $index ] ?? [] );

            if ( $item['type'] === 'text' ) {
                $item['node']->nodeValue = $value;
            } elseif ( $item['type'] === 'title' ) {
                $item['node']->textContent = $value;
            } elseif ( $item['type'] === 'attr' && $item['node'] instanceof DOMElement ) {
                $item['node']->setAttribute( $item['attr'], $value );
            }
        }
    }

    private function rewrite_internal_urls( DOMXPath $xpath, $target_lang ) {
        $nodes = $xpath->query( '//a[@href] | //form[@action]' );
        foreach ( $nodes as $node ) {
            if ( ! $node instanceof DOMElement ) continue;
            if ( $this->should_skip_url_rewrite( $node ) ) continue;

            $attr = $node->tagName === 'form' ? 'action' : 'href';
            $url  = $node->getAttribute( $attr );
            if ( ! $url ) continue;

            $localized = $this->language->localize_url( $url, $target_lang );
            if ( $localized !== $url ) {
                $node->setAttribute( $attr, $localized );
            }
        }
    }

    private function update_document_metadata( DOMDocument $dom, DOMXPath $xpath, $target_lang, $source_url ) {
        $html = $dom->getElementsByTagName( 'html' )->item( 0 );
        if ( $html instanceof DOMElement ) {
            $html->setAttribute( 'lang', $target_lang );
        }

        $head = $dom->getElementsByTagName( 'head' )->item( 0 );
        if ( ! $head instanceof DOMElement ) {
            return;
        }

        foreach ( $xpath->query( '//link[@rel="canonical"] | //link[@rel="alternate"][@hreflang]' ) as $link ) {
            if ( $link instanceof DOMNode && $link->parentNode ) {
                $link->parentNode->removeChild( $link );
            }
        }

        $canonical = $dom->createElement( 'link' );
        $canonical->setAttribute( 'rel', 'canonical' );
        $canonical->setAttribute( 'href', $this->language->localize_url( $source_url, $target_lang ) );
        $head->appendChild( $canonical );

        foreach ( $this->language->get_enabled() as $code => $label ) {
            $alt = $dom->createElement( 'link' );
            $alt->setAttribute( 'rel', 'alternate' );
            $alt->setAttribute( 'hreflang', $code );
            $alt->setAttribute( 'href', $this->language->localize_url( $source_url, $code ) );
            $head->appendChild( $alt );
        }

        $xdefault = $dom->createElement( 'link' );
        $xdefault->setAttribute( 'rel', 'alternate' );
        $xdefault->setAttribute( 'hreflang', 'x-default' );
        $xdefault->setAttribute( 'href', $this->language->localize_url( $source_url, $this->language->get_default() ) );
        $head->appendChild( $xdefault );
    }

    private function should_skip_node( DOMNode $node ) {
        static $skip_tags = [ 'script', 'style', 'noscript', 'textarea', 'option', 'code', 'pre', 'svg' ];

        $current = $node instanceof DOMText ? $node->parentNode : $node;
        while ( $current instanceof DOMNode ) {
            if ( $current instanceof DOMElement ) {
                if ( in_array( strtolower( $current->tagName ), $skip_tags, true ) ) return true;

                $class = ' ' . preg_replace( '/\s+/', ' ', (string) $current->getAttribute( 'class' ) ) . ' ';
                if ( strpos( $class, ' skiptranslate ' ) !== false || strpos( $class, ' notranslate ' ) !== false ) {
                    return true;
                }

                $id = (string) $current->getAttribute( 'id' );
                if ( $id && $this->matches_exclusion_selectors( $current, $class, $id ) ) {
                    return true;
                }
            }

            $current = $current->parentNode;
        }

        return false;
    }

    private function matches_exclusion_selectors( DOMElement $node, $class_attr, $id ) {
        foreach ( $this->parse_lines( $this->options['exclude_selectors'] ?? '' ) as $selector ) {
            if ( strpos( $selector, '.' ) === 0 ) {
                $class_name = substr( $selector, 1 );
                if ( $class_name && strpos( $class_attr, ' ' . $class_name . ' ' ) !== false ) {
                    return true;
                }
            } elseif ( strpos( $selector, '#' ) === 0 ) {
                if ( $id === substr( $selector, 1 ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function should_skip_url_rewrite( DOMElement $node ) {
        $attr = $node->tagName === 'form' ? 'action' : 'href';
        $url  = $node->getAttribute( $attr );
        if ( ! $url ) return true;

        $class = ' ' . preg_replace( '/\s+/', ' ', (string) $node->getAttribute( 'class' ) ) . ' ';
        if ( strpos( $class, ' anchor-translate-link ' ) !== false ) {
            return true;
        }

        if ( preg_match( '#^(mailto|tel|javascript):#i', $url ) ) return true;
        if ( strpos( $url, '#' ) === 0 ) return true;
        if ( strpos( $url, '/wp-admin/' ) !== false ) return true;
        if ( strpos( $url, '/wp-json/' ) !== false ) return true;
        if ( strpos( $url, '/wp-content/' ) !== false ) return true;

        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( $path && preg_match( '/\.(?:jpg|jpeg|png|gif|svg|webp|avif|css|js|woff2?|ttf|eot|pdf|zip|mp4|mp3|webm|xml)$/i', $path ) ) {
            return true;
        }

        return false;
    }

    private function tokenize_preserve_phrases( $text, array $phrases ) {
        $working = (string) $text;
        $tokens  = [];

        foreach ( $phrases as $phrase ) {
            if ( ! $phrase ) continue;

            $regex = '/' . preg_quote( $phrase, '/' ) . '/u';
            $working = preg_replace_callback( $regex, function( $matches ) use ( &$tokens ) {
                $token = '__ANCHOR_TRANSLATE_TOKEN_' . count( $tokens ) . '__';
                $tokens[] = [
                    'token' => $token,
                    'value' => $matches[0],
                ];
                return $token;
            }, $working );
        }

        return [
            'text'         => $working,
            'replacements' => $tokens,
        ];
    }

    private function restore_preserve_phrases( $text, array $tokens ) {
        $restored = (string) $text;
        foreach ( $tokens as $token ) {
            $restored = str_replace( $token['token'], $token['value'], $restored );
        }
        return $restored;
    }

    private function parse_lines( $value ) {
        if ( ! is_string( $value ) || $value === '' ) return [];
        $lines = array_filter( array_map( 'trim', preg_split( '/\r?\n/', $value ) ) );
        usort( $lines, static function( $a, $b ) {
            return strlen( $b ) <=> strlen( $a );
        } );
        return $lines;
    }
}
