<?php
/**
 * Event_Schema — schema.org/Event JSON-LD DATA builder (Phase 4, Task 4.1).
 *
 * One responsibility: given an event post id, project the event's existing
 * meta (dates/timezone/location/registration) into a schema.org/Event node
 * as a plain, json_encode-ready associative array. Pure read-only data
 * projection — it never writes meta, never touches seats/reconcile/senders,
 * and has no WordPress hooks of its own (no wp_head, no output). Front-end
 * emission (wp_head/template hook, de-dupe against the parent Anchor Schema
 * plugin's own Event handling) is Task 4.2 — NOT this file.
 *
 * OUTPUT CONTRACT: for_event() never includes an `@context` key. The
 * returned array is a bare node with `'@type' => 'Event'` (or 'Event' on
 * every subEvent too), ready to be either (a) merged with
 * ['@context' => 'https://schema.org'] for a standalone <script> tag, or
 * (b) dropped as-is into a `@graph` array under a single shared top-level
 * `@context`. That decision belongs to Task 4.2's emitter, not here.
 *
 * DATA, NOT HTML: every string value returned (name/description/address
 * fields/urls) is clean, un-escaped data. Callers MUST run it through
 * wp_json_encode() (which safely escapes for embedding in a <script> tag);
 * this class deliberately does NOT htmlspecialchars/esc_html anything.
 *
 * TYPE DISPATCH (occurrence = event post; see class docblock in
 * class-occurrences.php for the parent/child data model this reads):
 *   - group CHILD (Occurrences::is_group_child())      -> its own standalone node.
 *   - group PARENT (Occurrences::is_group_parent(), or
 *     _anchor_event_type is 'offering'/'recurring')     -> a node whose
 *     `subEvent` is for_event() of every LIVE (non-soft-closed) child, so
 *     the parent's page carries every date in structured data — this is the
 *     fix for "Google only sees one date" on a Pick-one-offering/recurring
 *     event. The parent's own startDate/endDate are taken from the
 *     EARLIEST live child (not the parent's own, usually-empty, dates).
 *     Zero live children -> [] (nothing to advertise).
 *   - `multisession` (_anchor_event_type)                -> one node whose
 *     `subEvent` is one minimal Event stub per get_sessions() row; the
 *     parent's own startDate = earliest session, endDate = latest session
 *     end (spans the whole event).
 *   - anything else ("single")                           -> one plain node.
 *
 * OFFERS (build_offers(), keyed off Module::registration_mode()):
 *   - wc: one Offer per ACTIVE ticket tier (Ticket_Types::get()), priced
 *     from the tier's own price. `availability` is derived from the EVENT's
 *     overall remaining capacity (Registrations::remaining_capacity() — one
 *     cheap cached count query), not a per-tier count, to keep this a single
 *     query regardless of tier count (documented simplification). No active
 *     tiers -> no offers key.
 *   - external: one Offer with `url` = external_url (or the permalink) and
 *     `price` parsed from the free-text external_display_price ("$495" ->
 *     495) when a numeric substring is found; price is omitted (not "0")
 *     when unparseable, so we never fabricate a price.
 *   - free (default): one Offer with price 0 — chosen over omitting offers
 *     entirely because Google's Event guidance treats a present, zero-price
 *     Offer as the canonical "free to attend" signal for rich results.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class Event_Schema {

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
    }

    /* ═══════════════════════════════════════════════════════════
       Public API
       ═══════════════════════════════════════════════════════════ */

    /**
     * Build a schema.org/Event JSON-LD node (as a plain array) for one event
     * post. Returns [] when there is no usable start date to advertise, or
     * (for a group parent) when it has zero live children.
     *
     * @param int $event_id
     * @return array
     */
    public function for_event( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 || \get_post_type( $event_id ) !== Module::CPT ) {
            return [];
        }

        // A group CHILD is always a standalone node — even though its own
        // `_anchor_event_type` meta is force-set to 'single' by Occurrences,
        // this check comes first so it never falls into the group-parent
        // branch below via some future data drift.
        if ( $this->module->occurrences->is_group_child( $event_id ) ) {
            return $this->build_single_node( $event_id );
        }

        $type = $this->module->event_type( $event_id );

        // Treat as a group parent both once it HAS been reconciled
        // (group_role=parent) and before its first reconcile (type is
        // offering/recurring but no children exist yet) — either way,
        // children() naturally returns [] pre-reconcile so this collapses
        // to the documented "zero live children -> []" case.
        if ( $this->module->occurrences->is_group_parent( $event_id ) || \in_array( $type, [ 'offering', 'recurring' ], true ) ) {
            return $this->build_group_parent_node( $event_id );
        }

        if ( $type === 'multisession' ) {
            return $this->build_multisession_node( $event_id );
        }

        return $this->build_single_node( $event_id );
    }

    /* ═══════════════════════════════════════════════════════════
       Type-specific builders
       ═══════════════════════════════════════════════════════════ */

    /**
     * A plain, standalone Event node from the event's OWN date meta.
     *
     * @param int $event_id
     * @return array
     */
    private function build_single_node( $event_id ) {
        $meta = $this->module->get_meta( $event_id );
        $ts   = $this->module->compute_timestamps( $meta );

        if ( (int) $ts['start'] <= 0 ) {
            return [];
        }

        return $this->assemble_node( $event_id, $meta, (int) $ts['start'], (int) $ts['end'] );
    }

    /**
     * A multisession Event node: the parent's own node spans the earliest
     * session's start to the latest session's end; `subEvent` holds one
     * minimal Event stub per session (name/date/location/url only — no
     * offers/image/description duplicated per session, since they're
     * identical to the parent's and would only bloat the node).
     *
     * @param int $event_id
     * @return array
     */
    private function build_multisession_node( $event_id ) {
        $meta     = $this->module->get_meta( $event_id );
        $sessions = $this->module->get_sessions( $event_id );

        if ( empty( $sessions ) ) {
            // No sessions authored yet — fall back to a plain single node
            // off the event's own dates rather than returning [].
            return $this->build_single_node( $event_id );
        }

        $tz      = $this->resolve_timezone( $meta );
        $all_day = ! empty( $meta['all_day'] );
        $loc     = $this->location_fields( $event_id, $meta );
        $url     = (string) \get_permalink( $event_id );
        $title   = (string) \get_the_title( $event_id );

        $session_nodes = [];
        $min_start     = null;
        $max_end       = null;

        foreach ( $sessions as $session ) {
            $session_meta                = $meta;
            $session_meta['start_date']  = $session['date'];
            $session_meta['end_date']    = $session['date'];
            $session_meta['start_time']  = $session['start_time'];
            $session_meta['end_time']    = $session['end_time'];

            $s_ts = $this->module->compute_timestamps( $session_meta );
            if ( (int) $s_ts['start'] <= 0 ) {
                continue; // Unparseable session row — skip it, don't fail the whole node.
            }
            $start_ts = (int) $s_ts['start'];
            $end_ts   = (int) $s_ts['end'];

            if ( $min_start === null || $start_ts < $min_start ) {
                $min_start = $start_ts;
            }
            if ( $max_end === null || $end_ts > $max_end ) {
                $max_end = $end_ts;
            }

            $session_nodes[] = [
                '@type'     => 'Event',
                'name'      => $session['label'] !== '' ? $session['label'] : $title,
                'startDate' => $this->format_iso( $start_ts, $tz, $all_day ),
                'endDate'   => $this->format_iso( $end_ts, $tz, $all_day ),
                'location'  => $loc['location'],
                'url'       => $url,
            ];
        }

        if ( empty( $session_nodes ) || $min_start === null || $max_end === null ) {
            return $this->build_single_node( $event_id );
        }

        $node              = $this->assemble_node( $event_id, $meta, $min_start, $max_end );
        $node['subEvent']  = $session_nodes;
        return $node;
    }

    /**
     * A group-parent Event node: header fields come from the PARENT post
     * (shared meta — location/registration/etc. are copied to every child by
     * Occurrences::sync_shared_meta(), so the parent's own copy matches),
     * but startDate/endDate are taken from the EARLIEST live child (the
     * parent's own start_date is normally empty/unused). `subEvent` is
     * for_event() of every live child, so a scraper reading only the
     * parent's page still sees every upcoming date.
     *
     * @param int $event_id
     * @return array
     */
    private function build_group_parent_node( $event_id ) {
        $live_child_ids = $this->module->occurrences->children( $event_id, false );

        $child_nodes   = [];
        $earliest_meta = null;

        foreach ( $live_child_ids as $child_id ) {
            $node = $this->for_event( $child_id );
            if ( empty( $node ) ) {
                continue;
            }
            $child_nodes[] = $node;
            if ( $earliest_meta === null ) {
                // children() is already sorted ascending by start_ts, so the
                // first non-empty node's child is the earliest live one.
                $earliest_meta = $this->module->get_meta( $child_id );
            }
        }

        if ( empty( $child_nodes ) || $earliest_meta === null ) {
            return [];
        }

        $ts = $this->module->compute_timestamps( $earliest_meta );
        if ( (int) $ts['start'] <= 0 ) {
            return [];
        }

        $parent_meta      = $this->module->get_meta( $event_id );
        $node             = $this->assemble_node( $event_id, $parent_meta, (int) $ts['start'], (int) $ts['end'] );
        $node['subEvent'] = $child_nodes;
        return $node;
    }

    /* ═══════════════════════════════════════════════════════════
       Shared node assembly
       ═══════════════════════════════════════════════════════════ */

    /**
     * Build the full set of node fields common to every Event node (single,
     * group child, multisession parent, group parent) given already-resolved
     * start/end timestamps.
     *
     * @param int   $event_id
     * @param array $meta
     * @param int   $start_ts
     * @param int   $end_ts
     * @return array
     */
    private function assemble_node( $event_id, array $meta, $start_ts, $end_ts ) {
        $tz      = $this->resolve_timezone( $meta );
        $all_day = ! empty( $meta['all_day'] );
        $loc     = $this->location_fields( $event_id, $meta );

        $node = [
            '@type'               => 'Event',
            'name'                => (string) \get_the_title( $event_id ),
            'startDate'           => $this->format_iso( $start_ts, $tz, $all_day ),
            'endDate'             => $this->format_iso( $end_ts, $tz, $all_day ),
            'eventStatus'         => $this->event_status( $meta ),
            'eventAttendanceMode' => $loc['mode'],
            'location'            => $loc['location'],
            'url'                 => (string) \get_permalink( $event_id ),
            'description'         => $this->description( $event_id ),
            'organizer'           => [
                '@type' => 'Organization',
                'name'  => (string) \get_bloginfo( 'name' ),
            ],
        ];

        $image = $this->image_url( $event_id );
        if ( $image !== '' ) {
            $node['image'] = $image;
        }

        $offers = $this->build_offers( $event_id, $meta );
        if ( ! empty( $offers ) ) {
            $node['offers'] = $offers;
        }

        return $node;
    }

    /**
     * ISO 8601 startDate/endDate, WITH a timezone offset — all-day events
     * collapse to a date-only string (no time/offset) per Google's Event
     * guidance for all-day events.
     *
     * @param int           $ts
     * @param \DateTimeZone $tz
     * @param bool          $all_day
     * @return string
     */
    private function format_iso( $ts, \DateTimeZone $tz, $all_day ) {
        $dt = ( new \DateTime( '@' . (int) $ts ) )->setTimezone( $tz );
        return $all_day ? $dt->format( 'Y-m-d' ) : $dt->format( 'c' );
    }

    /**
     * Resolve the DateTimeZone an event's wall-clock start/end times were
     * interpreted in. Deliberately mirrors Module::calculate_timestamps()'s
     * (private) timezone_mode resolution exactly, so the ISO string we
     * render always matches the wall-clock time that produced the event's
     * own start_ts/end_ts (same known limitation: a site with only a
     * floating gmt_offset — no timezone_string — falls back to UTC, same as
     * the existing save path).
     *
     * @param array $meta
     * @return \DateTimeZone
     */
    private function resolve_timezone( array $meta ) {
        $settings = $this->module->get_settings();
        $mode     = $settings['timezone_mode'] ?? 'site';

        if ( $mode === 'site' ) {
            $tz_name = \get_option( 'timezone_string' ) ?: 'UTC';
        } else {
            $tz_name = ! empty( $meta['timezone'] ) ? (string) $meta['timezone'] : ( \get_option( 'timezone_string' ) ?: 'UTC' );
        }

        try {
            return new \DateTimeZone( $tz_name );
        } catch ( \Exception $e ) {
            return new \DateTimeZone( 'UTC' );
        }
    }

    /**
     * eventStatus: EventCancelled when the event/occurrence status is
     * 'cancelled' (this already covers a soft-closed group-offering child —
     * Occurrences::soft_close() sets status=cancelled), EventScheduled
     * otherwise.
     *
     * @param array $meta
     * @return string
     */
    private function event_status( array $meta ) {
        $status = (string) ( $meta['status'] ?? '' );
        return $status === 'cancelled' ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled';
    }

    /**
     * eventAttendanceMode + location, together (they're derived from the
     * same virtual/physical signals). Mixed mode returns location as a
     * [Place, VirtualLocation] array (Google's documented shape for mixed
     * events).
     *
     * @param int   $event_id
     * @param array $meta
     * @return array{mode:string,location:array}
     */
    private function location_fields( $event_id, array $meta ) {
        $virtual = ! empty( $meta['virtual'] );

        $has_physical = \trim( (string) ( $meta['venue'] ?? '' ) ) !== ''
            || \trim( (string) ( $meta['address_street'] ?? '' ) ) !== ''
            || \trim( (string) ( $meta['address_city'] ?? '' ) ) !== ''
            || \trim( (string) ( $meta['address_state'] ?? '' ) ) !== ''
            || \trim( (string) ( $meta['address_zip'] ?? '' ) ) !== ''
            || \trim( (string) ( $meta['address_country'] ?? '' ) ) !== '';

        $place = ( $has_physical || ! $virtual ) ? $this->place_node( $event_id, $meta ) : null;

        $virtual_node = null;
        if ( $virtual ) {
            $virtual_node = [
                '@type' => 'VirtualLocation',
                'url'   => ! empty( $meta['virtual_url'] ) ? (string) $meta['virtual_url'] : (string) \get_permalink( $event_id ),
            ];
        }

        if ( $virtual && $place ) {
            return [ 'mode' => 'https://schema.org/MixedEventAttendanceMode', 'location' => [ $place, $virtual_node ] ];
        }
        if ( $virtual ) {
            return [ 'mode' => 'https://schema.org/OnlineEventAttendanceMode', 'location' => $virtual_node ];
        }
        return [ 'mode' => 'https://schema.org/OfflineEventAttendanceMode', 'location' => $place ];
    }

    /**
     * A schema.org/Place node from the location meta fields available.
     * `address` is only added when at least one address field is set.
     *
     * @param int   $event_id
     * @param array $meta
     * @return array
     */
    private function place_node( $event_id, array $meta ) {
        $name  = ! empty( $meta['venue'] ) ? (string) $meta['venue'] : (string) \get_the_title( $event_id );
        $place = [ '@type' => 'Place', 'name' => $name ];

        $field_map = [
            'streetAddress'   => 'address_street',
            'addressLocality' => 'address_city',
            'addressRegion'   => 'address_state',
            'postalCode'      => 'address_zip',
            'addressCountry'  => 'address_country',
        ];

        $address = [];
        foreach ( $field_map as $schema_key => $meta_key ) {
            $val = \trim( (string) ( $meta[ $meta_key ] ?? '' ) );
            if ( $val !== '' ) {
                $address[ $schema_key ] = $val;
            }
        }

        if ( ! empty( $address ) ) {
            $address['@type']  = 'PostalAddress';
            $place['address']  = $address;
        }

        return $place;
    }

    /**
     * Plain-text description: the post excerpt when set, else the first ~55
     * words of the content, HTML stripped either way. Reads the raw post
     * fields directly (not get_the_excerpt()/the_content filters) so this
     * works correctly outside The Loop and never picks up
     * theme/plugin-added "read more" markup. HTML entities (e.g. `&amp;`,
     * `&#8217;`) left behind by wp_strip_all_tags() are decoded so the
     * JSON-LD value is clean text, not markup source.
     *
     * @param int $event_id
     * @return string
     */
    private function description( $event_id ) {
        $post = \get_post( $event_id );
        if ( ! $post ) {
            return '';
        }

        $excerpt = (string) $post->post_excerpt;
        if ( \trim( $excerpt ) === '' ) {
            $excerpt = \wp_trim_words( \wp_strip_all_tags( (string) $post->post_content ), 55, '…' );
        }

        $excerpt = \trim( \wp_strip_all_tags( $excerpt ) );
        return \html_entity_decode( $excerpt, \ENT_QUOTES, 'UTF-8' );
    }

    /**
     * Absolute featured-image URL, or '' when there isn't one.
     *
     * @param int $event_id
     * @return string
     */
    private function image_url( $event_id ) {
        if ( ! \has_post_thumbnail( $event_id ) ) {
            return '';
        }
        $url = \get_the_post_thumbnail_url( $event_id, 'large' );
        return $url ? (string) $url : '';
    }

    /* ═══════════════════════════════════════════════════════════
       Offers
       ═══════════════════════════════════════════════════════════ */

    /**
     * Build the `offers` array for an event, branching on
     * Module::registration_mode(). See the class docblock for the
     * per-mode rules.
     *
     * @param int   $event_id
     * @param array $meta
     * @return array
     */
    private function build_offers( $event_id, array $meta ) {
        $mode     = $this->module->registration_mode( $event_id );
        $currency = $this->currency( $event_id );
        $url      = (string) \get_permalink( $event_id );

        if ( $mode === 'wc' ) {
            return $this->build_wc_offers( $event_id, $meta, $currency, $url );
        }
        if ( $mode === 'external' ) {
            return $this->build_external_offer( $meta, $currency, $url );
        }
        return $this->build_free_offer( $currency, $url );
    }

    /**
     * priceCurrency default: WooCommerce's configured currency when WC is
     * active, else 'USD' — filterable so a site can override either case.
     *
     * @param int $event_id
     * @return string
     */
    private function currency( $event_id ) {
        $default = \function_exists( 'get_woocommerce_currency' ) ? \get_woocommerce_currency() : 'USD';
        return (string) \apply_filters( 'anchor_events_schema_default_currency', $default, $event_id );
    }

    /**
     * One Offer per ACTIVE ticket tier. `availability` comes from the
     * event's overall remaining capacity (one cheap, cached query) rather
     * than a per-tier count — documented simplification, see class docblock.
     *
     * @param int    $event_id
     * @param array  $meta
     * @param string $currency
     * @param string $url
     * @return array
     */
    private function build_wc_offers( $event_id, array $meta, $currency, $url ) {
        $active = \array_values( \array_filter(
            $this->module->ticket_types->get( $event_id ),
            function ( $tier ) {
                return ! empty( $tier['active'] );
            }
        ) );

        if ( empty( $active ) ) {
            return [];
        }

        $capacity     = (int) ( $meta['capacity'] ?? 0 );
        $remaining    = $this->module->registrations->remaining_capacity( $event_id, $capacity );
        $availability = $remaining > 0 ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut';
        $valid_from   = $this->registration_open_iso( $meta );

        $offers = [];
        foreach ( $active as $tier ) {
            $offer = [
                '@type'         => 'Offer',
                'price'         => $this->clean_number( (float) ( $tier['price'] ?? 0 ) ),
                'priceCurrency' => $currency,
                'availability'  => $availability,
                'url'           => $url,
            ];
            if ( $valid_from !== '' ) {
                $offer['validFrom'] = $valid_from;
            }
            $offers[] = $offer;
        }
        return $offers;
    }

    /**
     * One Offer for an externally-registered event. `price` is parsed from
     * the free-text external_display_price and omitted (never fabricated as
     * "0") when no numeric substring is found.
     *
     * @param array  $meta
     * @param string $currency
     * @param string $fallback_url Permalink, used when external_url is empty.
     * @return array
     */
    private function build_external_offer( array $meta, $currency, $fallback_url ) {
        $url = ! empty( $meta['external_url'] ) ? (string) $meta['external_url'] : $fallback_url;

        $offer = [
            '@type'         => 'Offer',
            'priceCurrency' => $currency,
            'url'           => $url,
        ];

        $price = $this->parse_price( (string) ( $meta['external_display_price'] ?? '' ) );
        if ( $price !== null ) {
            $offer['price'] = $price;
        }

        return [ $offer ];
    }

    /**
     * The single free Offer (price 0) — see class docblock for why a
     * present, zero-price Offer is preferred over omitting `offers`.
     *
     * @param string $currency
     * @param string $url
     * @return array
     */
    private function build_free_offer( $currency, $url ) {
        return [ [
            '@type'         => 'Offer',
            'price'         => 0,
            'priceCurrency' => $currency,
            'availability'  => 'https://schema.org/InStock',
            'url'           => $url,
        ] ];
    }

    /**
     * Extract a numeric price from free text ("$495" -> 495, "$1,250.50" ->
     * 1250.5), or null when no numeric substring is present.
     *
     * @param string $raw
     * @return int|float|null
     */
    private function parse_price( $raw ) {
        if ( ! \preg_match( '/[\d,]+(?:\.\d+)?/', $raw, $m ) ) {
            return null;
        }
        $num = (float) \str_replace( ',', '', $m[0] );
        return $this->clean_number( $num );
    }

    /**
     * Whole-number-valued floats render as int (495, not 495.0); everything
     * else rounds to 2 decimals. Keeps the JSON output clean (Task 4.2 does
     * the actual json_encode).
     *
     * @param float $num
     * @return int|float
     */
    private function clean_number( $num ) {
        $num = (float) $num;
        if ( \fmod( $num, 1.0 ) === 0.0 ) {
            return (int) $num;
        }
        return \round( $num, 2 );
    }

    /**
     * ISO 8601 `validFrom` for a WC offer, from the event's
     * registration_open date (midnight, event timezone), or '' when unset.
     *
     * @param array $meta
     * @return string
     */
    private function registration_open_iso( array $meta ) {
        $raw = \trim( (string) ( $meta['registration_open'] ?? '' ) );
        if ( $raw === '' ) {
            return '';
        }
        $tz = $this->resolve_timezone( $meta );
        $dt = \DateTime::createFromFormat( 'Y-m-d H:i', $raw . ' 00:00', $tz );
        return $dt ? $dt->format( 'c' ) : '';
    }
}
