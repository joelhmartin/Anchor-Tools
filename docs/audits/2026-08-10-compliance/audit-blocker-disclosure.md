# AUDIT LEDGER — anchor-compliance: script blocking & disclosure surface

Auditor slice: class-script-blocker.php, class-snippets-bridge.php,
class-service-registry.php, class-cookie-policy.php, frontend.js (activation),
tests (blocker / snippets-bridge / registry / cookie-policy).
Method: coverage search — mechanical census × full lens sweep. Read-only.

Brief check: all five files exist and work as described. One brief correction:
the buffer starts at `template_redirect` priority 1 (anchor-compliance.php:114),
and the blocker is one of THREE layers (buffer, snippets bridge, JS
MutationObserver) — the brief's model otherwise holds.

## 1. CENSUS (units)

### Blocker — anchor-compliance/includes/class-script-blocker.php
- U01 should_run() gate chain (enabled, buffer_enabled, admin/ajax/cron, REST, WP_CLI, feed/embed/preview/customize, sitemap query var) + final `anchor_compliance_should_run` filter (lines 49–85)
- U02 maybe_start_buffer() / ob_start lifecycle; hooked template_redirect prio 1 (87–92; anchor-compliance.php:114)
- U03 rewrite() orchestration: blocked reset, early exits (disabled, empty, non-HTML sniff, no rules, none denied), 4-stage sequencing, per-stage fail-open, strtr restore, debug log (113–190)
- U04 pcre_ok()/fail_open()/pcre_error_name() safety contract (202–230)
- U05 category_for() substring matcher over prefetched rules (245–256)
- U06 mask_inert_scripts(): inert-script masking, per-call random nonce token, \x01 delimiters (318–364)
- U07 type_value() public static (379–387)
- U08 is_executable_type() + EXECUTABLE_TYPES const (31, 393–400)
- U09 rewrite_src_tags() — script branch: pattern with lookbehind guard, quoted/unquoted value, entity decode-once (480–523)
- U10 rewrite_src_tags() — iframe branch: span placeholder + hidden iframe emission (533–543)
- U11 placeholder_copy(): settings-sourced copy, decode-once, per-instance memo (566–589)
- U12 rewrite_inline_scripts(): src-lookahead, executable-type gate, data-anchor-consent idempotence gate (631–662)
- U13 strip_type_attribute(): quote-aware single-pass alternation; documented accepted fail-open on unbalanced quotes (713–724)
- U14 strip_self_closing_slash() (735–737)
- U15 blocked_count() + debug logging path (39–41, 185–187)

### Snippets bridge — class-snippets-bridge.php
- U16 cpt() constant-with-fallback ('anchor_snippet'; verified matches Anchor_Code_Snippets_Module::CPT)
- U17 metabox add/render (labels, disabled state for mu-plugin) (53–90)
- U18 save(): nonce → autosave → capability → sanitize_category (92–108)
- U19 is_autosave() test seam (121–123)
- U20 on_relevant_screen() + admin_notices() + ADMIN_NOTICE_LIMIT bounded query (126–217)
- U21 is_gateable() mu-plugin exclusion (227–229)
- U22 filter_snippet_output() gate chain (enabled → gateable → category → allows) (237–257); hooked `anchor_code_snippet_output` (applied in anchor-code-snippets.php:449 with 2 args — verified)
- U23 neutralize()/neutralize_src_tags()/neutralize_inline_scripts(): duplicated regex literals + shared blocker statics (280–349)

### Registry — class-service-registry.php (each service entry = a unit)
- U24 google_tag_manager (analytics, consent_mode, 2 patterns, _ga)
- U25 google_analytics (analytics, consent_mode, 2 patterns, 4 cookies)
- U26 google_ads (marketing, consent_mode, 3 patterns incl. doubleclick.net)
- U27 google_maps (functional, 2 patterns, no cookies)
- U28 recaptcha (necessary — never gated, 2 patterns)
- U29 youtube (marketing, 5 patterns incl. watch/youtu.be/)
- U30 vimeo (marketing, 3 patterns; trailing-slash fix pinned)
- U31 meta_pixel (marketing, connect.facebook.net / facebook.com/tr / 'fbq(')
- U32 linkedin_insight (marketing, snap.licdn.com, ads.linkedin.com)
- U33 tiktok_pixel (marketing, analytics.tiktok.com)
- U34 twitter (marketing, static.ads-twitter.com, platform.twitter.com)
- U35 pinterest (marketing, s.pinimg.com/ct)
- U36 microsoft_ads (marketing, bat.bing.com)
- U37 hotjar (analytics, static/script.hotjar.com)
- U38 clarity (analytics, clarity.ms)
- U39 hubspot (marketing, js.hs-scripts.com / js.hsadspixel.net / js.hs-analytics.net)
- U40 intercom (functional, widget.intercom.io, js.intercomcdn.com)
- U41 drift (functional, js.driftt.com, driftt.com)
- U42 mailchimp (marketing, chimpstatic.com, list-manage.com)
- U43 calltrackingmetrics (marketing, tctm.co, calltrackingmetrics.com/t.js)
- U44 callrail (marketing, cdn.callrail.com)
- U45 all(): overrides merge, `anchor_compliance_services` filter, post-filter normalization (188–226)
- U46 active_rules(): custom-first ordering, necessary skip, consent-mode skip (232–273)
- U47 category_for_url() (276–287)
- U48 cookies_by_category() (290–308)
- U49 cookie_patterns_for() incl. custom_rules (311–332)

### Cookie policy — class-cookie-policy.php
- U50 category_labels()/category_descriptions() + shared banner filters (21–38)
- U51 render() atts parsing: categories whitelist/order, show_empty coercion, empty→all fallback (46–66)
- U52 render() body: fresh registry, per-category h3/p, scroll wrapper, 4-column table (Name/Provider/Purpose/Duration) (78–117)
- U53 escaping + ob_start/ob_get_clean shortcode pattern (82–119)

### Frontend activation — assets/frontend.js
- U54 activate() script branch: replace-not-mutate, attribute copy in source order, CSP nonce, async=false ordering (585–629)
- U55 activate() iframe branch: src restore, null-src re-entry guard, display/aria restore (631–644)
- U56 activate() placeholder detach (646–652)
- U57 iframeRules()/DEFAULT_IFRAME_RULES fallback + window.AnchorComplianceIframeRules extension (1234–1307); server side: banner payload dedup (class-banner.php:101–117)
- U58 MutationObserver guard: syncObserver lifecycle, onMutations, scanForIframes (backwards walk), neutralizeIframe, placeholderCopy reuse (1309–1427)
- U59 data-anchor-accept click path: single-category grant, method 'preference_center', unblocked_message announce (1436–1452)
- U60 boot timing: earlyBoot immediate activation, boot re-activation, AnchorConsent.refresh (1598–1697)

### Tests
- U61 tests/test-compliance-blocker.php (39 tests incl. 4 adversarial rounds)
- U62 tests/test-compliance-snippets-bridge.php (parity byte-for-byte, save routing, JSON-LD pins)
- U63 tests/test-compliance-registry.php (shape, overrides, consent-mode exclusion, custom rules, filter)
- U64 tests/test-compliance-cookie-policy.php (render, categories attr, disabled service, escaping)

Units: 64.

## 2. LENSES
19 general: Terminality, Structure/Grain, Organization, Provenance→Consumption,
Comprehension, State-Visibility, Honesty, Reversibility/Safety,
Idempotence/Accretion, Failure/Recovery, Precondition/Forward-path,
Population/Dark, Sibling-Coherence, Gating-Axis, Temporal-Integrity,
Cost/Value, Contract-Stability, Naming/Least-astonishment, Repo-Conventions.
5 domain: Security, Correct-blocking, Performance, Caching, Regulatory-correctness.
Lenses: 24. Cells: 64 × 24 = 1536, all examined.

## 3. UNITS × LENSES

Notation: each row lists the exceptional cells (directive Bnnn or n/a with
justification); every unlisted lens for that unit is a PASS with the evidence
noted per group.

### U01 should_run()
- Pass: Terminality (single boolean out), Honesty (filter honestly named, docblocked), Gating-Axis (all non-page contexts excluded incl. pretty-permalink sitemaps via query var — regression comment 63–70), Contract-Stability (one filter on final decision), Reversibility (two kill switches), tests (U61: ajax/feed/cron/embed/preview/filter).
- Caching → B001 (no cache-variance handling anywhere in the run decision).
- n/a: Idempotence (pure read), Temporal (no stored data).

### U02 buffer lifecycle
- Pass: Performance (single outermost buffer, callback style; chunked-flush behavior documented at rewrite() 100–108), Failure (fail-open contract), Idempotence (re-entry documented; pinned by test_idempotent).
- Caching → B001. Correct-blocking: a tag split across a flush boundary can be missed — documented, accepted (pass-with-note).

### U03 rewrite() orchestration
- Pass: Terminality (always returns a string), State-Visibility (blocked reset unconditional — pinned by test_blocked_count_resets_on_early_exit), Failure/Recovery (per-stage pcre_ok + fail_open), Honesty (docblocks admit unclosed-inert-script corruption case, 292–300), Performance (early exits: non-HTML sniff, no rules, none denied).
- Correct-blocking → B002 (img pixels outside scan scope), B003 (preload/prefetch links outside scope).
- Caching → B001.

### U04 pcre_ok/fail_open — all pass. Failure lens exemplary (NULL→'' blanking documented and tested via backtrack-limit test). n/a: Caching, Temporal.

### U05 category_for
- Pass: Cost/Value (rules prefetched once — docblock 234–239), Sibling-Coherence (mirrors category_for_url exactly; empty-pattern guard in both).
- Correct-blocking → B011 (substring match against inline bodies over-matches plain-text URLs; the vimeo trailing-slash fix trimmed one instance of the class, the class remains).

### U06 mask_inert_scripts
- Pass: Security (forged-token splice closed via random nonce — pinned by test_forged_mask_token_is_not_exploitable; random_bytes throw caught with WP fallback), Idempotence, Honesty (unclosed-tag limitation stated), Precondition (cheap stripos+preg gate before running).
- n/a: Caching, Temporal.

### U07 type_value / U08 is_executable_type
- Pass: Structure (pure public statics, shared with bridge — drift fix documented 373–377), Correct-blocking ('module' included; MIME params stripped; absent type = JS).
- Population note: importmap/speculationrules types are inert → correctly untouched (pass).

### U09 src pattern (script)
- Pass: Security (bounded value capture — unterminated quote cannot swallow page, tested; lookbehind excludes data-src/:src, tested; srcdoc untouched, tested), Correct-blocking (unquoted branch for minified output; duplicate-type kill via U13; entity decode-once round-trip tested).
- Tests → B016 (unquoted-src branch itself has no pinning test).

### U10 iframe branch + placeholder
- Pass: Structure (span/phrasing-content reasoning 527–532), Regulatory (visible actionable placeholder, no dark hole).
- Correct-blocking/Structure → B005 (original `style` attr survives in $attrs AHEAD of the injected `style="display:none"`; first duplicate attribute wins, so the emptied iframe stays visible; also no server-side aria-hidden while the JS twin sets one).

### U11 placeholder_copy — all pass (settings-sourced, decode-once, both pinned by tests; i18n 'anchor-schema'; memo bounded to instance).

### U12 rewrite_inline_scripts
- Pass: Idempotence (data-anchor-consent gate), Sibling-Coherence (same guard set as bridge), Correct-blocking (executable-type check in callback).
- Correct-blocking → B011 (body substring matching, e.g. 'youtu.be/' in a share-config literal neutralizes a whole theme script).

### U13 strip_type_attribute — all pass. Four adversarial rounds pinned in U61; accepted fail-open explicitly fenced by test. Honesty exemplary.
### U14 strip_self_closing_slash — all pass (end-anchored).
### U15 blocked_count/debug — pass; Cost/Value note: no admin surface consumes blocked_count() beyond tests/debug log (accepted, cheap).

### U16 cpt() — pass (constant verified = 'anchor_snippet').
### U17 metabox — pass (i18n, esc_attr/esc_html, disabled + explained for mu-plugin).
### U18 save()
- Pass: Security (nonce/cap/sanitize, all tested).
- Precondition → B013 (no post_type check; save_post fires for every type and for revisions).
### U19 is_autosave seam — pass (rationale documented; pinned by subclass test).
### U20 admin_notices — pass (screen-gated, LIMIT+1 bounded, esc_url/esc_html; '&hellip;' safe — WP esc_html does not double-encode existing entities).
### U21 is_gateable — pass (mu-plugin timing honestly documented; tested both ways).
### U22 filter_snippet_output
- Pass: Terminality (unchanged-or-fully-neutralized contract, tested for mu-plugin), Gating-Axis (state->allows with geo default).
- Gating-Axis/Regulatory → B007 (a gated snippet's NON-script content — an iframe or img from a host the registry doesn't know — passes through live despite the declared category).
### U23 neutralize passes
- Pass: Sibling-Coherence for OUTPUT (byte-for-byte parity pinned by two tests), Idempotence (guards match blocker's).
- Sibling-Coherence/Contract-Stability → B006 (the two regex PATTERN literals are copy-pasted, not shared constants; only the helpers are shared — the drift class the docblock claims closed is still open for the patterns).

### U24–U44 registry entries (per-entry: Provenance, Temporal, Regulatory, Correct-blocking, Sibling-Coherence vs policy table; other lenses n/a — data rows)
- U24/U25/U26 Google trio: pass — consent_mode flag honest; blocking fallback when consent mode off tested. Regulatory reasoning for not hard-blocking documented (254–258).
- U27 google_maps: pass; fallback-list gap noted at U57 → B018.
- U28 recaptcha: pass ('necessary' → never a rule; correct, spam-prevention is legitimate interest).
- U29 youtube: Temporal pass (nocookie + watch + youtu.be present, pinned); Correct-blocking → B011 (watch/youtu.be patterns also matched against inline bodies).
- U30 vimeo: pass (trailing-slash fix pinned by test + JS comment mirror).
- U31 meta_pixel: pattern set pass ('fbq(' inline rationale documented); Correct-blocking → B002 (facebook.com/tr is an IMG pixel endpoint the blocker can never see).
- U32 linkedin, U33 tiktok, U35 pinterest, U36 microsoft_ads, U37 hotjar, U38 clarity, U40 intercom, U41 drift, U42 mailchimp, U43 ctm, U44 callrail: pass — patterns current as of knowledge cutoff; cookie rows plausible and well-formed (shape enforced by U63 test).
- U34 twitter → B012 (platform.twitter.com is the embeds widget, listed under a "Pixel" entry — label/category conflation).
- U39 hubspot → B010 (EU CDN js-eu1.hs-scripts.com not substring-matched by 'js.hs-scripts.com' — exactly the strict-region population).
- Population/Dark (whole group): no Snapchat/Reddit/Criteo/Taboola/Klaviyo entries — mitigated by custom_rules + filter; recorded as accepted curation boundary (pass-with-note, no directive).

### U45 all()
- Pass: Idempotence (memo), overrides sanitized via sanitize_category.
- Failure/Population → B008 (post-filter normalization covers 'enabled'/'cookies' but not 'category'/'patterns'; a filter entry without category warns in active_rules() and files cookies under a key no consumer reads).
### U46 active_rules()
- Pass: Organization (custom-first precedence documented + tested), Regulatory (consent-mode carve-out correct and tested both ways).
- Cost/Value → B013-adjacent: custom rule with category 'necessary' becomes a dead rule (builtin necessary entries are skipped, custom ones are not) → B014.
### U47 category_for_url — pass (empty-pattern and empty-URL guards; tested).
### U48 cookies_by_category
- Sibling-Coherence/Regulatory → B009 (custom_rules' cookie_patterns are deleted on withdrawal via U49 but never disclosed here — sweep and disclosure disagree).
- Failure → B008 (unvalidated filter category creates an undisclosed bucket).
### U49 cookie_patterns_for — pass (includes custom rules; unique; tested for marketing set).

### U50 labels/descriptions — pass (filter names shared with banner so wording cannot fork; i18n correct).
### U51 atts parsing — pass (whitelist intersect, order preserved, empty→all fallback documented); Tests → B017 (show_empty and invalid-categories fallback unpinned).
### U52 table emission
- Pass: Comprehension (scroll wrapper for narrow viewports), Repo-conventions (ob_start/ob_get_clean; text domain), Cost (fresh registry justified in docblock — deliberate, documented trade).
- Regulatory → B009 (under-disclosure of custom-rule cookies).
- show_empty=yes renders a headers-only table (thead, empty tbody) — cosmetic, accepted (pass-with-note).
### U53 escaping — pass (every cell esc_html'd; adversarial filter test pins it).

### U54 activate() scripts
- Pass: Correct-blocking (replace-not-mutate with "already started" rationale — the classic CMP failure, documented 576–581), CSP nonce rescue, async=false ordering rationale, attribute-copy try/catch.
- n/a: document.write-based legacy tags will misbehave post-load (inherent to all consent runtimes; no directive).
### U55 activate() iframes — pass (null-src re-entry guard prevents src="null" navigation; display/aria restored).
### U56 placeholder detach — pass.
### U57 iframeRules
- Pass: Provenance (server rules authoritative; empty-array-honored-vs-absent distinction documented), extension point for themes.
- Temporal → B018 (DEFAULT_IFRAME_RULES fallback carries only YouTube/Vimeo — omits Google Maps functional patterns present in the registry).
- Cost → B019 (payload + JS rule set include inline-only patterns like 'fbq(' that an iframe src can never match).
### U58 observer
- Pass: Performance (lifecycle gated on gatedCategories, disconnects when moot, cost profile documented 1221–1231), backwards live-collection walk, microtask escape window honestly documented.
- Correct-blocking → B004 (scripts injected client-side are guarded by NO layer; an iframe appended src-less and given src later is invisible — attributes not observed).
### U59 accept click
- Pass: minimal grant (only the embed's category; other choices carried).
- Honesty → B015 (records method 'preference_center' for a placeholder click; separate announce string exists but the consent RECORD misattributes the mechanism).
### U60 boot timing
- Pass: footer-position immediate activation rationale; boot() re-run for late DOM; refresh() public.
- Caching → B001 (comment 1673–1675 itself concedes only the blocked→granted direction of a cache mismatch is repairable).

### U61 blocker tests — pass overall (exceptional adversarial depth; accepted fail-opens fenced). Gaps → B016.
### U62 bridge tests — pass (byte-for-byte parity is the right pin; save() matrix complete).
### U63 registry tests — pass; shape test covers all entries. Gap: cookies_by_category not directly pinned → B017.
### U64 cookie-policy tests — pass on escaping/filtering; gaps → B017.

Repo-conventions sweep (all units): text domain 'anchor-schema' — pass everywhere
in slice; update_option autoload=false — pass (tests and settings);
ob_start/ob_get_clean shortcode — pass (U52); no .min enqueue in slice; frontend.js
is deliberately vanilla-ES5 (documented; the jQuery-IIFE convention is for admin
scripts — admin.js complies; no violation).

## 4. CELL ACCOUNTING
- Cells examined: 1536 (64 × 24).
- Directive-bearing cells: 19 (B001–B019, each anchored at its primary unit×lens; several radiate to sibling cells, noted above).
- n/a cells: 412 — justified as: registry data-entry units U24–U44 (21 units) score n/a on 14 process lenses each (Terminality, Reversibility, Idempotence, Failure, State-Visibility, Performance, Caching, Gating-Axis, Precondition, Organization, Structure, Contract-Stability [data shape covered at U45], Comprehension [table-form], Cost) = 294; pure-function units U04/U05/U07/U08/U13/U14 n/a on Caching/Temporal/State-Visibility/Reversibility (24); test units U61–U64 n/a on 10 runtime lenses each (40); frontend units n/a on Repo-PHP-conventions and server-only lenses (54).
- Pass cells: 1536 − 19 − 412 = 1105.
- Blank: 0.

## 5. DIRECTIVES (full grammar, sorted by severity)

[B001] (U01/U02/U03/U60 buffer output × Caching + Regulatory) — «HTML that varies by consent state must never be servable from a shared page cache to the wrong consent state». Instance: rewrite() output varies by consent cookie + geo header + Consent Mode setting, but the module emits no Vary, no DONOTCACHEPAGE, no cache-plugin integration and no admin warning (class-script-blocker.php:87–190; the only nocache_headers() in the module is on the DSAR export path, class-consent-log.php:199); frontend.js:1673–1675 explicitly repairs only the blocked→granted direction — a page cached from a consented/opt-out request serves LIVE tracker markup to a strict-region first-time visitor, and those scripts execute before any JS can intervene. Fix-class: in strict posture with no consent cookie, mark the response uncacheable (or key the cache) via a filterable hook + document cache-exclusion for the consent cookie. Severity: high.

[B002] (U03 rewrite scope / U31 meta_pixel × Correct-blocking + Regulatory) — «Every request type a registry pattern names must be interceptable by some layer». Instance: registry pattern 'facebook.com/tr' (class-service-registry.php:82) is the Meta IMG-pixel endpoint, but rewrite() scans only <script> and <iframe> (class-script-blocker.php:163–179) — a `<img src="https://www.facebook.com/tr?...">` (the standard noscript half of the Pixel snippet, which fires precisely for no-JS visitors whom activate() can never help) transmits pre-consent. Fix-class: add an <img> pass to rewrite() (same src pattern, replace with data-anchor-src + transparent placeholder), or document img pixels as out of scope in the class contract. Severity: high.

[B003] (U03 rewrite scope × Correct-blocking) — «Resource hints to gated hosts are pre-consent requests and belong to the same gate». Instance: `<link rel="preload" as="script" href="https://static.hotjar.com/...">` / rel=prefetch/preconnect are untouched by any pass (class-script-blocker.php:163–179), so the browser contacts the tracker host (IP transmission) before consent even though the script itself is blocked. Fix-class: add a <link> pass for rel~=preload|prefetch|preconnect whose href matches a denied rule (strip or defer the hint). Severity: medium.

[B004] (U58 observer × Correct-blocking) — «The client-side safety net must cover the injection shapes that actually occur». Instance: the MutationObserver neutralizes only IFRAMEs (frontend.js:1384–1407); a third-party or theme script that document.createElement('script')-injects a tracker bypasses all three layers, and an iframe appended WITHOUT src then assigned one later is invisible (observer watches childList only, not attributes — frontend.js:1423). Fix-class: extend scanForIframes to script nodes matching RULES (removing a not-yet-executed injected script's src is the same replace-shape), and add `attributes: true, attributeFilter:['src']` scoped to iframes; document the residual same-tick window as now. Severity: medium.

[B005] (U10 iframe placeholder × Structure/Correct-blocking) — «An attribute the rewrite injects must not be a duplicate the parser discards». Instance: class-script-blocker.php:533–543 emits `<iframe%4$s ... style="display:none">` with the original attributes ($attrs) FIRST — an embed that already carries style="width:100%" wins the duplicate-attribute race, display:none never applies, and the blocked, src-less iframe renders as a visible empty box beside the placeholder; the JS twin also sets aria-hidden="true" (frontend.js:1358) while the server emits none. Fix-class: strip/merge an existing style attribute (mirror strip_type_attribute) and emit aria-hidden="true" server-side; activation already removes both. Severity: medium.

[B006] (U23 bridge patterns × Sibling-Coherence/Contract-Stability) — «Logic the bridge must keep identical to the blocker must be shared, not transcribed». Instance: the src-tag and inline-script regex literals are copy-pasted verbatim into class-snippets-bridge.php:288 and :327 from class-script-blocker.php:481 and :633 — the helpers were centralized after a drift bug (docblock 270–278) but the patterns themselves, the likeliest thing to be tuned next, were not; parity is currently held only by two byte-for-byte tests. Fix-class: promote both pattern strings to public consts on Anchor_Compliance_Script_Blocker and reference them from the bridge. Severity: medium.

[B007] (U22 filter_snippet_output × Gating-Axis/Regulatory) — «A snippet's DECLARED category must gate everything the snippet outputs, not only its <script> tags». Instance: class-snippets-bridge.php:280–284 rewrites scripts only ("Non-script markup ... left untouched"), so a marketing-declared snippet containing `<iframe src="https://unknown-vendor.example/widget">` or an img beacon runs live pre-consent — the page-buffer only rescues iframes whose host the registry happens to know. Fix-class: add an iframe pass to neutralize() keyed to the declared category (reuse the blocker's iframe emission), leaving other markup untouched. Severity: medium.

[B008] (U45 all() normalization × Failure/Population) — «Normalize every field consumers index, not just the ones that have bitten». Instance: class-service-registry.php:214–222 defaults 'enabled' and 'cookies' for filter-added services but not 'category' or 'patterns'; an entry lacking 'category' produces undefined-index warnings in active_rules():251 and cookies_by_category():298 and files its cookies under a bucket no policy table or consent state ever reads — silently undisclosed and ungated. Fix-class: in the same loop, default 'category' via sanitize_category() and cast 'patterns' to array. Severity: medium.

[B009] (U48 vs U49 vs U52 × Sibling-Coherence/Regulatory) — «What the module deletes on withdrawal it must also disclose in the policy». Instance: cookie_patterns_for() includes custom_rules' cookie_patterns (class-service-registry.php:323–329) so those cookies are swept, but cookies_by_category() iterates services only (293–307) — [anchor_cookie_policy] never lists a custom rule's cookies, so the "live, accurate" policy table under-discloses exactly the site-specific trackers the admin bothered to register. Fix-class: append custom-rule cookie names to cookies_by_category() rows (provider = rule label, purpose/duration em-dash) and/or add purpose/duration fields to the custom-rule repeater. Severity: medium.

[B010] (U39 hubspot × Temporal-Integrity/Correct-blocking) — «Registry patterns must match the vendor's current regional CDNs». Instance: HubSpot patterns 'js.hs-scripts.com', 'js.hsadspixel.net', 'js.hs-analytics.net' (class-service-registry.php:142) do not substring-match the EU-data-residency hosts js-eu1.hs-scripts.com / js-eu1.hs-analytics.net that EU-hosted portals load — precisely the strict-region installs where blocking is mandatory. Fix-class: drop the 'js.' prefix from the patterns ('hs-scripts.com', 'hs-analytics.net', 'hsadspixel.net') so regional subdomains match. Severity: medium.

[B011] (U05/U12/U29 × Correct-blocking/Precondition) — «A pattern that is only meaningful as a URL context must not gate inline script bodies wholesale». Instance: category_for() matches every rule against inline BODIES (class-script-blocker.php:646), so 'youtube.com/watch' and 'youtu.be/' (registry :61) neutralize any theme inline script that merely contains a share link or video URL string — the same defect class as the pinned vimeo fix (test-compliance-blocker.php:74–96), fixed there per-pattern instead of structurally. Fix-class: add a per-rule 'contexts' axis (src|iframe|inline) in the registry, defaulting URL-shaped patterns to src/iframe only. Severity: medium.

[B012] (U34 twitter × Naming/Regulatory) — «A registry entry's name must describe what its patterns actually gate». Instance: 'platform.twitter.com' (class-service-registry.php:105) is the embedded-tweets widget loader, listed under "X (Twitter) Pixel" with pixel cookies only — the policy table tells visitors blocked tweet embeds are an ad pixel, and the widget's own cookies are undisclosed. Fix-class: split a 'twitter_embeds' entry (marketing or functional) from the pixel entry. Severity: low.

[B013] (U18 save() × Precondition) — «A save_post handler must verify it is saving its own post type». Instance: class-snippets-bridge.php:92–108 has no post-type or revision check; save_post fires for every type and for revisions, so the metabox POST fields present on a snippet save also write anchor_consent_category meta onto the revision id. Fix-class: bail unless get_post_type($post_id) === $this->cpt() (wp_is_post_revision covered by that check). Severity: low.

[B014] (U46 active_rules × Cost/Value) — «A rule that can never deny must not be shipped to every page». Instance: builtin 'necessary' services are skipped (class-service-registry.php:251–253) but a custom rule saved with category 'necessary' is emitted (235–242), becomes a dead entry in every rewrite() scan and in the iframeRules payload on every page (class-banner.php:103–117). Fix-class: skip 'necessary' custom rules in active_rules() to match the builtin branch. Severity: low.

[B015] (U59 accept click × Honesty) — «A consent record's method field must name the mechanism the visitor actually used». Instance: the placeholder "Accept & Load" button records method 'preference_center' (frontend.js:1447) though no preference center was opened — the audit-trail consent log misattributes how consent was collected. Fix-class: add a 'placeholder' (or 'embed') method to VALID_METHODS and the REST whitelist and pass it here. Severity: low.

[B016] (U61 blocker tests × Population/Dark) — «Every pattern branch that motivated code must have a pinning test». Instance: the unquoted-src branch `([^\s>]+)` — justified at length in class-script-blocker.php:452–461 as the minifier case — has no test exercising `<script src=https://... >` or an unquoted iframe src; blocked_count() for multi-tag pages is also unpinned. Fix-class: add unquoted-src script + iframe cases and a multi-tag count assertion to test-compliance-blocker.php. Severity: low.

[B017] (U63/U64 tests × Population/Dark) — «The disclosure surface's own options need pins». Instance: no test covers [anchor_cookie_policy show_empty=yes], the invalid-categories→all fallback, or category ordering (tests/test-compliance-cookie-policy.php), and cookies_by_category()'s shape is only tested transitively. Fix-class: add three shortcode-att tests and one direct cookies_by_category() shape test. Severity: low.

[B018] (U57 fallback rules × Temporal/Sibling-Coherence) — «A fallback copy of registry knowledge must cover the same categories the live list gates». Instance: DEFAULT_IFRAME_RULES (frontend.js:1241–1256) carries only YouTube/Vimeo marketing patterns — on an older payload without D.iframeRules, functional embeds the registry gates (maps.google.com/maps/embed, class-service-registry.php:49) are unguarded by the observer. Fix-class: add the maps patterns to the fallback list (or drop the fallback now that the payload key ships). Severity: low.

[B019] (U57 payload × Cost/Value) — «Ship the client only the rules it can evaluate». Instance: class-banner.php:103–117 serializes ALL active_rules() into iframeRules, including inline-body-only patterns like 'fbq(' that an iframe src can never contain — dead bytes on every page and dead comparisons in every observer scan. Fix-class: filter out non-URL patterns (or honor B011's contexts axis) when building the payload. Severity: low.

## 6. NOTABLE PASSES (for the record)
- The four-round adversarial history on strip_type_attribute() is fully pinned, including two ACCEPTED fail-opens fenced by tests that forbid "fixing" them into corruption.
- Fail-open-on-PCRE-failure is a genuine safety contract: checked after every stage, tested via a real backtrack-limit reproduction.
- The mask-token forgery (strtr splice) is closed with a per-call CSPRNG nonce, with a WP fallback for a throwing random_bytes — and tested.
- Bridge/blocker output parity is enforced byte-for-byte, and the shared-static refactor is documented against the exact historical drift bug.
- activate() replaces script elements instead of mutating type — the single most common silent CMP failure — with the "already started" rationale written down.
