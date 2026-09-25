<?php
namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Roster admin screen + CSV export (Phase 5 — spec §10).
 *
 * Loaded unconditionally (free + paid). The screen, the manual seat actions, and
 * the CSV export are gated by a WooCommerce-aware capability (M2): on a store the
 * roster exposes customer PII (billing email, customer ids, order numbers) so it
 * requires the `manage_woocommerce` capability rather than the Editor-held
 * `edit_others_posts`; free/internal installs keep `edit_others_posts`. The submenu
 * registration and every runtime check share a single source of truth via
 * Roster::cap(), which now just calls Module::events_capability() — the one
 * function the shortcodes and the WooCommerce order actions call too, so the
 * hardening cannot be sidestepped through another surface (audit REG-D20).
 *
 * This class NEVER writes seat meta directly: every mutation is delegated to the
 * Registrations data layer (claim_seats / update_status) so capacity, the event
 * lock, and history are always honored. WooCommerce order lookups are guarded by
 * `function_exists('wc_get_orders')` so the screen works in a non-WC environment.
 *
 * The CSV export handler was re-homed here from Module::handle_export(); it keeps
 * the exact action name `anchor_event_export` and nonce `anchor_event_export` so
 * existing Export links in the registrants metabox / front-end lists keep working.
 */
class Roster {

    /** Base capability for free/internal installs (no WooCommerce). */
    const CAP  = Module::CAP_BASE;
    const SLUG = 'anchor-event-roster';

    /**
     * Capability string used to register the roster submenu (M2).
     *
     * Kept as the roster's public name for the capability, but it no longer
     * decides anything: Module::events_capability() is the one function that
     * resolves the events capability for every surface — roster, CSV export,
     * the WooCommerce order actions, and the front-end console — so hardening
     * one of them can no longer be sidestepped through another (audit REG-D20,
     * REG-D62, WOO-D41). Filterable via `anchor_events_capability`.
     *
     * @return string
     */
    public static function cap() {
        return Module::events_capability();
    }

    /**
     * Runtime gate for view / export / manual seat actions (M2). Single source of
     * truth with the submenu registration: both use self::cap() so the menu cap and
     * every runtime check resolve to the exact same capability (manage_woocommerce
     * when WooCommerce is active, else edit_others_posts). This prevents a user from
     * passing the runtime gate / seeing roster links while WP denies the page.
     *
     * @return bool
     */
    public static function current_user_can_manage() {
        return \current_user_can( self::cap() );
    }

    /**
     * May this post id have its attendee list exported? (audit REG-D16)
     *
     * The capability says who; this says what. Seats are found by the
     * `_anchor_event_id` meta value, which does not require the referenced post
     * to exist, to be an event, or to still be published — so without this a
     * valid nonce exported a full attendee list (names, emails, order numbers)
     * for a trashed course, a deleted event, or any unrelated post id, under a
     * filename that named the id and an "Event" column that was blank.
     *
     * @param int $event_id
     * @return bool
     */
    public static function is_exportable_event( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) {
            return false;
        }
        if ( \get_post_type( $event_id ) !== Module::CPT ) {
            return false;
        }
        return ! \in_array( (string) \get_post_status( $event_id ), [ 'trash', 'auto-draft' ], true );
    }

    /**
     * Does this seat belong to the event whose nonce authorized the action?
     * (audit REG-D48)
     *
     * The manual seat nonces are per-event (`anchor_roster_edit_{event_id}`),
     * which reads as a per-event authorization — but the handlers only checked
     * that seat_id was a registration post, so a nonce for event A could edit or
     * cancel a seat on event B and the change landed where nobody was looking.
     *
     * @param int $seat_id
     * @param int $event_id
     * @return bool
     */
    public static function seat_belongs_to_event( $seat_id, $event_id ) {
        $seat_id  = (int) $seat_id;
        $event_id = (int) $event_id;
        if ( $seat_id <= 0 || $event_id <= 0 ) {
            return false;
        }
        if ( \get_post_type( $seat_id ) !== Module::REG_CPT ) {
            return false;
        }
        return (int) \get_post_meta( $seat_id, '_anchor_event_id', true ) === $event_id;
    }

    /** @var Module */
    private $module;

    /** @var Registrations */
    private $registrations;

    public function __construct( Module $module ) {
        $this->module        = $module;
        $this->registrations = $module->registrations;

        \add_action( 'admin_menu', [ $this, 'register_menu' ] );

        // CSV export — re-homed from Module; same action + nonce name (spec §10.4).
        \add_action( 'admin_post_anchor_event_export', [ $this, 'handle_export' ] );

        // Manual seat actions (cap-checked + nonced; delegate to the data layer).
        \add_action( 'admin_post_anchor_roster_add', [ $this, 'handle_add' ] );
        \add_action( 'admin_post_anchor_roster_edit', [ $this, 'handle_edit' ] );
        \add_action( 'admin_post_anchor_roster_cancel', [ $this, 'handle_cancel' ] );

        // Organizer roster digest — manual trigger (Task 4).
        \add_action( 'admin_post_anchor_events_send_roster', [ $this, 'handle_send_roster' ] );

        // Manual access (spec §4.4). Same per-event nonce as the seat actions.
        // Registered unconditionally — cheaper and simpler than a conditional
        // add_action() — even though each handler is unreachable from the UI
        // for an event whose master switch is off; add_access_by_email() and
        // revoke_access() each carry their own guard for that case.
        \add_action( 'admin_post_anchor_roster_grant', [ $this, 'handle_grant' ] );
        \add_action( 'admin_post_anchor_roster_revoke', [ $this, 'handle_revoke' ] );
        \add_action( 'admin_post_anchor_roster_add_access', [ $this, 'handle_add_access' ] );
    }

    /* ---------------------------------------------------------------------
     * Access (spec §8 "Switch semantics")
     * ------------------------------------------------------------------- */

    /**
     * How a seat's holder stands on this event's role.
     *
     * Four values, and `off` is not a fourth flavour of "no":
     *   - `off`    — the event's `access_role_enabled` is false (or it was
     *                never in the feature: external registration, a group
     *                parent), so the whole thing does not apply. The Access
     *                column is not rendered at all (Task 18); saying "No"
     *                would invite an operator to click Grant on an event that
     *                is not granting.
     *   - `yes`    — holds the role, granted by a seat.
     *   - `manual` — holds the role, granted by hand; a cancellation cannot
     *                take it away.
     *   - `no`     — the event is on and this person does not hold the role.
     *
     * NOTE the default is ON (spec §3.1), so `off` is the exception now, not
     * the rule: most events answer yes/manual/no.
     *
     * @param int   $event_id
     * @param array $seat Registrations::get_seat() DTO.
     * @return string off|yes|manual|no
     */
    public function access_state( $event_id, array $seat ) {
        $ent = $this->module->entitlements;
        if ( ! $ent || ! $ent->enabled( (int) $event_id ) ) {
            return 'off';
        }
        $user_id = (int) ( $seat['user_id'] ?? 0 );
        if ( $user_id <= 0 ) {
            $user    = \get_user_by( 'email', (string) ( $seat['email'] ?? '' ) );
            $user_id = $user ? (int) $user->ID : 0;
        }
        if ( $user_id <= 0 || ! $ent->holds_role( (int) $event_id, $user_id ) ) {
            return 'no';
        }
        return ( ( $ent->grant_record( (int) $event_id, $user_id )['source'] ?? '' ) === 'manual' ) ? 'manual' : 'yes';
    }

    /**
     * Whether this event is in the attendee-access feature at all.
     *
     * A thin, null-safe read of Entitlements::enabled(), because three roster
     * surfaces ask it — the Access column, the add-by-email form, and the
     * export scope — so the "is this event in the feature" test is written
     * once and Roster keeps working when $module->entitlements is somehow
     * absent.
     *
     * @param int $event_id
     * @return bool
     */
    public function access_enabled( $event_id ) {
        return (bool) ( $this->module->entitlements && $this->module->entitlements->enabled( (int) $event_id ) );
    }

    /**
     * Give somebody access with no seat at all (spec §4.4) — a comp, a
     * speaker, a late add. Creates the account when there isn't one.
     *
     * The ONE entry point in this plan that creates an account without going
     * through Entitlements::ensure_user(), so it carries its own master-switch
     * guard rather than inheriting one. The UI never offers it for a disabled
     * event (the Access column and this form are both omitted), but an
     * admin-post request is a URL and this is what makes the refusal real.
     *
     * @param int    $event_id
     * @param string $name
     * @param string $email
     * @return int User id, or 0.
     */
    public function add_access_by_email( $event_id, $name, $email ) {
        $email = \sanitize_email( (string) $email );
        $ent   = $this->module->entitlements;
        if ( $email === '' || ! $ent || ! $ent->enabled( (int) $event_id ) ) {
            return 0;
        }
        $user = \get_user_by( 'email', $email );
        if ( $user instanceof \WP_User ) {
            $user_id = (int) $user->ID;
        } else {
            // Reuse Entitlements::create_account() — the same no-mail path
            // ensure_user() takes when a seat resolves to no existing account.
            $user_id = $ent->create_account( \sanitize_text_field( (string) $name ), $email, (int) $event_id );
        }
        if ( $user_id <= 0 ) {
            return 0;
        }
        $ent->grant( (int) $event_id, $user_id, 'manual' );
        return $user_id;
    }

    /**
     * Take a manual grant away.
     *
     * A holder who still has a confirmed seat KEEPS the role — the seat
     * entitles them independently — and the caller is told so rather than
     * being shown "Access revoked." for a change that did not happen.
     *
     * @param int $event_id
     * @param int $user_id
     * @return string revoked|kept_seat|none
     */
    public function revoke_access( $event_id, $user_id ) {
        $ent = $this->module->entitlements;
        if ( ! $ent || ! $ent->holds_role( (int) $event_id, (int) $user_id ) ) {
            return 'none';
        }
        if ( $ent->has_confirmed_seat( (int) $event_id, (int) $user_id ) ) {
            // Downgrade the RECORD to 'seat' so a later cancellation can clear
            // it, but leave the role in place. NOT grant( ..., 'seat' ):
            // grant() treats a manual -> seat downgrade as a no-op on purpose
            // (manual outranks seat everywhere else), so this one legitimate
            // downgrade goes through its own dedicated method.
            $ent->downgrade_manual_grant_to_seat( (int) $event_id, (int) $user_id );
            return 'kept_seat';
        }
        $ent->revoke( (int) $event_id, (int) $user_id, 'manual' );
        return 'revoked';
    }

    /* ---------------------------------------------------------------------
     * Menu + URLs
     * ------------------------------------------------------------------- */

    public function register_menu() {
        \add_submenu_page(
            'edit.php?post_type=' . Module::CPT,
            \__( 'Event Roster', 'anchor-schema' ),
            \__( 'Roster', 'anchor-schema' ),
            self::cap(),
            self::SLUG,
            [ $this, 'render_page' ]
        );
    }

    /**
     * Shared nonced link builder to the roster submenu.
     *
     * @param int   $event_id
     * @param array $args Extra query args.
     * @return string
     */
    public function roster_url( $event_id, array $args = [] ) {
        $args = \array_merge( [
            'post_type' => Module::CPT,
            'page'      => self::SLUG,
            'event_id'  => (int) $event_id,
        ], $args );
        // No nonce: the roster screen is a read-only view gated by
        // current_user_can_manage(), and render_page() never verified the
        // `anchor_roster_view_{id}` nonce this used to mint. A nonce nobody checks
        // reads like authorization that is not there — and it broke every hand-typed
        // or bookmarked roster URL into a link that merely looked protected.
        return \add_query_arg( $args, \admin_url( 'edit.php' ) );
    }

    /* ---------------------------------------------------------------------
     * Screen
     * ------------------------------------------------------------------- */

    public function render_page() {
        if ( ! self::current_user_can_manage() ) {
            \wp_die( \esc_html__( 'You do not have permission to view rosters.', 'anchor-schema' ) );
        }

        $event_id = isset( $_GET['event_id'] ) ? (int) \wp_unslash( $_GET['event_id'] ) : 0;

        echo '<div class="wrap">';
        if ( $event_id <= 0 || \get_post_type( $event_id ) !== Module::CPT ) {
            $this->render_event_picker();
        } else {
            $this->render_roster( $event_id );
        }
        echo '</div>';
    }

    private function render_event_picker() {
        echo '<h1>' . \esc_html__( 'Event Roster', 'anchor-schema' ) . '</h1>';
        echo '<p>' . \esc_html__( 'Choose an event to view its roster.', 'anchor-schema' ) . '</p>';

        $events = \get_posts( [
            'post_type'      => Module::CPT,
            'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ] );

        if ( empty( $events ) ) {
            echo '<p>' . \esc_html__( 'No events found.', 'anchor-schema' ) . '</p>';
            return;
        }

        echo '<ul class="ul-disc">';
        foreach ( $events as $event ) {
            echo '<li><a href="' . \esc_url( $this->roster_url( $event->ID ) ) . '">'
                . \esc_html( \get_the_title( $event ) ? \get_the_title( $event ) : ( '#' . (int) $event->ID ) )
                . '</a></li>';
        }
        echo '</ul>';
    }

    private function render_roster( $event_id ) {
        $event_id = (int) $event_id;

        echo '<h1 class="wp-heading-inline">'
            . \esc_html( \get_the_title( $event_id ) ? \get_the_title( $event_id ) : ( '#' . $event_id ) )
            . ' &mdash; ' . \esc_html__( 'Roster', 'anchor-schema' ) . '</h1>';
        echo ' <a href="' . \esc_url( (string) \get_edit_post_link( $event_id ) ) . '" class="page-title-action">'
            . \esc_html__( 'Edit event', 'anchor-schema' ) . '</a>';
        echo '<hr class="wp-header-end" />';

        $this->maybe_render_notice();
        $this->render_summary( $event_id );

        // Edit panel (when an Edit row-action is active).
        $edit_seat = isset( $_GET['edit_seat'] ) ? (int) \wp_unslash( $_GET['edit_seat'] ) : 0;
        if ( $edit_seat > 0 ) {
            $this->render_edit_form( $event_id, $edit_seat );
        }

        $this->render_status_pill_styles();

        // List table.
        load_roster_list_table();
        $table = new Roster_List_Table( $event_id, $this->registrations, $this );
        $table->prepare_items();

        // REG-D13 — WP_List_Table::display() does not render the status filter
        // links; the page has to call views() itself. Without this the All /
        // Active / Confirmed / ... links that get_views() builds existed only in
        // code and the filter worked only for a hand-typed URL.
        $table->views();

        echo '<form method="get">';
        echo '<input type="hidden" name="post_type" value="' . \esc_attr( Module::CPT ) . '" />';
        echo '<input type="hidden" name="page" value="' . \esc_attr( self::SLUG ) . '" />';
        echo '<input type="hidden" name="event_id" value="' . (int) $event_id . '" />';
        $table->search_box( \__( 'Search attendees / order #', 'anchor-schema' ), 'anchor-roster-search' );
        $table->display();
        echo '</form>';

        $this->render_add_form( $event_id );
    }

    private function render_summary( $event_id ) {
        $s = $this->registrations->get_event_summary( (int) $event_id );

        $cap_label = $s['capacity'] > 0 ? (string) $s['capacity'] : \__( 'unlimited', 'anchor-schema' );
        $remaining = $s['remaining'] < 0 ? \__( 'unlimited', 'anchor-schema' ) : (string) $s['remaining'];

        echo '<div class="anchor-roster-summary" style="margin:12px 0;padding:10px 14px;background:#fff;border:1px solid #ccd0d4;border-radius:3px;">';
        echo '<strong>' . \esc_html__( 'Capacity', 'anchor-schema' ) . ':</strong> ' . \esc_html( $cap_label );
        echo ' &middot; <strong>' . \esc_html__( 'Reserved', 'anchor-schema' ) . ':</strong> ' . (int) $s['reserved'];
        echo ' (' . (int) $s['confirmed'] . ' ' . \esc_html__( 'confirmed', 'anchor-schema' )
            . ' + ' . (int) $s['pending'] . ' ' . \esc_html__( 'pending', 'anchor-schema' ) . ')';
        echo ' &middot; <strong>' . \esc_html__( 'Remaining', 'anchor-schema' ) . ':</strong> ' . \esc_html( $remaining );
        echo ' &middot; <strong>' . \esc_html__( 'Waitlist', 'anchor-schema' ) . ':</strong> ' . (int) $s['waitlist'];
        echo '</div>';

        if ( ! empty( $s['is_overbooked'] ) ) {
            echo '<div class="notice notice-warning inline"><p>'
                . \esc_html__( 'This event is overbooked — reserved seats exceed capacity.', 'anchor-schema' )
                . '</p></div>';
        }

        // Export links.
        $base = \admin_url( 'admin-post.php?action=anchor_event_export&event_id=' . (int) $event_id );
        $all  = \wp_nonce_url( \add_query_arg( 'scope', 'all', $base ), 'anchor_event_export' );
        $act  = \wp_nonce_url( \add_query_arg( 'scope', 'active', $base ), 'anchor_event_export' );
        echo '<p>';
        echo '<a class="button" href="' . \esc_url( $all ) . '">' . \esc_html__( 'Export CSV (all statuses)', 'anchor-schema' ) . '</a> ';
        echo '<a class="button" href="' . \esc_url( $act ) . '">' . \esc_html__( 'Export CSV (confirmed only)', 'anchor-schema' ) . '</a>';
        if ( $this->access_enabled( $event_id ) ) {
            $acc = \wp_nonce_url( \add_query_arg( 'scope', 'access', $base ), 'anchor_event_export' );
            echo ' <a class="button" href="' . \esc_url( $acc ) . '">' . \esc_html__( 'Export confirmed + manual access', 'anchor-schema' ) . '</a>';
        }
        echo '</p>';

        if ( self::current_user_can_manage() ) {
            $send_url = \admin_url( 'admin-post.php' );
            echo '<form method="post" action="' . \esc_url( $send_url ) . '" style="display:inline-block;margin-top:4px;">';
            echo '<input type="hidden" name="action" value="anchor_events_send_roster" />';
            echo '<input type="hidden" name="event_id" value="' . \esc_attr( (string) $event_id ) . '" />';
            \wp_nonce_field( 'anchor_events_send_roster_' . $event_id );
            \submit_button( \__( 'Send roster to organizer', 'anchor-schema' ), 'secondary', 'submit', false );
            echo '</form>';
        }
    }

    private function render_add_form( $event_id ) {
        $event_id = (int) $event_id;
        echo '<h2>' . \esc_html__( 'Add attendee', 'anchor-schema' ) . '</h2>';
        echo '<p class="description">' . \esc_html__( 'Manually added seats go through the same capacity decision as a public sign-up: a closed registration window, a sold-out flag or a full event blocks the add (or waitlists it, when the waitlist is on). Use “Allow over capacity” to override all of that deliberately — it is recorded in the seat history and the error log.', 'anchor-schema' ) . '</p>';
        echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:24px;">';
        echo '<input type="hidden" name="action" value="anchor_roster_add" />';
        echo '<input type="hidden" name="event_id" value="' . \esc_attr( (string) $event_id ) . '" />';
        \wp_nonce_field( 'anchor_roster_add_' . $event_id );
        echo '<table class="form-table"><tbody>';
        $this->text_row( 'roster_name', \__( 'Name', 'anchor-schema' ), '', true );
        $this->text_row( 'roster_email', \__( 'Email', 'anchor-schema' ), '', false, 'email' );
        $this->text_row( 'roster_phone', \__( 'Phone', 'anchor-schema' ), '' );
        $this->text_row( 'roster_guests', \__( 'Additional guests', 'anchor-schema' ), '0', false, 'number' );
        $this->tier_row( $event_id );
        $this->question_rows( $event_id );
        $this->override_row();
        echo '</tbody></table>';
        \submit_button( \__( 'Add attendee', 'anchor-schema' ) );
        echo '</form>';

        // Add-by-email (spec §4.4) — omitted entirely for an event that is
        // not in the access feature (access_enabled()), same as the Access
        // column: there is nothing to grant into.
        if ( $this->access_enabled( $event_id ) ) {
            echo '<h2>' . \esc_html__( 'Add person by email', 'anchor-schema' ) . '</h2>';
            echo '<p class="description">' . \esc_html__( 'Grants this event’s role directly — no seat is created and it never counts toward capacity. Use this for a comp, a speaker, or anyone who needs access without registering.', 'anchor-schema' ) . '</p>';
            echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:24px;">';
            echo '<input type="hidden" name="action" value="anchor_roster_add_access" />';
            echo '<input type="hidden" name="event_id" value="' . \esc_attr( (string) $event_id ) . '" />';
            \wp_nonce_field( 'anchor_roster_edit_' . $event_id );
            echo '<table class="form-table"><tbody>';
            $this->text_row( 'access_name', \__( 'Name', 'anchor-schema' ), '' );
            $this->text_row( 'access_email', \__( 'Email', 'anchor-schema' ), '', true, 'email' );
            echo '</tbody></table>';
            \submit_button( \__( 'Grant access', 'anchor-schema' ) );
            echo '</form>';
        }
    }

    /**
     * The stored values a seat edit form fills itself from, or null when the
     * seat does not belong to this event (REG-D48).
     *
     * REG-D56 — one reader. render_edit_form() and frontend_edit_form() each
     * pulled the same five/six meta keys by hand, so they had already drifted:
     * only the admin form knew about `source`, only the console keyed its
     * WooCommerce warning off `order_id`, and adding a field meant remembering
     * both. This returns the seat DTO the rest of the module already uses.
     *
     * @param int $event_id
     * @param int $seat_id
     * @return array|null
     */
    private function seat_form_values( $event_id, $seat_id ) {
        if ( ! self::seat_belongs_to_event( $seat_id, $event_id ) ) {
            return null;
        }
        return $this->registrations->get_seat( (int) $seat_id );
    }

    /** Is this seat owned by a WooCommerce order (so its money fields are read-only)? */
    private static function seat_is_order_owned( array $seat ) {
        return ( (string) ( $seat['source'] ?? '' ) === 'woocommerce' ) || ( (int) ( $seat['order_id'] ?? 0 ) > 0 );
    }

    private function render_edit_form( $event_id, $seat_id ) {
        $event_id = (int) $event_id;
        $seat_id  = (int) $seat_id;
        // REG-D48 — the read side needs the same scoping as handle_edit(): the seat
        // id arrives in $_GET, and rendering another event's seat here would print
        // that event's attendee name, email and phone under this event's heading.
        $seat = $this->seat_form_values( $event_id, $seat_id );
        if ( ! \is_array( $seat ) ) {
            echo '<div class="notice notice-error inline"><p>'
                . \esc_html__( 'Seat not found.', 'anchor-schema' ) . '</p></div>';
            return;
        }
        $name   = (string) $seat['name'];
        $email  = (string) $seat['email'];
        $phone  = (string) $seat['phone'];
        $status = (string) $seat['status'];
        $oid    = (int) $seat['order_id'];
        $is_woo = self::seat_is_order_owned( $seat );

        echo '<div class="anchor-roster-edit" style="margin:12px 0;padding:12px 16px;background:#fff;border:1px solid #2271b1;border-radius:3px;">';
        echo '<h2 style="margin-top:0;">' . \esc_html__( 'Edit seat', 'anchor-schema' ) . ' #' . \esc_html( (string) $seat_id ) . '</h2>';
        echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="anchor_roster_edit" />';
        echo '<input type="hidden" name="event_id" value="' . \esc_attr( (string) $event_id ) . '" />';
        echo '<input type="hidden" name="seat_id" value="' . \esc_attr( (string) $seat_id ) . '" />';
        \wp_nonce_field( 'anchor_roster_edit_' . $event_id );
        echo '<table class="form-table"><tbody>';
        $this->text_row( 'roster_name', \__( 'Name', 'anchor-schema' ), $name );
        $this->text_row( 'roster_email', \__( 'Email', 'anchor-schema' ), $email, false, 'email' );
        $this->text_row( 'roster_phone', \__( 'Phone', 'anchor-schema' ), $phone );

        // Status select.
        echo '<tr><th scope="row"><label for="roster_status">' . \esc_html__( 'Status', 'anchor-schema' ) . '</label></th><td>';
        echo '<select name="roster_status" id="roster_status">';
        foreach ( $this->status_options_for( $status ) as $val => $label ) {
            echo '<option value="' . \esc_attr( $val ) . '"' . \selected( $status, $val, false ) . '>' . \esc_html( $label ) . '</option>';
        }
        echo '</select></td></tr>';

        if ( $is_woo ) {
            // Order-derived fields are read-only for WooCommerce seats.
            echo '<tr><th scope="row">' . \esc_html__( 'Order', 'anchor-schema' ) . '</th><td>';
            echo '<input type="text" disabled value="' . \esc_attr( $oid > 0 ? ( '#' . $oid ) : '' ) . '" />';
            echo ' <span class="description">' . \esc_html__( 'Managed by WooCommerce — cancel/refund in the order to keep seats in sync.', 'anchor-schema' ) . '</span>';
            echo '</td></tr>';
        }
        // REG-D38 — a revive to confirmed now consults capacity, so the edit form
        // needs the same explicit "I know, do it anyway" the add form has. It is
        // never the default: an unticked box refuses rather than overbooking.
        $this->override_row();
        echo '</tbody></table>';
        \submit_button( \__( 'Save seat', 'anchor-schema' ) );
        echo ' <a class="button" href="' . \esc_url( $this->roster_url( $event_id ) ) . '">' . \esc_html__( 'Cancel', 'anchor-schema' ) . '</a>';
        echo '</form>';
        echo '</div>';
    }

    private function text_row( $name, $label, $value, $required = false, $type = 'text' ) {
        echo '<tr><th scope="row"><label for="' . \esc_attr( $name ) . '">' . \esc_html( $label ) . '</label></th><td>';
        echo '<input type="' . \esc_attr( $type ) . '" name="' . \esc_attr( $name ) . '" id="' . \esc_attr( $name ) . '" '
            . 'class="regular-text" value="' . \esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . ' />';
        echo '</td></tr>';
    }

    /**
     * Ticket-type selector for the manual add form (Phase 7 — spec §10). Options
     * come from the event's active ticket tiers (value = stable tier id, label +
     * price). When only the implicit primary tier exists the select still shows
     * that single option so the chosen tier is always explicit.
     *
     * @param int $event_id
     */
    /**
     * Active ticket tiers for the seat forms: id => label (price appended when
     * there is one), plus the id a fresh form should preselect.
     *
     * REG-D56 — ONE resolver. The admin form's tier_row() and the console's
     * frontend_tier_choices() each carried their own copy of these rules
     * (active tiers, fall back to all tiers when none is active, label
     * fallback, wc_price() formatting), so the two screens could answer
     * differently about the same event and any fix had to be made twice. The
     * markup is still per-screen; the decision is not.
     *
     * @param int $event_id
     * @return array{choices:array<string,string>,primary:string}
     */
    private function tier_choices( $event_id ) {
        $tt = isset( $this->module->ticket_types ) ? $this->module->ticket_types : null;
        if ( ! $tt ) {
            return [ 'choices' => [], 'primary' => 'primary' ];
        }
        $tiers  = (array) $tt->get( (int) $event_id );
        $active = [];
        foreach ( $tiers as $t ) {
            if ( ! empty( $t['active'] ) ) {
                $active[] = $t;
            }
        }
        if ( empty( $active ) ) {
            // Defensive: keep at least the implicit primary so the field is usable.
            $active = $tiers;
        }

        $choices = [];
        foreach ( $active as $t ) {
            $id    = (string) ( $t['id'] ?? 'primary' );
            $label = ( isset( $t['label'] ) && $t['label'] !== '' ) ? (string) $t['label'] : \__( 'Registration', 'anchor-schema' );
            $price = (float) ( $t['price'] ?? 0 );
            if ( $price > 0 ) {
                $label .= ' — ' . ( \function_exists( 'wc_price' )
                    ? \wp_strip_all_tags( \wc_price( $price ) )
                    : \number_format_i18n( $price, 2 ) );
            }
            $choices[ $id ] = $label;
        }

        return [
            'choices' => $choices,
            'primary' => (string) $tt->primary_id( (int) $event_id ),
        ];
    }

    private function tier_row( $event_id ) {
        $tiers = $this->tier_choices( $event_id );
        if ( empty( $tiers['choices'] ) ) {
            return;
        }

        echo '<tr><th scope="row"><label for="roster_ticket_type">' . \esc_html__( 'Ticket type', 'anchor-schema' ) . '</label></th><td>';
        echo '<select name="roster_ticket_type" id="roster_ticket_type">';
        foreach ( $tiers['choices'] as $id => $label ) {
            echo '<option value="' . \esc_attr( $id ) . '"' . \selected( $tiers['primary'], $id, false ) . '>' . \esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';
    }

    /**
     * The event's own attendee questions, as form-table rows (audit REG-D39).
     *
     * The manual add form used to collect none of them, so an event asking two
     * required questions got a hand-added seat with an empty answer set: its
     * roster row and its CSV cells were blank, with nothing to say the answers
     * had never been asked for. Same question model, same answer keys, same
     * control renderer as the free form and the WooCommerce checkout — this is
     * a third CALL SITE, not a third implementation.
     *
     * @param int $event_id
     */
    private function question_rows( $event_id ) {
        foreach ( $this->module_questions( $event_id ) as $q ) {
            $id       = 'roster_field_' . $q['key'];
            $req_mark = ! empty( $q['required'] ) ? ' <span class="description">*</span>' : '';
            echo '<tr><th scope="row"><label for="' . \esc_attr( $id ) . '">' . \esc_html( $q['label'] ) . $req_mark . '</label></th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $req_mark is a literal.
            echo $this->module->render_registration_question_control( $q, [ // phpcs:ignore WordPress.Security.EscapeOutput -- the renderer escapes.
                'name'  => 'roster_field[' . $q['key'] . ']',
                'id'    => $id,
                // `.regular-text` is a width, so it belongs on the text and
                // textarea controls only — never on a checkbox or a select.
                'class' => \in_array( $q['type'], [ 'select', 'checkbox' ], true ) ? '' : 'regular-text',
            ] );
            echo '</td></tr>';
        }
    }

    /** "Allow over capacity" override checkbox for the manual add form (spec §10). */
    private function override_row() {
        echo '<tr><th scope="row">' . \esc_html__( 'Capacity', 'anchor-schema' ) . '</th><td>';
        echo $this->override_check(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
        echo '</td></tr>';
    }

    /**
     * The "Allow over capacity" control itself, without the wp-admin table row
     * around it — the front-end console lays its fields out in divs.
     *
     * One source for the field name and the label: handle_add() and
     * handle_edit() both read `roster_allow_over`, and both refusals tell the
     * operator to tick a box by that name. The console's EDIT form had no such
     * box at all, so its refusal ("tick “Allow over capacity”") named a control
     * that was not on the page.
     *
     * @return string Escaped markup.
     */
    private function override_check() {
        return '<label><input type="checkbox" name="roster_allow_over" value="1" /> '
            . \esc_html__( 'Allow over capacity (bypass the event capacity and tier quota)', 'anchor-schema' )
            . '</label>';
    }

    /**
     * Resolve a ticket-type id to its human label for an event. The single tier
     * column shared by the roster metabox table, the registrants list table and
     * the CSV export.
     *
     * A seat stores its tier as a bare id and nothing repoints or relabels it
     * when the organizer removes that row from the Tickets metabox (audit
     * WOO-D58): Ticket_Types::find() returns null forever after, and the column
     * used to print the raw id — a twelve-character hash that reads as data
     * corruption rather than as "this tier no longer exists". The id is still
     * shown, because it is the only handle anyone has on those seats (the
     * quota that used to bind them is gone too, and the seats are invisible to
     * every tier count), but it is now labelled for what it is.
     *
     * @param int    $event_id
     * @param string $tier_id
     * @return string
     */
    public function tier_label( $event_id, $tier_id ) {
        $tier_id = (string) $tier_id;
        if ( $tier_id === '' ) {
            $tier_id = Ticket_Types::PRIMARY_ID;
        }
        $fallback = $tier_id === Ticket_Types::PRIMARY_ID ? \__( 'Primary', 'anchor-schema' ) : $tier_id;

        $tt = isset( $this->module->ticket_types ) ? $this->module->ticket_types : null;
        if ( ! $tt ) {
            return $fallback;
        }
        $tier = $tt->find( (int) $event_id, $tier_id );
        if ( ! \is_array( $tier ) ) {
            // The row is GONE — the only case that earns the marker.
            return \sprintf(
                /* translators: %s: the stored ticket-tier id, which no longer exists on the event. */
                \__( '%s (retired tier)', 'anchor-schema' ),
                $fallback
            );
        }
        // The row exists but was saved with a blank label: show what it has, as
        // before. That is an authoring gap, not a dangling reference.
        return ( isset( $tier['label'] ) && $tier['label'] !== '' ) ? (string) $tier['label'] : $fallback;
    }

    /* ---------------------------------------------------------------------
     * Manual seat actions (delegate to the data layer)
     * ------------------------------------------------------------------- */

    /**
     * Add one seat by hand (audit REG-D39).
     *
     * This is a seat-creating path like any other, and it used to be the only
     * one that never asked the capacity authority. capacity_decision() owns the
     * registration window, the hand-set `sold_out` flag, the past-event check,
     * the event total and the per-tier quota; claim_seats() below owns only the
     * numeric capacity and the quota. So an event whose registration had closed,
     * or one an organizer had marked sold out, quietly accepted a roster add
     * with nobody ticking "Allow over capacity" — the checkbox that exists to
     * mean exactly "I know, do it anyway".
     *
     * finding-6 — capacity_decision() ALSO never consults the event's status
     * (it is purely a seat-count/window authority), so a hand-cancelled event
     * with room left decided 'open' and slipped through the same gap.
     * finding-16 — the same is true of a postponed event. This method reads
     * get_event_status() itself, alongside capacity_decision(), and refuses
     * with `closed` for a cancelled or postponed event exactly like a closed
     * registration window (Module::status_is_closed() is the one place that
     * pair is named) — same "Allow over capacity" escape hatch, logged as
     * `capacity_overfill` with reason `status_cancelled` or `status_postponed`
     * when used.
     *
     * The order is: is this a real event → is the submission usable → may a seat
     * be sold → mint it. Each refusal returns its own code (see redirect()).
     */
    public function handle_add() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        $this->guard( 'anchor_roster_add_' . $event_id );

        // Nonce, capability, THEN the object — the order every handler uses.
        if ( \get_post_type( $event_id ) !== Module::CPT ) {
            $this->redirect( $event_id, 'error', \__( 'That is not an event.', 'anchor-schema' ), 'invalid' );
        }
        // A group container is not a seat and never has been: its capacity,
        // roster and tiers all live on its dates. bookability() answers
        // 'parent' for it, and a seat minted here would be invisible to every
        // date's roster and counted by none of them. There is nothing to
        // override, so this is `invalid` rather than `closed`.
        //
        // `disabled` — registration switched off — is deliberately NOT refused:
        // adding somebody by hand to a course that takes no public sign-ups is
        // the whole point of this form.
        if ( $this->module->occurrences && $this->module->occurrences->is_group_parent( $event_id ) ) {
            $this->redirect( $event_id, 'error', \__( 'This is a multi-date offering — add the attendee to one of its dates, not to the container.', 'anchor-schema' ), 'invalid' );
        }

        $name   = \sanitize_text_field( \wp_unslash( $_POST['roster_name'] ?? '' ) );
        $raw_email = \sanitize_text_field( \wp_unslash( $_POST['roster_email'] ?? '' ) );
        $email  = \sanitize_email( $raw_email );
        $phone  = \sanitize_text_field( \wp_unslash( $_POST['roster_phone'] ?? '' ) );
        $guests = max( 0, (int) \wp_unslash( $_POST['roster_guests'] ?? 0 ) );

        // Ticket tier (Phase 7 — spec §10). Validate the posted id against the
        // event's tiers; fall back to the primary tier id when missing/invalid.
        $tt         = isset( $this->module->ticket_types ) ? $this->module->ticket_types : null;
        $tier_id    = \sanitize_key( (string) \wp_unslash( $_POST['roster_ticket_type'] ?? '' ) );
        $tier       = null;
        if ( $tt ) {
            $primary = (string) $tt->primary_id( $event_id );
            if ( $tier_id !== '' ) {
                $tier = $tt->find( $event_id, $tier_id );
            }
            if ( ! \is_array( $tier ) ) {
                $tier_id = $primary;
                $tier    = $tt->find( $event_id, $tier_id );
            } else {
                $tier_id = (string) ( $tier['id'] ?? $tier_id );
            }
        }
        if ( $tier_id === '' ) {
            $tier_id = 'primary';
        }

        // Over-capacity override (spec §10). Bypasses the registration window,
        // the sold-out flag, the event capacity and the per-tier quota; recorded
        // in the seat history note and in the error log.
        $allow_over = ! empty( $_POST['roster_allow_over'] );

        // Whether this manual add emails the attendee. The wp-admin form has no
        // such control and posts nothing, so it keeps the historical behaviour
        // (always notify); the front-end console offers a checkbox for the case
        // where somebody is being added after they were told in person.
        $notify = ! isset( $_POST['roster_notify_control'] ) || ! empty( $_POST['roster_notify'] );

        /* --- Is the submission usable? (code: invalid) --- */
        if ( \trim( $name ) === '' ) {
            $this->redirect( $event_id, 'error', \__( 'A name is required.', 'anchor-schema' ), 'invalid' );
        }
        if ( $raw_email !== '' && $email === '' ) {
            $this->redirect( $event_id, 'error', \__( 'That email address is not usable — correct it or leave the field blank.', 'anchor-schema' ), 'invalid' );
        }

        // The event's own attendee questions (REG-D39), through the one
        // validator the free form and the WooCommerce checkout use.
        $posted_answers = ( ! empty( $_POST['roster_field'] ) && \is_array( $_POST['roster_field'] ) )
            ? \wp_unslash( $_POST['roster_field'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per answer in the validator.
            : [];
        $validated = $this->module->sanitize_registration_answers( $event_id, $posted_answers );
        if ( ! empty( $validated['missing'] ) ) {
            $labels = [];
            foreach ( $validated['missing'] as $q ) {
                $labels[] = $q['label'];
            }
            $this->redirect( $event_id, 'error', \sprintf(
                /* translators: %s: comma-separated list of question labels. */
                \__( 'This event requires an answer to: %s', 'anchor-schema' ),
                \implode( ', ', $labels )
            ), 'invalid' );
        }

        /* --- May a seat be sold at all? (codes: closed | full) --- */
        $meta     = $this->module->get_meta( $event_id );
        $decision = $this->registrations->capacity_decision( $event_id, $meta, 1 + $guests, $tier );
        // finding-6 — capacity_decision() (Registrations) never consults the
        // event's status; it only reasons about the registration window and
        // seat counts. A hand-cancelled or postponed event with room left (or
        // unlimited capacity) decided 'open' and a manual add went straight
        // through with nobody ticking "Allow over capacity" — the checkbox
        // that exists to mean exactly "I know, do it anyway". Checked here,
        // alongside capacity_decision(), rather than folded into that method
        // (kept — see the "Interfaces to keep" note on this task): the same
        // status_is_closed( get_event_status() ) read bookability() uses for
        // the exact same judgement (THEME-D25 / finding-16).
        $closed_status  = $this->module->get_event_status( $event_id, $meta );
        $status_closed  = $this->module->status_is_closed( $closed_status );
        if ( ! $allow_over ) {
            if ( $status_closed ) {
                $this->redirect( $event_id, 'error', \__( 'This event is cancelled or postponed — tick “Allow over capacity” to add anyway.', 'anchor-schema' ), 'closed' );
            }
            if ( $decision === 'closed' ) {
                $this->redirect( $event_id, 'error', \__( 'Registration is closed for this event — tick “Allow over capacity” to add anyway.', 'anchor-schema' ), 'closed' );
            }
            if ( $decision === 'full' ) {
                $this->redirect( $event_id, 'error', \__( 'This event is full and its waitlist is off — tick “Allow over capacity” to add anyway.', 'anchor-schema' ), 'full' );
            }
        }

        $result = $this->registrations->claim_seats( $event_id, $meta, 1, [
            'source'         => 'manual',
            'name'           => $name,
            'email'          => $email,
            'phone'          => $phone,
            'guests'         => $guests,
            'reg_fields'     => $validated['answers'],
            'ticket_type_id' => $tier_id,
            'actor'          => 'user:' . \get_current_user_id(),
            'note'           => $allow_over ? 'manual add (capacity override)' : 'manual roster add',
        ], $tier, $allow_over );

        // Comped adds resolve (and if necessary create) an account too, so a
        // hand-added attendee reaches the room exactly like a paid one. No
        // enabled() check here on purpose: ensure_user() owns that guard, so
        // adding a comped seat to a plain in-person event creates nothing.
        if ( $this->module->entitlements ) {
            foreach ( \array_merge( (array) ( $result['created'] ?? [] ), (array) ( $result['waitlisted'] ?? [] ) ) as $new_seat_id ) {
                $this->module->entitlements->ensure_user( [ 'id' => (int) $new_seat_id ] );
            }
        }

        // L2: surface an admin-visible signal when a seat was created while the
        // event capacity lock was unavailable (mirrors the paid path), so manual
        // adds can't silently oversell under lock degradation.
        if ( ! empty( $result['lock_unavailable'] ) && ( ! empty( $result['created'] ) || ! empty( $result['waitlisted'] ) ) ) {
            Events_Log::error( 'capacity_lock_unavailable', [ 'event' => $event_id, 'source' => 'manual' ] );
        }

        // A deliberate oversell is recorded the same way the paid path records
        // one — never as the default, always because somebody ticked the box,
        // and only when the box actually changed the answer (an override on an
        // event with room is not an overfill). finding-6 / finding-16: a
        // cancelled or postponed event is reported as `status_cancelled` /
        // `status_postponed` even when capacity_decision() itself still says
        // 'open' — the override that mattered here was the status, not a seat
        // count.
        if ( $allow_over && ( $status_closed || $decision !== 'open' ) && ! empty( $result['created'] ) ) {
            Events_Log::error( 'capacity_overfill', [
                'event'  => $event_id,
                'source' => 'manual',
                'from'   => $status_closed ? ( 'status_' . $closed_status ) : $decision,
            ] );
        }

        if ( ! empty( $result['created'] ) ) {
            // finding-13 — pass the seat id through for Events_Log identity
            // only (two attendees added to the same event must not collapse
            // into one deduped mail-failure row).
            $emailed = $notify
                ? $this->module->send_registration_emails( $event_id, $name, $email, Registrations::STATUS_CONFIRMED, $guests, (int) $result['created'][0] )
                : Outcome::skipped( 'notify_off' );
            // "and emailed" is only true when an email actually went (REG-D24):
            // notify_user, the per-event confirmation switch and a blank address
            // each silently stop it, and the notice used to claim it anyway.
            $this->redirect( $event_id, 'success', $emailed->is_sent()
                ? \__( 'Attendee added and emailed.', 'anchor-schema' )
                : \__( 'Attendee added (no confirmation email sent).', 'anchor-schema' ) );
        } elseif ( ! empty( $result['waitlisted'] ) ) {
            $emailed = $notify
                ? $this->module->send_registration_emails( $event_id, $name, $email, Registrations::STATUS_WAITLIST, $guests, (int) $result['waitlisted'][0] )
                : Outcome::skipped( 'notify_off' );
            $this->redirect( $event_id, 'success', $emailed->is_sent()
                ? \__( 'Attendee added to the waitlist (event is full) and emailed.', 'anchor-schema' )
                : \__( 'Attendee added to the waitlist (event is full); no email sent.', 'anchor-schema' ) );
        } else {
            $this->redirect( $event_id, 'error', \__( 'Could not add attendee — the event is full and the waitlist is disabled.', 'anchor-schema' ), 'full' );
        }
    }

    public function handle_edit() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        $seat_id  = isset( $_POST['seat_id'] ) ? (int) \wp_unslash( $_POST['seat_id'] ) : 0;
        $this->guard( 'anchor_roster_edit_' . $event_id );

        // REG-D48 — the nonce is per-event, so the seat has to be this event's.
        // Deliberately the same message either way: a seat that exists on another
        // event must not be distinguishable from one that does not exist.
        if ( ! self::seat_belongs_to_event( $seat_id, $event_id ) ) {
            $this->redirect( $event_id, 'error', \__( 'Seat not found.', 'anchor-schema' ) );
        }

        $name       = \sanitize_text_field( \wp_unslash( $_POST['roster_name'] ?? '' ) );
        $email      = \sanitize_email( \wp_unslash( $_POST['roster_email'] ?? '' ) );
        $phone      = \sanitize_text_field( \wp_unslash( $_POST['roster_phone'] ?? '' ) );
        $status     = \sanitize_text_field( \wp_unslash( $_POST['roster_status'] ?? '' ) );
        $allow_over = ! empty( $_POST['roster_allow_over'] );

        // Contact fields (name/email/phone) are not order-derived, so they are
        // editable for any seat — delegated to the data layer (no direct writes).
        // REG-D34: a blank name is a REFUSAL now, not a silent no-op that still
        // reported "Seat updated." while the old name stayed on the roster.
        $saved = $this->registrations->update_contact( $seat_id, [
            'name'  => $name,
            'email' => $email,
            'phone' => $phone,
        ] );
        if ( $saved->is_failed() ) {
            $message = $saved->reason() === 'empty_name'
                ? \__( 'A name is required — nothing was saved.', 'anchor-schema' )
                : \__( 'That seat could not be updated.', 'anchor-schema' );
            $this->redirect( $event_id, 'error', $message, 'invalid' );
        }

        // Status change routed through the data layer (transition rules + history).
        // REG-D38: a revive to a capacity-consuming status goes through the
        // capacity-checked path — cancelled -> confirmed and waitlist ->
        // confirmed used to skip the recount entirely and silently overbook.
        $current = (string) \get_post_meta( $seat_id, '_anchor_event_reg_status', true );
        if ( $status !== '' && $status !== $current ) {
            $changed = $this->registrations->change_status_with_capacity(
                $seat_id,
                $status,
                'roster edit',
                'user:' . \get_current_user_id(),
                $allow_over
            );
            if ( $changed->is_failed() ) {
                if ( $changed->reason() === 'capacity_full' ) {
                    $this->redirect( $event_id, 'error', \__( 'Contact details saved, but this event is full — tick “Allow over capacity” to confirm the seat anyway.', 'anchor-schema' ), 'full' );
                }
                // The event has room; this seat's ticket type does not. Told
                // apart because "the event is full" would send the operator to
                // raise the wrong number — and because a tier sold out on an
                // event with seats left never goes to the waitlist.
                if ( $changed->reason() === 'tier_full' ) {
                    $this->redirect( $event_id, 'error', \__( 'Contact details saved, but this seat’s ticket type is sold out — raise that ticket type’s quota, or tick “Allow over capacity” to confirm the seat anyway.', 'anchor-schema' ), 'tier_full' );
                }
                // Illegal transition — contact fields were still saved, but surface
                // the rejected status change instead of reporting full success (CodeRabbit).
                $this->redirect( $event_id, 'error', \sprintf(
                    /* translators: 1: from status, 2: to status */
                    \__( 'Contact details saved, but the status change from “%1$s” to “%2$s” is not allowed.', 'anchor-schema' ),
                    $current,
                    $status
                ), 'invalid' );
            }
            if ( $changed->reason() === 'waitlisted' ) {
                $this->redirect( $event_id, 'success', \__( 'Seat updated — the event was full, so this seat went to the waitlist rather than being confirmed.', 'anchor-schema' ) );
            }
        }

        $this->redirect( $event_id, 'success', \__( 'Seat updated.', 'anchor-schema' ) );
    }

    public function handle_cancel() {
        $event_id = isset( $_REQUEST['event_id'] ) ? (int) \wp_unslash( $_REQUEST['event_id'] ) : 0;
        $seat_id  = isset( $_REQUEST['seat_id'] ) ? (int) \wp_unslash( $_REQUEST['seat_id'] ) : 0;
        $this->guard( 'anchor_roster_cancel_' . $event_id );

        // REG-D48 — see handle_edit(): a nonce for one event cancels only its own seats.
        if ( ! self::seat_belongs_to_event( $seat_id, $event_id ) ) {
            $this->redirect( $event_id, 'error', \__( 'Seat not found.', 'anchor-schema' ) );
        }

        // The wp-admin row action only hides Cancel for cancelled/refunded, and a
        // stale page or a bookmarked cancel URL reaches it either way — so the
        // notice has to say which of the three things happened (audit REG-D37).
        $result = $this->registrations->update_status( $seat_id, Registrations::STATUS_CANCELLED, 'roster cancel', 'user:' . \get_current_user_id() );
        if ( $result->is_sent() ) {
            $this->redirect( $event_id, 'success', \__( 'Seat cancelled.', 'anchor-schema' ) );
        }
        if ( $result->is_skipped() ) {
            $this->redirect( $event_id, 'success', \__( 'This seat was already cancelled — nothing changed.', 'anchor-schema' ) );
        }
        $this->redirect( $event_id, 'error', \__( 'Could not cancel this seat.', 'anchor-schema' ) );
    }

    /* ---------------------------------------------------------------------
     * Manual access (spec §4.4)
     * ------------------------------------------------------------------- */

    public function handle_grant() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        $this->guard( 'anchor_roster_edit_' . $event_id );
        $seat_id = isset( $_POST['seat_id'] ) ? (int) \wp_unslash( $_POST['seat_id'] ) : 0;
        if ( ! self::seat_belongs_to_event( $seat_id, $event_id ) ) {
            $this->redirect( $event_id, 'error', \__( 'That seat is not on this event.', 'anchor-schema' ), 'invalid' );
        }
        $user_id = $this->module->entitlements->ensure_user( [ 'id' => $seat_id ] );
        if ( $user_id <= 0 ) {
            $this->redirect( $event_id, 'error', \__( 'That seat has no usable email address, so no account could be resolved.', 'anchor-schema' ), 'invalid' );
        }
        $this->module->entitlements->grant( $event_id, $user_id, 'manual' );
        $this->redirect( $event_id, 'success', \__( 'Access granted.', 'anchor-schema' ) );
    }

    public function handle_revoke() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        $this->guard( 'anchor_roster_edit_' . $event_id );
        // Resolve the user server-side from the seat, the same way
        // handle_grant() does — never trust a posted user_id. access_state()
        // can resolve a user by email fallback when the seat has no stored
        // _anchor_event_user_id, and the column then offers "Revoke" for a
        // user this form's posted user_id (always 0 in that case) cannot
        // reach; a raw 0 makes revoke_access() a silent no-op that still
        // reports success-shaped "did not hold access".
        $seat_id = isset( $_POST['seat_id'] ) ? (int) \wp_unslash( $_POST['seat_id'] ) : 0;
        if ( ! self::seat_belongs_to_event( $seat_id, $event_id ) ) {
            $this->redirect( $event_id, 'error', \__( 'That seat is not on this event.', 'anchor-schema' ), 'invalid' );
        }
        $seat    = (array) $this->registrations->get_seat( $seat_id );
        $user_id = (int) ( $seat['user_id'] ?? 0 );
        if ( $user_id <= 0 ) {
            $user    = \get_user_by( 'email', (string) ( $seat['email'] ?? '' ) );
            $user_id = $user ? (int) $user->ID : 0;
        }
        $result  = $this->revoke_access( $event_id, $user_id );
        $message = [
            'revoked'   => \__( 'Access revoked.', 'anchor-schema' ),
            'kept_seat' => \__( 'The manual grant was removed, but this person still holds a confirmed seat — so they keep access. Cancel the seat to remove it.', 'anchor-schema' ),
            'none'      => \__( 'That person did not hold access.', 'anchor-schema' ),
        ][ $result ];
        $this->redirect( $event_id, $result === 'none' ? 'error' : 'success', $message );
    }

    public function handle_add_access() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        $this->guard( 'anchor_roster_edit_' . $event_id );
        $name  = \sanitize_text_field( \wp_unslash( $_POST['access_name'] ?? '' ) );
        $email = \sanitize_email( \wp_unslash( $_POST['access_email'] ?? '' ) );
        if ( $email === '' ) {
            $this->redirect( $event_id, 'error', \__( 'An email address is required.', 'anchor-schema' ), 'invalid' );
        }
        $user_id = $this->add_access_by_email( $event_id, $name, $email );
        $this->redirect(
            $event_id,
            $user_id > 0 ? 'success' : 'error',
            $user_id > 0
                ? \__( 'Access granted. This person has no seat and does not count toward capacity.', 'anchor-schema' )
                : \__( 'Could not create or find an account for that address.', 'anchor-schema' )
        );
    }

    /** Send the organizer roster digest for a specific event (Task 4). */
    public function handle_send_roster() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        // Nonce, then capability, then the object — the same order every handler uses.
        \check_admin_referer( 'anchor_events_send_roster_' . $event_id );
        if ( ! self::current_user_can_manage() ) {
            \wp_die( \esc_html__( 'You do not have permission to do this.', 'anchor-schema' ) );
        }
        if ( \get_post_type( $event_id ) !== Module::CPT ) {
            \wp_die( \esc_html__( 'Invalid event.', 'anchor-schema' ) );
        }
        $result = $this->module->send_roster_email( $event_id );
        if ( $result->is_sent() ) {
            $this->redirect( $event_id, 'success', \__( 'Roster sent to organizer.', 'anchor-schema' ) );
        } elseif ( $result->is_skipped() ) {
            // Nothing was sent and nothing went wrong. The error channel would
            // send the operator to an error log with nothing in it, so this
            // goes to the ordinary notice channel and says which it is.
            $this->redirect( $event_id, 'success', \__( 'Roster not sent — the roster email is switched off for this event.', 'anchor-schema' ) );
        } else {
            $this->redirect( $event_id, 'error', \__( 'Roster could not be sent — check the error log.', 'anchor-schema' ) );
        }
    }

    /**
     * Nonce + capability gate shared by every manual action.
     *
     * Both checks always run, in that order: the request has to be one this site
     * issued (CSRF) before we say anything about who the user is, and the
     * capability is the module-wide one, never a per-handler guess.
     */
    private function guard( $nonce_action ) {
        \check_admin_referer( $nonce_action );
        if ( ! self::current_user_can_manage() ) {
            \wp_die( \esc_html__( 'Unauthorized', 'anchor-schema' ) );
        }
    }

    /**
     * Send the operator back with a notice — and, for a refusal, with the CODE
     * that says which refusal it was (audit REG-D39/D34).
     *
     * "Could not add attendee" used to be the single answer to three different
     * situations: the registration window has closed, the seats are gone, and
     * the form was not filled in properly. They need different actions from the
     * operator, so they now carry different codes:
     *
     *   closed  — the registration window has passed, or the event is cancelled
     *             or hand-flagged sold out; tick "Allow over capacity" to add
     *             anyway.
     *   full    — capacity (or the tier quota) is reached and no waitlist is
     *             running; tick the override, or turn on the waitlist.
     *   invalid — the submission itself is unusable (no name, an unusable email,
     *             a required question left blank). Nothing to override.
     *
     * Task 42 — $event_id here is whatever the acted-on FORM posted, which
     * for a seat on a group child is the CHILD's id. When the action was
     * taken from a TAB of the parent's console (the return URL already names
     * the parent as `event_id`, or already carries `occurrence=`), the
     * redirect is pinned to `event_id=<parent>&occurrence=<child>` — plain
     * `event_id=<child>` would silently swap the tabbed parent page for the
     * child's own single-event roster right after an action that was
     * supposed to return to a tab of it. But when the action was taken from
     * the CHILD's OWN Attendees page instead (e.g. a direct link from the
     * events list — no tabs, no `occurrence=` in sight), the redirect stays
     * on that child's own page: nothing about where the manager came from
     * asked to be bounced to the parent (review finding — this used to remap
     * unconditionally whenever $event_id was a group child, regardless of
     * which page the action was actually taken from).
     *
     * @param int    $event_id
     * @param string $type    'success' | 'error'.
     * @param string $message Human notice.
     * @param string $code    Machine refusal code, '' for success.
     */
    private function redirect( $event_id, $type, $message, $code = '' ) {
        $event_id = (int) $event_id;
        $args     = [
            'roster_msg'  => \rawurlencode( $message ),
            'roster_type' => ( $type === 'error' ? 'error' : 'success' ),
        ];
        if ( $code !== '' ) {
            $args['roster_code'] = $code;
        }

        // The front-end console posts the page it wants to come back to. Anything
        // off-site is dropped by wp_validate_redirect() and we fall back to the
        // wp-admin roster screen, so both surfaces land where the action started.
        $return = isset( $_REQUEST['roster_return'] )
            ? \esc_url_raw( \rawurldecode( \wp_unslash( $_REQUEST['roster_return'] ) ) )
            : '';
        if ( $return !== '' ) {
            $return = (string) \wp_validate_redirect( $return, '' );
        }

        // Task 42 — resolve the top-level page id (page_id) and, when the
        // acted-on event is a group child, the tab to land on (occurrence).
        //
        // Review finding — this used to remap UNCONDITIONALLY whenever
        // $event_id was a group child, which bounced a manager who opened a
        // CHILD's own Attendees page directly (e.g. a direct link from the
        // events list — render_frontend_single(), no tabs, `?event_id=
        // <child>` and no `occurrence=`) over to the parent's tabbed page the
        // instant they added/edited/cancelled a seat, even though nothing
        // about where they came from asked for that. The remap is only
        // correct when the return URL ITSELF already says "this was a tab of
        // the parent" — it already names the parent as `event_id`, or it
        // already carries an `occurrence=` tab param — never merely because
        // the acted-on event happens to be a child.
        $page_id    = $event_id;
        $occurrence = 0;
        if ( $return !== '' && $this->module->occurrences && $this->module->occurrences->is_group_child( $event_id ) ) {
            $parent = $this->module->occurrences->parent_of( $event_id );
            if ( $parent > 0 ) {
                $return_args = [];
                \wp_parse_str( (string) \wp_parse_url( $return, PHP_URL_QUERY ), $return_args );
                $return_names_parent = ( isset( $return_args['event_id'] ) && (int) $return_args['event_id'] === $parent )
                    || isset( $return_args['occurrence'] );
                if ( $return_names_parent ) {
                    $page_id    = $parent;
                    $occurrence = $event_id;
                }
            }
        }

        if ( $return !== '' ) {
            // Pin the view back to this event's roster. A return URL that lost its
            // event_id (or pointed at the console's list) would otherwise drop the
            // user on a roster with nothing selected right after they acted on one.
            $args = \array_merge( $args, [
                'event_action' => 'roster',
                'event_id'     => $page_id,
            ] );
            $remove = [ 'roster_msg', 'roster_type', 'roster_code', 'seat_id' ];
            if ( $occurrence > 0 ) {
                $args['occurrence'] = $occurrence;
            } else {
                // A single-event action never carries a tab — drop a stale
                // one rather than let it point at a page that has none.
                $remove[] = 'occurrence';
            }
            $url = \add_query_arg( $args, \remove_query_arg( $remove, $return ) );
        } else {
            // The wp-admin roster screen has no tabs (out of scope for Task
            // 42) — it always addresses the acted-on event directly.
            $url = $this->roster_url( $event_id, $args );
        }

        // Kept from when roster_url() ended in wp_nonce_url(), which HTML-escapes
        // its result for use in a link — the separators came back as `&amp;`, and
        // in a Location header that is not a separator at all: every argument after
        // the first arrived as `amp;roster_msg` and maybe_render_notice() (which
        // reads $_GET['roster_msg']) never fired. roster_url() no longer escapes,
        // but $return comes from the request, so the decode still earns its place.
        \wp_safe_redirect( \wp_specialchars_decode( $url, ENT_QUOTES ) );
        exit;
    }

    /**
     * The redirect notice a seat action left behind, or null when there is
     * none. REG-D56 — one reader for the `roster_msg`/`roster_type` pair; the
     * admin screen and the console each render it in their own markup.
     *
     * @return array{message:string,type:string}|null
     */
    private function notice_parts() {
        if ( empty( $_GET['roster_msg'] ) ) {
            return null;
        }
        $msg = \sanitize_text_field( \rawurldecode( \wp_unslash( $_GET['roster_msg'] ) ) );
        if ( $msg === '' ) {
            return null;
        }
        return [
            'message' => $msg,
            'type'    => ( isset( $_GET['roster_type'] ) && \wp_unslash( $_GET['roster_type'] ) === 'error' ) ? 'error' : 'success',
        ];
    }

    private function maybe_render_notice() {
        $notice = $this->notice_parts();
        if ( null === $notice ) {
            return;
        }
        echo '<div class="notice notice-' . ( $notice['type'] === 'error' ? 'error' : 'success' ) . ' is-dismissible"><p>'
            . \esc_html( $notice['message'] ) . '</p></div>';
    }

    /* ---------------------------------------------------------------------
     * CSV export (re-homed from Module — spec §10.4)
     * ------------------------------------------------------------------- */

    public function handle_export() {
        // NOTE: `anchor_event_export` is a GLOBAL nonce — it says the request came
        // from this site, not which event it may read. Nothing else scopes the id,
        // so is_exportable_event() below is the whole of that check (REG-D16).
        \check_admin_referer( 'anchor_event_export' );
        if ( ! self::current_user_can_manage() ) {
            \wp_die( \esc_html__( 'Unauthorized', 'anchor-schema' ) );
        }

        $event_id = isset( $_GET['event_id'] ) ? (int) \wp_unslash( $_GET['event_id'] ) : 0;
        // REG-D16 — a non-zero id is not enough: seats are matched on a meta value,
        // so a trashed, deleted, or entirely unrelated post id would otherwise
        // return a CSV of real attendee names and emails named after that id.
        if ( ! self::is_exportable_event( $event_id ) ) {
            \wp_die( \esc_html__( 'Invalid event.', 'anchor-schema' ) );
        }
        $scope = isset( $_GET['scope'] ) ? \sanitize_key( \wp_unslash( $_GET['scope'] ) ) : 'all';
        if ( ! \in_array( $scope, [ 'active', 'access' ], true ) ) {
            $scope = 'all';
        }
        // `access` is a bookmarkable URL (spec §4.4), and access_role_enabled
        // can be switched off after the link was made — fall back to the
        // confirmed-only scope rather than erroring on a stale bookmark.
        if ( $scope === 'access' && ! $this->access_enabled( $event_id ) ) {
            $scope = 'active';
        }

        // Task 42 — the page-level "All dates" export on a group parent's
        // console. Same nonce -> capability -> is_exportable_event(parent)
        // gate as every other export; `occurrences=all` only takes effect
        // when the id it was validated against is actually a group parent,
        // so the arg is inert (falls through to the ordinary single-event
        // export below, exactly as it always has) on every other event.
        $all_dates = isset( $_GET['occurrences'] ) && \wp_unslash( $_GET['occurrences'] ) === 'all';
        if ( $all_dates && $this->module->occurrences && $this->module->occurrences->is_group_parent( $event_id ) ) {
            $table = $this->export_table_all_dates( $event_id, $scope );
            $this->stream_csv( 'event-roster-' . $event_id . '-all-dates-' . $scope . '-' . \gmdate( 'Ymd' ) . '.csv', $table );
        }

        $table = $this->export_table( $event_id, $scope );
        $this->stream_csv( 'event-roster-' . $event_id . '-' . $scope . '-' . \gmdate( 'Ymd' ) . '.csv', $table );
    }

    /**
     * The base (non-question) CSV columns, shared by a single event's export
     * and the Task 42 all-dates export (which prepends its own leading Date
     * column to this same list).
     *
     * @return string[]
     */
    private function export_columns() {
        return [
            \__( 'Seat ID', 'anchor-schema' ),
            \__( 'Event', 'anchor-schema' ),
            \__( 'Attendee Name', 'anchor-schema' ),
            \__( 'Email', 'anchor-schema' ),
            \__( 'Phone', 'anchor-schema' ),
            \__( 'Status', 'anchor-schema' ),
            \__( 'Source', 'anchor-schema' ),
            \__( 'Ticket Type', 'anchor-schema' ),
            \__( 'Guests', 'anchor-schema' ),
            \__( 'Party Size', 'anchor-schema' ),
            \__( 'Registration Date', 'anchor-schema' ),
            \__( 'Order #', 'anchor-schema' ),
            \__( 'Order ID', 'anchor-schema' ),
            \__( 'Order Status', 'anchor-schema' ),
            \__( 'Order Date', 'anchor-schema' ),
            \__( 'Customer ID', 'anchor-schema' ),
            \__( 'Customer Email', 'anchor-schema' ),
            \__( 'Product', 'anchor-schema' ),
            \__( 'Product ID', 'anchor-schema' ),
            \__( 'Variation ID', 'anchor-schema' ),
            \__( 'Order Item ID', 'anchor-schema' ),
            \__( 'Seat Index', 'anchor-schema' ),
        ];
    }

    /**
     * One get_export_rows() row, as the CSV cell array export_columns()
     * describes plus one cell per key in $field_keys (in that order) — the
     * one place a row becomes a CSV line, shared by export_table() and
     * export_table_all_dates() so the two can never describe a row
     * differently.
     *
     * @param int   $event_id   The event this row's tier id belongs to (a
     *                          group child in the all-dates case).
     * @param array $row        One row from Registrations::get_export_rows().
     * @param array $field_keys Question keys, in header order.
     * @return array
     */
    private function export_row_cells( $event_id, array $row, array $field_keys ) {
        // Tier label: get_export_rows() rows don't carry the tier id, so resolve
        // it from the seat meta (falls back to Primary for legacy seats). A
        // manual-access row (export `access` scope) has no seat at all — no
        // seat_id means no tier to look up, not a "Primary (retired tier)".
        $seat_id    = (int) ( $row['seat_id'] ?? 0 );
        $tier_label = $seat_id > 0
            ? $this->tier_label( $event_id, (string) \get_post_meta( $seat_id, '_anchor_event_ticket_type_id', true ) )
            : '';

        // `?? ''` on every column (not just seat_id/tier above): a manual-access
        // row is built with only a handful of keys set (name/email/status/
        // source/fields), and a sibling branch will add a "Registration code"
        // column right after Seat Index (export_columns()) — this is the one
        // place a row becomes cells, so that column only ever needs a `?? ''`
        // here, never a second row-shape to keep in step.
        $cells = [
            $row['seat_id'] ?? '', $row['event'] ?? '', $row['name'] ?? '', $row['email'] ?? '', $row['phone'] ?? '',
            $row['status'] ?? '', $row['source'] ?? '', $tier_label, $row['guests'] ?? '', $row['party_size'] ?? '', $row['reg_date'] ?? '',
            $row['order_number'] ?? '', $row['order_id'] ?? '', $row['order_status'] ?? '', $row['order_date'] ?? '',
            $row['customer_id'] ?? '', $row['customer_email'] ?? '', $row['product'] ?? '', $row['product_id'] ?? '',
            $row['variation_id'] ?? '', $row['order_item_id'] ?? '', $row['seat_index'] ?? '',
        ];
        foreach ( $field_keys as $k ) {
            $cells[] = isset( $row['fields'][ $k ] ) ? $row['fields'][ $k ] : '';
        }
        return $cells;
    }

    /**
     * User ids that hold this event's role by a MANUAL grant (spec §4.4) —
     * role_members() only counts; the Access column's Grant/Revoke actions
     * work seat-by-seat so they never needed the list, but the export
     * `access` scope does.
     *
     * @param int $event_id
     * @return int[]
     */
    private function manual_access_user_ids( $event_id ) {
        $ent = $this->module->entitlements;
        if ( ! $ent ) {
            return [];
        }
        $slug = $ent->role_for( (int) $event_id, false );
        if ( $slug === '' ) {
            return [];
        }
        $ids   = [];
        $query = new \WP_User_Query( [ 'role' => $slug, 'fields' => 'ID', 'number' => 0 ] );
        foreach ( (array) $query->get_results() as $user_id ) {
            $user_id = (int) $user_id;
            if ( ( $ent->grant_record( (int) $event_id, $user_id )['source'] ?? '' ) === 'manual' ) {
                $ids[] = $user_id;
            }
        }
        return $ids;
    }

    /**
     * One synthetic export row per manual-access holder not already
     * represented among $existing_rows (matched by email — a holder who
     * ALSO has a confirmed seat already has a row from that seat, and must
     * not appear twice). Seat ID blank, Source "manual access", Status
     * "access only" — the one place those three literals are set, so the
     * single-event and all-dates exports can never describe an access-only
     * row differently.
     *
     * @param int   $event_id
     * @param array $existing_rows Raw get_export_rows() rows already gathered
     *                             for this event, so a dual-entitled holder
     *                             is not double-counted.
     * @return array[] Raw row shape — cells are cut by export_row_cells().
     */
    private function manual_access_export_rows( $event_id, array $existing_rows ) {
        $seen = [];
        foreach ( $existing_rows as $row ) {
            $email = \strtolower( \trim( (string) ( $row['email'] ?? '' ) ) );
            if ( $email !== '' ) {
                $seen[ $email ] = true;
            }
        }

        $rows = [];
        foreach ( $this->manual_access_user_ids( $event_id ) as $user_id ) {
            $user = \get_userdata( $user_id );
            if ( ! $user instanceof \WP_User || isset( $seen[ \strtolower( (string) $user->user_email ) ] ) ) {
                continue;
            }
            $rows[] = [
                'event'  => \get_the_title( $event_id ),
                'name'   => $user->display_name,
                'email'  => $user->user_email,
                'status' => \__( 'access only', 'anchor-schema' ),
                'source' => \__( 'manual access', 'anchor-schema' ),
                'fields' => [],
            ];
        }
        return $rows;
    }

    /**
     * Registrations::get_export_rows() plus, for the `access` scope, one
     * synthetic row per manual-access holder — the ONE place scope becomes
     * rows, shared by export_table() and export_table_all_dates() so the
     * `access` scope can never behave differently between a single event and
     * the all-dates export.
     *
     * `access` always sources its seat rows from `active` (confirmed only):
     * a comp export next to pending/waitlisted/cancelled seats would answer
     * a different question than "who currently has access".
     *
     * @param int    $event_id
     * @param string $scope 'all' | 'active' | 'access'.
     * @return array{field_keys:string[],rows:array[]}
     */
    private function export_rows_for_scope( $event_id, $scope ) {
        $event_id   = (int) $event_id;
        $seat_scope = ( $scope === 'access' ) ? 'active' : $scope;
        $data       = $this->registrations->get_export_rows( $event_id, $seat_scope );
        if ( $scope === 'access' ) {
            $data['rows'] = \array_merge( $data['rows'], $this->manual_access_export_rows( $event_id, $data['rows'] ) );
        }
        return $data;
    }

    /**
     * Header + data rows for one event's CSV export (spec §10.4).
     *
     * @param int    $event_id
     * @param string $scope 'all' | 'active' | 'access'.
     * @return array{header:string[],rows:array<int,array>}
     */
    private function export_table( $event_id, $scope ) {
        $event_id = (int) $event_id;
        $data     = $this->export_rows_for_scope( $event_id, $scope );

        // The answer columns are keyed by question id; the heading is resolved
        // from the event's current questions, falling back to the stored key for
        // a question that has since been deleted (REG-D10).
        $question_headings = [];
        foreach ( $data['field_keys'] as $k ) {
            $question_headings[] = $this->question_label( $event_id, $k );
        }

        $rows = [];
        foreach ( $data['rows'] as $row ) {
            $rows[] = $this->export_row_cells( $event_id, $row, $data['field_keys'] );
        }

        return [
            'header' => \array_merge( $this->export_columns(), $question_headings ),
            'rows'   => $rows,
        ];
    }

    /**
     * Test seam for export_table() (Task 18) — WP_UnitTestCase can call this
     * without reflection.
     *
     * @param int    $event_id
     * @param string $scope
     * @return array{header:string[],rows:array<int,array>}
     */
    public function export_table_public( $event_id, $scope ) {
        return $this->export_table( (int) $event_id, (string) $scope );
    }

    /**
     * Header + data rows for the "All dates" export on a group parent
     * (Task 42): one leading Date column carrying each child's own
     * occurrence label, the base columns unchanged, question columns
     * unioned across every child (the FIRST child a key is seen on resolves
     * its heading label — an authoring gap, not a correctness one, since a
     * deleted-question fallback already exists per-child), rows grouped by
     * child in date order (Occurrences::children($parent, true) — closed/
     * cancelled children included, their seats still exist) and, within a
     * child, in get_export_rows()'s own order.
     *
     * One export_rows_for_scope() call per child, one row-cell builder
     * (export_row_cells()) — never a second export implementation. Task 18 —
     * the `access` scope is per-child too: each occurrence child mints its
     * own event role, so export_rows_for_scope() appends that CHILD's own
     * manual-access holders, keeping this in step with export_table()
     * without a second copy of manual_access_export_rows().
     *
     * Task 42 review — children_any_status(), not children($parent, true):
     * the latter is publish-only, so a child an admin unpublished
     * (draft/pending/private) but which still holds seats would silently
     * vanish from this export.
     *
     * @param int    $parent_id
     * @param string $scope 'all' | 'active' | 'access'.
     * @return array{header:string[],rows:array<int,array>}
     */
    private function export_table_all_dates( $parent_id, $scope ) {
        $parent_id = (int) $parent_id;
        $children  = $this->module->occurrences ? $this->module->occurrences->children_any_status( $parent_id ) : [];

        $field_owner = []; // question key => the child id whose label resolves the heading.
        $per_child   = [];
        foreach ( $children as $child_id ) {
            $child_id                = (int) $child_id;
            $data                    = $this->export_rows_for_scope( $child_id, $scope );
            $per_child[ $child_id ]  = $data;
            foreach ( $data['field_keys'] as $k ) {
                if ( ! isset( $field_owner[ $k ] ) ) {
                    $field_owner[ $k ] = $child_id;
                }
            }
        }

        $field_keys        = \array_keys( $field_owner );
        $question_headings = [];
        foreach ( $field_keys as $k ) {
            $question_headings[] = $this->question_label( $field_owner[ $k ], $k );
        }
        $header = \array_merge( [ \__( 'Date', 'anchor-schema' ) ], $this->export_columns(), $question_headings );

        $rows = [];
        foreach ( $children as $child_id ) {
            $child_id = (int) $child_id;
            $label    = $this->module->occurrence_label( $child_id, $this->module->get_meta( $child_id ) );
            foreach ( $per_child[ $child_id ]['rows'] as $row ) {
                $cells = $this->export_row_cells( $child_id, $row, $field_keys );
                \array_unshift( $cells, $label );
                $rows[] = $cells;
            }
        }

        return [ 'header' => $header, 'rows' => $rows ];
    }

    /**
     * The one CSV writer (spec §10.4 / Task 42): headers, formula-injection
     * hardened cells (csv_safe()), then exit. Both export_table() and
     * export_table_all_dates() build the same {header,rows} shape and hand
     * it here — this is the only place that opens `php://output`.
     *
     * @param string $filename
     * @param array{header:string[],rows:array<int,array>} $table
     * @return void Exits.
     */
    private function stream_csv( $filename, array $table ) {
        \nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, \array_map( [ $this, 'csv_safe' ], $table['header'] ) );
        foreach ( $table['rows'] as $cells ) {
            fputcsv( $out, \array_map( [ $this, 'csv_safe' ], $cells ) );
        }
        fclose( $out );
        exit;
    }

    /**
     * Formula-injection hardening: prefix a leading apostrophe when a cell starts
     * with a character a spreadsheet would interpret as a formula (spec §10.4).
     *
     * @param mixed $v
     * @return string
     */
    public function csv_safe( $v ) {
        $v = (string) $v;
        if ( $v === '' ) {
            return $v;
        }
        $triggers = [ '=', '+', '-', '@', "\t", "\r", "\n" ];
        // Catch a formula behind leading whitespace too (some apps strip it before
        // evaluating), not just at the very first byte (CodeRabbit).
        $trimmed = \ltrim( $v );
        if (
            \in_array( $v[0], $triggers, true )
            || ( $trimmed !== '' && \in_array( $trimmed[0], $triggers, true ) )
        ) {
            return "'" . $v;
        }
        return $v;
    }

    /* ---------------------------------------------------------------------
     * Shared helpers (used by the list table too)
     * ------------------------------------------------------------------- */

    /**
     * The roster list table's columns, base + this event's own questions +
     * (Task 18) Access, when the event is in the feature. The one place this
     * set is built, so Roster_List_Table::get_columns() and the
     * list_table_columns_public() test seam can never describe it differently.
     *
     * @param int $event_id
     * @return array<string,string> column id => label.
     */
    public function list_table_columns( $event_id ) {
        $event_id = (int) $event_id;
        $columns  = [
            'attendee' => \__( 'Attendee', 'anchor-schema' ),
            'email'    => \__( 'Email', 'anchor-schema' ),
            'phone'    => \__( 'Phone', 'anchor-schema' ),
            'status'   => \__( 'Status', 'anchor-schema' ),
            'tier'     => \__( 'Tier', 'anchor-schema' ),
            'guests'   => \__( 'Guests', 'anchor-schema' ),
            'source'   => \__( 'Source', 'anchor-schema' ),
            'order'    => \__( 'Order', 'anchor-schema' ),
            'seat'     => \__( 'Seat', 'anchor-schema' ),
            'date'     => \__( 'Date', 'anchor-schema' ),
        ];
        foreach ( $this->module_questions( $event_id ) as $q ) {
            $columns[ 'q_' . $q['key'] ] = $q['label'];
        }
        // A plain event has no access to report. Omitting the column beats
        // printing a column of "off": the roster of an in-person event should
        // look exactly as it does today, and an operator should never be
        // shown a Grant button for an event with nothing to grant into.
        if ( $this->access_enabled( $event_id ) ) {
            $columns['access'] = \__( 'Access', 'anchor-schema' );
        }
        return $columns;
    }

    /** Test seam for list_table_columns() (Task 18). */
    public function list_table_columns_public( $event_id ) {
        return $this->list_table_columns( $event_id );
    }

    /** Status options for the edit select. */
    /** Questions for an event — lets the inner list-table class reach the module. */
    public function module_questions( $event_id ) {
        return \method_exists( $this->module, 'get_registration_questions' )
            ? $this->module->get_registration_questions( (int) $event_id )
            : [];
    }

    /** Display heading for a stored answer key — delegates to the one resolver. */
    public function question_label( $event_id, $key ) {
        return \method_exists( $this->module, 'registration_answer_label' )
            ? $this->module->registration_answer_label( (int) $event_id, $key )
            : (string) $key;
    }

    /**
     * The status <select> offered by the roster edit form and the front-end
     * console, and the label map status_label() reads.
     *
     * REG-D33 — built FROM Registrations::STATUSES rather than re-listed here.
     * The two lists used to be maintained separately, and a seat whose status
     * was not among the options got silently rewritten to the first option on
     * save: the browser selects option one when nothing matches, and "Save
     * seat" then posted `confirmed` without the operator choosing it. A status
     * the model accepts but this map has no label for still gets an option,
     * labelled from its own key, so the drift can no longer eat a value.
     */
    public function status_options() {
        $labels  = $this->status_labels();
        $options = [];
        foreach ( Registrations::STATUSES as $status ) {
            $options[ $status ] = $labels[ $status ] ?? \ucfirst( \str_replace( '_', ' ', (string) $status ) );
        }
        return $options;
    }

    /**
     * The options the edit form shows for a seat CURRENTLY holding $status.
     *
     * status_options() alone would re-open the hole REG-D33 closed for the
     * read-only legacy statuses: a seat stored as `attended` matches no option,
     * the browser selects the first one, and "Save seat" posts `confirmed`
     * without the operator choosing it. So a held status that is not offered is
     * added — first, and only for that seat — which makes it the selected
     * option and leaves the operator to pick the move deliberately.
     *
     * @param string $status The seat's stored status.
     * @return array<string,string>
     */
    public function status_options_for( $status ) {
        $options = $this->status_options();
        $status  = (string) $status;
        if ( $status !== '' && ! isset( $options[ $status ] ) ) {
            $options = [ $status => $this->status_label( $status ) ] + $options;
        }
        return $options;
    }

    /**
     * Label for every status a seat may HOLD, read-only legacy included.
     *
     * Separate from status_options() because the two answer different
     * questions: this one names what a seat IS (so a legacy `attended` seat
     * shows as "Attended" in the roster and the summary), while the options
     * list names what an operator may SET, and the legacy statuses are not on
     * that list.
     *
     * @return array<string,string>
     */
    private function status_labels() {
        return [
            Registrations::STATUS_CONFIRMED => \__( 'Confirmed', 'anchor-schema' ),
            Registrations::STATUS_PENDING   => \__( 'Pending', 'anchor-schema' ),
            Registrations::STATUS_WAITLIST  => \__( 'Waitlist', 'anchor-schema' ),
            Registrations::STATUS_CANCELLED => \__( 'Cancelled', 'anchor-schema' ),
            Registrations::STATUS_REFUNDED  => \__( 'Refunded', 'anchor-schema' ),
            Registrations::STATUS_FAILED    => \__( 'Failed', 'anchor-schema' ),
            Registrations::STATUS_ATTENDED  => \__( 'Attended', 'anchor-schema' ),
            Registrations::STATUS_NO_SHOW   => \__( 'No show', 'anchor-schema' ),
        ];
    }

    /** Human label for a status. */
    public function status_label( $status ) {
        $labels = $this->status_labels();
        return $labels[ $status ] ?? \ucfirst( \str_replace( '_', ' ', (string) $status ) );
    }

    /** Background colour for a status pill. */
    public function status_color( $status ) {
        switch ( $status ) {
            case Registrations::STATUS_CONFIRMED: return '#1a7f37';
            case Registrations::STATUS_PENDING:   return '#bf8700';
            case Registrations::STATUS_WAITLIST:  return '#0073aa';
            case Registrations::STATUS_REFUNDED:  return '#8250df';
            case Registrations::STATUS_FAILED:    return '#d63638';
            case Registrations::STATUS_CANCELLED: return '#646970';
            default:                              return '#646970';
        }
    }

    /** Edit-screen URL for an order (HPOS-aware), guarded for non-WC environments. */
    public function order_link( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return '';
        }
        if ( $this->module->woocommerce && \method_exists( $this->module->woocommerce, 'order_edit_url' ) ) {
            return $this->module->woocommerce->order_edit_url( $order_id );
        }
        return (string) \get_edit_post_link( $order_id );
    }

    /** Nonced cancel-link for a seat row. */
    public function cancel_url( $event_id, $seat_id ) {
        $url = \add_query_arg( [
            'action'   => 'anchor_roster_cancel',
            'event_id' => (int) $event_id,
            'seat_id'  => (int) $seat_id,
        ], \admin_url( 'admin-post.php' ) );
        return \wp_nonce_url( $url, 'anchor_roster_cancel_' . (int) $event_id );
    }

    private function render_status_pill_styles() {
        echo '<style>.anchor-roster-pill{display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-size:11px;line-height:1.6;}</style>';
    }

    /* ---------------------------------------------------------------------
     * Front-end surface (the [event_manager] "roster" view)
     * ------------------------------------------------------------------- */

    /**
     * Attendee console for one event, rendered outside wp-admin.
     *
     * Deliberately a second *view*, never a second implementation: the counts
     * come from Registrations, the mutations post to the same
     * anchor_roster_add / _edit / _cancel handlers with the same nonces, and the
     * export reuses anchor_event_export. Only the markup differs — WP_List_Table
     * is an admin-only class, so the seat table is rendered directly here.
     *
     * Task 42 — a group parent (offering/recurring) is a container: it has no
     * seats of its own and Roster::handle_add() already refuses to add one to
     * it, so its console page is not "one roster" but a tab per child date,
     * dispatched to render_frontend_group() below. Everything else (a single
     * event, a multisession series — one registration covers every session,
     * so one roster — or a group CHILD visited directly) is is_group_parent()
     * = false and keeps exactly today's single-roster page.
     *
     * @param int    $event_id
     * @param string $return_url Front-end page to come back to after an action.
     * @return string
     */
    public function render_frontend( $event_id, $return_url ) {
        $event_id = (int) $event_id;
        if ( ! self::current_user_can_manage() ) {
            return '<p>' . \esc_html__( 'You do not have permission to view attendees.', 'anchor-schema' ) . '</p>';
        }
        if ( \get_post_type( $event_id ) !== Module::CPT ) {
            return '<p>' . \esc_html__( 'Event not found.', 'anchor-schema' ) . '</p>';
        }

        if ( $this->module->occurrences && $this->module->occurrences->is_group_parent( $event_id ) ) {
            return $this->render_frontend_group( $event_id, $return_url );
        }

        return $this->render_frontend_single( $event_id, $return_url );
    }

    /**
     * The console page for one bookable event id — a single event, a
     * multisession series, or a group child visited directly. This is the
     * exact markup render_frontend() always produced; kept as its own method
     * (Task 42) so it wraps render_roster_panel() the same way
     * render_frontend_group() wraps one per child, instead of the page-level
     * chrome (title/back-link/notice) being duplicated between the two.
     *
     * @param int    $event_id
     * @param string $return_url
     * @return string
     */
    private function render_frontend_single( $event_id, $return_url ) {
        $event_id   = (int) $event_id;
        $return_url = $return_url ?: \home_url();
        $list_url   = \remove_query_arg( [ 'event_action', 'event_id', 'seat_id', 'roster_msg', 'roster_type', 'occurrence' ], $return_url );
        $self_url   = \add_query_arg( [ 'event_action' => 'roster', 'event_id' => $event_id ], $list_url );

        \ob_start();
        ?>
        <div class="anchor-roster-fe">

            <div class="anchor-event-manager-toolbar">
                <h2><?php echo \esc_html( \get_the_title( $event_id ) ); ?> — <?php \esc_html_e( 'Attendees', 'anchor-schema' ); ?></h2>
                <a class="anchor-event-button-secondary" href="<?php echo \esc_url( $list_url ); ?>"><?php \esc_html_e( 'Back to list', 'anchor-schema' ); ?></a>
            </div>

            <?php echo $this->frontend_notice(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>

            <?php echo $this->render_roster_panel( $event_id, $self_url, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /**
     * The tabbed console page for a group PARENT (Task 42): one tab per
     * child occurrence — INCLUDING one an admin unpublished (draft/pending/
     * private) or soft-closed, since its seats still exist and still need
     * managing (Occurrences::children_any_status(); see that method's
     * docblock — children($parent, true) is publish-only and review found a
     * draft/pending/private child with seats silently invisible here), plus
     * a page-level "All dates" export.
     *
     * Every tab is a real `<a href="...&occurrence=<child>">` (CodeRabbit
     * review) — switching tabs is a full page load, which is what keeps the
     * URL always naming the selected date and needs no JS at all. Only the
     * ACTIVE occurrence gets a panel at all: a single `role="tabpanel"`
     * section rendered via render_roster_panel() (same summary, same
     * add/edit form posting to the CHILD id, same Registered list, same
     * per-event export links, a single event's console has always shown).
     * Every OTHER tab contributes nothing but its tab link — no section, no
     * summary, no add form — so the page begins on whichever date was
     * actually selected instead of stacking every child's panel in date
     * order (site-owner-reported bug: the page always opened on the
     * chronologically-first child's roster regardless of which tab was
     * clicked). This also keeps a recurring parent with upwards of a
     * hundred children to exactly one seat table per page load, now
     * trivially true since only one panel exists.
     *
     * The parent itself never gets an add-form or a summary: it has no seats
     * of its own (Roster::handle_add()'s is_group_parent() refusal), so
     * there is nothing to show for the container beyond the title and the
     * tabs.
     *
     * @param int    $parent_id
     * @param string $return_url
     * @return string
     */
    private function render_frontend_group( $parent_id, $return_url ) {
        $parent_id  = (int) $parent_id;
        $return_url = $return_url ?: \home_url();
        $list_url   = \remove_query_arg( [ 'event_action', 'event_id', 'seat_id', 'roster_msg', 'roster_type', 'occurrence' ], $return_url );
        $page_url   = \add_query_arg( [ 'event_action' => 'roster', 'event_id' => $parent_id ], $list_url );

        $children = $this->module->occurrences ? $this->module->occurrences->children_any_status( $parent_id ) : [];

        // ?seat_id= is a single global query var; scope its "not found"
        // notice to the page level (once) rather than to every panel — every
        // OTHER child would otherwise also claim the seat is missing, since
        // it does not belong to them either (REG-D48 still decides ownership
        // per child inside render_roster_panel(), this only decides who gets
        // to print the warning).
        $seat_id    = isset( $_GET['seat_id'] ) ? (int) \wp_unslash( $_GET['seat_id'] ) : 0;
        $seat_owner = 0;
        if ( $seat_id > 0 ) {
            foreach ( $children as $cid ) {
                if ( self::seat_belongs_to_event( $seat_id, $cid ) ) {
                    $seat_owner = (int) $cid;
                    break;
                }
            }
        }

        $requested = isset( $_GET['occurrence'] ) ? (int) \wp_unslash( $_GET['occurrence'] ) : 0;
        if ( $seat_owner > 0 ) {
            // The seat's own tab always wins, so the edit form it opened is
            // the one a reader actually sees.
            $active = $seat_owner;
        } elseif ( \in_array( $requested, $children, true ) ) {
            $active = $requested;
        } else {
            $active = isset( $children[0] ) ? (int) $children[0] : 0;
        }

        \ob_start();
        ?>
        <div class="anchor-roster-fe anchor-roster-fe-group">

            <div class="anchor-event-manager-toolbar">
                <h2><?php echo \esc_html( \get_the_title( $parent_id ) ); ?> — <?php \esc_html_e( 'Attendees', 'anchor-schema' ); ?></h2>
                <a class="anchor-event-button-secondary" href="<?php echo \esc_url( $list_url ); ?>"><?php \esc_html_e( 'Back to list', 'anchor-schema' ); ?></a>
            </div>

            <?php echo $this->frontend_notice(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>

            <?php if ( $seat_id > 0 && 0 === $seat_owner ) : ?>
                <p class="anchor-roster-fe-warn"><?php \esc_html_e( 'Seat not found.', 'anchor-schema' ); ?></p>
            <?php endif; ?>

            <?php if ( empty( $children ) ) : ?>
                <p class="anchor-roster-fe-empty"><?php \esc_html_e( 'No dates currently scheduled.', 'anchor-schema' ); ?></p>
            <?php else : ?>
                <div class="anchor-roster-fe-tabs">
                    <div class="anchor-roster-fe-tabbar">
                    <div class="anchor-roster-fe-tablist" role="tablist">
                        <?php foreach ( $children as $child_id ) :
                            $child_id  = (int) $child_id;
                            $is_active = $child_id === $active;
                            $label     = $this->module->occurrence_label( $child_id, $this->module->get_meta( $child_id ) );
                            $badge     = $this->child_badge( $child_id );
                            $tab_id    = 'anchor-roster-tab-' . $child_id;
                            $panel_id  = 'anchor-roster-panel-' . $child_id;
                            // CodeRabbit review — a real link, not a JS-only
                            // button: switching tabs is a full page load that
                            // re-renders with `?occurrence=<child>`, which is
                            // also what keeps the URL always naming the
                            // selected date, with or without JS.
                            $tab_url   = \add_query_arg( 'occurrence', $child_id, $page_url );
                        ?>
                            <a role="tab"
                                id="<?php echo \esc_attr( $tab_id ); ?>"
                                href="<?php echo \esc_url( $tab_url ); ?>"
                                aria-controls="<?php echo \esc_attr( $panel_id ); ?>"
                                aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                                class="anchor-roster-fe-tab<?php echo $is_active ? ' is-active' : ''; ?>"
                            ><?php echo \esc_html( $label ); ?><?php if ( '' !== $badge ) : ?> <span class="anchor-roster-fe-badge anchor-roster-fe-badge--<?php echo \esc_attr( $badge ); ?>"><?php echo \esc_html( $this->child_badge_label( $badge ) ); ?></span><?php endif; ?></a>
                        <?php endforeach; ?>
                    </div>
                    <p class="anchor-roster-fe-tools anchor-roster-fe-all-dates">
                        <a class="anchor-event-button-secondary" href="<?php echo \esc_url( \wp_nonce_url( \add_query_arg( [ 'action' => 'anchor_event_export', 'event_id' => $parent_id, 'occurrences' => 'all', 'scope' => 'all' ], \admin_url( 'admin-post.php' ) ), 'anchor_event_export' ) ); ?>"><?php \esc_html_e( 'Export all dates', 'anchor-schema' ); ?></a>
                        <a class="anchor-event-button-secondary" href="<?php echo \esc_url( \wp_nonce_url( \add_query_arg( [ 'action' => 'anchor_event_export', 'event_id' => $parent_id, 'occurrences' => 'all', 'scope' => 'active' ], \admin_url( 'admin-post.php' ) ), 'anchor_event_export' ) ); ?>"><?php \esc_html_e( 'Export all dates (confirmed only)', 'anchor-schema' ); ?></a>
                    </p>
                    </div><!-- .anchor-roster-fe-tabbar -->

                    <?php
                    // Only the ACTIVE occurrence gets a panel — every other
                    // tab is a link and nothing else. Rendering a section per
                    // child here (even a lighter summary one) is what stacked
                    // every date's panel on the page regardless of which tab
                    // was selected; a single panel for $active is the fix.
                    $label          = $this->module->occurrence_label( $active, $this->module->get_meta( $active ) );
                    $tab_id         = 'anchor-roster-tab-' . $active;
                    $panel_id       = 'anchor-roster-panel-' . $active;
                    // The panel's own self_url carries `occurrence=` so the
                    // add/edit/cancel actions posted from it return to THIS
                    // tab (Roster::redirect() reads it back via
                    // Occurrences::parent_of()).
                    $child_self_url = \add_query_arg( 'occurrence', $active, $page_url );
                    ?>
                    <section id="<?php echo \esc_attr( $panel_id ); ?>" role="tabpanel" aria-labelledby="<?php echo \esc_attr( $tab_id ); ?>" class="anchor-roster-fe-panel">
                        <h3 class="anchor-roster-fe-panel-title"><?php echo \esc_html( $label ); ?></h3>
                        <?php echo $this->render_roster_panel( $active, $child_self_url, false ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
                    </section>
                </div>

            <?php endif; ?>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /**
     * Badge for a group child's tab: 'draft' | 'pending' | 'private' |
     * 'closed' | 'cancelled' | 'past' | ''.
     *
     * The post_status check runs FIRST (Task 42 review — since
     * render_frontend_group() now reaches every child via
     * children_any_status(), one of these tabs can be an unpublished child):
     * whether the post is even public is the more fundamental fact, and an
     * unpublished child can otherwise ALSO read as any of the states below
     * (a draft child is never `is_past()`-excluded, for instance) — the
     * operator needs to know it's a draft/pending/private post before
     * anything about its date.
     *
     * is_closed() has to be checked BEFORE the status: Occurrences::soft_close()
     * (dropping a date from the parent's offering) reuses the plain
     * `cancelled` status vocabulary — status_mode=manual, status=cancelled —
     * plus its own `occurrence_closed` flag, so every soft-closed child
     * already reads get_event_status() === 'cancelled' too. Checking status
     * first would report EVERY soft-closed date as merely "cancelled" and
     * the 'closed' badge would never fire for the case the brief actually
     * asks for. is_closed() is the engine's own, more specific signal for
     * "this date was pulled from the offering" and wins; a plain 'cancelled'
     * is left for a child individually marked cancelled by its own author
     * (still on offer, never soft-closed).
     *
     * @param int $child_id
     * @return string
     */
    private function child_badge( $child_id ) {
        $post_status = (string) \get_post_status( $child_id );
        if ( \in_array( $post_status, [ 'draft', 'pending', 'private' ], true ) ) {
            return $post_status;
        }
        if ( $this->module->occurrences && $this->module->occurrences->is_closed( $child_id ) ) {
            return 'closed';
        }
        if ( $this->module->get_event_status( $child_id ) === 'cancelled' ) {
            return 'cancelled';
        }
        if ( $this->module->occurrences && $this->module->occurrences->is_past( $child_id ) ) {
            return 'past';
        }
        return '';
    }

    /** Human label for child_badge()'s machine value. */
    private function child_badge_label( $badge ) {
        switch ( $badge ) {
            case 'draft':
                return \__( 'Draft', 'anchor-schema' );
            case 'pending':
                return \__( 'Pending', 'anchor-schema' );
            case 'private':
                return \__( 'Private', 'anchor-schema' );
            case 'cancelled':
                return \__( 'Cancelled', 'anchor-schema' );
            case 'closed':
                return \__( 'Closed', 'anchor-schema' );
            case 'past':
                return \__( 'Past', 'anchor-schema' );
            default:
                return '';
        }
    }

    /**
     * The summary cards (confirmed/pending/waitlist/cancelled/capacity/seats
     * left) plus the overbooked warning, used by render_roster_panel() —
     * the single roster renderer for a bookable event id, whether that's a
     * single event's console or the ACTIVE occurrence's panel on a group
     * parent's tabbed console.
     *
     * @param array $summary Registrations::get_event_summary() result.
     * @return string
     */
    private function render_summary_cards( array $summary ) {
        \ob_start();
        ?>
        <ul class="anchor-roster-fe-summary">
            <li><span><?php echo (int) $summary['confirmed']; ?></span><?php \esc_html_e( 'Confirmed', 'anchor-schema' ); ?></li>
            <li><span><?php echo (int) $summary['pending']; ?></span><?php \esc_html_e( 'Pending', 'anchor-schema' ); ?></li>
            <li><span><?php echo (int) $summary['waitlist']; ?></span><?php \esc_html_e( 'Waitlist', 'anchor-schema' ); ?></li>
            <li><span><?php echo (int) $summary['cancelled']; ?></span><?php \esc_html_e( 'Cancelled', 'anchor-schema' ); ?></li>
            <li><span><?php echo $summary['capacity'] > 0 ? (int) $summary['capacity'] : '&infin;'; ?></span><?php \esc_html_e( 'Capacity', 'anchor-schema' ); ?></li>
            <li><span><?php echo $summary['remaining'] < 0 ? '&infin;' : (int) $summary['remaining']; ?></span><?php \esc_html_e( 'Seats left', 'anchor-schema' ); ?></li>
        </ul>

        <?php if ( ! empty( $summary['is_overbooked'] ) ) : ?>
            <p class="anchor-roster-fe-warn"><?php \esc_html_e( 'This event is overbooked — reserved seats exceed capacity.', 'anchor-schema' ); ?></p>
        <?php endif; ?>
        <?php
        return (string) \ob_get_clean();
    }

    /**
     * The full attendee panel for ONE bookable event id: summary, the
     * edit-or-add form, the Registered list, and the export tools. The
     * single roster renderer used everywhere a panel is needed — a single
     * event's console (render_frontend_single()) or the ACTIVE occurrence's
     * panel on a group parent's tabbed console (render_frontend_group()) —
     * so there is never a second copy of the summary/form/list markup to
     * drift out of sync.
     *
     * A group parent's console renders exactly one of these per request
     * (the active occurrence only); every other tab is a link and nothing
     * else, which is what keeps the seat table (query_seats() at
     * per_page=500) bounded to one occurrence even on a recurring parent
     * with upwards of a hundred children.
     *
     * @param int    $event_id
     * @param string $self_url                 This event's own console URL —
     *                                          used as the add/edit forms'
     *                                          `roster_return` and as the
     *                                          edit-seat/cancel link base.
     *                                          For a group child this already
     *                                          carries `&occurrence=<id>`.
     * @param bool   $announce_missing_seat     Whether to print "Seat not
     *                                          found." here when `?seat_id=`
     *                                          does not belong to this event.
     *                                          True for the single-event page
     *                                          (unchanged behaviour); false
     *                                          from render_frontend_group(),
     *                                          which prints that notice once
     *                                          at the page level instead —
     *                                          otherwise every non-owning
     *                                          tab would also claim the seat
     *                                          is missing.
     * @return string
     */
    private function render_roster_panel( $event_id, $self_url, $announce_missing_seat = true ) {
        $event_id = (int) $event_id;
        $seat_id  = isset( $_GET['seat_id'] ) ? (int) \wp_unslash( $_GET['seat_id'] ) : 0;

        $questions = $this->module_questions( $event_id );
        $summary   = $this->registrations->get_event_summary( $event_id );
        $seats   = $this->registrations->query_seats( [
            'event_id' => $event_id,
            'status'   => 'all',
            'per_page' => 500,
            'orderby'  => 'attendee',
            'order'    => 'ASC',
        ] );

        \ob_start();
        ?>
        <?php echo $this->render_summary_cards( $summary ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>

        <?php // REG-D48 — ?seat_id= only opens a seat that belongs to THIS event. ?>
        <?php if ( $seat_id > 0 && self::seat_belongs_to_event( $seat_id, $event_id ) ) : ?>
            <?php echo $this->frontend_edit_form( $event_id, $seat_id, $self_url ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <?php else : ?>
            <?php if ( $seat_id > 0 && $announce_missing_seat ) : ?>
                <p class="anchor-roster-fe-warn"><?php \esc_html_e( 'Seat not found.', 'anchor-schema' ); ?></p>
            <?php endif; ?>
            <?php echo $this->frontend_add_form( $event_id, $self_url ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <?php endif; ?>

        <div class="anchor-event-section">
            <h3><?php \esc_html_e( 'Registered', 'anchor-schema' ); ?></h3>
            <?php if ( empty( $seats['items'] ) ) : ?>
                <p class="anchor-roster-fe-empty"><?php \esc_html_e( 'Nobody is registered yet.', 'anchor-schema' ); ?></p>
            <?php else : ?>
            <div class="anchor-roster-fe-tablewrap">
                <table class="anchor-roster-fe-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php \esc_html_e( 'Attendee', 'anchor-schema' ); ?></th>
                            <th scope="col"><?php \esc_html_e( 'Status', 'anchor-schema' ); ?></th>
                            <th scope="col"><?php \esc_html_e( 'Ticket', 'anchor-schema' ); ?></th>
                            <th scope="col"><?php \esc_html_e( 'Party', 'anchor-schema' ); ?></th>
                            <?php foreach ( $questions as $q ) : ?>
                                <th scope="col"><?php echo \esc_html( $q['label'] ); ?></th>
                            <?php endforeach; ?>
                            <th scope="col"><?php \esc_html_e( 'Source', 'anchor-schema' ); ?></th>
                            <th scope="col"><?php \esc_html_e( 'Added', 'anchor-schema' ); ?></th>
                            <th scope="col"><?php \esc_html_e( 'Actions', 'anchor-schema' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $seats['items'] as $seat ) :
                        $edit_link   = \add_query_arg( 'seat_id', (int) $seat['id'], $self_url );
                        $cancel_link = \add_query_arg( 'roster_return', \rawurlencode( $self_url ), $this->cancel_url( $event_id, $seat['id'] ) );
                        $is_cancelled = \in_array( $seat['status'], [ Registrations::STATUS_CANCELLED, Registrations::STATUS_REFUNDED, Registrations::STATUS_FAILED ], true );
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo \esc_html( $seat['name'] ); ?></strong>
                                <?php if ( $seat['email'] !== '' ) : ?>
                                    <span class="anchor-roster-fe-sub"><a href="mailto:<?php echo \esc_attr( $seat['email'] ); ?>"><?php echo \esc_html( $seat['email'] ); ?></a></span>
                                <?php endif; ?>
                                <?php if ( $seat['phone'] !== '' ) : ?>
                                    <span class="anchor-roster-fe-sub"><?php echo \esc_html( $seat['phone'] ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="anchor-roster-fe-pill" style="background:<?php echo \esc_attr( $this->status_color( $seat['status'] ) ); ?>"><?php echo \esc_html( $this->status_label( $seat['status'] ) ); ?></span></td>
                            <td><?php echo \esc_html( $this->tier_label( $event_id, $seat['ticket_type_id'] ) ); ?></td>
                            <td><?php echo (int) ( 1 + (int) $seat['guests'] ); ?></td>
                            <?php $answers = isset( $seat['reg_fields'] ) && \is_array( $seat['reg_fields'] ) ? $seat['reg_fields'] : []; ?>
                            <?php foreach ( $questions as $q ) : ?>
                                <td><?php echo \esc_html( (string) ( $answers[ $q['key'] ] ?? '' ) ); ?></td>
                            <?php endforeach; ?>
                            <td>
                                <?php echo \esc_html( $seat['source'] ); ?>
                                <?php if ( (int) $seat['order_id'] > 0 ) :
                                    $olink = $this->order_link( (int) $seat['order_id'] ); ?>
                                    <span class="anchor-roster-fe-sub"><?php if ( $olink ) : ?><a href="<?php echo \esc_url( $olink ); ?>">#<?php echo (int) $seat['order_id']; ?></a><?php else : ?>#<?php echo (int) $seat['order_id']; ?><?php endif; ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo \esc_html( $seat['date'] ); ?></td>
                            <td class="anchor-roster-fe-actions">
                                <a href="<?php echo \esc_url( $edit_link ); ?>"><?php \esc_html_e( 'Edit', 'anchor-schema' ); ?></a>
                                <?php if ( ! $is_cancelled ) : ?>
                                    <a class="anchor-roster-fe-danger" href="<?php echo \esc_url( $cancel_link ); ?>" data-confirm="<?php \esc_attr_e( 'Cancel this seat?', 'anchor-schema' ); ?>"><?php \esc_html_e( 'Cancel', 'anchor-schema' ); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <p class="anchor-roster-fe-tools">
                <a class="anchor-event-button-secondary" href="<?php echo \esc_url( \wp_nonce_url( \add_query_arg( [ 'action' => 'anchor_event_export', 'event_id' => $event_id, 'scope' => 'all' ], \admin_url( 'admin-post.php' ) ), 'anchor_event_export' ) ); ?>"><?php \esc_html_e( 'Export CSV', 'anchor-schema' ); ?></a>
                <a class="anchor-event-button-secondary" href="<?php echo \esc_url( \wp_nonce_url( \add_query_arg( [ 'action' => 'anchor_event_export', 'event_id' => $event_id, 'scope' => 'active' ], \admin_url( 'admin-post.php' ) ), 'anchor_event_export' ) ); ?>"><?php \esc_html_e( 'Export CSV (confirmed only)', 'anchor-schema' ); ?></a>
            </p>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /**
     * A form-control base name, suffixed with an event id so the same base
     * (`roster_name`, `roster_field_practice_name`, ...) is unique across
     * every panel render_frontend_group() renders (Task 42 review finding —
     * a duplicate `id` resolves `<label for>` to whichever element the
     * browser matches FIRST in the whole document, so a later tab's labels
     * silently pointed at the FIRST panel's inputs). One helper, used by
     * both frontend_add_form() and frontend_edit_form(), so the id a label's
     * `for` names and the id its control actually carries can never drift.
     *
     * @param string $base     e.g. 'roster_name', 'roster_field_practice_name'.
     * @param int    $event_id
     * @return string
     */
    private function field_id( $base, $event_id ) {
        return $base . '-' . (int) $event_id;
    }

    /**
     * A nonce field for a console form, with a unique `id` (Task 42 review —
     * `wp_nonce_field()`'s default markup carries `id="_wpnonce"`, and every
     * panel's form calling it produced a document-wide duplicate id even
     * after field_id() covered every OTHER control; `name="_wpnonce"` stays
     * exactly what it always was, since check_admin_referer() reads that
     * field by name, not by id). Echoes, matching wp_nonce_field()'s default.
     *
     * Task 18 — $id_base lets a panel that renders more than one nonced form
     * for the SAME event_id (the add-attendee form and the add-by-email form
     * both live in frontend_add_form()) give each its own id; every existing
     * caller renders one nonce per event_id per panel and keeps the default.
     *
     * @param string $action
     * @param int    $event_id
     * @param string $id_base
     */
    private function nonce_field_with_unique_id( $action, $event_id, $id_base = '_wpnonce' ) {
        echo '<input type="hidden" id="' . \esc_attr( $this->field_id( $id_base, $event_id ) ) . '" name="_wpnonce" value="'
            . \esc_attr( \wp_create_nonce( $action ) ) . '" />';
        echo \wp_referer_field( false ); // phpcs:ignore WordPress.Security.EscapeOutput -- core-escaped.
    }

    /** Manual "add attendee" form for the front-end console. */
    private function frontend_add_form( $event_id, $self_url ) {
        $event_id = (int) $event_id;
        // REG-D56 — the same resolver the admin form uses.
        $tiers    = $this->tier_choices( $event_id )['choices'];

        \ob_start();
        ?>
        <div class="anchor-event-section">
            <h3><?php \esc_html_e( 'Add an attendee', 'anchor-schema' ); ?></h3>
            <p class="anchor-roster-fe-help"><?php echo \esc_html( $this->add_form_help( $event_id ) ); ?></p>
            <form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" class="anchor-roster-fe-form">
                <input type="hidden" name="action" value="anchor_roster_add" />
                <input type="hidden" name="event_id" value="<?php echo \esc_attr( (string) $event_id ); ?>" />
                <input type="hidden" name="roster_return" value="<?php echo \esc_url( $self_url ); ?>" />
                <?php $this->nonce_field_with_unique_id( 'anchor_roster_add_' . $event_id, $event_id ); ?>

                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $this->field_id( 'roster_name', $event_id ) ); ?>"><?php \esc_html_e( 'Name', 'anchor-schema' ); ?> *</label>
                        <input type="text" id="<?php echo \esc_attr( $this->field_id( 'roster_name', $event_id ) ); ?>" name="roster_name" required />
                    </div>
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $this->field_id( 'roster_email', $event_id ) ); ?>"><?php \esc_html_e( 'Email', 'anchor-schema' ); ?></label>
                        <input type="email" id="<?php echo \esc_attr( $this->field_id( 'roster_email', $event_id ) ); ?>" name="roster_email" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $this->field_id( 'roster_phone', $event_id ) ); ?>"><?php \esc_html_e( 'Phone', 'anchor-schema' ); ?></label>
                        <input type="text" id="<?php echo \esc_attr( $this->field_id( 'roster_phone', $event_id ) ); ?>" name="roster_phone" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $this->field_id( 'roster_guests', $event_id ) ); ?>"><?php \esc_html_e( 'Additional guests', 'anchor-schema' ); ?></label>
                        <input type="number" id="<?php echo \esc_attr( $this->field_id( 'roster_guests', $event_id ) ); ?>" name="roster_guests" value="0" min="0" />
                    </div>
                    <?php if ( ! empty( $tiers ) ) : ?>
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $this->field_id( 'roster_ticket_type', $event_id ) ); ?>"><?php \esc_html_e( 'Ticket type', 'anchor-schema' ); ?></label>
                        <select id="<?php echo \esc_attr( $this->field_id( 'roster_ticket_type', $event_id ) ); ?>" name="roster_ticket_type">
                            <?php foreach ( $tiers as $tier_id => $label ) : ?>
                                <option value="<?php echo \esc_attr( $tier_id ); ?>"><?php echo \esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php // REG-D39 — the same questions the public form asks. ?>
                    <?php foreach ( $this->module_questions( $event_id ) as $q ) :
                        $field_id = $this->field_id( 'roster_field_' . $q['key'], $event_id );
                    ?>
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $field_id ); ?>"><?php echo \esc_html( $q['label'] ); ?><?php echo empty( $q['required'] ) ? '' : ' *'; ?></label>
                        <?php
                        echo $this->module->render_registration_question_control( $q, [ // phpcs:ignore WordPress.Security.EscapeOutput -- the renderer escapes.
                            'name' => 'roster_field[' . $q['key'] . ']',
                            'id'   => $field_id,
                        ] );
                        ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <input type="hidden" name="roster_notify_control" value="1" />
                <p class="anchor-roster-fe-check"><label><input type="checkbox" name="roster_notify" value="1" checked /> <?php \esc_html_e( 'Send the confirmation email', 'anchor-schema' ); ?></label></p>
                <p class="anchor-roster-fe-check"><?php echo $this->override_check(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></p>

                <button type="submit" class="anchor-event-button"><?php \esc_html_e( 'Add attendee', 'anchor-schema' ); ?></button>
            </form>
        </div>
        <?php if ( $this->access_enabled( $event_id ) ) : ?>
        <div class="anchor-event-section">
            <h3><?php \esc_html_e( 'Add person by email', 'anchor-schema' ); ?></h3>
            <p class="anchor-roster-fe-help"><?php \esc_html_e( 'Grants this event’s role directly — no seat is created and it never counts toward capacity. Use this for a comp, a speaker, or anyone who needs access without registering.', 'anchor-schema' ); ?></p>
            <form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" class="anchor-roster-fe-form">
                <input type="hidden" name="action" value="anchor_roster_add_access" />
                <input type="hidden" name="event_id" value="<?php echo \esc_attr( (string) $event_id ); ?>" />
                <input type="hidden" name="roster_return" value="<?php echo \esc_url( $self_url ); ?>" />
                <?php $this->nonce_field_with_unique_id( 'anchor_roster_edit_' . $event_id, $event_id, '_wpnonce_access' ); ?>

                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $this->field_id( 'access_name', $event_id ) ); ?>"><?php \esc_html_e( 'Name', 'anchor-schema' ); ?></label>
                        <input type="text" id="<?php echo \esc_attr( $this->field_id( 'access_name', $event_id ) ); ?>" name="access_name" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $this->field_id( 'access_email', $event_id ) ); ?>"><?php \esc_html_e( 'Email', 'anchor-schema' ); ?> *</label>
                        <input type="email" id="<?php echo \esc_attr( $this->field_id( 'access_email', $event_id ) ); ?>" name="access_email" required />
                    </div>
                </div>

                <button type="submit" class="anchor-event-button"><?php \esc_html_e( 'Grant access', 'anchor-schema' ); ?></button>
            </form>
        </div>
        <?php endif; ?>
        <?php
        return (string) \ob_get_clean();
    }

    /** Test seam for frontend_add_form() (Task 18). */
    public function frontend_add_form_public( $event_id, $self_url = '' ) {
        return $this->frontend_add_form( (int) $event_id, (string) $self_url );
    }

    /**
     * What a manual add on this event will actually do about email.
     *
     * REG-D57 — this used to be one fixed sentence promising that a manually
     * added seat "receives the same confirmation and reminder emails as a
     * public sign-up" and that "reminders still go out" even when the
     * confirmation is unticked. Neither is a fact about the site: reminders
     * reach the seat only while the site-wide reminder sweep is on AND this
     * event's reminder switch is untouched, and the confirmation is likewise
     * per-event — and none of that is visible from this form. The sentence is
     * derived from the same two answers the sender asks.
     *
     * @param int $event_id
     * @return string
     */
    private function add_form_help( $event_id ) {
        $settings     = $this->module->get_settings();
        $confirmation = ! empty( $settings['notify_user'] ) && $this->module->is_email_enabled( (int) $event_id, 'confirmation' );
        $reminders    = ! empty( $settings['reminder_enabled'] ) && $this->module->is_email_enabled( (int) $event_id, 'reminder' );

        $text = \__( 'Seats added here are real registrations: they count against capacity and appear in the export.', 'anchor-schema' );

        if ( $confirmation ) {
            $text .= ' ' . \__( 'This event sends a confirmation email — untick “Send the confirmation email” to add somebody silently.', 'anchor-schema' );
        } else {
            $text .= ' ' . \__( 'This event is not sending confirmation emails, so nothing is emailed on add.', 'anchor-schema' );
        }

        $text .= ' ' . ( $reminders
            ? \__( 'Reminder emails are on for this event, so the seat will get them.', 'anchor-schema' )
            : \__( 'Reminder emails are off for this event, so the seat will not get any.', 'anchor-schema' ) );

        return $text;
    }

    /** Edit one seat from the front-end console. */
    private function frontend_edit_form( $event_id, $seat_id, $self_url ) {
        $event_id = (int) $event_id;
        $seat_id  = (int) $seat_id;
        // REG-D48 — checked at the call site too; repeated here so the method can
        // never be the one that prints a foreign event's attendee details.
        $seat = $this->seat_form_values( $event_id, $seat_id );
        if ( ! \is_array( $seat ) ) {
            return '<p class="anchor-roster-fe-warn">' . \esc_html__( 'Seat not found.', 'anchor-schema' ) . '</p>';
        }

        $name   = (string) $seat['name'];
        $email  = (string) $seat['email'];
        $phone  = (string) $seat['phone'];
        $status = (string) $seat['status'];
        $is_woo = self::seat_is_order_owned( $seat );

        \ob_start();
        ?>
        <div class="anchor-event-section anchor-roster-fe-editing">
            <h3><?php \esc_html_e( 'Edit seat', 'anchor-schema' ); ?> #<?php echo (int) $seat_id; ?></h3>
            <?php if ( $is_woo ) : ?>
                <p class="anchor-roster-fe-help"><?php \esc_html_e( 'This seat came from a WooCommerce order — cancel or refund it in the order so payment and seats stay in step.', 'anchor-schema' ); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" class="anchor-roster-fe-form">
                <input type="hidden" name="action" value="anchor_roster_edit" />
                <input type="hidden" name="event_id" value="<?php echo \esc_attr( (string) $event_id ); ?>" />
                <input type="hidden" name="seat_id" value="<?php echo \esc_attr( (string) $seat_id ); ?>" />
                <input type="hidden" name="roster_return" value="<?php echo \esc_url( $self_url ); ?>" />
                <?php $this->nonce_field_with_unique_id( 'anchor_roster_edit_' . $event_id, $event_id ); ?>

                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $this->field_id( 'roster_name', $event_id ) ); ?>"><?php \esc_html_e( 'Name', 'anchor-schema' ); ?></label>
                        <input type="text" id="<?php echo \esc_attr( $this->field_id( 'roster_name', $event_id ) ); ?>" name="roster_name" value="<?php echo \esc_attr( $name ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $this->field_id( 'roster_email', $event_id ) ); ?>"><?php \esc_html_e( 'Email', 'anchor-schema' ); ?></label>
                        <input type="email" id="<?php echo \esc_attr( $this->field_id( 'roster_email', $event_id ) ); ?>" name="roster_email" value="<?php echo \esc_attr( $email ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $this->field_id( 'roster_phone', $event_id ) ); ?>"><?php \esc_html_e( 'Phone', 'anchor-schema' ); ?></label>
                        <input type="text" id="<?php echo \esc_attr( $this->field_id( 'roster_phone', $event_id ) ); ?>" name="roster_phone" value="<?php echo \esc_attr( $phone ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="<?php echo \esc_attr( $this->field_id( 'roster_status', $event_id ) ); ?>"><?php \esc_html_e( 'Status', 'anchor-schema' ); ?></label>
                        <select id="<?php echo \esc_attr( $this->field_id( 'roster_status', $event_id ) ); ?>" name="roster_status">
                            <?php foreach ( $this->status_options_for( $status ) as $val => $label ) : ?>
                                <option value="<?php echo \esc_attr( $val ); ?>" <?php \selected( $status, $val ); ?>><?php echo \esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php
                // REG-D38 — the console's refusal tells the operator to tick
                // "Allow over capacity", so the console has to offer it. The
                // wp-admin edit form has had it since the revive started
                // consulting capacity; this one did not, and its refusal named
                // a control that was nowhere on the page.
                ?>
                <p class="anchor-roster-fe-check"><?php echo $this->override_check(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></p>

                <button type="submit" class="anchor-event-button"><?php \esc_html_e( 'Save seat', 'anchor-schema' ); ?></button>
                <a class="anchor-event-button-secondary" href="<?php echo \esc_url( $self_url ); ?>"><?php \esc_html_e( 'Cancel', 'anchor-schema' ); ?></a>
            </form>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /** The same notice as maybe_render_notice(), in front-end markup. */
    private function frontend_notice() {
        $notice = $this->notice_parts();
        if ( null === $notice ) {
            return '';
        }
        $class = $notice['type'] === 'error' ? 'is-error' : 'is-ok';
        return '<div class="anchor-event-manager-notice ' . \esc_attr( $class ) . '">' . \esc_html( $notice['message'] ) . '</div>';
    }
}

/* =========================================================================
 * Roster list table — declared on demand by render_roster(), the one screen
 * that uses it. WP_List_Table only exists under wp-admin, so this used to be
 * wrapped in `if ( is_admin() )` at file scope; that made the class impossible
 * to reach from anywhere the admin bootstrap has not run (including the test
 * suite, which is why REG-D13 went unnoticed). Declaring it inside a function
 * keeps the same laziness and makes the one caller responsible for asking.
 * ========================================================================= */
function load_roster_list_table() {
    if ( ! \class_exists( '\WP_List_Table' ) ) {
        require_once \ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
    }

    if ( ! \class_exists( '\Anchor\Events\Roster_List_Table' ) ) {

        class Roster_List_Table extends \WP_List_Table {

            /** @var int */
            private $event_id;

            /** @var Registrations */
            private $registrations;

            /** @var Roster */
            private $roster;

            public function __construct( $event_id, Registrations $registrations, Roster $roster ) {
                $this->event_id      = (int) $event_id;
                $this->registrations = $registrations;
                $this->roster        = $roster;
                parent::__construct( [
                    'singular' => 'seat',
                    'plural'   => 'seats',
                    'ajax'     => false,
                ] );
            }

            /**
             * REG-D14 — no 'cb' column. The table used to render a row
             * checkbox posting `seat[]`, but there is no get_bulk_actions()
             * and no handler reads `seat[]`, so ticking ten seats and looking
             * for a bulk cancel found nothing to submit to. The checkbox is
             * gone until the bulk action that would consume it ships.
             *
             * Task 18 — delegates to Roster::list_table_columns(), the one
             * place base + question + (conditional) Access columns are built,
             * shared with the list_table_columns_public() test seam.
             */
            public function get_columns() {
                return $this->roster->list_table_columns( $this->event_id );
            }

            protected function get_sortable_columns() {
                return [
                    'attendee' => [ 'attendee', false ],
                    'email'    => [ 'email', false ],
                    'status'   => [ 'status', false ],
                    'source'   => [ 'source', false ],
                    'seat'     => [ 'seat', false ],
                    'date'     => [ 'date', true ],
                ];
            }

            public function prepare_items() {
                $per_page = 25;
                $paged    = isset( $_GET['paged'] ) ? max( 1, (int) \wp_unslash( $_GET['paged'] ) ) : 1;
                $orderby  = isset( $_GET['orderby'] ) ? \sanitize_key( \wp_unslash( $_GET['orderby'] ) ) : 'date';
                $order    = ( isset( $_GET['order'] ) && \strtoupper( \wp_unslash( $_GET['order'] ) ) === 'ASC' ) ? 'ASC' : 'DESC';
                $status   = isset( $_GET['status'] ) ? \sanitize_key( \wp_unslash( $_GET['status'] ) ) : '';
                $source   = isset( $_GET['source'] ) ? \sanitize_key( \wp_unslash( $_GET['source'] ) ) : '';
                $search   = isset( $_REQUEST['s'] ) ? \sanitize_text_field( \wp_unslash( $_REQUEST['s'] ) ) : '';

                $result = $this->registrations->query_seats( [
                    'event_id' => $this->event_id,
                    'status'   => $status,
                    'source'   => $source,
                    'search'   => $search,
                    'paged'    => $paged,
                    'per_page' => $per_page,
                    'orderby'  => $orderby,
                    'order'    => $order,
                ] );

                $this->items           = $result['items'];
                $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
                $this->set_pagination_args( [
                    'total_items' => $result['total'],
                    'per_page'    => $per_page,
                    'total_pages' => (int) \ceil( $result['total'] / $per_page ),
                ] );
            }

            protected function get_views() {
                $views   = [];
                $current = isset( $_GET['status'] ) ? \sanitize_key( \wp_unslash( $_GET['status'] ) ) : '';
                $base    = $this->roster->roster_url( $this->event_id );
                $filters = [
                    ''             => \__( 'All', 'anchor-schema' ),
                    'active'       => \__( 'Active', 'anchor-schema' ),
                    'confirmed'    => \__( 'Confirmed', 'anchor-schema' ),
                    'pending'      => \__( 'Pending', 'anchor-schema' ),
                    'waitlist'     => \__( 'Waitlist', 'anchor-schema' ),
                    'cancelled'    => \__( 'Cancelled', 'anchor-schema' ),
                    'refunded'     => \__( 'Refunded', 'anchor-schema' ),
                    'failed'       => \__( 'Failed', 'anchor-schema' ),
                ];
                foreach ( $filters as $key => $label ) {
                    $url = $key === '' ? $base : \add_query_arg( 'status', $key, $base );
                    $cls = ( $current === $key ) ? ' class="current"' : '';
                    $views[ $key ] = '<a href="' . \esc_url( $url ) . '"' . $cls . '>' . \esc_html( $label ) . '</a>';
                }
                return $views;
            }

            public function column_attendee( $item ) {
                $name    = $item['name'] !== '' ? $item['name'] : \__( '(no name)', 'anchor-schema' );
                $edit    = $this->roster->roster_url( $this->event_id, [ 'edit_seat' => (int) $item['id'] ] );
                $actions = [
                    'edit' => '<a href="' . \esc_url( $edit ) . '">' . \esc_html__( 'Edit', 'anchor-schema' ) . '</a>',
                ];
                if ( ! \in_array( $item['status'], [ Registrations::STATUS_CANCELLED, Registrations::STATUS_REFUNDED ], true ) ) {
                    $actions['cancel'] = '<a href="' . \esc_url( $this->roster->cancel_url( $this->event_id, (int) $item['id'] ) ) . '"'
                        . ' onclick="return confirm(\'' . \esc_js( \__( 'Cancel this seat?', 'anchor-schema' ) ) . '\');">'
                        . \esc_html__( 'Cancel', 'anchor-schema' ) . '</a>';
                }
                return '<strong>' . \esc_html( $name ) . '</strong>' . $this->row_actions( $actions );
            }

            public function column_status( $item ) {
                $color = $this->roster->status_color( $item['status'] );
                $label = $this->roster->status_label( $item['status'] );
                return '<span class="anchor-roster-pill" style="background:' . \esc_attr( $color ) . ';">'
                    . \esc_html( $label ) . '</span>';
            }

            public function column_order( $item ) {
                $oid = (int) $item['order_id'];
                if ( $oid <= 0 ) {
                    return '&mdash;';
                }
                $url = $this->roster->order_link( $oid );
                if ( $url !== '' ) {
                    return '<a href="' . \esc_url( $url ) . '">#' . $oid . '</a>';
                }
                return '#' . $oid;
            }

            public function column_seat( $item ) {
                return \esc_html( (string) (int) $item['seat_index'] );
            }

            /**
             * Task 18 — the seat's access ROLE, with an inline Grant/Revoke
             * form. Only rendered at all when get_columns() offered the
             * column, so this never runs for an event outside the feature.
             */
            public function column_access( $item ) {
                $state = $this->roster->access_state( $this->event_id, $item );
                $label = [
                    'yes'    => \__( 'Yes', 'anchor-schema' ),
                    'manual' => \__( 'Manual', 'anchor-schema' ),
                    'no'     => \__( 'No', 'anchor-schema' ),
                ][ $state ] ?? \__( 'Off', 'anchor-schema' ); // Not reachable: get_columns() omits this column for 'off'.
                $nonce   = \wp_create_nonce( 'anchor_roster_edit_' . $this->event_id );
                $action  = ( $state === 'no' ) ? 'anchor_roster_grant' : 'anchor_roster_revoke';
                $caption = ( $state === 'no' ) ? \__( 'Grant access', 'anchor-schema' ) : \__( 'Revoke access', 'anchor-schema' );
                return '<span class="anchor-roster-access anchor-roster-access--' . \esc_attr( $state ) . '">' . \esc_html( $label ) . '</span>'
                    . '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" class="anchor-roster-access-form">'
                    . '<input type="hidden" name="action" value="' . \esc_attr( $action ) . '" />'
                    . '<input type="hidden" name="event_id" value="' . (int) $this->event_id . '" />'
                    . '<input type="hidden" name="seat_id" value="' . (int) $item['id'] . '" />'
                    . '<input type="hidden" name="user_id" value="' . (int) ( $item['user_id'] ?? 0 ) . '" />'
                    . '<input type="hidden" name="_wpnonce" value="' . \esc_attr( $nonce ) . '" />'
                    . '<button type="submit" class="button-link">' . \esc_html( $caption ) . '</button>'
                    . '</form>';
            }

            public function column_default( $item, $column_name ) {
                if ( \strpos( $column_name, 'q_' ) === 0 ) {
                    // The column id IS the question key (Roster::list_table_columns()),
                    // and seat_dto() hands the answers back keyed the same way — the
                    // label is a heading only (REG-D10/D11).
                    $key    = \substr( $column_name, 2 );
                    $fields = isset( $item['reg_fields'] ) && \is_array( $item['reg_fields'] ) ? $item['reg_fields'] : [];
                    return \esc_html( (string) ( $fields[ $key ] ?? '' ) );
                }
                switch ( $column_name ) {
                    case 'email':
                        return \esc_html( $item['email'] );
                    case 'phone':
                        return \esc_html( $item['phone'] );
                    case 'tier':
                        return \esc_html( $this->roster->tier_label(
                            $this->event_id,
                            isset( $item['ticket_type_id'] ) ? (string) $item['ticket_type_id'] : 'primary'
                        ) );
                    case 'guests':
                        return \esc_html( (string) (int) $item['guests'] );
                    case 'source':
                        return \esc_html( $item['source'] );
                    case 'date':
                        return \esc_html( $item['date'] );
                    default:
                        return '';
                }
            }

            public function no_items() {
                \esc_html_e( 'No registrations found.', 'anchor-schema' );
            }
        }
    }
}
