# Anchor Blocks shortcodes + shared site-wide preview CSS

**Date:** 2026-06-10
**Status:** Approved (design)

## Summary

Two related improvements to the Anchor Tools plugin:

1. **Anchor Blocks shortcode support** — run nested shortcodes inside a block's
   HTML, and surface a click-to-copy shortcode in the block editor.
2. **Shared site-wide preview CSS** — a single reusable helper that harvests the
   *entire* live site's stylesheets (theme + child theme + every plugin + Google
   Fonts + inline `:root`/global styles) and feeds them into every module's
   editor preview iframe, regardless of which theme or plugins are installed.

### Critical boundary

- The **preview CSS** feature (Parts 2 & 3) is **editor-preview-only**. It never
  enqueues anything on the front end and never changes the HTML/CSS/JS that a
  module outputs on a published page.
- The **nested-shortcode** change (Part 1a) is the *only* change that affects
  front-end output, and that is the explicitly requested behavior.

## Part 1 — Anchor Blocks shortcode support

File: `anchor-blocks/anchor-blocks.php`, `anchor-blocks/assets/admin.js`

### 1a. Nested shortcodes in block HTML (front-end behavior change)

- In `shortcode_render()`, change the emitted inner HTML from raw `$m['html']`
  to `do_shortcode( $m['html'] )`, matching `anchor-slider` and `anchor-gallery`.
- Do **not** apply `wp_kses_post()` — blocks intentionally store and render raw,
  unescaped HTML (documented in the module). Wrapping in `do_shortcode` only
  expands shortcode tags; all other markup passes through unchanged.
- **Recursion guard:** maintain a per-request set of block IDs currently
  rendering. If `shortcode_render()` is re-entered for an ID already in the set
  (a block embedding itself directly or via a cycle), return `''` and skip
  queueing, instead of looping infinitely. Remove the ID from the set after its
  HTML is built.

### 1b. Click-to-copy shortcode in the editor

- In the **Block Settings** side metabox (`render_box_settings`), add a
  read-only display of `[anchor_block id="N"]` (N = current post ID) with a
  "Copy" button.
- For a new, unsaved block (no real ID yet), show the helper text
  "Save the block to get its shortcode." instead of a copyable value.
- Wire the copy button in `assets/admin.js` using the Clipboard API with a
  `document.execCommand('copy')` fallback; show brief "Copied!" feedback.
- The existing admin-list `ab_shortcode` column is unchanged.

## Part 2 — Shared preview-CSS helper (editor preview only)

New file: `includes/class-anchor-preview-css.php` → class `Anchor_Preview_CSS`.
New file: `assets/anchor-preview.js` (shared front-end glue).

This is the single source of preview CSS for every module.

### Data / storage

- Option `anchor_preview_settings` (autoload **false**):
  - `reference_url` — URL to harvest from. Default `home_url( '/' )`.
  - `extra_css_urls` — newline-separated additive stylesheet URLs (site-wide).
- Transient `anchor_preview_harvest` (1 hour TTL):
  - `urls` — array of harvested stylesheet URLs.
  - `inline` — concatenated inline `<style>` CSS captured from `<head>`.
  - `time` — harvest timestamp.
  - `count` — number of stylesheet links found.

### Harvest logic (Approach A)

- `harvest( $url )`:
  - `wp_remote_get( $url, [ timeout ~10s, redirection, sslverify default ] )`.
  - On success, isolate the `<head>` section and extract:
    - every `<link rel="stylesheet" ... href="...">` href (absolute or made
      absolute against the reference URL), and
    - the contents of every inline `<style>...</style>` in the head
      (captures theme.json `global-styles-inline-css`, block CSS, `:root`
      custom properties).
  - Returns `{ urls, inline, count }`. On failure (non-200, WP_Error), returns a
    safe empty payload and logs via `Anchor_Schema_Logger` if available.
- `get_payload()`:
  - Returns the cached transient if present; otherwise harvests, caches, returns.
  - Always appends, after the harvested URLs (additive, de-duplicated):
    1. theme fallback (`get_stylesheet_uri()`, parent `style.css`) — guarantees
       a baseline even if the harvest fails, and
    2. `extra_css_urls` from `anchor_preview_settings`.
  - Per-context extras (e.g. a block's own `ab_preview_css_urls`) are appended
    by the calling module on top of this payload — the helper does not know
    about module-specific fields.

### Refresh

- AJAX action `wp_ajax_anchor_preview_refresh` (capability: `manage_options`,
  nonce-checked): deletes the transient, re-harvests, returns the fresh payload
  as JSON. Used by the "Refresh now" button on the settings screen and is also
  safe to call from a module editor if we choose to expose it there later.

### Admin enqueue glue

- `Anchor_Preview_CSS::enqueue_for_admin()`:
  - Enqueues `assets/anchor-preview.js` (no deps beyond what modules already
    load).
  - `wp_localize_script` a global `ANCHOR_PREVIEW = { urls, inline }` from
    `get_payload()`.
- `assets/anchor-preview.js` exposes:
  - `window.AnchorPreview.headMarkup( extraUrls = [] )` → returns a string of
    `<link rel="stylesheet">` tags for `ANCHOR_PREVIEW.urls` + any `extraUrls`,
    followed by a single `<style>` containing `ANCHOR_PREVIEW.inline`.
  - Modules call this when assembling their iframe `srcdoc` `<head>`.

### Settings UI

- Registered as a small **"Preview"** section on the **General** settings tab
  (via the existing settings/tab mechanism). Fields:
  - Reference URL (text, default placeholder = home URL).
  - Global extra stylesheets (textarea, one URL per line).
  - "Refresh now" button + read-out: "Last harvested: <time> — <count>
    stylesheets found."
- **Migration:** on load, if `anchor_blocks_settings['preview_css_urls']` is set
  and `anchor_preview_settings['extra_css_urls']` is empty, copy the former into
  the latter (one-time, non-destructive) so existing manual URLs are preserved.
  The old Blocks-tab textarea is removed from the UI; the per-block
  `ab_preview_css_urls` field stays.

## Part 3 — Rollout to all preview modules (phased)

End state: all seven preview modules build their preview iframe head from
`AnchorPreview.headMarkup()`.

### Phase A — clean swap (already iframe `srcdoc`)

- `anchor-blocks` — replace `previewCssLinks()` with
  `AnchorPreview.headMarkup( perBlockExtraUrls )`; call
  `Anchor_Preview_CSS::enqueue_for_admin()` in `admin_assets()`; drop the
  module's own `previewCssUrls` localization (or keep only the per-block extras).
- `anchor-mega-menu` — replace `cssLinks()` / `MM_PREVIEW.cssUrls` with
  `AnchorPreview.headMarkup()`; enqueue the shared helper in its `admin_assets()`.

### Phase B — server-rendered previews (convert individually, verify each)

For `anchor-code-snippets`, `anchor-ctm-forms`, `anchor-gallery`,
`anchor-slider`, `anchor-universal-popups`:

- These render preview output inline or via AJAX. Wrap each module's existing
  preview output inside an iframe whose `<head>` is built from
  `AnchorPreview.headMarkup()`, leaving *what* they render unchanged.
- Each module is converted and manually verified on its own so a regression in
  one preview can't mask another. No change to any front-end render path.

## Versioning

- Bump the `ASSET_VER` / `wp_enqueue_*` version string on every touched module so
  browsers pick up the new admin JS/CSS.
- Bump the `Version:` header in `anchor-tools.php` at the end, then follow the
  documented release process (commit, push, GitHub release).

## Out of scope / non-goals

- No change to any front-end enqueue or render path other than Part 1a.
- No new build tooling — raw PHP/JS per project conventions.
- No automated tests (project has none; testing is manual in WordPress).

## Manual test plan

1. **Nested shortcode:** create a block whose HTML contains `[anchor_reviews]`
   (or any registered shortcode); place `[anchor_block id=N]` on a page; confirm
   the inner shortcode renders. Confirm a self-referencing block does not loop.
2. **Copy button:** confirm the editor shows the correct shortcode and Copy
   works; confirm new unsaved block shows the save hint.
3. **Harvest:** set reference URL = homepage; open a block editor; confirm the
   preview iframe shows theme colors/fonts/`:root` vars and plugin CSS. Toggle
   to a different theme and confirm the preview reflects it without code changes.
4. **Refresh:** change site CSS, click "Refresh now", confirm preview updates.
5. **Each rolled-out module:** open each editor, confirm its preview now carries
   site CSS and its rendered output is unchanged.
6. **Front-end unchanged:** confirm no Anchor preview stylesheet/script is
   enqueued on any front-end page.
