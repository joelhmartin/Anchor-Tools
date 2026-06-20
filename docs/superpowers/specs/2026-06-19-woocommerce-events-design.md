# Anchor Events — WooCommerce-Integrated Registration System: Unified Design Specification

Version: 1.0 (synthesis). Module: `anchor-events-manager`. Namespace `Anchor\Events`. This document supersedes the individual subsystem designs where they conflict; reconciliations are called out inline and summarized in §16. Adversarial-review corrections are applied inline throughout and traced in §17.

---

## 1. Overview & Goals

Add a full WooCommerce-integrated paid event registration system to the existing Anchor Events module without altering the behavior of the current free, internal-registration and event-display features. A linked WooCommerce product (simple → one event; variable → one event per variation/session) becomes the public registration path: the free `[event_registration]` form is auto-replaced by an add-to-cart / "Register — $price" button, attendee details are collected per seat on the WooCommerce checkout page, and WooCommerce order state is mirrored into seat records via one idempotent reconciliation engine.

Design goals:
- **One seat = one registration record.** Buying qty N creates N seat records.
- **WooCommerce stays optional.** Non-Woo sites load nothing extra and behave exactly as today (modulo the five corrective bug fixes that ship on all sites).
- **HPOS-compatible.** No direct order postmeta reads/writes; all order access via WC CRUD (`wc_get_order`, `$order->get_meta`, etc.).
- **Single capacity authority** shared by the free and paid paths; race-safe via a per-event MySQL named lock.
- **Idempotent everywhere.** Re-fired hooks, manual edits, and a manual "Resync" button all converge to the same seat set with no duplicates.
- **Data access isolated** behind a data layer (`class-registrations.php`) so a future custom-table swap is contained.

---

## 2. Scope

### MVP (build now)
- Product/variation → event linking (edited on the product; read-only mirror on the event).
- One seat record per paid seat; per-seat attendee capture (name/email/phone, required) at checkout; guest checkout supported.
- Order-status → seat-status sync (create/activate/cancel/refund including partial); idempotent resync + manual "Resync order" button.
- Capacity hard-limit + waitlist state (status only; no promotion).
- Admin roster screen (filter by status, search name/email/order, capacity vs count, order links) + manual add/edit/cancel seat.
- CSV export (active-only or all statuses; order/payment + attendee + source columns; formula-injection-safe).
- Organizer notification email + customer confirmation email; email-failure logging.
- Per-order sync log + needs-review admin notices; site-wide error log (the error log is in MVP only because the MVP email-failure logging requires it).
- HPOS compatibility declaration.
- Fold in five verified bug fixes (§12), applied on all sites.

### Explicitly deferred (design must not preclude; do NOT build)
- Waitlist promotion / auto-promote (vocabulary + history reserved).
- Check-in / QR tickets; mark attended/no-show UI (reserve `attended`/`no_show` status vocabulary only).
- Certificates / CE-credit fields; custom questions (dietary, license, etc.).
- Add-to-calendar / ICS.
- Per-attendee notification emails (reserve `notify_attendee` setting + `_anchor_event_attendee_notified` seat flag).
- Record anonymization / WP personal-data tool hooks; transfer attendee between events.
- Block (Gutenberg) checkout support — detected and fail-closed, not silently allowed.
- **Per-event activity log + "Activity" UI panel + Diagnostics settings subsection** (moved to deferred per review finding #20). The `_anchor_event_activity` event-meta data model is reserved (§4.7) so a future build is not precluded, but the activity roll-up writer, the Activity sub-panel on the event registrants metabox, and the Diagnostics settings subsection are NOT built in MVP. MVP keeps only the per-order sync log, the needs-review admin notices, and the site-wide error log (the error log stays because MVP email-failure logging depends on it).

---

## 3. Architecture & File Layout (Approach B)

Three new class files plus a logging helper. The existing `anchor-events-manager.php` remains the events/CPT/free-reg core.

| File | Class | Loaded | Guard |
|---|---|---|---|
| `anchor-events-manager/class-registrations.php` | `\Anchor\Events\Registrations` | always | none |
| `anchor-events-manager/class-roster.php` | `\Anchor\Events\Roster` | always | none |
| `anchor-events-manager/class-events-log.php` | `\Anchor\Events\Events_Log` | always | none |
| `anchor-events-manager/class-woocommerce.php` | `\Anchor\Events\WooCommerce` | only when WC active | `class_exists('WooCommerce')` |

**Reconciliation (file location):** the compat-testing subsystem placed these under `anchor-events-manager/includes/`; all other subsystems used the module root. **Resolved: module root** (`anchor-events-manager/class-*.php`), matching the majority and the existing flat module convention. Adjust the `require_once` paths accordingly.

**Loading & instantiation** — in `Module::__construct()`, immediately after `self::$instance = $this;` (L18), before hook registration:

```php
$dir = \plugin_dir_path( __FILE__ );
require_once $dir . 'class-events-log.php';
require_once $dir . 'class-registrations.php';
require_once $dir . 'class-roster.php';

$this->registrations = new Registrations( $this );
$this->roster        = new Roster( $this );

if ( \class_exists( 'WooCommerce' ) ) {
    require_once $dir . 'class-woocommerce.php';
    $this->woocommerce = new WooCommerce( $this, $this->registrations );
}
```

There is no autoloader for module sub-files (bootstrap does `require_once $module['path']` then `new $class()` at anchor-tools.php L312–325, on `plugins_loaded` priority 25). WooCommerce's main class is available by priority 25, so the `class_exists` gate is race-free.

> **Phasing note (plan-level, see §17 #25):** the snippet above is the *final* (post–Phase 5) loader. It must NOT be pasted verbatim into Phase 0 — Phase 0 instantiates only `Events_Log` + `Registrations` (and does not `require_once class-roster.php` or `new Roster()` until the Roster class exists in Phase 5, or supplies a minimal Roster stub). The plan owns the exact per-phase require/instantiate list.

`$this->woocommerce` is `null` when WC is absent and is never dereferenced; the core file references the WC class **only** through the `anchor_events_registration_form` filter (no `use`, no WC type-hints at file scope).

**HPOS declaration** — registered at **file scope in the main plugin `anchor-tools.php`** (not in the priority-25 bootstrap, because `before_woocommerce_init` can fire before priority 25):

```php
\add_action( 'before_woocommerce_init', function () {
    if ( \class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', ANCHOR_TOOLS_PLUGIN_FILE, true
        );
    }
} );
```

**Reconciliation (HPOS location):** order-sync put the declaration in the WC class constructor; compat-testing put it in the main file at file scope. **Resolved: main-file file scope** for load-order safety. It self-no-ops without WC.

**Method promotions on `Module`** (currently `private`, must become `public` for the new classes): `get_meta` (L2477), `meta_key` (L2498), `clear_caches` (L2420), `get_settings` (L3018), `get_registration_status` (L2769), `send_registration_emails` (L2885), `build_registration_email_html` (L2914, refactored — §11). Counting methods `get_attendee_count`/`get_registration_count`/`get_registrations` **move into `Registrations`**; `Module` keeps thin public wrappers delegating to the layer so existing internal callers (render/column paths) keep working.

**New render seam** — top of `render_registration_form($post_id)` (L1983), before any existing branch:

```php
$override = \apply_filters( 'anchor_events_registration_form', '', $post_id, $meta );
if ( $override !== '' ) { return $override; }
```

Only `WooCommerce::filter_registration_form()` consumes it. With WC absent the filter has no callbacks, returns `''`, and the free form renders unchanged.

> **Phasing note (plan-level, see §17 #12):** the `filter_registration_form()` callback that *replaces* the free form with the "Register — $price" button must NOT be registered until per-seat checkout capture and seat creation exist (Phase 2). Activating the free-form replacement before seat capture exists would let a linked-product purchase create an order with no seat records and no attendee data — silent data loss. Phase 1 builds linking + mirror + resolver only and leaves the free form live; the filter callback registers in Phase 2 (or Phases 1+2 are combined into one non-shippable-until-complete unit). The plan owns this.

**Cross-class wiring:** `Module` → `Registrations`/`Roster` always; `Module` → `WooCommerce` only via the filter. `Registrations` never touches orders (stores only order identifiers as seat meta; resolves orders via `wc_get_order()` only when read by Roster/WC class behind `function_exists` guards). `Roster` and `WooCommerce` both receive the `Registrations` instance.

Assets use `\Anchor_Asset_Loader::url('anchor-events-manager/assets/...')`. Text domain `anchor-schema`. Options use `autoload=false`.

---

## 4. Data Model

### 4.1 Seat record (`REG_CPT = 'anchor_event_reg'`)
One published post per seat; `post_title` = attendee name. Meta keys are bare `_anchor_event_*` on REG_CPT (distinct post type from the event CPT, so no collision). The 6 existing keys are unchanged; 9 new keys are added. All registered in `Module::register_meta()` (L209–265) reusing the existing `$reg_auth_callback` (`current_user_can('edit_post', $post_id)`).

| Meta key | Type | Default (legacy read) | Written by | Notes |
|---|---|---|---|---|
| `_anchor_event_id` | int | — (required join key) | both | Parent event. `meta_query` uses `'type'=>'NUMERIC'`. |
| `_anchor_event_name` | string | `''` | both | Attendee name. |
| `_anchor_event_email` | string | `''` | both | Attendee email. |
| `_anchor_event_reg_status` | string | `'confirmed'` (`?: 'confirmed'`) | layer only | Status vocab §4.2. Only the layer writes it (keeps history in sync). |
| `_anchor_event_reg_fields` | array | `[]` | both | Custom field values. `show_in_rest` as today. |
| `_anchor_event_guests` | int | `0` | both | Free path may be >0; **Woo seats always 0** (1 seat = 1 record). |
| `_anchor_event_phone` | string | `''` | both | NEW. Required at Woo checkout; optional for legacy free rows. |
| `_anchor_event_source` | string | absent → `'internal'` | layer (`create_seat`) | NEW. Enum `internal\|woocommerce\|manual\|imported`. |
| `_anchor_event_order_id` | int | `0` | woo | NEW. |
| `_anchor_event_order_item_id` | int | `0` | woo | NEW. Idempotency key half. |
| `_anchor_event_product_id` | int | `0` | woo | NEW. Historical link (survives un-linking). |
| `_anchor_event_variation_id` | int | `0` | woo | NEW. 0 for simple. |
| `_anchor_event_customer_id` | int | `0` | woo | NEW. 0 = guest (never fall back to current user). |
| `_anchor_event_seat_index` | int | `1` | woo | NEW. 1..qty within an order item. Idempotency key half. Always cast to integer when ordered/compared (see below). |
| `_anchor_event_history` | array | `[]` | layer only | NEW. Append-only (§4.4). `show_in_rest => false`. |

`_anchor_event_history` is `show_in_rest => false` (internal only, avoids REST array-schema friction); all other new keys mirror the existing `$reg_auth_callback` pattern. Integer keys registered as integer; `_anchor_event_source`/`_phone` as string.

**Idempotency key = `(_anchor_event_order_item_id, _anchor_event_seat_index)`.**

**`order_item_id = 0` wildcard guard (review #11).** Free (`internal`), `manual`, and `imported` seats — and all legacy free rows — default to `order_item_id = 0` and `seat_index = 1`, so EVERY non-Woo seat would share the key `(0,1)`. To prevent a wildcard collision:
- `get_seats_for_order_item()` and any `(order_item_id, seat_index)` dedupe / lookup MUST early-return (be a no-op) when `order_item_id <= 0`.
- `reconcile_order` MUST skip any line/seat with `order_item_id == 0`.
- The idempotency key is meaningful **only for `source = woocommerce` seats**. Free/manual/imported seats are never deduped on this key.

**`seat_index` integer handling (review #19).** `seat_index` is stored as post_meta (string). Everywhere it is ordered or compared — surplus-cancellation ordering, roster "seat N of M", gap detection, `max(existing_index)` — cast to integer (`CAST(... AS UNSIGNED)` in SQL, `(int)` in PHP), consistent with the `'type'=>'NUMERIC'` handling mandated for `_anchor_event_id`. Lexical ordering ('10' before '2') must never occur.

**`claim_seats`/`create_seat` self-defense (review #18).** The idempotency key is post_meta with no unique DB index, so correctness otherwise rests entirely on the gap-scan logic. Before inserting a seat, `claim_seats`/`create_seat` MUST assert that no existing seat of **any** status matches `(order_item_id, seat_index)` for that item (this check runs inside the event lock — §9.2). If one is found, log a needs-review flag `duplicate_seat_prevented`, skip the insert, and continue. This converts a silent capacity-skewing duplicate into a flagged, recoverable condition.

### 4.2 Status vocabulary (`_anchor_event_reg_status`)
Class constants on `Registrations`:

```
STATUS_CONFIRMED = 'confirmed'  // ACTIVE, counts toward capacity
STATUS_PENDING   = 'pending'    // RESERVED (WC on-hold), counts toward capacity
STATUS_WAITLIST  = 'waitlist'   // over-capacity overflow, counted separately, NOT active
STATUS_CANCELLED = 'cancelled'  // kept, excluded from counts
STATUS_REFUNDED  = 'refunded'   // kept, excluded from counts
STATUS_FAILED    = 'failed'     // kept, excluded from counts
STATUS_ATTENDED  = 'attended'   // RESERVED vocabulary only (check-in deferred); NOT counted in MVP
STATUS_NO_SHOW   = 'no_show'    // RESERVED vocabulary only; treat as inactive
```

Capacity buckets (the only thing the counter cares about):
- **RESERVING_STATUSES = ['confirmed','pending']** → count toward capacity.
- **WAITLIST_STATUSES = ['waitlist']** → counted separately, never in capacity.
- Everything else (`cancelled|refunded|failed|attended|no_show`) → excluded from both, kept as published posts (never hard-deleted).

> **`attended` membership (review #21).** Because check-in is deferred and `attended` is never written in MVP, `count_reserved_seats` is defined as **confirmed + pending only**; `attended` is explicitly **NOT** counted toward capacity in MVP. When check-in ships, `attended` will be folded into the reserving set. There is no "(+ attended) counts toward capacity" behavior in MVP — that phrasing is removed everywhere it previously appeared (§4.5).

`confirmed` stays the active status — **no data migration**. New statuses are additive; existing exact-match queries naturally exclude them.

### 4.3 Allowed transitions
Enforced in the layer's status setter via `protected static $transitions`. Illegal transitions are logged + no-oped (never fatal); same-status calls short-circuit unless a `$note` is passed.

```
pending    -> confirmed | cancelled | failed | refunded | waitlist
confirmed  -> cancelled | refunded | failed | attended | no_show
waitlist   -> confirmed | cancelled | failed
failed     -> confirmed | pending          (gateway retry / resync / revive)
cancelled  -> confirmed | pending          (resync may re-add / revive)
refunded   -> (terminal; resync leaves as-is — never auto-revive)
(any)      -> (same)  // no-op
```

Note: `cancelled`/`failed` are revivable (used by gap-fill revival in §7.5); `refunded` is terminal and never revived.

### 4.4 History log
`_anchor_event_history` = numerically-indexed, oldest→newest, append-only:

```php
[ 'status' => 'pending', 'time' => 1718800000, 'note' => 'order #1042 on-hold', 'actor' => 'woocommerce' ]
```
`time` = Unix UTC (`\time()`); `actor` ∈ `internal|woocommerce|manual|imported|system|user:<id>`. The first entry is written by `create_seat()`; every status change appends one. Prior entries are never mutated.

**Legacy-row baseline backfill (review #23).** Existing `confirmed`/`waitlist` rows predate `create_seat()` and have an empty `_anchor_event_history`. On the **first** `update_status()` of such a legacy seat (history empty), synthesize a backfill entry **before** appending the new transition:

```php
[ 'status' => <current status>, 'time' => <post_date as Unix>, 'note' => 'pre-existing', 'actor' => 'system' ]
```

so the audit trail is self-consistent (a synthesized "created"-equivalent record precedes the first real transition) rather than appearing truncated.

### 4.5 Capacity counting (single authority)
One `$wpdb` aggregate replaces the existing N+1 (`get_attendee_count` fetched IDs then per-row `get_post_meta`):

```sql
SELECT pm_status.meta_value AS status,
       COALESCE(SUM(1 + GREATEST(0, CAST(pm_guests.meta_value AS UNSIGNED))), 0) AS seats
FROM   {$wpdb->posts} p
JOIN   {$wpdb->postmeta} pm_event  ON pm_event.post_id = p.ID AND pm_event.meta_key = '_anchor_event_id'
JOIN   {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_anchor_event_reg_status'
LEFT JOIN {$wpdb->postmeta} pm_guests ON pm_guests.post_id = p.ID AND pm_guests.meta_key = '_anchor_event_guests'
WHERE  p.post_type = %s AND p.post_status = 'publish' AND pm_event.meta_value = %d
GROUP BY pm_status.meta_value
```

- **`count_reserved_seats(event_id)` = sum of `confirmed + pending` only** (`1 + guests` per row). `attended` is NOT included in MVP (§4.2); it will be added when check-in ships.
- `count_waitlist_seats(event_id)` = sum of `waitlist`.
- `remaining_capacity(event_id, $capacity)` = `capacity ? max(0, capacity - count_reserved_seats) : PHP_INT_MAX` (capacity 0 = unlimited).
- Result cached in transient `anchor_evt_caps_{event_id}` (registered in `Module::CACHE_OPTION`), invalidated by `clear_caches()` on every seat write. **The authoritative recount inside the lock (§9) always hits the DB directly, never the cache.**

**Backward-compat:** with no `pending` seats ever created (only the Woo path creates them), `count_reserved_seats` = confirmed count = today's `get_attendee_count` result. The reservation model is a strict superset that collapses to current behavior on free-only sites.

### 4.6 Order-side & order-item meta (CRUD only, HPOS-safe)
- Order-item (written at checkout): `_anchor_attendees` (array `[seat_index => {name,email,phone}]`, durable source of truth), `_anchor_event_id`, `_anchor_product_id`, `_anchor_variation_id` (link snapshot), `_anchor_seats_over_capacity` (waitlist-overflow hint).
- Order: `_anchor_event_sync_log` (capped ring buffer ~50), `_anchor_event_emails_sent` (array of `{type}:{event_id}` keys), `_anchor_event_needs_review` (array of `{reason, time}`).

### 4.7 Event-side meta (added to `get_meta_schema()` L267 **and** `get_meta_defaults()` L302)
- `linked_products` → `_anchor_event_linked_products` (array of `['product_id','variation_id']`) — denormalized mirror cache, product-owned (§5). **Must NOT appear in `save_meta()`'s hardcoded `$input` allow-list (L627)** so an event save never clobbers it.
- `organizer_email` → `_anchor_event_organizer_email` (string, default `''`).
- `activity` → `_anchor_event_activity` (array, capped ~100, append-only) — per-event activity roll-up. **Data model reserved only; NOT written or surfaced in MVP** (the activity log + Activity panel are deferred — §2, §11.6). Registered with the auth-callback so low-priv REST writes are blocked, and so the key exists for a future build, but no MVP code writes to it.

---

## 5. Product ↔ Event Linking

**Invariant: link writes only flow product → event mirror, never event → product.** The canonical answer to "what links to this event" is always a live query of product meta; the mirror is a cache rebuilt from that query.

### 5.1 Meta keys (authoritative, on product/variation posts)
Prefix `_anchor_evt_link_` (distinct from `_anchor_event_*`).

**Reconciliation:** the checkout subsystem referenced `_anchor_event_link_*`; the linking subsystem used `_anchor_evt_link_*`. **Resolved: `_anchor_evt_link_*`** (the linking design owns this; the prefix deliberately avoids the `_anchor_event_*` namespace).

| Key | Stored on | Type | Meaning |
|---|---|---|---|
| `_anchor_evt_link_enabled` | product (parent) | `'1'`/`''` | Master toggle "This product registers buyer for an event." Off ⇒ all linking ignored. Parent-only. |
| `_anchor_evt_link_event_id` | product (parent) | int | Simple-product target (ignored when variable). |
| `_anchor_evt_link_event_id` | each variation | int | Per-variation (session) target. Same key name; resolver picks by product type. `0`/empty = that session is a normal variation. |

Registered via `register_post_meta('product', …)` and `register_post_meta('product_variation', …)` with `single => true`, `show_in_rest => false`, `auth_callback` requiring `edit_products`.

### 5.2 Resolver (`event_for_line($product_id, $variation_id=0): int`)
1. If `$variation_id > 0`: load parent; if parent toggle off → 0; else read variation's `_anchor_evt_link_event_id`.
2. Else: if parent toggle off → 0; else read parent's `_anchor_evt_link_event_id`.
3. Validate: resolved id is `Module::CPT` and not trashed; else 0 (covers event deleted after linking).

Convenience wrappers: `event_for_product`, `event_for_variation`. Reverse lookup `products_for_event($event_id)` queries product+variation meta (`'type'=>'NUMERIC'`), drops variation hits whose parent toggle is off. `event_is_linked($event_id)` reads the mirror for an O(1) front-end check.

### 5.3 Admin UI (WC Product Data box)
- `woocommerce_product_data_tabs` → add "Event Registration" tab (`show_if_simple show_if_variable`).
- `woocommerce_product_data_panels` → master toggle checkbox + simple-product event `<select>` (`.show_if_simple`) populated from non-trashed events; note in `.show_if_variable` region.
- `woocommerce_product_after_variable_attributes` → per-variation event `<select>` (id `_anchor_evt_link_event_id[<loop>]`).
- Save: `woocommerce_admin_process_product_object` (`$product->update_meta_data`, no `$product->save()` — WC saves after) and `woocommerce_save_product_variation`. An event id that doesn't resolve to a real event is stored as `0`.
- **Stock guidance (review #16):** the panel surfaces a note recommending that linked products have WooCommerce **`manage_stock` disabled**, so event capacity is the single authority. If both WC stock and event capacity are set, the two limits are independent and can disagree. This same note appears on the read-only event mirror (§5.5).

### 5.4 Mirror maintenance
`rebuild_event_mirror($event_id)` = `update_post_meta($event_id, meta_key('linked_products'), products_for_event($event_id))` then `clear_caches()`. Rule: **on any product/variation link or toggle write, collect {old event ids} ∪ {new event ids} and rebuild each** (capture old meta before writing new). Lifecycle hooks: product save/variation save, toggle off, `woocommerce_delete_product_variation` (or `before_delete_post` for `product_variation`), product delete/trash. Event delete/trash leaves product meta untouched — `event_for_line` validation makes the stale id resolve to 0.

Sold seats keep their historical `_anchor_event_product_id`/`_variation_id`/`_event_id` regardless of later link changes.

### 5.5 Read-only mirror on the event screen
In/near `render_registrants_metabox()` (L562), when `class_exists('WooCommerce')` and the mirror is non-empty, render "Registers via: Product Name (#123) — simple / → Variation 'Morning' (#789)" linking to product edit screens (guard trashed products → "(product removed)"). Emits no form inputs. Show an inline notice that the public free form is replaced by WooCommerce while linked; capacity/waitlist fields stay editable. Also surface the manage_stock guidance from §5.3 (linked products should disable WC stock so event capacity is authoritative).

---

## 6. Checkout Attendee Capture

**Classic shortcode checkout only.** Block checkout is detected (`has_block('woocommerce/checkout', wc_get_page_id('checkout'))`) and, when event lines are present, fail-closed. Because static page detection alone does not stop a Store-API placement, fail-closed is enforced at **two** server-side levels (review #2):

1. **Store-API / block placement guard.** Hook `woocommerce_store_api_checkout_update_order_from_request` (or `woocommerce_blocks_checkout_order_processed`) and, when the order contains event line items, throw a `RouteException` / block placement with a clear error. The classic `woocommerce_after_checkout_validation` guard does NOT fire on block/Store-API placement, so this separate hook is required to actually stop a block-checkout order.
2. **Reconcile-time backstop (checkout-type-agnostic).** In `reconcile_order`, any paid event line item that has **no `_anchor_attendees` meta at all** must NOT silently fall back to billing identity — it sets `_anchor_event_needs_review` (`reason=attendees_missing`) so the missing attendee data is visible and recoverable. This makes the failure loud regardless of which checkout path created the order.

Combine these with hiding/blocking purchasability when the checkout page is a block checkout. No silent seat-less orders, and no silent seats-with-fake-attendee-details.

### 6.1 Canonical cart inspector
`get_event_cart_lines(): array` (private) — iterates `WC()->cart->get_cart()`, resolves each line's event via `resolve_event_for_line($product_id, $variation_id)` (the §5.2 resolver, also requiring the master toggle), returns lines **keyed by `cart_item_key`** with `{cart_item_key, product_id, variation_id, event_id, event_title, qty}`. Render, validate, and persist all consume this.

### 6.2 Field naming
```
anchor_attendees[<cart_item_key>][<seat_index>][name|email|phone]
```
`seat_index` 1..qty. Custom array fields are read from `$_POST` directly (not registered via `woocommerce_checkout_fields`).

### 6.3 Hooks
| Purpose | Hook | Callback |
|---|---|---|
| Render | `woocommerce_checkout_after_customer_details` (pri 10) | `render_checkout_attendee_fields()` |
| Validate + capacity re-check (classic) | `woocommerce_after_checkout_validation` | `validate_checkout_attendees($data, $errors)` |
| Block/Store-API fail-closed guard | `woocommerce_store_api_checkout_update_order_from_request` (or `woocommerce_blocks_checkout_order_processed`) | `guard_block_checkout($order, $request)` |
| Persist to line item | `woocommerce_checkout_create_order_line_item` | `persist_attendees_to_line_item($item, $cart_item_key, $values, $order)` |
| JS re-bind | enqueue on checkout; listen for `updated_checkout` | `checkout-attendees.js` |

### 6.4 Render
One `<fieldset>` per event line, `qty` seat blocks each ("{event_title} — Attendee {n} of {qty}"). `name`/`email`/`phone` `required` (HTML, for UX). Repopulate from `$_POST` (`wp_unslash` → `esc_attr`) on validation failure. Optional JS-only billing prefill of seat 1. Fields re-render automatically on the `update_checkout` AJAX pass; JS re-binds handlers on `updated_checkout`.

### 6.5 Validation (fail-closed, classic checkout)
1. Block-checkout guard (the static `has_block` detection; the real enforcement for block placement is the Store-API hook in §6 intro).
2. Re-derive expected counts from the **cart**, never `$_POST`.
3. Per seat: `wp_unslash` → `sanitize_text_field` (name/phone, non-empty), `sanitize_email` + `is_email` (email). Missing/invalid → unique `$errors->add('anchor_attendee_'.$key.'_'.$i, …)`.
4. **Capacity re-check (same pass):** aggregate requested seats **per event** across cart lines; `remaining = Registrations::remaining_capacity($event_id)`. If `requested > remaining`: waitlist OFF → `$errors->add()` with exact remaining ("Only %d seat(s) remain for %s."); waitlist ON → allow, record overflow intent (stashed at persist time). This is the early/accurate buyer error and is the **pre-payment** capacity defense; the durable defense is the locked recount at seat creation (§9). Note that this validation pass holds **no lock and creates no reservation** — two concurrent buyers can both pass it; the only true serialization is `claim_seats` under `GET_LOCK` (§9), and post-payment overfill is handled via needs-review, not a buyer error (§9.3).

### 6.6 Persist
Inside the order-creation transaction (`woocommerce_checkout_create_order_line_item`), HPOS-safe, survives guest checkout. Re-sanitize from `$_POST`, build `_anchor_attendees` array, `$item->add_meta_data('_anchor_attendees', $attendees, true)`, plus link snapshot (`_anchor_event_id`, `_anchor_product_id`, `_anchor_variation_id`) and optional `_anchor_seats_over_capacity`. **No seat CPT posts created here** — order is still pending. Seats are created later by the status-sync reconcile routine, which reads `$item->get_meta('_anchor_attendees')`.

**`$_POST` is only present on classic AJAX checkout (review #17).** Any non-classic order-creation path — admin-created orders, "Pay for order", renewals/Subscriptions, Store API — has no `$_POST`, so this callback silently produces line items with no attendee meta. We **keep** the `$_POST` read for classic checkout, but the reconcile backstop (§6 intro, §7) makes "event line item on a paid order with zero `_anchor_attendees`" raise needs-review (`reason=attendees_missing`) rather than silently billing-filling. This makes admin/manual/Store-API-created event orders visibly flagged for attendee completion instead of getting fabricated billing-identity seats.

Guest: `customer_id` = `$order->get_customer_id()` (0 for guests; never current user); contact-of-record = `$order->get_billing_email()`. Never carry attendee data in `WC()->session` past order creation.

---

## 7. Order Lifecycle Sync + Idempotent Resync

### 7.1 Core principle
Exactly ONE seat-mutation entry point: `reconcile_order(\WC_Order $order, string $reason = '')`. Every hook, the refund path, manual order edits, and the manual Resync button funnel into it. It is declarative and idempotent: it computes the desired seat set per line item from the order's current state and converges existing seats toward it. Re-firing is a no-op once converged.

**Reconciliation (entry-point naming):** subsystems variously named this `reconcile_order($order)`, `reconcile_order($order_id)`, and `sync_order_seats($order_id)`. **Resolved: `reconcile_order(\WC_Order $order, string $reason='')`**; every handler normalizes its argument (`$order = $order instanceof \WC_Order ? $order : \wc_get_order($order_id)`), returns if falsy, then calls it.

### 7.2 Hooks (in `WooCommerce` constructor, all WC-gated)
```php
\add_action('woocommerce_order_status_changed', [$this,'on_status_changed'], 10, 4); // primary, switch($to)
\add_action('woocommerce_payment_complete',     [$this,'on_payment_complete'], 20, 1); // secondary reconcile trigger (§ below)
\add_action('woocommerce_new_order',    [$this,'on_new_order'], 20, 2);
\add_action('woocommerce_order_refunded',[$this,'on_order_refunded'], 10, 2);       // §8
\add_action('woocommerce_saved_order_items',        [$this,'on_saved_order_items'], 10, 2);
\add_action('woocommerce_before_delete_order_item', [$this,'on_delete_order_item'], 10, 1);
// Order trash / permanent delete — release capacity before the order disappears (§7.8):
\add_action('woocommerce_before_trash_order',  [$this,'on_order_trashed_or_deleted'], 10, 1);
\add_action('woocommerce_trash_order',         [$this,'on_order_trashed_or_deleted'], 10, 1);
\add_action('woocommerce_before_delete_order', [$this,'on_order_trashed_or_deleted'], 10, 1);
\add_action('before_delete_post',              [$this,'on_legacy_order_deleted'], 10, 1); // legacy 'shop_order'
\add_action('admin_post_anchor_event_resync_order', [$this,'handle_resync_order']);
\add_action('add_meta_boxes', [$this,'register_order_metabox'], 30, 2);             // HPOS-aware screen id
```
(HPOS declaration lives in the main plugin file — §3.) The full-refund safety net `woocommerce_order_status_refunded` is handled by the status switch mapping to `expected=0`.

**`woocommerce_update_order` is intentionally NOT hooked (review #3).** That hook fires on essentially every `$order->save()` — during checkout while still `pending`, on stock reduction, on unrelated plugin saves, and CRITICALLY on reconcile's own end-of-pass `$order->save()` — risking a save loop and duplicate sync-log spam, and repeatedly invoking the `pending→null` branch mid-checkout. Status changes are already covered by `woocommerce_order_status_changed`; manual item edits by `woocommerce_saved_order_items`; gateway "mark as paid"/`payment_complete()` flows by `woocommerce_payment_complete`. If a future need forces re-adding `update_order`, it MUST be hard-gated (only reconcile when `get_status()` is in the active/reserving set AND not currently within checkout).

**`woocommerce_payment_complete` (review #4).** Added as a secondary reconcile trigger because some gateways and "mark as paid" paths set the paid status via `payment_complete()` where this is the most reliable signal; relying only on `order_status_changed` risks a paid order whose seats are never created. It is idempotent — a double-fire alongside `order_status_changed` is harmless given the seat_index gap dedupe under the lock.

**Callback argument shapes (review #14).** `woocommerce_before_delete_order_item` passes an **int item id**, not an item object — handlers query seats by `_anchor_event_order_item_id` (never call `->get_id()` on it). `woocommerce_saved_order_items` passes `($order_id, $items)`; `woocommerce_new_order` passes `($order_id, $order)` but older WC passed id only — every handler re-fetches via `wc_get_order($id)` defensively and returns on falsy. Each hook callback carries a comment documenting its real signature to prevent calling `->get_meta()` on an int.

**Re-entrancy guard:** `reconcile_order` keeps a static in-flight set keyed by order id and bails if already reconciling that order this request. **The in-flight static is per-PHP-process only and does NOTHING cross-process** — the per-event `GET_LOCK` (§9.2) is the only real concurrency guard. The guard is acquired at entry and released in `finally` **after** the single end-of-pass batched `$order->save()` (sync-log / needs-review / emails-sent meta), so that final save's own hooks cannot re-enter reconcile. `reconcile_order` **never** calls `$order->save()` inside the per-line loop — only once at end of pass, wrapped inside the in-flight guard.

### 7.3 Status map (`map_order_status_to_seat(string): ?string`)
| Order status | Target | Effect |
|---|---|---|
| processing | `confirmed` | create/activate up to expected qty |
| completed | `confirmed` | create/activate |
| on-hold | `pending` | create reserving seats (count toward capacity) |
| pending | `null` | create no active seats; sweep existing active → cancelled |
| failed | `failed` | transition existing → failed (releases pending hold) |
| cancelled | `cancelled` | transition existing → cancelled (kept) |
| refunded | `refunded` | transition existing → refunded (kept) |

Terminal/non-active statuses (`pending`/`failed`/`cancelled`/`refunded`) force `expected_qty = 0` so the surplus branch sweeps all active seats to the mapped status (never hard-deleted).

### 7.4 Expected qty & event resolution per line
`expected_qty_for_item = max(0, $item->get_quantity() - abs($order->get_qty_refunded_for_item($item_id)))` — cumulative, recomputed from current total refunded qty (refund re-fire safe). `resolve_event_for_item` = variation→event meta else product→event meta; `0` if no longer linked. A line resolving to `0` whose seats exist is left untouched and logged (un-linked-after-purchase).

### 7.5 Reconcile algorithm (per line item)
```
if order_item_id <= 0: skip line          // (0,1) wildcard guard, §4.1
if no _anchor_attendees meta on a paid event line: set needs-review attendees_missing (§6)
existing = get_seats_for_order_item(item_id)  // ANY status, ordered by CAST(seat_index AS UNSIGNED) ASC
active   = existing minus {cancelled, refunded, failed}
expected = (target null or terminal) ? 0 : expected_qty_for_item(order, item)

// VARIATION-CHANGE-IN-PLACE (review #10): for each existing seat, compare its
// stored _anchor_event_id against resolve_event_for_item(item). On mismatch,
// cancel that seat on the OLD event (release capacity, history note "event changed")
// and gap-fill a fresh seat on the NEW event (under the new event's lock). Do not
// assume WC always deletes+recreates the item.

// ADD missing: fill seat_index slots 1..expected via claim_seats() under event lock (§9)
//   Gap detection considers seats of ANY status at (order_item_id, seat_index), not just active:
//   - slot empty (no seat any status)        -> create via claim_seats()
//   - active seat present                    -> transition seat -> target (only if status differs)
//   - 'cancelled'/'failed' seat present       -> REVIVE via update_status() (allowed transition)
//   - 'refunded' seat present (terminal)      -> never revive; allocate the new seat at max(existing_index)+1

// CANCEL surplus (review #6): never threshold on seat_index as if it were a count.
surplus_count = count(active) - expected
if surplus_count > 0:
    take active seats ordered by CAST(seat_index AS UNSIGNED) DESC
    cancel exactly surplus_count of them -> (terminal map ?: 'cancelled')

// terminal order statuses: transition ALL non-terminal survivors -> target
```
Seats already at target are skipped (no history spam). `seat_index` is purely an identity/ordering field, never a count proxy. Attendee values are re-read from `_anchor_attendees[seat_index]`; if the array is entirely absent on a paid event line, the line is flagged `attendees_missing` (§6) rather than billing-filled. Where an individual seat's attendee entry is missing but the array exists (admin-added line, qty bump), fall back to billing identity with a history note "attendee data missing, used billing". All values `wp_unslash`'d before sanitize.

> Gap-fill rationale (review #7): because cancelled/refunded/failed seats are kept and still occupy their `(order_item_id, seat_index)`, gap detection MUST consider all statuses. Otherwise a later qty bump (or admin re-add after a refund) would create a second row at the same `(order_item_id, seat_index)` — a silent duplicate permanently double-counting capacity. Reviving `cancelled`/`failed` in place (per §4.3) implements the revival §4.3/§16 promise; `refunded` is never revived and the new seat takes `max(existing_index)+1`.

### 7.6 Manual order edits
- Add line → `woocommerce_saved_order_items` → reconcile → new seats (billing fallback where individual attendee entries are missing; whole-array-missing flags `attendees_missing`).
- Qty ± → `woocommerce_saved_order_items` → reconcile up (fill gaps, reviving terminal seats per §7.5) / down (cancel newest `surplus_count` active seats by seat_index DESC).
- Remove line → `woocommerce_before_delete_order_item` (arg is the **int item id**) → cancel that item's seats by `_anchor_event_order_item_id` (can't resolve after deletion).
- Variation change → handled two ways: (a) WC deletes old item + creates new → old seats cancelled, new seats created against the new variation's event; (b) variation changed **in place** on the same order item id (programmatic/REST edits, or WC versions that mutate `variation_id`) → the per-line `_anchor_event_id` mismatch check in §7.5 cancels the old-event seats and gap-fills on the new event. Both converge correctly; delete+create is no longer assumed to be the only path.

### 7.7 Manual "Resync order" button
Order meta box (registered for both `wc_get_page_screen_id('shop-order')` HPOS and legacy `'shop_order'`) shows per-line seat summary, the sync log, needs-review banner, and a nonced form POSTing `admin-post.php` `action=anchor_event_resync_order`. Handler: capability `edit_others_posts`, `check_admin_referer('anchor_event_resync_'.$order_id)`, `wc_get_order` guard, clears resolvable needs-review, calls the identical `reconcile_order($order, 'manual resync')`, redirects back with notice. Resync does **not** resend confirmations unless an admin explicitly opts in.

### 7.8 Order trash / permanent deletion (capacity-leak fix, review #8)
There must be a path that releases capacity when an order is trashed or permanently deleted, because once the order record is gone `wc_get_order` returns false and `reconcile_order` early-returns — leaving any `confirmed`/`pending` (active) seats published and reserving capacity forever.

Handlers `on_order_trashed_or_deleted($order_id)` (HPOS: `woocommerce_before_trash_order`, `woocommerce_trash_order`, `woocommerce_before_delete_order`) and `on_legacy_order_deleted($post_id)` (`before_delete_post`, only when the post type is `shop_order`) fire **while the order id is still known**. They capture all seats by `_anchor_event_order_id` and transition every **non-terminal** seat to `cancelled` (kept, history note "order trashed/deleted") under the event lock, releasing capacity. These run before the order disappears, so no resync is needed afterward.

---

## 8. Refund Handling

Refunds are not a special seat path — they change `expected_qty` and route through the same `reconcile_order`. The only refund-specific logic is "subtract refunded qty + detect amount-only."

### 8.1 Hooks
- `woocommerce_order_refunded($order_id, $refund_id)` — primary (full + partial; partial does NOT change order status).
- Full refund also drives `woocommerce_order_status_refunded` → same routine; idempotent double-fire.

### 8.2 Reading refunds (CRUD only)
- Per line: `expected = max(0, ordered_qty - abs($order->get_qty_refunded_for_item($item_id)))` (cumulative across all refunds).
- `classify_refund($order, $refund_id)`: load `$refund = wc_get_order($refund_id)`; iterate `$refund->get_items()`.

> **WooCommerce stores refund line-item quantities as NEGATIVE numbers (review #1, BLOCKER).** `$refund_item->get_quantity()` returns `-1, -2, …` for a refunded line. A naive `qty > 0` test is NEVER true, which would misclassify *every* line refund as `amount_only` → flagged needs-review with no seats ever cancelled/refunded — silently breaking the entire refund→seat path. Detect line refunds correctly:
> - **line refund** when **any** refund item has `abs($refund_item->get_quantity()) > 0` (equivalently `get_quantity() != 0` / `get_quantity() < 0`). Map back to the original line via `$refund_item->get_meta('_refunded_item_id')`, with product/variation match as fallback.
> - **`amount_only`** is the branch where **every** refund item has zero qty but `$refund->get_amount() > 0`.
> - **`mixed`** when both a line qty and extra unexplained amount are present.

### 8.3 Decision flow
- `amount_only` → set needs-review (`reason=amount_only_refund`), sync-log note, admin notice; **change no seats** (never guess).
- `line`/`mixed` → reconcile each line to the new `expected_qty`; surplus active seats → `refunded`, selected by `surplus_count = count(active) - expected` taking the highest `seat_index` first (§7.5 surplus rule — never a `seat_index > expected` threshold), kept (never deleted). `mixed` with extra unexplained amount also raises needs-review for the surplus money.
- Full refund → every line `expected=0` → all seats `refunded`.

### 8.4 Edge cases
Re-fired hook → same cumulative qty → no change. Multiple partials → monotonically lower expected. Product un-linked post-purchase → seats still found via `_anchor_event_order_item_id`, can still be refund-cancelled by item id; log, don't error. Trashed order on the refund path → `wc_get_order` false → early return (capacity for trashed orders is released proactively by §7.8, not here).

---

## 9. Capacity & Waitlist

### 9.1 Single authority
`Registrations` owns all capacity math. `Module::get_attendee_count` / `get_registration_status` are refactored to delegate, so free and paid can never diverge. The inline check at L2777–2783 is **deleted** and replaced by the layer's `capacity_decision($event_id, $meta, $requested=1): string` returning `open|full|waitlist` (preserving `get_registration_status`'s existing `closed`/window semantics and the free path's `+ party_size`).

### 9.2 Concurrency primitive — per-event MySQL named lock
WP transients are NOT atomic. Use a connection-scoped advisory lock (works across PHP-FPM workers and HPOS):
```php
GET_LOCK('anchor_evt_'.$event_id, 5)  // 1 on success
RELEASE_LOCK('anchor_evt_'.$event_id)
```
`with_event_lock(int $event_id, callable $fn)` wraps acquire → run → release (in `finally`). `claim_seats(int $event_id, array $meta, int $qty, array $seat_payload): array` is the shared reservation routine for **both** paths: acquire lock → **re-read existing item seats by `(order_item_id, seat_index)` across all statuses INSIDE the lock** (review #9 — not just capacity) → recount `remaining_capacity` from DB → allocate `confirmed`/`pending` up to remaining, surplus → `waitlist` if `$meta['waitlist']` (else not created on free path / flagged on paid) → run the `claim_seats` self-defense duplicate check (§4.1) → `create_seat()` **inside the lock** so a concurrent reader's recount already sees the reservation → release.

> **The per-item existing-seat lookup MUST run inside `with_event_lock`, immediately before create (review #9).** If `reconcile_order` read existing item seats outside the lock, two concurrent same-order webhooks (e.g. `processing` then `completed` in separate workers) could both see "index 1..N missing" and both create — duplicate seats despite the idempotency key. The in-flight static does nothing cross-process; the event lock is the only real guard, so the existence check and the create must both live under it.

Returns `['created'=>[ids],'waitlisted'=>[ids],'remaining_before'=>n,'status'=>'ok|partial|full|lock_failed']`.

**Lock-acquire failure / unavailability (review #5).** On managed DB proxies that pool/multiplex connections, `GET_LOCK` semantics can degrade. On timeout/unavailable: degrade — perform a single non-atomic recount + log `lock_unavailable`; never block a paid order, never duplicate (the `(order_item_id, seat_index)` existence check still gates creation within this process). **Additionally, set `_anchor_event_needs_review` (`reason=capacity_lock_unavailable`) on any order that creates seats while the lock was unavailable**, so an admin can audit potential genuine concurrent overfill (the seat_index-gap dedupe only prevents duplicate seat_index within one item — it does NOT prevent two different orders overfilling under degradation).

### 9.3 Three enforcement layers (paid)
1. **Add-to-cart gate (UX, stale read):** `woocommerce_is_purchasable` / `woocommerce_variation_is_purchasable` → `false` when sold out **and waitlist off**; `woocommerce_add_to_cart_validation` rejects a stale add. Button label: "Register — $price" / "Sold out" (disabled) / "Join waitlist — $price". This is rendered by `filter_registration_form()` at the §3 swap seam, reusing class `.anchor-event-register`. Note (review #16): returning `false` from `woocommerce_is_purchasable` causes WC to silently remove the product from a cart that already contains it on the next cart/checkout load; surface a clearer `wc_add_notice` on removal where possible.
2. **Order-placement re-check:** `woocommerce_after_checkout_validation` (§6.5) — narrows the window, gives the buyer an accurate **pre-payment** error.
3. **Seat creation under lock (the real defense):** at the `processing`/`completed`/`on-hold` transition, `claim_seats` recounts under the lock. If seats vanished mid-checkout and waitlist is OFF — buyer already paid, so **do not silently overfill and do not reject in a status hook**: create up to remaining as `confirmed`, surplus as `waitlist` if enabled else leave uncreated, set `_anchor_event_needs_review` (`reason=capacity_overfill`), sync-log note, organizer notice.

> **Pre- vs post-payment error semantics (review #24).** The buyer-facing remaining-count error ("Only N seat(s) remain") is the **pre-payment** defense (layer 2). For the **post-payment** race (waitlist off, seats vanished after the charge), no buyer-facing error is surfaced — once the buyer has paid, the only sane behavior is to create up to remaining and flag `_anchor_event_needs_review` + organizer notice (layer 3). This is intentional; there is no buyer error possible after the charge.

### 9.4 Free path race fix
`handle_registration()` (L2067) replaces the check-at-L2110 / insert-at-L2121 gap with one `claim_seats($event_id, $meta, 1, ['source'=>'internal', ...guests/fields/phone])` call (the single free "seat" carries `guests`, weighted `1+guests`). Same `anchor_evt_<id>` lock ⇒ free and paid serialize together. Adds `wp_unslash()` before sanitize and `_anchor_event_phone` capture.

### 9.5 Waitlist semantics
`waitlist` seats are real seat posts with full attendee/order meta and a history entry. Excluded from `count_reserved_seats`, counted by `count_waitlist_seats` for roster. **No auto-promotion in MVP** — vocabulary + append-only history reserved so a future `promote_seat()` can flip `waitlist`→`confirmed` under the same lock with no data changes. Capacity 0 = unlimited; never sold out; never waitlists.

---

## 10. Roster Admin + CSV Export (`class-roster.php`)

Loaded unconditionally (free + paid). Single capability `const CAP = 'edit_others_posts'` everywhere (fixes the export bug). Reads Woo data via the data layer / `wc_get_order` guarded by `function_exists('wc_get_orders')`.

### 10.1 Screen
Submenu under the Event CPT menu: `add_submenu_page('edit.php?post_type=event', 'Event Roster', 'Roster', self::CAP, 'anchor-event-roster', [$this,'render_page'])`; `?page=anchor-event-roster&event_id=NN`. Entry points: event metabox "Open full roster" link, `post_row_actions` "Roster" link on the Events list, and a "View roster" link from the WC order panel. `roster_url($event_id, $args=[])` is the shared nonced link builder.

Header summary from one call `Registrations::get_event_summary($event_id)` → capacity, reserved (confirmed+pending), confirmed, pending, waitlist, cancelled, refunded, failed, remaining, has_linked_product, is_overbooked. Renders "Capacity: 50 · Reserved: 47 (45 confirmed + 2 pending) · Remaining: 3 · Waitlist: 4"; needs-review → `notice-warning` with Resync link (Resync button only when `class_exists('WooCommerce')`).

### 10.2 Table (`WP_List_Table` subclass)
Filters (all `wp_unslash` + sanitized): `status` (all/active/confirmed/pending/waitlist/cancelled/refunded/failed/needs-review), `s` (matches name, email, AND order number/id), `source`, `paged`, `orderby`, `order`. Data via `Registrations::query_seats([...]) → {items: Seat_DTO[], total}` — all `meta_query` (with `'type'=>'NUMERIC'` on `_anchor_event_id`, and integer casting on `seat_index`) lives in the layer; DTOs are pre-hydrated (batched `update_meta_cache`, no N+1).

**Order search under HPOS (review #15), guarded behind `function_exists('wc_get_orders')`.** `wc_get_orders(['search'=>…])` is a fuzzy full-text search, not an exact lookup. For roster "search by order": if the term is **numeric**, prefer `wc_get_order($term)` / `['post__in'=>[$term]]` (HPOS-aware exact match) and resolve to `_anchor_event_order_id IN (…)`; only **non-numeric** terms fall back to `['search'=>…]`.

Columns: cb, attendee, email, phone, status (colored pill via `status_label()`), guests, source, order (link to `admin.php?page=wc-orders&action=edit&id=NN` HPOS with `get_edit_post_link` fallback + payment status; blank for free), seat ("1 of 3", with `seat_index` cast to int), date. Row actions: Edit | Cancel (hidden when already cancelled/refunded). Mark-attended / Transfer not rendered (deferred).

### 10.3 Manual seat actions (via `admin-post.php`, redirect-back)
`anchor_roster_add`, `anchor_roster_edit`, `anchor_roster_cancel`, `anchor_event_export` (moved here from Module). Each: `current_user_can(self::CAP)`, `check_admin_referer('anchor_roster_{action}_'.$event_id)`, `wp_unslash`→sanitize, delegate to the layer (never write seat meta directly).
- **Add** → `create_seat([... 'source'=>'manual' ...])` → runs `claim_seats` under the lock (capacity-honored; over capacity → waitlist if on, else block with remaining count). Manual seats use `order_item_id=0` and are never deduped on the idempotency key (§4.1).
- **Edit** → name/email/phone/guests; status change routed through the layer's status setter (capacity + history honored). Woo `_anchor_event_order_*` fields shown disabled ("Managed by order #N").
- **Cancel** → set status `cancelled` (kept + history). For Woo seats, warns "cancel/refund in WooCommerce to keep in sync"; bulk "Cancel selected" loops the same call.

### 10.4 CSV export (`handle_export`)
Hook `admin_post_anchor_event_export`, nonce `anchor_event_export`, **capability `edit_others_posts`** (the fix). Params: `event_id`, `scope` = `active` (confirmed only) or `all`. `Registrations::get_export_rows($event_id, $scope)` batches order lookups (`wc_get_orders(['include'=>$ids])` once, guarded by `function_exists('wc_get_orders')`).

Columns: `Seat ID, Event, Attendee Name, Email, Phone, Status, Source, Guests, Party Size, Registration Date, Order #, Order ID, Order Status, Order Date, Customer ID, Customer Email, Product, Product ID, Variation ID, Order Item ID, Seat Index` + dynamic union of `_anchor_event_reg_fields` keys. Free seats leave Woo columns blank, source `internal`.

**Formula-injection hardening:** `csv_safe($v)` prefixes `'` when the cell starts with `= + - @ \t \r`; applied to every data cell (name/email/phone/custom fields originate from public checkout). Streams via `php://output`, `nocache_headers()`, `Content-Disposition` filename `event-roster-{id}-{scope}-{Ymd}.csv`, `exit`.

---

## 11. Emails & Logging

### 11.1 Customer confirmation — ONE email PER ORDER
Addressed to `$order->get_billing_email()`, with a section per event and a per-seat attendee list inside. **Reconciliation:** test-matrix rows said "3 confirmations (or 1 per attendee email)"; the emails design says one-per-order. **Resolved: one confirmation per order** (avoids spamming the buyer; per-attendee emails are deferred). Sent once, gated by the `_anchor_event_emails_sent` idempotency flag; not resent on later partial refunds.

### 11.2 Organizer notification — one per order per event
Recipient: per-event `_anchor_event_organizer_email` → global setting `organizer_email` → `admin_email`. Content: event title, order link, buyer, qty, new remaining, per-seat list; prominently flags waitlisted seats.

### 11.3 Template & placeholders
Reuse `build_registration_email_html()` (L2914) — **promote to public and refactor to a `$ctx` array** (`event_id, name, status, intro_message, guests, detail_rows[], seat_list[], cta_label, cta_url`); a back-compat shim maps the free path's old positional args so `handle_registration()` needs no signature change. The existing `anchor_events_registration_email_html` filter is preserved (passed `$ctx`). `expand_email_tokens($template, $tokens)` supports `{event_title}{event_url}{attendee_name}{buyer_name}{order_number}{order_url}{seat_count}{event_date}{site_name}{remaining_seats}{status}`.

New settings (in `get_settings` defaults L3019, `register_settings` L2222, `sanitize_settings` L2347, Woo subsection of the Events tab, rendered only when `class_exists('WooCommerce')`): `wc_notify_customer` (true), `wc_notify_organizer` (true), `organizer_email` (''), `wc_customer_subject`, `wc_customer_intro`, `wc_organizer_subject`; reserved-unused `notify_attendee` (false).

### 11.4 Trigger matrix (from the status-sync path, after payment — never from line-item creation)
| Order → seat | Customer | Organizer |
|---|---|---|
| processing/completed → confirmed | YES (once) | YES (once) |
| on-hold → pending | NO | optional pending notice (default off) |
| pending → none | NO | NO |
| failed → failed | NO | NO |
| cancelled → cancelled | NO (WC sends its own) | YES (seats released) |
| refunded/partial → refunded | NO | YES (N seats released) |
| over-capacity + waitlist on → waitlist | YES (waitlist variant) | YES (flagged waitlist) |

Idempotency: check `_anchor_event_emails_sent` (`'customer:123'`, `'organizer:123'`) before sending; append on success; single `$order->save()` at end of pass (inside the in-flight guard — §7.2).

### 11.5 Email-failure logging (folds in the `wp_mail`-ignored bug)
Centralize in `Module::send_html_email($to, $subject, $html, $args): bool` — sets HTML header, captures the `wp_mail` return; on `false` → `Events_Log::error('email_failed', …)` + sync-log entry + (for customer mail on a paid order) needs-review flag `customer_email_failed`. Register `wp_mail_failed` once in `__construct` to capture the PHPMailer reason. The free path's two bare `wp_mail` calls (L2903, L2910) are replaced with `send_html_email`.

> **Scope note (review #26):** A third `wp_mail` exists at L1390 (the front-end event-manager **password-reset** flow). It already checks its return value and is unrelated to registration, so it is **intentionally out of scope** for this email centralization. "Centralize `wp_mail`" here means only the two registration calls (L2903/L2910), not all call sites.

### 11.6 Logging subsystem (`class-events-log.php`, no custom table)
Static `Events_Log` with `order()`, `event()`, `error()`, `flag_review()`, `clear_review()`; each timestamps + records `get_current_user_id()`. `error()` always also forwards to `\Anchor_Schema_Logger` (debug-gated `error_log`).
- **Per-order sync log** — order meta `_anchor_event_sync_log` (CRUD, capped ~50): `{time, action, event_id, order_item_id, seat_index, from, to, qty_expected, qty_existing, note, user}`. Written once per reconcile pass. **(MVP)**
- **Per-event activity log** — event meta `_anchor_event_activity` (capped ~100): aggregate roll-up (`seat_confirmed/waitlisted/cancelled/refunded/capacity_reached/order_needs_review`). Distinct from the seat-level `_anchor_event_history`. **DEFERRED (review #20):** the data model key is reserved (§4.7) so a future build is not precluded, but MVP does NOT write this roll-up and does NOT render the "Activity" sub-panel on the event screen. `Events_Log::event()` may be stubbed/no-op in MVP.
- **Site-wide error log** — option `anchor_events_error_log` (autoload false, capped ~200): failed emails (+ reason), failed/aborted syncs, refund ambiguity, capacity overfill. Admin "Clear log" (cap `edit_others_posts`, nonced). **(MVP — required to back the email-failure logging of §11.5.)** The "Diagnostics" settings subsection that would surface this is **DEFERRED (review #20)**; in MVP the error log is written and clearable via the existing admin-post action but the dedicated Diagnostics settings UI panel is not built.
- **Needs-review** — order meta `_anchor_event_needs_review` = array of `{reason, time}` (reasons: `amount_only_refund`, `capacity_overfill`, `capacity_lock_unavailable`, `attendees_missing`, `duplicate_seat_prevented`, `customer_email_failed`, `unmappable_line`, `manual_review_requested`). Surfaced as `admin_notices` on Events list / WC Orders / Events settings tab and in the per-order panel; cleared by "Mark reviewed" or a clean Resync. **(MVP)**

**HPOS needs-review query (review #15), guarded behind `function_exists('wc_get_orders')`.** Flagged orders are queried with a `meta_query` (the bare `meta_key` shorthand is not a supported `wc_get_orders` arg):
```php
wc_get_orders([
    'limit'      => -1,
    'meta_query' => [ [ 'key' => '_anchor_event_needs_review', 'compare' => 'EXISTS' ] ],
]);
```
The meta lives in the HPOS orders-meta table because it is written via `$order->update_meta_data` + `save`.

### 11.7 Admin surfaces
Order meta box ("Event Registrations", HPOS-aware screen id): linked events, seat list/statuses, sync log (newest-first), needs-review banner + "Mark reviewed", "Resync order", "Resend confirmation". Roster: order links (`wc_get_order` guarded) + needs-review filter. New nonced admin-post/AJAX actions (cap `edit_others_posts`): `anchor_events_resync_order` (alias of the §7 resync), `anchor_events_resend_confirmation`, `anchor_events_clear_review`, `anchor_events_clear_error_log`. **The event-screen "Activity" sub-panel is DEFERRED (review #20) and not rendered in MVP.**

---

## 12. Bug Fixes Folded In (apply on ALL sites)

1. **Export capability mismatch** — `handle_export()` gate `manage_options` → `edit_others_posts` (L2148; now owned by `class-roster.php`), consistent with where the links are exposed. *(Plan note, review #22: the one-line capability change is independent of the Roster move; the plan must either apply it to the existing `handle_export()` in Phase 0 and re-home it in Phase 5, or state explicitly that bug #1 lands in Phase 5 so the Phase 0 acceptance wording — "bugs #2–#5 fixed" — is not misleading. The spec-level fix is unchanged: the final capability is `edit_others_posts`.)*
2. **Write-on-read in `get_event_status()`** — make it pure (return computed status, no `update_post_meta` during render at L2724). Persist only in existing write contexts (`save_meta` L665, `handle_event_manager_save` L1756) plus a daily cron sweep (`anchor_events_status_sweep`) and `transition_post_status`. *(Plan note, review #13: plugin UPDATES do not fire `register_activation_hook`, so already-active installs upgraded via Plugin Update Checker would never schedule the sweep. The plan must schedule the cron defensively on `init`/`admin_init` behind a `wp_next_scheduled('anchor_events_status_sweep')` guard — in addition to activation — and clear on deactivation, and confirm the activation/deactivation hooks actually exist for this module.)*
3. **Capacity race (free path)** — replaced by `claim_seats()` under `GET_LOCK` (§9.4). Same defense shared with the paid path.
4. **Status vocabulary** — expanded additively; `confirmed` stays active; no migration (§4.2).
5. **`wp_mail` return ignored** — centralized in `send_html_email()` with `wp_mail_failed` capture (§11.5).

Additional folded-in items: **CSV formula injection** (`csv_safe`, §10.4); **missing `wp_unslash`** on `$_POST`/`$_GET` reads in the free path and all attendee capture (so `O'Brien` survives); **untyped `_anchor_event_id` meta compares** → `'type'=>'NUMERIC'`; **N+1 counting** → single `$wpdb` aggregate (§4.5).

---

## 13. Backward-Compat & WC-Optional Loading

Guarantee: with WooCommerce absent, every observable Events behavior is identical to today, except the corrective bug fixes (which intentionally apply on all sites and do not change normal front-end markup).

Invariants (verified by the §15 matrix rows 17–20):
- No `woocommerce_*` hook is registered (all live in the never-loaded `class-woocommerce.php`).
- `anchor_events_registration_form` has zero callbacks → the `if ($override !== '')` short-circuit is inert → free form renders via the unchanged decision tree.
- Active status stays `confirmed`; no migration; existing 6 seat meta keys keep identical semantics; new keys written only by the Woo path; legacy `_anchor_event_source` reads default `internal`.
- Capacity math collapses to current behavior (no pending seats ⇒ reserved = confirmed); counting refactor is behavior-preserving (single-status counts return identical numbers).
- All shortcode output identical; settings page identical except inert/hidden Woo keys.

The five bug fixes are independently revertable and gated by capability/behavior, not WC presence (testable on a non-Woo site). Baseline capture (rendered HTML of archive + single + free-form submission) is taken before changes to diff for byte-level equivalence.

HPOS: compatibility declared at main-file scope (self-no-ops without WC); zero direct order postmeta reads/writes; seat CPT remains a normal post (HPOS governs only orders/refunds/order-items).

---

## 14. Open Assumptions / Risks

**Confirmed data-model defaults flagged for user veto (proceeding as designed unless overridden):**
- `confirmed` = active status, no migration; `pending`/`on-hold` reserve capacity; idempotency key `(order_item_id, seat_index)` (meaningful only for `source=woocommerce`).
- Statuses `cancelled`/`refunded`/`failed` are kept (never hard-deleted), excluded from counts.
- New seat meta keys and defaults per §4.1.

**Risks / assumptions:**
- **Block checkout unsupported** — MVP detects and fail-closes via both a Store-API placement guard and a reconcile-time `attendees_missing` backstop (§6). If a client requires block checkout, this is post-MVP work (Store API `CheckoutFields`).
- **`GET_LOCK` reliance** — connection-scoped MySQL advisory lock. On managed DB proxies that pool/multiplex connections, `GET_LOCK` semantics can degrade; design degrades gracefully (single recount + log + `capacity_lock_unavailable` needs-review flag — §9.2) rather than blocking, but extreme-concurrency over-capacity is theoretically possible if both the lock and the seat_index gating fail simultaneously (mitigated by the per-item existence check inside the lock, the `duplicate_seat_prevented` self-defense, and the needs-review flag).
- **`get_qty_refunded_for_item` sign** is version-dependent — always `abs()`. Refund **line-item** quantities from `$refund->get_items()` are NEGATIVE — detect via `abs(get_quantity()) > 0` (§8.2).
- **Refund line → order item mapping** via `_refunded_item_id` may be unavailable on some WC versions; product/variation fallback is approximate when the same product appears on multiple lines (rare; logged).
- **Re-entrancy** — `woocommerce_update_order` is deliberately NOT hooked (§7.2); the static in-flight set is per-process only and the per-event `GET_LOCK` is the true guard; the one batched `$order->save()` runs once at end of pass inside the in-flight guard and never inside the per-line loop.
- **Order trash/delete** — capacity is released proactively by the trash/delete hooks (§7.8) because no resync is possible once the order is gone.
- **Multiple products → one event** — capacity is per event aggregated across all cart lines/products targeting it (handled in validation and reconcile).
- **Daily cron status sweep** adds a recurring event; ensure it's scheduled on activation AND defensively on `init`/`admin_init` (for the update path that skips activation hooks) and cleared on deactivation.
- **Product un-linked after purchase** — seats are preserved via historical seat meta; reconcile tolerates the unmapped line.
- **WC `manage_stock` vs event capacity** — if both are set they are independent limits that can disagree; linked products should disable WC stock (guidance surfaced in the product panel and event mirror — §5.3/§5.5).

---

## 15. Acceptance Criteria & Manual Test Matrix

### 15.1 Acceptance criteria (per MVP feature)
- **Linking:** simple→one event, variable→per-variation event, toggle persists on product; event shows read-only "Registers via" mirror; editing the product updates the mirror, editing the event never writes the link; multiple products may map to one event.
- **One seat per paid seat:** qty N → N rows, `seat_index` 1..N, same order/item/customer; `(order_item_id, seat_index)` prevents duplicates on re-fire (Woo seats only).
- **Checkout capture:** dynamic per-seat blocks from cart qty; required name/valid-email/phone validated server-side; guest works; persisted to `_anchor_attendees`, mirrored to seats on `confirmed`.
- **Status sync:** mapping table honored exactly; cancelled/refunded kept; every transition appended to history; `payment_complete` is a redundant secondary trigger.
- **Idempotent resync:** one routine for all hooks + manual button; expected_qty vs existing → add missing (reviving terminal seats per §7.5) / cancel `surplus_count` newest-by-seat_index; converges identically on repeat.
- **Refunds:** full→all refunded; partial line (NEGATIVE refund qty correctly detected)→reduce by refunded qty, newest first; amount-only→needs-review, no guessing; cumulative. A partial line refund MUST actually transition a seat to `refunded` (not merely land in needs-review).
- **Capacity/waitlist:** remaining = capacity − (confirmed+pending); add-to-cart disabled/"Sold out" at 0; locked re-check at placement with remaining-count error pre-payment; waitlist-on overflow completes as `waitlist`; post-payment overfill flags needs-review; lock-unavailable flags `capacity_lock_unavailable`; same lock/authority as free path.
- **Order trash/delete:** trashing/deleting an order releases its non-terminal seats' capacity before the order disappears.
- **Roster:** filter by status, search name/email/order (numeric → exact order lookup), capacity vs reserved, order link; `edit_others_posts` consistent between view and export.
- **CSV:** active-only or all; order/payment + attendee (incl. phone) + source columns; formula-injection-safe; `edit_others_posts`.
- **Emails:** organizer + one-per-order customer confirmation from the `confirmed` sync path; `wp_mail` return captured; failures logged.
- **Audit:** per-order sync log each pass; needs-review surfaced as admin notices. (Per-event activity log/panel and Diagnostics panel are deferred — not asserted in MVP.)
- **Backward compat:** WC absent ⇒ no `woocommerce_*` hooks, WC file never required, filter has no callbacks, free signup + event display match baseline; HPOS declared, no incompatibility warning, zero direct order postmeta reads.

### 15.2 Manual test matrix
Env-A = WordPress without WooCommerce. Env-B = WordPress with WooCommerce, classic shortcode checkout, run with HPOS on and off.

| # | Env | Scenario | Expected |
|---|---|---|---|
| 1 | B | Buy 1 seat (simple, cap 10) | 1 confirmed seat, source=woocommerce, all order/seat meta set, reserved=1, customer confirmation + organizer notice, sync-log entry |
| 2 | B | Buy 3 as guest | 3 confirmed, seat_index 1/2/3, customer_id=0, billing email contact, ONE customer confirmation + 1 organizer notice |
| 3 | B | Per-seat validation (seat-2 email blank/invalid) | Placement blocked, clear error naming the seat, no order/seats |
| 4 | B | Capacity hard block (cap 2 full, waitlist off) | Add-to-cart disabled/"Sold out"; forced checkout blocked "Only 0 seats remain" |
| 5 | B | Capacity race (cap 1, two concurrent checkouts) | GET_LOCK serializes; exactly 1 confirmed; other blocked (waitlist off) or waitlisted (on); never over capacity; if lock unavailable, overfill flagged `capacity_lock_unavailable` |
| 6 | B | Waitlist on over capacity | Seat status `waitlist`, counted separately, buyer charged, organizer notified |
| 7 | B | Partial line refund (3 conf, refund qty 1) | NEGATIVE refund qty detected as `line`; expected=2; newest (index 3) → refunded (kept); 2 confirmed; remaining +1; history appended — NOT merely needs-review |
| 8 | B | Full refund | All seats refunded, kept; idempotent on re-fire |
| 9 | B | Amount-only refund | No seat change; order needs-review `amount_only_refund`; admin notice; sync-log "not guessed" |
| 10 | B | Resync after manual order edit (add/qty/remove + button) | Add missing/revive terminal/cancel newest surplus_count/no duplicates; running twice = no change |
| 11 | B | Variation change A→B (delete+create AND in-place) | Old-event seats cancelled; new seats against B's event; in-place change detected via `_anchor_event_id` mismatch; seat "moves"; both events' capacity correct |
| 12 | B | on-hold → processing | on-hold creates pending (reserve); processing flips same seats to confirmed, no dup; history shows both |
| 13 | B | failed / cancelled | failed→failed (hold released); cancelled→cancelled (kept); reserved drops |
| 14 | B | Order trashed/deleted with active seats | Non-terminal seats cancelled, capacity released before order disappears |
| 15 | B | Unlinked event in Woo env | `[event_registration]` shows free internal form; free signup works; source=internal |
| 16 | B | HPOS on AND off (rows 1,7,10) | Identical results; all reads via CRUD; needs-review list via meta_query EXISTS; no notices; no incompatibility warning |
| 17 | B | Block checkout present | Fail-closed: Store-API guard blocks placement when event lines present; any reconcile of an attendee-less event line flags `attendees_missing`; no fatal; no silent seat-less / fake-attendee order |
| 18 | A | Free signup (WC absent) | confirmed seat, source=internal, emails sent (failures logged), capacity honored under lock |
| 19 | A | Event display (WC absent) | Output identical to baseline; NO DB writes on render (bug #2 fixed) |
| 20 | A | Editor export (bug #1) | CSV downloads as Editor; no "Unauthorized" |
| 21 | A/B | CSV injection + unslash | Cell `=cmd()` prefixed `'`; `O'Brien` stored/displayed correctly |

---

## 16. Reconciliations Applied (summary)

1. **File location** — module root `anchor-events-manager/class-*.php` (not `includes/`); compat-testing's `includes/` path overridden.
2. **HPOS declaration** — main plugin file (`anchor-tools.php`) file scope, not the WC class constructor (load-order safety).
3. **Reconcile entry point** — single `reconcile_order(\WC_Order $order, string $reason='')`; the variants `reconcile_order($order_id)` / `sync_order_seats($order_id)` are normalized into it; refund `on_order_refunded` and `payment_complete` both call it.
4. **Product link meta prefix** — `_anchor_evt_link_*` (linking design), overriding checkout's `_anchor_event_link_*`.
5. **Customer email cardinality** — ONE confirmation per order (emails design), overriding test-matrix's "3 confirmations / 1 per attendee."
6. **Data-layer API naming** — unified canonical names: `create_seat(array $args)`, status setter (`update_status`/`transition_seat`/`set_seat_status` → choose `update_status` as canonical; others are aliases to remove), `get_seats_for_order_item`, `count_reserved_seats`, `count_waitlist_seats`, `remaining_capacity`, `with_event_lock`, `claim_seats`, `query_seats`, `get_export_rows`, `get_event_summary`. Implementation must expose exactly one method per concept.
7. **`count_reserved_seats` membership** — confirmed + pending only (waitlist AND attended excluded in MVP), consistent across capacity/refund/order-sync designs.
8. **`get_attendee_count` wrapper** — Module keeps a thin public wrapper delegating to the layer; default (no-arg) now returns reserved (confirmed+pending), which equals confirmed on free-only sites.
9. **Variation-change-in-place** — per-line reconcile compares each existing seat's `_anchor_event_id` to the line's currently resolved event and moves seats on mismatch (§7.5/§7.6), not assuming WC always delete+recreates.

This specification is complete and internally consistent; an implementation plan can be written directly from it.

---

## 17. Review corrections applied

Trace of each adversarial-review finding and how it was resolved (inline fixes are in the cited sections; plan-only items are flagged).

1. **[BLOCKER] Refund qty sign (§8.2)** — line refunds now detected via `abs($refund_item->get_quantity()) > 0` (refund quantities are NEGATIVE); `amount_only` is the all-zero-qty-but-amount>0 branch. Matrix #7 now asserts a seat actually transitions to `refunded`.
2. **[BLOCKER] Block-checkout fail-closed (§6/§9)** — added a Store-API placement guard (`woocommerce_store_api_checkout_update_order_from_request` / `woocommerce_blocks_checkout_order_processed`) and a reconcile-time backstop that flags `attendees_missing` for any paid event line item with no `_anchor_attendees` (no silent billing-fill).
3. **[MAJOR] `woocommerce_update_order` too broad (§7.2)** — dropped that hook entirely; final `$order->save()` wrapped inside the in-flight guard; `$order->save()` never called inside the per-line loop.
4. **[MAJOR] payment_complete (§7.2)** — added `woocommerce_payment_complete` as an idempotent secondary reconcile trigger; `order_status_changed` remains primary.
5. **[MAJOR] Lock degradation visibility (§9.2)** — orders that create seats while `GET_LOCK` is unavailable now get needs-review `capacity_lock_unavailable`; graceful non-blocking degrade kept.
6. **[MAJOR] Surplus cancellation (§7.5/§8.3)** — `surplus_count = count(active) - expected`; cancel exactly that many active seats ordered by `seat_index` DESC; never threshold on seat_index as a count.
7. **[MAJOR] Gap-fill vs terminal seats (§7.5/§4.1)** — gap detection considers ANY status at `(order_item_id, seat_index)`; revive `cancelled`/`failed` via `update_status`; for `refunded` allocate at `max(existing_index)+1`; idempotency check keys across all statuses inside the lock.
8. **[MAJOR] Order trash/delete capacity leak (§7.8)** — added trash/delete hooks (`woocommerce_before_trash_order`/`trash_order`/`before_delete_order` + `before_delete_post` for `shop_order`) to cancel non-terminal seats and release capacity before the order disappears.
9. **[MAJOR] TOCTOU per-item existence (§9.2)** — `claim_seats` re-reads existing item seats by `(order_item_id, seat_index)` across all statuses INSIDE `with_event_lock`, immediately before create; documented that the in-flight static is process-local and the event lock is the only real guard.
10. **[MAJOR] Variation change in place (§7.6/§16)** — per-line reconcile compares each seat's `_anchor_event_id` to `resolve_event_for_item(item)` and moves seats on mismatch (cancel old, gap-fill new).
11. **[MAJOR] `order_item_id=0` wildcard (§4.1)** — data-layer `(order_item_id, seat_index)` lookups/dedupe are no-ops when `order_item_id <= 0`; reconcile skips item_id 0; key meaningful only for `source=woocommerce`.
12. **[MAJOR] Phasing/shippability (plan)** — spec adds a phasing note in §3 that the free-form-replacement filter must not activate before seat capture (Phase 2); detailed phasing left to the plan.
13. **[MAJOR] Bug #2 cron on upgrade (plan)** — §12 note: schedule `anchor_events_status_sweep` defensively on `init`/`admin_init` behind `wp_next_scheduled`, not only on activation; plan owns the verify step.
14. **[MINOR] Callback arg shapes (§7.2)** — documented that `before_delete_order_item` passes an int item id, and new/saved hooks are id-first; all handlers re-fetch via `wc_get_order` and never call `->get_meta()` on an int.
15. **[MINOR] HPOS queries (§10.2/§11.6)** — needs-review list uses `meta_query [['key'=>'_anchor_event_needs_review','compare'=>'EXISTS']]`; roster numeric order search uses `wc_get_order()/post__in` (exact), non-numeric falls back to `search`; all guarded by `function_exists('wc_get_orders')`.
16. **[MINOR] Purchasability side effects / stock (§5.3/§5.5/§9.3)** — documented WC cart-removal behavior with a clearer notice, and guidance to disable `manage_stock` on linked products so event capacity is the single authority.
17. **[MINOR] Attendee persistence non-classic paths (§6.6)** — keep `$_POST` read for classic checkout; flag `attendees_missing` for any non-classic event line with zero `_anchor_attendees` rather than billing-filling.
18. **[MINOR] Idempotency key not enforced (§4.1)** — added `claim_seats`/`create_seat` self-defense: assert no existing seat (any status) at `(order_item_id, seat_index)` before insert; on collision log `duplicate_seat_prevented` and skip.
19. **[MINOR] seat_index ordering (§4.1/§7.5/§10.2)** — `seat_index` cast to integer everywhere it is ordered/compared (SQL `CAST(... AS UNSIGNED)` / PHP `(int)`).
20. **[MINOR] Scope creep (§2/§11.6)** — per-event activity log + Activity UI panel + Diagnostics subsection MOVED to the deferred list; data model reserved (§4.7) but not built. MVP keeps only the per-order sync log, needs-review notices, and the error log that email-failure logging requires.
21. **[MINOR] `attended` capacity membership (§4.2/§4.5)** — defined `count_reserved_seats = confirmed + pending` only; `attended` explicitly NOT counted in MVP (folded in when check-in ships); removed all "(+ attended) counts toward capacity" wording.
22. **[MINOR] Bug #1 timing (§12)** — final capability is `edit_others_posts`; added a plan note that the change can land in Phase 0 (then re-homed in Phase 5) or be explicitly deferred to Phase 5 so the Phase 0 acceptance wording is accurate.
23. **[NIT] Legacy history baseline (§4.4)** — on first `update_status` of a legacy seat with empty history, synthesize a backfill `{status:current, time:post_date, note:'pre-existing', actor:'system'}` before appending.
24. **[NIT] Decision-6 literal wording (§9.3)** — added note: buyer-facing remaining-count error is the pre-payment defense; post-payment overfill is handled via needs-review (no buyer error possible after charge).
25. **[NIT] Loader divergence (§3)** — added a phasing note that the §3 loader snippet is the final form and must not be pasted verbatim into Phase 0 (which omits Roster until Phase 5); plan owns the per-phase list.
26. **[NIT] Bug #5 scope (§11.5)** — added note that the L1390 password-reset `wp_mail` is intentionally out of scope for the registration email centralization.
