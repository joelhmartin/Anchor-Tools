# Anchor Compliance — Design

**Date:** 2026-08-07
**Module key:** `compliance`
**Class:** `Anchor_Compliance_Module`
**Directory:** `anchor-compliance/`

## Purpose

A cookie-consent and privacy-compliance module for the Anchor Tools plugin suite. It renders a
polished, accessible consent banner and preference center, gates third-party tracking behind
consent, emits Google Consent Mode v2 signals, keeps a record of consent, and provides the
front-end surfaces (cookie policy table, privacy-request form, re-consent link) that a client's
privacy page needs.

Target audience is Anchor's client sites: predominantly US businesses, hosted on Kinsta, with
occasional EU traffic. The design therefore defaults to a geo-aware posture rather than choosing
one regime.

## Regulatory model

Two postures, selected per visitor:

| Posture | Regions | Behavior |
|---|---|---|
| **Strict (opt-in)** | EEA, UK, Switzerland, Brazil, plus a configurable list | Nothing non-essential loads or stores until the visitor chooses. Banner is prominent. |
| **Opt-out (notice)** | United States and everywhere else | Scripts load immediately. Banner is a notice offering "Do Not Sell or Share My Personal Information". |

Categories are the four-category convention used by Cookiebot, OneTrust, and Complianz:

- **Necessary** — always on, no toggle, shown locked
- **Functional**
- **Analytics**
- **Marketing**

### Explicitly out of scope

- **IAB TCF / TCF 2.2** — requires vendor certification and is the wrong fit for agency client sites.
- **Privacy-policy prose generation** — shipping legal text creates liability and every client's
  counsel rewrites it. The module links to a policy; it does not author one.

## Consent resolution order

Evaluated on every request, first match wins:

1. **Global Privacy Control.** If `Sec-GPC: 1` (server) or `navigator.globalPrivacyControl`
   (client) is present, Analytics and Marketing are denied automatically and the banner reports
   "Your Global Privacy Control signal has been honored." California's CPPA requires honoring GPC;
   this is the most commonly missed requirement in US consent tooling.
2. **Stored consent.** The `anchor_consent` cookie (12-month default lifetime, configurable),
   carrying a consent ID, timestamp, granted categories, and the policy version. A policy-version
   bump invalidates all stored consent and re-prompts.
3. **Region**, resolved by the geo ladder below.

### Geo ladder

Tiers are tried in order; the first that yields a country wins.

1. **Edge headers** — `HTTP_CF_IPCOUNTRY`, `HTTP_CLOUDFRONT_VIEWER_COUNTRY`,
   `HTTP_X_VERCEL_IP_COUNTRY`, `GEOIP_COUNTRY_CODE`, `HTTP_X_GEO_COUNTRY`.
   Free, instant, no outbound request.
   *Caveat:* `CF-IPCountry` is only present when Cloudflare's "Add visitor location headers"
   managed transform is enabled. On Kinsta that toggle lives in Kinsta's Cloudflare account, not
   the site owner's, so its presence must be verified per site — hence tier 4 and the settings
   readout.
2. **IP geolocation API (opt-in).** A settings field accepts an ipinfo.io or ipapi.co token.
   Results are cached in a transient keyed by the **/24 IP prefix**, so one lookup serves a whole
   address block. Disabled by default. Never blocks page render — a miss falls through.
3. **Client-side timezone and locale.** `Intl.DateTimeFormat().resolvedOptions().timeZone` plus
   `navigator.language`. Free, universal, no API.
   **This tier may only relax strict → opt-out. It may never tighten opt-out → strict.** A wrong
   guess can therefore only recover data, never create non-compliance.
4. **Fallback: strict.**

MaxMind GeoLite2 is deliberately not bundled: it requires an account and license key, mandates
recurring updates to remain licensed, and its redistribution terms make shipping the database in
this repository legally murky. The Google Maps API is not usable for this — it has no IP→country
lookup, and the Geolocation API is billed per request and designed for device positioning.

The settings page shows a live readout — e.g. `Detected region: US (via CF-IPCountry)` — so the
active tier is verifiable per site.

## Blocking layers

Three layers, because tracking arrives from three different places.

### 1. Google Consent Mode v2

Google tags are **not** blocked. They are loaded with consent denied, which is what Google has
required for EEA traffic since March 2024; hard-blocking GTM instead breaks conversion modeling
and Ads remarketing audiences.

Emitted in `wp_head` at priority 1, before any other output, so GTM observes defaults first. Our
four categories map onto Google's seven signals:

| Google signal | Category |
|---|---|
| `security_storage` | Necessary (always granted) |
| `functionality_storage` | Functional |
| `personalization_storage` | Functional |
| `analytics_storage` | Analytics |
| `ad_storage` | Marketing |
| `ad_user_data` | Marketing |
| `ad_personalization` | Marketing |

Also sets `wait_for_update` (configurable, default 500ms), `url_passthrough`, and
`ads_data_redaction`. On a consent choice the runtime fires `gtag('consent', 'update', …)`.

### 2. Anchor Code Snippets bridge

Adds a **Consent category** dropdown to each `anchor_code_snippet` post. Snippets categorized as
Necessary render unchanged; the rest render with `type="text/plain"` and
`data-anchor-consent="{category}"`, and are activated by the front-end runtime when that category
is granted.

This is the surgical layer with zero false positives, and it covers where Anchor clients actually
paste GTM, Meta Pixel, and CTM code.

**MU-plugin limitation.** The Code Snippets module can write a snippet to an mu-plugin file, which
loads before this module can gate it. For any snippet whose location is `mu_plugin`, the consent
dropdown is disabled and an admin notice explains that mu-plugin snippets cannot be consent-gated
and should be moved to a header/body/footer location if they need gating. We do not modify the
Code Snippets mu-plugin writer.

### 3. Output-buffer rewriter

`ob_start()` on `template_redirect`, flushed on `shutdown`. Rewrites matched third-party
`<script src>` into `type="text/plain" data-anchor-consent="…"` and `<iframe src>` into
`data-anchor-src`.

- Matching is by URL substring against the **service registry** (below) plus **user-defined custom
  rules** — a repeater of `{ url_pattern, category, label }`.
- Regex-based, not DOMDocument — DOMDocument mangles partial and non-conforming HTML.
- Bypassed entirely for admin, AJAX, REST, cron, feeds, sitemaps, and XML/JSON responses.
- Only engages when at least one rule would apply, so sites with no matches pay ~nothing.
- A global kill switch in settings disables this layer independently of the other two.
- Blocked iframes are replaced with a styled placeholder ("This content is blocked until you accept
  marketing cookies") carrying an **Accept & Load** button that grants the needed category and
  restores the frame in place — never a blank hole in the layout.

### Cookie auto-deletion

On withdrawal or downgrade, cookies matching the retired categories' known patterns are deleted
across the current host and its registrable parent domain: `_ga*`, `_gid`, `_gat*`, `_gcl_*`,
`_fbp`, `_fbc`, `_hj*`, `_clck`, `_clsk`, `li_*`, `__ctm*`, `_uetsid`, `_ttp`, and any patterns
declared on custom rules.

## Service registry

A curated, filterable database of known third-party services. Each entry carries a key, display
name, provider, default category, URL match patterns, and the cookies it sets (name, purpose,
duration). Ships with:

Google Analytics / GA4, Google Tag Manager, Google Ads, Google Maps, reCAPTCHA, YouTube, Meta
Pixel, LinkedIn Insight, TikTok Pixel, X/Twitter, Pinterest, Microsoft Advertising (UET), Hotjar,
Microsoft Clarity, Intercom, Drift, HubSpot, Mailchimp, Vimeo, CallTrackingMetrics, CallRail.

Exposed via `apply_filters( 'anchor_compliance_services', $services )` and extendable through the
custom-rules repeater in settings. Every entry's category is user-overridable.

## CallTrackingMetrics handling

CTM warrants specific treatment because Anchor sites depend on it.

**Verified:** `anchor-ctm-forms` submits server-side over AJAX to FormReactor
(`anchor-ctm-forms.php:78-79`) and never reads the CTM tracking cookie. **CTM form submissions
therefore continue to work at full fidelity regardless of consent state.** Only Dynamic Number
Insertion and session attribution depend on the `t.js` tracking script.

**What "collect but don't transmit" would require** is policing a third-party script's network
calls, which is not achievable once its JS executes. Instead:

- **Same-page consent (the common case).** `document.referrer`, the landing URL, and UTM/`gclid`/
  `fbclid` parameters persist for the entire page load and are unaffected by delaying a script.
  Injecting CTM at the moment of consent gives it identical attribution to what it would have had
  at page load. **No loss.** Most consent plugins lose this by deferring injection to a later page.
- **First-touch capture and replay.** Attribution is captured into a **memory-only** JS object at
  first paint — no storage, so ePrivacy's storage rules are not engaged — and replayed into CTM on
  consent. It is persisted to `sessionStorage` only *after* consent exists, or immediately in
  opt-out regions where storage is lawful by default.
- **Accepted loss.** A strict-region visitor who browses several pages before consenting loses
  first-touch attribution. This is stated plainly rather than papered over.
- **Pre-consent the default phone number remains visible and clickable.** Calls still connect;
  they are simply unattributed.
- CTM's category is **configurable** (default: Marketing), since classifying DNI as
  legitimate-interest is a decision for the client's counsel.

CTM's own consent API could not be verified — both relevant Zendesk articles return HTTP 403 to
automated fetches. If such an API exists, it can be wired in later behind the existing category
hook; nothing in this design depends on it.

## Banner and preference center

### Buttons

`[ Essential Only ]  [ Customize ]  [ Accept All ]`

**Essential Only and Accept All carry equal visual weight** — unequal prominence is the specific
pattern regulators have issued fines over. Customize is the quieter middle option. The reject
label is switchable between "Essential Only" and "Reject All" in settings.

In opt-out regions the banner instead presents a notice with "Do Not Sell or Share My Personal
Information" alongside a Customize link.

### Design

- **Mobile:** bottom sheet, slides up, drag handle, `env(safe-area-inset-bottom)` respected,
  buttons stack full-width at comfortable tap targets.
- **Desktop:** four layout presets — bar, floating card, center modal, corner popover — plus a
  blocking overlay variant for strict mode.
- Backdrop blur, spring entrance, `prefers-reduced-motion` fully respected.
- Dark-mode aware via `prefers-color-scheme` with a forced-light/forced-dark override.
- **Auto-inherits brand colors from Anchor Site Config** (`anchor_site_config_options` →
  `colors.primary` / `secondary` / `ink`), so the banner matches the client's site with no
  configuration, with per-setting overrides available.

### Preference center

One row per category: toggle, plain-English description, and an expandable list of the actual
services and cookies in that category — name, provider, purpose, duration, drawn from the service
registry. Necessary is displayed locked-on rather than hidden, so the visitor can see what it
covers.

### Accessibility

Non-negotiable, since Anchor ships an accessibility module:

- `role="dialog"`, `aria-modal="true"`, labelled by its heading
- Focus trap while open; focus restored to the triggering element on close
- Full keyboard navigation; visible focus rings
- WCAG 2.2 AA contrast verified on every preset in both color schemes
- **Escape does not constitute consent.** In strict mode Escape is inert; in opt-out mode it
  dismisses the notice without granting anything.
- Screen-reader announcement on consent change via a polite live region

### Re-entry

- A persistent floating pill; position and visibility configurable, hideable per-region
- `[anchor_consent_link]` — opens the preference center
- `[anchor_do_not_sell]` — renders the CCPA-mandated "Do Not Sell or Share My Personal
  Information" link

## Consent log

Custom table `{prefix}anchor_consent_log`:

| Column | Notes |
|---|---|
| `id` | PK |
| `consent_id` | UUIDv4, also written to the visitor cookie |
| `created_at` | UTC datetime |
| `ip_hash` | SHA-256 of a truncated IP + site salt. **Raw IP is never stored.** |
| `region` | Resolved country/region code |
| `posture` | strict / optout |
| `categories` | JSON of granted categories |
| `policy_version` | Version in force at the time |
| `method` | banner / preference_center / gpc / api |
| `ua_hash` | Hashed user agent |

Admin viewer with filtering by date, region, and method; CSV export; configurable retention with a
WP-Cron purge job. Satisfies the GDPR Art. 7(1) obligation to demonstrate consent while storing no
directly identifying data.

## Cookie policy

`[anchor_cookie_policy]` renders a live, categorized table of the cookies the site actually sets —
name, provider, purpose, duration, category — assembled from the service registry entries that
match the site's active rules, plus any custom declarations. Gives the client a real cookie policy
page instead of boilerplate.

## Privacy requests (DSAR)

`[anchor_privacy_request]` renders a front-end form supporting access, deletion, correction, and
opt-out requests.

- Honeypot, nonce, and per-IP rate limiting
- Stored in `{prefix}anchor_privacy_requests` with an admin queue showing status and the statutory
  response deadline
- Email notification to the site admin and a confirmation to the requester
- Bridges to WordPress core's `wp_privacy_personal_data_exporters` /
  `wp_privacy_personal_data_erasers` so core tooling performs the actual data work

## Settings

Registered on the unified Anchor Tools settings page via
`add_filter( 'anchor_settings_tabs', …, 15 )`, stored in a single option
`anchor_compliance_options` with `autoload=false`. Sub-sections:

1. **General** — enable, policy version, consent lifetime, privacy/cookie/terms URLs, company name
2. **Regions** — strict region list, geo tier configuration, optional IP API token, live detected-
   region readout, unknown-region fallback
3. **Appearance** — layout preset, position, colors (with Site Config inheritance toggle), corner
   radius, logo, dark-mode behavior
4. **Content** — all banner and preference-center copy, button labels, category descriptions
5. **Services** — the registry with per-service category overrides and enable/disable
6. **Custom rules** — repeater of `{ url_pattern, category, label, cookie_patterns }`
7. **Consent log** — viewer, export, retention
8. **Privacy requests** — queue, notification recipients, form configuration
9. **Advanced** — output-buffer kill switch, Consent Mode toggle and `wait_for_update`, GPC
   honoring toggle, debug mode

## File layout

```
anchor-compliance/
  anchor-compliance.php              Module class, boot, hook wiring (~200 lines)
  includes/
    class-settings.php               Tab, registration, sanitize
    class-consent-state.php          Cookie read/write, category + GPC resolution
    class-geo.php                    Geo ladder
    class-banner.php                 Banner + preference-center render
    class-consent-mode.php           Consent Mode v2 emitter
    class-script-blocker.php         Output-buffer rewriter
    class-service-registry.php       Curated service/cookie database
    class-snippets-bridge.php        Code Snippets integration
    class-consent-log.php            Table, insert, export, purge
    class-cookie-policy.php          [anchor_cookie_policy]
    class-dsar.php                   Request form + admin queue
  assets/
    frontend.css  frontend.js
    admin.css     admin.js
```

Source `.css` / `.js` only — minified assets are generated by `bin/build-assets.mjs` in CI and are
never committed.

## Front-end runtime contract

A small public JS API so other code can integrate:

```js
AnchorConsent.get()                    // → { necessary:true, functional:false, … }
AnchorConsent.has('analytics')         // → bool
AnchorConsent.on('change', fn)         // subscribe
AnchorConsent.accept(['analytics'])    // grant programmatically
AnchorConsent.openPreferences()
```

Plus a DOM event `anchor-consent-change` on `document`, and PHP-side
`do_action( 'anchor_compliance_consent_changed', $categories, $consent_id )`.

## Testing

- **PHPUnit** — geo ladder resolution across header permutations; consent cookie encode/decode and
  version invalidation; script-blocker rewriting against HTML fixtures (including the bypass
  conditions); service-registry category overrides; consent-log insert, export, and purge;
  sanitizers rejecting malformed settings.
- **Playwright E2E** — banner appears in strict mode and blocks nothing in opt-out mode; each of
  the three buttons produces the correct cookie and category state; preference center toggles
  persist; blocked iframe placeholder restores on Accept & Load; keyboard-only traversal and focus
  trap; Escape does not grant consent; consent survives navigation; withdrawal deletes cookies.
- **Manual** — verify `CF-IPCountry` presence on a live Kinsta site; verify GTM receives denied
  defaults before any tag fires, using Google's Tag Assistant.

## Risks

| Risk | Mitigation |
|---|---|
| Output buffering conflicts with a caching plugin or another buffering plugin | Kill switch; bypass list; run at a late `template_redirect` priority; document known conflicts |
| Page caching serves a strict banner to US visitors (or worse, the reverse) | Consent resolution is finalized client-side; server output is always the *conservative* variant, and the client relaxes it. Cache-safe by construction. |
| `CF-IPCountry` absent on a given Kinsta site | Timezone tier recovers it; settings readout makes the gap visible |
| Blocking a script the theme depends on breaks the page | Per-service enable/disable; blocked-iframe placeholders rather than removal; debug mode logs every rewrite |
| mu-plugin snippets silently ungated | Dropdown disabled with an explanatory admin notice |
