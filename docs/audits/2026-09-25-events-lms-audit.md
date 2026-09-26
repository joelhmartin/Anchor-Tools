# Anchor Events and LMS: targeted debugging audit

**Date:** September 25, 2026  
**Repository:** `joelhmartin/Anchor-Tools`  
**Pinned revision:** `d6080e2b26a95e0d16a055cf96e22302788d84ef`  
**Actual module directories:** `anchor-events-manager/` and `anchor-courses/`  
**Changes to repository or live sites:** None.

## Verdict

The foundations are substantial, but the combined events/LMS workflow is not finished, and several implemented paths need correction. This is not a recommendation to rewrite the modules. Prioritize entitlement consistency, persistence-failure handling, and quiz progression before building further integration on top.

The reviewed revision explicitly represents LMS implementation phases 0 through 4 plus the milestone gate. The design assigns live-session rendering and the stream prerequisite veto to Phase 5, and WooCommerce course enrollment to Phase 6. Their absence is deferred implementation, not proof of a regression. However, live-session settings are already exposed in the lesson editor and can imply protection that is not currently wired.

Seven findings are listed below. Four were reproduced with selected source-method bodies running against explicit in-memory dependencies. The other three are source-confirmed scope or concurrency defects with specified regression scenarios, not claims of a live exploit or a completed browser reproduction.

## What was checked

Targeted critical-path inspection covered event seat identity, entitlement grant/revocation, stream access and prerequisite decisions, account resolution and sign-in tokens; course role enrollment, curriculum progression, completion, credit issuance, quiz attempts, REST ownership checks, and quiz autosave. Module bootstraps, the design/implementation plans, and existing CI were reviewed to distinguish present behavior from future work.

This was not an every-line audit of the very large events implementation. The full payment reconciliation engine, every registration/rendering route, all migrations, and every UI were not exhaustively executed. Other Anchor Tools modules were outside scope. CI totals below describe the repository-wide jobs, not a test count attributable solely to these two modules.

### Evidence and execution

| Check | Result |
|---|---|
| GitHub Tests run `36166845760`, pinned revision | Successful PHP 8.1 and PHP 8.2 jobs observed |
| PHP 8.2 job log, main repository suite | 2,226 tests; 10,421 assertions |
| AJAX suite | 17 tests; 87 assertions |
| WordPress-free unit suite | 42 tests; 94 assertions |
| Local copied-method probes | Four targeted failure behaviors reproduced on PHP 8.4.23 |
| Existing E2E run `36166845885` | Still in progress at last inspection; not counted as passed |
| Fresh full WordPress/WooCommerce/browser run in this audit | Not performed |

The local probe harness is **not a plugin**, does not connect to a site, and does not use a real WordPress database. Its selected executable method bodies preserve the audited control flow, with comments/formatting reduced and surrounding classes replaced by explicit test doubles. It establishes the demonstrated control-flow failures, not full-platform behavior or concurrent MySQL execution.

Run the supplied evidence harness with:

```bash
php regression-probes.php
```

An exit status of zero means all targeted **failure behaviors** reproduced. It does not mean the production code passed a correctness test. `probe-results.json` records the actual output.

## Integration readiness

| Workflow | State at the audited revision |
|---|---|
| Confirmed event registration grants `anchor_event_{id}` | Implemented |
| Event stream access checks role, seat/tier, session and configuration | Implemented |
| Course enrollment follows `anchor_course_{id}` | Implemented, with a supporting enrollment row |
| Course completion grants `anchor_course_{id}_completed` | Implemented |
| Events/courses use these roles as prerequisites | Implemented |
| Live-session lesson schedule and join-link adapter | Deferred to Phase 5; not wired in the course bootstrap |
| Lesson `require_prior_items` veto on event-room access | Deferred to Phase 5; settings exposed before implementation |
| WooCommerce product-to-course enrollment/refund adapter | Deferred to Phase 6; not wired in the course bootstrap |
| Attendance automatically completes a lesson or enrolls a course | Explicitly out of scope in the current design |

**Important distinction:** an event role represents registration/access entitlement, not verified attendance. A course completion role is a different credential. Do not silently treat those as equivalent.

**Immediate UI recommendation:** hide or clearly mark deferred live-session controls, particularly "Block stream access until earlier items are complete," until the adapter is active and tested. Do not advertise the combined purchase-to-course workflow as complete yet.

Sources: [course design](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/docs/superpowers/specs/2026-09-23-anchor-courses-design.md), [course bootstrap](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/anchor-courses.php), [lesson editor](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Admin/LessonEditor.php).

## Findings

### F01. Event refunds can leave an orphaned prerequisite role

**Priority:** P1, entitlement integrity.  
**Evidence:** Source inspection and isolated lifecycle/identity probe.

`Entitlements::has_confirmed_seat()` calls `Registrations::identity_meta_query()` with its default `include_customer=true`. That counts seats bought by a customer, including seats assigned to somebody else. By contrast, stream tier resolution correctly uses the owner-only identity form.

**Reproduction:** Buyer A purchases seats for A and colleague B. Both receive their own event role. Refund A's seat first. The entitlement check counts B's still-confirmed seat through its buyer/customer ID, so A keeps the role. Refund B's seat next. That callback resolves B and revokes B's role, but never revisits A. No confirmed seats remain, yet A retains the event role.

The local probe reproduced exactly that final state. This can leave a stale role eligible for an LMS prerequisite. It does **not** by itself demonstrate continued stream access, because the stream gate also checks the attendee's confirmed seat.

**Fix:** use attendee ownership, not purchaser identity, when deciding whether an attendee retains a seat-derived role. Add a reconciliation path for existing orphaned seat grants, preserving manual grants and legitimate multiple-seat entitlements. If buyer-level entitlement is intentional, every relevant seat change must reconcile both the attendee and affected buyer; the current mixture does neither consistently.

**Regression acceptance:** test both refund orders, a buyer who is not attending, multiple seats for the same attendee, and manual grants. After the last relevant confirmed seat disappears, only genuinely manual entitlement may remain.

Sources: [Entitlements](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-events-manager/class-entitlements.php), particularly `on_seat_status_changed`, `maybe_revoke_seat_grant`, `has_confirmed_seat`, and `seat_tier_modality`; [Registrations](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-events-manager/class-registrations.php), `identity_meta_query`.

### F02. Completion can finish without its required effects, and normal retries do not repair it

**Priority:** P1, completion/credential integrity.  
**Evidence:** Source inspection and isolated failure-injection probe.

`CompletionService::complete()` commits the enrollment's completed status before awarding CE, issuing the certificate, granting the completion role, and emitting the completion hook. Failed CE or certificate issuance is not treated as a pipeline failure. A later completion call immediately returns because the row is already completed.

**Reproduction:** configure a course with positive CE credit and inject issuance failure after the completion status update. The first call returns true and grants the completion role. Restore healthy issuance and retry: the call returns false without attempting either issuance again. The local probe observed one credit call and one certificate call across both requests.

The problem is failure recovery, not the normal absence of credits on a zero-credit course. Direct repair through separate service calls may be possible; the ordinary completion path does not recover itself.

**Fix:** retain the atomic completion transition, but track and reconcile each required effect independently. Give each effect an idempotency key and observable pending/failed/succeeded state. A retry should repair missing effects without re-awarding existing credits or firing duplicate external notifications. Distinguish "not applicable" from "failed" in issuance results. Do not solve this by merely moving all side effects before the completion lock.

**Regression acceptance:** independently fail the credit insert, certificate insert, credit/certificate link, completion-role grant, and a completion-hook consumer; retry safely and verify complete, nonduplicated outcomes.

Sources: [CompletionService](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Services/CompletionService.php), [CreditService](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Services/CreditService.php).

### F03. Failed quiz grading writes can look successful and advance progress

**Priority:** P1, persistence and assessment integrity.  
**Evidence:** Source inspection and isolated repository failure-injection probe.

`QuizAttemptRepository::update()` ignores the return from `$wpdb->update()` and returns a fresh read of the existing attempt. `QuizService::submit()` first claims the attempt as `submitted`, then tries to persist its grade. Its recovery branch only runs when the repository result is not a `QuizAttempt`.

If the grading write fails but the old row remains readable, the repository returns a valid object still in `submitted` state. The intended recovery branch is skipped. The service can then record quiz progress using the computed `$passed` flag, emit pass/fail hooks, and recalculate course completion despite the grade not being durably saved.

The local probe reproduced the failed update returning a valid, still-submitted object. The downstream progress consequence is established by inspection of `submit()`.

**Fix:** explicitly distinguish a database error (`false`) from a successful no-change update (`0`), verify the expected persisted state, and propagate failure. Only advance progress and emit outcome hooks from a successfully saved grade. Add bounded recovery for stale `submitted` claims, including process crashes, not just returned errors.

**Regression acceptance:** fail only the grading UPDATE after the claim succeeds. Expect an error/retryable state, no quiz-pass hook, no completed quiz progress, and no dependent completion effects. A healthy retry must grade once.

Sources: [QuizAttemptRepository](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Database/QuizAttemptRepository.php), `update` and `transition`; [QuizService](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Services/QuizService.php), `submit`.

### F04. A required lesson followed by its required quiz can deadlock

**Priority:** P1, learner-blocking configuration.  
**Evidence:** Source inspection and isolated progression probe.

The authoring UI permits a lesson whose completion mode is `quiz_pass`. Sequential progression blocks a later quiz until all earlier required items are complete. With the natural sequence "required lesson, then that lesson's quiz," the learner must pass a locked quiz to complete the lesson that is locking it.

The local probe produced: lesson available = true, quiz available = false, lesson completion error = `quiz_required`. The quiz service uses the same item-availability check when starting an attempt.

**Fix:** define the attached assessment as part of the lesson's completion dependency, and allow it when its parent lesson is available, without bypassing unrelated prior requirements. Alternatively, reject unsupported dependency cycles during curriculum saving with an actionable explanation. Validate that a linked quiz is reachable in the course.

**Regression acceptance:** required lesson -> its quiz -> next lesson must be completable, while a quiz linked to a later locked lesson must remain inaccessible.

Sources: [ProgressService](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Services/ProgressService.php), `is_item_available`, `complete_lesson`, and `quiz_passed_for_lesson`; [QuizService](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Services/QuizService.php); [LessonEditor](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Admin/LessonEditor.php).

### F05. Shared quizzes can resume attempts from the wrong course

**Priority:** P1 when reusing quiz content.  
**Evidence:** Source-confirmed; no full runtime reproduction in this audit.

Curriculum explicitly allows items to be shared between courses, and progress is course-specific. However, `open_attempt()`, attempt counting, retry timing, and best-attempt lookup are keyed to user + quiz, without course. `start_attempt()` returns the existing open attempt without requiring its `course_id` to match the requested course.

**Reproduction:** enroll the same learner in courses A and B containing quiz Q. Start Q in A, then start Q from B. The lookup returns the A attempt. Submission records progress against A, not B. Attempt limits also cross course boundaries. Even if global quiz allowances were a deliberate policy, silently binding B's request to A's progress is still inconsistent.

**Fix:** explicitly scope attempt lifecycle operations to user + course + quiz, including queries, reset behavior, counters, REST payload context, and uniqueness strategy. If cross-course credit is desired, implement it as an explicit policy rather than accidental shared state.

**Regression acceptance:** an open, exhausted, reset, or completed attempt in A must not silently change B's lifecycle. Verify both directions and same-user reuse, not only different users.

Sources: [Curriculum](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Content/Curriculum.php), `courses_for_item`; [QuizService](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Services/QuizService.php), `start_attempt`; [QuizAttemptRepository](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Database/QuizAttemptRepository.php).

### F06. Overlapping answer saves can lose or overwrite saved state

**Priority:** P2, concurrency and recovery.  
**Evidence:** Source-confirmed interleaving; concurrent runtime test still required.

The browser sends an independent request for each answer change, without sequencing or save-error handling. The service reads the complete saved answer map, changes one entry, and writes the whole map back. Two requests can read the same old map and overwrite one another's changes. A save that passes its open-status check before submission can also reach its unconditional write after grading.

A normal final submission includes the full answer map, which mitigates ordinary lost-answer risk. It does not eliminate problems for autosaved/resumed attempts, server-side expiry, or writes racing the final grade.

**Fix:** use revision-aware compare-and-swap or per-answer atomic persistence, including an open-status condition on the write itself. Queue/coalesce browser saves, report failures, and coordinate submit with outstanding saves. Server-side enforcement remains necessary even with a browser queue.

**Regression acceptance:** save two questions concurrently, change one answer twice with responses deliberately reordered, overlap save and submit, and expire an attempt with a failed save. No accepted answer should disappear silently; a graded answer record must not diverge from its grade.

Sources: [quiz.js](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/assets/quiz.js), answer-change handler; [QuizService](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Services/QuizService.php), `save_answer`; [QuizAttemptRepository](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Database/QuizAttemptRepository.php), `update`.

### F07. Unique attempt numbers do not prevent two open attempts

**Priority:** P2, attempt-limit integrity.  
**Evidence:** Source-confirmed interleaving; real MySQL parallel test still required.

The preflight open-attempt and allowance checks happen before creation. The insert selects `MAX(attempt_number) + 1`, protected by a unique user/quiz/attempt-number key. That prevents duplicate numbers, not multiple open attempts or exceeding the allowed count.

**Reproduction interleaving:** requests A and B both pass preflight with no open attempt and one remaining attempt. A inserts attempt 1. B's insert executes after A commits, sees maximum 1, and inserts attempt 2. There is no duplicate key to reject. A disabled Start button in one browser does not serialize two tabs or direct requests.

**Fix:** serialize resume, allowance checking, and creation within the same learner/course/quiz critical section. Recheck all conditions inside it and enforce one open attempt at the database level where practical. Do not rely on number collision as the lock.

**Regression acceptance:** issue simultaneous starts with a one-attempt limit and no preexisting attempt. Both callers should receive the same open attempt or one should receive a controlled conflict. Exactly one counted attempt may exist.

Sources: [QuizService](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Services/QuizService.php), preflight and creation; [QuizAttemptRepository](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Database/QuizAttemptRepository.php), `create`.

## Existing protections worth preserving

The reviewed code has meaningful protections: capability-less event/course roles, manual event grants that are not casually stripped by refunds, confirmed-seat/tier checks for streams, separation of buyer and attendee during account resolution, refusal to mint or accept email sign-in tokens for elevated users, REST attempt ownership checks, server-side grading, and atomic claim/transition operations.

Do not remove these while repairing the identified gaps. In particular, an atomic completion or submission claim is useful; the missing piece is trustworthy persistence and recoverable downstream work.

Several behaviors were deliberately **not** reported as bugs: manual completion of a live-session lesson is the documented design; attendance automation is out of scope; uncompleting a course intentionally preserves earned credits, certificates, and the completion role; and the event state machine does not permit a normal confirmed-to-pending transition.

Sources: [Entitlements](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-events-manager/class-entitlements.php), [QuizController](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Rest/QuizController.php), [CompletionService](https://github.com/joelhmartin/Anchor-Tools/blob/d6080e2b26a95e0d16a055cf96e22302788d84ef/anchor-courses/src/Services/CompletionService.php), and the course design above.

## Systematic regression and release gate

The following are required follow-up tests, not a claim that they were all executed here. Put them in the existing repository test harness and keep their CI results separately identifiable from the broad suite.

| Area | Cases to add or explicitly verify | Required invariant |
|---|---|---|
| Identity and refunds | Buyer=self; buyer!=attendee; two named attendees; both partial-refund orders; full refund; multiple valid seats; manual comp | Only the actual entitlement owner is granted/revoked; no orphaned seat-derived roles |
| Event lifecycle | Cancel/fail/refund; replayed hooks; role switch off during refund and back on; attendee transfer; account email change | Entitlement can be reconciled from durable facts without stripping manual grants |
| Role/prerequisite integration | Event role as course prerequisite; completed-course role as event prerequisite; ordinary primary-role changes; revoked access with retained history | Registration, enrollment, and completion remain distinct; role restoration does not undo intended revocation |
| Course progression | Sequential/nonsequential; lesson before linked quiz; optional items; reused lesson/quiz in two courses; removed curriculum reference | No dependency cycles, incorrect cross-course attribution, or unintended bypass |
| Quiz identity/security | Anonymous; learner A targeting B's attempt; injected score/passed flag; answer-key leakage before grading | Ownership enforced and server outcome authoritative |
| Quiz lifecycle | Fail/pass/retake; exhausted attempts; reset; expired timer; concurrent starts; shared quiz contexts | Attempt state and limits are correctly scoped and atomic |
| Autosave | Parallel different-question saves; reordered same-question saves; failed save; save vs submit; server timer sweep | Acknowledged persisted answers are not silently lost or changed after grading |
| Completion and credentials | Duplicate completion; failure after every pipeline step; retry; stale submitted/completion claims | Earned completion and required effects converge exactly once |
| Phase 5 bridge | Both modules on; events off; missing event/session; prework incomplete/complete; direct room URL; staff path | The room enforces the same prerequisite decision as the lesson UI |
| Phase 6 bridge | Processing/completed; duplicate callbacks; partial/full refund; multiple grants; unmet prerequisite; guest/attendee ownership | Correct course enrollment and revocation, independent of hook order |

### Recommended order

1. Add failing integration tests for F01 through F05, then fix them without unrelated refactors. Hide or label the inactive live-session controls immediately.
2. Add database-level parallel-request tests for F06 and F07. Correct repository error propagation before trusting higher-level outcome tests.
3. Add an operator-visible reconciliation report for seat-derived roles and incomplete completion effects. It should support dry-run diagnosis, idempotent repair, and clear source IDs/reasons.
4. Implement the already-designed Phase 5 and Phase 6 adapters, then gate release on the cross-module matrix and browser tests.

Keep fixes inside the existing modules and their test suites. No persistent MU plugin is needed.

## Remaining verification limits

No live orders, learner accounts, production data, or site configuration were accessed or changed. No fresh full WordPress/WooCommerce runtime was available to execute the complete suite locally. The probe harness uses in-memory dependencies, and the identified concurrency interleavings still need real parallel database/HTTP tests. Existing CI success was observed rather than initiated by this audit. Warnings in the CI log, including test-bootstrap header warnings, were not automatically classified as production defects.

The findings apply to the pinned revision, not to uninspected later commits.
