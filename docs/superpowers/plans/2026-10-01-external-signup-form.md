# "Use external signup form" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One checkbox per event, "Use external signup form", that maps to the existing `registration_mode = external`, shows the signup embed/URL fields only when ticked, and switches off everything that only applies to registrations taken through the plugin (roster, lifecycle emails, hand-added seats, access role, livestream, native form/storefront). Then retire DEKA's theme-only JotForm field into the plugin field.

**Architecture:** One predicate, `Module::uses_external_signup( $event_id )`, is the single answer every runtime gate asks (`roster_capable()` and `stream_capable()` delegate to it). The checkbox is a form control only. A presence marker tells the save path the checkbox was on screen, and a new `native_registration_mode` meta remembers the wc/free choice so unticking restores it. UI hiding reuses the existing `data-when-mode` mechanism. The rule moves into one small shared JS file (`registration-mode.js`) that both `admin.js` and `manager.js` call. Every field stays in the DOM, so stored settings round-trip untouched.

**Tech Stack:** WordPress plugin PHP (namespaced `Anchor\Events`), jQuery (no build step), PHPUnit 9 on the WP test suite, Node (no deps) for one JS harness, Playwright e2e (wp-env). DEKA part: WP-CLI over SSH, theme PHP edited through `dgrab`/`dput`.

**Spec:** `docs/superpowers/specs/2026-10-01-external-signup-form-design.md`

## Global Constraints

- **Generic plugin.** No "DEKA" (or any client name) in plugin code, strings, tests or comments. A JotForm-*shaped* fixture in a test is fine. User-facing strings say "external signup form".
- Text domain is `anchor-schema` on every new string.
- Edit source `.js`/`.css` only. Never create or commit `*.min.*` (CI builds them). jQuery IIFE style, no ES modules: no `import `/`export ` tokens.
- **No new registration-mode value.** Mode stays `wc|free|external`. Events already saved as `external` keep working. A form that posts `anchor_event_registration_mode=external` without the new presence marker keeps today's behaviour, because existing tests and e2e specs post it that way.
- **Stored settings are never cleared by ticking the box** (spec decision 3): capacity, waitlist, tiers, questions, access, stream, email overrides and switches all stay in the DB. Hide them in the UI; never delete or reset them on save.
- `external_embed` already exists end to end. Do NOT register a new meta key for it. Reuse `sanitize_external_embed()` and the `anchor_events_embed_allowed_html` filter.
- The `anchor_events_roster_capable` filter from WIP commit 9e157a7 is removed (spec decision 5).
- PHPUnit env (harness lives in /tmp and is purged after ~3 days; rebuild recipe is in memory `anchor-tools-test-harness-tmp-purge`): `export WP_TESTS_DIR=/tmp/wordpress-tests-lib-subjects WP_CORE_DIR=/tmp/wordpress && composer dump-autoload`. Run a file with `vendor/bin/phpunit -c phpunit.xml.dist tests/<file>.php`. Before committing, run `git checkout -- vendor/composer`.
- Commit with `git add <exact files>` only (never `-A`). Every commit message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never push from a task. Always `git push origin <branch>` (never bare).
- DEKA tasks (9–11): `dekalasers.kinsta.cloud` / `dekadentallasers.com` IS PRODUCTION (SSH port 29603 = live). Back up first, verify after, use `dgrab`/`dput` (stop on `DPUT_ABORT`), purge with `dpurge`, and check pages visually with the Playwright MCP (`mcp__MCP_DOCKER__browser_*`, screenshots to `/tmp/playwright-output/`). Never use `mcp__claude-in-chrome__*`.

## Review Focus

1. **A save from a form without the checkbox** (old cached markup, a third-party/REST write, the theme's console injecting fields) must not flip an external event back to native. Pinned by `test_form_without_the_checkbox_never_flips_an_external_event` (Task 5).
2. **Tick → save → untick restores everything**: the stored stream embed, capacity, questions and email switches must survive the external period. Today an external save already wipes the stored stream, because `render_livestream_fields()` returns `''` and an absent textarea normalises to "clear". Pinned by `test_external_metabox_still_carries_every_native_setting` and `test_external_save_keeps_the_stored_stream` (Task 6).
3. **A pasted embed carrying an inline `<script>` body** (every JotForm "iframe" embed ships a resize handler) must not render the JavaScript as visible page text. `wp_kses()` keeps the body text of tags it strips. Pinned by `test_inline_script_body_is_dropped_not_printed` (Task 4).
4. **An event that was ticketed (wc) and is switched to external** must not show a stale storefront or "Tickets are not available" notice: the WooCommerce seam runs before the external branch today. Pinned by `test_external_branch_runs_before_the_storefront_seam` (Task 3).
5. **Legacy seats on an event switched to external** (DEKA has none today, but the plugin is generic) must get no reminders or cancellation mail, and the reminder sweep must write no markers, so unticking later still sends the reminder. Pinned by `test_reminder_sweep_skips_legacy_seats_and_writes_no_markers` (Task 2).

## Decommission inventory (verified against the code; line numbers ≈ at HEAD 0efe7bd)

Already external-aware (do NOT redo):
- Native register POST refuses: `handle_registration()` anchor-events-manager.php:11476
- Access role: `Entitlements::enabled()` class-entitlements.php:103, via `stream_capable()` :12686. It also covers `on_seat_created` (:481), `Roster::add_access_by_email()` class-roster.php:218, `handle_backfill_role()` :3558 and `render_event_role_panel()` access check :3449
- Livestream room + REST room route: `Stream_State` class-stream-state.php:69 (`stream_capable`), `Entitlements::resolve_access` :1023
- WooCommerce product: `Product_Sync` class-product-sync.php:564 demotes the managed product for any non-`wc` mode, so the cart and `is_purchasable` close on the next save
- JSON-LD: `Event_Schema::build_offers` class-event-schema.php:727
- Front-end render, embed over URL: `render_external_registration()` :10847 (embed wins, else Register button)
- `external_embed` meta registration (`show_in_rest=false` + `sanitize_callback`) :2834, default :2939, save :6253, inheritance `Occurrences::INHERITED_KEYS` class-occurrences.php:199-201
- WIP 9e157a7: `send_roster_email` :1560, `compute_email_schedule` roster row :1706, `Roster` list :344, roster screen :364, console panel :2210

Runtime touchpoints this plan changes (13):
- `roster_capable()` filter removal + delegation :12710; `stream_capable()` delegation :12694 (T1)
- `compute_email_schedule()` gets an `external_signup` notice :1690, Upcoming Sends notice text :5175 (T1)
- `Roster::handle_send_roster()` skip message :1139 (T1)
- `send_due_reminders()` :1049, `send_reminder_email()` :1474, `send_confirmation_email()` :15029, `send_registration_admin_notice()` :14982, `send_cancellation_email()` :15190 (T2)
- `Roster::handle_add()` :775, `Roster::handle_grant()` :1059 (T3)
- `render_registration_form()`: external branch moves above the `anchor_events_registration_form` seam :10924/:10956 (T3)
- `sanitize_external_embed()` script-body leak + iframe attributes :2689/:2699 (T4)
- Save path: checkbox + marker + `native_registration_mode` in `sanitize_event_type_input()` :6236 (T5)
- `render_livestream_fields()` early return that wipes the stored stream on save :3217 (T6)

UI touchpoints this plan changes (16):
- Mode chooser in the metabox :4098 and the wizard :9325 → one shared checkbox + select renderer (T6)
- External section in the metabox :4314 and the wizard :9514 → one shared renderer, moved directly under the checkbox (T6)
- Livestream section :3234, Access section :3363 (both surfaces) → `data-when-mode="wc free"` (T6)
- Wizard Attendee questions :9481, wizard Email Settings step 5 :9551 (+ new step-5 notice), metabox Email Settings :4383 (T6)
- Wizard Registration hint :9461 (T6)
- Registration fields (capacity/open/close/waitlist/sold-out/price), both surfaces: JS hides them while external (T7)
- wp-admin postboxes Tickets/Pricing, Registrations, Emails builder (:2969/:2978/:2990): JS hides them while external (T7)
- wp-admin Events list "Roster" row action :11620 (T8)
- `[event_manager]` list item Attendees/Export links :9131 + registrant count :14487 (T8)
- Console Basics "Event role" panel :3445 (T8)

Out of scope, flagged only: WooCommerce customer/organizer emails (class-woocommerce.php:3981/:4115 are wc-mode only, and products are demoted once an event leaves wc). The per-session "Attendance/Stream override" columns in the multisession table stay visible. `admin.js` and `manager.js` are near-duplicate files (one-line follow-up: merge them). The email-template sanitizer (`sanitize_email_template_html`, :5302) has the same kses script-body leak.

---

### Task 1: One predicate: `uses_external_signup()`, filter removed, roster-less notices

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php` (`stream_capable()` ≈12686, `roster_capable()` ≈12697-12713, `send_roster_email()` ≈1560, `compute_email_schedule()` ≈1690-1710, `render_upcoming_sends_metabox()` notices ≈5175)
- Modify: `anchor-events-manager/class-roster.php` (`handle_send_roster()` skipped branch ≈1135-1139)
- Test: `tests/test-roster-capable.php` (the WIP file)

**Interfaces:**
- Produces: `Module::uses_external_signup( int $event_id ): bool`, which is true iff `registration_mode() === 'external'`. Every later task calls it. Outcome skip reason `'external_signup'` (used by Tasks 1–2). Schedule notice key `'external_signup'`.

- [ ] **Step 1: Rewrite the WIP test file to the new contract**

In `tests/test-roster-capable.php`:
- In `tear_down()` delete the line `remove_all_filters( 'anchor_events_roster_capable' );`.
- Delete the whole method `test_a_site_can_mark_other_events_roster_less()`.
- In `test_external_registration_sends_no_roster_email()` change `'no_roster'` to `'external_signup'`.
- Add these methods to the class:

```php
	public function test_one_predicate_answers_roster_and_stream() {
		$ext  = $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/x' ] );
		$free = $this->make_event( [ 'registration_mode' => 'free' ] );

		$this->assertTrue( $this->module()->uses_external_signup( $ext ) );
		$this->assertFalse( $this->module()->uses_external_signup( $free ) );
		$this->assertFalse( $this->module()->roster_capable( $ext ) );
		$this->assertFalse( $this->module()->stream_capable( $ext ) );
		$this->assertTrue( $this->module()->roster_capable( $free ) );
		$this->assertTrue( $this->module()->stream_capable( $free ) );
	}

	public function test_upcoming_sends_says_why_nothing_is_scheduled() {
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), [ 'reminder_enabled' => true, 'organizer_roster_email' => true ] ), false );
		$ext = $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/x', 'start_ts' => time() + DAY_IN_SECONDS ] );
		$this->make_seat( $ext ); // a legacy seat from before the switch

		$schedule = $this->module()->compute_email_schedule( $ext );
		$this->assertSame( 'external_signup', $schedule['notice'] );
		$this->assertSame( [], $schedule['rows'], 'No reminder rows either — reminders do not go to external-signup events.' );
	}

	public function test_send_roster_by_hand_says_why_for_an_external_event() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$ext   = $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/x' ] );
		$_POST = [ 'event_id' => $ext, '_wpnonce' => wp_create_nonce( 'anchor_events_send_roster_' . $ext ) ];
		$_REQUEST = $_POST;
		$trap = function ( $location ) {
			throw new Anchor_Entitlements_Redirect_Signal( (string) $location );
		};
		add_filter( 'wp_redirect', $trap );
		try {
			$this->module()->roster->handle_send_roster();
			$this->fail( 'handle_send_roster() did not redirect.' );
		} catch ( Anchor_Entitlements_Redirect_Signal $e ) {
			$this->assertStringContainsString( 'external form', rawurldecode( rawurldecode( $e->getMessage() ) ) );
		} finally {
			remove_filter( 'wp_redirect', $trap );
			$_POST    = [];
			$_REQUEST = [];
		}
		$this->assertCount( 0, $this->sent );
	}
```

- [ ] **Step 2: Run, expect failures**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-roster-capable.php`
Expected: FAIL. `uses_external_signup` is undefined, the reason is `no_roster`, and the notice is `''`.

- [ ] **Step 3: Implement**

In `anchor-events-manager.php`, replace the WIP `roster_capable()` docblock and body (≈12697-12713) with:

```php
    /**
     * Does this event take its sign-ups on someone else's form?
     *
     * The ONE answer to the "Use external signup form" checkbox, which is
     * stored as registration_mode = external (spec 2026-10-01). Everything
     * that only makes sense for registrations taken THROUGH this plugin —
     * the roster and its digest, the lifecycle emails, hand-added seats, the
     * access role, the livestream room, the native form and storefront —
     * asks this, directly or through roster_capable()/stream_capable().
     *
     * @param int $event_id
     * @return bool
     */
    public function uses_external_signup( $event_id ) {
        return $this->registration_mode( (int) $event_id ) === 'external';
    }

    /**
     * Does this event keep a roster at all? A roster is the list of people
     * who registered through this plugin, so an external-signup event has
     * none: no roster screen, console panel, list entry or digest.
     *
     * @param int $event_id
     * @return bool
     */
    public function roster_capable( $event_id ) {
        return ! $this->uses_external_signup( (int) $event_id );
    }
```

In `stream_capable()`, replace the last line `return \in_array( $this->registration_mode( $event_id ), [ 'wc', 'free' ], true );` with:

```php
        return ! $this->uses_external_signup( $event_id );
```

In `send_roster_email()`, change `return Outcome::skipped( 'no_roster' ); // registrations happen on an external form` to:

```php
            return Outcome::skipped( 'external_signup' ); // sign-ups happen on an external form
```

In `compute_email_schedule()`, insert directly after the `'invalid'` early return:

```php
        if ( $this->uses_external_signup( $event_id ) ) {
            $result['notice'] = 'external_signup';
            return $result;
        }
```

and restore the roster line to `$roster_on    = ! empty( $settings['organizer_roster_email'] );` (the early return now covers it).

In `render_upcoming_sends_metabox()`'s `$notices` array add:

```php
            'external_signup' => __( 'This event takes sign-ups on an external form, so nothing is scheduled here: no reminders and no roster digest.', 'anchor-schema' ),
```

In `class-roster.php` `handle_send_roster()`, replace the `elseif ( $result->is_skipped() )` body with:

```php
            $message = $result->reason() === 'external_signup'
                ? \__( 'Roster not sent — this event takes sign-ups on an external form, so it has no roster.', 'anchor-schema' )
                : \__( 'Roster not sent — the roster email is switched off for this event.', 'anchor-schema' );
            $this->redirect( $event_id, 'success', $message );
```

- [ ] **Step 4: Run, expect pass**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-roster-capable.php tests/test-stream-state.php tests/test-entitlements.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php anchor-events-manager/class-roster.php tests/test-roster-capable.php
git commit -m "feat(events): uses_external_signup() is the one external-signup predicate; drop the roster_capable filter; say why nothing is scheduled

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Lifecycle emails refuse external-signup events

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php`: `send_due_reminders()` ≈1049, `send_reminder_email()` ≈1474, `send_registration_admin_notice()` ≈14982, `send_confirmation_email()` ≈15029, `send_cancellation_email()` ≈15190
- Test: `tests/test-external-signup-emails.php` (create)

**Interfaces:**
- Consumes: `Module::uses_external_signup()` (Task 1).
- Produces: each attendee sender returns `Outcome::skipped( 'external_signup' )` for an external event. The organizer notice returns early.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * External-signup events send no lifecycle email: no confirmation, no
 * organizer "new registration" notice, no reminder (sweep or retry), no
 * cancellation. Seats can only exist on such an event from before the box
 * was ticked; they must stay silent, and the reminder sweep must not mark
 * them, or unticking the box later would silently lose the reminder.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;

/**
 * @group email
 */
class Test_External_Signup_Emails extends Anchor_Events_TestCase {

	private $sent = [];

	public function set_up() {
		parent::set_up();
		$this->sent = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), [
			'notify_user'            => true,
			'notify_admin'           => true,
			'admin_email'            => 'org@example.org',
			'notify_cancellation'    => true,
			'reminder_enabled'       => true,
			'reminder_offsets'       => '1',
			'organizer_roster_email' => false,
		] ), false );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		delete_option( Module::OPTION_KEY );
		parent::tear_down();
	}

	public function capture_mail( $short_circuit, $atts ) {
		$this->sent[] = $atts;
		return true;
	}

	private function external_event( array $meta = [] ) {
		return $this->make_event( array_merge( [
			'registration_mode' => 'external',
			'external_embed'    => '<iframe src="https://form.example/1"></iframe>',
		], $meta ) );
	}

	public function test_no_confirmation_and_no_organizer_notice() {
		$out = $this->module()->send_registration_emails( $this->external_event(), 'Jane', 'jane@example.org', Registrations::STATUS_CONFIRMED );
		$this->assertTrue( $out->is_skipped() );
		$this->assertSame( 'external_signup', $out->reason() );
		$this->assertCount( 0, $this->sent, 'Neither the attendee nor the organizer copy may go out.' );
	}

	public function test_attendee_only_path_refuses_too() {
		// The waitlist promotion calls this half on its own.
		$out = $this->module()->send_confirmation_email( $this->external_event(), 'Jane', 'jane@example.org', Registrations::STATUS_CONFIRMED );
		$this->assertSame( 'external_signup', $out->reason() );
		$this->assertCount( 0, $this->sent );
	}

	public function test_reminder_sweep_skips_legacy_seats_and_writes_no_markers() {
		$start = time() + 12 * HOUR_IN_SECONDS;
		$ext   = $this->external_event( [
			'timezone'   => 'UTC',
			'start_date' => gmdate( 'Y-m-d', $start ),
			'start_time' => gmdate( 'H:i', $start ),
			'start_ts'   => $start,
		] );
		$seat = $this->make_seat( $ext, [ 'email' => 'legacy@example.org' ] );

		$this->module()->run_reminder_sweep();

		$this->assertCount( 0, $this->sent );
		$this->assertEmpty( get_post_meta( $seat, Registrations::META_REMINDERS_SENT, true ), 'No markers: unticking the box later must still let the reminder go.' );
	}

	public function test_reminder_retry_path_refuses() {
		$ext  = $this->external_event();
		$seat = $this->make_seat( $ext, [ 'email' => 'legacy@example.org' ] );
		$out  = $this->module()->send_reminder_email( [ 'id' => $seat, 'email' => 'legacy@example.org', 'name' => 'Legacy' ], $ext, 1 );
		$this->assertSame( 'external_signup', $out->reason() );
		$this->assertCount( 0, $this->sent );
	}

	public function test_cancellation_email_refuses() {
		$seat = $this->make_seat( $this->external_event(), [ 'email' => 'legacy@example.org' ] );
		$out  = $this->module()->send_cancellation_email( $seat );
		$this->assertSame( 'external_signup', $out->reason() );
		$this->assertCount( 0, $this->sent );
	}

	/** Control: a guard that blocks everything would pass every test above. */
	public function test_native_event_still_sends_confirmation_and_notice() {
		$free = $this->make_event( [ 'registration_mode' => 'free' ] );
		$out  = $this->module()->send_registration_emails( $free, 'Jane', 'jane@example.org', Registrations::STATUS_CONFIRMED );
		$this->assertTrue( $out->is_sent() );
		$this->assertCount( 2, $this->sent, 'Organizer notice + attendee confirmation.' );
	}
}
```

- [ ] **Step 2: Run, expect failure**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-emails.php`
Expected: FAIL. Mail is sent and the reasons are not `external_signup`. Only the control passes.

- [ ] **Step 3: Implement the five gates**

`send_due_reminders()`: make this the first statement of the body, above the `is_email_enabled( $event_id, 'reminder' )` check:

```php
        // External signup: no reminders, and — like the switch below — no
        // markers, so unticking the box mid-window still sends the reminder.
        if ( $this->uses_external_signup( $event_id ) ) {
            return;
        }
```

`send_reminder_email()`: make this the first statement:

```php
        if ( $this->uses_external_signup( (int) $event_id ) ) {
            return Outcome::skipped( 'external_signup' );
        }
```

`send_registration_admin_notice()`: make this the first statement:

```php
        if ( $this->uses_external_signup( (int) $event_id ) ) {
            return; // sign-ups happen on an external form; nobody registered here
        }
```

`send_confirmation_email()`: insert directly after `$guests = max( 0, (int) $guests );` and before the `notify_user` check:

```php
        if ( $this->uses_external_signup( (int) $event_id ) ) {
            return Outcome::skipped( 'external_signup' );
        }
```

`send_cancellation_email()`: insert directly after `$event_id = (int) $info['event_id'];` and before the `is_email_enabled( $event_id, 'cancellation' )` check:

```php
        if ( $this->uses_external_signup( $event_id ) ) {
            return Outcome::skipped( 'external_signup' );
        }
```

(`drain_email_retry_queue()` already retires a job whose sender answers `skipped`, around line 1424, so no change is needed there.)

- [ ] **Step 4: Run, expect pass, then the neighbours**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-emails.php tests/test-reminders.php tests/test-roster-add-emails.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php tests/test-external-signup-emails.php
git commit -m "feat(events): external-signup events send no confirmation, organizer notice, reminder or cancellation email

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Seat/role refusals and render order

**Files:**
- Modify: `anchor-events-manager/class-roster.php`: `handle_add()` after the group-parent refusal (≈795), `handle_grant()` after the `seat_belongs_to_event` refusal (≈1064)
- Modify: `anchor-events-manager/anchor-events-manager.php`: `render_registration_form()` (≈10915-10958)
- Test: `tests/test-external-signup-guards.php` (create)

**Interfaces:**
- Consumes: `Module::uses_external_signup()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Native-registration entry points refuse external-signup events, and the
 * external form is rendered before the WooCommerce storefront seam can
 * offer a stale buy button.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group registration
 */
class Test_External_Signup_Guards extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		add_filter( 'wp_redirect', [ $this, 'trap' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', [ $this, 'trap' ] );
		remove_all_filters( 'anchor_events_registration_form' );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function trap( $location ) {
		throw new Anchor_Entitlements_Redirect_Signal( (string) $location );
	}

	private function external_event() {
		return $this->make_event( [
			'registration_mode' => 'external',
			'external_url'      => 'https://form.example/signup',
			'external_embed'    => '<iframe src="https://form.example/embed"></iframe>',
		] );
	}

	private function drive( callable $handler, array $post ) {
		$_POST    = $post;
		$_REQUEST = $post;
		try {
			$handler();
		} catch ( Anchor_Entitlements_Redirect_Signal $e ) {
			return rawurldecode( rawurldecode( $e->getMessage() ) );
		}
		$this->fail( 'Handler did not redirect.' );
	}

	public function test_roster_add_refuses() {
		$ext = $this->external_event();
		$loc = $this->drive( [ $this->module()->roster, 'handle_add' ], [
			'event_id'      => $ext,
			'roster_name'   => 'Jane Doe',
			'roster_email'  => 'jane@example.org',
			'roster_guests' => 0,
			'_wpnonce'      => wp_create_nonce( 'anchor_roster_add_' . $ext ),
		] );
		$this->assertStringContainsString( 'roster_type=error', $loc );
		$this->assertStringContainsString( 'external form', $loc );
		$this->assertSame( 0, $this->count_seats( $ext ) );
	}

	public function test_manual_role_grant_refuses_and_mints_no_account() {
		$ext  = $this->external_event();
		$seat = $this->make_seat( $ext, [ 'email' => 'legacy-grant@example.org' ] );
		$loc  = $this->drive( [ $this->module()->roster, 'handle_grant' ], [
			'event_id' => $ext,
			'seat_id'  => $seat,
			'_wpnonce' => wp_create_nonce( 'anchor_roster_edit_' . $ext ),
		] );
		$this->assertStringContainsString( 'roster_type=error', $loc );
		$this->assertFalse( get_user_by( 'email', 'legacy-grant@example.org' ), 'No account may be created for a grant that was refused.' );
	}

	public function test_external_branch_runs_before_the_storefront_seam() {
		$ext = $this->external_event();
		add_filter( 'anchor_events_registration_form', function () {
			return '<div class="stale-storefront">Buy</div>';
		}, 1 );
		$html = $this->module()->render_registration_form( $ext );
		$this->assertStringNotContainsString( 'stale-storefront', $html );
		$this->assertStringContainsString( 'anchor-event-registration-external', $html );
	}

	public function test_embed_wins_over_the_url() {
		$html = $this->module()->render_registration_form( $this->external_event() );
		$this->assertStringContainsString( '<iframe src="https://form.example/embed"', $html );
		$this->assertStringNotContainsString( 'https://form.example/signup', $html );
	}

	public function test_url_only_renders_the_register_button() {
		$ext = $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/signup' ] );
		$this->assertStringContainsString( 'href="https://form.example/signup"', $this->module()->render_registration_form( $ext ) );
	}
}
```

- [ ] **Step 2: Run, expect failure**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-guards.php`
Expected: `test_roster_add_refuses`, `test_manual_role_grant_refuses_and_mints_no_account` and `test_external_branch_runs_before_the_storefront_seam` FAIL. The embed/URL tests already PASS (they pin existing behaviour).

- [ ] **Step 3: Implement**

`class-roster.php` `handle_add()`, directly after the `is_group_parent` refusal block:

```php
        // An external-signup event has no roster here, so it takes no
        // hand-added seats either: they would get this plugin's emails and
        // count toward a capacity nobody manages.
        if ( $this->module->uses_external_signup( $event_id ) ) {
            $this->redirect( $event_id, 'error', \__( 'This event takes sign-ups on an external form, so attendees cannot be added here.', 'anchor-schema' ), 'invalid' );
        }
```

`class-roster.php` `handle_grant()`, directly after the `seat_belongs_to_event` refusal and before `ensure_user()`:

```php
        if ( $this->module->uses_external_signup( $event_id ) ) {
            $this->redirect( $event_id, 'error', \__( 'This event takes sign-ups on an external form, so it grants no event role.', 'anchor-schema' ), 'invalid' );
        }
```

`anchor-events-manager.php` `render_registration_form()`: delete the existing block

```php
        // External registration mode (Task 1.6): the event's registration/
        // checkout happens off-site. Still gated by `registration_enabled`
        // above, matching the legacy external-URL path, can_view_virtual_link(),
        // and maybe_append_registration_shortcode() — when registration is
        // disabled, no registration UI renders at all, external or otherwise.
        if ( $this->registration_mode( $post_id ) === 'external' ) {
            return $this->render_external_registration( $post_id, $meta );
        }
```

and insert this immediately before the `// Render seam (spec §3): the WooCommerce class swaps…` comment (that is, after the `registration_enabled` block):

```php
        // External signup (spec 2026-10-01): sign-ups happen on someone else's
        // form, so nothing below applies — not the WooCommerce storefront seam,
        // not the "tickets not available" notice, not the native seat form.
        // Asked BEFORE the seam so a product or paid tier left over from a
        // previous mode can never put a buy button on this page. Still gated by
        // `registration_enabled` above, as it always was.
        if ( $this->uses_external_signup( $post_id ) ) {
            return $this->render_external_registration( $post_id, $meta );
        }
```

- [ ] **Step 4: Run, expect pass**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-guards.php tests/test-registration-external-guard.php tests/test-event-frontend-render.php tests/test-roster.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-roster.php anchor-events-manager/anchor-events-manager.php tests/test-external-signup-guards.php
git commit -m "feat(events): external-signup events refuse hand-added seats and role grants; external form renders before the storefront seam

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Embed sanitizer: no leaked script text, real-world iframe attributes

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php`: `sanitize_external_embed()` ≈2689 and its docblock ≈2668-2688, `get_embed_allowed_html()` iframe entry ≈2701-2714
- Test: `tests/test-external-embed-sanitizer.php` (create)

**Interfaces:**
- Produces: `Module::sanitize_external_embed( $value, $meta_key, $object_type ): string` (same signature). Tasks 9/11 call it from the migration.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * sanitize_external_embed(): iframe embeds (one or several) survive with the
 * attributes real form providers emit; scripts are dropped WHOLE.
 *
 * wp_kses() removes a disallowed tag but keeps its text, so an inline
 * <script>…</script> used to come out as visible JavaScript on the page.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group event-save
 */
class Test_External_Embed_Sanitizer extends Anchor_Events_TestCase {

	const FORM = '<iframe id="FormIFrame-123456789012345" title="Registration" src="https://forms.example.com/123456789012345" allowtransparency="true" allow="geolocation; microphone; camera; fullscreen" frameborder="0" scrolling="no" style="min-width:100%;height:900px;border:none;"></iframe>';

	private function clean( $html ) {
		return $this->module()->sanitize_external_embed( $html, '_anchor_event_external_embed', 'post' );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_events_embed_allowed_html' );
		parent::tear_down();
	}

	public function test_inline_script_body_is_dropped_not_printed() {
		$out = $this->clean( self::FORM . "\n<script src=\"https://cdn.forms.example.com/embed-handler.js\"></script>\n<script>window.formEmbedHandler(\"iframe[id='FormIFrame-123456789012345']\", \"https://forms.example.com/\");</script>" );
		$this->assertStringNotContainsString( 'formEmbedHandler', $out );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringContainsString( 'src="https://forms.example.com/123456789012345"', $out );
	}

	public function test_provider_iframe_attributes_survive() {
		$out = $this->clean( self::FORM );
		$this->assertStringContainsString( 'id="FormIFrame-123456789012345"', $out );
		$this->assertStringContainsString( 'scrolling="no"', $out );
		$this->assertStringContainsString( 'allowtransparency="true"', $out );
	}

	public function test_several_iframes_one_per_session_all_survive() {
		$two = '<iframe src="https://forms.example.com/1"></iframe><iframe src="https://forms.example.com/2"></iframe>';
		$this->assertSame( 2, substr_count( $this->clean( $two ), '<iframe' ) );
	}

	public function test_style_element_is_dropped_whole() {
		$this->assertStringNotContainsString( 'color:red', $this->clean( '<style>.x{color:red}</style>' . self::FORM ) );
	}

	public function test_a_site_that_opts_scripts_back_in_keeps_them() {
		add_filter( 'anchor_events_embed_allowed_html', function ( $allowed ) {
			$allowed['script'] = [ 'src' => true ];
			return $allowed;
		} );
		$this->assertStringContainsString( '<script src="https://cdn.forms.example.com/h.js">', $this->clean( '<script src="https://cdn.forms.example.com/h.js"></script>' ) );
	}

	public function test_event_handlers_are_still_stripped() {
		$this->assertStringNotContainsString( 'onload', $this->clean( '<iframe src="https://forms.example.com/1" onload="alert(1)"></iframe>' ) );
	}
}
```

- [ ] **Step 2: Run, expect failure**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-embed-sanitizer.php`
Expected: `test_inline_script_body_is_dropped_not_printed`, `test_provider_iframe_attributes_survive` and `test_style_element_is_dropped_whole` FAIL.

- [ ] **Step 3: Implement**

Replace the body of `sanitize_external_embed()` with:

```php
        $allowed = $this->get_embed_allowed_html();
        $html    = (string) $meta_value;
        // wp_kses() strips a disallowed TAG but keeps the text between the
        // tags, so `<script>handler(…)</script>` came out as visible JavaScript
        // on the event page (form providers' iframe embeds ship exactly such a
        // resize handler). Drop the whole element first — the same move core's
        // wp_strip_all_tags() makes — unless a site opted the tag back in.
        foreach ( [ 'script', 'style' ] as $tag ) {
            if ( ! isset( $allowed[ $tag ] ) ) {
                $html = (string) \preg_replace( '@<' . $tag . '\b[^>]*>.*?</' . $tag . '\s*>@si', '', $html );
            }
        }
        return (string) \wp_kses( $html, $allowed );
```

Correct the docblock sentence "wp_kses() strips any tag not in the allowed set entirely (open tag, body, and close tag)". It is false. Say instead that script/style elements are removed whole before `wp_kses()`.

In `get_embed_allowed_html()`, add to the `'iframe'` array:

```php
                'id' => true,
                'class' => true,
                'scrolling' => true,
                'allowtransparency' => true,
```

- [ ] **Step 4: Run, expect pass**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-embed-sanitizer.php tests/test-event-save.php tests/test-event-manager-save.php tests/test-backward-compat.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php tests/test-external-embed-sanitizer.php
git commit -m "fix(events): external embed sanitizer drops script/style bodies instead of printing them; keep provider iframe id/scrolling/allowtransparency

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Save path: checkbox → mode, presence marker, remembered native mode

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php`: `get_meta_schema()` (after `'registration_mode'` ≈2828), `get_meta_defaults()` (after `'registration_mode' => 'free'` ≈2937), `sanitize_event_type_input()` ≈6236-6256 plus its `@return` shape docblock ≈6225-6235, new `sanitize_native_registration_mode()` next to `sanitize_registration_mode()` ≈6361, new `native_registration_mode()` next to `registration_mode()` ≈12667
- Modify: `anchor-events-manager/class-occurrences.php`: `INHERITED_KEYS`, add `'native_registration_mode'` after `'registration_mode'` (≈198)
- Test: `tests/test-external-signup-save.php` (create)

**Interfaces:**
- Consumes: Task 1 predicate.
- Produces: POST contract `anchor_event_external_signup_present=1` + `anchor_event_external_signup=1|absent` + `anchor_event_registration_mode=wc|free`. Meta `_anchor_event_native_registration_mode` (`wc|free`). `Module::native_registration_mode( int $event_id ): string` returns `wc|free`; Task 6 uses it to preselect the select.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * The "Use external signup form" checkbox on the save path.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Occurrences;

/**
 * @group event-save
 */
class Test_External_Signup_Save extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		$_POST = [];
		parent::tear_down();
	}

	private function save( $event_id, array $fields ) {
		$_POST = array_merge( [
			Module::NONCE             => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date' => '2026-11-01',
			'anchor_event_registration_enabled' => '1',
		], $fields );
		$this->module()->save_meta( $event_id );
		return get_post_meta( $event_id, '_anchor_event_registration_mode', true );
	}

	public function test_ticked_box_saves_external_and_remembers_the_native_choice() {
		$id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$this->assertSame( 'external', $this->save( $id, [
			'anchor_event_external_signup_present' => '1',
			'anchor_event_external_signup'         => '1',
			'anchor_event_registration_mode'       => 'wc',
		] ) );
		$this->assertSame( 'wc', get_post_meta( $id, '_anchor_event_native_registration_mode', true ) );
		$this->assertSame( 'wc', $this->module()->native_registration_mode( $id ) );
	}

	public function test_unticking_hands_control_back_to_the_select() {
		$id = $this->make_event( [ 'registration_mode' => 'external', 'native_registration_mode' => 'wc' ] );
		$this->assertSame( 'free', $this->save( $id, [
			'anchor_event_external_signup_present' => '1',
			'anchor_event_registration_mode'       => 'free',
		] ) );
	}

	public function test_unticked_with_no_usable_select_value_restores_the_remembered_mode() {
		// A disabled "WooCommerce ticketed" option posts nothing, and an old
		// cached form may still post 'external' — neither is a native choice.
		$id = $this->make_event( [ 'registration_mode' => 'external', 'native_registration_mode' => 'wc' ] );
		$this->assertSame( 'wc', $this->save( $id, [
			'anchor_event_external_signup_present' => '1',
			'anchor_event_registration_mode'       => 'external',
		] ) );
	}

	public function test_form_without_the_checkbox_never_flips_an_external_event() {
		$id = $this->make_event( [ 'registration_mode' => 'external' ] );
		$this->assertSame( 'external', $this->save( $id, [] ), 'No marker, no select: keep what is stored.' );
	}

	public function test_legacy_select_posting_external_still_works() {
		$id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$this->assertSame( 'external', $this->save( $id, [ 'anchor_event_registration_mode' => 'external' ] ) );
		$this->assertSame( 'free', get_post_meta( $id, '_anchor_event_native_registration_mode', true ), 'The native mode it came from is remembered.' );
	}

	public function test_native_registration_mode_accessor() {
		$this->assertSame( 'free', $this->module()->native_registration_mode( $this->make_event( [ 'registration_mode' => 'free' ] ) ) );
		$this->assertSame( 'free', $this->module()->native_registration_mode( $this->make_event( [ 'registration_mode' => 'external' ] ) ), 'Nothing remembered → free.' );
	}

	public function test_dates_of_an_offering_inherit_the_remembered_mode() {
		$this->assertContains( 'native_registration_mode', Occurrences::INHERITED_KEYS );
	}
}
```

- [ ] **Step 2: Run, expect failure**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-save.php`
Expected: FAIL. `native_registration_mode` is undefined and the checkbox is ignored.

- [ ] **Step 3: Implement**

`get_meta_schema()`, after the `'registration_mode'` row:

```php
            // The wc|free choice an external-signup event goes back to when
            // "Use external signup form" is unticked (spec 2026-10-01 §3: stored
            // settings survive the external period). Written by the save path
            // only; read by native_registration_mode().
            'native_registration_mode' => [ 'type' => 'string', 'show_in_rest' => false ],
```

`get_meta_defaults()`, after `'registration_mode' => 'free',`:

```php
            'native_registration_mode' => '',
```

Add next to `registration_mode()`:

```php
    /**
     * The native mode (wc|free) this event uses when it is NOT taking sign-ups
     * on an external form: its own mode for a native event, or the remembered
     * choice for an external-signup one (free when nothing was remembered).
     * Preselects the Registration select behind the checkbox.
     *
     * @param int $event_id
     * @return string wc|free
     */
    public function native_registration_mode( $event_id ) {
        $event_id = (int) $event_id;
        $mode     = $this->registration_mode( $event_id );
        if ( $mode !== 'external' ) {
            return $mode;
        }
        $saved = (string) \get_post_meta( $event_id, $this->meta_key( 'native_registration_mode' ), true );
        return \in_array( $saved, [ 'wc', 'free' ], true ) ? $saved : 'free';
    }
```

Add next to `sanitize_registration_mode()`:

```php
    /**
     * A posted Registration select value as a NATIVE mode (wc|free). Anything
     * else (nothing posted, a disabled option, an old form's 'external') falls
     * back to what the event already resolves to.
     *
     * @param mixed  $raw
     * @param int    $post_id
     * @param string $fallback Pre-resolved registration_mode() of the event.
     * @return string wc|free
     */
    private function sanitize_native_registration_mode( $raw, $post_id, $fallback ) {
        $value = \sanitize_text_field( (string) $raw );
        if ( \in_array( $value, [ 'wc', 'free' ], true ) ) {
            return $value;
        }
        if ( (int) $post_id > 0 ) {
            return $this->native_registration_mode( (int) $post_id );
        }
        return \in_array( $fallback, [ 'wc', 'free' ], true ) ? $fallback : 'free';
    }
```

In `sanitize_event_type_input()`, after `$stream_embed = …;`, add:

```php
        // "Use external signup form" (spec 2026-10-01). When the checkbox was ON
        // the form (its hidden marker posted), it is the answer: ticked means
        // external, unticked means whatever the Registration select says. A
        // form that never carried the checkbox keeps the old rule — the select
        // alone, 'external' included — so no other save path can flip an
        // external-signup event back to native by omission.
        $posted_mode = \wp_unslash( $src['anchor_event_registration_mode'] ?? '' );
        $native      = $this->sanitize_native_registration_mode( $posted_mode, (int) $post_id, $registration_mode_fallback );
        if ( ! empty( $src['anchor_event_external_signup_present'] ) ) {
            $mode = ! empty( $src['anchor_event_external_signup'] ) ? 'external' : $native;
        } else {
            $mode = $this->sanitize_registration_mode( $posted_mode, $registration_mode_fallback );
        }
```

In the returned array, replace the `'registration_mode' => $this->sanitize_registration_mode( … ),` line with:

```php
            'registration_mode' => $mode,
            'native_registration_mode' => $mode === 'external' ? $native : $mode,
```

Update the method docblock's `@return` shape with `native_registration_mode: string`.

`class-occurrences.php` `INHERITED_KEYS`: insert `'native_registration_mode',` on the line after `'registration_mode',`.

- [ ] **Step 4: Run, expect pass, then the save/inheritance suites**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-save.php tests/test-event-save.php tests/test-event-manager-save.php tests/test-backward-compat.php tests/test-inheritance.php tests/test-group-authoring-save.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php anchor-events-manager/class-occurrences.php tests/test-external-signup-save.php
git commit -m "feat(events): save the 'Use external signup form' checkbox as registration_mode=external and remember the native mode for unticking

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Shared form renderers; native-only sections become conditional on both surfaces

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php`:
  - new public `render_registration_mode_field()` and `render_external_signup_section()` next to `render_access_fields()` (≈3353)
  - `render_livestream_fields()` ≈3216-3234 (drop the early return; section becomes conditional; fix the `@return` docblock)
  - `render_access_fields()` section tag ≈3363
  - `render_meta_box()`: mode field ≈4098-4107, insert the external section after the "Event Type & Registration" section's closing `</div>` ≈4111, delete the old External Registration section ≈4314-4333, Email Settings section tag ≈4383
  - `render_event_manager_form()`: mode field ≈9325-9334, insert the external section after its "Event Type & Registration" section ≈9338, Registration hint ≈9461, Attendee questions section tag ≈9481, delete the old External Registration section ≈9514-9533, Email Settings section tag ≈9551 plus a new step-5 notice just before it
- Test: `tests/test-external-signup-ui.php` (create)

**Interfaces:**
- Consumes: `uses_external_signup()` (T1), `native_registration_mode()` (T5).
- Produces markup contract (used by Task 7 JS and the e2e specs):
  - `#anchor_event_external_signup` checkbox, plus hidden `anchor_event_external_signup_present`
  - the select's field wrapper: `.anchor-event-conditional[data-when-mode="wc free"]`
  - the external section: `.anchor-event-section.anchor-event-conditional.anchor-event-external-signup[data-when-mode="external"][data-step="2"]`
  - native-only sections: `.anchor-event-conditional[data-when-mode="wc free"]`
  - the step-5 notice: `.anchor-event-external-signup-notice[data-when-mode="external"][data-step="5"]`

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Both authoring surfaces: the checkbox, the fields it reveals, and the
 * native-only sections it hides — which must still be RENDERED (hidden by
 * JS) so their stored values round-trip through every save.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group event-save
 */
class Test_External_Signup_Ui extends Anchor_Events_TestCase {

	const STREAM = [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/76979871', 'raw' => 'https://vimeo.com/76979871' ];

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		$_POST = [];
		parent::tear_down();
	}

	private function external_event() {
		return $this->make_event( [
			'registration_mode'        => 'external',
			'native_registration_mode' => 'wc',
			'external_embed'           => '<iframe src="https://form.example/1"></iframe>',
			'capacity'                 => 25,
			'stream_embed'             => self::STREAM,
		] );
	}

	private function metabox( $id ) {
		ob_start();
		$this->module()->render_meta_box( get_post( $id ) );
		return (string) ob_get_clean();
	}

	private function console( $id ) {
		$m = new ReflectionMethod( Module::class, 'render_event_manager_form' );
		$m->setAccessible( true );
		return (string) $m->invoke( $this->module(), $id, true );
	}

	public function test_checkbox_reflects_the_mode_and_external_is_no_longer_a_select_option() {
		foreach ( [ 'metabox', 'console' ] as $surface ) {
			$ext  = $this->$surface( $this->external_event() );
			$free = $this->$surface( $this->make_event( [ 'registration_mode' => 'free' ] ) );
			$this->assertMatchesRegularExpression( '/id="anchor_event_external_signup"[^>]*checked/', $ext, $surface );
			$this->assertDoesNotMatchRegularExpression( '/id="anchor_event_external_signup"[^>]*checked/', $free, $surface );
			$this->assertStringContainsString( 'name="anchor_event_external_signup_present" value="1"', $ext, $surface );
			$this->assertStringNotContainsString( '<option value="external"', $ext, $surface );
			$this->assertMatchesRegularExpression( '/data-when-mode="wc free"[^>]*>\s*<label for="anchor_event_registration_mode"/', $ext, $surface );
			$this->assertMatchesRegularExpression( '/<option value="wc"[^>]*selected/', $ext, "$surface preselects the remembered native mode" );
		}
	}

	public function test_external_section_sits_under_the_checkbox_on_both_surfaces() {
		foreach ( [ 'metabox', 'console' ] as $surface ) {
			$html = $this->$surface( $this->external_event() );
			$this->assertSame( 1, preg_match_all( '/class="[^"]*anchor-event-external-signup"[^>]*data-when-mode="external"[^>]*data-step="2"/', $html ), $surface );
			$this->assertStringContainsString( '&lt;iframe src=&quot;https://form.example/1&quot;&gt;', $html, "$surface textarea carries the stored embed" );
			$this->assertLessThan( strpos( $html, 'anchor_event_start_date' ), strpos( $html, 'anchor-event-external-signup"' ), "$surface: the fields appear right below the checkbox, before Date & Time" );
		}
	}

	public function test_external_metabox_still_carries_every_native_setting() {
		$html = $this->metabox( $this->external_event() );
		$this->assertStringContainsString( 'https://vimeo.com/76979871', $html, 'The stored stream is rendered (hidden), not dropped.' );
		$this->assertMatchesRegularExpression( '/anchor-event-livestream[^"]*"[^>]*data-when-mode="wc free"/', $html );
		$this->assertMatchesRegularExpression( '/anchor-event-access[^"]*"[^>]*data-when-mode="wc free"/', $html );
		$this->assertMatchesRegularExpression( '/name="anchor_event_capacity"[^>]*value="25"/', $html );
	}

	public function test_console_hides_questions_and_emails_and_explains_step_five() {
		$html = $this->console( $this->external_event() );
		$this->assertMatchesRegularExpression( '/data-when-mode="wc free"[^>]*data-step="4">\s*<h3>Attendee questions/', $html );
		$this->assertMatchesRegularExpression( '/data-when-mode="wc free"[^>]*data-step="5">\s*<h3>Email Settings/', $html );
		$this->assertMatchesRegularExpression( '/anchor-event-external-signup-notice[^>]*data-when-mode="external"[^>]*data-step="5"/', $html );
	}

	public function test_external_save_keeps_the_stored_stream() {
		$id    = $this->external_event();
		$_POST = [
			Module::NONCE                          => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date'              => '2026-11-01',
			'anchor_event_external_signup_present' => '1',
			'anchor_event_external_signup'         => '1',
			'anchor_event_registration_mode'       => 'wc',
			// What the now-always-rendered (hidden) livestream textarea posts:
			'anchor_event_stream_embed'            => 'https://vimeo.com/76979871',
			'anchor_event_capacity'                => '25',
		];
		$this->module()->save_meta( $id );
		$stream = get_post_meta( $id, '_anchor_event_stream_embed', true );
		$this->assertSame( 'https://player.vimeo.com/video/76979871', $stream['src'] ?? '' );
		$this->assertSame( '25', (string) get_post_meta( $id, '_anchor_event_capacity', true ) );
	}
}
```

- [ ] **Step 2: Run, expect failure**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-ui.php`
Expected: FAIL. There is no checkbox, the select still has `external`, the livestream is missing for external events, and the sections carry no `data-when-mode`.

- [ ] **Step 3: Add the two shared renderers** (next to `render_access_fields()`)

```php
    /**
     * "Use external signup form" + the native Registration select, shared by
     * the wp-admin metabox and the front-end console (one renderer, as
     * render_access_fields() is, so the two surfaces cannot drift).
     *
     * Ticked → registration_mode = external, and the select steps aside
     * (data-when-mode="wc free", toggled by registration-mode.js). The select
     * is still rendered and still posts: it carries the native mode the event
     * returns to when the box is unticked. The hidden marker tells the save
     * path this form carried the checkbox (sanitize_event_type_input()).
     *
     * @param int  $event_id 0 for a new event.
     * @param bool $admin    Metabox styling when true.
     * @return string Escaped HTML: two .anchor-event-field cells for a grid.
     */
    public function render_registration_mode_field( $event_id, $admin = true ) {
        $event_id  = (int) $event_id;
        $external  = $event_id > 0 && $this->uses_external_signup( $event_id );
        $native    = $this->native_registration_mode( $event_id );
        $wc_active = \class_exists( 'WooCommerce' );
        $hint      = $admin ? 'description' : 'anchor-event-hint';
        \ob_start();
        ?>
        <div class="anchor-event-field anchor-event-field--check anchor-event-external-signup-field">
            <span class="anchor-event-field-heading"><?php echo esc_html__( 'Signups', 'anchor-schema' ); ?></span>
            <input type="hidden" name="anchor_event_external_signup_present" value="1" />
            <label>
                <input type="checkbox" id="anchor_event_external_signup" name="anchor_event_external_signup" value="1" <?php checked( $external ); ?> />
                <?php echo esc_html__( 'Use external signup form', 'anchor-schema' ); ?>
            </label>
            <p class="<?php echo esc_attr( $hint ); ?>"><?php echo esc_html__( 'People sign up on another site\'s form. This event then keeps no attendee list here and sends no confirmation, reminder or roster emails. Untick it and every setting you had comes back.', 'anchor-schema' ); ?></p>
        </div>
        <div class="anchor-event-field anchor-event-conditional" data-when-mode="wc free">
            <label for="anchor_event_registration_mode"><?php echo esc_html__( 'Registration', 'anchor-schema' ); ?></label>
            <select id="anchor_event_registration_mode" name="anchor_event_registration_mode">
                <option value="wc" <?php selected( $native, 'wc' ); ?> <?php disabled( ! $wc_active ); ?>><?php echo esc_html__( 'WooCommerce ticketed', 'anchor-schema' ); ?><?php echo $wc_active ? '' : ' ' . esc_html__( '(requires WooCommerce)', 'anchor-schema' ); ?></option>
                <option value="free" <?php selected( $native, 'free' ); ?>><?php echo esc_html__( 'Free registration', 'anchor-schema' ); ?></option>
            </select>
            <?php if ( ! $wc_active ) : ?>
                <p class="<?php echo esc_attr( $hint ); ?>"><?php echo esc_html__( 'WooCommerce is inactive, so WooCommerce-ticketed registration is unavailable until it is activated.', 'anchor-schema' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /**
     * The fields "Use external signup form" reveals, directly under the
     * checkbox on both surfaces. data-step="2" places it in the console
     * wizard's Schedule step (inert in the metabox, as for Livestream).
     *
     * `external_embed` is stored already sanitized (sanitize_external_embed())
     * and shown here through esc_textarea() like any other field value.
     *
     * @param array $meta  get_meta() result.
     * @param bool  $admin Metabox styling when true.
     * @return string Escaped HTML.
     */
    public function render_external_signup_section( array $meta, $admin = true ) {
        $hint = $admin ? 'description' : 'anchor-event-hint';
        \ob_start();
        ?>
        <div class="anchor-event-section anchor-event-conditional anchor-event-external-signup" data-when-mode="external" data-step="2">
            <h3><?php echo esc_html__( 'External signup form', 'anchor-schema' ); ?></h3>
            <p class="<?php echo esc_attr( $hint ); ?>"><?php echo esc_html__( 'Paste the form\'s embed code, or link to the page where people sign up. When both are set, the embedded form is shown. It appears on the event page while "Enable registration" is ticked.', 'anchor-schema' ); ?></p>
            <div class="anchor-event-grid">
                <div class="anchor-event-field anchor-event-field-wide" style="grid-column:1/-1;">
                    <label for="anchor_event_external_embed"><?php echo esc_html__( 'Embed code', 'anchor-schema' ); ?></label>
                    <textarea id="anchor_event_external_embed" name="anchor_event_external_embed" rows="5" class="large-text code"><?php echo esc_textarea( (string) ( $meta['external_embed'] ?? '' ) ); ?></textarea>
                    <p class="<?php echo esc_attr( $hint ); ?>"><?php echo esc_html__( 'The provider\'s iframe embed. Several iframes are fine — one per session, for example. Scripts are removed.', 'anchor-schema' ); ?></p>
                </div>
                <div class="anchor-event-field">
                    <label for="anchor_event_external_url"><?php echo esc_html__( 'Signup page URL', 'anchor-schema' ); ?></label>
                    <input type="url" id="anchor_event_external_url" name="anchor_event_external_url" value="<?php echo esc_attr( (string) ( $meta['external_url'] ?? '' ) ); ?>" />
                </div>
                <div class="anchor-event-field">
                    <label for="anchor_event_external_display_price"><?php echo esc_html__( 'Display price', 'anchor-schema' ); ?></label>
                    <input type="text" id="anchor_event_external_display_price" name="anchor_event_external_display_price" value="<?php echo esc_attr( (string) ( $meta['external_display_price'] ?? '' ) ); ?>" />
                    <p class="<?php echo esc_attr( $hint ); ?>"><?php echo esc_html__( 'Display-only price label, e.g. $495. Not connected to WooCommerce.', 'anchor-schema' ); ?></p>
                </div>
            </div>
        </div>
        <?php
        return (string) \ob_get_clean();
    }
```

- [ ] **Step 4: Wire both surfaces**

1. `render_meta_box()`: replace the whole `<div class="anchor-event-field">` holding `<label for="anchor_event_registration_mode">…</select>…<?php endif; ?></div>` (≈4097-4107) with
   `<?php echo $this->render_registration_mode_field( $post->ID, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>`.
   Directly after the closing `</div>` of that "Event Type & Registration" section (just before `<div class="anchor-event-section">` + `Date & Time`), add
   `<?php echo $this->render_external_signup_section( $meta, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>`.
   Delete the old `<div class="anchor-event-section anchor-event-conditional" data-when-mode="external">` … `</div>` block (≈4314-4333).
   Change the metabox Email Settings opener (≈4383) from `<div class="anchor-event-section">` to `<div class="anchor-event-section anchor-event-conditional" data-when-mode="wc free">`. Use the `<h3>…'Email Settings'` line below it for uniqueness, since the wizard's opener differs by `data-step="5"`.
   The variable `$wc_active` in `render_meta_box()` may become unused. Delete it if so.
2. `render_event_manager_form()`: replace the same mode `<div class="anchor-event-field">` (≈9324-9334, the one after the `$event_type === 'recurring'` select) with
   `<?php echo $this->render_registration_mode_field( $event_id, false ); // already escaped ?>`.
   Directly after the closing `</div>` of the wizard's "Event Type & Registration" section (just before `<div class="anchor-event-section" data-step="2">` + `Date & Time`), add
   `<?php echo $this->render_external_signup_section( $meta, false ); // already escaped ?>`.
   Delete the old `<div class="anchor-event-section anchor-event-conditional" data-when-mode="external" data-step="4">` … `</div>` block (≈9514-9533). Delete `$registration_mode` / `$wc_active` locals if they are now unused.
3. Wizard Registration hint (≈9461): replace the sentence `If sign-ups happen on another site instead, choose External above.` with `If people sign up on another site's form instead, tick "Use external signup form" in step 2.`. The string is `__( 'How many people can come, and what they are asked when they sign up here. If people sign up on another site\'s form instead, tick "Use external signup form" in step 2.', 'anchor-schema' )`.
4. Wizard Attendee questions opener (≈9481): `<div class="anchor-event-section" data-step="4">` immediately above `<h3>…'Attendee questions'` becomes `<div class="anchor-event-section anchor-event-conditional" data-when-mode="wc free" data-step="4">`.
5. Wizard Email Settings opener (≈9551): `<div class="anchor-event-section" data-step="5">` immediately above `<h3>…'Email Settings'` becomes `<div class="anchor-event-section anchor-event-conditional" data-when-mode="wc free" data-step="5">`. Immediately BEFORE it, insert:

```php
            <div class="anchor-event-section anchor-event-conditional anchor-event-external-signup-notice" data-when-mode="external" data-step="5">
                <h3><?php echo esc_html__( 'Emails', 'anchor-schema' ); ?></h3>
                <p class="anchor-event-hint anchor-event-hint--section"><?php echo esc_html__( 'This event takes sign-ups on an external form, so it sends no confirmation, reminder or roster emails. Untick "Use external signup form" in step 2 to bring them back — your wording is kept.', 'anchor-schema' ); ?></p>
            </div>
```

6. `render_livestream_fields()`: delete

```php
        if ( $this->registration_mode( (int) $event_id ) === 'external' ) {
            return '';
        }
```

   then change `<div class="anchor-event-section anchor-event-livestream" data-step="3">` to `<div class="anchor-event-section anchor-event-livestream anchor-event-conditional" data-when-mode="wc free" data-step="3">`. Change the docblock `@return string '' when the event can never hold a stream.` to `@return string Escaped HTML. Always rendered, even for an external-signup event (hidden by JS): an absent textarea saves as "clear the stream", which wiped the stored embed on every external save.`
7. `render_access_fields()`: change `<div class="anchor-event-section anchor-event-access" data-step="4">` to `<div class="anchor-event-section anchor-event-access anchor-event-conditional" data-when-mode="wc free" data-step="4">`.

- [ ] **Step 5: Run, expect pass, then every authoring suite**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-ui.php tests/test-backward-compat.php tests/test-email-builder.php tests/test-event-labels.php tests/test-group-notices.php tests/test-event-manager-save.php tests/test-event-save.php tests/test-entitlements.php`
Expected: PASS. If a test asserted the old `External Registration` heading or `<option value="external"`, update its assertion to the new markup contract above and say so in the commit body.

- [ ] **Step 6: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php tests/test-external-signup-ui.php
git commit -m "feat(events): 'Use external signup form' checkbox on both surfaces; external fields directly under it; native-only sections conditional and always rendered

Fixes the stored livestream being wiped on every external-mode save.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Visibility JS: one shared rule for both forms, plus the metabox postboxes

**Files:**
- Create: `anchor-events-manager/assets/registration-mode.js`
- Modify: `anchor-events-manager/assets/admin.js` (`toggleRegistration` ≈43-49, `applyConditionalVisibility` ≈65-79, ready bindings ≈346-347)
- Modify: `anchor-events-manager/assets/manager.js` (same three spots ≈43-49, ≈66-81, ≈381-382)
- Modify: `anchor-events-manager/assets/admin.css` (append one rule)
- Modify: `anchor-events-manager/anchor-events-manager.php`: register the script and add it as a dependency of `anchor-events-admin` (≈7656) and `anchor-events-manager-frontend` (≈8687)
- Create: `tests/js/registration-mode-harness.js`
- Test: `tests/test-external-signup-js.php` (create)
- Modify: `e2e/event-authoring.spec.js` (≈80-112, ≈122, ≈210), `e2e/event-manager-authoring.spec.js` (≈79-95, ≈110, ≈140)

**Interfaces:**
- Consumes: the Task 6 markup contract.
- Produces: `window.AnchorEventsRegistrationMode.effective( externalChecked:boolean, selectValue:string ): 'wc'|'free'|'external'` and `.matches( whenMode:string|undefined, mode:string ): boolean` (also `module.exports` under Node).

- [ ] **Step 1: Write the failing test + harness**

`tests/js/registration-mode-harness.js`:

```js
/*
 * Node harness for anchor-events-manager/assets/registration-mode.js (driven
 * by Test_External_Signup_Js). Usage:
 *   node registration-mode-harness.js <registration-mode.js path> '<cases JSON>'
 *   cases = [ { fn: 'effective'|'matches', args: [...] }, ... ]
 * Prints the results as a JSON array.
 */
'use strict';
const api = require(process.argv[2]);
const cases = JSON.parse(process.argv[3]);
process.stdout.write(JSON.stringify(cases.map((c) => api[c.fn].apply(null, c.args))));
```

`tests/test-external-signup-js.php`:

```php
<?php
/**
 * The one visibility rule both authoring forms use (registration-mode.js),
 * and that both forms actually use it.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group assets
 */
class Test_External_Signup_Js extends Anchor_Events_TestCase {

	private function run_cases( array $cases ) {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		if ( $node === '' ) {
			$this->markTestSkipped( 'node is not installed.' );
		}
		$root = dirname( __DIR__ );
		$cmd  = escapeshellarg( $node ) . ' ' . escapeshellarg( $root . '/tests/js/registration-mode-harness.js' )
			. ' ' . escapeshellarg( $root . '/anchor-events-manager/assets/registration-mode.js' )
			. ' ' . escapeshellarg( wp_json_encode( $cases ) ) . ' 2>&1';
		$out  = (string) shell_exec( $cmd );
		$data = json_decode( $out, true );
		$this->assertIsArray( $data, 'Harness output: ' . $out );
		return $data;
	}

	public function test_checkbox_wins_then_the_select() {
		$this->assertSame( [ 'external', 'wc', 'free', 'free' ], $this->run_cases( [
			[ 'fn' => 'effective', 'args' => [ true, 'wc' ] ],
			[ 'fn' => 'effective', 'args' => [ false, 'wc' ] ],
			[ 'fn' => 'effective', 'args' => [ false, 'free' ] ],
			[ 'fn' => 'effective', 'args' => [ false, '' ] ],
		] ) );
	}

	public function test_when_mode_lists() {
		$this->assertSame( [ true, false, true, true, false ], $this->run_cases( [
			[ 'fn' => 'matches', 'args' => [ 'wc free', 'free' ] ],
			[ 'fn' => 'matches', 'args' => [ 'wc free', 'external' ] ],
			[ 'fn' => 'matches', 'args' => [ 'external', 'external' ] ],
			[ 'fn' => 'matches', 'args' => [ null, 'external' ] ],
			[ 'fn' => 'matches', 'args' => [ 'wc', 'free' ] ],
		] ) );
	}

	public function test_both_forms_use_the_shared_rule_and_watch_the_checkbox() {
		$base = dirname( __DIR__ ) . '/anchor-events-manager/assets/';
		foreach ( [ 'admin.js', 'manager.js' ] as $file ) {
			$src = file_get_contents( $base . $file );
			$this->assertStringContainsString( 'AnchorEventsRegistrationMode', $src, $file );
			$this->assertStringContainsString( '#anchor_event_external_signup', $src, $file );
		}
		$shared = file_get_contents( $base . 'registration-mode.js' );
		$this->assertStringNotContainsString( 'export ', $shared );
		$this->assertStringNotContainsString( 'import ', $shared );
	}
}
```

- [ ] **Step 2: Run, expect failure**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-js.php`
Expected: FAIL. `registration-mode.js` does not exist.

- [ ] **Step 3: Create `anchor-events-manager/assets/registration-mode.js`**

```js
/**
 * The registration-mode visibility rule, shared by admin.js (wp-admin
 * metabox) and manager.js (front-end console) so the two forms cannot
 * disagree about which sections a mode shows.
 *
 * "Use external signup form" (#anchor_event_external_signup) wins; otherwise
 * the Registration select (wc|free) decides. A container's data-when-mode
 * lists the modes it shows for (space-separated); none means always.
 *
 * Plain script, no dependencies: it also loads under Node for the test
 * harness (tests/js/registration-mode-harness.js).
 */
(function (root) {
  'use strict';
  var api = {
    effective: function (externalChecked, selectValue) {
      if (externalChecked) { return 'external'; }
      return selectValue ? String(selectValue) : 'free';
    },
    matches: function (whenMode, mode) {
      if (!whenMode) { return true; }
      return String(whenMode).split(/\s+/).indexOf(mode) !== -1;
    }
  };
  root.AnchorEventsRegistrationMode = api;
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
})(typeof window !== 'undefined' ? window : this);
```

- [ ] **Step 4: Use it in admin.js and manager.js**

In BOTH files:

Replace `toggleRegistration()` with:

```js
  function currentMode(){
    return window.AnchorEventsRegistrationMode.effective(
      $('#anchor_event_external_signup').is(':checked'),
      $('#anchor_event_registration_mode').val()
    );
  }

  // Capacity, dates, waitlist, sold-out and price only mean something for a
  // registration taken here: shown when registration is on AND the event is
  // not using an external signup form.
  function toggleRegistration(){
    var on = $('#anchor_event_registration_enabled').is(':checked') && currentMode() !== 'external';
    $('.anchor-event-registration-fields').toggle(on);
  }
```

Replace the body of `applyConditionalVisibility()` with:

```js
    var type = $('#anchor_event_type').val();
    var mode = currentMode();
    var rule = window.AnchorEventsRegistrationMode;

    $('.anchor-event-conditional').each(function(){
      var $el = $(this);
      var whenType = $el.attr('data-when-type');
      var typeMatches = !whenType || whenType.split(/\s+/).indexOf(type) !== -1;
      $el.toggle(typeMatches && rule.matches($el.attr('data-when-mode'), mode));
    });

    toggleRegistration();
```

In **admin.js only**, append inside `applyConditionalVisibility()` after `toggleRegistration();`:

```js
    // The wp-admin postboxes that only serve native registration. A class,
    // not .toggle(): Screen Options owns these boxes' own display state.
    $('#anchor_event_ticket_types, #anchor_event_registrants, #anchor_event_emails')
      .toggleClass('anchor-event-native-only-hidden', mode === 'external');
```

In the ready block of BOTH files, change
`$('#anchor_event_type, #anchor_event_registration_mode').on('change', applyConditionalVisibility);`
to
`$('#anchor_event_type, #anchor_event_registration_mode, #anchor_event_external_signup').on('change', applyConditionalVisibility);`.
Also update the comment above `applyConditionalVisibility` to say the mode comes from `registration-mode.js`.

Append to `anchor-events-manager/assets/admin.css`:

```css
/* "Use external signup form": postboxes that only serve native registration step aside (admin.js). */
.anchor-event-native-only-hidden { display: none !important; }
```

- [ ] **Step 5: Enqueue as a dependency**

In `anchor-events-manager.php`, directly before the `\wp_enqueue_script( 'anchor-events-admin', …` line (≈7656), add

```php
        \wp_register_script( 'anchor-events-registration-mode', \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/registration-mode.js' ), [], $this->asset_version( 'anchor-events-manager/assets/registration-mode.js' ), true );
```

and change that enqueue's deps to `[ 'jquery', 'jquery-ui-sortable', 'anchor-events-registration-mode' ]`. Do the same before the `'anchor-events-manager-frontend'` enqueue (≈8685): add the same `wp_register_script` line there too (registering twice is a harmless no-op), and set its deps to `[ 'jquery', 'jquery-ui-sortable', 'anchor-events-registration-mode' ]`.

- [ ] **Step 6: Run, expect pass**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-js.php tests/test-assets.php`
Expected: PASS.

- [ ] **Step 7: Update the e2e specs to the checkbox**

`e2e/event-authoring.spec.js`, first test: keep `modeSelect`, and add `const externalBox = page.locator('#anchor_event_external_signup');`. Change `externalSection` to `page.locator('.anchor-event-external-signup')`. Replace the "Selecting External…" and "Switching to WooCommerce…" blocks with:

```js
  // Ticking "Use external signup form" reveals the external fields, hides the
  // Registration select and the WooCommerce ticket tiers.
  await externalBox.check();
  await expect(externalSection).toBeVisible();
  await expect(modeSelect).toBeHidden();
  await expect(ticketsBox).toBeHidden();

  // Unticking hands control back to the select; WooCommerce brings the tiers back.
  await externalBox.uncheck();
  await expect(modeSelect).toBeVisible();
  await modeSelect.selectOption('wc');
  await expect(ticketsBox).toBeVisible();
  await expect(externalSection).toBeHidden();
```

In the same file, change `await page.locator('#anchor_event_registration_mode').selectOption('external');` (≈122) to `await page.locator('#anchor_event_external_signup').check();`, and change `await expect(page.locator('#anchor_event_registration_mode')).toHaveValue('external');` (≈210) to `await expect(page.locator('#anchor_event_external_signup')).toBeChecked();`.

Make the same three kinds of edit in `e2e/event-manager-authoring.spec.js` (≈79-95, ≈110, ≈140). Use `.anchor-event-external-signup` there too. The step-5 notice also carries `data-when-mode="external"`, so the old attribute locator would match two elements.

Run (only if wp-env is up): `npx playwright test e2e/event-authoring.spec.js e2e/event-manager-authoring.spec.js e2e/event-frontend.spec.js`. Expected: these specs pass. The suite has been red since 09-03 (memory `anchor-tools-e2e-workflow-red-since-09-03`), so compare failures against a baseline run on `0efe7bd` and read the logs. Never write a failure off as "pre-existing" without that comparison.

- [ ] **Step 8: Commit**

```bash
git add anchor-events-manager/assets/registration-mode.js anchor-events-manager/assets/admin.js anchor-events-manager/assets/manager.js anchor-events-manager/assets/admin.css anchor-events-manager/anchor-events-manager.php tests/js/registration-mode-harness.js tests/test-external-signup-js.php e2e/event-authoring.spec.js e2e/event-manager-authoring.spec.js
git commit -m "feat(events): one shared registration-mode visibility rule for both forms; the checkbox hides native-only sections and postboxes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Console and list surfaces stop offering a roster for external-signup events

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php`: `event_row_actions()` ≈11620, `render_event_manager_item()` ≈9119-9140, `render_registrant_counts()` ≈14487, `render_event_role_panel()` ≈3445
- Test: `tests/test-external-signup-console.php` (create)

**Interfaces:**
- Consumes: `uses_external_signup()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Admin lists and the console stop pointing at a roster that does not exist.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group roster
 */
class Test_External_Signup_Console extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function ext() {
		return $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/x' ] );
	}

	private function item( $id ) {
		$m = new ReflectionMethod( Module::class, 'render_event_manager_item' );
		$m->setAccessible( true );
		return (string) $m->invoke( $this->module(), get_post( $id ) );
	}

	public function test_no_roster_row_action() {
		$this->assertArrayNotHasKey( 'anchor_roster', $this->module()->event_row_actions( [], get_post( $this->ext() ) ) );
		$this->assertArrayHasKey( 'anchor_roster', $this->module()->event_row_actions( [], get_post( $this->make_event() ) ) );
	}

	public function test_console_list_item_says_external_and_offers_no_attendees_or_export() {
		$html = $this->item( $this->ext() );
		$this->assertStringContainsString( 'External signup form', $html );
		$this->assertStringNotContainsString( 'event_action=roster', $html );
		$this->assertStringNotContainsString( 'anchor_event_export', $html );
		$this->assertStringContainsString( 'event_action=roster', $this->item( $this->make_event() ), 'Control: native events keep the link.' );
	}

	public function test_no_event_role_panel() {
		$this->assertSame( '', $this->module()->render_event_role_panel( $this->ext() ) );
	}
}
```

- [ ] **Step 2: Run, expect failure**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-console.php`
Expected: FAIL. The roster action and links are present, and the role panel prints its "turn it on in Access" line.

- [ ] **Step 3: Implement**

`event_row_actions()`: add `&& ! $this->uses_external_signup( $post->ID )` to the `if` condition.

`render_registrant_counts()`: make this the first statement:

```php
        if ( $this->uses_external_signup( (int) $event_id ) ) {
            return ' <span class="anchor-event-admin-count anchor-event-admin-count--external">' . esc_html__( 'External signup form', 'anchor-schema' ) . '</span>';
        }
```

`render_event_manager_item()`: just before the `if ( Roster::current_user_can_manage() ) {` that builds `$roster_url`, add `$external = $this->uses_external_signup( $event->ID );`. Change both following `if ( Roster::current_user_can_manage() )` conditions (the Attendees link and the Export CSV link) to `if ( ! $external && Roster::current_user_can_manage() )`. Change `if ( empty( $registrations ) ) {` to:

```php
        if ( $external ) {
            $output .= '<p class="anchor-event-admin-empty">' . esc_html__( 'Sign-ups for this event happen on an external form.', 'anchor-schema' ) . '</p>';
        } elseif ( empty( $registrations ) ) {
```

`render_event_role_panel()`: change the first guard to

```php
        if ( $event_id <= 0 || ! $this->entitlements || ! Roster::current_user_can_manage() || $this->uses_external_signup( $event_id ) ) {
            return '';
        }
```

- [ ] **Step 4: Run, expect pass**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/test-external-signup-console.php tests/test-roster.php tests/test-entitlements.php`
Expected: PASS. Then run the whole suite: `vendor/bin/phpunit -c phpunit.xml.dist`. Expected: no new failures against a baseline run of `0efe7bd`.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php tests/test-external-signup-console.php
git commit -m "feat(events): admin list, console list and role panel stop offering a roster for external-signup events

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

**Plugin hand-off gate:** Tasks 1–8 go through branch review, a PR (check the file count is under 150 per the CodeRabbit rule; this branch is ~20 files), explicit human sign-off to merge, and a tagged Anchor-Tools release. Tasks 9–11 need that release.

---

## DEKA (deploy-time, production)

Facts verified read-only on live 2026-10-01: Anchor-Tools 3.34.1 is active (dir `wp-content/plugins/Anchor-Tools`). There are 22 free-mode events and 0 external. 16 events carry `_deka_event_reg_embed`: 7529, 7528, 7396, 7395, 7250, 7252, 7254, 7255, 7256, 7257, 7258 (parent), 7259, 7260 (parent), 7261, 7262, 7263. Each has one iframe, and only 7263 carries the two-script JotForm resize handler. Offering dates 7530/7531 (parent 7260) have no embed of their own and display the parent's. **No seat exists on any of them.** The theme reads the embed in `inc/events-meta.php`, `events/single-event.php:32,71` and `template-events-manager.php:100`.

**Ordering replaces the spec's "theme falls back to its own meta until migrated":** (1) plugin release, (2) migration (the unchanged theme keeps rendering the legacy meta, so pages don't move), (3) theme deploy reading only the plugin field, (4) legacy key renamed. That way no fallback code ever ships, and a later-unticked event can never resurrect a stale JotForm.

### Task 9: Migration script (deka-context repo)

**Files:**
- Create: `/Users/bif/Desktop/deka-context/deka-theme/tools/migrate-reg-embed-to-plugin.php`

**Interfaces:**
- Consumes: `Module::sanitize_external_embed()` (T4), `Module::uses_external_signup()` (T1, used as the "new plugin present" probe), `Occurrences::is_group_parent/children_any_status/is_group_child`, theme `deka_event_parse_reg_form_ids()`.
- Produces: modes `dry` (default, writes nothing), `apply`, `retire`, `rollback`. Output is one row per event plus a `ROLLBACK {json}` line.

- [ ] **Step 1: Write the script**

```php
<?php
/**
 * DEKA one-off (2026-10-01): retire the theme's JotForm field
 * (_deka_event_reg_embed) into Anchor Events' "Use external signup form"
 * (registration_mode=external + external_embed).
 *
 *   wp eval-file migrate-reg-embed-to-plugin.php            dry run — writes NOTHING
 *   wp eval-file migrate-reg-embed-to-plugin.php apply      set mode + embed (+ form id)
 *   wp eval-file migrate-reg-embed-to-plugin.php retire     AFTER the theme deploy: rename the legacy key
 *   wp eval-file migrate-reg-embed-to-plugin.php rollback   undo apply (mode back to native, embed cleared)
 *
 * Idempotent. Offering dates with no embed of their own get their parent's
 * (that is what the theme showed them).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$mode = isset( $args[0] ) ? (string) $args[0] : 'dry';
$m    = class_exists( '\Anchor\Events\Module' ) ? \Anchor\Events\Module::instance() : null;
if ( ! $m ) {
	WP_CLI::error( 'Anchor Events is not active.' );
}
if ( 'dry' !== $mode && ! is_callable( [ $m, 'uses_external_signup' ] ) ) {
	WP_CLI::error( 'This Anchor-Tools has no external-signup support yet — update the plugin first.' );
}

$legacy_key = ( 'rollback' === $mode ) ? '_deka_event_reg_embed_legacy' : '_deka_event_reg_embed';
$own = get_posts( [
	'post_type'   => 'event',
	'post_status' => 'any',
	'numberposts' => -1,
	'fields'      => 'ids',
	'meta_query'  => [ [ 'key' => $legacy_key, 'value' => '', 'compare' => '!=' ] ],
] );
if ( 'rollback' === $mode && ! $own ) { // rollback before retire: legacy key not renamed yet
	$own = get_posts( [ 'post_type' => 'event', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
		'meta_query' => [ [ 'key' => '_deka_event_reg_embed', 'value' => '', 'compare' => '!=' ] ] ] );
}

$targets = [];
foreach ( $own as $id ) {
	$targets[ (int) $id ] = (string) ( get_post_meta( $id, '_deka_event_reg_embed', true ) ?: get_post_meta( $id, '_deka_event_reg_embed_legacy', true ) );
}
foreach ( array_keys( $targets ) as $id ) {
	if ( ! $m->occurrences->is_group_parent( $id ) ) {
		continue;
	}
	foreach ( $m->occurrences->children_any_status( $id ) as $child ) {
		if ( ! isset( $targets[ (int) $child ] ) ) {
			$targets[ (int) $child ] = $targets[ $id ];
		}
	}
}

$rollback = [];
$refused  = 0;
foreach ( $targets as $id => $raw ) {
	$role = $m->occurrences->is_group_parent( $id ) ? 'parent' : ( $m->occurrences->is_group_child( $id ) ? 'child' : 'single' );
	$rollback[ $id ] = [
		'registration_mode'        => (string) get_post_meta( $id, '_anchor_event_registration_mode', true ),
		'native_registration_mode' => (string) get_post_meta( $id, '_anchor_event_native_registration_mode', true ),
		'external_embed'           => (string) get_post_meta( $id, '_anchor_event_external_embed', true ),
	];

	if ( 'rollback' === $mode ) {
		$native = (string) get_post_meta( $id, '_anchor_event_native_registration_mode', true );
		update_post_meta( $id, '_anchor_event_registration_mode', in_array( $native, [ 'wc', 'free' ], true ) ? $native : 'free' );
		delete_post_meta( $id, '_anchor_event_external_embed' );
		$legacy = get_post_meta( $id, '_deka_event_reg_embed_legacy', true );
		if ( '' !== (string) $legacy ) {
			update_post_meta( $id, '_deka_event_reg_embed', wp_slash( $legacy ) );
			delete_post_meta( $id, '_deka_event_reg_embed_legacy' );
		}
		WP_CLI::log( "$id\trolled back" );
		continue;
	}

	if ( 'retire' === $mode ) {
		$has_plugin = 'external' === $m->registration_mode( $id ) && '' !== (string) $m->get_meta( $id )['external_embed'];
		$legacy     = (string) get_post_meta( $id, '_deka_event_reg_embed', true );
		if ( $has_plugin && '' !== $legacy ) {
			update_post_meta( $id, '_deka_event_reg_embed_legacy', wp_slash( $legacy ) );
			delete_post_meta( $id, '_deka_event_reg_embed' );
			WP_CLI::log( "$id\tretired" );
		} else {
			WP_CLI::log( "$id\tskipped (" . ( $has_plugin ? 'no legacy key' : 'not migrated' ) . ')' );
		}
		continue;
	}

	$clean = $m->sanitize_external_embed( $raw, '_anchor_event_external_embed', 'post' );
	$in    = substr_count( strtolower( $raw ), '<iframe' );
	$out   = substr_count( strtolower( $clean ), '<iframe' );
	$leak  = (bool) preg_match( '/<script|jotformEmbedHandler/i', $clean );
	$ok    = $in > 0 && $in === $out && ! $leak;
	if ( ! $ok ) {
		$refused++;
	}
	WP_CLI::log( sprintf( "%d\t%s\t%s\t%s\tiframes %d->%d%s\t%s", $id, get_post_status( $id ), $role, $m->registration_mode( $id ), $in, $out, $leak ? "\tSCRIPT-LEAK" : '', $ok ? 'OK' : 'REFUSED' ) );

	if ( 'apply' === $mode && $ok ) {
		$native = $m->registration_mode( $id );
		update_post_meta( $id, '_anchor_event_native_registration_mode', in_array( $native, [ 'wc', 'free' ], true ) ? $native : 'free' );
		update_post_meta( $id, '_anchor_event_external_embed', wp_slash( $clean ) );
		update_post_meta( $id, '_anchor_event_registration_mode', 'external' );
		if ( function_exists( 'deka_event_parse_reg_form_ids' ) ) {
			$ids = deka_event_parse_reg_form_ids( $clean );
			if ( $ids ) {
				update_post_meta( $id, '_deka_event_reg_form_id', implode( ',', $ids ) );
			}
		}
	}
}

WP_CLI::log( 'ROLLBACK ' . wp_json_encode( $rollback ) );
WP_CLI::log( sprintf( '%s: %d events, %d refused.', $mode, count( $targets ), $refused ) );
```

- [ ] **Step 2: Lint**

Run: `php -l /Users/bif/Desktop/deka-context/deka-theme/tools/migrate-reg-embed-to-plugin.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Read-only dry run against the CURRENT live plugin (proves the refusal check works)**

The upload goes outside the web root and is deleted afterwards. The dry run writes nothing.

```bash
source ~/deka-work/deka.sh
dscp_up /Users/bif/Desktop/deka-context/deka-theme/tools/migrate-reg-embed-to-plugin.php /tmp/migrate-reg-embed-to-plugin.php
dwp "eval-file /tmp/migrate-reg-embed-to-plugin.php"
```

Expected on 3.34.1: 18 rows, all mode `free`. 7263 shows `SCRIPT-LEAK REFUSED` (the old sanitizer prints the handler text), and the rest show `OK`. That refusal is the Task 4 bug, seen on real data.

- [ ] **Step 4: Commit (deka-context repo, this file only)**

```bash
cd /Users/bif/Desktop/deka-context
git add deka-theme/tools/migrate-reg-embed-to-plugin.php
git commit -m "tools: one-off migration of the theme JotForm embed into Anchor Events' external signup

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 10: Theme changes, prepared locally (no server write in this task)

**Files (live theme, pulled fresh):** `inc/events-meta.php`, `events/single-event.php`, `template-events-manager.php` under `/www/dekalasers_639/public/wp-content/themes/deka/`

- [ ] **Step 1: Pull fresh copies (the server is the source of truth)**

```bash
source ~/deka-work/deka.sh
mkdir -p ~/deka-work/stage/inc ~/deka-work/stage/events
dgrab inc/events-meta.php ~/deka-work/stage/inc/events-meta.php
dgrab events/single-event.php ~/deka-work/stage/events/single-event.php
dgrab template-events-manager.php ~/deka-work/stage/template-events-manager.php
```

- [ ] **Step 2: `inc/events-meta.php` edits**

a. In the `init` callback, delete the `register_post_meta( DEKA_EVENT_CPT, '_deka_event_reg_embed', … )` call and the comment above it ("Registration embed holds raw iframe/script HTML…"). Keep `_deka_event_reg_form_id`. Update its comment to say it is derived from the plugin's `_anchor_event_external_embed`.

b. In `deka_event_fields()`, delete `$embed = get_post_meta( $post_id, '_deka_event_reg_embed', true );` and the final `<p><label for="deka_event_reg_embed">…</textarea></p>`. Put this in its place:

```php
	<p class="description"><?php esc_html_e( 'Registration form: tick "Use external signup form" in Event Details and paste the JotForm embed there.', 'deka' ); ?></p>
```

c. In the `save_post_` handler, delete everything from `// Embed: admins entering trusted JotForm code.` through the closing `}` of the `if ( $form_ids ) { … } else { … }` block.

d. Delete the function `deka_event_embed_allowed_html()` and its docblock.

e. In `deka_event_reg_form_ids()`, change the fallback line to `return deka_event_parse_reg_form_ids( deka_event_reg_embed( $post_id ) );`.

f. Add after `deka_event_reg_form_ids()`:

```php
/**
 * The signup embed for an event: Anchor Events' own field, set by ticking
 * "Use external signup form" (registration_mode = external). A date of a
 * multi-date offering with none of its own shows its parent's. '' for a
 * natively-registered event, or with the plugin off.
 *
 * Replaced the theme-only _deka_event_reg_embed field on 2026-10-01 (that
 * value survives as _deka_event_reg_embed_legacy, read by nothing).
 *
 * @param int $post_id
 * @return string Already sanitized by the plugin on save.
 */
function deka_event_reg_embed( $post_id ) {
	$m = deka_events_module();
	$post_id = (int) $post_id;
	if ( ! $m || 'external' !== $m->registration_mode( $post_id ) ) {
		return '';
	}
	$embed = (string) ( $m->get_meta( $post_id )['external_embed'] ?? '' );
	if ( '' === $embed && $m->occurrences->is_group_child( $post_id ) ) {
		$pid = (int) $m->occurrences->parent_of( $post_id );
		if ( $pid > 0 && 'external' === $m->registration_mode( $pid ) ) {
			$embed = (string) ( $m->get_meta( $pid )['external_embed'] ?? '' );
		}
	}
	return $embed;
}

/**
 * Keep _deka_event_reg_form_id in step with the plugin's embed (THEME-D48) on
 * EVERY write path — wp-admin, the console (which writes meta after
 * save_post fires), the plugin copying a parent's value to its dates, WP-CLI.
 */
function deka_event_sync_reg_form_id( $meta_id, $post_id, $meta_key, $meta_value ) {
	if ( '_anchor_event_external_embed' !== $meta_key || DEKA_EVENT_CPT !== get_post_type( $post_id ) ) {
		return;
	}
	$ids = deka_event_parse_reg_form_ids( is_string( $meta_value ) ? $meta_value : '' );
	if ( $ids ) {
		update_post_meta( $post_id, '_deka_event_reg_form_id', implode( ',', $ids ) );
	} else {
		delete_post_meta( $post_id, '_deka_event_reg_form_id' );
	}
}
add_action( 'added_post_meta', 'deka_event_sync_reg_form_id', 10, 4 );
add_action( 'updated_post_meta', 'deka_event_sync_reg_form_id', 10, 4 );
add_action( 'deleted_post_meta', function ( $meta_ids, $post_id, $meta_key ) {
	if ( '_anchor_event_external_embed' === $meta_key && DEKA_EVENT_CPT === get_post_type( $post_id ) ) {
		delete_post_meta( $post_id, '_deka_event_reg_form_id' );
	}
}, 10, 3 );
```

- [ ] **Step 3: `events/single-event.php` edits**

Line ≈32: `$embed    = get_post_meta( $id, '_deka_event_reg_embed', true );` becomes `$embed    = function_exists( 'deka_event_reg_embed' ) ? deka_event_reg_embed( $id ) : '';`.
Line ≈71: delete `if ( $embed === '' ) $embed = get_post_meta( $pid, '_deka_event_reg_embed', true );` (the helper already falls back to the parent). In the comment above it, change "(CE, instructor, embed)" to "(CE, instructor)".

- [ ] **Step 4: `template-events-manager.php` edit**

Line ≈100: `$embed     = (string) get_post_meta( $id, '_deka_event_reg_embed', true );` becomes `$embed     = function_exists( 'deka_event_reg_embed' ) ? deka_event_reg_embed( $id ) : '';`.

- [ ] **Step 5: Lint + leftover scan**

```bash
for f in ~/deka-work/stage/inc/events-meta.php ~/deka-work/stage/events/single-event.php ~/deka-work/stage/template-events-manager.php; do php -l "$f"; done
grep -n "_deka_event_reg_embed\b\|deka_event_embed_allowed_html" ~/deka-work/stage -r
```

Expected: no syntax errors. The grep returns nothing except the docblock line mentioning `_deka_event_reg_embed_legacy`.

### Task 11: Production runbook (live; needs Task 8's release + sign-off)

- [ ] **Step 1: Backups**
  - Kinsta manual backup of the live environment (skill `kinsta`), label `pre-external-signup`.
  - Plugin dir copy: `dssh "cd /www/dekalasers_639/public/wp-content/plugins && cp -a Anchor-Tools Anchor-Tools.bak-$(date +%Y%m%d-%H%M)-pre-external-signup"`.
- [ ] **Step 2: Update the plugin**: `dwp "plugin update Anchor-Tools"`, then `dwp "plugin list --name=Anchor-Tools --fields=name,version,status"`. Expected: the new version, `active`. Smoke test: pick one of the 6 embed-less free events (from `dwp "post list --post_type=event --post_status=publish --meta_key=_anchor_event_registration_mode --meta_value=free --fields=ID,post_title,url"`, take an ID not among the 18 above). It must still render its old registration box (Playwright screenshot). One embed event (7254) must still show its JotForm.
- [ ] **Step 3: Dry run on the new plugin**: `dwp "eval-file /tmp/migrate-reg-embed-to-plugin.php"` (re-upload with `dscp_up` if `/tmp` was cleared). Expected: `dry: 18 events, 0 refused`. Save the full output locally as `~/deka-work/backups/2026-10-01-reg-embed-dryrun.txt` (it has the ROLLBACK JSON: JotForm ids, no personal data).
- [ ] **Step 4: Apply**: `dwp "eval-file /tmp/migrate-reg-embed-to-plugin.php apply"`. Expected: `apply: 18 events, 0 refused`.
- [ ] **Step 5: Verify data (read-only)**: re-run the dry run, `dwp "eval-file /tmp/migrate-reg-embed-to-plugin.php"`. It writes nothing and prints each event's current mode. Expected: all 18 rows show mode `external`, `iframes 1->1`, `OK`. Then `dwp "post list --post_type=event --post_status=any --meta_key=_anchor_event_registration_mode --meta_value=free --fields=ID,post_title"` should list exactly the 6 embed-less events. The pages are visually unchanged because the theme is still old.
- [ ] **Step 6: Deploy the theme**: `dput inc/events-meta.php ~/deka-work/stage/inc/events-meta.php`, then the same for `events/single-event.php` and `template-events-manager.php`. On `DPUT_ABORT`: stop, re-`dgrab`, re-apply the Task 10 edits, retry. Then `dpurge`.
- [ ] **Step 7: Look at the pages** (Playwright MCP). For the permalinks of 7254, 7263, 7530 and one of the 6 native events: `browser_navigate` → `browser_take_screenshot` (to `/tmp/playwright-output/`) → `browser_evaluate` with `() => ({ iframes: [...document.querySelectorAll('.event-register__embed iframe')].map(f => f.src), leak: document.body.innerText.includes('jotformEmbedHandler'), h: [...document.querySelectorAll('.event-register__embed iframe')].map(f => f.getBoundingClientRect().height) })`. Expected: one `jotform.com/…` iframe each on 7254/7263/7530, `leak:false`, height > 0. The native event shows its plugin form or "opens soon" exactly as before. The console page (template-events-manager) Form column still lists the JotForm ids.
- [ ] **Step 8: Check wp-admin by hand.** Ask the user to open one event, because this agent cannot log into wp-admin: "DEKA Academy Fields" has no embed textarea, and Event Details shows "Use external signup form" ticked, the embed below it, and no Livestream/Access/Emails/Registrations boxes.
- [ ] **Step 9: Retire the legacy key**: `dwp "eval-file /tmp/migrate-reg-embed-to-plugin.php retire"` (expected 16 `retired`, 2 `skipped (no legacy key)` for 7530/7531). Then `dpurge`, and re-run Step 7's evaluate on 7254 (unchanged). Then `dssh "rm -f /tmp/migrate-reg-embed-to-plugin.php"`.
- [ ] **Step 10: Mirror + record.** Copy the three staged files into `/Users/bif/Desktop/deka-context/deka-theme/deka/` (same relative paths) and commit them in deka-context (`git add` those three paths only). Write a memory note (`deka-external-signup-migration`) with the date, versions, the 18 IDs, and the rollback command.

**Rollback** (any step after 4): `dwp "eval-file /tmp/migrate-reg-embed-to-plugin.php rollback"` restores mode (free), clears the plugin embed and renames the legacy key back. Then `dput` the `.bak-*` theme copies that `dput` made (`dssh "ls -t /www/dekalasers_639/public/wp-content/themes/deka/inc/events-meta.php.bak-*"`), and `dpurge`. A plugin rollback is the `Anchor-Tools.bak-*` dir swap.

---

## Self-review notes

- Spec §1 (checkbox = `external`, unticked shows the select): T5 save, T6 markup, T7 JS. §2 (fields appear only when ticked; embed or URL; multiple iframes; embed wins): T6 section under the checkbox, T4 multi-iframe, T3 embed-wins test (existing behaviour pinned). §3 (decommission UI + runtime; stored settings untouched): T1–T3 and T6–T8, with the stream-wipe fix in T6 and `native_registration_mode` in T5. §4 (DEKA migration + theme + form id): T9–T11. §5 (drop the filter): T1.
- Names used across tasks: `uses_external_signup`, `native_registration_mode` (method + meta), `render_registration_mode_field`, `render_external_signup_section`, Outcome/notice `external_signup`, `AnchorEventsRegistrationMode.effective/matches`, `.anchor-event-external-signup`, `anchor_event_external_signup(_present)`, `.anchor-event-native-only-hidden`.
