# Anchor Blocks — Design

**Date:** 2026-05-29
**Status:** Approved (design); pending implementation plan
**Module key:** `blocks` · **Class:** `Anchor_Blocks_Module` · **CPT:** `anchor_block` · **Meta prefix:** `ab_`

## 1. Purpose

A reusable-content library for the Anchor Tools plugin — a lightweight "Divi library" made of pure HTML/CSS/JS instead of a page-builder format, so it is faster and editable as code.

Each block is one CPT post holding raw **HTML / CSS / JS**, placed anywhere via the shortcode `[anchor_block id=123]`. It is edited Mega-Menu-style (CodeMirror fields) with an **isolated iframe live preview** at the bottom that loads the site's real theme CSS, so `:root` variables, colors, and fonts resolve exactly as on the front end. A block scales from a single button to a full-bleed section. No presets, no templates — pure HTML/CSS/JS.

### Distinction from existing modules
- **Code Snippets** (`anchor-code-snippets`): injects code globally into `wp_head` / `wp_body_open` / `wp_footer`, global or page-scoped. It is *site-wide injection*, not placed content.
- **Anchor Blocks**: *placed content* you drop into a specific spot in a page/post via shortcode.

These do not overlap; Anchor Blocks is a justified new module.

## 2. Module skeleton

Follows the proven CPT module pattern used across the codebase (mega_menu, code_snippets, universal_popups, video_slider).

```
anchor-blocks/
  anchor-blocks.php        Anchor_Blocks_Module — CPT, metaboxes, save_post, admin columns, shortcode, preview asset localization
  assets/admin.css         metabox + iframe preview styling
  assets/admin.js          CodeMirror init + debounced iframe preview rebuild
  assets/admin.min.css     minified (build step, like other modules)
  assets/admin.min.js
  assets/admin.min.js.map
```

### Registry entry

Added to `anchor_tools_get_available_modules()` in `anchor-tools.php` (near the existing `code_snippets` entry, ~line 2968):

```php
'blocks' => [
    'label'       => __( 'Anchor Blocks', 'anchor-schema' ),
    'description' => __( 'Reusable HTML/CSS/JS content blocks placed via shortcode. From a button to a full-width section.', 'anchor-schema' ),
    'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-blocks/anchor-blocks.php',
    'class'       => 'Anchor_Blocks_Module',
],
```

## 3. Data model

Stored as individual `post_meta` keys with the `ab_` prefix.

| Key | Type | Purpose |
|-----|------|---------|
| `ab_html` | text | Block markup |
| `ab_css` | text | Block CSS (output once in a `<style>`) |
| `ab_js` | text | Block JS (output once in a `<script>`) |
| `ab_full_width` | `'1'` / `''` | When `'1'`, wrap output in a full-bleed (`100vw`) container; otherwise inline/raw |
| `ab_preview_css_urls` | text (newline-separated URLs) | Optional extra stylesheet URLs loaded **in preview only** for this block |

### Site-level setting
A new **"Blocks"** tab on the existing unified settings page (`Settings > Anchor Tools`, controller `includes/class-anchor-settings-page.php`, registered via the `anchor_settings_tabs` filter) stores **default preview stylesheet URLs** for the whole site. The per-block `ab_preview_css_urls` field *adds to* this site default, so common stylesheets are set once.

Storage convention: a single option, `autoload=false` (per repo convention for `update_option`).

## 4. Frontend output

Shortcode: `[anchor_block id=123]`. Also resolves `[anchor_block slug="hero-cta"]`. Resolution order: **`id` → `slug`** (matches the Gallery module's resolver).

### Markup
Each placement renders the block HTML wrapped in a uniquely-identified container:

```html
<div class="anchor-block anchor-block--123" data-anchor-block="123" data-instance="N">
  … ab_html …
</div>
```

- `N` is a per-placement counter (1, 2, …) so multiple placements of the same block are individually targetable.
- If `ab_full_width` is on, an additional outer full-bleed wrapper applies the breakout technique (`width:100vw; margin-left:calc(50% - 50vw)`) so the section spans edge-to-edge regardless of the theme's content container. When off, output flows inline like normal content (correct for a button or small element).

### CSS / JS output discipline (decided with user)
- **HTML** renders at **every** placement → two placements = two real, independent DOM instances (e.g. two working sliders).
- **CSS** is output **once per page** no matter how many placements (a class rule styles all instances anyway; duplicating only bloats the page).
- **JS** is output **once per page**, written to iterate over all instances. Outputting JS per-placement is explicitly avoided because it causes double-initialization bugs (e.g. re-initializing the first slider, leaving the second uninitialized or with duplicated listeners).

Tracking: a static `$rendered` array on the module keyed by block ID guards CSS/JS so each block's assets emit at most once per request. JS/CSS emitted in the footer (`wp_footer`) after content is rendered.

### Editor guidance for multi-instance JS
The JS editor field ships with inline help text showing the canonical pattern, so multi-instance initialization is the obvious path:

```js
document.querySelectorAll('.anchor-block--ID').forEach(function (el) {
  // initialize each instance using `el` as the scope root
});
```

The unique `data-instance` attribute is available for any case requiring per-instance isolation.

## 5. Admin editor + isolated iframe preview (key piece)

### Editor
- Three CodeMirror fields: **HTML**, **CSS**, **JS** — reusing the same WordPress-bundled CodeMirror (`wp.codeEditor` / `wp_enqueue_code_editor`) approach Mega Menu uses, with appropriate modes (`htmlmixed`, `css`, `javascript`).
- A **Full width** checkbox (`ab_full_width`).
- A **Preview stylesheets (extra)** textarea (`ab_preview_css_urls`).
- Metaboxes follow the CPT pattern: editor fields (normal/high), preview (normal), settings (side). Admin assets enqueued only on `post.php` / `post-new.php` with a `post_type === 'anchor_block'` check.

### Preview engine — isolated iframe
- The preview is an `<iframe>` rendered via the `srcdoc` attribute, rebuilt on a **debounced** keystroke handler.
- The iframe document is composed client-side, in order:
  1. `<link>` to the active theme stylesheet(s): child via `get_stylesheet_uri()`, parent via `get_template_directory_uri() . '/style.css'`.
  2. `<link>` for each site-default preview URL, then each per-block `ab_preview_css_urls` entry.
  3. `<style>` with the block's current `ab_css`.
  4. The block's current `ab_html`.
  5. `<script>` with the block's current `ab_js`.
- Because content lives inside an iframe, theme CSS is fully isolated — it cannot leak into and break wp-admin — and `:root` variables / colors / fonts resolve as on the front end.
- The stylesheet URL list is passed to JS via `wp_localize_script` as `ANCHOR_BLOCKS.previewCssUrls` (built server-side from theme URIs + site-default + per-block field). No AJAX round-trip is needed for live typing; the iframe `srcdoc` is regenerated entirely in the browser.

## 6. Mega Menu preview upgrade (same enhancement, requested)

Mega Menu's current preview injects `<style>` and HTML inline into the admin page (`anchor-mega-menu/admin.js`, `ensurePreviewNodes()` / `applyPreview()`), which cannot safely load the theme's full stylesheet. It is swapped for the **same isolated-iframe approach** as Anchor Blocks:

- Preview becomes an iframe whose `srcdoc` loads the theme stylesheet(s) + Mega Menu's existing `mm_global_css` and `mm_css` fields + `mm_html` + `mm_js`.
- This is scoped and low-risk: it reuses the Blocks preview composition logic and Mega Menu's existing field IDs. No data-model change to Mega Menu.

## 7. Decisions made without asking (all reversible)

- Module key `blocks`, class `Anchor_Blocks_Module`, meta prefix `ab_`, settings tab label "Blocks".
- Shortcode resolves by `id` first, then `slug` (matches Gallery).
- Preview-CSS URLs configurable both site-wide (set once) and per-block (add extras).

## 8. Testing (manual — repo has no automated suite)

1. Enable the module in Settings > Anchor Tools; CPT "Blocks" appears in admin menu.
2. Create a block with HTML/CSS/JS; save; verify meta persists.
3. Place `[anchor_block id=N]` in a page → HTML renders, CSS applies, JS runs.
4. Toggle **Full width** → output breaks out edge-to-edge; toggle off → inline.
5. Place the same shortcode **twice** on one page → two live DOM instances; CSS and JS each appear **once** in page source; both instances function (slider test).
6. Resolve by `slug` → `[anchor_block slug="..."]` works.
7. In the editor, a CSS rule using a theme `:root` variable (e.g. `var(--brand-color)`) renders correctly **in the iframe preview**.
8. Mega Menu: open a mega menu, confirm the new iframe preview loads theme CSS and renders `mm_html`/`mm_css`/`mm_global_css`/`mm_js` correctly; confirm wp-admin styling is unaffected (no CSS leak).

## 9. Out of scope (YAGNI)

- Presets / starter templates.
- Gutenberg block or block-editor insert button (shortcode only, per request).
- Front-end scraping of the full enqueued stylesheet set for preview (theme stylesheet + configurable URLs is sufficient).
- Versioning / revisions beyond WordPress's native post revisions.
