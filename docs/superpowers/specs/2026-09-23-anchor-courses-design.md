# Anchor Courses: design spec (repo adaptation of the build brief)

**Module:** `anchor-courses` (`\Anchor\Courses`), new, inside Anchor-Tools
**Date:** 2026-09-23
**Brief:** `2026-09-23-anchor-courses-BRIEF.md` (the owner's build plan, copied verbatim; **it is the base**)
**Companion:** `2026-09-23-virtual-events-stream-design.md` §7 (the events contract this module consumes)

The brief is authoritative for the domain model (§5–§13), tables (§7), quiz engine (§8), progress and completion services (§9–§11), CE and certificates (§12–§13), REST (§14), public API (§15), hooks (§16), security, idempotency, migrations, testing, phases and the "critical rules" (§37). This document only records **what changes because it lives in this repo**, **what is decided where the brief leaves options**, and **how it connects to events**. Where this document and the brief disagree, this document wins, and the disagreement is listed in §1.

---

## 1. Deltas from the brief

| Brief says | We do | Why |
|---|---|---|
| Standalone plugin `anchor-courses`, namespace `AnchorCorps\Courses` (§4, §29) | **Module inside Anchor-Tools**: `anchor-courses/anchor-courses.php` defines `\Anchor\Courses\Module`, registered in `anchor_tools_get_available_modules()` as `courses`. Namespace **`Anchor\Courses`**, PSR-4 root `anchor-courses/src/` added to `composer.json` `autoload` (vendor is committed; run `composer dump-autoload` and commit the result). | Owner decision in brainstorm: one loader, one test harness, in-process access to events. Repo convention is `\Anchor\{Module}\Module`. |
| `src/Plugin.php` bootstrap | `Module` class is the bootstrap (constructor registers hooks; `Module::instance()`), same as events. Everything else under `src/` per the brief's tree. | Module loader instantiates the class named in the registry. |
| Table prefix `{$wpdb->prefix}anchor_courses_` | Same. Schema version option `anchor_courses_db_version`, `autoload=false`. Migrations run on `plugins_loaded` after module load and on `admin_init`, never only on activation (Anchor-Tools has no per-module activation hook). | Repo convention + brief §28. |
| PHP 8.1+, "modern syntax", strict typing, typed properties | Yes; `composer.json` already pins 8.1. `declare(strict_types=1)` in `src/`, not in the module entry file. | |
| React "where it materially improves" (§21) | **No React, no bundling.** jQuery IIFEs, `jquery-ui-sortable` (bundled with WP) for the curriculum and question builders. | Repo rule: raw PHP/CSS/JS, jQuery only, CI minifies. |
| Text domain unspecified | `'anchor-schema'`. | Repo convention. |
| Certificates: HTML→PDF (§13) | **Phase 1 = HTML certificate page** (`/certificate/{token}/`) with a print stylesheet and a public verification route; the `file_path` column is nullable and stays empty. **PDF generation is Phase 4b**, gated on choosing a library (Dompdf would add a large committed vendor tree). Email "your certificate" links to the page. | Ship the CE workflow without a dependency decision; nothing in the schema changes when PDF arrives. |
| Integrations as sub-plugins (§17) | `src/Integrations/{WooCommerce,Events,Analytics}.php`, each `class_exists`/module-enabled guarded, loaded by `Module`. Webhooks (§20) deferred. | Same plugin; no second update stream. |
| Course access type `course_access_type` (§6.3) and self-enrolment | **Removed.** There is no access-type field and no role picker. Every course mints its own role at creation; **holding that role is enrolment** (§3). It is gained by purchase (WooCommerce, §4), by an admin on the course's Learners tab, or by anything else that can add a WordPress role. | Owner decision 2026-09-23: mirror the events manager exactly (one predefined role per thing, granted on purchase, grantable by hand), nothing to configure. |
| Learner dashboard as shortcodes (§23) | Shortcodes as listed **plus** a `[anchor_course_room]`-free design: live sessions render through the events module (§3.3), not a courses copy. | No second stream renderer. |
| Suggested endpoints `/me/...` | Namespace `anchor-courses/v1` as brief. All routes `permission_callback` real checks, never `__return_true`. | Brief §25. |

## 2. Repo layout

```
anchor-courses/
├── anchor-courses.php            # \Anchor\Courses\Module — hooks, module glue, requires nothing else by hand
├── src/                          # PSR-4 Anchor\Courses\
│   ├── Content/   CoursePostType.php LessonPostType.php QuizPostType.php Curriculum.php
│   ├── Database/  Migrations.php + one Repository per table (brief §7)
│   ├── Domain/    Enrollment.php Progress.php QuizAttempt.php Credit.php Certificate.php (value objects)
│   ├── Services/  EnrollmentService ProgressService QuizService CompletionService CreditService CertificateService
│   ├── Rest/      Routes.php + one controller per resource
│   ├── Admin/     CourseEditor QuizEditor LearnerReports EnrollmentManager
│   ├── Frontend/  Shortcodes Templates Assets
│   ├── Integrations/ Events.php WooCommerce.php Analytics.php
│   └── Support/   Roles.php Uuid.php Clock.php Capabilities.php
├── templates/  course.php lesson.php quiz.php dashboard.php certificate.php
├── assets/     admin-curriculum.js admin-quiz.js quiz.js frontend.css admin.css
└── COURSES.md  (public hooks + API, kept current per brief §16 "document every public hook")
tests/test-courses-*.php          # in the repo's existing tests/ dir, base class Anchor_Courses_TestCase
```

CPTs: `anchor_course`, `anchor_lesson`, `anchor_quiz` (brief §6.1), `show_in_menu` via `apply_filters('anchor_courses_parent_menu', true)`. Curriculum stored on the course as the brief's module/items JSON (§6.2) under `_anchor_course_curriculum`, module UUIDs from `Support\Uuid`.

Capabilities per brief §24, mapped to `administrator` on migration; `Support\Capabilities::cap( 'manage' )` mirrors `Roster::cap()` in events.

## 3. Events contract (consumer side)

Everything below reads from the events module through the surface defined in the stream spec §7. **Courses never queries event tables, seat posts, or `_anchor_event_*` meta directly.**

### 3.1 Roles are the currency, exactly as in events

Each course owns two capability-less roles, managed by `Support\Roles`:

| Role | Slug | Display name | Minted | Meaning |
|---|---|---|---|---|
| **Access** | `anchor_course_{id}` | `Course: {title}` | on `save_post` of a published course (eager: the role must exist before anyone can be given it) | **Holding it is enrolment.** |
| **Completion** | `anchor_course_{id}_completed` | `Completed: {title}` | lazily, on the first completion | What an event or another course lists as a **prerequisite**. |

Both are renamed when the title changes and never auto-deleted; the course editor has an explicit **Delete role** action for each (confirm dialog, strips holders), like the event console's Basics panel.

**Enrolment = the access role.** `Integrations\Events`-style listeners are replaced by one `Support\Roles` listener on core `add_user_role` / `set_user_role` / `remove_user_role`, filtered to slugs matching `anchor_course_{id}` only:

- role gained → `EnrollmentService::enroll( $user_id, $course_id, ['source' => ..., 'source_id' => ...] )`, idempotent on the `(user_id, course_id)` unique key. The source is whatever granted the role: `woocommerce` + order id from the WooCommerce adapter, `manual` + actor id from the Learners tab, `role` for anything else.
- role lost → `anchor_courses_role_loss_policy` filter (`keep|expire|cancel`, default `keep`), matching the events module's "access outlives the thing" stance. `keep` leaves the progress rows so re-adding the role resumes where they were.

There is **no auto-enrol picker, no `open` self-enrolment, no `roles` list**. A free learner is someone an admin adds on the Learners tab (name + email; creates the account if needed through the same no-mail path events uses; grants the access role). A paid learner is someone whose order contains a product mapped to the course (§4). Nothing else grants access, and the module never enrols anyone it was not told to.

**Prerequisites** (`course_prerequisites`, brief §6.3) remain role slugs, chosen from a list of **completion roles and event roles only** (`anchor_course_*_completed`, `anchor_event_*`), never built-in or WooCommerce roles: `set_user_role` fires on every new account, so `customer` or `subscriber` there would gate on nothing. `EnrollmentService::can_enroll()` refuses when prerequisites are unmet, which blocks the Learners-tab add and the WooCommerce adapter alike (the adapter records a `blocked_prerequisite` note on the order instead of enrolling).

**Events side, for symmetry:** events mint `anchor_event_{id}` by default (stream spec §3.1: the switch is on for every plugin-registered event), so any past or future event is a usable prerequisite; one whose author switched it off is not, until it is switched back on and backfilled.

### 3.2 Actions and filters we hook

| Events surface | Courses use |
|---|---|
| `anchor_events_can_access_stream( $allowed, $event_id, $session_index, $user_id )` | **Phase 5**: a lesson of type `live_session` may set `require_prior_items = true`; the integration vetoes stream access until the learner's required items before that lesson are complete. Off by default. |
| `\Anchor\Events\Module::instance()->get_sessions( $event_id )` and `Stream_State::for_event()` | render the live-session lesson (§3.3) |
| `\Anchor\Events\Module::instance()->room_url( $event_id )` | the lesson's "Join" button |

### 3.3 Live-session lesson (Phase 5)

A lesson gains `lesson_type ∈ {content, live_session}`. A `live_session` lesson stores `event_id` (+ optional `session_index`) and renders: the session schedule and state from the events read API, and a "Join the livestream" button to the room. It **does not embed the stream**; the room is the only player. Completion mode for it is `manual` in Phase 5 (`external_event` — auto-complete when the events module reports attendance — is future, brief §9). If the events module is disabled the lesson renders "Live session unavailable" and is treated as optional.

## 4. Decisions where the brief leaves options

- **Progression default** `sequential`; **completion default** `all_required_items` (brief §6.3).
- **Timer expiry default** auto-submit saved answers (brief §8.5).
- **Enrolment via WooCommerce** is a role grant: on `processing` **or** `completed` (filterable, because this store's event tickets confirm on `processing`) the adapter calls `EnrollmentService::can_enroll()` for each course mapped to a purchased product BEFORE adding `anchor_course_{id}`; when prerequisites are unmet the adapter records a `blocked_prerequisite` note on the order and leaves the access role absent for that course (matching `Support\Roles::grant_access()`, which runs the same `can_enroll()` check before `add_role()`), otherwise it adds the role and the role listener creates the enrolment with `source=woocommerce`, `source_id=order_id`. Refund/cancel removes the role (`anchor_courses_wc_refund_policy`: `remove_role` default, or `keep`), and the role-loss policy decides what happens to progress. Product↔course mapping is product meta `_anchor_course_ids[]` edited on the product. The customer account WooCommerce already creates is the learner; guest checkout uses the same account-resolution path events uses.
- **Certificate numbering** `AC-{YYYY}-{8-digit zero-padded id}` from an `AUTO_INCREMENT` on the certificates table, never a counter option (race-free).
- **CE fields** per brief §12 on the course: `ce_credits`, `ce_type`, `ce_provider_name`, `ce_provider_number`, `ce_expires_days`. DEKA's Academy is dental CE; the provider block renders on the certificate.
- **Analytics** `dataLayer` events per brief §19, IDs only, never names or emails.
- **Uninstall**: `uninstall.php` in Anchor-Tools already exists; courses adds a **guarded** branch that drops its tables only when `anchor_courses_delete_data_on_uninstall` is set. Deactivation deletes nothing (brief rule 9).

## 5. Phases

Brief §34 phases are kept and numbered the same. Two adjustments:

- **Phase 0** also adds the module registry entry, the PSR-4 autoload, `Anchor_Courses_TestCase`, and `COURSES.md` skeleton.
- **Phase 2** owns the access/completion roles, the role listener and the Learners tab, because enrolment IS the role. **Phase 5 (integration layer)** keeps the events read API, the live-session lesson and the optional stream veto; **Phase 6** is the WooCommerce adapter (role grants on order status).

Milestone gate: the brief §38 path (course → module → lesson → quiz 80% / 2 attempts → fail → pass → 100% → completed → 2 CE credits) must pass as a PHPUnit integration test **and** a Playwright E2E before Phase 5 starts.

## 6. Testing

Brief §31 applies. Repo mechanics: PHPUnit in `tests/test-courses-*.php` on `Anchor_Courses_TestCase` (boots the module the way `Anchor_Events_TestCase` boots events; enables both modules so integration tests can run), `composer test` unchanged. Playwright specs under `e2e/courses/` with fixtures from `bin/e2e-seed.sh`. Unit-level pure logic (grading, progress math, attempt rules, certificate number format, curriculum validation) is tested without WordPress where possible via plain PHPUnit classes in `tests/unit/`.

## 7. Out of scope

Everything in brief §33, plus: PDF certificates (Phase 4b), webhooks, `external_event` completion, instructor roles, any stream rendering inside courses, self-enrolment (`open` courses), and event-attendance→course auto-enrolment (if wanted later it is a "also grant these roles" field on the **event**, not a picker on the course).
