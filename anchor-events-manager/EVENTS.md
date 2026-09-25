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
list of desired dates (`_anchor_event_offering_dates` meta: `{date, end_date,
start_time, end_time, label, capacity, tier_id}` rows, authored via the "Offering
Dates" repeater — `end_date` lets one row span more than a day, and `tier_id`
optionally links the date to one of the event's ticket tiers instead of selling every
tier on it). Saving
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
  - *Per-occurrence* (owned by the child): `start_date` (frozen once set — the date
    identity) and `status`/`status_mode` (frozen). `start_time`, `end_time`,
    `end_date`, and `capacity` are the row's *editable* fields and ARE re-applied
    parent-row-wins on every reconcile, with `start_ts`/`end_ts` recomputed. The END
    date is deliberately **not** part of the occurrence's identity — only the START
    date is — so a one-day occurrence that becomes two days updates in place instead
    of minting a new occurrence. Seats/roster and the managed WooCommerce product are
    implicitly per-occurrence and never copied.
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
      When the delete takes AUTHORED content — registration questions or email
      wording held on a date, whether typed there or left over from a value just
      cleared on the parent — the save queues one `inherited_child_data_removed`
      warning so the author is told once, rather than finding out from a booking.
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

### Attendee questions

An event can ask anything on top of name/email/phone: rows in
`_anchor_event_reg_questions` (`key`, `label`, `type` = text|textarea|select|checkbox,
`options`, `required`), read through `Module::get_registration_questions()`. **All three**
seat-creating paths render them — the free form, the WooCommerce checkout's attendee
fieldset, and the roster's manual add (both the wp-admin form and the front-end console)
— and all three enforce `required` client- and server-side. One control renderer
(`Module::render_registration_question_control()`) and one validator
(`Module::sanitize_registration_answers()`) serve all of them, so the types on offer,
the select constraint, the checkbox normalization and the textarea's newlines cannot
differ by path.

Answers live on the seat in `_anchor_event_reg_fields`, **keyed by the question's
stable `key`, never by its label**, so renaming a question keeps its answers. The label
is a display value only: every reader (roster table, roster list table, CSV header,
privacy export) resolves it at render time via
`Module::registration_answer_label()`, and `Module::resolve_registration_answers()` —
called once, in `Registrations::seat_dto()` — maps a seat's stored answers onto the
current question set, lazily migrating pre-fix label-keyed rows and keeping the stored
key for an answer whose question has been deleted.

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
- **`[event_registration]` auto-append**: saving an event with registration
  enabled appends `[event_registration]` to its content once, unless it's
  already there (`Module::maybe_append_registration_shortcode()`). A theme
  that renders its own registration UI for the event content declares
  `add_theme_support( 'anchor-events-registration' )` to suppress the
  auto-append entirely (NEW-D6); the `anchor_events_auto_append_registration`
  filter (`$should_append, $post_id`, default `true`) is the escape hatch for
  anything that can't add theme support. The shortcode itself also renders at
  most once per event per request — a second invocation for the same event
  anywhere on the page (theme template part, widget, a stray second copy of
  the tag) renders nothing rather than a duplicate picker/form.
- **Series archive grouping**: `Anchor\Events\Series` registers the public
  `event_series` taxonomy (rewrite slug `series`) and renders its archive
  (`render_archive()`). Because a group parent shares its series term with every one
  of its live children, the archive collapses each group down to ONE row (the
  parent, rendered as a "choose a date" summary with a date-range and an "N dates
  available" count) rather than listing every child date separately; soft-closed
  children are dropped from the archive entirely.
- **Attendee console tabs on a group parent**: `Roster::render_frontend()`
  tabs a group parent's `[event_manager]` "Attendees" page one tab per child
  occurrence (`render_frontend_group()`). Every tab is a real
  `<a role="tab" href="...&occurrence=<child>">` — switching tabs is a
  server-rendered page load, not a JS toggle, so the tablist/aria-selected/
  tabpanel markup and the keyboard behaviour are just the browser's ordinary
  link navigation (Tab moves focus between links, Enter/click follows one) —
  there is no custom keyboard handler to keep in sync. Only the ACTIVE
  occurrence's panel gets the full summary/add-form/Registered-list a single
  event's console shows (`render_roster_panel()`, one `query_seats()` call);
  every other tab's panel gets the lighter `render_roster_panel_summary()`
  (summary cards + add form + a "Show attendees" link to its own occurrence
  URL) so a recurring parent with upwards of a hundred children never
  renders more than one seat table per page load.

---

## Hosted livestream room

### Every event grants a role; only some have a room

`access_role_enabled` (default **true**, owner decision 2026-09-23) is the
master switch. While it is **on** — which is every plugin-registered event
unless somebody turned it off — each confirmed attendee gets an account and the
capability-less role `anchor_event_{id}` ("Event: {title}"), whether they
attend in person or over a stream. That is the point of it: the Private File
Manager hands out recordings, handouts and certificates **by role**, so the
role is how "attended this" is written down.

**The role is not the room.** A room exists only when the event also has a
resolvable stream, and that is `Module::room_url( $event_id ) !== ''` — the one
expression every room surface asks. For an ordinary in-person event:
`room_url()` is `''`, `/live/` redirects to the event page, the events list
shows `—` in the Live column, `{room_link}` is `''`, the email CTA is the one
it has always been, and `can_access_stream()` is false for everybody but staff.
Its roster still shows the Access column, because its attendees really do hold
the role.

While the switch is **off**:

- no attendee account is created, on any path — free registration, comped
  roster add, or WooCommerce checkout;
- no `anchor_event_{id}` role is minted, so `wp_roles()` is untouched;
- there is no room, no `{room_link}` and no changed email;
- the roster shows no Access column, and the Basics tab shows one line saying
  access is off.

`Entitlements::enabled( $event_id )` is that check in code —
`Module::stream_capable( $event_id ) && access_role_enabled` — and every entry
point asks it first: `grant_for_seat()`, `ensure_user()`, both seat hooks,
`maybe_revoke_seat_grant()`, `can_access_stream()`, `backfill()`, the roster's
Access surfaces. `stream_capable()` is the weaker, unchanged predicate ("could
this event ever hold a room": registration mode `wc`/`free`, not a group
parent). `tests/test-inertness.php` is the standing proof of all of it.

**Turning it off is a real, reversible operator act, and it looks forward
only.** Un-tick **Access → "Give confirmed attendees an account and the event
role"** and no *future* attendee is granted. Nobody who already holds the role
loses it — the only thing that removes holders is **Delete role** on the
console's Basics tab, which strips it from everyone. Tick it back on and use
**Grant role to current attendees** (Basics tab) to catch up the people who
registered in between; that backfill is idempotent, so running it twice is a
no-op.

Three saves force the switch back on, because a stream is useless without the
role: storing a non-empty `stream_embed`, a `virtual`/`hybrid` session (or
`stream_default_modality`), or a `virtual` ticket tier. With a stream saved,
the Access checkbox renders checked and disabled. A save whose form did not
carry the field at all — a programmatic write, a partial update — leaves the
stored value exactly as it is, in either direction.

### The room itself

Every event registered **through this plugin** (`registration_mode` `wc` or `free`;
never `external`, never a group parent) **that has a resolvable stream and its
access switch on** has a private room
at `<event permalink>/live/` — an `add_rewrite_endpoint( 'live', EP_PERMALINK )`
endpoint, so it is a URL on the event, not a second post. On a plain
permalink (the query-string shape a Plain permalink setting, or any CPT URL
WordPress never rewrote, produces) the room is `add_query_arg( 'live', '1',
$permalink )` instead, since a rewrite endpoint has nothing to append a path
segment to.

**A pre-existing legacy `virtual` event now qualifies too, with no author
action.** Two read-time bridges make this automatic: `event_level_embed()`
resolves a stored `virtual_url` through `Embed::normalize()` as the event's
stream embed whenever no `stream_embed` is already saved and the URL's host is
one `Embed::providers()` recognises (Vimeo, YouTube, Zoom, or a site-added
host) — a Zoom link resolves and then renders in the room as a "Join on Zoom"
button, exactly like a freshly-authored one. And `default_modality_for()`
resolves the implicit session's modality to `virtual` whenever
`stream_default_modality` was **never stored** (`metadata_exists()` false) and
the legacy `virtual` flag is on — so an event authored before this feature
existed, that only ever had the old Virtual Event checkbox and a join URL, has
a real, resolvable stream: `has_stream()` is true, `room_url()` is non-empty
(access defaults on), and its next confirmation switches from "Join the
event" to "Join the livestream" pointing at `{room_link}` (see `EMAILS.md`'s
CTA-order note). An author who explicitly saves `stream_default_modality` as
`in_person` on such an event overrides the bridge, since that is a deliberate,
stored choice rather than an absence. The Livestream "Default attendance"
select pre-fills from `default_modality_for()`, so saving such an event
without touching the select keeps it `virtual`.

Core role changes do not strip event roles: `WP_User::set_role()` (an admin
changing someone's primary role) and a direct `remove_role()` of an
`anchor_event_*` role are undone for every event the user still has a
`_anchor_event_grants` record for. `Entitlements::revoke()` clears the record
first, so it remains the one way to take access away.

The room is `private, no-store`, carries `X-Robots-Tag: noindex, nofollow` and a
robots meta tag, and is not in the sitemap (core sitemaps list post permalinks;
a rewrite endpoint is never enumerated). `Event_Schema` publishes the room URL
as the `VirtualLocation.url` — schema points at it, robots keep it out of
results. `Module::room_header_list( $event_id )` is the pure header/redirect
decision as data (no `header()`/`exit`) — `room_headers()` just applies it.

**States** (`Anchor\Events\Stream_State`, a pure function of the resolved
sessions, the two window widths and the clock): `unavailable`, `pending`,
`countdown`, `live`, `between`, `ended`. The embed is in the markup **only**
during `live`.

**Template:** `templates/live-event.php`, overridable by a theme at
`events/live-event.php` or `live-event.php`. The body is
`Module::render_room( $event_id )`, which renders one of: sign-in form (logged
out), denial (logged in, not entitled), or the state block + schedule.

**Refresh:** `assets/room.js` ticks the countdown, corrects for client clock
skew from `data-server-now`, and calls
`GET /wp-json/anchor-events/v1/events/{id}/room` when the target passes — and
every 5 minutes while `live`. 401 logged out, 403 not entitled.

**Access** is a capability-less WordPress role `anchor_event_{id}`
("Event: {title}"), minted lazily on the first grant, renamed with the title,
and **never deleted automatically** — trashing the event leaves it. The console's
Basics step has **Grant role to current attendees** (the backfill: every
confirmed seat, accounts created as needed, idempotent) and an explicit
**Delete role** action.

**Sign-in link:** `Entitlements::room_url_for( $user_id, $event_id )` appends a
stateless `?aek=` HMAC (user | event | expiry | password fragment), expiring at
the last session's `end_ts` + 7 days. A logged-out visitor with a valid token is
signed in (firing `wp_login`) and redirected to the clean room URL; a
logged-in visitor arriving with `?aek=` is redirected to the clean URL and
never switched; both redirects send no-cache headers. The token is never shown
in admin or logs. **No token is minted or accepted for staff** — any account
with `edit_posts` or the events capability (`Roster::cap()`) gets the plain
room URL and signs in normally. In emails a token is minted only when the
resolved account's email is the recipient's address; otherwise the recipient
gets the plain room URL.

**Which account a seat entitles** (`ensure_user()`): the stored
`_anchor_event_user_id`; else the order's customer **only when the seat email
is empty or is that customer's** (case-insensitive) — an attendee seat on a
logged-in buyer's order resolves to the attendee's own account, never the
buyer's; else an existing account with the seat email; else a new one.

---

## Key Meta Keys

All prefixed `_anchor_event_` (via `Module::meta_key( $key )`).

| Key (suffix) | Type | Notes |
|---|---|---|
| `type` | string | `single` \| `multisession` \| `offering` \| `recurring` |
| `registration_mode` | string | `wc` \| `free` \| `external` |
| `sessions` | array | Multi-session rows: `{date, start_time, end_time, label}` |
| `labels` | array | Event-level badge rows: `{key, label, value}` — see "Event Labels" below |
| `offering_dates` | array | Pick-one rows: `{date, end_date, start_time, end_time, label, capacity, tier_id}` |
| `recurrence` | array | Recurring rule: `{freq, interval, count?, until?, weekdays?, start_time, end_time, capacity, label?, span_days?, tier_id?}` — the last three (audit MODEL-D35) have no admin UI input yet; `span_days` becomes each generated row's `end_date` |
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
| `access_role_enabled` | bool | **The master switch for everything in "Hosted livestream room" below. Default `true`.** On: every confirmed attendee gets an account and the `anchor_event_{id}` role, in person or not, because event materials are handed out by role. Off: the event mints no role, creates no accounts, has no room, emits no `{room_link}` and shows no Access column. A **real, reversible checkbox** — un-ticking stops *future* grants and never removes an existing holder (that is **Delete role**); **Grant role to current attendees** on the Basics tab re-grants after it is switched back on. A save whose form did not carry the field leaves the stored value alone. Forced back to `true` by any save that stores a non-empty `stream_embed`, a `virtual`/`hybrid` session (or `stream_default_modality`), or a `virtual` tier. **Not the same as "has a room"** — that is `Module::room_url() !== ''`, which is this *and* a resolvable stream. Inherited. |
| `stream_embed` | array | Normalised stream `{provider, kind, src, raw}` — output of `Embed::normalize()`, never raw HTML. Saving a non-empty value forces `virtual=1` **and** `access_role_enabled=1`. Inherited by occurrence children. |
| `stream_default_modality` | string | `in_person` \| `virtual` \| `hybrid` — the seed for new session rows and new tiers. Inherited. |
| `in_person_includes_stream` | bool | "In-person registrants also get the stream". Default `true`. Inherited. |
| `stream_open_before_minutes` | int | Room switches countdown → player this many minutes before a session starts. Default `15`. Inherited. |
| `stream_close_after_minutes` | int | Room keeps the player this long after a session's `end_ts`. Default `30`. Inherited. |
| `required_roles` | string[] | Prerequisite role slugs — checked inside `Registrations::capacity_decision()`. Inherited. |
| `required_roles_mode` | string | `any` \| `all`. Inherited. |
| `sessions[].modality` | string | Per-session `in_person` \| `virtual` \| `hybrid`; empty inherits `stream_default_modality`. |
| `sessions[].stream_embed` | array | Per-session stream override; empty inherits the event's. |

Two more keys carry the same feature but live off the event post: the seat
(`Module::REG_CPT`) meta `_anchor_event_user_id` (int) — the account a seat
entitles, resolved by `Entitlements::ensure_user()`; distinct from
`_anchor_event_customer_id` (the WooCommerce order's customer, `0` = guest) —
and each ticket tier's `modality` field (`in_person` \| `virtual`, default
`in_person`), mirrored onto the tier's managed WooCommerce variation as
`_anchor_evt_modality` (`Product_Sync::VARIATION_MODALITY_META`).

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
- `resolve_email_template( string $type, int $event_id ): string` — per-event override → default constant (REG-D12 retired the never-written global-option tier).
- `compute_email_schedule( int $event_id ): array` — read-only upcoming reminder/roster schedule (see `EMAILS.md`).
- `event_type( $event_id )`, `registration_mode( $event_id )`, `get_meta( $event_id )`, `meta_key( $key )`, `get_sessions( $event_id )`.
- `get_labels( $event_id )`, `get_label( $event_id, $key )`, `labels_vocabulary()` — see "Event Labels" below.
- `room_url( $event_id ): string` — **the one definition of "this event has a room"**: `''` unless the access switch is on AND a stream resolves. Every room surface asks this, not `enabled()`.
- `has_stream( $event_id ): bool` — does any resolved session carry an embed?
- `render_room( $event_id ): string`, `room_state_block( $event_id, array $state ): string`
- `resolved_sessions( $event_id ): array` — always at least one row (a single event resolves to one implicit session).
- `stream_capable( $event_id ): bool`

**`Anchor\Events\Entitlements`** (`$module->entitlements`)
- `enabled( $event_id ): bool` — `stream_capable() && access_role_enabled`. The master switch every other entry point checks first; `true` for nearly every event, because the switch defaults on. `false` means this event is not in the feature and every code path returns its pre-feature answer. **Not "has a room"** — that is `Module::room_url() !== ''`.
- `can_access_stream( $event_id, $session_index = 0, $user_id = 0 ): bool` — the one access question. Needs a resolvable stream as well as the role, so holding `anchor_event_{id}` on a plain event is not stream access.
- `backfill( $event_id ): int` — grant the role to every confirmed seat now, creating accounts as needed. Returns how many were newly granted; idempotent; returns 0 when `enabled()` is false. This is how an event that was already selling is reconciled with the switch.
- `role_for( $event_id, $create = true ): string` / `role_name()` / `role_members()` / `delete_role()`
- `grant( $event_id, $user_id, $source = 'seat' )` / `revoke( … )` — `$source` is `seat` or `manual`; a manual grant is never downgraded by a seat cancellation.
- `downgrade_manual_grant_to_seat( $event_id, $user_id ): bool` — the one legitimate manual → seat rewrite, bypassing `grant()`'s "manual outranks seat" guard on purpose. `Roster::revoke_access()` calls it when an operator revokes a manual grant on someone who still independently holds a confirmed seat: the role stays, but the record now reads `seat`, so that seat's own later cancellation can go on to revoke it.
- `ensure_user( array $seat ): int` — resolve or create the account a seat entitles.
- `login_token( $user_id, $event_id )` / `verify_login_token( $token, $event_id )` / `room_url_for( $user_id, $event_id )`
- `meets_prerequisites( $event_id, $user_id = 0 ): bool` / `prerequisite_message( $event_id ): string`

**`Anchor\Events\Stream_State`** — `for_event( $event_id, $now = 0 )`, `decide( $sessions, $open_before, $close_after, $now, $capable )`.

**`Anchor\Events\Embed`** — `normalize( $input )` (array or `WP_Error`), `render( array $embed, $title )`, `providers()`.

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
  `startDate` is taken from the earliest live child, and `endDate` from the LATEST
  end among only the children that actually produced a `subEvent` node — a live
  child with no usable start date is skipped for both (RENDER-D6). Zero live
  children → `[]` (nothing advertised).
- A **`multisession`** event renders one node spanning its earliest session start to
  its latest session end, with one minimal `Event` stub per session in `subEvent`.
- Anything else (**`single`**) renders one plain node. A **group child** additionally
  carries `superEvent` (`{@type, name, url}` of its live parent).

**Status** (`eventStatus`) comes from `Module::get_event_status()` and maps
`cancelled` → `EventCancelled`, `postponed` → `EventPostponed`, `moved_online` →
`EventMovedOnline`, anything else → `EventScheduled`. `postponed`/`moved_online`
are manual-only status choices (`get_status_options()`), same as `cancelled` —
nothing computes them. Either one adds `previousStartDate` to the node when
`_anchor_event_previous_start` is set; that meta is written once, by the shared
save path (`Module::persist_event_authoring()` → `maybe_persist_previous_start()`),
the moment an event's status transitions INTO postponed/moved_online, or its start
date changes again while already in one of those states.

**Capacity**: when `capacity` (the "Maximum capacity" field) is > 0, the node
carries `maximumAttendeeCapacity` (that value) and `remainingAttendeeCapacity`
(`Registrations::remaining_capacity()` — the same capacity authority
`choose_date_availability_hint()` reads). Capacity 0 means unlimited and neither
key is published, same convention as `availability`.

**isAccessibleForFree**: `true` whenever registration_mode is `free` AND a live
free Offer was actually emitted (i.e. `offers` is non-empty) — omitted, like
`offers` itself, for a finished/closed/registration-off-with-nothing-to-sell
event.

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
| `anchor_events_inherited_keys` | `$keys` (`Occurrences::INHERITED_KEYS`) | The schema-fact keys inherited from a group parent to its occurrence children, read through `Occurrences::inherited_keys()` rather than the constant directly. Every key is UNPREFIXED, the same convention `INHERITED_KEYS` itself uses (e.g. `venue`, or Anchor Speakers' `speaker_ids`): `inherited_meta_keys()` (via `meta_key()`) is the single place that adds the `_anchor_event_` prefix, so a filtered-in key `foo` ends up stored as `_anchor_event_foo`. Same single-value-key constraint as `anchor_events_inherited_meta_keys` below: each key is copied with `get_post_meta( …, true )` and deleted wholesale when the parent has none. |
| `anchor_events_inherited_meta_keys` | `$keys, $parent_id, $child_id` | The full (prefixed) meta keys an occurrence child inherits from its group parent. Single-value keys only — each is copied with `get_post_meta( …, true )` and deleted wholesale when the parent has none, so a multi-row key (the DEKA theme's `_deka_event_speaker_ids`, say) must NOT be added here. |
| `anchor_events_labels` | `$rows, $post_id` | Resolved event label rows (`{key, label, value, caption}`) — inject or rewrite a label before render. |
| `anchor_events_labels_vocabulary` | `$vocabulary` | The label `key => caption` map. Add a site-specific key here instead of overloading `custom`. |
| `anchor_events_embed_allowed_html` | `$default_allowed` | `wp_kses()` allowlist for the External-mode `external_embed` field. |
| `anchor_events_email_template_allowed_html` | `$default_allowed` | `wp_kses()` allowlist for admin-authored email template HTML (see `EMAILS.md`). |
| `anchor_events_schema_default_currency` | `$default, $event_id` | Override the `priceCurrency` used in JSON-LD Offers. |
| `anchor_events_emit_event_schema` | `$should_emit, $event_id` | Suppress/force JSON-LD emission for an event. |
| `anchor_events_should_send_reminder` | `true, $seat, $offset` | Per-recipient reminder-email suppression. |
| `anchor_events_query_args` | `$query_args, $atts` | Adjust the `WP_Query` args behind event listing shortcodes. |
| `anchor_events_auto_append_registration` | `true, $post_id` | Return `false` to suppress the automatic `[event_registration]` append on save (NEW-D6) — checked independently of, and after, the `add_theme_support( 'anchor-events-registration' )` theme opt-out (either one suppresses it). |
| `anchor_events_event_classes` | `$classes, $post_id, $context` | Extra CSS classes on a rendered event card/row. |
| `anchor_events_registration_form` | `'', $post_id, $meta` | Override seam — return non-empty HTML to replace the registration form entirely (used by the WooCommerce integration for the ticketed buy UI). |
| `anchor_events_registration_email_html` | `$html, $ctx` | Final filter on any built registration/lifecycle email HTML. |
| `anchor_events_default_email_template` | `$html, $type` | The shipped default body for one email type (`confirmation` \| `reminder` \| `cancellation` \| `roster`) — the fallback behind "Reset to default". Override one type without touching the other three. |
| `anchor_events_capability` | `$cap, $wc_active` | The single capability every roster / export / resend / console surface resolves. Default: `manage_woocommerce` on a WooCommerce site, else `edit_others_posts`. A non-string or empty return is ignored. |
| `anchor_events_schema_node` | `$node, $event_id` | RENDER-D10. Fires at the end of `Event_Schema::assemble_node()`, so it runs on EVERY node it builds (single events, group children, and both the multisession/group-parent header nodes), before `subEvent` is attached to a parent. Return the (possibly decorated) node array. Used by the DEKA theme (`deka-structured-data.php`) to add `performer` from linked speakers, tie `organizer` to a site `@id`, and fall back an `image`; that snippet also recurses over `$node['subEvent']` itself, which is what still reaches a multisession event's per-session stubs (those are built as raw arrays, not through `assemble_node()`, so this filter never sees them directly). Also used by the Anchor Speakers module (Task 10, `anchor-speakers/class-speaker-events.php`, loaded only when this module is active) to append `performer` entries (`@type`, `name`, `url`, optional `image`/`jobTitle`) from the event's linked speakers, resolved via `Anchor_Speakers_Module::event_speaker_ids()` (which falls back to the group parent's speakers when an occurrence child has none of its own), onto any `performer` a theme already added. |
| `anchor_events_emit_canonical` | `$emit, $seo_plugin_active` | RENDER-D21. Whether `output_canonical_url()` — a fallback for a `?anchor_events_month=` calendar URL — should print its own `<link rel="canonical">`. Default: `! $seo_plugin_active`, i.e. `false` (stay silent) whenever Yoast, Rank Math, All in One SEO or SEOPress is detected active, since each of those already emits/filters its own canonical for the same URL; `true` otherwise. Return `true` to force this module's tag even alongside a detected SEO plugin, or `false` to always suppress it. |
| `anchor_events_can_access_stream` | `$allowed, $event_id, $session_index, $user_id` | The final say on room access. The courses module vetoes here ("finish the pre-work first"). |
| `anchor_events_embed_providers` | `$providers` | The stream provider table — `slug => { hosts[], kind: iframe\|link, transform }`. Add a host to allow it. |
| `anchor_events_create_account` | `$create, $event_id, $email` | Return `false` to stop the module creating accounts for registrants who have none. Since the access switch defaults on, this is the site-wide way to say "do not make accounts for my attendees"; opting out means those guests hold no event role and get no room access. |
| `anchor_events_room_denied_message` | `$message, $event_id` | The wording a signed-in but unentitled visitor sees in the room. |
| `anchor_events_room_login_notice` | `$message, $event_id` | The notice shown above the sign-in form when an invalid/expired one-click `?aek=` link brought a logged-out visitor to the room. |
| `anchor_events_stream_now` | `$now, $event_id` | The instant `Stream_State` reasons about. Exists for end-to-end tests; filtering it in production lies to the room. |

---

## Actions

| Action | Args | When |
|---|---|---|
| `anchor_events_seat_created` | `$seat_id, $status` | A seat was created, after every meta write. The companion to `anchor_events_seat_status_changed`, and not a duplicate: a seat is usually BORN in its final status and never transitions. |
| `anchor_events_seat_status_changed` | `$seat_id, $from, $to, $actor` | An actual status transition only — a same-status note-only call never fires it. |
| `anchor_events_access_granted` | `$event_id, $user_id, $source` | A user gained the event role. `$source` is `seat` or `manual`. |
| `anchor_events_access_revoked` | `$event_id, $user_id, $source` | A user lost the event role. |

Both access actions fire from the backfill too — it grants through the same
`grant_for_seat()` path a live registration uses, so a backfilled attendee is
indistinguishable from one who registered normally.
