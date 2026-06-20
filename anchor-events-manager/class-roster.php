<?php
namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Roster admin screen + CSV export (Phase 5 — spec §10).
 *
 * Loaded unconditionally (free + paid). A single capability — `edit_others_posts`
 * — gates the screen, the manual seat actions, and the CSV export, consistent with
 * where the Export links are exposed (fixes the original export-capability bug).
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

    /** Single capability for view, export, and all manual seat actions. */
    const CAP  = 'edit_others_posts';
    const SLUG = 'anchor-event-roster';

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
    }

    /* ---------------------------------------------------------------------
     * Menu + URLs
     * ------------------------------------------------------------------- */

    public function register_menu() {
        \add_submenu_page(
            'edit.php?post_type=' . Module::CPT,
            \__( 'Event Roster', 'anchor-schema' ),
            \__( 'Roster', 'anchor-schema' ),
            self::CAP,
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
        $url = \add_query_arg( $args, \admin_url( 'edit.php' ) );
        return \wp_nonce_url( $url, 'anchor_roster_view_' . (int) $event_id );
    }

    /* ---------------------------------------------------------------------
     * Screen
     * ------------------------------------------------------------------- */

    public function render_page() {
        if ( ! \current_user_can( self::CAP ) ) {
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
        $table = new Roster_List_Table( $event_id, $this->registrations, $this );
        $table->prepare_items();

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
        echo '</p>';
    }

    private function render_add_form( $event_id ) {
        $event_id = (int) $event_id;
        echo '<h2>' . \esc_html__( 'Add attendee', 'anchor-schema' ) . '</h2>';
        echo '<p class="description">' . \esc_html__( 'Manually added seats honor capacity (waitlisted if full and the waitlist is enabled, otherwise blocked).', 'anchor-schema' ) . '</p>';
        echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:24px;">';
        echo '<input type="hidden" name="action" value="anchor_roster_add" />';
        echo '<input type="hidden" name="event_id" value="' . $event_id . '" />';
        \wp_nonce_field( 'anchor_roster_add_' . $event_id );
        echo '<table class="form-table"><tbody>';
        $this->text_row( 'roster_name', \__( 'Name', 'anchor-schema' ), '', true );
        $this->text_row( 'roster_email', \__( 'Email', 'anchor-schema' ), '', false, 'email' );
        $this->text_row( 'roster_phone', \__( 'Phone', 'anchor-schema' ), '' );
        $this->text_row( 'roster_guests', \__( 'Additional guests', 'anchor-schema' ), '0', false, 'number' );
        echo '</tbody></table>';
        \submit_button( \__( 'Add attendee', 'anchor-schema' ) );
        echo '</form>';
    }

    private function render_edit_form( $event_id, $seat_id ) {
        $event_id = (int) $event_id;
        $seat_id  = (int) $seat_id;
        if ( \get_post_type( $seat_id ) !== Module::REG_CPT ) {
            return;
        }
        // Reads only — never written from here.
        $name   = (string) \get_post_meta( $seat_id, '_anchor_event_name', true );
        $email  = (string) \get_post_meta( $seat_id, '_anchor_event_email', true );
        $phone  = (string) \get_post_meta( $seat_id, '_anchor_event_phone', true );
        $status = (string) \get_post_meta( $seat_id, '_anchor_event_reg_status', true );
        $source = (string) \get_post_meta( $seat_id, '_anchor_event_source', true );
        $oid    = (int) \get_post_meta( $seat_id, '_anchor_event_order_id', true );
        $is_woo = ( $source === 'woocommerce' );

        echo '<div class="anchor-roster-edit" style="margin:12px 0;padding:12px 16px;background:#fff;border:1px solid #2271b1;border-radius:3px;">';
        echo '<h2 style="margin-top:0;">' . \esc_html__( 'Edit seat', 'anchor-schema' ) . ' #' . $seat_id . '</h2>';
        echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="anchor_roster_edit" />';
        echo '<input type="hidden" name="event_id" value="' . $event_id . '" />';
        echo '<input type="hidden" name="seat_id" value="' . $seat_id . '" />';
        \wp_nonce_field( 'anchor_roster_edit_' . $event_id );
        echo '<table class="form-table"><tbody>';
        $this->text_row( 'roster_name', \__( 'Name', 'anchor-schema' ), $name );
        $this->text_row( 'roster_email', \__( 'Email', 'anchor-schema' ), $email, false, 'email' );
        $this->text_row( 'roster_phone', \__( 'Phone', 'anchor-schema' ), $phone );

        // Status select.
        echo '<tr><th scope="row"><label for="roster_status">' . \esc_html__( 'Status', 'anchor-schema' ) . '</label></th><td>';
        echo '<select name="roster_status" id="roster_status">';
        foreach ( $this->status_options() as $val => $label ) {
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

    /* ---------------------------------------------------------------------
     * Manual seat actions (delegate to the data layer)
     * ------------------------------------------------------------------- */

    public function handle_add() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        $this->guard( 'anchor_roster_add_' . $event_id );

        $name   = \sanitize_text_field( \wp_unslash( $_POST['roster_name'] ?? '' ) );
        $email  = \sanitize_email( \wp_unslash( $_POST['roster_email'] ?? '' ) );
        $phone  = \sanitize_text_field( \wp_unslash( $_POST['roster_phone'] ?? '' ) );
        $guests = max( 0, (int) \wp_unslash( $_POST['roster_guests'] ?? 0 ) );

        if ( $name === '' ) {
            $this->redirect( $event_id, 'error', \__( 'A name is required.', 'anchor-schema' ) );
        }

        $meta   = $this->module->get_meta( $event_id );
        $result = $this->registrations->claim_seats( $event_id, $meta, 1, [
            'source' => 'manual',
            'name'   => $name,
            'email'  => $email,
            'phone'  => $phone,
            'guests' => $guests,
            'actor'  => 'user:' . \get_current_user_id(),
            'note'   => 'manual roster add',
        ] );

        if ( ! empty( $result['created'] ) ) {
            $this->redirect( $event_id, 'success', \__( 'Attendee added.', 'anchor-schema' ) );
        } elseif ( ! empty( $result['waitlisted'] ) ) {
            $this->redirect( $event_id, 'success', \__( 'Attendee added to the waitlist (event is full).', 'anchor-schema' ) );
        } else {
            $this->redirect( $event_id, 'error', \__( 'Could not add attendee — the event is full and the waitlist is disabled.', 'anchor-schema' ) );
        }
    }

    public function handle_edit() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        $seat_id  = isset( $_POST['seat_id'] ) ? (int) \wp_unslash( $_POST['seat_id'] ) : 0;
        $this->guard( 'anchor_roster_edit_' . $event_id );

        if ( \get_post_type( $seat_id ) !== Module::REG_CPT ) {
            $this->redirect( $event_id, 'error', \__( 'Seat not found.', 'anchor-schema' ) );
        }

        $name   = \sanitize_text_field( \wp_unslash( $_POST['roster_name'] ?? '' ) );
        $email  = \sanitize_email( \wp_unslash( $_POST['roster_email'] ?? '' ) );
        $phone  = \sanitize_text_field( \wp_unslash( $_POST['roster_phone'] ?? '' ) );
        $status = \sanitize_text_field( \wp_unslash( $_POST['roster_status'] ?? '' ) );

        // Contact fields (name/email/phone) are not order-derived, so they are
        // editable for any seat — delegated to the data layer (no direct writes).
        $this->registrations->update_contact( $seat_id, [
            'name'  => $name,
            'email' => $email,
            'phone' => $phone,
        ] );

        // Status change routed through the data layer (transition rules + history).
        $current = (string) \get_post_meta( $seat_id, '_anchor_event_reg_status', true );
        if ( $status !== '' && $status !== $current ) {
            if ( ! $this->registrations->update_status( $seat_id, $status, 'roster edit', 'user:' . \get_current_user_id() ) ) {
                // Illegal transition — contact fields were still saved, but surface
                // the rejected status change instead of reporting full success (CodeRabbit).
                $this->redirect( $event_id, 'error', \sprintf(
                    /* translators: 1: from status, 2: to status */
                    \__( 'Contact details saved, but the status change from “%1$s” to “%2$s” is not allowed.', 'anchor-schema' ),
                    $current,
                    $status
                ) );
            }
        }

        $this->redirect( $event_id, 'success', \__( 'Seat updated.', 'anchor-schema' ) );
    }

    public function handle_cancel() {
        $event_id = isset( $_REQUEST['event_id'] ) ? (int) \wp_unslash( $_REQUEST['event_id'] ) : 0;
        $seat_id  = isset( $_REQUEST['seat_id'] ) ? (int) \wp_unslash( $_REQUEST['seat_id'] ) : 0;
        $this->guard( 'anchor_roster_cancel_' . $event_id );

        if ( \get_post_type( $seat_id ) !== Module::REG_CPT ) {
            $this->redirect( $event_id, 'error', \__( 'Seat not found.', 'anchor-schema' ) );
        }

        $ok = $this->registrations->update_status( $seat_id, Registrations::STATUS_CANCELLED, 'roster cancel', 'user:' . \get_current_user_id() );
        if ( $ok ) {
            $this->redirect( $event_id, 'success', \__( 'Seat cancelled.', 'anchor-schema' ) );
        }
        $this->redirect( $event_id, 'error', \__( 'Could not cancel this seat.', 'anchor-schema' ) );
    }

    /** Capability + nonce gate shared by every manual action. */
    private function guard( $nonce_action ) {
        if ( ! \current_user_can( self::CAP ) ) {
            \wp_die( \esc_html__( 'Unauthorized', 'anchor-schema' ) );
        }
        \check_admin_referer( $nonce_action );
    }

    private function redirect( $event_id, $type, $message ) {
        $url = $this->roster_url( (int) $event_id, [
            'roster_msg'  => \rawurlencode( $message ),
            'roster_type' => ( $type === 'error' ? 'error' : 'success' ),
        ] );
        \wp_safe_redirect( $url );
        exit;
    }

    private function maybe_render_notice() {
        if ( empty( $_GET['roster_msg'] ) ) {
            return;
        }
        $msg  = \sanitize_text_field( \rawurldecode( \wp_unslash( $_GET['roster_msg'] ) ) );
        $type = ( isset( $_GET['roster_type'] ) && \wp_unslash( $_GET['roster_type'] ) === 'error' ) ? 'error' : 'success';
        if ( $msg === '' ) {
            return;
        }
        echo '<div class="notice notice-' . ( $type === 'error' ? 'error' : 'success' ) . ' is-dismissible"><p>'
            . \esc_html( $msg ) . '</p></div>';
    }

    /* ---------------------------------------------------------------------
     * CSV export (re-homed from Module — spec §10.4)
     * ------------------------------------------------------------------- */

    public function handle_export() {
        if ( ! \current_user_can( self::CAP ) ) {
            \wp_die( \esc_html__( 'Unauthorized', 'anchor-schema' ) );
        }
        \check_admin_referer( 'anchor_event_export' );

        $event_id = isset( $_GET['event_id'] ) ? (int) \wp_unslash( $_GET['event_id'] ) : 0;
        if ( ! $event_id ) {
            \wp_die( \esc_html__( 'Missing event.', 'anchor-schema' ) );
        }
        $scope = ( isset( $_GET['scope'] ) && \wp_unslash( $_GET['scope'] ) === 'active' ) ? 'active' : 'all';

        $data       = $this->registrations->get_export_rows( $event_id, $scope );
        $field_keys = $data['field_keys'];

        \nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="event-roster-' . $event_id . '-' . $scope . '-' . \gmdate( 'Ymd' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );

        $base_cols = [
            \__( 'Seat ID', 'anchor-schema' ),
            \__( 'Event', 'anchor-schema' ),
            \__( 'Attendee Name', 'anchor-schema' ),
            \__( 'Email', 'anchor-schema' ),
            \__( 'Phone', 'anchor-schema' ),
            \__( 'Status', 'anchor-schema' ),
            \__( 'Source', 'anchor-schema' ),
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
        $header = \array_merge( $base_cols, $field_keys );
        fputcsv( $out, \array_map( [ $this, 'csv_safe' ], $header ) );

        foreach ( $data['rows'] as $row ) {
            $cells = [
                $row['seat_id'], $row['event'], $row['name'], $row['email'], $row['phone'],
                $row['status'], $row['source'], $row['guests'], $row['party_size'], $row['reg_date'],
                $row['order_number'], $row['order_id'], $row['order_status'], $row['order_date'],
                $row['customer_id'], $row['customer_email'], $row['product'], $row['product_id'],
                $row['variation_id'], $row['order_item_id'], $row['seat_index'],
            ];
            foreach ( $field_keys as $k ) {
                $cells[] = isset( $row['fields'][ $k ] ) ? $row['fields'][ $k ] : '';
            }
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

    /** Status options for the edit select. */
    public function status_options() {
        return [
            Registrations::STATUS_CONFIRMED => \__( 'Confirmed', 'anchor-schema' ),
            Registrations::STATUS_PENDING   => \__( 'Pending', 'anchor-schema' ),
            Registrations::STATUS_WAITLIST  => \__( 'Waitlist', 'anchor-schema' ),
            Registrations::STATUS_CANCELLED => \__( 'Cancelled', 'anchor-schema' ),
            Registrations::STATUS_REFUNDED  => \__( 'Refunded', 'anchor-schema' ),
            Registrations::STATUS_FAILED    => \__( 'Failed', 'anchor-schema' ),
        ];
    }

    /** Human label for a status. */
    public function status_label( $status ) {
        $opts = $this->status_options();
        return $opts[ $status ] ?? \ucfirst( (string) $status );
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
}

/* =========================================================================
 * Roster list table — declared only in admin where WP_List_Table is available.
 * ========================================================================= */
if ( \is_admin() ) {
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

            public function get_columns() {
                return [
                    'cb'       => '<input type="checkbox" />',
                    'attendee' => \__( 'Attendee', 'anchor-schema' ),
                    'email'    => \__( 'Email', 'anchor-schema' ),
                    'phone'    => \__( 'Phone', 'anchor-schema' ),
                    'status'   => \__( 'Status', 'anchor-schema' ),
                    'guests'   => \__( 'Guests', 'anchor-schema' ),
                    'source'   => \__( 'Source', 'anchor-schema' ),
                    'order'    => \__( 'Order', 'anchor-schema' ),
                    'seat'     => \__( 'Seat', 'anchor-schema' ),
                    'date'     => \__( 'Date', 'anchor-schema' ),
                ];
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

            public function column_cb( $item ) {
                return '<input type="checkbox" name="seat[]" value="' . (int) $item['id'] . '" />';
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

            public function column_default( $item, $column_name ) {
                switch ( $column_name ) {
                    case 'email':
                        return \esc_html( $item['email'] );
                    case 'phone':
                        return \esc_html( $item['phone'] );
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
