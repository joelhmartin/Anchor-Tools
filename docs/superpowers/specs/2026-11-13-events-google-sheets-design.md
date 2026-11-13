# Events → Google Sheets mirror — design

**Date:** 2026-11-13 · **Branch:** `feat/events-google-sheets` · **Size:** small project

## Intent

An admin pastes a Google Sheet ID (or URL) into an event, and that sheet mirrors the event's
registrant list: every roster field and every registration-question answer. New registrations
add rows. Changes (cancel, refund, waitlist promotion, edited details) update the existing row in
place. Cancelled people stay in the sheet with Status = `cancelled`; rows are never deleted.

It hangs off the **events module**, not WooCommerce, because free and external-path events never
touch Woo. Paid registrations are still covered, because every seat, free or paid, goes through
`Registrations::create_seat()` / the status-transition path.

## Goals / non-goals / anti-goals

- **Goals:** "just the ID" setup per event; full-field mirror; update-in-place keyed on Seat ID;
  backfills registrants who existed before the sheet was connected; a visible sync status and a
  "Sync now" button on the event.
- **Non-goals:** two-way sync (edits made in the sheet never flow back); sheets for anything
  other than events; deleting rows.
- **Anti-goals:** a slow or down Google must never slow or break a registration or checkout;
  the sheet's columns must never disagree with the roster CSV export; the integration must not
  clobber columns the admin added to the sheet by hand.

## Approaches considered

1. **Google service account (picked by the owner).** The admin pastes one service-account JSON
   key into settings once, then shares each sheet with the robot's email. The site writes via the
   Sheets API itself, so it can read the sheet, match rows by Seat ID, update or append, and
   backfill.
2. **Apps Script web app per sheet** (the TMJ opt-in pattern). No Google Cloud setup, but every
   sheet needs a script deployed, so it's not "just the ID", and row matching would live in
   per-sheet script code. Rejected.
3. **Per-site OAuth ("Sign in with Google").** Needs a Google-verified OAuth app for a sensitive
   scope, and the refresh tokens expire and need reconnecting. Too much overhead for an internal
   tool. Rejected.

## Design

### Parts

| Part | Where | Role |
|---|---|---|
| `Anchor_Google_Service_Account` | `includes/google/class-anchor-google-service-account.php` | **Generic primitive.** Stores the SA JSON key (option `anchor_google_service_account`, `autoload=false`, write-only in the UI). Mints an OAuth access token from a self-signed RS256 JWT (`openssl_sign`, no Composer dependency), caches it in a transient until shortly before expiry, and exposes `client_email()`, `access_token( $scopes )`, and `request( $method, $url, $body )` returning decoded JSON or `WP_Error`. No events knowledge, so a later Locations/GSC consumer reuses it. |
| `Anchor_Google_Sheets` | `includes/google/class-anchor-google-sheets.php` | Thin Sheets v4 client on top of the SA: `get_values( $sheet_id, $range )`, `batch_update_values( $sheet_id, $data )`, `append_values( $sheet_id, $range, $rows )`, `first_tab_title( $sheet_id )`. |
| `Sheets_Reconciler` | `anchor-events-manager/class-sheets-reconciler.php` | **Pure function, no I/O.** Takes (existing sheet values, roster table `{header, rows}`) and returns the minimal write set: header cells to add, row updates by sheet row number, rows to append. This is where the correctness lives, and it's unit-tested without HTTP. |
| `Sheets_Sync` | `anchor-events-manager/class-sheets-sync.php` | Events glue: per-event settings field + side metabox, save handler, hooks, debounced scheduling, the sync run, status meta, "Sync now" admin-post action, the settings section that hosts the key field. |

The 16.9k-line `anchor-events-manager.php` only gets the `require_once` + `new Sheets_Sync( $this )`
wiring.

### Data model

- Event post meta `_anchor_event_sheet_id`: the bare spreadsheet ID. The save handler accepts a
  full `docs.google.com/spreadsheets/d/<id>/…` URL and extracts the ID.
- Event post meta `_anchor_event_sheet_tab`: optional tab name; blank = the spreadsheet's first tab.
- Event post meta `_anchor_event_sheet_state`: `{ last_sync: ts, ok: bool, message: string, rows: int }`,
  shown in the metabox.

### What a row is

The rows come from `Roster::export_table_public( $event_id, 'all' )`, the same builder the
roster CSV export uses, so the sheet and the CSV cannot drift. `all` scope includes
cancelled/refunded seats, which is how "mark cancelled" falls out naturally: Status changes,
and the row stays. For a **group parent** ("pick-one" dates) with a sheet ID, rows come from the
all-dates table instead (extra leading Date column), via a new public seam
`export_table_all_dates_public()`. Seat IDs are unique across children, so keying still works.

### Reconcile rules

- **Header row (row 1):** columns are matched **by header text**, not position. A roster column
  missing from the sheet is appended to the right end of row 1. Columns the admin added by hand
  are left alone, and their cells are never written.
- **Rows:** matched on the `Seat ID` column. A seat already in the sheet is rewritten only in our
  columns and only if a value changed. A seat not in the sheet is appended. A sheet row whose seat
  isn't in the roster is left untouched.
- An empty sheet gets the header plus all rows (this is the backfill).
- Values are written with `valueInputOption=RAW`, so a name like `=HYPERLINK(...)` or `+1 555…`
  is stored as text and never evaluated as a formula.

### When it runs

- Triggers: `anchor_events_seat_created`, `anchor_events_seat_status_changed`, and
  `updated_post_meta`/`added_post_meta` on an `anchor_event_reg` post (edited name/email/answers).
  Also saving the event with a new or changed sheet ID, and the "Sync now" button (which runs
  inline and reports the result).
- Each trigger resolves the seat → its event → the **target** (the event itself if it has a sheet
  ID, else its group parent if the parent has one), then schedules
  `wp_schedule_single_event( time() + 30, 'anchor_events_sheet_sync', [ $target ] )` unless one
  is already queued. Bursts (a 5-seat order) collapse into one sync, and the request that
  registered the person never waits on Google.
- Because the sync is a full idempotent reconcile, a missed trigger or a failed run self-heals on
  the next change or "Sync now". A failed run records the error in `_anchor_event_sheet_state`
  and logs through `Events_Log`. It does not retry in a loop.
- A transient lock per target stops two cron runs from interleaving writes.

### Settings

A **Google Sheets** section on the Events settings tab holds the write-only service-account key
field (paste JSON; validated for `client_email` + `private_key` on save), shows the robot's email
("Share each sheet with this address as Editor"), and gives a one-line setup guide. The key lives
in the generic primitive's option, not `anchor_events_settings`, so other modules can reuse it.

### Errors the admin sees (in the event's metabox)

- No key configured: "Add a Google service-account key under Settings → Anchor Tools → Events."
- 403/404 from Sheets: "The sheet isn't shared with `<robot email>`, or the ID is wrong."
- Anything else: the Google error message.

### Security / privacy

- Only users who can manage events can set the sheet ID or press Sync now (the existing roster
  capability, `Roster::current_user_can_manage()`), with a nonce on both.
- The key is never echoed back after it's saved.
- **PHI caution:** the owner asked for all fields. Registration answers on medical/dental client
  sites may be health-related, and they will land in Google Sheets. The metabox carries a short
  note saying so. Filtering which columns go over is a possible later addition, not built now.

## Left to the builder

- Exact transient TTLs, lock duration, and the scheduling delay (30s suggested).
- Column-letter math (A1 notation for columns past Z).
- How the metabox's status line is worded beyond the error cases above.

## Builder's calls (made without asking)

- Scope `all` (not confirmed-only), so cancellations remain visible as rows.
- Sheet ID set per event, with group children inheriting their parent's sheet.
- Key stored in a generic option, with its UI hosted on the Events tab, since Events is the only
  consumer today.
