# Anchor Locations — Phase 8 (Hardening) — Plan

Spec: `docs/superpowers/specs/2026-07-18-anchor-locations-p8-hardening-design.md`
Branch: `feature/anchor-locations-p8-hardening`

## Task 1 — Integrity class + safeguards (TDD)

1. Write failing `tests/test-locations-integrity.php` for the pure predicates:
   `location_slug_collision()` (other published id; 0 when unique; ignores drafts
   and self), `service_duplicate_combo()` (flags 2nd page same term+location; 0
   when unique), `service_orphan()`, `location_missing_coords()`.
2. Create `anchor-locations/class-integrity.php` (`\Anchor\Locations\Integrity`):
   - constructor registers `admin_notices`, the Locations "Health" list column,
     and the cache-invalidation hooks.
   - pure predicates above.
   - `edit_screen_notices()` — escaped, `edit_post`-gated warnings for slug
     collision / missing coords (location) and orphan / duplicate combo (service).
   - `add_health_column()` / `render_health_column()` — ⚠ on collision.
   - `cache_version()` / `bump_cache_version()` + the bump hook handlers
     (`on_saved_post` scoped via `save_post_{cpt}`, `on_deleted_post`,
     `on_edited_term`, `on_set_object_terms`).
3. Wire `require_once` + `new Integrity()` into `Module::__construct`.
4. Run `--filter LocationsIntegrity` → predicate tests green.
5. Commit: `feat(locations): P8 data-integrity safeguards (Integrity class)`.

## Task 2 — Versioned query caching (TDD)

1. Extend the test with cache-correctness cases for `map_data()` and
   `sc_directory` (identical results, poisoned-transient read, save_post
   invalidation).
2. In `Module`: wrap `map_data()` body + `sc_directory` tree build in a private
   `cached()` helper keyed by `Integrity::cache_version()`; add public
   `map_cache_key()` / `directory_cache_key()`. Bypass when version `<= 0`.
3. Run `--filter LocationsIntegrity` → all green.
4. Commit: `perf(locations): P8 versioned transient caching for map_data + directory`.

## Task 3 — Regression + report

1. `composer test -- --filter Locations` → all `Locations*` green.
2. `php -l` the new files.
3. Write `.superpowers/sdd/p8-report.md`.
4. Commit docs/report.

## Risks

- Caching must not serve stale results within a request. Mitigation: versioned
  keys + bump on every mutation hook; TTL is a backstop only.
- Multiple `Integrity` instances (bootstrap + test `new`) register duplicate bump
  hooks → version increments by >1 per save. Harmless: monotonic, still busts the
  key; tests reconstruct the key from the *current* version.
