# Admin Manager Tabs + Front-end Course Manager Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give administrators one tabbed panel (Files · Events · Courses · Stores) for My Account, including a new front-end Course Manager that edits a course's CE/certificate/availability settings and shows its learners read-only.

**Architecture:** The course-settings write loop is extracted from `CourseEditor::save()` into one shared static method that wp-admin and the new front-end `CourseManager` both call. The Course Manager is an `anchor-courses` shortcode that renders through the module's `Templates::render()` and reads learners only via `LearnerReports::rows()`. The tab panel is a core `includes/` class that composes existing shortcodes server-side, chosen by `?tab=` or inferred from each module's own query args.

**Tech Stack:** PHP 7.4+ / WordPress, PHPUnit 9 (`WP_UnitTestCase`, base `Anchor_Courses_TestCase`), plain CSS. No build step; never commit `*.min.*`.

**Spec:** `docs/superpowers/specs/2026-10-16-account-manager-tabs-design.md`

## Global Constraints

- Panel visible only to users with `manage_options`; outputs `''` for everyone else (no notice, no login prompt).
- Each tab still applies its module's own capability: events `\Anchor\Events\Roster::cap()`; stores `apply_filters( 'anchor_store_manager_capability', 'edit_posts' )`; files `shortcode_exists( 'anchor_account_portal' )` and logged-in; courses `Capabilities::cap( 'edit_courses' )`.
- Front-end editable keys, exactly: `ce_credits`, `ce_type`, `ce_provider_name`, `ce_provider_number`, `ce_expires_days`, `certificate_enabled`, `certificate_template`, `available_from`, `available_until`, `expiration_days`, `instructor`. Any other posted key is ignored.
- `ce_type` and `certificate_template` are free-text inputs, never a select, never pre-filled beyond the saved value.
- Read-only displays (learner table, list rows) never print provider name/number or credit type next to a credit figure.
- Saving never calls `wp_update_post()`. Nonce plus `current_user_can( Capabilities::cap( 'edit_courses' ), $course_id )`.
- Learners come only from `LearnerReports::rows( $course_id, 50, $offset )`; total from `EnrollmentRepository::count_for_course()`.
- Tab order and slugs: `files`, `events`, `courses`, `stores`; default `files`.
- Do not edit the event or store managers' redirect code.
- Source files < 1,000 lines, functions < 150. No `alert()/confirm()/prompt()`. Enqueue source assets only.
- Text domain `anchor-schema` (the plugin's existing domain).
- Run PHP tests with `composer test` (needs `WP_TESTS_DIR`/`WP_CORE_DIR` and the WP test lib from `bin/install-wp-tests.sh`; CI runs the same). If the local test lib is unavailable, push and rely on the `tests.yml` CI run, and say so.

## Review Focus

1. **Unchecking "Issue a certificate" from the front end** must turn it off. A posted form without the checkbox would otherwise leave it on. Pinned in Task 2 (`test_unchecking_certificate_turns_it_off`).
2. **A crafted POST with a non-whitelisted key** (`prerequisites`, `completion_mode`) must not change it. Pinned in Task 2 (`test_non_frontend_keys_are_ignored`).
3. **An Editor (has `edit_posts`) or a Shop Manager (has `manage_woocommerce`)** sees no panel at all, though each could use a manager on its own. Pinned in Task 4 (`test_editor_and_shop_manager_see_nothing`).
4. **Returning from an event or store sub-screen** (`?event_action=edit&event_id=5`, `?anchor_store_notice=saved`) with no `tab` arg opens that tab, not Files. Pinned in Task 4 (`test_tab_inferred_from_module_args`).
5. **A course ID in the URL that is not a course, or is trashed** shows the list, not a fatal error or another post's meta. Pinned in Task 2 (`test_bad_course_id_falls_back_to_list`).

---

### Task 1: Extract the shared course-settings writer

**Files:**
- Modify: `anchor-courses/src/Admin/CourseEditor.php` (`save()` around `:332-348`)
- Test: `tests/test-courses-course-editor.php`

**Interfaces:**
- Produces: `CourseEditor::save_settings( int $course_id, array $input ): void`. It writes, for each key of `self::defaults()` present in `$input`, `update_post_meta( $course_id, CoursePostType::meta_key( $key ), self::sanitize_value( $key, $input[ $key ], $course_id ) )`. `$input` is already unslashed. It does no auth (callers authorize).

- [ ] **Step 1: Write the failing tests** in `Test_Courses_Course_Editor`:
  - `test_save_settings_writes_only_present_keys`: `make_course( [ 'instructor' => 'Dr A', 'ce_credits' => '4' ] )`, then `CourseEditor::save_settings( $id, [ 'ce_credits' => '6' ] )`. Assert `CourseEditor::setting( $id, 'ce_credits' )` equals the sanitized `'6'` and `setting( $id, 'instructor' ) === 'Dr A'`.
  - `test_save_settings_sanitizes_like_wp_admin`: for keys `ce_expires_days` with input `'-3'` and `available_from` with input `'<b>2026-01-01</b>'`, assert the stored value `=== CourseEditor::sanitize_value( $key, $raw, $id )`.
- [ ] **Step 2: Run** `composer test -- --filter Test_Courses_Course_Editor`. Expected: the 2 new tests FAIL (undefined method); the existing tests PASS.
- [ ] **Step 3: Implement `save_settings()`**, and make `save()` call it after auth. `save()` keeps its unposted-checkbox rule by passing `$input + [ 'certificate_enabled' => '' ]` when the key is absent, so wp-admin behaviour is byte-identical.
- [ ] **Step 4: Run** `composer test -- --filter 'Test_Courses_Course_Editor|Test_Courses_Metabox_Save'`. Expected: all PASS.
- [ ] **Step 5: Commit** `refactor(courses): one shared course-settings writer for wp-admin and front end`.

---

### Task 2: Course Manager shortcode — course list, settings form, save

**Files:**
- Create: `anchor-courses/src/Frontend/CourseManager.php`
- Create: `anchor-courses/templates/course-manager/list.php`, `anchor-courses/templates/course-manager/edit.php`
- Modify: `anchor-courses/anchor-courses.php` (instantiate `new Frontend\CourseManager()` next to `Frontend\Shortcodes` at `:120`, outside the `is_admin()` block)
- Create: `anchor-courses/assets/course-manager.css` (enqueued only when the shortcode renders)
- Test: `tests/test-courses-course-manager.php`

**Interfaces:**
- Consumes: `CourseEditor::save_settings()` (Task 1); `CourseEditor::setting()`; `Capabilities::cap()`; `Templates::render( string $name, array $vars ): string`.
- Produces (Task 3 and Task 4 rely on these):
  - shortcode `[anchor_course_manager]`
  - `CourseManager::FRONTEND_KEYS` (the 11 keys in Global Constraints)
  - `CourseManager::QUERY_ARGS = [ 'acm_course', 'acm_saved', 'acm_page' ]`
  - `CourseManager::NONCE = 'anchor_course_manager'`
  - POST field `anchor_course_manager[<key>]`, plus hidden `acm_course`
  - `CourseManager::handle_save(): void`, hooked on `template_redirect`
  - `CourseManager::render( $atts = [] ): string`
  - `CourseManager::current_course_id(): int` (0 when the arg is absent, not an `anchor_course`, or trashed)

- [ ] **Step 1: Write the failing tests** (`class Test_Courses_Course_Manager extends Anchor_Courses_TestCase`). Each sets the user with `wp_set_current_user()` and calls `do_shortcode( '[anchor_course_manager]' )` or `handle_save()` with `$_POST` / `$_GET` set. To stop the redirect, use a `wp_redirect` filter that throws, as other tests in this repo do. Find one with `grep -rln "wp_redirect" tests/` and reuse its pattern.
  - `test_shortcode_registered` — `shortcode_exists( 'anchor_course_manager' )`.
  - `test_renders_nothing_for_non_admin` — subscriber and logged-out both give `''`.
  - `test_list_shows_courses_with_status` — one published and one draft course: both titles appear, and `Draft` appears. A trashed course does not appear.
  - `test_edit_form_shows_saved_values_and_no_ce_type_default` — with `ce_credits`=`'4'` and `ce_type` unset, the form contains `value="4"`, and the `ce_type` input has `value=""`.
  - `test_save_updates_frontend_keys` — admin posts `ce_credits=6`, `ce_provider_name=Acme`, valid nonce → `setting()` returns them.
  - `test_unchecking_certificate_turns_it_off` — the course has `certificate_enabled=1`; the post has `anchor_course_manager[certificate_enabled]=0` only → `setting(...)` is falsy.
  - `test_non_frontend_keys_are_ignored` — post `prerequisites` and `completion_mode=manual` → both unchanged.
  - `test_save_rejects_bad_nonce_and_non_admin` — nothing written in either case.
  - `test_bad_course_id_falls_back_to_list` — `$_GET['acm_course']` set to a page ID, then a trashed course ID → the list renders, with no form.
- [ ] **Step 2: Run** `composer test -- --filter Test_Courses_Course_Manager`. Expected: FAIL (shortcode missing).
- [ ] **Step 3: Implement `CourseManager`.**
  - `render()`: return `''` unless the user has `edit_courses`. When `current_course_id()` is 0, render `course-manager/list`. Otherwise render `course-manager/edit` with `$course`, `$values`, and `$saved` (from `acm_saved`).
  - List query: `post_type=anchor_course`, `post_status=[publish,draft,private,pending]`, `orderby=date DESC`, `posts_per_page=-1`. Each row links to `add_query_arg( 'acm_course', $id )`.
  - `handle_save()`: act only on POST with `acm_course`. Verify the nonce and `current_user_can( cap('edit_courses'), $id )`. Run `array_intersect_key( wp_unslash( $_POST['anchor_course_manager'] ), array_flip( FRONTEND_KEYS ) )`. Call `save_settings()`, then `wp_safe_redirect( add_query_arg( [ 'acm_course' => $id, 'acm_saved' => 1 ], wp_get_referer() ?: home_url() ) )` and exit.
  - `edit.php`: three groups, CE credits / Certificate / Availability, mirroring wp-admin labels. `certificate_enabled` is a hidden `0` followed by a checkbox `1`. Include a "Back to courses" link (`remove_query_arg( CourseManager::QUERY_ARGS )`) and a saved notice when `$saved`.
- [ ] **Step 4: Run** the filter again. Expected: all PASS.
- [ ] **Step 5: Commit** `feat(courses): front-end Course Manager — list and CE/certificate/availability settings`.

---

### Task 3: Read-only learners on the course screen

**Files:**
- Create: `anchor-courses/templates/course-manager/learners.php`
- Modify: `anchor-courses/src/Frontend/CourseManager.php` (render learners below the form when the user also has `cap('reports')`)
- Modify: `anchor-courses/assets/course-manager.css`
- Test: `tests/test-courses-course-manager.php`

**Interfaces:**
- Consumes: `LearnerReports::rows( int $course_id, int $limit = 50, int $offset = 0 ): array` (keys: `display_name`, `user_email`, `enrolled_at`, `percent`, `status`, `completed_at`, `credits`, `certificate_number`, `certificate_url`); `EnrollmentRepository::count_for_course()`; `CourseManager::QUERY_ARGS` (`acm_page`).

- [ ] **Step 1: Write the failing tests:**
  - `test_learners_table_lists_enrolled_and_completed` — enrol 2 learners with `EnrollmentService::enroll()` and complete 1 with `CompletionService::complete()`. Assert both emails appear, and the completed row shows its completed date and credit figure.
  - `test_certificate_links_to_certificate_page` — a completed learner with a certificate has an `<a href=` equal to `rows()[0]['certificate_url']`, and link text equal to the certificate number.
  - `test_no_actions_in_learner_table` — the output has no `<form` inside the learners section, and none of the strings `Revoke`, `Reset progress`, `Mark complete`.
  - `test_no_provider_or_type_beside_credits` — the course has `ce_type='AGD PACE'` and `ce_provider_name='Acme'`; the learners section contains neither string.
  - `test_learners_paginate_at_50` — 51 enrolments: page 1 shows 50 rows, `?acm_page=2` shows 1, and a "Showing 51–51 of 51" style label is present.
- [ ] **Step 2: Run** `composer test -- --filter Test_Courses_Course_Manager`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement.** Columns: Learner, Email, Enrolled, Progress (%), Status, Completed, CE credits, Certificate. Empty state: "Nobody is enrolled yet." Prev/next links use `add_query_arg( 'acm_page', n )`. Dates are formatted with `date_i18n( get_option( 'date_format' ) )`.
- [ ] **Step 4: Run** again. Expected: all PASS.
- [ ] **Step 5: Commit** `feat(courses): read-only learners, CE credits and certificates in the Course Manager`.

---

### Task 4: Admin manager panel (tabs)

**Files:**
- Create: `includes/class-anchor-manager-panel.php` (class `Anchor_Manager_Panel`, ends with `new Anchor_Manager_Panel();` like `class-anchor-account-dashboard.php`)
- Modify: `anchor-tools.php` (`require_once` it alongside the other `includes/` at `:59-100`)
- Create: `assets/css/manager-panel.css` (registered, enqueued when the shortcode renders)
- Test: `tests/test-manager-panel.php`

**Interfaces:**
- Consumes: shortcodes `anchor_account_portal`, `event_manager`, `anchor_course_manager` (Task 2), `anchor_store_manager`; `CourseManager::QUERY_ARGS` (Task 2).
- Produces: shortcode `[anchor_manager_panel]`; `Anchor_Manager_Panel::tabs(): array` (slug → label, available to the current user, in order); `Anchor_Manager_Panel::active_tab( array $available ): string`.

Decision recorded here: do **not** reuse `Anchor_Account_Dashboard`. It is a customer orders/account dashboard with its own inline CSS, and it is not placed on TMJ. Sharing it would couple an admin tool to customer markup. The tab strip is about 10 lines of markup, so it is not a second implementation of anything substantial.

- [ ] **Step 1: Write the failing tests** (`class Test_Manager_Panel extends WP_UnitTestCase`). Register stub shortcodes for any of the four tags that `shortcode_exists()` says are missing, so each tab's body is a known marker (e.g. `[[events-body]]`).
  - `test_editor_and_shop_manager_see_nothing` — editor gives `''`; a user with only `manage_woocommerce` + `edit_posts` gives `''`; logged-out gives `''`.
  - `test_admin_sees_tabs_in_order` — the labels appear in the order Files, Events, Courses, Stores, and only the active panel's marker is present.
  - `test_missing_module_tab_is_omitted` — remove the `event_manager` shortcode → no Events tab.
  - `test_tab_inferred_from_module_args` — `$_GET` = `event_action=edit&event_id=5` → Events active; `anchor_store_notice=saved` → Stores; `acm_course=1` → Courses; nothing → Files; `tab=stores&event_action=edit` → Stores (explicit wins); `tab=bogus` → Files.
  - `test_tab_links_drop_other_modules_args` — on `?event_action=edit&event_id=5`, the Stores tab href has `tab=stores` and no `event_action`.
- [ ] **Step 2: Run** `composer test -- --filter Test_Manager_Panel`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - Markup: `<div class="anchor-manager-panel"><nav class="anchor-manager-panel__tabs" role="tablist">` containing `<a role="tab" aria-selected>` links, then `<div class="anchor-manager-panel__body" role="tabpanel">` with `do_shortcode( '[<tag>]' )` for the active tab only.
  - Tab hrefs: `add_query_arg( 'tab', $slug, remove_query_arg( [ 'tab', 'event_action', 'event_id', 'anchor_store_notice', 'anchor_store_id', 'anchor_store_error', ...CourseManager::QUERY_ARGS ] ) )`.
  - CSS: neutral and overridable via `--amp-*` custom properties (accent, border, radius). Tabs scroll horizontally at phone width.
- [ ] **Step 4: Run** again, then the full `composer test`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat: admin manager panel — Files, Events, Courses, Stores tabs`.

---

### Task 5: Verify in a real WordPress, then PR

- [ ] **Step 1:** In `wp-env` (`npm run wp-env start`), put `[anchor_manager_panel]` on a page. As admin, check each tab; save a course's CE fields and confirm wp-admin shows the same values; create and save an event and a store from their tabs, and confirm you land back on the same tab. Note: the Files tab needs the separate Anchor Private File Manager plugin; if it is absent, confirm the tab is omitted. As an editor, confirm the page is empty.
- [ ] **Step 2:** Count files (`git diff --name-only origin/main...HEAD | wc -l`, expect ~12), check CodeRabbit capacity, push `feat/account-manager-tabs`, and open the PR. Mention that the store manager's post-save redirect was checked (spec: report, don't fix, if it lands elsewhere).

---

### Task 6 (after the release is installed on TMJ Live, with owner sign-off): TMJ My Account page

Not part of the plugin PR. TMJ theme repo `/Users/bif/DevKinsta/public/tmjtherapycentreinternational` plus Live page 8003.

- [ ] **Step 1:** Back up page 8003's content to `/tmp/tmjintl-backups/content-8003-before-manager-panel-<date>.html`.
- [ ] **Step 2:** Add theme shortcode `[tmj_account_tools]` in `inc/shortcodes.php`. Admins get `[anchor_manager_panel]`; others get `[anchor_account_portal]`.
- [ ] **Step 3:** Set page content to `[tmj_login_form]` + `[tmj_logged_in][tmj_account_tools][tmj_my_learning][/tmj_logged_in]`. This removes the standalone `[tmj_store_manager]`; leave that function in place, because other pages may use it (grep before deleting).
- [ ] **Step 4:** Verify from the server (site rules 6–7). Check the logged-out page with `MISS` and the login form present. Load a non-admin session (portal + My Courses, no tabs) and an admin session (tabs + My Courses). Purge the Kinsta cache.
