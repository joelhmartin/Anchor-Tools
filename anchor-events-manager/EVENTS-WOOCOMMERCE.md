# Anchor Events + WooCommerce — Usage

Event-first model: you author everything on the **event**, and the plugin manages the WooCommerce
product behind the scenes. WooCommerce is optional — free events work without it.

This document covers the WooCommerce-ticketed (`wc`) registration mode specifically. For the full
picture — the other two registration modes (Free internal, External), the four event types (Single,
Multi-session series, Pick-one offerings, Recurring schedule), and the occurrence/grouping engine that
generates one full event post per date for Pick-one/Recurring events — see `EVENTS.md`. Everything below
about ticket tiers, capacity, and managed products applies per-event, including to each generated child
event of a Pick-one-offerings or Recurring-schedule parent (each gets its own managed product).

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
- The storefront row, `is_purchasable`, the add-to-cart endpoint, the choose-a-date picker, the series
  archive and the JSON-LD offers all ask one predicate — `Module::bookability( $event_id, $tier )` →
  `open|waitlist|full|closed|parent|disabled` — so a row can never advertise a seat the cart then
  refuses. Beyond capacity it also answers for the "Sold out" flag, the registration open/close window,
  an event whose own end date has passed, a container (group parent), a soft-closed occurrence and
  registration being switched off. When nothing on the page is sellable, the "Register / Add to cart"
  button is not rendered at all, and a refusal at the cart says which of these it was.

## Coupons

Use **native WooCommerce coupons** (Marketing → Coupons). Restrict a coupon to events by targeting the
managed event products/category. No separate coupon system in the plugin.

## Sessions (Series)

Group "the same course on multiple dates" — each date its own event (own date, capacity, roster) — with
the **`event_series`** taxonomy on the event; the series archive (`/series/<slug>/`) lists all sessions
with date, "from $X", and availability. This taxonomy is populated two ways:

- **Manually**, by tagging separate events with the same Series term yourself.
- **Automatically**, for a **Pick-one offerings** or **Recurring schedule** parent event — the plugin
  generates one child event per date/occurrence and tags parent + every live child with a shared,
  engine-managed series term; the archive collapses that group into one "choose a date" row instead of
  listing every child separately. See `EVENTS.md` for the full offering/recurring/grouping model.

Don't confuse this with the **Multi-session series** event *type* (`multisession`): that's a single event
post whose sessions are stored as rows on that one post (one signup covers every date) — no separate event
posts, no Series taxonomy involved. Use the Series taxonomy (manual or offering/recurring) when each date
needs its own capacity/roster/registration; use the `multisession` type when one registration covers all
of them. See `EVENTS.md` for both.

## Roster & admin

- **Events → Roster** (or the event's roster screen): view attendees, filter/search, export CSV
  (includes the tier). Capability: `manage_woocommerce` when WooCommerce is active, else
  `edit_others_posts`.
- That capability is resolved in exactly one place — `Module::events_capability()` — and every
  surface that shows or acts on attendee/customer data uses it: the Roster screen and its manual
  seat actions, the CSV export, the `[event_registrants_list]` and `[event_manager]` shortcodes,
  the order actions (Resync / Mark reviewed / Resend confirmation) and their needs-review notice,
  and the event error log, plus the console's own save handler.
- **On a store this means a stock Editor loses all of those** — an Editor holds `edit_others_posts`
  but not `manage_woocommerce`. Map the module to your own capability site-wide with the
  `anchor_events_capability` filter (the second argument says whether WooCommerce is active):

  ```php
  add_filter( 'anchor_events_capability', fn( $cap, $wc ) => 'manage_event_roster', 10, 2 );
  ```
- The CSV export additionally refuses any id that is not a live `event` post (a trashed or deleted
  event exports nothing), and a manual seat action only accepts a seat that belongs to the event
  its nonce was issued for.
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
