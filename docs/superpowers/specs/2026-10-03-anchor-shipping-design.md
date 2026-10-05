# Anchor Shipping — design

Date: 2026-10-03 · Status: approved in conversation · First client: DEKA Dental Lasers (dekadentallasers.com)

## Goal

Carrier labels and tracking from WooCommerce orders, with every record kept in Woo. Carrier-agnostic: UPS is the first carrier adapter; FedEx/USPS/etc. plug in later without touching the core.

Success for DEKA: a paid order produces a UPS label (billed to DEKA's account) that lands in their shipping inbox as a PDF; they print, pack and hand it off; the customer is emailed tracking once UPS actually scans the package; the order completes itself on delivery. Nobody at DEKA needs to open WP admin for a normal order.

## Verified facts this design rests on (2026-10-03)

- DEKA's UPS OAuth app (client-credentials) is approved in production and authorized for Shipping (`/api/shipments/v2409/ship`, void), Rating, Tracking (incl. reference tracking) and Address Validation. Quantum View is **not** authorized (401).
- UPS account `K877V9` is linked to the app: a production Rate call with `NegotiatedRatesIndicator` returned DEKA's negotiated rate (11.66 vs 22.58 published).
- The same credentials work against UPS CIE (`https://wwwcie.ups.com`): a test label was produced (sample label, no billing, not on the account's history). **All development and staging testing uses CIE.**
- Live store: no shipping plugin; one zone (North America) with a single flat rate of $19.95/order (since 2025-04-14; 2022–2024 orders charged $0); "hide shipping costs until an address is entered" is on, so checkout shows no shipping line until a full address is typed; no method exists outside North America; 21 orders with a shipping line in the last 90 days; 29 published non-virtual products, **none with a weight**; store units kg/cm; ship-from 400 North Ashley Drive, Tampa FL 33602; HPOS off (must still be HPOS-compatible).
- Post-billed (invoiced) UPS accounts are not charged for labels never scanned; UPS auto-voids unscanned labels after ~10 days. DEKA's account billing type still needs a human check on ups.com — the module voids on cancel/refund regardless.
- Three FACE CODE ticket products are flagged non-virtual. The module acts only on orders that `needs_shipping()` and carry a shipping line, so tickets never get labels.

## Architecture

New module `anchor-shipping/`, namespaced `Anchor\Shipping\` (PSR-4 `anchor-shipping/src/`), bootstrap pattern of `anchor-announcements` / `anchor-agreements`. Registry key `shipping`. Requires WooCommerce; the module no-ops with an admin notice if Woo is inactive.

```
anchor-shipping/
  anchor-shipping.php            Module bootstrap
  src/
    Carriers/CarrierInterface.php
    Carriers/CarrierRegistry.php filter: anchor_shipping_carriers
    Carriers/Ups/UpsCarrier.php  adapter (rate, ship, void, track)
    Carriers/Ups/UpsClient.php   OAuth token cache + HTTP (CIE/prod base URL)
    Domain/Address.php, Parcel.php, ShipmentRequest.php, LabelResult.php,
           TrackingResult.php, RateQuote.php      plain value objects
    Domain/CarrierError.php      typed exception (code, message, retryable)
    Database/Migrations.php, ShipmentRepository.php
    Packing/Packer.php           order/cart -> Parcel[] or "can't size"
    Services/LabelService.php    create/void + label storage + notes
    Services/AutoShip.php        paid-order trigger, fallback
    Services/TrackingPoller.php  Action Scheduler recurring job
    Services/LabelStore.php      protected PDF storage + authenticated download
    Admin/SettingsPage.php       WooCommerce > Anchor Shipping
    Support/Settings.php         option + wp-config constant overrides
    Admin/OrderMetabox.php       per-order panel (HPOS + legacy screens)
    Admin/BulkLabels.php         orders-list bulk action + confirm screen
    Admin/ProductFields.php      per-product default box (weight uses Woo's native field)
    Emails/ReadyToShipEmail.php, LabelEmail.php, ShippedEmail.php  (WC_Email subclasses)
    Checkout/RateMethod.php      WC_Shipping_Method (phase 4)
  assets/admin.css, admin.js
```

### Carrier contract

```php
interface CarrierInterface {
  public function id(): string;                 // 'ups'
  public function label(): string;              // 'UPS'
  public function supports( string $feature ): bool; // 'labels','void','track','rates'
  public function services(): array;            // code => label, e.g. '03' => 'UPS Ground'
  public function settings_fields(): array;     // rendered by Admin\Settings
  public function create_label( ShipmentRequest $r ): LabelResult;      // one LabelResult per parcel
  public function void_label( string $shipment_id ): void;
  public function track( array $tracking_numbers ): array;              // TrackingResult[]
  public function rate( ShipmentRequest $r ): array;                    // RateQuote[]
}
```

Adapters throw `CarrierError`; core code never sees carrier JSON. Core normalizes tracking into a small status set: `label_created`, `in_transit`, `out_for_delivery`, `delivered`, `exception`, `returned`, `voided`. Third-party carriers register via `add_filter( 'anchor_shipping_carriers', fn( $c ) => $c + [ 'fedex' => new FedexCarrier() ] )`.

### UPS adapter

- OAuth client-credentials token cached in a transient keyed by environment, refreshed 5 min before `expires_in`.
- Environment per carrier: `sandbox` → `wwwcie.ups.com`, `production` → `onlinetools.ups.com`. Default **sandbox**.
- Ship: `RequestOption=nonvalidate`, `BillShipper` to the configured account, label image GIF (rotated/cropped and wrapped into a 4×6 portrait PDF by LabelStore — UPS Ship API has no PDF format) or ZPL for thermal printers (setting). **No `ShipTo` email and no UPS notification options** — UPS must not email the customer.
- Void: `DELETE /api/shipments/v2409/void/cancel/{id}`.
- Track: `/api/track/v1/details/{n}`, one request per number (UPS has no batch); map UPS status types to the normalized set.
- Rate: `Shop` request with `NegotiatedRatesIndicator`; returns negotiated where present.

### Credentials and settings

Settings page at WooCommerce > Anchor Shipping (own admin page; Woo's shipping-tab sections are reserved for zone methods and render unreliably for custom forms); stored in option `anchor_shipping_settings` (`autoload=false`). Secrets render as password inputs and are never echoed back. Constants override saved values and grey out the field: `ANCHOR_SHIPPING_UPS_CLIENT_ID`, `ANCHOR_SHIPPING_UPS_CLIENT_SECRET`, `ANCHOR_SHIPPING_UPS_ACCOUNT` (DEKA keeps these in `wp-config.php`, as with Mailgun).

Settings: enabled carriers; per-carrier environment + credentials; default carrier + service; ship-from address (defaults to Woo store address); box presets (name, L×W×H, empty weight, max weight); shipping inbox email(s); **"Create labels automatically when an order is paid" checkbox (default off)**; complete-on-delivery on/off; label format.

## Data

Table `{prefix}anchor_shipments`, one row per parcel:

| column | notes |
|---|---|
| id | PK |
| order_id | indexed |
| carrier, service | |
| shipment_id | carrier's shipment identifier (UPS ShipmentIdentificationNumber) |
| tracking_number | indexed |
| status | normalized set above, indexed |
| status_detail, last_event_at, delivered_at | from tracking |
| cost, currency | negotiated charge returned at label time |
| label_path, label_format | path relative to protected dir |
| box, weight, dims | what was shipped |
| source | `auto` / `manual` / `bulk` |
| created_by, created_at, voided_at, last_polled_at | |

Every create/void/delivery/exception also writes an order note. Order meta `_anchor_shipping_state` (`pending` / `needs_attention` / `labelled` / `shipped` / `delivered`) drives the orders-list column and stops double-creation.

Labels: `wp-content/uploads/anchor-shipping/` with a deny-all `.htaccess` plus `index.php`; served only through an admin-ajax download handler that checks `edit_shop_orders` and a nonce. (Kinsta runs nginx, which ignores `.htaccess`, so filenames also carry a random 32-char token; the handler is the only supported path.)

## Flows

**Paid-order handling.** On `woocommerce_order_status_processing` (paid), if the order needs shipping, has a shipping line and is not already labelled: enqueue an async Action Scheduler job (never block checkout). What the job does depends on the auto-label checkbox:

- **Checkbox off (DEKA's starting state):** state `needs_attention`, email `ReadyToShipEmail` to the shipping inbox — items, address, the Packer's suggested box/weight when it has one, and a **Create label** button deep-linking to the order panel (login required). A person confirms box + weight and creates the label there; the label PDF is then emailed to the inbox as well (`LabelEmail`).
- **Checkbox on:** the job asks `Packer` for parcels:
  - Every shipped item has a weight and a default box, and everything fits one box (sum of weights ≤ box max; box = the largest default box among the items) → create the label, store it, email `LabelEmail` (PDF attached, links to reprint/void) to the shipping inbox.
  - Otherwise → fall back to the checkbox-off behaviour for this order: state `needs_attention`, email `ReadyToShipEmail` to the shipping inbox (items, address, why it couldn't auto-size, **Create label** button that deep-links to the order panel; login required). No guessing.
  - Carrier error → `needs_attention`, note with the carrier message, ReadyToShip email including the error. Retryable errors retry 3× with backoff first.

**Protection: declared value (insurance) and signature.** Two independent options, each with a mode setting:

| Setting | `off` | `customer` | `auto` |
|---|---|---|---|
| Insurance (declared value) | never | checkout checkbox "Insure my shipment" adds a fee; label declares the order's shippable subtotal | label declares the shippable subtotal whenever it exceeds a threshold (default $100, UPS's free coverage); no checkout UI |
| Signature required | never | checkout checkbox "Require a signature" adds a fee | every label requires a signature when the shippable subtotal exceeds a threshold |

Customer fees are simple rules, not live quotes: insurance = `rate per $100` × ceil((subtotal − 100) / 100) (0 at or under $100); signature = flat amount. Both amounts are settings with no default (the checkbox is hidden until the store sets a fee). The choice is stored on the order (`_anchor_shipping_insure`, `_anchor_shipping_signature`) as a Woo fee line plus meta, and every label path (auto, manual, bulk) reads it; the order panel shows it and lets staff override per label. Reference prices verified 2026-10-03 on DEKA's account (2 lb Ground, Tampa→CA): base 11.66; declared $500 +4.75; declared $2,000 +19.00; signature +7.70. UPS: `PackageServiceOptions.DeclaredValue` and `PackageServiceOptions.DeliveryConfirmation.DCISType = 2` (signature required).

**Manual (order panel).** Carrier, service, box preset or custom dims, weight → Create label. (UPS labels carry no ship date; the label is valid until scanned or auto-voided.) Lists shipments with Print / Void / Track. Address validation warning (UPS XAV) shown but not blocking — phase 3.

**Bulk.** Orders list bulk action "Create shipping labels" → confirm screen with one row per order (box + weight prefilled from Packer, editable) → creates sequentially → one merged PDF (new Composer dependency `setasign/fpdi`; ZPL labels are concatenated instead) plus per-order results. Orders already labelled are skipped and listed.

**Cancel/refund.** On `cancelled` or full `refunded`, void every non-voided, not-yet-scanned shipment and note it. If the carrier refuses (already scanned), note it and email the shipping inbox.

**Tracking poll.** Recurring Action Scheduler job every 2 h over rows not in `delivered/voided/returned` and created < 30 days ago. On the first scan (`in_transit`) → send `ShippedEmail` to the customer (tracking number + carrier link) and set state `shipped`. On `delivered` → note, `delivered_at`, and mark the order Completed if the setting is on. On `exception` → note + email the shipping inbox. Rows still `label_created` after 10 days are marked voided (UPS auto-void) with a note.

**Customer.** No notification at label creation. `ShippedEmail` fires on first carrier scan only. My Account order view shows carrier, tracking number and status.

**Live checkout rates (phase 4, off by default).** `RateMethod` added per zone; Packer runs on the cart; offers the services selected in settings at negotiated rate + optional markup (flat or %). Any carrier error or un-sizeable cart → the method returns no rates so the zone's flat rate (kept as the fallback) is what the customer sees. Rate results cached per cart hash for 10 min.

## Error handling

`CarrierError` carries carrier code, message and `retryable`. All carrier HTTP is logged through `wc_get_logger()` source `anchor-shipping` with credentials and full label images stripped. Admin actions surface the carrier message verbatim in the panel.

## Testing

- PHPUnit (existing harness): `UpsCarrier` against recorded CIE fixtures via `pre_http_request` (ship, void, track statuses, rate, auth failure, token refresh); Packer rules; AutoShip decision table (sizeable / missing weight / multi-box / virtual-only / ticket product / already labelled); poller state transitions; cancel→void; email triggers (no customer email at label time).
- Staging (`stg-dekalasers-stage.kinsta.cloud`) end to end in **sandbox** mode against CIE: auto-ship, fallback email, manual, bulk, void, poller (CIE tracking test numbers).
- Production goes live by flipping the environment to production; the first production label is a real order. No production test labels.

## Phases

1. Core + UPS adapter + manual order panel + label storage + settings (incl. box presets, product box field).
2. Paid-order handling (checkbox) + ReadyToShip/Label emails + cancel/refund void + protection (insurance/signature, all three modes).
3. Tracking poller + customer Shipped email + My Account + complete-on-delivery + bulk labels.
4. Live checkout rates.

## Outside the code (DEKA inputs)

- Real weight and default box for each of the 29 physical products, and the box sizes they use. Until provided, leave the auto-label checkbox off; every order takes the ReadyToShip path (fully functional, one click).
- Shipping inbox address.
- Confirm on ups.com that K877V9 is invoice-billed.
- A Shop Manager login for whoever ships.

## Out of scope

International customs forms, freight/LTL, return labels, pickup scheduling, multi-origin shipping, Quantum View account feed.
