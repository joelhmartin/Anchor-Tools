# Anchor Locations — Phase 5: Coverage Matrix + SEO Quality Dashboard

## Goal
Give operators a **read-only** admin view of their service×location coverage and
SEO health, and a friction-free way to jump to the standard "Add New Service
Page" screen for missing combinations — **without the plugin ever creating,
mutating, or bulk-generating content**.

## Hard design constraint (owner directive)
The plugin owner populates pages via external connections / WP-CLI. Phase 5 is
therefore **strictly reporting + navigation**:
- No page generation, no cron, no bulk create/publish, no "Generate all" button.
- A **Missing** cell links to the normal `post-new.php?post_type=anchor_service_page`
  screen with `al_prefill_location` + `al_prefill_service` query args so the human
  (or their AI/WP-CLI flow) creates and Publishes the page through WordPress's own
  flow. The plugin only *pre-fills the form*; it never writes a post.
- Every data path is read-only: building the matrix / issue list creates zero
  posts (asserted by a unit test comparing post counts before/after).

## Deliverables
`anchor-locations/class-dashboard.php` — class `\Anchor\Locations\Dashboard`,
instantiated from `Module::__construct` (`require_once` + `new Dashboard()`).

### Admin pages (submenus under `edit.php?post_type=anchor_location`)
- **Coverage** — the service×location matrix.
- **SEO Reports** — issues grouped by severity.

Both registered via `add_submenu_page`, capability `edit_posts` (read-only view),
all output escaped, all GET filters sanitized/validated.

### A. Coverage matrix
Rows = published `anchor_location` posts (optional `?al_type=` filter), columns =
`service` taxonomy terms. Cell status per service×location:
- **published** — a published `anchor_service_page` linked via `al_location_id` and
  tagged that term, `al_robots_noindex` not set. Links to front-end URL + Edit.
- **noindex** — as published but `al_robots_noindex='1'`. Flagged.
- **draft** — a draft service page exists. Links to Edit.
- **missing** — no page. Links to the pre-filled Add New screen.

Pure builder `coverage_matrix(array $args = []): array` returns
`[ location_id => [ term_id => ['status','page_id','edit','view','add','score'] ] ]`.
Cell status precedence: published > noindex > draft > missing.

### B. Pre-fill support (no creation)
When the service-page editor loads a **new** post with `al_prefill_location` /
`al_prefill_service` query args:
- `Module::render_details_metabox` defaults the "Linked Location" field to the
  (int-validated) location id when no value is saved yet.
- Dashboard filters `wp_terms_checklist_args` to pre-check the `service` term in
  the Services box for new service posts (read-side default; no term is written
  until the human saves).

### C. SEO quality dashboard — issue types + severity
| Issue | Severity | Detection |
|-------|----------|-----------|
| Thin content | High | `al_html` stripped text < 300 chars |
| Location missing coordinates | High | location `al_lat`/`al_lng` empty |
| Orphan service page | High | `al_location_id` missing / non-published / nonexistent |
| Duplicate combination | High | two service pages, same `service` term + `al_location_id` |
| Missing SEO title | Medium | `al_seo_title` empty |
| Missing meta description | Medium | `al_seo_desc` empty |
| Missing H1 | Medium | `al_h1` empty AND no `<h1` in `al_html` |
| Coverage gaps | Low | missing service×location combos (from the matrix) |
| Noindex pages | Low | `al_robots_noindex='1'` |
| Sitemap-excluded pages | Low | `al_sitemap_exclude='1'` |

Pure builder `seo_issues(): array` → `['high'=>[...],'medium'=>[...],'low'=>[...]]`,
each entry `['type','label','posts'=>[['id','title','edit'?,'add'?], ...]]`.
Only issue types with ≥1 affected item are emitted.

### D. Content quality score — `quality_score(int $post_id): int` (0–100)
Additive, documented weights:
| Component | Weight |
|-----------|--------|
| Body length (`al_html` stripped) ≥ 300 chars | 20 |
| Has `al_seo_title` | 15 |
| Has `al_seo_desc` | 15 |
| Coords present (location) / linked location has coords (service page) | 15 |
| `al_html` contains ≥1 internal-linking shortcode | 15 |
| Not noindex (`al_robots_noindex` ≠ '1') | 10 |
| Has H1 (`al_h1` set OR `<h1` in `al_html`) | 10 |
Internal-linking shortcodes counted: `anchor_location_services`,
`anchor_nearby_locations`, `anchor_breadcrumbs`, `anchor_service_locations`,
`anchor_child_locations`, `anchor_location_parent`, `anchor_location_map`,
`anchor_service_area_directory`, `anchor_local_projects`,
`anchor_local_testimonials`, `anchor_local_faqs`, `anchor_h1`.
A fully-populated page scores 100; an empty draft scores ≤ 10.

## Conventions
Namespace `\Anchor\Locations\`; text domain `anchor-schema`; meta prefix `al_`;
`current_user_can('edit_posts')` on pages; escape all output; sanitize all GET
filters. No nonces needed (read-only, no mutations). No content creation anywhere.

## Testing
`tests/test-locations-dashboard.php` (`LocationsDashboardTest`): matrix status
resolution (published/draft/missing/noindex), quality score high vs low, seo_issues
detects thin/missing-coords/orphan/duplicate, and post-count-unchanged invariant.
