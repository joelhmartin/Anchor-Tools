# Anchor Locations — Simplification (per-page content sections; remove SEO / SEO-Reports / Analytics)

**Date:** 2026-07-22
**Module:** `anchor-locations/`
**Status:** Design approved (see decisions below); proceeding to implementation plan.

## Context

Anchor Locations shipped in phases (P1 render/hierarchy, P2 content libraries, P4
SEO, P5 dashboard, P6 import/export, P7 analytics, P8 integrity). In practice the
owner finds several of those subsystems more friction than value:

- **Content libraries** (Projects/Testimonials/FAQs) are three separate CPTs with a
  relevance/specificity resolver and assignment custom fields. Surfacing the right
  content per page is fiddly; the owner would rather type each page's content
  directly on that page.
- **SEO** (per-page metabox, own title/meta/OG, robots, canonical, sitemap
  exclusion, `[anchor_h1]`) duplicates what the owner already does in Yoast / Rank
  Math.
- **SEO Reports** dashboard and **Analytics** (GSC/GA4) are not used.

This spec removes that friction while keeping the module's core (render + wrapper,
hierarchy, internal-linking shortcodes, the map, Place/Service/BreadcrumbList
JSON-LD, Coverage Matrix, Import/Export, Integrity caching).

## Decisions (locked)

1. **Content model:** Free-form **Monaco HTML** section per type, per page. The
   plugin **stops emitting** `FAQPage` and `Review`/`AggregateRating` JSON-LD; the
   owner handles any such schema in Rank Math.
2. **Existing library data:** **Drop it.** No migration; the three library CPTs and
   their posts are removed. (The owner has nothing worth preserving.)

## Goals

- Author FAQ / Testimonials / Projects content **directly on each location and
  service page**, in Monaco editors, placeable anywhere via shortcodes.
- Remove the SEO subsystem, SEO Reports, and Analytics.
- Keep the Coverage Matrix, Import/Export, the map, hierarchy/linking shortcodes,
  and core JSON-LD working.
- Leave the Locations admin menu clean: no library CPT sub-items, no SEO Reports,
  no Analytics.

## Non-goals / out of scope

- No migration of existing library content.
- No new schema generation (FAQ/Review) — deferred to Rank Math.
- No change to the map beyond what already shipped in 3.9.26.
- Gating our BreadcrumbList against Rank Math's is **noted as a risk**, not built
  here (revisit only if duplicate breadcrumbs are observed).

## Design

### 1. Per-page content sections — new `class-sections.php` (`\Anchor\Locations\Sections`)

Replaces `class-libraries.php` in the phases loop (`anchor-locations.php` phases
array). Responsibilities:

- **Metabox** "Content Sections" on both `Module::CPT_LOCATION` and
  `Module::CPT_SERVICE` (`add_meta_boxes`), rendered with the shared
  `Anchor_Monaco` multi-tab component — three **HTML** tabs: FAQ, Testimonials,
  Projects. Mirrors the existing "Content (HTML/CSS/JS)" metabox pattern.
- **Meta keys:** `al_faq_html`, `al_testimonials_html`, `al_projects_html`. Saved
  via `save_post` with nonce + autosave + capability checks, stored **unslashed**
  (raw code, like `al_html`).
- **Shortcodes** (names retained for backward compatibility; behavior now "render
  THIS page's section"):
  - `[anchor_local_faqs]`   → renders `al_faq_html`
  - `[anchor_local_testimonials]` → renders `al_testimonials_html`
  - `[anchor_local_projects]` → renders `al_projects_html`
  - Each accepts `id` (int, default current post). Output is the section's HTML run
    through `do_shortcode()`, wrapped in `<div class="al-faqs|al-testimonials|al-projects">`.
  - A per-post recursion guard (like `Module::render_body()`) prevents a section
    that embeds its own shortcode from looping.
  - Old attributes `service` / `limit` are dropped (no resolver anymore). Passing
    them is harmless (ignored via `shortcode_atts`).
- **Removed:** the 3 library CPTs, `match_items()` resolver, assignment metaboxes
  (`al_location_ids`, `al_global`), library admin columns, and the `wp_footer`
  `print_faq_schema` / `print_review_schema` JSON-LD emitters.

Rendering helper is section-agnostic: `render_section($post_id, $meta_key, $class)`.

### 2. Remove the SEO subsystem — delete `class-seo.php`

- **Deleted behavior:** SEO metabox + `save_seo_meta`; `wp_robots`; own
  `<title>`/meta/OG (`print_head_meta`); canonical filter; Yoast/RankMath
  title/desc/canonical/OG feeds; sitemap exclusion (core + Yoast); `[anchor_h1]`.
- **Dropped meta keys:** `al_seo_title`, `al_seo_desc`, `al_canonical`,
  `al_robots_noindex`, `al_robots_nofollow`, `al_og_title`, `al_og_desc`,
  `al_og_image`, `al_h1`, `al_breadcrumb_title`, `al_sitemap_exclude`. (Values left
  in the DB are simply unused; no cleanup required.)
- **Relocated into the Module (kept):** the full-width single-template
  `template_include` filter (`SEO::fullwidth_template` → `Module::fullwidth_template`),
  loading `templates/single-anchor-fullwidth.php`, gated on the existing
  `fullwidth_template` setting. The setting UI already lives in the Module.
- **Simplified:** `Module::crumb_label()` returns the post title directly (nothing
  writes `al_breadcrumb_title` anymore). Breadcrumbs HTML, and Place/Service +
  BreadcrumbList JSON-LD in `Module::print_schema()`/`build_schema()`, are
  **retained** — they are the module's core structured-data output, not the removed
  SEO chrome.

### 3. Dashboard — keep Coverage Matrix, remove SEO Reports

- Remove the SEO Reports submenu page (`register_pages`), `render_seo_page`, and
  `seo_issues()`.
- Keep `coverage_matrix()` and `render_coverage_page()`. Trim scoring helpers that
  read now-removed SEO meta (`has_h1`, noindex, thin-content SEO flags) down to the
  signals that still have a data source (published, has-coords, service coverage
  gaps). Coverage remains the "which location × service cells exist / are complete"
  view the owner values.

### 4. Remove Analytics — delete `class-analytics.php`

- Delete the file, its phases-loop entry, its Analytics submenu page, its option
  (`anchor_locations_analytics`) usage, and its transients. Nothing else references
  the class (verified). No other edits required.

### 5. Import/Export (`class-io.php`)

- **Remove** from the JSON envelope and tier map: `projects`, `testimonials`,
  `faqs` entities and the `library_meta_keys()`; and the SEO meta keys from
  `location_meta_keys()` / `service_meta_keys()` (and any CSV key lists).
- **Add** `al_faq_html`, `al_testimonials_html`, `al_projects_html` to the location
  and service **JSON** meta-key tiers. Excluded from CSV (they are HTML/code, like
  `al_html`/`al_css`/`al_js`).
- Keep the `Integrity::$suspend_bumps` / `bump_now()` coupling and settings-blob
  round-trip unchanged.

### 6. Tests

- **Delete** `tests/test-locations-analytics.php`.
- **Replace** `tests/test-locations-libraries.php` with `tests/test-locations-sections.php`:
  save/round-trip of the three section meta keys; each shortcode renders the
  current page's section and honors `id`; `do_shortcode()` runs inside sections;
  recursion guard holds; blank section renders empty.
- **Trim** `tests/test-locations-seo.php`: remove SEO-metabox/robots/title/OG/
  canonical/sitemap/`[anchor_h1]` cases. Move the **full-width template** test to a
  Module-level test (behavior relocated). Breadcrumb behavior now asserts the
  title fallback (no override key).
- **Trim** `tests/test-locations-dashboard.php`: drop `seo_issues()` cases; keep
  and, where needed, adjust coverage-matrix cases for the trimmed scoring.
- **Update** `tests/test-locations-io.php`: new key set; assert libraries/SEO keys
  are gone and section keys round-trip.
- `tests/test-locations-schema.php`, `test-locations-shortcodes.php`,
  `test-locations-rewrite.php`, `test-locations-settings.php`,
  `test-locations-integrity.php`, `test-status-transitions.php` — verify still pass;
  adjust only if they reference removed pieces.

### 7. Docs

- Update `anchor-locations/README.md`: replace the content-libraries section with
  the per-page content-sections model; remove SEO / SEO-Reports / Analytics
  sections; update the shortcode table (the three `[anchor_local_*]` now render the
  page's own section; drop `[anchor_h1]`).
- Update the in-admin shortcode reference on the Settings ▸ Locations tab
  (`Module::render_shortcode_reference()`): move the three `[anchor_local_*]` into a
  "Page content" grouping with `id`-only attributes and the new descriptions; drop
  `[anchor_h1]`.

## Menu / UX after change

Locations submenu: **Locations · Service Pages · Coverage Matrix · Import/Export**,
plus the **Settings ▸ Anchor Tools ▸ Locations** tab. No Projects/Testimonials/FAQs
CPT items, no SEO Reports, no Analytics. Each location/service edit screen shows:
Content (HTML/CSS/JS), **Content Sections (FAQ/Testimonials/Projects)**, Details.

## Couplings preserved / risks

- **Full-width template** must move with its behavior or it silently breaks — handled
  in §2.
- **IO meta-key lists** must change in lockstep (§5) or fields drop from
  export/import silently.
- **BreadcrumbList duplication** with Rank Math is possible; noted, not addressed.
- **Integrity** already ignores library CPTs; removing them needs no Integrity
  change. Section meta lives on the two Phase-1 CPTs, so `al_*` meta writes already
  bump the cache version correctly.
- Removing SEO meta keys leaves orphaned values in the DB — harmless, no cleanup.

## Release

Ships as a normal tag off `main` (next patch version) via the existing release
workflow (minified assets rebuilt by CI).
