# Anchor Store — design

2026-10-29. Status: design, ready for plan.

## Why

Store behaviour that belongs to WooCommerce, not to a look, lives in the client themes, and two themes already carry two copies of it:

- **TMJ International** (`tmj-international`): ticket upsells in the cart drawer (`inc/upsells.php`), the cart drawer, floating cart button, coupon field and checkout coupon row (`inc/commerce.php`, `assets/js/cart.js`), "better of two" coupons and the Dentist-tier association codes (`inc/coupons.php`), GA4 ecommerce events (`inc/ecommerce-tracking.php`).
- **DEKA** (`deka`): its own cart drawer, coupon field and checkout coupon row (`functions.php` ~lines 160–330, `assets/js/cart-drawer.js`). TMJ's `cart.js` says in its header that it is a port of DEKA's.

The owner asked for this to move into the plugin as "Anchor Store" (2026-10-29) so every site gets one implementation and each theme keeps only its styling.

Neither theme is in a git repo; both live only on their servers. Their current copies were read from the live servers on 2026-10-29.

## Shape

New module `store`: "Anchor Store", `\Anchor\Store\Module`, PSR-4 under `anchor-store/src/`, structured like `anchor-agreements/` (Admin / Frontend / Services / Support). It is constructed only when WooCommerce is active and is off by default.

There are four features, each with its own on/off setting, all off by default:

| Feature | Setting key | Ported from |
|---|---|---|
| Cart drawer (drawer, floating button, header cart link, coupon field, checkout coupon row, return-and-open after a non-AJAX add) | `cart_drawer` | TMJ `inc/commerce.php` + `cart.js` (the newer port; DEKA's is the same design) |
| Ticket upsells in the drawer | `upsells` | TMJ `inc/upsells.php` + `tmj_cart_upsell_*` |
| Coupon rules (better of two, tier-restricted codes) | `coupon_rules` | TMJ `inc/coupons.php` |
| GA4 ecommerce events | `ga4` | TMJ `inc/ecommerce-tracking.php` |

The settings page lives under WooCommerce → Anchor Store, with option `anchor_store_settings` (`autoload=false`) and capability `manage_woocommerce`, following `anchor-agreements/src/Support/Settings.php`.

## Goals

- One implementation of each feature. A site gets it by switching the feature on, and the theme only styles it.
- On TMJ International and DEKA, the result looks and behaves the same as today once the theme copy is removed. The same prices, discounts, offers and GA4 events come out for the same cart.
- Rules that are hard-coded in the theme become settings an admin can change without a developer:
  - TMJ's coupon code list becomes per-coupon checkboxes.
  - TMJ's "Register Now" button label and "Browse courses" link become drawer settings.

## Non-goals

- Theme styling and layout: `commerce-assets.php`, `shop.css`, the WooCommerce template overrides, the checkout step UI (`checkout-steps.js`), and the Global Payments field-layout fix all stay in the theme.
- "Attendee 1 is the buyer" (`tmj_attendee_self_from_billing`) and DEKA's `inc/event-registration.php` gap-fillers. They fill gaps in Anchor Events itself, so they become an Events follow-up, not Store.
- Checkout Questions stays its own module (PR #82).
- Upsells on the classic `/cart/` page or the product page. v1 shows offers in the drawer only, as today.
- Block cart and block checkout.

## Anti-goals (a failure even if it works)

- A discount applied twice: the theme's coupon filter and the plugin's both running on one site. The same goes for two drawers, two GA4 `purchase` events for one order, or two copies of an offer.
- A third copy: the plugin port must replace both theme copies, not sit beside them.
- An upsell Add that skips Anchor Events' seat or capacity check.
- Tracking firing on staging. Today TMJ staging sends nothing because the GA4 snippet is off there, and that must stay true.

## Feature: cart drawer

- **Markup:** classes `anchor-cart-drawer`, `anchor-cart-drawer__*`, `anchor-cart-fab`, `anchor-cart-toggle`, `anchor-coupon-field__*`, in the same structure as TMJ's (head, flash, body; body = items, offers, foot).
- **Body:** each line has a thumbnail, a name (linked when visible), the unit price "each", a remove control, a −/+ quantity stepper and the line total. The foot has the coupon field, then Subtotal / Discount (only when > 0) / Total, then "View cart" and a checkout button.
- **Settings:**
  - checkout button label (default "Checkout"; TMJ sets "Register Now");
  - empty-cart link label and URL (default "Continue shopping" → shop page; TMJ sets "Browse courses" → `/for-doctors/upcoming-courses/`);
  - whether the floating button shows (default on);
  - the coupon field placeholder (default "Coupon code"; TMJ sets "Promo code");
  - "Return to the page and open the cart after adding" (`return_after_add`, default on; DEKA sets it off, because DEKA leaves the visitor on the product page today).

  An empty-cart link URL left blank prints no link, as on DEKA today.
- **Theme API:** `\Anchor\Store\Api::cart_toggle()` echoes the header cart link with its live count. The drawer, overlay and floating button print on `wp_footer` when the feature is on.
- **Fragments** (`woocommerce_add_to_cart_fragments`): the header count, the floating-button count and the whole drawer body.
- **AJAX:**
  - actions `anchor_store_cart_qty` (qty <= 0 removes the line; an increase runs `woocommerce_update_cart_validation` first and returns its error — my call: today's theme stepper skips it, and Anchor Events re-checks seats only at checkout) and `anchor_store_cart_coupon` (apply/remove; returns WooCommerce's own notice as plain text);
  - nonce action `anchor-store-cart`;
  - logged-in and `nopriv` variants.
- **Coupon field:** one renderer, a `<div>` and never a `<form>` (it sits inside the checkout form). It is used in the drawer foot, with applied codes listed with a remove link, and as a checkout order-box row on `woocommerce_review_order_before_order_total`, without the list. When the feature is on, WooCommerce's top-of-checkout "Have a coupon?" form is removed.
- **Non-AJAX add to cart** (only when `return_after_add` is on): when WooCommerce's own redirect is empty and the request isn't AJAX, send the visitor back to the referring page with the `add-to-cart` / `added-to-cart` arguments removed. Set a session flag that opens the drawer on that page view, and drop WooCommerce's "added to your cart" success notice.
- **JS** (`anchor-store/assets/cart-drawer.js`, jQuery IIFE per CLAUDE.md, depends on `wc-cart-fragments`):
  - Open from the toggle and floating button; close on overlay, close button and Esc.
  - A focus trap while open, with focus returned to the opener; `aria-expanded` on the toggle; no slide under `prefers-reduced-motion`.
  - Auto-open on WooCommerce's `added_to_cart`, and on the new `anchor_events_added_to_cart` body event (below).
  - Use the in-page flash region for messages, never `alert()`.
- **Anchor Events change:** `assets/event-storefront.js` triggers `$( document.body ).trigger( 'anchor_events_added_to_cart' )` after a successful add, plus `wc_fragment_refresh`. That replaces TMJ's `ajaxSuccess` sniff on `action=anchor_events_add_to_cart`. DEKA's drawer doesn't open after an event-ticket add today; with the plugin it will (my call: same as TMJ, and the visitor sees what they added).
- **CSS** (`cart-drawer.css`): a complete neutral default, driven by custom properties on `.anchor-cart-drawer`: accent, text, muted, surface, border, radius, font, width. Each theme sets the properties and deletes its own drawer CSS.

## Feature: ticket upsells

The rule is per variation, ported unchanged from TMJ `inc/upsells.php`:

- **Meta:** `_anchor_store_upsell_ids` (int[]: any products or variations) and `_anchor_store_upsell_siblings` (string[]: ticket names of the same product, e.g. "Staff").
- **Inheritance:** a variation with no rule of its own inherits the sibling names from the most recently modified other variation with the same Anchor Events tier id (`_anchor_evt_tier_id`), cached per request. Inherited explicit ids are ignored, because they point at another date.
- **What's offered:** `Services\Upsells::for_variation( int $variation_id ): int[]` returns the rule ids, then the siblings by name, minus:
  - the variation itself and duplicates;
  - anything already in the cart;
  - variable parents;
  - anything not purchasable or out of stock.

  It is filtered by `anchor_store_variation_upsells`.
- **Depends on the plugin drawer:** offers render only in the plugin drawer, so `upsells` is active only while `cart_drawer` is active (on and not deferring). In TMJ the theme drawer calls the theme's offers, so the two move over together.
- **Admin:** on the variation panel, `woocommerce_product_after_variable_attributes` shows "Upsells in the cart drawer": an "Upsell products" product/variation search and "Upsell tickets from this product" checkboxes. When a rule is inherited, a line says where it came from. Saving goes through `woocommerce_save_product_variation`, which clears the cache.
- **Drawer:** an "Add to your registration" section of cards, one per offer however many lines trigger it. Each card has an image, a name ("Staff ticket" for a sibling, otherwise the product name), the parent name for siblings, the price and an Add button. The section is omitted when empty.
- **Add:** AJAX `anchor_store_cart_upsell_add` with the trigger line's key and the offer id.
  - It is refused unless the offer is still in `for_variation()` of that line's variation.
  - It runs `woocommerce_add_to_cart_validation` by hand before `WC()->cart->add_to_cart()`, which is how Anchor Events' seat check runs.
  - It returns WooCommerce's error notice, or "That add-on is no longer available."
- **Migration:** the first request on which `upsells` is active (not deferring) copies `_tmj_upsell_ids` / `_tmj_upsell_siblings` to the new keys where the new key is absent, then sets option `anchor_store_upsells_migrated`. Running at takeover rather than at module setup keeps any rule an admin edited in the theme's panel in the meantime. The old rows are left in place. On TMJ today that's 5 variations with `_tmj_upsell_siblings`. `_tmj_assoc_discount` → `_anchor_store_tier_eligible` copies the same way when `coupon_rules` takes over (option `anchor_store_coupons_migrated`).

## Feature: coupon rules

Both are per-coupon settings on the coupon edit screen (`woocommerce_coupon_options` / `woocommerce_coupon_options_save`).

**"Never stack on a sale price (better of two)"** (`_anchor_store_better_of_two` = yes)
- The coupon defines a target unit price from the product's regular price:
  - `percent`: regular × (1 − amount/100);
  - `fixed_product`: max(0, regular − amount).
- Other discount types ignore the setting. The admin checkbox says it only applies to percent and fixed-product coupons.
- On `woocommerce_coupon_get_discount_amount`:
  - when the product is on sale at or below the target, the discount is 0;
  - otherwise the per-unit discount is max(0, discounting amount / qty − target), times qty unless `$single`.
  - A product with a regular price ≤ 0 is left alone.
- This reproduces TMJ's hard-coded list exactly. AIRWAYZ is percent 20, IAO percent 25, and VECTOR, TOR750 and aacp are `fixed_product` 500/750/500, as checked against the live DB on 2026-10-29.

**"Only ticket tiers named"** (`_anchor_store_tier_prefixes`, comma-separated, e.g. "Dentist")
- When set, the coupon is valid only for:
  - (a) a variation whose attribute value starts with one of the words, case-insensitive, hyphens read as spaces, singular or plural (so "Dentists" matches and "MD, DC, PT, DO or Non-Dental Professional" doesn't);
  - (b) a variation ticked "Eligible for tier-restricted coupons" (`_anchor_store_tier_eligible`) on its variation options (`woocommerce_variation_options`);
  - (c) a product that isn't a variation and isn't variable (one price for everyone).
- This runs on `woocommerce_coupon_is_valid_for_product` (priority 20), so WooCommerce shows its own "not applicable" message.
- Migration (see upsells): `_tmj_assoc_discount` → `_anchor_store_tier_eligible`. That's 0 rows on TMJ today, but the code path stays.
- AIRWAYZ's auto-apply (`inc/airwayz.php`) stays in the theme. It only applies the existing AIRWAYZ coupon from the database (percent 20), so the plugin's rule sees the same coupon the theme's did.

## Feature: GA4 ecommerce events

Ported from TMJ `inc/ecommerce-tracking.php`:

- **Events:** `view_item` (single product), `add_to_cart` (queued in the WooCommerce session and printed on the next page view, which covers the AJAX drawer add and a normal POST + redirect), `begin_checkout`, and `purchase` (order-received, once per order).
- Each event is pushed to `dataLayer` in GA4's ecommerce shape and sent with `gtag('event', …)` only `if ( typeof gtag === 'function' )`.
- The feature setting replaces TMJ's "is the GA4 snippet on" lookup. The feature also prints nothing on a non-production environment: `KINSTA_DEV_ENV` true (Kinsta staging, where `wp_get_environment_type()` still says production) or `wp_get_environment_type() !== 'production'`. Both gates are needed because TMJ also loads GTM (GTM-T6XVML9V), so a bare dataLayer push on a staging copy with snippets on would still reach Google.
- **Purchase de-dupe:** order meta `_anchor_store_ga4_purchase_tracked`, with existing `_tmj_ga4_purchase_tracked` also honoured. There are 38 TMJ orders carrying it, so an old order's refreshed thank-you page never re-sends.
- The item shape, currency and value are as TMJ builds them (`tmj_ga4_item()`).

## Coexistence with the theme copies (the anti-goal guard)

Each feature defers to a theme copy that's still present. It checks on `after_setup_theme` (priority 99) and registers none of its hooks if:

| Feature | Defers when any of these exist |
|---|---|
| cart_drawer | `tmj_cart_drawer`, `deka_drawer_cart_content`, `deka_coupon_field`, `deka_cart_fab` (DEKA's checkout coupon row and coupon-form removal are closures beside `deka_coupon_field`, so its presence stands for them) |
| upsells | `tmj_variation_upsells`, or `cart_drawer` not active |
| coupon_rules | `tmj_better_of_two_target`, `tmj_assoc_discount_applies` |
| ga4 | `tmj_ga4_enabled` |

The list is filterable via `anchor_store_legacy_functions`. When a switched-on feature defers, the settings page shows "Using the theme's copy: remove `<function>` from the theme to switch to Anchor Store." So the plugin can be enabled on a site before its theme is edited without doubling anything, and a site moves over by deleting theme code.

## Rollout (each live step needs owner sign-off)

1. Release the module (minor bump). On TMJ International and DEKA, enable the module and the features. Every feature defers to the theme, so nothing changes yet.
2. **TMJ International** (on a Kinsta staging env if one exists, otherwise live with a theme backup):
   - set the coupon checkboxes (better of two on AIRWAYZ, VECTOR, TOR750, aacp and iao; tier prefix "Dentist" on aacp and iao);
   - set the drawer labels;
   - map the theme tokens to the drawer's custom properties;
   - in header/footer, swap `tmj_cart_toggle()` / `tmj_cart_drawer()` for the plugin API;
   - delete `inc/upsells.php`, `inc/coupons.php`, `inc/ecommerce-tracking.php`, the drawer half of `inc/commerce.php`, `assets/js/cart.js` and the theme's drawer CSS.
   - Check one cart for each coupon example, an upsell Add, and one GA4 purchase in DebugView.
3. **DEKA** (staging first), drawer and coupon row only:
   - set `return_after_add` off, the placeholder to "Coupon code", and leave the empty-cart link blank;
   - swap the header cart link for `Api::cart_toggle()`;
   - delete from `functions.php`: the nav-badge fragment filter, `deka_coupon_field`, the checkout coupon row and the coupon-form removal, `deka_drawer_cart_content` and its fragment, `deka_cart_fab`, `deka_ajax_qty` / the coupon AJAX handler, and the `deka-cart-drawer` enqueue; delete `assets/js/cart-drawer.js` and the drawer CSS.

## Tests

- **PHPUnit:**
  - Upsells: own rule; inherited by tier; siblings by name; excludes self, in cart, out of stock and variable; the Add re-check refuses a stale offer and runs `woocommerce_add_to_cart_validation`; the migration copies once and doesn't overwrite.
  - Coupons: the TMJ examples. Toronto Dentist is regular $3,495, sale $2,995. VECTOR $500 targets $2,995, so it takes 0 off. TOR750 $750 targets $2,745, so the customer pays $2,745. A percent 20 coupon on a non-sale $1,000 item takes $200; with qty 2 it takes $400 (or $200 per unit when `$single`). Tier prefixes: "Dentist" and "Dentists" match; "MD, DC, PT, DO or Non-Dental Professional", "Staff" and "Students" don't; the eligibility tick matches; a simple product matches; a variable parent doesn't.
  - Drawer: fragments contain the body and counts; the qty AJAX removes on 0; the coupon AJAX returns WooCommerce's notice text; the non-AJAX redirect returns to the referer and sets the open flag.
  - GA4: purchase prints once, and is skipped when the legacy meta is present.
  - Coexistence: a defined legacy function makes the feature register nothing; `upsells` stays off while `cart_drawer` defers; GA4 prints nothing under `KINSTA_DEV_ENV`; the stepper refuses an increase that `woocommerce_update_cart_validation` rejects; the migration runs only at takeover and never overwrites.
- **Playwright (wp-env):** add a product → drawer opens; the stepper changes the quantity; a bad coupon shows WooCommerce's message in the field; an upsell card's Add puts the sibling ticket in the cart.

## Left to the builder

- Hook priorities, exact CSS defaults, settings-page layout.
- Whether the drawer markup lives in one class or a view file.
- The GA4 item builder's internal shape, as long as the dataLayer payloads match TMJ's today.
