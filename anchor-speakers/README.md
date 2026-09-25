# Anchor Speakers

Speaker/faculty CPT with a configurable URL base and optional linkage to Anchor Events. Registry key `speakers`, class `Anchor_Speakers_Module`, dir `anchor-speakers/`.

## Data model

CPT `anchor_speaker`: `public => true`, has single pages, no archive by default. Supports `title` (full display name, e.g. "Dr. Steven Olmos"), `editor` (full bio), `excerpt` (short bio, used by the shortcode's `show=excerpt`), `thumbnail` (headshot), `page-attributes` (`menu_order`), `revisions`. `show_in_rest => true`. `show_in_menu` is filterable via `anchor_speakers_parent_menu`.

### Meta (prefix `_as_`)

| Key | Type | Notes |
|---|---|---|
| `_as_credentials` | string | e.g. "DDS, MS, DABCP" |
| `_as_title` | string | Role, e.g. "Founder" |
| `_as_location` | string | |
| `_as_featured` | `'1'` or absent | Checkbox; the key is deleted (not stored as `''`) when unchecked. |
| `_as_links` | array of `{label, url}` | Up to 5 rows edited in the metabox; a row is only kept if its URL is non-empty. |

## URL base and the page-collision fallback

The rewrite slug is a setting, `anchor_speakers_options['base']` (default `speakers`, sanitized with `sanitize_title_with_dashes()`), with `has_archive` also a setting (off by default). This lets a site point speakers at an existing path, e.g. TMJ sets `base = about-us` so `/about-us/dr-steven-olmos/` keeps working.

Because the CPT's rewrite rules sit above a page's own rules, an `anchor_speaker` base matching a real page's path would otherwise swallow requests for that page's own non-speaker children. `Anchor_Speakers_Module::page_fallback()` (hooked on `request`) checks whether the matched request is an actual speaker first; if not, and a page exists at that same `base/child-slug` path, it hands the request back to that page instead. It reads the raw matched path from `$wp->request` rather than the resolved query vars, because a request nested two segments below the base matches WordPress's own auto-generated "attachment under this post type" rewrite rule, which only exposes the last path segment: the full path isn't otherwise recoverable at that point.

### Rewrite flush

There is no save-triggered flush flag. `maybe_flush()` (hooked on `init` at priority 99) computes a signature (`md5(base . '|' . archive-flag)`) and compares it against the stored `anchor_speakers_rules_signature` option; whenever they differ (the module's first load with no stored signature yet, a settings-page save, or the option changed by any other means, such as a direct `update_option()` call, as tests do), it calls `flush_rewrite_rules(false)` and updates the stored signature. A settings-page save works through this same mechanism (the option is already updated by the time this runs on the next request), not a separate explicit flush call.

## Events integration (`class-speaker-events.php`)

Loaded only when `\Anchor\Events\Module` exists (checked with `class_exists()` in the constructor), so the speakers module has no hard dependency on the events module. Mirrors the events module's own nullable-collaborator pattern for its optional WooCommerce integration.

- Adds a "Speakers" side metabox to the `event` post type: an ordered, addable/removable/reorderable list of published `anchor_speaker` posts, stored as `_anchor_event_speaker_ids` (ordered array of post IDs) on the event.
- **At save time, only published `anchor_speaker` posts survive** into `_anchor_event_speaker_ids` (`Anchor_Speaker_Events::published_speaker_ids()`): a stale/removed speaker, a draft, or an id that was never a speaker (a page, a bogus id) is silently dropped, in submitted order, before the meta is written. If the surviving list is empty, the meta key is deleted rather than stored as an empty array.
- **Inheritance to occurrences goes through the events module's own shared-fact allow-list, using an unprefixed key.** The speakers module hooks `anchor_events_inherited_keys` and adds the string `'speaker_ids'` (the constant `Anchor_Speaker_Events::INHERITED_KEY`) to the filtered array, unprefixed, matching the convention every other entry in `Occurrences::INHERITED_KEYS` already uses. The events module's own `Occurrences::inherited_meta_keys()` is the single place that prefixes an inherited key to its real meta key (`meta_key('speaker_ids') === '_anchor_event_speaker_ids'`); the speakers module never writes or reads a prefixed key itself here.
- `Anchor_Speakers_Module::event_speaker_ids( $event_id )` (delegates to `Anchor_Speaker_Events::event_speaker_ids()` when the class exists, else returns `[]`) resolves an event's own linked speakers, falling back to its group parent's list when the event is an occurrence child with no list of its own.
- Hooks `anchor_events_schema_node` to append `performer` entries (schema.org `Person`: `name`, `url`, `image` when a thumbnail exists, `jobTitle` from `_as_title` when set) for each linked, published speaker. An existing single `performer` node (has its own `@type`) is normalized to a one-item list before appending, rather than overwritten.

## Shortcode: `[anchor_speakers]`

Renders nothing (and enqueues no assets) when the resolved query is empty.

| Attribute | Values | Default |
|---|---|---|
| `featured` | `1` to limit to featured | `''` (off) |
| `ids` | comma list of speaker post IDs, overrides `event` when both given | `''` |
| `event` | `current` (resolves `get_queried_object_id()`) or an event post ID; ignored if `ids` is non-empty | `''` |
| `layout` | `grid`, `list`, `compact`, `avatars` | `grid` (invalid values fall back to `grid`) |
| `columns` | int, clamped 1-6 (grid/compact layouts; ignored by `avatars`) | `3` |
| `limit` | int, `-1` = no limit | `-1` |
| `link` | `1`/`0`, wrap photo/name/CTA (or, in `avatars`, the whole avatar) in a link to the speaker's single page | `1` |
| `show` | comma list, any of `credentials,title,location,excerpt` (ignored by `avatars`, which never shows text) | `credentials,title,excerpt` |

Ordering: `ids` order when `ids` is given, else event order when `event` resolves to a non-empty list (via `Anchor_Speakers_Module::event_speaker_ids()`), else `menu_order` ASC then `title` ASC. On a group-parent or group-child event, `event="current"`/an explicit event ID both resolve through the parent-fallback described above. When `event` is given and resolves to no speakers (no speakers linked, an invalid event ID, or the Events module inactive), the shortcode renders nothing rather than falling back to the full speaker roster.

## Markup contract

This is the `grid`/`compact` contract, from `Anchor_Speaker_Render::card()`. `list` uses a different method (`list_card()`) with its own markup shape - see "List layout row design" below. `avatars` is different again - see its own contract further down.

```html
<div class="anchor-speakers anchor-speakers--{grid|list|compact}" style="--as-cols:{columns};">
  <article class="anchor-speaker">
    <!-- photo, only if a thumbnail is set; wrapped in <a> when link=1, else <span> -->
    <a class="anchor-speaker__photo" href="{permalink}"><!-- medium-size thumbnail, loading=lazy --></a>
    <h3 class="anchor-speaker__name"><a href="{permalink}">{name}</a></h3> <!-- <a> only when link=1 -->
    <p class="anchor-speaker__credentials">{credentials}</p> <!-- only if in `show` and non-empty -->
    <p class="anchor-speaker__title">{title}</p> <!-- only if in `show` and non-empty -->
    <p class="anchor-speaker__location">{location}</p> <!-- only if in `show` and non-empty -->
    <p class="anchor-speaker__excerpt">{excerpt}</p> <!-- only if 'excerpt' in `show`; get_the_excerpt(), passed through wp_kses_post() not esc_html() since a manual excerpt may contain inline markup -->
    <a class="anchor-speaker__link" href="{permalink}">View profile</a> <!-- only when link=1 -->
  </article>
  <!-- one article per post -->
</div>
```

`show` filters against `Anchor_Speaker_Render::ALLOWED_SHOW` (`credentials`, `title`, `location`, `excerpt`); an unknown token in `show` is silently dropped rather than erroring.

### `layout="avatars"` markup contract

A compact overlapping stack of circular headshots only - no names, credentials or titles rendered as text. Each avatar's accessible name is the photo's `alt` attribute (or an `aria-label` on the wrapper when a speaker has no photo, since there is then no `<img>` to carry one).

```html
<div class="anchor-speakers anchor-speakers--avatars">
  <a class="anchor-speaker-avatar" href="{permalink}"> <!-- or <span> when link=0 -->
    <img class="anchor-speaker-avatar__img" src="{thumbnail}" alt="{name}" loading="lazy">
  </a>
  <!-- a speaker with no photo gets a placeholder instead of the <img>, and
       aria-label="{name}" on the wrapper: -->
  <a class="anchor-speaker-avatar" href="{permalink}" aria-label="{name}">
    <span class="anchor-speaker-avatar__img anchor-speaker-avatar__img--placeholder" aria-hidden="true">{first letter of name}</span>
  </a>
  <!-- one avatar per post, in query order (menu_order/title, ids order, or event order - same as any other layout) -->
</div>
```

`featured="1" limit="3"` (per the brief) resolves through the same query every other layout uses, so it's just `[anchor_speakers layout="avatars" featured="1" limit="3"]`; no avatars-specific query logic exists.

## List layout row design

`layout=list` renders each speaker as a row: a round photo, a compact two-(or more-)line text block vertically centered beside it, and a trailing "View" link with a right-arrow glyph vertically centered at the row's right edge. Rows are separated by a hairline bottom border (omitted on the last row).

```html
<div class="anchor-speakers anchor-speakers--list" style="--as-cols:{columns};">
  <article class="anchor-speaker">
    <!-- always present, wrapped in <a> when link=1, else <span> -->
    <a class="anchor-speaker__photo" href="{permalink}"><!-- medium-size thumbnail, loading=lazy --></a>
    <!-- a speaker with no thumbnail gets the same initial-letter placeholder
         layout="avatars" uses instead of the <img>, so the photo column
         never disappears and every row stays aligned: -->
    <a class="anchor-speaker__photo" href="{permalink}">
      <span class="anchor-speaker-avatar__img anchor-speaker-avatar__img--placeholder" aria-hidden="true">{first letter of name}</span>
    </a>
    <div class="anchor-speaker__body">
      <h3 class="anchor-speaker__name">
        <a href="{permalink}">{name}</a> <!-- <a> only when link=1 -->
        <span class="anchor-speaker__credentials">, {credentials}</span> <!-- only when BOTH credentials and title are shown; see the role-line rule below -->
      </h3>
      <p class="anchor-speaker__title">{title or credentials}</p> <!-- the role line: title when present, else credentials as a fallback, else omitted -->
      <p class="anchor-speaker__location">{location}</p> <!-- only if 'location' in `show` and non-empty -->
      <p class="anchor-speaker__excerpt">{excerpt}</p> <!-- only if 'excerpt' in `show` and non-empty -->
    </div>
    <a class="anchor-speaker__link anchor-speaker__cta" href="{permalink}">View<span aria-hidden="true"> &#8594;</span></a> <!-- only when link=1 -->
  </article>
  <!-- one article per post -->
</div>
```

All non-photo, non-CTA content is wrapped in one `.anchor-speaker__body` element (`Anchor_Speaker_Render::list_card()`), unlike the `grid`/`compact` contract where each field is its own sibling of `article`. This matters for two reasons: it collapses the row to a single CSS grid row (photo | body | CTA, three grid items - see the CSS custom properties section below for why that fixed a real layout bug), and the ~4px line spacing between body's children comes from its own flex `gap` rather than each child's own margin, which is immune to a theme's own heading/paragraph margins.

Unlike `grid`/`compact` (where the photo is entirely omitted for a speaker with no thumbnail), `list` **always** renders `.anchor-speaker__photo`: a fixed 64px photo column keeps every row's text aligned to the same starting position regardless of whether any individual speaker has a photo. `Anchor_Speaker_Render::photo_placeholder( $name )` is the one shared implementation of "no photo, show an initial" behind both this and the `avatars` layout's own placeholder (same `anchor-speaker-avatar__img`/`anchor-speaker-avatar__img--placeholder` classes, so `--as-avatar-placeholder-bg`/`--as-avatar-placeholder-fg` theme it in both places at once). The `--as-avatar-ring` border is scoped to `.anchor-speakers--avatars` only, though, so a list placeholder never gets a ring a real list photo doesn't also have.

**The role line** (`.anchor-speaker__title`, second line of `body`) prefers the speaker's title; when a speaker has no title, their credentials become the role line instead **using the same `.anchor-speaker__title` class** (so a credentials fallback is a plain line of body text, styled identically to a normal title - never a monospace/badge treatment). Credentials appear in exactly one place per card, never both: inline after the name (`.anchor-speaker__credentials`, only when the speaker has both credentials and a title, so the role line below is the title) or as the role line itself (only when there is no title). A speaker with neither a title nor credentials just has no second line.

## CSS custom properties

Set inline by the renderer: `--as-cols` (column count; not set for `avatars`, which isn't a grid).

Theme-overridable, read by `assets/speakers.css` with fallback defaults, not set inline:

| Property | Default | Affects |
|---|---|---|
| `--as-gap` | `24px` | Grid/list gap |
| `--as-photo-radius` | `50%` (a circle) | Speaker photo corner radius, on both the card photo and the single-template photo |
| `--as-cta-color` | `currentColor` | List layout's trailing "View" link color |
| `--as-list-border` | `rgba(0,0,0,.08)` | List layout's hairline row divider |
| `--as-title-color` | `rgba(0,0,0,.6)` | List layout's role line color (title, or the credentials fallback) |
| `--as-name-size` | `1.05em` | List layout's name line font size |
| `--as-photo-gap` | `16px` | List layout's gap between the photo and the text/CTA columns |
| `--as-avatar-size` | `40px` | Avatars layout: each headshot's diameter |
| `--as-avatar-overlap` | `12px` | Avatars layout: how far each avatar tucks under the previous one |
| `--as-avatar-ring` | `#fff` | Avatars layout: the ring/border color separating overlapping avatars |
| `--as-avatar-placeholder-bg` | `rgba(0,0,0,.08)` | A photo-less speaker's initial placeholder background (avatars and list layouts) |
| `--as-avatar-placeholder-fg` | `rgba(0,0,0,.5)` | That placeholder's text color (avatars and list layouts) |

`layout=list` lays each card out as a single-row CSS grid (photo at a fixed 64px | `.anchor-speaker__body` | CTA, per the row design above) instead of a grid of cards. `layout=compact` is the same grid as `grid` at half the gap. `layout=avatars` is a flex row of overlapping circles, not a grid at all.

## Single template fallback

`Anchor_Speakers_Module::single_template()` ships `templates/single-anchor_speaker.php` only when the active theme has no `single-anchor_speaker.php` of its own (checked with `locate_template()`). It is deliberately theme-agnostic (only `get_header()`/`get_footer()`, no assumed theme markup): title, credentials, title/role, featured image, `the_content()`, and, when the testimonials module is active, `[anchor_testimonials related="current" fallback="none"]` so any testimonial related to that speaker renders on their page automatically.

Front-end CSS/JS is only enqueued on this singular speaker view (`maybe_enqueue_frontend_assets()`, hooked on `wp_enqueue_scripts`) or directly from the shortcode handler when it renders something, never unconditionally on every page. There is no separate speakers front-end JS file; the module ships CSS only (`assets/speakers.css`).
