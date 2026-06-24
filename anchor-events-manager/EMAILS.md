# Anchor Events Manager — Email System

## Overview

The Events Manager module sends transactional and lifecycle emails to attendees and organizers. **Transactional emails** (existing) include buyer confirmation, organizer new-registration notice, and organizer seats-released notice. **Lifecycle emails** (new) add pre-event reminders to confirmed attendees, attendee-facing cancellation/refund notifications, and organizer roster digests (manual and scheduled).

All emails use a consistent HTML shell and a unified, documented token/placeholder system that works identically on free (non-WooCommerce) and paid (WooCommerce) sites.

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

- **No new HTML shells:** All emails use the shared `Module::build_registration_email_html()` method, ensuring consistent branding and responsive rendering.
- **No PII in logs:** Failure logs via `send_html_email()` log errors only, not recipient names or email addresses.
- **Localization:** All user-facing strings use the text domain `anchor-schema`.
- **Send outside locks:** Cancellation emails are deferred until after event locks release to avoid blocking seat operations on slow SMTP.
- **Filter hook:** `apply_filters('anchor_events_should_send_reminder', $bool, $seat, $offset)` allows custom per-recipient reminder suppression (e.g., honor CRM opt-out).
