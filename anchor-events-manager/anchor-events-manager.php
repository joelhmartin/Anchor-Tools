<?php
namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {
    const CPT = 'event';
    const REG_CPT = 'anchor_event_reg';
    const OPTION_KEY = 'anchor_events_settings';
    const CACHE_OPTION = 'anchor_events_cache_keys';
    const NONCE = 'anchor_event_meta_nonce';
    const REG_NONCE = 'anchor_event_reg_nonce';

    private static $instance = null;
    private $assets_enqueued = false;

    /** @var Registrations Seat data-access layer (always loaded). */
    public $registrations = null;

    /** @var WooCommerce|null WC integration; null when WooCommerce is inactive. */
    public $woocommerce = null;

    /** @var Product_Sync|null Event→product sync; null when WooCommerce is inactive. */
    public $product_sync = null;

    /** @var Roster|null Roster admin screen + CSV export (always loaded). */
    public $roster = null;

    /** @var Ticket_Types|null Per-event ticket-tier model (always loaded). */
    public $ticket_types = null;

    /** @var Series|null Event-series taxonomy + archive (always loaded). */
    public $series = null;

    /** @var Occurrences|null Parent→child offering-dates reconcile engine (Phase 2, Task 2.1; always loaded). */
    public $occurrences = null;

    /** @var int[] Seat ids queued for a cancellation email this request. */
    private $pending_cancellation_emails = [];

    public function __construct() {
        self::$instance = $this;

        // Always-on data layer (spec §3, approach B). WC-gated classes load in Phase 1.
        $dir = \plugin_dir_path( __FILE__ );
        require_once $dir . 'class-events-log.php';
        require_once $dir . 'class-registrations.php';
        require_once $dir . 'class-roster.php';
        require_once $dir . 'class-ticket-types.php';
        require_once $dir . 'class-series.php';
        require_once $dir . 'class-occurrences.php';
        $this->registrations = new Registrations( $this );
        // Roster is loaded unconditionally (free + paid) — spec §3 / finding #25.
        $this->roster = new Roster( $this );
        // Ticket-tier model (spec §3.2) — free + paid; no WooCommerce dependency.
        $this->ticket_types = new Ticket_Types( $this );
        // Series taxonomy + archive (spec §3.3, §6) — free + paid; registers the
        // `event_series` taxonomy on `init` and renders the series landing page.
        $this->series = new Series( $this );
        // Occurrences engine (Phase 2, Task 2.1) — free + paid; group-parent →
        // child-event reconcile for "Pick-one offerings". No hooks of its own;
        // driven explicitly (metabox wiring is a later task).
        $this->occurrences = new Occurrences( $this );

        // WC-gated integration loader (spec §3). Loads only when WooCommerce is
        // active; $this->woocommerce stays null otherwise and is never dereferenced.
        if ( \class_exists( 'WooCommerce' ) ) {
            require_once $dir . 'class-woocommerce.php';
            $this->woocommerce = new WooCommerce( $this, $this->registrations );
            // Event→managed-product sync (spec §4–5). Constructed only when WC is
            // active; depends on the always-loaded Ticket_Types model.
            require_once $dir . 'class-product-sync.php';
            $this->product_sync = new Product_Sync( $this, $this->ticket_types );
        }

        \add_action( 'init', [ $this, 'register_cpt' ] );
        \add_action( 'init', [ $this, 'register_taxonomies' ] );
        \add_action( 'init', [ $this, 'register_registration_cpt' ] );
        \add_action( 'init', [ $this, 'register_meta' ] );

        \add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
        \add_action( 'save_post_' . self::CPT, [ $this, 'save_meta' ] );
        \add_action( 'transition_post_status', [ $this, 'persist_status_on_transition' ], 10, 3 );

        \add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        \add_action( 'wp_enqueue_scripts', [ $this, 'frontend_assets' ] );

        \add_filter( 'manage_' . self::CPT . '_posts_columns', [ $this, 'columns' ] );
        \add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
        \add_filter( 'manage_edit-' . self::CPT . '_sortable_columns', [ $this, 'sortable_columns' ] );
        \add_filter( 'post_row_actions', [ $this, 'event_row_actions' ], 10, 2 );
        \add_action( 'pre_get_posts', [ $this, 'admin_sorting' ] );
        \add_filter( 'views_edit-' . self::CPT, [ $this, 'add_quick_filters' ] );
        \add_action( 'pre_get_posts', [ $this, 'apply_quick_filters' ] );
        \add_action( 'pre_get_posts', [ $this, 'filter_archive_query' ] );

        \add_filter( 'template_include', [ $this, 'template_include' ] );

        \add_shortcode( 'events_list', [ $this, 'shortcode_events_list' ] );
        \add_shortcode( 'event_calendar', [ $this, 'shortcode_event_calendar' ] );
        \add_shortcode( 'featured_events', [ $this, 'shortcode_featured_events' ] );
        \add_shortcode( 'event_registration', [ $this, 'shortcode_event_registration' ] );
        \add_shortcode( 'event_gallery', [ $this, 'shortcode_event_gallery' ] );
        \add_shortcode( 'event_registrants_list', [ $this, 'shortcode_event_registrants_list' ] );
        \add_shortcode( 'event_manager', [ $this, 'shortcode_event_manager' ] );

        \add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 40 );
        \add_action( 'admin_init', [ $this, 'register_settings' ] );
        \add_action( 'admin_notices', [ $this, 'admin_notices' ] );

        \add_action( 'admin_post_anchor_event_register', [ $this, 'handle_registration' ] );
        \add_action( 'admin_post_nopriv_anchor_event_register', [ $this, 'handle_registration' ] );
        // NOTE: `anchor_event_export` (CSV export) is now owned by Roster (Phase 5).
        \add_action( 'admin_post_anchor_event_manager_save', [ $this, 'handle_event_manager_save' ] );
        \add_action( 'admin_post_anchor_event_manager_delete', [ $this, 'handle_event_manager_delete' ] );
        \add_action( 'admin_post_nopriv_anchor_event_manager_login', [ $this, 'handle_event_manager_login' ] );
        \add_action( 'admin_post_anchor_event_manager_login', [ $this, 'handle_event_manager_login' ] );
        \add_action( 'admin_post_anchor_event_manager_logout', [ $this, 'handle_event_manager_logout' ] );
        \add_action( 'admin_post_nopriv_anchor_event_manager_lostpass', [ $this, 'handle_event_manager_lostpass' ] );
        \add_action( 'admin_post_anchor_event_manager_lostpass', [ $this, 'handle_event_manager_lostpass' ] );
        \add_action( 'wp_ajax_anchor_events_calendar', [ $this, 'ajax_calendar' ] );
        \add_action( 'wp_ajax_nopriv_anchor_events_calendar', [ $this, 'ajax_calendar' ] );

        \add_action( 'update_option_' . self::OPTION_KEY, [ $this, 'handle_settings_update' ], 10, 2 );
        \add_action( 'before_delete_post', [ $this, 'clear_caches_on_delete' ] );

        // SEO: Add canonical URL for calendar month parameter pages
        \add_action( 'wp_head', [ $this, 'output_canonical_url' ], 1 );
        \add_filter( 'wpseo_canonical', [ $this, 'filter_yoast_canonical' ] );
        \add_filter( 'rank_math/frontend/canonical', [ $this, 'filter_yoast_canonical' ] );

        // Status sweep cron (bug #2): scheduled defensively on init so it survives
        // plugin upgrades (which don't fire register_activation_hook), plus on
        // activation for fresh installs; cleared on deactivation.
        \add_action( 'init', [ $this, 'maybe_schedule_status_sweep' ] );
        \add_action( 'anchor_events_status_sweep', [ $this, 'run_status_sweep' ] );

        // v1.1: reminder + scheduled-roster sweep (spec §5). Hourly, scheduled
        // defensively on init so it survives plugin upgrades (no activation hook).
        \add_action( 'init', [ $this, 'maybe_schedule_reminder_sweep' ] );
        \add_action( 'anchor_events_reminder_sweep', [ $this, 'run_reminder_sweep' ] );

        if ( \defined( 'ANCHOR_TOOLS_PLUGIN_FILE' ) ) {
            \register_deactivation_hook( ANCHOR_TOOLS_PLUGIN_FILE, [ $this, 'on_deactivate' ] );
        }

        // Bug #5: capture wp_mail failures into the events error log.
        \add_action( 'wp_mail_failed', [ $this, 'capture_mail_failure' ] );

        // Phase 6: clear the site-wide error log (Events settings tab panel). Lives
        // here (not the WC class) because the error log exists on all sites.
        \add_action( 'admin_post_anchor_events_clear_error_log', [ $this, 'handle_clear_error_log' ] );

        // L14: GDPR personal-data exporter + eraser for attendee PII stored on seats.
        \add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_privacy_exporter' ] );
        \add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_privacy_eraser' ] );

        // v1.1: attendee cancellation/refund email (spec §7). Enqueue on transition,
        // flush after the event lock releases (shutdown) so no wp_mail runs under GET_LOCK.
        \add_action( 'anchor_events_seat_status_changed', [ $this, 'on_seat_status_changed' ], 10, 4 );
        \add_action( 'shutdown', [ $this, 'flush_cancellation_emails' ] );
    }

    /**
     * Schedule the daily status sweep if not already scheduled. Hooked to `init`
     * so already-active installs (upgraded via Plugin Update Checker, which does
     * not fire activation hooks) still get the cron registered.
     */
    public function maybe_schedule_status_sweep() {
        if ( ! \wp_next_scheduled( 'anchor_events_status_sweep' ) ) {
            \wp_schedule_event( \time() + HOUR_IN_SECONDS, 'daily', 'anchor_events_status_sweep' );
        }
    }

    /**
     * Schedule the hourly reminder sweep if not already scheduled. Hooked to `init`
     * so already-active installs (upgraded via Plugin Update Checker) still get the
     * cron registered without needing an activation hook.
     */
    public function maybe_schedule_reminder_sweep() {
        if ( ! \wp_next_scheduled( 'anchor_events_reminder_sweep' ) ) {
            \wp_schedule_event( \time() + HOUR_IN_SECONDS, 'hourly', 'anchor_events_reminder_sweep' );
        }
    }

    /** Clear scheduled crons on plugin deactivation. */
    public function on_deactivate() {
        $timestamp = \wp_next_scheduled( 'anchor_events_status_sweep' );
        if ( $timestamp ) {
            \wp_unschedule_event( $timestamp, 'anchor_events_status_sweep' );
        }
        \wp_clear_scheduled_hook( 'anchor_events_status_sweep' );

        $rts = \wp_next_scheduled( 'anchor_events_reminder_sweep' );
        if ( $rts ) {
            \wp_unschedule_event( $rts, 'anchor_events_reminder_sweep' );
        }
        \wp_clear_scheduled_hook( 'anchor_events_reminder_sweep' );
    }

    /**
     * Recompute and persist auto-mode event statuses. Replaces the former
     * write-on-read in get_event_status() (bug #2).
     */
    /**
     * Persist auto-mode event status when a post's status transitions (covers
     * quick-edit / bulk publish where save_meta's nonce check returns early).
     *
     * @param string   $new_status
     * @param string   $old_status
     * @param \WP_Post $post
     */
    public function persist_status_on_transition( $new_status, $old_status, $post ) {
        if ( ! $post instanceof \WP_Post || $post->post_type !== self::CPT ) {
            return;
        }
        if ( $new_status === 'auto-draft' || ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
            return;
        }
        $meta = $this->get_meta( $post->ID );
        if ( $meta['status_mode'] === 'manual' ) {
            return;
        }
        $computed = $this->calculate_status( $meta );
        if ( $computed !== $meta['status'] ) {
            \update_post_meta( $post->ID, $this->meta_key( 'status' ), $computed );
        }
    }

    public function run_status_sweep() {
        // L9: on_deactivate (which unschedules this recurring cron) is registered in
        // the constructor, which never runs when the events_manager module is toggled
        // off. If the event CPT isn't registered the module is effectively unavailable
        // — self-unschedule so we don't leave an orphaned recurring event running with
        // a no-op callback.
        if ( ! \post_type_exists( self::CPT ) ) {
            $this->on_deactivate();
            return;
        }
        $events = \get_posts( [
            'post_type'      => self::CPT,
            'post_status'    => [ 'publish', 'future', 'private' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'OR',
                // Legacy auto-mode events have no status_mode meta row yet — include
                // them so their persisted status doesn't go stale (CodeRabbit).
                [ 'key' => $this->meta_key( 'status_mode' ), 'compare' => 'NOT EXISTS' ],
                [ 'key' => $this->meta_key( 'status_mode' ), 'value' => 'manual', 'compare' => '!=' ],
            ],
        ] );
        foreach ( $events as $event_id ) {
            $meta = $this->get_meta( $event_id );
            $computed = $this->calculate_status( $meta );
            if ( $computed !== $meta['status'] ) {
                \update_post_meta( $event_id, $this->meta_key( 'status' ), $computed );
            }
        }
        $this->clear_caches();
    }

    /* ---------------------------------------------------------------------
     * v1.1: Pre-event reminder sweep (spec §5)
     * ------------------------------------------------------------------- */

    /**
     * Resolve effective reminder offsets for an event: per-event override CSV
     * takes priority; falls back to the global setting. Returns sorted unique
     * positive integers descending.
     *
     * @param int        $event_id
     * @param array      $settings
     * @param array|null $meta     Pre-loaded event meta; loaded if not supplied.
     * @return int[]
     */
    private function effective_offsets( $event_id, array $settings, $meta = null ) {
        if ( ! \is_array( $meta ) ) {
            $meta = $this->get_meta( (int) $event_id );
        }
        $csv  = ! empty( $meta['reminder_offsets'] ) ? $meta['reminder_offsets'] : $settings['reminder_offsets'];
        $days = array_filter( array_map( 'intval', explode( ',', (string) $csv ) ), function ( $d ) { return $d > 0; } );
        rsort( $days );
        return array_values( array_unique( $days ) );
    }

    /**
     * Hourly cron callback: send pre-event reminder emails and hand off to the
     * scheduled-roster pass (Task 4). Mirrors run_status_sweep() defensively:
     * self-unschedules if the CPT is absent (module toggled off).
     */
    public function run_reminder_sweep() {
        if ( ! \post_type_exists( self::CPT ) ) {
            $this->on_deactivate(); // self-heal like run_status_sweep()
            return;
        }
        $settings = $this->get_settings();
        $now      = \time();

        if ( empty( $settings['reminder_enabled'] ) && empty( $settings['organizer_roster_email'] ) ) {
            return; // nothing to do
        }

        // Bound the scan to imminent events: start_ts in (now, now + max_offset].
        $max_global = 0;
        foreach ( array_map( 'intval', explode( ',', (string) $settings['reminder_offsets'] ) ) as $d ) {
            $max_global = max( $max_global, $d );
        }
        $max_global = max( $max_global, (int) $settings['roster_auto_offset'] );

        // Fold in per-event reminder override offsets so events whose largest
        // per-event offset exceeds $max_global are still pulled into the scan.
        if ( ! empty( $settings['reminder_enabled'] ) ) {
            $override_events = \get_posts( [
                'post_type'      => self::CPT,
                'post_status'    => [ 'publish', 'future', 'private' ],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [ [ 'key' => $this->meta_key( 'reminder_offsets' ), 'value' => '', 'compare' => '!=' ] ],
            ] );
            foreach ( $override_events as $oid ) {
                foreach ( array_map( 'intval', explode( ',', (string) \get_post_meta( $oid, $this->meta_key( 'reminder_offsets' ), true ) ) ) as $d ) {
                    $max_global = max( $max_global, $d );
                }
            }
        }

        $horizon    = $now + ( max( 1, $max_global ) * DAY_IN_SECONDS );

        $event_ids = \get_posts( [
            'post_type'      => self::CPT,
            'post_status'    => [ 'publish', 'future', 'private' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [ 'key' => $this->meta_key( 'start_ts' ), 'value' => [ $now, $horizon ], 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ],
            ],
        ] );

        foreach ( $event_ids as $event_id ) {
            $meta     = $this->get_meta( $event_id );
            $start_ts = (int) ( $meta['start_ts'] ?? 0 );
            if ( $start_ts <= $now ) {
                continue; // already started
            }

            // --- Reminder pass ---
            if ( ! empty( $settings['reminder_enabled'] ) ) {
                foreach ( $this->effective_offsets( $event_id, $settings, $meta ) as $offset ) {
                    if ( ! ( ( $start_ts - $offset * DAY_IN_SECONDS ) <= $now && $now < $start_ts ) ) {
                        continue; // offset not due this sweep
                    }
                    $seats = $this->registrations->query_seats( [
                        'event_id' => $event_id,
                        'status'   => \Anchor\Events\Registrations::STATUS_CONFIRMED,
                        'per_page' => -1,
                    ] );
                    foreach ( $seats['items'] as $seat ) {
                        $sent_map = \get_post_meta( $seat['id'], '_anchor_event_reminders_sent', true );
                        if ( ! \is_array( $sent_map ) ) {
                            $sent_map = [];
                        }
                        if ( isset( $sent_map[ $offset ] ) ) {
                            continue; // already sent this offset
                        }
                        if ( ! \apply_filters( 'anchor_events_should_send_reminder', true, $seat, $offset ) ) {
                            continue;
                        }
                        if ( $this->send_reminder_email( $seat, $event_id, $offset, $settings ) ) {
                            $sent_map[ $offset ] = $now;
                            \update_post_meta( $seat['id'], '_anchor_event_reminders_sent', $sent_map );
                            \update_post_meta( $seat['id'], '_anchor_event_attendee_notified', true );
                        }
                    }
                }
            }

            // --- Scheduled roster pass (implemented in Task 4) ---
            $this->maybe_send_scheduled_roster( $event_id, $meta, $settings, $now );
        }
    }

    /**
     * Send a pre-event reminder email to a single confirmed seat.
     *
     * @param array      $seat     Seat DTO from query_seats().
     * @param int        $event_id
     * @param int        $offset   Days-before-start offset being sent.
     * @param array|null $settings Pre-resolved settings; loaded if not supplied.
     * @return bool True on successful send.
     */
    public function send_reminder_email( array $seat, $event_id, $offset, $settings = null ) {
        if ( empty( $seat['email'] ) ) {
            return false;
        }
        if ( ! \is_array( $settings ) ) {
            $settings = $this->get_settings();
        }
        $tokens   = $this->email_tokens( [ 'event_id' => (int) $event_id, 'seat' => $seat ] );
        $subject  = $this->expand_email_tokens( $settings['reminder_subject'], $tokens );
        $intro    = $this->expand_email_tokens( $settings['reminder_intro'], $tokens );

        $detail_rows = [];
        if ( $tokens['event_date'] !== '' ) {
            $detail_rows[] = [ 'label' => \__( 'Date', 'anchor-schema' ), 'value' => $tokens['event_date'] ];
        }
        if ( $tokens['event_time'] !== '' ) {
            $detail_rows[] = [ 'label' => \__( 'Time', 'anchor-schema' ), 'value' => $tokens['event_time'] ];
        }
        if ( $tokens['venue'] !== '' ) {
            $detail_rows[] = [ 'label' => \__( 'Location', 'anchor-schema' ), 'value' => $tokens['venue'] ];
        }

        $ctx = [
            'event_id'      => (int) $event_id,
            'name'          => (string) $seat['name'],
            'status'        => \Anchor\Events\Registrations::STATUS_CONFIRMED, // enables join button for virtual
            'intro_message' => $intro,
            'detail_rows'   => $detail_rows,
            'cta_label'     => \__( 'View event details', 'anchor-schema' ),
            'cta_url'       => $tokens['event_url'],
        ];
        $html = $this->build_registration_email_html( $ctx );
        return $this->send_html_email( (string) $seat['email'], $subject, $html );
    }

    /** Build + send the organizer roster digest (confirmed attendees + counts). */
    public function send_roster_email( $event_id ) {
        $event_id = (int) $event_id;
        if ( \get_post_type( $event_id ) !== self::CPT ) {
            return false;
        }
        $settings = $this->get_settings();
        $to       = $this->resolve_organizer_email( $event_id, $settings );
        if ( $to === '' ) {
            return false;
        }
        $summary = $this->registrations->get_event_summary( $event_id );
        $seats   = $this->registrations->query_seats( [
            'event_id' => $event_id,
            'status'   => \Anchor\Events\Registrations::STATUS_CONFIRMED,
            'per_page' => -1,
        ] );
        $cap         = isset( $summary['capacity'] ) ? (int) $summary['capacity'] : 0;
        $remaining   = isset( $summary['remaining'] ) && (int) $summary['remaining'] >= 0
            ? (string) (int) $summary['remaining']
            : \__( 'Unlimited', 'anchor-schema' );
        // Pass the already-computed remaining so email_tokens() doesn't recount.
        $tokens  = $this->email_tokens( [ 'event_id' => $event_id, 'seat_count' => count( $seats['items'] ), 'remaining' => $remaining ] );
        $subject = $this->expand_email_tokens( $settings['roster_subject'], $tokens );
        $intro   = $this->expand_email_tokens( $settings['roster_intro'], $tokens );

        $detail_rows = [
            [ 'label' => \__( 'Date', 'anchor-schema' ),      'value' => $tokens['event_date'] ],
            [ 'label' => \__( 'Venue', 'anchor-schema' ),     'value' => $tokens['venue'] ],
            [ 'label' => \__( 'Capacity', 'anchor-schema' ),  'value' => $cap ? (string) $cap : \__( 'Unlimited', 'anchor-schema' ) ],
            [ 'label' => \__( 'Confirmed', 'anchor-schema' ), 'value' => (string) (int) ( $summary['confirmed'] ?? 0 ) ],
            [ 'label' => \__( 'Waitlist', 'anchor-schema' ),  'value' => (string) (int) ( $summary['waitlist'] ?? 0 ) ],
            [ 'label' => \__( 'Remaining', 'anchor-schema' ), 'value' => $remaining ],
        ];
        $seat_list = [];
        foreach ( $seats['items'] as $s ) {
            $name  = $s['name'] !== '' ? $s['name'] : \__( 'Guest', 'anchor-schema' );
            $line  = $name . ' — ' . $s['email'];
            if ( ! empty( $s['phone'] ) ) { $line .= ' — ' . $s['phone']; }
            if ( ! empty( $s['source'] ) ) { $line .= ' (' . $s['source'] . ')'; }
            $seat_list[] = $line;
        }
        $ctx = [
            'event_id'      => $event_id,
            'name'          => '',
            'status'        => \Anchor\Events\Registrations::STATUS_CONFIRMED,
            'intro_message' => $intro,
            'detail_rows'   => $detail_rows,
            'seat_list'     => $seat_list,
            'cta_label'     => \__( 'Open full roster', 'anchor-schema' ),
            'cta_url'       => ( $this->roster && \method_exists( $this->roster, 'roster_url' ) )
                ? $this->roster->roster_url( $event_id )
                : \get_permalink( $event_id ),
        ];
        $html = $this->build_registration_email_html( $ctx );
        return $this->send_html_email( $to, $subject, $html );
    }

    /**
     * Scheduled roster pass — called by the hourly reminder sweep (Task 3).
     * Sends the organizer digest if the auto-offset window is active and the digest
     * has not already been sent for this event.
     */
    public function maybe_send_scheduled_roster( $event_id, $meta, $settings, $now ) {
        if ( empty( $settings['organizer_roster_email'] ) ) {
            return;
        }
        $start_ts = (int) ( $meta['start_ts'] ?? 0 );
        $offset   = (int) $settings['roster_auto_offset'];
        if ( ! ( ( $start_ts - $offset * DAY_IN_SECONDS ) <= $now && $now < $start_ts ) ) {
            return; // not due
        }
        if ( (int) ( $meta['roster_sent'] ?? 0 ) > 0 ) {
            return; // already sent
        }
        if ( $this->send_roster_email( $event_id ) ) {
            \update_post_meta( $event_id, $this->meta_key( 'roster_sent' ), $now );
        }
    }

    /**
     * wp_mail_failed handler (bug #5) — logs the failure to the events error log.
     *
     * @param \WP_Error $error
     */
    public function capture_mail_failure( $error ) {
        if ( \is_wp_error( $error ) ) {
            Events_Log::error( 'email_failed', [
                'message' => $error->get_error_message(),
                'data'    => $error->get_error_data(),
            ] );
        }
    }

    /**
     * Send an HTML email and log any failure (bug #5). Centralizes the two
     * registration wp_mail calls; the full email refactor lands in Phase 6.
     *
     * @return bool True on success.
     */
    public function send_html_email( $to, $subject, $html, $headers = [] ) {
        if ( empty( $headers ) ) {
            // Apply the configured event sender identity (From / Reply-To / BCC).
            $headers = $this->email_headers( [ 'Content-Type: text/html; charset=UTF-8' ] );
        } else {
            // Caller supplied headers explicitly (e.g. Bcc) — normalize to an array
            // and make sure a text/html Content-Type is present exactly once,
            // without dropping anything the caller passed in.
            $headers = is_array( $headers ) ? $headers : preg_split( "/\r\n|\r|\n/", (string) $headers, -1, PREG_SPLIT_NO_EMPTY );
            $has_content_type = false;
            foreach ( $headers as $header_line ) {
                if ( stripos( trim( (string) $header_line ), 'Content-Type:' ) === 0 ) {
                    $has_content_type = true;
                    break;
                }
            }
            if ( ! $has_content_type ) {
                array_unshift( $headers, 'Content-Type: text/html; charset=UTF-8' );
            }
        }
        $sent = \wp_mail( $to, $subject, $html, $headers );
        if ( ! $sent ) {
            Events_Log::error( 'email_send_returned_false', [ 'to' => $to, 'subject' => $subject ] );
        }
        return (bool) $sent;
    }

    /**
     * Build the per-message header lines that carry the configured event email
     * sender identity (From / Reply-To / BCC). Each header is emitted only when a
     * valid address is configured; blank settings fall back to WordPress defaults.
     * This only sets headers — actual delivery still relies on the site's mail
     * service (Mailgun, WP Mail SMTP, etc.), which may override the From address.
     *
     * @param array $extra Header lines to prepend (e.g. the Content-Type line).
     * @return array
     */
    public function email_headers( array $extra = [] ) {
        $settings = $this->get_settings();
        $headers  = $extra;

        $from_email = \sanitize_email( $settings['email_from_address'] ?? '' );
        if ( $from_email ) {
            $from_name = \sanitize_text_field( $settings['email_from_name'] ?? '' );
            $headers[] = $from_name !== ''
                ? sprintf( 'From: %s <%s>', $this->encode_email_name( $from_name ), $from_email )
                : 'From: ' . $from_email;
        }

        $reply_email = \sanitize_email( $settings['email_reply_to_address'] ?? '' );
        if ( $reply_email ) {
            $reply_name = \sanitize_text_field( $settings['email_reply_to_name'] ?? '' );
            $headers[] = $reply_name !== ''
                ? sprintf( 'Reply-To: %s <%s>', $this->encode_email_name( $reply_name ), $reply_email )
                : 'Reply-To: ' . $reply_email;
        }

        $bcc = \sanitize_email( $settings['email_bcc'] ?? '' );
        if ( $bcc ) {
            $headers[] = 'Bcc: ' . $bcc;
        }

        return $headers;
    }

    /** Quote a display name for an email header if it contains characters that need it. */
    private function encode_email_name( $name ) {
        if ( preg_match( '/[",:;<>@()\[\]\\\\]/', $name ) ) {
            return '"' . str_replace( '"', '', $name ) . '"';
        }
        return $name;
    }

    /* ---------------------------------------------------------------------
     * Privacy: WP personal-data exporter + eraser (L14)
     * ------------------------------------------------------------------- */

    /** Register the attendee-PII exporter with WP Tools > Export Personal Data. */
    public function register_privacy_exporter( $exporters ) {
        $exporters['anchor-events'] = [
            'exporter_friendly_name' => \__( 'Anchor Events registrations', 'anchor-schema' ),
            'callback'               => [ $this, 'privacy_export' ],
        ];
        return $exporters;
    }

    /** Register the attendee-PII eraser with WP Tools > Erase Personal Data. */
    public function register_privacy_eraser( $erasers ) {
        $erasers['anchor-events'] = [
            'eraser_friendly_name' => \__( 'Anchor Events registrations', 'anchor-schema' ),
            'callback'             => [ $this, 'privacy_erase' ],
        ];
        return $erasers;
    }

    /**
     * Exporter callback: return attendee fields for every seat matching the email.
     *
     * @param string $email_address
     * @param int    $page 1-based.
     * @return array{data:array,done:bool}
     */
    public function privacy_export( $email_address, $page = 1 ) {
        $page     = max( 1, (int) $page );
        $per_page = 100;
        $seat_ids = $this->registrations->seats_by_email( $email_address, $page, $per_page );

        $items = [];
        foreach ( $seat_ids as $seat_id ) {
            $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );
            $data     = [
                [ 'name' => \__( 'Event', 'anchor-schema' ), 'value' => \get_the_title( $event_id ) ],
                [ 'name' => \__( 'Name', 'anchor-schema' ), 'value' => (string) \get_post_meta( $seat_id, '_anchor_event_name', true ) ],
                [ 'name' => \__( 'Email', 'anchor-schema' ), 'value' => (string) \get_post_meta( $seat_id, '_anchor_event_email', true ) ],
                [ 'name' => \__( 'Phone', 'anchor-schema' ), 'value' => (string) \get_post_meta( $seat_id, '_anchor_event_phone', true ) ],
                [ 'name' => \__( 'Status', 'anchor-schema' ), 'value' => (string) \get_post_meta( $seat_id, '_anchor_event_reg_status', true ) ],
            ];

            // C: attendee-provided custom registration fields can themselves be PII,
            // so include them in the export (one row per field).
            $reg_fields = \get_post_meta( $seat_id, '_anchor_event_reg_fields', true );
            if ( \is_array( $reg_fields ) ) {
                foreach ( $reg_fields as $field_key => $field_value ) {
                    $value = \is_scalar( $field_value ) ? (string) $field_value : \wp_json_encode( $field_value );
                    $data[] = [
                        'name'  => (string) $field_key,
                        'value' => (string) $value,
                    ];
                }
            }

            $items[] = [
                'group_id'    => 'anchor_event_registrations',
                'group_label' => \__( 'Event Registrations', 'anchor-schema' ),
                'item_id'     => 'anchor-event-seat-' . (int) $seat_id,
                'data'        => $data,
            ];
        }

        return [
            'data' => $items,
            'done' => \count( $seat_ids ) < $per_page,
        ];
    }

    /**
     * Eraser callback: anonymize attendee PII on every seat matching the email.
     * The seat record + status/history are retained for capacity + audit.
     *
     * @param string $email_address
     * @param int    $page 1-based.
     * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
     */
    public function privacy_erase( $email_address, $page = 1 ) {
        $per_page = 100;
        // B: anonymize_seat() clears _anchor_event_email, so the matching set shrinks
        // between eraser calls. Always pull PAGE 1 of the remaining unscrubbed
        // records — paging with $page > 1 would skip records as the set contracts.
        $seat_ids = $this->registrations->seats_by_email( $email_address, 1, $per_page );

        foreach ( $seat_ids as $seat_id ) {
            $this->registrations->anonymize_seat( $seat_id );
        }

        return [
            // Seats are retained with PII scrubbed (kept for capacity + audit), not
            // physically deleted — so nothing is "removed", everything is "retained".
            'items_removed'  => false,
            'items_retained' => ! empty( $seat_ids ),
            'messages'       => [],
            'done'           => \count( $seat_ids ) < $per_page,
        ];
    }

    public static function instance() {
        return self::$instance;
    }

    /**
     * Get the canonical URL for the current page (without calendar month parameters).
     * This prevents search engines from indexing each month view as a separate page.
     *
     * @return string|false The canonical URL or false if not applicable.
     */
    private function get_canonical_url() {
        if ( ! isset( $_GET['anchor_events_month'] ) ) {
            return false;
        }

        // Get the current URL without query parameters
        global $wp;
        $canonical = \home_url( $wp->request );

        // Preserve other query parameters except anchor_events_month
        $query_params = $_GET;
        unset( $query_params['anchor_events_month'] );

        if ( ! empty( $query_params ) ) {
            $canonical = \add_query_arg( $query_params, $canonical );
        }

        // Ensure trailing slash consistency
        if ( \trailingslashit( \home_url() ) !== \home_url() . '/' ) {
            $canonical = \untrailingslashit( $canonical );
        } else {
            $canonical = \trailingslashit( $canonical );
        }

        return $canonical;
    }

    /**
     * Output canonical URL in wp_head for pages with calendar month parameter.
     * This serves as a fallback if no SEO plugin outputs a canonical tag.
     */
    public function output_canonical_url() {
        $canonical = $this->get_canonical_url();
        if ( ! $canonical ) {
            return;
        }

        // Output the canonical tag - SEO plugin filters will override if present
        echo '<link rel="canonical" href="' . \esc_url( $canonical ) . '" />' . "\n";
    }

    /**
     * Filter Yoast SEO and Rank Math canonical URL for calendar month pages.
     *
     * @param string $canonical The canonical URL.
     * @return string The filtered canonical URL.
     */
    public function filter_yoast_canonical( $canonical ) {
        $our_canonical = $this->get_canonical_url();
        if ( $our_canonical ) {
            return $our_canonical;
        }
        return $canonical;
    }

    public function register_cpt() {
        $settings = $this->get_settings();
        $slug = sanitize_title( $settings['event_slug'] );
        if ( ! $slug ) {
            $slug = 'event';
        }

        $labels = [
            'name'               => __( 'Anchor Events', 'anchor-schema' ),
            'singular_name'      => __( 'Anchor Event', 'anchor-schema' ),
            'add_new_item'       => __( 'Add New Event', 'anchor-schema' ),
            'edit_item'          => __( 'Edit Event', 'anchor-schema' ),
            'new_item'           => __( 'New Event', 'anchor-schema' ),
            'view_item'          => __( 'View Event', 'anchor-schema' ),
            'search_items'       => __( 'Search Events', 'anchor-schema' ),
            'not_found'          => __( 'No events found.', 'anchor-schema' ),
            'not_found_in_trash' => __( 'No events found in Trash.', 'anchor-schema' ),
            'menu_name'          => __( 'Anchor Events', 'anchor-schema' ),
        ];

        \register_post_type( self::CPT, [
            'labels' => $labels,
            'public' => true,
            'has_archive' => true,
            'rewrite' => [ 'slug' => $slug ],
            'show_in_rest' => true,
            'supports' => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
            'menu_icon' => 'dashicons-calendar-alt',
        ] );
    }

    public function register_taxonomies() {
        \register_taxonomy( 'event_category', self::CPT, [
            'label' => __( 'Event Categories', 'anchor-schema' ),
            'hierarchical' => true,
            'show_in_rest' => true,
            'rewrite' => [ 'slug' => 'event-category' ],
        ] );

        \register_taxonomy( 'event_tag', self::CPT, [
            'label' => __( 'Event Tags', 'anchor-schema' ),
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite' => [ 'slug' => 'event-tag' ],
        ] );

        \register_taxonomy( 'event_type', self::CPT, [
            'label' => __( 'Event Types', 'anchor-schema' ),
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite' => [ 'slug' => 'event-type' ],
        ] );
    }

    public function register_registration_cpt() {
        \register_post_type( self::REG_CPT, [
            'labels' => [
                'name' => __( 'Event Registrations', 'anchor-schema' ),
                'singular_name' => __( 'Event Registration', 'anchor-schema' ),
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => true,
            'supports' => [ 'title' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ] );
    }

    public function register_meta() {
        // One-time back-compat migration (Task 1.1+1.2): derives registration_mode
        // for events that predate the key. Flag-guarded, safe on every init.
        $this->migrate_registration_mode();

        // Protected (underscore-prefixed) meta keys require an explicit auth_callback
        // for REST writes, otherwise Gutenberg's meta save path fails with
        // "not allowed to edit the _anchor_event_* custom field" on publish.
        $event_auth_callback = function ( $allowed, $meta_key, $post_id ) {
            return \current_user_can( 'edit_post', $post_id );
        };

        foreach ( $this->get_meta_schema() as $key => $schema ) {
            \register_post_meta( self::CPT, $this->meta_key( $key ), array_merge( [
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => $event_auth_callback,
            ], $schema ) );
        }

        // Ticket-tier list (spec §3.2). Structured array; managed by the
        // Ticket_Types model + the Tickets / Pricing metabox, not REST.
        \register_post_meta( self::CPT, Ticket_Types::META_KEY, [
            'type'          => 'array',
            'single'        => true,
            'show_in_rest'  => false,
            'auth_callback' => $event_auth_callback,
        ] );

        $reg_auth_callback = function ( $allowed, $meta_key, $post_id ) {
            return \current_user_can( 'edit_post', $post_id );
        };

        \register_post_meta( self::REG_CPT, '_anchor_event_id', [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $reg_auth_callback,
        ] );
        \register_post_meta( self::REG_CPT, '_anchor_event_name', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $reg_auth_callback,
        ] );
        \register_post_meta( self::REG_CPT, '_anchor_event_email', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $reg_auth_callback,
        ] );
        \register_post_meta( self::REG_CPT, '_anchor_event_reg_status', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $reg_auth_callback,
        ] );
        // Internal custom-field values — keep out of REST to avoid the
        // "array meta without schema items" notice (and it isn't needed there).
        \register_post_meta( self::REG_CPT, '_anchor_event_reg_fields', [
            'type' => 'array',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => $reg_auth_callback,
        ] );
        \register_post_meta( self::REG_CPT, '_anchor_event_guests', [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $reg_auth_callback,
        ] );

        // New seat meta (spec §4.1). Integer keys as integer, strings as string.
        $reg_int_keys = [
            '_anchor_event_order_id',
            '_anchor_event_order_item_id',
            '_anchor_event_product_id',
            '_anchor_event_variation_id',
            '_anchor_event_customer_id',
            '_anchor_event_seat_index',
        ];
        foreach ( $reg_int_keys as $key ) {
            \register_post_meta( self::REG_CPT, $key, [
                'type' => 'integer',
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => $reg_auth_callback,
            ] );
        }
        foreach ( [ '_anchor_event_phone', '_anchor_event_source', '_anchor_event_ticket_type_id' ] as $key ) {
            \register_post_meta( self::REG_CPT, $key, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => $reg_auth_callback,
            ] );
        }
        // History is internal-only — keep it out of REST to avoid array-schema friction.
        \register_post_meta( self::REG_CPT, '_anchor_event_history', [
            'type' => 'array',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => $reg_auth_callback,
        ] );

        // L15: spec-reserved attendee-notified flag (honors the `notify_attendee`
        // reservation; not yet written, registered so the key is recognized).
        \register_post_meta( self::REG_CPT, '_anchor_event_attendee_notified', [
            'type' => 'boolean',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $reg_auth_callback,
        ] );

        // v1.1 lifecycle email markers (spec §4.2). Written by cron/cancel tasks only.
        \register_post_meta( self::REG_CPT, '_anchor_event_reminders_sent', [
            'type' => 'array', 'single' => true, 'show_in_rest' => false, 'auth_callback' => $reg_auth_callback,
        ] );
        \register_post_meta( self::REG_CPT, '_anchor_event_cancel_emailed', [
            'type' => 'boolean', 'single' => true, 'show_in_rest' => false, 'auth_callback' => $reg_auth_callback,
        ] );
    }

    /**
     * sanitize_callback for the `external_embed` meta key (Task 1.1+1.2 fix).
     * `external_embed` is show_in_rest=false (Task 1.3: classic-metabox-only,
     * to avoid a Gutenberg/classic-metabox save race), but sanitize_meta()
     * still runs this callback on every write regardless of REST exposure —
     * including the direct update_post_meta() call in save_meta() — so an
     * editor could otherwise store raw <script> via any write path. Runs the
     * value through an allowlisted wp_kses() so only third-party-embed-shaped
     * markup survives.
     *
     * `script` is deliberately absent from the default allowlist — wp_kses()
     * strips any tag not in the allowed set entirely (open tag, body, and
     * close tag), so both inline `<script>alert(1)</script>` and loader tags
     * like `<script src="...widget.js" async>` are removed cleanly with no
     * extra regex needed. Sites that genuinely need script-based embeds can
     * opt back in via the `anchor_events_embed_allowed_html` filter below.
     *
     * @param mixed  $meta_value
     * @param string $meta_key
     * @param string $object_type
     * @return string
     */
    public function sanitize_external_embed( $meta_value, $meta_key, $object_type ) {
        return (string) \wp_kses( (string) $meta_value, $this->get_embed_allowed_html() );
    }

    /**
     * Allowlisted tags/attributes for `external_embed` markup, filterable so
     * sites can extend it for embed providers with unusual attributes.
     *
     * @return array wp_kses() allowed_html array.
     */
    private function get_embed_allowed_html() {
        $default_allowed = [
            'iframe' => [
                'src' => true,
                'width' => true,
                'height' => true,
                'frameborder' => true,
                'allow' => true,
                'allowfullscreen' => true,
                'style' => true,
                'title' => true,
                'loading' => true,
                'name' => true,
                'sandbox' => true,
                'referrerpolicy' => true,
            ],
            'div' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'data-*' => true,
            ],
            'span' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'data-*' => true,
            ],
            'a' => [
                'href' => true,
                'target' => true,
                'rel' => true,
                'class' => true,
                'id' => true,
                'style' => true,
            ],
            'p' => [
                'class' => true,
            ],
            'br' => [],
        ];

        /**
         * Filter the wp_kses() allowlist used to sanitize the `external_embed`
         * event meta on save (including REST writes).
         *
         * @param array $default_allowed wp_kses() allowed_html array.
         */
        return \apply_filters( 'anchor_events_embed_allowed_html', $default_allowed );
    }

    private function get_meta_schema() {
        return [
            'start_date' => [ 'type' => 'string' ],
            'end_date' => [ 'type' => 'string' ],
            'start_time' => [ 'type' => 'string' ],
            'end_time' => [ 'type' => 'string' ],
            'timezone' => [ 'type' => 'string' ],
            'all_day' => [ 'type' => 'boolean' ],
            'venue' => [ 'type' => 'string' ],
            'address_street' => [ 'type' => 'string' ],
            'address_city' => [ 'type' => 'string' ],
            'address_state' => [ 'type' => 'string' ],
            'address_zip' => [ 'type' => 'string' ],
            'address_country' => [ 'type' => 'string' ],
            'virtual' => [ 'type' => 'boolean' ],
            'virtual_url' => [ 'type' => 'string' ],
            'status_mode' => [ 'type' => 'string' ],
            'status' => [ 'type' => 'string' ],
            'registration_enabled' => [ 'type' => 'boolean' ],
            'capacity' => [ 'type' => 'integer' ],
            'registration_open' => [ 'type' => 'string' ],
            'registration_close' => [ 'type' => 'string' ],
            'waitlist' => [ 'type' => 'boolean' ],
            'registration_type' => [ 'type' => 'string' ],
            'registration_url' => [ 'type' => 'string' ],
            'price' => [ 'type' => 'string' ],
            'hide_from_archive' => [ 'type' => 'boolean' ],
            'featured' => [ 'type' => 'boolean' ],
            'priority' => [ 'type' => 'integer' ],
            'start_ts' => [ 'type' => 'integer' ],
            'end_ts' => [ 'type' => 'integer' ],
            'gallery' => [ 'type' => 'array', 'show_in_rest' => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ] ] ],
            // Product-owned mirror cache of which products/variations register for this
            // event (spec §4.7). Written by the WooCommerce class only — intentionally
            // excluded from save_meta()'s allow-list so event saves never clobber it.
            'linked_products' => [ 'type' => 'array', 'show_in_rest' => false ],
            'organizer_email' => [ 'type' => 'string' ],
            // v1.1 lifecycle email per-event overrides (spec §4.2).
            'reminder_offsets' => [ 'type' => 'string' ],
            'roster_sent' => [ 'type' => 'integer', 'show_in_rest' => false ],
            // Per-event activity roll-up: data-model reserved only; NOT written/surfaced
            // in MVP (activity log deferred — spec §2, §11.6).
            'activity' => [ 'type' => 'array', 'show_in_rest' => false ],
            // Event-type / registration-mode data model (Task 1.1+1.2). Metabox
            // authoring UI + save_meta() wiring landed in Task 1.3+1.4; front-end
            // manager-form parity (same fields, same sanitize_event_type_input()
            // helper) landed in Task 1.5 — offering/recurring type controls remain
            // Phase 2 (placeholder note only).
            // These six keys are edited ONLY via the classic metabox and the
            // front-end manager form (see save_meta() / handle_event_manager_save()).
            // show_in_rest is intentionally false: exposing them to REST/Gutenberg
            // creates a last-write-wins race between the classic metabox save and any
            // REST/block-editor autosave that can silently revert a just-saved value
            // on Publish. sanitize_callback still runs on every write regardless of
            // show_in_rest (sanitize_meta() applies it unconditionally), so this does
            // not weaken sanitization for external_embed.
            'type' => [ 'type' => 'string', 'show_in_rest' => false ],
            'sessions' => [ 'type' => 'array', 'show_in_rest' => false ],
            'registration_mode' => [ 'type' => 'string', 'show_in_rest' => false ],
            'external_url' => [ 'type' => 'string', 'show_in_rest' => false ],
            // Third-party embed markup (spec §Task 1.1+1.2). Classic-metabox-only
            // (show_in_rest=false), but sanitize_callback still runs on every write
            // via sanitize_meta() regardless of REST exposure — kept as defense-in-depth
            // alongside the explicit sanitize_external_embed() call in save_meta().
            'external_embed' => [ 'type' => 'string', 'show_in_rest' => false, 'sanitize_callback' => [ $this, 'sanitize_external_embed' ] ],
            'external_display_price' => [ 'type' => 'string', 'show_in_rest' => false ],
            // Occurrences engine (Phase 2, Task 2.1) — parent/child group meta.
            // Engine-owned: written only by Occurrences, never by save_meta()'s
            // allow-list (see the $input array in save_meta() below — these five
            // keys are intentionally absent from it, same pattern as
            // linked_products/roster_sent/activity above). show_in_rest=false so
            // REST/Gutenberg can never write them either.
            'group_role' => [ 'type' => 'string', 'show_in_rest' => false ],
            'group_id' => [ 'type' => 'integer', 'show_in_rest' => false ],
            'offering_dates' => [ 'type' => 'array', 'show_in_rest' => false ],
            'occurrence_key' => [ 'type' => 'string', 'show_in_rest' => false ],
            'occurrence_closed' => [ 'type' => 'boolean', 'show_in_rest' => false ],
            // Recurrence generator (Phase 2, Task 2.2) — PARENT-only rule
            // ({freq,interval,count?,until?,weekdays?,start_time,end_time,
            // capacity}) that Occurrences::expand_recurrence() expands into
            // the same date-row shape as offering_dates. Engine-owned, same
            // pattern as offering_dates above: show_in_rest=false and
            // intentionally absent from save_meta()'s $input allow-list, so
            // it's never written by the classic metabox/REST/Gutenberg save
            // paths — only ever by whatever future authoring UI/AJAX handler
            // is built for it (out of scope for this task).
            'recurrence' => [ 'type' => 'array', 'show_in_rest' => false ],
        ];
    }

    private function get_meta_defaults() {
        $timezone = \get_option( 'timezone_string' );
        if ( ! $timezone ) {
            $offset = \get_option( 'gmt_offset' );
            $timezone = $offset ? 'UTC' . ( $offset >= 0 ? '+' : '' ) . $offset : 'UTC';
        }

        return [
            'start_date' => '',
            'end_date' => '',
            'start_time' => '',
            'end_time' => '',
            'timezone' => $timezone,
            'all_day' => false,
            'venue' => '',
            'address_street' => '',
            'address_city' => '',
            'address_state' => '',
            'address_zip' => '',
            'address_country' => '',
            'virtual' => false,
            'virtual_url' => '',
            'status_mode' => 'auto',
            'status' => 'upcoming',
            'registration_enabled' => false,
            'capacity' => 0,
            'registration_open' => '',
            'registration_close' => '',
            'waitlist' => false,
            'registration_type' => 'internal',
            'registration_url' => '',
            'price' => '',
            'hide_from_archive' => false,
            'featured' => false,
            'priority' => 0,
            'start_ts' => 0,
            'end_ts' => 0,
            'gallery' => [],
            'linked_products' => [],
            'organizer_email' => '',
            'reminder_offsets' => '',
            'roster_sent' => 0,
            'activity' => [],
            'type' => 'single',
            'sessions' => [],
            'registration_mode' => 'free',
            'external_url' => '',
            'external_embed' => '',
            'external_display_price' => '',
            // Occurrences engine (Phase 2, Task 2.1) — see get_meta_schema().
            'group_role' => '',
            'group_id' => 0,
            'offering_dates' => [],
            'occurrence_key' => '',
            'occurrence_closed' => false,
            'recurrence' => [],
        ];
    }

    public function add_metaboxes() {
        \add_meta_box(
            'anchor_event_details',
            __( 'Event Details', 'anchor-schema' ),
            [ $this, 'render_meta_box' ],
            self::CPT,
            'normal',
            'high'
        );

        \add_meta_box(
            'anchor_event_ticket_types',
            __( 'Tickets / Pricing', 'anchor-schema' ),
            [ $this, 'render_ticket_types_metabox' ],
            self::CPT,
            'normal',
            'default'
        );

        \add_meta_box(
            'anchor_event_registrants',
            __( 'Registrations', 'anchor-schema' ),
            [ $this, 'render_registrants_metabox' ],
            self::CPT,
            'normal',
            'default'
        );
    }

    /**
     * Tickets / Pricing metabox (spec §3.2). A repeatable table of ticket tiers
     * (label / price / quota / sale window / active). The Ticket_Types model
     * owns normalization + persistence; this only renders the rows + a hidden
     * template row consumed by ticket-types-admin.js. Nonce is shared with the
     * Event Details box (self::NONCE), verified once in save_meta().
     *
     * @param \WP_Post $post
     */
    public function render_ticket_types_metabox( $post ) {
        $tiers = $this->ticket_types->get( $post->ID );
        // The implicit-primary synthesized tier is not persisted; only show
        // authored rows so a blank event starts with an empty table.
        $stored = \get_post_meta( $post->ID, Ticket_Types::META_KEY, true );
        $rows   = ( \is_array( $stored ) && ! empty( $stored ) ) ? $tiers : [];
        ?>
        <div class="anchor-event-tickets anchor-event-conditional" id="anchor-event-tickets" data-when-mode="wc">
            <p class="description">
                <?php echo esc_html__( 'Define one or more ticket tiers for this event. Each tier has its own price and optional per-tier quota and sale window. Leave the table empty to use the single "Price" field above as the default registration tier.', 'anchor-schema' ); ?>
            </p>
            <table class="widefat anchor-event-tickets-table">
                <thead>
                    <tr>
                        <th class="anchor-ticket-handle" aria-hidden="true"></th>
                        <th><?php echo esc_html__( 'Label', 'anchor-schema' ); ?></th>
                        <th><?php echo esc_html__( 'Price', 'anchor-schema' ); ?></th>
                        <th><?php echo esc_html__( 'Quota', 'anchor-schema' ); ?></th>
                        <th><?php echo esc_html__( 'Sale start', 'anchor-schema' ); ?></th>
                        <th><?php echo esc_html__( 'Sale end', 'anchor-schema' ); ?></th>
                        <th><?php echo esc_html__( 'Active', 'anchor-schema' ); ?></th>
                        <th aria-hidden="true"></th>
                    </tr>
                </thead>
                <tbody class="anchor-event-tickets-rows">
                    <?php foreach ( $rows as $i => $tier ) : ?>
                        <?php echo $this->ticket_type_row_html( (int) $i, $tier ); // already escaped ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button type="button" class="button anchor-event-ticket-add"><?php echo esc_html__( 'Add ticket tier', 'anchor-schema' ); ?></button>
            </p>
            <script type="text/html" id="anchor-event-ticket-template">
                <?php echo $this->ticket_type_row_html( 0, null, true ); // already escaped ?>
            </script>
        </div>
        <?php
    }

    /**
     * Render a single ticket-tier table row. Field names use the index scheme
     * anchor_event_tickets[<index>][...]; a blank `id` marks a new row. When
     * $template is true, the literal token __INDEX__ is used so the JS can
     * substitute a fresh row index on add.
     *
     * @param int        $index
     * @param array|null $tier
     * @param bool       $template
     * @return string Escaped HTML.
     */
    private function ticket_type_row_html( $index, $tier = null, $template = false ) {
        $idx = $template ? '__INDEX__' : (string) $index;
        $base = 'anchor_event_tickets[' . $idx . ']';

        $id         = $tier['id'] ?? '';
        $label      = $tier['label'] ?? '';
        $price      = isset( $tier['price'] ) ? (string) $tier['price'] : '';
        $quota      = isset( $tier['quota'] ) ? (int) $tier['quota'] : 0;
        $sale_start = $tier['sale_start'] ?? '';
        $sale_end   = $tier['sale_end'] ?? '';
        $active     = $tier ? ! empty( $tier['active'] ) : true;

        \ob_start();
        ?>
        <tr class="anchor-event-ticket-row">
            <td class="anchor-ticket-handle">
                <span class="dashicons dashicons-move" aria-hidden="true"></span>
            </td>
            <td>
                <input type="hidden" name="<?php echo esc_attr( $base . '[id]' ); ?>" value="<?php echo esc_attr( $id ); ?>" class="anchor-ticket-id" />
                <input type="text" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" class="anchor-ticket-label" placeholder="<?php echo esc_attr__( 'e.g. General, VIP', 'anchor-schema' ); ?>" />
            </td>
            <td>
                <input type="number" step="0.01" min="0" name="<?php echo esc_attr( $base . '[price]' ); ?>" value="<?php echo esc_attr( $price ); ?>" class="anchor-ticket-price" />
            </td>
            <td>
                <input type="number" step="1" min="0" name="<?php echo esc_attr( $base . '[quota]' ); ?>" value="<?php echo esc_attr( $quota ); ?>" class="anchor-ticket-quota" placeholder="0" />
            </td>
            <td>
                <input type="date" name="<?php echo esc_attr( $base . '[sale_start]' ); ?>" value="<?php echo esc_attr( $sale_start ); ?>" class="anchor-ticket-sale-start" />
            </td>
            <td>
                <input type="date" name="<?php echo esc_attr( $base . '[sale_end]' ); ?>" value="<?php echo esc_attr( $sale_end ); ?>" class="anchor-ticket-sale-end" />
            </td>
            <td class="anchor-ticket-active-cell">
                <input type="checkbox" name="<?php echo esc_attr( $base . '[active]' ); ?>" value="1" <?php checked( $active ); ?> class="anchor-ticket-active" />
            </td>
            <td>
                <button type="button" class="button-link-delete anchor-event-ticket-remove" aria-label="<?php echo esc_attr__( 'Remove ticket tier', 'anchor-schema' ); ?>">&times;</button>
            </td>
        </tr>
        <?php
        return (string) \ob_get_clean();
    }

    /**
     * Render a single session-repeater table row (Task 1.3+1.4). Field names
     * use the index scheme anchor_event_sessions[<index>][...], matching the
     * ticket-tier row convention above. When $template is true, the literal
     * token __INDEX__ is used so the JS can substitute a fresh row index on add.
     *
     * @param int        $index
     * @param array|null $session
     * @param bool       $template
     * @return string Escaped HTML.
     */
    private function event_session_row_html( $index, $session = null, $template = false ) {
        $idx = $template ? '__INDEX__' : (string) $index;
        $base = 'anchor_event_sessions[' . $idx . ']';

        $date       = $session['date'] ?? '';
        $start_time = $session['start_time'] ?? '';
        $end_time   = $session['end_time'] ?? '';
        $label      = $session['label'] ?? '';

        \ob_start();
        ?>
        <tr class="anchor-event-session-row">
            <td>
                <input type="date" name="<?php echo esc_attr( $base . '[date]' ); ?>" value="<?php echo esc_attr( $date ); ?>" class="anchor-session-date" />
            </td>
            <td>
                <input type="time" name="<?php echo esc_attr( $base . '[start_time]' ); ?>" value="<?php echo esc_attr( $start_time ); ?>" class="anchor-session-start-time" />
            </td>
            <td>
                <input type="time" name="<?php echo esc_attr( $base . '[end_time]' ); ?>" value="<?php echo esc_attr( $end_time ); ?>" class="anchor-session-end-time" />
            </td>
            <td>
                <input type="text" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" class="anchor-session-label" placeholder="<?php echo esc_attr__( 'e.g. Day 1', 'anchor-schema' ); ?>" />
            </td>
            <td>
                <button type="button" class="button-link-delete anchor-event-session-remove" aria-label="<?php echo esc_attr__( 'Remove session', 'anchor-schema' ); ?>">&times;</button>
            </td>
        </tr>
        <?php
        return (string) \ob_get_clean();
    }

    public function render_meta_box( $post ) {
        \wp_nonce_field( self::NONCE, self::NONCE );
        $meta = $this->get_meta( $post->ID );
        $settings = $this->get_settings();
        $timezone_options = \wp_timezone_choice( $meta['timezone'] );
        $event_type = $this->event_type( $post->ID );
        $registration_mode = $this->registration_mode( $post->ID );
        $wc_active = \class_exists( 'WooCommerce' );
        $sessions = $this->get_sessions( $post->ID );
        ?>
        <div class="anchor-event-meta">
            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Event Type & Registration', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_type"><?php echo esc_html__( 'Event Type', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_type" name="anchor_event_type">
                            <option value="single" <?php selected( $event_type, 'single' ); ?>><?php echo esc_html__( 'Single event', 'anchor-schema' ); ?></option>
                            <option value="multisession" <?php selected( $event_type, 'multisession' ); ?>><?php echo esc_html__( 'Multi-session series', 'anchor-schema' ); ?></option>
                            <option value="offering" <?php selected( $event_type, 'offering' ); ?>><?php echo esc_html__( 'Pick-one offerings', 'anchor-schema' ); ?></option>
                            <option value="recurring" <?php selected( $event_type, 'recurring' ); ?>><?php echo esc_html__( 'Recurring schedule', 'anchor-schema' ); ?></option>
                        </select>
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_registration_mode"><?php echo esc_html__( 'Registration', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_registration_mode" name="anchor_event_registration_mode">
                            <option value="wc" <?php selected( $registration_mode, 'wc' ); ?> <?php disabled( ! $wc_active ); ?>><?php echo esc_html__( 'WooCommerce ticketed', 'anchor-schema' ); ?><?php echo $wc_active ? '' : ' ' . esc_html__( '(requires WooCommerce)', 'anchor-schema' ); ?></option>
                            <option value="free" <?php selected( $registration_mode, 'free' ); ?>><?php echo esc_html__( 'Free registration', 'anchor-schema' ); ?></option>
                            <option value="external" <?php selected( $registration_mode, 'external' ); ?>><?php echo esc_html__( 'External registration', 'anchor-schema' ); ?></option>
                        </select>
                        <?php if ( ! $wc_active ) : ?>
                            <p class="description"><?php echo esc_html__( 'WooCommerce is inactive, so WooCommerce-ticketed registration is unavailable until it is activated.', 'anchor-schema' ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Date & Time', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_start_date"><?php echo esc_html__( 'Start Date', 'anchor-schema' ); ?></label>
                        <input type="date" id="anchor_event_start_date" name="anchor_event_start_date" value="<?php echo esc_attr( $meta['start_date'] ); ?>" required />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_end_date"><?php echo esc_html__( 'End Date', 'anchor-schema' ); ?></label>
                        <input type="date" id="anchor_event_end_date" name="anchor_event_end_date" value="<?php echo esc_attr( $meta['end_date'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-time-fields">
                        <label for="anchor_event_start_time"><?php echo esc_html__( 'Start Time', 'anchor-schema' ); ?></label>
                        <input type="time" id="anchor_event_start_time" name="anchor_event_start_time" value="<?php echo esc_attr( $meta['start_time'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-time-fields">
                        <label for="anchor_event_end_time"><?php echo esc_html__( 'End Time', 'anchor-schema' ); ?></label>
                        <input type="time" id="anchor_event_end_time" name="anchor_event_end_time" value="<?php echo esc_attr( $meta['end_time'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_timezone"><?php echo esc_html__( 'Timezone', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_timezone" name="anchor_event_timezone">
                            <?php echo $timezone_options; ?>
                        </select>
                    </div>
                    <div class="anchor-event-field">
                        <label>
                            <input type="checkbox" id="anchor_event_all_day" name="anchor_event_all_day" value="1" <?php checked( $meta['all_day'] ); ?> />
                            <?php echo esc_html__( 'All day event', 'anchor-schema' ); ?>
                        </label>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section anchor-event-conditional" data-when-type="multisession">
                <h3><?php echo esc_html__( 'Sessions', 'anchor-schema' ); ?></h3>
                <p class="description"><?php echo esc_html__( 'Add one row per session date/time in this series.', 'anchor-schema' ); ?></p>
                <table class="widefat anchor-event-sessions-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Date', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Start time', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'End time', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Label', 'anchor-schema' ); ?></th>
                            <th aria-hidden="true"></th>
                        </tr>
                    </thead>
                    <tbody class="anchor-event-sessions-rows">
                        <?php foreach ( $sessions as $i => $session ) : ?>
                            <?php echo $this->event_session_row_html( (int) $i, $session ); // already escaped ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="button anchor-event-session-add"><?php echo esc_html__( 'Add session', 'anchor-schema' ); ?></button>
                </p>
                <script type="text/html" id="anchor-event-session-template">
                    <?php echo $this->event_session_row_html( 0, null, true ); // already escaped ?>
                </script>
            </div>

            <div class="anchor-event-section anchor-event-conditional" data-when-type="offering recurring">
                <h3><?php echo esc_html__( 'Offering / Recurring Schedule', 'anchor-schema' ); ?></h3>
                <p class="description"><?php echo esc_html__( 'Offering dates / recurrence are configured in the next phase.', 'anchor-schema' ); ?></p>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Location', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_venue"><?php echo esc_html__( 'Venue Name', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_venue" name="anchor_event_venue" value="<?php echo esc_attr( $meta['venue'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_address_street"><?php echo esc_html__( 'Street Address', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_address_street" name="anchor_event_address_street" value="<?php echo esc_attr( $meta['address_street'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_address_city"><?php echo esc_html__( 'City', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_address_city" name="anchor_event_address_city" value="<?php echo esc_attr( $meta['address_city'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_address_state"><?php echo esc_html__( 'State/Region', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_address_state" name="anchor_event_address_state" value="<?php echo esc_attr( $meta['address_state'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_address_zip"><?php echo esc_html__( 'Postal Code', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_address_zip" name="anchor_event_address_zip" value="<?php echo esc_attr( $meta['address_zip'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_address_country"><?php echo esc_html__( 'Country', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_address_country" name="anchor_event_address_country" value="<?php echo esc_attr( $meta['address_country'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label>
                            <input type="checkbox" id="anchor_event_virtual" name="anchor_event_virtual" value="1" <?php checked( $meta['virtual'] ); ?> />
                            <?php echo esc_html__( 'Virtual event', 'anchor-schema' ); ?>
                        </label>
                    </div>
                    <div class="anchor-event-field" id="anchor-event-virtual-url">
                        <label for="anchor_event_virtual_url"><?php echo esc_html__( 'Virtual Event URL', 'anchor-schema' ); ?></label>
                        <input type="url" id="anchor_event_virtual_url" name="anchor_event_virtual_url" value="<?php echo esc_attr( $meta['virtual_url'] ); ?>" />
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Status', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_status"><?php echo esc_html__( 'Event Status', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_status" name="anchor_event_status">
                            <option value="auto" <?php selected( $meta['status_mode'], 'auto' ); ?>><?php echo esc_html__( 'Auto (based on dates)', 'anchor-schema' ); ?></option>
                            <?php foreach ( $this->get_status_options() as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $meta['status_mode'] === 'manual' && $meta['status'] === $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php echo esc_html__( 'Auto status updates based on dates but can be overridden manually.', 'anchor-schema' ); ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Registration', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label>
                            <input type="checkbox" id="anchor_event_registration_enabled" name="anchor_event_registration_enabled" value="1" <?php checked( $meta['registration_enabled'] ); ?> />
                            <?php echo esc_html__( 'Enable registration', 'anchor-schema' ); ?>
                        </label>
                        <?php if ( empty( $settings['registration_internal'] ) ) : ?>
                            <p class="description"><?php echo esc_html__( 'Internal registration is disabled in Events settings. External registration URLs are still available.', 'anchor-schema' ); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label for="anchor_event_capacity"><?php echo esc_html__( 'Maximum capacity', 'anchor-schema' ); ?></label>
                        <input type="number" id="anchor_event_capacity" name="anchor_event_capacity" min="0" value="<?php echo esc_attr( $meta['capacity'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label for="anchor_event_registration_open"><?php echo esc_html__( 'Registration opens', 'anchor-schema' ); ?></label>
                        <input type="date" id="anchor_event_registration_open" name="anchor_event_registration_open" value="<?php echo esc_attr( $meta['registration_open'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label for="anchor_event_registration_close"><?php echo esc_html__( 'Registration closes', 'anchor-schema' ); ?></label>
                        <input type="date" id="anchor_event_registration_close" name="anchor_event_registration_close" value="<?php echo esc_attr( $meta['registration_close'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label>
                            <input type="checkbox" id="anchor_event_waitlist" name="anchor_event_waitlist" value="1" <?php checked( $meta['waitlist'] ); ?> />
                            <?php echo esc_html__( 'Enable waitlist', 'anchor-schema' ); ?>
                        </label>
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label for="anchor_event_registration_type"><?php echo esc_html__( 'Registration type', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_registration_type" name="anchor_event_registration_type">
                            <option value="internal" <?php selected( $meta['registration_type'], 'internal' ); ?>><?php echo esc_html__( 'Internal', 'anchor-schema' ); ?></option>
                            <option value="external" <?php selected( $meta['registration_type'], 'external' ); ?>><?php echo esc_html__( 'External URL', 'anchor-schema' ); ?></option>
                        </select>
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields" id="anchor-event-registration-url">
                        <label for="anchor_event_registration_url"><?php echo esc_html__( 'External Registration URL', 'anchor-schema' ); ?></label>
                        <input type="url" id="anchor_event_registration_url" name="anchor_event_registration_url" value="<?php echo esc_attr( $meta['registration_url'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label for="anchor_event_price"><?php echo esc_html__( 'Price (optional)', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_price" name="anchor_event_price" value="<?php echo esc_attr( $meta['price'] ); ?>" />
                    </div>
                </div>
            </div>

            <div class="anchor-event-section anchor-event-conditional" data-when-mode="external">
                <h3><?php echo esc_html__( 'External Registration', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_external_url"><?php echo esc_html__( 'External URL', 'anchor-schema' ); ?></label>
                        <input type="url" id="anchor_event_external_url" name="anchor_event_external_url" value="<?php echo esc_attr( $meta['external_url'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_external_display_price"><?php echo esc_html__( 'Display price', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_external_display_price" name="anchor_event_external_display_price" value="<?php echo esc_attr( $meta['external_display_price'] ); ?>" />
                        <p class="description"><?php echo esc_html__( 'Display-only price label, e.g. $495. Not connected to WooCommerce.', 'anchor-schema' ); ?></p>
                    </div>
                    <div class="anchor-event-field anchor-event-field-wide">
                        <label for="anchor_event_external_embed"><?php echo esc_html__( 'Embed code', 'anchor-schema' ); ?></label>
                        <textarea id="anchor_event_external_embed" name="anchor_event_external_embed" rows="5" class="large-text code"><?php echo esc_textarea( $meta['external_embed'] ); ?></textarea>
                        <p class="description"><?php echo esc_html__( 'Paste a third-party embed. Iframes allowed; scripts stripped by default.', 'anchor-schema' ); ?></p>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Display Controls', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label>
                            <input type="checkbox" id="anchor_event_hide_from_archive" name="anchor_event_hide_from_archive" value="1" <?php checked( $meta['hide_from_archive'] ); ?> />
                            <?php echo esc_html__( 'Hide from archive', 'anchor-schema' ); ?>
                        </label>
                    </div>
                    <div class="anchor-event-field">
                        <label>
                            <input type="checkbox" id="anchor_event_featured" name="anchor_event_featured" value="1" <?php checked( $meta['featured'] ); ?> />
                            <?php echo esc_html__( 'Featured / pinned', 'anchor-schema' ); ?>
                        </label>
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_priority"><?php echo esc_html__( 'Priority order', 'anchor-schema' ); ?></label>
                        <input type="number" id="anchor_event_priority" name="anchor_event_priority" value="<?php echo esc_attr( $meta['priority'] ); ?>" />
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Photo Gallery', 'anchor-schema' ); ?></h3>
                <p class="description"><?php echo esc_html__( 'Pick or upload images for the event photo gallery. Drag to reorder. The gallery renders via the [event_gallery] shortcode or automatically on the plugin\'s single-event template.', 'anchor-schema' ); ?></p>
                <?php
                $gallery_ids = array_map( 'intval', (array) $meta['gallery'] );
                $gallery_ids = array_values( array_filter( $gallery_ids ) );
                ?>
                <div class="anchor-event-gallery-field" data-max="0">
                    <input type="hidden" id="anchor_event_gallery" name="anchor_event_gallery" value="<?php echo esc_attr( implode( ',', $gallery_ids ) ); ?>" />
                    <ul class="anchor-event-gallery-previews">
                        <?php foreach ( $gallery_ids as $attachment_id ) :
                            $thumb = \wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
                            if ( ! $thumb ) { continue; }
                            ?>
                            <li data-id="<?php echo esc_attr( $attachment_id ); ?>">
                                <img src="<?php echo esc_url( $thumb ); ?>" alt="" />
                                <button type="button" class="anchor-event-gallery-remove" aria-label="<?php echo esc_attr__( 'Remove image', 'anchor-schema' ); ?>">&times;</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p>
                        <button type="button" class="button anchor-event-gallery-add"><?php echo esc_html__( 'Add / manage images', 'anchor-schema' ); ?></button>
                        <button type="button" class="button-link-delete anchor-event-gallery-clear"><?php echo esc_html__( 'Clear all', 'anchor-schema' ); ?></button>
                    </p>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Email Settings', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_reminder_offsets"><?php echo esc_html__( 'Reminder offsets (days)', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_reminder_offsets" name="anchor_event_reminder_offsets" value="<?php echo esc_attr( $meta['reminder_offsets'] ); ?>" class="regular-text" />
                        <p class="description"><?php echo esc_html__( 'Comma-separated days before start (e.g. 14,3,1). Leave blank to use the global default.', 'anchor-schema' ); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_registrants_metabox( $post ) {
        $registrations = $this->get_registrations( $post->ID );
        $count = $this->get_registration_count( $post->ID );
        $attendees = $this->get_attendee_count( $post->ID );
        $waitlist = $this->get_registration_count( $post->ID, 'waitlist' );
        $export_url = \wp_nonce_url(
            \admin_url( 'admin-post.php?action=anchor_event_export&event_id=' . $post->ID ),
            'anchor_event_export'
        );
        ?>
        <p>
            <strong><?php echo esc_html__( 'Registrations', 'anchor-schema' ); ?>:</strong>
            <?php echo esc_html( $count ); ?>
            <?php if ( $attendees !== (int) $count ) : ?>
                &middot; <strong><?php echo esc_html__( 'Total attendees', 'anchor-schema' ); ?>:</strong> <?php echo esc_html( $attendees ); ?>
            <?php endif; ?>
        </p>
        <?php if ( $waitlist ) : ?>
            <p><strong><?php echo esc_html__( 'Waitlist', 'anchor-schema' ); ?>:</strong> <?php echo esc_html( $waitlist ); ?></p>
        <?php endif; ?>
        <?php
        // Read-only WooCommerce linking mirror (spec §5.5). Shown when WooCommerce
        // is active and at least one product/variation registers for this event.
        if ( \class_exists( 'WooCommerce' ) ) {
            $linked = \get_post_meta( $post->ID, $this->meta_key( 'linked_products' ), true );
            if ( is_array( $linked ) && ! empty( $linked ) ) :
                ?>
                <div class="notice notice-info inline anchor-event-linked-products" style="margin:12px 0;padding:8px 12px;">
                    <p><strong><?php echo esc_html__( 'Registers via:', 'anchor-schema' ); ?></strong></p>
                    <ul style="margin:4px 0 4px 18px;list-style:disc;">
                        <?php foreach ( $linked as $link ) :
                            $product_id   = isset( $link['product_id'] ) ? (int) $link['product_id'] : 0;
                            $variation_id = isset( $link['variation_id'] ) ? (int) $link['variation_id'] : 0;
                            if ( $product_id <= 0 || \get_post_type( $product_id ) !== 'product' || \get_post_status( $product_id ) === 'trash' ) :
                                ?>
                                <li><?php echo esc_html__( '(product removed)', 'anchor-schema' ); ?></li>
                                <?php
                                continue;
                            endif;
                            $edit_link = \get_edit_post_link( $product_id );
                            $title     = \get_the_title( $product_id );
                            $var_label = '';
                            if ( $variation_id > 0 && \function_exists( 'wc_get_product' ) ) {
                                $variation = \wc_get_product( $variation_id );
                                if ( $variation && \function_exists( 'wc_get_formatted_variation' ) ) {
                                    $var_label = \wp_strip_all_tags( \wc_get_formatted_variation( $variation, true ) );
                                }
                            }
                            ?>
                            <li>
                                <?php if ( $edit_link ) : ?>
                                    <a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $title ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $title ); ?>
                                <?php endif; ?>
                                <span class="description">(#<?php echo esc_html( $product_id ); ?><?php
                                    if ( $variation_id > 0 ) {
                                        echo ' &middot; ' . esc_html__( 'variation', 'anchor-schema' ) . ' #' . esc_html( $variation_id );
                                    }
                                ?>)</span>
                                <?php if ( $var_label ) : ?>
                                    &mdash; <?php echo esc_html( $var_label ); ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="description">
                        <?php echo esc_html__( 'The public free registration form will be replaced by WooCommerce checkout once paid checkout is enabled (coming soon). For now the free form remains active on this event.', 'anchor-schema' ); ?>
                    </p>
                    <p class="description">
                        <?php echo esc_html__( 'Recommended: disable "Manage stock" on the linked product(s) so event capacity is the single source of truth for availability.', 'anchor-schema' ); ?>
                    </p>
                </div>
                <?php
            endif;
        }
        ?>
        <p>
            <a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php echo esc_html__( 'Export CSV', 'anchor-schema' ); ?></a>
            <?php if ( $this->roster ) : ?>
                <a class="button button-primary" href="<?php echo esc_url( $this->roster->roster_url( $post->ID ) ); ?>"><?php echo esc_html__( 'Open full roster', 'anchor-schema' ); ?></a>
            <?php endif; ?>
        </p>
        <div class="anchor-event-registrants">
            <?php if ( empty( $registrations ) ) : ?>
                <p class="description"><?php echo esc_html__( 'No registrations yet.', 'anchor-schema' ); ?></p>
            <?php else : ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Name', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Email', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Guests', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Status', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Date', 'anchor-schema' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $registrations as $reg ) : ?>
                            <tr>
                                <td><?php echo esc_html( $reg['name'] ); ?></td>
                                <td><?php echo esc_html( $reg['email'] ); ?></td>
                                <td><?php echo esc_html( (int) ( $reg['guests'] ?? 0 ) ); ?></td>
                                <td><?php echo esc_html( ucfirst( $reg['status'] ) ); ?></td>
                                <td><?php echo esc_html( $reg['date'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! \current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Resolved BEFORE the save loop below so an invalid/missing posted
        // registration_mode falls back to whatever the event currently
        // resolves to (explicit stored value, or the legacy-signal
        // derivation), not a hardcoded default.
        $current_registration_mode = $this->registration_mode( $post_id );

        $input = [
            'start_date' => $this->sanitize_date( $_POST['anchor_event_start_date'] ?? '' ),
            'end_date' => $this->sanitize_date( $_POST['anchor_event_end_date'] ?? '' ),
            'start_time' => $this->sanitize_time( $_POST['anchor_event_start_time'] ?? '' ),
            'end_time' => $this->sanitize_time( $_POST['anchor_event_end_time'] ?? '' ),
            'timezone' => sanitize_text_field( $_POST['anchor_event_timezone'] ?? '' ),
            'all_day' => ! empty( $_POST['anchor_event_all_day'] ),
            'venue' => sanitize_text_field( $_POST['anchor_event_venue'] ?? '' ),
            'address_street' => sanitize_text_field( $_POST['anchor_event_address_street'] ?? '' ),
            'address_city' => sanitize_text_field( $_POST['anchor_event_address_city'] ?? '' ),
            'address_state' => sanitize_text_field( $_POST['anchor_event_address_state'] ?? '' ),
            'address_zip' => sanitize_text_field( $_POST['anchor_event_address_zip'] ?? '' ),
            'address_country' => sanitize_text_field( $_POST['anchor_event_address_country'] ?? '' ),
            'virtual' => ! empty( $_POST['anchor_event_virtual'] ),
            'virtual_url' => esc_url_raw( $_POST['anchor_event_virtual_url'] ?? '' ),
            'registration_enabled' => ! empty( $_POST['anchor_event_registration_enabled'] ),
            'capacity' => (int) ( $_POST['anchor_event_capacity'] ?? 0 ),
            'registration_open' => $this->sanitize_date( $_POST['anchor_event_registration_open'] ?? '' ),
            'registration_close' => $this->sanitize_date( $_POST['anchor_event_registration_close'] ?? '' ),
            'waitlist' => ! empty( $_POST['anchor_event_waitlist'] ),
            'registration_type' => sanitize_text_field( $_POST['anchor_event_registration_type'] ?? 'internal' ),
            'registration_url' => esc_url_raw( $_POST['anchor_event_registration_url'] ?? '' ),
            'price' => sanitize_text_field( $_POST['anchor_event_price'] ?? '' ),
            'hide_from_archive' => ! empty( $_POST['anchor_event_hide_from_archive'] ),
            'featured' => ! empty( $_POST['anchor_event_featured'] ),
            'priority' => (int) ( $_POST['anchor_event_priority'] ?? 0 ),
            'gallery' => $this->sanitize_gallery_ids( $_POST['anchor_event_gallery'] ?? '' ),
            'reminder_offsets' => $this->sanitize_offset_csv( $_POST['anchor_event_reminder_offsets'] ?? '' ),
        ];

        // Event-type / registration-mode authoring UI (Task 1.3+1.4, front-end
        // parity in Task 1.5). Occurrence only — offering/recurring get a
        // placeholder note in both forms; no seats/capacity/tiers/product logic
        // here. Shared with handle_event_manager_save() so the two save paths
        // can never drift on how these six keys are sanitized.
        $input = array_merge( $input, $this->sanitize_event_type_input( $_POST, $current_registration_mode ) );

        if ( ! $input['start_date'] ) {
            \add_filter( 'redirect_post_location', function( $location ) {
                return \add_query_arg( 'anchor_event_notice', 'missing_start_date', $location );
            } );
        }

        $status_raw = sanitize_text_field( $_POST['anchor_event_status'] ?? 'auto' );
        if ( $status_raw === 'auto' ) {
            $input['status_mode'] = 'auto';
            $input['status'] = $this->calculate_status( $input );
        } else {
            $input['status_mode'] = 'manual';
            $input['status'] = in_array( $status_raw, array_keys( $this->get_status_options() ), true ) ? $status_raw : 'upcoming';
        }

        $timestamps = $this->calculate_timestamps( $input );
        $input['start_ts'] = $timestamps['start'];
        $input['end_ts'] = $timestamps['end'];

        foreach ( $input as $key => $value ) {
            \update_post_meta( $post_id, $this->meta_key( $key ), $value );
        }

        // Ticket tiers (spec §3.2). The Ticket_Types model sanitizes the rows,
        // assigns stable ids, drops empty rows, and persists. An empty table
        // clears the meta so the legacy single `price` field stays the
        // implicit-primary fallback.
        $ticket_rows = isset( $_POST['anchor_event_tickets'] ) && is_array( $_POST['anchor_event_tickets'] )
            ? \wp_unslash( $_POST['anchor_event_tickets'] )
            : [];
        $this->ticket_types->save( $post_id, $ticket_rows );

        $this->maybe_append_registration_shortcode( $post_id, $input );

        $this->clear_caches();
    }

    /**
     * Shared sanitizer for the event-type / registration-mode authoring fields
     * (type, registration_mode, sessions, external_url, external_embed,
     * external_display_price). Called by BOTH save paths — the admin metabox
     * save_meta() and the front-end manager form handle_event_manager_save()
     * (Task 1.5) — so the two forms can never drift out of sync on how these
     * six keys are sanitized.
     *
     * $src is a raw, NOT-yet-unslashed input array shaped like $_POST; every
     * value is wp_unslash()ed here (esp. external_embed, unslashed BEFORE it
     * hits wp_kses() in sanitize_external_embed() — never store it raw).
     *
     * @param array  $src                        Raw input array ($_POST-shaped).
     * @param string $registration_mode_fallback Pre-resolved registration_mode()
     *                                            value (computed by the caller
     *                                            BEFORE this save writes any meta)
     *                                            to fall back to when the posted
     *                                            registration_mode is missing or
     *                                            invalid — see
     *                                            sanitize_registration_mode().
     * @return array{
     *     type: string,
     *     registration_mode: string,
     *     sessions: array,
     *     external_url: string,
     *     external_embed: string,
     *     external_display_price: string,
     * }
     */
    private function sanitize_event_type_input( array $src, $registration_mode_fallback ) {
        $sessions_raw = isset( $src['anchor_event_sessions'] ) && is_array( $src['anchor_event_sessions'] )
            ? \wp_unslash( $src['anchor_event_sessions'] )
            : [];

        return [
            'type' => $this->sanitize_event_type( \wp_unslash( $src['anchor_event_type'] ?? '' ) ),
            'registration_mode' => $this->sanitize_registration_mode( \wp_unslash( $src['anchor_event_registration_mode'] ?? '' ), $registration_mode_fallback ),
            'sessions' => $this->sanitize_sessions_rows( $sessions_raw ),
            'external_url' => esc_url_raw( \wp_unslash( $src['anchor_event_external_url'] ?? '' ) ),
            // Reuses the SAME wp_kses() allowlist sanitizer as the REST write
            // path (sanitize_external_embed()) so this field is never stored
            // raw regardless of which save path wrote it.
            'external_embed' => $this->sanitize_external_embed( \wp_unslash( $src['anchor_event_external_embed'] ?? '' ), $this->meta_key( 'external_embed' ), self::CPT ),
            'external_display_price' => sanitize_text_field( \wp_unslash( $src['anchor_event_external_display_price'] ?? '' ) ),
        ];
    }

    /**
     * Validate a posted event `type`, falling back to 'single' for a missing
     * or garbage value. Mirrors the enum event_type() falls back to.
     *
     * @param mixed $raw
     * @return string One of single|multisession|offering|recurring.
     */
    private function sanitize_event_type( $raw ) {
        $valid = [ 'single', 'multisession', 'offering', 'recurring' ];
        $value = \sanitize_text_field( (string) $raw );
        return in_array( $value, $valid, true ) ? $value : 'single';
    }

    /**
     * Validate a posted `registration_mode`, falling back to whatever the
     * event currently resolves to (explicit stored value, or the
     * legacy-signal derivation performed by registration_mode()) rather than
     * a hardcoded default, so an empty/garbage post never silently downgrades
     * an already-derived mode.
     *
     * @param mixed  $raw
     * @param string $fallback Pre-resolved value from registration_mode(), called
     *                         BEFORE this save writes any meta.
     * @return string One of wc|free|external.
     */
    private function sanitize_registration_mode( $raw, $fallback ) {
        $valid = [ 'wc', 'free', 'external' ];
        $value = \sanitize_text_field( (string) $raw );
        if ( in_array( $value, $valid, true ) ) {
            return $value;
        }
        return in_array( $fallback, $valid, true ) ? $fallback : 'free';
    }

    /**
     * Sanitize the posted session-repeater rows (Sessions section,
     * data-when-type="multisession"). Rows with an empty date are dropped —
     * mirrors the normalization get_sessions() already applies on read, kept
     * here too so what's persisted is already clean.
     *
     * @param array $raw Raw anchor_event_sessions[] rows from $_POST (already wp_unslash()ed).
     * @return array<int,array{date:string,start_time:string,end_time:string,label:string}>
     */
    private function sanitize_sessions_rows( $raw ) {
        $sessions = [];
        foreach ( (array) $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $date = \sanitize_text_field( $row['date'] ?? '' );
            if ( $date === '' ) {
                continue;
            }
            $sessions[] = [
                'date' => $date,
                'start_time' => \sanitize_text_field( $row['start_time'] ?? '' ),
                'end_time' => \sanitize_text_field( $row['end_time'] ?? '' ),
                'label' => \sanitize_text_field( $row['label'] ?? '' ),
            ];
        }
        return $sessions;
    }

    private function sanitize_gallery_ids( $raw ) {
        if ( is_array( $raw ) ) {
            $ids = $raw;
        } else {
            $ids = preg_split( '/[\s,]+/', (string) $raw );
        }
        $ids = array_map( 'intval', (array) $ids );
        $ids = array_values( array_filter( $ids, function( $id ) {
            return $id > 0 && \get_post_type( $id ) === 'attachment';
        } ) );
        return $ids;
    }

    private function maybe_append_registration_shortcode( $post_id, $input ) {
        if ( empty( $input['registration_enabled'] ) ) {
            return;
        }
        $post = \get_post( $post_id );
        if ( ! $post || $post->post_type !== self::CPT ) {
            return;
        }
        if ( strpos( (string) $post->post_content, '[event_registration' ) !== false ) {
            return;
        }
        $new_content = rtrim( (string) $post->post_content );
        $new_content .= ( $new_content === '' ? '' : "\n\n" ) . '[event_registration]';

        \remove_action( 'save_post_' . self::CPT, [ $this, 'save_meta' ] );
        \wp_update_post( [
            'ID' => $post_id,
            'post_content' => $new_content,
        ] );
        \add_action( 'save_post_' . self::CPT, [ $this, 'save_meta' ] );
    }

    public function admin_notices() {
        if ( ! isset( $_GET['anchor_event_notice'] ) ) {
            return;
        }
        $notice = sanitize_text_field( $_GET['anchor_event_notice'] );
        if ( $notice === 'missing_start_date' ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Event start date is required.', 'anchor-schema' ) . '</p></div>';
        }
    }

    public function admin_assets( $hook ) {
        if ( ! in_array( $hook, [ 'post-new.php', 'post.php' ], true ) ) {
            return;
        }
        $screen = \get_current_screen();
        if ( ! $screen || $screen->post_type !== self::CPT ) {
            return;
        }
        \wp_enqueue_media();
        \wp_enqueue_style( 'anchor-events-admin', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/admin.css' ), [], '1.0.2' );
        \wp_enqueue_script( 'anchor-events-admin', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/admin.js' ), [ 'jquery', 'jquery-ui-sortable' ], '1.0.2', true );
        // Ticket-tier repeatable table (spec §3.2).
        \wp_enqueue_script( 'anchor-events-ticket-types', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/ticket-types-admin.js' ), [ 'jquery', 'jquery-ui-sortable' ], '1.0.0', true );
    }

    public function frontend_assets() {
        if ( \is_admin() ) {
            return;
        }
        if ( \is_singular( self::CPT ) || \is_post_type_archive( self::CPT ) ) {
            $this->enqueue_frontend_assets();
        }
    }

    public function enqueue_frontend_assets() {
        if ( $this->assets_enqueued ) {
            return;
        }
        \wp_enqueue_style( 'anchor-events-frontend', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/frontend.css' ), [], '1.0.8' );
        $settings = $this->get_settings();
        $btn_color = \sanitize_hex_color( $settings['register_button_color'] ?? '' ) ?: '#0f766e';
        \wp_add_inline_style( 'anchor-events-frontend', sprintf(
            '.anchor-event-register{background:%1$s !important;border-color:%1$s !important;color:#fff !important;}.anchor-event-register:hover{filter:brightness(0.92);}',
            $btn_color
        ) );
        \wp_enqueue_script( 'anchor-events-frontend', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/frontend.js' ), [], '1.0.5', true );
        \wp_localize_script( 'anchor-events-frontend', 'ANCHOR_EVENTS_AJAX', [
            'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
            'nonce'   => \wp_create_nonce( 'anchor_events_calendar' ),
        ] );
        $this->assets_enqueued = true;
    }

    public function columns( $columns ) {
        $columns['anchor_event_start'] = __( 'Start Date', 'anchor-schema' );
        $columns['anchor_event_status'] = __( 'Status', 'anchor-schema' );
        $columns['anchor_event_venue'] = __( 'Venue', 'anchor-schema' );
        $columns['anchor_event_capacity'] = __( 'Capacity', 'anchor-schema' );
        return $columns;
    }

    public function render_column( $column, $post_id ) {
        $meta = $this->get_meta( $post_id );
        switch ( $column ) {
            case 'anchor_event_start':
                echo esc_html( $this->format_date_time( $meta ) );
                break;
            case 'anchor_event_status':
                echo esc_html( ucfirst( $this->get_event_status( $post_id, $meta ) ) );
                break;
            case 'anchor_event_venue':
                echo esc_html( $meta['venue'] );
                break;
            case 'anchor_event_capacity':
                echo esc_html( $meta['capacity'] ? $meta['capacity'] : '-' );
                break;
        }
    }

    public function sortable_columns( $columns ) {
        $columns['anchor_event_start'] = 'anchor_event_start';
        $columns['anchor_event_status'] = 'anchor_event_status';
        $columns['anchor_event_venue'] = 'anchor_event_venue';
        $columns['anchor_event_capacity'] = 'anchor_event_capacity';
        return $columns;
    }

    public function admin_sorting( $query ) {
        if ( ! \is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( $query->get( 'post_type' ) !== self::CPT ) {
            return;
        }
        $orderby = $query->get( 'orderby' );
        switch ( $orderby ) {
            case 'anchor_event_start':
                $query->set( 'meta_key', $this->meta_key( 'start_ts' ) );
                $query->set( 'orderby', 'meta_value_num' );
                break;
            case 'anchor_event_status':
                $query->set( 'meta_key', $this->meta_key( 'status' ) );
                $query->set( 'orderby', 'meta_value' );
                break;
            case 'anchor_event_venue':
                $query->set( 'meta_key', $this->meta_key( 'venue' ) );
                $query->set( 'orderby', 'meta_value' );
                break;
            case 'anchor_event_capacity':
                $query->set( 'meta_key', $this->meta_key( 'capacity' ) );
                $query->set( 'orderby', 'meta_value_num' );
                break;
        }
    }

    public function add_quick_filters( $views ) {
        $base_url = \admin_url( 'edit.php?post_type=' . self::CPT );
        $current = sanitize_text_field( $_GET['event_status'] ?? '' );
        $statuses = [
            'upcoming' => __( 'Upcoming', 'anchor-schema' ),
            'past' => __( 'Past', 'anchor-schema' ),
            'cancelled' => __( 'Cancelled', 'anchor-schema' ),
        ];
        foreach ( $statuses as $key => $label ) {
            $count = $this->count_events_by_status( $key );
            $url = \add_query_arg( 'event_status', $key, $base_url );
            $class = $current === $key ? 'class="current"' : '';
            $views[ 'anchor_event_' . $key ] = '<a href="' . esc_url( $url ) . '" ' . $class . '>' . esc_html( $label ) . ' <span class="count">(' . intval( $count ) . ')</span></a>';
        }
        return $views;
    }

    public function apply_quick_filters( $query ) {
        if ( ! \is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( $query->get( 'post_type' ) !== self::CPT ) {
            return;
        }
        $status = sanitize_text_field( $_GET['event_status'] ?? '' );
        if ( ! $status ) {
            return;
        }
        $query->set( 'meta_query', [
            [
                'key' => $this->meta_key( 'status' ),
                'value' => $status,
                'compare' => '=',
            ],
        ] );
    }

    public function template_include( $template ) {
        if ( \is_singular( self::CPT ) ) {
            return $this->locate_template( 'single-event.php' );
        }
        if ( \is_post_type_archive( self::CPT ) ) {
            return $this->locate_template( 'archive-event.php' );
        }
        if ( \is_tax( Series::TAXONOMY ) ) {
            return $this->locate_template( 'taxonomy-event_series.php' );
        }
        return $template;
    }

    private function locate_template( $file ) {
        $settings = $this->get_settings();
        if ( $settings['template_source'] === 'theme' ) {
            $theme_template = \locate_template( 'events/' . $file );
            if ( $theme_template ) {
                return $theme_template;
            }
        }
        return \plugin_dir_path( __FILE__ ) . 'templates/' . $file;
    }

    public function shortcode_events_list( $atts ) {
        $atts = shortcode_atts( [
            'category' => '',
            'tag' => '',
            'type' => '',
            'status' => '',
            'start_date' => '',
            'end_date' => '',
            'orderby' => 'date',
            'order' => 'ASC',
            'limit' => 10,
            'show_past' => 'no',
        ], $atts );

        return $this->render_events_list( $atts, 'shortcode' );
    }

    public function shortcode_featured_events( $atts ) {
        $atts = shortcode_atts( [
            'limit' => 5,
            'orderby' => 'priority',
            'order' => 'DESC',
        ], $atts );
        $atts['featured'] = 'yes';
        return $this->render_events_list( $atts, 'featured' );
    }

    public function shortcode_event_calendar( $atts ) {
        $atts = shortcode_atts( [
            'view' => 'month',
            'month' => '',
            'show_past' => 'yes',
        ], $atts );

        $this->enqueue_frontend_assets();

        if ( $atts['view'] === 'list' ) {
            return $this->render_events_list( [ 'limit' => 20 ], 'calendar' );
        }

        return $this->render_calendar_month( $atts );
    }

    public function shortcode_event_registration( $atts ) {
        $atts = \shortcode_atts( [
            'id' => 0,
            'slug' => '',
            'show_title' => 'no',
            'show_notice' => 'yes',
        ], $atts );

        $event_id = (int) $atts['id'];
        if ( ! $event_id && ! empty( $atts['slug'] ) ) {
            $post = \get_page_by_path( sanitize_title( $atts['slug'] ), OBJECT, self::CPT );
            if ( $post ) {
                $event_id = (int) $post->ID;
            }
        }
        if ( ! $event_id ) {
            $queried = \get_queried_object();
            if ( $queried instanceof \WP_Post && $queried->post_type === self::CPT ) {
                $event_id = (int) $queried->ID;
            }
        }
        if ( ! $event_id ) {
            return '<div class="anchor-event-registration anchor-event-registration-closed">'
                . esc_html__( 'No event specified for registration.', 'anchor-schema' )
                . '</div>';
        }

        $this->enqueue_frontend_assets();

        $output = '';
        if ( $atts['show_title'] === 'yes' ) {
            $output .= '<h2 class="anchor-event-title">' . esc_html( \get_the_title( $event_id ) ) . '</h2>';
        }
        if ( $atts['show_notice'] === 'yes' ) {
            $output .= $this->render_registration_notice();
        }
        $output .= $this->render_registration_form( $event_id );

        return $output;
    }

    public function shortcode_event_gallery( $atts ) {
        $atts = \shortcode_atts( [
            'id' => 0,
            'slug' => '',
            'size' => 'large',
            'columns' => 3,
        ], $atts );

        $event_id = (int) $atts['id'];
        if ( ! $event_id && ! empty( $atts['slug'] ) ) {
            $post = \get_page_by_path( sanitize_title( $atts['slug'] ), OBJECT, self::CPT );
            if ( $post ) {
                $event_id = (int) $post->ID;
            }
        }
        if ( ! $event_id ) {
            $queried = \get_queried_object();
            if ( $queried instanceof \WP_Post && $queried->post_type === self::CPT ) {
                $event_id = (int) $queried->ID;
            }
        }
        if ( ! $event_id ) {
            return '';
        }

        return $this->render_event_gallery( $event_id, $atts );
    }

    public function render_event_gallery( $event_id, $atts = [] ) {
        $atts = \wp_parse_args( $atts, [
            'size' => 'large',
            'columns' => 3,
        ] );

        $meta = $this->get_meta( $event_id );
        $ids = array_map( 'intval', (array) $meta['gallery'] );
        $ids = array_values( array_filter( $ids ) );
        if ( empty( $ids ) ) {
            return '';
        }

        $this->enqueue_frontend_assets();

        $columns = max( 1, min( 6, (int) $atts['columns'] ) );
        $size = sanitize_text_field( $atts['size'] );

        $output = '<div class="anchor-event-gallery" data-columns="' . esc_attr( $columns ) . '">';
        $output .= '<div class="anchor-event-gallery-track">';
        foreach ( $ids as $attachment_id ) {
            $full = \wp_get_attachment_image_url( $attachment_id, 'full' );
            $img = \wp_get_attachment_image( $attachment_id, $size, false, [
                'class' => 'anchor-event-gallery-image',
                'loading' => 'lazy',
            ] );
            if ( ! $img ) {
                continue;
            }
            $caption = trim( (string) \get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
            if ( $caption === '' ) {
                $attachment_post = \get_post( $attachment_id );
                $caption = $attachment_post ? trim( (string) $attachment_post->post_excerpt ) : '';
            }
            $output .= '<a class="anchor-event-gallery-slide" href="' . esc_url( $full ) . '" data-anchor-lightbox="1" data-caption="' . esc_attr( $caption ) . '">' . $img . '</a>';
        }
        $output .= '</div>';
        $output .= '<button type="button" class="anchor-event-gallery-nav anchor-event-gallery-prev" aria-label="' . esc_attr__( 'Previous image', 'anchor-schema' ) . '">&larr;</button>';
        $output .= '<button type="button" class="anchor-event-gallery-nav anchor-event-gallery-next" aria-label="' . esc_attr__( 'Next image', 'anchor-schema' ) . '">&rarr;</button>';
        $output .= '</div>';

        return $output;
    }

    public function shortcode_event_registrants_list( $atts ) {
        if ( ! \current_user_can( 'edit_others_posts' ) ) {
            return '';
        }

        $atts = \shortcode_atts( [
            'show_past' => 'yes',
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'ASC',
        ], $atts );

        $this->enqueue_frontend_assets();

        $meta_query = [ $this->build_hide_clause() ];
        if ( $atts['show_past'] === 'no' ) {
            $meta_query[] = $this->build_visibility_clause();
        }

        $args = [
            'post_type' => self::CPT,
            'post_status' => [ 'publish', 'draft', 'future', 'private' ],
            'posts_per_page' => max( 1, min( 200, (int) $atts['limit'] ) ),
            'meta_query' => $meta_query,
            'orderby' => 'meta_value_num',
            'meta_key' => $this->meta_key( 'start_ts' ),
            'order' => strtoupper( $atts['order'] ) === 'DESC' ? 'DESC' : 'ASC',
        ];
        $events = \get_posts( $args );

        if ( empty( $events ) ) {
            return '<div class="anchor-event-admin-list"><p>' . esc_html__( 'No events found.', 'anchor-schema' ) . '</p></div>';
        }

        $output = '<div class="anchor-event-admin-list">';
        foreach ( $events as $event ) {
            $meta = $this->get_meta( $event->ID );
            $registrations = $this->get_registrations( $event->ID, 0 );
            $count = count( $registrations );
            $attendees = $this->get_attendee_count( $event->ID );
            $waitlist = $this->get_registration_count( $event->ID, 'waitlist' );
            $edit_link = \get_edit_post_link( $event->ID );
            $export_url = \wp_nonce_url(
                \admin_url( 'admin-post.php?action=anchor_event_export&event_id=' . $event->ID ),
                'anchor_event_export'
            );
            $date_label = $this->format_date_time( $meta );

            $output .= '<details class="anchor-event-admin-item">';
            $output .= '<summary class="anchor-event-admin-summary">';
            $output .= '<span class="anchor-event-admin-name">' . esc_html( \get_the_title( $event->ID ) ) . '</span>';
            if ( $date_label ) {
                $output .= ' <span class="anchor-event-admin-date">' . esc_html( $date_label ) . '</span>';
            }
            $output .= ' <span class="anchor-event-admin-count">' . esc_html( sprintf(
                \_n( '%d registrant', '%d registrants', $count, 'anchor-schema' ),
                $count
            ) );
            if ( $attendees !== $count ) {
                $output .= ' <span class="anchor-event-admin-attendees">(' . esc_html( sprintf( __( '%d total attendees', 'anchor-schema' ), $attendees ) ) . ')</span>';
            }
            $output .= '</span>';
            $output .= '</summary>';
            $output .= '<div class="anchor-event-admin-body">';
            $output .= '<p class="anchor-event-admin-meta">';
            if ( $waitlist ) {
                $output .= '<strong>' . esc_html__( 'Waitlist', 'anchor-schema' ) . ':</strong> ' . esc_html( $waitlist ) . ' &middot; ';
            }
            if ( $edit_link ) {
                $output .= '<a href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Edit event', 'anchor-schema' ) . '</a> &middot; ';
            }
            $output .= '<a href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'anchor-schema' ) . '</a>';
            $output .= '</p>';

            if ( empty( $registrations ) ) {
                $output .= '<p class="anchor-event-admin-empty">' . esc_html__( 'No registrants yet.', 'anchor-schema' ) . '</p>';
            } else {
                $output .= '<table class="anchor-event-admin-table"><thead><tr>';
                $output .= '<th>' . esc_html__( 'Name', 'anchor-schema' ) . '</th>';
                $output .= '<th>' . esc_html__( 'Email', 'anchor-schema' ) . '</th>';
                $output .= '<th>' . esc_html__( 'Guests', 'anchor-schema' ) . '</th>';
                $output .= '<th>' . esc_html__( 'Status', 'anchor-schema' ) . '</th>';
                $output .= '<th>' . esc_html__( 'Date', 'anchor-schema' ) . '</th>';
                $output .= '</tr></thead><tbody>';
                foreach ( $registrations as $reg ) {
                    $output .= '<tr>';
                    $output .= '<td>' . esc_html( $reg['name'] ) . '</td>';
                    $output .= '<td><a href="mailto:' . esc_attr( $reg['email'] ) . '">' . esc_html( $reg['email'] ) . '</a></td>';
                    $output .= '<td>' . esc_html( (int) ( $reg['guests'] ?? 0 ) ) . '</td>';
                    $output .= '<td>' . esc_html( ucfirst( $reg['status'] ) ) . '</td>';
                    $output .= '<td>' . esc_html( $reg['date'] ) . '</td>';
                    $output .= '</tr>';
                }
                $output .= '</tbody></table>';
            }
            $output .= '</div></details>';
        }
        $output .= '</div>';

        return $output;
    }

    public function shortcode_event_manager( $atts ) {
        $this->enqueue_frontend_assets();

        if ( ! \is_user_logged_in() ) {
            return '<div class="anchor-event-manager">' . $this->render_event_manager_notice() . $this->render_event_manager_login_form() . '</div>';
        }

        if ( ! \current_user_can( 'edit_others_posts' ) ) {
            return '<div class="anchor-event-manager">' . $this->render_event_manager_notice() . $this->render_event_manager_no_access() . '</div>';
        }

        $atts = \shortcode_atts( [
            'show_past' => 'yes',
            'limit' => 50,
            'order' => 'ASC',
        ], $atts );

        $action = isset( $_GET['event_action'] ) ? sanitize_key( $_GET['event_action'] ) : '';
        $event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;

        $this->enqueue_frontend_assets();
        \wp_enqueue_media();
        \wp_enqueue_script( 'jquery-ui-sortable' );
        \wp_enqueue_script(
            'anchor-events-manager-frontend',
            \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/manager.js' ),
            [ 'jquery', 'jquery-ui-sortable' ],
            '1.0.1',
            true
        );

        $output = '<div class="anchor-event-manager">';
        $output .= $this->render_event_manager_notice();

        if ( $action === 'new' ) {
            $output .= $this->render_event_manager_form( 0 );
        } elseif ( $action === 'edit' && $event_id ) {
            if ( \current_user_can( 'edit_post', $event_id ) && \get_post_type( $event_id ) === self::CPT ) {
                $output .= $this->render_event_manager_form( $event_id );
            } else {
                $output .= '<p>' . esc_html__( 'You do not have permission to edit that event.', 'anchor-schema' ) . '</p>';
            }
        } else {
            $output .= $this->render_event_manager_list( $atts );
        }

        $output .= '</div>';
        return $output;
    }

    private function render_event_manager_notice() {
        if ( empty( $_GET['event_manager_notice'] ) ) {
            return '';
        }
        $notice = sanitize_text_field( wp_unslash( $_GET['event_manager_notice'] ) );
        $map = [
            'saved'   => [ 'ok',  __( 'Event saved.', 'anchor-schema' ) ],
            'created' => [ 'ok',  __( 'Event created.', 'anchor-schema' ) ],
            'deleted' => [ 'ok',  __( 'Event moved to trash.', 'anchor-schema' ) ],
            'denied'  => [ 'err', __( 'You do not have permission to do that.', 'anchor-schema' ) ],
            'missing' => [ 'err', __( 'Event title and start date are required.', 'anchor-schema' ) ],
            'error'   => [ 'err', __( 'Something went wrong. Please try again.', 'anchor-schema' ) ],
            'login_failed'   => [ 'err', __( 'Invalid username or password. Please try again.', 'anchor-schema' ) ],
            'login_empty'    => [ 'err', __( 'Please enter your username and password.', 'anchor-schema' ) ],
            'logged_out'     => [ 'ok',  __( 'You have been signed out.', 'anchor-schema' ) ],
            'lostpass_sent'  => [ 'ok',  __( 'Check your email for a link to reset your password.', 'anchor-schema' ) ],
            'lostpass_error' => [ 'err', __( 'We could not find an account matching that username or email.', 'anchor-schema' ) ],
            'lostpass_empty' => [ 'err', __( 'Please enter your username or email address.', 'anchor-schema' ) ],
        ];
        if ( ! isset( $map[ $notice ] ) ) {
            return '';
        }
        $class = $map[ $notice ][0] === 'ok' ? 'is-ok' : 'is-error';
        return '<div class="anchor-event-manager-notice ' . esc_attr( $class ) . '">' . esc_html( $map[ $notice ][1] ) . '</div>';
    }

    private function get_event_manager_page_url() {
        $url = '';
        if ( \is_singular() || \is_page() ) {
            $url = \get_permalink();
        }
        if ( ! $url && isset( $_SERVER['REQUEST_URI'] ) ) {
            $url = \home_url( \wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        if ( ! $url ) {
            $url = \home_url( '/' );
        }
        $url = \remove_query_arg( [ 'event_manager_notice', 'event_manager_view' ], $url );
        return $url;
    }

    private function render_event_manager_login_form() {
        $page_url = $this->get_event_manager_page_url();
        $view = isset( $_GET['event_manager_view'] ) ? sanitize_key( $_GET['event_manager_view'] ) : '';
        $action_url = \admin_url( 'admin-post.php' );

        if ( $view === 'lostpassword' ) {
            $login_url = \add_query_arg( 'event_manager_view', 'login', $page_url );
            $out  = '<div class="anchor-event-manager-auth">';
            $out .= '<h2>' . esc_html__( 'Reset your password', 'anchor-schema' ) . '</h2>';
            $out .= '<p>' . esc_html__( 'Enter your username or email address. You will receive a link to create a new password via email.', 'anchor-schema' ) . '</p>';
            $out .= '<form class="anchor-event-manager-login-form" method="post" action="' . esc_url( $action_url ) . '">';
            $out .= '<input type="hidden" name="action" value="anchor_event_manager_lostpass" />';
            $out .= '<input type="hidden" name="redirect_to" value="' . esc_url( $page_url ) . '" />';
            $out .= \wp_nonce_field( 'anchor_event_manager_lostpass', '_anchor_lostpass_nonce', true, false );
            $out .= '<div class="anchor-event-field"><label for="anchor_event_user_login">' . esc_html__( 'Username or email address', 'anchor-schema' ) . '</label>';
            $out .= '<input type="text" id="anchor_event_user_login" name="user_login" required autocomplete="username" /></div>';
            $out .= '<div class="anchor-event-manager-submit">';
            $out .= '<button type="submit" class="anchor-event-button">' . esc_html__( 'Email me a reset link', 'anchor-schema' ) . '</button>';
            $out .= '<a class="anchor-event-button-secondary" href="' . esc_url( $login_url ) . '">' . esc_html__( 'Back to sign in', 'anchor-schema' ) . '</a>';
            $out .= '</div></form></div>';
            return $out;
        }

        $lost_url = \add_query_arg( 'event_manager_view', 'lostpassword', $page_url );
        $out  = '<div class="anchor-event-manager-auth">';
        $out .= '<h2>' . esc_html__( 'Sign in to manage events', 'anchor-schema' ) . '</h2>';
        $out .= '<form class="anchor-event-manager-login-form" method="post" action="' . esc_url( $action_url ) . '">';
        $out .= '<input type="hidden" name="action" value="anchor_event_manager_login" />';
        $out .= '<input type="hidden" name="redirect_to" value="' . esc_url( $page_url ) . '" />';
        $out .= \wp_nonce_field( 'anchor_event_manager_login', '_anchor_login_nonce', true, false );
        $out .= '<div class="anchor-event-field"><label for="anchor_event_log">' . esc_html__( 'Username or email', 'anchor-schema' ) . '</label>';
        $out .= '<input type="text" id="anchor_event_log" name="log" required autocomplete="username" /></div>';
        $out .= '<div class="anchor-event-field"><label for="anchor_event_pwd">' . esc_html__( 'Password', 'anchor-schema' ) . '</label>';
        $out .= '<input type="password" id="anchor_event_pwd" name="pwd" required autocomplete="current-password" /></div>';
        $out .= '<label class="anchor-event-manager-remember"><input type="checkbox" name="rememberme" value="forever" /> ' . esc_html__( 'Remember me', 'anchor-schema' ) . '</label>';
        $out .= '<div class="anchor-event-manager-submit">';
        $out .= '<button type="submit" class="anchor-event-button">' . esc_html__( 'Sign in', 'anchor-schema' ) . '</button>';
        $out .= '<a class="anchor-event-manager-lostlink" href="' . esc_url( $lost_url ) . '">' . esc_html__( 'Lost your password?', 'anchor-schema' ) . '</a>';
        $out .= '</div></form></div>';
        return $out;
    }

    private function render_event_manager_no_access() {
        $page_url = $this->get_event_manager_page_url();
        $logout_url = \add_query_arg( [
            'action' => 'anchor_event_manager_logout',
            '_wpnonce' => \wp_create_nonce( 'anchor_event_manager_logout' ),
            'redirect_to' => rawurlencode( $page_url ),
        ], \admin_url( 'admin-post.php' ) );
        $user = \wp_get_current_user();
        $out  = '<div class="anchor-event-manager-auth">';
        $out .= '<h2>' . esc_html__( 'No access', 'anchor-schema' ) . '</h2>';
        $out .= '<p>' . sprintf(
            esc_html__( 'You are signed in as %s, but that account does not have permission to manage events. Sign in with an editor or administrator account.', 'anchor-schema' ),
            '<strong>' . esc_html( $user->user_login ) . '</strong>'
        ) . '</p>';
        $out .= '<p><a class="anchor-event-button-secondary" href="' . esc_url( $logout_url ) . '">' . esc_html__( 'Sign out', 'anchor-schema' ) . '</a></p>';
        $out .= '</div>';
        return $out;
    }

    public function handle_event_manager_login() {
        $redirect = isset( $_POST['redirect_to'] ) ? \esc_url_raw( \wp_unslash( $_POST['redirect_to'] ) ) : \home_url( '/' );
        $nonce = isset( $_POST['_anchor_login_nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['_anchor_login_nonce'] ) ) : '';
        if ( ! \wp_verify_nonce( $nonce, 'anchor_event_manager_login' ) ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'error', $redirect ) );
            exit;
        }

        $log = isset( $_POST['log'] ) ? trim( \wp_unslash( $_POST['log'] ) ) : '';
        $pwd = isset( $_POST['pwd'] ) ? (string) \wp_unslash( $_POST['pwd'] ) : '';
        if ( $log === '' || $pwd === '' ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'login_empty', $redirect ) );
            exit;
        }

        $creds = [
            'user_login' => $log,
            'user_password' => $pwd,
            'remember' => ! empty( $_POST['rememberme'] ),
        ];
        $user = \wp_signon( $creds, \is_ssl() );
        if ( \is_wp_error( $user ) ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'login_failed', $redirect ) );
            exit;
        }

        \wp_set_current_user( $user->ID );
        \wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_event_manager_logout() {
        $redirect = isset( $_GET['redirect_to'] ) ? \esc_url_raw( \wp_unslash( $_GET['redirect_to'] ) ) : \home_url( '/' );
        \check_admin_referer( 'anchor_event_manager_logout' );
        \wp_logout();
        \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'logged_out', $redirect ) );
        exit;
    }

    public function handle_event_manager_lostpass() {
        $redirect = isset( $_POST['redirect_to'] ) ? \esc_url_raw( \wp_unslash( $_POST['redirect_to'] ) ) : \home_url( '/' );
        $lost_view_url = \add_query_arg( 'event_manager_view', 'lostpassword', $redirect );

        $nonce = $_POST['_anchor_lostpass_nonce'] ?? '';
        if ( ! \wp_verify_nonce( $nonce, 'anchor_event_manager_lostpass' ) ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'error', $lost_view_url ) );
            exit;
        }

        $login = isset( $_POST['user_login'] ) ? trim( \wp_unslash( $_POST['user_login'] ) ) : '';
        if ( $login === '' ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'lostpass_empty', $lost_view_url ) );
            exit;
        }

        if ( strpos( $login, '@' ) !== false ) {
            $user = \get_user_by( 'email', $login );
        } else {
            $user = \get_user_by( 'login', $login );
        }
        // Always report success to avoid leaking which accounts exist.
        if ( ! $user ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'lostpass_sent', $redirect ) );
            exit;
        }

        $allow = \apply_filters( 'allow_password_reset', true, $user->ID );
        if ( \is_wp_error( $allow ) || ! $allow ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'lostpass_error', $lost_view_url ) );
            exit;
        }

        $key = \get_password_reset_key( $user );
        if ( \is_wp_error( $key ) ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'lostpass_error', $lost_view_url ) );
            exit;
        }

        $reset_url = \network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
        $blogname = \wp_specialchars_decode( \get_option( 'blogname' ), ENT_QUOTES );
        $message  = sprintf( __( 'Someone has requested a password reset for the following account: %s', 'anchor-schema' ), $blogname ) . "\r\n\r\n";
        $message .= sprintf( __( 'Username: %s', 'anchor-schema' ), $user->user_login ) . "\r\n\r\n";
        $message .= __( 'If this was a mistake, ignore this email and nothing will happen.', 'anchor-schema' ) . "\r\n\r\n";
        $message .= __( 'To reset your password, visit the following address:', 'anchor-schema' ) . "\r\n\r\n";
        $message .= $reset_url . "\r\n";

        $title = sprintf( __( '[%s] Password Reset', 'anchor-schema' ), $blogname );
        $title = \apply_filters( 'retrieve_password_title', $title, $user->user_login, $user );
        $message = \apply_filters( 'retrieve_password_message', $message, $key, $user->user_login, $user );

        if ( $message && ! \wp_mail( $user->user_email, \wp_specialchars_decode( $title ), $message ) ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'lostpass_error', $lost_view_url ) );
            exit;
        }

        \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'lostpass_sent', $redirect ) );
        exit;
    }

    private function render_event_manager_list( $atts ) {
        $meta_query = [ $this->build_hide_clause() ];
        if ( $atts['show_past'] === 'no' ) {
            $meta_query[] = $this->build_visibility_clause();
        }

        $args = [
            'post_type' => self::CPT,
            'post_status' => [ 'publish', 'draft', 'future', 'private', 'pending' ],
            'posts_per_page' => max( 1, min( 200, (int) $atts['limit'] ) ),
            'meta_query' => $meta_query,
            'orderby' => 'meta_value_num',
            'meta_key' => $this->meta_key( 'start_ts' ),
            'order' => strtoupper( $atts['order'] ) === 'DESC' ? 'DESC' : 'ASC',
        ];
        $events = \get_posts( $args );

        $new_url = \add_query_arg( [ 'event_action' => 'new' ], \remove_query_arg( [ 'event_id', 'event_manager_notice' ] ) );

        $output = '<div class="anchor-event-manager-toolbar">';
        $output .= '<h2>' . esc_html__( 'Events', 'anchor-schema' ) . '</h2>';
        $output .= '<a class="anchor-event-button" href="' . esc_url( $new_url ) . '">+ ' . esc_html__( 'New event', 'anchor-schema' ) . '</a>';
        $output .= '</div>';

        if ( empty( $events ) ) {
            $output .= '<p>' . esc_html__( 'No events yet.', 'anchor-schema' ) . '</p>';
            return $output;
        }

        $output .= '<div class="anchor-event-admin-list">';
        foreach ( $events as $event ) {
            $output .= $this->render_event_manager_item( $event );
        }
        $output .= '</div>';

        return $output;
    }

    private function render_event_manager_item( $event ) {
        $meta = $this->get_meta( $event->ID );
        $registrations = $this->get_registrations( $event->ID, 0 );
        $count = count( $registrations );
        $attendees = $this->get_attendee_count( $event->ID );
        $waitlist = $this->get_registration_count( $event->ID, 'waitlist' );

        $base_url = \remove_query_arg( [ 'event_action', 'event_id', 'event_manager_notice' ] );
        $edit_url = \add_query_arg( [ 'event_action' => 'edit', 'event_id' => $event->ID ], $base_url );
        $delete_url = \wp_nonce_url(
            \add_query_arg( [
                'action' => 'anchor_event_manager_delete',
                'event_id' => $event->ID,
                'redirect_to' => \urlencode( $base_url ),
            ], \admin_url( 'admin-post.php' ) ),
            'anchor_event_manager_delete_' . $event->ID
        );
        $export_url = \wp_nonce_url(
            \admin_url( 'admin-post.php?action=anchor_event_export&event_id=' . $event->ID ),
            'anchor_event_export'
        );
        $date_label = $this->format_date_time( $meta );
        $status = ucfirst( (string) $meta['status'] );

        $output = '<details class="anchor-event-admin-item">';
        $output .= '<summary class="anchor-event-admin-summary">';
        $output .= '<span class="anchor-event-admin-name">' . esc_html( \get_the_title( $event->ID ) ?: __( '(untitled)', 'anchor-schema' ) ) . '</span>';
        if ( $date_label ) {
            $output .= ' <span class="anchor-event-admin-date">' . esc_html( $date_label ) . '</span>';
        }
        if ( $event->post_status !== 'publish' ) {
            $output .= ' <span class="anchor-event-admin-date">[' . esc_html( $event->post_status ) . ']</span>';
        }
        $output .= ' <span class="anchor-event-admin-count">' . esc_html( sprintf(
            \_n( '%d registrant', '%d registrants', $count, 'anchor-schema' ),
            $count
        ) );
        if ( $attendees !== $count ) {
            $output .= ' <span class="anchor-event-admin-attendees">(' . esc_html( sprintf( __( '%d total attendees', 'anchor-schema' ), $attendees ) ) . ')</span>';
        }
        $output .= '</span>';
        $output .= '</summary>';

        $output .= '<div class="anchor-event-admin-body">';
        $output .= '<p class="anchor-event-admin-meta">';
        if ( $status ) {
            $output .= '<strong>' . esc_html__( 'Status', 'anchor-schema' ) . ':</strong> ' . esc_html( $status ) . ' &middot; ';
        }
        if ( $waitlist ) {
            $output .= '<strong>' . esc_html__( 'Waitlist', 'anchor-schema' ) . ':</strong> ' . esc_html( $waitlist ) . ' &middot; ';
        }
        $output .= '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'anchor-schema' ) . '</a> &middot; ';
        $output .= '<a href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'anchor-schema' ) . '</a> &middot; ';
        $output .= '<a class="anchor-event-admin-delete" href="' . esc_url( $delete_url ) . '" data-confirm="' . esc_attr__( 'Move this event to trash?', 'anchor-schema' ) . '">' . esc_html__( 'Delete', 'anchor-schema' ) . '</a>';
        $output .= '</p>';

        if ( empty( $registrations ) ) {
            $output .= '<p class="anchor-event-admin-empty">' . esc_html__( 'No registrants yet.', 'anchor-schema' ) . '</p>';
        } else {
            $output .= '<table class="anchor-event-admin-table"><thead><tr>';
            $output .= '<th>' . esc_html__( 'Name', 'anchor-schema' ) . '</th>';
            $output .= '<th>' . esc_html__( 'Email', 'anchor-schema' ) . '</th>';
            $output .= '<th>' . esc_html__( 'Guests', 'anchor-schema' ) . '</th>';
            $output .= '<th>' . esc_html__( 'Status', 'anchor-schema' ) . '</th>';
            $output .= '<th>' . esc_html__( 'Date', 'anchor-schema' ) . '</th>';
            $output .= '</tr></thead><tbody>';
            foreach ( $registrations as $reg ) {
                $output .= '<tr>';
                $output .= '<td>' . esc_html( $reg['name'] ) . '</td>';
                $output .= '<td><a href="mailto:' . esc_attr( $reg['email'] ) . '">' . esc_html( $reg['email'] ) . '</a></td>';
                $output .= '<td>' . esc_html( (int) ( $reg['guests'] ?? 0 ) ) . '</td>';
                $output .= '<td>' . esc_html( ucfirst( $reg['status'] ) ) . '</td>';
                $output .= '<td>' . esc_html( $reg['date'] ) . '</td>';
                $output .= '</tr>';
            }
            $output .= '</tbody></table>';
        }
        $output .= '</div></details>';

        return $output;
    }

    private function render_event_manager_form( $event_id ) {
        $is_edit = $event_id > 0;
        $post = $is_edit ? \get_post( $event_id ) : null;
        if ( $is_edit && ( ! $post || $post->post_type !== self::CPT ) ) {
            return '<p>' . esc_html__( 'Event not found.', 'anchor-schema' ) . '</p>';
        }

        $meta = $is_edit ? $this->get_meta( $event_id ) : $this->get_meta_defaults();
        $title = $is_edit ? $post->post_title : '';
        $content = $is_edit ? $post->post_content : '';
        $status = $is_edit ? $post->post_status : 'publish';
        $thumbnail_id = $is_edit ? (int) \get_post_thumbnail_id( $event_id ) : 0;
        $gallery_ids = array_map( 'intval', (array) $meta['gallery'] );
        $gallery_ids = array_values( array_filter( $gallery_ids ) );

        $base_url = \remove_query_arg( [ 'event_action', 'event_id', 'event_manager_notice' ] );
        $timezone_options = \wp_timezone_choice( $meta['timezone'] );

        // Event-type / registration-mode authoring (Task 1.3 metabox parity,
        // Task 1.5). These resolvers apply the same enum-fallback validation as
        // the metabox and are safe to call with $event_id === 0 (new event):
        // get_post_meta( 0, ... ) reads nothing, so each resolver returns its
        // documented default (single / free / []).
        $event_type = $this->event_type( $event_id );
        $registration_mode = $this->registration_mode( $event_id );
        $wc_active = \class_exists( 'WooCommerce' );
        $sessions = $this->get_sessions( $event_id );

        ob_start();
        ?>
        <form class="anchor-event-manager-form" method="post" action="<?php echo esc_url( \admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="anchor_event_manager_save" />
            <input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
            <input type="hidden" name="redirect_to" value="<?php echo esc_url( $base_url ); ?>" />
            <?php \wp_nonce_field( 'anchor_event_manager_save', 'anchor_event_manager_nonce' ); ?>

            <div class="anchor-event-manager-toolbar">
                <h2><?php echo $is_edit ? esc_html__( 'Edit event', 'anchor-schema' ) : esc_html__( 'New event', 'anchor-schema' ); ?></h2>
                <a class="anchor-event-button-secondary" href="<?php echo esc_url( $base_url ); ?>"><?php echo esc_html__( 'Back to list', 'anchor-schema' ); ?></a>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Basics', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field" style="grid-column:1/-1;">
                        <label for="anchor_event_title"><?php echo esc_html__( 'Title', 'anchor-schema' ); ?> *</label>
                        <input type="text" id="anchor_event_title" name="anchor_event_title" value="<?php echo esc_attr( $title ); ?>" required />
                    </div>
                    <div class="anchor-event-field" style="grid-column:1/-1;">
                        <label for="anchor_event_content"><?php echo esc_html__( 'Description', 'anchor-schema' ); ?></label>
                        <textarea id="anchor_event_content" name="anchor_event_content" rows="6"><?php echo esc_textarea( $content ); ?></textarea>
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_post_status"><?php echo esc_html__( 'Publish status', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_post_status" name="anchor_event_post_status">
                            <option value="publish" <?php selected( $status, 'publish' ); ?>><?php echo esc_html__( 'Published', 'anchor-schema' ); ?></option>
                            <option value="draft" <?php selected( $status, 'draft' ); ?>><?php echo esc_html__( 'Draft', 'anchor-schema' ); ?></option>
                            <option value="private" <?php selected( $status, 'private' ); ?>><?php echo esc_html__( 'Private', 'anchor-schema' ); ?></option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Event Type & Registration', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_type"><?php echo esc_html__( 'Event Type', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_type" name="anchor_event_type">
                            <option value="single" <?php selected( $event_type, 'single' ); ?>><?php echo esc_html__( 'Single event', 'anchor-schema' ); ?></option>
                            <option value="multisession" <?php selected( $event_type, 'multisession' ); ?>><?php echo esc_html__( 'Multi-session series', 'anchor-schema' ); ?></option>
                            <option value="offering" <?php selected( $event_type, 'offering' ); ?>><?php echo esc_html__( 'Pick-one offerings', 'anchor-schema' ); ?></option>
                            <option value="recurring" <?php selected( $event_type, 'recurring' ); ?>><?php echo esc_html__( 'Recurring schedule', 'anchor-schema' ); ?></option>
                        </select>
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_registration_mode"><?php echo esc_html__( 'Registration', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_registration_mode" name="anchor_event_registration_mode">
                            <option value="wc" <?php selected( $registration_mode, 'wc' ); ?> <?php disabled( ! $wc_active ); ?>><?php echo esc_html__( 'WooCommerce ticketed', 'anchor-schema' ); ?><?php echo $wc_active ? '' : ' ' . esc_html__( '(requires WooCommerce)', 'anchor-schema' ); ?></option>
                            <option value="free" <?php selected( $registration_mode, 'free' ); ?>><?php echo esc_html__( 'Free registration', 'anchor-schema' ); ?></option>
                            <option value="external" <?php selected( $registration_mode, 'external' ); ?>><?php echo esc_html__( 'External registration', 'anchor-schema' ); ?></option>
                        </select>
                        <?php if ( ! $wc_active ) : ?>
                            <p class="description"><?php echo esc_html__( 'WooCommerce is inactive, so WooCommerce-ticketed registration is unavailable until it is activated.', 'anchor-schema' ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Date & Time', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_start_date"><?php echo esc_html__( 'Start date', 'anchor-schema' ); ?> *</label>
                        <input type="date" id="anchor_event_start_date" name="anchor_event_start_date" value="<?php echo esc_attr( $meta['start_date'] ); ?>" required />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_end_date"><?php echo esc_html__( 'End date', 'anchor-schema' ); ?></label>
                        <input type="date" id="anchor_event_end_date" name="anchor_event_end_date" value="<?php echo esc_attr( $meta['end_date'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-time-fields">
                        <label for="anchor_event_start_time"><?php echo esc_html__( 'Start time', 'anchor-schema' ); ?></label>
                        <input type="time" id="anchor_event_start_time" name="anchor_event_start_time" value="<?php echo esc_attr( $meta['start_time'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-time-fields">
                        <label for="anchor_event_end_time"><?php echo esc_html__( 'End time', 'anchor-schema' ); ?></label>
                        <input type="time" id="anchor_event_end_time" name="anchor_event_end_time" value="<?php echo esc_attr( $meta['end_time'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_timezone"><?php echo esc_html__( 'Timezone', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_timezone" name="anchor_event_timezone"><?php echo $timezone_options; ?></select>
                    </div>
                    <div class="anchor-event-field">
                        <label><input type="checkbox" id="anchor_event_all_day" name="anchor_event_all_day" value="1" <?php checked( $meta['all_day'] ); ?> /> <?php echo esc_html__( 'All-day event', 'anchor-schema' ); ?></label>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section anchor-event-conditional" data-when-type="multisession">
                <h3><?php echo esc_html__( 'Sessions', 'anchor-schema' ); ?></h3>
                <p class="description"><?php echo esc_html__( 'Add one row per session date/time in this series.', 'anchor-schema' ); ?></p>
                <table class="widefat anchor-event-sessions-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Date', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Start time', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'End time', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Label', 'anchor-schema' ); ?></th>
                            <th aria-hidden="true"></th>
                        </tr>
                    </thead>
                    <tbody class="anchor-event-sessions-rows">
                        <?php foreach ( $sessions as $i => $session ) : ?>
                            <?php echo $this->event_session_row_html( (int) $i, $session ); // already escaped ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="anchor-event-button-secondary anchor-event-session-add"><?php echo esc_html__( 'Add session', 'anchor-schema' ); ?></button>
                </p>
                <script type="text/html" id="anchor-event-session-template">
                    <?php echo $this->event_session_row_html( 0, null, true ); // already escaped ?>
                </script>
            </div>

            <div class="anchor-event-section anchor-event-conditional" data-when-type="offering recurring">
                <h3><?php echo esc_html__( 'Offering / Recurring Schedule', 'anchor-schema' ); ?></h3>
                <p class="description"><?php echo esc_html__( 'Offering dates / recurrence are configured in the next phase.', 'anchor-schema' ); ?></p>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Location', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field"><label for="anchor_event_venue"><?php echo esc_html__( 'Venue', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_venue" name="anchor_event_venue" value="<?php echo esc_attr( $meta['venue'] ); ?>" /></div>
                    <div class="anchor-event-field"><label for="anchor_event_address_street"><?php echo esc_html__( 'Street', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_address_street" name="anchor_event_address_street" value="<?php echo esc_attr( $meta['address_street'] ); ?>" /></div>
                    <div class="anchor-event-field"><label for="anchor_event_address_city"><?php echo esc_html__( 'City', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_address_city" name="anchor_event_address_city" value="<?php echo esc_attr( $meta['address_city'] ); ?>" /></div>
                    <div class="anchor-event-field"><label for="anchor_event_address_state"><?php echo esc_html__( 'State', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_address_state" name="anchor_event_address_state" value="<?php echo esc_attr( $meta['address_state'] ); ?>" /></div>
                    <div class="anchor-event-field"><label for="anchor_event_address_zip"><?php echo esc_html__( 'Postal code', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_address_zip" name="anchor_event_address_zip" value="<?php echo esc_attr( $meta['address_zip'] ); ?>" /></div>
                    <div class="anchor-event-field"><label><input type="checkbox" id="anchor_event_virtual" name="anchor_event_virtual" value="1" <?php checked( $meta['virtual'] ); ?> /> <?php echo esc_html__( 'Virtual event', 'anchor-schema' ); ?></label></div>
                    <div class="anchor-event-field" id="anchor-event-virtual-url"><label for="anchor_event_virtual_url"><?php echo esc_html__( 'Virtual URL', 'anchor-schema' ); ?></label><input type="url" id="anchor_event_virtual_url" name="anchor_event_virtual_url" value="<?php echo esc_attr( $meta['virtual_url'] ); ?>" /></div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Registration', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field"><label><input type="checkbox" id="anchor_event_registration_enabled" name="anchor_event_registration_enabled" value="1" <?php checked( $meta['registration_enabled'] ); ?> /> <?php echo esc_html__( 'Enable registration', 'anchor-schema' ); ?></label></div>
                    <div class="anchor-event-field anchor-event-registration-fields"><label for="anchor_event_capacity"><?php echo esc_html__( 'Capacity', 'anchor-schema' ); ?></label><input type="number" id="anchor_event_capacity" name="anchor_event_capacity" value="<?php echo esc_attr( $meta['capacity'] ); ?>" min="0" /></div>
                    <div class="anchor-event-field anchor-event-registration-fields"><label><input type="checkbox" id="anchor_event_waitlist" name="anchor_event_waitlist" value="1" <?php checked( $meta['waitlist'] ); ?> /> <?php echo esc_html__( 'Enable waitlist', 'anchor-schema' ); ?></label></div>
                    <div class="anchor-event-field anchor-event-registration-fields"><label for="anchor_event_registration_open"><?php echo esc_html__( 'Registration opens', 'anchor-schema' ); ?></label><input type="date" id="anchor_event_registration_open" name="anchor_event_registration_open" value="<?php echo esc_attr( $meta['registration_open'] ); ?>" /></div>
                    <div class="anchor-event-field anchor-event-registration-fields"><label for="anchor_event_registration_close"><?php echo esc_html__( 'Registration closes', 'anchor-schema' ); ?></label><input type="date" id="anchor_event_registration_close" name="anchor_event_registration_close" value="<?php echo esc_attr( $meta['registration_close'] ); ?>" /></div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label for="anchor_event_registration_type"><?php echo esc_html__( 'Registration type', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_registration_type" name="anchor_event_registration_type">
                            <option value="internal" <?php selected( $meta['registration_type'], 'internal' ); ?>>Internal</option>
                            <option value="external" <?php selected( $meta['registration_type'], 'external' ); ?>>External URL</option>
                        </select>
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields" id="anchor-event-registration-url"><label for="anchor_event_registration_url"><?php echo esc_html__( 'External URL', 'anchor-schema' ); ?></label><input type="url" id="anchor_event_registration_url" name="anchor_event_registration_url" value="<?php echo esc_attr( $meta['registration_url'] ); ?>" /></div>
                </div>
            </div>

            <div class="anchor-event-section anchor-event-conditional" data-when-mode="external">
                <h3><?php echo esc_html__( 'External Registration', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_external_url"><?php echo esc_html__( 'External URL', 'anchor-schema' ); ?></label>
                        <input type="url" id="anchor_event_external_url" name="anchor_event_external_url" value="<?php echo esc_attr( $meta['external_url'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_external_display_price"><?php echo esc_html__( 'Display price', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_external_display_price" name="anchor_event_external_display_price" value="<?php echo esc_attr( $meta['external_display_price'] ); ?>" />
                        <p class="description"><?php echo esc_html__( 'Display-only price label, e.g. $495. Not connected to WooCommerce.', 'anchor-schema' ); ?></p>
                    </div>
                    <div class="anchor-event-field" style="grid-column:1/-1;">
                        <label for="anchor_event_external_embed"><?php echo esc_html__( 'Embed code', 'anchor-schema' ); ?></label>
                        <textarea id="anchor_event_external_embed" name="anchor_event_external_embed" rows="5" class="large-text code"><?php echo esc_textarea( $meta['external_embed'] ); ?></textarea>
                        <p class="description"><?php echo esc_html__( 'Paste a third-party embed. Iframes allowed; scripts stripped by default.', 'anchor-schema' ); ?></p>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Featured image', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-thumbnail-field">
                    <input type="hidden" id="anchor_event_thumbnail_id" name="anchor_event_thumbnail_id" value="<?php echo esc_attr( $thumbnail_id ); ?>" />
                    <div class="anchor-event-thumbnail-preview">
                        <?php if ( $thumbnail_id ) : ?>
                            <img src="<?php echo esc_url( \wp_get_attachment_image_url( $thumbnail_id, 'medium' ) ); ?>" alt="" />
                        <?php endif; ?>
                    </div>
                    <p>
                        <button type="button" class="anchor-event-button-secondary anchor-event-thumbnail-select"><?php echo esc_html__( 'Select image', 'anchor-schema' ); ?></button>
                        <button type="button" class="anchor-event-button-secondary anchor-event-thumbnail-remove"<?php echo $thumbnail_id ? '' : ' hidden'; ?>><?php echo esc_html__( 'Remove', 'anchor-schema' ); ?></button>
                    </p>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Photo gallery', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-gallery-field" data-max="0">
                    <input type="hidden" id="anchor_event_gallery" name="anchor_event_gallery" value="<?php echo esc_attr( implode( ',', $gallery_ids ) ); ?>" />
                    <ul class="anchor-event-gallery-previews">
                        <?php foreach ( $gallery_ids as $attachment_id ) :
                            $thumb = \wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
                            if ( ! $thumb ) { continue; } ?>
                            <li data-id="<?php echo esc_attr( $attachment_id ); ?>">
                                <img src="<?php echo esc_url( $thumb ); ?>" alt="" />
                                <button type="button" class="anchor-event-gallery-remove" aria-label="Remove image">&times;</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p>
                        <button type="button" class="anchor-event-button-secondary anchor-event-gallery-add"><?php echo esc_html__( 'Add / manage images', 'anchor-schema' ); ?></button>
                        <button type="button" class="anchor-event-button-secondary anchor-event-gallery-clear"><?php echo esc_html__( 'Clear all', 'anchor-schema' ); ?></button>
                    </p>
                </div>
            </div>

            <div class="anchor-event-manager-submit">
                <button type="submit" class="anchor-event-button"><?php echo $is_edit ? esc_html__( 'Save changes', 'anchor-schema' ) : esc_html__( 'Create event', 'anchor-schema' ); ?></button>
                <a class="anchor-event-button-secondary" href="<?php echo esc_url( $base_url ); ?>"><?php echo esc_html__( 'Cancel', 'anchor-schema' ); ?></a>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    public function handle_event_manager_save() {
        $nonce = isset( $_POST['anchor_event_manager_nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['anchor_event_manager_nonce'] ) ) : '';
        if ( ! \wp_verify_nonce( $nonce, 'anchor_event_manager_save' ) ) {
            \wp_die( esc_html__( 'Invalid request.', 'anchor-schema' ) );
        }

        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : \home_url();
        $event_id = (int) ( $_POST['event_id'] ?? 0 );
        $is_edit = $event_id > 0;

        $capability_ok = $is_edit
            ? \current_user_can( 'edit_post', $event_id )
            : \current_user_can( 'edit_others_posts' );
        if ( ! $capability_ok ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'denied', $redirect ) );
            exit;
        }

        // M1: the edit branch must confirm the target is actually an event before
        // wp_update_post() forces post_type=CPT — otherwise an arbitrary post the
        // user can edit (their own draft) could be type-confused into an event
        // (mirror handle_event_manager_delete()).
        if ( $is_edit && \get_post_type( $event_id ) !== self::CPT ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'error', $redirect ) );
            exit;
        }

        // Resolved BEFORE the save below (mirrors save_meta()) so an invalid/missing
        // posted registration_mode falls back to whatever the event currently
        // resolves to, not a hardcoded default. A brand-new event (event_id === 0)
        // has no prior resolution to fall back to, so registration_mode( 0 ) — which
        // reads nothing and derives 'free' — is the correct starting fallback.
        $current_registration_mode = $this->registration_mode( $event_id );

        $title = sanitize_text_field( wp_unslash( $_POST['anchor_event_title'] ?? '' ) );
        $content = wp_kses_post( wp_unslash( $_POST['anchor_event_content'] ?? '' ) );
        $start_date = $this->sanitize_date( $_POST['anchor_event_start_date'] ?? '' );
        if ( ! $title || ! $start_date ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'missing', $redirect ) );
            exit;
        }

        $post_status = sanitize_key( $_POST['anchor_event_post_status'] ?? 'publish' );
        if ( ! in_array( $post_status, [ 'publish', 'draft', 'private' ], true ) ) {
            $post_status = 'publish';
        }
        // M1: wp_update_post()/wp_insert_post() do NOT enforce publish_posts for an
        // explicit post_status='publish'/'private'. Downgrade to 'pending' when the
        // user lacks the real publish capability (e.g. a Contributor) so the
        // front-end editor can't be used to bypass the publish gate.
        if ( in_array( $post_status, [ 'publish', 'private' ], true ) && ! \current_user_can( 'publish_posts' ) ) {
            $post_status = 'pending';
        }

        $postarr = [
            'post_type' => self::CPT,
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => $post_status,
        ];
        if ( $is_edit ) {
            $postarr['ID'] = $event_id;
            $saved_id = \wp_update_post( $postarr, true );
        } else {
            $saved_id = \wp_insert_post( $postarr, true );
        }

        if ( \is_wp_error( $saved_id ) || ! $saved_id ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'error', $redirect ) );
            exit;
        }

        $this->save_event_manager_fields( $saved_id, $start_date, $current_registration_mode );

        \wp_safe_redirect( \add_query_arg( 'event_manager_notice', $is_edit ? 'saved' : 'created', $redirect ) );
        exit;
    }

    /**
     * Persist the front-end manager-form's event fields to $saved_id's post
     * meta: Date & Time, Location, Registration, the event-type/registration-mode/
     * sessions/external fields (via sanitize_event_type_input(), Task 1.5),
     * gallery, featured image, and the auto-append registration shortcode.
     * Reads from $_POST.
     *
     * Extracted out of handle_event_manager_save() (Task 1.5) so the save
     * logic is directly unit-testable: that method ends in
     * wp_safe_redirect()+exit, which a PHPUnit process cannot safely exercise.
     * Purely a refactor of what handle_event_manager_save() already did
     * inline — behavior-preserving, no new logic.
     *
     * @param int    $saved_id                   Post ID already inserted/updated as self::CPT
     *                                            (handle_event_manager_save() calls this AFTER
     *                                            wp_insert_post()/wp_update_post()).
     * @param string $start_date                 Pre-sanitized start date (validated non-empty by the caller).
     * @param string $current_registration_mode  Pre-resolved registration_mode() fallback, resolved
     *                                            BEFORE this save writes any meta — see
     *                                            sanitize_event_type_input().
     * @return array The sanitized meta values written (mainly useful to callers/tests).
     *
     * Deliberately non-public (Task 1.5 review fix): this writes post meta
     * given only an int post ID with no capability/nonce re-check of its
     * own — those guards live in the real public entry point,
     * handle_event_manager_save(), which is the only caller. Making this
     * `protected` keeps it unit-testable via ReflectionMethod::setAccessible()
     * without exposing an unguarded direct meta-write on the public API.
     */
    protected function save_event_manager_fields( $saved_id, $start_date, $current_registration_mode ) {
        $input = [
            'start_date' => $start_date,
            'end_date' => $this->sanitize_date( $_POST['anchor_event_end_date'] ?? '' ),
            'start_time' => $this->sanitize_time( $_POST['anchor_event_start_time'] ?? '' ),
            'end_time' => $this->sanitize_time( $_POST['anchor_event_end_time'] ?? '' ),
            'timezone' => sanitize_text_field( $_POST['anchor_event_timezone'] ?? '' ),
            'all_day' => ! empty( $_POST['anchor_event_all_day'] ),
            'venue' => sanitize_text_field( $_POST['anchor_event_venue'] ?? '' ),
            'address_street' => sanitize_text_field( $_POST['anchor_event_address_street'] ?? '' ),
            'address_city' => sanitize_text_field( $_POST['anchor_event_address_city'] ?? '' ),
            'address_state' => sanitize_text_field( $_POST['anchor_event_address_state'] ?? '' ),
            'address_zip' => sanitize_text_field( $_POST['anchor_event_address_zip'] ?? '' ),
            'address_country' => sanitize_text_field( $_POST['anchor_event_address_country'] ?? '' ),
            'virtual' => ! empty( $_POST['anchor_event_virtual'] ),
            'virtual_url' => esc_url_raw( $_POST['anchor_event_virtual_url'] ?? '' ),
            'registration_enabled' => ! empty( $_POST['anchor_event_registration_enabled'] ),
            'capacity' => (int) ( $_POST['anchor_event_capacity'] ?? 0 ),
            'registration_open' => $this->sanitize_date( $_POST['anchor_event_registration_open'] ?? '' ),
            'registration_close' => $this->sanitize_date( $_POST['anchor_event_registration_close'] ?? '' ),
            'waitlist' => ! empty( $_POST['anchor_event_waitlist'] ),
            'registration_type' => sanitize_text_field( $_POST['anchor_event_registration_type'] ?? 'internal' ),
            'registration_url' => esc_url_raw( $_POST['anchor_event_registration_url'] ?? '' ),
            'hide_from_archive' => false,
            'featured' => false,
            'priority' => 0,
            'gallery' => $this->sanitize_gallery_ids( $_POST['anchor_event_gallery'] ?? '' ),
        ];

        // Event-type / registration-mode authoring UI (Task 1.3 metabox parity,
        // Task 1.5). Same six keys, same sanitize_event_type_input() helper as
        // save_meta() — see that method's docblock.
        $input = array_merge( $input, $this->sanitize_event_type_input( $_POST, $current_registration_mode ) );

        $input['status_mode'] = 'auto';
        $input['status'] = $this->calculate_status( $input );
        $timestamps = $this->calculate_timestamps( $input );
        $input['start_ts'] = $timestamps['start'];
        $input['end_ts'] = $timestamps['end'];

        foreach ( $input as $key => $value ) {
            \update_post_meta( $saved_id, $this->meta_key( $key ), $value );
        }

        $thumbnail_id = (int) ( $_POST['anchor_event_thumbnail_id'] ?? 0 );
        if ( $thumbnail_id && \get_post_type( $thumbnail_id ) === 'attachment' ) {
            \set_post_thumbnail( $saved_id, $thumbnail_id );
        } else {
            \delete_post_thumbnail( $saved_id );
        }

        $this->maybe_append_registration_shortcode( $saved_id, $input );
        $this->clear_caches();

        return $input;
    }

    public function handle_event_manager_delete() {
        $event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
        $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( urldecode( wp_unslash( $_GET['redirect_to'] ) ) ) : \home_url();

        \check_admin_referer( 'anchor_event_manager_delete_' . $event_id );

        if ( ! $event_id || \get_post_type( $event_id ) !== self::CPT ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'error', $redirect ) );
            exit;
        }
        if ( ! \current_user_can( 'delete_post', $event_id ) ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'denied', $redirect ) );
            exit;
        }

        \wp_trash_post( $event_id );
        $this->clear_caches();

        \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'deleted', $redirect ) );
        exit;
    }

    public function render_events_list( $atts, $context ) {
        $atts = \wp_parse_args( $atts, [
            'limit' => 10,
            'orderby' => 'date',
            'order' => 'ASC',
            'show_past' => 'no',
            'featured' => 'no',
        ] );

        $meta_query = [];
        if ( ! empty( $atts['status'] ) ) {
            $meta_query[] = [
                'key' => $this->meta_key( 'status' ),
                'value' => sanitize_text_field( $atts['status'] ),
                'compare' => '=',
            ];
        }

        if ( empty( $atts['show_past'] ) || $atts['show_past'] === 'no' ) {
            $meta_query[] = $this->build_visibility_clause();
        }
        $meta_query[] = $this->build_hide_clause();

        if ( ! empty( $atts['featured'] ) && $atts['featured'] === 'yes' ) {
            $meta_query[] = [
                'key' => $this->meta_key( 'featured' ),
                'value' => '1',
                'compare' => '=',
            ];
        }

        if ( ! empty( $atts['start_date'] ) || ! empty( $atts['end_date'] ) ) {
            $range = $this->build_range_clause( $atts['start_date'] ?? '', $atts['end_date'] ?? '' );
            if ( $range ) {
                $meta_query[] = $range;
            }
        }

        $tax_query = [];
        if ( ! empty( $atts['category'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'event_category',
                'field' => 'slug',
                'terms' => array_map( 'sanitize_title', explode( ',', $atts['category'] ) ),
            ];
        }
        if ( ! empty( $atts['tag'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'event_tag',
                'field' => 'slug',
                'terms' => array_map( 'sanitize_title', explode( ',', $atts['tag'] ) ),
            ];
        }
        if ( ! empty( $atts['type'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'event_type',
                'field' => 'slug',
                'terms' => array_map( 'sanitize_title', explode( ',', $atts['type'] ) ),
            ];
        }

        $orderby = strtolower( $atts['orderby'] );
        $order = strtoupper( $atts['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        $limit = (int) $atts['limit'];
        if ( $limit === 0 ) {
            $limit = -1;
        }

        $query_args = [
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'meta_value_num',
            'meta_key' => $this->meta_key( 'start_ts' ),
            'order' => $order,
            'meta_query' => $meta_query,
            'tax_query' => $tax_query,
        ];

        if ( $orderby === 'title' ) {
            $query_args['orderby'] = 'title';
            unset( $query_args['meta_key'] );
        } elseif ( $orderby === 'priority' ) {
            $query_args['meta_key'] = $this->meta_key( 'priority' );
            $query_args['orderby'] = 'meta_value_num';
        }

        $query_args = \apply_filters( 'anchor_events_query_args', $query_args, $atts );
        $ids = $this->get_cached_ids( $query_args );

        if ( empty( $ids ) ) {
            return '<div class="anchor-events-empty">' . esc_html__( 'No events found.', 'anchor-schema' ) . '</div>';
        }

        $this->enqueue_frontend_assets();

        $output = '<div class="anchor-events-list anchor-events-context-' . esc_attr( $context ) . '">';
        foreach ( $ids as $event_id ) {
            $output .= $this->render_event_card( $event_id, $context );
        }
        $output .= '</div>';

        return $output;
    }

    public function render_event_card( $post_id, $context ) {
        $meta = $this->get_meta( $post_id );
        $status = $this->get_event_status( $post_id, $meta );
        $classes = [
            'anchor-event-card',
            'anchor-event-status-' . $status,
        ];
        $classes = \apply_filters( 'anchor_events_event_classes', $classes, $post_id, $context );

        \do_action( 'anchor_events_before_render', $post_id, $context );

        $output = '<article class="' . esc_attr( implode( ' ', $classes ) ) . '" data-status="' . esc_attr( $status ) . '">';
        $output .= '<div class="anchor-event-card-header">';
        if ( \has_post_thumbnail( $post_id ) ) {
            $output .= '<div class="anchor-event-thumb">' . \get_the_post_thumbnail( $post_id, 'medium' ) . '</div>';
        }
        $output .= '<h3 class="anchor-event-title"><a href="' . esc_url( \get_permalink( $post_id ) ) . '">' . esc_html( \get_the_title( $post_id ) ) . '</a></h3>';
        $output .= '</div>';
        $output .= '<div class="anchor-event-meta">' . esc_html( $this->format_date_time( $meta ) ) . '</div>';
        if ( $meta['venue'] ) {
            $output .= '<div class="anchor-event-meta">' . esc_html( $meta['venue'] ) . '</div>';
        }
        $excerpt = \get_the_excerpt( $post_id );
        if ( $excerpt ) {
            $output .= '<div class="anchor-event-excerpt">' . esc_html( $excerpt ) . '</div>';
        }
        $output .= '<div class="anchor-event-actions"><a class="anchor-event-button" href="' . esc_url( \get_permalink( $post_id ) ) . '">' . esc_html__( 'View Event', 'anchor-schema' ) . '</a></div>';
        $output .= '</article>';

        \do_action( 'anchor_events_after_render', $post_id, $context );

        return $output;
    }

    public function render_registration_notice() {
        if ( empty( $_GET['event_registration'] ) ) {
            return '';
        }
        $key = sanitize_text_field( $_GET['event_registration'] );
        $messages = [
            'registration_success' => __( 'Registration received. Check your email for confirmation.', 'anchor-schema' ),
            'registration_closed' => __( 'Registration is closed for this event.', 'anchor-schema' ),
            'registration_invalid' => __( 'Please complete all required registration fields.', 'anchor-schema' ),
            'registration_error' => __( 'Registration could not be processed. Please try again.', 'anchor-schema' ),
        ];
        if ( ! isset( $messages[ $key ] ) ) {
            return '';
        }
        return '<div class="anchor-event-notice">' . esc_html( $messages[ $key ] ) . '</div>';
    }

    /**
     * Whether the current viewer may see the virtual "Join here" link (H1/A1).
     *
     * The link is shown to any entitled viewer:
     * - Purely informational public events (registration disabled AND NOT linked):
     *   nothing is gated behind the link, so it stays visible to everyone.
     * - Roster-capability staff: always.
     * - A logged-in viewer holding a confirmed/active seat for this event — this
     *   covers BOTH free registrants and confirmed paid (WooCommerce-linked) buyers.
     *
     * Anonymous / non-seat-holders never see it on a registration or paid event
     * (the paywall holds); guest/logged-out registrants instead receive the join
     * link in their confirmation email.
     *
     * @param int   $post_id
     * @param array $meta
     * @return bool
     */
    private function can_view_virtual_link( $post_id, $meta ) {
        $post_id   = (int) $post_id;
        $is_linked = ( $this->woocommerce && $this->woocommerce->event_is_linked( $post_id ) );

        if ( empty( $meta['registration_enabled'] ) && ! $is_linked ) {
            // Informational public event — nothing gated behind the link.
            return true;
        }

        if ( Roster::current_user_can_manage() ) {
            return true;
        }
        if ( ! \is_user_logged_in() ) {
            return false;
        }
        $user = \wp_get_current_user();
        return $this->registrations->user_has_active_seat( $post_id, (int) $user->ID, (string) $user->user_email );
    }

    public function render_single_content( $post_id ) {
        $meta = $this->get_meta( $post_id );
        $status = $this->get_event_status( $post_id, $meta );

        $output = '<section class="anchor-event-detail">';
        $output .= '<div class="anchor-event-detail-meta">';
        $output .= '<div><strong>' . esc_html__( 'Date', 'anchor-schema' ) . ':</strong> ' . esc_html( $this->format_date_time( $meta, true ) ) . '</div>';
        if ( $meta['venue'] ) {
            $output .= '<div><strong>' . esc_html__( 'Venue', 'anchor-schema' ) . ':</strong> ' . esc_html( $meta['venue'] ) . '</div>';
        }
        if ( $meta['virtual'] && $meta['virtual_url'] ) {
            // H1: the join link is the paid/registered deliverable — only emit it to
            // entitled viewers. Non-entitled viewers see a notice, never the URL.
            if ( $this->can_view_virtual_link( $post_id, $meta ) ) {
                $output .= '<div><strong>' . esc_html__( 'Virtual Event', 'anchor-schema' ) . ':</strong> <a href="' . esc_url( $meta['virtual_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Join here', 'anchor-schema' ) . '</a></div>';
            } else {
                $output .= '<div><strong>' . esc_html__( 'Virtual Event', 'anchor-schema' ) . ':</strong> ' . esc_html__( 'The join link is available to registered attendees.', 'anchor-schema' ) . '</div>';
            }
        }
        $output .= '<div><strong>' . esc_html__( 'Status', 'anchor-schema' ) . ':</strong> ' . esc_html( ucfirst( $status ) ) . '</div>';
        $output .= '</div>';

        $address = $this->format_address( $meta );
        if ( $address ) {
            $output .= '<div class="anchor-event-address"><strong>' . esc_html__( 'Address', 'anchor-schema' ) . ':</strong> ' . esc_html( $address ) . '</div>';
        }

        $output .= '</section>';
        $output .= $this->render_sessions_list( $post_id );
        return $output;
    }

    /**
     * Multi-session series (Task 1.6): a titled list of the event's sessions
     * (date + start/end time + label), rendered only when the event is
     * type=multisession AND has at least one normalized session row (rows
     * with an empty date are already dropped by get_sessions()). Every field
     * is plain text — escaped on output, no trusted-HTML fields here.
     *
     * occurrence = event post: this is a pure read of `sessions` meta, it does
     * not touch seats/capacity/tiers/product/roster/reconcile.
     *
     * @param int $post_id
     * @return string
     */
    private function render_sessions_list( $post_id ) {
        if ( $this->event_type( $post_id ) !== 'multisession' ) {
            return '';
        }
        $sessions = $this->get_sessions( $post_id );
        if ( empty( $sessions ) ) {
            return '';
        }

        $output = '<section class="anchor-event-sessions">';
        $output .= '<h2 class="anchor-event-sessions-title">' . esc_html__( 'Sessions', 'anchor-schema' ) . '</h2>';
        $output .= '<table class="anchor-event-sessions-list">';
        $output .= '<thead><tr>';
        $output .= '<th>' . esc_html__( 'Date', 'anchor-schema' ) . '</th>';
        $output .= '<th>' . esc_html__( 'Time', 'anchor-schema' ) . '</th>';
        $output .= '<th>' . esc_html__( 'Session', 'anchor-schema' ) . '</th>';
        $output .= '</tr></thead>';
        $output .= '<tbody>';
        foreach ( $sessions as $session ) {
            $time_range = trim( $session['start_time'] . ( $session['end_time'] ? ' – ' . $session['end_time'] : '' ) );
            $output .= '<tr>';
            $output .= '<td>' . esc_html( $session['date'] ) . '</td>';
            $output .= '<td>' . esc_html( $time_range ) . '</td>';
            $output .= '<td>' . esc_html( $session['label'] ) . '</td>';
            $output .= '</tr>';
        }
        $output .= '</tbody>';
        $output .= '</table>';
        $output .= '</section>';

        return $output;
    }

    /**
     * External registration mode (Task 1.6): the event is registered/
     * ticketed off-site. Renders EITHER the embedded form (when
     * `external_embed` is set) OR a link-out button (when only `external_url`
     * is set), plus the optional display-only price label. This is a pure
     * display block — occurrence = event post, it does not invoke any cart/
     * registration/seat code.
     *
     * SECURITY: `external_embed` is stored ALREADY-SANITIZED via a wp_kses()
     * allowlist at save time (sanitize_external_embed()) and is echoed here
     * as trusted HTML — it must NOT be esc_html()'d/esc_attr()'d, or the
     * iframe/allowed markup would render as literal escaped text instead of
     * HTML. EVERY other field (external_url, external_display_price) is
     * escaped on output as usual.
     *
     * @param int   $post_id
     * @param array $meta get_meta( $post_id ) result.
     * @return string
     */
    private function render_external_registration( $post_id, $meta ) {
        $output = '<div class="anchor-event-registration anchor-event-registration-external">';

        if ( $meta['external_embed'] !== '' ) {
            // Already sanitized at save time — echo as trusted HTML.
            $output .= '<div class="anchor-event-external-embed">' . $meta['external_embed'] . '</div>';
        } elseif ( $meta['external_url'] !== '' ) {
            $output .= '<a class="anchor-event-button anchor-event-register" href="' . esc_url( $meta['external_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Register', 'anchor-schema' ) . '</a>';
        }

        if ( $meta['external_display_price'] !== '' ) {
            $output .= '<p class="anchor-event-external-price">' . esc_html( $meta['external_display_price'] ) . '</p>';
        }

        $output .= '</div>';
        return $output;
    }

    public function render_registration_form( $post_id ) {
        $settings = $this->get_settings();
        $meta = $this->get_meta( $post_id );

        // Render seam (spec §3): the WooCommerce class swaps the free form for a
        // buy button on linked events by returning non-empty here. Inert until the
        // Phase 2 filter callback is registered (no consumers otherwise).
        $override = \apply_filters( 'anchor_events_registration_form', '', $post_id, $meta );
        if ( $override !== '' ) {
            return $override;
        }

        if ( ! $meta['registration_enabled'] ) {
            return '';
        }

        // External registration mode (Task 1.6): the event's registration/
        // checkout happens off-site. Still gated by `registration_enabled`
        // above, matching the legacy external-URL path, can_view_virtual_link(),
        // and maybe_append_registration_shortcode() — when registration is
        // disabled, no registration UI renders at all, external or otherwise.
        if ( $this->registration_mode( $post_id ) === 'external' ) {
            return $this->render_external_registration( $post_id, $meta );
        }

        if ( $meta['registration_type'] === 'external' ) {
            if ( $meta['registration_url'] ) {
                return '<div class="anchor-event-registration"><a class="anchor-event-button anchor-event-register" href="' . esc_url( $meta['registration_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Register', 'anchor-schema' ) . '</a></div>';
            }
            return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'Registration link unavailable.', 'anchor-schema' ) . '</div>';
        }

        if ( empty( $settings['registration_internal'] ) ) {
            return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'Registration is currently disabled.', 'anchor-schema' ) . '</div>';
        }

        $status = $this->get_registration_status( $post_id, $meta );
        if ( $status === 'closed' ) {
            return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'Registration is closed.', 'anchor-schema' ) . '</div>';
        }
        if ( $status === 'full' ) {
            return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'This event is full.', 'anchor-schema' ) . '</div>';
        }
        $notice = '';
        if ( $status === 'waitlist' ) {
            $notice = '<div class="anchor-event-notice">' . esc_html__( 'This event is full. You will be added to the waitlist.', 'anchor-schema' ) . '</div>';
        }

        $fields = $this->get_registration_fields();
        $redirect = \get_permalink( $post_id );

        $output = $notice;
        $output .= '<form class="anchor-event-registration" method="post" action="' . esc_url( \admin_url( 'admin-post.php' ) ) . '">';
        $output .= '<input type="hidden" name="action" value="anchor_event_register" />';
        $output .= '<input type="hidden" name="event_id" value="' . esc_attr( $post_id ) . '" />';
        $output .= '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect ) . '" />';
        $output .= \wp_nonce_field( self::REG_NONCE, self::REG_NONCE, true, false );

        // Ticket-tier selector (spec §9). The free form sells FREE tiers only;
        // paid tiers go through WooCommerce checkout. Render a selector only
        // when the event has more than one active free tier — a single
        // (implicit primary) tier needs no choice.
        $free_tiers = $this->get_active_free_tiers( $post_id );
        if ( count( $free_tiers ) > 1 ) {
            $output .= '<div class="anchor-event-field">';
            $output .= '<label for="anchor_event_ticket_type">' . esc_html__( 'Ticket type', 'anchor-schema' ) . '</label>';
            $output .= '<select id="anchor_event_ticket_type" name="anchor_event_ticket_type">';
            foreach ( $free_tiers as $tier ) {
                $output .= '<option value="' . esc_attr( $tier['id'] ) . '">' . esc_html( $tier['label'] ) . '</option>';
            }
            $output .= '</select>';
            $output .= '</div>';
        }

        $output .= '<div class="anchor-event-field">';
        $output .= '<label for="anchor_event_name">' . esc_html__( 'Name', 'anchor-schema' ) . '</label>';
        $output .= '<input type="text" id="anchor_event_name" name="anchor_event_name" required />';
        $output .= '</div>';
        $output .= '<div class="anchor-event-field">';
        $output .= '<label for="anchor_event_email">' . esc_html__( 'Email', 'anchor-schema' ) . '</label>';
        $output .= '<input type="email" id="anchor_event_email" name="anchor_event_email" required />';
        $output .= '</div>';
        $output .= '<div class="anchor-event-field">';
        $output .= '<label for="anchor_event_phone">' . esc_html__( 'Phone', 'anchor-schema' ) . '</label>';
        $output .= '<input type="tel" id="anchor_event_phone" name="anchor_event_phone" />';
        $output .= '</div>';

        $max_guests = (int) ( $settings['max_guests'] ?? 0 );
        if ( $max_guests > 0 ) {
            $output .= '<div class="anchor-event-field">';
            $output .= '<label for="anchor_event_guests">' . esc_html__( 'Bringing guests?', 'anchor-schema' ) . '</label>';
            $output .= '<select id="anchor_event_guests" name="anchor_event_guests">';
            for ( $i = 0; $i <= $max_guests; $i++ ) {
                $label = $i === 0
                    ? esc_html__( 'Just me', 'anchor-schema' )
                    : sprintf( \_n( '+%d guest', '+%d guests', $i, 'anchor-schema' ), $i );
                $output .= '<option value="' . esc_attr( $i ) . '">' . esc_html( $label ) . '</option>';
            }
            $output .= '</select>';
            $output .= '</div>';
        }

        foreach ( $fields as $field ) {
            $field_id = sanitize_key( $field['id'] );
            $label = $field['label'] ?? $field_id;
            $type = $field['type'] ?? 'text';
            $required = ! empty( $field['required'] );
            $output .= '<div class="anchor-event-field">';
            $output .= '<label for="anchor_event_field_' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label>';
            $output .= '<input type="' . esc_attr( $type ) . '" id="anchor_event_field_' . esc_attr( $field_id ) . '" name="anchor_event_field[' . esc_attr( $field_id ) . ']"' . ( $required ? ' required' : '' ) . ' />';
            $output .= '</div>';
        }

        $button_label = isset( $settings['register_button_label'] ) && $settings['register_button_label'] !== ''
            ? $settings['register_button_label']
            : __( 'Register', 'anchor-schema' );
        $output .= '<button type="submit" class="anchor-event-button anchor-event-register">' . esc_html( $button_label ) . '</button>';
        $output .= '</form>';

        return $output;
    }

    public function handle_registration() {
        if ( ! isset( $_POST[ self::REG_NONCE ] ) || ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST[ self::REG_NONCE ] ) ), self::REG_NONCE ) ) {
            \wp_die( esc_html__( 'Invalid registration request.', 'anchor-schema' ) );
        }

        $event_id = (int) ( $_POST['event_id'] ?? 0 );
        $redirect = esc_url_raw( $_POST['redirect_to'] ?? '' );
        if ( ! $event_id ) {
            \wp_safe_redirect( $redirect ?: \home_url() );
            exit;
        }

        $meta = $this->get_meta( $event_id );
        $settings = $this->get_settings();

        if ( ! $meta['registration_enabled'] || empty( $settings['registration_internal'] ) ) {
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_closed' ) );
            exit;
        }
        if ( $meta['registration_type'] === 'external' ) {
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_closed' ) );
            exit;
        }

        $name = sanitize_text_field( wp_unslash( $_POST['anchor_event_name'] ?? '' ) );
        $email = sanitize_email( wp_unslash( $_POST['anchor_event_email'] ?? '' ) );
        $phone = sanitize_text_field( wp_unslash( $_POST['anchor_event_phone'] ?? '' ) );
        if ( ! $name || ! $email ) {
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_invalid' ) );
            exit;
        }

        $extra_fields = [];
        if ( ! empty( $_POST['anchor_event_field'] ) && is_array( $_POST['anchor_event_field'] ) ) {
            foreach ( wp_unslash( $_POST['anchor_event_field'] ) as $key => $value ) {
                $extra_fields[ sanitize_key( $key ) ] = sanitize_text_field( $value );
            }
        }

        $max_guests = (int) ( $settings['max_guests'] ?? 0 );
        $guests = isset( $_POST['anchor_event_guests'] ) ? (int) $_POST['anchor_event_guests'] : 0;
        $guests = max( 0, min( $max_guests, $guests ) );
        $party_size = 1 + $guests;

        // Resolve + validate the chosen ticket tier (spec §9). The free form may
        // only sell active FREE tiers; anything else (missing/unknown/paid)
        // falls back to the event's primary tier.
        $posted_tier = isset( $_POST['anchor_event_ticket_type'] )
            ? sanitize_key( wp_unslash( $_POST['anchor_event_ticket_type'] ) )
            : '';
        // Default to the (single) active FREE tier, NOT primary_id() — primary may be
        // a paid tier ordered first, which would misfile a free signup + skew that
        // paid tier's quota/roster (PR review). Fall back to primary only if there
        // are no free tiers at all.
        $free_tiers = $this->get_active_free_tiers( $event_id );
        $tier_id    = ! empty( $free_tiers ) ? $free_tiers[0]['id'] : $this->ticket_types->primary_id( $event_id );
        if ( $posted_tier !== '' ) {
            $tier = $this->ticket_types->find( $event_id, $posted_tier );
            if ( $tier && ! empty( $tier['active'] ) && (float) ( $tier['price'] ?? 0 ) <= 0 ) {
                $tier_id = $tier['id'];
            }
        }
        // The resolved tier drives per-tier quota enforcement in both the pre-check
        // and the locked claim below.
        $tier = $this->ticket_types->find( $event_id, $tier_id );

        // Pre-check for user-facing messaging (closed window / full + no waitlist),
        // honoring the tier's own quota.
        $decision = $this->get_registration_status( $event_id, $meta, $party_size, $tier );
        if ( $decision === 'closed' || $decision === 'full' ) {
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_closed' ) );
            exit;
        }

        // Race-safe creation under the per-event lock (bug #3). claim_seats recounts
        // capacity inside the lock, so concurrent submits can never oversell; the
        // tier arg enforces the free tier's per-tier quota too.
        $result = $this->registrations->claim_seats( $event_id, $meta, 1, [
            'source'         => 'internal',
            'name'           => $name,
            'email'          => $email,
            'phone'          => $phone,
            'guests'         => $guests,
            'reg_fields'     => $extra_fields,
            'ticket_type_id' => $tier_id,
            'note'           => 'internal registration',
            'actor'          => 'internal',
        ], $tier );

        $created    = ! empty( $result['created'] );
        $waitlisted = ! empty( $result['waitlisted'] );
        if ( ! $created && ! $waitlisted ) {
            // Filled up between the pre-check and acquiring the lock; waitlist off.
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_closed' ) );
            exit;
        }

        // L2: a seat was created while the capacity lock was unavailable (degraded
        // mode) — record an admin-visible signal mirroring the paid path so the
        // free path can't silently oversell.
        if ( ! empty( $result['lock_unavailable'] ) ) {
            Events_Log::error( 'capacity_lock_unavailable', [ 'event' => $event_id, 'source' => 'internal' ] );
        }

        $reg_status = ( $waitlisted && ! $created ) ? 'waitlist' : 'confirmed';
        $this->send_registration_emails( $event_id, $name, $email, $reg_status, $guests );

        \wp_safe_redirect( $this->with_message( $redirect, 'registration_success' ) );
        exit;
    }

    /**
     * "Roster" row action on the Events list table (spec §10.1).
     *
     * @param array    $actions
     * @param \WP_Post $post
     * @return array
     */
    public function event_row_actions( $actions, $post ) {
        if ( $post instanceof \WP_Post && $post->post_type === self::CPT
            && $this->roster && Roster::current_user_can_manage() ) {
            $url = $this->roster->roster_url( $post->ID );
            $actions['anchor_roster'] = '<a href="' . \esc_url( $url ) . '">'
                . \esc_html__( 'Roster', 'anchor-schema' ) . '</a>';
        }
        return $actions;
    }

    public function register_tab( $tabs ) {
        $tabs['events'] = [
            'label'    => \__( 'Events', 'anchor-schema' ),
            'callback' => [ $this, 'render_tab_content' ],
        ];
        return $tabs;
    }

    public function register_settings() {
        \register_setting( 'anchor_events_group', self::OPTION_KEY, [ $this, 'sanitize_settings' ] );

        \add_settings_section( 'anchor_events_main', __( 'Event Defaults', 'anchor-schema' ), function() {
            echo '<p>' . esc_html__( 'Configure default behavior for events and archives.', 'anchor-schema' ) . '</p>';
        }, 'anchor_events_settings' );

        \add_settings_field( 'timezone_mode', __( 'Timezone behavior', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            $value = $opts['timezone_mode'];
            ?>
            <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[timezone_mode]">
                <option value="site" <?php selected( $value, 'site' ); ?>><?php echo esc_html__( 'Use site timezone by default', 'anchor-schema' ); ?></option>
                <option value="event" <?php selected( $value, 'event' ); ?>><?php echo esc_html__( 'Respect event timezone field', 'anchor-schema' ); ?></option>
            </select>
            <?php
        }, 'anchor_events_settings', 'anchor_events_main' );

        \add_settings_field( 'archive_hide_past', __( 'Archive past events', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[archive_hide_past]" value="1" <?php checked( $opts['archive_hide_past'] ); ?> />
                <?php echo esc_html__( 'Hide past events from archives by default', 'anchor-schema' ); ?>
            </label>
            <?php
        }, 'anchor_events_settings', 'anchor_events_main' );

        \add_settings_field( 'template_source', __( 'Template source', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[template_source]">
                <option value="theme" <?php selected( $opts['template_source'], 'theme' ); ?>><?php echo esc_html__( 'Use theme override when available', 'anchor-schema' ); ?></option>
                <option value="plugin" <?php selected( $opts['template_source'], 'plugin' ); ?>><?php echo esc_html__( 'Always use plugin templates', 'anchor-schema' ); ?></option>
            </select>
            <?php
        }, 'anchor_events_settings', 'anchor_events_main' );

        \add_settings_section( 'anchor_events_email_sender', __( 'Email Sender', 'anchor-schema' ), function() {
            echo '<p>' . esc_html__( 'From / Reply-To / BCC identity applied to all event emails. Leave blank to use WordPress defaults.', 'anchor-schema' ) . '</p>';
            echo '<p class="description">' . esc_html__( 'This only sets the email headers — actual delivery still relies on your site\'s mail service (e.g. Mailgun, WP Mail SMTP). The From address should be on a domain that service is authorized to send for (SPF/DKIM), or mail may be marked as spam. Some SMTP/Mailgun plugins force their own From address and will override this; Reply-To is usually respected.', 'anchor-schema' ) . '</p>';
        }, 'anchor_events_settings' );

        $email_text_field = function( $key, $type, $placeholder ) {
            $opts = $this->get_settings();
            printf(
                '<input type="%1$s" name="%2$s[%3$s]" value="%4$s" class="regular-text" placeholder="%5$s" />',
                esc_attr( $type ),
                esc_attr( self::OPTION_KEY ),
                esc_attr( $key ),
                esc_attr( $opts[ $key ] ?? '' ),
                esc_attr( $placeholder )
            );
        };
        \add_settings_field( 'email_from_name', __( 'From name', 'anchor-schema' ), function() use ( $email_text_field ) {
            $email_text_field( 'email_from_name', 'text', __( 'e.g. Acme Events', 'anchor-schema' ) );
        }, 'anchor_events_settings', 'anchor_events_email_sender' );
        \add_settings_field( 'email_from_address', __( 'From email', 'anchor-schema' ), function() use ( $email_text_field ) {
            $email_text_field( 'email_from_address', 'email', 'events@yoursite.com' );
        }, 'anchor_events_settings', 'anchor_events_email_sender' );
        \add_settings_field( 'email_reply_to_name', __( 'Reply-To name', 'anchor-schema' ), function() use ( $email_text_field ) {
            $email_text_field( 'email_reply_to_name', 'text', '' );
        }, 'anchor_events_settings', 'anchor_events_email_sender' );
        \add_settings_field( 'email_reply_to_address', __( 'Reply-To email', 'anchor-schema' ), function() use ( $email_text_field ) {
            $email_text_field( 'email_reply_to_address', 'email', '' );
        }, 'anchor_events_settings', 'anchor_events_email_sender' );
        \add_settings_field( 'email_bcc', __( 'BCC email (optional)', 'anchor-schema' ), function() use ( $email_text_field ) {
            $email_text_field( 'email_bcc', 'email', '' );
        }, 'anchor_events_settings', 'anchor_events_email_sender' );

        \add_settings_section( 'anchor_events_registration', __( 'Registration Settings', 'anchor-schema' ), function() {
            echo '<p>' . esc_html__( 'Control internal registration and email notifications.', 'anchor-schema' ) . '</p>';
        }, 'anchor_events_settings' );

        \add_settings_field( 'registration_internal', __( 'Internal registration', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[registration_internal]" value="1" <?php checked( $opts['registration_internal'] ); ?> />
                <?php echo esc_html__( 'Enable internal registration forms', 'anchor-schema' ); ?>
            </label>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        \add_settings_field( 'admin_email', __( 'Admin notification email', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="email" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[admin_email]" value="<?php echo esc_attr( $opts['admin_email'] ); ?>" class="regular-text" />
            <p class="description"><?php echo esc_html__( 'Leave blank to use the site admin email.', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        \add_settings_field( 'notify_admin', __( 'Admin notifications', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notify_admin]" value="1" <?php checked( $opts['notify_admin'] ); ?> />
                <?php echo esc_html__( 'Send admin email when a registration is submitted', 'anchor-schema' ); ?>
            </label>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        \add_settings_field( 'notify_user', __( 'User confirmations', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notify_user]" value="1" <?php checked( $opts['notify_user'] ); ?> />
                <?php echo esc_html__( 'Send confirmation email to registrants', 'anchor-schema' ); ?>
            </label>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        \add_settings_field( 'confirmation_message', __( 'Confirmation message', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[confirmation_message]" rows="3" class="large-text"><?php echo esc_textarea( $opts['confirmation_message'] ); ?></textarea>
            <p class="description"><?php echo esc_html__( 'Shown below the event title in the confirmation email. Plain text; line breaks become paragraphs.', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        \add_settings_field( 'max_guests', __( 'Max additional guests', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="number" min="0" max="50" step="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_guests]" value="<?php echo esc_attr( $opts['max_guests'] ); ?>" class="small-text" />
            <p class="description"><?php echo esc_html__( 'Let registrants bring guests (plus-ones). Set to 0 to disable. Total attendees (registrant + guests) count toward event capacity.', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        \add_settings_field( 'register_button_label', __( 'Register button label', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[register_button_label]" value="<?php echo esc_attr( $opts['register_button_label'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'Register', 'anchor-schema' ); ?>" />
            <p class="description"><?php echo esc_html__( 'Text shown on the registration submit button. Leave blank for the default "Register".', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        \add_settings_field( 'register_button_color', __( 'Register button color', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            $value = $opts['register_button_color'] ?: '#0f766e';
            ?>
            <input type="color" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[register_button_color]" value="<?php echo esc_attr( $value ); ?>" />
            <p class="description"><?php echo esc_html__( 'Background color used for every Register button on the site.', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        // Phase 6 — WooCommerce registration emails. Rendered only when WC is active.
        if ( \class_exists( 'WooCommerce' ) ) {
            \add_settings_section( 'anchor_events_wc_emails', __( 'WooCommerce Registration Emails', 'anchor-schema' ), function() {
                echo '<p>' . esc_html__( 'Emails for paid event registrations created through WooCommerce orders. Subject tokens: {event_title}, {site_name}, {order_number}, {buyer_name}, {remaining_seats}, {seat_count}.', 'anchor-schema' ) . '</p>';
            }, 'anchor_events_settings' );

            \add_settings_field( 'wc_notify_customer', __( 'Customer confirmation', 'anchor-schema' ), function() {
                $opts = $this->get_settings();
                ?>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[wc_notify_customer]" value="1" <?php checked( $opts['wc_notify_customer'] ); ?> />
                    <?php echo esc_html__( 'Send one confirmation email per order to the buyer when seats are confirmed', 'anchor-schema' ); ?>
                </label>
                <?php
            }, 'anchor_events_settings', 'anchor_events_wc_emails' );

            \add_settings_field( 'wc_customer_subject', __( 'Customer email subject', 'anchor-schema' ), function() {
                $opts = $this->get_settings();
                ?>
                <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[wc_customer_subject]" value="<?php echo esc_attr( $opts['wc_customer_subject'] ); ?>" class="regular-text" />
                <?php
            }, 'anchor_events_settings', 'anchor_events_wc_emails' );

            \add_settings_field( 'wc_customer_intro', __( 'Customer email intro', 'anchor-schema' ), function() {
                $opts = $this->get_settings();
                ?>
                <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[wc_customer_intro]" rows="3" class="large-text"><?php echo esc_textarea( $opts['wc_customer_intro'] ); ?></textarea>
                <p class="description"><?php echo esc_html__( 'Shown above the seat list in the buyer confirmation. Plain text; line breaks become paragraphs.', 'anchor-schema' ); ?></p>
                <?php
            }, 'anchor_events_settings', 'anchor_events_wc_emails' );

            \add_settings_field( 'wc_notify_organizer', __( 'Organizer notification', 'anchor-schema' ), function() {
                $opts = $this->get_settings();
                ?>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[wc_notify_organizer]" value="1" <?php checked( $opts['wc_notify_organizer'] ); ?> />
                    <?php echo esc_html__( 'Notify the organizer when seats are confirmed or released', 'anchor-schema' ); ?>
                </label>
                <?php
            }, 'anchor_events_settings', 'anchor_events_wc_emails' );

            \add_settings_field( 'wc_organizer_subject', __( 'Organizer email subject', 'anchor-schema' ), function() {
                $opts = $this->get_settings();
                ?>
                <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[wc_organizer_subject]" value="<?php echo esc_attr( $opts['wc_organizer_subject'] ); ?>" class="regular-text" />
                <?php
            }, 'anchor_events_settings', 'anchor_events_wc_emails' );

        }

        // v1.1 lifecycle email settings. Always shown (free + paid registrations).
        \add_settings_section( 'anchor_events_lifecycle_emails', __( 'Lifecycle Emails', 'anchor-schema' ), function() {
            echo '<p>' . esc_html__( 'Automated emails for registration reminders, cancellations, and organizer roster digests. Apply to both free (internal) and paid (WooCommerce) registrations. Available tokens: {event_title}, {event_url}, {event_date}, {event_time}, {venue}, {days_until}, {attendee_name}, {join_link}, {remaining}, {seat_count}, {order_number}, {order_url}, {status}, {site_name}.', 'anchor-schema' ) . '</p>';
        }, 'anchor_events_settings' );

        \add_settings_field( 'reminder_enabled', __( 'Send reminders', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[reminder_enabled]" value="1" <?php checked( $opts['reminder_enabled'] ); ?> />
                <?php echo esc_html__( 'Send a reminder email to registered attendees before the event', 'anchor-schema' ); ?>
            </label>
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'reminder_offsets', __( 'Reminder offsets (days)', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[reminder_offsets]" value="<?php echo esc_attr( $opts['reminder_offsets'] ); ?>" class="regular-text" />
            <p class="description"><?php echo esc_html__( 'Comma-separated whole days before the event start to send reminders (e.g. 7,1). Up to 5 values. Per-event overrides available in the event editor.', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'reminder_subject', __( 'Reminder subject', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[reminder_subject]" value="<?php echo esc_attr( $opts['reminder_subject'] ); ?>" class="regular-text" />
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'reminder_intro', __( 'Reminder email intro', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[reminder_intro]" rows="3" class="large-text"><?php echo esc_textarea( $opts['reminder_intro'] ); ?></textarea>
            <p class="description"><?php echo esc_html__( 'Tokens: {event_title}, {event_date}, {event_time}, {venue}, {days_until}, {attendee_name}, {join_link}.', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'notify_cancellation', __( 'Send cancellation emails', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notify_cancellation]" value="1" <?php checked( $opts['notify_cancellation'] ); ?> />
                <?php echo esc_html__( 'Notify the attendee when their registration is cancelled', 'anchor-schema' ); ?>
            </label>
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'cancellation_subject', __( 'Cancellation subject', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cancellation_subject]" value="<?php echo esc_attr( $opts['cancellation_subject'] ); ?>" class="regular-text" />
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'cancellation_intro', __( 'Cancellation email intro', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cancellation_intro]" rows="3" class="large-text"><?php echo esc_textarea( $opts['cancellation_intro'] ); ?></textarea>
            <p class="description"><?php echo esc_html__( 'Tokens: {event_title}, {attendee_name}, {status}, {site_name}.', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'organizer_email', __( 'Default organizer email', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="email" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[organizer_email]" value="<?php echo esc_attr( $opts['organizer_email'] ); ?>" class="regular-text" />
            <p class="description"><?php echo esc_html__( 'Fallback recipient for organizer notices. A per-event organizer email overrides this; if both are blank, the site admin email is used.', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'organizer_roster_email', __( 'Organizer roster digest', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[organizer_roster_email]" value="1" <?php checked( $opts['organizer_roster_email'] ); ?> />
                <?php echo esc_html__( 'Email the organizer the confirmed roster before the event starts', 'anchor-schema' ); ?>
            </label>
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'roster_auto_offset', __( 'Roster digest offset (days)', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[roster_auto_offset]" value="<?php echo esc_attr( $opts['roster_auto_offset'] ); ?>" min="0" class="small-text" />
            <p class="description"><?php echo esc_html__( 'How many days before the event start to send the roster digest (0 = day of).', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'roster_subject', __( 'Roster digest subject', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[roster_subject]" value="<?php echo esc_attr( $opts['roster_subject'] ); ?>" class="regular-text" />
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'roster_intro', __( 'Roster digest intro', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[roster_intro]" rows="3" class="large-text"><?php echo esc_textarea( $opts['roster_intro'] ); ?></textarea>
            <p class="description"><?php echo esc_html__( 'Tokens: {event_title}, {event_date}, {event_time}, {venue}, {seat_count}, {remaining}, {site_name}.', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_section( 'anchor_events_slugs', __( 'Permalinks', 'anchor-schema' ), function() {
            echo '<p>' . esc_html__( 'Customize event URL slugs.', 'anchor-schema' ) . '</p>';
        }, 'anchor_events_settings' );

        \add_settings_field( 'event_slug', __( 'Event slug', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[event_slug]" value="<?php echo esc_attr( $opts['event_slug'] ); ?>" class="regular-text" />
            <?php
        }, 'anchor_events_settings', 'anchor_events_slugs' );
    }

    public function sanitize_settings( $input ) {
        $defaults = $this->get_settings();
        $output = [
            'timezone_mode' => in_array( $input['timezone_mode'] ?? 'site', [ 'site', 'event' ], true ) ? $input['timezone_mode'] : 'site',
            'archive_hide_past' => ! empty( $input['archive_hide_past'] ),
            'template_source' => in_array( $input['template_source'] ?? 'theme', [ 'theme', 'plugin' ], true ) ? $input['template_source'] : 'theme',
            'registration_internal' => ! empty( $input['registration_internal'] ),
            'admin_email' => sanitize_email( $input['admin_email'] ?? '' ),
            'notify_admin' => ! empty( $input['notify_admin'] ),
            'notify_user' => ! empty( $input['notify_user'] ),
            'confirmation_message' => isset( $input['confirmation_message'] ) ? sanitize_textarea_field( $input['confirmation_message'] ) : $defaults['confirmation_message'],
            'max_guests' => max( 0, min( 50, (int) ( $input['max_guests'] ?? 0 ) ) ),
            'register_button_label' => sanitize_text_field( $input['register_button_label'] ?? '' ),
            'register_button_color' => \sanitize_hex_color( $input['register_button_color'] ?? '' ) ?: $defaults['register_button_color'],
            'event_slug' => sanitize_title( $input['event_slug'] ?? $defaults['event_slug'] ),
        ];
        if ( ! $output['event_slug'] ) {
            $output['event_slug'] = $defaults['event_slug'];
        }

        // Phase 6 — WooCommerce email settings. Only read from $input when the WC
        // subsection actually renders (class_exists). Otherwise preserve the stored
        // values so a non-WC save doesn't clobber them.
        // organizer_email is now an always-shown lifecycle field (free + paid sites).
        $output['organizer_email'] = sanitize_email( $input['organizer_email'] ?? '' );

        if ( \class_exists( 'WooCommerce' ) ) {
            $output['wc_notify_customer']   = ! empty( $input['wc_notify_customer'] );
            $output['wc_notify_organizer']  = ! empty( $input['wc_notify_organizer'] );
            $output['wc_customer_subject']  = sanitize_text_field( $input['wc_customer_subject'] ?? $defaults['wc_customer_subject'] );
            $output['wc_customer_intro']    = sanitize_textarea_field( $input['wc_customer_intro'] ?? $defaults['wc_customer_intro'] );
            $output['wc_organizer_subject'] = sanitize_text_field( $input['wc_organizer_subject'] ?? $defaults['wc_organizer_subject'] );
        } else {
            $output['wc_notify_customer']   = $defaults['wc_notify_customer'];
            $output['wc_notify_organizer']  = $defaults['wc_notify_organizer'];
            $output['wc_customer_subject']  = $defaults['wc_customer_subject'];
            $output['wc_customer_intro']    = $defaults['wc_customer_intro'];
            $output['wc_organizer_subject'] = $defaults['wc_organizer_subject'];
        }
        // Email sender identity (applied as per-message headers on event emails).
        $output['email_from_name']        = sanitize_text_field( $input['email_from_name'] ?? '' );
        $output['email_from_address']     = sanitize_email( $input['email_from_address'] ?? '' );
        $output['email_reply_to_name']    = sanitize_text_field( $input['email_reply_to_name'] ?? '' );
        $output['email_reply_to_address'] = sanitize_email( $input['email_reply_to_address'] ?? '' );
        $output['email_bcc']              = sanitize_email( $input['email_bcc'] ?? '' );

        // Reserved/unused — preserve stored value (no UI field).
        $output['notify_attendee'] = $defaults['notify_attendee'];

        // v1.1 lifecycle email settings (always saved — not WC-gated).
        $output['reminder_enabled']     = ! empty( $input['reminder_enabled'] );
        $output['reminder_offsets']     = $this->sanitize_offset_csv( $input['reminder_offsets'] ?? $defaults['reminder_offsets'] );
        $output['reminder_subject']     = \sanitize_text_field( $input['reminder_subject'] ?? '' ) ?: $defaults['reminder_subject'];
        $output['reminder_intro']       = \sanitize_textarea_field( $input['reminder_intro'] ?? '' ) ?: $defaults['reminder_intro'];
        $output['notify_cancellation']  = ! empty( $input['notify_cancellation'] );
        $output['cancellation_subject'] = \sanitize_text_field( $input['cancellation_subject'] ?? '' ) ?: $defaults['cancellation_subject'];
        $output['cancellation_intro']   = \sanitize_textarea_field( $input['cancellation_intro'] ?? '' ) ?: $defaults['cancellation_intro'];
        $output['organizer_roster_email'] = ! empty( $input['organizer_roster_email'] );
        $output['roster_auto_offset']   = max( 0, (int) ( $input['roster_auto_offset'] ?? 1 ) );
        $output['roster_subject']       = \sanitize_text_field( $input['roster_subject'] ?? '' ) ?: $defaults['roster_subject'];
        $output['roster_intro']         = \sanitize_textarea_field( $input['roster_intro'] ?? '' ) ?: $defaults['roster_intro'];

        return $output;
    }

    /** Normalize a CSV of day offsets → sorted-descending, de-duped, positive ints (≤5). */
    private function sanitize_offset_csv( $raw ) {
        $days = array_filter( array_map( 'intval', explode( ',', (string) $raw ) ), function ( $d ) { return $d > 0; } );
        $days = array_values( array_unique( $days ) );
        rsort( $days );
        $days = array_slice( $days, 0, 5 );
        return implode( ',', $days );
    }

    public function render_tab_content() {
        echo '<p>' . \esc_html__( 'Display events with these shortcodes:', 'anchor-schema' ) . '</p>';
        echo '<ul style="margin-left:18px;list-style:disc;">';
        echo '<li><code>[events_list]</code> ' . \esc_html__( 'List events. Attributes: category, tag, type, status, limit, orderby (date|title|priority), order (ASC|DESC), show_past (yes|no).', 'anchor-schema' ) . '</li>';
        echo '<li><code>[featured_events]</code> ' . \esc_html__( 'Show featured events. Attributes: limit, orderby (priority|date), order (ASC|DESC).', 'anchor-schema' ) . '</li>';
        echo '<li><code>[event_calendar]</code> ' . \esc_html__( 'Monthly calendar. Attributes: month=YYYY-MM, view=month|list, show_past (yes|no).', 'anchor-schema' ) . '</li>';
        echo '<li><code>[event_registration]</code> ' . \esc_html__( 'Registration form for an event. Attributes: id=POST_ID, slug=event-slug, show_title (yes|no), show_notice (yes|no). Auto-appended to an event\'s content when you enable registration, so it survives page builders like Divi.', 'anchor-schema' ) . '</li>';
        echo '<li><code>[event_gallery]</code> ' . \esc_html__( 'Photo gallery for an event. Attributes: id=POST_ID, slug=event-slug, size=thumbnail|medium|large|full, columns=1-6. Defaults to the current event when used on an event page.', 'anchor-schema' ) . '</li>';
        echo '<li><code>[event_registrants_list]</code> ' . \esc_html__( 'Admin-only: list every event with a collapsible panel of registrants. Only visible to users with edit_others_posts (admins + editors). Attributes: show_past (yes|no), limit, order (ASC|DESC).', 'anchor-schema' ) . '</li>';
        echo '<li><code>[event_manager]</code> ' . \esc_html__( 'Admin-only frontend dashboard: list, accordion registrants, create, edit, and trash events with a native WP media picker for featured image + gallery. Only visible to admins/editors. Attributes: show_past (yes|no), limit, order (ASC|DESC).', 'anchor-schema' ) . '</li>';
        echo '</ul>';
        echo '<p>' . \esc_html__( 'You can also link to the events archive at /event/ (or your custom slug).', 'anchor-schema' ) . '</p>';
        echo '<form method="post" action="options.php">';
        \settings_fields( 'anchor_events_group' );
        \do_settings_sections( 'anchor_events_settings' );
        \submit_button();
        echo '</form>';

        $this->render_error_log_panel();
    }

    /**
     * Read-only "Event error log" panel for the Events settings tab. Shows the most
     * recent entries from the site-wide anchor_events_error_log option and a nonced
     * "Clear error log" button. Capped to users with edit_others_posts.
     */
    private function render_error_log_panel() {
        if ( ! \current_user_can( 'edit_others_posts' ) ) {
            return;
        }

        $log = \get_option( Events_Log::ERROR_OPTION, [] );
        if ( ! \is_array( $log ) ) {
            $log = [];
        }

        echo '<hr style="margin:24px 0;" />';
        echo '<h2>' . \esc_html__( 'Event error log', 'anchor-schema' ) . '</h2>';
        echo '<p class="description">' . \esc_html__( 'Recent registration/email/sync failures. Most recent first.', 'anchor-schema' ) . '</p>';

        if ( isset( $_GET['anchor_events_log_cleared'] ) ) {
            echo '<div class="notice notice-success inline"><p>' . \esc_html__( 'Error log cleared.', 'anchor-schema' ) . '</p></div>';
        }

        if ( empty( $log ) ) {
            echo '<p>' . \esc_html__( 'No errors logged.', 'anchor-schema' ) . '</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:840px;">';
        echo '<thead><tr>';
        echo '<th>' . \esc_html__( 'Time', 'anchor-schema' ) . '</th>';
        echo '<th>' . \esc_html__( 'Code', 'anchor-schema' ) . '</th>';
        echo '<th>' . \esc_html__( 'Context', 'anchor-schema' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( \array_slice( \array_reverse( $log ), 0, 100 ) as $entry ) {
            $time    = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
            $code    = isset( $entry['code'] ) ? (string) $entry['code'] : '';
            $context = isset( $entry['context'] ) ? $entry['context'] : [];
            $when    = $time ? \date_i18n( 'Y-m-d H:i:s', $time ) : '';
            $ctx_str = \is_scalar( $context ) ? (string) $context : \wp_json_encode( $context );
            echo '<tr>';
            echo '<td>' . \esc_html( $when ) . '</td>';
            echo '<td><code>' . \esc_html( $code ) . '</code></td>';
            echo '<td style="word-break:break-word;">' . \esc_html( (string) $ctx_str ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
        echo '<input type="hidden" name="action" value="anchor_events_clear_error_log" />';
        \wp_nonce_field( 'anchor_events_clear_error_log' );
        \submit_button( \__( 'Clear error log', 'anchor-schema' ), 'delete', 'submit', false );
        echo '</form>';
    }

    /**
     * admin-post handler: clear the site-wide event error log. Cap edit_others_posts
     * + nonce. Lives in the Module (not the WC class) because the error log and its
     * panel are present on all sites, WooCommerce or not.
     */
    public function handle_clear_error_log() {
        if ( ! \current_user_can( 'edit_others_posts' ) ) {
            \wp_die( \esc_html__( 'You are not allowed to do this.', 'anchor-schema' ) );
        }
        \check_admin_referer( 'anchor_events_clear_error_log' );
        \delete_option( Events_Log::ERROR_OPTION );

        $redirect = \wp_get_referer();
        if ( ! $redirect ) {
            $redirect = \admin_url();
        }
        \wp_safe_redirect( \add_query_arg( 'anchor_events_log_cleared', '1', $redirect ) );
        exit;
    }

    public function handle_settings_update( $old, $new ) {
        if ( ( $old['event_slug'] ?? '' ) !== ( $new['event_slug'] ?? '' ) ) {
            \flush_rewrite_rules();
        }
    }

    public function filter_archive_query( $query ) {
        if ( \is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( ! $query->is_post_type_archive( self::CPT ) ) {
            return;
        }
        $settings = $this->get_settings();
        $meta_query = [ $this->build_hide_clause() ];
        if ( ! empty( $settings['archive_hide_past'] ) ) {
            $meta_query[] = $this->build_visibility_clause();
        }
        $query->set( 'meta_query', $meta_query );
        $query->set( 'meta_key', $this->meta_key( 'start_ts' ) );
        $query->set( 'orderby', 'meta_value_num' );
        $query->set( 'order', 'ASC' );
    }

    public function clear_caches_on_delete( $post_id ) {
        $post_type = \get_post_type( $post_id );
        if ( $post_type === self::CPT || $post_type === self::REG_CPT ) {
            $this->clear_caches();
        }
    }

    public function clear_caches() {
        $keys = \get_option( self::CACHE_OPTION, [] );
        if ( ! is_array( $keys ) ) {
            $keys = [];
        }
        foreach ( $keys as $key ) {
            \delete_transient( $key );
        }
        \update_option( self::CACHE_OPTION, [] );
    }

    private function store_cache_key( $key ) {
        $keys = \get_option( self::CACHE_OPTION, [] );
        if ( ! is_array( $keys ) ) {
            $keys = [];
        }
        if ( ! in_array( $key, $keys, true ) ) {
            $keys[] = $key;
            \update_option( self::CACHE_OPTION, $keys, false );
        }
    }

    private function get_cached_ids( $args ) {
        $key = 'anchor_events_' . md5( wp_json_encode( $args ) );
        $cached = \get_transient( $key );
        if ( $cached !== false ) {
            return $cached;
        }

        // Cache IDs only to keep transient payloads small and fast to rebuild markup.
        $query_args = $args;
        $query_args['fields'] = 'ids';
        $query = new \WP_Query( $query_args );
        $ids = $query->posts;

        \set_transient( $key, $ids, HOUR_IN_SECONDS );
        $this->store_cache_key( $key );

        return $ids;
    }

    private function count_events_by_status( $status ) {
        $query = new \WP_Query( [
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => $this->meta_key( 'status' ),
                    'value' => $status,
                    'compare' => '=',
                ],
            ],
        ] );
        return $query->found_posts;
    }

    public function get_meta( $post_id ) {
        $defaults = $this->get_meta_defaults();
        foreach ( $defaults as $key => $value ) {
            $stored = \get_post_meta( $post_id, $this->meta_key( $key ), true );
            if ( $stored === '' ) {
                $stored = $value;
            }
            if ( is_bool( $value ) ) {
                $stored = (bool) $stored;
            }
            if ( is_int( $value ) ) {
                $stored = (int) $stored;
            }
            if ( is_array( $value ) && ! is_array( $stored ) ) {
                $stored = $value;
            }
            $defaults[ $key ] = $stored;
        }
        return $defaults;
    }

    public function meta_key( $key ) {
        return '_anchor_event_' . $key;
    }

    /* ══════════════════════════════════════════════════════════
       Event-type / registration-mode data model (Task 1.1+1.2).
       Read-only resolvers + a one-time back-compat migration. The metabox
       authoring UI + save_meta() allow-list wiring for these keys landed in
       Task 1.3+1.4 (see render_meta_box()/save_meta() above).
       ══════════════════════════════════════════════════════════ */

    /**
     * The event's type, defaulting to 'single' when unset or invalid.
     *
     * @param int $event_id
     * @return string One of single|multisession|offering|recurring.
     */
    public function event_type( $event_id ) {
        $valid = [ 'single', 'multisession', 'offering', 'recurring' ];
        $stored = (string) \get_post_meta( $event_id, $this->meta_key( 'type' ), true );
        return in_array( $stored, $valid, true ) ? $stored : 'single';
    }

    /**
     * The event's registration mode. An explicit stored value wins; otherwise
     * it's derived from legacy signals for back-compat with pre-existing events
     * (mirrors the logic in migrate_registration_mode()).
     *
     * @param int $event_id
     * @return string One of wc|free|external.
     */
    public function registration_mode( $event_id ) {
        $valid = [ 'wc', 'free', 'external' ];
        $stored = (string) \get_post_meta( $event_id, $this->meta_key( 'registration_mode' ), true );
        if ( in_array( $stored, $valid, true ) ) {
            return $stored;
        }
        return $this->derive_registration_mode( $event_id );
    }

    /**
     * Derive a registration mode for an event that has no explicit stored
     * value, from legacy registration-type/url meta and ticket-tier/product
     * signals. Shared by registration_mode() and migrate_registration_mode().
     *
     * @param int $event_id
     * @return string One of wc|free|external.
     */
    private function derive_registration_mode( $event_id ) {
        $legacy_type = \get_post_meta( $event_id, $this->meta_key( 'registration_type' ), true );
        $legacy_url = \get_post_meta( $event_id, $this->meta_key( 'registration_url' ), true );
        if ( $legacy_type === 'external' || ! empty( $legacy_url ) ) {
            return 'external';
        }

        $managed_product = \get_post_meta( $event_id, $this->meta_key( 'managed_product' ), true );
        if ( ! empty( $managed_product ) ) {
            return 'wc';
        }
        foreach ( $this->ticket_types->get( $event_id ) as $tier ) {
            if ( ! empty( $tier['active'] ) && (float) $tier['price'] > 0 ) {
                return 'wc';
            }
        }

        return 'free';
    }

    /**
     * Normalized session rows for a multisession event.
     *
     * @param int $event_id
     * @return array<int,array{date:string,start_time:string,end_time:string,label:string}>
     */
    public function get_sessions( $event_id ) {
        $stored = \get_post_meta( $event_id, $this->meta_key( 'sessions' ), true );
        if ( ! is_array( $stored ) ) {
            return [];
        }

        $sessions = [];
        foreach ( $stored as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $date = \sanitize_text_field( $row['date'] ?? '' );
            if ( $date === '' ) {
                continue;
            }
            $sessions[] = [
                'date' => $date,
                'start_time' => \sanitize_text_field( $row['start_time'] ?? '' ),
                'end_time' => \sanitize_text_field( $row['end_time'] ?? '' ),
                'label' => \sanitize_text_field( $row['label'] ?? '' ),
            ];
        }
        return $sessions;
    }

    /**
     * One-time back-compat migration: derives and persists registration_mode
     * for events that predate the key. Idempotent — guarded by an option flag,
     * so it's safe to call on every request.
     */
    public function migrate_registration_mode() {
        if ( \get_option( 'anchor_events_regmode_migrated' ) ) {
            return;
        }

        $query = new \WP_Query( [
            'post_type' => self::CPT,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'meta_query' => [
                [ 'key' => $this->meta_key( 'registration_mode' ), 'compare' => 'NOT EXISTS' ],
            ],
        ] );

        foreach ( $query->posts as $event_id ) {
            \update_post_meta( $event_id, $this->meta_key( 'registration_mode' ), $this->derive_registration_mode( $event_id ) );
        }

        \update_option( 'anchor_events_regmode_migrated', true, false );
    }

    /**
     * Active FREE tiers (price == 0) for an event, in order. Used by the inline
     * registration form (paid tiers are sold through WooCommerce, not here).
     *
     * @param int $event_id
     * @return array<int,array>
     */
    public function get_active_free_tiers( $event_id ) {
        $tiers = [];
        foreach ( $this->ticket_types->get( $event_id ) as $tier ) {
            if ( empty( $tier['active'] ) ) {
                continue;
            }
            if ( (float) ( $tier['price'] ?? 0 ) > 0 ) {
                continue;
            }
            $tiers[] = $tier;
        }
        return $tiers;
    }

    private function sanitize_date( $value ) {
        if ( ! $value ) {
            return '';
        }
        $value = sanitize_text_field( $value );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return '';
        }
        return $value;
    }

    private function sanitize_time( $value ) {
        if ( ! $value ) {
            return '';
        }
        $value = sanitize_text_field( $value );
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
            return '';
        }
        return $value;
    }

    private function get_status_options() {
        return [
            'upcoming' => __( 'Upcoming', 'anchor-schema' ),
            'ongoing' => __( 'Ongoing', 'anchor-schema' ),
            'past' => __( 'Past', 'anchor-schema' ),
            'cancelled' => __( 'Cancelled', 'anchor-schema' ),
            'draft' => __( 'Draft', 'anchor-schema' ),
        ];
    }

    /**
     * Public wrapper around calculate_timestamps() (spec Phase 2, Task 2.1) so
     * the Occurrences engine can derive a child occurrence's start_ts/end_ts
     * using the exact same timezone/all-day logic as the classic per-event
     * save path, without duplicating it.
     *
     * @param array $meta Meta array with start_date/end_date/start_time/end_time/timezone/all_day.
     * @return array{start:int,end:int}
     */
    public function compute_timestamps( array $meta ) {
        return $this->calculate_timestamps( $meta );
    }

    /**
     * Public wrapper around calculate_status() (spec Phase 2, Task 2.1) — auto
     * status derivation from start/end dates, for the Occurrences engine.
     *
     * @param array $meta
     * @return string
     */
    public function compute_status( array $meta ) {
        return $this->calculate_status( $meta );
    }

    private function calculate_status( $meta ) {
        if ( empty( $meta['start_date'] ) ) {
            return 'draft';
        }

        $timestamps = $this->calculate_timestamps( $meta );
        $now = time();
        if ( $now < $timestamps['start'] ) {
            return 'upcoming';
        }
        if ( $now >= $timestamps['start'] && $now <= $timestamps['end'] ) {
            return 'ongoing';
        }
        return 'past';
    }

    private function calculate_timestamps( $meta ) {
        $settings = $this->get_settings();
        if ( $settings['timezone_mode'] === 'site' ) {
            $timezone = \get_option( 'timezone_string' ) ?: 'UTC';
        } else {
            $timezone = $meta['timezone'] ? $meta['timezone'] : ( \get_option( 'timezone_string' ) ?: 'UTC' );
        }
        $start_time = $meta['all_day'] ? '00:00' : ( $meta['start_time'] ?: '00:00' );
        $end_date = $meta['end_date'] ?: $meta['start_date'];
        $end_time = $meta['all_day'] ? '23:59' : ( $meta['end_time'] ?: $start_time );

        $start = $this->to_timestamp( $meta['start_date'], $start_time, $timezone );
        $end = $this->to_timestamp( $end_date, $end_time, $timezone );

        if ( $end < $start ) {
            $end = $start;
        }

        return [ 'start' => $start, 'end' => $end ];
    }

    private function to_timestamp( $date, $time, $timezone ) {
        if ( ! $date ) {
            return 0;
        }
        try {
            $tz = new \DateTimeZone( $timezone ?: 'UTC' );
        } catch ( \Exception $e ) {
            $tz = new \DateTimeZone( 'UTC' );
        }
        $dt = \DateTime::createFromFormat( 'Y-m-d H:i', $date . ' ' . $time, $tz );
        if ( ! $dt ) {
            return 0;
        }
        return $dt->getTimestamp();
    }

    private function diff_months( $a, $b ) {
        if ( ! preg_match( '/^(\\d{4})-(\\d{2})$/', $a, $ma ) || ! preg_match( '/^(\\d{4})-(\\d{2})$/', $b, $mb ) ) {
            return 0;
        }
        $am = ( (int) $ma[1] * 12 ) + (int) $ma[2];
        $bm = ( (int) $mb[1] * 12 ) + (int) $mb[2];
        return $am - $bm;
    }

    private function render_calendar_month( $atts, $force_month = '' ) {
        $show_past = $atts['show_past'] ?? 'yes';

        $requested_month = '';
        if ( $force_month ) {
            $requested_month = $force_month;
        } elseif ( ! empty( $_GET['anchor_events_month'] ) ) {
            $requested_month = sanitize_text_field( wp_unslash( $_GET['anchor_events_month'] ) );
        } elseif ( ! empty( $atts['month'] ) ) {
            $requested_month = sanitize_text_field( $atts['month'] );
        }
        if ( ! preg_match( '/^\\d{4}-\\d{2}$/', $requested_month ) ) {
            $requested_month = date( 'Y-m' );
        }

        $month = $requested_month;
        $month_start = $month . '-01';
        $timezone = \get_option( 'timezone_string' ) ?: 'UTC';
        $start = $this->to_timestamp( $month_start, '00:00', $timezone );
        $end = strtotime( '+1 month', strtotime( $month_start ) );

        $diff_to_now = $this->diff_months( $month, date( 'Y-m' ) );
        $prev_month = ( $diff_to_now > -12 ) ? date( 'Y-m', strtotime( '-1 month', strtotime( $month_start ) ) ) : '';
        $next_month = ( $diff_to_now < 12 ) ? date( 'Y-m', strtotime( '+1 month', strtotime( $month_start ) ) ) : '';

        $meta_query = [
            [
                'key' => $this->meta_key( 'start_ts' ),
                'value' => [ $start, $end ],
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC',
            ],
            $this->build_hide_clause(),
        ];
        if ( $show_past === 'no' ) {
            $meta_query[] = $this->build_visibility_clause();
        }

        $args = [
            'post_type' => self::CPT,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => $meta_query,
            'orderby' => 'meta_value_num',
            'meta_key' => $this->meta_key( 'start_ts' ),
            'order' => 'ASC',
        ];

        $args = \apply_filters( 'anchor_events_query_args', $args, $atts );
        $events = $this->get_cached_ids( $args );
        $by_day = [];
        foreach ( $events as $event_id ) {
            $meta = $this->get_meta( $event_id );
            $day = $meta['start_date'];
            if ( ! $day ) {
                continue;
            }
            if ( ! isset( $by_day[ $day ] ) ) {
                $by_day[ $day ] = [];
            }
            $by_day[ $day ][] = $event_id;
        }

        $calendar_month = $month_start;
        $calendar_first = strtotime( $month_start );
        $calendar_days = (int) date( 't', $calendar_first );
        $calendar_start_weekday = (int) date( 'N', $calendar_first );
        $calendar_events = $by_day;
        $calendar_prev_month = $prev_month;
        $calendar_next_month = $next_month;
        $calendar_show_past = $show_past;

        $template = $this->locate_template( 'calendar.php' );
        if ( $template && file_exists( $template ) ) {
            ob_start();
            include $template;
            return ob_get_clean();
        }

        return '<div class="anchor-events-empty">' . esc_html__( 'Calendar template not found.', 'anchor-schema' ) . '</div>';
    }

    private function format_date_time( $meta, $include_range = false ) {
        if ( ! $meta['start_date'] ) {
            return '';
        }
        $start = $meta['start_date'];
        $start_time = $meta['all_day'] ? '' : $meta['start_time'];
        $end_date = $meta['end_date'];
        $end_time = $meta['all_day'] ? '' : $meta['end_time'];

        $output = date_i18n( 'M j, Y', strtotime( $start ) );
        if ( $start_time ) {
            $output .= ' ' . $start_time;
        }
        if ( $include_range ) {
            if ( $end_date && $end_date !== $start ) {
                $output .= ' - ' . date_i18n( 'M j, Y', strtotime( $end_date ) );
                if ( $end_time ) {
                    $output .= ' ' . $end_time;
                }
            } elseif ( $end_time ) {
                $output .= ' - ' . $end_time;
            }
        }
        return $output;
    }

    private function format_address( $meta ) {
        $parts = array_filter( [
            $meta['address_street'],
            $meta['address_city'],
            $meta['address_state'],
            $meta['address_zip'],
            $meta['address_country'],
        ] );
        return implode( ', ', $parts );
    }

    private function get_event_status( $post_id, $meta = null ) {
        if ( ! $meta ) {
            $meta = $this->get_meta( $post_id );
        }
        if ( $meta['status_mode'] === 'manual' ) {
            return $meta['status'];
        }
        // Pure read (bug #2): never write during render. Persistence happens in
        // save contexts (save_meta / handle_event_manager_save / transition_post_status)
        // and the daily anchor_events_status_sweep cron.
        return $this->calculate_status( $meta );
    }

    private function build_visibility_clause() {
        return [
            'key' => $this->meta_key( 'end_ts' ),
            'value' => time(),
            'compare' => '>=',
            'type' => 'NUMERIC',
        ];
    }

    private function build_hide_clause() {
        return [
            'relation' => 'OR',
            [
                'key' => $this->meta_key( 'hide_from_archive' ),
                'compare' => 'NOT EXISTS',
            ],
            [
                'key' => $this->meta_key( 'hide_from_archive' ),
                'value' => '1',
                'compare' => '!=',
            ],
        ];
    }

    private function build_range_clause( $start, $end ) {
        $start = $this->sanitize_date( $start );
        $end = $this->sanitize_date( $end );
        if ( ! $start && ! $end ) {
            return null;
        }
        $start_ts = $start ? strtotime( $start . ' 00:00' ) : 0;
        $end_ts = $end ? strtotime( $end . ' 23:59' ) : strtotime( '+5 years' );
        return [
            'key' => $this->meta_key( 'start_ts' ),
            'value' => [ $start_ts, $end_ts ],
            'compare' => 'BETWEEN',
            'type' => 'NUMERIC',
        ];
    }

    public function get_registration_status( $event_id, $meta, $party_size = 1, $tier = null ) {
        // Single capacity authority lives in the data layer (spec §9.1). Passing the
        // tier enforces its per-tier quota alongside the event total.
        return $this->registrations->capacity_decision( $event_id, $meta, $party_size, $tier );
    }

    private function get_registration_fields() {
        $fields = [];
        // Allow developers to extend registration fields with custom inputs.
        return \apply_filters( 'anchor_events_registration_fields', $fields );
    }

    public function ajax_calendar() {
        \check_ajax_referer( 'anchor_events_calendar', 'nonce' );
        $month = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
        $show_past = isset( $_POST['show_past'] ) ? sanitize_text_field( wp_unslash( $_POST['show_past'] ) ) : 'yes';
        $html = $this->render_calendar_month( [ 'show_past' => $show_past ], $month );
        \wp_send_json_success( [ 'html' => $html ] );
    }

    // Counting now lives in the Registrations data layer (spec §9.1). These thin
    // public wrappers preserve the existing internal callers and signatures.
    public function get_registrations( $event_id, $limit = 50 ) {
        return $this->registrations->get_registrations( $event_id, $limit );
    }

    public function get_registration_count( $event_id, $status = 'confirmed' ) {
        return $this->registrations->record_count( $event_id, $status );
    }

    public function get_attendee_count( $event_id, $status = 'confirmed' ) {
        return $this->registrations->attendee_count( $event_id, $status );
    }

    public function send_registration_emails( $event_id, $name, $email, $status, $guests = 0 ) {
        $settings = $this->get_settings();
        $event_title = \get_the_title( $event_id );
        $event_link = \get_permalink( $event_id );
        $guests = max( 0, (int) $guests );

        if ( ! empty( $settings['notify_admin'] ) ) {
            $admin_email = $settings['admin_email'] ?: \get_option( 'admin_email' );
            $subject = sprintf( __( 'New registration for %s', 'anchor-schema' ), $event_title );
            $message = sprintf(
                __( "Name: %s\nEmail: %s\nStatus: %s\nGuests: %d\nParty size: %d\nEvent: %s", 'anchor-schema' ),
                $name,
                $email,
                $status,
                $guests,
                1 + $guests,
                $event_link
            );
            // Plain-text email, but still carry the configured sender identity.
            $sent = \wp_mail( $admin_email, $subject, $message, $this->email_headers() );
            if ( ! $sent ) {
                Events_Log::error( 'email_send_returned_false', [ 'to' => $admin_email, 'subject' => $subject ] );
            }
        }

        if ( ! empty( $settings['notify_user'] ) ) {
            $subject = sprintf( __( 'You are registered for %s', 'anchor-schema' ), $event_title );
            $html = $this->build_registration_email_html( $event_id, $name, $status, $settings, $guests );
            $this->send_html_email( $email, $subject, $html );
        }
    }

    // -------------------------------------------------------------------------
    // v1.1: Attendee cancellation / refund email (spec §7)
    // -------------------------------------------------------------------------

    /** Enqueue (do not send) on a live→cancelled/refunded transition (spec §7.2). */
    public function on_seat_status_changed( $seat_id, $from, $to, $actor ) {
        $terminal = [ \Anchor\Events\Registrations::STATUS_CANCELLED, \Anchor\Events\Registrations::STATUS_REFUNDED ];
        $live     = [ \Anchor\Events\Registrations::STATUS_CONFIRMED, \Anchor\Events\Registrations::STATUS_WAITLIST ];
        if ( ! \in_array( $to, $terminal, true ) || ! \in_array( $from, $live, true ) ) {
            return;
        }
        $settings = $this->get_settings();
        if ( empty( $settings['notify_cancellation'] ) ) {
            return;
        }
        if ( \get_post_meta( (int) $seat_id, '_anchor_event_cancel_emailed', true ) ) {
            return;
        }
        $this->pending_cancellation_emails[ (int) $seat_id ] = (int) $seat_id;
    }

    /** Flush queued cancellation emails outside any lock (shutdown + explicit end-of-reconcile). */
    public function flush_cancellation_emails() {
        if ( empty( $this->pending_cancellation_emails ) ) {
            return;
        }
        $queue = $this->pending_cancellation_emails;
        $this->pending_cancellation_emails = [];
        foreach ( $queue as $seat_id ) {
            $this->send_cancellation_email( (int) $seat_id );
        }
    }

    /**
     * Build + send one attendee cancellation/refund email; idempotent via marker.
     *
     * Note: Registrations::get_seat_info() does not return email, name, or order_id
     * (it returns id, status, seat_index, event_id, order_item_id). Those three
     * fields are read directly from seat post meta here.
     *
     * @param int $seat_id
     * @return bool
     */
    public function send_cancellation_email( $seat_id ) {
        $seat_id = (int) $seat_id;
        if ( \get_post_meta( $seat_id, '_anchor_event_cancel_emailed', true ) ) {
            return true;
        }
        // Defense-in-depth: this method is public, so re-honor the toggle here even
        // though on_seat_status_changed() already gates the normal enqueue path.
        $settings = $this->get_settings();
        if ( empty( $settings['notify_cancellation'] ) ) {
            return false;
        }
        $info  = $this->registrations->get_seat_info( $seat_id );
        if ( ! \is_array( $info ) ) {
            return false;
        }
        // get_seat_info() omits email, name, order_id — read from meta directly.
        $email    = (string) \get_post_meta( $seat_id, '_anchor_event_email', true );
        $name     = (string) \get_post_meta( $seat_id, '_anchor_event_name', true );
        $order_id = (int) \get_post_meta( $seat_id, '_anchor_event_order_id', true );
        if ( $email === '' ) {
            return false;
        }
        $event_id = (int) $info['event_id'];
        $status   = (string) $info['status']; // cancelled | refunded
        $order    = ( $order_id > 0 && \function_exists( 'wc_get_order' ) ) ? \wc_get_order( $order_id ) : null;

        $tokens = $this->email_tokens( [ 'event_id' => $event_id, 'seat' => array_merge( $info, [ 'name' => $name, 'status' => $status ] ), 'order' => $order ?: null ] );
        $is_refund = ( $status === \Anchor\Events\Registrations::STATUS_REFUNDED );
        $subject = $this->expand_email_tokens(
            $is_refund ? \str_ireplace( 'cancelled', 'refunded', $settings['cancellation_subject'] ) : $settings['cancellation_subject'],
            $tokens
        );
        $intro = $this->expand_email_tokens(
            $is_refund ? \str_ireplace( 'cancelled', 'refunded', $settings['cancellation_intro'] ) : $settings['cancellation_intro'],
            $tokens
        );
        $detail_rows = [ [ 'label' => \__( 'Event', 'anchor-schema' ), 'value' => $tokens['event_title'] ] ];
        if ( $tokens['event_date'] !== '' ) {
            $detail_rows[] = [ 'label' => \__( 'Date', 'anchor-schema' ), 'value' => $tokens['event_date'] ];
        }
        if ( $order ) {
            $detail_rows[] = [ 'label' => \__( 'Order', 'anchor-schema' ), 'value' => '#' . $order->get_order_number() ];
        }
        $ctx = [
            'event_id'      => $event_id,
            'name'          => $name,
            'status'        => $status,          // suppresses join link in the builder
            'intro_message' => $intro,
            'detail_rows'   => $detail_rows,
            'cta_label'     => '',
            'cta_url'       => '',
        ];
        $html = $this->build_registration_email_html( $ctx );
        $sent = $this->send_html_email( $email, $subject, $html );
        if ( $sent ) {
            \update_post_meta( $seat_id, '_anchor_event_cancel_emailed', true );
        }
        return $sent;
    }

    /**
     * Replace {token} placeholders in a template string.
     *
     * Supported tokens depend on the caller; the array keys (without braces) are
     * the token names. Values are cast to string. Used for email subjects/intros.
     *
     * @param string $template
     * @param array  $tokens  [ token_name => value ].
     * @return string
     */
    public function expand_email_tokens( $template, array $tokens ) {
        $search  = [];
        $replace = [];
        foreach ( $tokens as $key => $value ) {
            $search[]  = '{' . $key . '}';
            $replace[] = (string) $value;
        }
        return \str_replace( $search, $replace, (string) $template );
    }

    /** Documented token set for all event emails (spec §9). */
    public function email_tokens( array $ctx ) {
        $event_id = (int) ( $ctx['event_id'] ?? 0 );
        $meta     = $event_id ? $this->get_meta( $event_id ) : [];
        $start_ts = (int) ( $meta['start_ts'] ?? 0 );
        $seat     = isset( $ctx['seat'] ) && is_array( $ctx['seat'] ) ? $ctx['seat'] : [];
        $order    = ( isset( $ctx['order'] ) && $ctx['order'] instanceof \WC_Order ) ? $ctx['order'] : null;

        $venue = '';
        if ( ! empty( $meta['virtual'] ) ) {
            $venue = __( 'Online', 'anchor-schema' );
        } elseif ( ! empty( $meta['venue'] ) ) {
            $venue = (string) $meta['venue'];
        }
        $join = '';
        if ( ! empty( $meta['virtual'] ) && ! empty( $meta['virtual_url'] )
            && ( ! $seat || ( $seat['status'] ?? '' ) !== 'waitlist' ) ) {
            $join = (string) $meta['virtual_url'];
        }
        $remaining = $ctx['remaining'] ?? '';
        if ( $remaining === '' && $event_id ) {
            $summary   = $this->registrations ? $this->registrations->get_event_summary( $event_id ) : [];
            $remaining = ( isset( $summary['remaining'] ) && (int) $summary['remaining'] >= 0 )
                ? (string) (int) $summary['remaining'] : __( 'unlimited', 'anchor-schema' );
        }
        $days_until = ( $start_ts && $start_ts > time() ) ? (string) (int) ceil( ( $start_ts - time() ) / DAY_IN_SECONDS ) : '';

        return [
            'event_title'  => $event_id ? \get_the_title( $event_id ) : \get_bloginfo( 'name' ),
            'event_url'    => $event_id ? \get_permalink( $event_id ) : \home_url(),
            'event_date'   => $start_ts ? \wp_date( \get_option( 'date_format' ), $start_ts ) : '',
            'event_time'   => ( $start_ts && empty( $meta['all_day'] ) ) ? \wp_date( \get_option( 'time_format' ), $start_ts ) : '',
            'venue'        => $venue,
            'days_until'   => $days_until,
            'attendee_name'=> (string) ( $seat['name'] ?? '' ),
            'join_link'    => $join,
            'remaining'    => (string) $remaining,
            'seat_count'   => (string) (int) ( $ctx['seat_count'] ?? 0 ),
            'order_number' => $order ? (string) $order->get_order_number() : '',
            'order_url'    => $order ? (string) $order->get_view_order_url() : '',
            'status'       => (string) ( $seat['status'] ?? '' ),
            'site_name'    => \get_bloginfo( 'name' ),
        ];
    }

    /** Resolve organizer recipient: per-event meta → global setting → admin_email (spec §8.2). */
    public function resolve_organizer_email( $event_id, $settings = null ) {
        $settings = is_array( $settings ) ? $settings : $this->get_settings();
        $meta  = $this->get_meta( (int) $event_id );
        $email = ! empty( $meta['organizer_email'] ) ? \sanitize_email( (string) $meta['organizer_email'] ) : '';
        if ( $email === '' && ! empty( $settings['organizer_email'] ) ) {
            $email = \sanitize_email( (string) $settings['organizer_email'] );
        }
        if ( $email === '' ) {
            $email = \sanitize_email( (string) \get_option( 'admin_email' ) );
        }
        return $email;
    }

    /**
     * Build the registration confirmation email HTML.
     *
     * Phase 6: accepts a single `$ctx` array (keys: event_id, name, status,
     * intro_message, guests, detail_rows[[label,value]], seat_list[], cta_label,
     * cta_url). A back-compat shim keeps the legacy positional free-path call
     * `build_registration_email_html( $event_id, $name, $status, $settings, $guests )`
     * working by detecting the legacy arg shape and constructing a $ctx from it.
     *
     * The `anchor_events_registration_email_html` filter is preserved (now passed
     * `$html, $ctx`).
     *
     * @param array|int   $arg      $ctx array OR legacy event_id int.
     * @param string|null $name     Legacy positional attendee name.
     * @param string|null $status   Legacy positional status.
     * @param array|null  $settings Legacy positional settings.
     * @param int         $guests   Legacy positional guest count.
     * @return string
     */
    public function build_registration_email_html( $arg, $name = null, $status = null, $settings = null, $guests = 0 ) {
        // Back-compat shim: positional free-path call passes an int event_id.
        if ( \is_array( $arg ) ) {
            $ctx = $arg;
        } else {
            $settings = \is_array( $settings ) ? $settings : $this->get_settings();
            $intro    = isset( $settings['confirmation_message'] ) && $settings['confirmation_message'] !== ''
                ? $settings['confirmation_message']
                : __( "Thanks for signing up. We're excited to see you at the event!", 'anchor-schema' );
            $ctx = [
                'event_id'      => (int) $arg,
                'name'          => (string) $name,
                'status'        => (string) $status,
                'intro_message' => $intro,
                'guests'        => (int) $guests,
                'detail_rows'   => [],
                'seat_list'     => [],
                'cta_label'     => __( 'View event details', 'anchor-schema' ),
                'cta_url'       => \get_permalink( (int) $arg ),
            ];
        }

        $ctx = \wp_parse_args( $ctx, [
            'event_id'      => 0,
            'name'          => '',
            'status'        => 'confirmed',
            'intro_message' => '',
            'guests'        => 0,
            'detail_rows'   => [],
            'seat_list'     => [],
            'cta_label'     => __( 'View event details', 'anchor-schema' ),
            'cta_url'       => '',
        ] );

        $event_id    = (int) $ctx['event_id'];
        $name        = (string) $ctx['name'];
        $status      = (string) $ctx['status'];
        $guests      = max( 0, (int) $ctx['guests'] );
        $event_title = $event_id ? \get_the_title( $event_id ) : \get_bloginfo( 'name' );
        $image_url   = $event_id ? \get_the_post_thumbnail_url( $event_id, 'large' ) : '';
        $site_name   = \get_bloginfo( 'name' );
        $cta_label   = (string) $ctx['cta_label'];
        $cta_url     = (string) $ctx['cta_url'];
        $message     = (string) $ctx['intro_message'];
        $detail_rows = \is_array( $ctx['detail_rows'] ) ? $ctx['detail_rows'] : [];
        $seat_list   = \is_array( $ctx['seat_list'] ) ? $ctx['seat_list'] : [];

        // A2: confirmed registrants of a virtual event get the actual join link in
        // the email so guest/logged-out attendees (free or paid) gain access without
        // needing to be logged in on the gated event page. Allowlisted to confirmed
        // only — cancelled/refunded/waitlist statuses must never receive the link.
        $join_url = '';
        if ( $event_id && $status === 'confirmed' ) {
            $event_meta = $this->get_meta( $event_id );
            if ( ! empty( $event_meta['virtual'] ) && ! empty( $event_meta['virtual_url'] ) ) {
                $join_url = (string) $event_meta['virtual_url'];
            }
        }

        $paragraphs = '';
        foreach ( preg_split( "/(\r\n|\n|\r){2,}/", trim( $message ) ) as $block ) {
            $block = trim( $block );
            if ( $block === '' ) {
                continue;
            }
            $paragraphs .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">'
                . nl2br( esc_html( $block ) )
                . '</p>';
        }

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width,initial-scale=1" />
            <title><?php echo esc_html( $event_title ); ?></title>
        </head>
        <body style="margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 12px;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
                            <?php if ( $image_url ) : ?>
                            <tr>
                                <td style="padding:0;">
                                    <img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $event_title ); ?>" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;" />
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td style="padding:28px 32px 8px;">
                                    <h1 style="margin:0;font-size:24px;line-height:1.3;color:#111;"><?php echo esc_html( $event_title ); ?></h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:16px 32px 8px;">
                                    <?php if ( $name ) : ?>
                                        <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">
                                            <?php echo esc_html( sprintf( __( 'Hi %s,', 'anchor-schema' ), $name ) ); ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php echo $paragraphs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — content is escaped above ?>
                                    <?php if ( $guests > 0 ) : ?>
                                        <p style="margin:0 0 16px;font-size:15px;line-height:1.5;color:#333;">
                                            <?php
                                            $party_size = 1 + (int) $guests;
                                            echo esc_html( sprintf(
                                                \_n( 'Your party of %d is confirmed (you + %d guest).', 'Your party of %d is confirmed (you + %d guests).', $guests, 'anchor-schema' ),
                                                $party_size,
                                                $guests
                                            ) );
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ( $status === 'waitlist' ) : ?>
                                        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#666;">
                                            <?php echo esc_html__( 'You are currently on the waitlist and will be notified if a spot opens up.', 'anchor-schema' ); ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $detail_rows ) ) : ?>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-collapse:collapse;">
                                            <?php foreach ( $detail_rows as $row ) :
                                                $label = isset( $row['label'] ) ? (string) $row['label'] : '';
                                                $value = isset( $row['value'] ) ? (string) $row['value'] : '';
                                                if ( $label === '' && $value === '' ) { continue; } ?>
                                                <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;"><?php echo esc_html( $label ); ?></td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;"><?php echo esc_html( $value ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $seat_list ) ) : ?>
                                        <p style="margin:0 0 6px;font-size:14px;font-weight:600;color:#333;"><?php echo esc_html__( 'Attendees', 'anchor-schema' ); ?></p>
                                        <ul style="margin:0 0 16px;padding:0 0 0 18px;font-size:14px;line-height:1.6;color:#333;">
                                            <?php foreach ( $seat_list as $seat ) : ?>
                                                <li><?php echo esc_html( (string) $seat ); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ( $join_url ) : ?>
                            <tr>
                                <td style="padding:8px 32px 0;">
                                    <a href="<?php echo esc_url( $join_url ); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:12px 20px;background:#0f766e;color:#ffffff;text-decoration:none;border-radius:4px;font-size:15px;">
                                        <?php echo esc_html__( 'Join the event', 'anchor-schema' ); ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if ( $cta_url && $cta_label ) : ?>
                            <tr>
                                <td style="padding:8px 32px 32px;">
                                    <a href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;padding:12px 20px;background:#111;color:#ffffff;text-decoration:none;border-radius:4px;font-size:15px;">
                                        <?php echo esc_html( $cta_label ); ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td style="padding:16px 32px 24px;border-top:1px solid #eee;font-size:12px;color:#888;">
                                    <?php echo esc_html( $site_name ); ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        $html = ob_get_clean();

        return \apply_filters( 'anchor_events_registration_email_html', $html, $ctx );
    }

    private function with_message( $url, $message ) {
        $url = $url ?: \home_url();
        return \add_query_arg( 'event_registration', $message, $url );
    }

    public function get_settings() {
        $defaults = [
            'timezone_mode' => 'site',
            'archive_hide_past' => true,
            'template_source' => 'theme',
            'registration_internal' => true,
            'admin_email' => '',
            'notify_admin' => true,
            'notify_user' => true,
            'confirmation_message' => __( "Thanks for signing up. We're excited to see you at the event!", 'anchor-schema' ),
            'max_guests' => 0,
            'register_button_label' => '',
            'register_button_color' => '#0f766e',
            'event_slug' => 'event',
            // Phase 6 — WooCommerce registration emails (used only when WC active).
            'wc_notify_customer'   => true,
            'wc_notify_organizer'  => true,
            'wc_customer_subject'  => __( 'Your event registration is confirmed', 'anchor-schema' ),
            'wc_customer_intro'    => __( 'Thank you for your order. Your registration is confirmed — the details are below.', 'anchor-schema' ),
            'wc_organizer_subject' => __( 'New event registration: {event_title}', 'anchor-schema' ),
            'organizer_email'      => '',
            // v1.1 lifecycle emails (spec §4.3). All non-WC: free + paid.
            'reminder_enabled'       => false,                 // opt-in
            'reminder_offsets'       => '7,1',                 // CSV whole days before start
            'reminder_subject'       => __( 'Reminder: {event_title} is coming up', 'anchor-schema' ),
            'reminder_intro'         => __( 'This is a friendly reminder that you are registered for {event_title} on {event_date}. We look forward to seeing you.', 'anchor-schema' ),
            'notify_cancellation'    => true,
            'cancellation_subject'   => __( 'Your registration for {event_title} has been cancelled', 'anchor-schema' ),
            'cancellation_intro'     => __( 'Your registration for {event_title} has been cancelled. If this is unexpected, please contact us.', 'anchor-schema' ),
            'organizer_roster_email' => false,
            'roster_auto_offset'     => 1,
            'roster_subject'         => __( 'Final roster for {event_title}', 'anchor-schema' ),
            'roster_intro'           => __( 'Here is the current confirmed roster for {event_title} on {event_date}.', 'anchor-schema' ),
            // Reserved/unused in MVP (per-attendee emails are deferred).
            'notify_attendee'      => false,
            // Email sender identity (applied as per-message headers on event emails).
            'email_from_name'        => '',
            'email_from_address'     => '',
            'email_reply_to_name'    => '',
            'email_reply_to_address' => '',
            'email_bcc'              => '',
        ];
        $settings = \get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }
        return \wp_parse_args( $settings, $defaults );
    }
}
