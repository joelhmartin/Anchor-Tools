# Anchor Locations — Service Area SEO Engine (Phase 1)

**Module key:** `locations` · **Directory:** `anchor-locations/` · **Namespace:** `\Anchor\Locations\`
**Status:** Approved design, Phase 1 · **Date:** 2026-07-17

## 1. Purpose & Philosophy

`anchor-locations` is a **data model + renderer + map** for programmatic service-area SEO. It does **not**
generate pages, run bulk generators, score content, or build combos. Pages are created and populated
**externally** (AI over SSH + WP-CLI). The plugin's job is to make that external population trivial by
providing:

1. A clean, predictable content model (2 CPTs + 1 taxonomy) with documented meta keys.
2. A parent-child location hierarchy.
3. Frontend rendering of each page's Monaco HTML/CSS/JS content, theme-agnostically (works in Divi or any builder).
4. A Google map whose markers/regions link to the pages.
5. Auto-generated internal-link / directory / breadcrumb displays derived from the relationships.
6. JSON-LD schema (BreadcrumbList + Service/Place) derived from the relationships.

Everything the original spec called "generator / coverage matrix / quality scoring / dashboards / content
libraries / GSC / GA4 / import-export / template-inheritance engine / custom roles" is **deferred to later
phases**, not built here.

## 2. Content Model

### 2.1 `anchor_location` — hierarchical CPT (location hubs)

The state/county/city/etc. hub pages.

- `public => true`, `hierarchical => true`, `supports => ['title','editor','thumbnail','page-attributes']`.
- Hierarchy via native `post_parent` (Allegheny County → Pittsburgh).
- Rewrite base: **`service-areas`** (configurable). Permalink `/service-areas/{post_name}/`, e.g.
  `/service-areas/pittsburgh-pa/`.
- Slug convention (set externally): `{city}-{state_abbr}` / `{county}-county-{state_abbr}`. Plugin reads
  `post_name` as-is; does not enforce.

**Meta keys** (all prefixed `al_`):

| Key | Type | Notes |
|---|---|---|
| `al_type` | string | `state` \| `county` \| `city` \| `township` \| `borough` \| `neighborhood` \| `region` |
| `al_lat` | float | Latitude |
| `al_lng` | float | Longitude |
| `al_place_id` | string | Google Place ID (optional) |
| `al_state_abbr` | string | e.g. `PA` — for breadcrumbs/schema |
| `al_county` | string | Denormalized county name (optional; hierarchy is source of truth) |
| `al_postal_codes` | string | Comma-separated (optional) |
| `al_boundary` | longtext | GeoJSON polygon string (optional, mainly counties) |
| `al_marker_icon` | string | Media URL override; falls back to the global settings icon |
| `al_html` / `al_css` / `al_js` | longtext | Monaco content (see §4) |

### 2.2 `anchor_service_page` — flat CPT (service × location page)

A single "service in a location" page, e.g. *Roofing in Pittsburgh*.

- `public => true`, `hierarchical => false`, `supports => ['title','editor','thumbnail']`.
- Tagged with exactly **one** `service` term and linked to exactly **one** `anchor_location`.
- Rewrite base: **`services`** (configurable). Public URL `/services/{service-term-slug}/{location-slug}/`,
  e.g. `/services/roofing/pittsburgh-pa/` — produced by a custom rewrite rule (§3), **not** the default CPT
  permalink. Internal `post_name` is made unique (e.g. `roofing-pittsburgh-pa`) but never shown.

**Meta keys** (prefixed `al_`):

| Key | Type | Notes |
|---|---|---|
| `al_location_id` | int | The linked `anchor_location` post ID (**required** for a valid page) |
| `al_html` / `al_css` / `al_js` | longtext | Monaco content |

The service is stored via the `service` **taxonomy** (not meta) so term-based queries and filtering work.

### 2.3 `service` — hierarchical taxonomy (internal)

- Attached to `anchor_service_page`. `public => false`, `rewrite => false`, `show_ui => true`,
  `hierarchical => true` (supports Roofing → Roof Repair).
- No public archive — it exists for relationships/filtering/schema only.
- Optional **term meta** `al_main_page_id`: the normal WordPress page (`/services/roofing/`) that is this
  service's location-agnostic main page. Used only for linking; the plugin never owns that page.

### 2.4 Main service pages

`/services/roofing/` remain **normal WordPress pages** built in Divi/The7/Gutenberg. The plugin never
registers, owns, or overwrites them. The two-segment rewrite rule cannot collide with a one-segment page URL.

## 3. URLs & Rewrite Rules

- `anchor_location`: standard CPT rewrite, base `service-areas` → `/service-areas/{slug}/`.
- `anchor_service_page`: register with `rewrite => false`; add **one** custom rule:
  `^{services_base}/([^/]+)/([^/]+)/?$` → query vars `al_service` (term slug) + `al_loc` (location slug).
  A `pre_get_posts` / `parse_request` resolver finds the `anchor_service_page` whose `service` term slug and
  linked location's slug match, and sets it as the queried single post. Registered with high priority so it is
  evaluated before generic rules; the two-segment requirement means `/services/roofing/` (a Page) never matches.
- `services_base` and `service_areas_base` are stored in settings (defaults `services`, `service-areas`).
- `permalink` for `anchor_service_page` is produced via a `post_type_link` filter that builds
  `/{services_base}/{service-slug}/{location-slug}/` from the term + linked location — so `get_permalink()`,
  admin, sitemaps, and links are all correct.
- Rewrite rules are flushed on **activation** and whenever a base changes (a `flush_rewrite_rules()` guarded by
  a stored version option). Never flushed on normal page loads.
- Edge cases: a `anchor_service_page` with no `al_location_id`, or whose service term is missing, returns a
  non-resolving permalink (`#`) and is excluded from the map/directory. A location with no coords is excluded
  from the map but still renders its page.

## 4. Rendering — Monaco "within the page editor", theme-agnostic

### 4.1 Editor
Both CPTs get the **shared tabbed Monaco HTML/CSS/JS metabox + live preview**, reusing
`Anchor_Monaco::enqueue(CPT)` and `Anchor_Preview_CSS` exactly as `anchor-blocks` does (no new editor code).
Fields persist to `al_html` / `al_css` / `al_js`. External AI writes straight into these via WP-CLI/meta.

### 4.2 Frontend output
On `is_singular( CPT )` for either post type, render:

```
[global wrapper open] → [breadcrumbs] → {{content = al_html}} → [global wrapper close]
```

- CSS from `al_css` is scoped/printed and JS from `al_js` is printed in the footer — same mechanism as
  `anchor-blocks` (reuse its scoping/print approach; do not reinvent).
- Rendering integrates via `the_content` filter (so it flows through the theme's normal single template and
  therefore works in Divi/The7/any theme). A `template_include` fallback provides a minimal full-width
  template only when the theme has no suitable single template (opt-in via setting; default off to stay
  builder-friendly).
- **Builder escape hatch:** shortcode `[anchor_page_content id="123"]` renders a given page's body (or the
  current post's when `id` omitted) so a Divi Theme Builder / block template can place the plugin content
  wherever it wants. This is what makes "works in Divi or anywhere" true.

### 4.3 Global wrapper template (simple, optional, overridable)
One **global** Monaco HTML/CSS/JS template stored in module settings (single option, not per-page), providing
header / sidebar / global CTA / form around every page. It must contain a `{{content}}` token (or
`[anchor_page_content]`) marking where the per-page body goes. Also supports the relationship tokens in §5.

- This is **one flat template**, not an inheritance engine. Global → nothing else. (Per-service / per-type /
  per-page inheritance is a deferred Phase 2.)
- A per-post checkbox `al_disable_wrapper` (default off) lets any page opt out — so a fully Divi-built page can
  skip the wrapper entirely and just use `[anchor_page_content]`.

## 5. Internal Linking / Directory Displays

Auto-generated from the hierarchy + relationships, always reflecting current published state. Available as
**shortcodes** (drop into Monaco content or any builder) and as tokens inside the global wrapper:

| Shortcode | Output |
|---|---|
| `[anchor_location_map]` | The Google map (§6) |
| `[anchor_location_services]` | Services available at this location → links to each `anchor_service_page` |
| `[anchor_service_locations]` | Other locations offering this service (for a service page) |
| `[anchor_nearby_locations]` | Sibling locations under the same parent (or nearest by coords) |
| `[anchor_child_locations]` | Child locations (cities under a county) |
| `[anchor_location_parent]` | Parent location link |
| `[anchor_breadcrumbs]` | Full-hierarchy breadcrumb trail (Home › Service Areas › PA › Allegheny County › Pittsburgh) |
| `[anchor_service_area_directory]` | Structured directory of the whole tree (accordion/columns), published+indexable only |

All only list **published** posts. Output is filterable (`anchor_locations_{shortcode}_html`).

## 6. Map & Settings

### 6.1 Settings tab
New **"Locations"** tab on `Settings > Anchor Tools` via
`add_filter('anchor_settings_tabs', …, 65)` + `anchor_settings_enqueue_locations` action. Stored in a single
option `anchor_locations_settings` (`autoload=false`). Fields:

- Default **marker icon** (media picker or preset SVG).
- **Rewrite bases** (`services_base`, `service_areas_base`).
- **Global wrapper template** Monaco editor (HTML/CSS/JS) — §4.3.
- Map defaults: center lat/lng, zoom, height.
- Toggle: enable full-width `template_include` fallback (default off).

Google Maps API key is **read from the existing** `Anchor_Schema_Admin::OPTION_KEY['google_api_key']` — no new
key field.

### 6.2 `[anchor_location_map]`
- Loads Google Maps JS (`…/maps/api/js?key=…`) **only on pages containing the map** (enqueue gated by a render
  flag, mirroring the store-locator approach). No global Maps script.
- Markers from published `anchor_location` posts with valid `al_lat`/`al_lng`, using `al_marker_icon` or the
  settings default. Optional marker clustering.
- Marker click → popup: location name (→ hub page) + the location's available service pages (→ each service
  page).
- Optional county **GeoJSON polygon** rendered when `al_boundary` present (hover highlight + click → hub).
- Attributes: `types="city,county"`, `service="roofing"` (filter to a service), `parent="123"` (subtree),
  `zoom`, `height`, `center`.
- Marker/boundary data is cached (transient keyed by a query hash), busted on `save_post` of either CPT.

## 7. Schema (JSON-LD)

Output in `wp_head` on `is_singular` of the CPTs (self-contained; may register through `Anchor_Schema_Render`
if a clean hook exists, else print directly):

- **BreadcrumbList** — from the full hierarchy (even though the URL is shortened).
- **Service** on `anchor_service_page` — `provider` = the site's organization, `areaServed` = the linked
  location (Place/City/AdministrativeArea by `al_type`), `serviceType` = service term.
- **Place / City / AdministrativeArea** on `anchor_location` — with `GeoCoordinates` from `al_lat`/`al_lng`.
- **Never** asserts a physical business address at a service area (no `PostalAddress` for a location the
  company doesn't staff). Schema only emitted when the corresponding content exists.

## 8. Admin UX

Menu: standard CPT admin under an **"Anchor Locations"** parent (`show_in_menu` via
`apply_filters('anchor_locations_parent_menu', true)`):

- **Locations** (`anchor_location`) — with a `Type` and `Parent` admin column.
- **Service Pages** (`anchor_service_page`) — with `Service` and `Location` admin columns; column shows a
  ⚠ when `al_location_id` is missing/invalid.
- **Services** (the `service` taxonomy screen).
- Settings live on the shared `Settings > Anchor Tools` "Locations" tab (§6.1).

Metaboxes per CPT: **Content** (Monaco, high), **Details** (side: type/coords/place_id/state/icon/boundary for
locations; service+location linkage for service pages), **Preview** (normal). Save handlers follow the repo's
nonce/autosave/capability pattern; options use `autoload=false`; asset paths use `ANCHOR_TOOLS_PLUGIN_URL`.

## 9. File Layout

```
anchor-locations/
  anchor-locations.php          # \Anchor\Locations\Module — CPTs, taxonomy, rewrites, render, shortcodes, schema, settings tab
  assets/
    admin.css / admin.js        # metabox glue (mounts shared Monaco via data-anchor-monaco)
    frontend.css / frontend.js  # map + directory/accordion behavior
```
Source `.css`/`.js` only; `*.min.*` are CI-generated and gitignored. Register in
`anchor_tools_get_available_modules()` in `anchor-tools.php`:

```php
'locations' => [
    'label'       => __( 'Anchor Locations', 'anchor-schema' ),
    'description' => __( 'Service-area & service-location pages with a linked Google map, hierarchy, and internal linking.', 'anchor-schema' ),
    'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-locations/anchor-locations.php',
    'class'       => '\\Anchor\\Locations\\Module',
],
```

## 10. Testing

- **PHPUnit**: rewrite rule resolves `/services/{service}/{loc}/` to the right post; `post_type_link` builds
  correct permalinks; permalink is `#`/excluded when linkage missing; shortcodes list only published posts and
  respect hierarchy; breadcrumb reflects full ancestry; schema shape for each CPT; settings save/sanitize;
  map data query filters by type/service/parent. Follow existing `tests/` conventions.
- **Manual/E2E** (documented, run externally since population is external): create a county + child city + a
  service page via WP-CLI, confirm URLs, map markers, popups, breadcrumbs, and Divi `[anchor_page_content]`
  placement.

## 11. Conventions (repo)

Text domain `anchor-schema`; AJAX actions `anchor_locations_*`; asset URLs via `ANCHOR_TOOLS_PLUGIN_URL`;
admin assets gated to `post.php`/`post-new.php` + CPT check; `update_option(..., autoload=false)`; enqueue
**source** assets; bump version strings on asset change. Namespaced `\Anchor\Locations\`, self-contained so it
can be extracted to a standalone plugin later.

## 12. Deferred (later phases, explicitly not Phase 1)

Page generator · coverage matrix · content-quality scoring · SEO/quality dashboards · projects/testimonials/FAQ
libraries · GSC/GA4 · import/export · full template-inheritance engine (global→service→type→page, section
inherit/override) · custom capabilities & roles · schema-conflict detection vs Yoast/RankMath.
