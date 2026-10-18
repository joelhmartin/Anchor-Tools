# Library Module (Private File Manager in Anchor Tools) — Design

**Date:** 2026-10-18
**Roadmap:** project 3 of the member-app roadmap (`anchor-member-app/docs/specs/2026-10-12-member-app-v1-design.md`). Courses-in-app is parked.
**First site:** TMJ & Sleep Therapy Centre International, the only site running Anchor Private File Manager (live 2.14.0).
**Source plugin:** `joelhmartin/Anchor-Private-File-Manager`, origin/main 2.16.2, plus `feature/multi-role-users` (2.17.0, admin Users tab only).

---

## Why

Members (on TMJ, the Centres) get curated documents and videos through the File Manager portal at `/my-account/`. The member app needs the same library on the phone. Rule from the roadmap: **one place to upload everything**. The site stays the source, and the app mirrors it through an Anchor Tools REST API that uses the common module pattern.

Moving the plugin into Anchor Tools gives one codebase, one updater and one release line. It also gives the API a home next to the events feed.

## Decisions

| Question | Decision |
|---|---|
| Fold in, or keep the plugin and add an API to it | **Fold in** as module `library` (`anchor-library/`). The user approved this, and it is the only site that uses it. |
| Port or rewrite | **Port.** Keep behaviour, data and URLs. Split the 5.4k-line class along real seams, because the house rule is files under 1,000 lines and functions under 150. |
| Base version | **2.17.0.** Merge `feature/multi-role-users` into the plugin's main first. It is small, admin-only and has no schema change. |
| Data | **Untouched.** Same `{prefix}anchor_fm_*` tables, `anchor_fm_*` options, `_anchor_pd_docs` meta, cron hooks and storage dir. Nothing is copied. |
| Old plugin and module both active | **Never both run.** If the class `Anchor_Private_File_Manager` exists, the module does not load and shows an admin notice. |
| Default state | **Off.** It is enabled per site after the old plugin is deactivated. |
| App auth | Application Password (Basic), the same as the events feed. The library is **members-only**: signed-out requests get 401. |
| File delivery to the app | A **short-lived signed link** from the API, streamed by a REST route. It is never a direct file URL, and never a cookie nonce. |
| Video in the app | Vimeo player embed from the stored `vimeo_id` and unlisted `vimeo_hash`, the same as the web portal. The Vimeo token is not used in this project. |
| Offline video | **Out of scope** (roadmap project 4). It needs the Vimeo API, a plan that exposes file links, and one shared Vimeo client with webinars. |

## Module structure (Anchor Tools)

```
anchor-library/
  anchor-library.php        Module bootstrap: legacy guard, wiring
  src/                      PSR-4 \Anchor\Library\
    Schema.php              table(), dbDelta install, maybe_upgrade_db() with the existing version gates
    Storage.php             base dir resolution (constant, filter, legacy dir), 0700, Range streaming
    StorageGuard.php        (existing class) daily anonymous-access self-test
    Permissions.php         effective capability: admin, owner, tree walk, file override, product grant, memo
    Repository.php          folder/file/video/link queries (listing, search, children)
    Activity.php            audit log writes
    Ajax/*.php              the existing anchor_fm_* / anchor_ap_* / anchor_pd_* actions, grouped by area
    Portal.php              shortcodes [anchor_file_manager] [anchor_account_portal] [anchor_documents_portal]
    Users.php               Users tab (roles CRUD, multi-role), portal roles option
    Settings.php            Settings page (same option keys)
    ProductDocs.php         Woo product attachments
    Rest/Routes.php         anchor-library/v1
    Rest/LinkToken.php      signed link issue/verify
  includes/                 existing helper classes (Vimeo parse, Range, Media_Progress, Policy …) moved as-is
  assets/                   file-manager.js, user-manager.js, account-documents.js, CSS (same globals AnchorFM/AnchorAP)
```

Assets load from `ANCHOR_TOOLS_PLUGIN_URL`. The plugin's PUC updater is removed.

## What must stay identical

- **Database:**
  - table names and columns;
  - every `anchor_fm_*` option;
  - `_anchor_pd_docs`;
  - the cron hooks `anchor_fm_prune_resume` and `anchor_fm_storage_check`;
  - `anchor_fm_db_version` and its upgrade steps.

  Live is at 2.14.0. The module runs the 2.15.0 step (including its host-gated TMJ pins) and the 2.16.x steps on first load.
- **Storage:**
  - the `ANCHOR_FM_STORAGE_DIR` constant (TMJ: `wp-content/.anchor-private-files`);
  - the filters `anchor_fm_storage_dir` and `anchor_fm_allowed_upload_extensions`;
  - the dot-prefixed folder, mode 0700 and the guard.

  `docs/PRIVATE-STORAGE.md` moves with the module. The store stays inside the web root because of Kinsta's `open_basedir`.
- **Front end:**
  - the three shortcodes, so the theme's `shortcode_exists()` check stays true;
  - AJAX action names, nonce names and JS globals;
  - the `#afm-(file|video|folder|link)-<id>` deep links;
  - `/my-account/` behaviour.
- **Permissions:**
  - administrators get everything;
  - the folder owner can manage;
  - the best match is found walking up the folder tree;
  - a file row overrides its folder;
  - only admins can upload;
  - a Woo order grants view of product docs;
  - the Product Docs folder stays hidden from normal listings.

## REST API: `anchor-library/v1`

All routes are `GET` and follow the events-feed pattern:
- open `permission_callback`, with auth inside the callback;
- plain-text fields;
- a weak body-hash `ETag`;
- `Cache-Control: private, max-age=60`;
- `Vary: Authorization`.

Signed-out requests get `401 anchor_library_auth`. Who sees what is decided only by `Permissions`; the API never re-implements it.

| Route | Returns |
|---|---|
| `/folders/0` | Top-level folders the user can view (what the portal's Files tab shows at the root). |
| `/folders/{id}` | `{ folder, breadcrumbs[], folders[], files[], videos[], links[] }` for a folder the user can view. `403 anchor_library_forbidden` if not, `404 anchor_library_not_found` if it doesn't exist. |
| `/search?q=` | Matching folders, files, videos and links the user can view (same scope as the portal search). Min 2 chars. |
| `/files/{id}/link?disposition=inline\|attachment` | `{ url, expires_at }`. `url` points to `/files/{id}/content?t=<token>`. The view right is checked when the link is issued. |
| `/files/{id}/content?t=` | Streams the file with Range support. The token is verified and the view right is **re-checked** for the token's user. Logs `preview_file` or `download_file` to the activity table with source `app`. No Basic auth is needed, so in-app viewers and players can open it. |

Row shapes:
- **File:** `id, name, mime, size, updated_at`.
- **Video:** `id, title, thumbnail_url, embed_url`, where `embed_url` is `https://player.vimeo.com/video/{vimeo_id}?h={hash}`.
- **Link:** `id, title, url`.
- **Folder:** `id, name, parent_id, counts`.

Nothing personal goes out beyond the caller's own data. Uploader names are not included.

**Link token:** `base64url(user_id.file_id.disposition.exp).HMAC-SHA256(that, wp_salt('auth'))`.
- TTL is 10 minutes.
- The token is bound to the user and the file, so a leaked link expires quickly and only opens that one file.
- Verification uses `hash_equals`.
- The token never contains the Application Password.

## App side (separate plan, after the API lands)

- A **Library** tab is added to the tab list. Like the events app, it reads the API through the existing `apiGet`.
- **Folder screen:** breadcrumbs, then sub-folders, files, videos and links.
- **Search.**
- **File:** fetch a link, then open it. PDFs and images use the in-app browser; other types use the share sheet with "Open in…".
- **Video:** the Vimeo embed in a WebView.
- **Signed out:** "Sign in with your Centre account to see the library."
- **Cache:** the last listing of each folder is kept for offline browsing. Files are not cached, because that belongs to project 4.

## Cutover on TMJ (runbook, needs the user's OK at each live step)

1. Kinsta backup.
2. Deploy the Anchor Tools release containing the module. It stays off by default, and the legacy guard keeps it inert while the plugin is active.
3. Deactivate Anchor Private File Manager, then enable the Library module.
4. Smoke test:
   - `/my-account/` Files tab as a Centre and as an admin;
   - open and download a file;
   - play a video;
   - the Users tab;
   - Settings;
   - the storage self-test is green;
   - the activity log is still writing;
   - `anchor_fm_db_version` is now 2.17.0.
5. **Rollback:** disable the module and reactivate the plugin. The data is the same tables, so nothing needs restoring. The one exception is that the 2.15/2.16 upgrade steps will already have run, and plugin 2.16.2+ expects that anyway.

## Testing

- PHPUnit in Anchor Tools, group `library`:
  - port `tests/run.php` (the pure helpers);
  - add Permissions tests: admin, owner, inherited, file override, product grant, hidden Product Docs;
  - the upgrader from a 2.14.0 fixture;
  - the legacy guard;
  - every REST route (401, 403, 404, shapes, ETag/304);
  - the link token: expiry, tamper, wrong user, permission revoked after issue;
  - Range streaming.
- Existing JS tests move with the assets.
- Local TMJ copy (DevKinsta): run the cutover runbook end to end before live.

## Out of scope

| Item | Where |
|---|---|
| Offline video and file downloads in the app | Roadmap project 4 |
| One shared Vimeo client and token (webinars + library) | Project 4, when the token is first used |
| Uploading or managing files from the app | Not planned (upload stays admin, on the site) |
| Watch-progress sync from the app | Later; `video_views` already exists |
| Moving the Google API key out of `anchor_schema_settings` | Separate hygiene task (found during research) |
