# Coverage Audit Ledger — DSAR / Settings / Geo slice (anchor-compliance)

Auditor: AUDIT agent D (DSAR slice). Read-only. Files audited:

- `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/anchor-compliance/includes/class-dsar.php` (625 lines)
- `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/anchor-compliance/includes/class-settings.php` (955 lines)
- `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/anchor-compliance/includes/class-geo.php` (191 lines)
- `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/anchor-compliance/assets/admin.js`, `admin.css`
- `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/tests/test-compliance-dsar.php`, `test-compliance-settings.php`, `test-compliance-settings-ui.php`, `test-compliance-geo.php`
- Wiring context: `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/anchor-compliance/anchor-compliance.php`; registry `all()` and consent-log `export_csv()` skimmed for cross-checks.

## Brief challenge / premise verification

1. **The three recent fixes are real and sound.**
   - *Rate-limit case bypass*: key is `hash('sha256', strtolower($email).'|'.$raw_ip.wp_salt('auth'))` (class-dsar.php:146); test `test_rate_limit_blocks_a_case_varied_email` pins it. Sound.
   - *Nested-prepare %-in-email*: `where()` now returns `[$sql, $args]` unprepared; `query()` does exactly one `$wpdb->prepare()` (class-dsar.php:250-279); test `test_query_and_export_handle_a_percent_sign_in_the_email` pins it. Sound.
   - *Salted rate-limit key*: `wp_salt('auth')` appended (class-dsar.php:146). Sound (missing separator between IP and salt is cosmetically sloppy but not a weakness).
2. **Brief inaccuracy (mild):** the brief asks me to census the admin queue's "each action/button/filter/pagination" — the queue has *no* filters, no pagination, no bulk actions, no per-row view; its only control is a per-row status `<select>`. The absent machinery is itself finding D011, not an audit of fiction.
3. The settings screen has **nine** sections (General, Regions, Appearance, Content, Services, Custom Rules, Consent Log, Privacy Requests, Advanced), not only the ones the brief names; all nine were audited (Consent Log internals belong to another slice; its *embedding*, filter form, and export handler are audited here).

## Lenses (L = 23)

1 Terminality · 2 Structure/Grain · 3 Organization · 4 Provenance→Consumption · 5 Comprehension · 6 State-Visibility · 7 Honesty · 8 Reversibility/Safety · 9 Idempotence/Accretion · 10 Failure/Recovery · 11 Precondition/Forward-path · 12 Population/Dark · 13 Sibling-Coherence · 14 Gating-Axis · 15 Temporal-Integrity · 16 Cost/Value · 17 Contract-Stability · 18 Naming/Least-astonishment · 19 Security · 20 Privacy/Data-retention · 21 Regulatory-correctness · 22 i18n · 23 Accessibility

## Census (M = 92 units)

### A. DSAR storage (class-dsar.php)
1. Table schema + install()/dbDelta (L46-73)
2. DB_VERSION option + maybe_install() gating (L18-19, 76-80; hooked on `admin_init` in anchor-compliance.php:91)
3. Column `id`
4. Column `created_at`
5. Column `type`
6. Column `email`
7. Column `name`
8. Column `details`
9. Column `status`
10. Column `deadline`
11. Column `ip_hash`
12. Column `notes`
13. Status value `new`
14. Status value `in_progress`
15. Status value `completed` (terminal)
16. Status value `rejected` (terminal)
17. Request type `access`
18. Request type `delete`
19. Request type `correct`
20. Request type `optout`

### B. Public intake
21. Shortcode `[anchor_privacy_request]` wrapper + disabled-state message (L313-370)
22. ok/error notice rendering from `$_GET['anchor_cmp']` (L320-328)
23. Nonce field + `wp_verify_nonce` (L332, 386)
24. Honeypot field + indistinguishable-ok path (L334-337, 394-396)
25. Email field (required)
26. Name field (optional)
27. Type radios (default `access`)
28. Details textarea
29. `handle_submit()` redirect flow (L411-419; hooked priv + nopriv, anchor-compliance.php:108-109)
30. `process_submission()` (L385-406)
31. `create()` validation + insert (L117-186)
32. Rate limiter (salted email+IP transient, 15 min) (L145-149, 179-181)
33. `hash_ip()` (truncate-then-salt) (L90-109)
34. `notify()` — site-owner email (L198-213)
35. `notify()` — requester confirmation email (L215-224)

### C. Admin queue
36. `register_menu()` + `manage_options` capability (L425-439)
37. Status-update POST handler on the queue page (L441-452)
38. Queue query — hardcoded `limit 200` (L454)
39. Queue table rendering — 7 display columns (L456-501)
40. Days-remaining / overdue red styling (L475, 493-495)
41. Empty state (L471-472)

### D. WP core privacy bridge
42. `register_exporter()` (L509-515)
43. `register_eraser()` (L517-523)
44. `privacy_export()` — own DSAR rows + core pagination contract (L539-566, 597-600)
45. `privacy_export()` — consent-ID scan of `details` → consent-log rows (L568-595)
46. `privacy_erase()` (L613-624)

### E. DSAR service API
47. `get()` (L227-230)
48. `query()`/`where()` — single-prepare contract (L250-279)
49. `set_status()` (L282-297)

### F. Settings plumbing (class-settings.php)
50. `defaults()` incl. dsar defaults (enabled, notify_email, response_days 45) (L22-93)
51. `get()` section merge (list vs map shaped) (L112-130)
52. `register_settings()` + sanitize dispatch (L132-138)
53. sanitize: general (L150-157)
54. sanitize: regions (L160-174)
55. sanitize: appearance (L177-189)
56. sanitize: content (plain-text vs kses split) (L199-217)
57. sanitize: services (L220-228)
58. sanitize: custom_rules (L231-244)
59. sanitize: log (L247-249)
60. sanitize: dsar (L252-255)
61. sanitize: advanced (L258-263)
62. `sanitize_category()` (L269-272)
63. `register_tab()` (L274-280)
64. `enqueue_assets()` (tab-scoped action; ANCHOR_TOOLS_PLUGIN_URL; source assets; VERSION) (L286-294)

### G. Settings UI
65. General section incl. page-picker dropdowns (L355-429)
66. Regions section — strict-country multi-select, unknown fallback, provider+token, client-relax (L433-515)
67. Geo readout + `geo_source_label()` (L441-462, 518-528)
68. Appearance section (colors, logo media frame, pill, radius) (L532-631)
69. Content section (auto-generated rows from defaults) (L635-661)
70. Services table (registry-driven; checkbox + category select per row) (L665-700)
71. Custom-rules repeater — PHP row markup + `__INDEX__` template (L704-745)
72. Consent-log settings + read-only viewer embed (L755-819)
73. Consent-log filter form + `consent_log_filters_from_request()` (L827-862)
74. Export-CSV form + `handle_export_log()` (cap + `check_admin_referer`) (L301-307, 863-867)
75. Privacy-requests settings section (L873-902)
76. Advanced section (L906-954)

### H. Geo (class-geo.php)
77. Header ladder — 5 headers in order (CF, CloudFront, Vercel, GEOIP, X-Geo) (L24-30, 50-60)
78. `normalize()` + NON_COUNTRIES placeholder rejection (L33, 76-85)
79. `lookup_via_api()` — provider URLs, /24 cache, failure cache (L92-126)
80. `client_ip()` — CF-Connecting-IP → X-Real-IP → REMOTE_ADDR → XFF (L128-148)
81. `ip_block()` — /24 v4, /64 v6 via inet_pton (L160-175)
82. `posture()`/`is_strict()` + unknown fallback (L178-190)
83. `country()`/`source()` memoization (L35-36, 43-74)

### I. Assets
84. admin.js — color pickers (L16-20)
85. admin.js — page pickers (L27-36)
86. admin.js — logo media frame (L39-78)
87. admin.js — custom-rules repeater add/remove (L86-107)
88. admin.css (all rules)

### J. Tests
89. tests/test-compliance-dsar.php (16 tests)
90. tests/test-compliance-settings.php (5 tests)
91. tests/test-compliance-settings-ui.php (6 tests)
92. tests/test-compliance-geo.php (13 tests)

## Units × Lenses matrix

Notation: each row lists directive-cells (Lens→Directive), the count of justified-n/a lenses, and the remainder as passes. Every one of the 23 lenses was applied to every unit; n/a cells are lenses with no purchase on that unit (e.g. Accessibility on a DB column, Terminality on a pure string helper) — spot-justifications follow the table where non-obvious.

| # | Unit | Directive-cells | n/a | pass |
|---|------|-----------------|-----|------|
| 1 | table schema/install | 17→D022 | 10 | 12 |
| 2 | DB_VERSION/maybe_install | 11→D005 | 12 | 10 |
| 3 | id | — | 15 | 8 |
| 4 | created_at | 5→D020 (raw UTC) | 14 | 8 |
| 5 | type col | 5→D020 (raw slug) | 14 | 8 |
| 6 | email col | 20→D001 | 13 | 9 |
| 7 | name col | 20→D001 | 14 | 8 |
| 8 | details col | 12→D002, 20→D001, 16→D004 (no length cap) | 12 | 8 |
| 9 | status col | — | 14 | 9 |
| 10 | deadline col | 21→D012 | 13 | 9 |
| 11 | ip_hash col | — (privacy pass: truncated+salted) | 13 | 10 |
| 12 | notes col | 12→D019 (dead column) | 14 | 8 |
| 13 | status `new` | 6→D014 | 16 | 6 |
| 14 | status `in_progress` | — | 16 | 7 |
| 15 | status `completed` | 1→D001 (terminal but never purged) | 16 | 6 |
| 16 | status `rejected` | 1→D001 | 16 | 6 |
| 17 | type `access` | 14→D003 | 15 | 7 |
| 18 | type `delete` | 14→D003 | 15 | 7 |
| 19 | type `correct` | 12→D002 (unactionable w/o details), 14→D003 | 15 | 6 |
| 20 | type `optout` | 8→D017, 14→D003 | 15 | 6 |
| 21 | shortcode/disabled msg | 14→D023 | 10 | 12 |
| 22 | ok/error notices | 7→D013 | 12 | 10 |
| 23 | nonce | 10→D015 (page-cache staleness) | 12 | 10 |
| 24 | honeypot | — (Honesty pass by design: bot must not learn) | 12 | 11 |
| 25 | email field | — | 13 | 10 |
| 26 | name field | — | 15 | 8 |
| 27 | type radios | — | 13 | 10 |
| 28 | details textarea | 16→D004 (no maxlength) | 13 | 9 |
| 29 | handle_submit | 7→D013 | 12 | 10 |
| 30 | process_submission | 14→D023 | 11 | 11 |
| 31 | create() | 11→D005 | 11 | 11 |
| 32 | rate limiter | 19→D004 | 12 | 10 |
| 33 | hash_ip | — | 13 | 10 |
| 34 | owner notify email | 19→D004 (mail-bomb), 6→D014 (sole alert channel) | 13 | 8 |
| 35 | requester confirm email | 14→D003 (backscatter to arbitrary email) | 13 | 9 |
| 36 | menu + capability | — | 13 | 10 |
| 37 | status-update POST | 7→D016 | 11 | 11 |
| 38 | queue query limit 200 | 3→D011 | 13 | 9 |
| 39 | queue table | 12→D002, 3→D011, 5→D020 | 10 | 10 |
| 40 | overdue indicator | 15→D014 | 14 | 8 |
| 41 | empty state | — | 16 | 7 |
| 42 | register_exporter | — | 14 | 9 |
| 43 | register_eraser | — | 14 | 9 |
| 44 | privacy_export rows | — (pagination contract pass; tested) | 10 | 13 |
| 45 | consent-ID scan | 14→D018 | 12 | 10 |
| 46 | privacy_erase | 8→D017 | 11 | 11 |
| 47 | get() | — | 15 | 8 |
| 48 | query()/where() | — (fix verified; tested incl. `%`) | 11 | 12 |
| 49 | set_status() | 7→D016 (0-rows = true) | 13 | 9 |
| 50 | defaults() | 21→D012 (45-day default) | 12 | 10 |
| 51 | get() merge | — (partial-option merge tested) | 12 | 11 |
| 52 | register/sanitize dispatch | — | 13 | 10 |
| 53 | sanitize general | — (URL scheme allowlist tested) | 12 | 11 |
| 54 | sanitize regions | 7→D008 (absent key silently restores defaults) | 12 | 10 |
| 55 | sanitize appearance | — | 13 | 10 |
| 56 | sanitize content | 18→D024 (blank restores default; cannot empty a string) | 12 | 10 |
| 57 | sanitize services | — (category select guarantees key presence for unchecked rows — checkbox-absence trap avoided; verified) | 13 | 10 |
| 58 | sanitize custom_rules | — (empty-pattern rows dropped; note: no breadth warning on catastrophically broad patterns — accepted, blocker slice) | 12 | 11 |
| 59 | sanitize log | — | 14 | 9 |
| 60 | sanitize dsar | — | 13 | 10 |
| 61 | sanitize advanced | — | 14 | 9 |
| 62 | sanitize_category | — | 16 | 7 |
| 63 | register_tab | — | 16 | 7 |
| 64 | enqueue_assets | — (tab-scoped action; conventions met) | 12 | 11 |
| 65 | general section | — | 11 | 12 |
| 66 | regions section | 2→D007 (closed country list), 7→D008 | 10 | 11 |
| 67 | geo readout | — (Kinsta/CF-transform guidance is a genuine State-Visibility pass) | 12 | 11 |
| 68 | appearance section | — | 12 | 11 |
| 69 | content section | 18→D024 | 13 | 9 |
| 70 | services table | — (round-trip verified via registry all() merge) | 11 | 12 |
| 71 | repeater PHP/template | 9→D006 | 11 | 11 |
| 72 | log viewer embed | — | 12 | 11 |
| 73 | log filter form | — (GET, read-only, nonce-free: correct) | 12 | 11 |
| 74 | export handler | 20→D028 (no audit trail) | 10 | 12 |
| 75 | privacy-req section | — | 13 | 10 |
| 76 | advanced section | — | 13 | 10 |
| 77 | header ladder | 19→D009 | 10 | 12 |
| 78 | normalize/NON_COUNTRIES | — (placeholder + fall-through tested) | 14 | 9 |
| 79 | lookup_via_api | 19→D010, 20→D026, 12→D027 (untested) | 9 | 11 |
| 80 | client_ip | 19→D010 | 12 | 10 |
| 81 | ip_block | — (v6 compressed-form trap handled; tested) | 13 | 10 |
| 82 | posture()/fallback | — (tested incl. configurable list) | 12 | 11 |
| 83 | memoization | — | 15 | 8 |
| 84 | js color pickers | — | 16 | 7 |
| 85 | js page pickers | — | 15 | 8 |
| 86 | js logo frame | 22→D025 | 14 | 8 |
| 87 | js repeater | 9→D006 | 13 | 9 |
| 88 | admin.css | — | 17 | 6 |
| 89 | dsar tests | — (strong: honeypot, %-email, mail failure, deadline pinning, IPv6) | 18 | 5 |
| 90 | settings tests | 12→D027 (sanitize coverage gaps: services/custom_rules/dsar/content/countries) | 18 | 4 |
| 91 | settings-ui tests | — | 19 | 4 |
| 92 | geo tests | 12→D027 (Tier-2 path dark) | 18 | 4 |

**Tally:** 92 units × 23 lenses = **2116 cells**, all examined. Directive-cells: 57 (collapsing to 28 unique directives). n/a: 1213. Passes: 846. Blank: 0.

Representative n/a justifications (pattern applies across like units): Accessibility/i18n on DB columns and pure PHP helpers (no rendered surface); Terminality on stateless string transforms (`normalize`, `ip_block`, `sanitize_category`); Gating-Axis on units reachable only behind the already-audited `manage_options` gate; Organization on single-value units; Sibling-Coherence on units with no sibling implementation; CSV-injection on export fields (all values structurally constrained: UUID, hex hash, A-Z region, key slugs, ints — no formula-capable free text; justified n/a); email header injection (recipient via `sanitize_email`/`is_email`, static subjects — justified n/a); tests rows: most product lenses n/a, judged on coverage lenses only.

## Notable verified passes (things the audit specifically confirmed sound)

- Honeypot returns bot-indistinguishable 'ok' with zero rows and zero mail (tested).
- Rate-limit transient set only after successful insert — DB failure never locks out a retry (class-dsar.php:179-181).
- `privacy_export` isolation between requesters (tested); `done` contract obeyed; eraser single-pass `done:true` is correct given wholesale DELETE.
- Both mail sends deliberately best-effort — a mail outage cannot lose a persisted DSAR row (tested).
- Deadline snapshot at creation — later settings edits don't move existing deadlines (tested).
- Services table checkbox round-trip is *not* subject to the unchecked-checkbox trap: the adjacent category `<select>` always submits the service key, so `enabled => false` is representable.
- Settings form architecture (single options.php form; log filter/export forms outside it to avoid invalid nested forms) is correct and documented.
- Conventions: text domain `anchor-schema` throughout both PHP files; `ANCHOR_TOOLS_PLUGIN_URL` for assets; jQuery IIFE; source (non-min) assets enqueued; tab-scoped enqueue via `anchor_settings_enqueue_compliance`; `update_option(..., false)` where called directly; shortcode uses ob_start/ob_get_clean. All met. (The stored settings option is autoloaded via the Settings API path — acceptable and arguably desirable since `get()` runs on every front-end request.)

## Directives (28)

[D001] (DSAR table rows / statuses completed+rejected × Terminality + Privacy/Data-retention) — «A table that exists to service privacy law must itself obey retention limits: closed requests must age out.» Instance: `wp_anchor_privacy_requests` stores email, name, free-text details forever; no retention setting, no purge cron — contrast the consent log's `retention_days` + `CRON_HOOK` purge (anchor-compliance.php:92). A *delete* request's own PII is kept indefinitely. class-dsar.php:46-73, class-settings.php:80-84. Fix-class: add `dsar.retention_days` + reuse the existing daily cron to purge completed/rejected rows past deadline+N. Severity: high.

[D002] (details column / type `correct` / queue table × Population-Dark + Comprehension) — «Every stored field an admin needs to act on must be visible where the acting happens.» Instance: `details` — the only field carrying what a correct/delete requester actually wants — is never rendered anywhere in wp-admin; the queue shows 7 columns, details is not one (class-dsar.php:459-495). Correction requests are unactionable without raw SQL. Fix-class: add a details column/expandable row to the queue. Severity: high.

[D003] (types access/delete/correct/optout / requester email × Gating-Axis + Regulatory + Sibling-Coherence) — «A DSAR intake must verify the requester controls the email before the request is treated as actionable.» Instance: anyone can file a request naming any email (class-dsar.php:117-186); no confirmation link, no `verified` flag in the schema, nothing tells the admin verification is outstanding — while WP core's own `wp_create_user_request()` confirm-by-email machinery (which this class's docblock praises) goes unused for intake, so an admin acting on a queue row may release/delete data to an impostor. The confirm email sent is receipt-only. Fix-class: send a confirmation link that flips a `verified_at` column (or route access/delete into `wp_create_user_request()`), and badge unverified rows in the queue. Severity: high.

[D004] (rate limiter / owner email / details size × Security + Cost/Value) — «An unauthenticated write endpoint needs a limit keyed on what the attacker can't vary.» Instance: the limiter keys on email+IP (class-dsar.php:146), so unlimited distinct local-parts (or plus-addressing, or IP rotation) yield unlimited DB rows (details up to 64KB each), 2 wp_mail() sends per submit (owner mail-bomb), and one wp_options transient row per pair. Honeypot stops only dumb bots. Fix-class: add a second, IP-only (and a global per-window) limiter tier + a maxlength on details. Severity: medium.

[D005] (maybe_install / create() × Precondition/Forward-path) — «Schema install must precede the first code path that needs the table.» Instance: `maybe_install` runs only on `admin_init` (anchor-compliance.php:91), but the intake handler is `admin_post_nopriv` — on a fresh deploy, every public submission fails with the generic error until someone loads a wp-admin page. Fix-class: also call `maybe_install()` lazily at the top of `create()` (cheap option check), or hook the module's activation path. Severity: medium.

[D006] (custom-rule repeater × Idempotence/Structure) — «Repeater indexes must be unique for the life of the form, not recomputed from surviving row count.» Instance: admin.js:97 sets `nextIndex = $container.find('.anchor-cmp-rule-row').length`; remove a middle row (say index 1 of 0..2) then click Add — the new row gets index 2, colliding with the existing `custom_rules[2][...]` names, and PHP's POST parsing silently keeps only the later row: a saved rule vanishes. Fix-class: keep a monotonically increasing counter seeded from the max existing `data-index`. Severity: medium.

[D007] (strict-country select × Structure + Contract-Stability) — «The UI must be able to express every value the sanitizer accepts and the store may hold.» Instance: the multi-select renders options only from `default_strict_countries()` (class-settings.php:469), yet `sanitize()` accepts any A-Z code; a non-default code (e.g. `CA`, or anything set via code/filter) renders no option, so the very next save silently drops it from the stored list. Fix-class: render options for the union of defaults + currently-stored codes (or a full ISO list). Severity: medium.

[D008] (sanitize regions / regions section × Honesty + Least-astonishment) — «Deselect-all must mean empty, not 'restore factory defaults'.» Instance: a multi-select with nothing selected submits no `strict_countries` key; sanitize's `$r['strict_countries'] ?? $d['regions']['strict_countries']` (class-settings.php:161) then re-imposes all 33 defaults — an admin cannot empty the strict list, and gets no message saying so. Fix-class: emit a hidden sentinel field (e.g. `strict_countries_present=1`) so absent-vs-empty is distinguishable. Severity: medium.

[D009] (geo header ladder × Security + Provenance→Consumption) — «Trust a geolocation header only when its producer is known to be in the request path.» Instance: all five headers are trusted unconditionally (class-geo.php:24-30, 50-60) — any client or misconfigured proxy can send `X-Geo-Country: US` (or the Vercel/CloudFront ones) and flip an EU visitor's posture to optout site-wide-per-request; nothing lets the admin pin which source is authoritative. Fix-class: a settings dropdown selecting the trusted source (auto-detected default), ignoring the rest. Severity: medium.

[D010] (client_ip / lookup_via_api × Security + Cost/Value) — «Client-supplied IP headers must not drive paid lookups or cache writes ahead of REMOTE_ADDR.» Instance: `client_ip()` prefers spoofable `CF-Connecting-IP`/`X-Real-IP` over `REMOTE_ADDR` unconditionally (class-geo.php:129); an attacker iterating fake source /24s forces one metered API call + one week-long wp_options transient per block — quota drain plus unbounded option-table bloat. Fix-class: only honor proxy headers when the corresponding proxy is detected/configured; cap or namespace-flush geo transients. Severity: medium.

[D011] (queue query / queue table × Organization + Population-Dark + Temporal-Integrity) — «A statutory-deadline queue must keep every open item reachable and triageable.» Instance: hardcoded `limit 200`, newest-first, no pagination, no status filter, no sort (class-dsar.php:454) — past 200 rows, the *oldest* (most overdue) requests silently fall off the screen entirely. Fix-class: convert to `WP_List_Table` (pagination + status filter + sortable deadline), or at minimum an "open first, oldest first" ordering with paging. Severity: medium.

[D012] (deadline column / dsar defaults × Regulatory-correctness) — «Deadline defaults must satisfy the strictest regime the module itself defaults to enforcing.» Instance: `response_days` defaults to 45 (class-settings.php:83) — CCPA-correct, but GDPR Art. 12(3) requires response within one month, and this module's own default posture treats the EEA/UK as strict; a GDPR site shipping defaults is out of compliance by ~15 days. Fix-class: default 30 with a description noting 45 for CCPA-only sites (or per-region deadlines derived from the request's posture). Severity: medium.

[D013] (ok/error notices / handle_submit × Honesty + Cost/Value) — «Tell the requester what actually happened.» Instance: create() builds four distinct, translated WP_Error messages (invalid type/email, rate-limited, db) that handle_submit collapses to a binary `anchor_cmp=error` (class-dsar.php:405, 415-417); a rate-limited genuine user is told to "check the form and try again" — wrong advice — and the translated strings are dead weight. Fix-class: carry the error code in the redirect query and map it to the right message in shortcode(). Severity: medium.

[D014] (status `new` / overdue indicator / owner email × State-Visibility + Temporal-Integrity) — «A statutory clock must be visible without navigating to it.» Instance: the only push signal is one best-effort email at creation (class-dsar.php:198); if it's missed/filtered, nothing else — no admin notice, no menu bubble count of `new`/overdue rows, no reminder as the deadline nears; the red days-remaining styling only helps someone already on the page. Fix-class: pending/overdue count badge on the submenu + an admin_notices banner when any open request is within N days of deadline. Severity: medium.

[D015] (nonce × Failure/Recovery) — «A public form must degrade legibly under full-page caching.» Instance: the DSAR form embeds a 12-24h nonce (class-dsar.php:332); on any page-cached site the cached nonce goes stale, after which *every* submission returns the generic 'error' with no hint, indefinitely, until the cache purges. Fix-class: on nonce failure return a distinct code ("page expired — reload and retry"), or drop the nonce for this unauthenticated endpoint (honeypot+rate-limit already carry the abuse load; CSRF adds nothing an attacker can't do directly). Severity: medium.

[D016] (set_status / status-update POST × Honesty) — «Report success only when state changed.» Instance: `$wpdb->update()` returns 0 for a nonexistent id or unchanged status; `set_status()` maps that to true (class-dsar.php:296), so the page prints "Status updated." for a no-op or bogus id; an invalid status value shows nothing at all (no error notice, class-dsar.php:448-452). Fix-class: distinguish 0-rows from success; add a failure notice branch. Severity: low.

[D017] (privacy_erase / type optout × Regulatory + Reversibility) — «Erasure must not destroy the evidence of an honored opt-out.» Instance: `privacy_erase()` deletes *all* rows for the email including `optout` requests (class-dsar.php:616) with `items_retained` always false — the record proving a CCPA do-not-sell request was received/honored is destroyed, and no legal-hold consideration surfaces in `messages`. Fix-class: anonymize (blank email/name/details) rather than delete optout rows, with a `messages` entry explaining the retention. Severity: low.

[D018] (consent-ID scan × Gating-Axis) — «Possession of a token is weaker proof than ownership of the account it belongs to.» Instance: privacy_export() returns any consent-log record whose UUID appears in the free-text details (class-dsar.php:568-595) — anyone who has seen a victim's consent receipt UUID gets that record exported to *their own* verified email. Mitigation already present: UUIDs are high-entropy and the exported record is low-sensitivity (region/categories/hash). Fix-class: document the trade-off in the exporter output, or require the consent cookie itself rather than its ID. Severity: low.

[D019] (notes column × Cost/Value + Population-Dark) — «Every schema column must have at least one reader and one writer.» Instance: `notes` is created and written as `''` on insert (class-dsar.php:62, 168) and never read, displayed, or editable anywhere. Dead weight that implies a case-notes feature that doesn't exist. Fix-class: either add a notes textarea to the queue row (pairs well with D002) or drop the column. Severity: low.

[D020] (created_at/type/status/deadline in queue × Comprehension + i18n) — «Admin tables speak human, localized language.» Instance: the queue prints raw slugs (`optout`, `in_progress`) although `type_labels()` exists twenty lines away, and raw UTC `Y-m-d H:i:s` datetimes with no timezone label or `wp_date()`/site-format localization (class-dsar.php:477-492). Fix-class: reuse `type_labels()`, add status labels, render dates via `wp_date( get_option('date_format') ... )` with a UTC note. Severity: low.

[D021] (status select × Accessibility) — «A state-changing control must be operable without a mouse and without surprise submits.» Instance: `<select onchange="this.form.submit()">` with no submit button (class-dsar.php:485) — keyboard users changing value with arrow keys can fire immediate form submission per change in some browsers, and there is no non-JS path. Fix-class: add a visually-modest Apply button (or submit on `change` only via JS that respects keyboard interaction patterns). Severity: low.

[D022] (table install × Contract-Stability) — «dbDelta's parsing quirks are part of its contract.» Instance: `PRIMARY KEY (id)` with a single space (class-dsar.php:63); WP's dbDelta documentation requires two spaces after "PRIMARY KEY" — creation works today, but a future DB_VERSION bump re-runs dbDelta which can mis-parse and emit a duplicate-key ALTER. Fix-class: `PRIMARY KEY  (id)`. Severity: low.

[D023] (shortcode disabled-state / process_submission × Gating-Axis) — «An intake toggle must gate the endpoint, not just the form's visibility.» Instance: `dsar.enabled` hides the form (class-dsar.php:316) but `process_submission()`/`create()` never check it — a nonce harvested while enabled keeps working up to ~24h after the site owner turns intake off. Fix-class: check `dsar.enabled` at the top of `process_submission()`. Severity: low.

[D024] (sanitize content / content section × Least-astonishment) — «Blank is a value.» Instance: `'' === trim($val)` restores the shipped default (class-settings.php:211), so an admin cannot intentionally empty any of the 18 content strings (e.g. suppress `notice_body`); the placeholder shows the default, implying blank = blank. Fix-class: treat blank as blank; offer a "reset to default" affordance instead. Severity: low.

[D025] (js logo frame × i18n) — «All user-visible strings pass through the translation layer.» Instance: `'Select Logo'` / `'Use this logo'` hardcoded English in admin.js:51-52 while every PHP string uses the `anchor-schema` domain. Fix-class: pass them via `wp_localize_script`/`wp_add_inline_script` from PHP. Severity: low.

[D026] (lookup_via_api × Privacy + Cost) — «Sending a visitor's IP to a third party is itself a disclosure, and a synchronous one blocks the page.» Instance: Tier-2 ships the raw visitor IP to ipinfo/ipapi pre-consent (class-geo.php:112-116) with no mention in the settings description that this is a data transfer needing privacy-policy coverage, and the 2s-timeout request runs synchronously on a cache-miss page view. Fix-class: a settings-description disclosure sentence + consider deferring the first lookup off the critical path. Severity: low.

[D027] (Tier-2 geo path / sanitize sections × Population-Dark of tests) — «Untested units are dark units.» Instance: `lookup_via_api()` (provider URL building, success/failure transient caching, ladder hand-off) has zero tests (test-compliance-geo.php covers headers/normalize/ip_block only); `sanitize()` has no tests for services, custom_rules, content plain-text-vs-kses split, dsar, or strict_countries filtering (test-compliance-settings.php covers general/advanced only). Fix-class: add a `pre_http_request`-filtered Tier-2 test and per-section sanitize tests. Severity: low.

[D028] (export handler × Privacy/Data-retention) — «Bulk PII egress should leave a trace.» Instance: `handle_export_log()` streams the full consent log (incl. ip_hash column) with correct capability+nonce gating but no record of who exported when (class-settings.php:301-307). Fix-class: one `error_log`/option-stamped audit line (user ID, timestamp, row count) before streaming. Severity: low.
