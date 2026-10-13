# Anchor Shipping phase 3 + Shop Manager — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use `- [ ]`.

**Goal:** Tracking, customer shipped email, a front-end Shop Manager console (orders, labels, bulk), the Create-label button inside WooCommerce's New order email, address validation, and dormant live rates — then a staging test order end to end.

**Architecture:** Everything lives in the existing `anchor-shipping/` module (`Anchor\Shipping\`). The console is a shortcode (`[shop_manager]`) that reuses the wp-admin label panel and its AJAX endpoints; a client theme wraps it (DEKA template added after release). Carrier specifics stay inside `Carriers/Ups/`.

**Tech stack:** PHP 8.1, WordPress, WooCommerce (HPOS-compatible, DEKA runs legacy posts), Action Scheduler, UPS REST APIs, PHPUnit (existing harness, `tests/class-anchor-shipping-testcase.php`), `setasign/fpdi` (new, bulk PDF merge).

**Spec:** `docs/superpowers/specs/2026-10-03-anchor-shipping-design.md` + addendum `docs/superpowers/specs/2026-10-13-anchor-shipping-phase-3-shop-manager.md` (the addendum wins where they differ).

## Global Constraints

- Branch `feat/anchor-shipping-phase-3`, worktree `~/Developer/anchor-os/anchor-tools-wt-shipping`. Never push to `main`; `git push origin feat/anchor-shipping-phase-3` only.
- Text domain `'anchor-schema'` (module convention). Every class `declare(strict_types=1)`, `if ( ! \defined( 'ABSPATH' ) ) { exit; }`.
- All carrier HTTP goes through `UpsClient::request()`; tests stub HTTP with `pre_http_request` + JSON fixtures in `tests/fixtures/shipping/`. No test touches a real UPS host.
- Every console/AJAX action checks `current_user_can( 'edit_shop_orders' )` + a nonce. No unauthenticated path can create, void, download or view a label or an address.
- The UPS customer must never be emailed by UPS (no ShipTo email / notifications). Customer email only via `ShippedEmail` on first scan.
- Default settings keep DEKA's current behaviour: auto-label off, rates method attached to no zone.
- Before each commit: `git checkout -- vendor/composer` is NOT needed here (vendor untracked); run `vendor/bin/phpunit --filter Shipping` (see harness recipe in `tests/class-anchor-shipping-testcase.php` header / memory `anchor-tools-test-harness-tmp-purge`).

## Review Focus

1. **Tracking a voided or deleted row** — the poller must skip `voided/delivered/returned` and never resurrect a voided label's status.
2. **Duplicate customer emails** — `ShippedEmail` fires once per order even with several packages and repeated polls (`_anchor_shipping_shipped_sent` guard).
3. **New-order email for non-shippable orders** (FACE CODE tickets, virtual items, no shipping line) — no Create-label block, no Ready-to-ship email.
4. **Console access by a logged-in customer** — a subscriber/customer hitting the console page or its AJAX gets 403, never order data.
5. **Cancel from the console after the label was scanned** — order still cancels; void refusal is noted + inbox emailed (existing `VoidOnCancel` path), never a fatal.

---

### Task 1: UPS tracking

**Files:** Create `src/Domain/TrackingResult.php`; modify `src/Carriers/Ups/UpsCarrier.php` (`track()`); fixtures `tests/fixtures/shipping/ups-track-{label-created,in-transit,out-for-delivery,delivered,exception,returned,not-found}.json`; test `tests/test-shipping-ups-tracking.php`.

**Interfaces — produces:** `final class TrackingResult { public function __construct( public string $tracking_number, public string $status, public string $detail, public ?int $event_at ) {} }` (status ∈ `label_created|in_transit|out_for_delivery|delivered|exception|returned|unknown`). `UpsCarrier::track( array $numbers ): array` → `TrackingResult[]` keyed by tracking number; one `GET /api/track/v1/details/{n}` per number with headers `transId`, `transactionSrc: anchor-shipping`; a "not found" response → status `unknown` (not an exception); auth/network errors → `CarrierError` (retryable per existing client rules).

- [ ] Failing tests: each fixture maps to its status; `event_at` parsed from latest activity `date`+`time` (UTC); not-found → `unknown`; two numbers → two requests.
- [ ] Implement mapping per addendum (`M`,`MV` → label_created; `I`,`P` → in_transit; `O` with `code` `OT`/description "Out For Delivery" → out_for_delivery; `D` → delivered; `X` → exception; `RS` → returned).
- [ ] Green, commit `feat(shipping): UPS tracking`.

### Task 2: Tracking poller + delivery completion

**Files:** Create `src/Services/TrackingPoller.php`; modify `ShipmentRepository.php` (`pollable( int $limit ): array`), `Settings.php` (`complete_on_delivery` default `true`), `SettingsPage.php` (checkbox), `anchor-shipping.php` (wire + schedule); test `tests/test-shipping-tracking-poller.php`.

**Interfaces — consumes:** Task 1. **Produces:** `TrackingPoller::ACTION = 'anchor_shipping_poll_tracking'` recurring every 7200 s in group `anchor-shipping` (schedule on `init` if not scheduled); `TrackingPoller::poll(): void`; action `do_action( 'anchor_shipping_first_scan', int $order_id, array $row )`; action `do_action( 'anchor_shipping_exception', int $order_id, string $detail )`.

- [ ] Failing tests: pollable = status not in voided/delivered/returned AND created < 30 days, oldest `last_polled_at` first, limit 50; transitions update `status`, `status_detail`, `last_event_at`, `last_polled_at`; first non-`label_created` status on an order → `anchor_shipping_first_scan` once (order meta `_anchor_shipping_shipped_sent`) and state `shipped`; all packages delivered → `delivered_at`, note, state `delivered`, order → completed iff setting on and order is processing; exception → note + `anchor_shipping_exception` → `ProblemEmail` to inbox; `label_created` and created > 10 days → status `voided`, `voided_at`, note "UPS auto-voided (never scanned)"; carrier error on one number doesn't stop the others (logged).
- [ ] Implement; wire in `Module`; deactivation is not needed (AS cleans up with group unschedule in uninstall — add to `uninstall.php` only if the module already unschedules there).
- [ ] Green, commit.

### Task 3: Customer shipped email + My Account tracking

**Files:** Create `src/Emails/ShippedEmail.php`, `templates/emails/shipped.php`, `shipped-plain.php`, `src/Frontend/AccountTracking.php`; modify `Emails/Registry.php`, `anchor-shipping.php`; test `tests/test-shipping-shipped-email.php`.

**Interfaces — consumes:** `anchor_shipping_first_scan`. **Produces:** WC email id `anchor_shipping_shipped` (customer email, default enabled), subject `Your order #{order_number} has shipped`; body: carrier, every non-voided tracking number as a link (`CarrierInterface::tracking_url`), shipping address, order items. `AccountTracking` hooks `woocommerce_order_details_after_order_table` and prints carrier / tracking link / status for non-voided rows.

- [ ] Failing tests: trigger sends once to billing email; no email at label creation; voided rows excluded; My Account output contains the tracking link and nothing for orders without shipments.
- [ ] Implement, green, commit.

### Task 4: Console URL + Create-label block in the New order email

**Files:** Create `src/Support/Urls.php`, `templates/emails/create-label-block.php`; modify `Settings.php` (`console_page` int default 0, `new_order_button` bool default true), `SettingsPage.php` (page dropdown via `wp_dropdown_pages`, checkbox), `Emails/ReadyToShipEmail.php` (recipient filtering), `anchor-shipping.php`; test `tests/test-shipping-new-order-email.php`.

**Interfaces — produces:** `Urls::order( \WC_Order $o ): string` → console page permalink + `?order={id}` when `console_page` is set and published, else `$o->get_edit_order_url()`; `Urls::console( array $args = [] ): string`. `Urls::new_order_recipients(): string[]` (lower-cased, from `woocommerce_new_order_settings['recipient']`, empty if the email is disabled).

- [ ] Failing tests: `new_order` email for an eligible order (needs shipping + shipping line) contains the block with `Urls::order()`; ineligible orders (virtual/ticket, no shipping line) → no block; other email ids (customer emails) → never; setting off → no block; ReadyToShip recipients = inbox minus New-order recipients; all covered → ReadyToShip not sent (state still `needs_attention`); setting off → ReadyToShip to full inbox (today's behaviour). Use the ready-to-ship template's button styling for the block; text "Create shipping label".
- [ ] Implement via `woocommerce_email_after_order_table( $order, $sent_to_admin, $plain, $email )` with `'new_order' === $email->id`; ReadyToShip via its `get_recipient()` override. Green, commit.

### Task 5: Shop Manager console (orders + order view + actions)

**Files:** Create `src/Frontend/ShopManager.php`, `src/Frontend/ConsoleAjax.php`, `templates/console/orders.php`, `templates/console/order.php`, `assets/console.css`, `assets/console.js`; modify `src/Admin/OrderPanel.php` (make `render_panel` usable without `is_admin()` — move asset enqueue into a static `enqueue()` callable from front end), `anchor-shipping.php` (instantiate panel + Ajax unconditionally so admin-ajax works for front-end calls; keep metabox registration admin-only); test `tests/test-shipping-shop-manager.php`.

**Interfaces — consumes:** `Urls` (Task 4), `OrderPanel::render_panel`, existing `Admin\Ajax` endpoints. **Produces:** shortcode `[shop_manager]`; query args `tab` (`needs-label|labelled|shipped|delivered|all`, default `needs-label`), `s`, `paged`, `order`; AJAX `anchor_shipping_console_status` (`order_id`, `to` ∈ `completed|cancelled`, nonce `anchor_shipping`); filter `anchor_shipping_console_capability` (default `edit_shop_orders`).

- [ ] Failing tests: logged-out → shortcode returns a login link (theme template does the redirect); customer role → "not permitted" text, no order data; shop manager sees tabs with correct membership (state meta + `needs_shipping` + shipping line; uses `wc_get_orders` with `meta_query`, HPOS-safe); search by number/email/last name; order view renders ship-to, items, notes, the label panel, an inline `<iframe>`/link to the latest label via `Ajax::download_url`; status AJAX: completed/cancelled transitions, capability + nonce enforced, cancel fires `woocommerce_order_status_cancelled` (so `VoidOnCancel` runs); unknown `to` rejected.
- [ ] Implement: plain, accessible markup; classes prefixed `asm-`; no theme assumptions (theme styles it); `nocache_headers()` + `wp_robots_no_robots` when the shortcode renders.
- [ ] Green, commit.

### Task 6: Bulk labels + orders-list column

**Files:** `composer require setasign/fpdi:^2.6` (+ `setasign/fpdf` if fpdi requires it); create `src/Services/BulkLabels.php`, `src/Support/PdfMerge.php`, `templates/console/bulk.php`, `src/Admin/OrdersColumn.php`; modify `ShopManager` (Bulk view: checkboxes on *Needs label* tab → confirm screen), `ConsoleAjax` (`anchor_shipping_bulk_create`), wp-admin bulk action `anchor_shipping_bulk` (legacy + HPOS screens) redirecting to the console Bulk view when a console page is set, else an admin confirm screen rendered by the same template; test `tests/test-shipping-bulk.php`.

**Interfaces — produces:** `BulkLabels::create( array $rows ): array` where row = `[order_id, box, weight, length?, width?, height?]`, returns `['created'=>[order_id=>row_ids], 'skipped'=>[order_id=>reason], 'failed'=>[order_id=>message], 'pdf'=>?string relative path]`; sequential, reuses `LabelService::create_for_order` (source `bulk`); already-labelled → skipped; merged PDF saved through `LabelStore` and downloadable via a new `Ajax::bulk_download_url( string $rel )` with the same capability/nonce checks. ZPL labels concatenated to one `.zpl`. Column "Shipping" shows state + tracking.

- [ ] Failing tests: mixed batch (ok / already labelled / carrier error) → correct buckets; merge of two fixture PDFs has 2 pages; column renders per state.
- [ ] Implement, green, commit (include `composer.json` + `composer.lock`).

### Task 7: Address validation warning

**Files:** modify `UpsCarrier.php` (`validate_address( Address $a ): array` → `['valid'=>bool,'ambiguous'=>bool,'candidates'=>Address[]]`, `POST /api/addressvalidation/v2/1`), `CarrierInterface.php` (+ `supports('address_validation')`), `OrderPanel::render_panel` (warning line), fixtures `ups-xav-{valid,ambiguous,invalid}.json`; test `tests/test-shipping-address-validation.php`.

- [ ] Failing tests: three fixture outcomes; result cached in order meta `_anchor_shipping_xav` keyed by an address hash (re-validates when the address changes); carrier error → no warning, no exception; warning never blocks Create label.
- [ ] Implement, green, commit.

### Task 8: Live rates (dormant)

**Files:** create `src/Domain/RateQuote.php`, `src/Checkout/RateMethod.php`; modify `UpsCarrier::rate()`, `Packer` (`pack_cart( array $cart_contents ): PackResult` sharing the order rule), `anchor-shipping.php` (`woocommerce_shipping_methods` registration only); fixtures `ups-rate-shop.json`; test `tests/test-shipping-rates.php`.

**Interfaces — produces:** `RateQuote { service, label, amount, currency, negotiated: bool }`; `RateMethod` id `anchor_shipping_rates`, instance settings: services (multi-check of `UpsCarrier::services()`), markup type (`none|flat|percent`), markup amount; rates cached in a transient keyed by cart hash + destination for 600 s.

- [ ] Failing tests: Shop fixture → negotiated amounts preferred; markup applied; un-packable cart → no rates; `CarrierError` → no rates + log; the method is not added to any zone by activation (assert zone methods unchanged).
- [ ] Implement, green, commit.

### Task 9: Final wiring, docs, release prep

- [ ] Full shipping suite + full plugin suite green locally.
- [ ] `readme.txt` changelog entry; bump module `VERSION` to `1.1.0`.
- [ ] Commit; push branch. (PR, CodeRabbit capacity check, merge on human sign-off, release tag — controller.)

### Controller steps (not subagent tasks)

1. **DEKA theme template** `template-shop-manager.php` (admin + shop manager, `auth_redirect`, wraps `[shop_manager]`, house styling like `template-events-manager.php`); page "Shop Manager" (noindex) on live, set as `console_page`.
2. **Staging end to end (sandbox/CIE)** after installing the branch build on staging: inbox → my address for the test (staging mail is real), test order with a shippable product to a test customer email → New order email shows the button → console → Create label → PDF prints → `LabelEmail` arrives → simulate poll with a CIE tracking number → Shipped email → cancel a second labelled test order → void attempted, CIE refusal surfaced to the inbox as designed.
3. **Live config (after merge + release, DEKA still disabled until weights/boxes):** inbox `info@dekadentallasers.com`; recommend adding info@ to the New order recipients so it gets one email.
