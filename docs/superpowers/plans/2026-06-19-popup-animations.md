# Popup Open/Close Animations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-popup, user-selectable entrance + exit animation (auto / none / fade / zoom / slide / fly-in) with a direction and duration control, applied to every popup style in the Anchor Universal Popups module.

**Architecture:** A new `Animation` dropdown (+ direction + duration) is added to the popup editor and saved as `up_*` post meta, then passed through the `UP_SNIPPETS` payload to the frontend. The frontend applies `up-anim-*` / `up-anim-dir-*` classes plus a `--up-anim-dur` variable to the modal, and CSS transitions toggled by an `is-open` class drive a reversible entrance/exit. The transform of the animated element is composed from two CSS variables (`--up-pos-tx` for positioning, `--up-enter-tx` for the entrance offset) so positioning and animation stay independent. The existing hardcoded drawer/fly-in `@keyframes` are replaced by this system, with an `auto` default that reproduces today's behavior.

**Tech Stack:** Raw PHP (WordPress), vanilla JS (jQuery only in admin), CSS. No build tooling locally — `.min` assets are gitignored and built by CI. No automated test suite — verification is manual in a WordPress environment.

## Global Constraints

- Module dir: `anchor-universal-popups/`. Main file: `anchor-universal-popups/anchor-universal-popups.php`.
- Meta key prefix: `up_` (e.g. `up_open_animation`). Settings are defined in `defaults()` and read via `get_meta()`.
- Text domain for translatable strings: `anchor-schema`.
- Asset URLs via `Anchor_Asset_Loader::url('anchor-universal-popups/assets/<file>')`. Cache-busting uses `filemtime()` on the **source** file — editing source is sufficient.
- `.min.css` / `.min.js` are **gitignored**, built by CI (`bin/build-assets.mjs`). Do NOT hand-edit or commit them. For local testing define `SCRIPT_DEBUG` true (or delete local `.min` files) so the loader serves source.
- Frontend JS pattern: IIFE over `window.UP_SNIPPETS` (an array of snippet objects), no ES modules, no jQuery.
- Admin JS pattern: jQuery IIFE `(function($){ ... })(jQuery);`.
- Commit after each task with a `feat:`/`refactor:` message. Do NOT bump the plugin `Version:` header per-task — that happens once at release time.
- Animation values: `open_animation` ∈ {auto, none, fade, zoom, slide, flyin}; `animation_direction` ∈ {up, down, left, right}; `animation_duration` integer ms clamped 100–1000 (default 300).
- `auto` mapping: modal/theater → zoom; drawer-right → slide+right; drawer-left → slide+left; drawer-bottom → slide+down; flyin-bottom* → flyin+down; fullscreen → (never animated via this system).

---

### Task 1: Add settings to PHP (defaults, save whitelist, snippets payload)

Wires the three new settings end-to-end on the server side so they persist and reach the frontend. No UI yet (Task 2) and no frontend effect yet (Tasks 3–5).

**Files:**
- Modify: `anchor-universal-popups/anchor-universal-popups.php` — `defaults()` (~line 146), `save_meta()` whitelist (~line 785), snippets payload (~line 955).

**Interfaces:**
- Produces: post meta keys `up_open_animation`, `up_animation_direction`, `up_animation_duration`; and `UP_SNIPPETS[].open_animation` (string), `.animation_direction` (string), `.animation_duration` (int) consumed by Task 3.

- [ ] **Step 1: Add the three keys to `defaults()`**

In `anchor-universal-popups/anchor-universal-popups.php`, find the `popup_style` block in `defaults()` (~line 146-150) and add the new keys immediately after `flyin_max_width`:

```php
            'flyin_max_width' => '',        // e.g. 420px or 30%, blank = default 400px
            'open_animation' => 'auto',     // auto, none, fade, zoom, slide, flyin
            'animation_direction' => 'up',  // up, down, left, right (used by slide/flyin)
            'animation_duration' => '300',  // ms, clamped 100-1000 on output
```

- [ ] **Step 2: Add the keys to the `save_meta()` whitelist**

In `save_meta()` (~line 785), add the three keys to the `$fields` array on the line with `popup_style`:

```php
            'popup_style','modal_max_width','theater_max_width','theater_max_height','flyin_max_width',
            'open_animation','animation_direction','animation_duration','autoplay','close_color',
```

(Replace the existing `'popup_style',...,'flyin_max_width','autoplay','close_color',` line with the two lines above.) These pass through the default `sanitize_text_field()` branch — no special handling needed.

- [ ] **Step 3: Add the values to the snippets payload**

In the `$items[] = [ ... ]` array (~line 955), add after the `'flyin_max_width' => $m['flyin_max_width'],` line:

```php
                'flyin_max_width' => $m['flyin_max_width'],
                'open_animation' => $m['open_animation'] ?: 'auto',
                'animation_direction' => $m['animation_direction'] ?: 'up',
                'animation_duration' => max(100, min(1000, (int) $m['animation_duration'] ?: 300)),
```

- [ ] **Step 4: Verify PHP is syntactically valid**

Run: `php -l "anchor-universal-popups/anchor-universal-popups.php"`
Expected: `No syntax errors detected in anchor-universal-popups/anchor-universal-popups.php`

- [ ] **Step 5: Verify the values reach the frontend**

In a WordPress dev environment with the plugin active and a published popup on a page, view source / DevTools and confirm the `UP_SNIPPETS` localized array (in the page `<script>`) now includes `"open_animation":"auto"`, `"animation_direction":"up"`, `"animation_duration":300` for that popup.
Expected: the three keys are present with the default values.

- [ ] **Step 6: Commit**

```bash
git add anchor-universal-popups/anchor-universal-popups.php
git commit -m "feat(popups): add animation settings (defaults, save, payload)"
```

---

### Task 2: Add the animation controls to the settings metabox

Adds the editor UI. After this task an admin can pick an animation, direction, and duration and have it saved (save path landed in Task 1). The direction field shows only for slide/flyin via a new admin.js toggle.

**Files:**
- Modify: `anchor-universal-popups/anchor-universal-popups.php` — settings metabox, right after the fly-in max-width block (~line 672).
- Modify: `anchor-universal-popups/assets/admin.js` — add `toggleAnimationFields()` and wire it.

**Interfaces:**
- Consumes: meta keys from Task 1 via `$m['open_animation']`, `$m['animation_direction']`, `$m['animation_duration']`.
- Produces: form fields `up_open_animation`, `up_animation_direction`, `up_animation_duration` (consumed by `save_meta()` from Task 1).

- [ ] **Step 1: Add the markup to the metabox**

In `anchor-universal-popups/anchor-universal-popups.php`, immediately after the closing `</div>` of the `data-up-show-when-style="flyin-bottom,flyin-bottom-left,flyin-bottom-right"` block (the Fly-in Max Width block ending ~line 672), insert:

```php
          <hr/>
          <label><strong>Open Animation</strong></label>
          <select name="up_open_animation" id="up_open_animation">
            <option value="auto"  <?php selected($m['open_animation'], 'auto');  ?>>Auto (match popup style)</option>
            <option value="none"  <?php selected($m['open_animation'], 'none');  ?>>None (instant)</option>
            <option value="fade"  <?php selected($m['open_animation'], 'fade');  ?>>Fade</option>
            <option value="zoom"  <?php selected($m['open_animation'], 'zoom');  ?>>Zoom / Scale</option>
            <option value="slide" <?php selected($m['open_animation'], 'slide'); ?>>Slide</option>
            <option value="flyin" <?php selected($m['open_animation'], 'flyin'); ?>>Fly-in (overshoot)</option>
          </select>
          <p class="description">How the popup animates in and out. <strong>Auto</strong> uses a sensible animation for the chosen popup style.</p>

          <div data-up-show-when-anim="slide,flyin">
            <label>Direction</label>
            <select name="up_animation_direction" id="up_animation_direction">
              <option value="up"    <?php selected($m['animation_direction'], 'up');    ?>>From top</option>
              <option value="down"  <?php selected($m['animation_direction'], 'down');  ?>>From bottom</option>
              <option value="left"  <?php selected($m['animation_direction'], 'left');  ?>>From left</option>
              <option value="right" <?php selected($m['animation_direction'], 'right'); ?>>From right</option>
            </select>
            <p class="description">Edge the popup slides in from. Ignored by Auto, Fade, and Zoom.</p>
          </div>

          <label>Animation Duration (ms)</label>
          <input type="number" name="up_animation_duration" value="<?php echo esc_attr($m['animation_duration']); ?>" min="100" max="1000" step="50" />
          <p class="description">Speed of the open/close animation. 100–1000ms (default 300).</p>
```

- [ ] **Step 2: Add the admin.js toggle function**

In `anchor-universal-popups/assets/admin.js`, add this function right after `togglePopupStyleFields()` (ends ~line 58):

```javascript
  function toggleAnimationFields(){
    var anim = $('select[name="up_open_animation"]').val() || 'auto';
    $('[data-up-show-when-anim]').each(function(){
      var allowed = String($(this).attr('data-up-show-when-anim')).split(',').map($.trim).filter(Boolean);
      $(this).toggle(allowed.indexOf(anim) !== -1);
    });
  }
```

- [ ] **Step 3: Call it on ready and on change**

In `anchor-universal-popups/assets/admin.js`, in the `$(document).ready(...)` block, add the initial call next to `togglePopupStyleFields();` (~line 117):

```javascript
    togglePopupStyleFields();
    toggleAnimationFields();
```

And add the change binding next to the existing `up_popup_style` binding (~line 121):

```javascript
    $(document).on('change', 'select[name="up_popup_style"]', togglePopupStyleFields);
    $(document).on('change', 'select[name="up_open_animation"]', toggleAnimationFields);
```

- [ ] **Step 4: Verify PHP + manual UI check**

Run: `php -l "anchor-universal-popups/anchor-universal-popups.php"`
Expected: `No syntax errors detected ...`

Then in WP admin, edit a popup. In the settings metabox confirm: the **Open Animation** select, a **Direction** select that is hidden when animation is Auto/None/Fade/Zoom and shown for Slide/Fly-in, and a **Duration** number field. Set animation = Slide, direction = From left, duration = 500, **Update**, reload — confirm the values persist.
Expected: fields render, direction toggles correctly, values save and reload.

- [ ] **Step 5: Commit**

```bash
git add anchor-universal-popups/anchor-universal-popups.php anchor-universal-popups/assets/admin.js
git commit -m "feat(popups): add animation controls to popup editor"
```

---

### Task 3: Apply animation classes + duration in the frontend (JS)

Resolves the snippet's animation settings into `up-anim-*` / `up-anim-dir-*` classes and the `--up-anim-dur` variable on the modal, including `auto` resolution. No visible effect yet until the CSS (Task 4) and lifecycle (Task 5) land — but the classes become inspectable in the DOM.

**Files:**
- Modify: `anchor-universal-popups/assets/frontend.js` — add `applyAnimation(modal, sn)` helper and call it in `attach()` (~line 483, after `buildModalShell`).

**Interfaces:**
- Consumes: `sn.open_animation`, `sn.animation_direction`, `sn.animation_duration`, `sn.popup_style` from Task 1; `buildModalShell()` return value (the `.up-modal` element).
- Produces: classes `up-anim-{none|fade|zoom|slide|flyin}` and optionally `up-anim-dir-{up|down|left|right}` on the modal, plus inline `--up-anim-dur`. Consumed by Task 4 (CSS) and Task 5 (lifecycle reads the presence of an `up-anim-*` class that is not `up-anim-none`).

- [ ] **Step 1: Add the `applyAnimation` helper**

In `anchor-universal-popups/assets/frontend.js`, add this function just above `function attach(sn){` (~line 472):

```javascript
  // Resolve a snippet's animation settings into classes + a duration var on the
  // modal. 'auto' derives a tasteful animation from the popup style so existing
  // popups keep their current behavior.
  function applyAnimation(modal, sn){
    var anim = sn.open_animation || 'auto';
    var dir = sn.animation_direction || 'up';
    var style = normalizePopupStyle(sn.popup_style);

    if(anim === 'auto'){
      if(style === 'drawer-right'){ anim = 'slide'; dir = 'right'; }
      else if(style === 'drawer-left'){ anim = 'slide'; dir = 'left'; }
      else if(style === 'drawer-bottom'){ anim = 'slide'; dir = 'down'; }
      else if(style.indexOf('flyin') === 0){ anim = 'flyin'; dir = 'down'; }
      else { anim = 'zoom'; } // modal, theater
    }

    modal.classList.add('up-anim-' + anim);
    if(anim === 'slide' || anim === 'flyin'){
      modal.classList.add('up-anim-dir-' + dir);
    }
    var dur = parseInt(sn.animation_duration, 10);
    if(!dur || dur < 100) dur = 300;
    if(dur > 1000) dur = 1000;
    modal.style.setProperty('--up-anim-dur', dur + 'ms');
  }
```

- [ ] **Step 2: Call it in `attach()`**

In `attach()`, right after `var modal = buildModalShell(isVideo, popupStyle);` (~line 483), add:

```javascript
    var modal = buildModalShell(isVideo, popupStyle);
    applyAnimation(modal, sn);
```

- [ ] **Step 3: Verify the classes appear in the DOM**

In a WP dev environment (with `SCRIPT_DEBUG` true so source JS loads), open a page with a published popup whose animation = Slide / direction = From left. Trigger the popup, then in DevTools inspect the `.up-modal` element.
Expected: the element has classes `up-anim-slide up-anim-dir-left` and an inline style `--up-anim-dur: 500ms` (or whatever was set). A popup left at Auto on a centered modal shows `up-anim-zoom`; on a drawer-right shows `up-anim-slide up-anim-dir-right`.

- [ ] **Step 4: Commit**

```bash
git add anchor-universal-popups/assets/frontend.js
git commit -m "feat(popups): apply animation classes and duration var to modal"
```

---

### Task 4: CSS transition system (replace drawer/fly-in keyframes)

Adds the reversible entrance/exit CSS driven by the classes from Task 3 and the `is-open` class from Task 5, and removes the old `@keyframes`. The animated element's transform is composed from `--up-pos-tx` (position) and `--up-enter-tx` (entrance). After this task, drawers/fly-ins still position correctly but their open transition won't fire until Task 5 adds `is-open` — verify positioning here, motion in Task 5.

**Files:**
- Modify: `anchor-universal-popups/assets/frontend.css` — drawer blocks (~line 159-222), fly-in blocks (~line 227-313), and the `@keyframes` block (~line 315-330).

**Interfaces:**
- Consumes: classes `up-anim-*`, `up-anim-dir-*` (Task 3), `is-open` / `is-closing` (Task 5), and the `--up-anim-dur` variable (Task 3).
- Produces: CSS custom properties `--up-pos-tx`, `--up-enter-tx`, `--up-slide-dist`, `--up-anim-ease` and the visual transitions.

- [ ] **Step 1: Convert drawer rules to set position-transform + slide distance (remove `animation`)**

In `anchor-universal-popups/assets/frontend.css`, in the three drawer blocks, replace the `transform` + `animation` declarations. For **drawer-right** (~line 172-173) replace:

```css
  transform: translateX(100%);
  animation: up-slide-left .3s ease forwards;
```
with:
```css
  --up-slide-dist: 100%;
```

For **drawer-left** (~line 196-197) replace:
```css
  transform: translateX(-100%);
  animation: up-slide-right .3s ease forwards;
```
with:
```css
  --up-slide-dist: 100%;
```

For **drawer-bottom** (~line 220-221) replace:
```css
  transform: translateY(100%);
  animation: up-slide-up .3s ease forwards;
```
with:
```css
  --up-slide-dist: 100%;
```

- [ ] **Step 2: Convert fly-in shared rule to position-transform + slide distance**

In the shared fly-in dialog/content rule (~line 260-261) replace:
```css
  transform: translateY(calc(100% + 40px));
  animation: up-flyin-up .35s cubic-bezier(.22, .61, .36, 1) forwards;
```
with:
```css
  --up-slide-dist: calc(100% + 40px);
```

In the fly-in **bottom center** rule (~line 276-277) replace:
```css
  transform: translate(-50%, calc(100% + 40px));
  animation: up-flyin-up-center .35s cubic-bezier(.22, .61, .36, 1) forwards;
```
with:
```css
  --up-pos-tx: translateX(-50%);
```

(Leave the `left: 50%; right: auto;` lines in that rule intact — only the `transform`/`animation` lines change.)

- [ ] **Step 3: Remove the old keyframes**

Delete the entire `/* ── Animations ── */` block (~line 315-330):
```css
/* ── Animations ── */
@keyframes up-slide-left { to { transform: translateX(0); } }
@keyframes up-slide-right { to { transform: translateX(0); } }
@keyframes up-slide-up { to { transform: translateY(0); } }
@keyframes up-flyin-up { to { transform: translateY(0); } }
@keyframes up-flyin-up-center { to { transform: translate(-50%, 0); } }
```

- [ ] **Step 4: Add the unified animation system**

Append this block at the end of `anchor-universal-popups/assets/frontend.css`:

```css
/* ==========================================================================
   Open/close animation system
   Animated element transform = position layer + entrance layer, kept separate
   so positioning (e.g. fly-in centering) and animation don't collide.
   Gated behind an up-anim-* class; popups without one keep instant behavior.
   NOTE: the base rule must NOT assign --up-pos-tx / --up-slide-dist / --up-anim-ease.
   Those are owned by the lower-specificity drawer/fly-in style rules; the base
   rule (higher specificity) would clobber them. Consume them via var(...,fallback).
   ========================================================================== */
.up-modal[class*="up-anim-"]:not(.up-anim-none) .up-modal__dialog,
.up-modal[class*="up-anim-"]:not(.up-anim-none) .up-content-wrap {
  opacity: 0;
  transform: var(--up-pos-tx, translate(0,0)) var(--up-enter-tx, translate(0,0));
  transition: opacity var(--up-anim-dur, 300ms) var(--up-anim-ease, ease),
              transform var(--up-anim-dur, 300ms) var(--up-anim-ease, ease);
  will-change: opacity, transform;
}
.up-modal.is-open[class*="up-anim-"]:not(.up-anim-none) .up-modal__dialog,
.up-modal.is-open[class*="up-anim-"]:not(.up-anim-none) .up-content-wrap {
  opacity: 1;
  --up-enter-tx: translate(0, 0);
}

/* Backdrop cross-fade on the animated, non-fly-in path */
.up-modal[class*="up-anim-"]:not(.up-anim-none) .up-modal__backdrop {
  opacity: 0;
  transition: opacity var(--up-anim-dur, 300ms) ease;
}
.up-modal.is-open[class*="up-anim-"]:not(.up-anim-none) .up-modal__backdrop {
  opacity: 1;
}

/* Entrance offsets per animation (closed state) */
.up-modal.up-anim-zoom .up-modal__dialog,
.up-modal.up-anim-zoom .up-content-wrap { --up-enter-tx: scale(.94); }
.up-modal.up-anim-flyin .up-modal__dialog,
.up-modal.up-anim-flyin .up-content-wrap { --up-anim-ease: cubic-bezier(.22, .61, .36, 1); }

/* fade: opacity only (no enter offset, the default translate(0,0)) */

/* Direction offsets for slide/fly-in (direction = edge it comes FROM) */
.up-modal.up-anim-dir-up    .up-modal__dialog,
.up-modal.up-anim-dir-up    .up-content-wrap { --up-enter-tx: translateY(calc(-1 * var(--up-slide-dist, 24px))); }
.up-modal.up-anim-dir-down  .up-modal__dialog,
.up-modal.up-anim-dir-down  .up-content-wrap { --up-enter-tx: translateY(var(--up-slide-dist, 24px)); }
.up-modal.up-anim-dir-left  .up-modal__dialog,
.up-modal.up-anim-dir-left  .up-content-wrap { --up-enter-tx: translateX(calc(-1 * var(--up-slide-dist, 24px))); }
.up-modal.up-anim-dir-right .up-modal__dialog,
.up-modal.up-anim-dir-right .up-content-wrap { --up-enter-tx: translateX(var(--up-slide-dist, 24px)); }

/* Reduced motion: collapse to a quick fade, no movement */
@media (prefers-reduced-motion: reduce) {
  .up-modal[class*="up-anim-"]:not(.up-anim-none) .up-modal__dialog,
  .up-modal[class*="up-anim-"]:not(.up-anim-none) .up-content-wrap {
    --up-enter-tx: translate(0, 0);
    transition: opacity 120ms ease;
  }
}
```

- [ ] **Step 5: Verify positioning is intact (motion comes in Task 5)**

Run: `git grep -n "@keyframes up-" anchor-universal-popups/assets/frontend.css`
Expected: no matches (old keyframes removed).

Then in a WP dev environment (`SCRIPT_DEBUG` true), trigger each style: drawer-right/left/bottom and fly-in center/left/right. Because `is-open` isn't toggled yet, the dialog starts at `opacity:0` — to verify *positioning only*, temporarily add the `is-open` class in DevTools (`document.querySelector('.up-modal').classList.add('is-open')`) and confirm each style sits in its correct resting position (drawer flush to its edge, fly-in center horizontally centered, fly-ins anchored at corners).
Expected: every style rests in the same position as before this change.

- [ ] **Step 6: Commit**

```bash
git add anchor-universal-popups/assets/frontend.css
git commit -m "refactor(popups): replace drawer/flyin keyframes with unified transition system"
```

---

### Task 5: Open/close lifecycle (is-open / is-closing) in JS

Toggles `is-open` on reveal and runs a reverse transition + deferred teardown on close, making the animations actually play. This is the task that turns the CSS from Task 4 into visible entrance + exit motion.

**Files:**
- Modify: `anchor-universal-popups/assets/frontend.js` — `revealModal()` (~line 280) and `closeModal()` (~line 415).

**Interfaces:**
- Consumes: presence of an `up-anim-*` class (set in Task 3) other than `up-anim-none`; `--up-anim-dur` for the teardown fallback timeout.
- Produces: `is-open` / `is-closing` class toggles read by Task 4's CSS. No new exported functions.

- [ ] **Step 1: Add an animation-detection + duration helper**

In `anchor-universal-popups/assets/frontend.js`, add near the other small helpers (e.g. just above `function revealModal(modal){`, ~line 280):

```javascript
  // True when the modal has an entrance animation that is not 'none'.
  function isAnimated(modal){
    return /(^|\s)up-anim-(?!none)/.test(modal.className);
  }
  // Resolve the configured duration (ms) for teardown timing, default 300.
  function animDuration(modal){
    var v = getComputedStyle(modal).getPropertyValue('--up-anim-dur').trim();
    var ms = parseFloat(v);
    if(/ms$/.test(v)) return ms || 300;
    if(/s$/.test(v)) return (ms || 0.3) * 1000;
    return 300;
  }
```

- [ ] **Step 2: Toggle `is-open` in `revealModal()`**

Replace the body of `revealModal()` (~line 280-287):

```javascript
  function revealModal(modal){
    modal._openedAt = nowTs();
    modal._closing = false;
    if(modal._closeTimer){ clearTimeout(modal._closeTimer); modal._closeTimer = null; }
    modal.hidden = false;
    var closeBtn = modal.querySelector('.up-modal__close');
    if(closeBtn){ closeBtn.focus(); }
  }
```

with:

```javascript
  function revealModal(modal){
    modal._openedAt = nowTs();
    modal._closing = false;
    if(modal._closeTimer){ clearTimeout(modal._closeTimer); modal._closeTimer = null; }
    modal.classList.remove('is-closing');
    modal.hidden = false;
    if(isAnimated(modal)){
      // Commit the closed (opacity:0 / offset) state, then transition to open.
      void modal.offsetWidth;
      requestAnimationFrame(function(){ modal.classList.add('is-open'); });
    } else {
      modal.classList.add('is-open');
    }
    var closeBtn = modal.querySelector('.up-modal__close');
    if(closeBtn){ closeBtn.focus(); }
  }
```

- [ ] **Step 3: Add a teardown helper and animate the close in `closeModal()`**

In `closeModal()` (~line 415-440), the current tail clears content and sets `modal.hidden = true`. Refactor so that clearing/hiding happens in a `finalize` closure that runs after the exit transition. Replace the section from `if(modal._closing) return;` to the end of the function:

```javascript
    if(modal._closing) return;

    function finalize(){
      // Stop playback / clear under-content, re-arming the preload if present.
      var f = modal.querySelector('[data-frame]');
      if(f){
        if(modal._preloaded && modal._preloadSrc){
          var iframe = f.querySelector('iframe');
          if(iframe){ iframe.src = modal._preloadSrc; }
          else { f.innerHTML = videoIframe(modal._preloadSrc); }
        } else {
          f.innerHTML = '';
        }
      }
      var a = modal.querySelector('[data-after]');
      if(a) a.innerHTML = '';
      modal.hidden = true;
      modal.classList.remove('is-closing');
      modal._closing = false;
      if(modal._closeTimer){ clearTimeout(modal._closeTimer); modal._closeTimer = null; }
    }

    if(!isAnimated(modal)){
      modal.classList.remove('is-open');
      finalize();
      return;
    }

    // Play the exit: reverse the entrance, then tear down once it ends.
    modal._closing = true;
    modal.classList.remove('is-open');
    modal.classList.add('is-closing');
    var dialog = modal.querySelector('.up-modal__dialog, .up-content-wrap');
    var done = false;
    function onEnd(e){
      if(e && e.target !== dialog) return; // ignore bubbled child transitions
      if(done) return; done = true;
      if(dialog){ dialog.removeEventListener('transitionend', onEnd); }
      finalize();
    }
    if(dialog){ dialog.addEventListener('transitionend', onEnd); }
    // Fallback in case transitionend doesn't fire (e.g. element removed early).
    modal._closeTimer = setTimeout(onEnd, animDuration(modal) + 80);
```

(Leave the earlier part of `closeModal()` — the `if(modal.hidden) return;` guard and the `if(isFullscreen(modal)){ collapseTakeover(modal); return; }` branch — unchanged; this replacement starts at the `if(modal._closing) return;` line.)

- [ ] **Step 4: Verify entrance + exit motion for every style/animation**

In a WP dev environment (`SCRIPT_DEBUG` true), test the matrix:
- Centered **modal**, Auto → opens with a zoom+fade, closes reversing it.
- Modal with explicit **fade**, **slide** (each direction), **flyin** → correct motion in and out; duration field changes the speed.
- **none** → opens/closes instantly (no transition).
- **drawer-right/left/bottom** at Auto → slide in from their edge and slide back out (matching pre-change feel).
- **fly-in center/left/right** at Auto → fly up into place with overshoot and retract on close.
- Open then close rapidly, and re-open immediately → no stuck `opacity:0`, no double-teardown, content (video/iframe) clears on close and reloads on reopen.

Expected: all animate in and out; close fully tears down (popup hidden, content cleared); reopen works.

- [ ] **Step 5: Verify untouched paths still work**

- **Fullscreen takeover**: place the `[anchor_popup id=...]` shortcode for a fullscreen-style video popup, scroll it into view → it still expands from the thumbnail and collapses on scroll-past (this path never gets an `up-anim-*` class and routes through `collapseTakeover`).
- Backdrop click and the × close button both close with the exit animation.
- `prefers-reduced-motion` (enable "Reduce motion" in OS settings): popups fade quickly without movement.

Expected: fullscreen takeover unchanged; all close affordances work; reduced-motion respected.

- [ ] **Step 6: Commit**

```bash
git add anchor-universal-popups/assets/frontend.js
git commit -m "feat(popups): animate open/close lifecycle via is-open/is-closing"
```

---

### Task 6: Update module readme / changelog

Documents the new capability for users. Small but worth its own commit so the feature ships described.

**Files:**
- Modify: `anchor-universal-popups/readme.txt` — changelog/description.

**Interfaces:** none.

- [ ] **Step 1: Add a changelog entry**

In `anchor-universal-popups/readme.txt`, add a short note under the changelog/description describing the new **Open Animation** control (Auto / None / Fade / Zoom / Slide / Fly-in), the Direction and Duration options, that Auto preserves existing behavior, and that `prefers-reduced-motion` is respected. Match the file's existing formatting.

- [ ] **Step 2: Commit**

```bash
git add anchor-universal-popups/readme.txt
git commit -m "docs(popups): document open/close animation settings"
```

---

## Notes for the implementer

- **Local testing requires `SCRIPT_DEBUG`**: the asset loader serves the gitignored `.min` files by default. Define `define('SCRIPT_DEBUG', true);` in `wp-config.php` on the dev site (or delete the local `anchor-universal-popups/assets/*.min.*`) so your source edits are actually served. CI rebuilds the `.min` files at release.
- **Do not** edit, `git add`, or commit any `*.min.css` / `*.min.js` — they're gitignored and CI-built.
- **Do not** bump the plugin `Version:` header in these tasks; that's a release-time step.
- The `.up-content-wrap` element is the animated element for html/shortcode popups; `.up-modal__dialog` is used for video popups. Both are targeted everywhere it matters.
