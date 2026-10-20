# Events email log — design

## Why
A registrant (Dr. Noha Mechael, Toronto Mini Residency) said she never got the
"details" email. Answering that took an hour of digging through WP Mail Logging,
FunnelKit tables and Mailgun's short-retention event API. Admins need to answer it
themselves in seconds, and fix it with one click.

## What admins get
On a registrant's Edit view (wp-admin roster `?edit_seat=` and the front-end Events
Manager console `?seat_id=`), an **Emails** section listing every email sent to that
registrant for that event, newest first:

- when it was sent (site timezone), what it was (label + subject)
- delivery: Sent (waiting) / Delivered / Deferred / Bounced / Complained / Failed /
  Imported (no tracking — sent before logging existed)
- Opened (first time + count), Clicked (first time + count)
- **View** (the exact HTML that was sent) and **Resend** (same content, same
  recipient; arm-then-confirm button, no browser dialog)

Who sees it: whoever can manage events (`Module::events_capability()`). Nobody else.

## Decisions (my calls)
- **One generic primitive in `includes/email/`** (`Anchor_Mail_Log`): a table, a
  capture API (`begin()` / `wp_mail` filter / `finish()`), a Mailgun webhook
  receiver, view/resend endpoints and a reusable panel renderer. Events is the first
  consumer; nothing in the primitive knows about events.
- **Tracking comes from Mailgun webhooks**, not self-hosted pixels. Every logged
  message carries `X-Mailgun-Variables: {"anchor_mail_id":N,"anchor_site":"<host>"}`
  (proven on Live: arrives as `user-variables` on delivered events) and per-message
  `X-Mailgun-Track: yes`, `X-Mailgun-Track-Clicks: htmlonly`, `X-Mailgun-Track-Opens: yes`.
  Domain-level tracking stays off so the rest of the site's mail is untouched.
  (Anchor Announcements keeps its own pixel tracking and sends `X-Mailgun-Track: no`;
  the overlap is noted, not merged.)
- **Webhook matching** needs `anchor_mail_id` AND `anchor_site` = this site's host AND
  the event's recipient = the row's recipient (messages Bcc `education@` and
  `webforms@`; their opens must not count as the attendee's). Staging shares the
  Mailgun domain; its events carry staging's host and are ignored on Live.
- **Signature**: HMAC-SHA256(signing key, timestamp . token) must match, timestamp
  within 15 minutes. Signing key stored in option `anchor_mail_log_settings`
  (`autoload=false`), editable in Events → Settings → Email Sender, which also shows
  the webhook URL to paste into Mailgun.
- **Status never goes backwards**: failed/bounced/complained > delivered > deferred > sent.
  Opens/clicks only increment counters and set first-seen times.
- **Resend** replays the stored subject, HTML and headers (old Mailgun variable/track
  lines stripped, new ones added) to the stored recipient, as a NEW row with
  `source=resend`, `resend_of=<id>`, `sent_by=<user>`. Staff Bcc lines are kept, so
  staff see the resend.
- **WooCommerce customer emails** ("Thank You For Registering!" etc.) are logged when
  the order contains event line items, attached at order level (`seat_id=0`).
- **Backfill** from WP Mail Logging (`wp_wpml_mails`, back to 2025-05-28) via WP-CLI,
  dry-run by default. Live has 33 seats, all Toronto (1119879), so in practice this is
  the Toronto history incl. FunnelKit's "Mini Residency Course" email. FunnelKit bodies
  are cleaned: `bwfan-link` tracking URLs unwrapped to the real URL, open pixel and
  dead unsubscribe links removed.
- Stored HTML can contain one-click sign-in links (`{room_link}`), so View/Resend are
  capability + nonce checked and View is served with a sandbox CSP and `noindex`.

## Non-goals
Sending to a different address, editing before resend, a site-wide mail log screen,
retention/pruning, self-hosted pixel tracking, replacing Announcements' tracking.

## Anti-goals
- Rewriting any email content or template that exists today.
- Turning on Mailgun domain-wide tracking.
- Counting staff Bcc opens as the attendee's.
