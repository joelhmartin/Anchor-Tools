<?php
/**
 * Who holds access to an event, and why (virtual-events spec §4).
 *
 * One responsibility. Every event that anybody is ever granted access to mints
 * a capability-less WordPress role `anchor_event_{id}`; holding that role is
 * the fact the Anchor Private File Manager, the courses module and anything
 * else that understands roles can gate on, with no knowledge of this plugin.
 *
 * Two things must stay true:
 *   - Roles are minted LAZILY and deleted NEVER (except by an explicit
 *     operator action). An event nobody registers for creates no role; a
 *     finished, trashed or deleted event keeps its role so the owner can go on
 *     granting after the fact.
 *   - A role is a membership tag, not a permission. It carries no capabilities
 *     at all, so adding one to a user can never widen what they may do.
 *
 * `_anchor_event_grants` (user meta) records WHY somebody holds a role, so a
 * seat cancellation can never strip a manual grant.
 *
 * Seat-lifecycle wiring (auto-grant on a confirmed seat, auto-revoke on
 * cancellation) is Task 7's job — this class exposes grant()/revoke() as the
 * primitives it calls, but does not hook the seat actions itself.
 *
 * @package AnchorTools\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Entitlements {

    /** User meta: [ event_id => { source: seat|manual, at: int, by: int } ]. */
    const GRANTS_META = '_anchor_event_grants';

    /** Seat meta: the account this seat entitles (0 = unresolved). */
    const SEAT_USER_META = '_anchor_event_user_id';

    const SOURCE_SEAT   = 'seat';
    const SOURCE_MANUAL = 'manual';

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;

        // The role's display name follows the event title. save_post (not
        // save_post_event alone) because a quick-edit title change does not run
        // the metabox save; wp_update_post fires this for every path.
        \add_action( 'save_post_' . Module::CPT, [ $this, 'rename_role' ], 10, 2 );

        // Seat lifecycle (spec §4.2): grant on birth-confirmed or promotion to
        // confirmed, consider revoking on leaving confirmed. Deferred out of
        // Task 6 because these handlers did not exist yet.
        \add_action( 'anchor_events_seat_created', [ $this, 'on_seat_created' ], 10, 2 );
        \add_action( 'anchor_events_seat_status_changed', [ $this, 'on_seat_status_changed' ], 10, 4 );
    }

    /* ---------------------------------------------------------------------
     * The master switch (spec §2 row 8a, §3.1, §4 preamble)
     * ------------------------------------------------------------------- */

    /**
     * Does the event-role machinery apply to this event?
     *
     * TRUE for nearly every plugin-registered event, because the switch
     * defaults on (spec §3.1, owner decision 2026-09-23): confirmed attendees
     * get an account and the `anchor_event_{id}` role whether they attend in
     * person or over a stream, since event materials are handed out by role.
     *
     * FALSE means the operator switched this event off, or it was never in
     * the feature (external registration, a group parent). Then nothing in §4
     * runs: no account is created, no role is minted, no email gains a link,
     * no Access column appears. Every entry point that touches access should
     * ask this first and return its pre-spec answer.
     *
     * This is NOT "has a room". The room needs a stream as well, and asks
     * Module::room_url(), which is this AND a resolvable embed.
     *
     * Two conditions, and they mean different things:
     *   - stream_capable(): "could this event ever hold a room?" Registration
     *     mode wc|free and not a group parent (spec §2 decision 1). A fact
     *     about the event's shape, unchanged by this switch.
     *   - access_role_enabled: "should attendees of it get the role?" On by
     *     default; un-tickable by the author; forced back on by saving a
     *     stream, a virtual/hybrid session or a virtual tier.
     *
     * @param int $event_id
     * @return bool
     */
    public function enabled( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 || ! $this->module->stream_capable( $event_id ) ) {
            return false;
        }
        $meta = $this->module->get_meta( $event_id );
        return ! empty( $meta['access_role_enabled'] );
    }

    /* ---------------------------------------------------------------------
     * Roles
     * ------------------------------------------------------------------- */

    /** @return string The role slug for an event — never creates anything. */
    public function role_slug( $event_id ) {
        return 'anchor_event_' . (int) $event_id;
    }

    /** @return string The role's display name. */
    public function role_name( $event_id ) {
        /* translators: %s: event title. */
        return \sprintf( \__( 'Event: %s', 'anchor-schema' ), \get_the_title( (int) $event_id ) );
    }

    /**
     * The event's role, minting it on first use.
     *
     * @param int  $event_id
     * @param bool $create   false = "does it exist yet?", returns '' if not.
     * @return string Slug, or '' when absent and $create is false.
     */
    public function role_for( $event_id, $create = true ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) {
            return '';
        }
        $slug = $this->role_slug( $event_id );
        if ( \get_role( $slug ) ) {
            return $slug;
        }
        if ( ! $create ) {
            return '';
        }
        // No capabilities: membership, not permission.
        \add_role( $slug, $this->role_name( $event_id ), [] );
        return \get_role( $slug ) ? $slug : '';
    }

    /**
     * Keep the role's display name in step with the event title.
     *
     * Only ever RENAMES an existing role — it never mints one, so saving an
     * event nobody has registered for still creates nothing.
     *
     * @param int       $post_id
     * @param \WP_Post  $post
     */
    public function rename_role( $post_id, $post = null ) {
        $post_id = (int) $post_id;
        if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        $slug = $this->role_for( $post_id, false );
        if ( $slug === '' ) {
            return;
        }
        $roles = \wp_roles();
        $name  = $this->role_name( $post_id );
        if ( isset( $roles->roles[ $slug ] ) && $roles->roles[ $slug ]['name'] !== $name ) {
            $roles->roles[ $slug ]['name'] = $name;
            $roles->role_names[ $slug ]    = $name;
            \update_option( $roles->role_key, $roles->roles, false );
        }
    }

    /**
     * How many users hold the event's role.
     *
     * @param int $event_id
     * @return int
     */
    public function role_members( $event_id ) {
        $slug = $this->role_for( (int) $event_id, false );
        if ( $slug === '' ) {
            return 0;
        }
        $q = new \WP_User_Query( [ 'role' => $slug, 'fields' => 'ID', 'number' => 0 ] );
        return \count( (array) $q->get_results() );
    }

    /**
     * Operator-only: remove the role and strip it from every holder.
     *
     * The ONE path that deletes a role. Nothing automatic ever calls it —
     * trashing or deleting an event deliberately leaves the role behind.
     *
     * @param int $event_id
     * @return int Holders the role was stripped from.
     */
    public function delete_role( $event_id ) {
        $event_id = (int) $event_id;
        $slug     = $this->role_for( $event_id, false );
        if ( $slug === '' ) {
            return 0;
        }
        $q       = new \WP_User_Query( [ 'role' => $slug, 'fields' => 'ID', 'number' => 0 ] );
        $holders = \array_map( 'intval', (array) $q->get_results() );
        foreach ( $holders as $user_id ) {
            $this->revoke( $event_id, $user_id, 'delete_role' );
        }
        \remove_role( $slug );
        return \count( $holders );
    }

    /* ---------------------------------------------------------------------
     * Grants
     * ------------------------------------------------------------------- */

    /** @return bool Whether the user currently holds the event's role. */
    public function holds_role( $event_id, $user_id ) {
        $slug = $this->role_for( (int) $event_id, false );
        if ( $slug === '' || (int) $user_id <= 0 ) {
            return false;
        }
        $user = \get_userdata( (int) $user_id );
        return $user instanceof \WP_User && \in_array( $slug, (array) $user->roles, true );
    }

    /** @return array<int,array{source:string,at:int,by:int}> */
    public function grants_for_user( $user_id ) {
        $stored = \get_user_meta( (int) $user_id, self::GRANTS_META, true );
        return \is_array( $stored ) ? $stored : [];
    }

    /** @return array{source:string,at:int,by:int}|array Empty when no record. */
    public function grant_record( $event_id, $user_id ) {
        $grants = $this->grants_for_user( $user_id );
        return $grants[ (int) $event_id ] ?? [];
    }

    /**
     * Give a user the event's role and record why.
     *
     * add_role() is additive — a customer/subscriber keeps everything they had.
     *
     * Idempotent: re-granting what's already held and already recorded the
     * same way is a no-op — no meta rewrite (the original `at` survives), no
     * action, `false` returned — so a caller (e.g. a seat reconcile loop) can
     * call grant() defensively without spamming listeners or resetting a
     * grant's timestamp every time it runs. A manual grant OUTRANKS a seat
     * grant and is never downgraded by one (that's what makes "a cancellation
     * cannot strip a comp" true) — attempting the downgrade is itself treated
     * as a no-op. The one legitimate re-write is the upgrade seat -> manual,
     * which updates the record and fires the action.
     *
     * @param int    $event_id
     * @param int    $user_id
     * @param string $source   seat|manual.
     * @return bool True when a role and/or the grant record actually changed.
     */
    public function grant( $event_id, $user_id, $source = self::SOURCE_SEAT ) {
        $event_id = (int) $event_id;
        $user_id  = (int) $user_id;
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return false;
        }
        $user = \get_userdata( $user_id );
        if ( ! $user instanceof \WP_User ) {
            return false;
        }
        $slug = $this->role_for( $event_id );
        if ( $slug === '' ) {
            Events_Log::error( 'access_role_mint_failed', [ 'event' => $event_id ] );
            return false;
        }

        $had_role = \in_array( $slug, (array) $user->roles, true );

        $grants          = $this->grants_for_user( $user_id );
        $existing        = $grants[ $event_id ] ?? null;
        $existing_source = $existing['source'] ?? '';
        $incoming_source = ( $source === self::SOURCE_MANUAL ) ? self::SOURCE_MANUAL : self::SOURCE_SEAT;
        $downgrade       = ( $existing_source === self::SOURCE_MANUAL && $incoming_source !== self::SOURCE_MANUAL );
        $unchanged       = $had_role && $existing !== null && ( $existing_source === $incoming_source || $downgrade );

        if ( $unchanged ) {
            return false;
        }

        if ( ! $had_role ) {
            $user->add_role( $slug );
        }

        $grants[ $event_id ] = [
            'source' => $incoming_source,
            'at'     => \time(),
            'by'     => (int) \get_current_user_id(),
        ];
        \update_user_meta( $user_id, self::GRANTS_META, $grants );

        /**
         * A user just gained access to an event.
         *
         * @param int    $event_id
         * @param int    $user_id
         * @param string $source   seat|manual.
         */
        \do_action( 'anchor_events_access_granted', $event_id, $user_id, (string) $source );
        return true;
    }

    /**
     * Take the role away and clear the grant record.
     *
     * Fires the action only when something actually changed — a redundant
     * revoke() (role already gone, no grant record) is a silent no-op, so a
     * caller can revoke defensively without spamming listeners.
     *
     * @param int    $event_id
     * @param int    $user_id
     * @param string $source   Reported to the action; not a permission check.
     * @return bool True when the role and/or grant record was removed.
     */
    public function revoke( $event_id, $user_id, $source = self::SOURCE_SEAT ) {
        $event_id = (int) $event_id;
        $user_id  = (int) $user_id;
        $user     = \get_userdata( $user_id );
        if ( $event_id <= 0 || ! $user instanceof \WP_User ) {
            return false;
        }

        $slug    = $this->role_slug( $event_id );
        $changed = false;

        if ( \in_array( $slug, (array) $user->roles, true ) ) {
            $user->remove_role( $slug );
            $changed = true;
        }

        $grants = $this->grants_for_user( $user_id );
        if ( isset( $grants[ $event_id ] ) ) {
            unset( $grants[ $event_id ] );
            if ( empty( $grants ) ) {
                \delete_user_meta( $user_id, self::GRANTS_META );
            } else {
                \update_user_meta( $user_id, self::GRANTS_META, $grants );
            }
            $changed = true;
        }

        if ( ! $changed ) {
            return false;
        }

        /**
         * A user just lost access to an event.
         *
         * @param int    $event_id
         * @param int    $user_id
         * @param string $source
         */
        \do_action( 'anchor_events_access_revoked', $event_id, $user_id, (string) $source );
        return true;
    }

    /* ---------------------------------------------------------------------
     * Seat lifecycle (spec §4.2)
     * ------------------------------------------------------------------- */

    /**
     * A seat was created. Grant when it is born confirmed.
     *
     * `pending` grants nothing: a WooCommerce on-hold order and a waitlist seat
     * are both "maybe". Promotion to confirmed comes through
     * on_seat_status_changed() and grants normally.
     *
     * @param int    $seat_id
     * @param string $status
     */
    public function on_seat_created( $seat_id, $status ) {
        if ( (string) $status !== Registrations::STATUS_CONFIRMED ) {
            return;
        }
        // grant_for_seat() checks enabled() itself; this hook does no work of
        // its own beyond the status test, so there is nothing else to guard.
        $this->grant_for_seat( (int) $seat_id );
    }

    /**
     * A seat moved. Grant on entering confirmed; consider revoking on leaving it.
     *
     * @param int    $seat_id
     * @param string $from
     * @param string $to
     * @param string $actor
     */
    public function on_seat_status_changed( $seat_id, $from, $to, $actor = '' ) {
        $seat_id = (int) $seat_id;
        if ( (string) $to === Registrations::STATUS_CONFIRMED ) {
            $this->grant_for_seat( $seat_id );
            return;
        }
        if ( (string) $to === Registrations::STATUS_PENDING ) {
            return; // Reserved, not entitled. Nothing granted, nothing taken.
        }
        $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );
        // Inert event (spec §4 preamble): nothing was ever granted, so there is
        // nothing to revoke and no reason to run the seat query below.
        if ( ! $this->enabled( $event_id ) ) {
            return;
        }
        $user_id = (int) \get_post_meta( $seat_id, self::SEAT_USER_META, true );
        if ( $user_id <= 0 ) {
            $user    = \get_user_by( 'email', (string) \get_post_meta( $seat_id, '_anchor_event_email', true ) );
            $user_id = $user ? (int) $user->ID : 0;
        }
        $this->maybe_revoke_seat_grant( $event_id, $user_id );
    }

    /**
     * Revoke a SEAT grant only when nothing else entitles the user: no other
     * confirmed seat on this event, and no manual grant on record.
     *
     * @param int $event_id
     * @param int $user_id
     */
    public function maybe_revoke_seat_grant( $event_id, $user_id ) {
        $event_id = (int) $event_id;
        $user_id  = (int) $user_id;
        if ( $event_id <= 0 || $user_id <= 0 || ! $this->enabled( $event_id ) ) {
            return;
        }
        if ( ( $this->grant_record( $event_id, $user_id )['source'] ?? '' ) === self::SOURCE_MANUAL ) {
            return; // A comp is not undone by a refund.
        }
        if ( $this->has_confirmed_seat( $event_id, $user_id ) ) {
            return; // Another seat still entitles them.
        }
        $this->revoke( $event_id, $user_id, self::SOURCE_SEAT );
    }

    /**
     * Whether the user holds at least one CONFIRMED seat on the event.
     *
     * Deliberately narrower than Registrations::user_has_active_seat(), which
     * also counts `pending` — a reserved seat is not an entitlement.
     *
     * @param int $event_id
     * @param int $user_id
     * @return bool
     */
    public function has_confirmed_seat( $event_id, $user_id ) {
        $user = \get_userdata( (int) $user_id );
        if ( ! $user instanceof \WP_User ) {
            return false;
        }
        $identity = [
            'relation' => 'OR',
            [ 'key' => self::SEAT_USER_META, 'value' => (int) $user_id, 'compare' => '=', 'type' => 'NUMERIC' ],
            [ 'key' => '_anchor_event_customer_id', 'value' => (int) $user_id, 'compare' => '=', 'type' => 'NUMERIC' ],
            [ 'key' => '_anchor_event_email', 'value' => (string) $user->user_email, 'compare' => '=' ],
        ];
        $q = new \WP_Query( [
            'post_type'      => Module::REG_CPT,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_anchor_event_id', 'value' => (int) $event_id, 'compare' => '=', 'type' => 'NUMERIC' ],
                [ 'key' => '_anchor_event_reg_status', 'value' => Registrations::STATUS_CONFIRMED, 'compare' => '=' ],
                $identity,
            ],
        ] );
        return ! empty( $q->posts );
    }

    /**
     * Resolve the seat's user and grant. Task 8 replaces the resolution with
     * ensure_user(); until then an existing account by email is enough.
     *
     * @param int $seat_id
     * @return bool True when the grant actually changed something.
     */
    private function grant_for_seat( $seat_id ) {
        $seat_id  = (int) $seat_id;
        $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );
        // The master switch (spec §4 preamble). An inert event grants nothing,
        // so it never mints a role — which is what makes "no anchor_event_{id}
        // in wp_roles()" true for a plain event that ran with 200 attendees.
        if ( $event_id <= 0 || ! $this->enabled( $event_id ) ) {
            return false;
        }
        $user_id = (int) \get_post_meta( $seat_id, self::SEAT_USER_META, true );
        if ( $user_id <= 0 ) {
            $user    = \get_user_by( 'email', (string) \get_post_meta( $seat_id, '_anchor_event_email', true ) );
            $user_id = $user ? (int) $user->ID : 0;
        }
        if ( $user_id <= 0 ) {
            return false;
        }
        return $this->grant( $event_id, $user_id, self::SOURCE_SEAT );
    }
}
