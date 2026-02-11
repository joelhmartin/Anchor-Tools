# Adding and Updating Modules in Anchor Tools

This guide explains how to add new modules to Anchor Tools and update existing ones.

## Module Architecture Overview

Anchor Tools uses a modular architecture where each feature is encapsulated in its own module. Modules are:

- **Self-contained**: Each module lives in its own directory
- **Toggleable**: Users can enable/disable modules from the Anchor Tools settings
- **Lazy-loaded**: Modules are only loaded when enabled
- **Non-autoloaded**: Options use `autoload=false` to prevent loading on every page request

## Directory Structure

Each module follows this structure:

```
anchor-tools/
├── anchor-tools.php              # Main plugin file
├── anchor-{module-name}/         # Module directory
│   ├── anchor-{module-name}.php  # Main module file
│   └── assets/                   # Module-specific assets
│       ├── admin.css
│       ├── admin.js
│       └── ...
```

## Creating a New Module

### Step 1: Create the Directory Structure

```bash
mkdir -p anchor-{module-name}/assets
```

### Step 2: Create the Module Class

Create `anchor-{module-name}/anchor-{module-name}.php`:

```php
<?php
/**
 * Anchor Tools module: Anchor {Module Name}.
 * Brief description of what the module does.
 */

if (!defined('ABSPATH')) exit;

class Anchor_{Module_Name}_Module {
    // Optional: Define option key for storing settings
    const OPTION_KEY = 'anchor_{module_name}_options';

    public function __construct() {
        // Register hooks
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        // Add shortcodes, ajax handlers, etc.
    }

    /**
     * Get the assets URL for this module.
     * Always use ANCHOR_TOOLS_PLUGIN_URL instead of plugin_dir_url(__FILE__)
     */
    private function get_assets_url() {
        return ANCHOR_TOOLS_PLUGIN_URL . 'anchor-{module-name}/assets/';
    }

    /**
     * Get the assets directory path for this module.
     */
    private function get_assets_dir() {
        return ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-{module-name}/assets/';
    }

    public function register_menu() {
        add_options_page(
            __('Anchor {Module Name}', 'anchor-schema'),
            __('Anchor {Module Name}', 'anchor-schema'),
            'manage_options',
            'anchor-{module-name}',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_admin_assets($hook) {
        // Only load on your settings page
        if ($hook !== 'settings_page_anchor-{module-name}') {
            return;
        }

        $base = $this->get_assets_url();

        wp_enqueue_style('anchor-{module-name}-admin', $base . 'admin.css', [], '1.0.0');
        wp_enqueue_script('anchor-{module-name}-admin', $base . 'admin.js', ['jquery'], '1.0.0', true);
    }

    public function render_admin_page() {
        // Render your settings page
    }

    /**
     * When saving options, use autoload=false to prevent
     * loading on every WordPress request (un-cachable pattern).
     */
    public function save_options($options) {
        update_option(self::OPTION_KEY, $options, false);
    }
}
```

### Step 3: Register the Module

In `anchor-tools.php`, find the `anchor_tools_get_available_modules()` function and add your module to the array:

```php
function anchor_tools_get_available_modules() {
    return [
        // ... existing modules ...

        '{module_key}' => [
            'label'       => __( 'Anchor {Module Name}', 'anchor-schema' ),
            'description' => __( 'Brief description of the module.', 'anchor-schema' ),
            'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-{module-name}/anchor-{module-name}.php',
            'class'       => 'Anchor_{Module_Name}_Module',
        ],
    ];
}
```

### Module Registration Options

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `label` | string | Yes | Display name shown in settings |
| `description` | string | Yes | Brief description of the module |
| `path` | string | Yes | Full path to the module's main PHP file |
| `class` | string | Yes | Class name to instantiate |
| `setup` | callable | No | Function to run before loading (e.g., add filters) |
| `loader` | callable | No | Custom loader function instead of class instantiation |

### Step 4: Create Asset Files

Create your CSS and JavaScript files in `anchor-{module-name}/assets/`:

**admin.css**
```css
/* Module-specific admin styles */
```

**admin.js**
```javascript
(function($) {
    'use strict';
    // Module-specific admin JavaScript
})(jQuery);
```

## Best Practices

### 1. Use Plugin Constants

Always use the Anchor Tools constants for paths:

```php
// Good
$url = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-my-module/assets/style.css';
$path = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-my-module/assets/script.js';

// Bad - will break when used as a module
$url = plugin_dir_url(__FILE__) . 'assets/style.css';
```

### 2. Non-Autoloaded Options (Un-cachable)

When storing options, disable autoloading to prevent them from being loaded on every page:

```php
// Good - options won't be loaded unless explicitly retrieved
update_option('my_module_options', $data, false);

// Bad - options loaded on every WordPress request
update_option('my_module_options', $data);
// or
update_option('my_module_options', $data, true);
```

### 3. Text Domain

Use `'anchor-schema'` as the text domain for translations:

```php
__('My String', 'anchor-schema')
```

### 4. Class Naming

Follow the pattern `Anchor_{Module_Name}_Module`:

- `Anchor_Quick_Edit_Module`
- `Anchor_Video_Slider_Module`
- `Anchor_Social_Feed_Module`

### 5. Settings Page Registration

Register settings pages under the Settings menu:

```php
add_options_page(
    __('Page Title', 'anchor-schema'),
    __('Menu Title', 'anchor-schema'),
    'manage_options',
    'anchor-{module-slug}',
    [$this, 'render_settings_page']
);
```

### 6. AJAX Handlers

Prefix AJAX actions with your module name:

```php
add_action('wp_ajax_anchor_{module}_action', [$this, 'handle_ajax']);
```

## Updating Existing Modules

### Updating Code

1. Make changes to the module's PHP files
2. Update version numbers in any inline asset registrations
3. Test with the module enabled and disabled

### Updating Assets

1. Update CSS/JS files in the module's `assets/` directory
2. Bump version numbers in `wp_enqueue_style()` and `wp_enqueue_script()` calls

### Database Migrations

If your update requires database changes:

```php
public function __construct() {
    add_action('admin_init', [$this, 'maybe_run_migrations']);
}

public function maybe_run_migrations() {
    $version = get_option('anchor_{module}_version', '0');

    if (version_compare($version, '1.1.0', '<')) {
        $this->migrate_to_1_1_0();
        update_option('anchor_{module}_version', '1.1.0', false);
    }
}
```

## Module Lifecycle

1. **Bootstrap**: `plugins_loaded` hook at priority 25
2. **Check Enabled**: `anchor_tools_is_module_enabled()` checks settings
3. **Setup Callback**: Optional `setup` function runs (e.g., add filters)
4. **File Include**: Module file is `require_once`'d
5. **Instantiation**: Module class is instantiated with `new`
6. **Loader Callback**: Optional `loader` function runs

## Example: Converting a Standalone Plugin

If you have a standalone WordPress plugin to convert:

1. **Remove plugin header**: Change from full plugin header to module comment
2. **Rename class**: Follow `Anchor_{Name}_Module` pattern
3. **Update paths**: Replace `plugin_dir_url(__FILE__)` with `ANCHOR_TOOLS_PLUGIN_URL . 'module-folder/'`
4. **Remove instantiation**: Remove `new ClassName();` at the bottom (module loader handles this)
5. **Move to directory**: Create proper folder structure
6. **Register module**: Add to `anchor_tools_get_available_modules()`

## Debugging

Enable debug logging by checking the "Debug logging" option in Anchor Tools settings. Logs are written to `error.log`.

```php
if (class_exists('Anchor_Schema_Logger')) {
    Anchor_Schema_Logger::log('my_action', ['data' => $value]);
}
```

## Testing

1. **Enable/Disable**: Test that the module works when enabled and doesn't interfere when disabled
2. **Fresh Install**: Test on a site without existing module data
3. **Upgrade Path**: Test upgrading from previous versions
4. **Conflict Check**: Verify no conflicts with other Anchor Tools modules

## Working with External APIs

When integrating with external APIs, follow these patterns:

### Store Credentials Securely

Use the WordPress Settings API with password fields:

```php
add_settings_field('api_key', __('API Key', 'anchor-schema'), function() {
    $opts = $this->get_options();
    printf('<input type="password" name="%s[api_key]" value="%s" class="regular-text" autocomplete="off" />',
        esc_attr(self::OPTION_KEY),
        esc_attr($opts['api_key'] ?? '')
    );
}, 'your-settings-page', 'your-section');
```

### Use wp_remote_* Functions

Always use WordPress HTTP API instead of cURL:

```php
$response = wp_remote_post($url, [
    'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type' => 'application/json',
    ],
    'body' => wp_json_encode($data),
    'timeout' => 30,
]);

if (is_wp_error($response)) {
    return $response;
}

$code = wp_remote_retrieve_response_code($response);
$body = wp_remote_retrieve_body($response);
```

### API Endpoint Troubleshooting

A common issue is using wrong API endpoints. When you get 404 errors:

1. **Check endpoint documentation carefully** - listing endpoints often differ from action endpoints
2. **Log the full URL and response** for debugging:
   ```php
   error_log('API URL: ' . $url);
   error_log('API Response: ' . wp_remote_retrieve_body($response));
   ```
3. **Verify authentication** - some endpoints require different auth methods

**Real-world example** - CTM FormReactor API:
- **Listing endpoint**: `/api/v1/accounts/{account_id}/form_reactors/{id}`
- **Submission endpoint**: `/api/v1/formreactor/{id}` (different path!)

### Caching API Responses

Use transients for cacheable data:

```php
$cache_key = 'my_api_data_' . md5($unique_identifier);
$cached = get_transient($cache_key);

if ($cached !== false) {
    return $cached;
}

$data = $this->fetch_from_api();
set_transient($cache_key, $data, 10 * MINUTE_IN_SECONDS);
return $data;
```

## Available Modules Reference

Current modules in Anchor Tools:

| Module Key | Class Name | Description |
|------------|------------|-------------|
| `social_feed` | `Anchor_Social_Feed_Module` | Display curated social feeds |
| `mega_menu` | `Anchor_Mega_Menu_Module` | Create reusable mega menu snippets |
| `events_manager` | `\Anchor\Events\Module` | Manage events, calendars, registrations |
| `store_locator` | `\Anchor\StoreLocator\Module` | Map-based store locator |
| `webinars` | `\Anchor\Webinars\Module` | Gated webinars with Vimeo tracking |
| `universal_popups` | `Anchor_Universal_Popups_Module` | HTML/video popups with triggers |
| `shortcodes` | `Anchor_Shortcodes_Module` | Business info + custom shortcodes |
| `video_slider` | `Anchor_Video_Slider_Module` | Horizontal video slider |
| `quick_edit` | `Anchor_Quick_Edit_Module` | Quick Edit for Yoast SEO + images |
| `ctm_forms` | `Anchor_CTM_Forms_Module` | CallTrackingMetrics form integration |
| `code_snippets` | `Anchor_Code_Snippets_Module` | Insert code snippets into header, body, or footer |
