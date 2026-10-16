# Admin manager tabs on My Account, and a front-end Course Manager

Date: 2026-10-16 · First consumer: TMJ & Sleep Therapy Centre International (tmjtherapycentre.com)

## Intent

Admins at TMJ International run the business from the site's **My Account** page, not wp-admin.
Today that page already gives them the file manager and (below it) the store manager, but events
can only be managed from wp-admin, and courses have no front-end tool at all. The owner wants one
place on My Account where an admin can switch between the tools they use day to day: **Files,
Events, Courses, Stores**, with their own **My Courses** (learner view) still shown underneath.

The Course Manager is new. What admins need from it is narrow: keep each course's **CE details**
and related settings correct, and **see who is enrolled, who has finished, and which CE credits and
certificates were issued**. Changing who is enrolled stays in wp-admin.

## Goals

1. A tabbed **admin manager panel**: Files · Events · Courses · Stores. One tab visible at a time.
2. A front-end **Course Manager** (new) shown in the Courses tab:
   - a list of courses; opening one shows its settings form and its learners;
   - **editable course settings**: CE credits awarded, credit type, provider name, provider number,
     credit expiry (days), certificate on/off, certificate template, available from, available until,
     enrolment expiry (days), instructor;
   - **read-only learners**: name, email, enrolled date, progress, status, completed date,
     **CE credits issued**, **certificate** (number, linked to the certificate page).
3. On TMJ International's My Account page, admins see the panel at the top and **My Courses** below it.
   Everyone else sees exactly what they see today.

## Non-goals

- No learner actions in the front end: no add learner, revoke, reset, mark complete, or repair.
  Those stay in wp-admin (`EnrollmentManager`, `LearnerReports::render_add_learner_form()`).
- No editing of lessons, quizzes, curriculum, prerequisites, progression or completion rules,
  course title or content. Those stay in wp-admin.
- No new "manager" role. The panel is for administrators (decided by the owner; there are no Shop
  Manager accounts on the site, and the 2 Editor accounts are staff logins, not managers).
- No change to what non-admins see on My Account.

## Anti-goals (would be a failure even if it "works")

- **A second implementation of course settings.** The front-end form must save through the same
  sanitizing and meta keys as the wp-admin course editor (`CourseEditor::sanitize_value()`,
  `CoursePostType::meta_key()`, read with `CourseEditor::setting()`). If wp-admin and My Account ever
  show different values for the same course, the design has failed.
- **A second learner query.** The learner list must come from `LearnerReports::rows()`, which already
  returns credits, certificate number and certificate URL in batched queries. Do not write a parallel
  query.
- **Putting AGD PACE beside a credit claim.** TMJ's client requires that "AGD PACE" appear only in
  the course's approval banner, never next to a credit figure. `ce_type` is deliberately empty on
  TMJ courses. The form must never pre-fill or suggest a credit type, and the course manager must not
  print provider name/number next to the credit count in a way that reads as "4 CE Credits · AGD PACE".
- **Exposing learner names and emails to non-admins.** The learner table is personal data.
- **Breaking the existing managers' own navigation.** `[event_manager]` navigates with query args
  (`event_action`, `event_id`) and `[anchor_store_manager]` with redirects carrying
  `anchor_store_notice` / `anchor_store_id` / `anchor_store_error`. After any such navigation or form
  post, the page must come back with the same tab open, not reset to Files.

## Constraints

- **Built in Anchor Tools** (this repo), not in the TMJ theme, so other client sites get it. The
  Course Manager lives in the `anchor-courses` module; the tab panel lives where it can depend on
  modules being active and degrade when one is not.
- Each tab renders the module's **existing** front-end entry point: Files =
  `[anchor_account_portal]` (Anchor Private File Manager, a separate plugin), Events =
  `[event_manager]`, Stores = `[anchor_store_manager]`, Courses = the new course manager. A tab whose
  module or plugin is inactive, or whose capability the viewer lacks, is omitted.
- **Permissions:** each panel keeps its own capability check (events: `Roster::cap()`; stores:
  `Store_Manager` capability filter; courses: `Capabilities::cap('edit_courses')` to save,
  `Capabilities::cap('reports')` to see learners). The panel itself only renders for users who can
  use at least one tab. Saving uses a nonce and the edit-courses capability, checked per course.
- Course saves must pass the same validation as wp-admin, including the existing guards in
  `MetaboxSave::authorized_to_save()` (revision ids, autosave) where they apply.
- Learner table: paginate (rows() already takes limit/offset; wp-admin uses 50 per page).
- Plugin conventions (repo `CLAUDE.md`): enqueue source assets only, never commit `.min.*`; source
  files under 1,000 lines, functions under 150; no `alert()/confirm()/prompt()`. Add tests (PHPUnit,
  and a JS harness in `tests/js/` if there is meaningful JS), since the courses module is tested.
- TMJ International site specifics: My Account is page 8003, content
  `[tmj_login_form] [tmj_logged_in][anchor_account_portal][tmj_my_learning][/tmj_logged_in] [tmj_store_manager]`,
  rendered by the `tmj-international` theme. Its `[tmj_store_manager]` wrapper exists only to hide the
  plugin's "no permission" message from non-managers. Live runs Anchor Tools 3.36.3 and does not
  auto-update.

## Decided (by the owner)

- Admins only.
- Tabs: Files, Events, Courses, Stores. My Courses stays below the panel.
- Course Manager edits CE and related settings (list in Goal 2).
- Course Manager shows enrolled and finished learners, with CE credits issued and certificates.
  View only.

## Decided (my calls; the owner can overrule)

- Tab order: Files · Events · Courses · Stores. Files is the default tab.
- The panel and Course Manager are plugin shortcodes, so any site can place them. On TMJ the page
  content changes to: login form; for logged-in users, the panel (admins) or the file portal
  (everyone else); then My Courses. The store manager moves into the panel, and the separate
  `[tmj_store_manager]` line is removed from the page.
- Settings fields are the CE / certificate / availability / instructor subset of the wp-admin course
  editor. Prerequisites, progression and completion rules are excluded as course-design settings,
  not "CE and stuff".
- The certificate column links to the existing public certificate page (`Certificate::url()`).

## Left to the builder

- Shortcode names, file layout, and whether tab switching is client-side (all panels rendered, one
  shown) or server-side (`?tab=`). Whichever is chosen must satisfy the "same tab after navigation"
  anti-goal above. Rendering only the active panel is preferred if the others are expensive (the file
  portal is heavy).
- Whether the course list is a list-then-detail screen or a picker. Course count is small (single
  digits on TMJ).
- Whether the existing `Anchor_Account_Dashboard` (`includes/class-anchor-account-dashboard.php`,
  `[anchor_account_dashboard]`, which already has an `.anchor-nav` tab strip) should host or share
  the tab shell. It is a customer-facing orders/account dashboard not used on TMJ. Check before
  building a second tab strip, and say which way you went and why.
- Styling. Ship neutral, overridable styles; the TMJ theme skins plugin output in
  `assets/css/components/plugin-skins.css`.
- The TMJ theme/page change is a small follow-up once the plugin release is on Live; it may be in the
  same plan as a final task.
