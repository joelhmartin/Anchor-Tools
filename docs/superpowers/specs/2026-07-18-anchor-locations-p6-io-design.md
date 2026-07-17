# Anchor Locations — Phase 6: Import / Export (design)

## Goal

Let an operator move an entire Anchor Locations structure (locations hierarchy,
service pages, service taxonomy, content libraries, settings) between client
sites, and bulk-edit the two most-edited surfaces (locations, service pages) via
CSV round-trip. This is portability, **not** page generation: import only
creates/updates from a user-supplied file and never fabricates combinations or
deletes anything.

## Non-goals

- No auto page-generator (the owner rejected that in Phase 5). Import never
  invents service×location combinations; it only writes what the file contains.
- No deletion, ever. Omitting an item from an import leaves the existing item
  untouched.
- No media/attachment binary transfer — image fields are URLs (portable as-is).

## New file

`anchor-locations/class-io.php`, class `\Anchor\Locations\IO`, instantiated from
`Module::__construct` (`require_once` + `new IO()`), alongside Libraries/SEO/Dashboard.

## Cross-site portability principle

Everything references by **slug**, never numeric ID:

- Locations: `post_name` (slug); parent via `parent_slug` (parent's `post_name`).
- Service pages: `location_slug` (linked location's `post_name`) +
  `service_slugs[]` (service term slugs).
- Service terms: `slug`, parent term via `parent_slug`.
- Library `al_location_ids`: exported/imported as an array of location **slugs**.

## JSON envelope (version 1)

```json
{
  "format": "anchor-locations",
  "version": 1,
  "exported_at": "2026-07-18T00:00:00Z",
  "settings": { ...anchor_locations_settings... },
  "services": [ { "name": "...", "slug": "...", "parent_slug": "..." } ],
  "locations": [
    { "title": "...", "slug": "...", "status": "publish", "parent_slug": "...",
      "meta": { "al_type": "...", "al_lat": "...", ... all scalar al_* + al_html/al_css/al_js/al_boundary + SEO keys } }
  ],
  "service_pages": [
    { "title": "...", "slug": "...", "status": "publish",
      "service_slugs": ["..."], "location_slug": "...",
      "meta": { ...al_* except al_location_id (resolved from location_slug)... } }
  ],
  "projects":     [ { "title","slug","status","service_slugs":[...], "meta": { al_image, al_description, al_location_ids:[slugs], al_global } } ],
  "testimonials": [ { ..., "meta": { al_quote, al_author, al_rating, al_location_ids:[slugs], al_global } } ],
  "faqs":         [ { ..., "meta": { al_question, al_answer, al_location_ids:[slugs], al_global } } ]
}
```

`al_location_id` on a service page is NOT stored in `meta` — it is reconstructed
from `location_slug` on import. `al_location_ids` on library items is stored in
`meta` as an array of location slugs and reconstructed to IDs on import.

## Meta key groups (mirror save handlers)

- Location scalar text (`sanitize_text_field`): al_type, al_lat, al_lng,
  al_place_id, al_state_abbr, al_county, al_postal_codes, al_marker_icon.
- Code fields (raw via `wp_unslash`, no sanitize): al_html, al_css, al_js, al_boundary.
- Bool ('1'/''): al_disable_wrapper, al_robots_noindex, al_robots_nofollow, al_sitemap_exclude, al_global.
- SEO text (`sanitize_text_field`): al_seo_title, al_og_title, al_h1, al_breadcrumb_title.
- SEO textarea (`sanitize_textarea_field`): al_seo_desc, al_og_desc.
- URL (`esc_url_raw`): al_canonical, al_og_image, al_image.
- Library rich (`wp_kses_post`): al_description, al_quote, al_answer.
- Library text: al_author (`sanitize_text_field`), al_question (`sanitize_text_field`).
- Int: al_rating (clamp 1–5, else 0).
- al_location_ids: array of ints (resolved from slugs).

## CSV (bulk-edit)

Two separate exports/imports: **locations** and **service_pages**. Scalar fields
only — large HTML/CSS/JS and boundary GeoJSON are JSON-only (a header note row is
NOT used; a `# note` is impractical in CSV, so these columns are simply omitted
and documented here + in the UI).

- **locations.csv** columns: `slug,title,status,parent_slug,al_type,al_lat,al_lng,
  al_place_id,al_state_abbr,al_county,al_postal_codes,al_marker_icon,al_disable_wrapper,
  al_seo_title,al_seo_desc,al_canonical,al_robots_noindex,al_robots_nofollow,
  al_og_title,al_og_desc,al_og_image,al_breadcrumb_title,al_h1,al_sitemap_exclude`.
- **service_pages.csv** columns: `slug,title,status,location_slug,service_slugs,
  al_seo_title,al_seo_desc,al_canonical,al_robots_noindex,al_robots_nofollow,
  al_og_title,al_og_desc,al_og_image,al_breadcrumb_title,al_h1,al_sitemap_exclude`.
  `service_slugs` is a `|`-separated list in one cell.

Type detection on import = presence of `location_slug` header ⇒ service_pages,
presence of `al_type`/`parent_slug` (and no `location_slug`) ⇒ locations.

### CSV formula-injection guard

On export, any cell whose value starts with `=`, `+`, `-`, `@`, TAB (0x09), or CR
(0x0D) is prefixed with a single quote `'`. Applied to every emitted cell.

## Upsert-by-slug + ordering

`upsert_post($type, $slug, $fields)`:
1. Find existing post: `get_posts(post_type=$type, name=$slug, post_status=any, 1)`.
2. If found → `wp_update_post` (title/status/parent); else `wp_insert_post` with
   `post_name=$slug`. Track created vs updated.
3. Write meta via the sanitizer tiers.

Ordering for locations: parents must exist before children so `post_parent`
resolves. Implemented with a **multi-pass** loop: repeatedly walk the pending
list, importing any whose `parent_slug` is empty or already-resolved, until no
progress (remaining = unresolvable parents → recorded as errors, still created at
parent 0? No — recorded as error and skipped to avoid wrong hierarchy). Simpler
& deterministic: two-phase — (a) upsert every location at parent 0, building a
slug→id map; (b) second pass sets `post_parent` from `parent_slug`. This always
resolves regardless of file order and avoids infinite loops. We use the
two-phase approach.

Service terms: created first (parents before children, two-phase same as locations).

Service pages: resolve `location_slug`→id (skip+error if unknown),
`service_slugs`→term ids (create-by-slug? No — only assign existing terms that
were created in the services phase or already exist; unknown service slug is
recorded as a soft warning but the page still imports with the resolvable terms).

## Import API

`import_json(array $data, array $opts = []): array`
- Validate `format === 'anchor-locations'` && `version === 1` (else return error summary).
- `opts['dry_run']` = compute summary, write nothing.
- Order: settings → services → locations → service_pages → projects → testimonials → faqs.
- Returns `['created'=>int,'updated'=>int,'skipped'=>int,'errors'=>string[]]`.
- Every row wrapped so a malformed row is caught → `skipped`++ + `errors[]`, never fatal.

`import_csv(string $csv, array $opts = []): array` — detect type from header,
upsert scalar rows by slug. Same summary shape + dry_run.

## Admin UI

Submenu **Import / Export** under the Anchor Locations menu
(`edit.php?post_type=anchor_location`), capability `manage_options`.
- Export section: buttons → JSON (full), locations CSV, service_pages CSV. Each
  posts to `admin_post_anchor_locations_export` with `type` + nonce; handler
  streams the file with `Content-Disposition: attachment`.
- Import section: file upload (`.json`/`.csv`, size limit 5 MB), a
  **dry-run/preview** checkbox. On submit → `admin_post_anchor_locations_import`,
  nonce-checked, reads the temp file, runs import (dry-run first when checked),
  and renders the summary. A real (non-dry) run shows created/updated/skipped +
  the errors list.

## Safeguards (binding)

- Never delete. Upsert only.
- `manage_options` on both handlers; `check_admin_referer` on every handler.
- Validate uploaded file extension + size; sanitize/cast every imported value.
- CSV formula-injection guard on export.
- Per-row try/catch so one bad row can't abort the batch.

## Tests (tests/test-locations-io.php, LocationsIoTest)

Round-trip, idempotency, dry-run-writes-nothing, CSV formula-injection,
never-delete, bad-row-recorded. Export→import into a clean state and assert the
hierarchy/meta/term-linkage/al_location_ids reconstruct by slug.
