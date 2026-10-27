<?php
/**
 * Read-only REST feed for the member app (spec 2026-10-12-events-feed-api).
 *
 * The app mirrors the site, so this class decides nothing new: which events
 * exist in a range is Module::range_query_args(); what a row says is
 * Event_Schema::for_event(); who may see a gated event is
 * Entitlements::meets_prerequisites(); registration state is
 * Module::bookability(). It only selects, maps and serves.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class Feed {

    const SUMMARY_MAX = 300;

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
    }

    /* ── Selection ─────────────────────────────────────────────── */

    /**
     * Event IDs the current user may see in [from, to], one per actual date.
     *
     * @param int   $from_ts
     * @param int   $to_ts
     * @param int[] $term_ids event_category term IDs; [] = no filter.
     * @return int[]
     */
    public function visible_ids( $from_ts, $to_ts, array $term_ids ) {
        $term_ids = array_map( 'intval', $term_ids );
        $rows     = [];
        $in_range = $this->module->event_ids_in_range( $from_ts, $to_ts, true, true );
        if ( $in_range ) {
            // One query each instead of one per event in the loop below.
            \_prime_post_caches( $in_range, false, false );
            \update_meta_cache( 'post', $in_range );
        }
        foreach ( $in_range as $id ) {
            if ( $this->is_container( $id ) ) {
                continue;
            }
            if ( $this->is_password_protected( $id ) ) {
                continue;
            }
            if ( ! $this->module->entitlements->meets_prerequisites( $id, 0 ) ) {
                continue;
            }
            if ( $term_ids && ! array_intersect( $term_ids, \wp_list_pluck( $this->category_terms( $id ), 'term_id' ) ) ) {
                continue;
            }
            // The one "this id yields a row" check, shared with Event_Schema's
            // single-event node (Module::has_usable_start). /events paging,
            // /categories and /me all count from this list. row() keeps its
            // null check as a safety net for anything the node rejects later.
            if ( ! $this->module->has_usable_start( $id ) ) {
                continue;
            }
            $start = (int) \get_post_meta( $id, $this->module->meta_key( 'start_ts' ), true );
            $rows[] = [ $start, $id ];
        }
        usort( $rows, static function ( $a, $b ) {
            return [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ];
        } );
        return array_column( $rows, 1 );
    }

    /**
     * Password on the event, or on a group child's parent (children are not
     * given the parent's password). The schema description reads post_content
     * whatever the password says, so these never reach the feed.
     */
    private function is_password_protected( $id ) {
        if ( \get_post_field( 'post_password', $id ) !== '' ) {
            return true;
        }
        $parent = (int) $this->module->occurrences->parent_of( $id );
        return $parent > 0 && \get_post_field( 'post_password', $parent ) !== '';
    }

    /**
     * A group parent (or an offering/recurring event not yet reconciled) is a
     * container, never a date — the feed's rows are its children.
     */
    private function is_container( $id ) {
        $occ = $this->module->occurrences;
        if ( $occ->is_group_child( $id ) ) {
            return false;
        }
        return $occ->is_group_parent( $id ) || in_array( $this->module->event_type( $id ), [ 'offering', 'recurring' ], true );
    }

    /**
     * The categories a row belongs to. Reconcile does not copy event_category
     * to children, so a child answers with its parent's terms.
     *
     * @return \WP_Term[]
     */
    public function category_terms( $event_id ) {
        $source = (int) $event_id;
        if ( $this->module->occurrences->is_group_child( $source ) ) {
            $parent = (int) $this->module->occurrences->parent_of( $source );
            if ( $parent > 0 ) {
                $source = $parent;
            }
        }
        $terms = \get_the_terms( $source, 'event_category' );
        return is_array( $terms ) ? array_values( $terms ) : [];
    }

    /**
     * The App categories setting, minus terms deleted since it was saved.
     *
     * @return int[]
     */
    public function app_term_ids() {
        return self::existing_category_ids( (array) ( $this->module->get_settings()['app_categories'] ?? [] ) );
    }

    /**
     * Only the IDs that are real event_category terms, re-indexed.
     *
     * @param array $ids
     * @return int[]
     */
    public static function existing_category_ids( array $ids ) {
        return array_values( array_filter( array_map( 'absint', $ids ), static function ( $id ) {
            return $id > 0 && \get_term( $id, 'event_category' ) instanceof \WP_Term;
        } ) );
    }

    public function is_restricted( $event_id ) {
        return $this->module->entitlements->has_prerequisites( (int) $event_id );
    }

    /* ── Mapping ───────────────────────────────────────────────── */

    /**
     * One feed row, or null when the event has no usable schema node.
     *
     * @return array|null
     */
    public function row( $event_id ) {
        $id   = (int) $event_id;
        $node = $this->module->event_schema->for_event( $id );
        if ( empty( $node['startDate'] ) ) {
            return null;
        }
        $meta = $this->module->get_meta( $id );
        $mode = (string) $this->module->registration_mode( $id );
        list( $price, $currency, $price_max ) = $this->price( $node['offers'] ?? [] );

        return [
            'id'           => $id,
            'parent_id'    => $this->module->occurrences->is_group_child( $id ) ? (int) $this->module->occurrences->parent_of( $id ) : 0,
            'title'        => $this->plain( $node['name'] ?? \get_the_title( $id ) ),
            'summary'      => $this->summary( $node['description'] ?? '' ),
            'url'          => (string) \get_permalink( $id ),
            'image'        => isset( $node['image'] ) && is_string( $node['image'] ) ? $node['image'] : null,
            'categories'   => array_map( static function ( $t ) {
                return [ 'slug' => (string) $t->slug, 'name' => \html_entity_decode( (string) $t->name, \ENT_QUOTES, 'UTF-8' ) ];
            }, $this->category_terms( $id ) ),
            'start'        => (string) $node['startDate'],
            'end'          => (string) ( $node['endDate'] ?? $node['startDate'] ),
            // Date-only start = all day; the node is the one place that decides (themes may trim it).
            'all_day'      => $this->is_date( (string) $node['startDate'] ),
            'sessions'     => $this->sessions( $id, $node ),
            'timezone'     => $this->module->event_timezone( $meta )->getName(),
            'location'     => $this->location( $node, (string) ( $meta['venue'] ?? '' ) ),
            'registration' => [
                'mode'         => [ 'wc' => 'paid', 'free' => 'free', 'external' => 'external' ][ $mode ] ?? 'free',
                'status'       => $this->map_status( (string) $this->module->bookability( $id ), $mode ),
                'price'        => $price,
                'price_max'    => $price_max,
                'currency'     => $currency,
                'register_url' => (string) \get_permalink( $id ),
            ],
            'audience'     => $this->is_restricted( $id ) ? 'restricted' : 'public',
        ];
    }

    /**
     * The per-session spans of a multisession node (a standalone event, or a
     * group child switched to multisession), in date order; [] for anything
     * else. Dates come from the node's subEvents (the node decides, as for
     * start/end); labels are the RAW session labels, '' when none — unlike the
     * subEvent name, which falls back to the event title. A 2-day session is two
     * single-day rows sharing a label; the app groups them.
     *
     * @return array[] `{start, end, all_day, label}` each.
     */
    private function sessions( $id, array $node ) {
        $subs = $node['subEvent'] ?? [];
        if ( ! is_array( $subs ) || ! $subs ) {
            return [];
        }
        // subEvents are built from usable_sessions() in order; pair labels by position.
        $labels = array_column( $this->module->event_schema->usable_sessions( $id ), 'label' );
        $labels = count( $labels ) === count( $subs ) ? $labels : [];
        $out    = [];
        foreach ( array_values( $subs ) as $i => $sub ) {
            if ( empty( $sub['startDate'] ) ) {
                continue;
            }
            $out[] = [
                'start'   => (string) $sub['startDate'],
                'end'     => (string) ( $sub['endDate'] ?? $sub['startDate'] ),
                'all_day' => $this->is_date( (string) $sub['startDate'] ),
                'label'   => (string) ( $labels[ $i ] ?? '' ),
            ];
        }
        usort( $out, static function ( $a, $b ) {
            return strcmp( $a['start'], $b['start'] );
        } );
        return $out;
    }

    /**
     * bookability() → the feed's status vocabulary (spec "Registration
     * vocabulary"). An external-signup event takes sign-ups elsewhere, so the
     * native switch/seat states say nothing about it — only "closed" does.
     */
    public function map_status( $bookability, $mode ) {
        if ( $mode === 'external' ) {
            return $bookability === 'closed' ? 'closed' : 'open';
        }
        $map = [ 'open' => 'open', 'waitlist' => 'waitlist', 'full' => 'sold_out', 'disabled' => 'unavailable' ];
        return $map[ $bookability ] ?? 'closed';
    }

    /** @return array{0:?string,1:?string,2:?string} Lowest offer price, its currency, and the highest offer price. */
    private function price( $offers ) {
        if ( isset( $offers['@type'] ) ) {
            $offers = [ $offers ];
        }
        $best = null;
        $max  = null;
        foreach ( (array) $offers as $offer ) {
            if ( ! is_array( $offer ) || ! isset( $offer['price'] ) || ! is_numeric( $offer['price'] ) ) {
                continue;
            }
            if ( $best === null || (float) $offer['price'] < (float) $best['price'] ) {
                $best = $offer;
            }
            if ( $max === null || (float) $offer['price'] > $max ) {
                $max = (float) $offer['price'];
            }
        }
        if ( $best === null ) {
            return [ null, null, null ];
        }
        return [
            number_format( (float) $best['price'], 2, '.', '' ),
            isset( $best['priceCurrency'] ) ? (string) $best['priceCurrency'] : null,
            number_format( $max, 2, '.', '' ),
        ];
    }

    /**
     * Venue/city/state/country from the node's Place; online from its VirtualLocation. Never the URL.
     * Event_Schema names the Place after the post title when there is no venue,
     * so the venue is only trusted when the event's own venue meta is set.
     */
    private function location( array $node, $venue_meta ) {
        $loc   = $node['location'] ?? [];
        $nodes = isset( $loc['@type'] ) ? [ $loc ] : (array) $loc;
        $out   = [ 'venue' => '', 'city' => '', 'state' => '', 'country' => '', 'online' => false ];
        foreach ( $nodes as $n ) {
            if ( ! is_array( $n ) ) {
                continue;
            }
            if ( ( $n['@type'] ?? '' ) === 'VirtualLocation' ) {
                $out['online'] = true;
            } elseif ( ( $n['@type'] ?? '' ) === 'Place' ) {
                $out['venue']   = trim( (string) $venue_meta ) === '' ? '' : $this->plain( $n['name'] ?? '' );
                $out['city']    = (string) ( $n['address']['addressLocality'] ?? '' );
                $out['state']   = (string) ( $n['address']['addressRegion'] ?? '' );
                $out['country'] = (string) ( $n['address']['addressCountry'] ?? '' );
            }
        }
        return $out;
    }

    private function plain( $text ) {
        return trim( \wp_strip_all_tags( \html_entity_decode( (string) $text, \ENT_QUOTES, 'UTF-8' ) ) );
    }

    private function summary( $text ) {
        // Descriptions can carry shortcodes, including ones no longer registered (e.g. a retired
        // [event_registration]); strip_shortcodes() only knows registered tags. Also drop bracketed tags that
        // look like shortcodes (closing tags, snake/kebab-case names, or attributes) but keep prose like [sic].
        $text = \strip_shortcodes( (string) $text );
        $text = \preg_replace( '/\[\/[a-z][a-z0-9_-]*\]|\[[a-z][a-z0-9]*[_-][a-z0-9_-]*(?:\s[^\]]*)?\]|\[[a-z][a-z0-9_-]*\s[^\]]*=[^\]]*\]/i', '', $text );
        $text = \preg_replace( '/\s{2,}/', ' ', $this->plain( $text ) );
        if ( mb_strlen( $text ) <= self::SUMMARY_MAX ) {
            return $text;
        }
        return rtrim( mb_substr( $text, 0, self::SUMMARY_MAX - 1 ) ) . '…';
    }

    /* ── REST ──────────────────────────────────────────────────── */

    const DEFAULT_MONTHS = 12;
    const MAX_MONTHS     = 24;

    /** Route of the request being dispatched (recorded for filter_nocache_headers()). */
    private $dispatching = '';

    public function register_routes() {
        \add_filter( 'rest_pre_dispatch', [ $this, 'note_route' ], 10, 3 );
        \add_filter( 'rest_send_nocache_headers', [ $this, 'filter_nocache_headers' ] );
        $public = '__return_true';
        register_rest_route( Module::REST_NS, '/events', [
            'methods'             => 'GET',
            'permission_callback' => $public,
            'callback'            => [ $this, 'rest_events' ],
            'args'                => [
                'from'     => [ 'type' => 'string' ],
                'to'       => [ 'type' => 'string' ],
                'category' => [ 'type' => 'string' ],
                'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'per_page' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            ],
        ] );
        register_rest_route( Module::REST_NS, '/categories', [
            'methods'             => 'GET',
            'permission_callback' => $public,
            'callback'            => [ $this, 'rest_categories' ],
        ] );
        register_rest_route( Module::REST_NS, '/me', [
            'methods'             => 'GET',
            // Refused inside the callback so the 401 carries our own code.
            'permission_callback' => $public,
            'callback'            => [ $this, 'rest_me' ],
        ] );
    }

    /** @internal rest_pre_dispatch: remember which route is being served; changes nothing. */
    public function note_route( $result, $server, $request ) {
        $this->dispatching = $request instanceof \WP_REST_Request ? $request->get_route() : '';
        return $result;
    }

    /**
     * serve_request() sends no-cache headers for any signed-in caller (an
     * Application Password counts) AFTER our response headers, overwriting
     * `Cache-Control: private, max-age=300`. These three routes are already
     * private (restricted rows depend on who asks), so they opt out.
     */
    public function filter_nocache_headers( $send ) {
        $own = '/' . Module::REST_NS;
        return in_array( $this->dispatching, [ $own . '/events', $own . '/categories', $own . '/me' ], true ) ? false : $send;
    }

    public function rest_events( \WP_REST_Request $request ) {
        $range = $this->parse_range( $request['from'], $request['to'] );
        if ( is_wp_error( $range ) ) {
            return $range;
        }
        $terms = $this->parse_categories( $request['category'] );
        if ( is_wp_error( $terms ) ) {
            return $terms;
        }

        $ids      = $this->visible_ids( $range[0], $range[1], $terms );
        $per_page = (int) $request['per_page'];
        $page     = (int) $request['page'];
        $total    = count( $ids );
        $pages    = (int) ceil( $total / $per_page );

        // Rows (schema node, bookability) are built for the requested page only.
        $response = $this->respond( $request, [
            'events'      => $this->rows( array_slice( $ids, ( $page - 1 ) * $per_page, $per_page ) ),
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $pages,
        ] );
        $response->header( 'X-WP-Total', (string) $total );
        $response->header( 'X-WP-TotalPages', (string) $pages );
        return $response;
    }

    public function rest_categories( \WP_REST_Request $request ) {
        $range  = $this->parse_range( null, null );
        $counts = [];
        $terms  = [];
        foreach ( $this->visible_ids( $range[0], $range[1], $this->app_term_ids() ) as $id ) {
            foreach ( $this->category_terms( $id ) as $term ) {
                $counts[ $term->term_id ] = ( $counts[ $term->term_id ] ?? 0 ) + 1;
                $terms[ $term->term_id ]  = $term;
            }
        }
        $allowed = $this->app_term_ids();
        $out     = [];
        foreach ( $terms as $term_id => $term ) {
            if ( $allowed && ! in_array( (int) $term_id, $allowed, true ) ) {
                continue;
            }
            $out[] = [ 'slug' => (string) $term->slug, 'name' => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ), 'count' => (int) $counts[ $term_id ] ];
        }
        usort( $out, static function ( $a, $b ) {
            return strcasecmp( $a['name'], $b['name'] );
        } );
        return $this->respond( $request, [ 'categories' => $out ] );
    }

    public function rest_me( \WP_REST_Request $request ) {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return new \WP_Error( 'anchor_events_not_signed_in', __( 'Sign in to see your events.', 'anchor-schema' ), [ 'status' => 401 ] );
        }
        $from = $this->day_start( wp_date( 'Y-m-d' ) );
        $to   = $this->day_start( wp_date( 'Y-m-d', strtotime( '+' . self::MAX_MONTHS . ' months' ) ) ) - 1;
        $sees = false;
        foreach ( $this->visible_ids( $from, $to, [] ) as $id ) {
            if ( $this->is_restricted( $id ) ) {
                $sees = true;
                break;
            }
        }
        return $this->respond( $request, [ 'name' => (string) $user->display_name, 'sees_restricted' => $sees ] );
    }

    /** Rows for IDs, skipping any event whose schema node turns out unusable (rare). */
    private function rows( array $ids ) {
        $rows = [];
        foreach ( $ids as $id ) {
            $row = $this->row( $id );
            if ( $row !== null ) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @return int[]|\WP_Error [from_ts, to_ts] in the site zone, inclusive.
     */
    private function parse_range( $from, $to ) {
        $bad  = new \WP_Error( 'anchor_events_bad_range', __( 'Use from/to as YYYY-MM-DD, with to on or after from and no more than 24 months apart.', 'anchor-schema' ), [ 'status' => 400 ] );
        $from = ( $from === null || $from === '' ) ? wp_date( 'Y-m-d' ) : (string) $from;
        if ( ! $this->is_date( $from ) ) {
            return $bad;
        }
        $to = ( $to === null || $to === '' ) ? gmdate( 'Y-m-d', strtotime( $from . ' +' . self::DEFAULT_MONTHS . ' months' ) ) : (string) $to;
        if ( ! $this->is_date( $to ) || $to < $from || $to > gmdate( 'Y-m-d', strtotime( $from . ' +' . self::MAX_MONTHS . ' months' ) ) ) {
            return $bad;
        }
        return [ $this->day_start( $from ), $this->day_start( gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) ) - 1 ];
    }

    private function is_date( $s ) {
        return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $s, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    /** Midnight of a YYYY-MM-DD in the site zone, through the module's own converter. */
    private function day_start( $date ) {
        return (int) $this->module->to_timestamp( $date, '00:00', '' );
    }

    /** @return int[]|\WP_Error */
    private function parse_categories( $param ) {
        $param = trim( (string) $param );
        if ( $param === '' ) {
            return $this->app_term_ids();
        }
        $ids = [];
        foreach ( array_filter( array_map( 'trim', explode( ',', $param ) ) ) as $slug ) {
            $term = get_term_by( 'slug', sanitize_title( $slug ), 'event_category' );
            if ( ! $term instanceof \WP_Term ) {
                return new \WP_Error( 'anchor_events_bad_category', sprintf( __( 'Unknown event category: %s', 'anchor-schema' ), $slug ), [ 'status' => 400 ] );
            }
            $ids[] = (int) $term->term_id;
        }
        return $ids;
    }

    /**
     * 200 with private caching + a body-hash ETag, or 304 when the client
     * already has it. Body hash, not the cache generation: a seat sale moves
     * registration.status without bumping the generation. This is the one
     * place the feed's cache headers are set (200 and 304 alike). `Vary:
     * Authorization` because the public and signed-in URLs are identical and
     * differ only by that header, so any HTTP cache must key on it.
     */
    private function respond( \WP_REST_Request $request, array $data ) {
        $etag     = 'W/"' . md5( (string) wp_json_encode( $data ) ) . '"';
        $match    = trim( (string) $request->get_header( 'if_none_match' ) );
        $response = ( $match !== '' && $match === $etag )
            ? new \WP_REST_Response( null, 304 )
            : new \WP_REST_Response( $data, 200 );
        $response->header( 'ETag', $etag );
        $response->header( 'Cache-Control', 'private, max-age=300' );
        $vary = array_filter( array_map( 'trim', explode( ',', (string) ( $response->get_headers()['Vary'] ?? '' ) ) ) );
        if ( ! in_array( 'authorization', array_map( 'strtolower', $vary ), true ) ) {
            $vary[] = 'Authorization';
        }
        $response->header( 'Vary', implode( ', ', $vary ) );
        return $response;
    }
}
