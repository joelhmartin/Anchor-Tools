<?php
/**
 * Provider-agnostic stream embed normaliser (virtual-events spec §5.4).
 *
 * The ONE place a pasted iframe or a bare URL becomes the `{provider, kind,
 * src, raw}` shape stored in `_anchor_event_stream_embed`. Nothing else in the
 * module is allowed to build that array, and nothing ever stores raw embed
 * HTML — the room re-renders the iframe itself from `src`, so a provider
 * changing its markup is a change here and nowhere else.
 *
 * Pure and static: no hooks, no post meta, no WordPress state beyond the
 * `anchor_events_embed_providers` filter and the escaping helpers.
 *
 * @package AnchorTools\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Embed {

    /** Error code for an input whose host is on no provider's list. */
    const ERR_UNKNOWN_HOST = 'anchor_events_embed_unknown_host';

    /**
     * The provider table: slug => { hosts[], kind, transform }.
     *
     * `kind` is `iframe` (frameable) or `link` (a button — Zoom sends
     * X-Frame-Options and refuses to be framed at all). `transform` receives
     * the parsed URL parts and returns the final src, or '' to refuse.
     *
     * @return array<string,array{hosts:string[],kind:string,transform:callable}>
     */
    public static function providers() {
        $providers = [
            'vimeo' => [
                'hosts'     => [ 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com' ],
                'kind'      => 'iframe',
                'transform' => static function ( $path, $query ) {
                    // /event/456 and /event/456/embed both become the event embed.
                    if ( \preg_match( '#^/event/([A-Za-z0-9]+)#', $path, $m ) ) {
                        return 'https://player.vimeo.com/event/' . $m[1] . '/embed' . self::query_suffix( $query );
                    }
                    // /video/123 (player URL) or /123 (share URL).
                    if ( \preg_match( '#^/(?:video/)?(\d+)#', $path, $m ) ) {
                        return 'https://player.vimeo.com/video/' . $m[1] . self::query_suffix( $query );
                    }
                    return '';
                },
            ],
            'youtube' => [
                'hosts'     => [ 'youtube.com', 'www.youtube.com', 'youtu.be', 'm.youtube.com', 'www.youtube-nocookie.com' ],
                'kind'      => 'iframe',
                'transform' => static function ( $path, $query ) {
                    $id = '';
                    if ( \preg_match( '#^/(?:embed|live|v|shorts)/([A-Za-z0-9_-]{6,})#', $path, $m ) ) {
                        $id = $m[1];
                    } elseif ( ! empty( $query['v'] ) ) {
                        $id = \preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $query['v'] );
                    } elseif ( \preg_match( '#^/([A-Za-z0-9_-]{11})$#', $path, $m ) ) {
                        $id = $m[1]; // youtu.be/<id> — YouTube ids are 11 chars.
                    }
                    return $id === '' ? '' : 'https://www.youtube-nocookie.com/embed/' . $id;
                },
            ],
            'zoom' => [
                'hosts'     => [ 'zoom.us' ],
                'kind'      => 'link',
                'transform' => static function ( $path, $query, $url ) {
                    return $url;
                },
            ],
        ];

        /**
         * The stream providers an event may embed.
         *
         * Add `'generic' => [ 'hosts' => [ 'stream.example.org' ], 'kind' =>
         * 'iframe', 'transform' => fn( $path, $query, $url ) => $url ]` to allow
         * a host this table does not know. Hosts are matched exactly OR as a
         * suffix (`us02web.zoom.us` matches `zoom.us`).
         *
         * @param array $providers slug => { hosts[], kind, transform }.
         */
        return (array) \apply_filters( 'anchor_events_embed_providers', $providers );
    }

    /**
     * Normalise pasted iframe HTML or a bare URL into the stored embed shape.
     *
     * @param string $input
     * @return array{provider:string,kind:string,src:string,raw:string}|\WP_Error
     */
    public static function normalize( $input ) {
        $raw = \trim( (string) $input );
        if ( $raw === '' ) {
            return new \WP_Error( self::ERR_UNKNOWN_HOST, \__( 'Paste a stream URL or the provider\'s iframe embed code.', 'anchor-schema' ) );
        }

        $url = $raw;
        if ( \stripos( $raw, '<iframe' ) !== false && \preg_match( '#<iframe[^>]+src=["\']([^"\']+)["\']#i', $raw, $m ) ) {
            $url = \html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
        }

        $parts = \wp_parse_url( $url );
        // https only. A scheme-relative, http, javascript: or data: input is
        // refused outright rather than coerced — the value ends up in an
        // iframe src, so "probably fine" is not a standard we can apply.
        if ( empty( $parts['scheme'] ) || \strtolower( $parts['scheme'] ) !== 'https' || empty( $parts['host'] ) ) {
            return new \WP_Error(
                self::ERR_UNKNOWN_HOST,
                \__( 'A stream link must be a full https:// URL from a supported provider.', 'anchor-schema' )
            );
        }

        $host  = \strtolower( $parts['host'] );
        $path  = '/' . \ltrim( (string) ( $parts['path'] ?? '' ), '/' );
        $query = [];
        if ( ! empty( $parts['query'] ) ) {
            \parse_str( (string) $parts['query'], $query );
        }

        foreach ( self::providers() as $slug => $provider ) {
            foreach ( (array) ( $provider['hosts'] ?? [] ) as $candidate ) {
                $candidate = \strtolower( (string) $candidate );
                if ( $host !== $candidate && \substr( $host, - ( \strlen( $candidate ) + 1 ) ) !== '.' . $candidate ) {
                    continue;
                }
                $src = \call_user_func( $provider['transform'], $path, $query, $url );
                $src = \esc_url_raw( (string) $src, [ 'https' ] );
                if ( $src === '' ) {
                    return new \WP_Error(
                        self::ERR_UNKNOWN_HOST,
                        /* translators: %s: provider slug, e.g. vimeo. */
                        \sprintf( \__( 'That looks like a %s link, but no video or event id could be read from it.', 'anchor-schema' ), $slug )
                    );
                }
                return [
                    'provider' => (string) $slug,
                    'kind'     => ( ( $provider['kind'] ?? 'iframe' ) === 'link' ) ? 'link' : 'iframe',
                    'src'      => $src,
                    'raw'      => $raw,
                ];
            }
        }

        return new \WP_Error(
            self::ERR_UNKNOWN_HOST,
            /* translators: %s: the host that was rejected. */
            \sprintf( \__( '%s is not a supported stream provider. Supported: Vimeo, YouTube, Zoom — or add your own host with the anchor_events_embed_providers filter.', 'anchor-schema' ), $host )
        );
    }

    /**
     * The player markup for a normalised embed. `link` providers render a
     * button; everything else renders a 16:9 iframe.
     *
     * @param array  $embed {provider,kind,src,raw}
     * @param string $title Event title, used as the iframe's accessible name.
     * @return string Escaped HTML, '' when there is nothing to render.
     */
    public static function render( array $embed, $title = '' ) {
        $src = (string) ( $embed['src'] ?? '' );
        if ( $src === '' ) {
            return '';
        }
        if ( ( $embed['kind'] ?? 'iframe' ) === 'link' ) {
            return '<p class="anchor-room-join"><a class="anchor-event-button anchor-room-join-link" href="'
                . \esc_url( $src ) . '" target="_blank" rel="noopener">'
                /* translators: %s: provider name, e.g. Zoom. */
                . \esc_html( \sprintf( \__( 'Join on %s', 'anchor-schema' ), \ucfirst( (string) ( $embed['provider'] ?? 'the provider' ) ) ) )
                . '</a></p>';
        }
        return '<div class="anchor-room-player"><iframe src="' . \esc_url( $src ) . '"'
            . ' allow="autoplay; fullscreen; picture-in-picture; encrypted-media"'
            . ' allowfullscreen referrerpolicy="strict-origin-when-cross-origin" loading="eager"'
            . ' title="' . \esc_attr( $title ) . '"></iframe></div>';
    }

    /** Re-attach a query string, preserving only the keys a provider needs. */
    private static function query_suffix( array $query ) {
        $keep = \array_intersect_key( $query, \array_flip( [ 'h', 'badge', 'autopause', 'player_id', 'app_id' ] ) );
        return empty( $keep ) ? '' : '?' . \http_build_query( $keep );
    }
}
