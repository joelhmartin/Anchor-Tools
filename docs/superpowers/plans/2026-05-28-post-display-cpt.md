# Post Display CPT Conversion — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the Post Display module's `[anchor_post_grid]` into an editable `anchor_post_display` CPT (gallery-style builder) with per-display query, layout, style, and desktop/tablet/mobile responsive settings — additively, leaving inline shortcodes and `[anchor_search]` untouched.

**Architecture:** Expand the existing `anchor-post-display` module into three files: the main file keeps the settings-page tab + shortcode registration; a shared `Anchor_APD_Renderer` holds the render pipeline (used by both inline shortcodes and the CPT); a `Anchor_APD_Display_CPT` holds the CPT, schema, builder UI, save, and live preview, reusing `Anchor_Builder_Shell` / `Anchor_Builder_Device_Toolbar`.

**Tech Stack:** PHP 7.4+, WordPress plugin APIs (CPT, post_meta, shortcodes, admin-ajax), vanilla JS (no build step), CSS. Reuses `includes/builder/*` shell classes.

**Testing reality:** No PHPUnit in this repo. Per-task gate = `php -l <file>` (must report "No syntax errors"). The one pure function (responsive CSS builder) gets a standalone PHP logic test. Behavior is validated via the manual checklist in Task 12.

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `anchor-post-display/anchor-post-display.php` | Bootstrap, hooks, settings-page tab, shortcode/AJAX registration, `require_once` includes. Delegates rendering to the renderer. | Modify |
| `anchor-post-display/includes/class-apd-renderer.php` | Shared render pipeline: query args, card rendering, custom-field/teaser helpers, pagination, carousel markup, responsive scoped CSS. | Create |
| `anchor-post-display/includes/class-apd-display-cpt.php` | CPT registration, `get_setting_defs()`, builder panes, `save_meta`, admin assets, live-preview AJAX. | Create |
| `anchor-post-display/assets/builder.js` | Builder live-preview (debounced AJAX) + Source pane interactions. | Create |
| `anchor-post-display/assets/builder.css` | Builder-specific admin styles. | Create |
| `anchor-post-display/assets/frontend.js` | Add carousel behavior (loop, dots, pause-on-hover) atop existing slider. | Modify |
| `anchor-post-display/assets/frontend.css` | Add list layout + carousel dots/arrows styles; responsive via inline scoped CSS. | Modify |
| `tests/apd-css-builder-test.php` | Standalone logic test for the responsive CSS builder (no WP needed). | Create |

---

## Task 1: Extract the shared renderer

Move the render pipeline out of the module class into `Anchor_APD_Renderer` so both the inline shortcode and the CPT use one code path. No behavior change for inline use.

**Files:**
- Create: `anchor-post-display/includes/class-apd-renderer.php`
- Modify: `anchor-post-display/anchor-post-display.php`

- [ ] **Step 1: Create the renderer class** with the pipeline methods moved verbatim from the current module: `build_query_args`, `render_grid_items`, `get_custom_field_html`, `get_teaser`, `render_pagination`, `get_total_pages`, `resolve_post_types`, `get_searchable_types`, `normalize_post_count`. Make them `public static` (pure, no instance state). Header:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_APD_Renderer {
    // build_query_args( array $params, int $page = 1 ): array  — moved verbatim, made static
    // render_grid_items( WP_Query $query, array $params ): string — moved verbatim, made static
    // get_custom_field_html(...), get_teaser(...), render_pagination(...),
    // get_total_pages(...), resolve_post_types(...), get_searchable_types(...),
    // normalize_post_count(...)  — all moved verbatim from anchor-post-display.php, made static
}
```

- [ ] **Step 2: Require it from the main file** near the top of the class file load (in `anchor-post-display.php`, after the `ABSPATH` guard):

```php
require_once __DIR__ . '/includes/class-apd-renderer.php';
```

- [ ] **Step 3: Repoint the module's callers** — in `shortcode_grid`, `ajax_load`, replace `$this->build_query_args(...)` → `Anchor_APD_Renderer::build_query_args(...)`, `$this->render_grid_items(...)` → `Anchor_APD_Renderer::render_grid_items(...)`, `$this->render_pagination(...)`, `$this->get_total_pages(...)`. Delete the now-moved private methods from the module class. Keep `normalize_params`/`build_data_attrs` in the module for now (they read global options).

- [ ] **Step 4: Lint both files**

Run: `php -l anchor-post-display/includes/class-apd-renderer.php && php -l anchor-post-display/anchor-post-display.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Grep for leftover `$this->` calls** to moved methods

Run: `grep -n "\$this->\(build_query_args\|render_grid_items\|render_pagination\|get_total_pages\|get_custom_field_html\|get_teaser\|resolve_post_types\|get_searchable_types\|normalize_post_count\)" anchor-post-display/anchor-post-display.php`
Expected: no output (all repointed).

- [ ] **Step 6: Commit**

```bash
git add anchor-post-display/
git commit -m "Extract Post Display render pipeline into Anchor_APD_Renderer"
```

---

## Task 2: Register the CPT

**Files:**
- Create: `anchor-post-display/includes/class-apd-display-cpt.php`
- Modify: `anchor-post-display/anchor-post-display.php`

- [ ] **Step 1: Create the CPT class skeleton**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_APD_Display_CPT {
    const CPT     = 'anchor_post_display';
    const NONCE   = 'apd_cpt_nonce';
    const VERSION = '2.0.0';

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_filter( 'manage_' . self::CPT . '_posts_columns', [ $this, 'admin_columns' ] );
        add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'admin_column_content' ], 10, 2 );
    }

    public function register_cpt() {
        register_post_type( self::CPT, [
            'labels' => [
                'name'          => 'Post Displays',
                'singular_name' => 'Post Display',
                'add_new_item'  => 'Add New Post Display',
                'edit_item'     => 'Edit Post Display',
                'menu_name'     => 'Post Displays',
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => apply_filters( 'anchor_post_display_parent_menu', true ),
            'menu_icon'    => 'dashicons-grid-view',
            'supports'     => [ 'title' ],
        ] );
    }

    public function admin_columns( $columns ) {
        $new = [];
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( 'title' === $k ) {
                $new['apd_layout']    = 'Layout';
                $new['apd_shortcode'] = 'Shortcode';
            }
        }
        return $new;
    }

    public function admin_column_content( $column, $post_id ) {
        if ( 'apd_layout' === $column ) {
            echo esc_html( ucfirst( get_post_meta( $post_id, 'apd_layout', true ) ?: 'grid' ) );
        } elseif ( 'apd_shortcode' === $column ) {
            echo '<code>[anchor_post_grid id="' . intval( $post_id ) . '"]</code>';
        }
    }
}
```

- [ ] **Step 2: Require + instantiate from the main file** (after the renderer require):

```php
require_once __DIR__ . '/includes/class-apd-display-cpt.php';
```

And in the module constructor add:

```php
$this->cpt = new Anchor_APD_Display_CPT();
```

(Add a `private $cpt;` property.)

- [ ] **Step 3: Lint**

Run: `php -l anchor-post-display/includes/class-apd-display-cpt.php && php -l anchor-post-display/anchor-post-display.php`
Expected: No syntax errors.

- [ ] **Step 4: Commit**

```bash
git add anchor-post-display/
git commit -m "Register anchor_post_display CPT with admin columns"
```

---

## Task 3: Settings schema

**Files:**
- Modify: `anchor-post-display/includes/class-apd-display-cpt.php`

- [ ] **Step 1: Add `get_setting_defs()`** returning the schema from the spec. Use the layout-group arrays for `applies_to`:

```php
private function get_setting_defs() {
    $col_layouts    = [ 'grid' ];
    $slider_layouts = [ 'slider', 'carousel' ];
    $pag_layouts    = [ 'grid', 'list' ];
    $carousel_only  = [ 'carousel' ];

    return [
        // Content
        'fields'       => [ 'type' => 'text',   'label' => 'Fields (comma-separated)', 'section' => 'content', 'priority' => 10, 'help' => 'image,title,date,type,excerpt or any ACF/meta key. Empty = default order.' ],
        'show_date'    => [ 'type' => 'checkbox','label' => 'Show date',       'section' => 'content', 'priority' => 20 ],
        'show_type'    => [ 'type' => 'checkbox','label' => 'Show post type',  'section' => 'content', 'priority' => 30 ],
        'teaser_words' => [ 'type' => 'number',  'label' => 'Teaser word limit','section' => 'content', 'priority' => 40, 'min' => 1, 'max' => 200 ],
        'image_size'   => [ 'type' => 'text',    'label' => 'Image size',      'section' => 'content', 'priority' => 50 ],

        // Layout
        'layout'          => [ 'type' => 'select', 'label' => 'Layout', 'section' => 'layout', 'priority' => 10, 'options' => [ 'grid' => 'Grid', 'list' => 'List', 'slider' => 'Slider', 'carousel' => 'Carousel' ] ],
        'columns_desktop' => [ 'type' => 'number', 'label' => 'Desktop columns', 'section' => 'layout', 'priority' => 20, 'min' => 1, 'max' => 6, 'applies_to' => $col_layouts ],
        'gap'             => [ 'type' => 'number', 'label' => 'Gap (px)', 'section' => 'layout', 'priority' => 30, 'min' => 0, 'max' => 60, 'step' => 2 ],
        'card_style'      => [ 'type' => 'select', 'label' => 'Card style', 'section' => 'layout', 'priority' => 40, 'options' => [ 'card' => 'Card', 'minimal' => 'Minimal', 'bordered' => 'Bordered' ] ],

        // Style
        'border_radius' => [ 'type' => 'number', 'label' => 'Border radius (px)', 'section' => 'style', 'min' => 0, 'max' => 32, 'step' => 1 ],
        'tile_shadow'   => [ 'type' => 'select', 'label' => 'Card shadow', 'section' => 'style', 'options' => [ 'none' => 'None', 'soft' => 'Soft', 'medium' => 'Medium', 'strong' => 'Strong' ] ],
        'wrapper_bg'    => [ 'type' => 'color',  'label' => 'Background', 'section' => 'style' ],
        'title_color'   => [ 'type' => 'color',  'label' => 'Title color', 'section' => 'style' ],
        'title_size'    => [ 'type' => 'number', 'label' => 'Title size (px, 0=auto)', 'section' => 'style', 'min' => 0, 'max' => 40, 'step' => 1 ],
        'title_weight'  => [ 'type' => 'select', 'label' => 'Title weight', 'section' => 'style', 'options' => [ '400' => 'Normal', '500' => 'Medium', '600' => 'Semi-bold', '700' => 'Bold' ] ],

        // Behavior
        'pagination'        => [ 'type' => 'select', 'label' => 'Pagination', 'section' => 'behavior', 'priority' => 10, 'options' => [ 'none' => 'None', 'numbered' => 'Numbered', 'load_more' => 'Load More' ], 'applies_to' => $pag_layouts ],
        'pagination_window' => [ 'type' => 'number', 'label' => 'Page button limit', 'section' => 'behavior', 'priority' => 11, 'min' => 1, 'max' => 20, 'applies_to' => $pag_layouts, 'depends_on' => [ 'pagination' => [ 'numbered' ] ] ],
        'slider_per_view'   => [ 'type' => 'number', 'label' => 'Slides per view (desktop)', 'section' => 'behavior', 'priority' => 20, 'min' => 1, 'max' => 6, 'applies_to' => $slider_layouts ],
        'slider_autoplay'   => [ 'type' => 'checkbox','label' => 'Autoplay', 'section' => 'behavior', 'priority' => 21, 'applies_to' => $slider_layouts ],
        'slider_speed'      => [ 'type' => 'number', 'label' => 'Autoplay speed (ms)', 'section' => 'behavior', 'priority' => 22, 'min' => 1000, 'max' => 15000, 'step' => 500, 'applies_to' => $slider_layouts, 'depends_on' => [ 'slider_autoplay' => true ] ],
        'carousel_loop'     => [ 'type' => 'checkbox','label' => 'Loop continuously', 'section' => 'behavior', 'priority' => 30, 'applies_to' => $carousel_only ],
        'carousel_arrows'   => [ 'type' => 'checkbox','label' => 'Navigation arrows', 'section' => 'behavior', 'priority' => 31, 'applies_to' => $slider_layouts ],
        'carousel_dots'     => [ 'type' => 'checkbox','label' => 'Dots navigation', 'section' => 'behavior', 'priority' => 32, 'applies_to' => $slider_layouts ],
        'carousel_pause_on_hover' => [ 'type' => 'checkbox','label' => 'Pause on hover', 'section' => 'behavior', 'priority' => 33, 'applies_to' => $carousel_only, 'depends_on' => [ 'slider_autoplay' => true ] ],

        // Responsive
        'columns_tablet'         => [ 'type' => 'number', 'label' => 'Tablet columns', 'section' => 'responsive', 'min' => 1, 'max' => 4, 'applies_to' => $col_layouts ],
        'columns_mobile'         => [ 'type' => 'number', 'label' => 'Mobile columns', 'section' => 'responsive', 'min' => 1, 'max' => 2, 'applies_to' => $col_layouts ],
        'slider_per_view_tablet' => [ 'type' => 'number', 'label' => 'Slides per view (tablet)', 'section' => 'responsive', 'min' => 1, 'max' => 4, 'applies_to' => $slider_layouts ],
        'slider_per_view_mobile' => [ 'type' => 'number', 'label' => 'Slides per view (mobile)', 'section' => 'responsive', 'min' => 1, 'max' => 3, 'applies_to' => $slider_layouts ],
        'gap_mobile'             => [ 'type' => 'number', 'label' => 'Mobile gap (px, 0=use Gap)', 'section' => 'responsive', 'min' => 0, 'max' => 60, 'step' => 2 ],

        // Advanced
        'no_results'  => [ 'type' => 'text',     'label' => 'No results text', 'section' => 'advanced' ],
        'custom_css'  => [ 'type' => 'textarea', 'label' => 'Custom CSS', 'section' => 'advanced', 'help' => 'Scope rules to #apd-UID or your HTML Anchor.' ],
        'html_anchor' => [ 'type' => 'text',     'label' => 'HTML Anchor (wrapper id)', 'section' => 'advanced' ],
    ];
}
```

- [ ] **Step 2: Add `default_settings()`** that merges schema defaults with the saved global Post Display option, so new displays inherit site defaults:

```php
private function default_settings() {
    $globals = get_option( Anchor_Post_Display_Module::OPTION_KEY, [] );
    $defs    = $this->get_setting_defs();
    $out     = [];
    foreach ( $defs as $key => $def ) {
        if ( isset( $globals[ $key ] ) && $globals[ $key ] !== '' ) {
            $out[ $key ] = $globals[ $key ];
            continue;
        }
        switch ( $def['type'] ) {
            case 'checkbox': $out[ $key ] = 0; break;
            case 'number':   $out[ $key ] = isset( $def['min'] ) ? (int) $def['min'] : 0; break;
            case 'select':   $out[ $key ] = $def['options'] ? array_key_first( $def['options'] ) : ''; break;
            default:         $out[ $key ] = ''; break;
        }
    }
    // Sensible non-zero seeds when globals are absent.
    $out['layout']                 = $out['layout'] ?: 'grid';
    $out['columns_desktop']        = $out['columns_desktop'] ?: 3;
    $out['columns_tablet']         = $out['columns_tablet'] ?: 2;
    $out['columns_mobile']         = $out['columns_mobile'] ?: 1;
    $out['slider_per_view']        = $out['slider_per_view'] ?: 3;
    $out['slider_per_view_tablet'] = $out['slider_per_view_tablet'] ?: 2;
    $out['slider_per_view_mobile'] = $out['slider_per_view_mobile'] ?: 1;
    $out['slider_speed']           = $out['slider_speed'] ?: 5000;
    $out['gap']                    = $out['gap'] !== '' ? $out['gap'] : 16;
    $out['teaser_words']           = $out['teaser_words'] ?: 26;
    $out['image_size']             = $out['image_size'] ?: 'medium';
    $out['no_results']             = $out['no_results'] ?: 'No results found.';
    return $out;
}
```

- [ ] **Step 3: Add `get_settings_by_section()`** (copy the gallery's grouping helper verbatim — sorts by priority then declaration order):

```php
private function get_settings_by_section() {
    $defs = $this->get_setting_defs();
    $grouped = []; $order = 0;
    foreach ( $defs as $key => $def ) {
        $section  = $def['section'] ?? 'advanced';
        $priority = isset( $def['priority'] ) ? (int) $def['priority'] : 50;
        $grouped[ $section ][ $key ] = [ 'def' => $def, 'priority' => $priority, 'order' => $order++ ];
    }
    $out = [];
    foreach ( $grouped as $section => $items ) {
        uasort( $items, function ( $a, $b ) {
            return $a['priority'] !== $b['priority'] ? $a['priority'] - $b['priority'] : $a['order'] - $b['order'];
        } );
        $out[ $section ] = [];
        foreach ( $items as $k => $v ) { $out[ $section ][ $k ] = $v['def']; }
    }
    return $out;
}
```

- [ ] **Step 4: Lint**

Run: `php -l anchor-post-display/includes/class-apd-display-cpt.php`
Expected: No syntax errors.

- [ ] **Step 5: Commit**

```bash
git add anchor-post-display/
git commit -m "Add Post Display CPT settings schema + section grouping"
```

---

## Task 4: Builder UI shell + section panes

**Files:**
- Modify: `anchor-post-display/includes/class-apd-display-cpt.php`

- [ ] **Step 1: Hook the builder** in the constructor:

```php
add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
add_action( 'edit_form_after_title', [ $this, 'render_builder_after_title' ] );
```

- [ ] **Step 2: Add the builder render method** (mirrors the gallery):

```php
public function add_metaboxes() { /* builder replaces metaboxes; intentionally empty */ }

public function render_builder_after_title( $post ) {
    if ( ! ( $post instanceof WP_Post ) || $post->post_type !== self::CPT ) return;
    wp_nonce_field( self::NONCE, self::NONCE );

    $sections = [
        'source'     => 'Source',
        'content'    => 'Content',
        'layout'     => 'Layout',
        'style'      => 'Style',
        'behavior'   => 'Behavior',
        'responsive' => 'Responsive',
        'advanced'   => 'Advanced',
    ];
    $panels = [];
    foreach ( $sections as $key => $label ) {
        if ( 'source' === $key ) {
            $panels[ $key ] = [ $this, 'render_pane_source' ];
        } else {
            $panels[ $key ] = function ( $p ) use ( $key ) { $this->render_pane_section( $p, $key ); };
        }
    }

    Anchor_Builder_Shell::render( [
        'id'        => 'anchor-post-display-builder',
        'post'      => $post,
        'title'     => $post->post_title ?: 'Untitled post display',
        'shortcode' => '[anchor_post_grid id="' . $post->ID . '"]',
        'view_url'  => '',
        'tabs'      => $sections,
        'panels'    => $panels,
        'preview'   => [ $this, 'render_pane_preview' ],
        'utility'   => [ $this, 'render_pane_utility' ],
    ] );
}

public function render_pane_section( $post, $section ) {
    $grouped  = $this->get_settings_by_section();
    $defaults = $this->default_settings();
    if ( empty( $grouped[ $section ] ) ) {
        echo '<p class="anchor-builder__empty">No settings in this section.</p>';
        return;
    }
    foreach ( $grouped[ $section ] as $key => $def ) {
        $meta_key = 'apd_' . $key;
        $saved    = get_post_meta( $post->ID, $meta_key, true );
        $value    = ( $saved !== '' && $saved !== false ) ? $saved : ( $defaults[ $key ] ?? '' );
        Anchor_Builder_Shell::render_field( $key, $def, $value, $meta_key );
    }
}

public function render_pane_preview( $post ) {
    echo '<div id="apd-preview" class="apd-preview" data-post-id="' . intval( $post->ID ) . '">';
    echo '<p class="apd-preview__hint">Save or edit settings to preview.</p>';
    echo '</div>';
}

public function render_pane_utility( $post ) {
    $layout = get_post_meta( $post->ID, 'apd_layout', true ) ?: 'grid';
    ?>
    <div class="anchor-builder__util-row"><span class="anchor-builder__util-label">Status</span><span class="anchor-builder__util-value"><?php echo esc_html( ucfirst( $post->post_status ) ); ?></span></div>
    <div class="anchor-builder__util-row"><span class="anchor-builder__util-label">Layout</span><span class="anchor-builder__util-value"><?php echo esc_html( $layout ); ?></span></div>
    <div class="anchor-builder__util-row"><span class="anchor-builder__util-label">ID</span><span class="anchor-builder__util-value"><?php echo intval( $post->ID ); ?></span></div>
    <?php
}
```

- [ ] **Step 3: Lint**

Run: `php -l anchor-post-display/includes/class-apd-display-cpt.php`
Expected: No syntax errors.

- [ ] **Step 4: Commit**

```bash
git add anchor-post-display/
git commit -m "Add Post Display builder shell + schema-driven section panes"
```

---

## Task 5: Source pane (query builder)

**Files:**
- Modify: `anchor-post-display/includes/class-apd-display-cpt.php`

- [ ] **Step 1: Add `render_pane_source`** with friendly query controls. Source values are stored as `apd_src_*` meta.

```php
public function render_pane_source( $post ) {
    $get = function ( $k, $d = '' ) use ( $post ) {
        $v = get_post_meta( $post->ID, 'apd_src_' . $k, true );
        return ( $v === '' || $v === false ) ? $d : $v;
    };
    $selected_types = (array) $get( 'post_types', [] );
    if ( ! is_array( $selected_types ) ) $selected_types = array_filter( array_map( 'trim', explode( ',', (string) $selected_types ) ) );

    $types = get_post_types( [ 'public' => true ], 'objects' );
    unset( $types['attachment'] );
    ?>
    <div class="apd-source">
        <fieldset class="apd-source__group">
            <legend>Post types</legend>
            <?php foreach ( $types as $t ) : ?>
                <label class="apd-source__check">
                    <input type="checkbox" name="apd_src_post_types[]" value="<?php echo esc_attr( $t->name ); ?>" <?php checked( in_array( $t->name, $selected_types, true ) ); ?>>
                    <?php echo esc_html( $t->labels->singular_name ); ?>
                </label>
            <?php endforeach; ?>
            <p class="description">None checked = all searchable types.</p>
        </fieldset>

        <p><label>Include taxonomy<br><input type="text" name="apd_src_taxonomy" value="<?php echo esc_attr( $get( 'taxonomy', 'category' ) ); ?>" class="regular-text"></label></p>
        <p><label>Include terms (slugs, comma-separated)<br><input type="text" name="apd_src_terms" value="<?php echo esc_attr( $get( 'terms' ) ); ?>" class="regular-text"></label></p>
        <p><label>Exclude taxonomy<br><input type="text" name="apd_src_exclude_taxonomy" value="<?php echo esc_attr( $get( 'exclude_taxonomy', 'category' ) ); ?>" class="regular-text"></label></p>
        <p><label>Exclude terms (slugs, comma-separated)<br><input type="text" name="apd_src_exclude_terms" value="<?php echo esc_attr( $get( 'exclude_terms' ) ); ?>" class="regular-text"></label></p>

        <p><label>Order by
            <select name="apd_src_orderby">
                <?php foreach ( [ 'date' => 'Date', 'title' => 'Title', 'menu_order' => 'Menu order', 'rand' => 'Random' ] as $v => $l ) : ?>
                    <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $get( 'orderby', 'date' ), $v ); ?>><?php echo esc_html( $l ); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label> Order
            <select name="apd_src_order">
                <option value="DESC" <?php selected( $get( 'order', 'DESC' ), 'DESC' ); ?>>Descending</option>
                <option value="ASC" <?php selected( $get( 'order', 'DESC' ), 'ASC' ); ?>>Ascending</option>
            </select>
        </label></p>

        <p><label>Posts per page <input type="number" name="apd_src_posts" value="<?php echo esc_attr( $get( 'posts', 12 ) ); ?>" class="small-text" min="1" max="100"></label>
        <label> Max posts (0 = no cap) <input type="number" name="apd_src_max_posts" value="<?php echo esc_attr( $get( 'max_posts', 0 ) ); ?>" class="small-text" min="0"></label></p>

        <p><label>Forced search term (optional)<br><input type="text" name="apd_src_search" value="<?php echo esc_attr( $get( 'search' ) ); ?>" class="regular-text"></label></p>
    </div>
    <?php
}
```

- [ ] **Step 2: Lint**

Run: `php -l anchor-post-display/includes/class-apd-display-cpt.php`
Expected: No syntax errors.

- [ ] **Step 3: Commit**

```bash
git add anchor-post-display/
git commit -m "Add Post Display CPT Source pane (query builder)"
```

---

## Task 6: Save handler

**Files:**
- Modify: `anchor-post-display/includes/class-apd-display-cpt.php`

- [ ] **Step 1: Hook save** in the constructor:

```php
add_action( 'save_post', [ $this, 'save_meta' ] );
```

- [ ] **Step 2: Add `save_meta`** — schema fields + source fields, mirroring the gallery's sanitization:

```php
public function save_meta( $post_id ) {
    if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $defaults = $this->default_settings();
    foreach ( $this->get_setting_defs() as $key => $def ) {
        $meta_key = 'apd_' . $key;
        switch ( $def['type'] ) {
            case 'checkbox':
                $val = isset( $_POST[ $meta_key ] ) ? '1' : '0';
                break;
            case 'number':
                $val = isset( $_POST[ $meta_key ] ) ? intval( $_POST[ $meta_key ] ) : ( $defaults[ $key ] ?? 0 );
                if ( isset( $def['min'] ) ) $val = max( $def['min'], $val );
                if ( isset( $def['max'] ) ) $val = min( $def['max'], $val );
                break;
            case 'select':
                $val = isset( $_POST[ $meta_key ] ) ? sanitize_text_field( $_POST[ $meta_key ] ) : ( $defaults[ $key ] ?? '' );
                if ( isset( $def['options'] ) && ! array_key_exists( $val, $def['options'] ) ) $val = $defaults[ $key ] ?? '';
                break;
            case 'color':
                $raw = isset( $_POST[ $meta_key ] ) ? trim( (string) wp_unslash( $_POST[ $meta_key ] ) ) : '';
                $val = ( preg_match( '/^#([0-9a-fA-F]{3}){1,2}$/', $raw ) || preg_match( '/^rgba?\(\s*[\d.\s,%]+\s*\)$/', $raw ) ) ? $raw : '';
                break;
            case 'textarea':
                $raw = isset( $_POST[ $meta_key ] ) ? (string) wp_unslash( $_POST[ $meta_key ] ) : '';
                $raw = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $raw );
                $raw = preg_replace( '#</style\s*>#i', '', $raw );
                if ( strlen( $raw ) > 10240 ) $raw = substr( $raw, 0, 10240 );
                $val = $raw;
                break;
            default:
                $val = isset( $_POST[ $meta_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) ) : '';
        }
        update_post_meta( $post_id, $meta_key, $val );
    }

    // Source fields
    $post_types = isset( $_POST['apd_src_post_types'] ) && is_array( $_POST['apd_src_post_types'] )
        ? array_values( array_filter( array_map( 'sanitize_key', $_POST['apd_src_post_types'] ) ) )
        : [];
    update_post_meta( $post_id, 'apd_src_post_types', $post_types );

    $text_src = [ 'taxonomy', 'terms', 'exclude_taxonomy', 'exclude_terms', 'orderby', 'order', 'search' ];
    foreach ( $text_src as $k ) {
        update_post_meta( $post_id, 'apd_src_' . $k, sanitize_text_field( wp_unslash( $_POST[ 'apd_src_' . $k ] ?? '' ) ) );
    }
    update_post_meta( $post_id, 'apd_src_posts', max( 1, min( 100, intval( $_POST['apd_src_posts'] ?? 12 ) ) ) );
    update_post_meta( $post_id, 'apd_src_max_posts', max( 0, intval( $_POST['apd_src_max_posts'] ?? 0 ) ) );
}
```

- [ ] **Step 3: Lint**

Run: `php -l anchor-post-display/includes/class-apd-display-cpt.php`
Expected: No syntax errors.

- [ ] **Step 4: Commit**

```bash
git add anchor-post-display/
git commit -m "Add Post Display CPT save handler"
```

---

## Task 7: Shortcode `id` resolution + settings loader

Wire the CPT into rendering. Add a method that loads a display's settings+query into the `$params` array the renderer already consumes.

**Files:**
- Modify: `anchor-post-display/includes/class-apd-display-cpt.php`
- Modify: `anchor-post-display/anchor-post-display.php`

- [ ] **Step 1: Add a public static loader** on the CPT class that returns a normalized `$params` array for a given post, merging display settings + source query. It returns `null` if the post is not a published display.

```php
public static function build_params_for_post( $post_id ) {
    $post = get_post( (int) $post_id );
    if ( ! $post || $post->post_type !== self::CPT || $post->post_status !== 'publish' ) return null;

    $get  = function ( $k, $d = '' ) use ( $post_id ) {
        $v = get_post_meta( $post_id, $k, true );
        return ( $v === '' || $v === false ) ? $d : $v;
    };
    $post_types = (array) get_post_meta( $post_id, 'apd_src_post_types', true );
    $post_types = implode( ',', array_filter( array_map( 'strval', $post_types ) ) );

    return [
        'post_type'         => $post_types,
        'taxonomy'          => $get( 'apd_src_taxonomy', 'category' ),
        'terms'             => $get( 'apd_src_terms' ),
        'exclude_taxonomy'  => $get( 'apd_src_exclude_taxonomy', 'category' ),
        'exclude_terms'     => $get( 'apd_src_exclude_terms' ),
        'image_size'        => $get( 'apd_image_size', 'medium' ),
        'posts'             => (int) $get( 'apd_src_posts', 12 ),
        'search'            => $get( 'apd_src_search' ),
        'columns'           => (int) $get( 'apd_columns_desktop', 3 ),
        'layout'            => $get( 'apd_layout', 'grid' ),
        'pagination'        => $get( 'apd_pagination', 'none' ),
        'pagination_window' => (int) $get( 'apd_pagination_window', 7 ),
        'orderby'           => $get( 'apd_src_orderby', 'date' ),
        'order'             => $get( 'apd_src_order', 'DESC' ),
        'max_posts'         => (int) $get( 'apd_src_max_posts', 0 ),
        'show_date'         => $get( 'apd_show_date', '0' ) === '1' ? 'yes' : 'no',
        'show_type'         => $get( 'apd_show_type', '0' ) === '1' ? 'yes' : 'no',
        'no_results'        => $get( 'apd_no_results', 'No results found.' ),
        'id'                => $get( 'apd_html_anchor' ),
        'teaser_words'      => (int) $get( 'apd_teaser_words', 26 ),
        'fields'            => $get( 'apd_fields' ),
        // Display/style/responsive — consumed by the renderer's CSS + JS:
        'columns_tablet'    => (int) $get( 'apd_columns_tablet', 2 ),
        'columns_mobile'    => (int) $get( 'apd_columns_mobile', 1 ),
        'gap'               => (int) $get( 'apd_gap', 16 ),
        'gap_mobile'        => (int) $get( 'apd_gap_mobile', 0 ),
        'card_style'        => $get( 'apd_card_style', 'card' ),
        'border_radius'     => (int) $get( 'apd_border_radius', 0 ),
        'tile_shadow'       => $get( 'apd_tile_shadow', 'none' ),
        'wrapper_bg'        => $get( 'apd_wrapper_bg' ),
        'title_color'       => $get( 'apd_title_color' ),
        'title_size'        => (int) $get( 'apd_title_size', 0 ),
        'title_weight'      => $get( 'apd_title_weight', '400' ),
        'slider_per_view'         => (int) $get( 'apd_slider_per_view', 3 ),
        'slider_per_view_tablet'  => (int) $get( 'apd_slider_per_view_tablet', 2 ),
        'slider_per_view_mobile'  => (int) $get( 'apd_slider_per_view_mobile', 1 ),
        'slider_autoplay'         => $get( 'apd_slider_autoplay', '0' ) === '1' ? 'yes' : 'no',
        'slider_speed'            => (int) $get( 'apd_slider_speed', 5000 ),
        'carousel_loop'           => $get( 'apd_carousel_loop', '0' ),
        'carousel_arrows'         => $get( 'apd_carousel_arrows', '0' ),
        'carousel_dots'           => $get( 'apd_carousel_dots', '0' ),
        'carousel_pause_on_hover' => $get( 'apd_carousel_pause_on_hover', '0' ),
        'custom_css'              => $get( 'apd_custom_css' ),
    ];
}
```

- [ ] **Step 2: Branch the shortcode** in `anchor-post-display.php`'s `shortcode_grid`. At the very top, after `$opts = $this->get_option();`, add CPT resolution:

```php
// CPT mode: [anchor_post_grid id="123"] or id="my-slug"
$raw_id = isset( $atts['id'] ) ? trim( (string) $atts['id'] ) : '';
if ( $raw_id !== '' ) {
    $resolved = null;
    if ( ctype_digit( $raw_id ) ) {
        $resolved = Anchor_APD_Display_CPT::build_params_for_post( (int) $raw_id );
    }
    if ( ! $resolved ) {
        $by_slug = get_posts( [ 'post_type' => Anchor_APD_Display_CPT::CPT, 'name' => $raw_id, 'posts_per_page' => 1, 'post_status' => 'publish' ] );
        if ( $by_slug ) $resolved = Anchor_APD_Display_CPT::build_params_for_post( $by_slug[0]->ID );
    }
    if ( $resolved ) {
        // Inline atts may still override individual params.
        foreach ( [ 'layout', 'columns', 'posts', 'orderby', 'order', 'fields', 'pagination' ] as $ov ) {
            if ( isset( $atts[ $ov ] ) && $atts[ $ov ] !== '' && $atts[ $ov ] !== $opts[ $ov ] ?? null ) {
                $resolved[ $ov ] = $atts[ $ov ];
            }
        }
        return $this->render_resolved_display( $resolved );
    }
    // Unknown id falls through to inline behavior (renders nothing useful) — return empty for safety.
    return '';
}
```

Note: the `id` attribute previously meant "HTML id for search targeting." To preserve that for inline mode, only treat `id` as a CPT reference when it resolves to a published display; otherwise the old behavior (HTML id) still applies via `normalize_params`. Since a numeric/slug that doesn't resolve returns `''`, document this in the shortcode reference (Task 11).

- [ ] **Step 3: Add `render_resolved_display`** to the module — builds the wrapper using the renderer, identical structure to the existing inline path but driven by `$resolved` params and emitting the scoped responsive CSS (Task 8 adds the CSS method):

```php
private function render_resolved_display( $params ) {
    $this->enqueue_assets();
    $grid_id = ! empty( $params['id'] ) ? $params['id'] : 'apd-' . wp_unique_id();
    if ( empty( $params['search'] ) && isset( $_GET['s'] ) ) {
        $params['search'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
    }
    $query      = new WP_Query( Anchor_APD_Renderer::build_query_args( $params, 1 ) );
    $data_attrs = $this->build_data_attrs( $params );
    $scoped_css = Anchor_APD_Renderer::build_scoped_css( $grid_id, $params );

    ob_start();
    echo $scoped_css;
    echo '<div class="anchor-post-grid-wrap anchor-post-grid-wrap--' . esc_attr( $params['layout'] ) . '" data-layout="' . esc_attr( $params['layout'] ) . '">';
    echo Anchor_APD_Renderer::render_layout_open( $grid_id, $params, $data_attrs );
    echo Anchor_APD_Renderer::render_grid_items( $query, $params );
    echo Anchor_APD_Renderer::render_layout_close( $params );
    echo Anchor_APD_Renderer::render_pagination( $query, $params, 1 );
    echo '</div>';
    wp_reset_postdata();
    return ob_get_clean();
}
```

- [ ] **Step 4: Lint both files**

Run: `php -l anchor-post-display/includes/class-apd-display-cpt.php && php -l anchor-post-display/anchor-post-display.php`
Expected: No syntax errors.

- [ ] **Step 5: Commit**

```bash
git add anchor-post-display/
git commit -m "Resolve [anchor_post_grid id] to CPT displays with inline override"
```

---

## Task 8: Renderer — layout markup helpers + responsive scoped CSS

Centralize the wrapper markup (so inline and CPT share it) and add the responsive CSS builder. This is the one pure function with a standalone test.

**Files:**
- Modify: `anchor-post-display/includes/class-apd-renderer.php`
- Create: `tests/apd-css-builder-test.php`

- [ ] **Step 1: Add `render_layout_open` / `render_layout_close`** to the renderer — extract the slider/grid wrapper branch from today's `shortcode_grid` (lines ~435-452), generalized so `carousel` uses the slider markup with extra dot/arrow controls:

```php
public static function render_layout_open( $grid_id, $params, $data_attrs ) {
    $layout = $params['layout'];
    if ( in_array( $layout, [ 'slider', 'carousel' ], true ) ) {
        $h  = '<div class="anchor-post-slider anchor-post-slider--' . esc_attr( $layout ) . '">';
        $h .= '<div class="anchor-post-slider-viewport">';
        $h .= '<div id="' . esc_attr( $grid_id ) . '" class="anchor-post-grid anchor-post-slider-track" data-columns="' . intval( $params['columns'] ) . '" data-layout="' . esc_attr( $layout ) . '"' . $data_attrs . '>';
        return $h;
    }
    return '<div id="' . esc_attr( $grid_id ) . '" class="anchor-post-grid" data-columns="' . intval( $params['columns'] ) . '" data-layout="' . esc_attr( $layout ) . '"' . $data_attrs . '>';
}

public static function render_layout_close( $params ) {
    $layout = $params['layout'];
    if ( in_array( $layout, [ 'slider', 'carousel' ], true ) ) {
        $arrows = ! empty( $params['carousel_arrows'] ) && $params['carousel_arrows'] !== '0';
        $dots   = ! empty( $params['carousel_dots'] ) && $params['carousel_dots'] !== '0';
        $h  = '</div></div>'; // track + viewport
        if ( $arrows ) {
            $h .= '<div class="anchor-post-slider-nav">';
            $h .= '<button type="button" class="anchor-post-slider-btn anchor-post-slider-prev" aria-label="Previous">&lsaquo;</button>';
            $h .= '<button type="button" class="anchor-post-slider-btn anchor-post-slider-next" aria-label="Next">&rsaquo;</button>';
            $h .= '</div>';
        }
        if ( $dots ) $h .= '<div class="anchor-post-slider-dots" aria-hidden="false"></div>';
        $h .= '</div>'; // .anchor-post-slider
        return $h;
    }
    return '</div>';
}
```

- [ ] **Step 2: Add `build_scoped_css`** — pure function, returns a `<style>` block scoped to `#$grid_id`. Reads desktop/tablet/mobile columns, slider per-view, gap, and the lean style keys. Breakpoints tablet ≤1024px, mobile ≤767px.

```php
public static function build_scoped_css( $grid_id, $params ) {
    $sel    = '#' . $grid_id;
    $layout = $params['layout'] ?? 'grid';
    $css    = '';

    if ( 'grid' === $layout ) {
        $cd = max( 1, (int) ( $params['columns'] ?? 3 ) );
        $ct = max( 1, (int) ( $params['columns_tablet'] ?? 2 ) );
        $cm = max( 1, (int) ( $params['columns_mobile'] ?? 1 ) );
        $css .= "$sel{display:grid;grid-template-columns:repeat($cd,1fr);}";
        $css .= "@media(max-width:1024px){$sel{grid-template-columns:repeat($ct,1fr);}}";
        $css .= "@media(max-width:767px){$sel{grid-template-columns:repeat($cm,1fr);}}";
    } elseif ( in_array( $layout, [ 'slider', 'carousel' ], true ) ) {
        $pd = max( 1, (int) ( $params['slider_per_view'] ?? 3 ) );
        $pt = max( 1, (int) ( $params['slider_per_view_tablet'] ?? 2 ) );
        $pm = max( 1, (int) ( $params['slider_per_view_mobile'] ?? 1 ) );
        $css .= "$sel{--apd-per-view:$pd;}";
        $css .= "$sel .anchor-post-grid-card{flex:0 0 calc((100% - (var(--apd-gap,16px) * ($pd - 1))) / $pd);}";
        $css .= "@media(max-width:1024px){$sel .anchor-post-grid-card{flex-basis:calc((100% - (var(--apd-gap,16px) * ($pt - 1))) / $pt);}}";
        $css .= "@media(max-width:767px){$sel .anchor-post-grid-card{flex-basis:calc((100% - (var(--apd-gap,16px) * ($pm - 1))) / $pm);}}";
    }

    $gap = (int) ( $params['gap'] ?? 16 );
    $css .= "$sel{--apd-gap:{$gap}px;gap:{$gap}px;}";
    $gm = (int) ( $params['gap_mobile'] ?? 0 );
    if ( $gm > 0 ) $css .= "@media(max-width:767px){$sel{--apd-gap:{$gm}px;gap:{$gm}px;}}";

    $br = (int) ( $params['border_radius'] ?? 0 );
    if ( $br > 0 ) $css .= "$sel .anchor-post-grid-card{border-radius:{$br}px;overflow:hidden;}";

    $shadow_map = [ 'soft' => '0 1px 4px rgba(0,0,0,.08)', 'medium' => '0 4px 12px rgba(0,0,0,.12)', 'strong' => '0 8px 24px rgba(0,0,0,.18)' ];
    if ( ! empty( $params['tile_shadow'] ) && isset( $shadow_map[ $params['tile_shadow'] ] ) ) {
        $css .= "$sel .anchor-post-grid-card{box-shadow:{$shadow_map[$params['tile_shadow']]};}";
    }
    if ( ! empty( $params['wrapper_bg'] ) )  $css .= "$sel{background:" . $params['wrapper_bg'] . ";}";
    if ( ! empty( $params['title_color'] ) ) $css .= "$sel .anchor-post-grid-title{color:" . $params['title_color'] . ";}";
    if ( ! empty( $params['title_size'] ) && (int) $params['title_size'] > 0 ) $css .= "$sel .anchor-post-grid-title{font-size:" . (int) $params['title_size'] . "px;}";
    if ( ! empty( $params['title_weight'] ) ) $css .= "$sel .anchor-post-grid-title{font-weight:" . preg_replace( '/[^0-9]/', '', $params['title_weight'] ) . ";}";

    if ( ! empty( $params['custom_css'] ) ) {
        $css .= preg_replace( '#</?style[^>]*>#i', '', (string) $params['custom_css'] );
    }

    return "<style id=\"{$grid_id}-css\">" . $css . "</style>";
}
```

- [ ] **Step 3: Write the standalone logic test** — verifies the CSS builder emits the right column counts and breakpoints. No WordPress needed (the function uses only string ops + casts).

```php
<?php
// tests/apd-css-builder-test.php — run: php tests/apd-css-builder-test.php
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../anchor-post-display/includes/class-apd-renderer.php';

$fail = 0;
function check( $cond, $msg ) { global $fail; if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fail++; } }

$grid = Anchor_APD_Renderer::build_scoped_css( 'apd-x', [
    'layout' => 'grid', 'columns' => 4, 'columns_tablet' => 2, 'columns_mobile' => 1, 'gap' => 20,
] );
check( strpos( $grid, 'repeat(4,1fr)' ) !== false, 'grid desktop = 4 cols' );
check( strpos( $grid, '@media(max-width:1024px)' ) !== false && strpos( $grid, 'repeat(2,1fr)' ) !== false, 'grid tablet = 2 cols' );
check( strpos( $grid, '@media(max-width:767px)' ) !== false && strpos( $grid, 'repeat(1,1fr)' ) !== false, 'grid mobile = 1 col' );
check( strpos( $grid, '--apd-gap:20px' ) !== false, 'gap applied' );

$slider = Anchor_APD_Renderer::build_scoped_css( 'apd-y', [
    'layout' => 'slider', 'slider_per_view' => 3, 'slider_per_view_tablet' => 2, 'slider_per_view_mobile' => 1, 'gap' => 16,
] );
check( strpos( $slider, '--apd-per-view:3' ) !== false, 'slider desktop per-view = 3' );
check( strpos( $slider, '/ 2)' ) !== false, 'slider tablet flex-basis divides by 2' );
check( strpos( $slider, '/ 1)' ) !== false, 'slider mobile flex-basis divides by 1' );

$gm = Anchor_APD_Renderer::build_scoped_css( 'apd-z', [ 'layout' => 'grid', 'columns' => 3, 'gap' => 16, 'gap_mobile' => 8 ] );
check( strpos( $gm, '--apd-gap:8px' ) !== false, 'mobile gap override applied' );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
```

- [ ] **Step 4: Run the test**

Run: `php tests/apd-css-builder-test.php`
Expected: `ALL PASSED`, exit 0.

- [ ] **Step 5: Lint the renderer**

Run: `php -l anchor-post-display/includes/class-apd-renderer.php`
Expected: No syntax errors.

- [ ] **Step 6: Refactor the inline path to share the markup helpers** — in `shortcode_grid`, replace the inline slider/grid `if/else` markup block (current lines ~435-453) with calls to `Anchor_APD_Renderer::render_layout_open/close` and prepend `Anchor_APD_Renderer::build_scoped_css(...)`. Confirms one code path for both modes.

- [ ] **Step 7: Lint + re-run CSS test + commit**

Run: `php -l anchor-post-display/anchor-post-display.php && php tests/apd-css-builder-test.php`
Expected: No syntax errors; ALL PASSED.

```bash
git add anchor-post-display/ tests/apd-css-builder-test.php
git commit -m "Add shared layout markup + responsive scoped CSS builder with logic test"
```

---

## Task 9: Frontend JS — carousel behavior + list CSS

**Files:**
- Modify: `anchor-post-display/assets/frontend.js`
- Modify: `anchor-post-display/assets/frontend.css`

- [ ] **Step 1: Extend the slider init** to read carousel flags from data attributes and add loop + dots + pause-on-hover. The existing `initPostSlider(grid)` already handles per-view, autoplay, swipe, and prev/next. Add, inside it after the autoplay setup:

```js
var isCarousel = grid.dataset.layout === 'carousel';
var loop       = grid.dataset.carouselLoop === '1' || grid.dataset.carouselLoop === 'yes';
var wantDots   = grid.dataset.carouselDots === '1' || grid.dataset.carouselDots === 'yes';
var pauseHover = grid.dataset.carouselPauseOnHover === '1' || grid.dataset.carouselPauseOnHover === 'yes';

// Build dots
if (wantDots && slider) {
    var dotsWrap = slider.querySelector('.anchor-post-slider-dots');
    if (dotsWrap) {
        dotsWrap.innerHTML = '';
        var pages = Math.max(1, Math.ceil(total / getSliderPerView(grid)));
        for (var d = 0; d < pages; d++) {
            var dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'anchor-post-slider-dot' + (d === 0 ? ' is-active' : '');
            dot.setAttribute('aria-label', 'Go to slide ' + (d + 1));
            (function (idx) { dot.onclick = function () { grid._apdSliderGo(idx * getSliderPerView(grid), true); }; })(d);
            dotsWrap.appendChild(dot);
        }
    }
}

// Pause autoplay on hover
if (pauseHover && autoplay && slider) {
    slider.addEventListener('mouseenter', function () { stopSlider(grid); });
    slider.addEventListener('mouseleave', function () { if (grid._apdSliderGo) grid._apdSliderGo(current, true); });
}
```

In the existing index-clamping logic, branch on `loop`: when advancing past the end with `loop` true, wrap to `0`; when going before `0`, wrap to the last page. Update active dot in `grid._apdSliderGo` by toggling `.is-active` on `.anchor-post-slider-dot` at `floor(current / perView)`.

- [ ] **Step 2: Add list + carousel CSS** to `frontend.css`:

```css
/* List layout */
.anchor-post-grid-wrap--list .anchor-post-grid { display: flex; flex-direction: column; }
.anchor-post-grid-wrap--list .anchor-post-grid-card { display: flex; gap: 16px; align-items: flex-start; }
.anchor-post-grid-wrap--list .anchor-post-grid-image { flex: 0 0 33%; max-width: 33%; }

/* Slider/carousel track */
.anchor-post-slider-viewport { overflow: hidden; }
.anchor-post-slider-track { display: flex; gap: var(--apd-gap, 16px); transition: transform .4s ease; }
.anchor-post-slider-dots { display: flex; gap: 8px; justify-content: center; margin-top: 12px; }
.anchor-post-slider-dot { width: 10px; height: 10px; border-radius: 50%; border: 0; background: #c9ced6; cursor: pointer; padding: 0; }
.anchor-post-slider-dot.is-active { background: #2a3744; }
```

- [ ] **Step 3: Ensure new data attributes are emitted** — confirm `build_data_attrs` in the module includes `carousel_loop`, `carousel_dots`, `carousel_arrows`, `carousel_pause_on_hover`, `slider_per_view_tablet`, `slider_per_view_mobile`. Add any missing keys to the `$keys` map (data-key kebab-case).

- [ ] **Step 4: Lint the module file** (data attrs were edited there)

Run: `php -l anchor-post-display/anchor-post-display.php`
Expected: No syntax errors.

- [ ] **Step 5: Commit**

```bash
git add anchor-post-display/assets/ anchor-post-display/anchor-post-display.php
git commit -m "Add carousel behavior (loop, dots, pause-on-hover) + list layout CSS"
```

---

## Task 10: Live preview AJAX + builder JS

**Files:**
- Modify: `anchor-post-display/includes/class-apd-display-cpt.php`
- Create: `anchor-post-display/assets/builder.js`
- Create: `anchor-post-display/assets/builder.css`

- [ ] **Step 1: Register the preview AJAX** in the CPT constructor and add the handler. It accepts the post ID (preview uses the last-saved meta for simplicity v1; live-unsaved preview is a later enhancement):

```php
add_action( 'wp_ajax_anchor_post_display_preview', [ $this, 'ajax_preview' ] );
```

```php
public function ajax_preview() {
    check_ajax_referer( self::NONCE, 'nonce' );
    $post_id = intval( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error();
    $params = self::build_params_for_post( $post_id );
    if ( ! $params ) wp_send_json_error();
    $grid_id = 'apd-preview-' . $post_id;
    $query   = new WP_Query( Anchor_APD_Renderer::build_query_args( $params, 1 ) );
    $html    = Anchor_APD_Renderer::build_scoped_css( $grid_id, $params );
    $html   .= '<div class="anchor-post-grid-wrap anchor-post-grid-wrap--' . esc_attr( $params['layout'] ) . '">';
    $html   .= Anchor_APD_Renderer::render_layout_open( $grid_id, $params, '' );
    $html   .= Anchor_APD_Renderer::render_grid_items( $query, $params );
    $html   .= Anchor_APD_Renderer::render_layout_close( $params );
    $html   .= '</div>';
    wp_reset_postdata();
    wp_send_json_success( [ 'html' => $html ] );
}
```

- [ ] **Step 2: Enqueue builder + frontend assets on the CPT editor**. Add to the constructor:

```php
add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
```

```php
public function enqueue_admin_assets( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
    if ( get_post_type() !== self::CPT ) return;
    $base = Anchor_Asset_Loader::url( 'anchor-post-display/assets/' );
    wp_enqueue_style( 'anchor-post-display' ); // frontend CSS for accurate preview
    wp_enqueue_script( 'anchor-post-display' );
    wp_enqueue_style( 'apd-builder', $base . 'builder.css', [], self::VERSION );
    wp_enqueue_script( 'apd-builder', $base . 'builder.js', [], self::VERSION, true );
    wp_localize_script( 'apd-builder', 'APD_BUILDER', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( self::NONCE ),
        'postId'  => get_the_ID(),
    ] );
}
```

Note: `anchor-post-display` style/script are registered by the module on `wp_enqueue_scripts`; register them on `admin_enqueue_scripts` too, or move registration to an `init`-level shared registrar. Simplest: in the module, extract `register_assets()` and also call it from `admin_enqueue_scripts` before enqueue.

- [ ] **Step 3: Create `builder.js`** — debounced preview refresh on field change, and device-toolbar width switching:

```js
(function () {
    var cfg = window.APD_BUILDER || {};
    var box = document.getElementById('apd-preview');
    if (!box || !cfg.ajaxUrl) return;
    var timer = null;

    function refresh() {
        var body = new URLSearchParams();
        body.set('action', 'anchor_post_display_preview');
        body.set('nonce', cfg.nonce);
        body.set('post_id', cfg.postId);
        fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    box.innerHTML = res.data.html;
                    if (window.AnchorPostDisplayInit) window.AnchorPostDisplayInit(box);
                }
            });
    }
    function debounced() { clearTimeout(timer); timer = setTimeout(refresh, 400); }

    document.addEventListener('change', function (e) {
        if (e.target.closest('#anchor-post-display-builder')) debounced();
    });

    // Device toolbar (buttons rendered by Anchor_Builder_Device_Toolbar)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.anchor-builder__device');
        if (!btn) return;
        var widths = { desktop: '100%', tablet: '1024px', mobile: '767px', full: '100%' };
        box.style.maxWidth = widths[btn.dataset.device] || '100%';
        box.style.margin = btn.dataset.device === 'desktop' || btn.dataset.device === 'full' ? '0' : '0 auto';
    });

    refresh();
})();
```

- [ ] **Step 4: Expose a frontend re-init hook.** In `frontend.js`, wrap the slider/grid init so it can run on a container: add `window.AnchorPostDisplayInit = function(root){ /* query grids within root and init */ }` and call it for `document` on DOMContentLoaded. This lets the preview re-init injected markup.

- [ ] **Step 5: Create `builder.css`** (minimal):

```css
.apd-preview { background: #fff; padding: 16px; border: 1px solid #e1e5ea; border-radius: 6px; transition: max-width .2s ease; }
.apd-preview__hint { color: #646970; font-style: italic; }
.apd-source__group { border: 1px solid #e1e5ea; padding: 12px; border-radius: 4px; margin-bottom: 12px; }
.apd-source__check { display: inline-block; margin: 0 14px 6px 0; }
```

- [ ] **Step 6: Lint + commit**

Run: `php -l anchor-post-display/includes/class-apd-display-cpt.php && php -l anchor-post-display/anchor-post-display.php`
Expected: No syntax errors.

```bash
git add anchor-post-display/
git commit -m "Add Post Display live preview AJAX + builder JS/CSS + device toolbar"
```

---

## Task 11: Settings-page reference + version bump

**Files:**
- Modify: `anchor-post-display/anchor-post-display.php`
- Modify: `anchor-tools.php`

- [ ] **Step 1: Update the shortcode reference** in `render_shortcode_reference()` — add an `id` row to the `[anchor_post_grid]` table and a short note that `id="N"` or `id="slug"` renders a saved Post Display, and that inline attributes still override. Add the carousel layout to the `layout` description and the new responsive atts are managed in the CPT.

```php
[ 'id', '(none)', 'Render a saved Post Display by numeric ID or slug; inline atts override it' ],
```

Add a paragraph above the tables:

```php
echo '<p><strong>Post Displays (new):</strong> Build reusable displays under <em>Post Displays</em> in the admin menu, then embed with <code>[anchor_post_grid id="123"]</code>. Each display has its own layout, style, and desktop/tablet/mobile responsive settings.</p>';
```

- [ ] **Step 2: Bump the plugin version** in `anchor-tools.php` header `Version:` (next patch, e.g. `3.7.80` → confirm current then increment).

Run: `grep -n "Version:" anchor-tools.php | head -1`
Then edit that line to the next version.

- [ ] **Step 3: Lint**

Run: `php -l anchor-post-display/anchor-post-display.php && php -l anchor-tools.php`
Expected: No syntax errors.

- [ ] **Step 4: Commit**

```bash
git add anchor-post-display/ anchor-tools.php
git commit -m "Document Post Display CPT shortcode + bump version"
```

---

## Task 12: Manual verification + finish

**Files:** none (validation)

- [ ] **Step 1: Final lint sweep**

Run: `for f in anchor-post-display/anchor-post-display.php anchor-post-display/includes/*.php anchor-tools.php; do php -l "$f"; done`
Expected: "No syntax errors detected" for every file.

- [ ] **Step 2: Re-run the CSS logic test**

Run: `php tests/apd-css-builder-test.php`
Expected: ALL PASSED.

- [ ] **Step 3: Manual checklist in a WordPress environment** (the only real behavior gate — this repo has no WP test harness). Record results:
  1. Activate plugin; confirm **Post Displays** menu appears with Add New.
  2. Create a display per layout (grid, list, slider, carousel); set source = Posts; insert `[anchor_post_grid id="N"]` on a page; confirm each renders.
  3. Resize to desktop/tablet/mobile; confirm columns and slides-per-view match the per-breakpoint settings.
  4. Confirm an existing inline `[anchor_post_grid post_type="post" layout="slider" slider_per_view="4"]` still renders identically (regression).
  5. Confirm resolution by numeric ID and by slug.
  6. Confirm pagination (numbered) and load-more on a grid display.
  7. Confirm the `fields` system: built-in tokens + one ACF/meta key.
  8. Confirm carousel arrows, dots, autoplay, loop, pause-on-hover.
  9. Confirm the builder live preview matches the front end and the device toolbar resizes the preview.
  10. Confirm `[anchor_search]` and the global Post Display Defaults tab are unaffected.

- [ ] **Step 4: Use the finishing-a-development-branch skill** to decide merge/PR for `feature/post-display-cpt`.

---

## Self-Review notes

- **Spec coverage:** Source pane (T5), schema incl. responsive (T3), builder UI (T4), save (T6), `id` resolution + inline override (T7), responsive CSS + carousel markup (T8), carousel JS + list CSS (T9), live preview + device toolbar (T10), reference + version (T11), manual checklist (T12), additive/back-compat verified in T7/T12 step 4. Global-defaults-as-seed in T3 step 2. All spec sections mapped.
- **Back-compat caveat:** `id` previously meant an HTML id for search targeting in inline mode. T7 only hijacks `id` when it resolves to a published display; non-resolving numeric/slug returns empty — documented in T11. If any live site uses `id` as a plain HTML id on an inline grid AND that value happens to match a display slug, behavior changes. Low risk; called out for the manual check.
- **Type consistency:** `build_params_for_post` (T7) returns the exact `$params` keys consumed by `Anchor_APD_Renderer::build_query_args` / `render_grid_items` / `build_scoped_css` (T1, T8) and `build_data_attrs` (T9). Method names consistent across tasks.
