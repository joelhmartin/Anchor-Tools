# Anchor Testimonials + Anchor Speakers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship two generic Anchor Tools modules (authored testimonials with audience + related-post scoping; speakers CPT with configurable URL base and Events linkage) plus the shared lightbox, carousel and video-URL primitives they reuse, as release 3.31.0.

**Architecture:** Extract the gallery module's private lightbox, card carousel and video-URL parser into plugin-level shared assets/classes, repoint the gallery at them, then build the two new CPT modules on top. Speakers hooks into Events through filters (one new filter added to Events), never by editing Events' behavior.

**Tech Stack:** PHP 8 WordPress plugin (no build step, source CSS/JS only), vanilla JS IIFEs, PHPUnit 9 on the WP test lib (`/tmp/wordpress-tests-lib`, MySQL at 127.0.0.1:3307 via docker `anchor-tools-mysql`), Playwright on wp-env.

**Spec:** `docs/superpowers/specs/2026-09-24-testimonials-and-speakers-design.md`

**Repo / branch:** worktree `/Users/bif/Developer/anchor-os/anchor-tools-wt-people`, branch `feat/testimonials-speakers` (already created from `main` 3.30.1). Run `composer install` once (done). PHPUnit: `vendor/bin/phpunit --filter <Class>` from the worktree root.

## Global Constraints

- Never use the em dash character anywhere (code, comments, strings, docs, commit messages). Use commas, colons, parentheses or a plain hyphen.
- Text domain `'anchor-schema'`. Options saved with `update_option( $k, $v, false )`.
- Asset URLs via `Anchor_Asset_Loader::url( 'path/from/plugin/root' )`, versions via `filemtime()` of the same path. Never commit `*.min.*`.
- Non-namespaced module classes `Anchor_{Name}_Module`; CPT `show_in_menu` via `apply_filters( 'anchor_{module}_parent_menu', true )`.
- JS: vanilla IIFE, no ES modules, no new jQuery dependency on the front end.
- Front-end markup is BEM with CSS custom properties and fallbacks; module CSS covers layout only, no brand colors.
- Existing public behavior of `anchor-gallery` (markup, classes `avg-*`, `[anchor_gallery]` output, lightbox behavior) must not change. `e2e/gallery-lightbox.spec.js` is the regression gate.
- Do not tag or merge. Release happens only after the user signs off (Task 12).

## Review Focus

1. Video URLs with extra params (`youtube.com/watch?v=ID&t=53s`, `youtu.be/ID?t=5`, `youtube.com/shorts/ID`, `vimeo.com/channels/staffpicks/123`, `player.vimeo.com/video/123?h=abc`) must normalize to the right ID. Pinned in Task 3.
2. `related="current"` on an Events **child occurrence** must match testimonials related to the **group parent**; on a normal page with no related testimonials the default `fallback="all"` must still show testimonials instead of an empty section. Pinned in Task 6.
3. Speakers base `about-us`: `/about-us/` (the page itself) and a non-speaker child page `/about-us/our-story/` must still resolve as pages; `/about-us/dr-x/` resolves the speaker. Pinned in Task 8.
4. Two galleries plus a testimonials slider on one page: one shared lightbox instance, arrow keys only act while it is open, Escape closes it, focus returns to the clicked tile. Pinned in Tasks 1 and 7 (e2e).
5. Occurrence regeneration (`Occurrences::reconcile`) must copy `_anchor_event_speaker_ids` to children and must not wipe a child's value when the parent has none set yet. Pinned in Task 10.

---

### Task 1: Shared lightbox (`assets/shared/anchor-lightbox.{js,css}`)

**Files:**
- Create: `assets/shared/anchor-lightbox.js`, `assets/shared/anchor-lightbox.css`
- Create: `includes/class-anchor-shared-assets.php`
- Modify: `anchor-tools.php` (require the new class next to the other `includes/` requires, around line 59-95)
- Modify: `anchor-gallery/assets/anchor-video-slider.js` (remove lines 4-47 URL builders and 113-300 lightbox, plus the lightbox parts of the keydown and `anchor-close-popups` handlers, lines ~517-585; call the shared API)
- Modify: `anchor-gallery/assets/anchor-video-slider.css` (move `.avg-modal*` rules, lines ~1049-1230, to the shared css)
- Modify: `anchor-gallery/anchor-gallery.php` (`enqueue_assets()`: add `anchor-lightbox` as a dependency of `anchor-video-gallery` script and style)
- Test: `tests/test-shared-assets.php`, existing `e2e/gallery-lightbox.spec.js`

**Interfaces:**
- Produces JS global `window.AnchorLightbox = { open(items, startIndex, opts), close(), isOpen(), getVideoSrc(provider, id, autoplay), getDirectUrl(provider, id) }`. `items` are `{ type: 'video'|'image'|'html', provider, videoId, url, fullUrl, alt, html, caption }` (exactly the shape `readTile()` produces today). `opts`: `{ autoplay, maxWidth, aspect, showCaption, origin }`.
- Produces PHP `Anchor_Shared_Assets::register()` hooked on `wp_enqueue_scripts` and `admin_enqueue_scripts` priority 5; registers handles `anchor-lightbox` (script + style) and `anchor-carousel` (Task 2). Modules enqueue by handle.
- Markup and class names stay `avg-modal*` so existing site CSS keeps working.

- [ ] **Step 1: Write the failing PHPUnit test**

```php
<?php
/** @group shared-assets */
class Test_Shared_Assets extends WP_UnitTestCase {
	public function test_lightbox_handles_are_registered() {
		do_action( 'wp_enqueue_scripts' );
		$this->assertArrayHasKey( 'anchor-lightbox', wp_scripts()->registered );
		$this->assertArrayHasKey( 'anchor-lightbox', wp_styles()->registered );
		$this->assertNotEmpty( wp_scripts()->registered['anchor-lightbox']->ver );
	}

	public function test_gallery_script_depends_on_lightbox() {
		do_action( 'wp_enqueue_scripts' );
		$gallery = wp_scripts()->registered['anchor-video-gallery'] ?? null;
		$this->assertNotNull( $gallery, 'gallery module must be enabled in tests/bootstrap.php' );
		$this->assertContains( 'anchor-lightbox', $gallery->deps );
	}
}
```

Also add `'video_slider' => true, 'testimonials' => true, 'speakers' => true` to the modules array in `tests/bootstrap.php` (the `update_option( 'anchor_schema_settings', ...)` call).

- [ ] **Step 2: Run it, expect FAIL** (`vendor/bin/phpunit --filter Test_Shared_Assets`, "Failed asserting that an array has the key 'anchor-lightbox'").

- [ ] **Step 3: Implement**

`includes/class-anchor-shared-assets.php`:

```php
<?php
/**
 * Plugin-level front-end primitives shared by several modules (one lightbox,
 * one carousel). Modules enqueue these by handle instead of shipping copies.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Shared_Assets {
	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register' ], 5 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'register' ], 5 );
	}

	public static function register() {
		foreach ( [ 'anchor-lightbox', 'anchor-carousel' ] as $handle ) {
			$js  = 'assets/shared/' . $handle . '.js';
			$css = 'assets/shared/' . $handle . '.css';
			if ( file_exists( ANCHOR_TOOLS_PLUGIN_DIR . $js ) ) {
				wp_register_script( $handle, Anchor_Asset_Loader::url( $js ), [], filemtime( ANCHOR_TOOLS_PLUGIN_DIR . $js ), true );
			}
			if ( file_exists( ANCHOR_TOOLS_PLUGIN_DIR . $css ) ) {
				wp_register_style( $handle, Anchor_Asset_Loader::url( $css ), [], filemtime( ANCHOR_TOOLS_PLUGIN_DIR . $css ) );
			}
		}
	}
}
Anchor_Shared_Assets::init();
```

Check how `Anchor_Asset_Loader::url()` resolves `.min` siblings; if versioning should follow the resolved file (see `tests/test-assets.php` for the events pattern), use the same helper the events module uses rather than raw `filemtime`.

`assets/shared/anchor-lightbox.js`: move, verbatim, `buildYouTubeSrc`, `buildVimeoSrc`, `getVideoSrc`, `getDirectUrl`, `lightboxModal`, `lbState`, `getLightboxModal`, `applyPopupOptions`, `updateLightboxNav`, `renderLightboxItem`, `openLightbox`, `closeLightbox` into a new IIFE. Add inside it the lightbox-only branches of the gallery's keydown handler (ArrowLeft/ArrowRight while open, Tab focus trap, Escape calls `closeLightbox()`) and the `anchor-close-popups` listener branch for the lightbox. End the IIFE with:

```js
  window.AnchorLightbox = {
    open: openLightbox,
    close: closeLightbox,
    isOpen: function () { return !!(lightboxModal && !lightboxModal.hidden); },
    getVideoSrc: getVideoSrc,
    getDirectUrl: getDirectUrl
  };
```

In `anchor-video-slider.js`, delete the moved code and add at the top of its IIFE:

```js
  var LB = window.AnchorLightbox;
  var getVideoSrc = LB.getVideoSrc;
  var getDirectUrl = LB.getDirectUrl;
  function openLightbox(seq, i, opts) { LB.open(seq, i, opts); }
  function closeLightbox() { LB.close(); }
```

Keep the gallery's keydown handler for theater/side-panel/inline/legacy modal only (its Escape branch no longer calls `closeLightbox`, the shared file does). Keep the gallery's `anchor-close-popups` listener for theater/side-panel/inline only.

Move the `.avg-modal*` CSS block and its media query to `assets/shared/anchor-lightbox.css` unchanged. In `anchor-gallery.php` `enqueue_assets()`, add `'anchor-lightbox'` to the deps arrays of the `anchor-video-gallery` script and style registrations.

- [ ] **Step 4: Run PHPUnit, expect PASS.** Then run the gallery lightbox E2E: `npm run wp-env start && npm run env:seed && npx playwright test e2e/gallery-lightbox.spec.js` and expect all pass (this is the no-regression proof).

- [ ] **Step 5: Commit** `git add -A && git commit -m "refactor(gallery): extract shared lightbox to assets/shared"` (with the Co-Authored-By trailer).

---

### Task 2: Shared carousel (`assets/shared/anchor-carousel.{js,css}`)

**Files:**
- Create: `assets/shared/anchor-carousel.js`, `assets/shared/anchor-carousel.css`
- Modify: `anchor-gallery/assets/anchor-video-slider.js` (`initSliderNavigation`, lines ~688-925, becomes a thin adapter)
- Modify: `anchor-gallery/anchor-gallery.php` (add `anchor-carousel` dep)
- Test: `tests/test-shared-assets.php` (extend), `e2e/shared-carousel.spec.js`

**Interfaces:**
- Produces `window.AnchorCarousel.init(root, cfg)` returning `{ go(index), next(), prev(), destroy() }`. `cfg`:
  `{ track, items (NodeList|Array), prev, next, dotsContainer, dotClass ('avg-dot'), mode ('carousel'|'slider'), loop (bool), center (bool), cols: {desktop, tablet, mobile}, slidesToScroll, gapVar ('--avg-gap'), autoplay (bool), autoplaySpeed (ms), pauseOnHover (bool) }`.
- Behavior is exactly today's `initSliderNavigation` (transform carousel with measured step, scroll-based slider mode, dots, swipe with 40px threshold, arrow keys on root, ResizeObserver re-measure, autoplay with pause) but reads elements from `cfg` instead of `avg-*` selectors. Adds: autoplay disabled when `prefers-reduced-motion: reduce`.

- [ ] **Step 1: Failing tests.** Add to `Test_Shared_Assets`:

```php
	public function test_carousel_handle_registered_and_gallery_depends_on_it() {
		do_action( 'wp_enqueue_scripts' );
		$this->assertArrayHasKey( 'anchor-carousel', wp_scripts()->registered );
		$this->assertContains( 'anchor-carousel', wp_scripts()->registered['anchor-video-gallery']->deps );
	}
```

Create `e2e/shared-carousel.spec.js` that loads a page seeded with a carousel-layout gallery (reuse the fixture `e2e/helpers` creates for `gallery-lightbox.spec.js`; if none uses the carousel layout, add one to `bin/e2e-seed.sh` with `layout=carousel`, 5 videos, `cols 3`):

```js
const { test, expect } = require('@playwright/test');
const seed = require('./.seed.json');

test('gallery carousel advances with next and dots via shared carousel', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(seed.galleryCarouselUrl);
  const track = page.locator('.avg-track').first();
  const before = await track.evaluate(el => getComputedStyle(el).transform);
  await page.locator('.avg-nav-next').first().click();
  await expect.poll(() => track.evaluate(el => getComputedStyle(el).transform)).not.toBe(before);
  await expect(page.locator('.avg-dot')).toHaveCount(5);
  await page.locator('.avg-dot').nth(0).click();
  await expect(page.locator('.avg-dot').nth(0)).toHaveClass(/active/);
  expect(await page.evaluate(() => typeof window.AnchorCarousel.init)).toBe('function');
});
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Move the body of `initSliderNavigation` into `anchor-carousel.js` as `init(root, cfg)`, replacing each `gallery.querySelector('.avg-…')` / attribute read with the matching `cfg` field and `getVisibleCount()` reading `cfg.cols`. The gallery adapter becomes:

```js
  function initSliderNavigation(gallery) {
    var layout = gallery.getAttribute('data-layout');
    if (layout !== 'slider' && layout !== 'carousel') return;
    var cols = parseInt(gallery.getAttribute('data-cols-desktop'), 10) || 3;
    window.AnchorCarousel.init(gallery, {
      track: gallery.querySelector('.avg-track'),
      items: gallery.querySelectorAll('.avg-tile'),
      prev: gallery.querySelector('.avg-nav-prev'),
      next: gallery.querySelector('.avg-nav-next'),
      dotsContainer: gallery.querySelector('.avg-dots'),
      dotClass: 'avg-dot',
      mode: layout,
      loop: gallery.getAttribute('data-loop') === '1',
      center: gallery.getAttribute('data-center') === '1',
      cols: {
        desktop: cols,
        tablet: parseInt(gallery.getAttribute('data-cols-tablet'), 10) || Math.min(cols, 2),
        mobile: parseInt(gallery.getAttribute('data-cols-mobile'), 10) || 1
      },
      slidesToScroll: Math.max(1, parseInt(gallery.getAttribute('data-slides-to-scroll'), 10) || 1),
      gapVar: '--avg-gap',
      autoplay: gallery.getAttribute('data-slider-autoplay') === '1',
      autoplaySpeed: parseInt(gallery.getAttribute('data-autoplay-speed'), 10) || 5000,
      pauseOnHover: gallery.getAttribute('data-pause-on-hover') !== '0'
    });
  }
```

`anchor-carousel.css` holds only generic rules needed by non-gallery consumers: `.anchor-carousel__viewport{overflow:hidden}` `.anchor-carousel__track{display:flex;gap:var(--anchor-carousel-gap,16px);transition:transform .45s ease}` `.anchor-carousel__track > *{flex:0 0 calc((100% - (var(--anchor-carousel-cols,3) - 1) * var(--anchor-carousel-gap,16px)) / var(--anchor-carousel-cols,3))}` with `@media (prefers-reduced-motion: reduce){.anchor-carousel__track{transition:none}}`. Gallery CSS is untouched.

- [ ] **Step 4: Run PHPUnit + `npx playwright test e2e/shared-carousel.spec.js e2e/gallery-lightbox.spec.js`, expect PASS.**
- [ ] **Step 5: Commit** `refactor(gallery): extract shared carousel to assets/shared`.

---

### Task 3: Shared video URL parser (`includes/class-anchor-video-url.php`)

**Files:**
- Create: `includes/class-anchor-video-url.php`; require it in `anchor-tools.php`
- Modify: `anchor-gallery/anchor-gallery.php` `normalize_video_url()` (line ~2866) delegates to it
- Test: `tests/test-video-url.php`

**Interfaces:**
- Produces `Anchor_Video_URL::parse( string $url ): ?array` returning `[ 'provider' => 'youtube'|'vimeo', 'id' => string, 'start' => int seconds ]` or `null`.
- Produces `Anchor_Video_URL::thumbnail( string $provider, string $id ): string` (YouTube `https://i.ytimg.com/vi/{id}/hqdefault.jpg`; Vimeo: cached oEmbed `thumbnail_url` in transient `anchor_vimeo_thumb_{id}` for 7 days, `''` on failure).

- [ ] **Step 1: Failing test**

```php
<?php
/** @group video-url */
class Test_Video_URL extends WP_UnitTestCase {
	/** @dataProvider urls */
	public function test_parse( $url, $provider, $id, $start ) {
		$r = Anchor_Video_URL::parse( $url );
		$this->assertSame( $provider, $r['provider'] );
		$this->assertSame( $id, $r['id'] );
		$this->assertSame( $start, $r['start'] );
	}
	public function urls() {
		return [
			[ 'https://www.youtube.com/watch?v=bBSSR2F69A0&t=53s', 'youtube', 'bBSSR2F69A0', 53 ],
			[ 'https://youtu.be/dwr8S2iOfs8?t=5', 'youtube', 'dwr8S2iOfs8', 5 ],
			[ 'https://www.youtube.com/embed/8I9btCPqwfI', 'youtube', '8I9btCPqwfI', 0 ],
			[ 'https://youtube.com/shorts/KzVacYOs3AI', 'youtube', 'KzVacYOs3AI', 0 ],
			[ 'https://www.youtube.com/watch?feature=share&v=ZXyik8HzGSM', 'youtube', 'ZXyik8HzGSM', 0 ],
			[ 'https://vimeo.com/123456789', 'vimeo', '123456789', 0 ],
			[ 'https://player.vimeo.com/video/123456789?h=abc123', 'vimeo', '123456789', 0 ],
			[ 'https://vimeo.com/channels/staffpicks/987654321', 'vimeo', '987654321', 0 ],
		];
	}
	public function test_non_video_returns_null() {
		$this->assertNull( Anchor_Video_URL::parse( 'https://example.com/watch?v=nope' ) );
	}
}
```

- [ ] **Step 2: Run, expect FAIL** (class not found).
- [ ] **Step 3: Implement**

```php
<?php
/** Parses YouTube/Vimeo URLs. Shared by gallery and testimonials. */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Video_URL {
	public static function parse( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' ) return null;
		$start = 0;
		$query = [];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		if ( isset( $query['t'] ) ) $start = self::seconds( $query['t'] );
		elseif ( isset( $query['start'] ) ) $start = (int) $query['start'];

		if ( preg_match( '~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:embed/|shorts/|live/|v/))([A-Za-z0-9_-]{6,})~', $url, $m ) ) {
			return [ 'provider' => 'youtube', 'id' => $m[1], 'start' => $start ];
		}
		if ( preg_match( '~youtube\.com/~', $url ) && ! empty( $query['v'] ) && preg_match( '~^[A-Za-z0-9_-]{6,}$~', $query['v'] ) ) {
			return [ 'provider' => 'youtube', 'id' => $query['v'], 'start' => $start ];
		}
		if ( preg_match( '~vimeo\.com/(?:.*/)?(?:video/)?([0-9]{6,})~', $url, $m ) ) {
			return [ 'provider' => 'vimeo', 'id' => $m[1], 'start' => $start ];
		}
		return null;
	}

	private static function seconds( $t ) {
		if ( is_numeric( $t ) ) return (int) $t;
		$s = 0;
		if ( preg_match( '~(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?~', (string) $t, $m ) ) {
			$s = ( (int) ( $m[1] ?? 0 ) ) * 3600 + ( (int) ( $m[2] ?? 0 ) ) * 60 + (int) ( $m[3] ?? 0 );
		}
		return $s;
	}

	public static function thumbnail( $provider, $id ) {
		if ( $provider === 'youtube' ) return 'https://i.ytimg.com/vi/' . rawurlencode( $id ) . '/hqdefault.jpg';
		if ( $provider !== 'vimeo' ) return '';
		$key = 'anchor_vimeo_thumb_' . $id;
		$cached = get_transient( $key );
		if ( $cached !== false ) return (string) $cached;
		$res = wp_remote_get( 'https://vimeo.com/api/oembed.json?url=' . rawurlencode( 'https://vimeo.com/' . $id ), [ 'timeout' => 5 ] );
		$thumb = '';
		if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			$thumb = isset( $body['thumbnail_url'] ) ? esc_url_raw( $body['thumbnail_url'] ) : '';
		}
		set_transient( $key, $thumb, $thumb ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );
		return $thumb;
	}
}
```

In the gallery's `normalize_video_url($url)`: call `Anchor_Video_URL::parse($url)`; if null return null; otherwise build the same array it builds today from `provider`/`id` (keeping `fallback_thumb`, `label`, `raw_url`, etc. identical).

- [ ] **Step 4: Run `--filter 'Test_Video_URL|Test_Backward_Compat'`, expect PASS.**
- [ ] **Step 5: Commit** `refactor: shared Anchor_Video_URL parser used by gallery`.

---

### Task 4: Testimonials module skeleton (CPT, taxonomy, registry)

**Files:**
- Create: `anchor-testimonials/anchor-testimonials.php`
- Modify: `anchor-tools.php` `anchor_tools_get_available_modules()` (add after `events_manager`)
- Test: `tests/test-testimonials-registration.php`

**Interfaces:**
- Produces class `Anchor_Testimonials_Module` with consts `CPT = 'anchor_testimonial'`, `TAX = 'anchor_testimonial_audience'`, `META_PREFIX = '_at_'`.
- Registry entry key `testimonials`.

- [ ] **Step 1: Failing test**

```php
<?php
/** @group testimonials */
class Test_Testimonials_Registration extends WP_UnitTestCase {
	public function test_cpt_and_tax_registered_private() {
		$this->assertTrue( post_type_exists( 'anchor_testimonial' ) );
		$pt = get_post_type_object( 'anchor_testimonial' );
		$this->assertFalse( $pt->public );
		$this->assertFalse( $pt->publicly_queryable );
		$this->assertTrue( $pt->show_ui );
		$this->assertTrue( taxonomy_exists( 'anchor_testimonial_audience' ) );
	}
	public function test_default_audience_terms_seeded() {
		do_action( 'init' );
		$this->assertNotFalse( term_exists( 'patient', 'anchor_testimonial_audience' ) );
		$this->assertNotFalse( term_exists( 'doctor', 'anchor_testimonial_audience' ) );
	}
	public function test_module_in_registry() {
		$mods = anchor_tools_get_available_modules();
		$this->assertArrayHasKey( 'testimonials', $mods );
		$this->assertSame( 'Anchor_Testimonials_Module', $mods['testimonials']['class'] );
	}
}
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Registry entry:

```php
            'testimonials' => [
                'label'       => __( 'Anchor Testimonials', 'anchor-schema' ),
                'description' => __( 'Authored patient and professional testimonials (quotes and videos) with audience and related-page scoping.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-testimonials/anchor-testimonials.php',
                'class'       => 'Anchor_Testimonials_Module',
            ],
```

Module file:

```php
<?php
/**
 * Anchor Tools module: Anchor Testimonials.
 * Authored testimonials (quote and/or video) with an audience taxonomy and a
 * "related to" list of post IDs used to scope them to courses, pages or speakers.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Testimonials_Module {
	const CPT         = 'anchor_testimonial';
	const TAX         = 'anchor_testimonial_audience';
	const META_PREFIX = '_at_';

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'init', [ $this, 'seed_terms' ], 20 );
	}

	public function register() {
		register_post_type( self::CPT, [
			'labels'             => [
				'name'          => __( 'Testimonials', 'anchor-schema' ),
				'singular_name' => __( 'Testimonial', 'anchor-schema' ),
				'add_new_item'  => __( 'Add New Testimonial', 'anchor-schema' ),
				'edit_item'     => __( 'Edit Testimonial', 'anchor-schema' ),
			],
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => apply_filters( 'anchor_testimonials_parent_menu', true ),
			'menu_icon'          => 'dashicons-format-quote',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
			'show_in_rest'       => false,
		] );
		register_taxonomy( self::TAX, self::CPT, [
			'labels'            => [ 'name' => __( 'Audiences', 'anchor-schema' ), 'singular_name' => __( 'Audience', 'anchor-schema' ) ],
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'hierarchical'      => false,
			'rewrite'           => false,
		] );
	}

	public function seed_terms() {
		if ( get_option( 'anchor_testimonials_seeded' ) ) return;
		foreach ( [ 'patient' => __( 'Patient', 'anchor-schema' ), 'doctor' => __( 'Doctor', 'anchor-schema' ) ] as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAX ) ) wp_insert_term( $name, self::TAX, [ 'slug' => $slug ] );
		}
		update_option( 'anchor_testimonials_seeded', 1, false );
	}
}
```

Note for the test: the seeded option persists across tests; `test_default_audience_terms_seeded` must `delete_option( 'anchor_testimonials_seeded' )` first, then call `( new Anchor_Testimonials_Module() )->seed_terms()` directly rather than re-firing `init`.

- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(testimonials): CPT, audience taxonomy, registry entry`.

---

### Task 5: Testimonial meta, admin metabox, related index

**Files:**
- Create: `anchor-testimonials/class-testimonial-meta.php`, `anchor-testimonials/assets/admin.js`, `anchor-testimonials/assets/admin.css`
- Modify: `anchor-testimonials/anchor-testimonials.php` (require + wire)
- Test: `tests/test-testimonials-meta.php`

**Interfaces:**
- Produces `Anchor_Testimonial_Meta::save( int $post_id, array $input ): void` (pure, used by `save_post` and by import scripts), and `Anchor_Testimonial_Meta::get( int $post_id ): array` returning `[ 'person_name', 'person_meta', 'video_url', 'video_provider', 'video_id', 'video_start', 'video_thumb', 'rating', 'featured', 'related' => int[] ]`.
- Storage: `_at_person_name`, `_at_person_meta`, `_at_video_url`, `_at_video_provider`, `_at_video_id`, `_at_video_start`, `_at_video_thumb`, `_at_rating` (0-5), `_at_featured` ('1' or deleted), `_at_related` (int[]), and one non-unique `_at_related_id` row per related ID.
- AJAX `wp_ajax_anchor_testimonials_search_posts` (cap `edit_posts`, nonce `anchor_testimonials_admin`) returns `[ { id, title, type_label } ]` for `post_type` in public types plus `event` and `anchor_speaker` when registered.

- [ ] **Step 1: Failing test**

```php
<?php
/** @group testimonials */
class Test_Testimonials_Meta extends WP_UnitTestCase {
	public function test_save_normalizes_video_and_indexes_related() {
		$id   = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial' ] );
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		Anchor_Testimonial_Meta::save( $id, [
			'person_name' => 'Jane Doe', 'person_meta' => 'Patient, Denver',
			'video_url'   => 'https://youtu.be/dwr8S2iOfs8?t=5',
			'rating' => 9, 'featured' => '1', 'related' => [ $page, $page, 'x' ],
		] );
		$m = Anchor_Testimonial_Meta::get( $id );
		$this->assertSame( 'youtube', $m['video_provider'] );
		$this->assertSame( 'dwr8S2iOfs8', $m['video_id'] );
		$this->assertSame( 5, $m['video_start'] );
		$this->assertSame( 5, $m['rating'] );
		$this->assertTrue( $m['featured'] );
		$this->assertSame( [ $page ], $m['related'] );
		$this->assertSame( [ (string) $page ], get_post_meta( $id, '_at_related_id' ) );
	}
	public function test_resave_replaces_related_rows_and_clears_video() {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial' ] );
		Anchor_Testimonial_Meta::save( $id, [ 'related' => [ 11, 12 ], 'video_url' => 'https://vimeo.com/123456789' ] );
		Anchor_Testimonial_Meta::save( $id, [ 'related' => [ 12 ], 'video_url' => '' ] );
		$this->assertSame( [ '12' ], get_post_meta( $id, '_at_related_id' ) );
		$this->assertSame( '', Anchor_Testimonial_Meta::get( $id )['video_id'] );
		$this->assertFalse( Anchor_Testimonial_Meta::get( $id )['featured'] );
	}
}
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** `class-testimonial-meta.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Testimonial_Meta {
	const P = '_at_';

	public static function save( $post_id, array $in ) {
		$post_id = (int) $post_id;
		update_post_meta( $post_id, self::P . 'person_name', sanitize_text_field( $in['person_name'] ?? '' ) );
		update_post_meta( $post_id, self::P . 'person_meta', sanitize_text_field( $in['person_meta'] ?? '' ) );

		$url = esc_url_raw( trim( (string) ( $in['video_url'] ?? '' ) ) );
		$v   = $url !== '' ? Anchor_Video_URL::parse( $url ) : null;
		update_post_meta( $post_id, self::P . 'video_url', $v ? $url : '' );
		update_post_meta( $post_id, self::P . 'video_provider', $v ? $v['provider'] : '' );
		update_post_meta( $post_id, self::P . 'video_id', $v ? $v['id'] : '' );
		update_post_meta( $post_id, self::P . 'video_start', $v ? (int) $v['start'] : 0 );
		update_post_meta( $post_id, self::P . 'video_thumb', $v ? Anchor_Video_URL::thumbnail( $v['provider'], $v['id'] ) : '' );

		update_post_meta( $post_id, self::P . 'rating', max( 0, min( 5, (int) ( $in['rating'] ?? 0 ) ) ) );
		if ( ! empty( $in['featured'] ) ) update_post_meta( $post_id, self::P . 'featured', '1' );
		else delete_post_meta( $post_id, self::P . 'featured' );

		$related = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $in['related'] ?? [] ) ) ) ) );
		update_post_meta( $post_id, self::P . 'related', $related );
		delete_post_meta( $post_id, self::P . 'related_id' );
		foreach ( $related as $rid ) add_post_meta( $post_id, self::P . 'related_id', (string) $rid );
	}

	public static function get( $post_id ) {
		$g = function ( $k ) use ( $post_id ) { return get_post_meta( $post_id, self::P . $k, true ); };
		$related = $g( 'related' );
		return [
			'person_name'    => (string) $g( 'person_name' ),
			'person_meta'    => (string) $g( 'person_meta' ),
			'video_url'      => (string) $g( 'video_url' ),
			'video_provider' => (string) $g( 'video_provider' ),
			'video_id'       => (string) $g( 'video_id' ),
			'video_start'    => (int) $g( 'video_start' ),
			'video_thumb'    => (string) $g( 'video_thumb' ),
			'rating'         => (int) $g( 'rating' ),
			'featured'       => $g( 'featured' ) === '1',
			'related'        => is_array( $related ) ? array_map( 'intval', $related ) : [],
		];
	}
}
```

Metabox (in the module class): `add_meta_boxes` registers "Testimonial details" on the CPT rendering person name, person meta, video URL (with a `<img>` preview filled by `admin.js` from `Anchor_Video_URL::thumbnail` via the existing value on load, and for YouTube computed client-side on input from the ID regex), rating select 0-5, featured checkbox, related chips + search input. `save_post_anchor_testimonial` handler: verify nonce `anchor_testimonials_meta`, `current_user_can( 'edit_post', $post_id )`, skip autosave/revisions, then `Anchor_Testimonial_Meta::save( $post_id, wp_unslash( $_POST['anchor_testimonial'] ?? [] ) )`. Admin notice on the edit screen when neither `post_content` nor video ID is set. `admin.js` (jQuery allowed in admin): debounced search to the AJAX action, click result adds a chip with hidden input `anchor_testimonial[related][]`, x removes it. Admin columns: thumbnail (photo or video thumb), person, featured, related count. Enqueue admin assets only when `get_current_screen()->post_type === self::CPT`.

- [ ] **Step 4: Run `--filter Test_Testimonials`, expect PASS.**
- [ ] **Step 5: Commit** `feat(testimonials): meta model, admin metabox, related index`.

---

### Task 6: Testimonials query builder

**Files:**
- Create: `anchor-testimonials/class-testimonial-query.php`
- Test: `tests/test-testimonials-query.php`

**Interfaces:**
- Produces `Anchor_Testimonial_Query::resolve_related( string $related, int $current_id ): int[]` and `Anchor_Testimonial_Query::find( array $atts, int $current_id = 0 ): WP_Post[]`. `$atts` keys exactly as the shortcode (spec table): `audience, related, type, featured, limit, orderby, fallback`.
- Events group parent lookup: `(int) get_post_meta( $id, '_anchor_event_group_id', true )` when `get_post_meta( $id, '_anchor_event_group_role', true ) === 'child'`. Verify the exact meta values against `anchor-events-manager/class-occurrences.php` before coding (grep `_anchor_event_group_role`); if the parent role value or key differs, use what the code writes.

- [ ] **Step 1: Failing tests**

```php
<?php
/** @group testimonials */
class Test_Testimonials_Query extends WP_UnitTestCase {
	private function t( $aud, $related = [], $extra = [] ) {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial', 'post_content' => $extra['content'] ?? 'Great.' ] );
		wp_set_object_terms( $id, $aud, 'anchor_testimonial_audience' );
		Anchor_Testimonial_Meta::save( $id, array_merge( [ 'related' => $related ], $extra ) );
		return $id;
	}
	public function test_audience_filter() {
		$p = $this->t( 'patient' ); $d = $this->t( 'doctor' );
		$ids = wp_list_pluck( Anchor_Testimonial_Query::find( [ 'audience' => 'doctor' ] ), 'ID' );
		$this->assertSame( [ $d ], $ids );
	}
	public function test_related_current_on_occurrence_matches_group_parent() {
		$parent = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$child  = self::factory()->post->create( [ 'post_type' => 'post' ] );
		update_post_meta( $child, '_anchor_event_group_role', 'child' );
		update_post_meta( $child, '_anchor_event_group_id', $parent );
		$hit  = $this->t( 'doctor', [ $parent ] );
		$this->t( 'doctor', [ 999999 ] );
		$ids = wp_list_pluck( Anchor_Testimonial_Query::find( [ 'related' => 'current', 'fallback' => 'none' ], $child ), 'ID' );
		$this->assertSame( [ $hit ], $ids );
	}
	public function test_fallback_all_when_nothing_related() {
		$a = $this->t( 'patient' );
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$ids = wp_list_pluck( Anchor_Testimonial_Query::find( [ 'related' => 'current' ], $page ), 'ID' );
		$this->assertSame( [ $a ], $ids );
	}
	public function test_type_video_and_featured() {
		$v = $this->t( 'patient', [], [ 'video_url' => 'https://youtu.be/dwr8S2iOfs8', 'featured' => '1' ] );
		$this->t( 'patient', [], [ 'featured' => '1' ] );
		$ids = wp_list_pluck( Anchor_Testimonial_Query::find( [ 'type' => 'video', 'featured' => '1' ] ), 'ID' );
		$this->assertSame( [ $v ], $ids );
	}
}
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Testimonial_Query {
	public static function defaults() {
		return [ 'audience' => '', 'related' => '', 'type' => 'any', 'featured' => '', 'limit' => 12, 'orderby' => 'menu_order', 'fallback' => 'all' ];
	}

	public static function resolve_related( $related, $current_id ) {
		$related = trim( (string) $related );
		if ( $related === '' ) return [];
		$ids = [];
		foreach ( array_map( 'trim', explode( ',', $related ) ) as $part ) {
			if ( $part === 'current' ) {
				if ( $current_id ) {
					$ids[] = (int) $current_id;
					if ( get_post_meta( $current_id, '_anchor_event_group_role', true ) === 'child' ) {
						$parent = (int) get_post_meta( $current_id, '_anchor_event_group_id', true );
						if ( $parent ) $ids[] = $parent;
					}
				}
			} elseif ( ctype_digit( $part ) ) {
				$ids[] = (int) $part;
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public static function find( array $atts, $current_id = 0 ) {
		$a = wp_parse_args( $atts, self::defaults() );
		$args = [
			'post_type'      => Anchor_Testimonials_Module::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 100, (int) $a['limit'] ) ),
			'no_found_rows'  => true,
			'meta_query'     => [],
			'tax_query'      => [],
		];
		switch ( $a['orderby'] ) {
			case 'date': $args['orderby'] = 'date'; $args['order'] = 'DESC'; break;
			case 'rand': $args['orderby'] = 'rand'; break;
			default:     $args['orderby'] = [ 'menu_order' => 'ASC', 'date' => 'DESC' ];
		}
		if ( $a['audience'] !== '' ) {
			$args['tax_query'][] = [ 'taxonomy' => Anchor_Testimonials_Module::TAX, 'field' => 'slug', 'terms' => array_map( 'sanitize_title', explode( ',', $a['audience'] ) ) ];
		}
		if ( (string) $a['featured'] === '1' ) {
			$args['meta_query'][] = [ 'key' => '_at_featured', 'value' => '1' ];
		}
		if ( $a['type'] === 'video' ) {
			$args['meta_query'][] = [ 'key' => '_at_video_id', 'value' => '', 'compare' => '!=' ];
		} elseif ( $a['type'] === 'quote' ) {
			$args['meta_query'][] = [ 'relation' => 'OR', [ 'key' => '_at_video_id', 'value' => '' ], [ 'key' => '_at_video_id', 'compare' => 'NOT EXISTS' ] ];
		}

		$related = self::resolve_related( $a['related'], (int) $current_id );
		if ( $related ) {
			$scoped = $args;
			$scoped['meta_query'][] = [ 'key' => '_at_related_id', 'value' => array_map( 'strval', $related ), 'compare' => 'IN' ];
			$posts = get_posts( $scoped );
			if ( $posts || $a['fallback'] !== 'all' ) return $posts;
		} elseif ( $a['related'] !== '' && $a['fallback'] !== 'all' ) {
			return [];
		}
		return get_posts( $args );
	}
}
```

Note: `get_posts` sets `suppress_filters` true; fine. With the `IN` meta query a testimonial related to two matched IDs could appear twice, so pass `'distinct'` by adding `add_filter( 'posts_distinct', ... )` or simply `array_unique` on IDs after the query. Implement the latter: `$posts = array_values( array_intersect_key( $posts, array_unique( wp_list_pluck( $posts, 'ID' ) ) ) );`.

- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(testimonials): query builder with related scoping and fallback`.

---

### Task 7: Testimonials renderer, shortcode and front-end assets

**Files:**
- Create: `anchor-testimonials/class-testimonial-render.php`, `anchor-testimonials/assets/testimonials.css`, `anchor-testimonials/assets/testimonials.js`
- Modify: `anchor-testimonials/anchor-testimonials.php` (shortcode + enqueue)
- Test: `tests/test-testimonials-render.php`, `e2e/testimonials.spec.js` (+ seed data in `bin/e2e-seed.sh`)

**Interfaces:**
- Shortcode `[anchor_testimonials]` with the spec's attributes plus `columns` (1-4, default 3).
- `Anchor_Testimonial_Render::html( WP_Post[] $posts, array $atts ): string`.
- Markup contract (themes style against this):

```html
<div class="anchor-testimonials anchor-testimonials--grid" style="--at-cols:3" data-layout="grid">
  <div class="anchor-testimonials__viewport"><div class="anchor-testimonials__track">
    <figure class="anchor-testimonial anchor-testimonial--video">
      <button type="button" class="anchor-testimonial__media" data-provider="youtube" data-video-id="ID" data-start="0" aria-label="Play video: Jane Doe">
        <img src="thumb" alt="" loading="lazy"><span class="anchor-testimonial__play" aria-hidden="true"></span>
      </button>
      <blockquote class="anchor-testimonial__quote"><p>...</p></blockquote>
      <figcaption class="anchor-testimonial__person">
        <img class="anchor-testimonial__photo" ...><span class="anchor-testimonial__name">Jane Doe</span><span class="anchor-testimonial__meta">Patient, Denver</span>
        <span class="anchor-testimonial__rating" aria-label="5 out of 5">★★★★★</span>
      </figcaption>
    </figure>
  </div></div>
  <div class="anchor-testimonials__controls"> (slider only) prev / dots / next buttons </div>
</div>
```

- `testimonials.js`: on DOMContentLoaded, for each `.anchor-testimonials`: media buttons open `AnchorLightbox.open(items, index, { autoplay: true, origin: button })` where `items` are all video tiles in that block (`{type:'video', provider, videoId, caption: name}`); if `data-layout="slider"`, call `AnchorCarousel.init(root, { track, items: track.children, prev, next, dotsContainer, dotClass: 'anchor-testimonials__dot', mode: 'carousel', loop: true, cols: {desktop: n, tablet: min(n,2), mobile: 1}, gapVar: '--at-gap' })`.
- Script handle `anchor-testimonials` depends on `anchor-lightbox` and `anchor-carousel`; style depends on `anchor-lightbox` and `anchor-carousel`. Enqueued only when the shortcode renders.

- [ ] **Step 1: Failing tests**

```php
<?php
/** @group testimonials */
class Test_Testimonials_Render extends WP_UnitTestCase {
	public function test_grid_markup_escapes_and_has_video_button() {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial', 'post_content' => 'Life <b>changing</b><script>x</script>' ] );
		Anchor_Testimonial_Meta::save( $id, [ 'person_name' => 'Jane "J" Doe', 'video_url' => 'https://youtu.be/dwr8S2iOfs8' ] );
		$html = do_shortcode( '[anchor_testimonials layout="grid"]' );
		$this->assertStringContainsString( 'anchor-testimonials--grid', $html );
		$this->assertStringContainsString( 'data-video-id="dwr8S2iOfs8"', $html );
		$this->assertStringContainsString( 'Jane &quot;J&quot; Doe', $html );
		$this->assertStringNotContainsString( '<script>x</script>', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertTrue( wp_script_is( 'anchor-testimonials', 'enqueued' ) );
	}
	public function test_slider_has_controls_and_empty_returns_nothing() {
		$this->assertSame( '', do_shortcode( '[anchor_testimonials audience="nobody"]' ) );
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial', 'post_content' => 'Q' ] );
		$html = do_shortcode( '[anchor_testimonials layout="slider" columns="2"]' );
		$this->assertStringContainsString( 'anchor-testimonials__controls', $html );
		$this->assertStringContainsString( '--at-cols:2', $html );
	}
}
```

`e2e/testimonials.spec.js`: seed a page with `[anchor_testimonials layout="slider"]` and 4 testimonials (2 with YouTube URLs). Test: clicking the first `.anchor-testimonial__media` shows `.avg-modal` with an iframe whose `src` contains the video ID; Escape hides it and focus returns to the button; next button changes track transform.

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the renderer with `esc_html`, `esc_attr`, `esc_url`, quote body via `wp_kses_post( wpautop( $post->post_content ) )`, photo via `get_the_post_thumbnail( $id, 'thumbnail', [ 'class' => 'anchor-testimonial__photo', 'loading' => 'lazy' ] )`. Modifier classes `--video` / `--quote`. Slider controls: `<button class="anchor-testimonials__prev" aria-label="Previous">`, `<div class="anchor-testimonials__dots"></div>`, `<button class="anchor-testimonials__next" aria-label="Next">`. Grid/slider share the same card markup; `video-grid` renders only media + name. CSS: layout only, using `--at-cols`, `--at-gap` (default 24px), `--at-card-bg` (default `#fff`), `--at-card-radius` (12px), `--at-accent` (currentColor) for play icon and rating; grid is `display:grid;grid-template-columns:repeat(var(--at-cols),minmax(0,1fr))`, collapsing to 2 columns at <=1023px and 1 at <=767px; slider reuses `.anchor-carousel__track` rules by adding that class to the track as well. Play button: `aspect-ratio:16/9`, poster `object-fit:cover`.
- [ ] **Step 4: Run PHPUnit + `npx playwright test e2e/testimonials.spec.js`, expect PASS.**
- [ ] **Step 5: Commit** `feat(testimonials): shortcode renderer with grid, slider, video grid`.

---

### Task 8: Speakers module: CPT, configurable base, page-collision fallback

**Files:**
- Create: `anchor-speakers/anchor-speakers.php`
- Modify: `anchor-tools.php` (registry entry `speakers`)
- Test: `tests/test-speakers-rewrite.php`

**Interfaces:**
- Produces `Anchor_Speakers_Module` with `CPT = 'anchor_speaker'`, `OPTION = 'anchor_speakers_options'` (`[ 'base' => 'speakers', 'archive' => false ]`), `static base(): string`.
- Settings page under Settings > Anchor Speakers (fields: URL base, enable archive). On save, `flush_rewrite_rules()` once (via an option flag checked on next `init`).
- `request` filter fallback as specified.

- [ ] **Step 1: Failing tests**

```php
<?php
/** @group speakers */
class Test_Speakers_Rewrite extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		update_option( 'anchor_speakers_options', [ 'base' => 'about-us', 'archive' => false ], false );
		$this->set_permalink_structure( '/%postname%/' );
		( new Anchor_Speakers_Module() )->register();
		flush_rewrite_rules();
	}
	public function test_speaker_resolves_under_custom_base() {
		$s = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_name' => 'dr-jane-smith', 'post_title' => 'Dr. Jane Smith' ] );
		$this->assertSame( home_url( '/about-us/dr-jane-smith/' ), get_permalink( $s ) );
		$this->go_to( '/about-us/dr-jane-smith/' );
		$this->assertTrue( is_singular( 'anchor_speaker' ) );
		$this->assertSame( $s, get_queried_object_id() );
	}
	public function test_parent_page_and_non_speaker_child_page_still_resolve() {
		$about = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about-us' ] );
		$child = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'our-story', 'post_parent' => $about ] );
		$this->go_to( '/about-us/' );
		$this->assertSame( $about, get_queried_object_id() );
		$this->go_to( '/about-us/our-story/' );
		$this->assertTrue( is_page() );
		$this->assertSame( $child, get_queried_object_id() );
	}
	public function test_default_base_is_speakers() {
		delete_option( 'anchor_speakers_options' );
		$this->assertSame( 'speakers', Anchor_Speakers_Module::base() );
	}
}
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement**

```php
<?php
/**
 * Anchor Tools module: Anchor Speakers.
 * Speaker/faculty CPT with a configurable URL base and optional Events linkage.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Speakers_Module {
	const CPT    = 'anchor_speaker';
	const OPTION = 'anchor_speakers_options';

	public static function options() {
		$o = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $o ) ? $o : [], [ 'base' => 'speakers', 'archive' => false ] );
	}

	public static function base() {
		$b = trim( sanitize_title_with_dashes( (string) self::options()['base'] ), '/' );
		return $b !== '' ? $b : 'speakers';
	}

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'init', [ $this, 'maybe_flush' ], 99 );
		add_filter( 'request', [ $this, 'page_fallback' ] );
		add_action( 'admin_menu', [ $this, 'settings_menu' ] );
		add_action( 'admin_init', [ $this, 'settings_register' ] );
	}

	public function register() {
		register_post_type( self::CPT, [
			'labels'       => [ 'name' => __( 'Speakers', 'anchor-schema' ), 'singular_name' => __( 'Speaker', 'anchor-schema' ), 'add_new_item' => __( 'Add New Speaker', 'anchor-schema' ), 'edit_item' => __( 'Edit Speaker', 'anchor-schema' ) ],
			'public'       => true,
			'show_in_menu' => apply_filters( 'anchor_speakers_parent_menu', true ),
			'menu_icon'    => 'dashicons-businessperson',
			'supports'     => [ 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions' ],
			'has_archive'  => (bool) self::options()['archive'],
			'rewrite'      => [ 'slug' => self::base(), 'with_front' => false ],
			'show_in_rest' => true,
		] );
	}

	/**
	 * When the base equals a real page path, a request for base/slug that is not
	 * a speaker falls back to the child page of that name.
	 */
	public function page_fallback( $vars ) {
		if ( empty( $vars['post_type'] ) || $vars['post_type'] !== self::CPT || empty( $vars[ self::CPT ] ) ) return $vars;
		$slug = $vars[ self::CPT ];
		if ( get_page_by_path( $slug, OBJECT, self::CPT ) ) return $vars;
		$page = get_page_by_path( self::base() . '/' . $slug );
		if ( ! $page ) return $vars;
		return [ 'pagename' => self::base() . '/' . $slug ];
	}

	public function maybe_flush() {
		if ( get_option( 'anchor_speakers_flush' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'anchor_speakers_flush' );
		}
	}

	public function settings_menu() {
		add_options_page( __( 'Anchor Speakers', 'anchor-schema' ), __( 'Anchor Speakers', 'anchor-schema' ), 'manage_options', 'anchor-speakers', [ $this, 'settings_page' ] );
	}

	public function settings_register() {
		register_setting( 'anchor_speakers', self::OPTION, [ 'sanitize_callback' => [ $this, 'sanitize' ] ] );
	}

	public function sanitize( $in ) {
		update_option( 'anchor_speakers_flush', 1, false );
		return [ 'base' => sanitize_title_with_dashes( $in['base'] ?? 'speakers' ) ?: 'speakers', 'archive' => ! empty( $in['archive'] ) ];
	}

	public function settings_page() {
		$o = self::options();
		echo '<div class="wrap"><h1>' . esc_html__( 'Anchor Speakers', 'anchor-schema' ) . '</h1><form method="post" action="options.php">';
		settings_fields( 'anchor_speakers' );
		echo '<table class="form-table"><tr><th><label for="as-base">' . esc_html__( 'URL base', 'anchor-schema' ) . '</label></th><td><code>' . esc_html( home_url( '/' ) ) . '</code><input id="as-base" name="' . esc_attr( self::OPTION ) . '[base]" value="' . esc_attr( $o['base'] ) . '" class="regular-text"><code>/speaker-name/</code><p class="description">' . esc_html__( 'May match an existing page path (for example about-us); child pages that are not speakers keep working.', 'anchor-schema' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Archive page', 'anchor-schema' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[archive]" value="1" ' . checked( $o['archive'], true, false ) . '> ' . esc_html__( 'Enable an archive at the base URL', 'anchor-schema' ) . '</label></td></tr></table>';
		submit_button();
		echo '</form></div>';
	}
}
```

Note: `update_option` with autoload false for the options is done by `register_setting`'s save path; after `sanitize`, also call `wp_set_option_autoload( self::OPTION, false )` if available (WP 6.4+), guarded with `function_exists`.

- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(speakers): CPT with configurable base and page fallback`.

---

### Task 9: Speaker meta, shortcode, single fallback template

**Files:**
- Create: `anchor-speakers/class-speaker-meta.php`, `anchor-speakers/class-speaker-render.php`, `anchor-speakers/templates/single-anchor_speaker.php`, `anchor-speakers/assets/speakers.css`
- Test: `tests/test-speakers-render.php`

**Interfaces:**
- `Anchor_Speaker_Meta::save( int $id, array $in )` / `get( int $id ): array` with keys `credentials, title, location, featured (bool), links (array of ['label','url'])`, stored `_as_credentials`, `_as_title`, `_as_location`, `_as_featured` ('1' or deleted), `_as_links`.
- `Anchor_Speakers_Module::event_speaker_ids( int $event_id ): int[]` (added in Task 10; Task 9's `event` attribute calls it only if `method_exists`).
- Shortcode `[anchor_speakers featured ids event layout columns limit link show]`, markup:

```html
<div class="anchor-speakers anchor-speakers--grid" style="--as-cols:3">
  <article class="anchor-speaker">
    <a class="anchor-speaker__photo" href="permalink"><img ...></a>
    <h3 class="anchor-speaker__name"><a href="permalink">Dr. Steven Olmos</a></h3>
    <p class="anchor-speaker__credentials">DDS, DABCP</p>
    <p class="anchor-speaker__title">Founder</p>
    <p class="anchor-speaker__excerpt">...</p>
    <a class="anchor-speaker__link" href="permalink">View profile</a>
  </article>
</div>
```

- `show` default `credentials,title,excerpt`; `link="0"` removes anchors. Ordering: `ids` order when given, event order when `event`, else `menu_order ASC, title ASC`.
- `single_template` filter: if the theme has no `single-anchor_speaker.php` (`locate_template` empty), use the plugin template, which calls `get_header()`, renders photo, name, credentials, title, content, then `do_shortcode( '[anchor_testimonials related="current" fallback="none"]' )` only if the testimonials module class exists, then `get_footer()`.

- [ ] **Step 1: Failing tests**

```php
<?php
/** @group speakers */
class Test_Speakers_Render extends WP_UnitTestCase {
	public function test_featured_filter_and_order() {
		$a = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. B', 'menu_order' => 2 ] );
		$b = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. A', 'menu_order' => 1 ] );
		$c = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. C' ] );
		Anchor_Speaker_Meta::save( $a, [ 'featured' => '1', 'credentials' => 'DDS' ] );
		Anchor_Speaker_Meta::save( $b, [ 'featured' => '1' ] );
		$html = do_shortcode( '[anchor_speakers featured="1"]' );
		$this->assertLessThan( strpos( $html, 'Dr. B' ), strpos( $html, 'Dr. A' ) );
		$this->assertStringNotContainsString( 'Dr. C', $html );
		$this->assertStringContainsString( 'anchor-speaker__credentials">DDS', $html );
	}
	public function test_ids_order_and_link_off() {
		$a = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'One' ] );
		$b = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Two' ] );
		$html = do_shortcode( "[anchor_speakers ids=\"$b,$a\" link=\"0\"]" );
		$this->assertLessThan( strpos( $html, 'One' ), strpos( $html, 'Two' ) );
		$this->assertStringNotContainsString( '<a ', $html );
	}
}
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** (meta class mirrors `Anchor_Testimonial_Meta` style: `sanitize_text_field` for strings, `esc_url_raw` for link URLs; metabox on the speaker edit screen with those fields and a nonce; renderer escapes every field; CSS layout only with `--as-cols`, `--as-gap`, `--as-photo-radius`).
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(speakers): meta, shortcode, fallback single template`.

---

### Task 10: Speakers and Events integration

**Files:**
- Modify: `anchor-events-manager/class-occurrences.php` (every `self::INHERITED_KEYS` read goes through a new `self::inherited_keys()`)
- Create: `anchor-speakers/class-speaker-events.php`
- Modify: `anchor-events-manager/EVENTS.md` (document the new filter in its hooks section)
- Test: `tests/test-speakers-events.php`

**Interfaces:**
- New Events filter: `apply_filters( 'anchor_events_inherited_keys', self::INHERITED_KEYS )` inside `private static function inherited_keys(): array` in `Occurrences`.
- Event meta `_anchor_event_speaker_ids` (int[] ordered).
- `Anchor_Speakers_Module::event_speaker_ids( int $event_id ): int[]` resolving child to group parent when the child's own list is empty.
- `anchor_events_schema_node` filter adds `performer`.

- [ ] **Step 1: Failing tests** (extend `Anchor_Events_TestCase` so the events module is booted; skip if `Anchor\Events\Module` missing)

```php
<?php
/** @group speakers */
class Test_Speakers_Events extends Anchor_Events_TestCase {
	public function test_inherited_keys_filter_includes_speakers() {
		$keys = apply_filters( 'anchor_events_inherited_keys', \Anchor\Events\Occurrences::INHERITED_KEYS );
		$this->assertContains( '_anchor_event_speaker_ids', $keys );
	}
	public function test_schema_performer_added() {
		$s  = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. Steven Olmos', 'post_status' => 'publish' ] );
		Anchor_Speaker_Meta::save( $s, [ 'title' => 'Founder' ] );
		$ev = self::factory()->post->create( [ 'post_type' => 'event' ] );
		update_post_meta( $ev, '_anchor_event_speaker_ids', [ $s ] );
		$node = apply_filters( 'anchor_events_schema_node', [ '@type' => 'Event' ], $ev );
		$this->assertSame( 'Person', $node['performer'][0]['@type'] );
		$this->assertSame( 'Dr. Steven Olmos', $node['performer'][0]['name'] );
		$this->assertSame( 'Founder', $node['performer'][0]['jobTitle'] );
	}
	public function test_child_without_own_list_uses_parent() {
		$s = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_status' => 'publish' ] );
		$parent = self::factory()->post->create( [ 'post_type' => 'event' ] );
		$child  = self::factory()->post->create( [ 'post_type' => 'event' ] );
		update_post_meta( $parent, '_anchor_event_speaker_ids', [ $s ] );
		update_post_meta( $child, '_anchor_event_group_role', 'child' );
		update_post_meta( $child, '_anchor_event_group_id', $parent );
		$this->assertSame( [ $s ], Anchor_Speakers_Module::event_speaker_ids( $child ) );
	}
}
```

Also add a reconcile test: create an `offering` parent with two dates using the helpers already in `tests/test-inheritance.php` (copy its setup), set `_anchor_event_speaker_ids` on the parent, run reconcile, assert both children carry the same array; then set a child-specific value, run reconcile with the parent value deleted, assert the child keeps its own value only if the existing inheritance code preserves per-child values for other inherited keys (match whatever `test-inheritance.php` asserts for existing keys; the new key must behave identically to them).

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** In `class-occurrences.php`, add

```php
	/** INHERITED_KEYS, extendable by other modules (e.g. Anchor Speakers). */
	private static function inherited_keys() {
		$keys = (array) \apply_filters( 'anchor_events_inherited_keys', self::INHERITED_KEYS );
		return array_values( array_unique( array_filter( $keys, 'is_string' ) ) );
	}
```

and replace each `self::INHERITED_KEYS` usage (line ~1501 and any others found by grep) with `self::inherited_keys()`. In `class-speaker-events.php` (loaded by the speakers module only when `class_exists( '\\Anchor\\Events\\Module' )`): add the inherited key, add the "Speakers" metabox on `event` (ordered select of published speakers with add/remove/up/down, jQuery admin JS allowed, nonce), save to `_anchor_event_speaker_ids`, add the `performer` schema filter (`[ '@type' => 'Person', 'name', 'url' => get_permalink, 'image' => get_the_post_thumbnail_url( $id, 'large' ) ?: omitted, 'jobTitle' => title meta or omitted ]`, appended to any existing `performer`), and `event_speaker_ids()`.
- [ ] **Step 4: Run the full events suite (`vendor/bin/phpunit`), expect all PASS** (the inherited-keys refactor must not change existing results).
- [ ] **Step 5: Commit** `feat(speakers): link speakers to events, inherit to occurrences, schema performer`.

---

### Task 11: Docs and full verification

**Files:**
- Modify: `CLAUDE.md` (module table: add `testimonials` / `Anchor_Testimonials_Module` / CPT and `speakers` / `Anchor_Speakers_Module` / CPT; mention `assets/shared/` and `Anchor_Video_URL` in Conventions as the place for shared front-end primitives)
- Create: `anchor-testimonials/README.md`, `anchor-speakers/README.md` (shortcode attribute tables copied from the spec, the markup contract, the CSS custom properties)

- [ ] **Step 1:** `vendor/bin/phpunit` full suite: PASS. `npx playwright test`: PASS.
- [ ] **Step 2:** `grep -rnP '\x{2014}' anchor-testimonials anchor-speakers assets/shared includes/class-anchor-video-url.php includes/class-anchor-shared-assets.php docs/superpowers/plans/2026-09-24-testimonials-and-speakers.md` returns nothing.
- [ ] **Step 3:** Commit `docs: testimonials and speakers module docs`.

---

### Task 12: PR, review, release (controller does this, not a subagent)

- [ ] `git diff --name-only main...HEAD | wc -l` (must be well under 150).
- [ ] `git push origin feat/testimonials-speakers`; open PR against `main` (not draft); verify CodeRabbit actually posts inline findings (not only the walkthrough); address findings.
- [ ] Opus whole-branch review.
- [ ] **Stop for explicit user sign-off.** Then merge to `main`, bump `Version:` in `anchor-tools.php` to `3.31.0` on `main`, `git push origin main`, `git tag 3.31.0 && git push origin 3.31.0`, confirm the Release workflow publishes both ZIPs.
