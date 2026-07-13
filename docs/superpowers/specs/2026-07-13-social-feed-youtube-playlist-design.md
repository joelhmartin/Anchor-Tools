# Social Feed — YouTube Playlist Source

**Date:** 2026-07-13
**Module:** `anchor-social-feed/anchor-social-feed.php`
**Status:** Approved for implementation

## Problem

The Social Feed module's YouTube source can only pull a channel's recent uploads, then narrow them with hashtag, duration, type, and date filters. There is no way to point a feed at a specific YouTube playlist. Curating a playlist in YouTube is the natural way for a site owner to control exactly which videos appear and in what order, and the YouTube Data API already supports it through the same endpoint the module uses today.

## Current Behavior

`render_youtube_api_feed()` resolves the configured channel to its hidden "uploads" playlist via the `channels` endpoint (`contentDetails.relatedPlaylists.uploads`), pages through `playlistItems` for that playlist, hydrates each video through the `videos` endpoint, applies filters, sorts newest-first, and renders.

The paging against `playlistItems` is therefore already playlist-generic — only the playlist ID is hardcoded to the uploads playlist.

## Design

### New setting

One new meta key: `asf_yt_playlist_id`, a text field labeled **Playlist ID or URL**, rendered in the YouTube block of the Feed Source metabox immediately below Channel ID.

- Empty (the default): behavior is identical to today. This feature is fully backward-compatible; existing feed posts are untouched.
- Populated: videos are fetched from that playlist instead of the channel's recent uploads.

The Channel ID field is retained and continues to drive the profile header (avatar, subscriber count, channel link). A playlist feed with no channel set still renders; it simply has no channel-derived header data.

### Input parsing

`ytapi_parse_playlist_id( string $input ): string`

Accepts, in order:

1. A raw playlist ID — `[A-Za-z0-9_-]{12,}` (covers `PL…`, `UU…`, `FL…`, `OL…`, and legacy forms).
2. Any URL containing a `list=` query parameter — a playlist page URL (`youtube.com/playlist?list=…`) or a watch URL (`youtube.com/watch?v=…&list=…`). Parsed with `wp_parse_url()` + `wp_parse_str()`, not a bare regex.

Returns `''` when the input matches neither. An unparseable value surfaces the note *"That doesn't look like a YouTube playlist ID or URL."* rather than silently rendering an empty feed.

### Fetching

Extract the `playlistItems` paging loop out of `ytapi_get_uploads()` into:

`ytapi_get_playlist_items( string $playlist_id, string $api_key, int $pages ): array|WP_Error`

Returns items in **API order**, which for `playlistItems` is playlist position order.

- `ytapi_get_uploads()` keeps its signature, now resolving the uploads playlist and delegating to the new method, then applying its existing newest-first `usort()`. Channel behavior is unchanged.
- Playlist mode calls `ytapi_get_playlist_items()` directly and **does not sort** — the order curated in YouTube is preserved.

Caching is unchanged in shape: per-page transients keyed on playlist ID + page token, 10 minutes, bustable with `?ssfs_refresh=1`. Because the cache key is derived from the playlist ID, playlist pages and uploads pages never collide.

Private and deleted playlist entries are dropped naturally: they return no row from the `videos` details lookup, and the render loop already skips items with no matching detail record.

### Filtering

**In playlist mode, the content filters are skipped entirely.** A playlist is already a hand-picked list; re-filtering it would surprise the user. Specifically skipped: Video Type, Include/Exclude Hashtags, Min/Max Duration, Since/Until, Max Age.

This is a correctness requirement, not a preference: the shipped defaults are Video Type = `videos` (drops anything ≤65s) and Exclude Hashtags = `short,shorts,testimonial`. Applying them to a playlist would silently delete videos the user deliberately added.

Two settings still apply in playlist mode:

- **Item Limit** — a display cap, not a content filter.
- **Fetch Pages** — controls how many 50-item pages are pulled.

### Admin UI

The uploads-only filter defs are grouped in a wrapper element (`.asf-yt-uploads-only`) in the metabox, with a note: *"Not used when a playlist is set."* Admin JS dims the group when the playlist field is non-empty, toggling live on input. Fetch Pages sits outside the group, since it applies to both modes.

Implementation: tag the relevant defs in `get_youtube_setting_defs()` with `'uploads_only' => true` and render them into the wrapper; untagged defs render outside it.

### Shortcode

New `playlist_id` attribute on `[anchor_social_feed]`:

```
[anchor_social_feed platform="youtube" playlist_id="PLxxxxxxxx"]
```

It works standalone (no CPT post required) and overrides a post's configured playlist when passed alongside `id`/`slug`.

### Plumbing

`asf_yt_playlist_id` / `playlist_id` must be threaded through:

| Location | Change |
|---|---|
| `get_youtube_setting_defs()` | not added here — rendered explicitly beside Channel ID (mirrors `asf_yt_channel_id`) |
| `metabox_source()` | render the field under Channel ID; wrap uploads-only defs |
| `save_meta()` | add `asf_yt_playlist_id` to `$text_fields` |
| `build_opts_from_post()` | add to `$meta_keys`; map to `youtube_playlist_id` |
| `build_atts_from_post()` | add to `$meta_keys`; map to `playlist_id` |
| `shortcode_handler()` | add `'playlist_id' => ''` to `shortcode_atts` |
| legacy defaults arrays (~L870, ~L1060) | add `'playlist_id' => ''` |
| `render_youtube_api_feed()` | branch on playlist |
| admin CSS/JS | dim group; bump enqueue version strings |

### API guard

The existing guard requires an API key *and* a channel ID before it will call the API. It is relaxed to require an API key and **either** a channel ID or a playlist ID, so a playlist-only feed renders.

## Non-Goals

- No new API endpoints and no added quota cost — `playlistItems` is the same endpoint already in use, aimed at a different playlist.
- No playlist picker/browser UI. Paste an ID or URL.
- No multi-playlist merging.
- No changes to Facebook, Instagram, TikTok, X, or Spotify sources.

## Verification

There is no automated test suite in this repo; verification is `php -l` plus manual checks in WordPress:

1. Existing YouTube feed post with no playlist → renders identically to before (regression check).
2. Playlist URL pasted → videos appear in playlist order, including any Shorts, with default filters left untouched.
3. Raw `PL…` ID → same result as the URL form.
4. Garbage in the playlist field → the "doesn't look like a playlist" note, not an empty feed.
5. Playlist containing a private/deleted video → that entry is skipped, the rest render.
6. `[anchor_social_feed platform="youtube" playlist_id="PL…"]` with no CPT post → renders.
7. Item Limit is respected; the uploads-only filter group dims when a playlist is set.
