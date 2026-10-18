# Library Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Anchor Private File Manager into Anchor Tools as the `library` module, keeping its behaviour and data the same, and add the read-only `anchor-library/v1` REST API for the member app.

**Architecture:** Port the plugin into `anchor-library/` under the PSR-4 namespace `\Anchor\Library\`, split along real seams: schema, storage, permissions, repository, AJAX, portal, users, settings, product docs, REST. Data, option and table names, shortcodes, AJAX actions and JS globals stay identical, so cutover is "deactivate the plugin, enable the module". A legacy guard keeps the module inert while the old plugin is active. The REST layer reuses `Permissions` and `Repository`, and delivers files through HMAC-signed, user-bound, 10-minute links.

**Tech Stack:** PHP 8.1, WordPress, WooCommerce (optional), PHPUnit (wp-env tests DB), existing vanilla JS assets.

**Spec:** `docs/superpowers/specs/2026-10-18-library-module-design.md`

**Source pin:**
- Port from `Anchor-Private-File-Manager` at the commit chosen in Task 0. That is the `main` merge of `feature/multi-role-users` (2.17.0), or `origin/main` 2.16.2 if the user declines.
- Record the SHA in the ledger. Every "port" step copies from that SHA.

## Global Constraints

- Module key `library`, dir `anchor-library/`, main file `anchor-library/anchor-library.php`, namespace `Anchor\Library\` → `anchor-library/src/`. It is **disabled by default**.
- Text domain `anchor-schema`. Leading backslash on WP functions inside the namespace. Assets load via `ANCHOR_TOOLS_PLUGIN_URL . 'anchor-library/assets/'`.
- **Unchanged:**
  - tables `{prefix}anchor_fm_*`;
  - options `anchor_fm_*`;
  - post meta `_anchor_pd_docs`;
  - cron hooks `anchor_fm_prune_resume` and `anchor_fm_storage_check`;
  - the constant `ANCHOR_FM_STORAGE_DIR`;
  - the filters `anchor_fm_storage_dir` and `anchor_fm_allowed_upload_extensions`;
  - the shortcodes `anchor_file_manager`, `anchor_account_portal` and `anchor_documents_portal`;
  - all `wp_ajax_*` action names and nonce names;
  - the JS globals `AnchorFM` and `AnchorAP`;
  - the `#afm-…` hash links.
- The module never loads if `class_exists('Anchor_Private_File_Manager')`. It shows an admin notice instead.
- **REST:**
  - namespace `anchor-library/v1`, GET only;
  - 401 `anchor_library_auth` when signed out, 403 `anchor_library_forbidden`, 404 `anchor_library_not_found`;
  - weak body-hash ETag, `Cache-Control: private, max-age=60`, `Vary: Authorization`.
- **Link token:**
  - payload `user_id.file_id.disposition.exp`, base64url-encoded, plus an HMAC-SHA256 over the encoded payload with `wp_salt('auth')`;
  - TTL 600 s; checked with `hash_equals`;
  - the content route re-checks the view right for the token's user.
- Files under 1,000 lines and functions under 150. No PUC updater in the module.
- **Tests:** `@group library`. Run with `WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress vendor/bin/phpunit --group library`, after `composer dump-autoload -q`; then `git checkout -- vendor`. Never commit vendor. Run the full suite before the final commit.
- **Git:**
  - branch `feat/library-module` (worktree `/Users/bif/Developer/anchor-os/anchor-tools-wt-library`);
  - commits name explicit paths and end with the Co-Authored-By trailer;
  - push only with `git push origin feat/library-module`;
  - the PR body carries `@coderabbitai ignore`.

## Review Focus

1. **A 2.14.0 database (live TMJ) upgrading on first module load.** The 2.15.0 host-pinned settings and the 2.16.x steps must run once, and must not run again when the module is toggled or a request repeats. Task 2's upgrade test from a 2.14.0 fixture covers this.
2. **Old plugin still active while the module is enabled.** There must be no fatal error and no doubled hooks, and the site must keep working on the plugin. Task 1's guard test covers this.
3. **Permission revoked after a link was issued.** The content route must refuse it, not only once the link has expired. Task 6 has a test.
4. **REST requests without a cookie session.** Permissions and the per-request memo must use the Application Password user, and nothing may read a nonce. Task 5 tests run with `wp_set_current_user` and no nonce, plus one test where the memo is warm for a different user in the same process.
5. **Range and large files through REST.** Partial content (206), a correct `Content-Length` and no full-file buffering are required. Task 6 has a Range test with a multi-MB fixture.

---

### Task 0: Choose the source pin (user's call, recorded)

- [ ] **Step 1:** Ask the user whether to merge `feature/multi-role-users` (2.17.0) into the plugin's main first. The recommendation is yes: it touches only the admin Users tab and has no schema change.
- [ ] **Step 2:** If yes, open the plugin PR with `@coderabbitai ignore` in the body. Merge only after explicit sign-off. Record the merge SHA as the pin. If no, the pin is `origin/main` 2.16.2.

### Task 1: Module skeleton, registry and legacy guard

**Files:**
- Create: `anchor-library/anchor-library.php`, `anchor-library/src/Module.php`
- Modify: `anchor-tools.php` (registry entry in `anchor_tools_get_available_modules()`, default off), `composer.json` (PSR-4 `Anchor\\Library\\`)
- Test: `tests/test-library-module.php`

**Interfaces:**
- Produces:
  - `\Anchor\Library\Module::boot(): void` wires every component.
  - `\Anchor\Library\Module::legacy_active(): bool` returns `class_exists('Anchor_Private_File_Manager', false)`.
  - The constant `ANCHOR_LIBRARY_DIR`.

- [ ] **Step 1: Failing tests.**
  - `test_registry_lists_library_disabled_by_default`: the key exists, and `anchor_tools_is_module_enabled('library')` is false when nothing is stored.
  - `test_legacy_plugin_blocks_boot`: define a stub `Anchor_Private_File_Manager` class in the test, call `boot()`, and assert that no `anchor_file_manager` shortcode was registered by the module and that an `admin_notices` callback was added.
  - `test_boots_without_legacy`: shortcodes are registered once `boot()` has run (after Task 4; mark it incomplete until then).
- [ ] **Step 2:** Run it and see it fail.
- [ ] **Step 3:** Implement. The notice text: "Anchor Library is waiting: deactivate the Anchor Private File Manager plugin to switch to the built-in Library."
- [ ] **Step 4:** Run it and see it pass.
- [ ] **Step 5:** Commit.

### Task 2: Schema, upgrader and storage

**Files:**
- Create: `src/Schema.php`, `src/Storage.php`, `src/StorageGuard.php`; move `docs/PRIVATE-STORAGE.md` → `anchor-library/PRIVATE-STORAGE.md`
- Test: `tests/test-library-schema.php`, `tests/test-library-storage.php`

**Interfaces:**
- Produces:
  - `Schema::table(string $name): string`
  - `Schema::install(): void` (dbDelta, from the plugin's `activate()` table SQL)
  - `Schema::maybe_upgrade(): void` (the plugin's `maybe_upgrade_db()` with the same version gates, ending at the pinned version)
  - `Storage::base_dir(): string`
  - `Storage::path_for(object $file): string`
  - `Storage::stream(string $path, string $mime, string $disposition, string $name): void` (Range-aware, chunked, from `stream_file_range` and `Anchor_FM_Range`)

- [ ] **Step 1: Failing tests.**
  - `test_upgrade_from_2_14_runs_each_step_once`: set `anchor_fm_db_version=2.14.0` and call `maybe_upgrade()` twice. Assert the version equals the pin, and that the 2.15.0 portal settings were written only once (spy via an option-update counter).
  - `test_install_creates_all_tables`: every `anchor_fm_*` table from the plugin exists.
  - `test_storage_dir_constant_and_filter_precedence`: same order as the plugin's `default_storage_base()`.
  - `test_storage_dir_is_0700_and_dot_prefixed_by_default`.
- [ ] **Step 2:** Run it and see it fail.
- [ ] **Step 3:** Port. `Schema::maybe_upgrade()` runs on `plugins_loaded` from `Module::boot()` only, not at plugin activation, because modules have no activation hook. Cron scheduling and unscheduling move with it.
- [ ] **Step 4:** Run it and see it pass.
- [ ] **Step 5:** Commit.

### Task 3: Permissions and repository

**Files:**
- Create: `src/Permissions.php`, `src/Repository.php`, `src/Activity.php`. Move the `includes/` helper classes into `anchor-library/includes/` unchanged, required from the bootstrap.
- Test: `tests/test-library-permissions.php`, `tests/test-library-helpers.php` (a PHPUnit port of the plugin's `tests/run.php` cases)

**Interfaces:**
- Produces:
  - `Permissions::folder_capability(int $folder_id, ?int $user_id = null): string` (`none|view|manage`)
  - `Permissions::can_view_file(int $file_id, ?int $user_id = null): bool` (folder, file override and product grant)
  - `Permissions::reset_memo(): void`
  - `Repository::children(int $folder_id, int $user_id): array{folders,files,videos,links}` (Product Docs folder hidden)
  - `Repository::roots(int $user_id): array`
  - `Repository::search(string $q, int $user_id): array`
  - `Repository::breadcrumbs(int $folder_id): array`
  - `Activity::log(string $action, string $entity_type, int $entity_id, array $meta = []): void`

- [ ] **Step 1: Failing tests.**
  - The admin sees everything.
  - The owner can manage.
  - A role grant is inherited two levels deep.
  - A file-level row overrides its folder (both deny and allow).
  - A user grant beats no grant.
  - The depth cap holds at 50.
  - A Woo order grants a product-doc file (skip if Woo is absent).
  - Product Docs are hidden from `roots` and `children`.
  - The memo is keyed by user: a warm memo for user A never answers for user B.
  - All helper cases from `tests/run.php` pass.
- [ ] **Step 2:** Run it and see it fail.
- [ ] **Step 3:** Port the logic from the plugin's `get_effective_capability`, `compute_folder_capability_uncached`, `user_can_view_file_via_product` and the listing and search queries. Keep the SQL and policy behaviour byte-for-byte where possible. Every public method takes an explicit `$user_id`, with null meaning the current user.
- [ ] **Step 4:** Run it and see it pass.
- [ ] **Step 5:** Commit.

### Task 4: Front end, AJAX, Users, Settings and Product Docs (parity port)

**Files:**
- Create:
  - `src/Portal.php`
  - `src/Ajax/Files.php`, `src/Ajax/Folders.php`, `src/Ajax/Media.php`, `src/Ajax/Users.php`, `src/Ajax/Account.php` (group the roughly 50 actions by area)
  - `src/Users.php`, `src/Settings.php`, `src/ProductDocs.php`
  - `anchor-library/assets/*` (copied, URLs switched to `ANCHOR_TOOLS_PLUGIN_URL`)
- Test: `tests/test-library-parity.php`

**Interfaces:**
- Consumes everything from Tasks 2 and 3.
- Produces the same hooks, actions, shortcodes and localized globals as the plugin at the pin.

- [ ] **Step 1: Failing tests.**
  - `test_every_plugin_ajax_action_is_registered`: build the list from the plugin source at the pin (fixture file `tests/fixtures/library-ajax-actions.txt`, generated with grep and committed). Assert each `wp_ajax_*` and `wp_ajax_nopriv_*` hook has a callback after `boot()`.
  - `test_shortcodes_registered`.
  - `test_stream_ajax_refuses_without_view_right` and `test_stream_ajax_serves_with_view_right`: call the handler with a nonce and capture the output.
  - `test_settings_page_and_option_keys_unchanged`.
  - `test_users_tab_multi_role_assignment` (if the pin is 2.17.0).
  - `test_localized_globals_names`: `AnchorFM` and `AnchorAP` are passed to `wp_localize_script`.
- [ ] **Step 2:** Run it and see it fail.
- [ ] **Step 3:** Port the handlers. Each handler class stays under 1,000 lines and each function under 150. Where the plugin has a longer function, split it at a named step. Remove the PUC updater.
- [ ] **Step 4:** Run it and see it pass. Also check `test_boots_without_legacy` from Task 1.
- [ ] **Step 5:** Commit.

### Task 5: REST read routes

**Files:**
- Create: `src/Rest/Routes.php`, `src/Rest/Shapes.php`
- Test: `tests/test-library-rest.php`

**Interfaces:**
- Consumes the `Repository` and `Permissions` methods with an explicit user id.
- Produces:
  - `GET /anchor-library/v1/folders/(?P<id>\d+)`, where id 0 means the roots.
  - `GET /anchor-library/v1/search?q=`
  - The JSON shapes from the spec.
  - A shared response helper that sets the ETag, `Cache-Control` and `Vary`, and returns 304. Reuse the events feed's approach and say so in a comment: one pattern, not a second invention. If that helper is extractable without touching events, note it as a follow-up and do not refactor events here.

- [ ] **Step 1: Failing tests.**
  - Signed out → 401.
  - A Centre user sees only the folders they're granted at root.
  - A forbidden folder → 403. A missing folder → 404.
  - The shape keys are exact, and there is no uploader name or e-mail.
  - The video `embed_url` format is correct.
  - Product Docs are hidden.
  - An ETag/304 round trip works.
  - `Vary: Authorization` is present.
  - A query under 2 characters → 400 `anchor_library_query`.
- [ ] **Step 2:** Run it and see it fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run it and see it pass.
- [ ] **Step 5:** Commit.

### Task 6: Signed file links and content streaming

**Files:**
- Create: `src/Rest/LinkToken.php`. Modify `src/Rest/Routes.php`.
- Test: `tests/test-library-links.php`

**Interfaces:**
- Produces:
  - `LinkToken::issue(int $user_id, int $file_id, string $disposition, int $now): string`
  - `LinkToken::verify(string $token, int $file_id, int $now): ?array{user_id:int,disposition:string}`
  - `GET /files/{id}/link?disposition=inline|attachment` → `{url, expires_at}`
  - `GET /files/{id}/content?t=`

- [ ] **Step 1: Failing tests.**
  - The token round-trips.
  - A token past its expiry → null.
  - A tampered payload or signature → null.
  - A token for file A used on file B → null.
  - `/link` signed out → 401. `/link` without the view right → 403.
  - `/content` with a valid token streams the bytes, and the activity row has source `app`.
  - `/content` after the grant has been removed → 403, even inside the TTL.
  - A Range request returns 206 with `bytes=0-99` and a `Content-Length` of 100, on a 3 MB fixture.
  - `disposition=attachment` sets `Content-Disposition: attachment`.
- [ ] **Step 2:** Run it and see it fail.
- [ ] **Step 3:** Implement. The content route has an open `permission_callback`; the token is the auth, and it never accepts cookie or nonce auth. Stream through `Storage::stream()`.
- [ ] **Step 4:** Run it and see it pass.
- [ ] **Step 5:** Commit.

### Task 7: Docs, release notes and local cutover rehearsal

**Files:**
- Create: `anchor-library/LIBRARY.md` (module overview, REST reference, cutover runbook).
- Modify: `readme.txt` (changelog under Unreleased; no version bump on the branch).

- [ ] **Step 1:** Write `LIBRARY.md` from the spec. Include the REST reference in the format of the events module's "REST feed (member app)" section, because the app repo copies it.
- [ ] **Step 2: Rehearse the cutover on the local DevKinsta TMJ copy.**
  1. Back up the plugin folder, the database (`wp db export` inside the container) and `wp-config.php`.
  2. Copy the branch's Anchor Tools into the local site.
  3. Confirm the guard notice shows while the plugin is active.
  4. Deactivate the plugin and enable the module.
  5. Check that `anchor_fm_db_version` advanced from 2.14.0 to the pin.
  6. Check `/my-account/` Files as a Centre test user and as an admin: open a file, download it, play a video, and check the Users tab and Settings.
  7. Check that the storage self-test is green.
  8. Check REST with a temporary Application Password, using curl through `scripts/local-site-proxy.js` or https:
     - `/folders/0`;
     - a folder;
     - `/search`;
     - `/link` then `/content`.
  9. **Restore everything:** the DB import, the plugin folder, `wp-config.php` and the plugin re-activated. Delete the test user and its app password. Diff-check the restore, as in the member-app E2E report.
- [ ] **Step 3:** Run the full PHPUnit suite. Zero failures, apart from the documented pre-existing environment failures, which are listed by name.
- [ ] **Step 4:** Commit. Then the final whole-branch review (Opus), one fix wave, the push and the PR (`@coderabbitai ignore`). The release and the live cutover each need the user's explicit OK.
