# Anchor Locations — Phase 8 (Hardening) — Design

## Goal

Final phase of the `anchor-locations` module: add **data-integrity safeguards**
and **relationship-query caching**. No new user-facing features, no custom roles.
The plugin owner drives content via external tooling (WP-CLI / import), so every
safeguard is a *nudge* (dismissible admin notice + list marker), never a hard
block or an auto-mutation.

## Scope

New file `anchor-locations/class-integrity.php`, class `\Anchor\Locations\Integrity`,
instantiated from `Module::__construct`. Cache read/write wraps two existing hot
paths inside `Module` (`map_data()` + `sc_directory()`); the cache *version* lives
on `Integrity` so the invalidation hooks and the safeguards share one class.

### A. Slug-uniqueness validation (the real Phase-1 routing risk)

Two published `anchor_location` posts with the same `post_name` collapse to the
same `/services/{svc}/{slug}/` URL; `find_service_page()` resolves one arbitrarily
(it queries the location by `post_name` with `posts_per_page => 1`). External
tooling / direct DB writes can create such a collision because `wp_unique_post_slug`
only de-dupes within one insert path.

- `Integrity::location_slug_collision( int $post_id ): int` — returns the ID of
  **another published** `anchor_location` sharing this post's `post_name`, else `0`.
  Ignores drafts, ignores the post itself, ignores empty slugs.
- Location edit screen: a `notice notice-warning` naming the other location, with
  an edit link. Warning only — saving is never blocked, slugs are never rewritten.
- Locations list table: a ⚠ marker in a dedicated "Health" column when the row's
  slug collides.

### B. Data-quality admin notices (nudge, don't block)

On the CPT edit screens, dismissible-per-load `notice notice-warning`s, escaped,
capability-gated (`edit_post`):

- `anchor_location` with no `al_lat`/`al_lng` → "won't appear on the map".
- `anchor_service_page` whose `al_location_id` is missing / invalid / points at a
  non-published location → orphan, "won't route".
- `anchor_service_page` that duplicates an existing (service term + location)
  combination → mirrors the Dashboard's duplicate detection, but scoped to one
  post via a focused query (`Integrity::service_duplicate_combo()`).

Pure predicates back each notice so they can be unit-tested headless:
`location_missing_coords()`, `service_orphan()`, `service_duplicate_combo()`.

### C. Relationship-query caching (performance)

Versioned invalidation — never enumerate cache keys:

- Cache version in option `anchor_locations_cache_ver` (int, `autoload=false`).
  `Integrity::cache_version()` returns `0` when the option is absent (→ cache
  bypassed cleanly). `Integrity::bump_cache_version()` increments it (creating it
  at `1` on first bump).
- Bump hooks: `save_post_{location}` / `save_post_{service}`, `deleted_post` +
  `trashed_post` (post-type-guarded), `edited_term` + `set_object_terms`
  (taxonomy-guarded to `service`).
- `map_data($args)` → transient `al_mapdata_{ver}_{md5(json args)}`, TTL 12h.
- `sc_directory` rendered tree → transient `al_dir_{ver}_{md5(json atts)}`, TTL 12h.
  The `apply_filters()` still runs on every call (only the expensive tree build is
  cached), so dynamic filters keep working.
- **Behavior-preserving:** the returned shape of `map_data()` and the directory
  shortcode is unchanged. `map_data()` never returns `false` (array) and the
  directory never returns `false` (string), so `get_transient`'s `false`-miss
  sentinel is unambiguous. With the version option absent, both paths compute
  uncached exactly as before — the Phase-1/Phase-3 suites still pass.

Public helpers `Module::map_cache_key()` / `Module::directory_cache_key()` expose
the transient key so tests can assert cache population/read deterministically.

## Conventions

Namespace `\Anchor\Locations\`, text domain `anchor-schema`, meta prefix `al_`,
`autoload=false` on the cache-version option, all admin output escaped, `edit_post`
capability checks on the notices. No roles, no new front-end features.

## Testing

`tests/test-locations-integrity.php` (`LocationsIntegrityTest`): slug collision
(other id / 0 / ignores drafts + self), duplicate-combo detection, orphan +
missing-coords predicates, and cache correctness (identical results, served-from-
transient via a poisoned key, version-bump invalidation after `save_post`) for both
`map_data()` and the directory shortcode. Existing `--filter Locations` suites must
stay green (caching must not change results).
