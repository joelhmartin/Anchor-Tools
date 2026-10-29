# Anchor Store Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the cart drawer, ticket upsells, coupon rules and GA4 ecommerce events out of the TMJ International and DEKA themes into one Anchor Tools module, `store`. Each theme then keeps only its styling.

**Architecture:** A PSR-4 module under `anchor-store/src/`, shaped like `anchor-agreements/`. `Support\Features` decides per request which of the four features is active, from three inputs: the setting, a defer-to-theme guard, and feature dependencies. Each feature class registers its hooks only when active. The ports keep the theme code's behaviour; the theme code is the reference.

**Tech Stack:** PHP 8.2 / WordPress / WooCommerce (classic cart + checkout), jQuery IIFE JS, PHPUnit (`WP_UnitTestCase`, WooCommerce loaded in `$TMPDIR/wordpress`), Playwright on wp-env.

**Spec:** `docs/superpowers/specs/2026-10-29-anchor-store-design.md`

**Reference code (read-only copies of the live themes, 2026-10-29):** `.superpowers/reference/anchor-store-themes/int/` (TMJ) and `.superpowers/reference/anchor-store-themes/deka/` (DEKA). These are the source of truth for each port's behaviour. Where this plan or the spec says "as TMJ", read the named file.

## Global Constraints

- Text domain `anchor-schema`; options `update_option( …, false )`; assets via `ANCHOR_TOOLS_PLUGIN_URL . 'anchor-store/assets/…'`; enqueue the source `.css` / `.js`; never commit `.min.*`.
- JS is a jQuery IIFE `(function($){ … })(jQuery);`. No `alert()`, `confirm()` or `prompt()`; messages go in an in-page region.
- Files stay under 1,000 lines and functions under 150.
- The module is off by default, constructed only when WooCommerce is active, and every feature setting defaults to off.
- The settings option is `anchor_store_settings`; the capability is `manage_woocommerce`.
- The AJAX nonce action is `anchor-store-cart`. The AJAX actions are `anchor_store_cart_qty`, `anchor_store_cart_coupon` and `anchor_store_cart_upsell_add`, each with a `nopriv` twin.
- Meta keys:
  - `_anchor_store_upsell_ids` and `_anchor_store_upsell_siblings` (variation);
  - `_anchor_store_tier_eligible` (variation);
  - `_anchor_store_better_of_two` and `_anchor_store_tier_prefixes` (coupon);
  - `_anchor_store_ga4_purchase_tracked` (order, HPOS-safe).
- Never log cart, coupon or order values.
- Before every commit, `git checkout -- vendor`. The committed `vendor/` is no-dev, so run `composer install -q` to test, then restore `vendor/` before committing. After editing `composer.json` autoload, regenerate with `composer dump-autoload --no-dev -q` and commit only `vendor/composer/autoload_*.php` + `composer.json`.

## Review Focus

1. **A discount applied twice** when the theme's `coupons.php` is still loaded. `Features` must defer `coupon_rules` whenever `tmj_better_of_two_target` or `tmj_assoc_discount_applies` exists. This is tested in Task 1 and Task 5.
2. **An upsell Add or stepper "+" that skips Anchor Events' seat check.** The Add must call `woocommerce_add_to_cart_validation` and the "+" must call `woocommerce_update_cart_validation` before changing the cart. This is tested in Task 2 and Task 4.
3. **A stale offer added.** The upsell AJAX must re-check that the offer is still in `Upsells::for_variation()` of that cart line's variation, so a crafted POST can't add an arbitrary product. This is tested in Task 4.
4. **GA4 `purchase` firing twice or on staging.** The old `_tmj_ga4_purchase_tracked` meta must be honoured, and nothing may print when `KINSTA_DEV_ENV` is true or the environment isn't production. This is tested in Task 6.
5. **The coupon field inside the checkout form submitting checkout on Enter.** It must be a `<div>`, the JS must `preventDefault` on Enter, and it must apply by AJAX. This is tested in Task 3's Playwright spec.

---

### Task 1: Module skeleton, settings, feature switchboard

**Files:**
- Create: `anchor-store/anchor-store.php` (`\Anchor\Store\Module`, modelled on `anchor-agreements/anchor-agreements.php`: `instance()`, `dir()`, `url()`, a `$woocommerce_active` test seam, `missing_woocommerce_notice()`)
- Create: `anchor-store/src/Support/Settings.php`
- Create: `anchor-store/src/Support/Features.php`
- Create: `anchor-store/src/Admin/SettingsPage.php`
- Modify: `anchor-tools.php`: a registry entry `'store'` after `'shipping'`, with label "Anchor Store" and description "Cart drawer, ticket upsells, coupon rules and GA4 store events for WooCommerce."
- Modify: `composer.json` (`"Anchor\\Store\\": "anchor-store/src/"`), `vendor/composer/autoload_*`, `tests/bootstrap.php` (enable `store` like `agreements`), and `CLAUDE.md` (a module table row: `store` | `\Anchor\Store\Module` | Settings page (namespaced, PSR-4))
- Test: `tests/test-store-features.php`

**Interfaces:**
- Produces:
  - `Settings::get(): array` and `Settings::sanitize( $in ): array`. The DEFAULTS are:
    - `cart_drawer`, `upsells`, `coupon_rules`, `ga4` = false;
    - `checkout_label` = '' (empty = "Checkout");
    - `empty_label` = '' (empty = "Continue shopping"), `empty_url` = '' (empty = no link);
    - `fab` = true;
    - `coupon_placeholder` = '' (empty = "Coupon code");
    - `return_after_add` = true.
  - `Features::active( string $feature ): bool`.
  - `Features::deferred_to( string $feature ): ?string`, which returns the first legacy function found, or null.
  - `Features::is_production(): bool`.
  - The `Features::LEGACY` const map. Its default lists are copied verbatim from the spec's coexistence table, and it's filtered by `anchor_store_legacy_functions`.
- `active()` is true only when:
  - the setting is on;
  - `deferred_to()` is null;
  - for `upsells`, `active('cart_drawer')` is also true.
- Feature classes call `Features::active()` on `after_setup_theme` priority 99, because theme functions are defined by then.

- [ ] **Step 1: Write the failing tests** in `tests/test-store-features.php` (`class Test_Store_Features extends WP_UnitTestCase`):
  - `test_all_features_off_by_default`: every `Features::active()` is false with no option set.
  - `test_setting_on_makes_feature_active`: with `coupon_rules` on, `active('coupon_rules')` is true.
  - `test_legacy_function_defers_feature`: add `tmj_better_of_two_target` via the `anchor_store_legacy_functions` filter pointing at a function the test defines (e.g. `anchor_store_test_legacy_fn`), with the setting on. Then `active()` is false and `deferred_to()` returns that function's name.
  - `test_upsells_needs_active_drawer`: `upsells` on and `cart_drawer` off → false; both on → true; both on with `cart_drawer` deferred → false.
  - `test_sanitize_clamps`: `empty_url` → `esc_url_raw`; labels → `sanitize_text_field`; booleans are cast.
  - `test_is_production_false_under_env_type_staging`: filter `wp_get_environment_type` isn't filterable, so give `is_production()` an optional `?string $env_type = null, ?bool $kinsta_dev = null` test seam and assert `is_production('production', true) === false`, `is_production('staging', false) === false` and `is_production('production', false) === true`.
- [ ] **Step 2: Run** `composer install -q && WP_TESTS_DIR=$TMPDIR/wordpress-tests-lib vendor/bin/phpunit --filter Test_Store_Features`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement.** The module constructor (WooCommerce present) sets the instance and creates `SettingsPage`. Later tasks add their feature classes here, each constructed unconditionally and self-gating on `Features::active()` at `after_setup_theme` 99.
  - The SettingsPage is a WooCommerce submenu "Anchor Store" using the Settings API, the same as Agreements' SettingsPage.
  - It has one checkbox per feature, each with a description line, plus the drawer fields.
  - Beside a feature that's on but deferring, it prints: "Using the theme's copy: remove `<function>` from the theme to switch to Anchor Store."
- [ ] **Step 4: Run the tests.** Expected: PASS. Then run the full suite: `vendor/bin/phpunit`. Expected: no new failures. The existing `Test_Inertness` flake (role leak) is known; re-run once if it alone fails.
- [ ] **Step 5: Commit** with `git checkout -- vendor` first, except the regenerated `vendor/composer/autoload_*`. Message: "feat(store): module skeleton, settings, feature switchboard".

### Task 2: Cart drawer (server side)

**Files:**
- Create: `anchor-store/src/Frontend/CartDrawer.php` (hooks, AJAX, redirect-after-add)
- Create: `anchor-store/src/Frontend/DrawerView.php` (markup only: `body(): string`, `drawer(): string`, `fab(): string`, `toggle(): string`, `coupon_field( bool $show_applied ): string`)
- Create: `anchor-store/src/Api.php` (`Api::cart_toggle(): void` echoes `DrawerView::toggle()` when `cart_drawer` is active)
- Test: `tests/test-store-cart-drawer.php`

**Interfaces:**
- Consumes: `Features::active('cart_drawer')` and `Settings::get()` from Task 1.
- Produces:
  - `DrawerView::body()` prints `<div class="anchor-cart-drawer__body" id="anchor-cart-drawer-body">`. Task 4 inserts its offers section with `DrawerView::body()` calling `apply_filters( 'anchor_store_drawer_offers_html', '' )` between the items list and the foot.
  - Fragment selectors: `span.anchor-cart-toggle__count`, `#anchor-cart-fab .anchor-cart-fab__count`, `div.anchor-cart-drawer__body`.
  - `CartDrawer::localize(): array` returns `ajaxUrl`, `nonce`, `autoOpen` and an i18n map for Task 3's JS.

Port `tmj_cart_drawer_body()`, `tmj_coupon_field()`, `tmj_cart_fab()`, `tmj_cart_drawer()`, `tmj_cart_fragments()`, `tmj_ajax_cart_qty()`, `tmj_ajax_cart_coupon()`, `tmj_add_to_cart_redirect()`, `tmj_cart_take_auto_open()` and `tmj_checkout_coupon_row()` from `int/inc/commerce.php`, with these changes:
- class prefix `anchor-cart-drawer` / `anchor-cart-fab` / `anchor-cart-toggle` / `anchor-coupon-field`;
- labels come from Settings;
- the empty-cart link is printed only when `empty_url` is set;
- the redirect-after-add runs only when `return_after_add` is on;
- in the qty AJAX, an increase runs `apply_filters( 'woocommerce_update_cart_validation', true, $key, $item, $qty )` and on false returns `wp_send_json_error( [ 'message' => <first WC error notice text> ] )` without changing the cart.

When active, also do the `init` removal of `woocommerce_checkout_coupon_form` and add the `woocommerce_review_order_before_order_total` row.

- [ ] **Step 1: Write the failing tests** (`Test_Store_Cart_Drawer`; switch `cart_drawer` on in `set_up`, then build a WooCommerce cart with `WC()->cart->add_to_cart()` on a product from `WC_Helper_Product` if it's available in the test lib, otherwise create a simple product with `wc_get_product_object('simple')`):
  - `test_fragments_include_counts_and_body`: after adding qty 2, the fragments filter returns the three selectors, the body contains the product name and "each", and the toggle count is "2".
  - `test_empty_cart_link_only_when_url_set`: an empty cart with no link, then with `empty_url` set the link appears with `empty_label` or "Continue shopping".
  - `test_checkout_label_setting`: the button text is "Register Now" when it's set, and "Checkout" by default.
  - `test_qty_ajax_zero_removes_line` (use `_handleAjax` per `WP_Ajax_UnitTestCase`, following `memory: wp-ajax-unittestcase-gotchas`; if mixing case types is awkward, call the handler method directly with `$_POST` set and catch `WPDieException`).
  - `test_qty_increase_refused_by_update_validation`: add a `woocommerce_update_cart_validation` filter that returns false and adds `wc_add_notice( 'No seats', 'error' )`. Then qty is unchanged and the JSON error message is "No seats".
  - `test_coupon_ajax_returns_wc_notice_text`: applying `nosuchcode` returns WooCommerce's "does not exist" message as plain text.
  - `test_redirect_after_add_returns_to_referer_and_flags_open`: non-AJAX with `$_SERVER['HTTP_REFERER']` = `home_url('/course/?add-to-cart=5')` returns `home_url('/course/')`, and the session `anchor_store_cart_auto_open` is 1.
  - `test_redirect_untouched_when_setting_off`.
  - `test_coupon_field_is_div_not_form`: the `coupon_field(false)` HTML contains no `<form`.
  - `test_feature_off_registers_nothing`: with `cart_drawer` off, `has_filter( 'woocommerce_add_to_cart_fragments', … )` is false.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter Test_Store_Cart_Drawer`. Expected: FAIL.
- [ ] **Step 3: Implement** as above. Print the drawer + FAB on `wp_footer` (FAB only when the `fab` setting is on). Enqueue nothing yet (Task 3).
- [ ] **Step 4: Run.** Expected: PASS. Then run the full suite.
- [ ] **Step 5: Commit**: "feat(store): cart drawer markup, fragments, quantity and coupon AJAX".

### Task 3: Cart drawer JS, CSS, Events trigger, browser test

**Files:**
- Create: `anchor-store/assets/cart-drawer.js` and `anchor-store/assets/cart-drawer.css`
- Modify: `anchor-store/src/Frontend/CartDrawer.php` (enqueue on the front end when active, with deps `jquery` and `wc-cart-fragments`; localize `anchorStoreCart`)
- Modify: `anchor-events-manager/assets/event-storefront.js` (in the add-to-cart success branch, after the messages render: `$( document.body ).trigger( 'wc_fragment_refresh' ).trigger( 'anchor_events_added_to_cart' );`) and bump that script's version string where it's enqueued
- Modify: `bin/e2e-seed.sh` (seed a simple product "Store Test Product" priced 100, enable the `store` module with `cart_drawer` on and `fab` on, and write the product id into `e2e/.seed.json` as `storeProductId`)
- Test: `e2e/store-cart-drawer.spec.js`

Port `int/assets/js/cart.js` to a jQuery IIFE:
- open/close, the focus trap (write a small local one; TMJ's used the theme's `tmjKit.focusTrap`), focus restore, `aria-expanded`, Esc, overlay click;
- the stepper and remove;
- coupon apply/remove (Enter in `.anchor-coupon-field__input` → `preventDefault` + apply);
- the upsell Add (`.anchor-cart-drawer__offer-add`; posts `anchor_store_cart_upsell_add`, so Task 4 needs no JS);
- auto-open on `added_to_cart`, on `anchor_events_added_to_cart`, and on `anchorStoreCart.autoOpen`;
- AJAX errors and messages go in `#anchor-cart-drawer-flash` (drawer) or `.anchor-coupon-field__msg` (field);
- reduced motion as TMJ.

The CSS is a full neutral default for the drawer, FAB, toggle, coupon field and offers section (from `int/assets/css/components/cart-drawer.css`, renamed classes). Every colour, radius, font and width reads a custom property declared on `:root` with a fallback: `--anchor-store-accent`, `--anchor-store-text`, `--anchor-store-muted`, `--anchor-store-surface`, `--anchor-store-border`, `--anchor-store-radius`, `--anchor-store-font`, `--anchor-store-drawer-width`. It keeps TMJ's desktop left-column offers and phone stacked layout.

- [ ] **Step 1: Write the failing spec** `e2e/store-cart-drawer.spec.js`:
  - visit the shop page and add `storeProductId` via `/?add-to-cart=<id>`; the drawer is visible after the redirect (auto-open) and the toggle count is "1";
  - click "+" and the line quantity shows 2, with the subtotal updated;
  - type `nosuchcode` in the drawer coupon field and press Enter; `.anchor-coupon-field__msg` contains "does not exist" and the page did not navigate;
  - on `/checkout/`, the order box has the coupon row, and pressing Enter in it doesn't submit checkout (the URL is unchanged and there's no "required field" error banner);
  - Esc closes the drawer and focus returns to the toggle.
- [ ] **Step 2: Run** `npm run env:seed && npx playwright test e2e/store-cart-drawer.spec.js`. Expected: FAIL.
- [ ] **Step 3: Implement** the JS, CSS, enqueue and the Events trigger.
- [ ] **Step 4: Run** the spec, then `npx playwright test e2e/event-frontend.spec.js e2e/purchase.spec.js` (the Events storefront isn't broken). Expected: PASS. Then run the full PHPUnit suite.
- [ ] **Step 5: Commit**: "feat(store): cart drawer script and styles; events storefront announces adds".

### Task 4: Ticket upsells

**Files:**
- Create: `anchor-store/src/Services/Upsells.php` (`rule( int $variation_id ): array{ids:int[],siblings:string[],inherited:bool}`, `clean_names( array ): array`, `siblings_by_name( int, array ): int[]`, `for_variation( int ): int[]`, `reset_cache(): void`, `add_offer( string $key, int $offer_id ): bool`, `offers_html(): string`)
- Create: `anchor-store/src/Admin/VariationUpsellFields.php`
- Create: `anchor-store/src/Support/Migrations.php` (`maybe_copy( string $option, array $map ): void`, which copies post meta old → new wherever new is absent, then `update_option( $option, 1, false )`; a no-op when the option is set)
- Modify: `anchor-store/anchor-store.php` (construct them), `anchor-store/src/Frontend/CartDrawer.php` (register the `anchor_store_cart_upsell_add` AJAX when `upsells` is active)
- Test: `tests/test-store-upsells.php`

**Interfaces:**
- Consumes: the `anchor_store_drawer_offers_html` filter (Task 2) and the JS Add (Task 3).
- Produces: the `Migrations::maybe_copy()` that Task 5 reuses.

Port `int/inc/upsells.php` and `tmj_cart_upsell_offers()` / `tmj_cart_upsell_add()` / `tmj_ajax_cart_upsell_add()` from `int/inc/commerce.php`. The behaviour is unchanged; only the names, meta keys and classes change (`anchor-cart-drawer__offer*`). The filter is `anchor_store_variation_upsells`. The Events tier key stays `_anchor_evt_tier_id`. When `upsells` is active, run `Migrations::maybe_copy( 'anchor_store_upsells_migrated', [ '_tmj_upsell_ids' => '_anchor_store_upsell_ids', '_tmj_upsell_siblings' => '_anchor_store_upsell_siblings' ] )` on `init`.

- [ ] **Step 1: Write the failing tests** (`Test_Store_Upsells`; a variable product "Course" with variations Dentist and Staff, plus a simple "Add-on" product; `cart_drawer` and `upsells` on):
  - `test_own_sibling_rule_offers_staff`: Dentist has siblings `['Staff']` → `for_variation(Dentist)` = [Staff id].
  - `test_rule_inherited_by_tier`: a second product's Dentist variation with the same `_anchor_evt_tier_id` and no meta inherits `['Staff']` and offers its own product's Staff; `rule()['inherited']` is true; inherited explicit ids are ignored.
  - `test_excludes_self_in_cart_out_of_stock_and_variable`.
  - `test_explicit_ids_offered_before_siblings`.
  - `test_add_offer_refuses_stale_offer`: offer id not in `for_variation` → false, the cart is unchanged.
  - `test_add_offer_runs_add_to_cart_validation`: a filter on `woocommerce_add_to_cart_validation` returning false → false, nothing added.
  - `test_add_offer_adds_sibling_variation`: true, and the cart has the Staff variation with its attributes.
  - `test_offers_html_once_per_offer`: two Dentist units in two lines → the "Add to your registration" section appears with one Staff card, labelled "Staff ticket" with the parent name.
  - `test_migration_copies_once_without_overwrite`: legacy meta is copied; an existing new key is kept; a second run with changed legacy meta does nothing (the option is set).
  - `test_upsells_inactive_when_drawer_deferred`: with a legacy drawer function filtered in, `offers_html()` isn't hooked.
- [ ] **Step 2: Run** `--filter Test_Store_Upsells`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS. Add to `e2e/store-cart-drawer.spec.js`: the seed creates a variable "Store Ticket" with Dentist/Staff variations and siblings `['Staff']` on Dentist; adding Dentist shows a "Staff ticket" card, and clicking Add puts Staff in the cart while the card disappears. Run the spec plus the full PHPUnit suite.
- [ ] **Step 5: Commit**: "feat(store): ticket upsells in the cart drawer".

### Task 5: Coupon rules

**Files:**
- Create: `anchor-store/src/Services/CouponRules.php` (`target( WC_Coupon $c, WC_Product $p ): ?float`, `discount( $discount, $discounting_amount, $cart_item, $single, $coupon )`, `tier_matches( WC_Product $p, array $prefixes ): bool`, `valid_for_product( $valid, $product, $coupon )`)
- Create: `anchor-store/src/Admin/CouponFields.php` (the coupon screen's two fields; the variation option "Eligible for tier-restricted coupons")
- Modify: `anchor-store/anchor-store.php`
- Test: `tests/test-store-coupon-rules.php`

**Interfaces:**
- Consumes: `Migrations::maybe_copy( 'anchor_store_coupons_migrated', [ '_tmj_assoc_discount' => '_anchor_store_tier_eligible' ] )` (Task 4), run on `init` when `coupon_rules` is active.

The behaviour is ported from `int/inc/coupons.php`, with the target from the coupon's own type and amount (the spec's coupon section). `tier_matches()` uses `/^\s*(prefix)s?\b/i` per prefix on the attribute value with hyphens replaced by spaces, or true when `_anchor_store_tier_eligible` = yes. A product that isn't a variation and isn't variable matches; a variable parent doesn't.

The admin fields:
- "Never stack on a sale price (better of two)" checkbox, with the help text "Applies to percent and fixed product discounts.";
- "Only ticket tiers named", text, help text "Comma-separated, e.g. Dentist. Leave empty for every product.".

- [ ] **Step 1: Write the failing tests** (`Test_Store_Coupon_Rules`, `coupon_rules` on). Build a variable product "Toronto" with variation Dentist (regular 3495, sale 2995), and variations Staff and "MD, DC, PT, DO or Non-Dental Professional". Create coupons in the test:
  - `test_vector_ties_sale_takes_nothing`: VECTOR fixed_product 500, better-of-two → the cart total for one Dentist is 2995.
  - `test_tor750_beats_sale`: TOR750 fixed_product 750 → the total is 2745.
  - `test_percent_target_on_non_sale_item`: a simple product at 1000, coupon percent 20 → the total is 800; with qty 2 it's 1600.
  - `test_unflagged_coupon_untouched`: a percent 20 coupon without the flag on a sale item behaves like plain WooCommerce.
  - `test_fixed_cart_ignores_flag`.
  - `test_tier_prefix_matching`: Dentist and Dentists match; "MD, DC, PT, DO or Non-Dental Professional", Staff and Students don't; the eligible tick matches; a simple product matches; a variable parent doesn't.
  - `test_tier_restricted_coupon_refused_for_staff_only_cart`: applying a coupon with prefixes "Dentist" to a Staff-only cart fails with WooCommerce's not-applicable error.
  - `test_deferred_when_theme_function_exists`: with `tmj_better_of_two_target` filtered in as legacy, `has_filter( 'woocommerce_coupon_get_discount_amount', … )` is false.
  - `test_assoc_meta_migrates`.
- [ ] **Step 2: Run** `--filter Test_Store_Coupon_Rules`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS. Then run the full suite.
- [ ] **Step 5: Commit**: "feat(store): better-of-two and tier-restricted coupon rules".

### Task 6: GA4 ecommerce events

**Files:**
- Create: `anchor-store/src/Frontend/Ga4.php` (`item( WC_Product $p, int $qty = 1, ?float $price = null ): array`, `print_event( string $event, array $params ): void`, plus the four hooks)
- Modify: `anchor-store/anchor-store.php`
- Test: `tests/test-store-ga4.php`

This is ported from `int/inc/ecommerce-tracking.php`: the hooks, the add-to-cart session queue, and the payload shapes (`tmj_ga4_item()` → `item()`, `tmj_ga4_print_event()` → `print_event()`, the same script string). Replace `tmj_ga4_enabled()` with `Features::active('ga4') && Features::is_production()`. `purchase` is skipped when the order has `_anchor_store_ga4_purchase_tracked` or `_tmj_ga4_purchase_tracked`; after printing, the new key is set with `$order->update_meta_data()` + `save_meta_data()`.

- [ ] **Step 1: Write the failing tests** (`Test_Store_Ga4`, `ga4` on, production seam forced true):
  - `test_item_shape_variation`: `item_id` (SKU or id), `item_name` = the parent name, `item_variant`, `item_category`, `price` and `quantity`, matching TMJ's builder on the same fixture.
  - `test_purchase_prints_once`: running the thank-you hook twice prints `"purchase"` once, and the meta is set.
  - `test_purchase_skipped_for_legacy_tracked_order`.
  - `test_nothing_prints_off_production`: `is_production()` false → no output from any hook.
  - `test_add_to_cart_queued_then_printed`: `woocommerce_add_to_cart` queues; the next `wp_footer` (or whichever hook TMJ uses) prints `"add_to_cart"` once and clears the queue.
- [ ] **Step 2: Run** `--filter Test_Store_Ga4`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS. Then run the full suite plus the whole Playwright suite.
- [ ] **Step 5: Commit**: "feat(store): GA4 ecommerce events".

---

## After the tasks

1. A whole-branch Opus review.
2. Open the PR against `main`, after counting the files (`git diff --name-only origin/main...HEAD | wc -l`, expected about 30). The money path and the many parts earn CodeRabbit, so leave it on.
3. Merging, releasing and the per-site rollout (spec "Rollout") each need the owner's sign-off. The theme edits on TMJ and DEKA are server-side file edits with backups, not part of this PR.
