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
                    // /event/456, /event/456/embed, /event/456/embed/interaction (video + chat) and an unlisted
                    // /event/456/embed/<hash> all become the video-only event embed. It lives on vimeo.com:
                    // player.vimeo.com/event/<id>/embed answers 404 (checked 2026-11-11).
                    if ( \preg_match( '#^/event/([0-9]+)(?:/embed(?:/(?!interaction\b)([A-Za-z0-9]+))?)?#', $path, $m ) ) {
                        return self::vimeo_event_src( $m[1], $m[2] ?? '' ) . self::query_suffix( $query );
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
        $src = self::current_src( (string) ( $embed['src'] ?? '' ) );
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
        // The player IS the service the attendee registered and signed in for:
        // the compliance module's consent blocker must leave it alone (its
        // per-element opt-out, Anchor_Compliance_Script_Blocker::EXEMPT_ATTR),
        // while everything else on the room page stays gated as usual.
        return '<div class="anchor-room-player"><iframe src="' . \esc_url( $src ) . '"'
            . ' data-anchor-consent-exempt="stream"'
            . ' allow="autoplay; fullscreen; picture-in-picture; encrypted-media"'
            . ' allowfullscreen referrerpolicy="strict-origin-when-cross-origin" loading="eager"'
            . ' title="' . \esc_attr( $title ) . '"></iframe></div>';
    }

    /**
     * Normalise a pasted chat embed (iframe or URL) into the stored shape. Chat is its own embed because
     * providers ship it as a separate iframe: Vimeo event chat (vimeo.com/event/<id>/chat[/<hash>]) and
     * YouTube live chat (youtube.com/live_chat?v=<id>). Nothing else is accepted.
     *
     * @param string $input
     * @return array{provider:string,kind:string,src:string,raw:string}|\WP_Error
     */
    public static function normalize_chat( $input ) {
        $raw = \trim( (string) $input );
        $url = $raw;
        if ( \stripos( $raw, '<iframe' ) !== false && \preg_match( '#<iframe[^>]+src=["\']([^"\']+)["\']#i', $raw, $m ) ) {
            $url = \html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
        }
        $parts = \wp_parse_url( $url );
        $host  = \strtolower( (string) ( $parts['host'] ?? '' ) );
        $path  = '/' . \ltrim( (string) ( $parts['path'] ?? '' ), '/' );
        $query = [];
        if ( ! empty( $parts['query'] ) ) {
            \parse_str( (string) $parts['query'], $query );
        }
        $https = \strtolower( (string) ( $parts['scheme'] ?? '' ) ) === 'https';

        $src = '';
        $provider = '';
        // Vimeo: the chat-only widget is vimeo.com/live/interaction_tools/live_event/<id> (the event's /chat link
        // redirects there). Accept that, the /chat link, or any event link or embed code, so the same embed code
        // can be pasted into both boxes.
        if ( $https && \in_array( $host, [ 'vimeo.com', 'www.vimeo.com' ], true )
            && ( \preg_match( '#^/live/interaction_tools/live_event/([0-9]+)/?$#', $path, $m )
                || \preg_match( '#^/event/([0-9]+)(?:/(?:chat|interaction|embed(?:/interaction)?))?/?$#', $path, $m ) ) ) {
            $provider = 'vimeo';
            $src      = 'https://vimeo.com/live/interaction_tools/live_event/' . $m[1];
        } elseif ( $https && \in_array( $host, [ 'youtube.com', 'www.youtube.com' ], true ) && $path === '/live_chat'
            && ! empty( $query['v'] ) && \preg_match( '/^[A-Za-z0-9_-]{6,}$/', (string) $query['v'] ) ) {
            $provider = 'youtube';
            $src      = 'https://www.youtube.com/live_chat?v=' . $query['v'] . '&embed_domain=' . \rawurlencode( (string) \wp_parse_url( \home_url(), PHP_URL_HOST ) );
        }

        if ( $src === '' ) {
            return new \WP_Error(
                self::ERR_UNKNOWN_HOST,
                \__( 'Paste the chat embed code from a Vimeo event (vimeo.com/event/…/chat) or a YouTube live chat link.', 'anchor-schema' )
            );
        }
        return [ 'provider' => $provider, 'kind' => 'iframe', 'src' => \esc_url_raw( $src, [ 'https' ] ), 'raw' => $raw ];
    }

    /**
     * The chat panel markup for a normalised chat embed, '' when there is none.
     *
     * @param array  $chat  {provider,kind,src,raw}
     * @param string $title Event title, for the iframe's accessible name.
     * @return string
     */
    public static function render_chat( array $chat, $title = '' ) {
        $src = (string) ( $chat['src'] ?? '' );
        if ( $src === '' ) {
            return '';
        }
        /* translators: %s: event title */
        $label = \sprintf( \__( 'Chat: %s', 'anchor-schema' ), $title );
        return '<div class="anchor-room-chat"><iframe src="' . \esc_url( $src ) . '"'
            . ' data-anchor-consent-exempt="stream"'
            . ' referrerpolicy="strict-origin-when-cross-origin" loading="eager"'
            . ' title="' . \esc_attr( $label ) . '"></iframe></div>';
    }

    /** The video-only Vimeo event embed. */
    private static function vimeo_event_src( $id, $hash = '' ) {
        return 'https://vimeo.com/event/' . $id . '/embed' . ( $hash !== '' ? '/' . $hash : '' );
    }

    /**
     * A stored src in today's form. Events saved before 3.46.0 hold player.vimeo.com/event/<id>/embed, which
     * Vimeo now answers with 404; this upgrades them at render time so nobody has to re-save every event.
     */
    public static function current_src( $src ) {
        if ( \preg_match( '#^https://player\.vimeo\.com/event/([0-9]+)/embed(?:/([A-Za-z0-9]+))?(\?.*)?$#', (string) $src, $m ) ) {
            return self::vimeo_event_src( $m[1], $m[2] ?? '' ) . ( $m[3] ?? '' );
        }
        return (string) $src;
    }

    /** Re-attach a query string, preserving only the keys a provider needs. */
    private static function query_suffix( array $query ) {
        $keep = \array_intersect_key( $query, \array_flip( [ 'h', 'badge', 'autopause', 'player_id', 'app_id' ] ) );
        return empty( $keep ) ? '' : '?' . \http_build_query( $keep );
    }
}
