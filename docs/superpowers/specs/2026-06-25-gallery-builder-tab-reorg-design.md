# Anchor Gallery Builder — Tab Reorganization

**Date:** 2026-06-25
**Module:** `anchor-gallery/anchor-gallery.php` (class `Anchor_Video_Slider_Module`, CPT `anchor_video_gallery`)
**Status:** Approved design — ready for implementation plan

## Problem

The gallery builder's tab layout confuses users:

1. **Content** holds three display toggles at the bottom (`show_caption`, `show_duration`,
   `show_channel`) that have no obvious reason to be there.
2. **Style** mixes card/image styling with video-only concerns (play button), making it bloated.
3. **Behavior** is an incoherent grab-bag — navigation arrows, dots, slides-to-scroll,
   transition speed, autoplay, popup behavior — that doesn't read as a meaningful category.
4. Tabs show settings irrelevant to the chosen layout. The user wants the preset (which *is*
   the layout) to scope each tab down to only what that layout needs.

## How the builder works today (context)

- Each setting in `get_setting_defs()` carries a `section` key (`content|preset|layout|style|behavior|responsive|advanced`).
- `render_builder_after_title()` defines the tab array and maps each section to a render
  callback. `render_pane_section()` renders all fields whose `section` matches the tab.
- `applies_to` (array of layout keys) hides a field when the active `avg_layout` isn't in the
  list. Fields with no `applies_to` are global and show on every layout.
- Presets (`get_presets()`) set `avg_layout` (plus other overrides). The shared
  `includes/builder/assets/builder.js` applies overrides and fires `change` on `avg_layout`;
  the gallery's `assets/admin.js` `updateVisibility()` then re-filters fields by layout +
  `depends_on`.
- `section` is **presentational only** — it decides which tab renders a field. It does not
  affect meta keys or saving. The save handler iterates every setting def regardless of section.

## Design

### 1. Tab structure

Before: `Content · Preset · Layout · Style · Behavior · Responsive · Advanced`
After:  `Content · Preset · Layout · Style · Video · Responsive · Advanced`

- Remove `behavior` from the tab array in `render_builder_after_title()`.
- Add `video` (label "Video") in the slot after `style`.
- `video` renders via the existing generic `render_pane_section()` path (no special pane).

### 2. Setting → section reassignments

All changes are edits to the `section` value in `get_setting_defs()`.

**→ `video` (new):**
- Play button: `play_button_style`, `play_button_color`, `play_button_bg_color`,
  `play_button_size`, `play_button_shadow`
- `autoplay` (autoplay on popup open)
- Duration: `show_duration`, `duration_bg_color`, `duration_color`, `duration_size`, `duration_radius`
- Channel: `show_channel`, `channel_color`, `channel_size`
- Popup/click: `popup_style`, `popup_max_width`, `popup_aspect_ratio`, `popup_show_caption`

**→ `style` (from `content`):**
- `show_caption` (joins existing `caption_color`, `caption_size`)

**→ `layout` (the remainder of `behavior`):**
- Pagination: `pagination_enabled`, `videos_per_page`, `pagination_style`
- Slider/carousel nav & motion: `slider_arrows`, `slider_dots`, `slider_autoplay`,
  `slider_autoplay_speed`, `carousel_loop`, `carousel_center`, `carousel_slides_to_scroll`,
  `carousel_transition_speed`, `carousel_pause_on_hover`, `carousel_peek`
- Marquee motion: `marquee_speed`, `marquee_pause_on_hover`, `marquee_direction`
- Filter: `filter_default`, and `filter_all_label` (moved from `content` to group with `filter_default`)
- `transition_duration_ms`

**Consistency rule:** nav/dot/arrow *styling* stays in `style` (`nav_*`, `dot_*`); the
*toggles* move to `layout`. This matches the user's "navigation arrows / dots → Layout."

**Result:** the `content` section becomes empty of settings. The `render_pane_content()`
"Display" block is guarded by `if (!empty($grouped['content']))`, so it simply stops
rendering; Content shows only the item list + add buttons. (The now-dead Display block may be
left in place or removed during implementation — cosmetic, not required.)

### 3. Layout-scoped filtering ("only show what I need")

The engine already hides fields whose `applies_to` excludes the active layout. Two gaps:

**(a) Audit global settings.** These currently have no `applies_to` and leak onto every
layout, including `logo_carousel` where they are meaningless (logos use `marquee_*`
equivalents): `gap`, `gap_mobile`, `border_radius`, `transition_duration_ms`. Add `applies_to`
lists that exclude `logo_carousel` (and any other layout they don't apply to). Most
video/popup/play fields already carry `applies_to => $core_layouts`, which excludes
`logo_carousel`, so they need no change.

Implementation note: prefer reusing the existing layout-set variables (`$core_layouts`,
`$tile_layouts`, etc.) rather than inventing new ad-hoc arrays, for maintainability.

**(b) Hide empty tabs.** After the audit, some layouts have zero fields in a tab (e.g.
`logo_carousel` has nothing in Video and almost nothing in Style). Extend `updateVisibility()`
in `assets/admin.js`: after toggling field rows, for each tab pane count the visible field
rows; if zero, hide that tab's button and pane. This is the only behavioral JS change.

The tab-button / pane selectors come from the shared builder shell markup (tab buttons use
`.anchor-builder__tab[data-tab]`, panes use `[data-pane]`); the implementation plan confirms
exact selectors against `class-anchor-builder-shell.php` and `includes/builder/assets/builder.js`.

### 4. Compatibility, assets, testing

- **No data migration.** `section` is presentational; all `avg_*` meta keys and values are
  untouched. Existing galleries keep every setting. Saving/loading is unaffected because the
  save handler iterates all defs regardless of section.
- **Assets:** bump the enqueue version for `admin.js` / `admin.css` (see
  `anchor-gallery.php` ~lines 1394–1395) so the JS change ships. Rebuild `admin.min.js` if the
  minified file is the one enqueued (verify which is loaded).
- **Risk:** low and contained — `section` string edits, one tab-array edit, a few `applies_to`
  additions, ~15 lines of JS for hide-empty-tabs.
- **Testing (manual; no automated suite):**
  - Each base layout (slider, grid, masonry, carousel, gallery, filterable, logo_carousel)
    shows the correct fields in each tab.
  - Logo Carousel hides the Video tab and any other empty tab; shows only marquee-relevant fields.
  - Content tab shows only the item list + add buttons (no stray toggles).
  - Video tab shows play/autoplay/duration/channel/popup for video-capable layouts.
  - Save a gallery, reload, confirm every value round-trips (no settings lost by the section move).
  - Apply each preset; confirm the tabs scope down to that preset's layout.

## Out of scope

- No change to the preset list, the layout dropdown options, or the underlying render/output.
- No per-preset explicit allow-lists (rejected in favor of layout-based filtering).
- No new settings.
