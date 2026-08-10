# Anchor Compliance — Audit Corpus Merge & Fix Plan

Audit coverage: 300 units × 22–24 lenses ≈ 6,873 cells examined, Blank: 0.
Raw directives: 84 (11 high, 35 medium, 38 low). Full ledgers in this directory:
audit-consent-pipeline.md, audit-blocker-disclosure.md, audit-dsar-settings-geo.md, audit-frontend-integration.md.

## Cross-cutting laws (dedupe clusters)

- LAW-1 Cache-vs-consent: consent-varying HTML must not be servable to the wrong consent state. (C003 + B001 + D015)
- LAW-2 Lifecycle: stores installed before first write path; everything torn down on disable/uninstall; retention applies to ALL PII stores. (C004 + C008 + D001 + D005 + F001 + F002 + F014)
- LAW-3 Client mirrors consume server config, never hardcoded snapshots. (C006 + F003 + F004 + B018)
- LAW-4 Blocking covers every request shape a pattern names (script, iframe, img, link-hints, injected nodes). (B002 + B003 + B004 + B007)
- LAW-5 One shared vocabulary for consent methods and grant sets. (C012 + C013 + B015 + F013)
- LAW-6 Registry entries are normalized, current, context-scoped, honestly named. (B008 + B010 + B011 + B012 + B014)
- LAW-7 DSAR queue is trustworthy and visible: verified identity, visible details, reachable backlog, visible deadlines. (D002 + D003 + D011 + D014)
- LAW-8 No success-on-no-op anywhere. (C015 + D008 + D013 + D016 + F010)
- LAW-9 Compliance-critical client runtime carries executable coverage. (C009 + F009 + F017 + F019 + B016 + B017 + C014 + D027)

## File ownership (conflict avoidance — one agent owns each file per wave)

| Owner | Files |
|---|---|
| Fix-1 Blocker | class-script-blocker.php, class-service-registry.php, class-snippets-bridge.php, class-cookie-policy.php, their tests |
| Fix-2 DSAR | class-dsar.php, its tests (minimal, localized defaults() edit in class-settings.php dsar section only) |
| Fix-3 Consent-core & lifecycle | anchor-compliance.php, uninstall.php (new), class-banner.php, class-consent-state.php, class-consent-mode.php, class-rest.php, class-consent-log.php, their tests |
| Fix-4 Frontend runtime | assets/frontend.js, assets/frontend.css |
| Fix-5 Settings & geo | class-settings.php, class-geo.php, assets/admin.js, assets/admin.css, their tests |
| Fix-6 Coverage | tests/ additions only + e2e/compliance spec |

## Waves

### Wave A (3 parallel agents — file-disjoint)
- **Fix-1 Blocker & registry**: B002, B003, B004(PHP consts only), B005, B006, B007, B008, B010, B011 (add per-rule contexts axis: src|iframe|inline), B012, B013, B014, B016, B017. Publish pattern consts + contexts axis as the contract Wave B consumes.
- **Fix-2 DSAR**: D001 (retention + purge via cron hook exposed for bootstrap), D002, D003 (email-confirmation verification, verified_at column, queue badge), D004, D005, D011 (WP_List_Table w/ filter + deadline sort + pagination), D013, D014, D015, D016, D017, D019, D020, D021, D022, D023. D012 (default 30 days) coordinated with Fix-5's settings defaults — Fix-2 owns the dsar defaults block.
- **Fix-3 Consent-core & lifecycle**: C001, C005, C007, C008/D005/F014 (schema-gated maybe_install on bootstrap), C010, C015, C016, C003+B001 server side (no-cache signal on strict/no-cookie responses + filterable), C004+F002 (cron unschedule on module off), F001 (uninstall.php: drop both tables, delete options, clear cron), F005 (emit data-acmp-scheme), F006 (render logo or remove — decide: render in banner header, setting already exists), F007 (route 4 enqueues through Anchor_Asset_Loader), F012 (version from plugin version), F015 (deliberate buffer priority + comment), F020+B019+C006-prep (payload whitelist; keep strictCountries — Wave B consumes it), REST method whitelist additions for LAW-5 ('do_not_sell', 'placeholder').

Gate: run `composer test` (compliance suites at minimum); I review diffs before Wave B.

### Wave B (3 parallel agents — file-disjoint)
- **Fix-4 Frontend runtime**: C002, C011, C013 (dnsGrantSet helper), C017 (document/extend sweep), LAW-5 client side (method per surface), F003/C006 (consume D.strictCountries), B004 (script-node + attributeFilter observer), B018 (fallback rules parity), F008 (strict-posture scrim + scroll-lock all presets), F010 (cookie readback + failure notice), F016 (DSAR form styles), F018 (re-init guard), client half of F005 if needed.
- **Fix-5 Settings & geo**: D006 (monotonic repeater index), D007, D008 (presence sentinel), D009/D010 (trusted-proxy setting gating geo headers + REST tier-2 skip), D024, D025, D026 (disclosure note), D028 (export audit line), B009 (custom-rule cookies into disclosure — coordinate: cookie table render lives in registry/policy owned by Fix-1; Fix-1 lands cookies_by_category change in Wave A, Fix-5 adds repeater purpose/duration fields).
- **Fix-6 Coverage**: e2e/compliance-banner.spec.js (accept/reject/customize/pill/cookie shape/blocked-script activation), C014, F017 (~5 banner tests), D027 (geo tier-2 mocked + sanitize sections), F019, plus regression tests for every Wave A/B fix lacking one.

Gate: full `composer test`, diff review, then /code-review pass on the branch.

### Wave C — verification
Full suite + adversarial diff review; fix fallout; final report.

## Deliberately deferred (documented, not fixed now)
- D018 (consent-ID possession tradeoff — document in code)
- Deeper caching integrations per host (LAW-1 beyond no-cache signal + docs)

## Pre-flight
- Commit current branch state (incl. composer vendor drift) and push as rollback point before Wave A.
