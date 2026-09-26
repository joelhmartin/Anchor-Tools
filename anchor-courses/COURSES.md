# Anchor Courses

Module key `courses`, class `\Anchor\Courses\Module`, namespace `Anchor\Courses`
(PSR-4 root `anchor-courses/src/`). Bootstrap: `anchor-courses/anchor-courses.php`.
Text domain `anchor-schema`. All times stored UTC (`Y-m-d H:i:s`); `Support\Clock`
reads "now" through the `anchor_courses_now` filter so tests can freeze it.

The one rule everything else follows: **holding the role `anchor_course_{id}` IS
enrolment.** Nobody enrols themselves; access arrives as a role, granted by an
admin (Learners tab), a purchase, the PHP API, WP-CLI or any plugin.

## Services (`Module::instance()`)

| Property | Class | Owns |
|---|---|---|
| `enrollments` | `Services\EnrollmentService` | the enrolment row and its status machine; `can_enroll()`, `is_enrolled()`, `restart()`, the expiry sweep |
| `progress` | `Services\ProgressService` | the one progress calculation, item availability, lesson start/complete, `record_item()`, `reset_course()` |
| `quizzes` | `Services\QuizService` | attempts: start/resume, answer, atomic submit, timers, the attempt sweep |
| `credits` | `Services\CreditService` | CE credit records |
| `certificates` | `Services\CertificateService` | issue, snapshot, render, token lookup |
| `completion` | `Services\CompletionService` | the once-only completion pipeline (injected into `progress`) |
| `shortcodes` | `Frontend\Shortcodes` | shortcodes plus `render_lesson()` / `render_quiz()` |

Always use these instances. A freshly constructed `new ProgressService()` has no
completion pipeline and will never complete a course.

## Public PHP API (`api.php`, root namespace)

Thin wrappers over the module's own services. Each returns
`WP_Error('courses_not_loaded')` (or `null` for the credit award) if called before
the module has booted.

| Function | Returns | Notes |
|---|---|---|
| `anchor_courses_enroll_user( $user_id, $course_id, array $args = [] )` | `Domain\Enrollment\|WP_Error` | Grants the access role via `Roles::grant_access()` (so prerequisites and publish state are enforced). `$args`: `source` (default `api`), `source_id`. |
| `anchor_courses_complete_lesson( $user_id, $course_id, $lesson_id )` | `Domain\Progress\|WP_Error` | Idempotent; subject to enrolment and progression. Completing the last required item runs the completion pipeline. |
| `anchor_courses_get_progress( $user_id, $course_id )` | `Domain\CourseProgress\|WP_Error` | `percent`, `completed_required`, `total_required`, `complete`, `completed_item_keys`. |
| `anchor_courses_award_ce_credit( $user_id, $course_id, $credits )` | `Domain\Credit\|WP_Error\|null` | Idempotent per (user, course); `null` when there is nothing to award; `WP_Error('credit_insert_failed')` when a credit was due but could not be saved. |

## Roles and enrolment (`Support\Roles`)

Each course owns two capability-less roles.

| Role | Slug | Display name | Minted |
|---|---|---|---|
| Access | `anchor_course_{id}` | `Course: {title}` | on `save_post_anchor_course` of a **published** course |
| Completion | `anchor_course_{id}_completed` | `Completed: {title}` | lazily, on the first completion |

- Both are renamed when the title changes (`save_post_anchor_course`, priority 20)
  and never deleted automatically. The course editor's **Course Role** panel has a
  Delete action per role (strips every holder, then `remove_role()`).
  Deleting the access role of a published course is not permanent: the next save
  of that course mints it again (`ensure_access_role()`), empty.
- **Granting:** `Roles::grant_access( $user_id, $course_id, $source = 'manual', $source_id = '' )`
  checks `EnrollmentService::can_enroll()` (user and course exist, availability
  window, prerequisites) and then adds the role. **Revoking:**
  `Roles::revoke_access( $user_id, $course_id, $source = 'manual', $source_id = '' )`.
- **The listener:** core `add_user_role` / `set_user_role` / `remove_user_role`
  (plus `deleted_user` and multisite `remove_user_from_blog`). Gaining the access
  role calls `EnrollmentService::enroll()` (bypass checks; a cancelled/expired row
  is reactivated, a completed one is left alone). Losing it consults the loss
  policy (below). The completion role never touches enrolment.
- **Grant records:** user meta `_anchor_course_grants` =
  `[ course_id => [ source, source_id, granted_at ] ]` - why the role is held now.
  A `manual` grant upgrades any other record and is never downgraded.
  `Roles::grant_record()` reads it.
- **`is_enrolled()`** requires all of: an active (`enrolled`/`in_progress`) or
  `completed` row, the access role, and - for active rows only - `expires_at` not
  yet passed. Access to an unfinished course ends at `expires_at`; the daily sweep
  then flips the row to `expired` and removes the role. A `completed` row is never
  expired: completion ends the work, not the access.
- **Primary-role changes:** `WP_User::set_role()` strips every role, including on an
  ordinary profile save. A role loss that arrives with no reason (no grant
  context) is **queued**, not applied. `Roles::reapply_after_set_role()` (on
  `set_user_role`) puts course roles back and drops their queued losses, so a
  profile save or primary-role change never un-enrols anyone, under any policy.
  A raw `remove_role()` with no `set_role()` behind it is resolved on `shutdown`
  by `Roles::resolve_pending_losses()` (public - call it directly in a CLI command
  or test). `revoke_access()`, the expiry sweep, multisite removal and
  `delete_role()` carry a reason and apply at once.

### Loss policy

`anchor_courses_role_loss_policy` - `( string $policy = 'keep', int $user_id, int $course_id, string $role, string $source, string $source_id )`.
Return `keep` (row stays active, progress kept, re-grant resumes), `cancel` or
`expire`. Only an active row is affected; completed and already-closed rows are
never touched. `$source` is what `revoke_access()` was given, `removed_from_site`,
`role_deleted`, or `role` for a raw removal.

## Capabilities (`Support\Capabilities`)

| Key | Capability | Used for |
|---|---|---|
| `manage` | `manage_anchor_courses` | deleting a course role |
| `edit_courses` | `edit_anchor_courses` | the `anchor_course` CPT (every primitive maps to it) |
| `edit_lessons` | `edit_anchor_lessons` | the `anchor_lesson` CPT; also the lesson preview (`edit_post`) |
| `edit_quizzes` | `edit_anchor_quizzes` | the `anchor_quiz` CPT |
| `reports` | `view_anchor_course_reports` | the Learners metabox and other users' profile Courses block |
| `enrollments` | `manage_anchor_enrollments` | Learners tab add/revoke, Manage enrolment actions |
| `credits` | `manage_anchor_credits` | reserved (not yet checked anywhere) |
| `certificates` | `manage_anchor_certificates` | reserved (not yet checked anywhere) |

Granted to the roles named by `anchor_courses_capability_roles` (default
`[ 'administrator' ]`) during migration 1.0.0.

## Content types and settings

| CPT | Public URL | Meta prefix |
|---|---|---|
| `anchor_course` | `/courses/{slug}/` | `_anchor_course_` |
| `anchor_lesson` | `/lessons/{slug}/` (not in REST, search or `wp-sitemap.xml`) | `_anchor_lesson_` |
| `anchor_quiz` | none - renders inside its course | `_anchor_quiz_` |

**Course** (`Admin\CourseEditor::setting()`, defaults): `duration` `''`,
`difficulty` `''`, `instructor` `''`, `ce_credits` `0`, `ce_type` `''`,
`ce_provider_name` `''`, `ce_provider_number` `''`, `ce_expires_days` `0`,
`prerequisites` `[]` (role slugs: `anchor_course_*_completed` / `anchor_event_*`),
`completion_mode` `all_required_items` (`minimum_percentage`, `manual`),
`completion_percentage` `100`, `progression_mode` `sequential` (`free`),
`certificate_enabled` `1`, `certificate_template` `default`, `expiration_days` `0`
(enrolment window, 0 = never), `available_from` / `available_until` (`Y-m-d`, UTC).

**Curriculum:** `_anchor_course_curriculum` - ordered modules
`{ id (uuid v4), title, description, items: [ { type: lesson|quiz, id, required } ] }`.
Modules are not posts. `Content\Curriculum` reads are memoised per request and
invalidated on any write to that meta key. **`required` lives HERE, and only
here** - `Curriculum::required_items()` is the one place course completion
reads it (audit finding d, 2026-09-25). The curriculum builder's per-item
"Required" checkbox (`admin-curriculum.js`) is the only control that sets it;
new items default to required.

**Lesson** (`Admin\LessonEditor::setting()`): `completion_mode` `manual`
(`view`, `quiz_pass`), `quiz_id` `0`, `type` `content`
(`live_session`), `event_id` `0`, `session_index` `0`, `require_prior_items` `0`.
The three live-session fields are **not wired yet** (plan Phase 5 - the
live-session adapter and the stream prerequisite veto): the lesson editor shows
them disabled under "Not active until the live-session adapter ships (plan
Phase 5)", and hidden mirrors keep the stored values across a save. The
editor used to also carry its own "Required for course completion" checkbox;
it was removed (audit finding d) because nothing ever read it - a lesson's
requiredness is the curriculum item's, not the lesson post's. `required` is
no longer written by `save()` or listed in `defaults()`, though a pre-fix
row's stray `_anchor_lesson_required` meta is harmless if read directly.

**Quiz** (`_anchor_quiz_settings`, `Admin\QuizEditor::settings()`): `passing_score`
`80` (1-100), `max_attempts` `0` (unlimited, max 1000), `time_limit_seconds` `0`
(untimed, max 86400), `shuffle_questions` `0`, `shuffle_answers` `0`,
`show_correct_answers` `1`, `show_score` `1`, `allow_review` `1`,
`retry_delay_seconds` `0`, `on_timer_expiry` `auto_submit` (`expire`).
Questions: `_anchor_quiz_questions`, types `single_choice`, `multiple_choice`,
`true_false`. The time limit and expiry policy are pinned into each attempt at start.
Same finding-d removal as the lesson editor: the quiz settings box's own
"Required for course completion" checkbox is gone; `required` is no longer
part of these settings at all (a pre-fix quiz's stray `required` key inside
the stored `settings` array is harmless if read directly - `coerce()` simply
does not touch it).

### Which course a lesson is read in

A lesson may appear in several courses. `Frontend\Access::course_for_lesson()`
resolves, in order: the `course` query arg (only if that published course lists
the lesson) > the lowest-id published course the user is enrolled in > the
lowest-id published course. Draft/pending/private courses never own an item.
Every lesson link the templates emit carries `?course={id}`
(`Access::lesson_url()`). `Curriculum::course_for_item()` (lowest-id published
course, no user) is used only where there is no learner context.

### Who sees a lesson body

`Frontend\ContentGuard` filters `the_content`, `the_excerpt` and
`get_the_excerpt` for `anchor_lesson`. `Access::lesson_denial()` allows anyone who
can `edit_post` the lesson (preview), otherwise defers to
`ProgressService::is_item_available()`. A refusal renders "You are not enrolled in
this course." plus the access CTA, or "Finish the earlier lessons to unlock this
one." for an enrolled learner locked by progression.

## Lifecycle rules worth knowing

- **Completion pipeline** (`CompletionService::complete()`): requires
  `is_enrolled()`, takes the completion lock (below), re-reads the row, then
  an atomic `status <> 'completed'` UPDATE, then credit award, certificate
  issue (+ credit link), completion role, `anchor_courses_course_completed`.
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
- **The admin "Reset progress" action also bumps the cycle marker** (Round 7,
  PR #32 audit re-review, finding 2): `EnrollmentManager`'s `reset` action
  goes through `ProgressService::reset_course()` -> `EnrollmentService::
  restart()`, never `CompletionService::uncomplete()`. Reopening a row that
  was `completed` now bumps `metadata.completion_cycle` there too, the same
  marker `uncomplete()` bumps. Without it, a row that predates effect
  tracking (or one whose tracked state is otherwise empty) has NEITHER a
  cycle marker NOR an effects map after a reset, so `run_effects()` cannot
  tell the next completion apart from the FIRST one and re-fires
  `anchor_courses_course_completed` for a learner who already completed the
  course once. The cycle counter alone is enough to fix it: once
  `run_effects()` knows this is a re-completion at all, its existing
  "unknown outcome, never re-fired" handling for an empty effects map
  (above) takes over - `hook` reads back `n/a`, not `pending`.
- **Completion effects are tracked and repaired** (audit F02): the enrolment's
  `metadata.completion_effects` = `{ credit, certificate, completion_role, hook }`,
  each `pending` → `done` / `failed` / `n/a` (credit: nothing to award;
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
  reports a database error (mirroring the F03 fix below) - a fresh read of
  the row with the OLD metadata would otherwise look identical to a
  successful save. The failure never blocks the effects themselves (credit,
  certificate, role, hook are separate writes and still run for real); it
  only means their outcome was not durably recorded, logged again from
  `complete()` as `completion_effects_untracked`.
- **CE credit / certificate outcomes:** `CreditService::award()` and
  `CertificateService::issue()` return the row, `null` for not-applicable
  (reason in `last_skip_reason()`: `not_a_course`, `no_user`, `no_credits` /
  `certificates_disabled`), or `WP_Error` (`credit_insert_failed` /
  `certificate_insert_failed`) when the row was due but not written.
- **Quiz submit** is atomic: the attempt is claimed with a conditional
  `in_progress -> submitted` (or `-> expired`) UPDATE before grading, so
  concurrent submits grade once. A learner-initiated submit/answer from somebody
  no longer enrolled is refused (`not_enrolled`).
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
  stale object. Every caller follows through: the REST read route
  (`QuizController::read()`) maps a propagated `read_failed` to 503 rather
  than reporting `in_progress`, and `sweep_expired_attempts()` counts the
  attempt as closed on a propagated `WP_Error` too (the transition it
  performed is real regardless of whether anything could read the row back
  afterwards) rather than silently under-counting because there is no
  `$after->status` left to compare.
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
  `curriculum_quiz_link` warning notice.
- **Attempts belong to a course** (audit F05): a quiz may be shared by several
  courses, and every attempt lifecycle read - `open_attempt()`,
  `count_for_quiz()`, `last_for_quiz()`, `best_for_quiz()` and the service's
  `attempts_used()`, `attempts_remaining()`, `retry_available_at()`,
  `best_attempt()` - takes `( $user_id, $quiz_id, $course_id )`. An open,
  exhausted, delayed, reset or passed attempt in one course never changes
  another course's lifecycle; `start_attempt()` never resumes another course's
  attempt. Cross-course credit, if ever wanted, must be an explicit policy.
- **Best attempt counts:** a completed item is never downgraded by a later failed
  attempt; a repeat pass keeps the original `completed_at`.
- **Admin reset** (`ProgressService::reset_course()`): deletes progress rows,
  voids every counted attempt as `abandoned` (not counted toward
  `max_attempts`, no retry delay), and restarts the row (`enrolled`, no
  `started_at` / `completed_at`).
- **Certificates** freeze `learner_name`, `course_name`, `credits`,
  `provider_name`, `provider_number` and `instructor_name` into their metadata at
  issue; the page renders that snapshot (live values only for older rows).
  Numbers are `AC-{YYYY}-{8-digit id}`; the year and `completion_date` are UTC.

## Actions

| Hook | Arguments | Fires |
|---|---|---|
| `anchor_courses_access_granted` | `$user_id, $course_id, $source, $source_id` | `Roles::grant_access()` added the role |
| `anchor_courses_access_revoked` | `$user_id, $course_id, $source` | `Roles::revoke_access()` removed the role |
| `anchor_courses_enrolled` | `Enrollment $enrollment, $user_id, $course_id` | once, the first time a row is created |
| `anchor_courses_enrollment_status_changed` | `$user_id, $course_id, $from, $to` | any status change (set_status, reactivation, restart, completion, uncompletion) |
| `anchor_courses_course_started` | `$user_id, $course_id, Enrollment $enrollment` | first item opened (again after a reset) |
| `anchor_courses_lesson_started` | `$user_id, $course_id, $lesson_id, Progress $progress` | first view of a lesson |
| `anchor_courses_lesson_completed` | `$user_id, $course_id, $lesson_id, Progress $progress` | once, first completion of a lesson |
| `anchor_courses_quiz_started` | `QuizAttempt $attempt, $user_id, $quiz_id, $course_id` | a new attempt (never a resume) |
| `anchor_courses_quiz_submitted` | `QuizAttempt $attempt, $user_id, $quiz_id, $course_id` | an attempt was graded |
| `anchor_courses_quiz_passed` / `anchor_courses_quiz_failed` | same | after `_submitted`, by outcome |
| `anchor_courses_quiz_expired` | same | a timed attempt closed by the `expire` policy (nothing graded) |
| `anchor_courses_course_completed` | `$user_id, $course_id, Enrollment $enrollment` | once per (user, course) lifetime; fired again on repair only if a consumer threw (`completion_effects.hook = failed`); never re-fires after an uncomplete()/complete() cycle |
| `anchor_courses_course_recompleted` | `$user_id, $course_id, Enrollment $enrollment` | once per uncomplete() -> complete() cycle, in place of `anchor_courses_course_completed` |
| `anchor_courses_ce_credit_awarded` | `$user_id, $course_id, Credit $credit` | a credit record was created |
| `anchor_courses_certificate_issued` | `$user_id, $course_id, Certificate $certificate` | a certificate was created |
| `anchor_courses_curriculum_saved` | `$course_id, array $modules` | `Curriculum::save()` |

## Filters

| Hook | Value, then arguments | Purpose |
|---|---|---|
| `anchor_courses_can_enroll` | `true\|WP_Error, $user_id, $course_id` | veto a grant |
| `anchor_courses_role_loss_policy` | `'keep', $user_id, $course_id, $role, $source, $source_id` | see Loss policy |
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
| `anchor_courses_create_account` | `true, $email` | may the Learners tab create an account |
| `anchor_courses_capability_roles` | `[ 'administrator' ]` | roles granted the capabilities |
| `anchor_courses_parent_menu` | `true` | show the Courses admin menu tree |
| `anchor_courses_now` | `time()` | the clock (tests) |

## Shortcodes

| Shortcode | Attributes | Renders |
|---|---|---|
| `[anchor_courses]` | `limit` (20) | published courses by title, with a CE credits badge |
| `[anchor_course]` | `id` (current post) | course page: meta, progress, curriculum, access CTA |
| `[anchor_course_progress]` | `course_id` (current post) | the signed-in learner's progress bar |
| `[anchor_my_courses]` | - | the learner's enrolments and percent |
| `[anchor_my_credits]` | - | the learner's CE credits and total |
| `[anchor_my_certificates]` | - | the learner's certificates, linking to their verification pages |

Templates (`single-course`, `single-lesson`, `course`, `lesson`, `quiz`,
`dashboard`, `certificate`) are overridable from the theme at
`anchor-courses/{name}.php`.

## REST (`anchor-courses/v1`)

All routes require a signed-in user (`Routes::require_login`, 401 otherwise).

| Method | Route | Args | Success |
|---|---|---|---|
| POST | `/quizzes/{id}/attempts` | `course_id` (required) | 201, attempt + questions |
| GET | `/quiz-attempts/{id}` | `course_id` (optional) | 200, attempt (questions while open); applies the timer first |
| POST | `/quiz-attempts/{id}/answer` | `question_id`, `value` (string or up to 50 strings), `course_id` (optional) | 200 `{saved:true}` |
| POST | `/quiz-attempts/{id}/submit` | `answers` (object, question_id => value), `course_id` (optional) | 200, graded attempt |

Attempt routes check ownership first (`no_attempt` 404, `attempt_not_yours` 403),
then course context (audit F05): the attempt row's `course_id` is authoritative,
and a request naming a different `course_id` is refused 409
`attempt_course_mismatch` (quiz.js always sends the course it rendered).
Service errors map through `Routes::error_response()`:
403 `not_enrolled`, `locked`, `not_in_course`, `course_closed`,
`missing_prerequisite`, `no_attempts_remaining`, `retry_delay`,
`attempt_not_yours`; 404 `no_attempt`, `no_course`, `no_user`; 409
`attempt_closed`, `no_questions`, `attempt_course_mismatch`, `save_conflict`,
`attempt_busy`; 503 `save_failed`, `read_failed` (both retry); 400
`unknown_question` and anything unlisted.
Responses only ever carry `QuizAttempt::for_learner()` (score hidden when
`show_score` is off, grading data only with `show_correct_answers`).

## Other endpoints

| Endpoint | Handler |
|---|---|
| `admin-post.php?action=anchor_courses_complete_lesson` (also nopriv) | `Frontend\Actions` - "Mark complete"; redirects with `anchor_courses_notice` |
| `admin-post.php?action=anchor_courses_add_learner` / `anchor_courses_revoke_access` | `Admin\LearnerReports` (nonce `anchor_courses_learners_{course}`, cap `enrollments`) |
| `admin-post.php?action=anchor_courses_manage_enrollment` | `Admin\EnrollmentManager` - `cancel`, `reset`, `complete`, `uncomplete`, `repair` (re-run pending/failed/untracked completion effects; `repaired` only once all four read `done`/`n/a`, else `repair_incomplete`; `repair_not_completed` when the row isn't complete at all) |
| `admin-post.php?action=anchor_courses_delete_role` | `Admin\CourseEditor` (cap `manage`) |
| `admin-ajax.php?action=anchor_courses_search_items` / `anchor_courses_create_item` | curriculum builder |
| `/certificate/{token}/` | `Frontend\CertificatePage` - public verification page (query var `anchor_certificate`, noindex) |

Admin handlers authorise and redirect through `Admin\Notices::authorisation_error()`
/ `authorise()` and `Admin\Notices::redirect( $code, $target )`, which only sends
codes registered with `Notices::register()` (anything else becomes `error`).

## Database

Five tables, prefix `{$wpdb->prefix}anchor_courses_`: `enrollments`
(UNIQUE user+course), `progress` (UNIQUE user+course+item+type),
`quiz_attempts` (UNIQUE user+course+quiz+attempt_number), `ce_credits` (UNIQUE
user+course), `certificates` (UNIQUE user+course, certificate_number,
verification_token). Version option `anchor_courses_db_version`, run from the
Module constructor and `admin_init`.

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

## Cron

`anchor_courses_expire_sweep` (daily, scheduled on load, cleared on plugin
deactivation): `EnrollmentService::sweep_expired()` flips due rows to `expired`
and removes the access role; `QuizService::sweep_expired_attempts()` re-opens
stale `submitted` claims, then applies the timer policy to timed attempts left
open past their deadline.

## Uninstall

Learner history is kept by default. With
`update_option( 'anchor_courses_delete_data_on_uninstall', 1 )` set first,
uninstall drops the five tables, the version/rewrite options, the eight
capabilities, every `anchor_course_{id}[_completed]` role and the
`_anchor_course_grants` user meta, and clears the cron.
