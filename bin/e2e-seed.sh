#!/usr/bin/env bash
#
# Anchor Tools — E2E seed script.
#
# Runs *inside* the wp-env `cli` container (WP-CLI is on $PATH, WordPress root is
# /var/www/html). It makes the site checkout-ready WITHOUT the WooCommerce
# onboarding wizard and creates the paid-event fixture the Playwright purchase
# spec drives. It is idempotent: re-running reuses the existing event.
#
# Invoke from the host with:
#   npm run env:seed
# i.e.  wp-env run cli --env-cwd=wp-content/plugins/anchor-tools bash bin/e2e-seed.sh
#
# Output: writes e2e/.seed.json (event_id, event_url, product_id) back into the
# bind-mounted plugin directory so the host-side Playwright tests can read it.
set -euo pipefail

log() { printf '[e2e-seed] %s\n' "$*"; }

# ---------------------------------------------------------------------------
# Resolve the Anchor Tools plugin slug + dir.
#
# wp-env mounts the plugin under wp-content/plugins/<basename-of-repo-dir>, and
# that basename is NOT guaranteed to be "anchor-tools". Derive it from the
# active plugin file so activation + the .seed.json write are slug-independent.
# ---------------------------------------------------------------------------
PLUGIN_FILE="$(wp plugin list --fields=file --format=csv 2>/dev/null | grep -iE '(^|/)anchor-tools\.php$' | head -n1 || true)"
if [ -n "${PLUGIN_FILE}" ]; then
  PLUGIN_SLUG="$(dirname "${PLUGIN_FILE}")"
else
  PLUGIN_SLUG="anchor-tools"
fi
PLUGINS_DIR="$(wp plugin path 2>/dev/null || echo '/var/www/html/wp-content/plugins')"
PLUGIN_DIR="${PLUGINS_DIR}/${PLUGIN_SLUG}"
log "Plugin slug: ${PLUGIN_SLUG}"
log "Plugin dir : ${PLUGIN_DIR}"

# ---------------------------------------------------------------------------
# Ensure WooCommerce + the plugin are active. Don't assume wp-env installed/
# activated the WooCommerce zip — install it via WP-CLI if missing (deterministic).
# ---------------------------------------------------------------------------
if wp plugin is-installed woocommerce; then
  wp plugin activate woocommerce >/dev/null 2>&1 || true
else
  log "WooCommerce not installed — installing via WP-CLI..."
  wp plugin install woocommerce --activate
fi
wp plugin activate "${PLUGIN_SLUG}" >/dev/null 2>&1 || true

log "Installed plugins:"
wp plugin list --fields=name,status,version || true

wp plugin is-active woocommerce       || { log "ERROR: WooCommerce is not active after install"; exit 1; }
wp plugin is-active "${PLUGIN_SLUG}"  || { log "ERROR: ${PLUGIN_SLUG} is not active"; exit 1; }
log "Plugins active: woocommerce + ${PLUGIN_SLUG}"

# ---------------------------------------------------------------------------
# Install a real, WooCommerce-friendly theme. The wp-env default theme in this
# WordPress build renders without header/footer, breaking cart/checkout output.
# Storefront (WooCommerce's own theme) renders the shop pages reliably.
# ---------------------------------------------------------------------------
if ! wp theme is-active storefront; then
  wp theme install storefront --activate || log "WARNING: could not install Storefront theme."
fi
log "Active theme: $(wp theme list --status=active --field=name | head -n1)"

# ---------------------------------------------------------------------------
# Enable the Anchor Events module (gate the events feature on).
# ---------------------------------------------------------------------------
# Fresh test site: set the whole option (no other keys to preserve). `patch
# update modules` would fail because the nested key doesn't exist yet.
wp option update anchor_schema_settings '{"modules":{"events_manager":true}}' --format=json --autoload=no >/dev/null
log "Events module enabled."

# ---------------------------------------------------------------------------
# WooCommerce: skip onboarding + store basics + currency + guest checkout.
# ---------------------------------------------------------------------------
wp option update woocommerce_store_address  "123 Test Street"        >/dev/null
wp option update woocommerce_store_city     "Dallas"                 >/dev/null
wp option update woocommerce_default_country "US:TX"                 >/dev/null
wp option update woocommerce_store_postcode "75201"                  >/dev/null
wp option update woocommerce_currency       "USD"                    >/dev/null
wp option update woocommerce_calc_taxes     "no"                     >/dev/null
wp option update woocommerce_enable_guest_checkout "yes"             >/dev/null
wp option update woocommerce_enable_signup_and_login_from_checkout "no" >/dev/null
wp option update woocommerce_onboarding_profile '{"completed":true,"skipped":true}' --format=json >/dev/null
log "WooCommerce store basics set, onboarding skipped."

# Create the default WC pages (cart / checkout / shop / my-account).
wp wc --user=1 tool run install_pages >/dev/null 2>&1 \
  || wp wc tool run install_pages --user=1 >/dev/null 2>&1 \
  || log "WARNING: install_pages tool failed (pages may already exist)."

# ---------------------------------------------------------------------------
# Force CLASSIC cart/checkout. The plugin's per-seat attendee fields hook the
# classic shortcode checkout (woocommerce_checkout_after_customer_details);
# the block checkout is intentionally fail-closed. So replace any block markup
# with the classic shortcodes.
# ---------------------------------------------------------------------------
CART_ID="$(wp option get woocommerce_cart_page_id 2>/dev/null || echo 0)"
CHECKOUT_ID="$(wp option get woocommerce_checkout_page_id 2>/dev/null || echo 0)"
if [ -n "${CART_ID}" ] && [ "${CART_ID}" != "0" ]; then
  wp post update "${CART_ID}" --post_content='[woocommerce_cart]' >/dev/null
  log "Cart page #${CART_ID} forced to [woocommerce_cart]."
fi
if [ -n "${CHECKOUT_ID}" ] && [ "${CHECKOUT_ID}" != "0" ]; then
  wp post update "${CHECKOUT_ID}" --post_content='[woocommerce_checkout]' >/dev/null
  log "Checkout page #${CHECKOUT_ID} forced to [woocommerce_checkout]."
fi

# ---------------------------------------------------------------------------
# Enable Cash on Delivery (offline gateway). enable_for_virtual=yes is REQUIRED
# because event tickets are virtual products — COD hides itself on virtual-only
# carts otherwise, leaving no way to place the order.
# ---------------------------------------------------------------------------
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery","description":"Pay with cash upon delivery.","instructions":"Pay on the day of the event.","enable_for_methods":[],"enable_for_virtual":"yes"}' --format=json >/dev/null
log "Cash on Delivery enabled (incl. virtual-only carts)."

# ---------------------------------------------------------------------------
# Pretty permalinks so /event/<slug>/ resolves to the single-event storefront.
# ---------------------------------------------------------------------------
wp rewrite structure '/%postname%/' --hard >/dev/null
wp rewrite flush --hard >/dev/null
log "Pretty permalinks enabled."

# ---------------------------------------------------------------------------
# Create (or reuse) the published paid event with two ticket tiers.
# ---------------------------------------------------------------------------
EVENT_SLUG="e2e-test-event"
EVENT_ID="$(wp post list --post_type=event --post_status=any --name="${EVENT_SLUG}" --field=ID --posts_per_page=1 2>/dev/null | head -n1 || true)"
if [ -z "${EVENT_ID}" ]; then
  EVENT_ID="$(wp post create \
    --post_type=event \
    --post_status=publish \
    --post_title='E2E Test Event' \
    --post_name="${EVENT_SLUG}" \
    --post_content='Automated end-to-end purchase fixture event.' \
    --porcelain)"
  log "Created event #${EVENT_ID}."
else
  wp post update "${EVENT_ID}" --post_status=publish >/dev/null
  log "Reusing event #${EVENT_ID}."
fi

# Date meta (future) + registration + capacity.
START_DATE="$(wp eval 'echo gmdate("Y-m-d", strtotime("+30 days"));')"
START_TS="$(wp eval 'echo (int) strtotime("+30 days 09:00:00");')"
wp post meta update "${EVENT_ID}" _anchor_event_start_date          "${START_DATE}" >/dev/null
wp post meta update "${EVENT_ID}" _anchor_event_start_ts            "${START_TS}"   >/dev/null
wp post meta update "${EVENT_ID}" _anchor_event_end_date            "${START_DATE}" >/dev/null
wp post meta update "${EVENT_ID}" _anchor_event_registration_enabled 1              >/dev/null
wp post meta update "${EVENT_ID}" _anchor_event_registration_type    "internal"     >/dev/null
wp post meta update "${EVENT_ID}" _anchor_event_capacity             50             >/dev/null
wp post meta update "${EVENT_ID}" _anchor_event_status               "upcoming"     >/dev/null
wp post meta update "${EVENT_ID}" _anchor_event_status_mode          "manual"       >/dev/null

# Two PAID + active ticket tiers (GA $10, VIP $25). Stored exactly as the
# Ticket_Types model reads them (_anchor_event_ticket_types). sync_event writes
# each tier's wc_variation_id back in.
wp post meta update "${EVENT_ID}" _anchor_event_ticket_types '[{"id":"ga","label":"General Admission","price":"10.00","quota":0,"sale_start":"","sale_end":"","active":true,"attendee_fields":[]},{"id":"vip","label":"VIP","price":"25.00","quota":0,"sale_start":"","sale_end":"","active":true,"attendee_fields":[]}]' --format=json >/dev/null
log "Event meta + ticket tiers (GA \$10, VIP \$25) set."

# ---------------------------------------------------------------------------
# Trigger the WooCommerce product sync so the managed variable product +
# per-tier variations exist (required for add-to-cart).
# ---------------------------------------------------------------------------
PRODUCT_ID="$(wp eval '$m = \Anchor\Events\Module::instance(); echo ( $m && $m->product_sync ) ? (int) $m->product_sync->sync_event('"${EVENT_ID}"') : 0;')"
log "Managed product id: ${PRODUCT_ID}"
if [ "${PRODUCT_ID}" = "0" ] || [ -z "${PRODUCT_ID}" ]; then
  log "WARNING: product sync returned 0 — paid tiers may not be purchasable."
fi

# ---------------------------------------------------------------------------
# Emit the fixture for the Playwright specs (written via WP so the path is
# correct inside the container; the bind mount surfaces it on the host).
# ---------------------------------------------------------------------------
EVENT_URL="$(wp eval 'echo get_permalink('"${EVENT_ID}"');')"
mkdir -p "${PLUGIN_DIR}/e2e"
wp eval 'file_put_contents("'"${PLUGIN_DIR}"'/e2e/.seed.json", json_encode(["event_id"=>(int)'"${EVENT_ID}"',"event_url"=>get_permalink('"${EVENT_ID}"'),"product_id"=>(int)'"${PRODUCT_ID}"'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n");'
log "Event URL: ${EVENT_URL}"
log "Wrote ${PLUGIN_DIR}/e2e/.seed.json"
log "Seed complete."
