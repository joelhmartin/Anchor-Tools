# Plan — Anchor Locations P3: Advanced Maps

Spec: `docs/superpowers/specs/2026-07-18-anchor-locations-p3-maps-design.md`
Branch: `feature/anchor-locations-p3-maps`

## Task 1 — PHP: map_data enhancements (TDD)

Write failing tests in `tests/test-locations-settings.php` (class `LocationsSettingsTest`):

- `test_map_data_service_filter_includes_only_matching_service_pages`:
  location A has a published `anchor_service_page` linked via `al_location_id` with a
  `service` term `roofing`; location B has no roofing page. `map_data(['service'=>'roofing'])`
  → contains A, not B. Each returned service entry carries `service` => slug.
- `test_map_data_markers_include_type`: every marker has a `type` key equal to `al_type`.
- `test_map_data_includes_valid_boundary_and_skips_invalid`:
  location with valid `al_boundary` JSON → `boundary` key === decoded value;
  location with invalid `al_boundary` (e.g. `not json{`) → no `boundary` key, no fatal.

Then implement in `map_data()`:
- Add `$service = $args['service']` handling → resolve numeric id to slug via `get_term`.
- When collecting services per location, gather each page's `service` term slugs; set
  `service` on each service entry (first slug). Track whether any page matches `$service`.
- Skip the location when `$service` set and no linked page matches.
- Add `'type' => $type` to marker.
- Add `'boundary' => decoded` only when `json_decode(al_boundary)` is non-null.

Run: `composer test -- --filter LocationsSettings` → green.
Commit: `feat(locations): map_data service filter, type + boundary keys`.

## Task 2 — PHP: sc_map attributes + clustering enqueue

- `sc_map`: add atts `cluster=''`, `service=''`, `filters=''`.
  - pass `service` to `$args`; pass `filters` (parsed comma list, whitelisted to
    `service`,`type`) into `$cfg['filters']`; pass `cluster` bool into `$cfg['cluster']`.
- `enqueue_map_assets( $cluster = false )`: when `$cluster`, also
  `wp_enqueue_script('anchor-locations-markerclusterer', jsdelivr UMD, [], version, true)`
  and add it as a dep of the frontend script. Keep the `$assets_enqueued` guard but ensure
  clustering lib enqueues even if a prior non-cluster map already enqueued base assets
  (track a separate `$cluster_enqueued` guard).
- Bump frontend.js filemtime naturally (edited in Task 3).

Commit: `feat(locations): sc_map cluster/service/filters attrs + clusterer enqueue`.

## Task 3 — JS: clustering, boundaries, filter UI

Rewrite `frontend.js` (keep `esc`/`escUrl`):
- Build markers into an array; keep marker->data (type, service slugs) for filtering.
- Boundaries: per-map `google.maps.Data` layer, add each marker's `boundary`
  (normalize to FeatureCollection), style subtle, hover darken, click → navigate to url
  (validated). Wrap in try/catch so bad geojson never breaks the map.
- Clustering: if `cfg.cluster` && `markerClusterer.MarkerClusterer` → cluster; else direct.
- Filter panel: when `cfg.filters` non-empty, build accessible checkbox panel; toggling
  recomputes visible set, updates marker.map / clusterer.
- `node --check anchor-locations/assets/frontend.js`.

Add minimal CSS to `frontend.css` for `.al-map-filters`.

Commit: `feat(locations): clustering, boundary polygons, client filter UI`.

## Task 4 — Verify & report

- `composer test -- --filter Locations` all green.
- `node --check` clean.
- Write `.superpowers/sdd/p3-report.md`.

Commit any docs: `docs(locations): P3 advanced maps spec + plan`.
