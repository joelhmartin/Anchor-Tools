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
 *     from the tier's own price. No active tiers -> no offers key.
 *   - external: one Offer with `url` = external_url (or the permalink) and
 *     `price` parsed from the free-text external_display_price ("$495" ->
 *     495) when a numeric substring is found; price is omitted (not "0")
 *     when unparseable, so we never fabricate a price.
 *   - free (default): one Offer with price 0 — chosen over omitting a
 *     BOOKABLE event's offers because Google's Event guidance treats a
 *     present, zero-price Offer as the canonical "free to attend" signal.
 *
 * AVAILABILITY is not decided here. Every builder asks Module::bookability()
 * — the single purchasability authority the storefront, the cart, the date
 * picker and the series archive also ask — and maps it through
 * availability_for(). The wc branch asks per TIER, so an exhausted tier quota
 * is SoldOut while its sibling stays InStock.
 *
 * Three bookability states have no availability value, and they do NOT all
 * mean the same thing (see omits_offer()):
 *   - `parent` / `closed` — a container, a finished event, a soft-closed date
 *     or a registration window that has passed. There is nothing to sell, so
 *     the Offer is omitted entirely rather than claiming a false state.
 *   - `disabled` — registration is simply switched OFF, which is the DEFAULT
 *     and the normal steady state for a display-only site. The event is real,
 *     its price is real, and it is still worth publishing; what the site
 *     cannot honestly claim is a stock state. So the Offer IS emitted, with
 *     price / priceCurrency / url / validFrom and NO `availability` key —
 *     `availability` is an optional property, and omitting it says "no claim"
 *     instead of dropping every display-only event's price from the markup.
 *
 * OTHER FIELDS (RENDER-D9 / RENDER-D10 / RENDER-D38):
 *   - `eventStatus` also covers `EventPostponed`/`EventMovedOnline` (manual-only
 *     statuses, see Module::get_status_options()), each optionally paired with
 *     `previousStartDate` from Module::previous_start_date() — see
 *     assemble_node()'s docblock.
 *   - `superEvent` is added to a group CHILD's own node, pointing back at its
 *     live parent — the parent -> subEvent link already existed; this is the
 *     reverse of it.
 *   - `maximumAttendeeCapacity`/`remainingAttendeeCapacity` come from the same
 *     capacity authority (`$meta['capacity']` + Registrations::remaining_capacity())
 *     every other capacity reader already asks; capacity 0 means unlimited and
 *     publishes neither key, same convention as availability.
 *   - `isAccessibleForFree` is true only alongside an actually-emitted free Offer.
 *   - every node this class builds passes through the `anchor_events_schema_node`
 *     filter (`$node, $event_id`) at the end of assemble_node() before it is
 *     returned — the one seam a theme or another plugin can decorate a node
 *     from (performer, an `organizer.@id`, an image fallback, …) without this
 *     class knowing anything about speakers or SEO plugins. See EVENTS.md's
 *     filter table for who currently uses it and its one known gap
 *     (multisession's per-session `subEvent` stubs are raw arrays, never run
 *     through assemble_node(), so they never reach this filter directly).
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
     * The schema.org availability URL for a Module::bookability() state, or
     * null when there is no honest value to publish (RENDER-D3/D4/D5/D7).
     *
     * schema.org has no "not for sale" availability value, so a container, a
     * finished event, a closed registration window and an event with
     * registration switched off all map to null. null means "make no
     * availability claim" — it does NOT by itself mean "omit the Offer";
     * omits_offer() is what decides that, and it says yes for parent/closed
     * and no for disabled. 'waitlist' maps to LimitedAvailability rather than
     * SoldOut because the seat CAN still be requested —
     * Registrations::claim_seats() resolves it at creation.
     *
     * @param string $bookability One of open|waitlist|full|closed|parent|disabled.
     * @return string|null
     */
    public static function availability_for( $bookability ) {
        switch ( (string) $bookability ) {
            case 'open':
                return 'https://schema.org/InStock';
            case 'waitlist':
                return 'https://schema.org/LimitedAvailability';
            case 'full':
                return 'https://schema.org/SoldOut';
            default:
                return null;
        }
    }

    /**
     * Whether a Module::bookability() state means "publish no Offer at all",
     * as opposed to "publish the Offer but make no availability claim".
     *
     * `parent` is a container that has no seats of its own; `closed` covers a
     * finished event, a soft-closed date and a registration window that has
     * passed. In both cases an Offer would advertise something that does not
     * exist.
     *
     * `disabled` is deliberately NOT in this list. Registration off is the
     * default state of an event and the permanent state of every display-only
     * site, so treating it as "no Offer" stripped `offers` from the majority
     * of events on such a site — the price stopped reaching search results
     * because nobody had switched on a registration feature they do not use.
     * Its Offer is emitted without `availability` instead.
     *
     * @param string $bookability
     * @return bool
     */
    private static function omits_offer( $bookability ) {
        return ! \in_array( (string) $bookability, [ 'open', 'waitlist', 'full', 'disabled' ], true );
    }

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

        $node = $this->assemble_node( $event_id, $meta, (int) $ts['start'], (int) $ts['end'] );

        // RENDER-D38: a group child's node had no link back to its parent, so
        // the parent/child relationship was one-directional in the markup
        // (parent -> subEvent only). parent_of() is already validated —
        // trashed/reissued/dead ids resolve to 0 — so this never points at a
        // parent that is not really there.
        $parent_id = $this->module->occurrences->is_group_child( $event_id )
            ? $this->module->occurrences->parent_of( $event_id )
            : 0;
        if ( $parent_id > 0 ) {
            $node['superEvent'] = [
                '@type' => 'Event',
                'name'  => (string) \get_the_title( $parent_id ),
                'url'   => (string) \get_permalink( $parent_id ),
            ];
        }

        return $node;
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
        $end_ts        = null;

        // A container runs from its first occurrence to the END of its LAST
        // one. Taking both ends off the earliest child described a group as
        // finishing when its first date finished, while its own subEvents
        // ran on for weeks — contradictory data for anything reading the
        // markup. RENDER-D6: that end must come ONLY from a child that
        // actually produced a subEvent node — $live_child_ids can contain a
        // published, non-soft-closed child with no usable start_ts (for_event()
        // returns [] for it), and re-looping the full id list unconditionally
        // let such a child's end_ts leak into the parent's endDate with no
        // corresponding entry anywhere in subEvent[]. Folding the end-of-span
        // read into this same loop (rather than a second pass over every id)
        // also drops a second get_meta() + compute_timestamps() per child.
        foreach ( $live_child_ids as $child_id ) {
            $node = $this->for_event( $child_id );
            if ( empty( $node ) ) {
                continue;
            }
            $child_nodes[] = $node;

            $child_meta = $this->module->get_meta( $child_id );
            $child_ts   = $this->module->compute_timestamps( $child_meta );

            if ( $earliest_meta === null ) {
                // children() is already sorted ascending by start_ts, so the
                // first non-empty node's child is the earliest live one.
                $earliest_meta = $child_meta;
            }
            if ( $end_ts === null || (int) $child_ts['end'] > $end_ts ) {
                $end_ts = (int) $child_ts['end'];
            }
        }

        if ( empty( $child_nodes ) || $earliest_meta === null ) {
            return [];
        }

        $ts = $this->module->compute_timestamps( $earliest_meta );
        if ( (int) $ts['start'] <= 0 ) {
            return [];
        }

        $parent_meta = $this->module->get_meta( $event_id );
        $node        = $this->assemble_node( $event_id, $parent_meta, (int) $ts['start'], (int) $end_ts );

        // RENDER-D7: a container is never itself bookable
        // (render_registration_form() refuses to give a parent a form), so it
        // advertises no Offer — its subEvents each carry their own. Explicit
        // rather than implicit: bookability() already answers 'parent' here so
        // assemble_node() produces no offers, but assemble_node() is shared
        // with three builders and this is the parent's own contract.
        unset( $node['offers'] );

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
        $status  = (string) $this->module->get_event_status( $event_id, $meta );

        $node = [
            '@type'               => 'Event',
            'name'                => (string) \get_the_title( $event_id ),
            'startDate'           => $this->format_iso( $start_ts, $tz, $all_day ),
            'endDate'             => $this->format_iso( $end_ts, $tz, $all_day ),
            'eventStatus'         => $this->event_status_url( $status ),
            'eventAttendanceMode' => $loc['mode'],
            'location'            => $loc['location'],
            'url'                 => (string) \get_permalink( $event_id ),
            'description'         => $this->description( $event_id ),
            'organizer'           => [
                '@type' => 'Organization',
                'name'  => (string) \get_bloginfo( 'name' ),
            ],
        ];

        // RENDER-D9: EventPostponed/EventRescheduled/EventMovedOnline are
        // documented (Google's Event guidance) to carry the date being moved
        // AWAY from. _anchor_event_previous_start is written once, by the
        // shared save path (Module::persist_event_authoring()), the moment a
        // status transitions into one of these two, or its date changes again
        // while already in one — see that method's docblock. No stored value
        // (an event pinned straight to postponed/moved_online with no prior
        // save through that path) means no honest date to publish, so the key
        // is simply omitted rather than guessed.
        if ( \in_array( $status, [ 'postponed', 'moved_online' ], true ) ) {
            $previous_start = $this->module->previous_start_date( $event_id );
            if ( $previous_start !== '' ) {
                $node['previousStartDate'] = $previous_start;
            }
        }

        $image = $this->image_url( $event_id );
        if ( $image !== '' ) {
            $node['image'] = $image;
        }

        $offers = $this->build_offers( $event_id, $meta );
        if ( ! empty( $offers ) ) {
            $node['offers'] = $offers;

            // RENDER-D38: only claim free admission when a real, live Offer
            // was actually emitted for the free branch — a finished/closed
            // event (omits_offer()) makes no offers claim at all, so it must
            // not make this one either.
            if ( $this->module->registration_mode( $event_id ) === 'free' ) {
                $node['isAccessibleForFree'] = true;
            }
        }

        // RENDER-D38: maximumAttendeeCapacity/remainingAttendeeCapacity from
        // the SAME capacity authority choose_date_availability_hint() already
        // reads (Module::$meta['capacity'] + Registrations::remaining_capacity()) —
        // not a second decision. Capacity 0 means "unlimited" throughout this
        // module, so — same as an omitted `availability` — no capacity claim
        // is published rather than a false one.
        //
        // finding-5 (bot review, PR #20): a group PARENT is a container, never
        // a registration target (audit REG-D2) — it carries no seats of its
        // own, so remaining_capacity() against ITS meta always reports the
        // parent's full capacity back as remaining, wrongly claiming an event
        // with fully-booked children is wide open. The children (whose nodes
        // carry their own $meta['capacity']) already publish the real
        // numbers; the parent publishes neither field rather than a false one.
        $capacity = (int) ( $meta['capacity'] ?? 0 );
        if ( $capacity > 0 && ! $this->module->occurrences->is_group_parent( $event_id ) ) {
            $node['maximumAttendeeCapacity']   = $capacity;
            $node['remainingAttendeeCapacity'] = (int) $this->module->registrations->remaining_capacity( $event_id, $capacity );
        }

        // RENDER-D10 / THEME-D27: the one seam a theme (or another plugin) can
        // decorate a node from — add performer, tie organizer to a site-wide
        // @id, fall back an image, etc. — without Event_Schema knowing
        // anything about speakers or Yoast. Applied here, inside the shared
        // assembler, so every node (single, group child, multisession
        // parent, group parent, and every child pulled in via for_event())
        // passes through it as it is built, not just the outermost return of
        // for_event().
        $node = (array) \apply_filters( 'anchor_events_schema_node', $node, $event_id );

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
     * The DateTimeZone an event's wall-clock start/end times were interpreted
     * in — ASKED of the module, never re-derived (audit RENDER-D2 / MODEL-D20).
     *
     * This used to be a second copy of the resolution: `get_option(
     * 'timezone_string') ?: 'UTC'`. That option is empty on any site
     * configured with a raw gmt_offset instead of a named zone, and it cannot
     * translate WordPress's own "UTC-6" form either — so on exactly the sites
     * Module::normalize_timezone() was written to fix, the module computed
     * every timestamp at -06:00 while this rendered the same instants at
     * +00:00. Production published `"startDate":"2026-12-11T14:00:00+00:00"`
     * for an 08:00 local course, and an all-day node — which collapses to
     * `Y-m-d` in the RENDERED zone — named the wrong day on a UTC+ site.
     *
     * @param array $meta
     * @return \DateTimeZone
     */
    private function resolve_timezone( array $meta ) {
        return $this->module->event_timezone( $meta );
    }

    /**
     * eventStatus: maps the vocabulary Module::get_event_status() returns
     * (the one accessor every other renderer uses — RENDER-D11, see below) to
     * its schema.org URL. 'cancelled' already covers a soft-closed
     * group-offering child (Occurrences::soft_close() sets status_mode=manual
     * + status=cancelled). 'postponed'/'moved_online' are RENDER-D9: before
     * this fix the vocabulary had no member for either state, so a course
     * pushed to a later date or moved from in-person to virtual republished
     * as a plain EventScheduled with a silently changed startDate — exactly
     * the case Google's Event guidance says must use EventPostponed /
     * EventMovedOnline (+ previousStartDate, assembled alongside this in
     * assemble_node()). Anything else is EventScheduled.
     *
     * RENDER-D11: the status comes from Module::get_event_status(), not a raw
     * `$meta['status']` read — that row is only refreshed on save, on
     * transition_post_status and by the daily sweep, so reading it directly
     * made the JSON-LD and the visible "Status: …" on the same page derive one
     * fact from two sources — and a stale 'cancelled' row on an AUTO-mode
     * event (auto never computes 'cancelled') published EventCancelled for an
     * event nobody had cancelled.
     *
     * @param string $status Already-resolved Module::get_event_status() value.
     * @return string
     */
    private function event_status_url( $status ) {
        switch ( (string) $status ) {
            case 'cancelled':
                return 'https://schema.org/EventCancelled';
            case 'postponed':
                return 'https://schema.org/EventPostponed';
            case 'moved_online':
                return 'https://schema.org/EventMovedOnline';
            default:
                return 'https://schema.org/EventScheduled';
        }
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
            return $this->build_external_offer( $event_id, $meta, $currency, $url );
        }
        return $this->build_free_offer( $event_id, $meta, $currency, $url );
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
     * One Offer per ACTIVE ticket tier whose seats can still be sold.
     *
     * RENDER-D3: `availability` is Module::bookability() for THAT tier, not a
     * remaining_capacity() count — the count is blind to the hand-set
     * sold_out flag, the registration_open/close window, the waitlist toggle,
     * the per-tier quota and the past, all of which the picker on the same
     * page already honours. A tier whose state omits the Offer entirely
     * (closed / parent) contributes nothing, so an event that cannot be
     * booked publishes no price rather than a false one; a `disabled` event
     * still publishes its tier prices, just with no availability claim (see
     * omits_offer()).
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

        $valid_from = $this->registration_open_iso( $meta );

        $offers = [];
        foreach ( $active as $tier ) {
            $bookability = $this->module->bookability( $event_id, $tier );
            if ( self::omits_offer( $bookability ) ) {
                continue;
            }
            $offer = [
                '@type'         => 'Offer',
                'price'         => $this->clean_number( (float) ( $tier['price'] ?? 0 ) ),
                'priceCurrency' => $currency,
                'url'           => $url,
            ];
            $availability = self::availability_for( $bookability );
            if ( $availability !== null ) {
                $offer['availability'] = $availability;
            }
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
     * RENDER-D5: the Offer used to carry neither `availability` nor
     * `validFrom`, so Google dropped the block rather than showing "unknown".
     * All three builders now emit the same shape from the same authority.
     *
     * @param int    $event_id
     * @param array  $meta
     * @param string $currency
     * @param string $fallback_url Permalink, used when external_url is empty.
     * @return array
     */
    private function build_external_offer( $event_id, array $meta, $currency, $fallback_url ) {
        $bookability = $this->module->bookability( $event_id );
        if ( self::omits_offer( $bookability ) ) {
            return [];
        }

        $url = ! empty( $meta['external_url'] ) ? (string) $meta['external_url'] : $fallback_url;

        $offer = [
            '@type'         => 'Offer',
            'priceCurrency' => $currency,
            'url'           => $url,
        ];

        $availability = self::availability_for( $bookability );
        if ( $availability !== null ) {
            $offer['availability'] = $availability;
        }

        $price = $this->parse_price( (string) ( $meta['external_display_price'] ?? '' ) );
        if ( $price !== null ) {
            $offer['price'] = $price;
        }

        $valid_from = $this->registration_open_iso( $meta );
        if ( $valid_from !== '' ) {
            $offer['validFrom'] = $valid_from;
        }

        return [ $offer ];
    }

    /**
     * The single free Offer (price 0) — see class docblock for why a
     * present, zero-price Offer is preferred over omitting `offers`.
     *
     * RENDER-D4: `availability` used to be a hardcoded InStock, so a free
     * event that was full, hand-flagged sold out, or long finished still
     * advertised itself as available to Google indefinitely. There is no
     * "closed" availability value, so a container or a finished/closed event
     * now emits no Offer, and an event with registration merely switched off
     * emits the Offer with no availability claim (see omits_offer()).
     *
     * @param int    $event_id
     * @param array  $meta
     * @param string $currency
     * @param string $url
     * @return array
     */
    private function build_free_offer( $event_id, array $meta, $currency, $url ) {
        $bookability = $this->module->bookability( $event_id );
        if ( self::omits_offer( $bookability ) ) {
            return [];
        }

        $offer = [
            '@type'         => 'Offer',
            'price'         => 0,
            'priceCurrency' => $currency,
            'url'           => $url,
        ];

        $availability = self::availability_for( $bookability );
        if ( $availability !== null ) {
            $offer['availability'] = $availability;
        }

        $valid_from = $this->registration_open_iso( $meta );
        if ( $valid_from !== '' ) {
            $offer['validFrom'] = $valid_from;
        }

        return [ $offer ];
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
     * ISO 8601 `validFrom` for an Offer (all three builders emit the same
     * shape), from the event's registration_open date (midnight, event
     * timezone), or '' when unset.
     *
     * The wall-clock -> instant step is Module::to_timestamp()'s, not a local
     * `createFromFormat()` of its own: a second construction is a second place
     * for the format, the zone and the seconds rule to drift out of step with
     * the save path — which is exactly how resolve_timezone() came to render
     * every date in a zone the module never computed in.
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
        $ts = $this->module->to_timestamp( $raw, '00:00', $tz );
        // to_timestamp() answers 0 for both "no date" and "unparseable". Only
        // exactly the epoch is ambiguous, and a registration_open of
        // 1970-01-01T00:00Z is not a date anyone sets; a pre-1970 one still
        // renders, as it did before.
        return $ts !== 0 ? $this->format_iso( $ts, $tz, false ) : '';
    }
}
