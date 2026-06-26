# Monaco Editor + CPT Groups — Design

**Date:** 2026-06-24
**Branch:** `feature/monaco-and-groups`
**Status:** Approved, ready for implementation plan

## Goal

Two independent features for the Anchor Tools plugin:

1. **Monaco editor** — replace the per-field CodeMirror textareas in **Universal Popups**, **Anchor Blocks**, and **Mega Menu** with a tabbed Monaco (VS Code) editor, matching the UX of the user's `anchor-dental` theme editor.
2. **Groups** — add a non-public, category-style taxonomy ("Group") to **Popups, Blocks, Mega Menu, Code Snippets, and Galleries** so the CPT list screens can be grouped/filtered, with inline (Quick Edit), bulk, and filter-dropdown support — and **no** public archive pages or sitemap entries.

The two features share nothing and can be built/reviewed independently.

---

## Feature 1 — Monaco Editor

### Reference

Modeled on `wp-content/themes/anchor-dental/inc/editor-monaco.php` + `assets/js/ac-editor.js` in the user's `great-smiles-new` site: tabbed HTML/CSS/JS, one Monaco instance per tab (stacked, only active shown), `vs-dark`, Format Code button, Insert-from-Media-Library button, and localStorage undo-history persistence across the save reload. The theme's AI Assistant panel is **out of scope** here.

### Architecture: one shared helper, not three copies

New reusable component, enqueued by any module that opts in:

- `includes/class-anchor-monaco.php` — `Anchor_Monaco` helper.
  - `Anchor_Monaco::enqueue( $cpt )` — call from a module's `admin_assets( $hook )` when on that CPT's `post.php`/`post-new.php`. Enqueues the jsDelivr Monaco AMD loader (`monaco-editor@0.52.2`), the shared glue JS, and CSS; localizes the Monaco base URL + media-library i18n strings (`AnchorMonaco` JS object).
  - `Anchor_Monaco::render_tabs( $config )` — optional PHP helper to print the tabbed toolbar container, OR modules keep their existing textareas and just add a wrapper + `data-` attributes. **Chosen approach:** modules keep their existing `<textarea>` markup unchanged and wrap the code metabox in a container the glue JS recognizes (see "Field declaration" below). This keeps `save_post` and server-side rendering identical — Monaco is purely a front-end enhancement over the real form fields.
- `assets/anchor-monaco.js` — the shared glue (adapted from `ac-editor.js`): builds one Monaco editor per declared field, tabs, `vs-dark`, Format Code, Insert-from-Media-Library, undo persistence.
- `assets/anchor-monaco.css` — toolbar/tab/editor-host styling.

Loaded from CDN (jsDelivr), matching the reference. Enqueued **only** on the relevant CPT edit screens, never on the front end.

### Field declaration

Each module marks its code metabox so the glue can discover the fields and build tabs. The glue reads a JSON config attached to a wrapper element, e.g.:

```html
<div class="anchor-monaco" data-anchor-monaco='[
  {"id":"ab_html","label":"HTML","lang":"html"},
  {"id":"ab_css","label":"CSS","lang":"css"},
  {"id":"ab_js","label":"JS","lang":"javascript"}
]'>
  ... existing textareas (now visually hidden, Monaco mounts above) ...
  <div class="anchor-monaco-host"></div>
</div>
```

The glue:
1. Reads the config, hides the listed textareas, builds the tab bar + a single host div with one stacked Monaco pane per field.
2. On every Monaco change: writes the value back to the textarea **and dispatches a native `input` event** on it so existing live-preview wiring (which listens on `input`) keeps working.
3. Provides Format Code + Insert-from-Media-Library acting on the active tab.
4. Persists undo snapshots to localStorage keyed `anchorMonacoUndo:{postId}:{fieldId}` and replays them on next load (the reference's snapshot-replay technique), so Ctrl/Cmd+Z survives the post-save reload.

### Per-module field mapping

| Module | CPT | Tabs (existing textarea IDs) |
|---|---|---|
| Blocks | `anchor_block` | HTML `ab_html` / CSS `ab_css` / JS `ab_js` |
| Mega Menu | `anchor_mega_snippet` | HTML `mm_html` / Global CSS `mm_global_css` / CSS `mm_css` / JS `mm_js` |
| Popups | `anchor_popup` | HTML `up_html` / CSS `up_css` / JS `up_js` |

Non-code fields (popup `up_shortcode`, video/exclude-URL fields, block settings, etc.) are untouched and stay as plain inputs.

### Compatibility — replacing CodeMirror without breaking live preview

Each target module's `admin.js` currently:
- initializes CodeMirror via `wp.codeEditor.initialize()` on the textareas, and
- refreshes the live-preview iframe on textarea `input` / CodeMirror `change`, with an `else` fallback that wires `$('#id, ...').on('input', refresh)` when CodeMirror is unavailable.

Change per module:
1. **Skip the module's own CodeMirror init when Monaco is active.** Guard the `wp.codeEditor.initialize` block on `! window.AnchorMonaco` (or `! $('.anchor-monaco').length`). When Monaco owns the fields, the existing `else`/fallback path — `$('#...').on('input', refreshPreview)` — runs instead, and Monaco's dispatched `input` events drive it. No preview logic is rewritten.
2. Remove the now-redundant `wp_enqueue_code_editor()` / `code-editor` enqueues from the module's `render_box_code()` / `admin_assets()` (Monaco replaces them). Keep `'code-editor'` out of the admin.js dependency array.
3. `admin_assets()` calls `Anchor_Monaco::enqueue( self::CPT )` and adds the wrapper `data-anchor-monaco` config to the code metabox markup.

`save_post` handlers and front-end rendering are **unchanged** for all three modules.

### Out of scope (Monaco)

- AI Assistant panel (theme-only; Code Snippets already has its own AI).
- Converting Code Snippets to Monaco (it keeps its current CodeMirror single-field editor).

---

## Feature 2 — Groups (non-public category taxonomy)

### Architecture: one shared helper, one taxonomy per module

New reusable component:

- `includes/class-anchor-groups.php` — `Anchor_Groups` helper.
  - `Anchor_Groups::register( $taxonomy, $cpt, $labels )` — registers the taxonomy with the non-public flag set below, and wires the list-screen filter dropdown, bulk-assign action, and (relies on core for) Quick Edit + admin column. Each module calls this once on `init`.

### Taxonomies

| Module | CPT | Taxonomy |
|---|---|---|
| Popups | `anchor_popup` | `anchor_popup_group` |
| Blocks | `anchor_block` | `anchor_block_group` |
| Mega Menu | `anchor_mega_snippet` | `anchor_mega_group` |
| Code Snippets | `anchor_snippet` | `anchor_snippet_group` |
| Galleries | `anchor_gallery` | `anchor_gallery_group` |

All hierarchical (category-style). Mega Menu included for parity with the other code modules.

### Registration flags (no public archives / sitemap)

```php
register_taxonomy( $taxonomy, $cpt, [
    'labels'             => $labels,           // "Groups" / "Group"
    'hierarchical'       => true,
    'public'             => false,
    'publicly_queryable' => false,
    'rewrite'            => false,
    'query_var'          => false,
    'show_ui'            => true,
    'show_in_menu'       => true,              // submenu under the CPT
    'show_admin_column'  => true,              // sortable Group column on list table
    'show_in_quick_edit' => true,              // inline Quick Edit assignment
    'show_in_rest'       => false,             // no block-editor/REST exposure needed
] );
```

`public => false` + `publicly_queryable => false` + `rewrite => false` means no front-end archive URLs and nothing for WordPress core sitemaps to include (core sitemaps only list public taxonomies). No SEO-plugin sitemap entries result either, since they key off public taxonomies.

### Usability features (wired by the helper)

1. **Filter dropdown** on each CPT list screen — `restrict_manage_posts` prints a Group `<select>` (terms list); `parse_query` applies the chosen term to the query. (Not automatic for custom taxonomies; wired explicitly.)
2. **Inline add via Quick Edit** — provided by `show_in_quick_edit => true`. The post-edit metabox also gives the built-in "+ Add New Group" link, so groups are created inline without visiting the taxonomy page.
3. **Bulk assign** — a custom "Add to group →" bulk action (`bulk_actions-edit-{cpt}` filter + `handle_bulk_actions-edit-{cpt}`) plus the native Bulk Edit group control, so multiple posts can be dropped into a group at once.
4. **Admin column** — sortable "Group" column from `show_admin_column => true`.

### Module wiring

Each of the five modules adds, in its constructor:
```php
add_action( 'init', [ $this, 'register_groups' ] );
// register_groups(): Anchor_Groups::register( self::TAX, self::CPT, [...labels...] );
```
plus a `const TAX = '...';`. The bulk-action and filter-dropdown hooks are registered inside `Anchor_Groups::register()` so each module stays thin.

---

## Files touched

**New:**
- `includes/class-anchor-monaco.php`
- `assets/anchor-monaco.js`, `assets/anchor-monaco.css`
- `includes/class-anchor-groups.php`

**Modified:**
- `anchor-tools.php` — `require_once` the two new core classes during bootstrap.
- `anchor-universal-popups/anchor-universal-popups.php` + its `assets/admin.js` (Monaco + group)
- `anchor-blocks/anchor-blocks.php` + `assets/admin.js` (Monaco + group)
- `anchor-mega-menu/anchor-mega-menu.php` + its admin JS (Monaco + group)
- `anchor-code-snippets/anchor-code-snippets.php` (group only)
- `anchor-gallery/anchor-gallery.php` (group only)
- `anchor-tools.php` Version header bump.

## Testing (manual, per project convention)

- Monaco: open a Block/Popup/Mega Menu, confirm tabs render, syntax highlighting per language, Format Code, Insert-from-Media-Library inserts URL at cursor, live preview updates as you type, save persists, undo works after the save reload.
- Groups: each list screen shows a Group column + filter dropdown; Quick Edit assigns a group; "+ Add New Group" creates one inline; bulk action assigns several at once; confirm no front-end archive page at `/?anchor_block_group=...` and the taxonomy is absent from `wp-sitemap.xml`.
