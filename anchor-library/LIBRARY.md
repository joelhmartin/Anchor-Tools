# Anchor Library

Module key `library` (disabled by default), directory `anchor-library/`, namespace `Anchor\Library\`. A port of the Anchor Private File Manager 2.17.0: same tables, options, shortcodes and AJAX actions. This file is the module overview, the REST reference for the member app and the cutover and rollback runbook for the first site (TMJ). Spec: `docs/superpowers/specs/2026-10-18-library-module-design.md`.

## Overview

- **What it is:** the Anchor Private File Manager (plugin 2.17.0) folded into Anchor Tools. Portal shortcodes `[anchor_file_manager]`, `[anchor_account_portal]`, `[anchor_documents_portal]`, 51 `anchor_fm_*` / `anchor_ap_*` / `anchor_pd_*` AJAX actions, the admin Users tab and Settings, activity log, storage self-test, Product Docs (AJAX only), plus the read-only REST API below.
- **Data is shared with the plugin, not copied:** the same `{prefix}anchor_fm_*` tables, `anchor_fm_*` options (`anchor_fm_db_version`), `_anchor_pd_docs` meta, cron hooks (`anchor_fm_prune_resume`, `anchor_fm_storage_check`), JS globals (`AnchorFM`, `AnchorAP`) and the private storage dir (`ANCHOR_FM_STORAGE_DIR`, see `PRIVATE-STORAGE.md`).
- **Off by default.** Enable it in Anchor Tools Settings > Modules, or set `anchor_schema_settings['modules']['library']` to a truthy value.
- **Legacy guard:** while the class `Anchor_Private_File_Manager` exists (the plugin is active) the module loads nothing but an admin notice ("Anchor Library is waiting: deactivate the Anchor Private File Manager plugin..."). No upgrade, no cron, no hooks. The ported global helper classes are only required when not already declared, so having both on disk never fatals. Never run both.
- **Code map:** `src/Module.php` (guard and wiring), `Schema` (tables and the `maybe_upgrade_db()` version gates), `Permissions` / `Repository` / `Activity`, the AJAX handlers in `Uploads`, `Copier`, `Users`, `Settings`, `Portal`, `Storage` / `StorageGuard` / `StorageMonitor`, `Rest/*`. The classes in `includes/` are the legacy global helpers moved as-is. `assets/file-manager.js` and `.css` are copied verbatim from the plugin (over the 1,000-line house limit on purpose: parity first; splitting is a follow-up).
- **Autoload:** the module's PSR-4 line is in the committed `vendor/composer/autoload_psr4.php` and `autoload_static.php` (production builds use the committed vendor).
- **Text domain:** strings moved from `anchor-private-file-manager` to `anchor-schema`. Any site translation files for the old domain no longer apply.
- **Vimeo:** the module does not read the plugin's `.env` (`VIMEO_ACCESS_TOKEN`) any more. The portal and API use only the stored `vimeo_id` / `vimeo_hash` embeds. Nothing in this project calls the Vimeo API.

## Cutover and rollback runbook (TMJ)

Each live step needs the owner's explicit OK. Rehearse on the local DevKinsta copy first.

### Pre-cutover checks (read-only)

1. Kinsta backup of the site (files and database).
2. Is there a `.env` in the old plugin directory (`wp-content/plugins/anchor-private-file-manager/.env`) holding `VIMEO_ACCESS_TOKEN`? The plugin read it; the module does not. If one exists, nothing breaks (embeds do not need it), but note that the token is no longer used, and that anything else that depended on it (admin Vimeo lookups) is gone. Do not copy it anywhere.
3. Note the current `anchor_fm_db_version` (expected 2.14.0 on TMJ) and that the storage dir (`ANCHOR_FM_STORAGE_DIR`) is defined in `wp-config.php`.
4. REST only: Application Passwords must be available (`wp eval 'var_dump(wp_is_application_passwords_available());'`). Wordfence "Disable WordPress application passwords" blocks them.

### Cutover

1. Deploy the Anchor Tools release containing the module. It stays off. While the plugin is active the module is inert even if switched on.
2. Enable the Library module (Settings > Modules). The legacy notice appears. Nothing else changes.
3. Deactivate Anchor Private File Manager. Order matters: the module is already enabled, so the guard lifts on the next request and the module boots, upgrading the schema from 2.14.0 to 2.17.0.
4. Smoke test: `/my-account/` Files tab as a Centre user and as an admin (open, download, play a video), the Users tab, Settings, the storage self-test is green, the activity log still writes, `anchor_fm_db_version` is 2.17.0.
5. Optional: the REST checks in the section below with a test Application Password (delete it afterwards).

### Rollback

Order matters. **Disable the Library module first, then reactivate the plugin.** Activating the plugin while the module is loaded would redeclare classes; the activation is refused (sandboxed fatal), which is safe but confusing. The data is the same tables, so nothing needs restoring. The 2.15 to 2.17 upgrade steps stay applied and the plugin accepts that. Reactivating the 2.14.0 plugin re-stamps `anchor_fm_db_version` to 2.14.0; a second cutover then re-runs the upgrade (2.14.0 to 2.17.0), which is safe to repeat: the host pins only fill unset options and the watch-stats reset only applies below 2.12.0. Rehearsed on the local TMJ copy: with the module still enabled, activating the plugin is refused (it stays inactive); with the module disabled first, it activates cleanly.

## REST API (member app)

A read-only API for the member phone app, namespace `anchor-library/v1` (`Rest\Routes::NS`), served by `src/Rest/Routes.php`. The app mirrors the site, so the API decides nothing of its own: who may see what is `Permissions`, what is in a folder is `Repository`, and `Rest\Shapes` only maps rows. All routes are `GET`.

### Endpoints

| Route | Returns |
|---|---|
| `GET /wp-json/anchor-library/v1/folders/0` | The root: `folders` holds the top-level folders the caller can view |
| `GET /wp-json/anchor-library/v1/folders/{id}` | `{ folder, breadcrumbs, folders, files, videos, links }` for a folder the caller can view |
| `GET /wp-json/anchor-library/v1/search?q=` | `{ q, folders, files, videos, links, truncated }`, `q` at least 2 characters |
| `GET /wp-json/anchor-library/v1/files/{id}/link?disposition=inline\|attachment` | `{ url, expires_at }` (default `attachment`) |
| `GET /wp-json/anchor-library/v1/files/{id}/content?t=<token>` | The file bytes, with Range support |

### Folder response

```json
{
  "folder": { "id": 12, "name": "Training", "parent_id": 3, "counts": { "folders": 1, "files": 4, "videos": 2, "links": 1 } },
  "breadcrumbs": [ { "id": 3, "name": "Centre resources" }, { "id": 12, "name": "Training" } ],
  "folders": [ { "id": 20, "name": "2026", "parent_id": 12, "counts": { "folders": 0, "files": 3, "videos": 0, "links": 0 } } ],
  "files":   [ { "id": 77, "name": "Guide.pdf", "mime": "application/pdf", "size": 183004, "updated_at": "2026-01-02T03:04:05-06:00" } ],
  "videos":  [ { "id": 5, "title": "Intro", "thumbnail_url": "https://i.vimeocdn.com/…", "embed_url": "https://player.vimeo.com/video/987654?h=abc123" } ],
  "links":   [ { "id": 9, "title": "Portal", "url": "https://example.com/" } ]
}
```

- `/folders/0` has `folder` = `{ "id": 0, "name": "", "parent_id": 0, "counts": … }`, empty `breadcrumbs`, and empty `files`, `videos`, `links` (the root holds folders only).
- `counts` is what the caller can see directly inside that folder, not totals.
- `updated_at` is the file's upload time (files are never edited), ISO 8601 with the site's offset. The files table has no separate update column.
- `thumbnail_url` is `null` when the video has none. `embed_url` omits `?h=` when the video has no privacy hash. The app opens it in a WebView.
- Uploaded video files (mp4 and so on) are `files` rows. Play them through `/link` and `/content` like any file.
- No uploader or creator names or ids, owner ids or e-mail addresses are ever returned.

### Search rows

Each hit adds `folder_id` (its folder) and `path` (that folder's names joined with " / ", for display):

| Kind | Keys |
|---|---|
| `folders[]` | `id, name, parent_id, path` |
| `files[]` | `id, name, mime, size, folder_id, path` |
| `videos[]` | `id, title, thumbnail_url, embed_url, folder_id, path` |
| `links[]` | `id, title, url, folder_id, path` |

`truncated` is true when a kind hit the 200-row cap. Product Docs content is never listed.

### Signed file links

`/link` checks the caller's view right and returns a URL to `/content` carrying a token. `expires_at` is UTC, 10 minutes ahead.

- Token = `base64url("user_id.file_id.disposition.exp")` + `"."` + hex `HMAC-SHA256("anchor-library-link|" . that encoded payload, wp_salt('auth'))` (domain-separated, so a MAC minted elsewhere with the same salt never verifies), compared with `hash_equals`. TTL 600 s. It is bound to one user and one file, and carries no password.
- `/content` is authenticated by the token alone. It ignores the current user, cookies and nonces, so a browser session with no token is a `401`. The app opens the URL as is, with no Basic auth header, so in-app viewers and players work.
- The view right is **re-checked** for the token's user on every request: a grant removed inside the 10 minutes gives `403`.
- Range requests give `206` with a correct `Content-Range` / `Content-Length` and stream in 256 KB chunks (`Storage::plan` / `Storage::stream`); an unsatisfiable range is `416`, which is not logged (no bytes were delivered).
- `/content` is GET only: `HEAD` gets `405` (it would otherwise stream and log with the body thrown away); other methods match no route, so core answers `404 rest_no_route`.
- Response headers on `/content` (`Storage::response_headers( ..., $strict = true )`, asserted in `tests/test-library-delivery.php`):
  - `Cache-Control: private, no-store` and `Referrer-Policy: no-referrer`, always, set explicitly. Core's `nocache_headers()` leaves `no-store` out for signed-out requests before WordPress 6.8, and `/content` is a signed-out request by design. `X-Content-Type-Options: nosniff` and `Accept-Ranges: bytes` as well.
  - `Content-Disposition` follows the `disposition` the link was issued with, **except** that `inline` is honoured only for PDF, `image/png|jpeg|gif|webp`, `video/*`, `audio/*`, `text/plain` and `text/csv`. Anything else (SVG, HTML, XML, unknown) is sent as `attachment`.
  - An inline response also carries `Content-Security-Policy: sandbox; default-src 'none'; img-src data:; media-src 'self'; style-src 'unsafe-inline'`, except an inline `application/pdf`: a sandbox CSP stops Chrome's built-in PDF viewer, so PDFs get no CSP (the safe-type list already excludes scriptable types, and `nosniff` stays).
  - The name goes out as an ASCII `filename="..."` fallback plus `filename*=UTF-8''<percent-encoded>` (`Http::content_disposition()`, shared with the AJAX stream and the folder zip).
  - The portal's AJAX stream (`anchor_fm_stream`) keeps its existing inline behaviour: its allowed upload types include some (bmp, tif, ...) outside the safe set, so applying it there would turn working previews into downloads. It does get the shared `Content-Disposition` helper.
- Each read is logged to the activity table (`preview_file` for inline, `download_file` for attachment, by the disposition actually served: an `inline` request for a type outside the safe set is a download and is logged as one) with `meta` `{"source":"app"}` and the token's user as actor. As on the portal, an inline preview logs only its opening range; a download is always logged.

### Auth and visibility

Application Passwords sent as HTTP Basic over HTTPS; core resolves the user before the route runs. Nothing reads a nonce, and a cookie without a nonce is no user at all. Signed out is `401 anchor_library_auth`; the library has no public content. A Centre user sees only the folders granted to their role or account, and the descendants those grants inherit. The Product Docs container and users' private folders are not addressable (`404`). Application Passwords must be available on the site (`wp_is_application_passwords_available()`).

### Caching

`/folders/*` and `/search` carry a weak body-hash `ETag`, `Cache-Control: private, max-age=60` and `Vary: Authorization` (also on the `304`); a matching `If-None-Match` returns `304` with no body. These routes opt out of core's `rest_send_nocache_headers`, which would overwrite it for any signed-in caller. `/link` is `no-store` (every call mints a new token). `/content` is `private, no-store`. Every error response in the namespace (400/401/403/404/405) carries `Cache-Control: no-store` (`Routes::no_store_errors()`).

### Errors

| Case | Response |
|---|---|
| Signed out on `/folders`, `/search`, `/link` | `401 anchor_library_auth` |
| `/content` with no token, a forged, expired or other-file token | `401 anchor_library_auth` |
| Folder or file the caller (or the token's user) cannot view | `403 anchor_library_forbidden` |
| Folder or file missing, hidden folder, or bytes missing on disk | `404 anchor_library_not_found` |
| `q` under 2 characters | `400 anchor_library_query` |
| `disposition` not `inline` or `attachment` | `400 rest_invalid_param` (core) |
| Bad or revoked Application Password | `401` from core |

The `403` body never names the folder or its ancestors: the folder is gated before any breadcrumb is read.

### Reuse

`Rest\Routes::respond()` and `filter_nocache_headers()` are twins of `Anchor\Events\Feed::respond()` / `filter_nocache_headers()` (branch `feat/events-feed-api`). When both are on main, fold them into one shared helper. Don't add a second permission calculation to the API: call `Permissions` with the explicit user id.
