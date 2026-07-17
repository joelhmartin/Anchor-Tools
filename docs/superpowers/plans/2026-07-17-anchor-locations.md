# Anchor Locations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a Phase-1 `anchor-locations` Anchor Tools module: a data model + renderer + Google map for programmatic service-area SEO, populated externally (AI/WP-CLI), rendering per-page Monaco content theme-agnostically with auto-generated internal linking, hierarchy, breadcrumbs, and JSON-LD.

**Architecture:** Two CPTs (`anchor_location` hierarchical hub, `anchor_service_page` flat service×location) + one internal hierarchical `service` taxonomy. Pretty two-segment service URLs come from a custom rewrite rule + `post_type_link` filter — the taxonomy never appears in the URL. Frontend renders each post's Monaco HTML/CSS/JS via the `the_content` filter (Divi/any-theme friendly) plus an `[anchor_page_content]` shortcode escape hatch and an optional single global wrapper template. Map + directory shortcodes are generated from the hierarchy. Reuses existing shared infra: `Anchor_Monaco`, `Anchor_Preview_CSS`, `Anchor_Asset_Loader`, the `anchor_settings_tabs` filter, and the existing Google Maps key.

**Tech Stack:** PHP (WordPress, namespaced `\Anchor\Locations\`), jQuery IIFE, Google Maps JS API, Monaco (via shared loader), PHPUnit (WP test lib). No transpilation/bundling.

## Global Constraints

- Module key `locations`; directory `anchor-locations/`; class `\Anchor\Locations\Module`; self-contained/namespaced for possible extraction.
- Text domain for all strings: `anchor-schema`.
- All post meta keys prefixed `al_`. AJAX actions prefixed `anchor_locations_`.
- Asset URLs via `ANCHOR_TOOLS_PLUGIN_URL . 'anchor-locations/assets/…'` (never `plugin_dir_url(__FILE__)`).
- `update_option()` always with `autoload=false` third arg.
- Enqueue **source** `.css`/`.js`; never author/commit `*.min.*` (CI-generated, gitignored).
- Admin assets enqueue only on `post.php`/`post-new.php` with a CPT check.
- CPT `show_in_menu` via `apply_filters('anchor_locations_parent_menu', true)`.
- Google Maps API key read from `get_option( \Anchor_Schema_Admin::OPTION_KEY )['google_api_key']` — no new key field.
- Settings option key `anchor_locations_settings`, single option, `autoload=false`.
- Rewrite bases configurable; defaults `services` and `service-areas`. Flush rewrites on activation + base change only.
- Never emit a physical `PostalAddress` for a service-area location.
- PHPUnit tests follow existing `tests/` conventions (base `WP_UnitTestCase`/repo base case, `WP_TESTS_DIR`/`WP_CORE_DIR` env).

---

## File Structure

```
anchor-locations/
  anchor-locations.php     # \Anchor\Locations\Module — all PHP: CPTs, taxonomy, rewrites,
                           #   permalinks, render, shortcodes, map, schema, settings tab, admin
  assets/
    admin.css              # metabox styling
    admin.js               # mounts shared Monaco, preview wiring, media picker glue
    frontend.css           # map container, directory/accordion, breadcrumb styles
    frontend.js            # Google Maps init, markers, popups, clustering, directory accordion
tests/
  LocationsRewriteTest.php     # rewrite resolution + permalink filter
  LocationsShortcodesTest.php  # directory/linking shortcodes + breadcrumbs
  LocationsSchemaTest.php      # JSON-LD shape per CPT
  LocationsSettingsTest.php    # settings save/sanitize + map data query
```

Follows the repo's single-file-per-module convention (like `anchor-store-locator.php`, `anchor-blocks.php`). One PHP class file; split only if it grows unwieldy.

**Reference files to read before starting (existing patterns to mirror):**
- `anchor-blocks/anchor-blocks.php` — Monaco metabox mount (`data-anchor-monaco`), scoped CSS/footer JS print, shortcode render.
- `anchor-store-locator/anchor-store-locator.php` — gated Maps JS enqueue, `get_google_api_key()`, settings tab, admin columns.
- `anchor-webinars/anchor-webinars.php` — public CPT, `template_include` + `the_content` render pattern, `is_singular` gating.
- `includes/class-anchor-monaco.php` — `Anchor_Monaco::enqueue($cpt)` signature.
- `includes/class-anchor-settings-page.php` — `anchor_settings_tabs` filter + `anchor_settings_enqueue_{slug}` action contract.
- `anchor-tools.php:183-300` — `anchor_tools_get_available_modules()` registry.

---

## Task 1: Module skeleton, CPTs, taxonomy, registry wiring

**Files:**
- Create: `anchor-locations/anchor-locations.php`
- Modify: `anchor-tools.php` (add `locations` entry to `anchor_tools_get_available_modules()`, ~line 297 after `translate` or grouped near `store_locator`)
- Test: `tests/LocationsRewriteTest.php` (CPT/taxonomy registration assertions only in this task)

**Interfaces:**
- Produces: class `\Anchor\Locations\Module` with `const CPT_LOCATION = 'anchor_location'`, `const CPT_SERVICE = 'anchor_service_page'`, `const TAX_SERVICE = 'service'`, `const OPTION = 'anchor_locations_settings'`, `const NONCE = 'anchor_locations_nonce'`. Constructor hooks `init` → `register_types()`. Public `register_types()` registers both CPTs + taxonomy.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/LocationsRewriteTest.php
class LocationsRewriteTest extends WP_UnitTestCase {
    public function test_post_types_and_taxonomy_registered() {
        $this->assertTrue( post_type_exists( 'anchor_location' ) );
        $this->assertTrue( post_type_exists( 'anchor_service_page' ) );
        $this->assertTrue( taxonomy_exists( 'service' ) );
        $this->assertTrue( is_post_type_hierarchical( 'anchor_location' ) );
        $this->assertFalse( is_post_type_hierarchical( 'anchor_service_page' ) );
        $this->assertTrue( is_taxonomy_hierarchical( 'service' ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress && composer test -- --filter LocationsRewriteTest`
Expected: FAIL (post types not registered).

- [ ] **Step 3: Write minimal implementation**

Create `anchor-locations/anchor-locations.php`:

```php
<?php
/**
 * Anchor Tools module: Anchor Locations.
 * Service-area & service-location pages with a linked Google map, hierarchy, and internal linking.
 */
namespace Anchor\Locations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Module {
    const CPT_LOCATION = 'anchor_location';
    const CPT_SERVICE  = 'anchor_service_page';
    const TAX_SERVICE  = 'service';
    const OPTION       = 'anchor_locations_settings';
    const NONCE        = 'anchor_locations_nonce';

    public function __construct() {
        \add_action( 'init', [ $this, 'register_types' ] );
    }

    public function register_types() {
        \register_post_type( self::CPT_LOCATION, [
            'labels'       => [ 'name' => 'Locations', 'singular_name' => 'Location', 'menu_name' => 'Anchor Locations', 'add_new_item' => 'Add New Location', 'edit_item' => 'Edit Location' ],
            'public'       => true,
            'hierarchical' => true,
            'show_in_menu' => \apply_filters( 'anchor_locations_parent_menu', true ),
            'menu_icon'    => 'dashicons-location-alt',
            'supports'     => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
            'rewrite'      => [ 'slug' => $this->service_areas_base(), 'with_front' => false ],
            'has_archive'  => false,
        ] );

        \register_post_type( self::CPT_SERVICE, [
            'labels'       => [ 'name' => 'Service Pages', 'singular_name' => 'Service Page', 'add_new_item' => 'Add New Service Page', 'edit_item' => 'Edit Service Page' ],
            'public'       => true,
            'hierarchical' => false,
            'show_in_menu' => 'edit.php?post_type=' . self::CPT_LOCATION,
            'supports'     => [ 'title', 'editor', 'thumbnail' ],
            'rewrite'      => false,
            'has_archive'  => false,
        ] );

        \register_taxonomy( self::TAX_SERVICE, self::CPT_SERVICE, [
            'labels'       => [ 'name' => 'Services', 'singular_name' => 'Service' ],
            'public'       => false,
            'show_ui'      => true,
            'hierarchical' => true,
            'rewrite'      => false,
        ] );
    }

    private function settings() {
        $o = \get_option( self::OPTION, [] );
        return \is_array( $o ) ? $o : [];
    }
    private function service_areas_base() {
        $s = $this->settings();
        return ! empty( $s['service_areas_base'] ) ? \sanitize_title( $s['service_areas_base'] ) : 'service-areas';
    }
    private function services_base() {
        $s = $this->settings();
        return ! empty( $s['services_base'] ) ? \sanitize_title( $s['services_base'] ) : 'services';
    }
}
```

Add to `anchor_tools_get_available_modules()` in `anchor-tools.php` (after the `translate` entry, before the closing `];`):

```php
            'locations' => [
                'label'       => __( 'Anchor Locations', 'anchor-schema' ),
                'description' => __( 'Service-area & service-location pages with a linked Google map, hierarchy, and internal linking.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-locations/anchor-locations.php',
                'class'       => '\\Anchor\\Locations\\Module',
            ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter LocationsRewriteTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-locations/anchor-locations.php anchor-tools.php tests/LocationsRewriteTest.php
git commit -m "feat(locations): register location/service CPTs + service taxonomy"
```

---

## Task 2: Rewrite rule + permalink filter for `/services/{service}/{location}/`

**Files:**
- Modify: `anchor-locations/anchor-locations.php`
- Test: `tests/LocationsRewriteTest.php`

**Interfaces:**
- Consumes: `Module::CPT_SERVICE`, `Module::CPT_LOCATION`, `Module::TAX_SERVICE`, `services_base()`.
- Produces: `add_rewrite_rules()` (hooked `init`), `query_vars` filter adding `al_service`,`al_loc`, `resolve_service_request( \WP $wp )` on `parse_request`, `service_permalink( $url, $post )` on `post_type_link`. Public `service_page_url( int $post_id ): string` used by shortcodes/map/schema. Public static `activate()` (flush) + `maybe_flush()`.

- [ ] **Step 1: Write the failing test**

```php
// append to tests/LocationsRewriteTest.php
public function test_service_permalink_built_from_service_and_location() {
    $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_name' => 'pittsburgh-pa', 'post_status' => 'publish' ] );
    $term = wp_insert_term( 'Roofing', 'service' );
    $sp = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_name' => 'roofing-pittsburgh-pa', 'post_status' => 'publish' ] );
    wp_set_object_terms( $sp, [ (int) $term['term_id'] ], 'service' );
    update_post_meta( $sp, 'al_location_id', $loc );

    $url = get_permalink( $sp );
    $this->assertStringContainsString( '/services/roofing/pittsburgh-pa/', $url );
}
public function test_service_permalink_is_hash_when_link_missing() {
    $sp = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish' ] );
    $this->assertSame( '#', get_permalink( $sp ) );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter LocationsRewriteTest`
Expected: FAIL on the two new methods (permalink not yet filtered).

- [ ] **Step 3: Write minimal implementation**

Add to the constructor:

```php
        \add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        \add_filter( 'query_vars', [ $this, 'query_vars' ] );
        \add_action( 'parse_request', [ $this, 'resolve_service_request' ] );
        \add_filter( 'post_type_link', [ $this, 'service_permalink' ], 10, 2 );
        \add_action( 'init', [ $this, 'maybe_flush' ], 99 );
```

Add methods:

```php
    public function add_rewrite_rules() {
        $base = $this->services_base();
        \add_rewrite_rule( '^' . $base . '/([^/]+)/([^/]+)/?$', 'index.php?al_service=$matches[1]&al_loc=$matches[2]', 'top' );
    }
    public function query_vars( $vars ) { $vars[] = 'al_service'; $vars[] = 'al_loc'; return $vars; }

    public function resolve_service_request( $wp ) {
        if ( empty( $wp->query_vars['al_service'] ) || empty( $wp->query_vars['al_loc'] ) ) { return; }
        $service = \sanitize_title( $wp->query_vars['al_service'] );
        $loc     = \sanitize_title( $wp->query_vars['al_loc'] );
        $post_id = $this->find_service_page( $service, $loc );
        if ( $post_id ) {
            $wp->query_vars = [ 'post_type' => self::CPT_SERVICE, 'p' => $post_id ];
        } else {
            $wp->query_vars = [ 'error' => '404' ];
        }
    }

    /** Find a published service page by service term slug + linked location slug. */
    private function find_service_page( $service_slug, $loc_slug ) {
        $loc = \get_page_by_path( $loc_slug, OBJECT, self::CPT_LOCATION );
        if ( ! $loc ) { return 0; }
        $q = new \WP_Query( [
            'post_type'      => self::CPT_SERVICE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => [ [ 'taxonomy' => self::TAX_SERVICE, 'field' => 'slug', 'terms' => $service_slug ] ],
            'meta_query'     => [ [ 'key' => 'al_location_id', 'value' => $loc->ID ] ],
        ] );
        return $q->have_posts() ? (int) $q->posts[0] : 0;
    }

    public function service_page_url( $post_id ) {
        $loc_id = (int) \get_post_meta( $post_id, 'al_location_id', true );
        if ( ! $loc_id ) { return '#'; }
        $loc = \get_post( $loc_id );
        $terms = \wp_get_object_terms( $post_id, self::TAX_SERVICE, [ 'fields' => 'slugs' ] );
        if ( ! $loc || \is_wp_error( $terms ) || empty( $terms ) ) { return '#'; }
        return \home_url( '/' . $this->services_base() . '/' . $terms[0] . '/' . $loc->post_name . '/' );
    }

    public function service_permalink( $url, $post ) {
        if ( \is_object( $post ) && $post->post_type === self::CPT_SERVICE ) {
            return $this->service_page_url( $post->ID );
        }
        return $url;
    }

    public static function activate() { \flush_rewrite_rules(); }

    public function maybe_flush() {
        $s = $this->settings();
        $sig = ( $s['services_base'] ?? 'services' ) . '|' . ( $s['service_areas_base'] ?? 'service-areas' ) . '|v1';
        if ( \get_option( 'anchor_locations_rw_sig' ) !== $sig ) {
            $this->add_rewrite_rules();
            \flush_rewrite_rules( false );
            \update_option( 'anchor_locations_rw_sig', $sig, false );
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter LocationsRewriteTest`
Expected: PASS (all 4 methods).

- [ ] **Step 5: Commit**

```bash
git add anchor-locations/anchor-locations.php tests/LocationsRewriteTest.php
git commit -m "feat(locations): custom rewrite rule + permalink for service pages"
```

---

## Task 3: Admin — metaboxes, Monaco mount, save handler, admin columns

**Files:**
- Modify: `anchor-locations/anchor-locations.php`
- Create: `anchor-locations/assets/admin.css`, `anchor-locations/assets/admin.js`

**Interfaces:**
- Consumes: CPT/meta constants; `\Anchor_Monaco::enqueue()`, `\Anchor_Preview_CSS`, `\Anchor_Asset_Loader::url()`.
- Produces: `add_metaboxes()`, `render_content_metabox( $post )`, `render_details_metabox( $post )`, `save_meta( $post_id )`, `admin_assets( $hook )`, admin column callbacks. Meta saved: `al_html/al_css/al_js/al_type/al_lat/al_lng/al_place_id/al_state_abbr/al_county/al_postal_codes/al_boundary/al_marker_icon/al_disable_wrapper` on locations; `al_location_id/al_html/al_css/al_js/al_disable_wrapper` on service pages.

- [ ] **Step 1: Add hooks + metabox/save/enqueue code**

Constructor additions:

```php
        \add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
        \add_action( 'save_post', [ $this, 'save_meta' ] );
        \add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        \add_filter( 'manage_' . self::CPT_LOCATION . '_posts_columns', [ $this, 'location_columns' ] );
        \add_action( 'manage_' . self::CPT_LOCATION . '_posts_custom_column', [ $this, 'location_column' ], 10, 2 );
        \add_filter( 'manage_' . self::CPT_SERVICE . '_posts_columns', [ $this, 'service_columns' ] );
        \add_action( 'manage_' . self::CPT_SERVICE . '_posts_custom_column', [ $this, 'service_column' ], 10, 2 );
```

Implement (full code — mirror `anchor-blocks` Monaco mount and `anchor-store-locator` columns):

```php
    public function add_metaboxes() {
        foreach ( [ self::CPT_LOCATION, self::CPT_SERVICE ] as $cpt ) {
            \add_meta_box( 'al_content', 'Content (HTML / CSS / JS)', [ $this, 'render_content_metabox' ], $cpt, 'normal', 'high' );
            \add_meta_box( 'al_details', 'Details', [ $this, 'render_details_metabox' ], $cpt, 'side', 'default' );
        }
    }

    public function render_content_metabox( $post ) {
        \wp_nonce_field( self::NONCE, self::NONCE );
        $html = \get_post_meta( $post->ID, 'al_html', true );
        $css  = \get_post_meta( $post->ID, 'al_css', true );
        $js   = \get_post_meta( $post->ID, 'al_js', true );
        $spec = [
            [ 'id' => 'al_html', 'label' => 'HTML', 'lang' => 'html' ],
            [ 'id' => 'al_css',  'label' => 'CSS',  'lang' => 'css'  ],
            [ 'id' => 'al_js',   'label' => 'JS',   'lang' => 'javascript' ],
        ];
        echo '<div class="anchor-monaco" data-anchor-monaco="' . \esc_attr( \wp_json_encode( $spec ) ) . '">';
        echo '<textarea id="al_html" name="al_html" style="display:none">' . \esc_textarea( $html ) . '</textarea>';
        echo '<textarea id="al_css" name="al_css" style="display:none">' . \esc_textarea( $css ) . '</textarea>';
        echo '<textarea id="al_js" name="al_js" style="display:none">' . \esc_textarea( $js ) . '</textarea>';
        echo '</div>';
        $dis = \get_post_meta( $post->ID, 'al_disable_wrapper', true );
        echo '<p><label><input type="checkbox" name="al_disable_wrapper" value="1" ' . \checked( $dis, '1', false ) . '> Disable global wrapper on this page (Divi/builder mode)</label></p>';
    }

    public function render_details_metabox( $post ) {
        if ( $post->post_type === self::CPT_SERVICE ) {
            $loc = (int) \get_post_meta( $post->ID, 'al_location_id', true );
            echo '<p><label>Linked Location (post ID)<br><input type="number" name="al_location_id" value="' . \esc_attr( $loc ) . '" class="widefat"></label></p>';
            echo '<p class="description">Set the Service term via the Services box. Both are required for a live URL.</p>';
            return;
        }
        $f = function( $k ) use ( $post ) { return \esc_attr( \get_post_meta( $post->ID, $k, true ) ); };
        $types = [ 'state','county','city','township','borough','neighborhood','region' ];
        echo '<p><label>Type<br><select name="al_type" class="widefat">';
        $cur = $f( 'al_type' );
        foreach ( $types as $t ) { echo '<option value="' . $t . '" ' . \selected( $cur, $t, false ) . '>' . \ucfirst( $t ) . '</option>'; }
        echo '</select></label></p>';
        echo '<p><label>Latitude<br><input type="text" name="al_lat" value="' . $f('al_lat') . '" class="widefat"></label></p>';
        echo '<p><label>Longitude<br><input type="text" name="al_lng" value="' . $f('al_lng') . '" class="widefat"></label></p>';
        echo '<p><label>State abbr<br><input type="text" name="al_state_abbr" value="' . $f('al_state_abbr') . '" class="widefat"></label></p>';
        echo '<p><label>Place ID<br><input type="text" name="al_place_id" value="' . $f('al_place_id') . '" class="widefat"></label></p>';
        echo '<p><label>Postal codes<br><input type="text" name="al_postal_codes" value="' . $f('al_postal_codes') . '" class="widefat"></label></p>';
        echo '<p><label>Marker icon URL<br><input type="text" name="al_marker_icon" value="' . $f('al_marker_icon') . '" class="widefat al-media"></label></p>';
        echo '<p><label>Boundary GeoJSON<br><textarea name="al_boundary" class="widefat" rows="3">' . \esc_textarea( \get_post_meta( $post->ID, 'al_boundary', true ) ) . '</textarea></label></p>';
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! \wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) { return; }
        if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! \current_user_can( 'edit_post', $post_id ) ) { return; }
        $raw = [ 'al_html', 'al_css', 'al_js' ];               // code fields: keep as-is (unslashed)
        foreach ( $raw as $k ) { if ( isset( $_POST[ $k ] ) ) { \update_post_meta( $post_id, $k, \wp_unslash( $_POST[ $k ] ) ); } }
        $text = [ 'al_type','al_lat','al_lng','al_place_id','al_state_abbr','al_county','al_postal_codes','al_marker_icon' ];
        foreach ( $text as $k ) { if ( isset( $_POST[ $k ] ) ) { \update_post_meta( $post_id, $k, \sanitize_text_field( \wp_unslash( $_POST[ $k ] ) ) ); } }
        if ( isset( $_POST['al_location_id'] ) ) { \update_post_meta( $post_id, 'al_location_id', (int) $_POST['al_location_id'] ); }
        if ( isset( $_POST['al_boundary'] ) ) { \update_post_meta( $post_id, 'al_boundary', \wp_unslash( $_POST['al_boundary'] ) ); }
        \update_post_meta( $post_id, 'al_disable_wrapper', isset( $_POST['al_disable_wrapper'] ) ? '1' : '' );
        \delete_transient( 'anchor_locations_mapdata' );
    }

    public function admin_assets( $hook ) {
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) { return; }
        $screen = \get_current_screen();
        if ( ! $screen || ! \in_array( $screen->post_type, [ self::CPT_LOCATION, self::CPT_SERVICE ], true ) ) { return; }
        \Anchor_Monaco::enqueue( $screen->post_type );
        if ( \class_exists( '\\Anchor_Preview_CSS' ) ) { \Anchor_Preview_CSS::enqueue_for_admin(); }  // static; registers 'anchor-preview'
        $dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-locations/assets/';
        \wp_enqueue_style( 'anchor-locations-admin', \Anchor_Asset_Loader::url( 'anchor-locations/assets/admin.css' ), [], (string) \filemtime( $dir . 'admin.css' ) );
        \wp_enqueue_script( 'anchor-locations-admin', \Anchor_Asset_Loader::url( 'anchor-locations/assets/admin.js' ), [ 'jquery', 'anchor-monaco', 'anchor-preview' ], (string) \filemtime( $dir . 'admin.js' ), true );
    }

    public function location_columns( $c ) { $c['al_type'] = 'Type'; return $c; }
    public function location_column( $col, $post_id ) { if ( $col === 'al_type' ) { echo \esc_html( \ucfirst( (string) \get_post_meta( $post_id, 'al_type', true ) ) ); } }
    public function service_columns( $c ) { $c['al_link'] = 'Service / Location'; return $c; }
    public function service_column( $col, $post_id ) {
        if ( $col !== 'al_link' ) { return; }
        $terms = \wp_get_object_terms( $post_id, self::TAX_SERVICE, [ 'fields' => 'names' ] );
        $loc   = (int) \get_post_meta( $post_id, 'al_location_id', true );
        if ( empty( $terms ) || ! $loc || ! \get_post( $loc ) ) { echo '⚠ incomplete'; return; }
        echo \esc_html( $terms[0] . ' — ' . \get_the_title( $loc ) );
    }
```

Verify `\Anchor_Preview_CSS::enqueue()` exists; if the method name differs, read `includes/class-anchor-preview-css.php` and match it. (If it exposes no static enqueue, follow how `anchor-blocks` triggers preview and mirror that instead.)

- [ ] **Step 2: Create `anchor-locations/assets/admin.css`**

```css
.al-media { cursor: pointer; }
#al_content .anchor-monaco { margin-bottom: 8px; }
#al_details p { margin: 0 0 10px; }
```

- [ ] **Step 3: Create `anchor-locations/assets/admin.js`**

```js
(function ($) {
  'use strict';
  // Monaco auto-mounts via the shared anchor-monaco.js on .anchor-monaco[data-anchor-monaco].
  // Media picker for marker icon URL fields.
  $(document).on('click', '.al-media', function (e) {
    e.preventDefault();
    var $input = $(this);
    var frame = wp.media({ title: 'Select icon', multiple: false });
    frame.on('select', function () {
      var att = frame.state().get('selection').first().toJSON();
      $input.val(att.url).trigger('change');
    });
    frame.open();
  });
})(jQuery);
```

- [ ] **Step 4: Manual verify**

Run `npm run wp-env start` (if available) or on a live sandbox; open a new Location — confirm three Monaco tabs render and save. If the WP env is not available in this session, note it and rely on Task 8 E2E docs.

- [ ] **Step 5: Commit**

```bash
git add anchor-locations/
git commit -m "feat(locations): admin metaboxes with Monaco, save handler, columns"
```

---

## Task 4: Frontend render — the_content wrapper, global template, `[anchor_page_content]`

**Files:**
- Modify: `anchor-locations/anchor-locations.php`
- Test: `tests/LocationsShortcodesTest.php`

**Interfaces:**
- Consumes: CPT constants, `settings()`.
- Produces: `render_body( int $post_id ): string` (returns scoped `<style>`+html+`<script>`), `the_content_render( $content )` on `the_content` (priority 9), `shortcode_page_content( $atts )` for `[anchor_page_content]`, `apply_wrapper( string $body, int $post_id ): string`. Global wrapper token `{{content}}` replaced with body; `[anchor_page_content]` inside wrapper also resolves to body.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/LocationsShortcodesTest.php
class LocationsShortcodesTest extends WP_UnitTestCase {
    public function test_render_body_outputs_scoped_html_css_js() {
        $id = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
        update_post_meta( $id, 'al_html', '<h1>Pittsburgh</h1>' );
        update_post_meta( $id, 'al_css', 'h1{color:red}' );
        update_post_meta( $id, 'al_js', 'console.log(1)' );
        $mod = new \Anchor\Locations\Module();
        $out = $mod->render_body( $id );
        $this->assertStringContainsString( '<h1>Pittsburgh</h1>', $out );
        $this->assertStringContainsString( 'color:red', $out );
        $this->assertStringContainsString( 'console.log(1)', $out );
    }
    public function test_page_content_shortcode_by_id() {
        $id = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
        update_post_meta( $id, 'al_html', '<p>Body</p>' );
        $out = do_shortcode( '[anchor_page_content id="' . $id . '"]' );
        $this->assertStringContainsString( '<p>Body</p>', $out );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter LocationsShortcodesTest`
Expected: FAIL (methods/shortcode missing).

- [ ] **Step 3: Write minimal implementation**

Constructor:

```php
        \add_filter( 'the_content', [ $this, 'the_content_render' ], 9 );
        \add_shortcode( 'anchor_page_content', [ $this, 'shortcode_page_content' ] );
```

Methods (reuse the anchor-blocks scoping approach — wrap CSS in an id-scoped `<style>`; print JS in an IIFE):

```php
    public function render_body( $post_id ) {
        $html = (string) \get_post_meta( $post_id, 'al_html', true );
        $css  = (string) \get_post_meta( $post_id, 'al_css', true );
        $js   = (string) \get_post_meta( $post_id, 'al_js', true );
        $scope = 'al-page-' . (int) $post_id;
        $out = '<div class="anchor-locations-page ' . \esc_attr( $scope ) . '">';
        if ( $css !== '' ) {
            $scoped = \preg_replace( '/(^|\})\s*([^@\}\{]+)\{/', '$1 .' . $scope . ' $2{', $css );
            $out .= '<style>' . $scoped . '</style>';
        }
        $out .= \do_shortcode( $html );
        if ( $js !== '' ) { $out .= '<script>(function(){' . $js . '})();</script>'; }
        $out .= '</div>';
        return $out;
    }

    public function apply_wrapper( $body, $post_id ) {
        if ( \get_post_meta( $post_id, 'al_disable_wrapper', true ) === '1' ) { return $body; }
        $s = $this->settings();
        $tpl_html = $s['wrapper_html'] ?? '';
        if ( \trim( $tpl_html ) === '' ) { return $body; }
        $tpl_css = $s['wrapper_css'] ?? '';
        $tpl_js  = $s['wrapper_js'] ?? '';
        $out = '';
        if ( \trim( $tpl_css ) !== '' ) { $out .= '<style>' . $tpl_css . '</style>'; }
        $filled = \str_replace( '{{content}}', $body, $tpl_html );
        $filled = \str_replace( '[anchor_page_content]', $body, $filled );
        $out .= \do_shortcode( $filled );
        if ( \trim( $tpl_js ) !== '' ) { $out .= '<script>(function(){' . $tpl_js . '})();</script>'; }
        return $out;
    }

    public function the_content_render( $content ) {
        if ( ! \is_singular( [ self::CPT_LOCATION, self::CPT_SERVICE ] ) || ! \in_the_loop() || ! \is_main_query() ) { return $content; }
        $post_id = \get_the_ID();
        $body = $this->render_body( $post_id );
        return $this->apply_wrapper( $body, $post_id );
    }

    public function shortcode_page_content( $atts ) {
        $atts = \shortcode_atts( [ 'id' => 0 ], $atts, 'anchor_page_content' );
        $id = (int) $atts['id'] ? (int) $atts['id'] : \get_the_ID();
        if ( ! $id ) { return ''; }
        return $this->render_body( $id );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter LocationsShortcodesTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-locations/anchor-locations.php tests/LocationsShortcodesTest.php
git commit -m "feat(locations): frontend body render, global wrapper, page-content shortcode"
```

---

## Task 5: Internal-linking + directory + breadcrumb shortcodes

**Files:**
- Modify: `anchor-locations/anchor-locations.php`
- Test: `tests/LocationsShortcodesTest.php`

**Interfaces:**
- Consumes: `service_page_url()`, CPT/taxonomy constants.
- Produces shortcodes (all registered in constructor): `[anchor_breadcrumbs]`, `[anchor_child_locations]`, `[anchor_location_parent]`, `[anchor_nearby_locations]`, `[anchor_location_services]`, `[anchor_service_locations]`, `[anchor_service_area_directory]`. Each returns HTML listing **published** posts only; each wrapped in `apply_filters( 'anchor_locations_{name}_html', $html, $context )`.

- [ ] **Step 1: Write the failing test**

```php
// append to tests/LocationsShortcodesTest.php
public function test_child_locations_lists_only_published_children() {
    $county = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Allegheny County' ] );
    $city   = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh', 'post_parent' => $county ] );
    self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'draft', 'post_title' => 'Hidden', 'post_parent' => $county ] );
    $out = do_shortcode( '[anchor_child_locations id="' . $county . '"]' );
    $this->assertStringContainsString( 'Pittsburgh', $out );
    $this->assertStringNotContainsString( 'Hidden', $out );
}
public function test_location_services_lists_service_pages_for_location() {
    $loc  = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_name' => 'pittsburgh-pa' ] );
    $term = wp_insert_term( 'Roofing', 'service' );
    $sp   = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Roofing in Pittsburgh' ] );
    wp_set_object_terms( $sp, [ (int) $term['term_id'] ], 'service' );
    update_post_meta( $sp, 'al_location_id', $loc );
    $out = do_shortcode( '[anchor_location_services id="' . $loc . '"]' );
    $this->assertStringContainsString( 'Roofing in Pittsburgh', $out );
    $this->assertStringContainsString( '/services/roofing/pittsburgh-pa/', $out );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter LocationsShortcodesTest`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Constructor (register all):

```php
        \add_shortcode( 'anchor_breadcrumbs', [ $this, 'sc_breadcrumbs' ] );
        \add_shortcode( 'anchor_child_locations', [ $this, 'sc_child_locations' ] );
        \add_shortcode( 'anchor_location_parent', [ $this, 'sc_parent' ] );
        \add_shortcode( 'anchor_nearby_locations', [ $this, 'sc_nearby' ] );
        \add_shortcode( 'anchor_location_services', [ $this, 'sc_location_services' ] );
        \add_shortcode( 'anchor_service_locations', [ $this, 'sc_service_locations' ] );
        \add_shortcode( 'anchor_service_area_directory', [ $this, 'sc_directory' ] );
```

Methods:

```php
    private function cur_id( $atts ) { $a = \shortcode_atts( [ 'id' => 0 ], $atts ); return (int) $a['id'] ? (int) $a['id'] : (int) \get_the_ID(); }

    public function sc_child_locations( $atts ) {
        $id = $this->cur_id( $atts );
        $kids = \get_posts( [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'post_parent' => $id, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
        $html = '';
        if ( $kids ) {
            $html = '<ul class="al-child-locations">';
            foreach ( $kids as $k ) { $html .= '<li><a href="' . \esc_url( \get_permalink( $k ) ) . '">' . \esc_html( \get_the_title( $k ) ) . '</a></li>'; }
            $html .= '</ul>';
        }
        return \apply_filters( 'anchor_locations_child_locations_html', $html, $id );
    }

    public function sc_parent( $atts ) {
        $id = $this->cur_id( $atts );
        $p = (int) \get_post( $id )->post_parent;
        $html = $p ? '<a class="al-parent" href="' . \esc_url( \get_permalink( $p ) ) . '">' . \esc_html( \get_the_title( $p ) ) . '</a>' : '';
        return \apply_filters( 'anchor_locations_location_parent_html', $html, $id );
    }

    public function sc_nearby( $atts ) {
        $id = $this->cur_id( $atts );
        $parent = (int) \get_post( $id )->post_parent;
        $sibs = $parent ? \get_posts( [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'post_parent' => $parent, 'exclude' => [ $id ], 'numberposts' => 12, 'orderby' => 'title', 'order' => 'ASC' ] ) : [];
        $html = '';
        if ( $sibs ) {
            $html = '<ul class="al-nearby">';
            foreach ( $sibs as $s ) { $html .= '<li><a href="' . \esc_url( \get_permalink( $s ) ) . '">' . \esc_html( \get_the_title( $s ) ) . '</a></li>'; }
            $html .= '</ul>';
        }
        return \apply_filters( 'anchor_locations_nearby_locations_html', $html, $id );
    }

    public function sc_breadcrumbs( $atts ) {
        $id = $this->cur_id( $atts );
        $crumbs = [ '<a href="' . \esc_url( \home_url( '/' ) ) . '">Home</a>' ];
        $post = \get_post( $id );
        if ( $post && $post->post_type === self::CPT_SERVICE ) {
            $loc = (int) \get_post_meta( $id, 'al_location_id', true );
            $anc = $loc ? \array_reverse( \get_post_ancestors( $loc ) ) : [];
            foreach ( $anc as $aid ) { $crumbs[] = '<a href="' . \esc_url( \get_permalink( $aid ) ) . '">' . \esc_html( \get_the_title( $aid ) ) . '</a>'; }
            if ( $loc ) { $crumbs[] = '<a href="' . \esc_url( \get_permalink( $loc ) ) . '">' . \esc_html( \get_the_title( $loc ) ) . '</a>'; }
            $crumbs[] = \esc_html( \get_the_title( $id ) );
        } elseif ( $post ) {
            foreach ( \array_reverse( \get_post_ancestors( $id ) ) as $aid ) { $crumbs[] = '<a href="' . \esc_url( \get_permalink( $aid ) ) . '">' . \esc_html( \get_the_title( $aid ) ) . '</a>'; }
            $crumbs[] = \esc_html( \get_the_title( $id ) );
        }
        $html = '<nav class="al-breadcrumbs">' . \implode( ' <span class="sep">›</span> ', $crumbs ) . '</nav>';
        return \apply_filters( 'anchor_locations_breadcrumbs_html', $html, $id );
    }

    public function sc_location_services( $atts ) {
        $id = $this->cur_id( $atts );
        $pages = \get_posts( [ 'post_type' => self::CPT_SERVICE, 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => 'al_location_id', 'meta_value' => $id ] );
        $html = '';
        if ( $pages ) {
            $html = '<ul class="al-location-services">';
            foreach ( $pages as $p ) { $html .= '<li><a href="' . \esc_url( $this->service_page_url( $p->ID ) ) . '">' . \esc_html( \get_the_title( $p ) ) . '</a></li>'; }
            $html .= '</ul>';
        }
        return \apply_filters( 'anchor_locations_location_services_html', $html, $id );
    }

    public function sc_service_locations( $atts ) {
        $id = $this->cur_id( $atts );
        $terms = \wp_get_object_terms( $id, self::TAX_SERVICE, [ 'fields' => 'ids' ] );
        $html = '';
        if ( ! \is_wp_error( $terms ) && $terms ) {
            $pages = \get_posts( [ 'post_type' => self::CPT_SERVICE, 'post_status' => 'publish', 'numberposts' => -1, 'exclude' => [ $id ], 'tax_query' => [ [ 'taxonomy' => self::TAX_SERVICE, 'field' => 'term_id', 'terms' => $terms ] ] ] );
            if ( $pages ) {
                $html = '<ul class="al-service-locations">';
                foreach ( $pages as $p ) { $html .= '<li><a href="' . \esc_url( $this->service_page_url( $p->ID ) ) . '">' . \esc_html( \get_the_title( $p ) ) . '</a></li>'; }
                $html .= '</ul>';
            }
        }
        return \apply_filters( 'anchor_locations_service_locations_html', $html, $id );
    }

    public function sc_directory( $atts ) {
        $roots = \get_posts( [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'post_parent' => 0, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
        $html = '<div class="al-directory">' . $this->directory_branch( $roots ) . '</div>';
        return \apply_filters( 'anchor_locations_service_area_directory_html', $html, 0 );
    }
    private function directory_branch( $nodes ) {
        if ( ! $nodes ) { return ''; }
        $out = '<ul>';
        foreach ( $nodes as $n ) {
            $kids = \get_posts( [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'post_parent' => $n->ID, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
            $out .= '<li><a href="' . \esc_url( \get_permalink( $n ) ) . '">' . \esc_html( \get_the_title( $n ) ) . '</a>' . $this->directory_branch( $kids ) . '</li>';
        }
        return $out . '</ul>';
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter LocationsShortcodesTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-locations/anchor-locations.php tests/LocationsShortcodesTest.php
git commit -m "feat(locations): directory, breadcrumb, and internal-linking shortcodes"
```

---

## Task 6: Map data + `[anchor_location_map]` + frontend JS

**Files:**
- Modify: `anchor-locations/anchor-locations.php`
- Create: `anchor-locations/assets/frontend.css`, `anchor-locations/assets/frontend.js`
- Test: `tests/LocationsSettingsTest.php`

**Interfaces:**
- Consumes: settings, `service_page_url()`, `get_google_api_key()` (add — mirrors store locator).
- Produces: `get_google_api_key(): string`, `map_data( array $args ): array` (returns marker array `[ ['id','title','url','lat','lng','icon','services'=>[['title','url']] ] ]`), `sc_map( $atts )` on `[anchor_location_map]`, `enqueue_map_assets()`. Per-map config passed via a `data-al-map` JSON attribute (supports multiple maps). Maps JS + frontend JS are enqueued **directly inside `sc_map()`** (guarded by `$assets_enqueued`), mirroring the store locator — shortcodes render before `wp_footer`, so `in_footer=true` scripts still print correctly. No `wp_footer` juggling.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/LocationsSettingsTest.php
class LocationsSettingsTest extends WP_UnitTestCase {
    public function test_map_data_returns_published_located_markers_filtered_by_type() {
        $city = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh' ] );
        update_post_meta( $city, 'al_lat', '40.44' ); update_post_meta( $city, 'al_lng', '-79.99' ); update_post_meta( $city, 'al_type', 'city' );
        $county = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Allegheny' ] );
        update_post_meta( $county, 'al_lat', '40.46' ); update_post_meta( $county, 'al_lng', '-79.98' ); update_post_meta( $county, 'al_type', 'county' );
        $nocoords = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'NoCoords' ] );
        update_post_meta( $nocoords, 'al_type', 'city' );
        $mod = new \Anchor\Locations\Module();
        $markers = $mod->map_data( [ 'types' => [ 'city' ] ] );
        $titles = wp_list_pluck( $markers, 'title' );
        $this->assertContains( 'Pittsburgh', $titles );
        $this->assertNotContains( 'Allegheny', $titles );   // filtered by type
        $this->assertNotContains( 'NoCoords', $titles );     // no coords excluded
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter LocationsSettingsTest`
Expected: FAIL (`map_data` missing).

- [ ] **Step 3: Write PHP implementation**

Constructor:

```php
        \add_shortcode( 'anchor_location_map', [ $this, 'sc_map' ] );
```

Add property `private $assets_enqueued = false;` and methods:

```php
    public function get_google_api_key() {
        if ( ! \class_exists( '\\Anchor_Schema_Admin' ) ) { return ''; }
        $opts = \get_option( \Anchor_Schema_Admin::OPTION_KEY, [] );
        return isset( $opts['google_api_key'] ) ? \sanitize_text_field( $opts['google_api_key'] ) : '';
    }

    public function map_data( $args = [] ) {
        $types  = isset( $args['types'] ) ? (array) $args['types'] : [];
        $parent = isset( $args['parent'] ) ? (int) $args['parent'] : 0;
        $q = [ 'post_type' => self::CPT_LOCATION, 'post_status' => 'publish', 'numberposts' => -1, 'meta_query' => [ [ 'key' => 'al_lat', 'value' => '', 'compare' => '!=' ] ] ];
        if ( $parent ) { $q['post_parent'] = $parent; }
        $out = [];
        foreach ( \get_posts( $q ) as $p ) {
            $lat = \get_post_meta( $p->ID, 'al_lat', true ); $lng = \get_post_meta( $p->ID, 'al_lng', true );
            if ( $lat === '' || $lng === '' ) { continue; }
            $type = (string) \get_post_meta( $p->ID, 'al_type', true );
            if ( $types && ! \in_array( $type, $types, true ) ) { continue; }
            $services = [];
            foreach ( \get_posts( [ 'post_type' => self::CPT_SERVICE, 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => 'al_location_id', 'meta_value' => $p->ID ] ) as $sp ) {
                $services[] = [ 'title' => \get_the_title( $sp ), 'url' => $this->service_page_url( $sp->ID ) ];
            }
            $icon = \get_post_meta( $p->ID, 'al_marker_icon', true );
            if ( ! $icon ) { $s = $this->settings(); $icon = $s['marker_icon'] ?? ''; }
            $out[] = [ 'id' => $p->ID, 'title' => \get_the_title( $p ), 'url' => \get_permalink( $p ), 'lat' => (float) $lat, 'lng' => (float) $lng, 'icon' => $icon, 'services' => $services ];
        }
        return $out;
    }

    public function sc_map( $atts ) {
        $a = \shortcode_atts( [ 'types' => '', 'parent' => 0, 'zoom' => '', 'height' => '480', 'center' => '' ], $atts, 'anchor_location_map' );
        $this->map_used = true;
        $args = [];
        if ( $a['types'] !== '' ) { $args['types'] = \array_map( 'trim', \explode( ',', $a['types'] ) ); }
        if ( (int) $a['parent'] ) { $args['parent'] = (int) $a['parent']; }
        $markers = $this->map_data( $args );
        $s = $this->settings();
        $cfg = [
            'markers' => $markers,
            'zoom'    => $a['zoom'] !== '' ? (int) $a['zoom'] : (int) ( $s['map_zoom'] ?? 8 ),
            'center'  => $a['center'] !== '' ? $a['center'] : ( ( $s['map_center'] ?? '' ) ?: '' ),
        ];
        $this->enqueue_map_assets();
        $uid = 'al-map-' . \wp_rand( 1000, 9999 );
        $json = \esc_attr( \wp_json_encode( $cfg ) );
        return '<div id="' . $uid . '" class="al-map" style="height:' . (int) $a['height'] . 'px" data-al-map="' . $json . '"></div>';
    }

    /** Enqueue Maps + frontend JS directly (store-locator pattern). Shortcodes run before wp_footer. */
    public function enqueue_map_assets() {
        if ( $this->assets_enqueued ) { return; }
        $this->assets_enqueued = true;
        $dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-locations/assets/';
        \wp_enqueue_style( 'anchor-locations', \Anchor_Asset_Loader::url( 'anchor-locations/assets/frontend.css' ), [], (string) \filemtime( $dir . 'frontend.css' ) );
        $deps = [];
        $key = $this->get_google_api_key();
        if ( $key ) {
            \wp_enqueue_script( 'anchor-locations-gmaps', 'https://maps.googleapis.com/maps/api/js?key=' . \rawurlencode( $key ) . '&libraries=marker', [], null, true );
            $deps[] = 'anchor-locations-gmaps';
        }
        \wp_enqueue_script( 'anchor-locations-frontend', \Anchor_Asset_Loader::url( 'anchor-locations/assets/frontend.js' ), $deps, (string) \filemtime( $dir . 'frontend.js' ), true );
    }
```

> The frontend CSS is also useful on pages that use only the directory/breadcrumb shortcodes (no map). That's acceptable: those shortcodes are lightweight and the CSS is tiny. If you want the CSS present whenever any locations shortcode renders, call `enqueue_map_assets()` (rename mentally to "enqueue frontend") from the directory shortcodes too — optional, not required for Phase 1.

- [ ] **Step 4: Create `anchor-locations/assets/frontend.css`**

```css
.al-map { width: 100%; min-height: 320px; background: #eef1f4; border-radius: 8px; }
.al-map-popup h3 { margin: 0 0 4px; font-size: 15px; }
.al-map-popup ul { margin: 6px 0 0; padding-left: 16px; }
.al-breadcrumbs { font-size: 13px; margin: 0 0 16px; }
.al-breadcrumbs .sep { opacity: .5; }
.al-directory ul { list-style: none; padding-left: 16px; }
.al-child-locations, .al-nearby, .al-location-services, .al-service-locations { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: 8px 16px; }
```

- [ ] **Step 5: Create `anchor-locations/assets/frontend.js`**

```js
(function () {
  'use strict';
  function initOne(el) {
    var cfg;
    try { cfg = JSON.parse(el.getAttribute('data-al-map')); } catch (e) { return; }
    if (!window.google || !google.maps) { return; }
    var center = { lat: 39.5, lng: -98.35 };
    if (cfg.center && cfg.center.indexOf(',') > -1) {
      var c = cfg.center.split(','); center = { lat: parseFloat(c[0]), lng: parseFloat(c[1]) };
    } else if (cfg.markers && cfg.markers.length) {
      center = { lat: cfg.markers[0].lat, lng: cfg.markers[0].lng };
    }
    var map = new google.maps.Map(el, { center: center, zoom: cfg.zoom || 8 });
    var info = new google.maps.InfoWindow();
    var bounds = new google.maps.LatLngBounds();
    (cfg.markers || []).forEach(function (m) {
      var opts = { position: { lat: m.lat, lng: m.lng }, map: map, title: m.title };
      if (m.icon) { opts.icon = m.icon; }
      var marker = new google.maps.Marker(opts);
      bounds.extend(opts.position);
      marker.addListener('click', function () {
        var html = '<div class="al-map-popup"><h3><a href="' + m.url + '">' + m.title + '</a></h3>';
        if (m.services && m.services.length) {
          html += '<ul>';
          m.services.forEach(function (s) { html += '<li><a href="' + s.url + '">' + s.title + '</a></li>'; });
          html += '</ul>';
        }
        html += '</div>';
        info.setContent(html); info.open(map, marker);
      });
    });
    if (cfg.markers && cfg.markers.length > 1) { map.fitBounds(bounds); }
  }
  function init() { document.querySelectorAll('.al-map[data-al-map]').forEach(initOne); }
  if (document.readyState !== 'loading') { init(); } else { document.addEventListener('DOMContentLoaded', init); }
})();
```

- [ ] **Step 6: Run test + commit**

Run: `composer test -- --filter LocationsSettingsTest`
Expected: PASS.

```bash
git add anchor-locations/
git commit -m "feat(locations): [anchor_location_map] with gated Maps JS + marker popups"
```

---

## Task 7: JSON-LD schema (BreadcrumbList + Service/Place)

**Files:**
- Modify: `anchor-locations/anchor-locations.php`
- Test: `tests/LocationsSchemaTest.php`

**Interfaces:**
- Consumes: CPT/meta constants, `service_page_url()`.
- Produces: `print_schema()` on `wp_head` (priority 20); `build_schema( int $post_id ): array` returning a `@graph` array. Public for testing.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/LocationsSchemaTest.php
class LocationsSchemaTest extends WP_UnitTestCase {
    public function test_location_schema_has_place_and_geo() {
        $id = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh' ] );
        update_post_meta( $id, 'al_type', 'city' ); update_post_meta( $id, 'al_lat', '40.44' ); update_post_meta( $id, 'al_lng', '-79.99' );
        $graph = ( new \Anchor\Locations\Module() )->build_schema( $id );
        $types = array_column( $graph, '@type' );
        $this->assertContains( 'City', $types );
        $hasGeo = false;
        foreach ( $graph as $n ) { if ( isset( $n['geo']['latitude'] ) && (float) $n['geo']['latitude'] === 40.44 ) { $hasGeo = true; } }
        $this->assertTrue( $hasGeo );
    }
    public function test_service_page_schema_has_service_areaserved_no_postaladdress() {
        $loc  = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Pittsburgh', 'post_name' => 'pittsburgh-pa' ] );
        update_post_meta( $loc, 'al_type', 'city' );
        $term = wp_insert_term( 'Roofing', 'service' );
        $sp   = self::factory()->post->create( [ 'post_type' => 'anchor_service_page', 'post_status' => 'publish', 'post_title' => 'Roofing in Pittsburgh' ] );
        wp_set_object_terms( $sp, [ (int) $term['term_id'] ], 'service' );
        update_post_meta( $sp, 'al_location_id', $loc );
        $graph = ( new \Anchor\Locations\Module() )->build_schema( $sp );
        $json = wp_json_encode( $graph );
        $this->assertStringContainsString( '"Service"', $json );
        $this->assertStringContainsString( 'areaServed', $json );
        $this->assertStringNotContainsString( 'PostalAddress', $json );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter LocationsSchemaTest`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Constructor: `\add_action( 'wp_head', [ $this, 'print_schema' ], 20 );`

```php
    private function place_type( $al_type ) {
        switch ( $al_type ) {
            case 'state': case 'county': return 'AdministrativeArea';
            case 'city': case 'borough': case 'township': return 'City';
            default: return 'Place';
        }
    }

    public function build_schema( $post_id ) {
        $post = \get_post( $post_id );
        if ( ! $post ) { return []; }
        $graph = [];
        // Breadcrumb
        $items = []; $pos = 1;
        $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => 'Home', 'item' => \home_url( '/' ) ];
        if ( $post->post_type === self::CPT_SERVICE ) {
            $loc = (int) \get_post_meta( $post_id, 'al_location_id', true );
            $chain = $loc ? \array_merge( \array_reverse( \get_post_ancestors( $loc ) ), [ $loc ] ) : [];
            foreach ( $chain as $aid ) { $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => \get_the_title( $aid ), 'item' => \get_permalink( $aid ) ]; }
            $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => \get_the_title( $post_id ), 'item' => $this->service_page_url( $post_id ) ];
        } else {
            foreach ( \array_merge( \array_reverse( \get_post_ancestors( $post_id ) ), [ $post_id ] ) as $aid ) {
                $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => \get_the_title( $aid ), 'item' => \get_permalink( $aid ) ];
            }
        }
        $graph[] = [ '@type' => 'BreadcrumbList', 'itemListElement' => $items ];

        if ( $post->post_type === self::CPT_LOCATION ) {
            $lat = \get_post_meta( $post_id, 'al_lat', true ); $lng = \get_post_meta( $post_id, 'al_lng', true );
            $node = [ '@type' => $this->place_type( (string) \get_post_meta( $post_id, 'al_type', true ) ), 'name' => \get_the_title( $post_id ), 'url' => \get_permalink( $post_id ) ];
            if ( $lat !== '' && $lng !== '' ) { $node['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng ]; }
            $graph[] = $node;
        } else {
            $terms = \wp_get_object_terms( $post_id, self::TAX_SERVICE, [ 'fields' => 'names' ] );
            $loc = (int) \get_post_meta( $post_id, 'al_location_id', true );
            $node = [
                '@type'       => 'Service',
                'name'        => \get_the_title( $post_id ),
                'serviceType' => ! \is_wp_error( $terms ) && $terms ? $terms[0] : '',
                'url'         => $this->service_page_url( $post_id ),
                'provider'    => [ '@type' => 'Organization', 'name' => \get_bloginfo( 'name' ), 'url' => \home_url( '/' ) ],
            ];
            if ( $loc ) {
                $node['areaServed'] = [ '@type' => $this->place_type( (string) \get_post_meta( $loc, 'al_type', true ) ), 'name' => \get_the_title( $loc ) ];
            }
            $graph[] = $node;
        }
        return $graph;
    }

    public function print_schema() {
        if ( ! \is_singular( [ self::CPT_LOCATION, self::CPT_SERVICE ] ) ) { return; }
        $graph = $this->build_schema( \get_the_ID() );
        if ( ! $graph ) { return; }
        $doc = [ '@context' => 'https://schema.org', '@graph' => $graph ];
        echo "\n<script type=\"application/ld+json\">" . \wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter LocationsSchemaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-locations/anchor-locations.php tests/LocationsSchemaTest.php
git commit -m "feat(locations): BreadcrumbList + Service/Place JSON-LD"
```

---

## Task 8: Settings tab (map defaults, bases, marker icon, global wrapper Monaco)

**Files:**
- Modify: `anchor-locations/anchor-locations.php`
- Test: `tests/LocationsSettingsTest.php`

**Interfaces:**
- Consumes: `OPTION`, `\Anchor_Monaco`, `anchor_settings_tabs` filter, `anchor_settings_enqueue_locations` action.
- Produces: `register_tab( array $tabs ): array` (priority 65), `render_tab()`, `handle_save()` on `admin_post_anchor_locations_save`, `settings_assets( $hook )`. Persists `anchor_locations_settings` with keys `marker_icon, services_base, service_areas_base, map_center, map_zoom, wrapper_html, wrapper_css, wrapper_js, fullwidth_template`.

- [ ] **Step 1: Write the failing test**

```php
// append to tests/LocationsSettingsTest.php
public function test_sanitize_settings_persists_expected_keys() {
    $mod = new \Anchor\Locations\Module();
    $clean = $mod->sanitize_settings( [
        'services_base' => 'Services', 'service_areas_base' => 'Service Areas',
        'map_zoom' => '9', 'marker_icon' => 'https://x/i.svg', 'wrapper_html' => '<div>{{content}}</div>',
    ] );
    $this->assertSame( 'services', $clean['services_base'] );
    $this->assertSame( 'service-areas', $clean['service_areas_base'] );
    $this->assertSame( 9, $clean['map_zoom'] );
    $this->assertStringContainsString( '{{content}}', $clean['wrapper_html'] );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter LocationsSettingsTest`
Expected: FAIL (`sanitize_settings` missing).

- [ ] **Step 3: Write minimal implementation**

Constructor:

```php
        \add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 65 );
        \add_action( 'anchor_settings_enqueue_locations', [ $this, 'settings_assets' ] );
        \add_action( 'admin_post_anchor_locations_save', [ $this, 'handle_save' ] );
```

```php
    public function register_tab( $tabs ) {
        $tabs['locations'] = [ 'label' => \__( 'Locations', 'anchor-schema' ), 'callback' => [ $this, 'render_tab' ] ];
        return $tabs;
    }

    public function sanitize_settings( $in ) {
        $out = [];
        $out['services_base']      = ! empty( $in['services_base'] ) ? \sanitize_title( $in['services_base'] ) : 'services';
        $out['service_areas_base'] = ! empty( $in['service_areas_base'] ) ? \sanitize_title( $in['service_areas_base'] ) : 'service-areas';
        $out['marker_icon']        = isset( $in['marker_icon'] ) ? \esc_url_raw( $in['marker_icon'] ) : '';
        $out['map_center']         = isset( $in['map_center'] ) ? \sanitize_text_field( $in['map_center'] ) : '';
        $out['map_zoom']           = isset( $in['map_zoom'] ) ? (int) $in['map_zoom'] : 8;
        $out['wrapper_html']       = isset( $in['wrapper_html'] ) ? (string) $in['wrapper_html'] : '';
        $out['wrapper_css']        = isset( $in['wrapper_css'] ) ? (string) $in['wrapper_css'] : '';
        $out['wrapper_js']         = isset( $in['wrapper_js'] ) ? (string) $in['wrapper_js'] : '';
        $out['fullwidth_template'] = ! empty( $in['fullwidth_template'] ) ? '1' : '';
        return $out;
    }

    public function handle_save() {
        if ( ! \current_user_can( 'manage_options' ) || ! \check_admin_referer( 'anchor_locations_save' ) ) { \wp_die( 'no' ); }
        $clean = $this->sanitize_settings( \wp_unslash( $_POST['al'] ?? [] ) );
        \update_option( self::OPTION, $clean, false );
        \delete_option( 'anchor_locations_rw_sig' );  // force rewrite reflush on base change
        \wp_safe_redirect( \add_query_arg( [ 'page' => 'anchor-schema', 'tab' => 'locations', 'updated' => '1' ], \admin_url( 'options-general.php' ) ) );
        exit;
    }

    public function settings_assets( $hook ) {
        \Anchor_Monaco::enqueue( 'anchor_locations_settings' );
    }

    public function render_tab() {
        $s = $this->settings();
        $g = function( $k, $d = '' ) use ( $s ) { return \esc_attr( $s[ $k ] ?? $d ); };
        $spec = \wp_json_encode( [
            [ 'id' => 'al_wrapper_html', 'label' => 'Wrapper HTML', 'lang' => 'html' ],
            [ 'id' => 'al_wrapper_css',  'label' => 'Wrapper CSS',  'lang' => 'css' ],
            [ 'id' => 'al_wrapper_js',   'label' => 'Wrapper JS',   'lang' => 'javascript' ],
        ] );
        echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '">';
        \wp_nonce_field( 'anchor_locations_save' );
        echo '<input type="hidden" name="action" value="anchor_locations_save">';
        echo '<h2>Map & URLs</h2>';
        echo '<p><label>Default marker icon URL <input type="text" class="regular-text al-media" name="al[marker_icon]" value="' . $g('marker_icon') . '"></label></p>';
        echo '<p><label>Service-area base <input type="text" name="al[service_areas_base]" value="' . $g('service_areas_base','service-areas') . '"></label> ';
        echo '<label>Services base <input type="text" name="al[services_base]" value="' . $g('services_base','services') . '"></label></p>';
        echo '<p><label>Map center (lat,lng) <input type="text" name="al[map_center]" value="' . $g('map_center') . '"></label> ';
        echo '<label>Default zoom <input type="number" name="al[map_zoom]" value="' . $g('map_zoom','8') . '"></label></p>';
        echo '<p><label><input type="checkbox" name="al[fullwidth_template]" value="1" ' . \checked( $s['fullwidth_template'] ?? '', '1', false ) . '> Use plugin full-width single template when the theme lacks one</label></p>';
        echo '<h2>Global Wrapper Template</h2><p class="description">Wraps every location/service page. Include <code>{{content}}</code> where the page body goes. Leave blank to disable.</p>';
        echo '<div class="anchor-monaco" data-anchor-monaco="' . \esc_attr( $spec ) . '">';
        echo '<textarea id="al_wrapper_html" name="al[wrapper_html]" style="display:none">' . \esc_textarea( $s['wrapper_html'] ?? '' ) . '</textarea>';
        echo '<textarea id="al_wrapper_css" name="al[wrapper_css]" style="display:none">' . \esc_textarea( $s['wrapper_css'] ?? '' ) . '</textarea>';
        echo '<textarea id="al_wrapper_js" name="al[wrapper_js]" style="display:none">' . \esc_textarea( $s['wrapper_js'] ?? '' ) . '</textarea>';
        echo '</div>';
        \submit_button();
        echo '</form>';
    }
```

Verify the `anchor_settings_tabs` tab-array shape (`label`/`callback`) against `includes/class-anchor-settings-page.php`; match it exactly (read that file first). Confirm the save round-trips through `admin-post.php` — if other modules save via the WordPress Settings API instead, mirror the dominant pattern used by `anchor-store-locator`/`anchor-webinars` for their tab.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter LocationsSettingsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-locations/anchor-locations.php tests/LocationsSettingsTest.php
git commit -m "feat(locations): settings tab (bases, map defaults, marker icon, global wrapper)"
```

---

## Task 9: Activation flush + docs + full suite

**Files:**
- Modify: `anchor-locations/anchor-locations.php` (register activation flush via module setup, or `register_activation_hook` in a bootstrap-safe way), `ADDING-MODULES.md` mention (optional), create `anchor-locations/README.md` (WP-CLI population examples for external AI).
- Test: run full suite.

**Interfaces:**
- Consumes: everything. Produces: `README.md` documenting every meta key + WP-CLI create examples.

- [ ] **Step 1: Ensure rewrite flush on activation**

Because modules load on `plugins_loaded`, `register_activation_hook` on the main plugin file may not reach the module. Rely on the `maybe_flush()` signature check (Task 2) as the primary mechanism (fires on `init` when the stored signature is absent/changed). Confirm activating the module (toggling it on) results in working `/services/…/` URLs; if not, add a `add_action('anchor_module_activated_locations', …)` flush if the registry fires such a hook — otherwise document that visiting Settings > Permalinks once (or toggling the module) flushes rules, and that `handle_save()` already deletes the signature to force a reflush.

- [ ] **Step 2: Write `anchor-locations/README.md`**

Document: the two CPTs, the `service` taxonomy, every `al_*` meta key with type/purpose, URL structure, all shortcodes with attributes, and copy-paste WP-CLI examples, e.g.:

```bash
# Create a county hub
wp post create --post_type=anchor_location --post_status=publish --post_title="Allegheny County" --post_name="allegheny-county-pa"
wp post meta set <ID> al_type county
wp post meta set <ID> al_state_abbr PA
wp post meta set <ID> al_lat 40.46 && wp post meta set <ID> al_lng -79.98

# Create a city under it
wp post create --post_type=anchor_location --post_status=publish --post_title="Pittsburgh" --post_name="pittsburgh-pa" --post_parent=<COUNTY_ID>
wp post meta set <ID> al_type city && wp post meta set <ID> al_lat 40.44 && wp post meta set <ID> al_lng -79.99

# Create a service page: Roofing in Pittsburgh -> /services/roofing/pittsburgh-pa/
wp post create --post_type=anchor_service_page --post_status=publish --post_title="Roofing in Pittsburgh" --post_name="roofing-pittsburgh-pa"
wp post term set <ID> service roofing
wp post meta set <ID> al_location_id <PITTSBURGH_ID>
wp post meta set <ID> al_html '<h1>Roofing in Pittsburgh</h1>[anchor_location_map]'
```

- [ ] **Step 3: Run the full new suite**

Run: `composer test -- --filter 'Locations'`
Expected: all `Locations*` tests PASS.

- [ ] **Step 4: Commit**

```bash
git add anchor-locations/README.md anchor-locations/anchor-locations.php
git commit -m "docs(locations): README with meta keys + WP-CLI examples; activation flush"
```

---

## Self-Review

**Spec coverage:**
- §2 content model → Task 1 (CPTs/tax), Task 3 (meta save). ✓
- §3 URLs/rewrites → Task 2. ✓
- §4 rendering/wrapper/`[anchor_page_content]` → Task 4. ✓
- §5 linking/directory/breadcrumbs → Task 5. ✓
- §6 map + settings → Task 6 (map) + Task 8 (settings incl. marker icon, bases, wrapper). ✓
- §7 schema → Task 7. ✓
- §8 admin UX → Task 3. ✓
- §9 file layout/registry → Task 1 + assets across 3/6. ✓
- §10 testing → tests in each task + Task 9 full run. ✓
- §12 deferred → not built (correct). ✓

**Placeholder scan:** No TBD/TODO; every code step has complete code. The two "read the existing file and mirror" notes (Preview_CSS enqueue in Task 3, settings-save pattern in Task 8, footer-enqueue in Task 6) are deliberate verification instructions against real files, not placeholders — each has a concrete fallback.

**Type consistency:** `service_page_url()` defined in Task 2, consumed in 5/6/7 with same signature. `map_data()` shape (`markers` with `services[]`) matches `frontend.js` consumption. `settings()`, `services_base()`, `service_areas_base()` defined Task 1, reused throughout. Meta keys consistent (`al_*`) across save (Task 3), render (Task 4), queries (Task 5/6), schema (Task 7). Settings keys consistent between `sanitize_settings`/`render_tab` (Task 8) and `apply_wrapper`/`sc_map` (Tasks 4/6).

**Known risk flagged for executor:** WordPress footer-time script enqueue (Task 6) and the exact settings-tab save mechanism (Task 8) must be reconciled against the store-locator's actual approach — both tasks instruct the executor to read and mirror it. The CSS-scoping regex in `render_body` (Task 4) is best-effort; acceptable for Phase 1 since content is authored by a trusted external operator.
