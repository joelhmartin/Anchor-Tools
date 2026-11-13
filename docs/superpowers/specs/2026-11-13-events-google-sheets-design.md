# Events → spreadsheet mirror (Google Sheets + Microsoft Excel) — design

**Date:** 2026-11-13 · **Branch:** `feat/events-google-sheets` · **Size:** project

## Intent

An admin pastes a Google Sheet URL or an Excel (OneDrive/SharePoint) URL into an event, and that
spreadsheet mirrors the event's registrant list: every roster field and every
registration-question answer. New registrations add rows. Changes (cancel, refund, waitlist
promotion, edited details) update the existing row in place. Cancelled people stay, with Status =
`cancelled`; rows are never deleted.

Access works the way FunnelKit's connector does: each site has a **Connectors** screen where an
admin signs in with Google and/or Microsoft once (OAuth). After that, pasting a URL is all that's
needed. Everything lives in Anchor Tools, with no external service or relay.

It hangs off the **events module**, not WooCommerce, because free and external-path events never
touch Woo. Paid registrations are still covered, because every seat, free or paid, goes through
`Registrations::create_seat()` / the status-transition path, which fire
`anchor_events_seat_created` and `anchor_events_seat_status_changed`.

## Goals / non-goals / anti-goals

- **Goals:** a one-time "Connect Google" / "Connect Microsoft" sign-in per site; per event, paste a
  URL; full-field mirror; update-in-place keyed on Seat ID; backfill of registrants who existed
  before the sheet was connected; a visible sync status and a "Sync now" button on the event.
- **Non-goals:** two-way sync (sheet edits never flow back); deleting rows; a hosted relay or
  broker; connectors for anything other than Google and Microsoft.
- **Anti-goals:** a slow or down Google/Microsoft must never slow or break a registration or
  checkout; the sheet's columns must never disagree with the roster CSV export; the sync must
  never clobber columns the admin added by hand; tokens and client secrets must never be shown
  back or stored in plaintext.

## Approaches considered

1. **Service account** (robot email you share each sheet with). Rejected by the owner: they want
   the FunnelKit-style experience with no per-sheet sharing step. It also has no Microsoft
   equivalent for personal or business OneDrive files.
2. **OAuth relayed through a hosted Anchor service.** Rejected by the owner: Anchor Tools must
   stand alone.
3. **Self-contained OAuth in Anchor Tools (picked).** Each site holds the OAuth app's client ID
   and secret (pasted on the Connectors screen) and runs the authorization-code flow itself. The
   cost of not having a relay: each site's callback URL must be added once to the Google OAuth
   client and the Microsoft app registration. The Connectors screen shows that URL with a copy
   button and the steps.

## Design

### Parts

| Part | Where | Role |
|---|---|---|
| `Anchor_Secret_Box` | `includes/connectors/class-anchor-secret-box.php` | Encrypts/decrypts small secrets at rest (`openssl` AES-256-GCM, key derived from `wp_salt('auth')`). Used for client secrets and refresh tokens. If the salts change, the stored values fail to decrypt and the connector shows "Reconnect". |
| `Anchor_OAuth_Connector` | `includes/connectors/class-anchor-oauth-connector.php` | **Generic primitive**, one instance per provider. Stores `{client_id, client_secret*, refresh_token*, account, connected_at}` in option `anchor_connector_{provider}` (`autoload=false`, * = Secret Box). Builds the authorize URL (authorization code + PKCE S256 + random `state`), handles the callback, exchanges/refreshes tokens, caches the access token in a transient until 2 min before expiry, stores a rotated refresh token when the provider returns one, and supports disconnect. `request( $method, $url, $body = null )` returns decoded JSON or `WP_Error` (`status` in data). A 401/`invalid_grant` marks the connector `needs_reconnect`. |
| Provider definitions | `includes/connectors/providers.php` | **Google:** auth `https://accounts.google.com/o/oauth2/v2/auth`, token `https://oauth2.googleapis.com/token`, scopes `openid email https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive`, extra `access_type=offline&prompt=consent`. **Microsoft:** tenant `common`, auth `https://login.microsoftonline.com/common/oauth2/v2.0/authorize`, token `…/common/oauth2/v2.0/token`, scopes `offline_access openid email User.Read Files.ReadWrite.All`. Account label from the id_token / `userinfo` (Google) or `GET /me` (Microsoft). |
| `Anchor_Connectors_Admin` | `includes/connectors/class-anchor-connectors-admin.php` | A **Connectors** tab on Settings → Anchor Tools (`anchor_settings_tabs`, priority 15). Per provider: Client ID, Client Secret (write-only), the callback URL to register (copy button), a short setup guide, Connect / Disconnect, the connected account, and the reconnect state. |
| Spreadsheet adapters | `includes/connectors/class-anchor-sheet-google.php`, `…/class-anchor-sheet-excel.php`, both implementing `Anchor_Sheet_Adapter` | Provider-agnostic surface: `resolve( $url ): array\|WP_Error` (URL → stored ref), `read( $ref ): string[][]\|WP_Error` (whole used range of the tab, row 1 = header), `write_cells( $ref, $cells ): true\|WP_Error` (`[row, col, value]`, 1-based row, 0-based col), `append_rows( $ref, $rows ): true\|WP_Error`. Google uses Sheets v4 `values.batchUpdate` / `values.append` (`valueInputOption=RAW`, `insertDataOption=INSERT_ROWS`), and grows the grid with `appendDimension` when the header needs more columns than the tab has. Excel uses Graph: `GET /shares/u!{base64url(url)}/driveItem` to resolve, then `/drives/{d}/items/{i}/workbook/worksheets/{ws}/usedRange(valuesOnly=true)` and `range(address='…')` PATCH. Excel values are written with the number format set to text (`@`) so nothing becomes a formula. |
| `Sheets_Reconciler` | `anchor-events-manager/class-sheets-reconciler.php` | **Pure, no I/O.** Takes (existing values, roster table `{header, rows}`, key header) and returns `{ header_append: string[], header_start_col: int, cells: [row,col,value][], appends: string[][] }`. Where the correctness lives; unit-tested without WordPress. |
| `Sheets_Sync` | `anchor-events-manager/class-sheets-sync.php` | Events glue: the event's spreadsheet metabox and its save handler, hooks, debounced scheduling, the sync run, status meta, and the "Sync now" action. |

The 16.9k-line `anchor-events-manager.php` only gets the `require_once` + `new Sheets_Sync( $this )`
wiring.

### OAuth callback

The callback URL is the REST route `/wp-json/anchor-tools/v1/connectors/callback`, a path with no
query string, because Microsoft restricts query strings in redirect URIs (they aren't allowed for
apps that accept personal accounts) and Google requires an exact match. The site needs pretty
permalinks for this path. With plain permalinks the screen shows a warning and the
`?rest_route=` URL instead. The connector's REST callback URL is what the screen shows.

- The Connect button (admin-post, nonce, `manage_options`) creates `state` (32 random bytes) and a
  PKCE verifier, and stores `{provider, verifier, user_id}` in a 10-minute transient keyed by
  `state`. Then it redirects to the provider.
- The callback (REST, `permission_callback` open, because the browser's redirect carries no REST
  nonce) accepts only a `state` that exists in a transient, deletes it immediately (single use),
  and checks that the stored user still has `manage_options`. It then exchanges the code with the
  verifier, stores the tokens, and redirects to the Connectors tab with a success or error
  notice. An unknown or expired state stops with a plain error page and no exchange.

### Data model (events)

- `_anchor_event_sheet_url`: the URL as pasted.
- `_anchor_event_sheet_ref`: the resolved ref, e.g. `{provider:'google', id, tab}` or
  `{provider:'microsoft', drive_id, item_id, worksheet}`. Resolved on save. The tab/worksheet
  comes from the URL (`gid=` for Google), else the first tab. A resolve failure is shown in the
  metabox and nothing is saved over the old ref.
- `_anchor_event_sheet_state`: `{ last_sync, ok, message, rows }`.

### What a row is

`Roster::export_table_public( $event_id, 'all' )`, the same builder the CSV export uses, so the
sheet and the CSV cannot drift. `all` scope includes cancelled/refunded seats, so "mark cancelled"
falls out naturally. A **group parent** with a sheet uses the all-dates table (leading Date
column) through a new public seam `export_table_all_dates_public()`. Group children without their
own sheet roll up to their parent's.

### Reconcile rules

- **Header (row 1):** columns are matched **by header text**. Roster columns missing from the sheet
  are appended at the right end. Hand-added columns are left alone and never written.
- **Rows:** keyed on `Seat ID`. Only changed cells in our columns are rewritten. Unknown seats are
  appended **oldest first**. Sheet rows whose seat isn't in the roster are untouched. Duplicate
  Seat IDs in the sheet: only the first is maintained.
- An empty sheet gets the header plus all rows (the backfill).
- Cells are compared as strings, so a missing trailing cell counts as `''`.
- Values are written raw/text and never evaluated (formula-injection guard).

### When it runs

- Triggers: `anchor_events_seat_created`, `anchor_events_seat_status_changed`, and
  `added_post_meta`/`updated_post_meta` on an `anchor_event_reg` post. Also saving an event with a
  new or changed URL, and "Sync now" (runs inline, then redirects back with the result).
- Each trigger resolves seat → event → **target** (the event if it has a ref, else its group parent
  if that has one), and schedules `anchor_events_sheet_sync( $target )` 30s out unless one is
  already queued. Bursts collapse into one sync, and the request that registered someone never
  waits on Google or Microsoft.
- The sync is a full idempotent reconcile, so a missed trigger or failed run heals on the next run.
  Failures are recorded in `_anchor_event_sheet_state` and `Events_Log::error()`, with no retry
  loop. A transient lock per target (120s) prevents interleaved runs.
- External-signup events have no roster, so the metabox says so and no sync runs.

### Auto-share (Google)

A setting on the Google connector card: **Auto-share spreadsheets with**.

- **Share with event managers** (checkbox, on by default): every user holding
  `Module::events_capability()` (the same capability as the roster screen, so it's the events
  manager admin list), by their account email.
- **Also share with** (textarea, one email per line).
- **Access:** Editor (default) or Viewer.
- **Notify people by email** (checkbox, on by default): Google's own share notification.

Sharing runs inside the sync. `Sheets_Sync::run()` computes the desired email set, compares a hash
of it with `_anchor_event_sheet_shared` on the event, and when they differ (first sync, list
changed, new manager) it lists the file's permissions and adds only the missing ones (Drive v3
`permissions.list` / `permissions.create`, `type=user`). It **never removes** access; un-sharing
someone is done by hand in Google. A share failure is recorded in the sync state as a warning and
does not fail the row sync.

This needs the Drive scope `https://www.googleapis.com/auth/drive`, because the narrower
`drive.file` only covers files the app created, not a pasted sheet. That's a *restricted* scope:
fine on an Internal (anchorcorps.com) consent screen, but an External app would need Google's
security assessment. A Google connection made before this scope was added shows "Reconnect to
enable auto-share". Excel auto-share is not built; Graph's `invite` endpoint would be the route if
asked.

### Auto-create (Google)

A second setting on the Google card: **Auto-create a spreadsheet for each new event** (off by
default), with an optional **Folder** (a Drive folder URL; blank = the connected account's My Drive).

- When an event is **first published** with the setting on, no spreadsheet URL, native (not
  external) registration, and it is not a group child (children roll up to the parent), it gets
  `_anchor_event_sheet_autocreate = pending` and a sync is scheduled. Inside the run, a pending
  event creates its spreadsheet first (Sheets `spreadsheets.create`, title
  `<Event title> — Registrants`, moved into the folder with Drive `files.update addParents`), stores
  the URL and ref, marks `_anchor_event_sheet_autocreate = done`, then shares and syncs as usual.
  Publishing never waits on Google.
- It happens once per event. If an admin later clears the URL, it is not recreated. Events that
  already exist when the setting is turned on are untouched.
- The metabox also gets a **Create spreadsheet** button for an event with no URL (any time, setting
  on or off), which does the same thing inline.
- Creation needs the Google connector to be connected. If it isn't, the pending event's state says
  so, and the next sync after connecting creates it.

### Errors the admin sees (event metabox)

- Provider not connected: "Connect Google (or Microsoft) under Settings → Anchor Tools → Connectors."
- Connector needs reconnect: same, with "Reconnect".
- 403/404: "The connected account (`<account>`) can't open this spreadsheet. Check the URL, or share it with that account."
- Anything else: the provider's error message.

### Security / privacy

- Connectors screen: `manage_options`. Event spreadsheet field and Sync now: the roster capability
  `Roster::current_user_can_manage()`, plus `edit_post`, with nonces.
- Client secrets and refresh tokens are encrypted at rest and never echoed.
- The callback accepts single-use, short-lived, user-bound `state` only, with PKCE on the exchange.
- **PHI caution:** the owner asked for all fields. Registration answers on medical/dental client
  sites may be health-related and will land in Google or Microsoft. The metabox carries a one-line
  note. Column filtering is a possible later addition, not built.

### One-time setup outside the code (for the owner; the Connectors screen repeats it)

- **Google:** an OAuth client of type "Web application" in a GCP project whose consent screen
  allows the accounts that will connect. Internal = anchorcorps.com accounts only, with no Google
  review. Add each site's callback URL under Authorized redirect URIs. Enable the Google Sheets
  API on the project.
- **Microsoft:** an Entra app registration, "Accounts in any organizational directory and personal
  Microsoft accounts", Web platform, each site's callback URL as a redirect URI, a client secret,
  and the delegated Graph permissions `Files.ReadWrite.All`, `User.Read`, `offline_access`. A
  client tenant that blocks user consent needs its admin to approve once.

## Left to the builder

- TTL details beyond those stated, and the metabox wording beyond the error cases above.
- Column-letter math (A1 notation past Z).

## Builder's calls (made without asking)

- Scope `all` (not confirmed-only), so cancellations remain visible.
- One spreadsheet per event; group children roll up to the parent's.
- Connectors live under `includes/connectors/` as a site-wide primitive with its own settings tab,
  not inside events, so other modules can use it later.
- Microsoft tenant `common` (work, school, and personal accounts).
