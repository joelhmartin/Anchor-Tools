# Webinar Access Gating — Design

**Date:** 2026-05-29
**Module:** `anchor-webinars/` (`\Anchor\Webinars\Module`)
**Status:** Approved design, pending implementation plan

## Problem

Every single-webinar page currently calls `auth_redirect()` in `template_include()`
(`anchor-webinars/anchor-webinars.php:366`). Any logged-out visitor is bounced to the
default WordPress login screen (`wp-login.php`). There is no way to make a webinar
public, no per-webinar control, and the login experience is the bare wp-admin form.

## Goals

1. **Public by default.** Remove the blanket `auth_redirect()`. Unconfigured/existing
   webinars become publicly viewable immediately.
2. **Per-webinar, opt-in gating** with three modes: Public, Logged-in users, Specific roles.
3. **Never redirect to `wp-login.php`.** Gated, logged-out visitors see a polished inline
   login form *on the webinar page itself*.
4. **Inline AJAX login** that shows errors in place and, on success, reloads the same URL
   (no redirect anywhere else).
5. **Quick Edit and Bulk Edit** support for setting access mode + roles, with a
   multi-select role checklist (modeled on how categories work in the inline editor).

## Non-Goals

- Per-user (individual) gating — roles only.
- Drip/scheduled access, paywalls, or membership-plugin integration.
- Changing the archive page behavior beyond what the access model implies.

## Access Model

Stored in post meta on the `anchor_webinar` CPT:

| Meta key | Values | Notes |
|----------|--------|-------|
| `_anchor_webinar_access` | `public` \| `login` \| `roles` | Empty/unset is treated as `public`. |
| `_anchor_webinar_roles` | array of role slugs | Only consulted when mode is `roles`. |

### Single source of truth

```
can_user_access( int $post_id, ?int $user_id = null ) : bool
```

- Resolves `$user_id` to the current user when null.
- **Editor bypass:** if the user can `edit_post( $post_id )`, return `true` (so admins/
  editors are never locked out and preview works).
- `public` → `true`.
- `login` → `is_user_logged_in()`.
- `roles` → logged in AND the user has at least one slug in `_anchor_webinar_roles`.
  An empty role list in `roles` mode falls back to "any logged-in user" (treated like `login`).

This helper is used by: the single template gate, the frontend asset enqueue, and the
watch-log AJAX handler.

## Frontend Behavior

### Remove the redirect

`template_include()` no longer calls `auth_redirect()`. It still returns the
`single-webinar.php` template for single views and `archive-webinar.php` for archives.

### Gate rendering (in the single template / a render helper)

The template asks the module which of three states to render in place of the player:

1. **Access granted** → render the Vimeo player exactly as today (player JS + watch-logging).
2. **Gated + logged out** → render the inline login card (see below). The Vimeo ID and
   player script are **not** output, so the video is not reachable via view-source.
3. **Gated + logged in, wrong role** → render a clean "You don't have access to this
   webinar" notice (no login form — they are already authenticated). Copy may invite them
   to contact the site owner. No Vimeo ID / player output.

A small helper such as `render_access_gate( $post_id )` returns the correct HTML, keeping
the template thin.

### Asset enqueue changes (`frontend_assets`)

Current code bails entirely when the visitor is not logged in (line 341). New logic:

- On a single webinar page, branch on `can_user_access()`:
  - **Granted** → enqueue Vimeo player + `player.js` + localized `ANCHOR_WEBINAR`
    (unchanged from today).
  - **Gated + logged out** → enqueue the new login form CSS/JS and localize a separate
    object (`ANCHOR_WEBINAR_LOGIN`) with `ajaxUrl` and the login nonce. Do **not** enqueue
    the Vimeo player.
  - **Gated + wrong role** → frontend CSS only (for the notice styling).
- The base `frontend.css` continues to load on singular + archive as today; login form
  styles can live in `frontend.css` or a dedicated stylesheet (implementer's choice —
  prefer extending `frontend.css` to avoid an extra request).

## Inline Login Form

A product-quality login card rendered where the video would be. Contents:

- Heading + short subtext (e.g. "Sign in to watch this webinar").
- Username/email field.
- Password field.
- **Remember me** checkbox.
- Submit button.
- Footer links: **Lost your password?** (always) and **Register** (only when
  `get_option('users_can_register')` is true, via `wp_registration_url()`).
- An inline error/status region (hidden until needed).

Styled in CSS to look like a real login UI (card, spacing, focus states, button states) —
not a default wp-admin form.

### AJAX login flow

- New action: `wp_ajax_nopriv_anchor_webinar_login` (and `wp_ajax_` for parity / safety).
- Handler:
  1. Verify nonce (`check_ajax_referer`).
  2. Read `log`, `pwd`, `rememberme`, and the target `redirect`/`webinar_id`.
  3. Call `wp_signon()` with the credentials and remember flag.
  4. On `WP_Error` → `wp_send_json_error` with a user-safe message (mapped from the
     error code — bad username/password collapse to a single generic message to avoid
     username enumeration).
  5. On success → `wp_send_json_success` (optionally returning the current permalink).
- JS:
  - Submit via AJAX; on error, render the message inline and keep the form populated.
  - On success, `window.location.reload()` of the **same** webinar URL. The reloaded page
    sees the auth cookie, `can_user_access()` now passes, and the real player renders with
    watch-logging wired up.

**Rationale for self-reload:** revealing the video live without a reload would require
shipping the player script + Vimeo ID to logged-out users (defeats the gate) and
re-bootstrapping watch-logging client-side. A single reload of the same URL is bulletproof,
leaks nothing, and still never navigates away from the page.

## Admin: Metabox

Extend the existing "Webinar Details" metabox (or add an "Access Control" section) with:

- A radio group: **Public** / **Logged-in users** / **Specific roles**.
- A role checklist (all `wp_roles()` roles) shown only when "Specific roles" is selected
  (JS show/hide). Pre-checked from `_anchor_webinar_roles`.

Saved in the existing `save_meta()` handler (which already verifies `self::NONCE`,
autosave, and `edit_post`). Sanitize: access mode against the allowed set; role slugs
against `array_keys( wp_roles()->roles )`.

## Admin: List Column

- Add an **Access** column via `manage_anchor_webinar_posts_columns` /
  `manage_anchor_webinar_posts_custom_column`.
- Renders a human-readable summary ("Public", "Logged-in", "Roles: Subscriber, Editor").
- Also outputs the raw access mode + role slugs as hidden data (inline data block or data
  attributes) so the Quick Edit JS can pre-populate fields — the same pattern WordPress
  uses to feed Quick Edit.

## Admin: Quick Edit & Bulk Edit

Both use `quick_edit_custom_box` / `bulk_edit_custom_box` (hooked for the `anchor_webinar`
screen) to inject:

- **Quick Edit:** access mode select + role checklist (multi-select), pre-populated from
  the row's hidden data via JS (mirrors core's category handling).
- **Bulk Edit:** access mode select with a **"— No Change —"** default + the same role
  checklist. Applies to all selected webinars.

### Inline save path (separate from the metabox save)

Inline edits do **not** send `self::NONCE`; they use WordPress's `_inline_edit` nonce and
save through `inline-save` (quick edit, AJAX) and `bulk_edit` (bulk edit). Both fire
`save_post_anchor_webinar` per post.

A dedicated branch/handler on `save_post_anchor_webinar`:
1. Detects inline context (`$_REQUEST['action'] === 'inline-save'` for quick edit, or
   `isset($_REQUEST['bulk_edit'])` for bulk edit).
2. Verifies the `_inline_edit` nonce and `current_user_can('edit_post', $post_id)`.
3. For **bulk edit**, treats a `— No Change —` access value as "skip" so unselected fields
   aren't clobbered.
4. Writes the same sanitized `_anchor_webinar_access` / `_anchor_webinar_roles` meta as the
   metabox path.

This keeps the metabox save and the inline save as two clearly separated, correctly-nonced
code paths writing to the same meta via a shared private writer (e.g.
`persist_access_meta( $post_id, $mode, $roles )`).

A small admin JS file (enqueued on the webinar list screen only) handles: showing/hiding the
role checklist based on the selected mode, and populating Quick Edit fields from the row's
hidden data.

## Watch-Log Hardening

`handle_watch_log()` already checks `is_user_logged_in()`. Add a `can_user_access()` check
so a logged-in user without the required role cannot log views against a gated webinar.

## Security Notes

- Vimeo ID + player assets are never emitted to users who lack access.
- Login errors are generic (no username enumeration).
- All meta writes are capability- and nonce-checked on every path (metabox, quick edit,
  bulk edit).
- Role slugs and access modes are validated against allow-lists before saving.

## Files Touched (anticipated)

- `anchor-webinars/anchor-webinars.php` — access helper, gate rendering, enqueue branching,
  metabox fields, list column, quick/bulk edit boxes, inline + metabox save, login AJAX
  handler, watch-log access check, remove `auth_redirect()`.
- `anchor-webinars/templates/single-webinar.php` — call the gate helper instead of always
  rendering the player.
- `anchor-webinars/assets/frontend.css` — login card + access-denied notice styles.
- `anchor-webinars/assets/login.js` (new) — AJAX login submit + inline errors + reload.
- `anchor-webinars/assets/admin-list.js` (new) — quick/bulk edit field logic + role toggle.
- (Optional) bump asset version strings per project convention.

## Testing (manual — no automated suite)

- Logged-out visitor on a **public** webinar → sees the video, no login prompt.
- Logged-out visitor on a **login** or **roles** webinar → sees the inline login card,
  never wp-login.php.
- Wrong password → inline error, form stays, no navigation.
- Correct login → page reloads to the same webinar, video plays.
- Logged-in user without the required role → access-denied notice, no video, no login form.
- Admin/editor → always sees the video regardless of mode.
- Metabox save, Quick Edit, and Bulk Edit each correctly set mode + roles; Bulk Edit
  "— No Change —" leaves existing values intact.
- Access column reflects the current setting.
- Watch logs are not recorded for users without access.
