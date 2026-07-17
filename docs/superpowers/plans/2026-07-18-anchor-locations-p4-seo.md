# Anchor Locations — Phase 4: SEO Controls + Schema (Plan)

TDD, commit in logical chunks. New test file `tests/test-locations-seo.php` (`LocationsSeoTest`).

## Task 1 — SEO meta save/sanitize + metabox (`class-seo.php`)
- Write failing tests: save/sanitize round-trip for all 11 keys; nonce rejection.
- Implement `SEO` class: constructor hooks, `add_seo_metabox`, `render_seo_metabox`, `save_seo_meta`.
- Instantiate from `Module::__construct` (`require_once` + `new SEO()`).
- Commit: `feat(locations): per-page SEO meta fields + metabox`.

## Task 2 — Robots + own-output + SEO-plugin feed filters
- Tests: `filter_robots` adds noindex when flag set (and not otherwise); `own_document_title` returns
  `al_seo_title` with no plugin; `print_head_meta` output contains canonical/description when set; Yoast/RankMath
  callbacks return our value when set, passthrough otherwise.
- Implement `active_seo_plugin()`, `filter_robots`, `own_document_title`, `print_head_meta`, Yoast + RankMath callbacks.
- Commit: `feat(locations): robots + title/meta output with SEO-plugin integration`.

## Task 3 — Sitemap exclusion
- Test: `filter_sitemap_query` excludes a flagged post for both CPTs.
- Implement core filter + Yoast best-effort filter.
- Commit: `feat(locations): sitemap exclusion (core + Yoast)`.

## Task 4 — `al_h1` shortcode + `al_breadcrumb_title` in breadcrumbs/schema
- Tests: `[anchor_h1]` uses meta then title; breadcrumb + schema use `al_breadcrumb_title`.
- Implement `sc_h1`, Module `crumb_label`, wire into `sc_breadcrumbs` + `build_schema`.
- Commit: `feat(locations): al_h1 shortcode + breadcrumb_title override`.

## Task 5 — Review / AggregateRating schema (`class-libraries.php`)
- Tests: rated testimonials → footer has Review + AggregateRating; unrated/none → absent.
- Implement collector + `print_review_schema`.
- Commit: `feat(locations): Review + AggregateRating schema from testimonials`.

## Task 6 — Wire `fullwidth_template`
- Test: `template_include` returns plugin template path when setting on + singular; passthrough otherwise.
- Implement filter + `templates/single-anchor-fullwidth.php`.
- Update README + settings-tab description.
- Commit: `feat(locations): wire fullwidth single template setting`.

## Verify
`composer test -- --filter LocationsSeo` then `--filter Locations` (all green). `php -l` new files.
