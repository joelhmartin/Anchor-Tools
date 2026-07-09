# Post Display → CPT Conversion — Design

**Date:** 2026-05-28
**Module:** `anchor-post-display`
**Status:** Approved, ready for implementation plan

## Goal

Convert the Post Display module's `[anchor_post_grid]` from a single global
settings page into an editable **Custom Post Type** — each "post display" is its
own post with its own query + display settings, edited through the shared
gallery-style builder UI. This brings per-display control over layout,
**posts-per-slide**, and **desktop/tablet/mobile responsive** settings.

This mirrors the `anchor_gallery` CPT pattern and reuses the existing
`Anchor_Builder_Shell` / `Anchor_Builder_Device_Toolbar` infrastructure.

## Decisions (locked during brainstorming)

1. **Additive, not a replacement.** A new CPT adds an `id` mode
   (`[anchor_post_grid id="123"]`). Existing inline-attribute shortcodes
   (`[anchor_post_grid post_type="..." layout="slider" ...]`) keep working
   unchanged. No live page breaks; no forced data migration.
2. **Search stays on the settings page.** `[anchor_search]` is a single global
   form, not a display — it is not converted to a CPT.
3. **Responsive = desktop / tablet / mobile** (three discrete breakpoints).
   Breakpoints: tablet ≤ 1024px, mobile ≤ 767px (consistent with the gallery
   and slider modules).
4. **Layouts: grid, list, slider, + carousel.** Carousel is the new looping /
   auto-advancing layout (arrows, dots, autoplay, pause-on-hover); the existing
   basic slider is retained.
5. **Builder Shell reuse** (Approach A) — the CPT editor uses
   `Anchor_Builder_Shell` exactly like `anchor-gallery`.

## Architecture & Files

The existing `anchor-post-display/` module is expanded, not replaced. Concerns
are split so no single file is unwieldy:

| File | Responsibility |
|---|---|
| `anchor-post-display.php` | Bootstrap, hooks, the existing **settings-page tab** (search defaults + grid defaults), shortcode + AJAX registration, `require_once` of the new include files. Existing behavior unchanged. |
| `includes/class-apd-renderer.php` | **Shared** render pipeline extracted from today's code: `build_query_args`, `render_grid_items`, `get_custom_field_html`, `get_teaser`, `render_pagination`, plus new carousel markup and scoped responsive `<style>` generation. Consumed by both the inline shortcode and the CPT. No behavior change for inline use. |
| `includes/class-apd-display-cpt.php` | The `anchor_post_display` CPT: registration, `get_setting_defs()`, builder panes (Source + schema-driven sections), `save_meta`, admin asset enqueue, live-preview AJAX. |

The main module class keeps the global-defaults helpers (`get_option`,
`$defaults`, `sanitize_options`) and delegates rendering to the shared renderer.

## Custom Post Type

- Slug: `anchor_post_display`
- Menu label: **"Post Displays"**, `dashicons-grid-view`, `supports: ['title']`
- `public => false`, `show_ui => true`
- `show_in_menu => apply_filters('anchor_post_display_parent_menu', true)`
- Admin columns: Title, Layout, Shortcode (with the `[anchor_post_grid id="N"]`
  copy string), like the gallery's columns.

## Settings Schema (`get_setting_defs()`)

One schema keyed by setting; each entry declares
`type / label / section / priority / options / applies_to / depends_on`.
Stored as `apd_{key}` post_meta. Sections become builder tabs.

Layout keys used by `applies_to`:
- `col_layouts = ['grid']` (column count; list is always single-column)
- `slider_layouts = ['slider','carousel']`
- `pag_layouts = ['grid','list']` (list paginates but has no column control)
- `carousel_only = ['carousel']`

### Source *(custom pane — `render_pane_source`, not raw fields)*
Friendly query builder; values stored as `apd_*` meta:
- post-type checklist (registered, searchable types)
- taxonomy + include terms (comma-separated slugs)
- exclude taxonomy + exclude terms
- orderby (date / title / menu_order / rand), order (ASC / DESC)
- posts per page, max posts
- optional forced search term

### Content
- `fields` (text) — the existing `image,title,date,type,excerpt,<acf_key>` system, kept intact
- `show_date` (checkbox), `show_type` (checkbox)
- `teaser_words` (number)
- `image_size` (text)

### Layout
- `layout` (select: grid / list / slider / carousel)
- `columns_desktop` (number 1–6, `applies_to` col_layouts)
- `gap` (number)
- `card_style` (select: card / minimal / bordered)

### Style *(intentionally lean for v1)*
- `border_radius` (number)
- `tile_shadow` (select: none / soft / medium / strong)
- `wrapper_bg` (color)
- `title_color` (color), `title_size` (number, 0=auto), `title_weight` (select)

### Behavior
- `pagination` (select: none / numbered / load_more, `applies_to` pag_layouts)
- `pagination_window` (number, depends_on pagination ≠ none)
- `slider_per_view` (number 1–6, desktop, `applies_to` slider_layouts)
- `slider_autoplay` (checkbox, slider_layouts)
- `slider_speed` (number, depends_on slider_autoplay)
- `carousel_loop` (checkbox, carousel_only)
- `carousel_arrows` (checkbox, slider_layouts)
- `carousel_dots` (checkbox, slider_layouts)
- `carousel_pause_on_hover` (checkbox, carousel_only, depends_on slider_autoplay)

### Responsive
- `columns_tablet` (number 1–4, col_layouts)
- `columns_mobile` (number 1–2, col_layouts)
- `slider_per_view_tablet` (number, slider_layouts)
- `slider_per_view_mobile` (number, slider_layouts)
- `gap_mobile` (number, 0 = use Gap)

### Advanced
- `no_results` (text)
- `custom_css` (textarea, scoped to this display)
- `html_anchor` (text — wrapper id)

**Defaults** seed from the global Post Display Defaults option so a new display
starts consistent with the site. `default_settings` is built by merging the
schema defaults with the saved global option values.

## Builder UI

`render_builder_after_title` (hooked for this CPT only) calls
`Anchor_Builder_Shell::render()` with:
- tabs: Source, Content, Layout, Style, Behavior, Responsive, Advanced
- Source → `render_pane_source` (custom query controls)
- other tabs → `render_pane_section($post, $key)` iterating
  `get_settings_by_section()` and calling `Anchor_Builder_Shell::render_field`
- preview pane → `render_pane_preview` (live AJAX preview)
- utility panel → status / layout / source summary / ID
- `Anchor_Builder_Device_Toolbar` provides the Desktop/Tablet/Mobile/Full toggle
  that resizes the preview iframe/container.

`save_meta` iterates the schema (checkbox/number/select/color/text/textarea
sanitization, matching the gallery handler) plus the Source pane fields, all
stored as `apd_{key}`.

## Shortcode Resolution & Backward Compatibility

`[anchor_post_grid]` (and aliases `[post_grid]`, plus new `[anchor_post_display]`):

```
if id attr present:
    resolve CPT post by numeric ID, then by slug
    load apd_* meta into settings array (typed by default)
    inline atts present alongside id still override individual settings
    render via Anchor_APD_Renderer
else:
    existing inline-attribute behavior, unchanged
    global defaults remain the fallback
```

`[anchor_search]` unchanged.

## Frontend Rendering

- Renderer accepts a normalized settings array (already produced by
  `normalize_params`). Extend `normalize_params` with the new keys.
- **Responsive CSS:** emit a scoped `<style>` block keyed to a per-display
  wrapper id (`#apd-{uid}` or the `html_anchor`):
  - grid: `--apd-cols` set at desktop, overridden in `@media (max-width:1024px)`
    (tablet) and `@media (max-width:767px)` (mobile)
  - slider/carousel: per-view at each breakpoint controls slide flex-basis
  - `gap` / `gap_mobile`
- **Carousel layout:** extend `frontend.js` — build on the existing slider
  (per-view, autoplay, swipe, arrows already present). Add: continuous loop,
  dot navigation, pause-on-hover. List layout = grid renderer forced to 1 col.

## Live Preview

`wp_ajax_anchor_post_display_preview` renders the display from posted settings
using the shared renderer (mirrors the gallery's `ajax_preview`). Debounced from
the builder JS; device toolbar switches preview width.

## Migration / Defaults

- **No automatic data migration** (additive design — nothing to convert).
- The global **Post Display Defaults** settings tab stays and serves two roles:
  (1) fallback for inline-attribute shortcodes, (2) initial defaults for new CPT
  entries.

## Testing (manual — no automated suite in this repo)

Checklist run in a WordPress environment:
1. Create a display in each layout (grid, list, slider, carousel); insert via
   `[anchor_post_grid id="N"]`; confirm it renders.
2. Resize to desktop / tablet / mobile; confirm columns and slides-per-view
   match the per-breakpoint settings.
3. Confirm an existing inline `[anchor_post_grid post_type="..." layout="slider"]`
   still renders identically (regression).
4. Confirm resolution by both numeric ID and slug.
5. Confirm pagination (numbered) and load-more.
6. Confirm the `fields` system (built-in tokens + an ACF field) still works.
7. Confirm carousel: arrows, dots, autoplay, loop, pause-on-hover.
8. Confirm builder live preview matches the front-end output, and the device
   toolbar resizes the preview.
9. Confirm `[anchor_search]` and the global defaults tab are unaffected.

## Out of Scope (v1)

- Masonry layout (deferred).
- Custom per-display breakpoint pixel values (fixed tablet/mobile breakpoints).
- The gallery's full ~80-key style system — Style is intentionally lean.
- Converting `[anchor_search]` to a CPT.
- Term pickers beyond comma-separated slugs (token/autocomplete UI deferred).
