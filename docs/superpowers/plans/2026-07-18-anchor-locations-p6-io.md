# Plan — Anchor Locations Phase 6: Import / Export

Spec: `docs/superpowers/specs/2026-07-18-anchor-locations-p6-io-design.md`
Branch: `feature/anchor-locations-p6-io`. TDD, commit in logical chunks.

## Task 1 — Failing tests (tests/test-locations-io.php, LocationsIoTest)
Mirror LocationsDashboardTest helpers. Cases:
1. `test_round_trip_reconstructs_hierarchy_and_links` — county→city + service term
   + service page (linked loc + term) + testimonial (al_location_ids=[city]);
   `export_json()`; wipe/fresh; `import_json()`; assert city.parent==county,
   service page links to city + roofing term, testimonial al_location_ids resolves
   to the city id, meta round-trips.
2. `test_import_is_idempotent` — import twice; post counts stable; 2nd run
   created==0, updated>0.
3. `test_dry_run_writes_nothing` — dry_run=true leaves counts unchanged, returns
   accurate created count.
4. `test_csv_export_escapes_formula_injection` — location title `=SUM(A1)` emitted
   with leading `'`.
5. `test_import_never_deletes` — pre-existing location omitted from JSON survives import.
6. `test_bad_service_page_row_recorded_not_fatal` — service_page with unknown
   location_slug → errors[], others still import.
7. CSV round-trip: export locations CSV, import into fresh, assert scalar upsert.

## Task 2 — Implement class-io.php (\Anchor\Locations\IO)
- Constants: meta-key group arrays; ENVELOPE format/version.
- `export_json(): array` — build envelope with slug refs.
- `export_locations_csv(): string`, `export_service_pages_csv(): string` — with
  formula-injection guard (`csv_cell()` helper).
- `import_json(array,$opts): array` — validate envelope; ordered upsert; dry-run;
  per-row guard; summary.
- `import_csv(string,$opts): array` — header detect + scalar upsert.
- Helpers: `upsert_post()`, `sanitize_meta_value()`, slug↔id maps.
- Wire into Module::__construct (require_once + new IO()).

## Task 3 — Admin UI + handlers
- `register_pages()` on admin_menu → submenu "Import / Export", manage_options.
- `admin_post_anchor_locations_export` — nonce, cap, stream file.
- `admin_post_anchor_locations_import` — nonce, cap, validate upload, run, summary.
- Render page: export buttons + import upload + dry-run checkbox + result.

## Task 4 — Verify + report
- `php -l` new file. Filtered PHPUnit `--filter LocationsIo`, then `--filter Locations`.
- Report to `.superpowers/sdd/p6-report.md`.
