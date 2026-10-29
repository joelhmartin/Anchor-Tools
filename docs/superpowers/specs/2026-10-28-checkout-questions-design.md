# Checkout Questions — design

2026-10-28. Status: design, ready for plan.

## Why

3.44.2 put the answers to questions ticked "Include in admin email" into WooCommerce's "New order" email, but only for event tickets. The question system (field types, validation, the editor rows, the email lines) lives inside the Events Manager, so a course, or any other product, has no way to ask the buyer anything at checkout. The owner wants the same thing for every product, working alongside Events Manager.

## Shape (two deliverables, one PR each)

1. **Shared question kit**: move the question model out of `anchor-events-manager/anchor-events-manager.php` into `includes/questions/`, beside the shared email kit in `includes/email/`. Events Manager becomes its first consumer and behaves exactly as it does today. This is a refactor with no visible change.
2. **New module `checkout_questions`** ("Anchor Checkout Questions", `\Anchor\CheckoutQuestions\Module`, PSR-4 under `anchor-checkout-questions/src/`, structured like `anchor-agreements/`). Reusable question sets, attached to products or product categories, asked at classic checkout, stored on the order, shown on the order screen and in the "New order" email.

Name: my call. "Anchor Fields" reads like ACF and says nothing about checkout. "Anchor Orders" promises order management this is not. The module only asks the buyer questions at checkout.

## Goals

- Any WooCommerce product (courses, merch, services, anything) can ask the buyer questions at checkout, using the same field types and editor rows the events use.
- Answers on questions ticked **Include in admin email** print in WooCommerce's "New order" email, in the same style as event answers.
- Every answer shows on the admin order edit screen.
- Exactly one implementation of the question model, the answer sanitizer, the control renderer, and the New-order-email renderer. Events and the new module both call it.
- An existing site upgrading changes nothing: Events questions, stored answers, roster/CSV columns, and the 3.44.2 email output stay byte-for-byte compatible.

## Non-goals

- Showing answers to the customer (customer emails, My Account, thank-you page). Possible later through a per-question "Show to customer" flag; not in v1.
- WooCommerce Blocks checkout. v1 supports the classic `[woocommerce_checkout]` only, like Agreements does: an admin notice when the checkout page uses the block (reuse the pattern in `anchor-agreements/src/Admin/BlockCheckoutNotice.php`), and a Store API guard that refuses an order missing required answers rather than letting it through silently.
- Per-unit questions (asking again for each of qty 3). Events already handle per-attendee; v1 asks once per set per order.
- Conditional logic, file uploads, pricing on answers.
- Editing answers after the order is placed.

## Anti-goals (a failure even if it works)

- A second copy of the question validator or control renderer in the new module. The whole point of deliverable 1.
- Event ticket lines getting asked their event questions twice, or Events' per-attendee behaviour changing.
- A product that requires an answer being purchasable without one through any checkout path the site exposes.
- PHI in logs or debug output. Answers can be medical (these are dental/medical clients); never log answer values.

## Deliverable 1: shared question kit

Move these out of `\Anchor\Events\Module` (currently ~lines 14993–15560 of `anchor-events-manager.php`) into `includes/questions/`:

| Today (Events Module method) | Becomes |
|---|---|
| `normalize_registration_questions()` | `Anchor_Questions::normalize( array $rows ): array` |
| `sanitize_registration_answers()` + `multiselect_answer()` | `Anchor_Questions::sanitize_answers( array $questions, $posted ): array{answers,missing}` |
| `render_registration_question_control()` | `Anchor_Questions::render_control( array $q, array $args ): string` |
| `resolve_registration_answers()`, `registration_answer_label()`, `registration_answer_index()` | `Anchor_Questions::resolve_answers()` / `::answer_label()` (same contract, questions passed in instead of looked up by event) |
| `event_question_row_html()` + the add/remove/reorder JS in `assets/manager.js` (~line 331) | `Anchor_Questions_Editor` (row HTML + a shared admin JS/CSS asset under `includes/questions/assets/`), parameterised by input name prefix |
| `Admin_Email_Answers` (`class-admin-email-answers.php`) | `Anchor_Questions_Email`: `rows_for( $questions, $stored )` (flagged + answered, flattened) and `render_order_groups( array $groups, bool $plain_text )` where a group is `{heading, entries:[{name?, rows}]}` |

Rules:
- The kit takes question arrays and answer arrays. It knows nothing about events, seats, products or orders.
- The Events Module keeps its public methods as thin wrappers (callers in `class-woocommerce.php`, `class-roster.php`, templates and tests keep working), each delegating to the kit with the event's questions. Remove a wrapper only where every caller is in this plugin and is updated in the same PR.
- Storage keys, question-row shape (`key,label,type,options,required,admin_email`), and stored answer shape are unchanged. No migration.
- Loaded from `anchor-tools.php` like `includes/email/`, so any module can use it whether or not Events is enabled.
- Gate: the full PHPUnit suite and the Playwright events specs pass unchanged before any module work starts. Existing tests are not edited to make them pass, except mechanical renames if a test calls a moved private method by reflection.

## Deliverable 2: the `checkout_questions` module

### Data

- **CPT `anchor_question_set`** (admin only, not public; `show_in_menu` via `apply_filters( 'anchor_checkout_questions_parent_menu', ... )` per convention, under WooCommerce if that reads better). Title = set name. Meta: the questions (kit row shape), stored like the events' `QUESTIONS_META`.
- **Where a set applies**, chosen on the set's edit screen: specific products (WooCommerce product search select) and/or product categories. My call: assignment lives on the set, not on the product tab, so one place answers "where does this set show up", and covering all courses is one category pick. A read-only "Checkout questions" line on the product edit screen lists the sets that apply, linking to them.
- **Excluded lines**: a line Events Manager owns (`_anchor_event_id` resolvable, i.e. an event ticket) is never matched by category assignment, so event tickets don't get asked twice. Explicit product assignment still applies (the author chose it).

### Checkout

- Cart → applicable sets = union over cart lines, deduplicated. Each set renders once, as a titled block of controls, at `woocommerce_after_order_notes` (my call; builder may move it if the theme renders better elsewhere, as long as it is before payment).
- Controls via `Anchor_Questions::render_control()`, values redisplayed through `sanitize_answers()` exactly as Events' checkout does.
- Validation on `woocommerce_after_checkout_validation` through `sanitize_answers()`'s `missing`, one WooCommerce error per missing required question.
- Store API guard: on `woocommerce_store_api_checkout_update_order_from_request`, refuse when an applicable set has required questions (the block checkout cannot render them in v1).

### Storage

- Order meta `_anchor_cq_answers` = list of `{set_id, set_title, questions (snapshot of the rows as asked), answers}` written at `woocommerce_checkout_create_order`. Snapshotting the questions means renaming, editing or deleting a set later never changes what an old order shows. HPOS-safe via `$order->update_meta_data()`.

### Display

- **Admin order screen**: metabox (HPOS + legacy screens, like `anchor-agreements/src/Admin/OrderMetabox.php`) listing every set and every answered question.
- **New order email**: on `woocommerce_email_order_meta`, `new_order` only. One renderer for both modules: Events' 3.44.2 hook and this module's hook each build groups and call `Anchor_Questions_Email::render_order_groups()`. Group heading = set title; one entry with no name (per-order, not per-attendee). Only questions ticked "Include in admin email" print. Ordering in the email when both are present: event groups first, then sets (priority on the hook).
- Customer emails: nothing (non-goal).

### Module plumbing

- Register in `anchor_tools_get_available_modules()` (label "Anchor Checkout Questions", description "Ask buyers questions at WooCommerce checkout and see the answers on the order and in the New order email."). Constructed only when WooCommerce is active.
- Conventions from CLAUDE.md: `anchor-schema` text domain, `autoload=false` options, assets via `ANCHOR_TOOLS_PLUGIN_URL`, admin assets only on the set edit screen, files under 1,000 lines / functions under 150.

## Tests

- Kit: unit tests for normalize / sanitize (every type incl. multiselect "Other", textarea newlines, select off-list rejected, checkbox yes/''), render_control escaping, email rows (flagged only, unanswered skipped).
- Events: existing suite unchanged and green is the regression proof.
- Module: set matching (product, category, union/dedupe, event-ticket exclusion by category but not by explicit product), checkout validation of required, storage snapshot survives set edit/delete, order metabox output, New order email HTML + plain, customer emails empty, both modules' groups together in one email, Store API guard refusal.
- One Playwright spec: a course product with a category-assigned set, required question blocks checkout until answered, answer visible on the order screen.

## Calls settled after the builder check (mine, not the owner's)

1. **"Any checkout path" means the paths that run WooCommerce checkout:** classic checkout validation (`woocommerce_after_checkout_validation`, which the Stripe/PayPal express buttons also run because they submit through `WC_Checkout::process_checkout`) and the Store API guard. Orders created by staff (admin screen, REST, subscription renewals) and the pay-for-order page (the order already exists) are exempt. A plan step verifies the express-button claim against the gateways installed on a client site and adds a cart-level purchasability check only if one bypasses validation.
2. **Block checkout guard refuses only when a required question applies.** Optional-only sets are skipped there; the admin block-checkout notice says optional questions are not collected on that checkout.
3. **Event-ticket exclusion** asks Events Manager through one small public resolver (e.g. `\Anchor\Events\Module::product_is_event_ticket( $product_id, $variation_id )`, wrapping the existing `event_for_line()` logic). When Events Manager is off, nothing is an event ticket and nothing is excluded.
4. **Variations**: category and explicit product assignment both match on the parent product; the event-ticket check runs on the line's product + variation, as Events resolves it.
5. **Field names** are namespaced per set: `anchor_cq[<set_id>][<key>]`, and `sanitize_answers()` runs once per set against that set's questions.
6. **Email contract**: `render_order_groups()` takes pre-resolved `{label, answer}` rows. A nameless entry renders the `<p>` with only the `<br />`-separated rows and no `<strong>` line. Events' output stays byte-identical to 3.44.2, locked by a snapshot test written before the refactor. `rows_for( $questions, $stored )` (also in the kit) produces those rows; Events resolves its seat/attendee answers in its own wrapper, this module from the order snapshot.
7. **Sensitive answers**: the "Include in admin email" checkbox (both modules, it is the shared editor) gets one line of help text: "Answers are sent by plain email. Don't tick this for health information." No other gate.

## Left to the builder

- Exact hook priorities, CSS, the set edit screen layout, the product-search control (WooCommerce's `wc-product-search` select is the obvious one).
- Whether the shared editor JS replaces Events' copy in deliverable 1 or deliverable 2, as long as only one copy exists at the end.
- Release: each deliverable merges and releases on its own (minor bump for the module).
