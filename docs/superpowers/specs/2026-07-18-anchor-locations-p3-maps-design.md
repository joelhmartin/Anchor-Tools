# Anchor Locations — Phase 3: Advanced Maps (Design Spec)

Date: 2026-07-18
Branch: `feature/anchor-locations-p3-maps`

## Goal

Enhance the existing `[anchor_location_map]` Google map (Phase 1) with three
capabilities, without regressing the current marker + info-popup behavior or the
XSS escaping in `frontend.js`:

1. Marker clustering (opt-in).
2. County/area boundary polygons (from already-saved `al_boundary` GeoJSON).
3. Service + type filtering (server pre-filter by service; client-side show/hide UI).

## Current state (Phase 1)

- `map_data(array $args): array` — returns markers
  `['id','title','url','lat','lng','icon','services'=>[['title','url']]]`.
  Filters by `types` (array of `al_type`) and `parent` (post_parent).
- `sc_map($atts)` — atts `types,parent,zoom,height,center`. Emits
  `<div class="al-map" data-al-map='{json}'>` and enqueues assets via
  `enqueue_map_assets()` (guarded by `$assets_enqueued`).
- `frontend.js` — parses `data-al-map`, builds markers, InfoWindow with
  `esc()`/`escUrl()` escaping, `fitBounds`.
- `al_boundary` meta (GeoJSON string) is SAVED per location but unused.

## Phase 3 design

### 1. Marker clustering

- New `sc_map` att `cluster="true"` (falsy by default). Passed into `$cfg['cluster']` (bool).
- When clustering requested, enqueue `@googlemaps/markerclusterer` UMD build from jsDelivr
  (pin exact version), as a dep-less script; only enqueued when `cluster` is on.
- `frontend.js`: when `cfg.cluster` AND `window.markerClusterer?.MarkerClusterer` present,
  construct `new markerClusterer.MarkerClusterer({ map, markers })`; else add markers
  directly to the map (current behavior). Graceful degradation: if the library failed to
  load, fall back to plain markers — no thrown error.

### 2. Boundary polygons

- `map_data()`: read `al_boundary`; `json_decode` it; only when it decodes to a non-null
  value (valid JSON) attach it as `boundary` on the marker object. Invalid JSON → skip the
  key entirely (no `boundary`), never fatal.
- `frontend.js`: for each marker with a `boundary`, feed it to a per-map
  `google.maps.Data` layer via `data.addGeoJson()` (wrapping bare geometry/Feature into a
  FeatureCollection as needed), styled with a subtle fill + border. On `mouseover` darken the
  fill (hover highlight), on `mouseout` restore, on `click` navigate to that location's hub
  `url`. Absent/failed boundaries must not break the map or the markers.
- The GeoJSON is attached with the location's `url` as a feature property so the click
  handler can navigate; the URL is validated client-side before assignment to `location.href`.

### 3. Service filter (server)

- `map_data()` accepts a `service` arg (term slug OR term id). When set:
  - Resolve to a term slug (numeric → `get_term` slug).
  - Include ONLY locations that have ≥1 published `anchor_service_page` linked via
    `al_location_id` whose `service` taxonomy terms include that slug.
- Each service entry gains its term slug:
  `services => [ ['title','url','service'=>term_slug] ]` (first service term slug of the page;
  empty string if none). This lets the client filter markers by service.
- `sc_map` gains `service=""` att, passed through to `map_data`.

### 4. Type on markers + client filter UI

- `map_data()` adds `'type' => al_type` to every marker.
- `sc_map` gains `filters="service,type"` att (comma list; allowed tokens `service`,`type`).
  Passed to `$cfg['filters']` (array). When non-empty, `frontend.js` renders an accessible
  control panel above the map:
  - `service` → a checkbox per distinct service slug found across markers.
  - `type` → a checkbox per distinct location type found across markers.
  - Real `<label for>` elements, keyboard-usable native checkboxes.
  - Toggling recomputes visible markers: a marker is visible when (no service filter active OR
    it has ≥1 checked service slug) AND (no type filter active OR its type is checked).
  - Hidden markers are removed from the map (and from the clusterer, re-rendered) ; shown ones re-added.
- Filter labels are built from slugs/types with `esc()` — a crafted term slug or post title
  must not inject markup.

## New `map_data` marker keys

`type` (string, always), `boundary` (decoded GeoJSON, only when valid), and
`services[].service` (term slug string).

## New `sc_map` attributes

`cluster` (bool-ish), `service` (slug/id), `filters` (comma list of `service`,`type`).

## CDN library

`@googlemaps/markerclusterer` UMD from jsDelivr, exact-pinned version. Loaded via
`wp_enqueue_script` only when clustering is requested (mirrors the Monaco jsDelivr approach;
not vendored because `*.min.js` is gitignored).

## Testing

- PHP (TDD, `tests/test-locations-settings.php`):
  - `service` filter includes only locations with a matching published service page.
  - markers include `type`.
  - valid `al_boundary` → `boundary` key with decoded GeoJSON; invalid → no key, no fatal.
- JS: `node --check`; manual verification steps documented in the report.

## Non-goals

No admin UI changes to the boundary editor; no server-side rendering of the filter panel
markup beyond what `sc_map`/`frontend.js` emit; no new settings.
