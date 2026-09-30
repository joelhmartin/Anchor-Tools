# Anchor Announcements: design

Date: 2026-09-30. Branch: `feat/anchor-announcements`. Owner request (verbatim intent): a back-end way to send email from the website with the same email builder as the events manager, tokens for name, username, links and so on, open and click tracking so the admin can see who opened what, working through Mailgun on every client but never tied to Mailgun. Follow-up in the same conversation: the audience needs a robust filter builder with AND and OR rules and date-bounded conditions ("if a person bought this product within a certain date").

## 1. What exists today (checked, not assumed)

- The email builder lives in the **events module**, not the DEKA theme. There are two:
  - the admin "Emails" metabox (`assets/email-builder.js`: Monaco HTML editor, token palette, live preview, "preview with real data");
  - the front-end manager modal (`assets/email-modal.js`: TinyMCE for the prose region, rendered preview through `anchor_events_email_preview`, an HTML tab for the whole document, a starter template, media button).
- Both, plus the email shell (`Module::default_email_shell()`), token expansion (`Module::expand_email_tokens()`) and the email-safe `wp_kses()` allowlist used by `save_email_templates()`, are methods on the 16k-line `Anchor\Events\Module`. Nothing is reusable without the events module loaded.
- Events sends with `wp_mail()`. That is the SMTP-agnostic seam: Mailgun, Postmark, SendGrid, SES or plain SMTP plug in underneath through whatever mail plugin the site runs.
- `assets/anchor-monaco.js` + `includes/class-anchor-monaco.php` are already plugin-wide and shared.
- Audience data sources:
  - WooCommerce: the order line tables `woocommerce_order_items` + `woocommerce_order_itemmeta` (`_product_id`, `_variation_id`), identical in both storage modes, joined to the order record: HPOS `wc_orders` (status, `date_created_gmt`, `customer_id`, `billing_email`, `total_amount`) + `wc_order_addresses` (billing names), or legacy `posts` + `postmeta` (`_billing_email`, `_customer_user`, `_order_total`, billing names), chosen with `OrderUtil::custom_orders_table_usage_is_enabled()`. Guest buyers are included (customer id 0, billing email). The analytics lookup tables (`wc_order_product_lookup`, `wc_customer_lookup`) are deliberately **not** used: they stop updating when a site turns WooCommerce Analytics off and lag behind Action Scheduler on busy stores.
  - Anchor Courses: table `anchor_courses_enrollments` (user_id, course_id, status, enrolled_at, completed_at).
  - Anchor Events: `Registrations::query_seats()` over `anchor_event_reg` seats (`_anchor_event_email`, `_anchor_event_name`, `_anchor_event_user_id`, `_anchor_event_reg_status`).

## 2. Scope

In:

1. A shared **email kit** in `includes/` (shell, tokens, sanitizer, renderer, builder UI) that Announcements uses and Events can move onto later.
2. The **announcements module** (`announcements`): compose, target, test, schedule or send, report.
3. An **audience rule builder**: groups joined by OR, conditions inside a group joined by AND, any condition negatable, date ranges on every dated condition.
4. **Self-hosted tracking**: open pixel, click redirect, one-click unsubscribe, suppression list. Works identically on any mail transport.
5. **Reports**: totals and rates, per-recipient table, per-link clicks, CSV export.

Out (named so they are not silently assumed):

- Moving the events module onto the kit (separate follow-up PR, section 10).
- Provider webhooks (Mailgun delivered/bounced/complained). The suppression table and a documented action are the seam; no adapter ships now.
- A drag-and-drop block designer. The builder is the events modal's model: visual prose editor inside a designed shell, plus the full HTML tab.
- Automations, drip sequences, A/B subjects, saved segments shared across announcements, non-email channels.
- Sending to lists imported from outside the site other than the "specific people" condition (pasted addresses).

## 3. Module shape

- Key `announcements`, label "Anchor Announcements", class `\Anchor\Announcements\Module`, file `anchor-announcements/anchor-announcements.php`, PSR-4 `Anchor\Announcements\` => `anchor-announcements/src/` (added to `composer.json`, same as courses).
- CPT `anchor_announcement` (not public, no front end, `show_ui` true, top-level menu "Announcements", dashicons-megaphone). The post is the draft and the record: `post_title` is the internal name; meta holds subject, preheader, body HTML, audience rules JSON, schedule time and send state.
- Capability `anchor_send_announcements`, granted to `administrator` on migration. Every admin screen, AJAX handler and send action checks it. Editing a draft uses the CPT's own caps mapped to the same capability, so a role without it never sees the menu.
- Database, created by `Database\Migrations::maybe_migrate()` on load and `admin_init` (the courses pattern, versioned option `anchor_announcements_db_version`):
  - `anchor_announce_sends`: one row per recipient per announcement. `id`, `announcement_id`, `email` (lowercased), `user_id` (NULL for guests), `name`, `token` (32 random hex, unique), `status` (`queued`, `sent`, `failed`, `skipped`), `skip_reason`, `error`, `attempts`, `queued_at`, `sent_at`, `first_opened_at`, `open_count`, `first_clicked_at`, `click_count`. Unique (`announcement_id`, `email`).
  - `anchor_announce_events`: `id`, `send_id`, `type` (`open`, `click`, `unsubscribe`), `link_index` (NULL unless click), `created_at`, `user_agent` (first 255 chars). No IP address is stored.
  - `anchor_announce_suppressions`: `email` (primary key), `reason` (`unsubscribed`, `bounced`, `complained`, `manual`), `source_announcement_id`, `created_at`.
- Settings (Announcements > Settings, one non-autoloaded option `anchor_announcements_settings`): from name, from email (defaults: site name, `wp_mail_from` default), reply-to, **footer postal address** (required before any send; CAN-SPAM and CASL require it), brand color and logo for the shell, batch size (default 50 per minute).

## 4. Email kit (`includes/email/`)

Non-namespaced classes, the `includes/` convention, loaded by `anchor-tools.php` like `Anchor_Monaco`:

- `Anchor_Email_Shell::render( array $args ): string`: the table-based shell ported from `default_email_shell()`, parameterised by brand color, logo, preheader, body HTML and footer HTML. One layout, many callers.
- `Anchor_Email_Tokens`: `expand( string $template, array $tokens, bool $html ): string` (`{token}` replace; values escaped with `esc_html()` when `$html`, URL tokens with `esc_url()`), and a token registry (`register( $key, $label, $group )`) the builder's palette reads.
- `Anchor_Email_Sanitizer::body( string $html ): string`: the email-safe `wp_kses()` allowlist ported from `save_email_templates()` (tables, inline styles, images, links; no script, no forms), filterable as `anchor_email_allowed_html`.
- Builder UI: `assets/email-kit/builder.js` + `builder.css`, ported from `email-modal.js` with neutral class names (`anchor-email-*`) and a config object (`previewAction`, `nonce`, `ajaxUrl`, `tokens`, `starter`) instead of the events globals. Left: subject, preheader, TinyMCE body. Right: rendered preview (AJAX, the real renderer, sample recipient). Tabs: Design (TinyMCE) and HTML (Monaco, the shared `Anchor_Monaco`). Token palette inserts at the cursor in whichever tab is active. Media button uses the WordPress media library.
- `Anchor_Email_Kit::builder_markup( array $args ): string` prints the builder so no consumer hand-writes its DOM.

The events files are not edited in this PR. Section 10 is how they move.

## 5. Tokens

Per recipient: `{first_name}`, `{last_name}`, `{display_name}`, `{username}` (empty for a guest), `{email}`. Site: `{site_name}`, `{site_url}`, `{login_url}`, `{account_url}` (WooCommerce My Account when WooCommerce is active, else the profile URL). Announcement: `{unsubscribe_url}` (always also in the footer, whether or not the author uses it), `{view_in_browser_url}` is out of scope.

A guest has no username: the token expands to empty, and a first name falls back to the name on the order or the seat, then to an empty string. The palette shows a one-line note on which tokens can be empty. `anchor_announcements_tokens` filters the per-recipient map so a site can add its own.

## 6. Audience builder

### 6.1 Model

Stored as JSON in meta `_anchor_announcement_audience`:

```json
{ "groups": [
  { "conditions": [
    { "type": "wc_purchased", "negate": false,
      "params": { "products": [123, 456], "from": "2026-01-01", "to": "2026-06-30", "statuses": ["completed", "processing"] } },
    { "type": "user_role", "negate": true, "params": { "roles": ["centre"] } }
  ] },
  { "conditions": [ { "type": "course_completed", "negate": false, "params": { "courses": [789], "from": "", "to": "" } } ] }
] }
```

Groups are OR'd; conditions in a group are AND'd; `negate` means "does not match". This two-level form (OR of ANDs) expresses every boolean filter a nested tree would, without a recursive UI.

### 6.2 Identity and universe

A recipient is an **email address** (lowercased), carrying `user_id` and `name` when known, so WooCommerce guest buyers, event registrants without accounts and pasted addresses are first-class. When a user and a guest record share an address they are one recipient, the user's data winning.

The universe (what `negate` subtracts from) is: every user with an email, every WooCommerce customer lookup row, every event seat email, plus any addresses named by a "specific people" condition in the same group. A group made only of negated conditions is allowed; the builder warns that it targets nearly everyone.

### 6.3 Conditions (v1)

Each is a class implementing `Audience\Condition` (`key()`, `label()`, `available()`, `fields()` for the UI, `match( array $params ): RecipientSet`). `Audience\Registry` lists only the available ones; `anchor_announcements_conditions` lets integrations register more.

| Key | Available when | Params |
|---|---|---|
| `user_role` | always | roles (any of) |
| `user_registered` | always | from, to |
| `user_field` | always | meta key, compare (`=`, `!=`, `contains`, `exists`, `not exists`, `>`, `<`), value |
| `specific_people` | always | users (picker) and/or pasted addresses |
| `wc_purchased` | WooCommerce | products and variations (any of; empty = any product), from, to, order statuses (default completed + processing) |
| `wc_order_count` | WooCommerce | compare (`>=`, `<=`, `=`), number, from, to |
| `wc_total_spent` | WooCommerce | compare, amount, from, to |
| `course_enrolled` | Anchor Courses | courses (any of), enrolment status (active, completed, any), enrolled from, to |
| `course_completed` | Anchor Courses | courses, completed from, to |
| `event_registered` | Anchor Events | events (any of), seat statuses (default confirmed), registered from, to |

Dates are site-timezone calendar days, inclusive, either end optional. Each condition resolves with one or two SQL queries returning email, user_id and name (the WooCommerce ones through one `Audience\\WooOrders` query builder that owns the HPOS/legacy split); set algebra happens in PHP (fine to tens of thousands of rows; anything larger is out of scope and noted).

### 6.4 UI and preview

The audience panel renders groups as cards ("Match ALL of these"), an "OR" divider between groups, "+ Add condition" and "+ Add OR group". Each condition row: type select, "is / is not" toggle, its fields (product, course and event pickers use `wp_ajax` searches). "Preview audience" returns the count after suppression plus the first 25 recipients; the count also shows on the send confirmation.

### 6.5 Snapshot

The audience is resolved **once, at send time**, into `anchor_announce_sends` rows. Suppressed addresses get a `skipped` row with `skip_reason = suppressed`, so the report can say who was left out and why. A scheduled announcement resolves when its time arrives, not when it was scheduled.

## 7. Sending

- Actions on a draft: **Send test** (to the current user or a typed address, their own tokens, no tracking rows, subject prefixed "[Test]"), **Schedule** (date and time, site timezone), **Send now** (confirm dialog with the audience count). A sending or sent announcement's content and audience lock read-only; it can be **paused** and **resumed** while queued rows remain, or **cancelled** (remaining rows become `skipped`, `cancelled`).
- Queue: a one-minute WP-Cron schedule `anchor_announcements_tick`, guarded by a MySQL `GET_LOCK`, takes up to the batch size of `queued` rows across announcements (oldest first), renders each (tokens, then link rewriting, then shell), and calls `wp_mail()`. A `false` return or an exception retries on the next tick up to 3 attempts, then `failed` with the error `wp_mail_failed` reported. The tick also releases scheduled announcements whose time has come.
- Headers on every tracked send: `Content-Type: text/html; charset=UTF-8`, `From`, `Reply-To`, `List-Unsubscribe: <{unsubscribe_url}>` with `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (Gmail and Yahoo bulk-sender requirement), and `X-Mailgun-Track: no` so Mailgun does not rewrite links a second time (other providers ignore the header). `anchor_announcements_mail_headers` filters the list.
- Sites with `DISABLE_WP_CRON` rely on their server cron, as every other Anchor Tools schedule does. The report shows "last queue run" so a stalled cron is visible.

## 8. Tracking

All three endpoints are query-var routes on the home URL (`?anchor_aa=o|c|u&t=<token>`), not REST, so they work with REST restricted and never need a login.

- **Open**: an `<img>` 1x1 GIF appended before `</body>`. A hit with a known token records an `open` event, bumps `open_count`, sets `first_opened_at` once, and always returns the GIF with no-cache headers.
- **Click**: at send time every `href` in the rendered body is replaced with the click URL plus `&l=<index>`, where the index points into the announcement's link list (meta `_anchor_announcement_links`, captured at send). `mailto:`, `tel:`, `#` anchors and the unsubscribe link are not rewritten. A hit records a `click`, bumps counts and redirects (302) to the stored URL. The redirect target only ever comes from that stored list, so the endpoint is not an open redirect; an unknown token or index goes to the home page.
- **Unsubscribe**: GET shows a small themed confirmation page ("Unsubscribe [email] from site emails?" with a button); POST, including the RFC 8058 one-click POST, adds the address to suppressions with reason `unsubscribed` and records the event. A resubscribe link is out of scope; an admin can remove a suppression on the Suppressions screen.
- Honesty in the report: opens are an over-count (Apple Mail Privacy Protection and some corporate gateways load images for the reader) and some security scanners pre-click links. The report labels opens "estimated" and shows clicks as the reliable signal. Clicks within 10 seconds of the send from a user agent that has never opened are flagged "likely scanner" and excluded from the rate (kept in the raw data).

`anchor_announcements_suppress( $email, $reason, $announcement_id )` is the documented action a future provider webhook (bounce, complaint) calls.

## 9. Reports and privacy

- Announcements list columns: status, audience, sent, opened %, clicked %, scheduled or sent date.
- Report tab on a sent announcement: totals (queued, sent, failed, skipped, opened, clicked, unsubscribed) and rates; per-recipient table (name, email, status, first opened, opens, first clicked, clicks) with filters (opened, not opened, clicked, failed, skipped, unsubscribed) and search; per-link table (URL, clicks, unique clickers); CSV export of the recipient table.
- Suppressions screen: list, search, add manually, remove.
- WordPress privacy exporter and eraser registered for sends, events and suppressions by email (an erased suppression keeps a hashed email so the person stays unsubscribed).

## 10. Follow-up: events onto the kit (separate PR)

Point `Anchor\Events\Module::default_email_shell()`, `expand_email_tokens()` and the template sanitizer at the kit, and replace `email-modal.js` with the kit builder configured with events' preview action and tokens. Events keeps its own token set and templates. This is deliberately not in this PR: the events class is 16k lines with its own test suite, and other work is active in this repo.

## 11. Testing

PHPUnit (the repo's WP test library setup), a base `Anchor_Announcements_TestCase`:

- Kit: token expansion and escaping, sanitizer keeps email markup and strips script, shell renders footer and preheader.
- Audience: each condition against fixtures (users, guest and registered WooCommerce orders across dates and statuses, enrolments, seats); AND, OR, negation and the universe; dedupe of a user and a guest sharing an address; suppression applied at snapshot.
- Queue: batch size honoured, lock prevents double processing, retry then fail on `wp_mail` false (via `pre_wp_mail`), pause, resume, cancel, schedule release.
- Tracking: link rewriting skips mailto, tel, anchors and unsubscribe; open and click record once per hit with correct counts; unknown token or index never redirects off-site; one-click POST unsubscribes; suppressed address skipped on the next send.
- Capability: every AJAX handler refuses a user without `anchor_send_announcements`.

One Playwright spec: compose, preview audience, send test, send, and see the recipient row in the report (the existing wp-env setup).

## 12. Delivery

A pull request from `feat/anchor-announcements` for owner sign-off; no merge to `main` and no release tag from this branch. Expected size is well under CodeRabbit's 150-file limit.
