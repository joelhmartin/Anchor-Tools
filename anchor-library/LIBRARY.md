# Anchor Library

Module key `library` (disabled by default), directory `anchor-library/`, namespace `Anchor\Library\`. A port of the Anchor Private File Manager 2.17.0: same tables, options, shortcodes and AJAX actions. This file documents the REST API; Task 7 adds the cutover and rollback notes.

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

- Token = `base64url("user_id.file_id.disposition.exp")` + `"."` + hex `HMAC-SHA256(that encoded payload, wp_salt('auth'))`, compared with `hash_equals`. TTL 600 s. It is bound to one user and one file, and carries no password.
- `/content` is authenticated by the token alone. It ignores the current user, cookies and nonces, so a browser session with no token is a `401`. The app opens the URL as is, with no Basic auth header, so in-app viewers and players work.
- The view right is **re-checked** for the token's user on every request: a grant removed inside the 10 minutes gives `403`.
- Range requests give `206` with a correct `Content-Range` / `Content-Length` and stream in 256 KB chunks (`Storage::plan` / `Storage::stream`); an unsatisfiable range is `416`.
- `Content-Disposition` follows the `disposition` the link was issued with.
- Each read is logged to the activity table (`preview_file` for inline, `download_file` for attachment) with `meta` `{"source":"app"}` and the token's user as actor. As on the portal, an inline preview logs only its opening range; a download is always logged.

### Auth and visibility

Application Passwords sent as HTTP Basic over HTTPS; core resolves the user before the route runs. Nothing reads a nonce, and a cookie without a nonce is no user at all. Signed out is `401 anchor_library_auth`; the library has no public content. A Centre user sees only the folders granted to their role or account, and the descendants those grants inherit. The Product Docs container and users' private folders are not addressable (`404`). Application Passwords must be available on the site (`wp_is_application_passwords_available()`).

### Caching

`/folders/*` and `/search` carry a weak body-hash `ETag`, `Cache-Control: private, max-age=60` and `Vary: Authorization` (also on the `304`); a matching `If-None-Match` returns `304` with no body. These routes opt out of core's `rest_send_nocache_headers`, which would overwrite it for any signed-in caller. `/link` is `no-store` (every call mints a new token). `/content` is not cached.

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
