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
 *     of a still-live child, so editing the parent propagates): an EXPLICIT
 *     allow-list — INHERITED_KEYS (the shared schema facts: location fields,
 *     timezone, all_day, registration policy, price, gallery, labels,
 *     external_*, organizer_email, reminder_offsets) plus the event meta that
 *     lives outside the schema and so was invisible to the old
 *     "schema minus exclusions" definition: the registration questions and
 *     every per-event email override. inherited_meta_keys() assembles the
 *     full list and `anchor_events_inherited_meta_keys` filters it.
 *     Only keys the parent has a REAL row for are copied — never a default
 *     get_meta() synthesized at read time — and a key the parent no longer
 *     has is deleted from the child, so clearing a value propagates too
 *     (audit MODEL-D7 / MODEL-D37). Ticket tiers and the product are synced
 *     separately (sync_ticket_types()/sync_product()). A child's `type` meta
 *     is force-set to 'single' (never inherits 'offering'/'recurring')
 *     because each occurrence is itself a plain single event.
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
 * stable `occurrence_key` (the row's normalized "<date>|<start time>"
 * identity — see occurrence_key()) stored on the child at creation.
 * reconcile() is a pure function of (parent's
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
        // The occurrence's authored label (audit MODEL-D10/MODEL-D27): the
        // offering row's own text, re-applied from the row on every reconcile
        // like the other editable fields, and NEVER copied from the parent's
        // same-named meta — the parent has no occurrence label of its own.
        'label',
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
     * The SHARED event facts an occurrence child inherits from its parent —
     * schema keys, WITHOUT the `_anchor_event_` prefix (audit MODEL-D7).
     *
     * This is an explicit allow-list, not "the schema minus the two exclusion
     * lists above". The subtraction definition had two failure modes:
     *
     *   - Every event meta key OUTSIDE get_meta_defaults() was invisible to
     *     it, so the registration questions and the whole per-event email
     *     override set never reached a child (they are enumerated in
     *     inherited_meta_keys() below, which is what the copy actually
     *     iterates).
     *   - A new schema key silently became inherited the moment it was added,
     *     with nobody deciding that it should be.
     *
     * The membership here is exactly what the old subtraction produced, so
     * behaviour for schema keys is unchanged: everything in
     * get_meta_defaults() that is in neither PER_OCCURRENCE_KEYS nor
     * NEVER_COPY_KEYS. Adding a schema key now means deciding, here, whether
     * an occurrence shares it with its siblings.
     */
    const INHERITED_KEYS = [
        'timezone',
        'all_day',
        'venue',
        'address_street',
        'address_city',
        'address_state',
        'address_zip',
        'address_country',
        'virtual',
        'virtual_url',
        'registration_open',
        'registration_close',
        'waitlist',
        'registration_type',
        'registration_url',
        'price',
        'hide_from_archive',
        'featured',
        'priority',
        'gallery',
        'organizer_email',
        'reminder_offsets',
        'labels',
        'registration_mode',
        'external_url',
        'external_embed',
        'external_display_price',
    ];

    /**
     * The `_anchor_event_` suffixes of the per-event email overrides, one set
     * per EMAIL_TEMPLATE_TYPES entry. Assembled in inherited_meta_keys() from
     * the module's own type list rather than written out, so a fifth email
     * type inherits the day it is added.
     *
     * Sender identity (From / Reply-To / Cc / Bcc) is per event, not per
     * type, so it is listed separately.
     */
    const EMAIL_OVERRIDE_PER_TYPE = [
        'tpl',
        'off',
        'subject',
        'preheader',
        'intro',
        'cta_label',
        'cta_url',
        'cta2_label',
        'cta2_url',
    ];
    const EMAIL_SENDER_KEYS = [
        'email_from_name',
        'email_from_address',
        'email_reply_to_name',
        'email_reply_to_address',
        'email_cc',
        'email_bcc',
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

        $desired_map = $this->desired_rows_by_key( $parent_id );

        // Trashed children are included on purpose. Removing a date trashes its
        // occurrence; adding the same date back must revive THAT occurrence, not
        // build a second one beside it. Without this the original stays in the
        // trash for ever and the date silently acquires a duplicate post.
        // Duplicates (two children on one occurrence_key) are collapsed to a
        // single canonical child here, so everything below still works with a
        // plain occurrence_key => child_id map.
        $existing_map = $this->collapse_duplicate_children(
            $parent_id,
            $this->existing_children_map( $parent_id, true ) // any status + trash
        );

        $live_ids = [];
        // Which existing keys a desired row has taken. An existing child is
        // matched by its key OR, failing that, by its date alone (see
        // rekeyed_match()), so "still desired" cannot be re-derived from
        // $desired_map at retire time — it is recorded as it happens.
        $matched_keys = [];

        foreach ( $desired_map as $key => $row ) {
            $existing_key = isset( $existing_map[ $key ] )
                ? $key
                : $this->rekeyed_match( $key, $desired_map, $existing_map, $matched_keys );

            if ( $existing_key !== '' ) {
                $matched_keys[ $existing_key ] = true;
                $child_id = (int) $existing_map[ $existing_key ];
                // A wanted date whose occurrence is in the trash comes back out,
                // keeping its id, its roster and its history. Every OTHER status
                // is left exactly as the admin set it: a child moved to
                // draft/pending/private is matched and synced like any other
                // occurrence (MODEL-D9) but is not force-published, so hiding one
                // date sticks. children() is what keeps it off the picker.
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
            if ( isset( $matched_keys[ $key ] ) ) {
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
     * The stable identity of one occurrence row: its start DATE plus its start
     * TIME, joined by a pipe ("2026-11-07|09:00"; "2026-11-07|" when the row
     * carries no time).
     *
     * The key used to be the date alone (audit MODEL-D8), so a parent offering
     * a morning and an afternoon session on the same day silently kept only
     * the first row: the metabox showed two live rows and one generated date,
     * and the second row's tier/capacity/end_date were simply gone. Time is
     * part of identity because it is the only authored field that distinguishes
     * two sessions on one day. It is ALSO an editable field, so re-timing a row
     * must not orphan its occurrence — reconcile() re-keys the existing child
     * instead (see rekeyed_match()).
     *
     * Public because the save path dedupes against the same identity before it
     * persists a row (Module::sanitize_offering_dates_rows()); two spellings of
     * "the same occurrence" is exactly the drift this key exists to prevent.
     *
     * @param array $row Offering-dates row (or a recurrence-expanded row).
     * @return string
     */
    public function occurrence_key( array $row ) {
        return $this->normalize_date( (string) ( $row['date'] ?? '' ) )
            . '|' . $this->normalize_time( (string) ( $row['start_time'] ?? '' ) );
    }

    /**
     * A parent's desired occurrence rows, keyed by occurrence_key() — the one
     * answer to "which occurrences should this parent have?", for BOTH group
     * types (get_desired_dates() picks the source: offering rows or an expanded
     * recurrence rule).
     *
     * Public so nothing has to re-derive it from `offering_dates` alone and
     * quietly miss recurring parents, which is exactly what the label back-fill
     * (Module::backfill_occurrence_labels()) would otherwise have done.
     *
     * @param int $parent_id
     * @return array<string,array> occurrence key => row.
     */
    public function desired_rows_by_key( $parent_id ) {
        $map = [];
        foreach ( $this->get_desired_dates( $parent_id ) as $row ) {
            $map[ $this->occurrence_key( $row ) ] = $row;
        }
        return $map;
    }

    /**
     * A child's occurrence key as the current engine spells it: its stored
     * `occurrence_key` meta, or — for a child stamped before the key carried a
     * time (audit MODEL-D8) — that date plus the child's own start time, which
     * is what its row mints today (start_time is re-applied to the child from
     * the row on every reconcile).
     *
     * The one place the stored side is normalized, so the map, the reconcile
     * re-stamp and the label back-fill can never disagree about what a legacy
     * child's key means. Read-only: the upgraded spelling is written on the
     * parent-save path (sync_child_from_parent()), never from a render.
     *
     * @param int $child_id
     * @return string '' when the child carries no key at all.
     */
    public function stored_occurrence_key( $child_id ) {
        $key = (string) \get_post_meta( (int) $child_id, $this->module->meta_key( 'occurrence_key' ), true );
        if ( $key === '' || \strpos( $key, '|' ) !== false ) {
            return $key;
        }
        return $key . '|' . $this->normalize_time(
            (string) \get_post_meta( (int) $child_id, $this->module->meta_key( 'start_time' ), true )
        );
    }

    /**
     * Find the existing child of a desired key whose key does not match it
     * exactly but which is plainly the SAME occurrence, re-timed: same date,
     * not already claimed by another desired row, and not itself a desired key.
     *
     * Start time is identity (occurrence_key()) but is also an editable field
     * an author can change on an existing row. Without this, editing 09:00 to
     * 13:00 would retire the 09:00 occurrence — soft-closing it if it holds a
     * roster — and publish a second post for the same day beside it. It also
     * absorbs the pre-change storage format on the first reconcile after this
     * upgrade for any child whose stored date-only key could not be normalized
     * (existing_children_map() handles the ordinary legacy case exactly).
     *
     * Deliberately conservative: the re-key has to be unambiguous from BOTH
     * sides of the date. Exactly one existing child of that date may be
     * unclaimed (one candidate), AND exactly one desired row of that date may
     * still need a child (one claimant). Either count above one means the
     * pairing would be a guess:
     *
     *   - Two rows on one day both re-timed at once — two candidates, two
     *     claimants — could only be paired by position.
     *   - One 09:00 child re-timed to 10:00 while an 11:00 row is ADDED the
     *     same day is one candidate but TWO claimants: whichever row the loop
     *     reached first would take the existing child, and with it a roster
     *     that belongs to the other session. This is why the candidate count
     *     alone is not enough.
     *
     * An ambiguous date falls back to the ordinary rules: every claimant gets a
     * new child, and the unmatched existing children are retired roster-safely
     * (a seated one is soft-closed, keeping its post and its roster, and is
     * never silently re-pointed at a different session).
     *
     * Deterministic regardless of iteration order: the claimant count is
     * computed from the desired set itself, not from what has been matched so
     * far, so every row of an ambiguous date reaches the same verdict.
     *
     * @param string              $key          Desired occurrence key.
     * @param array<string,array> $desired_map  Every desired key.
     * @param array<string,int>   $existing_map Existing key => canonical child id.
     * @param array<string,bool>  $matched_keys Existing keys already claimed.
     * @return string Existing key to match, or '' for none.
     */
    private function rekeyed_match( $key, array $desired_map, array $existing_map, array $matched_keys ) {
        $date = $this->key_date( $key );
        if ( $date === '' ) {
            return '';
        }

        // Desired rows of this date that no existing child matches exactly, and
        // so are competing for a re-key.
        $claimants = 0;
        foreach ( \array_keys( $desired_map ) as $desired_key ) {
            if ( $this->key_date( $desired_key ) !== $date || isset( $existing_map[ $desired_key ] ) ) {
                continue;
            }
            $claimants++;
        }
        if ( $claimants !== 1 ) {
            return '';
        }

        $candidates = [];
        foreach ( \array_keys( $existing_map ) as $existing_key ) {
            if ( isset( $matched_keys[ $existing_key ] ) || isset( $desired_map[ $existing_key ] ) ) {
                continue; // Belongs to another row.
            }
            if ( $this->key_date( $existing_key ) === $date ) {
                $candidates[] = (string) $existing_key;
            }
        }

        return \count( $candidates ) === 1 ? $candidates[0] : '';
    }

    /**
     * The date half of an occurrence key ('' when it carries none).
     *
     * @param string $key
     * @return string
     */
    private function key_date( $key ) {
        return (string) \strstr( (string) $key, '|', true );
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
     * The parent's derived STATUS moves with its span (audit MODEL-D32). A
     * container whose only date had passed carries status='past'; the author
     * adds a 2027 date and the span moves forward, but the status row used to
     * sit stale until the daily sweep ran — up to 24 hours in which every
     * reader called the parent finished and the admin "Past" quick filter
     * still counted it. Mirrors what the save paths do, through the same
     * writer, so manual mode is untouched: a hand-pinned status is the
     * author's.
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
        $this->module->persist_auto_status( $parent_id, $meta );
    }

    public function retire_all_children( $parent_id ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return;
        }
        foreach ( $this->existing_children_map( $parent_id ) as $ids ) {
            foreach ( $ids as $child_id ) {
                $this->retire_child( (int) $child_id );
            }
        }
    }

    /**
     * Write one registration_enabled value down onto every LIVE child of a
     * group parent — the engine half of the parent form's explicit
     * "apply to all dates" action (audit MODEL-D40).
     *
     * This is deliberately NOT a sync. `registration_enabled` is a
     * PER_OCCURRENCE key: reconcile() never copies it from the parent, so
     * closing the September date cannot close November and cannot be silently
     * undone by the next parent save. What was missing was the other half — a
     * way to say "this applies to the whole offering" ON PURPOSE — and this is
     * it: one explicit, admin-initiated write, never reached from reconcile().
     *
     * SOFT-CLOSED children are excluded (children() with $include_closed
     * false). registration_enabled=false is one quarter of the soft-closed
     * quartet soft_close() asserts; re-opening a retired date here would both
     * contradict that state and put a "Register" CTA on an occurrence the
     * parent no longer offers.
     *
     * @param int  $parent_id
     * @param bool $enabled
     * @return int Number of live children written (0 when $parent_id is not a
     *             group parent, so a plain single event is a safe no-op).
     */
    public function apply_registration_to_children( $parent_id, $enabled ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 || ! $this->is_group_parent( $parent_id ) ) {
            return 0;
        }

        $enabled  = (bool) $enabled;
        $children = $this->children( $parent_id, false );
        foreach ( $children as $child_id ) {
            \update_post_meta( (int) $child_id, $this->module->meta_key( 'registration_enabled' ), $enabled );
        }

        return \count( $children );
    }

    /**
     * Live (or all, incl. soft-closed) child post ids for a group parent.
     * PUBLISHED children only — never trashed, and never one an admin has
     * unpublished (draft/pending/private): those stay occurrences of the
     * group, reconcile() still syncs them, but they are not on offer.
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
        foreach ( $this->existing_children_map( $parent_id ) as $group ) {
            // One occurrence per key: the canonical child. Any extra is a
            // duplicate, and reconcile() — the write path — retires it.
            $child_id = (int) $group[0];
            // The map is now status-agnostic on purpose (MODEL-D9), but this
            // list is the OFFERED set: it feeds the "choose a date" picker,
            // the schema emitter and the archive exclusions. A child an admin
            // unpublished is still an occurrence of the group — it is simply
            // not on offer — so it is filtered out here rather than by the
            // query, which is what reconcile() needs to see it at all.
            if ( \get_post_status( $child_id ) !== 'publish' ) {
                continue;
            }
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
     * The parent event post id for a child (0 when not a child, and 0 when the
     * id it stores no longer names a live event).
     *
     * The plugin has no cascade: permanently deleting a group parent leaves its
     * seated, soft-closed children with `group_role`='child' and a `group_id`
     * pointing at a dead id (audit MODEL-D22). Five callers then read fields
     * off that id — Series::representative_id() renders a session row from it,
     * the DEKA theme reads its meta, title and permalink — and if WordPress has
     * reissued the id to an unrelated post, the child silently inherits that
     * post's fields.
     *
     * So the pointer is validated here once, for every caller, rather than
     * five times (and only one of the five did it). Callers that LINK to the
     * parent keep their own `publish` check on top — a trashed parent is not a
     * parent at all now, but a private/draft one is still real and still must
     * not be linked (render_sibling_dates()).
     *
     * @param int $child_id
     * @return int
     */
    public function parent_of( $child_id ) {
        $child_id = (int) $child_id;
        if ( $child_id <= 0 || ! $this->is_group_child( $child_id ) ) {
            return 0;
        }
        $parent_id = (int) \get_post_meta( $child_id, $this->module->meta_key( 'group_id' ), true );
        if ( $parent_id <= 0 || \get_post_type( $parent_id ) !== Module::CPT ) {
            return 0; // Deleted, or an id reissued to something else.
        }
        if ( \get_post_status( $parent_id ) === 'trash' ) {
            return 0;
        }
        return $parent_id;
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
            $row = [
                'date'       => \date( 'Y-m-d', $ts ),
                'start_time' => $start_time,
                'end_time'   => $end_time,
                'label'      => $label,
                'capacity'   => $capacity,
            ];
            // Keyed the same way offering rows are (occurrence_key()); every
            // generated row shares one start time, so this is the date dedupe
            // it has always been — spelled in the one vocabulary reconcile()
            // matches on.
            $key = $this->occurrence_key( $row );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $rows[]       = $row;
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
     * @param string $key       occurrence_key (== occurrence_key( $row ), i.e. "<date>|<start time>").
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
        $this->sync_shared_meta( $parent_id, $child_id );
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

        // Re-stamp the occurrence key of a child that was matched under a
        // different spelling of the same occurrence — a pre-MODEL-D8 date-only
        // key, or a row whose start time was just edited (rekeyed_match()). The
        // write happens HERE, on the parent-save path, and never while a public
        // request is only reading the map.
        $key    = $this->occurrence_key( $row );
        $mk_key = $this->module->meta_key( 'occurrence_key' );
        if ( (string) \get_post_meta( $child_id, $mk_key, true ) !== $key ) {
            \update_post_meta( $child_id, $mk_key, $key );
        }

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

        $this->sync_shared_meta( $parent_id, $child_id );
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
     * an already-materialized child instead of silently no-op'ing.
     *
     * The row's `label` is one of those fields: it is stored on the child as
     * its own `label` meta (audit MODEL-D10/MODEL-D27/RENDER-D22). It used to
     * live ONLY inside the child's post_title, which the renderer then
     * string-sliced back out against the parent's title prefix — so renaming
     * the parent (a quick-edit never reaches reconcile()) made every authored
     * label vanish from "Choose a date". The title still carries the label for
     * display (child_title()), but display is no longer the storage. An empty
     * row label writes an empty meta on purpose: clearing a label must clear
     * it, and the renderer falls back to the formatted date.
     *
     * Never touches the child's date identity (occurrence_key/start_date/
     * end_date), status, or seats/roster.
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
        \update_post_meta( $child_id, $mk( 'label' ), (string) ( $row['label'] ?? '' ) );

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
     * The full meta keys (WITH the `_anchor_event_` prefix) an occurrence
     * child inherits from its parent: the shared schema facts
     * (INHERITED_KEYS) plus the event meta that lives outside
     * get_meta_defaults() and therefore never used to be copied at all —
     * the registration questions and every per-event email override.
     *
     * The email keys are enumerated from Module::EMAIL_TEMPLATE_TYPES rather
     * than written out, so the list cannot drift from the save handlers.
     *
     * @param int $parent_id
     * @param int $child_id
     * @return string[] Unique, prefixed meta keys.
     */
    private function inherited_meta_keys( $parent_id, $child_id ) {
        $keys = [];
        foreach ( self::INHERITED_KEYS as $key ) {
            $keys[] = $this->module->meta_key( $key );
        }

        // Registration questions: a child asks the same questions as its
        // parent, or a booking on one date collects nothing.
        $keys[] = Module::QUESTIONS_META;

        // Per-event email overrides. Without these a child sent the site-wide
        // confirmation while the parent's own wording sat one post away.
        foreach ( Module::EMAIL_TEMPLATE_TYPES as $type ) {
            foreach ( self::EMAIL_OVERRIDE_PER_TYPE as $suffix ) {
                $keys[] = $this->module->meta_key( 'email_' . $suffix . '_' . $type );
            }
        }
        foreach ( self::EMAIL_SENDER_KEYS as $key ) {
            $keys[] = $this->module->meta_key( $key );
        }

        /**
         * Filter the meta keys an occurrence child inherits from its group
         * parent. Keys are full meta keys (including the `_anchor_event_`
         * prefix). A key the parent has no row for is not written — and a
         * child's stale row for it is removed — so adding a key here is safe
         * for a parent that never used it.
         *
         * SINGLE-VALUE KEYS ONLY: each key is read with
         * get_post_meta( ..., true ) and written as one row, and a key the
         * parent lacks is deleted from the child wholesale. A genuinely
         * multi-row key — the DEKA theme's `_deka_event_speaker_ids`, which
         * stores one row per speaker, is the live example — would collapse to
         * its first row on the child and lose the rest. Do not filter one in.
         *
         * @param string[] $keys      Prefixed meta keys.
         * @param int      $parent_id Group parent post id.
         * @param int      $child_id  Occurrence child post id.
         */
        $keys = (array) \apply_filters( 'anchor_events_inherited_meta_keys', $keys, (int) $parent_id, (int) $child_id );

        return \array_values( \array_unique( \array_filter( \array_map( 'strval', $keys ) ) ) );
    }

    /**
     * Copy the inherited meta (see inherited_meta_keys()) from parent to
     * child.
     *
     * Reads the parent's RAW rows, not get_meta(): get_meta() fills every
     * missing key with the schema default, and this method then wrote that
     * default down as a real row on the child. That is how the seven
     * production children became the only posts on the site carrying
     * `_anchor_event_registration_type = internal`, and how eight events
     * acquired a `_anchor_event_timezone = "UTC-6"` string nobody authored
     * (audit MODEL-D7 / MODEL-D37). On a child, "never authored" and "equal
     * to the shipped default" then became indistinguishable, and a later
     * change to a default could never reach them.
     *
     * A key the parent has no row for is not merely skipped: an existing
     * child row for it is DELETED. Inheritance has to be symmetric or
     * clearing a venue on the parent would leave the old venue showing on
     * every occurrence for ever, with no way to remove it from the parent
     * screen. The child then falls back to the same default its parent reads,
     * which is what "inherited" means.
     *
     * @param int $parent_id
     * @param int $child_id
     */
    private function sync_shared_meta( $parent_id, $child_id ) {
        foreach ( $this->inherited_meta_keys( $parent_id, $child_id ) as $key ) {
            if ( \metadata_exists( 'post', (int) $parent_id, $key ) ) {
                // wp_slash() because update_post_meta() unslashes what it is
                // given: handing it a raw DB value would eat a backslash out
                // of every venue name and every email subject on every sync.
                \update_post_meta( $child_id, $key, \wp_slash( \get_post_meta( (int) $parent_id, $key, true ) ) );
            } elseif ( \metadata_exists( 'post', (int) $child_id, $key ) ) {
                \delete_post_meta( $child_id, $key );
            }
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
        // The trash is what demotes a managed product, so a child that does not
        // own the product it points at must let go of it first.
        $this->disown_foreign_product( $child_id );
        \wp_trash_post( $child_id );
    }

    /**
     * Drop a child's managed-product pointer when the product it names belongs
     * to a DIFFERENT event.
     *
     * A duplicate produced by a duplicate-post plugin copies
     * `_anchor_event_managed_product` along with everything else, so two child
     * posts point at one product — but the product's own back-pointer
     * (Product_Sync::PRODUCT_EVENT_META) still names exactly one owner. Trashing
     * the copy fires Product_Sync::on_event_trashed_or_deleted(), which reads
     * only the forward pointer and would set the CANONICAL child's product to
     * draft: the live date silently stops being purchasable. Clearing the
     * borrowed pointer first makes that trash a no-op for the product, and also
     * stops a preserved (soft-closed) duplicate from later adopting — and
     * re-owning — a product that is not its own.
     *
     * A child that genuinely owns its product keeps the pointer, so trashing a
     * real occurrence still drafts its product exactly as before.
     *
     * @param int $child_id
     */
    private function disown_foreign_product( $child_id ) {
        $child_id = (int) $child_id;
        if ( ! $this->module->product_sync || ! \function_exists( 'wc_get_product' ) ) {
            return;
        }
        // The RAW pointer on purpose: managed_product_id() now REJECTS a
        // borrowed pointer (WOO-D23), which is the very row this method exists
        // to delete. Reading the validated accessor here would make the read
        // safe and leave the stale meta on the post forever.
        $product_id = (int) $this->module->product_sync->stored_product_id( $child_id );
        if ( $product_id <= 0 ) {
            return;
        }
        $product = \wc_get_product( $product_id );
        if ( ! $product ) {
            return; // Dangling pointer: there is no product to demote or steal.
        }
        if ( (int) $product->get_meta( Product_Sync::PRODUCT_EVENT_META ) === $child_id ) {
            return; // This child owns it.
        }
        \delete_post_meta( $child_id, Product_Sync::EVENT_PRODUCT_META );
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
     * force registration_enabled: that key is PER-OCCURRENCE
     * (PER_OCCURRENCE_KEYS), so nothing — not this method, not
     * sync_child_from_parent() — re-applies it from the parent, and a revived
     * date therefore comes back with registration still OFF (soft_close()
     * turned it off) until it is opened deliberately: on the child itself, or
     * group-wide via the parent form's explicit "apply to all dates" action
     * (apply_registration_to_children(), audit MODEL-D40). An earlier version
     * of this docblock claimed registration_enabled was a SHARED field
     * re-synced by sync_child_from_parent() — it never was after the key
     * moved into PER_OCCURRENCE_KEYS. No-op if the child isn't currently
     * closed.
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
     * occurrence_key => child post ids for ALL of a parent's children, found
     * by GROUP IDENTITY (group_role + group_id meta) rather than by post
     * status (audit MODEL-D9).
     *
     * The query used to ask for post_status=publish, so a child an admin had
     * set to Draft/Pending/Private — to hide one date without deleting it —
     * was invisible to reconcile(): the date was still desired, the map did
     * not contain it, and create_child() inserted a SECOND published post for
     * the same date, with its own product and its own seat count, while the
     * hidden original was never retired and never appeared in children().
     * `any` is every status except trash and auto-draft, so the trash is still
     * opted into explicitly by the one caller that needs it (reconcile, which
     * revives a wanted date's trashed occurrence).
     *
     * The value is a LIST, not a single id (audit MODEL-D39): `$map[$key] =
     * $id` silently kept the last row when two children carried the same
     * occurrence_key (what any duplicate-post plugin produces), and the loser
     * became an invisible orphan — never matched, never retired, never listed,
     * but still published with its own product and seats. Every list is sorted
     * canonical-first (see child_rank()); reconcile() keeps the head and
     * retires the tail, and the read-only callers use the head.
     *
     * @param int  $parent_id
     * @param bool $include_trashed Also return trashed children.
     * @return array<string,int[]> occurrence_key => child ids, canonical first.
     */
    private function existing_children_map( $parent_id, $include_trashed = false ) {
        // 'any' means every status that isn't excluded from search — i.e.
        // everything but trash and auto-draft — and WP_Query drops an exclusion
        // that is ALSO listed explicitly, so [ 'any', 'trash' ] is "any + trash".
        $ids = \get_posts( [
            'post_type'      => Module::CPT,
            'post_status'    => $include_trashed ? [ 'any', 'trash' ] : 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => $this->module->meta_key( 'group_role' ), 'value' => 'child', 'compare' => '=' ],
                [ 'key' => $this->module->meta_key( 'group_id' ), 'value' => (int) $parent_id, 'compare' => '=', 'type' => 'NUMERIC' ],
            ],
        ] );

        // fields=>ids skips WP_Query's own cache priming, and every consumer of
        // this map immediately asks each child for its occurrence_key and its
        // post_status. One prime here is the difference between two queries and
        // two per child.
        if ( ! empty( $ids ) ) {
            \_prime_post_caches( $ids, false, true );
        }

        $map = [];
        foreach ( $ids as $id ) {
            $id  = (int) $id;
            // Pre-MODEL-D8 children were stamped with the start date alone;
            // stored_occurrence_key() reads them as "<date>|<the child's own
            // start time>" so a legacy occurrence is MATCHED rather than
            // duplicated. Normalized on read, never migrated here: this map is
            // also built on public render paths (children()/siblings()), and a
            // read must not write. The upgraded key is stamped on the
            // parent-save path instead, by sync_child_from_parent().
            $key = $this->stored_occurrence_key( $id );
            if ( $key === '' ) {
                continue;
            }
            $map[ $key ][] = $id;
        }

        foreach ( $map as $key => $group ) {
            if ( \count( $group ) < 2 ) {
                continue;
            }
            \usort( $group, function ( $a, $b ) {
                return [ $this->child_rank( $a ), $a ] <=> [ $this->child_rank( $b ), $b ];
            } );
            $map[ $key ] = $group;
        }

        return $map;
    }

    /**
     * Ordering rank for picking the canonical child of an occurrence_key when
     * more than one exists: the live published occurrence first (it is the one
     * the public is already looking at), then a published-but-soft-closed one,
     * then an unpublished one, then the trash. Ties break on the lowest id, so
     * the original beats a later copy.
     *
     * @param int $child_id
     * @return int
     */
    private function child_rank( $child_id ) {
        $status = \get_post_status( $child_id );
        if ( $status === 'trash' ) {
            return 3;
        }
        if ( $status !== 'publish' ) {
            return 2;
        }
        return $this->is_closed( $child_id ) ? 1 : 0;
    }

    /**
     * Reduce an existing_children_map() to one canonical child per
     * occurrence_key, retiring and reporting every extra (audit MODEL-D39).
     *
     * A duplicate is retired with the SAME retire_child() the no-longer-wanted
     * branch of reconcile() uses, so a duplicate holding a roster is
     * soft-closed rather than trashed. One already retired (trashed, or
     * soft-closed) is left exactly as it is — otherwise a preserved seated
     * duplicate would be re-reported on every single parent save.
     *
     * @param int                 $parent_id
     * @param array<string,int[]> $map
     * @return array<string,int> occurrence_key => canonical child id.
     */
    private function collapse_duplicate_children( $parent_id, array $map ) {
        $canonical = [];
        foreach ( $map as $key => $ids ) {
            $canonical[ $key ] = (int) \array_shift( $ids );
            foreach ( $ids as $duplicate_id ) {
                $duplicate_id = (int) $duplicate_id;
                if ( \get_post_status( $duplicate_id ) === 'trash' || $this->is_closed( $duplicate_id ) ) {
                    continue; // Already retired — nothing to do, nothing to report.
                }
                // Both retirement outcomes: a soft-closed duplicate must not
                // keep a borrowed product pointer either (it would let a later
                // sync re-own the canonical child's product).
                $this->disown_foreign_product( $duplicate_id );
                $this->retire_child( $duplicate_id );
                Events_Log::error( 'duplicate_occurrence', [
                    'parent_id'      => (int) $parent_id,
                    'occurrence_key' => (string) $key,
                    'kept'           => $canonical[ $key ],
                    'retired'        => $duplicate_id,
                    'result'         => \get_post_status( $duplicate_id ) === 'trash' ? 'trashed' : 'soft_closed',
                ] );
            }
        }
        return $canonical;
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
     * no parseable date are dropped; a duplicate OCCURRENCE KEY (same date AND
     * same start time — two sessions on one day are two occurrences, audit
     * MODEL-D8) keeps the FIRST row.
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
            if ( $date === '' ) {
                continue;
            }
            // Deduped by the OCCURRENCE key, not by the date: two sessions on
            // one day are two occurrences (audit MODEL-D8). A genuine duplicate
            // — same date AND same start time — still keeps the first row, but
            // the save path now rejects one before it can ever be stored (see
            // Module::sanitize_offering_dates_rows()), so this is the backstop
            // for rows written before that guard existed.
            $key = $this->occurrence_key( $row );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

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
