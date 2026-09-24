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
            // Reserved, not entitled — nothing granted, nothing taken, so this
            // is checked before enabled() on purpose: there is no work here
            // for the master switch to gate.
            return;
        }
        $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );
        // Inert event (spec §4 preamble): nothing was ever granted, so there is
        // nothing to revoke and no reason to run the seat query below.
        if ( ! $this->enabled( $event_id ) ) {
            return;
        }
        $this->maybe_revoke_seat_grant( $event_id, $this->resolve_seat_user( $seat_id ) );
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
     * Resolve (and, per ensure_user(), create if needed) the seat's account,
     * then grant it. ensure_user() owns the master-switch guard, so this
     * method does its own event lookup only to short-circuit before touching
     * the seat at all when it isn't even a real event.
     *
     * @param int $seat_id
     */
    private function grant_for_seat( $seat_id ) {
        $seat_id  = (int) $seat_id;
        $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );
        if ( $event_id <= 0 ) {
            return;
        }
        $user_id = $this->ensure_user( [ 'id' => $seat_id ] );
        if ( $user_id > 0 ) {
            $this->grant( $event_id, $user_id, self::SOURCE_SEAT );
        }
    }

    /**
     * The revoke path's one place a seat resolves to an account.
     *
     * Deliberately NOT ensure_user(): a cancellation must never create an
     * account for someone who never had one, so this stays read-only —
     * SEAT_USER_META first, then an existing account by email. grant_for_seat()
     * (the grant path) uses ensure_user() instead, which may create one.
     *
     * @param int $seat_id
     * @return int User id, or 0 when unresolved.
     */
    private function resolve_seat_user( $seat_id ) {
        $seat_id = (int) $seat_id;
        $user_id = (int) \get_post_meta( $seat_id, self::SEAT_USER_META, true );
        if ( $user_id > 0 ) {
            return $user_id;
        }
        $user = \get_user_by( 'email', (string) \get_post_meta( $seat_id, '_anchor_event_email', true ) );
        return $user ? (int) $user->ID : 0;
    }

    /* ---------------------------------------------------------------------
     * Accounts (spec §4.3)
     * ------------------------------------------------------------------- */

    /**
     * The account a seat entitles, creating one if there isn't one.
     *
     * FIRST, the master switch (spec §4 preamble): an event whose
     * `access_role_enabled` is false returns 0 and does nothing — no lookup,
     * no seat meta write, and above all no account. The check lives HERE, not
     * at the five call sites (grant_for_seat(), Roster::handle_add(), the
     * WooCommerce attendee capture, the {room_link} token, the roster's Grant
     * action), because a guard at the call sites is five chances to miss one
     * and the sixth caller is written by somebody who never read the spec.
     * It is deliberately ahead of branch 1: an inert event does not even
     * report an account it happens to have resolved earlier.
     *
     * Then four branches, in order:
     *   1. `_anchor_event_user_id` already set and the user still exists.
     *   2. An order seat with `customer_id > 0`.
     *   3. An existing account with the seat's email.
     *   4. Create one — wc_create_new_customer() when WooCommerce is active, so
     *      My Account works, else wp_insert_user() with the site's default role.
     *
     * NO WordPress (or WooCommerce) new-account email is ever sent: our own
     * confirmation carries the one-click sign-in link (§6.2), and a second
     * "here is your new password" mail from a course registration is noise the
     * attendee did not ask for.
     *
     * Always writes the resolution back to the seat so the next caller takes
     * branch 1.
     *
     * @param array $seat Registrations::get_seat() DTO, or [ 'id' => int ].
     * @return int User id, or 0 (no email on the seat, or the site opted out).
     */
    public function ensure_user( array $seat ) {
        $seat_id = (int) ( $seat['id'] ?? 0 );
        if ( $seat_id <= 0 ) {
            return 0;
        }
        $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );

        // 0 — the master switch. Nothing below this line runs for a plain event.
        if ( ! $this->enabled( $event_id ) ) {
            return 0;
        }

        // 1 — already resolved.
        $stored = (int) \get_post_meta( $seat_id, self::SEAT_USER_META, true );
        if ( $stored > 0 && \get_userdata( $stored ) ) {
            return $stored;
        }

        // 2 — the order's customer.
        $customer_id = (int) ( $seat['customer_id'] ?? \get_post_meta( $seat_id, '_anchor_event_customer_id', true ) );
        if ( $customer_id > 0 && \get_userdata( $customer_id ) ) {
            return $this->remember_seat_user( $seat_id, $customer_id );
        }

        $email = \sanitize_email( (string) ( $seat['email'] ?? \get_post_meta( $seat_id, '_anchor_event_email', true ) ) );
        if ( $email === '' ) {
            return 0;
        }

        // 3 — an existing account.
        $existing = \get_user_by( 'email', $email );
        if ( $existing instanceof \WP_User ) {
            return $this->remember_seat_user( $seat_id, (int) $existing->ID );
        }

        /**
         * Whether this site creates accounts for registrants who have none.
         *
         * Returning false means guests get no room access; the confirmation
         * email says so rather than pointing at a room they cannot enter.
         *
         * @param bool   $create
         * @param int    $event_id
         * @param string $email
         */
        if ( ! \apply_filters( 'anchor_events_create_account', true, $event_id, $email ) ) {
            return 0;
        }

        // 4 — create.
        $name     = \sanitize_text_field( (string) ( $seat['name'] ?? \get_post_meta( $seat_id, '_anchor_event_name', true ) ) );
        $username = $this->unique_username( $email );
        $password = \wp_generate_password( 24, true, true );

        // Suppress WooCommerce's "New account" email for the duration of the
        // create — wc_create_new_customer() fires woocommerce_created_customer,
        // which WC_Emails turns into a mail. wp_insert_user() sends nothing of
        // its own, so the plain branch needs no suppression.
        \add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
        try {
            if ( \function_exists( 'wc_create_new_customer' ) ) {
                $user_id = \wc_create_new_customer( $email, $username, $password, [ 'display_name' => $name ] );
            } else {
                $user_id = \wp_insert_user( [
                    'user_login'   => $username,
                    'user_email'   => $email,
                    'user_pass'    => $password,
                    'display_name' => $name !== '' ? $name : $username,
                    'role'         => (string) \get_option( 'default_role', 'subscriber' ),
                ] );
            }
        } finally {
            \remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
        }

        if ( \is_wp_error( $user_id ) || ! $user_id ) {
            Events_Log::error( 'access_user_create_failed', [
                'event' => $event_id,
                'seat'  => $seat_id,
                'to'    => \substr( \md5( $email ), 0, 8 ), // redacted identity (ERROR_IDENTITY_KEYS).
            ] );
            return 0;
        }

        if ( $name !== '' ) {
            \wp_update_user( [ 'ID' => (int) $user_id, 'display_name' => $name ] );
        }
        return $this->remember_seat_user( $seat_id, (int) $user_id );
    }

    /** Write the resolution onto the seat and return it. */
    private function remember_seat_user( $seat_id, $user_id ) {
        \update_post_meta( (int) $seat_id, self::SEAT_USER_META, (int) $user_id );
        return (int) $user_id;
    }

    /** A login that is not taken, derived from the email local part. */
    private function unique_username( $email ) {
        $base = \sanitize_user( \current( \explode( '@', $email ) ), true );
        if ( $base === '' ) {
            $base = 'attendee';
        }
        $candidate = $base;
        $n         = 1;
        while ( \username_exists( $candidate ) ) {
            $candidate = $base . '-' . ( ++$n );
        }
        return $candidate;
    }

    /* ---------------------------------------------------------------------
     * The one question (spec §4.5)
     * ------------------------------------------------------------------- */

    /**
     * May this person watch this session's stream?
     *
     * Resolution order, first hit wins (spec §4.5):
     *   1. Roster staff (current user only) — yes.
     *   2. Not logged in — no.
     *   3. `enabled()` is false (external registration, group parent, or the
     *      master switch off), session is in_person, or no embed resolves — no.
     *   4. A `manual` grant on record — yes.
     *   5. Holds the role AND a confirmed seat on a `virtual` tier — yes.
     *   6. Holds the role AND a confirmed `in_person` seat, with the event's
     *      "in-person registrants also get the stream" toggle on — yes.
     *   7. Otherwise no.
     *
     * @param int $event_id
     * @param int $session_index Index into Module::resolved_sessions().
     * @param int $user_id       0 = the current user.
     * @return bool
     */
    public function can_access_stream( $event_id, $session_index = 0, $user_id = 0 ) {
        $event_id      = (int) $event_id;
        $session_index = (int) $session_index;
        $for_current   = ( (int) $user_id === 0 );
        $user_id       = $for_current ? (int) \get_current_user_id() : (int) $user_id;

        $allowed = $this->resolve_access( $event_id, $session_index, $user_id, $for_current );

        /**
         * The final say on stream access.
         *
         * The courses module uses this to veto ("finish the pre-work first").
         *
         * @param bool $allowed
         * @param int  $event_id
         * @param int  $session_index
         * @param int  $user_id
         */
        return (bool) \apply_filters( 'anchor_events_can_access_stream', $allowed, $event_id, $session_index, $user_id );
    }

    /** The unfiltered decision — kept separate so the filter wraps it once. */
    private function resolve_access( $event_id, $session_index, $user_id, $for_current ) {
        // 1 — staff. Only meaningful for the CURRENT user: current_user_can()
        // cannot answer for somebody else without switching user context.
        if ( $for_current && Roster::current_user_can_manage() ) {
            return true;
        }
        // 2.
        if ( $user_id <= 0 ) {
            return false;
        }
        // 3 — enabled(), NOT stream_capable(): the stricter of the two, and
        // checked AHEAD of the manual-grant branch so a standing role on an
        // event the operator switched off is not a key. The switch defaults
        // on, so for most events this passes and the two session checks below
        // do the real work: holding the event role is not stream access, and
        // an in-person event with no embed refuses everybody but staff.
        if ( ! $this->enabled( $event_id ) ) {
            return false;
        }
        $sessions = $this->module->resolved_sessions( $event_id );
        $session  = $sessions[ $session_index ] ?? null;
        if ( ! \is_array( $session ) ) {
            return false;
        }
        if ( ! \in_array( (string) $session['modality'], Stream_State::STREAMABLE, true ) ) {
            return false;
        }
        if ( empty( $session['stream_embed']['src'] ) ) {
            return false;
        }
        // 4.
        if ( ( $this->grant_record( $event_id, $user_id )['source'] ?? '' ) === self::SOURCE_MANUAL ) {
            return true;
        }
        // 5 / 6.
        if ( ! $this->holds_role( $event_id, $user_id ) ) {
            return false;
        }
        $modality = $this->seat_tier_modality( $event_id, $user_id );
        if ( $modality === 'virtual' ) {
            return true;
        }
        if ( $modality === 'in_person' ) {
            $meta = $this->module->get_meta( $event_id );
            return ! empty( $meta['in_person_includes_stream'] );
        }
        return false; // Role but no confirmed seat: a stale role is not access.
    }

    /**
     * The best tier modality across the user's confirmed seats on an event.
     *
     * "Best" because a virtual seat always wins: somebody holding both an
     * in-person and a livestream ticket is entitled by the livestream one
     * regardless of the event toggle.
     *
     * @param int $event_id
     * @param int $user_id
     * @return string in_person|virtual|'' (no confirmed seat).
     */
    public function seat_tier_modality( $event_id, $user_id ) {
        $user = \get_userdata( (int) $user_id );
        if ( ! $user instanceof \WP_User ) {
            return '';
        }
        $q = new \WP_Query( [
            'post_type'      => Module::REG_CPT,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 50,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_anchor_event_id', 'value' => (int) $event_id, 'compare' => '=', 'type' => 'NUMERIC' ],
                [ 'key' => '_anchor_event_reg_status', 'value' => Registrations::STATUS_CONFIRMED, 'compare' => '=' ],
                $this->module->registrations->identity_meta_query( (int) $user_id, (string) $user->user_email ),
            ],
        ] );

        $best = '';
        foreach ( $q->posts as $seat_id ) {
            $tier_id = (string) \get_post_meta( (int) $seat_id, '_anchor_event_ticket_type_id', true );
            $tier    = $this->module->ticket_types ? $this->module->ticket_types->find( (int) $event_id, $tier_id ) : null;
            // A tier with no modality is an in-person tier — the meaning every
            // pre-upgrade tier already had (spec §3.3).
            $modality = \is_array( $tier ) ? (string) ( $tier['modality'] ?? 'in_person' ) : 'in_person';
            if ( $modality === 'virtual' ) {
                return 'virtual';
            }
            $best = 'in_person';
        }
        return $best;
    }

    /* ---------------------------------------------------------------------
     * Prerequisites (spec §4.6)
     * ------------------------------------------------------------------- */

    /**
     * Does the viewer hold the roles this event requires?
     *
     * Staff bypass — the gate exists to stop the public booking a course they
     * are not ready for, not to stop the owner adding somebody by hand.
     *
     * @param int $event_id
     * @param int $user_id  0 = the current user.
     * @return bool True when there is nothing to check.
     */
    public function meets_prerequisites( $event_id, $user_id = 0 ) {
        $meta     = $this->module->get_meta( (int) $event_id );
        $required = \is_array( $meta['required_roles'] ?? null ) ? $meta['required_roles'] : [];
        if ( empty( $required ) ) {
            return true;
        }
        $for_current = ( (int) $user_id === 0 );
        if ( $for_current && Roster::current_user_can_manage() ) {
            return true;
        }
        $user_id = $for_current ? (int) \get_current_user_id() : (int) $user_id;
        $user    = \get_userdata( $user_id );
        if ( ! $user instanceof \WP_User ) {
            return false;
        }
        $held = (array) $user->roles;
        $hits = \count( \array_intersect( $required, $held ) );
        return ( (string) ( $meta['required_roles_mode'] ?? 'any' ) === 'all' )
            ? ( $hits === \count( $required ) )
            : ( $hits > 0 );
    }

    /**
     * The refusal wording for a prerequisite-gated event.
     *
     * An anonymous visitor gets "Sign in to check eligibility" rather than a
     * list of role names they cannot act on.
     *
     * @param int $event_id
     * @return string
     */
    public function prerequisite_message( $event_id ) {
        if ( ! \is_user_logged_in() ) {
            return \__( 'Sign in to check eligibility for this course.', 'anchor-schema' );
        }
        $meta     = $this->module->get_meta( (int) $event_id );
        $required = \is_array( $meta['required_roles'] ?? null ) ? $meta['required_roles'] : [];
        $names    = [];
        $roles    = \wp_roles();
        foreach ( $required as $slug ) {
            $names[] = isset( $roles->role_names[ $slug ] )
                ? \translate_user_role( $roles->role_names[ $slug ] )
                : $slug;
        }
        if ( empty( $names ) ) {
            return '';
        }
        return \sprintf(
            /* translators: %s: comma-separated list of role names. */
            \__( 'This course requires %s.', 'anchor-schema' ),
            \implode( ', ', $names )
        );
    }

    /**
     * The generic prerequisite refusal, for a caller that has no per-event
     * message to fall back to (WooCommerce::bookability_message() when
     * Entitlements is unavailable, and the query-arg notice dictionary, which
     * can't call prerequisite_message() with an event id). Single source so
     * the two never drift apart — they used to be two hand-typed copies of
     * the same sentence (Task 10 fix round 1).
     *
     * @return string
     */
    public static function default_prerequisite_message() {
        return \__( 'You are not yet eligible to register for this course.', 'anchor-schema' );
    }
}
