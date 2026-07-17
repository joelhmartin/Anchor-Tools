# Gallery Lightbox Navigation — Design

**Date:** 2026-07-17
**Module:** `anchor-gallery/`
**Status:** Approved, pending implementation plan

## Goal

Clicking any item in an Anchor Gallery opens a lightbox that lets the user move through every visible item in that gallery — forward and back, by arrow, keyboard, or swipe. Videos stop playing the moment you navigate away from them.

## Current State (verified 2026-07-17)

Facts established by reading the module, not assumed:

- The module is `anchor-gallery/`, not `anchor-video-slider/`. The CPT is `anchor_video_gallery`; assets are still named `anchor-video-slider.{css,js}`.
- The frontend JS is **vanilla ES5 in two IIFEs — not jQuery.** jQuery appears only as a duck-type check (`container.jquery`) at `anchor-video-slider.js:1068` so the admin preview can pass a jQuery object.
- Items have three types: `video`, `image`, `html` (`anchor-gallery.php:1254`).
- Videos are **YouTube and Vimeo only**. `normalize_video_url()` (`anchor-gallery.php:2847`) returns `null` for anything else. There is no self-hosted mp4 support and no `<video>` element in the module.
- There are **four independent popup mechanisms**, not one: lightbox (`js:53-147`), theater (`js:149-209`), side panel (`js:211-292`), inline expand (`js:294-359`), plus a legacy modal (`js:1081-1138`).
- **No popup navigates between items.** `openLightbox(provider, id, autoplay, opts)` (`js:105`) receives a single video's identity and never learns its gallery or index. The modal markup (`js:64-70`) is backdrop + close + frame — no prev/next.
- Swipe exists **only on the carousel/slider track** (`js:623-652`), driving `scrollSlider()`. No popup has touch handling.
- The only popup keyboard handling is Escape (`js:365-379`). No arrow keys.
- **Playback is a raw iframe with `autoplay=1` in the query string** (`js:8-16`, `:114`). The YouTube IFrame Player API is not loaded; there is no `enablejsapi=1`, no `YT.Player`, no `postMessage`, no `.pause()` anywhere in the module.
- Videos are stopped **by destroying the iframe** — `frame.innerHTML = ''` (`js:141-142`). This already works on close.

### Visibility mechanism (verified — initial assumption was wrong)

The initial design assumed one shared "hidden" class. That is false:

- Filterable grid hides via **`.is-hidden`** (`js:1157`), CSS-scoped to `.avg-layout-filterable` (`css:1517`).
- Pagination hides via **`.avg-hidden`** (`js:888`, and server-rendered at `anchor-gallery.php:2220,2236,2244`), CSS at `css:85`.
- Carousel/slider off-screen tiles use **neither** — they keep real layout boxes and scroll out of view (`css:570-582`); only an `active` styling flag toggles.

Both hiding paths resolve to `display: none`. Therefore **`tile.offsetParent !== null`** is the single test that catches filtered and paginated tiles uniformly, without hardcoding either class, and correctly does not classify carousel tiles as hidden.

The known caveat — `offsetParent` is also null when an *ancestor* is `display:none`, e.g. a gallery inside a collapsed tab — is moot here, because the sequence is built at click time and a tile inside a collapsed tab cannot be clicked.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Which popup styles navigate | **Lightbox only** | Theater, side panel, inline keep current single-item behavior. Lightbox is what users expect to behave like a photo viewer and is what the `lightbox_grid` preset forces. |
| What's in the sequence | **All items, in on-page order** | Videos, images, and HTML tiles alike. Behaves like a native gallery — you swipe through what you see. |
| Filters & pagination | **Only what's visible** | With a filter active, only matching items. On a paginated grid, only the current page. |
| End behavior | **Wrap around** (item 12 → item 1) | Standard for galleries; expected on mobile. |
| Sequence source | **DOM at click time** | Honors filter/pagination state for free. |

## Architecture

### Chosen approach: build the sequence from the DOM at click time

Rejected alternatives:

- **PHP emits a JSON item payload per gallery.** More authoritative, but the server doesn't know filter state or current page, so it would have to be reconciled against the DOM anyway — which defeats the "only what's visible" decision.
- **Adopt PhotoSwipe / GLightbox.** Swipe and a11y for free, but adds a dependency to a repo whose convention is raw un-bundled JS, and it knows nothing about the existing YouTube/Vimeo embed logic. It would be wrapped more than used.

### Components

**`collectSequence(container)`** — given the `.anchor-video-gallery` container, return an ordered array of item descriptors for tiles that are navigable and currently visible (`offsetParent !== null`).

**`readTile(tile)`** — map one tile element to a descriptor:

```js
{
  type: 'video' | 'image' | 'html',
  provider, videoId, url,   // video  — from data-provider/data-video-id/data-url
  fullUrl, alt,             // image  — from data-full-url
  node,                     // html   — the tile's inner content, cloned
  caption
}
```

Two collectors are needed because the markup differs:

- **Standard collector** — `.avg-tile` elements from `render_output()`. Covers grid, masonry, slider, carousel, and the presets that rewrite onto them.
- **Gallery collector** — `render_gallery_layout()` emits `.avg-gallery-thumb` strip items plus one `.avg-tile.avg-gallery-featured`. Sequence comes from the thumb strip; start index is the active thumb.

`.avg-tile-linked` (an `<a>`) is excluded — it exists only when `popup_style === 'none'`, which never coexists with a lightbox, but is guarded anyway.

**`openLightbox(sequence, startIndex, opts)`** — replaces the current single-identity signature. This is an internal function; the legacy `.anchor-video-tile` handler and the admin preview use other entry points, so it is not a public break.

**`showItem(i)`** — the heart of the design:

```
showItem(i):
    frame.innerHTML = ''      // destroys the iframe → video stops
    render sequence[i] into frame
    update counter, caption, arrow state
```

### The video-stop guarantee

Because playback is a raw autoplay iframe, **destroying the frame is stopping the video.** Routing swipe, arrow-click, keyboard, and close all through `showItem` / `close` means there is exactly one teardown path and no way to leak audio. No YouTube IFrame API, no `postMessage`, no player objects, no per-provider special-casing.

This is the single most important property of the design: the requirement is satisfied structurally rather than by remembering to stop playback at each call site.

### Swipe

Reuses the carousel's proven pattern (`js:623-652`): 40px threshold, abort when vertical movement dominates so page scroll still works.

**Known constraint — touch events inside a cross-origin iframe do not bubble to the parent page.** A swipe that starts on the video picture itself cannot be detected. This is a browser security boundary, not a fixable defect. It affects videos only; images swipe edge to edge.

Mitigation (the same approach YouTube and PhotoSwipe take): the dialog has swipe-active gutters around the frame, and prev/next arrows remain visible on touch devices rather than being desktop-only. On mobile, images swipe anywhere; videos swipe from the margins or use the arrows.

### View coverage

| View | Covered | Mechanism |
|---|---|---|
| grid, masonry, slider, carousel | Yes | Standard collector |
| paginated, bento, card_carousel, lightbox_grid | Yes | Presets rewriting onto grid/carousel — same tiles |
| filterable | Yes | Same tiles; `offsetParent` respects `.is-hidden` |
| gallery, thumbnail_gallery | Yes | Gallery collector |
| logo_carousel | **No** | Emits `.avg-marquee-item` with no data attributes and no click behavior. It is a logo marquee, not a viewer. |

Only galleries with `popup_style = lightbox` get this behavior.

## Accessibility (folded in)

- `.avg-tile` currently has no `tabindex` or `role="button"` — the primary grid path is unreachable by keyboard. Add both so keyboard users can open the viewer. (Only `render_gallery_layout()`'s featured tile has them today, at `anchor-gallery.php:2530`.)
- Arrow-key navigation wired into the existing keydown handler (`js:365`).
- Focus trap while the modal is open; restore focus to the originating tile on close.

## Out of Scope

- **Theater, side panel, inline expand** — keep current single-item behavior per the scope decision.
- **`logo_carousel`** — no clickable items to navigate.
- **The inline-mode audio leak.** A playing inline video is a child of `.avg-track`, so a carousel advance or autoplay tick (`js:697`) translates it offscreen with audio still playing. Real bug, adjacent, but lives in a popup style scoped out of this work. Noted for a follow-up.

## Testing

PHPUnit does not apply — this is entirely client-side. Coverage goes in the existing Playwright E2E suite:

- Open the lightbox from a grid tile; assert correct start index.
- Navigate forward and back; assert the counter and rendered item.
- Wrap at both ends (last → first, first → last).
- **Assert the previous iframe is removed from the DOM after navigating** — this is the video-stop guarantee.
- Swipe via touch emulation on an image item.
- Filtered gallery: navigation covers only matching items.
- Paginated grid: navigation covers only the current page.
- Gallery layout: opening the featured tile starts at the active thumb.
- Keyboard: arrows navigate, Escape closes, focus returns to the originating tile.
