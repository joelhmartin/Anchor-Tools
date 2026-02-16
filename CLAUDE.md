# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Anchor Tools is a modular WordPress plugin (`anchor-tools.php`, version 3.4.x). Each feature is a self-contained module that users can enable/disable from Settings > Anchor Tools. The plugin uses GitHub-based updates via Plugin Update Checker.

## Key Commands

**No build tools** — raw PHP/CSS/JS with no transpilation, bundling, or package manager for frontend assets.

```bash
# Install PHP dependencies (required after clone)
composer install

# Version bump: edit the Version header in anchor-tools.php, then create a GitHub release
```

There is no automated test suite. Testing is manual in a WordPress environment.

## Architecture

### Bootstrap Flow

1. `anchor-tools.php` loads Composer autoload, `.env`, and core classes from `includes/`
2. On `plugins_loaded` (priority 25), `anchor_tools_bootstrap_modules()` iterates the module registry
3. For each enabled module: run optional `setup` callback → `require_once` the module file → instantiate the class → run optional `loader` callback
4. Module registry is in `anchor_tools_get_available_modules()` (~line 2870 of `anchor-tools.php`)

### Module System

Every module lives in `anchor-{name}/anchor-{name}.php` and follows one of two patterns:

**CPT-based** (most modules): Register a custom post type, metaboxes, `save_post` handler, admin columns, and shortcode. Data stored in post_meta with a module prefix (e.g. `avg_*`, `up_*`). Examples: video_slider, universal_popups, mega_menu, code_snippets, ctm_forms, events_manager, store_locator, webinars.

**Settings-page-based**: Register an options page under Settings and store data in a single option. Examples: social_feed, shortcodes.

See `ADDING-MODULES.md` for the full module development guide.

### Module List

| Key | Class | Pattern |
|-----|-------|---------|
| `social_feed` | `Anchor_Social_Feed_Module` | Settings page |
| `mega_menu` | `Anchor_Mega_Menu_Module` | CPT |
| `events_manager` | `\Anchor\Events\Module` | CPT (namespaced) |
| `store_locator` | `\Anchor\StoreLocator\Module` | CPT (namespaced) |
| `webinars` | `\Anchor\Webinars\Module` | CPT (namespaced) |
| `universal_popups` | `Anchor_Universal_Popups_Module` | CPT |
| `shortcodes` | `Anchor_Shortcodes_Module` | Settings page |
| `video_slider` | `Anchor_Video_Slider_Module` | CPT |
| `quick_edit` | `Anchor_Quick_Edit_Module` | No admin UI |
| `ctm_forms` | `Anchor_CTM_Forms_Module` | CPT |
| `code_snippets` | `Anchor_Code_Snippets_Module` | CPT |

### Core Classes (`includes/`)

- `Anchor_Schema_Admin` — Main settings page, API key management, module toggles. Settings stored in `anchor_schema_settings` option.
- `Anchor_Schema_Helper` — Schema.org types, OpenAI API wrapper, JSON-LD utilities.
- `Anchor_Schema_Render` — Outputs JSON-LD on `wp_head`.
- `Anchor_Reviews_Manager` — Google Reviews fetching/caching, `[anchor_reviews]` shortcode.
- `Anchor_Schema_Logger` — Debug logging to PHP `error_log` (enabled in settings).

The AI Bulk Rewriter (~2,700 lines) is embedded directly in `anchor-tools.php` as the `Anchor_Content_Rewriter` class.

### Constants

```php
ANCHOR_TOOLS_PLUGIN_DIR  // plugin_dir_path — use for file paths
ANCHOR_TOOLS_PLUGIN_URL  // plugin_dir_url — use for asset URLs
ANCHOR_TOOLS_PLUGIN_FILE // __FILE__ of main plugin file
```

## Conventions

- **Asset paths**: Always use `ANCHOR_TOOLS_PLUGIN_URL . 'anchor-{module}/assets/'` — never `plugin_dir_url(__FILE__)` inside a module (it resolves incorrectly).
- **Options**: Always pass `autoload=false` as the third argument to `update_option()`.
- **Text domain**: Use `'anchor-schema'` for all translatable strings.
- **Class naming**: `Anchor_{ModuleName}_Module` for non-namespaced modules, `\Anchor\{ModuleName}\Module` for namespaced ones.
- **AJAX actions**: Prefix with `anchor_{module}_` (e.g. `wp_ajax_anchor_video_slider_preview`).
- **Admin assets**: Only enqueue on relevant admin pages — check `$hook` and post type in `admin_enqueue_scripts`.
- **CPT registration**: Use `apply_filters('anchor_{module}_parent_menu', true)` for `show_in_menu` so it can be overridden.
- **JavaScript**: jQuery IIFE pattern `(function($) { ... })(jQuery);` — no ES modules.
- **CSS/JS versions**: Bump version string in `wp_enqueue_*()` calls when updating assets.
- **Frontend rendering**: Shortcode callbacks use `ob_start()` / `return ob_get_clean()`.

## Release Process

1. Bump `Version:` in the plugin header of `anchor-tools.php`
2. Commit and push to `main`
3. Create a GitHub release with a tag and upload the release ZIP
4. Plugin Update Checker detects the new version in WP admin
