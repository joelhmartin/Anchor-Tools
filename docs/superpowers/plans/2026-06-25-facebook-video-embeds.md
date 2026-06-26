# Facebook Video Embeds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Facebook videos and reels as a supported video source in both the video gallery/slider (`anchor-gallery`) and the video pop-up (`anchor-universal-popups`), alongside YouTube and Vimeo.

**Architecture:** Add a `facebook` provider to each module's existing three-stage pipeline (parse URL → resolve thumbnail → build iframe). Facebook needs the *full URL* (its embed plugin takes `href`, not an ID), so we store the canonical URL where YouTube/Vimeo store an `id`. Thumbnails are manual (media library) because Facebook offers no public thumbnail; orientation (landscape / vertical / square) is selectable per item because reels are vertical. The two modules are edited independently — this mirrors their existing deliberate code duplication.

**Tech Stack:** Raw PHP + jQuery/vanilla JS, WordPress plugin. No build tools, no transpilation.

## Global Constraints

- **No automated test suite exists** (per CLAUDE.md). "Verify" steps are `php -l` syntax checks (which we *can* run) plus explicit manual checks in a WordPress environment. There is no `pytest`/`jest` to run.
- Text domain for translatable strings: `'anchor-schema'`.
- Asset URLs: `ANCHOR_TOOLS_PLUGIN_URL . 'anchor-{module}/assets/'`.
- `update_option()` always passes `autoload=false` as the 3rd arg (not relevant here — no new options).
- JS is jQuery IIFE / vanilla `(function(){…})()` — no ES modules.
- When editing an asset, **bump the `wp_enqueue_*` version string** for that file and keep the readable `.js` and the `.min.js` copies in sync (no minifier is run; mirror the change into both).
- Facebook embed src form: `https://www.facebook.com/plugins/video.php?href={url-encoded canonical url}&show_text=false&autoplay={true|false}`; append `mute=1` whenever autoplay is on (Facebook only autoplays muted).
- Aspect values are stored as ratio strings (`16:9`, `9:16`, `1:1`) to match the existing aspect handling (`opts.aspect.replace(':', ' / ')`). An empty value means "inherit the gallery/popup-level aspect".

---

## File Structure

**Module A — `anchor-gallery`:**
- `anchor-gallery/anchor-gallery.php` — `normalize_video_url()` (add Facebook branch), `parse_videos_from_rows()` (carry `aspect`), tile render (emit `data-aspect`), inspector template (Orientation field), enqueue version bump.
- `anchor-gallery/assets/anchor-video-slider.js` + `.min.js` — `buildFacebookSrc`, `getVideoSrc`/`getDirectUrl` branches, click-handler per-item aspect, preload skip + preconnect.
- `anchor-gallery/assets/admin.js` + `.min.js` — per-item `aspect` field plumbing, bulk-import Facebook URL detection.

**Module B — `anchor-universal-popups`:**
- `anchor-universal-popups/anchor-universal-popups.php` — `parse_video_url()` (add Facebook branch), build-items meta block (Facebook thumbnail = manual), URL field placeholder.
- `anchor-universal-popups/assets/frontend.js` + `.min.js` — `buildFacebookSrc`, `getVideoSrc` dispatcher, Facebook open path (no preload/postMessage), pass `video_url` for Facebook.

---

## Task 1: Gallery — Facebook URL parsing + per-item aspect passthrough (PHP)

**Files:**
- Modify: `anchor-gallery/anchor-gallery.php` — `normalize_video_url()` (~line 2847), `parse_videos_from_rows()` `$extras` (~line 2781), tile render (~line 2247-2251).

**Interfaces:**
- Produces: `normalize_video_url($url)` now returns `['provider'=>'facebook','id'=>$canonical_url,'thumb'=>'','fallback_thumb'=>'','label'=>'Facebook Video','raw_url'=>$canonical_url,'duration'=>'','channel'=>'']` for Facebook URLs. Each parsed video row carries an `aspect` key (ratio string or `''`). Tiles emit `data-aspect` consumed by Task 2's JS.

- [ ] **Step 1: Add the Facebook branch to `normalize_video_url`**

In `anchor-gallery/anchor-gallery.php`, locate `private function normalize_video_url($url)` (~line 2847). Insert this block immediately **before** `return null;` (after the Vimeo block, ~line 2872):

```php
        // Facebook videos and reels. Facebook's embed plugin needs the full
        // page URL (href), not an extracted ID, so we store the canonical URL
        // as the "id". fb.watch short links and already-formed plugin URLs are
        // normalized to the real video URL where possible.
        if (preg_match('~(?:facebook\.com|fb\.watch)~i', $url)) {
            $canonical = $url;
            // Unwrap an already-formed embed URL: plugins/video.php?href=ENCODED
            if (preg_match('~plugins/video\.php\?.*\bhref=([^&]+)~i', $url, $m)) {
                $canonical = urldecode($m[1]);
            }
            return [
                'provider'       => 'facebook',
                'id'             => $canonical,
                'thumb'          => '',
                'fallback_thumb' => '',
                'label'          => 'Facebook Video',
                'raw_url'        => $canonical,
                'duration'       => '',
                'channel'        => '',
            ];
        }
```

- [ ] **Step 2: Carry `aspect` through `parse_videos_from_rows`**

In the same file, find the `$extras = [ ... ];` array inside `parse_videos_from_rows()` (~line 2781). Add an `aspect` entry. Replace:

```php
            $extras = [
                'alt'                 => $alt,
                'caption'             => $caption,
                'custom_thumbnail_id' => $custom_thb,
                'link_url'            => $link_url,
                'link_target'         => $link_target,
                'categories'          => $categories,
                'category'            => $categories ? $categories[0] : ( (string) ( $row['category'] ?? '' ) ),
            ];
```

with (add the `aspect` line, and the sanitization just above it):

```php
            $aspect = (string) ( $row['aspect'] ?? '' );
            if ( ! in_array( $aspect, [ '16:9', '9:16', '1:1' ], true ) ) $aspect = '';

            $extras = [
                'alt'                 => $alt,
                'caption'             => $caption,
                'custom_thumbnail_id' => $custom_thb,
                'link_url'            => $link_url,
                'link_target'         => $link_target,
                'categories'          => $categories,
                'category'            => $categories ? $categories[0] : ( (string) ( $row['category'] ?? '' ) ),
                'aspect'              => $aspect,
            ];
```

- [ ] **Step 3: Emit `data-aspect` on the tile**

Find the tile attribute block in the render (~line 2249-2251) that reads:

```php
                     data-provider="<?php echo esc_attr($video['provider']); ?>"
                     data-video-id="<?php echo esc_attr($video['id']); ?>"
                     data-url="<?php echo esc_attr($video['raw_url'] ?? ''); ?>"
```

Add a `data-aspect` attribute after `data-url`:

```php
                     data-provider="<?php echo esc_attr($video['provider']); ?>"
                     data-video-id="<?php echo esc_attr($video['id']); ?>"
                     data-url="<?php echo esc_attr($video['raw_url'] ?? ''); ?>"
                     <?php if ( ! empty( $video['aspect'] ) ): ?>data-aspect="<?php echo esc_attr($video['aspect']); ?>"<?php endif; ?>
```

- [ ] **Step 4: Verify PHP syntax**

Run: `php -l "anchor-gallery/anchor-gallery.php"`
Expected: `No syntax errors detected in anchor-gallery/anchor-gallery.php`

- [ ] **Step 5: Commit**

```bash
git add anchor-gallery/anchor-gallery.php
git commit -m "feat(gallery): parse Facebook video URLs + carry per-item aspect"
```

---

## Task 2: Gallery — Facebook frontend rendering (JS)

**Files:**
- Modify: `anchor-gallery/assets/anchor-video-slider.js` — `buildFacebookSrc` (new, ~after line 27), `getVideoSrc` (~line 29), `getDirectUrl` (~line 39), click handler `popupOpts` (~line 452), preload loop (~line 844-860).
- Modify: `anchor-gallery/assets/anchor-video-slider.min.js` — mirror the same changes.
- Modify: `anchor-gallery/anchor-gallery.php` — the `wp_enqueue_script` version string for this file.

**Interfaces:**
- Consumes: tile attributes `data-provider="facebook"`, `data-video-id="{canonical url}"`, `data-aspect` from Task 1.
- Produces: Facebook iframes in every popup style (lightbox, theater, side-panel, inline) via the shared `getVideoSrc`.

- [ ] **Step 1: Add `buildFacebookSrc` and extend `getVideoSrc`/`getDirectUrl`**

In `anchor-gallery/assets/anchor-video-slider.js`, after `buildVimeoSrc` (ends ~line 27) and within the same builders section, add:

```javascript
  function buildFacebookSrc(url, autoplay) {
    var params = new URLSearchParams({
      href: url,
      show_text: 'false',
      autoplay: autoplay ? 'true' : 'false'
    });
    if (autoplay) {
      params.set('mute', '1');
    }
    return 'https://www.facebook.com/plugins/video.php?' + params.toString();
  }
```

Then update `getVideoSrc` (~line 29) to add the Facebook branch (note: for Facebook the `id` argument is the canonical URL):

```javascript
  function getVideoSrc(provider, id, autoplay) {
    if (provider === 'youtube') {
      return buildYouTubeSrc(id, autoplay);
    }
    if (provider === 'vimeo') {
      return buildVimeoSrc(id, autoplay);
    }
    if (provider === 'facebook') {
      return buildFacebookSrc(id, autoplay);
    }
    return '';
  }
```

And update `getDirectUrl` (~line 39) so the fallback link is the stored URL:

```javascript
  function getDirectUrl(provider, id) {
    if (provider === 'youtube') {
      return 'https://www.youtube.com/watch?v=' + encodeURIComponent(id);
    }
    if (provider === 'vimeo') {
      return 'https://vimeo.com/' + encodeURIComponent(id);
    }
    if (provider === 'facebook') {
      return id;
    }
    return '';
  }
```

- [ ] **Step 2: Make the click handler use the per-item aspect**

In the same file, find the `popupOpts` object built in the tile click handler (~line 452). Replace:

```javascript
    var popupOpts = {
      maxWidth: gallery.getAttribute('data-popup-max-width') || '',
      aspect: gallery.getAttribute('data-popup-aspect') || '',
      caption: (showCaption === '1' && captionAttr) ? captionAttr : ''
    };
```

with (per-item `data-aspect` wins, gallery-level is the fallback):

```javascript
    var tileAspect = tile.getAttribute('data-aspect') || '';
    var popupOpts = {
      maxWidth: gallery.getAttribute('data-popup-max-width') || '',
      aspect: tileAspect || gallery.getAttribute('data-popup-aspect') || '',
      caption: (showCaption === '1' && captionAttr) ? captionAttr : ''
    };
```

(`applyPopupOptions` and the inline player already apply `opts.aspect` to the iframe, so all four popup styles pick this up automatically.)

- [ ] **Step 3: Add Facebook preconnect and skip Facebook in embed prefetch**

In the lazy-preload `IntersectionObserver` block (~line 844-860), after the existing `ensureLink` preconnect calls add a Facebook preconnect, and skip Facebook in the embed-URL prefetch (Facebook has no preconnectable embed-by-id URL). Replace:

```javascript
        // Preconnect to video CDN origins
        ensureLink('preconnect', 'https://www.youtube-nocookie.com');
        ensureLink('preconnect', 'https://player.vimeo.com');
        ensureLink('dns-prefetch', 'https://i.ytimg.com');

        // Prefetch each video's embed URL
        thumbs.forEach(function(thumb) {
          var provider = thumb.getAttribute('data-provider');
          var videoId  = thumb.getAttribute('data-video-id');
          if (!provider || !videoId) return;

          var embedUrl = provider === 'youtube'
            ? 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(videoId)
            : 'https://player.vimeo.com/video/' + encodeURIComponent(videoId);

          ensureLink('prefetch', embedUrl, 'document');
        });
```

with:

```javascript
        // Preconnect to video CDN origins
        ensureLink('preconnect', 'https://www.youtube-nocookie.com');
        ensureLink('preconnect', 'https://player.vimeo.com');
        ensureLink('preconnect', 'https://www.facebook.com');
        ensureLink('dns-prefetch', 'https://i.ytimg.com');

        // Prefetch each video's embed URL (YouTube/Vimeo only — Facebook's
        // embed URL is per-video href, nothing useful to prefetch by id).
        thumbs.forEach(function(thumb) {
          var provider = thumb.getAttribute('data-provider');
          var videoId  = thumb.getAttribute('data-video-id');
          if (!provider || !videoId) return;
          if (provider !== 'youtube' && provider !== 'vimeo') return;

          var embedUrl = provider === 'youtube'
            ? 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(videoId)
            : 'https://player.vimeo.com/video/' + encodeURIComponent(videoId);

          ensureLink('prefetch', embedUrl, 'document');
        });
```

- [ ] **Step 4: Mirror all three edits into the minified file**

Apply the equivalent changes to `anchor-gallery/assets/anchor-video-slider.min.js` (add `buildFacebookSrc`, the `getVideoSrc`/`getDirectUrl` facebook branches, the `tileAspect` line, the preconnect + provider guard). No minifier runs, so hand-edit to keep behavior identical.

- [ ] **Step 5: Bump the enqueue version**

In `anchor-gallery/anchor-gallery.php`, find the `wp_enqueue_script` (or `wp_register_script`) call for `anchor-video-slider.js` (the frontend script) and bump its version string (the 4th arg) to the next value, e.g. `'3.4.1'` → `'3.4.2'`. If the version is derived from a constant, leave as-is.

Run to locate it: `grep -n "anchor-video-slider" anchor-gallery/anchor-gallery.php`

- [ ] **Step 6: Verify**

Run: `node --check "anchor-gallery/assets/anchor-video-slider.js"`
Expected: exits 0 (no output). If `node` is unavailable, skip — the manual check below is authoritative.

Manual (WordPress): create a gallery with one Facebook reel URL (e.g. `https://www.facebook.com/reel/1533217648293233/`), set its thumbnail + Vertical orientation (Task 3), view on a page. Expected: tile shows the chosen thumbnail; clicking opens the popup with the Facebook player rendered vertically (9:16). Repeat with a `facebook.com/watch/?v=…` URL set to Landscape → 16:9 player.

- [ ] **Step 7: Commit**

```bash
git add anchor-gallery/assets/anchor-video-slider.js anchor-gallery/assets/anchor-video-slider.min.js anchor-gallery/anchor-gallery.php
git commit -m "feat(gallery): render Facebook video embeds in all popup styles"
```

---

## Task 3: Gallery — Orientation field + bulk-import Facebook detection (admin)

**Files:**
- Modify: `anchor-gallery/anchor-gallery.php` — `render_item_inspector_template()` (~line 1051-1128, add Orientation `<select>`).
- Modify: `anchor-gallery/assets/admin.js` — `buildCard` hidden input (~line 267), `readCard` (~line 296), `openInspector` (~line 359), `commitInspectorField` map (~line 404), inspector binding (~line 549), bulk-import regex (~line 502).
- Modify: `anchor-gallery/assets/admin.min.js` — mirror the same changes.
- Modify: `anchor-gallery/anchor-gallery.php` — bump the admin script enqueue version.

**Interfaces:**
- Consumes: per-item card data shape from `readCard`/`buildCard`.
- Produces: an `aspect` value saved into the `avg_videos[i][aspect]` field, consumed by Task 1's PHP.

- [ ] **Step 1: Add the Orientation field to the inspector template**

In `anchor-gallery/anchor-gallery.php`, inside `render_item_inspector_template()`, find the video URL row (~line 1068-1071):

```php
                <p class="avg-insp-row-video">
                    <label><strong>Video URL</strong></label>
                    <input type="url" class="avg-insp-url widefat" placeholder="https://youtube.com/watch?v=..." />
                </p>
```

Replace it with (updated placeholder mentioning Facebook + a new Orientation row directly below):

```php
                <p class="avg-insp-row-video">
                    <label><strong>Video URL</strong></label>
                    <input type="url" class="avg-insp-url widefat" placeholder="YouTube, Vimeo, or Facebook video / reel URL" />
                </p>
                <p class="avg-insp-row-video">
                    <label><strong>Orientation</strong></label>
                    <select class="avg-insp-aspect widefat">
                        <option value="">Auto (use gallery setting)</option>
                        <option value="16:9">Landscape (16:9)</option>
                        <option value="9:16">Vertical / Reel (9:16)</option>
                        <option value="1:1">Square (1:1)</option>
                    </select>
                    <span class="description">For Facebook reels choose Vertical. Also sets a custom thumbnail via "Custom Thumbnail" below.</span>
                </p>
```

- [ ] **Step 2: Add the hidden `aspect` input to `buildCard`**

In `anchor-gallery/assets/admin.js`, in `buildCard` find the link-target hidden input (~line 267) and add an `aspect` hidden input right after it. Replace:

```javascript
      +   '<input type="text" name="'+ prefix +'[link_target]"  value="'+ escapeAttr(item.link_target || '_self') +'" class="avg-item-link-target" style="display:none" />'
      + '</div>';
```

with:

```javascript
      +   '<input type="text" name="'+ prefix +'[link_target]"  value="'+ escapeAttr(item.link_target || '_self') +'" class="avg-item-link-target" style="display:none" />'
      +   '<input type="hidden" name="'+ prefix +'[aspect]"      value="'+ escapeAttr(item.aspect || '') +'"      class="avg-item-aspect" />'
      + '</div>';
```

- [ ] **Step 3: Read `aspect` in `readCard`**

In `readCard` (~line 284-297) add an `aspect` key. Replace:

```javascript
      link_target:         $row.find('.avg-item-link-target').val() || '_self'
    };
```

with:

```javascript
      link_target:         $row.find('.avg-item-link-target').val() || '_self',
      aspect:              $row.find('.avg-item-aspect').val() || ''
    };
```

- [ ] **Step 4: Populate the inspector field in `openInspector`**

In `openInspector` (~line 351-359) add a line populating the aspect select. After:

```javascript
    $p.find('.avg-insp-link-target').val(item.link_target);
```

add:

```javascript
    $p.find('.avg-insp-aspect').val(item.aspect || '');
```

- [ ] **Step 5: Add `aspect` to the `commitInspectorField` map**

In `commitInspectorField` (~line 395-405) add the `aspect` mapping. Replace:

```javascript
      link_url:    '.avg-item-link-url',
      link_target: '.avg-item-link-target'
    };
```

with:

```javascript
      link_url:    '.avg-item-link-url',
      link_target: '.avg-item-link-target',
      aspect:      '.avg-item-aspect'
    };
```

- [ ] **Step 6: Bind the inspector select change**

In the inspector field bindings block (~line 544-549), add a binding alongside the others:

```javascript
    $(document).on('change', '.avg-insp-aspect', function(){ commitInspectorField('aspect', $(this).val()); });
```

- [ ] **Step 7: Detect Facebook URLs in bulk import**

In the bulk URL import handler (~line 496-508) widen the regex. Replace:

```javascript
        if (/youtu\.?be|vimeo\.com/.test(line)) {
          addItem({ type: 'video', url: line });
        }
```

with:

```javascript
        if (/youtu\.?be|youtube\.com|vimeo\.com|facebook\.com|fb\.watch/.test(line)) {
          addItem({ type: 'video', url: line });
        }
```

- [ ] **Step 8: Mirror Steps 2-7 into the minified admin file**

Apply the equivalent edits to `anchor-gallery/assets/admin.min.js`.

- [ ] **Step 9: Bump the admin enqueue version**

In `anchor-gallery/anchor-gallery.php`, find the `wp_enqueue_script` for `admin.js` (run `grep -n "admin.js\|admin.min.js" anchor-gallery/anchor-gallery.php`) and bump its version string.

- [ ] **Step 10: Verify**

Run: `php -l "anchor-gallery/anchor-gallery.php"`
Expected: `No syntax errors detected`.
Run: `node --check "anchor-gallery/assets/admin.js"` (skip if no `node`) → exits 0.

Manual (WordPress): edit a gallery, add a video item, click Edit. Expected: an "Orientation" dropdown appears under the Video URL field with Auto/Landscape/Vertical/Square. Set it to Vertical, set a Custom Thumbnail, save, reload the editor → the value persists. Paste a Facebook reel URL into the bulk-import box → it creates a video item.

- [ ] **Step 11: Commit**

```bash
git add anchor-gallery/anchor-gallery.php anchor-gallery/assets/admin.js anchor-gallery/assets/admin.min.js
git commit -m "feat(gallery): per-item orientation field + Facebook bulk-import"
```

---

## Task 4: Popups — Facebook URL parsing + manual-thumbnail meta (PHP)

**Files:**
- Modify: `anchor-universal-popups/anchor-universal-popups.php` — `parse_video_url()` (~line 282-297), build-items meta block (~line 960-977), URL field placeholder (~line 507).

**Interfaces:**
- Produces: `parse_video_url($url)` returns `['provider'=>'facebook','id'=>'']` for Facebook URLs. The built snippet array sets `provider='facebook'`, keeps `video_url` as the canonical URL, and uses `custom_thumb` as `video_thumb`. Consumed by Task 5's JS via `sn.provider` and `sn.video_url`.

- [ ] **Step 1: Add the Facebook branch to `parse_video_url`**

In `anchor-universal-popups/anchor-universal-popups.php`, in `parse_video_url()` (~line 282), insert before `return null;` (after the Vimeo block, ~line 294):

```php
        // Facebook videos and reels. The embed plugin uses the full URL (href),
        // so there is no separate ID — callers use video_url for Facebook.
        if (preg_match('~(?:facebook\.com|fb\.watch)~i', $url)) {
            return ['provider' => 'facebook', 'id' => ''];
        }
```

- [ ] **Step 2: Handle Facebook in the build-items meta block**

Find the metadata block in the items loop (~line 960-977):

```php
                } else {
                    // Video mode: parse URL to get provider and ID
                    $parsed = $this->parse_video_url($video_url);
                    if ($parsed) {
                        $provider = $parsed['provider'];
                        $video_id = $parsed['id'];
                    }
                }

                // Fetch metadata
                if ($provider && $video_id) {
                    $meta = $this->fetch_video_meta($provider, $video_id, $m['thumb_size']);
                    $video_thumb = ! empty( $m['custom_thumb'] ) ? $m['custom_thumb'] : $meta['thumb'];
                    $video_title = $meta['title'];
                    $video_duration = $meta['duration'];
                    $video_channel = $meta['channel'];
                }
```

Replace with (Facebook skips the API and always uses the manual thumbnail):

```php
                } else {
                    // Video mode: parse URL to get provider and ID
                    $parsed = $this->parse_video_url($video_url);
                    if ($parsed) {
                        $provider = $parsed['provider'];
                        $video_id = $parsed['id'];
                    }
                }

                // Fetch metadata. Facebook has no public thumbnail API, so it
                // always uses the manually-set custom thumbnail; YouTube/Vimeo
                // auto-fetch as before.
                if ($provider === 'facebook') {
                    $video_thumb = $m['custom_thumb'];
                } elseif ($provider && $video_id) {
                    $meta = $this->fetch_video_meta($provider, $video_id, $m['thumb_size']);
                    $video_thumb = ! empty( $m['custom_thumb'] ) ? $m['custom_thumb'] : $meta['thumb'];
                    $video_title = $meta['title'];
                    $video_duration = $meta['duration'];
                    $video_channel = $meta['channel'];
                }
```

- [ ] **Step 3: Update the URL field placeholder**

Find the video URL input (~line 507):

```php
              <input type="url" name="up_video_url" value="<?php echo esc_attr($video_url_display); ?>" placeholder="https://youtube.com/watch?v=... or https://vimeo.com/..." class="widefat"/>
```

Replace the placeholder text:

```php
              <input type="url" name="up_video_url" value="<?php echo esc_attr($video_url_display); ?>" placeholder="YouTube, Vimeo, or Facebook video / reel URL" class="widefat"/>
```

(No new fields needed: this module already has per-snippet `aspect_ratio` — which includes `9:16` — and a `custom_thumb` picker. For Facebook, set Aspect Ratio to `9:16` for reels and supply a Custom Thumbnail.)

- [ ] **Step 4: Verify PHP syntax**

Run: `php -l "anchor-universal-popups/anchor-universal-popups.php"`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add anchor-universal-popups/anchor-universal-popups.php
git commit -m "feat(popups): parse Facebook URLs + manual thumbnail for Facebook video"
```

---

## Task 5: Popups — Facebook frontend rendering (JS)

**Files:**
- Modify: `anchor-universal-popups/assets/frontend.js` — `buildFacebookSrc` (new, ~after line 160), `getVideoSrc` dispatcher (new), `openVideo` (~line 388), `expandTakeover` (~line 318-320), dispatch call site (~line 536).
- Modify: `anchor-universal-popups/assets/frontend.min.js` — mirror the changes.
- Modify: `anchor-universal-popups/anchor-universal-popups.php` — bump the frontend enqueue version.

**Interfaces:**
- Consumes: snippet fields `sn.provider === 'facebook'`, `sn.video_url`, `sn.autoplay` from Task 4.
- Produces: Facebook player injected directly (no postMessage preload, which Facebook's embed doesn't support).

- [ ] **Step 1: Add `buildFacebookSrc` and a `getVideoSrc` dispatcher**

In `anchor-universal-popups/assets/frontend.js`, after `buildVimeoSrc` (ends ~line 160) add:

```javascript
  function buildFacebookSrc(url, opts){
    opts = opts || {};
    var p = new URLSearchParams({
      href: url,
      show_text: 'false',
      autoplay: opts.autoplay ? 'true' : 'false'
    });
    // Facebook only autoplays muted.
    if(opts.autoplay || opts.muted){
      p.set('mute', '1');
    }
    return 'https://www.facebook.com/plugins/video.php?' + p.toString();
  }

  // Build the embed src for any provider. For Facebook, `ref` is the full
  // video URL; for YouTube/Vimeo it is the video id.
  function getVideoSrc(provider, ref, opts){
    if(provider === 'facebook') return buildFacebookSrc(ref, opts);
    if(provider === 'vimeo')    return buildVimeoSrc(ref, opts);
    return buildYouTubeSrc(ref, opts);
  }
```

- [ ] **Step 2: Route `openVideo` through the dispatcher**

In `openVideo` (~line 384-390) replace:

```javascript
    var opts = { autoplay: !!autoplay, muted: !!(extra && extra.muted) };
    if(modal._preloaded){
      // Reuse the player that's already warmed up in the background.
      if(autoplay){ playPreloaded(modal, provider, opts.muted); }
    } else {
      var src = provider === 'youtube' ? buildYouTubeSrc(id, opts) : buildVimeoSrc(id, opts);
      frameWrap.innerHTML = videoIframe(src);
    }
```

with (Facebook never preloads and can't be driven by postMessage, so it always builds + injects; force mute when autoplaying):

```javascript
    var opts = { autoplay: !!autoplay, muted: !!(extra && extra.muted) };
    if(provider === 'facebook'){
      var fbSrc = getVideoSrc(provider, id, { autoplay: !!autoplay, muted: !!autoplay });
      frameWrap.innerHTML = videoIframe(fbSrc);
    } else if(modal._preloaded){
      // Reuse the player that's already warmed up in the background.
      if(autoplay){ playPreloaded(modal, provider, opts.muted); }
    } else {
      var src = getVideoSrc(provider, id, opts);
      frameWrap.innerHTML = videoIframe(src);
    }
```

- [ ] **Step 3: Route `expandTakeover` through the dispatcher**

In `expandTakeover` (~line 316-321) replace:

```javascript
      playPreloaded(modal, provider, true);
    } else {
      var src = provider === 'youtube'
        ? buildYouTubeSrc(id, { autoplay: true, muted: true })
        : buildVimeoSrc(id, { autoplay: true, muted: true });
      frameWrap.innerHTML = videoIframe(src);
```

with:

```javascript
      playPreloaded(modal, provider, true);
    } else {
      var src = getVideoSrc(provider, id, { autoplay: true, muted: true });
      frameWrap.innerHTML = videoIframe(src);
```

(Facebook reaches the `else` branch here because it is never preloaded — see Step 4 — so `modal._preloaded` is false.)

- [ ] **Step 4: Pass the canonical URL for Facebook at the dispatch site**

In the items loop, find the `triggerOpen` `openVideo` call (~line 531-536):

```javascript
      if(isVideo){
        // Autoplay must be muted unless this is a genuine user click (class/id) —
        // browsers block unmuted autoplay for page_load/scroll-triggered playback.
        var userClick = (trig && (trig.type === 'class' || trig.type === 'id'));
        var muteForAutoplay = !!sn.autoplay && !userClick;
        openVideo(modal, provider, sn.video_id, !!sn.autoplay, { html: sn.html, css: sn.css, js: sn.js, muted: muteForAutoplay });
      } else {
```

Replace the `openVideo(...)` line so Facebook passes its URL as the `ref`:

```javascript
      if(isVideo){
        // Autoplay must be muted unless this is a genuine user click (class/id) —
        // browsers block unmuted autoplay for page_load/scroll-triggered playback.
        var userClick = (trig && (trig.type === 'class' || trig.type === 'id'));
        var muteForAutoplay = !!sn.autoplay && !userClick;
        var vidRef = (provider === 'facebook') ? sn.video_url : sn.video_id;
        openVideo(modal, provider, vidRef, !!sn.autoplay, { html: sn.html, css: sn.css, js: sn.js, muted: muteForAutoplay });
      } else {
```

The preload queue condition at ~line 512 (`provider === 'youtube' || provider === 'vimeo'`) already excludes Facebook — no change needed there.

- [ ] **Step 5: Mirror Steps 1-4 into the minified file**

Apply the equivalent edits to `anchor-universal-popups/assets/frontend.min.js`.

- [ ] **Step 6: Bump the frontend enqueue version**

In `anchor-universal-popups/anchor-universal-popups.php` run `grep -n "frontend.js\|frontend.min.js" anchor-universal-popups/anchor-universal-popups.php` and bump the version string on that `wp_enqueue_script` call.

- [ ] **Step 7: Verify**

Run: `node --check "anchor-universal-popups/assets/frontend.js"` (skip if no `node`) → exits 0.

Manual (WordPress): create a Universal Popup, set Mode = Video, paste a Facebook reel URL, set Aspect Ratio = 9:16, set a Custom Thumbnail, place the popup's card via shortcode. Expected: card shows the thumbnail; clicking opens the modal with the Facebook player. Repeat with a landscape `watch/?v=` URL at 16:9. Confirm a YouTube popup still preloads and autoplays as before (no regression).

- [ ] **Step 8: Commit**

```bash
git add anchor-universal-popups/assets/frontend.js anchor-universal-popups/assets/frontend.min.js anchor-universal-popups/anchor-universal-popups.php
git commit -m "feat(popups): render Facebook video embeds in pop-up player"
```

---

## Task 6: Final integration verification

**Files:** none (verification only).

- [ ] **Step 1: Lint all touched PHP**

```bash
php -l "anchor-gallery/anchor-gallery.php" && php -l "anchor-universal-popups/anchor-universal-popups.php"
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 2: Confirm min/readable parity**

For each edited asset pair, confirm the Facebook logic exists in BOTH files:
```bash
grep -c "buildFacebookSrc" anchor-gallery/assets/anchor-video-slider.js anchor-gallery/assets/anchor-video-slider.min.js anchor-universal-popups/assets/frontend.js anchor-universal-popups/assets/frontend.min.js
```
Expected: each file reports `1` (or more), none report `0`.

- [ ] **Step 3: Cross-module manual smoke test**

In WordPress, on one page place both a gallery (mixed YouTube + Vimeo + Facebook reel) and a Facebook video popup. Expected: YouTube/Vimeo thumbnails + titles auto-fetch and play; the Facebook reel shows its manual thumbnail, plays vertically; opening the popup closes the gallery lightbox and vice-versa (cross-module `anchor-close-popups` still works); no console errors.

- [ ] **Step 4: Final commit (if any verification fix was needed)**

```bash
git add -A
git commit -m "test(video): verify Facebook embeds across gallery + popups"
```

---

## Self-Review

**Spec coverage:**
- Manual thumbnail upload → gallery reuses existing Custom Thumbnail picker (Task 3 Step 1 guidance); popups reuses existing `custom_thumb` (Task 4 Step 2). ✓
- Both modules → Tasks 1-3 (gallery), 4-5 (popups). ✓
- Per-video aspect (landscape/vertical/square) → gallery Task 3 Orientation field + Task 1/2 plumbing; popups uses existing `aspect_ratio` (Task 4 Step 3 note). ✓
- URL parsing of reel/watch/videos/share/fb.watch/already-embedded → Task 1 Step 1 (gallery) + Task 4 Step 1 (popups). Note: popups parse returns provider only (no ID needed); the embed uses `video_url` directly, so all those forms are handled by passing the raw URL to the embed plugin. ✓
- Facebook skipped in metadata fetch → Task 1 (no yt/vm id added) + Task 4 Step 2 (explicit `facebook` branch). ✓
- `buildFacebookSrc` with autoplay+mute → Task 2 Step 1, Task 5 Step 1. ✓
- All popup styles (lightbox/theater/side-panel/inline) → gallery via shared `getVideoSrc`/`applyPopupOptions` (Task 2). ✓
- Preconnect to facebook.com → Task 2 Step 3. ✓
- Out of scope (photos/posts, App-token oEmbed, shared helper) → none added. ✓

**Placeholder scan:** No TBD/TODO/"handle edge cases" — all steps contain exact code and exact file anchors. ✓

**Type/name consistency:** `getVideoSrc(provider, id|ref, …)` and `buildFacebookSrc(url, …)` named consistently within each module. Gallery stores the canonical URL in `id`/`data-video-id`; popups passes `sn.video_url` as `ref`. Aspect values `16:9`/`9:16`/`1:1` consistent across PHP sanitize (Task 1), inspector options (Task 3), and JS aspect application. ✓
