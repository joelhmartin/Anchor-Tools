# Events Email Gap (Lifecycle Notifications) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the deferred lifecycle emails to `anchor-events-manager` — pre-event reminders, an attendee-facing cancellation/refund email, an organizer roster digest (manual + scheduled), and a rounded-out documented placeholder/template system — all free+paid, WC-optional, HPOS-safe, idempotent.

**Architecture:** All sends reuse `Module::send_html_email()` + `Module::build_registration_email_html($ctx)` + `Module::expand_email_tokens()`. New emails key off the **seat/event data layer** (`Registrations`), never orders, so they work with WooCommerce absent. Reminders + scheduled roster ride one new hourly cron modeled on the existing `anchor_events_status_sweep`. The attendee cancellation email fires from a single `do_action` in `Registrations::update_status()` (the only status writer), deferred out of the event lock and flushed once per request.

**Tech Stack:** Raw PHP (no build step), WordPress plugin APIs (`register_post_meta`, Settings API, WP-Cron), WP-CLI for verification. PHP 7.4+ compatible (match existing module). Text domain `anchor-schema`.

Design spec: `docs/superpowers/specs/2026-06-20-events-email-gap-design.md`. Read it before starting.

## Global Constraints

- **No automated test suite.** Verify manually in a WordPress environment (WP-CLI + admin UI + a mail catcher / `wp_mail` log). Each task lists explicit manual verification.
- **WooCommerce-optional.** Reminder/cancellation/roster code lives on the always-loaded `Module`/`Registrations`. Any order read is guarded by `function_exists('wc_get_order')` / `_anchor_event_order_id > 0` and uses WC CRUD only (HPOS-safe). No `woocommerce_*` hook in the always-loaded path.
- **Text domain:** `'anchor-schema'` for every string; `_n()` for counts; `%d`/`%s` placeholders.
- **Options:** `update_option(..., false)` (autoload false). Settings live in `self::OPTION_KEY`.
- **Cron-upgrade-safe:** schedule defensively on `init` behind `wp_next_scheduled()`; self-unschedule when CPT absent; clear on deactivate. Do NOT rely on `register_activation_hook` (plugin updates don't fire it here).
- **Reuse, no new HTML shells:** every email builds a `$ctx` and calls `build_registration_email_html()`.
- **No `wp_mail` while holding `with_event_lock`.** Cancellation sends are enqueued and flushed after the lock releases.
- **Escape all output; caps + nonces** on the manual roster action and any new admin field saves.
- **Don't break shipped emails:** buyer confirmation, organizer new-registration, organizer seats-released stay unchanged.
- **Idempotency markers (exact keys):** seat `_anchor_event_reminders_sent` (array `offset=>unix`), seat `_anchor_event_cancel_emailed` (bool), event `_anchor_event_roster_sent` (int unix). Reserved seat flag `_anchor_event_attendee_notified` set true on first reminder.
- **Reminders go to `confirmed` seats only** (not pending/waitlist). Cancellation fires only on `confirmed|waitlist → cancelled|refunded`.

Key files:
- `anchor-events-manager/anchor-events-manager.php` (Module: settings, meta, builder, tokens, cron, cancellation handler)
- `anchor-events-manager/class-registrations.php` (the `do_action` in `update_status`)
- `anchor-events-manager/class-roster.php` (manual "Send roster" button + handler)
- `anchor-events-manager/class-woocommerce.php` (delegate `organizer_recipient` to the shared Module helper)

---

## Task 1: Foundations — settings, meta keys, token builder, shared organizer-recipient helper

Additive and inert: no email behavior changes yet. Everything new is registered/available for later tasks. Independently shippable (a no-op on the front end).

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php`
  - `get_settings()` defaults (~3611)
  - `register_settings()` (~2624) + `sanitize_settings()` (~2806)
  - `register_meta()` seat-meta block (~563) and `get_meta_schema()` (~571) / `get_meta_defaults()` (~614)
  - new methods `email_tokens()`, `resolve_organizer_email()`
- Modify: `anchor-events-manager/class-woocommerce.php` — `organizer_recipient()` (~2405) delegates to Module.

**Interfaces:**
- Produces:
  - `Module::email_tokens(array $ctx): array` — `$ctx` keys: `event_id` (int, required), `seat` (array seat DTO|null), `order` (\WC_Order|null), `seat_count` (int|null), `remaining` (string|null). Returns the documented token map (spec §9).
  - `Module::resolve_organizer_email(int $event_id, ?array $settings = null): string` — per-event `_anchor_event_organizer_email` → global `organizer_email` setting → `admin_email`.
  - New settings keys (spec §4.3): `reminder_enabled` (false), `reminder_offsets` ('7,1'), `reminder_subject`, `reminder_intro`, `notify_cancellation` (true), `cancellation_subject`, `cancellation_intro`, `organizer_roster_email` (false), `roster_auto_offset` (1), `roster_subject`, `roster_intro`.
  - New seat meta: `_anchor_event_reminders_sent` (array), `_anchor_event_cancel_emailed` (bool).
  - New event meta: `reminder_offsets` (string), `roster_sent` (int).

- [ ] **Step 1: Add the new settings defaults**

In `get_settings()` (~3611), after `'organizer_email' => ''` and before `'notify_attendee'`:

```php
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
```

- [ ] **Step 2: Register + sanitize the new settings**

In `register_settings()` (~2624) add fields to the Events settings section (follow the existing `add_settings_field` pattern used for `wc_customer_subject` etc.; render text inputs / checkboxes / a textarea for the intros). Place reminder/cancellation/roster fields in the **always-shown** part of the Events tab (NOT inside the `class_exists('WooCommerce')` subsection). Each intro field's description lists available tokens (spec §9).

In `sanitize_settings()` (~2806) add, before the `return $output;`:

```php
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
```

Add the helper (private):

```php
    /** Normalize a CSV of day offsets → sorted-descending, de-duped, positive ints (≤5). */
    private function sanitize_offset_csv( $raw ) {
        $days = array_filter( array_map( 'intval', explode( ',', (string) $raw ) ), function ( $d ) { return $d > 0; } );
        $days = array_values( array_unique( $days ) );
        rsort( $days );
        $days = array_slice( $days, 0, 5 );
        return implode( ',', $days );
    }
```

- [ ] **Step 3: Register the new seat + event meta**

In `register_meta()` after the `_anchor_event_attendee_notified` block (~568):

```php
        \register_post_meta( self::REG_CPT, '_anchor_event_reminders_sent', [
            'type' => 'array', 'single' => true, 'show_in_rest' => false, 'auth_callback' => $reg_auth_callback,
        ] );
        \register_post_meta( self::REG_CPT, '_anchor_event_cancel_emailed', [
            'type' => 'boolean', 'single' => true, 'show_in_rest' => false, 'auth_callback' => $reg_auth_callback,
        ] );
```

In `get_meta_schema()` (~571) add:

```php
            'reminder_offsets' => [ 'type' => 'string' ],
            'roster_sent' => [ 'type' => 'integer', 'show_in_rest' => false ],
```

In `get_meta_defaults()` (~614) add matching defaults:

```php
            'reminder_offsets' => '',
            'roster_sent' => 0,
```

Add `reminder_offsets` to `save_meta()`'s hardcoded `$input` allow-list so the event metabox can save the per-event override (do NOT add `roster_sent` — cron-only). Add a text input to the event registration metabox UI ("Reminder offsets (days, e.g. 14,3,1 — leave blank for default)").

- [ ] **Step 4: Add `email_tokens()` and `resolve_organizer_email()`**

Add to `Module` (near `expand_email_tokens`, ~3399):

```php
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
```

(Confirm `Module` holds a `$this->registrations` reference; per the spec §3 loader it does. If the property name differs, use the actual accessor.)

- [ ] **Step 5: Delegate the WC `organizer_recipient` to the shared helper**

In `class-woocommerce.php` `organizer_recipient()` (~2405) replace the body with:

```php
        return $this->module->resolve_organizer_email( (int) $event_id, $settings );
```

- [ ] **Step 6: Manual verification**

```bash
# Lint
php -l "anchor-events-manager/anchor-events-manager.php"
php -l "anchor-events-manager/class-woocommerce.php"
# Settings round-trip
wp eval '$m = \Anchor\Events\Module::instance(); $s = $m->get_settings(); echo $s["reminder_offsets"]." | ".var_export($s["reminder_enabled"], true)."\n";'
# Token builder smoke (use a real published event id)
wp eval '$m = \Anchor\Events\Module::instance(); print_r($m->email_tokens(["event_id"=>EVENT_ID]));'
# Organizer resolution
wp eval '$m = \Anchor\Events\Module::instance(); echo $m->resolve_organizer_email(EVENT_ID)."\n";'
```
Expected: `7,1 | false`; token array prints `{event_title}` etc.; organizer resolves to admin_email (or per-event/global if set). In wp-admin → Settings → Anchor Tools → Events, the new fields render, save, and persist. Existing transactional emails still send (place a test paid order / free signup → confirmation arrives unchanged).

- [ ] **Step 7: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php anchor-events-manager/class-woocommerce.php
git commit -m "feat(events): email-gap foundations — settings, markers, token builder, shared organizer recipient

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

**Acceptance:** New settings render/save/persist; new meta registered; `email_tokens()` returns the documented set; `resolve_organizer_email()` works with WC absent and present; no behavior change to shipped emails; both files lint clean.

---

## Task 2: Attendee cancellation / refund email

Fires on the real seat transition into `cancelled`/`refunded`, deferred out of the lock, once-only. Covers paid cancel/refund, manual roster cancel, order trash/delete, and free. Independently shippable.

**Files:**
- Modify: `anchor-events-manager/class-registrations.php` — `update_status()` (~196, just before `return true;`)
- Modify: `anchor-events-manager/anchor-events-manager.php` — constructor (~hook registration block ~100-119), new methods `on_seat_status_changed()`, `flush_cancellation_emails()`, `send_cancellation_email()`

**Interfaces:**
- Consumes: `Module::build_registration_email_html()`, `send_html_email()`, `expand_email_tokens()`, `email_tokens()` (Task 1), `Registrations::get_seat_info()` (class-registrations.php:651).
- Produces:
  - Action `anchor_events_seat_status_changed($seat_id, $from, $to, $actor)` fired by `update_status`.
  - `Module::send_cancellation_email(int $seat_id): bool` (idempotent; sets `_anchor_event_cancel_emailed`).

- [ ] **Step 1: Fire the transition action**

In `class-registrations.php::update_status()`, immediately before `return true;` (~196, after `$this->bust_cache($event_id);`):

```php
        \do_action( 'anchor_events_seat_status_changed', $seat_id, $from, $to, (string) $actor );
```

- [ ] **Step 2: Register the handler + flush hooks**

In the `Module` constructor, in the hook-registration block (~100-119):

```php
        // v1.1: attendee cancellation/refund email (spec §7). Enqueue on transition,
        // flush after the event lock releases (shutdown) so no wp_mail runs under GET_LOCK.
        \add_action( 'anchor_events_seat_status_changed', [ $this, 'on_seat_status_changed' ], 10, 4 );
        \add_action( 'shutdown', [ $this, 'flush_cancellation_emails' ] );
```

- [ ] **Step 3: Implement the enqueue handler + flush + send**

Add to `Module` (a private static queue property `$pending_cancellation_emails = []`):

```php
    /** @var int[] Seat ids queued for a cancellation email this request. */
    private $pending_cancellation_emails = [];

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

    /** Build + send one attendee cancellation/refund email; idempotent via marker. */
    public function send_cancellation_email( $seat_id ) {
        $seat_id = (int) $seat_id;
        if ( \get_post_meta( $seat_id, '_anchor_event_cancel_emailed', true ) ) {
            return true;
        }
        $info  = $this->registrations->get_seat_info( $seat_id );
        if ( empty( $info['email'] ) ) {
            return false;
        }
        $settings = $this->get_settings();
        $event_id = (int) $info['event_id'];
        $status   = (string) $info['status']; // cancelled | refunded
        $order    = ( ! empty( $info['order_id'] ) && \function_exists( 'wc_get_order' ) ) ? \wc_get_order( (int) $info['order_id'] ) : null;

        $tokens = $this->email_tokens( [ 'event_id' => $event_id, 'seat' => $info, 'order' => $order ?: null ] );
        $is_refund = ( $status === \Anchor\Events\Registrations::STATUS_REFUNDED );
        $subject = $this->expand_email_tokens(
            $is_refund ? \str_replace( 'cancelled', 'refunded', $settings['cancellation_subject'] ) : $settings['cancellation_subject'],
            $tokens
        );
        $intro = $this->expand_email_tokens(
            $is_refund ? \str_replace( 'cancelled', 'refunded', $settings['cancellation_intro'] ) : $settings['cancellation_intro'],
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
            'name'          => (string) $info['name'],
            'status'        => $status,          // suppresses join link in the builder
            'intro_message' => $intro,
            'detail_rows'   => $detail_rows,
            'cta_label'     => '',
            'cta_url'       => '',
        ];
        $html = $this->build_registration_email_html( $ctx );
        $sent = $this->send_html_email( (string) $info['email'], $subject, $html );
        if ( $sent ) {
            \update_post_meta( $seat_id, '_anchor_event_cancel_emailed', true );
        }
        return $sent;
    }
```

Verify `Registrations::get_seat_info()` returns `email`, `name`, `status`, `event_id`, `order_id` (read class-registrations.php:651; if a key name differs, adjust). If `get_seat_info` lacks `event_id`/`order_id`, read them via `get_post_meta` inside `send_cancellation_email` instead.

- [ ] **Step 4: Flush at end of reconcile for promptness (optional but recommended)**

In `class-woocommerce.php::reconcile_order()`, after the lock-bearing work completes and the end-of-pass `$order->save()` runs (~near the finally/return), call:

```php
        $this->module->flush_cancellation_emails();
```

(Shutdown still covers correctness; this just sends sooner during the request.)

- [ ] **Step 5: Manual verification**

```bash
php -l "anchor-events-manager/class-registrations.php"
php -l "anchor-events-manager/anchor-events-manager.php"
```
With a mail catcher (or `define('WP_DEBUG_LOG', true)` + an SMTP log), run:
- **Free path:** create a confirmed free seat, then cancel it from the Roster screen → attendee receives ONE cancellation email; `wp post meta get <seat_id> _anchor_event_cancel_emailed` → `1`. Cancel again / re-save → no second email.
- **Paid cancel (Env-B):** place + confirm an order, then set the order to Cancelled → attendee gets the cancellation email; organizer still gets the existing "seats released" notice.
- **Partial refund (Env-B):** buy 3, refund qty 1 → only the one refunded attendee gets a *refund*-worded email.
- **Abandoned (Env-B):** on-hold (pending) order → cancelled → NO attendee email (from-status was pending).
- **Toggle off:** set `notify_cancellation` off → cancel a seat → no email.
- **Lock check:** confirm in code/log that no `wp_mail` runs inside `with_event_lock` (sends appear after reconcile / on shutdown).

- [ ] **Step 6: Commit**

```bash
git add anchor-events-manager/class-registrations.php anchor-events-manager/anchor-events-manager.php anchor-events-manager/class-woocommerce.php
git commit -m "feat(events): attendee cancellation/refund email on seat transition (free+paid)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

**Acceptance:** Cancellation email fires exactly once on `confirmed|waitlist → cancelled|refunded` via every path (order cancel, partial refund, manual roster cancel, order trash/delete, free); refund wording when refunded; never on pending→cancelled or no-op passes; respects `notify_cancellation`; no `wp_mail` under the lock; idempotent via `_anchor_event_cancel_emailed`.

---

## Task 3: Pre-event reminder cron + reminder email

One hourly cron, window-scoped query, per-offset marker. Independently shippable.

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php` — constructor (cron registration ~100-119), `on_deactivate()` (~134), new methods `maybe_schedule_reminder_sweep()`, `run_reminder_sweep()`, `send_reminder_email()`, helper `effective_offsets()`

**Interfaces:**
- Consumes: `Registrations::query_seats()`, `email_tokens()`, `build_registration_email_html()`, `send_html_email()`, `expand_email_tokens()`.
- Produces: cron hook `anchor_events_reminder_sweep` (hourly); `Module::run_reminder_sweep()` (public, cron callback); `Module::send_reminder_email(array $seat_dto, int $event_id, int $offset): bool`.

- [ ] **Step 1: Register + schedule the cron (mirror the status sweep)**

In the constructor hook block (~100-119), after the status-sweep lines:

```php
        // v1.1: reminder + scheduled-roster sweep (spec §5). Hourly, scheduled
        // defensively on init so it survives plugin upgrades (no activation hook).
        \add_action( 'init', [ $this, 'maybe_schedule_reminder_sweep' ] );
        \add_action( 'anchor_events_reminder_sweep', [ $this, 'run_reminder_sweep' ] );
```

Add the scheduler:

```php
    public function maybe_schedule_reminder_sweep() {
        if ( ! \wp_next_scheduled( 'anchor_events_reminder_sweep' ) ) {
            \wp_schedule_event( \time() + HOUR_IN_SECONDS, 'hourly', 'anchor_events_reminder_sweep' );
        }
    }
```

In `on_deactivate()` (~134) add, before/after the existing unschedule:

```php
        $rts = \wp_next_scheduled( 'anchor_events_reminder_sweep' );
        if ( $rts ) {
            \wp_unschedule_event( $rts, 'anchor_events_reminder_sweep' );
        }
        \wp_clear_scheduled_hook( 'anchor_events_reminder_sweep' );
```

- [ ] **Step 2: Implement the sweep (reminder pass)**

```php
    /** Effective reminder offsets for an event: per-event override CSV else global. */
    private function effective_offsets( $event_id, array $settings ) {
        $meta = $this->get_meta( (int) $event_id );
        $csv  = ! empty( $meta['reminder_offsets'] ) ? $meta['reminder_offsets'] : $settings['reminder_offsets'];
        $days = array_filter( array_map( 'intval', explode( ',', (string) $csv ) ), function ( $d ) { return $d > 0; } );
        rsort( $days );
        return array_values( array_unique( $days ) );
    }

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
                foreach ( $this->effective_offsets( $event_id, $settings ) as $offset ) {
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
                        if ( $this->send_reminder_email( $seat, $event_id, $offset ) ) {
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
```

Note: `query_seats()` currently caps `per_page` via `max(1, ...)`; confirm `-1` yields all rows (WP_Query treats `-1` as unlimited; the `max(1, (int) -1)` at class-registrations.php:755 would turn `-1` into `1`). **Fix in this task:** in `query_seats`, allow `-1` (`$per_page = (int)$args['per_page']; if ($per_page !== -1) $per_page = max(1,$per_page);`). Verify the change keeps the roster screen pagination working (it passes a positive `per_page`).

- [ ] **Step 3: Implement `send_reminder_email()` and a stub `maybe_send_scheduled_roster()`**

```php
    public function send_reminder_email( array $seat, $event_id, $offset ) {
        if ( empty( $seat['email'] ) ) {
            return false;
        }
        $settings = $this->get_settings();
        $tokens   = $this->email_tokens( [ 'event_id' => (int) $event_id, 'seat' => $seat ] );
        $subject  = $this->expand_email_tokens( $settings['reminder_subject'], $tokens );
        $intro    = $this->expand_email_tokens( $settings['reminder_intro'], $tokens );

        $detail_rows = [];
        if ( $tokens['event_date'] !== '' ) { $detail_rows[] = [ 'label' => \__( 'Date', 'anchor-schema' ), 'value' => $tokens['event_date'] ]; }
        if ( $tokens['event_time'] !== '' ) { $detail_rows[] = [ 'label' => \__( 'Time', 'anchor-schema' ), 'value' => $tokens['event_time'] ]; }
        if ( $tokens['venue'] !== '' )      { $detail_rows[] = [ 'label' => \__( 'Location', 'anchor-schema' ), 'value' => $tokens['venue'] ]; }

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

    /** Placeholder until Task 4 fills the scheduled-roster body. */
    public function maybe_send_scheduled_roster( $event_id, $meta, $settings, $now ) {}
```

- [ ] **Step 4: Manual verification**

```bash
php -l "anchor-events-manager/anchor-events-manager.php"
php -l "anchor-events-manager/class-registrations.php"
# Cron registered?
wp cron event list | grep anchor_events_reminder_sweep
```
- Create an event starting tomorrow; set Settings → `reminder_enabled` on, offsets `1`. Add a confirmed seat with your email.
- Force a run: `wp cron event run anchor_events_reminder_sweep` → you receive ONE reminder; `wp post meta get <seat_id> _anchor_event_reminders_sent` shows `[1 => <ts>]`; `_anchor_event_attendee_notified` → `1`.
- Run again → no second email (marker).
- Set a waitlist/pending/cancelled seat → no reminder. Set event in the past → no reminder.
- Set offsets `7,1` with event in 8 days → run now sends nothing; (simulate) set start to 6 days out → run sends the 7-day reminder only; next day's run (event 1 day out) sends the 1-day reminder; neither repeats.
- `reminder_enabled` off → no reminders. Virtual event → reminder email contains the "Join the event" button.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php anchor-events-manager/class-registrations.php
git commit -m "feat(events): pre-event reminder cron + reminder email (free+paid, opt-in)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

**Acceptance:** Hourly cron registered defensively + cleared on deactivate + self-heals when CPT absent; reminders fire once per confirmed seat per due offset; never for pending/waitlist/cancelled/past; timezone-correct (start_ts); date-move-safe; respects `reminder_enabled` and the `anchor_events_should_send_reminder` filter; idempotent across cron overlaps.

---

## Task 4: Organizer roster digest — manual button + scheduled auto-send

Manual button (roster screen + order panel) and the scheduled pass that plugs into Task 3's sweep. Independently shippable.

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php` — fill `maybe_send_scheduled_roster()`, add `send_roster_email()`
- Modify: `anchor-events-manager/class-roster.php` — render the "Send roster" button on the roster header (~render_page ~113), register + handle `admin_post_anchor_events_send_roster`
- Modify: `anchor-events-manager/class-woocommerce.php` — add the "Send roster" button to the order "Event Registrations" metabox (optional; reuse the same admin-post action)

**Interfaces:**
- Consumes: `Module::resolve_organizer_email()`, `email_tokens()`, `build_registration_email_html()`, `send_html_email()`, `Registrations::get_event_summary()`, `query_seats()`, `Roster::current_user_can_manage()`, `Roster::roster_url()`.
- Produces: `Module::send_roster_email(int $event_id): bool`; admin-post action `anchor_events_send_roster`.

- [ ] **Step 1: Implement `send_roster_email()` (shared by manual + scheduled)**

Add to `Module`:

```php
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
        $tokens  = $this->email_tokens( [ 'event_id' => $event_id, 'seat_count' => count( $seats['items'] ) ] );
        $subject = $this->expand_email_tokens( $settings['roster_subject'], $tokens );
        $intro   = $this->expand_email_tokens( $settings['roster_intro'], $tokens );

        $cap = isset( $summary['capacity'] ) ? (int) $summary['capacity'] : 0;
        $detail_rows = [
            [ 'label' => \__( 'Date', 'anchor-schema' ), 'value' => $tokens['event_date'] ],
            [ 'label' => \__( 'Venue', 'anchor-schema' ), 'value' => $tokens['venue'] ],
            [ 'label' => \__( 'Capacity', 'anchor-schema' ), 'value' => $cap ? (string) $cap : \__( 'Unlimited', 'anchor-schema' ) ],
            [ 'label' => \__( 'Confirmed', 'anchor-schema' ), 'value' => (string) (int) ( $summary['confirmed'] ?? 0 ) ],
            [ 'label' => \__( 'Waitlist', 'anchor-schema' ), 'value' => (string) (int) ( $summary['waitlist'] ?? 0 ) ],
            [ 'label' => \__( 'Remaining', 'anchor-schema' ), 'value' => $tokens['remaining'] ],
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
            'cta_url'       => $this->roster && \method_exists( $this->roster, 'roster_url' ) ? $this->roster->roster_url( $event_id ) : \get_permalink( $event_id ),
        ];
        $html = $this->build_registration_email_html( $ctx );
        return $this->send_html_email( $to, $subject, $html );
    }
```

(Confirm `Module` has `$this->roster`; if not, call `Roster` via the instance accessor or fall back to the permalink as shown.)

- [ ] **Step 2: Fill the scheduled-roster pass**

Replace the Task-3 stub:

```php
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
```

- [ ] **Step 3: Manual "Send roster" button + handler (Roster screen)**

In `class-roster.php`, register the action in the constructor (~62):

```php
        \add_action( 'admin_post_anchor_events_send_roster', [ $this, 'handle_send_roster' ] );
```

In `render_page()` header area (~113, near the summary), render a nonced form (only `if ( self::current_user_can_manage() )`):

```php
        $send_url = \admin_url( 'admin-post.php' );
        echo '<form method="post" action="' . \esc_url( $send_url ) . '" style="display:inline-block;margin-left:8px;">';
        echo '<input type="hidden" name="action" value="anchor_events_send_roster" />';
        echo '<input type="hidden" name="event_id" value="' . \esc_attr( $event_id ) . '" />';
        \wp_nonce_field( 'anchor_events_send_roster_' . $event_id );
        \submit_button( \__( 'Send roster to organizer', 'anchor-schema' ), 'secondary', 'submit', false );
        echo '</form>';
```

Add the handler:

```php
    public function handle_send_roster() {
        if ( ! self::current_user_can_manage() ) {
            \wp_die( \esc_html__( 'You do not have permission to do this.', 'anchor-schema' ) );
        }
        $event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;
        \check_admin_referer( 'anchor_events_send_roster_' . $event_id );
        if ( \get_post_type( $event_id ) !== Module::CPT ) {
            \wp_die( \esc_html__( 'Invalid event.', 'anchor-schema' ) );
        }
        $ok  = $this->module->send_roster_email( $event_id );
        $url = \add_query_arg( [
            'page'         => 'anchor-event-roster',
            'event_id'     => $event_id,
            'roster_sent'  => $ok ? '1' : '0',
        ], \admin_url( 'edit.php?post_type=' . Module::CPT ) );
        \wp_safe_redirect( $url );
        exit;
    }
```

In `render_page()`, surface the `roster_sent` query arg as an `updated`/`error` notice ("Roster sent to organizer." / "Roster could not be sent — check the error log.").

- [ ] **Step 4: (Optional) Order-panel button (Env-B)**

In `class-woocommerce.php` order "Event Registrations" metabox, per linked event, render the same nonced form POSTing `anchor_events_send_roster` with that `event_id`. Skip if time-boxed — the roster-screen button is the primary surface.

- [ ] **Step 5: Manual verification**

```bash
php -l "anchor-events-manager/anchor-events-manager.php"
php -l "anchor-events-manager/class-roster.php"
```
- Open Roster for an event with confirmed seats → click "Send roster to organizer" → organizer (admin_email by default) receives a digest with correct counts (matches the header summary) and the confirmed attendee list; success notice shows. Click again → re-sends (intentional).
- Unauthorized user (no roster cap) → POST blocked (`wp_die`).
- WC-absent site (Env-A) → manual send works (uses `resolve_organizer_email`).
- Scheduled: set `organizer_roster_email` on, `roster_auto_offset` 1, event tomorrow → `wp cron event run anchor_events_reminder_sweep` → organizer digest sent; `wp post meta get <event_id> _anchor_event_roster_sent` set; re-run → not re-sent.

- [ ] **Step 6: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php anchor-events-manager/class-roster.php anchor-events-manager/class-woocommerce.php
git commit -m "feat(events): organizer roster digest — manual button + scheduled auto-send

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

**Acceptance:** Manual button produces an accurate confirmed-attendee digest, cap+nonce protected, works WC-absent; scheduled auto-send fires once per event before the event (idempotent via `_anchor_event_roster_sent`), date-move-safe; both rely on the shared organizer-recipient resolution.

---

## Task 5: Version bump + changelog + token docs

**Files:**
- Modify: `anchor-tools.php` (Version header)
- Modify: `ADDING-MODULES.md` or an events README if one documents email tokens (add the §9 token table). If none exists, add a short `anchor-events-manager/EMAILS.md`.

- [ ] **Step 1: Bump the plugin version** in `anchor-tools.php` header (next patch/minor per release convention).
- [ ] **Step 2: Document the token set** (spec §9 table) and the new settings in a short `anchor-events-manager/EMAILS.md` (reminder/cancellation/roster behavior, offsets, opt-in defaults, the `anchor_events_should_send_reminder` filter).
- [ ] **Step 3: Commit**

```bash
git add anchor-tools.php anchor-events-manager/EMAILS.md
git commit -m "docs(events): version bump + email token/settings documentation

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

**Acceptance:** Version bumped; tokens + new settings documented.

---

## Self-review notes (spec coverage)
- Decision 1 (mechanism) → Task 3 Step 1-2. Decision 2 (count/lead times/defaults) → Task 1 settings + Task 3 `effective_offsets`. Decision 3 (idempotency) → markers in Tasks 2/3/4. Decision 4 (recipients/opt-out) → Task 1 settings + Task 3 filter + defaults. Decision 5 (free/paid parity) → seat-driven sends in all tasks, WC guards. Decision 6 (cancellation trigger) → Task 2. Decision 7 (roster manual+scheduled) → Task 4. Decision 8 (template scope) → Task 1 `email_tokens` + subject/intro settings.
- Acceptance matrix (spec §13) is exercised by the per-task manual verification steps.
- Cross-task type consistency: `email_tokens($ctx)`, `resolve_organizer_email($event_id,$settings)`, `send_cancellation_email($seat_id)`, `send_reminder_email($seat,$event_id,$offset)`, `send_roster_email($event_id)`, `maybe_send_scheduled_roster($event_id,$meta,$settings,$now)`, action `anchor_events_seat_status_changed`, cron `anchor_events_reminder_sweep`, markers `_anchor_event_reminders_sent` / `_anchor_event_cancel_emailed` / `roster_sent` — used consistently across tasks.
- Known verify-before-implement points flagged inline: `$this->registrations`/`$this->roster` property names; `get_seat_info()` return keys; `query_seats()` `per_page = -1` handling (fix included in Task 3 Step 2).
