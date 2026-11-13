# Events → Spreadsheet Mirror (Google Sheets + Excel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Paste a Google Sheet or Excel URL on an event (or let new events get a Google Sheet automatically, shared with event managers), and the spreadsheet mirrors that event's registrant list (append new, update changed, cancelled stays marked cancelled). Access comes from per-site OAuth "Connectors" for Google and Microsoft.

**Architecture:** A site-wide OAuth connector primitive (`includes/connectors/`) holds encrypted tokens per provider and exposes `request()`. Two spreadsheet adapters (Google Sheets v4, Microsoft Graph workbook) share one interface. A pure reconciler diffs the roster table against the sheet. `Sheets_Sync` in the events module schedules a debounced full reconcile whenever a seat changes.

**Tech Stack:** PHP 7.4+ / WordPress, jQuery only, no new Composer deps (`openssl` for AES-GCM + PKCE), PHPUnit 9 (WP integration suite + pure unit suite).

**Spec:** `docs/superpowers/specs/2026-11-13-events-google-sheets-design.md`. Read it first; this plan does not repeat its reasoning.

## Global Constraints

- Text domain `'anchor-schema'`; every `update_option()` passes `autoload=false` (third arg `false`).
- No native `alert()/confirm()/prompt()`. Disconnect uses the inline arm-then-confirm button ("Click again to disconnect").
- Source files under 1,000 lines, functions under 150. `anchor-events-manager.php` (16.9k lines) gets wiring only.
- No `.min.*` files committed. Enqueue source assets only, and only on the Connectors tab / event edit screen.
- All HTTP through `wp_remote_request()` so tests can intercept with `pre_http_request`. **No test may reach the network.**
- Secrets (client secret, refresh token, access token) never echoed into HTML, logs, `Events_Log` context, or error messages.
- Values written to sheets are raw text, never formulas: Google `valueInputOption=RAW`; Excel `numberFormat` `@` on written ranges.
- Integration tests: `export WP_TESTS_DIR="$TMPDIR/wordpress-tests-lib" WP_CORE_DIR="$TMPDIR/wordpress"` then `./vendor/bin/phpunit --filter <Class>`. Unit tests: `./vendor/bin/phpunit -c phpunit-unit.xml.dist --filter <Class>`. Background runs are wrapped in `~/.claude/bin/with-timeout 3600`.
- Commits end with the session's Co-Authored-By / Claude-Session trailers.

## Review Focus

1. **A sheet whose header was hand-reordered or has hand-added columns in the middle.** Our cells land under the right header, and their columns are never written. → Task 1 test `test_hand_added_middle_column_is_never_written`.
2. **Header needs more columns than the Google tab's grid** (23 base columns + questions; a new tab has 26). The header write must not fail with "exceeds grid limits". → Task 4 test `test_google_grows_grid_before_widening_header`.
3. **Refresh token revoked/expired** (`invalid_grant`). The connector flips to `needs_reconnect`, the event shows "Reconnect", and nothing retries in a loop. → Task 2 test `test_invalid_grant_marks_needs_reconnect`.
4. **Microsoft rotates the refresh token on every refresh.** The new one must be stored, or the next refresh fails. → Task 2 test `test_rotated_refresh_token_is_persisted`.
5. **A 5-seat checkout fires 5+ seat events and many meta writes.** Exactly one sync is queued, and registration makes zero HTTP calls. → Task 5 test `test_burst_of_seat_changes_queues_one_sync_and_no_http`.

---

### Task 1: Sheets_Reconciler (pure diff)

**Files:**
- Create: `anchor-events-manager/class-sheets-reconciler.php`
- Test: `tests/unit/test-sheets-reconciler.php`

**Interfaces:**
- Produces: `\Anchor\Events\Sheets_Reconciler::reconcile( array $existing, array $table, string $key_header ): array` returning `['header_append' => string[], 'header_start_col' => int, 'cells' => array<array{0:int,1:int,2:string}>, 'appends' => string[][]]`. `$existing` = sheet values (row 0 = header, ragged rows allowed). `$table` = `['header' => string[], 'rows' => array[]]`, rows newest-first, as `Roster::export_table_public()` returns them. `cells` rows are 1-based sheet rows, cols 0-based. `appends` are full-width rows laid out against the **final** header (our values under our columns, `''` under hand-added ones).
- Produces: `Sheets_Reconciler::col_letter( int $index0 ): string` (0→`A`, 25→`Z`, 26→`AA`, 701→`ZZ`, 702→`AAA`).

- [ ] **Step 1: Write the failing tests** (`require_once` the class file at the top; class `Test_Sheets_Reconciler extends PHPUnit\Framework\TestCase`, `@group events-unit`):
  - `test_empty_sheet_gets_full_header_and_all_rows_oldest_first`: existing `[]`, table header `['Seat ID','Name','Status']`, rows `[[3,'C','confirmed'],[2,'B','confirmed']]` → `header_append` = the 3 headers, `header_start_col` 0, `cells` `[]`, `appends` `[['2','B','confirmed'],['3','C','confirmed']]`.
  - `test_status_change_updates_only_that_cell`: existing `[['Seat ID','Name','Status'],['2','B','confirmed']]`, table row `[2,'B','cancelled']` → `cells` `[[2,2,'cancelled']]`, no appends.
  - `test_unchanged_sheet_produces_no_writes`: all three outputs empty.
  - `test_new_question_column_is_appended_to_header_end`: sheet header lacks `Diet`, table has it → `header_append ['Diet']`, `header_start_col` = existing header width, and the existing row gets its Diet cell in `cells`.
  - `test_hand_added_middle_column_is_never_written`: sheet header `['Seat ID','Notes','Name','Status']` with Notes = `'vip'` → no cell in col 1, and appended rows have `''` at index 1.
  - `test_reordered_sheet_columns_are_matched_by_header_text`.
  - `test_sheet_row_for_unknown_seat_is_untouched` and `test_duplicate_seat_id_only_first_is_maintained`.
  - `test_ragged_rows_compare_missing_cells_as_empty`: sheet row `['2','B']`, table status `''` → no write.
  - `test_ints_and_strings_compare_equal`: sheet `'2'` vs table `2` → no write.
  - `test_col_letter` with the 5 values above.
- [ ] **Step 2: Run, expect FAIL** (class not found): `./vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Sheets_Reconciler`
- [ ] **Step 3: Implement** in namespace `Anchor\Events`, with an `ABSPATH` guard and no WordPress calls. Map key column and our columns by header text against the final header (existing + appended). Index the sheet by key value, first occurrence wins. Cast everything with `(string)`.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(events): pure reconciler for spreadsheet mirror`

### Task 2: Secret box + OAuth connector primitive + providers

**Files:**
- Create: `includes/connectors/class-anchor-secret-box.php`, `includes/connectors/class-anchor-oauth-connector.php`, `includes/connectors/providers.php`
- Modify: `anchor-tools.php` (require the three files next to the other `includes/` requires, ~line 108, unconditionally)
- Test: `tests/test-oauth-connector.php`

**Interfaces:**
- Produces: `Anchor_Secret_Box::seal( string $plain ): string` / `::open( string $sealed ): string|false` (AES-256-GCM, key = `hash('sha256', wp_salt('auth'), true)`, output `base64(iv|tag|ct)`).
- Produces: `Anchor_OAuth_Connector::get( string $provider ): ?Anchor_OAuth_Connector` (registry over `anchor_oauth_providers()`, keys `google`, `microsoft`). Instance methods:
  - `save_app_credentials( string $client_id, string $client_secret ): void` (blank secret = keep the existing one)
  - `has_app_credentials(): bool`, `is_connected(): bool`, `needs_reconnect(): bool`, `account(): string`, `label(): string`
  - `callback_url(): string` → `rest_url( 'anchor-tools/v1/connectors/callback' )`
  - `begin_authorize( int $user_id ): string` (authorize URL; stores the state transient `anchor_oauth_state_{state}` = `{provider, verifier, user_id}`, TTL 600)
  - `static handle_callback( WP_REST_Request $req ): WP_REST_Response|WP_Error` (registered on `rest_api_init`, route `anchor-tools/v1` `/connectors/callback`, GET, `permission_callback` `__return_true`; on success, 302 to `admin_url('options-general.php?page=anchor-schema&tab=connectors&anchor_connected=<provider>')`)
  - `access_token(): string|WP_Error` (transient `anchor_oauth_at_{provider}`, TTL `expires_in - 120`)
  - `request( string $method, string $url, ?array $json_body = null, array $headers = [] ): array|WP_Error` (Bearer, JSON; non-2xx → `WP_Error('anchor_oauth_http', <provider error message>, ['status' => code])`)
  - `disconnect(): void`
- Option `anchor_connector_{provider}` = `['client_id','client_secret'(sealed),'refresh_token'(sealed),'account','connected_at','needs_reconnect']`.
- `providers.php` defines `anchor_oauth_providers(): array` with the exact endpoints, scopes, and extra auth params from the spec's Parts table, plus `account_url` (`https://openidconnect.googleapis.com/v1/userinfo` → `email`; `https://graph.microsoft.com/v1.0/me` → `mail` ?: `userPrincipalName`).

- [ ] **Step 1: Write failing tests** (`WP_UnitTestCase`; mock HTTP with a `pre_http_request` filter that records `[method,url,body]` and returns canned responses):
  - `test_secret_box_round_trips_and_rejects_tampering`
  - `test_secrets_are_not_stored_in_plaintext`: the raw option contains neither the client secret nor the refresh token.
  - `test_authorize_url_has_pkce_state_and_provider_params`: Google URL contains `code_challenge_method=S256`, `access_type=offline`, `prompt=consent`, and the exact `redirect_uri`; the state transient exists.
  - `test_callback_rejects_unknown_state_and_makes_no_http`
  - `test_callback_state_is_single_use`
  - `test_callback_rejects_user_without_manage_options`
  - `test_callback_exchanges_code_and_stores_account`: the token POST body carries `code_verifier` and `grant_type=authorization_code`; `account()` = the mocked email.
  - `test_access_token_is_cached_until_near_expiry`: two calls make one token POST.
  - `test_rotated_refresh_token_is_persisted` (Review Focus 4)
  - `test_invalid_grant_marks_needs_reconnect` (Review Focus 3): `access_token()` returns a WP_Error, `needs_reconnect()` is true, and a second call makes **no** HTTP.
  - `test_request_maps_google_and_graph_error_shapes`: `{error:{message}}` for both providers, `status` in the error data.
- [ ] **Step 2: Run, expect FAIL:** `./vendor/bin/phpunit --filter Test_OAuth_Connector`
- [ ] **Step 3: Implement** the three files. Microsoft token requests are form-encoded and include `scope`; Google's are form-encoded without it. Clear `needs_reconnect` on a successful callback.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(connectors): OAuth connector primitive with encrypted tokens (Google, Microsoft)`

### Task 3: Connectors settings tab

**Files:**
- Create: `includes/connectors/class-anchor-connectors-admin.php`, `assets/admin/connectors.js` (copy-URL button + arm-then-confirm Disconnect, jQuery IIFE)
- Modify: `anchor-tools.php` (require + `new Anchor_Connectors_Admin()`, admin only)
- Test: `tests/test-connectors-admin.php`

**Interfaces:**
- Consumes: Task 2's `Anchor_OAuth_Connector` API.
- Produces: tab key `connectors` via `anchor_settings_tabs` at priority 15. admin-post actions `anchor_connector_save` (credentials), `anchor_connector_connect`, `anchor_connector_disconnect`, each with nonce `anchor_connector_{action}_{provider}` and `manage_options`.

- [ ] **Step 1: Failing tests:**
  - `test_tab_is_registered`
  - `test_render_shows_callback_url_and_never_the_secret`
  - `test_save_requires_nonce_and_manage_options`
  - `test_blank_secret_keeps_existing`
  - `test_plain_permalinks_shows_warning`: with `permalink_structure` empty, the output contains the warning text.
  - `test_connect_redirects_to_provider`: the `wp_redirect` filter captures a URL starting with the provider's `auth_url`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** One card per provider, with: status line (Not configured / Ready to connect / Connected as `<account>` / Needs reconnect); Client ID; Client Secret (`type=password`, empty value, placeholder `••••••••` when saved); callback URL in a readonly input with a Copy button; a collapsible "How to set this up" with the spec's one-time setup steps for that provider; Connect (or Reconnect) / Disconnect. Show the success/error notice from `anchor_connected` / `anchor_connector_error` query args. Enqueue `connectors.js` only on this tab via `anchor_settings_enqueue_connectors`.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(connectors): Connectors settings tab`

### Task 4: Spreadsheet adapters (Google Sheets, Excel)

**Files:**
- Create: `includes/connectors/interface-anchor-sheet-adapter.php`, `includes/connectors/class-anchor-sheet-google.php`, `includes/connectors/class-anchor-sheet-excel.php`
- Modify: `anchor-tools.php` (requires)
- Test: `tests/test-sheet-adapters.php`

**Interfaces:**
- Consumes: `Anchor_OAuth_Connector::get('google'|'microsoft')->request()`; `Sheets_Reconciler::col_letter()` (move it to the adapters if a reviewer prefers; one copy only).
- Produces: `interface Anchor_Sheet_Adapter { resolve( string $url ); read( array $ref ); write_cells( array $ref, array $cells ); append_rows( array $ref, array $rows ); ensure_columns( array $ref, int $count ); }` plus `Anchor_Sheet_Adapters::for_url( string $url ): ?Anchor_Sheet_Adapter` (`docs.google.com/spreadsheets/d/<id>` → Google; `*.sharepoint.com`, `onedrive.live.com`, `1drv.ms` → Excel) and `Anchor_Sheet_Adapters::for_ref( array $ref ): ?Anchor_Sheet_Adapter`.
- Ref shapes (stored in event meta): Google `['provider'=>'google','id'=>string,'tab'=>string]`; Excel `['provider'=>'microsoft','drive_id'=>string,'item_id'=>string,'worksheet'=>string]`.

- [ ] **Step 1: Failing tests (mocked HTTP):**
  - `test_google_resolve_uses_gid_tab_or_first_tab`: one spreadsheets GET with `fields=sheets.properties`.
  - `test_google_resolve_rejects_non_sheet_url`
  - `test_google_writes_are_raw`: `valueInputOption=RAW` on batchUpdate and on append (`insertDataOption=INSERT_ROWS`).
  - `test_google_tab_names_are_quoted_in_ranges`: tab `Bob's List` → `'Bob''s List'!A1`.
  - `test_google_grows_grid_before_widening_header` (Review Focus 2): the tab reports `columnCount` 26 and `ensure_columns(30)` sends `appendDimension` length 4 before any values write.
  - `test_excel_resolve_encodes_share_url`: the GET hits `/shares/u!<base64url, no padding>/driveItem`, and worksheet = the first from `/workbook/worksheets`.
  - `test_excel_write_cells_patches_text_number_format`
  - `test_excel_append_writes_after_used_range`
  - `test_403_maps_to_cannot_open_message`: the adapter returns `WP_Error` code `anchor_sheet_forbidden`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Google `read` = `values.get` on `'<tab>'` (whole tab). Excel `read` = `usedRange(valuesOnly=true)`, stringified. Excel `write_cells` groups cells per row into contiguous ranges. Excel `append_rows` writes at `usedRange` row count + 1 (Excel has no grid limit, so `ensure_columns` is a no-op there).
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(connectors): Google Sheets and Excel adapters`

### Task 5: Sheets_Sync core (targets, debounce, run)

**Files:**
- Create: `anchor-events-manager/class-sheets-sync.php`
- Modify: `anchor-events-manager/anchor-events-manager.php` (require `class-sheets-reconciler.php` and `class-sheets-sync.php` with the other always-on requires ~line 461; `$this->sheets_sync = new Sheets_Sync( $this );` after `$this->roster`; declare `public $sheets_sync;`)
- Modify: `anchor-events-manager/class-roster.php` (add `export_table_all_dates_public( $parent_id, $scope )` next to `export_table_public()`)
- Test: `tests/test-events-sheets-sync.php` (extends `Anchor_Events_TestCase`)

**Interfaces:**
- Consumes: Task 1 reconciler; Task 4 `Anchor_Sheet_Adapters::for_ref()`; `Roster::export_table_public()`; `Occurrences::parent_of()` / `is_group_parent()`; `Module::uses_external_signup()`.
- Produces: constants `META_URL='_anchor_event_sheet_url'`, `META_REF='_anchor_event_sheet_ref'`, `META_STATE='_anchor_event_sheet_state'`, `HOOK='anchor_events_sheet_sync'`, `DELAY=30`; `target_for_event( int $event_id ): int`; `schedule( int $target ): void`; `run( int $target ): array` (the saved state `{last_sync,ok,message,rows}`); `get_state( int $event_id ): array`.

- [ ] **Step 1: Failing tests** (inject a fake adapter with the filter `anchor_sheet_adapter_for_ref`, which returns an in-memory `Anchor_Sheet_Adapter` holding a 2D array, so the sync is tested without HTTP):
  - `test_target_is_event_then_group_parent_then_zero`
  - `test_seat_created_schedules_one_sync` and `test_status_change_schedules`
  - `test_burst_of_seat_changes_queues_one_sync_and_no_http` (Review Focus 5): 5 `make_seat()` calls plus a status change → `wp_next_scheduled( HOOK, [ $event ] )` set once, and the `pre_http_request` spy saw 0 calls.
  - `test_event_without_sheet_schedules_nothing`
  - `test_run_backfills_then_updates_cancelled_row_in_place`: 2 seats → run → fake sheet has header + 2 rows; cancel one → run → still 2 data rows, and that row's Status = `cancelled`.
  - `test_group_parent_sheet_gets_child_seats_with_date_column`
  - `test_run_records_failure_state_and_logs_without_secret`: the adapter returns WP_Error → state `ok=false` with its message, and nothing token-like appears in `Events_Log`.
  - `test_lock_prevents_concurrent_run`
  - `test_external_signup_event_does_not_sync`
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Hooks: `anchor_events_seat_created`, `anchor_events_seat_status_changed`, `added_post_meta` + `updated_post_meta` (only when `get_post_type( $object_id ) === Module::REG_CPT`; seat → event via `_anchor_event_id`), and `HOOK` → `run()`. The run builds the table (all-dates for a group parent), takes the key header = the translated `'Seat ID'`, then: `read` → `reconcile` → `ensure_columns` → write the header cells and changed cells → `append_rows` → save state. Lock transient `anchor_ev_sheet_lock_{id}`, 120s.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(events): mirror registrants to a connected spreadsheet`

### Task 6: Event metabox, save, Sync now

**Files:**
- Modify: `anchor-events-manager/class-sheets-sync.php` (metabox + handlers; split into `class-sheets-sync-admin.php` if the file nears 1,000 lines)
- Test: `tests/test-events-sheets-sync-admin.php`

**Interfaces:**
- Consumes: Task 5 constants/`run()`/`schedule()`; `Anchor_Sheet_Adapters::for_url()`; `Anchor_OAuth_Connector`; `Roster::current_user_can_manage()`.
- Produces: side metabox `anchor_event_spreadsheet` ("Spreadsheet sync") on `Module::CPT`; field `anchor_event_sheet_url`, nonce `anchor_event_sheet_nonce`; `save_post_event` handler at priority 20; admin-post `anchor_events_sheet_sync_now` with nonce `anchor_events_sheet_sync_now_{event_id}`.

- [ ] **Step 1: Failing tests:**
  - `test_save_resolves_url_and_schedules_sync`
  - `test_save_with_unconnected_provider_keeps_url_and_shows_connect_message`
  - `test_resolve_failure_keeps_previous_ref`
  - `test_clearing_url_removes_ref_and_unschedules`
  - `test_save_requires_nonce_and_capability`
  - `test_sync_now_runs_inline_and_redirects_with_state`
  - `test_metabox_shows_phi_note_and_last_sync_state`
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** The metabox shows: URL input; connected-account line for the matching provider, or the spec's "Connect … under Settings → Anchor Tools → Connectors" link; last sync (time in site timezone, row count, or the error); a Sync now button; and the one-line PHI note ("Every registration field and answer is copied to this spreadsheet."). The external-signup state reads "This event takes sign-ups on an external form, so there's nothing to sync."
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(events): spreadsheet sync metabox and Sync now`

### Task 7: Google auto-share

**Files:**
- Modify: `includes/connectors/class-anchor-connectors-admin.php` (Auto-share fields on the Google card), `includes/connectors/class-anchor-sheet-google.php` (`share()`), `anchor-events-manager/class-sheets-sync.php` (share step in `run()`)
- Test: add to `tests/test-sheet-adapters.php`, `tests/test-connectors-admin.php`, `tests/test-events-sheets-sync.php`

**Interfaces:**
- Consumes: Task 2 connector option; Task 4 Google adapter; Task 5 `run()`; `Module::events_capability()`.
- Produces: Google connector option keys `share_managers` (bool, default true), `share_emails` (string[]), `share_role` (`writer`|`reader`, default `writer`), `share_notify` (bool, default true). `Anchor_Sheet_Google::share( array $ref, string[] $emails, string $role, bool $notify ): array|WP_Error` returns the emails newly added. `Anchor_OAuth_Connector::has_scope( string $scope ): bool` (granted scopes stored from the token response's `scope`). Event meta `_anchor_event_sheet_shared` = sha1 of the sorted, lowercased desired set + role.

- [ ] **Step 1: Failing tests:**
  - `test_share_adds_only_missing_permissions`: `permissions.list` returns `a@x.test`; desired `[a@x.test, b@x.test]` → exactly one `permissions.create` for b, with `sendNotificationEmail` matching `$notify` and `supportsAllDrives=true`.
  - `test_share_emails_are_case_insensitive`
  - `test_run_shares_with_events_capability_holders_plus_extra_emails`: an Editor (non-WC) user and an extra email are both shared; a Subscriber is not.
  - `test_run_skips_share_when_set_unchanged`: second run makes no permissions calls.
  - `test_new_manager_triggers_share_on_next_run`
  - `test_share_failure_is_a_warning_not_a_failed_sync`: rows still written, state `ok=true` with a warning message.
  - `test_connection_without_drive_scope_shows_reconnect_to_enable_autoshare`
  - `test_share_settings_save_validates_emails`: invalid lines are dropped and reported in a settings notice.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** The share step runs after the row writes and is skipped when `share_managers` is false and `share_emails` is empty, or when the connector lacks the drive scope (warning in state). Never remove a permission.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(connectors): auto-share Google spreadsheets with event managers`

### Task 8: Google auto-create

**Files:**
- Modify: `includes/connectors/class-anchor-connectors-admin.php` (Auto-create checkbox + Folder field), `includes/connectors/class-anchor-sheet-google.php` (`create()`), `anchor-events-manager/class-sheets-sync.php` (publish hook, pending step in `run()`, Create spreadsheet button + admin-post `anchor_events_sheet_create` with nonce `anchor_events_sheet_create_{event_id}`)
- Test: add to `tests/test-sheet-adapters.php`, `tests/test-events-sheets-sync.php`, `tests/test-events-sheets-sync-admin.php`

**Interfaces:**
- Consumes: Tasks 4–7.
- Produces: Google connector option keys `autocreate` (bool, default false), `autocreate_folder_id` (string, parsed from a `drive.google.com/drive/folders/<id>` URL; blank OK). `Anchor_Sheet_Google::create( string $title, string $folder_id = '' ): array|WP_Error` returns `['url' => string, 'ref' => array]`. Event meta `_anchor_event_sheet_autocreate` ∈ `pending|done`.

- [ ] **Step 1: Failing tests:**
  - `test_create_posts_title_and_moves_into_folder`: `spreadsheets.create` body title `Spring Gala — Registrants`; then `files.update` with `addParents=<folder>` and `supportsAllDrives=true`; no folder → no `files.update`.
  - `test_first_publish_with_autocreate_marks_pending_and_schedules`: `wp_update_post` draft→publish; no HTTP during the publish.
  - `test_autocreate_skips_external_signup_group_children_and_events_with_a_url`
  - `test_autocreate_off_does_nothing`
  - `test_republish_or_update_does_not_create_again`: a second publish transition after `done` with the URL cleared → stays without a URL.
  - `test_run_with_pending_creates_shares_and_syncs`: one create, the ref is stored, the share set is applied, and rows are written; state `ok`.
  - `test_pending_without_connection_waits_and_reports`: state message names connecting Google; still `pending`.
  - `test_create_spreadsheet_button_creates_inline_and_redirects`
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Hook `transition_post_status` (`$new === 'publish' && $old !== 'publish'`, post type `Module::CPT`). The pending step runs at the top of `run()` before the ref is read. The title format comes from the spec.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat(events): auto-create a Google spreadsheet for new events`

### Task 9: Full suite, docs, live verification

**Files:**
- Modify: `anchor-events-manager/EVENTS.md` (a "Spreadsheet sync" section: setup, behaviour, limits); `CLAUDE.md` (one line under Core Classes: `includes/connectors/`: OAuth connectors (Google, Microsoft) + spreadsheet adapters; Events spreadsheet sync uses them)

- [ ] **Step 1:** Full suites: `~/.claude/bin/with-timeout 3600 composer test` and `./vendor/bin/phpunit -c phpunit-unit.xml.dist`. Expect all green apart from the pre-existing WooCommerce-inactive skips. Exit 124 = hung; find the file, don't call it passed.
- [ ] **Step 2: Live check (needs the owner's OAuth apps).** On wp-env (`http://localhost:8888`, which is a permitted Google/Microsoft redirect host), register the callback URL, connect Google, paste a real test sheet, register 2 people, cancel 1, edit one answer, and press Sync now. Confirm: header + 2 rows, one Status `cancelled`, the answer updated, and a hand-added column untouched. Then turn on Share with event managers + Auto-create, publish a new event, and confirm a `<title> — Registrants` sheet appears (in the folder, if set) shared with the site's event managers. Repeat the mirror check with an Excel file on OneDrive. Record what was seen in the PR body.
- [ ] **Step 3: Commit** `docs(events): spreadsheet sync`
