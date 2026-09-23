# Anchor Courses
## WordPress LMS Plugin Implementation Plan

**Author:** Anchor Corps  
**Purpose:** Build a lightweight, maintainable WordPress LMS focused on courses, modules, lessons, quizzes, progress tracking, CE credits, and clean integration hooks.

---

# 1. Product Goal

Build a purpose-built WordPress LMS plugin that covers the actual learning workflow without the unnecessary complexity found in large LMS platforms.

The core experience should be:

1. A user enrolls in a course.
2. The course contains ordered modules.
3. Modules contain ordered lessons.
4. Lessons can contain text, video, downloads, or other standard WordPress content.
5. A lesson may optionally require a quiz.
6. The learner completes lessons in order, subject to course progression rules.
7. Quiz logic determines whether the learner passes, fails, may retry, or must wait.
8. Progress is stored per user.
9. Course completion can award CE credits and optionally generate a certificate.
10. Other systems can hook into LMS events without modifying the LMS core.

The plugin should remain intentionally small, modular, and WordPress-native.

---

# 2. Guiding Principles

## 2.1 Keep the LMS core simple

Do not attempt to recreate LearnDash, Tutor LMS, LifterLMS, or Moodle.

Do not add functionality unless it directly supports:

- course structure
- content delivery
- learner progression
- quizzes
- progress
- completion
- CE credits
- certificates
- integrations

Avoid marketplace features, gamification, social feeds, badges, instructor revenue sharing, SCORM, complex gradebooks, and similar features unless explicitly added later.

## 2.2 WordPress-native where it makes sense

Use WordPress APIs for:

- authentication
- users
- permissions
- course content
- lesson content
- media
- Gutenberg / editor content
- post revisions
- REST API
- cron
- mail
- capability checks
- admin UI

Use dedicated database tables for transactional learner data.

## 2.3 Integrations should listen to events

The LMS should expose actions and filters.

External systems should not need to modify the core plugin.

Examples:

- WooCommerce
- Zoho
- Mailgun
- CRM synchronization
- analytics
- certificate providers
- webinar systems
- custom reporting
- course reminder emails

## 2.4 Data should remain portable

Avoid storing critical learner history only in serialized post meta.

Learner activity should be queryable with normal SQL.

## 2.5 Do not couple presentation to business logic

Course progression, quiz grading, enrollment, and completion should live in services/classes independent of templates.

---

# 3. Reference Projects

Use existing open-source projects only as references, not as architecture that must be preserved.

Recommended references:

### lessonLMS

Use for ideas around:

- WordPress CPT registration
- native WordPress administration
- enrollment patterns
- course templates
- AJAX / WordPress interaction

Do not inherit theme coupling or storage decisions without review.

### MIT Laravel LMS implementations

Use for ideas around:

- progress domain logic
- quiz attempts
- grading
- certificates
- reporting
- test coverage

Translate concepts into WordPress conventions rather than porting framework architecture literally.

---

# 4. Plugin Identity

Suggested plugin name:

**Anchor Courses**

Suggested slug:

```text
anchor-courses
```

Suggested PHP namespace:

```php
AnchorCorps\Courses
```

Suggested database prefix:

```text
{$wpdb->prefix}anchor_courses_
```

Suggested minimum requirements:

```text
WordPress: 6.6+
PHP: 8.1+
MySQL: 8+ / MariaDB equivalent
```

Use modern PHP syntax where supported by the chosen minimum PHP version.

---

# 5. Core Domain Model

The hierarchy should be:

```text
Course
├── Module
│   ├── Lesson
│   ├── Lesson
│   └── Quiz
├── Module
│   ├── Lesson
│   └── Quiz
└── Completion
    ├── CE Credits
    └── Certificate
```

A quiz can either:

1. belong to a lesson, or
2. act as the final item in a module/course.

The implementation should support both without creating separate quiz engines.

---

# 6. WordPress Content Model

## 6.1 Custom Post Types

Create:

```text
anchor_course
anchor_lesson
anchor_quiz
```

Optional future CPT:

```text
anchor_certificate
```

Certificates can initially be generated dynamically and stored as records rather than posts.

## 6.2 Modules

Modules do not need their own CPT initially.

Store the course curriculum as structured course metadata referencing lesson and quiz IDs.

Example conceptual structure:

```json
[
  {
    "id": "module_uuid",
    "title": "Module 1",
    "description": "",
    "items": [
      {
        "type": "lesson",
        "id": 124
      },
      {
        "type": "lesson",
        "id": 131
      },
      {
        "type": "quiz",
        "id": 150
      }
    ]
  }
]
```

Advantages:

- simple hierarchy
- easy drag-and-drop ordering
- no unnecessary posts
- module data remains attached to the course

Each module should receive a stable UUID so reordering modules does not break references.

## 6.3 Course Metadata

Support at minimum:

```text
course_duration
course_difficulty
course_instructor
course_ce_credits
course_access_type
course_prerequisites
course_completion_mode
course_progression_mode
course_certificate_enabled
course_certificate_template
course_expiration_days
course_available_from
course_available_until
```

Possible progression values:

```text
free
sequential
```

Possible completion rules:

```text
all_required_items
minimum_percentage
manual
```

---

# 7. Custom Database Tables

Do not store high-volume learner activity in post meta.

Use custom tables created on plugin activation and upgraded through versioned migrations.

---

## 7.1 Enrollments

```text
wp_anchor_courses_enrollments
```

Columns:

```text
id BIGINT UNSIGNED AUTO_INCREMENT
user_id BIGINT UNSIGNED NOT NULL
course_id BIGINT UNSIGNED NOT NULL
status VARCHAR(30) NOT NULL
enrolled_at DATETIME NOT NULL
started_at DATETIME NULL
completed_at DATETIME NULL
expires_at DATETIME NULL
source VARCHAR(100) NULL
source_id VARCHAR(191) NULL
metadata LONGTEXT NULL
created_at DATETIME NOT NULL
updated_at DATETIME NOT NULL
```

Unique constraint:

```text
(user_id, course_id)
```

Status values:

```text
enrolled
in_progress
completed
expired
cancelled
```

---

## 7.2 Item Progress

```text
wp_anchor_courses_progress
```

Columns:

```text
id BIGINT UNSIGNED AUTO_INCREMENT
user_id BIGINT UNSIGNED NOT NULL
course_id BIGINT UNSIGNED NOT NULL
item_id BIGINT UNSIGNED NOT NULL
item_type VARCHAR(20) NOT NULL
status VARCHAR(30) NOT NULL
progress_percent DECIMAL(5,2) DEFAULT 0
started_at DATETIME NULL
completed_at DATETIME NULL
last_viewed_at DATETIME NULL
time_spent_seconds BIGINT UNSIGNED DEFAULT 0
metadata LONGTEXT NULL
created_at DATETIME NOT NULL
updated_at DATETIME NOT NULL
```

Possible item types:

```text
lesson
quiz
```

Possible status values:

```text
not_started
in_progress
completed
failed
```

Unique constraint:

```text
(user_id, course_id, item_id, item_type)
```

---

## 7.3 Quiz Attempts

```text
wp_anchor_courses_quiz_attempts
```

Columns:

```text
id BIGINT UNSIGNED AUTO_INCREMENT
user_id BIGINT UNSIGNED NOT NULL
course_id BIGINT UNSIGNED NOT NULL
quiz_id BIGINT UNSIGNED NOT NULL
attempt_number INT UNSIGNED NOT NULL
status VARCHAR(30) NOT NULL
score DECIMAL(6,2) NULL
points_earned DECIMAL(8,2) NULL
points_possible DECIMAL(8,2) NULL
passed TINYINT(1) DEFAULT 0
started_at DATETIME NOT NULL
submitted_at DATETIME NULL
duration_seconds INT UNSIGNED NULL
answers LONGTEXT NULL
grading_data LONGTEXT NULL
created_at DATETIME NOT NULL
updated_at DATETIME NOT NULL
```

Possible statuses:

```text
in_progress
submitted
graded
expired
abandoned
```

---

## 7.4 CE Credits

```text
wp_anchor_courses_ce_credits
```

Columns:

```text
id BIGINT UNSIGNED AUTO_INCREMENT
user_id BIGINT UNSIGNED NOT NULL
course_id BIGINT UNSIGNED NOT NULL
credits DECIMAL(6,2) NOT NULL
credit_type VARCHAR(100) NULL
awarded_at DATETIME NOT NULL
expires_at DATETIME NULL
certificate_id BIGINT UNSIGNED NULL
metadata LONGTEXT NULL
created_at DATETIME NOT NULL
```

---

## 7.5 Certificates

```text
wp_anchor_courses_certificates
```

Columns:

```text
id BIGINT UNSIGNED AUTO_INCREMENT
user_id BIGINT UNSIGNED NOT NULL
course_id BIGINT UNSIGNED NOT NULL
certificate_number VARCHAR(100) NOT NULL
issued_at DATETIME NOT NULL
expires_at DATETIME NULL
file_path TEXT NULL
verification_token VARCHAR(191) NULL
metadata LONGTEXT NULL
created_at DATETIME NOT NULL
```

The verification token should support a public certificate verification route later.

---

# 8. Quiz Engine

The quiz engine is the most important business-logic component after progress tracking.

Build it as a standalone service.

Example class:

```php
AnchorCorps\Courses\Services\QuizService
```

---

## 8.1 Quiz Configuration

Each quiz should support:

```text
passing_score
max_attempts
time_limit_seconds
shuffle_questions
shuffle_answers
show_correct_answers
show_score
allow_review
retry_delay_seconds
required
```

`max_attempts = 0` can represent unlimited attempts.

---

## 8.2 Question Types

Phase 1:

```text
single_choice
multiple_choice
true_false
```

Phase 2:

```text
short_answer
number
matching
ordering
```

Avoid essay/manual grading until needed.

---

## 8.3 Question Data

Quiz questions may initially be stored as structured JSON in quiz post meta.

Each question requires a stable UUID.

Example:

```json
{
  "id": "uuid",
  "type": "single_choice",
  "prompt": "Question text",
  "points": 1,
  "answers": [
    {
      "id": "a1",
      "text": "Answer A",
      "correct": false
    },
    {
      "id": "a2",
      "text": "Answer B",
      "correct": true
    }
  ]
}
```

Do not expose `correct` values to frontend REST responses before submission.

---

## 8.4 Quiz Attempt Lifecycle

```text
START
↓
Validate enrollment
↓
Validate progression
↓
Check attempt allowance
↓
Create attempt
↓
Start timer
↓
Learner answers questions
↓
Submit
↓
Server grades attempt
↓
Pass / Fail
↓
Update progress
↓
Evaluate lesson/course completion
↓
Emit hooks
```

Quiz grading must always happen server-side.

Never trust scores calculated in JavaScript.

---

## 8.5 Timer Rules

The server must store:

```text
started_at
time_limit_seconds
```

The frontend timer is only visual.

When submitting, the backend verifies whether:

```text
current_time <= started_at + time_limit
```

If expired:

- mark attempt as expired, or
- auto-submit currently saved answers

Choose behavior per quiz setting.

Default recommendation:

```text
auto-submit saved answers
```

---

# 9. Lesson Completion

A lesson may be completed through one of several completion modes.

Supported Phase 1 modes:

```text
manual
view
quiz_pass
```

Possible future modes:

```text
video_percentage
minimum_time
external_event
```

## 9.1 Manual completion

Learner clicks:

```text
Mark Complete
```

Backend verifies:

- enrollment
- course availability
- progression rules

Then creates/updates progress.

## 9.2 Quiz completion

If a lesson requires a quiz, the lesson cannot complete until the quiz passes.

## 9.3 Video completion

Do not make video tracking a Phase 1 blocker.

Design the system so a video integration can later call:

```php
anchor_courses_complete_lesson(
    $user_id,
    $course_id,
    $lesson_id
);
```

Future video tracking can use Vimeo, YouTube, HTML5 video, Wistia, etc.

---

# 10. Course Progress Calculation

Create a centralized service:

```php
ProgressService
```

Never calculate course completion separately in templates/controllers.

Required methods should conceptually include:

```php
getCourseProgress($userId, $courseId)
getCompletedItems($userId, $courseId)
isItemAvailable($userId, $courseId, $itemId)
completeLesson($userId, $courseId, $lessonId)
recalculateCourse($userId, $courseId)
```

Recommended calculation:

```text
completed required items
------------------------ × 100
total required items
```

Optional items should not reduce completion percentage.

---

# 11. Course Completion

Course completion should be idempotent.

Running completion evaluation multiple times must not:

- award duplicate CE credits
- create duplicate certificates
- send duplicate completion events

Completion flow:

```text
Required items complete?
↓
YES
↓
Set enrollment = completed
↓
Set completed_at
↓
Award CE credits
↓
Generate certificate if enabled
↓
Fire completion hooks
```

---

# 12. CE Credit System

CE credits should be native to the LMS rather than bolted onto certificates.

Each course may define:

```text
credit amount
credit category/type
provider name
provider number
credit expiration
jurisdiction metadata
```

When course completion occurs:

1. validate CE eligibility
2. create CE credit record
3. connect it to certificate if applicable
4. emit CE event

Example:

```php
do_action(
    'anchor_courses_ce_credit_awarded',
    $user_id,
    $course_id,
    $credit_record
);
```

Allow filters before credit creation.

---

# 13. Certificate System

Phase 1 certificate generation can be HTML to PDF.

Certificate template variables should include:

```text
learner_name
course_name
completion_date
ce_credits
certificate_number
instructor_name
provider_name
provider_number
expiration_date
```

Certificate numbers should be unique.

Example:

```text
AC-2026-00000124
```

Support:

```text
download certificate
email certificate
verify certificate
regenerate certificate
```

---

# 14. WordPress REST API

Namespace:

```text
anchor-courses/v1
```

Suggested endpoints:

```text
GET  /courses
GET  /courses/{id}
GET  /courses/{id}/curriculum

GET  /me/courses
GET  /me/courses/{id}/progress

POST /courses/{id}/enroll

POST /lessons/{id}/start
POST /lessons/{id}/complete

POST /quizzes/{id}/attempts
GET  /quiz-attempts/{id}
POST /quiz-attempts/{id}/answer
POST /quiz-attempts/{id}/submit

GET  /me/certificates
GET  /me/credits
```

Admin routes:

```text
GET /admin/courses/{id}/learners
GET /admin/users/{id}/courses
GET /admin/reports/completions
GET /admin/reports/credits
```

All routes require appropriate capability and nonce/authentication checks.

---

# 15. Public PHP API

Provide clean public service functions for integrations.

Examples:

```php
anchor_courses_enroll_user(
    int $user_id,
    int $course_id,
    array $args = []
): Enrollment;

anchor_courses_complete_lesson(
    int $user_id,
    int $course_id,
    int $lesson_id
): Progress;

anchor_courses_get_progress(
    int $user_id,
    int $course_id
): CourseProgress;

anchor_courses_award_ce_credit(
    int $user_id,
    int $course_id,
    float $credits
): Credit;
```

Integrators should not query LMS tables directly unless absolutely necessary.

---

# 16. Hooks and Events

This is a major requirement.

At minimum expose:

```php
do_action('anchor_courses_enrolled', ...);

do_action('anchor_courses_course_started', ...);

do_action('anchor_courses_lesson_started', ...);

do_action('anchor_courses_lesson_completed', ...);

do_action('anchor_courses_quiz_started', ...);

do_action('anchor_courses_quiz_submitted', ...);

do_action('anchor_courses_quiz_passed', ...);

do_action('anchor_courses_quiz_failed', ...);

do_action('anchor_courses_course_completed', ...);

do_action('anchor_courses_ce_credit_awarded', ...);

do_action('anchor_courses_certificate_issued', ...);
```

Useful filters:

```php
apply_filters('anchor_courses_can_enroll', ...);

apply_filters('anchor_courses_can_access_lesson', ...);

apply_filters('anchor_courses_can_start_quiz', ...);

apply_filters('anchor_courses_quiz_result', ...);

apply_filters('anchor_courses_course_completion_status', ...);

apply_filters('anchor_courses_certificate_data', ...);
```

Document every public hook.

---

# 17. Integration Architecture

Do not put WooCommerce, CRM, analytics, or email-vendor-specific code in the LMS core.

Create integrations separately.

Possible structure:

```text
integrations/
├── woocommerce/
├── zoho/
├── analytics/
├── mailgun/
└── webhooks/
```

Or separate plugins:

```text
anchor-courses-woocommerce
anchor-courses-zoho
```

Separate plugins are preferable for substantial integrations.

---

# 18. WooCommerce Integration

If WooCommerce is active:

Product may optionally map to:

```text
one course
multiple courses
```

On qualifying WooCommerce order status:

```text
completed
```

or configurable status:

```php
anchor_courses_enroll_user()
```

On refund/cancellation:

Configurable behavior:

```text
keep enrollment
cancel enrollment
expire enrollment
```

Store order ID as enrollment source metadata.

Example:

```text
source = woocommerce
source_id = order_id
```

---

# 19. Analytics Integration

Emit browser events in addition to PHP hooks.

Recommended dataLayer events:

```text
course_enrolled
course_started
lesson_started
lesson_completed
quiz_started
quiz_completed
quiz_passed
quiz_failed
course_completed
ce_credit_awarded
certificate_generated
```

Example:

```javascript
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
  event: 'lesson_completed',
  course_id: 123,
  lesson_id: 456
});
```

Do not send personally identifiable information to analytics platforms.

---

# 20. Webhooks

Add a generic webhook system after core hooks are stable.

Admin should be able to configure:

```text
Webhook URL
Secret
Events
Enabled/disabled
```

Payload example:

```json
{
  "event": "course.completed",
  "timestamp": "2026-09-23T15:00:00Z",
  "data": {
    "user_id": 123,
    "course_id": 456,
    "completion_id": 789
  }
}
```

Sign requests using HMAC.

---

# 21. Admin Experience

Avoid building a giant custom SPA initially.

Use WordPress admin and React components only where they materially improve usability.

## 21.1 Course Editor

Course editor needs:

```text
Course settings
Curriculum builder
CE settings
Certificate settings
Access settings
```

Curriculum builder should support drag-and-drop:

```text
Module 1
  Lesson A
  Lesson B
  Quiz A

Module 2
  Lesson C
  Quiz B
```

Actions:

```text
Add module
Add existing lesson
Create lesson
Add existing quiz
Create quiz
Rename module
Reorder
Remove item
```

Removing an item from a course should not delete the lesson/quiz post.

## 21.2 Quiz Editor

Needs:

```text
quiz settings
question list
question editor
answer editor
points
correct answers
drag ordering
```

## 21.3 Learner Reporting

Course screen:

```text
Learner
Enrollment date
Progress
Last activity
Quiz score
Status
Completion date
Credits
Certificate
```

User screen:

```text
Courses
Progress
Quiz attempts
CE credits
Certificates
```

---

# 22. Frontend Experience

Plugin should provide default frontend rendering but remain theme-compatible.

## Course Page

Include:

```text
course title
description
instructor
CE credits
progress
curriculum
enroll/start/continue button
```

## Lesson Page

Include:

```text
breadcrumb
lesson content
module navigation
previous lesson
next lesson
mark complete
quiz CTA
course progress
```

## Quiz Page

Include:

```text
quiz title
attempt number
timer
question navigation
submit
result
retry rules
```

## Learner Dashboard

Shortcode/block/page:

```text
My Courses
My CE Credits
My Certificates
```

---

# 23. Blocks / Shortcodes

Phase 1 shortcodes:

```text
[anchor_courses]
[anchor_course id="123"]
[anchor_course_progress]
[anchor_my_courses]
[anchor_my_credits]
[anchor_my_certificates]
```

Prefer blocks later, but shortcodes give immediate compatibility with:

- Divi
- Elementor
- Gutenberg
- custom themes

---

# 24. Access Control

Capabilities should include:

```text
manage_anchor_courses
edit_anchor_courses
edit_anchor_lessons
edit_anchor_quizzes
view_anchor_course_reports
manage_anchor_enrollments
manage_anchor_credits
manage_anchor_certificates
```

Map these to administrators initially.

Support custom instructor roles later.

---

# 25. Security Requirements

All state-changing operations must:

```text
verify authentication
verify nonce where appropriate
verify capability
verify enrollment
sanitize input
escape output
use prepared SQL
```

Quiz answers must never expose correct-answer information prior to grading.

REST API must not leak:

```text
answers
quiz keys
other learners' progress
private course content
certificate internals
```

Use `$wpdb->prepare()` for custom SQL.

---

# 26. Concurrency / Idempotency

Protect against duplicate requests.

Particularly:

```text
course completion
lesson completion
quiz submission
credit awards
certificate generation
enrollment
```

Example:

Two simultaneous `complete lesson` calls must still create one progress state.

Database uniqueness constraints should be used wherever appropriate.

---

# 27. Performance

Do not calculate the entire learner history on every request.

Use normalized progress records.

Course curriculum may be cached.

Potential caches:

```text
course curriculum
course item count
learner course progress
```

Invalidate intelligently after:

```text
course update
lesson completion
quiz completion
enrollment change
```

Avoid loading all attempts or answers unless requested.

---

# 28. Database Migrations

Maintain schema version:

```text
anchor_courses_db_version
```

Migration pattern:

```text
1.0.0
1.1.0
1.2.0
```

Never assume plugin activation is the only point where schema updates occur.

Run migration checks during plugin bootstrap/admin init.

Use `dbDelta()` only where appropriate, with explicit migrations when destructive/schema-transforming changes are required.

---

# 29. Plugin Structure

Suggested architecture:

```text
anchor-courses/
├── anchor-courses.php
├── composer.json
├── package.json
├── readme.txt
├── src/
│   ├── Plugin.php
│   ├── Activation/
│   ├── Admin/
│   ├── Api/
│   ├── Content/
│   │   ├── CoursePostType.php
│   │   ├── LessonPostType.php
│   │   └── QuizPostType.php
│   ├── Database/
│   │   ├── Migrations.php
│   │   ├── EnrollmentRepository.php
│   │   ├── ProgressRepository.php
│   │   ├── QuizAttemptRepository.php
│   │   ├── CreditRepository.php
│   │   └── CertificateRepository.php
│   ├── Domain/
│   ├── Services/
│   │   ├── EnrollmentService.php
│   │   ├── ProgressService.php
│   │   ├── QuizService.php
│   │   ├── CompletionService.php
│   │   ├── CreditService.php
│   │   └── CertificateService.php
│   ├── Rest/
│   ├── Integrations/
│   ├── Frontend/
│   └── Support/
├── templates/
│   ├── course.php
│   ├── lesson.php
│   ├── quiz.php
│   └── dashboard.php
├── assets/
│   ├── js/
│   └── css/
└── tests/
    ├── Unit/
    ├── Integration/
    └── E2E/
```

---

# 30. Coding Standards

Use:

```text
PSR-4 autoloading
WordPress coding standards where WordPress-facing
strict typing where practical
typed properties
DTO/value objects for important domain data
repositories for custom table access
services for business logic
```

Avoid:

```text
global business logic
large procedural files
SQL scattered across templates
direct table writes from controllers
serialized business logic
frontend-only validation
```

---

# 31. Testing Strategy

Testing is required.

## 31.1 Unit Tests

Cover:

```text
quiz grading
attempt limits
timers
progress calculation
completion calculation
CE credit calculation
certificate numbering
access/progression rules
```

## 31.2 Integration Tests

Cover:

```text
enrollment creation
lesson completion persistence
quiz attempt persistence
course completion
duplicate event protection
database migrations
REST permissions
```

## 31.3 E2E Tests

Core learner path:

```text
register/login
enroll
open course
complete lesson
take quiz
fail
retry
pass
complete next lesson
finish course
receive credit
download certificate
```

Also test:

```text
expired timer
no attempts remaining
sequential lesson locking
course expiration
duplicate completion requests
```

---

# 32. Required Phase 1 Features

The initial production release should include only:

### Content

- courses
- modules
- lessons
- quizzes

### Learners

- enrollment
- progress tracking
- sequential/free progression
- lesson completion

### Quizzes

- single choice
- multiple choice
- true/false
- passing percentage
- attempts
- timers
- retry logic
- auto-grading

### Completion

- course completion
- CE credits
- certificate generation

### Administration

- curriculum builder
- quiz builder
- learner progress view
- enrollment management

### Integration Layer

- PHP actions/filters
- REST API
- browser analytics events

---

# 33. Explicitly Out of Scope for Phase 1

Do not implement unless separately approved:

```text
SCORM
xAPI
gamification
badges
points
leaderboards
instructor marketplace
instructor commissions
social network
forums
complex assignments
essay grading
Zoom integration
live classes
subscription billing
native checkout
multi-tenancy
mobile apps
AI tutoring
AI quiz generation
```

The architecture may allow these later, but they must not increase initial complexity.

---

# 34. Implementation Phases

## Phase 0: Repository / Foundation

Deliver:

```text
plugin bootstrap
autoloading
namespaces
coding standards
test environment
CI
database migration framework
```

Acceptance criteria:

- plugin activates without warnings
- migrations execute safely
- test suite runs
- plugin deactivation does not delete learner data

---

## Phase 1: Content Model

Deliver:

```text
course CPT
lesson CPT
quiz CPT
curriculum structure
course editor
lesson editor
basic quiz editor
```

Acceptance criteria:

- admin can create a course
- admin can create modules
- admin can add/reorder lessons and quizzes
- hierarchy persists reliably
- deleting/reordering curriculum items does not corrupt unrelated content

---

## Phase 2: Enrollment + Progress

Deliver:

```text
enrollment table
progress table
enrollment service
progress service
learner dashboard
lesson completion
sequential progression
```

Acceptance criteria:

- user enrolls once
- progress persists
- completion survives logout/login
- sequential courses lock future lessons
- free progression allows open navigation
- progress percentage is accurate

---

## Phase 3: Quiz Engine

Deliver:

```text
quiz attempt table
quiz service
question types
auto grading
attempt rules
timer
results
retry
```

Acceptance criteria:

- quiz cannot expose correct answers before submission
- attempt numbers are accurate
- score is server-calculated
- attempt limit works
- timer works server-side
- retry logic works
- quiz completion updates course progress

---

## Phase 4: Course Completion + CE

Deliver:

```text
completion service
CE credits
certificate generation
learner credits screen
learner certificate screen
```

Acceptance criteria:

- completion occurs once
- duplicate requests do not duplicate credits
- certificate number is unique
- learner can retrieve certificate
- admins can view awarded credits

---

## Phase 5: Integration Layer

Deliver:

```text
public PHP API
actions
filters
REST routes
dataLayer events
documentation
```

Acceptance criteria:

- another plugin can enroll a user without SQL
- another plugin can respond to course completion
- REST routes enforce permissions
- browser events contain no PII

---

## Phase 6: WooCommerce Adapter

Only after core is stable.

Deliver:

```text
product-course mapping
automatic enrollment
order source tracking
refund/cancellation handling
```

Core LMS must remain usable without WooCommerce.

---

# 35. Agent Workflow

The coding agent should work phase-by-phase.

For each phase:

1. inspect current repository state
2. identify affected architecture
3. implement the smallest coherent version
4. add tests
5. run tests
6. run static analysis/linting
7. manually verify WordPress admin/frontend behavior
8. document new public hooks/APIs
9. commit the phase cleanly

Do not implement multiple future phases prematurely.

If a design decision affects database compatibility, public APIs, or plugin extensibility, document the decision before implementing it.

---

# 36. Definition of Done

A feature is not complete unless:

```text
business logic is server-side
permissions are enforced
data persists correctly
tests exist
duplicate requests are safe
public APIs are documented
admin UI is usable
frontend output is escaped
database queries are prepared
```

---

# 37. Critical Architectural Rules

The coding agent should treat these as hard requirements.

## Rule 1

Do not use post meta for learner histories, quiz attempts, CE records, or high-volume progress records.

## Rule 2

Do not make WooCommerce a dependency of the LMS.

## Rule 3

Do not put business logic inside templates.

## Rule 4

Do not calculate authoritative quiz results in JavaScript.

## Rule 5

Do not tightly couple the LMS to Divi, Gutenberg, Elementor, or a specific theme.

## Rule 6

Do not make integrations modify the LMS core.

## Rule 7

Do not issue duplicate CE credits/certificates on repeated completion events.

## Rule 8

Do not expose correct quiz answers through public REST responses.

## Rule 9

Do not delete learner data on normal plugin deactivation.

## Rule 10

Do not overbuild Phase 1.

---

# 38. Suggested First Milestone

The first usable milestone should demonstrate this exact path:

```text
Admin creates Course A
↓
Admin creates Module 1
↓
Admin adds Lesson 1
↓
Admin adds Quiz 1
↓
Admin sets passing score = 80%
↓
Admin sets max attempts = 2
↓
User enrolls
↓
User reads Lesson 1
↓
User marks Lesson 1 complete
↓
User takes Quiz 1
↓
User fails
↓
User retries
↓
User passes
↓
Course reaches 100%
↓
Course marked completed
↓
2 CE credits awarded
↓
Certificate generated
```

If this flow is robust, almost everything else is incremental.

---

# 39. Recommended Build Order Within Code

The preferred internal implementation order is:

```text
Database migrations
↓
Repositories
↓
Domain models / DTOs
↓
Enrollment service
↓
Curriculum service
↓
Progress service
↓
Quiz service
↓
Completion service
↓
CE service
↓
Certificate service
↓
REST controllers
↓
Admin UI
↓
Frontend UI
↓
Integrations
```

This keeps UI development from defining business rules.

---

# 40. Future Extensions

The architecture should make these possible without requiring them now:

```text
video completion %
lesson prerequisites
course prerequisites
drip scheduling
renewal courses
credit expiration
provider-specific CE reporting
public certificate verification
course bundles
organization/company enrollment
group reporting
webhooks
CRM sync
email reminders
WooCommerce Subscriptions
Zoho integration
Vimeo progress events
external webinar completion
API-issued completion
```

---

# 41. Final Instruction to Coding Agent

Build **Anchor Courses** as a small, reliable learning engine rather than a giant LMS platform.

The most important components are:

```text
curriculum hierarchy
enrollment
progress
quiz attempts
course completion
CE credits
integration hooks
```

Treat those systems as the product.

Everything else should be an optional layer around them.

Prefer explicit, testable, boring architecture over clever abstractions.

The end result should be easy for another developer or AI coding agent to understand, extend, debug, and integrate with other WordPress systems.
