# Anchor Events — Email Gap (Lifecycle Notifications) Design Specification

Version 1.1. Module: `anchor-events-manager`. Namespace `Anchor\Events`. This spec covers the **lifecycle/notification** emails deferred from the WooCommerce-events MVP (the "email gap"). It builds directly on the shipped, merged system documented in `2026-06-19-woocommerce-events-design.md` and reviewed in `2026-06-20-wc-events-review-findings.md`. It does **not** alter the shipped transactional emails (buyer confirmation, organizer notice, seats-released organizer notice) except to share their helpers.

---

## 1. Overview & Goals

The MVP ships **transactional** emails only: a one-per-order buyer confirmation, a per-event organizer "new registration" notice, and a per-event organizer "seats released" notice. The review (§3 Feature Inventory) confirms three deferred lifecycle emails plus a half-built template system:

1. **Pre-event reminders** to confirmed attendees ("your event is in N days").
2. **Attendee-facing cancellation / refund email** (today only the *organizer* is told seats were released; the attendee gets nothing).
3. **Final roster digest to the organizer** — on demand (manual button) and optionally auto-sent before the event.
4. **A rounded-out, documented placeholder/template system** spanning every event email.

Design goals:
- **Free + paid parity.** Every new email keys off the **seat/event data layer**, never off orders. They work identically on a WooCommerce-absent site (free seats) and a WooCommerce site (paid seats).
- **Reuse, don't reinvent.** All sends go through `Module::send_html_email()` (failure logging) and `Module::build_registration_email_html($ctx)` (the HTML shell). All token substitution goes through `Module::expand_email_tokens()`.
- **Idempotent everywhere.** Cron overlaps, re-syncs, reconcile re-fires, and event-detail edits never double-send. Each new email has an explicit per-seat or per-event marker.
- **Cron-upgrade-safe.** The reminder/roster scheduler is registered defensively on `init` behind a `wp_next_scheduled()` guard — modeled exactly on the existing `anchor_events_status_sweep` — because plugin **updates** do not fire `register_activation_hook` on this plugin.
- **Conservative by default.** Outbound reminders default **off** (opt-in) so upgrading an existing site never surprises its attendees with a sudden batch of emails.
- **No new HTML shells, no PII in logs, all output escaped, i18n via `anchor-schema`.**

---

## 2. Scope

### In scope (build now)
- Pre-event reminder email(s) to confirmed/active attendees, configurable offsets, free + paid.
- Attendee-facing cancellation/refund email, fired on the actual seat transition into `cancelled`/`refunded`, covering every cancel path (paid order cancel/refund, manual roster cancel, order trash/delete, free).
- Manual "Send roster" button (organizer digest) on the roster screen + order panel.
- Optional scheduled roster auto-send before the event.
- A consistent, documented placeholder set across all event emails, with per-email-type **subject + intro** template settings (bodies stay code-built).

### Out of scope (stay deferred — design must not preclude)
- Waitlist auto-promotion; check-in/QR; certificates; attendee transfer.
- Per-recipient unsubscribe link / preference center (decision-4 below recommends honoring the site-level toggle instead; a `filter` seam is provided for sites that need finer control).
- ICS / add-to-calendar attachments (noted as an option on reminders in §6.4, not required).
- A full WYSIWYG body editor (decision-8 keeps bodies code-built).

---

## 3. Existing system this builds on

| Capability | Where | How this spec uses it |
|---|---|---|
| Send + failure logging | `Module::send_html_email($to,$subject,$html,$headers=[]):bool` (anchor-events-manager.php:225) + `wp_mail_failed` → `Events_Log::error` | Every new send. Untouched. |
| HTML shell builder | `Module::build_registration_email_html($ctx)` (anchor-events-manager.php:3428); `$ctx` = `event_id,name,status,intro_message,guests,detail_rows[],seat_list[],cta_label,cta_url`; renders the virtual "Join the event" button for confirmed virtual events; filter `anchor_events_registration_email_html` | Every new email builds a `$ctx` and calls this. Reminders inherit the join-link rendering for free (it already emits `virtual_url` for non-waitlist seats). |
| Token expansion | `Module::expand_email_tokens($template,$tokens)` (anchor-events-manager.php:3399) | All subject/intro templates. Extended token set in §9. |
| Status setter (single writer) | `Registrations::update_status($seat_id,$to,$note,$actor)` (class-registrations.php:145) — the **only** code that writes `_anchor_event_reg_status`; no-ops same-status; appends history | The cancellation-email trigger hooks here (§7) so it fires on the real transition, once, for every path. |
| Per-event lock | `Registrations::with_event_lock()` / `claim_seats()` | Cancellation send is **deferred out of the lock** (§7.3). |
| Seat queries | `Registrations::query_seats($args)` (class-registrations.php:745), `get_event_summary()` (879), `count_reserved_seats()` (237) | Reminder recipient query + roster digest. |
| Paid email dispatch | `WooCommerce::dispatch_emails()` (class-woocommerce.php:2096), `collect_order_seats()`, `send_organizer_notice()` `released` kind | Untouched (organizer-facing). The attendee cancellation email is a **separate** trigger so it also covers free seats. |
| Organizer recipient resolution | `WooCommerce::organizer_recipient()` (class-woocommerce.php:2405): per-event `_anchor_event_organizer_email` → global `organizer_email` → `admin_email` | Roster digest reuses the same precedence; promoted to a shared helper so the free-path roster works without the WC class (§8.2). |
| Cron precedent | `maybe_schedule_status_sweep()` on `init` behind `wp_next_scheduled()`; `run_status_sweep()` self-unschedules when CPT absent; `on_deactivate()` clears it (anchor-events-manager.php:100-203) | The reminder/roster sweep is a second cron modeled on this exactly. |
| Timezone | `start_ts`/`end_ts` are **already timezone-resolved Unix timestamps** (computed by `calculate_timestamps()` at save, honoring site/event tz mode) | Reminder/roster windows compare `start_ts` against `time()` directly — timezone-correct with no extra math. |
| Reserved markers | seat meta `_anchor_event_attendee_notified` (registered, boolean, anchor-events-manager.php:563); setting `notify_attendee` (reserved, get_settings:3633) | Reused/extended per §4. |

---

## 4. Data-model additions (markers & settings)

### 4.1 New seat meta (REG_CPT `anchor_event_reg`), registered in `register_meta()`
| Key | Type | Default | Written by | Purpose |
|---|---|---|---|---|
| `_anchor_event_reminders_sent` | array | `[]` | reminder sweep | Map `offset_days => sent_unix`. The authoritative per-offset idempotency marker. `show_in_rest=false`. |
| `_anchor_event_cancel_emailed` | boolean | `false` | cancellation flush | Set once when the attendee cancellation email is sent. Prevents re-send across reconcile passes / re-cancel. `show_in_rest=false`. |

`_anchor_event_attendee_notified` (already registered, reserved) is set to `true` the first time **any** reminder is sent for a seat — this honors the reserved-flag contract while `_anchor_event_reminders_sent` carries the per-offset detail. Both use the existing `$reg_auth_callback` (`current_user_can('edit_post',$post_id)`).

### 4.2 New event meta (added to `get_meta_schema()` + `get_meta_defaults()`)
| Key | Type | Default | Purpose |
|---|---|---|---|
| `reminder_offsets` → `_anchor_event_reminder_offsets` | string | `''` | Optional per-event override of the global offsets (CSV of whole days, e.g. `"14,3,1"`). Empty = use global setting. |
| `roster_sent` → `_anchor_event_roster_sent` | integer | `0` | Unix time the scheduled roster auto-send fired. Idempotency marker for the auto roster. `show_in_rest=false`. |

`reminder_offsets` is added to the event metabox UI and **to `save_meta()`'s allow-list** (unlike `linked_products`). `roster_sent` is internal (not surfaced, not in the allow-list — written only by the cron).

### 4.3 New settings (in `get_settings()` defaults, `register_settings()`, `sanitize_settings()`)
| Key | Default | Notes |
|---|---|---|
| `reminder_enabled` | `false` | Master toggle. **Off by default** (opt-in; see decision 2/4). |
| `reminder_offsets` | `'7,1'` | Global default offsets, CSV of whole days before `start_ts`. Sanitized to a sorted, de-duped, descending list of positive ints (cap the count, e.g. ≤5). |
| `reminder_subject` | `__('Reminder: {event_title} is coming up','anchor-schema')` | Token-expanded. |
| `reminder_intro` | `__('This is a friendly reminder that you are registered for {event_title} on {event_date}. We look forward to seeing you.','anchor-schema')` | Token-expanded. |
| `notify_cancellation` | `true` | Attendee-facing cancellation/refund email toggle. **Not** WC-only — applies to free + paid (decision 4). |
| `cancellation_subject` | `__('Your registration for {event_title} has been cancelled','anchor-schema')` | Token-expanded; reworded to "refunded" at send time when the seat status is `refunded`. |
| `cancellation_intro` | `__('Your registration for {event_title} has been cancelled. If this is unexpected, please contact us.','anchor-schema')` | Token-expanded. |
| `organizer_roster_email` | `false` | Enables the scheduled roster auto-send. |
| `roster_auto_offset` | `1` | Whole days before `start_ts` to auto-send the roster (used only when `organizer_roster_email` is on). |
| `roster_subject` | `__('Final roster for {event_title}','anchor-schema')` | Token-expanded. |
| `roster_intro` | `__('Here is the current confirmed roster for {event_title} on {event_date}.','anchor-schema')` | Token-expanded. |

The existing reserved `notify_attendee` setting stays reserved (per-attendee *registration* emails remain deferred); it is **not** the cancellation toggle. Reminder/cancellation settings render in the Events tab on **all** sites (they are not WC-gated); only the existing `wc_*` subsection stays behind `class_exists('WooCommerce')`.

---

## 5. Scheduling design (reminders + scheduled roster)

### Decision 1 — mechanism: one recurring cron, hourly, window-scoped query (NOT per-seat single events)
A single recurring cron hook `anchor_events_reminder_sweep` on the **`hourly`** schedule drives both reminders and the scheduled roster. Rationale for hourly over daily: reminder lead times are time-sensitive ("in 1 day"); an hourly sweep bounds delivery lateness to ≤1 hour while the tightly-scoped `start_ts` window query keeps each run cheap (only events near their start are scanned, most hours nothing is due). Per-seat `wp_schedule_single_event` is rejected — it is fragile across event reschedules, seat edits, and offset changes, and leaves orphaned events.

### Registration (mirrors `anchor_events_status_sweep` exactly)
```php
\add_action( 'init', [ $this, 'maybe_schedule_reminder_sweep' ] );   // defensive, survives upgrades
\add_action( 'anchor_events_reminder_sweep', [ $this, 'run_reminder_sweep' ] );
// maybe_schedule_reminder_sweep(): wp_schedule_event(time()+HOUR, 'hourly', hook) if !wp_next_scheduled(hook)
// on_deactivate(): also unschedule + wp_clear_scheduled_hook this hook
// run_reminder_sweep(): if ! post_type_exists(CPT) { unschedule; return; }  // self-heal like the status sweep
```
`on_deactivate()` (anchor-events-manager.php:134) is extended to clear this hook too. `run_reminder_sweep()` self-unschedules when the CPT is absent (module toggled off), exactly like `run_status_sweep()`.

### 5.1 Reminder pass
For each enabled offset `D` (from per-event override else global `reminder_offsets`), an event is **due for D** when:
```
start_ts - D*DAY_IN_SECONDS <= now  AND  now < start_ts
```
Pass algorithm (single sweep run):
1. If `reminder_enabled` is off → skip the reminder pass entirely.
2. Query candidate events: published `CPT` posts with `registration` not closed-irrelevant, whose `start_ts` is in `(now, now + max_global_offset*DAY]` (a `meta_query` on `start_ts` with `'type'=>'NUMERIC'`, mirroring the status-sweep query shape). This bounds the scan to imminent events only.
3. For each candidate event, resolve its effective offsets (per-event override CSV if non-empty, else global). For each offset `D` that is currently *due* (window test above):
   - Load that event's **active** seats via `Registrations::query_seats(['event_id'=>$id,'status'=>'active', 'per_page'=>-1])` — `active` = `RESERVING_STATUSES` (confirmed + pending). **Waitlist/cancelled/refunded/failed are excluded.** (Decision: pending seats *are* reminded — they hold a real reservation; sites that don't want pending reminders are WC on-hold edge cases, negligible.) Actually restrict to `confirmed` only to avoid reminding un-paid on-hold holds — see note below.
   - For each seat whose `_anchor_event_reminders_sent` lacks key `D`: build + send the reminder (§6.1), then set `_anchor_event_reminders_sent[D]=now` and `_anchor_event_attendee_notified=true`.

> **Active vs confirmed for reminders.** Reminders go to **`confirmed`** seats only. `pending` (WC on-hold, unpaid) seats are excluded — reminding someone who hasn't paid is wrong. Implementation queries `status=confirmed`. (The roster digest uses the same confirmed set.)

### Decision 3 (idempotency) — reminders
The per-offset key in `_anchor_event_reminders_sent` makes every offset fire at most once per seat, regardless of cron overlap, manual re-run, or event-detail edits. Two overlapping sweeps both reading "D not sent" is the only race; it is acceptable in practice (WP-Cron is effectively single-flight per request and the worst case is a rare duplicate of one reminder). If stricter guarantees are wanted later, the sweep can take a short `with_event_lock($event_id)` around each event's marker writes — noted as an optional hardening, not required for MVP.

### Decision (event date moved) — reminders
Because dueness is computed live from current `start_ts` and the marker is per-offset:
- Date moves **later** out of a window → that offset simply isn't due yet; it fires when the new date re-enters the window (unless already sent — markers are not reset, so an already-sent offset never re-fires; this is the intended "don't re-spam" behavior).
- Date moves **earlier** into a tighter window → any not-yet-sent tighter offset fires on the next sweep; already-sent looser offsets are not re-sent.
- An event moved into the past (`now >= start_ts`) is never due (the `now < start_ts` guard) → no reminders for events that already started.

### 5.2 Scheduled roster pass (decision 7, auto half)
If `organizer_roster_email` is on: in the same sweep, an event is due for the auto-roster when `start_ts - roster_auto_offset*DAY <= now < start_ts` **and** `_anchor_event_roster_sent == 0`. On send, set `_anchor_event_roster_sent = now`. The marker makes it once-only and date-move-safe (same logic as reminders).

---

## 6. Per-email designs

All emails build a `$ctx` and call `Module::build_registration_email_html($ctx)` then `Module::send_html_email()`. Subjects/intros come from settings, token-expanded via §9.

### 6.1 Reminder email
- **Recipient:** seat `_anchor_event_email` (one email per confirmed seat).
- **Subject/intro:** `reminder_subject` / `reminder_intro`, token-expanded with the seat+event tokens (incl. `{attendee_name}`, `{event_date}`, `{days_until}`, `{join_link}`, `{remaining}`).
- **Body:** `$ctx` with `event_id`, `name`=attendee, `status`='confirmed', `intro_message`=expanded intro, `detail_rows`=[Date, Time, Venue/Location or "Online", Address when physical], `cta_label`='View event details', `cta_url`=permalink. For a **confirmed virtual** event the builder already emits the "Join the event" button from `virtual_url` (no extra work; this is the correct, gated way to deliver the join link — consistent with the H1 fix that removed it from the public page).
- **Optional ICS:** not built; if added later, attach via the `$headers`/attachments path — noted, not required.

### 6.2 Attendee cancellation / refund email (decision 6 trigger in §7)
- **Recipient:** seat `_anchor_event_email`.
- **Gate:** `notify_cancellation` on; seat `_anchor_event_cancel_emailed` not yet set; the transition was from a "live" status (`confirmed` or `waitlist`) into `cancelled`/`refunded` (see §7.2 for which transitions qualify).
- **Wording:** `cancellation_subject`/`cancellation_intro`; when the new status is `refunded`, swap "cancelled" → "refunded" in the default copy (a `{status}` token also lets admins template it).
- **Body:** `$ctx` with `status`=the new status (so the builder suppresses the join link), `detail_rows`=[Event, Date, Order # when `_anchor_event_order_id`>0], no CTA button (or a "Contact us" mailto). On success set `_anchor_event_cancel_emailed=true`.
- **Order trash/delete:** §7.8 of the MVP transitions non-terminal seats to `cancelled` via `update_status` → this email fires through the same trigger, so trashing an order still notifies attendees.

### 6.3 Organizer roster digest (decision 7)
- **Recipient:** `organizer_recipient($event_id)` (shared helper — §8.2): per-event `_anchor_event_organizer_email` → global `organizer_email` → `admin_email`.
- **Subject/intro:** `roster_subject`/`roster_intro`, token-expanded (event tokens; no per-attendee tokens).
- **Body:** `$ctx` with `detail_rows` = [Date, Venue, Capacity, Confirmed, Pending, Waitlist, Remaining] from `Registrations::get_event_summary()`; `seat_list` = the confirmed attendees as `"Name — email — phone (source)"` lines (built from `query_seats(status=confirmed, per_page=-1)`). CTA = "Open full roster" → `Roster::roster_url($event_id)`. Counts and list come from the live data layer, so they are always accurate at send time.
- **Manual button:** see §8.

### 6.4 Free vs paid parity (decision 5)
Reminder, cancellation, and roster emails all read **seats** (`_anchor_event_*` post meta) and **events**, never orders. Order-specific tokens (`{order_number}`, `{order_url}`) resolve to empty for free seats (guarded by `_anchor_event_order_id > 0` and `function_exists('wc_get_order')`). With WooCommerce absent the WC class never loads, but none of these emails depend on it — they are driven by the always-loaded `Module` + `Registrations`.

---

## 7. Cancellation-email triggering (decision 6, detailed)

### 7.1 Single choke point
`Registrations::update_status()` is the **only** writer of `_anchor_event_reg_status` (verified). Add a fire at the end of a successful real transition (after meta + history writes, before return at class-registrations.php:196):
```php
\do_action( 'anchor_events_seat_status_changed', $seat_id, $from, $to, (string) $actor );
```
This single hook gives every cancellation path uniform coverage: paid order cancel/refund (reconcile → `update_status`), manual roster cancel (`Roster::handle_cancel` → `update_status`), order trash/delete (§7.8 → `update_status`), and free. It fires **only on an actual transition** (same-status calls no-op above it), so reconcile re-passes that don't change status never fire it.

### 7.2 Handler (always-loaded, on `Module`)
`Module::on_seat_status_changed($seat_id,$from,$to,$actor)`:
- Return unless `$to ∈ {cancelled, refunded}` and `$from ∈ {confirmed, waitlist}`. (Excludes `pending→cancelled` abandoned checkouts and `failed`/already-terminal transitions — an attendee who never confirmed/held a live seat gets no "cancelled" mail.)
- Return if `notify_cancellation` is off, or `_anchor_event_cancel_emailed` already set.
- **Enqueue** the seat id into a per-request static `$pending_cancellation_emails` (do **not** send here — see 7.3).

### 7.3 Deferred send (out of the lock)
`update_status()` runs **inside `with_event_lock`** during reconcile/claim. Sending `wp_mail` while holding a MySQL `GET_LOCK` is unacceptable (slow SMTP would serialize all capacity ops for that event). Therefore the handler only enqueues; the actual send is flushed:
- on the `shutdown` action (registered once), and
- explicitly at the end of `reconcile_order()` after the lock releases, for promptness (optional; shutdown covers correctness).

`Module::flush_cancellation_emails()` iterates the queue, re-checks `_anchor_event_cancel_emailed` (skip if set), builds + sends (§6.2), and sets the marker on success. The marker guarantees once-only even if both flush points run. This keeps every send outside any lock and collapses a multi-seat refund (many `update_status` calls in one locked pass) into a clean post-lock batch.

### 7.4 Why not extend `dispatch_emails()` for this
`dispatch_emails()` is WC-only and order-driven; it already sends the **organizer** "seats released" notice and is left untouched. Routing the **attendee** cancellation through the data-layer transition hook instead is what delivers free-path parity and covers manual roster cancels and trashed orders that `dispatch_emails` would miss.

---

## 8. Manual "Send roster" button (decision 7, manual half)

- **Location:** Roster screen header (a "Send roster to organizer" form button next to the existing summary), and the WC order panel "Event Registrations" metabox (per linked event). 
- **Action:** `admin_post_anchor_events_send_roster`; capability `Roster::current_user_can_manage()` (= `Roster::CAP`, the WC-aware cap when WC is active per M2); nonce `anchor_events_send_roster_{event_id}` via `check_admin_referer`; `event_id` validated to `CPT`.
- **Behavior:** builds + sends the §6.3 digest to `organizer_recipient($event_id)`; redirects back to the roster with a success/failure notice. No idempotency marker (it is an explicit, repeatable admin action); failures land in the error log via `send_html_email`.
- **Cap note:** this surfaces attendee PII to the organizer, so it sits behind the same roster/export capability hardened in review M2 (`manage_woocommerce`/`edit_shop_orders` when WC active; `edit_others_posts` otherwise) via `Roster::current_user_can_manage()`.

### 8.2 Shared organizer-recipient helper
`organizer_recipient()` currently lives on the WC class (class-woocommerce.php:2405) but the roster digest must work with WC absent. Promote the resolution to a shared method `Module::resolve_organizer_email($event_id, $settings=null)` (per-event meta → global setting → `admin_email`); the WC class delegates to it so there is one implementation.

---

## 9. Template & placeholder system (decision 8)

### Decision 8 — per-email subject + intro templates; code-built bodies
Add subject + intro template settings per email type (§4.3). **Do not** add full-body editors — bodies stay in `build_registration_email_html()` so escaping, the responsive shell, and the gated join-link logic stay centralized and consistent. This matches the existing `wc_customer_subject`/`wc_customer_intro` pattern and is the maintainable middle ground the brief asked for.

### Central token builder
Add `Module::email_tokens(array $ctx): array` producing the documented, consistent token set from an event (+ optional seat + optional order). All call sites (existing confirmation/organizer + new reminder/cancellation/roster) build their subject/intro through `expand_email_tokens($template, Module::email_tokens($ctx))`, so the available tokens are uniform and documented in one place.

### Documented token set
| Token | Source | Empty when |
|---|---|---|
| `{event_title}` | `get_the_title(event_id)` | — |
| `{event_url}` | `get_permalink(event_id)` | — |
| `{event_date}` | localized date from `start_ts` (site format) | no start date |
| `{event_time}` | localized time from `start_ts` | all-day / no time |
| `{venue}` | event `venue` (or "Online" when virtual) | — |
| `{days_until}` | `ceil((start_ts-now)/DAY)` | past events |
| `{attendee_name}` | seat name | non-seat context (organizer/roster) |
| `{join_link}` | event `virtual_url` for confirmed virtual events | not virtual / not confirmed |
| `{remaining}` | `get_event_summary()['remaining']` ("unlimited" when cap 0) | — |
| `{seat_count}` | confirmed count (roster) / seats in order (confirmation) | — |
| `{order_number}` | `$order->get_order_number()` | free seat / WC absent |
| `{order_url}` | customer order URL | free seat / WC absent |
| `{status}` | seat status | — |
| `{site_name}` | `get_bloginfo('name')` | — |

The settings UI lists the available tokens per field. `expand_email_tokens` is unchanged (it already does keyed `{token}` replacement); only the supplied token array grows.

---

## 10. Recipients, opt-out & WC-optional (decision 4)

- **Reminder:** attendee email; gated by `reminder_enabled`. Default **off** (opt-in) so upgrades never auto-blast existing attendees.
- **Cancellation:** attendee email; gated by `notify_cancellation` (default on). Applies to free + paid (generic name, not `wc_*`).
- **Roster:** organizer email; manual button always available, scheduled send gated by `organizer_roster_email` (default off).

**Opt-out stance.** Confirmation, organizer, cancellation, and roster emails are transactional/operational and need no unsubscribe. Reminders are borderline; for MVP the **site-level toggle is the opt-out** and no per-recipient unsubscribe link is built (these go only to people who registered for a specific event; volume is low and bounded). A filter `apply_filters('anchor_events_should_send_reminder', true, $seat_dto, $offset)` lets a site suppress per-recipient (e.g., honor a CRM preference) without core changes. This is the documented recommendation; a full preference center stays out of scope.

**WC-optional / HPOS.** None of the new emails require WooCommerce. Order tokens and order links are guarded by `_anchor_event_order_id > 0` and `function_exists('wc_get_order')`; any order read is via CRUD only (HPOS-safe). Reminder/cancellation/roster settings and the cron live on the always-loaded `Module`/`Registrations`, so a WC-absent site gets the full free-path feature set.

---

## 11. Idempotency summary (decision 3, consolidated)
| Email | Marker | Scope | Re-fire safety |
|---|---|---|---|
| Reminder | seat `_anchor_event_reminders_sent[D]` | per seat, per offset | Cron overlap / manual re-run / event edit never re-send a sent offset. |
| Cancellation | seat `_anchor_event_cancel_emailed` | per seat | Reconcile re-passes, re-cancel, trash-after-cancel never re-send. Fired only on real `live→cancelled/refunded` transition. |
| Scheduled roster | event `_anchor_event_roster_sent` | per event | Once per event; date-move-safe. |
| Manual roster | none (explicit admin action) | per click | Intentionally repeatable. |
| (existing) confirmation/organizer | order `_anchor_event_emails_sent` keys | per order/event | Unchanged. |

---

## 12. Constraints (non-negotiable, inherited)
- WooCommerce-optional; HPOS-safe (CRUD-only order reads); no PII in logs (Events_Log redaction unchanged).
- i18n text domain `anchor-schema`; all strings translatable with proper placeholders (`%d`/`%s`, `_n()` for counts).
- Cron registered defensively on `init` (upgrade-safe), self-unscheduling when CPT absent, cleared on deactivate.
- Reuse `send_html_email` + `build_registration_email_html`; no new HTML shells.
- Escape all output; caps + nonces on the manual roster action and any new admin field saves.
- The shipped transactional emails (buyer confirmation, organizer new-registration, organizer seats-released) keep working unchanged; the attendee cancellation email is additive and independent.
- No `wp_mail`/SMTP send while holding `with_event_lock` (§7.3).

---

## 13. Acceptance criteria / test matrix
Env-A = WordPress without WooCommerce. Env-B = WordPress + WooCommerce (HPOS on and off).

### Reminders
1. **A** Free confirmed seat, offset 1, event tomorrow → exactly one reminder; `_anchor_event_reminders_sent[1]` set; a second sweep within the hour sends nothing.
2. **B** Paid confirmed seat → reminder sent (free + paid parity); content includes event date and (if virtual) the join button.
3. Two offsets `7,1` → two reminders total per seat, on the right days; neither re-sends.
4. `reminder_enabled` off → no reminders ever (the global default; opt-in respected).
5. Cancelled / refunded / waitlist / `pending`(on-hold) seat → **no** reminder.
6. Event timezone respected (start_ts is tz-resolved) — a reminder for an event at 9am local fires relative to local start, not UTC.
7. **Date moved later** out of the 7-day window → 7-day reminder not yet sent; fires when re-entering (and never twice). **Date moved earlier** into the 1-day window → 1-day reminder fires next sweep; already-sent 7-day not re-sent.
8. Event already started (`now>=start_ts`) → no reminder.
9. Cron overlap (two sweeps) → no double reminder (marker).
10. `anchor_events_should_send_reminder` filter returning false for a seat → that seat skipped, marker still not set (so a later true allows it).

### Cancellation / refund
11. **B** Paid seat order cancelled → attendee gets exactly one cancellation email; organizer still gets the existing "seats released" notice; `_anchor_event_cancel_emailed` set.
12. **B** Partial refund (1 of 3) → the one refunded attendee gets a refund-worded email; the other two get nothing.
13. **A/B** Manual roster cancel of a confirmed seat → attendee cancellation email fires (free-path parity).
14. **B** Order trashed/deleted with active seats → each attendee gets a cancellation email (via §7.8 → update_status trigger).
15. No-op reconcile pass (status unchanged) → no cancellation email.
16. `pending→cancelled` (abandoned checkout) → no attendee email.
17. `notify_cancellation` off → no attendee cancellation email (organizer notice unaffected).
18. Re-cancel / reconcile re-fire → not re-sent (marker).
19. No `wp_mail` is invoked while the event lock is held (sends occur on flush after lock release / shutdown).

### Roster
20. Manual "Send roster" → organizer gets an accurate confirmed-attendee digest with correct counts/remaining; cap + nonce enforced; clicking again re-sends (intentional).
21. Manual button works on a WC-absent site (free seats), using the shared organizer-recipient resolution.
22. Scheduled roster (`organizer_roster_email` on, offset 1) → sent once the day before; `_anchor_event_roster_sent` set; not re-sent; date-move-safe.
23. Unauthorized user (no roster cap) → manual send blocked.

### Templates / cross-cutting
24. All subjects/intros expand the documented tokens; order tokens empty on free seats; `{join_link}` only for confirmed virtual.
25. Every send failure lands in the events error log (via `send_html_email`); no PII in the log.
26. All new strings translatable; all output escaped; WC-absent site sends reminder/cancellation/roster correctly.

---

## 14. Deliverables traceability (brief §4 decisions → spec)
1. Scheduling mechanism → §5 (decision 1): one hourly cron, window-scoped query, per-offset marker, tz via start_ts, date-move handled.
2. How many reminders + lead times → §4.3 + §5: global `reminder_offsets="7,1"`, per-event override, default off.
3. Idempotency for every email → §11 + §7.3.
4. Recipient resolution + opt-out → §10: per-email recipients, toggles, site-level opt-out + filter seam, no unsubscribe link (recommended).
5. Free vs paid parity → §6.4, §7, §8.2: seat/event-driven, WC-optional.
6. Cancellation triggering → §7: `update_status` action → deferred, out-of-lock, once-only flush; covers all paths.
7. Send roster manual + scheduled → §6.3, §8, §5.2.
8. Template/placeholder scope → §9: subject+intro templates, code-built bodies, central token builder + documented set.

This specification is complete and internally consistent; the implementation plan follows.
