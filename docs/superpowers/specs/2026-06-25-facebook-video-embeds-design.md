# Facebook Video Embeds — Design

**Date:** 2026-06-25
**Modules:** `anchor-gallery`, `anchor-universal-popups`
**Status:** Approved design

## Goal

Add Facebook videos and reels as a supported video source in both the video
gallery/slider (`anchor-gallery`, `[anchor_video_slider]` / `[anchor_video_gallery]`)
and the video pop-up (`anchor-universal-popups`), alongside the existing YouTube
and Vimeo providers.

## Background

Both modules independently implement the same three-stage video pipeline:

1. **Parse** a pasted URL into `{provider, id}` — currently only `youtube` and
   `vimeo` (`normalize_video_url` in gallery, `parse_video_url` in popups).
2. **Fetch metadata** (thumbnail, title) via the YouTube Data API / Vimeo
   oEmbed. `image` and `html` providers are skipped here.
3. **Build an iframe** `src` from `provider` + `id` on the frontend
   (`getVideoSrc` in gallery JS; the popup equivalent), consumed by every popup
   style (lightbox, theater, side-panel, inline).

This duplication across the two modules is intentional in the codebase. This
feature follows the same pattern rather than introducing a shared abstraction.

## Decisions (from brainstorming)

- **Thumbnails:** manual upload only. Facebook offers no public thumbnail URL
  and its oEmbed now requires a Facebook App token. Each Facebook video gets a
  media-library thumbnail picker. No API setup required.
- **Scope:** both modules.
- **Orientation:** per-video aspect field — `landscape` (16:9), `vertical`
  (9:16), `square` (1:1) — because Facebook mixes vertical reels with landscape
  videos.
- **Content types:** video + reels only (not photos/posts).
- **Autoplay:** when autoplay is enabled the Facebook player is muted
  (browser + Facebook requirement); acceptable.

## Approach

**Approach A — add a `facebook` provider inline in each module.** Mirror the
existing `youtube`/`vimeo` handling in both `anchor-gallery` and
`anchor-universal-popups` separately. Lowest risk, consistent with the existing
(deliberately duplicated) structure. Rejected alternatives: extracting a shared
provider helper (refactor of working code, out of scope) and a hybrid of the
two.

## Data Model

New provider `facebook`, stored per video:

| Field     | Meaning                                                            |
|-----------|-------------------------------------------------------------------|
| `provider`| `'facebook'`                                                      |
| `url`     | Canonical Facebook video/reel URL. Facebook's embed requires the full URL, so we store the URL rather than an extracted ID. |
| `thumb`   | Attachment ID / URL from the manual media-library picker.         |
| `aspect`  | `landscape` \| `vertical` \| `square` (per video).                |
| `label`   | Optional user-entered title (no API to fetch one; blank is fine). |

In the gallery, videos are entries in the serialized `avg_videos` meta array; in
popups they live in that module's existing video config. Facebook entries carry
`url` where YouTube/Vimeo entries carry `id`.

## URL Parsing

Add a Facebook branch to `normalize_video_url` (gallery) and `parse_video_url`
(popups). Matched forms:

- `facebook.com/reel/{id}`
- `facebook.com/watch/?v={id}` and `facebook.com/watch?v={id}`
- `facebook.com/{page}/videos/{id}`
- `facebook.com/share/v/{id}`
- `fb.watch/{shortcode}`
- already-formed `facebook.com/plugins/video.php?href={encoded}` (unwrap to the
  inner `href`)

Returns `{provider: 'facebook', url: <canonical url>}`. The match runs after the
YouTube and Vimeo checks.

## Metadata Path

Facebook is skipped in the YouTube/Vimeo metadata fetch loops, exactly as
`image` and `html` already are. Thumbnail comes from the manual `thumb` field;
title from `label`. No network calls for Facebook.

## Frontend Rendering

Add `buildFacebookSrc(url, autoplay, aspect)` to the gallery JS `getVideoSrc`
and the popup equivalent:

```
https://www.facebook.com/plugins/video.php?href={encodeURIComponent(url)}&show_text=false&autoplay={0|1}
```

- When autoplay is on, append `&mute=1` (Facebook autoplay only fires muted).
- The player frame's `aspect-ratio` CSS is driven by the per-video `aspect`
  (`landscape` → 16/9, `vertical` → 9/16, `square` → 1/1).
- All existing popup styles (lightbox, theater, side-panel, inline) work
  unchanged because they consume `getVideoSrc`.

`getDirectUrl` (used for fallback links) returns the stored Facebook `url`.

## Admin UI

In each module's per-video inspector/row: when a pasted URL resolves to
`facebook`, reveal a **Thumbnail** media picker and an **Orientation** select
(Landscape / Vertical / Square). Bulk URL paste continues to work; Facebook rows
simply need a thumbnail set afterward. Mirror each module's existing field
styling and JS conventions (jQuery IIFE, `wp.media` for the picker).

## Preconnect / Performance

Add `https://www.facebook.com` to the existing preconnect / lazy-preload hints
in both modules, alongside the current YouTube/Vimeo hints.

## Out of Scope

- Facebook photos and text posts.
- Automatic thumbnail/title fetching via Facebook App token or oEmbed.
- Extracting a shared cross-module video-provider helper.

## Testing

No automated suite exists; manual verification in WordPress:

1. Paste a reel URL → set vertical orientation + thumbnail → renders vertically
   in tile and in each popup style.
2. Paste a landscape `watch?v=` URL → 16:9 in tile and popups.
3. Mixed gallery (YouTube + Vimeo + Facebook) renders correctly; YouTube/Vimeo
   thumbnails/titles still auto-fetch.
4. Autoplay-on popup opens muted and plays.
5. Bulk paste including a Facebook URL produces a Facebook row needing a
   thumbnail.
