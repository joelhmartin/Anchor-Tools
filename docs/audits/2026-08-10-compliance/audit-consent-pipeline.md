# AUDIT LEDGER — anchor-compliance consent runtime pipeline

Slice: module bootstrap, class-banner, class-consent-state, class-consent-mode, class-rest,
class-consent-log, consent-flow portions of assets/frontend.js, and their 5 test files.
Read-only audit. Repo: /Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools

Brief challenge: brief is accurate. All named files/flows exist. One overlap note: frontend.js
Section 15 (iframe MutationObserver guard) straddles this slice and the script-blocker slice;
it is audited here for consent-flow correctness only. `[anchor_consent_link]` /
`[anchor_do_not_sell]` are registered in anchor-compliance.php:103-104 as stated.

## LENSES (23)
L1 Terminality · L2 Structure/Grain · L3 Organization · L4 Provenance→Consumption ·
L5 Comprehension · L6 State-Visibility · L7 Honesty · L8 Reversibility/Safety ·
L9 Idempotence/Accretion · L10 Failure/Recovery · L11 Precondition/Forward-path ·
L12 Population/Dark · L13 Sibling-Coherence · L14 Gating-Axis · L15 Temporal-Integrity ·
L16 Cost/Value · L17 Contract-Stability · L18 Naming/Least-astonishment ·
L19 Security · L20 Privacy/Retention · L21 Regulatory-correctness · L22 Concurrency/Caching ·
L23 Performance

## CENSUS (53 units, enumerated from code)

Bootstrap — anchor-compliance/anchor-compliance.php
- U1  __construct() hook wiring (rest_api_init, admin_init×2, cron hook, metabox/save_post/admin_notices/snippet filter, 4 shortcodes, admin_post DSAR ×2, admin_menu, and !is_admin(): wp_head p1 emit_defaults, template_redirect p1 buffer, wp_enqueue_scripts, wp_footer p5 render)
- U2  instance() passive singleton
- U3  load_includes() (12 requires)
- U4  cron scheduling block (wp_next_scheduled + wp_schedule_event daily, lines 99-101)

class-banner.php
- U5  payload() — AnchorComplianceData contract (posture, gpc, hasConsent, categories, policyVersion, lifetimeDays, cookieName, restUrl, strictCountries, allowClientRelax, consentMode, signalMap, cookiePatterns, iframeRules, ctm, i18n)
- U6  brand_colors() (inherit from anchor_site_config_options)
- U7  contrast_ink() (WCAG luminance)
- U8  safe_hex()
- U9  enqueue() (frontend.css/js, inline payload before, :root token inline style)
- U10 render() (banner + prefs dialogs, category toggles, cookie tables, footer links, pill, live region)
- U11 shortcode_consent_link() `[anchor_consent_link]`
- U12 shortcode_do_not_sell() `[anchor_do_not_sell]`
- U13 categories()/category_labels()/category_descriptions() helpers + filters

class-consent-state.php
- U14 encode()/decode() (base64url JSON)
- U15 stored()/has_stored_consent() (memoized; policy-version + lifetime validation)
- U16 is_gpc() (Sec-GPC + honor_gpc)
- U17 categories()/allows() (grant map w/ default_grant + GPC override)
- U18 consent cookie contract: name `anchor_consent`; value base64url(JSON {id,ts,v,cats}); TTL = consent_lifetime_days (default 365, cap 730); client-written only; path=/; SameSite=Lax; Secure on https; host-only (no Domain)

class-consent-mode.php
- U19 signal_map() — 7 GCMv2 signals: security_storage→necessary, functionality_storage+personalization_storage→functional, analytics_storage→analytics, ad_storage+ad_user_data+ad_personalization→marketing
- U20 defaults_payload()
- U21 emit_defaults() (wp_head p1; gtag stub, consent default, ads_data_redaction, url_passthrough, wait_for_update)

class-rest.php
- U22 route POST /anchor-compliance/v1/consent (permission __return_true; args: consent_id UUIDv4 regex, categories enum array, method enum banner|preference_center|gpc|api)
- U23 handle_consent() + dedupe transient (anchor_cmp_consent_ + md5(id), 60s)
- U24 action `anchor_compliance_consent_changed` (categories, consent_id)

class-consent-log.php
- U25 install()/maybe_install() + table wp_anchor_consent_log — columns: id, consent_id CHAR(36), created_at DATETIME(UTC), ip_hash CHAR(64), region VARCHAR(8), posture VARCHAR(16), categories TEXT(JSON), policy_version VARCHAR(16), method VARCHAR(32), ua_hash CHAR(64); keys consent_id, created_at, method
- U26 record()
- U27 hash_ip() (/24 v4, /64 v6 truncate + sha256 w/ wp_salt('auth')) / hash_ua()
- U28 where()/query()/count() (filters: method, region, since; limit cap 500)
- U29 purge() + daily cron (retention_days, default 730, 30–3650)
- U30 export_csv() (streams all rows; capability check delegated to caller by contract)

assets/frontend.js (consent flow)
- U31 S0/S10 first-touch capture + persistFirstTouch (sessionStorage anchor_cmp_ft, gated on CTM grant or optout posture)
- U32 S1 payload bootstrap + normalization (bail on missing D)
- U33 S3 cookie codec + readStoredConsent()/writeConsentCookie() (mirrors PHP incl. version/age)
- U34 S4 sweepDeniedCookies() + deletionDomains() (denied-category cookie deletion on withdrawal)
- U35 S5 state maps (blankState/mapFromList/cloneState/grantedList)
- U36 S6 GPC (navigator.globalPrivacyControl ∪ D.gpc; gpcClientOnly branches in boot: revoke / optout auto-record / strict announce-only)
- U37 S7 relax tier clientCountryIsStrict() (IANA timezone; strict→optout only)
- U38 S8 activate() (script replace, iframe restore, placeholder removal)
- U39 S9 ensureGtag()/pushConsentMode() (gtag consent update)
- U40 S11 uuidv4() + postRecord() (fire-and-forget POST, keepalive)
- U41 S12 listeners + notifyChange (anchor-consent-change CustomEvent)
- U42 S13 UI state, focus trap, handleKeydown (Escape never grants; documented)
- U43 S14 setConsent() (700ms same-decision debounce) + normalizeCategories + refresh/readCheckboxes
- U44 S15 iframe guard MutationObserver (consent-relevant portions; shared w/ blocker slice)
- U45 S16 onClick dispatch: accept-all, reject-all, save-preferences, customize/open-preferences, do-not-sell, close, data-anchor-accept
- U46 applyNoticeCopy() + showGpcNotice()
- U47 S17 public API window.AnchorConsent (get/has/on/off/accept/reject/openPreferences/firstTouch/refresh/version)
- U48 S18 resolveInitialState()/earlyBoot()/boot()

Tests
- U49 tests/test-compliance-banner.php (18 tests)
- U50 tests/test-compliance-consent-state.php (9 tests)
- U51 tests/test-compliance-consent-mode.php (8 tests)
- U52 tests/test-compliance-rest.php (9 tests)
- U53 tests/test-compliance-log.php (7 tests)

Repo conventions verified: text domain 'anchor-schema' throughout slice (pass); the single
update_option in slice passes autoload=false (class-consent-log.php:49, pass); asset URLs use
ANCHOR_TOOLS_PLUGIN_URL (class-banner.php:235, pass); source .css/.js enqueued, never .min
(pass, test-asserted); frontend.js is a vanilla (non-jQuery) IIFE — documented deliberate
deviation (must run before jQuery; footer, no dependency) → n/a justified; shortcode callbacks
return built strings rather than ob_start/ob_get_clean — n/a justified for one-element output
(convention targets complex frontend rendering); REST namespace (not AJAX) so anchor_{module}_
AJAX prefix n/a; transient/option keys prefixed anchor_cmp_/anchor_compliance_ (pass).

## UNITS × LENSES

Notation: `D:` directive cells (see directive list), `n/a:` justified not-applicable lenses,
everything else = pass. Every one of the 53×23 = 1219 cells is accounted for; blank = 0.

| Unit | D (lens) | n/a (justified) | pass |
|---|---|---|---|
| U1 wiring | — | L20,L22 (no data stored; wiring not cacheable) | 21 |
| U2 instance() | — | L6,L8,L14,L19,L20,L21,L22,L23 (passive getter, no state writes) | 15 |
| U3 load_includes | — | L4,L6,L8,L9,L14,L15,L19,L20,L21,L22 (require_once, inert) | 13 |
| U4 cron block | C004(L8) | L14,L19,L21,L22 | 18 |
| U5 payload() | C003(L22) | L8,L20 (read-only; no PII in payload — verified no token leak) | 20 |
| U6 brand_colors | — | L8,L9,L14,L15,L20,L21,L22 (pure read) | 16 |
| U7 contrast_ink | — | L4?,no—consumed. n/a: L6,L8,L9,L10,L11,L14,L15,L19,L20,L21,L22,L23 (pure math) | 11 |
| U8 safe_hex | — | L6,L8,L9,L10,L11,L14,L15,L16,L20,L21,L22,L23 (pure validator) | 11 |
| U9 enqueue() | — | L20,L21 | 21 |
| U10 render() | C005(L19), C011(L5) | L20 | 20 |
| U11 [anchor_consent_link] | C014(L12) | L8,L9,L15,L20,L22 | 17 |
| U12 [anchor_do_not_sell] | — | L8,L9,L15,L20,L22 | 18 |
| U13 label helpers | — | L1,L6,L8,L9,L14,L15,L19,L20,L22 | 14 |
| U14 encode/decode | — | L6,L8,L9,L14,L15,L21,L22 (pure codec; round-trip test-verified) | 16 |
| U15 stored() | — | L8,L14,L21,L22 (validation itself IS the regulatory pass at U17/U18) | 19 |
| U16 is_gpc() | — | L6,L8,L9,L14,L15,L22 | 17 |
| U17 categories()/allows() | C001(L21) | L8,L14,L15,L22 | 18 |
| U18 cookie contract | C010(L21) | L14,L23 | 20 |
| U19 signal_map | — | L6,L8,L9,L10,L11,L14,L20,L22 (static map; v2-complete incl. ad_user_data/ad_personalization — test-asserted) | 15 |
| U20 defaults_payload | — | L8,L9,L14,L20,L22 (caching failure charged once at C003) | 18 |
| U21 emit_defaults | — (C003 cross-ref for L22 cached-HTML defaults) | L8,L9,L20 | 20 |
| U22 route+validation | — | L8,L15,L23 (validation strict: UUID regex, category enum, method enum; 400s test-asserted) | 20 |
| U23 handle_consent | C007(L19) | L8,L15 | 21 |
| U24 consent_changed action | — | L6,L8,L9,L14,L20,L22 (fires once per recorded decision — dedupe test-asserted) | 17 |
| U25 install/schema | C008(L11) | L14,L21,L22 | 19 |
| U26 record() | C016(L15) | L14,L22 | 20 |
| U27 hash_ip/hash_ua | — | L6,L8,L9,L14,L22 (truncate-then-salt-hash; raw IP never persisted — test-asserted; IPv6 via inet_pton, compressed-form bug documented+fixed) | 18 |
| U28 where/query/count | C015(L7) | L8,L14,L20,L21 (all inputs %s-prepared; limit capped 500) | 18 |
| U29 purge()+cron | — (C004 cross-ref: purge dead when module off) | L14,L19,L21,L22 | 19 |
| U30 export_csv | — | L9,L14,L21,L22 (capability contract explicitly delegated in docblock; batched 1000; exits) | 19 |
| U31 first-touch | — | L8,L14,L21? no—L21 pass (ePrivacy reasoning sound: memory-only until lawful). n/a: L8,L14,L22,L23 | 19 |
| U32 payload bootstrap | — | L8,L9,L14,L20,L21,L22 (defensive normalization; silent bail documented) | 17 |
| U33 JS cookie codec | — | L14,L20,L21 (mirrors PHP incl. NaN-ts and negative-age; UTF-8 safe) | 20 |
| U34 sweepDeniedCookies | C017(L1) | L14,L19,L21,L23 | 18 |
| U35 state maps | — | L1,L6,L8,L10,L14,L20,L22 (pure) | 16 |
| U36 GPC JS | — | L8,L14,L22,L23 (revoke preserves functional — correct; strict-no-choice logs nothing per pageview — correct) | 19 |
| U37 relax tier | C006(L15) | L8,L9,L20,L22 (strict→optout only, single call site, GPC-guarded — structurally sound) | 18 |
| U38 activate() | — | L8,L14,L20,L21 (replace-not-mutate correct; iframe double-activation guarded; nonce carried) | 19 |
| U39 pushConsentMode | — | L8,L14,L20,L22,L23 (correct gtag('consent','update') shape) | 18 |
| U40 uuid/postRecord | — | L8,L14,L20,L22 (crypto.getRandomValues w/ fallback; fire-and-forget honest per contract) | 19 |
| U41 notifyChange | — | L1,L8,L14,L20,L21,L22 (subscriber isolation; CustomEvent fallback) | 17 |
| U42 focus/keydown | — | L4,L8,L14,L20,L22 (Escape-never-grants invariant documented and correct in all 3 branches) | 18 |
| U43 setConsent | — | L14,L20,L22 (debounce collapses record, never the UI response — dead-click case handled) | 20 |
| U44 iframe guard | — | L8,L14,L20,L21 (microtask-gap and cost profile documented; shared with blocker slice) | 19 |
| U45 onClick dispatch | C002(L21), C012(L4), C013(L13) | L8,L20,L22 | 17 |
| U46 notice/GPC notice | — | L8,L9,L14,L20,L22 (innerHTML only for kses-sanitized payload strings; notice built client-side for cache honesty) | 18 |
| U47 public API | — | L8,L14,L20,L21,L22 (returns copies; version field present) | 18 |
| U48 boot/resolve | C009(L12) | L8,L14,L20 | 19 |
| U49 banner tests | — | L6,L8,L9,L10,L14,L15,L20,L22,L23 (test code) | 14 |
| U50 state tests | — | same 9 | 14 |
| U51 mode tests | — | same 9 | 14 |
| U52 rest tests | — | same 9 | 14 |
| U53 log tests | — | same 9 | 14 |

Totals: cells 1219 = directives 17 + n/a 275 + pass 927. Blank 0.

Notable explicit passes (things checked and found correct, recorded so they aren't re-litigated):
- GCM v2 signal set complete incl. ad_user_data + ad_personalization (U19; test-asserted).
- Strict region defaults all-denied before choice; opt-out defaults granted (GDPR/CCPA split correct server-side) (U20, U51).
- GPC precedence over stored opt-in; functional untouched (U17, U50).
- IP pseudonymization: truncate /24 (v4) / /64 (v6 via inet_pton) then salted sha256; raw never stored (U27, U53).
- Retention purge parameterized, UTC-coherent with created_at (U29, U53).
- All log SQL goes through $wpdb->prepare with %s/%d; limit capped (U28).
- Payload JSON via wp_json_encode — PHP escapes `/` by default so `</script>` breakout is closed (U9, U21).
- Consent cookie: no PII, host-only, SameSite=Lax, Secure-on-https, TTL capped 730d (U18).
- Escape never grants; strict-region banner is a genuine focus-trapped modal with equal-prominence Reject (U42, U10).
- Dedupe answers `logged:false` honestly on suppression; disabled log answers `logged:false` (U23, U52).
- Client cookie read wins over cache-baked hasConsent/categories (U33) — cookie half of the cache problem is solved; posture half is not (C003).

## DIRECTIVES

[C001] (U17 Consent_State::categories × Regulatory-correctness) — «Unknown input in a grant list must be dropped, never coerced into a grant». Instance: class-consent-state.php:122 runs stored cookie cats through Anchor_Compliance_Settings::sanitize_category (class-settings.php:271), whose fallback for any invalid token is 'marketing' → a corrupt/forged cookie `cats:["junk"]` grants marketing; the JS mirror (frontend.js:1076-1084) correctly drops unknown tokens, so PHP and JS disagree on the same cookie. Fix-class: in categories(), validate with in_array against all_categories() and skip non-matches; reserve sanitize_category's marketing fallback for service categorization only. Severity: high.

[C002] (U45 do-not-sell handler × Regulatory-correctness) — «An opt-out signal must never grant a category the visitor did not grant». Instance: frontend.js:1481 `setConsent(['necessary','functional'], ...)` force-grants functional regardless of prior choice; a visitor who had rejected functional and clicks "Do Not Sell" gets functional granted — the exact failure the GPC branch (frontend.js:1720-1724) documents and avoids («force-granting it here would turn a withdrawal signal into a grant the visitor never gave»). Fix-class: mirror the GPC branch — `var keep=['necessary']; if(state.functional){keep.push('functional');}`. Severity: high.

[C003] (U5 payload()/U21 emit_defaults × Concurrency/Caching) — «Per-visitor consent posture must not be decided by cache-baked HTML». Instance: class-banner.php:120 bakes `posture` and class-consent-mode.php:62-70 bakes granted/denied GCM *defaults* into cacheable page HTML; a full-page cache warmed by a US visitor serves `posture:'optout'` + `granted` defaults to an EU visitor, and frontend.js:1616-1651 only ever relaxes strict→optout — nothing tightens optout→strict — so tags fire pre-consent in a strict region (prior-consent violation). The cookie half is correctly cache-proofed (frontend.js:293); posture is not. Fix-class: client-side tightening check symmetrical to the relax tier (consume D.strictCountries + timezone to force strict when the cached posture can't be trusted), or emit all-denied GCM defaults whenever the response is cacheable. Severity: high.

[C004] (U4 cron scheduling × Reversibility/Safety + Privacy/Data-retention) — «A retention promise must survive the feature being turned off». Instance: anchor-compliance.php:99-101 schedules the daily purge only while the module class is constructed; disabling the module leaves the cron event orphaned with no callback, so consent-log rows outlive log.retention_days indefinitely (storage-limitation breach in a compliance plugin); no register_deactivation_hook/uninstall cleanup exists anywhere (grep: zero hits repo-wide). Fix-class: unschedule + optionally purge on module toggle-off / plugin deactivation. Severity: medium.

[C005] (U10 render() × Security/Sibling-Coherence) — «Untrusted color values are sanitized at the point of CSS interpolation — everywhere, not in one of two call sites». Instance: class-banner.php:297-304 interpolates brand_colors() (which safe_hex's own docblock, lines 207-215, declares untrusted because anchor_site_config_options is never sanitized by this module) into the #anchor-cmp style attribute without safe_hex(), while enqueue() (lines 260-267) carefully sanitizes the identical values — esc_attr blocks attribute breakout but permits arbitrary injected CSS declarations (e.g. background:url(...) beacons). Fix-class: run the three colors through safe_hex() in render() exactly as enqueue() does. Severity: medium.

[C006] (U37 relax tier × Temporal-Integrity + U5 strictCountries × Population/Dark) — «A payload key either drives behavior or does not ship; a client-side mirror of server config must consume the config, not a hardcoded snapshot». Instance: payload exports `strictCountries` (class-banner.php:128) but frontend.js never references it (grep: zero hits); clientCountryIsStrict() (frontend.js:554-566) hardcodes a continent map instead, so an admin adding e.g. JP or CA to strict_countries still gets relax-to-optout for Asia/America timezones — default-grant in an admin-declared strict country. Fix-class: have clientCountryIsStrict() consult D.strictCountries (timezone→country needs only the "could this zone be in the list" direction), or drop the dead key. Severity: medium.

[C007] (U23 handle_consent × Security/Cost) — «An unauthenticated endpoint must not let the caller mint unbounded outbound requests». Instance: class-rest.php:111 calls geo->country() per POST; with an IP API configured, class-geo.php:106-123 does a 2s wp_remote_get per uncached /24 block keyed off client_ip() (class-geo.php:129-137), which trusts spoofable X-Real-IP/CF-Connecting-IP — an attacker rotating fake IPs drives one metered API call + 2s of PHP per request (quota burn + worker exhaustion), bypassing the consent_id dedupe entirely. Fix-class: skip tier-2 lookup in the REST context (region on the audit row is informational; headers-or-nothing suffices) or gate proxy headers behind a trusted-proxy setting. Severity: medium.

[C008] (U25 maybe_install × Precondition/Forward-path) — «A write path must guarantee its table exists before accepting writes». Instance: anchor-compliance.php:90 installs the log table only on admin_init; on a migrated/imported/WP-CLI-provisioned site where no admin request precedes visitor traffic, every REST consent record fails silently ($wpdb->insert on a missing table → record() false → response still `ok:true`), losing the very consent proof the log exists for. Fix-class: call maybe_install() (cheap version-option compare) at the top of record() or also on rest_api_init. Severity: medium.

[C009] (U48 consent runtime × Population/Dark — test coverage) — «The compliance-critical client flow carries automated coverage like the server flow it mirrors». Instance: the 1,819-line frontend.js implements cookie write/expiry, denied-cookie sweep, GPC branches, relax tier, GCM update and the debounce — zero JS/e2e tests exist (no compliance spec in e2e/, no JS harness), while every PHP counterpart is tested; repo rule is "add tests when you touch them". Fix-class: one Playwright spec driving accept/reject/customize and asserting cookie shape + dataLayer update. Severity: medium.

[C010] (U18 consent cookie × Regulatory-correctness — self-disclosure) — «A compliance plugin's own cookie appears in its own disclosure tables». Instance: `anchor_consent` (365-day persistent cookie) is absent from Anchor_Compliance_Service_Registry::builtin(), so the preference-center cookie table (class-banner.php:394-408) and the [anchor_cookie_policy] table disclose every third-party cookie but not the module's own — the necessary category lists only _GRECAPTCHA. Fix-class: add a builtin 'anchor_compliance' necessary-category entry (name anchor_consent, purpose, duration = consent lifetime). Severity: medium.

[C011] (U10 render() × Comprehension/a11y) — «ARIA must describe actual behavior in every posture». Instance: class-banner.php:315 hardcodes `aria-modal="true"` on the banner, but in server-known opt-out posture boot() (frontend.js:1746-1759) neither traps focus nor opens it as a dialog, and applyNoticeCopy() (which sets aria-modal=false + notice class) runs only on the *relaxed* path (frontend.js:1746) — a plain optout render tells screen readers "modal" while behaving as a passive notice. Fix-class: run the aria/notice fixup whenever posture is optout, or render aria-modal conditionally on is_strict(). Severity: low.

[C012] (U45 onClick × Provenance→Consumption — audit method fidelity) — «The audit record's method field reflects the surface the choice was made on». Instance: accept-all/reject-all buttons exist in both the banner and the preference panel but always log `method:'banner'` (frontend.js:1459-1464), and do-not-sell logs `'preference_center'` (frontend.js:1481) because no `do_not_sell` value exists in either enum — the consent log cannot distinguish a CPRA opt-out from an ordinary preference save. Fix-class: derive method from the containing dialog; add 'do_not_sell' to VALID_METHODS and the REST enum. Severity: low.

[C013] (U45 DNS paths × Sibling-Coherence) — «One opt-out semantics, one grant set». Instance: three do-not-sell-shaped paths yield three different results — banner reject-all in optout (labeled with dns_label) keeps necessary only (frontend.js:1464), [anchor_do_not_sell] forces necessary+functional (1481), GPC preserves the visitor's functional choice (1722-1724). The over-revoking direction is privacy-safe but the visitor-visible behavior of the same-labeled control differs by entry point. Fix-class: a single dnsGrantSet() helper used by all three. Severity: low.

[C014] (U11 [anchor_consent_link] × Population/Dark — coverage) — «Every registered shortcode has at least one rendering test». Instance: test-compliance-banner.php tests [anchor_do_not_sell] (line 109) but has no test for [anchor_consent_link] (registered anchor-compliance.php:103) — its disabled-module empty-string path and filter are unexercised. Fix-class: mirror the do_not_sell test. Severity: low.

[C015] (U28 where('since') × Honesty) — «A filter that cannot be applied must fail loudly, not silently match everything». Instance: class-consent-log.php:147 `gmdate('Y-m-d H:i:s', strtotime($args['since']))` — strtotime on garbage returns false, coerced to timestamp 0, producing `created_at >= '1970-01-01'` — an invalid since filters nothing while appearing applied. Fix-class: validate strtotime's return and drop the clause (or error) on false. Severity: low.

[C016] (U26 record() × Temporal-Integrity) — «A consent record stamps the policy version the visitor actually consented under». Instance: class-consent-log.php:80 stamps `policy_version` from current settings at write time, not from the version in the client's payload/cookie; an admin bumping policy_version between page render and the visitor's click yields an audit row claiming consent to a policy text the visitor never saw. Fix-class: accept an optional version param from the client (the payload already carries policyVersion) and fall back to settings. Severity: low.

[C017] (U34 sweepDeniedCookies × Terminality) — «Withdrawal deletion reaches every scope the tracker could have written». Instance: frontend.js:442-446 expires cookies at path=/ (all domain variants) only; a denied-category cookie set with a narrower Path survives withdrawal and keeps transmitting. Rare for mainstream trackers (all registry entries use /), so impact is edge-case. Fix-class: also expire at location.pathname and its parent segments, or document the limitation beside deletionDomains()'s. Severity: low.
