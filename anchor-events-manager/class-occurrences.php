<?php
/**
 * Occurrences — parent→child reconcile engine for "Pick-one offerings" (spec
 * Phase 2, Task 2.1).
 *
 * One responsibility: given a group PARENT event post that holds a list of
 * explicit offering dates (`_anchor_event_offering_dates`), reconcile a set of
 * CHILD event posts — one per desired date — so each occurrence is a full,
 * standalone event post that reuses the existing per-event engine unchanged
 * (its own date/timestamps/status, capacity, ticket tiers, seats/roster,
 * managed WooCommerce product). This class ORCHESTRATES child event posts; it
 * never reaches into seats/capacity/tiers/product-sync/roster internals — it
 * only calls their existing public APIs on a per-child basis, exactly as the
 * classic per-event admin save path already does for a single event.
 *
 * Field split (see class docblock sections below):
 *   - PER-OCCURRENCE meta ("owned" by the child once created): start_date,
 *     end_date, start_time, end_time, start_ts, end_ts, capacity,
 *     status_mode, status. Of these, only start_date/end_date (the date
 *     IDENTITY, via occurrence_key) and status/status_mode are frozen once
 *     set — start_time/end_time/capacity are the row's EDITABLE fields and
 *     ARE re-applied (parent-row-wins) on every reconcile of a still-desired
 *     date, with start_ts/end_ts recomputed accordingly (see
 *     apply_occurrence_editable_fields()). Also implicitly per-occurrence:
 *     seats/roster (REG_CPT rows keyed by
 *     event id) and the managed WooCommerce product
 *     (`_anchor_event_managed_product`) — both are per-post already and are
 *     never copied from the parent.
 *   - SHARED meta (copied at child creation AND re-synced on every reconcile
 *     of a still-live child, so editing the parent propagates): everything
 *     else in the event meta schema except the per-occurrence keys above and
 *     the NEVER_COPY_KEYS below (product/engine-owned mirrors + fields that
 *     don't make sense to copy). This covers title-ish/content, location
 *     fields, ticket_types, registration_mode, external_*, the capacity
 *     *default*, timezone, and the remaining registration-policy fields
 *     (registration_enabled, waitlist, registration_open/close,
 *     registration_type, registration_url, price, hide_from_archive,
 *     featured, priority, organizer_email, reminder_offsets, gallery,
 *     all_day). A child's `type` meta is force-set to 'single' (never
 *     inherits 'offering'/'recurring') because each occurrence is itself a
 *     plain single event.
 *
 * Soft-close representation: a removed-but-seated occurrence is NEVER
 * deleted. It is marked with the existing status vocabulary
 * (status_mode=manual, status=cancelled, registration_enabled=false) plus an
 * engine-owned flag (`_anchor_event_occurrence_closed=1`) so it can be
 * unambiguously excluded from the active "choose a date" set (children()
 * with $include_closed=false) while its post + roster survive untouched and
 * remain reachable via children($include_closed=true). Re-adding the same
 * occurrence_key later REVIVES the same child (clears the closed flag,
 * restores auto status/registration) instead of creating a duplicate, so its
 * historical roster is retained.
 *
 * Idempotency: a child is matched to a desired offering-dates row by a
 * stable `occurrence_key` (the row's normalized date string) stored on the
 * child at creation. reconcile() is a pure function of (parent's
 * offering_dates, existing children keyed by occurrence_key) — an unchanged
 * desired set produces no new posts, no closures, and no meta churn beyond
 * re-writing identical shared-field values.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class Occurrences {

    /**
     * Event meta keys (WITHOUT the `_anchor_event_` prefix) that belong to a
     * single occurrence and are NEVER copied verbatim from the parent's own
     * meta of the same name (they're excluded from sync_shared_meta()).
     * start_date/end_date (the date identity) and status/status_mode are set
     * once at creation and then frozen; start_time/end_time/capacity are the
     * offering-dates row's editable fields and ARE re-applied from the
     * matched row on every reconcile (see apply_occurrence_editable_fields())
     * — never blindly copied from the parent's own same-named meta.
     */
    const PER_OCCURRENCE_KEYS = [
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'start_ts',
        'end_ts',
        'capacity',
        'status_mode',
        'status',
        // Availability is a fact about ONE date, not about the group. Closing or
        // selling out the September date must not close November, and must not be
        // silently undone the next time the parent is saved — which is exactly
        // what happened while these were copied down from the parent.
        'registration_enabled',
        'sold_out',
    ];

    /**
     * Event meta keys that are never copied parent→child at all (engine-owned
     * mirrors, product-sync-owned caches, or fields that don't apply to a
     * child occurrence).
     */
    const NEVER_COPY_KEYS = [
        'linked_products',
        'roster_sent',
        'activity',
        'type',
        'sessions',
        'group_role',
        'group_id',
        'offering_dates',
        'recurrence',
        'occurrence_key',
        'occurrence_closed',
    ];

    /**
     * Hard safety cap on the number of rows expand_recurrence() will ever
     * return, regardless of the rule's count/until (spec Phase 2, Task 2.2).
     * ~2 years of weekly occurrences. Guarantees the generator can never loop
     * unbounded, including a rule with neither `count` nor `until` set.
     */
    const RECURRENCE_MAX_ROWS = 104;

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
    }

    /* ═══════════════════════════════════════════════════════════
       Public API
       ═══════════════════════════════════════════════════════════ */

    /**
     * Idempotently reconcile a group parent's desired offering dates against
     * its existing child event posts.
     *
     * @param int $parent_id
     * @return int[] Live (non-closed, non-trashed) child post ids.
     */
    public function reconcile( $parent_id ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 || \get_post_type( $parent_id ) !== Module::CPT ) {
            return [];
        }
        // A child post is never itself treated as a group parent.
        if ( $this->is_group_child( $parent_id ) ) {
            return [];
        }

        $this->set_group_role( $parent_id, 'parent' );

        $desired_map  = [];
        foreach ( $this->get_desired_dates( $parent_id ) as $row ) {
            $desired_map[ $row['date'] ] = $row;
        }

        // Trashed children are included on purpose. Removing a date trashes its
        // occurrence; adding the same date back must revive THAT occurrence, not
        // build a second one beside it. Without this the original stays in the
        // trash for ever and the date silently acquires a duplicate post.
        $existing_map = $this->existing_children_map( $parent_id, true ); // occurrence_key => child_id (live + closed + trashed)

        $live_ids = [];

        foreach ( $desired_map as $key => $row ) {
            if ( isset( $existing_map[ $key ] ) ) {
                $child_id = (int) $existing_map[ $key ];
                // A wanted date whose occurrence is in the trash comes back out,
                // keeping its id, its roster and its history.
                if ( \get_post_status( $child_id ) === 'trash' ) {
                    \wp_untrash_post( $child_id );
                    \wp_update_post( [ 'ID' => $child_id, 'post_status' => 'publish' ] );
                }
                $this->sync_child_from_parent( $parent_id, $child_id, $row );
                $this->revive_if_closed( $child_id );
                $live_ids[] = $child_id;
            } else {
                $child_id = $this->create_child( $parent_id, $row, $key );
                if ( $child_id > 0 ) {
                    $live_ids[] = $child_id;
                }
            }
        }

        foreach ( $existing_map as $key => $child_id ) {
            if ( isset( $desired_map[ $key ] ) ) {
                continue; // still desired — handled above.
            }
            // Already in the trash and still unwanted: leave it exactly as it is.
            if ( \get_post_status( (int) $child_id ) === 'trash' ) {
                continue;
            }
            $this->retire_child( (int) $child_id );
        }

        $this->assign_series( $parent_id, $live_ids );

        \usort( $live_ids, function ( $a, $b ) {
            return $this->start_ts( $a ) <=> $this->start_ts( $b );
        } );

        $this->sync_parent_span( $parent_id, $live_ids );

        return $live_ids;
    }

    /**
     * Retire EVERY existing child of a group parent (live + soft-closed),
     * roster-safe: soft-close a child that has any seats (preserve roster),
     * trash a child with none. Used when the parent itself is being trashed
     * (spec Phase 2, Task 2.3 FIX 2) — wp_trash_post() does not fire
     * save_post, so reconcile() never runs on its own in that case; see
     * Module::retire_children_on_parent_trash(), which calls this. Reuses
     * the exact same retire_child() roster-safety logic reconcile() already
     * applies to a no-longer-desired occurrence rather than reimplementing
     * it. Deliberately does NOT touch the parent's own meta
     * (offering_dates/recurrence/group_role) or call set_group_role() — the
     * parent is being trashed, not reconciled.
     *
     * @param int $parent_id
     */
    /**
     * Give the parent the date span its occurrences actually cover.
     *
     * A container's own dates are otherwise whatever was last typed into them,
     * which drifts the moment an occurrence is added or removed — and anything
     * sorting or labelling by the parent's start_ts then places it arbitrarily.
     * Deriving it removes the only reason to hand-stretch an end date to keep a
     * multi-date event visible.
     *
     * Earliest start to latest end across live children. Left untouched when
     * there are none, so an event that has stopped being a container keeps the
     * dates it had rather than being blanked.
     *
     * @param int   $parent_id
     * @param int[] $live_ids Live children, already sorted earliest-first.
     * @return void
     */
    private function sync_parent_span( $parent_id, array $live_ids ) {
        if ( empty( $live_ids ) ) {
            return;
        }

        $mk = function ( $k ) {
            return $this->module->meta_key( $k );
        };

        $starts = [];
        $ends   = [];
        foreach ( $live_ids as $child_id ) {
            $start = (string) \get_post_meta( (int) $child_id, $mk( 'start_date' ), true );
            if ( $start === '' ) {
                continue;
            }
            $end      = (string) \get_post_meta( (int) $child_id, $mk( 'end_date' ), true );
            $starts[] = $start;
            $ends[]   = $end !== '' ? $end : $start;
        }

        if ( empty( $starts ) ) {
            return;
        }

        $start_date = \min( $starts );
        $end_date   = \max( $ends );

        \update_post_meta( $parent_id, $mk( 'start_date' ), $start_date );
        \update_post_meta( $parent_id, $mk( 'end_date' ), $end_date );

        // Recompute through the module so the parent's timestamps use the exact
        // same timezone/all-day rules as every other event.
        $meta = $this->module->get_meta( $parent_id );
        $meta['start_date'] = $start_date;
        $meta['end_date']   = $end_date;

        $this->module->persist_timestamps( $parent_id, $meta );
    }

    public function retire_all_children( $parent_id ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return;
        }
        foreach ( $this->existing_children_map( $parent_id ) as $child_id ) {
            $this->retire_child( (int) $child_id );
        }
    }

    /**
     * Live (or all, incl. soft-closed) child post ids for a group parent.
     * Never includes trashed children.
     *
     * @param int  $parent_id
     * @param bool $include_closed
     * @return int[]
     */
    public function children( $parent_id, $include_closed = false ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return [];
        }

        $ids = [];
        foreach ( $this->existing_children_map( $parent_id ) as $child_id ) {
            $child_id = (int) $child_id;
            if ( ! $include_closed && $this->is_closed( $child_id ) ) {
                continue;
            }
            $ids[] = $child_id;
        }

        \usort( $ids, function ( $a, $b ) {
            return $this->start_ts( $a ) <=> $this->start_ts( $b );
        } );

        return $ids;
    }

    /**
     * Sibling child ids (same group, excluding $child_id itself).
     *
     * @param int  $child_id
     * @param bool $include_closed
     * @return int[]
     */
    public function siblings( $child_id, $include_closed = false ) {
        $child_id  = (int) $child_id;
        $parent_id = $this->parent_of( $child_id );
        if ( $parent_id <= 0 ) {
            return [];
        }
        return \array_values( \array_diff( $this->children( $parent_id, $include_closed ), [ $child_id ] ) );
    }

    /**
     * Whether a post is a group parent (has been reconciled at least once as
     * one — stamped by reconcile()).
     *
     * @param int $id
     * @return bool
     */
    public function is_group_parent( $id ) {
        $id = (int) $id;
        return $id > 0 && \get_post_meta( $id, $this->module->meta_key( 'group_role' ), true ) === 'parent';
    }

    /**
     * Whether a post is a group child (created by reconcile()).
     *
     * @param int $id
     * @return bool
     */
    public function is_group_child( $id ) {
        $id = (int) $id;
        return $id > 0 && \get_post_meta( $id, $this->module->meta_key( 'group_role' ), true ) === 'child';
    }

    /**
     * The parent event post id for a child (0 when not a child).
     *
     * @param int $child_id
     * @return int
     */
    public function parent_of( $child_id ) {
        $child_id = (int) $child_id;
        if ( $child_id <= 0 || ! $this->is_group_child( $child_id ) ) {
            return 0;
        }
        return (int) \get_post_meta( $child_id, $this->module->meta_key( 'group_id' ), true );
    }

    /**
     * Pure, deterministic weekly/monthly date expansion (spec Phase 2, Task
     * 2.2). NOT full RFC-5545 RRULE — only `weekly` and `monthly` frequencies
     * are supported. The result is a function of $rule + $anchor_date ONLY
     * (no current-time dependence), so calling it twice with the same inputs
     * always returns the identical array.
     *
     * Rule shape:
     *   freq      : 'weekly' | 'monthly' (default 'weekly' for any other value).
     *   interval  : int >= 1, every N weeks/months (default 1).
     *   count     : int, number of occurrences to generate.
     *   until     : Y-m-d, inclusive last date.
     *               Exactly one of count/until normally terminates the rule;
     *               if both are given, generation stops at whichever is hit
     *               first. If NEITHER is given, generation stops at the
     *               safety cap (RECURRENCE_MAX_ROWS) — a rule is never
     *               required to self-terminate; the cap always does.
     *   weekdays  : optional int[] 0 (Sun) .. 6 (Sat), weekly only. When
     *               given, every listed weekday within each active week is
     *               included (chronological order); when omitted, only
     *               $anchor_date's own weekday is used.
     *   start_time/end_time/capacity : copied onto every generated row as-is
     *               (same normalization as get_offering_dates()'s rows).
     *
     * Monthly semantics: same day-of-month as $anchor_date, every `interval`
     * months. SHORT-MONTH HANDLING (documented choice): when the target month
     * has fewer days than the anchor's day-of-month (e.g. day 31 in a 30-day
     * month), that month is SKIPPED ENTIRELY — no occurrence is generated for
     * it, and it is NOT rolled forward/back to a different day. The next
     * month that does have the day contributes the next occurrence.
     *
     * Weekly safety: with weekdays given, no explicit cap is needed to
     * guarantee progress (>=1 weekday per active week is always guaranteed by
     * falling back to the anchor's weekday when the list is empty).
     *
     * SAFETY CAP: never returns more than RECURRENCE_MAX_ROWS rows. This is
     * enforced independently of count/until so a pathological rule (e.g.
     * count=10000, or neither count nor until set) can never loop unbounded.
     *
     * @param array  $rule        Recurrence rule (see above).
     * @param string $anchor_date The first occurrence date (Y-m-d) — normally
     *                            the parent's start_date.
     * @return array<int,array{date:string,start_time:string,end_time:string,label:string,capacity:int}>
     *         Ordered ascending, deduped by date.
     */
    public function expand_recurrence( array $rule, $anchor_date ) {
        $anchor_date = $this->normalize_date( (string) $anchor_date );
        if ( $anchor_date === '' ) {
            return [];
        }
        $anchor_ts = \strtotime( $anchor_date . ' 00:00:00' );
        if ( $anchor_ts === false ) {
            return [];
        }

        $freq     = ( ( $rule['freq'] ?? '' ) === 'monthly' ) ? 'monthly' : 'weekly';
        $interval = \max( 1, (int) ( $rule['interval'] ?? 1 ) );

        $count = null;
        if ( isset( $rule['count'] ) && $rule['count'] !== '' ) {
            $count = \max( 0, (int) $rule['count'] );
        }
        $until    = isset( $rule['until'] ) ? $this->normalize_date( (string) $rule['until'] ) : '';
        $until_ts = $until !== '' ? \strtotime( $until . ' 00:00:00' ) : null;

        // The cap always applies, independent of count/until — a rule with
        // neither terminator stops at the cap instead of looping unbounded.
        $limit = ( $count !== null && $count > 0 ) ? \min( $count, self::RECURRENCE_MAX_ROWS ) : self::RECURRENCE_MAX_ROWS;
        if ( $count === 0 ) {
            $limit = 0;
        }

        $date_timestamps = ( $freq === 'monthly' )
            ? $this->expand_monthly_timestamps( $anchor_ts, $interval, $limit, $until_ts )
            : $this->expand_weekly_timestamps( $anchor_ts, $interval, $limit, $until_ts, $rule['weekdays'] ?? null );

        $start_time = $this->normalize_time( (string) ( $rule['start_time'] ?? '' ) );
        $end_time   = $this->normalize_time( (string) ( $rule['end_time'] ?? '' ) );
        $label      = \sanitize_text_field( (string) ( $rule['label'] ?? '' ) );
        $capacity   = \max( 0, (int) ( $rule['capacity'] ?? 0 ) );

        $rows = [];
        $seen = [];
        foreach ( $date_timestamps as $ts ) {
            $date = \date( 'Y-m-d', $ts );
            if ( isset( $seen[ $date ] ) ) {
                continue;
            }
            $seen[ $date ] = true;
            $rows[]        = [
                'date'       => $date,
                'start_time' => $start_time,
                'end_time'   => $end_time,
                'label'      => $label,
                'capacity'   => $capacity,
            ];
        }
        return $rows;
    }

    /**
     * Weekly-frequency timestamp expansion for expand_recurrence(). Walks
     * active weeks (every $interval weeks starting from the anchor's week)
     * and, within each, every listed weekday in ascending order — which keeps
     * the overall sequence strictly ascending since weeks are always more
     * than 6 days apart. Stops on $limit rows, on the first candidate whose
     * date exceeds $until_ts, or on a generous internal iteration ceiling
     * (defensive; normal termination is always via $limit or $until_ts).
     *
     * @param int      $anchor_ts
     * @param int      $interval
     * @param int      $limit
     * @param int|null $until_ts
     * @param mixed    $weekdays_raw
     * @return int[] Ascending, unix timestamps at local midnight.
     */
    private function expand_weekly_timestamps( $anchor_ts, $interval, $limit, $until_ts, $weekdays_raw ) {
        if ( $limit <= 0 ) {
            return [];
        }

        $weekdays = [];
        if ( \is_array( $weekdays_raw ) ) {
            foreach ( $weekdays_raw as $wd ) {
                $wd = (int) $wd;
                if ( $wd >= 0 && $wd <= 6 ) {
                    $weekdays[] = $wd;
                }
            }
            $weekdays = \array_values( \array_unique( $weekdays ) );
            \sort( $weekdays );
        }
        if ( empty( $weekdays ) ) {
            $weekdays = [ (int) \date( 'w', $anchor_ts ) ];
        }

        $anchor_dow    = (int) \date( 'w', $anchor_ts );
        $week_start_ts = \strtotime( '-' . $anchor_dow . ' days', $anchor_ts );

        $out              = [];
        $max_week_index   = self::RECURRENCE_MAX_ROWS * 8; // defensive ceiling; see docblock.
        for ( $week_index = 0; $week_index < $max_week_index; $week_index++ ) {
            $this_week_start = \strtotime( '+' . ( $week_index * $interval * 7 ) . ' days', $week_start_ts );

            foreach ( $weekdays as $wd ) {
                $date_ts = \strtotime( '+' . $wd . ' days', $this_week_start );
                if ( $date_ts < $anchor_ts ) {
                    continue; // Before the series' own first occurrence.
                }
                if ( $until_ts !== null && $date_ts > $until_ts ) {
                    return $out; // Ascending order guaranteed -> nothing later qualifies.
                }
                $out[] = $date_ts;
                if ( \count( $out ) >= $limit ) {
                    return $out;
                }
            }
        }
        return $out;
    }

    /**
     * Monthly-frequency timestamp expansion for expand_recurrence(). Walks
     * months every $interval months starting at the anchor's month; a month
     * that doesn't have the anchor's day-of-month (short-month case, e.g. day
     * 31 in a 30-day month) is SKIPPED ENTIRELY (documented choice — no
     * roll-forward/back). Uses the target month's 1st as a monotonic
     * termination proxy against $until_ts so a run of skipped months can't
     * defeat the until check.
     *
     * @param int      $anchor_ts
     * @param int      $interval
     * @param int      $limit
     * @param int|null $until_ts
     * @return int[] Ascending, unix timestamps at local midnight.
     */
    private function expand_monthly_timestamps( $anchor_ts, $interval, $limit, $until_ts ) {
        if ( $limit <= 0 ) {
            return [];
        }

        $anchor_day = (int) \date( 'j', $anchor_ts );

        $out             = [];
        $max_iterations  = self::RECURRENCE_MAX_ROWS * 4; // covers the worst case (day 31, mostly-short months).
        for ( $month_offset = 0; $month_offset < $max_iterations; $month_offset++ ) {
            [ $year, $month ] = $this->add_months( $anchor_ts, $month_offset * $interval );

            $first_of_month_ts = \mktime( 0, 0, 0, $month, 1, $year );
            if ( $until_ts !== null && $first_of_month_ts > $until_ts ) {
                break; // Every later month is even further past $until_ts.
            }

            $days_in_month = (int) \date( 't', $first_of_month_ts );
            if ( $anchor_day > $days_in_month ) {
                continue; // Short month — skip entirely, do not roll over.
            }

            $date_ts = \mktime( 0, 0, 0, $month, $anchor_day, $year );
            if ( $date_ts < $anchor_ts ) {
                continue;
            }
            if ( $until_ts !== null && $date_ts > $until_ts ) {
                break;
            }

            $out[] = $date_ts;
            if ( \count( $out ) >= $limit ) {
                break;
            }
        }
        return $out;
    }

    /**
     * Add $months_offset calendar months to $anchor_ts's year/month,
     * returning [year, month] (month always 1..12, year rolls over
     * correctly). The day-of-month is deliberately NOT part of this helper —
     * callers decide short-month handling themselves.
     *
     * @param int $anchor_ts
     * @param int $months_offset
     * @return array{0:int,1:int}
     */
    private function add_months( $anchor_ts, $months_offset ) {
        $anchor_year  = (int) \date( 'Y', $anchor_ts );
        $anchor_month = (int) \date( 'n', $anchor_ts );

        $zero_based = ( $anchor_month - 1 ) + $months_offset;
        $year       = $anchor_year + \intdiv( $zero_based, 12 );
        $month      = $zero_based % 12;
        if ( $month < 0 ) {
            $month += 12;
            $year--;
        }
        return [ $year, $month + 1 ];
    }

    /* ═══════════════════════════════════════════════════════════
       Reconcile internals
       ═══════════════════════════════════════════════════════════ */

    /**
     * Create a new child event post for a desired occurrence row.
     *
     * @param int    $parent_id
     * @param array  $row       Normalized offering-dates row (date/start_time/end_time/label/capacity).
     * @param string $key       occurrence_key (== $row['date']).
     * @return int New child post id, or 0 on failure.
     */
    private function create_child( $parent_id, array $row, $key ) {
        $parent_meta = $this->module->get_meta( $parent_id );

        $child_id = \wp_insert_post( [
            'post_type'    => Module::CPT,
            'post_status'  => 'publish',
            'post_title'   => $this->child_title( $parent_id, $row ),
            'post_content' => (string) \get_post_field( 'post_content', $parent_id ),
            'post_excerpt' => (string) \get_post_field( 'post_excerpt', $parent_id ),
        ], true );

        if ( \is_wp_error( $child_id ) || ! $child_id ) {
            return 0;
        }
        $child_id = (int) $child_id;

        // Engine-owned identity meta.
        \update_post_meta( $child_id, $this->module->meta_key( 'group_role' ), 'child' );
        \update_post_meta( $child_id, $this->module->meta_key( 'group_id' ), $parent_id );
        \update_post_meta( $child_id, $this->module->meta_key( 'occurrence_key' ), $key );
        \update_post_meta( $child_id, $this->module->meta_key( 'occurrence_closed' ), false );

        // A child occurrence is always a plain single event.
        \update_post_meta( $child_id, $this->module->meta_key( 'type' ), 'single' );

        // Availability is seeded from the parent ONCE, at creation, so a new date
        // inherits the group's default — and is never re-applied, because it is a
        // per-occurrence key from here on (see PER_OCCURRENCE_KEYS).
        \update_post_meta( $child_id, $this->module->meta_key( 'registration_enabled' ), ! empty( $parent_meta['registration_enabled'] ) );
        \update_post_meta( $child_id, $this->module->meta_key( 'sold_out' ), false );

        // Per-occurrence date/capacity meta, set ONCE from the row (falling
        // back to the parent's current capacity default when the row carries
        // no override).
        $this->apply_occurrence_dates( $child_id, $row, $parent_meta );

        // Shared fields (title[+suffix] handled above already; everything
        // else copied here), ticket tiers, and product sync.
        $this->sync_shared_meta( $parent_id, $child_id, $parent_meta );
        $this->sync_ticket_types( $parent_id, $child_id, $row );
        $this->sync_product( $child_id, $parent_meta );

        return $child_id;
    }

    /**
     * Re-sync an existing live child from the parent (shared fields + title +
     * ticket tiers + product), WITHOUT touching its own date/capacity/status
     * or seats.
     *
     * @param int   $parent_id
     * @param int   $child_id
     * @param array $row Matched offering-dates row (used only for the title suffix).
     */
    private function sync_child_from_parent( $parent_id, $child_id, array $row ) {
        $parent_meta = $this->module->get_meta( $parent_id );

        \wp_update_post( [
            'ID'           => $child_id,
            'post_title'   => $this->child_title( $parent_id, $row ),
            'post_content' => (string) \get_post_field( 'post_content', $parent_id ),
            'post_excerpt' => (string) \get_post_field( 'post_excerpt', $parent_id ),
        ] );

        // Per-occurrence time/capacity (row override, else parent default),
        // re-applied on every reconcile so editing a row's time/capacity/label
        // propagates to the already-materialized child (spec Fix 2.1a). Date
        // identity, status, and seats/roster are never touched here.
        $this->apply_occurrence_editable_fields( $child_id, $row, $parent_meta );

        // A still-closed occurrence gets its four-field closed state re-asserted
        // before anything downstream reads it (audit MODEL-D6 / WOO-D35): the
        // quartet lives in PER_OCCURRENCE_KEYS, so nothing else here would ever
        // repair a row whose `status` a later save recomputed while the flag
        // stayed set, and nothing downstream should read a half-written row.
        // reconcile()'s matched branch calls
        // revive_if_closed() straight after this, so a date that IS back on the
        // parent still reopens; this only normalises the state on the way.
        if ( $this->is_closed( $child_id ) ) {
            $this->soft_close( $child_id );
        }

        $this->sync_shared_meta( $parent_id, $child_id, $parent_meta );
        $this->sync_ticket_types( $parent_id, $child_id, $row );
        $this->sync_product( $child_id, $parent_meta );
    }

    /**
     * Write the PER_OCCURRENCE_KEYS meta on a freshly-created child: its own
     * date identity (from the row, set ONCE and never touched again), its
     * editable time/capacity fields (delegated to
     * apply_occurrence_editable_fields() so creation and later re-syncs share
     * one code path), and derived start_ts/end_ts + auto status (reusing the
     * Module's own timestamp/status calculation).
     *
     * @param int   $child_id
     * @param array $row
     * @param array $parent_meta
     */
    private function apply_occurrence_dates( $child_id, array $row, array $parent_meta ) {
        $mk = function ( $k ) {
            return $this->module->meta_key( $k );
        };

        // Date identity — the START date, set ONCE at creation and never
        // re-applied by a later reconcile (children are matched on it). The END
        // date is NOT identity: it is written by apply_occurrence_editable_fields
        // below, so adding a second day to an existing row still propagates.
        \update_post_meta( $child_id, $mk( 'start_date' ), $row['date'] );

        $this->apply_occurrence_editable_fields( $child_id, $row, $parent_meta );

        $start_time = $row['start_time'];
        $end_time   = $row['end_time'] !== '' ? $row['end_time'] : $start_time;
        $occurrence_meta = [
            'start_date' => $row['date'],
            'end_date'   => ( $row['end_date'] ?? '' ) !== '' ? $row['end_date'] : $row['date'],
            'start_time' => $start_time,
            'end_time'   => $end_time,
            'all_day'    => ! empty( $parent_meta['all_day'] ),
            'timezone'   => (string) ( $parent_meta['timezone'] ?? '' ),
        ];

        \update_post_meta( $child_id, $mk( 'status_mode' ), 'auto' );
        \update_post_meta( $child_id, $mk( 'status' ), $this->module->compute_status( $occurrence_meta ) );
    }

    /**
     * Apply the row's NON-IDENTITY per-occurrence fields — start_time,
     * end_time, and capacity (parent-row-wins) — to a child, and recompute
     * start_ts/end_ts from those plus the child's OWN, immutable
     * start_date/end_date. Called both at creation (create_child, via
     * apply_occurrence_dates, after the date identity is set) and on every
     * reconcile of an already-matched child (sync_child_from_parent), so
     * editing a row's start_time/end_time/capacity/label later propagates to
     * an already-materialized child instead of silently no-op'ing. The row's
     * `label` itself is applied separately, via the post_title suffix
     * (child_title()) — already re-applied on every sync. Never touches the
     * child's date identity (occurrence_key/start_date/end_date), status, or
     * seats/roster.
     *
     * @param int   $child_id
     * @param array $row
     * @param array $parent_meta
     */
    private function apply_occurrence_editable_fields( $child_id, array $row, array $parent_meta ) {
        $mk = function ( $k ) {
            return $this->module->meta_key( $k );
        };

        $start_time = $row['start_time'];
        $end_time   = $row['end_time'] !== '' ? $row['end_time'] : $start_time;
        $capacity   = $row['capacity'] > 0 ? $row['capacity'] : (int) ( $parent_meta['capacity'] ?? 0 );

        \update_post_meta( $child_id, $mk( 'start_time' ), $start_time );
        \update_post_meta( $child_id, $mk( 'end_time' ), $end_time );
        \update_post_meta( $child_id, $mk( 'capacity' ), $capacity );

        // The child's start date is its identity and is read, never written,
        // here. Its end date follows the row, so a one-day occurrence that
        // becomes two days updates in place; an empty row value means same-day.
        $start_date = (string) \get_post_meta( $child_id, $mk( 'start_date' ), true );
        $row_end    = (string) ( $row['end_date'] ?? '' );
        $end_date   = ( $row_end !== '' && $row_end >= $start_date ) ? $row_end : $start_date;
        \update_post_meta( $child_id, $mk( 'end_date' ), $end_date );

        $occurrence_meta = [
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'start_time' => $start_time,
            'end_time'   => $end_time,
            'all_day'    => ! empty( $parent_meta['all_day'] ),
            'timezone'   => (string) ( $parent_meta['timezone'] ?? '' ),
        ];
        $this->module->persist_timestamps( $child_id, $occurrence_meta );
    }

    /**
     * Copy every SHARED meta key (parent meta minus PER_OCCURRENCE_KEYS minus
     * NEVER_COPY_KEYS) from parent to child.
     *
     * @param int        $parent_id
     * @param int        $child_id
     * @param array|null $parent_meta Pre-fetched parent meta (avoids a re-read).
     */
    private function sync_shared_meta( $parent_id, $child_id, ?array $parent_meta = null ) {
        $parent_meta = $parent_meta ?? $this->module->get_meta( $parent_id );

        $excluded = \array_flip( \array_merge( self::PER_OCCURRENCE_KEYS, self::NEVER_COPY_KEYS ) );
        $shared   = \array_diff_key( $parent_meta, $excluded );

        foreach ( $shared as $key => $value ) {
            \update_post_meta( $child_id, $this->module->meta_key( $key ), $value );
        }
    }

    /**
     * Copy the parent's ticket-tier rows to the child, resetting each row's
     * `wc_variation_id` to 0 — each child gets its OWN managed product /
     * variations via sync_product(); the parent's variation ids never belong
     * on a child's tiers. An empty parent tier list clears the child's list
     * too (both fall back to the same implicit-primary-from-price tier).
     *
     * @param int $parent_id
     * @param int $child_id
     */
    private function sync_ticket_types( $parent_id, $child_id, array $occurrence_row = [] ) {
        $raw = \get_post_meta( $parent_id, Ticket_Types::META_KEY, true );
        if ( ! \is_array( $raw ) || empty( $raw ) ) {
            $this->module->ticket_types->save( $child_id, [] );
            return;
        }

        // When the occurrence names its own tier, the child gets THAT tier only.
        // Copying the parent's whole list onto every child is right when tiers
        // are prices shared across all dates (early bird / student), and wrong
        // when each tier IS a date — that produced a December occurrence selling
        // an October ticket. The link is what tells the two apart.
        $tier_id = \sanitize_key( (string) ( $occurrence_row['tier_id'] ?? '' ) );

        $rows = [];
        foreach ( $raw as $row ) {
            if ( ! \is_array( $row ) ) {
                continue;
            }
            if ( $tier_id !== '' && \sanitize_key( (string) ( $row['id'] ?? '' ) ) !== $tier_id ) {
                continue;
            }
            // Always cleared: the child's variation is its own, created by
            // Product_Sync against the child's product, not the parent's.
            $row['wc_variation_id'] = 0;
            $rows[]                 = $row;
        }

        // A link that matches nothing would silently sell nothing, so fall back
        // to the full list rather than leaving the occurrence unbookable.
        if ( $tier_id !== '' && empty( $rows ) ) {
            foreach ( $raw as $row ) {
                if ( ! \is_array( $row ) ) {
                    continue;
                }
                $row['wc_variation_id'] = 0;
                $rows[]                 = $row;
            }
        }

        $this->module->ticket_types->save( $child_id, $rows );
    }

    /**
     * Ensure the child has its own managed WooCommerce product when the
     * parent's registration_mode is 'wc'. No-op (and product_sync itself is
     * idempotent) otherwise, mirroring how a single event is already synced.
     *
     * @param int   $child_id
     * @param array $parent_meta
     */
    private function sync_product( $child_id, array $parent_meta ) {
        if ( ( $parent_meta['registration_mode'] ?? '' ) !== 'wc' ) {
            return;
        }
        if ( ! $this->module->product_sync ) {
            return;
        }
        $this->module->product_sync->sync_event( $child_id );
    }

    /**
     * Retire an existing child whose occurrence is no longer desired:
     * soft-close when it has any seats (roster-preserving), else trash it.
     *
     * @param int $child_id
     */
    private function retire_child( $child_id ) {
        if ( $this->has_seats( $child_id ) ) {
            $this->soft_close( $child_id );
            return;
        }
        if ( $this->is_closed( $child_id ) ) {
            // Already soft-closed with (now) no seats — keep the preserved
            // occurrence rather than surprise-trashing it, but re-assert the
            // four-field closed state so a row that only carries part of it
            // (audit MODEL-D6: production child 7530 kept occurrence_closed=1
            // while a later save recomputed status to 'past') is repaired here
            // too. soft_close() is idempotent, so a complete row is untouched.
            $this->soft_close( $child_id );
            return;
        }
        \wp_trash_post( $child_id );
    }

    /**
     * Soft-close a child: preserve the post + roster, mark it closed via the
     * existing status vocabulary (manual/cancelled + registration disabled)
     * plus the engine's own closed flag.
     *
     * All FOUR fields are written unconditionally, every time (audit MODEL-D6 /
     * WOO-D35). The old `is_closed()` early return made the flag the guard for
     * its own three companions, so a row carrying only part of the state — a
     * later save recomputing `status`, a hand-edited meta row, a restore — could
     * never be repaired by anything: soft_close() saw "already closed" and did
     * nothing. Re-asserting the state changes no VALUE — update_post_meta()
     * short-circuits when the stored string already matches (the two boolean
     * keys still issue their UPDATE, since `true`/`false` do not compare equal
     * to the stored '1'/'') — so there are no extra meta rows, no extra
     * revisions and no roster side effects.
     *
     * @param int $child_id
     */
    private function soft_close( $child_id ) {
        $mk = function ( $k ) {
            return $this->module->meta_key( $k );
        };
        \update_post_meta( $child_id, $mk( 'status_mode' ), 'manual' );
        \update_post_meta( $child_id, $mk( 'status' ), 'cancelled' );
        \update_post_meta( $child_id, $mk( 'registration_enabled' ), false );
        \update_post_meta( $child_id, $mk( 'occurrence_closed' ), true );
    }

    /**
     * Revive a previously soft-closed child whose occurrence_key has been
     * re-added to the parent's offering_dates: clear the closed flag and
     * restore auto status, WITHOUT touching its date or seats. Does NOT
     * force registration_enabled — that's a SHARED field already re-synced
     * from the parent by sync_child_from_parent() (called first, in
     * reconcile()'s matched branch), so a parent with registration disabled
     * stays disabled on the revived child instead of being force-enabled.
     * No-op if the child isn't currently closed.
     *
     * The `occurrence_closed` flag is the ONLY reopening trigger, on purpose.
     * Inferring closure from the status half of the quartet (manual +
     * cancelled + registration off) reads identically to an admin cancelling a
     * still-offered date and unchecking registration — and on a parent whose
     * own registration is off, every hand-cancelled child looks like that from
     * birth (create_child() seeds the flag from the parent). Reviving on that
     * signature would silently flip a deliberate cancellation back to
     * auto/upcoming on the next parent save. A row whose flag was cleared by
     * hand is instead repaired by the CLOSING side: retire_child() /
     * soft_close() re-assert all four keys unconditionally (audit MODEL-D6).
     *
     * @param int $child_id
     */
    private function revive_if_closed( $child_id ) {
        if ( ! $this->is_closed( $child_id ) ) {
            return;
        }
        $mk = function ( $k ) {
            return $this->module->meta_key( $k );
        };
        $meta = $this->module->get_meta( $child_id );

        \update_post_meta( $child_id, $mk( 'occurrence_closed' ), false );
        \update_post_meta( $child_id, $mk( 'status_mode' ), 'auto' );
        \update_post_meta( $child_id, $mk( 'status' ), $this->module->compute_status( $meta ) );
    }

    /**
     * Find-or-create a stable event_series term derived from the parent
     * ("group-{parent_id}" slug, parent title as the name) and assign it to
     * the parent + every live child.
     *
     * @param int   $parent_id
     * @param int[] $live_child_ids
     */
    private function assign_series( $parent_id, array $live_child_ids ) {
        if ( ! \taxonomy_exists( Series::TAXONOMY ) ) {
            return;
        }

        $slug = 'group-' . $parent_id;
        $name = (string) \get_the_title( $parent_id );
        if ( $name === '' ) {
            $name = $slug;
        }

        $term = \get_term_by( 'slug', $slug, Series::TAXONOMY );
        if ( ! $term ) {
            $result = \wp_insert_term( $name, Series::TAXONOMY, [ 'slug' => $slug ] );
            if ( \is_wp_error( $result ) ) {
                return;
            }
            $term_id = (int) $result['term_id'];
        } else {
            $term_id = (int) $term->term_id;
            if ( $term->name !== $name ) {
                \wp_update_term( $term_id, Series::TAXONOMY, [ 'name' => $name ] );
            }
        }

        \wp_set_object_terms( $parent_id, [ $term_id ], Series::TAXONOMY, false );
        foreach ( $live_child_ids as $child_id ) {
            \wp_set_object_terms( (int) $child_id, [ $term_id ], Series::TAXONOMY, false );
        }
    }

    /* ═══════════════════════════════════════════════════════════
       Small helpers
       ═══════════════════════════════════════════════════════════ */

    /**
     * Set the group_role meta on a post (idempotent: skips the write when
     * unchanged).
     *
     * @param int    $id
     * @param string $role 'parent'|'child'|''.
     */
    private function set_group_role( $id, $role ) {
        $key = $this->module->meta_key( 'group_role' );
        if ( \get_post_meta( $id, $key, true ) !== $role ) {
            \update_post_meta( $id, $key, $role );
        }
    }

    /**
     * Whether an occurrence is currently soft-closed.
     *
     * Public because the listing/notification layer needs the same predicate:
     * run_reminder_sweep() skips a soft-closed date rather than mailing its
     * roster "…is coming up" (audit MODEL-D17).
     *
     * @param int $child_id
     * @return bool
     */
    public function is_closed( $child_id ) {
        return (bool) \get_post_meta( $child_id, $this->module->meta_key( 'occurrence_closed' ), true );
    }

    /**
     * Whether a child has any seat (registration) rows at all, any status —
     * the roster-preservation trigger. Reuses Registrations::query_seats()
     * unchanged (spec constraint: never touch seat internals directly).
     *
     * @param int $child_id
     * @return bool
     */
    private function has_seats( $child_id ) {
        $result = $this->module->registrations->query_seats( [
            'event_id' => $child_id,
            'status'   => 'all',
            'per_page' => 1,
        ] );
        return ( (int) ( $result['total'] ?? 0 ) ) > 0;
    }

    /**
     * occurrence_key => child post id map for ALL of a parent's children
     * (live + soft-closed; trashed posts are excluded because they're
     * queried out by post_status=publish).
     *
     * @param int $parent_id
     * @return array<string,int>
     */
    private function existing_children_map( $parent_id, $include_trashed = false ) {
        $ids = \get_posts( [
            'post_type'      => Module::CPT,
            'post_status'    => $include_trashed ? [ 'publish', 'trash' ] : 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => $this->module->meta_key( 'group_role' ), 'value' => 'child', 'compare' => '=' ],
                [ 'key' => $this->module->meta_key( 'group_id' ), 'value' => (int) $parent_id, 'compare' => '=', 'type' => 'NUMERIC' ],
            ],
        ] );

        $map = [];
        foreach ( $ids as $id ) {
            $id  = (int) $id;
            $key = (string) \get_post_meta( $id, $this->module->meta_key( 'occurrence_key' ), true );
            if ( $key === '' ) {
                continue;
            }
            $map[ $key ] = $id;
        }
        return $map;
    }

    /**
     * Build a child's title: "<parent title> — <row label, or formatted date>".
     *
     * @param int   $parent_id
     * @param array $row
     * @return string
     */
    private function child_title( $parent_id, array $row ) {
        $label = $row['label'] !== '' ? $row['label'] : $this->format_date_label( $row['date'] );
        return (string) \get_the_title( $parent_id ) . ' — ' . $label;
    }

    /**
     * Human date label ("Jan 5, 2027") for a Y-m-d date string.
     *
     * @param string $date
     * @return string
     */
    private function format_date_label( $date ) {
        $ts = \strtotime( $date );
        return $ts ? \date_i18n( 'M j, Y', $ts ) : $date;
    }

    /**
     * A child's start_ts (0 if unset) — used only for display ordering.
     *
     * @param int $child_id
     * @return int
     */
    private function start_ts( $child_id ) {
        return (int) \get_post_meta( $child_id, $this->module->meta_key( 'start_ts' ), true );
    }

    /**
     * The unified "desired dates" resolver reconcile() drives off of (spec
     * Phase 2, Task 2.2): branches on the parent's `_anchor_event_type` to
     * pick the date SOURCE only — everything downstream (create/soft-close/
     * revive/idempotency) is the exact same reconcile() code path for both
     * event types.
     *   - `recurring` -> expand_recurrence() over the parent's `recurrence`
     *                    rule, anchored at the parent's own start_date.
     *   - anything else (incl. `offering`) -> the existing offering_dates
     *                    path, unchanged.
     *
     * @param int $parent_id
     * @return array<int,array>
     */
    private function get_desired_dates( $parent_id ) {
        $type = (string) \get_post_meta( $parent_id, $this->module->meta_key( 'type' ), true );
        if ( $type === 'recurring' ) {
            $rule = \get_post_meta( $parent_id, $this->module->meta_key( 'recurrence' ), true );
            $rule = \is_array( $rule ) ? $rule : [];
            $anchor_date = (string) \get_post_meta( $parent_id, $this->module->meta_key( 'start_date' ), true );
            return $this->expand_recurrence( $rule, $anchor_date );
        }
        return $this->get_offering_dates( $parent_id );
    }

    /**
     * Normalized, deduped list of the parent's desired offering-date rows.
     * Each row: date (Y-m-d), start_time (H:i or ''), end_time (H:i or ''),
     * label (string), capacity (int, 0 = use the parent's default). Rows with
     * no parseable date are dropped; a duplicate date keeps the FIRST row.
     *
     * @param int $parent_id
     * @return array<int,array>
     */
    private function get_offering_dates( $parent_id ) {
        $raw = \get_post_meta( $parent_id, $this->module->meta_key( 'offering_dates' ), true );
        if ( ! \is_array( $raw ) ) {
            return [];
        }

        $out  = [];
        $seen = [];
        foreach ( $raw as $row ) {
            if ( ! \is_array( $row ) ) {
                continue;
            }
            $date = $this->normalize_date( (string) ( $row['date'] ?? '' ) );
            if ( $date === '' || isset( $seen[ $date ] ) ) {
                continue;
            }
            $seen[ $date ] = true;

            // end_date lets one offering span more than a day (a two-day
            // masterclass is one occurrence, not two). '' means same-day, which
            // is what every row written before this field existed means.
            $end_date = $this->normalize_date( (string) ( $row['end_date'] ?? '' ) );
            if ( $end_date !== '' && $end_date < $date ) {
                $end_date = '';
            }

            $out[] = [
                'date'       => $date,
                'end_date'   => $end_date,
                'start_time' => $this->normalize_time( (string) ( $row['start_time'] ?? '' ) ),
                'end_time'   => $this->normalize_time( (string) ( $row['end_time'] ?? '' ) ),
                'label'      => \sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
                'capacity'   => \max( 0, (int) ( $row['capacity'] ?? 0 ) ),
                // Which of the parent's ticket tiers is THIS date's ticket. '' =
                // unlinked, which keeps the pre-existing copy-every-tier behaviour
                // for groups that use tiers as prices rather than as dates.
                'tier_id'    => \sanitize_key( (string) ( $row['tier_id'] ?? '' ) ),
            ];
        }
        return $out;
    }

    /**
     * Normalize a date to Y-m-d, or '' when unparseable.
     *
     * @param string $date
     * @return string
     */
    private function normalize_date( $date ) {
        $date = \trim( $date );
        if ( $date === '' ) {
            return '';
        }
        if ( \preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return $date;
        }
        $ts = \strtotime( $date );
        return $ts ? \date( 'Y-m-d', $ts ) : '';
    }

    /**
     * Normalize a time to H:i, or '' when unparseable/blank.
     *
     * @param string $time
     * @return string
     */
    private function normalize_time( $time ) {
        $time = \trim( $time );
        if ( $time === '' ) {
            return '';
        }
        if ( \preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
            return $time;
        }
        $ts = \strtotime( $time );
        return $ts ? \date( 'H:i', $ts ) : '';
    }
}
