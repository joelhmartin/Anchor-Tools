# Anchor Testimonials

Authored patient and professional testimonials (quotes and/or videos), scoped by an audience taxonomy and an optional "related to" list of post IDs (courses, pages, speakers, events). Registry key `testimonials`, class `Anchor_Testimonials_Module`, dir `anchor-testimonials/`. Off by default like other modules.

## Data model

CPT `anchor_testimonial`: `public => false`, `publicly_queryable => false`, `show_ui => true` (no front-end single URL or archive). Supports `title` (internal label), `editor` (the quote, optional), `thumbnail` (person photo), `page-attributes` (`menu_order` for manual sort). `show_in_menu` is filterable via `anchor_testimonials_parent_menu`.

Taxonomy `anchor_testimonial_audience`: non-hierarchical, admin-only (`public => false`, `show_ui => true`, `show_admin_column => true`, `rewrite => false`). Seeded once with `patient` and `doctor` terms; sites can add more.

A testimonial is considered "incomplete" (admin notice on the edit screen only, not a save block) when it has neither quote text nor a video.

### Meta (prefix `_at_`)

| Key | Type | Notes |
|---|---|---|
| `_at_person_name` | string | |
| `_at_person_meta` | string | e.g. "Patient, Denver" or "DDS, Auburn, CA" |
| `_at_video_url` | string | Raw URL as entered, `esc_url_raw()`'d. Cleared to `''` if it doesn't parse as a YouTube/Vimeo URL. |
| `_at_video_provider` | string | `youtube` \| `vimeo` \| `''`, from `Anchor_Video_URL::parse()`. |
| `_at_video_id` | string | Parsed video id, or `''`. |
| `_at_video_start` | int | Parsed start-time offset in seconds (from a `t=`/`start=` query param), or `0`. |
| `_at_video_thumb` | string | Resolved poster URL from `Anchor_Video_URL::thumbnail()` (YouTube: static `i.ytimg.com` URL; Vimeo: oEmbed lookup cached in a transient), or `''`. |
| `_at_rating` | int | Clamped `0`-`5`. `0` means no rating shown. |
| `_at_featured` | `'1'` or absent | Checkbox; the key is deleted (not stored as `''`) when unchecked. |
| `_at_related` | array of int | Post IDs from the "Related to" picker, deduplicated. Any post type: an Anchor Events group parent or occurrence, a page, a speaker, etc. |
| `_at_related_id` | one row per related ID (string) | Written alongside `_at_related` so a single ID can be matched with a plain `meta_query` `IN`/`=` comparison; existing rows are deleted and rewritten on every save. |

## Admin

One metabox, "Testimonial details": person name/meta, video URL (with a live thumbnail preview loaded by admin JS), rating, featured checkbox, and a "Related to" picker. The picker is an AJAX search (`wp_ajax_anchor_testimonials_search_posts`) across every public post type plus `event` and `anchor_speaker` when those post types exist, always restricted to `post_status=publish` (so the picker can never expose a draft/private post through a lower-privileged editor's session), rendered as removable chips with type labels.

Admin list columns: thumbnail (post thumbnail, falling back to the video poster), person (name + meta), featured, related count.

## Shortcode: `[anchor_testimonials]`

Renders nothing (and enqueues no assets) when the resolved query is empty.

| Attribute | Values | Default |
|---|---|---|
| `audience` | comma list of `anchor_testimonial_audience` term slugs | all |
| `related` | `current`, a comma list of post IDs, or a mix of both | `''` (no scoping) |
| `layout` | `grid`, `slider`, `video-grid` | `grid` (invalid values fall back to `grid`) |
| `type` | `any`, `video`, `quote` | `any` |
| `featured` | `1` to limit to featured | `''` (off) |
| `limit` | int, capped at 100; `0` or negative means "all" (capped at 100) | `12` |
| `columns` | int, clamped 1-4 (grid/slider desktop column count) | `3` |
| `orderby` | `menu_order` (then date DESC), `date` (DESC), `rand` | `menu_order` |
| `fallback` | `all` = if `related` resolves to nothing, drop the related scoping and show the plain query instead; anything else = show nothing in that case | `all` |

`related="current"` resolves to `get_queried_object_id()`; if that post is an Anchor Events occurrence child (`_anchor_event_group_role === 'child'`), its group parent ID (`_anchor_event_group_id`) is added too, so a testimonial related to the course matches every date page. Non-numeric tokens other than `current` (e.g. stray text) are ignored, not treated as an error.

`type=video` matches posts with a non-empty `_at_video_id`. `type=quote` matches posts where `_at_video_id` is empty **or the meta key doesn't exist at all** (an `OR` of both conditions): a testimonial that has never had a video URL entered still counts as a quote.

## Markup contract

```html
<div class="anchor-testimonials anchor-testimonials--{grid|slider|video-grid}"
     style="--at-cols:{columns};--at-gap:24px;" data-layout="{layout}">
  <div class="anchor-testimonials__viewport">
    <div class="anchor-testimonials__track"> <!-- plus .anchor-carousel__track when layout=slider -->
      <figure class="anchor-testimonial anchor-testimonial--video"> <!-- or --quote -->
        <button type="button" class="anchor-testimonial__media"
                data-provider="youtube|vimeo" data-video-id="…" data-start="0"
                aria-label="Play video: {name}">
          <img src="{poster}" alt="" loading="lazy">
          <span class="anchor-testimonial__play" aria-hidden="true"></span>
        </button>
        <!-- video-grid layout stops here: only the media button + name, no quote/photo/meta/rating -->
        <blockquote class="anchor-testimonial__quote"><!-- wp_kses_post(wpautop(post_content)), only if non-empty --></blockquote>
        <figcaption class="anchor-testimonial__person">
          <!-- get_the_post_thumbnail(), class anchor-testimonial__photo, only if a thumbnail is set -->
          <span class="anchor-testimonial__name">{person_name}</span>
          <span class="anchor-testimonial__meta">{person_meta}</span> <!-- only if non-empty -->
          <span class="anchor-testimonial__rating" aria-label="{n} out of 5">★★★☆☆</span> <!-- only if rating > 0 -->
        </figcaption>
      </figure>
      <!-- one figure per post -->
    </div>
  </div>
  <!-- slider layout only -->
  <div class="anchor-testimonials__controls">
    <button type="button" class="anchor-testimonials__prev" aria-label="Previous"><svg aria-hidden="true">…</svg></button>
    <div class="anchor-testimonials__dots"></div>
    <button type="button" class="anchor-testimonials__next" aria-label="Next"><svg aria-hidden="true">…</svg></button>
  </div>
</div>
```

A card without a video gets `anchor-testimonial--quote` and skips the media button entirely (no empty button, no broken poster). `video-grid` layout renders only the media button and the person's name for every card, regardless of quote/photo/meta/rating content.

`layout=video-grid` always forces `type=video` (the query only returns posts with a video), even when the shortcode sets `type` explicitly (`type="quote"` or `type="any"` included): a quote-only card in video-grid would otherwise show only the person's name with no quote and no media button, since video-grid never renders the quote. To mix video and quote-only testimonials in one block, use `layout=grid` or `layout=slider` instead.

## CSS custom properties

Set inline by the renderer: `--at-cols` (column count), `--at-gap` (`24px`).

Theme-overridable, read by `assets/testimonials.css` with fallback defaults, not set inline:

| Property | Default | Affects |
|---|---|---|
| `--at-card-bg` | `#fff` | Card background, prev/next button background |
| `--at-card-radius` | `12px` | Card corner radius |
| `--at-accent` | `currentColor` | Play-button circle, rating stars, dots |

Slider layout reuses `assets/shared/anchor-carousel.css`'s `.anchor-carousel__track` rules: the CSS only maps `--at-cols`/`--at-gap` into that shared component's own `--anchor-carousel-cols`/`--anchor-carousel-gap` variables, so column count and spacing have a single source of truth and no carousel layout math is duplicated here.

## Front-end behavior (`assets/testimonials.js`)

Enqueued only when the shortcode actually renders something, with `anchor-lightbox` and `anchor-carousel` as script/style dependencies (registered by `includes/class-anchor-shared-assets.php`). On `DOMContentLoaded` (or immediately if the DOM already finished parsing), for each `.anchor-testimonials` block:

- A delegated click listener on `.anchor-testimonial__media` collects every video tile in that block into `{ type: 'video', provider, videoId, caption: name }` items and calls `window.AnchorLightbox.open(items, index, { autoplay: true, origin: button })`. If `window.AnchorLightbox` isn't present (dependency not enqueued by a theme override), it logs a `console.warn` and playback is disabled rather than throwing.
- When `data-layout="slider"`, calls `window.AnchorCarousel.init(root, { … })` reading `--at-cols` off the root's computed style for the desktop/tablet/mobile column counts (tablet = `min(cols, 2)`, mobile = `1`), wiring the prev/next buttons and `.anchor-testimonials__dots` from the markup above. Same defensive `console.warn`-and-skip if `window.AnchorCarousel` is missing.

No new lightbox or carousel code lives in this module; both are pure consumers of the shared `assets/shared/anchor-lightbox.*` and `assets/shared/anchor-carousel.*` primitives.

## Reuse

Testimonials can relate to any post, including a speaker: a speaker's single page can render `[anchor_testimonials related="current" fallback="none"]` to show only testimonials related to that speaker (see `anchor-speakers/templates/single-anchor_speaker.php`).
