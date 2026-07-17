# Anchor Locations — Phase 2: Content Libraries (Design Spec)

Date: 2026-07-18
Branch: `feature/anchor-locations-p2-libraries`
Module: `\Anchor\Locations` (Anchor Tools WordPress plugin)

## 1. Goal

Add three reusable content libraries — **projects**, **testimonials**, **FAQs** — that
authors create once and that auto-surface on Phase-1 location / service pages by
relevance. Each item is assignable to a service (via the existing `service`
taxonomy), to one or more locations (applying to that location and its
descendants), and/or globally. A specificity resolver ranks items so the most
relevant appear first. FAQs additionally emit `FAQPage` JSON-LD.

Phase 1 (2 CPTs, `service` taxonomy, Monaco content, map, internal-linking
shortcodes, Service/Place JSON-LD) is unchanged. Phase 2 lives in a **new file**
`anchor-locations/class-libraries.php`, class `\Anchor\Locations\Libraries`, with
its own hooks, instantiated from `Module::__construct()`. This keeps
`anchor-locations.php` from bloating and keeps the FAQ schema fully decoupled
from Phase-1 `build_schema()`.

## 2. Custom post types

All three: non-hierarchical, `public => false`, `show_ui => true`,
`show_in_menu => 'edit.php?post_type=anchor_location'`, attach the existing
`service` taxonomy. Registered on `init`.

| CPT | Supports | Fields (meta) |
|---|---|---|
| `anchor_project` | title, thumbnail | `al_image` (media URL, `esc_url_raw`), `al_description` (`wp_kses_post`) |
| `anchor_testimonial` | title | `al_quote` (`wp_kses_post`), `al_author` (`sanitize_text_field`), `al_rating` (int, clamp 1–5, 0 = none) |
| `anchor_faq` | title | `al_question` (`sanitize_text_field`; falls back to post title), `al_answer` (`wp_kses_post`) |

## 3. Assignment meta (all three)

| Meta key | Type | Meaning |
|---|---|---|
| `al_location_ids` | array of int | Assigned `anchor_location` post IDs. A location assignment matches that location **and all its descendants**. |
| `al_global` | `'1'` \| `''` | `'1'` = eligible on every page. |
| `service` terms | taxonomy | Zero or more `service` terms. |

## 4. Specificity resolver

Public method:

```php
public function match_items( string $cpt, int $location_id, int $service_term_id = 0, int $limit = 0 ): array
```

Returns **published** item IDs, highest score first, `post_date` DESC tiebreak.
Scoring per item (a match = the item carries that assignment):

- **+8** matches BOTH the page's service term AND location
- **+4** matches location (item's `al_location_ids` contains `$location_id` OR any ancestor of `$location_id`)
- **+2** matches the service term
- **+1** `al_global === '1'`
- score **0 ⇒ excluded**

Notes:
- The +8 "both" bonus is **additive on top of** the +4 and +2 component scores
  (an item matching both scores 8+4+2 = 14). What matters for the spec's ordering
  guarantee is only the relative order; the additive model preserves
  both-match > location-only > service-only > global. Draft/pending items are
  never returned (query is `post_status => publish`).
- "Location match" walks `get_post_ancestors($location_id)` so a county-level
  item surfaces on a child city.
- `$limit <= 0` = no limit.

## 5. Shortcodes

Published-only, fully escaped, each wrapped in
`apply_filters( 'anchor_locations_{name}_html', $html, $ctx )` where `$ctx` is
`[ 'location_id' => int, 'service_term_id' => int, 'ids' => int[] ]`.

| Shortcode | Default limit | Renders |
|---|---|---|
| `[anchor_local_projects id="" service="" limit="6"]` | 6 | title + thumbnail (`al_image` or featured image) + description |
| `[anchor_local_testimonials id="" service="" limit="3"]` | 3 | quote + author + rating stars |
| `[anchor_local_faqs id="" service="" limit="10"]` | 10 | question + answer; also feeds FAQ-schema collector |

Context derivation (before `id`/`service` attr overrides):
- On an `anchor_service_page`: location = its `al_location_id` meta; service term =
  its first `service` term.
- On an `anchor_location`: location = current post; no service term.
- `id` attr overrides the location id; `service` attr (term slug or id) overrides
  the service term.

## 6. FAQ JSON-LD

Decoupled from Phase-1 `build_schema()`. The `[anchor_local_faqs]` shortcode, when
it renders ≥1 FAQ on a singular page, appends `{question, answer}` pairs to a
per-request collector on the `Libraries` instance. A dedicated `wp_head` hook
(priority 21, after Phase-1's 20) emits a single `FAQPage` node **only** when the
collector is non-empty and `is_singular()`. Same safe encoding as Phase 1:
`wp_json_encode( $doc, JSON_UNESCAPED_UNICODE )` (NO `JSON_UNESCAPED_SLASHES`) then
`str_replace( '</', '<\/', $json )` to prevent `</script>` breakout. Answers are
passed through `wp_strip_all_tags()` for the schema text value.

## 7. Admin

- Metaboxes per CPT: a "Details" box (fields above) + an "Assignment" box
  (`al_location_ids` multi-select of published locations, `al_global` checkbox;
  `service` terms use the native taxonomy metabox).
- `save_post` guard: nonce (`Libraries::NONCE`) + `DOING_AUTOSAVE` +
  `current_user_can('edit_post',$id)`. Sanitize per §2/§3.
- Admin assets gated to `post.php`/`post-new.php` + CPT check; `wp_enqueue_media()`
  for the project image field, reusing the existing `anchor-locations/assets/admin.js`
  `.al-media` picker.
- Admin list column showing assignment summary (service / locations / global).

## 8. Testing

`tests/test-locations-libraries.php`, class `LocationsLibrariesTest`
(PHPUnit discovers `tests/test-*.php`). Real assertions:
- Specificity ordering: Pittsburgh-roofing (both) > Allegheny-County (location) > global.
- Draft item excluded.
- County-level item surfaces on a child city (ancestor walk).
- Service-only vs global ordering.
- `al_rating` clamps to 1–5.
- FAQ schema present (`FAQPage`) when FAQs rendered on a singular page; absent otherwise.
- Save handler persists/sanitizes meta.

## 9. Non-goals

- No frontend CSS beyond reusing Phase-1 `frontend.css` (optional lightweight
  additions only). No new JS. No block editor UI. No REST surface.
