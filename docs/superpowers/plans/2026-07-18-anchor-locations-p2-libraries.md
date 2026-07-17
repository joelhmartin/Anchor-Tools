# Plan — Anchor Locations Phase 2: Content Libraries

Spec: `docs/superpowers/specs/2026-07-18-anchor-locations-p2-libraries-design.md`
Branch: `feature/anchor-locations-p2-libraries`

## Task 1 — Test scaffold (TDD, RED)
- Create `tests/test-locations-libraries.php`, class `LocationsLibrariesTest extends WP_UnitTestCase`.
- Assertions:
  1. `match_items()` ordering: both-match > location-only > global.
  2. Draft item excluded from `match_items()`.
  3. County item matches a child city (ancestor walk).
  4. Service-only item scores above global-only.
  5. `[anchor_local_faqs]` renders answers AND FAQ `FAQPage` schema appears via `wp_head` callback.
  6. Save handler sanitizes: rating clamp 1–5, `al_global` normalized, `al_location_ids` ints.
- Run filtered, expect failure (class missing).

## Task 2 — `class-libraries.php` skeleton + CPT registration (GREEN for CPT)
- New file, `\Anchor\Locations\Libraries`, constants for 3 CPTs + `NONCE`.
- Constructor hooks: `init` (register types), `add_meta_boxes`, `save_post`,
  `admin_enqueue_scripts`, admin column filters, 3 shortcodes, `wp_head` (FAQ schema, pri 21).
- `register_types()` for the 3 CPTs, attaching `service` tax.
- Wire `require_once __DIR__ . '/class-libraries.php'; new Libraries();` into `Module::__construct()`.

## Task 3 — Resolver `match_items()` (GREEN ordering/draft/ancestor tests)
- Query published items of `$cpt`; score each; filter score>0; sort by score desc, date desc; slice by limit.

## Task 4 — Shortcodes + context derivation + FAQ collector (GREEN faq render)
- 3 render methods, context derivation, escaping, `apply_filters` wrappers.
- FAQ shortcode fills `$this->faq_items`.

## Task 5 — FAQ `wp_head` schema emit (GREEN schema test)
- Emit `FAQPage` when collector non-empty + `is_singular()`, safe encoding.

## Task 6 — Admin metaboxes, save, assets, columns (GREEN save test)
- Details + Assignment metaboxes; guarded save; media/admin.js enqueue on CPT screens; assignment column.

## Task 7 — Verify
- `composer test -- --filter LocationsLibraries` green.
- `composer test -- --filter Locations` all green (no Phase-1 regressions).
- Write report, commit in logical chunks.
