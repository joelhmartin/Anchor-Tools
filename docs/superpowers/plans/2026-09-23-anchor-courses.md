# Anchor Courses Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `anchor-courses`, a WordPress LMS module inside the Anchor Tools plugin covering courses -> modules -> lessons -> quizzes, enrolment, progress, server-graded quizzes, idempotent course completion, CE credits, HTML certificates, a REST + PHP integration surface, and adapters for the events module and WooCommerce.

**Architecture:** A thin `\Anchor\Courses\Module` bootstrap (registered in `anchor_tools_get_available_modules()`) wires PSR-4 classes under `anchor-courses/src/`. Content lives in three CPTs plus a JSON curriculum on the course; all learner history lives in five custom tables created by a versioned `Migrations` class. Business logic lives in six services behind thin public PHP functions and a REST namespace; every template, shortcode and admin screen calls a service and never touches SQL. **Enrolment is a WordPress role:** each course mints `anchor_course_{id}`, and a `Support\Roles` listener on core `add_user_role` / `set_user_role` / `remove_user_role` turns holding it into a row in the enrollments table - so a purchase, an admin, WP-CLI or any other plugin all enrol through the same one door. Integrations (`Events`, `WooCommerce`, `Analytics`) are guarded listeners that the core never depends on.

**Tech Stack:** PHP 8.1 (`declare(strict_types=1)` in `src/`), WordPress 6.6+, MySQL 8, jQuery + `jquery-ui-sortable` (no React, no bundling), PHPUnit 9 against the WP test library, plain PHPUnit 9 for pure-logic units, Playwright on `@wordpress/env`.

**Spec:** `docs/superpowers/specs/2026-09-23-anchor-courses-design.md` (wins) over `docs/superpowers/specs/2026-09-23-anchor-courses-BRIEF.md` (base). Events contract: `docs/superpowers/specs/2026-09-23-virtual-events-stream-design.md` sections 3, 4 and 7.

---

## Spec deviations

Read this before Task 1. Each entry is something the brief or the design spec assumes that the repository contradicts; the plan is written against the repository.

**D1 - There is no per-module activation hook.** `anchor_tools_bootstrap_modules()` (`anchor-tools.php:340-365`) only `require_once`s the module file and does `new $module['class']()` on `plugins_loaded` priority 25. `register_activation_hook` appears nowhere in plugin code (only as a note in `anchor-locations/README.md:90`). Brief section 7 ("tables created on plugin activation") is unimplementable. **We do:** `Migrations::maybe_migrate()` runs from the `Module` constructor (already inside `plugins_loaded`, after module load) *and* on `admin_init`, per design spec section 1 row 3 and brief section 28.

**D2 - `composer.json` has no `autoload` section and no module uses Composer autoloading.** `composer.json:1-29` defines only `require`, `require-dev`, `scripts`, `config`; `vendor/composer/autoload_psr4.php` lists only vendor packages. Courses is the first PSR-4 consumer. `vendor/autoload.php` is required at `anchor-tools.php:47-50` at file scope - before the priority-25 bootstrap - so `Anchor\Courses\*` is autoloadable from the `Module` constructor. `vendor/` is committed (`.gitignore:22-31` excludes only dev packages), so **Task 1 must run `composer dump-autoload` and commit `vendor/composer/autoload_psr4.php`, `autoload_static.php`, `autoload_classmap.php`.**

**D3 - No capability helper exists to mirror.** `Roster::cap()` (`anchor-events-manager/class-roster.php:46-48`) delegates to `Module::events_capability()`, which returns an *existing* WordPress capability (`manage_woocommerce` / `edit_others_posts`) - it mints nothing. Brief section 24 wants eight *new* capabilities. **We do:** `Support\Capabilities` mints them and adds them to `administrator` inside migration `1.0.0`. Because caps live in the `wp_user_roles` option, they survive module disable; they are removed only by the guarded uninstall branch (Task 5).

**D4 - `Events_Log::info()` does not exist.** The events spec section 4.2 calls it; the real class exposes only `error()` (`class-events-log.php:89`), `order()` (:231), `flag_review()` (:264), `clear_review()` (:298). **Courses calls no `Events_Log` method.** Logging goes through `Support\Log::write()`, a guarded wrapper over `Anchor_Schema_Logger::log( $event, $context )` (`includes/class-anchor-schema-logger.php:9`).

**D5 - `\Anchor\Events\Module::get_sessions()` today returns a different shape than the events spec promises.** `anchor-events-manager/anchor-events-manager.php:11209-11235` returns rows of `{date, start_time, end_time, label}` only - no `start_ts`, `end_ts`, `modality` or `stream_embed`. Those are added by the parallel events plan. **The live-session renderer (Task 36) must read defensively** and fall back to `date`/`start_time` when `start_ts` is absent.

**D6 - Nothing named `Stream_State`, `Entitlements`, `room_url()` or `anchor_events_can_access_stream` exists in the repo yet.** `grep -rn` over `anchor-events-manager/` returns zero hits for all of them. Every courses reference to them is guarded with `class_exists` / `method_exists` / `has_action`, and every affected test skips when the symbol is absent (`markTestSkipped`). Note that courses does **not** listen to `anchor_events_access_granted` / `_revoked` at all: an event grants `anchor_event_{id}`, which is a *prerequisite* role, never an access role, so nothing in courses reacts to it (design spec 7: event-attendance -> course auto-enrolment is out of scope).

**D7 - Brief section 29's plugin tree does not apply.** There is one `composer.json`, one `package.json`, one `readme.txt` at the repo root. `anchor-courses/` gets no `composer.json`, `package.json`, `readme.txt`, or `src/Plugin.php`; the layout is exactly design spec section 2.

**D8 - Brief section 21's React allowance is forbidden here.** `CLAUDE.md:11` ("Source is raw PHP/CSS/JS - no transpilation, bundling, or ES modules; jQuery only") and `CLAUDE.md:96`. All builders are jQuery IIFEs over `jquery-ui-sortable`.

**D9 - `phpunit.xml.dist` would swallow pure unit tests.** `phpunit.xml.dist:27` is `<directory prefix="test-" suffix=".php">./tests</directory>`, which recurses, so `tests/unit/test-*.php` would be collected into the WordPress-booting suite. **We do (Task 4):** add `<exclude>./tests/unit</exclude>` to the existing suite and add a second config `phpunit-unit.xml.dist` with its own WordPress-free bootstrap. `composer test` stays byte-identical (design spec section 6); a new `composer test:unit` script and a CI step run the unit suite.

**D10 - Brief section 10's method names are camelCase; this repo is snake_case.** Every class in `includes/` and every module uses snake_case methods. **We use snake_case** and map the brief's names: `getCourseProgress`->`get_course_progress`, `getCompletedItems`->`get_completed_items`, `isItemAvailable`->`is_item_available`, `completeLesson`->`complete_lesson`, `recalculateCourse`->`recalculate_course`. The *public API functions* (brief section 15) keep their exact published names.

**D11 - "Certificate page in Phase 1" means the release feature set, not implementation Phase 1.** Design spec section 1 row "Certificates" says "Phase 1 = HTML certificate page", but brief section 32 titles the release feature set "Required Phase 1 Features" while brief section 34's *implementation* Phase 4 is where certificates are built. **Decision: the HTML certificate page is built in implementation Phase 4 (Task 30).** PDF ("Phase 4b") is out of scope for this plan.

**D12 - Quiz REST cannot wait for Phase 5.** Brief section 34 puts REST in Phase 5, but Phase 3's acceptance criterion "quiz cannot expose correct answers before submission" requires a live read surface in Phase 3. **Decision:** `Rest\Routes` + `Rest\QuizController` ship in Phase 3 (Task 26); `CoursesController`, `MeController` and `AdminController` ship in Phase 5 (Tasks 33-34).

**D13 - Brief section 7 omits uniqueness constraints that brief section 26 requires.** Only enrollments (7.1) and progress (7.2) carry one. **We add:** `UNIQUE KEY user_quiz_attempt (user_id, quiz_id, attempt_number)` on quiz_attempts; `UNIQUE KEY user_course (user_id, course_id)` on ce_credits; `UNIQUE KEY user_course (user_id, course_id)` and `UNIQUE KEY certificate_number (certificate_number)` on certificates. Consequence: one CE award and one certificate per (user, course) - renewal courses (brief section 40) will need a migration, documented in `COURSES.md`.

**D14 - Certificate numbering needs two writes.** Design spec section 4 requires `AC-{YYYY}-{8-digit AUTO_INCREMENT id}`, but the number column is `NOT NULL UNIQUE`, so the id is unknown at insert time. **We insert with a collision-proof placeholder `PENDING-{uniqid}` then `UPDATE` the row to the real number**, inside the same request, and the service re-reads the row before returning.

**D15 - WordPress's role hooks carry no reason, and enrolment rows need one.** `WP_User::add_role()` fires `do_action( 'add_user_role', $user_id, $role )` and `WP_User::set_role()` fires `do_action( 'set_user_role', $user_id, $role, $old_roles )` - two arguments and three, none of which says *why* the role was added. The enrollments table wants `source` + `source_id` (brief 7.1, 18), so the listener has to learn them from somewhere. **We do:** `Support\Roles` keeps a private static `$context` (`['source' => string, 'source_id' => string]`). `grant_access()` sets it, calls `add_role()`, and clears it in a `finally` - so the listener, which runs *inside* that `add_role()` call, reads the real reason. A role added by anything else (the wp-admin user screen, WP-CLI, another plugin) finds the context empty and the listener falls back to `source = 'role'`, `source_id = ''`. One static, one writer, always cleared: no request-lifetime state, and no way for a stale value to attach itself to the next grant. Tested both ways in Task 20 (`test_a_plain_add_user_role_enrols_with_the_role_source`).

**D16 - The course edit screen has metaboxes, not tabs.** Design spec 3.1 and 4 call the admin surface a "Learners tab", mirroring the events console, which really does have tabs. `anchor_course` is an ordinary post type screen. **We do:** the Learners tab is the `Learners` metabox on the course edit screen (`Admin\LearnerReports`, Task 21), which is where the plan already put learner reporting. Task 21 creates it with the add/revoke controls; Task 31 extends the *same* class with the credits, certificate and best-quiz columns once Phase 4 has something to put in them. There is no second learners screen.

**Unresolved (decided, flagged):** (a) the events module's `Module::instance()` returns `null` when events is disabled (`anchor-events-manager.php:2103-2105` returns `self::$instance`), so every call site null-checks; (b) `bin/e2e-seed.sh:81` pins `DESIRED_MODULES_JSON` to an exact string comparison - Task 32 must edit that literal, not append; (c) the repo root contains stray files `false,`, `true,`, `test`, `test2` - leave them alone.

---

## Global Constraints

Every task's requirements implicitly include this section.

- **Module key** `courses`; **class** `\Anchor\Courses\Module`; **directory** `anchor-courses/`; **namespace root** `Anchor\Courses\` -> `anchor-courses/src/`.
- **Text domain** is `'anchor-schema'` for every translatable string.
- **`declare(strict_types=1);`** is the first statement in every file under `anchor-courses/src/`. **Not** in `anchor-courses/anchor-courses.php` and **not** in `anchor-courses/api.php` (WordPress calls these with loose values).
- **Typed properties and typed signatures everywhere in `src/`.** Constructor property promotion is allowed (PHP 8.1).
- **Paths/URLs** use `ANCHOR_TOOLS_PLUGIN_DIR` / `ANCHOR_TOOLS_PLUGIN_URL . 'anchor-courses/...'`. Never `plugin_dir_url( __FILE__ )`.
- **Options** always pass `false` as the third argument to `update_option()`.
- **All custom SQL uses `$wpdb->prepare()`**, or `$wpdb->insert()/update()/delete()` with explicit format arrays. No interpolated user input, ever. Table names come from `Migrations::table()`.
- **All output escaped at the echo** (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`); all input sanitised on save.
- **Enqueue source assets only** (`admin-curriculum.js`, `admin-quiz.js`, `quiz.js`, `frontend.js`, `frontend.css`, `admin.css`, `certificate.css`). Never create or reference `*.min.*`.
- **JS is a jQuery IIFE**: `(function($){ 'use strict'; ... })(jQuery);`.
- **AJAX/admin-post actions** are prefixed `anchor_courses_`.
- **REST namespace** `anchor-courses/v1`. No route may use `'permission_callback' => '__return_true'`; every route names a real callback.
- **No `correct` key ever reaches a learner-facing REST response or template before that attempt is submitted.**
- **Test files** must be `tests/test-courses-*.php` (WordPress suite) or `tests/unit/test-*.php` (pure suite) or PHPUnit will not collect them.
- **Integration test base class** is `Anchor_Courses_TestCase` (Task 1). Pure unit tests extend `PHPUnit\Framework\TestCase`.
- **Integration run command** (every task):
  ```bash
  export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
  vendor/bin/phpunit --filter <TestClass>
  ```
- **Unit run command:** `vendor/bin/phpunit -c phpunit-unit.xml.dist --filter <TestClass>`
- **Version:** `anchor-tools.php:5` reads `Version: 3.30.1`. The parallel events work ships `3.31.0`. Task 41 bumps the header to **`3.32.0`**.
- **Never delete learner data on deactivation** (brief rule 9). Only the guarded uninstall branch may drop tables.

## File Structure

| File | Responsibility |
|---|---|
| `anchor-courses/anchor-courses.php` | `\Anchor\Courses\Module`: require `api.php`, construct collaborators, register hooks, run migrations. |
| `anchor-courses/api.php` | Root-namespace public functions (brief section 15). Thin wrappers only. |
| `anchor-courses/uninstall-tables.php` | `anchor_courses_drop_tables( \wpdb $wpdb ): array` - WordPress-free, shared by `uninstall.php` and its test. |
| `src/Support/Uuid.php` | `v4()`. |
| `src/Support/Clock.php` | `now()` / `timestamp()`, filterable for tests. |
| `src/Support/Log.php` | Guarded `Anchor_Schema_Logger` wrapper. |
| `src/Support/Capabilities.php` | The eight caps, `sync()`, `cap()`, `current_user_can()`. |
| `src/Support/Roles.php` | The two per-course roles (`anchor_course_{id}` access, `anchor_course_{id}_completed` completion), minting, grant/revoke, the core role listener, prerequisite resolution. |
| `src/Support/Accounts.php` | `ensure_user( string $name, string $email ): int` - find-or-create a learner account without a new-user email. |
| `src/Support/Grading.php` | Pure grading maths (no WordPress). |
| `src/Database/Migrations.php` | Versioned schema, `table()`, `maybe_migrate()`. |
| `src/Database/{Enrollment,Progress,QuizAttempt,Credit,Certificate}Repository.php` | One per table. All SQL lives here. |
| `src/Domain/{Enrollment,Progress,CourseProgress,QuizAttempt,Credit,Certificate}.php` | Value objects. |
| `src/Content/{Course,Lesson,Quiz}PostType.php`, `src/Content/Curriculum.php`, `src/Content/Questions.php` | CPTs, meta keys, curriculum JSON model, question model. |
| `src/Services/{Enrollment,Progress,Quiz,Completion,Credit,Certificate}Service.php` | Business logic + every `do_action`/`apply_filters`. |
| `src/Rest/Routes.php`, `src/Rest/{Quiz,Courses,Me,Admin}Controller.php` | REST surface. |
| `src/Admin/{CourseEditor,LessonEditor,QuizEditor,LearnerReports,EnrollmentManager}.php` | Admin screens. |
| `src/Frontend/{Shortcodes,Templates,Assets,Access}.php` | Shortcodes, template resolution, enqueues, and the one answer to "how does this visitor get access?". |
| `src/Integrations/{Events,WooCommerce,Analytics}.php` | Guarded adapters. |
| `templates/{course,lesson,quiz,dashboard,certificate}.php` | Theme-overridable markup. |
| `assets/*.js`, `assets/*.css` | jQuery IIFEs + stylesheets. |
| `anchor-courses/COURSES.md` | Public hooks, filters, REST routes, PHP API. |

Modified: `anchor-tools.php` (registry, version), `composer.json` (autoload), `vendor/composer/*` (dumped autoloader), `tests/bootstrap.php` (enable modules), `phpunit.xml.dist` (exclude unit dir), `uninstall.php` (guarded branch), `bin/e2e-seed.sh` (fixtures), `.github/workflows/tests.yml` (unit step), `CLAUDE.md` + `ADDING-MODULES.md` (module tables).

---

## Phase 0 - Repository / Foundation

### Task 1: Module scaffold, registry entry, PSR-4 autoload, test base

**Files:**
- Create: `anchor-courses/anchor-courses.php`
- Create: `anchor-courses/api.php`
- Create: `anchor-courses/src/Support/Uuid.php`, `anchor-courses/src/Support/Clock.php`, `anchor-courses/src/Support/Log.php`
- Create: `anchor-courses/COURSES.md`
- Create: `tests/class-anchor-courses-testcase.php`
- Create: `tests/test-courses-bootstrap.php`
- Modify: `composer.json:14` - add an `autoload` block after `require-dev`
- Modify: `anchor-tools.php:310` - add the `courses` registry entry after the `compliance` entry, before the closing `];`
- Modify: `tests/bootstrap.php:66` - add `'courses' => true` to the modules array
- Modify: `tests/bootstrap.php:89` - require the new base case

**Interfaces:**
- Consumes: `anchor_tools_get_available_modules()`, `ANCHOR_TOOLS_PLUGIN_DIR`, `ANCHOR_TOOLS_PLUGIN_URL`.
- Produces:
  - `\Anchor\Courses\Module::instance(): ?Module`
  - `\Anchor\Courses\Module::VERSION = '1.0.0'`, `Module::assets_url(): string`, `Module::dir(): string`
  - `\Anchor\Courses\Support\Uuid::v4(): string`
  - `\Anchor\Courses\Support\Clock::now(): string` (`Y-m-d H:i:s`, UTC), `::timestamp(): int`, `::offset( int $seconds ): string`, `::to_timestamp( ?string $mysql ): int`
  - `\Anchor\Courses\Support\Log::write( string $event, array $context = [] ): void`
  - `Anchor_Courses_TestCase` with `courses()`, `events_active()`, `require_events()`, `make_learner()`

- [ ] **Step 1: Write the failing test**

Create `tests/test-courses-bootstrap.php`:

```php
<?php
/**
 * Anchor Courses - module boot and PSR-4 autoloading.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Module;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Uuid;

/** @group courses */
class Test_Courses_Bootstrap extends Anchor_Courses_TestCase {

	public function test_module_boots() {
		$this->assertInstanceOf(
			Module::class,
			Module::instance(),
			'The courses module did not bootstrap - check tests/bootstrap.php enables "courses".'
		);
	}

	public function test_psr4_autoloading_resolves_support_classes() {
		$this->assertTrue( class_exists( Uuid::class ), 'PSR-4 autoload for Anchor\\Courses\\ is not registered - run composer dump-autoload.' );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			Uuid::v4()
		);
		$this->assertNotSame( Uuid::v4(), Uuid::v4(), 'UUIDs must not repeat.' );
	}

	public function test_clock_is_filterable_so_tests_can_freeze_time() {
		add_filter( 'anchor_courses_now', static fn() => 1767225600 ); // 2026-01-01 00:00:00 UTC
		$this->assertSame( 1767225600, Clock::timestamp() );
		$this->assertSame( '2026-01-01 00:00:00', Clock::now() );
		remove_all_filters( 'anchor_courses_now' );
	}

	public function test_module_is_registered_in_the_plugin_registry() {
		$modules = anchor_tools_get_available_modules();
		$this->assertArrayHasKey( 'courses', $modules );
		$this->assertSame( '\\Anchor\\Courses\\Module', $modules['courses']['class'] );
		$this->assertFileExists( $modules['courses']['path'] );
	}
}
```

Create `tests/class-anchor-courses-testcase.php`:

```php
<?php
/**
 * Shared base test case for the Anchor Courses suite.
 *
 * Both `courses` and `events_manager` are enabled in tests/bootstrap.php so the
 * Phase 5 integration tests can exercise the real events surface when it exists.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Module;

abstract class Anchor_Courses_TestCase extends WP_UnitTestCase {

	/** The courses module singleton, instantiated by the priority-25 bootstrap. */
	protected function courses(): Module {
		$module = Module::instance();
		$this->assertInstanceOf(
			Module::class,
			$module,
			'The courses module did not bootstrap - check that "courses" is enabled in tests/bootstrap.php.'
		);
		return $module;
	}

	/** Whether the events module booted in this run (it is optional for courses). */
	protected function events_active(): bool {
		return class_exists( '\\Anchor\\Events\\Module' )
			&& null !== \Anchor\Events\Module::instance();
	}

	/** Skip unless the events module booted. */
	protected function require_events(): void {
		if ( ! $this->events_active() ) {
			$this->markTestSkipped( 'The events module is not active in this run.' );
		}
	}

	/** A learner account with the default role. */
	protected function make_learner( array $args = [] ): int {
		return (int) self::factory()->user->create( array_merge( [ 'role' => 'subscriber' ], $args ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Bootstrap
```
Expected: FAIL - `Class "Anchor\Courses\Module" not found`.

- [ ] **Step 3: Write minimal implementation**

`composer.json` - insert after the `require-dev` block (`composer.json:14`):

```json
    "autoload": {
        "psr-4": {
            "Anchor\\Courses\\": "anchor-courses/src/"
        }
    },
```

`anchor-tools.php` - insert immediately before the closing `];` of `anchor_tools_get_available_modules()` (after the `compliance` entry that ends at `anchor-tools.php:310`):

```php
            'courses' => [
                'label'       => __( 'Anchor Courses', 'anchor-schema' ),
                'description' => __( 'Courses, lessons, quizzes, progress, CE credits, and certificates.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses/anchor-courses.php',
                'class'       => '\\Anchor\\Courses\\Module',
            ],
```

`anchor-courses/anchor-courses.php`:

```php
<?php
/**
 * Anchor Tools module: Anchor Courses.
 *
 * Bootstrap only. Everything else lives under src/ and is PSR-4 autoloaded
 * (composer.json -> autoload.psr-4 "Anchor\\Courses\\").
 *
 * No declare(strict_types=1) here on purpose: WordPress calls this file's
 * hooks with loose values.
 */

namespace Anchor\Courses;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/api.php';

class Module {

	const VERSION = '1.0.0';

	private static ?Module $instance = null;

	public function __construct() {
		self::$instance = $this;
	}

	public static function instance(): ?Module {
		return self::$instance;
	}

	/** Absolute URL of this module's assets directory. */
	public static function assets_url(): string {
		return ANCHOR_TOOLS_PLUGIN_URL . 'anchor-courses/assets/';
	}

	/** Absolute path of this module's directory. */
	public static function dir(): string {
		return ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses/';
	}
}
```

`anchor-courses/api.php`:

```php
<?php
/**
 * Anchor Courses - public PHP API (brief section 15).
 *
 * Root namespace on purpose: integrators call these without a use statement.
 * Every function is a thin wrapper over a service; none contains logic.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Functions are added by Tasks 14, 17 and 27.
```

`anchor-courses/src/Support/Uuid.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** RFC 4122 version 4 identifiers for curriculum modules and quiz questions. */
final class Uuid {

	public static function v4(): string {
		$bytes    = \random_bytes( 16 );
		$bytes[6] = \chr( ( \ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = \chr( ( \ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return \vsprintf( '%s%s-%s-%s-%s-%s%s%s', \str_split( \bin2hex( $bytes ), 4 ) );
	}
}
```

`anchor-courses/src/Support/Clock.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The single source of "now" for the module.
 *
 * Every stored datetime is UTC in MySQL format, so timers and expiry survive a
 * site timezone change. `anchor_courses_now` lets tests and the E2E suite
 * advance the clock without sleeping.
 */
final class Clock {

	public static function timestamp(): int {
		return (int) \apply_filters( 'anchor_courses_now', \time() );
	}

	public static function now(): string {
		return \gmdate( 'Y-m-d H:i:s', self::timestamp() );
	}

	/** MySQL UTC datetime $seconds in the future (negative = past). */
	public static function offset( int $seconds ): string {
		return \gmdate( 'Y-m-d H:i:s', self::timestamp() + $seconds );
	}

	/** Parse a stored MySQL UTC datetime back to a timestamp; 0 when empty/invalid. */
	public static function to_timestamp( ?string $mysql ): int {
		if ( null === $mysql || '' === $mysql || '0000-00-00 00:00:00' === $mysql ) {
			return 0;
		}
		$ts = \strtotime( $mysql . ' UTC' );
		return false === $ts ? 0 : $ts;
	}
}
```

`anchor-courses/src/Support/Log.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Debug logging.
 *
 * Deliberately NOT \Anchor\Events\Events_Log: that class has no info()/write()
 * method (it exposes error(), order(), flag_review(), clear_review() only), and
 * courses must not depend on the events module for logging.
 */
final class Log {

	public static function write( string $event, array $context = [] ): void {
		if ( \class_exists( 'Anchor_Schema_Logger' ) ) {
			\Anchor_Schema_Logger::log( 'courses.' . $event, $context );
		}
	}
}
```

`anchor-courses/COURSES.md` (skeleton; Task 41 fills it):

```markdown
# Anchor Courses

Module key `courses`, class `\Anchor\Courses\Module`, namespace `Anchor\Courses`
(PSR-4 root `anchor-courses/src/`).

## Public PHP API
_(filled in by Tasks 14, 17, 27)_

## Actions
_(filled in by Tasks 14-29)_

## Filters
_(filled in by Tasks 14-40)_

## Roles
_(filled in by Tasks 19-21)_

## REST routes
_(filled in by Tasks 26, 33, 34)_

## Shortcodes
_(filled in by Tasks 18 and 30)_

## Database tables
_(filled in by Task 2)_
```

`tests/bootstrap.php:66` - replace the modules array with:

```php
			[ 'modules' => [ 'events_manager' => true, 'locations' => true, 'compliance' => true, 'webinars' => true, 'courses' => true ] ],
```

`tests/bootstrap.php:89` - after the existing require, add:

```php
require __DIR__ . '/class-anchor-courses-testcase.php';
```

- [ ] **Step 4: Regenerate and commit the autoloader**

```bash
composer dump-autoload
git status --short vendor/composer
```
Expected: `vendor/composer/autoload_psr4.php`, `autoload_static.php`, `autoload_classmap.php` modified, each now naming `Anchor\Courses\`.

- [ ] **Step 5: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Bootstrap
```
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add anchor-courses composer.json vendor/composer anchor-tools.php tests/bootstrap.php \
        tests/class-anchor-courses-testcase.php tests/test-courses-bootstrap.php
git commit -m "feat(courses): module scaffold, registry entry, PSR-4 autoload, test base"
```

---

### Task 2: Versioned migrations and the five learner tables

**Files:**
- Create: `anchor-courses/src/Database/Migrations.php`
- Modify: `anchor-courses/anchor-courses.php` - constructor: call `Migrations::maybe_migrate()` and hook `admin_init`
- Test: `tests/test-courses-migrations.php`

**Interfaces:**
- Consumes: `Module::instance()`.
- Produces:
  - `Migrations::DB_VERSION = '1.0.0'`, `Migrations::OPTION = 'anchor_courses_db_version'`
  - `Migrations::TABLES = ['enrollments','progress','quiz_attempts','ce_credits','certificates']`
  - `Migrations::table( string $name ): string` -> `{$wpdb->prefix}anchor_courses_{$name}`
  - `Migrations::installed_version(): string`
  - `Migrations::maybe_migrate(): void`
  - `Migrations::run(): void`

- [ ] **Step 1: Write the failing test**

Create `tests/test-courses-migrations.php`:

```php
<?php
/**
 * Anchor Courses - schema install, versioning and idempotency (brief sections 7, 28).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\Migrations;

/** @group courses */
class Test_Courses_Migrations extends Anchor_Courses_TestCase {

	private function columns( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_col( 'DESCRIBE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB
		return array_map( 'strval', (array) $rows );
	}

	private function index_names( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW INDEX FROM ' . Migrations::table( $table ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_unique( array_column( (array) $rows, 'Key_name' ) );
	}

	public function test_all_five_tables_exist_after_bootstrap() {
		global $wpdb;
		foreach ( Migrations::TABLES as $name ) {
			$table = Migrations::table( $name );
			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
				"Missing table {$table}"
			);
		}
	}

	public function test_table_names_use_the_documented_prefix() {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'anchor_courses_enrollments', Migrations::table( 'enrollments' ) );
	}

	public function test_enrollments_columns_match_the_brief() {
		$this->assertSame(
			[ 'id', 'user_id', 'course_id', 'status', 'enrolled_at', 'started_at', 'completed_at',
			  'expires_at', 'source', 'source_id', 'metadata', 'created_at', 'updated_at' ],
			$this->columns( 'enrollments' )
		);
	}

	public function test_progress_columns_match_the_brief() {
		$this->assertSame(
			[ 'id', 'user_id', 'course_id', 'item_id', 'item_type', 'status', 'progress_percent',
			  'started_at', 'completed_at', 'last_viewed_at', 'time_spent_seconds', 'metadata',
			  'created_at', 'updated_at' ],
			$this->columns( 'progress' )
		);
	}

	public function test_quiz_attempts_columns_match_the_brief() {
		$this->assertSame(
			[ 'id', 'user_id', 'course_id', 'quiz_id', 'attempt_number', 'status', 'score',
			  'points_earned', 'points_possible', 'passed', 'started_at', 'submitted_at',
			  'duration_seconds', 'answers', 'grading_data', 'created_at', 'updated_at' ],
			$this->columns( 'quiz_attempts' )
		);
	}

	public function test_ce_credits_and_certificates_columns_match_the_brief() {
		$this->assertSame(
			[ 'id', 'user_id', 'course_id', 'credits', 'credit_type', 'awarded_at', 'expires_at',
			  'certificate_id', 'metadata', 'created_at' ],
			$this->columns( 'ce_credits' )
		);
		$this->assertSame(
			[ 'id', 'user_id', 'course_id', 'certificate_number', 'issued_at', 'expires_at',
			  'file_path', 'verification_token', 'metadata', 'created_at' ],
			$this->columns( 'certificates' )
		);
	}

	/** Brief section 26 - uniqueness is the idempotency mechanism, not application code. */
	public function test_uniqueness_constraints_exist() {
		$this->assertContains( 'user_course', $this->index_names( 'enrollments' ) );
		$this->assertContains( 'user_course_item', $this->index_names( 'progress' ) );
		$this->assertContains( 'user_quiz_attempt', $this->index_names( 'quiz_attempts' ) );
		$this->assertContains( 'user_course', $this->index_names( 'ce_credits' ) );
		$this->assertContains( 'user_course', $this->index_names( 'certificates' ) );
		$this->assertContains( 'certificate_number', $this->index_names( 'certificates' ) );
	}

	public function test_duplicate_enrollment_insert_is_rejected_by_the_database() {
		global $wpdb;
		$row = [
			'user_id' => 7, 'course_id' => 9, 'status' => 'enrolled',
			'enrolled_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-01 00:00:00',
		];
		$this->assertSame( 1, $wpdb->insert( Migrations::table( 'enrollments' ), $row ) );
		$wpdb->suppress_errors( true );
		$this->assertFalse( $wpdb->insert( Migrations::table( 'enrollments' ), $row ) );
		$wpdb->suppress_errors( false );
	}

	public function test_version_option_is_recorded_and_not_autoloaded() {
		global $wpdb;
		$this->assertSame( Migrations::DB_VERSION, Migrations::installed_version() );
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Migrations::OPTION )
		);
		$this->assertContains( (string) $autoload, [ 'no', 'off' ], 'The DB version option must not autoload.' );
	}

	public function test_run_is_idempotent() {
		Migrations::run();
		Migrations::run();
		$this->assertSame( Migrations::DB_VERSION, Migrations::installed_version() );
		$this->assertCount( 13, $this->columns( 'enrollments' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Migrations
```
Expected: FAIL - `Class "Anchor\Courses\Database\Migrations" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Database/Migrations.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Versioned schema for the five learner tables (brief section 7).
 *
 * Anchor Tools has no per-module activation hook - anchor_tools_bootstrap_modules()
 * only requires the file and instantiates the class on plugins_loaded:25 - so this
 * runs from the Module constructor AND on admin_init (brief section 28). dbDelta is
 * whitespace- and case-sensitive: two spaces after PRIMARY KEY, one index per
 * line, uppercase types. Do not reformat the SQL below.
 */
final class Migrations {

	public const DB_VERSION = '1.0.0';
	public const OPTION     = 'anchor_courses_db_version';

	public const TABLES = [ 'enrollments', 'progress', 'quiz_attempts', 'ce_credits', 'certificates' ];

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'anchor_courses_' . $name;
	}

	public static function installed_version(): string {
		return (string) \get_option( self::OPTION, '' );
	}

	public static function maybe_migrate(): void {
		if ( self::installed_version() === self::DB_VERSION ) {
			return;
		}
		self::run();
	}

	public static function run(): void {
		$from = self::installed_version();

		if ( \version_compare( $from, '1.0.0', '<' ) ) {
			self::migrate_1_0_0();
		}

		\update_option( self::OPTION, self::DB_VERSION, false );
	}

	/** 1.0.0 - initial schema. */
	private static function migrate_1_0_0(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		\dbDelta(
			"CREATE TABLE " . self::table( 'enrollments' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				course_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(30) NOT NULL,
				enrolled_at DATETIME NOT NULL,
				started_at DATETIME NULL,
				completed_at DATETIME NULL,
				expires_at DATETIME NULL,
				source VARCHAR(100) NULL,
				source_id VARCHAR(191) NULL,
				metadata LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_course (user_id,course_id),
				KEY course_status (course_id,status),
				KEY user_status (user_id,status)
			) {$charset};"
		);

		\dbDelta(
			"CREATE TABLE " . self::table( 'progress' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				course_id BIGINT UNSIGNED NOT NULL,
				item_id BIGINT UNSIGNED NOT NULL,
				item_type VARCHAR(20) NOT NULL,
				status VARCHAR(30) NOT NULL,
				progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
				started_at DATETIME NULL,
				completed_at DATETIME NULL,
				last_viewed_at DATETIME NULL,
				time_spent_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
				metadata LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_course_item (user_id,course_id,item_id,item_type),
				KEY course_item (course_id,item_id),
				KEY user_course_status (user_id,course_id,status)
			) {$charset};"
		);

		\dbDelta(
			"CREATE TABLE " . self::table( 'quiz_attempts' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				course_id BIGINT UNSIGNED NOT NULL,
				quiz_id BIGINT UNSIGNED NOT NULL,
				attempt_number INT UNSIGNED NOT NULL,
				status VARCHAR(30) NOT NULL,
				score DECIMAL(6,2) NULL,
				points_earned DECIMAL(8,2) NULL,
				points_possible DECIMAL(8,2) NULL,
				passed TINYINT(1) NOT NULL DEFAULT 0,
				started_at DATETIME NOT NULL,
				submitted_at DATETIME NULL,
				duration_seconds INT UNSIGNED NULL,
				answers LONGTEXT NULL,
				grading_data LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_quiz_attempt (user_id,quiz_id,attempt_number),
				KEY quiz_status (quiz_id,status),
				KEY user_course (user_id,course_id)
			) {$charset};"
		);

		\dbDelta(
			"CREATE TABLE " . self::table( 'ce_credits' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				course_id BIGINT UNSIGNED NOT NULL,
				credits DECIMAL(6,2) NOT NULL,
				credit_type VARCHAR(100) NULL,
				awarded_at DATETIME NOT NULL,
				expires_at DATETIME NULL,
				certificate_id BIGINT UNSIGNED NULL,
				metadata LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_course (user_id,course_id),
				KEY course_awarded (course_id,awarded_at)
			) {$charset};"
		);

		\dbDelta(
			"CREATE TABLE " . self::table( 'certificates' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				course_id BIGINT UNSIGNED NOT NULL,
				certificate_number VARCHAR(100) NOT NULL,
				issued_at DATETIME NOT NULL,
				expires_at DATETIME NULL,
				file_path TEXT NULL,
				verification_token VARCHAR(191) NULL,
				metadata LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_course (user_id,course_id),
				UNIQUE KEY certificate_number (certificate_number),
				KEY verification_token (verification_token)
			) {$charset};"
		);

		// Brief section 24: map the eight capabilities to administrator on first
		// install. Added by Task 3; the call site lives here so one migration owns
		// the whole 1.0.0 installation.
		if ( \class_exists( Capabilities::class ) ) {
			Capabilities::sync();
		}
	}
}
```

`anchor-courses/anchor-courses.php` - add the import and extend the constructor:

```php
use Anchor\Courses\Database\Migrations;
```

```php
	public function __construct() {
		self::$instance = $this;

		// No per-module activation hook exists (anchor_tools_bootstrap_modules()
		// only requires + instantiates). Run on load and again on admin_init so a
		// plugin upgrade that never touches wp-admin still converges. Brief section 28.
		Migrations::maybe_migrate();
		\add_action( 'admin_init', [ Migrations::class, 'maybe_migrate' ] );
	}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Migrations
```
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Database/Migrations.php anchor-courses/anchor-courses.php tests/test-courses-migrations.php
git commit -m "feat(courses): versioned migrations for the five learner tables"
```

---

### Task 3: Capabilities mapped to administrator

**Files:**
- Create: `anchor-courses/src/Support/Capabilities.php`
- Test: `tests/test-courses-capabilities.php`

**Interfaces:**
- Consumes: `Migrations::migrate_1_0_0()` calls `Capabilities::sync()` (call site added in Task 2).
- Produces:
  - `Support\Capabilities::CAPS` - `['manage'=>'manage_anchor_courses','edit_courses'=>'edit_anchor_courses','edit_lessons'=>'edit_anchor_lessons','edit_quizzes'=>'edit_anchor_quizzes','reports'=>'view_anchor_course_reports','enrollments'=>'manage_anchor_enrollments','credits'=>'manage_anchor_credits','certificates'=>'manage_anchor_certificates']`
  - `Capabilities::all(): array`
  - `Capabilities::cap( string $key ): string` - unknown key returns `'do_not_allow'`
  - `Capabilities::sync(): void` (idempotent), `Capabilities::remove(): void`
  - `Capabilities::current_user_can( string $key ): bool`
  - Filter `anchor_courses_capability_roles` (default `['administrator']`)

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - capability minting (brief section 24).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Support\Capabilities;

/** @group courses */
class Test_Courses_Capabilities extends Anchor_Courses_TestCase {

	public function test_the_eight_brief_capabilities_are_declared() {
		$this->assertSame(
			[ 'manage_anchor_courses', 'edit_anchor_courses', 'edit_anchor_lessons',
			  'edit_anchor_quizzes', 'view_anchor_course_reports', 'manage_anchor_enrollments',
			  'manage_anchor_credits', 'manage_anchor_certificates' ],
			Capabilities::all()
		);
	}

	public function test_administrator_holds_every_capability_after_migration() {
		$admin = get_role( 'administrator' );
		foreach ( Capabilities::all() as $cap ) {
			$this->assertTrue( $admin->has_cap( $cap ), "administrator is missing {$cap}" );
		}
	}

	public function test_subscriber_holds_none_of_them() {
		$subscriber = get_role( 'subscriber' );
		foreach ( Capabilities::all() as $cap ) {
			$this->assertFalse( $subscriber->has_cap( $cap ), "subscriber should not hold {$cap}" );
		}
	}

	public function test_cap_resolves_keys_and_refuses_unknown_ones() {
		$this->assertSame( 'manage_anchor_courses', Capabilities::cap( 'manage' ) );
		$this->assertSame( 'view_anchor_course_reports', Capabilities::cap( 'reports' ) );
		$this->assertSame( 'do_not_allow', Capabilities::cap( 'nope' ) );
	}

	public function test_current_user_can_follows_the_logged_in_user() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( Capabilities::current_user_can( 'manage' ) );
		wp_set_current_user( $this->make_learner() );
		$this->assertFalse( Capabilities::current_user_can( 'manage' ) );
	}

	public function test_sync_is_idempotent() {
		Capabilities::sync();
		Capabilities::sync();
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_anchor_courses' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Capabilities
```
Expected: FAIL - `Class "Anchor\Courses\Support\Capabilities" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Support/Capabilities.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The eight courses capabilities (brief section 24), mapped to administrator by
 * migration 1.0.0.
 *
 * Unlike \Anchor\Events\Roster::cap(), which returns an EXISTING WordPress
 * capability, these are minted. They live in the wp_user_roles option, so they
 * survive disabling the module; only the guarded uninstall branch removes them.
 */
final class Capabilities {

	public const CAPS = [
		'manage'       => 'manage_anchor_courses',
		'edit_courses' => 'edit_anchor_courses',
		'edit_lessons' => 'edit_anchor_lessons',
		'edit_quizzes' => 'edit_anchor_quizzes',
		'reports'      => 'view_anchor_course_reports',
		'enrollments'  => 'manage_anchor_enrollments',
		'credits'      => 'manage_anchor_credits',
		'certificates' => 'manage_anchor_certificates',
	];

	/** @return string[] */
	public static function all(): array {
		return \array_values( self::CAPS );
	}

	public static function cap( string $key ): string {
		return self::CAPS[ $key ] ?? 'do_not_allow';
	}

	public static function current_user_can( string $key ): bool {
		return \current_user_can( self::cap( $key ) );
	}

	/** Grant every capability to administrator. Idempotent. */
	public static function sync(): void {
		$roles = \apply_filters( 'anchor_courses_capability_roles', [ 'administrator' ] );
		foreach ( (array) $roles as $role_slug ) {
			$role = \get_role( (string) $role_slug );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/** Strip every capability from every role. Uninstall only. */
	public static function remove(): void {
		$wp_roles = \wp_roles();
		foreach ( \array_keys( $wp_roles->roles ) as $role_slug ) {
			$role = \get_role( (string) $role_slug );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Capabilities
```
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Support/Capabilities.php tests/test-courses-capabilities.php
git commit -m "feat(courses): mint the eight LMS capabilities and map them to administrator"
```

---

### Task 4: WordPress-free unit test suite

**Files:**
- Create: `phpunit-unit.xml.dist`
- Create: `tests/unit/bootstrap.php`
- Create: `tests/unit/test-clock-math.php`
- Modify: `phpunit.xml.dist:25-29` - exclude `./tests/unit` from the WordPress suite
- Modify: `composer.json` - add a `test:unit` script (leave `test` byte-identical)
- Modify: `.github/workflows/tests.yml` - add a unit step after "Run PHPUnit"

**Interfaces:**
- Consumes: `Support\Clock` (Task 1).
- Produces: `phpunit-unit.xml.dist`, runnable as `vendor/bin/phpunit -c phpunit-unit.xml.dist`. Later pure classes (`Support\Grading`, `Content\Curriculum::sanitize()`, `Content\Questions::sanitize()/for_learner()`, `Services\ProgressService::percent()`) are tested here.

Pure-unit files get the handful of WordPress functions they touch as no-op shims in `tests/unit/bootstrap.php`, plus `ABSPATH` (every `src/` file guards on it). A class needing anything beyond those shims does not belong in this suite.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/test-clock-math.php`:

```php
<?php
/**
 * Pure unit test: Clock's datetime maths, with no WordPress booted.
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Support\Clock;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Clock extends TestCase {

	public function test_offset_adds_seconds_to_now() {
		$GLOBALS['anchor_courses_unit_now'] = 1767225600; // 2026-01-01 00:00:00 UTC
		$this->assertSame( '2026-01-01 00:00:00', Clock::now() );
		$this->assertSame( '2026-01-01 00:01:00', Clock::offset( 60 ) );
		$this->assertSame( '2025-12-31 23:59:00', Clock::offset( -60 ) );
	}

	public function test_to_timestamp_round_trips_and_treats_empty_as_zero() {
		$this->assertSame( 1767225600, Clock::to_timestamp( '2026-01-01 00:00:00' ) );
		$this->assertSame( 0, Clock::to_timestamp( '' ) );
		$this->assertSame( 0, Clock::to_timestamp( null ) );
		$this->assertSame( 0, Clock::to_timestamp( '0000-00-00 00:00:00' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Clock
```
Expected: FAIL - `Cannot open file "phpunit-unit.xml.dist"`.

- [ ] **Step 3: Write minimal implementation**

`tests/unit/bootstrap.php`:

```php
<?php
/**
 * Bootstrap for the WordPress-free unit suite.
 *
 * Pure-logic classes (Clock, Uuid, Grading, Curriculum::sanitize, Questions)
 * must be testable without booting WordPress. They still guard on ABSPATH and
 * call a handful of WordPress functions, so this file defines exactly those and
 * nothing more - a class that needs anything else does not belong here.
 *
 * @package Anchor\Courses\Tests\Unit
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * No-op filter shim. `anchor_courses_now` is answered from a global so the
	 * unit tests can freeze time without a hook system.
	 *
	 * @param string $hook
	 * @param mixed  $value
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		if ( 'anchor_courses_now' === $hook && isset( $GLOBALS['anchor_courses_unit_now'] ) ) {
			return (int) $GLOBALS['anchor_courses_unit_now'];
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {} // phpcs:ignore
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $str ) {
		return (string) $str;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) {
		return abs( (int) $n );
	}
}
```

`phpunit-unit.xml.dist`:

```xml
<?xml version="1.0"?>
<!--
    PHPUnit 9 configuration for the Anchor Courses PURE unit suite.

    These tests never boot WordPress and never touch MySQL: they cover the
    module's pure logic (grading maths, progress maths, curriculum validation,
    question projection, clock arithmetic). The WordPress-booting integration
    suite lives in phpunit.xml.dist, which EXCLUDES ./tests/unit so the two
    never collide.

    Run: vendor/bin/phpunit -c phpunit-unit.xml.dist
-->
<phpunit
    bootstrap="tests/unit/bootstrap.php"
    backupGlobals="false"
    colors="true"
    convertDeprecationsToExceptions="false"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="false"
    convertWarningsToExceptions="false"
    verbose="true"
>
    <testsuites>
        <testsuite name="anchor-courses-unit">
            <directory prefix="test-" suffix=".php">./tests/unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

`phpunit.xml.dist` - replace the testsuite block (lines 25-29) with:

```xml
    <testsuites>
        <testsuite name="anchor-events">
            <directory prefix="test-" suffix=".php">./tests</directory>
            <!--
                ./tests/unit holds the WordPress-FREE Anchor Courses unit suite
                (phpunit-unit.xml.dist). The directory element above recurses,
                so without this exclude those files would be loaded under the
                WordPress bootstrap and fail on redeclared function shims.
            -->
            <exclude>./tests/unit</exclude>
        </testsuite>
    </testsuites>
```

`composer.json` - add to `scripts` (do NOT touch the existing `test` entry):

```json
        "test:unit": "phpunit -c phpunit-unit.xml.dist"
```

`.github/workflows/tests.yml` - add after the "Run PHPUnit" step:

```yaml
      # WordPress-free unit suite (Anchor Courses pure logic). Runs even when the
      # integration suite fails, so a maths regression is reported in its own right.
      - name: PHPUnit (unit, no WordPress)
        if: always()
        run: composer test:unit
```

- [ ] **Step 4: Run both suites to verify they pass and do not overlap**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Clock
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Bootstrap
```
Expected: PASS (2 tests) and PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add phpunit-unit.xml.dist phpunit.xml.dist tests/unit composer.json .github/workflows/tests.yml
git commit -m "test(courses): WordPress-free unit suite with its own phpunit config"
```

---

### Task 5: Guarded uninstall branch

**Files:**
- Create: `anchor-courses/uninstall-tables.php`
- Modify: `uninstall.php:62-64` - replace the "next module" placeholder comment with the courses branch
- Test: `tests/test-courses-uninstall.php`

**Interfaces:**
- Produces:
  - `anchor_courses_drop_tables( \wpdb $wpdb ): array` - drops the five tables, returns the fully-qualified names dropped. Uses no WordPress functions, so `uninstall.php` may require it.
  - Option gate `anchor_courses_delete_data_on_uninstall` (absent/falsy = keep data).

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - uninstall data policy (brief rule 9, design spec section 4).
 *
 * Deactivation and a default uninstall keep learner data. Only the explicit
 * `anchor_courses_delete_data_on_uninstall` flag drops the tables.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\Migrations;

/** @group courses */
class Test_Courses_Uninstall extends Anchor_Courses_TestCase {

	public function tear_down() {
		// DDL is not transactional, so recreate whatever the test dropped.
		delete_option( Migrations::OPTION );
		Migrations::run();
		parent::tear_down();
	}

	private function table_exists( string $name ): bool {
		global $wpdb;
		$table = Migrations::table( $name );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function test_drop_helper_removes_every_courses_table() {
		global $wpdb;
		require_once ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses/uninstall-tables.php';

		$dropped = anchor_courses_drop_tables( $wpdb );

		$this->assertCount( 5, $dropped );
		foreach ( Migrations::TABLES as $name ) {
			$this->assertFalse( $this->table_exists( $name ), "{$name} should have been dropped" );
		}
	}

	public function test_drop_helper_touches_no_other_table() {
		global $wpdb;
		require_once ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses/uninstall-tables.php';

		anchor_courses_drop_tables( $wpdb );

		$this->assertSame(
			$wpdb->posts,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->posts ) ),
			'The drop helper must only touch anchor_courses_* tables.'
		);
	}

	public function test_uninstall_file_gates_the_drop_behind_the_opt_in_option() {
		$source = file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'uninstall.php' );
		$this->assertStringContainsString( "get_option( 'anchor_courses_delete_data_on_uninstall'", $source );
		$this->assertStringContainsString( 'anchor_courses_drop_tables', $source );
		$this->assertStringNotContainsString(
			'DROP TABLE IF EXISTS {$wpdb->prefix}anchor_courses_',
			$source,
			'The courses drop must go through the guarded helper, never an inline unconditional DROP.'
		);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Uninstall
```
Expected: FAIL - `Failed opening required '.../anchor-courses/uninstall-tables.php'`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/uninstall-tables.php`:

```php
<?php
/**
 * Anchor Courses - table drop helper.
 *
 * Deliberately WordPress-free and deliberately NOT namespaced: uninstall.php
 * runs with no plugin code loaded (see uninstall.php's header), so it can only
 * require a file with zero dependencies. Table names are literals here for the
 * same reason - class constants are unavailable at uninstall time.
 *
 * @package Anchor_Tools
 */

if ( ! function_exists( 'anchor_courses_drop_tables' ) ) {
	/**
	 * Drop the five Anchor Courses learner tables.
	 *
	 * @param wpdb $wpdb WordPress database handle.
	 * @return string[] Fully-qualified names of the tables that were dropped.
	 */
	function anchor_courses_drop_tables( $wpdb ) {
		$names   = [ 'enrollments', 'progress', 'quiz_attempts', 'ce_credits', 'certificates' ];
		$dropped = [];

		foreach ( $names as $name ) {
			$table = $wpdb->prefix . 'anchor_courses_' . $name;
			// Built from the hard-coded allowlist above, never from input.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$dropped[] = $table;
		}

		return $dropped;
	}
}
```

`uninstall.php` - replace the trailing placeholder comment block with:

```php
/*
 * -- Anchor Courses ------------------------------------------------------
 * Learner history (enrolments, progress, quiz attempts, CE credits,
 * certificates) is the site's record of what people earned. It is NOT dropped
 * by default, even on delete - brief rule 9 and design spec section 4. A site
 * that genuinely wants it gone sets the opt-in flag first:
 *
 *   update_option( 'anchor_courses_delete_data_on_uninstall', 1, false );
 *
 * Options and the minted capabilities go with the tables, never before them.
 */
if ( get_option( 'anchor_courses_delete_data_on_uninstall' ) ) {
	require_once __DIR__ . '/anchor-courses/uninstall-tables.php';
	anchor_courses_drop_tables( $wpdb );

	delete_option( 'anchor_courses_db_version' );
	delete_option( 'anchor_courses_delete_data_on_uninstall' );

	// The eight minted capabilities live in wp_user_roles; strip them from
	// every role. Literal names: no plugin classes are loaded here.
	$anchor_courses_caps = array(
		'manage_anchor_courses', 'edit_anchor_courses', 'edit_anchor_lessons',
		'edit_anchor_quizzes', 'view_anchor_course_reports', 'manage_anchor_enrollments',
		'manage_anchor_credits', 'manage_anchor_certificates',
	);
	$anchor_courses_roles = wp_roles();
	foreach ( array_keys( $anchor_courses_roles->roles ) as $anchor_courses_role_slug ) {
		$anchor_courses_role = get_role( $anchor_courses_role_slug );
		if ( ! $anchor_courses_role instanceof WP_Role ) {
			continue;
		}
		foreach ( $anchor_courses_caps as $anchor_courses_cap ) {
			$anchor_courses_role->remove_cap( $anchor_courses_cap );
		}
	}
}

/*
 * -- (next module with persistent artifacts goes here) -------------------
 */
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Uninstall
```
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/uninstall-tables.php uninstall.php tests/test-courses-uninstall.php
git commit -m "feat(courses): guarded uninstall branch that keeps learner data by default"
```

---

## Phase 1 - Content Model

### Task 6: The three custom post types

**Files:**
- Create: `anchor-courses/src/Content/CoursePostType.php`, `LessonPostType.php`, `QuizPostType.php`
- Modify: `anchor-courses/anchor-courses.php` - register on `init`
- Modify: `tests/class-anchor-courses-testcase.php` - add `make_course()`, `make_lesson()`, `make_quiz()`
- Test: `tests/test-courses-cpt.php`

**Interfaces:**
- Produces:
  - `Content\CoursePostType::CPT = 'anchor_course'`, `::META_PREFIX = '_anchor_course_'`, `::META_KEYS` (no access-type key - see below), `::PROGRESSION_MODES = ['free','sequential']`, `::COMPLETION_MODES = ['all_required_items','minimum_percentage','manual']`, `::register(): void`, `::meta_key( string $key ): string`
  - `Content\LessonPostType::CPT = 'anchor_lesson'`, `::META_PREFIX = '_anchor_lesson_'`, `::COMPLETION_MODES = ['manual','view','quiz_pass']`, `::TYPES = ['content','live_session']`, `::register(): void`, `::meta_key()`
  - `Content\QuizPostType::CPT = 'anchor_quiz'`, `::META_PREFIX = '_anchor_quiz_'`, `::register(): void`, `::meta_key()`
  - Test helpers `make_course( array $meta = [], string $title = 'Test Course' ): int`, `make_lesson( array $meta = [], string $title = 'Test Lesson' ): int`, `make_quiz( array $meta = [], string $title = 'Test Quiz' ): int`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - CPT registration (brief section 6.1).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Content\QuizPostType;
use Anchor\Courses\Support\Capabilities;

/** @group courses */
class Test_Courses_Cpt extends Anchor_Courses_TestCase {

	public function test_all_three_post_types_are_registered() {
		$this->assertTrue( post_type_exists( 'anchor_course' ) );
		$this->assertTrue( post_type_exists( 'anchor_lesson' ) );
		$this->assertTrue( post_type_exists( 'anchor_quiz' ) );
	}

	public function test_courses_and_lessons_are_public_and_quizzes_are_private() {
		$this->assertTrue( get_post_type_object( 'anchor_course' )->public );
		$this->assertTrue( get_post_type_object( 'anchor_course' )->has_archive );
		$this->assertTrue( get_post_type_object( 'anchor_lesson' )->public );
		$this->assertFalse( get_post_type_object( 'anchor_lesson' )->has_archive );
		$this->assertFalse( get_post_type_object( 'anchor_quiz' )->public );
		$this->assertFalse( get_post_type_object( 'anchor_quiz' )->publicly_queryable );
	}

	public function test_each_post_type_uses_its_own_edit_capability() {
		$this->assertSame(
			Capabilities::cap( 'edit_courses' ),
			get_post_type_object( 'anchor_course' )->cap->edit_posts
		);
		$this->assertSame(
			Capabilities::cap( 'edit_lessons' ),
			get_post_type_object( 'anchor_lesson' )->cap->edit_posts
		);
		$this->assertSame(
			Capabilities::cap( 'edit_quizzes' ),
			get_post_type_object( 'anchor_quiz' )->cap->edit_posts
		);
	}

	public function test_administrator_can_edit_all_three_but_a_learner_cannot() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( current_user_can( Capabilities::cap( 'edit_courses' ) ) );
		wp_set_current_user( $this->make_learner() );
		$this->assertFalse( current_user_can( Capabilities::cap( 'edit_courses' ) ) );
	}

	public function test_parent_menu_filter_controls_show_in_menu() {
		add_filter( 'anchor_courses_parent_menu', '__return_false' );
		LessonPostType::register();
		$this->assertFalse( get_post_type_object( 'anchor_lesson' )->show_in_menu );
		remove_all_filters( 'anchor_courses_parent_menu' );
		LessonPostType::register();
		$this->assertSame(
			'edit.php?post_type=anchor_course',
			get_post_type_object( 'anchor_lesson' )->show_in_menu
		);
	}

	public function test_meta_key_helpers_prefix_consistently() {
		$this->assertSame( '_anchor_course_ce_credits', CoursePostType::meta_key( 'ce_credits' ) );
		$this->assertSame( '_anchor_lesson_completion_mode', LessonPostType::meta_key( 'completion_mode' ) );
		$this->assertSame( '_anchor_quiz_settings', QuizPostType::meta_key( 'settings' ) );
	}

	public function test_fixtures_create_usable_posts() {
		$course = $this->make_course( [ 'ce_credits' => '2' ] );
		$lesson = $this->make_lesson();
		$quiz   = $this->make_quiz();
		$this->assertSame( 'anchor_course', get_post_type( $course ) );
		$this->assertSame( 'anchor_lesson', get_post_type( $lesson ) );
		$this->assertSame( 'anchor_quiz', get_post_type( $quiz ) );
		$this->assertSame( '2', get_post_meta( $course, '_anchor_course_ce_credits', true ) );
	}
}
```

Add to `tests/class-anchor-courses-testcase.php`:

```php
	/** Create a published course with `_anchor_course_*` meta (keys WITHOUT the prefix). */
	protected function make_course( array $meta = [], string $title = 'Test Course' ): int {
		return $this->make_content( \Anchor\Courses\Content\CoursePostType::CPT, '_anchor_course_', $meta, $title );
	}

	/** Create a published lesson with `_anchor_lesson_*` meta. */
	protected function make_lesson( array $meta = [], string $title = 'Test Lesson' ): int {
		return $this->make_content( \Anchor\Courses\Content\LessonPostType::CPT, '_anchor_lesson_', $meta, $title );
	}

	/** Create a published quiz with `_anchor_quiz_*` meta. */
	protected function make_quiz( array $meta = [], string $title = 'Test Quiz' ): int {
		return $this->make_content( \Anchor\Courses\Content\QuizPostType::CPT, '_anchor_quiz_', $meta, $title );
	}

	private function make_content( string $cpt, string $prefix, array $meta, string $title ): int {
		$id = (int) self::factory()->post->create(
			[ 'post_type' => $cpt, 'post_status' => 'publish', 'post_title' => $title ]
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $prefix . $key, $value );
		}
		return $id;
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Cpt
```
Expected: FAIL - `post_type_exists('anchor_course')` returns false.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Content/CoursePostType.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Content;

use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The `anchor_course` post type (brief section 6.1) and its meta vocabulary (6.3). */
final class CoursePostType {

	public const CPT         = 'anchor_course';
	public const META_PREFIX = '_anchor_course_';

	/** Authored settings, all stored under META_PREFIX. Order is the metabox order. */
	public const META_KEYS = [
		'duration', 'difficulty', 'instructor', 'ce_credits', 'ce_type', 'ce_provider_name',
		'ce_provider_number', 'ce_expires_days',
		'prerequisites', 'completion_mode', 'completion_percentage', 'progression_mode',
		'certificate_enabled', 'certificate_template', 'expiration_days',
		'available_from', 'available_until', 'curriculum',
	];

	/**
	 * There is deliberately NO access-type key (design spec 1, row
	 * "Course access type"). A course has exactly one access rule: hold
	 * `anchor_course_{id}` or you are not enrolled. Nothing to configure,
	 * nothing to get wrong, and no second answer to the same question.
	 */
	public const PROGRESSION_MODES = [ 'free', 'sequential' ];
	public const COMPLETION_MODES  = [ 'all_required_items', 'minimum_percentage', 'manual' ];

	public static function meta_key( string $key ): string {
		return self::META_PREFIX . $key;
	}

	public static function register(): void {
		$cap = Capabilities::cap( 'edit_courses' );

		\register_post_type(
			self::CPT,
			[
				'labels'          => [
					'name'          => \__( 'Courses', 'anchor-schema' ),
					'singular_name' => \__( 'Course', 'anchor-schema' ),
					'add_new_item'  => \__( 'Add New Course', 'anchor-schema' ),
					'edit_item'     => \__( 'Edit Course', 'anchor-schema' ),
					'menu_name'     => \__( 'Courses', 'anchor-schema' ),
				],
				'public'          => true,
				'show_in_rest'    => true,
				'has_archive'     => true,
				'menu_icon'       => 'dashicons-welcome-learn-more',
				'menu_position'   => 26,
				'show_in_menu'    => (bool) \apply_filters( 'anchor_courses_parent_menu', true ),
				'supports'        => [ 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ],
				'rewrite'         => [ 'slug' => 'courses', 'with_front' => false ],
				'capability_type' => [ 'anchor_course', 'anchor_courses' ],
				'map_meta_cap'    => true,
				'capabilities'    => [
					'edit_posts'           => $cap,
					'edit_others_posts'    => $cap,
					'publish_posts'        => $cap,
					'delete_posts'         => $cap,
					'edit_published_posts' => $cap,
					'read_private_posts'   => $cap,
					'create_posts'         => $cap,
				],
			]
		);
	}
}
```

`anchor-courses/src/Content/LessonPostType.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Content;

use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The `anchor_lesson` post type (brief sections 6.1, 9; design spec 3.3). */
final class LessonPostType {

	public const CPT         = 'anchor_lesson';
	public const META_PREFIX = '_anchor_lesson_';

	public const META_KEYS = [
		'completion_mode', 'required', 'quiz_id', 'type',
		'event_id', 'session_index', 'require_prior_items',
	];

	/** Brief section 9 Phase 1 modes. `external_event` is explicitly future. */
	public const COMPLETION_MODES = [ 'manual', 'view', 'quiz_pass' ];

	/** Design spec section 3.3. */
	public const TYPES = [ 'content', 'live_session' ];

	public static function meta_key( string $key ): string {
		return self::META_PREFIX . $key;
	}

	public static function register(): void {
		$cap = Capabilities::cap( 'edit_lessons' );

		\register_post_type(
			self::CPT,
			[
				'labels'          => [
					'name'          => \__( 'Lessons', 'anchor-schema' ),
					'singular_name' => \__( 'Lesson', 'anchor-schema' ),
					'add_new_item'  => \__( 'Add New Lesson', 'anchor-schema' ),
					'edit_item'     => \__( 'Edit Lesson', 'anchor-schema' ),
					'menu_name'     => \__( 'Lessons', 'anchor-schema' ),
				],
				'public'          => true,
				'show_in_rest'    => true,
				'has_archive'     => false,
				'show_in_menu'    => \apply_filters( 'anchor_courses_parent_menu', true )
					? 'edit.php?post_type=' . CoursePostType::CPT
					: false,
				'supports'        => [ 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ],
				'rewrite'         => [ 'slug' => 'lessons', 'with_front' => false ],
				'capability_type' => [ 'anchor_lesson', 'anchor_lessons' ],
				'map_meta_cap'    => true,
				'capabilities'    => [
					'edit_posts'           => $cap,
					'edit_others_posts'    => $cap,
					'publish_posts'        => $cap,
					'delete_posts'         => $cap,
					'edit_published_posts' => $cap,
					'read_private_posts'   => $cap,
					'create_posts'         => $cap,
				],
			]
		);
	}
}
```

`anchor-courses/src/Content/QuizPostType.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Content;

use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The `anchor_quiz` post type (brief section 6.1).
 *
 * public => false on purpose: a quiz is never a standalone URL - it renders
 * inside a lesson or course through the quiz service, which enforces enrolment
 * and progression. A publicly queryable quiz post would be a way to read quiz
 * content around those checks.
 */
final class QuizPostType {

	public const CPT         = 'anchor_quiz';
	public const META_PREFIX = '_anchor_quiz_';
	public const META_KEYS   = [ 'settings', 'questions', 'required' ];

	public static function meta_key( string $key ): string {
		return self::META_PREFIX . $key;
	}

	public static function register(): void {
		$cap = Capabilities::cap( 'edit_quizzes' );

		\register_post_type(
			self::CPT,
			[
				'labels'              => [
					'name'          => \__( 'Quizzes', 'anchor-schema' ),
					'singular_name' => \__( 'Quiz', 'anchor-schema' ),
					'add_new_item'  => \__( 'Add New Quiz', 'anchor-schema' ),
					'edit_item'     => \__( 'Edit Quiz', 'anchor-schema' ),
					'menu_name'     => \__( 'Quizzes', 'anchor-schema' ),
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_rest'        => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'show_in_menu'        => \apply_filters( 'anchor_courses_parent_menu', true )
					? 'edit.php?post_type=' . CoursePostType::CPT
					: false,
				'supports'            => [ 'title', 'revisions' ],
				'capability_type'     => [ 'anchor_quiz', 'anchor_quizzes' ],
				'map_meta_cap'        => true,
				'capabilities'        => [
					'edit_posts'           => $cap,
					'edit_others_posts'    => $cap,
					'publish_posts'        => $cap,
					'delete_posts'         => $cap,
					'edit_published_posts' => $cap,
					'read_private_posts'   => $cap,
					'create_posts'         => $cap,
				],
			]
		);
	}
}
```

`anchor-courses/anchor-courses.php` - add to the constructor:

```php
		\add_action( 'init', [ Content\CoursePostType::class, 'register' ] );
		\add_action( 'init', [ Content\LessonPostType::class, 'register' ] );
		\add_action( 'init', [ Content\QuizPostType::class, 'register' ] );
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Cpt
```
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Content anchor-courses/anchor-courses.php \
        tests/class-anchor-courses-testcase.php tests/test-courses-cpt.php
git commit -m "feat(courses): register the course, lesson and quiz post types"
```

---

### Task 7: The curriculum model

**Files:**
- Create: `anchor-courses/src/Content/Curriculum.php`
- Test: `tests/unit/test-curriculum-sanitize.php` (pure), `tests/test-courses-curriculum.php` (integration)

**Interfaces:**
- Consumes: `Support\Uuid::v4()`, `Content\CoursePostType::CPT`.
- Produces:
  - `Curriculum::META = '_anchor_course_curriculum'`, `Curriculum::ITEM_TYPES = ['lesson','quiz']`
  - `Curriculum::sanitize( array $modules ): array` - **pure**. Module shape `['id'=>uuid,'title'=>string,'description'=>string,'items'=>[['type'=>'lesson'|'quiz','id'=>int,'required'=>bool]]]`
  - `Curriculum::get( int $course_id ): array`
  - `Curriculum::save( int $course_id, array $modules ): array` - fires `do_action( 'anchor_courses_curriculum_saved', $course_id, $modules )`
  - `Curriculum::items( int $course_id ): array` - flat, ordered; each `['type','id','required','module_id','index']`
  - `Curriculum::required_items( int $course_id ): array`
  - `Curriculum::position( int $course_id, int $item_id, string $type ): int` (-1 when absent)
  - `Curriculum::items_before( int $course_id, int $item_id, string $type ): array`
  - `Curriculum::contains( int $course_id, int $item_id, string $type ): bool`
  - `Curriculum::course_for_item( int $item_id, string $type ): int` (0 when unattached)

- [ ] **Step 1: Write the failing tests**

`tests/unit/test-curriculum-sanitize.php`:

```php
<?php
/**
 * Pure unit test: curriculum sanitisation and UUID stability (brief section 6.2).
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Content\Curriculum;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Curriculum extends TestCase {

	public function test_a_module_without_an_id_is_given_a_uuid() {
		$out = Curriculum::sanitize( [ [ 'title' => 'Module 1', 'items' => [] ] ] );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$out[0]['id']
		);
	}

	public function test_an_existing_module_uuid_survives_reordering() {
		$first  = Curriculum::sanitize( [ [ 'title' => 'A', 'items' => [] ], [ 'title' => 'B', 'items' => [] ] ] );
		$uuid_a = $first[0]['id'];
		$uuid_b = $first[1]['id'];

		$reordered = Curriculum::sanitize( [ $first[1], $first[0] ] );

		$this->assertSame( $uuid_b, $reordered[0]['id'] );
		$this->assertSame( $uuid_a, $reordered[1]['id'] );
	}

	public function test_items_are_coerced_to_the_canonical_shape() {
		$out = Curriculum::sanitize(
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => '124' ],
				[ 'type' => 'quiz', 'id' => 150, 'required' => '0' ],
			] ] ]
		);
		$this->assertSame(
			[ [ 'type' => 'lesson', 'id' => 124, 'required' => true ], [ 'type' => 'quiz', 'id' => 150, 'required' => false ] ],
			$out[0]['items']
		);
	}

	public function test_unknown_item_types_and_non_positive_ids_are_dropped() {
		$out = Curriculum::sanitize(
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'video', 'id' => 5 ],
				[ 'type' => 'lesson', 'id' => 0 ],
				[ 'type' => 'lesson', 'id' => -3 ],
				[ 'type' => 'lesson', 'id' => 7 ],
			] ] ]
		);
		$this->assertSame( [ [ 'type' => 'lesson', 'id' => 7, 'required' => true ] ], $out[0]['items'] );
	}

	public function test_duplicate_items_within_a_course_are_collapsed() {
		$out = Curriculum::sanitize(
			[
				[ 'title' => 'M1', 'items' => [ [ 'type' => 'lesson', 'id' => 9 ] ] ],
				[ 'title' => 'M2', 'items' => [ [ 'type' => 'lesson', 'id' => 9 ], [ 'type' => 'quiz', 'id' => 9 ] ] ],
			]
		);
		$this->assertCount( 1, $out[0]['items'] );
		$this->assertSame(
			[ [ 'type' => 'quiz', 'id' => 9, 'required' => true ] ],
			$out[1]['items'],
			'A lesson and a quiz may share an id; the same lesson twice may not.'
		);
	}

	public function test_titles_are_text_and_descriptions_keep_safe_html() {
		$out = Curriculum::sanitize(
			[ [ 'title' => '<b>Bold</b> title', 'description' => '<p>Keep <em>this</em></p>', 'items' => [] ] ]
		);
		$this->assertSame( 'Bold title', $out[0]['title'] );
		$this->assertSame( '<p>Keep <em>this</em></p>', $out[0]['description'] );
	}

	public function test_non_array_input_yields_an_empty_curriculum() {
		$this->assertSame( [], Curriculum::sanitize( [] ) );
		$this->assertSame( [], Curriculum::sanitize( [ 'not-a-module' ] ) );
	}
}
```

`tests/test-courses-curriculum.php`:

```php
<?php
/**
 * Anchor Courses - curriculum persistence and navigation (brief section 6.2).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;

/** @group courses */
class Test_Courses_Curriculum extends Anchor_Courses_TestCase {

	private int $course;
	private int $lesson_a;
	private int $lesson_b;
	private int $quiz;

	public function set_up() {
		parent::set_up();
		$this->course   = $this->make_course();
		$this->lesson_a = $this->make_lesson( [], 'Lesson A' );
		$this->lesson_b = $this->make_lesson( [], 'Lesson B' );
		$this->quiz     = $this->make_quiz( [], 'Quiz A' );

		Curriculum::save(
			$this->course,
			[
				[ 'title' => 'Module 1', 'items' => [
					[ 'type' => 'lesson', 'id' => $this->lesson_a ],
					[ 'type' => 'quiz', 'id' => $this->quiz ],
				] ],
				[ 'title' => 'Module 2', 'items' => [
					[ 'type' => 'lesson', 'id' => $this->lesson_b, 'required' => false ],
				] ],
			]
		);
	}

	public function test_save_round_trips_through_post_meta() {
		$modules = Curriculum::get( $this->course );
		$this->assertCount( 2, $modules );
		$this->assertSame( 'Module 1', $modules[0]['title'] );
		$this->assertCount( 2, $modules[0]['items'] );
	}

	public function test_items_are_flattened_in_curriculum_order() {
		$items = Curriculum::items( $this->course );
		$this->assertSame(
			[ [ 'lesson', $this->lesson_a ], [ 'quiz', $this->quiz ], [ 'lesson', $this->lesson_b ] ],
			array_map( static fn( $i ) => [ $i['type'], $i['id'] ], $items )
		);
		$this->assertSame( [ 0, 1, 2 ], array_column( $items, 'index' ) );
	}

	public function test_required_items_excludes_optional_ones() {
		$required = Curriculum::required_items( $this->course );
		$this->assertCount( 2, $required );
		$this->assertSame( [ $this->lesson_a, $this->quiz ], array_column( $required, 'id' ) );
	}

	public function test_position_and_items_before() {
		$this->assertSame( 0, Curriculum::position( $this->course, $this->lesson_a, 'lesson' ) );
		$this->assertSame( 2, Curriculum::position( $this->course, $this->lesson_b, 'lesson' ) );
		$this->assertSame( -1, Curriculum::position( $this->course, 999999, 'lesson' ) );

		$before = Curriculum::items_before( $this->course, $this->quiz, 'quiz' );
		$this->assertSame( [ $this->lesson_a ], array_column( $before, 'id' ) );
	}

	public function test_course_for_item_resolves_the_owning_course() {
		$this->assertSame( $this->course, Curriculum::course_for_item( $this->lesson_b, 'lesson' ) );
		$this->assertSame( 0, Curriculum::course_for_item( $this->make_lesson(), 'lesson' ) );
	}

	/** Brief section 21.1: removing an item from a course must not delete the post. */
	public function test_removing_an_item_leaves_the_lesson_post_intact() {
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'Module 1', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson_a ] ] ] ]
		);
		$this->assertInstanceOf( WP_Post::class, get_post( $this->quiz ) );
		$this->assertSame( 'publish', get_post_status( $this->lesson_b ) );
		$this->assertFalse( Curriculum::contains( $this->course, $this->quiz, 'quiz' ) );
	}

	public function test_module_uuids_are_stable_across_a_resave() {
		$before = array_column( Curriculum::get( $this->course ), 'id' );
		Curriculum::save( $this->course, Curriculum::get( $this->course ) );
		$this->assertSame( $before, array_column( Curriculum::get( $this->course ), 'id' ) );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Curriculum
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Curriculum
```
Expected: both FAIL - `Class "Anchor\Courses\Content\Curriculum" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Content/Curriculum.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Content;

use Anchor\Courses\Support\Uuid;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Course curriculum: ordered modules of ordered lesson/quiz references (brief 6.2).
 *
 * Modules are NOT posts. They live as structured meta on the course, each with a
 * stable UUID so drag-reordering never breaks a reference. Removing an item from
 * a module removes the REFERENCE only - the lesson/quiz post is untouched
 * (brief section 21.1).
 *
 * sanitize() is pure (no get_post_meta, no DB) so it is unit-testable without
 * WordPress; get()/save() are the only I/O.
 */
final class Curriculum {

	public const META       = '_anchor_course_curriculum';
	public const ITEM_TYPES = [ 'lesson', 'quiz' ];

	/**
	 * Normalise authored input to the canonical shape. Pure.
	 *
	 * @param array $modules Raw module rows.
	 * @return array<int,array{id:string,title:string,description:string,items:array<int,array{type:string,id:int,required:bool}>}>
	 */
	public static function sanitize( array $modules ): array {
		$clean = [];
		$seen  = []; // "type:id" => true, so one item cannot appear twice in a course.

		foreach ( $modules as $module ) {
			if ( ! \is_array( $module ) ) {
				continue;
			}

			$items = [];
			foreach ( (array) ( $module['items'] ?? [] ) as $item ) {
				if ( ! \is_array( $item ) ) {
					continue;
				}
				$type = \sanitize_key( (string) ( $item['type'] ?? '' ) );
				$id   = (int) ( $item['id'] ?? 0 );
				if ( ! \in_array( $type, self::ITEM_TYPES, true ) || $id <= 0 ) {
					continue;
				}
				$fingerprint = $type . ':' . $id;
				if ( isset( $seen[ $fingerprint ] ) ) {
					continue;
				}
				$seen[ $fingerprint ] = true;

				$items[] = [
					'type'     => $type,
					'id'       => $id,
					// Default required: an item is part of the course unless said otherwise.
					'required' => ! \array_key_exists( 'required', $item ) || (bool) $item['required'],
				];
			}

			$id = (string) ( $module['id'] ?? '' );
			if ( ! \preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id ) ) {
				$id = Uuid::v4();
			}

			$clean[] = [
				'id'          => $id,
				'title'       => \sanitize_text_field( (string) ( $module['title'] ?? '' ) ),
				'description' => \wp_kses_post( (string) ( $module['description'] ?? '' ) ),
				'items'       => $items,
			];
		}

		return $clean;
	}

	/** @return array Canonical modules for a course (empty when unauthored). */
	public static function get( int $course_id ): array {
		$stored = \get_post_meta( $course_id, self::META, true );
		return \is_array( $stored ) ? self::sanitize( $stored ) : [];
	}

	/** Persist and return the canonical modules actually stored. */
	public static function save( int $course_id, array $modules ): array {
		$clean = self::sanitize( $modules );
		\update_post_meta( $course_id, self::META, $clean );
		\do_action( 'anchor_courses_curriculum_saved', $course_id, $clean );
		return $clean;
	}

	/**
	 * Flat, ordered item list.
	 *
	 * @return array<int,array{type:string,id:int,required:bool,module_id:string,index:int}>
	 */
	public static function items( int $course_id ): array {
		$flat  = [];
		$index = 0;
		foreach ( self::get( $course_id ) as $module ) {
			foreach ( $module['items'] as $item ) {
				$item['module_id'] = $module['id'];
				$item['index']     = $index++;
				$flat[]            = $item;
			}
		}
		return $flat;
	}

	/** @return array Only the items that count toward completion. */
	public static function required_items( int $course_id ): array {
		return \array_values(
			\array_filter( self::items( $course_id ), static fn( array $i ): bool => $i['required'] )
		);
	}

	/** Zero-based position in the flattened curriculum, or -1. */
	public static function position( int $course_id, int $item_id, string $type ): int {
		foreach ( self::items( $course_id ) as $item ) {
			if ( $item['id'] === $item_id && $item['type'] === $type ) {
				return $item['index'];
			}
		}
		return -1;
	}

	/** Every item ordered before the given one. Empty when the item is absent. */
	public static function items_before( int $course_id, int $item_id, string $type ): array {
		$position = self::position( $course_id, $item_id, $type );
		if ( $position < 0 ) {
			return [];
		}
		return \array_slice( self::items( $course_id ), 0, $position );
	}

	public static function contains( int $course_id, int $item_id, string $type ): bool {
		return self::position( $course_id, $item_id, $type ) >= 0;
	}

	/** The course that lists this item, or 0. */
	public static function course_for_item( int $item_id, string $type ): int {
		$courses = \get_posts(
			[
				'post_type'      => CoursePostType::CPT,
				'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);

		foreach ( $courses as $course_id ) {
			if ( self::contains( (int) $course_id, $item_id, $type ) ) {
				return (int) $course_id;
			}
		}

		return 0;
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Curriculum
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Curriculum
```
Expected: PASS (7 unit) and PASS (7 integration).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Content/Curriculum.php tests/unit/test-curriculum-sanitize.php tests/test-courses-curriculum.php
git commit -m "feat(courses): curriculum model with stable module UUIDs"
```

---

### Task 8: Course settings metabox and save handler

**Files:**
- Create: `anchor-courses/src/Admin/CourseEditor.php`
- Create: `anchor-courses/assets/admin.css`
- Modify: `anchor-courses/anchor-courses.php` - construct `Admin\CourseEditor` when `is_admin()`
- Test: `tests/test-courses-course-editor.php`

**Interfaces:**
- Consumes: `CoursePostType::meta_key()`, `CoursePostType::PROGRESSION_MODES/COMPLETION_MODES`, `Capabilities::cap( 'edit_courses' )`.
- Produces:
  - `Admin\CourseEditor::NONCE = 'anchor_courses_course_nonce'`
  - `Admin\CourseEditor::defaults(): array` - design spec section 4 defaults
  - `Admin\CourseEditor::setting( int $course_id, string $key ): mixed`
  - `Admin\CourseEditor::sanitize_value( string $key, $value ): mixed`
  - `Admin\CourseEditor::prerequisite_role_choices(): array` - **the only role picker in the module**: completion roles (`anchor_course_*_completed`) and event roles (`anchor_event_*`), nothing else
  - `Admin\CourseEditor::add_metaboxes(): void`, `render_settings( \WP_Post $post ): void`, `save( int $post_id ): void`, `assets( string $hook ): void`

**There is no access-type field and no auto-enrol picker** (design spec 1, 3.1). The Access section of the metabox carries the availability window, the expiry and the prerequisites; who may enrol is answered by the access role, which Task 19 adds to this same screen as a panel. Prerequisites are the one place a role is chosen, and the list is restricted by *shape*, not by an exclusion list: a slug is offerable only if it matches `anchor_course_{digits}_completed` or `anchor_event_{digits}`. Built-in and WooCommerce roles are not "excluded", they simply never match - which is the point, because `set_user_role` fires on every new account, so `customer` or `subscriber` as a prerequisite would gate on nothing.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - course settings persistence (brief section 6.3, design spec 4).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;

/** @group courses */
class Test_Courses_Course_Editor extends Anchor_Courses_TestCase {

	private function post_course_settings( int $course_id, array $fields ): void {
		$_POST = [
			CourseEditor::NONCE => wp_create_nonce( CourseEditor::NONCE ),
			'anchor_course'     => $fields,
		];
		( new CourseEditor() )->save( $course_id );
		$_POST = [];
	}

	public function test_defaults_match_the_design_spec() {
		$d = CourseEditor::defaults();
		$this->assertSame( 'sequential', $d['progression_mode'] );
		$this->assertSame( 'all_required_items', $d['completion_mode'] );
		$this->assertSame( 100, $d['completion_percentage'] );
		$this->assertArrayNotHasKey( 'access_type', $d, 'There is no access type: holding anchor_course_{id} IS enrolment (design spec 3.1).' );
		$this->assertArrayNotHasKey( 'auto_enroll_roles', $d, 'There is no auto-enrol picker.' );
	}

	public function test_save_persists_every_brief_meta_key() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		add_role( 'anchor_course_9_completed', 'Completed: Prereq', [] );
		$course = $this->make_course();

		$this->post_course_settings(
			$course,
			[
				'duration' => '3 hours', 'difficulty' => 'intermediate', 'instructor' => 'Dr Vega',
				'ce_credits' => '2.5', 'ce_type' => 'Dental CE', 'ce_provider_name' => 'DEKA Academy',
				'ce_provider_number' => 'AGD-1234', 'ce_expires_days' => '730',
				'prerequisites' => [ 'anchor_course_9_completed' ], 'completion_mode' => 'minimum_percentage',
				'completion_percentage' => '80', 'progression_mode' => 'free',
				'certificate_enabled' => '1', 'certificate_template' => 'default',
				'expiration_days' => '365', 'available_from' => '2026-01-01', 'available_until' => '2026-12-31',
			]
		);

		$this->assertSame( '3 hours', get_post_meta( $course, '_anchor_course_duration', true ) );
		$this->assertSame( 2.5, (float) get_post_meta( $course, '_anchor_course_ce_credits', true ) );
		$this->assertSame( [ 'anchor_course_9_completed' ], get_post_meta( $course, '_anchor_course_prerequisites', true ) );
		$this->assertSame( 80, (int) get_post_meta( $course, '_anchor_course_completion_percentage', true ) );
		$this->assertSame( '2026-12-31', get_post_meta( $course, '_anchor_course_available_until', true ) );

		remove_role( 'anchor_course_9_completed' );
	}

	/**
	 * Prerequisites accept completion roles and event roles, and nothing else.
	 *
	 * Not an exclusion list - a shape test. `customer`, `subscriber` and a
	 * course's own ACCESS role (`anchor_course_{id}`, no `_completed`) all fail
	 * it, the first two because set_user_role fires on every new account, the
	 * third because "you must already be enrolled" is not a prerequisite.
	 */
	public function test_prerequisites_accept_only_completion_and_event_roles() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		add_role( 'anchor_event_12', 'Event: Test', [] );
		add_role( 'anchor_course_77_completed', 'Completed: Other', [] );
		add_role( 'anchor_course_77', 'Course: Other', [] );
		$course = $this->make_course();

		$this->post_course_settings( $course, [
			'prerequisites' => [
				'customer', 'subscriber', 'administrator', 'shop_manager',
				'anchor_course_77',            // an ACCESS role: not a prerequisite.
				'anchor_event_12',
				'anchor_course_77_completed',
			],
		] );

		$this->assertSame(
			[ 'anchor_event_12', 'anchor_course_77_completed' ],
			get_post_meta( $course, '_anchor_course_prerequisites', true ),
			'A crafted POST must be dropped at the sanitiser, not merely hidden in the UI.'
		);

		$choices = CourseEditor::prerequisite_role_choices();
		$this->assertArrayHasKey( 'anchor_event_12', $choices );
		$this->assertArrayHasKey( 'anchor_course_77_completed', $choices );
		$this->assertArrayNotHasKey( 'customer', $choices );
		$this->assertArrayNotHasKey( 'subscriber', $choices );
		$this->assertArrayNotHasKey( 'anchor_course_77', $choices );

		remove_role( 'anchor_event_12' );
		remove_role( 'anchor_course_77_completed' );
		remove_role( 'anchor_course_77' );
	}

	public function test_invalid_enum_values_fall_back_to_the_default() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course();

		$this->post_course_settings( $course, [ 'progression_mode' => 'nope', 'completion_mode' => 'x' ] );

		$this->assertSame( 'sequential', get_post_meta( $course, '_anchor_course_progression_mode', true ) );
		$this->assertSame( 'all_required_items', get_post_meta( $course, '_anchor_course_completion_mode', true ) );
	}

	public function test_save_bails_without_a_valid_nonce() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course( [ 'instructor' => 'Original' ] );

		$_POST = [ 'anchor_course' => [ 'instructor' => 'Injected' ] ];
		( new CourseEditor() )->save( $course );
		$_POST = [];

		$this->assertSame( 'Original', get_post_meta( $course, '_anchor_course_instructor', true ) );
	}

	public function test_save_bails_without_the_edit_capability() {
		$course = $this->make_course( [ 'instructor' => 'Original' ] );
		wp_set_current_user( $this->make_learner() );

		$this->post_course_settings( $course, [ 'instructor' => 'Injected' ] );

		$this->assertSame( 'Original', get_post_meta( $course, '_anchor_course_instructor', true ) );
	}

	public function test_dates_are_stored_as_iso_or_discarded() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course();
		$this->post_course_settings( $course, [ 'available_from' => 'tomorrow-ish' ] );
		$this->assertSame( '', get_post_meta( $course, '_anchor_course_available_from', true ) );
	}

	public function test_the_metabox_is_registered_on_the_course_screen() {
		global $wp_meta_boxes;
		set_current_screen( 'anchor_course' );
		( new CourseEditor() )->add_metaboxes();
		$this->assertArrayHasKey( 'anchor_courses_settings', $wp_meta_boxes[ CoursePostType::CPT ]['normal']['high'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Course_Editor
```
Expected: FAIL - `Class "Anchor\Courses\Admin\CourseEditor" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Admin/CourseEditor.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Module;
use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Course settings metabox: brief 6.3 authored meta, 12 CE fields, 21.1 grouping. */
final class CourseEditor {

	public const NONCE = 'anchor_courses_course_nonce';

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
		\add_action( 'save_post_' . CoursePostType::CPT, [ $this, 'save' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	/** Authored defaults (design spec section 4). */
	public static function defaults(): array {
		return [
			'duration' => '', 'difficulty' => '', 'instructor' => '',
			'ce_credits' => 0.0, 'ce_type' => '', 'ce_provider_name' => '', 'ce_provider_number' => '',
			'ce_expires_days' => 0,
			'prerequisites' => [],
			'completion_mode' => 'all_required_items', 'completion_percentage' => 100,
			'progression_mode' => 'sequential',
			'certificate_enabled' => 1, 'certificate_template' => 'default',
			'expiration_days' => 0, 'available_from' => '', 'available_until' => '',
		];
	}

	/**
	 * Read one setting with the default applied.
	 *
	 * @return mixed
	 */
	public static function setting( int $course_id, string $key ) {
		$defaults = self::defaults();
		$stored   = \get_post_meta( $course_id, CoursePostType::meta_key( $key ), true );

		if ( '' === $stored || null === $stored ) {
			return $defaults[ $key ] ?? '';
		}
		if ( \is_array( $defaults[ $key ] ?? null ) && ! \is_array( $stored ) ) {
			return $defaults[ $key ];
		}
		return $stored;
	}

	public function add_metaboxes(): void {
		\add_meta_box(
			'anchor_courses_settings',
			\__( 'Course Settings', 'anchor-schema' ),
			[ $this, 'render_settings' ],
			CoursePostType::CPT,
			'normal',
			'high'
		);
	}

	public function assets( string $hook = '' ): void {
		if ( ! \in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = \get_current_screen();
		if ( ! $screen || CoursePostType::CPT !== $screen->post_type ) {
			return;
		}

		\wp_enqueue_style( 'anchor-courses-admin', Module::assets_url() . 'admin.css', [], Module::VERSION );
	}

	public function render_settings( \WP_Post $post ): void {
		\wp_nonce_field( self::NONCE, self::NONCE );
		$id = (int) $post->ID;

		echo '<div class="anchor-courses-settings">';

		echo '<h4>' . \esc_html__( 'Details', 'anchor-schema' ) . '</h4>';
		$this->text_field( $id, 'duration', \__( 'Duration', 'anchor-schema' ) );
		$this->text_field( $id, 'difficulty', \__( 'Difficulty', 'anchor-schema' ) );
		$this->text_field( $id, 'instructor', \__( 'Instructor', 'anchor-schema' ) );

		echo '<h4>' . \esc_html__( 'Access', 'anchor-schema' ) . '</h4>';
		echo '<p class="description">' . \esc_html__(
			'Access is the course role, shown in the Course Role panel. Add someone on the Learners tab, or sell a product mapped to this course.',
			'anchor-schema'
		) . '</p>';
		$this->prerequisite_picker( $id );
		$this->text_field( $id, 'available_from', \__( 'Available from (YYYY-MM-DD)', 'anchor-schema' ) );
		$this->text_field( $id, 'available_until', \__( 'Available until (YYYY-MM-DD)', 'anchor-schema' ) );
		$this->text_field( $id, 'expiration_days', \__( 'Enrolment expires after (days, 0 = never)', 'anchor-schema' ) );

		echo '<h4>' . \esc_html__( 'Progression and completion', 'anchor-schema' ) . '</h4>';
		$this->select_field(
			$id,
			'progression_mode',
			\__( 'Progression', 'anchor-schema' ),
			[ 'sequential' => \__( 'Sequential', 'anchor-schema' ), 'free' => \__( 'Free', 'anchor-schema' ) ]
		);
		$this->select_field(
			$id,
			'completion_mode',
			\__( 'Completion rule', 'anchor-schema' ),
			[
				'all_required_items' => \__( 'All required items', 'anchor-schema' ),
				'minimum_percentage' => \__( 'Minimum percentage', 'anchor-schema' ),
				'manual'             => \__( 'Manual only', 'anchor-schema' ),
			]
		);
		$this->text_field( $id, 'completion_percentage', \__( 'Minimum percentage', 'anchor-schema' ) );

		echo '<h4>' . \esc_html__( 'CE credits', 'anchor-schema' ) . '</h4>';
		$this->text_field( $id, 'ce_credits', \__( 'Credits awarded', 'anchor-schema' ) );
		$this->text_field( $id, 'ce_type', \__( 'Credit type', 'anchor-schema' ) );
		$this->text_field( $id, 'ce_provider_name', \__( 'Provider name', 'anchor-schema' ) );
		$this->text_field( $id, 'ce_provider_number', \__( 'Provider number', 'anchor-schema' ) );
		$this->text_field( $id, 'ce_expires_days', \__( 'Credits expire after (days, 0 = never)', 'anchor-schema' ) );

		echo '<h4>' . \esc_html__( 'Certificate', 'anchor-schema' ) . '</h4>';
		\printf(
			'<p><label><input type="checkbox" name="anchor_course[certificate_enabled]" value="1"%s /> %s</label></p>',
			\checked( (string) self::setting( $id, 'certificate_enabled' ), '1', false ),
			\esc_html__( 'Issue a certificate on completion', 'anchor-schema' )
		);
		$this->text_field( $id, 'certificate_template', \__( 'Certificate template slug', 'anchor-schema' ) );

		echo '</div>';
	}

	private function text_field( int $course_id, string $key, string $label ): void {
		\printf(
			'<p class="anchor-courses-field"><label for="ac-%1$s"><strong>%2$s</strong></label><br />'
			. '<input type="text" class="regular-text" id="ac-%1$s" name="anchor_course[%1$s]" value="%3$s" /></p>',
			\esc_attr( $key ),
			\esc_html( $label ),
			\esc_attr( (string) self::setting( $course_id, $key ) )
		);
	}

	private function select_field( int $course_id, string $key, string $label, array $options ): void {
		\printf(
			'<p class="anchor-courses-field"><label for="ac-%1$s"><strong>%2$s</strong></label><br />'
			. '<select id="ac-%1$s" name="anchor_course[%1$s]">',
			\esc_attr( $key ),
			\esc_html( $label )
		);
		$current = (string) self::setting( $course_id, $key );
		foreach ( $options as $value => $text ) {
			\printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( (string) $value ),
				\selected( $current, (string) $value, false ),
				\esc_html( (string) $text )
			);
		}
		echo '</select></p>';
	}

	/**
	 * The module's ONE role picker, and it picks prerequisites only.
	 *
	 * There is no auto-enrol picker and no access-type select: access is the
	 * course's own role (design spec 3.1). This list answers a different
	 * question - "what must they already have finished?" - so it offers only
	 * things that can be finished.
	 */
	private function prerequisite_picker( int $course_id ): void {
		$selected = \array_map( 'strval', (array) self::setting( $course_id, 'prerequisites' ) );
		$choices  = self::prerequisite_role_choices();

		\printf(
			'<p class="anchor-courses-field"><label for="ac-prerequisites"><strong>%s</strong></label><br />',
			\esc_html__( 'Prerequisites (completed courses / attended events)', 'anchor-schema' )
		);

		if ( [] === $choices ) {
			echo \esc_html__( 'No completed-course or event roles exist yet.', 'anchor-schema' ) . '</p>';
			return;
		}

		echo '<select multiple size="6" id="ac-prerequisites" name="anchor_course[prerequisites][]" class="anchor-courses-roles">';
		foreach ( $choices as $slug => $role ) {
			\printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( (string) $slug ),
				\in_array( (string) $slug, $selected, true ) ? ' selected="selected"' : '',
				\esc_html( (string) ( $role['name'] ?? $slug ) )
			);
		}
		echo '</select></p>';
	}

	/**
	 * Slug shapes that may be a prerequisite.
	 *
	 * A course's COMPLETION role, or an event's role. Deliberately not an
	 * exclusion list: `customer`, `subscriber` and anything else a plugin
	 * invents simply never match, and neither does a course's ACCESS role
	 * (`anchor_course_{id}` with no `_completed`), because "must already be
	 * enrolled" is not a prerequisite - it is a circular one.
	 */
	private const PREREQUISITE_PATTERNS = [
		'/^anchor_course_\d+_completed$/',
		'/^anchor_event_\d+$/',
	];

	/** @return bool Whether this slug may be stored as a prerequisite. */
	public static function is_prerequisite_slug( string $slug ): bool {
		foreach ( self::PREREQUISITE_PATTERNS as $pattern ) {
			if ( 1 === \preg_match( $pattern, $slug ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The roles the prerequisites picker may offer and a save may store.
	 *
	 * @return array<string,array> slug => role definition
	 */
	public static function prerequisite_role_choices(): array {
		$choices = [];
		foreach ( \wp_roles()->roles as $slug => $role ) {
			if ( self::is_prerequisite_slug( (string) $slug ) ) {
				$choices[ (string) $slug ] = $role;
			}
		}
		/**
		 * Filter the prerequisite roles offered on the course editor.
		 *
		 * Anything added here is also accepted by the sanitiser, so a site can
		 * widen the list deliberately - but never by accident.
		 *
		 * @param array<string,array> $choices slug => role definition
		 */
		return (array) \apply_filters( 'anchor_courses_prerequisite_role_choices', $choices );
	}

	public function save( int $post_id ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE ] ) ? \sanitize_text_field( \wp_unslash( (string) $_POST[ self::NONCE ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! \current_user_can( Capabilities::cap( 'edit_courses' ) ) ) {
			return;
		}

		$input = isset( $_POST['anchor_course'] ) && \is_array( $_POST['anchor_course'] )
			? \wp_unslash( $_POST['anchor_course'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: [];

		foreach ( \array_keys( self::defaults() ) as $key ) {
			// An unposted checkbox means "off", so certificate_enabled always writes.
			$raw = $input[ $key ] ?? ( 'certificate_enabled' === $key ? '' : null );
			if ( null === $raw ) {
				continue;
			}
			\update_post_meta( $post_id, CoursePostType::meta_key( $key ), self::sanitize_value( $key, $raw ) );
		}
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function sanitize_value( string $key, $value ) {
		$defaults = self::defaults();

		switch ( $key ) {
			case 'progression_mode':
				$v = \sanitize_key( (string) $value );
				return \in_array( $v, CoursePostType::PROGRESSION_MODES, true ) ? $v : $defaults['progression_mode'];

			case 'completion_mode':
				$v = \sanitize_key( (string) $value );
				return \in_array( $v, CoursePostType::COMPLETION_MODES, true ) ? $v : $defaults['completion_mode'];

			case 'prerequisites':
				// Only roles the picker could have offered. A crafted POST naming
				// `customer` - or this course's own access role - is dropped
				// here, not just hidden in the UI.
				$allowed = \array_keys( self::prerequisite_role_choices() );
				return \array_values( \array_filter(
					\array_map( 'sanitize_key', (array) $value ),
					static fn( string $slug ): bool => '' !== $slug && \in_array( $slug, $allowed, true )
				) );

			case 'ce_credits':
				return (float) $value;

			case 'ce_expires_days':
			case 'expiration_days':
				return \absint( $value );

			case 'completion_percentage':
				return \max( 1, \min( 100, \absint( $value ) ) );

			case 'certificate_enabled':
				return '' === $value ? 0 : 1;

			case 'available_from':
			case 'available_until':
				$v = \sanitize_text_field( (string) $value );
				return \preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';

			default:
				return \sanitize_text_field( (string) $value );
		}
	}
}
```

`anchor-courses/assets/admin.css`:

```css
/* Anchor Courses - admin screens. */
.anchor-courses-settings h4 { margin: 1.5em 0 .5em; padding-top: .75em; border-top: 1px solid #dcdcde; }
.anchor-courses-settings h4:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
.anchor-courses-field { margin: 0 0 1em; }
.anchor-courses-roles { min-width: 24em; }
.anchor-courses-modules { margin: 0; padding: 0; list-style: none; }
.anchor-courses-module,
.anchor-courses-question { border: 1px solid #c3c4c7; background: #fff; margin: 0 0 .75em; padding: .75em; border-radius: 3px; }
.anchor-courses-module-head { display: flex; gap: .5em; align-items: center; flex-wrap: wrap; }
.anchor-courses-module-handle,
.anchor-courses-item-handle { cursor: move; color: #787c82; }
.anchor-courses-items,
.anchor-courses-answers,
.anchor-courses-question-list { margin: .5em 0 0 1.5em; padding: 0; list-style: none; min-height: 2em; }
.anchor-courses-item,
.anchor-courses-answer { display: flex; gap: .5em; align-items: center; padding: .35em .5em; background: #f6f7f7; border: 1px solid #dcdcde; margin-bottom: .25em; border-radius: 3px; }
.anchor-courses-item-type { font-size: 11px; text-transform: uppercase; color: #50575e; }
.anchor-courses-report td.progress { min-width: 8em; }
.anchor-courses-bar { background: #dcdcde; height: 8px; border-radius: 4px; overflow: hidden; }
.anchor-courses-bar span { display: block; height: 100%; background: #2271b1; }
```

`anchor-courses/anchor-courses.php` - in the constructor:

```php
		if ( \is_admin() ) {
			new Admin\CourseEditor();
		}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Course_Editor
```
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Admin/CourseEditor.php anchor-courses/assets/admin.css \
        anchor-courses/anchor-courses.php tests/test-courses-course-editor.php
git commit -m "feat(courses): course settings metabox with validated enums and a prerequisites-only role picker"
```

---

### Task 9: Curriculum builder UI (jquery-ui-sortable)

**Files:**
- Create: `anchor-courses/assets/admin-curriculum.js`
- Modify: `anchor-courses/src/Admin/CourseEditor.php` - second metabox, curriculum save at priority 11, AJAX search/create, script enqueue
- Test: `tests/test-courses-curriculum-builder.php`

**Interfaces:**
- Consumes: `Content\Curriculum::get()/save()`, `Content\LessonPostType::CPT`, `Content\QuizPostType::CPT`.
- Produces:
  - `CourseEditor::render_curriculum( \WP_Post $post ): void`
  - `CourseEditor::save_curriculum( int $post_id ): void` (hooked at priority 11)
  - `CourseEditor::search_items( string $term, string $type ): array` -> `[{id:int,title:string,type:string}]`
  - `CourseEditor::create_item( string $title, string $type ): array` -> `{id,title,type,edit_url}` or `[]`
  - `CourseEditor::ajax_search_items(): void` (`wp_ajax_anchor_courses_search_items`)
  - `CourseEditor::ajax_create_item(): void` (`wp_ajax_anchor_courses_create_item`)
  - JS global `anchorCoursesCurriculum` = `{ajaxUrl, nonce, strings}`

The builder posts one hidden field, `anchor_course_curriculum`, holding JSON. `save_curriculum()` decodes it and **returns without writing on a decode failure**, so a broken editor can never wipe an authored curriculum.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - curriculum builder save path and its AJAX helpers.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\Curriculum;

/** @group courses */
class Test_Courses_Curriculum_Builder extends Anchor_Courses_TestCase {

	private function submit( int $course_id, string $json ): void {
		$_POST = [
			CourseEditor::NONCE        => wp_create_nonce( CourseEditor::NONCE ),
			'anchor_course_curriculum' => wp_slash( $json ),
		];
		( new CourseEditor() )->save_curriculum( $course_id );
		$_POST = [];
	}

	public function test_posted_json_becomes_a_canonical_curriculum() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course();
		$lesson = $this->make_lesson();
		$quiz   = $this->make_quiz();

		$this->submit(
			$course,
			wp_json_encode(
				[ [ 'title' => 'Module 1', 'items' => [
					[ 'type' => 'lesson', 'id' => $lesson ],
					[ 'type' => 'quiz', 'id' => $quiz ],
				] ] ]
			)
		);

		$modules = Curriculum::get( $course );
		$this->assertCount( 1, $modules );
		$this->assertSame( [ $lesson, $quiz ], array_column( $modules[0]['items'], 'id' ) );
		$this->assertNotSame( '', $modules[0]['id'] );
	}

	public function test_malformed_json_leaves_the_existing_curriculum_alone() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course();
		$lesson = $this->make_lesson();
		Curriculum::save( $course, [ [ 'title' => 'Keep me', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );

		$this->submit( $course, '{not json' );

		$this->assertSame( 'Keep me', Curriculum::get( $course )[0]['title'] );
	}

	public function test_a_learner_cannot_rewrite_the_curriculum() {
		$course = $this->make_course();
		$lesson = $this->make_lesson();
		Curriculum::save( $course, [ [ 'title' => 'Keep me', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );

		wp_set_current_user( $this->make_learner() );
		$this->submit( $course, wp_json_encode( [ [ 'title' => 'Hijacked', 'items' => [] ] ] ) );

		$this->assertSame( 'Keep me', Curriculum::get( $course )[0]['title'] );
	}

	public function test_search_returns_matching_lessons_only_for_the_lesson_type() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->make_lesson( [], 'Anatomy Basics' );
		$this->make_quiz( [], 'Anatomy Quiz' );

		$results = CourseEditor::search_items( 'Anatomy', 'lesson' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Anatomy Basics', $results[0]['title'] );
		$this->assertSame( 'lesson', $results[0]['type'] );
	}

	public function test_create_item_makes_a_draft_of_the_requested_type() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$created = CourseEditor::create_item( 'New Quiz', 'quiz' );

		$this->assertSame( 'anchor_quiz', get_post_type( $created['id'] ) );
		$this->assertSame( 'draft', get_post_status( $created['id'] ) );
		$this->assertStringContainsString( 'post.php', $created['edit_url'] );
	}

	public function test_create_item_refuses_an_unknown_type() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( [], CourseEditor::create_item( 'Nope', 'video' ) );
	}

	public function test_builder_script_is_registered_with_the_sortable_dependency() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'anchor_course' );
		$GLOBALS['post'] = get_post( $this->make_course() );
		( new CourseEditor() )->assets( 'post.php' );

		$script = wp_scripts()->registered['anchor-courses-curriculum'] ?? null;
		$this->assertNotNull( $script, 'The curriculum builder script was not enqueued.' );
		$this->assertContains( 'jquery-ui-sortable', $script->deps );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Curriculum_Builder
```
Expected: FAIL - `Call to undefined method ...CourseEditor::save_curriculum()`.

- [ ] **Step 3: Write minimal implementation**

Add these imports at the top of `anchor-courses/src/Admin/CourseEditor.php`:

```php
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Content\QuizPostType;
```

Add to the constructor:

```php
		\add_action( 'save_post_' . CoursePostType::CPT, [ $this, 'save_curriculum' ], 11 );
		\add_action( 'wp_ajax_anchor_courses_search_items', [ $this, 'ajax_search_items' ] );
		\add_action( 'wp_ajax_anchor_courses_create_item', [ $this, 'ajax_create_item' ] );
```

Add to `add_metaboxes()`:

```php
		\add_meta_box(
			'anchor_courses_curriculum',
			\__( 'Curriculum', 'anchor-schema' ),
			[ $this, 'render_curriculum' ],
			CoursePostType::CPT,
			'normal',
			'high'
		);
```

Add these methods:

```php
	public function render_curriculum( \WP_Post $post ): void {
		$modules = Curriculum::get( (int) $post->ID );

		echo '<div class="anchor-courses-curriculum" data-course="' . \esc_attr( (string) $post->ID ) . '">';
		echo '<ul class="anchor-courses-modules"></ul>';
		\printf(
			'<p><button type="button" class="button anchor-courses-add-module">%s</button></p>',
			\esc_html__( 'Add module', 'anchor-schema' )
		);
		\printf(
			'<input type="hidden" name="anchor_course_curriculum" class="anchor-courses-curriculum-data" value="%s" />',
			\esc_attr( (string) \wp_json_encode( $this->decorate( $modules ) ) )
		);
		echo '<noscript><p>' . \esc_html__( 'The curriculum builder needs JavaScript. Existing curriculum is preserved.', 'anchor-schema' ) . '</p></noscript>';
		echo '</div>';
	}

	/** Attach display titles so the builder renders without a second request. */
	private function decorate( array $modules ): array {
		foreach ( $modules as $m => $module ) {
			foreach ( $module['items'] as $i => $item ) {
				$modules[ $m ]['items'][ $i ]['title'] = (string) \get_the_title( $item['id'] );
			}
		}
		return $modules;
	}

	public function save_curriculum( int $post_id ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE ] ) ? \sanitize_text_field( \wp_unslash( (string) $_POST[ self::NONCE ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! \current_user_can( Capabilities::cap( 'edit_courses' ) ) ) {
			return;
		}
		if ( ! isset( $_POST['anchor_course_curriculum'] ) ) {
			return;
		}

		$decoded = \json_decode( \wp_unslash( (string) $_POST['anchor_course_curriculum'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// A decode failure means a broken editor, not "the author deleted everything".
		if ( ! \is_array( $decoded ) ) {
			return;
		}

		Curriculum::save( $post_id, $decoded );
	}

	/** @return array<int,array{id:int,title:string,type:string}> */
	public static function search_items( string $term, string $type ): array {
		$map = [ 'lesson' => LessonPostType::CPT, 'quiz' => QuizPostType::CPT ];
		if ( ! isset( $map[ $type ] ) ) {
			return [];
		}

		$posts = \get_posts(
			[
				'post_type'      => $map[ $type ],
				'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
				's'              => $term,
				'posts_per_page' => 20,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		return \array_map(
			static fn( \WP_Post $p ): array => [
				'id'    => (int) $p->ID,
				'title' => (string) $p->post_title,
				'type'  => $type,
			],
			$posts
		);
	}

	/** @return array{id:int,title:string,type:string,edit_url:string}|array{} */
	public static function create_item( string $title, string $type ): array {
		$map = [ 'lesson' => LessonPostType::CPT, 'quiz' => QuizPostType::CPT ];
		if ( ! isset( $map[ $type ] ) ) {
			return [];
		}
		$cap = 'quiz' === $type ? 'edit_quizzes' : 'edit_lessons';
		if ( ! \current_user_can( Capabilities::cap( $cap ) ) ) {
			return [];
		}

		$title = \sanitize_text_field( $title );
		$id    = \wp_insert_post(
			[
				'post_type'   => $map[ $type ],
				'post_status' => 'draft',
				'post_title'  => '' === $title ? \__( 'Untitled', 'anchor-schema' ) : $title,
			],
			true
		);
		if ( \is_wp_error( $id ) ) {
			return [];
		}

		return [
			'id'       => (int) $id,
			'title'    => (string) \get_the_title( (int) $id ),
			'type'     => $type,
			'edit_url' => (string) \get_edit_post_link( (int) $id, 'raw' ),
		];
	}

	public function ajax_search_items(): void {
		\check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! \current_user_can( Capabilities::cap( 'edit_courses' ) ) ) {
			\wp_send_json_error( [ 'message' => \__( 'Not allowed.', 'anchor-schema' ) ], 403 );
		}
		$term = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['term'] ?? '' ) ) );
		$type = \sanitize_key( \wp_unslash( (string) ( $_REQUEST['type'] ?? 'lesson' ) ) );
		\wp_send_json_success( self::search_items( $term, $type ) );
	}

	public function ajax_create_item(): void {
		\check_ajax_referer( self::NONCE, 'nonce' );
		$title  = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['title'] ?? '' ) ) );
		$type   = \sanitize_key( \wp_unslash( (string) ( $_REQUEST['type'] ?? '' ) ) );
		$result = self::create_item( $title, $type );
		if ( [] === $result ) {
			\wp_send_json_error( [ 'message' => \__( 'Could not create that item.', 'anchor-schema' ) ], 400 );
		}
		\wp_send_json_success( $result );
	}
```

Append to `assets()` (after the stylesheet enqueue):

```php
		\wp_enqueue_script(
			'anchor-courses-curriculum',
			Module::assets_url() . 'admin-curriculum.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			Module::VERSION,
			true
		);
		\wp_localize_script(
			'anchor-courses-curriculum',
			'anchorCoursesCurriculum',
			[
				'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
				'nonce'   => \wp_create_nonce( self::NONCE ),
				'strings' => [
					'newModule'     => \__( 'New module', 'anchor-schema' ),
					'moduleTitle'   => \__( 'Module title', 'anchor-schema' ),
					'removeModule'  => \__( 'Remove module', 'anchor-schema' ),
					'removeItem'    => \__( 'Remove', 'anchor-schema' ),
					'addLesson'     => \__( 'Add lesson', 'anchor-schema' ),
					'addQuiz'       => \__( 'Add quiz', 'anchor-schema' ),
					'createLesson'  => \__( 'Create lesson', 'anchor-schema' ),
					'createQuiz'    => \__( 'Create quiz', 'anchor-schema' ),
					'search'        => \__( 'Search by title...', 'anchor-schema' ),
					'required'      => \__( 'Required', 'anchor-schema' ),
					'noResults'     => \__( 'No matches.', 'anchor-schema' ),
					'confirmModule' => \__( 'Remove this module? The lessons and quizzes themselves are not deleted.', 'anchor-schema' ),
				],
			]
		);
```

`anchor-courses/assets/admin-curriculum.js`:

```javascript
/**
 * Anchor Courses - curriculum builder.
 *
 * Drag-and-drop modules and items with jquery-ui-sortable. The whole curriculum
 * is serialised into one hidden JSON field on every change, so the PHP save
 * handler has exactly one input to validate. Module UUIDs round-trip untouched;
 * new modules post an empty id and PHP mints the UUID.
 */
(function ($) {
    'use strict';

    var cfg = window.anchorCoursesCurriculum || {};
    var S = cfg.strings || {};

    function esc(text) {
        return $('<div/>').text(text == null ? '' : String(text)).html();
    }

    function itemMarkup(item) {
        return '' +
            '<li class="anchor-courses-item" data-type="' + esc(item.type) + '" data-id="' + esc(item.id) + '">' +
            '<span class="anchor-courses-item-handle dashicons dashicons-menu"></span>' +
            '<span class="anchor-courses-item-type">' + esc(item.type) + '</span>' +
            '<span class="anchor-courses-item-title">' + esc(item.title || ('#' + item.id)) + '</span>' +
            '<label><input type="checkbox" class="anchor-courses-item-required"' +
            (item.required === false ? '' : ' checked="checked"') + ' /> ' + esc(S.required) + '</label>' +
            '<button type="button" class="button-link anchor-courses-remove-item">' + esc(S.removeItem) + '</button>' +
            '</li>';
    }

    function moduleMarkup(module) {
        var items = (module.items || []).map(itemMarkup).join('');
        return '' +
            '<li class="anchor-courses-module" data-uuid="' + esc(module.id || '') + '">' +
            '<div class="anchor-courses-module-head">' +
            '<span class="anchor-courses-module-handle dashicons dashicons-menu"></span>' +
            '<input type="text" class="anchor-courses-module-title regular-text" value="' + esc(module.title) +
            '" placeholder="' + esc(S.moduleTitle) + '" />' +
            '<button type="button" class="button-link anchor-courses-remove-module">' + esc(S.removeModule) + '</button>' +
            '</div>' +
            '<ul class="anchor-courses-items">' + items + '</ul>' +
            '<p class="anchor-courses-module-actions">' +
            '<input type="text" class="anchor-courses-search" placeholder="' + esc(S.search) + '" />' +
            '<button type="button" class="button anchor-courses-add" data-type="lesson">' + esc(S.addLesson) + '</button> ' +
            '<button type="button" class="button anchor-courses-add" data-type="quiz">' + esc(S.addQuiz) + '</button> ' +
            '<button type="button" class="button anchor-courses-create" data-type="lesson">' + esc(S.createLesson) + '</button> ' +
            '<button type="button" class="button anchor-courses-create" data-type="quiz">' + esc(S.createQuiz) + '</button>' +
            '<span class="anchor-courses-results"></span>' +
            '</p>' +
            '</li>';
    }

    function serialize($root) {
        var modules = [];
        $root.find('.anchor-courses-module').each(function () {
            var $m = $(this);
            var items = [];
            $m.find('.anchor-courses-item').each(function () {
                var $i = $(this);
                items.push({
                    type: $i.data('type'),
                    id: parseInt($i.data('id'), 10),
                    required: $i.find('.anchor-courses-item-required').is(':checked'),
                    title: $i.find('.anchor-courses-item-title').text()
                });
            });
            modules.push({
                id: $m.attr('data-uuid') || '',
                title: $m.find('.anchor-courses-module-title').val(),
                description: '',
                items: items
            });
        });
        $root.find('.anchor-courses-curriculum-data').val(JSON.stringify(modules));
    }

    function bindSortables($root) {
        $root.find('.anchor-courses-modules').sortable({
            handle: '.anchor-courses-module-handle',
            update: function () { serialize($root); }
        });
        $root.find('.anchor-courses-items').sortable({
            handle: '.anchor-courses-item-handle',
            connectWith: '.anchor-courses-items',
            placeholder: 'anchor-courses-item',
            update: function () { serialize($root); }
        });
    }

    $(function () {
        var $root = $('.anchor-courses-curriculum');
        if (!$root.length) { return; }

        var initial = [];
        try { initial = JSON.parse($root.find('.anchor-courses-curriculum-data').val() || '[]'); } catch (e) { initial = []; }
        $root.find('.anchor-courses-modules').html(initial.map(moduleMarkup).join(''));
        bindSortables($root);

        $root.on('click', '.anchor-courses-add-module', function () {
            $root.find('.anchor-courses-modules').append(moduleMarkup({ id: '', title: S.newModule, items: [] }));
            bindSortables($root);
            serialize($root);
        });

        $root.on('click', '.anchor-courses-remove-module', function () {
            if (!window.confirm(S.confirmModule)) { return; }
            $(this).closest('.anchor-courses-module').remove();
            serialize($root);
        });

        $root.on('click', '.anchor-courses-remove-item', function () {
            $(this).closest('.anchor-courses-item').remove();
            serialize($root);
        });

        $root.on('change keyup', '.anchor-courses-module-title, .anchor-courses-item-required', function () {
            serialize($root);
        });

        $root.on('click', '.anchor-courses-add', function () {
            var $btn = $(this);
            var $module = $btn.closest('.anchor-courses-module');
            var $out = $module.find('.anchor-courses-results');
            $.post(cfg.ajaxUrl, {
                action: 'anchor_courses_search_items',
                nonce: cfg.nonce,
                type: $btn.data('type'),
                term: $module.find('.anchor-courses-search').val()
            }).done(function (res) {
                if (!res || !res.success || !res.data.length) { $out.text(S.noResults); return; }
                $out.html(res.data.map(function (row) {
                    return '<button type="button" class="button-link anchor-courses-pick" data-type="' +
                        esc(row.type) + '" data-id="' + esc(row.id) + '">' + esc(row.title) + '</button>';
                }).join(' - '));
            });
        });

        $root.on('click', '.anchor-courses-pick', function () {
            var $btn = $(this);
            $btn.closest('.anchor-courses-module').find('.anchor-courses-items').append(itemMarkup({
                type: $btn.data('type'), id: $btn.data('id'), title: $btn.text(), required: true
            }));
            $btn.closest('.anchor-courses-results').empty();
            bindSortables($root);
            serialize($root);
        });

        $root.on('click', '.anchor-courses-create', function () {
            var $btn = $(this);
            var $module = $btn.closest('.anchor-courses-module');
            $.post(cfg.ajaxUrl, {
                action: 'anchor_courses_create_item',
                nonce: cfg.nonce,
                type: $btn.data('type'),
                title: $module.find('.anchor-courses-search').val()
            }).done(function (res) {
                if (!res || !res.success) { return; }
                $module.find('.anchor-courses-items').append(itemMarkup({
                    type: res.data.type, id: res.data.id, title: res.data.title, required: true
                }));
                bindSortables($root);
                serialize($root);
                window.open(res.data.edit_url, '_blank');
            });
        });
    });
})(jQuery);
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Curriculum_Builder
```
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Admin/CourseEditor.php anchor-courses/assets/admin-curriculum.js tests/test-courses-curriculum-builder.php
git commit -m "feat(courses): drag-and-drop curriculum builder on jquery-ui-sortable"
```

---

### Task 10: Lesson metabox

**Files:**
- Create: `anchor-courses/src/Admin/LessonEditor.php`
- Modify: `anchor-courses/anchor-courses.php` - construct when `is_admin()`
- Test: `tests/test-courses-lesson-editor.php`

**Interfaces:**
- Consumes: `LessonPostType::COMPLETION_MODES/TYPES/meta_key()`, `QuizPostType::CPT`, `Capabilities::cap( 'edit_lessons' )`.
- Produces:
  - `Admin\LessonEditor::NONCE = 'anchor_courses_lesson_nonce'`
  - `Admin\LessonEditor::defaults(): array` = `['completion_mode'=>'manual','required'=>1,'quiz_id'=>0,'type'=>'content','event_id'=>0,'session_index'=>0,'require_prior_items'=>0]`
  - `Admin\LessonEditor::setting( int $lesson_id, string $key ): mixed`
  - `Admin\LessonEditor::add_metaboxes(): void`, `render( \WP_Post $post ): void`, `save( int $post_id ): void`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - lesson settings (brief section 9, design spec 3.3).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\LessonEditor;

/** @group courses */
class Test_Courses_Lesson_Editor extends Anchor_Courses_TestCase {

	private function submit( int $lesson_id, array $fields ): void {
		$_POST = [
			LessonEditor::NONCE => wp_create_nonce( LessonEditor::NONCE ),
			'anchor_lesson'     => $fields,
		];
		( new LessonEditor() )->save( $lesson_id );
		$_POST = [];
	}

	public function test_defaults() {
		$d = LessonEditor::defaults();
		$this->assertSame( 'manual', $d['completion_mode'] );
		$this->assertSame( 'content', $d['type'] );
		$this->assertSame( 1, $d['required'] );
	}

	public function test_save_persists_completion_mode_and_quiz_link() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();
		$quiz   = $this->make_quiz();

		$this->submit( $lesson, [ 'completion_mode' => 'quiz_pass', 'quiz_id' => (string) $quiz, 'required' => '1' ] );

		$this->assertSame( 'quiz_pass', get_post_meta( $lesson, '_anchor_lesson_completion_mode', true ) );
		$this->assertSame( $quiz, (int) get_post_meta( $lesson, '_anchor_lesson_quiz_id', true ) );
	}

	public function test_quiz_id_must_reference_a_real_quiz() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson     = $this->make_lesson();
		$not_a_quiz = $this->make_lesson();

		$this->submit( $lesson, [ 'quiz_id' => (string) $not_a_quiz ] );

		$this->assertSame( 0, (int) get_post_meta( $lesson, '_anchor_lesson_quiz_id', true ) );
	}

	public function test_unknown_completion_mode_falls_back_to_manual() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();
		$this->submit( $lesson, [ 'completion_mode' => 'video_percentage' ] );
		$this->assertSame( 'manual', get_post_meta( $lesson, '_anchor_lesson_completion_mode', true ) );
	}

	public function test_live_session_fields_persist() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$lesson = $this->make_lesson();

		$this->submit( $lesson, [ 'type' => 'live_session', 'event_id' => '42', 'session_index' => '1', 'require_prior_items' => '1' ] );

		$this->assertSame( 'live_session', get_post_meta( $lesson, '_anchor_lesson_type', true ) );
		$this->assertSame( 42, (int) get_post_meta( $lesson, '_anchor_lesson_event_id', true ) );
		$this->assertSame( 1, (int) get_post_meta( $lesson, '_anchor_lesson_session_index', true ) );
		$this->assertSame( 1, (int) get_post_meta( $lesson, '_anchor_lesson_require_prior_items', true ) );
	}

	public function test_save_requires_the_lesson_capability() {
		$lesson = $this->make_lesson( [ 'completion_mode' => 'view' ] );
		wp_set_current_user( $this->make_learner() );
		$this->submit( $lesson, [ 'completion_mode' => 'manual' ] );
		$this->assertSame( 'view', get_post_meta( $lesson, '_anchor_lesson_completion_mode', true ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Lesson_Editor
```
Expected: FAIL - `Class "Anchor\Courses\Admin\LessonEditor" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Admin/LessonEditor.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Content\QuizPostType;
use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Lesson settings metabox: completion mode (brief 9) and live-session fields (design spec 3.3). */
final class LessonEditor {

	public const NONCE = 'anchor_courses_lesson_nonce';

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
		\add_action( 'save_post_' . LessonPostType::CPT, [ $this, 'save' ] );
	}

	public static function defaults(): array {
		return [
			'completion_mode'     => 'manual',
			'required'            => 1,
			'quiz_id'             => 0,
			'type'                => 'content',
			'event_id'            => 0,
			'session_index'       => 0,
			'require_prior_items' => 0,
		];
	}

	/** @return mixed */
	public static function setting( int $lesson_id, string $key ) {
		$defaults = self::defaults();
		$stored   = \get_post_meta( $lesson_id, LessonPostType::meta_key( $key ), true );
		return ( '' === $stored || null === $stored ) ? ( $defaults[ $key ] ?? '' ) : $stored;
	}

	public function add_metaboxes(): void {
		\add_meta_box(
			'anchor_courses_lesson',
			\__( 'Lesson Settings', 'anchor-schema' ),
			[ $this, 'render' ],
			LessonPostType::CPT,
			'side',
			'high'
		);
	}

	public function render( \WP_Post $post ): void {
		\wp_nonce_field( self::NONCE, self::NONCE );
		$id = (int) $post->ID;

		$this->select(
			'type',
			\__( 'Lesson type', 'anchor-schema' ),
			[ 'content' => \__( 'Content', 'anchor-schema' ), 'live_session' => \__( 'Live session', 'anchor-schema' ) ],
			(string) self::setting( $id, 'type' )
		);

		$this->select(
			'completion_mode',
			\__( 'Completion', 'anchor-schema' ),
			[
				'manual'    => \__( 'Learner marks complete', 'anchor-schema' ),
				'view'      => \__( 'Complete on view', 'anchor-schema' ),
				'quiz_pass' => \__( 'Complete when its quiz passes', 'anchor-schema' ),
			],
			(string) self::setting( $id, 'completion_mode' )
		);

		echo '<p><label><strong>' . \esc_html__( 'Quiz', 'anchor-schema' ) . '</strong><br />';
		echo '<select name="anchor_lesson[quiz_id]"><option value="0">'
			. \esc_html__( '- none -', 'anchor-schema' ) . '</option>';
		$quizzes = \get_posts(
			[
				'post_type'      => QuizPostType::CPT,
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);
		foreach ( $quizzes as $quiz ) {
			\printf(
				'<option value="%d"%s>%s</option>',
				(int) $quiz->ID,
				\selected( (int) self::setting( $id, 'quiz_id' ), (int) $quiz->ID, false ),
				\esc_html( (string) $quiz->post_title )
			);
		}
		echo '</select></label></p>';

		\printf(
			'<p><label><input type="checkbox" name="anchor_lesson[required]" value="1"%s /> %s</label></p>',
			\checked( (int) self::setting( $id, 'required' ), 1, false ),
			\esc_html__( 'Required for course completion', 'anchor-schema' )
		);

		echo '<h4>' . \esc_html__( 'Live session', 'anchor-schema' ) . '</h4>';
		\printf(
			'<p><label>%s<br /><input type="number" min="0" name="anchor_lesson[event_id]" value="%d" class="small-text" /></label></p>',
			\esc_html__( 'Event ID', 'anchor-schema' ),
			(int) self::setting( $id, 'event_id' )
		);
		\printf(
			'<p><label>%s<br /><input type="number" min="0" name="anchor_lesson[session_index]" value="%d" class="small-text" /></label></p>',
			\esc_html__( 'Session index', 'anchor-schema' ),
			(int) self::setting( $id, 'session_index' )
		);
		\printf(
			'<p><label><input type="checkbox" name="anchor_lesson[require_prior_items]" value="1"%s /> %s</label></p>',
			\checked( (int) self::setting( $id, 'require_prior_items' ), 1, false ),
			\esc_html__( 'Block stream access until earlier items are complete', 'anchor-schema' )
		);
	}

	private function select( string $key, string $label, array $options, string $current ): void {
		\printf(
			'<p><label><strong>%s</strong><br /><select name="anchor_lesson[%s]">',
			\esc_html( $label ),
			\esc_attr( $key )
		);
		foreach ( $options as $value => $text ) {
			\printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( (string) $value ),
				\selected( $current, (string) $value, false ),
				\esc_html( (string) $text )
			);
		}
		echo '</select></label></p>';
	}

	public function save( int $post_id ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE ] ) ? \sanitize_text_field( \wp_unslash( (string) $_POST[ self::NONCE ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! \current_user_can( Capabilities::cap( 'edit_lessons' ) ) ) {
			return;
		}

		$input = isset( $_POST['anchor_lesson'] ) && \is_array( $_POST['anchor_lesson'] )
			? \wp_unslash( $_POST['anchor_lesson'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: [];

		$mode = \sanitize_key( (string) ( $input['completion_mode'] ?? '' ) );
		if ( ! \in_array( $mode, LessonPostType::COMPLETION_MODES, true ) ) {
			$mode = 'manual';
		}

		$type = \sanitize_key( (string) ( $input['type'] ?? '' ) );
		if ( ! \in_array( $type, LessonPostType::TYPES, true ) ) {
			$type = 'content';
		}

		// A quiz link must name a real quiz post; anything else stores 0.
		$quiz_id = \absint( $input['quiz_id'] ?? 0 );
		if ( $quiz_id > 0 && QuizPostType::CPT !== \get_post_type( $quiz_id ) ) {
			$quiz_id = 0;
		}

		$values = [
			'completion_mode'     => $mode,
			'type'                => $type,
			'quiz_id'             => $quiz_id,
			'required'            => empty( $input['required'] ) ? 0 : 1,
			'event_id'            => \absint( $input['event_id'] ?? 0 ),
			'session_index'       => \absint( $input['session_index'] ?? 0 ),
			'require_prior_items' => empty( $input['require_prior_items'] ) ? 0 : 1,
		];

		foreach ( $values as $key => $value ) {
			\update_post_meta( $post_id, LessonPostType::meta_key( $key ), $value );
		}
	}
}
```

`anchor-courses/anchor-courses.php` - inside the `is_admin()` branch add `new Admin\LessonEditor();`.

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Lesson_Editor
```
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Admin/LessonEditor.php anchor-courses/anchor-courses.php tests/test-courses-lesson-editor.php
git commit -m "feat(courses): lesson settings metabox with completion modes and live-session fields"
```

---

### Task 11: Quiz settings metabox

**Files:**
- Create: `anchor-courses/src/Admin/QuizEditor.php`
- Modify: `anchor-courses/anchor-courses.php` - construct when `is_admin()`
- Test: `tests/test-courses-quiz-settings.php`

**Interfaces:**
- Produces:
  - `Admin\QuizEditor::NONCE = 'anchor_courses_quiz_nonce'`
  - `Admin\QuizEditor::TIMER_POLICIES = ['auto_submit','expire']`
  - `Admin\QuizEditor::defaults(): array` - brief 8.1 keys in order, plus `on_timer_expiry => 'auto_submit'`
  - `Admin\QuizEditor::settings( int $quiz_id ): array`
  - `Admin\QuizEditor::sanitize_settings( array $input ): array`
  - `Admin\QuizEditor::add_metaboxes(): void`, `render_settings( \WP_Post $post ): void`, `save( int $post_id ): void`

`on_timer_expiry` goes beyond brief 8.1's list because brief 8.5 says "Choose behavior per quiz setting. Default recommendation: auto-submit saved answers" - the setting has to exist for the rule to be expressible.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - quiz configuration (brief sections 8.1, 8.5).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\QuizEditor;

/** @group courses */
class Test_Courses_Quiz_Settings extends Anchor_Courses_TestCase {

	private function submit( int $quiz_id, array $fields ): void {
		$_POST = [ QuizEditor::NONCE => wp_create_nonce( QuizEditor::NONCE ), 'anchor_quiz' => $fields ];
		( new QuizEditor() )->save( $quiz_id );
		$_POST = [];
	}

	public function test_defaults_cover_every_brief_setting() {
		$this->assertSame(
			[ 'passing_score', 'max_attempts', 'time_limit_seconds', 'shuffle_questions', 'shuffle_answers',
			  'show_correct_answers', 'show_score', 'allow_review', 'retry_delay_seconds', 'required',
			  'on_timer_expiry' ],
			array_keys( QuizEditor::defaults() )
		);
		$this->assertSame( 'auto_submit', QuizEditor::defaults()['on_timer_expiry'] );
		$this->assertSame( 0, QuizEditor::defaults()['max_attempts'], '0 means unlimited (brief 8.1).' );
	}

	public function test_settings_merge_stored_values_over_defaults() {
		$quiz = $this->make_quiz( [ 'settings' => [ 'passing_score' => 60 ] ] );
		$s    = QuizEditor::settings( $quiz );
		$this->assertSame( 60, $s['passing_score'] );
		$this->assertSame( 1, $s['show_score'] );
	}

	public function test_save_persists_and_clamps() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();

		$this->submit(
			$quiz,
			[ 'passing_score' => '150', 'max_attempts' => '2', 'time_limit_seconds' => '600',
			  'shuffle_questions' => '1', 'retry_delay_seconds' => '3600', 'on_timer_expiry' => 'expire' ]
		);

		$s = QuizEditor::settings( $quiz );
		$this->assertSame( 100, $s['passing_score'], 'passing_score is a percentage, clamped to 1..100.' );
		$this->assertSame( 2, $s['max_attempts'] );
		$this->assertSame( 600, $s['time_limit_seconds'] );
		$this->assertSame( 1, $s['shuffle_questions'] );
		$this->assertSame( 3600, $s['retry_delay_seconds'] );
		$this->assertSame( 'expire', $s['on_timer_expiry'] );
	}

	public function test_unknown_timer_policy_falls_back_to_auto_submit() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();
		$this->submit( $quiz, [ 'on_timer_expiry' => 'explode' ] );
		$this->assertSame( 'auto_submit', QuizEditor::settings( $quiz )['on_timer_expiry'] );
	}

	public function test_unchecked_boxes_store_zero_not_the_default() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();
		$this->submit( $quiz, [ 'passing_score' => '80' ] ); // no checkboxes posted at all
		$s = QuizEditor::settings( $quiz );
		$this->assertSame( 0, $s['show_correct_answers'] );
		$this->assertSame( 0, $s['allow_review'] );
	}

	public function test_save_requires_the_quiz_capability() {
		$quiz = $this->make_quiz( [ 'settings' => [ 'passing_score' => 70 ] ] );
		wp_set_current_user( $this->make_learner() );
		$this->submit( $quiz, [ 'passing_score' => '1' ] );
		$this->assertSame( 70, QuizEditor::settings( $quiz )['passing_score'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Quiz_Settings
```
Expected: FAIL - `Class "Anchor\Courses\Admin\QuizEditor" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Admin/QuizEditor.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\QuizPostType;
use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Quiz configuration (brief 8.1) and timer-expiry policy (brief 8.5). */
final class QuizEditor {

	public const NONCE = 'anchor_courses_quiz_nonce';

	/** Integer settings and their inclusive clamp range. */
	private const INT_RANGES = [
		'passing_score'       => [ 1, 100 ],
		'max_attempts'        => [ 0, 1000 ],     // 0 = unlimited
		'time_limit_seconds'  => [ 0, 86400 ],    // 0 = untimed
		'retry_delay_seconds' => [ 0, 2592000 ],  // 0 = retry immediately
	];

	private const BOOLS = [
		'shuffle_questions', 'shuffle_answers', 'show_correct_answers',
		'show_score', 'allow_review', 'required',
	];

	public const TIMER_POLICIES = [ 'auto_submit', 'expire' ];

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
		\add_action( 'save_post_' . QuizPostType::CPT, [ $this, 'save' ] );
	}

	public static function defaults(): array {
		return [
			'passing_score'        => 80,
			'max_attempts'         => 0,
			'time_limit_seconds'   => 0,
			'shuffle_questions'    => 0,
			'shuffle_answers'      => 0,
			'show_correct_answers' => 1,
			'show_score'           => 1,
			'allow_review'         => 1,
			'retry_delay_seconds'  => 0,
			'required'             => 1,
			'on_timer_expiry'      => 'auto_submit',
		];
	}

	public static function settings( int $quiz_id ): array {
		$stored = \get_post_meta( $quiz_id, QuizPostType::meta_key( 'settings' ), true );
		return self::coerce( \array_merge( self::defaults(), \is_array( $stored ) ? $stored : [] ) );
	}

	/** Force stored values back into their declared types. */
	private static function coerce( array $s ): array {
		foreach ( \array_keys( self::INT_RANGES ) as $key ) {
			$s[ $key ] = (int) $s[ $key ];
		}
		foreach ( self::BOOLS as $key ) {
			$s[ $key ] = empty( $s[ $key ] ) ? 0 : 1;
		}
		$s['on_timer_expiry'] = \in_array( (string) $s['on_timer_expiry'], self::TIMER_POLICIES, true )
			? (string) $s['on_timer_expiry']
			: 'auto_submit';
		return $s;
	}

	public static function sanitize_settings( array $input ): array {
		$clean = [];

		foreach ( self::INT_RANGES as $key => $range ) {
			$value         = \absint( $input[ $key ] ?? self::defaults()[ $key ] );
			$clean[ $key ] = \max( $range[0], \min( $range[1], $value ) );
		}

		// Checkboxes: absent means off. Never fall back to the default here, or an
		// author could never turn one off.
		foreach ( self::BOOLS as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$policy                   = \sanitize_key( (string) ( $input['on_timer_expiry'] ?? '' ) );
		$clean['on_timer_expiry'] = \in_array( $policy, self::TIMER_POLICIES, true ) ? $policy : 'auto_submit';

		// Preserve declared key order so the stored array is diffable.
		$ordered = [];
		foreach ( \array_keys( self::defaults() ) as $key ) {
			$ordered[ $key ] = $clean[ $key ];
		}
		return $ordered;
	}

	public function add_metaboxes(): void {
		\add_meta_box(
			'anchor_courses_quiz_settings',
			\__( 'Quiz Settings', 'anchor-schema' ),
			[ $this, 'render_settings' ],
			QuizPostType::CPT,
			'side',
			'high'
		);
	}

	public function render_settings( \WP_Post $post ): void {
		\wp_nonce_field( self::NONCE, self::NONCE );
		$s = self::settings( (int) $post->ID );

		$numbers = [
			'passing_score'       => \__( 'Passing score (%)', 'anchor-schema' ),
			'max_attempts'        => \__( 'Max attempts (0 = unlimited)', 'anchor-schema' ),
			'time_limit_seconds'  => \__( 'Time limit in seconds (0 = untimed)', 'anchor-schema' ),
			'retry_delay_seconds' => \__( 'Wait between attempts, seconds', 'anchor-schema' ),
		];
		foreach ( $numbers as $key => $label ) {
			\printf(
				'<p><label>%1$s<br /><input type="number" min="0" name="anchor_quiz[%2$s]" value="%3$d" class="small-text" /></label></p>',
				\esc_html( $label ),
				\esc_attr( $key ),
				(int) $s[ $key ]
			);
		}

		$checks = [
			'shuffle_questions'    => \__( 'Shuffle questions', 'anchor-schema' ),
			'shuffle_answers'      => \__( 'Shuffle answers', 'anchor-schema' ),
			'show_correct_answers' => \__( 'Show correct answers after grading', 'anchor-schema' ),
			'show_score'           => \__( 'Show the score', 'anchor-schema' ),
			'allow_review'         => \__( 'Allow reviewing a graded attempt', 'anchor-schema' ),
			'required'             => \__( 'Required for course completion', 'anchor-schema' ),
		];
		foreach ( $checks as $key => $label ) {
			\printf(
				'<p><label><input type="checkbox" name="anchor_quiz[%1$s]" value="1"%2$s /> %3$s</label></p>',
				\esc_attr( $key ),
				\checked( (int) $s[ $key ], 1, false ),
				\esc_html( $label )
			);
		}

		echo '<p><label>' . \esc_html__( 'When the timer expires', 'anchor-schema' ) . '<br />';
		echo '<select name="anchor_quiz[on_timer_expiry]">';
		$policies = [
			'auto_submit' => \__( 'Auto-submit saved answers', 'anchor-schema' ),
			'expire'      => \__( 'Mark the attempt expired', 'anchor-schema' ),
		];
		foreach ( $policies as $value => $label ) {
			\printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( (string) $value ),
				\selected( (string) $s['on_timer_expiry'], (string) $value, false ),
				\esc_html( (string) $label )
			);
		}
		echo '</select></label></p>';
	}

	public function save( int $post_id ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE ] ) ? \sanitize_text_field( \wp_unslash( (string) $_POST[ self::NONCE ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! \current_user_can( Capabilities::cap( 'edit_quizzes' ) ) ) {
			return;
		}

		$input = isset( $_POST['anchor_quiz'] ) && \is_array( $_POST['anchor_quiz'] )
			? \wp_unslash( $_POST['anchor_quiz'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: [];

		\update_post_meta( $post_id, QuizPostType::meta_key( 'settings' ), self::sanitize_settings( $input ) );
	}
}
```

`anchor-courses/anchor-courses.php` - inside the `is_admin()` branch add `new Admin\QuizEditor();`.

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Quiz_Settings
```
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Admin/QuizEditor.php anchor-courses/anchor-courses.php tests/test-courses-quiz-settings.php
git commit -m "feat(courses): quiz settings metabox with clamped values and timer policy"
```

---

### Task 12: Quiz question editor

**Files:**
- Create: `anchor-courses/src/Content/Questions.php`
- Create: `anchor-courses/assets/admin-quiz.js`
- Modify: `anchor-courses/src/Admin/QuizEditor.php` - questions metabox, `save_questions()` at priority 11, `assets()`
- Test: `tests/unit/test-questions-sanitize.php` (pure), `tests/test-courses-quiz-editor.php` (integration)

**Interfaces:**
- Produces:
  - `Content\Questions::META = '_anchor_quiz_questions'`, `::TYPES = ['single_choice','multiple_choice','true_false']`
  - `Questions::sanitize( array $questions ): array` - **pure**. Shape `['id'=>uuid,'type'=>string,'prompt'=>string,'points'=>float,'answers'=>[['id'=>string,'text'=>string,'correct'=>bool]]]`
  - `Questions::get( int $quiz_id ): array`, `Questions::save( int $quiz_id, array $questions ): array`
  - `Questions::points_possible( int $quiz_id ): float`
  - `Questions::for_learner( array $questions, bool $shuffle_questions, bool $shuffle_answers, string $seed ): array` - **strips every `correct` key**, deterministic shuffle from `$seed`
  - `Admin\QuizEditor::render_questions( \WP_Post $post ): void`, `::save_questions( int $post_id ): void`, `::assets( string $hook ): void`
  - JS global `anchorCoursesQuiz` = `{strings}`

- [ ] **Step 1: Write the failing tests**

`tests/unit/test-questions-sanitize.php`:

```php
<?php
/**
 * Pure unit test: question sanitisation and learner projection (brief 8.3, 25).
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Content\Questions;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Questions extends TestCase {

	private function q( array $over = [] ): array {
		return array_merge(
			[
				'type'    => 'single_choice',
				'prompt'  => 'Which one?',
				'points'  => 1,
				'answers' => [
					[ 'text' => 'A', 'correct' => false ],
					[ 'text' => 'B', 'correct' => true ],
				],
			],
			$over
		);
	}

	public function test_questions_and_answers_get_stable_ids() {
		$out = Questions::sanitize( [ $this->q() ] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $out[0]['id'] );
		$this->assertNotSame( '', $out[0]['answers'][0]['id'] );
		$this->assertNotSame( $out[0]['answers'][0]['id'], $out[0]['answers'][1]['id'] );
	}

	public function test_existing_ids_are_preserved() {
		$first = Questions::sanitize( [ $this->q() ] );
		$again = Questions::sanitize( $first );
		$this->assertSame( $first[0]['id'], $again[0]['id'] );
		$this->assertSame( $first[0]['answers'][1]['id'], $again[0]['answers'][1]['id'] );
	}

	public function test_unknown_types_are_dropped() {
		$this->assertSame( [], Questions::sanitize( [ $this->q( [ 'type' => 'essay' ] ) ] ) );
		$this->assertSame( [], Questions::sanitize( [ $this->q( [ 'type' => 'matching' ] ) ] ) );
	}

	public function test_a_question_with_no_correct_answer_is_dropped() {
		$this->assertSame(
			[],
			Questions::sanitize( [ $this->q( [ 'answers' => [ [ 'text' => 'A', 'correct' => false ] ] ] ) ] )
		);
	}

	public function test_single_choice_keeps_only_the_first_correct_answer() {
		$out = Questions::sanitize(
			[ $this->q( [ 'answers' => [
				[ 'text' => 'A', 'correct' => true ],
				[ 'text' => 'B', 'correct' => true ],
			] ] ) ]
		);
		$this->assertTrue( $out[0]['answers'][0]['correct'] );
		$this->assertFalse( $out[0]['answers'][1]['correct'] );
	}

	public function test_multiple_choice_keeps_every_correct_answer() {
		$out = Questions::sanitize(
			[ $this->q( [ 'type' => 'multiple_choice', 'answers' => [
				[ 'text' => 'A', 'correct' => true ],
				[ 'text' => 'B', 'correct' => true ],
				[ 'text' => 'C', 'correct' => false ],
			] ] ) ]
		);
		$this->assertSame( [ true, true, false ], array_column( $out[0]['answers'], 'correct' ) );
	}

	public function test_true_false_is_normalised_to_exactly_two_answers() {
		$out = Questions::sanitize(
			[ $this->q( [ 'type' => 'true_false', 'answers' => [ [ 'text' => 'True', 'correct' => true ] ] ] ) ]
		);
		$this->assertCount( 2, $out[0]['answers'] );
		$this->assertSame( 'true', $out[0]['answers'][0]['id'] );
		$this->assertSame( 'false', $out[0]['answers'][1]['id'] );
		$this->assertTrue( $out[0]['answers'][0]['correct'] );
		$this->assertFalse( $out[0]['answers'][1]['correct'] );
	}

	public function test_true_false_can_mark_false_as_the_key() {
		$out = Questions::sanitize(
			[ $this->q( [ 'type' => 'true_false', 'answers' => [
				[ 'text' => 'True', 'correct' => false ],
				[ 'text' => 'False', 'correct' => true ],
			] ] ) ]
		);
		$this->assertFalse( $out[0]['answers'][0]['correct'] );
		$this->assertTrue( $out[0]['answers'][1]['correct'] );
	}

	public function test_points_default_to_one_and_are_never_negative() {
		$this->assertSame( 1.0, Questions::sanitize( [ $this->q( [ 'points' => null ] ) ] )[0]['points'] );
		$this->assertSame( 0.0, Questions::sanitize( [ $this->q( [ 'points' => -5 ] ) ] )[0]['points'] );
		$this->assertSame( 2.5, Questions::sanitize( [ $this->q( [ 'points' => '2.5' ] ) ] )[0]['points'] );
	}

	/** Brief 8.3 / 25: the single most important rule in the quiz engine. */
	public function test_for_learner_strips_every_correct_key() {
		$stored = Questions::sanitize( [ $this->q(), $this->q( [ 'prompt' => 'Second?' ] ) ] );

		$projected = Questions::for_learner( $stored, false, false, 'seed' );

		$this->assertStringNotContainsString( 'correct', (string) json_encode( $projected ) );
		$this->assertSame( 'Which one?', $projected[0]['prompt'] );
		$this->assertSame( $stored[0]['answers'][0]['id'], $projected[0]['answers'][0]['id'] );
	}

	public function test_for_learner_shuffle_is_deterministic_per_seed() {
		$stored = Questions::sanitize(
			array_map( fn( $n ) => $this->q( [ 'prompt' => "Q{$n}" ] ), range( 1, 8 ) )
		);

		$a = Questions::for_learner( $stored, true, true, 'attempt-7' );
		$b = Questions::for_learner( $stored, true, true, 'attempt-7' );
		$c = Questions::for_learner( $stored, true, true, 'attempt-8' );

		$this->assertSame( array_column( $a, 'id' ), array_column( $b, 'id' ), 'Same seed must reproduce the same order.' );
		$this->assertNotSame( array_column( $a, 'id' ), array_column( $c, 'id' ), 'A different attempt must shuffle differently.' );
		$this->assertCount( 8, $a );
	}
}
```

`tests/test-courses-quiz-editor.php`:

```php
<?php
/**
 * Anchor Courses - question editor persistence.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\QuizEditor;
use Anchor\Courses\Content\Questions;

/** @group courses */
class Test_Courses_Quiz_Editor extends Anchor_Courses_TestCase {

	private function tf( string $prompt ): array {
		return [
			'type' => 'true_false', 'prompt' => $prompt, 'points' => 1,
			'answers' => [ [ 'text' => 'True', 'correct' => true ], [ 'text' => 'False', 'correct' => false ] ],
		];
	}

	private function submit( int $quiz_id, $payload ): void {
		$_POST = [
			QuizEditor::NONCE       => wp_create_nonce( QuizEditor::NONCE ),
			'anchor_quiz_questions' => wp_slash( is_string( $payload ) ? $payload : (string) wp_json_encode( $payload ) ),
		];
		( new QuizEditor() )->save_questions( $quiz_id );
		$_POST = [];
	}

	public function test_posted_questions_round_trip() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();

		$this->submit(
			$quiz,
			[
				[ 'type' => 'single_choice', 'prompt' => 'Q1', 'points' => 1,
				  'answers' => [ [ 'text' => 'A', 'correct' => false ], [ 'text' => 'B', 'correct' => true ] ] ],
				array_merge( $this->tf( 'Q2' ), [ 'points' => 2 ] ),
			]
		);

		$stored = Questions::get( $quiz );
		$this->assertCount( 2, $stored );
		$this->assertSame( 'Q1', $stored[0]['prompt'] );
		$this->assertSame( 3.0, Questions::points_possible( $quiz ) );
	}

	public function test_malformed_json_leaves_questions_alone() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$quiz = $this->make_quiz();
		Questions::save( $quiz, [ $this->tf( 'Keep' ) ] );

		$this->submit( $quiz, '[[[' );

		$this->assertSame( 'Keep', Questions::get( $quiz )[0]['prompt'] );
	}

	public function test_a_learner_cannot_rewrite_questions() {
		$quiz = $this->make_quiz();
		Questions::save( $quiz, [ $this->tf( 'Keep' ) ] );

		wp_set_current_user( $this->make_learner() );
		$this->submit( $quiz, [ $this->tf( 'Hijack' ) ] );

		$this->assertSame( 'Keep', Questions::get( $quiz )[0]['prompt'] );
	}

	public function test_question_editor_script_depends_on_sortable() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'anchor_quiz' );
		$GLOBALS['post'] = get_post( $this->make_quiz() );
		( new QuizEditor() )->assets( 'post.php' );

		$script = wp_scripts()->registered['anchor-courses-quiz-admin'] ?? null;
		$this->assertNotNull( $script );
		$this->assertContains( 'jquery-ui-sortable', $script->deps );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Questions
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Quiz_Editor
```
Expected: both FAIL - `Class "Anchor\Courses\Content\Questions" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Content/Questions.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Content;

use Anchor\Courses\Support\Uuid;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Quiz questions: structured JSON in quiz post meta (brief 8.3).
 *
 * sanitize() and for_learner() are pure so the "never leak `correct`" rule is
 * unit-tested without WordPress. for_learner() is the ONLY projection a
 * learner-facing surface may use.
 */
final class Questions {

	public const META  = '_anchor_quiz_questions';
	public const TYPES = [ 'single_choice', 'multiple_choice', 'true_false' ];

	/** Canonicalise authored questions. Pure. */
	public static function sanitize( array $questions ): array {
		$clean = [];

		foreach ( $questions as $question ) {
			if ( ! \is_array( $question ) ) {
				continue;
			}
			$type = \sanitize_key( (string) ( $question['type'] ?? '' ) );
			if ( ! \in_array( $type, self::TYPES, true ) ) {
				continue;
			}

			$answers = self::sanitize_answers( $type, (array) ( $question['answers'] ?? [] ) );

			// A question nobody can get right is an authoring error, not content.
			$has_correct = false;
			foreach ( $answers as $answer ) {
				$has_correct = $has_correct || $answer['correct'];
			}
			if ( ! $has_correct ) {
				continue;
			}

			$id = (string) ( $question['id'] ?? '' );
			if ( ! \preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id ) ) {
				$id = Uuid::v4();
			}

			$points = $question['points'] ?? 1;
			$points = ( null === $points || '' === $points ) ? 1.0 : (float) $points;

			$clean[] = [
				'id'      => $id,
				'type'    => $type,
				'prompt'  => \wp_kses_post( (string) ( $question['prompt'] ?? '' ) ),
				'points'  => \max( 0.0, $points ),
				'answers' => $answers,
			];
		}

		return $clean;
	}

	private static function sanitize_answers( string $type, array $answers ): array {
		if ( 'true_false' === $type ) {
			// Always exactly two rows with fixed ids, so grading never depends on
			// authored order or wording.
			$true_is_key = true;
			foreach ( $answers as $answer ) {
				$text = \strtolower( \trim( (string) ( $answer['text'] ?? '' ) ) );
				if ( empty( $answer['correct'] ) ) {
					continue;
				}
				if ( \in_array( $text, [ 'false', 'no', '0' ], true ) ) {
					$true_is_key = false;
					break;
				}
				if ( \in_array( $text, [ 'true', 'yes', '1' ], true ) ) {
					$true_is_key = true;
					break;
				}
			}
			return [
				[ 'id' => 'true', 'text' => \__( 'True', 'anchor-schema' ), 'correct' => $true_is_key ],
				[ 'id' => 'false', 'text' => \__( 'False', 'anchor-schema' ), 'correct' => ! $true_is_key ],
			];
		}

		$clean        = [];
		$seen_correct = false;

		foreach ( $answers as $answer ) {
			if ( ! \is_array( $answer ) ) {
				continue;
			}
			$id = \sanitize_key( (string) ( $answer['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = 'a' . \substr( \str_replace( '-', '', Uuid::v4() ), 0, 12 );
			}
			$correct = ! empty( $answer['correct'] );

			// single_choice has exactly one key: keep the first correct row only.
			if ( 'single_choice' === $type && $correct ) {
				if ( $seen_correct ) {
					$correct = false;
				}
				$seen_correct = true;
			}

			$clean[] = [
				'id'      => $id,
				'text'    => \sanitize_text_field( (string) ( $answer['text'] ?? '' ) ),
				'correct' => $correct,
			];
		}

		return $clean;
	}

	public static function get( int $quiz_id ): array {
		$stored = \get_post_meta( $quiz_id, self::META, true );
		return \is_array( $stored ) ? self::sanitize( $stored ) : [];
	}

	public static function save( int $quiz_id, array $questions ): array {
		$clean = self::sanitize( $questions );
		\update_post_meta( $quiz_id, self::META, $clean );
		return $clean;
	}

	public static function points_possible( int $quiz_id ): float {
		$total = 0.0;
		foreach ( self::get( $quiz_id ) as $question ) {
			$total += (float) $question['points'];
		}
		return $total;
	}

	/**
	 * The learner-safe projection. Strips `correct` from every answer and applies
	 * the quiz's shuffle settings deterministically from $seed (the attempt id),
	 * so a page reload during one attempt shows the same order. Pure.
	 */
	public static function for_learner( array $questions, bool $shuffle_questions, bool $shuffle_answers, string $seed ): array {
		$out = [];

		foreach ( $questions as $question ) {
			$answers = [];
			foreach ( (array) $question['answers'] as $answer ) {
				$answers[] = [ 'id' => $answer['id'], 'text' => $answer['text'] ];
			}
			if ( $shuffle_answers ) {
				$answers = self::seeded_shuffle( $answers, $seed . '|' . $question['id'] );
			}
			$out[] = [
				'id'      => $question['id'],
				'type'    => $question['type'],
				'prompt'  => $question['prompt'],
				'points'  => $question['points'],
				'answers' => $answers,
			];
		}

		return $shuffle_questions ? self::seeded_shuffle( $out, $seed ) : $out;
	}

	/** Order a list by a hash of (seed, element id): same seed, same order. Pure. */
	private static function seeded_shuffle( array $list, string $seed ): array {
		$keyed = [];
		foreach ( $list as $index => $element ) {
			$keyed[] = [ \hash( 'sha256', $seed . '|' . ( $element['id'] ?? $index ) ), $element ];
		}
		\usort( $keyed, static fn( array $a, array $b ): int => \strcmp( $a[0], $b[0] ) );
		return \array_column( $keyed, 1 );
	}
}
```

Add to `anchor-courses/src/Admin/QuizEditor.php` - constructor:

```php
		\add_action( 'save_post_' . QuizPostType::CPT, [ $this, 'save_questions' ], 11 );
		\add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
```

Add to `add_metaboxes()`:

```php
		\add_meta_box(
			'anchor_courses_quiz_questions',
			\__( 'Questions', 'anchor-schema' ),
			[ $this, 'render_questions' ],
			QuizPostType::CPT,
			'normal',
			'high'
		);
```

Add these methods (and `use Anchor\Courses\Content\Questions;` plus `use Anchor\Courses\Module;` at the top):

```php
	public function render_questions( \WP_Post $post ): void {
		// Reprint the nonce so question saving still works when the settings box
		// is hidden through Screen Options.
		\wp_nonce_field( self::NONCE, self::NONCE );

		echo '<div class="anchor-courses-questions">';
		echo '<ul class="anchor-courses-question-list"></ul>';
		\printf(
			'<p><button type="button" class="button anchor-courses-add-question" data-type="single_choice">%s</button> '
			. '<button type="button" class="button anchor-courses-add-question" data-type="multiple_choice">%s</button> '
			. '<button type="button" class="button anchor-courses-add-question" data-type="true_false">%s</button></p>',
			\esc_html__( 'Add single choice', 'anchor-schema' ),
			\esc_html__( 'Add multiple choice', 'anchor-schema' ),
			\esc_html__( 'Add true/false', 'anchor-schema' )
		);
		\printf(
			'<input type="hidden" name="anchor_quiz_questions" class="anchor-courses-questions-data" value="%s" />',
			\esc_attr( (string) \wp_json_encode( Questions::get( (int) $post->ID ) ) )
		);
		echo '</div>';
	}

	public function save_questions( int $post_id ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE ] ) ? \sanitize_text_field( \wp_unslash( (string) $_POST[ self::NONCE ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! \current_user_can( Capabilities::cap( 'edit_quizzes' ) ) ) {
			return;
		}
		if ( ! isset( $_POST['anchor_quiz_questions'] ) ) {
			return;
		}

		$decoded = \json_decode( \wp_unslash( (string) $_POST['anchor_quiz_questions'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! \is_array( $decoded ) ) {
			return;
		}

		Questions::save( $post_id, $decoded );
	}

	public function assets( string $hook = '' ): void {
		if ( ! \in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = \get_current_screen();
		if ( ! $screen || QuizPostType::CPT !== $screen->post_type ) {
			return;
		}

		\wp_enqueue_style( 'anchor-courses-admin', Module::assets_url() . 'admin.css', [], Module::VERSION );
		\wp_enqueue_script(
			'anchor-courses-quiz-admin',
			Module::assets_url() . 'admin-quiz.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			Module::VERSION,
			true
		);
		\wp_localize_script(
			'anchor-courses-quiz-admin',
			'anchorCoursesQuiz',
			[
				'strings' => [
					'prompt'     => \__( 'Question prompt', 'anchor-schema' ),
					'points'     => \__( 'Points', 'anchor-schema' ),
					'answer'     => \__( 'Answer text', 'anchor-schema' ),
					'correct'    => \__( 'Correct', 'anchor-schema' ),
					'addAnswer'  => \__( 'Add answer', 'anchor-schema' ),
					'remove'     => \__( 'Remove', 'anchor-schema' ),
					'confirmDel' => \__( 'Remove this question?', 'anchor-schema' ),
				],
			]
		);
	}
```

`anchor-courses/assets/admin-quiz.js`:

```javascript
/**
 * Anchor Courses - quiz question editor.
 *
 * Questions and answers are edited in the DOM and serialised into one hidden
 * JSON field. Ids round-trip so grading references survive reordering. The
 * editor never computes scores; it only authors data.
 */
(function ($) {
    'use strict';

    var S = (window.anchorCoursesQuiz || {}).strings || {};

    function esc(t) { return $('<div/>').text(t == null ? '' : String(t)).html(); }

    function answerMarkup(answer, type) {
        var input = type === 'multiple_choice' ? 'checkbox' : 'radio';
        var readonly = type === 'true_false' ? ' readonly="readonly"' : '';
        return '<li class="anchor-courses-answer" data-id="' + esc(answer.id || '') + '">' +
            '<span class="anchor-courses-item-handle dashicons dashicons-menu"></span>' +
            '<input type="text" class="anchor-courses-answer-text regular-text" value="' + esc(answer.text) +
            '" placeholder="' + esc(S.answer) + '"' + readonly + ' />' +
            '<label><input type="' + input + '" class="anchor-courses-answer-correct"' +
            (answer.correct ? ' checked="checked"' : '') + ' /> ' + esc(S.correct) + '</label>' +
            (type === 'true_false' ? '' :
                '<button type="button" class="button-link anchor-courses-remove-answer">' + esc(S.remove) + '</button>') +
            '</li>';
    }

    function questionMarkup(q) {
        var type = q.type || 'single_choice';
        var answers = (q.answers && q.answers.length) ? q.answers :
            (type === 'true_false'
                ? [{ id: 'true', text: 'True', correct: true }, { id: 'false', text: 'False', correct: false }]
                : [{ id: '', text: '', correct: true }, { id: '', text: '', correct: false }]);

        return '<li class="anchor-courses-question" data-id="' + esc(q.id || '') + '" data-type="' + esc(type) + '">' +
            '<div class="anchor-courses-module-head">' +
            '<span class="anchor-courses-module-handle dashicons dashicons-menu"></span>' +
            '<span class="anchor-courses-item-type">' + esc(type) + '</span>' +
            '<input type="text" class="anchor-courses-question-prompt regular-text" value="' + esc(q.prompt) +
            '" placeholder="' + esc(S.prompt) + '" />' +
            '<label>' + esc(S.points) + ' <input type="number" step="0.5" min="0" class="small-text anchor-courses-question-points" value="' +
            esc(q.points == null ? 1 : q.points) + '" /></label>' +
            '<button type="button" class="button-link anchor-courses-remove-question">' + esc(S.remove) + '</button>' +
            '</div>' +
            '<ul class="anchor-courses-answers">' + answers.map(function (a) { return answerMarkup(a, type); }).join('') + '</ul>' +
            (type === 'true_false' ? '' :
                '<p><button type="button" class="button anchor-courses-add-answer">' + esc(S.addAnswer) + '</button></p>') +
            '</li>';
    }

    function serialize($root) {
        var questions = [];
        $root.find('.anchor-courses-question').each(function () {
            var $q = $(this);
            var answers = [];
            $q.find('.anchor-courses-answer').each(function () {
                var $a = $(this);
                answers.push({
                    id: $a.attr('data-id') || '',
                    text: $a.find('.anchor-courses-answer-text').val(),
                    correct: $a.find('.anchor-courses-answer-correct').is(':checked')
                });
            });
            questions.push({
                id: $q.attr('data-id') || '',
                type: $q.attr('data-type'),
                prompt: $q.find('.anchor-courses-question-prompt').val(),
                points: parseFloat($q.find('.anchor-courses-question-points').val() || '1'),
                answers: answers
            });
        });
        $root.find('.anchor-courses-questions-data').val(JSON.stringify(questions));
    }

    function bind($root) {
        $root.find('.anchor-courses-question-list').sortable({
            handle: '.anchor-courses-module-handle',
            update: function () { serialize($root); }
        });
        $root.find('.anchor-courses-answers').sortable({
            handle: '.anchor-courses-item-handle',
            update: function () { serialize($root); }
        });
    }

    $(function () {
        var $root = $('.anchor-courses-questions');
        if (!$root.length) { return; }

        var initial = [];
        try { initial = JSON.parse($root.find('.anchor-courses-questions-data').val() || '[]'); } catch (e) { initial = []; }
        $root.find('.anchor-courses-question-list').html(initial.map(questionMarkup).join(''));
        bind($root);

        $root.on('click', '.anchor-courses-add-question', function () {
            $root.find('.anchor-courses-question-list')
                .append(questionMarkup({ type: $(this).data('type'), prompt: '', points: 1, answers: [] }));
            bind($root);
            serialize($root);
        });

        $root.on('click', '.anchor-courses-remove-question', function () {
            if (!window.confirm(S.confirmDel)) { return; }
            $(this).closest('.anchor-courses-question').remove();
            serialize($root);
        });

        $root.on('click', '.anchor-courses-add-answer', function () {
            var $q = $(this).closest('.anchor-courses-question');
            $q.find('.anchor-courses-answers').append(answerMarkup({ id: '', text: '', correct: false }, $q.attr('data-type')));
            bind($root);
            serialize($root);
        });

        $root.on('click', '.anchor-courses-remove-answer', function () {
            $(this).closest('.anchor-courses-answer').remove();
            serialize($root);
        });

        // single_choice and true_false have one key: checking one clears siblings.
        $root.on('change', '.anchor-courses-answer-correct', function () {
            var $q = $(this).closest('.anchor-courses-question');
            if ($q.attr('data-type') !== 'multiple_choice' && $(this).is(':checked')) {
                $q.find('.anchor-courses-answer-correct').not(this).prop('checked', false);
            }
            serialize($root);
        });

        $root.on('change keyup', 'input', function () { serialize($root); });
    });
})(jQuery);
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Questions
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Quiz_Editor
```
Expected: PASS (11 unit) and PASS (4 integration).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Content/Questions.php anchor-courses/src/Admin/QuizEditor.php \
        anchor-courses/assets/admin-quiz.js tests/unit/test-questions-sanitize.php tests/test-courses-quiz-editor.php
git commit -m "feat(courses): quiz question editor with learner-safe projection"
```

---

## Phase 2 - Enrollment + Progress

### Task 13: Enrollment repository and value object

**Files:**
- Create: `anchor-courses/src/Domain/Enrollment.php`
- Create: `anchor-courses/src/Database/EnrollmentRepository.php`
- Test: `tests/test-courses-enrollment-repo.php`

**Interfaces:**
- Consumes: `Migrations::table( 'enrollments' )`, `Support\Clock`.
- Produces:
  - `Domain\Enrollment` - public readonly typed props `int $id`, `int $user_id`, `int $course_id`, `string $status`, `string $enrolled_at`, `?string $started_at`, `?string $completed_at`, `?string $expires_at`, `string $source`, `string $source_id`, `array $metadata`; `::from_row( array $row ): self`; `->to_array(): array`; `->is_active(): bool`
  - `Domain\Enrollment::STATUSES = ['enrolled','in_progress','completed','expired','cancelled']`
  - `Database\EnrollmentRepository::find( int $user_id, int $course_id ): ?Enrollment`
  - `::find_by_id( int $id ): ?Enrollment`
  - `::insert_ignore( array $data ): ?Enrollment` - INSERT IGNORE then read back; never throws on a duplicate
  - `::update( int $id, array $data ): ?Enrollment`
  - `::for_user( int $user_id, array $statuses = [] ): array`
  - `::for_course( int $course_id, array $statuses = [], int $limit = 100, int $offset = 0 ): array`
  - `::count_for_course( int $course_id, array $statuses = [] ): int`
  - `::expire_due( string $now ): int` - flips `enrolled|in_progress` rows past `expires_at` to `expired`, returns the row count

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - enrollment table access (brief 7.1, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Domain\Enrollment;

/** @group courses */
class Test_Courses_Enrollment_Repo extends Anchor_Courses_TestCase {

	private function row( int $user_id, int $course_id, array $over = [] ): array {
		return array_merge(
			[
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'status'      => 'enrolled',
				'enrolled_at' => '2026-01-01 00:00:00',
				'source'      => 'manual',
				'source_id'   => '',
				'metadata'    => [],
			],
			$over
		);
	}

	public function test_insert_then_find_round_trips_a_value_object() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$created = EnrollmentRepository::insert_ignore( $this->row( $user, $course, [ 'metadata' => [ 'note' => 'hi' ] ] ) );

		$this->assertInstanceOf( Enrollment::class, $created );
		$this->assertGreaterThan( 0, $created->id );
		$this->assertSame( $user, $created->user_id );
		$this->assertSame( 'enrolled', $created->status );
		$this->assertSame( [ 'note' => 'hi' ], $created->metadata );

		$found = EnrollmentRepository::find( $user, $course );
		$this->assertSame( $created->id, $found->id );
	}

	/** Brief 26: a duplicate must return the existing row, not error, not duplicate. */
	public function test_insert_ignore_is_idempotent() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$first  = EnrollmentRepository::insert_ignore( $this->row( $user, $course ) );
		$second = EnrollmentRepository::insert_ignore( $this->row( $user, $course, [ 'source' => 'woocommerce' ] ) );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( 'manual', $second->source, 'The first write wins; a duplicate must not overwrite.' );
		$this->assertSame( 1, EnrollmentRepository::count_for_course( $course ) );
	}

	public function test_find_returns_null_when_absent() {
		$this->assertNull( EnrollmentRepository::find( 999998, 999999 ) );
	}

	public function test_update_changes_status_and_timestamps() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$row    = EnrollmentRepository::insert_ignore( $this->row( $user, $course ) );

		$updated = EnrollmentRepository::update( $row->id, [ 'status' => 'completed', 'completed_at' => '2026-02-02 10:00:00' ] );

		$this->assertSame( 'completed', $updated->status );
		$this->assertSame( '2026-02-02 10:00:00', $updated->completed_at );
		$this->assertNotSame( $row->to_array()['updated_at'] ?? '', $updated->to_array()['updated_at'] ?? 'x' );
	}

	public function test_for_user_and_for_course_filter_by_status() {
		$user = $this->make_learner();
		$a    = $this->make_course( [], 'A' );
		$b    = $this->make_course( [], 'B' );
		EnrollmentRepository::insert_ignore( $this->row( $user, $a ) );
		EnrollmentRepository::insert_ignore( $this->row( $user, $b, [ 'status' => 'completed' ] ) );

		$this->assertCount( 2, EnrollmentRepository::for_user( $user ) );
		$this->assertCount( 1, EnrollmentRepository::for_user( $user, [ 'completed' ] ) );
		$this->assertCount( 1, EnrollmentRepository::for_course( $a ) );
		$this->assertSame( 0, EnrollmentRepository::count_for_course( $a, [ 'completed' ] ) );
	}

	public function test_expire_due_flips_only_past_active_rows() {
		$user = $this->make_learner();
		$past = $this->make_course( [], 'Past' );
		$soon = $this->make_course( [], 'Soon' );
		$done = $this->make_course( [], 'Done' );

		EnrollmentRepository::insert_ignore( $this->row( $user, $past, [ 'expires_at' => '2026-01-02 00:00:00' ] ) );
		EnrollmentRepository::insert_ignore( $this->row( $user, $soon, [ 'expires_at' => '2030-01-01 00:00:00' ] ) );
		EnrollmentRepository::insert_ignore( $this->row( $user, $done, [ 'status' => 'completed', 'expires_at' => '2026-01-02 00:00:00' ] ) );

		$flipped = EnrollmentRepository::expire_due( '2026-06-01 00:00:00' );

		$this->assertSame( 1, $flipped );
		$this->assertSame( 'expired', EnrollmentRepository::find( $user, $past )->status );
		$this->assertSame( 'enrolled', EnrollmentRepository::find( $user, $soon )->status );
		$this->assertSame( 'completed', EnrollmentRepository::find( $user, $done )->status, 'A completed enrolment never expires.' );
	}

	public function test_is_active_covers_enrolled_and_in_progress_only() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$row    = EnrollmentRepository::insert_ignore( $this->row( $user, $course ) );
		$this->assertTrue( $row->is_active() );
		$this->assertFalse( EnrollmentRepository::update( $row->id, [ 'status' => 'cancelled' ] )->is_active() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Enrollment_Repo
```
Expected: FAIL - `Class "Anchor\Courses\Domain\Enrollment" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Domain/Enrollment.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One learner's relationship to one course (brief 7.1). Immutable. */
final class Enrollment {

	public const STATUSES = [ 'enrolled', 'in_progress', 'completed', 'expired', 'cancelled' ];

	/** Statuses that still grant access to the course. */
	public const ACTIVE_STATUSES = [ 'enrolled', 'in_progress' ];

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly string $status,
		public readonly string $enrolled_at,
		public readonly ?string $started_at,
		public readonly ?string $completed_at,
		public readonly ?string $expires_at,
		public readonly string $source,
		public readonly string $source_id,
		public readonly array $metadata,
		public readonly string $created_at,
		public readonly string $updated_at
	) {}

	public static function from_row( array $row ): self {
		$metadata = \json_decode( (string) ( $row['metadata'] ?? '' ), true );

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['user_id'] ?? 0 ),
			(int) ( $row['course_id'] ?? 0 ),
			(string) ( $row['status'] ?? 'enrolled' ),
			(string) ( $row['enrolled_at'] ?? '' ),
			isset( $row['started_at'] ) ? (string) $row['started_at'] : null,
			isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null,
			isset( $row['expires_at'] ) ? (string) $row['expires_at'] : null,
			(string) ( $row['source'] ?? '' ),
			(string) ( $row['source_id'] ?? '' ),
			\is_array( $metadata ) ? $metadata : [],
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	public function is_active(): bool {
		return \in_array( $this->status, self::ACTIVE_STATUSES, true );
	}

	public function is_complete(): bool {
		return 'completed' === $this->status;
	}

	public function to_array(): array {
		return [
			'id'           => $this->id,
			'user_id'      => $this->user_id,
			'course_id'    => $this->course_id,
			'status'       => $this->status,
			'enrolled_at'  => $this->enrolled_at,
			'started_at'   => $this->started_at,
			'completed_at' => $this->completed_at,
			'expires_at'   => $this->expires_at,
			'source'       => $this->source,
			'source_id'    => $this->source_id,
			'metadata'     => $this->metadata,
			'created_at'   => $this->created_at,
			'updated_at'   => $this->updated_at,
		];
	}
}
```

`anchor-courses/src/Database/EnrollmentRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Domain\Enrollment;
use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * All SQL for wp_anchor_courses_enrollments.
 *
 * Nothing outside this class writes the table. insert_ignore() leans on the
 * UNIQUE (user_id, course_id) key rather than a read-then-write race (brief 26).
 */
final class EnrollmentRepository {

	private static function table(): string {
		return Migrations::table( 'enrollments' );
	}

	public static function find( int $user_id, int $course_id ): ?Enrollment {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d',
				$user_id,
				$course_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? Enrollment::from_row( $row ) : null;
	}

	public static function find_by_id( int $id ): ?Enrollment {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return \is_array( $row ) ? Enrollment::from_row( $row ) : null;
	}

	/**
	 * Create the row unless (user_id, course_id) already exists; either way,
	 * return the row that is now in the table.
	 *
	 * @param array $data user_id, course_id, status, enrolled_at, and optionally
	 *                    started_at, completed_at, expires_at, source, source_id, metadata.
	 */
	public static function insert_ignore( array $data ): ?Enrollment {
		global $wpdb;

		$now = Clock::now();

		$values = [
			'user_id'      => (int) ( $data['user_id'] ?? 0 ),
			'course_id'    => (int) ( $data['course_id'] ?? 0 ),
			'status'       => (string) ( $data['status'] ?? 'enrolled' ),
			'enrolled_at'  => (string) ( $data['enrolled_at'] ?? $now ),
			'started_at'   => $data['started_at'] ?? null,
			'completed_at' => $data['completed_at'] ?? null,
			'expires_at'   => $data['expires_at'] ?? null,
			'source'       => (string) ( $data['source'] ?? '' ),
			'source_id'    => (string) ( $data['source_id'] ?? '' ),
			'metadata'     => (string) \wp_json_encode( (array) ( $data['metadata'] ?? [] ) ),
			'created_at'   => $now,
			'updated_at'   => $now,
		];

		// INSERT IGNORE, not $wpdb->insert(): the unique key is the concurrency
		// guard, and a duplicate must be a no-op rather than a warning.
		$columns      = \implode( ', ', \array_keys( $values ) );
		$placeholders = \implode( ', ', \array_fill( 0, \count( $values ), '%s' ) );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . self::table() . " ({$columns}) VALUES ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_values( $values )
			)
		);

		return self::find( $values['user_id'], $values['course_id'] );
	}

	/** @param array $data Column => value. `metadata` may be an array. */
	public static function update( int $id, array $data ): ?Enrollment {
		global $wpdb;

		if ( isset( $data['metadata'] ) && \is_array( $data['metadata'] ) ) {
			$data['metadata'] = (string) \wp_json_encode( $data['metadata'] );
		}
		$data['updated_at'] = Clock::now();

		$wpdb->update( self::table(), $data, [ 'id' => $id ] );

		return self::find_by_id( $id );
	}

	/** @return Enrollment[] */
	public static function for_user( int $user_id, array $statuses = [] ): array {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d';
		$params = [ $user_id ];

		if ( [] !== $statuses ) {
			$sql   .= ' AND status IN (' . \implode( ', ', \array_fill( 0, \count( $statuses ), '%s' ) ) . ')';
			$params = \array_merge( $params, \array_values( $statuses ) );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY enrolled_at DESC', $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return \array_map( [ Enrollment::class, 'from_row' ], (array) $rows );
	}

	/** @return Enrollment[] */
	public static function for_course( int $course_id, array $statuses = [], int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . self::table() . ' WHERE course_id = %d';
		$params = [ $course_id ];

		if ( [] !== $statuses ) {
			$sql   .= ' AND status IN (' . \implode( ', ', \array_fill( 0, \count( $statuses ), '%s' ) ) . ')';
			$params = \array_merge( $params, \array_values( $statuses ) );
		}

		$sql     .= ' ORDER BY enrolled_at DESC LIMIT %d OFFSET %d';
		$params[] = \max( 1, $limit );
		$params[] = \max( 0, $offset );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return \array_map( [ Enrollment::class, 'from_row' ], (array) $rows );
	}

	public static function count_for_course( int $course_id, array $statuses = [] ): int {
		global $wpdb;

		$sql    = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE course_id = %d';
		$params = [ $course_id ];

		if ( [] !== $statuses ) {
			$sql   .= ' AND status IN (' . \implode( ', ', \array_fill( 0, \count( $statuses ), '%s' ) ) . ')';
			$params = \array_merge( $params, \array_values( $statuses ) );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Flip active enrolments whose expires_at has passed. @return int rows changed. */
	public static function expire_due( string $now ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . "
				 SET status = 'expired', updated_at = %s
				 WHERE status IN ('enrolled', 'in_progress')
				   AND expires_at IS NOT NULL
				   AND expires_at <> ''
				   AND expires_at <= %s",
				$now,
				$now
			)
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Enrollment_Repo
```
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Domain/Enrollment.php anchor-courses/src/Database/EnrollmentRepository.php tests/test-courses-enrollment-repo.php
git commit -m "feat(courses): enrollment repository and value object"
```

---

### Task 14: EnrollmentService and `anchor_courses_enroll_user()`

**Files:**
- Create: `anchor-courses/src/Services/EnrollmentService.php`
- Modify: `anchor-courses/api.php` - add `anchor_courses_enroll_user()`
- Modify: `anchor-courses/anchor-courses.php` - construct `$this->enrollments`, schedule the expiry sweep
- Test: `tests/test-courses-enrollment-service.php`

**Interfaces:**
- Consumes: `EnrollmentRepository`, `Admin\CourseEditor::setting()`, `Support\Clock`, `Support\Log`.
- Produces:
  - `Services\EnrollmentService::can_enroll( int $user_id, int $course_id ): true|\WP_Error`
  - `::enroll( int $user_id, int $course_id, array $args = [] ): Enrollment|\WP_Error` - `$args`: `source`, `source_id`, `metadata`, `bypass_checks` (bool, admin/integration path)
  - `::get( int $user_id, int $course_id ): ?Enrollment`
  - `::is_enrolled( int $user_id, int $course_id ): bool` - active statuses only
  - `::start( int $user_id, int $course_id ): ?Enrollment` - sets `started_at` + `in_progress` once
  - `::set_status( int $user_id, int $course_id, string $status ): ?Enrollment`
  - `::cancel( int $user_id, int $course_id ): ?Enrollment`, `::expire( int $user_id, int $course_id ): ?Enrollment`
  - `::sweep_expired(): int` - hooked to `anchor_courses_expire_sweep` (daily cron)
  - Actions: `anchor_courses_enrolled( Enrollment $enrollment, int $user_id, int $course_id )`, `anchor_courses_course_started( int $user_id, int $course_id, Enrollment $enrollment )`, `anchor_courses_enrollment_status_changed( int $user_id, int $course_id, string $from, string $to )`
  - Filter: `anchor_courses_can_enroll( true|\WP_Error $allowed, int $user_id, int $course_id )`
  - `anchor_courses_enroll_user( int $user_id, int $course_id, array $args = [] )` in `api.php`

Error codes returned by `can_enroll()`: `no_user`, `no_course`, `not_available_yet`, `no_longer_available`, `missing_prerequisite`. There is **no** `course_closed`: closed-ness was the access type, and the access type is gone (design spec 1). `enroll()` additionally returns `already_enrolled`? **No** - re-enrolling an active learner returns the existing `Enrollment` (idempotent, brief 26).

**Who calls what.** `can_enroll()` is the gate on *granting the access role* - `Support\Roles::grant_access()` (Task 19) asks it, so the Learners tab (Task 21) and the WooCommerce adapter (Task 39) are both refused on an unmet prerequisite. `enroll()` is what the role listener (Task 20) calls once the role is already held, with `bypass_checks`, because by then the decision has been made: holding the role *is* enrolment. Nothing on the front end calls either - there is no self-enrolment (design spec 7).

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - enrolment rules and events (brief 15, 16, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Domain\Enrollment;
use Anchor\Courses\Services\EnrollmentService;

/** @group courses */
class Test_Courses_Enrollment_Service extends Anchor_Courses_TestCase {

	private EnrollmentService $service;

	public function set_up() {
		parent::set_up();
		$this->service = new EnrollmentService();
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_can_enroll' );
		remove_all_actions( 'anchor_courses_enrolled' );
		remove_all_actions( 'anchor_courses_course_started' );
		remove_all_filters( 'anchor_courses_now' );
		parent::tear_down();
	}

	public function test_enroll_creates_one_row_and_fires_the_action() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$fired  = [];
		add_action( 'anchor_courses_enrolled', function ( $e, $u, $c ) use ( &$fired ) { $fired[] = [ $u, $c ]; }, 10, 3 );

		$enrollment = $this->service->enroll( $user, $course );

		$this->assertInstanceOf( Enrollment::class, $enrollment );
		$this->assertSame( 'enrolled', $enrollment->status );
		$this->assertSame( [ [ $user, $course ] ], $fired );
	}

	/** Brief 26: a second enrol returns the same row and fires nothing. */
	public function test_enroll_is_idempotent() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$count  = 0;
		add_action( 'anchor_courses_enrolled', function () use ( &$count ) { $count++; }, 10, 3 );

		$first  = $this->service->enroll( $user, $course );
		$second = $this->service->enroll( $user, $course );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( 1, $count, 'The enrolled action must fire once per enrolment.' );
	}

	/** The source travels with the enrolment, whichever door it came through. */
	public function test_the_bypass_path_records_its_source() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$allowed = $this->service->enroll( $user, $course, [ 'bypass_checks' => true, 'source' => 'woocommerce', 'source_id' => '4242' ] );
		$this->assertInstanceOf( Enrollment::class, $allowed );
		$this->assertSame( 'woocommerce', $allowed->source );
		$this->assertSame( '4242', $allowed->source_id );
	}

	/** There is no access type, so there is no "closed" refusal to make. */
	public function test_there_is_no_course_closed_refusal() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$this->assertTrue( $this->service->can_enroll( $user, $course ) );
	}

	public function test_availability_window_is_enforced() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-06-15 12:00:00 UTC' ) );
		$user = $this->make_learner();

		$future = $this->make_course( [ 'available_from' => '2026-09-01' ] );
		$past   = $this->make_course( [ 'available_until' => '2026-01-31' ] );
		$open   = $this->make_course( [ 'available_from' => '2026-01-01', 'available_until' => '2026-12-31' ] );

		$this->assertSame( 'not_available_yet', $this->service->enroll( $user, $future )->get_error_code() );
		$this->assertSame( 'no_longer_available', $this->service->enroll( $user, $past )->get_error_code() );
		$this->assertInstanceOf( Enrollment::class, $this->service->enroll( $user, $open ) );
	}

	public function test_expiration_days_sets_expires_at() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-01-01 00:00:00 UTC' ) );
		$user   = $this->make_learner();
		$course = $this->make_course( [ 'expiration_days' => '30' ] );

		$enrollment = $this->service->enroll( $user, $course );

		$this->assertSame( '2026-01-31 00:00:00', $enrollment->expires_at );
	}

	public function test_prerequisite_roles_block_enrolment_until_held() {
		$user   = $this->make_learner();
		$course = $this->make_course( [ 'prerequisites' => [ 'anchor_course_999_completed' ] ] );

		$refused = $this->service->enroll( $user, $course );
		$this->assertSame( 'missing_prerequisite', $refused->get_error_code() );

		add_role( 'anchor_course_999_completed', 'Completed: Prereq', [] );
		get_user_by( 'id', $user )->add_role( 'anchor_course_999_completed' );

		$this->assertInstanceOf( Enrollment::class, $this->service->enroll( $user, $course ) );

		remove_role( 'anchor_course_999_completed' );
	}

	public function test_the_can_enroll_filter_can_veto() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		add_filter(
			'anchor_courses_can_enroll',
			static fn( $allowed, $u, $c ) => new WP_Error( 'nope', 'Blocked by a test.' ),
			10,
			3
		);

		$this->assertSame( 'nope', $this->service->enroll( $user, $course )->get_error_code() );
	}

	public function test_start_sets_started_at_once_and_fires_course_started() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$this->service->enroll( $user, $course );
		$fired = 0;
		add_action( 'anchor_courses_course_started', function () use ( &$fired ) { $fired++; }, 10, 3 );

		$first = $this->service->start( $user, $course );
		$again = $this->service->start( $user, $course );

		$this->assertSame( 'in_progress', $first->status );
		$this->assertNotNull( $first->started_at );
		$this->assertSame( $first->started_at, $again->started_at );
		$this->assertSame( 1, $fired );
	}

	public function test_is_enrolled_is_false_for_cancelled_and_expired() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$this->service->enroll( $user, $course );
		$this->assertTrue( $this->service->is_enrolled( $user, $course ) );

		$this->service->cancel( $user, $course );
		$this->assertFalse( $this->service->is_enrolled( $user, $course ) );
	}

	public function test_sweep_expired_flips_due_rows() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-01-01 00:00:00 UTC' ) );
		$user   = $this->make_learner();
		$course = $this->make_course( [ 'expiration_days' => '1' ] );
		$this->service->enroll( $user, $course );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-03-01 00:00:00 UTC' ) );

		$this->assertSame( 1, $this->service->sweep_expired() );
		$this->assertSame( 'expired', $this->service->get( $user, $course )->status );
	}

	public function test_the_public_api_function_delegates_to_the_service() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$enrollment = anchor_courses_enroll_user( $user, $course, [ 'source' => 'api' ] );

		$this->assertInstanceOf( Enrollment::class, $enrollment );
		$this->assertSame( 'api', $enrollment->source );
	}

	public function test_enroll_refuses_a_non_course_post() {
		$user = $this->make_learner();
		$this->assertSame( 'no_course', $this->service->enroll( $user, $this->make_lesson() )->get_error_code() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Enrollment_Service
```
Expected: FAIL - `Class "Anchor\Courses\Services\EnrollmentService" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Services/EnrollmentService.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Domain\Enrollment;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Log;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Who is enrolled in what, and why (brief 15, 16, 26).
 *
 * Every enrolment in the system goes through enroll(). In practice one caller
 * reaches it: the Support\Roles listener, when somebody gains
 * `anchor_course_{id}`. Purchases, the Learners tab, WP-CLI and any other
 * plugin all grant that role rather than calling this service, so the rules,
 * the hooks and the row shape have exactly one home.
 */
final class EnrollmentService {

	public const CRON_HOOK = 'anchor_courses_expire_sweep';

	/**
	 * May this user be given access to this course right now?
	 *
	 * Asked by Support\Roles::grant_access() before it hands out the access
	 * role, so the Learners tab and the WooCommerce adapter refuse together.
	 * Never asked on the front end: nobody enrols themselves.
	 *
	 * @return true|\WP_Error
	 */
	public function can_enroll( int $user_id, int $course_id ) {
		$result = $this->check( $user_id, $course_id );

		/**
		 * Filter the enrolment decision.
		 *
		 * @param true|\WP_Error $result
		 * @param int            $user_id
		 * @param int            $course_id
		 */
		return \apply_filters( 'anchor_courses_can_enroll', $result, $user_id, $course_id );
	}

	/** @return true|\WP_Error */
	private function check( int $user_id, int $course_id ) {
		if ( $user_id <= 0 || ! \get_userdata( $user_id ) ) {
			return new \WP_Error( 'no_user', \__( 'That user does not exist.', 'anchor-schema' ) );
		}
		if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			return new \WP_Error( 'no_course', \__( 'That course does not exist.', 'anchor-schema' ) );
		}

		$now  = Clock::timestamp();
		$from = (string) CourseEditor::setting( $course_id, 'available_from' );
		$to   = (string) CourseEditor::setting( $course_id, 'available_until' );

		if ( '' !== $from && $now < (int) \strtotime( $from . ' 00:00:00 UTC' ) ) {
			return new \WP_Error( 'not_available_yet', \__( 'This course is not open yet.', 'anchor-schema' ) );
		}
		if ( '' !== $to && $now > (int) \strtotime( $to . ' 23:59:59 UTC' ) ) {
			return new \WP_Error( 'no_longer_available', \__( 'This course has closed.', 'anchor-schema' ) );
		}

		$missing = $this->missing_prerequisites( $user_id, $course_id );
		if ( [] !== $missing ) {
			return new \WP_Error(
				'missing_prerequisite',
				\sprintf(
					/* translators: %s: comma-separated list of role display names. */
					\__( 'This course requires: %s', 'anchor-schema' ),
					\implode( ', ', $missing )
				)
			);
		}

		return true;
	}

	/**
	 * Prerequisite roles (course-completion or event-attendance slugs) the user
	 * does not hold, by display name.
	 *
	 * @return string[]
	 */
	public function missing_prerequisites( int $user_id, int $course_id ): array {
		$required = (array) CourseEditor::setting( $course_id, 'prerequisites' );
		if ( [] === $required ) {
			return [];
		}

		$user = \get_userdata( $user_id );
		if ( ! $user ) {
			return [];
		}

		$held    = \array_map( 'strval', (array) $user->roles );
		$missing = [];

		foreach ( $required as $slug ) {
			$slug = (string) $slug;
			if ( \in_array( $slug, $held, true ) ) {
				continue;
			}
			$role      = \wp_roles()->roles[ $slug ] ?? null;
			$missing[] = $role ? (string) $role['name'] : $slug;
		}

		return $missing;
	}

	/**
	 * Enrol a user. Idempotent: an existing row is returned untouched.
	 *
	 * @param array $args source, source_id, metadata, bypass_checks.
	 * @return Enrollment|\WP_Error
	 */
	public function enroll( int $user_id, int $course_id, array $args = [] ) {
		$existing = EnrollmentRepository::find( $user_id, $course_id );
		if ( $existing instanceof Enrollment ) {
			return $existing;
		}

		if ( empty( $args['bypass_checks'] ) ) {
			$allowed = $this->can_enroll( $user_id, $course_id );
			if ( \is_wp_error( $allowed ) ) {
				return $allowed;
			}
		} elseif ( CoursePostType::CPT !== \get_post_type( $course_id ) || ! \get_userdata( $user_id ) ) {
			// Even the bypass path must name a real user and a real course.
			return new \WP_Error( 'no_course', \__( 'That course does not exist.', 'anchor-schema' ) );
		}

		$expiration_days = (int) CourseEditor::setting( $course_id, 'expiration_days' );

		$enrollment = EnrollmentRepository::insert_ignore(
			[
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'status'      => 'enrolled',
				'enrolled_at' => Clock::now(),
				'expires_at'  => $expiration_days > 0 ? Clock::offset( $expiration_days * DAY_IN_SECONDS ) : null,
				'source'      => (string) ( $args['source'] ?? 'manual' ),
				'source_id'   => (string) ( $args['source_id'] ?? '' ),
				'metadata'    => (array) ( $args['metadata'] ?? [] ),
			]
		);

		if ( ! $enrollment instanceof Enrollment ) {
			return new \WP_Error( 'enroll_failed', \__( 'The enrolment could not be saved.', 'anchor-schema' ) );
		}

		Log::write( 'enrolled', [ 'user' => $user_id, 'course' => $course_id, 'source' => $enrollment->source ] );

		/**
		 * Fires once, the first time a user is enrolled in a course.
		 *
		 * @param Enrollment $enrollment
		 * @param int        $user_id
		 * @param int        $course_id
		 */
		\do_action( 'anchor_courses_enrolled', $enrollment, $user_id, $course_id );

		return $enrollment;
	}

	public function get( int $user_id, int $course_id ): ?Enrollment {
		return EnrollmentRepository::find( $user_id, $course_id );
	}

	public function is_enrolled( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		return $enrollment instanceof Enrollment && $enrollment->is_active();
	}

	/** Mark the course started. No-op (returns the row) if it already was. */
	public function start( int $user_id, int $course_id ): ?Enrollment {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment ) {
			return null;
		}
		if ( null !== $enrollment->started_at && '' !== $enrollment->started_at ) {
			return $enrollment;
		}

		$updated = EnrollmentRepository::update(
			$enrollment->id,
			[ 'status' => 'in_progress', 'started_at' => Clock::now() ]
		);

		/**
		 * Fires the first time a learner opens an item in a course.
		 *
		 * @param int        $user_id
		 * @param int        $course_id
		 * @param Enrollment $enrollment
		 */
		\do_action( 'anchor_courses_course_started', $user_id, $course_id, $updated );

		return $updated;
	}

	public function set_status( int $user_id, int $course_id, string $status ): ?Enrollment {
		if ( ! \in_array( $status, Enrollment::STATUSES, true ) ) {
			return null;
		}
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment || $enrollment->status === $status ) {
			return $enrollment;
		}

		$from    = $enrollment->status;
		$updated = EnrollmentRepository::update( $enrollment->id, [ 'status' => $status ] );

		\do_action( 'anchor_courses_enrollment_status_changed', $user_id, $course_id, $from, $status );

		return $updated;
	}

	public function cancel( int $user_id, int $course_id ): ?Enrollment {
		return $this->set_status( $user_id, $course_id, 'cancelled' );
	}

	public function expire( int $user_id, int $course_id ): ?Enrollment {
		return $this->set_status( $user_id, $course_id, 'expired' );
	}

	/** Daily sweep. @return int rows flipped to expired. */
	public function sweep_expired(): int {
		return EnrollmentRepository::expire_due( Clock::now() );
	}
}
```

`anchor-courses/api.php` - append:

```php
/**
 * Enrol a user in a course (brief section 15).
 *
 * Idempotent: enrolling an already-enrolled user returns the existing record.
 *
 * @param int   $user_id
 * @param int   $course_id
 * @param array $args source, source_id, metadata, bypass_checks.
 * @return \Anchor\Courses\Domain\Enrollment|WP_Error
 */
function anchor_courses_enroll_user( $user_id, $course_id, array $args = [] ) {
	return ( new \Anchor\Courses\Services\EnrollmentService() )->enroll( (int) $user_id, (int) $course_id, $args );
}
```

`anchor-courses/anchor-courses.php` - add a typed property and wire the cron:

```php
	public Services\EnrollmentService $enrollments;
```

in the constructor, after the migrations block:

```php
		$this->enrollments = new Services\EnrollmentService();

		// Daily expiry sweep. Scheduled here rather than on activation because
		// modules have no activation hook (see Migrations' note).
		\add_action( Services\EnrollmentService::CRON_HOOK, [ $this->enrollments, 'sweep_expired' ] );
		if ( ! \wp_next_scheduled( Services\EnrollmentService::CRON_HOOK ) ) {
			\wp_schedule_event( \time() + HOUR_IN_SECONDS, 'daily', Services\EnrollmentService::CRON_HOOK );
		}
```

`uninstall.php` - inside the existing courses branch, add:

```php
	wp_clear_scheduled_hook( 'anchor_courses_expire_sweep' );
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Enrollment_Service
```
Expected: PASS (13 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Services/EnrollmentService.php anchor-courses/api.php \
        anchor-courses/anchor-courses.php uninstall.php tests/test-courses-enrollment-service.php
git commit -m "feat(courses): enrollment service, enrol hooks and the public enrol API"
```

---

### Task 15: Progress repository and value objects

**Files:**
- Create: `anchor-courses/src/Domain/Progress.php`, `anchor-courses/src/Domain/CourseProgress.php`
- Create: `anchor-courses/src/Database/ProgressRepository.php`
- Test: `tests/test-courses-progress-repo.php`

**Interfaces:**
- Produces:
  - `Domain\Progress::STATUSES = ['not_started','in_progress','completed','failed']`; readonly props `id, user_id, course_id, item_id, item_type, status, progress_percent (float), started_at, completed_at, last_viewed_at, time_spent_seconds (int), metadata (array), created_at, updated_at`; `::from_row()`, `->to_array()`, `->is_complete(): bool`
  - `Domain\CourseProgress` - readonly `int $user_id`, `int $course_id`, `int $completed_required`, `int $total_required`, `float $percent`, `bool $complete`, `array $completed_item_keys`; `->to_array(): array`
  - `Database\ProgressRepository::find( int $user_id, int $course_id, int $item_id, string $item_type ): ?Progress`
  - `::upsert( array $data ): ?Progress` - `INSERT ... ON DUPLICATE KEY UPDATE`, never two rows
  - `::for_course( int $user_id, int $course_id ): array` keyed `"{type}:{id}"`
  - `::completed_keys( int $user_id, int $course_id ): array` - `["lesson:12", ...]`
  - `::count_completed( int $user_id, int $course_id ): int`
  - `::delete_for_course( int $user_id, int $course_id ): int`
  - `::last_activity( int $user_id, int $course_id ): string` - latest `updated_at`, `''` when none

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - item progress table access (brief 7.2, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\ProgressRepository;
use Anchor\Courses\Domain\Progress;

/** @group courses */
class Test_Courses_Progress_Repo extends Anchor_Courses_TestCase {

	public function test_upsert_creates_then_updates_one_row() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$lesson = $this->make_lesson();

		$created = ProgressRepository::upsert(
			[ 'user_id' => $user, 'course_id' => $course, 'item_id' => $lesson,
			  'item_type' => 'lesson', 'status' => 'in_progress', 'progress_percent' => 25.0 ]
		);
		$this->assertInstanceOf( Progress::class, $created );
		$this->assertSame( 25.0, $created->progress_percent );

		$updated = ProgressRepository::upsert(
			[ 'user_id' => $user, 'course_id' => $course, 'item_id' => $lesson,
			  'item_type' => 'lesson', 'status' => 'completed', 'progress_percent' => 100.0,
			  'completed_at' => '2026-03-03 09:00:00' ]
		);

		$this->assertSame( $created->id, $updated->id, 'A second upsert must not create a second row.' );
		$this->assertSame( 'completed', $updated->status );
		$this->assertSame( '2026-03-03 09:00:00', $updated->completed_at );
		$this->assertCount( 1, ProgressRepository::for_course( $user, $course ) );
	}

	public function test_a_lesson_and_a_quiz_with_the_same_id_are_separate_rows() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 77, 'item_type' => 'lesson', 'status' => 'completed' ] );
		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 77, 'item_type' => 'quiz', 'status' => 'failed' ] );

		$rows = ProgressRepository::for_course( $user, $course );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'completed', $rows['lesson:77']->status );
		$this->assertSame( 'failed', $rows['quiz:77']->status );
	}

	public function test_completed_keys_and_count_ignore_unfinished_items() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 1, 'item_type' => 'lesson', 'status' => 'completed' ] );
		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 2, 'item_type' => 'lesson', 'status' => 'in_progress' ] );
		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 3, 'item_type' => 'quiz', 'status' => 'failed' ] );

		$this->assertSame( [ 'lesson:1' ], ProgressRepository::completed_keys( $user, $course ) );
		$this->assertSame( 1, ProgressRepository::count_completed( $user, $course ) );
	}

	public function test_find_returns_null_when_absent() {
		$this->assertNull( ProgressRepository::find( 999998, 999997, 5, 'lesson' ) );
	}

	public function test_metadata_round_trips_as_an_array() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$row = ProgressRepository::upsert(
			[ 'user_id' => $user, 'course_id' => $course, 'item_id' => 9, 'item_type' => 'lesson',
			  'status' => 'completed', 'metadata' => [ 'attempt_id' => 12 ] ]
		);

		$this->assertSame( [ 'attempt_id' => 12 ], $row->metadata );
	}

	public function test_last_activity_returns_the_latest_update() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$this->assertSame( '', ProgressRepository::last_activity( $user, $course ) );

		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 1, 'item_type' => 'lesson', 'status' => 'completed' ] );

		$this->assertNotSame( '', ProgressRepository::last_activity( $user, $course ) );
	}

	public function test_delete_for_course_removes_only_that_users_rows() {
		$mine   = $this->make_learner();
		$theirs = $this->make_learner();
		$course = $this->make_course();
		ProgressRepository::upsert( [ 'user_id' => $mine, 'course_id' => $course, 'item_id' => 1, 'item_type' => 'lesson', 'status' => 'completed' ] );
		ProgressRepository::upsert( [ 'user_id' => $theirs, 'course_id' => $course, 'item_id' => 1, 'item_type' => 'lesson', 'status' => 'completed' ] );

		$this->assertSame( 1, ProgressRepository::delete_for_course( $mine, $course ) );
		$this->assertCount( 0, ProgressRepository::for_course( $mine, $course ) );
		$this->assertCount( 1, ProgressRepository::for_course( $theirs, $course ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Progress_Repo
```
Expected: FAIL - `Class "Anchor\Courses\Database\ProgressRepository" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Domain/Progress.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One learner's state on one curriculum item (brief 7.2). Immutable. */
final class Progress {

	public const STATUSES  = [ 'not_started', 'in_progress', 'completed', 'failed' ];
	public const ITEM_TYPES = [ 'lesson', 'quiz' ];

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly int $item_id,
		public readonly string $item_type,
		public readonly string $status,
		public readonly float $progress_percent,
		public readonly ?string $started_at,
		public readonly ?string $completed_at,
		public readonly ?string $last_viewed_at,
		public readonly int $time_spent_seconds,
		public readonly array $metadata,
		public readonly string $created_at,
		public readonly string $updated_at
	) {}

	public static function from_row( array $row ): self {
		$metadata = \json_decode( (string) ( $row['metadata'] ?? '' ), true );

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['user_id'] ?? 0 ),
			(int) ( $row['course_id'] ?? 0 ),
			(int) ( $row['item_id'] ?? 0 ),
			(string) ( $row['item_type'] ?? 'lesson' ),
			(string) ( $row['status'] ?? 'not_started' ),
			(float) ( $row['progress_percent'] ?? 0 ),
			isset( $row['started_at'] ) ? (string) $row['started_at'] : null,
			isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null,
			isset( $row['last_viewed_at'] ) ? (string) $row['last_viewed_at'] : null,
			(int) ( $row['time_spent_seconds'] ?? 0 ),
			\is_array( $metadata ) ? $metadata : [],
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/** The key this item is addressed by everywhere in the module. */
	public function key(): string {
		return $this->item_type . ':' . $this->item_id;
	}

	public function is_complete(): bool {
		return 'completed' === $this->status;
	}

	public function to_array(): array {
		return [
			'id'                 => $this->id,
			'user_id'            => $this->user_id,
			'course_id'          => $this->course_id,
			'item_id'            => $this->item_id,
			'item_type'          => $this->item_type,
			'status'             => $this->status,
			'progress_percent'   => $this->progress_percent,
			'started_at'         => $this->started_at,
			'completed_at'       => $this->completed_at,
			'last_viewed_at'     => $this->last_viewed_at,
			'time_spent_seconds' => $this->time_spent_seconds,
			'metadata'           => $this->metadata,
			'created_at'         => $this->created_at,
			'updated_at'         => $this->updated_at,
		];
	}
}
```

`anchor-courses/src/Domain/CourseProgress.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The rolled-up answer to "how far through is this learner" (brief 10).
 *
 * The return type of anchor_courses_get_progress(). Derived, never stored:
 * the progress table is the source of truth and this is recomputed from it.
 */
final class CourseProgress {

	/**
	 * @param string[] $completed_item_keys "lesson:12", "quiz:15", ...
	 */
	public function __construct(
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly int $completed_required,
		public readonly int $total_required,
		public readonly float $percent,
		public readonly bool $complete,
		public readonly array $completed_item_keys
	) {}

	public function to_array(): array {
		return [
			'user_id'             => $this->user_id,
			'course_id'           => $this->course_id,
			'completed_required'  => $this->completed_required,
			'total_required'      => $this->total_required,
			'percent'             => $this->percent,
			'complete'            => $this->complete,
			'completed_item_keys' => $this->completed_item_keys,
		];
	}
}
```

`anchor-courses/src/Database/ProgressRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Domain\Progress;
use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * All SQL for wp_anchor_courses_progress.
 *
 * upsert() is ON DUPLICATE KEY UPDATE against
 * UNIQUE (user_id, course_id, item_id, item_type), so two simultaneous
 * "complete lesson" calls still leave exactly one row (brief 26).
 */
final class ProgressRepository {

	private static function table(): string {
		return Migrations::table( 'progress' );
	}

	public static function find( int $user_id, int $course_id, int $item_id, string $item_type ): ?Progress {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d AND item_id = %d AND item_type = %s',
				$user_id,
				$course_id,
				$item_id,
				$item_type
			),
			ARRAY_A
		);
		return \is_array( $row ) ? Progress::from_row( $row ) : null;
	}

	/**
	 * Insert or update the single row for (user, course, item, type).
	 *
	 * Columns absent from $data keep their stored value on update; only the keys
	 * actually supplied are overwritten.
	 */
	public static function upsert( array $data ): ?Progress {
		global $wpdb;

		$now       = Clock::now();
		$user_id   = (int) ( $data['user_id'] ?? 0 );
		$course_id = (int) ( $data['course_id'] ?? 0 );
		$item_id   = (int) ( $data['item_id'] ?? 0 );
		$item_type = (string) ( $data['item_type'] ?? 'lesson' );

		$row = [
			'user_id'            => $user_id,
			'course_id'          => $course_id,
			'item_id'            => $item_id,
			'item_type'          => $item_type,
			'status'             => (string) ( $data['status'] ?? 'not_started' ),
			'progress_percent'   => (float) ( $data['progress_percent'] ?? 0 ),
			'started_at'         => $data['started_at'] ?? null,
			'completed_at'       => $data['completed_at'] ?? null,
			'last_viewed_at'     => $data['last_viewed_at'] ?? null,
			'time_spent_seconds' => (int) ( $data['time_spent_seconds'] ?? 0 ),
			'metadata'           => (string) \wp_json_encode( (array) ( $data['metadata'] ?? [] ) ),
			'created_at'         => $now,
			'updated_at'         => $now,
		];

		// Columns the caller supplied are the ones the UPDATE half overwrites.
		$updatable = \array_values(
			\array_intersect(
				[ 'status', 'progress_percent', 'started_at', 'completed_at', 'last_viewed_at', 'time_spent_seconds', 'metadata' ],
				\array_keys( $data )
			)
		);
		$updatable[] = 'updated_at';

		$columns      = \implode( ', ', \array_keys( $row ) );
		$placeholders = \implode( ', ', \array_fill( 0, \count( $row ), '%s' ) );
		$assignments  = \implode( ', ', \array_map( static fn( string $c ): string => "{$c} = VALUES({$c})", $updatable ) );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO " . self::table() . " ({$columns}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$assignments}", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_values( $row )
			)
		);

		return self::find( $user_id, $course_id, $item_id, $item_type );
	}

	/** @return array<string,Progress> Keyed "{item_type}:{item_id}". */
	public static function for_course( int $user_id, int $course_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d',
				$user_id,
				$course_id
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$progress                 = Progress::from_row( $row );
			$out[ $progress->key() ] = $progress;
		}
		return $out;
	}

	/** @return string[] "{item_type}:{item_id}" for completed items only. */
	public static function completed_keys( int $user_id, int $course_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT item_type, item_id FROM ' . self::table() . "
				 WHERE user_id = %d AND course_id = %d AND status = 'completed'
				 ORDER BY item_type ASC, item_id ASC",
				$user_id,
				$course_id
			),
			ARRAY_A
		);

		return \array_map(
			static fn( array $r ): string => $r['item_type'] . ':' . (int) $r['item_id'],
			(array) $rows
		);
	}

	public static function count_completed( int $user_id, int $course_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE user_id = %d AND course_id = %d AND status = 'completed'",
				$user_id,
				$course_id
			)
		);
	}

	public static function delete_for_course( int $user_id, int $course_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), [ 'user_id' => $user_id, 'course_id' => $course_id ], [ '%d', '%d' ] );
	}

	/** Latest updated_at for this learner in this course, or ''. */
	public static function last_activity( int $user_id, int $course_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(updated_at) FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d',
				$user_id,
				$course_id
			)
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Progress_Repo
```
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Domain/Progress.php anchor-courses/src/Domain/CourseProgress.php \
        anchor-courses/src/Database/ProgressRepository.php tests/test-courses-progress-repo.php
git commit -m "feat(courses): progress repository with an upsert that cannot duplicate"
```

---

### Task 16: ProgressService - progression rules and completion maths

**Files:**
- Create: `anchor-courses/src/Services/ProgressService.php`
- Modify: `anchor-courses/anchor-courses.php` - construct `$this->progress`
- Test: `tests/unit/test-progress-math.php` (pure), `tests/test-courses-progress-service.php` (integration)

**Interfaces:**
- Consumes: `Content\Curriculum`, `Database\ProgressRepository`, `Services\EnrollmentService`, `Admin\CourseEditor::setting()`, `Admin\LessonEditor::setting()`, `Support\Clock`.
- Produces:
  - `ProgressService::percent( int $completed_required, int $total_required ): float` - **pure static**, 2dp, 100.0 when there is nothing required
  - `ProgressService::get_course_progress( int $user_id, int $course_id ): CourseProgress`
  - `::get_completed_items( int $user_id, int $course_id ): array` - `["lesson:12", ...]`
  - `::is_item_available( int $user_id, int $course_id, int $item_id, string $item_type = 'lesson' ): bool`
  - `::start_lesson( int $user_id, int $course_id, int $lesson_id ): ?Progress`
  - `::complete_lesson( int $user_id, int $course_id, int $lesson_id ): Progress|\WP_Error`
  - `::record_item( int $user_id, int $course_id, int $item_id, string $item_type, string $status, array $extra = [] ): ?Progress`
  - `::recalculate_course( int $user_id, int $course_id ): CourseProgress`
  - Actions: `anchor_courses_lesson_started( int $user_id, int $course_id, int $lesson_id, Progress $progress )`, `anchor_courses_lesson_completed( int $user_id, int $course_id, int $lesson_id, Progress $progress )`
  - Filter: `anchor_courses_can_access_lesson( bool $allowed, int $user_id, int $course_id, int $item_id, string $item_type )`
  - `::set_completion_service( CompletionService $service ): void` - Task 29 injects it; until then `recalculate_course()` only computes.

`complete_lesson()` error codes: `not_enrolled`, `not_in_course`, `locked`, `quiz_required`.

- [ ] **Step 1: Write the failing tests**

`tests/unit/test-progress-math.php`:

```php
<?php
/**
 * Pure unit test: the completion percentage formula (brief section 10).
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Services\ProgressService;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Progress_Math extends TestCase {

	public function test_percent_is_completed_over_total_times_one_hundred() {
		$this->assertSame( 0.0, ProgressService::percent( 0, 4 ) );
		$this->assertSame( 25.0, ProgressService::percent( 1, 4 ) );
		$this->assertSame( 100.0, ProgressService::percent( 4, 4 ) );
	}

	public function test_percent_rounds_to_two_decimals() {
		$this->assertSame( 33.33, ProgressService::percent( 1, 3 ) );
		$this->assertSame( 66.67, ProgressService::percent( 2, 3 ) );
	}

	/** A course with no required items is 100% by definition, never a divide by zero. */
	public function test_zero_required_items_is_one_hundred_percent() {
		$this->assertSame( 100.0, ProgressService::percent( 0, 0 ) );
	}

	public function test_percent_never_exceeds_one_hundred_or_drops_below_zero() {
		$this->assertSame( 100.0, ProgressService::percent( 9, 4 ) );
		$this->assertSame( 0.0, ProgressService::percent( -3, 4 ) );
	}
}
```

`tests/test-courses-progress-service.php`:

```php
<?php
/**
 * Anchor Courses - progression rules and lesson completion (brief 9, 10).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Domain\CourseProgress;
use Anchor\Courses\Domain\Progress;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

/** @group courses */
class Test_Courses_Progress_Service extends Anchor_Courses_TestCase {

	private ProgressService $progress;
	private EnrollmentService $enrollments;
	private int $user;
	private int $course;
	private int $l1;
	private int $l2;
	private int $l3;

	public function set_up() {
		parent::set_up();
		$this->progress    = new ProgressService();
		$this->enrollments = new EnrollmentService();

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'sequential' ] );
		$this->l1     = $this->make_lesson( [], 'L1' );
		$this->l2     = $this->make_lesson( [], 'L2' );
		$this->l3     = $this->make_lesson( [], 'L3' );

		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M1', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->l1 ],
				[ 'type' => 'lesson', 'id' => $this->l2 ],
				[ 'type' => 'lesson', 'id' => $this->l3, 'required' => false ],
			] ] ]
		);

		$this->enrollments->enroll( $this->user, $this->course );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_can_access_lesson' );
		remove_all_actions( 'anchor_courses_lesson_completed' );
		remove_all_actions( 'anchor_courses_lesson_started' );
		parent::tear_down();
	}

	public function test_a_new_learner_is_at_zero_percent() {
		$p = $this->progress->get_course_progress( $this->user, $this->course );
		$this->assertInstanceOf( CourseProgress::class, $p );
		$this->assertSame( 0.0, $p->percent );
		$this->assertSame( 2, $p->total_required, 'Only the two required lessons count.' );
		$this->assertFalse( $p->complete );
	}

	public function test_completing_a_lesson_moves_the_percentage_and_fires_the_action() {
		$fired = [];
		add_action( 'anchor_courses_lesson_completed', function ( $u, $c, $l ) use ( &$fired ) { $fired[] = $l; }, 10, 4 );

		$result = $this->progress->complete_lesson( $this->user, $this->course, $this->l1 );

		$this->assertInstanceOf( Progress::class, $result );
		$this->assertSame( 'completed', $result->status );
		$this->assertSame( [ $this->l1 ], $fired );
		$this->assertSame( 50.0, $this->progress->get_course_progress( $this->user, $this->course )->percent );
	}

	/** Brief 26: a repeated complete must not double-count or re-fire. */
	public function test_completing_twice_is_idempotent() {
		$count = 0;
		add_action( 'anchor_courses_lesson_completed', function () use ( &$count ) { $count++; }, 10, 4 );

		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );
		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );

		$this->assertSame( 1, $count );
		$this->assertSame( 50.0, $this->progress->get_course_progress( $this->user, $this->course )->percent );
	}

	public function test_optional_items_do_not_reduce_the_percentage() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );
		$this->progress->complete_lesson( $this->user, $this->course, $this->l2 );

		$p = $this->progress->get_course_progress( $this->user, $this->course );
		$this->assertSame( 100.0, $p->percent, 'The optional third lesson must not hold the course below 100%.' );
		$this->assertTrue( $p->complete );
	}

	public function test_sequential_progression_locks_later_lessons() {
		$this->assertTrue( $this->progress->is_item_available( $this->user, $this->course, $this->l1 ) );
		$this->assertFalse( $this->progress->is_item_available( $this->user, $this->course, $this->l2 ) );

		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );

		$this->assertTrue( $this->progress->is_item_available( $this->user, $this->course, $this->l2 ) );
	}

	public function test_completing_a_locked_lesson_is_refused() {
		$refused = $this->progress->complete_lesson( $this->user, $this->course, $this->l2 );
		$this->assertWPError( $refused );
		$this->assertSame( 'locked', $refused->get_error_code() );
	}

	public function test_free_progression_unlocks_everything() {
		update_post_meta( $this->course, '_anchor_course_progression_mode', 'free' );
		$this->assertTrue( $this->progress->is_item_available( $this->user, $this->course, $this->l3, 'lesson' ) );
	}

	public function test_an_unenrolled_user_cannot_complete_or_access() {
		$stranger = $this->make_learner();
		$this->assertFalse( $this->progress->is_item_available( $stranger, $this->course, $this->l1 ) );
		$this->assertSame( 'not_enrolled', $this->progress->complete_lesson( $stranger, $this->course, $this->l1 )->get_error_code() );
	}

	public function test_an_item_outside_the_curriculum_is_refused() {
		$orphan = $this->make_lesson();
		$this->assertSame( 'not_in_course', $this->progress->complete_lesson( $this->user, $this->course, $orphan )->get_error_code() );
	}

	public function test_a_quiz_pass_lesson_cannot_be_marked_complete_by_hand() {
		$quiz   = $this->make_quiz();
		$lesson = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $quiz ], 'Gated' );
		$course = $this->make_course( [ 'progression_mode' => 'free' ] );
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		$this->enrollments->enroll( $this->user, $course );

		$refused = $this->progress->complete_lesson( $this->user, $course, $lesson );

		$this->assertSame( 'quiz_required', $refused->get_error_code() );
	}

	public function test_start_lesson_records_a_view_and_fires_lesson_started_once() {
		$fired = 0;
		add_action( 'anchor_courses_lesson_started', function () use ( &$fired ) { $fired++; }, 10, 4 );

		$this->progress->start_lesson( $this->user, $this->course, $this->l1 );
		$this->progress->start_lesson( $this->user, $this->course, $this->l1 );

		$this->assertSame( 1, $fired );
		$this->assertSame( 'in_progress', $this->progress->get_course_progress( $this->user, $this->course )->percent > 0 ? 'in_progress' : 'in_progress' );
		$this->assertNotNull( $this->enrollments->get( $this->user, $this->course )->started_at );
	}

	public function test_a_view_completion_lesson_completes_on_start() {
		$lesson = $this->make_lesson( [ 'completion_mode' => 'view' ], 'Viewable' );
		$course = $this->make_course( [ 'progression_mode' => 'free' ] );
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		$this->enrollments->enroll( $this->user, $course );

		$this->progress->start_lesson( $this->user, $course, $lesson );

		$this->assertSame( 100.0, $this->progress->get_course_progress( $this->user, $course )->percent );
	}

	public function test_the_can_access_lesson_filter_can_veto() {
		add_filter( 'anchor_courses_can_access_lesson', '__return_false' );
		$this->assertFalse( $this->progress->is_item_available( $this->user, $this->course, $this->l1 ) );
	}

	public function test_minimum_percentage_completion_mode() {
		update_post_meta( $this->course, '_anchor_course_completion_mode', 'minimum_percentage' );
		update_post_meta( $this->course, '_anchor_course_completion_percentage', 50 );

		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );

		$this->assertTrue( $this->progress->get_course_progress( $this->user, $this->course )->complete );
	}

	public function test_manual_completion_mode_never_reports_complete_from_items() {
		update_post_meta( $this->course, '_anchor_course_completion_mode', 'manual' );
		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );
		$this->progress->complete_lesson( $this->user, $this->course, $this->l2 );

		$this->assertFalse( $this->progress->get_course_progress( $this->user, $this->course )->complete );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Progress_Math
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Progress_Service
```
Expected: both FAIL - `Class "Anchor\Courses\Services\ProgressService" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Services/ProgressService.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Admin\LessonEditor;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Database\ProgressRepository;
use Anchor\Courses\Domain\CourseProgress;
use Anchor\Courses\Domain\Progress;
use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The one place course progress is calculated (brief section 10).
 *
 * Templates, shortcodes, REST controllers and the admin reports all call this;
 * none of them recomputes completion for itself. percent() is a pure static so
 * the formula is unit-tested without WordPress.
 */
final class ProgressService {

	private ?CompletionService $completion = null;

	public function __construct( private ?EnrollmentService $enrollments = null ) {
		$this->enrollments = $enrollments ?? new EnrollmentService();
	}

	/** Injected by the Module once CompletionService exists (Task 29). */
	public function set_completion_service( CompletionService $service ): void {
		$this->completion = $service;
	}

	/**
	 * completed required items / total required items * 100, 2dp.
	 *
	 * A course with nothing required is 100% - there is nothing left to do, and
	 * the alternative is a division by zero. Pure.
	 */
	public static function percent( int $completed_required, int $total_required ): float {
		if ( $total_required <= 0 ) {
			return 100.0;
		}
		$raw = ( \max( 0, $completed_required ) / $total_required ) * 100;
		return \round( \min( 100.0, $raw ), 2 );
	}

	public function get_course_progress( int $user_id, int $course_id ): CourseProgress {
		$required  = Curriculum::required_items( $course_id );
		$completed = ProgressRepository::completed_keys( $user_id, $course_id );

		$done = 0;
		foreach ( $required as $item ) {
			if ( \in_array( $item['type'] . ':' . $item['id'], $completed, true ) ) {
				$done++;
			}
		}

		$total   = \count( $required );
		$percent = self::percent( $done, $total );

		$mode     = (string) CourseEditor::setting( $course_id, 'completion_mode' );
		$complete = false;

		if ( 'all_required_items' === $mode ) {
			$complete = $total > 0 && $done >= $total;
		} elseif ( 'minimum_percentage' === $mode ) {
			$complete = $percent >= (float) CourseEditor::setting( $course_id, 'completion_percentage' );
		}
		// 'manual' never derives completion from items - an admin marks it.

		/**
		 * Filter whether this learner's course counts as complete.
		 *
		 * @param bool $complete
		 * @param int  $user_id
		 * @param int  $course_id
		 */
		$complete = (bool) \apply_filters( 'anchor_courses_course_completion_status', $complete, $user_id, $course_id );

		return new CourseProgress( $user_id, $course_id, $done, $total, $percent, $complete, $completed );
	}

	/** @return string[] "{type}:{id}" */
	public function get_completed_items( int $user_id, int $course_id ): array {
		return ProgressRepository::completed_keys( $user_id, $course_id );
	}

	/**
	 * May this learner open this item right now?
	 *
	 * Sequential progression requires every REQUIRED item before it to be
	 * complete; optional items never block.
	 */
	public function is_item_available( int $user_id, int $course_id, int $item_id, string $item_type = 'lesson' ): bool {
		$allowed = true;

		if ( ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			$allowed = false;
		} elseif ( ! Curriculum::contains( $course_id, $item_id, $item_type ) ) {
			$allowed = false;
		} elseif ( 'sequential' === (string) CourseEditor::setting( $course_id, 'progression_mode' ) ) {
			$completed = ProgressRepository::completed_keys( $user_id, $course_id );
			foreach ( Curriculum::items_before( $course_id, $item_id, $item_type ) as $earlier ) {
				if ( ! $earlier['required'] ) {
					continue;
				}
				if ( ! \in_array( $earlier['type'] . ':' . $earlier['id'], $completed, true ) ) {
					$allowed = false;
					break;
				}
			}
		}

		/**
		 * Filter access to one curriculum item.
		 *
		 * @param bool   $allowed
		 * @param int    $user_id
		 * @param int    $course_id
		 * @param int    $item_id
		 * @param string $item_type
		 */
		return (bool) \apply_filters( 'anchor_courses_can_access_lesson', $allowed, $user_id, $course_id, $item_id, $item_type );
	}

	/**
	 * Record a view. Starts the enrolment on first contact, and completes the
	 * lesson immediately when its completion mode is `view`.
	 */
	public function start_lesson( int $user_id, int $course_id, int $lesson_id ): ?Progress {
		if ( ! $this->is_item_available( $user_id, $course_id, $lesson_id, 'lesson' ) ) {
			return null;
		}

		$this->enrollments->start( $user_id, $course_id );

		$existing = ProgressRepository::find( $user_id, $course_id, $lesson_id, 'lesson' );
		$now      = Clock::now();
		$first    = ! $existing instanceof Progress;

		$data = [
			'user_id'        => $user_id,
			'course_id'      => $course_id,
			'item_id'        => $lesson_id,
			'item_type'      => 'lesson',
			'last_viewed_at' => $now,
		];
		if ( $first ) {
			$data['status']     = 'in_progress';
			$data['started_at'] = $now;
		}

		$progress = ProgressRepository::upsert( $data );

		if ( $first && $progress instanceof Progress ) {
			/**
			 * Fires the first time a learner opens a lesson.
			 *
			 * @param int      $user_id
			 * @param int      $course_id
			 * @param int      $lesson_id
			 * @param Progress $progress
			 */
			\do_action( 'anchor_courses_lesson_started', $user_id, $course_id, $lesson_id, $progress );
		}

		if ( 'view' === (string) LessonEditor::setting( $lesson_id, 'completion_mode' ) ) {
			$completed = $this->complete_lesson( $user_id, $course_id, $lesson_id );
			if ( $completed instanceof Progress ) {
				return $completed;
			}
		}

		return $progress;
	}

	/**
	 * Complete a lesson. Idempotent - a second call returns the existing row and
	 * fires nothing.
	 *
	 * @return Progress|\WP_Error
	 */
	public function complete_lesson( int $user_id, int $course_id, int $lesson_id ) {
		if ( ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			return new \WP_Error( 'not_enrolled', \__( 'You are not enrolled in this course.', 'anchor-schema' ) );
		}
		if ( ! Curriculum::contains( $course_id, $lesson_id, 'lesson' ) ) {
			return new \WP_Error( 'not_in_course', \__( 'That lesson is not part of this course.', 'anchor-schema' ) );
		}

		$existing = ProgressRepository::find( $user_id, $course_id, $lesson_id, 'lesson' );
		if ( $existing instanceof Progress && $existing->is_complete() ) {
			return $existing;
		}

		if ( ! $this->is_item_available( $user_id, $course_id, $lesson_id, 'lesson' ) ) {
			return new \WP_Error( 'locked', \__( 'Finish the earlier lessons first.', 'anchor-schema' ) );
		}

		// A quiz-gated lesson is completed by the quiz service, never by hand.
		if ( 'quiz_pass' === (string) LessonEditor::setting( $lesson_id, 'completion_mode' )
			&& ! $this->quiz_passed_for_lesson( $user_id, $course_id, $lesson_id ) ) {
			return new \WP_Error( 'quiz_required', \__( 'Pass this lesson\'s quiz to complete it.', 'anchor-schema' ) );
		}

		$progress = $this->record_item( $user_id, $course_id, $lesson_id, 'lesson', 'completed' );
		if ( ! $progress instanceof Progress ) {
			return new \WP_Error( 'save_failed', \__( 'The progress could not be saved.', 'anchor-schema' ) );
		}

		/**
		 * Fires once, the first time a lesson is completed.
		 *
		 * @param int      $user_id
		 * @param int      $course_id
		 * @param int      $lesson_id
		 * @param Progress $progress
		 */
		\do_action( 'anchor_courses_lesson_completed', $user_id, $course_id, $lesson_id, $progress );

		$this->recalculate_course( $user_id, $course_id );

		return $progress;
	}

	/** Has the lesson's quiz been passed already? */
	private function quiz_passed_for_lesson( int $user_id, int $course_id, int $lesson_id ): bool {
		$quiz_id = (int) LessonEditor::setting( $lesson_id, 'quiz_id' );
		if ( $quiz_id <= 0 ) {
			return false;
		}
		$quiz_progress = ProgressRepository::find( $user_id, $course_id, $quiz_id, 'quiz' );
		return $quiz_progress instanceof Progress && $quiz_progress->is_complete();
	}

	/**
	 * Write one item's state. The single write path used by both the lesson
	 * flow and the quiz service.
	 */
	public function record_item(
		int $user_id,
		int $course_id,
		int $item_id,
		string $item_type,
		string $status,
		array $extra = []
	): ?Progress {
		if ( ! \in_array( $status, Progress::STATUSES, true ) ) {
			return null;
		}

		$now  = Clock::now();
		$data = \array_merge(
			[
				'user_id'          => $user_id,
				'course_id'        => $course_id,
				'item_id'          => $item_id,
				'item_type'        => $item_type,
				'status'           => $status,
				'progress_percent' => 'completed' === $status ? 100.0 : 0.0,
				'last_viewed_at'   => $now,
			],
			$extra
		);

		if ( 'completed' === $status && ! isset( $data['completed_at'] ) ) {
			$data['completed_at'] = $now;
		}

		$existing = ProgressRepository::find( $user_id, $course_id, $item_id, $item_type );
		if ( ! $existing instanceof Progress ) {
			$data['started_at'] = $data['started_at'] ?? $now;
		}

		return ProgressRepository::upsert( $data );
	}

	/**
	 * Recompute the rolled-up progress and hand it to the completion service.
	 *
	 * Called after every write. CompletionService is responsible for the
	 * once-only side effects (credits, certificate, hooks).
	 */
	public function recalculate_course( int $user_id, int $course_id ): CourseProgress {
		$progress = $this->get_course_progress( $user_id, $course_id );

		if ( $progress->complete && $this->completion instanceof CompletionService ) {
			$this->completion->complete( $user_id, $course_id );
		}

		return $progress;
	}
}
```

`anchor-courses/anchor-courses.php` - add the property and construct it after `$this->enrollments`:

```php
	public Services\ProgressService $progress;
```

```php
		$this->progress = new Services\ProgressService( $this->enrollments );
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Progress_Math
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Progress_Service
```
Expected: PASS (4 unit) and PASS (15 integration).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Services/ProgressService.php anchor-courses/anchor-courses.php \
        tests/unit/test-progress-math.php tests/test-courses-progress-service.php
git commit -m "feat(courses): progress service with sequential progression and completion maths"
```

---

### Task 17: Public progress API and the mark-complete form handler

**Files:**
- Modify: `anchor-courses/api.php` - add `anchor_courses_complete_lesson()` and `anchor_courses_get_progress()`
- Create: `anchor-courses/src/Frontend/Actions.php`
- Modify: `anchor-courses/anchor-courses.php` - construct `Frontend\Actions`
- Test: `tests/test-courses-progress-api.php`

**Interfaces:**
- Produces:
  - `anchor_courses_complete_lesson( int $user_id, int $course_id, int $lesson_id ): Progress|WP_Error`
  - `anchor_courses_get_progress( int $user_id, int $course_id ): CourseProgress`
  - `Frontend\Actions::NONCE_COMPLETE = 'anchor_courses_complete'`
  - `Frontend\Actions::handle_complete_lesson(): void` - `admin_post_anchor_courses_complete_lesson`, logged-in only, nonce `anchor_courses_complete_{lesson_id}`, redirects back with `anchor_courses_notice`
  - `Frontend\Actions::complete_url( int $course_id, int $lesson_id ): string`
  - `Frontend\Actions::notice(): string` - the decoded notice for the current request, `''` when none

There is deliberately **no** `wp_ajax_` route for lesson completion: the form posts to `admin-post.php` so it works without JavaScript, and REST (Task 33) covers scripted clients.

There is also deliberately **no enrol handler** - no `NONCE_ENROLL`, no `enroll_url()`, no `handle_enroll()`. Self-enrolment does not exist (design spec 7): a learner gets `anchor_course_{id}` by buying a product mapped to the course or by an admin adding them on the Learners tab. A front-end form that called `EnrollmentService::enroll()` would be a second, unguarded door into the same room.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - public progress API and the no-JS completion form.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Domain\CourseProgress;
use Anchor\Courses\Domain\Progress;
use Anchor\Courses\Frontend\Actions;
use Anchor\Courses\Services\EnrollmentService;

/** Thrown from the wp_redirect filter so the handler's exit() never runs. */
class Anchor_Courses_Redirected extends \Exception {}

/** @group courses */
class Test_Courses_Progress_Api extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->lesson = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Courses_Redirected( (string) $location );
	}

	public function test_complete_lesson_api_delegates_to_the_service() {
		$result = anchor_courses_complete_lesson( $this->user, $this->course, $this->lesson );
		$this->assertInstanceOf( Progress::class, $result );
		$this->assertSame( 'completed', $result->status );
	}

	public function test_get_progress_api_returns_a_course_progress() {
		$before = anchor_courses_get_progress( $this->user, $this->course );
		$this->assertInstanceOf( CourseProgress::class, $before );
		$this->assertSame( 0.0, $before->percent );

		anchor_courses_complete_lesson( $this->user, $this->course, $this->lesson );

		$this->assertSame( 100.0, anchor_courses_get_progress( $this->user, $this->course )->percent );
	}

	public function test_the_form_handler_completes_the_lesson_and_redirects() {
		wp_set_current_user( $this->user );
		$_POST = [
			'course_id' => (string) $this->course,
			'lesson_id' => (string) $this->lesson,
			'_wpnonce'  => wp_create_nonce( Actions::NONCE_COMPLETE . '_' . $this->lesson ),
			'_redirect' => get_permalink( $this->lesson ),
		];

		try {
			( new Actions() )->handle_complete_lesson();
			$this->fail( 'Expected a redirect.' );
		} catch ( Anchor_Courses_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_notice=completed', $e->getMessage() );
		}

		$this->assertSame( 100.0, anchor_courses_get_progress( $this->user, $this->course )->percent );
	}

	public function test_the_form_handler_refuses_a_bad_nonce() {
		wp_set_current_user( $this->user );
		$_POST = [
			'course_id' => (string) $this->course,
			'lesson_id' => (string) $this->lesson,
			'_wpnonce'  => 'not-a-nonce',
		];

		try {
			( new Actions() )->handle_complete_lesson();
			$this->fail( 'Expected a redirect.' );
		} catch ( Anchor_Courses_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_notice=bad_nonce', $e->getMessage() );
		}

		$this->assertSame( 0.0, anchor_courses_get_progress( $this->user, $this->course )->percent );
	}

	public function test_the_form_handler_refuses_a_logged_out_visitor() {
		wp_set_current_user( 0 );
		$_POST = [
			'course_id' => (string) $this->course,
			'lesson_id' => (string) $this->lesson,
			'_wpnonce'  => wp_create_nonce( Actions::NONCE_COMPLETE . '_' . $this->lesson ),
		];

		try {
			( new Actions() )->handle_complete_lesson();
			$this->fail( 'Expected a redirect.' );
		} catch ( Anchor_Courses_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_notice=login_required', $e->getMessage() );
		}
	}

	public function test_a_service_error_becomes_a_notice_not_a_fatal() {
		$stranger = $this->make_learner();
		wp_set_current_user( $stranger );
		$_POST = [
			'course_id' => (string) $this->course,
			'lesson_id' => (string) $this->lesson,
			'_wpnonce'  => wp_create_nonce( Actions::NONCE_COMPLETE . '_' . $this->lesson ),
		];

		try {
			( new Actions() )->handle_complete_lesson();
			$this->fail( 'Expected a redirect.' );
		} catch ( Anchor_Courses_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_notice=not_enrolled', $e->getMessage() );
		}
	}

	public function test_complete_url_carries_the_action_and_ids() {
		$url = Actions::complete_url( $this->course, $this->lesson );
		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=anchor_courses_complete_lesson', $url );
	}

	/** Self-enrolment does not exist, so neither does an enrol endpoint. */
	public function test_there_is_no_self_enrolment_endpoint() {
		new Actions();

		$this->assertFalse( has_action( 'admin_post_anchor_courses_enroll' ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_anchor_courses_enroll' ) );
		$this->assertFalse( method_exists( Actions::class, 'handle_enroll' ) );
		$this->assertFalse( method_exists( Actions::class, 'enroll_url' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Progress_Api
```
Expected: FAIL - `Call to undefined function anchor_courses_complete_lesson()`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/api.php` - append:

```php
/**
 * Mark a lesson complete for a user (brief section 15).
 *
 * Idempotent, and subject to enrolment + progression rules.
 *
 * @param int $user_id
 * @param int $course_id
 * @param int $lesson_id
 * @return \Anchor\Courses\Domain\Progress|WP_Error
 */
function anchor_courses_complete_lesson( $user_id, $course_id, $lesson_id ) {
	return ( new \Anchor\Courses\Services\ProgressService() )
		->complete_lesson( (int) $user_id, (int) $course_id, (int) $lesson_id );
}

/**
 * Rolled-up progress for one learner on one course (brief section 15).
 *
 * @param int $user_id
 * @param int $course_id
 * @return \Anchor\Courses\Domain\CourseProgress
 */
function anchor_courses_get_progress( $user_id, $course_id ) {
	return ( new \Anchor\Courses\Services\ProgressService() )
		->get_course_progress( (int) $user_id, (int) $course_id );
}
```

`anchor-courses/src/Frontend/Actions.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Domain\Progress;
use Anchor\Courses\Services\ProgressService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Form posts from the front end.
 *
 * admin-post.php rather than admin-ajax.php or REST, so "Mark complete" works
 * with JavaScript switched off. The handler verifies login, nonce and service
 * rules, then redirects with a notice code - never a raw message, so nothing
 * user-supplied reaches the page.
 *
 * "Mark complete" is the ONLY thing a learner may post. There is no enrol
 * action: access arrives as a role, granted by a purchase or an admin.
 */
final class Actions {

	public const NONCE_COMPLETE = 'anchor_courses_complete';

	/** Notice codes this module will render. Anything else is ignored. */
	public const NOTICES = [
		'completed', 'bad_nonce', 'login_required', 'not_enrolled',
		'locked', 'quiz_required', 'not_in_course', 'error',
	];

	public function __construct( private ?ProgressService $progress = null ) {
		$this->progress = $progress ?? new ProgressService();

		\add_action( 'admin_post_anchor_courses_complete_lesson', [ $this, 'handle_complete_lesson' ] );
		\add_action( 'admin_post_nopriv_anchor_courses_complete_lesson', [ $this, 'handle_complete_lesson' ] );
	}

	public static function complete_url( int $course_id, int $lesson_id ): string {
		return \add_query_arg(
			[
				'action'    => 'anchor_courses_complete_lesson',
				'course_id' => $course_id,
				'lesson_id' => $lesson_id,
			],
			\admin_url( 'admin-post.php' )
		);
	}

	/** The notice code on the current request, or ''. */
	public static function notice(): string {
		$code = \sanitize_key( \wp_unslash( (string) ( $_GET['anchor_courses_notice'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return \in_array( $code, self::NOTICES, true ) ? $code : '';
	}

	/** Human text for a notice code. */
	public static function notice_text( string $code ): string {
		$messages = [
			'completed'      => \__( 'Lesson marked complete.', 'anchor-schema' ),
			'bad_nonce'      => \__( 'That link expired. Please try again.', 'anchor-schema' ),
			'login_required' => \__( 'Please sign in first.', 'anchor-schema' ),
			'not_enrolled'   => \__( 'You do not have access to this course.', 'anchor-schema' ),
			'locked'         => \__( 'Finish the earlier lessons first.', 'anchor-schema' ),
			'quiz_required'  => \__( 'Pass this lesson\'s quiz to complete it.', 'anchor-schema' ),
			'not_in_course'  => \__( 'That lesson is not part of this course.', 'anchor-schema' ),
			'error'          => \__( 'Something went wrong. Please try again.', 'anchor-schema' ),
		];
		return $messages[ $code ] ?? '';
	}

	public function handle_complete_lesson(): void {
		$course_id = \absint( $_POST['course_id'] ?? $_GET['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$lesson_id = \absint( $_POST['lesson_id'] ?? $_GET['lesson_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! \is_user_logged_in() ) {
			$this->redirect( 'login_required', $lesson_id );
		}

		$nonce = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['_wpnonce'] ?? '' ) ) );
		if ( ! \wp_verify_nonce( $nonce, self::NONCE_COMPLETE . '_' . $lesson_id ) ) {
			$this->redirect( 'bad_nonce', $lesson_id );
		}

		$result = $this->progress->complete_lesson( \get_current_user_id(), $course_id, $lesson_id );

		$this->redirect(
			\is_wp_error( $result ) ? (string) $result->get_error_code() : 'completed',
			$lesson_id
		);
	}

	/** Redirect back with a notice CODE (never a message) and stop. */
	private function redirect( string $code, int $fallback_post_id ): void {
		if ( ! \in_array( $code, self::NOTICES, true ) ) {
			$code = 'error';
		}

		$requested = \wp_unslash( (string) ( $_REQUEST['_redirect'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$target    = '' !== $requested
			? \wp_validate_redirect( $requested, (string) \get_permalink( $fallback_post_id ) )
			: (string) \get_permalink( $fallback_post_id );

		if ( '' === $target ) {
			$target = \home_url( '/' );
		}

		\wp_safe_redirect( \add_query_arg( 'anchor_courses_notice', $code, $target ) );
		exit;
	}
}
```

`anchor-courses/anchor-courses.php` - construct it unconditionally (the handler lives on `admin-post.php`):

```php
		new Frontend\Actions( $this->progress );
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Progress_Api
```
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/api.php anchor-courses/src/Frontend/Actions.php \
        anchor-courses/anchor-courses.php tests/test-courses-progress-api.php
git commit -m "feat(courses): public progress API and the no-JS completion form handler"
```

---

### Task 18: Front-end templates, shortcodes and assets

**Files:**
- Create: `anchor-courses/src/Frontend/Templates.php`, `Assets.php`, `Shortcodes.php`, `Access.php`
- Create: `anchor-courses/templates/course.php`, `lesson.php`, `dashboard.php`
- Create: `anchor-courses/assets/frontend.css`, `anchor-courses/assets/frontend.js`
- Modify: `anchor-courses/anchor-courses.php` - construct `Templates`, `Assets` and `Shortcodes` (`Access` is static)
- Test: `tests/test-courses-shortcodes.php`

**Interfaces:**
- Consumes: `ProgressService`, `EnrollmentService`, `Curriculum`, `Frontend\Actions`.
- Produces:
  - `Frontend\Templates::locate( string $name ): string` - theme override at `anchor-courses/{$name}.php` via `locate_template()`, else the plugin file
  - `Frontend\Templates::render( string $name, array $vars = [] ): string` - buffered include, `$vars` extracted
  - `Frontend\Templates::template_include( string $template ): string` - `template_include` filter for single course/lesson
  - `Frontend\Assets::enqueue(): void` - `wp_enqueue_scripts`, only on courses screens; localises `anchorCourses` = `{restUrl, nonce, dataLayer: bool}`
  - `Frontend\Shortcodes` registering: `[anchor_courses]`, `[anchor_course id]`, `[anchor_course_progress course_id]`, `[anchor_my_courses]`, `[anchor_my_credits]`, `[anchor_my_certificates]` (the last two render "no records yet" until Phase 4 fills them)
  - `Frontend\Access::cta( int $course_id, int $user_id = 0 ): array{url:string,label:string,message:string}` - the one answer to "how does this visitor get access?"
  - Filters: `anchor_courses_no_access_message( string $message, int $course_id )`, `anchor_courses_access_cta( array $cta, int $course_id, int $user_id )`
  - Each callback uses `ob_start()` / `return ob_get_clean()` per repo convention.

**No Enrol button.** A course page never offers self-enrolment (design spec 7). `Access::cta()` returns a *message* by default - "Ask us about access", filterable via `anchor_courses_no_access_message` - and returns a *link* only when something has filtered `anchor_courses_access_cta` to supply one. The WooCommerce adapter (Task 38) is that something: when a product maps to the course it filters in the product permalink with the label "Enrol". The core never mentions WooCommerce, and a site with no store shows the message. That is why the CTA is a filter and not an `if ( class_exists( 'WooCommerce' ) )` branch in the template.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - shortcodes and template resolution (brief 22, 23).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Frontend\Templates;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

/** @group courses */
class Test_Courses_Shortcodes extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free', 'instructor' => 'Dr Vega' ], 'Laser Safety' );
		$this->lesson = $this->make_lesson( [], 'Optics' );
		Curriculum::save( $this->course, [ [ 'title' => 'Fundamentals', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
	}

	public function test_every_phase_one_shortcode_is_registered() {
		foreach ( [ 'anchor_courses', 'anchor_course', 'anchor_course_progress', 'anchor_my_courses', 'anchor_my_credits', 'anchor_my_certificates' ] as $tag ) {
			$this->assertTrue( shortcode_exists( $tag ), "Missing shortcode [{$tag}]" );
		}
	}

	public function test_courses_index_lists_published_courses() {
		$html = do_shortcode( '[anchor_courses]' );
		$this->assertStringContainsString( 'Laser Safety', $html );
		$this->assertStringContainsString( 'anchor-courses-list', $html );
	}

	public function test_single_course_shows_curriculum_and_instructor() {
		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );
		$this->assertStringContainsString( 'Fundamentals', $html );
		$this->assertStringContainsString( 'Optics', $html );
		$this->assertStringContainsString( 'Dr Vega', $html );
	}

	/** No product, no self-enrolment: the page says how to ask, and offers no button. */
	public function test_a_course_with_no_product_shows_the_ask_us_message() {
		wp_set_current_user( $this->user );
		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringContainsString( 'Ask us about access', $html );
		$this->assertStringNotContainsString( 'anchor_courses_enroll', $html, 'There is no self-enrolment endpoint to post to.' );
		$this->assertStringNotContainsString( '<button', $html );
	}

	public function test_the_no_access_message_is_filterable() {
		wp_set_current_user( $this->user );
		add_filter( 'anchor_courses_no_access_message', static fn() => 'Call the Academy on 555-0100.' );

		$this->assertStringContainsString( 'Call the Academy on 555-0100.', do_shortcode( '[anchor_course id="' . $this->course . '"]' ) );

		remove_all_filters( 'anchor_courses_no_access_message' );
	}

	/** An integration (in production, WooCommerce) may supply a real CTA link. */
	public function test_a_filtered_cta_renders_as_a_link_not_a_form() {
		wp_set_current_user( $this->user );
		add_filter(
			'anchor_courses_access_cta',
			static fn( $cta ) => [ 'url' => 'https://example.test/product/laser-safety/', 'label' => 'Enrol', 'message' => '' ],
			10,
			3
		);

		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringContainsString( 'https://example.test/product/laser-safety/', $html );
		$this->assertStringContainsString( 'Enrol', $html );
		$this->assertStringNotContainsString( '<form', $html );

		remove_all_filters( 'anchor_courses_access_cta' );
	}

	/** An enrolled learner is offered neither: they get on with the course. */
	public function test_an_enrolled_learner_sees_no_access_cta() {
		wp_set_current_user( $this->user );
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringNotContainsString( 'Ask us about access', $html );
	}

	public function test_progress_shortcode_reflects_completion() {
		wp_set_current_user( $this->user );
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		$before = do_shortcode( '[anchor_course_progress course_id="' . $this->course . '"]' );
		$this->assertStringContainsString( '0%', $before );

		( new ProgressService() )->complete_lesson( $this->user, $this->course, $this->lesson );

		$after = do_shortcode( '[anchor_course_progress course_id="' . $this->course . '"]' );
		$this->assertStringContainsString( '100%', $after );
	}

	public function test_my_courses_requires_login_and_then_lists_enrolments() {
		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'sign in', strtolower( do_shortcode( '[anchor_my_courses]' ) ) );

		wp_set_current_user( $this->user );
		( new EnrollmentService() )->enroll( $this->user, $this->course );
		$this->assertStringContainsString( 'Laser Safety', do_shortcode( '[anchor_my_courses]' ) );
	}

	public function test_output_is_escaped() {
		$nasty = $this->make_course( [], '<script>alert(1)</script>' );
		$html  = do_shortcode( '[anchor_course id="' . $nasty . '"]' );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_an_unknown_course_id_renders_nothing_loud() {
		$this->assertSame( '', trim( do_shortcode( '[anchor_course id="999999"]' ) ) );
	}

	public function test_templates_locate_prefers_a_theme_override() {
		$plugin_path = Templates::locate( 'course' );
		$this->assertStringContainsString( 'anchor-courses/templates/course.php', $plugin_path );
		$this->assertFileExists( $plugin_path );
	}

	public function test_frontend_assets_enqueue_on_a_course_page() {
		$this->go_to( get_permalink( $this->course ) );
		( new \Anchor\Courses\Frontend\Assets() )->enqueue();
		$this->assertTrue( wp_style_is( 'anchor-courses-frontend', 'enqueued' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Shortcodes
```
Expected: FAIL - `shortcode_exists('anchor_courses')` is false.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Frontend/Templates.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Template resolution.
 *
 * A theme overrides any template by dropping `anchor-courses/{name}.php` into
 * its root - the same convention the events module uses for `events/`. The
 * plugin templates are theme-agnostic: markup plus escaping, no business logic
 * (brief rule 3).
 */
final class Templates {

	public function __construct() {
		\add_filter( 'template_include', [ $this, 'template_include' ] );
	}

	public static function locate( string $name ): string {
		$override = \locate_template( [ 'anchor-courses/' . $name . '.php' ] );
		if ( '' !== $override ) {
			return $override;
		}
		return Module::dir() . 'templates/' . $name . '.php';
	}

	/** Render a template to a string. $vars become local variables. */
	public static function render( string $name, array $vars = [] ): string {
		$file = self::locate( $name );
		if ( ! \file_exists( $file ) ) {
			return '';
		}

		\extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		\ob_start();
		include $file;
		return (string) \ob_get_clean();
	}

	/** Use the plugin's single templates unless the theme already has one. */
	public function template_include( string $template ): string {
		if ( \is_singular( CoursePostType::CPT ) ) {
			$file = self::locate( 'single-course' );
			return \file_exists( $file ) ? $file : $template;
		}
		if ( \is_singular( LessonPostType::CPT ) ) {
			$file = self::locate( 'single-lesson' );
			return \file_exists( $file ) ? $file : $template;
		}
		return $template;
	}
}
```

`anchor-courses/src/Frontend/Assets.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Front-end enqueues. Source files only; CI minifies. */
final class Assets {

	public function __construct() {
		\add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		if ( ! $this->is_courses_screen() ) {
			return;
		}

		\wp_enqueue_style( 'anchor-courses-frontend', Module::assets_url() . 'frontend.css', [], Module::VERSION );
		\wp_enqueue_script(
			'anchor-courses-frontend',
			Module::assets_url() . 'frontend.js',
			[ 'jquery' ],
			Module::VERSION,
			true
		);
		\wp_localize_script(
			'anchor-courses-frontend',
			'anchorCourses',
			[
				'restUrl' => \esc_url_raw( \rest_url( 'anchor-courses/v1/' ) ),
				'nonce'   => \wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/** Courses content, or a page carrying one of our shortcodes. */
	private function is_courses_screen(): bool {
		if ( \is_singular( [ CoursePostType::CPT, LessonPostType::CPT ] ) || \is_post_type_archive( CoursePostType::CPT ) ) {
			return true;
		}

		$post = \get_post();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		foreach ( [ 'anchor_courses', 'anchor_course', 'anchor_course_progress', 'anchor_my_courses', 'anchor_my_credits', 'anchor_my_certificates' ] as $tag ) {
			if ( \has_shortcode( (string) $post->post_content, $tag ) ) {
				return true;
			}
		}
		return false;
	}
}
```

`anchor-courses/src/Frontend/Shortcodes.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The Phase 1 shortcodes (brief section 23). Presentation only. */
final class Shortcodes {

	public function __construct(
		private ProgressService $progress,
		private EnrollmentService $enrollments
	) {
		\add_shortcode( 'anchor_courses', [ $this, 'courses_index' ] );
		\add_shortcode( 'anchor_course', [ $this, 'single_course' ] );
		\add_shortcode( 'anchor_course_progress', [ $this, 'course_progress' ] );
		\add_shortcode( 'anchor_my_courses', [ $this, 'my_courses' ] );
		\add_shortcode( 'anchor_my_credits', [ $this, 'my_credits' ] );
		\add_shortcode( 'anchor_my_certificates', [ $this, 'my_certificates' ] );
	}

	public function courses_index( $atts = [] ): string {
		$atts = \shortcode_atts( [ 'limit' => 20 ], (array) $atts, 'anchor_courses' );

		$courses = \get_posts(
			[
				'post_type'      => CoursePostType::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => \max( 1, (int) $atts['limit'] ),
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		\ob_start();
		echo '<ul class="anchor-courses-list">';
		foreach ( $courses as $course ) {
			\printf(
				'<li class="anchor-courses-list-item"><a href="%s">%s</a>%s</li>',
				\esc_url( (string) \get_permalink( $course ) ),
				\esc_html( (string) $course->post_title ),
				$this->credits_badge( (int) $course->ID )
			);
		}
		echo '</ul>';
		return (string) \ob_get_clean();
	}

	private function credits_badge( int $course_id ): string {
		$credits = (float) CourseEditor::setting( $course_id, 'ce_credits' );
		if ( $credits <= 0 ) {
			return '';
		}
		return \sprintf(
			' <span class="anchor-courses-credits">%s</span>',
			\esc_html(
				\sprintf(
					/* translators: %s: number of CE credits. */
					\__( '%s CE credits', 'anchor-schema' ),
					\number_format_i18n( $credits, 1 )
				)
			)
		);
	}

	public function single_course( $atts = [] ): string {
		$atts      = \shortcode_atts( [ 'id' => 0 ], (array) $atts, 'anchor_course' );
		$course_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : (int) \get_the_ID();

		if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			return '';
		}

		$user_id = \get_current_user_id();

		return Templates::render(
			'course',
			[
				'course_id'  => $course_id,
				'user_id'    => $user_id,
				'modules'    => Curriculum::get( $course_id ),
				'progress'   => $user_id > 0 ? $this->progress->get_course_progress( $user_id, $course_id ) : null,
				'enrollment' => $user_id > 0 ? $this->enrollments->get( $user_id, $course_id ) : null,
				'service'    => $this->progress,
			]
		);
	}

	public function course_progress( $atts = [] ): string {
		$atts      = \shortcode_atts( [ 'course_id' => 0 ], (array) $atts, 'anchor_course_progress' );
		$course_id = (int) $atts['course_id'] > 0 ? (int) $atts['course_id'] : (int) \get_the_ID();
		$user_id   = \get_current_user_id();

		if ( $user_id <= 0 || CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			return '';
		}

		$progress = $this->progress->get_course_progress( $user_id, $course_id );

		\ob_start();
		\printf(
			'<div class="anchor-courses-progress"><div class="anchor-courses-bar"><span style="width:%1$s%%"></span></div>'
			. '<p class="anchor-courses-progress-label">%2$s</p></div>',
			\esc_attr( (string) $progress->percent ),
			\esc_html(
				\sprintf(
					/* translators: 1: percent complete, 2: completed items, 3: total items. */
					\__( '%1$s%% complete (%2$d of %3$d)', 'anchor-schema' ),
					\number_format_i18n( $progress->percent, 0 ),
					$progress->completed_required,
					$progress->total_required
				)
			)
		);
		return (string) \ob_get_clean();
	}

	public function my_courses(): string {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your courses.', 'anchor-schema' ) . '</p>';
		}

		return Templates::render(
			'dashboard',
			[
				'user_id'     => $user_id,
				'enrollments' => $this->enrollments->get_for_user( $user_id ),
				'progress'    => $this->progress,
			]
		);
	}

	/** Filled in by Task 30 (CreditService). */
	public function my_credits(): string {
		if ( \get_current_user_id() <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your CE credits.', 'anchor-schema' ) . '</p>';
		}
		return '<p class="anchor-courses-notice">' . \esc_html__( 'No CE credits yet.', 'anchor-schema' ) . '</p>';
	}

	/** Filled in by Task 30 (CertificateService). */
	public function my_certificates(): string {
		if ( \get_current_user_id() <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your certificates.', 'anchor-schema' ) . '</p>';
		}
		return '<p class="anchor-courses-notice">' . \esc_html__( 'No certificates yet.', 'anchor-schema' ) . '</p>';
	}
}
```

Add to `EnrollmentService` (used by the dashboard):

```php
	/** @return \Anchor\Courses\Domain\Enrollment[] */
	public function get_for_user( int $user_id, array $statuses = [] ): array {
		return EnrollmentRepository::for_user( $user_id, $statuses );
	}
```

`anchor-courses/templates/course.php`:

```php
<?php
/**
 * Course page body.
 *
 * Variables: $course_id, $user_id, $modules, $progress (CourseProgress|null),
 * $enrollment (Enrollment|null), $service (ProgressService).
 *
 * Theme override: anchor-courses/course.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Frontend\Access;
use Anchor\Courses\Frontend\Actions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$notice = Actions::notice();
?>
<div class="anchor-course" data-course="<?php echo esc_attr( (string) $course_id ); ?>">

	<?php if ( '' !== $notice ) : ?>
		<p class="anchor-courses-notice"><?php echo esc_html( Actions::notice_text( $notice ) ); ?></p>
	<?php endif; ?>

	<h2 class="anchor-course-title"><?php echo esc_html( get_the_title( $course_id ) ); ?></h2>

	<div class="anchor-course-meta">
		<?php $instructor = (string) CourseEditor::setting( $course_id, 'instructor' ); ?>
		<?php if ( '' !== $instructor ) : ?>
			<span class="anchor-course-instructor"><?php echo esc_html( $instructor ); ?></span>
		<?php endif; ?>

		<?php $duration = (string) CourseEditor::setting( $course_id, 'duration' ); ?>
		<?php if ( '' !== $duration ) : ?>
			<span class="anchor-course-duration"><?php echo esc_html( $duration ); ?></span>
		<?php endif; ?>

		<?php $credits = (float) CourseEditor::setting( $course_id, 'ce_credits' ); ?>
		<?php if ( $credits > 0 ) : ?>
			<span class="anchor-course-credits">
				<?php
				printf(
					/* translators: %s: number of CE credits. */
					esc_html__( '%s CE credits', 'anchor-schema' ),
					esc_html( number_format_i18n( $credits, 1 ) )
				);
				?>
			</span>
		<?php endif; ?>
	</div>

	<div class="anchor-course-description"><?php echo wp_kses_post( get_the_excerpt( $course_id ) ); ?></div>

	<?php if ( $progress && $enrollment ) : ?>
		<?php echo do_shortcode( '[anchor_course_progress course_id="' . (int) $course_id . '"]' ); ?>
	<?php endif; ?>

	<ol class="anchor-course-curriculum">
		<?php foreach ( $modules as $module ) : ?>
			<li class="anchor-course-module">
				<h3 class="anchor-course-module-title"><?php echo esc_html( $module['title'] ); ?></h3>
				<?php if ( '' !== $module['description'] ) : ?>
					<div class="anchor-course-module-description"><?php echo wp_kses_post( $module['description'] ); ?></div>
				<?php endif; ?>
				<ul class="anchor-course-items">
					<?php foreach ( $module['items'] as $item ) : ?>
						<?php
						$available = $user_id > 0 && $service->is_item_available( $user_id, $course_id, (int) $item['id'], $item['type'] );
						$done      = $progress && in_array( $item['type'] . ':' . $item['id'], $progress->completed_item_keys, true );
						?>
						<li class="anchor-course-item anchor-course-item--<?php echo esc_attr( $item['type'] ); ?><?php echo $done ? ' is-complete' : ''; ?><?php echo $available ? '' : ' is-locked'; ?>">
							<?php if ( $available && 'lesson' === $item['type'] ) : ?>
								<a href="<?php echo esc_url( (string) get_permalink( (int) $item['id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $item['id'] ) ); ?></a>
							<?php else : ?>
								<span><?php echo esc_html( get_the_title( (int) $item['id'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( ! $item['required'] ) : ?>
								<em class="anchor-course-optional"><?php esc_html_e( 'Optional', 'anchor-schema' ); ?></em>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</li>
		<?php endforeach; ?>
	</ol>

	<?php
	/*
	 * Access CTA. Never a self-enrol form: either a link an integration
	 * supplied (a WooCommerce product that grants this course), or a line of
	 * text telling the visitor how to ask. See Frontend\Access.
	 */
	if ( ! $enrollment ) :
		$cta = Access::cta( $course_id, $user_id );
		?>
		<?php if ( '' !== $cta['url'] ) : ?>
			<p class="anchor-course-cta">
				<a class="anchor-courses-button" href="<?php echo esc_url( $cta['url'] ); ?>">
					<?php echo esc_html( $cta['label'] ); ?>
				</a>
			</p>
		<?php elseif ( '' !== $cta['message'] ) : ?>
			<p class="anchor-course-cta anchor-course-cta--ask"><?php echo esc_html( $cta['message'] ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
```

`anchor-courses/src/Frontend/Access.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * How does this visitor get access to this course?
 *
 * Exactly one answer, in one place, used by the course template, the course
 * list and anything else that needs to say something to a visitor who is not
 * enrolled. It is deliberately NOT an "Enrol" button: self-enrolment does not
 * exist (design spec 7), so the honest default is a sentence telling them how
 * to ask.
 *
 * An integration turns that sentence into a link by filtering
 * `anchor_courses_access_cta`. In this repo that is the WooCommerce adapter,
 * which supplies the permalink of a product mapped to the course. The core
 * knows nothing about it, so a site with no store still renders correctly.
 */
final class Access {

	/**
	 * @return array{url:string,label:string,message:string}
	 */
	public static function cta( int $course_id, int $user_id = 0 ): array {
		/**
		 * The text shown when nothing sells or grants this course.
		 *
		 * @param string $message
		 * @param int    $course_id
		 */
		$message = (string) \apply_filters(
			'anchor_courses_no_access_message',
			\__( 'Ask us about access to this course.', 'anchor-schema' ),
			$course_id
		);

		$cta = [ 'url' => '', 'label' => '', 'message' => $message ];

		/**
		 * Filter the access call to action.
		 *
		 * Return a `url` + `label` to render a link instead of the message.
		 * Anything that can grant `anchor_course_{id}` - a product, a form, a
		 * partner portal - belongs here.
		 *
		 * @param array{url:string,label:string,message:string} $cta
		 * @param int                                           $course_id
		 * @param int                                           $user_id
		 */
		$cta = (array) \apply_filters( 'anchor_courses_access_cta', $cta, $course_id, $user_id );

		return [
			'url'     => (string) ( $cta['url'] ?? '' ),
			'label'   => (string) ( $cta['label'] ?? '' ),
			'message' => (string) ( $cta['message'] ?? '' ),
		];
	}
}
```

`anchor-courses/templates/lesson.php`:

```php
<?php
/**
 * Lesson page body.
 *
 * Variables: $lesson_id, $course_id, $user_id, $available (bool),
 * $complete (bool), $previous (int), $next (int).
 *
 * Theme override: anchor-courses/lesson.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Frontend\Actions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$notice = Actions::notice();
?>
<div class="anchor-lesson" data-lesson="<?php echo esc_attr( (string) $lesson_id ); ?>" data-course="<?php echo esc_attr( (string) $course_id ); ?>">

	<?php if ( '' !== $notice ) : ?>
		<p class="anchor-courses-notice"><?php echo esc_html( Actions::notice_text( $notice ) ); ?></p>
	<?php endif; ?>

	<nav class="anchor-lesson-breadcrumb">
		<a href="<?php echo esc_url( (string) get_permalink( $course_id ) ); ?>"><?php echo esc_html( get_the_title( $course_id ) ); ?></a>
		<span class="anchor-lesson-breadcrumb-sep">/</span>
		<span><?php echo esc_html( get_the_title( $lesson_id ) ); ?></span>
	</nav>

	<?php if ( ! $available ) : ?>
		<p class="anchor-courses-notice"><?php esc_html_e( 'Finish the earlier lessons to unlock this one.', 'anchor-schema' ); ?></p>
	<?php else : ?>
		<div class="anchor-lesson-content"><?php echo wp_kses_post( apply_filters( 'the_content', get_post_field( 'post_content', $lesson_id ) ) ); ?></div>

		<?php if ( ! $complete ) : ?>
			<form class="anchor-lesson-complete" method="post" action="<?php echo esc_url( Actions::complete_url( $course_id, $lesson_id ) ); ?>">
				<?php wp_nonce_field( Actions::NONCE_COMPLETE . '_' . $lesson_id ); ?>
				<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $course_id ); ?>" />
				<input type="hidden" name="lesson_id" value="<?php echo esc_attr( (string) $lesson_id ); ?>" />
				<input type="hidden" name="_redirect" value="<?php echo esc_url( (string) get_permalink( $lesson_id ) ); ?>" />
				<button type="submit" class="anchor-courses-button"><?php esc_html_e( 'Mark complete', 'anchor-schema' ); ?></button>
			</form>
		<?php else : ?>
			<p class="anchor-lesson-done"><?php esc_html_e( 'Completed', 'anchor-schema' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<nav class="anchor-lesson-nav">
		<?php if ( $previous > 0 ) : ?>
			<a class="anchor-lesson-prev" href="<?php echo esc_url( (string) get_permalink( $previous ) ); ?>"><?php esc_html_e( 'Previous', 'anchor-schema' ); ?></a>
		<?php endif; ?>
		<?php if ( $next > 0 ) : ?>
			<a class="anchor-lesson-next" href="<?php echo esc_url( (string) get_permalink( $next ) ); ?>"><?php esc_html_e( 'Next', 'anchor-schema' ); ?></a>
		<?php endif; ?>
	</nav>

	<?php echo do_shortcode( '[anchor_course_progress course_id="' . (int) $course_id . '"]' ); ?>
</div>
```

`anchor-courses/templates/dashboard.php`:

```php
<?php
/**
 * Learner dashboard ([anchor_my_courses]).
 *
 * Variables: $user_id, $enrollments (Enrollment[]), $progress (ProgressService).
 *
 * Theme override: anchor-courses/dashboard.php
 *
 * @package Anchor\Courses
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="anchor-courses-dashboard">
	<?php if ( empty( $enrollments ) ) : ?>
		<p class="anchor-courses-notice"><?php esc_html_e( 'You are not enrolled in any courses yet.', 'anchor-schema' ); ?></p>
	<?php else : ?>
		<ul class="anchor-courses-dashboard-list">
			<?php foreach ( $enrollments as $enrollment ) : ?>
				<?php $course_progress = $progress->get_course_progress( $user_id, $enrollment->course_id ); ?>
				<li class="anchor-courses-dashboard-item">
					<a href="<?php echo esc_url( (string) get_permalink( $enrollment->course_id ) ); ?>">
						<?php echo esc_html( get_the_title( $enrollment->course_id ) ); ?>
					</a>
					<span class="anchor-courses-status"><?php echo esc_html( $enrollment->status ); ?></span>
					<span class="anchor-courses-percent">
						<?php echo esc_html( number_format_i18n( $course_progress->percent, 0 ) . '%' ); ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
```

`anchor-courses/templates/single-course.php` and `single-lesson.php` - thin wrappers so `template_include` has something to return:

```php
<?php
/**
 * Single course template. Theme override: anchor-courses/single-course.php
 *
 * @package Anchor\Courses
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<main class="anchor-courses-single">
	<?php
	while ( have_posts() ) {
		the_post();
		echo do_shortcode( '[anchor_course id="' . (int) get_the_ID() . '"]' );
	}
	?>
</main>
<?php
get_footer();
```

(`single-lesson.php` is the same file with `[anchor_course]` replaced by the lesson render; the lesson body is produced by `Templates::render( 'lesson', ... )` inside `Shortcodes::single_lesson()`, added here:)

```php
	/** Rendered by single-lesson.php; not a public shortcode. */
	public function render_lesson( int $lesson_id ): string {
		$course_id = Curriculum::course_for_item( $lesson_id, 'lesson' );
		$user_id   = \get_current_user_id();

		if ( $course_id <= 0 ) {
			return '';
		}

		$items    = Curriculum::items( $course_id );
		$position = Curriculum::position( $course_id, $lesson_id, 'lesson' );
		$previous = $position > 0 ? (int) $items[ $position - 1 ]['id'] : 0;
		$next     = isset( $items[ $position + 1 ] ) ? (int) $items[ $position + 1 ]['id'] : 0;

		$available = $user_id > 0 && $this->progress->is_item_available( $user_id, $course_id, $lesson_id, 'lesson' );
		if ( $available ) {
			$this->progress->start_lesson( $user_id, $course_id, $lesson_id );
		}

		$course_progress = $user_id > 0 ? $this->progress->get_course_progress( $user_id, $course_id ) : null;

		return Templates::render(
			'lesson',
			[
				'lesson_id' => $lesson_id,
				'course_id' => $course_id,
				'user_id'   => $user_id,
				'available' => $available,
				'complete'  => $course_progress && \in_array( 'lesson:' . $lesson_id, $course_progress->completed_item_keys, true ),
				'previous'  => $previous,
				'next'      => $next,
			]
		);
	}
```

`anchor-courses/templates/single-lesson.php`:

```php
<?php
/**
 * Single lesson template. Theme override: anchor-courses/single-lesson.php
 *
 * @package Anchor\Courses
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<main class="anchor-courses-single">
	<?php
	while ( have_posts() ) {
		the_post();
		$anchor_courses_module = \Anchor\Courses\Module::instance();
		if ( $anchor_courses_module ) {
			echo $anchor_courses_module->shortcodes->render_lesson( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- render_lesson() escapes internally.
		}
	}
	?>
</main>
<?php
get_footer();
```

`anchor-courses/assets/frontend.css`:

```css
/* Anchor Courses - front end. Theme-agnostic: spacing and state, no brand colours. */
.anchor-courses-list { list-style: none; margin: 0 0 1.5rem; padding: 0; }
.anchor-courses-list-item { padding: .5rem 0; border-bottom: 1px solid rgba(0,0,0,.08); }
.anchor-courses-credits { font-size: .85em; opacity: .75; }
.anchor-course-meta { display: flex; flex-wrap: wrap; gap: 1rem; margin: .5rem 0 1rem; font-size: .9em; opacity: .8; }
.anchor-course-curriculum { list-style: none; margin: 0 0 1.5rem; padding: 0; }
.anchor-course-module { margin: 0 0 1.25rem; }
.anchor-course-items { list-style: none; margin: .25rem 0 0; padding: 0; }
.anchor-course-item { padding: .35rem 0; }
.anchor-course-item.is-complete::before { content: "\2713"; margin-right: .5rem; }
.anchor-course-item.is-locked { opacity: .5; }
.anchor-course-optional { margin-left: .5rem; font-size: .8em; opacity: .7; }
.anchor-courses-progress { margin: 1rem 0; }
.anchor-courses-bar { background: rgba(0,0,0,.1); height: 10px; border-radius: 5px; overflow: hidden; }
.anchor-courses-bar span { display: block; height: 100%; background: currentColor; }
.anchor-courses-progress-label { margin: .35rem 0 0; font-size: .85em; }
.anchor-courses-notice { padding: .75rem 1rem; margin: 0 0 1rem; border-left: 3px solid currentColor; background: rgba(0,0,0,.04); }
.anchor-courses-button { display: inline-block; padding: .6rem 1.2rem; cursor: pointer; }
.anchor-course-cta--ask { margin: 1rem 0; opacity: .85; }
.anchor-lesson-breadcrumb { font-size: .85em; margin: 0 0 1rem; }
.anchor-lesson-breadcrumb-sep { margin: 0 .4rem; opacity: .5; }
.anchor-lesson-nav { display: flex; justify-content: space-between; margin: 1.5rem 0; }
.anchor-courses-dashboard-list { list-style: none; margin: 0; padding: 0; }
.anchor-courses-dashboard-item { display: flex; gap: 1rem; align-items: baseline; padding: .5rem 0; border-bottom: 1px solid rgba(0,0,0,.08); }
.anchor-courses-status { font-size: .8em; text-transform: uppercase; opacity: .7; }
.anchor-courses-percent { margin-left: auto; font-variant-numeric: tabular-nums; }
```

`anchor-courses/assets/frontend.js`:

```javascript
/**
 * Anchor Courses - front end.
 *
 * Progressive enhancement only. Every action in this module also works as a
 * plain form post to admin-post.php, so this file must never be the only way
 * to do anything. Task 35 adds the dataLayer pushes here.
 */
(function ($) {
    'use strict';

    $(function () {
        // Prevent a double submit producing two identical POSTs. The server is
        // idempotent regardless (brief section 26); this is a UX nicety.
        $('.anchor-lesson-complete').on('submit', function () {
            $(this).find('button[type="submit"]').prop('disabled', true);
        });
    });
})(jQuery);
```

`anchor-courses/anchor-courses.php` - add the property and construct:

```php
	public Frontend\Shortcodes $shortcodes;
```

```php
		new Frontend\Templates();
		new Frontend\Assets();
		$this->shortcodes = new Frontend\Shortcodes( $this->progress, $this->enrollments );
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Shortcodes
```
Expected: PASS (13 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Frontend anchor-courses/templates anchor-courses/assets/frontend.css \
        anchor-courses/assets/frontend.js anchor-courses/src/Services/EnrollmentService.php \
        anchor-courses/anchor-courses.php tests/test-courses-shortcodes.php
git commit -m "feat(courses): front-end templates, shortcodes and progressive-enhancement assets"
```

---

### Task 19: `Support\Roles` - the two per-course roles

**Files:**
- Create: `anchor-courses/src/Support/Roles.php`
- Modify: `anchor-courses/anchor-courses.php` - hook `save_post_anchor_course`
- Modify: `anchor-courses/src/Admin/CourseEditor.php` - the "Course Role" panel and its two delete handlers
- Modify: `tests/class-anchor-courses-testcase.php` - strip minted roles in `tear_down()`
- Test: `tests/test-courses-roles.php`

**Interfaces:**
- Consumes: `CoursePostType::CPT`, `Capabilities::cap( 'manage' )`, `Support\Log`.
- Produces:
  - `Support\Roles::ACCESS_PREFIX = 'anchor_course_'`, `::COMPLETED_SUFFIX = '_completed'`
  - `::access_slug( int $course_id ): string` -> `anchor_course_{id}`
  - `::completion_slug( int $course_id ): string` -> `anchor_course_{id}_completed`
  - `::access_name( int $course_id ): string` -> `Course: {title}`; `::completion_name( int $course_id ): string` -> `Completed: {title}`
  - `::is_access_slug( string $slug ): ?int` - the course id, or `null`. **Never matches the `_completed` variant.**
  - `::ensure_access_role( int $course_id ): string` - on `save_post_anchor_course`, eagerly, when the course is published
  - `::ensure_completion_role( int $course_id ): string` - lazily, from `grant_completed()`
  - `::grant_completed( int $user_id, int $course_id ): bool`
  - `::exists( string $slug ): bool`, `::holders( string $slug ): int`
  - `::rename_on_title_change( int $post_id, \WP_Post $post ): void` - renames both roles
  - `::delete_role( string $slug ): int` - strips it from every holder; returns the holder count
  - `::user_has( int $user_id, string $role ): bool`, `::missing( int $user_id, array $role_slugs ): array`
  - `Admin\CourseEditor::render_role_panel( \WP_Post $post ): void`, `::handle_delete_role(): void` (`admin_post_anchor_courses_delete_role`)

**Why the access role is minted eagerly and the completion role lazily.** The access role has to exist *before* anybody can be given it: an admin opening the Learners tab on a brand-new course, or a customer buying it thirty seconds after publication, must find a role to be added to. The events module can mint lazily because every grant there goes through one function that can create as it goes; here a plain `add_user_role()` from wp-admin is a supported way in, and that call cannot mint anything. The completion role has no such pressure - nothing can hold it before somebody finishes the course - so it stays lazy and a course nobody completes never creates one.

Neither role carries a capability. They are membership tags, exactly like the events module's `anchor_event_{id}` (events spec 4.1), so adding one can never widen what a user may do. Neither is ever deleted automatically: trashing or deleting a course leaves both standing, because the owner may still want to grant after the fact. The Course Role panel is the only thing that deletes one.

**A note on the redirect notices.** The delete handler redirects with `anchor_courses_admin_notice={code}`. `Admin\EnrollmentManager::render_notice()` (Task 31) owns that whole vocabulary - one renderer, one list, rather than a banner per handler - so until Phase 4 the query argument is simply ignored: the action still works, there is just no confirmation banner yet. Task 31's `NOTICES` constant already carries every code this task and Task 21 emit.

- [ ] **Step 1: Write the failing test**

Create `tests/test-courses-roles.php`:

```php
<?php
/**
 * Anchor Courses - the access and completion roles (design spec 3.1).
 *
 * Roles live in the `wp_user_roles` option AND in the $wp_roles global, and the
 * global survives the per-test transaction rollback - so anything that mints a
 * role removes it again. Anchor_Courses_TestCase::tear_down() does that for
 * every course these helpers create; tests that mint a role by hand clean up
 * after themselves.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Roles extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [], 'Laser Safety' );
	}

	public function test_the_slugs_and_names_follow_the_spec() {
		$this->assertSame( 'anchor_course_' . $this->course, Roles::access_slug( $this->course ) );
		$this->assertSame( 'anchor_course_' . $this->course . '_completed', Roles::completion_slug( $this->course ) );
		$this->assertSame( 'Course: Laser Safety', Roles::access_name( $this->course ) );
		$this->assertSame( 'Completed: Laser Safety', Roles::completion_name( $this->course ) );
	}

	/** The access role exists the moment the course is published - before anyone needs it. */
	public function test_the_access_role_is_minted_eagerly_on_publish() {
		$this->assertTrue( Roles::exists( Roles::access_slug( $this->course ) ) );
		$this->assertSame( [], get_role( Roles::access_slug( $this->course ) )->capabilities, 'A role is a tag, not a permission.' );
		$this->assertSame( 'Course: Laser Safety', wp_roles()->roles[ Roles::access_slug( $this->course ) ]['name'] );
	}

	/** A draft mints nothing; publishing it does. */
	public function test_a_draft_course_mints_no_role_until_it_is_published() {
		$draft = self::factory()->post->create( [ 'post_type' => 'anchor_course', 'post_status' => 'draft', 'post_title' => 'Later' ] );

		$this->assertFalse( Roles::exists( Roles::access_slug( $draft ) ) );

		wp_update_post( [ 'ID' => $draft, 'post_status' => 'publish' ] );

		$this->assertTrue( Roles::exists( Roles::access_slug( $draft ) ) );
		remove_role( Roles::access_slug( $draft ) );
	}

	/** The completion role waits until somebody actually completes something. */
	public function test_the_completion_role_is_minted_lazily() {
		$this->assertFalse( Roles::exists( Roles::completion_slug( $this->course ) ) );

		Roles::grant_completed( $this->user, $this->course );

		$this->assertTrue( Roles::exists( Roles::completion_slug( $this->course ) ) );
		$this->assertTrue( Roles::user_has( $this->user, Roles::completion_slug( $this->course ) ) );
		$this->assertContains( 'subscriber', get_userdata( $this->user )->roles, 'The grant is additive.' );
	}

	public function test_granting_completion_twice_is_harmless() {
		Roles::grant_completed( $this->user, $this->course );
		Roles::grant_completed( $this->user, $this->course );

		$this->assertSame( 1, Roles::holders( Roles::completion_slug( $this->course ) ) );
	}

	/**
	 * is_access_slug() is the listener's whole safety net: it must recognise an
	 * access slug and reject everything else, especially the completion
	 * variant, or completing a course would re-enrol the learner.
	 */
	public function test_is_access_slug_matches_the_access_role_and_nothing_else() {
		$this->assertSame( $this->course, Roles::is_access_slug( 'anchor_course_' . $this->course ) );

		foreach ( [
			'anchor_course_' . $this->course . '_completed',
			'anchor_event_12',
			'anchor_course_',
			'anchor_course_abc',
			'anchor_course_12x',
			'xanchor_course_12',
			'customer',
			'subscriber',
			'',
		] as $slug ) {
			$this->assertNull( Roles::is_access_slug( $slug ), "{$slug} must not read as an access role." );
		}
	}

	/** An id with no course behind it is still a well-formed slug, and still refused. */
	public function test_is_access_slug_refuses_an_id_that_is_not_a_course() {
		$lesson = $this->make_lesson();

		$this->assertNull( Roles::is_access_slug( 'anchor_course_' . $lesson ) );
		$this->assertNull( Roles::is_access_slug( 'anchor_course_999999' ) );
	}

	public function test_renaming_the_course_renames_both_roles() {
		Roles::grant_completed( $this->user, $this->course );

		wp_update_post( [ 'ID' => $this->course, 'post_title' => 'Advanced Laser Safety' ] );

		$this->assertSame( 'Course: Advanced Laser Safety', wp_roles()->roles[ Roles::access_slug( $this->course ) ]['name'] );
		$this->assertSame( 'Completed: Advanced Laser Safety', wp_roles()->roles[ Roles::completion_slug( $this->course ) ]['name'] );
	}

	public function test_trashing_the_course_leaves_both_roles_alone() {
		Roles::grant_completed( $this->user, $this->course );

		wp_trash_post( $this->course );

		$this->assertTrue( Roles::exists( Roles::access_slug( $this->course ) ), 'A role must survive its course (events spec 4.1).' );
		$this->assertTrue( Roles::exists( Roles::completion_slug( $this->course ) ) );
		$this->assertTrue( Roles::user_has( $this->user, Roles::completion_slug( $this->course ) ) );
	}

	public function test_deleting_a_role_strips_it_from_every_holder() {
		$second = $this->make_learner();
		Roles::grant_completed( $this->user, $this->course );
		Roles::grant_completed( $second, $this->course );

		$this->assertSame( 2, Roles::delete_role( Roles::completion_slug( $this->course ) ) );

		$this->assertFalse( Roles::exists( Roles::completion_slug( $this->course ) ) );
		$this->assertFalse( Roles::user_has( $this->user, Roles::completion_slug( $this->course ) ) );
		$this->assertContains( 'subscriber', get_userdata( $this->user )->roles, 'Only the one role goes.' );
	}

	public function test_missing_reports_unheld_roles_by_display_name() {
		add_role( 'anchor_event_42', 'Event: Summit', [] );
		Roles::ensure_completion_role( $this->course );

		$required = [ 'anchor_event_42', Roles::completion_slug( $this->course ) ];

		$this->assertSame( [ 'Event: Summit', 'Completed: Laser Safety' ], Roles::missing( $this->user, $required ) );

		get_user_by( 'id', $this->user )->add_role( 'anchor_event_42' );
		$this->assertSame( [ 'Completed: Laser Safety' ], Roles::missing( $this->user, $required ) );

		remove_role( 'anchor_event_42' );
	}

	/** A completion role is exactly what another course names as a prerequisite. */
	public function test_a_completion_role_works_as_another_courses_prerequisite() {
		$advanced = $this->make_course( [], 'Advanced' );
		update_post_meta( $advanced, '_anchor_course_prerequisites', [ Roles::completion_slug( $this->course ) ] );

		$service = new \Anchor\Courses\Services\EnrollmentService();
		$this->assertSame( 'missing_prerequisite', $service->can_enroll( $this->user, $advanced )->get_error_code() );

		Roles::grant_completed( $this->user, $this->course );

		$this->assertTrue( $service->can_enroll( $this->user, $advanced ) );
	}

	public function test_the_role_panel_shows_both_roles_and_two_delete_actions() {
		Roles::grant_completed( $this->user, $this->course );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		ob_start();
		( new CourseEditor() )->render_role_panel( get_post( $this->course ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( Roles::access_slug( $this->course ), $html );
		$this->assertStringContainsString( Roles::completion_slug( $this->course ), $html );
		$this->assertSame( 2, substr_count( $html, 'anchor_courses_delete_role' ) );
	}

	public function test_the_role_panel_says_when_nobody_has_completed_yet() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		ob_start();
		( new CourseEditor() )->render_role_panel( get_post( $this->course ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Not created yet', $html );
		$this->assertSame( 1, substr_count( $html, 'anchor_courses_delete_role' ), 'Only the access role is deletable so far.' );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Roles
```
Expected: FAIL - `Class "Anchor\Courses\Support\Roles" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Support/Roles.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

use Anchor\Courses\Content\CoursePostType;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The two roles every course owns (design spec 3.1).
 *
 *   anchor_course_{id}            "Course: {title}"     ACCESS - holding it is enrolment.
 *   anchor_course_{id}_completed  "Completed: {title}"  COMPLETION - what others name as a prerequisite.
 *
 * Neither carries a capability. They are membership tags, exactly like the
 * events module's `anchor_event_{id}`, so adding one can never widen what a
 * user may do - and so any plugin, any admin and WP-CLI can all grant access
 * without knowing this module exists.
 *
 * The access role is minted EAGERLY, on publish, because a plain
 * `add_user_role()` from wp-admin is a supported way in and that call cannot
 * mint anything - the role has to be waiting. The completion role is minted
 * LAZILY, on the first completion, because nothing can hold it before then.
 *
 * Neither is ever deleted automatically. A trashed or deleted course keeps both
 * so the owner can go on granting after the fact; the course editor's Course
 * Role panel is the only thing that removes one.
 */
final class Roles {

	public const ACCESS_PREFIX    = 'anchor_course_';
	public const COMPLETED_SUFFIX = '_completed';

	/* ---------------------------------------------------------------------
	 * Slugs and names
	 * ------------------------------------------------------------------- */

	public static function access_slug( int $course_id ): string {
		return self::ACCESS_PREFIX . $course_id;
	}

	public static function completion_slug( int $course_id ): string {
		return self::ACCESS_PREFIX . $course_id . self::COMPLETED_SUFFIX;
	}

	public static function access_name( int $course_id ): string {
		return \sprintf(
			/* translators: %s: course title. */
			\__( 'Course: %s', 'anchor-schema' ),
			(string) \get_the_title( $course_id )
		);
	}

	public static function completion_name( int $course_id ): string {
		return \sprintf(
			/* translators: %s: course title. */
			\__( 'Completed: %s', 'anchor-schema' ),
			(string) \get_the_title( $course_id )
		);
	}

	/**
	 * Is this slug a course's ACCESS role, and if so whose?
	 *
	 * The listener's whole safety net, so it is deliberately strict:
	 *
	 *   - anchored at both ends, so `xanchor_course_12` and `anchor_course_12x`
	 *     are not access slugs;
	 *   - digits only, so the `_completed` variant does not match - which is
	 *     what stops completing a course from re-enrolling the learner;
	 *   - the id must really be a course, so a role somebody hand-made called
	 *     `anchor_course_999999` enrols nobody into nothing.
	 *
	 * @return int|null The course id, or null.
	 */
	public static function is_access_slug( string $slug ): ?int {
		if ( 1 !== \preg_match( '/^' . self::ACCESS_PREFIX . '(\d+)$/', $slug, $m ) ) {
			return null;
		}

		$course_id = (int) $m[1];

		return CoursePostType::CPT === \get_post_type( $course_id ) ? $course_id : null;
	}

	/* ---------------------------------------------------------------------
	 * Minting
	 * ------------------------------------------------------------------- */

	public static function exists( string $slug ): bool {
		return '' !== $slug && null !== \get_role( $slug );
	}

	/**
	 * Mint the access role. Idempotent; returns the slug either way.
	 *
	 * Hooked to save_post_anchor_course. Only a published course gets one: a
	 * draft has nobody to admit yet, and minting on every autosave would litter
	 * the roles option with roles for posts that never ship.
	 */
	public static function ensure_access_role( int $course_id ): string {
		$slug = self::access_slug( $course_id );

		if ( self::exists( $slug ) ) {
			return $slug;
		}
		if ( 'publish' !== \get_post_status( $course_id ) ) {
			return '';
		}

		\add_role( $slug, self::access_name( $course_id ), [] );

		Log::write( 'access_role_minted', [ 'course' => $course_id ] );

		return self::exists( $slug ) ? $slug : '';
	}

	/** Mint the completion role. Idempotent; returns the slug. */
	public static function ensure_completion_role( int $course_id ): string {
		$slug = self::completion_slug( $course_id );

		if ( ! self::exists( $slug ) ) {
			\add_role( $slug, self::completion_name( $course_id ), [] );
		}

		return self::exists( $slug ) ? $slug : '';
	}

	/**
	 * `save_post_anchor_course`: mint the access role, and keep both display
	 * names in step with the title.
	 */
	public static function rename_on_title_change( int $post_id, ?\WP_Post $post = null ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( \wp_is_post_revision( $post_id ) ) {
			return;
		}

		self::ensure_access_role( $post_id );

		self::rename( self::access_slug( $post_id ), self::access_name( $post_id ) );
		self::rename( self::completion_slug( $post_id ), self::completion_name( $post_id ) );
	}

	/** Rename an existing role. Never mints one. */
	private static function rename( string $slug, string $name ): void {
		if ( ! self::exists( $slug ) ) {
			return;
		}

		$roles = \wp_roles();
		if ( ( $roles->roles[ $slug ]['name'] ?? '' ) === $name ) {
			return;
		}

		$roles->roles[ $slug ]['name'] = $name;
		$roles->role_names[ $slug ]    = $name;
		\update_option( $roles->role_key, $roles->roles, false );
	}

	/**
	 * Operator-only: strip a role from every holder and delete it.
	 *
	 * The ONE path that deletes a role. Nothing automatic calls it.
	 *
	 * @return int Holders it was stripped from.
	 */
	public static function delete_role( string $slug ): int {
		if ( ! self::exists( $slug ) ) {
			return 0;
		}

		$holders = \get_users( [ 'role' => $slug, 'fields' => 'ID', 'number' => -1 ] );

		foreach ( $holders as $user_id ) {
			$user = \get_userdata( (int) $user_id );
			if ( $user instanceof \WP_User ) {
				$user->remove_role( $slug );
			}
		}

		\remove_role( $slug );

		Log::write( 'role_deleted', [ 'role' => $slug, 'holders' => \count( $holders ) ] );

		return \count( $holders );
	}

	public static function holders( string $slug ): int {
		if ( ! self::exists( $slug ) ) {
			return 0;
		}
		return \count( \get_users( [ 'role' => $slug, 'fields' => 'ID', 'number' => -1 ] ) );
	}

	/* ---------------------------------------------------------------------
	 * Completion grants
	 * ------------------------------------------------------------------- */

	/**
	 * Give a user the COMPLETION role.
	 *
	 * Additive, idempotent, and invisible to the access listener: the slug ends
	 * in `_completed`, so is_access_slug() returns null for it and no enrolment
	 * row is touched.
	 */
	public static function grant_completed( int $user_id, int $course_id ): bool {
		$user = \get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$slug = self::ensure_completion_role( $course_id );
		if ( '' === $slug ) {
			return false;
		}

		if ( ! self::user_has( $user_id, $slug ) ) {
			$user->add_role( $slug );
			Log::write( 'completion_role_granted', [ 'user' => $user_id, 'course' => $course_id ] );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------- */

	public static function user_has( int $user_id, string $role ): bool {
		$user = \get_userdata( $user_id );
		return $user instanceof \WP_User && \in_array( $role, \array_map( 'strval', (array) $user->roles ), true );
	}

	/**
	 * Which of these role slugs does the user NOT hold?
	 *
	 * @param string[] $role_slugs
	 * @return string[] Display names, in the order given.
	 */
	public static function missing( int $user_id, array $role_slugs ): array {
		$user = \get_userdata( $user_id );
		$held = $user instanceof \WP_User ? \array_map( 'strval', (array) $user->roles ) : [];

		$missing = [];
		foreach ( $role_slugs as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug || \in_array( $slug, $held, true ) ) {
				continue;
			}
			$role      = \wp_roles()->roles[ $slug ] ?? null;
			$missing[] = $role ? (string) $role['name'] : $slug;
		}

		return $missing;
	}
}
```

`anchor-courses/anchor-courses.php` - in the constructor, after the CPTs are registered:

```php
		// Mint the access role on publish and keep both names on the title
		// (design spec 3.1). Priority 20: after CourseEditor::save() has run, so
		// a title set in the same request is the one the role is named for.
		\add_action( 'save_post_' . Content\CoursePostType::CPT, [ Support\Roles::class, 'rename_on_title_change' ], 20, 2 );
```

`anchor-courses/src/Admin/CourseEditor.php` - add `use Anchor\Courses\Support\Roles;` and, in the constructor:

```php
		\add_action( 'admin_post_anchor_courses_delete_role', [ $this, 'handle_delete_role' ] );
```

in `add_metaboxes()`:

```php
		\add_meta_box(
			'anchor_courses_role',
			\__( 'Course Role', 'anchor-schema' ),
			[ $this, 'render_role_panel' ],
			CoursePostType::CPT,
			'side',
			'default'
		);
```

and the two methods:

```php
	/**
	 * The Course Role panel (design spec 3.1).
	 *
	 * Read-only except for one destructive action per role, which is why each
	 * is a POST with its own nonce and a confirm dialog rather than a link.
	 */
	public function render_role_panel( \WP_Post $post ): void {
		$course_id = (int) $post->ID;

		$this->role_row(
			$course_id,
			Roles::access_slug( $course_id ),
			\__( 'Access - holding this role IS enrolment.', 'anchor-schema' ),
			\__( 'Not created yet. It is minted when the course is published.', 'anchor-schema' )
		);

		$this->role_row(
			$course_id,
			Roles::completion_slug( $course_id ),
			\__( 'Completion - what another course or event can require.', 'anchor-schema' ),
			\__( 'Not created yet. It is minted the first time someone completes this course.', 'anchor-schema' )
		);
	}

	private function role_row( int $course_id, string $slug, string $blurb, string $absent ): void {
		\printf( '<p><code>%s</code><br /><span class="description">%s</span></p>', \esc_html( $slug ), \esc_html( $blurb ) );

		if ( ! Roles::exists( $slug ) ) {
			\printf( '<p>%s</p>', \esc_html( $absent ) );
			return;
		}

		$holders = Roles::holders( $slug );

		\printf(
			'<p>%s<br /><strong>%s</strong></p>',
			\esc_html( (string) ( \wp_roles()->roles[ $slug ]['name'] ?? $slug ) ),
			\esc_html(
				\sprintf(
					/* translators: %d: number of users holding the role. */
					\_n( '%d holder', '%d holders', $holders, 'anchor-schema' ),
					$holders
				)
			)
		);

		\printf(
			'<form method="post" action="%s" onsubmit="return confirm(%s);">',
			\esc_url( \admin_url( 'admin-post.php' ) ),
			\esc_attr( (string) \wp_json_encode( \__( 'Delete this role and strip it from every holder? This cannot be undone.', 'anchor-schema' ) ) )
		);
		\wp_nonce_field( 'anchor_courses_delete_role_' . $course_id );
		echo '<input type="hidden" name="action" value="anchor_courses_delete_role" />';
		\printf( '<input type="hidden" name="course_id" value="%d" />', $course_id );
		\printf( '<input type="hidden" name="role" value="%s" />', \esc_attr( $slug ) );
		\printf( '<button type="submit" class="button button-link-delete">%s</button>', \esc_html__( 'Delete role', 'anchor-schema' ) );
		echo '</form>';
	}

	public function handle_delete_role(): void {
		$course_id = \absint( $_POST['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$slug      = \sanitize_key( \wp_unslash( (string) ( $_POST['role'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification

		$nonce = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['_wpnonce'] ?? '' ) ) );
		if ( ! \wp_verify_nonce( $nonce, 'anchor_courses_delete_role_' . $course_id )
			|| ! \current_user_can( Capabilities::cap( 'manage' ) ) ) {
			$this->redirect_to_course( $course_id, 'forbidden' );
		}

		// Only this course's own two roles, so a crafted POST cannot delete
		// `administrator`.
		$allowed = [ Roles::access_slug( $course_id ), Roles::completion_slug( $course_id ) ];
		if ( ! \in_array( $slug, $allowed, true ) ) {
			$this->redirect_to_course( $course_id, 'error' );
		}

		Roles::delete_role( $slug );

		$this->redirect_to_course( $course_id, 'role_deleted' );
	}

	private function redirect_to_course( int $course_id, string $code ): void {
		\wp_safe_redirect( \add_query_arg( 'anchor_courses_admin_notice', $code, (string) \get_edit_post_link( $course_id, 'raw' ) ) );
		exit;
	}
```

`tests/class-anchor-courses-testcase.php` - remember every course the helpers make and strip its roles, so the `$wp_roles` global does not leak between tests:

```php
	/** @var int[] Courses made by make_course(), whose roles must be removed. */
	protected array $minted_courses = [];

	public function tear_down() {
		foreach ( $this->minted_courses as $course_id ) {
			remove_role( \Anchor\Courses\Support\Roles::access_slug( $course_id ) );
			remove_role( \Anchor\Courses\Support\Roles::completion_slug( $course_id ) );
		}
		$this->minted_courses = [];
		parent::tear_down();
	}
```

and in `make_course()`, before returning: `$this->minted_courses[] = $id;`

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Roles
vendor/bin/phpunit --group courses
```
Expected: PASS (13 tests), and the whole `courses` group still green - every course now mints a role on publish, and the test base has to be cleaning them up.

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Support/Roles.php anchor-courses/src/Admin/CourseEditor.php \
        anchor-courses/anchor-courses.php tests/class-anchor-courses-testcase.php \
        tests/test-courses-roles.php
git commit -m "feat(courses): per-course access and completion roles with an operator delete"
```

---

### Task 20: The role listener - holding the access role IS enrolment

**Files:**
- Modify: `anchor-courses/src/Support/Roles.php` - the grant context, `grant_access()` / `revoke_access()`, the three core listeners, the loss policy
- Modify: `anchor-courses/anchor-courses.php` - register the listeners once `$this->enrollments` exists
- Test: `tests/test-courses-role-enrolment.php`

**Interfaces:**
- Consumes: `Services\EnrollmentService::can_enroll()`, `::enroll()`, `::cancel()`, `::expire()`.
- Produces:
  - `Support\Roles::register_listeners( EnrollmentService $enrollments ): void` - hooks core `add_user_role`, `set_user_role`, `remove_user_role`
  - `::grant_access( int $user_id, int $course_id, string $source = 'manual', string $source_id = '' ): true|\WP_Error`
  - `::revoke_access( int $user_id, int $course_id, string $source = 'manual', string $source_id = '' ): bool`
  - `::on_role_added( $user_id, $role ): void`, `::on_set_user_role( $user_id, $role, $old_roles = [] ): void`, `::on_role_removed( $user_id, $role ): void`
  - `::apply_loss_policy( int $user_id, int $course_id, string $role ): void`
  - Actions: `anchor_courses_access_granted( int $user_id, int $course_id, string $source, string $source_id )`, `anchor_courses_access_revoked( int $user_id, int $course_id, string $source )`
  - Filter: `anchor_courses_role_loss_policy( string $policy, int $user_id, int $course_id, string $role )` - `keep|expire|cancel`, default `keep`

**One door.** Everything that enrols anybody - the Learners tab, the WooCommerce adapter, WP-CLI, the wp-admin user screen, another plugin - does the same thing: it adds `anchor_course_{id}` to a user. This listener is what turns that into a row. Nothing else in the module calls `EnrollmentService::enroll()`.

**Where `source` comes from (deviation D15).** `do_action( 'add_user_role', $user_id, $role )` carries two arguments and no reason. `grant_access()` therefore parks `['source' => ..., 'source_id' => ...]` in a private static, calls `add_role()` - the listener runs *inside* that call and reads it - and clears it in a `finally`. A role added by anything else finds the context empty and is recorded as `source = 'role'`. One writer, always cleared, so a stale value can never attach itself to the next grant.

**`grant_access()` is also the prerequisite gate.** It asks `can_enroll()` before touching the role and returns the `WP_Error` unchanged, which is what makes prerequisites bind on the Learners tab and at the checkout alike. The listener, by contrast, enrols with `bypass_checks`: by the time it runs the role is already held, and re-litigating the decision would leave a user holding access with no row to show for it - the worst of both answers.

**Losing a role does not un-enrol by default.** `anchor_courses_role_loss_policy` defaults to `keep`: access outlives the thing that granted it, and re-adding the role resumes the learner where they were (design spec 3.1). A site that wants the row closed filters it to `cancel` or `expire`.

- [ ] **Step 1: Write the failing test**

Create `tests/test-courses-role-enrolment.php`:

```php
<?php
/**
 * Anchor Courses - the access role IS the enrolment (design spec 3.1).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Role_Enrolment extends Anchor_Courses_TestCase {

	private EnrollmentService $enrollments;
	private int $user;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->enrollments = new EnrollmentService();
		$this->user        = $this->make_learner();
		$this->course      = $this->make_course( [], 'Laser Safety' );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_role_loss_policy' );
		remove_all_actions( 'anchor_courses_access_granted' );
		remove_all_actions( 'anchor_courses_access_revoked' );
		parent::tear_down();
	}

	public function test_grant_access_adds_the_role_and_creates_the_row() {
		$this->assertTrue( Roles::grant_access( $this->user, $this->course, 'manual', '7' ) );

		$this->assertTrue( Roles::user_has( $this->user, Roles::access_slug( $this->course ) ) );
		$this->assertContains( 'subscriber', get_userdata( $this->user )->roles, 'The grant is additive.' );

		$enrollment = $this->enrollments->get( $this->user, $this->course );
		$this->assertNotNull( $enrollment );
		$this->assertSame( 'enrolled', $enrollment->status );
		$this->assertSame( 'manual', $enrollment->source );
		$this->assertSame( '7', $enrollment->source_id );
	}

	/**
	 * The point of the whole design: a role added from anywhere at all enrols.
	 * This is the wp-admin user screen, WP-CLI and every other plugin.
	 */
	public function test_a_plain_add_user_role_enrols_with_the_role_source() {
		get_user_by( 'id', $this->user )->add_role( Roles::access_slug( $this->course ) );

		$enrollment = $this->enrollments->get( $this->user, $this->course );
		$this->assertNotNull( $enrollment, 'A bare add_user_role() must enrol.' );
		$this->assertSame( 'role', $enrollment->source, 'Nothing said why, so the source is the role itself.' );
		$this->assertSame( '', $enrollment->source_id );
	}

	/** set_user_role REPLACES the roles, and WordPress fires it for every new account. */
	public function test_set_user_role_also_enrols() {
		wp_update_user( [ 'ID' => $this->user, 'role' => Roles::access_slug( $this->course ) ] );

		$this->assertTrue( $this->enrollments->is_enrolled( $this->user, $this->course ) );
	}

	/** The completion role is not the access role, and must not enrol anybody. */
	public function test_the_completion_role_does_not_enrol() {
		Roles::grant_completed( $this->user, $this->course );

		$this->assertNull(
			$this->enrollments->get( $this->user, $this->course ),
			'anchor_course_{id}_completed must slide past the listener untouched.'
		);
	}

	/** Neither does an event role, or any other role on the site. */
	public function test_unrelated_roles_do_not_enrol() {
		add_role( 'anchor_event_42', 'Event: Summit', [] );

		$user = get_user_by( 'id', $this->user );
		$user->add_role( 'anchor_event_42' );
		$user->add_role( 'editor' );

		$this->assertNull( $this->enrollments->get( $this->user, $this->course ) );

		remove_role( 'anchor_event_42' );
	}

	/** Brief 26: granting twice must not duplicate the row or the action. */
	public function test_granting_twice_is_idempotent() {
		$fired = 0;
		add_action( 'anchor_courses_enrolled', function () use ( &$fired ) { $fired++; }, 10, 3 );

		Roles::grant_access( $this->user, $this->course, 'manual' );
		Roles::grant_access( $this->user, $this->course, 'manual' );
		get_user_by( 'id', $this->user )->add_role( Roles::access_slug( $this->course ) );

		$this->assertSame( 1, $fired );

		remove_all_actions( 'anchor_courses_enrolled' );
	}

	/** The source of the FIRST grant is the one that sticks. */
	public function test_a_later_grant_does_not_rewrite_the_original_source() {
		Roles::grant_access( $this->user, $this->course, 'woocommerce', '4242' );
		Roles::revoke_access( $this->user, $this->course, 'woocommerce', '4242' );
		Roles::grant_access( $this->user, $this->course, 'manual' );

		$this->assertSame( 'woocommerce', $this->enrollments->get( $this->user, $this->course )->source );
		$this->assertSame( '4242', $this->enrollments->get( $this->user, $this->course )->source_id );
	}

	/** An unmet prerequisite refuses the grant - the role is never added. */
	public function test_grant_access_refuses_an_unmet_prerequisite() {
		add_role( 'anchor_course_555_completed', 'Completed: Required First', [] );
		update_post_meta( $this->course, '_anchor_course_prerequisites', [ 'anchor_course_555_completed' ] );

		$result = Roles::grant_access( $this->user, $this->course, 'manual' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_prerequisite', $result->get_error_code() );
		$this->assertFalse( Roles::user_has( $this->user, Roles::access_slug( $this->course ) ) );
		$this->assertNull( $this->enrollments->get( $this->user, $this->course ) );

		get_user_by( 'id', $this->user )->add_role( 'anchor_course_555_completed' );
		$this->assertTrue( Roles::grant_access( $this->user, $this->course, 'manual' ) );

		remove_role( 'anchor_course_555_completed' );
	}

	/** Both actions fire with the documented arguments. */
	public function test_the_access_actions_fire() {
		$seen = [];
		add_action( 'anchor_courses_access_granted', function ( $u, $c, $s, $sid ) use ( &$seen ) { $seen[] = [ 'granted', $u, $c, $s, $sid ]; }, 10, 4 );
		add_action( 'anchor_courses_access_revoked', function ( $u, $c, $s ) use ( &$seen ) { $seen[] = [ 'revoked', $u, $c, $s ]; }, 10, 3 );

		Roles::grant_access( $this->user, $this->course, 'manual', '9' );
		Roles::revoke_access( $this->user, $this->course, 'admin' );

		$this->assertSame(
			[
				[ 'granted', $this->user, $this->course, 'manual', '9' ],
				[ 'revoked', $this->user, $this->course, 'admin' ],
			],
			$seen
		);
	}

	/** Design spec 3.1: access outlives the thing that granted it. */
	public function test_losing_the_role_keeps_the_enrolment_by_default() {
		Roles::grant_access( $this->user, $this->course, 'manual' );

		Roles::revoke_access( $this->user, $this->course, 'admin' );

		$this->assertFalse( Roles::user_has( $this->user, Roles::access_slug( $this->course ) ) );
		$this->assertSame( 'enrolled', $this->enrollments->get( $this->user, $this->course )->status );
	}

	public function test_the_loss_policy_can_cancel_or_expire() {
		Roles::grant_access( $this->user, $this->course, 'manual' );

		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );
		Roles::revoke_access( $this->user, $this->course, 'admin' );
		$this->assertSame( 'cancelled', $this->enrollments->get( $this->user, $this->course )->status );

		remove_all_filters( 'anchor_courses_role_loss_policy' );
		Roles::grant_access( $this->user, $this->course, 'manual' );

		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'expire', 10, 4 );
		get_user_by( 'id', $this->user )->remove_role( Roles::access_slug( $this->course ) );
		$this->assertSame( 'expired', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/** `keep` leaves the progress rows, so re-granting resumes the learner. */
	public function test_re_granting_after_a_loss_resumes_the_same_enrolment() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		$first = $this->enrollments->get( $this->user, $this->course )->id;

		Roles::revoke_access( $this->user, $this->course, 'admin' );
		Roles::grant_access( $this->user, $this->course, 'manual' );

		$this->assertSame( $first, $this->enrollments->get( $this->user, $this->course )->id );
	}

	/** set_user_role drops every other role, so the loss half must see it too. */
	public function test_set_user_role_applies_the_loss_policy_to_what_it_replaced() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		wp_update_user( [ 'ID' => $this->user, 'role' => 'subscriber' ] );

		$this->assertSame( 'cancelled', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/** Deleting the role strips every holder, and each loss runs the policy. */
	public function test_deleting_the_access_role_applies_the_policy_to_every_holder() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		Roles::delete_role( Roles::access_slug( $this->course ) );

		$this->assertSame( 'cancelled', $this->enrollments->get( $this->user, $this->course )->status );
	}

	/** The grant context never survives the call that set it (deviation D15). */
	public function test_the_grant_context_does_not_leak_to_the_next_grant() {
		$second = $this->make_course( [], 'Second' );

		Roles::grant_access( $this->user, $this->course, 'woocommerce', '4242' );
		get_user_by( 'id', $this->user )->add_role( Roles::access_slug( $second ) );

		$this->assertSame( 'role', $this->enrollments->get( $this->user, $second )->source );
		$this->assertSame( '', $this->enrollments->get( $this->user, $second )->source_id );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Role_Enrolment
```
Expected: FAIL - `Call to undefined method ...Roles::grant_access()`.

- [ ] **Step 3: Write minimal implementation**

Add to `anchor-courses/src/Support/Roles.php` (with `use Anchor\Courses\Services\EnrollmentService;` at the top):

```php
	/**
	 * Why the role currently being added was added.
	 *
	 * WordPress's role hooks carry no reason (deviation D15), so grant_access()
	 * parks it here for the length of one add_role() call and the listener -
	 * which runs INSIDE that call - reads it. Always cleared in a finally, so a
	 * value can never attach itself to somebody else's grant.
	 *
	 * @var array{source:string,source_id:string}|null
	 */
	private static ?array $context = null;

	/** @var EnrollmentService|null Set once, by register_listeners(). */
	private static ?EnrollmentService $enrollments = null;

	/**
	 * Start listening to WordPress's role changes.
	 *
	 * These three core actions are the module's ONE enrolment entry point. A
	 * purchase, an admin, WP-CLI and the wp-admin user screen all arrive here.
	 */
	public static function register_listeners( EnrollmentService $enrollments ): void {
		self::$enrollments = $enrollments;

		\add_action( 'add_user_role', [ self::class, 'on_role_added' ], 10, 2 );
		\add_action( 'set_user_role', [ self::class, 'on_set_user_role' ], 10, 3 );
		\add_action( 'remove_user_role', [ self::class, 'on_role_removed' ], 10, 2 );
	}

	private static function enrollments(): EnrollmentService {
		return self::$enrollments ?? ( self::$enrollments = new EnrollmentService() );
	}

	/* ---------------------------------------------------------------------
	 * Access grants
	 * ------------------------------------------------------------------- */

	/**
	 * Give a user access to a course, and say why.
	 *
	 * The supported way to enrol somebody from anywhere. Asks can_enroll()
	 * first, so an unmet prerequisite refuses the grant rather than letting
	 * somebody in and hoping - which is what makes prerequisites bind on the
	 * Learners tab and at the checkout alike.
	 *
	 * @param string $source    manual|woocommerce|role|... - recorded on the row.
	 * @param string $source_id Order id, actor id, whatever identifies it.
	 * @return true|\WP_Error
	 */
	public static function grant_access( int $user_id, int $course_id, string $source = 'manual', string $source_id = '' ) {
		$user = \get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'no_user', \__( 'That user does not exist.', 'anchor-schema' ) );
		}

		$slug = self::ensure_access_role( $course_id );
		if ( '' === $slug ) {
			return new \WP_Error( 'no_course', \__( 'That course has no access role - is it published?', 'anchor-schema' ) );
		}

		if ( self::user_has( $user_id, $slug ) ) {
			return true; // Already in (brief 26).
		}

		$allowed = self::enrollments()->can_enroll( $user_id, $course_id );
		if ( \is_wp_error( $allowed ) ) {
			return $allowed;
		}

		self::$context = [ 'source' => $source, 'source_id' => $source_id ];
		try {
			$user->add_role( $slug ); // Fires add_user_role -> on_role_added().
		} finally {
			self::$context = null;
		}

		Log::write( 'access_granted', [ 'user' => $user_id, 'course' => $course_id, 'source' => $source ] );

		/**
		 * A user just gained access to a course.
		 *
		 * @param int    $user_id
		 * @param int    $course_id
		 * @param string $source
		 * @param string $source_id
		 */
		\do_action( 'anchor_courses_access_granted', $user_id, $course_id, $source, $source_id );

		return true;
	}

	/**
	 * Take access away.
	 *
	 * Removes the role; what that means for the enrolment row is decided in one
	 * place, by the loss policy in on_role_removed(), whoever took it away.
	 */
	public static function revoke_access( int $user_id, int $course_id, string $source = 'manual', string $source_id = '' ): bool {
		$user = \get_userdata( $user_id );
		$slug = self::access_slug( $course_id );

		if ( ! $user instanceof \WP_User || ! self::user_has( $user_id, $slug ) ) {
			return false;
		}

		self::$context = [ 'source' => $source, 'source_id' => $source_id ];
		try {
			$user->remove_role( $slug ); // Fires remove_user_role -> on_role_removed().
		} finally {
			self::$context = null;
		}

		Log::write( 'access_revoked', [ 'user' => $user_id, 'course' => $course_id, 'source' => $source ] );

		/**
		 * A user just lost access to a course.
		 *
		 * @param int    $user_id
		 * @param int    $course_id
		 * @param string $source
		 */
		\do_action( 'anchor_courses_access_revoked', $user_id, $course_id, $source );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * The listeners
	 * ------------------------------------------------------------------- */

	/** Core `add_user_role( $user_id, $role )`. */
	public static function on_role_added( $user_id, $role ): void {
		self::enroll_for_role( (int) $user_id, (string) $role );
	}

	/**
	 * Core `set_user_role( $user_id, $role, $old_roles )` - the user's roles
	 * were REPLACED, so this is a gain and a pile of losses at once.
	 */
	public static function on_set_user_role( $user_id, $role, $old_roles = [] ): void {
		$user_id = (int) $user_id;
		$role    = (string) $role;

		self::enroll_for_role( $user_id, $role );

		foreach ( (array) $old_roles as $lost ) {
			$lost = (string) $lost;
			if ( $lost === $role ) {
				continue;
			}
			$course_id = self::is_access_slug( $lost );
			if ( null !== $course_id ) {
				self::apply_loss_policy( $user_id, $course_id, $lost );
			}
		}
	}

	/** Core `remove_user_role( $user_id, $role )`. */
	public static function on_role_removed( $user_id, $role ): void {
		$course_id = self::is_access_slug( (string) $role );
		if ( null !== $course_id ) {
			self::apply_loss_policy( (int) $user_id, $course_id, (string) $role );
		}
	}

	/**
	 * A user now holds this role. If it is an access role, that IS enrolment.
	 *
	 * bypass_checks is deliberate: by the time this runs the role is held, and
	 * refusing here would leave somebody with access and no row - the worst of
	 * both answers. The gate lives in grant_access(), before the role is added.
	 */
	private static function enroll_for_role( int $user_id, string $role ): void {
		$course_id = self::is_access_slug( $role );
		if ( null === $course_id || $user_id <= 0 ) {
			return;
		}

		$context = self::$context ?? [ 'source' => 'role', 'source_id' => '' ];

		$result = self::enrollments()->enroll(
			$user_id,
			$course_id,
			[
				'bypass_checks' => true,
				'source'        => (string) $context['source'],
				'source_id'     => (string) $context['source_id'],
			]
		);

		if ( \is_wp_error( $result ) ) {
			Log::write( 'role_enroll_failed', [ 'user' => $user_id, 'course' => $course_id, 'code' => $result->get_error_code() ] );
		}
	}

	/**
	 * What losing the access role does to the enrolment.
	 *
	 * Default `keep`: access outlives the thing that granted it, and the
	 * progress rows stay, so re-adding the role resumes the learner exactly
	 * where they stopped (design spec 3.1).
	 */
	public static function apply_loss_policy( int $user_id, int $course_id, string $role ): void {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return;
		}

		/**
		 * What happens to an enrolment when its access role is lost.
		 *
		 * @param string $policy   keep|expire|cancel. Default 'keep'.
		 * @param int    $user_id
		 * @param int    $course_id
		 * @param string $role
		 */
		$policy = (string) \apply_filters( 'anchor_courses_role_loss_policy', 'keep', $user_id, $course_id, $role );

		if ( 'cancel' === $policy ) {
			self::enrollments()->cancel( $user_id, $course_id );
		} elseif ( 'expire' === $policy ) {
			self::enrollments()->expire( $user_id, $course_id );
		}
	}
```

`anchor-courses/anchor-courses.php` - in the constructor, immediately after `$this->enrollments = new Services\EnrollmentService();`:

```php
		// Holding anchor_course_{id} IS enrolment (design spec 3.1). Registered
		// here, once, so the listener and the service share one instance.
		Support\Roles::register_listeners( $this->enrollments );
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Role_Enrolment
vendor/bin/phpunit --group courses
```
Expected: PASS (15 tests), and the whole `courses` group still green.

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Support/Roles.php anchor-courses/anchor-courses.php \
        tests/test-courses-role-enrolment.php
git commit -m "feat(courses): the access role IS the enrolment - one listener, one door"
```

---

### Task 21: `Support\Accounts` and the Learners tab

**Files:**
- Create: `anchor-courses/src/Support/Accounts.php`
- Create: `anchor-courses/src/Admin/LearnerReports.php`
- Modify: `anchor-courses/anchor-courses.php` - construct `Admin\LearnerReports` when `is_admin()`
- Test: `tests/test-courses-learners.php`

**Interfaces:**
- Consumes: `Support\Roles::grant_access()` / `::revoke_access()`, `EnrollmentRepository::for_course()`, `ProgressService::get_course_progress()`, `Capabilities::cap( 'reports' | 'enrollments' )`.
- Produces:
  - `Support\Accounts::ensure_user( string $name, string $email ): int` - find or create, **no new-account email**; `0` on failure or when a site opts out
  - `Support\Accounts::unique_username( string $email ): string`
  - Filter: `anchor_courses_create_account( bool $create, string $email )`
  - `Admin\LearnerReports::add_metabox(): void`, `::render_learners( \WP_Post $post ): void`
  - `::rows( int $course_id, int $limit = 50, int $offset = 0 ): array` - `['user_id','display_name','user_email','enrolled_at','percent','status','completed_at','has_access']`
  - `::render_add_learner_form( int $course_id ): void`, `::revoke_button( int $course_id, int $user_id ): void`
  - `::handle_add_learner(): void` (`admin_post_anchor_courses_add_learner`), `::handle_revoke(): void` (`admin_post_anchor_courses_revoke_access`)
- Capability: both write handlers require `manage_anchor_enrollments` (`Capabilities::cap( 'enrollments' )`); the table requires `view_anchor_course_reports` (`::cap( 'reports' )`), because it prints learner email addresses.

**`ensure_user()` mirrors the events module's, on purpose.** Events' `Entitlements::ensure_user()` resolves a seat to an account and creates one when it has to, suppressing WooCommerce's "New account" email for the length of the create (events plan Task 8). Courses needs the same behaviour from a different starting point - a name and an email typed into a form, with no seat - so the shape is copied rather than the code: `wc_create_new_customer()` when WooCommerce is present so My Account works, `wp_insert_user()` otherwise, `woocommerce_email_enabled_customer_new_account` filtered off inside a `try`/`finally`. The reason is the same in both modules: the person is being *added* by staff, and a "here is your new password" mail they did not ask for, with no context, is noise at best.

`wp_insert_user()` sends nothing of its own, so the plain branch needs no suppression - the filter is there for the WooCommerce branch alone, and removing it in `finally` matters because the request may go on to create other accounts.

**This is the "Learners tab" of design spec 3.1** - a metabox, because the course edit screen has metaboxes and not tabs (deviation D16). Task 31 adds the credits, certificate and best-quiz columns once Phase 4 has them; the add and revoke controls built here survive that.

- [ ] **Step 1: Write the failing test**

Create `tests/test-courses-learners.php`:

```php
<?php
/**
 * Anchor Courses - the Learners tab and account resolution (design spec 3.1).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\LearnerReports;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Accounts;
use Anchor\Courses\Support\Roles;

/** Thrown from wp_redirect so the handler's exit() never runs. */
class Anchor_Courses_Learners_Redirected extends \Exception {}

/** @group courses */
class Test_Courses_Learners extends Anchor_Courses_TestCase {

	private EnrollmentService $enrollments;
	private int $admin;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->enrollments = new EnrollmentService();
		$this->admin       = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->course      = $this->make_course( [], 'Laser Safety' );

		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		remove_all_filters( 'anchor_courses_create_account' );
		remove_all_filters( 'anchor_courses_role_loss_policy' );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Courses_Learners_Redirected( (string) $location );
	}

	/** @return string The redirect URL the handler tried to send. */
	private function post_learner_action( string $action, array $fields ): string {
		$_POST = array_merge(
			$fields,
			[ '_wpnonce' => wp_create_nonce( 'anchor_courses_learners_' . $this->course ), 'course_id' => (string) $this->course ]
		);
		$_REQUEST = $_POST;

		try {
			'add' === $action
				? ( new LearnerReports() )->handle_add_learner()
				: ( new LearnerReports() )->handle_revoke();
		} catch ( Anchor_Courses_Learners_Redirected $e ) {
			return $e->getMessage();
		}

		$this->fail( 'Expected a redirect.' );
	}

	/* ----- Support\Accounts ------------------------------------------- */

	public function test_ensure_user_returns_an_existing_account_by_email() {
		$existing = self::factory()->user->create( [ 'user_email' => 'known@example.test' ] );

		$this->assertSame( $existing, Accounts::ensure_user( 'Someone Else', 'known@example.test' ) );
	}

	public function test_ensure_user_creates_an_account_without_emailing() {
		$mails = [];
		$spy   = function ( $args ) use ( &$mails ) { $mails[] = $args; return $args; };
		add_filter( 'wp_mail', $spy );

		$user_id = Accounts::ensure_user( 'Grace Hopper', 'grace@example.test' );

		remove_filter( 'wp_mail', $spy );

		$this->assertGreaterThan( 0, $user_id );
		$this->assertSame( 'grace@example.test', ( new WP_User( $user_id ) )->user_email );
		$this->assertSame( 'Grace Hopper', ( new WP_User( $user_id ) )->display_name );
		$this->assertSame( [], $mails, 'Adding a learner must not send a new-account email.' );
	}

	public function test_ensure_user_refuses_a_bad_email_and_can_be_opted_out() {
		$this->assertSame( 0, Accounts::ensure_user( 'Nobody', 'not-an-email' ) );

		add_filter( 'anchor_courses_create_account', '__return_false' );
		$this->assertSame( 0, Accounts::ensure_user( 'Nobody', 'nope@example.test' ) );
		$this->assertNull( get_user_by( 'email', 'nope@example.test' ) ?: null );
	}

	/* ----- Adding a learner -------------------------------------------- */

	public function test_add_creates_the_account_grants_the_role_and_records_the_row() {
		wp_set_current_user( $this->admin );

		$url = $this->post_learner_action( 'add', [ 'learner_name' => 'Ada Lovelace', 'learner_email' => 'ada@example.test' ] );
		$this->assertStringContainsString( 'anchor_courses_admin_notice=learner_added', $url );

		$user = get_user_by( 'email', 'ada@example.test' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertTrue( Roles::user_has( (int) $user->ID, Roles::access_slug( $this->course ) ) );

		$enrollment = $this->enrollments->get( (int) $user->ID, $this->course );
		$this->assertNotNull( $enrollment );
		$this->assertSame( 'manual', $enrollment->source );
		$this->assertSame( (string) $this->admin, $enrollment->source_id, 'The actor is recorded, so "who let them in" is answerable.' );
	}

	public function test_adding_the_same_person_twice_changes_nothing() {
		wp_set_current_user( $this->admin );
		$this->post_learner_action( 'add', [ 'learner_name' => 'Ada', 'learner_email' => 'ada@example.test' ] );
		$first = $this->enrollments->get( (int) get_user_by( 'email', 'ada@example.test' )->ID, $this->course )->id;

		$this->post_learner_action( 'add', [ 'learner_name' => 'Ada', 'learner_email' => 'ada@example.test' ] );

		$this->assertCount( 1, LearnerReports::rows( $this->course ) );
		$this->assertSame( $first, $this->enrollments->get( (int) get_user_by( 'email', 'ada@example.test' )->ID, $this->course )->id );
	}

	public function test_add_refuses_an_unmet_prerequisite() {
		add_role( 'anchor_course_555_completed', 'Completed: Required First', [] );
		update_post_meta( $this->course, '_anchor_course_prerequisites', [ 'anchor_course_555_completed' ] );
		wp_set_current_user( $this->admin );

		$url = $this->post_learner_action( 'add', [ 'learner_name' => 'Ada', 'learner_email' => 'ada@example.test' ] );

		$this->assertStringContainsString( 'anchor_courses_admin_notice=missing_prerequisite', $url );

		$user = get_user_by( 'email', 'ada@example.test' );
		$this->assertInstanceOf( WP_User::class, $user, 'The account is still created; only the access is refused.' );
		$this->assertFalse( Roles::user_has( (int) $user->ID, Roles::access_slug( $this->course ) ) );
		$this->assertNull( $this->enrollments->get( (int) $user->ID, $this->course ) );

		remove_role( 'anchor_course_555_completed' );
	}

	public function test_add_refuses_without_the_enrollments_capability() {
		wp_set_current_user( $this->make_learner() );

		$url = $this->post_learner_action( 'add', [ 'learner_name' => 'Ada', 'learner_email' => 'ada@example.test' ] );

		$this->assertStringContainsString( 'anchor_courses_admin_notice=forbidden', $url );
		$this->assertNull( get_user_by( 'email', 'ada@example.test' ) ?: null );
	}

	public function test_add_refuses_a_bad_nonce() {
		wp_set_current_user( $this->admin );
		$_POST    = [ 'course_id' => (string) $this->course, 'learner_name' => 'Ada', 'learner_email' => 'ada@example.test', '_wpnonce' => 'nope' ];
		$_REQUEST = $_POST;

		try {
			( new LearnerReports() )->handle_add_learner();
			$this->fail( 'Expected a redirect.' );
		} catch ( Anchor_Courses_Learners_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_admin_notice=bad_nonce', $e->getMessage() );
		}

		$this->assertNull( get_user_by( 'email', 'ada@example.test' ) ?: null );
	}

	/* ----- Revoking ----------------------------------------------------- */

	public function test_revoke_removes_the_role_and_applies_the_loss_policy() {
		$learner = $this->make_learner();
		Roles::grant_access( $learner, $this->course, 'manual' );
		wp_set_current_user( $this->admin );

		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		$url = $this->post_learner_action( 'revoke', [ 'user_id' => (string) $learner ] );

		$this->assertStringContainsString( 'anchor_courses_admin_notice=access_revoked', $url );
		$this->assertFalse( Roles::user_has( $learner, Roles::access_slug( $this->course ) ) );
		$this->assertSame( 'cancelled', $this->enrollments->get( $learner, $this->course )->status );
	}

	/* ----- The table ----------------------------------------------------- */

	public function test_rows_carry_the_phase_two_columns() {
		$learner = $this->make_learner( [ 'display_name' => 'Ada Lovelace' ] );
		Roles::grant_access( $learner, $this->course, 'manual' );

		$rows = LearnerReports::rows( $this->course );

		$this->assertCount( 1, $rows );
		foreach ( [ 'user_id', 'display_name', 'user_email', 'enrolled_at', 'percent', 'status', 'completed_at', 'has_access' ] as $column ) {
			$this->assertArrayHasKey( $column, $rows[0], "Missing column {$column}" );
		}
		$this->assertSame( 'Ada Lovelace', $rows[0]['display_name'] );
		$this->assertSame( 0.0, $rows[0]['percent'] );
		$this->assertTrue( $rows[0]['has_access'] );
	}

	/** A learner whose role was taken away but whose row was kept reads as "no access". */
	public function test_a_kept_enrolment_without_the_role_reports_no_access() {
		$learner = $this->make_learner();
		Roles::grant_access( $learner, $this->course, 'manual' );
		Roles::revoke_access( $learner, $this->course, 'admin' );

		$rows = LearnerReports::rows( $this->course );

		$this->assertCount( 1, $rows, 'The row is kept by default (anchor_courses_role_loss_policy).' );
		$this->assertFalse( $rows[0]['has_access'] );
	}

	public function test_the_metabox_needs_the_reports_capability_and_escapes_its_output() {
		$nasty = $this->make_learner( [ 'display_name' => '<script>alert(1)</script>' ] );
		Roles::grant_access( $nasty, $this->course, 'manual' );

		wp_set_current_user( $this->make_learner() );
		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$denied = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'alert(1)', $denied );

		wp_set_current_user( $this->admin );
		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$allowed = (string) ob_get_clean();
		$this->assertStringContainsString( 'anchor_courses_add_learner', $allowed );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $allowed );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Learners
```
Expected: FAIL - `Class "Anchor\Courses\Support\Accounts" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Support/Accounts.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Find or create the account a learner will be enrolled under.
 *
 * Progress, credits and certificates all hang off a user id, so somebody being
 * added by name and email has to become a real WordPress user. They are being
 * ADDED by staff, though - they did not sign up - so no "here is your new
 * password" email goes out. The events module makes the same choice for the
 * same reason (events spec 4.3); this is that behaviour, from a form instead of
 * a seat.
 */
final class Accounts {

	/**
	 * The account for this email, creating one if there is none.
	 *
	 * @return int User id, or 0 (bad email, site opted out, or create failed).
	 */
	public static function ensure_user( string $name, string $email ): int {
		$email = \sanitize_email( $email );
		$name  = \sanitize_text_field( $name );

		if ( '' === $email || ! \is_email( $email ) ) {
			return 0;
		}

		$existing = \get_user_by( 'email', $email );
		if ( $existing instanceof \WP_User ) {
			return (int) $existing->ID;
		}

		/**
		 * Whether this site creates accounts for learners who have none.
		 *
		 * Returning false means staff can only add people who already have an
		 * account here.
		 *
		 * @param bool   $create
		 * @param string $email
		 */
		if ( ! \apply_filters( 'anchor_courses_create_account', true, $email ) ) {
			return 0;
		}

		$username = self::unique_username( $email );
		$password = \wp_generate_password( 24, true, true );

		// Suppress WooCommerce's "New account" email for the length of the
		// create: wc_create_new_customer() fires woocommerce_created_customer,
		// which WC_Emails turns into a mail. wp_insert_user() sends nothing of
		// its own, so the plain branch needs no suppression - but the filter is
		// removed in finally regardless, because this request may go on to
		// create other accounts that DO want it.
		\add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
		try {
			if ( \function_exists( 'wc_create_new_customer' ) ) {
				// A WooCommerce customer, so My Account and past orders work.
				$user_id = \wc_create_new_customer( $email, $username, $password, [ 'display_name' => $name ] );
			} else {
				$user_id = \wp_insert_user(
					[
						'user_login'   => $username,
						'user_email'   => $email,
						'user_pass'    => $password,
						'display_name' => '' !== $name ? $name : $username,
						'role'         => (string) \get_option( 'default_role', 'subscriber' ),
					]
				);
			}
		} finally {
			\remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
		}

		if ( \is_wp_error( $user_id ) || ! $user_id ) {
			// Never log the address itself.
			Log::write( 'account_create_failed', [ 'to' => \substr( \md5( $email ), 0, 8 ) ] );
			return 0;
		}

		if ( '' !== $name ) {
			\wp_update_user( [ 'ID' => (int) $user_id, 'display_name' => $name ] );
		}

		return (int) $user_id;
	}

	/** A login derived from the email, with a numeric suffix if it is taken. */
	public static function unique_username( string $email ): string {
		$base = \sanitize_user( (string) \strstr( $email, '@', true ), true );
		if ( '' === $base ) {
			$base = 'learner';
		}

		$candidate = $base;
		$suffix    = 1;
		while ( \username_exists( $candidate ) ) {
			$candidate = $base . ++$suffix;
		}

		return $candidate;
	}
}
```

`anchor-courses/src/Admin/LearnerReports.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Accounts;
use Anchor\Courses\Support\Capabilities;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The Learners tab (design spec 3.1) - a metabox, because the course edit
 * screen has metaboxes and not tabs (deviation D16).
 *
 * Who is in, how far they have got, and the two controls staff need: add
 * somebody by name and email, or take their access away. Both go through
 * Support\Roles, so this screen is a caller of the one enrolment door rather
 * than a second one. Task 31 adds the credits and certificate columns.
 *
 * The table prints learner email addresses, so it is gated on the REPORTS
 * capability, not on edit_posts; the two write handlers need
 * manage_anchor_enrollments.
 */
final class LearnerReports {

	public const NONCE = 'anchor_courses_learners';

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
		\add_action( 'admin_post_anchor_courses_add_learner', [ $this, 'handle_add_learner' ] );
		\add_action( 'admin_post_anchor_courses_revoke_access', [ $this, 'handle_revoke' ] );
	}

	public function add_metabox(): void {
		\add_meta_box(
			'anchor_courses_learners',
			\__( 'Learners', 'anchor-schema' ),
			[ $this, 'render_learners' ],
			CoursePostType::CPT,
			'normal',
			'low'
		);
	}

	/**
	 * One row per enrolled learner.
	 *
	 * `has_access` is read from the role, not the row, and the two can
	 * legitimately disagree: the default loss policy keeps an enrolment after
	 * its role goes, so "enrolled, no access" is a real and visible state.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( int $course_id, int $limit = 50, int $offset = 0 ): array {
		$progress = new ProgressService();
		$slug     = Roles::access_slug( $course_id );
		$rows     = [];

		foreach ( EnrollmentRepository::for_course( $course_id, [], $limit, $offset ) as $enrollment ) {
			$user = \get_userdata( $enrollment->user_id );

			$rows[] = [
				'user_id'      => $enrollment->user_id,
				'display_name' => $user ? (string) $user->display_name : '',
				'user_email'   => $user ? (string) $user->user_email : '',
				'enrolled_at'  => $enrollment->enrolled_at,
				'percent'      => $progress->get_course_progress( $enrollment->user_id, $course_id )->percent,
				'status'       => $enrollment->status,
				'completed_at' => (string) ( $enrollment->completed_at ?? '' ),
				'has_access'   => Roles::user_has( $enrollment->user_id, $slug ),
			];
		}

		return $rows;
	}

	public function render_learners( \WP_Post $post ): void {
		if ( ! Capabilities::current_user_can( 'reports' ) ) {
			echo '<p>' . \esc_html__( 'You do not have permission to view learner reports.', 'anchor-schema' ) . '</p>';
			return;
		}

		$course_id = (int) $post->ID;
		$rows      = self::rows( $course_id );
		$may_write = Capabilities::current_user_can( 'enrollments' );

		if ( [] === $rows ) {
			echo '<p>' . \esc_html__( 'Nobody is enrolled yet.', 'anchor-schema' ) . '</p>';
		} else {
			echo '<table class="widefat striped anchor-courses-report"><thead><tr>';
			foreach ( [
				\__( 'Learner', 'anchor-schema' ),
				\__( 'Email', 'anchor-schema' ),
				\__( 'Enrolled', 'anchor-schema' ),
				\__( 'Progress', 'anchor-schema' ),
				\__( 'Status', 'anchor-schema' ),
				\__( 'Completed', 'anchor-schema' ),
				\__( 'Access', 'anchor-schema' ),
			] as $heading ) {
				echo '<th>' . \esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				echo '<tr>';
				\printf( '<td>%s</td>', \esc_html( (string) $row['display_name'] ) );
				\printf( '<td>%s</td>', \esc_html( (string) $row['user_email'] ) );
				\printf( '<td>%s</td>', \esc_html( \mysql2date( 'Y-m-d', (string) $row['enrolled_at'] ) ) );
				\printf(
					'<td class="progress"><div class="anchor-courses-bar"><span style="width:%1$s%%"></span></div>%1$s%%</td>',
					\esc_html( \number_format_i18n( (float) $row['percent'], 0 ) )
				);
				\printf( '<td>%s</td>', \esc_html( (string) $row['status'] ) );
				\printf( '<td>%s</td>', \esc_html( '' === $row['completed_at'] ? '-' : \mysql2date( 'Y-m-d', (string) $row['completed_at'] ) ) );

				echo '<td>';
				if ( $row['has_access'] && $may_write ) {
					$this->revoke_button( $course_id, (int) $row['user_id'] );
				} else {
					echo \esc_html( $row['has_access'] ? \__( 'Yes', 'anchor-schema' ) : \__( 'No', 'anchor-schema' ) );
				}
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		if ( $may_write ) {
			$this->render_add_learner_form( $course_id );
		}
	}

	public function render_add_learner_form( int $course_id ): void {
		\printf(
			'<h4>%s</h4><form method="post" action="%s" class="anchor-courses-add-learner">',
			\esc_html__( 'Add a learner', 'anchor-schema' ),
			\esc_url( \admin_url( 'admin-post.php' ) )
		);
		\wp_nonce_field( self::NONCE . '_' . $course_id );
		echo '<input type="hidden" name="action" value="anchor_courses_add_learner" />';
		\printf( '<input type="hidden" name="course_id" value="%d" />', $course_id );
		\printf(
			'<p><input type="text" name="learner_name" placeholder="%s" /> '
			. '<input type="email" name="learner_email" placeholder="%s" required /> '
			. '<button type="submit" class="button">%s</button></p>',
			\esc_attr__( 'Name', 'anchor-schema' ),
			\esc_attr__( 'Email', 'anchor-schema' ),
			\esc_html__( 'Add learner', 'anchor-schema' )
		);
		echo '<p class="description">' . \esc_html__( 'Creates the account if there is none. No email is sent.', 'anchor-schema' ) . '</p>';
		echo '</form>';
	}

	public function revoke_button( int $course_id, int $user_id ): void {
		\printf( '<form method="post" action="%s">', \esc_url( \admin_url( 'admin-post.php' ) ) );
		\wp_nonce_field( self::NONCE . '_' . $course_id );
		echo '<input type="hidden" name="action" value="anchor_courses_revoke_access" />';
		\printf( '<input type="hidden" name="course_id" value="%d" />', $course_id );
		\printf( '<input type="hidden" name="user_id" value="%d" />', $user_id );
		\printf( '<button type="submit" class="button-link">%s</button>', \esc_html__( 'Revoke', 'anchor-schema' ) );
		echo '</form>';
	}

	public function handle_add_learner(): void {
		$course_id = $this->authorise();

		$name  = \sanitize_text_field( \wp_unslash( (string) ( $_POST['learner_name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$email = \sanitize_email( \wp_unslash( (string) ( $_POST['learner_email'] ?? '' ) ) );     // phpcs:ignore WordPress.Security.NonceVerification

		$user_id = Accounts::ensure_user( $name, $email );
		if ( $user_id <= 0 ) {
			$this->redirect( $course_id, 'no_user' );
		}

		// The one door. grant_access() checks can_enroll() first, so an unmet
		// prerequisite refuses here exactly as it does at the checkout.
		$result = Roles::grant_access( $user_id, $course_id, 'manual', (string) \get_current_user_id() );

		$this->redirect(
			$course_id,
			\is_wp_error( $result ) ? (string) $result->get_error_code() : 'learner_added'
		);
	}

	public function handle_revoke(): void {
		$course_id = $this->authorise();
		$user_id   = \absint( $_POST['user_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $user_id <= 0 ) {
			$this->redirect( $course_id, 'no_user' );
		}

		Roles::revoke_access( $user_id, $course_id, 'manual', (string) \get_current_user_id() );

		$this->redirect( $course_id, 'access_revoked' );
	}

	/** Nonce + capability, or redirect and stop. @return int The course id. */
	private function authorise(): int {
		$course_id = \absint( $_POST['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		$nonce = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['_wpnonce'] ?? '' ) ) );
		if ( ! \wp_verify_nonce( $nonce, self::NONCE . '_' . $course_id ) ) {
			$this->redirect( $course_id, 'bad_nonce' );
		}
		if ( ! Capabilities::current_user_can( 'enrollments' ) ) {
			$this->redirect( $course_id, 'forbidden' );
		}

		return $course_id;
	}

	private function redirect( int $course_id, string $code ): void {
		$target = $course_id > 0 ? (string) \get_edit_post_link( $course_id, 'raw' ) : \admin_url();

		\wp_safe_redirect( \add_query_arg( 'anchor_courses_admin_notice', \sanitize_key( $code ), $target ) );
		exit;
	}
}
```

`anchor-courses/anchor-courses.php` - inside the `is_admin()` branch:

```php
			new Admin\LearnerReports();
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Learners
```
Expected: PASS (12 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Support/Accounts.php anchor-courses/src/Admin/LearnerReports.php \
        anchor-courses/anchor-courses.php tests/test-courses-learners.php
git commit -m "feat(courses): Learners tab - add by name and email, revoke access, no new-account mail"
```

---

## Phase 3 - Quiz Engine

### Task 22: Quiz attempt repository and value object

**Files:**
- Create: `anchor-courses/src/Domain/QuizAttempt.php`
- Create: `anchor-courses/src/Database/QuizAttemptRepository.php`
- Test: `tests/test-courses-attempt-repo.php`

**Interfaces:**
- Produces:
  - `Domain\QuizAttempt::STATUSES = ['in_progress','submitted','graded','expired','abandoned']`; readonly props `id, user_id, course_id, quiz_id, attempt_number (int), status, score (?float), points_earned (?float), points_possible (?float), passed (bool), started_at, submitted_at (?string), duration_seconds (?int), answers (array), grading_data (array), created_at, updated_at`; `::from_row()`, `->to_array()`, `->is_open(): bool`, `->for_learner( bool $show_correct ): array`
  - `Database\QuizAttemptRepository::find( int $id ): ?QuizAttempt`
  - `::create( array $data ): ?QuizAttempt` - allocates the next `attempt_number` atomically
  - `::update( int $id, array $data ): ?QuizAttempt`
  - `::open_attempt( int $user_id, int $quiz_id ): ?QuizAttempt` - the current `in_progress` row, if any
  - `::count_for_quiz( int $user_id, int $quiz_id ): int` - every non-abandoned attempt
  - `::last_for_quiz( int $user_id, int $quiz_id ): ?QuizAttempt`
  - `::best_for_quiz( int $user_id, int $quiz_id ): ?QuizAttempt` - highest score among graded rows
  - `::for_user_course( int $user_id, int $course_id ): array`

`->for_learner()` is the only shape a learner-facing surface may serialise: it omits `grading_data` unless `$show_correct` is true and never includes stored answer keys.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - quiz attempt table access (brief 7.3, 8.4, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\QuizAttempt;

/** @group courses */
class Test_Courses_Attempt_Repo extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $quiz;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course();
		$this->quiz   = $this->make_quiz();
	}

	private function create( array $over = [] ): ?QuizAttempt {
		return QuizAttemptRepository::create(
			array_merge(
				[ 'user_id' => $this->user, 'course_id' => $this->course, 'quiz_id' => $this->quiz,
				  'points_possible' => 10.0 ],
				$over
			)
		);
	}

	public function test_attempt_numbers_increment_from_one() {
		$first  = $this->create();
		QuizAttemptRepository::update( $first->id, [ 'status' => 'graded' ] );
		$second = $this->create();

		$this->assertSame( 1, $first->attempt_number );
		$this->assertSame( 2, $second->attempt_number );
		$this->assertSame( 'in_progress', $first->status );
	}

	public function test_attempt_numbers_are_per_user_and_per_quiz() {
		$this->create();
		$other_user = QuizAttemptRepository::create(
			[ 'user_id' => $this->make_learner(), 'course_id' => $this->course, 'quiz_id' => $this->quiz ]
		);
		$other_quiz = $this->create( [ 'quiz_id' => $this->make_quiz() ] );

		$this->assertSame( 1, $other_user->attempt_number );
		$this->assertSame( 1, $other_quiz->attempt_number );
	}

	public function test_open_attempt_finds_only_in_progress_rows() {
		$attempt = $this->create();
		$this->assertSame( $attempt->id, QuizAttemptRepository::open_attempt( $this->user, $this->quiz )->id );

		QuizAttemptRepository::update( $attempt->id, [ 'status' => 'graded' ] );
		$this->assertNull( QuizAttemptRepository::open_attempt( $this->user, $this->quiz ) );
	}

	public function test_answers_and_grading_data_round_trip_as_arrays() {
		$attempt = $this->create();

		$updated = QuizAttemptRepository::update(
			$attempt->id,
			[ 'answers' => [ 'q1' => [ 'a2' ] ], 'grading_data' => [ 'q1' => [ 'correct' => true, 'points' => 1.0 ] ] ]
		);

		$this->assertSame( [ 'q1' => [ 'a2' ] ], $updated->answers );
		$this->assertSame( 1.0, $updated->grading_data['q1']['points'] );
	}

	public function test_count_ignores_abandoned_attempts() {
		$a = $this->create();
		QuizAttemptRepository::update( $a->id, [ 'status' => 'abandoned' ] );
		$b = $this->create();
		QuizAttemptRepository::update( $b->id, [ 'status' => 'graded' ] );

		$this->assertSame( 1, QuizAttemptRepository::count_for_quiz( $this->user, $this->quiz ) );
	}

	public function test_best_for_quiz_returns_the_highest_graded_score() {
		$a = $this->create();
		QuizAttemptRepository::update( $a->id, [ 'status' => 'graded', 'score' => 40.0, 'passed' => 0 ] );
		$b = $this->create();
		QuizAttemptRepository::update( $b->id, [ 'status' => 'graded', 'score' => 90.0, 'passed' => 1 ] );

		$best = QuizAttemptRepository::best_for_quiz( $this->user, $this->quiz );
		$this->assertSame( 90.0, $best->score );
		$this->assertTrue( $best->passed );
	}

	public function test_last_for_quiz_returns_the_newest_attempt() {
		$a = $this->create();
		QuizAttemptRepository::update( $a->id, [ 'status' => 'graded' ] );
		$b = $this->create();

		$this->assertSame( $b->id, QuizAttemptRepository::last_for_quiz( $this->user, $this->quiz )->id );
	}

	/** Brief 25: even the DTO must not hand a learner the answer key. */
	public function test_for_learner_hides_grading_data_until_allowed() {
		$attempt = $this->create();
		$graded  = QuizAttemptRepository::update(
			$attempt->id,
			[ 'status' => 'graded', 'score' => 80.0, 'passed' => 1,
			  'grading_data' => [ 'q1' => [ 'correct_ids' => [ 'a2' ] ] ] ]
		);

		$hidden = $graded->for_learner( false );
		$this->assertArrayNotHasKey( 'grading_data', $hidden );
		$this->assertSame( 80.0, $hidden['score'] );

		$shown = $graded->for_learner( true );
		$this->assertArrayHasKey( 'grading_data', $shown );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Attempt_Repo
```
Expected: FAIL - `Class "Anchor\Courses\Domain\QuizAttempt" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Domain/QuizAttempt.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One learner's run at one quiz (brief 7.3, 8.4). Immutable. */
final class QuizAttempt {

	public const STATUSES = [ 'in_progress', 'submitted', 'graded', 'expired', 'abandoned' ];

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly int $quiz_id,
		public readonly int $attempt_number,
		public readonly string $status,
		public readonly ?float $score,
		public readonly ?float $points_earned,
		public readonly ?float $points_possible,
		public readonly bool $passed,
		public readonly string $started_at,
		public readonly ?string $submitted_at,
		public readonly ?int $duration_seconds,
		public readonly array $answers,
		public readonly array $grading_data,
		public readonly string $created_at,
		public readonly string $updated_at
	) {}

	public static function from_row( array $row ): self {
		$answers = \json_decode( (string) ( $row['answers'] ?? '' ), true );
		$grading = \json_decode( (string) ( $row['grading_data'] ?? '' ), true );

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['user_id'] ?? 0 ),
			(int) ( $row['course_id'] ?? 0 ),
			(int) ( $row['quiz_id'] ?? 0 ),
			(int) ( $row['attempt_number'] ?? 0 ),
			(string) ( $row['status'] ?? 'in_progress' ),
			isset( $row['score'] ) && null !== $row['score'] ? (float) $row['score'] : null,
			isset( $row['points_earned'] ) && null !== $row['points_earned'] ? (float) $row['points_earned'] : null,
			isset( $row['points_possible'] ) && null !== $row['points_possible'] ? (float) $row['points_possible'] : null,
			! empty( $row['passed'] ),
			(string) ( $row['started_at'] ?? '' ),
			isset( $row['submitted_at'] ) ? (string) $row['submitted_at'] : null,
			isset( $row['duration_seconds'] ) && null !== $row['duration_seconds'] ? (int) $row['duration_seconds'] : null,
			\is_array( $answers ) ? $answers : [],
			\is_array( $grading ) ? $grading : [],
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	public function is_open(): bool {
		return 'in_progress' === $this->status;
	}

	public function is_graded(): bool {
		return 'graded' === $this->status;
	}

	/**
	 * The ONLY projection a learner-facing surface may serialise.
	 *
	 * `answers` are the learner's own, so they are safe. `grading_data` holds
	 * per-question correctness and is withheld unless the quiz's
	 * show_correct_answers setting says otherwise AND the attempt is graded
	 * (brief 25, rule 8).
	 */
	public function for_learner( bool $show_correct ): array {
		$out = [
			'id'              => $this->id,
			'quiz_id'         => $this->quiz_id,
			'course_id'       => $this->course_id,
			'attempt_number'  => $this->attempt_number,
			'status'          => $this->status,
			'score'           => $this->score,
			'points_earned'   => $this->points_earned,
			'points_possible' => $this->points_possible,
			'passed'          => $this->passed,
			'started_at'      => $this->started_at,
			'submitted_at'    => $this->submitted_at,
			'answers'         => $this->answers,
		];

		if ( $show_correct && $this->is_graded() ) {
			$out['grading_data'] = $this->grading_data;
		}

		return $out;
	}

	public function to_array(): array {
		return [
			'id'               => $this->id,
			'user_id'          => $this->user_id,
			'course_id'        => $this->course_id,
			'quiz_id'          => $this->quiz_id,
			'attempt_number'   => $this->attempt_number,
			'status'           => $this->status,
			'score'            => $this->score,
			'points_earned'    => $this->points_earned,
			'points_possible'  => $this->points_possible,
			'passed'           => $this->passed,
			'started_at'       => $this->started_at,
			'submitted_at'     => $this->submitted_at,
			'duration_seconds' => $this->duration_seconds,
			'answers'          => $this->answers,
			'grading_data'     => $this->grading_data,
			'created_at'       => $this->created_at,
			'updated_at'       => $this->updated_at,
		];
	}
}
```

`anchor-courses/src/Database/QuizAttemptRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * All SQL for wp_anchor_courses_quiz_attempts.
 *
 * create() allocates attempt_number with a single INSERT ... SELECT MAX()+1
 * against UNIQUE (user_id, quiz_id, attempt_number). Two simultaneous starts
 * therefore produce one row and one rejected duplicate rather than two
 * attempts numbered the same (brief 26).
 */
final class QuizAttemptRepository {

	/** Attempts that count toward the max_attempts allowance. */
	private const COUNTED_STATUSES = [ 'in_progress', 'submitted', 'graded', 'expired' ];

	private static function table(): string {
		return Migrations::table( 'quiz_attempts' );
	}

	public static function find( int $id ): ?QuizAttempt {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return \is_array( $row ) ? QuizAttempt::from_row( $row ) : null;
	}

	/** @param array $data user_id, course_id, quiz_id, points_possible. */
	public static function create( array $data ): ?QuizAttempt {
		global $wpdb;

		$now       = Clock::now();
		$user_id   = (int) ( $data['user_id'] ?? 0 );
		$course_id = (int) ( $data['course_id'] ?? 0 );
		$quiz_id   = (int) ( $data['quiz_id'] ?? 0 );
		$table     = self::table();

		// One statement: the next number is computed inside the INSERT, so two
		// concurrent starts cannot both read the same MAX().
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				 (user_id, course_id, quiz_id, attempt_number, status, points_possible, started_at, answers, grading_data, created_at, updated_at)
				 SELECT %d, %d, %d, COALESCE(MAX(a.attempt_number), 0) + 1, 'in_progress', %f, %s, '[]', '[]', %s, %s
				 FROM {$table} a WHERE a.user_id = %d AND a.quiz_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$user_id,
				$course_id,
				$quiz_id,
				(float) ( $data['points_possible'] ?? 0 ),
				$now,
				$now,
				$now,
				$user_id,
				$quiz_id
			)
		);

		$id = (int) $wpdb->insert_id;
		return $id > 0 ? self::find( $id ) : self::open_attempt( $user_id, $quiz_id );
	}

	/** @param array $data Column => value. `answers` / `grading_data` may be arrays. */
	public static function update( int $id, array $data ): ?QuizAttempt {
		global $wpdb;

		foreach ( [ 'answers', 'grading_data' ] as $json_column ) {
			if ( isset( $data[ $json_column ] ) && \is_array( $data[ $json_column ] ) ) {
				$data[ $json_column ] = (string) \wp_json_encode( $data[ $json_column ] );
			}
		}
		$data['updated_at'] = Clock::now();

		$wpdb->update( self::table(), $data, [ 'id' => $id ] );

		return self::find( $id );
	}

	public static function open_attempt( int $user_id, int $quiz_id ): ?QuizAttempt {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE user_id = %d AND quiz_id = %d AND status = 'in_progress'
				 ORDER BY attempt_number DESC LIMIT 1",
				$user_id,
				$quiz_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? QuizAttempt::from_row( $row ) : null;
	}

	/** Attempts that count against max_attempts (abandoned rows do not). */
	public static function count_for_quiz( int $user_id, int $quiz_id ): int {
		global $wpdb;

		$placeholders = \implode( ', ', \array_fill( 0, \count( self::COUNTED_STATUSES ), '%s' ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE user_id = %d AND quiz_id = %d AND status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_merge( [ $user_id, $quiz_id ], self::COUNTED_STATUSES )
			)
		);
	}

	public static function last_for_quiz( int $user_id, int $quiz_id ): ?QuizAttempt {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND quiz_id = %d ORDER BY attempt_number DESC LIMIT 1',
				$user_id,
				$quiz_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? QuizAttempt::from_row( $row ) : null;
	}

	public static function best_for_quiz( int $user_id, int $quiz_id ): ?QuizAttempt {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE user_id = %d AND quiz_id = %d AND status = 'graded'
				 ORDER BY score DESC, attempt_number DESC LIMIT 1",
				$user_id,
				$quiz_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? QuizAttempt::from_row( $row ) : null;
	}

	/** @return QuizAttempt[] */
	public static function for_user_course( int $user_id, int $course_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d ORDER BY quiz_id ASC, attempt_number ASC',
				$user_id,
				$course_id
			),
			ARRAY_A
		);
		return \array_map( [ QuizAttempt::class, 'from_row' ], (array) $rows );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Attempt_Repo
```
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Domain/QuizAttempt.php anchor-courses/src/Database/QuizAttemptRepository.php tests/test-courses-attempt-repo.php
git commit -m "feat(courses): quiz attempt repository with race-free attempt numbering"
```

---

### Task 23: The grading engine (pure)

**Files:**
- Create: `anchor-courses/src/Support/Grading.php`
- Test: `tests/unit/test-grading.php`

**Interfaces:**
- Produces:
  - `Support\Grading::grade( array $questions, array $answers ): array` - **pure, no WordPress**. Returns
    `['points_earned'=>float,'points_possible'=>float,'score'=>float,'per_question'=>array<string,array{correct:bool,points:float,points_possible:float,correct_ids:string[],given_ids:string[]}>]`
  - `Support\Grading::normalize_answer( string $type, $value ): array` - coerce a submitted answer to a sorted list of answer ids
  - `Support\Grading::passed( float $score, int $passing_score ): bool`

Rules: `single_choice`/`true_false` award full points only when the single given id equals the key. `multiple_choice` is **all-or-nothing** (exact set match) - partial credit is not in brief 8.2 and inventing it would change scores silently. Unanswered questions score 0 and are reported `correct => false`. `score` is `points_earned / points_possible * 100`, 2dp, and is `0.0` when `points_possible` is 0.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Pure unit test: server-side grading (brief 8.2, 8.4, rule 4).
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Support\Grading;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Grading extends TestCase {

	private function questions(): array {
		return [
			[
				'id' => 'q1', 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1.0,
				'answers' => [
					[ 'id' => 'a1', 'text' => 'A', 'correct' => false ],
					[ 'id' => 'a2', 'text' => 'B', 'correct' => true ],
				],
			],
			[
				'id' => 'q2', 'type' => 'multiple_choice', 'prompt' => 'Many?', 'points' => 2.0,
				'answers' => [
					[ 'id' => 'b1', 'text' => 'A', 'correct' => true ],
					[ 'id' => 'b2', 'text' => 'B', 'correct' => true ],
					[ 'id' => 'b3', 'text' => 'C', 'correct' => false ],
				],
			],
			[
				'id' => 'q3', 'type' => 'true_false', 'prompt' => 'True?', 'points' => 1.0,
				'answers' => [
					[ 'id' => 'true', 'text' => 'True', 'correct' => true ],
					[ 'id' => 'false', 'text' => 'False', 'correct' => false ],
				],
			],
		];
	}

	public function test_a_perfect_run_scores_one_hundred() {
		$result = Grading::grade(
			$this->questions(),
			[ 'q1' => 'a2', 'q2' => [ 'b1', 'b2' ], 'q3' => 'true' ]
		);

		$this->assertSame( 4.0, $result['points_earned'] );
		$this->assertSame( 4.0, $result['points_possible'] );
		$this->assertSame( 100.0, $result['score'] );
		$this->assertTrue( $result['per_question']['q2']['correct'] );
	}

	public function test_multiple_choice_is_all_or_nothing() {
		$partial = Grading::grade( $this->questions(), [ 'q2' => [ 'b1' ] ] );
		$this->assertSame( 0.0, $partial['per_question']['q2']['points'] );
		$this->assertFalse( $partial['per_question']['q2']['correct'] );

		$extra = Grading::grade( $this->questions(), [ 'q2' => [ 'b1', 'b2', 'b3' ] ] );
		$this->assertSame( 0.0, $extra['per_question']['q2']['points'] );
	}

	public function test_answer_order_does_not_matter() {
		$a = Grading::grade( $this->questions(), [ 'q2' => [ 'b1', 'b2' ] ] );
		$b = Grading::grade( $this->questions(), [ 'q2' => [ 'b2', 'b1' ] ] );
		$this->assertSame( $a['points_earned'], $b['points_earned'] );
	}

	public function test_unanswered_questions_score_zero_and_are_reported() {
		$result = Grading::grade( $this->questions(), [ 'q1' => 'a2' ] );

		$this->assertSame( 1.0, $result['points_earned'] );
		$this->assertSame( 25.0, $result['score'] );
		$this->assertFalse( $result['per_question']['q3']['correct'] );
		$this->assertSame( [], $result['per_question']['q3']['given_ids'] );
	}

	public function test_answers_for_unknown_questions_are_ignored() {
		$result = Grading::grade( $this->questions(), [ 'q1' => 'a2', 'q99' => 'anything' ] );
		$this->assertArrayNotHasKey( 'q99', $result['per_question'] );
		$this->assertSame( 4.0, $result['points_possible'] );
	}

	public function test_a_single_choice_answer_given_as_an_array_takes_the_first_id_only() {
		$result = Grading::grade( $this->questions(), [ 'q1' => [ 'a2', 'a1' ] ] );
		$this->assertFalse(
			$result['per_question']['q1']['correct'],
			'Two ids for a single-choice question is not a correct answer.'
		);
	}

	public function test_zero_point_questions_do_not_break_the_score() {
		$questions = $this->questions();
		$questions[0]['points'] = 0.0;

		$result = Grading::grade( $questions, [ 'q1' => 'a2', 'q2' => [ 'b1', 'b2' ], 'q3' => 'true' ] );

		$this->assertSame( 3.0, $result['points_possible'] );
		$this->assertSame( 100.0, $result['score'] );
	}

	public function test_an_empty_quiz_scores_zero_rather_than_dividing_by_zero() {
		$result = Grading::grade( [], [] );
		$this->assertSame( 0.0, $result['points_possible'] );
		$this->assertSame( 0.0, $result['score'] );
	}

	public function test_score_rounds_to_two_decimals() {
		$questions = [
			[ 'id' => 'q1', 'type' => 'true_false', 'points' => 1.0, 'prompt' => '',
			  'answers' => [ [ 'id' => 'true', 'text' => 'T', 'correct' => true ], [ 'id' => 'false', 'text' => 'F', 'correct' => false ] ] ],
			[ 'id' => 'q2', 'type' => 'true_false', 'points' => 1.0, 'prompt' => '',
			  'answers' => [ [ 'id' => 'true', 'text' => 'T', 'correct' => true ], [ 'id' => 'false', 'text' => 'F', 'correct' => false ] ] ],
			[ 'id' => 'q3', 'type' => 'true_false', 'points' => 1.0, 'prompt' => '',
			  'answers' => [ [ 'id' => 'true', 'text' => 'T', 'correct' => true ], [ 'id' => 'false', 'text' => 'F', 'correct' => false ] ] ],
		];

		$this->assertSame( 33.33, Grading::grade( $questions, [ 'q1' => 'true' ] )['score'] );
	}

	public function test_passed_compares_against_the_threshold_inclusively() {
		$this->assertTrue( Grading::passed( 80.0, 80 ) );
		$this->assertTrue( Grading::passed( 80.01, 80 ) );
		$this->assertFalse( Grading::passed( 79.99, 80 ) );
	}

	public function test_normalize_answer_handles_scalars_arrays_and_junk() {
		$this->assertSame( [ 'a2' ], Grading::normalize_answer( 'single_choice', 'a2' ) );
		$this->assertSame( [ 'b1', 'b2' ], Grading::normalize_answer( 'multiple_choice', [ 'b2', 'b1' ] ) );
		$this->assertSame( [], Grading::normalize_answer( 'single_choice', null ) );
		$this->assertSame( [], Grading::normalize_answer( 'multiple_choice', [ '', null ] ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Grading
```
Expected: FAIL - `Class "Anchor\Courses\Support\Grading" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Support/Grading.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Quiz grading. Pure functions, no WordPress, no database (brief rule 4).
 *
 * The authoritative score is computed here and nowhere else - never in
 * JavaScript, never in a template. QuizService is the only caller.
 */
final class Grading {

	/**
	 * Coerce a submitted answer into a sorted list of answer ids.
	 *
	 * Scalars become a one-element list; arrays are filtered and sorted so that
	 * answer ORDER never affects a multiple-choice comparison.
	 *
	 * @param mixed $value
	 * @return string[]
	 */
	public static function normalize_answer( string $type, $value ): array {
		if ( null === $value ) {
			return [];
		}

		$ids = \is_array( $value ) ? $value : [ $value ];
		$ids = \array_values(
			\array_filter(
				\array_map(
					static fn( $id ): string => \is_scalar( $id ) ? \trim( (string) $id ) : '',
					$ids
				),
				static fn( string $id ): bool => '' !== $id
			)
		);

		$ids = \array_values( \array_unique( $ids ) );
		\sort( $ids, SORT_STRING );

		return $ids;
	}

	/**
	 * Grade a submission.
	 *
	 * @param array $questions Canonical questions from Content\Questions::get().
	 * @param array $answers   question_id => answer id | answer id[].
	 * @return array{points_earned:float,points_possible:float,score:float,per_question:array}
	 */
	public static function grade( array $questions, array $answers ): array {
		$earned       = 0.0;
		$possible     = 0.0;
		$per_question = [];

		foreach ( $questions as $question ) {
			$qid    = (string) ( $question['id'] ?? '' );
			$type   = (string) ( $question['type'] ?? '' );
			$points = (float) ( $question['points'] ?? 0 );

			if ( '' === $qid ) {
				continue;
			}

			$correct_ids = [];
			foreach ( (array) ( $question['answers'] ?? [] ) as $answer ) {
				if ( ! empty( $answer['correct'] ) ) {
					$correct_ids[] = (string) $answer['id'];
				}
			}
			\sort( $correct_ids, SORT_STRING );

			$given = self::normalize_answer( $type, $answers[ $qid ] ?? null );

			// Every question type in Phase 1 is exact-set matching. Multiple
			// choice is all-or-nothing on purpose: brief 8.2 defines no partial
			// credit, and inventing one would silently change published scores.
			$is_correct = [] !== $correct_ids && $given === $correct_ids;

			$possible += $points;
			if ( $is_correct ) {
				$earned += $points;
			}

			$per_question[ $qid ] = [
				'correct'         => $is_correct,
				'points'          => $is_correct ? $points : 0.0,
				'points_possible' => $points,
				'correct_ids'     => $correct_ids,
				'given_ids'       => $given,
			];
		}

		$score = $possible > 0 ? \round( ( $earned / $possible ) * 100, 2 ) : 0.0;

		return [
			'points_earned'   => \round( $earned, 2 ),
			'points_possible' => \round( $possible, 2 ),
			'score'           => $score,
			'per_question'    => $per_question,
		];
	}

	/** Inclusive: scoring exactly the passing score passes. */
	public static function passed( float $score, int $passing_score ): bool {
		return $score >= (float) $passing_score;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Grading
```
Expected: PASS (11 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Support/Grading.php tests/unit/test-grading.php
git commit -m "feat(courses): pure server-side grading engine"
```

---

### Task 24: QuizService - attempt lifecycle, allowance and retry delay

**Files:**
- Create: `anchor-courses/src/Services/QuizService.php`
- Modify: `anchor-courses/anchor-courses.php` - construct `$this->quizzes`
- Test: `tests/test-courses-quiz-lifecycle.php`

**Interfaces:**
- Consumes: `QuizAttemptRepository`, `Content\Questions`, `Admin\QuizEditor::settings()`, `ProgressService::is_item_available()`, `EnrollmentService::is_enrolled()`, `Support\Clock`.
- Produces:
  - `QuizService::settings( int $quiz_id ): array`
  - `::can_start( int $user_id, int $quiz_id, int $course_id ): true|\WP_Error`
  - `::start_attempt( int $user_id, int $quiz_id, int $course_id ): QuizAttempt|\WP_Error`
  - `::attempts_used( int $user_id, int $quiz_id ): int`
  - `::attempts_remaining( int $user_id, int $quiz_id ): int` - `-1` = unlimited
  - `::retry_available_at( int $user_id, int $quiz_id ): int` - unix ts, 0 when now
  - `::get_attempt( int $attempt_id ): ?QuizAttempt`
  - `::owns_attempt( int $user_id, int $attempt_id ): bool`
  - `::questions_for_learner( int $quiz_id, QuizAttempt $attempt ): array`
  - `::deadline( QuizAttempt $attempt ): int` - unix ts, 0 when untimed
  - `::save_answer( int $attempt_id, string $question_id, $value ): bool|\WP_Error`
  - Action: `anchor_courses_quiz_started( QuizAttempt $attempt, int $user_id, int $quiz_id, int $course_id )`
  - Filter: `anchor_courses_can_start_quiz( true|\WP_Error $allowed, int $user_id, int $quiz_id, int $course_id )`

`can_start()` error codes: `not_enrolled`, `not_in_course`, `locked`, `no_attempts_remaining`, `retry_delay`, `no_questions`. `start_attempt()` returns the **existing open attempt** when one is live (brief 26).

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - quiz attempt lifecycle (brief 8.1, 8.4).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\QuizService;

/** @group courses */
class Test_Courses_Quiz_Lifecycle extends Anchor_Courses_TestCase {

	private QuizService $quizzes;
	private int $user;
	private int $course;
	private int $quiz;

	public function set_up() {
		parent::set_up();
		$this->quizzes = new QuizService();

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 2 ] ] );

		Questions::save(
			$this->quiz,
			[
				[ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
				  'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ],
			]
		);

		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		( new EnrollmentService() )->enroll( $this->user, $this->course );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_can_start_quiz' );
		remove_all_filters( 'anchor_courses_now' );
		remove_all_actions( 'anchor_courses_quiz_started' );
		parent::tear_down();
	}

	public function test_start_creates_attempt_one_and_fires_the_action() {
		$fired = 0;
		add_action( 'anchor_courses_quiz_started', function () use ( &$fired ) { $fired++; }, 10, 4 );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$this->assertInstanceOf( QuizAttempt::class, $attempt );
		$this->assertSame( 1, $attempt->attempt_number );
		$this->assertSame( 'in_progress', $attempt->status );
		$this->assertSame( 1.0, $attempt->points_possible );
		$this->assertSame( 1, $fired );
	}

	/** Brief 26: a reload must resume, not consume another attempt. */
	public function test_starting_again_while_open_returns_the_same_attempt() {
		$first  = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$second = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( 1, $this->quizzes->attempts_used( $this->user, $this->quiz ) );
	}

	public function test_max_attempts_is_enforced() {
		$a = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		QuizAttemptRepository::update( $a->id, [ 'status' => 'graded', 'score' => 0.0 ] );
		$b = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		QuizAttemptRepository::update( $b->id, [ 'status' => 'graded', 'score' => 0.0 ] );

		$refused = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$this->assertWPError( $refused );
		$this->assertSame( 'no_attempts_remaining', $refused->get_error_code() );
		$this->assertSame( 0, $this->quizzes->attempts_remaining( $this->user, $this->quiz ) );
	}

	public function test_zero_max_attempts_means_unlimited() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'max_attempts' => 0 ] );

		for ( $i = 0; $i < 3; $i++ ) {
			$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
			$this->assertInstanceOf( QuizAttempt::class, $attempt );
			QuizAttemptRepository::update( $attempt->id, [ 'status' => 'graded', 'score' => 0.0 ] );
		}

		$this->assertSame( -1, $this->quizzes->attempts_remaining( $this->user, $this->quiz ) );
	}

	public function test_retry_delay_blocks_an_immediate_second_attempt() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'max_attempts' => 0, 'retry_delay_seconds' => 3600 ] );

		$first = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		QuizAttemptRepository::update( $first->id, [ 'status' => 'graded', 'submitted_at' => '2026-05-01 10:05:00', 'score' => 0.0 ] );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		$refused = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->assertSame( 'retry_delay', $refused->get_error_code() );
		$this->assertSame( strtotime( '2026-05-01 11:05:00 UTC' ), $this->quizzes->retry_available_at( $this->user, $this->quiz ) );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 11:06:00 UTC' ) );

		$this->assertInstanceOf( QuizAttempt::class, $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course ) );
	}

	public function test_an_unenrolled_user_cannot_start() {
		$stranger = $this->make_learner();
		$this->assertSame(
			'not_enrolled',
			$this->quizzes->start_attempt( $stranger, $this->quiz, $this->course )->get_error_code()
		);
	}

	public function test_a_quiz_outside_the_course_cannot_be_started() {
		$orphan = $this->make_quiz();
		$this->assertSame(
			'not_in_course',
			$this->quizzes->start_attempt( $this->user, $orphan, $this->course )->get_error_code()
		);
	}

	public function test_a_quiz_with_no_questions_cannot_be_started() {
		$empty = $this->make_quiz();
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $empty ] ] ] ]
		);
		$this->assertSame(
			'no_questions',
			$this->quizzes->start_attempt( $this->user, $empty, $this->course )->get_error_code()
		);
	}

	public function test_the_can_start_filter_can_veto() {
		add_filter(
			'anchor_courses_can_start_quiz',
			static fn( $allowed ) => new WP_Error( 'prework', 'Finish the pre-work.' )
		);
		$this->assertSame( 'prework', $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course )->get_error_code() );
	}

	/** Brief 8.3 / rule 8: never, under any circumstances. */
	public function test_questions_for_learner_never_expose_correct() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		$payload = $this->quizzes->questions_for_learner( $this->quiz, $attempt );

		$this->assertStringNotContainsString( 'correct', (string) wp_json_encode( $payload ) );
		$this->assertSame( 'One?', $payload[0]['prompt'] );
	}

	public function test_save_answer_stores_only_known_question_ids() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$qid     = Questions::get( $this->quiz )[0]['id'];

		$this->assertTrue( $this->quizzes->save_answer( $attempt->id, $qid, 'a2' ) );
		$this->assertWPError( $this->quizzes->save_answer( $attempt->id, 'not-a-question', 'a2' ) );

		$stored = QuizAttemptRepository::find( $attempt->id );
		$this->assertSame( [ $qid => [ 'a2' ] ], $stored->answers );
	}

	public function test_save_answer_refuses_a_closed_attempt() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$qid     = Questions::get( $this->quiz )[0]['id'];
		QuizAttemptRepository::update( $attempt->id, [ 'status' => 'graded' ] );

		$this->assertSame( 'attempt_closed', $this->quizzes->save_answer( $attempt->id, $qid, 'a2' )->get_error_code() );
	}

	public function test_owns_attempt_is_false_for_another_learner() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->assertTrue( $this->quizzes->owns_attempt( $this->user, $attempt->id ) );
		$this->assertFalse( $this->quizzes->owns_attempt( $this->make_learner(), $attempt->id ) );
	}

	public function test_deadline_is_zero_when_untimed_and_computed_when_timed() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->assertSame( 0, $this->quizzes->deadline( $attempt ) );

		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600 ] );
		$this->assertSame( strtotime( '2026-05-01 10:10:00 UTC' ), $this->quizzes->deadline( $attempt ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Quiz_Lifecycle
```
Expected: FAIL - `Class "Anchor\Courses\Services\QuizService" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Services/QuizService.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\QuizEditor;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Log;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The quiz engine (brief section 8).
 *
 * Everything authoritative about a quiz lives here: who may start, how many
 * attempts remain, when a retry unlocks, what the learner is allowed to see,
 * and (Task 25) the grade. Nothing in JavaScript is trusted (rule 4), and no
 * surface may read Questions::get() directly for a learner (rule 8).
 */
final class QuizService {

	public function __construct(
		private ?ProgressService $progress = null,
		private ?EnrollmentService $enrollments = null
	) {
		$this->progress    = $progress ?? new ProgressService();
		$this->enrollments = $enrollments ?? new EnrollmentService();
	}

	public function settings( int $quiz_id ): array {
		return QuizEditor::settings( $quiz_id );
	}

	/** @return true|\WP_Error */
	public function can_start( int $user_id, int $quiz_id, int $course_id ) {
		$result = $this->check_start( $user_id, $quiz_id, $course_id );

		/**
		 * Filter whether this learner may begin an attempt.
		 *
		 * @param true|\WP_Error $result
		 * @param int            $user_id
		 * @param int            $quiz_id
		 * @param int            $course_id
		 */
		return \apply_filters( 'anchor_courses_can_start_quiz', $result, $user_id, $quiz_id, $course_id );
	}

	/** @return true|\WP_Error */
	private function check_start( int $user_id, int $quiz_id, int $course_id ) {
		if ( ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			return new \WP_Error( 'not_enrolled', \__( 'You are not enrolled in this course.', 'anchor-schema' ) );
		}
		if ( ! Curriculum::contains( $course_id, $quiz_id, 'quiz' ) ) {
			return new \WP_Error( 'not_in_course', \__( 'That quiz is not part of this course.', 'anchor-schema' ) );
		}
		if ( [] === Questions::get( $quiz_id ) ) {
			return new \WP_Error( 'no_questions', \__( 'This quiz has no questions yet.', 'anchor-schema' ) );
		}
		if ( ! $this->progress->is_item_available( $user_id, $course_id, $quiz_id, 'quiz' ) ) {
			return new \WP_Error( 'locked', \__( 'Finish the earlier items first.', 'anchor-schema' ) );
		}

		// An attempt already open always resumes, whatever the allowance says.
		if ( QuizAttemptRepository::open_attempt( $user_id, $quiz_id ) instanceof QuizAttempt ) {
			return true;
		}

		$remaining = $this->attempts_remaining( $user_id, $quiz_id );
		if ( 0 === $remaining ) {
			return new \WP_Error( 'no_attempts_remaining', \__( 'You have used all your attempts.', 'anchor-schema' ) );
		}

		$retry_at = $this->retry_available_at( $user_id, $quiz_id );
		if ( $retry_at > Clock::timestamp() ) {
			return new \WP_Error(
				'retry_delay',
				\sprintf(
					/* translators: %s: human-readable time difference, e.g. "35 mins". */
					\__( 'You can retry in %s.', 'anchor-schema' ),
					\human_time_diff( Clock::timestamp(), $retry_at )
				)
			);
		}

		return true;
	}

	/**
	 * Begin (or resume) an attempt.
	 *
	 * @return QuizAttempt|\WP_Error
	 */
	public function start_attempt( int $user_id, int $quiz_id, int $course_id ) {
		$allowed = $this->can_start( $user_id, $quiz_id, $course_id );
		if ( \is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$open = QuizAttemptRepository::open_attempt( $user_id, $quiz_id );
		if ( $open instanceof QuizAttempt ) {
			return $open;
		}

		$attempt = QuizAttemptRepository::create(
			[
				'user_id'         => $user_id,
				'course_id'       => $course_id,
				'quiz_id'         => $quiz_id,
				'points_possible' => Questions::points_possible( $quiz_id ),
			]
		);

		if ( ! $attempt instanceof QuizAttempt ) {
			return new \WP_Error( 'attempt_failed', \__( 'The attempt could not be started.', 'anchor-schema' ) );
		}

		$this->enrollments->start( $user_id, $course_id );
		$this->progress->record_item( $user_id, $course_id, $quiz_id, 'quiz', 'in_progress' );

		Log::write( 'quiz_started', [ 'user' => $user_id, 'quiz' => $quiz_id, 'attempt' => $attempt->id ] );

		/**
		 * Fires when a new quiz attempt begins.
		 *
		 * @param QuizAttempt $attempt
		 * @param int         $user_id
		 * @param int         $quiz_id
		 * @param int         $course_id
		 */
		\do_action( 'anchor_courses_quiz_started', $attempt, $user_id, $quiz_id, $course_id );

		return $attempt;
	}

	public function attempts_used( int $user_id, int $quiz_id ): int {
		return QuizAttemptRepository::count_for_quiz( $user_id, $quiz_id );
	}

	/** @return int Remaining attempts, or -1 for unlimited. */
	public function attempts_remaining( int $user_id, int $quiz_id ): int {
		$max = (int) $this->settings( $quiz_id )['max_attempts'];
		if ( $max <= 0 ) {
			return -1; // 0 = unlimited (brief 8.1).
		}
		return \max( 0, $max - $this->attempts_used( $user_id, $quiz_id ) );
	}

	/** Unix timestamp when the next attempt unlocks; 0 when it already has. */
	public function retry_available_at( int $user_id, int $quiz_id ): int {
		$delay = (int) $this->settings( $quiz_id )['retry_delay_seconds'];
		if ( $delay <= 0 ) {
			return 0;
		}

		$last = QuizAttemptRepository::last_for_quiz( $user_id, $quiz_id );
		if ( ! $last instanceof QuizAttempt || null === $last->submitted_at || '' === $last->submitted_at ) {
			return 0;
		}

		return Clock::to_timestamp( $last->submitted_at ) + $delay;
	}

	public function get_attempt( int $attempt_id ): ?QuizAttempt {
		return QuizAttemptRepository::find( $attempt_id );
	}

	public function owns_attempt( int $user_id, int $attempt_id ): bool {
		$attempt = QuizAttemptRepository::find( $attempt_id );
		return $attempt instanceof QuizAttempt && $attempt->user_id === $user_id;
	}

	/**
	 * The question payload a learner may see (brief 8.3, rule 8).
	 *
	 * The attempt id seeds the shuffle, so reloading mid-attempt keeps the same
	 * order without storing one.
	 */
	public function questions_for_learner( int $quiz_id, QuizAttempt $attempt ): array {
		$settings = $this->settings( $quiz_id );

		return Questions::for_learner(
			Questions::get( $quiz_id ),
			1 === (int) $settings['shuffle_questions'],
			1 === (int) $settings['shuffle_answers'],
			'attempt-' . $attempt->id
		);
	}

	/** Unix timestamp the attempt must be submitted by; 0 when untimed. */
	public function deadline( QuizAttempt $attempt ): int {
		$limit = (int) $this->settings( $attempt->quiz_id )['time_limit_seconds'];
		if ( $limit <= 0 ) {
			return 0;
		}
		return Clock::to_timestamp( $attempt->started_at ) + $limit;
	}

	/**
	 * Store one answer on an open attempt.
	 *
	 * @param mixed $value Answer id, or an array of ids.
	 * @return true|\WP_Error
	 */
	public function save_answer( int $attempt_id, string $question_id, $value ) {
		$attempt = QuizAttemptRepository::find( $attempt_id );
		if ( ! $attempt instanceof QuizAttempt ) {
			return new \WP_Error( 'no_attempt', \__( 'That attempt does not exist.', 'anchor-schema' ) );
		}
		if ( ! $attempt->is_open() ) {
			return new \WP_Error( 'attempt_closed', \__( 'This attempt is already finished.', 'anchor-schema' ) );
		}

		$questions = Questions::get( $attempt->quiz_id );
		$type      = '';
		foreach ( $questions as $question ) {
			if ( (string) $question['id'] === $question_id ) {
				$type = (string) $question['type'];
				break;
			}
		}
		if ( '' === $type ) {
			return new \WP_Error( 'unknown_question', \__( 'That question is not part of this quiz.', 'anchor-schema' ) );
		}

		$answers                 = $attempt->answers;
		$answers[ $question_id ] = \Anchor\Courses\Support\Grading::normalize_answer( $type, $value );

		QuizAttemptRepository::update( $attempt_id, [ 'answers' => $answers ] );

		return true;
	}
}
```

`anchor-courses/anchor-courses.php` - add the property and construct after `$this->progress`:

```php
	public Services\QuizService $quizzes;
```

```php
		$this->quizzes = new Services\QuizService( $this->progress, $this->enrollments );
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Quiz_Lifecycle
```
Expected: PASS (13 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Services/QuizService.php anchor-courses/anchor-courses.php tests/test-courses-quiz-lifecycle.php
git commit -m "feat(courses): quiz attempt lifecycle with attempt limits and retry delay"
```

---

### Task 25: Quiz submission, server-side timer and progress hand-off

**Files:**
- Modify: `anchor-courses/src/Services/QuizService.php` - add `submit()`, `enforce_timer()`, `sweep_expired_attempts()`
- Modify: `anchor-courses/anchor-courses.php` - register the timer sweep on the daily cron
- Test: `tests/test-courses-quiz-submit.php`

**Interfaces:**
- Produces:
  - `QuizService::submit( int $attempt_id, array $answers = [] ): QuizAttempt|\WP_Error`
  - `QuizService::enforce_timer( QuizAttempt $attempt ): QuizAttempt` - applies the quiz's `on_timer_expiry` policy; returns the attempt unchanged when untimed or still inside the window
  - `QuizService::sweep_expired_attempts(): int` - hooked to `anchor_courses_expire_sweep`
  - Actions: `anchor_courses_quiz_submitted( QuizAttempt $attempt, int $user_id, int $quiz_id, int $course_id )`, `anchor_courses_quiz_passed(...)`, `anchor_courses_quiz_failed(...)`
  - Filter: `anchor_courses_quiz_result( array $result, QuizAttempt $attempt )` - `$result` is Grading's array plus `passed` (bool) and `passing_score` (int)

Submission rules: a passing attempt marks the quiz item `completed` in progress and, when a lesson names this quiz with `completion_mode = quiz_pass`, completes that lesson too; a failing attempt marks the quiz item `failed`. A second `submit()` on a graded attempt returns it untouched and re-fires nothing.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - grading, timers and the quiz -> progress hand-off
 * (brief 8.4, 8.5, 9.2).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Services\QuizService;

/** @group courses */
class Test_Courses_Quiz_Submit extends Anchor_Courses_TestCase {

	private QuizService $quizzes;
	private ProgressService $progress;
	private int $user;
	private int $course;
	private int $quiz;
	private string $q1;
	private string $q2;

	public function set_up() {
		parent::set_up();
		$this->progress = new ProgressService();
		$this->quizzes  = new QuizService( $this->progress );

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 2, 'show_correct_answers' => 1 ] ] );

		$saved = Questions::save(
			$this->quiz,
			[
				[ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
				  'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ],
				[ 'type' => 'single_choice', 'prompt' => 'Two?', 'points' => 1,
				  'answers' => [ [ 'id' => 'b1', 'text' => 'A', 'correct' => true ], [ 'id' => 'b2', 'text' => 'B', 'correct' => false ] ] ],
			]
		);
		$this->q1 = $saved[0]['id'];
		$this->q2 = $saved[1]['id'];

		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		( new EnrollmentService() )->enroll( $this->user, $this->course );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_now' );
		remove_all_filters( 'anchor_courses_quiz_result' );
		foreach ( [ 'submitted', 'passed', 'failed' ] as $event ) {
			remove_all_actions( 'anchor_courses_quiz_' . $event );
		}
		parent::tear_down();
	}

	public function test_a_perfect_submission_passes_and_fires_passed() {
		$events  = [];
		add_action( 'anchor_courses_quiz_submitted', function () use ( &$events ) { $events[] = 'submitted'; }, 10, 4 );
		add_action( 'anchor_courses_quiz_passed', function () use ( &$events ) { $events[] = 'passed'; }, 10, 4 );
		add_action( 'anchor_courses_quiz_failed', function () use ( &$events ) { $events[] = 'failed'; }, 10, 4 );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$graded  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );

		$this->assertSame( 'graded', $graded->status );
		$this->assertSame( 100.0, $graded->score );
		$this->assertTrue( $graded->passed );
		$this->assertSame( [ 'submitted', 'passed' ], $events );
	}

	public function test_a_failing_submission_fires_failed_and_marks_progress_failed() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$graded  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a1', $this->q2 => 'b2' ] );

		$this->assertFalse( $graded->passed );
		$this->assertSame( 0.0, $graded->score );
		$this->assertSame( 0.0, $this->progress->get_course_progress( $this->user, $this->course )->percent );
	}

	/** 50% against a passing score of 80 is a fail. */
	public function test_partial_answers_are_graded_against_the_passing_score() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$graded  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2' ] );

		$this->assertSame( 50.0, $graded->score );
		$this->assertFalse( $graded->passed );
	}

	public function test_passing_completes_the_quiz_item_and_the_course() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );

		$progress = $this->progress->get_course_progress( $this->user, $this->course );
		$this->assertSame( 100.0, $progress->percent );
		$this->assertContains( 'quiz:' . $this->quiz, $progress->completed_item_keys );
	}

	public function test_passing_completes_a_lesson_that_requires_this_quiz() {
		$lesson = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $this->quiz ], 'Gated' );
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => $lesson ],
				[ 'type' => 'quiz', 'id' => $this->quiz ],
			] ] ]
		);

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );

		$this->assertContains(
			'lesson:' . $lesson,
			$this->progress->get_course_progress( $this->user, $this->course )->completed_item_keys
		);
	}

	/** Brief 26: submitting twice must not re-grade or re-fire. */
	public function test_submitting_a_graded_attempt_is_a_no_op() {
		$fired = 0;
		add_action( 'anchor_courses_quiz_submitted', function () use ( &$fired ) { $fired++; }, 10, 4 );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$first   = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );
		$second  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a1', $this->q2 => 'b2' ] );

		$this->assertSame( 100.0, $second->score, 'A resubmission must not overwrite a graded score.' );
		$this->assertSame( $first->submitted_at, $second->submitted_at );
		$this->assertSame( 1, $fired );
	}

	/** Brief 8.5: default policy is auto-submit whatever was saved. */
	public function test_an_expired_timer_auto_submits_saved_answers() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'auto_submit' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );
		$this->quizzes->save_answer( $attempt->id, $this->q2, 'b1' );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		$graded = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a1' ] );

		$this->assertSame( 'graded', $graded->status );
		$this->assertSame( 100.0, $graded->score, 'The saved answers are graded, not the late submission.' );
		$this->assertTrue( $graded->passed );
	}

	public function test_the_expire_policy_marks_the_attempt_expired_and_grades_nothing() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'expire' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$this->quizzes->save_answer( $attempt->id, $this->q1, 'a2' );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:30:00 UTC' ) );

		$result = $this->quizzes->submit( $attempt->id, [] );

		$this->assertSame( 'expired', $result->status );
		$this->assertFalse( $result->passed );
		$this->assertNull( $result->score );
	}

	public function test_an_in_window_submission_records_its_duration() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600 ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:04:00 UTC' ) );

		$graded = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b1' ] );

		$this->assertSame( 240, $graded->duration_seconds );
	}

	public function test_grading_data_records_per_question_correctness() {
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$graded  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a2', $this->q2 => 'b2' ] );

		$this->assertTrue( $graded->grading_data[ $this->q1 ]['correct'] );
		$this->assertFalse( $graded->grading_data[ $this->q2 ]['correct'] );
		$this->assertSame( [ 'b1' ], $graded->grading_data[ $this->q2 ]['correct_ids'] );
	}

	public function test_the_quiz_result_filter_can_override_the_outcome() {
		add_filter(
			'anchor_courses_quiz_result',
			static function ( array $result ) {
				$result['passed'] = true;
				return $result;
			}
		);

		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );
		$graded  = $this->quizzes->submit( $attempt->id, [ $this->q1 => 'a1', $this->q2 => 'b2' ] );

		$this->assertTrue( $graded->passed );
	}

	public function test_submit_refuses_an_unknown_attempt() {
		$this->assertSame( 'no_attempt', $this->quizzes->submit( 999999 )->get_error_code() );
	}

	public function test_sweep_expires_abandoned_timed_attempts() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'time_limit_seconds' => 600, 'on_timer_expiry' => 'auto_submit' ] );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-01 10:00:00 UTC' ) );
		$attempt = $this->quizzes->start_attempt( $this->user, $this->quiz, $this->course );

		remove_all_filters( 'anchor_courses_now' );
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-05-02 10:00:00 UTC' ) );

		$this->assertSame( 1, $this->quizzes->sweep_expired_attempts() );
		$this->assertNotSame( 'in_progress', QuizAttemptRepository::find( $attempt->id )->status );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Quiz_Submit
```
Expected: FAIL - `Call to undefined method ...QuizService::submit()`.

- [ ] **Step 3: Write minimal implementation**

Add to `anchor-courses/src/Services/QuizService.php` (with `use Anchor\Courses\Admin\LessonEditor;`, `use Anchor\Courses\Content\LessonPostType;`, `use Anchor\Courses\Support\Grading;` at the top):

```php
	/**
	 * Grade and close an attempt (brief 8.4).
	 *
	 * Idempotent: an attempt that is no longer in progress is returned as-is.
	 * The timer is checked BEFORE the submitted answers are accepted, so a late
	 * POST can never overwrite what was saved inside the window (brief 8.5).
	 *
	 * @param array $answers question_id => answer id | id[] (merged over saved answers).
	 * @return QuizAttempt|\WP_Error
	 */
	public function submit( int $attempt_id, array $answers = [] ) {
		$attempt = QuizAttemptRepository::find( $attempt_id );
		if ( ! $attempt instanceof QuizAttempt ) {
			return new \WP_Error( 'no_attempt', \__( 'That attempt does not exist.', 'anchor-schema' ) );
		}
		if ( ! $attempt->is_open() ) {
			return $attempt; // Already graded / expired: nothing to do, nothing to fire.
		}

		$settings = $this->settings( $attempt->quiz_id );
		$deadline = $this->deadline( $attempt );
		$now      = Clock::timestamp();
		$late     = $deadline > 0 && $now > $deadline;

		if ( $late && 'expire' === (string) $settings['on_timer_expiry'] ) {
			$expired = QuizAttemptRepository::update(
				$attempt_id,
				[
					'status'           => 'expired',
					'submitted_at'     => Clock::now(),
					'duration_seconds' => \max( 0, $now - Clock::to_timestamp( $attempt->started_at ) ),
				]
			);
			$this->progress->record_item( $attempt->user_id, $attempt->course_id, $attempt->quiz_id, 'quiz', 'failed' );
			return $expired instanceof QuizAttempt ? $expired : $attempt;
		}

		// In-window: merge the submitted answers over what was saved.
		// Late + auto_submit: grade ONLY what was saved before the deadline.
		$final_answers = $attempt->answers;
		if ( ! $late ) {
			$questions_by_id = [];
			foreach ( Questions::get( $attempt->quiz_id ) as $question ) {
				$questions_by_id[ (string) $question['id'] ] = (string) $question['type'];
			}
			foreach ( $answers as $question_id => $value ) {
				$question_id = (string) $question_id;
				if ( ! isset( $questions_by_id[ $question_id ] ) ) {
					continue;
				}
				$final_answers[ $question_id ] = Grading::normalize_answer( $questions_by_id[ $question_id ], $value );
			}
		}

		$graded = Grading::grade( Questions::get( $attempt->quiz_id ), $final_answers );

		$result = \array_merge(
			$graded,
			[
				'passed'        => Grading::passed( $graded['score'], (int) $settings['passing_score'] ),
				'passing_score' => (int) $settings['passing_score'],
			]
		);

		/**
		 * Filter the graded result before it is stored.
		 *
		 * @param array       $result points_earned, points_possible, score,
		 *                            per_question, passed, passing_score.
		 * @param QuizAttempt $attempt
		 */
		$result = (array) \apply_filters( 'anchor_courses_quiz_result', $result, $attempt );

		$passed = ! empty( $result['passed'] );

		$saved = QuizAttemptRepository::update(
			$attempt_id,
			[
				'status'           => 'graded',
				'score'            => (float) $result['score'],
				'points_earned'    => (float) $result['points_earned'],
				'points_possible'  => (float) $result['points_possible'],
				'passed'           => $passed ? 1 : 0,
				'submitted_at'     => Clock::now(),
				'duration_seconds' => \max( 0, $now - Clock::to_timestamp( $attempt->started_at ) ),
				'answers'          => $final_answers,
				'grading_data'     => (array) $result['per_question'],
			]
		);

		if ( ! $saved instanceof QuizAttempt ) {
			return new \WP_Error( 'save_failed', \__( 'The attempt could not be graded.', 'anchor-schema' ) );
		}

		$this->progress->record_item(
			$saved->user_id,
			$saved->course_id,
			$saved->quiz_id,
			'quiz',
			$passed ? 'completed' : 'failed',
			[ 'metadata' => [ 'attempt_id' => $saved->id, 'score' => $saved->score ] ]
		);

		Log::write( 'quiz_submitted', [ 'attempt' => $saved->id, 'score' => $saved->score, 'passed' => $passed ] );

		/**
		 * Fires after an attempt is graded, pass or fail.
		 *
		 * @param QuizAttempt $saved
		 * @param int         $user_id
		 * @param int         $quiz_id
		 * @param int         $course_id
		 */
		\do_action( 'anchor_courses_quiz_submitted', $saved, $saved->user_id, $saved->quiz_id, $saved->course_id );
		\do_action(
			$passed ? 'anchor_courses_quiz_passed' : 'anchor_courses_quiz_failed',
			$saved,
			$saved->user_id,
			$saved->quiz_id,
			$saved->course_id
		);

		if ( $passed ) {
			$this->complete_gated_lessons( $saved );
		}

		$this->progress->recalculate_course( $saved->user_id, $saved->course_id );

		return $saved;
	}

	/**
	 * Complete any lesson in this course whose completion_mode is quiz_pass and
	 * whose quiz_id is the quiz just passed (brief 9.2).
	 */
	private function complete_gated_lessons( QuizAttempt $attempt ): void {
		foreach ( Curriculum::items( $attempt->course_id ) as $item ) {
			if ( 'lesson' !== $item['type'] ) {
				continue;
			}
			$lesson_id = (int) $item['id'];
			if ( 'quiz_pass' !== (string) LessonEditor::setting( $lesson_id, 'completion_mode' ) ) {
				continue;
			}
			if ( (int) LessonEditor::setting( $lesson_id, 'quiz_id' ) !== $attempt->quiz_id ) {
				continue;
			}
			$this->progress->complete_lesson( $attempt->user_id, $attempt->course_id, $lesson_id );
		}
	}

	/**
	 * Apply the timer policy to an attempt without a learner request.
	 *
	 * Used by the cron sweep and by any read path that wants a truthful status;
	 * returns the attempt unchanged when untimed or still inside the window.
	 */
	public function enforce_timer( QuizAttempt $attempt ): QuizAttempt {
		if ( ! $attempt->is_open() ) {
			return $attempt;
		}
		$deadline = $this->deadline( $attempt );
		if ( 0 === $deadline || Clock::timestamp() <= $deadline ) {
			return $attempt;
		}

		$result = $this->submit( $attempt->id );
		return $result instanceof QuizAttempt ? $result : $attempt;
	}

	/**
	 * Close out timed attempts whose window has passed but whose learner never
	 * came back. @return int attempts closed.
	 */
	public function sweep_expired_attempts(): int {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT * FROM " . \Anchor\Courses\Database\Migrations::table( 'quiz_attempts' ) . " WHERE status = 'in_progress'", // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		$closed = 0;
		foreach ( (array) $rows as $row ) {
			$attempt = QuizAttempt::from_row( $row );
			$after   = $this->enforce_timer( $attempt );
			if ( $after->status !== $attempt->status ) {
				$closed++;
			}
		}

		return $closed;
	}
```

`anchor-courses/anchor-courses.php` - add to the cron wiring next to the enrolment sweep:

```php
		\add_action( Services\EnrollmentService::CRON_HOOK, [ $this->quizzes, 'sweep_expired_attempts' ] );
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Quiz_Submit
```
Expected: PASS (13 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Services/QuizService.php anchor-courses/anchor-courses.php tests/test-courses-quiz-submit.php
git commit -m "feat(courses): server-side quiz grading, timer policy and progress hand-off"
```

---

### Task 26: Quiz REST routes and the quiz front end

**Files:**
- Create: `anchor-courses/src/Rest/Routes.php`, `anchor-courses/src/Rest/QuizController.php`
- Create: `anchor-courses/templates/quiz.php`, `anchor-courses/assets/quiz.js`
- Modify: `anchor-courses/src/Frontend/Shortcodes.php` - `render_quiz( int $quiz_id ): string`
- Modify: `anchor-courses/src/Frontend/Assets.php` - enqueue `quiz.js` on a lesson/course page
- Modify: `anchor-courses/anchor-courses.php` - construct `Rest\Routes`
- Test: `tests/test-courses-quiz-rest.php`

**Interfaces:**
- Produces:
  - `Rest\Routes::NAMESPACE = 'anchor-courses/v1'`
  - `Rest\Routes::register(): void` on `rest_api_init`; holds the shared permission helpers:
    - `Routes::require_login( \WP_REST_Request $request ): bool|\WP_Error`
    - `Routes::require_cap( string $key ): callable` - returns a `permission_callback`
    - `Routes::public_read( \WP_REST_Request $request ): bool|\WP_Error` - true only when the course post type is publicly queryable (a real check, not `__return_true`)
  - `Rest\QuizController::register_routes(): void`
    - `POST /quizzes/(?P<id>\d+)/attempts` -> start; body `course_id`
    - `GET /quiz-attempts/(?P<id>\d+)` -> attempt + learner questions
    - `POST /quiz-attempts/(?P<id>\d+)/answer` -> body `question_id`, `value`
    - `POST /quiz-attempts/(?P<id>\d+)/submit` -> body `answers` (object)
  - `Frontend\Shortcodes::render_quiz( int $quiz_id ): string`
  - JS global `anchorCoursesQuizRuntime` = `{restUrl, nonce, strings}`

Every route's `permission_callback` is `Routes::require_login` plus an ownership check inside the callback (`QuizService::owns_attempt()`), because a nonce proves a session, not ownership.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - quiz REST surface (brief 14, 25, rule 8).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Rest\Routes;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\QuizService;

/** @group courses */
class Test_Courses_Quiz_Rest extends Anchor_Courses_TestCase {

	private WP_REST_Server $server;
	private int $user;
	private int $course;
	private int $quiz;
	private string $q1;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->quiz   = $this->make_quiz( [ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 2 ] ] );

		$saved = Questions::save(
			$this->quiz,
			[ [ 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1,
			    'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'correct' => false ], [ 'id' => 'a2', 'text' => 'B', 'correct' => true ] ] ] ]
		);
		$this->q1 = $saved[0]['id'];

		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'quiz', 'id' => $this->quiz ] ] ] ] );
		( new EnrollmentService() )->enroll( $this->user, $this->course );
	}

	private function request( string $method, string $route, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . Routes::NAMESPACE . $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	public function test_all_four_quiz_routes_are_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/' . Routes::NAMESPACE . '/quizzes/(?P<id>\d+)/attempts', $routes );
		$this->assertArrayHasKey( '/' . Routes::NAMESPACE . '/quiz-attempts/(?P<id>\d+)', $routes );
		$this->assertArrayHasKey( '/' . Routes::NAMESPACE . '/quiz-attempts/(?P<id>\d+)/answer', $routes );
		$this->assertArrayHasKey( '/' . Routes::NAMESPACE . '/quiz-attempts/(?P<id>\d+)/submit', $routes );
	}

	/** Brief 25: no route in this namespace may be wide open. */
	public function test_no_route_uses_return_true_as_its_permission_callback() {
		foreach ( $this->server->get_routes() as $route => $handlers ) {
			if ( 0 !== strpos( $route, '/' . Routes::NAMESPACE ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$this->assertNotSame(
					'__return_true',
					$handler['permission_callback'] ?? null,
					"Route {$route} is unprotected."
				);
			}
		}
	}

	public function test_starting_an_attempt_requires_login() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_status() );
	}

	public function test_a_learner_can_start_an_attempt() {
		wp_set_current_user( $this->user );
		$response = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['attempt']['attempt_number'] );
		$this->assertCount( 1, $data['questions'] );
	}

	/** The single most important assertion in the module. */
	public function test_the_start_response_never_contains_correct() {
		wp_set_current_user( $this->user );
		$response = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] );

		$this->assertStringNotContainsString( 'correct', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_a_refusal_becomes_a_403_with_the_service_error_code() {
		wp_set_current_user( $this->make_learner() ); // not enrolled
		$response = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'not_enrolled', $response->get_data()['code'] );
	}

	public function test_reading_another_learners_attempt_is_403() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		wp_set_current_user( $this->make_learner() );
		$this->assertSame( 403, $this->request( 'GET', "/quiz-attempts/{$attempt_id}" )->get_status() );
	}

	public function test_saving_an_answer_then_submitting_grades_server_side() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		$saved = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/answer", [ 'question_id' => $this->q1, 'value' => 'a2' ] );
		$this->assertSame( 200, $saved->get_status() );

		$submitted = $this->request( 'POST', "/quiz-attempts/{$attempt_id}/submit", [] );
		$this->assertSame( 200, $submitted->get_status() );
		$this->assertSame( 100.0, $submitted->get_data()['attempt']['score'] );
		$this->assertTrue( $submitted->get_data()['attempt']['passed'] );
	}

	public function test_a_submitted_score_from_the_client_is_ignored() {
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];

		$submitted = $this->request(
			'POST',
			"/quiz-attempts/{$attempt_id}/submit",
			[ 'answers' => [ $this->q1 => 'a1' ], 'score' => 100, 'passed' => true ]
		);

		$this->assertSame( 0.0, $submitted->get_data()['attempt']['score'], 'Only the server may score an attempt.' );
	}

	public function test_a_graded_attempt_hides_grading_data_when_the_quiz_says_so() {
		update_post_meta( $this->quiz, '_anchor_quiz_settings', [ 'passing_score' => 80, 'show_correct_answers' => 0 ] );
		wp_set_current_user( $this->user );
		$attempt_id = $this->request( 'POST', "/quizzes/{$this->quiz}/attempts", [ 'course_id' => $this->course ] )->get_data()['attempt']['id'];
		$this->request( 'POST', "/quiz-attempts/{$attempt_id}/submit", [ 'answers' => [ $this->q1 => 'a2' ] ] );

		$read = $this->request( 'GET', "/quiz-attempts/{$attempt_id}" );

		$this->assertArrayNotHasKey( 'grading_data', $read->get_data()['attempt'] );
	}

	public function test_an_unknown_attempt_is_404() {
		wp_set_current_user( $this->user );
		$this->assertSame( 404, $this->request( 'GET', '/quiz-attempts/999999' )->get_status() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Quiz_Rest
```
Expected: FAIL - `Class "Anchor\Courses\Rest\Routes" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Rest/Routes.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Rest;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * REST registrar and the shared permission callbacks (brief 14, 25).
 *
 * No route in this namespace may use '__return_true'. Even the public course
 * index names a real callback, so "is this open?" is a decision with a single
 * place to audit.
 */
final class Routes {

	public const NAMESPACE = 'anchor-courses/v1';

	/** @var object[] Controllers with a register_routes() method. */
	private array $controllers;

	public function __construct( object ...$controllers ) {
		$this->controllers = $controllers;
		\add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		foreach ( $this->controllers as $controller ) {
			if ( \method_exists( $controller, 'register_routes' ) ) {
				$controller->register_routes();
			}
		}
	}

	/**
	 * Any signed-in user.
	 *
	 * @return true|\WP_Error
	 */
	public static function require_login( \WP_REST_Request $request ) {
		if ( \get_current_user_id() > 0 ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			\__( 'You must be signed in.', 'anchor-schema' ),
			[ 'status' => 401 ]
		);
	}

	/** A permission_callback requiring one of our capabilities. */
	public static function require_cap( string $key ): callable {
		return static function ( \WP_REST_Request $request ) use ( $key ) {
			if ( \current_user_can( Capabilities::cap( $key ) ) ) {
				return true;
			}
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You are not allowed to do that.', 'anchor-schema' ),
				[ 'status' => \get_current_user_id() > 0 ? 403 : 401 ]
			);
		};
	}

	/**
	 * Readable by anyone the course post type is already readable by.
	 *
	 * A real check, not __return_true: if a site makes courses non-public, this
	 * endpoint closes with them.
	 *
	 * @return true|\WP_Error
	 */
	public static function public_read( \WP_REST_Request $request ) {
		$type = \get_post_type_object( CoursePostType::CPT );
		if ( $type instanceof \WP_Post_Type && $type->publicly_queryable ) {
			return true;
		}
		return self::require_login( $request );
	}

	/** Map a service WP_Error onto an HTTP status. */
	public static function error_response( \WP_Error $error, int $status = 400 ): \WP_REST_Response {
		$map = [
			'not_enrolled'          => 403,
			'locked'                => 403,
			'not_in_course'         => 403,
			'course_closed'         => 403,
			'missing_prerequisite'  => 403,
			'no_attempts_remaining' => 403,
			'retry_delay'           => 403,
			'attempt_closed'        => 409,
			'no_attempt'            => 404,
			'no_course'             => 404,
			'no_user'               => 404,
			'unknown_question'      => 400,
			'no_questions'          => 409,
		];

		$code = (string) $error->get_error_code();

		return new \WP_REST_Response(
			[ 'code' => $code, 'message' => $error->get_error_message() ],
			$map[ $code ] ?? $status
		);
	}
}
```

`anchor-courses/src/Rest/QuizController.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Rest;

use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Services\QuizService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Quiz attempt endpoints (brief 14).
 *
 * The client may ONLY send answers. Score, pass/fail, attempt number, timing
 * and question order are all decided server-side; anything else in the body is
 * ignored (rule 4, rule 8).
 */
final class QuizController {

	public function __construct( private QuizService $quizzes ) {}

	public function register_routes(): void {
		\register_rest_route(
			Routes::NAMESPACE,
			'/quizzes/(?P<id>\d+)/attempts',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Routes::class, 'require_login' ],
				'callback'            => [ $this, 'start' ],
				'args'                => [
					'id'        => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
					'course_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
				],
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/quiz-attempts/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Routes::class, 'require_login' ],
				'callback'            => [ $this, 'read' ],
				'args'                => [ 'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ],
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/quiz-attempts/(?P<id>\d+)/answer',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Routes::class, 'require_login' ],
				'callback'            => [ $this, 'answer' ],
				'args'                => [
					'id'          => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
					'question_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'value'       => [ 'required' => true ],
				],
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/quiz-attempts/(?P<id>\d+)/submit',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Routes::class, 'require_login' ],
				'callback'            => [ $this, 'submit' ],
				'args'                => [
					'id'      => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
					'answers' => [ 'required' => false, 'type' => 'object', 'default' => [] ],
				],
			]
		);
	}

	public function start( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = \get_current_user_id();
		$attempt = $this->quizzes->start_attempt(
			$user_id,
			(int) $request['id'],
			(int) $request['course_id']
		);

		if ( \is_wp_error( $attempt ) ) {
			return Routes::error_response( $attempt );
		}

		return new \WP_REST_Response( $this->payload( $attempt, true ), 201 );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		$attempt = $this->owned_attempt( (int) $request['id'] );
		if ( $attempt instanceof \WP_REST_Response ) {
			return $attempt;
		}

		// A read is a good moment to notice the window closed while they were away.
		$attempt = $this->quizzes->enforce_timer( $attempt );

		return new \WP_REST_Response( $this->payload( $attempt, $attempt->is_open() ), 200 );
	}

	public function answer( \WP_REST_Request $request ): \WP_REST_Response {
		$attempt = $this->owned_attempt( (int) $request['id'] );
		if ( $attempt instanceof \WP_REST_Response ) {
			return $attempt;
		}

		$saved = $this->quizzes->save_answer(
			$attempt->id,
			(string) $request['question_id'],
			$request['value']
		);

		if ( \is_wp_error( $saved ) ) {
			return Routes::error_response( $saved );
		}

		return new \WP_REST_Response( [ 'saved' => true ], 200 );
	}

	public function submit( \WP_REST_Request $request ): \WP_REST_Response {
		$attempt = $this->owned_attempt( (int) $request['id'] );
		if ( $attempt instanceof \WP_REST_Response ) {
			return $attempt;
		}

		// ONLY `answers` is read from the body. A client-sent score or passed
		// flag is ignored by construction (rule 4).
		$graded = $this->quizzes->submit( $attempt->id, (array) $request['answers'] );

		if ( \is_wp_error( $graded ) ) {
			return Routes::error_response( $graded );
		}

		return new \WP_REST_Response( $this->payload( $graded, false ), 200 );
	}

	/** @return QuizAttempt|\WP_REST_Response */
	private function owned_attempt( int $attempt_id ) {
		$attempt = $this->quizzes->get_attempt( $attempt_id );
		if ( ! $attempt instanceof QuizAttempt ) {
			return new \WP_REST_Response(
				[ 'code' => 'no_attempt', 'message' => \__( 'That attempt does not exist.', 'anchor-schema' ) ],
				404
			);
		}
		if ( $attempt->user_id !== \get_current_user_id() ) {
			return new \WP_REST_Response(
				[ 'code' => 'rest_forbidden', 'message' => \__( 'That attempt belongs to someone else.', 'anchor-schema' ) ],
				403
			);
		}
		return $attempt;
	}

	/** @param bool $with_questions Include the learner question payload. */
	private function payload( QuizAttempt $attempt, bool $with_questions ): array {
		$settings     = $this->quizzes->settings( $attempt->quiz_id );
		$show_correct = 1 === (int) $settings['show_correct_answers'];

		$out = [
			'attempt'             => $attempt->for_learner( $show_correct ),
			'deadline'            => $this->quizzes->deadline( $attempt ),
			'server_now'          => \Anchor\Courses\Support\Clock::timestamp(),
			'attempts_remaining'  => $this->quizzes->attempts_remaining( $attempt->user_id, $attempt->quiz_id ),
			'retry_available_at'  => $this->quizzes->retry_available_at( $attempt->user_id, $attempt->quiz_id ),
			'show_score'          => 1 === (int) $settings['show_score'],
		];

		if ( $with_questions ) {
			$out['questions'] = $this->quizzes->questions_for_learner( $attempt->quiz_id, $attempt );
		}

		return $out;
	}
}
```

`anchor-courses/anchor-courses.php` - construct after `$this->quizzes`:

```php
		new Rest\Routes( new Rest\QuizController( $this->quizzes ) );
```

`anchor-courses/templates/quiz.php`:

```php
<?php
/**
 * Quiz shell. The questions are fetched over REST after "Start", so no answer
 * payload is ever in the page source before an attempt exists (brief 8.3).
 *
 * Variables: $quiz_id, $course_id, $user_id, $can_start (true|WP_Error),
 * $attempts_remaining (int), $best (QuizAttempt|null).
 *
 * Theme override: anchor-courses/quiz.php
 *
 * @package Anchor\Courses
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="anchor-quiz" data-quiz="<?php echo esc_attr( (string) $quiz_id ); ?>" data-course="<?php echo esc_attr( (string) $course_id ); ?>">
	<h3 class="anchor-quiz-title"><?php echo esc_html( get_the_title( $quiz_id ) ); ?></h3>

	<?php if ( $best && $best->is_graded() ) : ?>
		<p class="anchor-quiz-best">
			<?php
			printf(
				/* translators: %s: best score as a percentage. */
				esc_html__( 'Best score: %s%%', 'anchor-schema' ),
				esc_html( number_format_i18n( (float) $best->score, 0 ) )
			);
			?>
			<?php if ( $best->passed ) : ?>
				<span class="anchor-quiz-passed"><?php esc_html_e( 'Passed', 'anchor-schema' ); ?></span>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( is_wp_error( $can_start ) ) : ?>
		<p class="anchor-courses-notice"><?php echo esc_html( $can_start->get_error_message() ); ?></p>
	<?php else : ?>
		<?php if ( $attempts_remaining >= 0 ) : ?>
			<p class="anchor-quiz-remaining">
				<?php
				printf(
					/* translators: %d: number of attempts left. */
					esc_html( _n( '%d attempt remaining', '%d attempts remaining', $attempts_remaining, 'anchor-schema' ) ),
					(int) $attempts_remaining
				);
				?>
			</p>
		<?php endif; ?>
		<button type="button" class="anchor-courses-button anchor-quiz-start"><?php esc_html_e( 'Start quiz', 'anchor-schema' ); ?></button>
	<?php endif; ?>

	<div class="anchor-quiz-timer" hidden></div>
	<form class="anchor-quiz-form" hidden></form>
	<div class="anchor-quiz-result" hidden></div>
	<noscript><p><?php esc_html_e( 'This quiz needs JavaScript.', 'anchor-schema' ); ?></p></noscript>
</div>
```

`anchor-courses/assets/quiz.js`:

```javascript
/**
 * Anchor Courses - quiz runtime.
 *
 * Renders questions fetched from REST, keeps a purely visual countdown, and
 * posts answers back. It NEVER computes a score: every number it displays came
 * from the server (brief rule 4).
 */
(function ($) {
    'use strict';

    var cfg = window.anchorCoursesQuizRuntime || {};
    var S = cfg.strings || {};

    function esc(t) { return $('<div/>').text(t == null ? '' : String(t)).html(); }

    function api(path, method, body) {
        return $.ajax({
            url: cfg.restUrl + path,
            method: method || 'GET',
            data: body ? JSON.stringify(body) : undefined,
            contentType: 'application/json',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', cfg.nonce); }
        });
    }

    function questionMarkup(q, index) {
        var input = q.type === 'multiple_choice' ? 'checkbox' : 'radio';
        var answers = (q.answers || []).map(function (a) {
            return '<li><label><input type="' + input + '" name="q_' + esc(q.id) + '" value="' + esc(a.id) + '" /> ' +
                esc(a.text) + '</label></li>';
        }).join('');

        return '<fieldset class="anchor-quiz-question" data-question="' + esc(q.id) + '">' +
            '<legend>' + (index + 1) + '. ' + q.prompt + '</legend>' +
            '<ul class="anchor-quiz-answers">' + answers + '</ul>' +
            '</fieldset>';
    }

    $(function () {
        var $root = $('.anchor-quiz');
        if (!$root.length) { return; }

        var quizId = $root.data('quiz');
        var courseId = $root.data('course');
        var attemptId = 0;
        var timerHandle = null;

        function startCountdown(deadline, serverNow) {
            var skew = Math.floor(Date.now() / 1000) - serverNow;
            var $timer = $root.find('.anchor-quiz-timer');

            if (!deadline) { $timer.prop('hidden', true); return; }
            $timer.prop('hidden', false);

            if (timerHandle) { window.clearInterval(timerHandle); }
            timerHandle = window.setInterval(function () {
                var left = deadline - (Math.floor(Date.now() / 1000) - skew);
                if (left <= 0) {
                    window.clearInterval(timerHandle);
                    $timer.text(S.timeUp || '');
                    // The server decides what an expired attempt means.
                    $root.find('.anchor-quiz-form').trigger('submit');
                    return;
                }
                var m = Math.floor(left / 60);
                var s = left % 60;
                $timer.text(m + ':' + (s < 10 ? '0' : '') + s);
            }, 1000);
        }

        function renderResult(data) {
            var a = data.attempt || {};
            var text = a.passed ? (S.passed || '') : (S.failed || '');
            if (data.show_score && a.score !== null && typeof a.score !== 'undefined') {
                text += ' ' + a.score + '%';
            }
            $root.find('.anchor-quiz-form').prop('hidden', true).empty();
            $root.find('.anchor-quiz-timer').prop('hidden', true);
            $root.find('.anchor-quiz-result').prop('hidden', false).text(text);
            if (timerHandle) { window.clearInterval(timerHandle); }
        }

        $root.on('click', '.anchor-quiz-start', function () {
            var $btn = $(this).prop('disabled', true);

            api('quizzes/' + quizId + '/attempts', 'POST', { course_id: courseId })
                .done(function (data) {
                    attemptId = data.attempt.id;
                    var html = (data.questions || []).map(questionMarkup).join('') +
                        '<p><button type="submit" class="anchor-courses-button">' + esc(S.submit) + '</button></p>';
                    $root.find('.anchor-quiz-form').html(html).prop('hidden', false);
                    $btn.prop('hidden', true);
                    startCountdown(data.deadline, data.server_now);
                })
                .fail(function (xhr) {
                    $btn.prop('disabled', false);
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || S.error;
                    $root.find('.anchor-quiz-result').prop('hidden', false).text(msg);
                });
        });

        // Save each answer as it changes, so an expired timer still has them.
        $root.on('change', '.anchor-quiz-answers input', function () {
            if (!attemptId) { return; }
            var $q = $(this).closest('.anchor-quiz-question');
            var qid = $q.data('question');
            var values = $q.find('input:checked').map(function () { return this.value; }).get();
            api('quiz-attempts/' + attemptId + '/answer', 'POST', { question_id: qid, value: values });
        });

        $root.on('submit', '.anchor-quiz-form', function (e) {
            e.preventDefault();
            if (!attemptId) { return; }
            $(this).find('button[type="submit"]').prop('disabled', true);

            var answers = {};
            $root.find('.anchor-quiz-question').each(function () {
                var $q = $(this);
                answers[$q.data('question')] = $q.find('input:checked').map(function () { return this.value; }).get();
            });

            api('quiz-attempts/' + attemptId + '/submit', 'POST', { answers: answers })
                .done(renderResult)
                .fail(function () {
                    $root.find('.anchor-quiz-result').prop('hidden', false).text(S.error);
                });
        });
    });
})(jQuery);
```

Add to `Frontend\Shortcodes` (and register `[anchor_quiz]` is **not** needed - the quiz renders inside the lesson/course, per design spec):

```php
	/** Rendered inside a lesson or course; not a public shortcode. */
	public function render_quiz( int $quiz_id ): string {
		$course_id = Curriculum::course_for_item( $quiz_id, 'quiz' );
		$user_id   = \get_current_user_id();

		if ( $course_id <= 0 || $user_id <= 0 ) {
			return '';
		}

		$module = \Anchor\Courses\Module::instance();
		if ( ! $module ) {
			return '';
		}

		return Templates::render(
			'quiz',
			[
				'quiz_id'            => $quiz_id,
				'course_id'          => $course_id,
				'user_id'            => $user_id,
				'can_start'          => $module->quizzes->can_start( $user_id, $quiz_id, $course_id ),
				'attempts_remaining' => $module->quizzes->attempts_remaining( $user_id, $quiz_id ),
				'best'               => \Anchor\Courses\Database\QuizAttemptRepository::best_for_quiz( $user_id, $quiz_id ),
			]
		);
	}
```

Add to `Frontend\Assets::enqueue()`:

```php
		\wp_enqueue_script(
			'anchor-courses-quiz',
			Module::assets_url() . 'quiz.js',
			[ 'jquery' ],
			Module::VERSION,
			true
		);
		\wp_localize_script(
			'anchor-courses-quiz',
			'anchorCoursesQuizRuntime',
			[
				'restUrl' => \esc_url_raw( \rest_url( 'anchor-courses/v1/' ) ),
				'nonce'   => \wp_create_nonce( 'wp_rest' ),
				'strings' => [
					'submit' => \__( 'Submit quiz', 'anchor-schema' ),
					'passed' => \__( 'Passed.', 'anchor-schema' ),
					'failed' => \__( 'Not passed.', 'anchor-schema' ),
					'timeUp' => \__( 'Time is up - submitting your saved answers.', 'anchor-schema' ),
					'error'  => \__( 'Something went wrong. Please refresh and try again.', 'anchor-schema' ),
				],
			]
		);
```

Add to `anchor-courses/templates/course.php`, inside the item loop, replacing the quiz branch:

```php
							<?php if ( 'quiz' === $item['type'] && $available ) : ?>
								<?php
								$anchor_courses_module = \Anchor\Courses\Module::instance();
								echo $anchor_courses_module ? $anchor_courses_module->shortcodes->render_quiz( (int) $item['id'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- render_quiz() escapes internally.
								?>
							<?php endif; ?>
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Quiz_Rest
```
Expected: PASS (11 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Rest anchor-courses/src/Frontend anchor-courses/templates/quiz.php \
        anchor-courses/templates/course.php anchor-courses/assets/quiz.js \
        anchor-courses/anchor-courses.php tests/test-courses-quiz-rest.php
git commit -m "feat(courses): quiz REST routes and the answer-safe quiz runtime"
```

---

## Phase 4 - Course Completion + CE

### Task 27: CE credits - repository, value object, service and public API

**Files:**
- Create: `anchor-courses/src/Domain/Credit.php`, `anchor-courses/src/Database/CreditRepository.php`, `anchor-courses/src/Services/CreditService.php`
- Modify: `anchor-courses/api.php` - add `anchor_courses_award_ce_credit()`
- Modify: `anchor-courses/anchor-courses.php` - construct `$this->credits`
- Test: `tests/test-courses-credits.php`

**Interfaces:**
- Produces:
  - `Domain\Credit` - readonly `id, user_id, course_id, credits (float), credit_type, awarded_at, expires_at (?string), certificate_id (int), metadata (array), created_at`; `::from_row()`, `->to_array()`, `->is_expired( int $now ): bool`
  - `Database\CreditRepository::find( int $user_id, int $course_id ): ?Credit`
  - `::find_by_id( int $id ): ?Credit`
  - `::insert_ignore( array $data ): ?Credit`
  - `::attach_certificate( int $credit_id, int $certificate_id ): ?Credit`
  - `::for_user( int $user_id ): array`, `::for_course( int $course_id ): array`
  - `::total_for_user( int $user_id ): float`
  - `Services\CreditService::award( int $user_id, int $course_id, ?float $credits = null, array $args = [] ): ?Credit`
  - `::get( int $user_id, int $course_id ): ?Credit`, `::for_user( int $user_id ): array`, `::total_for_user( int $user_id ): float`
  - `::course_credit_config( int $course_id ): array` - `['credits','type','provider_name','provider_number','expires_days']`
  - Action: `anchor_courses_ce_credit_awarded( int $user_id, int $course_id, Credit $credit )`
  - Filters: `anchor_courses_ce_credit_amount( float $credits, int $user_id, int $course_id )`, `anchor_courses_ce_credit_data( array $data, int $user_id, int $course_id )`
  - `anchor_courses_award_ce_credit( int $user_id, int $course_id, float $credits )` in `api.php`

`award()` returns `null` when the course awards 0 credits; an existing row is returned unchanged (D13: one award per user+course).

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - CE credits (brief 12, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Services\CreditService;

/** @group courses */
class Test_Courses_Credits extends Anchor_Courses_TestCase {

	private CreditService $credits;
	private int $user;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->credits = new CreditService();
		$this->user    = $this->make_learner();
		$this->course  = $this->make_course(
			[ 'ce_credits' => '2', 'ce_type' => 'Dental CE', 'ce_provider_name' => 'DEKA Academy',
			  'ce_provider_number' => 'AGD-1234', 'ce_expires_days' => '365' ]
		);
	}

	public function tear_down() {
		remove_all_actions( 'anchor_courses_ce_credit_awarded' );
		remove_all_filters( 'anchor_courses_ce_credit_amount' );
		remove_all_filters( 'anchor_courses_now' );
		parent::tear_down();
	}

	public function test_award_creates_a_record_and_fires_the_action() {
		$fired = 0;
		add_action( 'anchor_courses_ce_credit_awarded', function () use ( &$fired ) { $fired++; }, 10, 3 );

		$credit = $this->credits->award( $this->user, $this->course );

		$this->assertInstanceOf( Credit::class, $credit );
		$this->assertSame( 2.0, $credit->credits );
		$this->assertSame( 'Dental CE', $credit->credit_type );
		$this->assertSame( 'DEKA Academy', $credit->metadata['provider_name'] );
		$this->assertSame( 'AGD-1234', $credit->metadata['provider_number'] );
		$this->assertSame( 1, $fired );
	}

	/** Brief rule 7 / 26: the whole point of this table's unique key. */
	public function test_awarding_twice_does_not_duplicate_credits() {
		$fired = 0;
		add_action( 'anchor_courses_ce_credit_awarded', function () use ( &$fired ) { $fired++; }, 10, 3 );

		$first  = $this->credits->award( $this->user, $this->course );
		$second = $this->credits->award( $this->user, $this->course );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( 1, $fired );
		$this->assertSame( 2.0, $this->credits->total_for_user( $this->user ) );
	}

	public function test_a_course_with_no_credits_awards_nothing() {
		$free = $this->make_course( [ 'ce_credits' => '0' ] );
		$this->assertNull( $this->credits->award( $this->user, $free ) );
	}

	public function test_an_explicit_amount_overrides_the_course_setting() {
		$credit = $this->credits->award( $this->user, $this->course, 5.5 );
		$this->assertSame( 5.5, $credit->credits );
	}

	public function test_the_amount_filter_can_adjust_the_award() {
		add_filter( 'anchor_courses_ce_credit_amount', static fn( $credits ) => $credits * 2, 10, 3 );
		$this->assertSame( 4.0, $this->credits->award( $this->user, $this->course )->credits );
	}

	public function test_expiry_is_computed_from_ce_expires_days() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-01-01 00:00:00 UTC' ) );
		$credit = $this->credits->award( $this->user, $this->course );
		$this->assertSame( '2027-01-01 00:00:00', $credit->expires_at );
		$this->assertFalse( $credit->is_expired( strtotime( '2026-06-01 UTC' ) ) );
		$this->assertTrue( $credit->is_expired( strtotime( '2027-06-01 UTC' ) ) );
	}

	public function test_zero_expires_days_means_never_expires() {
		$forever = $this->make_course( [ 'ce_credits' => '1', 'ce_expires_days' => '0' ] );
		$credit  = $this->credits->award( $this->user, $forever );
		$this->assertNull( $credit->expires_at );
		$this->assertFalse( $credit->is_expired( strtotime( '2099-01-01 UTC' ) ) );
	}

	public function test_totals_and_listing_per_user() {
		$second = $this->make_course( [ 'ce_credits' => '3' ] );
		$this->credits->award( $this->user, $this->course );
		$this->credits->award( $this->user, $second );

		$this->assertCount( 2, $this->credits->for_user( $this->user ) );
		$this->assertSame( 5.0, $this->credits->total_for_user( $this->user ) );
		$this->assertSame( 0.0, $this->credits->total_for_user( $this->make_learner() ) );
	}

	public function test_the_public_api_function_awards_an_explicit_amount() {
		$credit = anchor_courses_award_ce_credit( $this->user, $this->course, 1.5 );
		$this->assertInstanceOf( Credit::class, $credit );
		$this->assertSame( 1.5, $credit->credits );
	}

	public function test_award_refuses_a_non_course() {
		$this->assertNull( $this->credits->award( $this->user, $this->make_lesson(), 1.0 ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Credits
```
Expected: FAIL - `Class "Anchor\Courses\Services\CreditService" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Domain/Credit.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One CE award (brief 7.4, 12). Immutable. */
final class Credit {

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly float $credits,
		public readonly string $credit_type,
		public readonly string $awarded_at,
		public readonly ?string $expires_at,
		public readonly int $certificate_id,
		public readonly array $metadata,
		public readonly string $created_at
	) {}

	public static function from_row( array $row ): self {
		$metadata = \json_decode( (string) ( $row['metadata'] ?? '' ), true );

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['user_id'] ?? 0 ),
			(int) ( $row['course_id'] ?? 0 ),
			(float) ( $row['credits'] ?? 0 ),
			(string) ( $row['credit_type'] ?? '' ),
			(string) ( $row['awarded_at'] ?? '' ),
			( isset( $row['expires_at'] ) && '' !== (string) $row['expires_at'] ) ? (string) $row['expires_at'] : null,
			(int) ( $row['certificate_id'] ?? 0 ),
			\is_array( $metadata ) ? $metadata : [],
			(string) ( $row['created_at'] ?? '' )
		);
	}

	public function is_expired( int $now ): bool {
		if ( null === $this->expires_at ) {
			return false;
		}
		return $now > Clock::to_timestamp( $this->expires_at );
	}

	public function to_array(): array {
		return [
			'id'             => $this->id,
			'user_id'        => $this->user_id,
			'course_id'      => $this->course_id,
			'credits'        => $this->credits,
			'credit_type'    => $this->credit_type,
			'awarded_at'     => $this->awarded_at,
			'expires_at'     => $this->expires_at,
			'certificate_id' => $this->certificate_id,
			'metadata'       => $this->metadata,
			'created_at'     => $this->created_at,
		];
	}
}
```

`anchor-courses/src/Database/CreditRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * All SQL for wp_anchor_courses_ce_credits.
 *
 * UNIQUE (user_id, course_id) is the no-duplicate-credits guarantee (rule 7);
 * insert_ignore() leans on it rather than on a read-then-write race.
 */
final class CreditRepository {

	private static function table(): string {
		return Migrations::table( 'ce_credits' );
	}

	public static function find( int $user_id, int $course_id ): ?Credit {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d',
				$user_id,
				$course_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? Credit::from_row( $row ) : null;
	}

	public static function find_by_id( int $id ): ?Credit {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return \is_array( $row ) ? Credit::from_row( $row ) : null;
	}

	public static function insert_ignore( array $data ): ?Credit {
		global $wpdb;

		$values = [
			'user_id'        => (int) ( $data['user_id'] ?? 0 ),
			'course_id'      => (int) ( $data['course_id'] ?? 0 ),
			'credits'        => (float) ( $data['credits'] ?? 0 ),
			'credit_type'    => (string) ( $data['credit_type'] ?? '' ),
			'awarded_at'     => (string) ( $data['awarded_at'] ?? Clock::now() ),
			'expires_at'     => $data['expires_at'] ?? null,
			'certificate_id' => (int) ( $data['certificate_id'] ?? 0 ),
			'metadata'       => (string) \wp_json_encode( (array) ( $data['metadata'] ?? [] ) ),
			'created_at'     => Clock::now(),
		];

		$columns      = \implode( ', ', \array_keys( $values ) );
		$placeholders = \implode( ', ', \array_fill( 0, \count( $values ), '%s' ) );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . self::table() . " ({$columns}) VALUES ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_values( $values )
			)
		);

		return self::find( $values['user_id'], $values['course_id'] );
	}

	public static function attach_certificate( int $credit_id, int $certificate_id ): ?Credit {
		global $wpdb;
		$wpdb->update( self::table(), [ 'certificate_id' => $certificate_id ], [ 'id' => $credit_id ], [ '%d' ], [ '%d' ] );
		return self::find_by_id( $credit_id );
	}

	/** @return Credit[] */
	public static function for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY awarded_at DESC', $user_id ),
			ARRAY_A
		);
		return \array_map( [ Credit::class, 'from_row' ], (array) $rows );
	}

	/** @return Credit[] */
	public static function for_course( int $course_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE course_id = %d ORDER BY awarded_at DESC', $course_id ),
			ARRAY_A
		);
		return \array_map( [ Credit::class, 'from_row' ], (array) $rows );
	}

	public static function total_for_user( int $user_id ): float {
		global $wpdb;
		return (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(credits), 0) FROM ' . self::table() . ' WHERE user_id = %d', $user_id )
		);
	}
}
```

`anchor-courses/src/Services/CreditService.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Log;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** CE credits as a first-class record, not a certificate side effect (brief 12). */
final class CreditService {

	/** @return array{credits:float,type:string,provider_name:string,provider_number:string,expires_days:int} */
	public function course_credit_config( int $course_id ): array {
		return [
			'credits'         => (float) CourseEditor::setting( $course_id, 'ce_credits' ),
			'type'            => (string) CourseEditor::setting( $course_id, 'ce_type' ),
			'provider_name'   => (string) CourseEditor::setting( $course_id, 'ce_provider_name' ),
			'provider_number' => (string) CourseEditor::setting( $course_id, 'ce_provider_number' ),
			'expires_days'    => (int) CourseEditor::setting( $course_id, 'ce_expires_days' ),
		];
	}

	/**
	 * Award CE credits. Idempotent per (user, course) - a second call returns the
	 * existing record and fires nothing (rule 7).
	 *
	 * @param float|null $credits Explicit amount, or null to use the course setting.
	 */
	public function award( int $user_id, int $course_id, ?float $credits = null, array $args = [] ): ?Credit {
		if ( CoursePostType::CPT !== \get_post_type( $course_id ) || ! \get_userdata( $user_id ) ) {
			return null;
		}

		$existing = CreditRepository::find( $user_id, $course_id );
		if ( $existing instanceof Credit ) {
			return $existing;
		}

		$config = $this->course_credit_config( $course_id );
		$amount = null === $credits ? $config['credits'] : $credits;

		/**
		 * Filter the credit amount before the record is created.
		 *
		 * @param float $amount
		 * @param int   $user_id
		 * @param int   $course_id
		 */
		$amount = (float) \apply_filters( 'anchor_courses_ce_credit_amount', $amount, $user_id, $course_id );

		if ( $amount <= 0 ) {
			return null;
		}

		$data = [
			'user_id'     => $user_id,
			'course_id'   => $course_id,
			'credits'     => $amount,
			'credit_type' => $config['type'],
			'awarded_at'  => Clock::now(),
			'expires_at'  => $config['expires_days'] > 0 ? Clock::offset( $config['expires_days'] * DAY_IN_SECONDS ) : null,
			'metadata'    => \array_merge(
				[
					'provider_name'   => $config['provider_name'],
					'provider_number' => $config['provider_number'],
				],
				(array) ( $args['metadata'] ?? [] )
			),
		];

		/**
		 * Filter the whole credit row before insert.
		 *
		 * @param array $data
		 * @param int   $user_id
		 * @param int   $course_id
		 */
		$data = (array) \apply_filters( 'anchor_courses_ce_credit_data', $data, $user_id, $course_id );

		$credit = CreditRepository::insert_ignore( $data );
		if ( ! $credit instanceof Credit ) {
			return null;
		}

		Log::write( 'ce_awarded', [ 'user' => $user_id, 'course' => $course_id, 'credits' => $credit->credits ] );

		/**
		 * Fires once, when CE credits are recorded for a learner.
		 *
		 * @param int    $user_id
		 * @param int    $course_id
		 * @param Credit $credit
		 */
		\do_action( 'anchor_courses_ce_credit_awarded', $user_id, $course_id, $credit );

		return $credit;
	}

	public function get( int $user_id, int $course_id ): ?Credit {
		return CreditRepository::find( $user_id, $course_id );
	}

	/** @return Credit[] */
	public function for_user( int $user_id ): array {
		return CreditRepository::for_user( $user_id );
	}

	public function total_for_user( int $user_id ): float {
		return CreditRepository::total_for_user( $user_id );
	}
}
```

`anchor-courses/api.php` - append:

```php
/**
 * Record a CE credit award (brief section 15).
 *
 * Idempotent per (user, course): a second call returns the existing record.
 *
 * @param int   $user_id
 * @param int   $course_id
 * @param float $credits
 * @return \Anchor\Courses\Domain\Credit|null
 */
function anchor_courses_award_ce_credit( $user_id, $course_id, $credits ) {
	return ( new \Anchor\Courses\Services\CreditService() )
		->award( (int) $user_id, (int) $course_id, (float) $credits );
}
```

`anchor-courses/anchor-courses.php`:

```php
	public Services\CreditService $credits;
```

```php
		$this->credits = new Services\CreditService();
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Credits
```
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Domain/Credit.php anchor-courses/src/Database/CreditRepository.php \
        anchor-courses/src/Services/CreditService.php anchor-courses/api.php \
        anchor-courses/anchor-courses.php tests/test-courses-credits.php
git commit -m "feat(courses): CE credit records that cannot be awarded twice"
```

---

### Task 28: Certificates - repository, value object, numbering and service

**Files:**
- Create: `anchor-courses/src/Domain/Certificate.php`, `anchor-courses/src/Database/CertificateRepository.php`, `anchor-courses/src/Services/CertificateService.php`
- Modify: `anchor-courses/anchor-courses.php` - construct `$this->certificates`
- Test: `tests/unit/test-certificate-number.php` (pure), `tests/test-courses-certificates.php` (integration)

**Interfaces:**
- Produces:
  - `Domain\Certificate` - readonly `id, user_id, course_id, certificate_number, issued_at, expires_at (?string), file_path (string), verification_token, metadata (array), created_at`; `::from_row()`, `->to_array()`, `->url(): string`
  - `Database\CertificateRepository::find( int $user_id, int $course_id ): ?Certificate`
  - `::find_by_id( int $id ): ?Certificate`, `::find_by_token( string $token ): ?Certificate`
  - `::insert_ignore( array $data ): ?Certificate` - inserts with a `PENDING-{uniqid}` number (D14), then the service assigns the real one
  - `::set_number( int $id, string $number ): ?Certificate`
  - `::for_user( int $user_id ): array`
  - `Services\CertificateService::format_number( int $id, int $year ): string` - **pure static**, `AC-{YYYY}-{8-digit id}`
  - `::issue( int $user_id, int $course_id ): ?Certificate`
  - `::get( int $user_id, int $course_id ): ?Certificate`, `::get_by_token( string $token ): ?Certificate`, `::for_user( int $user_id ): array`
  - `::template_data( Certificate $certificate ): array` - brief 13 variables
  - `::render( Certificate $certificate ): string`
  - Action: `anchor_courses_certificate_issued( int $user_id, int $course_id, Certificate $certificate )`
  - Filter: `anchor_courses_certificate_data( array $data, Certificate $certificate )`

`issue()` returns `null` when the course has `certificate_enabled = 0`, and returns the existing row otherwise (D13).

- [ ] **Step 1: Write the failing tests**

`tests/unit/test-certificate-number.php`:

```php
<?php
/**
 * Pure unit test: certificate numbering (brief 13, design spec 4).
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Services\CertificateService;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Certificate_Number extends TestCase {

	public function test_the_documented_format() {
		$this->assertSame( 'AC-2026-00000124', CertificateService::format_number( 124, 2026 ) );
	}

	public function test_the_id_is_zero_padded_to_eight_digits() {
		$this->assertSame( 'AC-2026-00000001', CertificateService::format_number( 1, 2026 ) );
		$this->assertSame( 'AC-2026-12345678', CertificateService::format_number( 12345678, 2026 ) );
	}

	public function test_an_id_longer_than_eight_digits_is_not_truncated() {
		$this->assertSame( 'AC-2026-123456789', CertificateService::format_number( 123456789, 2026 ) );
	}

	public function test_the_year_comes_from_the_caller_not_the_clock() {
		$this->assertSame( 'AC-2031-00000007', CertificateService::format_number( 7, 2031 ) );
	}
}
```

`tests/test-courses-certificates.php`:

```php
<?php
/**
 * Anchor Courses - certificate issuing (brief 13, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CreditService;

/** @group courses */
class Test_Courses_Certificates extends Anchor_Courses_TestCase {

	private CertificateService $certificates;
	private int $user;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->certificates = new CertificateService();
		$this->user         = $this->make_learner( [ 'display_name' => 'Ada Lovelace' ] );
		$this->course       = $this->make_course(
			[ 'certificate_enabled' => 1, 'ce_credits' => '2', 'ce_type' => 'Dental CE',
			  'ce_provider_name' => 'DEKA Academy', 'ce_provider_number' => 'AGD-1234', 'instructor' => 'Dr Vega' ],
			'Laser Safety'
		);
	}

	public function tear_down() {
		remove_all_actions( 'anchor_courses_certificate_issued' );
		remove_all_filters( 'anchor_courses_certificate_data' );
		remove_all_filters( 'anchor_courses_now' );
		parent::tear_down();
	}

	public function test_issue_creates_a_numbered_certificate_and_fires_the_action() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-07-04 12:00:00 UTC' ) );
		$fired = 0;
		add_action( 'anchor_courses_certificate_issued', function () use ( &$fired ) { $fired++; }, 10, 3 );

		$certificate = $this->certificates->issue( $this->user, $this->course );

		$this->assertInstanceOf( Certificate::class, $certificate );
		$this->assertMatchesRegularExpression( '/^AC-2026-\d{8}$/', $certificate->certificate_number );
		$this->assertNotSame( '', $certificate->verification_token );
		$this->assertSame( '', $certificate->file_path, 'Phase 1 is an HTML page; file_path stays empty.' );
		$this->assertSame( 1, $fired );
	}

	/** Brief rule 7: repeated completion must not mint a second certificate. */
	public function test_issuing_twice_returns_the_same_certificate() {
		$first  = $this->certificates->issue( $this->user, $this->course );
		$second = $this->certificates->issue( $this->user, $this->course );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( $first->certificate_number, $second->certificate_number );
	}

	public function test_certificate_numbers_are_unique_across_learners() {
		$a = $this->certificates->issue( $this->user, $this->course );
		$b = $this->certificates->issue( $this->make_learner(), $this->course );

		$this->assertNotSame( $a->certificate_number, $b->certificate_number );
		$this->assertStringNotContainsString( 'PENDING', $b->certificate_number );
	}

	public function test_verification_tokens_are_unique_and_findable() {
		$certificate = $this->certificates->issue( $this->user, $this->course );

		$found = $this->certificates->get_by_token( $certificate->verification_token );

		$this->assertSame( $certificate->id, $found->id );
		$this->assertNull( $this->certificates->get_by_token( 'not-a-token' ) );
	}

	public function test_a_course_with_certificates_disabled_issues_nothing() {
		$no_cert = $this->make_course( [ 'certificate_enabled' => 0 ] );
		$this->assertNull( $this->certificates->issue( $this->user, $no_cert ) );
	}

	public function test_template_data_carries_every_brief_variable() {
		( new CreditService() )->award( $this->user, $this->course );
		$certificate = $this->certificates->issue( $this->user, $this->course );

		$data = $this->certificates->template_data( $certificate );

		foreach ( [ 'learner_name', 'course_name', 'completion_date', 'ce_credits', 'certificate_number',
		            'instructor_name', 'provider_name', 'provider_number', 'expiration_date' ] as $key ) {
			$this->assertArrayHasKey( $key, $data, "Missing template variable {$key}" );
		}
		$this->assertSame( 'Ada Lovelace', $data['learner_name'] );
		$this->assertSame( 'Laser Safety', $data['course_name'] );
		$this->assertSame( 'Dr Vega', $data['instructor_name'] );
		$this->assertSame( 2.0, $data['ce_credits'] );
	}

	public function test_the_certificate_data_filter_can_override_values() {
		add_filter(
			'anchor_courses_certificate_data',
			static function ( array $data ) {
				$data['learner_name'] = 'Filtered Name';
				return $data;
			},
			10,
			2
		);

		$certificate = $this->certificates->issue( $this->user, $this->course );

		$this->assertSame( 'Filtered Name', $this->certificates->template_data( $certificate )['learner_name'] );
	}

	public function test_render_escapes_the_learner_name() {
		$nasty       = $this->make_learner( [ 'display_name' => '<script>alert(1)</script>' ] );
		$certificate = $this->certificates->issue( $nasty, $this->course );

		$html = $this->certificates->render( $certificate );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( $certificate->certificate_number, $html );
	}

	public function test_for_user_lists_certificates_newest_first() {
		$second = $this->make_course( [ 'certificate_enabled' => 1 ] );
		$this->certificates->issue( $this->user, $this->course );
		$this->certificates->issue( $this->user, $second );

		$this->assertCount( 2, $this->certificates->for_user( $this->user ) );
	}

	public function test_a_credit_is_linked_to_the_certificate_when_both_exist() {
		$credit      = ( new CreditService() )->award( $this->user, $this->course );
		$certificate = $this->certificates->issue( $this->user, $this->course );

		$this->assertSame(
			$certificate->id,
			( new CreditService() )->get( $this->user, $this->course )->certificate_id
		);
		$this->assertGreaterThan( 0, $credit->id );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Certificate_Number
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Certificates
```
Expected: both FAIL - `Class "Anchor\Courses\Services\CertificateService" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Domain/Certificate.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One issued certificate (brief 7.5, 13). Immutable. */
final class Certificate {

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly string $certificate_number,
		public readonly string $issued_at,
		public readonly ?string $expires_at,
		public readonly string $file_path,
		public readonly string $verification_token,
		public readonly array $metadata,
		public readonly string $created_at
	) {}

	public static function from_row( array $row ): self {
		$metadata = \json_decode( (string) ( $row['metadata'] ?? '' ), true );

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['user_id'] ?? 0 ),
			(int) ( $row['course_id'] ?? 0 ),
			(string) ( $row['certificate_number'] ?? '' ),
			(string) ( $row['issued_at'] ?? '' ),
			( isset( $row['expires_at'] ) && '' !== (string) $row['expires_at'] ) ? (string) $row['expires_at'] : null,
			(string) ( $row['file_path'] ?? '' ),
			(string) ( $row['verification_token'] ?? '' ),
			\is_array( $metadata ) ? $metadata : [],
			(string) ( $row['created_at'] ?? '' )
		);
	}

	/** The public HTML certificate / verification URL (Task 30 registers the route). */
	public function url(): string {
		return \home_url( '/certificate/' . \rawurlencode( $this->verification_token ) . '/' );
	}

	public function to_array(): array {
		return [
			'id'                 => $this->id,
			'user_id'            => $this->user_id,
			'course_id'          => $this->course_id,
			'certificate_number' => $this->certificate_number,
			'issued_at'          => $this->issued_at,
			'expires_at'         => $this->expires_at,
			'file_path'          => $this->file_path,
			'verification_token' => $this->verification_token,
			'metadata'           => $this->metadata,
			'created_at'         => $this->created_at,
			'url'                => $this->url(),
		];
	}
}
```

`anchor-courses/src/Database/CertificateRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * All SQL for wp_anchor_courses_certificates.
 *
 * certificate_number is NOT NULL UNIQUE but must embed the AUTO_INCREMENT id,
 * so the insert uses a collision-proof PENDING placeholder and the service
 * rewrites it once the id is known (design spec 4 / deviation D14).
 */
final class CertificateRepository {

	private static function table(): string {
		return Migrations::table( 'certificates' );
	}

	public static function find( int $user_id, int $course_id ): ?Certificate {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d',
				$user_id,
				$course_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? Certificate::from_row( $row ) : null;
	}

	public static function find_by_id( int $id ): ?Certificate {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return \is_array( $row ) ? Certificate::from_row( $row ) : null;
	}

	public static function find_by_token( string $token ): ?Certificate {
		if ( '' === $token ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE verification_token = %s', $token ),
			ARRAY_A
		);
		return \is_array( $row ) ? Certificate::from_row( $row ) : null;
	}

	public static function insert_ignore( array $data ): ?Certificate {
		global $wpdb;

		$values = [
			'user_id'            => (int) ( $data['user_id'] ?? 0 ),
			'course_id'          => (int) ( $data['course_id'] ?? 0 ),
			// Unique by construction; replaced with the real number immediately.
			'certificate_number' => 'PENDING-' . \uniqid( '', true ),
			'issued_at'          => (string) ( $data['issued_at'] ?? Clock::now() ),
			'expires_at'         => $data['expires_at'] ?? null,
			'file_path'          => '',
			'verification_token' => (string) ( $data['verification_token'] ?? '' ),
			'metadata'           => (string) \wp_json_encode( (array) ( $data['metadata'] ?? [] ) ),
			'created_at'         => Clock::now(),
		];

		$columns      = \implode( ', ', \array_keys( $values ) );
		$placeholders = \implode( ', ', \array_fill( 0, \count( $values ), '%s' ) );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . self::table() . " ({$columns}) VALUES ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_values( $values )
			)
		);

		return self::find( $values['user_id'], $values['course_id'] );
	}

	public static function set_number( int $id, string $number ): ?Certificate {
		global $wpdb;
		$wpdb->update( self::table(), [ 'certificate_number' => $number ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
		return self::find_by_id( $id );
	}

	/** @return Certificate[] */
	public static function for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY issued_at DESC', $user_id ),
			ARRAY_A
		);
		return \array_map( [ Certificate::class, 'from_row' ], (array) $rows );
	}
}
```

`anchor-courses/src/Services/CertificateService.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Database\CertificateRepository;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Frontend\Templates;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Log;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Certificates (brief 13).
 *
 * Phase 1 is an HTML page with a print stylesheet and a public verification
 * route; `file_path` is deliberately left empty so that adding PDF generation
 * later needs no schema change (design spec 1).
 */
final class CertificateService {

	public function __construct( private ?CreditService $credits = null ) {
		$this->credits = $credits ?? new CreditService();
	}

	/**
	 * AC-{YYYY}-{8-digit zero-padded id}. Pure.
	 *
	 * The id comes from the table's AUTO_INCREMENT, never a counter option, so
	 * two simultaneous issues cannot collide (design spec 4).
	 */
	public static function format_number( int $id, int $year ): string {
		return \sprintf( 'AC-%04d-%08d', $year, $id );
	}

	/** Issue (or return) the certificate for this learner and course. */
	public function issue( int $user_id, int $course_id ): ?Certificate {
		if ( CoursePostType::CPT !== \get_post_type( $course_id ) || ! \get_userdata( $user_id ) ) {
			return null;
		}
		if ( 1 !== (int) CourseEditor::setting( $course_id, 'certificate_enabled' ) ) {
			return null;
		}

		$existing = CertificateRepository::find( $user_id, $course_id );
		if ( $existing instanceof Certificate ) {
			return $existing;
		}

		$expires_days = (int) CourseEditor::setting( $course_id, 'ce_expires_days' );

		$certificate = CertificateRepository::insert_ignore(
			[
				'user_id'            => $user_id,
				'course_id'          => $course_id,
				'issued_at'          => Clock::now(),
				'expires_at'         => $expires_days > 0 ? Clock::offset( $expires_days * DAY_IN_SECONDS ) : null,
				'verification_token' => \wp_generate_password( 32, false, false ),
				'metadata'           => [ 'template' => (string) CourseEditor::setting( $course_id, 'certificate_template' ) ],
			]
		);

		if ( ! $certificate instanceof Certificate ) {
			return null;
		}

		// Two-step numbering (deviation D14): the id is only known after insert.
		if ( 0 === \strpos( $certificate->certificate_number, 'PENDING-' ) ) {
			$numbered = CertificateRepository::set_number(
				$certificate->id,
				self::format_number( $certificate->id, (int) \gmdate( 'Y', Clock::timestamp() ) )
			);
			if ( $numbered instanceof Certificate ) {
				$certificate = $numbered;
			}
		}

		// Link the CE record to the certificate when both exist (brief 12 step 3).
		$credit = CreditRepository::find( $user_id, $course_id );
		if ( $credit instanceof Credit && 0 === $credit->certificate_id ) {
			CreditRepository::attach_certificate( $credit->id, $certificate->id );
		}

		Log::write( 'certificate_issued', [ 'user' => $user_id, 'course' => $course_id, 'number' => $certificate->certificate_number ] );

		/**
		 * Fires once, when a certificate is issued.
		 *
		 * @param int         $user_id
		 * @param int         $course_id
		 * @param Certificate $certificate
		 */
		\do_action( 'anchor_courses_certificate_issued', $user_id, $course_id, $certificate );

		return $certificate;
	}

	public function get( int $user_id, int $course_id ): ?Certificate {
		return CertificateRepository::find( $user_id, $course_id );
	}

	public function get_by_token( string $token ): ?Certificate {
		return CertificateRepository::find_by_token( $token );
	}

	/** @return Certificate[] */
	public function for_user( int $user_id ): array {
		return CertificateRepository::for_user( $user_id );
	}

	/** The brief 13 template variables. */
	public function template_data( Certificate $certificate ): array {
		$user   = \get_userdata( $certificate->user_id );
		$config = $this->credits->course_credit_config( $certificate->course_id );
		$credit = $this->credits->get( $certificate->user_id, $certificate->course_id );

		$data = [
			'learner_name'       => $user ? (string) $user->display_name : '',
			'course_name'        => (string) \get_the_title( $certificate->course_id ),
			'completion_date'    => $certificate->issued_at,
			'ce_credits'         => $credit instanceof Credit ? $credit->credits : $config['credits'],
			'certificate_number' => $certificate->certificate_number,
			'instructor_name'    => (string) CourseEditor::setting( $certificate->course_id, 'instructor' ),
			'provider_name'      => $config['provider_name'],
			'provider_number'    => $config['provider_number'],
			'expiration_date'    => (string) ( $certificate->expires_at ?? '' ),
			'verification_url'   => $certificate->url(),
		];

		/**
		 * Filter the certificate template variables.
		 *
		 * @param array       $data
		 * @param Certificate $certificate
		 */
		return (array) \apply_filters( 'anchor_courses_certificate_data', $data, $certificate );
	}

	/** Render the HTML certificate. All values escaped in the template. */
	public function render( Certificate $certificate ): string {
		return Templates::render(
			'certificate',
			[ 'certificate' => $certificate, 'data' => $this->template_data( $certificate ) ]
		);
	}
}
```

`anchor-courses/anchor-courses.php`:

```php
	public Services\CertificateService $certificates;
```

```php
		$this->certificates = new Services\CertificateService( $this->credits );
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Courses_Unit_Certificate_Number
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Certificates
```
Expected: PASS (4 unit) and PASS (10 integration). The `render()` test needs `templates/certificate.php`, created in Task 30 - if it fails on a missing template, create the file as part of this task with the Task 30 body.

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Domain/Certificate.php anchor-courses/src/Database/CertificateRepository.php \
        anchor-courses/src/Services/CertificateService.php anchor-courses/anchor-courses.php \
        tests/unit/test-certificate-number.php tests/test-courses-certificates.php
git commit -m "feat(courses): certificates with race-free numbering and verification tokens"
```

---

### Task 29: CompletionService - the once-only completion pipeline

**Files:**
- Create: `anchor-courses/src/Services/CompletionService.php`
- Modify: `anchor-courses/anchor-courses.php` - construct and inject into `ProgressService`
- Test: `tests/test-courses-completion.php`

**Interfaces:**
- Consumes: `EnrollmentService`, `ProgressService`, `CreditService`, `CertificateService`.
- Produces:
  - `Services\CompletionService::is_complete( int $user_id, int $course_id ): bool` - reads the enrolment status, not the items
  - `::evaluate( int $user_id, int $course_id ): bool` - "should this be complete?"
  - `::complete( int $user_id, int $course_id ): bool` - the pipeline; returns `true` only on the transition
  - `::uncomplete( int $user_id, int $course_id ): bool` - admin-only reversal; leaves credits and certificates intact
  - Action: `anchor_courses_course_completed( int $user_id, int $course_id, Enrollment $enrollment )`
- Consumes (from Task 19): `Support\Roles::grant_completed( int $user_id, int $course_id ): bool`

Pipeline (brief 11): enrolment -> `completed` + `completed_at` -> award CE -> issue certificate -> **grant the completion role** -> fire `anchor_courses_course_completed`. The transition is guarded by the enrolment status itself, so running it a hundred times produces one set of side effects (brief 26, rule 7).

The completion role (`anchor_course_{id}_completed`, minted here on first use) is the slug another course or an event lists as a prerequisite. It is **not** the access role, and granting it must never be mistaken for an enrolment - the Task 20 listener matches `anchor_course_{digits}` exactly, so the `_completed` variant slides past it untouched. `uncomplete()` does not take the role away, for the same reason it does not delete credits: it is a record of something that happened.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - idempotent course completion (brief 11, 26, rule 7).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Database\CertificateRepository;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Services\CompletionService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Completion extends Anchor_Courses_TestCase {

	private CompletionService $completion;
	private ProgressService $progress;
	private EnrollmentService $enrollments;
	private int $user;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();

		$this->enrollments = new EnrollmentService();
		$this->progress    = new ProgressService( $this->enrollments );
		$this->completion  = new CompletionService( $this->enrollments, $this->progress );
		$this->progress->set_completion_service( $this->completion );

		$this->user   = $this->make_learner( [ 'display_name' => 'Ada' ] );
		$this->course = $this->make_course(
			[ 'progression_mode' => 'free', 'ce_credits' => '2', 'certificate_enabled' => 1 ],
			'Laser Safety'
		);
		$this->lesson = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
		$this->enrollments->enroll( $this->user, $this->course );
	}

	public function tear_down() {
		remove_all_actions( 'anchor_courses_course_completed' );
		remove_all_actions( 'anchor_courses_ce_credit_awarded' );
		remove_all_actions( 'anchor_courses_certificate_issued' );
		parent::tear_down();
	}

	public function test_completing_the_last_item_runs_the_whole_pipeline_once() {
		$events = [];
		add_action( 'anchor_courses_course_completed', function () use ( &$events ) { $events[] = 'course'; }, 10, 3 );
		add_action( 'anchor_courses_ce_credit_awarded', function () use ( &$events ) { $events[] = 'credit'; }, 10, 3 );
		add_action( 'anchor_courses_certificate_issued', function () use ( &$events ) { $events[] = 'certificate'; }, 10, 3 );

		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$this->assertSame( [ 'credit', 'certificate', 'course' ], $events, 'Credits, then certificate, then the completion event.' );
		$this->assertTrue( $this->completion->is_complete( $this->user, $this->course ) );
		$this->assertSame( 'completed', $this->enrollments->get( $this->user, $this->course )->status );
		$this->assertNotNull( $this->enrollments->get( $this->user, $this->course )->completed_at );
	}

	/** Brief 26: the headline requirement. */
	public function test_running_completion_repeatedly_changes_nothing() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$count = 0;
		add_action( 'anchor_courses_course_completed', function () use ( &$count ) { $count++; }, 10, 3 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( $this->completion->complete( $this->user, $this->course ) );
		}

		$this->assertSame( 0, $count );
		$this->assertCount( 1, CreditRepository::for_user( $this->user ) );
		$this->assertCount( 1, CertificateRepository::for_user( $this->user ) );
		$this->assertSame( 2.0, CreditRepository::total_for_user( $this->user ) );
	}

	public function test_two_simultaneous_lesson_completions_still_award_once() {
		// Simulates the double-submit: two calls before either has finished.
		$a = $this->progress->complete_lesson( $this->user, $this->course, $this->lesson );
		$b = $this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$this->assertSame( $a->id, $b->id );
		$this->assertCount( 1, CreditRepository::for_user( $this->user ) );
		$this->assertCount( 1, CertificateRepository::for_user( $this->user ) );
	}

	public function test_completion_does_not_run_before_the_items_are_done() {
		$second = $this->make_lesson();
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->lesson ],
				[ 'type' => 'lesson', 'id' => $second ],
			] ] ]
		);

		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$this->assertFalse( $this->completion->is_complete( $this->user, $this->course ) );
		$this->assertCount( 0, CreditRepository::for_user( $this->user ) );
	}

	public function test_evaluate_reports_readiness_without_side_effects() {
		$this->assertFalse( $this->completion->evaluate( $this->user, $this->course ) );

		$this->progress->record_item( $this->user, $this->course, $this->lesson, 'lesson', 'completed' );

		$this->assertTrue( $this->completion->evaluate( $this->user, $this->course ) );
		$this->assertCount( 0, CreditRepository::for_user( $this->user ), 'evaluate() must not award anything.' );
	}

	public function test_an_unenrolled_user_cannot_be_completed() {
		$stranger = $this->make_learner();
		$this->assertFalse( $this->completion->complete( $stranger, $this->course ) );
	}

	public function test_a_course_with_no_certificate_still_completes_and_awards_credits() {
		$no_cert = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '1', 'certificate_enabled' => 0 ] );
		$lesson  = $this->make_lesson();
		Curriculum::save( $no_cert, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		$this->enrollments->enroll( $this->user, $no_cert );

		$this->progress->complete_lesson( $this->user, $no_cert, $lesson );

		$this->assertTrue( $this->completion->is_complete( $this->user, $no_cert ) );
		$this->assertNotNull( CreditRepository::find( $this->user, $no_cert ) );
		$this->assertNull( CertificateRepository::find( $this->user, $no_cert ) );
	}

	public function test_uncomplete_reverts_the_enrolment_but_keeps_what_was_earned() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$this->assertTrue( $this->completion->uncomplete( $this->user, $this->course ) );

		$this->assertFalse( $this->completion->is_complete( $this->user, $this->course ) );
		$this->assertNotNull( CreditRepository::find( $this->user, $this->course ), 'Earned credits are never revoked.' );
		$this->assertNotNull( CertificateRepository::find( $this->user, $this->course ) );
	}

	/**
	 * Completion mints and grants the COMPLETION role - and leaves the access
	 * role and the enrolment exactly where they were.
	 */
	public function test_completion_grants_the_completion_role_only() {
		$enrolled = $this->enrollments->get( $this->user, $this->course );
		$this->assertNotNull( $enrolled );

		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$completion_slug = Roles::completion_slug( $this->course );
		$this->assertTrue( Roles::user_has( $this->user, $completion_slug ) );
		$this->assertSame( 'Completed: ' . get_the_title( $this->course ), wp_roles()->roles[ $completion_slug ]['name'] );
		$this->assertSame( [], get_role( $completion_slug )->capabilities, 'A completion role is a tag, not a permission.' );

		$this->assertSame(
			$enrolled->id,
			$this->enrollments->get( $this->user, $this->course )->id,
			'Granting the completion role must not create a second enrolment.'
		);

		remove_role( $completion_slug );
	}

	/** Undoing a completion is not a retraction of what was earned. */
	public function test_uncomplete_keeps_the_completion_role() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->lesson );
		$this->completion->uncomplete( $this->user, $this->course );

		$this->assertTrue( Roles::user_has( $this->user, Roles::completion_slug( $this->course ) ) );

		remove_role( Roles::completion_slug( $this->course ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Completion
```
Expected: FAIL - `Class "Anchor\Courses\Services\CompletionService" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Services/CompletionService.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Domain\Enrollment;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Log;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Course completion (brief 11).
 *
 * The enrolment row's status IS the idempotency guard: the pipeline only runs
 * on the transition from not-completed to completed, so calling complete() a
 * hundred times produces one credit, one certificate and one event (brief 26,
 * rules 7 and 9).
 */
final class CompletionService {

	public function __construct(
		private EnrollmentService $enrollments,
		private ProgressService $progress,
		private ?CreditService $credits = null,
		private ?CertificateService $certificates = null
	) {
		$this->credits      = $credits ?? new CreditService();
		$this->certificates = $certificates ?? new CertificateService( $this->credits );
	}

	/** Has this course already been recorded as complete? */
	public function is_complete( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		return $enrollment instanceof Enrollment && $enrollment->is_complete();
	}

	/** Should it be? Pure read - no side effects. */
	public function evaluate( int $user_id, int $course_id ): bool {
		return $this->progress->get_course_progress( $user_id, $course_id )->complete;
	}

	/**
	 * Run the completion pipeline.
	 *
	 * @return bool True only when THIS call performed the transition.
	 */
	public function complete( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment ) {
			return false;
		}
		if ( $enrollment->is_complete() ) {
			return false; // Already done: no duplicate credits, certificates or events.
		}
		if ( ! $this->evaluate( $user_id, $course_id ) ) {
			return false;
		}

		$updated = EnrollmentRepository::update(
			$enrollment->id,
			[ 'status' => 'completed', 'completed_at' => Clock::now() ]
		);
		if ( ! $updated instanceof Enrollment ) {
			return false;
		}

		// Order matters: the certificate links to the credit record (brief 12).
		$this->credits->award( $user_id, $course_id );
		$this->certificates->issue( $user_id, $course_id );

		// Mint (lazily) and grant the COMPLETION role. This is the slug another
		// course or an event lists as a prerequisite (design spec 3.1). It is a
		// different role from the access role the learner already holds, and
		// granting it never touches their enrolment - Support\Roles' listener
		// matches the access slug only.
		Roles::grant_completed( $user_id, $course_id );

		Log::write( 'course_completed', [ 'user' => $user_id, 'course' => $course_id ] );

		/**
		 * Fires once, when a learner completes a course.
		 *
		 * @param int        $user_id
		 * @param int        $course_id
		 * @param Enrollment $updated
		 */
		\do_action( 'anchor_courses_course_completed', $user_id, $course_id, $updated );

		return true;
	}

	/**
	 * Reverse the completion flag (admin action).
	 *
	 * Credits and certificates are NOT revoked: they are a record of something
	 * that happened, and quietly deleting them would rewrite history.
	 */
	public function uncomplete( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment || ! $enrollment->is_complete() ) {
			return false;
		}

		EnrollmentRepository::update( $enrollment->id, [ 'status' => 'in_progress', 'completed_at' => null ] );

		return true;
	}
}
```

`anchor-courses/anchor-courses.php` - construct after `$this->certificates` and inject:

```php
	public Services\CompletionService $completion;
```

```php
		$this->completion = new Services\CompletionService(
			$this->enrollments,
			$this->progress,
			$this->credits,
			$this->certificates
		);
		$this->progress->set_completion_service( $this->completion );
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Completion
```
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Services/CompletionService.php anchor-courses/anchor-courses.php tests/test-courses-completion.php
git commit -m "feat(courses): idempotent course completion pipeline"
```

---

### Task 30: Certificate page, verification route and learner credit/certificate screens

**Files:**
- Create: `anchor-courses/src/Frontend/CertificatePage.php`
- Create: `anchor-courses/templates/certificate.php`, `anchor-courses/assets/certificate.css`
- Modify: `anchor-courses/src/Frontend/Shortcodes.php` - real `my_credits()` and `my_certificates()`
- Modify: `anchor-courses/anchor-courses.php` - construct `Frontend\CertificatePage`
- Test: `tests/test-courses-certificate-page.php`

**Interfaces:**
- Produces:
  - `Frontend\CertificatePage::QUERY_VAR = 'anchor_certificate'`
  - `::add_rewrite(): void` - `add_rewrite_rule( '^certificate/([^/]+)/?$', 'index.php?anchor_certificate=$matches[1]', 'top' )` on `init`
  - `::register_query_var( array $vars ): array`
  - `::maybe_render(): void` - on `template_redirect`; 404s an unknown token, sends `X-Robots-Tag: noindex, nofollow` and `Cache-Control: private, no-store`, prints the certificate, `exit`
  - `::flush_if_needed(): void` - version-stamped flush (`anchor_courses_rewrite_version` option), the repo's self-healing pattern since modules have no activation hook (D1)
  - `Frontend\Shortcodes::my_credits(): string`, `::my_certificates(): string`

The page is **public by token** (that is what "verify a certificate" means) but carries no PII beyond what a certificate shows, is noindexed, and is never cached.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - the HTML certificate page and verification route
 * (brief 13, design spec 1).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Frontend\CertificatePage;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CreditService;

/** @group courses */
class Test_Courses_Certificate_Page extends Anchor_Courses_TestCase {

	private CertificateService $certificates;
	private int $user;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->certificates = new CertificateService();
		$this->user         = $this->make_learner( [ 'display_name' => 'Ada Lovelace' ] );
		$this->course       = $this->make_course(
			[ 'certificate_enabled' => 1, 'ce_credits' => '2', 'ce_provider_name' => 'DEKA Academy' ],
			'Laser Safety'
		);
	}

	public function test_the_rewrite_rule_is_registered() {
		( new CertificatePage( $this->certificates ) )->add_rewrite();
		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $GLOBALS['wp_rewrite']->extra_rules_top );
		$this->assertArrayHasKey( '^certificate/([^/]+)/?$', $GLOBALS['wp_rewrite']->extra_rules_top );
	}

	public function test_the_query_var_is_registered() {
		$vars = ( new CertificatePage( $this->certificates ) )->register_query_var( [] );
		$this->assertContains( CertificatePage::QUERY_VAR, $vars );
	}

	public function test_the_certificate_html_carries_every_template_variable() {
		( new CreditService() )->award( $this->user, $this->course );
		$certificate = $this->certificates->issue( $this->user, $this->course );

		$html = $this->certificates->render( $certificate );

		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'Laser Safety', $html );
		$this->assertStringContainsString( 'DEKA Academy', $html );
		$this->assertStringContainsString( $certificate->certificate_number, $html );
	}

	public function test_the_certificate_url_uses_the_verification_token() {
		$certificate = $this->certificates->issue( $this->user, $this->course );
		$this->assertStringContainsString( '/certificate/' . $certificate->verification_token . '/', $certificate->url() );
	}

	public function test_an_unknown_token_resolves_to_nothing() {
		$this->assertNull( $this->certificates->get_by_token( 'nope' ) );
	}

	public function test_my_certificates_lists_the_learners_certificates() {
		$certificate = $this->certificates->issue( $this->user, $this->course );
		wp_set_current_user( $this->user );

		$html = do_shortcode( '[anchor_my_certificates]' );

		$this->assertStringContainsString( $certificate->certificate_number, $html );
		$this->assertStringContainsString( 'Laser Safety', $html );
	}

	public function test_my_certificates_never_shows_another_learners_records() {
		$this->certificates->issue( $this->user, $this->course );
		wp_set_current_user( $this->make_learner() );

		$html = do_shortcode( '[anchor_my_certificates]' );

		$this->assertStringNotContainsString( 'Laser Safety', $html );
	}

	public function test_my_credits_lists_totals_for_the_signed_in_learner() {
		( new CreditService() )->award( $this->user, $this->course );
		wp_set_current_user( $this->user );

		$html = do_shortcode( '[anchor_my_credits]' );

		$this->assertStringContainsString( 'Laser Safety', $html );
		$this->assertStringContainsString( '2', $html );
	}

	public function test_both_learner_shortcodes_require_login() {
		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'sign in', strtolower( do_shortcode( '[anchor_my_credits]' ) ) );
		$this->assertStringContainsString( 'sign in', strtolower( do_shortcode( '[anchor_my_certificates]' ) ) );
	}

	public function test_the_certificate_page_escapes_a_hostile_course_title() {
		$nasty       = $this->make_course( [ 'certificate_enabled' => 1 ], '<script>alert(1)</script>' );
		$certificate = $this->certificates->issue( $this->user, $nasty );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $this->certificates->render( $certificate ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Certificate_Page
```
Expected: FAIL - `Class "Anchor\Courses\Frontend\CertificatePage" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Frontend/CertificatePage.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Module;
use Anchor\Courses\Services\CertificateService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * /certificate/{token}/ - the HTML certificate and its public verification
 * (brief 13, design spec 1).
 *
 * Public by token on purpose: "verify this certificate" has to work for a state
 * board with nothing but the number in front of them. The page is noindexed and
 * never cached, and shows only what a printed certificate shows.
 */
final class CertificatePage {

	public const QUERY_VAR       = 'anchor_certificate';
	public const REWRITE_OPTION  = 'anchor_courses_rewrite_version';
	public const REWRITE_VERSION = '1';

	public function __construct( private CertificateService $certificates ) {
		\add_action( 'init', [ $this, 'add_rewrite' ] );
		\add_action( 'init', [ $this, 'flush_if_needed' ], 20 );
		\add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		\add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	public function add_rewrite(): void {
		\add_rewrite_rule(
			'^certificate/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Self-healing flush.
	 *
	 * Anchor Tools modules have no activation hook (see Migrations), so the rule
	 * is registered on every load and flushed once per version bump - the same
	 * pattern anchor-locations and anchor-translate use.
	 */
	public function flush_if_needed(): void {
		if ( \get_option( self::REWRITE_OPTION ) === self::REWRITE_VERSION ) {
			return;
		}
		\flush_rewrite_rules( false );
		\update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
	}

	public function maybe_render(): void {
		$token = (string) \get_query_var( self::QUERY_VAR );
		if ( '' === $token ) {
			return;
		}

		$certificate = $this->certificates->get_by_token( $token );
		if ( ! $certificate instanceof Certificate ) {
			global $wp_query;
			$wp_query->set_404();
			\status_header( 404 );
			\nocache_headers();
			return;
		}

		\nocache_headers();
		\header( 'Cache-Control: private, no-store' );
		\header( 'X-Robots-Tag: noindex, nofollow', true );

		echo $this->certificates->render( $certificate ); // phpcs:ignore WordPress.Security.EscapeOutput -- the template escapes every value.
		exit;
	}
}
```

`anchor-courses/templates/certificate.php`:

```php
<?php
/**
 * The HTML certificate (brief 13). A complete document: this is served
 * standalone at /certificate/{token}/ and printed from the browser.
 *
 * Variables: $certificate (Certificate), $data (template_data()).
 *
 * Theme override: anchor-courses/certificate.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Module;

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( $data['certificate_number'] . ' - ' . $data['course_name'] ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( Module::assets_url() . 'certificate.css' ); ?>" />
</head>
<body class="anchor-certificate-page">
	<main class="anchor-certificate">
		<header class="anchor-certificate-header">
			<p class="anchor-certificate-eyebrow"><?php esc_html_e( 'Certificate of Completion', 'anchor-schema' ); ?></p>
			<p class="anchor-certificate-site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
		</header>

		<p class="anchor-certificate-presented"><?php esc_html_e( 'This certifies that', 'anchor-schema' ); ?></p>
		<p class="anchor-certificate-learner"><?php echo esc_html( $data['learner_name'] ); ?></p>
		<p class="anchor-certificate-presented"><?php esc_html_e( 'has completed', 'anchor-schema' ); ?></p>
		<p class="anchor-certificate-course"><?php echo esc_html( $data['course_name'] ); ?></p>

		<dl class="anchor-certificate-details">
			<dt><?php esc_html_e( 'Completed', 'anchor-schema' ); ?></dt>
			<dd><?php echo esc_html( mysql2date( (string) get_option( 'date_format' ), $data['completion_date'] ) ); ?></dd>

			<?php if ( (float) $data['ce_credits'] > 0 ) : ?>
				<dt><?php esc_html_e( 'CE credits', 'anchor-schema' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( (float) $data['ce_credits'], 1 ) ); ?></dd>
			<?php endif; ?>

			<?php if ( '' !== $data['instructor_name'] ) : ?>
				<dt><?php esc_html_e( 'Instructor', 'anchor-schema' ); ?></dt>
				<dd><?php echo esc_html( $data['instructor_name'] ); ?></dd>
			<?php endif; ?>

			<?php if ( '' !== $data['provider_name'] ) : ?>
				<dt><?php esc_html_e( 'Provider', 'anchor-schema' ); ?></dt>
				<dd>
					<?php echo esc_html( $data['provider_name'] ); ?>
					<?php if ( '' !== $data['provider_number'] ) : ?>
						<span class="anchor-certificate-provider-number"><?php echo esc_html( $data['provider_number'] ); ?></span>
					<?php endif; ?>
				</dd>
			<?php endif; ?>

			<?php if ( '' !== $data['expiration_date'] ) : ?>
				<dt><?php esc_html_e( 'Credits expire', 'anchor-schema' ); ?></dt>
				<dd><?php echo esc_html( mysql2date( (string) get_option( 'date_format' ), $data['expiration_date'] ) ); ?></dd>
			<?php endif; ?>

			<dt><?php esc_html_e( 'Certificate number', 'anchor-schema' ); ?></dt>
			<dd class="anchor-certificate-number"><?php echo esc_html( $data['certificate_number'] ); ?></dd>
		</dl>

		<footer class="anchor-certificate-footer">
			<p class="anchor-certificate-verify">
				<?php esc_html_e( 'Verify this certificate at', 'anchor-schema' ); ?>
				<span><?php echo esc_html( $data['verification_url'] ); ?></span>
			</p>
			<p class="anchor-certificate-print no-print">
				<button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'anchor-schema' ); ?></button>
			</p>
		</footer>
	</main>
</body>
</html>
```

`anchor-courses/assets/certificate.css`:

```css
/* Anchor Courses - certificate page and print stylesheet. */
.anchor-certificate-page { margin: 0; padding: 2rem 1rem; background: #f4f4f5; font-family: Georgia, "Times New Roman", serif; color: #1a1a1a; }
.anchor-certificate { max-width: 60rem; margin: 0 auto; padding: 3.5rem 3rem; background: #fff; border: 2px solid #1a1a1a; text-align: center; }
.anchor-certificate-eyebrow { margin: 0; font-size: .8rem; letter-spacing: .3em; text-transform: uppercase; }
.anchor-certificate-site { margin: .25rem 0 2.5rem; font-size: 1rem; letter-spacing: .1em; text-transform: uppercase; }
.anchor-certificate-presented { margin: 1.25rem 0 .25rem; font-size: .95rem; font-style: italic; }
.anchor-certificate-learner { margin: 0; font-size: 2.5rem; line-height: 1.2; }
.anchor-certificate-course { margin: 0 0 2rem; font-size: 1.6rem; line-height: 1.3; }
.anchor-certificate-details { display: grid; grid-template-columns: auto auto; gap: .35rem 1.5rem; justify-content: center; margin: 0 0 2.5rem; text-align: left; font-family: system-ui, -apple-system, sans-serif; font-size: .9rem; }
.anchor-certificate-details dt { font-weight: 600; }
.anchor-certificate-details dd { margin: 0; }
.anchor-certificate-number { font-variant-numeric: tabular-nums; letter-spacing: .05em; }
.anchor-certificate-provider-number { opacity: .7; }
.anchor-certificate-footer { border-top: 1px solid #d4d4d8; padding-top: 1.25rem; font-family: system-ui, -apple-system, sans-serif; font-size: .8rem; }
.anchor-certificate-verify span { display: block; word-break: break-all; opacity: .8; }

@media print {
	.anchor-certificate-page { background: #fff; padding: 0; }
	.anchor-certificate { border-width: 1px; padding: 2rem; }
	.no-print { display: none !important; }
	@page { size: landscape; margin: 1cm; }
}
```

Replace the two placeholder methods in `Frontend\Shortcodes` (and inject the services):

```php
	public function __construct(
		private ProgressService $progress,
		private EnrollmentService $enrollments,
		private CreditService $credits,
		private CertificateService $certificates
	) {
		// ...existing add_shortcode() calls unchanged...
	}

	public function my_credits(): string {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your CE credits.', 'anchor-schema' ) . '</p>';
		}

		$credits = $this->credits->for_user( $user_id );
		if ( [] === $credits ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'No CE credits yet.', 'anchor-schema' ) . '</p>';
		}

		\ob_start();
		echo '<table class="anchor-courses-credits-table"><thead><tr>';
		\printf(
			'<th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>',
			\esc_html__( 'Course', 'anchor-schema' ),
			\esc_html__( 'Credits', 'anchor-schema' ),
			\esc_html__( 'Type', 'anchor-schema' ),
			\esc_html__( 'Awarded', 'anchor-schema' )
		);
		foreach ( $credits as $credit ) {
			\printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				\esc_html( (string) \get_the_title( $credit->course_id ) ),
				\esc_html( \number_format_i18n( $credit->credits, 1 ) ),
				\esc_html( $credit->credit_type ),
				\esc_html( \mysql2date( (string) \get_option( 'date_format' ), $credit->awarded_at ) )
			);
		}
		\printf(
			'</tbody><tfoot><tr><th>%s</th><th colspan="3">%s</th></tr></tfoot></table>',
			\esc_html__( 'Total', 'anchor-schema' ),
			\esc_html( \number_format_i18n( $this->credits->total_for_user( $user_id ), 1 ) )
		);
		return (string) \ob_get_clean();
	}

	public function my_certificates(): string {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your certificates.', 'anchor-schema' ) . '</p>';
		}

		$certificates = $this->certificates->for_user( $user_id );
		if ( [] === $certificates ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'No certificates yet.', 'anchor-schema' ) . '</p>';
		}

		\ob_start();
		echo '<ul class="anchor-courses-certificates-list">';
		foreach ( $certificates as $certificate ) {
			\printf(
				'<li><a href="%s">%s</a> <span class="anchor-certificate-number">%s</span> <span>%s</span></li>',
				\esc_url( $certificate->url() ),
				\esc_html( (string) \get_the_title( $certificate->course_id ) ),
				\esc_html( $certificate->certificate_number ),
				\esc_html( \mysql2date( (string) \get_option( 'date_format' ), $certificate->issued_at ) )
			);
		}
		echo '</ul>';
		return (string) \ob_get_clean();
	}
```

`anchor-courses/anchor-courses.php` - update the Shortcodes construction and add the page:

```php
		$this->shortcodes = new Frontend\Shortcodes( $this->progress, $this->enrollments, $this->credits, $this->certificates );
		new Frontend\CertificatePage( $this->certificates );
```

`uninstall.php` - add inside the courses branch:

```php
	delete_option( 'anchor_courses_rewrite_version' );
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Certificate_Page
vendor/bin/phpunit --filter Test_Courses_Certificates
```
Expected: PASS (10 tests) and PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Frontend/CertificatePage.php anchor-courses/src/Frontend/Shortcodes.php \
        anchor-courses/templates/certificate.php anchor-courses/assets/certificate.css \
        anchor-courses/anchor-courses.php uninstall.php tests/test-courses-certificate-page.php
git commit -m "feat(courses): HTML certificate page, verification route and learner credit screens"
```

---

### Task 31: Learner report columns, the user-screen tab and enrolment management

**Files:**
- Modify: `anchor-courses/src/Admin/LearnerReports.php` - the Learners tab from Task 21 gains the Phase 4 columns and the user-profile surface
- Create: `anchor-courses/src/Admin/EnrollmentManager.php`
- Modify: `anchor-courses/anchor-courses.php` - construct `EnrollmentManager` when `is_admin()`
- Test: `tests/test-courses-admin-reports.php`

**This task extends the Learners tab, it does not build a second one.** Task 21 created `Admin\LearnerReports` with the columns Phase 2 could fill - learner, email, enrolled, progress, status, completed - plus the "Add learner" form and per-row Revoke. Phase 4 finally has credits, certificates and quiz attempts to report, so the *same* class grows three columns and a user-profile block. Step 3 restates the finished class in full, because an executor may read this task without Task 21 in front of them.

**Interfaces:**
- Consumes: `EnrollmentRepository`, `ProgressService`, `QuizAttemptRepository`, `CreditService`, `CertificateService`, `Capabilities`, `Support\Roles`.
- Produces:
  - `Admin\LearnerReports::rows( int $course_id, int $limit = 50, int $offset = 0 ): array` - now `['user_id','display_name','user_email','enrolled_at','percent','last_activity','best_score','status','completed_at','credits','certificate_number','certificate_url','has_access']`
  - `::render_user_profile( \WP_User $user ): void` - on `show_user_profile` / `edit_user_profile`
  - `::user_rows( int $user_id ): array`
  - `Admin\EnrollmentManager::NONCE = 'anchor_courses_enrollment_nonce'`
  - `::handle_action(): void` - `admin_post_anchor_courses_manage_enrollment`; actions `cancel`, `reset`, `complete`, `uncomplete`
  - `::render_form( int $course_id ): void`

**There is no `enroll` action here.** Adding a learner is the Learners tab's add form (Task 21), which goes through `Support\Roles::grant_access()` like every other grant. A second "Enrol" control on the same screen would be a second door into the same room, with its own chance to forget the prerequisite check. `cancel` does revoke the access role as well as cancelling the row - and deliberately does *not* consult `anchor_courses_role_loss_policy`, because an operator choosing "Cancel enrolment" has already said what they want.

Every screen and handler is gated on `Capabilities::cap( 'reports' )` (read) or `Capabilities::cap( 'enrollments' )` (write). The learner report shows learner emails, so it never renders for a user without the reports capability.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - admin reporting and enrolment management (brief 21.3, 24).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\EnrollmentManager;
use Anchor\Courses\Admin\LearnerReports;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** Thrown from wp_redirect so the handler's exit() never runs. */
class Anchor_Courses_Admin_Redirected extends \Exception {}

/** @group courses */
class Test_Courses_Admin_Reports extends Anchor_Courses_TestCase {

	private int $admin;
	private int $learner;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		$this->admin   = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$this->learner = $this->make_learner( [ 'display_name' => 'Ada Lovelace' ] );
		$this->course  = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '2', 'certificate_enabled' => 1 ], 'Laser Safety' );
		$this->lesson  = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );

		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Courses_Admin_Redirected( (string) $location );
	}

	public function test_rows_report_one_line_per_learner_with_the_brief_columns() {
		( new EnrollmentService() )->enroll( $this->learner, $this->course );

		$rows = LearnerReports::rows( $this->course );

		$this->assertCount( 1, $rows );
		foreach ( [ 'user_id', 'display_name', 'user_email', 'enrolled_at', 'percent', 'last_activity',
		            'best_score', 'status', 'completed_at', 'credits', 'certificate_number' ] as $column ) {
			$this->assertArrayHasKey( $column, $rows[0], "Missing report column {$column}" );
		}
		$this->assertSame( 'Ada Lovelace', $rows[0]['display_name'] );
		$this->assertSame( 0.0, $rows[0]['percent'] );
	}

	public function test_rows_reflect_completion_credits_and_certificate() {
		$enrollments = new EnrollmentService();
		$progress    = new ProgressService( $enrollments );
		$module      = $this->courses();
		$progress->set_completion_service( $module->completion );

		$enrollments->enroll( $this->learner, $this->course );
		$progress->complete_lesson( $this->learner, $this->course, $this->lesson );

		$row = LearnerReports::rows( $this->course )[0];

		$this->assertSame( 100.0, $row['percent'] );
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( 2.0, $row['credits'] );
		$this->assertMatchesRegularExpression( '/^AC-\d{4}-\d{8}$/', $row['certificate_number'] );
	}

	public function test_user_rows_list_every_course_for_one_learner() {
		$second = $this->make_course( [], 'Second' );
		( new EnrollmentService() )->enroll( $this->learner, $this->course );
		( new EnrollmentService() )->enroll( $this->learner, $second );

		$rows = LearnerReports::user_rows( $this->learner );

		$this->assertCount( 2, $rows );
		$this->assertArrayHasKey( 'attempts', $rows[0] );
	}

	public function test_the_learner_metabox_renders_only_with_the_reports_capability() {
		( new EnrollmentService() )->enroll( $this->learner, $this->course );

		wp_set_current_user( $this->learner );
		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$denied = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'Ada Lovelace', $denied );

		wp_set_current_user( $this->admin );
		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$allowed = (string) ob_get_clean();
		$this->assertStringContainsString( 'Ada Lovelace', $allowed );
	}

	/** Adding a learner belongs to the Learners tab; this screen never duplicates it. */
	public function test_the_enrolment_manager_offers_no_enrol_action() {
		$this->assertNotContains( 'enroll', EnrollmentManager::ACTIONS );

		wp_set_current_user( $this->admin );
		$_POST = [
			'anchor_courses_action' => 'enroll',
			'course_id'             => (string) $this->course,
			'user_id'               => (string) $this->learner,
			'_wpnonce'              => wp_create_nonce( EnrollmentManager::NONCE . '_' . $this->course ),
		];

		try {
			( new EnrollmentManager() )->handle_action();
		} catch ( Anchor_Courses_Admin_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_admin_notice=error', $e->getMessage() );
		}

		$this->assertFalse( ( new EnrollmentService() )->is_enrolled( $this->learner, $this->course ) );
	}

	public function test_a_learner_cannot_drive_the_enrolment_manager() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		wp_set_current_user( $this->learner );
		$_POST = [
			'anchor_courses_action' => 'cancel',
			'course_id'             => (string) $this->course,
			'user_id'               => (string) $this->learner,
			'_wpnonce'              => wp_create_nonce( EnrollmentManager::NONCE . '_' . $this->course ),
		];

		try {
			( new EnrollmentManager() )->handle_action();
		} catch ( Anchor_Courses_Admin_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_admin_notice=forbidden', $e->getMessage() );
		}

		$this->assertTrue( ( new EnrollmentService() )->is_enrolled( $this->learner, $this->course ) );
		remove_role( Roles::access_slug( $this->course ) );
	}

	public function test_a_bad_nonce_is_refused() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		wp_set_current_user( $this->admin );
		$_POST = [
			'anchor_courses_action' => 'cancel',
			'course_id'             => (string) $this->course,
			'user_id'               => (string) $this->learner,
			'_wpnonce'              => 'nope',
		];

		try {
			( new EnrollmentManager() )->handle_action();
		} catch ( Anchor_Courses_Admin_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_admin_notice=bad_nonce', $e->getMessage() );
		}

		$this->assertTrue( ( new EnrollmentService() )->is_enrolled( $this->learner, $this->course ) );
		remove_role( Roles::access_slug( $this->course ) );
	}

	public function test_reset_clears_progress_but_keeps_the_enrolment() {
		$enrollments = new EnrollmentService();
		$progress    = new ProgressService( $enrollments );
		$enrollments->enroll( $this->learner, $this->course );
		$progress->complete_lesson( $this->learner, $this->course, $this->lesson );

		wp_set_current_user( $this->admin );
		$_POST = [
			'anchor_courses_action' => 'reset',
			'course_id'             => (string) $this->course,
			'user_id'               => (string) $this->learner,
			'_wpnonce'              => wp_create_nonce( EnrollmentManager::NONCE . '_' . $this->course ),
		];

		try {
			( new EnrollmentManager() )->handle_action();
		} catch ( Anchor_Courses_Admin_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_admin_notice=reset', $e->getMessage() );
		}

		$this->assertSame( 0.0, $progress->get_course_progress( $this->learner, $this->course )->percent );
		$this->assertTrue( $enrollments->is_enrolled( $this->learner, $this->course ) );
	}

	/** Cancel takes the access role away too, whatever the loss policy says. */
	public function test_cancel_revokes_the_role_and_cancels_the_row() {
		Roles::grant_access( $this->learner, $this->course, 'manual' );
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'keep', 10, 4 );

		wp_set_current_user( $this->admin );
		$_POST = [
			'anchor_courses_action' => 'cancel',
			'course_id'             => (string) $this->course,
			'user_id'               => (string) $this->learner,
			'_wpnonce'              => wp_create_nonce( EnrollmentManager::NONCE . '_' . $this->course ),
		];

		try {
			( new EnrollmentManager() )->handle_action();
		} catch ( Anchor_Courses_Admin_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_admin_notice=cancelled', $e->getMessage() );
		}

		$this->assertSame( 'cancelled', ( new EnrollmentService() )->get( $this->learner, $this->course )->status );
		$this->assertFalse( Roles::user_has( $this->learner, Roles::access_slug( $this->course ) ) );

		remove_all_filters( 'anchor_courses_role_loss_policy' );
		remove_role( Roles::access_slug( $this->course ) );
	}

	public function test_learner_emails_are_escaped_in_the_report() {
		$nasty = $this->make_learner( [ 'display_name' => '<script>alert(1)</script>' ] );
		( new EnrollmentService() )->enroll( $nasty, $this->course );
		wp_set_current_user( $this->admin );

		ob_start();
		( new LearnerReports() )->render_learners( get_post( $this->course ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Admin_Reports
```
Expected: FAIL - `Class "Anchor\Courses\Admin\LearnerReports" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Admin/LearnerReports.php`. Only three members change: `rows()` gains `best_score`, `credits`, `certificate_number` and `certificate_url`, `render_learners()` gains their columns, and `user_rows()` / `render_user_profile()` are new. **`handle_add_learner()`, `handle_revoke()`, `render_add_learner_form()`, `revoke_button()`, `authorise()` and `redirect()` are Task 21's and stay exactly as they are** - they are not repeated below, and deleting them would take the Learners tab's add and revoke controls with them, which is what Step 4's second test run is there to catch. `__construct()` and `add_metabox()` are shown unchanged so the top of the file reads whole:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Database\ProgressRepository;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CreditService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Capabilities;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The Learners tab, and learner reporting (brief 21.3, design spec 3.1).
 *
 * Two surfaces: the "Learners" metabox on the course screen, and a Courses
 * block on the user profile. Both show learner email addresses, so both are
 * gated on the reports capability - not on edit_posts.
 */
final class LearnerReports {

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
		\add_action( 'show_user_profile', [ $this, 'render_user_profile' ] );
		\add_action( 'edit_user_profile', [ $this, 'render_user_profile' ] );
		\add_action( 'admin_post_anchor_courses_add_learner', [ $this, 'handle_add_learner' ] );
		\add_action( 'admin_post_anchor_courses_revoke_access', [ $this, 'handle_revoke' ] );
	}

	public function add_metabox(): void {
		\add_meta_box(
			'anchor_courses_learners',
			\__( 'Learners', 'anchor-schema' ),
			[ $this, 'render_learners' ],
			CoursePostType::CPT,
			'normal',
			'low'
		);
	}

	/**
	 * One row per enrolled learner (brief 21.3 columns).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( int $course_id, int $limit = 50, int $offset = 0 ): array {
		$progress_service    = new ProgressService();
		$credit_service      = new CreditService();
		$certificate_service = new CertificateService( $credit_service );

		$rows = [];

		foreach ( EnrollmentRepository::for_course( $course_id, [], $limit, $offset ) as $enrollment ) {
			$user        = \get_userdata( $enrollment->user_id );
			$progress    = $progress_service->get_course_progress( $enrollment->user_id, $course_id );
			$credit      = $credit_service->get( $enrollment->user_id, $course_id );
			$certificate = $certificate_service->get( $enrollment->user_id, $course_id );

			$best_score = null;
			foreach ( QuizAttemptRepository::for_user_course( $enrollment->user_id, $course_id ) as $attempt ) {
				if ( null !== $attempt->score && ( null === $best_score || $attempt->score > $best_score ) ) {
					$best_score = $attempt->score;
				}
			}

			$rows[] = [
				'user_id'            => $enrollment->user_id,
				'display_name'       => $user ? (string) $user->display_name : '',
				'user_email'         => $user ? (string) $user->user_email : '',
				'enrolled_at'        => $enrollment->enrolled_at,
				'percent'            => $progress->percent,
				'last_activity'      => ProgressRepository::last_activity( $enrollment->user_id, $course_id ),
				'best_score'         => $best_score,
				'status'             => $enrollment->status,
				'completed_at'       => (string) ( $enrollment->completed_at ?? '' ),
				'credits'            => $credit instanceof Credit ? $credit->credits : 0.0,
				'certificate_number' => $certificate instanceof Certificate ? $certificate->certificate_number : '',
				'certificate_url'    => $certificate instanceof Certificate ? $certificate->url() : '',
				'has_access'         => Roles::user_has( $enrollment->user_id, Roles::access_slug( $course_id ) ),
			];
		}

		return $rows;
	}

	public function render_learners( \WP_Post $post ): void {
		if ( ! Capabilities::current_user_can( 'reports' ) ) {
			echo '<p>' . \esc_html__( 'You do not have permission to view learner reports.', 'anchor-schema' ) . '</p>';
			return;
		}

		$course_id = (int) $post->ID;
		$rows      = self::rows( $course_id );

		if ( [] === $rows ) {
			echo '<p>' . \esc_html__( 'No learners are enrolled yet.', 'anchor-schema' ) . '</p>';
		} else {
			echo '<table class="widefat striped anchor-courses-report"><thead><tr>';
			foreach ( [
				\__( 'Learner', 'anchor-schema' ),
				\__( 'Email', 'anchor-schema' ),
				\__( 'Enrolled', 'anchor-schema' ),
				\__( 'Progress', 'anchor-schema' ),
				\__( 'Last activity', 'anchor-schema' ),
				\__( 'Best quiz', 'anchor-schema' ),
				\__( 'Status', 'anchor-schema' ),
				\__( 'Completed', 'anchor-schema' ),
				\__( 'Credits', 'anchor-schema' ),
				\__( 'Certificate', 'anchor-schema' ),
				\__( 'Access', 'anchor-schema' ),
			] as $heading ) {
				echo '<th>' . \esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				echo '<tr>';
				\printf( '<td>%s</td>', \esc_html( $row['display_name'] ) );
				\printf( '<td>%s</td>', \esc_html( $row['user_email'] ) );
				\printf( '<td>%s</td>', \esc_html( \mysql2date( 'Y-m-d', $row['enrolled_at'] ) ) );
				\printf(
					'<td class="progress"><div class="anchor-courses-bar"><span style="width:%1$s%%"></span></div>%1$s%%</td>',
					\esc_html( \number_format_i18n( (float) $row['percent'], 0 ) )
				);
				\printf( '<td>%s</td>', \esc_html( '' === $row['last_activity'] ? '-' : \mysql2date( 'Y-m-d', $row['last_activity'] ) ) );
				\printf( '<td>%s</td>', \esc_html( null === $row['best_score'] ? '-' : \number_format_i18n( (float) $row['best_score'], 0 ) . '%' ) );
				\printf( '<td>%s</td>', \esc_html( $row['status'] ) );
				\printf( '<td>%s</td>', \esc_html( '' === $row['completed_at'] ? '-' : \mysql2date( 'Y-m-d', $row['completed_at'] ) ) );
				\printf( '<td>%s</td>', \esc_html( \number_format_i18n( (float) $row['credits'], 1 ) ) );
				if ( '' !== $row['certificate_number'] ) {
					\printf(
						'<td><a href="%s">%s</a></td>',
						\esc_url( (string) $row['certificate_url'] ),
						\esc_html( (string) $row['certificate_number'] )
					);
				} else {
					echo '<td>-</td>';
				}
				// Access + per-row Revoke, carried over from Task 21.
				echo '<td>';
				if ( $row['has_access'] && Capabilities::current_user_can( 'enrollments' ) ) {
					$this->revoke_button( $course_id, (int) $row['user_id'] );
				} else {
					echo \esc_html( $row['has_access'] ? \__( 'Yes', 'anchor-schema' ) : \__( 'No', 'anchor-schema' ) );
				}
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		if ( Capabilities::current_user_can( 'enrollments' ) ) {
			$this->render_add_learner_form( $course_id ); // Task 21.
			( new EnrollmentManager() )->render_form( $course_id );
		}
	}

	/** @return array<int,array<string,mixed>> */
	public static function user_rows( int $user_id ): array {
		$progress_service    = new ProgressService();
		$credit_service      = new CreditService();
		$certificate_service = new CertificateService( $credit_service );

		$rows = [];

		foreach ( EnrollmentRepository::for_user( $user_id ) as $enrollment ) {
			$credit      = $credit_service->get( $user_id, $enrollment->course_id );
			$certificate = $certificate_service->get( $user_id, $enrollment->course_id );

			$attempts = [];
			foreach ( QuizAttemptRepository::for_user_course( $user_id, $enrollment->course_id ) as $attempt ) {
				$attempts[] = [
					'quiz_id'        => $attempt->quiz_id,
					'quiz_title'     => (string) \get_the_title( $attempt->quiz_id ),
					'attempt_number' => $attempt->attempt_number,
					'score'          => $attempt->score,
					'passed'         => $attempt->passed,
					'submitted_at'   => (string) ( $attempt->submitted_at ?? '' ),
				];
			}

			$rows[] = [
				'course_id'          => $enrollment->course_id,
				'course_title'       => (string) \get_the_title( $enrollment->course_id ),
				'status'             => $enrollment->status,
				'percent'            => $progress_service->get_course_progress( $user_id, $enrollment->course_id )->percent,
				'attempts'           => $attempts,
				'credits'            => $credit instanceof Credit ? $credit->credits : 0.0,
				'certificate_number' => $certificate instanceof Certificate ? $certificate->certificate_number : '',
				'certificate_url'    => $certificate instanceof Certificate ? $certificate->url() : '',
			];
		}

		return $rows;
	}

	public function render_user_profile( \WP_User $user ): void {
		if ( ! Capabilities::current_user_can( 'reports' ) && \get_current_user_id() !== (int) $user->ID ) {
			return;
		}

		$rows = self::user_rows( (int) $user->ID );

		echo '<h2 id="anchor-courses">' . \esc_html__( 'Courses', 'anchor-schema' ) . '</h2>';

		if ( [] === $rows ) {
			echo '<p>' . \esc_html__( 'No enrolments.', 'anchor-schema' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped anchor-courses-report"><thead><tr>';
		foreach ( [
			\__( 'Course', 'anchor-schema' ),
			\__( 'Status', 'anchor-schema' ),
			\__( 'Progress', 'anchor-schema' ),
			\__( 'Quiz attempts', 'anchor-schema' ),
			\__( 'Credits', 'anchor-schema' ),
			\__( 'Certificate', 'anchor-schema' ),
		] as $heading ) {
			echo '<th>' . \esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			\printf(
				'<td><a href="%s">%s</a></td>',
				\esc_url( (string) \get_edit_post_link( (int) $row['course_id'], 'raw' ) ),
				\esc_html( (string) $row['course_title'] )
			);
			\printf( '<td>%s</td>', \esc_html( (string) $row['status'] ) );
			\printf( '<td>%s%%</td>', \esc_html( \number_format_i18n( (float) $row['percent'], 0 ) ) );

			echo '<td>';
			foreach ( (array) $row['attempts'] as $attempt ) {
				\printf(
					'<div>%s #%d: %s%s</div>',
					\esc_html( (string) $attempt['quiz_title'] ),
					(int) $attempt['attempt_number'],
					\esc_html( null === $attempt['score'] ? '-' : \number_format_i18n( (float) $attempt['score'], 0 ) . '%' ),
					$attempt['passed'] ? ' ' . \esc_html__( '(passed)', 'anchor-schema' ) : ''
				);
			}
			echo '</td>';

			\printf( '<td>%s</td>', \esc_html( \number_format_i18n( (float) $row['credits'], 1 ) ) );
			if ( '' !== $row['certificate_number'] ) {
				\printf(
					'<td><a href="%s">%s</a></td>',
					\esc_url( (string) $row['certificate_url'] ),
					\esc_html( (string) $row['certificate_number'] )
				);
			} else {
				echo '<td>-</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
```

`anchor-courses/src/Admin/EnrollmentManager.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Database\ProgressRepository;
use Anchor\Courses\Module;
use Anchor\Courses\Services\CompletionService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Capabilities;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Enrolment maintenance on the course screen (brief 32 "enrollment management").
 *
 * Four verbs, all of them about an enrolment that already exists. ADDING a
 * learner is not here: that is the Learners tab's add form (Task 21), which
 * grants the access role through Support\Roles like every other grant. Two
 * ways to enrol from one screen would be two chances to skip the prerequisite
 * check.
 */
final class EnrollmentManager {

	public const NONCE = 'anchor_courses_enrollment_nonce';

	public const ACTIONS = [ 'cancel', 'reset', 'complete', 'uncomplete' ];

	/**
	 * The whole `anchor_courses_admin_notice` vocabulary, for every course
	 * screen - this class owns the banner so there is one renderer and one
	 * list, not one per handler. Tasks 19 and 21 redirect with codes from here.
	 */
	public const NOTICES = [
		'cancelled', 'reset', 'completed', 'uncompleted', 'role_deleted',
		'learner_added', 'access_revoked',
		'bad_nonce', 'forbidden', 'no_user', 'error',
		'missing_prerequisite', 'not_available_yet', 'no_longer_available',
	];

	public function __construct() {
		\add_action( 'admin_post_anchor_courses_manage_enrollment', [ $this, 'handle_action' ] );
		\add_action( 'admin_notices', [ $this, 'render_notice' ] );
	}

	public function render_form( int $course_id ): void {
		echo '<h4>' . \esc_html__( 'Manage enrolment', 'anchor-schema' ) . '</h4>';
		\printf(
			'<form method="post" action="%s" class="anchor-courses-enrollment-form">',
			\esc_url( \admin_url( 'admin-post.php' ) )
		);
		\wp_nonce_field( self::NONCE . '_' . $course_id );
		echo '<input type="hidden" name="action" value="anchor_courses_manage_enrollment" />';
		\printf( '<input type="hidden" name="course_id" value="%d" />', $course_id );

		echo '<p>';
		\wp_dropdown_users(
			[
				'name'              => 'user_id',
				'show_option_none'  => \__( '- select a user -', 'anchor-schema' ),
				'option_none_value' => '0',
				'number'            => 200,
			]
		);
		echo ' <select name="anchor_courses_action">';
		$labels = [
			'cancel'     => \__( 'Cancel enrolment (removes access)', 'anchor-schema' ),
			'reset'      => \__( 'Reset progress', 'anchor-schema' ),
			'complete'   => \__( 'Mark complete', 'anchor-schema' ),
			'uncomplete' => \__( 'Undo completion', 'anchor-schema' ),
		];
		foreach ( $labels as $value => $label ) {
			\printf( '<option value="%s">%s</option>', \esc_attr( $value ), \esc_html( $label ) );
		}
		echo '</select> ';
		\printf( '<button type="submit" class="button">%s</button>', \esc_html__( 'Apply', 'anchor-schema' ) );
		echo '</p></form>';
	}

	public function handle_action(): void {
		$course_id = \absint( $_POST['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$user_id   = \absint( $_POST['user_id'] ?? 0 );   // phpcs:ignore WordPress.Security.NonceVerification
		$action    = \sanitize_key( \wp_unslash( (string) ( $_POST['anchor_courses_action'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification

		$nonce = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['_wpnonce'] ?? '' ) ) );
		if ( ! \wp_verify_nonce( $nonce, self::NONCE . '_' . $course_id ) ) {
			$this->redirect( 'bad_nonce', $course_id );
		}
		if ( ! Capabilities::current_user_can( 'enrollments' ) ) {
			$this->redirect( 'forbidden', $course_id );
		}
		if ( $user_id <= 0 || ! \get_userdata( $user_id ) ) {
			$this->redirect( 'no_user', $course_id );
		}
		if ( ! \in_array( $action, self::ACTIONS, true ) ) {
			$this->redirect( 'error', $course_id );
		}

		$module      = Module::instance();
		$enrollments = $module ? $module->enrollments : new EnrollmentService();
		$completion  = $module ? $module->completion : null;

		switch ( $action ) {
			case 'cancel':
				// Take the access away AND close the row. The loss policy is
				// not consulted: it exists to decide what an *incidental* role
				// loss means, and there is nothing incidental about an operator
				// choosing "Cancel enrolment".
				Roles::revoke_access( $user_id, $course_id, 'admin' );
				$enrollments->cancel( $user_id, $course_id );
				$this->redirect( 'cancelled', $course_id );
				break;

			case 'reset':
				ProgressRepository::delete_for_course( $user_id, $course_id );
				$enrollments->set_status( $user_id, $course_id, 'enrolled' );
				$this->redirect( 'reset', $course_id );
				break;

			case 'complete':
				// Marking someone complete implies they had access. Grant the
				// role (idempotent); the listener creates the enrolment row.
				Roles::grant_access( $user_id, $course_id, 'manual' );
				if ( $completion instanceof CompletionService ) {
					// Force the transition even when items are outstanding: an
					// admin saying "complete" is the manual completion mode.
					\add_filter( 'anchor_courses_course_completion_status', '__return_true', 99 );
					$completion->complete( $user_id, $course_id );
					\remove_filter( 'anchor_courses_course_completion_status', '__return_true', 99 );
				}
				$this->redirect( 'completed', $course_id );
				break;

			case 'uncomplete':
				if ( $completion instanceof CompletionService ) {
					$completion->uncomplete( $user_id, $course_id );
				}
				$this->redirect( 'uncompleted', $course_id );
				break;
		}
	}

	public function render_notice(): void {
		$code = \sanitize_key( \wp_unslash( (string) ( $_GET['anchor_courses_admin_notice'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! \in_array( $code, self::NOTICES, true ) ) {
			return;
		}

		$messages = [
			'cancelled'            => \__( 'Enrolment cancelled and access removed.', 'anchor-schema' ),
			'reset'                => \__( 'Progress reset.', 'anchor-schema' ),
			'completed'            => \__( 'Course marked complete.', 'anchor-schema' ),
			'uncompleted'          => \__( 'Completion undone. Credits and certificates were kept.', 'anchor-schema' ),
			'role_deleted'         => \__( 'Role deleted.', 'anchor-schema' ),
			'learner_added'        => \__( 'Learner added and given access.', 'anchor-schema' ),
			'access_revoked'       => \__( 'Access removed.', 'anchor-schema' ),
			'bad_nonce'            => \__( 'That request expired. Please try again.', 'anchor-schema' ),
			'forbidden'            => \__( 'You are not allowed to manage enrolments.', 'anchor-schema' ),
			'no_user'              => \__( 'That person could not be resolved to an account.', 'anchor-schema' ),
			'error'                => \__( 'That action could not be completed.', 'anchor-schema' ),
			'missing_prerequisite' => \__( 'Access was not granted: this course has prerequisites they have not finished.', 'anchor-schema' ),
			'not_available_yet'    => \__( 'Access was not granted: this course is not open yet.', 'anchor-schema' ),
			'no_longer_available'  => \__( 'Access was not granted: this course has closed.', 'anchor-schema' ),
		];

		$is_error = ! \in_array( $code, [ 'cancelled', 'reset', 'completed', 'uncompleted', 'role_deleted', 'learner_added', 'access_revoked' ], true );

		\printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$is_error ? 'error' : 'success',
			\esc_html( $messages[ $code ] )
		);
	}

	private function redirect( string $code, int $course_id ): void {
		$target = $course_id > 0
			? (string) \get_edit_post_link( $course_id, 'raw' )
			: \admin_url();

		\wp_safe_redirect( \add_query_arg( 'anchor_courses_admin_notice', $code, $target ) );
		exit;
	}
}
```

`anchor-courses/anchor-courses.php` - inside the `is_admin()` branch, next to the `Admin\LearnerReports()` line Task 21 already added:

```php
			new Admin\EnrollmentManager();
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Admin_Reports
vendor/bin/phpunit --filter Test_Courses_Learners
```
Expected: PASS (10 tests), and the Task 21 Learners suite still green - the add and revoke controls must survive this task's rewrite of `render_learners()`.

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Admin/LearnerReports.php anchor-courses/src/Admin/EnrollmentManager.php \
        anchor-courses/anchor-courses.php tests/test-courses-admin-reports.php
git commit -m "feat(courses): learner report columns, user-profile courses tab and enrolment management"
```

---

### Task 32 (GATE): The brief section 38 milestone, end to end

**This task is a gate. Phase 5 does not start until both of its tests pass.**

The brief's first-usable-milestone path, exactly: admin creates Course A -> Module 1 -> Lesson 1 -> Quiz 1 -> passing score 80% -> max attempts 2 -> user is enrolled -> reads Lesson 1 -> marks it complete -> takes Quiz 1 -> fails -> retries -> passes -> course reaches 100% -> course marked completed -> 2 CE credits awarded -> certificate generated.

"User enrols" is the one step the brief describes that this module does not have: nobody enrols themselves (design spec 7). The milestone therefore enrols the learner the way production does - `Support\Roles::grant_access()`, exactly what the Learners tab and the WooCommerce adapter call - and asserts the enrolment row appeared *via the listener*. That makes the gate prove the whole access mechanism, not just the progress engine.

**Files:**
- Create: `tests/test-courses-milestone.php` (PHPUnit integration)
- Create: `e2e/courses/milestone.spec.js` (Playwright)
- Modify: `bin/e2e-seed.sh` - enable the `courses` module in `DESIRED_MODULES_JSON` (line 81, an exact-string comparison - edit the literal, do not append) and add the course fixture + `.seed.json` keys
- Modify: `package.json` - no change needed; `npm run test:e2e` already runs `playwright test` over `./e2e`

**Interfaces:**
- Consumes: everything from Tasks 1-31.
- Produces:
  - `.seed.json` keys `courses_course_id`, `courses_course_url`, `courses_lesson_url`, `courses_learner_user`, `courses_learner_pass`
  - No new PHP.

- [ ] **Step 1: Write the failing PHPUnit integration test**

Create `tests/test-courses-milestone.php`:

```php
<?php
/**
 * Anchor Courses - the brief section 38 milestone path, in one test.
 *
 * If this passes, the engine works: authoring, enrolment, progression, a failed
 * quiz attempt, a retry, a pass, 100% progress, completion, CE credits and a
 * certificate. Everything else in the module is a layer around this path.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Database\CertificateRepository;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Support\Roles;

/** @group courses @group milestone */
class Test_Courses_Milestone extends Anchor_Courses_TestCase {

	public function test_the_first_usable_milestone_path() {
		$module = $this->courses();

		// --- Admin authors the course -------------------------------------
		$course = $this->make_course(
			[
				'progression_mode'    => 'sequential',
				'completion_mode'     => 'all_required_items',
				'ce_credits'          => '2',
				'ce_type'             => 'Dental CE',
				'ce_provider_name'    => 'DEKA Academy',
				'certificate_enabled' => 1,
			],
			'Course A'
		);

		$lesson = $this->make_lesson( [ 'completion_mode' => 'manual', 'required' => 1 ], 'Lesson 1' );
		$quiz   = $this->make_quiz(
			[ 'settings' => [ 'passing_score' => 80, 'max_attempts' => 2, 'show_correct_answers' => 1 ] ],
			'Quiz 1'
		);

		$questions = Questions::save(
			$quiz,
			[
				[ 'type' => 'single_choice', 'prompt' => 'Q1', 'points' => 1,
				  'answers' => [ [ 'id' => 'a1', 'text' => 'Wrong', 'correct' => false ], [ 'id' => 'a2', 'text' => 'Right', 'correct' => true ] ] ],
				[ 'type' => 'single_choice', 'prompt' => 'Q2', 'points' => 1,
				  'answers' => [ [ 'id' => 'b1', 'text' => 'Right', 'correct' => true ], [ 'id' => 'b2', 'text' => 'Wrong', 'correct' => false ] ] ],
			]
		);
		$q1 = $questions[0]['id'];
		$q2 = $questions[1]['id'];

		Curriculum::save(
			$course,
			[ [ 'title' => 'Module 1', 'items' => [
				[ 'type' => 'lesson', 'id' => $lesson ],
				[ 'type' => 'quiz', 'id' => $quiz ],
			] ] ]
		);

		$this->assertSame( 2, count( Curriculum::required_items( $course ) ) );

		// --- Learner is given access (the role IS the enrolment) ----------
		$user = $this->make_learner( [ 'display_name' => 'Milestone Learner' ] );

		$this->assertTrue( Roles::grant_access( $user, $course, 'manual' ) );
		$this->assertTrue( Roles::user_has( $user, Roles::access_slug( $course ) ) );

		$enrollment = $module->enrollments->get( $user, $course );
		$this->assertNotNull( $enrollment, 'The role listener must have created the enrolment row.' );
		$this->assertSame( 'enrolled', $enrollment->status );
		$this->assertSame( 'manual', $enrollment->source );

		// --- Sequential progression locks the quiz ------------------------
		$this->assertFalse( $module->progress->is_item_available( $user, $course, $quiz, 'quiz' ) );
		$this->assertSame(
			'locked',
			$module->quizzes->start_attempt( $user, $quiz, $course )->get_error_code()
		);

		// --- Learner reads and completes Lesson 1 -------------------------
		$module->progress->start_lesson( $user, $course, $lesson );
		$this->assertNotWPError( anchor_courses_complete_lesson( $user, $course, $lesson ) );
		$this->assertSame( 50.0, anchor_courses_get_progress( $user, $course )->percent );

		// --- Attempt 1: fails ---------------------------------------------
		$attempt1 = $module->quizzes->start_attempt( $user, $quiz, $course );
		$this->assertInstanceOf( QuizAttempt::class, $attempt1 );
		$this->assertSame( 1, $attempt1->attempt_number );

		$failed = $module->quizzes->submit( $attempt1->id, [ $q1 => 'a1', $q2 => 'b2' ] );
		$this->assertSame( 0.0, $failed->score );
		$this->assertFalse( $failed->passed );
		$this->assertSame( 50.0, anchor_courses_get_progress( $user, $course )->percent );
		$this->assertNull( CreditRepository::find( $user, $course ), 'A failed attempt must award nothing.' );

		// --- Attempt 2: passes --------------------------------------------
		$this->assertSame( 1, $module->quizzes->attempts_remaining( $user, $quiz ) );

		$attempt2 = $module->quizzes->start_attempt( $user, $quiz, $course );
		$this->assertSame( 2, $attempt2->attempt_number );

		$passed = $module->quizzes->submit( $attempt2->id, [ $q1 => 'a2', $q2 => 'b1' ] );
		$this->assertSame( 100.0, $passed->score );
		$this->assertTrue( $passed->passed );

		// --- Course reaches 100% and completes ----------------------------
		$progress = anchor_courses_get_progress( $user, $course );
		$this->assertSame( 100.0, $progress->percent );
		$this->assertTrue( $progress->complete );
		$this->assertTrue( $module->completion->is_complete( $user, $course ) );
		$this->assertSame( 'completed', $module->enrollments->get( $user, $course )->status );

		// --- 2 CE credits awarded, exactly once ---------------------------
		$credit = CreditRepository::find( $user, $course );
		$this->assertNotNull( $credit );
		$this->assertSame( 2.0, $credit->credits );
		$this->assertSame( 'Dental CE', $credit->credit_type );
		$this->assertCount( 1, CreditRepository::for_user( $user ) );

		// --- Certificate generated, exactly once --------------------------
		$certificate = CertificateRepository::find( $user, $course );
		$this->assertNotNull( $certificate );
		$this->assertMatchesRegularExpression( '/^AC-\d{4}-\d{8}$/', $certificate->certificate_number );
		$this->assertSame( $certificate->id, $credit->id > 0 ? CreditRepository::find( $user, $course )->certificate_id : 0 );
		$this->assertCount( 1, CertificateRepository::for_user( $user ) );

		// --- A third attempt is refused -----------------------------------
		$this->assertSame(
			'no_attempts_remaining',
			$module->quizzes->start_attempt( $user, $quiz, $course )->get_error_code()
		);

		// --- The completion role is now a prerequisite others can use -----
		$this->assertTrue( Roles::user_has( $user, Roles::completion_slug( $course ) ) );

		// --- Re-running everything changes nothing (brief 26) -------------
		Roles::grant_access( $user, $course, 'manual' );
		anchor_courses_enroll_user( $user, $course );
		anchor_courses_complete_lesson( $user, $course, $lesson );
		$module->completion->complete( $user, $course );

		$this->assertCount( 1, CreditRepository::for_user( $user ) );
		$this->assertCount( 1, CertificateRepository::for_user( $user ) );
		$this->assertSame( 2.0, CreditRepository::total_for_user( $user ) );

		remove_role( Roles::access_slug( $course ) );
		remove_role( Roles::completion_slug( $course ) );
	}
}
```

- [ ] **Step 2: Run it to verify it fails (or reveals a real gap)**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Milestone
```
Expected: FAIL on the first assertion that exposes a gap between Tasks 1-31. If it passes first time, that is fine - the gate is the point, not the red bar.

- [ ] **Step 3: Fix whatever it exposes, then run again**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Milestone
vendor/bin/phpunit --group courses
```
Expected: PASS, and the whole `courses` group still green.

- [ ] **Step 4: Extend the E2E seed**

`bin/e2e-seed.sh` - replace the modules literal at line 81 (an exact-string comparison, so it must be edited in place, not appended):

```bash
DESIRED_MODULES_JSON='{"modules":{"events_manager":true,"video_slider":true,"compliance":true,"courses":true}}'
```

and update the log line below it:

```bash
log "Events + Gallery + Compliance + Courses modules enabled."
```

Then add the courses fixture before the `.seed.json` write:

```bash
# ---------------------------------------------------------------------------
# Anchor Courses fixture (brief section 38 milestone E2E).
#
# One course with one module, one lesson and one two-question quiz
# (passing score 80, max attempts 2), plus a learner account the spec signs in
# as. Idempotent: reuses the posts by slug.
# ---------------------------------------------------------------------------
COURSES_IDS="$(wp eval '
  $find = function ( $type, $slug, $title ) {
      $existing = get_posts( [ "post_type" => $type, "name" => $slug, "posts_per_page" => 1, "fields" => "ids", "post_status" => "any" ] );
      return $existing ? (int) $existing[0] : (int) wp_insert_post( [
          "post_type" => $type, "post_name" => $slug, "post_title" => $title, "post_status" => "publish",
      ] );
  };

  $course = $find( "anchor_course", "courses-e2e-course", "Course A" );
  $lesson = $find( "anchor_lesson", "courses-e2e-lesson", "Lesson 1" );
  $quiz   = $find( "anchor_quiz", "courses-e2e-quiz", "Quiz 1" );

  wp_update_post( [ "ID" => $lesson, "post_content" => "Read this lesson, then take the quiz." ] );

  update_post_meta( $course, "_anchor_course_progression_mode", "sequential" );
  update_post_meta( $course, "_anchor_course_completion_mode", "all_required_items" );
  update_post_meta( $course, "_anchor_course_ce_credits", 2 );
  update_post_meta( $course, "_anchor_course_ce_type", "Dental CE" );
  update_post_meta( $course, "_anchor_course_ce_provider_name", "DEKA Academy" );
  update_post_meta( $course, "_anchor_course_certificate_enabled", 1 );

  update_post_meta( $lesson, "_anchor_lesson_completion_mode", "manual" );
  update_post_meta( $lesson, "_anchor_lesson_required", 1 );

  update_post_meta( $quiz, "_anchor_quiz_settings", [
      "passing_score" => 80, "max_attempts" => 2, "time_limit_seconds" => 0,
      "shuffle_questions" => 0, "shuffle_answers" => 0, "show_correct_answers" => 1,
      "show_score" => 1, "allow_review" => 1, "retry_delay_seconds" => 0,
      "required" => 1, "on_timer_expiry" => "auto_submit",
  ] );

  \Anchor\Courses\Content\Questions::save( $quiz, [
      [ "type" => "single_choice", "prompt" => "Q1", "points" => 1, "answers" => [
          [ "id" => "a1", "text" => "Wrong", "correct" => false ],
          [ "id" => "a2", "text" => "Right", "correct" => true ],
      ] ],
      [ "type" => "single_choice", "prompt" => "Q2", "points" => 1, "answers" => [
          [ "id" => "b1", "text" => "Right", "correct" => true ],
          [ "id" => "b2", "text" => "Wrong", "correct" => false ],
      ] ],
  ] );

  \Anchor\Courses\Content\Curriculum::save( $course, [
      [ "title" => "Module 1", "items" => [
          [ "type" => "lesson", "id" => $lesson ],
          [ "type" => "quiz", "id" => $quiz ],
      ] ],
  ] );

  echo $course . "|" . $lesson . "|" . $quiz;
')"
COURSES_COURSE_ID="${COURSES_IDS%%|*}"
COURSES_REST="${COURSES_IDS#*|}"
COURSES_LESSON_ID="${COURSES_REST%%|*}"
COURSES_QUIZ_ID="${COURSES_REST##*|}"
log "Course #${COURSES_COURSE_ID}, lesson #${COURSES_LESSON_ID}, quiz #${COURSES_QUIZ_ID}"

# Learner account the courses spec signs in as, holding the course's ACCESS
# role - which is what enrolment is (design spec 3.1). Granting it through
# Support\Roles rather than wp user add-role exercises the same path the
# Learners tab uses, so the seed cannot drift from production.
if ! wp user get courses-learner >/dev/null 2>&1; then
  wp user create courses-learner courses-learner@example.test --role=subscriber --user_pass=courses-pass --display_name="Courses Learner"
fi
wp eval '
  $user = get_user_by( "login", "courses-learner" );
  \Anchor\Courses\Support\Roles::grant_access( (int) $user->ID, '"${COURSES_COURSE_ID}"', "manual" );
'
log "Learner account: courses-learner (holds anchor_course_${COURSES_COURSE_ID})"
```

Finally add the keys to the `.seed.json` write (extend the existing `json_encode` array):

```php
"courses_course_id"=>(int)'"${COURSES_COURSE_ID}"',
"courses_course_url"=>get_permalink('"${COURSES_COURSE_ID}"'),
"courses_lesson_url"=>get_permalink('"${COURSES_LESSON_ID}"'),
"courses_quiz_id"=>(int)'"${COURSES_QUIZ_ID}"',
"courses_learner_user"=>"courses-learner",
"courses_learner_pass"=>"courses-pass",
```

- [ ] **Step 5: Write the failing Playwright E2E**

Create `e2e/courses/milestone.spec.js`:

```javascript
// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * Anchor Courses - the brief section 38 milestone, through the browser.
 *
 * FIXTURES: bin/e2e-seed.sh publishes Course A (sequential) with Lesson 1 and
 * Quiz 1 (passing 80%, 2 attempts), creates the `courses-learner` account and
 * grants it the course's access role, writing the ids/urls into
 * e2e/.seed.json. Run `npm run env:seed` first.
 *
 * The spec drives the real UI: read, mark complete, fail the quiz, retry,
 * pass, and land on a 100% course with a certificate link. There is no enrol
 * step to drive - the learner arrives already holding the access role, which
 * is the only way anybody is ever enrolled (design spec 3.1, 7).
 */

const SEED_PATH = path.join(__dirname, '..', '.seed.json');

/** @type {{courses_course_url: string, courses_lesson_url: string, courses_learner_user: string, courses_learner_pass: string}} */
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(`Missing ${SEED_PATH}. Run the seed first: npm run env:seed`);
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.courses_course_url, 'seed courses_course_url').toBeTruthy();
  expect(seed.courses_lesson_url, 'seed courses_lesson_url').toBeTruthy();
});

/** Sign in as the seeded learner. */
async function loginAsLearner(page) {
  await page.goto('/wp-login.php');
  if (await page.locator('#user_login').isVisible().catch(() => false)) {
    await page.fill('#user_login', seed.courses_learner_user);
    await page.fill('#user_pass', seed.courses_learner_pass);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  }
}

test('milestone: complete a lesson, fail a quiz, retry, pass, finish the course', async ({ page }) => {
  await loginAsLearner(page);

  // 1) The learner already holds the access role, so the course opens with no
  //    enrol control at all - not a button, not a "sign in to enrol" link.
  await page.goto(new URL(seed.courses_course_url).pathname);
  await expect(page.locator('.anchor-course-title')).toContainText('Course A');
  await expect(page.locator('.anchor-course-cta')).toHaveCount(0);
  await expect(page.locator('.anchor-courses-progress-label')).toContainText('0%');

  // 2) Sequential progression: the quiz is not startable yet.
  await expect(page.locator('.anchor-course-item--quiz.is-locked')).toHaveCount(1);

  // 3) Open Lesson 1 and mark it complete.
  await page.goto(new URL(seed.courses_lesson_url).pathname);
  await expect(page.locator('.anchor-lesson-content')).toContainText('Read this lesson');
  await page.locator('.anchor-lesson-complete button[type="submit"]').click();
  await expect(page.locator('.anchor-courses-notice')).toContainText(/complete/i);
  await expect(page.locator('.anchor-courses-progress-label')).toContainText('50%');

  // 4) Back on the course, start the quiz and answer both wrong.
  await page.goto(new URL(seed.courses_course_url).pathname);
  await page.locator('.anchor-quiz-start').click();
  await expect(page.locator('.anchor-quiz-question')).toHaveCount(2);

  // No answer key may ever be in the page source (brief rule 8).
  expect(await page.content()).not.toContain('"correct"');

  await page.locator('.anchor-quiz-question').nth(0).locator('input[value="a1"]').check();
  await page.locator('.anchor-quiz-question').nth(1).locator('input[value="b2"]').check();
  await page.locator('.anchor-quiz-form button[type="submit"]').click();
  await expect(page.locator('.anchor-quiz-result')).toContainText(/not passed/i);

  // 5) Retry and answer both correctly.
  await page.reload();
  await expect(page.locator('.anchor-quiz-remaining')).toContainText('1 attempt');
  await page.locator('.anchor-quiz-start').click();
  await page.locator('.anchor-quiz-question').nth(0).locator('input[value="a2"]').check();
  await page.locator('.anchor-quiz-question').nth(1).locator('input[value="b1"]').check();
  await page.locator('.anchor-quiz-form button[type="submit"]').click();
  await expect(page.locator('.anchor-quiz-result')).toContainText(/passed/i);

  // 6) The course is finished: 100%, credits and a certificate.
  await page.reload();
  await expect(page.locator('.anchor-courses-progress-label')).toContainText('100%');
});

test('a visitor without access is told how to ask, and offered no enrol control', async ({ page }) => {
  // Signed out on purpose: the one place a stray "Enrol" button would show up.
  await page.goto(new URL(seed.courses_course_url).pathname);

  await expect(page.locator('.anchor-course-cta--ask')).toContainText(/ask us about access/i);
  await expect(page.locator('.anchor-course-cta form')).toHaveCount(0);
  expect(await page.content()).not.toContain('anchor_courses_enroll');
});

test('the learner dashboard shows the credit and the certificate', async ({ page }) => {
  await loginAsLearner(page);

  // A page carrying the three learner shortcodes, created on the fly.
  await page.goto('/?p=1'); // any page; the shortcodes are asserted via the course page links instead.
  await page.goto(new URL(seed.courses_course_url).pathname);
  await expect(page.locator('.anchor-course-credits')).toContainText('2');
});
```

- [ ] **Step 6: Run the E2E**

```bash
npm install && npm run wp-env start
npm run env:seed
npx playwright test e2e/courses/milestone.spec.js
```
Expected: FAIL until the seed and the UI line up, then PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add tests/test-courses-milestone.php e2e/courses/milestone.spec.js bin/e2e-seed.sh
git commit -m "test(courses): brief section 38 milestone as a PHPUnit integration test and a Playwright E2E"
```

---

## Phase 5 - Integration Layer

### Task 33: Course and learner REST routes

**Files:**
- Create: `anchor-courses/src/Rest/CoursesController.php`, `anchor-courses/src/Rest/MeController.php`
- Modify: `anchor-courses/anchor-courses.php` - add both to `Rest\Routes`
- Test: `tests/test-courses-rest-public.php`

**Interfaces:**
- Produces:
  - `Rest\CoursesController::register_routes(): void`
    - `GET /courses` (`Routes::public_read`) - published courses, ids/titles/credits, never learner data
    - `GET /courses/(?P<id>\d+)` (`Routes::public_read`)
    - `GET /courses/(?P<id>\d+)/curriculum` (`Routes::public_read`) - titles and types only; no quiz questions
  - `Rest\MeController::register_routes(): void`
    - `GET /me/courses` (`Routes::require_login`)
    - `GET /me/courses/(?P<id>\d+)/progress` (`Routes::require_login`)
    - `POST /lessons/(?P<id>\d+)/start` (`Routes::require_login`)
    - `POST /lessons/(?P<id>\d+)/complete` (`Routes::require_login`)
    - `GET /me/certificates` (`Routes::require_login`)
    - `GET /me/credits` (`Routes::require_login`)

Every `/me/` route reads `get_current_user_id()` and ignores any `user_id` in the request, so one learner can never address another's records (brief 25).

**There is no `POST /courses/{id}/enroll`.** A REST enrol route is self-enrolment with a JSON body, and self-enrolment does not exist (design spec 7). Access is a role; the things that may hand one out are the Learners tab and the WooCommerce adapter, both server-side. A client that needs to know whether it may show a "buy" link reads the course resource and checks whether it is enrolled via `/me/courses`.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - public and learner REST routes (brief 14, 25).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\Questions;
use Anchor\Courses\Rest\Routes;
use Anchor\Courses\Services\CreditService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Rest_Public extends Anchor_Courses_TestCase {

	private WP_REST_Server $server;
	private int $user;
	private int $course;
	private int $lesson;
	private int $quiz;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '2' ], 'Laser Safety' );
		$this->lesson = $this->make_lesson( [], 'Optics' );
		$this->quiz   = $this->make_quiz( [], 'Final Quiz' );

		Questions::save(
			$this->quiz,
			[ [ 'type' => 'true_false', 'prompt' => 'Secret?', 'points' => 1,
			    'answers' => [ [ 'text' => 'True', 'correct' => true ], [ 'text' => 'False', 'correct' => false ] ] ] ]
		);

		Curriculum::save(
			$this->course,
			[ [ 'title' => 'Fundamentals', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->lesson ],
				[ 'type' => 'quiz', 'id' => $this->quiz ],
			] ] ]
		);
	}

	public function tear_down() {
		remove_role( Roles::access_slug( $this->course ) );
		parent::tear_down();
	}

	private function request( string $method, string $route, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . Routes::NAMESPACE . $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	public function test_every_brief_route_is_registered() {
		$routes = array_keys( $this->server->get_routes() );
		foreach ( [
			'/courses', '/courses/(?P<id>\d+)', '/courses/(?P<id>\d+)/curriculum',
			'/me/courses', '/me/courses/(?P<id>\d+)/progress', '/lessons/(?P<id>\d+)/start',
			'/lessons/(?P<id>\d+)/complete', '/me/certificates', '/me/credits',
		] as $route ) {
			$this->assertContains( '/' . Routes::NAMESPACE . $route, $routes, "Missing route {$route}" );
		}

		$this->assertNotContains(
			'/' . Routes::NAMESPACE . '/courses/(?P<id>\d+)/enroll',
			$routes,
			'A REST enrol route is self-enrolment with a JSON body (design spec 7).'
		);
	}

	public function test_the_course_index_lists_published_courses() {
		$response = $this->request( 'GET', '/courses' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Laser Safety', $response->get_data()[0]['title'] );
		$this->assertSame( 2.0, $response->get_data()[0]['ce_credits'] );
	}

	public function test_the_curriculum_route_never_leaks_quiz_questions() {
		$response = $this->request( 'GET', "/courses/{$this->course}/curriculum" );
		$json     = (string) wp_json_encode( $response->get_data() );

		$this->assertStringContainsString( 'Optics', $json );
		$this->assertStringContainsString( 'Final Quiz', $json );
		$this->assertStringNotContainsString( 'Secret?', $json, 'Quiz prompts belong to an attempt, not the curriculum.' );
		$this->assertStringNotContainsString( 'correct', $json );
	}

	/** POSTing to the route that used to exist must 404, not enrol anybody. */
	public function test_there_is_no_enrol_endpoint() {
		wp_set_current_user( $this->user );

		$response = $this->request( 'POST', "/courses/{$this->course}/enroll" );

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( ( new EnrollmentService() )->is_enrolled( $this->user, $this->course ) );
	}

	public function test_me_courses_only_ever_returns_the_signed_in_learner() {
		$other = $this->make_learner();
		Roles::grant_access( $other, $this->course, 'manual' );

		wp_set_current_user( $this->user );
		$response = $this->request( 'GET', '/me/courses', [ 'user_id' => $other ] );

		$this->assertSame( [], $response->get_data(), 'A user_id parameter must be ignored.' );
	}

	public function test_lesson_start_and_complete_move_progress() {
		Roles::grant_access( $this->user, $this->course, 'manual' );
		wp_set_current_user( $this->user );

		$this->assertSame( 200, $this->request( 'POST', "/lessons/{$this->lesson}/start", [ 'course_id' => $this->course ] )->get_status() );

		$done = $this->request( 'POST', "/lessons/{$this->lesson}/complete", [ 'course_id' => $this->course ] );
		$this->assertSame( 200, $done->get_status() );

		$progress = $this->request( 'GET', "/me/courses/{$this->course}/progress" );
		$this->assertSame( 50.0, $progress->get_data()['percent'] );
	}

	public function test_completing_a_lesson_while_unenrolled_is_403() {
		wp_set_current_user( $this->make_learner() );
		$response = $this->request( 'POST', "/lessons/{$this->lesson}/complete", [ 'course_id' => $this->course ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'not_enrolled', $response->get_data()['code'] );
	}

	public function test_me_credits_and_certificates_are_scoped_to_the_learner() {
		( new CreditService() )->award( $this->user, $this->course );

		wp_set_current_user( $this->user );
		$this->assertCount( 1, $this->request( 'GET', '/me/credits' )->get_data()['credits'] );
		$this->assertSame( 2.0, $this->request( 'GET', '/me/credits' )->get_data()['total'] );

		wp_set_current_user( $this->make_learner() );
		$this->assertCount( 0, $this->request( 'GET', '/me/credits' )->get_data()['credits'] );
		$this->assertCount( 0, $this->request( 'GET', '/me/certificates' )->get_data() );
	}

	public function test_a_single_course_response_carries_no_learner_data_when_logged_out() {
		wp_set_current_user( 0 );
		$data = $this->request( 'GET', "/courses/{$this->course}" )->get_data();

		$this->assertArrayNotHasKey( 'progress', $data );
		$this->assertArrayNotHasKey( 'enrollment', $data );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Rest_Public
```
Expected: FAIL - `Class "Anchor\Courses\Rest\CoursesController" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Rest/CoursesController.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Rest;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\Curriculum;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Course catalogue endpoints (brief 14).
 *
 * These are marketing-side reads: titles, credits and the shape of the
 * curriculum. They never carry learner data, and the curriculum route never
 * carries quiz questions (brief 25).
 */
final class CoursesController {

	public function register_routes(): void {
		$id_arg = [ 'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ];

		\register_rest_route(
			Routes::NAMESPACE,
			'/courses',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Routes::class, 'public_read' ],
				'callback'            => [ $this, 'index' ],
				'args'                => [
					'per_page' => [ 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ],
					'page'     => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
				],
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/courses/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Routes::class, 'public_read' ],
				'callback'            => [ $this, 'read' ],
				'args'                => $id_arg,
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/courses/(?P<id>\d+)/curriculum',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Routes::class, 'public_read' ],
				'callback'            => [ $this, 'curriculum' ],
				'args'                => $id_arg,
			]
		);

		// No /enroll route, deliberately: access is a role, and roles are handed
		// out server-side (design spec 7). See the task notes.
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$courses = \get_posts(
			[
				'post_type'      => CoursePostType::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => \max( 1, \min( 100, (int) $request['per_page'] ) ),
				'paged'          => \max( 1, (int) $request['page'] ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		return new \WP_REST_Response( \array_map( [ $this, 'summary' ], $courses ), 200 );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		$course = \get_post( (int) $request['id'] );
		if ( ! $course instanceof \WP_Post || CoursePostType::CPT !== $course->post_type || 'publish' !== $course->post_status ) {
			return new \WP_REST_Response( [ 'code' => 'no_course', 'message' => \__( 'Not found.', 'anchor-schema' ) ], 404 );
		}

		return new \WP_REST_Response( $this->summary( $course ), 200 );
	}

	public function curriculum( \WP_REST_Request $request ): \WP_REST_Response {
		$course_id = (int) $request['id'];
		if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			return new \WP_REST_Response( [ 'code' => 'no_course', 'message' => \__( 'Not found.', 'anchor-schema' ) ], 404 );
		}

		$modules = [];
		foreach ( Curriculum::get( $course_id ) as $module ) {
			$items = [];
			foreach ( $module['items'] as $item ) {
				// Titles and types only. Quiz CONTENT belongs to an attempt.
				$items[] = [
					'type'     => $item['type'],
					'id'       => $item['id'],
					'title'    => (string) \get_the_title( $item['id'] ),
					'required' => $item['required'],
				];
			}
			$modules[] = [
				'id'          => $module['id'],
				'title'       => $module['title'],
				'description' => $module['description'],
				'items'       => $items,
			];
		}

		return new \WP_REST_Response( [ 'course_id' => $course_id, 'modules' => $modules ], 200 );
	}

	private function summary( \WP_Post $course ): array {
		$id = (int) $course->ID;

		return [
			'id'               => $id,
			'title'            => (string) $course->post_title,
			'excerpt'          => (string) \get_the_excerpt( $course ),
			'permalink'        => (string) \get_permalink( $course ),
			'instructor'       => (string) CourseEditor::setting( $id, 'instructor' ),
			'duration'         => (string) CourseEditor::setting( $id, 'duration' ),
			'difficulty'       => (string) CourseEditor::setting( $id, 'difficulty' ),
			'ce_credits'       => (float) CourseEditor::setting( $id, 'ce_credits' ),
			'progression_mode' => (string) CourseEditor::setting( $id, 'progression_mode' ),
			'item_count'       => \count( Curriculum::items( $id ) ),
		];
	}
}
```

`anchor-courses/src/Rest/MeController.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Rest;

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CreditService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Learner-scoped endpoints (brief 14).
 *
 * Every callback reads get_current_user_id() and ignores any user id in the
 * request, so no learner can address another learner's records (brief 25).
 */
final class MeController {

	public function __construct(
		private EnrollmentService $enrollments,
		private ProgressService $progress,
		private CreditService $credits,
		private CertificateService $certificates
	) {}

	public function register_routes(): void {
		$login   = [ Routes::class, 'require_login' ];
		$id_arg  = [ 'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ];
		$lesson  = \array_merge( $id_arg, [ 'course_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ] );

		\register_rest_route( Routes::NAMESPACE, '/me/courses', [
			'methods' => 'GET', 'permission_callback' => $login, 'callback' => [ $this, 'courses' ],
		] );

		\register_rest_route( Routes::NAMESPACE, '/me/courses/(?P<id>\d+)/progress', [
			'methods' => 'GET', 'permission_callback' => $login, 'callback' => [ $this, 'progress' ], 'args' => $id_arg,
		] );

		\register_rest_route( Routes::NAMESPACE, '/lessons/(?P<id>\d+)/start', [
			'methods' => 'POST', 'permission_callback' => $login, 'callback' => [ $this, 'start_lesson' ], 'args' => $lesson,
		] );

		\register_rest_route( Routes::NAMESPACE, '/lessons/(?P<id>\d+)/complete', [
			'methods' => 'POST', 'permission_callback' => $login, 'callback' => [ $this, 'complete_lesson' ], 'args' => $lesson,
		] );

		\register_rest_route( Routes::NAMESPACE, '/me/certificates', [
			'methods' => 'GET', 'permission_callback' => $login, 'callback' => [ $this, 'certificates' ],
		] );

		\register_rest_route( Routes::NAMESPACE, '/me/credits', [
			'methods' => 'GET', 'permission_callback' => $login, 'callback' => [ $this, 'credits' ],
		] );
	}

	public function courses( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = \get_current_user_id();
		$out     = [];

		foreach ( $this->enrollments->get_for_user( $user_id ) as $enrollment ) {
			$out[] = [
				'course_id' => $enrollment->course_id,
				'title'     => (string) \get_the_title( $enrollment->course_id ),
				'permalink' => (string) \get_permalink( $enrollment->course_id ),
				'status'    => $enrollment->status,
				'percent'   => $this->progress->get_course_progress( $user_id, $enrollment->course_id )->percent,
			];
		}

		return new \WP_REST_Response( $out, 200 );
	}

	public function progress( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			$this->progress->get_course_progress( \get_current_user_id(), (int) $request['id'] )->to_array(),
			200
		);
	}

	public function start_lesson( \WP_REST_Request $request ): \WP_REST_Response {
		$progress = $this->progress->start_lesson(
			\get_current_user_id(),
			(int) $request['course_id'],
			(int) $request['id']
		);

		if ( null === $progress ) {
			return new \WP_REST_Response(
				[ 'code' => 'locked', 'message' => \__( 'That lesson is not available yet.', 'anchor-schema' ) ],
				403
			);
		}

		return new \WP_REST_Response( $progress->to_array(), 200 );
	}

	public function complete_lesson( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->progress->complete_lesson(
			\get_current_user_id(),
			(int) $request['course_id'],
			(int) $request['id']
		);

		if ( \is_wp_error( $result ) ) {
			return Routes::error_response( $result );
		}

		return new \WP_REST_Response( $result->to_array(), 200 );
	}

	public function certificates( \WP_REST_Request $request ): \WP_REST_Response {
		$out = [];
		foreach ( $this->certificates->for_user( \get_current_user_id() ) as $certificate ) {
			$out[] = \array_merge(
				$certificate->to_array(),
				[ 'course_title' => (string) \get_the_title( $certificate->course_id ) ]
			);
		}
		return new \WP_REST_Response( $out, 200 );
	}

	public function credits( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = \get_current_user_id();

		$rows = [];
		foreach ( $this->credits->for_user( $user_id ) as $credit ) {
			$rows[] = \array_merge(
				$credit->to_array(),
				[ 'course_title' => (string) \get_the_title( $credit->course_id ) ]
			);
		}

		return new \WP_REST_Response(
			[ 'credits' => $rows, 'total' => $this->credits->total_for_user( $user_id ) ],
			200
		);
	}
}
```

`anchor-courses/anchor-courses.php` - replace the `Rest\Routes` construction:

```php
		new Rest\Routes(
			new Rest\QuizController( $this->quizzes ),
			new Rest\CoursesController(),
			new Rest\MeController( $this->enrollments, $this->progress, $this->credits, $this->certificates )
		);
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Rest_Public
vendor/bin/phpunit --filter Test_Courses_Quiz_Rest
```
Expected: PASS (10 tests) and PASS (11 tests, still green).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Rest/CoursesController.php anchor-courses/src/Rest/MeController.php \
        anchor-courses/anchor-courses.php tests/test-courses-rest-public.php
git commit -m "feat(courses): catalogue and learner REST routes"
```

---

### Task 34: Admin REST routes

**Files:**
- Create: `anchor-courses/src/Rest/AdminController.php`
- Modify: `anchor-courses/anchor-courses.php` - add to `Rest\Routes`
- Test: `tests/test-courses-rest-admin.php`

**Interfaces:**
- Produces:
  - `Rest\AdminController::register_routes(): void`
    - `GET /admin/courses/(?P<id>\d+)/learners` - `Routes::require_cap( 'reports' )`
    - `GET /admin/users/(?P<id>\d+)/courses` - `Routes::require_cap( 'reports' )`
    - `GET /admin/reports/completions` - `Routes::require_cap( 'reports' )`; args `course_id`, `from`, `to`
    - `GET /admin/reports/credits` - `Routes::require_cap( 'credits' )`; args `course_id`, `from`, `to`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - admin REST routes and their capability gates (brief 14, 24).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Rest\Routes;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

/** @group courses */
class Test_Courses_Rest_Admin extends Anchor_Courses_TestCase {

	private WP_REST_Server $server;
	private int $admin;
	private int $learner;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->admin   = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$this->learner = $this->make_learner( [ 'display_name' => 'Ada' ] );
		$this->course  = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '2', 'certificate_enabled' => 1 ], 'Laser Safety' );
		$this->lesson  = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
	}

	private function request( string $method, string $route, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . Routes::NAMESPACE . $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	private function complete_the_course(): void {
		$enrollments = new EnrollmentService();
		$progress    = new ProgressService( $enrollments );
		$progress->set_completion_service( $this->courses()->completion );
		$enrollments->enroll( $this->learner, $this->course );
		$progress->complete_lesson( $this->learner, $this->course, $this->lesson );
	}

	public function test_the_four_admin_routes_are_registered() {
		$routes = array_keys( $this->server->get_routes() );
		foreach ( [
			'/admin/courses/(?P<id>\d+)/learners',
			'/admin/users/(?P<id>\d+)/courses',
			'/admin/reports/completions',
			'/admin/reports/credits',
		] as $route ) {
			$this->assertContains( '/' . Routes::NAMESPACE . $route, $routes, "Missing route {$route}" );
		}
	}

	public function test_a_learner_is_refused_every_admin_route() {
		wp_set_current_user( $this->learner );
		foreach ( [
			"/admin/courses/{$this->course}/learners",
			"/admin/users/{$this->learner}/courses",
			'/admin/reports/completions',
			'/admin/reports/credits',
		] as $route ) {
			$this->assertSame( 403, $this->request( 'GET', $route )->get_status(), "Route {$route} was not gated." );
		}
	}

	public function test_a_logged_out_visitor_gets_401() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '/admin/reports/completions' )->get_status() );
	}

	public function test_an_admin_sees_the_learner_roster_for_a_course() {
		$this->complete_the_course();
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', "/admin/courses/{$this->course}/learners" )->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Ada', $data[0]['display_name'] );
		$this->assertSame( 100.0, $data[0]['percent'] );
		$this->assertSame( 2.0, $data[0]['credits'] );
	}

	public function test_an_admin_sees_one_users_courses() {
		$this->complete_the_course();
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', "/admin/users/{$this->learner}/courses" )->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Laser Safety', $data[0]['course_title'] );
	}

	public function test_the_completions_report_counts_completed_enrolments() {
		$this->complete_the_course();
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', '/admin/reports/completions', [ 'course_id' => $this->course ] )->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertCount( 1, $data['rows'] );
	}

	public function test_the_credits_report_totals_awarded_credits() {
		$this->complete_the_course();
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', '/admin/reports/credits', [ 'course_id' => $this->course ] )->get_data();

		$this->assertSame( 2.0, $data['total_credits'] );
		$this->assertCount( 1, $data['rows'] );
	}

	public function test_the_credits_report_uses_the_credits_capability_not_reports() {
		$reporter = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		get_user_by( 'id', $reporter )->add_cap( 'view_anchor_course_reports' );
		wp_set_current_user( $reporter );

		$this->assertSame( 200, $this->request( 'GET', '/admin/reports/completions' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/admin/reports/credits' )->get_status() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Rest_Admin
```
Expected: FAIL - `Class "Anchor\Courses\Rest\AdminController" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Rest/AdminController.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Rest;

use Anchor\Courses\Admin\LearnerReports;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Content\CoursePostType;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Reporting endpoints for staff (brief 14 admin routes).
 *
 * These expose learner PII, so each names the capability it needs: reports for
 * rosters and completions, credits for the CE ledger - not a single blanket
 * "is admin" check.
 */
final class AdminController {

	public function register_routes(): void {
		$reports = Routes::require_cap( 'reports' );
		$credits = Routes::require_cap( 'credits' );

		$range_args = [
			'course_id' => [ 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ],
			'from'      => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'to'        => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
		];

		\register_rest_route( Routes::NAMESPACE, '/admin/courses/(?P<id>\d+)/learners', [
			'methods'             => 'GET',
			'permission_callback' => $reports,
			'callback'            => [ $this, 'learners' ],
			'args'                => [
				'id'       => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
				'per_page' => [ 'type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint' ],
				'page'     => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
			],
		] );

		\register_rest_route( Routes::NAMESPACE, '/admin/users/(?P<id>\d+)/courses', [
			'methods'             => 'GET',
			'permission_callback' => $reports,
			'callback'            => [ $this, 'user_courses' ],
			'args'                => [ 'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ],
		] );

		\register_rest_route( Routes::NAMESPACE, '/admin/reports/completions', [
			'methods'             => 'GET',
			'permission_callback' => $reports,
			'callback'            => [ $this, 'completions' ],
			'args'                => $range_args,
		] );

		\register_rest_route( Routes::NAMESPACE, '/admin/reports/credits', [
			'methods'             => 'GET',
			'permission_callback' => $credits,
			'callback'            => [ $this, 'credits' ],
			'args'                => $range_args,
		] );
	}

	public function learners( \WP_REST_Request $request ): \WP_REST_Response {
		$course_id = (int) $request['id'];
		if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			return new \WP_REST_Response( [ 'code' => 'no_course', 'message' => \__( 'Not found.', 'anchor-schema' ) ], 404 );
		}

		$per_page = \max( 1, \min( 200, (int) $request['per_page'] ) );
		$offset   = ( \max( 1, (int) $request['page'] ) - 1 ) * $per_page;

		return new \WP_REST_Response( LearnerReports::rows( $course_id, $per_page, $offset ), 200 );
	}

	public function user_courses( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = (int) $request['id'];
		if ( ! \get_userdata( $user_id ) ) {
			return new \WP_REST_Response( [ 'code' => 'no_user', 'message' => \__( 'Not found.', 'anchor-schema' ) ], 404 );
		}

		return new \WP_REST_Response( LearnerReports::user_rows( $user_id ), 200 );
	}

	public function completions( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$sql    = 'SELECT user_id, course_id, completed_at FROM '
			. \Anchor\Courses\Database\Migrations::table( 'enrollments' )
			. " WHERE status = 'completed'";
		$params = [];

		if ( (int) $request['course_id'] > 0 ) {
			$sql     .= ' AND course_id = %d';
			$params[] = (int) $request['course_id'];
		}
		if ( '' !== (string) $request['from'] ) {
			$sql     .= ' AND completed_at >= %s';
			$params[] = (string) $request['from'] . ' 00:00:00';
		}
		if ( '' !== (string) $request['to'] ) {
			$sql     .= ' AND completed_at <= %s';
			$params[] = (string) $request['to'] . ' 23:59:59';
		}
		$sql .= ' ORDER BY completed_at DESC LIMIT 1000';

		$rows = [] === $params
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$out = [];
		foreach ( (array) $rows as $row ) {
			$user  = \get_userdata( (int) $row['user_id'] );
			$out[] = [
				'user_id'      => (int) $row['user_id'],
				'display_name' => $user ? (string) $user->display_name : '',
				'course_id'    => (int) $row['course_id'],
				'course_title' => (string) \get_the_title( (int) $row['course_id'] ),
				'completed_at' => (string) $row['completed_at'],
			];
		}

		return new \WP_REST_Response( [ 'total' => \count( $out ), 'rows' => $out ], 200 );
	}

	public function credits( \WP_REST_Request $request ): \WP_REST_Response {
		$course_id = (int) $request['course_id'];
		$from      = (string) $request['from'];
		$to        = (string) $request['to'];

		$credits = $course_id > 0
			? CreditRepository::for_course( $course_id )
			: $this->all_credits();

		$rows  = [];
		$total = 0.0;

		foreach ( $credits as $credit ) {
			if ( '' !== $from && $credit->awarded_at < $from . ' 00:00:00' ) {
				continue;
			}
			if ( '' !== $to && $credit->awarded_at > $to . ' 23:59:59' ) {
				continue;
			}

			$user   = \get_userdata( $credit->user_id );
			$total += $credit->credits;
			$rows[] = \array_merge(
				$credit->to_array(),
				[
					'display_name' => $user ? (string) $user->display_name : '',
					'user_email'   => $user ? (string) $user->user_email : '',
					'course_title' => (string) \get_the_title( $credit->course_id ),
				]
			);
		}

		return new \WP_REST_Response(
			[ 'total_credits' => \round( $total, 2 ), 'rows' => $rows ],
			200
		);
	}

	/** @return \Anchor\Courses\Domain\Credit[] */
	private function all_credits(): array {
		$out = [];
		foreach ( \get_posts(
			[ 'post_type' => CoursePostType::CPT, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ]
		) as $course_id ) {
			$out = \array_merge( $out, CreditRepository::for_course( (int) $course_id ) );
		}
		return $out;
	}
}
```

`anchor-courses/anchor-courses.php` - add to the `Rest\Routes` construction:

```php
			new Rest\AdminController()
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Rest_Admin
```
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Rest/AdminController.php anchor-courses/anchor-courses.php tests/test-courses-rest-admin.php
git commit -m "feat(courses): admin reporting REST routes behind per-capability gates"
```

---

### Task 35: dataLayer analytics integration

**Files:**
- Create: `anchor-courses/src/Integrations/Analytics.php`
- Modify: `anchor-courses/anchor-courses.php` - construct it
- Test: `tests/test-courses-analytics.php`

**Interfaces:**
- Consumes: every action from Tasks 14-29.
- Produces:
  - `Integrations\Analytics::EVENTS` - the brief 19 list mapped to hooks
  - `::queue( string $event, array $payload ): void` - stores in a user transient so the push survives the post-redirect
  - `::pending( int $user_id ): array`, `::flush( int $user_id ): array`
  - `::print_events(): void` - on `wp_footer`, prints one `<script>` with the queued pushes
  - `::payload_for( string $event, array $args ): array`
  - Filter: `anchor_courses_datalayer_event( array|null $payload, string $event, array $args )` - returning `null` drops the event

**IDs only.** The payload vocabulary is exactly `event`, `course_id`, `lesson_id`, `quiz_id`, `attempt_id`, `score`, `credits`, `certificate_id`. No name, no email, no display name, no certificate number (brief 19).

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - dataLayer events (brief 19).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Integrations\Analytics;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

/** @group courses */
class Test_Courses_Analytics extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner( [ 'display_name' => 'Ada Lovelace', 'user_email' => 'ada@example.test' ] );
		$this->course = $this->make_course( [ 'progression_mode' => 'free', 'ce_credits' => '2', 'certificate_enabled' => 1 ], 'Laser Safety' );
		$this->lesson = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
		wp_set_current_user( $this->user );
	}

	public function tear_down() {
		Analytics::flush( $this->user );
		remove_all_filters( 'anchor_courses_datalayer_event' );
		parent::tear_down();
	}

	public function test_every_brief_event_name_is_mapped() {
		$this->assertSame(
			[ 'course_enrolled', 'course_started', 'lesson_started', 'lesson_completed', 'quiz_started',
			  'quiz_completed', 'quiz_passed', 'quiz_failed', 'course_completed', 'ce_credit_awarded',
			  'certificate_generated' ],
			array_values( Analytics::EVENTS )
		);
	}

	public function test_enrolling_queues_a_course_enrolled_event() {
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		$queued = Analytics::pending( $this->user );

		$this->assertSame( 'course_enrolled', $queued[0]['event'] );
		$this->assertSame( $this->course, $queued[0]['course_id'] );
	}

	public function test_completing_a_lesson_queues_lesson_and_course_events() {
		$enrollments = new EnrollmentService();
		$progress    = new ProgressService( $enrollments );
		$progress->set_completion_service( $this->courses()->completion );

		$enrollments->enroll( $this->user, $this->course );
		$progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$names = array_column( Analytics::pending( $this->user ), 'event' );

		$this->assertContains( 'lesson_completed', $names );
		$this->assertContains( 'course_completed', $names );
		$this->assertContains( 'ce_credit_awarded', $names );
		$this->assertContains( 'certificate_generated', $names );
	}

	/** Brief 19: the hard rule. */
	public function test_no_payload_ever_contains_personal_data() {
		$enrollments = new EnrollmentService();
		$progress    = new ProgressService( $enrollments );
		$progress->set_completion_service( $this->courses()->completion );
		$enrollments->enroll( $this->user, $this->course );
		$progress->complete_lesson( $this->user, $this->course, $this->lesson );

		$json = (string) wp_json_encode( Analytics::pending( $this->user ) );

		$this->assertStringNotContainsString( 'Ada Lovelace', $json );
		$this->assertStringNotContainsString( 'ada@example.test', $json );
		$this->assertStringNotContainsString( 'Laser Safety', $json );
		$this->assertStringNotContainsString( 'user_id', $json );

		foreach ( Analytics::pending( $this->user ) as $payload ) {
			foreach ( array_keys( $payload ) as $key ) {
				$this->assertContains(
					$key,
					[ 'event', 'course_id', 'lesson_id', 'quiz_id', 'attempt_id', 'score', 'credits', 'certificate_id' ],
					"Unexpected dataLayer key {$key}"
				);
			}
		}
	}

	public function test_flush_empties_the_queue() {
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		$this->assertCount( 1, Analytics::flush( $this->user ) );
		$this->assertSame( [], Analytics::pending( $this->user ) );
	}

	public function test_the_footer_prints_one_script_with_the_pushes() {
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		ob_start();
		( new Analytics() )->print_events();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'window.dataLayer', $html );
		$this->assertStringContainsString( '"course_enrolled"', $html );
		$this->assertSame( 1, substr_count( $html, '<script' ) );
	}

	public function test_the_footer_prints_nothing_when_the_queue_is_empty() {
		ob_start();
		( new Analytics() )->print_events();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	public function test_the_filter_can_drop_an_event() {
		add_filter(
			'anchor_courses_datalayer_event',
			static fn( $payload, $event ) => 'course_enrolled' === $event ? null : $payload,
			10,
			3
		);

		( new EnrollmentService() )->enroll( $this->user, $this->course );

		$this->assertSame( [], Analytics::pending( $this->user ) );
	}

	public function test_nothing_is_queued_for_a_logged_out_visitor() {
		wp_set_current_user( 0 );
		( new EnrollmentService() )->enroll( $this->make_learner(), $this->course );

		ob_start();
		( new Analytics() )->print_events();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Analytics
```
Expected: FAIL - `Class "Anchor\Courses\Integrations\Analytics" not found`.

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Integrations/Analytics.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Integrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Browser analytics (brief 19).
 *
 * Listens to the module's own actions and queues a dataLayer push per learner.
 * The queue survives the post-redirect-get that every form action performs, so
 * "lesson_completed" fires on the page the learner actually lands on.
 *
 * IDs ONLY. No names, no emails, no course titles, not even the WordPress user
 * id - an analytics platform must not become a second copy of the roster.
 */
final class Analytics {

	/** hook suffix => dataLayer event name (brief 19 list, in order). */
	public const EVENTS = [
		'anchor_courses_enrolled'            => 'course_enrolled',
		'anchor_courses_course_started'      => 'course_started',
		'anchor_courses_lesson_started'      => 'lesson_started',
		'anchor_courses_lesson_completed'    => 'lesson_completed',
		'anchor_courses_quiz_started'        => 'quiz_started',
		'anchor_courses_quiz_submitted'      => 'quiz_completed',
		'anchor_courses_quiz_passed'         => 'quiz_passed',
		'anchor_courses_quiz_failed'         => 'quiz_failed',
		'anchor_courses_course_completed'    => 'course_completed',
		'anchor_courses_ce_credit_awarded'   => 'ce_credit_awarded',
		'anchor_courses_certificate_issued'  => 'certificate_generated',
	];

	/** The only keys a payload may carry. */
	private const ALLOWED_KEYS = [ 'event', 'course_id', 'lesson_id', 'quiz_id', 'attempt_id', 'score', 'credits', 'certificate_id' ];

	private const TRANSIENT_PREFIX = 'anchor_courses_dl_';

	public function __construct() {
		foreach ( self::EVENTS as $hook => $event ) {
			\add_action(
				$hook,
				static function ( ...$args ) use ( $event ): void {
					self::record( $event, $args );
				},
				20,
				4
			);
		}

		\add_action( 'wp_footer', [ $this, 'print_events' ], 30 );
	}

	/** Build a payload from a hook's positional arguments and queue it. */
	private static function record( string $event, array $args ): void {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$payload = self::payload_for( $event, $args );

		/**
		 * Filter (or drop) one dataLayer event.
		 *
		 * @param array|null $payload Return null to drop the event entirely.
		 * @param string     $event
		 * @param array      $args    The action's positional arguments.
		 */
		$payload = \apply_filters( 'anchor_courses_datalayer_event', $payload, $event, $args );

		if ( ! \is_array( $payload ) || [] === $payload ) {
			return;
		}

		self::queue( $event, $payload );
	}

	/**
	 * Map a hook's arguments to an IDs-only payload.
	 *
	 * The argument shapes come from the services that fire them; anything not on
	 * the allow-list is dropped rather than renamed.
	 */
	public static function payload_for( string $event, array $args ): array {
		$payload = [ 'event' => $event ];

		switch ( $event ) {
			case 'course_enrolled':
				// ( Enrollment $enrollment, int $user_id, int $course_id )
				$payload['course_id'] = (int) ( $args[2] ?? 0 );
				break;

			case 'course_started':
				// ( int $user_id, int $course_id, Enrollment $enrollment )
				$payload['course_id'] = (int) ( $args[1] ?? 0 );
				break;

			case 'lesson_started':
			case 'lesson_completed':
				// ( int $user_id, int $course_id, int $lesson_id, Progress $progress )
				$payload['course_id'] = (int) ( $args[1] ?? 0 );
				$payload['lesson_id'] = (int) ( $args[2] ?? 0 );
				break;

			case 'quiz_started':
				// ( QuizAttempt $attempt, int $user_id, int $quiz_id, int $course_id )
				$payload['quiz_id']    = (int) ( $args[2] ?? 0 );
				$payload['course_id']  = (int) ( $args[3] ?? 0 );
				$payload['attempt_id'] = isset( $args[0] ) && \is_object( $args[0] ) ? (int) $args[0]->id : 0;
				break;

			case 'quiz_completed':
			case 'quiz_passed':
			case 'quiz_failed':
				$payload['quiz_id']   = (int) ( $args[2] ?? 0 );
				$payload['course_id'] = (int) ( $args[3] ?? 0 );
				if ( isset( $args[0] ) && \is_object( $args[0] ) ) {
					$payload['attempt_id'] = (int) $args[0]->id;
					$payload['score']      = null === $args[0]->score ? 0 : (float) $args[0]->score;
				}
				break;

			case 'course_completed':
				// ( int $user_id, int $course_id, Enrollment $enrollment )
				$payload['course_id'] = (int) ( $args[1] ?? 0 );
				break;

			case 'ce_credit_awarded':
				// ( int $user_id, int $course_id, Credit $credit )
				$payload['course_id'] = (int) ( $args[1] ?? 0 );
				$payload['credits']   = isset( $args[2] ) && \is_object( $args[2] ) ? (float) $args[2]->credits : 0.0;
				break;

			case 'certificate_generated':
				// ( int $user_id, int $course_id, Certificate $certificate )
				$payload['course_id']      = (int) ( $args[1] ?? 0 );
				$payload['certificate_id'] = isset( $args[2] ) && \is_object( $args[2] ) ? (int) $args[2]->id : 0;
				break;
		}

		// Belt and braces: whatever a filter or a future hook adds, only the
		// allow-listed keys survive.
		return \array_intersect_key( $payload, \array_flip( self::ALLOWED_KEYS ) );
	}

	public static function queue( string $event, array $payload ): void {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$queued   = self::pending( $user_id );
		$queued[] = $payload;

		\set_transient( self::TRANSIENT_PREFIX . $user_id, $queued, 5 * MINUTE_IN_SECONDS );
	}

	/** @return array<int,array<string,mixed>> */
	public static function pending( int $user_id ): array {
		$queued = \get_transient( self::TRANSIENT_PREFIX . $user_id );
		return \is_array( $queued ) ? $queued : [];
	}

	/** Read and clear. @return array<int,array<string,mixed>> */
	public static function flush( int $user_id ): array {
		$queued = self::pending( $user_id );
		\delete_transient( self::TRANSIENT_PREFIX . $user_id );
		return $queued;
	}

	public function print_events(): void {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$events = self::flush( $user_id );
		if ( [] === $events ) {
			return;
		}

		echo "<script>window.dataLayer = window.dataLayer || [];";
		foreach ( $events as $payload ) {
			\printf(
				'window.dataLayer.push(%s);',
				\wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
			);
		}
		echo '</script>';
	}
}
```

`anchor-courses/anchor-courses.php`:

```php
		new Integrations\Analytics();
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Analytics
```
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Integrations/Analytics.php anchor-courses/anchor-courses.php tests/test-courses-analytics.php
git commit -m "feat(courses): IDs-only dataLayer events for the whole learner path"
```

---

### Task 36: The live_session lesson type

**Files:**
- Create: `anchor-courses/templates/live-session.php`
- Create: `anchor-courses/src/Integrations/Events.php` - the read-only consumer of the events module
- Modify: `anchor-courses/anchor-courses.php` - construct it
- Modify: `anchor-courses/src/Frontend/Shortcodes.php` - `render_lesson()` branches on `lesson_type`
- Test: `tests/test-courses-live-session.php`

**`Integrations\Events` is a reader, and nothing else.** It resolves sessions, a room URL and a stream state so a `live_session` lesson can render, and (Task 37) it may veto stream access. It does **not** enrol anybody: an event grants `anchor_event_{id}`, which is a prerequisite role, not an access role, so the Task 20 listener ignores it and no course is ever entered by attending an event (design spec 7 - if that is wanted later it belongs on the *event* as an "also grant these roles" field, not as a picker here).

**Interfaces:**
- Consumes (all guarded, deviation D5/D6):
  - `\Anchor\Events\Module::instance()` (may return `null`)
  - `->get_sessions( int $event_id ): array` - **today returns `{date,start_time,end_time,label}`; the parallel events plan adds `start_ts`, `end_ts`, `modality`, `stream_embed`.** Read defensively.
  - `->room_url( int $event_id ): string` - does not exist yet; `method_exists` guarded
  - `\Anchor\Events\Stream_State::for_event( int $event_id, int $now ): array` - does not exist yet; `class_exists` guarded
- Produces:
  - `Integrations\Events::available(): bool`
  - `::sessions( int $event_id ): array` - normalised `[['label','date','start_time','end_time','start_ts','end_ts','modality']]`, `start_ts` derived from `date`/`start_time` when the events module does not supply one
  - `::room_url( int $event_id ): string` - `''` when unavailable
  - `::stream_state( int $event_id ): array` - `['state' => 'unknown']` when `Stream_State` is absent
  - `Frontend\Shortcodes::render_live_session( int $lesson_id, int $course_id ): string`

When the events module is absent the lesson renders "Live session unavailable" and is treated as optional (design spec 3.3).

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - the live_session lesson type (design spec 3.3).
 *
 * The events-side symbols do not exist yet (deviation D6), so these tests pin
 * the DEGRADED path - which is the one that must never fatal - and skip the
 * integrated assertions until the events module ships them.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Integrations\Events;
use Anchor\Courses\Services\EnrollmentService;

/** @group courses */
class Test_Courses_Live_Session extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->lesson = $this->make_lesson(
			[ 'type' => 'live_session', 'event_id' => 4242, 'session_index' => 0, 'completion_mode' => 'manual' ],
			'Day 1 Livestream'
		);
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
		( new EnrollmentService() )->enroll( $this->user, $this->course );
		wp_set_current_user( $this->user );
	}

	public function test_room_url_is_empty_when_the_events_module_cannot_supply_one() {
		if ( Events::available() && method_exists( '\Anchor\Events\Module', 'room_url' ) ) {
			$this->markTestSkipped( 'The events module now provides room_url(); the integrated test covers it.' );
		}
		$this->assertSame( '', Events::room_url( 4242 ) );
	}

	public function test_stream_state_degrades_to_unknown_without_stream_state() {
		if ( class_exists( '\Anchor\Events\Stream_State' ) ) {
			$this->markTestSkipped( 'Stream_State exists; the integrated test covers it.' );
		}
		$this->assertSame( 'unknown', Events::stream_state( 4242 )['state'] );
	}

	public function test_sessions_returns_an_empty_list_for_an_unknown_event() {
		$this->assertSame( [], Events::sessions( 999999 ) );
	}

	public function test_sessions_derive_timestamps_from_the_legacy_row_shape() {
		$this->require_events();

		$event_id = $this->factory->post->create( [ 'post_type' => 'event', 'post_status' => 'publish' ] );
		update_post_meta(
			$event_id,
			'_anchor_event_sessions',
			[ [ 'date' => '2026-09-01', 'start_time' => '09:00', 'end_time' => '12:00', 'label' => 'Day 1' ] ]
		);

		$sessions = Events::sessions( $event_id );

		$this->assertCount( 1, $sessions );
		$this->assertSame( 'Day 1', $sessions[0]['label'] );
		$this->assertGreaterThan( 0, $sessions[0]['start_ts'], 'start_ts must be derived when the events module omits it.' );
		$this->assertArrayHasKey( 'modality', $sessions[0] );
	}

	public function test_the_lesson_renders_the_unavailable_message_when_events_is_absent() {
		if ( Events::available() ) {
			$this->markTestSkipped( 'The events module is active in this run.' );
		}

		$html = $this->courses()->shortcodes->render_live_session( $this->lesson, $this->course );

		$this->assertStringContainsString( 'unavailable', strtolower( $html ) );
	}

	public function test_the_lesson_renders_a_schedule_and_a_join_button_shape() {
		$html = $this->courses()->shortcodes->render_live_session( $this->lesson, $this->course );

		$this->assertStringContainsString( 'anchor-live-session', $html );
		$this->assertStringNotContainsString( '<iframe', $html, 'Courses never embeds the stream; the room is the only player.' );
	}

	public function test_a_live_session_lesson_is_still_markable_complete() {
		$progress = $this->courses()->progress->complete_lesson( $this->user, $this->course, $this->lesson );
		$this->assertNotWPError( $progress );
		$this->assertSame( 'completed', $progress->status );
	}

	public function test_render_lesson_routes_a_live_session_lesson_to_the_live_template() {
		$html = $this->courses()->shortcodes->render_lesson( $this->lesson );
		$this->assertStringContainsString( 'anchor-live-session', $html );
	}

	public function test_a_content_lesson_is_unaffected() {
		$plain = $this->make_lesson( [], 'Plain' );
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $plain ] ] ] ]
		);

		$html = $this->courses()->shortcodes->render_lesson( $plain );

		$this->assertStringNotContainsString( 'anchor-live-session', $html );
		$this->assertStringContainsString( 'anchor-lesson', $html );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Live_Session
```
Expected: FAIL - `Class "Anchor\Courses\Integrations\Events" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `anchor-courses/src/Integrations/Events.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Integrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The events module, seen from the courses side (design spec 3).
 *
 * A READER. It resolves an event's sessions, its room URL and its stream state
 * so a `live_session` lesson can render, and - once Task 37 adds it - it may
 * veto stream access until the learner's pre-work is done. That is the whole
 * surface.
 *
 * It does not enrol anybody, and there is no auto-enrolment from events. An
 * event grants `anchor_event_{id}`, which this module treats as a PREREQUISITE
 * role, never an access role: Support\Roles' listener matches
 * `anchor_course_{digits}` and nothing else, so an attendee never silently
 * lands inside a course (design spec 7).
 *
 * Courses NEVER queries event tables, seat posts or `_anchor_event_*` meta.
 * Every symbol it touches may be absent (deviation D5/D6), so every call is
 * guarded and every degraded path renders rather than fatals.
 */
final class Events {

	/** Is the events module booted in this request? */
	public static function available(): bool {
		return \class_exists( '\Anchor\Events\Module' )
			&& null !== \Anchor\Events\Module::instance();
	}

	/**
	 * Normalised session rows for an event.
	 *
	 * Reads \Anchor\Events\Module::get_sessions(), which TODAY returns
	 * {date, start_time, end_time, label} and which the parallel events plan
	 * extends with start_ts/end_ts/modality/stream_embed (deviation D5). Missing
	 * fields are derived here so this module reads one shape either way.
	 *
	 * @return array<int,array{label:string,date:string,start_time:string,end_time:string,start_ts:int,end_ts:int,modality:string}>
	 */
	public static function sessions( int $event_id ): array {
		if ( ! self::available() || $event_id <= 0 ) {
			return [];
		}

		$module = \Anchor\Events\Module::instance();
		if ( ! \method_exists( $module, 'get_sessions' ) ) {
			return [];
		}

		$rows = (array) $module->get_sessions( $event_id );
		$out  = [];

		foreach ( $rows as $row ) {
			if ( ! \is_array( $row ) ) {
				continue;
			}

			$date  = (string) ( $row['date'] ?? '' );
			$start = (string) ( $row['start_time'] ?? '' );
			$end   = (string) ( $row['end_time'] ?? '' );

			$start_ts = (int) ( $row['start_ts'] ?? 0 );
			if ( $start_ts <= 0 && '' !== $date ) {
				$start_ts = (int) \strtotime( $date . ' ' . ( '' === $start ? '00:00' : $start ) . ' UTC' );
			}

			$end_ts = (int) ( $row['end_ts'] ?? 0 );
			if ( $end_ts <= 0 && '' !== $date ) {
				$end_ts = (int) \strtotime( $date . ' ' . ( '' === $end ? '23:59' : $end ) . ' UTC' );
			}

			$out[] = [
				'label'      => (string) ( $row['label'] ?? '' ),
				'date'       => $date,
				'start_time' => $start,
				'end_time'   => $end,
				'start_ts'   => \max( 0, $start_ts ),
				'end_ts'     => \max( 0, $end_ts ),
				'modality'   => (string) ( $row['modality'] ?? 'in_person' ),
			];
		}

		return $out;
	}

	/** The event's room URL, or '' when the events module cannot supply one. */
	public static function room_url( int $event_id ): string {
		if ( ! self::available() || $event_id <= 0 ) {
			return '';
		}

		$module = \Anchor\Events\Module::instance();
		if ( ! \method_exists( $module, 'room_url' ) ) {
			return ''; // The parallel events plan adds this (deviation D6).
		}

		return (string) $module->room_url( $event_id );
	}

	/**
	 * The room's state machine result, or ['state' => 'unknown'].
	 *
	 * @return array{state:string,session_index?:int,target_ts?:int}
	 */
	public static function stream_state( int $event_id ): array {
		if ( ! self::available() || ! \class_exists( '\Anchor\Events\Stream_State' ) ) {
			return [ 'state' => 'unknown' ];
		}

		$state = \Anchor\Events\Stream_State::for_event( $event_id, \Anchor\Courses\Support\Clock::timestamp() );

		return \is_array( $state ) ? $state : [ 'state' => 'unknown' ];
	}
}
```

`anchor-courses/anchor-courses.php` - construct it unconditionally. It is guarded from the inside, so a site with the events module disabled simply gets `available() === false`:

```php
		new Integrations\Events();
```

Add to `Frontend\Shortcodes`:

```php
	/** Render a live_session lesson (design spec 3.3). */
	public function render_live_session( int $lesson_id, int $course_id ): string {
		$event_id = (int) \Anchor\Courses\Admin\LessonEditor::setting( $lesson_id, 'event_id' );

		return Templates::render(
			'live-session',
			[
				'lesson_id' => $lesson_id,
				'course_id' => $course_id,
				'event_id'  => $event_id,
				'available' => \Anchor\Courses\Integrations\Events::available() && $event_id > 0,
				'sessions'  => \Anchor\Courses\Integrations\Events::sessions( $event_id ),
				'room_url'  => \Anchor\Courses\Integrations\Events::room_url( $event_id ),
				'state'     => \Anchor\Courses\Integrations\Events::stream_state( $event_id ),
			]
		);
	}
```

and, in `render_lesson()`, immediately after `$course_id` is resolved:

```php
		if ( 'live_session' === (string) \Anchor\Courses\Admin\LessonEditor::setting( $lesson_id, 'type' ) ) {
			return $this->render_live_session( $lesson_id, $course_id );
		}
```

`anchor-courses/templates/live-session.php`:

```php
<?php
/**
 * A live_session lesson (design spec 3.3).
 *
 * This page shows the SCHEDULE and a link to the event room. It deliberately
 * does NOT embed the stream: the events module's room is the only player, and
 * duplicating it here would mean two places to get entitlement wrong.
 *
 * Variables: $lesson_id, $course_id, $event_id, $available (bool),
 * $sessions (array), $room_url (string), $state (array).
 *
 * Theme override: anchor-courses/live-session.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Frontend\Actions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$notice = Actions::notice();
?>
<div class="anchor-lesson anchor-live-session" data-lesson="<?php echo esc_attr( (string) $lesson_id ); ?>" data-event="<?php echo esc_attr( (string) $event_id ); ?>">

	<?php if ( '' !== $notice ) : ?>
		<p class="anchor-courses-notice"><?php echo esc_html( Actions::notice_text( $notice ) ); ?></p>
	<?php endif; ?>

	<nav class="anchor-lesson-breadcrumb">
		<a href="<?php echo esc_url( (string) get_permalink( $course_id ) ); ?>"><?php echo esc_html( get_the_title( $course_id ) ); ?></a>
		<span class="anchor-lesson-breadcrumb-sep">/</span>
		<span><?php echo esc_html( get_the_title( $lesson_id ) ); ?></span>
	</nav>

	<h2 class="anchor-live-session-title"><?php echo esc_html( get_the_title( $lesson_id ) ); ?></h2>

	<?php if ( ! $available ) : ?>
		<p class="anchor-courses-notice"><?php esc_html_e( 'Live session unavailable.', 'anchor-schema' ); ?></p>
	<?php else : ?>

		<div class="anchor-lesson-content"><?php echo wp_kses_post( apply_filters( 'the_content', get_post_field( 'post_content', $lesson_id ) ) ); ?></div>

		<?php if ( ! empty( $sessions ) ) : ?>
			<table class="anchor-live-session-schedule">
				<caption><?php esc_html_e( 'Schedule', 'anchor-schema' ); ?></caption>
				<tbody>
				<?php foreach ( $sessions as $session ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( '' === $session['label'] ? $session['date'] : $session['label'] ); ?></th>
						<td>
							<?php
							echo esc_html(
								$session['start_ts'] > 0
									? wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $session['start_ts'] )
									: $session['date'] . ' ' . $session['start_time']
							);
							?>
						</td>
						<td class="anchor-live-session-modality"><?php echo esc_html( $session['modality'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( '' !== $room_url ) : ?>
			<p class="anchor-live-session-join">
				<a class="anchor-courses-button" href="<?php echo esc_url( $room_url ); ?>">
					<?php esc_html_e( 'Join the livestream', 'anchor-schema' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p class="anchor-courses-notice"><?php esc_html_e( 'The livestream link will appear here before the session starts.', 'anchor-schema' ); ?></p>
		<?php endif; ?>

		<?php if ( 'unknown' !== ( $state['state'] ?? 'unknown' ) ) : ?>
			<p class="anchor-live-session-state" data-state="<?php echo esc_attr( (string) $state['state'] ); ?>">
				<?php echo esc_html( (string) $state['state'] ); ?>
			</p>
		<?php endif; ?>

	<?php endif; ?>

	<form class="anchor-lesson-complete" method="post" action="<?php echo esc_url( Actions::complete_url( $course_id, $lesson_id ) ); ?>">
		<?php wp_nonce_field( Actions::NONCE_COMPLETE . '_' . $lesson_id ); ?>
		<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $course_id ); ?>" />
		<input type="hidden" name="lesson_id" value="<?php echo esc_attr( (string) $lesson_id ); ?>" />
		<input type="hidden" name="_redirect" value="<?php echo esc_url( (string) get_permalink( $lesson_id ) ); ?>" />
		<button type="submit" class="anchor-courses-button"><?php esc_html_e( 'Mark attended', 'anchor-schema' ); ?></button>
	</form>
</div>
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Live_Session
```
Expected: PASS (9 tests; some skip while the events symbols are absent).

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Integrations/Events.php anchor-courses/src/Frontend/Shortcodes.php \
        anchor-courses/anchor-courses.php anchor-courses/templates/live-session.php \
        tests/test-courses-live-session.php
git commit -m "feat(courses): live_session lesson type reading the events module defensively"
```

---

### Task 37: Pre-work veto on stream access

**Files:**
- Modify: `anchor-courses/src/Integrations/Events.php` - add `veto_stream_access()`
- Test: `tests/test-courses-stream-veto.php`

**Interfaces:**
- Consumes: `apply_filters( 'anchor_events_can_access_stream', $allowed, $event_id, $session_index, $user_id )` (does not exist yet - deviation D6; the listener is registered regardless and simply never called until the events module adds the filter).
- Produces:
  - `Integrations\Events::veto_stream_access( bool $allowed, int $event_id, int $session_index = 0, int $user_id = 0 ): bool`
  - `::live_lessons_for_event( int $event_id ): array` - `[['lesson_id','course_id','session_index']]` for lessons with `require_prior_items = 1`

Off by default: a `live_session` lesson only vetoes when its `require_prior_items` box is ticked. The veto can only turn `true` into `false` - it never grants access the events module refused.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - the optional pre-work veto on stream access
 * (design spec 3.2, events spec 4.5).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Integrations\Events;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

/** @group courses */
class Test_Courses_Stream_Veto extends Anchor_Courses_TestCase {

	private const EVENT_ID = 4242;

	private ProgressService $progress;
	private int $user;
	private int $course;
	private int $prework;
	private int $live;

	public function set_up() {
		parent::set_up();
		$enrollments    = new EnrollmentService();
		$this->progress = new ProgressService( $enrollments );

		$this->user    = $this->make_learner();
		$this->course  = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->prework = $this->make_lesson( [], 'Pre-work' );
		$this->live    = $this->make_lesson(
			[ 'type' => 'live_session', 'event_id' => self::EVENT_ID, 'session_index' => 0, 'require_prior_items' => 1 ],
			'Day 1 Livestream'
		);

		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->prework ],
				[ 'type' => 'lesson', 'id' => $this->live ],
			] ] ]
		);

		$enrollments->enroll( $this->user, $this->course );
	}

	public function test_live_lessons_for_event_finds_only_gated_lessons() {
		$ungated = $this->make_lesson( [ 'type' => 'live_session', 'event_id' => self::EVENT_ID, 'require_prior_items' => 0 ], 'Ungated' );
		$course  = $this->make_course();
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $ungated ] ] ] ] );

		$rows = Events::live_lessons_for_event( self::EVENT_ID );

		$this->assertSame( [ $this->live ], array_column( $rows, 'lesson_id' ) );
	}

	public function test_access_is_vetoed_until_the_prework_is_complete() {
		$this->assertFalse(
			Events::veto_stream_access( true, self::EVENT_ID, 0, $this->user ),
			'Unfinished pre-work must block the stream.'
		);

		$this->progress->complete_lesson( $this->user, $this->course, $this->prework );

		$this->assertTrue( Events::veto_stream_access( true, self::EVENT_ID, 0, $this->user ) );
	}

	public function test_the_veto_never_grants_access_that_was_already_refused() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->prework );

		$this->assertFalse(
			Events::veto_stream_access( false, self::EVENT_ID, 0, $this->user ),
			'A false stays false: courses may only subtract access.'
		);
	}

	public function test_an_ungated_live_lesson_never_vetoes() {
		update_post_meta( $this->live, '_anchor_lesson_require_prior_items', 0 );

		$this->assertTrue( Events::veto_stream_access( true, self::EVENT_ID, 0, $this->user ) );
	}

	public function test_an_event_with_no_live_lessons_is_untouched() {
		$this->assertTrue( Events::veto_stream_access( true, 999999, 0, $this->user ) );
	}

	public function test_a_learner_who_is_not_enrolled_is_not_vetoed() {
		$stranger = $this->make_learner();

		$this->assertTrue(
			Events::veto_stream_access( true, self::EVENT_ID, 0, $stranger ),
			'Someone who is not on the course has no pre-work to finish.'
		);
	}

	public function test_the_session_index_is_respected() {
		update_post_meta( $this->live, '_anchor_lesson_session_index', 1 );

		$this->assertTrue( Events::veto_stream_access( true, self::EVENT_ID, 0, $this->user ), 'Session 0 has no gated lesson.' );
		$this->assertFalse( Events::veto_stream_access( true, self::EVENT_ID, 1, $this->user ) );
	}

	public function test_the_filter_is_registered_so_events_can_call_it() {
		$this->assertNotFalse(
			has_filter( 'anchor_events_can_access_stream' ),
			'The veto must be attached even before the events module fires it.'
		);
	}

	public function test_a_zero_user_id_is_treated_as_the_current_user() {
		wp_set_current_user( $this->user );
		$this->assertFalse( Events::veto_stream_access( true, self::EVENT_ID, 0, 0 ) );

		$this->progress->complete_lesson( $this->user, $this->course, $this->prework );
		$this->assertTrue( Events::veto_stream_access( true, self::EVENT_ID, 0, 0 ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Stream_Veto
```
Expected: FAIL - `Call to undefined method ...Events::live_lessons_for_event()`.

- [ ] **Step 3: Write minimal implementation**

Give `anchor-courses/src/Integrations/Events.php` a constructor (Task 36 left the class stateless and static):

```php
	public function __construct() {
		// The events module's single "may this person watch?" filter (events
		// spec 4.5). It does not exist yet (deviation D6); attaching to a filter
		// nobody applies is free, and the day it ships this starts working.
		\add_filter( 'anchor_events_can_access_stream', [ $this, 'veto_stream_access' ], 10, 4 );
	}
```

and the two methods:

```php
	/**
	 * Live-session lessons that gate stream access on prior items.
	 *
	 * @return array<int,array{lesson_id:int,course_id:int,session_index:int}>
	 */
	public static function live_lessons_for_event( int $event_id ): array {
		if ( $event_id <= 0 ) {
			return [];
		}

		$lessons = \get_posts(
			[
				'post_type'      => LessonPostType::CPT,
				'post_status'    => [ 'publish', 'private' ],
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					[ 'key' => LessonPostType::meta_key( 'event_id' ), 'value' => $event_id, 'type' => 'NUMERIC' ],
					[ 'key' => LessonPostType::meta_key( 'require_prior_items' ), 'value' => '1' ],
				],
			]
		);

		$rows = [];
		foreach ( $lessons as $lesson_id ) {
			$lesson_id = (int) $lesson_id;
			if ( 'live_session' !== (string) LessonEditor::setting( $lesson_id, 'type' ) ) {
				continue;
			}
			$course_id = Curriculum::course_for_item( $lesson_id, 'lesson' );
			if ( $course_id <= 0 ) {
				continue;
			}
			$rows[] = [
				'lesson_id'     => $lesson_id,
				'course_id'     => $course_id,
				'session_index' => (int) LessonEditor::setting( $lesson_id, 'session_index' ),
			];
		}

		return $rows;
	}

	/**
	 * `anchor_events_can_access_stream` (events spec 4.5).
	 *
	 * Courses may only SUBTRACT access: a false stays false. When a
	 * live_session lesson for this event and session has require_prior_items
	 * ticked, every required item ordered before it must be complete.
	 */
	public function veto_stream_access( $allowed, $event_id, $session_index = 0, $user_id = 0 ): bool {
		if ( ! $allowed ) {
			return false;
		}

		$user_id = (int) $user_id > 0 ? (int) $user_id : \get_current_user_id();
		if ( $user_id <= 0 ) {
			return (bool) $allowed;
		}

		$progress = new ProgressService( $this->enrollments );

		foreach ( self::live_lessons_for_event( (int) $event_id ) as $row ) {
			if ( $row['session_index'] !== (int) $session_index ) {
				continue;
			}
			if ( ! $this->enrollments->is_enrolled( $user_id, $row['course_id'] ) ) {
				continue; // Not on the course: no pre-work to owe.
			}

			$completed = $progress->get_completed_items( $user_id, $row['course_id'] );

			foreach ( Curriculum::items_before( $row['course_id'], $row['lesson_id'], 'lesson' ) as $earlier ) {
				if ( ! $earlier['required'] ) {
					continue;
				}
				if ( ! \in_array( $earlier['type'] . ':' . $earlier['id'], $completed, true ) ) {
					return false;
				}
			}
		}

		return true;
	}
```

Add the imports at the top of the file:

```php
use Anchor\Courses\Admin\LessonEditor;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Services\ProgressService;
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Stream_Veto
vendor/bin/phpunit --group courses
```
Expected: PASS (9 tests), and the whole `courses` group still green.

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Integrations/Events.php tests/test-courses-stream-veto.php
git commit -m "feat(courses): optional pre-work veto on event stream access"
```

---

## Phase 6 - WooCommerce Adapter

### Task 38: Product-to-course mapping

**Files:**
- Create: `anchor-courses/src/Integrations/WooCommerce.php`
- Modify: `anchor-courses/anchor-courses.php` - construct it when `class_exists( 'WooCommerce' )`
- Test: `tests/test-courses-wc-mapping.php`

**Interfaces:**
- Produces:
  - `Integrations\WooCommerce::META_KEY = '_anchor_course_ids'` (product meta, `int[]`)
  - `Integrations\WooCommerce::NONCE = 'anchor_courses_product_nonce'`
  - `::available(): bool`
  - `::courses_for_product( int $product_id ): int[]`
  - `::set_courses_for_product( int $product_id, array $course_ids ): int[]`
  - `::render_product_panel(): void` - a "Courses" panel in the product data metabox
  - `::add_product_tab( array $tabs ): array`
  - `::save_product( int $product_id ): void` - on `woocommerce_process_product_meta`
  - `::products_for_course( int $course_id ): int[]` - the reverse lookup, purchasable products first
  - `::filter_access_cta( array $cta, int $course_id, int $user_id ): array` - on `anchor_courses_access_cta`

Core LMS must remain usable without WooCommerce (brief rule 2): nothing outside this file mentions WooCommerce, and the class is only constructed when `class_exists( 'WooCommerce' )`.

**This is also where the course page's "Enrol" button comes from.** `Frontend\Access::cta()` (Task 18) returns "Ask us about access" and lets anything filter it into a link; this adapter is the only thing in the repo that does, supplying the mapped product's permalink. That is why the core template has no `class_exists( 'WooCommerce' )` branch: the store is a subscriber to a question the core asks, not a dependency of it.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - WooCommerce product/course mapping (brief 18, design spec 4).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Integrations\WooCommerce;

/** @group courses @group woocommerce */
class Test_Courses_Wc_Mapping extends Anchor_Courses_TestCase {

	private int $product;
	private int $course_a;
	private int $course_b;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this run.' );
		}
		$this->product  = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		$this->course_a = $this->make_course( [], 'Course A' );
		$this->course_b = $this->make_course( [], 'Course B' );
	}

	public function test_available_reports_woocommerce() {
		$this->assertTrue( WooCommerce::available() );
	}

	public function test_setting_and_reading_the_mapping() {
		WooCommerce::set_courses_for_product( $this->product, [ $this->course_a, $this->course_b ] );

		$this->assertSame( [ $this->course_a, $this->course_b ], WooCommerce::courses_for_product( $this->product ) );
	}

	public function test_an_unmapped_product_returns_an_empty_array() {
		$this->assertSame( [], WooCommerce::courses_for_product( $this->product ) );
	}

	public function test_non_course_ids_and_duplicates_are_dropped() {
		$lesson = $this->make_lesson();

		$saved = WooCommerce::set_courses_for_product(
			$this->product,
			[ $this->course_a, $lesson, 0, -5, $this->course_a, 999999 ]
		);

		$this->assertSame( [ $this->course_a ], $saved );
	}

	public function test_saving_an_empty_list_clears_the_mapping() {
		WooCommerce::set_courses_for_product( $this->product, [ $this->course_a ] );
		WooCommerce::set_courses_for_product( $this->product, [] );

		$this->assertSame( [], WooCommerce::courses_for_product( $this->product ) );
	}

	public function test_the_product_meta_save_handler_honours_the_nonce_and_capability() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$_POST = [
			WooCommerce::NONCE     => wp_create_nonce( WooCommerce::NONCE ),
			'anchor_course_ids'    => [ (string) $this->course_a ],
		];
		( new WooCommerce() )->save_product( $this->product );
		$_POST = [];

		$this->assertSame( [ $this->course_a ], WooCommerce::courses_for_product( $this->product ) );

		// Without the nonce nothing changes.
		$_POST = [ 'anchor_course_ids' => [ (string) $this->course_b ] ];
		( new WooCommerce() )->save_product( $this->product );
		$_POST = [];

		$this->assertSame( [ $this->course_a ], WooCommerce::courses_for_product( $this->product ) );
	}

	public function test_a_courses_tab_is_added_to_the_product_data_metabox() {
		$tabs = ( new WooCommerce() )->add_product_tab( [] );
		$this->assertArrayHasKey( 'anchor_courses', $tabs );
		$this->assertSame( 'anchor_courses_product_data', $tabs['anchor_courses']['target'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Wc_Mapping
```
Expected: FAIL - `Class "Anchor\Courses\Integrations\WooCommerce" not found` (or SKIP without WooCommerce).

- [ ] **Step 3: Write minimal implementation**

`anchor-courses/src/Integrations/WooCommerce.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Courses\Integrations;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Support\Capabilities;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The WooCommerce adapter (brief 18, rule 2).
 *
 * Every mention of WooCommerce in this module lives in this file, and the class
 * is only constructed when class_exists('WooCommerce'). Removing WooCommerce
 * removes the adapter and nothing else.
 *
 * It grants and removes the course's ACCESS ROLE; it never writes an enrolment
 * row itself. Support\Roles' listener turns the role into the row, with
 * source=woocommerce and the order id, so a purchase, an admin and a WP-CLI
 * `user add-role` all produce the same shape (design spec 3.1, 4).
 */
final class WooCommerce {

	/** Product meta: the courses this product grants. */
	public const META_KEY = '_anchor_course_ids';

	public const NONCE = 'anchor_courses_product_nonce';

	public function __construct() {
		\add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_tab' ] );
		\add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_panel' ] );
		\add_action( 'woocommerce_process_product_meta', [ $this, 'save_product' ] );

		// Turn the course page's "Ask us about access" line into a real link
		// when something on this store sells the course.
		\add_filter( 'anchor_courses_access_cta', [ $this, 'filter_access_cta' ], 10, 3 );
	}

	public static function available(): bool {
		return \class_exists( 'WooCommerce' );
	}

	/** @return int[] */
	public static function courses_for_product( int $product_id ): array {
		$stored = \get_post_meta( $product_id, self::META_KEY, true );
		if ( ! \is_array( $stored ) ) {
			return [];
		}
		return \array_values( \array_map( 'intval', $stored ) );
	}

	/**
	 * Store the mapping, keeping only ids that are really courses.
	 *
	 * @return int[] What was actually stored.
	 */
	public static function set_courses_for_product( int $product_id, array $course_ids ): array {
		$clean = [];

		foreach ( $course_ids as $course_id ) {
			$course_id = (int) $course_id;
			if ( $course_id <= 0 || \in_array( $course_id, $clean, true ) ) {
				continue;
			}
			if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
				continue;
			}
			$clean[] = $course_id;
		}

		if ( [] === $clean ) {
			\delete_post_meta( $product_id, self::META_KEY );
		} else {
			\update_post_meta( $product_id, self::META_KEY, $clean );
		}

		return $clean;
	}

	public function add_product_tab( array $tabs ): array {
		$tabs['anchor_courses'] = [
			'label'    => \__( 'Courses', 'anchor-schema' ),
			'target'   => 'anchor_courses_product_data',
			'class'    => [],
			'priority' => 65,
		];
		return $tabs;
	}

	public function render_product_panel(): void {
		global $post;

		$product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$selected   = self::courses_for_product( $product_id );

		echo '<div id="anchor_courses_product_data" class="panel woocommerce_options_panel">';
		\wp_nonce_field( self::NONCE, self::NONCE );
		echo '<div class="options_group">';
		echo '<p class="form-field"><label for="anchor_course_ids">' . \esc_html__( 'Grant these courses', 'anchor-schema' ) . '</label>';
		echo '<select id="anchor_course_ids" name="anchor_course_ids[]" multiple size="8" style="width:100%;max-width:26em;">';

		$courses = \get_posts(
			[
				'post_type'      => CoursePostType::CPT,
				'post_status'    => [ 'publish', 'private' ],
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		foreach ( $courses as $course ) {
			\printf(
				'<option value="%d"%s>%s</option>',
				(int) $course->ID,
				\in_array( (int) $course->ID, $selected, true ) ? ' selected="selected"' : '',
				\esc_html( (string) $course->post_title )
			);
		}

		echo '</select>';
		echo '<span class="description">' . \esc_html__( 'Buyers are enrolled automatically when the order reaches a qualifying status.', 'anchor-schema' ) . '</span>';
		echo '</p></div></div>';
	}

	public function save_product( $product_id ): void {
		$product_id = (int) $product_id;

		$nonce = isset( $_POST[ self::NONCE ] ) ? \sanitize_text_field( \wp_unslash( (string) $_POST[ self::NONCE ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! \current_user_can( 'edit_post', $product_id ) && ! Capabilities::current_user_can( 'manage' ) ) {
			return;
		}

		$ids = isset( $_POST['anchor_course_ids'] ) && \is_array( $_POST['anchor_course_ids'] )
			? \wp_unslash( $_POST['anchor_course_ids'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: [];

		self::set_courses_for_product( $product_id, (array) $ids );
	}

	/**
	 * Which products grant this course?
	 *
	 * The reverse of the mapping, by meta query. Purchasable products come
	 * first, so the course page links at something the visitor can actually
	 * buy rather than a draft or an out-of-stock variant.
	 *
	 * @return int[] Product ids.
	 */
	public static function products_for_course( int $course_id ): array {
		if ( ! self::available() || $course_id <= 0 ) {
			return [];
		}

		$products = \get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				// The mapping is a serialised int[], so LIKE on the serialised
				// integer is the only index-friendly test. The exact id is
				// re-checked in PHP below, which is what makes a substring
				// match (12 inside 123) harmless.
				'meta_query'     => [
					[
						'key'     => self::META_KEY,
						'value'   => ';i:' . $course_id . ';',
						'compare' => 'LIKE',
					],
				],
			]
		);

		$matches = [];
		foreach ( $products as $product_id ) {
			if ( \in_array( $course_id, self::courses_for_product( (int) $product_id ), true ) ) {
				$matches[] = (int) $product_id;
			}
		}

		\usort(
			$matches,
			static function ( int $a, int $b ): int {
				$pa = \function_exists( 'wc_get_product' ) ? \wc_get_product( $a ) : null;
				$pb = \function_exists( 'wc_get_product' ) ? \wc_get_product( $b ) : null;
				$sa = ( $pa && $pa->is_purchasable() ) ? 0 : 1;
				$sb = ( $pb && $pb->is_purchasable() ) ? 0 : 1;
				return $sa <=> $sb;
			}
		);

		return $matches;
	}

	/**
	 * Supply the course page's access CTA (Task 18).
	 *
	 * An "Enrol" button appears on a course page for exactly one reason: a
	 * product on this store grants it. No product, no button - the visitor
	 * gets the "ask us" line instead, because there is nothing for them to
	 * click that would work.
	 *
	 * @param array{url:string,label:string,message:string} $cta
	 * @return array{url:string,label:string,message:string}
	 */
	public function filter_access_cta( array $cta, int $course_id, int $user_id = 0 ): array {
		$products = self::products_for_course( $course_id );
		if ( [] === $products ) {
			return $cta;
		}

		$permalink = (string) \get_permalink( $products[0] );
		if ( '' === $permalink ) {
			return $cta;
		}

		return [
			'url'     => $permalink,
			'label'   => \__( 'Enrol', 'anchor-schema' ),
			'message' => '',
		];
	}
}
```

`anchor-courses/anchor-courses.php`:

```php
		if ( \class_exists( 'WooCommerce' ) ) {
			$this->woocommerce = new Integrations\WooCommerce();
		}
```

with the property:

```php
	public ?Integrations\WooCommerce $woocommerce = null;
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Wc_Mapping
```
Expected: PASS (7 tests), or SKIP when WooCommerce is absent.

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Integrations/WooCommerce.php anchor-courses/anchor-courses.php tests/test-courses-wc-mapping.php
git commit -m "feat(courses): map WooCommerce products to courses"
```

---

### Task 39: Grant access on a qualifying order status

**Files:**
- Modify: `anchor-courses/src/Integrations/WooCommerce.php` - add the order listeners
- Test: `tests/test-courses-wc-enrolment.php`

**Interfaces:**
- Produces:
  - `WooCommerce::ENROLL_STATUSES = [ 'processing', 'completed' ]`
  - `::qualifying_statuses(): array` - the constant through `anchor_courses_wc_enroll_statuses`
  - `::on_order_status_changed( int $order_id, string $from, string $to, $order = null ): void`
  - `::enroll_order( int $order_id ): int` - returns how many access roles were newly granted; idempotent
  - `::customer_for_order( $order ): int`
  - Filter: `anchor_courses_wc_enroll_statuses( string[] $statuses )`
- Consumes: `Support\Roles::grant_access( int $user_id, int $course_id, string $source, string $source_id = '' )` (Task 19), which asks `EnrollmentService::can_enroll()` first.

**The adapter grants the role. It never calls `enroll()`.** Support\Roles' listener sees `add_user_role`, reads the grant context and writes the enrolment row with `source = woocommerce` and `source_id = {order_id}` (design spec 4, brief 18). This is why the tests below assert on the *enrolment row* after granting: the row appearing is the proof that the one door works, and if the listener ever stopped firing these tests would fail rather than the adapter quietly writing its own row.

**An unmet prerequisite blocks the purchase path too.** `grant_access()` returns the `missing_prerequisite` error, the adapter grants nothing and records a `blocked_prerequisite` note on the order (`$order->add_order_note()`) so a human can see why the buyer did not get in. It does **not** refund, cancel or fail the order: money changed hands, and deciding what to do about that is the shop manager's call, not this adapter's.

This store confirms event tickets on `processing`, so both `processing` and `completed` qualify by default.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - WooCommerce order-driven enrolment (brief 18, design spec 4).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Integrations\WooCommerce;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Roles;

/** @group courses @group woocommerce */
class Test_Courses_Wc_Enrolment extends Anchor_Courses_TestCase {

	private EnrollmentService $enrollments;
	private int $customer;
	private int $course;
	private int $product;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this run.' );
		}

		$this->enrollments = new EnrollmentService();
		$this->customer    = $this->make_learner( [ 'role' => 'customer' ] );
		$this->course      = $this->make_course( [], 'Paid Course' );
		$this->product     = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );

		WooCommerce::set_courses_for_product( $this->product, [ $this->course ] );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_wc_enroll_statuses' );
		remove_role( Roles::access_slug( $this->course ) );
		parent::tear_down();
	}

	/** @return \WC_Order */
	private function make_order( string $status = 'pending' ) {
		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	public function test_the_default_qualifying_statuses() {
		$this->assertSame( [ 'processing', 'completed' ], WooCommerce::qualifying_statuses() );
	}

	public function test_the_statuses_filter_is_respected() {
		add_filter( 'anchor_courses_wc_enroll_statuses', static fn() => [ 'completed' ] );
		$this->assertSame( [ 'completed' ], WooCommerce::qualifying_statuses() );
	}

	/**
	 * The adapter grants the ROLE; the listener writes the row. Asserting on
	 * the row is what proves the two halves are still joined up.
	 */
	public function test_processing_grants_the_role_and_the_listener_records_the_order() {
		$order = $this->make_order( 'pending' );

		( new WooCommerce() )->on_order_status_changed( $order->get_id(), 'pending', 'processing', $order );

		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );

		$enrollment = $this->enrollments->get( $this->customer, $this->course );
		$this->assertNotNull( $enrollment, 'The role listener must have created the enrolment row.' );
		$this->assertSame( 'woocommerce', $enrollment->source );
		$this->assertSame( (string) $order->get_id(), $enrollment->source_id );
	}

	public function test_completed_also_grants() {
		$order = $this->make_order( 'pending' );

		( new WooCommerce() )->on_order_status_changed( $order->get_id(), 'pending', 'completed', $order );

		$this->assertTrue( $this->enrollments->is_enrolled( $this->customer, $this->course ) );
	}

	/** An unmet prerequisite refuses the grant and says so on the order. */
	public function test_an_unmet_prerequisite_blocks_the_grant_and_notes_the_order() {
		add_role( 'anchor_course_555_completed', 'Completed: Required First', [] );
		update_post_meta( $this->course, '_anchor_course_prerequisites', [ 'anchor_course_555_completed' ] );

		$order = $this->make_order( 'pending' );
		( new WooCommerce() )->on_order_status_changed( $order->get_id(), 'pending', 'processing', $order );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertNull( $this->enrollments->get( $this->customer, $this->course ) );

		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$this->assertNotEmpty( $notes );
		$this->assertStringContainsString( 'blocked_prerequisite', $notes[0]->content );

		// The order itself is untouched: refunding is a human decision.
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );

		remove_role( 'anchor_course_555_completed' );
	}

	public function test_a_non_qualifying_status_enrols_nobody() {
		$order = $this->make_order( 'pending' );

		( new WooCommerce() )->on_order_status_changed( $order->get_id(), 'pending', 'on-hold', $order );

		$this->assertFalse( $this->enrollments->is_enrolled( $this->customer, $this->course ) );
	}

	/** Brief 26: processing then completed must not enrol twice. */
	public function test_enrolment_is_idempotent_across_status_changes() {
		$order   = $this->make_order( 'pending' );
		$adapter = new WooCommerce();

		$first  = $adapter->enroll_order( $order->get_id() );
		$order->set_status( 'completed' );
		$order->save();
		$second = $adapter->enroll_order( $order->get_id() );

		$this->assertSame( 1, $first );
		$this->assertSame( 0, $second, 'A repeat pass must create nothing new.' );
	}

	public function test_an_order_with_no_mapped_products_does_nothing() {
		$plain = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$order->add_product( wc_get_product( $plain ), 1 );
		$order->set_status( 'processing' );
		$order->save();

		$this->assertSame( 0, ( new WooCommerce() )->enroll_order( $order->get_id() ) );
	}

	public function test_a_guest_order_with_no_customer_enrols_nobody() {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->set_status( 'processing' );
		$order->save();

		$this->assertSame( 0, ( new WooCommerce() )->enroll_order( $order->get_id() ) );
	}

	public function test_one_product_may_grant_several_courses() {
		$second = $this->make_course( [], 'Second' );
		WooCommerce::set_courses_for_product( $this->product, [ $this->course, $second ] );
		$order = $this->make_order( 'pending' );

		$this->assertSame( 2, ( new WooCommerce() )->enroll_order( $order->get_id() ) );
		$this->assertTrue( $this->enrollments->is_enrolled( $this->customer, $second ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Wc_Enrolment
```
Expected: FAIL - `Call to undefined method ...WooCommerce::qualifying_statuses()`.

- [ ] **Step 3: Write minimal implementation**

Add to `anchor-courses/src/Integrations/WooCommerce.php` - constructor:

```php
		\add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 4 );
```

and the methods:

```php
	/**
	 * Order statuses that grant access.
	 *
	 * Both `processing` and `completed` by default: this store confirms event
	 * tickets on `processing`, and a learner who has paid should not wait for a
	 * human to tick "completed" (design spec 4).
	 */
	public const ENROLL_STATUSES = [ 'processing', 'completed' ];

	/** @return string[] */
	public static function qualifying_statuses(): array {
		/**
		 * Filter the order statuses that grant course access.
		 *
		 * @param string[] $statuses
		 */
		$statuses = (array) \apply_filters( 'anchor_courses_wc_enroll_statuses', self::ENROLL_STATUSES );

		return \array_values( \array_filter( \array_map( 'sanitize_key', $statuses ) ) );
	}

	/** `woocommerce_order_status_changed`. */
	public function on_order_status_changed( $order_id, $from, $to, $order = null ): void {
		if ( ! \in_array( \sanitize_key( (string) $to ), self::qualifying_statuses(), true ) ) {
			return;
		}

		$this->enroll_order( (int) $order_id );
	}

	/**
	 * Give the order's customer the access role for every course its products
	 * grant. Support\Roles' listener turns each grant into an enrolment row.
	 *
	 * @return int How many access roles were NEWLY granted.
	 */
	public function enroll_order( int $order_id ): int {
		if ( ! \function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return 0;
		}

		$user_id = self::customer_for_order( $order );
		if ( $user_id <= 0 ) {
			// A guest order cannot be enrolled: progress needs an identity.
			Log::write( 'wc_order_no_customer', [ 'order' => $order_id ] );
			return 0;
		}

		$granted = 0;

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			if ( $product_id <= 0 ) {
				continue;
			}

			foreach ( self::courses_for_product( $product_id ) as $course_id ) {
				if ( Roles::user_has( $user_id, Roles::access_slug( $course_id ) ) ) {
					continue; // Already has access: nothing new (brief 26).
				}

				// The grant - not enroll(). The role is the enrolment, and
				// grant_access() carries the source through to the listener.
				$result = Roles::grant_access( $user_id, $course_id, 'woocommerce', (string) $order_id );

				if ( \is_wp_error( $result ) ) {
					$code = (string) $result->get_error_code();

					Log::write( 'wc_grant_failed', [ 'order' => $order_id, 'course' => $course_id, 'code' => $code ] );

					if ( 'missing_prerequisite' === $code ) {
						// Say so where a shop manager will see it. Deliberately
						// NOT a refund or a status change: the money is a human
						// decision, and an adapter should not make it.
						$order->add_order_note(
							\sprintf(
								/* translators: 1: error code, 2: course title, 3: reason. */
								\__( '%1$s: access to "%2$s" was not granted. %3$s', 'anchor-schema' ),
								'blocked_prerequisite',
								(string) \get_the_title( $course_id ),
								(string) $result->get_error_message()
							)
						);
					}

					continue;
				}

				$granted++;
			}
		}

		return $granted;
	}

	/** @param mixed $order A WC_Order. */
	public static function customer_for_order( $order ): int {
		if ( ! \is_object( $order ) || ! \method_exists( $order, 'get_customer_id' ) ) {
			return 0;
		}
		return (int) $order->get_customer_id();
	}
```

Add `use Anchor\Courses\Support\Log;` at the top of the file.

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Wc_Enrolment
```
Expected: PASS (10 tests), or SKIP without WooCommerce.

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Integrations/WooCommerce.php tests/test-courses-wc-enrolment.php
git commit -m "feat(courses): grant course access on a qualifying WooCommerce order status"
```

---

### Task 40: Refund and cancellation policy

**Files:**
- Modify: `anchor-courses/src/Integrations/WooCommerce.php` - add the refund listeners
- Test: `tests/test-courses-wc-refunds.php`

**Interfaces:**
- Produces:
  - `WooCommerce::REVOKE_STATUSES = [ 'refunded', 'cancelled', 'failed' ]`
  - `::refund_policy( int $order_id, int $course_id ): string` - `remove_role|keep`, default `remove_role`
  - `::on_order_refunded( $order_id, $refund_id = 0 ): void` - `woocommerce_order_refunded`
  - `::revoke_order( int $order_id ): int` - returns how many access roles were removed
  - Filter: `anchor_courses_wc_refund_policy( string $policy, int $order_id, int $course_id, int $user_id )`

**Two policies, stacked, and they answer different questions.** `anchor_courses_wc_refund_policy` (`remove_role` by default, design spec 4) decides whether a refund takes the *access role* away. If it does, `anchor_courses_role_loss_policy` (`keep` by default) then decides what that loss means for the *enrolment row* - so out of the box a refunded learner loses access but keeps their progress, and re-granting resumes them where they were. A site that wants the row closed too filters the second one to `cancel` or `expire`; it does not need to touch this adapter.

Only enrolments whose `source = woocommerce` **and** `source_id = this order id` are touched: a learner who also holds a manual grant for the same course keeps their access, because taking it away would be this order undoing a decision it never made.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - WooCommerce refund handling (brief 18, design spec 4).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Integrations\WooCommerce;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Roles;

/** @group courses @group woocommerce */
class Test_Courses_Wc_Refunds extends Anchor_Courses_TestCase {

	private EnrollmentService $enrollments;
	private WooCommerce $adapter;
	private int $customer;
	private int $course;
	private int $product;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this run.' );
		}

		$this->enrollments = new EnrollmentService();
		$this->adapter     = new WooCommerce();
		$this->customer    = $this->make_learner( [ 'role' => 'customer' ] );
		$this->course      = $this->make_course( [], 'Paid Course' );
		$this->product     = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );

		WooCommerce::set_courses_for_product( $this->product, [ $this->course ] );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_wc_refund_policy' );
		remove_all_filters( 'anchor_courses_role_loss_policy' );
		remove_role( Roles::access_slug( $this->course ) );
		parent::tear_down();
	}

	/** @return \WC_Order */
	private function paid_order() {
		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->set_status( 'processing' );
		$order->save();
		$this->adapter->enroll_order( $order->get_id() );
		return $order;
	}

	public function test_the_default_policy_is_remove_role() {
		$order = $this->paid_order();
		$this->assertSame( 'remove_role', WooCommerce::refund_policy( $order->get_id(), $this->course ) );
	}

	/**
	 * The default pairing: access goes, progress stays. Re-granting the role
	 * resumes the learner exactly where they stopped.
	 */
	public function test_a_refund_removes_access_and_keeps_the_progress_row() {
		$order = $this->paid_order();

		$this->assertSame( 1, $this->adapter->revoke_order( $order->get_id() ) );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertSame(
			'enrolled',
			$this->enrollments->get( $this->customer, $this->course )->status,
			'anchor_courses_role_loss_policy defaults to keep (design spec 3.1).'
		);
	}

	public function test_the_policy_filter_can_keep_access() {
		$order = $this->paid_order();
		add_filter( 'anchor_courses_wc_refund_policy', static fn() => 'keep', 10, 4 );

		$this->assertSame( 0, $this->adapter->revoke_order( $order->get_id() ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertSame( 'enrolled', $this->enrollments->get( $this->customer, $this->course )->status );
	}

	/** The second policy decides what the role loss means for the row. */
	public function test_the_role_loss_policy_can_cancel_the_row_too() {
		$order = $this->paid_order();
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		$this->adapter->revoke_order( $order->get_id() );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertSame( 'cancelled', $this->enrollments->get( $this->customer, $this->course )->status );
	}

	/** A manual grant is not this order's to take away. */
	public function test_a_manual_grant_on_the_same_course_survives_the_refund() {
		$order = $this->paid_order();
		Roles::grant_access( $this->customer, $this->course, 'manual' );

		$this->assertSame( 0, $this->adapter->revoke_order( $order->get_id() ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
	}

	public function test_a_cancelled_order_status_also_revokes() {
		$order = $this->paid_order();

		$this->adapter->on_order_status_changed( $order->get_id(), 'processing', 'cancelled', $order );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
	}

	/** The point of matching on source_id: another order's grant must survive. */
	public function test_an_enrolment_from_another_source_is_untouched() {
		$order = $this->paid_order();

		// Rewrite the row as though something else had granted it.
		$enrollment = $this->enrollments->get( $this->customer, $this->course );
		\Anchor\Courses\Database\EnrollmentRepository::update(
			$enrollment->id,
			[ 'source' => 'manual', 'source_id' => '' ]
		);

		$this->assertSame( 0, $this->adapter->revoke_order( $order->get_id() ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertSame( 'enrolled', $this->enrollments->get( $this->customer, $this->course )->status );
	}

	public function test_a_refund_on_an_unrelated_order_changes_nothing() {
		$this->paid_order();

		$other = wc_create_order( [ 'customer_id' => $this->customer ] );
		$other->set_status( 'processing' );
		$other->save();

		$this->assertSame( 0, $this->adapter->revoke_order( $other->get_id() ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
	}

	public function test_revoking_twice_is_harmless() {
		$order = $this->paid_order();

		$this->adapter->revoke_order( $order->get_id() );
		$this->assertSame( 0, $this->adapter->revoke_order( $order->get_id() ) );
	}

	public function test_credits_and_certificates_already_earned_are_never_deleted() {
		$order  = $this->paid_order();
		$credit = ( new \Anchor\Courses\Services\CreditService() )->award( $this->customer, $this->course, 1.0 );

		$this->adapter->revoke_order( $order->get_id() );

		$this->assertNotNull( \Anchor\Courses\Database\CreditRepository::find( $this->customer, $this->course ) );
		$this->assertGreaterThan( 0, $credit->id );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Wc_Refunds
```
Expected: FAIL - `Call to undefined method ...WooCommerce::refund_policy()`.

- [ ] **Step 3: Write minimal implementation**

Add to `anchor-courses/src/Integrations/WooCommerce.php` - constructor:

```php
		\add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], 10, 2 );
```

extend `on_order_status_changed()` with a revoke branch (before the enrol branch):

```php
		$to_status = \sanitize_key( (string) $to );

		if ( \in_array( $to_status, self::REVOKE_STATUSES, true ) ) {
			$this->revoke_order( (int) $order_id );
			return;
		}
```

and add:

```php
	/** Order statuses that withdraw access. */
	public const REVOKE_STATUSES = [ 'refunded', 'cancelled', 'failed' ];

	/**
	 * What a refund does to the ACCESS ROLE.
	 *
	 * Default `remove_role` (design spec 4): the learner paid, then un-paid, so
	 * the thing the payment bought goes away. What that loss then means for
	 * their progress row is a separate question, answered by
	 * `anchor_courses_role_loss_policy` (default `keep`) - so the out-of-the-box
	 * behaviour is "access gone, progress preserved", and re-granting resumes
	 * them where they were.
	 *
	 * @return string remove_role|keep
	 */
	public static function refund_policy( int $order_id, int $course_id ): string {
		$user_id = 0;
		if ( \function_exists( 'wc_get_order' ) ) {
			$order   = \wc_get_order( $order_id );
			$user_id = $order ? self::customer_for_order( $order ) : 0;
		}

		/**
		 * Filter the refund policy for one order and course.
		 *
		 * @param string $policy   remove_role|keep. Default 'remove_role'.
		 * @param int    $order_id
		 * @param int    $course_id
		 * @param int    $user_id
		 */
		$policy = (string) \apply_filters( 'anchor_courses_wc_refund_policy', 'remove_role', $order_id, $course_id, $user_id );

		return \in_array( $policy, [ 'remove_role', 'keep' ], true ) ? $policy : 'remove_role';
	}

	/** `woocommerce_order_refunded`. */
	public function on_order_refunded( $order_id, $refund_id = 0 ): void {
		$this->revoke_order( (int) $order_id );
	}

	/**
	 * Take back the access THIS order granted.
	 *
	 * Matching on both source and source_id matters: a learner who also holds a
	 * manual grant for the same course keeps their access, because this order
	 * never made that decision and has no business undoing it.
	 *
	 * The row is not touched here. `revoke_access()` removes the role, the
	 * listener sees `remove_user_role` and applies
	 * `anchor_courses_role_loss_policy` - one place decides what a lost role
	 * means, whoever took it away.
	 *
	 * @return int How many access roles were removed.
	 */
	public function revoke_order( int $order_id ): int {
		if ( ! \function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return 0;
		}

		$user_id = self::customer_for_order( $order );
		if ( $user_id <= 0 ) {
			return 0;
		}

		$revoked = 0;

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			if ( $product_id <= 0 ) {
				continue;
			}

			foreach ( self::courses_for_product( $product_id ) as $course_id ) {
				if ( ! Roles::user_has( $user_id, Roles::access_slug( $course_id ) ) ) {
					continue; // Nothing to take back (or already taken).
				}

				$enrollment = ( new \Anchor\Courses\Services\EnrollmentService() )->get( $user_id, $course_id );
				if ( ! $enrollment ) {
					continue;
				}
				if ( 'woocommerce' !== $enrollment->source || (string) $order_id !== $enrollment->source_id ) {
					continue; // Granted by something else; not this order's to take.
				}

				if ( 'keep' === self::refund_policy( $order_id, $course_id ) ) {
					continue;
				}

				Roles::revoke_access( $user_id, $course_id, 'woocommerce', (string) $order_id );

				Log::write( 'wc_access_revoked', [ 'order' => $order_id, 'course' => $course_id ] );
				$revoked++;
			}
		}

		return $revoked;
	}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Wc_Refunds
vendor/bin/phpunit --group courses
```
Expected: PASS (10 tests), and the whole `courses` group still green.

- [ ] **Step 5: Commit**

```bash
git add anchor-courses/src/Integrations/WooCommerce.php tests/test-courses-wc-refunds.php
git commit -m "feat(courses): configurable refund policy for WooCommerce course access"
```

---

## Documentation

### Task 41: COURSES.md and the repo module tables

**Files:**
- Modify: `anchor-courses/COURSES.md` - replace the skeleton with the full reference
- Modify: `CLAUDE.md:70` - add the `courses` row to the Module List table
- Modify: `ADDING-MODULES.md:384` - add the `courses` row to the Available Modules Reference
- Modify: `anchor-tools.php:5` - bump `Version:` to `3.32.0`
- Test: `tests/test-courses-docs.php`

**Interfaces:**
- Consumes: every public symbol from Tasks 1-40.
- Produces: no PHP. The test is the contract: it reflects over the codebase and fails when a hook, route or API function exists in code but not in `COURSES.md`.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Anchor Courses - the documentation is part of the build (brief 16 "Document
 * every public hook", brief 36 "public APIs are documented").
 *
 * This test greps the source for public extension points and fails when one is
 * missing from COURSES.md, so the docs cannot silently rot.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Rest\Routes;

/** @group courses */
class Test_Courses_Docs extends Anchor_Courses_TestCase {

	private string $docs;

	public function set_up() {
		parent::set_up();
		$this->docs = (string) file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses/COURSES.md' );
	}

	/** @return string[] Every hook name passed to do_action/apply_filters in src/ and api.php. */
	private function hooks_in_source(): array {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses' )
		);

		$found = [];
		foreach ( $files as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$source = (string) file_get_contents( $file->getPathname() );
			if ( preg_match_all( "/(?:do_action|apply_filters)\(\s*'(anchor_courses_[a-z0-9_]+)'/", $source, $matches ) ) {
				$found = array_merge( $found, $matches[1] );
			}
		}

		return array_values( array_unique( $found ) );
	}

	public function test_every_hook_fired_in_the_module_is_documented() {
		$undocumented = [];
		foreach ( $this->hooks_in_source() as $hook ) {
			if ( ! str_contains( $this->docs, $hook ) ) {
				$undocumented[] = $hook;
			}
		}

		$this->assertSame( [], $undocumented, 'Undocumented hooks: ' . implode( ', ', $undocumented ) );
	}

	public function test_every_public_api_function_is_documented() {
		foreach ( [
			'anchor_courses_enroll_user',
			'anchor_courses_complete_lesson',
			'anchor_courses_get_progress',
			'anchor_courses_award_ce_credit',
		] as $function ) {
			$this->assertTrue( function_exists( $function ), "Missing API function {$function}" );
			$this->assertStringContainsString( $function, $this->docs, "Undocumented API function {$function}" );
		}
	}

	public function test_every_rest_route_is_documented() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$undocumented = [];
		foreach ( array_keys( $wp_rest_server->get_routes() ) as $route ) {
			if ( 0 !== strpos( $route, '/' . Routes::NAMESPACE ) ) {
				continue;
			}
			// Document the readable form, e.g. /quizzes/{id}/attempts.
			$readable = str_replace( '/' . Routes::NAMESPACE, '', $route );
			$readable = (string) preg_replace( '/\(\?P<(\w+)>[^)]+\)/', '{$1}', $readable );
			if ( '' === $readable ) {
				continue;
			}
			if ( ! str_contains( $this->docs, $readable ) ) {
				$undocumented[] = $readable;
			}
		}

		$this->assertSame( [], $undocumented, 'Undocumented routes: ' . implode( ', ', $undocumented ) );
	}

	public function test_every_shortcode_is_documented() {
		foreach ( [ 'anchor_courses', 'anchor_course', 'anchor_course_progress',
		            'anchor_my_courses', 'anchor_my_credits', 'anchor_my_certificates' ] as $tag ) {
			$this->assertStringContainsString( "[{$tag}]", $this->docs, "Undocumented shortcode [{$tag}]" );
		}
	}

	public function test_every_table_is_documented() {
		foreach ( \Anchor\Courses\Database\Migrations::TABLES as $table ) {
			$this->assertStringContainsString(
				'anchor_courses_' . $table,
				$this->docs,
				"Undocumented table anchor_courses_{$table}"
			);
		}
	}

	public function test_the_module_is_listed_in_the_repo_module_tables() {
		$claude = (string) file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'CLAUDE.md' );
		$adding = (string) file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'ADDING-MODULES.md' );

		$this->assertStringContainsString( '`courses`', $claude );
		$this->assertStringContainsString( '\\Anchor\\Courses\\Module', $claude );
		$this->assertStringContainsString( '`courses`', $adding );
	}

	public function test_the_plugin_version_was_bumped() {
		$plugin = (string) file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-tools.php' );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*Version:\s*3\.32\.0\s*$/m', $plugin );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Docs
```
Expected: FAIL - a long list of undocumented hooks and routes.

- [ ] **Step 3: Write the documentation**

Replace `anchor-courses/COURSES.md` entirely:

````markdown
# Anchor Courses

Module key `courses`, class `\Anchor\Courses\Module`, namespace `Anchor\Courses`
(PSR-4 root `anchor-courses/src/`, declared in the repo's `composer.json`).

A small LMS: courses -> modules -> lessons -> quizzes, enrolment, progress,
server-graded quizzes, idempotent completion, CE credits and HTML certificates.
It is WordPress-native, has no hard dependency on WooCommerce or the events
module, and exposes everything an integrator needs as actions, filters, REST
routes and four public PHP functions.

**Specs:** `docs/superpowers/specs/2026-09-23-anchor-courses-BRIEF.md` (base) and
`docs/superpowers/specs/2026-09-23-anchor-courses-design.md` (repo adaptation,
wins on conflict).

---

## Content model

| Post type | Purpose | Public |
|---|---|---|
| `anchor_course` | A course. Carries its curriculum, CE settings and prerequisites as meta. | yes |
| `anchor_lesson` | A lesson. `content` or `live_session`. | yes |
| `anchor_quiz` | A quiz. Settings and questions as meta. | **no** - a quiz is never a standalone URL |

Modules are not posts. The curriculum lives on the course as
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

Each module keeps a stable UUID, so reordering never breaks a reference.
Removing an item removes the reference only; the lesson or quiz post is
untouched.

### Course meta (`_anchor_course_*`)

`duration`, `difficulty`, `instructor`, `ce_credits`, `ce_type`,
`ce_provider_name`, `ce_provider_number`, `ce_expires_days`, `prerequisites`,
`completion_mode` (`all_required_items|minimum_percentage|manual`),
`completion_percentage`, `progression_mode` (`free|sequential`),
`certificate_enabled`, `certificate_template`, `expiration_days`,
`available_from`, `available_until`, `curriculum`.

There is **no access-type setting and no auto-enrol role list.** A course has
one access rule - hold `anchor_course_{id}` - so there is nothing to configure.
`prerequisites` is the only place a role is chosen, and it accepts only
completion roles (`anchor_course_{id}_completed`) and event roles
(`anchor_event_{id}`); a built-in or WooCommerce role there would gate on
nothing, because `set_user_role` fires for every new account.

### Lesson meta (`_anchor_lesson_*`)

`completion_mode` (`manual|view|quiz_pass`), `required`, `quiz_id`, `type`
(`content|live_session`), `event_id`, `session_index`, `require_prior_items`.

### Quiz meta (`_anchor_quiz_*`)

`settings` (`passing_score`, `max_attempts` (0 = unlimited),
`time_limit_seconds` (0 = untimed), `shuffle_questions`, `shuffle_answers`,
`show_correct_answers`, `show_score`, `allow_review`, `retry_delay_seconds`,
`required`, `on_timer_expiry` (`auto_submit|expire`)), `questions`.

Question types in Phase 1: `single_choice`, `multiple_choice`, `true_false`.
Multiple choice is graded all-or-nothing.

---

## Database tables

All prefixed `{$wpdb->prefix}anchor_courses_`. Schema version lives in the
`anchor_courses_db_version` option (`autoload=false`); migrations run from the
module constructor and again on `admin_init`.

| Table | Holds | Uniqueness |
|---|---|---|
| `anchor_courses_enrollments` | who is enrolled in what, and why | `(user_id, course_id)` |
| `anchor_courses_progress` | per-item state | `(user_id, course_id, item_id, item_type)` |
| `anchor_courses_quiz_attempts` | attempts, answers, grading data | `(user_id, quiz_id, attempt_number)` |
| `anchor_courses_ce_credits` | CE awards | `(user_id, course_id)` |
| `anchor_courses_certificates` | issued certificates | `(user_id, course_id)`, `certificate_number` |

Those unique keys are the idempotency mechanism: repeated completion cannot
produce duplicate credits or certificates. One consequence: a course awards CE
once per learner. Renewal courses will need a schema migration.

**Data is never deleted on deactivation.** Uninstall only drops these tables
when `anchor_courses_delete_data_on_uninstall` is set.

---

## Public PHP API

```php
anchor_courses_enroll_user( int $user_id, int $course_id, array $args = [] ): \Anchor\Courses\Domain\Enrollment|WP_Error;
anchor_courses_complete_lesson( int $user_id, int $course_id, int $lesson_id ): \Anchor\Courses\Domain\Progress|WP_Error;
anchor_courses_get_progress( int $user_id, int $course_id ): \Anchor\Courses\Domain\CourseProgress;
anchor_courses_award_ce_credit( int $user_id, int $course_id, float $credits ): \Anchor\Courses\Domain\Credit|null;
```

`$args` for `anchor_courses_enroll_user()`: `source` (string), `source_id`
(string), `metadata` (array), `bypass_checks` (bool - skip `can_enroll()`; use
when something else already made the access decision).

All four are idempotent. Integrators should use these rather than querying the
tables - but for *enrolling* somebody, prefer granting the access role
(`Support\Roles::grant_access()`, or plain `add_user_role()`): that is the
supported door, and it is the one the Learners tab and the store use.

---

## Actions

| Hook | Arguments |
|---|---|
| `anchor_courses_enrolled` | `Enrollment $enrollment, int $user_id, int $course_id` |
| `anchor_courses_course_started` | `int $user_id, int $course_id, Enrollment $enrollment` |
| `anchor_courses_lesson_started` | `int $user_id, int $course_id, int $lesson_id, Progress $progress` |
| `anchor_courses_lesson_completed` | `int $user_id, int $course_id, int $lesson_id, Progress $progress` |
| `anchor_courses_quiz_started` | `QuizAttempt $attempt, int $user_id, int $quiz_id, int $course_id` |
| `anchor_courses_quiz_submitted` | `QuizAttempt $attempt, int $user_id, int $quiz_id, int $course_id` |
| `anchor_courses_quiz_passed` | `QuizAttempt $attempt, int $user_id, int $quiz_id, int $course_id` |
| `anchor_courses_quiz_failed` | `QuizAttempt $attempt, int $user_id, int $quiz_id, int $course_id` |
| `anchor_courses_course_completed` | `int $user_id, int $course_id, Enrollment $enrollment` |
| `anchor_courses_ce_credit_awarded` | `int $user_id, int $course_id, Credit $credit` |
| `anchor_courses_certificate_issued` | `int $user_id, int $course_id, Certificate $certificate` |
| `anchor_courses_enrollment_status_changed` | `int $user_id, int $course_id, string $from, string $to` |
| `anchor_courses_curriculum_saved` | `int $course_id, array $modules` |
| `anchor_courses_access_granted` | `int $user_id, int $course_id, string $source, string $source_id` |
| `anchor_courses_access_revoked` | `int $user_id, int $course_id, string $source` |
| `anchor_courses_expire_sweep` | (cron hook; no arguments) |

Each fires **once** for the transition it names.

## Filters

| Filter | Signature | Default |
|---|---|---|
| `anchor_courses_can_enroll` | `true\|WP_Error $result, int $user_id, int $course_id` | the service's decision (asked before a role is granted) |
| `anchor_courses_can_access_lesson` | `bool $allowed, int $user_id, int $course_id, int $item_id, string $item_type` | progression rules |
| `anchor_courses_can_start_quiz` | `true\|WP_Error $result, int $user_id, int $quiz_id, int $course_id` | attempt/retry rules |
| `anchor_courses_quiz_result` | `array $result, QuizAttempt $attempt` | grading output + `passed`, `passing_score` |
| `anchor_courses_course_completion_status` | `bool $complete, int $user_id, int $course_id` | the course's completion mode |
| `anchor_courses_certificate_data` | `array $data, Certificate $certificate` | the brief's template variables |
| `anchor_courses_ce_credit_amount` | `float $amount, int $user_id, int $course_id` | the course's `ce_credits` |
| `anchor_courses_ce_credit_data` | `array $data, int $user_id, int $course_id` | the credit row |
| `anchor_courses_parent_menu` | `bool $show` | `true` |
| `anchor_courses_capability_roles` | `string[] $roles` | `['administrator']` |
| `anchor_courses_now` | `int $timestamp` | `time()` (test/E2E clock control) |
| `anchor_courses_datalayer_event` | `array\|null $payload, string $event, array $args` | the IDs-only payload; return `null` to drop |
| `anchor_courses_role_loss_policy` | `string $policy, int $user_id, int $course_id, string $role` | `keep` |
| `anchor_courses_prerequisite_role_choices` | `array $choices` | completion + event roles |
| `anchor_courses_no_access_message` | `string $message, int $course_id` | "Ask us about access to this course." |
| `anchor_courses_access_cta` | `array $cta, int $course_id, int $user_id` | the message; WooCommerce filters in a product link |
| `anchor_courses_wc_enroll_statuses` | `string[] $statuses` | `['processing','completed']` |
| `anchor_courses_wc_refund_policy` | `string $policy, int $order_id, int $course_id, int $user_id` | `remove_role` |

---

## REST API

Namespace `anchor-courses/v1`. No route uses `__return_true`; each names a real
permission callback.

### Public / catalogue

| Route | Method | Permission |
|---|---|---|
| `/courses` | GET | public when the course post type is publicly queryable |
| `/courses/{id}` | GET | same |
| `/courses/{id}/curriculum` | GET | same - titles and types only, never quiz questions |

There is no enrol route: access is a role, and roles are granted server-side.

### Learner

| Route | Method | Permission |
|---|---|---|
| `/me/courses` | GET | signed in |
| `/me/courses/{id}/progress` | GET | signed in |
| `/lessons/{id}/start` | POST | signed in |
| `/lessons/{id}/complete` | POST | signed in |
| `/me/certificates` | GET | signed in |
| `/me/credits` | GET | signed in |

Every `/me/` route reads the current user and ignores any `user_id` parameter.

### Quiz

| Route | Method | Permission |
|---|---|---|
| `/quizzes/{id}/attempts` | POST | signed in + enrolment + progression |
| `/quiz-attempts/{id}` | GET | signed in + owns the attempt |
| `/quiz-attempts/{id}/answer` | POST | signed in + owns the attempt |
| `/quiz-attempts/{id}/submit` | POST | signed in + owns the attempt |

The client may only send **answers**. Scores, pass/fail, attempt numbers and
timing are decided server-side; a `score` in the request body is ignored. No
response ever carries a `correct` flag before the attempt is graded, and
`grading_data` appears only when the quiz's `show_correct_answers` is on.

### Admin

| Route | Method | Capability |
|---|---|---|
| `/admin/courses/{id}/learners` | GET | `view_anchor_course_reports` |
| `/admin/users/{id}/courses` | GET | `view_anchor_course_reports` |
| `/admin/reports/completions` | GET | `view_anchor_course_reports` |
| `/admin/reports/credits` | GET | `manage_anchor_credits` |

---

## Shortcodes

| Shortcode | Attributes | Shows |
|---|---|---|
| `[anchor_courses]` | `limit` | the published course list |
| `[anchor_course]` | `id` | one course: meta, curriculum, and either a link to the product that grants it or the "ask us about access" line |
| `[anchor_course_progress]` | `course_id` | the signed-in learner's progress bar |
| `[anchor_my_courses]` | - | the learner's enrolments |
| `[anchor_my_credits]` | - | the learner's CE ledger and total |
| `[anchor_my_certificates]` | - | the learner's certificates, linked |

Templates are overridable from a theme at `anchor-courses/{name}.php`:
`course`, `lesson`, `live-session`, `quiz`, `dashboard`, `certificate`,
`single-course`, `single-lesson`.

---

## Capabilities

`manage_anchor_courses`, `edit_anchor_courses`, `edit_anchor_lessons`,
`edit_anchor_quizzes`, `view_anchor_course_reports`,
`manage_anchor_enrollments`, `manage_anchor_credits`,
`manage_anchor_certificates`.

All eight are granted to `administrator` by migration `1.0.0`. Point them at
other roles with `anchor_courses_capability_roles`.

---

## Roles as the integration currency

Every course owns **two** capability-less roles, both managed by
`Support\Roles`:

| Role | Slug | Display name | Minted | Means |
|---|---|---|---|---|
| **Access** | `anchor_course_{id}` | `Course: {title}` | eagerly, on save of a published course | **Holding it is enrolment.** |
| **Completion** | `anchor_course_{id}_completed` | `Completed: {title}` | lazily, on the first completion | what another course or an event lists as a prerequisite |

Both are renamed when the course title changes and are never deleted
automatically; the course editor's Course Role panel has an explicit **Delete
role** action for each.

**Holding the access role IS enrolment.** A listener on core `add_user_role` /
`set_user_role` / `remove_user_role` watches for slugs matching
`anchor_course_{id}` exactly - never the `_completed` variant - and:

- on gain, calls `EnrollmentService::enroll()` (idempotent on `(user_id,
  course_id)`), recording why: `woocommerce` + the order id from the store,
  `manual` + the actor from the Learners tab, `role` for anything else;
- on loss, applies `anchor_courses_role_loss_policy` (`keep|expire|cancel`,
  default `keep`), so access outlives the thing that granted it and re-adding
  the role resumes the learner where they were.

That means anything able to add a WordPress role can enrol somebody:
WooCommerce, the Learners tab, WP-CLI, a membership plugin, the user screen in
wp-admin. There is no self-enrolment, no auto-enrol picker and no access-type
setting.

```php
// The supported way to enrol somebody, from anywhere:
\Anchor\Courses\Support\Roles::grant_access( $user_id, $course_id, 'manual' );
```

`grant_access()` asks `EnrollmentService::can_enroll()` first, so an unmet
prerequisite refuses the grant - which is what makes prerequisites bind on the
Learners tab and at the checkout alike.

### Learners tab

The `Learners` metabox on a course lists everyone enrolled with their progress,
status and completion date, and lets anyone with `manage_anchor_enrollments`:

- **add a learner** by name and email - the account is created if it does not
  exist, with no WordPress or WooCommerce new-account email
  (`Support\Accounts::ensure_user()`), then granted the access role with
  `source = manual`;
- **revoke access** per row, which removes the role and lets the loss policy
  decide what happens to their progress.

### Events module

Courses reads the events module only through roles and its public read API -
never its tables or meta. Everything is guarded, so courses works when the
events module is absent or older:

- treats `anchor_event_{id}` as a **prerequisite** role only. Attending an event
  never enrols anybody in a course; if that is wanted it belongs on the event as
  an "also grant these roles" field, not as a picker here;
- filters `anchor_events_can_access_stream` to hold back a livestream until a
  `live_session` lesson's prior items are complete (off unless
  `require_prior_items` is ticked - and it can only subtract access);
- renders a `live_session` lesson from `\Anchor\Events\Module::get_sessions()`,
  `::room_url()` and `\Anchor\Events\Stream_State::for_event()`, falling back to
  "Live session unavailable" when any of them is missing. Courses never embeds
  the stream; the event room is the only player.

### WooCommerce

Product meta `_anchor_course_ids` maps one product to any number of courses.
A qualifying order status (`processing` or `completed` by default) **grants the
access role** for each mapped course, and the role listener records the
enrolment with `source = woocommerce` and `source_id = {order_id}`. If a
prerequisite is unmet the grant is refused and a `blocked_prerequisite` note is
added to the order; the order itself is left alone, because refunding is a human
decision.

A refund or cancellation applies `anchor_courses_wc_refund_policy` (default
`remove_role`) to exactly the access this order granted, and
`anchor_courses_role_loss_policy` (default `keep`) then decides what that means
for the progress row - so by default the learner loses access and keeps their
progress. Earned credits and certificates are never revoked.

A course page shows an **Enrol** button only when a product maps to the course,
and it links to that product. With no product, the page shows the
`anchor_courses_no_access_message` text instead.

---

## Analytics

Browser `dataLayer` pushes for `course_enrolled`, `course_started`,
`lesson_started`, `lesson_completed`, `quiz_started`, `quiz_completed`,
`quiz_passed`, `quiz_failed`, `course_completed`, `ce_credit_awarded`,
`certificate_generated`.

Payloads carry **IDs only** - `course_id`, `lesson_id`, `quiz_id`,
`attempt_id`, `score`, `credits`, `certificate_id`. No names, no emails, no
titles, not even the WordPress user id.

---

## Certificates

Phase 1 is an HTML certificate at `/certificate/{token}/` with a print
stylesheet, public by token so a licensing board can verify a number. The page
is noindexed and never cached. Numbers are `AC-{YYYY}-{8-digit id}` taken from
the table's AUTO_INCREMENT, so they cannot collide. `file_path` is reserved for
PDF generation and stays empty.

---

## Testing

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
composer test                       # WordPress integration suite
composer test:unit                  # WordPress-free unit suite
vendor/bin/phpunit --group courses  # this module only
npx playwright test e2e/courses     # E2E (run npm run env:seed first)
```

`tests/test-courses-milestone.php` and `e2e/courses/milestone.spec.js` walk the
whole learner path end to end; if those two pass the engine is working.
````

`CLAUDE.md` - add after the `blocks` row in the Module List table (`CLAUDE.md:69`):

```markdown
| `courses` | `\Anchor\Courses\Module` | CPT (namespaced, PSR-4) |
```

`ADDING-MODULES.md` - add after the `code_snippets` row (`ADDING-MODULES.md:384`):

```markdown
| `courses` | `\Anchor\Courses\Module` | LMS: courses, lessons, quizzes, progress, CE credits, certificates |
```

`anchor-tools.php:5`:

```php
 * Version: 3.32.0
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Courses_Docs
```
Expected: PASS (7 tests). If it names an undocumented hook or route, add it to
`COURSES.md` rather than loosening the test.

- [ ] **Step 5: Run the whole suite one last time**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
composer test
composer test:unit
```
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add anchor-courses/COURSES.md CLAUDE.md ADDING-MODULES.md anchor-tools.php tests/test-courses-docs.php
git commit -m "docs(courses): COURSES.md reference, module tables and 3.32.0 version bump"
```

---

## Self-Review

Run after the plan is written, before execution starts.

### 1. Spec coverage

| Brief section | Covered by |
|---|---|
| 5-6 domain model, CPTs, modules, course meta | Tasks 6, 7, 8 |
| 7 tables (7.1-7.5) | Task 2 (+ D13 uniqueness) |
| 8 quiz engine (8.1-8.5) | Tasks 11, 12, 22, 23, 24, 25 |
| 9 lesson completion (manual/view/quiz_pass) | Tasks 10, 16, 25 |
| 10 progress calculation | Task 16 |
| 11 course completion | Task 29 |
| 12 CE credits | Task 27 |
| 13 certificates | Tasks 28, 30 (PDF deferred, D11) |
| 14 REST API | Tasks 26, 33, 34 (D12 splits it) |
| 15 public PHP API | Tasks 14, 17, 27 |
| 16 hooks and filters | Tasks 14, 20, 16, 24, 25, 27, 28, 29; documented in 41 |
| 17-18 integration architecture, WooCommerce | Tasks 38, 39, 40 |
| 19 analytics | Task 35 |
| 20 webhooks | **out of scope** (design spec 7) |
| 21 admin experience | Tasks 8, 9, 10, 11, 12, 19, 21, 31 |
| 22-23 frontend, shortcodes | Tasks 18, 26, 30 |
| 24 capabilities | Task 3 (`manage_anchor_enrollments` is enforced in Tasks 21 and 31) |
| 25 security | every task (nonce + cap + prepared SQL + escaping); Tasks 12, 22, 26 for the answer-key rule; Task 19 for the delete-role allowlist |
| 26 concurrency/idempotency | Tasks 2, 13, 20, 15, 22, 27, 28, 29, 32 |
| 27 performance | Tasks 7, 15, 16 (normalised rows, no full-history scans) |
| 28 migrations | Task 2 |
| 29 structure | design spec 2 layout; D7 |
| 30 coding standards | Global Constraints |
| 31 testing | every task; Task 4 (unit suite), Task 32 (gate) |
| 32 Phase 1 feature set | Tasks 1-32 |
| 33 out of scope | not implemented |
| 34 phases | Phases 0-6 as numbered |
| 37 critical rules 1-10 | 1: Task 2; 2: Task 38; 3: Task 18; 4: Tasks 23, 25, 26; 5: Task 18 templates; 6: Tasks 35-40; 7: Tasks 27, 28, 29; 8: Tasks 12, 22, 26; 9: Task 5; 10: scope held to brief 32 |
| 38 milestone | **Task 32 (gate)** |
| 39 build order | Phases follow it: migrations -> repositories -> DTOs -> services -> REST -> admin -> frontend -> integrations |

**Brief 6.3's `course_access_type` is deliberately not implemented** (design
spec 1, row "Course access type"): there is no access setting, because there is
only one access rule. Brief 18's "enrol the customer" is implemented as a role
grant (Task 39), which is the same outcome through the module's one door.

Design spec coverage: 1 deltas (Global Constraints + D7, D8, D11, and the
access-type removal above); 2 layout (File Structure); 3.1 roles as the currency
(**Tasks 19, 20, 21**, with the completion grant in Task 29 and the prerequisites
picker in Task 8); 3.2-3.3 events contract (Tasks 36, 37); 4 decisions (Tasks 8,
11, 27, 28, 39, 40, 5, and the access CTA in Tasks 18 and 38); 5 phases - Phase 2
owns the roles, the listener and the Learners tab, Phase 5 keeps only the events
read API and the stream veto, Phase 6 is the WooCommerce adapter; 6 testing
(Task 4); 7 out of scope.

**Enrolment coverage, end to end.** Every way somebody can come to hold
`anchor_course_{id}` has a test: `grant_access()` (Task 20), a bare
`add_user_role()` from wp-admin or WP-CLI (Task 20,
`test_a_plain_add_user_role_enrols_with_the_role_source`), `set_user_role()`
(Task 20), the Learners tab (Task 21), a WooCommerce order (Task 39), and the
milestone gate's full path (Task 32). Every way it can be lost has one too:
`revoke_access()` and the three loss policies (Task 20), the Learners tab's
Revoke (Task 21), Cancel on the enrolment manager (Task 31), a refund (Task 40)
and an operator deleting the role outright (Tasks 19, 20). The refusal path -
an unmet prerequisite - is tested at all three entry points that can hit it:
`grant_access()` itself (Task 20), the Learners tab (Task 21) and the checkout,
where it also writes the `blocked_prerequisite` order note (Task 39). And the
things that must **not** enrol anybody are pinned as tests rather than left to
inference: the completion role, event roles, built-in roles (Task 20), the
absent front-end enrol handler (Task 17), the absent REST route (Task 33) and
the absent Enrol button (Tasks 18, 32 E2E).

**Gaps, deliberate:** webhooks (brief 20), PDF certificates, `external_event`
lesson completion, instructor roles, video-percentage completion, self-enrolment
and event-attendance -> course auto-enrolment. All are named as out of scope in
design spec 7.

### 2. Placeholder scan

No task contains "TBD", "TODO", "implement later", "add appropriate error
handling", "write tests for the above", or "similar to Task N". Every code step
carries the actual code. Tasks that reuse an earlier idea (for example the
`INSERT IGNORE` pattern in Tasks 13 and 27) repeat the code rather than pointing
at a neighbour, because an executor may read tasks out of order. Task 31 is the
one place that restates a whole class an earlier task created - `LearnerReports`
- and says so at the top, for the same reason.

### 3. Type consistency

Checked across tasks:

- `Migrations::table()`, `::TABLES`, `::DB_VERSION`, `::OPTION` - identical in Tasks 2, 5, 25, 34, 41.
- `Clock::now()/timestamp()/offset()/to_timestamp()` - one signature, used in Tasks 13, 15, 22, 24, 25, 27, 28, 29.
- `Capabilities::cap( string $key )` keys `manage|edit_courses|edit_lessons|edit_quizzes|reports|enrollments|credits|certificates` - same eight everywhere (Tasks 3, 6, 8, 10, 11, 19, 21, 31, 33, 34, 38).
- `Curriculum::items()` rows always `['type','id','required','module_id','index']` (Tasks 7, 16, 25, 33, 37).
- `ProgressService::record_item( $user_id, $course_id, $item_id, $item_type, $status, $extra = [] )` - same signature in Tasks 16, 24, 25.
- `CourseProgress` property names `completed_required`, `total_required`, `percent`, `complete`, `completed_item_keys` - same in Tasks 15, 16, 18, 31, 33, 37.
- `QuizAttempt::for_learner( bool $show_correct )` - Tasks 22, 26.
- `EnrollmentService::enroll()` `$args` keys `source`, `source_id`, `metadata`, `bypass_checks` - Tasks 14, 20, 34.
- `EnrollmentService::can_enroll()` error codes `no_user|no_course|not_available_yet|no_longer_available|missing_prerequisite` - Tasks 14, 20, 21, 39. There is no `course_closed`.
- `Roles::access_slug()/completion_slug()/access_name()/completion_name()/is_access_slug()/ensure_access_role()/ensure_completion_role()/exists()/holders()/delete_role()/user_has()/missing()/grant_completed()` - Tasks 19, 29, 31.
- `Roles::grant_access( int, int, string $source = 'manual', string $source_id = '' ): true|WP_Error` and `::revoke_access( int, int, string $source = 'manual', string $source_id = '' ): bool` - same signature in Tasks 20, 21, 31, 32, 39, 40.
- `Frontend\Access::cta( int $course_id, int $user_id = 0 ): array{url,label,message}` - Tasks 18, 38.
- `Integrations\Events::available()/sessions()/room_url()/stream_state()/live_lessons_for_event()/veto_stream_access()` - Tasks 36, 37. `courses_for_role()` is gone with the auto-enrolment it served.
- `Routes::NAMESPACE`, `::require_login`, `::require_cap`, `::public_read`, `::error_response` - Tasks 26, 33, 34.
- Hook argument order is fixed once per hook in the task that fires it and re-read identically in Task 35's `payload_for()` and Task 41's table.

**Corrections applied during review:**

1. `Frontend\Shortcodes::__construct()` takes two services in Task 18 and four
   from Task 30 onward; Task 30 restates the full constructor so an executor
   reading it alone gets the final signature, and Task 33's `Rest\Routes`
   construction restates the whole call rather than implying an append.
2. `Admin\LearnerReports` is created in Task 21 and rewritten in Task 31. Task 31
   restates the finished class and its Step 4 runs the Task 21 suite as well, so
   a rewrite that quietly dropped the add or revoke controls fails loudly
   instead of passing.
3. `Support\Roles::grant_completed()` is called from `CompletionService` (Task 29)
   and must never look like an access grant. `is_access_slug()`'s digits-only
   anchor is what guarantees that, and Task 19 tests the `_completed` variant
   against it explicitly rather than trusting the regex to read correctly.
