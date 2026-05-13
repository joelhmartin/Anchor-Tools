# Anchor Gallery — Full-Coverage Customization Design

**Status:** Approved (brainstorming complete, ready for implementation plan)
**Date:** 2026-05-12
**Target module:** `anchor-gallery/` (CPT-based)
**Target plugin version:** 3.7.0

---

## 1. Goal

Expose nearly every visual property of the Anchor Gallery module as an editable, per-post control. Today the module ships with a rigid set of "tile styles" and "themes" that hardcode dozens of visual decisions (tile background, hover behavior, title typography, padding, borders, overlay gradient, play-button color/size, transition speed). The user can only pick a coarse preset and live with whatever it bakes in.

After this change:

1. The three explicitly broken/limited controls — **hover effect**, **show title**, **tile background** — are fixed and expanded.
2. Other hardcoded visual properties uncovered during diagnosis are also exposed.
3. An **Advanced tab** lets the user override every underlying CSS custom property per-post, plus drop in a raw Custom CSS block scoped to that gallery.
4. Presets remain **one-shot defaults**. After a preset is applied, every field is freely editable, including being cleared back to "no styling," so the user can "strip a preset down to nothing, but JavaScript still works."

Untouched galleries must render byte-identically after upgrade.

---

## 2. Diagnosis (what's broken or hardcoded today)

### 2.1 Named issues

1. **`show_title` vs `title_position` conflict.** The renderer (`render_output()`, lines ~1622–1645) merges two overlapping controls awkwardly. When `title_position` is `below` or `overlay`, the `show_title` checkbox is silently ignored. Toggling Show Title appears to do nothing in those cases.
2. **`hover_effect`** is a 4-value select (`lift / zoom / glow / none`) implemented as fixed CSS classes. No intensity, no transition timing control.
3. **Tile background** has no per-gallery control. `--avg-bg` is set entirely by `theme` (dark/light/auto). No transparent option, no custom color.

### 2.2 Additional hardcoded properties found

- Tile padding (no setting).
- Tile border width / color / style (only `border_radius` exists).
- Title typography: size, weight, color, text-transform, alignment.
- Caption visibility (always-on if caption non-empty), color, size.
- Overlay tile style: gradient hardcoded at `linear-gradient(to top, rgba(0,0,0,0.85) 0%, transparent 100%)`.
- Play button color / background / size — uses `--avg-play-bg` and `--avg-play-color` but these aren't surfaced as controls.
- Thumb empty-state background gradient (line 86) hardcoded.
- Global transition duration (0.2s in many places) hardcoded.
- `--avg-bg-hover` is theme-locked.

---

## 3. Architecture

### 3.1 Single uniform pattern

Every visual control becomes a **CSS custom property** emitted in the gallery wrapper's inline `style=""` attribute. This matches how `--avg-gap`, `--avg-radius`, `--avg-cols-desktop` already work in `render_output()`.

### 3.2 CSS refactor with safe fallbacks

`anchor-gallery/assets/anchor-video-slider.css` is updated so that everywhere a new control needs to take effect, the hardcoded value is replaced with `var(--avg-X, FALLBACK)`. The fallback equals today's value. Result: galleries that don't set any new control render byte-identically to current.

Example:

```css
/* Before */
.avg-title { font-size: 14px; color: var(--avg-text); }

/* After */
.avg-title {
  font-size: var(--avg-title-size, 14px);
  color: var(--avg-title-color, var(--avg-text));
}
```

### 3.3 New schema field types

`get_setting_defs()` currently supports `select`, `number`, `checkbox`, `text`. Add:

- **`color`** — renders a WP color picker (`wp-color-picker`). Stored as hex (or empty string = unset).
- **`textarea`** — multi-line text input. Used for Custom CSS.

Both are sanitized in `save_meta()`:

- `color`: validated against `^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$|^rgba?\([^)]+\)$|^$`.
- `textarea` (Custom CSS): stripped of `<script>` and `</style>` substrings, length-capped (e.g. 10 KB), stored raw.

### 3.4 CSS variable emission

In `render_output()`, after the existing `$style_vars` list is built, append every new setting that has a non-empty value as another `--avg-X: value` entry. Order: friendly Style-tab controls first, then raw Advanced-tab vars (so Advanced wins on duplicates). Custom CSS is emitted separately as a `<style>` block placed immediately before the gallery wrapper element in the rendered HTML (see §4.5).

### 3.5 Custom CSS scoping

V1 keeps scoping user-managed for simplicity and safety. The textarea label includes the gallery's unique id (e.g. `#avs-1738293012`) and reads:

> Custom CSS — use `#avs-1738293012` to scope rules to this gallery.

The emitted block:

```html
<style data-avg-uid="avs-1738293012">
{{ user content, verbatim, with </style> stripped }}
</style>
```

If the user writes unscoped global rules, they propagate. This is documented inline. A future v2 may add an "auto-scope declarations" toggle.

### 3.6 Presets stay one-shot

No new `forced_by` entries added. Presets are applied via the Preset pane (existing flow) and merely write their `overrides` into post meta. After that every field is editable, including clearing the field back to its empty/zero state to strip styling entirely.

---

## 4. New & changed controls (by tab)

Tabs in the builder today: `layout`, `style`, `content`, `behavior`, `responsive`, `advanced`.

### 4.1 Content tab — fix title bug

- **Remove** `show_title` from the UI (meta key remains in DB; renderer stops reading it).
- `title_position` becomes the single source of truth: `hidden / below / overlay`.
- **Add** `show_caption` (checkbox, default true) — currently caption renders whenever non-empty.

### 4.2 Style tab — expand visual surface

Hover:

- `hover_effect` — expand options to: `none, lift, zoom, glow, tilt, fade, slide-up, brighten, desaturate`.
- `hover_intensity` — select `subtle / normal / strong` (drives CSS-var scale factor).
- `hover_duration_ms` — number, 50–1000, default 200.

Tile background:

- `tile_bg_mode` — select `theme / transparent / custom`, default `theme`.
- `tile_bg_color` — color, shown when `tile_bg_mode = custom`.
- `tile_hover_bg_color` — color (optional override, empty = use theme).

Tile box:

- `tile_padding` — number px, 0–48, default uses CSS fallback (current value).
- `tile_border_width` — number px, 0–8, default 0.
- `tile_border_color` — color.
- `tile_border_style` — select `solid / dashed / dotted / none`.

Title:

- `title_color` — color.
- `title_size` — number px, 10–28.
- `title_weight` — select `300 / 400 / 500 / 600 / 700`.
- `title_transform` — select `none / uppercase / lowercase / capitalize`.
- `title_align` — select `left / center / right`.

Caption:

- `caption_color` — color.
- `caption_size` — number px, 10–22.

Play button:

- `play_button_color` — color (icon fill).
- `play_button_bg_color` — color (button bg).
- `play_button_size` — number px, 24–96.

Overlay tile style:

- `overlay_gradient_strength` — number 0–100, default 85, drives the alpha of the bottom gradient stop (applies only when `tile_style = overlay`).

### 4.3 Behavior tab

- `transition_duration_ms` — number, 50–800, default 200. Drives a new `--avg-transition` var consumed by tile/thumb/title/button transitions.

### 4.4 Advanced tab — expose every CSS var + Custom CSS

Renders as collapsible groups:

**Colors group** — color picker per var. Empty = use theme default.

- `--avg-bg` ("Tile Background")
- `--avg-bg-hover` ("Tile Hover Background")
- `--avg-text` ("Text Color")
- `--avg-text-muted` ("Muted Text Color")
- `--avg-border` ("Border Color")
- `--avg-overlay` ("Overlay Color")
- `--avg-play-bg` ("Play Button Background")
- `--avg-play-color` ("Play Button Color")

**Sizes / Misc group** — any remaining raw vars not surfaced in friendlier tabs. (Most are already exposed via existing controls; this group is mostly future-proofing.)

**Custom CSS** — single `textarea`, with the gallery's `#avs-XXXX` id displayed next to it.

### 4.5 Specificity & precedence

1. CSS file (fallbacks via `var(--avg-X, default)`).
2. Friendly Style-tab controls (emitted in inline `style="..."`).
3. Advanced-tab raw vars (emitted later in the same inline `style="..."`, so they win on duplicates).
4. Custom CSS (`<style>` block emitted immediately before the gallery wrapper). Practical override behavior:
   - User CSS that **redefines a CSS variable on the wrapper** (e.g. `#avs-XXXX { --avg-bg: red; }`) is shadowed by the inline `style=""` declarations on the same element, because inline styles beat stylesheet selectors. To force a var override, use `!important` (e.g. `#avs-XXXX { --avg-bg: red !important; }`).
   - User CSS that **targets downstream properties** (e.g. `#avs-XXXX .avg-tile { background: red; }`) wins normally on selector specificity (`#id .class` = 101 beats `.class` = 10 in the bundled stylesheet) and needs no `!important`.
   - This nuance is mentioned in the field's help text.

---

## 5. Migration

One-shot option flag: `anchor_gallery_title_position_migrated_v37`.

On first load after upgrading to v3.7.0, run a migration that iterates every `anchor_video_gallery` post:

- If `avg_show_title` is `'0'` / falsy **and** `avg_title_position` is unset → set `avg_title_position = 'hidden'`.
- If `avg_show_title` is `'1'` / truthy **and** `avg_title_position` is unset or empty → set `avg_title_position = 'below'`.
- Leave `avg_show_title` meta in place (do not delete).

After migration, the renderer ignores `avg_show_title` entirely.

---

## 6. Files affected

- `anchor-gallery/anchor-gallery.php`
  - `get_setting_defs()` — add new fields, mark `show_title` deprecated (remove from `get_settings_by_section()` output).
  - `get_default_settings()` (or wherever defaults live) — defaults for all new keys; new visual defaults must be empty/zero to preserve current rendering.
  - `save_meta()` — add sanitize cases for `color` and `textarea` types; size-cap Custom CSS.
  - `render_box_settings()` — render new field types.
  - `render_pane_section()` — group Advanced tab into collapsibles (Colors / Sizes / Custom CSS).
  - `render_output()` — emit new CSS vars in `$style_vars`; drop `show_title` reads; emit Custom CSS `<style>` block.
  - New helper `emit_custom_css_block(string $uid, string $css): string`.
  - New migration block, gated on `anchor_gallery_title_position_migrated_v37`, fired on `plugins_loaded`.

- `anchor-gallery/assets/anchor-video-slider.css`
  - Replace hardcoded values touched by new controls with `var(--avg-X, fallback)`.
  - Add new hover effect classes: `avg-hover-tilt`, `avg-hover-fade`, `avg-hover-slide-up`, `avg-hover-brighten`, `avg-hover-desaturate`, plus intensity modifiers.
  - Bump asset version string in `wp_enqueue_*()`.

- `anchor-gallery/assets/admin.js`
  - Render `color` fields via `wp.colorPicker` (already bundled in WP core; enqueue `wp-color-picker` + `wp-color-picker` CSS in `enqueue_admin_assets()`).
  - Render `textarea` fields.
  - Hook color picker change event into debounced AJAX preview.

- `anchor-gallery/assets/admin.css`
  - Style for Advanced tab collapsibles, Custom CSS textarea, gallery-id display chip.

- `anchor-tools.php`
  - Bump version header to 3.7.0.

---

## 7. Sanitization summary

- `color` — regex match hex 3/6 or `rgb(a)`. Reject otherwise, fall back to empty.
- `textarea` (Custom CSS) — `wp_strip_all_tags(`...`, true)` is too aggressive (would kill `{}`). Instead: strip case-insensitive `</style>`, strip `<script>...</script>` blocks, cap at 10 KB. Stored as-is, escaped only on output (`echo` inside `<style>` tag is fine; the strip rules prevent breakouts).
- `number` — existing pattern (intval + clamp by min/max).
- `select` — existing pattern (must match a defined option key).

---

## 8. Testing

No automated suite exists. Manual smoke matrix:

1. **No-touch upgrade.** Pre-upgrade gallery snapshot (5 layouts × 3 themes). After upgrade with zero edits, verify rendered HTML/CSS produces identical visual output.
2. **Migration.** Pre-create galleries with `show_title=false, title_position` unset, and `show_title=true, title_position` unset. After upgrade verify `title_position` is `'hidden'` and `'below'` respectively.
3. **Each new control.** For two layouts (grid + carousel), step through every new field and verify the corresponding CSS var is emitted and visually applied.
4. **Advanced override wins.** Set a Style-tab color and an Advanced-tab raw var override of the same property. Confirm Advanced wins.
5. **Custom CSS.** Drop in `#avs-XXXX .avg-title { color: red; }`. Confirm only this gallery's titles turn red, not others on the page.
6. **Strip-to-nothing.** Apply the `simple_slider` preset, then clear every field back to empty/zero. Confirm slider still works (JS-wise: arrows, autoplay, popup) but rendering is visually minimal.

---

## 9. Out of scope (v1)

- Auto-scoping of Custom CSS rules.
- Popup-specific styling per-gallery (popup renders outside the wrapper).
- Per-breakpoint overrides for new visual controls (responsive tab keeps existing controls only).
- Visual control of marquee/logo-carousel beyond what already exists.
- Migration of `avg_show_title` meta deletion. (Left in DB intentionally; can be cleaned up in a later release.)

---

## 10. Release

- Version bump: `anchor-tools.php` `Version:` header → `3.7.0`.
- Commit, push to `main`, tag a GitHub release. Plugin Update Checker handles distribution.
