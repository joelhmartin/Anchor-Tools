# Anchor Events Manager — Rework Design Spec

**Date:** 2026-07-15
**Branch:** `feature/events-rework` (off `origin/main`)
**Module:** `anchor-events-manager/` — `Anchor\Events\Module`, `CPT='event'`, `REG_CPT='anchor_event_reg'`
**Status:** Design approved (occurrence model + full six-phase scope). Spec → plan next.

---

## 1. Goal

Make the Events Manager a first-class, **client-agnostic** events system with:

1. An **event-type chooser** at the top of event setup: Single · Multi-session series · Pick-one offerings · Recurring schedule.
2. A first-class, generic **external-registration** field (link-out or embed) with an optional **display-only price** — no client-specific (JotForm/DEKA) language anywhere in the plugin.
3. **Email reminders / lifecycle emails** rescued from the stranded `feature/events-email-gap` branch and merged onto main.
4. A **per-event email backend**: editable HTML templates with a live-preview builder + token insertion, plus a read-only "upcoming sends" schedule.
5. **Event JSON-LD** structured data (net-new) so search engines see every real date.

Non-goals (YAGNI): per-session attendance tracking; full RFC 5545 RRULE; a per-send editable email queue (templates + schedule view only); reworking the working free-internal registration flow.

---

## 2. Ground-truth findings (from codebase survey)

These are the load-bearing facts the design is built on. File paths are under `anchor-events-manager/` unless noted.

**Occurrence coupling.** Every seat, capacity count, roster query, product, and (future) schema hangs off **one event post ID + one `start_date`/`end_date` pair**. The only existing sub-unit is the ticket *tier* (`_anchor_event_ticket_type_id`), a pricing/quota dimension, not a date dimension. A seat (`create_seat()`, `class-registrations.php:84-136`) links to its event **only** via `_anchor_event_id`; there is no occurrence key. Capacity is enforced event-wide (`counts()`/`remaining_capacity()`/`capacity_decision()`/`with_event_lock()`, `class-registrations.php:359/286/471/514`). Adding a true intra-post occurrence dimension is "the single biggest change" and is explicitly **rejected** (see §3).

**Series taxonomy already exists.** `class-series.php` registers `event_series` (non-hierarchical taxonomy on `event`) and renders an archive of separate event posts sharing a term, ordered by `_anchor_event_start_ts`. Its design comment: *"sessions are separate events grouped by a shared series term — never product variations."* This is the backbone for grouping.

**Two authoring paths exist and BOTH must be updated.** Admin metabox `render_meta_box()` (`:870-1067`) + `save_meta()` (`:1183-1258`, hook `save_post_event`), AND a front-end `render_event_manager_form()` (`:2100`) + its save (`:2289-2359`). Core meta via `meta_key()` = `'_anchor_event_' . $key`; schema `get_meta_schema()` (`:649-690`), defaults `get_meta_defaults()` (`:692-733`). Existing external-registration seeds already present: `_anchor_event_registration_type` (internal|external), `_anchor_event_registration_url`, `_anchor_event_price`.

**Product sync coupling.** Auto-managed product (`class-product-sync.php`): one event → one `WC_Product_Variable`, one variation per paid+active tier; variation axis is hard-wired to tier (`do_sync_event()` `:318-437`). Manual-link path (`class-woocommerce.php:39-40, 225-300`) already supports a **variation → distinct event** ("per-session") mapping — precedent, but not needed under the chosen model.

**Email system (branch `feature/events-email-gap`).** Complete but unmerged. Senders live in `anchor-events-manager.php` (+ paid confirmations in `class-woocommerce.php`). **Bodies are hardcoded PHP** — one shared shell `build_registration_email_html()` (branch `:4012`); only *subject + intro* are settings-driven and token-expanded. Token infra exists and is reused everywhere: `email_tokens($ctx)` (branch `:3935`) and `expand_email_tokens()` (branch `:3924`); both `build_registration_email_html()` and `email_tokens()` are `public` and side-effect-free. Reminder cron: hourly `anchor_events_reminder_sweep` (`maybe_schedule_reminder_sweep()` branch `:152`, `run_reminder_sweep()` branch `:265`); idempotency markers `_anchor_event_reminders_sent` (per seat, per offset), `_anchor_event_roster_sent` (per event), `_anchor_event_cancel_emailed` (per seat). Per-event offset override meta `reminder_offsets`. Lifecycle settings registered in the Events tab. **Latent bug:** emails sent without a `text/html` content-type header (`send_html_email()` branch `:502`).

**Merge assessment.** `git merge-tree origin/main origin/feature/events-email-gap`: `class-registrations.php`, `class-roster.php`, `class-woocommerce.php` **auto-merge cleanly**; only `anchor-events-manager.php` (and a trivial version-header line in `anchor-tools.php`) conflict, and the conflicts are additive ("accept both") in: class properties/constructor loader, constructor hook registrations, a ~252-line new-methods insertion block, `register_settings()`/`sanitize_settings()`, and the `get_settings()` defaults array. Effort: a few hours. Two semantic checks post-merge: (a) main's rewritten `Registrations::update_status()` still fires `do_action('anchor_events_seat_status_changed', …)` on **all** cancel/refund/trash paths (drives cancellation emails); (b) main didn't re-fork organizer-recipient logic away from the branch's centralized `resolve_organizer_email()`. Note: main already has configurable From/Reply-To/BCC (commit `e1ab500`) which the branch lacks — reconcile toward keeping main's.

**HTML-editor house pattern.** Shared, reusable, already merged to main: `Anchor_Monaco` (`includes/class-anchor-monaco.php`, `assets/anchor-monaco.js`) tabbed Monaco editor w/ CodeMirror/textarea fallback; `Anchor_Preview_CSS` (`includes/class-anchor-preview-css.php`, `assets/anchor-preview.js`) live-CSS harvester for preview iframes; `Anchor_Asset_Loader::url()`. Reference implementation to clone: **anchor-blocks** (`anchor-blocks/anchor-blocks.php:81-190`, `anchor-blocks/assets/admin.js`) — Monaco + `Anchor_Preview_CSS::enqueue_for_admin()` + client-side `iframe.srcdoc` preview debounced ~250ms, wired on `input` (Monaco dispatches synthetic `input` events so this "just works"). Enqueue gating everywhere: `admin_enqueue_scripts` + `$hook==='post.php'||'post-new.php'` + `$post->post_type===CPT`.

**Schema gap.** The module emits **no** Event JSON-LD today (grep-confirmed). The parent Anchor Schema plugin has a generic manual schema builder listing `Event` as a `@type` but does not auto-map the `event` CPT. Net-new work.

---

## 3. Core architectural decision — occurrence = event post

**Rule: one occurrence = one event post. Never subdivide a post.** A seat, capacity count, roster, and managed product always belong to exactly one event post, exactly as today. We reject threading an occurrence key through seats/capacity/tiers/product/roster/reconcile (high effort, high risk to shipped commerce code) in favor of reusing the existing per-event engine unchanged.

The four types map on as follows:

| Type | Storage | Registration engine impact |
|------|---------|----------------------------|
| **Single** | One event post (today's model) | none |
| **Multi-session series** — one signup, attendee attends ALL dates | One event post + a **repeatable session-date list** stored as meta (`_anchor_event_sessions`); the seat covers the whole series. Sessions are **display + schema only**. | **none** — capacity/roster/product unchanged |
| **Pick-one offerings** — register for ONE of several dates/locations | **N child event posts** in one group (shared `event_series` term + group parent meta); each child owns its own date/capacity/roster/managed product | none — reuses per-event engine per child |
| **Recurring schedule** — same event repeats on a rule | Same as Pick-one, but child posts are **generated from a recurrence rule** | none |

**New grouping subsystem (the only genuinely new engine work):** a *parent → children generator/sync*, modeled on the existing idempotent product-sync reconcile. A "group parent" event post holds shared content (title, description, location, tier template, email templates, external-reg settings) plus either an explicit offering-date list (Pick-one) or a recurrence rule (Recurring). On save it **reconciles child event posts** (create/update/soft-close), each linked back to the parent via meta and sharing the group's `event_series` term. Child posts inherit shared content and own their own date + capacity + seats + roster + product.

Side benefit: because each real date is its own event post, **Event JSON-LD is correct for free** — search engines see every occurrence, which resolves the original "Google only sees one date" problem.

### Data model additions (meta keys, all `_anchor_event_` prefixed)

- `type` — `single|multisession|offering|recurring` (the chooser value; drives conditional UI + save + render).
- `sessions` — array of `{date,start_time,end_time,label}` rows (multisession only; display/schema).
- `group_id` — parent event post ID for generated children (offering/recurring); absent on standalone/parent.
- `group_role` — `parent|child` for offering/recurring groups.
- `offering_dates` — parent-held list of offering date rows (Pick-one) used to reconcile children.
- `recurrence` — parent-held rule `{freq:weekly|monthly, interval, byday/bymonthday, until|count}` (Recurring); intentionally simple, not full RRULE.
- `registration_mode` — `wc|free|external` (registration axis; supersedes/absorbs the legacy `registration_type`).
- `external_url` — link-out target (external mode).
- `external_embed` — sanitized third-party embed markup/iframe (external mode).
- `external_display_price` — display-only price string; **pure label, no cart/seat accounting**.

All new keys added to `get_meta_schema()`, `get_meta_defaults()`, the `save_meta()` allow-list, and the front-end manager form + save. Generator-owned keys (`group_id`, `group_role`) and marker keys stay **out** of the user-editable allow-list so edits never clobber them (mirrors how `roster_sent`/managed-product keys are already excluded).

### Backward compatibility

Existing events have no `type` meta → treated as `single`; existing `registration_type` (internal|external) maps to `registration_mode` (free|external) via a read-time shim + a one-time migration. No data loss; single-event behavior is byte-for-byte unchanged.

---

## 4. Two independent axes

An event = **date pattern** (§3) × **registration mode**, orthogonal. The metabox shows only fields relevant to the current combination.

**Registration modes:**
- `wc` — existing tier/product/seat commerce system. Untouched.
- `free` — existing free internal registration. Untouched.
- `external` — NEW first-class field. Sub-choice *link-out* (`external_url`) **or** *embed* (`external_embed`, sanitized allow-list for iframe/script-of-known-providers via `wp_kses` with a filterable allowed-tags set). Optional `external_display_price` renders as a label ("$495") with zero commerce wiring. Free external embed already works today via the theme; this brings the field into the plugin generically and adds the paid-label case so a paid external event stops masquerading as free.

For grouped events, registration mode is a **shared** (parent) setting inherited by children.

---

## 5. Email backend

### 5.1 Rescue merge (Phase 0, foundation)

Reconcile `feature/events-email-gap` onto `feature/events-rework`. Only `anchor-events-manager.php` needs hand-merging (additive). Run the two semantic checks (§2). Fix the `text/html` content-type header bug. Keep main's From/Reply-To/BCC. Verify reminder cron schedules and the lifecycle settings render in the Events tab.

### 5.2 Editable templates

Today only subject + intro are editable; bodies are the hardcoded `build_registration_email_html()` shell. Introduce a **stored body template** per email type:

- **Global defaults** — per-type option in the Events settings tab (seeded from the current hardcoded shell so behavior is unchanged on upgrade).
- **Per-event override** — optional `_anchor_event_email_tpl_{type}` meta; when present, overrides the global default for that event.
- **Rendering** — refactor `build_registration_email_html()` to render from the resolved template string through the existing `expand_email_tokens()` + `email_tokens($ctx)` pipeline, instead of `ob_start()` markup. Preserve the `$ctx` contract and the `anchor_events_registration_email_html` filter seam.
- Email types covered: Confirmation (buyer/free), Reminder, Cancellation/refund, Roster digest.

### 5.3 The builder (clone anchor-blocks)

Per-event **Emails** metabox/panel on the `event` CPT:
- Tabs: Confirmation / Reminder / Cancellation / Roster digest.
- Each tab: `Anchor_Monaco` HTML editor (one `{"id":"...","label":"HTML","lang":"html"}` field) + a **token palette** (insert `{event_title}`, `{attendee_name}`, `{join_link}`, … from the documented set) + client-side `srcdoc` live preview via `Anchor_Preview_CSS` (blocks pattern), debounced ~250ms.
- An **"render with real tokens"** AJAX button: server calls the `public` `build_registration_email_html()`/`email_tokens()` for this event id (no send) and returns HTML — accurate merge-tag preview. Endpoint: `wp_ajax_anchor_events_email_preview`, `check_ajax_referer` + `current_user_can('manage_options')`.
- "Reset to global default" per tab.
- Enqueue gating per house convention.

### 5.4 Schedule view

Read-only "Upcoming sends" panel per event, computed (no new storage) from `start_ts`, effective reminder offsets, roster auto-offset, confirmed-seat counts, and the idempotency markers (`_anchor_event_reminders_sent`, `_anchor_event_roster_sent`). Shows each pending send: type, recipient scope + count, scheduled time, sent/pending state.

Grouped events need no email changes — each child is an event post, so emails and schedules resolve per date automatically.

---

## 6. Event JSON-LD (Phase 4)

New renderer emitting `Event` JSON-LD on single-event views (and optionally in `<head>` via the module), from `start_ts`/`end_ts`, location meta, featured image, permalink, and `offers` (tier price for `wc`, `external_display_price` for `external`, free otherwise). Multi-session emits one `Event` with `subEvent`/`eventSchedule` rows from `_anchor_event_sessions`. Offering/recurring children each emit their own `Event` node (correct multi-date coverage). Respect an `apply_filters` seam and avoid duplicating any schema the parent Anchor Schema plugin already emits for the post.

---

## 7. Phasing (drives the sub-agent plan)

| Phase | Deliverable | Depends on |
|------:|-------------|-----------|
| **0** | Rescue-merge `events-email-gap`; fix `text/html` header; verify cron + settings render | — |
| **1** | Type-chooser + registration-mode refactor (client-agnostic external field w/ embed + display price) + metabox reorg across **both** admin metabox and front-end manager form; Single + Multi-session (session-date list, display + schema-ready) + External. No seat surgery. BC shim + migration. | 0 (shared file) |
| **2** | Grouping engine: parent→child generator/sync for Pick-one + Recurring (series term + group meta, idempotent reconcile); unified "choose your date" front end + series archive polish | 1 |
| **3** | Email templates backend: stored templates (global default + per-event override), Monaco/preview builder, token palette, AJAX real-token preview, schedule view | 0, 1 |
| **4** | Event JSON-LD (single, multisession subEvent, per-child) with external display-price offers | 1, 2 |
| **5** | Client-agnostic string audit; docs (EVENTS-*.md, EMAILS.md refresh); version bump; merge to `main`; release | all |

Review gate between each phase. Each phase is a self-contained sub-agent work package with its own verification.

---

## 8. Risks & mitigations

- **Merge regressions in shipped commerce code (Phase 0).** Mitigation: rely on the clean auto-merge for the three commerce files; hand-merge only `anchor-events-manager.php`; run the two named semantic checks; manual smoke of free + paid registration + reminder cron before proceeding.
- **Generator drift / orphaned child posts (Phase 2).** Mitigation: idempotent reconcile keyed by group + occurrence identity (date), soft-close (not delete) children with existing seats when a date is removed; never regenerate a child that has registrations without preserving its roster.
- **Front-end form parity (Phase 1).** Two authoring paths must stay in lockstep; every new field/validation is implemented in both `render_meta_box`/`save_meta` and `render_event_manager_form`/its save. Verification checklist covers both.
- **Embed XSS (Phase 1).** External embed sanitized via a filterable `wp_kses` allowed-tags set; never stored raw-echoed without capability gate on save.
- **Release from a feature branch (Phase 5).** Merge to `main` first, then tag/release from `main` (documented lesson: never tag off a feature branch).

---

## 9. Open implementation details (resolve during planning)

- Group parent representation: dedicated parent event post (with `group_role=parent`, excluded from public single view) vs. a non-CPT options record. Leaning parent post to reuse metaboxes/authoring UI.
- Recurrence UI surface (simple builder: frequency + interval + until/count) — exact field set.
- Whether the per-event Emails UI is a metabox on the event edit screen or a dedicated sub-screen; leaning metabox for locality with the schedule view.
- Migration trigger for `registration_type`→`registration_mode` (on-load lazy shim vs. one-time sweep).
