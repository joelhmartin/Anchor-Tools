# Event-First Commerce — Design Spec

**Status:** approved design (brainstormed 2026-06-24). Supersedes the *authoring model* of the
shipped product-first WooCommerce integration; **reuses** its seat/capacity/reconcile/roster/email
engines. Implementation plan to follow separately.

## 1. Overview & Goals

Turn the Anchor Events module into a **malleable, professional-grade events manager with a deep,
event-first WooCommerce integration**. The admin authors everything on the **event** — including its
ticket tiers — and the plugin manages the WooCommerce product(s) behind the scenes. WooCommerce is the
commerce engine (cart, checkout, tax, coupons, payments, refunds); the plugin owns events, capacity,
and the attendee roster.

This is a general-purpose design, not tuned to one site's event types. It must support the full
matrix — multiple **ticket tiers** per event, **sessions** (the same course on multiple dates),
**coupons**, free and paid registration, and direct admin roster management — cleanly.

**Design goals**
- **One place to author:** the event editor. The Woo product is a generated projection the admin
  rarely touches.
- **Single source of truth per concern:** the *event* owns price/title/availability and ticket tiers;
  the *plugin seat layer* owns capacity and the roster; *WooCommerce* owns the transaction.
- **No double ownership / no orphans:** declarative one-way sync (event → product), reconciled on
  every save; nothing with sales is ever hard-deleted.
- **WooCommerce-optional:** free events and event display work with WooCommerce absent; paid tiers
  require it and degrade gracefully when it's deactivated.
- **Evolution, not rewrite:** build additively on the already-merged-and-reviewed seat data layer,
  per-event capacity lock, idempotent order reconcile engine, refunds, roster, emails, and GDPR hooks.

## 2. Scope

**In scope**
- Event-first authoring of **ticket types** (tiers) on the event.
- **Auto-managed WooCommerce product** per paid event (tier = variation), created/synced from the event.
- **Series** grouping (sessions = events in a series) via a taxonomy with an archive/landing page.
- **Event-page storefront:** inline tier selection + quantity + AJAX add-to-cart; per-seat attendee
  capture at checkout.
- **Capacity** at two levels (event total + optional per-tier quota), single authority in the seat layer.
- **Coupons** via native WooCommerce (no custom engine).
- **Free + paid coexistence** within one event; fully-free events need no WooCommerce.
- **Admin manual roster add** (comped seats) with an over-capacity override; tier-aware edit/cancel.
- **Migration/coexistence** that preserves existing Event + free-registration data.

**Out of scope / deferred (design must not preclude, do NOT build here)**
- Email reminders, customer cancellation/refund emails, "send roster" digest (separate v1.1 email
  work — see `2026-06-20-events-email-gap-BRIEF.md`).
- Custom attendee questions UI (dietary/license/CE): the model **reserves** configurable attendee
  fields per ticket type/event, but the authoring UI for custom questions is a later feature.
- Waitlist auto-promotion, check-in / QR / mark attended, certificates, add-to-calendar / ICS,
  attendee transfer between events.
- Bulk add / CSV import (source = `imported`) — natural follow-on, not core.
- "Date-as-variation" axis — explicitly **not** built; sessions are separate events (§4).

## 3. Data Model

### 3.1 Event (existing CPT `event`, extended)
The atomic occurrence: date/time, timezone, location/virtual, **total capacity**, **roster**. Unchanged
fields stay. New: a **ticket types** collection (§3.2) and an optional **series** term (§3.3). The event
is the source of truth for ticket title/price/availability.

### 3.2 Ticket Type (tier)
A sellable option *within* an event. Stored as structured event meta (an ordered list), each entry:
- `id` — permanent internal id (stable across rename/reprice; never reused).
- `label` — e.g. "Early-bird", "VIP", "Member".
- `price` — decimal; `0` = free tier.
- `quota` — optional per-tier seat cap (0/empty = only bounded by event total).
- `sale_start` / `sale_end` — optional sale window.
- `attendee_fields` — which fields this tier collects (default name/email/phone; reserves space for
  custom questions later).
- `wc_variation_id` — the managed Woo variation this tier maps to (0 for a free tier / no Woo).
- `active` — false = deactivated (kept for history, no longer sold).

An event has 1..N ticket types. A one-off free event = a single `price = 0` ticket type with no Woo
product (today's internal registration).

### 3.3 Series (taxonomy)
A taxonomy on the Event CPT grouping events as "sessions of the same course." Provides a series
archive/landing page listing its sessions (date + "from $X" + availability). Optional — a one-off
event needs none. (Sessions are **separate events**, never product variations.)

### 3.4 Seat (existing `anchor_event_reg`, extended)
One record per attendee/seat — unchanged except a new `_anchor_event_ticket_type_id` linking the seat
to its tier. Existing seats default to the event's primary/only tier. All other seat meta, statuses,
history, and the reconcile/capacity behavior are retained.

## 4. WooCommerce Ownership & Product Mapping

- Each **paid event** maps to **one auto-managed WooCommerce variable product**; each **paid ticket
  type = one variation**, keyed by the tier `id` (stored in variation meta) for stable identity.
- The managed product is flagged `managed-by-events` and **hidden from catalog/search** (reached via
  the event page, not browsed), but is a real product so cart/checkout/tax/**coupons**/payments/refunds
  work natively.
- **Event is the source of truth** for title, price, and the variation set; the product is a generated
  projection. Sync is **one-way (event → product)** for those managed fields. Descriptive fields
  (product image, long description) may live product-side and are left untouched.
- **Capacity is NOT Woo stock** — it lives in the plugin seat layer (single authority), avoiding the
  dual-authority drift the review flagged. Managed products have stock management off.
- **Free tiers** have no Woo product/variation; they use the inline internal-registration path.

## 5. Event ⇄ Product Sync Engine & Lifecycle

Declarative, idempotent, one-way (event → product), reconciled on every event save — mirroring the
order-reconcile pattern.

- **Create-on-demand:** saving an event that has ≥1 paid tier ensures the managed product exists
  (creates if missing).
- **Reconcile each save:** add variations for new tiers; update price/label for changed tiers (matched
  by tier `id`); **deactivate, never hard-delete**, variations for removed tiers that have sales
  (preserves order/seat history); a tier with zero sales may be removed outright.
- **Event trashed/deleted:** the managed product is set to `draft` (sales stop); seats + orders are
  preserved. Nothing is silently destroyed.
- **Managed fields locked:** price/title/variation-set are owned by the event; direct product edits to
  those are overwritten on the next sync, with an admin notice. Descriptive fields are left alone.
- **WooCommerce deactivated:** paid tiers render unavailable; free tiers + event display keep working;
  managed products lie dormant and re-activate cleanly. No fatals (all Woo access guarded).
- **Escape hatch (advanced, optional):** allow linking an existing, self-managed product to a ticket
  type for power cases (bundles/memberships). Off the happy path; default everyone to auto-managed;
  capacity still owned by the plugin. The shipped product-first link is demoted to this path.

## 6. Event-Page Storefront, Checkout & Attendee Capture

**Event page (storefront, no hop to a product page):**
- A **ticket block** lists each tier with label, price, and live availability; each row has a
  **quantity** selector; one **"Register / Add to cart"** button adds the chosen quantities to the cart
  via **AJAX**, staying on the page with an "added — view cart / checkout" confirmation.
- Per-tier states: available (qty + price) · sold out (disabled) · over-capacity-with-waitlist ("Join
  waitlist") · outside sale window (hidden or "Sales open <date>").
- A buyer can add **multiple tiers at once** (separate cart lines, all resolving to this event).
- A **free tier** renders the lightweight inline form (name/email/phone) and registers immediately —
  no cart, no WooCommerce. An event may mix free and paid tiers.

**Checkout (standard WooCommerce):**
- **Per-seat attendee fields** appear at checkout — one block per seat, grouped by event/tier,
  required, guest-checkout safe (robust for multi-line/mixed carts). Configurable per tier/event
  (name/email/phone default; custom questions reserved for later).
- On `processing`/`completed`, one **seat** per seat is created, tagged with its tier, via the existing
  idempotent reconcile engine.

**Series page:** the series archive lists its sessions (each event: date, "from $X", availability),
linking to each event page.

## 7. Capacity, Quotas, Waitlist

- **Two levels, one authority.** The **event** has a total capacity (counts reserving seats —
  confirmed + pending — across all tiers). A **ticket type** may set its own **quota**. A purchase
  succeeds only if the tier quota *and* the event total both have room. All counting is in the seat
  layer under the per-event MySQL lock — never Woo stock.
- **Tier sold-out vs event full:** a tier whose quota is hit shows "Sold out" while other tiers stay
  sellable; when the *event total* is full, the existing **event-level waitlist** toggle governs
  (on → over-capacity purchases become `waitlist` seats; off → sold out everywhere). Waitlist stays
  event-level; promotion is deferred.
- **Refunds/cancellations** flow through the existing reconcile engine, now **tier-aware** (a refunded
  line releases that tier's quota and the event total).

## 8. Coupons
Native **WooCommerce coupons** only — percentage/fixed, per-product/category, usage limits, expiry —
applied at cart/checkout. Restrict a coupon to events by targeting the managed event products/category.
**No custom coupon engine** in the plugin.

## 9. Free / Paid Coexistence
An event can carry both free and paid tiers; free tiers render the inline form (no Woo), paid tiers go
through cart/checkout, and **they share the event's total capacity**. A fully-free event needs no
WooCommerce at all. This unifies the free and paid paths under one "ticket type" concept while keeping
free registration first-class and Woo-independent.

## 10. Admin: Manual Roster Management
- From the event's **roster screen** (and a quick "Add attendee" action on the event editor), an admin
  adds someone directly: name/email/phone (+ custom fields), pick the **ticket type**, set **status**
  (confirmed/pending/waitlist), **source = `manual`**, no order required (comped seats).
- Routes through the **seat data layer under the per-event lock**, so capacity, per-tier quota, and
  history stay correct and consistent with paid/free signups.
- **Over-capacity override:** manual adds respect capacity/quota by default; an "allow over capacity"
  checkbox lets an admin deliberately exceed it (recorded in seat history).
- Works for free and paid events alike. Existing edit/cancel/mark-status actions remain, gated by the
  WooCommerce-aware roster capability.

## 11. Migration & Coexistence
The paid/Woo layer is merged to `main` but **unreleased** (no versioned release, no live paid data);
the **free events + display have been live**. So this is an **evolution** of the merged foundation —
the reviewed engines (seat layer, capacity lock, reconcile, refunds, roster, emails, GDPR) are kept.
- **Preserve all existing data:** Event CPT + existing free registrations work untouched; an existing
  free event reads as an event with one implicit free tier. No destructive migration.
- **Seats gain `_anchor_event_ticket_type_id`** (existing seats default to the event's primary tier).
- **Authoring flips to event-first**; the shipped product-data "link to event" panel is **demoted to
  the advanced escape hatch** (§5). No forced migration — the few existing dev/staging links map onto
  the escape-hatch path.
- **WooCommerce stays optional** throughout.
- The build is mostly **additive**: ticket-type UI, the product sync engine, the storefront/AJAX
  add-to-cart, the Series taxonomy, and tier-awareness threaded through capacity/roster/reconcile.

## 12. Cross-Cutting Constraints
- **HPOS-safe:** all order access via WooCommerce CRUD; no order postmeta.
- **WooCommerce-optional:** no fatals when Woo is absent; paid features guarded by
  `class_exists('WooCommerce')` / `function_exists('wc_*')`.
- **Single capacity authority:** the plugin seat layer under the per-event `GET_LOCK`; never Woo stock.
- **Idempotent everywhere:** event→product sync and order→seat reconcile both converge with no
  duplicates or orphans on re-run.
- **i18n** (`anchor-schema` text domain), all output escaped, caps + nonces on every admin/AJAX action.
- **No PII in logs** (existing redaction retained).

## 13. Acceptance Criteria / Test Matrix
- Create an event with two paid tiers → one hidden managed variable product with two variations at the
  right prices; rename/reprice a tier → same variation updates (no orphan); remove a tier with sales →
  variation deactivated, history intact; remove a tier with no sales → removed.
- Event page shows tiers with live availability; add 1 VIP + 2 Regular via AJAX → three seats after
  checkout, each tagged with its tier; guest checkout works.
- Per-tier quota hit → that tier "Sold out", others sellable; event total full + waitlist on → waitlist
  seats; off → sold out everywhere.
- A WooCommerce coupon restricted to the event product discounts the order; seats still created correctly.
- Free tier on the same event registers via the inline form with WooCommerce absent.
- Series with three session-events → series page lists all three; each has its own capacity/roster.
- Admin manual add (comped) creates a confirmed seat with `source=manual`; over-capacity override
  exceeds capacity and is recorded in history.
- Event trashed → managed product set to draft, seats/orders preserved.
- WooCommerce deactivated → paid tiers unavailable, free + display intact, no fatals; reactivation clean.
- Existing free event + its registrations behave exactly as before; existing seats read the primary tier.
- Refund a tier line → releases that tier's quota + the event total (reconcile, idempotent on re-run).
