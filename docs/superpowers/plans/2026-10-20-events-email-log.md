# Events Email Log Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Per registrant per event, admins see every email sent, whether it was delivered, opened and clicked, can view it and resend it — including emails sent before this existed. Also: dates inherit the main event's emails and can override them, and the console nests dates under their main event (Task 6).

**Architecture:** A generic mail-log primitive in `includes/email/` (table + capture API + Mailgun webhook + view/resend endpoints + panel renderer). Anchor Events is the first consumer: its one sender `send_html_email()` and WooCommerce customer emails feed the log, and the roster's two seat-edit views render the panel. A WP-CLI importer backfills from WP Mail Logging.

**Tech Stack:** WordPress plugin PHP (no build step), jQuery IIFE, PHPUnit (WP test suite), Mailgun webhooks.

**Spec:** `docs/superpowers/specs/2026-10-20-events-email-log-design.md`

## Global Constraints

- Text domain `'anchor-schema'`. Options saved with `update_option( $k, $v, false )`.
- Source files < 1,000 lines, functions < 150 lines. Do not grow `anchor-events-manager.php` by more than the call-site changes; new events logic lives in a new file.
- No `alert()` / `confirm()` / `prompt()`. Resend uses arm-then-confirm: first click → label "Click again to resend" for 4s; second click within 4s sends.
- Never commit `.min.*` files or anything under `vendor/` (vendor autoload files are locally modified by `composer install` and marked skip-worktree — use `git add <paths>`, never `git add -A`/`.`).
- Tests: `WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress vendor/bin/phpunit --filter <ClassName>` (DB on 127.0.0.1:3307 is running). Test classes in `tests/test-*.php`.
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Do not push.
- Times shown in the UI use the site timezone (`wp_date`). Stored times are UTC (`gmdate('Y-m-d H:i:s')`).
- The primitive in `includes/email/` must not reference `Anchor\Events` or any module.
- If anything in this plan contradicts the code you find, stop and report it with evidence rather than building around it.

## Review Focus

1. **Staff Bcc copies** — Mailgun fires delivered/opened events for the Bcc'd `education@` / `webforms@` copies with the same user-variables; those must not mark the attendee's row opened. (Task 2 test `test_event_for_other_recipient_is_ignored`.)
2. **Staging traffic** — staging sends through the same Mailgun domain; an event whose `anchor_site` ≠ this host must be a no-op 200 (not an error, or Mailgun retries for hours). (Task 2 `test_foreign_site_is_ignored`.)
3. **Out-of-order webhooks** — `delivered` arriving after `opened`, or `temporary_fail` after `delivered`, must not downgrade status. (Task 2 `test_status_never_downgrades`.)
4. **Resend of a resend / of an imported row** — must work and point `resend_of` at the row clicked; an imported FunnelKit row has no `anchor_*` headers and its Bcc lines may be missing — resend must still send to the stored recipient. (Task 3 `test_resend_of_imported_row`.)
5. **Mail that fails to send** — `wp_mail()` returning false must leave the row `failed` with the mailer's message, and the panel must show it (Task 1 `test_failed_send_marks_row_failed`).

---

### Task 1: Mail log store and capture

**Files:**
- Create: `includes/email/class-anchor-mail-log.php`
- Modify: `anchor-tools.php` (require it inside the existing `Anchor_Email_Tokens` block, after the kit)
- Test: `tests/test-mail-log.php`

**Interfaces:**
- Produces (all static on `Anchor_Mail_Log`):
  - `const DB_VERSION = '1'`, option `anchor_mail_log_db_version`; `install()` runs `dbDelta` when the stored version differs (hook `plugins_loaded`/`admin_init` cheap check). Table `{$wpdb->prefix}anchor_mail_log`, columns: `id BIGINT UNSIGNED AUTO_INCREMENT`, `created_at DATETIME`, `context VARCHAR(40)`, `kind VARCHAR(60)`, `audience VARCHAR(20)`, `event_id BIGINT UNSIGNED DEFAULT 0`, `seat_id BIGINT UNSIGNED DEFAULT 0`, `order_id BIGINT UNSIGNED DEFAULT 0`, `recipient VARCHAR(190)`, `subject TEXT`, `html LONGTEXT`, `headers LONGTEXT` (JSON array of lines), `source VARCHAR(10)` (`live|import|resend`), `source_ref VARCHAR(64)`, `resend_of BIGINT UNSIGNED DEFAULT 0`, `sent_by BIGINT UNSIGNED DEFAULT 0`, `status VARCHAR(20)`, `status_detail TEXT`, `delivered_at DATETIME NULL`, `opened_at DATETIME NULL`, `open_count INT UNSIGNED DEFAULT 0`, `clicked_at DATETIME NULL`, `click_count INT UNSIGNED DEFAULT 0`, `last_event_at DATETIME NULL`. Keys: `event_seat (event_id,seat_id)`, `order_id`, `recipient`, `source_ref`.
  - `begin( array $ctx ): void` — ctx keys `context, kind, audience, event_id, seat_id, order_id, source, resend_of, sent_by` (missing → defaults). Arms the next `wp_mail` call.
  - `finish( bool $sent, string $error = '' ): ?int` — sets the armed row `sent` or `failed` (+ detail) and disarms; returns row id (null if nothing was captured).
  - `site_tag(): string` — `wp_parse_url( home_url(), PHP_URL_HOST )`.
  - `insert( array $row ): int` / `get( int $id ): ?array` / `query( array $where ): array` — `query` accepts `seat_id`, `order_id`, `event_ids` (int[]), `audience`, `seat_or_order` (array `[seat_id, order_id, event_id]` → `seat_id=S OR (order_id=O AND seat_id=0 AND event_id IN (E,0))`), ordered `created_at DESC, id DESC`.
  - `apply_event( int $id, string $type, string $when_utc, string $detail = '' ): bool` — used by Task 2; implements precedence (below).
  - Filter `wp_mail` (priority 999): when armed, insert the row (recipient = first `to` address, lowercased), store subject/message/headers, and append headers `X-Mailgun-Variables: {"anchor_mail_id":<id>,"anchor_site":"<site_tag>"}`, `X-Mailgun-Track: yes`, `X-Mailgun-Track-Clicks: htmlonly`, `X-Mailgun-Track-Opens: yes`, after removing any existing `X-Mailgun-Variables` / `X-Mailgun-Track*` lines. Headers may arrive as string or array — normalise to array. When not armed, return atts unchanged.
  - Hook `wp_mail_failed`: when armed, remember the error message for `finish()`.
- Status precedence (rank): `sent`=0, `deferred`=1, `delivered`=2, `failed`/`bounced`/`complained`=3. `apply_event` types: `delivered` (sets delivered_at), `opened`/`clicked` (first-time + count, never change status), `temporary_fail`→`deferred`, `permanent_fail`→`bounced`, `complained`. A lower rank never overwrites a higher one. Every call sets `last_event_at`.

- [ ] **Step 1: Write failing tests** in `tests/test-mail-log.php` (`class Test_Mail_Log extends WP_UnitTestCase`, use `reset_phpmailer_instance()` / `tests_retrieve_phpmailer_instance()`):
  - `test_install_creates_table` — `SHOW TABLES LIKE` returns the table.
  - `test_unarmed_mail_is_not_logged` — `wp_mail()` with no `begin()` → 0 rows, headers unchanged.
  - `test_armed_mail_is_logged_and_tagged` — `begin(['context'=>'test','kind'=>'k','event_id'=>5,'seat_id'=>7])`, `wp_mail('A@Example.com','S','<p>x</p>',['Content-Type: text/html; charset=UTF-8'])`, `finish(true)` → one row, recipient `a@example.com`, status `sent`, html `<p>x</p>`; the mock mailer's header string contains `"anchor_mail_id":<id>` and `X-Mailgun-Track-Opens: yes`.
  - `test_existing_tracking_headers_are_replaced` — passing `X-Mailgun-Track: no` → sent headers contain `X-Mailgun-Track: yes` once and not `no`.
  - `test_failed_send_marks_row_failed` — filter `pre_wp_mail` to return false after triggering `do_action('wp_mail_failed', new WP_Error('x','boom'))` → `finish(false)` row status `failed`, `status_detail` contains `boom`. (If `pre_wp_mail` short-circuits before the `wp_mail` filter, the row must still be created by `finish()` from the armed context with what is known; assert status `failed`.)
  - `test_status_precedence` — `apply_event` sequence opened, delivered, temporary_fail → status `delivered`, open_count 1; then permanent_fail → `bounced`; then delivered → stays `bounced`.
  - `test_query_seat_or_order` — rows (seat 7), (order 9 seat 0 event 5), (order 9 seat 0 event 0), (order 9 seat 0 event 6), (seat 8) → `query(['seat_or_order'=>[7,9,5]])` returns the first three only.
- [ ] **Step 2: Run** `--filter Test_Mail_Log` → FAIL (class not found).
- [ ] **Step 3: Implement** `includes/email/class-anchor-mail-log.php` per the interfaces; require it in `anchor-tools.php`.
- [ ] **Step 4: Run** `--filter Test_Mail_Log` → PASS; also `--filter Test_Admin_Email_Answers` still PASS.
- [ ] **Step 5: Commit** `feat(email): mail log table and capture API`.

### Task 2: Mailgun webhook receiver

**Files:**
- Create: `includes/email/class-anchor-mail-log-mailgun.php`
- Modify: `anchor-tools.php` (require)
- Test: `tests/test-mail-log-mailgun.php`

**Interfaces:**
- Consumes: `Anchor_Mail_Log::get()`, `apply_event()`, `site_tag()`.
- Produces: `Anchor_Mail_Log_Mailgun`:
  - REST route `POST anchor-tools/v1/mail-log/mailgun`, `permission_callback => '__return_true'` (auth is the signature).
  - `const OPTION = 'anchor_mail_log_settings'` (array, key `mailgun_signing_key`); `signing_key(): string`; `save_signing_key( string $k ): void`; `webhook_url(): string` (`rest_url('anchor-tools/v1/mail-log/mailgun')`).
  - `verify( array $sig, string $key, int $now ): bool` — `hash_equals( hash_hmac('sha256', $sig['timestamp'].$sig['token'], $key), $sig['signature'] )` and `abs($now - timestamp) <= 900`.
  - `handle( WP_REST_Request $r ): WP_REST_Response` — body JSON `{signature:{timestamp,token,signature}, "event-data":{event, severity, recipient, timestamp, "user-variables":{anchor_mail_id, anchor_site}, "delivery-status":{message, description, code}}}`. Bad/missing signature or no key configured → 401. Otherwise always 200 (including ignored events) with `{ok:true, applied:bool}`. Map: `delivered`, `opened`, `clicked`, `complained` as-is; `failed` + severity `permanent` → `permanent_fail`, else `temporary_fail`. `when` = `gmdate` of event-data timestamp. Detail = delivery-status message/description/code joined. Ignore when: no `anchor_mail_id`; `anchor_site` ≠ `site_tag()`; row missing; `strtolower(recipient)` ≠ row recipient.
- [ ] **Step 1: Failing tests** (`class Test_Mail_Log_Mailgun`), building a signed payload with key `key-test` and `time()`; dispatch through `rest_do_request`:
  - `test_valid_delivered_updates_row` → 200, row `delivered`, `delivered_at` set.
  - `test_bad_signature_401` and `test_stale_timestamp_401` (timestamp −1000s) and `test_no_key_configured_401`.
  - `test_foreign_site_is_ignored` → 200, row unchanged.
  - `test_event_for_other_recipient_is_ignored` → opened for `education@x.com` on attendee's row → open_count 0.
  - `test_failed_permanent_is_bounced_temporary_is_deferred`.
  - `test_status_never_downgrades` → opened, delivered, then `failed`/temporary → status `delivered`, open_count 1.
- [ ] **Step 2: Run** `--filter Test_Mail_Log_Mailgun` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(email): Mailgun webhook updates mail log delivery, opens, clicks`.

### Task 3: View, resend and the reusable panel

**Files:**
- Create: `includes/email/class-anchor-mail-log-admin.php`, `includes/email/assets/mail-log.js`, `includes/email/assets/mail-log.css`
- Modify: `anchor-tools.php` (require)
- Test: `tests/test-mail-log-admin.php`

**Interfaces:**
- Consumes: Task 1 API.
- Produces: `Anchor_Mail_Log_Admin`:
  - `can_manage( array $row ): bool` — `apply_filters( 'anchor_mail_log_can_manage', current_user_can('manage_options'), $row )`.
  - `resend( int $id, int $user_id ): array` — `['ok'=>bool,'id'=>new_id,'message'=>string]`. Rebuild headers from stored JSON minus `X-Mailgun-Variables`/`X-Mailgun-Track*`; `begin()` with the original row's context/kind/audience/event/seat/order, `source=resend`, `resend_of=$id`, `sent_by=$user_id`; `wp_mail(recipient, subject, html, headers)`; `finish()`.
  - AJAX `wp_ajax_anchor_mail_log_resend` (POST `id`, nonce `anchor_mail_log`) → JSON `{ok, row_html}` where `row_html` is the new row rendered by `render_row()`. 403 when `!can_manage`.
  - AJAX `wp_ajax_anchor_mail_log_view` (GET `id`, `_wpnonce`) → the stored HTML with headers `Content-Security-Policy: sandbox; default-src 'none'; img-src * data:; style-src 'unsafe-inline' *` and `X-Robots-Tag: noindex`. 403 when `!can_manage`.
  - `render_panel( array $rows, array $labels = [] ): string` — `$labels` maps `kind` → human label (fallback: subject). Section heading "Emails", empty state "No emails logged for this registrant yet." Table columns: Sent | Email | Delivery | Opened | Clicked | (actions). Delivery badge text: `Delivered`, `Waiting for delivery report`, `Deferred (retrying)`, `Bounced`, `Marked as spam`, `Failed`, and for `source=import`: `Sent (before tracking)`. `status_detail` in the badge's `title`. Opened/Clicked: "—" or "Yes · Oct 20, 3:04 PM (×N)"; imported rows show "not tracked". Resend rows show "Resent by <display name>". Actions: **View** link (new tab) and **Resend** button `data-id`. `render_row( array $row, array $labels ): string`.
  - `enqueue(): void` — registers/enqueues the JS/CSS (URL `ANCHOR_TOOLS_PLUGIN_URL . 'includes/email/assets/'`) and localizes `anchorMailLog = {ajaxUrl, nonce, i18n}`. Callers call it when rendering a panel.
  - JS: delegated click on `.anchor-mail-log [data-resend]`: arm/confirm (Global Constraints), POST, on success prepend `row_html` and flash "Sent"; on error show inline message in the row. No dialogs.
- [ ] **Step 1: Failing tests** (`class Test_Mail_Log_Admin`):
  - `test_resend_creates_linked_row` — seed a live row with headers incl. `Bcc: staff@x.com` and an old `X-Mailgun-Variables`; `resend()` → new row `source=resend`, `resend_of`=old id, recipient same; sent headers contain `Bcc: staff@x.com`, exactly one `X-Mailgun-Variables` with the NEW id.
  - `test_resend_of_imported_row` — seed `source=import` row with `headers` = `[]` → resend ok, mail goes to stored recipient.
  - `test_resend_ajax_requires_capability` — subscriber → 403 (`wp_die` handler / `WPAjaxDieStopException` pattern used in existing ajax tests).
  - `test_view_requires_capability`.
  - `test_panel_renders_states` — rows delivered+opened×2, bounced with detail, imported → HTML contains `Delivered`, `×2`, `Bounced`, the detail inside `title=`, `Sent (before tracking)`, `not tracked`, and `data-resend` for each row.
- [ ] **Step 2: Run** `--filter Test_Mail_Log_Admin` → FAIL.
- [ ] **Step 3: Implement** PHP, JS (jQuery IIFE), CSS (prefix `.anchor-mail-log`; badges green/grey/amber/red; table scrolls horizontally under 600px).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(email): mail log view, resend and panel`.

### Task 4: Anchor Events integration

**Files:**
- Create: `anchor-events-manager/class-email-log.php` (namespace `Anchor\Events`, class `Email_Log`)
- Modify: `anchor-events-manager/anchor-events-manager.php` — require + instantiate beside the other classes (~line 420/440) as `$this->email_log`; `send_html_email()` (~1897) gains `array $log = []`; each caller passes a kind; Email Sender settings section (~11837) gets a "Mailgun webhook signing key" password field + read-only webhook URL (saved via `Anchor_Mail_Log_Mailgun::save_signing_key`, never echoed back — show "Saved ••••" when set).
- Modify: `anchor-events-manager/class-woocommerce.php` (callers ~4127, ~4259 pass kinds)
- Modify: `anchor-events-manager/class-roster.php` — `render_edit_form()` (~512) and `frontend_edit_form()` (~2532) append `$this->module->email_log->panel_for_seat( $event_id, $seat_id )` after the form's closing `</form>`, inside the wrapper.
- Modify: `anchor-events-manager/EMAILS.md` — short "Email log" section (what is logged, kinds, webhook setup).
- Test: `tests/test-events-email-log.php` (extends `Anchor_Events_TestCase`)

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces:
  - `send_html_email( $to, $subject, $html, $headers = [], $event_id = 0, array $identity = [], array $log = [] )` — when `$log` has `kind`: `\Anchor_Mail_Log::begin([ 'context'=>'events', 'kind'=>$log['kind'], 'audience'=>$log['audience'] ?? 'attendee', 'event_id'=>$event_id, 'seat_id'=>$identity['seat'] ?? 0, 'order_id'=>$identity['order'] ?? 0 ])` before `wp_mail`, `finish( $sent, <last_mail_error message> )` after. No `$log` → unchanged behaviour.
  - Kinds: `events.confirmation` (send_confirmation_email), `events.reminder` (send_reminder_email; append `:<offset>` e.g. `events.reminder:1d` only if offset is a string label, else plain), `events.cancellation`, `events.roster` (audience `organizer`), `events.wc_confirmation` (WooCommerce::send_customer_confirmation), `events.organizer_notice` (audience `organizer`). Raw `wp_mail` calls at ~9188 and ~15394 stay unlogged.
  - `Email_Log::kind_labels(): array` — the kinds above plus `wc.customer_processing_order` "Order received (WooCommerce)", `wc.customer_completed_order` "Order complete (WooCommerce)", `wc.customer_invoice` "Invoice (WooCommerce)", `legacy.funnelkit` "Course details (old FunnelKit email)", `imported` "Imported email".
  - WooCommerce capture: filter `woocommerce_mail_callback_params` ($params, $email): if `$email->is_customer_email()` and `$email->object instanceof \WC_Order` and the order has any seat (`Registrations::get_seats_for_order`) → `begin(['context'=>'events','kind'=>'wc.'.$email->id,'audience'=>'attendee','event_id'=> <the single event id if all seats share one, else 0>,'order_id'=>$order_id])`. Action `woocommerce_email_sent` ($sent, $id, $email) → `finish( (bool) $sent )`. Return params unchanged.
  - Filter `anchor_mail_log_can_manage`: when `$row['context']==='events'` return `current_user_can( Module::events_capability() )`.
  - `panel_for_seat( int $event_id, int $seat_id ): string` — loads the seat (`Registrations::get_seat`), queries `seat_or_order => [seat, order, event]` with `audience=attendee`, calls `\Anchor_Mail_Log_Admin::enqueue()` and returns `render_panel( $rows, self::kind_labels() )`. Empty string if the seat does not belong to the event.
- [ ] **Step 1: Failing tests** (`class Test_Events_Email_Log`):
  - `test_confirmation_is_logged_against_seat` — create event + seat via helpers, call `send_confirmation_email(...)` → one row, `kind=events.confirmation`, seat/event ids match, audience attendee.
  - `test_unlogged_call_unchanged` — `send_html_email` without `$log` → no row.
  - `test_wc_customer_email_logged_for_event_order` — with `woocommerce()` helper create an order holding an event ticket + seat; trigger `WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order_id)` → row `kind=wc.customer_processing_order`, `order_id` set, `seat_id=0`.
  - `test_wc_email_for_non_event_order_not_logged`.
  - `test_panel_for_seat_lists_seat_and_order_rows` — rows for seat, its order, and another seat → panel contains the first two subjects only.
  - `test_events_capability_gates_rows` — user with events capability but not `manage_options` → `Anchor_Mail_Log_Admin::can_manage($events_row)` true; for a non-events row false.
  - `test_signing_key_saved_not_echoed` — save settings with key `key-abc` → option holds it; rendered settings field HTML does not contain `key-abc`.
- [ ] **Step 2: Run** `--filter Test_Events_Email_Log` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** this test, then the full events suite: `vendor/bin/phpunit --group events` if groups exist, otherwise `--filter 'Test_(Events|Roster|Registration|Woo|Admin_Email|Capacity|Mail_Log)'` → all PASS (report any pre-existing failures separately with evidence they fail on `origin/main` too).
- [ ] **Step 5: Commit** `feat(events): log every registrant email and show it on the seat`.

### Task 5: WP Mail Logging backfill

**Files:**
- Create: `includes/email/class-anchor-mail-log-import.php` (generic: reads `{prefix}wpml_mails`, cleans bodies, inserts rows) and `anchor-events-manager/class-email-log-import.php` (events matching + `WP_CLI::add_command( 'anchor-events email-log', ... )`)
- Modify: `anchor-events-manager/anchor-events-manager.php` (require the CLI file only `if ( defined('WP_CLI') && WP_CLI )`)
- Test: `tests/test-mail-log-import.php`

**Interfaces:**
- Produces:
  - `Anchor_Mail_Log_Import::clean_html( string $html ): string` — (a) every `href` whose query has `bwfan-track-action=click` and `bwfan-link=<enc>` → `rawurldecode(<enc>)`; (b) remove `<img>` whose src contains `bwfan-track-action=open` or `bwfan-action=open`; (c) remove `<a>` elements whose href contains `bwfan-action=unsubscribe` (keep nothing of them); (d) `urldefense.proofpoint.com/v2/url?u=` links decoded (`-`→`%`, `_`→`/`, then `rawurldecode`). Everything else byte-identical.
  - `Anchor_Mail_Log_Import::wpml_rows( string $recipient, string $from_utc, string $to_utc ): array` — rows from `wpml_mails` where `receiver` contains the recipient (case-insensitive) and `timestamp` in range. NOTE: `wpml_mails.timestamp` is site-local time (PT); convert with `get_gmt_from_date`.
  - `Anchor_Mail_Log_Import::import_row( array $wpml, array $ctx ): ?int` — skips if a row with `source='import' AND source_ref=<mail_id>` exists (idempotent); inserts `source=import`, `status=sent`, `created_at` = GMT of timestamp, headers split from WPML's `,\n`-joined string into lines, html cleaned, recipient = the matched address.
  - `Anchor_Mail_Log_Import::kind_for_subject( string $subject ): string` — `Thank You For Registering!` → `wc.customer_processing_order`; `/^Mini Residency Course/i` → `legacy.funnelkit`; `/^You.re registered/i` → `events.wc_confirmation`; else `imported`.
  - Exclude subjects matching `/account (has been )?created|password|Document Viewed|has been signed|^\[|new order/i`.
  - CLI `wp anchor-events email-log import-wpml [--event=<id>] [--before=<Y-m-d H:i:s UTC>] [--apply]`: default dry-run, prints a table (seat, recipient, wpml id, local time, subject, kind, action CREATE/SKIP-DUP/SKIP-EXCLUDED). `--before` defaults to the earliest `source=live` row in the log (or now). Matching per seat: addresses = seat email ∪ order billing email; window A = [order `date_created` −5 min, +6 h]; window B = [seat post_date, before] AND (message body contains the decoded event title OR the event permalink OR subject contains the event title). Order-level messages (to the billing email, not the seat email) attach as `seat_id=0, order_id=O, event_id=E`; messages to the seat email attach to that seat. Each WPML message is imported at most once per recipient. Ends with counts and, when `--apply`, a `SELECT COUNT(*)` of import rows before/after.
- [ ] **Step 1: Failing tests** (`class Test_Mail_Log_Import`):
  - `test_clean_html_unwraps_bwfan_links` — fixture with a `bwfan-link=https%3A%2F%2Fwww.marriott.com%2Fx%3Fa%3D1%26b%3D2` href → `https://www.marriott.com/x?a=1&b=2`; open pixel removed; unsubscribe anchor removed; an unrelated `<a href="https://tmjtherapycentre.com/">` unchanged.
  - `test_clean_html_decodes_proofpoint`.
  - `test_import_is_idempotent` — same wpml row twice → one log row.
  - `test_kind_for_subject`.
  - `test_cli_matches_order_window_and_skips_excluded` — create the `wpml_mails` table in the test (minimal columns `mail_id, timestamp, receiver, subject, message, headers, error`), seed: buyer email at order time ("Thank You For Registering!"), FunnelKit "Mini Residency Course" 2 min later, "Your account has been created" (excluded), an unrelated email 3 days earlier → run the command's matcher with apply → 2 rows, kinds `wc.customer_processing_order` and `legacy.funnelkit`, both `order_id` set.
- [ ] **Step 2: Run** `--filter Test_Mail_Log_Import` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS, then all `Mail_Log` tests.
- [ ] **Step 5: Commit** `feat(events): backfill registrant emails from WP Mail Logging`.

### Task 6: Dates inherit the main event's emails and can override them; the console nests dates under their main event

**Why:** Toronto is a group parent (1119878) with one date child (1119879). Today every email setting is COPIED parent → dates on each parent save (`Occurrences::sync_shared_meta()`), and a parent with no row DELETES the date's row. The custom confirmation wording was stored only on the date, so the main event's editor looked blank and saving it would have wiped live wording. The console list also shows dates and main events as unrelated flat items — and on Live it shows **1 of 8 events**, because the theme passes `limit="-1"` and `render_event_manager_list()` clamps with `max( 1, … )`.

**Joel's model (verbatim intent):** "For events with children, you should see it as an expandable item where you can click into the main event or expand the dropdown and edit the individual event. The confirmation email should inherit the main template but be overwritable."

**Files:**
- Create: `anchor-events-manager/class-email-inheritance.php` (class `Email_Inheritance`: the resolver + the one-time migration)
- Modify: `anchor-events-manager/class-occurrences.php` — remove the per-type email override keys and `EMAIL_SENDER_KEYS` from what `sync_shared_meta()` copies/deletes (registration questions keep today's copy behaviour); keep the constants (the resolver uses them).
- Modify: `anchor-events-manager/anchor-events-manager.php` — every READ of an email override or sender key (send time: `get_email_field()`, `is_email_enabled()`, `email_headers()`'s `$ev`, the per-event `tpl` lookup; editor: console form ~9343 and wp-admin `render_email_builder_metabox()` ~5201) goes through the resolver; console list + item (~9198–9300).
- Modify: `anchor-events-manager/assets/*` (console CSS/JS that styles `.anchor-event-admin-item`) for the nested dates.
- Test: `tests/test-events-email-inheritance.php`, `tests/test-events-console-groups.php`

**Interfaces:**
- `Email_Inheritance::resolve( int $event_id, string $meta_key ): array` → `['value' => string, 'source' => 'own'|'main'|'none']`. Own non-empty row → `own`; else, when `is_group_child()`, the parent's non-empty row → `main`; else `none` with `''`. (`off` switch: a date's own `'1'` turns it off; a date with no row follows the main event.)
- `Module::email_meta( int $event_id, string $meta_key ): string` → `resolve()['value']`. The ONLY way any code reads `_anchor_event_email_*` override/sender meta after this task. Grep proves it: no remaining `get_post_meta( …, '_anchor_event_email_` outside `Email_Inheritance` and the save handlers.
- **Save rules:** main event saves exactly as today (its own rows) and no longer touches dates. A date save writes only fields that differ from what it would inherit; a field equal to the main event's value, or emptied, deletes the date's own row (so it goes back to inheriting). Each per-field override is independent (a date can override just the subject).
- **Date editor (console + wp-admin metabox):** same Emails UI as a main event. Inherited fields render EMPTY with the main event's value as the grey placeholder/preview and the hint "Inherited from Adjunct Mini Residency — type here to change it for this date only." An overridden field shows its value plus a **Use main event's wording** link that clears it (no dialog). Header notice: "This is one date of <Main title>. Edit the main event" (link). The preview shows the resolved result.
- **Main event editor:** unchanged fields; under the Emails heading, when any date overrides anything: "Nov 13, 2026 has its own Confirmation subject, Confirmation opening lines" (each date linked to its editor).
- **Console list:** a group parent renders as one `<details>` item; its summary links the title to the main event's editor (as today) and shows "N dates". Expanding it shows, before the existing actions, a list of its dates (all statuses the list already shows; closed dates marked), each with date label, status, seat count and **Edit** / **Roster** links — the same actions a flat item has. Date children are NOT rendered as separate top-level items. A child whose parent is missing/trashed still renders top-level (never hidden). `limit <= 0` means "all" (cap 200), so the theme's `limit="-1"` lists every event.
- **Migration** (`Email_Inheritance::migrate()`, run once on `admin_init` behind option `anchor_events_email_inheritance_v1`, also exposed as `wp anchor-events email-inheritance migrate [--dry-run]` if Task 5's CLI file exists): for each date child and each email override/sender key, delete the child's row when it is byte-identical to the parent's row (it was a sync copy); when the parent has NO row and every date of that parent holds the same value, MOVE it up to the parent (Toronto's case is already fixed on Live, but other sites may have it); otherwise keep it (a real override). Log counts via `Events_Log::info`.
- [ ] **Step 1: Failing tests** — `Test_Events_Email_Inheritance`:
  - `test_date_without_row_inherits_main` / `test_date_override_wins` / `test_non_group_event_reads_own_row`.
  - `test_override_subject_only_inherits_intro` — date overrides subject; `get_email_field(child,'confirmation','intro')` returns the main event's intro.
  - `test_main_save_no_longer_touches_dates` — date has an override, main event saved with that field empty → date's override survives and is what the date sends.
  - `test_date_save_equal_to_main_stores_nothing` and `test_date_save_empty_reverts_to_inherit`.
  - `test_off_switch_inherits_and_overrides`.
  - `test_sender_headers_inherit` — main sets Reply-To, date none → `email_headers([], child)` contains the main's Reply-To.
  - `test_migration_removes_copies_keeps_overrides_lifts_agreed` — three cases in one fixture, then running it twice changes nothing the second time.
  - `test_confirmation_send_uses_inherited_wording` — `send_confirmation_email` for a seat on the date → captured mail body contains the main event's intro.
  `Test_Events_Console_Groups`:
  - `test_parent_lists_dates_nested_children_not_top_level`, `test_orphan_child_still_listed`, `test_limit_minus_one_lists_all` (create 3 events, render with `limit="-1"`, 3 items), `test_date_editor_shows_inherited_placeholder_and_main_link`, `test_main_editor_lists_date_overrides`.
- [ ] **Step 2: Run** `--filter 'Test_Events_Email_Inheritance|Test_Events_Console_Groups'` → FAIL.
- [ ] **Step 3: Implement.** Keep `anchor-events-manager.php` from growing: list/item rendering for groups goes in a new `class-console-list.php` if it adds more than ~40 lines.
- [ ] **Step 4: Run** them, then `--filter 'Occurrence|Group|Series|Email|Roster'` → PASS. Any existing test asserting the old copy-down of email keys is updated to the new rule and named in the report (do not delete tests silently).
- [ ] **Step 5: Commit** `feat(events): dates inherit and can override the main event's emails; console nests dates under their main event`.

---

## After the tasks (controller, not subagents)

1. Opus whole-branch review; fix; full test filter green.
2. Open PR (count files first), CodeRabbit capacity check, get Joel's sign-off to merge, bump `Version` in `anchor-tools.php`, tag, install on Live from the release zip (back up the current plugin to `/tmp/tmjintl-backups/`).
3. Live config: save the Mailgun signing key (from `GET /v5/accounts/http_signing_key`) via the settings field or `Anchor_Mail_Log_Mailgun::save_signing_key`; in Mailgun domain `mg.tmjtherapycentre.com` replace the dead FunnelKit `delivered` webhook URL and add `opened`, `clicked`, `permanent_fail`, `temporary_fail`, `complained` → the new webhook URL.
4. Send one real event email to Joel's address; confirm row → delivered → opened → clicked and that the link was rewritten (`email.mg.tmjtherapycentre.com`). If `X-Mailgun-Track*` headers are not honoured over the API, report it — do not switch on domain-wide tracking without asking.
5. Backfill on Live: dry-run for event 1119879, review the table (Noha's order 1119726 must show "Thank You For Registering!" and "Mini Residency Course"), then `--apply`, assert counts in SQL.
6. Update the site CLAUDE.md open-items row and the rebuild reference.
