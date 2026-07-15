# Anchor Events Manager — Email System

## Overview

The Events Manager module sends transactional and lifecycle emails to attendees and organizers. **Transactional emails** (existing) include buyer confirmation, organizer new-registration notice, and organizer seats-released notice. **Lifecycle emails** (new) add pre-event reminders to confirmed attendees, attendee-facing cancellation/refund notifications, and organizer roster digests (manual and scheduled).

All emails use a consistent HTML shell and a unified, documented token/placeholder system that works identically on free (non-WooCommerce) and paid (WooCommerce) sites.

There are two token systems, used in two different places (both driven by the same
`Module::expand_email_tokens( $template, $tokens )` `{token}`-replace helper):

1. **Subject/intro tokens** (`Module::email_tokens( $ctx )`) — expand the plain-text
   Settings fields below (`reminder_subject`, `roster_intro`, etc). This is the token
   table in this section.
2. **Body-template tokens** (scalar + block) — expand the per-event/global **email
   HTML body** templates edited in the Emails builder metabox. See "Editable Email
   Templates" below for that (larger, overlapping) token set.

---

## Email Templates & Tokens

All emails expand placeholders from the documented token set below. Each token is available in the subject and intro fields (via settings); email bodies are code-built and not customizable.

| Token | Source | Empty when |
|---|---|---|
| `{event_title}` | Post title | — |
| `{event_url}` | Post permalink | — |
| `{event_date}` | Localized date from `start_ts` (site format) | No start date configured |
| `{event_time}` | Localized time from `start_ts` | All-day event or no time |
| `{venue}` | Event venue field, or "Online" for virtual events | — |
| `{days_until}` | Days remaining until event start | Past event |
| `{attendee_name}` | Registered attendee name | Organizer/roster context (no attendee) |
| `{join_link}` | Virtual event URL for confirmed virtual attendees | Not a virtual event, or attendee not confirmed |
| `{remaining}` | Remaining seats from `get_event_summary()` | — |
| `{seat_count}` | Confirmed attendees (roster) or total seats in order (confirmation) | — |
| `{order_number}` | WooCommerce order number | Free seat or WooCommerce not installed |
| `{order_url}` | WooCommerce customer order URL | Free seat or WooCommerce not installed |
| `{status}` | Attendee registration status | — |
| `{site_name}` | Site name from `get_bloginfo('name')` | — |

---

## Email Settings

All settings below are registered in the Events tab of **Settings > Anchor Tools**. Defaults prioritize opt-in behavior (reminders off, cancellation on, scheduled roster off) to prevent surprise emails on upgrade.

### Reminders (Pre-event notifications)

| Setting | Default | Notes |
|---------|---------|-------|
| **Enable reminders** (`reminder_enabled`) | `false` | Master toggle. Off by default so existing sites don't auto-email attendees on upgrade. |
| **Reminder offsets** (`reminder_offsets`) | `7,1` | CSV of whole days before event start (e.g. `7,1` → send at 7 days and 1 day before). Sanitized to 1–5 offsets, sorted descending. Per-event override available in the event metabox. |
| **Reminder subject** (`reminder_subject`) | "Reminder: {event_title} is coming up" | Token-expanded subject line. |
| **Reminder intro** (`reminder_intro`) | "This is a friendly reminder that you are registered for {event_title} on {event_date}. We look forward to seeing you." | Token-expanded intro paragraph. |

**Behavior:** Reminders are sent once per offset per confirmed attendee via an hourly scheduled cron. The join link is auto-included for confirmed virtual events. A `apply_filters('anchor_events_should_send_reminder', true, $seat, $offset)` filter allows per-site customization (e.g., honor a CRM preference).

### Cancellation / Refund Notifications

| Setting | Default | Notes |
|---------|---------|-------|
| **Notify on cancellation** (`notify_cancellation`) | `true` | Enables attendee cancellation/refund emails. Applies to both free seats and WooCommerce orders. |
| **Cancellation subject** (`cancellation_subject`) | "Your registration for {event_title} has been cancelled" | Token-expanded. Wording automatically switches to "refunded" for refund-status seats. |
| **Cancellation intro** (`cancellation_intro`) | "Your registration for {event_title} has been cancelled. If this is unexpected, please contact us." | Token-expanded intro paragraph. |

**Behavior:** Sent once per attendee when a seat transitions from confirmed/waitlist into cancelled or refunded status. Covers all cancellation paths: paid order cancels/refunds, manual roster cancels, and trashed orders. The `{status}` token in templates allows dynamic wording.

### Organizer Roster Digest

| Setting | Default | Notes |
|---------|---------|-------|
| **Send scheduled roster** (`organizer_roster_email`) | `false` | Enables automatic roster send before the event. |
| **Auto-send roster offset** (`roster_auto_offset`) | `1` | Whole days before event start to auto-send (e.g. `1` → send the day before). |
| **Roster subject** (`roster_subject`) | "Final roster for {event_title}" | Token-expanded subject. |
| **Roster intro** (`roster_intro`) | "Here is the current confirmed roster for {event_title} on {event_date}." | Token-expanded intro. |

**Behavior:** The organizer can manually send the roster at any time via the "Send roster to organizer" button on the Roster screen. If auto-send is enabled, the roster is sent automatically once at the configured offset before the event. The roster includes a confirmed-attendee list with names, emails, and phone numbers, plus summary counts (confirmed, pending, waitlist, remaining).

---

## Editable Email Templates

Each of the four lifecycle email types (`confirmation`, `reminder`, `cancellation`,
`roster` — `Module::EMAIL_TEMPLATE_TYPES`) has its own **editable HTML body**, in
addition to the plain-text subject/intro Settings fields above. All four ship with
the same default shell (`Module::default_email_shell()`) — a documented starting
point, not a hard requirement that they stay identical.

### Resolution order

`Module::resolve_email_template( string $type, int $event_id ): string` resolves the
effective template for a type on a given event, in order:

1. **Per-event override** — post meta `_anchor_event_email_tpl_{type}` on the event
   (registered via `register_post_meta()` for all four types), when non-empty.
2. **Global default** — the site-wide option `anchor_events_email_tpl_{type}`
   (`Module::get_email_template_option( $type )`), when non-empty.
3. **Default constant** — `Module::default_email_template( $type )`, currently the
   same shared shell for every type.

`resolve_email_template( $type, 0 )` (no event id) skips step 1 and resolves the
global-or-default fallback only — this is also what a per-event save compares against
to decide whether to store an override at all (see below).

### Per-event Emails builder metabox

The event editor's **Emails** metabox (`Module::render_email_builder_metabox()`) has
one tab per email type, each with:

- A **Monaco HTML editor** (the same `Anchor_Monaco` wrapper used by `anchor-blocks`),
  pre-filled via `resolve_email_template( $type, $post->ID )` — i.e. the per-event
  override if one exists, else the effective global/default template.
- A **token-insert palette** — buttons for the curated subset of body tokens
  documented as safe/useful to hand-insert (`Module::documented_email_tokens()`; see
  the token table below — the palette omits a few internal-only tokens like
  `{event_id}`/`{status}`/`{greeting}`/`{waitlist_notice}`/`{join_button}` and offers
  no `{footer}` token since the footer region only ever substitutes `{site_name}`).
- A **live preview iframe** that shows the raw template with tokens literal until...
- ...**"Preview with real data"** is clicked, which AJAX-renders the in-progress
  (unsaved) editor content through the exact same `build_registration_email_html()`
  renderer real sends use, substituting representative sample data — without writing
  anything, via a transient, request-scoped override
  (`Module::$preview_template_override`) consumed by `resolve_email_template()` and
  cleared in a `finally` block.
- A **"Reset to default"** button (writes the fallback text back into the editor).

**Save path**: `Module::save_email_templates()` is a dedicated, validated path called
from `save_meta()` — never the generic settings allow-list. Each submitted tab's HTML
is run through an **email-safe `wp_kses()` allowlist**
(`Module::get_email_template_allowed_html()` — filterable via
`anchor_events_email_template_allowed_html`) covering the shell's structural/table
tags plus common formatting tags with a restricted `style` attribute (further
constrained to WordPress's safe-CSS property list); `<script>` and anything else off
the allowlist is stripped entirely, open tag through close tag. Content that matches
the event's override-less resolved default is stored as `''` instead of a redundant
literal copy — same effect as clicking "Reset to default" and saving.

### Body-template token set

These are the tokens available inside an editable email **body** (distinct from —
and a superset of — the subject/intro token table earlier in this document). Built
in `Module::build_registration_email_html()`:

**Scalar tokens** (HTML/URL-escaped before substitution — `event_title`/`site_name`
are pre-escaped for `<title>`/`<h1>`/footer use, everything else is escaped inline so
a custom template can't become a stored-injection vector):

| Token | Source |
|---|---|
| `{event_id}` | Event post ID |
| `{event_title}` | Event post title |
| `{site_name}` | `get_bloginfo('name')` |
| `{attendee_name}` | Registered attendee name |
| `{status}` | Attendee registration status |
| `{join_link}` | Virtual-event join URL (confirmed attendees of a virtual event only) |
| `{event_url}` | Event permalink |
| `{event_date}` | Localized date from `start_ts` |
| `{event_time}` | Localized time from `start_ts` (empty for all-day events) |
| `{venue}` | Venue name, or "Online" for virtual events |
| `{days_until}` | Days remaining until start (empty for past events) |

**Block tokens** (pre-rendered HTML fragments for structured/conditional regions):

| Token | Renders |
|---|---|
| `{intro}` | The confirmation/reminder/cancellation intro message, as `<p>` paragraphs |
| `{header_image}` | The event's featured image, when set |
| `{greeting}` | "Hi {name}," style greeting block |
| `{guests_line}` | Guest-count line, when guests > 0 |
| `{waitlist_notice}` | Waitlist-specific notice, when status is `waitlist` |
| `{detail_rows}` | A table of label/value detail rows |
| `{seat_list}` | A list of named seats (multi-seat orders) |
| `{join_button}` | A styled "Join" button linking `{join_link}`, when set |
| `{cta_button}` | A styled call-to-action button (e.g. "View event details") |

The **token-insert palette** in the Emails builder UI offers a curated subset of the
above (`event_title`, `event_date`, `event_time`, `venue`, `attendee_name`,
`join_link`, `event_url`, `site_name`, `intro`, `detail_rows`, `seat_list`,
`cta_button`, `header_image`) — every other token above is still expanded if
hand-typed into the editor, it's just not offered as a one-click button.

---

## Upcoming Sends Panel

The event editor's **Upcoming Sends** metabox (side column;
`Module::render_upcoming_sends_metabox()`) is a **read-only** schedule of this
event's pending/sent reminder and roster-digest emails, computed on the fly by
`Module::compute_email_schedule( int $event_id ): array` from the exact same inputs
the hourly sweep itself uses (effective offsets, the settings flags, the per-seat
`_anchor_event_reminders_sent` markers, and the per-event `roster_sent` marker) — no
new storage, no send/reschedule side effects, just a report of what the sweep already
has done or will do.

Each row has a `type` (`reminder`|`roster`), a `scheduled_ts`, a recipient
description, and a `state` (`sent` | `partial` — reminders sent to some but not all
confirmed seats at that offset | `scheduled` | `past` — a scheduled_ts already gone by
that was never sent).

A **group parent** never shows a schedule of its own — parents don't take
registrations, so the panel shows an explanatory notice pointing at each date's own
event instead (each child computes its own schedule independently, exactly like a
standalone event). Other notice states cover an invalid event id, reminders+roster
both disabled site-wide, and no start date/time set yet.

---

## Recipient Resolution

**Reminder & cancellation emails** go to the attendee's registered email address (`_anchor_event_email` post meta).

**Roster digest** goes to the organizer's email, resolved in order:
1. Per-event `_anchor_event_organizer_email` (if set)
2. Global `organizer_email` setting
3. Site `admin_email`

---

## Scheduling & Idempotency

### Reminders & Scheduled Roster

Both are executed by a single recurring cron hook (`anchor_events_reminder_sweep`) on the **hourly** schedule. This cron:
- Runs once per hour and identifies events whose reminders or roster digests are due based on event start time and configured offsets.
- Uses idempotency markers to ensure each email sends exactly once per offset (reminders) or per event (roster), even if cron overlaps or the event date is moved.
- Respects event timezones via the pre-computed `start_ts` Unix timestamp (no extra timezone math needed).

**Reminder marker:** Per-seat `_anchor_event_reminders_sent` meta (array of `offset_days => sent_unix`). Each offset fires at most once.

**Scheduled roster marker:** Per-event `_anchor_event_roster_sent` meta (Unix timestamp of send, or 0 if not sent). Fires once per event.

### Manual Roster Send

The "Send roster to organizer" button on the Roster screen may be clicked repeatedly to re-send the digest on demand. It is not idempotent by design — this is an explicit admin action.

---

## WooCommerce-Optional Design

All reminder, cancellation, and roster emails work identically on sites with or without WooCommerce:

- **Free sites:** Seats are always active; emails send via the same code paths.
- **WooCommerce sites:** Paid seats are reconciled with orders; idempotency markers prevent duplicate sends across reconcile re-runs.
- **Token availability:** Order-specific tokens (`{order_number}`, `{order_url}`) are empty for free seats (guarded by `_anchor_event_order_id > 0` and `function_exists('wc_get_order')`).

---

## Development Notes

- **One shared renderer:** All emails render through the single `Module::build_registration_email_html()` method — per-event/global template overrides (see "Editable Email Templates" above) change which HTML string that method expands tokens into, they never bypass it, so branding/responsive structure stays consistent even when a site fully customizes a type's body.
- **No PII in logs:** Failure logs via `send_html_email()` log errors only, not recipient names or email addresses.
- **Localization:** All user-facing strings use the text domain `anchor-schema`.
- **Send outside locks:** Cancellation emails are deferred until after event locks release to avoid blocking seat operations on slow SMTP.
- **Filter hooks:**
  - `apply_filters('anchor_events_should_send_reminder', $bool, $seat, $offset)` — per-recipient reminder suppression (e.g., honor CRM opt-out).
  - `apply_filters('anchor_events_email_template_allowed_html', $default_allowed)` — extend the `wp_kses()` allowlist used to sanitize custom email template HTML on save and on the "Preview with real data" endpoint.
  - `apply_filters('anchor_events_registration_email_html', $html, $ctx)` — final filter on the fully-rendered HTML of any registration/lifecycle email before send.
