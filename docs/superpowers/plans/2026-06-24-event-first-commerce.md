# Event-First Commerce Implementation Plan

> **For agentic workers:** Implement task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> **No automated test suite exists** (raw PHP/WordPress). Every task closes with `php -l` on changed
> files + explicit **manual verification** steps + a commit. Two test environments are referenced:
> **Env-A** = WooCommerce inactive; **Env-B** = WooCommerce active (test HPOS on and off).

**Goal:** Evolve the Anchor Events module into an event-first WooCommerce integration where the event
owns its ticket tiers and an auto-managed product, sessions are events grouped by a Series, and the
event page is an inline storefront — reusing the existing seat/capacity/reconcile/roster/email engines.

**Architecture:** Additive on the merged code. Ticket types are structured event meta; each paid event
maps to one auto-managed, catalog-hidden WooCommerce variable product (tier = variation) via a
declarative one-way sync. Capacity (event total + per-tier quota) stays the single authority in the
seat layer under the per-event `GET_LOCK`. The event page renders tiers with AJAX add-to-cart; seats
are created tier-tagged through the existing order-reconcile engine. Free tiers keep the inline,
Woo-independent registration path.

**Tech Stack:** PHP 7.4+ (namespaced `Anchor\Events`), WordPress CPT/meta/taxonomy/Settings APIs,
WooCommerce CRUD (HPOS-safe) + product/variation CRUD, jQuery (IIFE) for admin + storefront JS.

**Spec:** `docs/superpowers/specs/2026-06-24-event-first-commerce-design.md`

## Global Constraints

- Text domain: `anchor-schema`. Asset URLs via `\Anchor_Asset_Loader::url('anchor-events-manager/assets/...')`.
- `update_option(..., false)` (autoload off). Namespaced files prefix WP/WC globals with `\`.
- **WooCommerce-optional:** never fatal when Woo absent; guard all `wc_*`/WC classes with
  `function_exists`/`class_exists`. The WooCommerce class loads only when `class_exists('WooCommerce')`.
- **HPOS-safe:** order/order-item access via WooCommerce CRUD only — no order postmeta.
- **Single capacity authority:** the `Registrations` seat layer under `with_event_lock()`; never Woo stock.
- **Idempotent:** event→product sync and order→seat reconcile converge on re-run; nothing with sales is
  hard-deleted; `(order_item_id, seat_index)` remains the Woo idempotency key.
- Escape all output; caps + nonces on every admin/AJAX action; no PII in logs.
- Reuse existing engines — do NOT reimplement: `Registrations` (seat CRUD, capacity, `with_event_lock`,
  `claim_seats`, `find_seat_by_item`), `class-woocommerce.php` `reconcile_order()`, `Roster`,
  `Events_Log`, the email builder/sender, GDPR hooks.

## File Structure

- `anchor-events-manager/class-ticket-types.php` — **new.** `\Anchor\Events\Ticket_Types`: the ticket-tier
  model — read/normalize/save the per-event tier list, stable tier IDs, tier lookups, attendee-field
  resolution. One responsibility: tier data.
- `anchor-events-manager/class-product-sync.php` — **new.** `\Anchor\Events\Product_Sync` (WC-gated):
  declarative event→managed-product reconcile (create/update/deactivate variations), catalog-hidden +
  managed flags, trash handling, managed-field lock. One responsibility: keep the product matching the event.
- `anchor-events-manager/class-series.php` — **new.** `\Anchor\Events\Series`: register the `event_series`
  taxonomy + the series archive rendering. One responsibility: session grouping.
- `anchor-events-manager/class-registrations.php` — **modify.** Add `ticket_type_id` to seats + tier-aware
  capacity (per-tier quota counting) and manual-add override.
- `anchor-events-manager/class-woocommerce.php` — **modify.** Tier-aware reconcile (tag seats with tier,
  resolve tier from variation), storefront filter → ticket block + AJAX add-to-cart handlers, demote the
  product-first link panel to the advanced escape hatch.
- `anchor-events-manager/class-roster.php` — **modify.** Tier column + tier picker + over-capacity override
  in manual add/edit.
- `anchor-events-manager/anchor-events-manager.php` — **modify.** Load the new classes; ticket-types
  metabox UI on the event editor; thread tier into the free registration path + the registration-form
  render seam; series UI.
- `anchor-events-manager/assets/ticket-types-admin.js` — **new.** Repeatable ticket-tier rows in the editor.
- `anchor-events-manager/assets/event-storefront.js` — **new.** Event-page tier qty + AJAX add-to-cart.
- `anchor-events-manager/templates/archive-series.php` (or taxonomy template hook) — **new.** Series archive.

The plan is phased; each phase is independently shippable and leaves the plugin working (free + paid).

---

## Phase 1 — Ticket-type model + event-editor authoring (no product yet)

**Goal:** Author ticket tiers on the event and store them; seats carry a tier id; free-only behavior
unchanged. No WooCommerce product yet — this phase ships standalone and is testable in Env-A.

### Task 1.1 — Ticket_Types model

**Files:** Create `anchor-events-manager/class-ticket-types.php`; Modify `anchor-events-manager.php`
(require + instantiate in `Module::__construct` after `$this->registrations`).

**Interfaces — Produces:**
- `Ticket_Types::get( int $event_id ): array` — ordered list of normalized tier arrays
  `['id'=>string,'label'=>string,'price'=>float,'quota'=>int,'sale_start'=>string,'sale_end'=>string,'active'=>bool,'wc_variation_id'=>int,'attendee_fields'=>array]`.
  When an event has no tier list but `registration_enabled`/legacy data, synthesize a single implicit
  tier `id='primary'`, `price=(float)$meta['price']`, `active=true`.
- `Ticket_Types::save( int $event_id, array $raw ): array` — sanitize + assign stable ids
  (`wp_generate_uuid4()`-style short id for new rows; preserve existing ids), persist to event meta
  `_anchor_event_ticket_types`, return the saved list.
- `Ticket_Types::find( int $event_id, string $tier_id ): ?array`.
- `Ticket_Types::is_on_sale( array $tier, int $now=0 ): bool` (sale window check using `current_time`).
- `Ticket_Types::primary_id( int $event_id ): string`.

- [ ] **Step 1:** Write `class-ticket-types.php` with the methods above. Storage key
  `_anchor_event_ticket_types` (array). IDs are stable strings; never reuse a removed id. Prices cast
  via `wc_format_decimal` when available else `(float)`. Register the meta in `Module::register_meta()`
  with `show_in_rest=>false` + the existing event auth callback.
- [ ] **Step 2:** In `Module::__construct`, `require_once $dir . 'class-ticket-types.php';` and
  `$this->ticket_types = new Ticket_Types( $this );` (add `public $ticket_types = null;`).
- [ ] **Step 3:** `php -l` both files.
- [ ] **Step 4: Manual verify (Env-A):** add a quick `error_log` (temporary) or WP-CLI eval:
  `Ticket_Types::get($id)` on an event with the legacy `price` meta returns one implicit `primary` tier.
  Remove the temporary probe.
- [ ] **Step 5: Commit** `feat(events): ticket-type model + implicit-primary fallback`.

### Task 1.2 — Ticket-types metabox UI

**Files:** Modify `anchor-events-manager.php` (new metabox `anchor_event_ticket_types`, render + save in
`save_meta`); Create `assets/ticket-types-admin.js`; enqueue on the event editor screen.

**Interfaces — Consumes:** `Ticket_Types::get/save`.

- [ ] **Step 1:** Add an "Tickets / Pricing" metabox: a repeatable table (label, price, quota,
  sale_start, sale_end, active) with add/remove row + drag order, hidden index inputs carrying each
  row's stable `id` (blank for new rows). jQuery IIFE in `ticket-types-admin.js`, enqueued only on
  `post.php`/`post-new.php` for the `event` CPT (mirror the existing admin-asset gating).
- [ ] **Step 2:** In `save_meta`, read the posted tier rows and call `Ticket_Types::save()`. Nonce reuse
  the existing `self::NONCE`. Do not remove the legacy single `price` field yet (kept as the implicit
  primary fallback; hide it when tiers exist).
- [ ] **Step 3:** `php -l`; build min asset if the repo minifies (check `assets/` for `.min` siblings —
  if present, regenerate or load the non-min in dev; match existing enqueue version-bump convention).
- [ ] **Step 4: Manual verify (Env-A):** add 2 tiers, save, reload → both persist with stable ids;
  remove one → gone; reorder → order persists.
- [ ] **Step 5: Commit** `feat(events): ticket-type authoring metabox`.

### Task 1.3 — Seat tier tag + tier-aware free registration

**Files:** Modify `class-registrations.php` (register `_anchor_event_ticket_type_id`, accept
`ticket_type_id` in `create_seat`, default to primary), `anchor-events-manager.php` (free
`handle_registration` + `render_registration_form` pass the chosen tier).

**Interfaces — Consumes:** `Ticket_Types`. **Produces:** seats carry `_anchor_event_ticket_type_id`.

- [ ] **Step 1:** Register seat meta `_anchor_event_ticket_type_id` (string) in `Module::register_meta`.
  In `Registrations::create_seat`, persist `ticket_type_id` (default `Ticket_Types::primary_id`).
- [ ] **Step 2:** Free path: `render_registration_form` shows a tier selector when an event has >1 free
  tier (else implicit primary); `handle_registration` reads/validates the tier and passes it to
  `claim_seats` payload. Paid tiers are NOT sold via the free form.
- [ ] **Step 3:** `php -l`.
- [ ] **Step 4: Manual verify (Env-A):** a free signup creates a seat with the correct
  `_anchor_event_ticket_type_id`; existing seats (no meta) read as `primary` via `get_seat_info`/roster.
- [ ] **Step 5: Commit** `feat(events): tag seats with ticket type; tier-aware free registration`.

**Phase 1 acceptance:** tiers authored + stored with stable ids; seats carry a tier; free events behave
as before (implicit primary); no WooCommerce involved; Env-A clean.

---

## Phase 2 — Auto-managed product sync (event → product)

**Goal:** A paid event auto-manages one catalog-hidden variable product; tiers ↔ variations; declarative
idempotent sync on save; trash → draft; managed fields locked. WC-gated; Env-A untouched.

### Task 2.1 — Product_Sync reconcile

**Files:** Create `class-product-sync.php` (`\Anchor\Events\Product_Sync`, constructed only inside the
`class_exists('WooCommerce')` block in `Module::__construct`, receiving `Module` + `Ticket_Types`).

**Interfaces — Produces:**
- `Product_Sync::sync_event( int $event_id ): int` — ensure/refresh the managed product; returns product id (0 if no paid tier).
- `Product_Sync::variation_for_tier( int $event_id, string $tier_id ): int` and
  `Product_Sync::tier_for_variation( int $variation_id ): array{event_id:int,tier_id:string}`.
- `Product_Sync::managed_product_id( int $event_id ): int`.

- [ ] **Step 1:** Implement `sync_event()`: if the event has ≥1 paid (`price>0`) active tier, ensure a
  variable product exists (store its id in event meta `_anchor_event_managed_product`; store
  `event_id` back on the product meta `_anchor_evt_managed_event`). Set product
  `catalog_visibility='hidden'`, `manage_stock=false`, status `publish`. Reconcile variations:
  match by variation meta `_anchor_evt_tier_id`; create missing, update price/label for changed,
  **deactivate** (set variation status `private` + a `_anchor_evt_tier_active=0` flag) tiers removed
  *with sales*, delete variations for removed tiers with **zero** seats
  (`Registrations` has no seats for that tier). Write `wc_variation_id` back into the tier list via
  `Ticket_Types::save`. All via WC CRUD.
- [ ] **Step 2:** Hook `save_post_event` (after `save_meta`, lower priority) → `sync_event`. Also expose
  it for manual re-sync.
- [ ] **Step 3:** Trash/delete: on `wp_trash_post`/`before_delete_post` for `event`, set the managed
  product to `draft` (do not delete — orders reference it). Seats/orders preserved.
- [ ] **Step 4:** `php -l`.
- [ ] **Step 5: Manual verify (Env-B):** event with 2 paid tiers → one hidden variable product, 2
  variations at right prices; rename/reprice a tier → same variation updates; add a tier → new
  variation; remove a no-sales tier → variation gone; trash the event → product draft.
- [ ] **Step 6: Commit** `feat(events): auto-managed product sync (event -> product)`.

### Task 2.2 — Managed-field lock + admin notice

**Files:** Modify `class-product-sync.php`.

- [ ] **Step 1:** On `woocommerce_update_product` for a managed product, if a managed field (price/title/
  variation set) was changed directly, re-assert from the event on next `sync_event` and queue an
  `admin_notice` ("This product is managed by its event; managed fields were restored."). Leave
  descriptive fields (image, description) alone. Guard against self-recursion (the in-flight pattern
  already used in `class-woocommerce.php`).
- [ ] **Step 2:** `php -l`.
- [ ] **Step 3: Manual verify (Env-B):** edit a managed variation price directly → on next event save it
  reverts + notice shows. Editing the product image persists.
- [ ] **Step 4: Commit** `feat(events): lock managed product fields with admin notice`.

**Phase 2 acceptance:** paid event ⇄ product stays in sync idempotently; deactivate-not-delete for
tiers with sales; trash → draft; managed fields locked; Env-A shows no product, no fatals.

---

## Phase 3 — Event-page storefront (inline AJAX add-to-cart)

**Goal:** The event page renders tiers with quantity + AJAX add-to-cart for paid tiers; free tier inline
form retained; per-tier availability states. Replaces the current "Register → product page" button.

### Task 3.1 — Storefront render

**Files:** Modify `class-woocommerce.php` `filter_registration_form()` to render the ticket block;
Create `assets/event-storefront.js`; enqueue on single event when Woo active + event has paid tiers.

**Interfaces — Consumes:** `Ticket_Types`, `Product_Sync::variation_for_tier`,
`Registrations::remaining_capacity` + new per-tier remaining (Task 5.1).

- [ ] **Step 1:** Render one row per active tier: label, `wc_price`, availability state
  (available → qty input; tier quota hit → "Sold out"; event full + waitlist → "Join waitlist"; outside
  sale window → "Sales open <date>" / hidden), and a single "Register / Add to cart" button. Free tiers
  render the existing inline form. Markup reuses `.anchor-event-register`.
- [ ] **Step 2:** `event-storefront.js`: collect chosen quantities, POST to the AJAX endpoint (Task 3.2),
  show an "added — View cart / Checkout" confirmation inline.
- [ ] **Step 3:** `php -l`; asset version bump.
- [ ] **Step 4: Manual verify (Env-B):** event page shows tiers + prices + qty; unlinked/free event still
  shows the inline form; sold-out tier shows disabled.
- [ ] **Step 5: Commit** `feat(events): event-page ticket storefront render`.

### Task 3.2 — Add-to-cart AJAX endpoint

**Files:** Modify `class-woocommerce.php` (register `wp_ajax_anchor_events_add_to_cart` +
`nopriv`; nonce; map each {tier → variation} + qty into `WC()->cart->add_to_cart`).

- [ ] **Step 1:** Handler: verify nonce; for each requested tier+qty, resolve the managed variation via
  `Product_Sync::variation_for_tier`, validate the tier is on sale + has remaining (server-side, under
  the capacity check), add the variation to the cart; return JSON `{added, cart_url, checkout_url,
  messages}`. Reject with a clear message if sold out / sales closed (mirrors the existing add-to-cart
  validation contract).
- [ ] **Step 2:** Keep the existing `woocommerce_add_to_cart_validation` / `is_purchasable` gates as the
  back-stop for direct cart adds.
- [ ] **Step 3:** `php -l`.
- [ ] **Step 4: Manual verify (Env-B):** add 1 VIP + 2 Regular via the event page → cart shows 3 items on
  the right variations; sold-out tier rejected with a notice; guest (logged-out) works.
- [ ] **Step 5: Commit** `feat(events): AJAX add-to-cart for event tiers`.

**Phase 3 acceptance:** event page is a working storefront; multi-tier add works; free path intact;
Env-A unaffected.

---

## Phase 4 — Tier-aware checkout capture + seat creation

**Goal:** Per-seat attendee fields (existing) are grouped per tier; reconcile tags each created seat with
its tier (resolved from the line's variation).

### Task 4.1 — Tier-tagged reconcile

**Files:** Modify `class-woocommerce.php` (`reconcile_line` / seat creation: resolve tier via
`Product_Sync::tier_for_variation` from the line's variation id; pass `ticket_type_id` into
`create_seat`); checkout attendee fieldsets labelled by tier.

**Interfaces — Consumes:** `Product_Sync::tier_for_variation`, `Registrations::create_seat(ticket_type_id)`.

- [ ] **Step 1:** In the reconcile seat-create path, derive `tier_id` from the order item's variation
  (fallback: event primary). Pass it into the seat payload. The checkout attendee block headings show
  the tier label.
- [ ] **Step 2:** `php -l`.
- [ ] **Step 3: Manual verify (Env-B):** buy 1 VIP + 2 Regular → 3 seats, each with the correct
  `_anchor_event_ticket_type_id`; roster shows the tier; refund a VIP line → that seat refunded.
- [ ] **Step 4: Commit** `feat(events): tag created seats with their ticket tier`.

**Phase 4 acceptance:** seats are tier-accurate end-to-end through purchase + refund.

---

## Phase 5 — Tier-aware capacity, quotas, waitlist

**Goal:** Enforce per-tier quota nested under the event total, in the single seat-layer authority.

### Task 5.1 — Per-tier counting + decision

**Files:** Modify `class-registrations.php`.

**Interfaces — Produces:**
- `Registrations::count_reserved_for_tier( int $event_id, string $tier_id, bool $fresh=false ): int`.
- `Registrations::tier_remaining( int $event_id, array $tier, bool $fresh=false ): int`
  (`min(event remaining, tier quota − tier reserved)`; tier quota 0 = only event-bounded).
- Extend `capacity_decision` / `claim_seats` / `claim_woo_seats` to accept a `tier_id` + tier quota and
  return `open|full|waitlist|closed` honoring both levels (event full → event-level waitlist; tier quota
  hit but event has room → `full` for that tier, no waitlist).

- [ ] **Step 1:** Add a `(event_id, tier_id)` reserved count (extend the existing aggregate SQL with a
  `_anchor_event_ticket_type_id` GROUP BY, or a filtered count). Cast/COALESCE-safe like the existing
  capacity query. Thread `tier_id` + quota through `claim_seats`/`claim_woo_seats`/`capacity_decision`,
  all inside `with_event_lock`.
- [ ] **Step 2:** `php -l`.
- [ ] **Step 3: Manual verify (Env-B):** tier quota 2, event capacity 10 → 3rd buy of that tier blocked
  while other tiers still sell; event capacity full + waitlist on → waitlist seats; concurrent double-buy
  never exceeds the tier quota or the event total.
- [ ] **Step 4: Commit** `feat(events): per-tier quota under event capacity (single authority)`.

**Phase 5 acceptance:** both capacity levels enforced atomically; coupons (native Woo) verified to
discount an event order without affecting seat counts.

---

## Phase 6 — Series taxonomy + archive

**Goal:** Group session-events under a Series with an archive/landing page.

### Task 6.1 — Series taxonomy + archive

**Files:** Create `class-series.php`; Modify `anchor-events-manager.php` (load it); Create
`templates/archive-series.php` (or a `taxonomy-event_series.php` template via `template_include`).

- [ ] **Step 1:** Register the `event_series` taxonomy on the `event` CPT (`show_in_rest`, hierarchical
  false, rewrite slug `series`). Event editor gets the standard term UI.
- [ ] **Step 2:** Series archive: list the series' events ordered by `start_ts`, each showing date,
  "from `<lowest tier price>`", and availability; link to each event page. Hook the template via the
  module's existing `template_include`.
- [ ] **Step 3:** `php -l`.
- [ ] **Step 4: Manual verify:** create a series with 3 session-events → archive lists all 3 with correct
  "from $X"; each event keeps its own capacity/roster.
- [ ] **Step 5: Commit** `feat(events): event series taxonomy + archive`.

**Phase 6 acceptance:** sessions navigable as a series; each session independent.

---

## Phase 7 — Admin manual add (tier + over-capacity override) + escape-hatch demotion

**Goal:** Admin adds attendees directly with a tier + optional capacity override; demote the
product-first link panel to the advanced escape hatch.

### Task 7.1 — Roster manual add tier-aware + override

**Files:** Modify `class-roster.php` (add form gets a tier `<select>` + "allow over capacity" checkbox;
pass `ticket_type_id` + `$allow_over` into `claim_seats`); Modify `class-registrations.php`
(`claim_seats`/`claim_woo_seats` accept an `$allow_over` flag that bypasses the capacity ceiling while
still recording the seat + a history note).

- [ ] **Step 1:** Roster "Add attendee" form: tier select (from `Ticket_Types::get`), status select,
  over-capacity checkbox. Manual add uses `source='manual'`, no order. Override records
  `note='manual add (capacity override)'`.
- [ ] **Step 2:** Roster table gains a **Tier** column; CSV export includes the tier label.
- [ ] **Step 3:** `php -l`.
- [ ] **Step 4: Manual verify (Env-A + Env-B):** comp an attendee into a paid event (no order) → confirmed
  seat, `source=manual`, tier set; override exceeds capacity and is noted in history; editor without WC
  caps blocked appropriately.
- [ ] **Step 5: Commit** `feat(events): tier-aware manual roster add with capacity override`.

### Task 7.2 — Demote product-first link panel to advanced escape hatch

**Files:** Modify `class-woocommerce.php` (the existing product-data "Event Registration" link panel).

- [ ] **Step 1:** Relabel/relocate the existing product-data panel as "Advanced: link this product to an
  event" with a note that auto-managed event products are the normal path; keep its resolver/mirror so
  any existing links keep working. Do NOT remove (back-compat). Managed products created by `Product_Sync`
  are excluded from this panel (they're already linked).
- [ ] **Step 2:** `php -l`.
- [ ] **Step 3: Manual verify (Env-B):** a manually-linked existing product still creates seats on
  purchase; an auto-managed event product is unaffected.
- [ ] **Step 4: Commit** `refactor(events): demote product-first linking to advanced escape hatch`.

**Phase 7 acceptance:** admins can comp/manage attendees per tier with override; legacy linking remains
as a clearly-secondary path.

---

## Phase 8 — Migration, regression sweep, docs

**Goal:** Verify additive migration + non-regression, document the model.

### Task 8.1 — Migration + regression verification

**Files:** Modify `docs/` (a short "Events + WooCommerce" usage note); no schema migration script needed
(additive — existing free events read implicit-primary; existing seats default tier in code).

- [ ] **Step 1:** Confirm an existing free event (pre-tier data) renders + registers unchanged
  (implicit-primary), and its existing seats show as `primary` in roster/export.
- [ ] **Step 2:** Full regression sweep — Env-A: free signup, event display, calendar, archive unchanged;
  no `woocommerce_*` hooks registered; no DB writes on render. Env-B: full purchase (multi-tier, guest),
  capacity/quota/waitlist, coupon, refund (full/partial/amount-only), order edit/resync, trash/delete,
  HPOS on/off — all behave; needs-review + emails fire as before.
- [ ] **Step 3:** Write the usage note (create event → add tiers → publish → event page sells →
  roster/exports; free events; series; manual add; advanced linking).
- [ ] **Step 4: Commit** `docs(events): event-first commerce usage + migration notes`.

**Phase 8 acceptance:** existing data preserved and behaving; all matrix rows pass in both environments.

---

## Self-Review (author)

- **Spec coverage:** §3 model → P1/P4/P5; §4–5 product+sync → P2; §6 storefront/checkout → P3/P4; §7
  capacity → P5; §8 coupons → native (verified P5); §9 free/paid → P1/P3; §10 manual add → P7; §11
  migration → P1/P8; §12 constraints → Global Constraints + every task; §13 acceptance → mapped across
  phase acceptance + P8 matrix. No spec section is unmapped.
- **Placeholders:** none — each task names exact files, signatures, hooks, and concrete manual checks.
- **Type consistency:** `Ticket_Types::get/save/find/primary_id`, `Product_Sync::sync_event/
  variation_for_tier/tier_for_variation/managed_product_id`, seat meta `_anchor_event_ticket_type_id`,
  `Registrations::count_reserved_for_tier/tier_remaining` + `claim_seats(...,$tier_id,$allow_over)` are
  used consistently across phases.
