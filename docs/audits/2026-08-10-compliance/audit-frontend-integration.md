# AUDIT LEDGER — anchor-compliance: frontend assets, plugin integration, test-coverage mapping
Slice agent: F (frontend/integration). Read-only audit, 2026-08-10.
Repo: /Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools (branch feature/anchor-compliance)

## Lens key (22 lenses)
T=Terminality · SG=Structure/Grain · O=Organization · PC=Provenance→Consumption · C=Comprehension ·
SV=State-Visibility · H=Honesty · R=Reversibility/Safety · I=Idempotence/Accretion · FR=Failure/Recovery ·
PF=Precondition/Forward-path · PD=Population/Dark · SC=Sibling-Coherence · GA=Gating-Axis ·
TI=Temporal-Integrity · CV=Cost/Value · CS=Contract-Stability · N=Naming/Least-astonishment ·
A11Y=Accessibility · SEC=Security · BC=Browser-compat · TC=Test-coverage

## Cell convention
Every unit row below was walked against all 22 lenses. Notation per row:
- `F###@LENS` — directive (violation) at that cell
- `na: LENS,LENS` — lens does not apply to this unit, with the justification being the unit's kind (e.g. BC on pure-PHP units, A11Y on non-UI units, TI on stateless helpers)
- everything not listed as directive or n/a is an examined **pass**
Census M=91 units × L=22 lenses = 2002 cells; 20 directive cells; 646 n/a cells; 1336 pass cells; 0 blank.

---

## CENSUS + MATRIX

### A. frontend.js UI surfaces & controls (units 1–17)
| # | Unit | Result |
|---|------|--------|
| 1 | Banner panel `#anchor-cmp-banner` (role=dialog, aria-modal) | F008@A11Y. na: BC-n/a? no — BC pass (ES5). Passes: rest. TC covered by F009/F017 at units 76/89. |
| 2 | Prefs panel `#anchor-cmp-prefs` | pass all; na: — |
| 3 | Accept All button (`data-anchor-action=accept-all`, banner+prefs) | pass all (debounce collapses dup records; UI response never suppressed) |
| 4 | Reject / Essential-Only / DNS-label dual button | pass all; H pass (label swap in optout is honest notice copy) |
| 5 | Customize button (aria-controls=anchor-cmp-prefs) | pass all |
| 6 | Save Preferences button | pass all |
| 7 | Prefs close button (&times;, aria-label Close) | pass all; R pass (close = cancel, never a decision) |
| 8 | Category toggles ×4 (checkbox, for/id label, aria-describedby, necessary disabled) | pass all; A11Y pass (forced-colors knob preserved) |
| 9 | Per-category cookie `<details>` + table | pass all; CV pass-with-note (full table markup on every page; justified: pill reopen without round trip) |
| 10 | Footer links (privacy/cookie/terms, esc_url, skip-empty) | pass all; TC gap folded into F017 |
| 11 | Re-entry pill (44px min target, aria-label + sr-only text, mask icon) | pass all |
| 12 | Live region `#anchor-cmp-live` (aria-live=polite, clear-then-set re-announce) | pass all |
| 13 | GPC notice (server-rendered ∨ client-built `showGpcNotice`) | pass all; H pass (server asserts only what it saw) |
| 14 | `[anchor_consent_link]` button shortcode | F017@TC (untested). Others pass |
| 15 | `[anchor_do_not_sell]` button shortcode | pass all (tested) |
| 16 | Blocked-embed placeholder + Accept&Load (`data-anchor-accept`) | F013@H (logged as method `preference_center`). Others pass; R pass (grants only the one category, carries the rest) |
| 17 | DSAR frontend form (`anchor-cmp-dsar-*`) as a styled surface | F016@PD (no CSS rules anywhere); honeypot survives via inline style — FR pass |

### B. frontend.js internal units (18–43)
| # | Unit | Result |
|---|------|--------|
| 18 | First-touch capture (§0, in-memory only pre-consent) | pass all incl. SEC/H (ePrivacy reasoning documented); na: A11Y |
| 19 | Payload bootstrap + normalization (§1) | pass; FR pass (silent bail when payload stripped); na: A11Y |
| 20 | DOM helpers each/qsa/qs/inArray/hasOwn/detach/closest | pass; BC pass (hand-rolled for old Safari); na: A11Y,TI,GA |
| 21 | decodeEntities/i18nText (textarea RCDATA decoder) | pass; SEC pass (no element construction); na: A11Y |
| 22 | Cookie codec (readRawCookie, utf8↔binary, base64url) | pass; CS pass (mirrors PHP encode/decode, padding-free round-trips); na: A11Y |
| 23 | readStoredConsent / writeConsentCookie | F010@FR (no write-verification readback). TI pass (policy-version + age check mirror PHP). Others pass; na: A11Y |
| 24 | sweepDeniedCookies / deletionDomains / matchesAny | pass; R pass (withdrawal deletes parent-domain copies; consent cookie itself protected); documented `.co.uk` no-op — H pass; na: A11Y |
| 25 | State model (blankState/mapFromList/cloneState/grantedList/normalizeCategories) | pass; na: A11Y,FR |
| 26 | GPC flags + applyGpc (§6) | pass; GA pass (revokes analytics+marketing only, functional untouched); na: A11Y |
| 27 | clientCountryIsStrict relax tier (§7) | F003@GA (ignores configured strict list; Cyprus=Asia/Nicosia, Brazil non-São-Paulo tz, EU overseas relaxed). C pass (guard doc excellent) — but the guard protects direction, not membership |
| 28 | activate(): script replace / iframe restore / placeholder detach (§8) | pass; I pass (null-src guard vs second pass); SEC pass (nonce IDL copy reasoning); na: A11Y-partial (aria-hidden removed on restore — pass) |
| 29 | ensureGtag/pushConsentMode (§9) | pass; FR pass (try/catch around gtag); na: A11Y |
| 30 | ctmGranted/persistFirstTouch (§10) | pass; FR pass (Safari-private throws handled); H pass (earlier snapshot wins); na: A11Y |
| 31 | uuidv4 (crypto → getRandomValues → Math.random ladder) | pass; na: A11Y,TI |
| 32 | postRecord REST fire-and-forget (§11) | pass; FR **pass-by-design** (documented: 400 never retried, failure never gates the choice) — but see F009: never exercised by any E2E; na: A11Y |
| 33 | listeners/notifyChange + `anchor-consent-change` CustomEvent (§12) | pass; FR pass (bad subscriber isolated); BC pass (initCustomEvent fallback); na: A11Y |
| 34 | cacheDom/announce/showBanner/showPrefs/closePanels | pass; SV pass (pill-only state class; root pointer-events strategy) |
| 35 | Focus trap (FOCUSABLE/focusableWithin/openWithFocus/releaseFocus/handleKeydown) | pass mechanics (escape-return, first-open memory, zero-layout fallback); the *policy* problem is F008 at unit 1. Escape-inert-in-strict: SV pass-with-note (deliberate, documented) |
| 36 | setConsent + 700ms keyed debounce (§14) | pass; I pass (dup DECISION suppressed, UI response kept); R pass (different decision inside window honored) |
| 37 | refreshCheckboxes/readCheckboxes | pass; na: FR |
| 38 | Iframe guard: iframeRules/observer/neutralizeIframe/scanForIframes/syncObserver (§15) | pass; CV pass (observer never created when nothing gated, disconnects when done); H pass (first-request escape documented); CS pass (empty server array honored as "nothing gated") |
| 39 | onClick delegated dispatcher (§16) | pass; BC pass (closest fallback); GA pass (unknown action falls through) |
| 40 | applyNoticeCopy (innerHTML of notice_body/dns_label) | SEC pass-with-note: relies on wp_kses_post at settings-write time (verified in class-settings.php sanitize; plain-text keys use sanitize_text_field); aria-modal set false for notice — A11Y pass |
| 41 | Public API window.AnchorConsent (get/has/on/off/accept/reject/openPreferences/firstTouch/refresh/version) | pass; CS pass (version:1); na: A11Y |
| 42 | resolveInitialState/earlyBoot/boot ordering | F018@I (no double-init guard: re-execution rebinds document listeners, replaces AnchorConsent, drops subscribers). Others pass; PF pass (footer position ⇒ blocked tags above are parsed) |
| 43 | showGpcNotice/storedGrantedTracking | pass all |

### C. Cookies / storage keys (44–46)
| # | Unit | Result |
|---|------|--------|
| 44 | `anchor_consent` cookie (b64url JSON {id,ts,v,cats}; SameSite=Lax; Secure-on-https; max-age=lifetime) | pass; TI pass (v mismatch ⇒ re-prompt; negative age = skew, not expiry); CS pass (format mirrors PHP; version field present) |
| 45 | `anchor_cmp_ft` sessionStorage (first touch) | pass; R pass (session-scoped, never pre-consent in strict); na: A11Y |
| 46 | Third-party cookie deletion writes (3 domain scopes + host-only) | pass; see unit 24 |

### D. frontend.css units (47–59)
| # | Unit | Result |
|---|------|--------|
| 47 | Token system + color-mix derivation (@supports-guarded) | pass; C pass (design-note ledger doc); BC pass (guard rationale correct: unguarded color-mix would blank at substitution) |
| 48 | Dark scheme: prefers-color-scheme + `[data-acmp-scheme]` override hooks | F005@PC — the override attribute is implemented here but **never emitted by PHP**; `appearance.dark_mode` setting is dead. Media-query path itself: pass |
| 49 | Armor/reset (`#anchor-cmp *`, [hidden] !important, box-sizing) | pass; SC pass (defends against hostile themes) |
| 50 | :focus-visible ring (accent halo + deep ring) | pass A11Y |
| 51 | Layout presets bar/floating/modal/corner ×positions (mobile bottom-sheet base) | pass; O pass (mobile-first base + one min-width block, no specificity war) |
| 52 | Scrim (root ::before; :has() for open prefs; z-index:-1 + panel z-index:auto invariant) | pass; C pass (stacking-context comment); BC pass (no-:has ⇒ degraded, not broken) |
| 53 | Buttons (.anchor-cmp-btn variants) | pass; A11Y pass (contrast computed: worst text 4.74:1, worst UI 3.28:1 per header note) |
| 54 | Switch/checkbox styles + forced-colors knob preservation | pass A11Y (state visible under forced-colors) |
| 55 | Cookie table / mono ledger styles | pass |
| 56 | Pill styles (fixed, 44px targets, mask icon, --bottom-right modifier; bottom-left is the base) | pass |
| 57 | Outside-root styles (.anchor-cmp-link, placeholder, policy tables; currentColor-first philosophy) | pass; H pass (documented: inherit host look, don't project brand) |
| 58 | Media queries: responsive 641px, prefers-reduced-motion (kills all animation/transition/transform), forced-colors, print | pass A11Y across the board |
| 59 | `.anchor-cmp-text`/`.anchor-cmp-copy` selectors in JS with no emitter | pass-with-note (defensive fallback selectors, harmless); F016 (dsar) recorded at unit 17 |

### E. Enqueue / integration / lifecycle (60–75)
| # | Unit | Result |
|---|------|--------|
| 60 | Frontend enqueue (banner::enqueue: source .css/.js, ANCHOR_TOOLS_PLUGIN_URL, inline payload `before`, :root token registration, disabled ⇒ no-op) | F007@SC (bypasses Anchor_Asset_Loader used by ~20 sibling modules ⇒ release .min never served); F012@TI (ver=VERSION '1.0.0', frozen while plugin is 3.9.x ⇒ no cache-busting on asset edits). Conventions pass: source enqueued, plugin URL constant, footer script |
| 61 | Admin enqueue (anchor_settings_enqueue_compliance action ⇒ tab-gated; wp-color-picker + media deps) | pass all (matches unified-settings-page pattern; equivalent of $hook gating) |
| 62 | admin.js (jQuery IIFE: color pickers, page-picker autofill, logo media frame, custom-rules repeater `__INDEX__`) | F019@TC (zero coverage); F006@PC (logo picker feeds logo_id which nothing renders). Convention pass: jQuery IIFE, no ES modules |
| 63 | Module registry entry ('compliance', text-domain anchor-schema, path, class) | pass all; N pass |
| 64 | Anchor_Compliance_Module constructor wiring (12 collaborators, frontend hooks gated by !is_admin, singleton with passive instance()) | pass; I pass (instance() never constructs — documented double-hook hazard); C pass |
| 65 | Activation lifecycle | — no register_activation_hook anywhere; tables/cron created lazily instead (see F014). Recorded under 67/69/68 |
| 66 | Deactivation lifecycle | F002@T (cron event never unscheduled; orphan daily event fires forever after module disable/plugin deactivation) |
| 67 | Uninstall lifecycle | F001@T — **no uninstall.php and no register_uninstall_hook in the entire repo** (verified by grep). Two custom tables (consent log with hashed-IP consent records; DSAR table with names/emails = PII) + anchor_compliance_options + cron persist after uninstall — for a privacy plugin, PII left behind forever |
| 68 | Cron scheduling (constructor: wp_next_scheduled guard + daily purge) | pass I (guarded); T deficit recorded at 66 |
| 69 | maybe_install on admin_init (both tables) | F014@PF — frontend consent POST / DSAR submit before first wp-admin visit hits a missing table; Consent_Log::record() returns false silently: the consent record is lost with no surfaced error |
| 70 | anchor-optimize interaction (both ob_start on template_redirect **priority 1**) | F015@SC — nesting order is registration order (compliance registered last in the registry ⇒ inner buffer ⇒ its rewrite runs first, optimize wraps after). Currently benign (optimize touches only `<img`), but the ordering is unpinned and silently flips if the registry is reordered |
| 71 | code_snippets interaction (anchor_code_snippet_output filter, metabox, save, notices) | pass; SC pass (bridge reuses blocker's JSON-LD guard per commit dbac592; byte-for-byte parity tested) |
| 72 | anchor-translate (prio 0) / anchor-webinars (nocache) on template_redirect | pass (translate runs before any buffer starts; webinars only sets nocache headers) |
| 73 | VERSION constant '1.0.0' | see F012 at unit 60 |
| 74 | .min serving strategy | see F007 at unit 60 |
| 75 | Committed .min files check | **pass** — `git ls-files` shows zero tracked *.min.* anywhere (the e2e *.spec.min.js seen on disk are untracked local build outputs; .gitignore lines 6–8 cover them). Brief premise "check none committed": confirmed clean |

### F. Test-coverage census (76–91)
For each PHPUnit file: behaviors covered ✔ / notable untested public behavior ✘.
| # | Unit | Result |
|---|------|--------|
| 76 | test-compliance-banner.php (19 tests) | ✔ payload posture/patterns/signal-map/GPC, brand inherit ×4, render buttons/toggles/disabled/pill class, contrast ink both ways, enqueue source+inline-before+root tokens+disabled-no-op. ✘ shortcode_consent_link, GPC-notice markup, footer links, show_pill=false, safe_hex vs malicious site-config value ⇒ F017@TC |
| 77 | test-compliance-blocker.php (40 tests) | ✔ exhaustive (parser edge cases, fail-open, idempotence, should_run matrix). No directive |
| 78 | test-compliance-consent-mode.php (7) | ✔ signal map, postures, GPC, stored, emit order/suppression. ✘ wait_for_update value emission — noted, below directive threshold (single scalar) — counted pass-with-note |
| 79 | test-compliance-consent-state.php (9) | ✔ full round-trip/expiry/version/GPC matrix. pass |
| 80 | test-compliance-cookie-policy.php (4) | ✔ table/attr/disabled/escaping. pass (small class) |
| 81 | test-compliance-dsar.php (18) | ✔ install, validation, rate-limit incl. case/% bypass, honeypot, nonce, privacy bridge, mail failure, deadline pinning, IPv6 /64. pass |
| 82 | test-compliance-geo.php (13) | ✔ header ladder, malformed/XX, strict list configurable, IPv6 cache key. pass |
| 83 | test-compliance-log.php (7) | ✔ install/insert/no-raw-IP/version/disabled/query/purge. pass |
| 84 | test-compliance-registry.php (11) | ✔ services, overrides, consent-mode exclusion, custom rules, filter. pass |
| 85 | test-compliance-rest.php (10) | ✔ route, validation, dedupe, disabled-log 200, hook order. pass |
| 86 | test-compliance-settings-ui.php (6) | ✔ sections, geo readout, warning, service table, namespacing, nonce. pass |
| 87 | test-compliance-settings.php (5) | ✔ boot, defaults, merge, sanitize clamp, tab. pass (thin but proportionate; deeper sanitize edges exercised via blocker/banner tests) |
| 88 | test-compliance-snippets-bridge.php (17) | ✔ gating matrix, mu-plugin, JSON-LD, byte parity with blocker, save guards. pass |
| 89 | frontend.js runtime coverage (unit or E2E) | F009@TC — **zero**: no compliance spec exists in e2e/ (only event-*/gallery-*/optimize-*/purchase specs); the 1819-line consent runtime (cookie codec, activation, focus trap, GPC, relax tier, iframe observer) is untested in any executable form |
| 90 | E2E infra (.wp-env.json, seed, playwright) | exists and healthy for events — the gap is the missing compliance spec (counted at 89) |
| 91 | admin.js coverage | F019@TC (recorded at unit 62) — repeater indexing off DOM count is exactly the kind of logic a spec catches |

---

## CHALLENGES TO THE BRIEF
1. "are the two custom DB tables and options cleaned up on uninstall anywhere in the repo?" — **No.** No uninstall.php, no register_uninstall_hook, no register_activation/deactivation_hook anywhere (grep across repo). ⇒ F001/F002.
2. "check none are committed for this module [.min]" — none tracked for this module **or anywhere**; e2e/*.spec.min.js files on disk are untracked local build outputs (gitignored).
3. "jQuery IIFE, no ES modules" convention — frontend.js is deliberately **vanilla ES5** (documented header: runs in footer before jQuery may load; a parse error would hard-brick consent). Verified: no arrow fns/let/const/template literals/spread/async. This is a justified, documented deviation, not a violation. admin.js follows the jQuery IIFE convention.
4. Brief's "banner bar, preferences modal, each tab/toggle" — the preference center has no tabs; it is a single scrolling ledger with four category rows. Census reflects the real UI.

## DIRECTIVES (20)
See final report; IDs F001–F019 + F004, severities: high F001 F003 F009; medium F002 F005 F006 F007 F008 F011 F014; low F004 F010 F012 F013 F015 F016 F017 F018 F019 F020.

[F001] (uninstall lifecycle × Terminality) — «A plugin that creates persistent stores must destroy them on uninstall; a *privacy* plugin doubly so.» Instance: no uninstall.php / register_uninstall_hook anywhere in the repo; consent-log table, DSAR table (names+emails = PII), anchor_compliance_options, and the daily cron all survive uninstall (tables created in anchor-compliance/includes/class-consent-log.php:29, class-dsar.php:52). Fix-class: add repo-root uninstall.php that drops both tables, deletes the option, and wp_clear_scheduled_hook. Severity: high.
[F002] (deactivation × Terminality) — «Every wp_schedule_event needs a matching unschedule on the way out.» Instance: anchor-compliance/anchor-compliance.php:99–101 schedules Anchor_Compliance_Consent_Log::CRON_HOOK; nothing ever unschedules it — disabling the module orphans a daily event forever. Fix-class: clear the hook in a deactivation hook and when the module toggle turns off. Severity: medium.
[F003] (relax tier × Gating-Axis) — «A client-side relaxation may only relax what the server's *configured* strict set permits.» Instance: assets/frontend.js:543–567 clientCountryIsStrict() hardcodes a timezone heuristic and never reads D.strictCountries — Cyprus (Asia/Nicosia, EU), Brazil outside America/Sao_Paulo (America/Manaus, Fortaleza, …), EU overseas territories (America/Martinique, Indian/Reunion), and any admin-added strict country are relaxed to opt-out whenever server geo fails, with allow_client_relax defaulting to true. Fix-class: derive the tz→maybe-strict decision from D.strictCountries (or disable relax when the configured list differs from the built-in default). Severity: high.
[F004] (payload strictCountries × Provenance→Consumption) — «Don't ship payload keys nothing reads.» Instance: class-banner.php:128 emits strictCountries on every page; frontend.js never references it. Fix-class: consume it in the relax tier (the F003 fix) or drop the key. Severity: low.
[F005] (dark_mode setting × Provenance→Consumption) — «A stored setting must reach the surface it configures.» Instance: appearance.dark_mode ('auto'|'light'|'dark') sanitized at class-settings.php:186 and fully supported by frontend.css ([data-acmp-scheme] overrides, lines 129–174), but class-banner.php::render() never emits data-acmp-scheme — the admin's forced light/dark does nothing. Fix-class: print data-acmp-scheme="{dark_mode}" on #anchor-cmp when not 'auto'. Severity: medium.
[F006] (logo_id × Provenance→Consumption) — «Don't build admin UI for a setting no renderer consumes.» Instance: admin.js:39–78 full media-frame picker; class-settings.php:187 sanitizes logo_id; no PHP/CSS/JS ever renders the logo. Fix-class: render the logo in the banner heading or remove the setting+picker. Severity: medium.
[F007] (frontend enqueue × Sibling-Coherence/Cost) — «Use the shared asset loader so release builds serve the minified variant.» Instance: class-banner.php:237–238 and class-settings.php:292–293 concatenate ANCHOR_TOOLS_PLUGIN_URL directly while ~20 sibling modules use Anchor_Asset_Loader::url(); the release ZIP's frontend.min.js/.min.css are built but never served — 1819-line JS + 1586-line CSS ship unminified on every front-end page. Fix-class: route the four enqueues through Anchor_Asset_Loader::url(). Severity: medium.
[F008] (strict banner × Accessibility/Honesty) — «aria-modal=true plus a Tab trap must come with real modality: inert/scrim-covered background for everyone.» Instance: class-banner.php:315 sets role=dialog aria-modal=true and frontend.js:1752–1756 traps focus in strict posture, but bar/floating/corner presets draw no scrim and the page stays pointer-interactive — mouse users browse freely while keyboard/SR users are hard-walled (WCAG 2.1.2 inequity; the modality claim is untrue). Fix-class: either show the scrim + scroll lock in strict posture for all presets, or drop aria-modal/trap to match the non-blocking visual. Severity: medium.
[F009] (frontend.js runtime × Test-coverage) — «The most compliance-critical executable in the module must have at least one executable test.» Instance: no compliance spec in e2e/ (only event/gallery/optimize/purchase specs); zero unit or E2E coverage of the 1819-line runtime — banner flows, cookie write/read, script/iframe activation, focus trap, GPC, relax tier, REST post. Fix-class: add e2e/compliance-banner.spec.js covering accept/reject/customize/pill/cookie persistence + a blocked-script activation assertion. Severity: high.
[F010] (writeConsentCookie × Failure/Recovery) — «After persisting a choice, verify it persisted before telling the user it did.» Instance: frontend.js:336–357 returns true after document.cookie assignment without readback; with cookies blocked the UI announces "preferences saved", hasChoice=true for the pageview, and the banner silently returns next page. Fix-class: re-read the cookie after write; on failure announce a "could not be saved" message. Severity: low.
[F011] (show_pill=false × Reversibility) — «Withdrawing consent must stay as reachable as giving it.» Instance: with appearance.show_pill unchecked and no [anchor_consent_link] placed, a decided visitor has no re-entry surface at all (closePanels() hides the root; nothing reopens prefs); the settings UI does not warn. Fix-class: warn/require a consent-link placement when the pill is disabled, or keep a minimal re-entry affordance. Severity: medium.
[F012] (asset version × Temporal-Integrity) — «Asset version strings must change when the asset changes.» Instance: all four enqueues use Anchor_Compliance_Module::VERSION frozen at '1.0.0' (anchor-compliance.php:17) while the plugin ships 3.9.x releases; future frontend.js/css edits will not cache-bust. Fix-class: tie ver to the plugin version constant or filemtime via Anchor_Asset_Loader::path(). Severity: low.
[F013] (placeholder accept × Honesty) — «Audit records should name the surface that produced them.» Instance: frontend.js:1447 logs a placeholder "Accept & Load" grant as method 'preference_center'; the log cannot distinguish embed-placeholder grants from deliberate preference-centre saves (VALID_METHODS has no 'placeholder'). Fix-class: add a 'placeholder' method to the shared JS/PHP method vocabulary. Severity: low.
[F014] (maybe_install × Precondition/Forward-path) — «A table a frontend request writes to must exist before the first frontend request, not after the first admin visit.» Instance: anchor-compliance.php:90–91 installs both tables only on admin_init; a consent POST or DSAR submit before any wp-admin visit hits a missing table and Consent_Log::record() (class-consent-log.php:87) returns false silently — the consent record is lost. Fix-class: also run maybe_install on module bootstrap version-gated by a stored schema version (cheap option check per request). Severity: medium.
[F015] (buffer ordering × Sibling-Coherence) — «Two rewriters of the same response must have an explicit, pinned order.» Instance: compliance blocker (anchor-compliance.php:114) and anchor-optimize rewriter (class-frontend-rewriter.php:49) both hook template_redirect at priority 1; nesting depends on module-registry registration order (compliance last ⇒ inner buffer ⇒ rewrites first). Currently benign, silently flips on a registry reorder. Fix-class: give the compliance buffer a distinct priority (e.g. 0 or 2) chosen deliberately, with a comment stating the required order. Severity: low.
[F016] (DSAR form × Population/Dark) — «Every frontend surface the module renders should be styled by the module's stylesheet.» Instance: [anchor_privacy_request] emits anchor-cmp-dsar-form/-field/-notice/-error/-success classes with zero matching rules in frontend.css (grep: none) — the form renders raw next to the fully-designed banner (honeypot survives via inline style, class-dsar.php:334). Fix-class: add a DSAR section to frontend.css. Severity: low.
[F017] (banner render/shortcodes × Test-coverage) — «Each public render path deserves an assertion.» Instance: tests/test-compliance-banner.php never exercises shortcode_consent_link(), the GPC notice markup, footer-link rendering, show_pill=false suppression, or safe_hex() against a malicious anchor_site_config_options value. Fix-class: add ~5 targeted tests to the existing file. Severity: low.
[F018] (IIFE boot × Idempotence) — «A script that binds document-level listeners needs a re-execution guard.» Instance: frontend.js has no window.AnchorConsent-already-defined check; a second execution (concatenating optimizer, double print) rebinds click+keydown, replaces the API object, and drops registered subscribers. Fix-class: early-return when window.AnchorConsent && AnchorConsent.version. Severity: low.
[F019] (admin.js × Test-coverage) — «Repeater index arithmetic is exactly what a spec catches.» Instance: assets/admin.js custom-rules repeater (rows indexed off live DOM count, line 97) and logo picker have no test of any kind. Fix-class: cover via the compliance E2E settings-tab spec (piggyback on F009's spec file). Severity: low.
[F020] (payload i18n × Provenance→Consumption/Cost) — «Send the runtime only the strings the runtime reads.» Instance: class-banner.php:138 ships all 14 content strings in payload()['i18n']; frontend.js reads 8 (notice_body, dns_label, saved/unblocked/gpc/dns_confirmation, placeholder_text/button) — heading, body, accept/reject/customize/save labels ride along dead on every page. Fix-class: whitelist the runtime keys in payload(). Severity: low.
