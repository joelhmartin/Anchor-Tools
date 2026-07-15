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
#
# Re-run guard: WP's update_option() returns false (a no-op, not a failure)
# when the new value is identical to the stored one, and `wp option update`
# treats that false as an error — breaking idempotency on a second `env:seed`
# run against an already-seeded site. Skip the write when the value already
# matches so the script stays idempotent.
if [ "$(wp option get anchor_schema_settings --format=json 2>/dev/null || true)" != '{"modules":{"events_manager":true}}' ]; then
  wp option update anchor_schema_settings '{"modules":{"events_manager":true}}' --format=json --autoload=no >/dev/null
fi
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
# Create (or reuse) the published page hosting the front-end [event_manager]
# shortcode form. e2e/event-manager-authoring.spec.js (Task 1.5) drives the
# manager-form UI/save round-trip through this page; without it the spec has
# no fixture to navigate to. Idempotent, same idiom as the event fixture above.
# ---------------------------------------------------------------------------
MANAGER_PAGE_SLUG="event-manager"
MANAGER_PAGE_ID="$(wp post list --post_type=page --post_status=any --name="${MANAGER_PAGE_SLUG}" --field=ID --posts_per_page=1 2>/dev/null | head -n1 || true)"
if [ -z "${MANAGER_PAGE_ID}" ]; then
  MANAGER_PAGE_ID="$(wp post create \
    --post_type=page \
    --post_status=publish \
    --post_title='Event Manager' \
    --post_name="${MANAGER_PAGE_SLUG}" \
    --post_content='[event_manager]' \
    --porcelain)"
  log "Created event-manager page #${MANAGER_PAGE_ID}."
else
  wp post update "${MANAGER_PAGE_ID}" --post_status=publish --post_content='[event_manager]' >/dev/null
  log "Reusing event-manager page #${MANAGER_PAGE_ID}."
fi

# ---------------------------------------------------------------------------
# Create (or reuse) a published MULTISESSION event (Task 1.6): a "Sessions"
# list is rendered on the single-event page from _anchor_event_sessions.
# Read by e2e/event-frontend.spec.js. Idempotent, same idiom as the paid-event
# fixture above.
# ---------------------------------------------------------------------------
MULTI_SLUG="e2e-multisession-event"
MULTI_EVENT_ID="$(wp post list --post_type=event --post_status=any --name="${MULTI_SLUG}" --field=ID --posts_per_page=1 2>/dev/null | head -n1 || true)"
if [ -z "${MULTI_EVENT_ID}" ]; then
  MULTI_EVENT_ID="$(wp post create \
    --post_type=event \
    --post_status=publish \
    --post_title='E2E Multisession Event' \
    --post_name="${MULTI_SLUG}" \
    --post_content='Automated end-to-end multisession fixture event.' \
    --porcelain)"
  log "Created multisession event #${MULTI_EVENT_ID}."
else
  wp post update "${MULTI_EVENT_ID}" --post_status=publish >/dev/null
  log "Reusing multisession event #${MULTI_EVENT_ID}."
fi

MULTI_START_DATE="$(wp eval 'echo gmdate("Y-m-d", strtotime("+50 days"));')"
MULTI_START_TS="$(wp eval 'echo (int) strtotime("+50 days 09:00:00");')"
MULTI_DAY2_DATE="$(wp eval 'echo gmdate("Y-m-d", strtotime("+51 days"));')"
MULTI_DAY3_DATE="$(wp eval 'echo gmdate("Y-m-d", strtotime("+52 days"));')"
wp post meta update "${MULTI_EVENT_ID}" _anchor_event_type        "multisession"        >/dev/null
wp post meta update "${MULTI_EVENT_ID}" _anchor_event_start_date  "${MULTI_START_DATE}" >/dev/null
wp post meta update "${MULTI_EVENT_ID}" _anchor_event_start_ts    "${MULTI_START_TS}"   >/dev/null
wp post meta update "${MULTI_EVENT_ID}" _anchor_event_end_date    "${MULTI_DAY3_DATE}"  >/dev/null
wp post meta update "${MULTI_EVENT_ID}" _anchor_event_status      "upcoming"            >/dev/null
wp post meta update "${MULTI_EVENT_ID}" _anchor_event_status_mode "manual"              >/dev/null
wp post meta update "${MULTI_EVENT_ID}" _anchor_event_sessions '[{"date":"'"${MULTI_START_DATE}"'","start_time":"09:00","end_time":"10:30","label":"Day 1: Orientation"},{"date":"'"${MULTI_DAY2_DATE}"'","start_time":"09:00","end_time":"12:00","label":"Day 2: Workshop"},{"date":"'"${MULTI_DAY3_DATE}"'","start_time":"09:00","end_time":"11:00","label":"Day 3: Wrap-up"}]' --format=json >/dev/null
log "Multisession event meta + 3 sessions set."

# ---------------------------------------------------------------------------
# Create (or reuse) a published EXTERNAL-registration event, LINK variant
# (Task 1.6): registration_mode=external with only a URL — renders the
# link-out button + display price, no cart/ticket UI. Read by
# e2e/event-frontend.spec.js.
# ---------------------------------------------------------------------------
EXT_SLUG="e2e-external-event"
EXT_EVENT_ID="$(wp post list --post_type=event --post_status=any --name="${EXT_SLUG}" --field=ID --posts_per_page=1 2>/dev/null | head -n1 || true)"
if [ -z "${EXT_EVENT_ID}" ]; then
  EXT_EVENT_ID="$(wp post create \
    --post_type=event \
    --post_status=publish \
    --post_title='E2E External Event' \
    --post_name="${EXT_SLUG}" \
    --post_content='Automated end-to-end external-registration fixture event.' \
    --porcelain)"
  log "Created external event #${EXT_EVENT_ID}."
else
  wp post update "${EXT_EVENT_ID}" --post_status=publish >/dev/null
  log "Reusing external event #${EXT_EVENT_ID}."
fi

EXT_START_DATE="$(wp eval 'echo gmdate("Y-m-d", strtotime("+45 days"));')"
EXT_START_TS="$(wp eval 'echo (int) strtotime("+45 days 09:00:00");')"
wp post meta update "${EXT_EVENT_ID}" _anchor_event_start_date           "${EXT_START_DATE}" >/dev/null
wp post meta update "${EXT_EVENT_ID}" _anchor_event_start_ts             "${EXT_START_TS}"   >/dev/null
wp post meta update "${EXT_EVENT_ID}" _anchor_event_end_date             "${EXT_START_DATE}" >/dev/null
wp post meta update "${EXT_EVENT_ID}" _anchor_event_status               "upcoming"          >/dev/null
wp post meta update "${EXT_EVENT_ID}" _anchor_event_status_mode          "manual"            >/dev/null
wp post meta update "${EXT_EVENT_ID}" _anchor_event_registration_enabled 1                   >/dev/null
wp post meta update "${EXT_EVENT_ID}" _anchor_event_registration_mode    "external"          >/dev/null
wp post meta update "${EXT_EVENT_ID}" _anchor_event_external_url         "https://example.test/e2e-external-register" >/dev/null
wp post meta update "${EXT_EVENT_ID}" _anchor_event_external_display_price '$495' >/dev/null
log "External (link variant) event meta set."

# ---------------------------------------------------------------------------
# Create (or reuse) a published EXTERNAL-registration event, EMBED variant
# (Task 1.6): registration_mode=external with an already-sanitized
# `external_embed` (a real <iframe src=...>) — renders the embed as trusted
# HTML plus the display price. Read by e2e/event-frontend.spec.js.
# ---------------------------------------------------------------------------
EXT_EMBED_SLUG="e2e-external-embed-event"
EXT_EMBED_EVENT_ID="$(wp post list --post_type=event --post_status=any --name="${EXT_EMBED_SLUG}" --field=ID --posts_per_page=1 2>/dev/null | head -n1 || true)"
if [ -z "${EXT_EMBED_EVENT_ID}" ]; then
  EXT_EMBED_EVENT_ID="$(wp post create \
    --post_type=event \
    --post_status=publish \
    --post_title='E2E External Embed Event' \
    --post_name="${EXT_EMBED_SLUG}" \
    --post_content='Automated end-to-end external-embed fixture event.' \
    --porcelain)"
  log "Created external embed event #${EXT_EMBED_EVENT_ID}."
else
  wp post update "${EXT_EMBED_EVENT_ID}" --post_status=publish >/dev/null
  log "Reusing external embed event #${EXT_EMBED_EVENT_ID}."
fi

EXT_EMBED_START_DATE="$(wp eval 'echo gmdate("Y-m-d", strtotime("+46 days"));')"
EXT_EMBED_START_TS="$(wp eval 'echo (int) strtotime("+46 days 09:00:00");')"
wp post meta update "${EXT_EMBED_EVENT_ID}" _anchor_event_start_date           "${EXT_EMBED_START_DATE}" >/dev/null
wp post meta update "${EXT_EMBED_EVENT_ID}" _anchor_event_start_ts             "${EXT_EMBED_START_TS}"   >/dev/null
wp post meta update "${EXT_EMBED_EVENT_ID}" _anchor_event_end_date             "${EXT_EMBED_START_DATE}" >/dev/null
wp post meta update "${EXT_EMBED_EVENT_ID}" _anchor_event_status               "upcoming"                >/dev/null
wp post meta update "${EXT_EMBED_EVENT_ID}" _anchor_event_status_mode          "manual"                  >/dev/null
wp post meta update "${EXT_EMBED_EVENT_ID}" _anchor_event_registration_enabled 1                         >/dev/null
wp post meta update "${EXT_EMBED_EVENT_ID}" _anchor_event_registration_mode    "external"                >/dev/null
wp post meta update "${EXT_EMBED_EVENT_ID}" _anchor_event_external_embed       '<iframe src="https://example.test/e2e-embed" width="600" height="400" allowfullscreen></iframe>' >/dev/null
wp post meta update "${EXT_EMBED_EVENT_ID}" _anchor_event_external_display_price '$150' >/dev/null
log "External (embed variant) event meta set."

# ---------------------------------------------------------------------------
# Create (or reuse) a published OFFERING-type parent event (Task 2.3): a
# "Pick-one offerings" event with 2 offering_dates, reconciled into 2 CHILD
# event posts via Occurrences::reconcile(). Read by
# e2e/event-grouping-authoring.spec.js, which edits it to 3 dates in the
# metabox and asserts the child count follows. Idempotent: re-seeding
# re-applies the same offering_dates and re-runs reconcile(), which is itself
# idempotent (Task 2.1) — no duplicate children accumulate across re-runs.
# ---------------------------------------------------------------------------
OFFERING_SLUG="e2e-offering-event"
OFFERING_EVENT_ID="$(wp post list --post_type=event --post_status=any --name="${OFFERING_SLUG}" --field=ID --posts_per_page=1 2>/dev/null | head -n1 || true)"
if [ -z "${OFFERING_EVENT_ID}" ]; then
  OFFERING_EVENT_ID="$(wp post create \
    --post_type=event \
    --post_status=publish \
    --post_title='E2E Offering Event' \
    --post_name="${OFFERING_SLUG}" \
    --post_content='Automated end-to-end pick-one-offerings fixture event.' \
    --porcelain)"
  log "Created offering event #${OFFERING_EVENT_ID}."
else
  wp post update "${OFFERING_EVENT_ID}" --post_status=publish >/dev/null
  log "Reusing offering event #${OFFERING_EVENT_ID}."
fi

OFFERING_DATE_1="$(wp eval 'echo gmdate("Y-m-d", strtotime("+60 days"));')"
OFFERING_DATE_2="$(wp eval 'echo gmdate("Y-m-d", strtotime("+67 days"));')"
wp post meta update "${OFFERING_EVENT_ID}" _anchor_event_type                 "offering"           >/dev/null
wp post meta update "${OFFERING_EVENT_ID}" _anchor_event_start_date           "${OFFERING_DATE_1}" >/dev/null
wp post meta update "${OFFERING_EVENT_ID}" _anchor_event_end_date             "${OFFERING_DATE_1}" >/dev/null
wp post meta update "${OFFERING_EVENT_ID}" _anchor_event_status               "upcoming"           >/dev/null
wp post meta update "${OFFERING_EVENT_ID}" _anchor_event_status_mode          "manual"             >/dev/null
wp post meta update "${OFFERING_EVENT_ID}" _anchor_event_registration_enabled 1                    >/dev/null
wp post meta update "${OFFERING_EVENT_ID}" _anchor_event_registration_mode    "free"               >/dev/null
wp post meta update "${OFFERING_EVENT_ID}" _anchor_event_offering_dates '[{"date":"'"${OFFERING_DATE_1}"'","start_time":"09:00","end_time":"11:00","label":"Session A","capacity":10},{"date":"'"${OFFERING_DATE_2}"'","start_time":"09:00","end_time":"11:00","label":"Session B","capacity":10}]' --format=json >/dev/null

OFFERING_LIVE_COUNT="$(wp eval '$m = \Anchor\Events\Module::instance(); echo ( $m && $m->occurrences ) ? count( $m->occurrences->reconcile( '"${OFFERING_EVENT_ID}"' ) ) : 0;')"
log "Offering event #${OFFERING_EVENT_ID} reconciled -> ${OFFERING_LIVE_COUNT} live child date(s)."

# ---------------------------------------------------------------------------
# Create (or reuse) a published RECURRING-type parent event (Task 2.3): a
# weekly rule bounded by count=3, reconciled into 3 CHILD event posts. Read by
# e2e/event-grouping-authoring.spec.js (the incomplete-rule guard + the
# complete-rule reconcile). Same idempotency note as the offering fixture
# above.
# ---------------------------------------------------------------------------
RECURRING_SLUG="e2e-recurring-event"
RECURRING_EVENT_ID="$(wp post list --post_type=event --post_status=any --name="${RECURRING_SLUG}" --field=ID --posts_per_page=1 2>/dev/null | head -n1 || true)"
if [ -z "${RECURRING_EVENT_ID}" ]; then
  RECURRING_EVENT_ID="$(wp post create \
    --post_type=event \
    --post_status=publish \
    --post_title='E2E Recurring Event' \
    --post_name="${RECURRING_SLUG}" \
    --post_content='Automated end-to-end recurring-schedule fixture event.' \
    --porcelain)"
  log "Created recurring event #${RECURRING_EVENT_ID}."
else
  wp post update "${RECURRING_EVENT_ID}" --post_status=publish >/dev/null
  log "Reusing recurring event #${RECURRING_EVENT_ID}."
fi

RECURRING_START_DATE="$(wp eval 'echo gmdate("Y-m-d", strtotime("next monday +80 days"));')"
wp post meta update "${RECURRING_EVENT_ID}" _anchor_event_type                 "recurring"               >/dev/null
wp post meta update "${RECURRING_EVENT_ID}" _anchor_event_start_date           "${RECURRING_START_DATE}" >/dev/null
wp post meta update "${RECURRING_EVENT_ID}" _anchor_event_end_date             "${RECURRING_START_DATE}" >/dev/null
wp post meta update "${RECURRING_EVENT_ID}" _anchor_event_status               "upcoming"                >/dev/null
wp post meta update "${RECURRING_EVENT_ID}" _anchor_event_status_mode          "manual"                  >/dev/null
wp post meta update "${RECURRING_EVENT_ID}" _anchor_event_registration_enabled 1                         >/dev/null
wp post meta update "${RECURRING_EVENT_ID}" _anchor_event_registration_mode    "free"                    >/dev/null
wp post meta update "${RECURRING_EVENT_ID}" _anchor_event_recurrence '{"freq":"weekly","interval":1,"count":3,"start_time":"09:00","end_time":"10:00","capacity":8}' --format=json >/dev/null

RECURRING_LIVE_COUNT="$(wp eval '$m = \Anchor\Events\Module::instance(); echo ( $m && $m->occurrences ) ? count( $m->occurrences->reconcile( '"${RECURRING_EVENT_ID}"' ) ) : 0;')"
log "Recurring event #${RECURRING_EVENT_ID} reconciled -> ${RECURRING_LIVE_COUNT} live occurrence(s)."

# ---------------------------------------------------------------------------
# Emit the fixture for the Playwright specs (written via WP so the path is
# correct inside the container; the bind mount surfaces it on the host).
# ---------------------------------------------------------------------------
EVENT_URL="$(wp eval 'echo get_permalink('"${EVENT_ID}"');')"
MANAGER_PAGE_URL="$(wp eval 'echo get_permalink('"${MANAGER_PAGE_ID}"');')"
MULTI_EVENT_URL="$(wp eval 'echo get_permalink('"${MULTI_EVENT_ID}"');')"
EXT_EVENT_URL="$(wp eval 'echo get_permalink('"${EXT_EVENT_ID}"');')"
EXT_EMBED_EVENT_URL="$(wp eval 'echo get_permalink('"${EXT_EMBED_EVENT_ID}"');')"
OFFERING_EVENT_URL="$(wp eval 'echo get_permalink('"${OFFERING_EVENT_ID}"');')"
RECURRING_EVENT_URL="$(wp eval 'echo get_permalink('"${RECURRING_EVENT_ID}"');')"
mkdir -p "${PLUGIN_DIR}/e2e"
wp eval 'file_put_contents("'"${PLUGIN_DIR}"'/e2e/.seed.json", json_encode(["event_id"=>(int)'"${EVENT_ID}"',"event_url"=>get_permalink('"${EVENT_ID}"'),"product_id"=>(int)'"${PRODUCT_ID}"',"manager_page_id"=>(int)'"${MANAGER_PAGE_ID}"',"manager_page_url"=>get_permalink('"${MANAGER_PAGE_ID}"'),"multisession_event_id"=>(int)'"${MULTI_EVENT_ID}"',"multisession_event_url"=>get_permalink('"${MULTI_EVENT_ID}"'),"external_event_id"=>(int)'"${EXT_EVENT_ID}"',"external_event_url"=>get_permalink('"${EXT_EVENT_ID}"'),"external_embed_event_id"=>(int)'"${EXT_EMBED_EVENT_ID}"',"external_embed_event_url"=>get_permalink('"${EXT_EMBED_EVENT_ID}"'),"offering_event_id"=>(int)'"${OFFERING_EVENT_ID}"',"offering_event_url"=>get_permalink('"${OFFERING_EVENT_ID}"'),"recurring_event_id"=>(int)'"${RECURRING_EVENT_ID}"',"recurring_event_url"=>get_permalink('"${RECURRING_EVENT_ID}"')], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n");'
log "Event URL: ${EVENT_URL}"
log "Manager form page URL: ${MANAGER_PAGE_URL}"
log "Multisession event URL: ${MULTI_EVENT_URL}"
log "External (link) event URL: ${EXT_EVENT_URL}"
log "External (embed) event URL: ${EXT_EMBED_EVENT_URL}"
log "Offering event URL: ${OFFERING_EVENT_URL}"
log "Recurring event URL: ${RECURRING_EVENT_URL}"
log "Wrote ${PLUGIN_DIR}/e2e/.seed.json"
log "Seed complete."
