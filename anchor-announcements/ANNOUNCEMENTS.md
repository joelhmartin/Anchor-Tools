# Anchor Announcements

Operator guide for the `announcements` module. Design background: `docs/superpowers/specs/2026-09-30-anchor-announcements-design.md`.

## What it does

Write a branded email in the admin, choose who gets it with AND/OR rules (roles, sign-up date, profile fields, specific people, WooCommerce purchases, Anchor Courses enrolments, Anchor Events registrations), send it in background batches through `wp_mail()`, and see who opened, who clicked and who unsubscribed. Tracking is self-hosted, so it works the same on Mailgun, Postmark, SES or plain SMTP. Nothing in the module talks to a mail provider.

## Setup

1. Turn the module on: Anchor Tools settings > modules > **Anchor Announcements**. An "Announcements" menu appears for users with the `anchor_send_announcements` capability (administrators get it automatically).
2. Announcements > Settings: set the **mailing address** (required; nothing can be sent until it is filled, it prints in every footer for CAN-SPAM and CASL), plus from name, from email, reply-to, brand color and logo. **Batch size** is emails per minute (default 50, 1 to 500). Settings are stored in one non-autoloaded option, `anchor_announcements_settings`.
3. Point the site's SMTP or mail plugin at your provider (Mailgun or anything else). Announcements only calls `wp_mail()`.
4. Provider click tracking: every tracked send carries `X-Mailgun-Track: no`, so Mailgun will not rewrite links a second time. Other providers ignore that header; turn their own click tracking off if you want one set of numbers, or leave it on and accept two.

## Composing and tokens

Announcements > Add New. Fill the internal name (title), subject, optional preheader, and the message in the Design tab (visual editor) or the HTML tab (code). The preview on the right is rendered by the real renderer. Body HTML is passed through the email sanitizer on save: tables, inline styles, images and links are kept; scripts, forms and the `data:` URL scheme are removed.

Click a token in the palette to insert it. Tokens are replaced per recipient. In the body they are HTML-escaped; in the subject they are plain text.

| Token | Value | Can be empty |
|---|---|---|
| `{first_name}`, `{last_name}` | From the user's profile, else split from the name on the order or seat | Yes, for guests with no name |
| `{display_name}` | The user's display name, else the name on the order or seat | Yes, for guests with no name |
| `{username}` | Login name | Always empty for guests (no account) |
| `{email}` | Recipient address | No |
| `{site_name}`, `{site_url}` | Site title and home URL | No |
| `{login_url}` | WordPress login URL | No |
| `{account_url}` | WooCommerce My Account page when WooCommerce is active, else the profile screen | No |
| `{unsubscribe_url}` | The recipient's one-click unsubscribe link | No |

An unsubscribe link is always added to the footer, whether or not you use `{unsubscribe_url}` in the body. **Send a test** (sidebar) sends the current, even unsaved, content to the address you type, with your own tokens, a `[Test]` subject prefix and no tracking rows.

## The audience builder

The audience is a list of **groups**. A person is included if they match any group (groups are OR'd). A group has **conditions**, and a person must match all of them (conditions are AND'd). Each condition has an **is / is not** toggle: "is not" removes the people who match it. A group made only of "is not" conditions starts from everyone on the site, so the builder warns that it targets nearly everyone.

A recipient is an email address. A user and a guest buyer with the same address are one recipient, and the user's data wins. Guest WooCommerce buyers and event registrants without accounts are included.

| Condition | Shown when | Fields |
|---|---|---|
| User role | always | roles (any of) |
| Registered | always | from, to |
| User field | always | meta key, compare (`=`, `!=`, contains, exists, not exists, `>`, `<`), value |
| Specific people | always | pick users and/or paste addresses |
| Purchased | WooCommerce active | products and variations (any of; empty means any product), from, to, order statuses (default completed and processing) |
| Order count | WooCommerce active | compare, number, from, to |
| Total spent | WooCommerce active | compare, amount, from, to |
| Enrolled | Anchor Courses on | courses (any of), status (active, completed, any), enrolled from, to |
| Completed a course | Anchor Courses on | courses, completed from, to |
| Registered for event | Anchor Events on | events (any of), seat statuses (default confirmed), registered from, to |

Dates are calendar days in the site timezone, inclusive, and either end can be left open. WooCommerce conditions work with both HPOS and legacy order storage. **Preview audience** shows the count after unsubscribes and the first 25 people.

The audience is a **snapshot taken when sending starts**. A scheduled announcement resolves when its time arrives, not when it was scheduled. After that the content and audience are locked.

## Sending

- **Send test**: see above.
- **Send now**: re-checks the count, asks you to confirm, then queues one row per recipient. If nobody is left after removing unsubscribed addresses, it refuses with a message and creates nothing.
- **Schedule**: pick a date and time (site timezone). **Unschedule** returns it to draft.
- **Pause / Resume** while rows are still queued. **Cancel** marks the remaining queued rows skipped (reason `cancelled`).
- Delivery runs on a one-minute WP-Cron event, `anchor_announcements_tick`, which sends up to the batch size per minute, oldest first, and releases due scheduled announcements. A MySQL lock stops overlapping ticks, and each row is claimed individually so no one is emailed twice.
- **At-most-once delivery.** A `wp_mail()` failure is retried on the next tick, up to 3 attempts, then the row is `failed` with the error. A send that was interrupted (a crash or timeout mid-send) is not retried: after 30 minutes it becomes `failed` ("interrupted") and is never resent automatically, because we cannot tell whether the mail already went out and a duplicate is worse than a miss. Resend to those people in a new announcement if you need to.
- **Suppression is re-checked at send time**: someone who unsubscribes after the snapshot but before their row is sent is skipped (`suppressed`).
- Sites with `DISABLE_WP_CRON` need a real server cron hitting `wp-cron.php`. The report shows the last queue run so a stalled cron is visible.
- Headers on every tracked send: `Content-Type: text/html`, `From`, `Reply-To` (when set), `List-Unsubscribe` with `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (Gmail and Yahoo bulk-sender rules), and `X-Mailgun-Track: no`.

## Tracking and what it can and cannot tell you

Three endpoints on the home URL (`?anchor_aa=o|c|u&t=<token>`) need no login and do not use REST.

- **Open**: a 1x1 image. Opens are **estimated and over-counted**: Apple Mail Privacy Protection and some company mail filters load images for the reader, and some clients block images so real opens go uncounted.
- **Click**: every `http(s)` link in the body is replaced with a redirect. The redirect target is read only from the link list stored when sending started, so it cannot be used as an open redirect; an unknown token or link goes to the home page. `mailto:`, `tel:`, `#` anchors and the unsubscribe link are left alone. Clicks are the reliable signal, but some security scanners pre-click links. A click within 10 seconds of the send, from a recipient who has not opened, is recorded but flagged "likely scanner" and kept out of the click rate.
- **Unsubscribe**: the link shows a confirmation page; confirming (or a mail client's one-click POST) adds the address to the suppression list. There is no resubscribe link; remove the address on the Unsubscribed screen.
- No IP address is stored; events keep the first 255 characters of the user agent.

## Reports and CSV

Open a sent announcement: the **Report** box shows totals (queued, sent, failed, skipped, opened, clicked, unsubscribed) and rates, a per-recipient table (status, first opened, opens, first clicked, clicks) with filters and name/email search, a per-link table (clicks and unique clickers), and a CSV export of the recipient table. The Announcements list shows status, audience, sent, opened %, clicked % and the date.

## Unsubscribed screen

Announcements > **Unsubscribed** lists suppressed addresses with the reason (`unsubscribed`, `bounced`, `complained`, `manual`), lets you search, add an address manually and remove one. Suppressed addresses are never sent to.

## Privacy

The module registers WordPress personal-data exporters and erasers (Tools > Export / Erase Personal Data), looked up by email. Export covers send, open and click data. Erase deletes the send and tracking rows but **keeps the suppression row** and reports it as retained ("kept so this address is never emailed again"), so an erased person is not emailed again.

## Extension points

| Hook | Type | Purpose |
|---|---|---|
| `anchor_announcements_conditions` | filter | Register more audience conditions (classes implementing `Audience\Condition`) |
| `anchor_announcements_tokens` | filter | Add or change the per-recipient token map; receives `( $tokens, $recipient )` |
| `anchor_announcements_mail_headers` | filter | Change the header list passed to `wp_mail()` |
| `anchor_announcements_universe` | filter | Add contacts to the audience universe (what "is not" subtracts from) |
| `anchor_email_allowed_html` | filter | Change the email sanitizer's allowed tags and attributes (shared kit) |
| `anchor_announcements_suppress` | action | Call `do_action( 'anchor_announcements_suppress', $email, $reason, $announcement_id )` from a provider webhook handler to suppress a bounced or complaining address. No provider adapter ships. |

## Shared email kit and the events migration

`includes/email/` holds the reusable pieces: `Anchor_Email_Shell` (the table-based layout), `Anchor_Email_Tokens` (token registry and escaping expansion), `Anchor_Email_Sanitizer` (email-safe allowlist) and `Anchor_Email_Kit::builder_markup()` with `assets/email-kit/` (the builder UI). Announcements uses it. The events module (`anchor-events-manager/`) still has its own copies; moving it onto the kit (shell, token expansion, sanitizer, and swapping `email-modal.js` for the kit builder) is a planned separate PR, design spec section 10.

## Testing notes

PHPUnit tests force WooCommerce HPOS on; set `WC_HPOS=0` to run the legacy order-storage path. The Playwright check is `e2e/announcements.spec.js` (needs `npm run wp-env start` and `npm run env:seed`).
