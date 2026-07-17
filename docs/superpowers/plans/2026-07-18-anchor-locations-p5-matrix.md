# Plan — Anchor Locations Phase 5: Coverage Matrix + SEO Dashboard

Spec: docs/superpowers/specs/2026-07-18-anchor-locations-p5-matrix-design.md
Branch: feature/anchor-locations-p5-matrix

## Task 1 — Failing tests (TDD red)
`tests/test-locations-dashboard.php`, class `LocationsDashboardTest`:
- `test_coverage_matrix_statuses` — loc + terms roofing/gutters/siding; published
  roofing page, draft gutters page, no siding → published/draft/missing; missing
  cell `add` url carries prefill args.
- `test_coverage_matrix_noindex` — published page w/ `al_robots_noindex=1` → noindex.
- `test_quality_score_high_vs_low` — fully-populated page == 100; empty draft ≤ 10.
- `test_seo_issues_detects` — thin page (High), missing-coords location (High),
  orphan service page (High), duplicate combo (High).
- `test_builders_create_no_content` — post counts unchanged after building.
Run filtered → red (class/methods absent).

## Task 2 — Dashboard class (green)
`anchor-locations/class-dashboard.php` `\Anchor\Locations\Dashboard`:
- `__construct`: `admin_menu` → two `add_submenu_page`; `wp_terms_checklist_args`
  filter for service-term pre-fill.
- `coverage_matrix(array $args=[]): array` — pure builder.
- `quality_score(int $post_id): int` — pure, documented weights.
- `seo_issues(): array` — pure, grouped by severity.
- `render_coverage_page()` / `render_seo_page()` — cap check, escaped output,
  sanitized `?al_type=` filter.
- URL helpers: `edit_url`, `add_url` (admin_url based, no cap dependency so builders
  are testable headless).
Wire into `Module::__construct` (require_once + new). Prefill default in
`Module::render_details_metabox` (Linked Location field). `php -l`.

## Task 3 — Verify + report
`composer test -- --filter LocationsDashboard` green; `--filter Locations` all green
(no regressions). Write `.superpowers/sdd/p5-report.md`. Commit in logical chunks.
