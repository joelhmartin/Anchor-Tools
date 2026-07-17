# Anchor Locations — Phase 4: SEO Controls + Schema (Design)

Date: 2026-07-18
Branch: `feature/anchor-locations-p4-seo`

## Goal

Give per-page SEO control to `anchor_location` and `anchor_service_page` posts, integrating cleanly
with an active SEO plugin (Yoast / Rank Math / AIOSEO) instead of fighting it, plus Review/AggregateRating
JSON-LD driven by rendered testimonials, and finally wire the long-inert `fullwidth_template` setting.

## Scope (Phase 4 only)

### A. Per-page SEO meta (both CPTs)
New "SEO" metabox with meta keys (prefix `al_`):

| Key | Type | Sanitize |
|---|---|---|
| `al_seo_title` | text | `sanitize_text_field` |
| `al_seo_desc` | textarea | `sanitize_textarea_field` |
| `al_canonical` | url | `esc_url_raw` |
| `al_robots_noindex` | `'1'|''` | bool normalize |
| `al_robots_nofollow` | `'1'|''` | bool normalize |
| `al_og_title` | text | `sanitize_text_field` |
| `al_og_desc` | textarea | `sanitize_textarea_field` |
| `al_og_image` | url (media) | `esc_url_raw` |
| `al_breadcrumb_title` | text | `sanitize_text_field` |
| `al_h1` | text | `sanitize_text_field` |
| `al_sitemap_exclude` | `'1'|''` | bool normalize |

Save uses the existing guard trio (nonce `al_seo_nonce` / autosave / `current_user_can('edit_post')`).

### B. Output / SEO-plugin integration
Detection: Yoast `defined('WPSEO_VERSION')`, Rank Math `class_exists('RankMath')`, AIOSEO `defined('AIOSEO_VERSION')`.
- **Robots** — ALWAYS via core `wp_robots` filter; add `noindex`/`nofollow` for the queried CPT when its flags are set.
- **SEO plugin active** — feed our per-page values INTO it via filters, override only when our field is non-empty:
  - Yoast: `wpseo_title`, `wpseo_metadesc`, `wpseo_canonical`, `wpseo_opengraph_title`, `wpseo_opengraph_desc`, `wpseo_opengraph_image`.
  - Rank Math: `rank_math/frontend/title`, `rank_math/frontend/description`, `rank_math/frontend/canonical`.
  - AIOSEO: detected → suppress our own output (no public value-injection filters wired; best-effort).
  Never emit our own raw `<title>`/`<meta>` when a plugin is active (would duplicate).
- **No SEO plugin** — emit our own, guarded to `is_singular([CPT_LOCATION, CPT_SERVICE])`:
  - `pre_get_document_title` returns `al_seo_title`.
  - `wp_head`: `<meta name="description">`, `<link rel="canonical">`, OG `og:title`/`og:description`/`og:image` — each only when its field is set; all escaped.
- **`al_h1`** — `[anchor_h1 id=""]` shortcode (falls back to post title); also `anchor_locations_h1` filter.
- **`al_breadcrumb_title`** — Module gains a `crumb_label($id)` helper (meta or title fallback) used by
  `sc_breadcrumbs` and `build_schema` for crumb labels.

### C. Sitemap exclusion
`al_sitemap_exclude === '1'`:
- Core: `wp_sitemaps_posts_query_args` for both CPTs adds `post__not_in` of flagged IDs (must-have).
- Yoast: `wpseo_exclude_from_sitemap_by_post_ids` returns flagged IDs (best-effort).

### D. Review / AggregateRating schema
Lives in `class-libraries.php` (owns the testimonial CPT + the `wp_footer` collector pattern already used for FAQ).
`sc_testimonials` feeds a per-request collector for testimonials WITH a rating (deduped by post ID). On
`wp_footer` (singular only, ≥1 rated testimonial rendered) emit a node:
- `@type` = `Service` on a service page, else `Place`; `name` = page title.
- `review[]`: `{@type:Review, author:{@type:Person,name}, reviewBody, reviewRating:{@type:Rating,ratingValue,bestRating:5}}`.
- `aggregateRating`: `{@type:AggregateRating, ratingValue: avg (1dp), reviewCount: count, bestRating:5}`.
Same SAFE encoding (no `JSON_UNESCAPED_SLASHES`, `</`→`<\/`). Absent when no rated testimonials shown.

### E. Wire `fullwidth_template`
`template_include` filter: when setting `fullwidth_template === '1'` AND `is_singular([CPT_LOCATION, CPT_SERVICE])`,
return `anchor-locations/templates/single-anchor-fullwidth.php` (header + `the_content` + footer, full-width, no sidebar).
Default off.

## Placement
- New file `anchor-locations/class-seo.php` → `\Anchor\Locations\SEO`, instantiated from `Module::__construct`.
- Review schema in `class-libraries.php` (co-located with the testimonial CPT + collector).
- `crumb_label` + its two call sites in `anchor-locations.php`.
- Template file under `anchor-locations/templates/`.

## Conventions
Namespace `\Anchor\Locations\`; text domain `anchor-schema`; meta prefix `al_`; save guards; escape all output;
`</script>`-safe JSON-LD.
