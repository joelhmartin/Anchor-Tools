# Anchor Shipping phase 3 + Shop Manager — design addendum

Date: 2026-10-13 · Extends `2026-10-03-anchor-shipping-design.md` · First client: DEKA Dental Lasers

## What changed since the phase 1–2 design (client input, 2026-10-13)

1. **Recommended flow from DEKA:** customer orders → staff get the new-order email and, with it, a **Create label** button → the button opens a page where one click (after they check box/weight) creates the label PDF for printing; the PDF stays on the site for later.
2. **One email, not two.** When an order has shippable products and the shipping inbox already receives WooCommerce's admin *New order* email, the Create-label block goes **inside that email**; the separate *Ready to ship* email is sent only to inbox addresses that are not New-order recipients.
3. **A front-end Shop Manager**, like the Events Manager, so staff never open wp-admin. The plugin ships the console as a shortcode; a client theme wraps it (DEKA: an admin-only page template, exactly as `template-events-manager.php` wraps `[event_manager]`). The Create-label button deep-links into the console's order view.
4. **Shipping inbox:** `info@dekadentallasers.com` (same inbox as the contact forms).
5. **Rates:** DEKA keeps the $19.95 flat rate for now but wants to switch later, so the phase-4 live-rate method is built now, **off and attached to no zone**.

## Decisions

- **Console, not a token page.** The "temporary page" is the Shop Manager's single-order view (login required; front-end `auth_redirect()` returns there). One label path, one permission model (`edit_shop_orders`), no unauthenticated label purchasing. DEKA needs a Shop Manager login per shipper (already on the go-live list).
- **Shortcode `[shop_manager]`** in `anchor-shipping/` (`Frontend\ShopManager`), capability `edit_shop_orders`, never cached, noindex. Views:
  - **Orders** — tabs: *Needs label* (state `needs_attention` or eligible-and-unlabelled), *Labelled* (awaiting first scan), *Shipped*, *Delivered*, *All*; search by order number / name / email; each row: number, date, customer, ship-to city/state, items count, total, shipping state, tracking.
  - **Order** — customer + ship-to (with UPS address-validation warning), items, order notes, the existing label panel (`OrderPanel::render_panel`, reused verbatim — same AJAX endpoints), label PDF inline preview + Print, order actions **Mark completed** and **Cancel order** (cancel triggers the existing void-on-cancel), and a link to the wp-admin order for anything else.
  - **Bulk** — select needs-label orders → confirm screen (box + weight per order, prefilled by Packer) → one merged PDF.
  - **Products** — price, sale price, stock and per-product weight + default box, edited inline (capability `edit_products`). Weights and boxes are what auto-labels need, so DEKA can supply them here.
- **New-order email integration:** hook `woocommerce_email_after_order_table` for email id `new_order` when the order is eligible (needs shipping + has a shipping line): render the Create-label block (button → console order URL). `PaidOrderHandler` still sets state `needs_attention`; `ReadyToShipEmail` sends only to inbox addresses not in the New-order recipient list (none if all are covered). Setting: *Put the Create label button in the New order email* (default on).
- **Tracking (UPS Track API)** `GET /api/track/v1/details/{n}` per number; map UPS `currentStatus.type`/`code`: `M`/`MV` → `label_created`, `I`/`P`/`O`-not-delivery → `in_transit`, `OT` (out for delivery) → `out_for_delivery`, `D` → `delivered`, `X` → `exception`, `RS` → `returned`. New domain value `TrackingResult { tracking_number, status, detail, event_at }`.
- **Poller** as in the original design (Action Scheduler, every 2 h, active rows < 30 days); first scan → customer `ShippedEmail` + state `shipped`; delivered → note + `delivered_at` + Completed if *Complete orders on delivery* (default on); exception → inbox email; `label_created` > 10 days → `voided` (UPS auto-void) note.
- **Customer-facing:** `ShippedEmail` (WC_Email, customer) on first scan only; My Account order view shows carrier, tracking link, status.
- **Cancel → UPS:** unchanged (phase 2 `VoidOnCancel`). Now also reachable from the console's Cancel button. Note: UPS CIE sample labels cannot be voided, so staging proves the call is made and failure is surfaced; acceptance of a void is only provable on the first production label.
- **Rates (phase 4, built dormant):** `UpsCarrier::rate()` (Shop + negotiated), `Checkout\RateMethod` (`WC_Shipping_Method` id `anchor_shipping_rates`) with services checklist + markup (flat/%) + 10-min cart-hash cache; returns nothing on any error so the zone's flat rate shows. Not added to any zone by the plugin.
- **Address validation:** `UpsCarrier::validate_address()` (XAV, street-level); shown as a non-blocking warning in the console order view and the wp-admin panel; cached on the order (`_anchor_shipping_xav`).

## Out of scope

Product creation/deletion, images and descriptions in the console, return labels, international customs, pickup scheduling.
