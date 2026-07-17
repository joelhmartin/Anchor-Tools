# Anchor Locations — Phase 7: Search Console + GA4 Reporting (Plan)

Spec: `docs/superpowers/specs/2026-07-18-anchor-locations-p7-analytics-design.md`
Branch: `feature/anchor-locations-p7-analytics`

TDD throughout; HTTP mocked via `pre_http_request`. Commit in logical chunks.

## Task 1 — Config + dormancy (RED→GREEN)

- New file `anchor-locations/class-analytics.php`, class `\Anchor\Locations\Analytics`.
- `OPTION = 'anchor_locations_analytics'`, `NONCE`, scope constants, cache keys.
- `settings()`, `is_configured()`, `save_config()` (parse pasted JSON key, preserve
  existing private key when textarea blank, `update_option(..., false)`).
- Wire `new Analytics()` into `Module::__construct`.
- Tests: `is_configured()` false unset + **no HTTP**; save round-trips with
  `autoload=false`; private key never rendered in the config form HTML.

## Task 2 — JWT + token exchange (RED→GREEN)

- `base64url()` helper, `build_jwt($scopes)`, `get_access_token($scopes)` with
  transient caching keyed by scope hash, TTL = `expires_in - 60`.
- Tests: in-test RSA key + `pre_http_request` stub → returns `'x'`, 2nd call no
  re-request, JWT has 3 segments decoding to `alg:RS256`/`iss`/`aud`/`scope`.

## Task 3 — Fetch + normalize + cache (RED→GREEN)

- Pure `normalize_gsc()` / `normalize_ga4()`.
- `fetch_gsc()` / `fetch_ga4()` (token → POST → normalize → cache), `metrics()`
  (cached merged map), `metrics_for($url)`, `refresh()`.
- Tests: normalizers map sample JSON; fetch populates cache; 500/WP_Error ⇒
  WP_Error + no cache poisoning; `metrics_for()` matches by path.

## Task 4 — Surface (report page + optional matrix column)

- `register_pages()` → "Analytics" submenu under Anchor Locations.
- `render_page()`: configure notice when dormant; else table of locations +
  service pages with metrics, opportunity + zero-traffic flags, Refresh button.
- `handle_refresh()` admin-post handler (`manage_options` + nonce).
- Pure `report_rows()` builder (list of pages + metrics + flags) is unit-tested;
  rendering is thin.

## Task 5 — Regression + docs

- `composer test -- --filter Locations` all green.
- `php -l` the new file.
- Report → `.superpowers/sdd/p7-report.md`.
