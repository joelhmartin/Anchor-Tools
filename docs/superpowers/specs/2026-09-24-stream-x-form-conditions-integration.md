# Integration analysis: virtual-events stream (A) × registration form conditions (B)

**Date:** 2026-09-24 · **Read-only analysis.** No code was changed.
**A** = `feat/virtual-events-stream` (worktree `anchor-tools-wt-stream`), plan
`docs/superpowers/plans/2026-09-23-virtual-events-stream.md`, 24 tasks, **1–10 landed**
(`origin/main..HEAD` = `7f6b382..0ace7c4`), 11–23 pending.
**B** = `docs/registration-form-conditions` (main checkout), spec + plan only, 11 tasks.
All line numbers are **A's branch at `0ace7c4`** unless marked "(main)".

---

## 0. The premise that does not survive contact with the code

B's spec §1 says: *"this spec adds nothing to its keys and both can land in either order."*
That is true of **semantics** and false of **merge**. The two branches edit the same array in
`create_seat()`, the same notice dictionary, the same two export functions, the same console
section, and both claim version **3.31.0**. B's plan also names the wrong companion branch
(`docs/stream-and-courses-design`; the live one is `feat/virtual-events-stream`), and **every
line anchor in B's plan is stale by 150–555 lines** against A's branch:

| B's anchor (main) | Actual on A | Δ | | B's anchor (main) | Actual on A | Δ |
|---|---|---|---|---|---|---|
| `normalize_registration_questions()` ~12992 | **13536** | +544 | | message map ~9125 | **9464** | +339 |
| `sanitize_registration_answers()` ~12805 | **13349** | +544 | | `persist_event_authoring()` ~5385 | **5537** | +152 |
| `render_registration_question_control()` ~12863 | **13407** | +544 | | console Registration §~8150 | **8490** | +340 |
| `get_registration_questions()` ~12696 | **13251** | +555 | | repeater `<thead>` ~8165 | **8506** | +341 |
| `event_question_row_html()` ~13094 | **13638** | +544 | | frontend enqueue ~6885 | **7210** | +325 |
| form open tag ~9484 | **9841** | +357 | | `Roster::export_columns()` ~1120 | **1168** | +48 |
| question loop ~9532 | **9896** | +364 | | `Roster::frontend_add_form()` ~1938 | **1990** | +52 |
| `handle_registration()` name/email ~9954 | **10323** | +369 | | `Occurrences::authored_child_meta_keys()` ~1551 | **1551** | 0 |
| `claim_seats()` call ~10012 | **10406** | +394 | | `Registrations::create_seat()` `$meta` ~320 | **317** | −3 |

B's implementer must re-derive every anchor against whatever base it starts from. This alone
argues against branching B from `main`.

---

## 1. File and function collision map

| File | Function / region | A changes | B changes | Kind | Merge difficulty |
|---|---|---|---|---|---|
| `anchor-events-manager.php:9464-9471` | `render_registration_notice()` message dictionary | **landed** T10 — `registration_prerequisite` → `Entitlements::default_prerequisite_message()` (:9469) | T10 — adds `registration_code` | **same lines** | trivial textual, but see §4 (shared vocabulary) |
| `anchor-events-manager.php:10284-10430` | `handle_registration()` | **landed** T10 — `'prerequisite'` refusal at **:10398-10401**, after the window/full checks | T10 — access-code refusal **after the name/email check (:10326)**, before seat work | **same function, different insertion points** | textually easy, **semantically wrong as specified** — see §2 |
| `anchor-events-manager.php:9841-9905` | `render_registration_form()` form tag + question loop | **landed** T10 — `'prerequisite'` blocked-state branch at **:9823-9831** (returns before the form) | T5 — `data-anchor-q-scope` on the form tag (:9841), wrapper attrs + heading branch in the loop (:9896-9905); T10 — code input before the Name field (:9863) | **same function, adjacent regions** | easy; A's branch returns early so the two never both render |
| `anchor-events-manager.php:8490-8512` | console Registration section / questions repeater | **pending T17** — inserts the shared `render_access_fields()` block (switch + required-roles picker) here | T9 — "Access codes" textarea + `_present` marker here; T8 — two new `<th>` in the repeater `<thead>` (:8506) | **same UI region** | medium — two unrelated things both called "Access" in one panel |
| `anchor-events-manager.php:5537-5600` | `persist_event_authoring()` | **landed** T4 (stream embed, forced switch) | T9 — `save_access_codes()` beside `save_registration_questions()` (:5579) | same function, adjacent | easy |
| `anchor-events-manager.php:5202-5295` / `:5699-5720` | `event_authoring_input()` / `sanitize_event_type_input()` | **landed** T1/T4 — 8 stream keys, `sanitize_modality()`, `sanitize_role_slugs()`, `stream_embed_input()`, `access_role_enabled_input()` (:5334) | **none** — B uses a dedicated saver, per the questions pattern | **none** | none |
| `anchor-events-manager.php:13536-13608` | `normalize_registration_questions()` | none | T1 — `heading` type, `show_if_*`, validation pass | **none** | none |
| `anchor-events-manager.php:13349-13405` | `sanitize_registration_answers()` | none | T2 — visibility-aware rewrite | **none** | none |
| `anchor-events-manager.php:13638-13674` | `event_question_row_html()` | none | T8 — two cells, `heading` option | **none** | none |
| `class-registrations.php:944-968` | `capacity_decision()` | **landed** T10 — `'prerequisite'`, ranked **below** closed/full/sold-out (ledger ruling), replaces `open`/`waitlist` only | **bypasses it entirely** (form-level) | **same data structure (the decision vocabulary)** | none textually; see §2 |
| `class-registrations.php:317-364` | `create_seat()` `$meta` array | **landed** T8 — `_anchor_event_user_id` (:337-339); `anchor_events_seat_created` fires at :370 | T10 — `_anchor_event_access_code` from a new `access_code` arg | **same data structure** | easy conflict; `wp_slash()` at :350 already covers B's string; `claim_seats()` `array_merge( $payload, … )` (:1175) passes it through unchanged |
| `class-registrations.php:1674` / `:1772` | `seat_dto()` / `get_export_rows()` | **pending T18** — `access` export scope | T10 — `access_code` in both | **same functions** | medium |
| `class-roster.php:1168-1192` / `:1208-1227` | `export_columns()` / `export_row_cells()` | **pending T18** — Access column + `access` scope | T10 — "Access Code" column after "Seat Index" (:1191), matching cell (:1224) | **same lines** | **hardest merge point** — see R1–R3 |
| `class-roster.php:345` / `:541` / `:1990` / `:2215` | `render_add_form()`, `question_rows()`, `frontend_add_form()`, `question_columns()` | **pending T18** — "Add person by email" header, Access column in the list table | T7 — scope attr, wrapper attrs, heading branch, answerable-only columns | **same functions, different regions** | medium |
| `class-roster.php:162-176` | `access_state()` | **landed** T9b (`off\|yes\|manual\|no`), consumed by pending T18 | none | none | none |
| `class-roster.php:781-786` | `handle_add()` → `ensure_user()` | **landed** T8 | T7 touches the *form*, not the handler | none | none |
| `class-occurrences.php:155-183` | `INHERITED_KEYS` | **pending T20** — 8 stream keys | none | none | none |
| `class-occurrences.php:1551-1568` | `authored_child_meta_keys()` | none | T9 — `ACCESS_CODES_META` | **none** | none — ~1,400 lines apart; both merged in `inherited_meta_keys()` (:1499) |
| `class-woocommerce.php:1871` / `:2264` | `render_checkout_attendee_fields()` / `enqueue_checkout_assets()` | none | T6 — scope attr, wrappers, heading, enqueue | **none** | none |
| `class-woocommerce.php:3082-3106` | `sync_order_seats()` — `create_seat()` then `ensure_user()` | **landed** T8 | none | **none** (different function from the render path) | none |
| `assets/manager.js` | `initQuestionsRepeater()` | pending T17 touches the Location/Registration partials, not this | T8 rewrites it | none expected | low |
| `anchor-tools.php` / `Anchor-Tools.php` / `readme.txt` | `Version:` header | **T23 explicitly does NOT bump** ("the release is cut from `main` by the owner") | **T11 bumps 3.30.1 → 3.31.0** | **same lines, same target version** | **must be resolved before either merges** |

---

## 2. Conceptual overlap: two gates on registration

A's `required_roles` is a **property of the viewer**, so it can be answered at render time and
therefore belongs in `capacity_decision()` (`class-registrations.php:958-966`) — the single
authority the date picker, the CTA, the storefront row and
`WooCommerce::filter_is_purchasable()` all consult, which is why they refuse together.

B's access code is a **property of the submission**. It cannot be answered at render time: you
cannot know whether an anonymous visitor holds the code until they type it. Routing it through
`capacity_decision()` would make a code-gated event answer `'access_code'` permanently, the form
would never render, and **nobody could ever type a code**. B is right: this is a form-level check.
Do not "unify" it into the decision authority.

That said, three things need fixing, all of them consequences of the gate living only in the free
form:

1. **Ordering.** B inserts its check after the name/email check (main ~9954 → A **:10326**),
   which puts it *above* the closed / full / prerequisite refusals at **:10380-10401**. A's Task 10
   ledger ruling is explicit: viewer-independent inventory truths rank above viewer-dependent
   refusals. A secret-based refusal is the most viewer-dependent of all. **Move B's check to
   immediately after :10401**, still before `claim_seats()` (:10406). Cost: one line moved.
2. **WooCommerce.** B §2 #9 and §7 decline a checkout gate. The consequence is not neutral: on a
   `wc`-mode event the code field never renders and configured codes are **silently inert** —
   exactly the event an author most wants private. `render_registration_form()`'s mixed free+paid
   re-entry (`is_rendering_free()`, :9880-9905) means a mixed event gates the free tier and not
   the paid one, which is worse than either. Fix without building the checkout gate: a save-time
   notice through the existing `queue_group_notice()` mechanism (the pattern A already uses for
   `stream_embed_invalid`, :5793) when codes are saved on an event with `registration_mode = 'wc'`
   and no active free tier. Cost: ~20 lines, one test.
3. **Date picker / CTA / storefront / purchasability are correctly untouched.** Anyone may *see*
   the form; only a correct code completes it. No change needed — but say so in B §4.3 so a later
   reader does not "fix" it by adding a decision branch.

**Recommended shape:** keep the code gate in `handle_registration()`, ranked last; share the
refusal vocabulary with A (§4); warn at save time on wc-mode events. Total cost ≈ half a task.
Also document the division of labour, because it is not obvious: **codes gate registration, roles
gate attendance.** A roster manual add stores `''` as the code (B §4.3) and still mints the role
and room access via A's Tasks 8/18 — so a code is never an access control for the stream.

---

## 3. Sequencing

**Recommendation: execute B in its own worktree, branched from A's branch HEAD (not `main`), and
merge second — with B's Tasks 9 and 10 held until A's Task 20 is complete.**

Why not rebase-and-wait: A has 13 tasks left plus its per-task review loop; blocking B entirely
costs days of wall clock for a conflict set that is enumerable. Why not fold into A: B is 11
tasks with its own TDD loop, and A's plan is already 24 tasks — folding pushes it past 35 and
delays the room, which is the date-driven half. Why not branch from `main`: the anchor table in
§0 — every one of B's 40-odd line references is wrong there, and six functions conflict.

**B's Tasks 1–8** (question model, resolver, render helpers, JS runtime, free form, checkout,
roster forms, console repeater) touch almost nothing A's remaining tasks touch. Start them now,
based on `0ace7c4`.
**B's Tasks 9–10** (access codes: console Registration section, occurrences, roster export,
`create_seat()`, `handle_registration()`, message map) land exactly where A's Tasks 16/17/18/20
land. Hold them.

What B's implementer must know about each pending A task it meets:

- **T16 (metabox Access section).** A adds `render_access_fields( $event_id, $meta, $admin )`, a
  partial shared by metabox and console, containing `access_role_enabled` (+ its hidden `0`
  companion) and the required-roles picker. B's codes field must **not** go inside it and must not
  reuse the word "Access". The metabox renders neither questions nor codes; B's `_present` marker
  rule (`:13611`) is what keeps a metabox save from blanking them — do not add a codes control to
  the metabox without the marker.
- **T17 (console parity).** A inserts its Access block into the same
  `<div class="anchor-event-section" data-step="3">` at **:8490-8495**. B's codes textarea goes
  **after** A's block, in its own labelled group ("Registration codes").
- **T18 (Roster).** `Roster::access_state()` already exists (**:162-176**, from T9b); T18 adds a
  list-table **Access** column, `handle_grant()`/`handle_revoke()`/`add_access_by_email()`, and an
  **`access` export scope**. B's CSV column must slot **after "Seat Index" (:1191) and before A's
  Access column**, with the matching cell inserted at the same index in `export_row_cells()`
  (:1218-1224). A's `access` scope emits rows for role holders **with no seat**, so B must read
  `$row['access_code'] ?? ''`, never `$row['access_code']`.
- **T19 (Basics role panel), T11-T14 (room, REST, token, `{room_link}`), T15 (tier modality):**
  no overlap.
- **T20 (inheritance).** A edits `INHERITED_KEYS` (**:155**); B edits `authored_child_meta_keys()`
  (**:1551**). Different constants, different semantics, no conflict — see §4/B-7.
- **T21 (Playwright).** A's free-registration scenario must not seed an event with codes, or it
  will start failing when B lands.
- **T23 (release note).** Must describe both behaviours in one paragraph if they ship together.

**Concrete conflicts to expect on the merge** (in descending pain): `Roster::export_columns()` /
`export_row_cells()`; the console Registration section at `:8490`; `Registrations::create_seat()`'s
`$meta` array; `seat_dto()` / `get_export_rows()`; the notice dictionary at `:9469`;
`handle_registration()`'s refusal ladder; the three version-header files.

---

## 4. Spec-level changes to request

**B-1 — drop the version bump (Task 11 Steps 1 and 4).** A's Task 23 records that the 3.31.0 bump
and tag are cut from `main` after merge, per CLAUDE.md. B's plan bumps on the branch. Both cannot
be 3.31.0. Keep B's changelog prose as an unnumbered "unreleased" block; the owner numbers it.
*(A's spec §10.1 also says "Ship as Anchor-Tools 3.31.0", contradicting its own Task 23 — fix the
spec to match the plan.)*

**B-2 — stop calling it "Access".** A owns that word across `access_role_enabled`,
`render_access_fields()`, `Roster::access_state()`, the roster Access column and the `access`
export scope. B should use **"Registration code"** for the console label, the CSV header and the
message key. The internal meta key `_anchor_event_reg_access_codes` can stay.

**B-3 — one refusal vocabulary.** `registration_prerequisite` (A, `:9469`) and B's new key are the
same species: refused for a non-capacity reason. Ask for them to sit adjacent in the dictionary
with a cross-reference comment, and for B's message to be sourced the way A sources its own
(`Entitlements::default_prerequisite_message()`) rather than an inline string.

**B-4 — pin the refusal order** in B §4.3: the code check runs *after* closed/full/prerequisite.

**B-5 — warn on wc-mode events** where codes are configured but cannot apply (§2 item 2).

**B-6 — correct the companion-branch name and re-derive every line anchor** (§0).

**B-7 — challenge the suggestion to move B's inheritance to `INHERITED_KEYS`: B is already right.**
`INHERITED_KEYS` (`class-occurrences.php:155`) is documented as "shared schema facts"; 
`authored_child_meta_keys()` (`:1551-1568`) is the list for **content an author typed on one date**,
and it is what triggers the destructive-inheritance notice when a parent's empty value deletes a
child's. Access codes are authored content, exactly like the question set that is already the
first entry in that list (`:1554`). B's placement gives authors the notice; A's would not.

**B-8 — retract the byte-identity claim.** B §8 says "the roster/CSV column set … is
byte-identical to today". False once A's Task 18 adds its Access column. Say "unchanged except for
the columns this release and the stream release each append, in the order fixed in §4/A-3".

**A-1 — publish the `access` export row shape** in A's spec §7, since B must emit a cell for rows
that have no seat.

**A-2 — fix the final CSV column order in both specs**: `… Seat Index → Registration Code →
Access → question columns`. Whoever merges second appends to the other's, not to `main`'s.

---

## 5. Risks if both land uncoordinated

**R1 — two adjacent columns called "Access Code" and "Access"** in the same CSV, meaning entirely
different things (a typed secret vs. a WordPress role). Guaranteed support ticket.

**R2 — header/cell count drift.** `export_row_cells()` (`class-roster.php:1208`) is shared by
`export_table()` and the all-dates export (which *prepends* a Date column). If A and B each add a
header in one place and a cell in another, every column after the insertion point shifts by one
and **the CSV is silently wrong** — no error, no test failure unless a test asserts a full row.

**R3 — `access` scope rows have no `access_code` key.** A's manual-grant rows are not seats. An
unguarded `$row['access_code']` warns in PHP 7.4 and fatals in 8.x with the right error handler.

**R4 — double / misleading notices.** With B's specified insertion point, a visitor who is not
role-eligible and types a wrong code sees "That registration code is not valid" for an event they
could never have joined. With the §2 fix, they see the eligibility message, which is the truth.

**R5 — a hidden question mistaken for a gate.** B's conditions are cosmetic: a hidden required
question stores `''` and is not enforced (B §4.2). Nothing stops an author from building
"Are you a partner? → [partner code question]" and believing it gates entry. A's `required_roles`
is the only real identity gate and B's codes are the only real secret gate. Both specs should say
so in one sentence.

**R6 — blank answers that look like answered-and-empty.** A hidden required question and a visible
question left blank are indistinguishable on the seat and in the CSV. Any downstream consumer that
treats a required column as non-empty (an importer, a CE-credit report, a badge print) breaks
silently. Worth one line in B §4.2 and a note in the CSV header row.

**R7 — the account surprise, doubled.** A's `access_role_enabled` now defaults **true**
(`:2905`), so every confirmed seat mints a role and an account. B adds a required code field to
the same form. The rollout note in A's Task 23 must describe both in one paragraph, or an operator
reading two changelogs will not connect "I added a code" with "my registrants now have logins".

**R8 — inheritance notice untested.** B adds codes to `authored_child_meta_keys()`, so a parent
with no codes now **deletes** a child's codes and queues the authored-content notice. B's plan has
no test for that path; A's Task 20 tests only its own keys.

**R9 — console repeater column count.** B's Task 8 adds two `<th>` at `:8506` and two `<td>` in
`event_question_row_html()` (`:13638`). If A's Task 17 touches that table (it should not), the
header and the row template drift and the repeater renders ragged.
