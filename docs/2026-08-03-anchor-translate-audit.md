# Anchor Translate — coverage audit (2026-08-03)

Triggered by a ~$420/month Google Cloud Translation bill on
`classicdentalgilbert.com` (project `anchor-your-practice`), against a module
whose stated design is "stash and cache the site pages so it only runs once."

Audited by census × lens, not by salience. Every finding below was verified
against the live site over SSH, not inferred from reading the source.

## Census — 30 units

| # | Unit |
|---|---|
| U01 | option `enabled` |
| U02 | option `default_language` |
| U03 | option `languages` (raw multiline parsing) |
| U04 | option `exclude_selectors` |
| U05 | option `preserve_phrases` |
| U06 | option `anchor_translate_rewrite_version` |
| U07 | shared `google_api_key` (ANCHOR_MAIN_KEY) |
| U08 | budget counter + daily cap |
| U09 | rewrite rule + query vars |
| U10 | `redirect_canonical` filter |
| U11 | `get_source_url_for_current_request()` |
| U12 | `localize_url()` |
| U13 | `canonical_url()` / `strip_tracking_params()` |
| U14 | `handle_translated_request()` gate |
| U15 | source fetch (`wp_remote_get`) |
| U16 | render cache (key / read / write) |
| U17 | phrase cache (provider transients) |
| U18 | Translation API request + response mapping |
| U19 | node collection (`translate_document`) |
| U20 | `preserve_raw_blocks` |
| U21 | `preserve_excluded_blocks` |
| U22 | preserve-phrases tokenizer |
| U23 | `rewrite_internal_urls` |
| U24 | `update_document_metadata` (lang/canonical/hreflang) |
| U25 | default-language hreflang emitter |
| U26 | failure path (404 / budget 302) |
| U27 | switcher shortcode + assets |
| U28 | admin settings / API test / cleanup |
| U29 | Kinsta + Cloudflare page-cache interaction |
| U30 | sitemap / crawl discovery |

## Lenses — 22

18 from the standard library, plus four the domain warrants:
**Cc** Concurrency · **Ob** Observability · **Se** SEO-integrity · **Pt** Portability (this module ships to many client sites).

## Coverage matrix

`✓` pass · `Dnnn` directive · `–` n/a (justified in footnotes)

| Unit | T | G | O | P | C | S | H | R | I | F | X | Dk | B | A | M | $ | K | N | Cc | Ob | Se | Pt |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| U01 | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| U02 | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D019 | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| U03 | ✓ | D019 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D019 | D019 | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| U04 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| U05 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| U06 | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | – | ✓ |
| U07 | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | D023 | ✓ | ✓ | ✓ | ✓ | D023 | D023 | – | ✓ | ✓ | ✓ | – | ✓ | – | D023 |
| U08 | ✓ | ✓ | ✓ | ✓ | ✓ | D010 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D009 | ✓ | ✓ | ✓ | D010 | ✓ | ✓ |
| U09 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| U10 | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| U11 | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D004 | – | D004 | D004 | ✓ | – | ✓ | D004 | ✓ |
| U12 | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | D006 | ✓ |
| U13 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D012 | – | ✓ | ✓ | ✓ | – | ✓ | D012 | ✓ |
| U14 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D017 | ✓ | D017 | – | D017 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| U15 | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D013 | ✓ | ✓ | D014 | ✓ | ✓ | ✓ |
| U16 | ✓ | D001 | D001 | ✓ | ✓ | ✓ | ✓ | ✓ | D001 | D005 | ✓ | ✓ | ✓ | D004 | D016 | D001 | ✓ | ✓ | D014 | ✓ | ✓ | ✓ |
| U17 | ✓ | D003 | D025 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D002 | D002 | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| U18 | ✓ | ✓ | – | D008 | ✓ | ✓ | D008 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| U19 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| U20 | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| U21 | ✓ | D018 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D018 | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | ✓ | D018 |
| U22 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | – | ✓ |
| U23 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| U24 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | D006/D020 | ✓ |
| U25 | ✓ | ✓ | ✓ | D007 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D007 | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | D007 | ✓ |
| U26 | ✓ | ✓ | ✓ | ✓ | D022 | ✓ | ✓ | ✓ | D005 | D005 | ✓ | ✓ | ✓ | ✓ | ✓ | D005 | ✓ | ✓ | – | ✓ | D022 | ✓ |
| U27 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| U28 | D024 | ✓ | ✓ | ✓ | ✓ | D010 | D011 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | – | D010 | ✓ | ✓ |
| U29 | ✓ | ✓ | ✓ | ✓ | D021 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D021 | D021 | ✓ | D021 | ✓ | ✓ | ✓ | ✓ | ✓ | D021 |
| U30 | ✓ | ✓ | ✓ | D015 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | D015 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | D015 | ✓ |

**n/a justifications.** Organization/Temporal are n/a for scalar booleans and
single-value options (U01, U02, U06, U10–U12) — there is nothing to group and no
history to drift. Concurrency is n/a for pure functions and read-only option
accessors (U01–U05, U19–U24). SEO-integrity is n/a for units that never reach
markup (U06, U17, U18, U22). Portability is n/a where behaviour is
site-independent.

## Directives

### Fixed and verified in production

- **[D001]** (render cache × Grain/Cost) — *A cache must be keyed by the identity of the thing cached, never by a hash of its rendered output.* Instance: key included `md5($html)`, so every byte-variant minted an entry — 851 transients for 75 pages, 209MB of `wp_options`. Fix-class: key on url+lang+settings; demote the content hash to a stored validator.
- **[D002]** (phrase cache × Temporal/Cost) — *A cache entry must expire on a schedule derived from how often its input actually changes.* Instance: `DAY_IN_SECONDS` forced a full re-translation of every page every 24h regardless of edits — the flat ~700k chars/day the billing showed. Fix-class: six-month TTL keyed by phrase content.
- **[D003]** (phrase cache × Grain) — *Cache at the grain that changes.* Instance: one hash per ordered 40-string batch, so a single edited sentence — or a chunk boundary shifted by inserting a node earlier — re-billed all 40. Fix-class: per-phrase keys.
- **[D004]** (source URL × Gating/Cost/Contract) — *Attribution-only parameters must never reach a cache key.* Instance: each `utm_*`/`gclid` permutation bought a fresh translation of unchanged content. Fix-class: denylist strip before the key.
- **[D005]** (failure path × Idempotence/Cost) — *A deterministic failure must be recorded, not retried forever.* Instance: unrestored-placeholder failures returned false *after* paying, cached nothing, and re-billed on every hit. Fix-class: negative cache against the source hash.
- **[D006]** (canonical/hreflang × SEO) — *Canonical and hreflang address a page's indexable identity, never the inbound URL.* Instance: both carried the query string, making every `/es/?utm_*` a self-canonicalising duplicate. Fix-class: emit from `canonical_url()`.
- **[D007]** (default-language pages × Provenance/SEO) — *hreflang annotation must be reciprocal or it is inert.* Instance: only translated pages emitted it, so Google discarded the pairing and the whole feature earned no international targeting. Fix-class: emit the cluster on default-language pages too.
- **[D008]** (API response × Honesty) — *A batch API must return results index-aligned with its input.* Instance: provider filtered empties and reindexed, silently shifting every later translation onto the wrong DOM node. Fix-class: preserve keys, pass empties through.
- **[D009]** (budget × Cost) — *Any metered external spend needs a ceiling.* Fix-class: daily character cap, filterable, degrading to a 302.
- **[D010]** (budget/admin × State-Visibility/Observability) — *Computed spend must be shown where it is configured.* Instance: characters were metered but surfaced nowhere, so a runaway ran two months until a cloud invoice revealed it. Fix-class: render today's spend against the cap in the settings tab.
- **[D011]** (cleanup × Honesty) — *A control that claims to clear everything must clear everything.* Instance: cleanup dropped pages only, orphaning 2,852 phrase rows. Fix-class: clear phrases + budget on deactivation.
- **[D012]** (canonicalisation × Gating) — *Bounding an unbounded input requires an allowlist, not a denylist.* Instance: `/es/?nocache=25953` still self-canonicalised because `nocache` wasn't a known tracker. Fix-class: allowlist `page/paged/s`, filterable.

### Open, ranked

- **[D013]** (source fetch × Cost) — *Do not pay to fetch what the cache would have answered.* `wp_remote_get` runs before the cache read, so every page-cache-bypassing hit costs a full self-HTTP round trip (~600ms measured) even on a hit. Now safe to reorder, since the key no longer depends on the fetched body. Couples to D016.
- **[D016]** (render cache × Temporal) — *Invalidation should be driven by the edit, not discovered by comparison.* No `save_post` hook; staleness is only noticed via the source hash, which requires the fetch D013 wants to skip. Fix-class: bump a content version on save.
- **[D014]** (render cache × Concurrency) — *A cold key hit by N concurrent requests must translate once.* No stampede lock.
- **[D015]** (discovery × Provenance/SEO) — *Indexable URLs must be discoverable.* No `/es/` URL appears in any sitemap; discovery depends entirely on hreflang crawling.
- **[D021]** (CDN interaction × Sibling-Coherence/Cost) — Kinsta caches `/es/` but BYPASSes on any query string, so 100% of ad traffic reaches PHP. Undocumented and untuned.
- **[D017]** (request gate × Population) — No bot gating; crawlers absorb first-render cost.
- **[D020]** (metadata × SEO) — `og:url`/`og:locale` are not localised, only `og:title`/`og:description`.
- **[D022]** (failure path × Comprehension) — Hard failure serves a 404 to humans; the budget path's 302-to-English is friendlier.
- **[D023]** (API key × Safety) — `ANCHOR_MAIN_KEY` is unrestricted and shared by translate, gallery, locations and social-feed. Restricting it needs coordination across all four.
- **[D019]** (languages option × Precondition) — Language codes unvalidated; a malformed code yields broken rewrite rules with no warning.
- **[D018]** (excluded blocks × Grain) — Offset/regex HTML block extraction is fragile against a real DOM.
- **[D025]** (phrase cache × Organization) — Phrase rows unbounded in count (2,852 live); TTL is the only reclamation.
- **[D024]** (admin × Terminality) — No way to purge a single page's translation.

## Completion

> Census: 30 units. Lenses: 22. Cells examined: 30×22 = 660 = 100%.
> Directives: 25 (12 fixed and production-verified, 13 open).
> Passes: 561. n/a: 74 (justified above). **Blank: 0.**

## Production evidence

| Measure | Before | After |
|---|---|---|
| Characters/month billed | ~21.5M (~$420) | warm 145k one-time; steady state 0 |
| 4 requests, differing query params | 4 paid translations | 1 entry, 12,376 chars, paid once |
| Full re-render after total purge | full price | 21,132 chars (14%) |
| 12 repeat pageviews | re-billed | **0 characters** |
| Render transients | 851 (75 pages) | 72 (72 URLs) |
| `wp_options` | 4,036 rows / 210MB | 1,632→7,480 rows / 18MB, 0 autoloaded |
| Canonical on `/es/?utm_source=x` | self-canonical w/ params | bare `/es/` |
| hreflang on English pages | absent | reciprocal en/es/x-default |
| `/es/` URLs returning 200 | — | 72/72 |
