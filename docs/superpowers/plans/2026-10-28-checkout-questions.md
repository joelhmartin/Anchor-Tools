# Checkout Questions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Any WooCommerce product can ask the buyer questions at checkout, and the answers show on the order screen and, for flagged questions, in the "New order" email, through one shared question kit that Events Manager also uses.

**Architecture:** Part A moves the question model (normalize, sanitize, render, resolve, editor rows, New-order-email rendering) out of `\Anchor\Events\Module` into `includes/questions/`, leaving Events' public methods as thin wrappers, with no behaviour change. Part B adds the PSR-4 module `\Anchor\CheckoutQuestions\Module`: a question-set CPT assigned to products/categories, rendered and validated at classic checkout, snapshotted onto the order, and displayed through the kit.

**Tech Stack:** PHP 8.1+, WordPress, WooCommerce (classic checkout, HPOS-safe order meta), jQuery IIFE admin JS, PHPUnit 9 (`Anchor_Events_TestCase`), Playwright on wp-env.

**Spec:** `docs/superpowers/specs/2026-10-28-checkout-questions-design.md` (read it, especially "Calls settled after the builder check").

## Global Constraints

- Question row shape is unchanged: `key, label, type, options, required, admin_email`. Types: `text, textarea, select, multiselect, checkbox`.
- Stored answer shape, Events storage keys (`_anchor_event_reg_questions`, `_anchor_event_reg_fields`, `_anchor_attendees`) and roster/CSV output are unchanged. No migration.
- Events' 3.44.2 New order email HTML and plain-text output stay byte-identical (Task A1 locks this).
- Text domain `anchor-schema`. `update_option(..., false)`. Assets via `ANCHOR_TOOLS_PLUGIN_URL`. Enqueue source `.js`/`.css`, never `.min.*`. jQuery IIFE, no ES modules. No `alert()/confirm()/prompt()`.
- Source files under 1,000 lines, functions under 150. New code must not grow `anchor-events-manager.php` or `class-woocommerce.php`; net line count of both must go down in Part A.
- Never log answer values (medical clients). Error/debug logs may name set ids and question keys, not answers.
- Help text under every "Include in admin email" checkbox, verbatim: `Answers are sent by plain email. Don't tick this for health information.`
- Module registry entry: key `checkout_questions`, label `Anchor Checkout Questions`, description `Ask buyers questions at WooCommerce checkout and see the answers on the order and in the New order email.`
- Order meta key `_anchor_cq_answers`. CPT `anchor_question_set`. Set meta: `_anchor_cq_questions`, `_anchor_cq_product_ids`, `_anchor_cq_category_ids`. Checkout field prefix `anchor_cq[<set_id>][<key>]`.
- Test env recipe: memory `compliance-test-env-setup` (wp-env tests-mysql port, `$TMPDIR/wordpress-tests-lib`, WooCommerce unzipped into `$TMPDIR/wordpress/wp-content/plugins/`). Do not commit `vendor/` changes made by a dev `composer install`; DO commit the `vendor/composer/autoload_*` changes from registering the new PSR-4 namespace (precedent: commit a1e8dd2).

## Review Focus

1. The same set attached by product AND by the product's category, or applying to two cart lines, renders once and stores once. Test in B2.
2. A cart with an event ticket and a course, where a category set covers both products: the event line is not matched, the course is. Explicit product assignment of the ticket still matches. Test in B2.
3. A set edited or deleted after the order: the order screen and a resent New order email still show the questions and answers as asked. Test in B3/B4.
4. Two sets that both have a question keyed `phone`: answers don't collide or leak between sets. Test in B3.
5. Answers containing `<script>`, `&`, quotes, or newlines are escaped in the metabox and HTML email, raw in the plain-text email, and textarea newlines survive storage. Tests in B4/B5.

---

## Part A — shared question kit (PR 1, patch release)

### Task A1: Lock current Events output

**Files:**
- Test: `tests/test-questions-golden.php` (create)

**Interfaces:**
- Produces: golden assertions later tasks must keep green unchanged.

- [ ] **Step 1: Write characterization tests** against the current code, `@group questions`:
  - `test_new_order_email_html_is_unchanged`: order with one line `_anchor_event_id` = event "Golden Event", `_anchor_attendees` = `[1 => ['name'=>'Ann','fields'=>['heard'=>'A friend','diet'=>'Vegan, Other: shellfish']], 2 => ['name'=>'','fields'=>['heard'=>'R&D <b>']]]`, questions as in `tests/test-admin-email-answers.php::questions()`. Capture `render_new_order_email_answers( $order, true, false, (object)['id'=>'new_order'] )` and `assertSame` against the literal string the current code produces (run it once, paste the output as the expected literal).
  - `test_new_order_email_plain_is_unchanged`: same, `$plain_text = true`.
  - `test_free_admin_notice_answer_lines_are_unchanged`: `Admin_Email_Answers::text_lines()` for a seat with the same answers, `assertSame` the array.
  - `test_question_control_markup_is_unchanged`: `render_registration_question_control()` for one question of each of the 5 types (multiselect with an "Other" option and a stored value `Vegan, Other: shellfish`), `assertSame` each literal.
  - `test_question_row_editor_markup_is_unchanged`: `event_question_row_html( 0, $row )` via reflection, and the `$template = true` variant, `assertSame` literals.
- [ ] **Step 2: Run** `./vendor/bin/phpunit --filter Test_Questions_Golden`. Expected: PASS (it describes today).
- [ ] **Step 3: Commit** `test(events): golden output for questions and the New order email answers`.

### Task A2: `Anchor_Questions` (model, sanitizer, control renderer, resolver)

**Files:**
- Create: `includes/questions/class-anchor-questions.php`
- Modify: `anchor-tools.php` (require beside `includes/email/`, ~line 69), `anchor-events-manager/anchor-events-manager.php:15094-15470` (bodies become delegations)
- Test: `tests/test-questions-kit.php` (create)

**Interfaces:**
- Produces (all `public static`, global class `Anchor_Questions`):
  - `normalize( $rows ): array` (body of `normalize_registration_questions`)
  - `sanitize_answers( array $questions, $posted ): array{answers: array<string,string>, missing: array<int,array>}`
  - `multiselect_answer( array $q, $raw, $other = '' ): string`, `multiselect_parse( array $q, $value ): array{chosen:string[],other:string}`, `is_other_choice( $opt ): bool`, `other_field_name( $name ): string`
  - `render_control( array $q, array $args = [] ): string` (same `$args` keys as today: `name,id,value,class,required,checkbox_label,checkbox_class`)
  - `resolve_answers( array $questions, $stored ): array`, `answer_label( array $questions, $key ): string`
- Events keeps every current public method with its current signature; each fetches the event's questions when `$questions` is null and delegates. `Module::is_other_choice()` / `other_field_name()` stay as one-line delegations.

- [ ] **Step 1: Write failing kit tests** on plain arrays (no event): normalize (drops blank label, derives key from label, dedupes `phone`→`phone_2`, unknown type→text, select with no options→text, multiselect option commas→` / `, `admin_email` defaults false); sanitize (textarea keeps `"a\nb"`, text flattens it, select off-list→`''`, checkbox `'1'`→`'yes'` and `'0'`→`''`, multiselect `['Vegan','Other']` + `__other`=`shellfish` → `'Vegan, Other: shellfish'`, required blank in `missing`, required unticked checkbox in `missing`); render_control escapes a value of `"<script>"`; resolve maps a legacy label key `How did you hear about us?` to `heard`; answer_label falls back to the key.
- [ ] **Step 2: Run** `--filter Test_Questions_Kit`. Expected: FAIL, class `Anchor_Questions` not found.
- [ ] **Step 3: Move the bodies** into `Anchor_Questions` and replace Events bodies with delegations. Move, don't copy: after this step the logic exists once.
- [ ] **Step 4: Run** `--filter 'Test_Questions_Kit|Test_Questions_Golden|Test_Registration_Questions|Test_Admin_Email_Answers|Test_Roster'`. Expected: PASS with no edits to existing tests.
- [ ] **Step 5: Commit** `refactor(questions): shared Anchor_Questions kit; Events delegates`.

### Task A3: `Anchor_Questions_Editor` (row editor + shared admin asset)

**Files:**
- Create: `includes/questions/class-anchor-questions-editor.php`, `includes/questions/assets/questions-editor.js`, `includes/questions/assets/questions-editor.css`
- Modify: `anchor-events-manager/anchor-events-manager.php` (`event_question_row_html` + its repeater markup, ~15495), `anchor-events-manager/assets/manager.js:331-~380` (remove the question repeater code), the Events admin enqueue (enqueue the shared asset where the repeater renders)
- Test: `tests/test-questions-kit.php` (add), golden test update

**Interfaces:**
- Produces: `Anchor_Questions_Editor::row_html( string $name_prefix, int $index, ?array $row = null, bool $template = false ): string`; `Anchor_Questions_Editor::repeater_html( string $name_prefix, array $rows ): string` (wrapper, rows, hidden `<prefix>_present` marker, add button, `<script type="text/template">` row template); `Anchor_Questions_Editor::enqueue(): void` (registers/enqueues handle `anchor-questions-editor`, version string bumped on change). JS binds to `.anchor-questions-editor` wrappers, so several can live on one screen.
- Events calls them with prefix `anchor_event_questions`, so posted field names are unchanged and `save_registration_questions()` is untouched.

- [ ] **Step 1: Write failing tests:** `row_html('anchor_event_questions', 0, ['label'=>'Q','admin_email'=>true])` contains `name="anchor_event_questions[0][admin_email]"` checked and the exact help text from Global Constraints; a prefix `anchor_cq_questions` produces `name="anchor_cq_questions[0][label]"`; `repeater_html` contains `anchor_event_questions_present`.
- [ ] **Step 2: Run, expect FAIL.** Then move the markup and JS; Events' `event_question_row_html()` delegates.
- [ ] **Step 3: Update the A1 row-editor golden literals** for the one intended change (the help text and any wrapper class rename), and nothing else. Diff the old and new literal in the commit message.
- [ ] **Step 4: Run** the questions/roster/admin-email filters plus `npx playwright test e2e/event-authoring.spec.js e2e/event-manager-authoring.spec.js e2e/purchase.spec.js` after a fresh `npm run env:seed`. Expected: PASS.
- [ ] **Step 5: Commit** `refactor(questions): one question editor for every module`.

### Task A4: `Anchor_Questions_Email` (one New-order-email renderer)

**Files:**
- Create: `includes/questions/class-anchor-questions-email.php`
- Modify: `anchor-events-manager/class-admin-email-answers.php` (becomes Events-side group building only), `anchor-events-manager/EMAILS.md` (one line: rendering lives in the kit)
- Test: `tests/test-questions-kit.php` (add)

**Interfaces:**
- Produces:
  - `Anchor_Questions_Email::rows_for( array $questions, array $stored ): array<int,array{label:string,answer:string}>`. Flagged (`admin_email`) and non-empty only, whitespace collapsed to one line, question order, stored answers already resolved by the caller.
  - `Anchor_Questions_Email::render_order_groups( array $groups, bool $plain_text ): string` where a group is `['heading'=>string,'entries'=>[['name'=>string|null,'rows'=>rows]]]`. Returns the markup (the hook echoes it). HTML per group: `<h2>` heading, per entry `<p style="margin:0 0 12px;">`, then `<strong>name</strong>` only when name is non-null, then each row as `<br />label: answer` (for a nameless entry, the first row has no leading `<br />`). Plain: `"\n{heading}\n"`, per entry `"\n{name}\n"` only when named, rows `"label: answer\n"`, each group as today.
- `Admin_Email_Answers::for_answers()` uses `rows_for()`; `render_order()` builds groups (attendee names default `Guest` as today) and echoes `render_order_groups()`.

- [ ] **Step 1: Write failing tests:** a nameless entry's HTML is exactly `<h2>Set</h2><p style="margin:0 0 12px;">Q1: a<br />Q2: b</p>`; plain is exactly `"\nSet\nQ1: a\nQ2: b\n"`; unflagged and blank answers are omitted; `<b>` in an answer is escaped in HTML and raw in plain.
- [ ] **Step 2: Run, expect FAIL; implement; run** `Test_Questions_Kit` + `Test_Questions_Golden` + `Test_Admin_Email_Answers`. Expected: PASS, golden email tests unchanged.
- [ ] **Step 3: Full suite gate:** `./vendor/bin/phpunit` (expect 0 failures; WooCommerce present) and `php -l` on every touched file. Confirm `wc -l` of `anchor-events-manager.php` + `class-woocommerce.php` is lower than on `origin/main`.
- [ ] **Step 4: Commit** `refactor(questions): one renderer for answers in the New order email`.
- [ ] **Step 5: PR 1.** Count files (`git diff --name-only origin/main...HEAD | wc -l`). Use CodeRabbit (cross-module refactor), check capacity first. Merge after green CI + owner sign-off; release patch per CLAUDE.md "Release Process".

---

## Part B — `checkout_questions` module (PR 2, minor release)

### Task B1: Module skeleton, question-set CPT, set edit screen

**Files:**
- Create: `anchor-checkout-questions/anchor-checkout-questions.php` (`\Anchor\CheckoutQuestions\Module`, same shape as `anchor-agreements/anchor-agreements.php`: WooCommerce test seam, missing-Woo notice), `anchor-checkout-questions/src/Content/QuestionSetPostType.php`, `anchor-checkout-questions/src/Admin/SetEditor.php`, `anchor-checkout-questions/src/Support/QuestionSets.php`
- Modify: `anchor-tools.php` (registry entry, Global Constraints copy), `composer.json` psr-4 (`"Anchor\\CheckoutQuestions\\": "anchor-checkout-questions/src/"`), `vendor/composer/autoload_*` via `composer dump-autoload`, `CLAUDE.md` module table row
- Test: `tests/test-checkout-questions-sets.php`

**Interfaces:**
- Produces `Support\QuestionSets`:
  - `const CPT = 'anchor_question_set'`, meta constants per Global Constraints.
  - `questions( int $set_id ): array` (normalized through `Anchor_Questions::normalize`)
  - `product_ids( int $set_id ): int[]`, `category_ids( int $set_id ): int[]`
  - `published_set_ids(): int[]` (publish status only, ordered by `menu_order`, then ID)
- CPT: not public, `show_ui` true, `show_in_menu` = `apply_filters( 'anchor_checkout_questions_parent_menu', 'woocommerce' )`, supports title. SetEditor: questions metabox via `Anchor_Questions_Editor::repeater_html( 'anchor_cq_questions', ... )`; "Where it applies" metabox with `wc-product-search` (products) and a category checklist; save on `save_post_anchor_question_set` with nonce, autosave and `edit_post` checks; honours the `anchor_cq_questions_present` marker the way Events' `save_registration_questions()` does.

- [ ] **Step 1: Failing tests:** saving posted rows + product ids `[12, 0, 'x']` + category ids stores normalized questions, `[12]`, ints; a save without the present marker and without rows leaves questions untouched; drafts are excluded from `published_set_ids()`; module constructs nothing with `$woocommerce_active = false`.
- [ ] **Step 2: Run, FAIL; implement; run, PASS.**
- [ ] **Step 3: Commit** `feat(checkout-questions): module skeleton and question sets`.

### Task B2: Which sets apply to a cart or order

**Files:**
- Create: `anchor-checkout-questions/src/Services/Matcher.php`
- Modify: `anchor-events-manager/anchor-events-manager.php` (public `product_is_event_ticket`; `WooCommerce::event_for_line()` at `class-woocommerce.php:344` is already public)
- Test: `tests/test-checkout-questions-matcher.php`

**Interfaces:**
- Produces `\Anchor\Events\Module::product_is_event_ticket( int $product_id, int $variation_id = 0 ): bool` (delegates to `WooCommerce::event_for_line()`; false when the WooCommerce integration isn't loaded).
- Produces `Matcher::for_lines( array $lines ): int[]` where `$lines` is `[['product_id'=>int,'variation_id'=>int], ...]`; returns unique set ids in `published_set_ids()` order. Matching on the parent product: explicit product id matches always; category matches (any of the parent's `product_cat` terms, including children of an assigned category) only when the line is not an event ticket. Event check only if the `events_manager` module is enabled and `\Anchor\Events\Module` is loaded.
- `Matcher::for_cart(): int[]` and `Matcher::for_order( \WC_Order $order ): int[]` build `$lines` and call `for_lines`.

- [ ] **Step 1: Failing tests** (Review Focus 1 & 2): set on product A and on A's category → `[set]` once; two lines both matching → once; variation line matches via parent; a child category of the assigned one matches; event ticket product in the category → not matched; same ticket assigned explicitly → matched; events module disabled → ticket matched by category; draft set never matched.
- [ ] **Step 2: Run, FAIL; implement; run, PASS.**
- [ ] **Step 3: Commit** `feat(checkout-questions): match sets to cart and order lines`.

### Task B3: Checkout: render, validate, store, Store API guard

**Files:**
- Create: `anchor-checkout-questions/src/Frontend/Checkout.php`, `anchor-checkout-questions/assets/checkout.css` (only if the controls need it)
- Test: `tests/test-checkout-questions-checkout.php`

**Interfaces:**
- Consumes: `Matcher::for_cart()`, `QuestionSets::questions()`, `Anchor_Questions::render_control()/sanitize_answers()`.
- Hooks: render on `woocommerce_after_order_notes`; validate on `woocommerce_after_checkout_validation` (10, 2); store on `woocommerce_checkout_create_order`; guard on `woocommerce_store_api_checkout_update_order_from_request` (throw `\Automattic\WooCommerce\StoreApi\Exceptions\RouteException` with code `anchor_cq_block_checkout`, status 400, as Events' `guard_block_checkout` does).
- Produces order meta `_anchor_cq_answers` = `list<array{set_id:int,set_title:string,questions:array,answers:array<string,string>}>`, one entry per applicable set, stored even when all answers are blank (so the order shows what was asked). Written with `$order->update_meta_data()`.
- Per set: a `<div class="anchor-cq-set">` with `<h3>` set title and one `form-row form-row-wide` per question, control name `anchor_cq[<set_id>][<key>]`, posted values redisplayed through `sanitize_answers()`. Errors: `sprintf( __( '%s is a required field.', 'anchor-schema' ), '<strong>' . esc_html( $label ) . '</strong>' )` via `$errors->add( 'validation', ... )`.

- [ ] **Step 1: Failing tests:** required text blank → one error naming it; required checkbox unticked → error; all answered → no errors; stored meta carries `set_title` and the questions snapshot; after renaming the set, changing a label and then deleting the set, the order's stored entry is unchanged (Review Focus 3); two sets both keyed `phone` keep separate answers (Review Focus 4); textarea answer keeps `\n`; Store API guard throws for a cart with a required set and does nothing for an optional-only set or no set; posted answers for a set id not in the cart are ignored.
- [ ] **Step 2: Run, FAIL; implement; run, PASS.**
- [ ] **Step 3: Commit** `feat(checkout-questions): ask, validate and store answers at checkout`.

### Task B4: Admin display: order metabox, product line, block-checkout notice

**Files:**
- Create: `anchor-checkout-questions/src/Admin/OrderMetabox.php` (pattern: `anchor-agreements/src/Admin/OrderMetabox.php`, HPOS + legacy screen ids), `anchor-checkout-questions/src/Admin/ProductNotice.php`, `anchor-checkout-questions/src/Admin/BlockCheckoutNotice.php`
- Test: `tests/test-checkout-questions-admin.php`

**Interfaces:**
- Consumes `_anchor_cq_answers` (B3), `Matcher::for_lines()` (B2).
- OrderMetabox: title `Checkout questions`; renders nothing (no box) when the meta is empty; per set `<h4>` title then a `<table>` of every question in the snapshot with its answer, blank answers shown as `—`, all escaped, labels from the snapshot (not the live set).
- ProductNotice: on `woocommerce_product_options_general_product_data`, a read-only line `Checkout questions: <a>Set A</a>, <a>Set B</a>` listing sets that would apply to this product (via `for_lines` with its id); nothing if none.
- BlockCheckoutNotice: reuse `\Anchor\Agreements\Admin\BlockCheckoutNotice::uses_checkout_block()` only if Agreements is loaded, otherwise the same `has_block( 'woocommerce/checkout', wc_get_page_id('checkout') )` check. Extract it to a kit-free helper only if a third consumer appears; two call sites stay as is. Notice text: `Checkout Questions works with the classic checkout only. On the block checkout, orders that need a required answer are refused and optional questions are not collected.` Shown to `manage_woocommerce` users when at least one set is published.

- [ ] **Step 1: Failing tests:** metabox output for the B3 snapshot shows renamed/deleted-set data from the snapshot; `<script>alert(1)</script>` answer is escaped (Review Focus 5); empty meta → no metabox registered; product line lists matching sets with edit links; notice shows only with the checkout block present and a published set.
- [ ] **Step 2: Run, FAIL; implement; run, PASS.**
- [ ] **Step 3: Commit** `feat(checkout-questions): show answers on the order and the product`.

### Task B5: New order email

**Files:**
- Create: `anchor-checkout-questions/src/Services/OrderEmail.php`
- Test: `tests/test-checkout-questions-email.php`

**Interfaces:**
- Consumes `Anchor_Questions_Email::rows_for()` / `render_order_groups()` (A4), `_anchor_cq_answers` (B3).
- Hook `woocommerce_email_order_meta`, priority 30 (Events is 20, so event groups print first), 4 args; acts only when `$email->id === 'new_order'`. One group per stored set with at least one flagged answered row: heading = snapshot `set_title`, one nameless entry. Echo the result.

- [ ] **Step 1: Failing tests:** HTML and plain output for a stored order match exact literals; unflagged questions absent; `customer_processing_order`, `customer_completed_order`, `customer_on_hold_order`, `cancelled_order` → empty; order with both an event line (Events answers) and a set → event `<h2>` appears before the set `<h2>` when both hooks run via `do_action( 'woocommerce_email_order_meta', ... )`; answer with `&` and newline: escaped + one line in HTML, raw + one line in plain (Review Focus 5).
- [ ] **Step 2: Run, FAIL; implement; run, PASS.**
- [ ] **Step 3: Commit** `feat(checkout-questions): answers in the New order email`.

### Task B6: End-to-end, express-checkout check, docs, PR 2

**Files:**
- Create: `e2e/checkout-questions.spec.js`, `anchor-checkout-questions/README.md`
- Modify: `bin/e2e-seed.sh` (idempotent: a simple product in a `Courses` category, a published set "Course intake" assigned to that category with one required text question `Licence number` flagged for admin email; write ids to `e2e/.seed.json`)
- Modify: `readme.txt` changelog (at release)

- [ ] **Step 1: Playwright spec:** add the course product to the cart, go to checkout, the "Course intake" block with "Licence number" is visible; place the order with it blank → WooCommerce error naming "Licence number"; fill it and place the order → order received page; as admin, the order screen's "Checkout questions" box shows the answer. Pin `timezoneId: 'Europe/Berlin'` (see the consent helper in `e2e/helpers/consent.js`).
- [ ] **Step 2: Run** `npm run env:seed && npx playwright test e2e/checkout-questions.spec.js`. Expected: PASS.
- [ ] **Step 3: Express-button check (spec call 1).** Download `woocommerce-gateway-stripe` and `woocommerce-paypal-payments` from wordpress.org into a fresh scratch dir; find their express/payment-request order-creation handlers and confirm each reaches `WC_Checkout::process_checkout()` (so `woocommerce_after_checkout_validation` runs). Record file:line evidence in `anchor-checkout-questions/README.md`. If either bypasses it, stop and report to the owner before adding anything.
- [ ] **Step 4: README** covers: what it does, where sets apply (product, category, event-ticket exclusion), classic-checkout-only and the block guard, storage key and snapshot, the email rule, the express-button evidence.
- [ ] **Step 5: Full gate:** `./vendor/bin/phpunit` (0 failures with WooCommerce), `npx playwright test` (full suite after a fresh seed), `php -l` on all new files, every new file under 1,000 lines.
- [ ] **Step 6: Commit and PR 2.** Count files; CodeRabbit yes (new module, checkout path), check capacity first. Merge after green CI + owner sign-off; release a minor version from `main` per CLAUDE.md.
