# Anchor Blocks shortcodes + shared preview CSS — Implementation Plan

> **For agentic workers:** Steps use checkbox (`- [ ]`) syntax. This is a no-build,
> manual-test WordPress PHP plugin — there is no automated test suite, so "verify"
> steps are PHP-lint + manual-in-WordPress checks, not unit tests.

**Goal:** Run nested shortcodes inside Anchor Blocks + add a copy-shortcode button, and
give module editor previews access to the whole live site's CSS via one shared helper.

**Architecture:** A new core class `Anchor_Preview_CSS` harvests every stylesheet + inline
head style from a reference URL (default homepage), caches it in a transient, and localizes
it for the admin. A shared `anchor-preview.js` exposes `AnchorPreview.headMarkup()` that
every module's preview iframe uses to build its `<head>`. Blocks/mega-menu (already iframe)
and universal-popups (converted to iframe) consume it.

**Tech Stack:** Raw PHP 7+, jQuery IIFE admin JS, WordPress transients/options/AJAX, no build.

**Scope note (decided during planning):** `code_snippets` has no preview (skip).
`gallery`/`slider`/`ctm_forms` previews render the plugin's own component markup inside the
shared **Builder Shell** (in-page DOM / AJAX), not raw user HTML/CSS/JS. Safely injecting
site CSS there requires converting the shared Builder Shell + device toolbar to an iframe —
a large change to shipped builder UIs with regression risk that can't be auto-verified here.
That conversion is documented as **Task 9 (deferred follow-up)**, not implemented now. The
rollout below covers blocks, mega-menu, and universal-popups — the genuine raw-HTML previews.

**Front-end boundary:** Only Task 1 (nested shortcodes) changes published-page output. All
preview-CSS work is editor-only and enqueues nothing on the front end.

---

### Task 1: Anchor Blocks — nested shortcodes + recursion guard

**Files:** Modify `anchor-blocks/anchor-blocks.php`

- [ ] **Step 1:** Add a `private $rendering = [];` property near the other per-request state
  (after `$printed_base`).
- [ ] **Step 2:** In `shortcode_render()`, after resolving `$id`, add a recursion guard at the
  top of the render body:
  ```php
  if ( isset( $this->rendering[ $id ] ) ) { return ''; } // prevent self/cyclic embed loops
  $this->rendering[ $id ] = true;
  ```
- [ ] **Step 3:** Change the inner HTML to run shortcodes:
  ```php
  $inner = '<div class="anchor-block anchor-block--' . $id . '" data-anchor-block="' . $id . '" data-instance="' . $instance . '">'
         . do_shortcode( $m['html'] )
         . '</div>';
  ```
- [ ] **Step 4:** Before each `return`, release the guard. Restructure the tail so both the
  full-width and normal paths run `unset( $this->rendering[ $id ] );` then `return $inner;`.
- [ ] **Step 5 (verify):** `php -l anchor-blocks/anchor-blocks.php` → "No syntax errors".
  Manual: a block containing `[anchor_block id=SELF]` must not infinite-loop; a block
  containing a real shortcode renders it.
- [ ] **Step 6 (commit):** `feat(blocks): execute nested shortcodes in block HTML with recursion guard`

---

### Task 2: Anchor Blocks — copy-shortcode button in editor

**Files:** Modify `anchor-blocks/anchor-blocks.php` (`render_box_settings`), `anchor-blocks/assets/admin.js`

- [ ] **Step 1:** In `render_box_settings()`, before the full-width checkbox, add (uses real
  post ID; `auto-draft` means unsaved):
  ```php
  <?php if ( $post->post_status !== 'auto-draft' ) : ?>
      <label>Shortcode</label>
      <div class="ab-shortcode-copy">
          <input type="text" readonly class="widefat" id="ab-shortcode-value"
                 value="<?php echo esc_attr( '[anchor_block id="' . (int) $post->ID . '"]' ); ?>">
          <button type="button" class="button" id="ab-shortcode-copy-btn">Copy</button>
      </div>
  <?php else : ?>
      <label>Shortcode</label>
      <p class="description">Save the block to get its shortcode.</p>
  <?php endif; ?>
  ```
- [ ] **Step 2:** In `assets/admin.js`, inside `$(document).ready`, add the copy handler:
  ```js
  $('#ab-shortcode-copy-btn').on('click', function () {
    var $val = $('#ab-shortcode-value');
    var text = $val.val();
    function done() {
      var $b = $('#ab-shortcode-copy-btn'); var t = $b.text();
      $b.text('Copied!'); setTimeout(function () { $b.text(t); }, 1200);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { $val.select(); document.execCommand('copy'); done(); });
    } else { $val.select(); document.execCommand('copy'); done(); }
  });
  ```
- [ ] **Step 3 (verify):** `php -l` clean. Manual: editor shows shortcode + Copy works; new
  block shows the save hint.
- [ ] **Step 4 (commit):** `feat(blocks): add copy-shortcode control to block editor`

---

### Task 3: Core helper `Anchor_Preview_CSS` (harvest + cache + payload)

**Files:** Create `includes/class-anchor-preview-css.php`

- [ ] **Step 1:** Create the class with constants and the harvest/payload/settings logic.
  Full file content:
  ```php
  <?php
  /**
   * Shared editor-preview CSS source. Harvests the live site's stylesheets +
   * inline head styles from a reference URL so module preview iframes resolve
   * theme variables, fonts and plugin CSS regardless of the active theme.
   *
   * Editor-preview only. Enqueues nothing on the front end.
   */
  if ( ! defined( 'ABSPATH' ) ) { exit; }

  class Anchor_Preview_CSS {
      const OPTION_KEY   = 'anchor_preview_settings';
      const TRANSIENT    = 'anchor_preview_harvest';
      const CACHE_TTL     = HOUR_IN_SECONDS;
      const ASSET_VER    = '1.0.0';
      const NONCE_ACTION = 'anchor_preview_refresh';

      public function __construct() {
          add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 12 );
          add_action( 'admin_init',           [ $this, 'register_settings' ] );
          add_action( 'admin_init',           [ $this, 'migrate_legacy_urls' ] );
          add_action( 'wp_ajax_anchor_preview_refresh', [ $this, 'ajax_refresh' ] );
      }

      /* ---- settings ---- */

      private function settings() {
          $o = get_option( self::OPTION_KEY, [] );
          return [
              'reference_url'  => isset( $o['reference_url'] ) ? (string) $o['reference_url'] : '',
              'extra_css_urls' => isset( $o['extra_css_urls'] ) ? (string) $o['extra_css_urls'] : '',
          ];
      }

      private function reference_url() {
          $s = $this->settings();
          $url = trim( $s['reference_url'] );
          return $url !== '' ? $url : home_url( '/' );
      }

      private function lines_to_urls( $text ) {
          $out = [];
          foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
              $line = trim( $line );
              if ( $line !== '' ) { $out[] = $line; }
          }
          return $out;
      }

      private function theme_fallback() {
          $urls = [];
          $child  = get_stylesheet_uri();
          $parent = get_template_directory_uri() . '/style.css';
          if ( $child )  { $urls[] = $child; }
          if ( $parent && $parent !== $child ) { $urls[] = $parent; }
          return $urls;
      }

      /* ---- harvest ---- */

      private function harvest( $url ) {
          $empty = [ 'urls' => [], 'inline' => '', 'count' => 0, 'time' => time(), 'ok' => false ];
          $resp = wp_remote_get( $url, [ 'timeout' => 10, 'redirection' => 3 ] );
          if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
              if ( class_exists( 'Anchor_Schema_Logger' ) ) {
                  Anchor_Schema_Logger::log( 'preview_css_harvest_failed', [ 'url' => $url ] );
              }
              return $empty;
          }
          $html = (string) wp_remote_retrieve_body( $resp );
          $head = $html;
          if ( preg_match( '/<head\b[^>]*>(.*?)<\/head>/is', $html, $m ) ) { $head = $m[1]; }

          $urls = [];
          if ( preg_match_all( '/<link\b[^>]*>/i', $head, $links ) ) {
              foreach ( $links[0] as $tag ) {
                  if ( ! preg_match( '/rel\s*=\s*["\']?[^"\'>]*stylesheet/i', $tag ) ) { continue; }
                  if ( preg_match( '/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $h ) ) {
                      $urls[] = $this->absolutize( html_entity_decode( $h[1] ), $url );
                  }
              }
          }
          $inline = '';
          if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $head, $styles ) ) {
              $inline = implode( "\n", $styles[1] );
          }
          $urls = array_values( array_unique( array_filter( $urls ) ) );
          return [ 'urls' => $urls, 'inline' => $inline, 'count' => count( $urls ), 'time' => time(), 'ok' => true ];
      }

      private function absolutize( $href, $base ) {
          $href = trim( $href );
          if ( $href === '' ) { return ''; }
          if ( preg_match( '#^https?://#i', $href ) ) { return $href; }
          if ( strpos( $href, '//' ) === 0 ) {
              $scheme = parse_url( $base, PHP_URL_SCHEME ) ?: 'https';
              return $scheme . ':' . $href;
          }
          $p = parse_url( $base );
          if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) { return $href; }
          $origin = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
          if ( strpos( $href, '/' ) === 0 ) { return $origin . $href; }
          $dir = isset( $p['path'] ) ? preg_replace( '#/[^/]*$#', '/', $p['path'] ) : '/';
          return $origin . $dir . $href;
      }

      private function cached_harvest( $force = false ) {
          if ( ! $force ) {
              $cached = get_transient( self::TRANSIENT );
              if ( is_array( $cached ) ) { return $cached; }
          }
          $data = $this->harvest( $this->reference_url() );
          set_transient( self::TRANSIENT, $data, self::CACHE_TTL );
          return $data;
      }

      /** Public payload for localization: merged harvest + theme fallback + global extras. */
      public function get_payload( $force = false ) {
          $h = $this->cached_harvest( $force );
          $urls = is_array( $h['urls'] ?? null ) ? $h['urls'] : [];
          $urls = array_merge( $urls, $this->theme_fallback(), $this->lines_to_urls( $this->settings()['extra_css_urls'] ) );
          $urls = array_values( array_unique( array_filter( $urls ) ) );
          return [
              'urls'   => $urls,
              'inline' => is_string( $h['inline'] ?? null ) ? $h['inline'] : '',
              'time'   => (int) ( $h['time'] ?? 0 ),
              'count'  => (int) ( $h['count'] ?? 0 ),
          ];
      }

      /** Enqueue the shared preview glue + localized payload on a module edit screen. */
      public static function enqueue_for_admin() {
          static $done = false;
          if ( $done ) { return; }
          $done = true;
          wp_enqueue_script(
              'anchor-preview',
              Anchor_Asset_Loader::url( 'assets/anchor-preview.js' ),
              [ 'jquery' ], self::ASSET_VER, true
          );
          $instance = new self();
          $p = $instance->get_payload();
          wp_localize_script( 'anchor-preview', 'ANCHOR_PREVIEW', [
              'urls'   => $p['urls'],
              'inline' => $p['inline'],
          ] );
      }
      // (settings UI, migration, ajax — Task 4)
  }
  ```
- [ ] **Step 2 (verify):** `php -l includes/class-anchor-preview-css.php` → no syntax errors.
- [ ] **Step 3 (commit):** `feat(core): add Anchor_Preview_CSS harvest helper`

---

### Task 4: `Anchor_Preview_CSS` — settings tab, migration, AJAX refresh

**Files:** Modify `includes/class-anchor-preview-css.php`

- [ ] **Step 1:** Replace the `// (settings UI, migration, ajax — Task 4)` marker with:
  ```php
  public function register_settings() {
      register_setting( 'anchor_preview_group', self::OPTION_KEY, [
          'type'              => 'array',
          'sanitize_callback' => [ $this, 'sanitize_settings' ],
          'default'           => [],
      ] );
  }

  public function sanitize_settings( $input ) {
      $out = [];
      $ref = isset( $input['reference_url'] ) ? trim( (string) $input['reference_url'] ) : '';
      $out['reference_url'] = $ref === '' ? '' : esc_url_raw( $ref );
      $clean = [];
      foreach ( $this->lines_to_urls( $input['extra_css_urls'] ?? '' ) as $u ) { $clean[] = esc_url_raw( $u ); }
      $out['extra_css_urls'] = implode( "\n", array_filter( $clean ) );
      delete_transient( self::TRANSIENT ); // settings changed → re-harvest next preview
      return $out;
  }

  /** One-time, non-destructive copy of the old Blocks-tab URL list. */
  public function migrate_legacy_urls() {
      $cur = get_option( self::OPTION_KEY, [] );
      if ( ! empty( $cur['extra_css_urls'] ) ) { return; }
      $blocks = get_option( 'anchor_blocks_settings', [] );
      $legacy = isset( $blocks['preview_css_urls'] ) ? trim( (string) $blocks['preview_css_urls'] ) : '';
      if ( $legacy === '' ) { return; }
      $cur = is_array( $cur ) ? $cur : [];
      $cur['extra_css_urls'] = $legacy;
      update_option( self::OPTION_KEY, $cur, false );
  }

  public function ajax_refresh() {
      if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'forbidden', 403 ); }
      check_ajax_referer( self::NONCE_ACTION, 'nonce' );
      $p = $this->get_payload( true );
      wp_send_json_success( [ 'count' => $p['count'], 'time' => $p['time'] ] );
  }

  public function register_tab( $tabs ) {
      $tabs['preview'] = [ 'label' => __( 'Preview', 'anchor-schema' ), 'callback' => [ $this, 'render_tab_content' ] ];
      return $tabs;
  }

  public function render_tab_content() {
      $s = $this->settings();
      $h = get_transient( self::TRANSIENT );
      $when = ( is_array( $h ) && ! empty( $h['time'] ) )
          ? sprintf( '%s ago', human_time_diff( (int) $h['time'], time() ) ) : 'never';
      $count = ( is_array( $h ) && isset( $h['count'] ) ) ? (int) $h['count'] : 0;
      ?>
      <form method="post" action="options.php">
          <?php settings_fields( 'anchor_preview_group' ); ?>
          <h2><?php esc_html_e( 'Preview Stylesheets', 'anchor-schema' ); ?></h2>
          <p class="description"><?php esc_html_e( 'Module editor previews load the live site\'s CSS so they resemble the front end. The reference URL below is fetched and its stylesheets (plus inline :root styles) are reused in every preview. Editor-only — nothing here affects published pages.', 'anchor-schema' ); ?></p>
          <table class="form-table" role="presentation">
              <tr>
                  <th scope="row"><label for="anchor_preview_reference_url"><?php esc_html_e( 'Reference URL', 'anchor-schema' ); ?></label></th>
                  <td>
                      <input type="url" class="regular-text" id="anchor_preview_reference_url"
                             name="<?php echo esc_attr( self::OPTION_KEY ); ?>[reference_url]"
                             value="<?php echo esc_attr( $s['reference_url'] ); ?>"
                             placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
                      <p class="description"><?php esc_html_e( 'Page to harvest CSS from. Defaults to your homepage.', 'anchor-schema' ); ?></p>
                  </td>
              </tr>
              <tr>
                  <th scope="row"><label for="anchor_preview_extra_css_urls"><?php esc_html_e( 'Extra stylesheets', 'anchor-schema' ); ?></label></th>
                  <td>
                      <textarea id="anchor_preview_extra_css_urls" rows="4" class="large-text code"
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[extra_css_urls]"
                                placeholder="https://example.com/extra.css"><?php echo esc_textarea( $s['extra_css_urls'] ); ?></textarea>
                      <p class="description"><?php esc_html_e( 'One URL per line, added on top of the harvested set.', 'anchor-schema' ); ?></p>
                  </td>
              </tr>
              <tr>
                  <th scope="row"><?php esc_html_e( 'Harvest status', 'anchor-schema' ); ?></th>
                  <td>
                      <p id="anchor-preview-status"><?php echo esc_html( sprintf( __( 'Last harvested: %1$s — %2$d stylesheets found.', 'anchor-schema' ), $when, $count ) ); ?></p>
                      <button type="button" class="button" id="anchor-preview-refresh"
                              data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"><?php esc_html_e( 'Refresh now', 'anchor-schema' ); ?></button>
                      <script>
                      (function($){$('#anchor-preview-refresh').on('click',function(){
                        var $b=$(this).prop('disabled',true);
                        $.post(ajaxurl,{action:'anchor_preview_refresh',nonce:$b.data('nonce')})
                         .done(function(r){ if(r&&r.success){ $('#anchor-preview-status').text('Last harvested: just now — '+r.data.count+' stylesheets found.'); } })
                         .always(function(){ $b.prop('disabled',false); });
                      });})(jQuery);
                      </script>
                  </td>
              </tr>
          </table>
          <?php submit_button(); ?>
      </form>
      <?php
  }
  ```
- [ ] **Step 2 (verify):** `php -l includes/class-anchor-preview-css.php` clean.
- [ ] **Step 3 (commit):** `feat(core): preview-css settings tab, legacy migration, refresh`

---

### Task 5: Shared front-end glue `assets/anchor-preview.js`

**Files:** Create `assets/anchor-preview.js`

- [ ] **Step 1:** Create:
  ```js
  (function () {
    function esc(u) { return String(u).replace(/"/g, '&quot;'); }
    window.AnchorPreview = {
      // Returns <link> tags for harvested + extra URLs, then one <style> of inline head CSS.
      headMarkup: function (extraUrls) {
        var data = window.ANCHOR_PREVIEW || { urls: [], inline: '' };
        var urls = (data.urls || []).concat(extraUrls || []);
        var seen = {}, links = '';
        urls.forEach(function (u) {
          if (!u || seen[u]) { return; }
          seen[u] = true;
          links += '<link rel="stylesheet" href="' + esc(u) + '">';
        });
        var inline = data.inline ? '<style>' + data.inline + '</style>' : '';
        return links + inline;
      }
    };
  })();
  ```
- [ ] **Step 2 (commit):** `feat(core): add shared AnchorPreview.headMarkup glue`

---

### Task 6: Wire helper into bootstrap

**Files:** Modify `anchor-tools.php`

- [ ] **Step 1:** In the `require_once` block (after the Schema_Logger require, ~line 46), add:
  ```php
  if ( ! class_exists( 'Anchor_Preview_CSS' ) ) {
      require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-preview-css.php';
  }
  ```
- [ ] **Step 2:** In the `plugins_loaded` closure (~line 112), after the `Anchor_Schema_Admin`
  block, add:
  ```php
  if ( is_admin() && class_exists( 'Anchor_Preview_CSS' ) ) {
      new Anchor_Preview_CSS();
  }
  ```
- [ ] **Step 3 (verify):** `php -l anchor-tools.php` clean.
- [ ] **Step 4 (commit):** `feat(core): bootstrap Anchor_Preview_CSS`

---

### Task 7: Repoint blocks + mega-menu previews to the shared helper

**Files:** Modify `anchor-blocks/anchor-blocks.php`, `anchor-blocks/assets/admin.js`,
`anchor-mega-menu/anchor-mega-menu.php`, `anchor-mega-menu/admin.js`

- [ ] **Step 1 (blocks PHP):** In `admin_assets()`, call `Anchor_Preview_CSS::enqueue_for_admin();`
  before localizing `ANCHOR_BLOCKS`, and reduce that localize payload to per-block extras only —
  keep `previewCssUrls` removed (now from shared helper). Bump `ASSET_VER` to `1.1.0`.
- [ ] **Step 2 (blocks JS):** Replace `previewCssLinks()` body so the harvested set comes from
  the shared helper and only the per-block textarea URLs are passed as extras:
  ```js
  function previewHead() {
    var extra = ($('#ab_preview_css_urls').val() || '')
      .split(/\r\n|\r|\n/).map(function (s) { return s.trim(); }).filter(Boolean);
    return (window.AnchorPreview ? window.AnchorPreview.headMarkup(extra) : '');
  }
  ```
  and in `buildDoc()` replace `previewCssLinks()` with `previewHead()`.
- [ ] **Step 3 (mega-menu PHP):** In `admin_assets()`, call `Anchor_Preview_CSS::enqueue_for_admin();`
  and remove the `MM_PREVIEW` `cssUrls` localization (or set it to `[]`). Bump version `1.1.5`→`1.1.6`.
- [ ] **Step 4 (mega-menu JS):** Replace `cssLinks()` with
  `return (window.AnchorPreview ? window.AnchorPreview.headMarkup() : '');` and keep
  `buildDoc()` calling it.
- [ ] **Step 5 (verify):** `php -l` both PHP files clean. Manual: both previews still render and
  now reflect harvested site CSS.
- [ ] **Step 6 (commit):** `refactor(blocks,mega-menu): use shared AnchorPreview for preview CSS`

---

### Task 8: Convert universal-popups preview to iframe + shared helper

**Files:** Modify `anchor-universal-popups/anchor-universal-popups.php` (`render_box_preview`,
`admin_assets`), `anchor-universal-popups/assets/admin.js`

- [ ] **Step 1 (PHP preview box):** Replace the `#up-preview-wrap`/`#up-preview-content` markup
  in `render_box_preview()` with an iframe:
  ```php
  <p class="description">Live preview renders inside an isolated frame that loads your site's CSS, so colors, fonts and <code>:root</code> variables resolve as on the front end.</p>
  <iframe id="up-preview-frame" style="width:100%; min-height:360px; border:1px solid #ccd0d4; border-radius:8px; background:#fff;"></iframe>
  ```
- [ ] **Step 2 (PHP enqueue):** In `admin_assets()`, call `Anchor_Preview_CSS::enqueue_for_admin();`
  alongside the existing `up-admin` enqueue.
- [ ] **Step 3 (JS):** Replace the `applyPreview()` DOM-injection (the function writing into
  `#up-preview-content`) with iframe `srcdoc` assembly. Identify the popup's HTML/CSS/JS field
  selectors already used in `admin.js` and build:
  ```js
  function buildDoc() {
    var css  = $('#up_css').val()  || '';   // adjust ids to the actual popup fields
    var html = $('#up_html').val() || '';
    var js   = $('#up_js').val()   || '';
    var head = (window.AnchorPreview ? window.AnchorPreview.headMarkup() : '');
    return '<!DOCTYPE html><html><head><meta charset="utf-8">' +
      '<meta name="viewport" content="width=device-width, initial-scale=1">' +
      head + '<style>' + css + '</style></head><body>' + html +
      '<script>(function(){try{' + js + '}catch(e){console.error(e);}})();<\/script></body></html>';
  }
  function applyPreview() { var f = document.getElementById('up-preview-frame'); if (f) { f.srcdoc = buildDoc(); } }
  ```
  Keep the existing change/debounce wiring pointed at `applyPreview()`.
- [ ] **Step 4 (PHP version):** Bump the `up-admin` enqueue version (filemtime already used, so a
  no-op — confirm filemtime is used; if a literal version string, bump it).
- [ ] **Step 5 (verify):** `php -l anchor-universal-popups/anchor-universal-popups.php` clean.
  Manual: popup preview renders in the iframe with site CSS; front-end popup output unchanged.
- [ ] **Step 6 (commit):** `feat(popups): isolated iframe preview using shared site CSS`

---

### Task 9 (DEFERRED follow-up — not implemented now): Builder Shell iframe

`gallery`/`slider` (and the `ctm_forms` AJAX builder) render plugin-component markup into the
admin DOM via `Anchor_Builder_Shell` + `Anchor_Builder_Device_Toolbar`. Giving them harvested
site CSS safely requires moving `.anchor-builder__preview-frame` into an iframe (and updating the
device toolbar to size the iframe). This touches shared infra for multiple shipped modules and
needs manual regression testing of each builder preview. Recommend as a separate change. No code
in this plan modifies these modules.

---

### Task 10: Version bump + release

**Files:** Modify `anchor-tools.php` header

- [ ] **Step 1:** Bump `Version:` in the plugin header (e.g. `3.7.92` → `3.8.0`).
- [ ] **Step 2 (commit):** `chore(release): 3.8.0 — blocks shortcodes + shared preview CSS`
- [ ] **Step 3:** Push to `main` (user handles GitHub release ZIP per Release Process).

---

## Self-review

- **Spec coverage:** Part 1a → Task 1; Part 1b → Task 2; Part 2 helper/harvest/cache → Task 3;
  settings/migration/refresh/glue → Tasks 4–5; bootstrap → Task 6; Phase A rollout → Task 7;
  universal-popups (Phase B clean candidate) → Task 8; remaining Phase B modules → consciously
  deferred with rationale (Task 9, documented scope change vs spec).
- **Front-end boundary:** only Task 1 changes published output; all else editor-only. ✓
- **Type/name consistency:** `Anchor_Preview_CSS::enqueue_for_admin()`, `ANCHOR_PREVIEW.{urls,inline}`,
  `window.AnchorPreview.headMarkup()`, option `anchor_preview_settings`, transient
  `anchor_preview_harvest`, AJAX `anchor_preview_refresh` — used identically across tasks. ✓
- **Deviation from spec:** spec said the Preview settings live as a *section on the General tab*;
  plan registers a dedicated **Preview tab** instead (avoids entangling the General tab's single
  form/sanitize). Functionally equivalent, lower risk.
