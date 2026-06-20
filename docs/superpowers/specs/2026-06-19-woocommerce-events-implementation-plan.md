Verified key anchors. The spec's line numbers are approximately correct (actuals: `render_registration_form` 1983, `handle_registration` 2067, `handle_export` 2147, `get_meta` 2477, `meta_key` 2498, `clear_caches` 2420, `get_event_status` 2715, `get_registration_status` 2769, `get_attendee_count` 2854, `send_registration_emails` 2885, `build_registration_email_html` 2914, `get_settings` 3018, `register_meta` 209, `get_meta_schema` 267, `get_meta_defaults` 302, `save_meta` 616, `render_registrants_metabox` 562). I'll reference method names plus approximate lines.

Here is the phased implementation plan.

---

# Anchor Events — WooCommerce Registration: Phased Implementation Plan

Module root: `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/anchor-events-manager/`
Main core file (existing): `anchor-events-manager.php` (3039 lines)
Main plugin file: `anchor-tools.php` (HPOS declaration + module bootstrap)

**Guiding rules for every phase**
- Plugin must remain shippable after each phase. WooCommerce stays optional; non-WC sites load nothing extra.
- All new methods/hooks named per spec §16 reconciliations. One method per concept (no aliases).
- Commit + push before each phase (rollback safety, per global rule).
- No automated tests exist — each phase ends with explicit manual verification steps.
- Two test environments throughout: **Env-A** = WordPress, no WooCommerce. **Env-B** = WordPress + WooCommerce, classic shortcode checkout, tested with HPOS **on and off**.

---

## Phase 0 — Data layer + bug fixes + status vocabulary (NO WooCommerce; safe to ship)

**Goal:** Introduce the seat data layer and logging helper, expand the status vocabulary additively, fix the five verified bugs, and route the existing free path through the new layer. Behavior on a free-only site must be byte-identical to today except the corrective fixes. This phase ships independently and de-risks everything after it.

**Files to create**
- `anchor-events-manager/class-events-log.php` — `\Anchor\Events\Events_Log` (static): `order()`, `event()`, `error()`, `flag_review()`, `clear_review()`. `error()` forwards to `\Anchor_Schema_Logger`. Site-wide error log option `anchor_events_error_log` (autoload=false, cap ~200). (Per-order sync log + needs-review live here too; the **per-event activity roll-up / Activity panel is deferred — see Finding #20**, so do not build `_anchor_event_activity` UI now. The data-model field may be reserved but no panel is built.)
- `anchor-events-manager/class-registrations.php` — `\Anchor\Events\Registrations`. Constructor takes `Module`. Implements:
  - Status constants (§4.2) + `RESERVING_STATUSES`, `WAITLIST_STATUSES`, `$transitions` (§4.3). `RESERVING_STATUSES = ['confirmed','pending']` only — **`attended` is NOT counted in MVP** (Finding #21; check-in deferred).
  - `create_seat(array $args): int` (writes all seat meta, writes first history entry, sets `_anchor_event_source`). Integer seat meta (`seat_index`, `order_item_id`, etc.) stored/compared as **integer** (Finding #19).
  - `update_status(int $seat_id, string $to, string $note='', string $actor='system'): bool` (validates transition, appends history, no-ops illegal/same-status, never fatal). On the first `update_status` of a legacy seat (empty history), synthesize a backfill `{status: current, time: post_date, note: 'pre-existing', actor: 'system'}` entry before appending (Finding #23).
  - Capacity: single `$wpdb` aggregate (§4.5) → `count_reserved_seats`, `count_waitlist_seats`, `remaining_capacity`, transient `anchor_evt_caps_{id}`.
  - Concurrency: `with_event_lock(int, callable)` (GET_LOCK/RELEASE_LOCK in `finally`), `claim_seats(int $event_id, array $meta, int $qty, array $seat_payload): array` (recount-under-lock, allocate confirmed/pending/waitlist). **The per-item existing-seat lookup by `(order_item_id, seat_index)` runs INSIDE the lock, immediately before create** (Finding #9) — `claim_seats` re-reads existing item seats under the lock, not just capacity. Before any insert it asserts no existing seat (ANY status) matches `(order_item_id, seat_index)`; if one exists it logs needs-review `duplicate_seat_prevented` and skips (Finding #18). All `(order_item_id, seat_index)` dedupe/lookups **no-op when `order_item_id <= 0`** (Finding #11) — the idempotency key is meaningful only for `source=woocommerce` seats.
  - `capacity_decision(int $event_id, array $meta, int $requested=1): string` → `open|full|waitlist|closed` (preserves window/closed semantics from `get_registration_status`).
  - Counting moved here: `get_attendee_count`, `get_registration_count`, `get_registrations` (their bodies move out of `Module`).
  - `get_seats_for_order_item(int $item_id)` — **early-returns empty when `$item_id <= 0`** (Finding #11), orders by `CAST(seat_index AS UNSIGNED)` (Finding #19).

**Files to edit**
- `anchor-events-manager.php`:
  - In `Module::__construct()` right after `self::$instance = $this;` (≈L18): require/instantiate **only the always-on data layer this phase needs** — `Events_Log` (static) and `Registrations`. **Do NOT `require_once class-roster.php` and do NOT `new Roster()` in Phase 0** (Finding #25); Roster is re-homed/instantiated in Phase 5. Make the loader block explicit so the spec §3 full snippet is not pasted verbatim:

    ```php
    $dir = \plugin_dir_path( __FILE__ );
    require_once $dir . 'class-events-log.php';
    require_once $dir . 'class-registrations.php';
    $this->registrations = new Registrations( $this );
    // NOTE: class-roster.php / new Roster() deferred to Phase 5.
    // NOTE: class-woocommerce.php loader added in Phase 1 (WC-gated).
    ```
  - `register_meta()` (L209): register the 9 new seat meta keys (§4.1) with existing `$reg_auth_callback`; `_anchor_event_history` → `show_in_rest=>false`; integer keys as integer, string keys as string.
  - `get_meta_schema()` (L267) + `get_meta_defaults()` (L302): add event-side `organizer_email`, `linked_products` (mirror). **Do NOT add `linked_products` to the `save_meta()` allow-list (L616/L627 region)** so event saves never clobber the product-owned mirror. (`activity` reserved-only per Finding #20 — no panel.)
  - **Bug #2 (write-on-read):** `get_event_status()` (L2715) becomes pure — remove the `update_post_meta` during render (L2724). Add persistence in existing write contexts (`save_meta` L665, `handle_event_manager_save` L1756) + `transition_post_status` + a **defensively-scheduled** daily cron `anchor_events_status_sweep` (Finding #13): schedule on `init`/`admin_init` behind a `wp_next_scheduled('anchor_events_status_sweep')` guard (NOT only on activation — plugin updates via Plugin Update Checker do not fire `register_activation_hook`, so already-active installs would otherwise never schedule it), AND on activation for fresh installs; clear on deactivation. **The module currently has no activation/deactivation hooks in `anchor-tools.php` — add them for this module (register the deactivation clear; confirm where the module is bootstrapped at priority 25 and wire `register_deactivation_hook`/the `init` guard there).**
  - **Bug #3 (free-path race):** rewrite `handle_registration()` (L2067) to call `claim_seats($event_id, $meta, 1, [...])` instead of the check-then-insert gap (L2110/L2121). Add `wp_unslash()` before all `$_POST` sanitize calls; capture `_anchor_event_phone`.
  - **Bug #4 (status vocab):** additive only; `confirmed` stays active; no migration.
  - **Bug #5 (wp_mail ignored):** add `Module::send_html_email($to,$subject,$html,$args): bool`; replace the two bare `wp_mail` calls in `send_registration_emails()` (≈L2903/L2910). Register `wp_mail_failed` once in `__construct`. Full email refactor lands in Phase 6 — here just capture+log the return value. **The third `wp_mail` at L1390 (password-reset flow) is intentionally out of scope** — it already checks its return and is a different feature (Finding #26).
  - **Bug #1 (export capability) — one-line fix now (Finding #22):** in the existing `handle_export()` (L2147/L2148), change the gate `manage_options` → `edit_others_posts`. This is trivial and independent of the Phase 5 Roster move; applying it here means the known capability bug does not persist through Phases 0–4. (The method itself is re-homed into `class-roster.php` in Phase 5.)
  - Refactor `get_attendee_count` (L2854) / `get_registration_status` (L2769) to **delegate** to `Registrations` (thin public wrappers). Delete the inline capacity check at ≈L2777–2783.
  - Promote to `public`: `get_meta` (L2477), `meta_key` (L2498), `clear_caches` (L2420), `get_settings` (L3018), `get_registration_status`, `send_registration_emails`, `build_registration_email_html`.
  - Add the render seam at top of `render_registration_form()` (L1983): `$override = apply_filters('anchor_events_registration_form','',$post_id,$meta); if ($override !== '') return $override;` (no consumers yet → inert).

**Risky / be careful**
- **Counting refactor must be behavior-preserving:** on a free-only site `count_reserved_seats` (confirmed+pending, but no pending ever exists) must return the *same integer* as today's `get_attendee_count`. Verify with a DB row count before/after.
- **GET_LOCK degradation:** `claim_seats` must degrade gracefully (single recount + `Events_Log::error('lock_unavailable')`) if `GET_LOCK` times out — never block a free signup.
- **Cron lifecycle:** the `init`/`admin_init` `wp_next_scheduled()` guard is the primary mechanism (survives upgrades); activation is a convenience. Verify the deactivation clear actually unschedules.

**Verify before moving on (Env-A, no WC)**
1. Free `[event_registration]` signup creates a `confirmed` seat with `_anchor_event_source=internal`, `_anchor_event_phone` populated, one history entry. (Matrix #17)
2. Capacity full → blocked; waitlist on → seat status `waitlist`. Concurrent double-submit (two browser tabs) never exceeds capacity. (Matrix #5 free analog)
3. Render the public archive + single event page; confirm **zero DB writes** during render (enable query logging / `SAVEQUERIES`). (Matrix #18, bug #2)
4. **Upgrade-path cron check (Finding #13):** simulate a plugin *update* on an already-active install (no activation hook fires) → confirm `anchor_events_status_sweep` is scheduled by the `init`/`admin_init` guard; deactivate → confirm it is cleared.
5. `O'Brien` as attendee name stores and displays correctly (wp_unslash). (Matrix #20)
6. Diff rendered HTML of archive/single/free-form against a pre-change baseline — identical.
7. Force a `wp_mail` failure (bad SMTP) → entry appears in `anchor_events_error_log`. (bug #5)
8. **Editor (edit_others_posts, not admin) can export CSV** — bug #1 fix verified now, before Phase 5. (Matrix #19)

**Acceptance criteria:** Free path unchanged in output; seats flow through `Registrations`; status vocabulary expanded with no migration; **bugs #1–#5 fixed and verified** on a non-WC site (bug #1 = one-line export-capability fix applied here; the `handle_export` method is re-homed in Phase 5); cron scheduled defensively on `init`/`admin_init` so it survives plugin upgrades; new files load with WC absent and cause no errors.

---

## Phase 1 — Product↔Event linking + read-only event mirror + resolver + WC-optional loader + HPOS declaration

**Goal:** Wire the WooCommerce class loader (gated), declare HPOS compat, and implement product→event linking (single source of truth on the product) with the read-only mirror on the event screen, plus the event resolver. **No checkout/seat logic, and — critically — NO free-form replacement yet.** The free `[event_registration]` form stays live for all events this phase.

> **Phasing safety (Finding #12 — was a near-BLOCKER):** Phase 1 must NOT register the `anchor_events_registration_form` filter callback that swaps the free form for a "Register — $price" button. If it did, linking a product on a live WC site would remove the free form while seat creation does not yet exist (Phase 2) — a completed purchase would create an order with no seat records and no attendee data (silent data loss). Therefore the form swap is **registered in Phase 2**, the same phase that adds checkout capture + seat creation, so a linked product never removes the free form before seats can be created. Phase 1 builds linking + mirror + resolver + WC-optional loader + HPOS declaration ONLY.

**Files to create**
- `anchor-events-manager/class-woocommerce.php` — `\Anchor\Events\WooCommerce`. Constructor `(Module $m, Registrations $r)`. In this phase it registers **only the linking admin hooks + the mirror lifecycle hooks**. It does **NOT** hook `anchor_events_registration_form` yet (deferred to Phase 2). Implements:
  - Meta keys `_anchor_evt_link_enabled`, `_anchor_evt_link_event_id` registered via `register_post_meta('product'…)` + `register_post_meta('product_variation'…)`, `auth_callback` requires `edit_products`.
  - Resolver `event_for_line($product_id,$variation_id=0): int` (+ `event_for_product`, `event_for_variation`, `products_for_event`, `event_is_linked`). Validates resolved id is `Module::CPT` and not trashed → else 0.
  - Admin UI: `woocommerce_product_data_tabs`, `woocommerce_product_data_panels`, `woocommerce_product_after_variable_attributes`; save via `woocommerce_admin_process_product_object` (no `$product->save()`) + `woocommerce_save_product_variation`. Panel note recommends disabling `manage_stock` on linked products so event capacity is the single authority (Finding #16).
  - Mirror: `rebuild_event_mirror($event_id)`; on any link/toggle write rebuild {old}∪{new} event ids. Lifecycle: product/variation save, toggle-off, `woocommerce_delete_product_variation`/`before_delete_post` for `product_variation`, product delete/trash.
  - **`filter_registration_form($html,$post_id,$meta)` is DEFINED but NOT yet hooked** (the `add_filter('anchor_events_registration_form', …)` call is added in Phase 2). This phase leaves the free form live for every event.

**Files to edit**
- `anchor-tools.php`: add **file-scope** `before_woocommerce_init` HPOS declaration (`FeaturesUtil::declare_compatibility('custom_order_tables', ANCHOR_TOOLS_PLUGIN_FILE, true)`). Self-no-ops without WC.
- `anchor-events-manager.php`: in `__construct`, add the `if (class_exists('WooCommerce')) { require_once …; $this->woocommerce = new WooCommerce($this,$this->registrations); }` block. Keep `$this->woocommerce` null otherwise; never dereference it directly.
- `render_registrants_metabox()` (L562): when `class_exists('WooCommerce')` and mirror non-empty, render read-only "Registers via: Product (#id) → Variation 'X' (#id)" with edit links (guard trashed → "(product removed)"), plus a note that the public free form **will be** replaced by WooCommerce once checkout is live (Phase 2). Also note the recommendation to disable product `manage_stock` (Finding #16). No form inputs. Capacity/waitlist fields stay editable.

**Risky / be careful**
- **Load-order / HPOS:** declaration must be file-scope in `anchor-tools.php`, NOT in the priority-25 bootstrap (`before_woocommerce_init` can fire first).
- **Mirror integrity:** capture *old* meta before writing new, or rebuild misses the de-linked event. Event delete must NOT touch product meta (resolver validation handles stale ids → 0).
- **`class_exists('WooCommerce')` gate** at bootstrap priority 25 is race-free (WC main class loaded by then) — confirm bootstrap priority is 25 in `anchor-tools.php`.

**Verify before moving on**
- Env-B: Simple product → pick one event; toggle persists; event screen shows correct "Registers via" mirror. (Matrix linking AC)
- Env-B: Variable product → per-variation event selects save independently; mirror lists each variation. Editing the product updates the mirror; editing the event never writes link meta.
- Env-B: Two products → same event both appear in mirror. Trash a product → mirror removes it. Delete the event → product meta intact, resolver returns 0.
- Env-B: **Linked event front-end STILL shows the free `[event_registration]` form** (the Register button is NOT activated until Phase 2). Resolver returns the correct event id for the linked product/variation. (Finding #12 — verify the free form is intact.)
- Env-A + Env-B: WooCommerce admin shows **no HPOS incompatibility warning**; toggle HPOS on/off with no errors. (Matrix #15)
- Env-A: nothing changed; `class-woocommerce.php` never required.

**Acceptance criteria:** Linking is product-owned with a read-only event mirror; the resolver works; multiple products can map to one event; HPOS declared with no warnings; WC loader gated. **The free form is unchanged on every event — no form swap is registered this phase** (Finding #12); free/unlinked behavior untouched.

---

## Phase 2 — Form swap activation + checkout attendee capture + seat creation on processing/completed + capacity block

**Goal:** Activate the free-form replacement (Register button) for linked events, collect per-seat attendee details on the classic checkout page, persist them to the order line item, and create seats when the order reaches `processing`/`completed`. Enforce the three-layer capacity defense. This is the first end-to-end paid purchase. Because the form swap and seat creation land together, a linked product never removes the free form before seats can be created (Finding #12).

**Files to edit**
- `anchor-events-manager/class-woocommerce.php`:
  - **Activate the form swap (Finding #12):** add `add_filter('anchor_events_registration_form', [$this,'filter_registration_form'], 10, 3)`. `filter_registration_form` renders the add-to-cart "Register — $price" / "Sold out" / "Join waitlist — $price" button (class `.anchor-event-register`) for linked events; returns `''` for unlinked events so the free form renders.
  - Cart inspector `get_event_cart_lines(): array` (keyed by `cart_item_key`, uses §5.2 resolver + master toggle).
  - Render: `render_checkout_attendee_fields()` on `woocommerce_checkout_after_customer_details` (pri 10). Field name `anchor_attendees[<cart_item_key>][<seat_index>][name|email|phone]`. One fieldset per line, qty seat blocks, all required, repopulate from `$_POST` on failure.
  - Validate: `validate_checkout_attendees($data,$errors)` on `woocommerce_after_checkout_validation`. **Classic-checkout block-checkout guard first** (`has_block('woocommerce/checkout', wc_get_page_id('checkout'))` → fail-closed here). Re-derive counts from cart, per-seat sanitize (`wp_unslash`→`sanitize_text_field`/`sanitize_email`+`is_email`), unique `$errors->add()` per bad seat. **Capacity re-check:** aggregate requested per event across lines vs `remaining_capacity`; waitlist off → error with exact remaining; waitlist on → allow + record overflow intent.
  - **Store-API / block-checkout server-side guard (Finding #2 — BLOCKER):** the classic `woocommerce_after_checkout_validation` hook does NOT fire for the Checkout block / Store API, so the `has_block()` notice alone is ineffective. Add a guard that actually runs on block placement — hook `woocommerce_store_api_checkout_update_order_from_request` (or `woocommerce_blocks_checkout_order_processed`) and throw a `RouteException` when event lines are present. **AND** — checkout-type-agnostic backstop — in `reconcile_order`, when a paid event line item has NO `_anchor_attendees` meta at all, do **NOT** silently billing-fallback: set needs-review (`reason=attendees_missing`) so the failure is visible (this also covers admin-created / "pay for order" / Subscriptions orders — Finding #17). Combine with hiding/blocking purchasability when the checkout page is a block checkout.
  - Persist: `persist_attendees_to_line_item($item,$cart_item_key,$values,$order)` on `woocommerce_checkout_create_order_line_item`. Re-sanitize from `$_POST`, write `_anchor_attendees` array + link snapshot (`_anchor_event_id`,`_anchor_product_id`,`_anchor_variation_id`) + optional `_anchor_seats_over_capacity`. **No seat posts created here.** (Non-classic paths have no `$_POST` and produce no `_anchor_attendees` → caught by the `attendees_missing` needs-review rule above — Finding #17.)
  - Status sync (subset this phase): `reconcile_order(\WC_Order $order, string $reason='')` — single mutation entry point. Hook `woocommerce_order_status_changed` (pri 10, switch on `$to`) **and `woocommerce_payment_complete` → reconcile_order** (Finding #4 — idempotent insurance for gateways/"mark as paid" flows where status_changed is unreliable; double-fire is harmless given seat_index gap dedupe). This phase handles **processing → confirmed** and **completed → confirmed** only (full map in Phase 3). Per-line: read `_anchor_attendees`, compute expected qty, `claim_seats()` under lock to create `confirmed` (or `waitlist`) seats with idempotency key `(order_item_id, seat_index)`. **Skip any line with `order_item_id <= 0`** (Finding #11). Re-entrancy static in-flight guard.
  - Purchasability gate: `woocommerce_is_purchasable` / `woocommerce_variation_is_purchasable` → false when sold out + waitlist off; `woocommerce_add_to_cart_validation` rejects stale add. Button label "Register — $price" / "Sold out" / "Join waitlist — $price". **Surface a clearer `wc_add_notice` when WC silently removes a now-unpurchasable product from an existing cart** (Finding #16).
- New asset: `anchor-events-manager/assets/js/checkout-attendees.js` — enqueue on checkout, re-bind on `updated_checkout` event. Load via `\Anchor_Asset_Loader::url(...)`.

**Risky / be careful**
- **Guest checkout:** `customer_id = $order->get_customer_id()` (0 for guests, NEVER current user); contact = `$order->get_billing_email()`. Never stash attendee data in `WC()->session` past order creation.
- **Idempotency:** re-firing `processing` (e.g. processing→completed) must not duplicate — the per-item existence check on `(order_item_id, seat_index)` across **all statuses** runs inside the lock (Finding #9); existing seats at target are skipped.
- **Capacity race (the real defense, Finding #9):** seat creation recount + per-item existence check both happen **inside** the `GET_LOCK` in `claim_seats`. The static in-flight guard is per-process only and does nothing cross-process — the event lock is the only real cross-worker guard. If seats vanished mid-checkout and waitlist off → buyer already paid → create up to remaining as confirmed, surplus waitlist-if-enabled-else-uncreated, set `_anchor_event_needs_review` (`capacity_overfill`) + log. Do NOT reject in a status hook. **When `GET_LOCK` is unavailable/times out (Finding #5):** after the non-atomic recount, also set needs-review `reason=capacity_lock_unavailable` on any order that created seats while the lock was down, so an admin can audit potential overfill. Keep graceful-degrade (never block a paid order).
- **Block checkout:** must fail-closed via the Store-API guard, never produce a seat-less paid order; the `attendees_missing` needs-review rule is the backstop. (Matrix #16)
- **HPOS:** all order/item reads via CRUD (`$item->get_meta`, `$order->get_*`), zero postmeta.

**Verify before moving on (Env-B, HPOS on & off)**
1. Buy 1 seat (simple, cap 10) → 1 confirmed seat, all order/seat meta set, reserved=1. (Matrix #1)
2. Buy 3 as guest → 3 confirmed, seat_index 1/2/3, customer_id=0, billing email contact. (Matrix #2)
3. Seat-2 email blank/invalid → placement blocked, clear per-seat error, no order/seats. (Matrix #3)
4. Cap 2 full, waitlist off → add-to-cart disabled/"Sold out"; forced checkout blocked "Only 0 seats remain". (Matrix #4)
5. Two concurrent checkouts, cap 1 → exactly 1 confirmed, other blocked (or waitlisted if on); never over capacity. (Matrix #5)
6. Waitlist on over capacity → seat `waitlist`, counted separately, buyer charged. (Matrix #6)
7. **Block-checkout page with event line → Store-API guard throws / placement blocked; no fatal, no seat-less order** (Finding #2). Separately, an event line that somehow reaches `processing` with NO `_anchor_attendees` → order flagged needs-review `attendees_missing`, not silently billing-filled. (Matrix #16)
8. Linked event front-end now shows the Register button (form swap activated this phase); unlinked event still shows the free form (Finding #12). (Matrix #14 partial)
9. Repeat #1 with HPOS toggled → identical, no postmeta reads. (Matrix #15)

**Acceptance criteria:** Form swap activated alongside seat creation (no window where a linked product loses the free form without seat capture — Finding #12); one seat per paid seat with full meta; per-seat required attendee capture validated server-side incl. guests; block/Store-API placement fails closed and missing-attendee lines are flagged not billing-filled; seats created idempotently on processing/completed (and on `woocommerce_payment_complete`); three-layer capacity defense holds under concurrency with lock-degradation surfaced as needs-review; HPOS-safe.

---

## Phase 3 — Full lifecycle sync + idempotent resync + manual button + order-edit reconcile

**Goal:** Complete the order-status → seat-status map, make `reconcile_order` fully declarative/idempotent for all statuses and manual order edits, add order trash/delete capacity release, and add the manual "Resync order" admin button with a per-order sync log.

**Files to edit**
- `anchor-events-manager/class-woocommerce.php`:
  - `map_order_status_to_seat(string): ?string` — full table (§7.3): on-hold→pending, pending→null (sweep active→cancelled), failed→failed, cancelled→cancelled, refunded→refunded (terminal statuses force expected=0).
  - Flesh out `reconcile_order` per §7.5 algorithm (corrected):
    - **Surplus = (count of ACTIVE seats) − expected, cancelled NEWEST-first by integer `seat_index` DESC (Finding #6 + #19).** Do NOT threshold on `seat_index > expected` — once an earlier seat is cancelled/refunded the active indices are non-contiguous, so a positional threshold cancels the wrong count. Take active seats ordered by `CAST(seat_index AS UNSIGNED)` DESC and cancel exactly `surplus_count` of them.
    - **Gap-fill considers seats of ANY status at `(order_item_id, seat_index)` (Finding #7), not just active.** For an index occupied by a `cancelled`/`failed` seat → **revive via `update_status`** (allowed transition) instead of creating a duplicate. For `refunded` (terminal, never revive) → skip that index and allocate at `max(existing_index)+1`. This implements the cancelled→confirmed revival §4.3/§16 promise and prevents two rows sharing one idempotency key.
    - **Variation-change-in-place mismatch handling (Finding #10):** treat any existing seat whose `_anchor_event_id != resolve_event_for_item(item)` as a mismatch — cancel it on the OLD event (release that capacity, history note) and gap-fill a fresh seat on the NEW event. Do not assume WC always delete+recreates the item.
    - **Skip lines/seats with `order_item_id <= 0`** (Finding #11).
    - Seats already at target are skipped (no history spam). Attendee fallback to billing identity with history note ONLY when `_anchor_attendees[seat_index]` is present-but-partial; a line with **zero** `_anchor_attendees` raises `attendees_missing` needs-review instead of billing-filling (Finding #2/#17).
  - `expected_qty_for_item` = `max(0, qty - abs(get_qty_refunded_for_item))` (refund-safe; `abs()` always — version-dependent sign).
  - `resolve_event_for_item` (variation→else product→ else 0; unmapped line left untouched + logged `unmappable_line`).
  - Hooks: `woocommerce_order_status_changed` (primary, Phase 2) and `woocommerce_payment_complete` (Phase 2); `woocommerce_saved_order_items` → `on_saved_order_items`; `woocommerce_before_delete_order_item` → `on_delete_order_item`; `admin_post_anchor_event_resync_order` → `handle_resync_order`.
    - **`woocommerce_update_order` is NOT a blanket reconcile trigger (Finding #3).** It fires on essentially every `$order->save()` — including reconcile's own end-of-pass save (re-entrancy / save loop) and mid-checkout while status='pending'. Either **drop it entirely** (status changes covered by `order_status_changed`/`payment_complete`; item edits by `saved_order_items`) or gate it hard (only reconcile when `get_status()` is in the active/reserving set AND not currently within checkout). The static in-flight guard must wrap the **final batched `$order->save()`** (acquire at entry, release in `finally` AFTER the save), and `$order->save()` must never run inside the per-line loop.
    - **Order trash / delete capacity release (Finding #8):** register `woocommerce_before_trash_order` / `woocommerce_before_delete_order` (HPOS) + `before_delete_post` for legacy `'shop_order'` + `woocommerce_trash_order` → transition all that order's non-terminal seats to `cancelled` (kept, history note "order trashed/deleted"). Capture seats by `_anchor_event_order_id` while the order id is still known (after deletion `wc_get_order` returns false and reconcile early-returns, leaking capacity).
  - **Hook argument shapes (Finding #14):** `woocommerce_before_delete_order_item` passes an **int item_id** (NOT an item object) — query seats by `_anchor_event_order_item_id` using that int, never call `->get_id()`/`->get_meta()` on it. `woocommerce_saved_order_items` passes `($order_id, $items)`; `woocommerce_new_order`/`woocommerce_update_order` pass `($order_id, $order)` id-first (older WC passed only the id). Every handler **re-fetches via `wc_get_order($id)`** defensively and returns on falsy. Add a code comment documenting each hook's real signature.
  - Order meta box `register_order_metabox` (`add_meta_boxes` pri 30, HPOS screen id `wc_get_page_screen_id('shop-order')` + legacy `'shop_order'`): per-line seat summary, sync log, needs-review banner, nonced Resync form (`check_admin_referer('anchor_event_resync_'.$order_id)`, cap `edit_others_posts`).
  - Sync-log: write order meta `_anchor_event_sync_log` (capped ~50) once per reconcile pass; single `$order->save()` at end of pass (never inside per-line loop), inside the in-flight guard (Finding #3).

**Risky / be careful**
- **Re-entrancy (Finding #3):** `woocommerce_update_order` either dropped or hard-gated; static in-flight set keyed by order id wraps the one batched `$order->save()`.
- **Idempotency:** running Resync twice = zero changes. Surplus cancellation prefers **highest integer seat_index** and is computed as a COUNT, not a threshold (Finding #6).
- **Variation change:** both delete+recreate (handled by `before_delete_order_item` + gap-fill) AND in-place mutation (handled by the `_anchor_event_id` mismatch rule, Finding #10) converge correctly. Verify the seat "moves" to the correct event and the old event's capacity is released.
- **Gap-fill revival (Finding #7):** verify a qty re-bump after a cancel revives the cancelled seat at the same index rather than creating a duplicate; a refunded index is skipped and a new index allocated.
- **on-hold→processing:** pending seats must flip to confirmed (same seats, no duplication), history shows both.
- **Manual line removal:** seats cancelled on `before_delete_order_item` using the int item_id (Finding #14).

**Verify before moving on (Env-B, HPOS on & off)**
1. on-hold → pending seats (reserve); → processing flips same seats to confirmed, no dup, history both. (Matrix #12)
2. failed → failed (hold released); cancelled → cancelled (kept); reserved count drops. (Matrix #13)
3. Manual order edit: add line / qty up / qty down / remove line → add missing (revive cancelled index where present) / cancel surplus_count newest-first / no duplicates; running twice = no change. (Matrix #10)
4. Variation A→B via delete+recreate AND (if reproducible) in-place edit → old seats cancelled / capacity released on A, new seats against B's event. (Matrix #11, Finding #10)
5. **Trash an order with confirmed seats → those seats transition to `cancelled` and capacity is released** (Finding #8); permanently delete → same. Remaining-count math stays correct.
6. **Qty re-bump after a partial cancel revives the cancelled seat at its index (no duplicate at same `(order_item_id, seat_index)`)** (Finding #7).
7. Manual "Resync order" button → converges; needs-review cleared on clean resync; cap `edit_others_posts` enforced.
8. HPOS on & off identical. (Matrix #15)

**Acceptance criteria:** Full status map honored exactly; surplus computed as a count and cancelled newest-first (no positional-threshold bug); gap-fill revives terminal-but-revivable seats and never duplicates an idempotency key; in-place variation changes release old-event capacity; order trash/delete releases capacity; `woocommerce_update_order` not used as a blanket trigger; one idempotent `reconcile_order` for all hooks + manual button + order edits; converges identically on repeat; per-order sync log written.

---

## Phase 4 — Refunds (full / partial line / amount-only needs-review)

**Goal:** Route refunds through the same `reconcile_order` by adjusting expected qty, and detect amount-only refunds for needs-review (never guess).

**Files to edit**
- `anchor-events-manager/class-woocommerce.php`:
  - Hook `woocommerce_order_refunded($order_id,$refund_id)` → `on_order_refunded` → normalize via `wc_get_order` → reconcile. Full refund also drives `woocommerce_order_status_refunded` (idempotent double-fire).
  - `classify_refund($order,$refund_id): string` (`line|amount_only|mixed`) — load `wc_get_order($refund_id)`, iterate `$refund->get_items()`; map back via `_refunded_item_id` (fallback product/variation match).
    - **Refund line-item quantities are NEGATIVE (Finding #1 — BLOCKER).** `$refund_item->get_quantity()` returns `-1`, `-2`, … A test of `qty > 0` is NEVER true, so EVERY refund would be misclassified as `amount_only` and no seat would ever be refunded. **Detect line refunds with `abs($refund_item->get_quantity()) > 0`** (equivalently `get_quantity() != 0` / `< 0`). `amount_only` is the branch where every refund item has zero qty but `$refund->get_amount() > 0`. This is the same negative-sign trap already caught for `get_qty_refunded_for_item`.
  - Decision: `amount_only` → `Events_Log::flag_review($order,'amount_only_refund')` + sync-log + admin notice, change no seats. `line`/`mixed` → reconcile each line to new cumulative expected; surplus active → `refunded` newest-first (integer seat_index DESC, count-based per Finding #6), kept. `mixed` extra amount → also needs-review.

**Risky / be careful**
- **Cumulative qty:** always recompute from total refunded via `abs(get_qty_refunded_for_item)` — multiple partials monotonically lower expected; re-fired hook = no change.
- **`_refunded_item_id` may be absent on some WC versions** → product/variation fallback is approximate when same product on multiple lines (rare; log it).
- **Never hard-delete** refunded seats; status `refunded` is terminal (resync never auto-revives; gap-fill skips refunded indices per Finding #7).
- **Un-linked-after-purchase:** seats still found via `_anchor_event_order_item_id`; log, don't error.

**Verify before moving on (Env-B, HPOS on & off)**
1. **3 confirmed, refund qty 1 → a seat ACTUALLY transitions to `refunded` (Finding #1):** expected 2, newest (index 3) → `refunded` (kept), 2 confirmed, remaining +1, history appended. Assert the seat status is `refunded`, NOT that it merely landed in needs-review. Confirm with WC's negative refund quantities (`get_quantity()` < 0). (Matrix #7)
2. Full refund → all seats refunded, kept; re-fire idempotent. (Matrix #8)
3. Amount-only refund → no seat change, order needs-review, admin notice, sync-log "not guessed". (Matrix #9)
4. Two sequential partial refunds → expected drops monotonically, correct surplus_count cancelled.

**Acceptance criteria:** Partial LINE refund actually transitions a seat to `refunded` (negative-qty classification correct — Finding #1); full→all refunded; partial line→reduce by refunded qty newest-first; amount-only→needs-review with no seat change; cumulative and re-fire-safe; refunded seats preserved.

---

## Phase 5 — Roster admin screen + CSV export (shared free & paid)

**Goal:** Add the roster screen and CSV export, loaded unconditionally, with consistent `edit_others_posts` capability. Re-home the `handle_export()` method into the Roster class (the capability fix itself already shipped in Phase 0) and add CSV formula-injection hardening.

**Files to create**
- `anchor-events-manager/class-roster.php` — `\Anchor\Events\Roster`. `const CAP = 'edit_others_posts'`. Submenu under Event CPT (`anchor-event-roster`). `roster_url($event_id,$args=[])` nonced link builder. `WP_List_Table` subclass with columns cb/attendee/email/phone/status pill/guests/source/order/seat/date; filters status/s/source/paged/orderby/order (all `wp_unslash`+sanitized). Header summary from `Registrations::get_event_summary($event_id)`. Manual seat actions via `admin-post.php`: `anchor_roster_add`, `anchor_roster_edit`, `anchor_roster_cancel`, `anchor_event_export` (all cap-checked + nonced, delegate to layer — never write seat meta directly). CSV `handle_export` (moved from Module, already carrying the `edit_others_posts` gate from Phase 0) with `csv_safe($v)` formula-injection prefix, streams via `php://output`.
- Roster CSS/JS assets if needed under `assets/`.

**Files to edit**
- `anchor-events-manager.php`:
  - Instantiate `$this->roster = new Roster($this)` in `__construct` (always, both envs) — and `require_once` `class-roster.php` here (this is where the Phase 0 loader stub omitted it — Finding #25).
  - **Re-home `handle_export()`:** remove the method (and its `admin_post_anchor_event_export` hook registration) from Module; ownership moves to Roster. (The `manage_options`→`edit_others_posts` capability change already landed in Phase 0 per Finding #22 — this step is just the move.)
  - Add roster entry points: event metabox "Open full roster" link, `post_row_actions` "Roster" on Events list.
- `class-registrations.php`: add `query_seats([...]) → {items: Seat_DTO[], total}` (all meta_query with `'type'=>'NUMERIC'` on `_anchor_event_id`, `CAST(seat_index AS UNSIGNED)` ordering — Finding #19, batched `update_meta_cache`, no N+1), `get_event_summary($event_id)`, `get_export_rows($event_id,$scope)` (batched `wc_get_orders(['include'=>$ids])` guarded by `function_exists`).
- `class-woocommerce.php`: add "View roster" link from order panel; roster order column links to `admin.php?page=wc-orders&action=edit&id=NN` (HPOS) with `get_edit_post_link` fallback.

**Risky / be careful**
- **Order lookups guarded:** Env-A has no `wc_get_orders` — wrap in `function_exists`. Free seats show blank order columns, source `internal`.
- **CSV injection:** `csv_safe` applied to every data cell (name/email/phone/custom fields are public input).
- **Search by order number (Finding #15):** if the search term is numeric, prefer `wc_get_order($term)` / `['post__in'=>[$term]]` (HPOS-aware exact lookup) → seat `_anchor_event_order_id IN(…)`; only fall back to the fuzzy `['search'=>…]` for non-numeric terms. Guard all behind `function_exists('wc_get_orders')`.
- **Manual Add** routes through `claim_seats` under lock (capacity honored; waitlist if on else block with remaining).

**Verify before moving on**
1. Env-A + Env-B: roster filters by status, searches name/email/order, shows capacity vs reserved, order links. (Roster AC)
2. Env-A: log in as **Editor** (edit_others_posts, not admin) → Export CSV downloads, no "Unauthorized". (Matrix #19, bug #1 — already fixed Phase 0, re-confirm after the move)
3. CSV contains order/payment + attendee (incl. phone) + source columns + dynamic custom-field union; free seats blank Woo columns. Cell `=cmd()` is prefixed `'`. (Matrix #20, CSV AC)
4. Numeric order search returns the exact order's seats (not fuzzy matches); non-numeric search falls back to `search` (Finding #15).
5. Manual Add/Edit/Cancel from roster honor capacity + write history; Woo seats show order fields disabled.

**Acceptance criteria:** Roster works for free + paid; one `edit_others_posts` cap for view and export; `handle_export` re-homed to Roster (capability already correct since Phase 0); CSV active-only or all, formula-injection-safe, complete columns; numeric order search is exact; manual seat actions route through the layer.

---

## Phase 6 — Emails + per-order sync log / needs-review notices + error log

**Goal:** Send organizer + one-per-order customer confirmations from the confirmed sync path, refactor the email template, capture failures, and surface the per-order sync log, needs-review notices, and the site-wide email-failure error log. **The per-event activity log + Activity panel + Diagnostics subsection are DEFERRED (Finding #20) — do NOT build them in MVP.**

> **Scope correction (Finding #20):** the confirmed MVP lists only a per-order sync log + needs-review admin notices (and the error log that backs email-failure logging). The per-event activity log (`_anchor_event_activity`, capped ~100), its "Activity" sub-panel on the event registrants metabox, and a "Diagnostics" settings subsection are net-new features NOT in the confirmed decision list. They are moved to deferred. The data model may reserve `_anchor_event_activity` so a future build is not precluded, but no panel/subsection is built now. Phase 6 builds only: per-order sync log, needs-review notices, and the email-failure error log.

**Files to edit**
- `anchor-events-manager.php`:
  - Refactor `build_registration_email_html()` (L2914) to a `$ctx` array (`event_id,name,status,intro_message,guests,detail_rows[],seat_list[],cta_label,cta_url`) + back-compat shim mapping the free path's positional args (so `handle_registration` signature unchanged). Preserve `anchor_events_registration_email_html` filter (passed `$ctx`). Add `expand_email_tokens($template,$tokens)`.
  - Settings: add `wc_notify_customer`, `wc_notify_organizer`, `organizer_email`, `wc_customer_subject`, `wc_customer_intro`, `wc_organizer_subject`, reserved `notify_attendee` to `get_settings` defaults (L3018), `register_settings`, `sanitize_settings`. Woo subsection rendered only when `class_exists('WooCommerce')`.
  - `send_html_email` (added Phase 0) now used by all email sends; on `false` → `Events_Log::error('email_failed')` + sync-log + (customer mail on paid order) needs-review `customer_email_failed`.
- `class-woocommerce.php`:
  - From `reconcile_order` confirmed pass (after payment, never line-item creation): customer confirmation ONE per order (gated by `_anchor_event_emails_sent` `'customer:{event_id}'`), organizer notice one per order per event (recipient: per-event `organizer_email` → global → `admin_email`). Trigger matrix §11.4. Single `$order->save()` at end of pass (inside the in-flight guard — Finding #3).
  - Needs-review surfaces: `admin_notices` on Events list / WC Orders / Events settings tab. **Flagged orders queried with `['meta_query'=>[['key'=>'_anchor_event_needs_review','compare'=>'EXISTS']]]`** (HPOS-aware) — NOT the unsupported bare `'meta_key'` shorthand (Finding #15); guarded by `function_exists('wc_get_orders')`. Order panel "Mark reviewed" + "Resend confirmation". New nonced actions (cap `edit_others_posts`): `anchor_events_resend_confirmation`, `anchor_events_clear_review`, `anchor_events_clear_error_log`.
- `class-events-log.php`: **only** the site-wide error log (`anchor_events_error_log`) surface needed for MVP. (Per-event activity log + Activity sub-panel + Diagnostics subsection are deferred — Finding #20.)

**Risky / be careful**
- **Email idempotency:** confirmations sent exactly once; NOT resent on later partial refunds; resend only via explicit admin button.
- **Emails fire from the status-sync confirmed path, never from `woocommerce_checkout_create_order_line_item`** (order still pending there).
- **Free path emails** keep working via the back-compat shim — verify no signature break.

**Verify before moving on**
1. Env-B: processing/completed → buyer gets ONE confirmation listing all seats; organizer gets one notice per event with new remaining. (Matrix #1,#2; Emails AC)
2. Cancelled/refunded → organizer "seats released" notice; buyer not re-spammed.
3. Waitlist seat → customer waitlist-variant email; organizer flagged waitlist.
4. Force `wp_mail` failure on customer mail → logged + needs-review `customer_email_failed`; admin notice appears.
5. Per-order sync log shows entries each pass; needs-review notices appear on Events list/Orders/settings (queried via `meta_query EXISTS`) and clear on "Mark reviewed"/clean resync.
6. Env-A: free signup emails still send via shim; failures logged.

**Acceptance criteria:** One-per-order customer confirmation + organizer notice from confirmed path; idempotent; `wp_mail` failures captured and logged; per-order sync log, site-wide error log, and needs-review notices surfaced (queried HPOS-safe via `meta_query`); per-event activity log / Activity panel / Diagnostics subsection NOT built (deferred — Finding #20); settings gated to WC presence.

---

## Final regression check (run after Phase 6, both environments)

**Backward-compat (Env-A, no WooCommerce):**
- Confirm no `woocommerce_*` hook is registered (`class-woocommerce.php` never required); `anchor_events_registration_form` has zero callbacks → free form renders via the unchanged decision tree.
- Free signup + event display match the pre-project byte-level baseline (archive, single, free-form submission) — diff captured in Phase 0.
- Active status still `confirmed`; existing 6 seat meta keys identical semantics; no migration ran; legacy rows read `source=internal`.
- Capacity math collapses to confirmed-only counts (single-status counts return identical numbers to original `get_attendee_count`).
- Render path performs **no DB writes** (bug #2 stays fixed).
- `anchor_events_status_sweep` cron survives a plugin update (scheduled via the `init` guard, not just activation) — Finding #13.
- Editor can export CSV (bug #1 stays fixed since Phase 0); `O'Brien` round-trips; `=cmd()` is escaped.

**HPOS (Env-B):** declared, no incompatibility warning; rows #1/#7/#10 identical with HPOS on and off; zero direct order postmeta reads/writes (grep the WC class for `get_post_meta`/`update_post_meta` on order ids — must be none); needs-review queries use `meta_query` not the bare `meta_key` shorthand.

**Idempotency sweep:** run "Resync order" on every test order twice — zero diffs on the second run. Confirm no duplicate seat ever shares a `(order_item_id, seat_index)` (Findings #7/#18).

**Sign-off:** all 20 matrix rows (§15.2) pass in both environments before release. Bump `Version:` in `anchor-events-manager.php`/`anchor-tools.php`, commit, push, create GitHub release per CLAUDE.md release process.

---

**Cross-cutting risk callouts (watch in every phase that touches them):**
- **Race:** all seat creation (free + paid + manual) goes through `claim_seats` under `GET_LOCK`; recount AND per-item existence check both inside the lock hit the DB directly, never the cache (Finding #9). Lock degradation → needs-review `capacity_lock_unavailable` (Finding #5).
- **Idempotency:** `(order_item_id, seat_index)` is the dedupe key (meaningful only for `source=woocommerce`; no-op when `order_item_id <= 0` — Finding #11); checked across ALL statuses (Finding #7/#18); `reconcile_order` is the single mutation entry point; static in-flight guard prevents same-process re-entrancy and wraps the one batched `$order->save()`; the event lock is the only cross-process guard.
- **HPOS:** all order access via CRUD; HPOS declaration at main-file scope; needs-review queried via `meta_query EXISTS`.
- **Guest checkout:** `customer_id` from `$order->get_customer_id()` (0 = guest), never current user; contact = billing email; no session reliance post-order.
- **WC-optional:** every `woocommerce_*` hook lives only in the gated `class-woocommerce.php`; core file references WC solely through the `anchor_events_registration_form` filter (callback registered in Phase 2, not Phase 1 — Finding #12).
- **Hook signatures (Finding #14):** `before_delete_order_item` → int item_id; `saved_order_items` → `($order_id,$items)`; `new_order`/`update_order` → id-first; always re-fetch via `wc_get_order($id)` and return on falsy.

---

## Review corrections applied

Plan-relevant findings and how each was resolved in this final plan:

- **#1 (BLOCKER, Phase 4):** Refund line quantities are negative; classification now uses `abs(get_quantity()) > 0`. Phase 4 verify step #1 asserts a partial LINE refund actually transitions a seat to `refunded` (using WC's negative refund quantities), not just lands in needs-review.
- **#2 (BLOCKER, Phase 2):** Added a Store-API/block-checkout server-side guard (`woocommerce_store_api_checkout_update_order_from_request` / `woocommerce_blocks_checkout_order_processed`) plus the reconcile rule "paid event line with zero `_anchor_attendees` → needs-review `attendees_missing`" (no silent billing-fill). Matrix test #16 covers block checkout.
- **#3 (MAJOR, Phase 3):** `woocommerce_update_order` removed as a blanket reconcile trigger (or hard-gated); in-flight guard explicitly wraps the single end-of-pass `$order->save()`; never saves inside the per-line loop.
- **#4 (MAJOR, Phase 2/3):** Also hook `woocommerce_payment_complete → reconcile_order` as idempotent insurance.
- **#5 (MAJOR, Phase 2):** GET_LOCK degradation surfaced as needs-review `capacity_lock_unavailable`; still never blocks a paid order.
- **#6 (MAJOR, Phase 3/4):** Surplus computed as `(active count) − expected`, cancelled newest-first by integer seat_index DESC — no positional `seat_index > expected` threshold.
- **#7 (MAJOR, Phase 3):** Gap-fill considers seats of ANY status at `(order_item_id, seat_index)`; revives `cancelled`/`failed` in place, skips `refunded` and allocates `max+1` — no duplicate idempotency keys; implements the cancelled→confirmed revival.
- **#8 (MAJOR, Phase 3):** Registered order trash/delete hooks (`before_trash_order`/`before_delete_order`/legacy `before_delete_post`/`trash_order`) to release capacity by `_anchor_event_order_id` before the order disappears.
- **#9 (MAJOR, Phase 2):** Per-item existence check + capacity recount both run inside the event lock; documented that the static in-flight guard is per-process only and the event lock is the sole cross-process guard.
- **#10 (MAJOR, Phase 3):** In-place variation change handled — seat whose `_anchor_event_id != resolve_event_for_item` is cancelled on the old event and re-created on the new event.
- **#11 (MAJOR, Phase 0/2/3):** `order_item_id <= 0` short-circuits in `get_seats_for_order_item` and all dedupe; reconcile skips such lines; idempotency key meaningful only for `source=woocommerce`.
- **#12 (BLOCKER-adjacent phasing):** Phase 1 no longer activates the free-form replacement / Register button — it builds linking + read-only mirror + resolver + WC-optional loader + HPOS declaration only, and the free form stays live. The `anchor_events_registration_form` filter callback is registered in Phase 2 alongside checkout capture + seat creation. Phase 1 verify step updated (no "front-end shows Register button"; instead confirms the free form is intact).
- **#13 (MAJOR, Phase 0):** `anchor_events_status_sweep` scheduled defensively on `init`/`admin_init` behind a `wp_next_scheduled()` guard (survives plugin updates) plus activation; cleared on deactivation. Added an upgrade-path verify step. Noted the module currently has no activation/deactivation hooks in `anchor-tools.php` and where to add them.
- **#14 (MINOR, Phase 3/4):** Documented real hook signatures — `before_delete_order_item` passes int item_id; `saved_order_items`/`new_order`/`update_order` are id-first; always re-fetch via `wc_get_order()`.
- **#15 (MINOR, Phase 5/6):** Needs-review query uses `meta_query EXISTS` (not bare `meta_key`); numeric roster order search uses exact `wc_get_order`/`post__in`, fuzzy `search` only for non-numeric; all guarded by `function_exists('wc_get_orders')`.
- **#16 (MINOR, Phase 1/2):** Linked products should disable `manage_stock` (noted in product panel + event mirror); cart-removal on lost purchasability surfaced via a clearer `wc_add_notice`.
- **#17 (MINOR, Phase 2/3):** Non-classic order paths (admin/"pay for order"/Subscriptions/Store API) produce no `_anchor_attendees` → reconcile raises `attendees_missing` needs-review rather than silently billing-filling.
- **#18 (MINOR, Phase 0):** `claim_seats`/`create_seat` self-defense — assert no existing seat (any status) at `(order_item_id, seat_index)` before insert; otherwise log `duplicate_seat_prevented` + skip.
- **#19 (MINOR, Phase 0/3/5):** seat_index cast to integer everywhere it is ordered/compared (`CAST(... AS UNSIGNED)` / `(int)`), consistent with `_anchor_event_id` NUMERIC handling.
- **#20 (MINOR scope, Phase 6):** Per-event activity log + Activity panel + Diagnostics subsection MOVED to deferred; Phase 6 builds only the per-order sync log, needs-review notices, and the email-failure error log.
- **#21 (MINOR, Phase 0):** `RESERVING_STATUSES = ['confirmed','pending']` only; `attended` explicitly NOT counted in MVP.
- **#22 (MINOR, Phase 0):** One-line export-capability fix (`manage_options → edit_others_posts` in the existing `handle_export()`) applied in Phase 0; `handle_export` re-homed into `class-roster.php` in Phase 5. Phase 0 acceptance wording corrected to "bugs #1–#5".
- **#23 (NIT, Phase 0):** Legacy seats get a synthesized backfill history entry on first `update_status`.
- **#25 (NIT, Phase 0):** Phase 0 loader block made explicit — omits `class-roster.php` require and `new Roster()` until Phase 5 (so the spec §3 full snippet is not pasted verbatim).
- **#26 (NIT, Phase 0):** Noted the L1390 password-reset `wp_mail` is intentionally out of scope for the registration-email centralization.

(Findings #24 (spec wording note on post-payment overfill) and the spec-only halves of #21 are spec-level clarifications; reflected here only where they touch build/verify steps. Spec reconcile/idempotency corrections — surplus = active-count-minus-expected DESC, gap-fill across all statuses, variation-change-in-place, `order_item_id<=0` skip, integer seat_index — are integrated into the Phase 3/4/5 build and verify steps above.)
