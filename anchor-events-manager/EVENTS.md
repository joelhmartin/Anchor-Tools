# Anchor Events Manager — Event Types, Registration & Grouping

## Overview

The `event` CPT (`Anchor\Events\Module::CPT`) is the single unit of authoring. Every
bookable date on the front end — whether it's one standalone event, one session in a
multi-date series, one date in a "pick one" group, or one instance of a recurring
schedule — is a full, standalone `event` post with its own dates, capacity, ticket
tiers, seats/roster, and (when ticketed) managed WooCommerce product. There is no
separate "occurrence" data type: an occurrence *is* an event post. Grouping ("these N
posts are the same bookable thing") is expressed via the `event_series` taxonomy plus
`group_id`/`group_role` post meta, and a dedicated reconcile engine
(`Anchor\Events\Occurrences`) keeps generated child posts in sync with their parent.

This document covers the rework added on `feature/events-rework`: event types,
registration modes, the occurrence/grouping model, authoring UI, front-end rendering,
the public API, and the filters exposed. For WooCommerce ticketing specifics see
`EVENTS-WOOCOMMERCE.md`; for the email system see `EMAILS.md`.

---

## Event Types

Set via the **Event Type** field in the Event Details metabox
(`_anchor_event_type` meta, one of `single|multisession|offering|recurring`; default
`single`). Read through `Module::event_type( $event_id )`.

### Single event
One event post, one date. The default and unchanged legacy behavior.

### Multi-session series (`multisession`)
One signup covers a repeatable list of session date/times, all stored on the SAME
event post (`_anchor_event_sessions` meta, an array of
`{date, start_time, end_time, label}` rows edited via the "Sessions" repeater in the
metabox). There is no child-post generation for this type — one post, one roster, one
registration — it's for things like a 4-week course where a single registration
covers every meeting. `Module::get_sessions( $event_id )` returns the normalized rows.

### Pick-one offerings (`offering`)
A visitor registers for ONE of several dates. The parent event post holds an explicit
list of desired dates (`_anchor_event_offering_dates` meta: `{date, start_time,
end_time, label, capacity}` rows, authored via the "Offering Dates" repeater). Saving
the parent triggers `Occurrences::reconcile()`, which generates one full child `event`
post per date — each with its own capacity, seats, roster, and (if the parent's
registration mode is `wc`) its own managed WooCommerce product/variations. The parent
itself is never directly bookable; its front-end page renders a "Choose a date" list
over its live children instead of a registration form.

### Recurring schedule (`recurring`)
Like Pick-one offerings, but the date list is generated from a rule
(`_anchor_event_recurrence` meta: `{freq, interval, count?, until?, weekdays?,
start_time, end_time, capacity}`) instead of being hand-typed. `freq` is `weekly` or
`monthly`; `interval` is "every N weeks/months"; the rule must set `count` or `until`
(or both — whichever is hit first wins) to terminate, otherwise generation stops at a
hard safety cap of 104 rows (`Occurrences::RECURRENCE_MAX_ROWS`, ~2 years of weekly
occurrences). `Occurrences::expand_recurrence( $rule, $anchor_date )` is a pure
function of its inputs — same rule + anchor always produces the identical date list.
Monthly short-month handling is a documented choice: a month that doesn't have the
anchor's day-of-month (e.g. day 31 hitting a 30-day month) is skipped entirely, never
rolled to a different day. The recurrence builder is **admin-only** — the front-end
event-manager form's Event Type selector never offers "Recurring schedule"; an already
-recurring event opened in that form shows a read-only summary of the stored rule
(hidden inputs round-trip it unchanged on save) instead of the interactive builder.

---

## The occurrence = event-post model

`Anchor\Events\Occurrences` (`anchor-events-manager/class-occurrences.php`) is the
parent → child reconcile engine shared by both `offering` and `recurring` types —
only the *date source* differs (explicit rows vs. `expand_recurrence()`); everything
downstream is identical.

- **Grouping**: a parent's live children are all tagged with the same
  `event_series` term (auto-created, slug `group-{parent_id}`) via
  `Occurrences::assign_series()`. Identity meta: `_anchor_event_group_role`
  (`parent`|`child`|``) and `_anchor_event_group_id` (child → parent post ID).
- **Idempotency**: each child is matched to a desired date by a stable
  `_anchor_event_occurrence_key` (the row's normalized `Y-m-d` date), stored on the
  child at creation. Reconciling an unchanged desired set produces no new posts, no
  closures, and no meta churn.
- **Field split on every reconcile**:
  - *Per-occurrence* (owned by the child): `start_date`/`end_date` (frozen once set —
    the date identity) and `status`/`status_mode` (frozen). `start_time`, `end_time`,
    and `capacity` are the row's *editable* fields and ARE re-applied parent-row-wins
    on every reconcile, with `start_ts`/`end_ts` recomputed. Seats/roster and the
    managed WooCommerce product are implicitly per-occurrence and never copied.
  - *Shared* (copied from parent → child at creation AND re-synced on every reconcile
    of a still-live child): an **explicit allow-list**, not "everything else" —
    `Occurrences::INHERITED_KEYS` (location fields, `timezone`, `all_day`, the
    registration-policy fields, `price`, `gallery`, `labels`, `registration_mode`,
    `external_*`, `organizer_email`, `reminder_offsets`) **plus** the event meta
    that lives outside the meta schema: `_anchor_event_reg_questions` and every
    `_anchor_event_email_*` override (per-type template/on-off/subject/preheader/
    intro/CTA, and the From / Reply-To / Cc / Bcc sender identity). Title/content
    and ticket tiers are copied by their own code paths. A child's own `type` meta
    is force-set to `single`.
    Two rules apply to everything on that list:
    - Only a key the parent has a **real meta row** for is copied — never a value
      `get_meta()` defaulted at read time, so a child is never handed a
      `registration_type=internal` or `timezone=UTC-6` row nobody authored.
    - Inheritance is **authoritative and symmetric**: a value authored directly on
      a child is overwritten by the parent's on the next reconcile, and **deleted**
      when the parent has no row for it (so clearing a venue — or a custom
      confirmation subject — on the parent propagates instead of stranding the old
      value on every date). Customise these on the parent, never on a child.
    Single-value only: each key is read with `get_post_meta( …, true )` and written
    as one row, so a genuinely multi-row key cannot be inherited.
  - *Per-occurrence, never copied*: `PER_OCCURRENCE_KEYS` — the date identity,
    `status`/`status_mode`, `registration_enabled`, `sold_out`, and the occurrence
    `label`.
  - *Never copied at all*: engine-owned/product-owned keys (`linked_products`,
    `roster_sent`, `activity`, `type`, `sessions`, `group_role`, `group_id`,
    `offering_dates`, `recurrence`, `occurrence_key`, `occurrence_closed`,
    `occurrence_prev_reg`).
- **Roster-safe soft-close**: when a previously-desired date is removed from the
  parent, its child is never deleted outright. If it has ANY seats (any status), it is
  *soft-closed*: `status_mode=manual`, `status=cancelled`,
  `registration_enabled=false`, plus the engine-owned flag
  `_anchor_event_occurrence_closed=1` — post and roster survive untouched, just
  excluded from the "active" child set. A child with zero seats is trashed instead.
  Re-adding the same date later *revives* the same child (clears the closed flag,
  restores `status_mode=auto`, and puts `registration_enabled` back to the value
  the close overwrote — snapshotted in `_anchor_event_occurrence_prev_reg`)
  rather than creating a duplicate, so its historical roster is retained.
- **Parent trash**: trashing the parent (`wp_trash_post()` doesn't fire `save_post`,
  so `reconcile()` can't run on its own) is handled by
  `Occurrences::retire_all_children()`, which applies the exact same roster-safe
  soft-close/trash logic to every existing child.

---

## Registration Modes

Set via the **Registration** field (`_anchor_event_registration_mode` meta, one of
`wc|free|external`; default `free`). Read through
`Module::registration_mode( $event_id )` — an explicit stored value wins; otherwise a
legacy event (pre-rework) derives its mode from old signals (external URL/type meta,
a managed product, or any active priced ticket tier) so existing events keep working
unchanged after upgrade.

- **`wc` — WooCommerce ticketed**: sold through one or more ticket tiers
  (`Anchor\Events\Ticket_Types`), each backed by a managed WooCommerce product
  variation. See `EVENTS-WOOCOMMERCE.md` for the full purchase/capacity flow.
- **`free` — Free internal**: the plugin's own lightweight registration form
  (`Module::render_registration_form()`), no WooCommerce involved. Free tiers can
  still be offered when multiple free options exist.
- **`external` — External**: registration happens off-site. This mode is
  intentionally generic — not a single "URL" field:
  - `_anchor_event_external_url`: a plain "Register" link, OR
  - `_anchor_event_external_embed`: arbitrary embed markup (an `<iframe>`, a
    third-party form widget's `<div>`/`<script>`-adjacent markup, etc.), sanitized
    through a dedicated `wp_kses()` allowlist (`Module::get_embed_allowed_html()`)
    that permits `iframe`/`div`/`span`/`a`/`p`/`br` with common attrs (including
    `data-*`) but strips `<script>` (and anything else off the allowlist) entirely.
    The allowlist is filterable via `anchor_events_embed_allowed_html`.
  - `_anchor_event_external_display_price`: a free-text, **display-only** price
    string (e.g. `"$495"`) — never validated/charged, purely informational. It's also
    parsed for a numeric substring when building JSON-LD Offers (see below).
  - When both `external_embed` and `external_url` are set, the embed renders and the
    plain link does not.

---

## Authoring

- **Metabox choosers**: the Event Details metabox has an "Event Type" `<select>`
  (single/multisession/offering/recurring) and a "Registration" `<select>`
  (wc/free/external, with `wc` disabled and annotated when WooCommerce isn't active).
  Conditional sections (`.anchor-event-conditional[data-when-type]` /
  `[data-when-mode]`) show/hide via `admin.js` based on the current selection.
- **Offering-date repeater**: a table of date/start-time/end-time/label/capacity rows
  (`anchor_event_offering_dates[<index>][...]` POST fields), add/remove rows via JS,
  validated on save — an `offering`-type event with zero rows shows an inline error
  and generates nothing.
- **Recurrence builder**: frequency (weekly/monthly), interval, weekday checkboxes
  (weekly only), count/until, plus start/end time and capacity defaults for generated
  rows. Requires `count` or `until` before saving will generate anything (inline
  error otherwise). Admin-only, per above.
- **Front-end manager-form parity**: the same offering-dates repeater (shared render
  method, `render_group_authoring_sections()`) is available on the front-end
  event-manager form so non-admin event owners can maintain a Pick-one-offerings
  event's dates without WP admin access — but that form's Event Type selector never
  exposes "Recurring schedule", and the recurrence builder itself never renders there.
- Saving a parent event runs `Occurrences::reconcile()` after the parent's own meta is
  persisted (guarded against re-entrant saves triggered by the child posts'
  `save_post_event` firing during reconcile).

---

## Front End

- **Choose-your-date parent page**: `Module::render_choose_date_list( $parent_id )`
  renders a "Choose a date" list over the parent's live children (date/time,
  availability hint, link) in place of a registration form — a group parent is never
  itself bookable (`render_registration_form()` returns `''` for a group parent
  before any other branch, including the WooCommerce override filter).
- **Child sibling nav**: `Module::render_sibling_dates( $child_id )` renders an
  "Other dates" list of a child's live siblings plus a link back to the parent's
  choose-a-date page. A directly-visited soft-closed child shows a
  "no longer available" notice instead of a booking form, alongside this sibling list.
- **Series archive grouping**: `Anchor\Events\Series` registers the public
  `event_series` taxonomy (rewrite slug `series`) and renders its archive
  (`render_archive()`). Because a group parent shares its series term with every one
  of its live children, the archive collapses each group down to ONE row (the
  parent, rendered as a "choose a date" summary with a date-range and an "N dates
  available" count) rather than listing every child date separately; soft-closed
  children are dropped from the archive entirely.

---

## Key Meta Keys

All prefixed `_anchor_event_` (via `Module::meta_key( $key )`).

| Key (suffix) | Type | Notes |
|---|---|---|
| `type` | string | `single` \| `multisession` \| `offering` \| `recurring` |
| `registration_mode` | string | `wc` \| `free` \| `external` |
| `sessions` | array | Multi-session rows: `{date, start_time, end_time, label}` |
| `labels` | array | Event-level badge rows: `{key, label, value}` — see "Event Labels" below |
| `offering_dates` | array | Pick-one rows: `{date, start_time, end_time, label, capacity}` |
| `recurrence` | array | Recurring rule: `{freq, interval, count?, until?, weekdays?, start_time, end_time, capacity}` |
| `group_role` | string | `parent` \| `child` \| `` — engine-owned |
| `group_id` | int | Child → parent post ID — engine-owned |
| `occurrence_key` | string | Child's date identity, matches its source row — engine-owned |
| `occurrence_closed` | bool | Soft-close flag — engine-owned |
| `occurrence_prev_reg` | bool | Pre-close `registration_enabled`, restored on revive — engine-owned |
| `external_url` | string | External-mode plain link |
| `external_embed` | string | External-mode sanitized embed markup |
| `external_display_price` | string | External-mode display-only price text |
| `organizer_email` | string | Per-event roster-digest recipient override |
| `roster_sent` | int | Scheduled-roster idempotency marker (unix ts, 0 = not sent) |

---

## Main Public API

**`Anchor\Events\Occurrences`** (`$module->occurrences`)
- `reconcile( $parent_id ): int[]` — idempotently sync a parent's children; returns live child ids.
- `children( $parent_id, $include_closed = false ): int[]` — a parent's children, date-ascending.
- `siblings( $child_id, $include_closed = false ): int[]` — a child's siblings (excludes itself).
- `is_group_parent( $id ): bool` / `is_group_child( $id ): bool`
- `parent_of( $child_id ): int` — 0 when not a child.
- `expand_recurrence( array $rule, $anchor_date ): array` — pure date-row generator.
- `retire_all_children( $parent_id )` — roster-safe soft-close/trash of every child (parent-trash path).

**`Anchor\Events\Event_Schema`** (`$module->event_schema`)
- `for_event( $event_id ): array` — schema.org/Event JSON-LD node (no `@context`), dispatching on type: group child/parent, `multisession`, or plain single. See "JSON-LD" below.

**`Module`**
- `resolve_email_template( string $type, int $event_id ): string` — per-event override → global option → default constant.
- `compute_email_schedule( int $event_id ): array` — read-only upcoming reminder/roster schedule (see `EMAILS.md`).
- `event_type( $event_id )`, `registration_mode( $event_id )`, `get_meta( $event_id )`, `meta_key( $key )`, `get_sessions( $event_id )`.
- `get_labels( $event_id )`, `get_label( $event_id, $key )`, `labels_vocabulary()` — see "Event Labels" below.

---

## Event Labels

Short, author-written descriptors a theme renders as badges: **"2 Day Course"**,
**"14 CE Credits"**, **"Hands-on"**. They exist so course duration is first-class
data instead of being buried in body copy.

**Duration is text, not a computed value — on purpose.** A `2026-03-05 → 2026-03-06`
span could be a "1.5 Day Course" (Thursday evening plus Friday) or a "2 Day
Course", and "2.5 Day Course" cannot be derived from dates at all. Deriving it
would be wrong often enough to be worse than leaving it blank.

### Vocabulary

| Key | Caption | Example value |
|---|---|---|
| `duration` | Duration | `2 Day Course` |
| `credits` | CE Credits | `14 CE Credits` |
| `format` | Format | `Hands-on` |
| `level` | Level | `Advanced` |
| `custom` | *(author-typed)* | `Spring 2027` |

Captions for known keys are resolved at render time via `labels_vocabulary()`
rather than stored, so they stay translatable — persisting a translated caption
would freeze it in whatever locale the author saved in. Only a `custom` row
carries its own caption on the row. An unknown key clamps to `custom`, and a row
with an empty value is dropped (the labels equivalent of a session row with no
date). Duplicate keys are allowed; `get_label()` returns the first match.

### Theme API

```php
// One label — the DEKA "2 Day Course" badge.
$duration = anchor_event_label( get_the_ID(), 'duration' );
if ( $duration ) {
    echo '<span class="course-badge">' . esc_html( $duration ) . '</span>';
}

// Every label, in author order. Rows are { key, label, value, caption }.
foreach ( anchor_event_labels( get_the_ID() ) as $row ) {
    printf( '<li class="badge-%s">%s</li>', esc_attr( $row['key'] ), esc_html( $row['value'] ) );
}
```

Both are global functions (defined in `template-tags.php`, which is separate
because PHP forbids a bracketed `namespace { }` block in a file that already
opened with an unbracketed `namespace Anchor\Events;`).

Values are **plain text** — always escape at the point of output.

### Rendering

- **Card** — a badge list, value only, each `<li>` carrying `anchor-event-label`
  plus a per-key class (`anchor-event-label-duration`) and `data-caption`, so a
  theme can position one badge specifically rather than styling a blob.
- **Single** — `Caption: value` rows inside `.anchor-event-detail-meta`, matching
  the existing Date / Venue / Status shape.
- **Occurrence children inherit labels.** `labels` is named in
  `Occurrences::INHERITED_KEYS`, so `sync_shared_meta()` copies the parent's row
  down — a "2 Day Course" describes each date of a pick-one offering, so
  inheriting is the correct default.

### Not in JSON-LD

Labels are deliberately absent from the schema.org output. `duration` there
requires ISO-8601 (`P2D`); "2.5 Day Course" is free text that cannot be safely
coerced, and emitting it would produce *invalid* structured data. A valid
`duration` would need its own separate ISO field.

---

## JSON-LD (schema.org/Event)

`Event_Schema::for_event()` dispatches on the event's shape:

- A **group child** always renders as a standalone `Event` node.
- A **group parent** (or an `offering`/`recurring` type pre-reconcile) renders one
  node whose `subEvent` array is `for_event()` of every LIVE child — so a scraper
  reading only the parent's page still sees every upcoming date. The parent's own
  `startDate`/`endDate` are taken from the earliest live child. Zero live children →
  `[]` (nothing advertised).
- A **`multisession`** event renders one node spanning its earliest session start to
  its latest session end, with one minimal `Event` stub per session in `subEvent`.
- Anything else (**`single`**) renders one plain node.

**Offers**, keyed off `registration_mode( $event_id )`:
- `wc`: one `Offer` per active ticket tier, priced from the tier.
- `external`: one `Offer` with `url` = `external_url` (or the permalink) and `price`
  parsed from `external_display_price` when a numeric substring is found — never
  fabricated when unparseable.
- `free` (default): one zero-price `Offer` (a present zero-price offer, per Google's
  guidance, is the canonical "free to attend" signal — preferred over omitting
  `offers` for a bookable event).

`availability` on every branch is `Module::bookability()` — the single
purchasability authority the storefront, the cart, the choose-a-date picker and
the series archive also ask — mapped through `Event_Schema::availability_for()`:
`open` → `InStock`, `waitlist` → `LimitedAvailability`, `full` → `SoldOut`.
A state with no availability value (`closed`, `disabled`, a group `parent`)
emits **no Offer at all** rather than a false one, so a finished event, an event
outside its registration window, an event with registration switched off and a
group-parent container all advertise no price. The `wc` branch asks per **tier**,
so an exhausted tier quota is `SoldOut` while its sibling stays `InStock`.

Emission (`Module::render_event_schema()`, on `wp_head` for single `event` views) is
skipped when: there's nothing to advertise; the parent Anchor Schema plugin already
has an enabled, manually-configured `Event`-typed schema item for the same post
(de-dupe, checked via `Anchor_Schema_Admin::META_KEY` post meta); or the
`anchor_events_emit_event_schema` filter returns `false`.

---

## Filters

| Filter | Args | Purpose |
|---|---|---|
| `anchor_events_inherited_meta_keys` | `$keys, $parent_id, $child_id` | The full (prefixed) meta keys an occurrence child inherits from its group parent. Single-value keys only — each is copied with `get_post_meta( …, true )` and deleted wholesale when the parent has none, so a multi-row key (the DEKA theme's `_deka_event_speaker_ids`, say) must NOT be added here. |
| `anchor_events_labels` | `$rows, $post_id` | Resolved event label rows (`{key, label, value, caption}`) — inject or rewrite a label before render. |
| `anchor_events_labels_vocabulary` | `$vocabulary` | The label `key => caption` map. Add a site-specific key here instead of overloading `custom`. |
| `anchor_events_embed_allowed_html` | `$default_allowed` | `wp_kses()` allowlist for the External-mode `external_embed` field. |
| `anchor_events_email_template_allowed_html` | `$default_allowed` | `wp_kses()` allowlist for admin-authored email template HTML (see `EMAILS.md`). |
| `anchor_events_schema_default_currency` | `$default, $event_id` | Override the `priceCurrency` used in JSON-LD Offers. |
| `anchor_events_emit_event_schema` | `$should_emit, $event_id` | Suppress/force JSON-LD emission for an event. |
| `anchor_events_should_send_reminder` | `true, $seat, $offset` | Per-recipient reminder-email suppression. |
| `anchor_events_query_args` | `$query_args, $atts` | Adjust the `WP_Query` args behind event listing shortcodes. |
| `anchor_events_event_classes` | `$classes, $post_id, $context` | Extra CSS classes on a rendered event card/row. |
| `anchor_events_registration_form` | `'', $post_id, $meta` | Override seam — return non-empty HTML to replace the registration form entirely (used by the WooCommerce integration for the ticketed buy UI). |
| `anchor_events_registration_fields` | `$fields` | Extra custom fields on the free registration form. |
| `anchor_events_registration_email_html` | `$html, $ctx` | Final filter on any built registration/lifecycle email HTML. |
