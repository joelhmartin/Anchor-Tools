# Anchor Testimonials + Anchor Speakers: Design

Date: 2026-09-24
Status: approved in conversation (user reviews plans, not specs)
Target release: 3.31.0
First consumer: TMJ & Sleep Therapy Centre International (framework conversion). Second consumer: DEKA (theme-level `_deka_event_speaker_ids` can migrate to Speakers later; not in scope).

## Why

Anchor Tools has no authored-testimonial model. `anchor-reviews` only renders cached Google Places reviews (no manual entries, no audience type, no scoping). There is no speaker/person entity either; DEKA keeps speakers in its theme. TMJ needs:

- Patient testimonials and doctor testimonials, most of them YouTube videos, some written quotes, ideally scoped to the course they talk about.
- Speakers as a real post type (not loose pages), with a "featured" designation for the three principal faculty, linked to events.

Both are generic (any clinic or events site), so they belong in the plugin, not the TMJ child theme.

## Module 1: `anchor-testimonials`

Registry key `testimonials`, class `Anchor_Testimonials_Module`, dir `anchor-testimonials/`. Off by default like other modules.

### Data

- CPT `anchor_testimonial`: `public => false`, `show_ui => true`, `publicly_queryable => false`, no archive, no single URLs. Supports title (internal label), editor (the quote, optional), thumbnail (person photo), page-attributes (menu_order for manual sort). `show_in_menu` via `apply_filters('anchor_testimonials_parent_menu', true)`.
- Taxonomy `anchor_testimonial_audience` (non-hierarchical, admin-only, `show_admin_column`). Seeded terms: `patient`, `doctor`. Sites may add terms.
- Meta (prefix `_at_`):
  - `_at_person_name` (string), `_at_person_meta` (string, e.g. "DDS, Auburn, CA" or "Patient, Denver")
  - `_at_video_url` (string, YouTube or Vimeo URL; normalized to provider + ID on save into `_at_video_provider`, `_at_video_id`)
  - `_at_rating` (int 0-5, optional, 0 = hidden)
  - `_at_featured` (bool)
  - `_at_related` (array of post IDs, any post type: an Anchor Events group parent or event, a page, a product, an `anchor_speaker`). Stored as one serialized array plus one `_at_related_id` row per ID so it can be queried with `meta_query` `=`.
- A testimonial is valid if it has a quote or a video (enforced as an admin notice, not a save block).

### Admin

- One metabox "Testimonial details": person name/meta, video URL with live thumbnail preview, rating, featured checkbox, "Related to" picker (AJAX post search across public post types plus `event` and `anchor_speaker`; shows chips with type labels).
- Admin columns: photo/video thumb, person, audience, related, featured.

### Front end

Shortcode `[anchor_testimonials]`, attributes:

| attr | values | default |
|---|---|---|
| `audience` | term slug(s), comma list | all |
| `related` | `current`, ID list, or empty | empty (no scoping) |
| `layout` | `slider`, `grid`, `video-grid` | `grid` |
| `type` | `any`, `video`, `quote` | `any` |
| `featured` | `1` to limit to featured | off |
| `limit` | int | 12 |
| `columns` | 1-4 (grid/slider desktop) | 3 |
| `orderby` | `menu_order`, `date`, `rand` | `menu_order` |
| `fallback` | `all` = if `related` finds nothing, drop scoping | `all` |

`related="current"` resolves the queried object ID; if that post is an Anchor Events child occurrence, its group parent ID is added (so a testimonial related to the course matches every date page). Pages can also pass the course's event ID explicitly.

Markup is plain BEM with no hardcoded colors: `.anchor-testimonials`, `--grid|--slider|--video-grid`, `.anchor-testimonial`, `__media`, `__play`, `__quote`, `__person`, `__name`, `__meta`, `__rating`. Styling reads CSS custom properties with fallbacks (`--at-card-bg`, `--at-card-radius`, `--at-accent`, `--at-gap`) so a theme skins it without overrides. Base CSS covers layout only.

Video behavior: tiles render a poster (YouTube `i.ytimg.com` maxres/hq, Vimeo oEmbed thumbnail cached in `_at_video_thumb`) and a play button; the iframe is created only on click (no iframes on page load). Playback opens in a lightbox.

### Reuse (do not build parallel copies)

- **Lightbox**: `anchor-gallery` already opens YouTube/Vimeo in a lightbox. The implementation must first check whether its JS can be called for arbitrary triggers. If it can, use it. If it is welded to gallery markup, extract the lightbox into a shared plugin-level asset (`assets/js/anchor-lightbox.js` + css), point `anchor-gallery` at it, and use it here. One lightbox in the plugin.
- **Carousel**: same rule for `anchor-slider/assets/slider.js`. If it cannot drive an arbitrary track of cards (per-breakpoint cards-per-view, prev/next, dots), extract a shared `assets/js/anchor-carousel.js` and have both modules use it.
- Video URL parsing: reuse whatever parser `anchor-gallery` has (move it to a shared helper in `includes/` if it is module-private).

### Schema

No `Review` JSON-LD by default (Google disallows self-serving review markup for LocalBusiness). Not in scope.

## Module 2: `anchor-speakers`

Registry key `speakers`, class `Anchor_Speakers_Module`, dir `anchor-speakers/`.

### Data

- CPT `anchor_speaker`: public, has single pages, supports title (full display name, e.g. "Dr. Steven Olmos"), editor (full bio), excerpt (short bio), thumbnail (headshot), page-attributes (menu_order).
- Rewrite base is a setting, option `anchor_speakers_options['base']`, default `speakers`. `has_archive` off by default (setting). TMJ sets base `about-us` to keep `/about-us/dr-steven-olmos/` unchanged.
- **Page-collision fallback**: when the base equals an existing page path (e.g. `about-us`), a `request` filter checks whether `anchor_speaker` with that slug exists; if not, it rewrites the query to the page `about-us/<slug>` so non-speaker child pages keep resolving. Covered by tests. Changing the base flushes rewrites once (on option save).
- Meta (prefix `_as_`): `_as_credentials` ("DDS, MS, DABCP..."), `_as_title` (role, e.g. "Founder"), `_as_location`, `_as_featured` (bool), `_as_links` (array of label + URL, optional).

### Events integration

Only when the Events module is active (checked with `class_exists`), and without editing Events' own files except where noted:

- Speakers module adds a "Speakers" metabox to the `event` post type (ordered multi-select of `anchor_speaker`), stored as `_anchor_event_speaker_ids` (ordered array). On save, occurrences inherit it: `Occurrences::INHERITED_KEYS` is a constant with no filter today, so add a filter `anchor_events_inherited_keys` around it in Events (small, tested change) and hook it from Speakers.
- Hooks `anchor_events_schema_node` to add `performer` (`Person` with name, url, image, jobTitle) for each linked speaker.
- `[anchor_speakers event="current"]` lists an event's speakers; on a group parent or child it uses the parent's list.

### Front end

Shortcode `[anchor_speakers]`: `featured` (1), `ids`, `event` (`current` or ID), `layout` (`grid`, `list`, `compact`), `columns`, `limit`, `link` (1/0 to single page), `show` (comma list of `credentials,title,excerpt,location`).

BEM: `.anchor-speakers`, `--grid|--list|--compact`, `.anchor-speaker`, `__photo`, `__name`, `__credentials`, `__title`, `__excerpt`, `__link`. Custom properties for skinning, like testimonials.

Single template: plugin ships a minimal fallback via `single_template` only if the theme has no `single-anchor_speaker.php`. TMJ's child theme provides its own.

Testimonials can relate to a speaker, so a speaker page can show `[anchor_testimonials related="current"]`.

## Testing

- PHPUnit (existing `tests/` harness): CPT/tax registration, video URL normalization (YouTube watch/embed/youtu.be/shorts with `t=` params, Vimeo), `related="current"` resolution including occurrence to group parent, query building for each attribute, speakers rewrite base + page-collision fallback, schema `performer` injection, inherited-key propagation to occurrences.
- Playwright: one E2E per module (render grid, open a video in the lightbox, slider next/prev; speaker single page under custom base).

## Release

Branch `feat/testimonials-speakers` (worktree `anchor-tools-wt-people`). PR (count files, must be well under 150), CodeRabbit review verified, user sign-off, merge to `main`, bump to 3.31.0 on `main`, tag `3.31.0`. Update `CLAUDE.md` module table and `ADDING-MODULES.md` only where they list modules.

## Out of scope

Review schema, front-end testimonial submission, migrating DEKA speakers, Anchor Courses.
