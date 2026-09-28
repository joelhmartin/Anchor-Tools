# Anchor Courses

Module key `courses`, class `\Anchor\Courses\Module`, namespace `Anchor\Courses`
(PSR-4 root `anchor-courses/src/`). Bootstrap: `anchor-courses/anchor-courses.php`.
Text domain `anchor-schema`. All times stored UTC (`Y-m-d H:i:s`); `Support\Clock`
reads "now" through the `anchor_courses_now` filter so tests can freeze it.

A small LMS: courses -> modules -> lessons -> quizzes, enrolment, progress,
server-graded quizzes, idempotent completion, CE credits and HTML
certificates. It has no hard dependency on WooCommerce or the events module -
both are optional integrations, guarded so the core LMS works without either.

The one rule everything else follows: **holding the role `anchor_course_{id}` IS
enrolment.** Nobody enrols themselves; access arrives as a role, granted by an
admin (Learners tab), a purchase, the PHP API, WP-CLI or any plugin.

**Specs:** `docs/superpowers/specs/2026-09-23-anchor-courses-BRIEF.md` (base) and
`docs/superpowers/plans/2026-09-23-anchor-courses.md` (repo adaptation, wins on
conflict).

---

## Overview and enabling the module

Like every other Anchor Tools module, `courses` is registered in
`anchor-tools.php`'s module list (`'courses' => [ 'label', 'description',
'path' => 'anchor-courses/anchor-courses.php', 'class' => '\Anchor\Courses\Module'
]`) and toggled from the plugin's own settings page - there is no separate
plugin-activation hook for a module, so `anchor-courses.php`'s bootstrap
(`Module::__construct()`) runs its own convergence on every load: migrations
run from the constructor and again on `admin_init` (`Migrations::maybe_migrate()`),
and the daily cron is (re)scheduled from the constructor if missing.

Once enabled, `Module::instance()` exposes the module's services as public
properties - see "Services" below - and three post types (`anchor_course`,
`anchor_lesson`, `anchor_quiz`), the REST namespace `anchor-courses/v1`, six
shortcodes, and the public PHP API in `api.php` all become live immediately;
nothing further needs enabling per-feature. WooCommerce and the events module
are each detected and wired in only when active (`class_exists('WooCommerce')`,
`Integrations\Events::available()`); their absence never breaks the core LMS.

### Services (`Module::instance()`)

| Property | Class | Owns |
|---|---|---|
| `enrollments` | `Services\EnrollmentService` | the enrolment row and its status machine; `can_enroll()`, `is_enrolled()`, the expiry sweep |
| `progress` | `Services\ProgressService` | the one progress calculation, item availability, lesson start/complete, `record_item()`, `reset_course()` |
| `quizzes` | `Services\QuizService` | attempts: start/resume, answer, atomic submit, timers, the attempt sweep |
| `credits` | `Services\CreditService` | CE credit records |
| `certificates` | `Services\CertificateService` | issue, snapshot, render, token lookup |
| `completion` | `Services\CompletionService` | the once-only completion pipeline (injected into `progress`) |
| `shortcodes` | `Frontend\Shortcodes` | shortcodes plus `render_lesson()` / `render_quiz()` |
| `woocommerce` | `Integrations\WooCommerce\|null` | the product/course mapping adapter; `null` when WooCommerce is inactive |

Always use these instances. A freshly constructed `new ProgressService()` has no
completion pipeline and will never complete a course.

---

## Concepts

### Roles are the integration currency

Every course owns **two** capability-less roles, both managed by `Support\Roles`:

| Role | Slug | Display name | Minted | Means |
|---|---|---|---|---|
| **Access** | `anchor_course_{id}` | `Course: {title}` | on `save_post_anchor_course` of a **published** course | **Holding it is enrolment.** |
| **Completion** | `anchor_course_{id}_completed` | `Completed: {title}` | lazily, on the first completion | what another course or an event lists as a prerequisite |

- Both are renamed when the title changes (`save_post_anchor_course`, priority
  20) and never deleted automatically. The course editor's **Course Role**
  panel has a **Delete role** action per role (strips every holder, then
  `remove_role()`). Deleting the access role of a published course is not
  permanent: the next save of that course mints it again
  (`ensure_access_role()`), empty.
- **Granting:** `Roles::grant_access( $user_id, $course_id, $source = 'manual', $source_id = '' )`
  checks `EnrollmentService::can_enroll()` (user and course exist, availability
  window, prerequisites) and then adds the role. **Revoking:**
  `Roles::revoke_access( $user_id, $course_id, $source = 'manual', $source_id = '' )`.
- **The listener:** core `add_user_role` / `set_user_role` / `remove_user_role`
  (plus `deleted_user` and multisite `remove_user_from_blog`), watching for
  slugs matching `anchor_course_{id}` exactly - never the `_completed`
  variant. Gaining the access role calls `EnrollmentService::enroll()`
  (bypass checks; a cancelled/expired row is reactivated, a completed one is
  left alone). Losing it consults the loss policy (below). The completion
  role never touches enrolment.
- **Grant records:** user meta `_anchor_course_grants` =
  `[ course_id => [ source, source_id, granted_at ] ]` - why the role is held
  now. A `manual` grant upgrades any other record and is never downgraded; a
  later non-manual grant of an already-held course never overwrites the
  FIRST non-manual reason recorded. `Roles::grant_record()` reads it. This is
  the mechanism the WooCommerce refund logic (below) matches against, not the
  enrolment row's own `source`/`source_id`.
- **`is_enrolled()`** requires all of: an active (`enrolled`/`in_progress`) or
  `completed` row, the access role, and - for active rows only - `expires_at`
  not yet passed. Access to an unfinished course ends at `expires_at`; the
  daily sweep then flips the row to `expired` and removes the role. A
  `completed` row is never expired: completion ends the work, not the access.
- **Primary-role changes:** `WP_User::set_role()` strips every role, including
  on an ordinary profile save. A role loss that arrives with no reason (no
  grant context) is **queued**, not applied. `Roles::reapply_after_set_role()`
  (on `set_user_role`) puts course roles back and drops their queued losses,
  so a profile save or primary-role change never un-enrols anyone, under any
  policy. A raw `remove_role()` with no `set_role()` behind it is resolved on
  `shutdown` by `Roles::resolve_pending_losses()` (public - call it directly
  in a CLI command or test). `revoke_access()`, the expiry sweep, multisite
  removal and `delete_role()` carry a reason and apply at once.
- That means anything able to add a WordPress role can enrol somebody:
  WooCommerce, the Learners tab, WP-CLI, a membership plugin, the user screen
  in wp-admin. There is no self-enrolment, no auto-enrol picker and no
  access-type setting.

```php
// The supported way to enrol somebody, from anywhere:
\Anchor\Courses\Support\Roles::grant_access( $user_id, $course_id, 'manual' );
```

### Cycles

A course can be completed more than once. `uncomplete()` re-opens a completed
row (status back to `in_progress`) and bumps `metadata.completion_cycle`;
credits, certificate, completion role and `metadata.completion_effects` are
all KEPT (a stored `hook: done` is durable history - the point of a cycle is
never to re-issue what was already issued). The next completion of that row
is treated as a re-completion of the SAME cycle machinery: the tracked
effects reset to `pending` (idempotent no-ops when the credit/certificate/role
already exist), `hook` stays `done` (or `n/a`) forever once confirmed, and a
`recompleted_hook` effect fires `anchor_courses_course_recompleted` once per
cycle - never `anchor_courses_course_completed` again. See "Progression and
completion" below for the full mechanics (locking, effect tracking, repair).

### Loss and refund policies

Two independently-filterable policies decide what happens when access is
taken away, and they compose:

- **`anchor_courses_role_loss_policy`** (default `keep`) - what losing the
  access role means for the ENROLMENT ROW: `keep` (row stays active, progress
  kept, re-grant resumes), `cancel`, or `expire`. Only an active row is
  affected; completed and already-closed rows are never touched. Consulted by
  every path that removes the role - `revoke_access()`, the expiry sweep, a
  WooCommerce refund, an admin Cancel.
- **`anchor_courses_wc_refund_policy`** (default `remove_role`) - specific to
  WooCommerce: whether a refund or cancellation takes the access role away AT
  ALL. If it does, the role-loss policy above then decides what that means
  for the row. See "WooCommerce integration" for the order-level revoked
  marker and the one-record-per-course grants-map consequence this stacks
  with.

---

## Content model and settings

### Post types

| CPT | Public URL | Meta prefix |
|---|---|---|
| `anchor_course` | `/courses/{slug}/` | `_anchor_course_` |
| `anchor_lesson` | `/lessons/{slug}/` (not in REST, search or `wp-sitemap.xml`) | `_anchor_lesson_` |
| `anchor_quiz` | none - renders inside its course | `_anchor_quiz_` |

Modules are **not** posts. The curriculum lives on the course as
`_anchor_course_curriculum`:

```json
[
  {
    "id": "8f2c...-uuid",
    "title": "Module 1",
    "description": "",
    "items": [
      { "type": "lesson", "id": 124, "required": true },
      { "type": "quiz",   "id": 150, "required": true }
    ]
  }
]
```

Each module keeps a stable UUID (v4), so reordering never breaks a reference.
Removing an item removes the reference only; the lesson or quiz post is
untouched. `Content\Curriculum` reads are memoised per request and invalidated
on any write to that meta key. **`required` lives HERE, and only here** -
`Curriculum::required_items()` is the one place course completion reads it
(audit finding d, 2026-09-25). The curriculum builder's per-item "Required"
checkbox (`admin-curriculum.js`) is the only control that sets it; new items
default to required.

### Course settings (`Admin\CourseEditor::setting()`, defaults)

`duration` `''`, `difficulty` `''`, `instructor` `''`, `ce_credits` `0`,
`ce_type` `''`, `ce_provider_name` `''`, `ce_provider_number` `''`,
`ce_expires_days` `0`, `prerequisites` `[]` (role slugs:
`anchor_course_*_completed` / `anchor_event_*`), `completion_mode`
`all_required_items` (`minimum_percentage`, `manual`), `completion_percentage`
`100`, `progression_mode` `sequential` (`free`), `certificate_enabled` `1`,
`certificate_template` `default`, `expiration_days` `0` (enrolment window, 0 =
never), `available_from` / `available_until` (`Y-m-d`, UTC).

**There is no access-type setting and no auto-enrol role list.** A course has
one access rule - hold `anchor_course_{id}` - so there is nothing to
configure. `prerequisites` is the only place a role is chosen, and it accepts
only completion roles (`anchor_course_{id}_completed`) and event roles
(`anchor_event_{id}`); a built-in or WooCommerce role there would gate on
nothing, because `set_user_role` fires for every new account. The filter
`anchor_courses_prerequisite_role_choices` controls which roles are offered
(and accepted).

### Lesson settings (`Admin\LessonEditor::setting()`, defaults)

`completion_mode` `manual` (`view`, `quiz_pass`), `quiz_id` `0`, `type`
`content` (`live_session`), `event_id` `0`, `session_index` `0`,
`require_prior_items` `0`.

The three live-session fields (`event_id`, `session_index`,
`require_prior_items`) are fully wired - see "The live-session lesson type
and the stream veto" below. They were shipped disabled behind a "not active
until Phase 5" notice while the adapter didn't exist yet; that notice is gone
now that Tasks 36-37 wired the event picker, session resolution and the
stream-access veto against the real events module.

The editor used to also carry its own "Required for course completion"
checkbox; it was removed (audit finding d) because nothing ever read it - a
lesson's requiredness is the curriculum item's, not the lesson post's.
`required` is no longer written by `save()` or listed in `defaults()`, though
a pre-fix row's stray `_anchor_lesson_required` meta is harmless if read
directly.

### Quiz settings (`_anchor_quiz_settings`, `Admin\QuizEditor::settings()`)

`passing_score` `80` (1-100), `max_attempts` `0` (unlimited, max 1000),
`time_limit_seconds` `0` (untimed, max 86400), `shuffle_questions` `0`,
`shuffle_answers` `0`, `show_correct_answers` `1`, `show_score` `1`,
`allow_review` `1`, `retry_delay_seconds` `0`, `on_timer_expiry` `auto_submit`
(`expire`). Questions: `_anchor_quiz_questions`, types `single_choice`,
`multiple_choice`, `true_false` (multiple choice is graded all-or-nothing).
The time limit and expiry policy are pinned into each attempt at start.

Same finding-d removal as the lesson editor: the quiz settings box's own
"Required for course completion" checkbox is gone; `required` is no longer
part of these settings at all (a pre-fix quiz's stray `required` key inside
the stored `settings` array is harmless if read directly - `coerce()` simply
does not touch it).

### Which course a lesson is read in

A lesson may appear in several courses. `Frontend\Access::course_for_lesson()`
resolves, in order: the `course` query arg (only if that published course
lists the lesson) > the lowest-id published course the user is enrolled in >
the lowest-id published course. Draft/pending/private courses never own an
item. Every lesson link the templates emit carries `?course={id}`
(`Access::lesson_url()`). `Curriculum::course_for_item()` (lowest-id published
course, no user) is used only where there is no learner context.

### Who sees a lesson body

`Frontend\ContentGuard` filters `the_content`, `the_excerpt` and
`get_the_excerpt` for `anchor_lesson`. `Access::lesson_denial()` allows anyone
who can `edit_post` the lesson (preview), otherwise defers to
`ProgressService::is_item_available()`. A refusal renders "You are not
enrolled in this course." plus the access CTA, or "Finish the earlier lessons
to unlock this one." for an enrolled learner locked by progression.

---

## Progression and completion

Progression is governed by the course's `progression_mode`: `sequential`
(each item unlocks only once every earlier required item is done) or `free`
(everything is open once enrolled). `ProgressService::is_item_available()` is
the single authority both the lesson gate (`ContentGuard`) and the curriculum
template consult.

**An item is never available for ITSELF unless its own post is `publish`**
(PR36 round 3, Codex) - independent of the "unpublished item never gates
progression" rule above for items ordered BEFORE it. Enrolment, curriculum
membership and sequential progression can all be satisfied and a
draft/pending/private lesson or quiz still refuses, because no public route
can open or complete unreleased content yet. `start_lesson()` and
`complete_lesson()` check this first and on their own, returning
`WP_Error('no_lesson')` - the REST routes (`POST /lessons/{id}/start`,
`/complete`) map it to 404, distinct from the generic 403 `locked`
progression refusal `is_item_available()`'s `false` produces everywhere
else. An enrolled learner who knows or guesses a staged lesson's id
therefore gets a 404, not a way to create progress against it.

**Unpublished required items never count toward completion.**
`ProgressService::get_course_progress()` filters `Curriculum::required_items()`
to `publish` posts before computing `total_required`/`percent()` - a
draft/pending/private required lesson or quiz cannot be completed through any
public route, so it must not hold a course below 100% for a reason no learner
can act on. This makes two curricula that both filter down to zero published
required items look identical to `percent()` (which returns 100.0 for any
`$total <= 0`) but they are NOT the same thing for `minimum_percentage`
completion: a genuinely EMPTY required curriculum has nothing left to do and
completes; a curriculum that is non-empty BEFORE the publish filter and only
empty after it - required items that all happen to be drafts right now - has
real, if currently unreachable, work, and must stay incomplete (PR36 round 2,
CodeRabbit, Major). `all_required_items` mode already requires `$total > 0`
to complete and was never affected.

### The completion pipeline

- **`CompletionService::complete()`** requires `is_enrolled()`, takes the
  completion lock (below), re-reads the row, then an atomic
  `status <> 'completed'` UPDATE, then credit award, certificate issue (+
  credit link), completion role, `anchor_courses_course_completed`.
  `anchor_courses_course_completed` fires exactly once per (user, course)
  lifetime.
- **Eligibility is re-checked under the lock, not just before it** (Round 7,
  PR #32 audit re-review, finding 1): the `eligible()` call before
  `acquire_lock()` is only a short-circuit for the common case (not eligible,
  nothing to wait for) - it is NOT what makes the transition safe. A caller
  that WAITS for the completion lock can find the learner has since lost the
  access role or had progress reset while it waited; under the default
  `keep` loss policy the enrolment row itself stays active, so checking only
  `is_active()` after the lock (the old code) would still let the flip
  through. `complete()` re-runs the FULL `eligible()` check - `is_active()`
  AND `is_enrolled()` (the role) AND the curriculum evaluation - against the
  row it just re-read once the lock is held, immediately before the flip,
  and returns `false` with nothing awarded if it no longer holds.
- **Completion cycles** (audit finding c; Round 6, PR #32): `uncomplete()`
  takes the same lock, reopens the row (`in_progress`) and increments
  `metadata.completion_cycle`; credits, certificate, completion role and
  `metadata.completion_effects` are all kept (a stored `hook: done` is
  durable history). The next transition of that row is a RE-completion
  (a cycle marker, or any stored effect state) and starts a **new cycle**:
  `credit`/`certificate`/`completion_role` reset to `pending` (idempotent
  no-ops when already present), `hook` keeps `done` (or `n/a`) forever - it
  is re-run only if no earlier cycle ever confirmed it - and a
  `recompleted_hook` effect is added as `pending`, which fires
  `anchor_courses_course_recompleted` once per cycle and is repaired like
  any other effect. `uncomplete()` returns `false` (admin notice
  `uncomplete_failed`) when the row is not completed, the lock is busy or
  the write failed.
- **The admin "Reset progress" action is serialised with the completion
  pipeline, and clears progress under the SAME lock hold as the reopen**
  (Round 9, Codex + CodeRabbit Major, PR #32 finding 1, supersedes Round 8
  below, which held the lock for the reopen write alone): `EnrollmentManager`'s
  `reset` action goes through `ProgressService::reset_course()` ->
  `CompletionService::reopen_for_reset( $user_id, $course_id, $data, $clear )`
  - a STATIC method, same as `lock_name()`/`is_settled()`, because the MySQL
  named lock it takes is server-wide, not tied to any one `CompletionService`
  instance. It takes the SAME per-(user, course) completion lock
  `complete()`/`uncomplete()` take, re-reads the row only once the lock is
  held, applies the reset's `status`/`started_at`/`completed_at` in the SAME
  call that bumps `metadata.completion_cycle` (see below), and - Round 9 -
  invokes the caller's `$clear` closure (progress-row deletion, quiz-attempt
  abandonment) from inside that SAME lock hold, right after the reopen write
  succeeds and before the lock is released. Releasing the lock as soon as the
  reopen write landed (Round 8's shape) left `$clear`'s two writes running
  with no lock at all: a completion racing into that window could re-read the
  reopened row, evaluate it against the STILL-PRESENT old progress (which
  still reports complete) and flip it straight back to `completed` - with
  effects awarded - moments before the reset deleted that same progress out
  from under it. One lock-scoped operation now covers reopen + cycle bump +
  clear, so neither a `complete()`/`uncomplete()` pipeline nor a reset can
  ever race the other's writes for the same row, and no completion can ever
  observe the row reopened but not yet cleared.
  Before Round 8, `EnrollmentService::restart()` read and rewrote a completed
  row's metadata with no coordination at all: a stale write could restore
  `hook: failed` (firing the lifetime `course_completed` again next time) or
  clobber a newer effects map or claim written in between.
  `EnrollmentService` no longer touches completion metadata OR progress at
  all - `restart()` is gone. A reset that cannot get the lock, or whose
  reopen write fails at the database, changes NOTHING (`$clear` never runs
  either way) and `reset_course()` returns a `WP_Error` instead of a plain
  `false` (Round 9, PR #32 finding 2): `reset_busy` when the lock is held
  elsewhere (retry shortly - the old boolean gave `EnrollmentManager` no way
  to tell this apart from a real failure), `reset_failed` when the write
  itself failed (retrying immediately will not help). `EnrollmentManager`
  reports each as its own distinct notice, never conflated, and never
  `reset` on either.
- **A `$clear()` that fails AFTER the reopen write commits is now
  RECOVERABLE, never a silently-reported success** (Round 10, CodeRabbit
  Major, PR #32 finding 1 - the shape above ignored `$clear()`'s own result
  entirely). `reopen_for_reset()` writes
  `CompletionService::RESET_PENDING_META` (`reset_pending_at`, a timestamp)
  into the row's metadata in the SAME update as the reopen itself - so it is
  durable the instant the reopen commits, before `$clear()` ever runs - and
  regardless of whether the row being reset was `completed` a moment ago:
  the race a failed clear opens (stale progress that never got deleted
  still reporting the course complete) does not depend on that. While the
  marker stands, `complete()` refuses the row outright, before even
  checking `is_complete()` - so a completion racing in against progress that
  failed to delete can never flip it, no matter how the check that would
  normally decide eligibility comes out. A failed `$clear()` (either write:
  `ProgressRepository::delete_for_course()` or
  `QuizAttemptRepository::abandon_for_course()` reporting a genuine database
  error - see below) is reported as `reset_failed`, same code as a failed
  reopen write; the caller cannot tell which write failed and does not need
  to, only that a retry is the recovery. The marker is lifted in a second
  write once `$clear()` succeeds; if THAT write itself fails, it is logged
  (`completion_reset_marker_clear_failed`) but never reported as a failure -
  the learner's progress genuinely is clear by then, and a stray marker left
  behind is healed by the very next Reset, whose `$clear()` is idempotent
  (see next point). A later Reset (re-run once the underlying failure is
  healed) simply retries the whole `reopen_for_reset()` call - reopening an
  already-reopened row and re-running `$clear()` - and clears the marker on
  that pass.
- **Zero rows is a real success, never a failure, for either half of
  `$clear()`** (Round 10, same finding): `ProgressRepository::
  delete_for_course()` and `QuizAttemptRepository::abandon_for_course()` now
  return `int|false` - `false` ONLY when `$wpdb->delete()`/`$wpdb->query()`
  itself reports a database error, mirroring the `EnrollmentRepository::
  update()` / `QuizAttemptRepository::update()` null-on-error convention
  already used elsewhere in this module. Before this fix both simply cast
  the result to `(int)`, which turns a real `false` error into `0` - making
  it indistinguishable from a learner who genuinely had nothing to clear.
  `ProgressService::reset_course()`'s `$clear` closure reports `false` only
  when either repository call itself returned `false`, never for "0 rows
  affected" - which is exactly what makes a healing Reset's retry safe: a
  row already cleared by a previous attempt clears zero more rows and still
  counts as success.
- **Reopening a completed row for a reset also bumps the cycle marker**
  (Round 7, PR #32 audit re-review, finding 2 - now inside
  `reopen_for_reset()` above): a row that was `completed` has
  `metadata.completion_cycle` incremented, the same marker `uncomplete()`
  bumps. Without it, a row that predates effect tracking (or one whose
  tracked state is otherwise empty) has NEITHER a cycle marker NOR an
  effects map after a reset, so `run_effects()` cannot tell the next
  completion apart from the FIRST one and re-fires
  `anchor_courses_course_completed` for a learner who already completed the
  course once. The cycle counter alone is enough to fix it: once
  `run_effects()` knows this is a re-completion at all, its existing
  "unknown outcome, never re-fired" handling for an empty effects map
  (below) takes over - `hook` reads back `n/a`, not `pending`.
- **Completion effects are tracked and repaired** (audit F02): the enrolment's
  `metadata.completion_effects` = `{ credit, certificate, completion_role, hook }`,
  each `pending` -> `done` / `failed` / `n/a` (credit: nothing to award;
  certificate: disabled). `complete()` on an already-completed row re-runs
  only the `pending`/`failed` effects and returns `false` - so the next
  progress record, or the admin **Repair completion** action, is the repair
  path. Each is idempotent (existing credit/certificate rows are returned, the
  role grant is a no-op when held); the certificate waits while the credit is
  unsettled, and is `done` only once linked to the credit. A throwing
  consumer of either hook (`anchor_courses_course_completed`,
  `anchor_courses_course_recompleted`) is contained (Round 6): the exception
  is caught and logged (`completion_effect_exception`), never escapes
  `complete()` once the row is committed, marks only that hook effect
  `failed` (the other effects still run), and the action is fired again on
  repair; once `done` it never fires again in that cycle. A throwing
  `anchor_courses_enrollment_status_changed` listener on the transition is
  likewise caught and logged (`completion_status_hook_failed`).
  `CompletionService::effects( $user_id, $course_id )` reads the state.
  **An untracked completed row - no `completion_effects` at all, whether it
  predates tracking or the tracking write itself failed - has an UNKNOWN
  outcome, not a done one** (CodeRabbit PR #32, audit F02 re-review):
  `run_effects()` treats it like a fresh transition and re-runs the
  idempotent effects (credit, certificate, completion role) so one that
  genuinely never landed still gets created, but never re-runs the hook
  (not idempotent; may already have fired once with nothing recorded to
  prove it). The admin **Repair completion** action therefore always has a
  real chance to fix an untracked row; the earlier `repair_not_tracked` code
  (a distinct "nothing could be determined" outcome) can no longer occur
  through this path and was removed.
  **`repaired` is reported only once every one of the four tracked effects
  (plus a cycle's `recompleted_hook`) reads back `done` or `n/a`** (Codex
  P1 + CodeRabbit, PR #32 re-review; `CompletionService::is_settled()`) -
  checked against `CompletionService::EFFECTS` explicitly, not by testing
  for the absence of `pending`/`failed`. That weaker test also passes for
  two shapes where nothing is actually confirmed: a fresh `running` claim
  (a pipeline still within `EFFECTS_RUNNING_STALE_SECONDS`, deliberately
  left alone rather than re-run) is neither `pending` nor `failed`, and
  neither is a still-empty map (the repair's own `complete()` call could
  not get the completion lock, so it ran nothing). Anything short of all
  four settled redirects with **`repair_incomplete`** instead - a step
  failed, or one is still finishing from moments ago; the operator is
  told to check the log and try again in a few minutes, never told it
  succeeded.
- **The completion lock covers flip + effects** (Codex review, PR #32
  finding 1; Round 6): every `complete()` and `uncomplete()` takes the same
  kind of MySQL named lock `start_attempt()` uses
  (`CompletionService::lock_name( $user_id, $course_id )`, `GET_LOCK`,
  `anchor_courses_completion_lock_timeout` seconds, default 5, released in
  `finally`) BEFORE the completed-transition, and holds it through the
  effects. The row and its effect state are (re)read only once the lock is
  held - a caller that waited never acts on its pre-lock snapshot, so two
  repairs that both saw `hook: failed` fire it once. A caller that cannot
  get the lock returns `false` and changes nothing: a repair runs no
  effects, and **a fresh completion does not flip the row** - the learner's
  next progress recalculation (or the admin Complete/Repair action) retries
  it - so a transition can never be committed without its effects cycle.
  Before any claimed effect actually runs, `run_effects()` marks it
  `running` in `completion_effects` with a timestamp in
  `completion_effects_claimed_at`, so a pipeline that crashes mid-run leaves
  that exact shape behind. The next call to hold the lock treats a
  `running` entry older than `CompletionService::EFFECTS_RUNNING_STALE_SECONDS`
  (300s) as `pending` (the claimant is dead - repair re-runs it) and one
  still within that window as still owned (left alone - the lock is
  re-entrant on one connection, so it can only be this caller's own claim).
- **The effect-tracking write is itself checked** (re-review, audit F02):
  `CompletionService::save_effects()` returns `false` and `Log::write()`s
  `completion_effects_save_failed` when `EnrollmentRepository::update()`
  reports a database error (mirroring the F03 fix under "Quizzes") - a fresh
  read of the row with the OLD metadata would otherwise look identical to a
  successful save. The failure never blocks the effects themselves (credit,
  certificate, role, hook are separate writes and still run for real); it
  only means their outcome was not durably recorded, logged again from
  `complete()` as `completion_effects_untracked`.

### Admin reset (`ProgressService::reset_course()`)

Reopens the row, deletes progress rows, voids every counted attempt as
`abandoned` (not counted toward `max_attempts`, no retry delay), and restarts
the row (`enrolled`, no `started_at` / `completed_at`) - all under ONE hold of
the completion lock (see `reopen_for_reset()` above, Round 9). `WP_Error(
'reset_busy')` on a busy lock touches nothing at all - retry shortly.
`WP_Error( 'reset_failed' )` on the REOPEN write itself failing also touches
nothing; but the same code also covers a `$clear()` (progress-row deletion /
attempt-abandonment) that fails AFTER the reopen write already committed -
that case is NOT "nothing touched": the row is left reopened with
`reset_pending_at` still set, `complete()` refused while that marker stands,
and the fix is simply to retry Reset (see "Progression and completion" above
for the full recovery mechanics).

---

## Quizzes

- **Quiz submit is atomic**: the attempt is claimed with a conditional
  `in_progress -> submitted` (or `-> expired`) UPDATE before grading, so
  concurrent submits grade once. A learner-initiated submit/answer from
  somebody no longer enrolled is refused (`not_enrolled`).
- **The `expire` timer policy re-reads the row after its own transition**
  (Codex review, PR #32 finding 2): it claims the attempt
  (`in_progress -> expired`) first, then writes `submitted_at`/
  `duration_seconds` in a second statement. If that second write fails
  (`QuizAttemptRepository::update()`'s null-on-error contract), `submit()`
  re-reads the row with `find()` rather than falling back to the
  PRE-transition `in_progress` object it read at the top of the method - the
  transition already happened for real, so the fired
  `anchor_courses_quiz_expired` hook, a REST read and
  `sweep_expired_attempts()`'s count must all see the real `expired` status,
  never a stale one. A failed detail write is logged as
  `quiz_expire_detail_save_failed`. **That re-read itself is retried once**
  (CodeRabbit Major, PR #32 re-review) before giving up: a null `find()`
  right after a write that just succeeded is treated as a transient read
  blip, not evidence the row reverted, so a second attempt is given a
  chance to see it. Only if BOTH reads come back empty does `submit()`
  return `WP_Error('read_failed')` (REST 503, safe to retry) - never the
  stale `in_progress` object, and logged as `quiz_expire_read_failed`.
- **The expiry effects run even when BOTH post-transition reads fail**
  (Round 10, Codex re-review, PR #32 finding - closes the gap the point
  above left open): before this fix, a double read-back failure returned
  `read_failed` immediately after logging it - BEFORE recording the failed
  quiz progress, firing `anchor_courses_quiz_expired`, or recalculating the
  course. A "just retry" recovery does not exist for this: `submit_tracked()`'s
  own "already closed" short-circuit refuses any later call on this same
  attempt once it is no longer `in_progress`, and `sweep_expired_attempts()`
  already counts this call `transitioned` - so a skipped effect here was
  permanently skipped, not merely delayed. `QuizService::fire_expiry_effects()`
  (record the failed progress, fire the hook, recalculate) now runs from
  BOTH the confirmed-read path and the double-failure path, in the latter
  case against a SYNTHESISED `QuizAttempt` built from the pre-transition
  object with `status` overridden to `'expired'` - the `in_progress ->
  expired` transition already committed for real, so this is the truth even
  though nothing could confirm it just now, and no `in_progress` object ever
  reaches the hook. `submit()`'s public return value is unchanged
  (`WP_Error('read_failed')`, still logged as `quiz_expire_read_failed`) -
  only the effects that follow a confirmed expiry are now guaranteed to run
  exactly once, regardless of which of the two post-transition reads (if
  either) actually landed.
- **Grading reads the claimed row, never the pre-claim snapshot** (Round 6,
  PR #32): after the `in_progress -> submitted` claim `submit()` re-reads
  the row, so an autosave acknowledged between its first read and the
  claim is graded. If that re-read fails, it releases the claim
  (`submitted -> in_progress`), logs `quiz_submit_read_failed` and returns
  `WP_Error('read_failed')` (REST 503, safe to retry) - the stored answers
  are untouched and the retry grades them.
- **`enforce_timer()` propagates `read_failed` too, through every caller**
  (Round 7, PR #32 audit re-review, finding 3): `submit()`'s own
  `read_failed` guarantees above are about what `submit()` itself returns -
  but `enforce_timer()` (the cron sweep's and a truthful-status read's entry
  point, above `submit()`) used to convert ANY non-`QuizAttempt` result,
  `read_failed` included, back into its own PRE-transition `$attempt`
  argument. That argument can already be stale: the `expire` branch commits
  the row as `expired` in the database before either of its own two
  re-reads runs, so a caller seeing `read_failed` from `submit()` still
  means the transition landed for real. `enforce_timer()` now takes one
  more, authoritative re-read of its own on any non-`QuizAttempt` result;
  only when that ALSO fails does it hand back the `WP_Error` - never the
  stale object. The REST read route (`QuizController::read()`) maps a
  propagated `read_failed` to 503 rather than reporting `in_progress`.
- **The sweep counts only CONFIRMED expiry transitions** (Round 8,
  CodeRabbit, supersedes the sweep-counting half of Round 7 finding 3 above):
  neither a `WP_Error` result nor a before/after status compare says a call
  actually transitioned the row - both over-count. The generic
  claim-then-grade path (any timer policy other than `expire`) ROLLS BACK its
  own `in_progress -> submitted` claim before returning `read_failed`/
  `save_failed`, so the row is back exactly where it started even though the
  result is an error. `submit_tracked()` (private; `submit()`'s real
  implementation) and `enforce_timer_tracked()` therefore return an explicit
  `transitioned` bool alongside the `QuizAttempt|WP_Error` result: true only
  for the `expire` branch once its claim commits (whatever follows - a failed
  detail write, a failed re-read - THIS call already closed the attempt) and
  for a successful grade; false for every lost-race short-circuit and every
  rollback. `sweep_expired_attempts()` reads `transitioned` directly instead
  of inferring it - a rollback under `auto_submit` counts 0, a confirmed
  `expire` transition with a failed re-read still counts 1. `submit()` and
  `enforce_timer()` keep their public `QuizAttempt|WP_Error` contracts
  unchanged - only the sweep's own counting reads the tracked variant.
- **A grade counts only once it is saved** (audit F03):
  `QuizAttemptRepository::update()` returns `null` when `$wpdb->update()`
  reports an error (a 0-row no-change update is still a success). `submit()`
  moves progress and fires `_submitted`/`_passed`/`_failed` only when the
  re-read row is `graded`; otherwise it releases the claim
  (`submitted -> in_progress`) and returns `WP_Error('save_failed')` (REST 503,
  safe to retry). A `submitted` claim older than
  `QuizService::STALE_CLAIM_SECONDS` (300) was orphaned by a crashed request
  and is re-opened, answers intact, by `QuizService::reopen_stale_claims()` -
  run by the daily sweep and by the learner's own next start.
- **Starting an attempt is serialised** (audit F07): `start_attempt()` takes the
  MySQL named lock `QuizService::attempt_lock_name( $user, $course, $quiz )`
  (`GET_LOCK`, `anchor_courses_attempt_lock_timeout` seconds, default 5) around
  the preflight and the create, released in `finally`; a caller that cannot get
  it receives `WP_Error('attempt_busy')` (REST 409). The lock name folds in
  `md5( DB_NAME . $wpdb->prefix )` (re-review) - `GET_LOCK()` is server-wide,
  not scoped by database, so two sites sharing a MySQL server (or two
  subsites of one multisite) never contend over the same key just because a
  learner, course and quiz share ids; still at most 64 characters. As a database-level
  backstop, `QuizAttemptRepository::create()` inserts only while no attempt is
  open for (user, course, quiz) and fewer than `max_attempts` counted attempts
  exist; nothing inserted resolves to the open attempt, or to
  `no_attempts_remaining`. Two simultaneous starts therefore share one attempt
  or one gets a controlled conflict - never two open attempts.
- **Answer autosave is a compare-and-swap** (audit F06): `quiz_attempts.revision`
  (1.3.0) is bumped by every answers write;
  `QuizAttemptRepository::save_answers( $id, $answers, $expected_revision )`
  writes only `WHERE status = 'in_progress' AND revision = %d`. `save_answer()`
  re-reads and retries once on a lost race, then returns
  `WP_Error('save_conflict')` (REST 409); a database error is
  `WP_Error('save_failed')` (503). A save can never land after the submit
  claim, and `submit()` re-reads the row after claiming, so every acknowledged
  save is graded and the stored answers always match the stored grade.
  `quiz.js` keeps one save in flight, coalesces queued changes per question,
  shows a save-error line (`.anchor-quiz-save-status`), and submit waits for
  the queue to drain.
- **A lesson and its quiz** (audit F04, re-review): under sequential
  progression a quiz is not blocked by its parent lesson - an earlier required
  lesson whose `completion_mode` is `quiz_pass` with this quiz as `quiz_id`
  (`ProgressService::lesson_completes_by_quiz()`). The quiz opens exactly when
  that lesson does; every other earlier required item still gates it. A quiz
  placed BEFORE its lesson also works (it opens with the earlier items, and
  passing it completes the lesson via `QuizService::complete_gated_lessons()`)
  and is not a problem. The real deadlock is a REQUIRED item strictly between
  the lesson and its quiz: that item waits on the lesson, the quiz waits on
  that item, and the lesson waits on the quiz - none of the three can ever
  finish. Saving a curriculum in which a required `quiz_pass` lesson's quiz is
  absent from the course, or (sequential mode only) has a required item
  between it and its lesson (`ProgressService::quiz_link_problems()`, problems
  `quiz_absent` / `item_between`), saves anyway and redirects with the
  `curriculum_quiz_link` warning notice. **Re-checked on lesson save too**
  (Round 8, Codex, PR #32 finding 3): `quiz_link_problems()` used to run only
  when a COURSE's curriculum was saved, so changing a lesson's
  `completion_mode` to `quiz_pass` or repointing its `quiz_id` could create
  the same deadlock in a course whose curriculum is never re-saved, with no
  warning anywhere. `Admin\LessonEditor::save()` now runs the same check for
  every PUBLISHED course the lesson actually belongs to
  (`Curriculum::courses_for_item()`) and redirects with `lesson_quiz_link`,
  naming the affected course(s) - the lesson screen has no course of its own
  to name it from, so the title(s) ride in `Notices::COURSES_QUERY_ARG` and
  are interpolated into the registered message's `%s` placeholder. **Scoped
  to the SAVED lesson, never any problem anywhere in the course** (Round 9,
  PR #32 finding 3): `quiz_link_problems( $course_id )` returns every problem
  in that course, not only ones involving the lesson just saved, so a course
  only counts as affected when one of its returned problems' `lesson_id`
  matches the lesson being saved - otherwise saving a perfectly sound lesson
  B would warn about a problem an unrelated lesson A already had.
- **Attempts belong to a course** (audit F05): a quiz may be shared by several
  courses, and every attempt lifecycle read - `open_attempt()`,
  `count_for_quiz()`, `last_for_quiz()`, `best_for_quiz()` and the service's
  `attempts_used()`, `attempts_remaining()`, `retry_available_at()`,
  `best_attempt()` - takes `( $user_id, $quiz_id, $course_id )`. An open,
  exhausted, delayed, reset or passed attempt in one course never changes
  another course's lifecycle; `start_attempt()` never resumes another course's
  attempt. Cross-course credit, if ever wanted, must be an explicit policy.
- **Best attempt counts:** a completed item is never downgraded by a later
  failed attempt; a repeat pass keeps the original `completed_at`.

---

## Certificates and credits

- **CE credit / certificate outcomes:** `CreditService::award()` and
  `CertificateService::issue()` return the row, `null` for not-applicable
  (reason in `last_skip_reason()`: `not_a_course`, `no_user`, `no_credits` /
  `certificates_disabled`), or `WP_Error` (`credit_insert_failed` /
  `certificate_insert_failed`) when the row was due but not written. Both are
  idempotent per (user, course) - a second award/issue returns the existing
  record.
- **Certificates** freeze `learner_name`, `course_name`, `credits`,
  `provider_name`, `provider_number` and `instructor_name` into their
  metadata at issue; the page renders that snapshot (live values only for
  older rows). Numbers are `AC-{YYYY}-{8-digit id}`; the year and
  `completion_date` are UTC.
- **Certificate templates:** the course setting `certificate_template`
  (default `default`) is frozen into the certificate at issue; a non-default
  slug renders `certificate-{slug}.php` (theme override under
  `anchor-courses/`, then the plugin's `templates/`), falling back to
  `certificate.php` when the file does not exist.
  `CertificateService::template_name()` decides.
- **Verification page:** Phase 1 is an HTML certificate at
  `/certificate/{token}/` (query var `anchor_certificate`,
  `Frontend\CertificatePage`), noindexed and never cached, public by token so
  a licensing board can verify a number without an account. `file_path` is
  reserved for PDF generation and stays empty - see "Known limitations".
- **Renewal is out of scope for Phase 1**: the `ce_credits` table's UNIQUE key
  is `(user_id, course_id)`, so a course awards CE once per learner, ever. A
  renewal/recertification course needs a schema migration (a period or
  expiry column), not a code change.

---

## Admin surfaces and notices

### Learners tab

The `Learners` metabox on a course lists everyone enrolled with their
progress, status and completion date (`Admin\LearnerReports::rows()`, the
same batch-loaded row builder the `/admin/courses/{id}/learners` REST route
and the user-profile Courses block (`user_rows()`) also use), and lets anyone
with `manage_anchor_enrollments`:

- **add a learner** by name and email - the account is created if it does not
  exist, with no WordPress or WooCommerce new-account email
  (`Support\Accounts::ensure_user()`, gated by the `anchor_courses_create_account`
  filter with a `null` context), then granted the access role with
  `source = manual`;
- **revoke access** per row, which removes the role and lets the loss policy
  decide what happens to their progress.

Both actions go through `admin-post.php?action=anchor_courses_add_learner` /
`anchor_courses_revoke_access` (nonce `anchor_courses_learners_{course}`, cap
`enrollments`).

### Enrolment management

`admin-post.php?action=anchor_courses_manage_enrollment`
(`Admin\EnrollmentManager`) exposes `cancel`, `reset` (`reset_busy` when the
completion lock is busy - nothing touched; `reset_failed` when the reopen
write itself fails - also nothing touched, OR when the later `$clear()`
fails after the reopen already committed - that leaves the row reopened with
`reset_pending_at` set, recoverable by simply retrying Reset; see
"Progression and completion" for the full mechanics), `complete`,
`uncomplete`, and `repair` (re-run pending/failed/untracked completion
effects; `repaired` only once all four read `done`/`n/a`, else
`repair_incomplete`; `repair_not_completed` when the row isn't complete at
all).

### Course Role panel

On the course editor: shows the access and completion role slugs and holder
counts, with a **Delete role** action per role
(`admin-post.php?action=anchor_courses_delete_role`, cap `manage`) that strips
every holder before removing the role.

### Curriculum builder

`admin-ajax.php?action=anchor_courses_search_items` /
`anchor_courses_create_item` back the curriculum builder's item picker
(search existing lessons/quizzes, or create one inline without leaving the
course editor).

### Notices registry (`Admin\Notices`)

Every admin-post handler authorises and redirects through
`Admin\Notices::authorisation_error()` / `authorise()` and
`Admin\Notices::redirect( $code, $target )`, which only ever sends a code
registered with `Notices::register()` - anything else becomes the generic
`error` notice, so a typo in a handler can never leak an unregistered code
to the query string.

Registered codes: `role_deleted`, `curriculum_quiz_link`, `lesson_quiz_link`,
`forbidden`, `error` (Course Role / Curriculum builder); `learner_added`,
`access_revoked`, `revoke_failed`, `bad_nonce`, `no_user`, `no_course`,
`enroll_failed`, `missing_prerequisite`, `not_available_yet`,
`no_longer_available` (Learners tab); `cancelled`, `reset`, `reset_busy`,
`reset_failed`, `completed`, `uncompleted`, `uncomplete_failed`,
`complete_failed`, `repaired`, `repair_incomplete`, `repair_not_completed`
(Enrolment management).

---

## Hooks

Every `do_action()`/`apply_filters()` call in `anchor-courses/src/` and
`anchor-courses/api.php`, whether the hook name is a literal string or (as
with `quiz_passed`/`quiz_failed`) chosen by a ternary.

### Actions

| Hook | Arguments | Fires |
|---|---|---|
| `anchor_courses_access_granted` | `$user_id, $course_id, $source, $source_id` | `Roles::grant_access()` added the role |
| `anchor_courses_access_revoked` | `$user_id, $course_id, $source` | `Roles::revoke_access()` removed the role |
| `anchor_courses_enrolled` | `Enrollment $enrollment, $user_id, $course_id` | once, the first time a row is created |
| `anchor_courses_enrollment_status_changed` | `$user_id, $course_id, $from, $to` | any status change (set_status, reactivation, reset, completion, uncompletion) |
| `anchor_courses_course_started` | `$user_id, $course_id, Enrollment $enrollment` | first item opened (again after a reset) |
| `anchor_courses_lesson_started` | `$user_id, $course_id, $lesson_id, Progress $progress` | first view of a lesson |
| `anchor_courses_lesson_completed` | `$user_id, $course_id, $lesson_id, Progress $progress` | once, first completion of a lesson |
| `anchor_courses_quiz_started` | `QuizAttempt $attempt, $user_id, $quiz_id, $course_id` | a new attempt (never a resume) |
| `anchor_courses_quiz_submitted` | `QuizAttempt $attempt, $user_id, $quiz_id, $course_id` | an attempt was graded |
| `anchor_courses_quiz_passed` / `anchor_courses_quiz_failed` | same | after `_submitted`, by outcome (hook name chosen by ternary at the call site) |
| `anchor_courses_quiz_expired` | same | a timed attempt closed by the `expire` policy (nothing graded) |
| `anchor_courses_course_completed` | `$user_id, $course_id, Enrollment $enrollment` | once per (user, course) lifetime; fired again on repair only if a consumer threw (`completion_effects.hook = failed`); never re-fires after an uncomplete()/complete() cycle |
| `anchor_courses_course_recompleted` | `$user_id, $course_id, Enrollment $enrollment` | once per uncomplete() -> complete() cycle, in place of `anchor_courses_course_completed` |
| `anchor_courses_ce_credit_awarded` | `$user_id, $course_id, Credit $credit` | a credit record was created |
| `anchor_courses_certificate_issued` | `$user_id, $course_id, Certificate $certificate` | a certificate was created |
| `anchor_courses_curriculum_saved` | `$course_id, array $modules` | `Curriculum::save()` - also the invalidation point for `Integrations\Events`' per-request `live_lessons_for_event()` memo |

### Filters

| Hook | Value, then arguments | Purpose |
|---|---|---|
| `anchor_courses_can_enroll` | `true\|WP_Error, $user_id, $course_id` | veto a grant |
| `anchor_courses_role_loss_policy` | `'keep', $user_id, $course_id, $role, $source, $source_id` | see "Concepts - Loss and refund policies" |
| `anchor_courses_can_access_lesson` | `bool, $user_id, $course_id, $item_id, $item_type` | item availability (lessons and quizzes) |
| `anchor_courses_can_start_quiz` | `true\|WP_Error, $user_id, $quiz_id, $course_id` | may this attempt start/resume |
| `anchor_courses_attempt_lock_timeout` | `5, $user_id, $quiz_id, $course_id` | seconds `start_attempt()` waits for the per-learner start lock before `attempt_busy` |
| `anchor_courses_completion_lock_timeout` | `5, $user_id, $course_id` | seconds `complete()` waits for the per-(user,course) completion-effects lock before backing off |
| `anchor_courses_quiz_result` | `array $result, QuizAttempt $attempt` | graded result before it is stored |
| `anchor_courses_course_completion_status` | `bool, $user_id, $course_id` | does the course count as complete |
| `anchor_courses_ce_credit_amount` | `float, $user_id, $course_id` | credit amount before the record |
| `anchor_courses_ce_credit_data` | `array $row, $user_id, $course_id` | the whole credit row before insert |
| `anchor_courses_certificate_data` | `array $data, Certificate $certificate` | certificate template variables |
| `anchor_courses_access_cta` | `array{url,label,message}, $course_id, $user_id` | turn the "how to get access" line into a link |
| `anchor_courses_no_access_message` | `string, $course_id` | the default access line |
| `anchor_courses_prerequisite_role_choices` | `array $choices, $exclude_course_id` | roles offered (and accepted) as prerequisites |
| `anchor_courses_create_account` | `true, $email, $context` | may an account be created for someone who has none - the module's single creation point (`Support\Accounts::resolve()`). `$context` is `null` for the Learners tab, the `WC_Order` when the WooCommerce adapter resolves a guest course buyer. Independent of the events module's `anchor_events_create_account` (different module, different reason): opting one out does not opt out the other |
| `anchor_courses_capability_roles` | `[ 'administrator' ]` | roles granted the capabilities |
| `anchor_courses_parent_menu` | `true` | show the Courses admin menu tree |
| `anchor_courses_now` | `time()` | the clock (tests) |
| `anchor_courses_analytics_enabled` | `true` | turn the whole `Integrations\Analytics` dataLayer pipeline off; when false nothing is ever queued (and a queue from before the flip is never printed) |
| `anchor_courses_datalayer_event` | `array\|null $payload, string $event, array $args` | edit or drop (`null`) one dataLayer event before it is queued; `$args` are the firing hook's own positional arguments. The IDs-only allow-list is re-applied to what it returns, so it can remove or change allowed keys but never add others |
| `anchor_courses_wc_enroll_statuses` | `['processing','completed']` | which order statuses grant access |
| `anchor_courses_wc_refund_policy` | `'remove_role', $order_id, $course_id, $user_id` | remove_role\|keep - whether a refund takes the access role away |
| `anchor_courses_wc_retry_order_limit` | `500, $course_id` | how many of the newest orders with a line item mapped to a just-published course retry-on-publish re-runs |

There is also `anchor_events_can_access_stream` - NOT one of this module's own
hooks; courses only *listens* to it (unconditionally, from
`Integrations\Events::__construct()`) to implement the stream veto. See "The
live-session lesson type and the stream veto" below.

---

## Public PHP API (`api.php`, root namespace)

Thin wrappers over the module's own services. Each returns
`WP_Error('courses_not_loaded')` (or `null` for the credit award) if called
before the module has booted.

| Function | Returns | Notes |
|---|---|---|
| `anchor_courses_enroll_user( $user_id, $course_id, array $args = [] )` | `Domain\Enrollment\|WP_Error` | Grants the access role via `Roles::grant_access()` (so prerequisites and publish state are enforced). `$args`: `source` (default `api`), `source_id`. |
| `anchor_courses_complete_lesson( $user_id, $course_id, $lesson_id )` | `Domain\Progress\|WP_Error` | Idempotent; subject to enrolment and progression. Completing the last required item runs the completion pipeline. |
| `anchor_courses_get_progress( $user_id, $course_id )` | `Domain\CourseProgress\|WP_Error` | `percent`, `completed_required`, `total_required`, `complete`, `completed_item_keys`. |
| `anchor_courses_award_ce_credit( $user_id, $course_id, $credits )` | `Domain\Credit\|WP_Error\|null` | Idempotent per (user, course); `null` when there is nothing to award; `WP_Error('credit_insert_failed')` when a credit was due but could not be saved. |

Integrators should use these rather than querying the tables directly - but
for *enrolling* somebody, prefer granting the access role
(`Support\Roles::grant_access()`, or plain `add_user_role()`): that is the
supported door, and it is the one the Learners tab and the store use.
`anchor_courses_enroll_user()`'s own `$args` deliberately shrinks the brief's
original `source`/`source_id`/`metadata`/`bypass_checks` shape down to just
`source`/`source_id`: `Roles::grant_access()` always runs `can_enroll()`
(prerequisites, publish state) and refuses an unpublished course, so there is
no bypass to expose, and `metadata` has no corresponding write path - both
keys are silently accepted and ignored if passed.

---

## Shortcodes

| Shortcode | Attributes | Renders |
|---|---|---|
| `[anchor_courses]` | `limit` (20) | published courses by title, with a CE credits badge |
| `[anchor_course]` | `id` (current post) | course page: meta, progress, curriculum, access CTA |
| `[anchor_course_progress]` | `course_id` (current post) | the signed-in learner's progress bar |
| `[anchor_my_courses]` | - | the learner's enrolments and percent |
| `[anchor_my_credits]` | - | the learner's CE credits and total |
| `[anchor_my_certificates]` | - | the learner's certificates, linking to their verification pages |

Templates (`single-course`, `single-lesson`, `course`, `lesson`,
`live-session`, `quiz`, `dashboard`, `certificate`) are overridable from the
theme at `anchor-courses/{name}.php`.

---

## REST API (`anchor-courses/v1`)

No route in this namespace uses `__return_true` - even the public catalogue
names a real permission callback (`Routes::public_read()`; a site that makes
courses non-public closes the catalogue with it). Quiz and `/me/` routes
require a signed-in user (`Routes::require_login`, 401 otherwise), and every
`/me/` / `/lessons/{id}/*` callback reads `get_current_user_id()` and ignores
any `user_id` in the request, so one learner can never address another's
records (brief 25).

### Public / catalogue

| Method | Route | Args | Success |
|---|---|---|---|
| GET | `/courses` | `per_page` (20), `page` (1) | 200, published courses (id, title, excerpt, permalink, instructor, duration, difficulty, `ce_credits`, `progression_mode`, `item_count`) - no learner data |
| GET | `/courses/{id}` | - | 200, one course summary; 404 `no_course` when absent/unpublished |
| GET | `/courses/{id}/curriculum` | - | 200, modules -> items with `type`/`id`/`title`/`required` only - never a quiz's questions or answers; 404 `no_course` when absent/unpublished |

There is no `POST /courses/{id}/enroll`: a REST enrol route is
self-enrolment with a JSON body, and self-enrolment does not exist (design
spec 7). Access is a role, and the things that may hand one out - the
Learners tab, the WooCommerce adapter - are both server-side; a client that
needs to know whether it may show a "buy" link reads `/me/courses`.

### Learner

| Method | Route | Args | Success |
|---|---|---|---|
| GET | `/me/courses` | - | 200, the signed-in learner's own enrolments (`course_id`, `title`, `permalink`, `status`, `percent`) |
| GET | `/me/courses/{id}/progress` | - | 200, `ProgressService::get_course_progress()->to_array()` for the signed-in learner |
| POST | `/lessons/{id}/start` | `course_id` (required) | 200, `Progress::to_array()`; 404 `no_lesson` when the lesson itself is not `publish` (PR36 round 3); 403 `locked` when `ProgressService::start_lesson()` otherwise refuses (not enrolled, not in the course, or sequential gating) |
| POST | `/lessons/{id}/complete` | `course_id` (required) | 200, `Progress::to_array()`; service `WP_Error` mapped through `Routes::error_response()` (404 `no_lesson` when the lesson itself is not `publish`, PR36 round 3; 403 `not_enrolled`, `not_in_course`, `locked`; 409 `quiz_required`; 503 `save_failed`; 400 unmapped) |
| GET | `/me/certificates` | - | 200, the signed-in learner's certificates + `course_title` |
| GET | `/me/credits` | - | 200 `{credits:[...], total}` for the signed-in learner |

### Quiz

| Method | Route | Args | Success |
|---|---|---|---|
| POST | `/quizzes/{id}/attempts` | `course_id` (required) | 201, attempt + questions |
| GET | `/quiz-attempts/{id}` | `course_id` (optional) | 200, attempt (questions while open); applies the timer first |
| POST | `/quiz-attempts/{id}/answer` | `question_id`, `value` (string or up to 50 strings), `course_id` (optional) | 200 `{saved:true}` |
| POST | `/quiz-attempts/{id}/submit` | `answers` (object, question_id => value, each value bounded like `answer`), `course_id` (optional) | 200, graded attempt |

Attempt routes check ownership first (`no_attempt` 404, `attempt_not_yours`
403), then course context (audit F05): the attempt row's `course_id` is
authoritative, and a request naming a different `course_id` is refused 409
`attempt_course_mismatch` (quiz.js always sends the course it rendered). The
client may only send **answers**. Scores, pass/fail, attempt numbers and
timing are decided server-side; a `score` in the request body is ignored. No
response ever carries a `correct` flag before the attempt is graded, and
`grading_data` appears only when the quiz's `show_correct_answers` is on.
Responses only ever carry `QuizAttempt::for_learner()` (score hidden when
`show_score` is off, grading data only with `show_correct_answers`).

### Admin reporting (`Rest\AdminController`)

Staff-only reads. Each route names the capability it needs rather than a
blanket "is admin" check: `reports` (`view_anchor_course_reports`) for the
roster and completions routes, `credits` (`manage_anchor_credits`) for the CE
ledger - a user can hold one without the other. Logged out is 401; signed in
without the capability is 403.

| Method | Route | Args | Success |
|---|---|---|---|
| GET | `/admin/courses/{id}/learners` | `per_page` (50, max 200), `page` (1) | 200, `Admin\LearnerReports::rows()` - the same batch-loaded roster the Learners metabox renders; 404 `no_course` |
| GET | `/admin/users/{id}/courses` | - | 200, `Admin\LearnerReports::user_rows()` - the same rows the user-profile Courses block renders; 404 `no_user` |
| GET | `/admin/reports/completions` | `course_id` (optional), `from`, `to` (`Y-m-d`, inclusive) | 200 `{total, truncated, rows}` of `EnrollmentRepository::completions()` (status `completed`), each row `user_id`, `display_name`, `course_id`, `course_title`, `completed_at`; 400 `invalid_date` |
| GET | `/admin/reports/credits` | `course_id` (optional), `from`, `to` (`Y-m-d`, inclusive) | 200 `{total, total_credits, truncated, rows}` of `CreditRepository::report()`, each row a `Credit::to_array()` plus `display_name`, `user_email`, `course_title`; 400 `invalid_date` |

There is no REST route for enrol/revoke/cancel/reset/complete/uncomplete:
those already have a door (`admin-post.php?action=anchor_courses_add_learner`
/ `anchor_courses_revoke_access` / `anchor_courses_manage_enrollment` - see
"Admin surfaces and notices" above) and this controller is read-only, so it
does not open a second one. `completions()`/`credits()` cap `rows` at 1000
(no page param on a report) rather than leaving either query unbounded, but
never hide that: `total` is a `COUNT(*)` over every match
(`EnrollmentRepository::count_completions()`,
`CreditRepository::report_totals()` - each shares its WHERE builder with the
row query, so the two cannot disagree), `total_credits` is summed over every
match too, and `truncated` is `true` exactly when `total` exceeds the rows
returned - narrow the range (`course_id`, `from`, `to`) to see the rest.
`from`/`to` must be real `Y-m-d` dates (`2026-02-30` is refused); anything
else is a 400 `invalid_date`. `learners()` bounds `per_page` to `[1,200]`.
No route in this controller leaks lesson body or quiz-answer data.

### Error -> HTTP status map (`Routes::error_response()`)

The one place a service's plain `WP_Error` (no HTTP concept inside a
service) is mapped to a status code:

| Status | Codes |
|---|---|
| 400 | `unknown_question`, `invalid_date`, and anything not listed below |
| 403 | `not_enrolled`, `locked`, `not_in_course`, `course_closed`, `missing_prerequisite`, `no_attempts_remaining`, `retry_delay`, `attempt_not_yours` |
| 404 | `no_attempt`, `no_course`, `no_user`, `no_lesson` (lesson not `publish`) |
| 409 | `attempt_closed`, `no_questions`, `attempt_course_mismatch` (audit F05), `save_conflict` (audit F06), `attempt_busy` (audit F07), `quiz_required` (completing a `quiz_pass` lesson by hand) |
| 503 | `save_failed` (audit F03), `read_failed` (safe to retry either way) |

Every controller error goes through this map - including the simple
existence checks (`no_course`, `no_user`, `no_attempt`) and the controllers'
own refusals (`locked`, `attempt_not_yours`, `invalid_date`), which build a
`WP_Error` and hand it to `Routes::error_response()` rather than choosing a
status themselves.

## Other (non-REST) endpoints

| Endpoint | Handler |
|---|---|
| `admin-post.php?action=anchor_courses_complete_lesson` (also nopriv) | `Frontend\Actions` - "Mark complete"; redirects with `anchor_courses_notice` |
| `admin-post.php?action=anchor_courses_add_learner` / `anchor_courses_revoke_access` | `Admin\LearnerReports` (nonce `anchor_courses_learners_{course}`, cap `enrollments`) |
| `admin-post.php?action=anchor_courses_manage_enrollment` | `Admin\EnrollmentManager` - see "Admin surfaces and notices" |
| `admin-post.php?action=anchor_courses_delete_role` | `Admin\CourseEditor` (cap `manage`) |
| `admin-ajax.php?action=anchor_courses_search_items` / `anchor_courses_create_item` | curriculum builder |
| `/certificate/{token}/` | `Frontend\CertificatePage` - public verification page (query var `anchor_certificate`, noindex) |

---

## WooCommerce integration (`Integrations\WooCommerce`)

Only constructed when `class_exists('WooCommerce')`; `Module::$woocommerce` is
null otherwise. A product's `_anchor_course_ids` meta names the course(s) it
grants (`WooCommerce::set_courses_for_product()`/`courses_for_product()`); a
variation's own mapping wins over its parent product's when set. **A
variation may also explicitly opt out of the parent's mapping**: saving it
with no courses selected and the override checkbox on (see below) stores the
sentinel value `WooCommerce::NONE_OVERRIDE` (`'none'`) instead of deleting the
meta - distinct from unmapped (meta absent, which INHERITS the parent's
mapping). `courses_for_ids()` checks for the sentinel before ever falling
back to the parent. The mapping accepts any `anchor_course` post regardless
of status (draft/private included) - see "Publishing" below for what happens
when a mapped course isn't published yet.

The product screen's **Courses** tab lists every course in
`WooCommerce::MAPPABLE_STATUSES` (publish, private, draft, pending, future),
unpublished ones labelled with their status (e.g. "Staged Course (draft)"),
and always includes every course already mapped - so saving a product never
drops a mapping merely because the course is unpublished.

**Variation mappings are otherwise code-only.** The Courses tab lives on the
parent product's data panel; there is no per-variation multi-select. Setting
SPECIFIC courses on a variation, distinct from its parent's, can only be done
in code with `WooCommerce::set_courses_for_product( $variation_id, [ ... ] )`.
The one thing the variation panel itself exposes is the minimal opt-out
above - a single "No courses (override the parent)" checkbox
(`render_variation_fields()`/`save_variation_courses()`, hooked on
WooCommerce's own `woocommerce_product_after_variable_attributes` /
`woocommerce_save_product_variation`, the same pair
`anchor-events-manager/class-woocommerce.php` uses for its own per-variation
event field).

**The resolved courses are snapshotted onto the order line, ALWAYS.** At
checkout (`woocommerce_checkout_create_order_line_item`) the line's mapped
courses - resolved from the product/variation exactly as above - are written
onto the order item as `_anchor_courses_course_ids`
(`WooCommerce::COURSE_IDS_META`), before the order is ever saved -
UNCONDITIONALLY, even when the line resolves to `[]` (an unmapped product,
or a variation's explicit `NONE_OVERRIDE` opt-out). An empty array IS the
snapshot for such a line, not "nothing to freeze": the only thing that means
"no snapshot" is the meta being ABSENT entirely, which happens only for a
line from before this existed, or an order created outside checkout (the
same "outside checkout" cases the account section below already
distinguishes) - `resolve_item_courses()`'s `is_array()` check is what tells
a real `[]` snapshot apart from that legacy absence. `enroll_order()`,
`retry_orders_for_course()` and both revoke paths all resolve a line's
courses through this snapshot FIRST, falling back to the live
product/variation mapping only for a line with no snapshot at all. A legacy
line without one gets a snapshot written the first time `enroll_order()`
resolves it (empty or not), whether or not the grant that follows succeeds.
The practical effect: remapping a product from course A to B while an order
is still pending does not change what that order grants - the buyer who
checked out against A still gets A, and a buyer whose line sold NO course at
all cannot be handed one by a later remap either; and removing a mapping
entirely before a refund does not make the already-granted course
un-revocable - the refund still finds A in the snapshot and takes it back.

An order reaching a qualifying status (`ENROLL_STATUSES`, default
`processing`/`completed`, filter `anchor_courses_wc_enroll_statuses`) grants
the access role for every mapped course through `Roles::grant_access()`
(never `EnrollmentService::enroll()` directly - the role IS the enrolment).
A refusal (missing prerequisite, or the course still draft/private) is logged
and left as an order note (`blocked_prerequisite`/`blocked_no_course`); the
order itself is never touched - refunding is a human decision.

**Accounts.** Only an order that carries a mapped course line ever resolves
or creates an account: `enroll_order()` reads the lines first and returns 0
for anything else (a ticket-only or any other unmapped order, at any status,
creates nobody). The revoke and refund-policy paths never create accounts at
all - they look up the order's customer id, then an existing user with the
billing email, and with neither there is nothing to revoke. A course buyer
always ends up with a login, by one of two paths:

- **At checkout** (classic and block/Store API alike): while the cart holds
  a product that grants a course, `woocommerce_checkout_registration_required`
  and `woocommerce_checkout_registration_enabled` both return `true`, so
  guest checkout is off for that cart and WooCommerce creates the account
  itself. Carts with no course product keep the store's own settings.
- **Outside checkout** (an admin-created order, the REST API, or a guest
  that slipped through): `enroll_order()` resolves the buyer from the
  billing email through `Support\Accounts::resolve()`, which consults
  `anchor_courses_create_account` with the order as `$context`. The order is
  then linked to that account (`set_customer_id()` + `save()`), and an
  account created just now - never one that already existed - gets
  WordPress's own set-password notice
  (`wp_new_user_notification( $user_id, null, 'user' )`). If the filter
  declines, nothing is granted, and the decline is logged and left as an
  `account_not_created` order note.

**Publishing.** Publishing a draft/private mapped course retries every
already-qualifying order against it (`on_course_published()` ->
`retry_orders_for_course()`), so an order blocked by `blocked_no_course`
self-heals once the course goes live without anyone having to touch the
order. Orders are found **by line item, through the UNION of two lookups**:
every product/variation (any status) CURRENTLY mapped to the course, then
the orders whose items name one of them, read from WooCommerce's order-item
tables (`woocommerce_order_items` + `_product_id`/`_variation_id` item meta -
used by both the posts and the HPOS order stores; not
`wc_order_product_lookup`, which is filled by an asynchronous Action
Scheduler import) - **and, separately, every order whose line already froze
this course into its own `_anchor_courses_course_ids` snapshot**, whatever
the product maps to now. The second lookup is what catches a course bought
while still a draft and then remapped onto a different product (or the
product remapped to a different course entirely) before it published - the
first lookup alone would find nothing of the original sale in that case. Each
candidate from either lookup is re-verified through the line's own
`resolve_item_courses()` (snapshot first, same as every other read path)
before anything is granted, so a false match from either lookup's LIKE query
is harmless. Each of the two lookups is bounded separately to the newest
`anchor_courses_wc_retry_order_limit` (default `RETRY_ORDER_LIMIT`, 500)
matching orders - it counts course orders only, not the whole store, but of
ANY status (failed, cancelled and refund records included), so a course with
many abandoned attempts can push older paid orders past the bound - and
hitting either bound is logged (`wc_retry_capped`); older orders past the
bound need a manual resync (`enroll_order( $order_id )`). Each order found by
either lookup is then checked for a qualifying status and re-resolved line by
line before granting.

A course page shows an **Enrol** button only when a product maps to the
course (`WooCommerce::products_for_course()`, the reverse lookup), and it
links to that product (`anchor_courses_access_cta`). A course mapped only on
a `product_variation` still gets the button - the reverse lookup includes
variations and resolves each match to its PARENT product (deduplicated
against any product that also matches directly), since a visitor buys the
product, never a bare variation. With no product (and no variation whose
parent resolves), the page shows the `anchor_courses_no_access_message` text
instead.

### Refund and cancellation

An order moving to `cancelled`/`failed` (of `REVOKE_STATUSES`) takes back
every course access role THIS order granted (`revoke_order()`) - both carry
no refund object at all, so the whole order is the only signal either gives.
An order moving to **`refunded`** instead goes through the same per-line path
a partial refund does (`revoke_refunded_lines()`, below) rather than the
unconditional `revoke_order()`: a shop manager can set an order to `refunded`
by hand after only a partial refund, and some gateways do this automatically
too, so treating the status alone as "revoke everything" would take back a
line's course that was never actually refunded. `woocommerce_order_refunded`
(which fires for every refund, full or partial, whether or not it also moves
the order into `refunded`) reaches the same method directly. Either way, only
the course(s) whose mapped line was refunded IN FULL are revoked - an
untouched or partially-refunded line's course survives. **When the order
carries no refund records at all** (the `refunded` status was set by hand, or
by a gateway that skips WooCommerce's own refund flow, with no
`wc_create_refund()` behind it), there is no per-line refund quantity to
read, so every mapped line is treated as fully refunded instead - the
order's status is the only fact available, and it says the whole order is
refunded.

**Amount-only refunds** (`wc_create_refund()` called with a bare `amount`
and no `line_items` - the same shape
`anchor-events-manager/class-woocommerce.php` recognises for its own
refunds) leave `get_qty_refunded_for_item()` at 0 for every line, exactly
like the no-refund-records case above - there is no per-line quantity for
either signal to read. The two are told apart by the order's CUMULATIVE
refunded amount (`get_total_refunded()`) against its total: once the
cumulative amount reaches the order's total - which is also what makes
WooCommerce itself flip the order to `refunded` - every mapped line is
treated as fully refunded, the same as a full refund with real line items.
A **partial** amount-only refund (cumulative amount still under the order's
total) is different: it cannot be attributed to any one line at all - there
is no quantity AND no whole-order signal - so it revokes nothing rather than
guess. A line-item refund and an amount-only refund on the same order still
compose correctly: the amount-only branch only ever fires when the
cumulative total already covers the whole order, at which point every line
is fully refunded regardless of how the total got there.

**What "this order granted" means.** Revocation is matched through
`Roles::grant_record( $user_id, $course_id )` - what the grants map says is
held *now* - never the enrolment row's `source`/`source_id`, which keep the
row's FIRST source forever and never change again. This is why a manual comp
layered on top of a purchase survives a refund of the order that originally
paid for it (`record_grant()` upgrades the record to `manual` the moment
`Roles::grant_access( ..., 'manual' )` runs against an already-held role),
and why two orders granting the SAME course behave asymmetrically: the grants
map holds only one record per course, and `record_grant()` keeps the FIRST
non-manual reason, so the map goes on crediting whichever order granted
first. Refunding a *later* order that also paid for an already-held course is
therefore correctly a no-op (its `source_id` never matches); refunding the
*first* order still revokes the course even though a second order also paid
for it - a known limit of the single-record grants map, not something this
task redesigns. See "Known limitations and follow-ups".

Two policies stack: `anchor_courses_wc_refund_policy` (`WooCommerce::refund_policy()`,
default `remove_role`, filter args `$policy, $order_id, $course_id, $user_id`)
decides whether the refund takes the access role away at all; if it does,
the usual `anchor_courses_role_loss_policy` (default `keep`) is consulted
inside `Roles::revoke_access()` exactly as for any other revocation, deciding
what the loss means for the enrolment row.

**The revoked marker.** The first successful revoke on an order stamps
HPOS-safe order meta `WooCommerce::REVOKED_META` (`_anchor_courses_wc_revoked`,
via `$order->update_meta_data()`/`save()`, never `update_post_meta()`) and
leaves one order note. While marked, `enroll_order()` refuses to re-grant
from that order - a stray duplicate status fire must not silently undo a
deliberate refund. **This marker is order-level, not course-level**: while it
stands, the WHOLE order is refused re-grants, including any of that order's
OTHER mapped courses that were never touched by the partial refund that set
the marker. No admin UI ships with this task; clear it from WP-CLI or PHP
with `WooCommerce::clear_revoked_marker( $order_id )`, then re-run the order
through `enroll_order()` (or re-fire its qualifying status) to restore
access. A refund against an order that never granted anything (e.g. a
blocked prerequisite) is a silent no-op: nothing is revoked, the marker is
not set, and no note is added.

---

## The live-session lesson type and the stream veto

A lesson's `type` setting (`content` default, or `live_session`) decides how
it renders and what it may gate. `live_session` lessons read the (optional)
events module ONLY through its public API
(`\Anchor\Events\Module::resolved_sessions()`/`::room_url()`,
`\Anchor\Events\Stream_State::for_event()`) - never event tables, seat posts
or `_anchor_event_*` meta directly. Every symbol `Integrations\Events` touches
is guarded, so a site with the events module absent or older gets a degraded
render (`Live session unavailable.`), never a fatal.

Courses never enrols anybody through events, and there is no auto-enrolment
from event attendance: an event grants `anchor_event_{id}`, which courses
treats as a **prerequisite** role only (usable in a course's `prerequisites`
setting) - `Support\Roles`' listener matches `anchor_course_{digits}` and
nothing else, so an attendee never silently lands inside a course. If
attendance-based auto-enrolment is ever wanted, it belongs on the EVENT side
as an "also grant these roles" field, not as a picker here (design spec 7).

### Rendering (`Frontend\Shortcodes::render_live_session()`)

A `live_session` lesson renders the `live-session` template with: the
event's resolved sessions (`Events::sessions()`), its stream state
(`Events::stream_state()`), and a room URL that is withheld unless BOTH the
events module resolves the event AND the current user
`is_enrolled()` in the lesson's course - a COURSE access decision, made here,
that is separate from and in addition to the events module's own room
entitlement check (`Entitlements::can_access_stream()`), which still runs
independently once a learner follows the link. Courses never embeds the
stream itself; the event room is the only player. The template's own
`$ready` (renders as `$available`) is `Events::available() &&
Events::event_exists( $event_id )` (PR36 round 3, Codex) - so when the
event does not resolve (module inactive, or the lesson's `event_id` no
longer names a real `event` post, including one deleted AFTER the lesson
saved it), the template falls back to "Live session unavailable." rather
than the "will appear here before the session starts" wording, which
implies the event still exists.

The schedule table renders each session's clock time in the same zone the
room does: `Events::sessions()` attaches the zone name
`Module::event_timezone()` resolved for the event - the event's own
timezone when the events setting `timezone_mode` is `event`, the site
timezone when it is `site` (the default) - and the template passes that zone
into `wp_date()` as its third argument. Whichever mode is set, the same
instant never reads differently here than in the room.

When the stream veto (below) would refuse this learner the room, the
template shows the veto's notice - "Finish the earlier lessons in {course}
first." with a link to the unfinished lesson - **instead of** the Join
button. It asks `Events::prework_block()`, the same decision
`veto_stream_access()` makes, for the lesson's own `session_index`. The room
asks the same function for the session that is live RIGHT NOW, so when a
lesson is bound to a different session than the one streaming, the lesson
page and the room can legitimately show different states - each is answering
for its own session.

The events module's own event page has a separate "Join here" link
(`Module::can_view_virtual_link()`); that is events-side behaviour and it
consults `can_access_stream()` for **session 0 only**, so on a multisession
event a veto on a later session is not reflected there - the room itself
still enforces it.

### The stream veto (`Integrations\Events::veto_stream_access()`, Task 37)

Registered unconditionally on the events module's own single "may this
person watch?" filter, `anchor_events_can_access_stream` (events spec 4.5,
`Entitlements::can_access_stream()`) - attaching to a filter the optional
events module never fires is free, and it must already be attached before
that module's own bootstrap can possibly call it.

Courses may only **subtract** access: a `false` coming in stays `false`
going out - this veto never grants access the events module itself already
refused. When a `live_session` lesson for the event and session in question
has `require_prior_items` ticked, every required curriculum item ordered
before it - in every PUBLISHED course that lists it
(`Events::live_lessons_for_event()`, one row per (lesson, course) pairing) -
must be complete for this learner in THAT course; access is denied as soon
as any one applicable row blocks. **The gating `live_session` lesson itself
must also be PUBLISHED** (PR36 round 2, Codex) -
`live_lessons_for_event()`'s own query only selects `publish` lessons, so a
private or draft one (staged content a learner cannot open through any
public route) is never in the gating set at all and cannot veto anyone. The
prior-items rule itself is
`ProgressService::prior_required_items_complete()`, the exact loop
`is_item_available()` runs for sequential progression, reused rather than
re-implemented, and applied regardless of the course's own
`progression_mode` - a `free` course can still gate one specific
`live_session` lesson on its own pre-work.

Two exemptions, evaluated per (lesson, course) row, not globally:

- a learner **not enrolled** in that particular course has no pre-work to
  owe it and is skipped, not denied - the course cannot block an event it
  does not own the attendee of;
- **staff** - anyone who can `edit_post` that specific lesson - is never
  vetoed, so an instructor managing or previewing the room is not locked out
  by their own unfinished "pre-work".

`live_lessons_for_event()` is memoised per request (one `get_posts()` plus a
`Curriculum::courses_for_item()` scan per lesson found is too expensive to
repeat on every stream-state poll) and invalidated by
`anchor_courses_curriculum_saved`, directly from `LessonEditor::save()`
(whose own `event_id`/`session_index`/`require_prior_items` writes never fire
that action themselves), and by `Events::flush_on_status_transition()`
(hooked to WordPress's own `transition_post_status`, PR36 round 3,
CodeRabbit) - a lesson's status can cross the `publish` boundary through
Quick Edit, a bulk action, or any other programmatic `wp_update_post()`,
none of which post the metabox nonce `LessonEditor::save()` requires, so its
own flush never ran for them. Registered on `Integrations\Events` itself
(constructed unconditionally by the Module bootstrap) rather than on
`LessonEditor` (constructed only `if ( is_admin() )`), so it fires for a
status change made through any request context, not only wp-admin.

**Why, not just no.** `veto_stream_access()` records its last denial per
request as `[event_id][user_id] => {course, unfinished item}`
(`Events::prework_block()`), and courses filters the events module's
`anchor_events_room_denied_message` (`Events::denied_message()`): a vetoed
learner - who IS registered - sees "Finish the earlier lessons in {course}
first." with an escaped link to the unfinished lesson in its course context
(`Access::lesson_url()`; a quiz links the course page), not "This account
isn't registered". The room's REST poll (`GET
/anchor-events/v1/events/{id}/room`) runs its 403 message through the same
filter, tags stripped. A denial the events module made on its own (no seat,
no role) keeps the default wording; the record is cleared whenever the
veto is consulted with an incoming `false` or finds nothing blocking.

---

## Analytics (`Integrations\Analytics`)

Browser `dataLayer` pushes for GTM/GA4 across the learner lifecycle,
controlled by the `anchor_courses_analytics_enabled` filter (default `true`
- when off, nothing is ever queued, and a queue from before the flip is
never printed) and editable/droppable per event via
`anchor_courses_datalayer_event`.

| Hook | dataLayer event | Payload keys |
|---|---|---|
| `anchor_courses_enrolled` | `course_enrolled` | `course_id` |
| `anchor_courses_course_started` | `course_started` | `course_id` |
| `anchor_courses_lesson_started` | `lesson_started` | `course_id`, `lesson_id` |
| `anchor_courses_lesson_completed` | `lesson_completed` | `course_id`, `lesson_id` |
| `anchor_courses_quiz_started` | `quiz_started` | `course_id`, `quiz_id`, `attempt_id` |
| `anchor_courses_quiz_submitted` | `quiz_completed` | `course_id`, `quiz_id`, `attempt_id`, `score` |
| `anchor_courses_quiz_passed` | `quiz_passed` | `course_id`, `quiz_id`, `attempt_id`, `score` |
| `anchor_courses_quiz_failed` | `quiz_failed` | `course_id`, `quiz_id`, `attempt_id`, `score` |
| `anchor_courses_course_completed` | `course_completed` | `course_id` |
| `anchor_courses_ce_credit_awarded` | `ce_credit_awarded` | `course_id`, `credits` |
| `anchor_courses_certificate_issued` | `certificate_generated` | `course_id`, `certificate_id` |

Every payload also carries `event`. **IDs only** - no name, email, title, or
even the WordPress user id ever appears in a payload: the fixed allow-list
(`event`, `course_id`, `lesson_id`, `quiz_id`, `attempt_id`, `score`,
`credits`, `certificate_id`) is applied where every payload enters the
queue - after `anchor_courses_datalayer_event` has run, and for
`Analytics::queue()`'s own callers too - so neither a filter nor a custom
event can add another key. Not
wired: `anchor_courses_quiz_expired`, `anchor_courses_course_recompleted`,
`anchor_courses_access_granted`/`_revoked` - a product call, not an
oversight.

Server-side actions fire during a request with no page of its own
(`admin-post.php` redirects, REST writes), so an event is queued
(`Support\UserEventQueue`, a 5-minute transient, capped at 50 entries per
user) against the **learner the event is about**, not necessarily whoever is
logged in when it fires - an admin enrolling or completing a course for a
learner from wp-admin must not have that learner's event attributed to the
admin's own session. `wp_footer` (priority 30) flushes and prints the current
visitor's own queue as one `<script>` tag (`wp_print_inline_script_tag()`),
so it can land on whatever front-end page that learner's browser loads next.

`Support\UserEventQueue` is deliberately generic (nothing analytics- or
courses-specific): the plugin's other modules load as classic
`require_once`d classes rather than PSR-4, so if `anchor-events-manager`
ever wants the same "queue it, print it once" pattern for its own dataLayer
events, it should depend on this class (Composer's autoloader is
plugin-wide) rather than writing a second transient queue.

---

## Database

Five tables, prefix `{$wpdb->prefix}anchor_courses_`, schema version in the
`anchor_courses_db_version` option (`autoload=false`), migrations run from
the Module constructor and again on `admin_init`:

| Table | Holds | Uniqueness |
|---|---|---|
| `anchor_courses_enrollments` | who is enrolled in what, and why | `(user_id, course_id)` |
| `anchor_courses_progress` | per-item state | `(user_id, course_id, item_id, item_type)` |
| `anchor_courses_quiz_attempts` | attempts, answers, grading data | `(user_id, course_id, quiz_id, attempt_number)` |
| `anchor_courses_ce_credits` | CE awards | `(user_id, course_id)` |
| `anchor_courses_certificates` | issued certificates | `(user_id, course_id)`, `certificate_number`, `verification_token` |

Those unique keys are the idempotency mechanism: repeated completion cannot
produce duplicate credits or certificates. One consequence: a course awards
CE once per learner, ever - see "Certificates and credits".

| Version | Change |
|---|---|
| 1.0.0 | initial schema; capabilities granted |
| 1.1.0 | `quiz_attempts.metadata` (pinned timer settings) |
| 1.2.0 | `certificates.verification_token` becomes UNIQUE |
| 1.3.0 | `quiz_attempts` unique key becomes `user_course_quiz_attempt` (user, course, quiz, attempt_number) - audit F05; `quiz_attempts.revision` (answers compare-and-swap) - audit F06 |

Statuses: enrolment `enrolled`, `in_progress`, `completed`, `expired`,
`cancelled`; progress `not_started`, `in_progress`, `completed`, `failed`;
attempt `in_progress`, `submitted`, `graded`, `expired`, `abandoned`
(`abandoned` does not count toward `max_attempts`).

**Data is never deleted on deactivation.** Only the guarded uninstall branch
(below) drops anything.

---

## Cron

`anchor_courses_expire_sweep` (daily, scheduled on load, cleared on plugin
deactivation): `EnrollmentService::sweep_expired()` flips due rows to
`expired` and removes the access role; `QuizService::sweep_expired_attempts()`
re-opens stale `submitted` claims, then applies the timer policy to timed
attempts left open past their deadline. One cron hook, two closers.

---

## Capabilities (`Support\Capabilities`)

| Key | Capability | Used for |
|---|---|---|
| `manage` | `manage_anchor_courses` | deleting a course role |
| `edit_courses` | `edit_anchor_courses` | the `anchor_course` CPT (every primitive maps to it) |
| `edit_lessons` | `edit_anchor_lessons` | the `anchor_lesson` CPT; also the lesson preview (`edit_post`) |
| `edit_quizzes` | `edit_anchor_quizzes` | the `anchor_quiz` CPT |
| `reports` | `view_anchor_course_reports` | the Learners metabox, other users' profile Courses block, and the roster/completions REST routes only - `/admin/courses/{id}/learners`, `/admin/users/{id}/courses`, `/admin/reports/completions` (NOT `/admin/reports/credits` - that route is `manage_anchor_credits`, see "Admin reporting" above) |
| `enrollments` | `manage_anchor_enrollments` | Learners tab add/revoke, Manage enrolment actions |
| `credits` | `manage_anchor_credits` | the `/admin/reports/credits` REST route; otherwise reserved (not yet checked anywhere else) |
| `certificates` | `manage_anchor_certificates` | reserved (not yet checked anywhere) |

Granted to the roles named by `anchor_courses_capability_roles` (default
`[ 'administrator' ]`) during migration 1.0.0. `Capabilities::sync()` is
idempotent; `Capabilities::remove()` (uninstall only) strips all eight from
EVERY role, not just the ones the filter currently names, so a role granted a
capability by a filter value that has since changed is never left holding a
stranded cap.

---

## Uninstall

Learner history is kept by default. With
`update_option( 'anchor_courses_delete_data_on_uninstall', 1 )` set first,
uninstall drops the five tables, the version/rewrite options, the eight
capabilities, every `anchor_course_{id}[_completed]` role and the
`_anchor_course_grants` user meta, and clears the cron.

---

## Known limitations and follow-ups

**WooCommerce grants map and revoked marker (see "WooCommerce integration"
for the full mechanics):**

- The revoked marker (`_anchor_courses_wc_revoked`) is stamped at the ORDER
  level, not per course. A partial refund that revokes only ONE of an
  order's several mapped courses still marks the WHOLE order revoked, which
  blocks `enroll_order()` from re-granting any of that order's OTHER
  courses later (e.g. on a retry, or if a future status change would
  otherwise re-qualify it) until an operator clears the marker with
  `WooCommerce::clear_revoked_marker( $order_id )`.
- The grants map (`_anchor_course_grants` user meta) holds exactly one
  record per course. When two different orders both grant the SAME course
  to the same learner, refunding the order that granted it FIRST revokes
  the course even though the second order also paid for it; refunding the
  SECOND order is a no-op (its `source_id` never matches the recorded
  grant). This is a known limit of the single-record design, not something
  Task 40 redesigns.

**Certificates and credits:**

- PDF certificate generation is deferred; `certificates.file_path` is
  reserved and stays empty. Phase 1 ships only the HTML verification page.
- CE credit is awarded once per (user, course) forever - the table's UNIQUE
  key enforces it. A renewal/recertification course needs a schema
  migration (a period or expiry column), not a code change.
- The `credits` and `certificates` capabilities are minted and granted to
  administrator, but `certificates` is not checked anywhere in the code yet
  (`credits` gates the one `/admin/reports/credits` REST route).

**Deliberately out of scope for Phase 1** (design spec 7): webhooks (brief
20), `external_event` lesson completion, instructor roles, video-percentage
lesson completion, self-enrolment, and event-attendance -> course
auto-enrolment (an attendee never lands in a course without an explicit
grant; see "The live-session lesson type and the stream veto"). None of
these have partial scaffolding waiting to be finished - they are not
started.

**Admin reporting REST is read-only by design**, not by omission: every
write action (enrol, revoke, cancel, reset, complete, uncomplete, repair)
already has an `admin-post.php` door (see "Admin surfaces and notices"), and
`Rest\AdminController` intentionally does not open a second one over REST.
