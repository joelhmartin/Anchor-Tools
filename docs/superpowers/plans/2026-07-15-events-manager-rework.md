# Anchor Events Manager Rework — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn Anchor Events Manager into a client-agnostic events system with an event-type chooser (Single / Multi-session / Pick-one offerings / Recurring), a first-class external-registration field, rescued lifecycle emails with an editable HTML-template builder + schedule view, and Event JSON-LD.

**Architecture:** Occurrence = event post (never subdivide a post). Multi-session = one post + a repeatable session-date list (display/schema only). Pick-one/Recurring = generated child event posts grouped by the existing `event_series` taxonomy + a parent post, reconciled idempotently like the existing product-sync. The entire per-event seat/capacity/tier/roster/product engine is reused **unchanged**. Emails are rescued from `feature/events-email-gap` and their bodies moved from hardcoded PHP into stored, token-rendered, previewable templates.

**Tech Stack:** Raw PHP 7.4+ (no build step), jQuery IIFE, WordPress post-meta + CPT, WP-Cron, shared `Anchor_Monaco` + `Anchor_Preview_CSS` admin helpers, WooCommerce (optional, HPOS-safe). Spec: `docs/superpowers/specs/2026-07-15-events-manager-rework-design.md`.

## Global Constraints

Copied from `CLAUDE.md` + spec. Every task's requirements implicitly include this section.

- **No build tools.** Raw PHP/CSS/JS. No transpile/bundle. Edit `.js`/`.css` directly; regenerate `.min.*` only via the repo's existing release minifier (do not hand-edit `.min.*` / `.map`).
- **Test harness EXISTS — use it (TDD).** The events module has PHPUnit (`phpunit/phpunit ^9.6`, `phpunit.xml.dist`, `composer test`, WP test-lib via `bin/install-wp-tests.sh`, base `Anchor_Events_TestCase extends WP_UnitTestCase` with event/seat factories; existing suites `tests/test-capacity.php|test-product-sync.php|test-reconcile.php|test-status-transitions.php|test-ticket-types.php`) AND Playwright E2E on `@wordpress/env` (`.wp-env.json`, `bin/e2e-seed.sh`, `npm run env:seed`, `npm run test:e2e`, existing `e2e/purchase.spec.js`). New logic MUST ship with PHPUnit tests written test-first; new UI flows MUST ship with a Playwright spec. Environment is local/disposable — never test on staging or live.
- **Asset paths:** `Anchor_Asset_Loader::url('anchor-events-manager/assets/…')` (or `ANCHOR_TOOLS_PLUGIN_URL . 'anchor-events-manager/assets/'`). Never `plugin_dir_url(__FILE__)` inside the module.
- **Options:** always pass `autoload=false` as the 3rd arg to `update_option()`.
- **Text domain:** `'anchor-schema'` for all translatable strings.
- **Class naming:** namespaced `\Anchor\Events\…`.
- **AJAX actions:** prefix `anchor_events_`. Every handler: `check_ajax_referer(<action>, 'nonce')` then `current_user_can('manage_options')`.
- **Admin assets:** enqueue only on `post.php`/`post-new.php` gated by `$post->post_type === self::CPT` (or the specific settings tab action).
- **CPT/menu:** keep `apply_filters('anchor_events_parent_menu', true)` for `show_in_menu`.
- **JS:** jQuery IIFE `(function($){…})(jQuery);`. No ES modules.
- **Asset versions:** bump the version string in each `wp_enqueue_*` call when its asset changes.
- **Frontend render:** `ob_start()` / `return ob_get_clean()`.
- **Occurrence rule:** one occurrence = one event post. NO changes to seat keying, capacity locks, tier quota, product/variation axis, roster query base clause, or order reconcile. If a task seems to need them, stop — the design is wrong.
- **Meta discipline:** new user-editable meta goes in `get_meta_schema()`, `get_meta_defaults()`, the `save_meta()` allow-list, AND the front-end manager form + its save. Generator-owned (`group_id`, `group_role`) and marker meta stay OUT of the allow-list.
- **Two authoring paths in lockstep:** every field/validation change lands in BOTH `render_meta_box`/`save_meta` and `render_event_manager_form`/its save.
- **Release discipline:** merge to `main` first, then tag/release from `main`. Never tag from a feature branch.
- **Version bump:** `Version:` header in `anchor-tools.php` on release only (Phase 5).

## Verification Loop (used by every task)

The module has a real test harness (see Global Constraints). Verification is **tiered by task type**, applied in this order; each task ends by committing only once its tier's checks pass.

**One-time environment bootstrap (Task 0.0, before anything else):**
- `composer install` (installs PHPUnit + polyfills into `vendor/`).
- `bin/install-wp-tests.sh wordpress_test root '' localhost latest` (or set `WP_TESTS_DIR`) so `composer test` can boot the WP test library. Confirm `composer test` runs the existing suites green **before** writing new code — this is the baseline.
- `npm install` (installs `@wordpress/env` + Playwright), `npm run wp-env start`, `npm run env:seed` (Docker is running; Node 22 present). Confirm `npm run test:e2e` passes `e2e/purchase.spec.js` green — the E2E baseline.

**Per task:**
1. **Lint:** `php -l <each changed .php>` → `No syntax errors detected`.
2. **Logic tasks — PHPUnit, test-first (red→green):** add a test to `tests/` extending `Anchor_Events_TestCase` (use its event/seat factories); run `composer test -- --filter <TestName>` and watch it FAIL first, implement, then watch it PASS. Add the file to `phpunit.xml.dist`'s suite if needed. Applies to: resolvers/migration (1.2), reconcile (2.2), recurrence expansion (2.3), template resolve/render (3.1/3.2), schema build (4.1/4.2).
3. **UI/flow tasks — Playwright on wp-env:** extend `bin/e2e-seed.sh` with any needed fixture (idempotent), add/extend an `e2e/*.spec.js`, run `npm run env:seed && npm run test:e2e -- <spec>` green. Applies to: choosers/repeater/external render (1.3–1.6), choose-your-date (2.6), email builder preview (3.3–3.6).
4. **Static invariant check(s):** task-specific `rg` assertions listed in the task.
5. **Spot checks:** `npm run wp-env run cli wp eval '<php>'` for quick in-WP assertions (e.g. dump generated schema JSON, expand a recurrence rule).
6. **Commit** with the given message.

A subagent that cannot start Docker/wp-env marks E2E steps `PENDING-HUMAN` and the reviewer runs them at the phase gate; PHPUnit (which needs only `composer install` + the WP test-lib, no Docker) must still be run.

---

## Phase 0 — Rescue-merge the lifecycle-email branch (foundation)

**Outcome:** `feature/events-rework` contains all `feature/events-email-gap` functionality on top of the shipped WooCommerce events code, linting clean, with reminders/cancellation/roster-digest wired and the `text/html` header bug fixed.

**File structure (touched):**
- Modify: `anchor-events-manager/anchor-events-manager.php` (hand-merge)
- Auto-merged (verify only): `class-registrations.php`, `class-roster.php`, `class-woocommerce.php`
- New from branch: `anchor-events-manager/EMAILS.md`
- Trivial: `anchor-tools.php` version-header line (keep higher)

### Task 0.0: Bootstrap + baseline the test harness (before any code)

- [ ] **Step 1: PHP deps** — `composer install` → `vendor/bin/phpunit` present.
- [ ] **Step 2: WP test library** — `bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest` (adjust DB creds as needed) or export `WP_TESTS_DIR`. Ensure WooCommerce is available to the suite per `tests/bootstrap.php`'s path contract.
- [ ] **Step 3: PHPUnit baseline** — `composer test` → existing suites (capacity/product-sync/reconcile/status/tickets) pass. Record the pass count as the baseline.
- [ ] **Step 4: E2E env** — `npm install`; `npm run wp-env start`; `npm run env:seed` → writes `e2e/.seed.json`.
- [ ] **Step 5: E2E baseline** — `npm run test:e2e` → `e2e/purchase.spec.js` passes.
- [ ] **Step 6: Checkpoint** — no code changes; record both baselines in the phase-gate notes. If either baseline is red on the pristine branch, STOP and report before merging.

### Task 0.1: Perform the merge and resolve conflicts

**Interfaces produced:** the merged public API later phases consume — `Module::build_registration_email_html(array $ctx): string`, `Module::email_tokens(array $ctx): array`, `Module::expand_email_tokens(string $tpl, array $tokens): string`, `Module::send_html_email($to,$subject,$html,$headers='')`, hook `anchor_events_reminder_sweep`, filter `anchor_events_registration_email_html`, settings keys `reminder_enabled|reminder_offsets|reminder_subject|reminder_intro|notify_cancellation|cancellation_subject|cancellation_intro|organizer_email|organizer_roster_email|roster_auto_offset|roster_subject|roster_intro`.

- [ ] **Step 1: Start the merge**
  Run: `git merge --no-ff origin/feature/events-email-gap`
  Expected: conflicts in `anchor-events-manager/anchor-events-manager.php` and `anchor-tools.php`; the other three module files merge clean.

- [ ] **Step 2: Verify only the expected files conflict**
  Run: `git diff --name-only --diff-filter=U`
  Expected output exactly: `anchor-events-manager/anchor-events-manager.php` and `anchor-tools.php`. If any of `class-registrations.php`/`class-roster.php`/`class-woocommerce.php` appear, STOP and re-read the spec §2 merge assessment before proceeding.

- [ ] **Step 3: Resolve `anchor-tools.php`**
  Keep the higher version string; drop conflict markers. This file's only conflict is the `Version:` header line.

- [ ] **Step 4: Resolve `anchor-events-manager.php` — accept-both in each additive region**
  Resolve the five additive regions (spec §2): (a) class properties + constructor `require_once`/`new` loaders — keep main's `$product_sync/$ticket_types/$series` AND the branch's `$pending_cancellation_emails`; (b) constructor hook registrations — keep both sets; (c) the ~252-line reminder/roster/cancellation methods block AND main's ticket-tier methods; (d) `register_settings()`/`sanitize_settings()` — keep both sets of fields/sections; (e) `get_settings()` defaults array — union the keys. Remove every conflict marker.

- [ ] **Step 5: Lint**
  Run: `php -l anchor-events-manager/anchor-events-manager.php` → `No syntax errors detected`.

- [ ] **Step 6: Static invariant — every rescued symbol is present**
  Run: `rg -n "function build_registration_email_html|function email_tokens|function run_reminder_sweep|function send_reminder_email|function send_cancellation_email|function send_roster_email|anchor_events_reminder_sweep" anchor-events-manager/anchor-events-manager.php`
  Expected: each appears at least once.

- [ ] **Step 7: Commit the merge**
  ```bash
  git add -A
  git commit -m "merge(events): rescue lifecycle-email branch onto main-based events code"
  ```

### Task 0.2: Verify the two semantic integration points

- [ ] **Step 1: Confirm the seat-status hook fires on all transitions**
  Read `class-registrations.php` `update_status()` and confirm every path that moves a seat to cancelled/refunded/trashed calls `do_action('anchor_events_seat_status_changed', …)`.
  Run: `rg -n "anchor_events_seat_status_changed" anchor-events-manager/class-registrations.php`
  Expected: the `do_action` is present and reachable from cancel/refund/trash. If main's rewrite removed it from any path, add it back at the single `update_status()` choke point.

- [ ] **Step 2: Confirm organizer-recipient is centralized**
  Run: `rg -n "resolve_organizer_email|function organizer_recipient" anchor-events-manager/anchor-events-manager.php anchor-events-manager/class-woocommerce.php`
  Expected: `class-woocommerce.php` delegates to `Module::resolve_organizer_email()` rather than a re-forked resolver. If main re-forked it, repoint WC to `resolve_organizer_email()`.

- [ ] **Step 3: Commit any fixes**
  ```bash
  git add -A && git commit -m "fix(events): re-assert seat-status hook + centralized organizer recipient post-merge"
  ```
  (Skip if no changes were needed.)

### Task 0.3: Fix the `text/html` content-type header bug

- [ ] **Step 1: Locate the sender**
  Read `send_html_email()` in `anchor-events-manager.php`. It calls `wp_mail($to,$subject,$html,$headers)` with `$headers` defaulting empty — HTML bodies are sent without a `Content-Type: text/html` header.

- [ ] **Step 2: Add the header**
  In `send_html_email()`, ensure a `Content-Type: text/html; charset=UTF-8` header is always present (merge into `$headers` whether it arrives as string or array), without clobbering caller-supplied From/Reply-To/BCC headers.

- [ ] **Step 3: Lint**
  Run: `php -l anchor-events-manager/anchor-events-manager.php` → clean.

- [ ] **Step 4: Static check**
  Run: `rg -n "text/html" anchor-events-manager/anchor-events-manager.php` → the header appears in `send_html_email()`.

- [ ] **Step 5: Smoke (wp-env)**
  In the running wp-env, capture the header: `npm run wp-env run cli wp eval '$m=\Anchor\Events\Module::instance(); $h=""; add_filter("wp_mail",function($a)use(&$h){$h=$a["headers"];return $a;}); $m->send_html_email(get_option("admin_email"),"t","<b>hi</b>"); echo is_array($h)?implode("|",$h):$h;'` → output contains `Content-Type: text/html`. (Behavioral email delivery is covered by the reminder PHPUnit path; this asserts the header fix directly.)

- [ ] **Step 6: Commit**
  ```bash
  git add anchor-events-manager/anchor-events-manager.php
  git commit -m "fix(events): send lifecycle emails with text/html content-type header"
  ```

### Task 0.4: Confirm settings + cron surface

- [ ] **Step 1: Smoke the settings tab**
  In wp-env (`http://localhost:8888/wp-admin`), open Settings → Anchor Tools → Events. Expected: the "Lifecycle Emails" and (WC active) "WooCommerce Registration Emails" sections render with all keys from Task 0.1 Interfaces.

- [ ] **Step 2: Confirm cron scheduled**
  Run: `npm run wp-env run cli wp cron event list | grep anchor_events_reminder_sweep` → an hourly schedule exists.

- [ ] **Step 3: Commit doc**
  Ensure `EMAILS.md` came over from the branch (`git show HEAD:anchor-events-manager/EMAILS.md | head`). No code change; this is a checkpoint.

**Phase 0 review gate.** Reviewer confirms `composer test` + `npm run test:e2e` baselines green post-merge, both semantic checks pass, and the `text/html` header assertion passes. Do not start Phase 1 until merged state is green.

---

## Phase 1 — Event-type chooser + registration-mode refactor

**Outcome:** Event setup opens with a type chooser; the metabox and the front-end manager form adapt to it; Single + Multi-session (session-date list) + a client-agnostic External registration field (link/embed + display-only price) all work and save from both authoring paths. Zero seat surgery. Existing events behave identically (BC).

**File structure:**
- Modify: `anchor-events-manager/anchor-events-manager.php` — `get_meta_schema()`, `get_meta_defaults()`, `render_meta_box()`, `save_meta()`, `render_event_manager_form()` + its save, plus a read-time BC shim + one-time migration.
- Modify: `anchor-events-manager/assets/admin.js` — conditional field visibility keyed to the type chooser + registration-mode chooser; repeatable session-date rows.
- Modify: `anchor-events-manager/assets/admin.css` — chooser + session-row styles.
- Modify: `anchor-events-manager/assets/frontend.css` (+ `frontend.js` if session list needs behavior) — session list + external-embed/display-price rendering.
- Modify: `anchor-events-manager/templates/single-event.php` — render session list + external registration block + display price.

**Interfaces produced (consumed by Phases 2–4):**
- Meta keys: `_anchor_event_type` ∈ `single|multisession|offering|recurring`; `_anchor_event_sessions` = `array<{date,start_time,end_time,label}>`; `_anchor_event_registration_mode` ∈ `wc|free|external`; `_anchor_event_external_url`, `_anchor_event_external_embed`, `_anchor_event_external_display_price`.
- `Module::event_type(int $id): string` (resolves with BC default `single`).
- `Module::registration_mode(int $id): string` (resolves with BC shim from legacy `registration_type`).
- `Module::get_sessions(int $id): array` (normalized rows, empty for non-multisession).

### Task 1.1: Add the new meta keys to schema, defaults, and registration

- [ ] **Step 1: Extend `get_meta_schema()` and `get_meta_defaults()`**
  Add the six keys above with types/sanitizers (`type` enum, `sessions` array-of-rows, `registration_mode` enum, `external_url` esc_url_raw, `external_embed` kses, `external_display_price` sanitize_text_field). Register as post_meta alongside existing keys.

- [ ] **Step 2: Lint** → `php -l` clean.

- [ ] **Step 3: Static check**
  Run: `rg -n "_anchor_event_type|_anchor_event_registration_mode|_anchor_event_sessions|external_embed|external_display_price" anchor-events-manager/anchor-events-manager.php`
  Expected: each key appears in schema + defaults.

- [ ] **Step 4: Commit** — `feat(events): register event-type + registration-mode + external + sessions meta`.

### Task 1.2: Resolver helpers + BC shim + migration

- [ ] **Step 1: Implement `event_type()`, `registration_mode()`, `get_sessions()`**
  `event_type()` returns stored `_anchor_event_type` or `'single'`. `registration_mode()` returns stored `_anchor_event_registration_mode`; if absent, derive from legacy `_anchor_event_registration_type` (`external`→`external`, else `free`) and from presence of tiers/managed product (`wc` when the event has paid tiers). `get_sessions()` returns normalized rows or `[]`.

- [ ] **Step 2: One-time migration**
  Add an idempotent migration (option-flag guarded, `autoload=false`) that back-fills `_anchor_event_registration_mode` for existing events using the same derivation, so old events render correctly without a live read shim forever.

- [ ] **Step 3: Lint + static** — `php -l` clean; `rg -n "function event_type|function registration_mode|function get_sessions" …` present.

- [ ] **Step 4: Smoke (wp-env)** — existing events still show correct registration behavior; `event_type()` returns `single` for them.

- [ ] **Step 5: Commit** — `feat(events): type/mode resolvers + BC shim + one-time mode migration`.

### Task 1.3: Type chooser + registration-mode chooser in the admin metabox

- [ ] **Step 1: Render the choosers at the top of `render_meta_box()`**
  Insert, before the Date & Time section (~`:876`): an "Event type" radio/select (`single|multisession|offering|recurring`) and a "Registration" mode select (`wc|free|external`). Wrap dependent sections in containers with `data-when-type` / `data-when-mode` attributes for JS visibility.

- [ ] **Step 2: Render conditional field groups**
  Session-date repeater (multisession); external URL / embed / display-price fields (external mode); keep existing Date/Location/Ticket/Registration sections for single+wc/free. Offering/recurring parent controls are stubbed with a "configured in Phase 2" note container (no functional controls yet).

- [ ] **Step 3: Extend `save_meta()`**
  Add the six keys to the allow-list with their sanitizers; sanitize `sessions` rows (drop empty rows, validate dates); `external_embed` via the filterable `wp_kses` allowed-tags set (`anchor_events_embed_allowed_html`).

- [ ] **Step 4: Lint + static** — `php -l` clean; `rg -n "data-when-type|data-when-mode" …` present in the PHP; the six keys present in the `save_meta()` allow-list.

- [ ] **Step 5: Commit** — `feat(events): type + registration-mode choosers and conditional fields (admin metabox)`.

### Task 1.4: Conditional visibility + session repeater JS/CSS

- [ ] **Step 1: admin.js — visibility engine**
  jQuery IIFE: on load and on chooser change, show/hide `[data-when-type]`/`[data-when-mode]` containers matching current values. Debounce not needed (instant).

- [ ] **Step 2: admin.js — session repeater**
  Add/remove/reorder `{date,start_time,end_time,label}` rows; serialize into the named inputs `save_meta()` reads.

- [ ] **Step 3: admin.css** — chooser + repeater styles matching existing metabox styling.

- [ ] **Step 4: Version-bump** the `wp_enqueue_*` version strings for admin.js/admin.css.

- [ ] **Step 5: Smoke (wp-env)** — switching type/mode reveals the right fields; add two session rows, save, reopen — rows persist; set external mode with a URL + display price, save, reopen — persists.

- [ ] **Step 6: Commit** — `feat(events): admin JS/CSS for conditional fields + session repeater`.

### Task 1.5: Front-end manager form parity

- [ ] **Step 1: Mirror the choosers + fields in `render_event_manager_form()`**
  Add the type chooser, registration-mode chooser, session repeater, and external fields to the front-end form (parallel markup + a small enqueued script or inline handler reusing the same visibility approach).

- [ ] **Step 2: Mirror the save**
  Extend the front-end save (`:2289-2359`) to accept + sanitize the six keys identically to `save_meta()` (extract the shared sanitizer into one private method both paths call — DRY).

- [ ] **Step 3: Lint + static** — `php -l` clean; the shared sanitizer method is called from both `save_meta()` and the front-end save (`rg -n`).

- [ ] **Step 4: Smoke (wp-env)** — create a multisession + an external event via the front-end manager form; verify parity with admin.

- [ ] **Step 5: Commit** — `feat(events): front-end manager form parity for type/mode/sessions/external`.

### Task 1.6: Front-end rendering (single-event template)

- [ ] **Step 1: Render the session list** (multisession) in `single-event.php` from `get_sessions()`.

- [ ] **Step 2: Render the external registration block** — link button (`external_url`) or sanitized embed (`external_embed`), plus the display-only price label (`external_display_price`) when set. Ensure the display price never triggers any cart/registration code.

- [ ] **Step 3: frontend.css/js** — style the session list + external block; version-bump.

- [ ] **Step 4: Smoke (wp-env)** — view a multisession event (all sessions listed) and an external paid event (embed/link + "$X" label shown, no cart).

- [ ] **Step 5: Commit** — `feat(events): front-end rendering for sessions + external registration + display price`.

**Phase 1 review gate.**

---

## Phase 2 — Grouping engine (Pick-one offerings + Recurring)

**Outcome:** A group parent event holds shared content + either an offering-date list or a recurrence rule and reconciles child event posts (each a full event with its own date/capacity/roster/product), grouped by an `event_series` term. Front end presents the group as one "choose your date" unit.

**File structure:**
- Create: `anchor-events-manager/class-occurrences.php` — the reconcile engine (`\Anchor\Events\Occurrences`).
- Modify: `anchor-events-manager.php` — instantiate the engine; parent-role controls in the metabox (offering-date list, recurrence rule); wire save → reconcile; child inheritance of shared content.
- Modify: `class-series.php` — group-aware archive / "choose your date" grouping; `single-event.php` — sibling-date picker on a child.
- Modify: `assets/admin.js`/`admin.css` — offering-date + recurrence-rule editors.

**Interfaces produced:**
- Meta: `_anchor_event_group_id` (child→parent), `_anchor_event_group_role` (`parent|child`), `_anchor_event_offering_dates` (parent), `_anchor_event_recurrence` (parent rule `{freq,interval,byday|bymonthday,until|count}`).
- `Occurrences::reconcile(int $parent_id): array` (idempotent create/update/soft-close of children; returns child IDs).
- `Occurrences::children(int $parent_id): int[]`, `Occurrences::siblings(int $child_id): int[]`.

**Tasks (each with the standard lint + PHPUnit/Playwright + static + commit loop):**

- **2.1** `Occurrences` class scaffold + parent/child meta registration (excluded from user allow-list). Static check: keys not present in `save_meta()` allow-list.
- **2.2** Reconcile algorithm: for each offering date / generated recurrence date, find-or-create a child event post keyed by `(group_id, date)`; update shared content from parent; **soft-close** (set status closed, never delete) any child whose date was removed **and preserve its roster/seats**. Idempotent: second run with no changes creates nothing. Smoke: add 3 offering dates → 3 children; remove 1 → 2 active + 1 closed with seats intact.
- **2.3** Recurrence generator: expand `{freq,interval,until|count}` into concrete dates (weekly/monthly, simple; capped horizon). Pure function, unit-checkable via `wp eval`. Smoke: weekly ×4 → 4 children.
- **2.4** Metabox parent-role editors (offering-date list; recurrence-rule builder) + save → `Occurrences::reconcile()`. Front-end manager-form parity for offering dates (recurrence admin-only is acceptable — note in plan).
- **2.5** Child inheritance + guard: children render read-mostly (shared fields inherited, date/capacity local); prevent orphaning children with registrations.
- **2.6** Front-end "choose your date": `class-series.php` archive groups by parent; `single-event.php` on a child shows a sibling-date picker (each links to its own registration). Smoke: pick-one event shows all dates; registering on one only affects that child's roster.

**Phase 2 review gate.**

---

## Phase 3 — Email templates backend (builder + schedule view)

**Outcome:** Per-event editable email templates (Confirmation/Reminder/Cancellation/Roster) with a Monaco HTML builder + live preview + token palette, backed by global defaults, plus a read-only upcoming-sends schedule.

**File structure:**
- Modify: `anchor-events-manager.php` — refactor `build_registration_email_html()` to render from a resolved template string; template resolver (per-event meta → global option → seeded default); new Emails metabox; AJAX preview endpoint; schedule computation.
- Modify: settings registration — per-type global default template options (seeded from current shell markup).
- Create: `anchor-events-manager/assets/email-builder.js` (+ `.css`) — clone of `anchor-blocks/assets/admin.js` (Monaco + `Anchor_Preview_CSS` + `srcdoc` debounced preview + token insertion + "render real tokens" AJAX).
- Modify: enqueue block — `Anchor_Monaco::enqueue(self::CPT)` + `Anchor_Preview_CSS::enqueue_for_admin()` + the new script (dep `anchor-monaco`,`anchor-preview`), gated to the event editor.

**Interfaces produced:**
- Meta: `_anchor_event_email_tpl_{confirmation|reminder|cancellation|roster}` (per-event override).
- Options: `anchor_events_email_tpl_{type}` (global default, `autoload=false`).
- `Module::resolve_email_template(int $event_id, string $type): string`.
- AJAX: `wp_ajax_anchor_events_email_preview` → `{html}` (renders via `build_registration_email_html()`/`email_tokens()` for a given event+type, no send).

**Tasks (standard loop each):**

- **3.1** Seed global default template options from the current hardcoded shell (extract the `build_registration_email_html()` markup into a default template string with tokens). Verify: rendering the default produces byte-equivalent output to pre-refactor for a fixed `$ctx` (capture before/after via `wp eval`).
- **3.2** Refactor `build_registration_email_html()` to render from `resolve_email_template()` through `expand_email_tokens()`; preserve `$ctx` contract + the `anchor_events_registration_email_html` filter. PHPUnit regression: assert rendered output for a fixed `$ctx` equals the 3.1 captured baseline.
- **3.3** Emails metabox: four tabs, each a `Anchor_Monaco` HTML field + token palette + `srcdoc` preview iframe + "reset to global default" + "render with real tokens" button.
- **3.4** `email-builder.js`/`.css` cloned from anchor-blocks; wire Monaco synthetic `input` → debounced `srcdoc`; token-insert buttons; AJAX real-token preview. Version-bump enqueues.
- **3.5** AJAX `anchor_events_email_preview` endpoint (nonce + cap; returns rendered HTML for event+type). Smoke: preview matches a real send.
- **3.6** Upcoming-sends schedule panel: compute pending sends from `start_ts`, effective reminder offsets, `roster_auto_offset`, confirmed-seat count, and markers (`_anchor_event_reminders_sent`, `_anchor_event_roster_sent`); render type/recipient-scope/count/time/state. Read-only. Smoke: schedule matches what the cron would send.

**Phase 3 review gate.**

---

## Phase 4 — Event JSON-LD

**Outcome:** Correct `Event` structured data per event post; multi-session as one Event with `subEvent`/`eventSchedule`; offering/recurring children each emit their own node; external display-price surfaces as `offers`.

**File structure:**
- Create: `anchor-events-manager/class-event-schema.php` (`\Anchor\Events\Event_Schema`).
- Modify: `single-event.php` (or hook `wp_head` for single event views) to emit the JSON-LD; guard against duplicating any schema the parent Anchor Schema plugin already emits for the post.

**Interfaces produced:** `Event_Schema::for_event(int $id): array` (JSON-LD graph node), rendered via a `wp_head`/template hook with `apply_filters('anchor_events_event_schema', $data, $id)`.

**Tasks (standard loop each):**
- **4.1** `for_event()` builds the base `Event` node from `start_ts`/`end_ts`, location, image, permalink, `offers` (tier price for `wc`, `external_display_price` for `external`, free otherwise). Verify JSON via `wp eval` against Google Rich Results expectations (valid required fields).
- **4.2** Multi-session: emit `subEvent[]`/`eventSchedule` from `_anchor_event_sessions`.
- **4.3** Emit + de-dupe guard on single views; children emit their own node. Smoke: validate a single, a multisession, and a pick-one child via the Rich Results structure (manually paste output into validator — PENDING-HUMAN if no network in-agent).

**Phase 4 review gate.**

---

## Phase 5 — Client-agnostic audit, docs, release

**Outcome:** No client-specific strings in the plugin; docs refreshed; merged to `main` and released.

**Tasks:**
- **5.1** String audit: `rg -ni "deka|jotform|jot form|anchor dental|<other client names>" anchor-events-manager/` → zero functional matches; neutralize any found (labels become generic/filterable).
- **5.2** Docs: refresh `EVENTS-WOOCOMMERCE.md`, `EMAILS.md`; add an `EVENTS.md` covering event types + external registration + email builder. Update root `ADDING-MODULES.md` cross-refs if needed.
- **5.3** Full regression: `composer test` (all PHPUnit suites) + `npm run test:e2e` (all Playwright specs) green across all four types × three registration modes + emails + schema.
- **5.4** Version bump `anchor-tools.php` `Version:` header; merge `feature/events-rework` → `main`; tag/release **from main**; regenerate `.min.*` via the release minifier.

**Phase 5 review gate → release.**

---

## Self-Review (against spec)

- **Spec §3 occurrence model** → Phase 1 (multisession + meta) + Phase 2 (offering/recurring generator). ✓
- **Spec §4 two axes / external registration** → Phase 1 Tasks 1.1–1.6. ✓
- **Spec §5 email rescue + templates + builder + schedule** → Phase 0 + Phase 3. ✓
- **Spec §6 JSON-LD** → Phase 4. ✓
- **Spec §2 latent header bug** → Task 0.3. ✓
- **Spec §2 two semantic merge checks** → Task 0.2. ✓
- **Spec §7 phasing + gates** → phase headers + review gates. ✓
- **Spec §8 risks** (generator drift/soft-close, form parity, embed XSS, release-from-main) → Tasks 2.2/2.5, 1.5, 1.3 Step 3, 5.4. ✓
- **Spec §9 open items** → resolved at their phase start (parent = post: Phase 2; recurrence UI: 2.4; Emails UI = metabox: 3.3; migration = one-time sweep: 1.2). ✓

No `TODO`/`TBD` placeholders; each task has concrete files, interfaces, and a lint+static+smoke+commit loop. Later-phase code steps are intentionally finalized at phase start against then-current code (staged multi-subsystem plan), not left vague — each task states exact files, methods, and verification.
