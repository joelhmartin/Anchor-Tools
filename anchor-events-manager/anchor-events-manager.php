<?php
namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

// Root-namespace theme template tags (anchor_event_label/anchor_event_labels).
// Must be a separate file: PHP forbids a bracketed `namespace { }` block in a
// file that already opened with an unbracketed `namespace Anchor\Events;`.
require_once __DIR__ . '/template-tags.php';

class Module {
    const CPT = 'event';

    /**
     * Base events-management capability on a site with no store (audit REG-D20).
     * Roster::CAP is kept as an alias of this for back-compat.
     */
    const CAP_BASE = 'edit_others_posts';

    /** Events-management capability once WooCommerce is active — the roster is PII. */
    const CAP_STORE = 'manage_woocommerce';
    const REG_CPT = 'anchor_event_reg';

    // Task 7 (COORD-D2): the only get_meta_schema() keys exposed over REST by
    // default. Everything else defaults to show_in_rest => false unless the
    // schema entry itself opts back in explicitly (array_merge in
    // register_meta() puts the per-key $schema AFTER this default, so an
    // explicit override always wins over this list).
    //
    // Because the per-key entry wins, listing a key here that also sets
    // 'show_in_rest' => false in get_meta_schema() does nothing at all. `type`
    // and `external_display_price` were both listed and both overridden, so
    // they were dead entries that read as "exposed" while the schema kept them
    // hidden — they are metabox/manager-form owned on purpose (see the
    // last-write-wins note on `type` in get_meta_schema()). They are removed
    // from this list rather than from their overrides: the overrides are the
    // intended behaviour. Anything added here must NOT carry its own
    // show_in_rest.
    const REST_PUBLIC_META = [ 'start_date', 'end_date', 'start_time', 'end_time', 'all_day', 'timezone', 'venue', 'address_city', 'address_state', 'address_country', 'status', 'price' ];
    const OPTION_KEY = 'anchor_events_settings';

    /**
     * Version of the derived-timestamp schema (`start_ts`/`end_ts`).
     *
     * Bumped whenever calculate_timestamps() would produce DIFFERENT rows for
     * unchanged authored dates, so backfill_timestamps() knows the stored rows
     * are stale and recomputes them. Every event carries the version it was
     * last computed under in `_anchor_event_ts_version`; the site-wide option
     * `anchor_events_ts_version` records the version the migration has finished.
     *
     * 1 — implicit/original: a date-only event ended at 00:00, because
     *     `end_time` defaulted to '' and end == start.
     * 2 — a date-only or end-time-less event ends at 23:59 on its end date.
     *     Version 1 rows must be recomputed or the past-event guard in
     *     Registrations::capacity_decision() closes such an event at midnight
     *     on the morning it runs.
     * 3 — both bounds are computed in the site's zone with seconds zeroed, and
     *     a `_anchor_event_timezone` row that merely restates the site's own
     *     gmt_offset ("UTC-6" on a -06:00 site) is deleted rather than kept:
     *     get_meta_defaults() used to MINT that string at read time and
     *     Occurrences::sync_shared_meta() wrote the invention down as real
     *     data (audit MODEL-D37). It is not a zone anyone chose, and
     *     DateTimeZone rejects it outright.
     */
    const TS_SCHEMA_VERSION = 3;

    /**
     * The upper bound of an OPEN-ENDED [events_list] date range —
     * 2100-01-01T00:00:00Z.
     *
     * A constant, not `strtotime('+5 years')` (audit MODEL-D11): the bound goes
     * into the meta_query that keys the listing transient, so a floating value
     * re-keyed the cache on every request.
     *
     * This stabilises the RANGE clause only. `build_visibility_clause()` still
     * embeds a raw `time()`, and that clause is added whenever `show_past` is
     * 'no' — the [events_list] DEFAULT — so most listings still churn their key
     * every second. Filed as NEW-D5; not this fix's scope.
     */
    const RANGE_OPEN_END_TS = 4102444800;

    /**
     * Version of the event-status VALUE schema (`_anchor_event_status`).
     *
     * Bumped whenever a stored status value is RENAMED, so
     * backfill_status_values() knows the stored rows spell a state under an
     * older name. The site-wide option `anchor_events_status_version` records
     * the version the migration has finished; there is no per-event stamp
     * because the migration's selection predicate is self-clearing (it looks
     * for the old value, and rewriting it removes the row from the window).
     *
     * 1 — 'draft' (which collided with WordPress's own post_status) became
     *     'undated' (MODEL-D19).
     */
    const STATUS_SCHEMA_VERSION = 1;

    /**
     * Statuses whose old spelling still exists in the wild, mapped to the
     * current one. Read through normalize_status(); rewritten on disk by
     * backfill_status_values().
     */
    const LEGACY_STATUS_ALIASES = [ 'draft' => 'undated' ];

    /** Per-event attendee questions (see get_registration_questions()). */
    const QUESTIONS_META = '_anchor_event_reg_questions';
    /**
     * Retired key registry (RENDER-D20). Kept only so the one-time cleanup in
     * cleanup_legacy_cache_registry() can name the row it deletes; nothing
     * writes it any more.
     */
    const CACHE_OPTION = 'anchor_events_cache_keys';

    /**
     * Monotonic listing-cache generation. Folded into every get_cached_ids()
     * transient key, so clear_caches() invalidates the whole group with one
     * option write instead of walking a registry of key names (RENDER-D20).
     * Orphaned transients from older generations expire on their own hour TTL.
     */
    const CACHE_VERSION_OPTION = 'anchor_events_cache_ver';
    const NONCE = 'anchor_event_meta_nonce';
    /**
     * How long a queued authoring notice (queue_group_notice()) waits for a
     * request that can render it. One redirect, not one session: long enough
     * for the save's own redirect or the next admin page load, short enough
     * that a notice can never surface against an unrelated later save.
     */
    const NOTICE_TTL = 60;
    /**
     * Per-user record of the DST warning being dismissed
     * (timezone_notice_html()). Stores the UTC offset it was dismissed FOR, so
     * a site moved to a different fixed offset asks again.
     */
    const TZ_NOTICE_DISMISSED_META = 'anchor_events_tz_notice_dismissed';
    /** Nonce action for that dismissal (maybe_dismiss_timezone_notice()). */
    const TZ_NOTICE_DISMISS_ACTION = 'anchor_events_dismiss_tz';
    const REG_NONCE = 'anchor_event_reg_nonce';

    /**
     * Task 3.1 — editable lifecycle-email types. Each has a per-event override
     * meta key (`_anchor_event_email_tpl_{type}`); REG-D12 retired the
     * never-written global `anchor_events_email_tpl_{type}` option tier that
     * used to sit between it and the default. Orientation found that ALL FOUR
     * currently render through the exact same shared shell in
     * build_registration_email_html() — including the roster digest, which
     * was hypothesized to differ but does not — so all four DEFAULT templates
     * are (for now) identical text; Task 3.2's builder can diverge them later.
     */
    const EMAIL_TEMPLATE_TYPES = [ 'confirmation', 'reminder', 'cancellation', 'roster' ];

    /**
     * The block tokens — regions of the email rendered by this class rather
     * than authored, so they always carry the stock literal colours and always
     * go through recolor_email_literals(). See build_registration_email_html().
     */
    const EMAIL_BLOCK_TOKENS = [
        'intro', 'header_image', 'greeting', 'guests_line', 'waitlist_notice',
        'detail_rows', 'seat_list', 'cta_button', 'cta_button_2',
        'preheader',
    ];

    /**
     * Total delivery attempts for one lifecycle email before the retry job is
     * abandoned with a log entry (audit REG-D5). The first send counts as
     * attempt 1, so this is two retries an hour apart, not three.
     */
    const MAX_EMAIL_ATTEMPTS = 3;

    /** Seats whose retry jobs one sweep will drain. Bounds an hourly cron run. */
    const EMAIL_RETRY_BATCH = 100;

    /**
     * How far ahead the hourly reminder sweep will ever look, in days
     * (audit REG-D36). The scan window is normally the largest reminder offset
     * in play; this is the ceiling on that, so one absurd per-event offset
     * cannot turn an hourly cron into a full-table scan of the calendar.
     */
    const REMINDER_HORIZON_DAYS = 366;

    /**
     * Event starts kept in a lifecycle-email marker map (audit MODEL-D16).
     * Keeping the recent ones is what makes "moved away and back again" not
     * re-send; a repeatedly rescheduled event still cannot grow meta for ever.
     */
    const MAX_MARKER_DATES = 10;

    private static $instance = null;
    private $assets_enqueued = false;

    /**
     * True only while a preview is rendering. Makes build_registration_email_html()
     * substitute preview_sample_scalars() for tokens the event has no value for,
     * and render the conditional regions that would otherwise be empty. Never
     * set on a send path.
     */
    private $preview_samples = false;

    /** Unsaved CTA label/url pairs supplied by a live preview request; see get_email_cta(). */
    private $preview_cta_override = null;

    /** Unsaved subject/intro supplied by a live preview request; see get_email_field(). */
    private $preview_field_override = null;

    /**
     * The event a send_html_email() call is currently in the middle of, so the
     * wp_mail_failed handler — which is handed nothing but a WP_Error — can name
     * the same subject the call site does (REG-D46). 0 outside a send.
     *
     * @var int
     */
    private $sending_event_id = 0;

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

    /** @var Event_Schema|null schema.org/Event JSON-LD data builder (Phase 4, Task 4.1; always loaded, read-only). */
    public $event_schema = null;

    /** @var int[] Seat ids queued for a cancellation email this request. */
    private $pending_cancellation_emails = [];

    /**
     * Task 3.2 — transient (never persisted) substitution for
     * resolve_email_template(), set only inside ajax_email_preview()'s
     * try/finally. Lets "Preview with real data" render the admin's
     * in-progress (unsaved) editor content through the exact same
     * build_registration_email_html() renderer real sends use, without
     * writing anything and without affecting any other request. Shape:
     * [ 'type' => string, 'html' => string ] | null.
     *
     * @var array{type:string,html:string}|null
     */
    private $preview_template_override = null;

    /**
     * Re-entrancy guard for persist_group_authoring()'s call into
     * Occurrences::reconcile() (Phase 2, Task 2.3). reconcile() creates/
     * updates/trashes CHILD event posts, each of which fires save_post_event
     * again; this static flag blocks a nested call from re-entering
     * persist_group_authoring() (and therefore reconcile()) even if some
     * other hook chain calls save_meta()/save_event_manager_fields() while a
     * reconcile() for this request is already in flight. The primary guard —
     * removing the save_post_event action for the duration of the
     * reconcile() call itself — lives in run_reconcile(); this is
     * defense-in-depth on top of that.
     *
     * @var bool
     */
    private static $reconciling = false;

    /**
     * Re-entrancy guard for retire_children_on_parent_trash() (Phase 2, Task
     * 2.3 FIX 2). Occurrences::retire_all_children() may itself call
     * wp_trash_post() on an unseated child, which re-fires the generic
     * `wp_trash_post` action for that child post id. A child is never itself
     * a group parent (is_group_parent() already guards on that), so the
     * re-entrant call is a no-op regardless — this static flag is
     * defense-in-depth, matching the self::$reconciling pattern above.
     *
     * @var bool
     */
    private static $retiring_children = false;

    /**
     * Re-entrancy guard for restore_children_on_parent_untrash() (audit
     * MODEL-D15), the mirror of self::$retiring_children above.
     * Occurrences::reconcile() calls wp_untrash_post() on a wanted date's
     * trashed occurrence, which re-fires the generic `untrashed_post` action
     * for that child post id. A child is never itself a group parent, so the
     * re-entrant call is a no-op regardless — this flag is defense-in-depth.
     *
     * @var bool
     */
    private static $restoring_children = false;

    public function __construct() {
        self::$instance = $this;

        // Always-on data layer (spec §3, approach B). WC-gated classes load in Phase 1.
        $dir = \plugin_dir_path( __FILE__ );
        require_once $dir . 'class-events-log.php';
        // The tri-state every sender and every status write answers with.
        require_once $dir . 'class-outcome.php';
        require_once $dir . 'class-registrations.php';
        require_once $dir . 'class-roster.php';
        require_once $dir . 'class-ticket-types.php';
        require_once $dir . 'class-series.php';
        require_once $dir . 'class-occurrences.php';
        require_once $dir . 'class-event-schema.php';
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
        // Event JSON-LD data builder (Phase 4, Task 4.1) — free + paid; pure
        // read-only data projection, no hooks of its own. Front-end emission
        // (wp_head) is a later task.
        $this->event_schema = new Event_Schema( $this );

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
        // REST/Gutenberg writes the six public date keys (REST_PUBLIC_META)
        // without going through either save path that maintains the derived
        // rows — save_meta() bails on the missing metabox nonce, and
        // transition_post_status fires from wp_update_post() BEFORE the REST
        // controller saves meta. rest_after_insert_{$post_type} is the hook
        // that runs after the meta write, so it is the only place the new
        // dates are visible.
        \add_action( 'rest_after_insert_' . self::CPT, [ $this, 'persist_after_rest_write' ], 10, 3 );
        // …and a REST WRITE response carries any notice still queued from the
        // author's previous metabox save (audit MODEL-D14) — see
        // attach_notices_to_rest_response() for why it is never this save's.
        \add_filter( 'rest_prepare_' . self::CPT, [ $this, 'attach_notices_to_rest_response' ], 10, 3 );

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
        // One-time start_ts/end_ts back-fill for legacy events (audit MODEL-D2).
        // admin_init, never init: it writes meta, so it must not run on an
        // ordinary front-end pageload (MODEL-D41). admin_init alone does not
        // make it privileged — admin-post.php fires it before auth, for the
        // nopriv handlers registered below — so the method itself is
        // capability-gated as well as flag-guarded and batched.
        \add_action( 'admin_init', [ $this, 'backfill_timestamps' ] );
        // Same shape, same reasons: capability-gated, flag-guarded, batched.
        \add_action( 'admin_init', [ $this, 'backfill_occurrence_labels' ] );
        // Same shape again: the 'draft' -> 'undated' status rename (MODEL-D19).
        \add_action( 'admin_init', [ $this, 'backfill_status_values' ] );
        \add_action( 'admin_init', [ $this, 'cleanup_legacy_cache_registry' ] );
        \add_action( 'admin_notices', [ $this, 'admin_notices' ] );
        \add_action( 'admin_notices', [ $this, 'maybe_render_timezone_notice' ] );
        \add_action( 'admin_init', [ $this, 'maybe_dismiss_timezone_notice' ] );

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

        // Task 3.2 — Emails builder metabox "Preview with real data". Admin-only
        // (edit_post-capable), no nopriv counterpart — never sends anything.
        \add_action( 'wp_ajax_anchor_events_email_preview', [ $this, 'ajax_email_preview' ] );

        \add_action( 'update_option_' . self::OPTION_KEY, [ $this, 'handle_settings_update' ], 10, 2 );
        // The site's timezone is an INPUT to every stored timestamp, so moving
        // it invalidates all of them. Both halves of the setting, and both the
        // add and update actions (a fresh install has no `timezone_string` row
        // until somebody picks one). See invalidate_stored_timestamps().
        foreach ( [ 'timezone_string', 'gmt_offset' ] as $tz_option ) {
            \add_action( 'update_option_' . $tz_option, [ $this, 'invalidate_stored_timestamps' ] );
            \add_action( 'add_option_' . $tz_option, [ $this, 'invalidate_stored_timestamps' ] );
        }
        \add_action( 'before_delete_post', [ $this, 'clear_caches_on_delete' ] );
        // …and its trash/untrash/unpublish twin. before_delete_post fires only
        // on PERMANENT deletion, so every soft change — the one authors
        // actually make — left both the listing ids and the capacity counts
        // cached against data that no longer exists (REG-D18 / RENDER-D19).
        \add_action( 'transition_post_status', [ $this, 'clear_caches_on_transition' ], 10, 3 );
        // Group-parent trash retirement (Phase 2, Task 2.3 FIX 2). wp_trash_post()
        // fires for every post type on this one generic action — never a
        // CPT-specific save hook — so a group parent's children are never left
        // orphaned whether the parent is trashed via the classic admin list,
        // the front-end manager form's delete handler, or any other caller of
        // wp_trash_post(). See retire_children_on_parent_trash()'s docblock.
        \add_action( 'wp_trash_post', [ $this, 'retire_children_on_parent_trash' ] );
        // …and its mirror (audit MODEL-D15). Restoring the parent from the
        // trash must undo the retirement, or the state is one-way: seated
        // children stay soft-closed, unseated ones stay trashed, and the
        // parent's page says "No dates currently scheduled" until somebody
        // knows to open and re-save it. wp_untrash_post() does fire save_post
        // (it restores the status through wp_update_post), but save_meta()
        // bails there without the metabox nonce, so `untrashed_post` is the
        // only hook that can drive the reconcile.
        \add_action( 'untrashed_post', [ $this, 'restore_children_on_parent_untrash' ] );
        // …and the status half of the same restore (NEW-D4). See
        // restore_untrashed_event_status().
        \add_filter( 'wp_untrash_post_status', [ $this, 'restore_untrashed_event_status' ], 10, 3 );

        // SEO: Add canonical URL for calendar month parameter pages
        \add_action( 'wp_head', [ $this, 'output_canonical_url' ], 1 );
        \add_filter( 'wpseo_canonical', [ $this, 'filter_yoast_canonical' ] );
        \add_filter( 'rank_math/frontend/canonical', [ $this, 'filter_yoast_canonical' ] );

        // Phase 4, Task 4.2: Event JSON-LD on single-event views (data built
        // by Event_Schema, Task 4.1). Priority 5 — well before the parent
        // Anchor Schema plugin's own output_active_schemas() (priority 99),
        // though ordering doesn't matter for de-dupe since that's decided by
        // reading post meta, not by output order.
        \add_action( 'wp_head', [ $this, 'output_event_schema' ], 5 );

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

        // A quick-edit publish never runs the meta box save, so an event could
        // reach 'publish' with no start_ts/end_ts row at all and drop out of
        // every listing (audit MODEL-D2). Write them here too — before the
        // manual-status bail, because visibility does not depend on status_mode.
        if ( $new_status === 'publish' && ! empty( $meta['start_date'] ) ) {
            $this->persist_timestamps( $post->ID, $meta );
        }

        $this->persist_auto_status( $post->ID, $meta );
    }

    /**
     * Recompute the derived rows after a REST write to an event (audit REST-D1).
     *
     * `start_date`, `end_date`, `start_time`, `end_time`, `all_day` and
     * `timezone` are all in REST_PUBLIC_META, so any client with `edit_post`
     * can move an event's dates through `/wp/v2/event/<id>` — and nothing
     * downstream recomputed `start_ts`/`end_ts`/`status` from them. The event
     * kept sorting, listing and (since the past-event guard in
     * Registrations::capacity_decision()) opening or closing on its OLD dates.
     *
     * Same calculators as every other save path, via persist_timestamps() and
     * persist_auto_status() — not a second implementation.
     *
     * Status is included deliberately: on an auto-mode event a REST-written
     * `_anchor_event_status` is recomputed from the dates and overwritten,
     * because in auto mode the dates own the status — a client that wants to
     * set it by hand has to switch the event to manual mode first.
     *
     * @param \WP_Post         $post
     * @param \WP_REST_Request $request  Unused; part of the hook signature.
     * @param bool             $creating Unused; part of the hook signature.
     */
    public function persist_after_rest_write( $post, $request = null, $creating = false ) {
        if ( ! $post instanceof \WP_Post || $post->post_type !== self::CPT ) {
            return;
        }

        $meta = $this->get_meta( $post->ID );
        if ( ! empty( $meta['start_date'] ) ) {
            $this->persist_timestamps( $post->ID, $meta );
        }
        $this->persist_auto_status( $post->ID, $meta );

        // The rows this just moved are what the listing caches were keyed on.
        $this->clear_caches();
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
            // persist_auto_status() is the single writer for the status row
            // (it is also what the save, transition and REST paths call), so
            // the sweep cannot drift from them. It owns the "write when the
            // row is ABSENT, not only when the value differs" rule that
            // MODEL-D18 is about, and it re-checks manual mode itself — the
            // query above already excludes manual events, but the guard is
            // what makes that exclusion a belt rather than the only strap.
            $this->persist_auto_status( $event_id, $this->get_meta( $event_id ) );
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

        // Retries first (audit REG-D5). A lifecycle email whose wp_mail()
        // returned false leaves a job on the seat; this is the only thing that
        // drains it, and it must run BEFORE the early return below because a
        // queued CANCELLATION retry is governed by `notify_cancellation`, not
        // by whether this site sends reminders or roster digests at all.
        //
        // Draining before the reminder pass also keeps a reminder retry from
        // being attempted twice in the same hour: the drain writes the sent
        // marker, and the pass below then sees the offset as already sent.
        $this->drain_email_retry_queue( $now );

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
        //
        // REG-D36 — bounded twice over. The query used to be every event that
        // has EVER set an override, with no date bound, loaded every hour; one
        // archived course with `reminder_offsets = 365` therefore widened the
        // main scan below to every event starting in the next year, and the
        // per-seat query then ran once per due offset per event. An override
        // only matters while the event it belongs to is still ahead of us, so
        // the scan asks only about future events — and never looks further
        // ahead than REMINDER_HORIZON_DAYS whatever an offset claims.
        if ( ! empty( $settings['reminder_enabled'] ) ) {
            $cap_ts          = $now + ( self::REMINDER_HORIZON_DAYS * DAY_IN_SECONDS );
            $override_events = \get_posts( [
                'post_type'      => self::CPT,
                'post_status'    => [ 'publish', 'future', 'private' ],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [
                    'relation' => 'AND',
                    [ 'key' => $this->meta_key( 'reminder_offsets' ), 'value' => '', 'compare' => '!=' ],
                    [ 'key' => $this->meta_key( 'start_ts' ), 'value' => [ $now, $cap_ts ], 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ],
                ],
            ] );
            foreach ( $override_events as $oid ) {
                foreach ( array_map( 'intval', explode( ',', (string) \get_post_meta( $oid, $this->meta_key( 'reminder_offsets' ), true ) ) ) as $d ) {
                    $max_global = max( $max_global, $d );
                }
            }
        }

        $max_global = min( max( 1, $max_global ), self::REMINDER_HORIZON_DAYS );
        $horizon    = $now + ( $max_global * DAY_IN_SECONDS );

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

            // A group parent is a container, never a registration target: its
            // seats live on the dates (audit REG-D2). compute_email_schedule()
            // already refuses parents, so without this the sweep and the
            // "Upcoming sends" panel disagreed about who gets reminded.
            if ( $this->occurrences->is_group_parent( $event_id ) ) {
                continue;
            }

            // Never remind attendees about a date that is off (audit MODEL-D17).
            // A soft-closed occurrence keeps its future start_ts and its roster,
            // so it matched the window and mailed "…is coming up" for a date
            // that had been cancelled.
            if ( 'cancelled' === $this->get_event_status( $event_id, $meta )
                || $this->occurrences->is_closed( $event_id ) ) {
                continue;
            }

            $start_ts = (int) ( $meta['start_ts'] ?? 0 );
            if ( $start_ts <= $now ) {
                continue; // already started
            }

            // --- Reminder pass ---
            if ( ! empty( $settings['reminder_enabled'] ) ) {
                $this->send_due_reminders( $event_id, $start_ts, $settings, $meta, $now );
            }

            // --- Scheduled roster pass (implemented in Task 4) ---
            $this->maybe_send_scheduled_roster( $event_id, $meta, $settings, $now );
        }
    }

    /**
     * Send ONE reminder per confirmed seat: the due offset closest to the
     * event that this seat has not been reminded about yet (audit REG-D3).
     *
     * The old loop sent every offset whose window had opened. With the
     * production offsets "7,1", a registration taken 12 hours before the
     * course matched `start-7d <= now` AND `start-1d <= now` on the very next
     * sweep and mailed that attendee twice in the same minute; switching
     * reminders on inside the last day did it to the whole roster at once.
     *
     * Two rules, together:
     *   1. Only the SMALLEST due unsent offset is sent. It is the reminder
     *      that actually describes the situation ("tomorrow", not "next
     *      week").
     *   2. Every larger due offset is marked as superseded (value 0) rather
     *      than left unsent, so it cannot fire an hour later and turn the
     *      double into a delayed double.
     *
     * The audit also floated gating on "the window opened within the last
     * sweep interval". That is deliberately NOT used as the send condition:
     * it silences a legitimately-due reminder whenever the registration
     * arrives after the window opened (the exact case above) or the hourly
     * cron misses a run — a reminder nobody gets is a worse failure than a
     * reminder that arrives a few hours into its window. The supersede rule
     * gets the same "never fire two at once" guarantee without dropping mail.
     *
     * @param int   $event_id
     * @param int   $start_ts Event start the markers are keyed to.
     * @param array $settings
     * @param array $meta     Pre-loaded event meta.
     * @param int   $now
     */
    private function send_due_reminders( $event_id, $start_ts, array $settings, array $meta, $now ) {
        // An event whose reminders are switched off is asked nothing further:
        // no seat query, and — the point — no markers. Deciding it per seat
        // inside the loop would record every due offset as accounted for, so
        // switching reminders back ON mid-window would silently send nothing
        // (audit WOO-D14: check the switch BEFORE the sender, not after).
        if ( ! $this->is_email_enabled( $event_id, 'reminder' ) ) {
            return;
        }

        $due = [];
        foreach ( $this->effective_offsets( $event_id, $settings, $meta ) as $offset ) {
            if ( ( $start_ts - $offset * DAY_IN_SECONDS ) <= $now && $now < $start_ts ) {
                $due[] = (int) $offset;
            }
        }
        if ( empty( $due ) ) {
            return;
        }
        \sort( $due ); // ascending — the offset nearest the event first.

        $seats = $this->registrations->query_seats( [
            'event_id' => $event_id,
            'status'   => Registrations::STATUS_CONFIRMED,
            'per_page' => -1,
        ] );

        foreach ( $seats['items'] as $seat ) {
            // A seat whose reminder is already queued for retry belongs to the
            // drain, which ran at the top of this sweep. Attempting it again
            // here would burn a second attempt in the same hour.
            $job = \get_post_meta( $seat['id'], Registrations::META_EMAIL_RETRY, true );
            if ( \is_array( $job ) && ( $job['type'] ?? '' ) === 'reminder' ) {
                continue;
            }

            $sent_map = $this->reminder_markers( $seat['id'], $start_ts, true );
            $target   = null;
            foreach ( $due as $offset ) {
                if ( isset( $sent_map[ $offset ] ) ) {
                    continue; // sent, or superseded.
                }
                if ( ! \apply_filters( 'anchor_events_should_send_reminder', true, $seat, $offset ) ) {
                    // Blocked, and left UNMARKED on purpose so the filter can
                    // allow it later. Fall through to the next-widest due
                    // offset rather than silencing the seat entirely: the old
                    // loop would still have sent that one.
                    continue;
                }
                $target = $offset;
                break;
            }
            if ( null === $target ) {
                continue; // every due offset already accounted for, or blocked.
            }

            $result = $this->deliver_reminder( $seat, $event_id, $target, $start_ts, $settings );

            // A skip is something no retry and no later sweep can fix (this
            // seat has no address). Record the offset as superseded (0) rather
            // than leaving it unsent: an unmarked offset is re-attempted by
            // every sweep for the rest of the window. A FAILURE is different —
            // it owns a retry job, so it stays unmarked (audit REG-D5).
            if ( $result->is_skipped() ) {
                $this->mark_reminder_sent( $seat['id'], $start_ts, $target, 0 );
            }

            // Whether or not that send succeeded, the wider windows are stale.
            foreach ( $due as $offset ) {
                if ( $offset > $target && ! isset( $sent_map[ $offset ] ) ) {
                    $this->mark_reminder_sent( $seat['id'], $start_ts, $offset, 0 );
                }
            }
        }
    }

    /**
     * Send one reminder and record it. The single place a reminder send is
     * turned into a marker, shared by the sweep and the retry drain so the two
     * can never disagree about what "sent" means.
     *
     * @param array $seat     Seat DTO.
     * @param int   $event_id
     * @param int   $offset   Days-before-start offset being sent.
     * @param int   $start_ts Event start the marker is keyed to.
     * @param array $settings
     * @return Outcome
     */
    private function deliver_reminder( array $seat, $event_id, $offset, $start_ts, array $settings ) {
        $result = $this->send_reminder_email( $seat, $event_id, $offset, $settings );
        if ( $result->is_sent() ) {
            // REG-D29 — this used to also write _anchor_event_attendee_notified.
            // Nothing ever read it, and its name claimed a general "the attendee
            // has been notified" fact that only a reminder could set, so a later
            // reader would have taken it to mean the confirmation went out. The
            // reminders_sent map below is the record.
            $this->mark_reminder_sent( $seat['id'], $start_ts, $offset, \time() );
        }
        return $result;
    }

    /* ---------------------------------------------------------------------
     * Lifecycle-email markers (audit MODEL-D16 / REG-D42)
     *
     * Both markers are keyed by the start_ts they were written ABOUT, so a
     * rescheduled event re-arms on its own — no clear step in the save path to
     * forget, and moving an event BACK onto a date it already mailed about
     * does not mail about it twice.
     * ------------------------------------------------------------------- */

    /**
     * Normalize a stored marker map to `[ start_ts => [ key => value ] ]`.
     *
     * A pre-upgrade seat holds the flat `[ offset => sent_at ]` shape with no
     * date attached. The only defensible reading of it is "these were sent
     * about the date this event is on now" — treating it as unsent would mail
     * the whole roster a second time on the upgrade sweep.
     *
     * @param mixed $raw      Stored meta value.
     * @param int   $start_ts Current event start.
     * @return array{0:array,1:bool} [ normalized map, whether it differed from $raw ]
     */
    private function normalize_marker_map( $raw, $start_ts ) {
        if ( ! \is_array( $raw ) || empty( $raw ) ) {
            return [ [], false ];
        }
        $keyed  = [];
        $legacy = false;
        foreach ( $raw as $key => $value ) {
            if ( \is_array( $value ) ) {
                $keyed[ (int) $key ] = $value;
                continue;
            }
            $legacy = true;
            $keyed[ (int) $start_ts ][ (int) $key ] = $value;
        }
        return [ $keyed, $legacy ];
    }

    /**
     * A seat's reminder markers for one event start.
     *
     * @param int  $seat_id
     * @param int  $start_ts
     * @param bool $migrate  Rewrite a legacy flat map in place. The sweep does;
     *                       compute_email_schedule() must not — it is
     *                       documented as a read with no side effects.
     * @return array [ offset => sent_at ]; 0 means "superseded, never sent".
     */
    private function reminder_markers( $seat_id, $start_ts, $migrate = false ) {
        $raw = \get_post_meta( $seat_id, Registrations::META_REMINDERS_SENT, true );
        list( $keyed, $legacy ) = $this->normalize_marker_map( $raw, $start_ts );
        if ( $legacy && $migrate ) {
            \update_post_meta( $seat_id, Registrations::META_REMINDERS_SENT, $keyed );
        }
        return isset( $keyed[ (int) $start_ts ] ) ? $keyed[ (int) $start_ts ] : [];
    }

    /**
     * Record one reminder marker.
     *
     * @param int $seat_id
     * @param int $start_ts
     * @param int $offset
     * @param int $sent_at  Send time, or 0 for "superseded by a nearer offset".
     */
    private function mark_reminder_sent( $seat_id, $start_ts, $offset, $sent_at ) {
        $raw = \get_post_meta( $seat_id, Registrations::META_REMINDERS_SENT, true );
        list( $keyed, ) = $this->normalize_marker_map( $raw, $start_ts );
        $keyed[ (int) $start_ts ][ (int) $offset ] = (int) $sent_at;
        \update_post_meta( $seat_id, Registrations::META_REMINDERS_SENT, $this->prune_marker_map( $keyed, $start_ts ) );
    }

    /**
     * An event's roster-digest markers, keyed by start_ts.
     *
     * @param int  $event_id
     * @param int  $start_ts
     * @param bool $migrate  Rewrite a legacy bare timestamp in place.
     * @return array [ start_ts => sent_at ]
     */
    private function roster_sent_markers( $event_id, $start_ts, $migrate = false ) {
        $raw = \get_post_meta( $event_id, $this->meta_key( 'roster_sent' ), true );
        if ( ! \is_array( $raw ) ) {
            // Pre-upgrade shape: a bare timestamp for whatever date the event
            // was on when the digest went out. Read it as the current date.
            $ts     = (int) $raw;
            $keyed  = $ts > 0 ? [ (int) $start_ts => $ts ] : [];
            $legacy = $ts > 0;
        } else {
            $keyed  = [];
            $legacy = false;
            foreach ( $raw as $key => $value ) {
                $keyed[ (int) $key ] = (int) $value;
            }
        }
        if ( $legacy && $migrate ) {
            \update_post_meta( $event_id, $this->meta_key( 'roster_sent' ), $keyed );
        }
        return $keyed;
    }

    /**
     * Keep marker maps from growing without bound on a heavily rescheduled
     * event: the most recent starts are the only ones a sweep can ever match
     * again (the sweep only looks at events whose start_ts is in the future).
     *
     * $keep_ts is retained unconditionally. It is the date the caller has just
     * written a marker for, and dropping it would be worse than any amount of
     * meta growth: an event moved EARLIER than ten already-stored dates is the
     * oldest key in the map, so a plain "keep the newest ten" would delete the
     * marker on the way out and re-mail the whole roster on the next sweep.
     *
     * @param array $keyed
     * @param int   $keep_ts Start the caller just wrote; 0 for none.
     * @return array
     */
    private function prune_marker_map( array $keyed, $keep_ts = 0 ) {
        if ( \count( $keyed ) <= self::MAX_MARKER_DATES ) {
            return $keyed;
        }
        $keep_ts = (int) $keep_ts;
        $kept    = [];
        if ( $keep_ts > 0 && \array_key_exists( $keep_ts, $keyed ) ) {
            $kept[ $keep_ts ] = $keyed[ $keep_ts ];
            unset( $keyed[ $keep_ts ] );
        }
        \krsort( $keyed, SORT_NUMERIC );
        foreach ( $keyed as $ts => $value ) {
            if ( \count( $kept ) >= self::MAX_MARKER_DATES ) {
                break;
            }
            $kept[ $ts ] = $value;
        }
        \krsort( $kept, SORT_NUMERIC );
        return $kept;
    }

    /* ---------------------------------------------------------------------
     * Lifecycle-email retry queue (audit REG-D5)
     * ------------------------------------------------------------------- */

    /**
     * Queue (or re-queue) a lifecycle email whose wp_mail() returned false.
     *
     * Before this, a cancellation email that hit an SMTP blip was simply gone:
     * flush_cancellation_emails() had already emptied its in-memory queue, the
     * "emailed" marker was never written, and only a fresh live→terminal
     * transition could re-enqueue — which a seat that is already terminal can
     * never make.
     *
     * A seat holds one job at a time, so the attempt count is per JOB TYPE, not
     * per seat: without that reset, a confirmed seat carrying a reminder job at
     * two spent attempts would have its first failed cancellation email treated
     * as the third and abandoned on the spot.
     *
     * @param int   $seat_id
     * @param array $job {type, plus whatever the sender needs to try again}.
     */
    private function queue_email_retry( $seat_id, array $job ) {
        $seat_id   = (int) $seat_id;
        $type      = (string) ( $job['type'] ?? '' );
        $existing  = \get_post_meta( $seat_id, Registrations::META_EMAIL_RETRY, true );
        $same_type = \is_array( $existing ) && (string) ( $existing['type'] ?? '' ) === $type;
        $attempts  = ( $same_type ? (int) ( $existing['attempts'] ?? 0 ) : 0 ) + 1;

        if ( $attempts >= self::MAX_EMAIL_ATTEMPTS ) {
            \delete_post_meta( $seat_id, Registrations::META_EMAIL_RETRY );

            // An abandoned REMINDER has to be recorded as accounted for, not
            // merely dropped: the sweep skips a seat only while a reminder job
            // exists, so an unmarked offset would be picked up by the very same
            // sweep's reminder pass, sent, and re-queued at one attempt — a
            // permanently failing mailer would cycle for ever and fill the
            // capped error log. Marking it superseded (0) ends it for good.
            if ( 'reminder' === $type && (int) ( $job['start_ts'] ?? 0 ) > 0 && (int) ( $job['offset'] ?? 0 ) > 0 ) {
                $this->mark_reminder_sent( $seat_id, (int) $job['start_ts'], (int) $job['offset'], 0 );
            }

            Events_Log::error( 'email_retry_abandoned', [
                'seat'     => $seat_id,
                'type'     => $type,
                'attempts' => $attempts,
            ] );
            return;
        }

        $job['attempts'] = $attempts;
        $job['next_at']  = \time() + HOUR_IN_SECONDS;
        \update_post_meta( $seat_id, Registrations::META_EMAIL_RETRY, $job );
    }

    /**
     * Drop a seat's retry job — it was delivered, or it no longer applies.
     *
     * @param int    $seat_id
     * @param string $type Clear only a job of this type. A sender must pass its
     *                     own type: one seat holds one job, so an unqualified
     *                     delete would let a delivered reminder throw away a
     *                     cancellation still waiting to be retried.
     */
    private function clear_email_retry( $seat_id, $type = '' ) {
        $seat_id = (int) $seat_id;
        if ( '' !== $type ) {
            $job = \get_post_meta( $seat_id, Registrations::META_EMAIL_RETRY, true );
            if ( \is_array( $job ) && (string) ( $job['type'] ?? '' ) !== $type ) {
                return; // someone else's job
            }
        }
        \delete_post_meta( $seat_id, Registrations::META_EMAIL_RETRY );
    }

    /**
     * Re-attempt every lifecycle email whose retry is due. Called at the top
     * of the hourly sweep, before its own sends.
     *
     * @param int|null $now
     */
    public function drain_email_retry_queue( $now = null ) {
        $now      = null === $now ? \time() : (int) $now;
        $seat_ids = \get_posts( [
            'post_type'      => self::REG_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => self::EMAIL_RETRY_BATCH,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [
                [ 'key' => Registrations::META_EMAIL_RETRY, 'compare' => 'EXISTS' ],
            ],
        ] );

        foreach ( $seat_ids as $seat_id ) {
            $job = \get_post_meta( $seat_id, Registrations::META_EMAIL_RETRY, true );
            if ( ! \is_array( $job ) || empty( $job['type'] ) ) {
                $this->clear_email_retry( $seat_id );
                continue;
            }
            if ( (int) ( $job['next_at'] ?? 0 ) > $now ) {
                continue; // not due yet
            }

            // Both senders own their own marker + retry bookkeeping, so a
            // success clears the job and a mail failure re-queues it with one
            // more attempt spent.
            if ( 'cancellation' === $job['type'] ) {
                $this->send_cancellation_email( $seat_id );
            } elseif ( 'reminder' === $job['type'] ) {
                $this->retry_reminder( (int) $seat_id, $job, $now );
            } else {
                $this->clear_email_retry( $seat_id );
                continue;
            }

            // A job whose attempt count did not move is one the sender never
            // got as far as mailing: the email type is switched off, the seat
            // has no address, the record is gone. An hourly retry cannot
            // change any of those, so retire it rather than let it sit in the
            // queue being re-read for ever.
            $after = \get_post_meta( $seat_id, Registrations::META_EMAIL_RETRY, true );
            if ( \is_array( $after ) && (int) ( $after['attempts'] ?? 0 ) === (int) ( $job['attempts'] ?? 0 ) ) {
                $this->clear_email_retry( $seat_id, (string) $job['type'] );
                Events_Log::error( 'email_retry_undeliverable', [
                    'seat' => (int) $seat_id,
                    'type' => (string) $job['type'],
                ] );
            }
        }
    }

    /**
     * One queued reminder retry. Abandons the job (without sending) when the
     * reminder no longer applies — the event has started, been cancelled, or
     * been rescheduled away from the date the job was queued for.
     *
     * @param int   $seat_id
     * @param array $job
     * @param int   $now
     */
    private function retry_reminder( $seat_id, array $job, $now ) {
        $seat     = $this->registrations->get_seat( $seat_id );
        $event_id = (int) ( $job['event_id'] ?? 0 );
        $offset   = (int) ( $job['offset'] ?? 0 );
        if ( ! $seat || $seat['status'] !== Registrations::STATUS_CONFIRMED || $event_id <= 0 || $offset <= 0 ) {
            $this->clear_email_retry( $seat_id, 'reminder' );
            return;
        }
        $meta     = $this->get_meta( $event_id );
        $start_ts = (int) ( $meta['start_ts'] ?? 0 );
        if ( $start_ts <= $now
            || $start_ts !== (int) ( $job['start_ts'] ?? 0 )
            || 'cancelled' === $this->get_event_status( $event_id, $meta )
            || $this->occurrences->is_closed( $event_id ) ) {
            $this->clear_email_retry( $seat_id, 'reminder' );
            return;
        }
        $this->deliver_reminder( $seat, $event_id, $offset, $start_ts, $this->get_settings() );
    }

    /**
     * Send a pre-event reminder email to a single confirmed seat.
     *
     * @param array      $seat     Seat DTO from query_seats().
     * @param int        $event_id
     * @param int        $offset   Days-before-start offset being sent.
     * @param array|null $settings Pre-resolved settings; loaded if not supplied.
     * @return Outcome sent | skipped (disabled, no_address) | failed (wp_mail).
     */
    public function send_reminder_email( array $seat, $event_id, $offset, $settings = null ) {
        // REG-D40 — three different refusals used to leave the same silence.
        // The reason string tells the CALLER them apart; these codes tell the
        // OPERATOR, who otherwise reads an unsent reminder as a mail failure.
        // 'disabled' is deliberately not logged: it is the organizer's own
        // setting, not a defect (see Outcome), and logging it would fill the
        // error log with every seat of every event that switched reminders off.
        if ( ! $this->is_email_enabled( $event_id, 'reminder' ) ) {
            return Outcome::skipped( 'disabled' );
        }
        if ( empty( $seat['email'] ) ) {
            Events_Log::error( 'reminder_no_address', [
                'event' => (int) $event_id,
                'seat'  => (int) ( $seat['id'] ?? 0 ),
            ] );
            return Outcome::skipped( 'no_address' );
        }
        if ( ! \is_array( $settings ) ) {
            $settings = $this->get_settings();
        }
        $tokens   = $this->email_tokens( [ 'event_id' => (int) $event_id, 'seat' => $seat ] );
        $subject  = $this->expand_email_tokens( $this->get_email_field( $event_id, 'reminder', 'subject', $settings['reminder_subject'] ), $tokens );
        $intro    = $this->expand_email_tokens( $this->get_email_field( $event_id, 'reminder', 'intro', $settings['reminder_intro'] ), $tokens );

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
            'type'          => 'reminder',
        ];
        $html = $this->build_registration_email_html( $ctx );
        $sent = $this->send_html_email( (string) $seat['email'], $subject, $html, [], $event_id );

        // Retry bookkeeping lives at the point of the actual send (audit
        // REG-D5) so the pre-flight bails above — reminders off for this
        // event, no address on the seat — never burn an attempt on something
        // no retry could fix.
        $seat_id = (int) ( $seat['id'] ?? 0 );
        if ( $seat_id > 0 ) {
            if ( $sent ) {
                $this->clear_email_retry( $seat_id, 'reminder' );
            } else {
                $this->queue_email_retry( $seat_id, [
                    'type'     => 'reminder',
                    'event_id' => (int) $event_id,
                    'offset'   => (int) $offset,
                    'start_ts' => (int) ( $this->get_meta( (int) $event_id )['start_ts'] ?? 0 ),
                ] );
            }
        }
        // A delivery failure is NOT logged here: send_html_email() already
        // records email_send_returned_false for it. What was missing was a
        // record of the refusals ABOVE, which never reached a send at all.
        return Outcome::from_bool( $sent, 'wp_mail' );
    }

    /**
     * Build + send the organizer roster digest (confirmed attendees + counts).
     *
     * @param int $event_id
     * @return Outcome sent | skipped (disabled) | failed (invalid_event, no_address, wp_mail).
     */
    public function send_roster_email( $event_id ) {
        if ( ! $this->is_email_enabled( $event_id, 'roster' ) ) {
            return Outcome::skipped( 'disabled' );
        }
        $event_id = (int) $event_id;
        if ( \get_post_type( $event_id ) !== self::CPT ) {
            // REG-D40 — each refusal names itself, so "the roster never arrived"
            // can be told apart from "the roster bounced" without guesswork.
            Events_Log::error( 'roster_invalid_event', [ 'event' => $event_id ] );
            return Outcome::failed( 'invalid_event' );
        }
        $settings = $this->get_settings();
        $to       = $this->resolve_organizer_email( $event_id, $settings );
        if ( $to === '' ) {
            Events_Log::error( 'roster_no_address', [ 'event' => $event_id ] );
            return Outcome::failed( 'no_address' );
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
        $subject = $this->expand_email_tokens( $this->get_email_field( $event_id, 'roster', 'subject', $settings['roster_subject'] ), $tokens );
        $intro   = $this->expand_email_tokens( $this->get_email_field( $event_id, 'roster', 'intro', $settings['roster_intro'] ), $tokens );

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
            'type'          => 'roster',
        ];
        $html = $this->build_registration_email_html( $ctx );
        // Delivery failure is send_html_email()'s to log (email_send_returned_false).
        return Outcome::from_bool( $this->send_html_email( $to, $subject, $html, [], $event_id ), 'wp_mail' );
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
        // Keyed by the start_ts the digest was sent ABOUT (audit REG-D42): the
        // marker used to be a bare "sent" timestamp, so a course postponed by
        // three weeks never earned the organizer a roster for the date they
        // actually needed — and the Upcoming Sends panel reported it as Sent.
        $markers = $this->roster_sent_markers( $event_id, $start_ts, true );
        if ( (int) ( $markers[ $start_ts ] ?? 0 ) > 0 ) {
            return; // already sent for THIS date
        }
        if ( $this->send_roster_email( $event_id )->is_sent() ) {
            $markers[ $start_ts ] = $now;
            \update_post_meta( $event_id, $this->meta_key( 'roster_sent' ), $this->prune_marker_map( $markers, $start_ts ) );
        }
    }

    /**
     * Task 3.3 — read-only "upcoming sends" schedule, computed on the fly from
     * the exact same inputs run_reminder_sweep()/maybe_send_scheduled_roster()
     * use (effective_offsets(), the settings flags, the per-seat
     * `_anchor_event_reminders_sent` markers, and the per-event `roster_sent`
     * marker). NO new storage, NO send/reschedule side effects — this only
     * reads state and reports what the sweep already has done / will do.
     *
     * Return shape:
     * [
     *   'notice' => ''|'invalid'|'group_parent'|'disabled'|'no_start',
     *   'rows'   => [
     *     [
     *       'type'         => 'reminder'|'roster',
     *       'offset_days'  => int,
     *       'scheduled_ts' => int,        // start_ts - offset_days*DAY_IN_SECONDS
     *       'recipient'    => string,     // human-readable recipient description
     *       'sent_count'   => int,        // reminders: seats w/ the offset marker; roster: 0|1
     *       'total_count'  => int,        // reminders: confirmed seats; roster: 1
     *       'state'        => 'sent'|'partial'|'scheduled'|'past',
     *     ],
     *     ...
     *   ],
     * ]
     * Rows are ordered by scheduled_ts ascending.
     *
     * Grouped events: a group PARENT never itself takes registrations — its
     * children (each a full event post with its own start_ts + seats) are
     * what the sweep actually processes. Rather than aggregate every child's
     * schedule into one (more moving parts, easy to get subtly wrong), the
     * parent gets an explicit 'group_parent' notice pointing at the per-date
     * children; a child computes its own schedule exactly like a standalone
     * event.
     *
     * @param int $event_id
     * @return array{notice:string,rows:array}
     */
    public function compute_email_schedule( int $event_id ): array {
        $event_id = (int) $event_id;
        $result   = [ 'notice' => '', 'rows' => [] ];

        if ( $event_id <= 0 || \get_post_type( $event_id ) !== self::CPT ) {
            $result['notice'] = 'invalid';
            return $result;
        }

        if ( $this->occurrences->is_group_parent( $event_id ) ) {
            $result['notice'] = 'group_parent';
            return $result;
        }

        $settings     = $this->get_settings();
        $reminders_on = ! empty( $settings['reminder_enabled'] );
        $roster_on    = ! empty( $settings['organizer_roster_email'] );

        if ( ! $reminders_on && ! $roster_on ) {
            $result['notice'] = 'disabled';
            return $result;
        }

        $meta     = $this->get_meta( $event_id );
        $start_ts = (int) ( $meta['start_ts'] ?? 0 );
        if ( $start_ts <= 0 ) {
            $result['notice'] = 'no_start';
            return $result;
        }

        $now  = \time();
        $rows = [];

        if ( $reminders_on ) {
            // Mirrors run_reminder_sweep()'s own seat query exactly (same
            // status + per_page) so "confirmed seats" here can never drift
            // from what the sweep would actually count/notify.
            $seats = $this->registrations->query_seats( [
                'event_id' => $event_id,
                'status'   => \Anchor\Events\Registrations::STATUS_CONFIRMED,
                'per_page' => -1,
            ] );
            $total = \count( $seats['items'] );

            foreach ( $this->effective_offsets( $event_id, $settings, $meta ) as $offset ) {
                $scheduled_ts = $start_ts - ( $offset * DAY_IN_SECONDS );
                $sent_count   = 0;
                foreach ( $seats['items'] as $seat ) {
                    // Markers are read, never migrated, here — this method is
                    // documented as having no side effects. A 0 marker means
                    // the offset was superseded by a nearer reminder (REG-D3)
                    // and was never actually sent, so it must not be counted:
                    // the row then reports 'past', which is the truth.
                    $sent_map = $this->reminder_markers( $seat['id'], $start_ts );
                    if ( ! empty( $sent_map[ $offset ] ) ) {
                        $sent_count++;
                    }
                }
                $rows[] = [
                    'type'         => 'reminder',
                    'offset_days'  => $offset,
                    'scheduled_ts' => $scheduled_ts,
                    'recipient'    => \sprintf(
                        /* translators: %d: number of confirmed attendees. */
                        \_n( '%d confirmed attendee', '%d confirmed attendees', $total, 'anchor-schema' ),
                        $total
                    ),
                    'sent_count'   => $sent_count,
                    'total_count'  => $total,
                    'state'        => $this->schedule_row_state( $scheduled_ts, $now, $sent_count, $total ),
                ];
            }
        }

        if ( $roster_on ) {
            $offset       = (int) $settings['roster_auto_offset'];
            $scheduled_ts = $start_ts - ( $offset * DAY_IN_SECONDS );
            // Sent FOR THIS DATE (audit REG-D42) — a digest sent about the
            // date this event has since moved off is not this row's send.
            $already_sent = (int) ( $this->roster_sent_markers( $event_id, $start_ts )[ $start_ts ] ?? 0 ) > 0;
            $email        = $this->resolve_organizer_email( $event_id, $settings );

            $rows[] = [
                'type'         => 'roster',
                'offset_days'  => $offset,
                'scheduled_ts' => $scheduled_ts,
                'recipient'    => $email !== '' ? $email : \__( 'Organizer', 'anchor-schema' ),
                'sent_count'   => $already_sent ? 1 : 0,
                'total_count'  => 1,
                'state'        => $already_sent ? 'sent' : ( $now < $scheduled_ts ? 'scheduled' : 'past' ),
            ];
        }

        \usort( $rows, function ( $a, $b ) {
            return $a['scheduled_ts'] <=> $b['scheduled_ts'];
        } );

        $result['rows'] = $rows;
        return $result;
    }

    /**
     * State for one compute_email_schedule() reminder row.
     * - 'sent'      : every confirmed seat has the offset marker (total>0).
     * - 'partial'   : some, but not all, confirmed seats have it — honestly
     *                 surfaces mixed state instead of collapsing it into
     *                 either "sent" or "scheduled".
     * - 'scheduled' : none sent yet, send window still in the future.
     * - 'past'      : none sent yet, send window already passed (e.g.
     *                 reminders were enabled after the window elapsed).
     *
     * @param int $scheduled_ts
     * @param int $now
     * @param int $sent_count
     * @param int $total_count
     * @return string
     */
    private function schedule_row_state( $scheduled_ts, $now, $sent_count, $total_count ) {
        if ( $total_count > 0 && $sent_count === $total_count ) {
            return 'sent';
        }
        if ( $sent_count > 0 ) {
            return 'partial';
        }
        return $now < $scheduled_ts ? 'scheduled' : 'past';
    }

    /**
     * wp_mail_failed handler (bug #5) — logs the failure to the events error log.
     *
     * @param \WP_Error $error
     */
    public function capture_mail_failure( $error ) {
        if ( \is_wp_error( $error ) ) {
            Events_Log::error( 'email_failed', [
                // REG-D46 — name the event the send belonged to, so failures for
                // two different events stay two rows instead of collapsing into
                // one counted row keyed on the code alone.
                'event'   => (int) $this->sending_event_id,
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
    public function send_html_email( $to, $subject, $html, $headers = [], $event_id = 0 ) {
        if ( empty( $headers ) ) {
            // Apply the sender identity: the event's own where it sets one,
            // the site-wide setting otherwise (From / Reply-To / Cc / Bcc).
            $headers = $this->email_headers( [ 'Content-Type: text/html; charset=UTF-8' ], $event_id );
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
        // REG-D46 — carry the event through wp_mail so capture_mail_failure()
        // (a wp_mail_failed handler, which is handed nothing but the WP_Error)
        // can name the same subject this call site does.
        $previous_event         = $this->sending_event_id;
        $this->sending_event_id = (int) $event_id;
        try {
            $sent = \wp_mail( $to, $subject, $html, $headers );
        } finally {
            $this->sending_event_id = $previous_event;
        }
        if ( ! $sent ) {
            Events_Log::error( 'email_send_returned_false', [
                'event'   => (int) $event_id,
                'to'      => $to,
                'subject' => $subject,
            ] );
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
    public function email_headers( array $extra = [], $event_id = 0 ) {
        $settings = $this->get_settings();
        $headers  = $extra;

        // Per-event overrides beat the site-wide setting, field by field: an
        // event can change only its Reply-To and keep the site's From. Empty
        // means "not overridden", which is why each is read with a fallback
        // rather than merged as a block.
        $ev = function ( $key, $default ) use ( $event_id ) {
            if ( ! $event_id ) {
                return $default;
            }
            $value = \get_post_meta( (int) $event_id, '_anchor_event_' . $key, true );
            return ( \is_string( $value ) && \trim( $value ) !== '' ) ? $value : $default;
        };

        $from_email = \sanitize_email( $ev( 'email_from_address', $settings['email_from_address'] ?? '' ) );
        if ( $from_email ) {
            $from_name = \sanitize_text_field( $ev( 'email_from_name', $settings['email_from_name'] ?? '' ) );
            $headers[] = $from_name !== ''
                ? sprintf( 'From: %s <%s>', $this->encode_email_name( $from_name ), $from_email )
                : 'From: ' . $from_email;
        }

        $reply_email = \sanitize_email( $ev( 'email_reply_to_address', $settings['email_reply_to_address'] ?? '' ) );
        if ( $reply_email ) {
            $reply_name = \sanitize_text_field( $ev( 'email_reply_to_name', $settings['email_reply_to_name'] ?? '' ) );
            $headers[] = $reply_name !== ''
                ? sprintf( 'Reply-To: %s <%s>', $this->encode_email_name( $reply_name ), $reply_email )
                : 'Reply-To: ' . $reply_email;
        }

        // Cc and Bcc take a list. The event's list ADDS to the site-wide one
        // rather than replacing it — a site-wide Bcc is normally an archive or
        // a compliance copy, and an event quietly dropping it would be a
        // surprise nobody asked for.
        foreach ( [ 'Cc' => 'email_cc', 'Bcc' => 'email_bcc' ] as $header => $key ) {
            $list = \array_merge(
                $this->email_address_list( $settings[ $key ] ?? '' ),
                $this->email_address_list( $event_id ? \get_post_meta( (int) $event_id, '_anchor_event_' . $key, true ) : '' )
            );
            foreach ( \array_unique( $list ) as $address ) {
                $headers[] = $header . ': ' . $address;
            }
        }

        return $headers;
    }

    /**
     * Split a comma/newline separated address list into valid addresses.
     *
     * Anything that is not an address is dropped rather than passed through:
     * a malformed entry in a Cc header can make the whole message bounce, and
     * one typo should not cost an event its confirmation emails.
     */
    public function email_address_list( $raw ) {
        $parts = \preg_split( '/[,;\r\n]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
        $out   = [];
        foreach ( (array) $parts as $part ) {
            $address = \sanitize_email( \trim( $part ) );
            if ( $address !== '' && \is_email( $address ) ) {
                $out[] = $address;
            }
        }
        return $out;
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
            // Resolved against the event's current questions (REG-D10), so a
            // label-keyed legacy answer exports once, under the heading the
            // organizer sees everywhere else.
            $questions  = $this->get_registration_questions( $event_id );
            $reg_fields = $this->resolve_registration_answers(
                $event_id,
                \get_post_meta( $seat_id, '_anchor_event_reg_fields', true ),
                $questions
            );
            foreach ( $reg_fields as $field_key => $field_value ) {
                $value  = \is_scalar( $field_value ) ? (string) $field_value : \wp_json_encode( $field_value );
                $data[] = [
                    'name'  => $this->registration_answer_label( $event_id, $field_key, $questions ),
                    'value' => (string) $value,
                ];
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

        // REG-D55 — say WHAT was retained. anonymize_seat() scrubs name, email,
        // phone and the custom answers but deliberately keeps the seat's
        // customer id and order id, either of which resolves straight back to
        // the person through the WP user record or the WooCommerce order's
        // billing email. Reporting items_retained with no message left the
        // operator to assume the retained part was anonymous.
        $messages = [];
        if ( ! empty( $seat_ids ) ) {
            $messages[] = \__( 'Event registrations were kept for capacity and audit purposes with the attendee name, email, phone and answers removed. Each seat still records the WordPress customer id and WooCommerce order id it came from; those links are cleared by WooCommerce\'s own eraser.', 'anchor-schema' );
        }

        return [
            // Seats are retained with PII scrubbed (kept for capacity + audit), not
            // physically deleted — so nothing is "removed", everything is "retained".
            'items_removed'  => false,
            'items_retained' => ! empty( $seat_ids ),
            'messages'       => $messages,
            'done'           => \count( $seat_ids ) < $per_page,
        ];
    }

    public static function instance() {
        return self::$instance;
    }

    /**
     * The single events-management capability (audit REG-D20 / REG-D62 / WOO-D41).
     *
     * Every roster, export, resend and front-end console surface resolves who may
     * act here and nowhere else. On a store the roster and the order actions expose
     * customer PII (billing email, customer ids, order numbers), so they require a
     * shop-management capability; a free/internal install keeps the Editor-held
     * `edit_others_posts`. Before this existed the same data was reachable behind
     * three different capabilities, so hardening one surface simply moved the hole.
     *
     * A site whose events are run by a role that holds neither capability can point
     * the whole module at its own capability with one filter:
     *
     *     add_filter( 'anchor_events_capability', fn() => 'manage_event_roster' );
     *
     * A filter that returns something unusable (empty string, non-string) is
     * ignored rather than obeyed — an empty capability string passes
     * current_user_can() for everyone, which would open every surface at once.
     *
     * @return string Capability slug.
     */
    public static function events_capability() {
        return self::capability_for( \class_exists( 'WooCommerce' ) );
    }

    /**
     * The store-aware half of events_capability(), with the runtime taken as an
     * argument rather than read from class_exists().
     *
     * Split out so both answers are reachable in one process: CI installs
     * WooCommerce for every run, so without this the `edit_others_posts` branch —
     * the one every non-store site in the fleet resolves to — would be exercised
     * by nothing at all.
     *
     * @param bool $wc_active Whether WooCommerce is active.
     * @return string Capability slug.
     */
    public static function capability_for( $wc_active ) {
        $wc_active = (bool) $wc_active;
        $cap       = $wc_active ? self::CAP_STORE : self::CAP_BASE;

        /**
         * Filter the capability required to manage events (rosters, exports,
         * resends, the front-end console).
         *
         * @param string $cap       Default: manage_woocommerce on a store, else edit_others_posts.
         * @param bool   $wc_active Whether WooCommerce is active.
         */
        $filtered = \apply_filters( 'anchor_events_capability', $cap, $wc_active );

        return ( \is_string( $filtered ) && $filtered !== '' ) ? $filtered : $cap;
    }

    /** Convenience wrapper: does the current user hold events_capability()? */
    public static function current_user_can_manage_events() {
        return \current_user_can( self::events_capability() );
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

    /* ═══════════════════════════════════════════════════════════
       Phase 4, Task 4.2 — Event JSON-LD front-end emission
       ═══════════════════════════════════════════════════════════ */

    /**
     * Thin wp_head wrapper around render_event_schema() — the interesting
     * logic (and everything testable) lives in render_event_schema() itself,
     * which returns a string instead of echoing so it can be unit-tested
     * without booting the full query/template stack.
     */
    public function output_event_schema() {
        echo $this->render_event_schema(); // phpcs:ignore WordPress.Security.EscapeOutput -- render_event_schema() returns pre-escaped-for-<script> JSON via wp_json_encode(); nothing here needs esc_html.
    }

    /**
     * Build the `<script type="application/ld+json">` tag for one event, or
     * '' when nothing should be emitted.
     *
     * When $event_id is omitted, resolves the current single-event front-end
     * view via is_singular( self::CPT ) + get_queried_object_id() — this is
     * the wp_head codepath. Passing $event_id explicitly (as tests do) skips
     * that query-dependent resolution entirely, which is what makes this
     * method unit-testable outside a real front-end request.
     *
     * Nothing is emitted when:
     *   - not a single `event` CPT view (query-resolved path only);
     *   - Event_Schema::for_event() has nothing to advertise ([] — no usable
     *     start date, or a group parent with zero live children);
     *   - the parent Anchor Schema plugin already has an ENABLED manual
     *     'Event' schema item configured for this exact post (real de-dupe
     *     check against Anchor_Schema_Admin::META_KEY post meta — see
     *     parent_schema_plugin_has_event_schema() for the full reasoning);
     *   - the `anchor_events_emit_event_schema` filter returns false.
     *
     * @param int|null $event_id
     * @return string
     */
    public function render_event_schema( $event_id = null ) {
        if ( $event_id === null ) {
            if ( \is_admin() || ! \is_singular( self::CPT ) ) {
                return '';
            }
            $event_id = \get_queried_object_id();
        }

        $event_id = (int) $event_id;
        if ( $event_id <= 0 || \get_post_type( $event_id ) !== self::CPT ) {
            return '';
        }

        $data = $this->event_schema->for_event( $event_id );
        if ( empty( $data ) ) {
            return '';
        }

        $should_emit = ! $this->parent_schema_plugin_has_event_schema( $event_id );

        /**
         * Filter whether Task 4.2 emits Event JSON-LD for a given event.
         * Defaults to true unless the parent Anchor Schema plugin already
         * has an enabled manual 'Event' schema item for this post (de-dupe
         * — avoids two conflicting Event nodes on the same page).
         *
         * @param bool $should_emit
         * @param int  $event_id
         */
        $should_emit = (bool) \apply_filters( 'anchor_events_emit_event_schema', $should_emit, $event_id );
        if ( ! $should_emit ) {
            return '';
        }

        $payload = \array_merge( [ '@context' => 'https://schema.org' ], $data );

        // Deliberately no JSON_UNESCAPED_SLASHES: the default `/` -> `\/`
        // escaping is what keeps a literal "</script>" in any data value
        // (e.g. an event title) from breaking out of this <script> tag.
        // Escaped slashes decode back to plain "/" for any JSON consumer
        // (browsers, Google, json_decode()) — this is purely a safe-HTML-
        // embedding concern, it does not change the parsed JSON values.
        return '<script type="application/ld+json">' . \wp_json_encode( $payload, \JSON_UNESCAPED_UNICODE ) . '</script>';
    }

    /**
     * De-dupe check vs the parent Anchor Schema plugin (Anchor_Schema_Render
     * / Anchor_Schema_Admin, includes/class-anchor-schema-*.php).
     *
     * Investigated behavior: Anchor_Schema_Render::output_active_schemas()
     * hooks wp_head and, for ANY singular post, reads
     * get_post_meta( $post->ID, Anchor_Schema_Admin::META_KEY, true )
     * ('_anchor_schema_items') — an array of manually-configured schema
     * items, each with 'type' / 'enabled' / 'json' keys — and prints the
     * 'json' (or 'min_json') of every item with enabled === true. It does
     * NOT auto-map the `event` CPT (or any CPT) to an Event type; it only
     * ever emits what a site editor explicitly added via the Schema admin
     * UI for that specific post. So by default (no manual schema configured)
     * the parent plugin never emits anything for an event post, and ours
     * emits normally.
     *
     * The one real conflict case is when an editor HAS manually added an
     * enabled 'Event'-typed item to this exact post via that UI — printing
     * ours too would put two competing Event nodes on the same page. This
     * checks for exactly that case (an enabled item whose 'type' is
     * 'Event') and defers to the manually-configured one.
     *
     * A non-Event manual item (e.g. FAQPage) or a disabled item never
     * matches — those don't produce a conflicting Event node, so ours still
     * emits.
     *
     * @param int $event_id
     * @return bool True when the parent plugin will emit an Event node for this post.
     */
    protected function parent_schema_plugin_has_event_schema( $event_id ) {
        if ( ! \class_exists( 'Anchor_Schema_Admin' ) ) {
            return false;
        }

        $items = \get_post_meta( $event_id, \Anchor_Schema_Admin::META_KEY, true );
        if ( ! \is_array( $items ) ) {
            return false;
        }

        foreach ( $items as $item ) {
            if ( empty( $item['enabled'] ) ) {
                continue;
            }
            $type = isset( $item['type'] ) ? (string) $item['type'] : '';
            if ( \strcasecmp( $type, 'Event' ) === 0 ) {
                return true;
            }
        }

        return false;
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
            // Task 7 (COORD-D4/REG-D19): seats are published posts whose title
            // is the attendee's name — nothing reads this over REST, so it has
            // no REST route at all.
            'show_in_rest' => false,
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
                // Task 7 (COORD-D2): allow-list, not a blanket true — virtual_url,
                // organizer_email and every other unlisted key default to hidden.
                'show_in_rest' => in_array( $key, self::REST_PUBLIC_META, true ),
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

        // Task 3.1 — per-event email template overrides (Task 3.2 metabox writes
        // these directly via update_post_meta; deliberately NOT added to
        // get_meta_schema()/save_meta()'s allow-list and NOT exposed to REST).
        foreach ( self::EMAIL_TEMPLATE_TYPES as $email_type ) {
            \register_post_meta( self::CPT, '_anchor_event_email_tpl_' . $email_type, [
                'type'          => 'string',
                'single'        => true,
                'show_in_rest'  => false,
                'auth_callback' => $event_auth_callback,
            ] );
        }

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

        // v1.1 lifecycle email markers (spec §4.2). Written by cron/cancel tasks only.
        //
        // reminders_sent is keyed by the event's start_ts (audit MODEL-D16):
        // [ start_ts => [ offset => sent_at ] ]. Keying by the date the
        // reminder was sent ABOUT is what re-arms a rescheduled event, and —
        // unlike clearing the marker when the date moves — it still remembers
        // the original date if the event is moved back onto it.
        \register_post_meta( self::REG_CPT, Registrations::META_REMINDERS_SENT, [
            'type' => 'array', 'single' => true, 'show_in_rest' => false, 'auth_callback' => $reg_auth_callback,
        ] );
        // A TIMESTAMP, not a boolean (audit REG-D4): it records which
        // cancellation was emailed about, and Registrations::update_status()
        // clears it whenever the seat leaves a terminal status.
        \register_post_meta( self::REG_CPT, Registrations::META_CANCEL_EMAILED, [
            'type' => 'integer', 'single' => true, 'show_in_rest' => false, 'auth_callback' => $reg_auth_callback,
        ] );
        // Retry job for a lifecycle email whose wp_mail() returned false
        // (audit REG-D5): { type, attempts, next_at, ... }. Drained by the
        // hourly sweep; deleted on success or after MAX_EMAIL_ATTEMPTS.
        \register_post_meta( self::REG_CPT, Registrations::META_EMAIL_RETRY, [
            'type' => 'array', 'single' => true, 'show_in_rest' => false, 'auth_callback' => $reg_auth_callback,
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
            // Full, as distinct from merely closed. Capacity can say this on its
            // own, but a course can be full without its seat count ever being
            // entered — and "registration happens elsewhere" is not the same
            // message to a visitor as "no seats left".
            'sold_out' => [ 'type' => 'boolean' ],
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
            // Organizer-digest markers, keyed by the start_ts each digest was
            // sent ABOUT (audit REG-D42/MODEL-D16): [ start_ts => sent_at ].
            // Was a bare timestamp, which latched an event to its first date
            // for ever; a pre-upgrade int still reads as "sent for the date
            // this event is on now" (roster_sent_markers()) and is rewritten
            // into the keyed shape on the next sweep.
            'roster_sent' => [ 'type' => 'array', 'show_in_rest' => false ],
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
            // Event-level typed labels — short author-written descriptors a
            // theme renders as badges ("2 Day Course", "14 CE Credits").
            // Rows are { key, label, value }; `key` is clamped to
            // labels_vocabulary() and `label` only carries a caption for
            // key='custom' (known keys resolve their caption at render time so
            // it stays translatable). Metabox/manager-form owned, so
            // show_in_rest=false for the same reason as `sessions` above.
            //
            // Deliberately NOT derived from start/end dates: the same two-date
            // span can be a "1.5 Day Course" or a "2 Day Course", and
            // "2.5 Day Course" is not computable from dates at all.
            'labels' => [ 'type' => 'array', 'show_in_rest' => false ],
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
            // linked_products/roster_sent above). show_in_rest=false so
            // REST/Gutenberg can never write them either.
            'group_role' => [ 'type' => 'string', 'show_in_rest' => false ],
            'group_id' => [ 'type' => 'integer', 'show_in_rest' => false ],
            'offering_dates' => [ 'type' => 'array', 'show_in_rest' => false ],
            'occurrence_key' => [ 'type' => 'string', 'show_in_rest' => false ],
            'occurrence_closed' => [ 'type' => 'boolean', 'show_in_rest' => false ],
            // The occurrence's authored label — the offering-dates row's own
            // text, written onto the child by Occurrences (audit MODEL-D10 /
            // MODEL-D27 / RENDER-D22). It used to live only inside the child's
            // post_title, which occurrence_label() then string-sliced back out
            // against the parent's title prefix, so renaming the parent erased
            // every label from "Choose a date". Deliberately absent from
            // Occurrences::INHERITED_KEYS (and named in PER_OCCURRENCE_KEYS as
            // a second guard): a parent has no occurrence label of its own to
            // copy down. Engine-owned, so show_in_rest=false like its
            // siblings.
            'label' => [ 'type' => 'string', 'show_in_rest' => false ],
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
        return [
            'start_date' => '',
            'end_date' => '',
            'start_time' => '',
            'end_time' => '',
            // '' means "the site's zone", which is what an event with no
            // timezone of its own has always meant. This used to mint the
            // literal "UTC-6" from gmt_offset — a string DateTimeZone rejects
            // outright, kept alive only by normalize_timezone()'s special case
            // — and Occurrences::sync_shared_meta() then wrote that
            // read-time invention down as a real row on every occurrence child
            // (audit MODEL-D37). normalize_timezone( '' ) resolves to
            // wp_timezone_string(), so the offset still applies; it is just no
            // longer stored as data.
            'timezone' => '',
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
            'sold_out' => false,
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
            'roster_sent' => [],
            'type' => 'single',
            'sessions' => [],
            'labels' => [],
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

        // Task 3.2 — per-event lifecycle-email authoring UI (Monaco + token
        // palette + live/real-data preview) over Task 3.1's template model.
        // Full-width 'normal' box (editor + preview side-by-side needs room).
        \add_meta_box(
            'anchor_event_emails',
            __( 'Emails', 'anchor-schema' ),
            [ $this, 'render_email_builder_metabox' ],
            self::CPT,
            'normal',
            'default'
        );

        // Task 3.3 — read-only "upcoming sends" schedule (computed on the fly
        // by compute_email_schedule(); no cron/send/offset behavior changes).
        \add_meta_box(
            'anchor_event_upcoming_sends',
            __( 'Upcoming Sends', 'anchor-schema' ),
            [ $this, 'render_upcoming_sends_metabox' ],
            self::CPT,
            'side',
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
        echo $this->render_ticket_types_fields( (int) $post->ID, 'button' ); // already escaped
    }

    /**
     * Shared ticket-tier authoring table for the wp-admin metabox and the
     * front-end event manager. The persistence shape is identical in both
     * places: anchor_event_tickets[<index>][...].
     *
     * @param int    $event_id
     * @param string $button_class
     * @return string Escaped HTML.
     */
    private function render_ticket_types_fields( $event_id, $button_class = 'button' ) {
        $tiers = $this->ticket_types->get( $event_id );
        // The implicit-primary synthesized tier is not persisted; only show
        // authored rows so a blank event starts with an empty table.
        $stored = \get_post_meta( $event_id, Ticket_Types::META_KEY, true );
        $rows   = ( \is_array( $stored ) && ! empty( $stored ) ) ? $tiers : [];
        \ob_start();
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
                <button type="button" class="<?php echo esc_attr( $button_class ); ?> anchor-event-ticket-add"><?php echo esc_html__( 'Add ticket tier', 'anchor-schema' ); ?></button>
            </p>
            <script type="text/html" id="anchor-event-ticket-template">
                <?php echo $this->ticket_type_row_html( 0, null, true ); // already escaped ?>
            </script>
        </div>
        <?php
        return (string) \ob_get_clean();
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

    /**
     * Render a single labels-repeater table row. Field names use the index
     * scheme anchor_event_labels[<index>][...], matching the session/ticket-tier
     * row convention above. When $template is true, the literal token __INDEX__
     * is used so the JS can substitute a fresh row index on add.
     *
     * The caption input is only meaningful for key='custom' (known keys resolve
     * their caption from labels_vocabulary() at render time); the JS toggles its
     * disabled state to match the selected key.
     *
     * @param int        $index
     * @param array|null $row
     * @param bool       $template
     * @return string Escaped HTML.
     */
    private function event_label_row_html( $index, $row = null, $template = false ) {
        $idx  = $template ? '__INDEX__' : (string) $index;
        $base = 'anchor_event_labels[' . $idx . ']';

        $key     = $row['key'] ?? 'duration';
        $caption = $row['label'] ?? '';
        $value   = $row['value'] ?? '';

        \ob_start();
        ?>
        <tr class="anchor-event-label-row">
            <td>
                <select name="<?php echo esc_attr( $base . '[key]' ); ?>" class="anchor-label-key">
                    <?php foreach ( $this->labels_vocabulary() as $vocab_key => $vocab_caption ) : ?>
                        <option value="<?php echo esc_attr( $vocab_key ); ?>" <?php selected( $key, $vocab_key ); ?>><?php echo esc_html( $vocab_caption ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <input type="text" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $caption ); ?>" class="anchor-label-caption" placeholder="<?php echo esc_attr__( 'Caption (custom only)', 'anchor-schema' ); ?>" <?php disabled( $key !== 'custom' ); ?> />
            </td>
            <td>
                <input type="text" name="<?php echo esc_attr( $base . '[value]' ); ?>" value="<?php echo esc_attr( $value ); ?>" class="anchor-label-value" placeholder="<?php echo esc_attr__( 'e.g. 2 Day Course', 'anchor-schema' ); ?>" />
            </td>
            <td>
                <button type="button" class="button-link-delete anchor-event-label-remove" aria-label="<?php echo esc_attr__( 'Remove label', 'anchor-schema' ); ?>">&times;</button>
            </td>
        </tr>
        <?php
        return (string) \ob_get_clean();
    }

    /**
     * Render a single offering-dates repeater row (Phase 2, Task 2.3 — Offering
     * Dates section, data-when-type="offering"). Field names use the index
     * scheme anchor_event_offering_dates[<index>][...], matching the session/
     * ticket-tier repeater convention above. When $template is true, the
     * literal token __INDEX__ is used so the JS can substitute a fresh row
     * index on add. Values are display-only here; save_meta()'s
     * sanitize_offering_dates_rows() is the single validated place these are
     * persisted (never the generic save_meta() allow-list — see
     * persist_group_authoring()).
     *
     * @param int        $index
     * @param array|null $row
     * @param bool       $template
     * @return string Escaped HTML.
     */
    private function event_offering_row_html( $index, $row = null, $template = false, array $tiers = [] ) {
        $idx = $template ? '__INDEX__' : (string) $index;
        $base = 'anchor_event_offering_dates[' . $idx . ']';

        $date = $row['date'] ?? '';
        $end_date = $row['end_date'] ?? '';
        $tier_id = $row['tier_id'] ?? '';
        $start_time = $row['start_time'] ?? '';
        $end_time = $row['end_time'] ?? '';
        $label = $row['label'] ?? '';
        $capacity = ( $row && ! empty( $row['capacity'] ) ) ? (int) $row['capacity'] : '';

        \ob_start();
        ?>
        <tr class="anchor-event-offering-row">
            <td>
                <input type="date" name="<?php echo esc_attr( $base . '[date]' ); ?>" value="<?php echo esc_attr( $date ); ?>" class="anchor-offering-date" />
            </td>
            <td>
                <input type="date" name="<?php echo esc_attr( $base . '[end_date]' ); ?>" value="<?php echo esc_attr( $end_date ); ?>" class="anchor-offering-end-date" />
            </td>
            <td>
                <input type="time" name="<?php echo esc_attr( $base . '[start_time]' ); ?>" value="<?php echo esc_attr( $start_time ); ?>" class="anchor-offering-start-time" />
            </td>
            <td>
                <input type="time" name="<?php echo esc_attr( $base . '[end_time]' ); ?>" value="<?php echo esc_attr( $end_time ); ?>" class="anchor-offering-end-time" />
            </td>
            <td>
                <input type="text" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" class="anchor-offering-label" placeholder="<?php echo esc_attr__( 'e.g. Morning session', 'anchor-schema' ); ?>" />
            </td>
            <td>
                <input type="number" min="0" step="1" name="<?php echo esc_attr( $base . '[capacity]' ); ?>" value="<?php echo esc_attr( $capacity ); ?>" class="anchor-offering-capacity" placeholder="<?php echo esc_attr__( 'Default', 'anchor-schema' ); ?>" />
            </td>
            <td>
                <select name="<?php echo esc_attr( $base . '[tier_id]' ); ?>" class="anchor-offering-tier">
                    <option value=""><?php echo esc_html__( 'All tickets', 'anchor-schema' ); ?></option>
                    <?php foreach ( $tiers as $tier ) :
                        $t_id = (string) ( $tier['id'] ?? '' );
                        if ( $t_id === '' ) { continue; }
                        $t_label = (string) ( $tier['label'] ?? '' );
                        ?>
                        <option value="<?php echo esc_attr( $t_id ); ?>" <?php selected( $tier_id, $t_id ); ?>><?php echo esc_html( $t_label !== '' ? $t_label : $t_id ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <button type="button" class="button-link-delete anchor-event-offering-remove" aria-label="<?php echo esc_attr__( 'Remove date', 'anchor-schema' ); ?>">&times;</button>
            </td>
        </tr>
        <?php
        return (string) \ob_get_clean();
    }

    /**
     * Sun(0)..Sat(6) short weekday labels for the recurrence builder's
     * weekday checkboxes (Phase 2, Task 2.3), matching the 0..6 index scheme
     * PHP's date('w') and Occurrences::expand_recurrence()'s `weekdays` rule
     * key both use.
     *
     * @return array<int,string>
     */
    private function weekday_labels() {
        return [
            0 => __( 'Sun', 'anchor-schema' ),
            1 => __( 'Mon', 'anchor-schema' ),
            2 => __( 'Tue', 'anchor-schema' ),
            3 => __( 'Wed', 'anchor-schema' ),
            4 => __( 'Thu', 'anchor-schema' ),
            5 => __( 'Fri', 'anchor-schema' ),
            6 => __( 'Sat', 'anchor-schema' ),
        ];
    }

    /**
     * Normalize a stored/posted recurrence rule for display, filling in the
     * builder's default shape so render callers can safely read every key
     * without isset() checks. Purely a read-side convenience — sanitization
     * for PERSISTENCE is sanitize_recurrence_rule()'s job, not this.
     *
     * @param mixed $raw
     * @return array{freq:string,interval:int|string,count:int|string,until:string,weekdays:array,start_time:string,end_time:string,capacity:int|string}
     */
    private function recurrence_display_defaults( $raw ) {
        return \wp_parse_args( \is_array( $raw ) ? $raw : [], [
            'freq' => 'weekly',
            'interval' => 1,
            'count' => '',
            'until' => '',
            'weekdays' => [],
            'start_time' => '',
            'end_time' => '',
            'capacity' => '',
        ] );
    }

    /**
     * The group parent's "apply this registration setting to all dates"
     * action, shared verbatim by the classic metabox and the front-end
     * manager form (audit MODEL-D40).
     *
     * Why an action and not a plain inherited setting: `registration_enabled`
     * is a PER-OCCURRENCE fact (Occurrences::PER_OCCURRENCE_KEYS) — closing
     * one date must not close the others, and must not be silently undone by
     * the next parent save. So the parent's own checkbox governs the PARENT
     * post only, and every existing date keeps its own value unless an admin
     * ticks this box on purpose. Before this existed, ticking the parent's
     * checkbox looked like a group-wide switch, saved without error, and
     * changed nothing for any date that already existed (production parent
     * 7258 registration_enabled=1 / child 7528 =0).
     *
     * Rendered ONLY on a post that is a group parent with at least one LIVE
     * date — there is nothing to apply to otherwise, and soft-closed dates are
     * never written (see Occurrences::apply_registration_to_children()).
     * Always unchecked: it is a one-shot action, never a stored setting.
     *
     * @param int $post_id
     * @return string Escaped markup, or '' when the action does not apply.
     */
    public function render_registration_apply_to_dates( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || ! $this->occurrences->is_group_parent( $post_id ) ) {
            return '';
        }

        $count = count( $this->occurrences->children( $post_id, false ) );
        if ( $count < 1 ) {
            return '';
        }

        $label = sprintf(
            /* translators: %d: number of live scheduled dates in this offering. */
            _n(
                'Apply to the %d scheduled date',
                'Apply to all %d scheduled dates',
                $count,
                'anchor-schema'
            ),
            $count
        );

        return '<div class="anchor-event-field anchor-event-field--check anchor-event-apply-to-dates">'
            . '<label><input type="checkbox" id="anchor_event_registration_apply_to_dates" name="anchor_event_registration_apply_to_dates" value="1" /> '
            . esc_html( $label ) . '</label>'
            . '<p class="description">'
            . esc_html__( 'Existing dates keep their own setting unless you apply.', 'anchor-schema' )
            . '</p></div>';
    }

    /**
     * Renders the Offering Dates repeater (data-when-type="offering") + the
     * Recurring Schedule rule builder (data-when-type="recurring") shared by
     * the classic metabox and, offering-only, the front-end manager form
     * (Phase 2, Task 2.3). $include_recurrence is false for the front-end
     * form — the recurrence builder is admin-only per spec. The front-end
     * form's own Event Type <select> (render_event_manager_form()) never
     * offers "Recurring schedule" as a choosable option, so a front-end user
     * can no longer land in the $include_recurrence===false /
     * $event_type==='recurring' state via their own action; it's still
     * reachable when an event that's ALREADY recurring (created/edited in the
     * admin) is opened in the front-end form. In that case this renders a
     * read-only note plus hidden inputs that round-trip the stored rule
     * unchanged (Task 2.3 FIX 1) — never the interactive builder, and never a
     * silently-blank state that would clobber the rule on save.
     *
     * @param int  $post_id
     * @param array $meta   get_meta()'s result for $post_id (or defaults for a new post).
     * @param string $event_type
     * @param bool $include_recurrence
     * @return void Echoes directly (called from inside an existing ob context in both callers).
     */
    private function render_group_authoring_sections( $post_id, array $meta, $event_type, $include_recurrence = true ) {
        $offering_dates = \is_array( $meta['offering_dates'] ) ? $meta['offering_dates'] : [];
        $recurrence = $this->recurrence_display_defaults( $meta['recurrence'] );
        $weekday_labels = $this->weekday_labels();
        $child_count = ( $post_id && in_array( $event_type, [ 'offering', 'recurring' ], true ) )
            ? count( $this->occurrences->children( $post_id ) )
            : 0;
        // The event's own ticket tiers, offered per row so a date can name which
        // ticket it sells. Empty on a free event, leaving only "All tickets".
        $offering_tiers = $post_id ? (array) $this->ticket_types->get( $post_id ) : [];
        ?>
        <?php
        // Inline validation surfacing (Task 2.3 notice fix): a stored-state
        // check, not a save outcome — an offering that currently has no dates
        // at all. Since MODEL-D14 an emptied save KEEPS the stored rows, so
        // this no longer fires for the case it was written for; the queued
        // notice below is what tells that author anything happened.
        $offering_invalid = ( $event_type === 'offering' && empty( $offering_dates ) );

        // The queued notices from THIS author's last save of this post
        // (queue_group_notice()). This render is the one request that provably
        // runs after the queue is written on every editor: Gutenberg re-POSTs
        // the metaboxes to post.php?meta-box-loader=1 after its REST save, and
        // that request IS this markup — it is where save_meta() runs and where
        // its output is shown. Without it a block-editor author watched the
        // rows they deleted quietly reappear with no explanation.
        //
        // A PEEK, not a consume: on a classic full page load admin_notices()
        // has already fired (and consumed) before any metabox renders, so the
        // classic editor still shows exactly one notice at the top and finds
        // nothing left here. The metabox-loader request — the one where
        // admin_notices() deliberately bails — is the only one where this peek
        // has anything to render. The front-end manager form consumes the
        // queue into its redirect arg long before it renders, so it too finds
        // nothing here and never doubles up.
        $queued_notices = $post_id ? $this->queued_group_notices( $post_id ) : [];
        ?>
        <?php foreach ( $queued_notices as $queued_notice ) : ?>
            <div class="notice inline anchor-event-save-notice <?php echo esc_attr( $queued_notice['level'] === 'warning' ? 'notice-warning' : 'notice-error' ); ?>">
                <p><?php echo esc_html( $queued_notice['message'] ); ?></p>
            </div>
        <?php endforeach; ?>
        <div class="anchor-event-section anchor-event-conditional" data-step="2" data-when-type="offering">
            <h3><?php echo esc_html__( 'Offering Dates', 'anchor-schema' ); ?></h3>
            <p class="description"><?php echo esc_html__( 'One row per date this event is being offered. Visitors pick the date that suits them, and each date keeps its own seat count, so one filling up does not close the others. Blank rows are skipped.', 'anchor-schema' ); ?></p>
            <div class="notice notice-error inline anchor-event-offering-error"<?php echo $offering_invalid ? '' : ' style="display:none;"'; ?>>
                <p><?php echo esc_html__( 'Add at least one offering date before saving — no dates were generated/updated.', 'anchor-schema' ); ?></p>
            </div>
            <?php if ( $child_count > 0 && $event_type === 'offering' ) : ?>
                <p class="description"><strong><?php echo esc_html( sprintf( /* translators: %d: number of generated child events */ _n( '%d generated date is currently live.', '%d generated dates are currently live.', $child_count, 'anchor-schema' ), $child_count ) ); ?></strong></p>
            <?php endif; ?>
            <table class="widefat anchor-event-offering-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__( 'Date', 'anchor-schema' ); ?></th>
                        <th><?php echo esc_html__( 'End date', 'anchor-schema' ); ?></th>
                        <th><?php echo esc_html__( 'Start time', 'anchor-schema' ); ?></th>
                        <th><?php echo esc_html__( 'End time', 'anchor-schema' ); ?></th>
                        <th><?php echo esc_html__( 'Label', 'anchor-schema' ); ?></th>
                        <th><?php echo esc_html__( 'Capacity', 'anchor-schema' ); ?></th>
                        <th><?php echo esc_html__( 'Ticket', 'anchor-schema' ); ?></th>
                        <th aria-hidden="true"></th>
                    </tr>
                </thead>
                <tbody class="anchor-event-offering-rows">
                    <?php foreach ( $offering_dates as $i => $row ) : ?>
                        <?php echo $this->event_offering_row_html( (int) $i, $row, false, $offering_tiers ); // already escaped ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button type="button" class="button anchor-event-offering-add"><?php echo esc_html__( 'Add date', 'anchor-schema' ); ?></button>
            </p>
            <script type="text/html" id="anchor-event-offering-template">
                <?php echo $this->event_offering_row_html( 0, null, true, $offering_tiers ); // already escaped ?>
            </script>
        </div>

        <?php
        $recurrence_invalid = ( $event_type === 'recurring' && empty( $recurrence['count'] ) && empty( $recurrence['until'] ) );
        ?>
        <?php if ( $include_recurrence ) : ?>
        <div class="anchor-event-section anchor-event-conditional" data-step="2" data-when-type="recurring">
            <h3><?php echo esc_html__( 'Recurring Schedule', 'anchor-schema' ); ?></h3>
            <p class="description"><?php echo esc_html__( 'For an event that repeats on a schedule — every Tuesday, or the first of each month. It starts from the Start Date above and creates each date for you. Tell it when to stop, either after a number of dates or on a final date; without that it will not create anything.', 'anchor-schema' ); ?></p>
            <div class="notice notice-error inline anchor-event-recurrence-error"<?php echo $recurrence_invalid ? '' : ' style="display:none;"'; ?>>
                <p><?php echo esc_html__( 'Set an end for the recurrence — a number of occurrences or an until date — before saving. No occurrences were generated/updated.', 'anchor-schema' ); ?></p>
            </div>
            <?php if ( $child_count > 0 && $event_type === 'recurring' ) : ?>
                <p class="description"><strong><?php echo esc_html( sprintf( /* translators: %d: number of generated child events */ _n( '%d generated occurrence is currently live.', '%d generated occurrences are currently live.', $child_count, 'anchor-schema' ), $child_count ) ); ?></strong></p>
            <?php endif; ?>
            <div class="anchor-event-grid">
                <div class="anchor-event-field">
                    <label for="anchor_event_recurrence_freq"><?php echo esc_html__( 'Frequency', 'anchor-schema' ); ?></label>
                    <select id="anchor_event_recurrence_freq" name="anchor_event_recurrence[freq]">
                        <option value="weekly" <?php selected( $recurrence['freq'], 'weekly' ); ?>><?php echo esc_html__( 'Weekly', 'anchor-schema' ); ?></option>
                        <option value="monthly" <?php selected( $recurrence['freq'], 'monthly' ); ?>><?php echo esc_html__( 'Monthly', 'anchor-schema' ); ?></option>
                    </select>
                </div>
                <div class="anchor-event-field">
                    <label for="anchor_event_recurrence_interval"><?php echo esc_html__( 'Every', 'anchor-schema' ); ?></label>
                    <input type="number" min="1" step="1" id="anchor_event_recurrence_interval" name="anchor_event_recurrence[interval]" value="<?php echo esc_attr( $recurrence['interval'] ? $recurrence['interval'] : 1 ); ?>" />
                </div>
                <div class="anchor-event-field anchor-event-recurrence-weekdays" data-when-freq="weekly">
                    <label><?php echo esc_html__( 'Repeat on', 'anchor-schema' ); ?></label>
                    <?php foreach ( $weekday_labels as $wd => $label ) : ?>
                        <label class="anchor-event-weekday-checkbox">
                            <input type="checkbox" name="anchor_event_recurrence[weekdays][]" value="<?php echo esc_attr( $wd ); ?>" <?php checked( in_array( $wd, (array) $recurrence['weekdays'], true ) ); ?> />
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php echo esc_html__( 'Leave all unchecked to repeat on the Start Date\'s own weekday only.', 'anchor-schema' ); ?></p>
                </div>
                <div class="anchor-event-field">
                    <label for="anchor_event_recurrence_count"><?php echo esc_html__( 'End after (# occurrences)', 'anchor-schema' ); ?></label>
                    <input type="number" min="1" step="1" id="anchor_event_recurrence_count" name="anchor_event_recurrence[count]" value="<?php echo esc_attr( $recurrence['count'] ); ?>" />
                </div>
                <div class="anchor-event-field">
                    <label for="anchor_event_recurrence_until"><?php echo esc_html__( '...or end by date', 'anchor-schema' ); ?></label>
                    <input type="date" id="anchor_event_recurrence_until" name="anchor_event_recurrence[until]" value="<?php echo esc_attr( $recurrence['until'] ); ?>" />
                </div>
                <div class="anchor-event-field anchor-event-time-fields">
                    <label for="anchor_event_recurrence_start_time"><?php echo esc_html__( 'Start time', 'anchor-schema' ); ?></label>
                    <input type="time" id="anchor_event_recurrence_start_time" name="anchor_event_recurrence[start_time]" value="<?php echo esc_attr( $recurrence['start_time'] ); ?>" />
                </div>
                <div class="anchor-event-field anchor-event-time-fields">
                    <label for="anchor_event_recurrence_end_time"><?php echo esc_html__( 'End time', 'anchor-schema' ); ?></label>
                    <input type="time" id="anchor_event_recurrence_end_time" name="anchor_event_recurrence[end_time]" value="<?php echo esc_attr( $recurrence['end_time'] ); ?>" />
                </div>
                <div class="anchor-event-field">
                    <label for="anchor_event_recurrence_capacity"><?php echo esc_html__( 'Capacity per occurrence', 'anchor-schema' ); ?></label>
                    <input type="number" min="0" step="1" id="anchor_event_recurrence_capacity" name="anchor_event_recurrence[capacity]" value="<?php echo esc_attr( $recurrence['capacity'] ); ?>" />
                </div>
            </div>
            <p class="description anchor-event-recurrence-terminator-hint"><?php echo esc_html__( 'Required: set "End after" OR "...or end by date" (or both — whichever is hit first wins). Without one of these, saving will NOT generate any events.', 'anchor-schema' ); ?></p>
        </div>
        <?php elseif ( $event_type === 'recurring' ) : ?>
        <div class="anchor-event-section anchor-event-conditional" data-step="2" data-when-type="recurring">
            <h3><?php echo esc_html__( 'Recurring Schedule', 'anchor-schema' ); ?></h3>
            <div class="notice notice-info inline anchor-event-recurrence-admin-only">
                <p><?php echo esc_html__( 'Recurring events are managed in the admin. This event\'s recurrence rule is preserved and cannot be edited from this form.', 'anchor-schema' ); ?></p>
            </div>
            <?php
            // Round-trip the stored rule unchanged via hidden inputs (never the
            // interactive builder) so saving this form again does not
            // overwrite it with a blank/incomplete rule — sanitize_recurrence_rule()
            // reads these back into the exact same shape it was stored in.
            foreach ( [ 'freq', 'interval', 'start_time', 'end_time', 'capacity', 'count', 'until' ] as $rk ) :
            ?>
                <input type="hidden" name="anchor_event_recurrence[<?php echo esc_attr( $rk ); ?>]" value="<?php echo esc_attr( $recurrence[ $rk ] ); ?>" />
            <?php endforeach; ?>
            <?php foreach ( (array) $recurrence['weekdays'] as $wd ) : ?>
                <input type="hidden" name="anchor_event_recurrence[weekdays][]" value="<?php echo esc_attr( $wd ); ?>" />
            <?php endforeach; ?>
        </div>
        <?php endif;
    }

    /**
     * <option> markup for an event's Timezone field: wp_timezone_choice()
     * prefixed with an explicit "site default" row.
     *
     * The empty option is not cosmetic. An event's timezone default is ''
     * (meaning "the site's zone" — see get_meta_defaults()), and
     * wp_timezone_choice( '' ) selects nothing, which a browser renders as
     * the FIRST zone in the list. Saving that form would then silently store
     * Africa/Abidjan on an event nobody set a zone for. The empty option
     * gives '' somewhere to be selected, and makes the meaning visible.
     *
     * Shared by the classic metabox and the front-end manager form so the two
     * offer the same choices.
     *
     * @param string $selected Current event timezone ('' = site default).
     * @return string
     */
    private function timezone_field_options( $selected ) {
        $selected = (string) $selected;
        $label    = \sprintf(
            /* translators: %s: the site's timezone, e.g. America/Chicago or UTC-6. */
            __( 'Site default (%s)', 'anchor-schema' ),
            \wp_timezone_string()
        );

        return '<option value=""' . \selected( $selected, '', false ) . '>' . \esc_html( $label ) . '</option>'
            . \wp_timezone_choice( $selected );
    }

    public function render_meta_box( $post ) {
        \wp_nonce_field( self::NONCE, self::NONCE );
        $meta = $this->get_meta( $post->ID );
        $settings = $this->get_settings();
        $timezone_options = $this->timezone_field_options( $meta['timezone'] );
        $event_type = $this->event_type( $post->ID );
        $registration_mode = $this->registration_mode( $post->ID );
        $wc_active = \class_exists( 'WooCommerce' );
        $sessions = $this->get_sessions( $post->ID );
        $labels = $this->get_labels( $post->ID );
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

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Labels', 'anchor-schema' ); ?></h3>
                <p class="description"><?php echo esc_html__( 'Short badges shown on event cards — e.g. "2 Day Course", "14 CE Credits". Duration is stored as text on purpose: a two-date span could be a 1.5- or 2-day course, and "2.5 Day Course" cannot be derived from dates at all.', 'anchor-schema' ); ?></p>
                <table class="widefat anchor-event-labels-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Type', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Caption', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Value', 'anchor-schema' ); ?></th>
                            <th aria-hidden="true"></th>
                        </tr>
                    </thead>
                    <tbody class="anchor-event-labels-rows">
                        <?php foreach ( $labels as $i => $label_row ) : ?>
                            <?php echo $this->event_label_row_html( (int) $i, $label_row ); // already escaped ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="button anchor-event-label-add"><?php echo esc_html__( 'Add label', 'anchor-schema' ); ?></button>
                </p>
                <script type="text/html" id="anchor-event-label-template">
                    <?php echo $this->event_label_row_html( 0, null, true ); // already escaped ?>
                </script>
            </div>

            <?php $this->render_group_authoring_sections( $post->ID, $meta, $event_type, true ); ?>

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
                        <input type="url" id="anchor_event_virtual_url" name="anchor_event_virtual_url" value="<?php echo esc_attr( $meta['virtual_url'] ); ?>" data-required-when-virtual="1" />
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
                    <?php
                    // Group parent only: the explicit "apply to all dates" action
                    // (MODEL-D40). Pre-escaped by the shared renderer.
                    echo $this->render_registration_apply_to_dates( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?>
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
                        <label>
                            <input type="checkbox" id="anchor_event_sold_out" name="anchor_event_sold_out" value="1" <?php checked( $meta['sold_out'] ); ?> />
                            <?php echo esc_html__( 'Sold out', 'anchor-schema' ); ?>
                        </label>
                        <p class="description"><?php echo esc_html__( 'Say the course is full. Use this when there is no seat count to run out — closing registration on its own only means "not bookable here".', 'anchor-schema' ); ?></p>
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
            <?php if ( Roster::current_user_can_manage() ) : // REG-D21 — both handlers would refuse these. ?>
                <a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php echo esc_html__( 'Export CSV', 'anchor-schema' ); ?></a>
                <?php if ( $this->roster ) : ?>
                    <a class="button button-primary" href="<?php echo esc_url( $this->roster->roster_url( $post->ID ) ); ?>"><?php echo esc_html__( 'Open full roster', 'anchor-schema' ); ?></a>
                <?php endif; ?>
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

    /**
     * Task 3.2 — token palette list for the Emails builder UI. The exact
     * scalar + block token keys documented for admin-authored templates
     * (spec §9 / Task 3.1's build_registration_email_html() $tokens map
     * docblock), read directly from that map rather than re-derived: every
     * name below is a real key in $tokens there. There is NO separate
     * {footer} block token — the footer region only substitutes the
     * {site_name} scalar (see default_email_shell()'s docblock) — so it is
     * deliberately not offered here; a {footer} button would insert dead
     * literal text.
     *
     * @return string[] Token names WITHOUT braces.
     */
    /**
     * A per-event override for one of the writable email fields, falling back to
     * whatever the caller already used.
     *
     * The subject and intro of every lifecycle email have only ever been global
     * settings, so an event could rewrite the HTML shell but not the sentence
     * inside it. These are per event now. The fallback is passed in rather than
     * looked up here so each send path keeps its own historical default when no
     * override exists — this can only ever add a value, never change one.
     *
     * @param int    $event_id
     * @param string $type     One of EMAIL_TEMPLATE_TYPES.
     * @param string $field    'subject' or 'intro'.
     * @param string $fallback The caller's existing value.
     * @return string
     */
    public function get_email_field( $event_id, $type, $field, $fallback = '' ) {
        $field = \in_array( $field, [ 'subject', 'intro', 'preheader' ], true ) ? $field : 'intro';
        $type  = \in_array( $type, self::EMAIL_TEMPLATE_TYPES, true ) ? $type : 'confirmation';

        // A live preview passes unsaved values so the panel reflects typing.
        if ( \is_array( $this->preview_field_override )
            && ( $this->preview_field_override['type'] ?? '' ) === $type
            && isset( $this->preview_field_override[ $field ] ) ) {
            return (string) $this->preview_field_override[ $field ];
        }

        $meta = \get_post_meta( (int) $event_id, '_anchor_event_email_' . $field . '_' . $type, true );
        return ( \is_string( $meta ) && $meta !== '' ) ? $meta : (string) $fallback;
    }

    /**
     * Is this email switched on for this event?
     *
     * Enabled unless explicitly turned off, so every event that already exists
     * keeps sending exactly what it sends today — the meta is only ever written
     * when someone unticks the box.
     *
     * @param int    $event_id
     * @param string $type
     * @return bool
     */
    public function is_email_enabled( $event_id, $type ) {
        $type = \in_array( $type, self::EMAIL_TEMPLATE_TYPES, true ) ? $type : 'confirmation';
        return \get_post_meta( (int) $event_id, '_anchor_event_email_off_' . $type, true ) !== '1';
    }

    /**
     * Persist the per-type on/off switches posted by the email builder.
     *
     * No wp_slash() here, deliberately: this saver stores the literal '1' (or
     * deletes the row), never a value the author typed, so there is no
     * backslash for update_post_meta()'s unslash to eat. It is the one
     * exception in persist_event_authoring()'s list — see that docblock.
     */
    private function save_email_switches( $post_id, array $src ) {
        // Only trust the post when the builder was actually on screen, otherwise
        // a save from anywhere else would read "absent" as "turned off".
        if ( empty( $src['anchor_event_email_switches_present'] ) ) {
            return;
        }
        foreach ( self::EMAIL_TEMPLATE_TYPES as $type ) {
            $on = ! empty( $src[ 'anchor_event_email_on_' . $type ] );
            if ( $on ) {
                \delete_post_meta( $post_id, '_anchor_event_email_off_' . $type );
            } else {
                \update_post_meta( $post_id, '_anchor_event_email_off_' . $type, '1' );
            }
        }
    }

    /** Persist the per-event subject/intro pairs posted by the email builder. */
    private function save_email_fields( $post_id, array $src ) {
        foreach ( self::EMAIL_TEMPLATE_TYPES as $type ) {
            // 'intro' is authored in a visual editor now, so it is markup:
            // wp_kses_post() keeps the formatting and strips anything unsafe.
            // sanitize_textarea_field() used to flatten it to plain text, which
            // would silently delete every bold/link/list an author added.
            foreach ( [ 'subject' => 'sanitize_text_field', 'intro' => 'wp_kses_post', 'preheader' => 'sanitize_text_field' ] as $field => $clean ) {
                $key = 'anchor_event_email_' . $field . '_' . $type;
                if ( ! isset( $src[ $key ] ) ) {
                    continue;
                }
                $value = \call_user_func( '\\' . $clean, \wp_unslash( $src[ $key ] ) );
                $meta  = '_anchor_event_email_' . $field . '_' . $type;
                if ( \trim( (string) $value ) === '' ) {
                    \delete_post_meta( $post_id, $meta );   // empty means "use the site default"
                } else {
                    // wp_slash(): re-slash the sanitized value, because
                    // update_post_meta() unslashes again — see
                    // persist_event_authoring()'s docblock.
                    \update_post_meta( $post_id, $meta, \wp_slash( $value ) );
                }
            }
        }
    }

    /**
     * The site-wide wording for one email field — what the event falls back to
     * when it has no override. Shown as the placeholder in the builder so the
     * author can see what they are replacing.
     *
     * @param string $type
     * @param string $field 'subject' or 'intro'
     * @return string
     */
    public function email_field_default( $type, $field ) {
        $s = $this->get_settings();
        $map = [
            'confirmation' => [ 'subject' => 'wc_customer_subject', 'intro' => 'confirmation_message' ],
            'reminder'     => [ 'subject' => 'reminder_subject',     'intro' => 'reminder_intro' ],
            'cancellation' => [ 'subject' => 'cancellation_subject', 'intro' => 'cancellation_intro' ],
            'roster'       => [ 'subject' => 'roster_subject',       'intro' => 'roster_intro' ],
        ];
        $key = $map[ $type ][ $field ] ?? '';
        return $key !== '' ? (string) ( $s[ $key ] ?? '' ) : '';
    }

    /**
     * Tokens that resolve inside the SUBJECT and OPENING LINES.
     *
     * These two fields are expanded with email_tokens() before they are handed
     * to the template, so the only keys that can resolve here are that method's
     * keys. This list is exactly those keys and nothing else.
     *
     * The block tokens ({intro}, {detail_rows}, {seat_list}, {cta_button},
     * {header_image} ...) are deliberately absent: they are regions of the
     * template document, substituted by build_registration_email_html() one
     * pass LATER, and str_replace() does not re-scan what it just wrote. Offering
     * them here produced the exact bug this list fixes — {intro} typed into the
     * intro survived to the inbox as the literal text "{intro}", and the other
     * four silently expanded to nothing.
     */
    private function wording_email_tokens() {
        return [
            'event_title', 'event_date', 'event_time', 'venue', 'days_until',
            'attendee_name', 'join_link', 'event_url', 'site_name',
            'remaining', 'seat_count', 'status', 'order_number', 'order_url',
        ];
    }

    /**
     * Tokens that resolve inside the raw HTML template — the scalars plus the
     * pre-rendered block regions. Mirrors the $tokens map built in
     * build_registration_email_html(); every name here is a real key there.
     */
    private function template_email_tokens() {
        return [
            'event_title', 'event_date', 'event_time', 'venue', 'days_until',
            'attendee_name', 'status', 'join_link', 'event_url', 'site_name', 'event_id',
            'preheader', 'intro', 'greeting', 'header_image', 'guests_line', 'waitlist_notice',
            'detail_rows', 'seat_list', 'cta_button', 'cta_button_2',
            // REG-D27 — the Email Appearance palette, so a hand-built template
            // can opt into the site's colours and logo instead of silently
            // ignoring them.
            'brand_bg', 'brand_surface', 'brand_heading', 'brand_text',
            'brand_button', 'brand_button_text', 'logo',
        ];
    }

    /**
     * Editable starter copy for the opening lines, per email type.
     *
     * NOT the same thing as the fallback wording. The fallback (see
     * email_field_default()) is what an event sends when its opening lines are
     * left blank, and it stays a one-liner so that nothing changes for the
     * events already relying on it. This is what the "Start from a draft"
     * button drops into the editor: real copy with the tokens already placed,
     * so the field is something to edit rather than something to invent.
     *
     * Nothing here is written anywhere until the author clicks that button and
     * then saves the event.
     *
     * No draft opens with a greeting: the template emits {greeting} ("Hi
     * <name>,") immediately before {intro}, so one here would send the email
     * out saying hello twice.
     */
    private function email_starter_copy( $type ) {
        $starters = [
            'confirmation' => [
__( "You're registered for <strong>{event_title}</strong> — we're looking forward to seeing you.", 'anchor-schema' ),
                __( 'It runs on {event_date} at {event_time}, at {venue}.', 'anchor-schema' ),
                __( 'If anything changes, just reply to this email and we\'ll sort it out.', 'anchor-schema' ),
            ],
            'reminder' => [
__( '<strong>{event_title}</strong> is coming up in {days_until} days — on {event_date} at {event_time}.', 'anchor-schema' ),
                __( 'Where to go: {venue}. Please arrive a few minutes early so we can start on time.', 'anchor-schema' ),
            ],
            'cancellation' => [
__( 'Your registration for <strong>{event_title}</strong> on {event_date} has been cancelled.', 'anchor-schema' ),
                __( "If you didn't expect this, reply to this email and we'll look into it right away.", 'anchor-schema' ),
            ],
            'roster' => [
                __( 'Here is the current roster for <strong>{event_title}</strong> on {event_date}.', 'anchor-schema' ),
                __( '{seat_count} registered so far, {remaining} places still open.', 'anchor-schema' ),
            ],
        ];
        $lines = $starters[ $type ] ?? $starters['confirmation'];
        return \implode( '', \array_map( function ( $line ) {
            return '<p>' . $line . '</p>';
        }, $lines ) );
    }

    /**
     * What each block token puts in the email, and when it puts nothing.
     *
     * These are regions of the template document rather than values, which is
     * why several of them look like they "do nothing" in a preview: each is
     * conditional, and renders an empty string when its condition is false. A
     * confirmation for someone who brought no guests and is not waitlisted
     * genuinely has no {guests_line} and no {waitlist_notice}.
     */
    private function template_token_notes() {
        return [
            'preheader'       => __( 'The Preview text from this panel, hidden in the email and shown by the inbox after the subject.', 'anchor-schema' ),
            'intro'           => __( 'The Opening lines from this panel — the body of the email.', 'anchor-schema' ),
            'greeting'        => __( '"Hi <name>," as its own paragraph. Empty when the recipient has no name on file.', 'anchor-schema' ),
            'header_image'    => __( "The event's featured image. Empty when it has none.", 'anchor-schema' ),
            'detail_rows'     => __( 'A small label/value table — what it lists depends on the email (date and venue for a reminder, the seats booked for a confirmation).', 'anchor-schema' ),
            'seat_list'       => __( 'A bulleted list of the attendees. Only the roster and multi-seat confirmations have one.', 'anchor-schema' ),
            'guests_line'     => __( '"Your party of N is confirmed." Empty unless guests were booked.', 'anchor-schema' ),
            'waitlist_notice' => __( 'A note that the recipient is on the waitlist. Empty for everyone else.', 'anchor-schema' ),
            'cta_button'      => __( 'The main button, from the Button field on the left. Empty unless it has both text and a link.', 'anchor-schema' ),
            'cta_button_2'    => __( 'The second button, from the Second button field on the left. Empty unless it has both text and a link.', 'anchor-schema' ),
            'brand_bg'          => __( 'The Email background colour from Settings. Use it inside a style attribute.', 'anchor-schema' ),
            'brand_surface'     => __( 'The card colour from Settings — the panel the email sits on.', 'anchor-schema' ),
            'brand_heading'     => __( 'The heading colour from Settings.', 'anchor-schema' ),
            'brand_text'        => __( 'The body text colour from Settings.', 'anchor-schema' ),
            'brand_button'      => __( 'The button colour from Settings.', 'anchor-schema' ),
            'brand_button_text' => __( 'The colour of the text on a button.', 'anchor-schema' ),
            'logo'              => __( 'The logo from Settings, as its own table row. Empty when no logo is set.', 'anchor-schema' ),
        ];
    }

    /**
     * Stand-in values for the scalar tokens, used ONLY where a real one is
     * missing and ONLY outside a real send.
     *
     * Half the token set is conditional on data a given event may not have —
     * a room link only exists for a virtual event, an order number only after
     * a WooCommerce order, days_until only while the date is still ahead. In a
     * preview those tokens expanded to an empty string, so the panel showed a
     * gap and there was no way to tell a token that does nothing from one that
     * is simply not applicable here.
     *
     * Every caller is a preview or the palette's hover text. Nothing on a send
     * path reads this — see the guard in build_registration_email_html(), which
     * only applies these when $ctx['preview_samples'] is set, and that flag is
     * set in exactly one place: build_preview_ctx().
     *
     * @param int $event_id Used only to build a plausible order URL.
     */
    private function preview_sample_scalars( $event_id = 0 ) {
        return [
            'event_title'   => __( 'Sample Event', 'anchor-schema' ),
            'site_name'     => \get_bloginfo( 'name' ),
            'attendee_name' => __( 'Sample Attendee', 'anchor-schema' ),
            'venue'         => __( 'Sample Venue, Dallas TX', 'anchor-schema' ),
            'event_date'    => \wp_date( \get_option( 'date_format' ), \time() + ( 14 * DAY_IN_SECONDS ) ),
            'event_time'    => \wp_date( \get_option( 'time_format' ), \time() + ( 14 * DAY_IN_SECONDS ) ),
            'days_until'    => '14',
            'status'        => 'confirmed',
            'seat_count'    => '1',
            'remaining'     => '8',
            'order_number'  => '1042',
            'order_url'     => \home_url( '/my-account/view-order/1042/' ),
            'join_link'     => \home_url( '/sample-join-link/' ),
            'event_url'     => $event_id ? (string) \get_permalink( $event_id ) : \home_url(),
            'event_id'      => (string) (int) $event_id,
        ];
    }

    /**
     * The call-to-action buttons for one email, per event.
     *
     * Two slots. Slot 1 is the button the template has always rendered; slot 2
     * is a second, optional one. Each is a label and a URL, and a button only
     * renders when it has both.
     *
     * "No meta" and "empty meta" mean different things on purpose. An event
     * that has never been through this panel has no meta, and keeps the caller's
     * defaults — for slot 1 that is "View event details" pointing at the event,
     * exactly what every existing email already sends. An author who clears the
     * field saves an empty string, which is a deliberate "no button". Without
     * that distinction, adding these fields would have silently dropped the CTA
     * from every email on the site.
     *
     * @param int    $slot     1 or 2.
     * @param array  $defaults label/url to fall back to when the event has no meta.
     */
    public function get_email_cta( $event_id, $type, $slot, array $defaults = [] ) {
        $type   = \in_array( $type, self::EMAIL_TEMPLATE_TYPES, true ) ? $type : 'confirmation';
        $slot   = ( (int) $slot === 2 ) ? 2 : 1;
        $prefix = '_anchor_event_email_cta' . ( $slot === 2 ? '2' : '' ) . '_';

        // What the builder currently has typed, before any save — same contract
        // as get_email_field()'s override, so the preview shows the button the
        // author is editing rather than the one on disk.
        if ( \is_array( $this->preview_cta_override )
            && ( $this->preview_cta_override['type'] ?? '' ) === $type
            && isset( $this->preview_cta_override[ $slot ] ) ) {
            return $this->preview_cta_override[ $slot ];
        }

        $out = [];
        foreach ( [ 'label', 'url' ] as $field ) {
            $key   = $prefix . $field . '_' . $type;
            $saved = \get_post_meta( (int) $event_id, $key, true );
            // metadata_exists(), not a truthiness check: '' is a real answer here.
            $out[ $field ] = \metadata_exists( 'post', (int) $event_id, $key )
                ? (string) $saved
                : (string) ( $defaults[ $field ] ?? '' );
        }
        return $out;
    }

    /**
     * Persist the per-event sender identity.
     *
     * Empty is written, not skipped: an author clearing From email means "go
     * back to the site-wide one", and email_headers() reads an empty override
     * as exactly that.
     */
    private function save_email_sender_fields( $post_id, array $src ) {
        if ( empty( $src['anchor_event_sender_present'] ) ) {
            return;
        }
        $fields = [
            'email_from_name'        => 'sanitize_text_field',
            'email_from_address'     => 'sanitize_email',
            'email_reply_to_address' => 'sanitize_email',
            'email_cc'               => 'list',
            'email_bcc'              => 'list',
        ];
        foreach ( $fields as $key => $clean ) {
            $form = 'anchor_event_' . $key;
            if ( ! isset( $src[ $form ] ) ) {
                continue;
            }
            $raw   = \wp_unslash( $src[ $form ] );
            $value = ( $clean === 'list' )
                ? \implode( ', ', $this->email_address_list( $raw ) )
                : \call_user_func( '\\' . $clean, $raw );
            // wp_slash(): see persist_event_authoring()'s docblock — a From
            // name is free text and may legitimately contain a backslash.
            \update_post_meta( $post_id, '_anchor_event_' . $key, \wp_slash( $value ) );
        }
    }

    /**
     * Persist the CTA label/URL pairs posted by the email builder.
     *
     * REG-D26 — a value equal to the resolved default is DELETED, not written.
     * The builder shows the default (a virtual event's room link, the event
     * permalink) as the field's placeholder, but the browser posts whatever is
     * in the box, and an author who typed over nothing still posts the default
     * back on the first save of any other field. Writing it froze a live
     * default into meta: change the event's Zoom URL afterwards and every email
     * still linked to the old room. Deleting also thaws the events that were
     * frozen before this fix, on their next save.
     *
     * Empty is still written when it DIFFERS from the default: that is the
     * author clearing the field, which get_email_cta() reads as "deliberately
     * no button".
     */
    private function save_email_cta_fields( $post_id, array $src ) {
        // Only when the builder was actually on the page, so a save from any
        // other form cannot blank a button it never rendered.
        if ( empty( $src['anchor_event_email_cta_present'] ) ) {
            return;
        }
        foreach ( self::EMAIL_TEMPLATE_TYPES as $type ) {
            foreach ( [ 1, 2 ] as $slot ) {
                $prefix   = '_anchor_event_email_cta' . ( $slot === 2 ? '2' : '' ) . '_';
                $form     = 'anchor_event_email_cta' . ( $slot === 2 ? '2' : '' ) . '_';
                $defaults = $this->email_cta_defaults( $post_id, $slot );
                foreach ( [ 'label' => 'sanitize_text_field', 'url' => 'esc_url_raw' ] as $field => $clean ) {
                    $key = $form . $field . '_' . $type;
                    if ( ! isset( $src[ $key ] ) ) {
                        continue;
                    }
                    $value    = (string) \call_user_func( '\\' . $clean, \wp_unslash( $src[ $key ] ) );
                    $meta_key = $prefix . $field . '_' . $type;

                    if ( ! $this->cta_field_is_authored( $post_id, $meta_key, $value ) ) {
                        // An untouched placeholder — leave the field unset so the
                        // default keeps resolving. See cta_field_is_authored().
                        continue;
                    }

                    if ( $value === (string) ( $defaults[ $field ] ?? '' ) ) {
                        // Still the default — keep it a default, so it follows
                        // the event instead of pinning it to today's value.
                        \delete_post_meta( $post_id, $meta_key );
                        continue;
                    }
                    // wp_slash(): see persist_event_authoring()'s docblock.
                    // The default comparison above deliberately uses the
                    // UNSLASHED $value — it is comparing meaning, not bytes.
                    \update_post_meta( $post_id, $meta_key, \wp_slash( $value ) );
                }
            }
        }
    }

    /**
     * Did the author actually put something in this CTA field? (REG-D26)
     *
     * The one predicate behind BOTH the save path and the live preview, because
     * the builder renders the default as the field's PLACEHOLDER: an untouched
     * field posts ''. On save, writing that would read as "deliberately no
     * button" and silently drop the CTA from every email; in the preview,
     * honouring it shows no button where the send would put one — and an author
     * who believes the preview will re-type the default into the field, which is
     * exactly the freeze this fix removed. Both surfaces have to answer the
     * question the same way, so they ask it here.
     *
     * metadata_exists() is the discriminator, the same one get_email_cta() uses
     * to tell "no meta" from "empty meta": clearing a field that HAS a value is
     * still a deliberate "no button".
     *
     * @param int    $event_id
     * @param string $meta_key The field's `_anchor_event_email_cta*_{field}_{type}` key.
     * @param string $value    The posted, sanitized value.
     * @return bool
     */
    private function cta_field_is_authored( $event_id, $meta_key, $value ) {
        if ( (string) $value !== '' ) {
            return true;
        }
        return \metadata_exists( 'post', (int) $event_id, (string) $meta_key );
    }

    /**
     * The default CTA pair one builder field falls back to (REG-D26).
     *
     * One resolver for the field's placeholder and for the save path's
     * "is this still the default?" test — if those two disagreed, the builder
     * would show one thing and freeze another.
     *
     * @param int $event_id
     * @param int $slot     1 or 2.
     * @return array{label:string,url:string}
     */
    private function email_cta_defaults( $event_id, $slot ) {
        // Slot 2 has never had a default — it is the optional second button.
        return ( (int) $slot === 2 )
            ? [ 'label' => '', 'url' => '' ]
            : $this->default_email_cta( (int) $event_id );
    }

    /**
     * The button an event gets when nobody has set one.
     *
     * Virtual events point at the room, everything else at the event page. Kept
     * in one place because the builder's field and the renderer must agree —
     * if they drifted, the preview would show a different button from the one
     * that sends.
     *
     * @param array $fallback Caller's own label/url, used for a non-virtual event.
     */
    private function default_email_cta( $event_id, array $fallback = [] ) {
        $meta = $event_id ? $this->get_meta( (int) $event_id ) : [];
        if ( ! empty( $meta['virtual'] ) && ! empty( $meta['virtual_url'] ) ) {
            return [
                'label' => __( 'Join the event', 'anchor-schema' ),
                'url'   => (string) $meta['virtual_url'],
            ];
        }
        return [
            'label' => (string) ( $fallback['label'] ?? __( 'View event details', 'anchor-schema' ) ),
            'url'   => (string) ( $fallback['url'] ?? ( $event_id ? \get_permalink( $event_id ) : \home_url() ) ),
        ];
    }

    private function documented_email_tokens() {
        return $this->template_email_tokens();
    }

    /** Human labels for the Emails builder's outer (email-type) tab bar. */
    private function email_type_labels() {
        return [
            'confirmation' => __( 'Confirmation', 'anchor-schema' ),
            'reminder'     => __( 'Reminder', 'anchor-schema' ),
            'cancellation' => __( 'Cancellation', 'anchor-schema' ),
            'roster'       => __( 'Roster digest', 'anchor-schema' ),
        ];
    }

    /**
     * "Emails" metabox (Task 3.2). Four tabs (one per EMAIL_TEMPLATE_TYPES
     * entry), each a Monaco HTML editor (Anchor_Monaco wrapper contract,
     * cloned from anchor-blocks) pre-filled via resolve_email_template() —
     * the per-event override if one exists, else the effective global/
     * default template — plus a token-insert palette, a raw client-side
     * live preview iframe, a "Preview with real data" AJAX button, and a
     * "Reset to default" button. Persistence is save_email_templates(), a
     * dedicated validated path called from save_meta() — never the generic
     * $input allow-list loop.
     *
     * @param \WP_Post $post
     */
    public function render_email_builder_metabox( $post ) {
        $labels = $this->email_type_labels();
        $tokens = $this->documented_email_tokens();
        ?>
        <div class="anchor-email-builder">
            <p class="description">
                <?php echo esc_html__( 'Customize the HTML sent for this event only. Leave a tab untouched (or use "Reset to default") to keep using the site-wide template for that email type.', 'anchor-schema' ); ?>
            </p>
            <?php // REG-D49 — this metabox renders the four templates and nothing else.
                  // The save path now persists every email field (save_meta() and the
                  // console share persist_event_authoring()), so anything authored in the
                  // console survives a save here untouched — but the inputs for those
                  // fields live on the console, and this says so instead of leaving an
                  // administrator to guess they do not exist. ?>
            <p class="description">
                <?php echo esc_html__( 'Subject lines, opening lines, the on/off switch, the buttons, the sender identity and the attendee questions are edited in the Events Manager console. Saving here leaves all of them exactly as the console set them.', 'anchor-schema' ); ?>
            </p>
            <div class="anchor-email-tabs">
                <?php $first = true; foreach ( $labels as $type => $label ) : ?>
                    <button type="button" class="anchor-email-tab<?php echo $first ? ' is-active' : ''; ?>" data-email-type="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></button>
                    <?php $first = false; ?>
                <?php endforeach; ?>
            </div>
            <?php $first = true; foreach ( $labels as $type => $label ) : ?>
                <div class="anchor-email-panel" data-email-type="<?php echo esc_attr( $type ); ?>"<?php echo $first ? '' : ' style="display:none;"'; ?>>
                    <div class="anchor-email-columns">
                        <div class="anchor-email-editor-col">
                            <div class="anchor-email-token-palette">
                                <span class="anchor-email-token-label"><?php echo esc_html__( 'Insert token:', 'anchor-schema' ); ?></span>
                                <?php foreach ( $tokens as $token ) : ?>
                                    <button type="button" class="button button-small anchor-email-token" data-token="<?php echo esc_attr( '{' . $token . '}' ); ?>">{<?php echo esc_html( $token ); ?>}</button>
                                <?php endforeach; ?>
                            </div>
                            <?php $email_template = $this->resolve_email_template( $type, $post->ID ); ?>
                            <div class="anchor-monaco" data-anchor-monaco='<?php echo esc_attr( wp_json_encode( [
                                [ 'id' => 'anchor_email_tpl_' . $type, 'label' => __( 'HTML', 'anchor-schema' ), 'lang' => 'html' ],
                            ] ) ); ?>'>
                                <label for="anchor_email_tpl_<?php echo esc_attr( $type ); ?>" class="screen-reader-text"><?php echo esc_html( $label ); ?></label>
                                <textarea id="anchor_email_tpl_<?php echo esc_attr( $type ); ?>" name="anchor_email_tpl_<?php echo esc_attr( $type ); ?>" rows="18" class="widefat code"><?php echo esc_textarea( $email_template ); ?></textarea>
                            </div>
                            <?php if ( ! $this->template_uses_brand_tokens( $email_template ) ) : ?>
                                <?php
                                /**
                                 * REG-D27 — same warning as the front-end builder's HTML
                                 * view, for the same reason: this template opts into none
                                 * of the appearance tokens, so the colours and logo set in
                                 * Settings reach it only if it still carries the stock
                                 * literal colours. One warning per surface beats branding
                                 * that silently applies to nothing.
                                 */
                                ?>
                                <p class="description anchor-event-email-appearance-warning">
                                    <?php echo esc_html__( 'This email uses its own HTML, so the colours and logo set in Settings may not reach it. Use the {brand_bg}, {brand_surface}, {brand_heading}, {brand_text}, {brand_button}, {brand_button_text} and {logo} tokens to opt back in.', 'anchor-schema' ); ?>
                                </p>
                            <?php endif; ?>
                            <p>
                                <button type="button" class="button anchor-email-preview-real" data-email-type="<?php echo esc_attr( $type ); ?>"><?php echo esc_html__( 'Preview with real data', 'anchor-schema' ); ?></button>
                                <button type="button" class="button anchor-email-reset" data-email-type="<?php echo esc_attr( $type ); ?>"><?php echo esc_html__( 'Reset to default', 'anchor-schema' ); ?></button>
                            </p>
                        </div>
                        <div class="anchor-email-preview-col">
                            <p class="description"><?php echo esc_html__( 'Live preview (tokens shown literally until you click "Preview with real data").', 'anchor-schema' ); ?></p>
                            <iframe class="anchor-email-preview-frame" data-email-type="<?php echo esc_attr( $type ); ?>"></iframe>
                        </div>
                    </div>
                </div>
                <?php $first = false; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Task 3.3 — renders the read-only "upcoming sends" panel. Pulls its data
     * exclusively from compute_email_schedule() (unit-tested separately) and
     * escapes everything on the way out; this method has no side effects and
     * offers no send/reschedule controls — it is purely informational.
     *
     * @param \WP_Post $post
     */
    public function render_upcoming_sends_metabox( $post ) {
        $schedule = $this->compute_email_schedule( (int) $post->ID );
        $notices  = [
            'invalid'      => __( 'This event could not be loaded.', 'anchor-schema' ),
            'group_parent' => __( 'Sends are scheduled per date — see each date\'s event for its own reminder/roster schedule.', 'anchor-schema' ),
            'disabled'     => __( 'Reminders and the roster digest are both off. Enable them in Settings › Anchor Tools › Events to schedule sends for this event.', 'anchor-schema' ),
            'no_start'     => __( 'Set a start date/time for this event to see its send schedule.', 'anchor-schema' ),
        ];

        echo '<div class="anchor-upcoming-sends">';

        if ( $schedule['notice'] !== '' && isset( $notices[ $schedule['notice'] ] ) ) {
            echo '<p class="description">' . esc_html( $notices[ $schedule['notice'] ] ) . '</p>';
            echo '</div>';
            return;
        }

        if ( empty( $schedule['rows'] ) ) {
            echo '<p class="description">' . esc_html__( 'No sends are currently scheduled for this event.', 'anchor-schema' ) . '</p>';
            echo '</div>';
            return;
        }

        $type_labels = [
            'reminder' => __( 'Reminder', 'anchor-schema' ),
            'roster'   => __( 'Roster digest', 'anchor-schema' ),
        ];
        $state_labels = [
            'sent'      => __( 'Sent', 'anchor-schema' ),
            'scheduled' => __( 'Scheduled', 'anchor-schema' ),
            'past'      => __( 'Past — not sent', 'anchor-schema' ),
        ];
        $date_format = \get_option( 'date_format' ) . ' ' . \get_option( 'time_format' );

        echo '<ul class="anchor-upcoming-sends-list">';
        foreach ( $schedule['rows'] as $row ) {
            $type_label  = $type_labels[ $row['type'] ] ?? ucfirst( $row['type'] );
            $when        = \wp_date( $date_format, (int) $row['scheduled_ts'] );
            $state       = (string) $row['state'];
            $state_label = $state === 'partial'
                ? \sprintf(
                    /* translators: 1: number sent, 2: total confirmed. */
                    \__( 'Sent to %1$d of %2$d', 'anchor-schema' ),
                    (int) $row['sent_count'],
                    (int) $row['total_count']
                )
                : ( $state_labels[ $state ] ?? ucfirst( $state ) );

            echo '<li class="anchor-upcoming-send anchor-upcoming-send-' . esc_attr( $state ) . '">';
            echo '<strong>' . esc_html( $type_label ) . '</strong> ';
            echo '<span class="anchor-upcoming-send-when">' . esc_html( $when ) . '</span><br />';
            echo '<span class="anchor-upcoming-send-recipient">' . esc_html( $row['recipient'] ) . '</span> — ';
            echo '<span class="anchor-upcoming-send-state">' . esc_html( $state_label ) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    /**
     * Task 3.2 — dedicated, validated per-event email-template save path.
     * Deliberately NOT part of save_meta()'s generic $input allow-list loop
     * (matching the sanitize_external_embed()/persist_group_authoring()
     * pattern already used for other engine/UI-owned fields): every posted
     * value here goes through an email-safe wp_kses() allowlist before it is
     * ever written, and content that matches the event's override-less
     * resolved default (the default constant — i.e.
     * resolve_email_template( $type, 0 )) is stored as '' instead of a
     * redundant literal copy, exactly like clicking "Reset to default" and
     * saving without further edits would produce. Called from save_meta()
     * AFTER that method's own nonce + DOING_AUTOSAVE + edit_post cap checks —
     * this method assumes the caller already gated the request.
     *
     * Task 28 — $src (a raw, NOT-yet-unslashed $_POST-shaped array) is passed
     * in rather than read off $_POST here, so this saver takes the same input
     * as its five siblings and persist_event_authoring() can hand all six the
     * one array both save surfaces built.
     *
     * @param int   $post_id
     * @param array $src     Raw input array ($_POST-shaped, still slashed).
     */
    private function save_email_templates( $post_id, array $src ) {
        foreach ( self::EMAIL_TEMPLATE_TYPES as $type ) {
            $field = 'anchor_email_tpl_' . $type;
            if ( ! isset( $src[ $field ] ) ) {
                continue; // Form not submitted for this type — leave existing meta untouched.
            }
            // REG-D25 — the doctype is not part of a template (it is emitted on
            // assembly), so drop it before the "is this still the default?"
            // comparison as well; otherwise a template that is the default plus
            // a pasted doctype would be stored as a redundant literal override.
            $raw      = self::strip_email_doctype( (string) \wp_unslash( $src[ $field ] ) );
            $fallback = $this->resolve_email_template( $type, 0 ); // default constant, no per-event lookup.

            if ( \trim( $raw ) === \trim( $fallback ) ) {
                // Unmodified (or explicitly reset by the JS "Reset to default"
                // button, which writes the same fallback text into the editor
                // before submit) — store no override.
                \update_post_meta( $post_id, '_anchor_event_email_tpl_' . $type, '' );
                continue;
            }

            // wp_slash(): update_post_meta() unslashes what it is given, so an
            // already-unslashed template would lose every CSS escape and every
            // literal backslash in it. See persist_event_authoring()'s docblock.
            \update_post_meta( $post_id, '_anchor_event_email_tpl_' . $type, \wp_slash( $this->sanitize_email_template_html( $raw ) ) );
        }
    }

    /**
     * Email-safe wp_kses() sanitizer for per-event/global custom email
     * template HTML (Task 3.2). Mirrors sanitize_external_embed()'s
     * filterable-allowlist approach (Task 1.1). `{token}` braces are plain
     * text to wp_kses and pass through untouched; `<script>` is off the
     * default allowlist so it — and any other disallowed tag — is stripped
     * entirely, open tag/body/close tag, with no extra regex needed.
     *
     * @param string $tpl
     * @return string
     */
    private function sanitize_email_template_html( $tpl ) {
        // REG-D25 — the allowlist below cannot express a doctype declaration, so
        // wp_kses() deletes one silently and every saved template permanently
        // loses it. Drop it here deliberately instead: the doctype is emitted
        // once, on assembly, by build_registration_email_html().
        $tpl = self::strip_email_doctype( (string) $tpl );

        // REG-D27 — safecss_filter_attr() rejects any declaration containing
        // "}", which is every brand token: `background:{brand_bg}` would be
        // stripped from the style attribute on save and the palette could never
        // reach a custom template. Re-test the declaration with the `{token}`
        // placeholders removed; braces cannot terminate an attribute, and an
        // unknown token survives expansion as inert text.
        $allow_tokens = function ( $allow_css, $css_test_string ) {
            if ( $allow_css ) {
                return $allow_css;
            }
            // Only ever RE-runs safecss_filter_attr()'s own verdict on a string
            // with the `{token}` placeholders removed — it never widens what
            // that check permits. The pattern below is a VERBATIM COPY of the
            // one in safecss_filter_attr() (wp-includes/kses.php, "Disallow CSS
            // containing \ ( & } = or comments"); if WordPress tightens it, copy
            // the new one here. Anything that check rejects for a reason other
            // than our braces — url(javascript:…), expression(), a stray `}`,
            // a comment — is still rejected, and the filter is removed again
            // before this method returns, so no other kses() call is affected.
            $stripped = \preg_replace( '/\{[a-z0-9_]+\}/', '', (string) $css_test_string );
            return \is_string( $stripped ) && 0 === \preg_match( '%[\\\(&=}]|/\*%', $stripped );
        };

        \add_filter( 'safecss_filter_attr_allow_css', $allow_tokens, 10, 2 );
        try {
            return (string) \wp_kses( $tpl, $this->get_email_template_allowed_html() );
        } finally {
            \remove_filter( 'safecss_filter_attr_allow_css', $allow_tokens, 10 );
        }
    }

    /**
     * Remove a leading `<!DOCTYPE ...>` declaration (REG-D25).
     *
     * One source of truth for the doctype: it is never stored in a template
     * (kses cannot keep it) and never carried by the default shell — it is
     * added back by email_document_html() when the assembled email is a full
     * HTML document.
     *
     * @param string $html
     * @return string
     */
    private static function strip_email_doctype( $html ) {
        // A UTF-8 BOM ahead of the declaration is tolerated (and dropped with
        // it): an editor that saves the template as "UTF-8 with BOM" would
        // otherwise leave the doctype unmatched here and un-stripped, and the
        // assembled email would carry two.
        $out = \preg_replace( '/^(?:\xEF\xBB\xBF)?\s*<!doctype[^>]*>[ \t]*(?:\r\n|\n|\r)?/i', '', (string) $html );
        return \is_string( $out ) ? $out : (string) $html;
    }

    /**
     * Emit the doctype for an assembled email (REG-D25).
     *
     * Only for a template that is a whole document: a fragment override (a
     * bare `<div>`, or a single `{detail_rows}`) is not a document and must
     * not grow a doctype it never had.
     *
     * @param string $html
     * @return string
     */
    private static function email_document_html( $html ) {
        $html = self::strip_email_doctype( $html );
        if ( ! \preg_match( '/<html\b/i', $html ) ) {
            return $html;
        }
        // A BOM the template arrived with must not end up BETWEEN the doctype
        // and the document; strip_email_doctype() already drops one that sat in
        // front of a declaration, this covers a template that never had one.
        $html = \preg_replace( '/^\xEF\xBB\xBF/', '', $html );
        return "<!DOCTYPE html>\n" . $html;
    }

    /**
     * Allowlisted tags/attributes for custom email template HTML,
     * filterable so sites can extend it for their own email markup needs.
     * Covers the shell markup used by default_email_shell() (html/head/
     * meta/title/body/table structure) plus common email-safe formatting
     * tags. `style` is allowed on the block-level elements that carry it in
     * the shipped shell — wp_kses further restricts the VALUE of any
     * allowed `style` attribute to WordPress's safe-CSS property list
     * (safecss_filter_attr()), so this is not an escape hatch for arbitrary
     * CSS/JS.
     *
     * @return array wp_kses() allowed_html array.
     */
    private function get_email_template_allowed_html() {
        $default_allowed = [
            'html'   => [],
            'head'   => [],
            'meta'   => [ 'charset' => true, 'name' => true, 'content' => true ],
            'title'  => [],
            'body'   => [ 'style' => true ],
            'table'  => [ 'role' => true, 'width' => true, 'cellpadding' => true, 'cellspacing' => true, 'style' => true, 'align' => true, 'border' => true ],
            'thead'  => [],
            'tbody'  => [],
            'tr'     => [ 'style' => true ],
            'td'     => [ 'style' => true, 'align' => true, 'valign' => true, 'width' => true, 'colspan' => true ],
            'th'     => [ 'style' => true, 'align' => true, 'valign' => true, 'width' => true, 'colspan' => true ],
            'div'    => [ 'style' => true, 'class' => true, 'id' => true, 'align' => true ],
            'span'   => [ 'style' => true, 'class' => true, 'id' => true ],
            'p'      => [ 'style' => true, 'class' => true, 'align' => true ],
            'br'     => [],
            'hr'     => [ 'style' => true ],
            'h1'     => [ 'style' => true ],
            'h2'     => [ 'style' => true ],
            'h3'     => [ 'style' => true ],
            'h4'     => [ 'style' => true ],
            'a'      => [ 'href' => true, 'style' => true, 'target' => true, 'rel' => true, 'class' => true, 'id' => true ],
            'img'    => [ 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'style' => true, 'class' => true ],
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'ul'     => [ 'style' => true ],
            'ol'     => [ 'style' => true ],
            'li'     => [ 'style' => true ],
        ];

        /**
         * Filter the wp_kses() allowlist used to sanitize per-event/global
         * custom email template HTML on save (Task 3.2) and on the
         * "Preview with real data" AJAX endpoint.
         *
         * @param array $default_allowed wp_kses() allowed_html array.
         */
        return \apply_filters( 'anchor_events_email_template_allowed_html', $default_allowed );
    }

    /**
     * Task 3.2 — representative $ctx for the "Preview with real data" AJAX
     * endpoint (ajax_email_preview()). Built the same way each live sender
     * (send_registration_emails()/send_reminder_email()/
     * send_cancellation_email()/send_roster_email()) builds its own $ctx —
     * real event data, real settings copy — but substitutes a synthetic
     * "Sample Attendee" seat when the event has no real confirmed
     * registrant yet, so a brand-new event still previews something
     * meaningful. Preview-only: never sends, never persists.
     *
     * @param int    $event_id
     * @param string $type One of EMAIL_TEMPLATE_TYPES.
     * @return array $ctx shape consumed by build_registration_email_html().
     */
    private function build_preview_ctx( $event_id, $type ) {
        $event_id = (int) $event_id;
        $settings = $this->get_settings();

        $seat = null;
        if ( $this->registrations ) {
            $seats = $this->registrations->query_seats( [
                'event_id' => $event_id,
                'status'   => \Anchor\Events\Registrations::STATUS_CONFIRMED,
                'per_page' => 1,
            ] );
            $seat = $seats['items'][0] ?? null;
        }
        $sample_seat = $seat ?: [
            'name'   => __( 'Sample Attendee', 'anchor-schema' ),
            'email'  => 'sample@example.test',
            'status' => \Anchor\Events\Registrations::STATUS_CONFIRMED,
        ];

        $tokens = $this->email_tokens( [ 'event_id' => $event_id, 'seat' => $sample_seat ] );

        // Fill in whatever this event genuinely has no value for, so every
        // token in the palette shows what it produces instead of a gap. Set
        // here and nowhere else; no send path reaches this method.
        $this->preview_samples = true;

        // The subject and opening lines are expanded against $tokens a few
        // lines below — BEFORE build_registration_email_html() gets a chance to
        // apply the same stand-ins to the template. Without this, a token typed
        // into the body still resolved to nothing while the identical token in
        // the template resolved to a sample.
        foreach ( $this->preview_sample_scalars( $event_id ) as $key => $sample ) {
            if ( isset( $tokens[ $key ] ) && \trim( (string) $tokens[ $key ] ) === '' ) {
                $tokens[ $key ] = $sample;
            }
        }

        switch ( $type ) {
            case 'reminder':
                $intro       = $this->expand_email_tokens( $this->get_email_field( $event_id, 'reminder', 'intro', $settings['reminder_intro'] ), $tokens );
                $detail_rows = [];
                if ( $tokens['event_date'] !== '' ) { $detail_rows[] = [ 'label' => __( 'Date', 'anchor-schema' ), 'value' => $tokens['event_date'] ]; }
                if ( $tokens['event_time'] !== '' ) { $detail_rows[] = [ 'label' => __( 'Time', 'anchor-schema' ), 'value' => $tokens['event_time'] ]; }
                if ( $tokens['venue'] !== '' ) { $detail_rows[] = [ 'label' => __( 'Location', 'anchor-schema' ), 'value' => $tokens['venue'] ]; }
                return [
                    'event_id'      => $event_id,
                    'name'          => (string) $sample_seat['name'],
                    'status'        => \Anchor\Events\Registrations::STATUS_CONFIRMED,
                    'intro_message' => $intro,
                    'detail_rows'   => $detail_rows,
                    'cta_label'     => __( 'View event details', 'anchor-schema' ),
                    'cta_url'       => $tokens['event_url'],
                    'type'          => 'reminder',
                ];

            case 'cancellation':
                $intro       = $this->expand_email_tokens( $this->get_email_field( $event_id, 'cancellation', 'intro', $settings['cancellation_intro'] ), $tokens );
                $detail_rows = [ [ 'label' => __( 'Event', 'anchor-schema' ), 'value' => $tokens['event_title'] ] ];
                if ( $tokens['event_date'] !== '' ) { $detail_rows[] = [ 'label' => __( 'Date', 'anchor-schema' ), 'value' => $tokens['event_date'] ]; }
                return [
                    'event_id'      => $event_id,
                    'name'          => (string) $sample_seat['name'],
                    'status'        => \Anchor\Events\Registrations::STATUS_CANCELLED,
                    'intro_message' => $intro,
                    'detail_rows'   => $detail_rows,
                    'cta_label'     => '',
                    'cta_url'       => '',
                    'type'          => 'cancellation',
                ];

            case 'roster':
                $summary   = $this->registrations ? $this->registrations->get_event_summary( $event_id ) : [];
                $cap       = isset( $summary['capacity'] ) ? (int) $summary['capacity'] : 0;
                $remaining = isset( $summary['remaining'] ) && (int) $summary['remaining'] >= 0
                    ? (string) (int) $summary['remaining']
                    : __( 'Unlimited', 'anchor-schema' );
                $intro       = $this->expand_email_tokens( $this->get_email_field( $event_id, 'roster', 'intro', $settings['roster_intro'] ), $tokens );
                $detail_rows = [
                    [ 'label' => __( 'Date', 'anchor-schema' ), 'value' => $tokens['event_date'] ],
                    [ 'label' => __( 'Venue', 'anchor-schema' ), 'value' => $tokens['venue'] ],
                    [ 'label' => __( 'Capacity', 'anchor-schema' ), 'value' => $cap ? (string) $cap : __( 'Unlimited', 'anchor-schema' ) ],
                    [ 'label' => __( 'Confirmed', 'anchor-schema' ), 'value' => (string) (int) ( $summary['confirmed'] ?? ( $seat ? 1 : 0 ) ) ],
                    [ 'label' => __( 'Waitlist', 'anchor-schema' ), 'value' => (string) (int) ( $summary['waitlist'] ?? 0 ) ],
                    [ 'label' => __( 'Remaining', 'anchor-schema' ), 'value' => $remaining ],
                ];
                $seat_list = [ $sample_seat['name'] . ' — ' . $sample_seat['email'] ];
                return [
                    'event_id'      => $event_id,
                    'name'          => '',
                    'status'        => \Anchor\Events\Registrations::STATUS_CONFIRMED,
                    'intro_message' => $intro,
                    'detail_rows'   => $detail_rows,
                    'seat_list'     => $seat_list,
                    'cta_label'     => __( 'Open full roster', 'anchor-schema' ),
                    'cta_url'       => ( $this->roster && \method_exists( $this->roster, 'roster_url' ) )
                        ? $this->roster->roster_url( $event_id )
                        : \get_permalink( $event_id ),
                    'type'          => 'roster',
                ];

            default: // confirmation
                $intro = $this->expand_email_tokens( $this->get_email_field( $event_id, 'confirmation', 'intro', (string) ( $settings['confirmation_message'] ?? '' ) ), $tokens );
                return [
                    'event_id'      => $event_id,
                    'name'          => (string) $sample_seat['name'],
                    'status'        => \Anchor\Events\Registrations::STATUS_CONFIRMED,
                    'intro_message' => $intro,
                    'guests'        => 0,
                    // Was []. The real confirmation builds one row per event with
                    // its seat count (Anchor\Events\WooCommerce::send_customer_email),
                    // so an empty array here made {detail_rows} and {seat_list}
                    // look dead in the preview when they are not — the panel was
                    // showing less than the email actually sends.
                    'detail_rows'   => [ [
                        'label' => $tokens['event_title'],
                        'value' => \sprintf( \_n( '%d seat', '%d seats', 1, 'anchor-schema' ), 1 ),
                    ] ],
                    'seat_list'     => [ \sprintf( '%s (%s)', $sample_seat['name'], $tokens['event_title'] ) ],
                    'cta_label'     => __( 'View event details', 'anchor-schema' ),
                    'cta_url'       => $tokens['event_url'],
                    'type'          => 'confirmation',
                ];
        }
    }

    /**
     * Testable core of the "Preview with real data" AJAX endpoint (Task
     * 3.2) — deliberately factored OUT of ajax_email_preview() so PHPUnit
     * can exercise it directly without going through wp_send_json_success(),
     * which only routes through the test suite's catchable wp_die() when
     * wp_doing_ajax() is true; calling it outside a real admin-ajax.php
     * request otherwise ends the whole PHP process in a bare `die;` (see
     * wp_send_json()). Mirrors the same extraction Task 1.5 already used for
     * handle_event_manager_save()/save_event_manager_fields().
     *
     * Renders the given (possibly unsaved) template HTML through
     * build_registration_email_html() with a representative $ctx for this
     * event+type, expanding real tokens. Never sends anything, never
     * persists anything. $raw_template is run through the same email-safe
     * wp_kses() allowlist as the real save path before it is substituted in
     * — Task 3.1 already output-escapes every scalar token, so a malicious
     * attendee name can't inject via this endpoint either, but the posted
     * markup itself is still untrusted admin input.
     *
     * @param int    $event_id
     * @param string $type         One of EMAIL_TEMPLATE_TYPES (falls back to 'confirmation').
     * @param string $raw_template Unsanitized posted template HTML.
     * @return string Rendered email HTML.
     */
    public function render_email_preview_html( $event_id, $type, $raw_template ) {
        $type     = \in_array( $type, self::EMAIL_TEMPLATE_TYPES, true ) ? $type : 'confirmation';
        $template = $this->sanitize_email_template_html( (string) $raw_template );

        $ctx = $this->build_preview_ctx( $event_id, $type );

        $this->preview_template_override = [ 'type' => $type, 'html' => $template ];
        try {
            return $this->build_registration_email_html( $ctx );
        } finally {
            $this->preview_template_override = null;
            $this->preview_samples           = false;
        }
    }

    /**
     * AJAX: `wp_ajax_anchor_events_email_preview` — Task 3.2 "Preview with
     * real data". Thin nonce+capability-gated wrapper around
     * render_email_preview_html() (see that method's docblock for the
     * rendering contract and why the two are split).
     */
    public function ajax_email_preview() {
        \check_ajax_referer( 'anchor_events_email_preview', 'nonce' );

        $event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;
        if ( ! \current_user_can( 'edit_post', $event_id ) ) {
            \wp_send_json_error( 'forbidden', 403 );
        }

        $type         = isset( $_POST['type'] ) ? \sanitize_text_field( \wp_unslash( $_POST['type'] ) ) : 'confirmation';
        // The builder sends the template base64-encoded: a security plugin sees raw
        // HTML in a POST field and blocks the request with its own 403 before
        // WordPress runs. Decoding here changes nothing about trust — the value
        // still goes through sanitize_email_template_html() below.
        if ( isset( $_POST['template_b64'] ) ) {
            $decoded      = \base64_decode( (string) \wp_unslash( $_POST['template_b64'] ), true );
            $raw_template = ( $decoded === false ) ? '' : $decoded;
        } else {
            $raw_template = isset( $_POST['template'] ) ? (string) \wp_unslash( $_POST['template'] ) : '';
        }

        // The builder sends the subject/intro currently in its fields so the panel
        // shows what the email would say right now, not what was last saved.
        $this->preview_field_override = [
            'type'    => $type,
            'subject' => isset( $_POST['subject'] ) ? \sanitize_text_field( \wp_unslash( $_POST['subject'] ) ) : null,
            'intro'   => isset( $_POST['intro'] ) ? \wp_kses_post( \wp_unslash( $_POST['intro'] ) ) : null,
            'preheader' => isset( $_POST['preheader'] ) ? \sanitize_text_field( \wp_unslash( $_POST['preheader'] ) ) : null,
        ];
        $this->preview_field_override = \array_filter( $this->preview_field_override, function ( $v ) { return $v !== null; } );

        // REG-D26 — the same authored/not-authored rule the save path applies, so
        // the preview shows the button that would actually send. The builder now
        // renders the default as a placeholder, so an untouched field posts '';
        // taking that literally previewed NO button while the send had one, and
        // the author's fix for that is to re-type the default into the field —
        // re-freezing the very default this task unfroze.
        $cta_override = [ 'type' => $type ];
        foreach ( [ 1 => '', 2 => '2' ] as $slot => $suffix ) {
            $label_key = 'cta' . $suffix . '_label';
            $url_key   = 'cta' . $suffix . '_url';
            if ( ! isset( $_POST[ $label_key ] ) && ! isset( $_POST[ $url_key ] ) ) {
                continue;
            }
            $posted = [
                'label' => \sanitize_text_field( \wp_unslash( $_POST[ $label_key ] ?? '' ) ),
                'url'   => \esc_url_raw( \wp_unslash( $_POST[ $url_key ] ?? '' ) ),
            ];
            // What this slot resolves to with nothing posted — stored meta, else
            // the same default the builder printed as the placeholder. Read now,
            // while preview_cta_override is still null, so get_email_cta() answers
            // from the event rather than from the override being built.
            $resolved = $this->get_email_cta( $event_id, $type, $slot, $this->email_cta_defaults( $event_id, $slot ) );

            $pair = [];
            foreach ( [ 'label', 'url' ] as $field ) {
                $meta_key = '_anchor_event_email_cta' . $suffix . '_' . $field . '_' . $type;
                $pair[ $field ] = $this->cta_field_is_authored( $event_id, $meta_key, $posted[ $field ] )
                    ? $posted[ $field ]
                    : (string) ( $resolved[ $field ] ?? '' );
            }
            $cta_override[ $slot ] = $pair;
        }
        $this->preview_cta_override = ( \count( $cta_override ) > 1 ) ? $cta_override : null;

        try {
            $html = $this->render_email_preview_html( $event_id, $type, $raw_template );
        } finally {
            $this->preview_field_override = null;
            $this->preview_cta_override   = null;
        }

        \wp_send_json_success( [ 'html' => $html ] );
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
            'sold_out' => ! empty( $_POST['anchor_event_sold_out'] ),
            // Task BC: `registration_type`/`registration_url` are intentionally
            // NOT in this allow-list — the metabox no longer renders those
            // legacy fields (superseded by registration_mode/external_url
            // below), and this save loop writes every key present here via
            // update_post_meta() regardless of whether $_POST carries it. Had
            // they stayed listed, their absence from $_POST would sanitize to
            // 'internal'/'' and silently BLANK an old external event's real
            // link on its next re-save. Leaving them out of $input means this
            // save path never touches those two legacy keys at all — old
            // events keep whatever they already have, and external_url()/
            // get_meta() still read them as a fallback (see those methods).
            'price' => sanitize_text_field( $_POST['anchor_event_price'] ?? '' ),
            'hide_from_archive' => ! empty( $_POST['anchor_event_hide_from_archive'] ),
            'featured' => ! empty( $_POST['anchor_event_featured'] ),
            'priority' => (int) ( $_POST['anchor_event_priority'] ?? 0 ),
            'gallery' => $this->sanitize_gallery_ids( $_POST['anchor_event_gallery'] ?? '' ),
            'reminder_offsets' => $this->sanitize_offset_csv( $_POST['anchor_event_reminder_offsets'] ?? '' ),
            'labels' => $this->labels_input( $_POST ),
        ];

        // Event-type / registration-mode authoring UI (Task 1.3+1.4, front-end
        // parity in Task 1.5). Occurrence only — offering/recurring get a
        // placeholder note in both forms; no seats/capacity/tiers/product logic
        // here. Shared with handle_event_manager_save() so the two save paths
        // can never drift on how these six keys are sanitized.
        $input = array_merge( $input, $this->sanitize_event_type_input( $_POST, $current_registration_mode ) );

        if ( ! $input['start_date'] ) {
            $this->queue_group_notice( 'missing_start_date', $post_id );
        }

        $status_raw = sanitize_text_field( $_POST['anchor_event_status'] ?? 'auto' );
        if ( $status_raw === 'auto' ) {
            $input['status_mode'] = 'auto';
            $input['status'] = $this->calculate_status( $input );
        } else {
            $input['status_mode'] = 'manual';
            // A value that is not an offered choice — including the retired
            // 'draft' (MODEL-D19) — falls back to what the DATES say rather
            // than to a hardcoded 'upcoming', so a dateless event does not
            // silently claim to be upcoming.
            $input['status'] = in_array( $status_raw, array_keys( $this->get_status_options() ), true )
                ? $status_raw
                : $this->calculate_status( $input );
        }

        // persist_timestamps() is the single writer for the derived rows: it
        // stamps `ts_version` alongside them, so a just-saved event is already
        // at the current schema version and backfill_timestamps() skips it.
        // The values are mirrored back into $input purely so the generic
        // update_post_meta() loop below (and this method's return value) still
        // carry them; the loop's writes are then no-ops.
        $timestamps = $this->persist_timestamps( $post_id, $input );
        $input['start_ts'] = $timestamps['start'];
        $input['end_ts'] = $timestamps['end'];

        foreach ( $input as $key => $value ) {
            \update_post_meta( $post_id, $this->meta_key( $key ), $value );
        }

        // Everything the two authoring surfaces share — tickets, the
        // auto-appended shortcode, email templates/wording/switches/CTA/sender,
        // attendee questions, group authoring and the cache flush — is ONE
        // function (MODEL-D24 / REG-D49). Before Task 28 this metabox path ran
        // a shorter list than the front-end console did, so five whole
        // families of field were silently dropped on every wp-admin save.
        $this->persist_event_authoring( $post_id, $_POST, $input );
    }

    /**
     * Task 28 (MODEL-D24 / REG-D49) — the shared tail of BOTH event save
     * surfaces: the wp-admin metabox (save_meta()) and the front-end Events
     * Manager console (save_event_manager_fields()).
     *
     * Until this existed the two paths ran different sub-saver lists — the
     * console persisted attendee questions, email wording, the per-type on/off
     * switches, the CTA pairs and the sender identity; the metabox persisted
     * none of them. A field authored on one surface was therefore invisible to
     * the other, and an administrator in wp-admin could not turn an event's
     * email off at all. Both paths now end here, so they cannot drift again.
     *
     * This is ORCHESTRATION ONLY. Every rule about what a field means lives in
     * the saver that owns it — placeholder-vs-default semantics in
     * save_email_cta_fields(), stable question keys in
     * save_registration_questions(), the kses allowlist in
     * save_email_templates() — and none of it is restated here.
     *
     * ORDER MATTERS, and is the console's pre-existing order preserved
     * verbatim: persist_group_authoring() is LAST because its reconcile copies
     * the parent's freshly-saved rows down onto the children, so everything
     * above it is an input to that copy. See persist_group_authoring()'s
     * docblock.
     *
     * $src is a raw, NOT-yet-unslashed input array shaped like $_POST — the
     * same shape both callers hold, and the shape every sub-saver already
     * expects (each does its own wp_unslash() at the point of use). Do NOT
     * unslash it here.
     *
     * THE SLASH CONTRACT, because it is counter-intuitive and it bit this
     * code: WordPress's meta setters take SLASHED input. add_metadata(),
     * update_metadata() and the by-value delete_metadata() each call
     * wp_unslash() on the value themselves (wp-includes/meta.php). So a saver
     * that unslashes the post and hands the sanitized result straight to
     * update_post_meta() has unslashed TWICE, and the second one destroys any
     * literal backslash the value legitimately contains —
     *   posted `C:\\path` -> unslash -> `C:\path` -> update_post_meta -> `C:path`.
     * Every saver below therefore does ONE wp_unslash() on the way in (it must:
     * wp_kses() and friends have to see the real characters) and wp_slash()es
     * the sanitized value back at the update_post_meta() call. wp_slash() maps
     * over arrays and leaves non-strings alone, so an array-valued meta (the
     * question repeater, ticket tiers, offering rows, labels) is slashed the
     * same way. Values that were NEVER unslashed — most of the callers'
     * $input allow-list, which feeds $_POST straight into sanitize_text_field()
     * — are already in the slashed domain and must NOT be slashed again.
     *
     * Callers gate the request themselves (save_meta() checks nonce +
     * DOING_AUTOSAVE + edit_post; handle_event_manager_save() checks its own
     * nonce + capability before calling save_event_manager_fields()); this
     * method assumes that has already happened.
     *
     * @param int   $post_id Event post ID, already inserted/updated.
     * @param array $src     Raw input array ($_POST-shaped, still slashed).
     * @param array $input   The sanitized meta the caller just wrote — read for
     *                       the auto-append shortcode decision and the event
     *                       `type` that drives group authoring.
     */
    private function persist_event_authoring( $post_id, array $src, array $input ) {
        // Ticket tiers (spec §3.2). The Ticket_Types model sanitizes the rows,
        // assigns stable ids, drops empty rows, and persists. An empty table
        // clears the meta so the legacy single `price` field stays the
        // implicit-primary fallback.
        $ticket_rows = isset( $src['anchor_event_tickets'] ) && is_array( $src['anchor_event_tickets'] )
            ? \wp_unslash( $src['anchor_event_tickets'] )
            : [];
        $this->ticket_types->save( $post_id, $ticket_rows );

        $this->maybe_append_registration_shortcode( $post_id, $input );

        // Task 3.2 — per-event lifecycle-email template overrides. Deliberately
        // NOT part of the callers' generic $input allow-list loop (see the
        // property docblock on the meta keys' register_post_meta() call); this
        // is the one dedicated, email-safe-kses-validated place they're written.
        $this->save_email_templates( $post_id, $src );

        // The five savers that used to be console-only (REG-D49). Each is a
        // no-op when its own fields are absent from $src — the three "…_present"
        // markers and save_email_fields()'s per-field isset() — so a surface
        // that does not render a given family never blanks it.
        $this->save_registration_questions( $post_id, $src );
        $this->save_email_fields( $post_id, $src );
        $this->save_email_switches( $post_id, $src );
        $this->save_email_cta_fields( $post_id, $src );
        $this->save_email_sender_fields( $post_id, $src );

        // Group authoring (offering_dates / recurrence / group_role) — Phase 2,
        // Task 2.3. Deliberately NOT part of the callers' generic $input
        // allow-list loop (see get_meta_schema()'s docblock on those keys);
        // this is the one dedicated, validated place they're written, and the
        // only place Occurrences::reconcile() is ever called from.
        //
        // LAST, after every other sub-saver: it reconciles, and the reconcile
        // copies the parent's rows down onto the children. See the ORDERING
        // note in persist_group_authoring()'s docblock.
        $this->persist_group_authoring( $post_id, $input['type'], $src );

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
     * hits wp_kses() in sanitize_external_embed() — never store it raw) and
     * the sanitized result is wp_slash()ed back before it is returned.
     *
     * That last step is not decoration: the returned array is merged straight
     * into both callers' $input and written by their generic
     * update_post_meta() loop, and update_post_meta() unslashes. Without it
     * these six keys would be unslashed TWICE and any literal backslash in
     * them — a CSS escape inside external_embed, a `C:\path` in a session
     * label — would be destroyed. Every other producer in that $input array
     * never unslashes at all, so the whole array is in one (slashed) domain.
     * See persist_event_authoring()'s docblock for the full contract.
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

        return \wp_slash( [
            'type' => $this->sanitize_event_type( \wp_unslash( $src['anchor_event_type'] ?? '' ) ),
            'registration_mode' => $this->sanitize_registration_mode( \wp_unslash( $src['anchor_event_registration_mode'] ?? '' ), $registration_mode_fallback ),
            'sessions' => $this->sanitize_sessions_rows( $sessions_raw ),
            'external_url' => esc_url_raw( \wp_unslash( $src['anchor_event_external_url'] ?? '' ) ),
            // Reuses the SAME wp_kses() allowlist sanitizer as the REST write
            // path (sanitize_external_embed()) so this field is never stored
            // raw regardless of which save path wrote it.
            'external_embed' => $this->sanitize_external_embed( \wp_unslash( $src['anchor_event_external_embed'] ?? '' ), $this->meta_key( 'external_embed' ), self::CPT ),
            'external_display_price' => sanitize_text_field( \wp_unslash( $src['anchor_event_external_display_price'] ?? '' ) ),
        ] );
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

    /* ══════════════════════════════════════════════════════════
       Event-level typed labels ("2 Day Course", "14 CE Credits", ...).
       ══════════════════════════════════════════════════════════ */

    /**
     * The fixed label vocabulary, key => display caption.
     *
     * Captions are resolved through this map at render time rather than stored
     * with the row, so they stay translatable — persisting a translated caption
     * would freeze it in whatever locale the author happened to save in.
     *
     * `custom` is the deliberate escape hatch: it carries an author-typed
     * caption on the row itself, so a site needing a label this list does not
     * anticipate never has to wait on a plugin release.
     *
     * @return array<string,string>
     */
    public function labels_vocabulary() {
        return \apply_filters(
            'anchor_events_labels_vocabulary',
            [
                'duration' => \__( 'Duration', 'anchor-schema' ),
                'credits'  => \__( 'CE Credits', 'anchor-schema' ),
                'format'   => \__( 'Format', 'anchor-schema' ),
                'level'    => \__( 'Level', 'anchor-schema' ),
                'custom'   => \__( 'Custom', 'anchor-schema' ),
            ]
        );
    }

    /**
     * Sanitize the labels repeater rows. Mirrors sanitize_sessions_rows()
     * exactly: plain text only (escaped on output, no trusted-HTML fields
     * here), malformed rows skipped, and a row missing its one required field
     * dropped rather than persisted blank.
     *
     * `value` is required — an empty or whitespace-only value is the labels
     * equivalent of a session row with no date. `key` is clamped to the
     * vocabulary so an unexpected value can never reach a CSS class name, and
     * `label` is retained only for `custom` rows.
     *
     * @param array $raw Raw anchor_event_labels[] rows from $_POST (already wp_unslash()ed).
     * @return array<int,array{key:string,label:string,value:string}>
     */
    private function sanitize_labels_rows( $raw ) {
        $vocabulary = $this->labels_vocabulary();
        $labels     = [];

        foreach ( (array) $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $value = \sanitize_text_field( $row['value'] ?? '' );
            $value = trim( $value );
            if ( $value === '' ) {
                continue;
            }

            $key = \sanitize_key( $row['key'] ?? '' );
            if ( ! isset( $vocabulary[ $key ] ) ) {
                $key = 'custom';
            }

            $labels[] = [
                'key'   => $key,
                // Only a custom row carries its own caption; a known key
                // resolves one from the vocabulary at render time.
                'label' => $key === 'custom' ? \sanitize_text_field( $row['label'] ?? '' ) : '',
                'value' => $value,
            ];
        }

        return $labels;
    }

    /**
     * Extract + sanitize the labels rows out of a raw, NOT-yet-unslashed input
     * array shaped like $_POST.
     *
     * Called by BOTH save paths — the admin metabox save_meta() and the
     * front-end manager form save_event_manager_fields() — for the same reason
     * sanitize_event_type_input() exists: so the two forms can never drift on
     * how these rows are read and sanitized.
     *
     * @param array $src Raw input array ($_POST-shaped).
     * @return array<int,array{key:string,label:string,value:string}>
     */
    private function labels_input( array $src ) {
        $raw = isset( $src['anchor_event_labels'] ) && is_array( $src['anchor_event_labels'] )
            ? \wp_unslash( $src['anchor_event_labels'] )
            : [];

        // wp_slash(): the result goes STRAIGHT into both save paths' generic
        // update_post_meta() loop, which unslashes what it is given. This is
        // one of only two producers in that $input array that unslash at all
        // (the other is sanitize_event_type_input()); everything else feeds
        // $_POST into a sanitizer without unslashing and is therefore already
        // in the slashed domain the loop expects. Re-slashing here keeps the
        // whole array in ONE domain. See persist_event_authoring()'s docblock.
        return \wp_slash( $this->sanitize_labels_rows( $raw ) );
    }

    /**
     * Every label row for an event, each with a resolved display `caption`.
     *
     * Occurrence children inherit `labels` from their parent —
     * Occurrences::INHERITED_KEYS names it explicitly, and
     * sync_shared_meta() copies the parent's row down (and removes the
     * child's when the parent has none). A "2 Day Course" describes each date
     * of a pick-one offering, so inheriting is the correct default.
     *
     * @param int $post_id
     * @return array<int,array{key:string,label:string,value:string,caption:string}>
     */
    public function get_labels( $post_id ) {
        $stored = \get_post_meta( (int) $post_id, $this->meta_key( 'labels' ), true );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        $vocabulary = $this->labels_vocabulary();
        $rows       = [];

        foreach ( $stored as $row ) {
            if ( ! is_array( $row ) || ( $row['value'] ?? '' ) === '' ) {
                continue;
            }
            $key = (string) ( $row['key'] ?? 'custom' );
            $rows[] = [
                'key'     => $key,
                'label'   => (string) ( $row['label'] ?? '' ),
                'value'   => (string) $row['value'],
                'caption' => $key === 'custom'
                    ? (string) ( $row['label'] ?? '' )
                    : (string) ( $vocabulary[ $key ] ?? '' ),
            ];
        }

        /**
         * Filter the resolved label rows for an event.
         *
         * @param array $rows    Rows of { key, label, value, caption }.
         * @param int   $post_id
         */
        return (array) \apply_filters( 'anchor_events_labels', $rows, (int) $post_id );
    }

    /**
     * The value of a single label by key, or '' when the event has none.
     *
     * Duplicate keys are permitted (the sanitizer does not dedupe, matching the
     * sessions repeater) and the first match wins.
     *
     * @param int    $post_id
     * @param string $key
     * @return string
     */
    public function get_label( $post_id, $key ) {
        foreach ( $this->get_labels( $post_id ) as $row ) {
            if ( $row['key'] === $key ) {
                return $row['value'];
            }
        }
        return '';
    }

    /* ══════════════════════════════════════════════════════════
       Group authoring: offering_dates / recurrence validated persist +
       Occurrences::reconcile() wiring (spec Phase 2, Task 2.3).
       ══════════════════════════════════════════════════════════ */

    /**
     * The single, DEDICATED, validated place `offering_dates` / `recurrence` /
     * `group_role` are ever written (Phase 2, Task 2.3). These three keys are
     * intentionally OUT of save_meta()'s / save_event_manager_fields()'s
     * generic $input allow-list (see get_meta_schema()'s docblock on them) —
     * exposing them there would let a REST write or an unrelated form field
     * silently clobber engine-owned state. Called from BOTH save paths
     * (save_meta() and save_event_manager_fields()) with the already-sanitized
     * `type`, so the two forms can never drift on this logic.
     *
     * VALIDATION GUARD (critical — see class docblock intro / spec): an
     * offering with zero valid dates, or a recurrence rule with neither
     * `count` nor `until`, is NEVER passed to reconcile(). A rule with no
     * terminator would expand to Occurrences::RECURRENCE_MAX_ROWS (104) rows;
     * silently reconciling it would create up to 104 child posts from one
     * save. On an invalid config the sanitized-but-incomplete input is still
     * PERSISTED (so the metabox/form shows back exactly what was typed on the
     * next load) but reconcile() is skipped entirely and an admin notice is
     * queued instead — existing children (if any) are left exactly as they
     * were, since reconcile() never runs.
     *
     * TYPE CHANGE AWAY (documented choice): when a post that IS currently a
     * group parent (is_group_parent()) is saved with a type other than
     * offering/recurring, its offering_dates/recurrence are cleared and
     * reconcile() is called once against that now-empty desired set — this
     * reuses reconcile()'s existing soft-close/trash logic (the same path a
     * dropped offering-dates row already takes), so seated children are
     * preserved (soft-closed, roster intact) and unseated ones are trashed,
     * never left orphaned. group_role is then explicitly reset to '' —
     * reconcile() itself always stamps 'parent', which is no longer correct
     * once the type has changed away. An event that was NEVER a group parent
     * (ordinary single/multisession) has nothing to reconcile away and is a
     * no-op here.
     *
     * RE-ENTRANCY (critical — see class docblock intro / spec): reconcile()
     * creates/updates/trashes CHILD event posts, each of which fires
     * save_post_event again.
     *   1. A CHILD post (Occurrences::is_group_child()) is NEVER treated as an
     *      authored parent — this method returns immediately for one, so a
     *      human editing a child directly can never turn it into a nested
     *      parent.
     *   2. run_reconcile() removes the save_post_event action for the
     *      duration of the reconcile() call (the SAME established pattern
     *      already used by maybe_append_registration_shortcode()'s
     *      wp_update_post() call above), so none of reconcile()'s own
     *      wp_insert_post()/wp_update_post()/wp_trash_post() calls can
     *      re-enter save_meta() — and therefore this method — at all.
     *   3. The static self::$reconciling flag is additional defense-in-depth
     *      in case some other, unrelated hook chain calls save_meta()/
     *      save_event_manager_fields() while a reconcile() for this request
     *      is already in flight.
     *
     * ORDERING (Codex P1): both save paths call this LAST, after every other
     * sub-saver. reconcile() copies the parent's rows down onto its children
     * (Occurrences::sync_shared_meta(), plus the post_content
     * maybe_append_registration_shortcode() may rewrite), so anything that runs
     * after it propagates one save late: the author changed the confirmation
     * subject, opened an occurrence and read the PREVIOUS one, with nothing on
     * screen to say the copy had already happened. The sub-savers write the
     * parent's own meta and never touch a child, so running them first is safe
     * as well as correct. Adding a new one? Put it above the
     * persist_group_authoring() call, not below it.
     *
     * APPLY-TO-ALL-DATES (audit MODEL-D40): after the structure is settled,
     * the parent's own `registration_enabled` is written down onto every LIVE
     * child — but ONLY when the form's explicit action checkbox was ticked.
     * It runs AFTER reconcile() so it covers dates created or revived by this
     * same save, and it runs even when a validation guard skipped reconcile()
     * (the admin's instruction is about the dates that exist, which that guard
     * deliberately leaves alone). See maybe_apply_registration_to_dates().
     *
     * @param int    $post_id
     * @param string $type Already-sanitized event type (sanitize_event_type_input()'s 'type').
     * @param array  $src  Raw input array ($_POST-shaped, still slashed) — the
     *                     SAME array persist_event_authoring() hands every
     *                     other sub-saver. Threaded through (Task 28 fix round
     *                     1) rather than read off $_POST down in
     *                     persist_group_structure(), so the shared tail has
     *                     exactly one input and can be exercised without a
     *                     superglobal. Both real callers pass $_POST, so this
     *                     changes no production behaviour.
     */
    private function persist_group_authoring( $post_id, $type, array $src ) {
        if ( self::$reconciling ) {
            return;
        }
        if ( $this->occurrences->is_group_child( $post_id ) ) {
            return;
        }

        $this->persist_group_structure( $post_id, $type, $src );

        // One call site, after every structural branch above (including the
        // guards' early returns, which live inside persist_group_structure()).
        $this->maybe_apply_registration_to_dates( $post_id, $src );
    }

    /**
     * persist_group_authoring()'s structural half: write the authored
     * offering_dates / recurrence rule and reconcile the children (or retire
     * them when the type has changed away). Split out purely so the
     * apply-to-all-dates step can run once, after every branch, instead of
     * being repeated before each early return.
     *
     * @param int    $post_id
     * @param string $type
     * @param array  $src Raw input array ($_POST-shaped, still slashed).
     */
    private function persist_group_structure( $post_id, $type, array $src ) {
        $was_parent = $this->occurrences->is_group_parent( $post_id );

        if ( $type === 'offering' ) {
            $rows = $this->sanitize_offering_dates_rows( $src['anchor_event_offering_dates'] ?? [], $post_id );

            if ( empty( $rows ) && $this->offering_has_dates_to_protect( $post_id ) ) {
                // Guard (audit MODEL-D14): an offering that ALREADY has dates
                // and comes back with none is an accident — a cleared repeater,
                // a JS failure that posted no rows at all — not an instruction.
                // Persisting the empty list destroyed the authored dates while
                // the children (skipped by the reconcile guard below) stayed
                // published and bookable with nothing pointing at them, and
                // every later save repeated the no-op because the list was
                // still empty. So keep the stored rows, skip reconcile, and
                // tell the author. Clearing an offering FOR REAL is an explicit
                // action: change the event's type away from "offering", which
                // clears both keys and retires the children (the $was_parent
                // branch at the bottom of this method).
                $this->queue_group_notice( 'offering_incomplete', $post_id );
                return;
            }

            // wp_slash(): sanitize_offering_dates_rows() unslashed the posted
            // rows and update_post_meta() unslashes again, so a row `label`
            // reading `Room C:\Alpha` would otherwise arrive as `Room C:Alpha`.
            // See persist_event_authoring()'s docblock.
            \update_post_meta( $post_id, $this->meta_key( 'offering_dates' ), \wp_slash( $rows ) );
            \update_post_meta( $post_id, $this->meta_key( 'recurrence' ), [] );

            if ( empty( $rows ) ) {
                // Nothing authored yet and nothing to protect (a brand-new
                // offering saved empty): the empty list is the truth, but it
                // must still never reach reconcile().
                $this->queue_group_notice( 'offering_incomplete', $post_id );
                return;
            }

            $this->run_reconcile( $post_id );
            return;
        }

        if ( $type === 'recurring' ) {
            // The rule carries no free text (freq/interval/times/counts/dates
            // only), so there is nothing for a re-slash to protect here.
            $rule = $this->sanitize_recurrence_rule( $src['anchor_event_recurrence'] ?? [] );
            \update_post_meta( $post_id, $this->meta_key( 'recurrence' ), $rule );
            \update_post_meta( $post_id, $this->meta_key( 'offering_dates' ), [] );

            $has_terminator = ( ! empty( $rule['count'] ) && (int) $rule['count'] > 0 ) || ! empty( $rule['until'] );
            if ( ! $has_terminator ) {
                // Guard: never reconcile an unterminated rule — expand_recurrence()
                // would otherwise expand it to the RECURRENCE_MAX_ROWS (104) safety
                // cap, i.e. up to 104 child posts from one incomplete save.
                $this->queue_group_notice( 'recurrence_incomplete', $post_id );
                return;
            }

            $this->run_reconcile( $post_id );
            return;
        }

        // Type changed away from offering/recurring. Only act when this post
        // WAS a group parent — an ordinary single/multisession event was
        // never group-authored and has nothing to reconcile away.
        if ( $was_parent ) {
            \update_post_meta( $post_id, $this->meta_key( 'offering_dates' ), [] );
            \update_post_meta( $post_id, $this->meta_key( 'recurrence' ), [] );
            $this->run_reconcile( $post_id ); // Empty desired set -> retires every existing child (soft-close seated, trash unseated).
            \update_post_meta( $post_id, $this->meta_key( 'group_role' ), '' ); // reconcile() always stamps 'parent'; not correct once the type has changed away.
        }
    }

    /**
     * Does this offering parent have authored dates that an empty save would
     * destroy (audit MODEL-D14)? Either stored rows or live children counts:
     * the rows are what an author typed, and the children are what visitors
     * can book — a parent with either has something a zero-row save should
     * never be allowed to silently take away.
     *
     * @param int $post_id
     * @return bool
     */
    private function offering_has_dates_to_protect( $post_id ) {
        $stored = \get_post_meta( $post_id, $this->meta_key( 'offering_dates' ), true );
        if ( ! empty( $stored ) && is_array( $stored ) ) {
            return true;
        }
        return ! empty( $this->occurrences->children( $post_id, true ) );
    }

    /**
     * The parent form's explicit "apply this registration setting to all
     * dates" action (audit MODEL-D40), shared by both save paths.
     *
     * `registration_enabled` is PER-OCCURRENCE, so a plain parent save changes
     * the PARENT's own flag and every existing date keeps its own value. This
     * runs only when the admin ticked the action checkbox rendered by
     * render_registration_apply_to_dates() — the same POST key in both forms —
     * and then writes the value the admin just saved onto every LIVE child
     * (Occurrences::apply_registration_to_children(); soft-closed dates are
     * never touched). A no-op on anything that is not a group parent, so the
     * key is inert if it ever arrives on a single event's save.
     *
     * @param int   $post_id
     * @param array $src Raw input array ($_POST-shaped) threaded down from
     *                   persist_event_authoring(), not read off the superglobal.
     */
    private function maybe_apply_registration_to_dates( $post_id, array $src ) {
        if ( empty( $src['anchor_event_registration_apply_to_dates'] ) ) {
            return;
        }
        $enabled = ! empty( $src['anchor_event_registration_enabled'] );

        $this->occurrences->apply_registration_to_children( $post_id, $enabled );
    }

    /**
     * Call Occurrences::reconcile() with the save_post_event hook removed for
     * its duration (+ a static flag as defense-in-depth) so none of
     * reconcile()'s own child-post writes can re-enter save_meta() /
     * persist_group_authoring() (spec Phase 2, Task 2.3 — see
     * persist_group_authoring()'s docblock, point 2).
     *
     * @param int $post_id
     */
    private function run_reconcile( $post_id ) {
        self::$reconciling = true;
        \remove_action( 'save_post_' . self::CPT, [ $this, 'save_meta' ] );
        try {
            $this->occurrences->reconcile( $post_id );
        } finally {
            \add_action( 'save_post_' . self::CPT, [ $this, 'save_meta' ] );
            self::$reconciling = false;
        }
    }

    /**
     * Hooked on the generic `wp_trash_post` action (spec Phase 2, Task 2.3
     * FIX 2). Trashing a group PARENT event does NOT fire save_post — so
     * persist_group_authoring()/reconcile() never run for it — which would
     * otherwise leave its children live, published, and bookable while
     * pointing at a trashed parent. When the trashed post IS a group parent,
     * every existing child is retired via Occurrences::retire_all_children():
     * roster-safe, reusing the SAME soft-close/trash logic reconcile()
     * already applies to a no-longer-desired occurrence rather than
     * reimplementing it — a seated child is soft-closed (roster preserved), an
     * unseated one is trashed.
     *
     * RE-ENTRANCY: retire_all_children() may itself call wp_trash_post() on an
     * unseated child, re-firing this action for that child post id. A child
     * is never itself a group parent (is_group_parent() checks
     * group_role === 'parent'; a child's group_role is 'child'), so that
     * re-entrant call is already a no-op via the is_group_parent() guard
     * below — self::$retiring_children is additional defense-in-depth,
     * matching the self::$reconciling pattern used elsewhere in this class.
     *
     * SCOPE: trash only. The restore half lives in
     * restore_children_on_parent_untrash() (audit MODEL-D15).
     *
     * @param int $post_id
     */
    public function retire_children_on_parent_trash( $post_id ) {
        if ( self::$retiring_children ) {
            return;
        }
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || \get_post_type( $post_id ) !== self::CPT ) {
            return;
        }
        if ( ! $this->occurrences->is_group_parent( $post_id ) ) {
            return;
        }

        self::$retiring_children = true;
        try {
            $this->occurrences->retire_all_children( $post_id );
        } finally {
            self::$retiring_children = false;
        }
    }

    /**
     * The mirror of retire_children_on_parent_trash(), hooked on the generic
     * `untrashed_post` action (audit MODEL-D15). Restoring a group parent from
     * the trash has to undo the retirement, or the trash is a one-way door:
     * every seated child stays soft-closed (manual/cancelled, registration
     * off), every unseated child stays in the trash, and the parent's page
     * renders "No dates currently scheduled" with no visible way back.
     *
     * The repair is just reconcile(): its matched branch already untrashes +
     * republishes a wanted date's occurrence and revives a soft-closed one,
     * which is precisely the state retire_all_children() left behind. Reusing
     * it rather than writing an inverse of retire_child() means restore can
     * never drift from retire.
     *
     * WHY NOT save_post: wp_untrash_post() restores the status via
     * wp_update_post(), so save_post_event DOES fire — but save_meta() bails
     * without the metabox nonce and persist_group_authoring() therefore never
     * runs, so nothing reconciles. `untrashed_post` is the hook that can.
     *
     * Runs through run_reconcile() for the same save_post_event suppression
     * every other reconcile entry point uses.
     *
     * RE-ENTRANCY: reconcile() calls wp_untrash_post() on a wanted date's
     * trashed occurrence, re-firing this action for the CHILD post id. A child
     * is never a group parent, so the is_group_parent() guard already makes
     * that a no-op; self::$restoring_children is defense-in-depth, matching
     * self::$retiring_children on the trash side.
     *
     * @param int $post_id
     */
    public function restore_children_on_parent_untrash( $post_id ) {
        if ( self::$restoring_children || self::$reconciling ) {
            return;
        }
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || \get_post_type( $post_id ) !== self::CPT ) {
            return;
        }
        // is_group_parent() is a stamped-meta check, so an ordinary event that
        // was never group-authored is never turned into one by a restore.
        if ( ! $this->occurrences->is_group_parent( $post_id ) ) {
            return;
        }

        self::$restoring_children = true;
        try {
            $this->run_reconcile( $post_id );
        } finally {
            self::$restoring_children = false;
        }
    }

    /**
     * Restore an event to the status it was trashed in, instead of WordPress's
     * blanket `draft` (audit NEW-D4).
     *
     * Since WP 5.6 wp_untrash_post() restores every post type to 'draft' rather
     * than to its pre-trash status, and offers `wp_untrash_post_status` as the
     * opt-out. For events that default is actively wrong in a way an author
     * cannot see: restoring a published group parent brings its occurrences
     * back published (restore_children_on_parent_untrash() reconciles them) and
     * leaves the CONTAINER as a draft — a live "choose a date" set hanging off
     * an unpublished parent, with no notice that anything is amiss. The same
     * applies to a plain event: its managed product is republished by
     * Product_Sync::on_event_saved() while the event itself is not.
     *
     * `$previous_status` is WordPress's own read of `_wp_trash_meta_status`,
     * the row wp_trash_post() writes; this is the identical three-line filter
     * WC_Post_Data uses to keep an order's status across a trash round trip. An
     * empty previous status (a row written by something that did not record
     * one) falls back to WordPress's default rather than to a guess.
     *
     * DEPLOY NOTE: a `future` (scheduled) event is restored as `future` too,
     * and WordPress does not re-check a schedule on restore — so an event whose
     * publish date passed while it sat in the trash comes back scheduled rather
     * than published, and stays unpublished until it is saved again. That is
     * the same behaviour WooCommerce orders get from the identical filter, and
     * it is still strictly better than the blanket `draft`; if it bites, the
     * fix is to re-publish that event, not to drop the filter.
     *
     * @param string $new_status      The status wp_untrash_post() would use.
     * @param int    $post_id
     * @param string $previous_status The status the post was trashed in.
     * @return string
     */
    public function restore_untrashed_event_status( $new_status, $post_id, $previous_status ) {
        if ( \get_post_type( $post_id ) !== self::CPT || (string) $previous_status === '' ) {
            return $new_status;
        }
        return (string) $previous_status;
    }

    /**
     * Sanitize the posted offering-dates repeater rows (Offering Dates
     * section, data-when-type="offering", spec Phase 2, Task 2.3). Rows with
     * no parseable date are dropped — same normalization
     * Occurrences::get_offering_dates() applies on read, kept here too so
     * what's persisted is already clean. Reuses the SAME sanitize_date()/
     * sanitize_time() helpers the rest of save_meta() uses (strict Y-m-d /
     * H:i regex — matches the <input type="date">/<input type="time"> the
     * repeater renders).
     *
     * @param mixed $raw     Raw anchor_event_offering_dates[] rows from $_POST (NOT yet unslashed).
     * @param int   $post_id The event being saved — the notice queue is per post.
     * @return array<int,array{date:string,start_time:string,end_time:string,label:string,capacity:int}>
     */
    private function sanitize_offering_dates_rows( $raw, $post_id = 0 ) {
        $rows = [];
        // Two rows that mint the SAME occurrence identity (same date AND same
        // start time) are one occurrence, and only one child can ever exist for
        // it. Storing both left the metabox showing two live rows against "1
        // generated date is currently live", with the second row's
        // tier/capacity/end_date silently discarded on read (audit MODEL-D8).
        // The extra row is rejected here, at the only moment an author is
        // present to be told about it. Same date, DIFFERENT start time is two
        // legitimate sessions and is kept — see Occurrences::occurrence_key(),
        // the one place that identity is spelled.
        $seen      = [];
        $duplicate = false;
        foreach ( (array) \wp_unslash( $raw ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $date = $this->sanitize_date( $row['date'] ?? '' );
            if ( $date === '' ) {
                continue;
            }
            // An occurrence may run longer than a day. Anything earlier than the
            // start is dropped rather than stored, so a typo cannot create an
            // occurrence that ends before it begins. Empty means single-day,
            // which is what every row written before this field existed means.
            $end_date = $this->sanitize_date( $row['end_date'] ?? '' );
            if ( $end_date !== '' && $end_date < $date ) {
                $end_date = '';
            }

            $clean = [
                'date' => $date,
                'end_date' => $end_date,
                'start_time' => $this->sanitize_time( $row['start_time'] ?? '' ),
                'end_time' => $this->sanitize_time( $row['end_time'] ?? '' ),
                'label' => \sanitize_text_field( $row['label'] ?? '' ),
                'capacity' => \max( 0, (int) ( $row['capacity'] ?? 0 ) ),
                // Optional link to one of the event's ticket tiers, so a date
                // sells its own ticket instead of every tier on the event.
                'tier_id' => \sanitize_key( (string) ( $row['tier_id'] ?? '' ) ),
            ];

            $key = $this->occurrences->occurrence_key( $clean );
            if ( isset( $seen[ $key ] ) ) {
                $duplicate = true;
                continue;
            }
            $seen[ $key ] = true;

            $rows[] = $clean;
        }

        if ( $duplicate ) {
            $this->queue_group_notice( 'offering_duplicate_date', $post_id );
        }

        return $rows;
    }

    /**
     * Sanitize the posted recurrence rule (Recurring Schedule section,
     * data-when-type="recurring", spec Phase 2, Task 2.3) into the exact
     * shape Occurrences::expand_recurrence() expects. `count` is only set
     * when it's a valid positive integer; `until` only when it's a valid
     * date; `weekdays` only for freq=weekly and only when at least one valid
     * 0..6 value was checked — omitting all three when unset/invalid (rather
     * than writing 0/''/[]) is what lets persist_group_authoring()'s
     * has_terminator check with a plain empty()/isset() read cleanly.
     *
     * @param mixed $raw Raw anchor_event_recurrence[] map from $_POST (NOT yet unslashed).
     * @return array{freq:string,interval:int,start_time:string,end_time:string,capacity:int,count?:int,until?:string,weekdays?:array<int,int>}
     */
    private function sanitize_recurrence_rule( $raw ) {
        $raw = is_array( $raw ) ? \wp_unslash( $raw ) : [];

        $freq = ( ( $raw['freq'] ?? '' ) === 'monthly' ) ? 'monthly' : 'weekly';
        $interval = \max( 1, (int) ( $raw['interval'] ?? 1 ) );

        $rule = [
            'freq' => $freq,
            'interval' => $interval,
            'start_time' => $this->sanitize_time( $raw['start_time'] ?? '' ),
            'end_time' => $this->sanitize_time( $raw['end_time'] ?? '' ),
            'capacity' => \max( 0, (int) ( $raw['capacity'] ?? 0 ) ),
        ];

        $count_raw = (int) ( $raw['count'] ?? 0 );
        if ( $count_raw > 0 ) {
            $rule['count'] = $count_raw;
        }

        $until = $this->sanitize_date( $raw['until'] ?? '' );
        if ( $until !== '' ) {
            $rule['until'] = $until;
        }

        if ( $freq === 'weekly' && isset( $raw['weekdays'] ) && is_array( $raw['weekdays'] ) ) {
            $weekdays = [];
            foreach ( $raw['weekdays'] as $wd ) {
                $wd = (int) $wd;
                if ( $wd >= 0 && $wd <= 6 ) {
                    $weekdays[] = $wd;
                }
            }
            $weekdays = \array_values( \array_unique( $weekdays ) );
            \sort( $weekdays );
            if ( ! empty( $weekdays ) ) {
                $rule['weekdays'] = $weekdays;
            }
        }

        return $rule;
    }

    /**
     * The ONE vocabulary of save-time authoring notices (audit MODEL-D14, and
     * the notice half of MODEL-D8). Every renderer — admin_notices() for the
     * classic editor, render_event_manager_notice() for the front-end manager
     * form, attach_notices_to_rest_response() for a block-editor consumer —
     * reads its copy from here, so a code can never mean two things or exist
     * on one path only.
     *
     * @return array<string,array{level:string,message:string}>
     */
    private function group_notice_map() {
        return [
            'missing_start_date' => [
                'level' => 'error',
                'message' => \__( 'Event start date is required.', 'anchor-schema' ),
            ],
            // Guard: this save never reached reconcile(). Any existing dates
            // were left exactly as they were — including the authored rows,
            // which are NOT overwritten with the empty list (MODEL-D14).
            'offering_incomplete' => [
                'level' => 'error',
                'message' => \__( 'Add at least one offering date — nothing was generated or updated, and any dates already on this event were left exactly as they were. To remove them all, change the event type away from "Offering".', 'anchor-schema' ),
            ],
            // Not a guard: the save DID go through, minus the rows that
            // repeated an occurrence already in the list (MODEL-D8).
            'offering_duplicate_date' => [
                'level' => 'warning',
                'message' => \__( 'Two offering dates had the same date and start time — only the first was kept. Give them different start times to run two sessions on one day.', 'anchor-schema' ),
            ],
            'recurrence_incomplete' => [
                'level' => 'error',
                'message' => \__( 'Set an end for the recurrence — a number of occurrences or an until date — before saving. No occurrences were generated/updated.', 'anchor-schema' ),
            ],
            // Inheritance is symmetric on purpose — a value cleared on this
            // event is cleared on its dates — but when what it clears is
            // authored content, saying nothing is how a date silently stops
            // asking a question. Queued by Occurrences::sync_shared_meta().
            // Worded for BOTH ways a date ends up holding a row this event does
            // not: somebody typed it on the date, or somebody cleared it here
            // (save_registration_questions()/save_email_fields() delete the row
            // on an empty field) — much the commoner case, and the one where
            // claiming the dates authored it would be plain wrong.
            'inherited_child_data_removed' => [
                'level' => 'warning',
                'message' => \__( 'Registration questions or email wording were removed from this event\'s dates, because the event itself no longer has them — every date follows the event. To keep them, set them here on the event and save again.', 'anchor-schema' ),
            ],
        ];
    }

    /**
     * Where a queued notice lives: a short-lived per-user, per-post transient.
     *
     * This replaced a redirect_post_location filter, which only ever fired on
     * ONE of the three save paths (audit MODEL-D14). The block editor saves
     * over REST and through a hidden metabox iframe — no redirect — and
     * handle_event_manager_save() does its own wp_safe_redirect() with its own
     * `event_manager_notice` arg, so two thirds of authors were told nothing
     * while their save was silently refused. A transient is readable from
     * whichever request happens to render next, which is the property the
     * filter lacked. Per user so two editors never see each other's notices,
     * per post so a notice cannot follow the author to an unrelated screen,
     * and 60s so a stale one can never surface a page-load later.
     *
     * @param int $post_id
     * @param int $user_id 0 = the current user.
     * @return string
     */
    private function group_notice_key( $post_id, $user_id = 0 ) {
        $user_id = $user_id ?: \get_current_user_id();
        return 'anchor_events_notice_' . (int) $user_id . '_' . (int) $post_id;
    }

    /**
     * Queue an authoring notice for the author who is saving $post_id. Codes
     * accumulate (a save can trip more than one guard) and never repeat.
     *
     * Public because the reconcile engine queues too (Occurrences::
     * sync_shared_meta() reports the child rows an inheritance pass deleted).
     * One queue, one vocabulary — the alternative was a second notice channel
     * that only the classic editor would have rendered.
     *
     * @param string $code    A key of group_notice_map().
     * @param int    $post_id
     */
    public function queue_group_notice( $code, $post_id = 0 ) {
        $post_id = (int) $post_id;
        $user_id = \get_current_user_id();
        if ( $post_id <= 0 || $user_id <= 0 ) {
            // Nothing to key on — a cron/CLI write with no author present has
            // nobody to tell.
            return;
        }
        $codes = $this->queued_group_notice_codes( $post_id );
        if ( in_array( $code, $codes, true ) ) {
            return;
        }
        $codes[] = $code;
        \set_transient( $this->group_notice_key( $post_id ), $codes, self::NOTICE_TTL );
    }

    /**
     * The queued codes for a post, WITHOUT consuming them.
     *
     * @param int $post_id
     * @return string[]
     */
    private function queued_group_notice_codes( $post_id ) {
        $codes = \get_transient( $this->group_notice_key( (int) $post_id ) );
        if ( ! is_array( $codes ) ) {
            return [];
        }
        $map = $this->group_notice_map();
        return \array_values( \array_filter( $codes, function ( $code ) use ( $map ) {
            return isset( $map[ $code ] );
        } ) );
    }

    /**
     * The queued codes for a post, consuming them: a notice is delivered
     * exactly once, by whichever renderer gets there first.
     *
     * @param int $post_id
     * @return string[]
     */
    private function take_group_notices( $post_id ) {
        $codes = $this->queued_group_notice_codes( $post_id );
        if ( ! empty( $codes ) ) {
            \delete_transient( $this->group_notice_key( (int) $post_id ) );
        }
        return $codes;
    }

    /**
     * Public read of the notices queued for the current user on $post_id, as
     * rendered payloads. Read-only — it never consumes the queue — so a
     * caller that only wants to LOOK (the REST response, a test) cannot rob
     * the admin notice of its one delivery.
     *
     * @param int $post_id
     * @return array<int,array{code:string,level:string,message:string}>
     */
    public function queued_group_notices( $post_id ) {
        $map = $this->group_notice_map();
        $out = [];
        foreach ( $this->queued_group_notice_codes( $post_id ) as $code ) {
            $out[] = [
                'code' => $code,
                'level' => $map[ $code ]['level'],
                'message' => $map[ $code ]['message'],
            ];
        }
        return $out;
    }

    /**
     * The front-end manager form's `event_manager_notice` value: the outcome
     * code this save is reporting plus any authoring notice queued during it,
     * comma-separated. render_event_manager_notice() renders each in turn.
     *
     * @param string $base_code 'saved' / 'created' / …
     * @param int    $post_id
     * @return string
     */
    private function event_manager_notice_arg( $base_code, $post_id ) {
        $codes = \array_filter( \array_merge( [ $base_code ], $this->take_group_notices( $post_id ) ) );
        return \implode( ',', \array_unique( $codes ) );
    }

    /**
     * Expose any queued notices on a REST WRITE response (audit MODEL-D14).
     *
     * Read the ordering before relying on this: Gutenberg saves over REST
     * FIRST and only then POSTs the metaboxes to post.php?meta-box-loader=1,
     * and it is that metabox POST — save_meta() — which queues the notice. So
     * this response can NEVER carry the notice for the save it is answering;
     * what it carries is a LEFTOVER from the previous metabox save, if one is
     * still inside NOTICE_TTL. That makes it a convenience for a future
     * editor-side consumer, never the delivery mechanism: the block editor is
     * told by the metabox render itself (render_group_authoring_sections()),
     * which is the request that provably runs after the queue is written.
     * The read does NOT consume, so it can never rob that render. Write
     * requests only; a public GET of an event is untouched.
     *
     * @param \WP_REST_Response $response
     * @param \WP_Post          $post
     * @param \WP_REST_Request  $request
     * @return \WP_REST_Response
     */
    public function attach_notices_to_rest_response( $response, $post, $request ) {
        if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post ) {
            return $response;
        }
        if ( ! $request instanceof \WP_REST_Request || \strtoupper( $request->get_method() ) === 'GET' ) {
            return $response;
        }
        $notices = $this->queued_group_notices( $post->ID );
        if ( empty( $notices ) ) {
            return $response;
        }
        $data = $response->get_data();
        if ( ! is_array( $data ) ) {
            return $response;
        }
        $data['anchor_event_notices'] = $notices;
        $response->set_data( $data );
        return $response;
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

    /**
     * Render the authoring notices queued by this user's last save of the post
     * on screen, then consume them (one delivery — see queue_group_notice()).
     * The copy itself is group_notice_map()'s, shared with the front-end
     * manager form so the two can never say different things.
     */
    public function admin_notices() {
        // Gutenberg posts the metaboxes to post.php in a hidden iframe whose
        // output nobody ever sees. Consuming the queue there would eat the
        // notice before the editor could show it, so that request is left to
        // pass through untouched.
        if ( ! empty( $_REQUEST['meta-box-loader'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        // A bulk-action URL sends post[] — an array is a list screen, not a
        // post on screen, so there is nothing to key a notice to.
        $post_id = ( isset( $_GET['post'] ) && ! is_array( $_GET['post'] ) ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $post_id && isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) {
            $post_id = (int) $GLOBALS['post']->ID;
        }
        if ( ! $post_id ) {
            return;
        }

        $map = $this->group_notice_map();
        foreach ( $this->take_group_notices( $post_id ) as $code ) {
            $class = $map[ $code ]['level'] === 'warning' ? 'notice-warning' : 'notice-error';
            echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $map[ $code ]['message'] ) . '</p></div>';
        }
    }

    /**
     * The DST warning for a site configured with a raw UTC offset instead of a
     * named zone (audit MODEL-D21), or '' when there is nothing to warn about.
     *
     * A fixed ±HH:MM offset does not observe daylight saving, so on a site with
     * `timezone_string` empty and `gmt_offset` -6 every event is read at UTC-6
     * all year: a September course is computed an hour later than the time its
     * author typed, and that hour propagates into the reminder window, the
     * visibility clause, the wp_date()-rendered email tokens and the JSON-LD
     * instant. In December the same event is correct — the error appears and
     * disappears twice a year, which is what makes it hard to see.
     *
     * This does NOT change behaviour: the fix is a human setting a named zone
     * in Settings > General, and nothing here can choose one safely (there are
     * several zones per offset and picking the wrong one moves real dates).
     *
     * Split from the renderer so the condition is testable without a screen.
     *
     * DISMISSIBLE, per user (audit NEW-D6): the fix is a decision only a human
     * with access to Settings → General can make, and until they make it this
     * printed on every event edit, every event list and the settings tab, for
     * everyone — a permanent banner is how authors learn to stop reading
     * notices. The dismissal records the OFFSET it was dismissed for, so
     * changing the site to a different fixed offset asks again: the notice
     * names the offset, and a changed setting is a fresh decision.
     *
     * @return string
     */
    public function timezone_notice_html() {
        // wp_timezone_string() returns either a named zone or the '+HH:MM'
        // form it derives from gmt_offset. Only the latter loses DST.
        $zone = \wp_timezone_string();
        if ( ! \preg_match( '/^[+-]\d{2}:\d{2}$/', $zone ) ) {
            return '';
        }
        // '+00:00' is the shipped WordPress default (timezone_string '',
        // gmt_offset 0), so warning on it would put a permanent notice on the
        // events screens of every untouched install in the fleet without
        // naming a real problem — the site is being read as UTC, which is what
        // its setting says. The defect this warns about is a NON-ZERO offset
        // standing in for a zone that observes DST.
        if ( $zone === '+00:00' ) {
            return '';
        }
        $user_id = \get_current_user_id();
        if ( $user_id > 0 && (string) \get_user_meta( $user_id, self::TZ_NOTICE_DISMISSED_META, true ) === $zone ) {
            return '';
        }

        return '<div class="notice notice-warning is-dismissible" data-anchor-events-tz-dismiss="'
            . \esc_attr( $this->timezone_notice_dismiss_url() ) . '"><p><strong>'
            . \esc_html__( 'Events: this site has no time zone, only a UTC offset.', 'anchor-schema' )
            . '</strong> '
            . \esc_html(
                \sprintf(
                    /* translators: %s: the site's UTC offset, e.g. -06:00. */
                    \__( 'Event dates are read at a fixed %s all year, so daylight saving time is not observed and events during DST are computed an hour away from the time you typed.', 'anchor-schema' ),
                    $zone
                )
            )
            . ' <a href="' . \esc_url( \admin_url( 'options-general.php#timezone_string' ) ) . '">'
            . \esc_html__( 'Choose a named city in Settings → General', 'anchor-schema' )
            . '</a>.</p></div>';
    }

    /**
     * The nonced URL that records "this author has seen the timezone warning".
     *
     * A plain admin URL, not an ajax endpoint: it is handled on admin_init
     * (maybe_dismiss_timezone_notice()) so following it in a browser works
     * exactly as well as the fetch() the inline script sends — one handler, no
     * second entry point to keep in step.
     *
     * @return string
     */
    private function timezone_notice_dismiss_url() {
        return \wp_nonce_url(
            \add_query_arg( 'anchor_events_dismiss_tz', '1', \admin_url() ),
            self::TZ_NOTICE_DISMISS_ACTION
        );
    }

    /**
     * Record the dismissal (audit NEW-D6). Hooked on admin_init, so it also
     * works for a plain navigation to the URL above; it deliberately does NOT
     * redirect, because the fetch() that normally calls it wants no page change
     * and the notice is already suppressed for the render that follows.
     *
     * Capability-gated like everything else on this hook — admin_init fires on
     * admin-post.php before the auth cookie is validated, and this module
     * registers nopriv handlers there.
     */
    public function maybe_dismiss_timezone_notice() {
        if ( ! isset( $_GET['anchor_events_dismiss_tz'] ) ) {
            return;
        }
        if ( ! \current_user_can( 'edit_posts' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? \sanitize_text_field( \wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! \wp_verify_nonce( $nonce, self::TZ_NOTICE_DISMISS_ACTION ) ) {
            return;
        }
        \update_user_meta( \get_current_user_id(), self::TZ_NOTICE_DISMISSED_META, \wp_timezone_string() );
    }

    /**
     * Print timezone_notice_html() once, on the screens whose author can act on
     * it: the Events settings tab and the event editor/list.
     */
    public function maybe_render_timezone_notice() {
        if ( ! \current_user_can( 'edit_posts' ) || ! \function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = \get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $on_events = ( $screen->post_type === self::CPT && \in_array( $screen->base, [ 'post', 'edit' ], true ) );
        // The Events tab of Settings > Anchor Tools. `tab` is always present
        // for this tab — events registers at filter priority 40, so it is never
        // the page's default first tab.
        if ( ! $on_events && \class_exists( 'Anchor_Settings_Page' ) && $screen->id === 'settings_page_' . \Anchor_Settings_Page::PAGE_SLUG ) {
            $on_events = ( isset( $_GET['tab'] ) && \sanitize_key( $_GET['tab'] ) === 'events' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        if ( ! $on_events ) {
            return;
        }

        $html = $this->timezone_notice_html();
        if ( $html === '' ) {
            return;
        }

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at build time.
        // `is-dismissible` only hides the notice for this pageload; core's
        // dismiss button has no idea what to persist. Six lines of listener
        // send the nonced URL the markup carries, so the X means "and don't
        // tell me again" rather than "until I reload".
        echo '<script>(function(){var n=document.querySelector(\'[data-anchor-events-tz-dismiss]\');'
            . 'if(!n)return;n.addEventListener(\'click\',function(e){'
            . 'if(!e.target||!e.target.closest||!e.target.closest(\'.notice-dismiss\'))return;'
            . 'fetch(n.getAttribute(\'data-anchor-events-tz-dismiss\'),{credentials:\'same-origin\'});});})();</script>';
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
        \wp_enqueue_style( 'anchor-events-admin', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/admin.css' ), [], $this->asset_version( 'anchor-events-manager/assets/admin.css' ) );
        \wp_enqueue_script( 'anchor-events-admin', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/admin.js' ), [ 'jquery', 'jquery-ui-sortable' ], $this->asset_version( 'anchor-events-manager/assets/admin.js' ), true );
        // Ticket-tier repeatable table (spec §3.2).
        \wp_enqueue_script( 'anchor-events-ticket-types', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/ticket-types-admin.js' ), [ 'jquery', 'jquery-ui-sortable' ], $this->asset_version( 'anchor-events-manager/assets/ticket-types-admin.js' ), true );

        // Task 3.2 — Emails builder metabox (Monaco + token palette + preview),
        // cloned from the anchor-blocks house pattern.
        if ( \class_exists( 'Anchor_Preview_CSS' ) ) {
            \Anchor_Preview_CSS::enqueue_for_admin();
        }
        \Anchor_Monaco::enqueue( self::CPT );
        \wp_enqueue_style( 'anchor-events-email-builder', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/email-builder.css' ), [], $this->asset_version( 'anchor-events-manager/assets/email-builder.css' ) );
        \wp_enqueue_script( 'anchor-events-email-builder', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/email-builder.js' ), [ 'jquery', 'anchor-monaco', 'anchor-preview' ], $this->asset_version( 'anchor-events-manager/assets/email-builder.js' ), true );

        $post_id  = isset( $GLOBALS['post'] ) && $GLOBALS['post'] ? (int) $GLOBALS['post']->ID : 0;
        $defaults = [];
        foreach ( self::EMAIL_TEMPLATE_TYPES as $email_type ) {
            $defaults[ $email_type ] = $this->resolve_email_template( $email_type, 0 );
        }
        \wp_localize_script( 'anchor-events-email-builder', 'AnchorEmailBuilder', [
            'ajaxUrl'  => \admin_url( 'admin-ajax.php' ),
            'nonce'    => \wp_create_nonce( 'anchor_events_email_preview' ),
            'postId'   => $post_id,
            'tokens'   => $this->documented_email_tokens(),
            'defaults' => $defaults,
        ] );
    }

    public function frontend_assets() {
        if ( \is_admin() ) {
            return;
        }
        if ( \is_singular( self::CPT ) || \is_post_type_archive( self::CPT ) ) {
            $this->enqueue_frontend_assets();
        }
    }

    /**
     * Black or white text for a given background, whichever a reader can
     * actually see. Uses the WCAG relative-luminance formula rather than a
     * simple brightness average, so a mid-tone accent (the kind most brand
     * palettes pick) lands on the correct side instead of always taking white.
     *
     * @param string $hex Background colour, #rgb or #rrggbb.
     * @return string Hex colour for text on that background.
     */
    private function readable_foreground( $hex ) {
        $hex = \ltrim( (string) $hex, '#' );
        if ( \strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( \strlen( $hex ) !== 6 ) {
            return '#ffffff';
        }

        $channel = function ( $c ) {
            $c = \hexdec( $c ) / 255;
            return $c <= 0.03928 ? $c / 12.92 : \pow( ( $c + 0.055 ) / 1.055, 2.4 );
        };
        $lum = 0.2126 * $channel( \substr( $hex, 0, 2 ) )
             + 0.7152 * $channel( \substr( $hex, 2, 2 ) )
             + 0.0722 * $channel( \substr( $hex, 4, 2 ) );

        // Contrast against white vs against the module's own dark text colour.
        $vs_white = 1.05 / ( $lum + 0.05 );
        $vs_dark  = ( $lum + 0.05 ) / 0.0606; // #111827 luminance + 0.05
        return $vs_dark > $vs_white ? '#111827' : '#ffffff';
    }

    /**
     * Cache-busting version for one of this module's assets.
     *
     * Hand-maintained version strings are a standing trap: edit the file, forget
     * the bump, and every browser keeps serving the old copy while the source on
     * disk says otherwise — which reads as "the code didn't deploy". filemtime()
     * cannot fall out of step with the file it versions. Falls back to the
     * plugin version if the file cannot be stat'd.
     *
     * @param string $relative Path under the plugin root.
     * @return string
     */
    public function asset_version( $relative ) {
        $path = \Anchor_Asset_Loader::path( $relative );
        $time = \is_readable( $path ) ? \filemtime( $path ) : false;
        return $time ? (string) $time : ( \defined( 'ANCHOR_TOOLS_VERSION' ) ? ANCHOR_TOOLS_VERSION : '1' );
    }

    public function enqueue_frontend_assets() {
        if ( $this->assets_enqueued ) {
            return;
        }
        \wp_enqueue_style( 'anchor-events-frontend', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/frontend.css' ), [], $this->asset_version( 'anchor-events-manager/assets/frontend.css' ) );
        $settings = $this->get_settings();
        $btn_color = \sanitize_hex_color( $settings['register_button_color'] ?? '' ) ?: '#0f766e';
        // Drive the module's accent custom property, not just the register button.
        // Every other button the module renders (View cart, Checkout, the list
        // CTAs) reads --anchor-event-accent, so setting only .anchor-event-register
        // left them on the built-in teal and the colour setting looked broken.
        $btn_fg = $this->readable_foreground( $btn_color );
        \wp_add_inline_style( 'anchor-events-frontend', sprintf(
            ':root{--anchor-event-accent:%1$s;--anchor-event-accent-fg:%2$s;}'
            . '.anchor-event-register{background:%1$s !important;border-color:%1$s !important;color:%2$s !important;}'
            . '.anchor-event-register:hover{filter:brightness(0.92);}',
            $btn_color,
            $btn_fg
        ) );
        \wp_enqueue_script( 'anchor-events-frontend', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/frontend.js' ), [], $this->asset_version( 'anchor-events-manager/assets/frontend.js' ), true );
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
                echo esc_html( $this->status_label( $this->get_event_status( $post_id, $meta ) ) );
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
        // 'undated' earns a view of its own (MODEL-D19): events with no start
        // date are the ones most likely to need an editor, and until now there
        // was no way to list them at all.
        $statuses = [
            'upcoming' => __( 'Upcoming', 'anchor-schema' ),
            'past' => __( 'Past', 'anchor-schema' ),
            'cancelled' => __( 'Cancelled', 'anchor-schema' ),
            'undated' => __( 'Undated', 'anchor-schema' ),
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
        $query->set( 'meta_query', [ $this->build_status_clause( $status ) ] );
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
        if ( $this->occurrences->is_group_parent( $event_id ) ) {
            // A container is never bookable itself (render_registration_form()
            // returns '' for it); the picker over its live dates is what a
            // landing page that names the parent actually wants.
            $output .= $this->render_choose_date_list( $event_id );
            return $output;
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
        // REG-D20 — this prints attendee names and emails, the same PII the Roster
        // screen protects, so it uses the same single capability. Gating it lower
        // than the roster made the M2 hardening bypassable from the front end.
        if ( ! Roster::current_user_can_manage() ) {
            return '';
        }

        $atts = \shortcode_atts( [
            'show_past' => 'yes',
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'ASC',
        ], $atts );

        $this->enqueue_frontend_assets();

        // $public = false: this shortcode is gated on edit_others_posts and
        // exists to reach rosters. A soft-closed date still HAS a roster to
        // email or refund, so the occurrence_closed half of the exclusion is
        // not applied here — hide_from_archive still is.
        $meta_query = [ $this->build_hide_clause( false ) ];
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
            $output .= $this->render_registrant_counts( $event->ID );
            $output .= '</summary>';
            $output .= '<div class="anchor-event-admin-body">';
            $output .= '<p class="anchor-event-admin-meta">';
            if ( $waitlist ) {
                $output .= '<strong>' . esc_html__( 'Waitlist', 'anchor-schema' ) . ':</strong> ' . esc_html( $waitlist ) . ' &middot; ';
            }
            if ( $edit_link ) {
                $output .= '<a href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Edit event', 'anchor-schema' ) . '</a> &middot; ';
            }
            // REG-D21 — never offer a link the export handler will refuse.
            if ( Roster::current_user_can_manage() ) {
                $output .= '<a href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'anchor-schema' ) . '</a>';
            }
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

        // REG-D20 — the console's list body prints the same name/email table as
        // the Roster screen, so it answers to the same capability.
        if ( ! Roster::current_user_can_manage() ) {
            return '<div class="anchor-event-manager">' . $this->render_event_manager_notice() . $this->render_event_manager_no_access() . '</div>';
        }

        $atts = \shortcode_atts( [
            'show_past' => 'yes',
            'limit' => 50,
            'order' => 'ASC',
            // Where the validated wizard applies:
            //   no   (default) — never; the form stays one page, as it always was
            //   new            — only when creating, where a guided order helps
            //   all            — creating and editing
            // Editing is deliberately excluded from "new": someone opening an
            // existing event is usually changing one field they already have in
            // mind, and a wizard makes them walk to it.
            'steps' => 'no',
        ], $atts );

        $steps_mode = \strtolower( \trim( (string) $atts['steps'] ) );
        if ( \in_array( $steps_mode, [ 'yes', '1', 'true', 'on' ], true ) ) {
            $steps_mode = 'all'; // back-compat with the boolean spelling
        }
        $wizard_new  = \in_array( $steps_mode, [ 'new', 'all' ], true );
        $wizard_edit = ( $steps_mode === 'all' );
        $wizard      = $wizard_new || $wizard_edit; // gates the asset enqueue only

        $action = isset( $_GET['event_action'] ) ? sanitize_key( $_GET['event_action'] ) : '';
        $event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;

        $this->enqueue_frontend_assets();
        \wp_enqueue_media();
        \wp_enqueue_style( 'dashicons' );
        \wp_enqueue_script( 'jquery-ui-sortable' );
        \wp_enqueue_script(
            'anchor-events-manager-frontend',
            \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/manager.js' ),
            [ 'jquery', 'jquery-ui-sortable' ],
            $this->asset_version( 'anchor-events-manager/assets/manager.js' ),
            true
        );
        // The per-event email builder: modal, live preview, media picker, and the
        // visual editor for the opening lines.
        //
        // That editor is WordPress's own — the same wp_editor() already used for
        // the event Description above, reached here through wp.editor.initialize()
        // so it can be attached after the dialog opens. It brings the real media
        // library with it, which is the whole reason the third-party visual
        // builder that used to sit behind a "Design" tab was removed: its asset
        // manager was a parallel place to put files that WordPress knew nothing
        // about, and its component model rewrote hand-built email HTML on every
        // round trip.
        \wp_enqueue_editor();
        \wp_enqueue_script(
            'anchor-events-email-modal',
            \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/email-modal.js' ),
            [ 'jquery' ],
            $this->asset_version( 'anchor-events-manager/assets/email-modal.js' ),
            true
        );
        // Values for the palette's hover text, so a token's meaning is visible
        // before it is inserted. Every scalar gets one: the event's real value
        // where it has one, and the same stand-in the preview uses where it does
        // not — otherwise the tokens that are conditional (a room link, an order
        // number, days_until once the date has passed) would hover blank, which
        // is exactly the case the author most needs explained. The block tokens
        // are not here; they expand to markup and have prose notes instead, from
        // template_token_notes().
        $sample_tokens = $this->preview_sample_scalars( $event_id );
        if ( $event_id > 0 ) {
            $all = $this->email_tokens( [
                'event_id' => $event_id,
                'seat'     => [ 'name' => \__( 'Sample Attendee', 'anchor-schema' ), 'email' => 'sample@example.test' ],
            ] );
            foreach ( $all as $k => $v ) {
                if ( ! \is_scalar( $v ) || \trim( (string) $v ) === '' ) {
                    continue;
                }
                // Substituted as plain text, so an entity-encoded title would
                // show as "&#038;" rather than "&".
                $sample_tokens[ $k ] = \html_entity_decode( (string) $v, ENT_QUOTES, 'UTF-8' );
            }
        }

        \wp_localize_script( 'anchor-events-email-modal', 'ANCHOR_EVENT_EMAILS', [
            'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
            'nonce'   => \wp_create_nonce( 'anchor_events_email_preview' ),
            'tokens'  => $sample_tokens,
        ] );

        if ( $wizard ) {
            \wp_enqueue_script(
                'anchor-events-manager-wizard',
                \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/manager-wizard.js' ),
                [],
                $this->asset_version( 'anchor-events-manager/assets/manager-wizard.js' ),
                true
            );
        }
        \wp_enqueue_script(
            'anchor-events-ticket-types',
            \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/ticket-types-admin.js' ),
            [ 'jquery', 'jquery-ui-sortable' ],
            $this->asset_version( 'anchor-events-manager/assets/ticket-types-admin.js' ),
            true
        );

        $output = '<div class="anchor-event-manager">';
        $output .= $this->render_event_manager_notice();

        if ( $action === 'new' ) {
            $output .= $this->render_event_manager_form( 0, $wizard_new );
        } elseif ( $action === 'edit' && $event_id ) {
            if ( \current_user_can( 'edit_post', $event_id ) && \get_post_type( $event_id ) === self::CPT ) {
                $output .= $this->render_event_manager_form( $event_id, $wizard_edit );
            } else {
                $output .= '<p>' . esc_html__( 'You do not have permission to edit that event.', 'anchor-schema' ) . '</p>';
            }
        } elseif ( $action === 'roster' && $event_id ) {
            // Attendees for one event. Same data layer and same add/edit/cancel
            // handlers as the wp-admin Roster screen — only the markup differs.
            $output .= $this->roster->render_frontend( $event_id, $this->get_event_manager_page_url() );
        } else {
            $output .= $this->render_event_manager_list( $atts );
        }

        $output .= '</div>';
        return $output;
    }

    /**
     * Render the `event_manager_notice` arg. It carries a COMMA-SEPARATED list
     * (event_manager_notice_arg()): the outcome of the request plus any
     * authoring notice queued during the save — an offering whose rows came
     * back empty, a duplicated date — which used to reach only the classic
     * admin editor (audit MODEL-D14). The authoring copy comes from
     * group_notice_map(), the same map admin_notices() renders.
     */
    private function render_event_manager_notice() {
        if ( empty( $_GET['event_manager_notice'] ) ) {
            return '';
        }
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
            'lostpass_empty' => [ 'err', __( 'Please enter your username or email address.', 'anchor-schema' ) ],
        ];
        foreach ( $this->group_notice_map() as $code => $notice ) {
            $map[ $code ] = [ $notice['level'] === 'warning' ? 'warn' : 'err', $notice['message'] ];
        }

        $raw = sanitize_text_field( wp_unslash( $_GET['event_manager_notice'] ) );
        $out = '';
        foreach ( array_unique( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) ) as $notice ) {
            if ( ! isset( $map[ $notice ] ) ) {
                continue;
            }
            $class = 'is-error';
            if ( $map[ $notice ][0] === 'ok' ) {
                $class = 'is-ok';
            } elseif ( $map[ $notice ][0] === 'warn' ) {
                $class = 'is-warning';
            }
            $out .= '<div class="anchor-event-manager-notice ' . esc_attr( $class ) . '">' . esc_html( $map[ $notice ][1] ) . '</div>';
        }
        return $out;
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
        // REG-D47 — every branch from here down answers the same way. The
        // "no such user" branch was already deliberately silent about account
        // existence, but a real user whose reset was denied, whose key could
        // not be minted, or whose mail failed answered `lostpass_error`, so
        // submitting a list of candidate logins told an attacker which ones
        // existed from the difference alone — and the error text ("we could not
        // find an account matching that username or email") actively
        // misdescribed two of those three cases. The real reason goes to the
        // error log, where the operator can see it and the submitter cannot.
        if ( ! $user ) {
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'lostpass_sent', $redirect ) );
            exit;
        }

        $allow = \apply_filters( 'allow_password_reset', true, $user->ID );
        if ( \is_wp_error( $allow ) || ! $allow ) {
            Events_Log::error( 'lostpass_reset_denied', [ 'user' => $user->ID ] );
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'lostpass_sent', $redirect ) );
            exit;
        }

        $key = \get_password_reset_key( $user );
        if ( \is_wp_error( $key ) ) {
            Events_Log::error( 'lostpass_key_failed', [ 'user' => $user->ID, 'detail' => $key->get_error_code() ] );
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'lostpass_sent', $redirect ) );
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
            Events_Log::error( 'lostpass_mail_failed', [ 'user' => $user->ID ] );
            \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'lostpass_sent', $redirect ) );
            exit;
        }

        \wp_safe_redirect( \add_query_arg( 'event_manager_notice', 'lostpass_sent', $redirect ) );
        exit;
    }

    private function render_event_manager_list( $atts ) {
        // $public = false — same reason as shortcode_event_registrants_list():
        // the manager console is a staff surface and a cancelled date's roster
        // has to stay findable. See build_hide_clause().
        $meta_query = [ $this->build_hide_clause( false ) ];
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
        // MODEL-D43: the auto-aware accessor, not the raw row — an auto-mode
        // event that ended yesterday is "Past" here the moment it ends, not
        // when the daily sweep next runs.
        $status = $this->status_label( $this->get_event_status( $event->ID, $meta ) );

        $output = '<details class="anchor-event-admin-item">';
        $output .= '<summary class="anchor-event-admin-summary">';
        $output .= '<span class="anchor-event-admin-name">' . esc_html( \get_the_title( $event->ID ) ?: __( '(untitled)', 'anchor-schema' ) ) . '</span>';
        if ( $date_label ) {
            $output .= ' <span class="anchor-event-admin-date">' . esc_html( $date_label ) . '</span>';
        }
        if ( $event->post_status !== 'publish' ) {
            $output .= ' <span class="anchor-event-admin-date">[' . esc_html( $event->post_status ) . ']</span>';
        }
        $output .= $this->render_registrant_counts( $event->ID );
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
        if ( Roster::current_user_can_manage() ) {
            $roster_url = \add_query_arg( [ 'event_action' => 'roster', 'event_id' => $event->ID ], $base_url );
            $output .= '<a href="' . esc_url( $roster_url ) . '">' . esc_html__( 'Attendees', 'anchor-schema' ) . '</a> &middot; ';
        }
        // REG-D21 — gated with the Attendees link above it: same handler, same cap.
        if ( Roster::current_user_can_manage() ) {
            $output .= '<a href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'anchor-schema' ) . '</a> &middot; ';
        }
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

    /**
     * Titles for the manager form's wizard steps, keyed by the data-step number
     * stamped on each .anchor-event-section. One definition drives both the rail
     * and the "Step 2 of 5" readout, so adding a section to a step needs no JS
     * change — only the data-step attribute.
     *
     * @return array<int,array{title:string,hint:string}>
     */
    private function manager_form_steps() {
        return [
            1 => [ 'title' => __( 'Basics', 'anchor-schema' ),        'hint' => __( 'Name and description', 'anchor-schema' ) ],
            2 => [ 'title' => __( 'Schedule', 'anchor-schema' ),      'hint' => __( 'Type, dates, sessions', 'anchor-schema' ) ],
            3 => [ 'title' => __( 'Details', 'anchor-schema' ),       'hint' => __( 'Place, labels, images', 'anchor-schema' ) ],
            4 => [ 'title' => __( 'Registration', 'anchor-schema' ),  'hint' => __( 'How people sign up', 'anchor-schema' ) ],
            5 => [ 'title' => __( 'Emails', 'anchor-schema' ),        'hint' => __( 'What attendees receive', 'anchor-schema' ) ],
        ];
    }

    private function render_event_manager_form( $event_id, $wizard = false ) {
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
        $timezone_options = $this->timezone_field_options( $meta['timezone'] );

        // Event-type / registration-mode authoring (Task 1.3 metabox parity,
        // Task 1.5). These resolvers apply the same enum-fallback validation as
        // the metabox and are safe to call with $event_id === 0 (new event):
        // get_post_meta( 0, ... ) reads nothing, so each resolver returns its
        // documented default (single / free / []).
        $event_type = $this->event_type( $event_id );
        $registration_mode = $this->registration_mode( $event_id );
        $wc_active = \class_exists( 'WooCommerce' );
        $sessions = $this->get_sessions( $event_id );
        // Safe with $event_id === 0 (new event): get_post_meta( 0, ... ) reads
        // nothing, so get_labels() returns [].
        $labels = $this->get_labels( $event_id );

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

            <?php if ( $wizard ) :
                /**
                 * Step rail. Rendered server-side but inert until manager-wizard.js
                 * adds .is-wizard to the form: with JS off every section stays
                 * visible and the form submits in one go exactly as before, so the
                 * wizard is progressive enhancement rather than a dependency.
                 */
                ?>
                <ol class="anchor-event-steps" aria-label="<?php esc_attr_e( 'Form steps', 'anchor-schema' ); ?>">
                <?php foreach ( $this->manager_form_steps() as $n => $step ) : ?>
                    <li class="anchor-event-steps__item" data-step-nav="<?php echo (int) $n; ?>">
                        <span class="anchor-event-steps__n"><?php echo (int) $n; ?></span>
                        <span class="anchor-event-steps__label">
                            <span class="anchor-event-steps__title"><?php echo esc_html( $step['title'] ); ?></span>
                            <span class="anchor-event-steps__hint"><?php echo esc_html( $step['hint'] ); ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <div class="anchor-event-section" data-step="1">
                <h3><?php echo esc_html__( 'Basics', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'The name and copy that appear on the event card, the event page and in search results.', 'anchor-schema' ); ?></p>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field" style="grid-column:1/-1;">
                        <label for="anchor_event_title"><?php echo esc_html__( 'Title', 'anchor-schema' ); ?> *</label>
                        <input type="text" id="anchor_event_title" name="anchor_event_title" value="<?php echo esc_attr( $title ); ?>" required />
                        <p class="anchor-event-hint"><?php echo esc_html__( 'Required.', 'anchor-schema' ); ?></p>
                    </div>
                    <div class="anchor-event-field" style="grid-column:1/-1;">
                        <label for="anchor_event_content"><?php echo esc_html__( 'Description', 'anchor-schema' ); ?></label>
                        <?php
                        /**
                         * The real WordPress editor, so the body of the course page is
                         * written the way it reads instead of hand-typed HTML. The Text
                         * tab is deliberately kept: existing events store markup the
                         * visual editor would otherwise quietly reformat, and whoever
                         * wrote that markup needs a way back to it.
                         *
                         * textarea_name matches the field handle_event_manager_save()
                         * already reads, so the save path is unchanged.
                         */
                        \wp_editor( $content, 'anchor_event_content', [
                            'textarea_name' => 'anchor_event_content',
                            'textarea_rows' => 14,
                            'media_buttons' => true,
                            'teeny'         => false,
                            'quicktags'     => true,
                            'tinymce'       => [
                                'toolbar1' => 'formatselect,bold,italic,bullist,numlist,link,unlink,blockquote,removeformat,undo,redo',
                                'toolbar2' => '',
                            ],
                        ] );
                        ?>
                        <p class="anchor-event-hint"><?php echo esc_html__( 'Visual writes the page for you; Text shows the underlying HTML if you need it.', 'anchor-schema' ); ?></p>
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

            <div class="anchor-event-section" data-step="2">
                <h3><?php echo esc_html__( 'Event Type & Registration', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'Is this one date, several dates people choose between, or a multi-day event? And do they sign up here or somewhere else? Set these two first — the rest of the form changes to match.', 'anchor-schema' ); ?></p>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_type"><?php echo esc_html__( 'Event Type', 'anchor-schema' ); ?></label>
                        <?php if ( $event_type === 'recurring' ) : ?>
                            <?php /* Recurrence authoring is admin-only (Task 2.3 FIX 1): an event that's
                                     already recurring is shown read-only here rather than offered in the
                                     select — swapping the select to "single" and letting an unrelated save
                                     silently downgrade the type would corrupt the event. */ ?>
                            <p class="description anchor-event-type-locked">
                                <strong><?php echo esc_html__( 'Recurring schedule', 'anchor-schema' ); ?></strong> — <?php echo esc_html__( 'Recurring events are managed in the admin and cannot be changed from this form.', 'anchor-schema' ); ?>
                            </p>
                            <input type="hidden" id="anchor_event_type" name="anchor_event_type" value="recurring" />
                        <?php else : ?>
                            <select id="anchor_event_type" name="anchor_event_type">
                                <option value="single" <?php selected( $event_type, 'single' ); ?>><?php echo esc_html__( 'Single event', 'anchor-schema' ); ?></option>
                                <option value="multisession" <?php selected( $event_type, 'multisession' ); ?>><?php echo esc_html__( 'Multi-session series', 'anchor-schema' ); ?></option>
                                <option value="offering" <?php selected( $event_type, 'offering' ); ?>><?php echo esc_html__( 'Pick-one offerings', 'anchor-schema' ); ?></option>
                            </select>
                        <?php endif; ?>
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

            <div class="anchor-event-section" data-step="2">
                <h3><?php echo esc_html__( 'Date & Time', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'When it runs. The start date is required, and once it has passed the event stops appearing on the site.', 'anchor-schema' ); ?></p>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_start_date"><?php echo esc_html__( 'Start date', 'anchor-schema' ); ?> *</label>
                        <input type="date" id="anchor_event_start_date" name="anchor_event_start_date" value="<?php echo esc_attr( $meta['start_date'] ); ?>" required />
                        <p class="anchor-event-hint"><?php echo esc_html__( 'Required. An event cannot be saved without one.', 'anchor-schema' ); ?></p>
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
                    <div class="anchor-event-field anchor-event-field--check"><span class="anchor-event-field-heading"><?php echo esc_html__( 'Duration', 'anchor-schema' ); ?></span><label><input type="checkbox" id="anchor_event_all_day" name="anchor_event_all_day" value="1" <?php checked( $meta['all_day'] ); ?> /> <?php echo esc_html__( 'All-day event', 'anchor-schema' ); ?></label>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section anchor-event-conditional" data-when-type="multisession" data-step="2">
                <h3><?php echo esc_html__( 'Sessions', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'For an event that runs over several sessions. List each session here; people sign up once and are booked for all of them.', 'anchor-schema' ); ?></p>
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

            <div class="anchor-event-section" data-step="3">
                <h3><?php echo esc_html__( 'Labels', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'Short badges shown on the event card — e.g. 2 Day Course, or 14 CE Credits.', 'anchor-schema' ); ?></p>
                <table class="widefat anchor-event-labels-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Type', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Caption', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Value', 'anchor-schema' ); ?></th>
                            <th aria-hidden="true"></th>
                        </tr>
                    </thead>
                    <tbody class="anchor-event-labels-rows">
                        <?php foreach ( $labels as $i => $label_row ) : ?>
                            <?php echo $this->event_label_row_html( (int) $i, $label_row ); // already escaped ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="anchor-event-button-secondary anchor-event-label-add"><?php echo esc_html__( 'Add label', 'anchor-schema' ); ?></button>
                </p>
                <script type="text/html" id="anchor-event-label-template">
                    <?php echo $this->event_label_row_html( 0, null, true ); // already escaped ?>
                </script>
            </div>

            <?php $this->render_group_authoring_sections( $event_id, $meta, $event_type, false ); ?>

            <div class="anchor-event-section" data-step="3">
                <h3><?php echo esc_html__( 'Location', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'Where it is held. Leave blank if there is no fixed venue.', 'anchor-schema' ); ?></p>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field"><label for="anchor_event_venue"><?php echo esc_html__( 'Venue', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_venue" name="anchor_event_venue" value="<?php echo esc_attr( $meta['venue'] ); ?>" /></div>
                    <div class="anchor-event-field"><label for="anchor_event_address_street"><?php echo esc_html__( 'Street', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_address_street" name="anchor_event_address_street" value="<?php echo esc_attr( $meta['address_street'] ); ?>" /></div>
                    <div class="anchor-event-field"><label for="anchor_event_address_city"><?php echo esc_html__( 'City', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_address_city" name="anchor_event_address_city" value="<?php echo esc_attr( $meta['address_city'] ); ?>" /></div>
                    <div class="anchor-event-field"><label for="anchor_event_address_state"><?php echo esc_html__( 'State', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_address_state" name="anchor_event_address_state" value="<?php echo esc_attr( $meta['address_state'] ); ?>" /></div>
                    <div class="anchor-event-field"><label for="anchor_event_address_zip"><?php echo esc_html__( 'Postal code', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_address_zip" name="anchor_event_address_zip" value="<?php echo esc_attr( $meta['address_zip'] ); ?>" /></div>
                    <div class="anchor-event-field"><label for="anchor_event_address_country"><?php echo esc_html__( 'Country', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_address_country" name="anchor_event_address_country" value="<?php echo esc_attr( $meta['address_country'] ); ?>" /></div>
                    <div class="anchor-event-field anchor-event-field--check"><span class="anchor-event-field-heading"><?php echo esc_html__( 'Format', 'anchor-schema' ); ?></span><label><input type="checkbox" id="anchor_event_virtual" name="anchor_event_virtual" value="1" <?php checked( $meta['virtual'] ); ?> /> <?php echo esc_html__( 'Virtual event', 'anchor-schema' ); ?></label></div>
                    <div class="anchor-event-field" id="anchor-event-virtual-url"><label for="anchor_event_virtual_url"><?php echo esc_html__( 'Virtual URL', 'anchor-schema' ); ?></label><input type="url" id="anchor_event_virtual_url" name="anchor_event_virtual_url" value="<?php echo esc_attr( $meta['virtual_url'] ); ?>" data-required-when-virtual="1" /></div>
                </div>
            </div>

            <div class="anchor-event-section" data-step="4">
                <h3><?php echo esc_html__( 'Status', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'Whether people can still sign up. Closing it keeps the event page online but takes the sign-up form off it.', 'anchor-schema' ); ?></p>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_status"><?php echo esc_html__( 'Event status', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_status" name="anchor_event_status">
                            <option value="auto" <?php selected( $meta['status_mode'], 'auto' ); ?>><?php echo esc_html__( 'Auto (based on dates)', 'anchor-schema' ); ?></option>
                            <?php foreach ( $this->get_status_options() as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $meta['status_mode'] === 'manual' && $meta['status'] === $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section" data-step="4">
                <h3><?php echo esc_html__( 'Registration', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'How many people can come, and what they are asked when they sign up here. If sign-ups happen on another site instead, choose External above.', 'anchor-schema' ); ?></p>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field anchor-event-field--check"><span class="anchor-event-field-heading"><?php echo esc_html__( 'Registration', 'anchor-schema' ); ?></span><label><input type="checkbox" id="anchor_event_registration_enabled" name="anchor_event_registration_enabled" value="1" <?php checked( $meta['registration_enabled'] ); ?> /> <?php echo esc_html__( 'Enable registration', 'anchor-schema' ); ?></label></div>
                    <?php
                    // Group parent only: the explicit "apply to all dates" action
                    // (MODEL-D40). Pre-escaped by the shared renderer.
                    echo $this->render_registration_apply_to_dates( $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?>
                    <div class="anchor-event-field anchor-event-registration-fields"><label for="anchor_event_capacity"><?php echo esc_html__( 'Capacity', 'anchor-schema' ); ?></label><input type="number" id="anchor_event_capacity" name="anchor_event_capacity" value="<?php echo esc_attr( $meta['capacity'] ); ?>" min="0" /></div>
                    <div class="anchor-event-field anchor-event-registration-fields anchor-event-field--check"><span class="anchor-event-field-heading"><?php echo esc_html__( 'Waitlist', 'anchor-schema' ); ?></span><label><input type="checkbox" id="anchor_event_waitlist" name="anchor_event_waitlist" value="1" <?php checked( $meta['waitlist'] ); ?> /> <?php echo esc_html__( 'Enable waitlist', 'anchor-schema' ); ?></label></div>
                    <div class="anchor-event-field anchor-event-registration-fields anchor-event-field--check"><span class="anchor-event-field-heading"><?php echo esc_html__( 'Availability', 'anchor-schema' ); ?></span><label><input type="checkbox" id="anchor_event_sold_out" name="anchor_event_sold_out" value="1" <?php checked( $meta['sold_out'] ); ?> /> <?php echo esc_html__( 'Sold out', 'anchor-schema' ); ?></label></div>
                    <div class="anchor-event-field anchor-event-registration-fields"><label for="anchor_event_registration_open"><?php echo esc_html__( 'Registration opens', 'anchor-schema' ); ?></label><input type="date" id="anchor_event_registration_open" name="anchor_event_registration_open" value="<?php echo esc_attr( $meta['registration_open'] ); ?>" /></div>
                    <div class="anchor-event-field anchor-event-registration-fields"><label for="anchor_event_registration_close"><?php echo esc_html__( 'Registration closes', 'anchor-schema' ); ?></label><input type="date" id="anchor_event_registration_close" name="anchor_event_registration_close" value="<?php echo esc_attr( $meta['registration_close'] ); ?>" /></div>
                    <div class="anchor-event-field anchor-event-registration-fields"><label for="anchor_event_price"><?php echo esc_html__( 'Price label', 'anchor-schema' ); ?></label><input type="text" id="anchor_event_price" name="anchor_event_price" value="<?php echo esc_attr( $meta['price'] ); ?>" /></div>
                </div>

            </div>

            <div class="anchor-event-section" data-step="4">
                <h3><?php echo esc_html__( 'Attendee questions', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'Anything you want to ask each person attending, on top of their name, email and phone. Each question becomes a column on the registration list and in the CSV export.', 'anchor-schema' ); ?></p>
                <div class="anchor-event-questions">
                    <?php // Task 28 — tells save_registration_questions() the repeater was on
                          // screen, so removing the last row CLEARS the questions instead of
                          // reading as "this form did not edit them". ?>
                    <input type="hidden" name="anchor_event_questions_present" value="1" />
                    <table class="widefat anchor-event-questions-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__( 'Question', 'anchor-schema' ); ?></th>
                                <th><?php echo esc_html__( 'Answer type', 'anchor-schema' ); ?></th>
                                <th><?php echo esc_html__( 'Choices', 'anchor-schema' ); ?></th>
                                <th><?php echo esc_html__( 'Required', 'anchor-schema' ); ?></th>
                                <th aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody class="anchor-event-questions-rows">
                            <?php foreach ( $this->get_registration_questions( $event_id ) as $qi => $q_row ) : ?>
                                <?php echo $this->event_question_row_html( (int) $qi, $q_row ); // already escaped ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p>
                        <button type="button" class="anchor-event-button-secondary anchor-event-question-add"><?php echo esc_html__( 'Add question', 'anchor-schema' ); ?></button>
                    </p>
                    <script type="text/html" id="anchor-event-question-template">
                        <?php echo $this->event_question_row_html( 0, null, true ); // already escaped ?>
                    </script>
                </div>
            </div>

            <div class="anchor-event-section anchor-event-conditional" data-when-mode="external" data-step="4">
                <h3><?php echo esc_html__( 'External Registration', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'Send people to a sign-up form somewhere else. Those names will live in that system, so they will not appear under Attendees here.', 'anchor-schema' ); ?></p>
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

            <div class="anchor-event-section anchor-event-conditional" data-when-mode="wc" data-step="4">
                <h3><?php echo esc_html__( 'Tickets / Pricing', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'Paid tickets. Add a row per price — early bird, standard, student — each with its own price, how many are available, and when it can be bought.', 'anchor-schema' ); ?></p>
                <?php echo $this->render_ticket_types_fields( $event_id, 'anchor-event-button-secondary' ); // already escaped ?>
            </div>

            <div class="anchor-event-section" data-step="4">
                <h3><?php echo esc_html__( 'Display controls', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'Which pages on the site this event shows up on.', 'anchor-schema' ); ?></p>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field anchor-event-field--check"><span class="anchor-event-field-heading"><?php echo esc_html__( 'Archive', 'anchor-schema' ); ?></span><label><input type="checkbox" id="anchor_event_hide_from_archive" name="anchor_event_hide_from_archive" value="1" <?php checked( $meta['hide_from_archive'] ); ?> /> <?php echo esc_html__( 'Hide from archive', 'anchor-schema' ); ?></label></div>
                    <div class="anchor-event-field anchor-event-field--check"><span class="anchor-event-field-heading"><?php echo esc_html__( 'Featured', 'anchor-schema' ); ?></span><label><input type="checkbox" id="anchor_event_featured" name="anchor_event_featured" value="1" <?php checked( $meta['featured'] ); ?> /> <?php echo esc_html__( 'Featured / pinned', 'anchor-schema' ); ?></label></div>
                    <div class="anchor-event-field"><label for="anchor_event_priority"><?php echo esc_html__( 'Priority order', 'anchor-schema' ); ?></label><input type="number" id="anchor_event_priority" name="anchor_event_priority" value="<?php echo esc_attr( $meta['priority'] ); ?>" /></div>
                </div>
            </div>

            <div class="anchor-event-section" data-step="5">
                <h3><?php echo esc_html__( 'Email Settings', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'The emails people get when they sign up, and the reminder before the event. Leave one alone and it keeps using the standard wording.', 'anchor-schema' ); ?></p>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_reminder_offsets"><?php echo esc_html__( 'Reminder offsets (days)', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_reminder_offsets" name="anchor_event_reminder_offsets" value="<?php echo esc_attr( $meta['reminder_offsets'] ); ?>" />
                        <p class="description"><?php echo esc_html__( 'Comma-separated days before start (e.g. 14,3,1). Leave blank to use the global default.', 'anchor-schema' ); ?></p>
                    </div>
                </div>

                <?php
                $sender_fields = [
                    'email_from_name'        => [ __( 'From name', 'anchor-schema' ),  'text',  \get_option( 'blogname' ) ],
                    'email_from_address'     => [ __( 'From email', 'anchor-schema' ), 'email', '' ],
                    'email_reply_to_address' => [ __( 'Reply-To', 'anchor-schema' ),   'email', '' ],
                    'email_cc'               => [ __( 'CC', 'anchor-schema' ),         'text',  'one@example.com, two@example.com' ],
                    'email_bcc'              => [ __( 'BCC', 'anchor-schema' ),        'text',  'one@example.com, two@example.com' ],
                ];
                $site_email = $this->get_settings();
                ?>
                <div class="anchor-event-sender">
                    <h4><?php echo esc_html__( 'Who these emails come from', 'anchor-schema' ); ?></h4>
                    <p class="anchor-event-hint"><?php
                        /* translators: %s: the site-wide From identity these fields fall back to. */
                        echo esc_html( sprintf(
                            __( 'Leave blank to use the site-wide setting: %s. CC and BCC add to the site-wide list rather than replacing it.', 'anchor-schema' ),
                            trim( ( $site_email['email_from_name'] ?? '' ) . ' <' . ( $site_email['email_from_address'] ?? '' ) . '>' )
                        ) );
                    ?></p>
                    <input type="hidden" name="anchor_event_sender_present" value="1" />
                    <div class="anchor-event-grid">
                        <?php foreach ( $sender_fields as $key => $meta_field ) :
                            list( $label, $input_type, $placeholder ) = $meta_field;
                            $value = (string) \get_post_meta( $event_id, '_anchor_event_' . $key, true ); ?>
                            <div class="anchor-event-field">
                                <label for="anchor_event_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
                                <input type="<?php echo esc_attr( $input_type ); ?>"
                                    id="anchor_event_<?php echo esc_attr( $key ); ?>"
                                    name="anchor_event_<?php echo esc_attr( $key ); ?>"
                                    value="<?php echo esc_attr( $value ); ?>"
                                    placeholder="<?php echo esc_attr( $placeholder ); ?>" />
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="anchor-event-hint"><?php echo esc_html__( 'Keep the From address on this site\'s own domain. Mail is signed for this domain, and a From somewhere else will be treated as spoofed and land in spam.', 'anchor-schema' ); ?></p>
                </div>

                <div class="anchor-event-emails" data-event="<?php echo esc_attr( $event_id ); ?>">
                    <p class="anchor-event-hint"><?php echo esc_html__( 'Each email has its own editor: the wording on the left with a visual editor, a live preview on the right, and an HTML tab if you want to rebuild the whole email.', 'anchor-schema' ); ?></p>
                    <input type="hidden" name="anchor_event_email_switches_present" value="1" />
                    <input type="hidden" name="anchor_event_email_cta_present" value="1" />
                    <ul class="anchor-event-email-list">
                        <?php foreach ( $this->email_type_labels() as $type => $label ) : ?>
                            <li>
                                <label class="anchor-event-email-switch">
                                    <input type="checkbox" name="anchor_event_email_on_<?php echo esc_attr( $type ); ?>" value="1" <?php checked( $this->is_email_enabled( $event_id, $type ) ); ?> />
                                    <span><?php echo esc_html( $label ); ?></span>
                                </label>
                                <button type="button" class="anchor-event-button-secondary anchor-event-email-open" data-email-type="<?php echo esc_attr( $type ); ?>">
                                    <?php echo esc_html__( 'Edit', 'anchor-schema' ); ?>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="anchor-event-hint"><?php echo esc_html__( 'Untick an email to stop this event sending it. All four are on by default.', 'anchor-schema' ); ?></p>

                    <?php foreach ( $this->email_type_labels() as $type => $label ) :
                        $subject_default = $this->email_field_default( $type, 'subject' );
                        $intro_default   = $this->email_field_default( $type, 'intro' );
                        ?>
                        <?php
                        /**
                         * tabindex so the dialog can take programmatic focus. It is
                         * opened with show(), not showModal(), so the browser moves
                         * focus nowhere on its own — and the Esc-to-close handler is
                         * bound to this element, so without focus landing inside it
                         * the key never arrives.
                         */
                        ?>
                        <dialog class="anchor-event-email-modal" tabindex="-1" data-email-modal="<?php echo esc_attr( $type ); ?>">
                            <div class="anchor-event-email-modal__head">
                                <h3><?php echo esc_html( sprintf( __( '%s email', 'anchor-schema' ), $label ) ); ?></h3>
                                <button type="button" class="anchor-event-email-close" aria-label="<?php echo esc_attr__( 'Close', 'anchor-schema' ); ?>">&times;</button>
                            </div>
                            <div class="anchor-event-email-modal__body">
                                <div class="anchor-event-email-fields">
                                    <label for="anchor_event_email_subject_<?php echo esc_attr( $type ); ?>"><?php echo esc_html__( 'Subject', 'anchor-schema' ); ?></label>
                                    <input type="text" id="anchor_event_email_subject_<?php echo esc_attr( $type ); ?>"
                                        name="anchor_event_email_subject_<?php echo esc_attr( $type ); ?>"
                                        value="<?php echo esc_attr( (string) \get_post_meta( $event_id, '_anchor_event_email_subject_' . $type, true ) ); ?>"
                                        placeholder="<?php echo esc_attr( $subject_default ); ?>" data-email-field="subject" />

                                    <label for="anchor_event_email_preheader_<?php echo esc_attr( $type ); ?>"><?php echo esc_html__( 'Preview text', 'anchor-schema' ); ?></label>
                                    <input type="text" id="anchor_event_email_preheader_<?php echo esc_attr( $type ); ?>"
                                        name="anchor_event_email_preheader_<?php echo esc_attr( $type ); ?>"
                                        value="<?php echo esc_attr( (string) \get_post_meta( $event_id, '_anchor_event_email_preheader_' . $type, true ) ); ?>"
                                        placeholder="<?php echo esc_attr__( 'Shown after the subject in the inbox', 'anchor-schema' ); ?>"
                                        data-email-field="preheader" />
                                    <p class="anchor-event-hint"><?php echo esc_html__( 'Hidden inside the email itself. Tokens work here, and in the subject.', 'anchor-schema' ); ?></p>

                                    <label for="anchor_event_email_intro_<?php echo esc_attr( $type ); ?>"><?php echo esc_html__( 'Opening lines', 'anchor-schema' ); ?></label>
                                    <?php
                                    /**
                                     * Plain textarea in the markup; the visual editor is attached to it
                                     * on open by wp.editor.initialize(). Initialising TinyMCE inside a
                                     * closed <dialog> measures a zero-height iframe, and four eager
                                     * instances (one per email type) would load on every event edit.
                                     */
                                    ?>
                                    <textarea id="anchor_event_email_intro_<?php echo esc_attr( $type ); ?>"
                                        class="anchor-event-email-intro"
                                        name="anchor_event_email_intro_<?php echo esc_attr( $type ); ?>" rows="8"
                                        placeholder="<?php echo esc_attr( $intro_default ); ?>" data-email-field="intro"><?php echo esc_textarea( (string) \get_post_meta( $event_id, '_anchor_event_email_intro_' . $type, true ) ); ?></textarea>
                                    <p class="anchor-event-hint"><?php echo esc_html__( 'This is the body of the email. Leave it blank to use the site-wide wording shown in grey.', 'anchor-schema' ); ?></p>
                                    <button type="button" class="anchor-event-button-secondary anchor-event-email-starter"
                                        data-starter="<?php echo esc_attr( $this->email_starter_copy( $type ) ); ?>">
                                        <?php echo esc_html__( 'Start from a draft', 'anchor-schema' ); ?>
                                    </button>

                                    <?php
                                    /**
                                     * REG-D26 — the resolved default is the PLACEHOLDER, never the
                                     * value. Printing it into value= meant the browser posted it
                                     * back and save_email_cta_fields() wrote it as explicit meta on
                                     * the first save, freezing a default that was designed to stay
                                     * live (a virtual event's room link would keep pointing at the
                                     * old room after the URL changed).
                                     */
                                    $cta_fields = [];
                                    foreach ( [ 1, 2 ] as $cta_slot ) {
                                        $cta_prefix   = '_anchor_event_email_cta' . ( $cta_slot === 2 ? '2' : '' ) . '_';
                                        $cta_defaults = $this->email_cta_defaults( $event_id, $cta_slot );
                                        foreach ( [ 'label', 'url' ] as $cta_field ) {
                                            $cta_key = $cta_prefix . $cta_field . '_' . $type;
                                            $cta_set = $event_id && \metadata_exists( 'post', (int) $event_id, $cta_key );
                                            $cta_fields[ $cta_slot ][ $cta_field ] = [
                                                'value'       => $cta_set ? (string) \get_post_meta( (int) $event_id, $cta_key, true ) : '',
                                                'placeholder' => (string) ( $cta_defaults[ $cta_field ] ?? '' ),
                                            ];
                                        }
                                    }
                                    $cta_hint = [
                                        'label' => __( 'Button text', 'anchor-schema' ),
                                        'url'   => 'https://',
                                    ];
                                    ?>
                                    <label for="anchor_event_email_cta_label_<?php echo esc_attr( $type ); ?>"><?php echo esc_html__( 'Button', 'anchor-schema' ); ?></label>
                                    <div class="anchor-event-email-cta">
                                        <input type="text" id="anchor_event_email_cta_label_<?php echo esc_attr( $type ); ?>"
                                            name="anchor_event_email_cta_label_<?php echo esc_attr( $type ); ?>"
                                            value="<?php echo esc_attr( $cta_fields[1]['label']['value'] ); ?>"
                                            placeholder="<?php echo esc_attr( $cta_fields[1]['label']['placeholder'] !== '' ? $cta_fields[1]['label']['placeholder'] : $cta_hint['label'] ); ?>" />
                                        <input type="url" id="anchor_event_email_cta_url_<?php echo esc_attr( $type ); ?>"
                                            name="anchor_event_email_cta_url_<?php echo esc_attr( $type ); ?>"
                                            value="<?php echo esc_attr( $cta_fields[1]['url']['value'] ); ?>"
                                            placeholder="<?php echo esc_attr( $cta_fields[1]['url']['placeholder'] !== '' ? $cta_fields[1]['url']['placeholder'] : $cta_hint['url'] ); ?>" />
                                    </div>

                                    <label for="anchor_event_email_cta2_label_<?php echo esc_attr( $type ); ?>"><?php echo esc_html__( 'Second button', 'anchor-schema' ); ?></label>
                                    <div class="anchor-event-email-cta">
                                        <input type="text" id="anchor_event_email_cta2_label_<?php echo esc_attr( $type ); ?>"
                                            name="anchor_event_email_cta2_label_<?php echo esc_attr( $type ); ?>"
                                            value="<?php echo esc_attr( $cta_fields[2]['label']['value'] ); ?>"
                                            placeholder="<?php echo esc_attr( $cta_hint['label'] ); ?>" />
                                        <input type="url" id="anchor_event_email_cta2_url_<?php echo esc_attr( $type ); ?>"
                                            name="anchor_event_email_cta2_url_<?php echo esc_attr( $type ); ?>"
                                            value="<?php echo esc_attr( $cta_fields[2]['url']['value'] ); ?>"
                                            placeholder="<?php echo esc_attr( $cta_hint['url'] ); ?>" />
                                    </div>
                                    <p class="anchor-event-hint"><?php echo esc_html__( 'A button shows only when it has both text and a link. Grey text is the default this event falls back to — fill a field in to override it, or clear a filled-in field to remove the button.', 'anchor-schema' ); ?></p>

                                    <?php
                                    /**
                                     * Two palettes, not one. A token only resolves in the field whose
                                     * expansion pass knows the key — see wording_email_tokens(). The
                                     * template group is hidden until the HTML view is open, so a block
                                     * token cannot be dropped into wording that will never expand it.
                                     */
                                    ?>
                                    <div class="anchor-event-email-tokens-group" data-token-scope="wording">
                                        <p class="anchor-event-email-tokens-label"><?php echo esc_html__( 'Insert a token', 'anchor-schema' ); ?></p>
                                        <div class="anchor-event-email-tokens">
                                            <?php foreach ( $this->wording_email_tokens() as $token ) : ?>
                                                <button type="button" class="anchor-event-token" data-token="{<?php echo esc_attr( $token ); ?>}">{<?php echo esc_html( $token ); ?>}</button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="anchor-event-email-tokens-group" data-token-scope="template" hidden>
                                        <p class="anchor-event-email-tokens-label"><?php echo esc_html__( 'Insert a token (HTML)', 'anchor-schema' ); ?></p>
                                        <?php $notes = $this->template_token_notes(); ?>
                                        <div class="anchor-event-email-tokens">
                                            <?php foreach ( $this->template_email_tokens() as $token ) : ?>
                                                <button type="button" class="anchor-event-token"
                                                    <?php echo isset( $notes[ $token ] ) ? 'title="' . esc_attr( $notes[ $token ] ) . '"' : ''; ?>
                                                    data-token="{<?php echo esc_attr( $token ); ?>}">{<?php echo esc_html( $token ); ?>}</button>
                                            <?php endforeach; ?>
                                        </div>
                                        <details class="anchor-event-email-legend">
                                            <summary><?php echo esc_html__( 'What do these do?', 'anchor-schema' ); ?></summary>
                                            <p><?php echo esc_html__( 'Each one is a region of the email, not a value. Several are conditional and render nothing when they do not apply — that is why a preview can look like they are broken.', 'anchor-schema' ); ?></p>
                                            <dl>
                                                <?php foreach ( $notes as $token => $note ) : ?>
                                                    <dt>{<?php echo esc_html( $token ); ?>}</dt>
                                                    <dd><?php echo esc_html( $note ); ?></dd>
                                                <?php endforeach; ?>
                                            </dl>
                                            <p><?php echo esc_html__( 'You are not required to keep any of them. To make the email nothing but what you write in Opening lines, delete the others from this template and leave {intro}.', 'anchor-schema' ); ?></p>
                                        </details>
                                        <button type="button" class="anchor-event-button-secondary anchor-event-email-media"><?php echo esc_html__( 'Insert image from library', 'anchor-schema' ); ?></button>
                                    </div>
                                </div>

                                <div class="anchor-event-email-preview-pane">
                                    <div class="anchor-event-email-toolbar">
                                        <button type="button" class="anchor-event-email-tab is-active" data-email-view="preview"><?php echo esc_html__( 'Preview', 'anchor-schema' ); ?></button>
                                        <button type="button" class="anchor-event-email-tab" data-email-view="html"><?php echo esc_html__( 'HTML', 'anchor-schema' ); ?></button>
                                        <span class="anchor-event-email-status" aria-live="polite"></span>
                                        <span class="anchor-event-email-note"><?php echo esc_html__( 'Preview fills empty tokens with sample data, and shows text regions a given recipient might not get.', 'anchor-schema' ); ?></span>
                                    </div>
                                    <iframe class="anchor-event-email-frame" title="<?php echo esc_attr( sprintf( __( '%s email preview', 'anchor-schema' ), $label ) ); ?>"></iframe>
                                    <?php $email_template = $this->resolve_email_template( $type, $event_id ); ?>
                                    <?php if ( ! $this->template_uses_brand_tokens( $email_template ) ) : ?>
                                        <?php
                                        /**
                                         * REG-D27 — this template opts into none of the appearance
                                         * tokens, so the colours and logo set in Settings reach it
                                         * only if it still contains the stock literal colours. Say
                                         * so here rather than let the branding silently apply to
                                         * nothing.
                                         */
                                        ?>
                                        <p class="anchor-event-hint anchor-event-email-appearance-warning">
                                            <?php echo esc_html__( 'This email uses its own HTML, so the colours and logo set in Settings may not reach it. Use the {brand_bg}, {brand_surface}, {brand_heading}, {brand_text}, {brand_button}, {brand_button_text} and {logo} tokens to opt back in.', 'anchor-schema' ); ?>
                                        </p>
                                    <?php endif; ?>
                                    <textarea class="anchor-event-email-source code" name="anchor_email_tpl_<?php echo esc_attr( $type ); ?>" rows="24" hidden><?php echo esc_textarea( $email_template ); ?></textarea>
                                </div>
                            </div>
                            <div class="anchor-event-email-modal__foot">
                                <button type="submit" class="anchor-event-button"><?php echo esc_html__( 'Save event', 'anchor-schema' ); ?></button>
                                <button type="button" class="anchor-event-button-secondary anchor-event-email-close"><?php echo esc_html__( 'Close', 'anchor-schema' ); ?></button>
                                <span class="anchor-event-hint"><?php echo esc_html__( 'Closing keeps your changes on the form; Save event writes them.', 'anchor-schema' ); ?></span>
                            </div>
                        </dialog>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="anchor-event-section" data-step="3">
                <h3><?php echo esc_html__( 'Featured image', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'The image used on the event card and at the top of the event page.', 'anchor-schema' ); ?></p>
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

            <div class="anchor-event-section" data-step="3">
                <h3><?php echo esc_html__( 'Photo gallery', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'Extra images for the event page. Optional.', 'anchor-schema' ); ?></p>
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

            <?php
            /**
             * Extra authoring fields for the front-end manager form.
             *
             * A theme that adds its own event metabox can print the same inputs
             * here (including its own nonce) and they save through the metabox's
             * existing save_post handler — handle_event_manager_save() goes through
             * wp_insert_post()/wp_update_post(), so save_post fires exactly as it
             * does in wp-admin. That keeps one save path per field set instead of
             * teaching this form about every theme's meta.
             *
             * @param int    $event_id 0 when creating a new event.
             * @param Module $module   This module instance.
             */
            \do_action( 'anchor_events_manager_form_fields', $event_id, $this );
            ?>

            <?php if ( $wizard ) : ?>
                <div class="anchor-event-wizard-nav" hidden>
                    <button type="button" class="anchor-event-button-secondary" data-wizard-back><?php echo esc_html__( 'Back', 'anchor-schema' ); ?></button>
                    <p class="anchor-event-wizard-where" role="status" aria-live="polite"></p>
                    <button type="button" class="anchor-event-button" data-wizard-next><?php echo esc_html__( 'Continue', 'anchor-schema' ); ?></button>
                </div>
            <?php endif; ?>

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

        // Nonce (above), then the module capability, then the per-post check. The
        // console that posts this form is gated on events_capability(); without
        // the same gate here a user who cannot open the console could still POST
        // to it and create or edit events.
        $capability_ok = Roster::current_user_can_manage() && ( $is_edit
            ? \current_user_can( 'edit_post', $event_id )
            : \current_user_can( self::CAP_BASE ) );
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

        // The save may have queued an authoring notice (offering rows that came
        // back empty, a duplicated date). It rides the SAME query arg as the
        // outcome code so the front-end form reports it too — before MODEL-D14
        // this path redirected with a bare "saved" and the author was told
        // nothing at all.
        \wp_safe_redirect( \add_query_arg(
            'event_manager_notice',
            \rawurlencode( $this->event_manager_notice_arg( $is_edit ? 'saved' : 'created', $saved_id ) ),
            $redirect
        ) );
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
            'sold_out' => ! empty( $_POST['anchor_event_sold_out'] ),
            // Task BC: see save_meta()'s matching comment — `registration_type`/
            // `registration_url` are deliberately absent from this front-end
            // form's $input too, for the identical reason (the legacy fields
            // no longer render here either; leaving them listed would blank
            // an old external event's real link on its next re-save).
            'price' => sanitize_text_field( $_POST['anchor_event_price'] ?? '' ),
            'hide_from_archive' => ! empty( $_POST['anchor_event_hide_from_archive'] ),
            'featured' => ! empty( $_POST['anchor_event_featured'] ),
            'priority' => (int) ( $_POST['anchor_event_priority'] ?? 0 ),
            'labels' => $this->labels_input( $_POST ),
            'gallery' => $this->sanitize_gallery_ids( $_POST['anchor_event_gallery'] ?? '' ),
            'reminder_offsets' => $this->sanitize_offset_csv( $_POST['anchor_event_reminder_offsets'] ?? '' ),
        ];

        // Event-type / registration-mode authoring UI (Task 1.3 metabox parity,
        // Task 1.5). Same six keys, same sanitize_event_type_input() helper as
        // save_meta() — see that method's docblock.
        $input = array_merge( $input, $this->sanitize_event_type_input( $_POST, $current_registration_mode ) );

        $status_raw = sanitize_text_field( $_POST['anchor_event_status'] ?? 'auto' );
        if ( $status_raw === 'auto' ) {
            $input['status_mode'] = 'auto';
            $input['status'] = $this->calculate_status( $input );
        } else {
            $input['status_mode'] = 'manual';
            // A value that is not an offered choice — including the retired
            // 'draft' (MODEL-D19) — falls back to what the DATES say rather
            // than to a hardcoded 'upcoming', so a dateless event does not
            // silently claim to be upcoming.
            $input['status'] = in_array( $status_raw, array_keys( $this->get_status_options() ), true )
                ? $status_raw
                : $this->calculate_status( $input );
        }

        // persist_timestamps() is the single writer for the derived rows: it
        // stamps `ts_version` alongside them, so a just-saved event is already
        // at the current schema version and backfill_timestamps() skips it.
        // The values are mirrored back into $input purely so the generic
        // update_post_meta() loop below (and this method's return value) still
        // carry them; the loop's writes are then no-ops.
        $timestamps = $this->persist_timestamps( $saved_id, $input );
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

        // Tickets, the auto-appended shortcode, email templates/wording/
        // switches/CTA/sender, attendee questions, group authoring and the
        // cache flush are the SAME shared tail the wp-admin metabox runs
        // (Task 28, MODEL-D24 / REG-D49) — one function, called from both, so
        // the two surfaces cannot drift on which fields a save persists.
        $this->persist_event_authoring( $saved_id, $_POST, $input ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the manager nonce is verified by the caller.

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
            $meta_query[] = $this->build_status_clause( $atts['status'] );
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
        // RENDER-D19: never render a card for an id that is not a published
        // event. The listing feeds this from an hour-long id cache, and
        // get_the_title()/get_permalink() happily resolve a trashed post — so
        // without this the list showed a live-looking card whose link 404s.
        // Cheap: get_post() is served from the post cache the render warms anyway.
        $post = \get_post( $post_id );
        if ( ! $post instanceof \WP_Post || $post->post_type !== self::CPT || $post->post_status !== 'publish' ) {
            return '';
        }

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
        $output .= $this->render_labels_badges( $post_id );
        $excerpt = \get_the_excerpt( $post_id );
        if ( $excerpt ) {
            $output .= '<div class="anchor-event-excerpt">' . esc_html( $excerpt ) . '</div>';
        }
        $output .= '<div class="anchor-event-actions"><a class="anchor-event-button" href="' . esc_url( \get_permalink( $post_id ) ) . '">' . esc_html__( 'View Event', 'anchor-schema' ) . '</a></div>';
        $output .= '</article>';

        \do_action( 'anchor_events_after_render', $post_id, $context );

        return $output;
    }

    /**
     * The event's labels as a badge list, or '' when the event has none.
     *
     * Each badge carries a per-key class (anchor-event-label-duration, ...) so a
     * theme can style or position one specific badge rather than receiving an
     * undifferentiated blob. The caption rides along in `data-caption` — the
     * badge text is just the value, which is what reads well at card size.
     *
     * Values are plain text (see sanitize_labels_rows()) and escaped here.
     *
     * @param int $post_id
     * @return string
     */
    private function render_labels_badges( $post_id ) {
        $labels = $this->get_labels( $post_id );
        if ( empty( $labels ) ) {
            return '';
        }

        $output = '<ul class="anchor-event-labels">';
        foreach ( $labels as $row ) {
            $output .= '<li class="anchor-event-label anchor-event-label-' . esc_attr( $row['key'] ) . '"'
                . ' data-label-key="' . esc_attr( $row['key'] ) . '"'
                . ' data-caption="' . esc_attr( $row['caption'] ) . '">'
                . esc_html( $row['value'] )
                . '</li>';
        }
        $output .= '</ul>';

        return $output;
    }

    /**
     * The on-screen answer to a registration POST (audit REG-D24).
     *
     * Two things used to be wrong here at once. A waitlisted registration and a
     * confirmed one came back under the SAME key, so somebody who had just been
     * put on a waitlist read "Registration received. Check your email for
     * confirmation." and had no way to learn otherwise until (or unless) the
     * email arrived. And that sentence promised an email unconditionally — it
     * was shown just the same when `notify_user` was off or the event's
     * confirmation email was switched off, i.e. when no email would ever come.
     *
     * So the outcome and the promise are now two separate facts: the key says
     * WHAT HAPPENED (received / waitlisted), and the `event_registration_email`
     * flag — set by handle_registration() only when the sender reported an
     * actual send — decides whether "check your email" is appended.
     */
    public function render_registration_notice() {
        if ( empty( $_GET['event_registration'] ) ) {
            return '';
        }
        $key = sanitize_text_field( \wp_unslash( $_GET['event_registration'] ) );
        $messages = [
            'registration_success' => __( 'Registration received.', 'anchor-schema' ),
            'registration_waitlisted' => __( 'This event is full — you have been added to the waitlist. We will be in touch if a seat opens up.', 'anchor-schema' ),
            'registration_closed' => __( 'Registration is closed for this event.', 'anchor-schema' ),
            'registration_invalid' => __( 'Please complete all required registration fields.', 'anchor-schema' ),
            'registration_error' => __( 'Registration could not be processed. Please try again.', 'anchor-schema' ),
        ];
        if ( ! isset( $messages[ $key ] ) ) {
            return '';
        }
        $message = $messages[ $key ];
        if ( ! empty( $_GET['event_registration_email'] ) ) {
            $message .= ' ' . __( 'Check your email for confirmation.', 'anchor-schema' );
        }
        return '<div class="anchor-event-notice">' . esc_html( $message ) . '</div>';
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
        // Labels read as "Duration: 2 Day Course" here — the single page has the
        // room for a caption, unlike the card, where the value alone is the badge.
        foreach ( $this->get_labels( $post_id ) as $label_row ) {
            $caption = $label_row['caption'] !== '' ? $label_row['caption'] . ': ' : '';
            $output .= '<div class="anchor-event-label-detail anchor-event-label-' . esc_attr( $label_row['key'] ) . '">'
                . '<strong>' . esc_html( $caption ) . '</strong>'
                . esc_html( $label_row['value'] )
                . '</div>';
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

        // Task 2.4: a group PARENT is a container, not directly bookable — it
        // never gets its own registration form/ticket UI, regardless of mode.
        // single-event.php renders render_choose_date_list() over its live
        // children instead. Checked before every other branch (including the
        // WC override filter below) so nothing can re-introduce a bookable
        // form on a parent page.
        if ( $this->occurrences->is_group_parent( $post_id ) ) {
            return '';
        }

        // Task 2.4 / Task 2.1 known quirk: a soft-closed group child is
        // excluded from its parent's live children()/siblings() set, but the
        // post itself is still directly reachable by URL. Its own
        // registration_enabled meta is NOT used to detect closedness here —
        // correction (review round): it actually reads back `false`
        // (get_meta_defaults()'s own schema default, and also written
        // explicitly by Occurrences::soft_close()), not `true` as an earlier
        // comment here claimed. It's still not used, because relying on it
        // would only work by coincidence of those two values agreeing — so
        // closedness is determined purely via the engine's own
        // children($parent,false) exclusion (never a meta read). A directly
        // visited closed child always gets this notice, never a booking form.
        if ( $this->is_closed_group_child( $post_id ) ) {
            return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'This date is no longer available.', 'anchor-schema' ) . '</div>';
        }

        // RENDER-D1 / WOO-D1: `registration_enabled` is the outermost gate on
        // ALL registration UI, so it is checked BEFORE the render seam below.
        // It used to sit after the apply_filters(), which meant WooCommerce's
        // callback could hand back a full ticket block + "Add to cart" button
        // for an event whose "Enable registration" box was unticked — the
        // buyer then got a generic "Could not add Ticket to the cart" from
        // is_purchasable(). Nothing on this filter may re-introduce a form for
        // a disabled event.
        if ( ! $meta['registration_enabled'] ) {
            // NEW-D2: the switch suppresses the FORM, not the fact. A course
            // that is sold out, finished or cancelled is still that, and
            // rendering nothing at all told the visitor less than the truth
            // (production child 7528: sold_out=1 + registration off, whose page
            // said only "Registration closed"). Same branch order, and the same
            // wording, as the seat-layer checks further down — no booking UI is
            // reachable from here either way.
            $state = $this->bookability( $post_id );
            if ( $state === 'full' ) {
                return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'This event is full.', 'anchor-schema' ) . '</div>';
            }
            if ( $state === 'closed' ) {
                return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'Registration is closed.', 'anchor-schema' ) . '</div>';
            }
            return '';
        }

        // Render seam (spec §3): the WooCommerce class swaps the free form for a
        // buy button on linked events by returning non-empty here. Inert until the
        // Phase 2 filter callback is registered (no consumers otherwise).
        $override = \apply_filters( 'anchor_events_registration_form', '', $post_id, $meta );
        if ( $override !== '' ) {
            return $override;
        }

        // WOO-D19: a paid event whose storefront produced nothing (WooCommerce
        // off, product missing, every paid tier deactivated) must NOT fall
        // through to the free internal form below — that would book free seats
        // on a ticketed course. Two wc-mode cases legitimately DO want the free
        // form and are exempted:
        //
        //  - the event still has an AUTHORED active free tier, so it is a
        //    bookable free course rather than a ticketed one (a free tier that
        //    `Ticket_Types::get()` merely synthesized does not count — see
        //    has_authored_free_tier()); and
        //  - WooCommerce's own mixed free+paid re-entry, which has already
        //    rendered the paid storefront and is now asking for the FREE-tier
        //    form to append to it. is_rendering_free() marks that nested call.
        //    It is subsumed by the free-tier test above (the re-entry only fires
        //    when an active free tier exists) but is kept explicit so the seam
        //    stays correct independently of how the tier predicate evolves.
        $wc_free_reentry = ( $this->woocommerce && $this->woocommerce->is_rendering_free( $post_id ) );
        $free_bookable   = $this->has_authored_free_tier( $post_id );
        if ( ! $wc_free_reentry && ! $free_bookable && $this->registration_mode( $post_id ) === 'wc' ) {
            return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'Tickets are not available right now.', 'anchor-schema' ) . '</div>';
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

        // The same authority the storefront, the cart, the picker and the
        // JSON-LD ask, so the free form cannot be the one reader that still
        // books a cancelled course. Past this point registration is on and the
        // event is neither a parent nor soft-closed, so the states reaching the
        // branches below are exactly the seat layer's own — plus 'closed' for a
        // hand-cancelled event, which is the one this form used to miss.
        $status = $this->bookability( $post_id );
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

        $questions = $this->get_registration_questions( $post_id );
        $redirect  = \get_permalink( $post_id );

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

        // Whatever else this event asks its attendees (REG-D9). The SAME question
        // model the WooCommerce checkout renders and the roster/CSV column onto,
        // so a free course and a ticketed one ask the same things and store the
        // answers under the same keys. `required` is asserted here for the
        // browser and re-checked in handle_registration() for everything else.
        foreach ( $questions as $q ) {
            $id       = 'anchor_event_field_' . $q['key'];
            $req_mark = ! empty( $q['required'] ) ? ' <span class="anchor-event-required" aria-hidden="true">*</span>' : '';

            $output .= '<div class="anchor-event-field">';
            $output .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $q['label'] ) . $req_mark . '</label>';
            $output .= $this->render_registration_question_control( $q, [
                'name' => 'anchor_event_field[' . $q['key'] . ']',
                'id'   => $id,
            ] );
            $output .= '</div>';
        }

        $button_label = isset( $settings['register_button_label'] ) && $settings['register_button_label'] !== ''
            ? $settings['register_button_label']
            : __( 'Register', 'anchor-schema' );
        $output .= '<button type="submit" class="anchor-event-button anchor-event-register">' . esc_html( $button_label ) . '</button>';
        $output .= '</form>';

        return $output;
    }

    /**
     * Whether a post is a group child whose occurrence is currently
     * soft-closed (Task 2.4). Deliberately does NOT read the
     * `registration_enabled`/`occurrence_closed` meta directly — correction
     * (review round): a closed child's `registration_enabled` actually reads
     * back `false` (get_meta_defaults()'s schema default, and also written
     * explicitly by Occurrences::soft_close()), not `true` as a previous
     * version of this comment claimed. It's still not read directly, because
     * the only trustworthy signal for "closed" is the engine's own
     * definition of it — whether children($parent_id, false) (live-only)
     * still includes this post — not a meta value that merely happens to
     * agree.
     *
     * @param int $post_id
     * @return bool
     */
    private function is_closed_group_child( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! $this->occurrences->is_group_child( $post_id ) ) {
            return false;
        }
        // The engine's own closed flag first — it is the exact predicate
        // children() filters on, and unlike the parent lookup below it survives
        // the parent being trashed or deleted (MODEL-D22). Without it, a
        // soft-closed occurrence orphaned by a deleted parent would read as
        // "not closed" and lose its "no longer available" notice.
        if ( $this->occurrences->is_closed( $post_id ) ) {
            return true;
        }
        $parent_id = $this->occurrences->parent_of( $post_id );
        if ( $parent_id <= 0 ) {
            return false;
        }
        // Still asked, because children() also drops an unpublished child and a
        // non-canonical duplicate of an occurrence key — neither of which the
        // closed flag records.
        return ! \in_array( $post_id, $this->occurrences->children( $parent_id, false ), true );
    }

    /**
     * "Choose a date" picker for a group PARENT single-event page (Task 2.4):
     * lists the parent's LIVE children — Occurrences::children($parent_id,
     * false), which already excludes soft-closed occurrences via the
     * engine's own bookkeeping (never a meta-value check) — ordered by
     * Occurrences::order_by_bookability(): the dates a visitor can act on
     * first, then upcoming-but-unbookable ones, then the ones that have been
     * and gone, each block earliest-first. Every live date is still listed
     * (somebody looking for the date they booked has to find it) and each row
     * says which kind it is (MODEL-D4 / NEW-D1) — the picker used to lead
     * with a sold-out or finished date and put a live Register CTA on it.
     * Each row carries a
     * date/time label, an availability hint sourced from the same seat-layer
     * capacity authority the registration form uses, and a link to that
     * child's own page. The parent is a container, never directly bookable,
     * so single-event.php renders THIS in place of render_registration_form()
     * on a parent page (see that method's is_group_parent() guard).
     *
     * @param int $parent_id
     * @return string
     */
    public function render_choose_date_list( $parent_id ) {
        $parent_id = (int) $parent_id;
        $children  = $this->occurrences->order_by_bookability(
            $this->occurrences->children( $parent_id, false )
        );

        $output  = '<section class="anchor-event-choose-date">';
        $output .= '<h2 class="anchor-event-choose-date-title">' . esc_html__( 'Choose a date', 'anchor-schema' ) . '</h2>';

        if ( empty( $children ) ) {
            $output .= '<p class="anchor-event-choose-date-empty">' . esc_html__( 'No dates currently scheduled.', 'anchor-schema' ) . '</p>';
            $output .= '</section>';
            return $output;
        }

        $output .= '<ul class="anchor-event-choose-date-list">';
        foreach ( $children as $child_id ) {
            $output .= $this->render_choose_date_row( (int) $child_id );
        }
        $output .= '</ul>';
        $output .= '</section>';

        return $output;
    }

    /**
     * "Other dates" sibling picker for a group CHILD single-event page (Task
     * 2.4): lists the child's LIVE siblings — Occurrences::siblings($child_id,
     * false), same soft-close exclusion as render_choose_date_list() — plus a
     * link back to the parent's own choose-a-date page, so a visitor can
     * switch dates from either a live child or a soft-closed one (a closed
     * child's own page still shows this block alongside the "no longer
     * available" registration notice — see render_registration_form()'s
     * is_closed_group_child() guard). Empty string for anything that is not
     * a group child at all.
     *
     * @param int $child_id
     * @return string
     */
    public function render_sibling_dates( $child_id ) {
        $child_id = (int) $child_id;
        if ( ! $this->occurrences->is_group_child( $child_id ) ) {
            return '';
        }
        $parent_id = $this->occurrences->parent_of( $child_id );
        if ( $parent_id <= 0 ) {
            return '';
        }
        $siblings = $this->occurrences->order_by_bookability(
            $this->occurrences->siblings( $child_id, false )
        );

        $output  = '<section class="anchor-event-other-dates">';
        $output .= '<h2 class="anchor-event-other-dates-title">' . esc_html__( 'Other dates', 'anchor-schema' ) . '</h2>';

        if ( empty( $siblings ) ) {
            $output .= '<p class="anchor-event-other-dates-empty">' . esc_html__( 'No other dates currently scheduled.', 'anchor-schema' ) . '</p>';
        } else {
            $output .= '<ul class="anchor-event-choose-date-list anchor-event-other-dates-list">';
            foreach ( $siblings as $sibling_id ) {
                $output .= $this->render_choose_date_row( (int) $sibling_id );
            }
            $output .= '</ul>';
        }

        // FIX 2 (review round): a seated child stays published via soft-close
        // even after its parent is trashed (Module::retire_children_on_parent_trash()),
        // so the parent's own permalink can 404 in that state. Only link to it
        // when the parent is still actually published; the sibling list above
        // (if any) still renders either way.
        if ( \get_post_status( $parent_id ) === 'publish' ) {
            $output .= '<p class="anchor-event-other-dates-all"><a class="anchor-event-other-dates-link" href="' . esc_url( \get_permalink( $parent_id ) ) . '">' . esc_html__( 'See all dates', 'anchor-schema' ) . '</a></p>';
        }
        $output .= '</section>';

        return $output;
    }

    /**
     * One row shared by render_choose_date_list() and render_sibling_dates()
     * (Task 2.4): date/time, the occurrence's own label, an availability
     * hint, and a register-or-details link to the occurrence's own page —
     * the brief calls for "date + time + label" (FIX 1, review round). Pure
     * display — reads capacity through the existing seat-layer authority
     * (Registrations::remaining_capacity()/get_registration_status()) exactly
     * like render_registration_form() already does, never Woo stock.
     *
     * @param int $event_id A live child (or the parent itself, defensively).
     * @return string
     */
    private function render_choose_date_row( $event_id ) {
        $event_id = (int) $event_id;
        $meta     = $this->get_meta( $event_id );
        $label    = $this->occurrence_label( $event_id, $meta );

        $date_text = $this->format_date_time( $meta, true );

        // Same predicate the ordering above ranks on, so the row's mark and
        // its position can never describe the date differently.
        $state   = $this->occurrences->picker_state( $event_id );
        $output  = '<li class="anchor-event-choose-date-row anchor-event-choose-date-row--' . esc_attr( $state ) . '">';
        $output .= '<a class="anchor-event-choose-date-link" href="' . esc_url( \get_permalink( $event_id ) ) . '">';
        $output .= '<span class="anchor-event-choose-date-date">' . esc_html( $date_text ) . '</span>';
        // An occurrence with no authored label resolves to the formatted date
        // (occurrence_label()'s fallback), which is the line directly above —
        // print it once, not twice.
        if ( $label !== '' && $label !== $date_text ) {
            $output .= '<span class="anchor-event-choose-date-label">' . esc_html( $label ) . '</span>';
        }
        $output .= '</a>';
        $output .= '<span class="anchor-event-choose-date-availability">' . esc_html( $this->choose_date_availability_hint( $event_id, $meta ) ) . '</span>';
        $output .= '<a class="anchor-event-button anchor-event-choose-date-cta" href="' . esc_url( \get_permalink( $event_id ) ) . '">' . esc_html( $this->choose_date_cta_label( $state ) ) . '</a>';
        $output .= '</li>';

        return $output;
    }

    /**
     * Occurrence-specific label for a choose-date row (Task 2.4 FIX 1 —
     * review found the brief's "date + time + label" requirement unmet).
     *
     * The authored label is the child's own `label` meta, written from the
     * offering row by Occurrences::apply_occurrence_editable_fields() — the
     * only source. When there is none (an unlabelled row, or an event that is
     * not a group child at all), the formatted date/time stands in.
     *
     * It used to prefer a second source: the suffix of the child's post_title,
     * sliced off against the parent's title prefix. That made a display string
     * the storage for structured data, and it broke the moment the two drifted
     * (audit MODEL-D10): quick-editing the parent's title fires save_post but
     * save_meta() returns early with no nonce, so reconcile() never re-titles
     * the children — the computed prefix stopped matching and every authored
     * label silently became a bare date until someone re-saved the parent. The
     * meta branch was the documented "if a future engine writes one" fallback
     * that nothing ever wrote (MODEL-D27 / RENDER-D22); it now has a writer,
     * and the slicing branch is gone. Occurrences::child_title() still bakes
     * the label into the title — for display only.
     *
     * @param int   $event_id
     * @param array $meta get_meta( $event_id ) — passed in to avoid a
     *                     redundant lookup by the (only) caller.
     * @return string
     */
    private function occurrence_label( $event_id, array $meta ) {
        $meta_label = \get_post_meta( (int) $event_id, $this->meta_key( 'label' ), true );
        if ( \is_string( $meta_label ) && $meta_label !== '' ) {
            return $meta_label;
        }

        return $this->format_date_time( $meta, true );
    }

    /**
     * Short availability hint for a choose-date row, rendered from
     * bookability() — the single purchasability authority — as "Sold out" /
     * "Waitlist only" / "Date passed" / "Registration closed" / "N spot(s)
     * left" / "Open" (unlimited capacity).
     *
     * Public because the series archive renders the same hint for the same
     * question (MODEL-D42): Series::availability_hint() used to re-decide it
     * from remaining_capacity() alone and got a weaker answer — it printed
     * "Open" for a hand-flagged sold-out occurrence.
     *
     * @param int   $event_id
     * @param array $meta     get_meta( $event_id ) — passed in by callers that
     *                        already hold it (only the capacity read uses it).
     * @return string
     */
    public function choose_date_availability_hint( $event_id, array $meta ) {
        // RENDER-D32 / MODEL-D42: one call to the single purchasability
        // authority, not a local re-decision. "The seats went" outranking "the
        // button is gone" used to be re-decided here, by asking the seat layer
        // again whenever bookability() said 'disabled'; that judgement is now
        // bookability()'s own branch order (NEW-D2), so every reader gets it.
        $state = $this->bookability( $event_id );

        if ( $state === 'full' ) {
            return \__( 'Sold out', 'anchor-schema' );
        }
        if ( $state === Registrations::STATUS_WAITLIST ) {
            return \__( 'Waitlist only', 'anchor-schema' );
        }
        if ( $state === 'closed' || $state === 'disabled' ) {
            // MODEL-D4: a date that has been and gone is still LISTED — the
            // picker no longer leads with it, but somebody looking for the
            // date they attended has to find it — so it gets the word for what
            // it actually is rather than "Registration closed", which reads
            // like a date you just missed the deadline for.
            return $this->occurrences->is_past( $event_id )
                ? \__( 'Date passed', 'anchor-schema' )
                : \__( 'Registration closed', 'anchor-schema' );
        }

        // 'open' — and 'parent', which is now the ONE container state that
        // reaches this line: a group with nothing left to book answers
        // 'full'/'disabled'/'closed' above and gets that word, and a group
        // that still has a bookable date has no capacity of its own, so it
        // reads as unlimited/"Open". render_choose_date_row() is documented
        // as tolerating a parent id for exactly this reason.
        $capacity = (int) ( $meta['capacity'] ?? 0 );
        if ( $capacity <= 0 ) {
            return \__( 'Open', 'anchor-schema' );
        }
        $remaining = $this->registrations->remaining_capacity( $event_id, $capacity );
        /* translators: %d: number of remaining spots. */
        return \sprintf( \_n( '%d spot left', '%d spots left', $remaining, 'anchor-schema' ), $remaining );
    }

    /**
     * CTA label for a choose-date row: "Details" when the occurrence isn't
     * currently accepting new registrations (closed/full/registration
     * disabled/a container/a date that has been and gone), else "Register".
     *
     * Takes the row's already-resolved Occurrences::picker_state() rather than
     * re-asking bookability() — RENDER-D32's point is that the CTA, the hint
     * beside it and the row's position in the picker are one answer, so the
     * row can never say "Sold out" and "Register" at once, or rank a date as
     * bookable and then label it "Details".
     *
     * @param string $picker_state 'bookable'|'unavailable'|'past'.
     * @return string
     */
    private function choose_date_cta_label( $picker_state ) {
        return $picker_state === 'bookable'
            ? \__( 'Register', 'anchor-schema' )
            : \__( 'Details', 'anchor-schema' );
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
        // Renderer and handler have to agree on what "external" means, and they
        // did not: render_registration_form() routes on registration_mode()
        // (line ~5453) while this only checked the LEGACY registration_type.
        // An event authored through the mode select stores registration_mode
        // and deliberately never writes registration_type (see save_meta()'s
        // Task BC note), so for exactly those events the front end shows an
        // outbound link while this handler would still claim a seat.
        //
        // That is reachable: REG_NONCE is a bare action nonce, not bound to
        // event_id, so a nonce lifted from any internal event's form posts
        // fine against an external one — phantom seats on somebody else's
        // roster, and a confirmation email to go with them.
        if ( $meta['registration_type'] === 'external' || $this->registration_mode( $event_id ) === 'external' ) {
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

        // The event's own attendee questions (REG-D9/D10). Read from the question
        // model rather than from whatever the POST happened to carry, so the
        // stored answer set is exactly this event's questions, keyed by their
        // stable ids — the same shape the WooCommerce checkout writes.
        $posted_answers = ( ! empty( $_POST['anchor_event_field'] ) && is_array( $_POST['anchor_event_field'] ) )
            ? wp_unslash( $_POST['anchor_event_field'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per answer below.
            : [];
        $validated    = $this->sanitize_registration_answers( $event_id, $posted_answers );
        $extra_fields = $validated['answers'];
        // Required answers are enforced server-side too: the inputs carry
        // `required`, but that only covers a browser that runs it.
        if ( ! empty( $validated['missing'] ) ) {
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_invalid' ) );
            exit;
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

        // A hand-cancelled course takes no seats (THEME-D25). The form has not
        // rendered for one since bookability() started answering 'closed' for
        // it, but REG_NONCE is a bare action nonce, so a POST can still arrive
        // from a stale page — the same reasoning as the external-mode guard
        // above. Not folded into the decision below because that one carries
        // the party size and the tier; this is a property of the event.
        if ( $this->get_event_status( $event_id, $meta ) === 'cancelled' ) {
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_closed' ) );
            exit;
        }

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

        // REG-D24 — the seat that was actually minted decides what the visitor
        // is told, and whether an email was actually sent decides whether one
        // is promised. Both used to be assumed.
        $is_waitlist = ( $waitlisted && ! $created );
        $reg_status  = $is_waitlist ? Registrations::STATUS_WAITLIST : Registrations::STATUS_CONFIRMED;
        $emailed     = $this->send_registration_emails( $event_id, $name, $email, $reg_status, $guests );

        $url = $this->with_message( $redirect, $is_waitlist ? 'registration_waitlisted' : 'registration_success' );
        if ( $emailed->is_sent() ) {
            $url = \add_query_arg( 'event_registration_email', '1', $url );
        }
        \wp_safe_redirect( $url );
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
        \add_settings_field( 'email_cc', __( 'CC (optional)', 'anchor-schema' ), function() use ( $email_text_field ) {
            $email_text_field( 'email_cc', 'text', 'one@example.com, two@example.com' );
        }, 'anchor-events-settings', 'anchor_events_email_section' );
        \add_settings_field( 'email_bcc', __( 'BCC (optional)', 'anchor-schema' ), function() use ( $email_text_field ) {
            $email_text_field( 'email_bcc', 'text', 'one@example.com, two@example.com' );
        }, 'anchor_events_settings', 'anchor_events_email_sender' );

        \add_settings_section( 'anchor_events_email_appearance', __( 'Email Appearance', 'anchor-schema' ), function() {
            echo '<p>' . esc_html__( 'Basic branding for event confirmation, reminder, cancellation, and roster emails.', 'anchor-schema' ) . '</p>';
        }, 'anchor_events_settings' );

        \add_settings_field( 'email_logo_url', __( 'Logo URL', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <?php
            // Loaded here rather than in admin_assets(), which only runs on the
            // event edit screen. Calling it during the settings render still works:
            // the media templates print on admin_footer.
            \wp_enqueue_media();
            ?>
            <span class="anchor-logo-field">
                <input type="url" id="anchor_event_email_logo_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[email_logo_url]" value="<?php echo esc_attr( $opts['email_logo_url'] ); ?>" class="regular-text" placeholder="https://example.com/logo.png" />
                <button type="button" class="button" id="anchor-logo-choose"><?php echo esc_html__( 'Choose image', 'anchor-schema' ); ?></button>
                <button type="button" class="button-link-delete" id="anchor-logo-clear"<?php echo $opts['email_logo_url'] === '' ? ' hidden' : ''; ?>><?php echo esc_html__( 'Remove', 'anchor-schema' ); ?></button>
            </span>
            <p class="description"><?php echo esc_html__( 'Optional logo shown above event email content. Pick one from the media library, or paste any public image URL.', 'anchor-schema' ); ?></p>
            <p><img id="anchor-logo-preview" src="<?php echo esc_url( $opts['email_logo_url'] ); ?>" alt="" style="max-width:220px;height:auto;<?php echo $opts['email_logo_url'] === '' ? 'display:none;' : ''; ?>" /></p>
            <script>
            (function () {
                var input   = document.getElementById( 'anchor_event_email_logo_url' );
                var choose  = document.getElementById( 'anchor-logo-choose' );
                var clear   = document.getElementById( 'anchor-logo-clear' );
                var preview = document.getElementById( 'anchor-logo-preview' );
                if ( ! input || ! choose ) { return; }

                function show( url ) {
                    input.value = url;
                    preview.src = url;
                    preview.style.display = url ? '' : 'none';
                    clear.hidden = ! url;
                }

                choose.addEventListener( 'click', function () {
                    if ( ! window.wp || ! wp.media ) { return; }
                    var picker = wp.media( {
                        title: <?php echo wp_json_encode( __( 'Choose a logo', 'anchor-schema' ) ); ?>,
                        library: { type: 'image' },
                        multiple: false,
                        button: { text: <?php echo wp_json_encode( __( 'Use this image', 'anchor-schema' ) ); ?> }
                    } );
                    picker.on( 'select', function () {
                        var img = picker.state().get( 'selection' ).first().toJSON();
                        // Full size on purpose: an email logo is rendered at a fixed
                        // width and a thumbnail would be resampled up.
                        show( img.url );
                    } );
                    picker.open();
                } );

                clear.addEventListener( 'click', function () { show( '' ); } );
                input.addEventListener( 'input', function () { show( input.value ); } );
            })();
            </script>
            <?php
        }, 'anchor_events_settings', 'anchor_events_email_appearance' );

        $email_color_field = function( $key, $fallback ) {
            $opts = $this->get_settings();
            $value = \sanitize_hex_color( $opts[ $key ] ?? '' ) ?: $fallback;
            printf(
                '<input type="color" name="%1$s[%2$s]" value="%3$s" />',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $key ),
                esc_attr( $value )
            );
        };
        \add_settings_field( 'email_background_color', __( 'Email background', 'anchor-schema' ), function() use ( $email_color_field ) {
            $email_color_field( 'email_background_color', '#f4f4f4' );
        }, 'anchor_events_settings', 'anchor_events_email_appearance' );
        \add_settings_field( 'email_card_color', __( 'Content background', 'anchor-schema' ), function() use ( $email_color_field ) {
            $email_color_field( 'email_card_color', '#ffffff' );
        }, 'anchor_events_settings', 'anchor_events_email_appearance' );
        \add_settings_field( 'email_text_color', __( 'Text color', 'anchor-schema' ), function() use ( $email_color_field ) {
            $email_color_field( 'email_text_color', '#333333' );
        }, 'anchor_events_settings', 'anchor_events_email_appearance' );
        \add_settings_field( 'email_heading_color', __( 'Heading color', 'anchor-schema' ), function() use ( $email_color_field ) {
            $email_color_field( 'email_heading_color', '#111111' );
        }, 'anchor_events_settings', 'anchor_events_email_appearance' );
        \add_settings_field( 'email_button_color', __( 'Button color', 'anchor-schema' ), function() use ( $email_color_field ) {
            $email_color_field( 'email_button_color', '#111111' );
        }, 'anchor_events_settings', 'anchor_events_email_appearance' );

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

        \add_settings_field( 'refund_subject', __( 'Refund subject', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[refund_subject]" value="<?php echo esc_attr( $opts['refund_subject'] ); ?>" class="regular-text" />
            <?php
        }, 'anchor_events_settings', 'anchor_events_lifecycle_emails' );

        \add_settings_field( 'refund_intro', __( 'Refund email intro', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[refund_intro]" rows="3" class="large-text"><?php echo esc_textarea( $opts['refund_intro'] ); ?></textarea>
            <p class="description"><?php echo esc_html__( 'Sent instead of the cancellation wording when a seat is refunded. Tokens: {event_title}, {attendee_name}, {status}, {site_name}.', 'anchor-schema' ); ?></p>
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
        $output['email_cc']               = \implode( ', ', $this->email_address_list( $input['email_cc'] ?? '' ) );
        $output['email_bcc']              = \implode( ', ', $this->email_address_list( $input['email_bcc'] ?? '' ) );
        $output['email_logo_url']         = esc_url_raw( $input['email_logo_url'] ?? '' );
        $output['email_background_color'] = \sanitize_hex_color( $input['email_background_color'] ?? '' ) ?: $defaults['email_background_color'];
        $output['email_card_color']       = \sanitize_hex_color( $input['email_card_color'] ?? '' ) ?: $defaults['email_card_color'];
        $output['email_text_color']       = \sanitize_hex_color( $input['email_text_color'] ?? '' ) ?: $defaults['email_text_color'];
        $output['email_heading_color']    = \sanitize_hex_color( $input['email_heading_color'] ?? '' ) ?: $defaults['email_heading_color'];
        $output['email_button_color']     = \sanitize_hex_color( $input['email_button_color'] ?? '' ) ?: $defaults['email_button_color'];

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
        $output['refund_subject']       = \sanitize_text_field( $input['refund_subject'] ?? '' ) ?: $defaults['refund_subject'];
        $output['refund_intro']         = \sanitize_textarea_field( $input['refund_intro'] ?? '' ) ?: $defaults['refund_intro'];
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
     * "Clear error log" button. Capped to Module::events_capability().
     */
    private function render_error_log_panel() {
        if ( ! Roster::current_user_can_manage() ) {
            return;
        }

        $log = \get_option( Events_Log::ERROR_OPTION, [] );
        if ( ! \is_array( $log ) ) {
            $log = [];
        }

        echo '<hr style="margin:24px 0;" />';
        echo '<h2>' . \esc_html__( 'Event error log', 'anchor-schema' ) . '</h2>';
        echo '<p class="description">' . \esc_html__( 'Recent registration/email/sync failures. Most recent first.', 'anchor-schema' ) . '</p>';

        $archive = \get_option( Events_Log::ERROR_ARCHIVE_OPTION, [] );
        $archive = \is_array( $archive ) ? $archive : [];

        if ( isset( $_GET['anchor_events_log_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $moved = (int) $_GET['anchor_events_log_cleared']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            // Only when something actually moved — "cleared 0 entries" is noise.
            if ( $moved > 0 ) {
                echo '<div class="notice notice-success inline"><p>' . \esc_html( \sprintf(
                    /* translators: %d: number of entries moved to the archive. */
                    \_n( 'Error log cleared. %d entry kept in the archive.', 'Error log cleared. %d entries kept in the archive.', $moved, 'anchor-schema' ),
                    $moved
                ) ) . '</p></div>';
            }
        }

        if ( empty( $log ) ) {
            echo '<p>' . \esc_html__( 'No errors logged.', 'anchor-schema' ) . '</p>';
        } else {
            $this->render_error_log_table( $log );
        }

        // REG-D31 — the archive is the "undo" for the Clear button, so it has to
        // be readable from here. Collapsed, read-only, same row markup.
        if ( ! empty( $archive ) ) {
            echo '<details style="margin-top:12px;max-width:840px;"><summary style="cursor:pointer;">'
                . \esc_html( \sprintf(
                    /* translators: %d: number of archived entries. */
                    \_n( 'Archived entries (%d)', 'Archived entries (%d)', \count( $archive ), 'anchor-schema' ),
                    \count( $archive )
                ) ) . '</summary>';
            echo '<p class="description">' . \esc_html__( 'Entries removed by a previous "Clear error log". Kept read-only so a cleared failure is still recoverable.', 'anchor-schema' ) . '</p>';
            $this->render_error_log_table( $archive );
            echo '</details>';
        }

        if ( empty( $log ) ) {
            return;
        }

        echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
        echo '<input type="hidden" name="action" value="anchor_events_clear_error_log" />';
        \wp_nonce_field( 'anchor_events_clear_error_log' );
        // REG-D31 — a confirm step, and the copy says the entries are archived
        // rather than destroyed so nobody presses this expecting either outcome.
        \submit_button(
            \__( 'Clear error log', 'anchor-schema' ),
            'delete',
            'submit',
            false,
            [ 'onclick' => 'return confirm(' . \wp_json_encode( \__( 'Clear the error log? The entries move to the archive and stay recoverable.', 'anchor-schema' ) ) . ');' ]
        );
        echo '</form>';
    }

    /**
     * Render one error-log table. Shared by the live log and the archive so the
     * two can never drift apart (REG-D31/REG-D46). Read-only; escapes everything.
     *
     * @param array $rows Log entries, oldest first.
     */
    private function render_error_log_table( array $rows ) {
        echo '<table class="widefat striped" style="max-width:840px;">';
        echo '<thead><tr>';
        echo '<th>' . \esc_html__( 'Last seen', 'anchor-schema' ) . '</th>';
        echo '<th>' . \esc_html__( 'Code', 'anchor-schema' ) . '</th>';
        echo '<th>' . \esc_html__( 'Count', 'anchor-schema' ) . '</th>';
        echo '<th>' . \esc_html__( 'Context', 'anchor-schema' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( \array_slice( \array_reverse( $rows ), 0, 100 ) as $entry ) {
            $entry   = \is_array( $entry ) ? $entry : [];
            $time    = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
            $first   = isset( $entry['first_time'] ) ? (int) $entry['first_time'] : $time;
            $count   = isset( $entry['count'] ) ? max( 1, (int) $entry['count'] ) : 1;
            $code    = isset( $entry['code'] ) ? (string) $entry['code'] : '';
            $context = isset( $entry['context'] ) ? $entry['context'] : [];
            $when    = $time ? \date_i18n( 'Y-m-d H:i:s', $time ) : '';
            $ctx_str = \is_scalar( $context ) ? (string) $context : \wp_json_encode( $context );
            $tally   = $count > 1 && $first
                ? \sprintf(
                    /* translators: 1: repeat count, 2: first-seen timestamp. */
                    \__( '%1$d× since %2$s', 'anchor-schema' ),
                    $count,
                    \date_i18n( 'Y-m-d H:i:s', $first )
                )
                : (string) $count;
            echo '<tr>';
            echo '<td>' . \esc_html( $when ) . '</td>';
            echo '<td><code>' . \esc_html( $code ) . '</code></td>';
            echo '<td>' . \esc_html( $tally ) . '</td>';
            echo '<td style="word-break:break-word;">' . \esc_html( (string) $ctx_str ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * admin-post handler: clear the site-wide event error log. Nonce, then
     * Module::events_capability(). Lives in the Module (not the WC class) because the error log and its
     * panel are present on all sites, WooCommerce or not.
     */
    public function handle_clear_error_log() {
        \check_admin_referer( 'anchor_events_clear_error_log' );
        if ( ! Roster::current_user_can_manage() ) {
            \wp_die( \esc_html__( 'You are not allowed to do this.', 'anchor-schema' ) );
        }
        // REG-D31 — archive rather than destroy. These entries are the ONLY
        // record of email_send_returned_false / capacity_lock_unavailable /
        // illegal_transition / seat_insert_failed; the seat history does not
        // carry them, and there is no per-event activity log to fall back on
        // (REG-D30 retired the no-op that pretended otherwise).
        $archived = Events_Log::archive_and_clear();

        $redirect = \wp_get_referer();
        if ( ! $redirect ) {
            $redirect = \admin_url();
        }
        \wp_safe_redirect( \add_query_arg( 'anchor_events_log_cleared', (string) $archived, $redirect ) );
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
        // The plain CPT archive leaves out hidden events AND soft-closed group
        // children (a soft-closed child stays post_status=publish so its roster
        // survives, and used to show up here as a normal "View Event" card that
        // dead-ends in render_registration_form()'s "no longer available"
        // notice). Both facts now live in build_hide_clause(), so every listing
        // query gets them — see that builder for why they were merged.
        $meta_query = [ $this->build_hide_clause() ];
        if ( ! empty( $settings['archive_hide_past'] ) ) {
            $meta_query[] = $this->build_visibility_clause();
        }
        $query->set( 'meta_query', $meta_query );
        $query->set( 'meta_key', $this->meta_key( 'start_ts' ) );
        $query->set( 'orderby', 'meta_value_num' );
        $query->set( 'order', 'ASC' );
    }

    /**
     * Permanent deletion of an event or a seat (`before_delete_post`).
     *
     * Both the listing/calendar id cache AND the per-event capacity transients
     * have to go (REG-D18 / WOO-D47). This used to clear only the former, and
     * only through a registry that never held the caps keys, so production
     * ended up with `_transient_anchor_evt_caps_7909` reporting a refunded
     * seat against zero `anchor_event_reg` posts.
     *
     * The seat's `_anchor_event_id` is read HERE, on `before_delete_post`,
     * because by the time the post is gone so is its meta.
     *
     * @param int $post_id Post being deleted.
     */
    public function clear_caches_on_delete( $post_id ) {
        $this->bust_caches_for_post( $post_id, \get_post_type( $post_id ) );
    }

    /**
     * Any status change on an event or a seat (`transition_post_status`).
     *
     * Trash, untrash, unpublish and publish all land here, and every one of
     * them changes what the caches say: a trashed seat drops out of the
     * counts() aggregate, a trashed event drops out of every listing query.
     * `before_delete_post` only ever fired on PERMANENT deletion, which left a
     * trashed event rendering a card that 404s for up to an hour (RENDER-D19).
     *
     * Only transitions that cross `publish` do any work. Every cached read is
     * publish-scoped — both get_cached_ids() callers query
     * `post_status => 'publish'`, and the counts()/tier_counts() aggregate is
     * `WHERE p.post_status = 'publish'` — so a move between two non-public
     * statuses (the 'new' -> 'draft' of a first save, an auto-draft, one draft
     * revision to the next, trash -> draft on untrash) cannot change a single
     * cached value, and bumping the listing generation for it would throw away
     * every site visitor's warm cache on an author's private edit.
     *
     * @param string   $new_status
     * @param string   $old_status
     * @param \WP_Post $post
     */
    public function clear_caches_on_transition( $new_status, $old_status, $post ) {
        if ( ! $post instanceof \WP_Post || $new_status === $old_status ) {
            return;
        }
        if ( $new_status !== 'publish' && $old_status !== 'publish' ) {
            return;
        }
        if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        $this->bust_caches_for_post( $post->ID, $post->post_type );
    }

    /**
     * One invalidation path for both hooks above: resolve the affected EVENT
     * (a seat's parent, or the event itself) and hand it to the one function
     * that knows the capacity transient key names.
     *
     * @param int    $post_id
     * @param string $post_type
     */
    private function bust_caches_for_post( $post_id, $post_type ) {
        if ( $post_type !== self::CPT && $post_type !== self::REG_CPT ) {
            return;
        }

        $event_id = $post_type === self::REG_CPT
            ? (int) \get_post_meta( $post_id, '_anchor_event_id', true )
            : (int) $post_id;

        if ( $event_id > 0 && $this->registrations ) {
            // Busts anchor_evt_caps_{id} + anchor_evt_tier_caps_{id} AND calls
            // clear_caches() for the listing group.
            $this->registrations->bust_cache( $event_id );
            return;
        }

        $this->clear_caches();
    }

    /**
     * Invalidate every cached listing/calendar id list.
     *
     * One option write. The generation counter is part of each transient key
     * (see get_cached_ids()), so nothing has to be enumerated and there is no
     * registry to race with — the read-then-overwrite on `anchor_events_cache_keys`
     * could drop a key written by a concurrent request, stranding that
     * transient past every future clear (RENDER-D20). Superseded transients
     * are left to expire on their own hour TTL.
     */
    public function clear_caches() {
        $version = (int) \get_option( self::CACHE_VERSION_OPTION, 1 );
        \update_option( self::CACHE_VERSION_OPTION, $version + 1, false );
    }

    /**
     * One-time removal of the retired `anchor_events_cache_keys` registry.
     *
     * Self-clearing (the row is gone after the first pass) and idempotent, so
     * it needs no schema-version option. Same admin_init + capability gate as
     * the back-fills: admin_init is NOT an authenticated hook.
     */
    public function cleanup_legacy_cache_registry() {
        if ( ! \current_user_can( 'edit_posts' ) ) {
            return;
        }
        if ( \get_option( self::CACHE_OPTION, null ) !== null ) {
            \delete_option( self::CACHE_OPTION );
        }
    }

    private function get_cached_ids( $args ) {
        // The generation counter makes clear_caches() a single option write —
        // see its docblock (RENDER-D20).
        $key = 'anchor_events_' . (int) \get_option( self::CACHE_VERSION_OPTION, 1 )
            . '_' . md5( wp_json_encode( $args ) );
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

        return $ids;
    }

    private function count_events_by_status( $status ) {
        $query = new \WP_Query( [
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [ $this->build_status_clause( $status ) ],
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

        // BC read-map (MODEL-D19): a status stored under its old name reads as
        // the current one, so the admin column, the card classes, the JSON-LD
        // and both authoring forms all agree on what a legacy row means while
        // backfill_status_values() works through the site in batches.
        $defaults['status'] = $this->normalize_status( $defaults['status'] );

        // BC fallback (Task BC): a pre-upgrade external event only ever wrote
        // the legacy `registration_url` meta and never got a chance to write
        // `external_url` — the loop above would otherwise return '' here even
        // though the event plainly has a real registration link. Route every
        // consumer of get_meta() (render_external_registration(),
        // Event_Schema::build_external_offer(), and both authoring forms'
        // pre-filled "External URL" field) through the single external_url()
        // accessor so they all resolve the same way. See that method's
        // docblock for why this is belt-and-suspenders with the
        // registration_url -> external_url copy in migrate_registration_mode().
        $defaults['external_url'] = $this->external_url( $post_id );

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
     * The event's external registration URL, for external-mode display
     * (render_external_registration()) and JSON-LD (Event_Schema::
     * build_external_offer()) — both read this via get_meta()['external_url'],
     * which calls this method (Task BC).
     *
     * An explicit `_anchor_event_external_url` wins; otherwise falls back to
     * the legacy `_anchor_event_registration_url` meta. This is the live-read
     * half of the BC fix: a pre-upgrade external event only ever wrote the
     * legacy key, and this fallback means its link resolves correctly even on
     * a site where the one-time migrate_registration_mode() migration below
     * has NOT run yet (first page load after upgrade). The migration's own
     * registration_url -> external_url copy is the belt-and-suspenders half,
     * for any OTHER code that might read `_anchor_event_external_url` meta
     * directly via get_post_meta() instead of through this accessor/get_meta().
     *
     * @param int $event_id
     * @return string
     */
    public function external_url( $event_id ) {
        $explicit = (string) \get_post_meta( $event_id, $this->meta_key( 'external_url' ), true );
        if ( $explicit !== '' ) {
            return $explicit;
        }
        return (string) \get_post_meta( $event_id, $this->meta_key( 'registration_url' ), true );
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

        // MODEL-D23: the cached pointer used to be tested with empty() alone, so
        // an event whose managed product had been DELETED (or a site migrated
        // without its products) still derived 'wc' — render_registration_form()
        // then routed to the WooCommerce branch with nothing to sell and the
        // event became unbookable instead of falling back to the free form.
        // Product_Sync::managed_product_id() is the validated accessor (it also
        // rejects a trashed product and a pointer copied from another event);
        // without WooCommerce there is no Product_Sync, so validate in place.
        $managed_product = $this->product_sync
            ? $this->product_sync->managed_product_id( $event_id )
            : (int) \get_post_meta( $event_id, $this->meta_key( 'managed_product' ), true );
        if ( $managed_product > 0 && \get_post_type( $managed_product ) === 'product' ) {
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
     *
     * Task BC extension: for an event this derives as `external`, also copies
     * the legacy `registration_url` into the new `external_url` key (only
     * when `external_url` is still empty) — the migration mapping half of the
     * external-URL BC fix (see external_url()'s docblock for the live-read
     * fallback half). The legacy `registration_url`/`registration_type` meta
     * is intentionally left in place, never cleared — other code (and this
     * migration itself, if ever re-run) still reads it.
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
            $mode = $this->derive_registration_mode( $event_id );
            \update_post_meta( $event_id, $this->meta_key( 'registration_mode' ), $mode );

            if ( $mode === 'external' ) {
                $existing_external_url = (string) \get_post_meta( $event_id, $this->meta_key( 'external_url' ), true );
                if ( $existing_external_url === '' ) {
                    $legacy_url = (string) \get_post_meta( $event_id, $this->meta_key( 'registration_url' ), true );
                    if ( $legacy_url !== '' ) {
                        \update_post_meta( $event_id, $this->meta_key( 'external_url' ), $legacy_url );
                    }
                }
            }
        }

        \update_option( 'anchor_events_regmode_migrated', true, false );
    }

    /**
     * Send every event back through the timestamp back-fill, because the site's
     * timezone moved (audit NEW-D3).
     *
     * `start_ts`/`end_ts` are the event's authored local date+time RESOLVED
     * against a zone, and the zone most events resolve against is the site's
     * (an event's own `timezone` row is the exception, not the rule). Change
     * Settings → General and every one of those stored instants is now an hour
     * — or six — from the time printed on the event's own page, and nothing
     * recomputed them: the listing order, the reminder window, the registration
     * close, the "past" sweep and the JSON-LD instant all kept the old zone
     * until somebody happened to re-save each event by hand.
     *
     * Both markers have to go. The site option is the migration's "finished"
     * flag, and the per-event `ts_version` stamps are what its selection query
     * matches on — dropping only the option would re-run a pass that selects
     * nothing. One DELETE for the whole key (the key is this module's, on this
     * module's CPT), and the ordinary batched pass rewrites the rows over the
     * next few capability-gated admin loads.
     *
     * DEPLOY NOTE: this is the automatic half of what used to be a manual
     * "re-save every event after a timezone change" step. It still needs an
     * admin page load by a user who can edit events — backfill_timestamps() is
     * capability-gated on purpose (admin_init is not an authenticated hook) —
     * so on a site with no admin traffic the rows stay stale until one happens.
     */
    public function invalidate_stored_timestamps() {
        \delete_option( 'anchor_events_ts_version' );
        // The per-event stamps are the batch selector; leaving them would make
        // the re-run a no-op.
        \delete_post_meta_by_key( $this->meta_key( 'ts_version' ) );
    }

    /**
     * Batched, VERSIONED back-fill of start_ts/end_ts (audit MODEL-D2).
     *
     * Legacy events never had the `_ts` keys written — nothing back-filled them
     * — and every listing query orders by `start_ts` (meta_key + orderby =
     * meta_value_num), which INNER-JOINs postmeta. An event without the row was
     * therefore absent from the archive, [events_list], [event_calendar],
     * [event_manager] and [event_registrants_list] entirely, not merely
     * unsorted.
     *
     * It is versioned, not one-time, because the rows can go stale without ever
     * going missing. The first shipped version of calculate_timestamps() left a
     * date-only event with `end == start` — midnight on its own start date — so
     * once Registrations::capacity_decision() learned to close an event whose
     * `end_ts` is in the past, every such event closed at 00:00 on the morning
     * it ran. A back-fill keyed on "has no start_ts row" cannot see those
     * events at all: their rows are present, just computed under the old rules.
     * So selection is by `_anchor_event_ts_version` — missing, or older than
     * TS_SCHEMA_VERSION — and every event this touches is stamped with the
     * current version, including one with no `start_date` that can never get
     * `_ts` rows at all. The same selector is what makes the pass RE-RUNNABLE
     * without a version bump: changing the site's timezone drops both markers
     * (invalidate_stored_timestamps()) and this runs again over the whole
     * population, because the rows it wrote were computed against a zone the
     * site no longer keeps. Stamping the unfillable ones is what keeps them from
     * occupying the batch window forever and stranding the fillable ones behind
     * them.
     *
     * Runs on admin_init in batches of 200, and only records the option once a
     * batch comes back short — so a site with thousands of events converges over
     * a few admin page loads instead of timing out on one, and a site with no
     * events at all finishes on the first call. Because every processed event is
     * stamped, a full batch always makes progress and the migration cannot loop.
     *
     * admin_init is NOT an authenticated hook: wp-admin/admin-post.php fires it
     * before it validates the auth cookie, and this module registers
     * admin_post_nopriv_anchor_event_register and ..._manager_login — so a
     * logged-out visitor's registration POST reaches this method. Hence the
     * capability check: the back-fill only ever runs for a user who could have
     * edited the events by hand, never inline on a public request.
     */
    public function backfill_timestamps() {
        if ( ! \current_user_can( 'edit_posts' ) ) {
            return;
        }
        // The pre-versioning boolean flag is deliberately NOT consulted as a
        // done-marker: a site that set it ran the v1 rules, which is exactly the
        // state this migration exists to repair. It is deleted below once the
        // versioned option supersedes it.
        if ( (int) \get_option( 'anchor_events_ts_version' ) >= self::TS_SCHEMA_VERSION ) {
            return;
        }

        $batch = 200;
        $ids = \get_posts( [
            'post_type' => self::CPT,
            'post_status' => 'any',
            'posts_per_page' => $batch,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'meta_query' => [
                'relation' => 'OR',
                // Never computed under a versioned schema — every event that
                // predates this migration, whether or not it has `_ts` rows.
                //
                // This arm cannot be folded into the one below as a single
                // `compare => '!='`. WP_Meta_Query DOES nest negative operators
                // in a NOT EXISTS subquery, but only for `compare_key` — the
                // comparison against the meta KEY name (class-wp-meta-query.php
                // ~L655 switches on $meta_compare_key, not $meta_compare). A
                // value-level `!=` gets a plain join, so it matches only posts
                // that HAVE the row, and every never-stamped event — the whole
                // legacy population this migration exists for — is skipped.
                // Tried, measured: 6 of the 13 Test_Timestamps cases fail, all
                // of them the missing-row ones.
                [ 'key' => $this->meta_key( 'ts_version' ), 'compare' => 'NOT EXISTS' ],
                // Computed, but under older rules than the ones in force now.
                [ 'key' => $this->meta_key( 'ts_version' ), 'value' => self::TS_SCHEMA_VERSION, 'compare' => '<', 'type' => 'NUMERIC' ],
            ],
        ] );

        // One query for the whole batch instead of one per get_meta() call:
        // get_meta() reads ~40 keys per event through get_post_meta(), and with
        // a cold meta cache that is 200 uncached round trips on an admin page
        // load. Priming the cache up front makes them all cache hits.
        if ( ! empty( $ids ) ) {
            \update_meta_cache( 'post', $ids );
        }

        $written = 0;
        foreach ( $ids as $event_id ) {
            // v3: drop the minted offset row BEFORE reading the meta the
            // recompute uses, so the event is timed by the site's zone from
            // this pass on rather than by a string DateTimeZone rejects.
            $this->drop_minted_timezone_row( $event_id );
            $meta = $this->get_meta( $event_id );
            if ( empty( $meta['start_date'] ) ) {
                // Unfillable, but still stamped — see the docblock. Without the
                // stamp a window of 200 dateless posts comes back every pass.
                \update_post_meta( $event_id, $this->meta_key( 'ts_version' ), self::TS_SCHEMA_VERSION );
                continue;
            }
            $this->persist_timestamps( $event_id, $meta );
            $written++;
        }

        // Recomputed events change what every cached listing should contain —
        // an event that was invisible to the start_ts ordering join a moment
        // ago now sorts into [events_list], the calendar and the archive, and a
        // v1 event that read as already-over is bookable again. The listing
        // caches are keyed by query args, not by post state, so they would
        // otherwise keep serving the pre-backfill result until they expired on
        // their own.
        if ( $written > 0 ) {
            $this->clear_caches();
        }

        // A short batch means nothing is left to process. Every event the query
        // returns is stamped before the loop ends, so the window always moves
        // and this condition is always eventually reached.
        if ( count( $ids ) < $batch ) {
            \update_option( 'anchor_events_ts_version', self::TS_SCHEMA_VERSION, false );
            // Superseded by the versioned option; leaving it would only invite a
            // future reader to treat it as authoritative again.
            \delete_option( 'anchor_events_ts_backfilled' );
        }
    }

    /**
     * Delete a `_anchor_event_timezone` row that only restates the site's own
     * gmt_offset — the leftover of get_meta_defaults() minting "UTC-6" at read
     * time and Occurrences::sync_shared_meta() writing that invention down as
     * real data on every occurrence child (audit MODEL-D37, cleanup deferred
     * from the get_meta_defaults() fix).
     *
     * GROUP CHILDREN ONLY. The mint had exactly one writer — the inheritance
     * copy — so a child is the one post whose "UTC-6" provably nobody typed.
     * On a single event or a group parent the identical string is a value an
     * author picked out of the timezone field (the field offers the UTC±N
     * choices WordPress itself offers), and deleting it would hand that event
     * to whatever the site setting becomes next: the event does not move today,
     * it moves the day somebody edits Settings → General. A fixed offset is a
     * poor choice — it does not observe DST, which is what
     * timezone_notice_html() says on every events screen — but it is the
     * author's, and a migration is not the place to overrule it.
     *
     * Only the site's OWN offset goes. An offset that differs ("UTC-5" on a
     * -06:00 site) cannot have come from gmt_offset, so somebody chose it, and
     * deleting it would silently move the event by an hour; a named zone is
     * always an author's choice. What is removed is exactly the value that
     * `''` already resolves to, so no event's computed instant changes.
     *
     * @param int $event_id
     */
    private function drop_minted_timezone_row( $event_id ) {
        if ( ! $this->occurrences->is_group_child( $event_id ) ) {
            return;
        }
        $stored = (string) \get_post_meta( $event_id, $this->meta_key( 'timezone' ), true );
        if ( $stored === '' || ! \preg_match( '/^UTC[+-]/i', $stored ) ) {
            return;
        }
        if ( $this->normalize_timezone( $stored ) !== $this->normalize_timezone( '' ) ) {
            return; // An offset nobody could have minted from this site's setting.
        }
        \delete_post_meta( $event_id, $this->meta_key( 'timezone' ) );
    }

    /**
     * One-time rewrite of status values stored under an old name — today just
     * 'draft' -> 'undated' (audit MODEL-D19).
     *
     * normalize_status() already makes every READER treat a legacy row as
     * 'undated', so nothing is broken while this runs; what it buys is that
     * the rows on disk stop saying a word that means something else in
     * WordPress. Production carried 7 published events whose
     * `_anchor_event_status` was 'draft', which the admin Status column
     * printed as "Draft" next to a post whose post_status was "Published".
     *
     * Deliberately NOT folded into backfill_timestamps(): that migration is
     * versioned on TS_SCHEMA_VERSION, which describes the DERIVED TIMESTAMP
     * rows, and bumping it to carry a value rename would force every event on
     * every site running this plugin to recompute start_ts/end_ts for no
     * reason. This one gets its own `anchor_events_status_version` option.
     *
     * It also needs no per-event stamp, unlike the timestamp back-fill: the
     * selection predicate is self-clearing. Every row it matches is rewritten
     * to a value the predicate no longer matches, so the window always moves
     * and the pass cannot loop. Same batching (200 per admin page load, option
     * recorded only when a batch comes back short) and the same capability
     * gate, for the same reason: admin_init is NOT an authenticated hook —
     * admin-post.php fires it before it validates the auth cookie, and this
     * module registers nopriv handlers — so a logged-out visitor's POST
     * reaches this method.
     */
    public function backfill_status_values() {
        if ( ! \current_user_can( 'edit_posts' ) ) {
            return;
        }
        if ( (int) \get_option( 'anchor_events_status_version' ) >= self::STATUS_SCHEMA_VERSION ) {
            return;
        }

        $legacy = array_keys( self::LEGACY_STATUS_ALIASES );
        $batch  = 200;
        $ids    = $legacy ? \get_posts( [
            'post_type' => self::CPT,
            'post_status' => 'any',
            'posts_per_page' => $batch,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'meta_query' => [
                [ 'key' => $this->meta_key( 'status' ), 'value' => $legacy, 'compare' => 'IN' ],
            ],
        ] ) : [];

        foreach ( $ids as $event_id ) {
            $stored = (string) \get_post_meta( $event_id, $this->meta_key( 'status' ), true );
            \update_post_meta( $event_id, $this->meta_key( 'status' ), $this->normalize_status( $stored ) );
        }

        // The renamed rows are what the status meta_queries match on, and the
        // listing caches are keyed by query args rather than by post state.
        if ( ! empty( $ids ) ) {
            $this->clear_caches();
        }

        if ( count( $ids ) < $batch ) {
            \update_option( 'anchor_events_status_version', self::STATUS_SCHEMA_VERSION, false );
        }
    }

    /**
     * One-time back-fill of the occurrence `label` meta on children created
     * before it existed (audit MODEL-D10 / MODEL-D27).
     *
     * occurrence_label() now reads that meta and nothing else. Every child
     * created or re-synced from here on gets it written by
     * Occurrences::apply_occurrence_editable_fields(), but an ALREADY
     * materialized child carries its authored label only in the parent's
     * offering_dates row (and, for display, inside its own post_title). Without
     * this pass, every published group would show a bare date in "Choose a
     * date" until somebody happened to re-save its parent — including DEKA's
     * FACE CODE dates ("October 23-24, 2026"), which no one would think to
     * re-save. Reconciling every parent instead would create/trash posts as a
     * side effect of an upgrade; this only writes one meta row per child.
     *
     * Reads the parent's desired rows through Occurrences::desired_rows_by_key(),
     * NOT `offering_dates` directly, so a RECURRING parent's children are
     * covered too — their rows come from expand_recurrence() over the parent's
     * rule, and re-deriving the offering path here would have silently skipped
     * them. Today an expanded row's label is always empty: expand_recurrence()
     * reads `label` off the rule, but no authoring UI or sanitizer ever writes
     * one (audit MODEL-D35), so a recurring occurrence has never HAD an
     * authored label — '' is the honest value, and the renderer falls back to
     * the formatted date exactly as it did before. If D35 is fixed later, the
     * labels arrive by the ordinary route rather than needing this pass again:
     * writing a label onto the rule IS a parent save, which reconciles and
     * stamps every child through apply_occurrence_editable_fields().
     *
     * Idempotent and self-terminating: it selects children that have NO label
     * row at all and ALWAYS writes one (an unlabelled row writes ''), so the
     * window always moves, a hand-edited label is never clobbered, and a second
     * pass is a no-op. Skipping a write instead would leave those children in
     * the query's window for ever — on a site with more than one batch of them
     * the short-batch flag would never be set and this would re-run on every
     * admin request, doing nothing, indefinitely. Batched at 200 per admin
     * request, and capability-gated for the same reason backfill_timestamps()
     * is — admin_init fires before auth on admin-post.php.
     */
    public function backfill_occurrence_labels() {
        if ( ! \current_user_can( 'edit_posts' ) ) {
            return;
        }
        if ( \get_option( 'anchor_events_occurrence_labels_backfilled' ) ) {
            return;
        }

        $batch = 200;
        $ids = \get_posts( [
            'post_type' => self::CPT,
            'post_status' => 'any',
            'posts_per_page' => $batch,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'meta_query' => [
                'relation' => 'AND',
                [ 'key' => $this->meta_key( 'group_role' ), 'value' => 'child', 'compare' => '=' ],
                [ 'key' => $this->meta_key( 'label' ), 'compare' => 'NOT EXISTS' ],
            ],
        ] );

        if ( ! empty( $ids ) ) {
            \update_meta_cache( 'post', $ids );
        }

        $labels_by_parent = [];
        foreach ( $ids as $child_id ) {
            $child_id  = (int) $child_id;
            $parent_id = (int) \get_post_meta( $child_id, $this->meta_key( 'group_id' ), true );

            if ( $parent_id > 0 && ! isset( $labels_by_parent[ $parent_id ] ) ) {
                $labels = [];
                foreach ( $this->occurrences->desired_rows_by_key( $parent_id ) as $key => $row ) {
                    $labels[ $key ] = (string) ( $row['label'] ?? '' );
                }
                $labels_by_parent[ $parent_id ] = $labels;
            }

            // The child's own key, spelled the way the parent's rows are — one
            // normalizer for both sides, so a pre-MODEL-D8 date-only key means
            // the same thing here as it does to reconcile().
            $key = $this->occurrences->stored_occurrence_key( $child_id );

            // An orphaned child (no parent, or a key its parent no longer
            // offers) is still stamped with '' — the row must leave the query's
            // window or the batch would return it for ever.
            \update_post_meta( $child_id, $this->meta_key( 'label' ), $labels_by_parent[ $parent_id ][ $key ] ?? '' );
        }

        if ( count( $ids ) < $batch ) {
            \update_option( 'anchor_events_occurrence_labels_backfilled', 1, false );
        }
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

    /**
     * Whether the event has an AUTHORED active free tier — i.e. somebody
     * deliberately published a free ticket for it.
     *
     * This is deliberately narrower than `! empty( get_active_free_tiers() )`.
     * `Ticket_Types::get()` synthesizes an implicit primary tier (price from the
     * legacy `price` field, `active => true`) for any event with no stored tier
     * rows, so on an event with NO tier authoring at all the plain helper reports
     * a free tier that nobody ever created. The WOO-D19 guard in
     * render_registration_form() keys off this method because that exact case —
     * a `wc` event with no tiers and no product — is the bug it exists to close;
     * treating the synthesized placeholder as a real free ticket would hand the
     * free form straight back to a ticketed course.
     *
     * (That `get()` does not itself report whether it synthesized is the gap
     * recorded as WOO-D6; when that is fixed, this should read the flag instead
     * of re-checking the stored meta.)
     *
     * @param int $event_id
     * @return bool
     */
    private function has_authored_free_tier( $event_id ) {
        $stored = \get_post_meta( $event_id, Ticket_Types::META_KEY, true );
        if ( ! \is_array( $stored ) || empty( $stored ) ) {
            return false;
        }
        return ! empty( $this->get_active_free_tiers( $event_id ) );
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

    /**
     * The statuses an author may PIN an event to in manual mode. Doubles as
     * the allow-list both save paths validate a submitted status against.
     *
     * 'draft' is deliberately absent (MODEL-D19). It was never a choice: it is
     * what calculate_status() returns for an event with no start date, and
     * offering it as a manual option let an author pin a fully dated event to
     * it for ever, under a name that collides with WordPress's post_status.
     * Its replacement, 'undated', is not offered either — it is computed, and
     * an author who wants an event hidden has post_status for that.
     *
     * @return array<string,string> value => label.
     */
    private function get_status_options() {
        return [
            'upcoming' => __( 'Upcoming', 'anchor-schema' ),
            'ongoing' => __( 'Ongoing', 'anchor-schema' ),
            'past' => __( 'Past', 'anchor-schema' ),
            'cancelled' => __( 'Cancelled', 'anchor-schema' ),
        ];
    }

    /**
     * Every status a reader can encounter, labelled — the manual choices plus
     * the computed-only ones. Display goes through here rather than
     * ucfirst()ing the raw value, so a renamed state is renamed in one place.
     *
     * @return array<string,string> value => label.
     */
    private function get_status_labels() {
        return $this->get_status_options() + [
            'undated' => __( 'Undated', 'anchor-schema' ),
        ];
    }

    /**
     * The human label for a stored or computed status value.
     *
     * @param string $status
     * @return string
     */
    private function status_label( $status ) {
        $status = $this->normalize_status( $status );
        $labels = $this->get_status_labels();
        return $labels[ $status ] ?? ucfirst( $status );
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
     * Compute AND persist an event's derived timestamp rows, stamped with the
     * schema version they were computed under.
     *
     * The single writer for `start_ts`/`end_ts`/`ts_version`. Every path that
     * has ever minted those rows — the metabox save, the front-end manager
     * save, the status transition, the REST meta write and the Occurrences
     * engine (parent roll-up and child creation) — goes through here, so the
     * version stamp can never drift out of step with the values it describes.
     * A freshly saved event is therefore already at TS_SCHEMA_VERSION and the
     * back-fill skips it.
     *
     * Public for the same reason compute_timestamps() is: the Occurrences
     * engine lives in its own class and must not duplicate this.
     *
     * @param int   $post_id
     * @param array $meta Meta array with start_date/end_date/start_time/end_time/timezone/all_day.
     * @return array{start:int,end:int} The timestamps just written.
     */
    public function persist_timestamps( $post_id, array $meta ) {
        $timestamps = $this->calculate_timestamps( $meta );
        \update_post_meta( $post_id, $this->meta_key( 'start_ts' ), (int) $timestamps['start'] );
        \update_post_meta( $post_id, $this->meta_key( 'end_ts' ), (int) $timestamps['end'] );
        \update_post_meta( $post_id, $this->meta_key( 'ts_version' ), self::TS_SCHEMA_VERSION );
        return $timestamps;
    }

    /**
     * Re-derive and persist an auto-mode event's status. No-op in manual mode —
     * an author who picked a status by hand owns it.
     *
     * Shared by persist_status_on_transition() and the REST after-insert hook
     * so the two agree on what "auto status" means.
     *
     * Public for the same reason persist_timestamps() is: the Occurrences
     * engine lives in its own class and re-derives a group parent's status
     * from the span it just wrote (MODEL-D32). One writer, so "auto status"
     * cannot come to mean two things.
     *
     * @param int   $post_id
     * @param array $meta
     */
    public function persist_auto_status( $post_id, array $meta ) {
        if ( ( $meta['status_mode'] ?? 'auto' ) === 'manual' ) {
            return;
        }
        $computed = $this->calculate_status( $meta );
        $key      = $this->meta_key( 'status' );

        // Compare against the ROW, not against $meta['status'] (MODEL-D18).
        //
        // get_meta() falls back to the schema default — 'upcoming' — so an
        // event that has never had the row written is indistinguishable here
        // from one holding 'upcoming', and the old value comparison found no
        // difference and wrote nothing. That is invisible to a reader, because
        // every status meta_query matches the VALUE and a value comparison
        // INNER-JOINs postmeta: 6 published, future-dated production events
        // were missing from the admin counts, the quick filters and
        // [events_list status="upcoming"] for as long as the sweep had been
        // running "successfully".
        //
        // Reading the raw row also catches a value stored under its old name
        // (LEGACY_STATUS_ALIASES) that get_meta() has already normalized on
        // the way out, so the sweep repairs it instead of agreeing with it.
        if ( ! \metadata_exists( 'post', $post_id, $key )
            || (string) \get_post_meta( $post_id, $key, true ) !== $computed ) {
            \update_post_meta( $post_id, $key, $computed );
        }
    }

    /**
     * The current spelling of a stored status value.
     *
     * One read-side map for the whole module, so every consumer of a status
     * agrees on what a legacy row means while the batched back-fill catches
     * up (and on a site whose admin is never visited, for ever).
     *
     * @param string $status
     * @return string
     */
    private function normalize_status( $status ) {
        $status = (string) $status;
        return self::LEGACY_STATUS_ALIASES[ $status ] ?? $status;
    }

    /**
     * A meta_query clause matching events whose status is $status — counting
     * the ones that have never had the row written (MODEL-D18).
     *
     * Same NOT-EXISTS-or-equals shape as build_hide_clause(), and for the same
     * reason: a bare value comparison INNER-JOINs postmeta, so it can only ever
     * return posts that HAVE the row. For the DEFAULT status that is wrong —
     * get_meta() reports a rowless event as 'upcoming', so every reader that
     * goes through get_meta() calls it upcoming while every reader that goes
     * through a meta_query cannot see it at all. For any other status the exact
     * match is right: a rowless event is not cancelled.
     *
     * The IN arm carries the legacy spellings too, so an "Undated" count is
     * correct before the back-fill has rewritten the old 'draft' rows.
     *
     * @param string $status
     * @return array meta_query clause.
     */
    public function build_status_clause( $status ) {
        $status  = $this->normalize_status( \sanitize_text_field( (string) $status ) );
        $aliases = array_keys( self::LEGACY_STATUS_ALIASES, $status, true );
        $values  = array_merge( [ $status ], $aliases );

        $match = count( $values ) > 1
            ? [ 'key' => $this->meta_key( 'status' ), 'value' => $values, 'compare' => 'IN' ]
            : [ 'key' => $this->meta_key( 'status' ), 'value' => $status, 'compare' => '=' ];

        $defaults = $this->get_meta_defaults();
        if ( ( $defaults['status'] ?? '' ) !== $status ) {
            return $match;
        }

        return [
            'relation' => 'OR',
            [ 'key' => $this->meta_key( 'status' ), 'compare' => 'NOT EXISTS' ],
            $match,
        ];
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
            // 'undated', not 'draft' (MODEL-D19): 'draft' is WordPress's own
            // post_status, so the admin column printed "Draft" beside a post
            // whose post_status was "Published" and the card carried
            // `anchor-event-status-draft` for a live event. The state being
            // named here is "this event has no start date", which is
            // orthogonal to whether it is published.
            return 'undated';
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
        $timezone = $this->event_timezone_name( $meta );
        $start_time = $meta['all_day'] ? '00:00' : ( $meta['start_time'] ?: '00:00' );
        $end_date = $meta['end_date'] ?: $meta['start_date'];
        $end_time = ( $meta['all_day'] || ! $meta['end_time'] ) ? '23:59' : $meta['end_time'];

        $start = $this->to_timestamp( $meta['start_date'], $start_time, $timezone );
        $end = $this->to_timestamp( $end_date, $end_time, $timezone );

        if ( $end < $start ) {
            $end = $start;
        }

        return [ 'start' => $start, 'end' => $end ];
    }

    /**
     * A timezone string DateTimeZone will actually accept.
     *
     * Three shapes reach here. A named zone ("America/Chicago") is fine. An
     * empty one means "use the site's", which is wp_timezone_string() — NOT
     * get_option('timezone_string'), because that option is empty on any site
     * configured with a raw UTC offset instead of a named zone. And WordPress's
     * own manual-offset form ("UTC-6", which get_meta_defaults() mints and the
     * timezone dropdown offers) is REJECTED by DateTimeZone — it wants
     * "-06:00".
     *
     * Both of those last two used to end at the catch below, silently, so every
     * event on this site had a start_ts six hours from the date its admin
     * typed: {event_date} rendered blank or a day early, and the reminder
     * scheduler's start_ts window never matched.
     *
     * PUBLIC because Event_Schema is a separate class and must ask rather than
     * re-implement (audit RENDER-D2 / MODEL-D20): it kept its own
     * `get_option('timezone_string') ?: 'UTC'` copy, which is empty on exactly
     * the sites this method exists for, so the module computed every timestamp
     * at -06:00 while the JSON-LD rendered the same instants at +00:00.
     */
    public function normalize_timezone( $timezone ) {
        $timezone = \trim( (string) $timezone );

        if ( $timezone === '' ) {
            return \wp_timezone_string();   // named zone, or ±HH:MM from gmt_offset
        }
        // UTC-6, UTC+5.5 — the decimal form WordPress uses for manual offsets.
        if ( \preg_match( '/^UTC([+-])(\d+(?:\.\d+)?)$/i', $timezone, $m ) ) {
            $minutes = (int) \round( (float) $m[2] * 60 );
            return \sprintf( '%s%02d:%02d', $m[1], \intdiv( $minutes, 60 ), $minutes % 60 );
        }
        // UTC-06:30 / UTC-0630
        if ( \preg_match( '/^UTC([+-])(\d{1,2}):?(\d{2})$/i', $timezone, $m ) ) {
            return \sprintf( '%s%02d:%02d', $m[1], (int) $m[2], (int) $m[3] );
        }
        return $timezone;
    }

    /**
     * The timezone STRING an event's wall-clock times are read in, per the
     * site's timezone_mode setting. '' means "the site's own zone" and is
     * resolved by normalize_timezone().
     *
     * @param array $meta
     * @return string
     */
    private function event_timezone_name( array $meta ) {
        $settings = $this->get_settings();
        if ( $settings['timezone_mode'] === 'site' ) {
            return '';
        }
        return ! empty( $meta['timezone'] ) ? (string) $meta['timezone'] : '';
    }

    /**
     * The ONE answer to "what zone were this event's times typed in" (audit
     * RENDER-D2 / MODEL-D20). The save path (calculate_timestamps) and the
     * JSON-LD renderer (Event_Schema::resolve_timezone) both ask here, so the
     * instant a timestamp encodes and the offset the markup prints can no
     * longer come from two different derivations.
     *
     * @param array $meta
     * @return \DateTimeZone
     */
    public function event_timezone( array $meta ) {
        return $this->timezone_object( $this->event_timezone_name( $meta ) );
    }

    /**
     * A DateTimeZone from any stored shape, falling back to UTC rather than
     * throwing mid-render. An already-resolved zone passes straight through, so
     * a caller that holds one (Event_Schema, which needs the same zone to
     * render with) does not have to round-trip it back to a string.
     */
    private function timezone_object( $timezone ) {
        if ( $timezone instanceof \DateTimeZone ) {
            return $timezone;
        }
        try {
            return new \DateTimeZone( $this->normalize_timezone( $timezone ) );
        } catch ( \Exception $e ) {
            return new \DateTimeZone( 'UTC' );
        }
    }

    /**
     * A wall-clock date + time in a given zone as a Unix timestamp.
     *
     * The leading `!` in the format resets every field the format does not
     * name — seconds and microseconds — to zero instead of inheriting them
     * from the current clock (audit MODEL-D13). Without it the value a save
     * writes depends on the second it ran, and Occurrences' "an unchanged
     * desired set produces no meta churn" contract cannot hold.
     *
     * PUBLIC because Event_Schema built its own copy of this construction for
     * an Offer's `validFrom` — a second place where a wall-clock string becomes
     * an instant is a second place for the format, the zone and the seconds
     * rule to drift apart.
     *
     * @param string                $date     Y-m-d.
     * @param string                $time     H:i.
     * @param string|\DateTimeZone  $timezone A stored timezone string ('' = the
     *                                        site's), or a resolved zone.
     * @return int Unix timestamp, or 0 when $date is empty or unparseable.
     */
    public function to_timestamp( $date, $time, $timezone ) {
        if ( ! $date ) {
            return 0;
        }
        $tz = $this->timezone_object( $timezone );
        $dt = \DateTime::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, $tz );
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
        // "Now" is a wall-clock question, so it is asked in the site's zone
        // (audit RENDER-D30). date('Y-m') runs in PHP's default zone — UTC
        // under WordPress — so at 19:00 local on the last day of a month the
        // calendar opened on the NEXT month, and diff_months() shifted its
        // prev/next bounds with it.
        $current_month = \wp_date( 'Y-m' );
        if ( ! preg_match( '/^\\d{4}-\\d{2}$/', $requested_month ) ) {
            $requested_month = $current_month;
        }

        $month = $requested_month;
        $month_start = $month . '-01';
        $timezone = '';                          // as above — normalize_timezone() resolves it
        // BOTH ends of the window in the site's zone (audit MODEL-D12). The end
        // used to be a raw strtotime() in UTC, so on a UTC-6 site September
        // stopped at 19:00 local on the 30th and an event later that evening
        // never appeared in its own month. date() below is pure string
        // arithmetic on a chosen YYYY-MM-01, which no zone can move.
        $start = $this->to_timestamp( $month_start, '00:00', $timezone );
        $end = $this->to_timestamp( date( 'Y-m-d', strtotime( '+1 month', strtotime( $month_start ) ) ), '00:00', $timezone );

        $diff_to_now = $this->diff_months( $month, $current_month );
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

    /**
     * An event's status as a reader must see it: the author's pinned value in
     * manual mode, the value the dates imply in auto mode.
     *
     * The ONE accessor for that fact (audit MODEL-D43 / RENDER-D11). The raw
     * `_anchor_event_status` row is only refreshed on save, on
     * transition_post_status and by the daily sweep, so anything reading it
     * directly disagrees with this for as long as a day: the front-end
     * manager listed an event that ended yesterday as "Upcoming", and the
     * JSON-LD derived its eventStatus from a different source than the visible
     * "Status: Past" beside it. Public because Event_Schema is a separate
     * class and must ask rather than re-implement.
     *
     * @param int        $post_id
     * @param array|null $meta Already-loaded meta for $post_id, when the
     *                         caller holds it.
     * @return string
     */
    public function get_event_status( $post_id, $meta = null ) {
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

    /**
     * "Hide past events" without treating a missing `end_ts` as PAST
     * (audit RENDER-D31).
     *
     * The old shape was a bare `end_ts >= time()` comparison, which INNER-JOINs
     * postmeta: an event that never had an `end_ts` row written was treated as
     * over and silently dropped. The NOT EXISTS branch is what stops that.
     *
     * Be precise about what it does and does not rescue, because the clause is
     * only one of two joins a listing applies:
     *   - It rescues an event that HAS a `start_ts` row but no `end_ts` row —
     *     a single-day event saved before end_ts existed, or one whose end
     *     meta was lost. That event is undated at the far end, not finished.
     *   - It does NOT rescue an event with NO `start_ts` at all. Every listing
     *     query also orders by `meta_key => start_ts` / `meta_value_num`,
     *     which INNER-JOINs postmeta on start_ts, so a start_ts-less event is
     *     dropped by the ORDERING join no matter what this clause says. That
     *     is the gap backfill_timestamps() closes: it mints `start_ts`/`end_ts`
     *     for every legacy event that still has a `start_date`, which is what
     *     actually returns fully-undated legacy events to the listings.
     */
    public function build_visibility_clause() {
        return [
            'relation' => 'OR',
            [
                'key' => $this->meta_key( 'end_ts' ),
                'compare' => 'NOT EXISTS',
            ],
            [
                'key' => $this->meta_key( 'end_ts' ),
                'value' => time(),
                'compare' => '>=',
                'type' => 'NUMERIC',
            ],
        ];
    }

    /**
     * The single listing-exclusion clause: everything a list of events must
     * leave out, regardless of dates (audit RENDER-D15 / MODEL-D3).
     *
     * Two facts hide an event from a PUBLIC listing:
     *   - `hide_from_archive` — the editor's explicit "keep this off lists".
     *   - `occurrence_closed` — a soft-closed group child (Occurrences::
     *     soft_close() preserves the post + its roster but the date is no
     *     longer bookable).
     *
     * These used to be two builders, and the closed half had exactly ONE call
     * site (filter_archive_query) against the hide half's five, so a cancelled
     * date still rendered as a bookable card in [events_list], the calendar,
     * both manager shortcodes and the series archive — each linking to a page
     * whose only registration UI is "This date is no longer available."
     * Folding them into one clause makes every existing call site correct and
     * leaves nothing to forget at the next one.
     *
     * Only the `hide_from_archive` half is an exclusion for STAFF, though. A
     * soft-closed date keeps its roster — that is the entire point of a soft
     * close — and somebody has to email or refund those attendees, so the two
     * capability-gated surfaces ([event_registrants_list], which requires
     * edit_others_posts, and the front-end Events Manager console) pass
     * $public = false and keep cancelled dates in the list. Folding the closed
     * half in unconditionally would have made a cancelled date's roster
     * unreachable from the surfaces built to manage it.
     *
     * Each half keeps the NOT-EXISTS-or-not-truthy shape: a post that has
     * never had the meta row written (every legacy event, and every group
     * child before its first soft-close) is unaffected rather than
     * INNER-JOINed out.
     *
     * @param bool $public Whether this is a public-facing listing (default
     *                     true). False = a staff surface: hidden events are
     *                     still excluded, soft-closed dates are not.
     * @return array meta_query clause.
     */
    public function build_hide_clause( $public = true ) {
        $hidden = [
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

        if ( ! $public ) {
            return $hidden;
        }

        return [
            'relation' => 'AND',
            $hidden,
            [
                'relation' => 'OR',
                [
                    'key' => $this->meta_key( 'occurrence_closed' ),
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => $this->meta_key( 'occurrence_closed' ),
                    'value' => '1',
                    'compare' => '!=',
                ],
            ],
        ];
    }

    private function build_range_clause( $start, $end ) {
        $start = $this->sanitize_date( $start );
        $end = $this->sanitize_date( $end );
        if ( ! $start && ! $end ) {
            return null;
        }
        // Both bounds are wall-clock dates an author typed, so they are read in
        // the site's zone like every other date in this module (audit
        // MODEL-D11). Raw strtotime() runs in UTC: on a UTC-6 site
        // start_date="2026-09-15" opened at 2026-09-14 18:00 local and swept in
        // the previous evening, while end_date's 23:59 closed at 17:59 local
        // and dropped the last evening.
        $start_ts = $start ? $this->to_timestamp( $start, '00:00', '' ) : 0;
        // A FIXED far-future end, not strtotime('+5 years'): the open bound is
        // part of the transient cache key, and a value that moves every second
        // re-keyed it on every request. (build_visibility_clause() still does
        // the same with time() on the show_past='no' default — NEW-D5.)
        $end_ts = $end ? $this->to_timestamp( $end, '23:59', '' ) : self::RANGE_OPEN_END_TS;
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

    /**
     * "Can a seat on this event (optionally on this tier) be sold right now?"
     * — the ONE predicate every reader asks (WOO-D2/D3/D37, RENDER-D3/D4/D5/
     * D7/D32, MODEL-D42). The storefront row, `is_purchasable`, the AJAX
     * add-to-cart endpoint, the choose-a-date picker, the series archive and
     * the JSON-LD Offer builders all route through here, so one event can no
     * longer read "Join waitlist" on the page, "sold out" at the cart and
     * "InStock" in the markup on the same request.
     *
     * The branch order says WHAT THE EVENT IS before it says whether the
     * button is on, and render_registration_form() mirrors it:
     *   parent    — a group container is never itself a seat, but only while
     *                it still has a date somebody can take (parent_bookability()),
     *   closed    — a soft-closed occurrence, still reachable by direct URL,
     *   cancelled — the author's own word, via get_event_status(),
     *   then the seat-layer capacity authority (open|waitlist|full|closed),
     *   which owns the sold_out flag, the registration window, the past-event
     *   check, the event total and the per-tier quota,
     *   disabled  — "Enable registration" is unticked, and nothing above has
     *   already answered.
     *
     * `disabled` used to come FIRST (audit NEW-D2), so a hand-flagged sold-out
     * or finished event with registration off — the normal shape, because an
     * admin who marks a course sold out also unticks the box — reported
     * 'disabled' and nothing else. Production child 7528 published a JSON-LD
     * Offer with a price and no availability while its own page said "Sold
     * out"; choose_date_availability_hint() had to re-ask the seat layer
     * locally to print anything true; and the DEKA theme grew a second
     * capacity accessor beside this one. The seats going outranks the button
     * being gone, so that judgement now lives here, once.
     *
     * 'waitlist' is the one seat-layer state the switch DOES outrank, because
     * is_bookable() accepts it and the cart mints a real waitlist seat from
     * it: on a disabled event it degrades to 'full' — no seat can be taken,
     * and "sold out" is the honest half of that answer.
     *
     * @param int        $event_id
     * @param array|null $tier     Normalized ticket-tier row, when the question
     *                             is about one tier rather than the event.
     * @return string One of open|waitlist|full|closed|parent|disabled.
     */
    public function bookability( $event_id, $tier = null ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) {
            return 'closed';
        }
        if ( $this->occurrences->is_group_parent( $event_id ) ) {
            return $this->parent_bookability( $event_id );
        }
        if ( $this->is_closed_group_child( $event_id ) ) {
            return 'closed';
        }

        $meta = $this->get_meta( $event_id );

        // A cancelled course sells nothing, whatever its dates or its switch
        // say (THEME-D25). Read through the accessor, not the raw row: auto
        // mode owns that row and never computes 'cancelled', so only a
        // hand-pinned (or soft-closed) event reaches this.
        if ( $this->get_event_status( $event_id, $meta ) === 'cancelled' ) {
            return 'closed';
        }

        $seats   = $this->get_registration_status( $event_id, $meta, 1, $tier );
        $enabled = ! empty( $meta['registration_enabled'] );

        if ( $seats === 'closed' || $seats === 'full' ) {
            return $seats;
        }
        if ( $seats === Registrations::STATUS_WAITLIST ) {
            return $enabled ? $seats : 'full';
        }
        if ( ! $enabled ) {
            return 'disabled';
        }
        return $seats;
    }

    /**
     * A group container's own bookability (audit MODEL-D4 / NEW-D1).
     *
     * 'parent' used to be unconditional, which said only "this is a
     * container" and never "there is nothing left in it": a parent whose
     * every date was sold out, and one whose every date had passed, both read
     * exactly like one with November wide open. Every reader that wanted the
     * difference had to compute it — the DEKA theme derived "sold out" and
     * "closed" for containers itself, from its own copy of the bookable-child
     * loop, and the picker/archive simply did not show it.
     *
     * The vocabulary is unchanged, which is the point: 'parent' still means
     * "choose a date" (WooCommerce::bookability_message() says exactly that,
     * and Event_Schema::omits_offer() still withholds the container's Offer),
     * and a container with nothing left borrows the words the rest of the
     * module already uses for one date, in the same order bookability()
     * itself resolves them:
     *   'full'     — a date is sold out (the seats going outranks the button
     *                being gone, NEW-D2),
     *   'disabled' — registration is simply switched off on every date,
     *   'closed'   — finished, cancelled, outside its window, or no dates.
     *
     * The 'disabled' rung is not cosmetic. Registration off is the DEFAULT
     * state of an event and the PERMANENT state of every display-only site,
     * and Event_Schema::omits_offer() deliberately keeps publishing an Offer
     * for it while withholding one for 'closed' — collapsing it into 'closed'
     * would have stripped the price out of the markup of every offering on
     * every site that has never switched registration on. A past date can
     * never report 'full' or 'disabled' (capacity_decision() answers 'closed'
     * for a finished event before it looks at anything else), so a container
     * whose dates have all run still reads 'closed'.
     *
     * @param int $parent_id
     * @return string parent|full|disabled|closed
     */
    private function parent_bookability( $parent_id ) {
        $parent_id = (int) $parent_id;
        if ( ! empty( $this->occurrences->bookable_children( $parent_id ) ) ) {
            return 'parent';
        }

        $states = [];
        foreach ( $this->occurrences->children( $parent_id, false ) as $child_id ) {
            $states[ $this->bookability( (int) $child_id ) ] = true;
        }

        if ( isset( $states['full'] ) ) {
            return 'full';
        }
        if ( isset( $states['disabled'] ) ) {
            return 'disabled';
        }
        return 'closed';
    }

    /**
     * The occurrence a group parent currently advertises — its soonest
     * BOOKABLE date, falling back to its soonest date still to come, and 0
     * when it is not a container or has nothing ahead of it (MODEL-D4 /
     * NEW-D1).
     *
     * The one answer to "which date does this container show", so the card's
     * date, its city, its sort key and its CTA cannot each pick a different
     * one. Bookable first because that is the date a visitor can act on
     * (production parent 7258 advertised a sold-out September while its own
     * picker offered November); upcoming second so a fully-booked offering
     * still shows a date rather than nothing.
     *
     * 0 is deliberate for "every date has passed": the parent's own
     * start_date already spans its live children (Occurrences::
     * sync_parent_span()), so a caller falling back to the parent id gets
     * that span rather than a wrong "next" date.
     *
     * @param int $parent_id
     * @return int Occurrence post id, or 0.
     */
    public function next_occurrence( $parent_id ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 || ! $this->occurrences->is_group_parent( $parent_id ) ) {
            return 0;
        }

        $bookable = $this->occurrences->bookable_children( $parent_id );
        if ( ! empty( $bookable ) ) {
            return (int) $bookable[0];
        }

        $upcoming = $this->occurrences->upcoming_children( $parent_id );
        return empty( $upcoming ) ? 0 : (int) $upcoming[0];
    }

    /**
     * Whether a bookability() state can still take a seat. 'waitlist' counts:
     * a waitlist request is a real, accepted transaction (Registrations::
     * claim_seats() resolves it at creation), which is exactly why the cart
     * and the storefront must agree that it is allowed.
     *
     * @param string $bookability
     * @return bool
     */
    public function is_bookable( $bookability ) {
        return \in_array( $bookability, [ 'open', Registrations::STATUS_WAITLIST ], true );
    }

    /**
     * The extra questions this event asks each attendee, on top of the built-in
     * name / email / phone.
     *
     * Answers are stored on the seat in _anchor_event_reg_fields, which the roster
     * and the CSV export already read — Registrations::get_export_rows() collects
     * every key it finds and merges them into the header — so a question added
     * here becomes a roster column and an export column with no further wiring.
     *
     * @param int $event_id
     * @return array<int,array{key:string,label:string,type:string,options:array,required:bool}>
     */
    public function get_registration_questions( $event_id ) {
        $raw = \get_post_meta( (int) $event_id, self::QUESTIONS_META, true );
        return $this->normalize_registration_questions( \is_array( $raw ) ? $raw : [] );
    }

    /**
     * The registrant breakdown behind every "N registrants" headline (REG-D22).
     *
     * `count( get_registrations( $id, 0 ) )` counted RECORDS OF EVERY STATUS —
     * cancelled, refunded and failed seats included — so an event with three
     * live registrations and seven cancellations announced "10 registrants
     * (3 total attendees)": two numbers computed on different axes, with the
     * wrong one in the headline. The live count and the cancelled count are now
     * two separate, named facts drawn from the SAME source the roster header
     * uses (get_event_summary()), so the two screens cannot disagree.
     *
     * `active` is records, not weighted seats — it answers "how many people
     * signed up" — while `attendees` stays the weighted confirmed figure
     * (registrant + guests) the parenthetical has always meant.
     *
     * @param int $event_id
     * @return array{active:int,confirmed:int,pending:int,waitlist:int,cancelled:int,attendees:int}
     */
    public function registrant_counts( $event_id ) {
        $summary = $this->registrations->get_event_summary( (int) $event_id );
        $per     = isset( $summary['per_status'] ) && \is_array( $summary['per_status'] ) ? $summary['per_status'] : [];
        $records = function ( $status ) use ( $per ) {
            return (int) ( $per[ $status ]['records'] ?? 0 );
        };

        $confirmed = $records( Registrations::STATUS_CONFIRMED );
        $pending   = $records( Registrations::STATUS_PENDING );
        $waitlist  = $records( Registrations::STATUS_WAITLIST );
        $cancelled = $records( Registrations::STATUS_CANCELLED )
            + $records( Registrations::STATUS_REFUNDED )
            + $records( Registrations::STATUS_FAILED );

        return [
            'active'    => $confirmed + $pending + $waitlist,
            'confirmed' => $confirmed,
            'pending'   => $pending,
            'waitlist'  => $waitlist,
            'cancelled' => $cancelled,
            'attendees' => (int) ( $summary['confirmed'] ?? 0 ),
        ];
    }

    /**
     * The headline + breakdown markup for one event's registrant counts. One
     * implementation for both admin list surfaces ([event_registrants_list] and
     * the [event_manager] item), which used to carry identical copies of the
     * wrong sum (REG-D22).
     *
     * @param int $event_id
     * @return string
     */
    private function render_registrant_counts( $event_id ) {
        $c   = $this->registrant_counts( $event_id );
        $out = ' <span class="anchor-event-admin-count">' . esc_html( sprintf(
            \_n( '%d registrant', '%d registrants', $c['active'], 'anchor-schema' ),
            $c['active']
        ) );
        if ( $c['attendees'] !== $c['active'] ) {
            $out .= ' <span class="anchor-event-admin-attendees">(' . esc_html( sprintf( __( '%d total attendees', 'anchor-schema' ), $c['attendees'] ) ) . ')</span>';
        }
        if ( $c['cancelled'] > 0 ) {
            $out .= ' <span class="anchor-event-admin-cancelled">' . esc_html( sprintf(
                /* translators: %d: number of cancelled/refunded/failed seats, which are NOT in the headline count. */
                __( '+%d cancelled', 'anchor-schema' ),
                $c['cancelled']
            ) ) . '</span>';
        }
        $out .= '</span>';
        return $out;
    }

    /**
     * Sanitize one posted answer set against an event's OWN question model —
     * the single validator every write path uses (REG-D9/D39).
     *
     * There used to be three copies of this loop: the free handler, the
     * WooCommerce checkout validator and the WooCommerce item writer. Copies
     * that have to AGREE to be correct drift, and these had: two of them ran
     * every answer through sanitize_text_field(), which strips newlines, so a
     * textarea answer ("no nuts, and I use a wheelchair") arrived on the roster
     * as one run-on line while the third path kept it intact. One
     * implementation, three call sites.
     *
     * Answers come back keyed by the question's stable key — never its label
     * (REG-D10) — with select values constrained to the offered options and a
     * checkbox normalized to 'yes' | ''. `missing` lists the required questions
     * that were left blank, so each caller can phrase its own refusal.
     *
     * @param int        $event_id
     * @param mixed      $posted    Raw posted answers, keyed by question key.
     * @param array|null $questions Pre-fetched question set (loop hoist).
     * @return array{answers:array<string,string>,missing:array<int,array>}
     */
    public function sanitize_registration_answers( $event_id, $posted, $questions = null ) {
        $questions = \is_array( $questions ) ? $questions : $this->get_registration_questions( (int) $event_id );
        $posted    = \is_array( $posted ) ? $posted : [];

        $answers = [];
        $missing = [];
        foreach ( $questions as $q ) {
            $raw    = $posted[ $q['key'] ] ?? '';
            $answer = '';
            if ( \is_scalar( $raw ) ) {
                // A textarea is the one type whose newlines are part of the
                // answer; sanitize_text_field() would flatten them.
                $answer = ( $q['type'] === 'textarea' )
                    ? \sanitize_textarea_field( (string) $raw )
                    : \sanitize_text_field( (string) $raw );
            }
            if ( $q['type'] === 'checkbox' ) {
                $answer = ( $answer !== '' && $answer !== '0' ) ? 'yes' : '';
            } elseif ( $q['type'] === 'select' && $answer !== '' && ! \in_array( $answer, $q['options'], true ) ) {
                $answer = ''; // not one of the offered choices
            }
            if ( ! empty( $q['required'] ) && \trim( $answer ) === '' ) {
                $missing[] = $q;
            }
            $answers[ $q['key'] ] = $answer;
        }

        return [
            'answers' => $answers,
            'missing' => $missing,
        ];
    }

    /**
     * The form control for ONE attendee question — the single renderer behind
     * the free registration form, the WooCommerce checkout attendee block and
     * the roster's manual add form (REG-D39).
     *
     * Only the control is rendered, never the wrapper or the label: the three
     * surfaces put their fields in a `.anchor-event-field` div, a Woo
     * `.form-row` paragraph and a `.form-table` row respectively, and that is
     * their business. What must not vary is which types exist, what a select
     * offers, and what a checkbox posts — those are the question model, and
     * they now have one implementation.
     *
     * @param array $q    Normalized question row.
     * @param array $args {
     *     @type string $name           Input name attribute (required).
     *     @type string $id             Input id, omitted when ''.
     *     @type string $value          Current value (redisplay).
     *     @type string $class          class attribute, omitted when ''.
     *     @type bool   $required       Defaults to the question's own flag.
     *     @type string $checkbox_label Text beside a checkbox; when non-empty the
     *                                  input is wrapped in a <label>.
     *     @type string $checkbox_class class for that wrapping <label>.
     * }
     * @return string
     */
    public function render_registration_question_control( array $q, array $args = [] ) {
        $args = \array_merge( [
            'name'           => '',
            'id'             => '',
            'value'          => '',
            'class'          => '',
            'required'       => ! empty( $q['required'] ),
            'checkbox_label' => '',
            'checkbox_class' => '',
        ], $args );

        $id    = $args['id'] !== '' ? ' id="' . \esc_attr( $args['id'] ) . '"' : '';
        $class = $args['class'] !== '' ? ' class="' . \esc_attr( $args['class'] ) . '"' : '';
        $name  = ' name="' . \esc_attr( $args['name'] ) . '"';
        $req   = $args['required'] ? ' required' : '';
        $value = (string) $args['value'];
        $type  = isset( $q['type'] ) ? (string) $q['type'] : 'text';

        if ( $type === 'textarea' ) {
            return '<textarea' . $id . $class . $name . ' rows="3"' . $req . '>' . \esc_textarea( $value ) . '</textarea>';
        }

        if ( $type === 'select' ) {
            $out = '<select' . $id . $class . $name . $req . '>';
            $out .= '<option value="">' . \esc_html__( '— Select —', 'anchor-schema' ) . '</option>';
            foreach ( (array) ( $q['options'] ?? [] ) as $opt ) {
                $out .= '<option value="' . \esc_attr( $opt ) . '"' . \selected( $value, $opt, false ) . '>' . \esc_html( $opt ) . '</option>';
            }
            return $out . '</select>';
        }

        if ( $type === 'checkbox' ) {
            $input = '<input type="checkbox"' . $id . $class . $name . ' value="yes"' . \checked( $value, 'yes', false ) . $req . ' />';
            if ( $args['checkbox_label'] === '' ) {
                return $input;
            }
            $wrap = $args['checkbox_class'] !== '' ? ' class="' . \esc_attr( $args['checkbox_class'] ) . '"' : '';
            return '<label' . $wrap . '>' . $input . ' ' . \esc_html( $args['checkbox_label'] ) . '</label>';
        }

        return '<input type="text"' . $id . $class . $name . ' value="' . \esc_attr( $value ) . '"' . $req . ' />';
    }

    /**
     * Map a seat's stored answers onto the event's CURRENT question set
     * (REG-D10 / REG-D11).
     *
     * Answers are keyed by the question's stable key on every write path. Seats
     * booked before that was true — every WooCommerce seat up to it — hold
     * LABEL-keyed answers, so a stored key that matches a current question's
     * label is migrated onto that question's key HERE, on read: no data
     * rewrite, and a rename can never orphan an answer because the label is
     * only ever a display value.
     *
     * An answer whose question no longer exists keeps the key it was stored
     * under, so it still reaches the roster and the CSV instead of vanishing.
     *
     * @param int        $event_id
     * @param mixed      $stored    Raw _anchor_event_reg_fields value.
     * @param array|null $questions Pre-fetched question set (loop hoist).
     * @return array<string,mixed> Answers keyed by question key.
     */
    public function resolve_registration_answers( $event_id, $stored, $questions = null ) {
        if ( ! \is_array( $stored ) || empty( $stored ) ) {
            return [];
        }
        $questions = \is_array( $questions ) ? $questions : $this->get_registration_questions( $event_id );
        if ( empty( $questions ) ) {
            return $stored;
        }

        $keys     = [];
        $by_label = [];
        foreach ( $questions as $q ) {
            $keys[ $q['key'] ] = true;
            $by_label[ $this->registration_answer_index( $q['label'] ) ] = $q['key'];
        }

        $out = [];
        foreach ( $stored as $stored_key => $value ) {
            $key = (string) $stored_key;
            if ( ! isset( $keys[ $key ] ) ) {
                $index = $this->registration_answer_index( $key );
                // Only migrate when the question does not ALSO have an id-keyed
                // answer on this seat — that one is the current spelling and wins.
                if ( isset( $by_label[ $index ] ) && ! \array_key_exists( $by_label[ $index ], $stored ) ) {
                    $key = $by_label[ $index ];
                }
            }
            $out[ $key ] = $value;
        }
        return $out;
    }

    /**
     * Display label for a stored answer key: the current question's label, or
     * the stored key itself once that question is gone (REG-D10). The one place
     * the roster table, the roster list table, the CSV header and the privacy
     * export ask, so a heading cannot mean two things in two readers.
     *
     * @param int        $event_id
     * @param string     $key
     * @param array|null $questions Pre-fetched question set (loop hoist).
     * @return string
     */
    public function registration_answer_label( $event_id, $key, $questions = null ) {
        $questions = \is_array( $questions ) ? $questions : $this->get_registration_questions( $event_id );
        foreach ( $questions as $q ) {
            if ( $q['key'] === (string) $key ) {
                return $q['label'];
            }
        }
        return (string) $key;
    }

    /** Comparison form for matching a stored key against a question label. */
    private function registration_answer_index( $value ) {
        return \strtolower( \trim( (string) $value ) );
    }

    /**
     * Normalize question rows. Drops rows with no label, derives a stable key from
     * the label when one is not supplied, and guarantees key uniqueness — the key
     * identifies the answer on every seat, so a duplicate would merge two
     * questions into one column.
     *
     * @param array $rows
     * @return array
     */
    public function normalize_registration_questions( $rows ) {
        $clean = [];
        $seen  = [];
        foreach ( (array) $rows as $row ) {
            if ( ! \is_array( $row ) ) {
                continue;
            }
            $label = \sanitize_text_field( (string) ( $row['label'] ?? '' ) );
            if ( $label === '' ) {
                continue;
            }
            $key = \sanitize_key( (string) ( $row['key'] ?? '' ) );
            if ( $key === '' ) {
                $key = \sanitize_key( \substr( \sanitize_title( $label ), 0, 32 ) );
            }
            if ( $key === '' ) {
                $key = 'question';
            }
            if ( isset( $seen[ $key ] ) ) {
                $n = 2;
                while ( isset( $seen[ $key . '_' . $n ] ) ) {
                    $n++;
                }
                $key = $key . '_' . $n;
            }
            $seen[ $key ] = true;

            $type = \sanitize_key( (string) ( $row['type'] ?? 'text' ) );
            if ( ! \in_array( $type, [ 'text', 'textarea', 'select', 'checkbox' ], true ) ) {
                $type = 'text';
            }

            $options = [];
            if ( $type === 'select' ) {
                $raw_options = $row['options'] ?? '';
                if ( ! \is_array( $raw_options ) ) {
                    $raw_options = \preg_split( '/\r\n|\r|\n/', (string) $raw_options );
                }
                foreach ( (array) $raw_options as $opt ) {
                    $opt = \sanitize_text_field( (string) $opt );
                    if ( $opt !== '' ) {
                        $options[] = $opt;
                    }
                }
                if ( empty( $options ) ) {
                    $type = 'text'; // a select with nothing to select is a text box
                }
            }

            $clean[] = [
                'key'      => $key,
                'label'    => $label,
                'type'     => $type,
                'options'  => $options,
                'required' => ! empty( $row['required'] ),
            ];
        }
        return $clean;
    }

    /**
     * Persist the posted question repeater for an event.
     *
     * Task 28 — "the repeater was on screen and is now empty" and "this form
     * never rendered the repeater" post the SAME thing: no
     * anchor_event_questions rows at all. Only the first may clear the meta.
     * The console therefore ships a hidden anchor_event_questions_present
     * marker (mirroring the email builder's three "…_present" markers), and a
     * save carrying neither the marker nor any rows — a plain wp-admin metabox
     * save, whose Emails/details metaboxes do not render this repeater — is a
     * no-op here rather than a silent delete of every question the console
     * authored. Rows without the marker are still honoured, so an older cached
     * form (or a direct call) keeps working.
     */
    private function save_registration_questions( $post_id, array $src ) {
        if ( ! isset( $src['anchor_event_questions'] ) && empty( $src['anchor_event_questions_present'] ) ) {
            return;
        }
        $raw = isset( $src['anchor_event_questions'] ) && \is_array( $src['anchor_event_questions'] )
            ? \wp_unslash( $src['anchor_event_questions'] )
            : [];
        $clean = $this->normalize_registration_questions( $raw );
        if ( empty( $clean ) ) {
            \delete_post_meta( $post_id, self::QUESTIONS_META );
            return;
        }
        // wp_slash(): the rows were unslashed on the way in, and
        // update_post_meta() unslashes again — wp_slash() maps over the array,
        // so a label reading `Room C:\Alpha` survives. See
        // persist_event_authoring()'s docblock.
        \update_post_meta( $post_id, self::QUESTIONS_META, \wp_slash( $clean ) );
    }

    /**
     * One row of the question repeater. Mirrors event_label_row_html(): the
     * template copy uses __INDEX__, which manager.js rewrites on add.
     *
     * @param int        $index
     * @param array|null $row
     * @param bool       $template
     * @return string
     */
    private function event_question_row_html( $index, $row = null, $template = false ) {
        $idx  = $template ? '__INDEX__' : (string) $index;
        $base = 'anchor_event_questions[' . $idx . ']';

        $label    = $row['label'] ?? '';
        $key      = $row['key'] ?? '';
        $type     = $row['type'] ?? 'text';
        $options  = isset( $row['options'] ) && \is_array( $row['options'] ) ? \implode( "\n", $row['options'] ) : '';
        $required = ! empty( $row['required'] );

        \ob_start();
        ?>
        <tr class="anchor-event-question-row">
            <td>
                <input type="text" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php echo esc_attr__( 'e.g. Company or organization', 'anchor-schema' ); ?>" />
                <input type="hidden" name="<?php echo esc_attr( $base . '[key]' ); ?>" value="<?php echo esc_attr( $key ); ?>" />
            </td>
            <td>
                <select name="<?php echo esc_attr( $base . '[type]' ); ?>" class="anchor-question-type">
                    <option value="text" <?php selected( $type, 'text' ); ?>><?php echo esc_html__( 'Short text', 'anchor-schema' ); ?></option>
                    <option value="textarea" <?php selected( $type, 'textarea' ); ?>><?php echo esc_html__( 'Long text', 'anchor-schema' ); ?></option>
                    <option value="select" <?php selected( $type, 'select' ); ?>><?php echo esc_html__( 'Choose one', 'anchor-schema' ); ?></option>
                    <option value="checkbox" <?php selected( $type, 'checkbox' ); ?>><?php echo esc_html__( 'Yes / no', 'anchor-schema' ); ?></option>
                </select>
            </td>
            <td>
                <textarea name="<?php echo esc_attr( $base . '[options]' ); ?>" rows="2" placeholder="<?php echo esc_attr__( 'One choice per line', 'anchor-schema' ); ?>"><?php echo esc_textarea( $options ); ?></textarea>
            </td>
            <td>
                <label><input type="checkbox" name="<?php echo esc_attr( $base . '[required]' ); ?>" value="1" <?php checked( $required ); ?> /> <?php echo esc_html__( 'Required', 'anchor-schema' ); ?></label>
            </td>
            <td>
                <button type="button" class="button-link-delete anchor-event-question-remove" aria-label="<?php echo esc_attr__( 'Remove question', 'anchor-schema' ); ?>">&times;</button>
            </td>
        </tr>
        <?php
        return (string) \ob_get_clean();
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

    /**
     * The opening lines a confirmation falls back to when the event saved no
     * override: the site setting, or the sentence this plugin has always
     * shipped. One definition, because two copies of a default that have to
     * agree are two copies that will eventually disagree.
     *
     * @param array $settings Module settings.
     * @return string
     */
    private function default_confirmation_intro( array $settings ) {
        return ( isset( $settings['confirmation_message'] ) && $settings['confirmation_message'] !== '' )
            ? (string) $settings['confirmation_message']
            : __( "Thanks for signing up. We're excited to see you at the event!", 'anchor-schema' );
    }

    /**
     * The free/manual registration emails: the site's own "new registration"
     * notice, and the attendee's confirmation.
     *
     * The two answer to different switches. `notify_admin` governs the internal
     * notice; the per-event "confirmation" switch and `notify_user` govern the
     * attendee email. They used to share one guard at the top of this method, so
     * an organizer who unticked "Confirmation" because they were emailing
     * attendees by hand also stopped their own site telling them anyone had
     * registered (REG-D8).
     *
     * The attendee's subject and opening lines resolve through get_email_field()
     * so the per-event overrides the Emails builder writes reach this path too —
     * they previously reached only the WooCommerce sender, which meant "Preview
     * with real data" showed copy a free registration never sent (REG-D1/D45).
     */
    /**
     * @return Outcome The ATTENDEE email: sent | skipped (notifications_off,
     *                 disabled, no_address) | failed (wp_mail).
     */
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
                Events_Log::error( 'email_send_returned_false', [
                    'event'   => (int) $event_id,
                    'to'      => $admin_email,
                    'subject' => $subject,
                ] );
            }
        }

        // The ATTENDEE half is what the return value describes: the caller needs
        // to know whether it may promise "check your email" (REG-D24). The
        // organizer copy above is a different recipient with a different switch
        // and is deliberately not folded into this answer.
        if ( empty( $settings['notify_user'] ) ) {
            return Outcome::skipped( 'notifications_off' );
        }
        if ( ! $this->is_email_enabled( $event_id, 'confirmation' ) ) {
            return Outcome::skipped( 'disabled' );
        }
        if ( \sanitize_email( (string) $email ) === '' ) {
            return Outcome::skipped( 'no_address' );
        }
        $tokens = $this->email_tokens( [
            'event_id' => $event_id,
            'seat'     => [ 'name' => $name, 'email' => $email, 'status' => $status ],
        ] );
        // Per-event override -> site setting -> the default this path has
        // always used. Same three-step resolution, and the same fallbacks,
        // as every other sender: nothing changes for an event with no
        // override saved.
        $subject = $this->expand_email_tokens(
            $this->get_email_field(
                $event_id,
                'confirmation',
                'subject',
                sprintf( __( 'You are registered for %s', 'anchor-schema' ), $event_title )
            ),
            $tokens
        );
        $intro = $this->expand_email_tokens(
            $this->get_email_field(
                $event_id,
                'confirmation',
                'intro',
                $this->default_confirmation_intro( $settings )
            ),
            $tokens
        );
        // The $ctx form of the builder, not the positional shim: the shim
        // resolves the intro from the settings alone, which is the bug.
        $html = $this->build_registration_email_html( [
            'event_id'      => (int) $event_id,
            'name'          => (string) $name,
            'status'        => (string) $status,
            'intro_message' => $intro,
            'guests'        => $guests,
            'detail_rows'   => [],
            'seat_list'     => [],
            'cta_label'     => __( 'View event details', 'anchor-schema' ),
            'cta_url'       => $event_link,
            'type'          => 'confirmation',
        ] );
        return Outcome::from_bool( $this->send_html_email( $email, $subject, $html, [], $event_id ), 'wp_mail' );
    }

    // -------------------------------------------------------------------------
    // v1.1: Attendee cancellation / refund email (spec §7)
    // -------------------------------------------------------------------------

    /** Enqueue (do not send) on a live→cancelled/refunded transition (spec §7.2). */
    public function on_seat_status_changed( $seat_id, $from, $to, $actor ) {
        // A waitlist promotion is the one non-terminal transition an attendee
        // has to hear about (audit REG-D38). It is the module's ONLY promotion
        // — there is no automatic one — and it used to send nothing at all, so
        // somebody who had been told "you are on the waitlist" got a seat and
        // was never told. Reuses the confirmation sender, which owns both
        // switches (notify_user and the per-event confirmation toggle), rather
        // than growing a fifth template.
        if ( $from === Registrations::STATUS_WAITLIST && $to === Registrations::STATUS_CONFIRMED ) {
            $seat = $this->registrations->get_seat( (int) $seat_id );
            if ( \is_array( $seat ) ) {
                $this->send_registration_emails(
                    (int) \get_post_meta( (int) $seat_id, '_anchor_event_id', true ),
                    (string) ( $seat['name'] ?? '' ),
                    (string) ( $seat['email'] ?? '' ),
                    Registrations::STATUS_CONFIRMED,
                    (int) ( $seat['guests'] ?? 0 )
                );
            }
            return;
        }

        $live = [ Registrations::STATUS_CONFIRMED, Registrations::STATUS_WAITLIST ];
        if ( ! \in_array( $to, Registrations::TERMINAL_STATUSES, true ) || ! \in_array( $from, $live, true ) ) {
            return;
        }
        $settings = $this->get_settings();
        if ( empty( $settings['notify_cancellation'] ) ) {
            return;
        }
        // The marker is per cancellation, not per seat (audit REG-D4): a seat
        // restored to confirmed had it cleared by update_status(), so a second
        // cancellation enqueues a second email instead of being swallowed.
        if ( (int) \get_post_meta( (int) $seat_id, Registrations::META_CANCEL_EMAILED, true ) > 0 ) {
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
     * Answers `sent` ONLY when wp_mail() accepted the message. Every reason it
     * does not mail — already emailed about this cancellation, cancellation
     * emails switched off, no address on the seat, the seat gone — is a
     * `skipped`, not a failure: none of them is fixable by a retry, and none is
     * a send (audit REG-D4/REG-D6). `failed` is reserved for wp_mail() saying
     * no, which is the one case that earns a retry job.
     *
     * @param int $seat_id
     * @return Outcome sent | skipped (already_sent, notifications_off, seat_gone,
     *                 no_address, disabled) | failed (wp_mail).
     */
    public function send_cancellation_email( $seat_id ) {
        $seat_id = (int) $seat_id;
        if ( (int) \get_post_meta( $seat_id, Registrations::META_CANCEL_EMAILED, true ) > 0 ) {
            $this->clear_email_retry( $seat_id, 'cancellation' ); // nothing left to retry
            return Outcome::skipped( 'already_sent' );
        }
        // Defense-in-depth: this method is public, so re-honor the toggle here even
        // though on_seat_status_changed() already gates the normal enqueue path.
        $settings = $this->get_settings();
        if ( empty( $settings['notify_cancellation'] ) ) {
            return Outcome::skipped( 'notifications_off' );
        }
        $info  = $this->registrations->get_seat_info( $seat_id );
        if ( ! \is_array( $info ) ) {
            return Outcome::skipped( 'seat_gone' );
        }
        // REG-D53 — the snapshot carries these now; no reaching around the
        // data layer for seat storage.
        $email    = (string) $info['email'];
        $name     = (string) $info['name'];
        $order_id = (int) $info['order_id'];
        if ( $email === '' ) {
            return Outcome::skipped( 'no_address' );
        }
        $event_id = (int) $info['event_id'];
        if ( ! $this->is_email_enabled( $event_id, 'cancellation' ) ) {
            return Outcome::skipped( 'disabled' );
        }
        $status   = (string) $info['status']; // cancelled | refunded
        $order    = ( $order_id > 0 && \function_exists( 'wc_get_order' ) ) ? \wc_get_order( $order_id ) : null;

        $tokens = $this->email_tokens( [ 'event_id' => $event_id, 'seat' => array_merge( $info, [ 'name' => $name, 'status' => $status ] ), 'order' => $order ?: null ] );
        $is_refund = ( $status === \Anchor\Events\Registrations::STATUS_REFUNDED );

        // REG-D51 — a refund has its own subject and opening lines. This used
        // to be str_ireplace( 'cancelled', 'refunded', ... ) over the author's
        // own prose: copy that never says "cancelled" ("Sorry — your seat has
        // been released") went out with no mention of a refund at all, and copy
        // that says it twice came out as "your refunded registration for the
        // refundation policy course". A word-level rewrite of admin-authored
        // text in an arbitrary language cannot be made to work; a second pair
        // of fields can. Per-event overrides stay with the cancellation tab
        // that owns them until a refund tab exists to write a refund one.
        if ( $is_refund ) {
            $subject_source = (string) $settings['refund_subject'];
            $intro_source   = (string) $settings['refund_intro'];
        } else {
            $subject_source = $this->get_email_field( $event_id, 'cancellation', 'subject', $settings['cancellation_subject'] );
            $intro_source   = $this->get_email_field( $event_id, 'cancellation', 'intro', $settings['cancellation_intro'] );
        }
        $subject = $this->expand_email_tokens( $subject_source, $tokens );
        $intro   = $this->expand_email_tokens( $intro_source, $tokens );
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
            'type'          => 'cancellation',
        ];
        $html = $this->build_registration_email_html( $ctx );
        $sent = $this->send_html_email( $email, $subject, $html, [], $event_id );
        if ( $sent ) {
            // WHEN it was emailed, not merely THAT it was: the value is what
            // survives a later un-cancel/re-cancel cycle being distinguishable
            // from the marker update_status() cleared.
            \update_post_meta( $seat_id, Registrations::META_CANCEL_EMAILED, \time() );
            $this->clear_email_retry( $seat_id, 'cancellation' );
        } else {
            // The in-memory queue was emptied before this ran, and the seat is
            // already terminal, so nothing else will ever re-enqueue it
            // (audit REG-D5). Leave the job for the hourly sweep to drain.
            $this->queue_email_retry( $seat_id, [ 'type' => 'cancellation' ] );
        }
        return Outcome::from_bool( $sent, 'wp_mail' );
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
     * Task 3.1 — the shared shell markup, extracted verbatim (byte-for-byte,
     * via mechanical text surgery on the pre-refactor source) from the
     * original build_registration_email_html() ob_start() block. This is the
     * ultimate fallback for every type (resolve_email_template()). Scalar
     * tokens: {event_title} {site_name} (both pre-escaped for HTML safety
     * via esc_html() before this array is even built — see the $tokens map
     * in build_registration_email_html()), {attendee_name} {status}
     * {event_date} {event_time} {venue} {days_until} (esc_html()'d in that
     * same map), {join_link} {event_url} (esc_url()'d), and {event_id}
     * (numeric, safe as-is). Escaping happens there — Task 3.1 hardening —
     * because $template can be an admin-authored custom override
     * (resolve_email_template()) with these scalars placed directly in raw
     * HTML, and attendee_name/venue trace back to sanitize_text_field()
     * input (tags stripped, but not entity-escaped). Block tokens (pre-rendered
     * HTML fragments for the structured/conditional regions):
     * {header_image} {greeting} {intro} {guests_line} {waitlist_notice}
     * {detail_rows} {seat_list} {cta_button}. There is no
     * separate {footer} block token — the footer region is static markup
     * with only a scalar {site_name} substitution, so no block extraction
     * was needed for it.
     */
    private static function default_email_shell() {
        return <<<'ANCHOR_EVENTS_EMAIL_SHELL'
        <html>
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width,initial-scale=1" />
            <title>{event_title}</title>
        </head>
        <body style="margin:0;padding:0;background:{brand_bg};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            {preheader}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{brand_bg};padding:24px 12px;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:{brand_surface};border-radius:8px;overflow:hidden;">
                            {logo}
                            {header_image}
                            <tr>
                                <td style="padding:28px 32px 8px;">
                                    <h1 style="margin:0;font-size:24px;line-height:1.3;color:{brand_heading};">{event_title}</h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:16px 32px 8px;">
                                    {greeting}
                                    {intro}
                                    {guests_line}
                                    {waitlist_notice}
                                    {detail_rows}
                                    {seat_list}
                                </td>
                            </tr>
                            {cta_button}
                            {cta_button_2}
                            <tr>
                                <td style="padding:16px 32px 24px;border-top:1px solid #eee;font-size:12px;color:#888;">
                                    {site_name}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        
ANCHOR_EVENTS_EMAIL_SHELL;
    }

    /**
     * Default template constant for $type — the ultimate fallback (Task 3.1
     * orientation: all four share the same shell today). Public so the
     * Task 3.2 builder UI can offer a "reset to default" preview.
     */
    public function default_email_template( $type ) {
        return self::default_email_shell();
    }

    /**
     * Resolve the effective template for $type on $event_id: per-event
     * override meta -> default constant.
     *
     * REG-D12 — there used to be a middle "site-wide default" tier reading the
     * option `anchor_events_email_tpl_{type}`. No UI, saver or migration ever
     * wrote that option, so the tier could never be populated by an
     * administrator and only ever added an unreachable branch. It is gone;
     * per-event overrides and the shipped default are the whole story.
     *
     * @param string $type     One of EMAIL_TEMPLATE_TYPES.
     * @param int    $event_id 0 = no per-event override lookup.
     * @return string
     */
    public function resolve_email_template( string $type, int $event_id ): string {
        $type = \in_array( $type, self::EMAIL_TEMPLATE_TYPES, true ) ? $type : 'confirmation';

        // Task 3.2 — "Preview with real data" substitution. Only ever set
        // (and immediately unset in a finally block) inside
        // ajax_email_preview(); every other caller — every real send,
        // resolve_email_template( $type, 0 ) inside save_email_templates(),
        // etc. — always sees this as null and this branch never runs.
        if ( $this->preview_template_override !== null && $this->preview_template_override['type'] === $type ) {
            return self::strip_email_doctype( $this->preview_template_override['html'] );
        }

        // One exit, so REG-D25's doctype strip applies to every source: a stored
        // per-event override written before this fix, a global option template
        // pasted in with its own doctype, and the shipped shell alike. The
        // builder pre-fill, the "reset to default" text and save_email_templates()'s
        // "is this still the default?" comparison all read through here, so none
        // of them can disagree about whether a template carries a doctype.
        $template = '';
        if ( $event_id > 0 ) {
            $template = (string) \get_post_meta( $event_id, '_anchor_event_email_tpl_' . $type, true );
        }
        if ( $template === '' ) {
            $template = $this->default_email_template( $type );
        }
        return self::strip_email_doctype( $template );
    }

    /**
     * Block-token renderer — verbatim byte-for-byte extraction of the
     * original inline `header_image` conditional from build_registration_email_html().
     * Returns '' when the condition is false, exactly as before.
     */
    /**
     * The {intro} region — the author's opening lines, rendered for email.
     *
     * Two shapes arrive here and both have to keep working:
     *
     *  - Plain text, from every event authored before the opening lines became a
     *    visual editor (and from the site-wide defaults, which are still plain
     *    __() strings). Handled exactly as it always was: split on blank lines,
     *    escaped, one styled <p> per block. Byte-for-byte identical output — no
     *    existing event's email changes.
     *  - Markup, from the visual editor. Run through wp_kses_post() and then
     *    given inline styles, because email clients have no stylesheet to fall
     *    back on: a bare <p> from TinyMCE would inherit whatever Gmail feels
     *    like, next to the styled paragraphs the rest of the template emits.
     *
     * The discriminator is a named list of the tags the editor actually emits,
     * NOT "does this contain a < ". strip_tags() reads "3<4 and 5>2" as a tag
     * and deletes "4 and 5"; routing that sentence down the markup branch would
     * silently destroy an author's copy. A named list cannot misfire on prose.
     */
    private function tpl_block_intro( $message ) {
        $message = (string) $message;
        if ( \trim( $message ) === '' ) {
            return '';
        }

        $has_markup = (bool) \preg_match(
            '#</?(?:p|br|ul|ol|li|strong|em|b|i|u|a|h[1-6]|blockquote|img|span|div|table|tr|td)\b[^>]*>#i',
            $message
        );

        if ( ! $has_markup ) {
            $paragraphs = '';
            foreach ( \preg_split( "/(\r\n|\n|\r){2,}/", \trim( $message ) ) as $block ) {
                $block = \trim( $block );
                if ( $block === '' ) {
                    continue;
                }
                $paragraphs .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">'
                    . \nl2br( \esc_html( $block ) )
                    . '</p>';
            }
            return $paragraphs;
        }

        // wpautop() because the classic editor hands its content back to the
        // textarea with paragraphs collapsed to blank lines (inline tags intact),
        // so the stored value can be either shape. wpautop() turns the collapsed
        // form into paragraphs and leaves already-wrapped markup alone, which
        // means one path renders both.
        return $this->inline_email_styles( \wpautop( \wp_kses_post( $message ) ) );
    }

    /**
     * Give the editor's bare block tags the inline styles email needs.
     *
     * Only tags with no style attribute of their own are touched, so an author
     * who set something in the HTML tab keeps it.
     */
    private function inline_email_styles( $html ) {
        $styles = [
            'p'          => 'margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;',
            'ul'         => 'margin:0 0 16px;padding:0 0 0 20px;font-size:16px;line-height:1.5;color:#333;',
            'ol'         => 'margin:0 0 16px;padding:0 0 0 20px;font-size:16px;line-height:1.5;color:#333;',
            'li'         => 'margin:0 0 6px;',
            'h1'         => 'margin:0 0 12px;font-size:24px;line-height:1.3;color:#111;',
            'h2'         => 'margin:0 0 12px;font-size:20px;line-height:1.3;color:#111;',
            'h3'         => 'margin:0 0 12px;font-size:18px;line-height:1.3;color:#111;',
            'h4'         => 'margin:0 0 12px;font-size:16px;line-height:1.3;color:#111;',
            'blockquote' => 'margin:0 0 16px;padding:0 0 0 16px;border-left:3px solid #ddd;color:#555;',
            'a'          => 'color:#0f766e;',
            'img'        => 'max-width:100%;height:auto;display:block;',
        ];

        foreach ( $styles as $tag => $css ) {
            $html = \preg_replace_callback(
                '#<' . $tag . '(\s[^>]*)?>#i',
                function ( $m ) use ( $tag, $css ) {
                    $attrs = $m[1] ?? '';
                    if ( \stripos( $attrs, 'style=' ) !== false ) {
                        return $m[0];
                    }
                    return '<' . $tag . $attrs . ' style="' . $css . '">';
                },
                $html
            );
        }

        return $html;
    }

    private function tpl_block_header_image( $image_url, $event_title ) {
        \ob_start();
        ?><?php if ( $image_url ) : ?>
                            <tr>
                                <td style="padding:0;">
                                    <img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $event_title ); ?>" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;" />
                                </td>
                            </tr>
                            <?php endif; ?><?php
        return \ob_get_clean();
    }

    /**
     * Block-token renderer — verbatim byte-for-byte extraction of the
     * original inline `greeting` conditional from build_registration_email_html().
     * Returns '' when the condition is false, exactly as before.
     */
    /**
     * The {preheader} region — the line an inbox shows after the subject.
     *
     * Hidden in the message body itself: every belt-and-braces property here is
     * for one client or another (mso-hide for Outlook, the 1px font and zero
     * opacity for the rest), because none of them agree on how to hide a node
     * without also hiding it from the list preview.
     *
     * The trailing run of zero-width joiners and word joiners is the standard
     * trick to stop a client padding the preview out with whatever body copy
     * comes next — without it the inbox shows the preheader followed by the
     * first line of the email anyway, which defeats the point of writing one.
     *
     * Tags are stripped: this is read as plain text by the mail client, and
     * markup here would show up as literal angle brackets in the inbox list.
     */
    private function tpl_block_preheader( $text ) {
        $text = \trim( \wp_strip_all_tags( (string) $text ) );
        if ( $text === '' ) {
            return '';
        }
        return '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;'
            . 'font-size:1px;line-height:1px;color:#ffffff;opacity:0;">'
            . \esc_html( $text )
            . \str_repeat( '&#8199;&#65279;&#847;', 30 )
            . '</div>';
    }

    private function tpl_block_greeting( $name ) {
        \ob_start();
        ?><?php if ( $name ) : ?>
                                        <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">
                                            <?php echo esc_html( sprintf( __( 'Hi %s,', 'anchor-schema' ), $name ) ); ?>
                                        </p>
                                    <?php endif; ?><?php
        return \ob_get_clean();
    }

    /**
     * Block-token renderer — verbatim byte-for-byte extraction of the
     * original inline `guests_line` conditional from build_registration_email_html().
     * Returns '' when the condition is false, exactly as before.
     */
    private function tpl_block_guests_line( $guests ) {
        \ob_start();
        ?><?php if ( $guests > 0 ) : ?>
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
                                    <?php endif; ?><?php
        return \ob_get_clean();
    }

    /**
     * Block-token renderer — verbatim byte-for-byte extraction of the
     * original inline `waitlist_notice` conditional from build_registration_email_html().
     * Returns '' when the condition is false, exactly as before.
     */
    private function tpl_block_waitlist_notice( $status ) {
        \ob_start();
        ?><?php if ( $status === 'waitlist' ) : ?>
                                        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#666;">
                                            <?php echo esc_html__( 'You are currently on the waitlist and will be notified if a spot opens up.', 'anchor-schema' ); ?>
                                        </p>
                                    <?php endif; ?><?php
        return \ob_get_clean();
    }

    /**
     * Block-token renderer — verbatim byte-for-byte extraction of the
     * original inline `detail_rows` conditional from build_registration_email_html().
     * Returns '' when the condition is false, exactly as before.
     */
    private function tpl_block_detail_rows( array $detail_rows ) {
        \ob_start();
        ?><?php if ( ! empty( $detail_rows ) ) : ?>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-collapse:collapse;">
                                            <?php foreach ( $detail_rows as $row ) :
                                                $label = isset( $row['label'] ) ? (string) $row['label'] : '';
                                                $value = isset( $row['value'] ) ? (string) $row['value'] : '';
                                                if ( $label === '' && $value === '' ) { continue; } ?>
                                                <tr>
                                                    <td style="padding:6px 12px 6px 0;font-size:14px;color:#666;vertical-align:top;"><?php echo esc_html( $label ); ?></td>
                                                    <td style="padding:6px 0;font-size:14px;color:#222;vertical-align:top;text-align:right;white-space:nowrap;"><?php echo esc_html( $value ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    <?php endif; ?><?php
        return \ob_get_clean();
    }

    /**
     * Block-token renderer — verbatim byte-for-byte extraction of the
     * original inline `seat_list` conditional from build_registration_email_html().
     * Returns '' when the condition is false, exactly as before.
     */
    private function tpl_block_seat_list( array $seat_list ) {
        \ob_start();
        ?><?php if ( ! empty( $seat_list ) ) : ?>
                                        <p style="margin:0 0 6px;font-size:14px;font-weight:600;color:#333;"><?php echo esc_html__( 'Attendees', 'anchor-schema' ); ?></p>
                                        <ul style="margin:0 0 16px;padding:0 0 0 18px;font-size:14px;line-height:1.6;color:#333;">
                                            <?php foreach ( $seat_list as $seat ) : ?>
                                                <li><?php echo esc_html( (string) $seat ); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?><?php
        return \ob_get_clean();
    }

    /**
     * Block-token renderer — verbatim byte-for-byte extraction of the
     * original inline `cta_button` conditional from build_registration_email_html().
     * Returns '' when the condition is false, exactly as before.
     */
    private function tpl_block_cta_button( $cta_url, $cta_label, $bg = '#111', $fg = '#ffffff' ) {
        $border = ( $bg === '#ffffff' ) ? 'border:1px solid #d4d4d8;' : 'border:1px solid ' . $bg . ';';
        \ob_start();
        ?><?php if ( $cta_url && $cta_label ) : ?>
                            <tr>
                                <td style="padding:8px 32px 32px;">
                                    <a href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;padding:12px 20px;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $fg ); ?>;text-decoration:none;border-radius:4px;font-size:15px;<?php echo esc_attr( $border ); ?>">
                                        <?php echo esc_html( $cta_label ); ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endif; ?><?php
        return \ob_get_clean();
    }

    /**
     * The recolorable regions of a rendered event email, keyed by the setting
     * that drives each one. Every entry is [ <literal the stock markup emits>,
     * [ <css declaration prefixes to rewrite> ] ].
     *
     * Two properties this map has to keep:
     *
     * 1. The stock literal is the SOURCE OF TRUTH for "unchanged". A setting
     *    equal to it (in any hex spelling) is skipped entirely, so an install
     *    that never touched Email Appearance renders the byte-for-byte email
     *    it rendered before the feature existed — which is what
     *    tests/test-email-templates.php's byte-equivalence cases assert.
     *    Getting this wrong is not cosmetic: the stock CTA is #111, so a
     *    "default" of #0f766e silently repaints every button on every site.
     *
     * 2. Prefixes are matched WITHOUT their trailing semicolon, because a
     *    saved template has been through sanitize_email_template_html() ->
     *    wp_kses, which rebuilds style attributes and drops the final ";".
     *    apply_email_appearance() therefore anchors with a hex-digit
     *    lookahead rather than a literal ";" (which would no-op on every
     *    customized template) — see that method.
     *
     * The email has two stock buttons — the near-black CTA (#111) and the teal
     * "join" button on virtual events (#0f766e) — and one setting drives both.
     * Customizing flattens that distinction; leaving one of them stock while
     * the other follows the brand color looks like a bug, not a design.
     *
     * `color:#222` (detail-row values) rides along with the body text color:
     *  it is one shade darker than #333 in the stock design, but recoloring
     *  the body while leaving it behind is how you get black-on-black rows in
     *  a dark palette. Losing that one shade of emphasis is the cheaper bug.
     *
     * @return array<string,array{0:string,1:string[]}>
     */
    private function email_appearance_map() {
        return [
            'email_background_color' => [ '#f4f4f4', [ 'background:#f4f4f4' ] ],
            'email_card_color'       => [ '#ffffff', [ 'background:#ffffff' ] ],
            'email_heading_color'    => [ '#111111', [ 'color:#111' ] ],
            'email_text_color'       => [ '#333333', [ 'color:#333', 'color:#222' ] ],
            'email_button_color'     => [ '#111111', [ 'background:#111', 'background:#0f766e' ] ],
        ];
    }

    /**
     * Expand a hex color to its lowercase 6-digit form so #111 and #111111
     * compare equal. Non-hex input is returned as-is (callers have already
     * run it through sanitize_hex_color()).
     *
     * @param string $hex
     * @return string
     */
    private function normalize_hex_color( $hex ) {
        $hex = \strtolower( \trim( (string) $hex ) );
        if ( \preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $hex, $m ) ) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        return $hex;
    }

    /**
     * Apply basic global branding to rendered event emails. A setting left at
     * the stock value is not applied at all, so an untouched install keeps its
     * existing email output byte for byte.
     *
     * @param string $html
     * @param array  $settings
     * @return string
     */
    private function apply_email_appearance( $html, array $settings ) {
        $html = $this->recolor_email_literals( $html, $settings );

        $logo = $this->email_logo_row( $settings );
        if ( $logo === '' ) {
            return $html;
        }

        // Anchor on the 600px content table's opening tag, not on its style
        // attribute: kses rewrites that attribute (and the card color may have
        // just been swapped above), so matching the full tag string means the
        // logo silently never renders on any customized template. width="600"
        // is unique to this table in the shell.
        $out = \preg_replace_callback(
            '/<table\b[^>]*\bwidth="600"[^>]*>/',
            function ( $m ) use ( $logo ) {
                return $m[0] . $logo;
            },
            $html,
            1
        );

        return \is_string( $out ) ? $out : $html;
    }

    /**
     * The colour half of apply_email_appearance(): rewrite the stock literal
     * declarations to the configured palette.
     *
     * Split out for REG-D27. A template that opts into the {brand_*} tokens
     * gets its colours from the token layer instead, but the block-token
     * fragments this class generates (greeting, intro, detail rows, CTA
     * buttons) still carry the stock literals — they are rendered by PHP, not
     * authored — so they go through here on every path.
     *
     * @param string $html
     * @param array  $settings
     * @return string
     */
    private function recolor_email_literals( $html, array $settings ) {
        $html = (string) $html;

        foreach ( $this->email_appearance_map() as $key => $spec ) {
            list( $stock, $prefixes ) = $spec;

            $value = \sanitize_hex_color( $settings[ $key ] ?? '' );
            if ( ! $value || $this->normalize_hex_color( $value ) === $this->normalize_hex_color( $stock ) ) {
                continue;
            }

            foreach ( $prefixes as $prefix ) {
                // The lookahead is what makes a semicolon-less match safe:
                // it stops `color:#111` from eating the `#111111` in a
                // longhand value, and lets the same pattern hit both the
                // `...;` (raw shell) and `..."` (post-kses) spellings.
                // Keep the property name: the prefix matched is the whole
                // `background:#f4f4f4` declaration, so the replacement has to
                // be `background:` + the new color, not the color alone.
                $property    = \substr( $prefix, 0, \strpos( $prefix, '#' ) );
                $pattern     = '/' . \preg_quote( $prefix, '/' ) . '(?![0-9a-fA-F])/';
                $out         = \preg_replace( $pattern, $property . $value, $html );
                if ( \is_string( $out ) ) {
                    $html = $out;
                }
            }
        }

        return $html;
    }

    /**
     * The logo table row, or '' when no logo is configured.
     *
     * One renderer for both placements: the {logo} token (REG-D27) and
     * apply_email_appearance()'s width="600" anchor, which stays as the
     * fallback for templates that use none of the tokens.
     *
     * @param array $settings
     * @return string
     */
    private function email_logo_row( array $settings ) {
        $logo_url = \esc_url( $settings['email_logo_url'] ?? '' );
        if ( $logo_url === '' ) {
            return '';
        }
        return '<tr><td align="center" style="padding:24px 32px 0;">'
            . '<img src="' . $logo_url . '" alt="' . esc_attr( \get_bloginfo( 'name' ) ) . '" width="160" style="display:block;max-width:160px;width:100%;height:auto;border:0;" />'
            . '</td></tr>';
    }

    /**
     * The Email Appearance palette, exposed as template tokens (REG-D27).
     *
     * apply_email_appearance() applies the palette by rewriting the stock
     * literal colour strings, which works only for a template that still
     * contains them — a hand-built one (production event 7258) uses its own
     * colours and its own table markup, so the branding settings and the logo
     * applied to nothing and nothing said so. These tokens are the opt-in a
     * custom template can use instead.
     *
     * The value is the STOCK LITERAL whenever the setting is unset or equal to
     * it, for the same reason apply_email_appearance() skips an unchanged
     * setting: an install that never touched Email Appearance must render the
     * byte-for-byte email it rendered before. Note the spellings are the
     * markup's (`#111`), not email_appearance_map()'s comparison values
     * (`#111111`).
     *
     * @return array<string,array{0:string,1:string}> token => [ settings key, stock literal ]
     */
    private function email_brand_map() {
        return [
            'brand_bg'          => [ 'email_background_color', '#f4f4f4' ],
            'brand_surface'     => [ 'email_card_color', '#ffffff' ],
            'brand_heading'     => [ 'email_heading_color', '#111' ],
            'brand_text'        => [ 'email_text_color', '#333' ],
            'brand_button'      => [ 'email_button_color', '#111' ],
            // No settings field of its own — the stock button label colour, so a
            // template that opts in does not have to hard-code white.
            'brand_button_text' => [ 'email_button_text_color', '#ffffff' ],
        ];
    }

    /**
     * Resolve the {brand_*} and {logo} tokens for one send.
     *
     * @param array $settings
     * @return array<string,string>
     */
    private function email_brand_tokens( array $settings ) {
        $tokens = [];
        foreach ( $this->email_brand_map() as $token => $spec ) {
            list( $key, $stock ) = $spec;
            $value = \sanitize_hex_color( $settings[ $key ] ?? '' );
            $tokens[ $token ] = ( $value && $this->normalize_hex_color( $value ) !== $this->normalize_hex_color( $stock ) )
                ? $value
                : $stock;
        }
        $tokens['logo'] = $this->email_logo_row( $settings );
        return $tokens;
    }

    /** Whether $template opts into the appearance token layer (REG-D27). */
    private function template_uses_brand_tokens( $template ) {
        $template = (string) $template;
        foreach ( \array_merge( \array_keys( $this->email_brand_map() ), [ 'logo' ] ) as $token ) {
            if ( \strpos( $template, '{' . $token . '}' ) !== false ) {
                return true;
            }
        }
        return false;
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
            // Same resolution the live caller (send_registration_emails()) uses,
            // minus the token expansion it does with a seat in hand — a
            // positional caller must not be the one path that ignores the
            // event's own opening lines (REG-D1).
            $intro = $this->get_email_field(
                (int) $arg,
                'confirmation',
                'intro',
                $this->default_confirmation_intro( $settings )
            );
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
                'type'          => 'confirmation',
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
            'type'          => 'confirmation',
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
        $type        = \in_array( $ctx['type'], self::EMAIL_TEMPLATE_TYPES, true ) ? (string) $ctx['type'] : 'confirmation';

        // A2: confirmed registrants of a virtual event get the actual join link in
        // the email so guest/logged-out attendees (free or paid) gain access without
        // needing to be logged in on the gated event page. Allowlisted to confirmed
        // only — cancelled/refunded/waitlist statuses must never receive the link.
        // Also doubles as the source event meta for the {event_date}/{event_time}/
        // {venue} scalar tokens below (Task 3.1), so it is now fetched unconditionally
        // — cheap, since get_the_title()/get_the_post_thumbnail_url() above have
        // already primed WP's per-post meta cache for this same post id.
        $event_meta = $event_id ? $this->get_meta( $event_id ) : [];
        $join_url   = '';
        if ( $event_id && $status === 'confirmed' ) {
            if ( ! empty( $event_meta['virtual'] ) && ! empty( $event_meta['virtual_url'] ) ) {
                $join_url = (string) $event_meta['virtual_url'];
            }
        }

        // Preview only. A confirmation for an in-person event has no room link,
        // no guests and no waitlist notice, so three of the blocks in the
        // template rendered nothing and looked broken. Showing them with sample
        // content is the only way to see what they do. $preview is false on
        // every send path, so nothing here can reach an inbox.
        // Per-event CTA overrides. Resolved here rather than in each caller's
        // $ctx so every send path — free, WooCommerce, reminder, cancellation,
        // roster — picks them up from one place.
        //
        // A virtual event defaults to its room link. That replaced the separate
        // {join_button} region, which was a second button no field controlled:
        // it appeared and disappeared on rules the author could not see, and
        // read as a stray duplicate of the CTA sitting right beside it. Same
        // link, same place, but now it is in a field that can be renamed,
        // repointed, or emptied. REG-D28 removed the leftover region and its
        // token, which no shipped template and no palette ever offered.
        $cta  = $this->get_email_cta( $event_id, $type, 1, $this->default_email_cta( $event_id, [ 'label' => $cta_label, 'url' => $cta_url ] ) );
        $cta2 = $this->get_email_cta( $event_id, $type, 2 );

        $preview = ! empty( $this->preview_samples );
        if ( $preview ) {
            $samples = $this->preview_sample_scalars( $event_id );
            if ( $guests === 0 ) { $guests = 1; }

            // A stand-in room link ONLY for an event that is actually virtual
            // and simply has no URL saved yet. Never for an in-person event.
            //
            // The retired {join_button} was a full-width button, and faking one
            // put a second button in the preview of an event that will never
            // send it — indistinguishable from the CTA buttons that ARE
            // configured just above it. Same reason {header_image} gets no
            // stand-in photo: a
            // sample is helpful when it is obviously filling a gap in a line of
            // text, and misleading when it renders as a piece of the layout the
            // author did not put there. The {join_link} SCALAR still gets a
            // stand-in further down, so a token typed into the body resolves.
            if ( $join_url === '' && ! empty( $event_meta['virtual'] ) ) {
                $join_url = $samples['join_link'];
            }
        }

        $paragraphs = $this->tpl_block_intro( $message );

        // Task 3.1 — resolve the template (per-event override -> global option ->
        // default constant), then expand the same scalar+block token set into it.
        $template = $this->resolve_email_template( $type, $event_id );

        $start_ts = (int) ( $event_meta['start_ts'] ?? 0 );
        $venue    = '';
        if ( ! empty( $event_meta['virtual'] ) ) {
            $venue = __( 'Online', 'anchor-schema' );
        } elseif ( ! empty( $event_meta['venue'] ) ) {
            $venue = (string) $event_meta['venue'];
        }

        $tokens = [
            // Scalar tokens — the documented event-email token set (spec §9),
            // computed locally rather than via email_tokens() so this method
            // never issues the extra get_event_summary() query that method's
            // 'remaining'/'seat_count' fallback would trigger on every send.
            // Task 3.1 hardening (Medium finding): every scalar below is
            // inserted via raw str_replace() into $template, which may be an
            // admin-authored custom override (resolve_email_template()) with
            // these tokens placed directly in HTML markup rather than inside
            // one of the already-escaped block-token fragments. attendee_name
            // and venue trace to user/registration input that is only
            // sanitize_text_field()'d (tags stripped, but & and quotes are
            // NOT entity-escaped) — output-escape every scalar here so no
            // custom template can become a stored-injection vector. TEXT
            // scalars use esc_html(); URL scalars use esc_url(). event_title/
            // site_name were already pre-escaped above — not re-wrapped here.
            'event_id'      => (string) $event_id,
            'event_title'   => esc_html( $event_title ), // pre-escaped: used in <title>/<h1>.
            'site_name'     => esc_html( $site_name ),   // pre-escaped: used in the footer.
            'attendee_name' => esc_html( $name ),
            'status'        => esc_html( $status ),
            'join_link'     => esc_url( $join_url ),
            'event_url'     => esc_url( $event_id ? \get_permalink( $event_id ) : \home_url() ),
            'event_date'    => esc_html( $start_ts ? \wp_date( \get_option( 'date_format' ), $start_ts ) : '' ),
            'event_time'    => esc_html( ( $start_ts && empty( $event_meta['all_day'] ) ) ? \wp_date( \get_option( 'time_format' ), $start_ts ) : '' ),
            'venue'         => esc_html( $venue ),
            'days_until'    => esc_html( ( $start_ts && $start_ts > time() ) ? (string) (int) ceil( ( $start_ts - time() ) / DAY_IN_SECONDS ) : '' ),

            // Block tokens — pre-rendered HTML fragments for the structured/
            // conditional regions, built by the same code (byte-for-byte) that
            // used to render them inline.
            'intro'            => $paragraphs,
            'header_image'     => $this->tpl_block_header_image( $image_url, $event_title ),
            'greeting'         => $this->tpl_block_greeting( $name ),
            'guests_line'      => $this->tpl_block_guests_line( $guests ),
            'waitlist_notice'  => $this->tpl_block_waitlist_notice( $preview ? 'waitlist' : $status ),
            'detail_rows'      => $this->tpl_block_detail_rows( $detail_rows ),
            'seat_list'        => $this->tpl_block_seat_list( $seat_list ),
            'cta_button'       => $this->tpl_block_cta_button( $cta['url'], $cta['label'] ),
            'cta_button_2'     => $this->tpl_block_cta_button( $cta2['url'], $cta2['label'], '#ffffff', '#111' ),
        ];

        // Preview only: any scalar this event has no value for gets a stand-in,
        // so a token never expands to a gap the author has to guess about.
        // Escaped the same way the real value directly above would have been.
        if ( $preview ) {
            foreach ( $this->preview_sample_scalars( $event_id ) as $key => $sample ) {
                if ( ! isset( $tokens[ $key ] ) || \trim( (string) $tokens[ $key ] ) !== '' ) {
                    continue;
                }
                $tokens[ $key ] = \in_array( $key, [ 'join_link', 'event_url', 'order_url' ], true )
                    ? \esc_url( $sample )
                    : \esc_html( $sample );
            }
        }

        // The preheader, expanded against the SCALARS only: a block token here
        // would push markup into a line the inbox reads as plain text.
        $scalars = \array_intersect_key( $tokens, \array_flip( [
            'event_id', 'event_title', 'site_name', 'attendee_name', 'status', 'join_link',
            'event_url', 'event_date', 'event_time', 'venue', 'days_until',
        ] ) );
        $tokens['preheader'] = $this->tpl_block_preheader( $this->expand_email_tokens(
            $this->get_email_field( $event_id, $type, 'preheader', '' ),
            $scalars
        ) );

        // REG-D27 — two ways the Email Appearance palette reaches the email, and
        // exactly one of them runs. A template that opts into the {brand_*}/
        // {logo} tokens gets the palette through the same token layer as
        // everything else; one that does not falls back to
        // apply_email_appearance()'s literal rewrite, so an install that never
        // customized its template renders byte-for-byte what it always did.
        //
        // The block tokens above are markup this class generates with the stock
        // literals baked in, so on the token path they still need the rewrite —
        // otherwise opting a template in would silently stop recolouring the
        // greeting, the detail rows and the CTA buttons.
        $email_settings = $this->get_settings();
        $tokens         = \array_merge( $tokens, $this->email_brand_tokens( $email_settings ) );

        if ( $this->template_uses_brand_tokens( $template ) ) {
            foreach ( self::EMAIL_BLOCK_TOKENS as $block ) {
                if ( isset( $tokens[ $block ] ) && $tokens[ $block ] !== '' ) {
                    $tokens[ $block ] = $this->recolor_email_literals( $tokens[ $block ], $email_settings );
                }
            }
            $html = $this->expand_email_tokens( $template, $tokens );
        } else {
            $html = $this->expand_email_tokens( $template, $tokens );
            $html = $this->apply_email_appearance( $html, $email_settings );
        }

        // REG-D25 — the doctype belongs to the assembled document, not to the
        // stored template: the kses allowlist cannot express one, so every
        // saved template loses it and the mail renders in quirks mode.
        $html = self::email_document_html( $html );

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
            // A refund is its own message, not the cancellation copy with a word
            // swapped in it (audit REG-D51).
            'refund_subject'         => __( 'Your registration for {event_title} has been refunded', 'anchor-schema' ),
            'refund_intro'           => __( 'Your registration for {event_title} has been refunded. If this is unexpected, please contact us.', 'anchor-schema' ),
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
            'email_cc'               => '',
            'email_bcc'              => '',
            'email_logo_url'         => '',
            'email_background_color' => '#f4f4f4',
            'email_card_color'       => '#ffffff',
            'email_text_color'       => '#333333',
            'email_heading_color'    => '#111111',
            'email_button_color'     => '#111111',
        ];
        $settings = \get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }
        return \wp_parse_args( $settings, $defaults );
    }
}
