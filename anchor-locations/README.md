# Anchor Locations

Service-area & service-location pages with a linked Google map, a location hierarchy, and internal-linking shortcodes.

**This module ships with no content.** Locations and service pages are populated **externally**, typically by an AI agent operating over SSH via WP-CLI. This README is the operator reference for that workflow: the data model, every meta key, every shortcode, and copy-paste WP-CLI examples.

Class: `\Anchor\Locations\Module` — registered in the module registry as `locations` (`anchor-tools.php`). Enable/configure it under **Settings > Anchor Tools > Locations**.

---

## 1. Data model

Two custom post types plus one internal taxonomy:

| Post type | Constant | Hierarchical | Purpose |
|---|---|---|---|
| `anchor_location` | `Module::CPT_LOCATION` | Yes (parent/child via `page-attributes`) | The location hub tree — states, counties, cities, neighborhoods, etc. |
| `anchor_service_page` | `Module::CPT_SERVICE` | No | One page per service × location combination. |

| Taxonomy | Constant | Applies to | Notes |
|---|---|---|---|
| `service` | `Module::TAX_SERVICE` | `anchor_service_page` | Hierarchical, internal (`public => false`, `show_ui => true` — editable in wp-admin but not queryable/browsable on the front end). A service page must have exactly one `service` term for its URL to resolve. |

A service page is fully wired only when **both** are set: a `service` term, and the `al_location_id` meta pointing at a published `anchor_location` post. Missing either shows `⚠ incomplete` in the admin list column and the page's permalink falls back to `#`.

### URL structure

| Post type | URL pattern | Mechanism |
|---|---|---|
| `anchor_location` | `/{service-areas-base}/{slug}/` (nested under ancestors, since it's a hierarchical CPT) | Normal WP CPT rewrite (`rewrite => ['slug' => service_areas_base]`) |
| `anchor_service_page` | `/{services-base}/{service-slug}/{location-slug}/` | Custom rewrite rule + `post_type_link` filter — **not** a normal CPT permalink (`rewrite => false` on the CPT itself) |

Defaults: `service-areas-base` = `service-areas`, `services-base` = `services`. Both are configurable in **Settings > Anchor Tools > Locations**. The location slug in a service-page URL is the location's own `post_name` (a single path segment — it does not need to include its ancestors' slugs, just be unique across `anchor_location`).

**Reserved path space:** the plugin claims the two-segment path `/{services-base}/{anything}/{anything}/` (default `/services/{service}/{location}/`) via a rewrite rule with `'top'` priority. A single-segment page like a normal WordPress Page at `/services/roofing/` is **not** affected — the rule only matches when there are exactly two segments under the base. If you rename `services-base` away from `services` there is no collision risk at all.

---

## 2. Meta keys

### `anchor_location` posts

| Meta key | Type | Purpose |
|---|---|---|
| `al_type` | string enum | One of `state`, `county`, `city`, `township`, `borough`, `neighborhood`, `region`. Drives the schema.org `@type` (`AdministrativeArea` for state/county, `City` for city/borough/township, `Place` otherwise) and is filterable via `[anchor_location_map types="..."]`. |
| `al_lat` | string (float) | Latitude. Required for the location to appear on `[anchor_location_map]`. |
| `al_lng` | string (float) | Longitude. Required for the location to appear on `[anchor_location_map]`. |
| `al_place_id` | string | Google Place ID, free text (not currently consumed by any code path — reserved for future Places API use). |
| `al_state_abbr` | string | Two-letter state abbreviation, free text. |
| `al_county` | string | County name, free text. No admin UI field for this one — set it via WP-CLI. |
| `al_postal_codes` | string | Free-text list of ZIP/postal codes (e.g. comma-separated), not currently parsed by code — informational/for future use. |
| `al_boundary` | string (GeoJSON) | Free-text GeoJSON boundary, editable in the admin "Details" metabox. **Drawn on `[anchor_location_map]`** (P3): when the value parses as valid JSON it is attached to the marker and `frontend.js` renders it as a county/area boundary polygon via `google.maps.Data` (a `FeatureCollection`, `Feature`, or bare geometry are all accepted); an unparseable value is silently skipped so a bad paste never breaks the map. |
| `al_marker_icon` | string (URL) | Per-location marker icon override. Falls back to the global `marker_icon` setting when empty. |
| `al_html` / `al_css` / `al_js` | string | The page body, authored via the Monaco HTML/CSS/JS metabox. Rendered via `do_shortcode()` on HTML, CSS is auto id-scoped to `.al-page-{ID}`, JS is wrapped in an IIFE. |
| `al_faq_html` / `al_testimonials_html` / `al_projects_html` | string | The three per-page content sections (FAQ, Testimonials, Projects), each authored via its own Monaco HTML field in the "Content Sections" metabox. Empty by default. See §6. |
| `al_disable_wrapper` | `'1'` or `''` | When `'1'`, skips the global wrapper template (see §4) for this page — use for page-builder pages that already have their own header/footer chrome. |

### `anchor_service_page` posts

| Meta key | Type | Purpose |
|---|---|---|
| `al_location_id` | int (post ID) | The linked `anchor_location` post. Required for the page to have a real permalink; without it, `get_permalink()` returns `#`. |
| `al_html` / `al_css` / `al_js` | string | Same as above — the page body. |
| `al_faq_html` / `al_testimonials_html` / `al_projects_html` | string | Same as above — the three per-page content sections. See §6. |
| `al_disable_wrapper` | `'1'` or `''` | Same as above. |

Plus the `service` taxonomy term (set via `wp post term set`, not post meta) — exactly one term is expected per service page.

---

## 3. Settings (Settings > Anchor Tools > Locations)

Stored as the `anchor_locations_settings` option (array), sanitized by `Module::sanitize_settings()`:

| Key | Purpose |
|---|---|
| `services_base` | Slug base for service-page URLs. Default `services`. |
| `service_areas_base` | Slug base (CPT rewrite slug) for location-hub URLs. Default `service-areas`. |
| `marker_icon` | Global default marker icon URL, used when a location has no `al_marker_icon` of its own. |
| `marker_size` | Default max dimension (px, 8–200; default 40) for custom marker-pin images on `[anchor_location_map]`, aspect-preserving. Overridden per-map by the shortcode's `iconsize` attribute. |
| `map_center` | Default `[anchor_location_map]` center as `lat,lng`, used when the shortcode doesn't pass `center` and there are no markers to derive a center from. |
| `map_zoom` | Default zoom level (int), used when the shortcode doesn't pass `zoom`. |
| `wrapper_html` / `wrapper_css` / `wrapper_js` | The global wrapper template (see §4). Leave `wrapper_html` blank to disable wrapping entirely. |
| `fullwidth_template` | `'1'`/`''` — when `'1'`, a `template_include` filter serves the plugin's minimal full-width single template (`templates/single-anchor-fullwidth.php`: theme header + `the_content` + footer, no sidebar) on singular location/service views when the theme lacks a suitable layout. Default off. |

**Any change to `services_base` or `service_areas_base` triggers a rewrite-rule reflush** on the next request: `sanitize_settings()` deletes the `anchor_locations_rw_sig` option on every save, and `maybe_flush()` (hooked on `init` at priority 99) re-adds the custom rewrite rule and calls `flush_rewrite_rules()` whenever the stored signature is missing or doesn't match the current bases.

### Activation / rewrite-flush behavior

Because Anchor Tools modules are instantiated on `plugins_loaded`, a `register_activation_hook` on the main plugin file cannot reliably reach a module that's toggled on later from the settings screen. This module relies entirely on `maybe_flush()`'s self-healing signature check instead (the same pattern used by `anchor-translate` and `anchor-events-manager` elsewhere in this plugin):

- On the **first request after the module is enabled**, `anchor_locations_rw_sig` doesn't exist yet (`get_option()` returns `false`), which never matches the computed signature string — so the rule is (re-)registered and `flush_rewrite_rules()` runs automatically. No manual step is required.
- **Saving the Locations settings tab** always forces a reflush too (see above), which is the fastest way to fix a stale rewrite cache if `/services/.../ ` URLs ever start 404ing (e.g. after another plugin's activation/deactivation flushed rewrite rules while this module happened to be disabled).
- As a manual fallback, visiting **Settings > Permalinks** and clicking Save always flushes rewrite rules for the whole site, which also picks up this module's rule as long as the module is currently enabled.

No code changes were needed here — `maybe_flush()` (added in an earlier task) already covers activation correctly; this section only documents the existing behavior.

---

## 4. Shortcodes

| Shortcode | Attributes | Notes |
|---|---|---|
| `[anchor_page_content]` | `id` (int, default: current post) | Renders a location/service page's `al_html`/`al_css`/`al_js` body. Useful inside a wrapper template, or to embed one page's content on another. |
| `[anchor_breadcrumbs]` | `id` (int, default: current post) | Home → ancestor chain → (for service pages) linked location chain → current title. Skips unpublished ancestors. |
| `[anchor_child_locations]` | `id` (int, default: [resolved location](#location-resolution)) | `<ul>` of the location's direct published children. |
| `[anchor_location_parent]` | `id` (int, default: [resolved location](#location-resolution)) | A link to the location's parent, if published; empty otherwise. |
| `[anchor_nearby_locations]` | `id` (int, default: [resolved location](#location-resolution)) | `<ul>` of up to 12 published sibling locations (same parent). |
| `[anchor_location_services]` | `id` (int, default: [resolved location](#location-resolution)) | `<ul>` of all published service pages linked to this location (via `al_location_id`). |
| `[anchor_service_locations]` | `id` (int, default: current post) | `<ul>` of other published service pages sharing this page's `service` term (other locations offering the same service). |
| `[anchor_service_area_directory]` | none | Renders the full published location hierarchy as nested `<ul>`s, starting from top-level (parent-less) locations. |
| `[anchor_local_faqs]` | `id` (int, default: current post) | Renders this page's FAQ content section (the `al_faq_html` field, authored via the "Content Sections" metabox). `id` targets another page's section instead. Empty if the field is blank. |
| `[anchor_local_testimonials]` | `id` (int, default: current post) | Renders this page's Testimonials content section (`al_testimonials_html`). Same `id` behavior. |
| `[anchor_local_projects]` | `id` (int, default: current post) | Renders this page's Projects content section (`al_projects_html`). Same `id` behavior. |
| `[anchor_location_map]` | `types` (comma-separated `al_type` values, default: all), `parent` (location post ID — restrict to its children, default: none), `zoom` (int, default: settings `map_zoom` / 8), `height` (px, default: `480`), `center` (`lat,lng`, default: settings `map_center` or first marker), `service` (service term slug or id — server-side pre-filter to locations that have a matching service page, default: none), `cluster` (`1`/`true` to group nearby pins via the MarkerClusterer library, loaded from CDN only when requested; default off), `filters` (comma-separated subset of `service,type` — renders front-end filter controls for those facets; default none), `focus` (which location the viewport frames — default: the current page's location on a singular location/service page; `none` frames all markers instead; a numeric ID targets a specific location, e.g. for a homepage overview map), `iconsize` (max custom-pin dimension in px, aspect-preserving; default `40`) | Renders a Google Map with a pin per matching location that has coordinates; each pin's info window links to the location and lists its linked service pages, and any location with a valid `al_boundary` also draws its boundary polygon. On a location/service page the map opens framed on that page's area (its boundary when set, otherwise centered on it at a type-derived zoom) rather than on the whole marker set. Requires a Google Maps API key set in the main Anchor Tools settings (`Anchor_Schema_Admin::OPTION_KEY['google_api_key']`). |

<a name="location-resolution"></a>

### Location resolution on service pages

The four shortcodes above are about a **location**, not the page they sit on. When no `id` attribute is given they resolve their subject as follows:

1. An explicit `id` attribute always wins.
2. Otherwise, if they are running on an **`anchor_service_page`**, they resolve to that page's linked location (`al_location_id`) — so `[anchor_location_services]` on `/services/roofing/pittsburgh-pa/` lists Pittsburgh's *other* services ("Other Services in Pittsburgh") rather than querying for the service page's own ID.
3. Otherwise they use the current post (the normal location-page case).

If a service page's `al_location_id` is missing, or points at a post that no longer exists or is not a location, these shortcodes render **empty** rather than emitting a broken self-link.

Note that `[anchor_service_locations]` is deliberately excluded: it is about the *service*, so it correctly keys off the current service page. `[anchor_breadcrumbs]` handles the service-page branch itself, and `[anchor_service_area_directory]` is global.

All the internal-linking shortcodes' output HTML is filterable:
`anchor_locations_child_locations_html`, `anchor_locations_location_parent_html`, `anchor_locations_nearby_locations_html`, `anchor_locations_breadcrumbs_html`, `anchor_locations_location_services_html`, `anchor_locations_service_locations_html`, `anchor_locations_service_area_directory_html` — each filter receives `( $html, $id )`.

### Global wrapper template

If `wrapper_html` is set (Settings > Anchor Tools > Locations) and a page hasn't set `al_disable_wrapper`, every location/service page's rendered body is substituted into the wrapper wherever `{{content}}` or a literal `[anchor_page_content]` appears, then the whole thing runs through `do_shortcode()`. `wrapper_css`/`wrapper_js` are emitted alongside it. This lets an operator define one global "hero + CTA + body + footer" shell without repeating it on every page.

### Placing content anywhere (shortcodes)

The wrapper is optional. Because the page body and every related element are exposed as shortcodes, they run anywhere WordPress processes shortcodes — a page-builder module, a widget, or a template — so the content can be injected wherever you want it:

- `[anchor_page_content]` with no `id` renders the *current* page's `al_html`/`al_css`/`al_js` and **skips the global wrapper**, so the surrounding layout supplies the chrome. Drop it wherever the page body should go, and scatter the other shortcodes (`[anchor_breadcrumbs]`, `[anchor_location_map]`, `[anchor_local_faqs]`, …) wherever they belong.
- Leave `wrapper_html` blank, or tick **al_disable_wrapper** ("Disable global wrapper on this page") per page, when the layout already provides the chrome.
- **Don't render the body twice:** if the layout already outputs `the_content` (e.g. a "post content" element in a builder or theme template), that alone shows the body plus wrapper — adding `[anchor_page_content]` in the same layout renders it again. Use one or the other.

The full shortcode reference is also printed on the **Settings > Anchor Tools > Locations** tab for in-admin access.

---

## 5. WP-CLI population examples

These match the exact shipped meta keys above. Run them over SSH with WP-CLI (`wp` in the site's document root, or `wp --path=...`).

### Create a county hub

```bash
wp post create --post_type=anchor_location --post_status=publish \
  --post_title="Allegheny County" --post_name="allegheny-county-pa" --porcelain
# -> prints the new post ID; capture it as $COUNTY_ID

wp post meta set $COUNTY_ID al_type county
wp post meta set $COUNTY_ID al_state_abbr PA
wp post meta set $COUNTY_ID al_county "Allegheny"
wp post meta set $COUNTY_ID al_lat 40.46
wp post meta set $COUNTY_ID al_lng -79.98
wp post meta set $COUNTY_ID al_html '<h1>Allegheny County, PA</h1><p>We serve every city and township in the county.</p>[anchor_child_locations]'
```

### Create a city under it

```bash
wp post create --post_type=anchor_location --post_status=publish \
  --post_title="Pittsburgh" --post_name="pittsburgh-pa" --post_parent=$COUNTY_ID --porcelain
# -> $CITY_ID

wp post meta set $CITY_ID al_type city
wp post meta set $CITY_ID al_state_abbr PA
wp post meta set $CITY_ID al_lat 40.44
wp post meta set $CITY_ID al_lng -79.99
wp post meta set $CITY_ID al_place_id "ChIJmzw9AVMCyIkR-cRDUcXGSbc"
wp post meta set $CITY_ID al_html '<h1>Pittsburgh, PA</h1>[anchor_breadcrumbs]<p>Local service info here.</p>[anchor_location_services]'
```
URL once rewrite rules are current: `/service-areas/allegheny-county-pa/pittsburgh-pa/`

### Create a service page: Roofing in Pittsburgh

```bash
wp post create --post_type=anchor_service_page --post_status=publish \
  --post_title="Roofing in Pittsburgh" --post_name="roofing-pittsburgh-pa" --porcelain
# -> $SP_ID

wp post term set $SP_ID service roofing
wp post meta set $SP_ID al_location_id $CITY_ID
wp post meta set $SP_ID al_html '<h1>Roofing in Pittsburgh, PA</h1>[anchor_breadcrumbs][anchor_location_map parent="'$COUNTY_ID'" height="360"]<p>Content about roofing services in Pittsburgh.</p>[anchor_service_locations]'
```
URL: `/services/roofing/pittsburgh-pa/` (note: this reads from the **location's** `post_name`, i.e. `pittsburgh-pa`, not the service page's own slug).

If `/services/roofing/pittsburgh-pa/` 404s immediately after creating the first location/service page (e.g. right after enabling the module), it means rewrite rules haven't flushed yet on this WP install — see §3's activation note. Running `wp rewrite flush` once resolves it.

### Bulk / spot-checks

```bash
# List all locations with type + coords
wp post list --post_type=anchor_location --fields=ID,post_title,post_name \
  --format=table
wp post meta list <ID> --keys=al_type,al_lat,al_lng

# Find service pages missing a link (location or service term)
wp post list --post_type=anchor_service_page --format=ids | while read id; do
  wp post meta get "$id" al_location_id >/dev/null 2>&1 || echo "$id missing al_location_id"
done

# Force a rewrite flush after bulk-creating content
wp rewrite flush
```

---

## 6. Content sections

Each location and service page has three optional, free-form content sections —
FAQ, Testimonials, Projects — authored directly on the page itself, no separate
library CPTs involved. Implemented in `class-sections.php`
(`\Anchor\Locations\Sections`), which adds a single "Content Sections (FAQ /
Testimonials / Projects)" metabox to both `anchor_location` and
`anchor_service_page` edit screens.

| Meta key | Shortcode | Purpose |
|---|---|---|
| `al_faq_html` | `[anchor_local_faqs]` | Free-form HTML authored via a Monaco HTML editor. |
| `al_testimonials_html` | `[anchor_local_testimonials]` | Same. |
| `al_projects_html` | `[anchor_local_projects]` | Same. |

Each shortcode takes a single optional `id` attribute (int, default: the current
page) and renders that page's section HTML — wrapped in a `<div class="al-faqs">`
/ `al-testimonials` / `al-projects"` container and passed through `do_shortcode()`
so nested shortcodes still work — or nothing at all when the field is empty. A
per-page/per-section recursion guard prevents an `id`-referencing loop from
rendering infinitely.

**No FAQPage or Review/AggregateRating JSON-LD is emitted for these sections.**
The content is plain HTML you author (which can itself include your SEO plugin's
own schema shortcode/block, if it has one) — structured data for FAQs and reviews
is the site's SEO plugin's job, not this module's.

### WP-CLI example

```bash
wp post meta set $CITY_ID al_faq_html '<h2>FAQs</h2><p><strong>How much does roofing cost?</strong> Most roofs run $8k–$20k depending on size.</p>'
wp post meta set $CITY_ID al_testimonials_html '<h2>What our customers say</h2><blockquote>"Fast, fair, and did great work." — J. Smith</blockquote>'
```

---

## 7. Admin pages

All of these live as submenus under the **Anchor Locations** menu
(`edit.php?post_type=anchor_location`). They are reporting/operator tools — none
of them generate or bulk-create page content.

| Screen | Slug | Capability | What it does |
|---|---|---|---|
| **Coverage** (Coverage Matrix) | `anchor-locations-coverage` | `edit_posts` | Read-only P5 matrix of every `service` term × location. Each cell shows the page's state — Published / Noindex / Draft / **Missing** — with a quality score, and View/Edit links. A **Missing** cell links to the standard *Add New Service Page* screen with the service term + `al_location_id` pre-filled (via `?al_prefill_location=`); nothing is written until a human saves. Filterable by `al_type`. |
| **Import / Export** | `anchor-locations-io` | `manage_options` | P6 portability. **Export**: full JSON migration envelope (settings, `service` taxonomy, locations with hierarchy, and service pages — all referenced by slug), or scalar CSV of locations / service pages (code fields, content sections, and boundary GeoJSON are JSON-only). **Import**: upload a `.json` or `.csv` and upsert **by slug** — it never fabricates combinations and never deletes. A **Preview (dry run)** checkbox reports created/updated/skipped counts (and per-row errors) without writing. |

### Locations list — Health column & versioned caching (P8)

- The **Locations** list table gains a **Health** column showing a ⚠ marker when a
  published location's slug collides with another published location (both would
  collapse to the same `/services/…/{slug}/` URL). The same data-integrity checks
  (slug collision, missing coordinates, orphan / duplicate service page) surface as
  dismissible `notice-warning`s on the edit screen. These are non-blocking **nudges**
  only — they never rewrite a slug or mutate content.
- The expensive `[anchor_location_map]` and `[anchor_service_area_directory]`
  relationship queries are cached in transients keyed by a **monotonic cache
  version** (the `anchor_locations_cache_ver` option, `autoload=false`). Any write
  that could change the relationship graph — a location/service save, trash/delete,
  `service` term edit, term assignment, or an `al_*` meta write (covers WP-CLI /
  direct import) — bumps that version, invalidating every cached entry at once
  without enumerating keys. When the option is absent the version reads 0 and callers
  bypass the cache entirely (behaviour identical to pre-P8). A bulk JSON/CSV import
  suspends the per-write bumps and invalidates **exactly once** at the end.

