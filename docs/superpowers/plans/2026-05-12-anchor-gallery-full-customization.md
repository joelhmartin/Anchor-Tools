# Anchor Gallery — Full Customization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose nearly every visual property of the Anchor Gallery module as an editable per-post control, fix the `show_title`/`title_position` bug, expand `hover_effect`, and add a per-gallery Advanced tab + Custom CSS block — without changing the rendered output for untouched galleries.

**Architecture:** Every new visual control becomes a CSS custom property emitted in the gallery wrapper's inline `style=""` attribute. The bundled CSS is refactored to consume `var(--avg-X, fallback)` where the fallback equals today's value. Custom CSS is emitted in a `<style>` block before the wrapper, with user-managed `#avs-XXXX` scoping.

**Tech Stack:** PHP 7.4+ / WordPress 6.x, jQuery, raw CSS, `wp-color-picker` (WP core). No build tools. No automated test suite — verification is manual smoke testing in a live WP environment.

**Spec:** `docs/superpowers/specs/2026-05-12-anchor-gallery-full-customization-design.md`

**Pre-existing capabilities (already in code, no work needed):**
- `Anchor_Builder_Shell::render_field()` already supports `color` and `textarea` field types (lines 163–168 of `includes/builder/class-anchor-builder-shell.php`).
- The schema docblock already lists `color` and `textarea` as supported types (line 102 of `anchor-gallery/anchor-gallery.php`).

**Pre-existing gaps to plug:**
- `save_meta()` (around line 1042 of `anchor-gallery/anchor-gallery.php`) treats all non-`select`/`number`/`checkbox` types as `sanitize_text_field()`, which strips newlines — wrong for textareas.
- `wp-color-picker` JS/CSS is not enqueued and color-picker `<input>`s are never wired to `wp.wpColorPicker()`.
- The bundled CSS hardcodes values that need to become `var(--avg-X, …)`.

---

## File Structure

Files modified (none created):

- `anchor-gallery/anchor-gallery.php`
  - `get_setting_defs()` — add new field definitions, mark `show_title` deprecated by removing it from the schema.
  - `default_settings` property — add defaults for every new key.
  - `save_meta()` — add `color` and `textarea` sanitization branches.
  - `render_output()` — emit new CSS vars, drop `show_title` reads, emit Custom CSS `<style>` block, prepend a gallery-id chip to the wrapper.
  - `__construct()` and a new `migrate_title_position_v37()` method — run the one-shot meta migration.
  - `enqueue_admin_assets()` — enqueue `wp-color-picker` style + script.

- `anchor-gallery/assets/anchor-video-slider.css`
  - Replace hardcoded values touched by new controls with `var(--avg-X, fallback)`.
  - Add new hover-effect classes (`avg-hover-tilt`, `avg-hover-fade`, `avg-hover-slide-up`, `avg-hover-brighten`, `avg-hover-desaturate`).
  - Add hover-intensity modifiers (`avg-hover-subtle`, `avg-hover-strong`).

- `anchor-gallery/assets/admin.js`
  - Attach `wp.wpColorPicker()` to `.anchor-builder__color-picker` on `document.ready`.

- `anchor-gallery/assets/admin.css`
  - Style the gallery-id chip displayed next to Custom CSS textarea (label augmentation).

- `anchor-tools.php`
  - Bump `Version:` header to `3.7.0`.

---

## Task 1: Pre-flight safety commit

**Files:** none (snapshot)

- [ ] **Step 1: Confirm clean working tree**

Run: `git status`
Expected: `nothing to commit, working tree clean` (or your in-progress files are listed; if so, stop and commit them first).

- [ ] **Step 2: Snapshot the current rendered output of a test gallery**

In WP admin, open one existing Anchor Gallery post in the front-end and save view-source HTML + a screenshot. Place both in `/tmp/avg-pre-upgrade/` (a scratch directory, do not commit). This is your byte-identical baseline for Task 12.

- [ ] **Step 3: Create a feature branch**

Run: `git checkout -b feature/gallery-full-customization`
Expected: `Switched to a new branch 'feature/gallery-full-customization'`

---

## Task 2: Add `save_meta()` sanitization branches for `color` and `textarea`

**Files:**
- Modify: `anchor-gallery/anchor-gallery.php` (around line 1028–1046)

- [ ] **Step 1: Read the current save loop**

Run: `sed -n '1027,1047p' anchor-gallery/anchor-gallery.php`
Expected output (current code):

```php
$defs = $this->get_setting_defs();
foreach ($defs as $key => $def) {
    $meta_key = 'avg_' . $key;
    if ($def['type'] === 'checkbox') {
        $val = isset($_POST[$meta_key]) ? '1' : '0';
    } elseif ($def['type'] === 'number') {
        $val = isset($_POST[$meta_key]) ? intval($_POST[$meta_key]) : $this->default_settings[$key];
        if (isset($def['min'])) $val = max($def['min'], $val);
        if (isset($def['max'])) $val = min($def['max'], $val);
    } elseif ($def['type'] === 'select') {
        $val = isset($_POST[$meta_key]) ? sanitize_text_field($_POST[$meta_key]) : $this->default_settings[$key];
        if (isset($def['options']) && !array_key_exists($val, $def['options'])) {
            $val = $this->default_settings[$key];
        }
    } else {
        $val = isset($_POST[$meta_key]) ? sanitize_text_field($_POST[$meta_key]) : '';
    }
    update_post_meta($post_id, $meta_key, $val);
}
```

- [ ] **Step 2: Replace the loop with branches for `color` and `textarea`**

Use the Edit tool to replace the `else { ... }` block at the end with three new branches. Final loop body:

```php
$defs = $this->get_setting_defs();
foreach ($defs as $key => $def) {
    $meta_key = 'avg_' . $key;
    if ($def['type'] === 'checkbox') {
        $val = isset($_POST[$meta_key]) ? '1' : '0';
    } elseif ($def['type'] === 'number') {
        $val = isset($_POST[$meta_key]) ? intval($_POST[$meta_key]) : ($this->default_settings[$key] ?? 0);
        if (isset($def['min'])) $val = max($def['min'], $val);
        if (isset($def['max'])) $val = min($def['max'], $val);
    } elseif ($def['type'] === 'select') {
        $val = isset($_POST[$meta_key]) ? sanitize_text_field($_POST[$meta_key]) : ($this->default_settings[$key] ?? '');
        if (isset($def['options']) && !array_key_exists($val, $def['options'])) {
            $val = $this->default_settings[$key] ?? '';
        }
    } elseif ($def['type'] === 'color') {
        $raw = isset($_POST[$meta_key]) ? trim((string) wp_unslash($_POST[$meta_key])) : '';
        if ($raw === '') {
            $val = '';
        } elseif (preg_match('/^#([0-9a-fA-F]{3}){1,2}$/', $raw)) {
            $val = $raw;
        } elseif (preg_match('/^rgba?\(\s*[\d.\s,%]+\s*\)$/', $raw)) {
            $val = $raw;
        } else {
            $val = '';
        }
    } elseif ($def['type'] === 'textarea') {
        $raw = isset($_POST[$meta_key]) ? (string) wp_unslash($_POST[$meta_key]) : '';
        // Strip <script>...</script> blocks and any closing </style> to prevent breakouts.
        $raw = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $raw);
        $raw = str_ireplace('</style>', '', $raw);
        // Cap at 10 KB.
        if (strlen($raw) > 10240) $raw = substr($raw, 0, 10240);
        $val = $raw;
    } else {
        $val = isset($_POST[$meta_key]) ? sanitize_text_field($_POST[$meta_key]) : '';
    }
    update_post_meta($post_id, $meta_key, $val);
}
```

- [ ] **Step 3: Lint-check PHP syntax**

Run: `php -l anchor-gallery/anchor-gallery.php`
Expected: `No syntax errors detected in anchor-gallery/anchor-gallery.php`

- [ ] **Step 4: Commit**

```bash
git add anchor-gallery/anchor-gallery.php
git commit -m "feat(gallery): add color + textarea sanitizers in save_meta"
```

---

## Task 3: Enqueue `wp-color-picker` and wire it up

**Files:**
- Modify: `anchor-gallery/anchor-gallery.php` (around line 1095–1099 in `enqueue_admin_assets`)
- Modify: `anchor-gallery/assets/admin.js` (top-level IIFE)

- [ ] **Step 1: Add color-picker enqueue calls in `enqueue_admin_assets`**

Use Edit to add inside the `if (($hook === 'post-new.php' …)` block, right after `wp_enqueue_media();`:

```php
wp_enqueue_style('wp-color-picker');
wp_enqueue_script('wp-color-picker');
```

- [ ] **Step 2: Add color-picker initializer to `admin.js`**

Open `anchor-gallery/assets/admin.js`. Find the existing jQuery IIFE wrapper `(function($) { ... })(jQuery);`. Inside it, add — at the bottom, just before the closing `})(jQuery);`:

```javascript
// Attach WP color picker to any .anchor-builder__color-picker inputs.
$(function() {
    if (typeof $.fn.wpColorPicker !== 'function') return;
    $('.anchor-builder__color-picker').each(function() {
        var $input = $(this);
        $input.wpColorPicker({
            change: function() {
                // Debounce: trigger preview refresh via existing change handler.
                setTimeout(function() { $input.trigger('change'); }, 50);
            },
            clear: function() {
                $input.trigger('change');
            }
        });
    });
});
```

- [ ] **Step 3: Reload the gallery edit screen and confirm color pickers render**

Manually: open a gallery post in `wp-admin`. (Note: no color fields exist yet — they'll be added in Task 5 — so this step has nothing to verify visually. The Step is here to confirm no JS errors.) Open browser devtools console; expected: no errors.

- [ ] **Step 4: Lint + commit**

```bash
php -l anchor-gallery/anchor-gallery.php
git add anchor-gallery/anchor-gallery.php anchor-gallery/assets/admin.js
git commit -m "feat(gallery): enqueue wp-color-picker and wire up initializer"
```

---

## Task 4: Refactor bundled CSS to use `var(--avg-X, fallback)`

**Files:**
- Modify: `anchor-gallery/assets/anchor-video-slider.css`

This task replaces hardcoded values with var-with-fallback so future controls can override them without changing default output. Each sub-step is one logical group.

- [ ] **Step 1: Title typography**

Find the `.avg-title` rule (around line 107). Current:

```css
.avg-title {
  font-weight: 600;
  color: var(--avg-text);
  ...
}
```

Replace the relevant declarations so the rule looks like:

```css
.avg-title {
  font-size: var(--avg-title-size, 14px);
  font-weight: var(--avg-title-weight, 600);
  color: var(--avg-title-color, var(--avg-text));
  text-transform: var(--avg-title-transform, none);
  text-align: var(--avg-title-align, left);
  line-height: 1.4;
  margin: 0;
}
```

Keep any other declarations (line-height, margin) that were already there. If a property doesn't exist in the original rule, add it.

- [ ] **Step 2: Caption typography**

Find the `.avg-caption` rule. Add/replace:

```css
.avg-caption {
  font-size: var(--avg-caption-size, 12px);
  color: var(--avg-caption-color, var(--avg-text-muted));
}
```

- [ ] **Step 3: Tile box (padding + border)**

Find the `.avg-tile-inner` (or `.avg-tile`) rule that defines the tile card container (look for the `border-radius: var(--avg-radius)` line — that's the right rule). Add to it:

```css
padding: var(--avg-tile-padding, 0);
border-width: var(--avg-tile-border-width, 0);
border-style: var(--avg-tile-border-style, solid);
border-color: var(--avg-tile-border-color, transparent);
```

- [ ] **Step 4: Tile background mode**

Find the `.avg-tile-inner` rule that uses `background: var(--avg-bg);` (around line 73). Change to:

```css
background: var(--avg-tile-bg, var(--avg-bg));
```

And the hover rule:

```css
.avg-tile:hover .avg-tile-inner { background: var(--avg-tile-hover-bg, var(--avg-bg-hover)); }
```

- [ ] **Step 5: Play button**

Find the play-button rule(s) (`.avg-play`). Change the existing `background: var(--avg-play-bg);` and color/size:

```css
.avg-play {
  width:  var(--avg-play-size, 48px);
  height: var(--avg-play-size, 48px);
  background: var(--avg-play-button-bg, var(--avg-play-bg));
  color: var(--avg-play-button-color, var(--avg-play-color));
}
```

- [ ] **Step 6: Overlay gradient strength**

Find the rule at ~line 244: `background: linear-gradient(to top, rgba(0,0,0,0.85) 0%, transparent 100%);`. Replace with:

```css
background: linear-gradient(to top, rgba(0, 0, 0, var(--avg-overlay-strength, 0.85)) 0%, transparent 100%);
```

- [ ] **Step 7: Global transition duration**

Add at the top of the file (just below the `:root` block) a global:

```css
.anchor-video-gallery { --avg-transition: 200ms; }
```

Then audit existing `transition:` declarations across the file — replace hardcoded `0.2s` and `200ms` values with `var(--avg-transition, 200ms)`. Search-and-replace:

Run: `grep -n "transition:" anchor-gallery/assets/anchor-video-slider.css`
For each line that uses a hardcoded `0.2s` or `200ms` in the transition shorthand, replace that duration with `var(--avg-transition, 200ms)`. Leave longer/shorter durations (e.g. `0.4s` on hover-glow) alone.

- [ ] **Step 8: Lint-check CSS (no parser; eyeball-only)**

Run: `grep -c "var(--avg-" anchor-gallery/assets/anchor-video-slider.css`
Expected: count is meaningfully higher than before. (For context, a quick `grep -c "var(--avg-" anchor-gallery/assets/anchor-video-slider.css` before this task gave ~30. After should be ~45+.)

- [ ] **Step 9: Commit**

```bash
git add anchor-gallery/assets/anchor-video-slider.css
git commit -m "refactor(gallery): use var(--avg-X, fallback) for customizable visual properties"
```

---

## Task 5: Add new hover-effect classes and intensity modifiers

**Files:**
- Modify: `anchor-gallery/assets/anchor-video-slider.css`

- [ ] **Step 1: Append new hover classes at the end of the hover-effects section**

Find the existing block starting around line 192 (`.avg-hover-lift …`). After the existing 4 classes (`lift`, `zoom`, `glow`, `none`), append:

```css
/* Tilt */
.avg-hover-tilt .avg-tile { transition: transform var(--avg-transition, 200ms) ease; transform-style: preserve-3d; }
.avg-hover-tilt .avg-tile:hover,
.avg-hover-tilt .avg-tile:focus { transform: perspective(800px) rotateY(var(--avg-hover-tilt-y, 4deg)) rotateX(var(--avg-hover-tilt-x, -2deg)); }

/* Fade */
.avg-hover-fade .avg-tile { transition: opacity var(--avg-transition, 200ms) ease; }
.avg-hover-fade .avg-tile:hover ~ .avg-tile,
.avg-hover-fade:hover .avg-tile:not(:hover):not(:focus) { opacity: var(--avg-hover-fade-other, 0.5); }

/* Slide-up */
.avg-hover-slide-up .avg-tile { transition: transform var(--avg-transition, 200ms) ease; }
.avg-hover-slide-up .avg-tile:hover,
.avg-hover-slide-up .avg-tile:focus { transform: translateY(var(--avg-hover-slide-distance, -6px)); }

/* Brighten */
.avg-hover-brighten .avg-thumb { transition: filter var(--avg-transition, 200ms) ease; }
.avg-hover-brighten .avg-tile:hover .avg-thumb,
.avg-hover-brighten .avg-tile:focus .avg-thumb { filter: brightness(var(--avg-hover-brightness, 1.15)); }

/* Desaturate (default greyscale, color on hover) */
.avg-hover-desaturate .avg-thumb { filter: grayscale(var(--avg-hover-desat-base, 1)); transition: filter var(--avg-transition, 200ms) ease; }
.avg-hover-desaturate .avg-tile:hover .avg-thumb,
.avg-hover-desaturate .avg-tile:focus .avg-thumb { filter: grayscale(0); }
```

- [ ] **Step 2: Append intensity modifiers**

Below the hover classes, append:

```css
/* Hover intensity modifiers — multipliers for the per-effect magnitude vars */
.avg-hover-subtle  { --avg-hover-tilt-y: 2deg;  --avg-hover-tilt-x: -1deg; --avg-hover-slide-distance: -3px;  --avg-hover-brightness: 1.07; --avg-hover-fade-other: 0.75; }
.avg-hover-strong  { --avg-hover-tilt-y: 7deg;  --avg-hover-tilt-x: -4deg; --avg-hover-slide-distance: -10px; --avg-hover-brightness: 1.25; --avg-hover-fade-other: 0.3;  }
```

(Default = "normal" = no class added; the per-effect vars provide the normal-intensity values via their fallbacks.)

- [ ] **Step 3: Commit**

```bash
git add anchor-gallery/assets/anchor-video-slider.css
git commit -m "feat(gallery): add tilt/fade/slide-up/brighten/desaturate hover effects + intensity modifiers"
```

---

## Task 6: Schema — add new field definitions

**Files:**
- Modify: `anchor-gallery/anchor-gallery.php` — `get_setting_defs()` (line 118+), `default_settings` (line 30+)

- [ ] **Step 1: Add defaults to the `$default_settings` array**

Use Edit to insert these keys into `$default_settings` (alphabetical or any consistent ordering — append at the bottom of the array, before the closing `]`):

```php
// 3.7.0 — full customization
'show_caption'            => true,
'hover_intensity'         => 'normal',
'hover_duration_ms'       => 200,
'tile_bg_mode'            => 'theme',
'tile_bg_color'           => '',
'tile_hover_bg_color'     => '',
'tile_padding'            => 0,
'tile_border_width'       => 0,
'tile_border_color'       => '',
'tile_border_style'       => 'solid',
'title_color'             => '',
'title_size'              => 0,
'title_weight'            => '600',
'title_transform'         => 'none',
'title_align'             => 'left',
'caption_color'           => '',
'caption_size'            => 0,
'play_button_color'       => '',
'play_button_bg_color'    => '',
'play_button_size'        => 0,
'overlay_gradient_strength' => 85,
'transition_duration_ms'  => 200,
// Advanced raw CSS-var overrides (empty = use friendly control / theme default)
'css_var_bg'              => '',
'css_var_bg_hover'        => '',
'css_var_text'            => '',
'css_var_text_muted'      => '',
'css_var_border'          => '',
'css_var_overlay'         => '',
'css_var_play_bg'         => '',
'css_var_play_color'      => '',
'custom_css'              => '',
```

Notes:
- `title_size`, `caption_size`, `play_button_size` use `0` as "unset" — emitter checks `> 0` before emitting the var.
- `hover_duration_ms` and `transition_duration_ms` default to `200` (the CSS fallback value); emitter still emits even when default, since the inline style winning is harmless.

- [ ] **Step 2: Update `default_settings['show_title']`**

Remove the line `'show_title' => true,` from `$default_settings` and from the schema in Step 3 below. The setting is deprecated.

- [ ] **Step 3: Update `get_setting_defs()` — remove `show_title`, expand `hover_effect`**

Find:

```php
'hover_effect' => ['type' => 'select', 'label' => 'Hover Effect', 'section' => 'style', 'options' => ['lift' => 'Lift', 'zoom' => 'Zoom', 'glow' => 'Glow', 'none' => 'None'], 'applies_to' => $tile_layouts],
```

Replace with:

```php
'hover_effect' => ['type' => 'select', 'label' => 'Hover Effect', 'section' => 'style', 'options' => [
    'none' => 'None', 'lift' => 'Lift', 'zoom' => 'Zoom', 'glow' => 'Glow',
    'tilt' => 'Tilt', 'fade' => 'Fade Others', 'slide-up' => 'Slide Up',
    'brighten' => 'Brighten', 'desaturate' => 'Desaturate (color on hover)',
], 'applies_to' => $tile_layouts],
```

Then find:

```php
'show_title' => ['type' => 'checkbox', 'label' => 'Show Title', 'section' => 'content', 'priority' => 10, 'applies_to' => $core_layouts],
```

Delete that entire line. (Migration in Task 8 maps existing values into `title_position`.)

- [ ] **Step 4: Append new field definitions to the schema**

In `get_setting_defs()`, just before the closing `];`, append:

```php
/* ── 3.7.0: Content additions ─────────────────────────────── */
'show_caption' => ['type' => 'checkbox', 'label' => 'Show Caption', 'section' => 'content', 'priority' => 30, 'applies_to' => $core_layouts],

/* ── 3.7.0: Style — hover ─────────────────────────────────── */
'hover_intensity' => ['type' => 'select', 'label' => 'Hover Intensity', 'section' => 'style', 'options' => ['subtle' => 'Subtle', 'normal' => 'Normal', 'strong' => 'Strong'], 'applies_to' => $tile_layouts, 'depends_on' => ['hover_effect' => ['lift','zoom','glow','tilt','fade','slide-up','brighten','desaturate']]],
'hover_duration_ms' => ['type' => 'number', 'label' => 'Hover Duration (ms)', 'section' => 'style', 'min' => 50, 'max' => 1000, 'step' => 10, 'applies_to' => $tile_layouts],

/* ── 3.7.0: Style — tile background ───────────────────────── */
'tile_bg_mode'        => ['type' => 'select', 'label' => 'Tile Background', 'section' => 'style', 'options' => ['theme' => 'Use Theme', 'transparent' => 'Transparent', 'custom' => 'Custom Color'], 'applies_to' => $tile_layouts],
'tile_bg_color'       => ['type' => 'color',  'label' => 'Tile Background Color', 'section' => 'style', 'applies_to' => $tile_layouts, 'depends_on' => ['tile_bg_mode' => 'custom']],
'tile_hover_bg_color' => ['type' => 'color',  'label' => 'Tile Hover Background', 'section' => 'style', 'applies_to' => $tile_layouts],

/* ── 3.7.0: Style — tile box ──────────────────────────────── */
'tile_padding'       => ['type' => 'number', 'label' => 'Tile Padding (px)', 'section' => 'style', 'min' => 0, 'max' => 48, 'step' => 1, 'applies_to' => $tile_layouts],
'tile_border_width'  => ['type' => 'number', 'label' => 'Tile Border Width (px)', 'section' => 'style', 'min' => 0, 'max' => 8, 'step' => 1, 'applies_to' => $tile_layouts],
'tile_border_color'  => ['type' => 'color',  'label' => 'Tile Border Color', 'section' => 'style', 'applies_to' => $tile_layouts],
'tile_border_style'  => ['type' => 'select', 'label' => 'Tile Border Style', 'section' => 'style', 'options' => ['solid' => 'Solid', 'dashed' => 'Dashed', 'dotted' => 'Dotted', 'none' => 'None'], 'applies_to' => $tile_layouts],

/* ── 3.7.0: Style — title typography ──────────────────────── */
'title_color'     => ['type' => 'color',  'label' => 'Title Color', 'section' => 'style', 'applies_to' => $tile_layouts],
'title_size'      => ['type' => 'number', 'label' => 'Title Size (px, 0=auto)', 'section' => 'style', 'min' => 0, 'max' => 28, 'step' => 1, 'applies_to' => $tile_layouts],
'title_weight'    => ['type' => 'select', 'label' => 'Title Weight', 'section' => 'style', 'options' => ['300' => 'Light', '400' => 'Normal', '500' => 'Medium', '600' => 'Semi-bold', '700' => 'Bold'], 'applies_to' => $tile_layouts],
'title_transform' => ['type' => 'select', 'label' => 'Title Transform', 'section' => 'style', 'options' => ['none' => 'None', 'uppercase' => 'UPPERCASE', 'lowercase' => 'lowercase', 'capitalize' => 'Capitalize'], 'applies_to' => $tile_layouts],
'title_align'     => ['type' => 'select', 'label' => 'Title Align', 'section' => 'style', 'options' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right'], 'applies_to' => $tile_layouts],

/* ── 3.7.0: Style — caption typography ────────────────────── */
'caption_color' => ['type' => 'color',  'label' => 'Caption Color', 'section' => 'style', 'applies_to' => $tile_layouts],
'caption_size'  => ['type' => 'number', 'label' => 'Caption Size (px, 0=auto)', 'section' => 'style', 'min' => 0, 'max' => 22, 'step' => 1, 'applies_to' => $tile_layouts],

/* ── 3.7.0: Style — play button ───────────────────────────── */
'play_button_color'    => ['type' => 'color',  'label' => 'Play Button Color', 'section' => 'style', 'applies_to' => $tile_layouts],
'play_button_bg_color' => ['type' => 'color',  'label' => 'Play Button Background', 'section' => 'style', 'applies_to' => $tile_layouts],
'play_button_size'     => ['type' => 'number', 'label' => 'Play Button Size (px, 0=auto)', 'section' => 'style', 'min' => 0, 'max' => 96, 'step' => 2, 'applies_to' => $tile_layouts],

/* ── 3.7.0: Style — overlay tile gradient strength ────────── */
'overlay_gradient_strength' => ['type' => 'number', 'label' => 'Overlay Gradient Strength (0-100)', 'section' => 'style', 'min' => 0, 'max' => 100, 'step' => 5, 'applies_to' => $tile_layouts, 'depends_on' => ['tile_style' => 'overlay'], 'help' => 'Alpha of the bottom gradient stop on the overlay tile style.'],

/* ── 3.7.0: Behavior ──────────────────────────────────────── */
'transition_duration_ms' => ['type' => 'number', 'label' => 'Transition Duration (ms)', 'section' => 'behavior', 'min' => 50, 'max' => 800, 'step' => 10, 'help' => 'Global tile / thumb / title transition speed.'],

/* ── 3.7.0: Advanced — raw CSS var overrides ──────────────── */
'css_var_bg'         => ['type' => 'color', 'label' => 'Override: Tile Background (--avg-bg)', 'section' => 'advanced'],
'css_var_bg_hover'   => ['type' => 'color', 'label' => 'Override: Tile Hover Background (--avg-bg-hover)', 'section' => 'advanced'],
'css_var_text'       => ['type' => 'color', 'label' => 'Override: Text Color (--avg-text)', 'section' => 'advanced'],
'css_var_text_muted' => ['type' => 'color', 'label' => 'Override: Muted Text (--avg-text-muted)', 'section' => 'advanced'],
'css_var_border'     => ['type' => 'color', 'label' => 'Override: Border Color (--avg-border)', 'section' => 'advanced'],
'css_var_overlay'    => ['type' => 'color', 'label' => 'Override: Overlay (--avg-overlay)', 'section' => 'advanced'],
'css_var_play_bg'    => ['type' => 'color', 'label' => 'Override: Play BG (--avg-play-bg)', 'section' => 'advanced'],
'css_var_play_color' => ['type' => 'color', 'label' => 'Override: Play Color (--avg-play-color)', 'section' => 'advanced'],

/* ── 3.7.0: Advanced — custom CSS ─────────────────────────── */
'custom_css' => ['type' => 'textarea', 'label' => 'Custom CSS', 'section' => 'advanced', 'help' => 'Use #avs-XXXX (this gallery\'s id, shown in the rendered HTML) to scope rules. Redefining a CSS variable on the wrapper needs !important (e.g. #avs-1 { --avg-bg: red !important; }). Targeting child elements (e.g. #avs-1 .avg-tile { ... }) does not.'],
```

- [ ] **Step 5: Lint-check**

Run: `php -l anchor-gallery/anchor-gallery.php`
Expected: `No syntax errors detected …`

- [ ] **Step 6: Commit**

```bash
git add anchor-gallery/anchor-gallery.php
git commit -m "feat(gallery): add 3.7.0 schema fields for full customization"
```

---

## Task 7: `render_output()` — emit new CSS vars + Custom CSS block

**Files:**
- Modify: `anchor-gallery/anchor-gallery.php` (lines ~1391–1519, the `render_output()` method)

This is the largest task. Done in small chunks so each commit is reviewable.

- [ ] **Step 1: Drop `show_title` reads from the title/meta logic**

Find lines around 1622–1645 (the `$show_meta_below` logic). Replace the block:

```php
$show_meta_below = false;
if ($title_position === 'below' && !empty($video['label'])) {
    $show_meta_below = true;
} elseif ($title_position === 'hidden' && ($settings['show_title'] || $settings['show_channel'])) {
    $show_meta_below = true;
}
```

With:

```php
// 3.7.0: title_position is now the single source of truth for title visibility.
// `show_title` is deprecated (auto-migrated into title_position) and no longer read here.
$show_meta_below = false;
if ($title_position === 'below' && !empty($video['label'])) {
    $show_meta_below = true;
} elseif ($title_position === 'hidden' && !empty($settings['show_channel'])) {
    $show_meta_below = true;
}
```

Then find the inner `if ($title_position === 'below')` / `else` block right after that and simplify the `else` branch:

```php
<?php if ($title_position === 'below'): ?>
<span class="avg-title"><?php echo esc_html($video['label']); ?></span>
<?php else: ?>
    <?php if ($settings['show_title']): ?>
    <span class="avg-title"><?php echo esc_html($video['label']); ?></span>
    <?php endif; ?>
    <?php if ($settings['show_channel'] && !empty($video['channel'])): ?>
    <span class="avg-channel"><?php echo esc_html($video['channel']); ?></span>
    <?php endif; ?>
<?php endif; ?>
```

Becomes:

```php
<?php if ($title_position === 'below'): ?>
<span class="avg-title"><?php echo esc_html($video['label']); ?></span>
<?php elseif ($settings['show_channel'] && !empty($video['channel'])): ?>
<span class="avg-channel"><?php echo esc_html($video['channel']); ?></span>
<?php endif; ?>
```

(In `title_position='hidden'` mode, only the channel is rendered below — `show_title` is gone.)

- [ ] **Step 2: Gate caption rendering on `show_caption`**

In the same `render_output()` method, find the two caption render sites (search `avg-caption-wrap`). Each currently looks like `<?php if ( ! empty( $video['caption'] ) ) : ?>`. Change both to:

```php
<?php if ( ! empty( $video['caption'] ) && ! empty( $settings['show_caption'] ) ) : ?>
```

- [ ] **Step 3: Add hover_effect / hover_intensity to wrapper classes**

Find the `$classes` array near line 1393:

```php
$classes = [
    'anchor-video-gallery',
    'avg-layout-' . $layout,
    'avg-theme-' . $settings['theme'],
    'avg-tiles-' . $settings['tile_style'],
    'avg-hover-' . $settings['hover_effect'],
    'avg-play-' . $settings['play_button_style'],
];
```

Add (right after the existing entries, before the closing `]`):

```php
];
$hover_intensity = $settings['hover_intensity'] ?? 'normal';
if (in_array($hover_intensity, ['subtle', 'strong'], true)) {
    $classes[] = 'avg-hover-' . $hover_intensity;
}
$tile_bg_mode = $settings['tile_bg_mode'] ?? 'theme';
if ($tile_bg_mode === 'transparent') {
    $classes[] = 'avg-tile-bg-transparent';
}
```

(Note: `avg-tile-bg-transparent` is a class that forces `--avg-tile-bg: transparent` — added in Task 4 as well; if you didn't add it, add this rule now to the CSS:

```css
.avg-tile-bg-transparent { --avg-tile-bg: transparent; --avg-tile-hover-bg: transparent; }
```

near the top of `anchor-video-slider.css`.)

- [ ] **Step 4: Emit new CSS variables in `$style_vars`**

Find the `$style_vars` array around line 1467 and the `$max_height` block right after it (around line 1478). Right after that block — before the `$shadow = !empty(...)` line at 1484 — insert:

```php
/* 3.7.0 — Style-tab visual overrides */

if (!empty($settings['hover_duration_ms']) && intval($settings['hover_duration_ms']) > 0) {
    $style_vars[] = '--avg-transition: ' . intval($settings['hover_duration_ms']) . 'ms';
}
if (!empty($settings['transition_duration_ms']) && intval($settings['transition_duration_ms']) > 0) {
    // transition_duration_ms wins over hover_duration_ms for the global var.
    $style_vars[] = '--avg-transition: ' . intval($settings['transition_duration_ms']) . 'ms';
}

// Tile background — only emit when mode = custom (transparent is handled by class).
if (($settings['tile_bg_mode'] ?? 'theme') === 'custom' && !empty($settings['tile_bg_color'])) {
    $style_vars[] = '--avg-tile-bg: ' . sanitize_text_field($settings['tile_bg_color']);
}
if (!empty($settings['tile_hover_bg_color'])) {
    $style_vars[] = '--avg-tile-hover-bg: ' . sanitize_text_field($settings['tile_hover_bg_color']);
}

// Tile box
if (intval($settings['tile_padding'] ?? 0) > 0) {
    $style_vars[] = '--avg-tile-padding: ' . intval($settings['tile_padding']) . 'px';
}
if (intval($settings['tile_border_width'] ?? 0) > 0) {
    $style_vars[] = '--avg-tile-border-width: ' . intval($settings['tile_border_width']) . 'px';
}
if (!empty($settings['tile_border_color'])) {
    $style_vars[] = '--avg-tile-border-color: ' . sanitize_text_field($settings['tile_border_color']);
}
if (!empty($settings['tile_border_style'])) {
    $style_vars[] = '--avg-tile-border-style: ' . sanitize_text_field($settings['tile_border_style']);
}

// Title typography
if (!empty($settings['title_color'])) {
    $style_vars[] = '--avg-title-color: ' . sanitize_text_field($settings['title_color']);
}
if (intval($settings['title_size'] ?? 0) > 0) {
    $style_vars[] = '--avg-title-size: ' . intval($settings['title_size']) . 'px';
}
if (!empty($settings['title_weight'])) {
    $style_vars[] = '--avg-title-weight: ' . sanitize_text_field($settings['title_weight']);
}
if (!empty($settings['title_transform']) && $settings['title_transform'] !== 'none') {
    $style_vars[] = '--avg-title-transform: ' . sanitize_text_field($settings['title_transform']);
}
if (!empty($settings['title_align'])) {
    $style_vars[] = '--avg-title-align: ' . sanitize_text_field($settings['title_align']);
}

// Caption typography
if (!empty($settings['caption_color'])) {
    $style_vars[] = '--avg-caption-color: ' . sanitize_text_field($settings['caption_color']);
}
if (intval($settings['caption_size'] ?? 0) > 0) {
    $style_vars[] = '--avg-caption-size: ' . intval($settings['caption_size']) . 'px';
}

// Play button
if (!empty($settings['play_button_color'])) {
    $style_vars[] = '--avg-play-button-color: ' . sanitize_text_field($settings['play_button_color']);
}
if (!empty($settings['play_button_bg_color'])) {
    $style_vars[] = '--avg-play-button-bg: ' . sanitize_text_field($settings['play_button_bg_color']);
}
if (intval($settings['play_button_size'] ?? 0) > 0) {
    $style_vars[] = '--avg-play-size: ' . intval($settings['play_button_size']) . 'px';
}

// Overlay gradient strength (only meaningful with overlay tile style)
if (isset($settings['overlay_gradient_strength']) && $settings['tile_style'] === 'overlay') {
    $alpha = max(0, min(100, intval($settings['overlay_gradient_strength']))) / 100;
    $style_vars[] = '--avg-overlay-strength: ' . $alpha;
}

/* 3.7.0 — Advanced raw CSS var overrides (emitted last so they win over friendly controls) */
$adv_map = [
    'css_var_bg'         => '--avg-bg',
    'css_var_bg_hover'   => '--avg-bg-hover',
    'css_var_text'       => '--avg-text',
    'css_var_text_muted' => '--avg-text-muted',
    'css_var_border'     => '--avg-border',
    'css_var_overlay'    => '--avg-overlay',
    'css_var_play_bg'    => '--avg-play-bg',
    'css_var_play_color' => '--avg-play-color',
];
foreach ($adv_map as $setting_key => $var_name) {
    if (!empty($settings[$setting_key])) {
        $style_vars[] = $var_name . ': ' . sanitize_text_field($settings[$setting_key]);
    }
}
```

- [ ] **Step 5: Emit Custom CSS `<style>` block before the wrapper**

Inside `render_output()`, after `ob_start();` and right before the opening `<div class="<?php echo …">`, add a custom-CSS block:

```php
<?php
$custom_css = isset($settings['custom_css']) ? (string) $settings['custom_css'] : '';
// Defensive re-strip in case meta was set programmatically.
$custom_css = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $custom_css);
$custom_css = str_ireplace('</style>', '', $custom_css);
if (trim($custom_css) !== ''):
?>
<style data-avg-uid="<?php echo esc_attr($uid); ?>"><?php echo $custom_css; ?></style>
<?php endif; ?>
```

(Note: we deliberately do NOT call `esc_html()` on `$custom_css` — it's raw CSS inside a `<style>` tag. The double-sanitization at save + emit time covers breakouts.)

- [ ] **Step 6: Lint + commit**

```bash
php -l anchor-gallery/anchor-gallery.php
git add anchor-gallery/anchor-gallery.php anchor-gallery/assets/anchor-video-slider.css
git commit -m "feat(gallery): emit new CSS vars + custom CSS block in render_output"
```

---

## Task 8: One-shot migration of `show_title` → `title_position`

**Files:**
- Modify: `anchor-gallery/anchor-gallery.php` — `__construct()` + new method

- [ ] **Step 1: Add `migrate_title_position_v37()` method**

Insert after the existing `migrate_cpt_slug()` method (around line 501), before the `/* Admin Columns */` comment:

```php
/* ══════════════════════════════════════════════════════════
   3.7.0 — One-shot show_title → title_position migration
   ══════════════════════════════════════════════════════════ */

private function migrate_title_position_v37() {
    if (get_option('anchor_gallery_title_position_migrated_v37')) return;

    $posts = get_posts([
        'post_type'      => [self::CPT, self::OLD_CPT],
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'suppress_filters' => true,
    ]);

    foreach ($posts as $pid) {
        $existing_pos = get_post_meta($pid, 'avg_title_position', true);
        if ($existing_pos !== '' && $existing_pos !== false) {
            // Already set — never overwrite.
            continue;
        }
        $show_title_raw = get_post_meta($pid, 'avg_show_title', true);
        // Treat truthy ('1', 1, true) as below; everything else (including '0', '', false) as hidden.
        $new_pos = ($show_title_raw === '1' || $show_title_raw === 1 || $show_title_raw === true)
            ? 'below'
            : 'hidden';
        update_post_meta($pid, 'avg_title_position', $new_pos);
    }

    update_option('anchor_gallery_title_position_migrated_v37', 1, false);
}
```

- [ ] **Step 2: Call the migration from `register_cpt`**

In `register_cpt()` (around line 456), after the two existing migration calls:

```php
$this->migrate_cpt_slug();
$this->migrate_legacy_data();
```

Add a third line:

```php
$this->migrate_title_position_v37();
```

- [ ] **Step 3: Lint + commit**

```bash
php -l anchor-gallery/anchor-gallery.php
git add anchor-gallery/anchor-gallery.php
git commit -m "feat(gallery): migrate show_title → title_position on upgrade to 3.7.0"
```

---

## Task 9: Admin asset polish (gallery-id chip)

**Files:**
- Modify: `anchor-gallery/assets/admin.css`
- Modify: `anchor-gallery/anchor-gallery.php` — `render_output()` adds a `data-avg-uid` HTML comment / chip near Custom CSS field

The Custom CSS field's help text already mentions `#avs-XXXX`. We want the actual UID displayed beside the field on the edit screen.

- [ ] **Step 1: Display the gallery's preview UID via JS-injected chip**

The post UID used at frontend render is generated at `render_gallery()` time (not the post ID). For the admin chip, we'll display the post ID instead — which the user can also use because the rendered wrapper id is `avg-{post_id}` for saved posts? Let's verify: in `render_gallery()` find how `$uid` is built. (Quick re-read step.)

Run: `grep -n 'uid\b\|\$uid' anchor-gallery/anchor-gallery.php | head -20`

If `$uid` is built from the post ID (e.g. `'avs-' . $post_id`), display that. If it's a random per-call value, fall back to instructing the user to use `data-avg-uid` attribute selectors instead. Encode this finding in the field help text update below.

- [ ] **Step 2: Update Custom CSS field help based on the UID format found**

If UID is post-ID-derived (e.g. `avs-123`), change the `'custom_css'` schema entry's `help` text to:

```
Use #avs-{POST_ID} to scope rules to this gallery (post ID shown in the right sidebar). To override a CSS variable on the wrapper, you must use !important. Targeting child elements does not.
```

If UID is random, change to:

```
Custom CSS is emitted scoped only by the .anchor-video-gallery class. To target only this gallery, use the attribute selector: [data-avg-uid="THIS_POSTS_TITLE_SLUG"] .avg-tile { ... }.
```

(Update the previously-written `help` string in Task 6, Step 4.)

- [ ] **Step 3: Add `data-avg-uid` attribute to the wrapper**

In `render_output()`, find the wrapper div opening tag inside the `ob_start()` block. Add a new attribute:

```php
data-avg-uid="<?php echo esc_attr($uid); ?>"
```

This makes the UID available for attribute selectors regardless of whether the id is post-derived or random.

- [ ] **Step 4: Add CSS to admin.css for any future chip styling (placeholder)**

Append to `anchor-gallery/assets/admin.css`:

```css
.anchor-builder__field[data-setting-key="custom_css"] .anchor-builder__help {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
  background: #f6f7f7;
  padding: 6px 8px;
  border-left: 3px solid #2271b1;
  margin-top: 6px;
}
```

- [ ] **Step 5: Commit**

```bash
php -l anchor-gallery/anchor-gallery.php
git add anchor-gallery/anchor-gallery.php anchor-gallery/assets/admin.css
git commit -m "chore(gallery): expose data-avg-uid attribute + polish custom CSS help text"
```

---

## Task 10: Version bump

**Files:**
- Modify: `anchor-tools.php` (plugin header)
- Modify: `anchor-gallery/anchor-gallery.php` — bump CSS/JS enqueue version strings if any are hardcoded (they use `filemtime()` so they self-bump; leave as-is)

- [ ] **Step 1: Bump the Version header**

Run: `grep -n 'Version:' anchor-tools.php`
Find the line `Version: 3.6.19` (or whatever the current is). Edit to `Version: 3.7.0`.

- [ ] **Step 2: Commit**

```bash
git add anchor-tools.php
git commit -m "chore: bump version to 3.7.0"
```

---

## Task 11: Manual smoke test — no-touch upgrade is visually identical

**Files:** none (verification)

- [ ] **Step 1: Activate the branch in your WP test environment**

Either `git pull` on the test server's plugin checkout, or zip-upload via `wp-admin`. Confirm WP shows version `3.7.0` under Plugins → Installed Plugins.

- [ ] **Step 2: Open the test gallery used for the baseline (Task 1, Step 2)**

Visit the front-end page that uses this gallery. View source.

- [ ] **Step 3: Compare against the baseline**

Run: `diff /tmp/avg-pre-upgrade/source.html <(curl -s "<frontend-url>")` (or copy-paste into a visual diff tool).

Expected: The differences should be limited to:
- A new `data-avg-uid="..."` attribute on the wrapper.
- Any value emitted via the new CSS-var emission block ONLY if the gallery had a non-default setting that happens to map to a new var. For an untouched gallery, expect zero new declarations in the inline `style=""`.
- A new (empty) `<style data-avg-uid="...">` block — but our emit code skips when `$custom_css` is empty, so this should NOT appear.

If you see new declarations on an untouched gallery, find which setting accidentally has a non-empty default and zero it out.

- [ ] **Step 4: Side-by-side screenshot diff**

Render the page in a browser, screenshot, diff visually against `/tmp/avg-pre-upgrade/screenshot.png`. Expected: identical.

- [ ] **Step 5: If diff is non-trivial, fix and recommit**

Roll any fixes into Task 6/7 via small follow-up commits. Do NOT proceed until untouched galleries render identically.

- [ ] **Step 6: Verify migration ran**

Run in WP-CLI (or via a temporary admin-only debug print):

```bash
wp option get anchor_gallery_title_position_migrated_v37
```

Expected: `1`.

For a gallery where `avg_show_title` was previously `'0'`:

```bash
wp post meta get <POST_ID> avg_title_position
```

Expected: `hidden`.

For a gallery where `avg_show_title` was previously `'1'`:

```bash
wp post meta get <POST_ID> avg_title_position
```

Expected: `below`.

---

## Task 12: Manual smoke test — each new control

**Files:** none (verification)

In `wp-admin`, edit the test gallery. For each row below, change the setting, save, view front-end, confirm.

- [ ] **Step 1: Hover effects**

For each hover_effect value (`none`, `lift`, `zoom`, `glow`, `tilt`, `fade`, `slide-up`, `brighten`, `desaturate`): set the value, save, hover a tile in the front-end. Confirm the effect renders. Combine with `hover_intensity = strong` to confirm intensity classes apply.

- [ ] **Step 2: Title visibility**

Set `title_position = hidden` → no title in DOM. Set `below` → title under image. Set `overlay` → title overlaying image. Toggle `show_caption` and confirm `.avg-caption` appears/disappears.

- [ ] **Step 3: Tile background**

Set `tile_bg_mode = theme` → matches theme. Set `transparent` → wrapper gets `avg-tile-bg-transparent` class; tile is see-through. Set `custom` + a color → tile bg matches. Verify `tile_hover_bg_color` works independently.

- [ ] **Step 4: Tile box (padding, border)**

Set `tile_padding=12, tile_border_width=2, tile_border_color=#ff0000, tile_border_style=dashed`. Front-end: confirm 12px inner padding + 2px dashed red border on each tile.

- [ ] **Step 5: Title typography**

Set `title_size=18, title_weight=300, title_color=#ff00ff, title_transform=uppercase, title_align=center`. Confirm each.

- [ ] **Step 6: Caption typography**

Set `caption_color=#888, caption_size=14`. Confirm.

- [ ] **Step 7: Play button**

For a video gallery: set `play_button_size=72, play_button_color=#ffffff, play_button_bg_color=#ff0000`. Confirm.

- [ ] **Step 8: Overlay gradient**

Switch `tile_style=overlay`. Set `overlay_gradient_strength=20`. Confirm gradient is much lighter. Set to `100`, confirm near-opaque black.

- [ ] **Step 9: Transition duration**

Set `transition_duration_ms=800` with `hover_effect=lift`. Hover a tile — transition should feel sluggish. Set to `60` — should snap.

- [ ] **Step 10: Advanced — raw CSS vars override Style controls**

In Style tab, set `tile_bg_color=#ffff00` (yellow). In Advanced tab, set `css_var_bg=#ff0000` (red). Front-end: tile should be RED (Advanced wins because emitted later in inline style).

Wait — actually `--avg-tile-bg` (Style) and `--avg-bg` (Advanced raw) are different vars. `.avg-tile-inner` uses `background: var(--avg-tile-bg, var(--avg-bg))`. So Style-tab `tile_bg_color` (= `--avg-tile-bg`) takes precedence over Advanced-tab `css_var_bg` (= `--avg-bg`) because the cascade resolves the inner var first.

Confirm this is the documented behavior. If not desired, swap so Advanced wins. (Open question — decision: leave as-is. The CSS-var-with-fallback chain inherently means the more-specific Style var beats the fallback. Document this in the Advanced field help.)

Update the `css_var_bg` help text:

```php
'css_var_bg' => ['type' => 'color', 'label' => 'Override: Tile Background (--avg-bg)', 'section' => 'advanced', 'help' => 'Theme-level default. Style tab Tile Background Color overrides this.'],
```

(And similar for the other css_var_* entries where Style tab has a more-specific control.)

- [ ] **Step 11: Custom CSS**

In Advanced → Custom CSS, paste:

```css
[data-avg-uid="<UID-FROM-SOURCE>"] .avg-title { color: lime; }
```

(Replace `<UID-FROM-SOURCE>` with the actual `data-avg-uid` attribute value from view-source.)

Save. Confirm only this gallery's titles turn lime; other galleries on the same page are unaffected.

Then try redefining a var without `!important`:

```css
[data-avg-uid="<UID>"] { --avg-bg: lime; }
```

If the gallery has a Style-tab `tile_bg_color` set, Style still wins (because `--avg-tile-bg` outranks `--avg-bg` in `var(--avg-tile-bg, var(--avg-bg))`).

If the gallery has NO Style-tab tile bg set, the wrapper inline style does NOT define `--avg-bg`, so the Custom CSS redefinition takes effect. Confirm both cases.

- [ ] **Step 12: Strip-to-nothing**

Apply the `simple_slider` preset. Manually clear every Style-tab field back to empty / 0. Save. Confirm: slider arrows still work, autoplay still works, popup still opens — but visual styling is minimal (theme default only).

- [ ] **Step 13: Commit any small fixes uncovered during smoke testing**

```bash
git add -p   # cherry-pick fixes
git commit -m "fix(gallery): <specific fix>"
```

---

## Task 13: Final wrap-up

**Files:** none

- [ ] **Step 1: Push the feature branch**

Run: `git push -u origin feature/gallery-full-customization`

- [ ] **Step 2: Open a PR (if using PR workflow) or merge to main**

Decision per repo convention: this codebase commits directly to `main` for releases (see recent commits). Confirm with user before merging.

- [ ] **Step 3: Tag and release**

If user approves merge to `main`:

```bash
git checkout main
git merge --no-ff feature/gallery-full-customization
git push origin main
git tag v3.7.0
git push origin v3.7.0
```

Then create a GitHub release matching the tag (web UI), upload a release ZIP if that's the project's convention (check past releases for the pattern).

- [ ] **Step 4: Verify in a staging WP install via Plugin Update Checker**

Wait a minute, refresh `wp-admin` → Plugins, confirm "Update available" appears and the update applies cleanly. Visit a test gallery and confirm no visual regression.

---

## Self-Review Notes

**Spec coverage check:**
- §2.1 "show_title vs title_position": Task 7 Step 1 + Task 8 (migration).
- §2.1 "hover_effect": Task 5 + Task 6 Step 3.
- §2.1 "Tile background": Task 4 Step 4 + Task 6 Step 4 + Task 7 Step 3+4.
- §2.2 (all hardcoded properties): Task 4 + Task 6.
- §3 architecture (CSS vars + fallbacks + Custom CSS scoping): Task 4 + Task 7.
- §4 (controls per tab): Task 6.
- §5 (migration): Task 8.
- §6 (files affected): all tasks. `anchor-gallery/assets/admin.css` is touched in Task 9, `admin.js` in Task 3.
- §7 (sanitization): Task 2.
- §8 (testing matrix): Tasks 11 + 12.

**Placeholder scan:** No `TBD` / `TODO` / "implement later" strings. The phrase "if you didn't add it, add this rule now" in Task 7 Step 3 references the `.avg-tile-bg-transparent` rule that was already covered in Task 4 — the redundant note is a belt-and-suspenders safety check, not a placeholder.

**Type consistency:**
- `avg_show_title` meta key is read in Task 8 migration; deprecated everywhere else.
- New meta key prefixes: all use `avg_` (consistent with existing schema).
- CSS var names match between Task 4 (CSS), Task 7 (PHP emit), Task 6 (schema defaults). Each new var listed: `--avg-tile-bg`, `--avg-tile-hover-bg`, `--avg-tile-padding`, `--avg-tile-border-width`, `--avg-tile-border-color`, `--avg-tile-border-style`, `--avg-title-color`, `--avg-title-size`, `--avg-title-weight`, `--avg-title-transform`, `--avg-title-align`, `--avg-caption-color`, `--avg-caption-size`, `--avg-play-button-color`, `--avg-play-button-bg`, `--avg-play-size`, `--avg-overlay-strength`, `--avg-transition`. Used consistently across tasks.

**One ambiguity surfaced during writing (Task 12 Step 10):** Style-tab vs Advanced-tab precedence. The spec said Advanced wins, but the implementation uses separate vars (`--avg-tile-bg` for Style vs `--avg-bg` for Advanced) which means Style wins for properties where both exist. The plan documents this and updates the Advanced help text to reflect it. The user's "Advanced wins" intent is preserved for properties that ONLY exist in Advanced (the theme-level vars that have no Style-tab equivalent).
