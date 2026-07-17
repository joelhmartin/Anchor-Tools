# Anchor Locations — Phase 7: Search Console + GA4 Reporting (Design Spec)

Date: 2026-07-18
Branch: `feature/anchor-locations-p7-analytics`
Module: `anchor-locations` (`\Anchor\Locations\`)

## Goal

Pull per-page Google Search Console (GSC) + GA4 metrics for the module's location
and service pages and surface them in an admin-only "Analytics" report. Auth is
server-to-server via a Google **service account** — the admin pastes the JSON key.
No interactive OAuth. Everything must degrade gracefully when unconfigured.

## Hard constraints

- **No heavy composer deps.** JWT built + signed with `openssl_sign` (RS256).
  HTTP via `wp_remote_post` / `wp_remote_get`. JSON via core `json_encode/decode`.
- **Service-account auth only.** JWT-bearer grant against
  `https://oauth2.googleapis.com/token`.
- **Graceful dormancy.** No credentials ⇒ `is_configured()` returns false, the
  report page shows a "configure" notice, and **no HTTP is ever attempted**.
- **Sensitive key handling.** The private key is stored in an option with
  `autoload=false`, capability-gated (`manage_options`), never echoed back into
  the config form (show "configured ✓"), and never emitted on the front end.
- **Testability.** Live API calls are not exercisable here (no creds). All
  network-independent logic is unit-tested; HTTP is mocked via the
  `pre_http_request` filter. Live verification is documented as a follow-up.

## New file / class

`anchor-locations/class-analytics.php` → class `\Anchor\Locations\Analytics`,
instantiated from `Module::__construct` via `require_once` + `new Analytics()`.

## A. Config

- Admin subpage under **Anchor Locations** ("Analytics"), `manage_options` + nonce.
- Fields: service-account JSON key (textarea), GSC site
  (`sc-domain:example.com` or a URL-prefix like `https://example.com/`),
  GA4 property id (numeric).
- Parsed + stored in option `anchor_locations_analytics` (`autoload=false`):
  `{ client_email, private_key, token_uri, gsc_site, ga4_property, key_present }`.
- On save, the pasted JSON is parsed; `client_email` + `private_key` extracted.
  If the textarea is left blank on re-save, the existing key is preserved (so the
  admin never has to re-paste to change the site/property).
- `is_configured(): bool` = has client_email + private_key + at least one of
  (gsc_site, ga4_property).
- The private key is never rendered back into the form; a "configured ✓" note is
  shown instead.

## B. Auth — `get_access_token(array $scopes)`

1. Build header `{"alg":"RS256","typ":"JWT"}` and claims
   `{ iss: client_email, scope: <space-joined>, aud: token_uri, iat, exp: iat+3600 }`.
2. `$signing_input = base64url(header) . "." . base64url(claims)`.
3. `openssl_sign($signing_input, $sig, $private_key, OPENSSL_ALGO_SHA256)`.
4. `$jwt = $signing_input . "." . base64url($sig)`.
5. POST `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=<jwt>`
   (form-encoded) to `token_uri`.
6. Return `access_token`; cache it in a transient keyed by a hash of the scopes,
   with a TTL of `expires_in - 60s` (default 3600 ⇒ ~59 min).
7. Any failure (no creds, openssl failure, WP_Error, non-200, missing token) ⇒
   `WP_Error`, never fatal.

Scopes:
- GSC: `https://www.googleapis.com/auth/webmasters.readonly`
- GA4: `https://www.googleapis.com/auth/analytics.readonly`

## C. Fetch + normalize

- `fetch_gsc($start, $end)`: POST Search Analytics
  `https://searchconsole.googleapis.com/webmasters/v3/sites/{urlenc site}/searchAnalytics/query`
  with body `{ startDate, endDate, dimensions:['page'], rowLimit:25000 }`.
  Pipe the decoded body through `normalize_gsc()`.
- `normalize_gsc(array): array` (pure) ⇒ `[ path => { clicks, impressions, ctr, position } ]`
  keyed by the URL **path** (`wp_parse_url($row.keys[0], PHP_URL_PATH)`).
- `fetch_ga4($start, $end)`: POST
  `https://analyticsdata.googleapis.com/v1beta/properties/{id}:runReport`
  with body `{ dateRanges:[{startDate,endDate}], dimensions:[{name:'pagePath'}],
  metrics:[{name:'sessions'},{name:'conversions'}], limit:100000 }`.
  Pipe through `normalize_ga4()`.
- `normalize_ga4(array): array` (pure) ⇒ `[ path => { sessions, conversions } ]`.
- Both normalized maps are cached in a transient (default 12h). `refresh()`
  re-fetches both and overwrites the cache. A fetch that returns `WP_Error`
  leaves the existing cache untouched (no cache poisoning) and surfaces an admin
  notice.
- `metrics_for($url): array` merges GSC + GA4 by path
  (`{ clicks, impressions, ctr, position, sessions, conversions }`, missing ⇒ 0).

## D. Surface

- An "Analytics" report page listing published locations + service pages with
  clicks / impressions / avg position / CTR / sessions from `metrics_for(...)`.
- Flags: **opportunity** (impressions above a floor but avg position poorer than
  ~10) and **zero-traffic** (no clicks and no sessions).
- Refresh button (`manage_options` + nonce) triggers `refresh()`.
- Optional: a metrics column on the P5 coverage matrix, only when
  `is_configured()`. (Deferred unless cheap; the standalone report is the
  primary surface.)

## Conventions (binding)

- Namespace `\Anchor\Locations\`, text domain `anchor-schema`, meta prefix `al_`.
- Option `anchor_locations_analytics`, `autoload=false`.
- `manage_options` + nonce on config + refresh.
- Escape all admin output. Never print the private key. Admin-only — no
  front-end analytics output.

## Testing strategy

- `is_configured()` false when unset **and** asserts no HTTP attempted (a
  `pre_http_request` that fails the test if it fires).
- `normalize_gsc()` / `normalize_ga4()` map sample API JSON to per-path metrics.
- `get_access_token()` with an in-test RSA key (`openssl_pkey_new`) + a
  `pre_http_request` stub returning `{access_token:'x',expires_in:3600}` returns
  `'x'`, caches (2nd call no re-request), and the JWT decodes to 3 segments with
  `alg:RS256` / `iss` / `aud` / `scope`.
- `fetch_gsc()` / `fetch_ga4()` populate cache from canned bodies; a 500 / WP_Error
  yields WP_Error and does not poison the cache.
- `metrics_for($url)` matches by path.
- Config save round-trips with `autoload=false`; private key not echoed into form.

## Live-verification follow-up (cannot be tested here)

Live GSC/GA4 calls require the owner's real service-account key plus that service
account being granted access in Search Console (as a user of the property) and in
GA4 (Viewer on the property). Document that the token exchange, the two report
endpoints, and the date-range plumbing need one manual run with real creds.
