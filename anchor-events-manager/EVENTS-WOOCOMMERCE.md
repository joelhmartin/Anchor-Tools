# Anchor Events + WooCommerce — Usage

Event-first model: you author everything on the **event**, and the plugin manages the WooCommerce
product behind the scenes. WooCommerce is optional — free events work without it.

## Create a paid event

1. **Events → Add New.** Set date/time, location (or mark virtual + add the join URL), and the event
   **capacity**.
2. In the **Tickets / Pricing** metabox, add one or more **ticket types** (tiers): label, price, optional
   per-tier **quota**, optional sale window, active toggle. (No tiers = a single free "Registration"
   tier driven by the legacy price field.)
3. **Publish.** If any tier has a price > 0, the plugin auto-creates one **hidden, managed WooCommerce
   variable product** (one variation per paid tier). You do not edit that product directly — its price,
   title, and variations are owned by the event (direct edits are restored, with a notice).

## How buyers register

- The **event page** shows each tier with its price and live availability and a quantity selector;
  "Register / Add to cart" adds the chosen quantities to the cart inline (AJAX) — no hop to a product
  page. Multiple tiers can be added at once.
- At **checkout**, the buyer fills in **per-seat attendee details** (name/email/phone), grouped by
  event/tier. Guest checkout is supported.
- On payment (`processing`/`completed`), one **seat** is created per seat, tagged with its tier, via the
  idempotent order-sync engine. Refunds/cancellations release the tier's quota and the event total.
- **Free tiers** show a lightweight inline form and register immediately — no cart, no WooCommerce.

## Capacity & waitlist

- Two levels, one authority (the plugin's seat layer, under a per-event lock): the **event total** plus
  each tier's optional **quota**. A purchase needs room in both.
- A tier whose quota is hit shows "Sold out" while other tiers keep selling. When the **event total** is
  full, the event's **waitlist** toggle governs (on → over-capacity buyers become `waitlist` seats).

## Coupons

Use **native WooCommerce coupons** (Marketing → Coupons). Restrict a coupon to events by targeting the
managed event products/category. No separate coupon system in the plugin.

## Sessions (Series)

Group "the same course on multiple dates" with the **Series** taxonomy on the event. Each session is its
own event (own date, capacity, roster). The series archive (`/series/<slug>/`) lists all sessions with
date, "from $X", and availability.

## Roster & admin

- **Events → Roster** (or the event's roster screen): view attendees, filter/search, export CSV
  (includes the tier). Capability: `manage_woocommerce`/`edit_shop_orders` when WooCommerce is active,
  else `edit_others_posts`.
- **Add attendee** directly (comped, no order): pick a tier, optionally tick **Allow over capacity** to
  exceed the limit (recorded in the seat's history). Edit/cancel/mark-status are tier-aware.

## Advanced: link an existing product

The product editor still has an **"Event Registration (advanced link)"** panel to attach a self-managed
product to an event (bundles/memberships). This is the secondary path — auto-managed event products use
the event-first flow above and can't be double-linked.

## WooCommerce off / migration

- With WooCommerce inactive: paid tiers are unavailable, but free events, event display, the calendar,
  and free registration all work; managed products lie dormant and reactivate cleanly.
- Existing events and free registrations are preserved unchanged — an event with no tiers reads as a
  single implicit "primary" tier, and existing seats report the `primary` tier.
