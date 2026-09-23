# Virtual Events: Hosted Stream Room Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every plugin-registered event a private `/live/` room that counts down, switches to a provider-agnostic embed at start time, and closes after the session — gated by a per-event WordPress role that outlives the event.

**Architecture:** Three new pure-ish classes (`Embed` normalises provider input, `Stream_State` is a pure state machine over resolved sessions, `Entitlements` owns "who holds access and why") are constructed by `Module` exactly like `Registrations`/`Roster`/`Occurrences`. The room is a `EP_PERMALINK` rewrite endpoint rendered through `templates/live-event.php`; a single REST endpoint re-runs the same two classes server-side so the embed URL never appears in markup before its window opens. Access is a role (`anchor_event_{id}`) granted from seat lifecycle hooks, plus a manual grant channel on the roster.

**Tech Stack:** PHP 8.1+ (composer.json floor) / WordPress, namespaced `\Anchor\Events`, backslash-prefixed global functions, `'anchor-schema'` text domain, WooCommerce (optional, guarded), jQuery IIFE JS (no ES modules, source `.js` enqueued), PHPUnit 9 on the WP test library, Playwright on `@wordpress/env`.

**Spec:** `docs/superpowers/specs/2026-09-23-virtual-events-stream-design.md`

## Global Constraints

- Module is `anchor-events-manager/`, class `\Anchor\Events\Module`; CPT const `'event'`, seat CPT const `'anchor_event_reg'`.
- All event meta keys use the `_anchor_event_` prefix **via `Module::meta_key( $key )`** — never a literal.
- Text domain for every translatable string: `'anchor-schema'`.
- JS is jQuery IIFE `(function($){ ... })(jQuery);`. No ES modules. Enqueue the **source** `.js`/`.css` through `\Anchor_Asset_Loader::url()` + `$this->asset_version()`. Never create or edit a `*.min.*` file.
- Asset URLs: `\Anchor_Asset_Loader::url( 'anchor-events-manager/assets/<file>' )` — never `plugin_dir_url(__FILE__)`.
- `update_option()` always takes `autoload=false` as the third argument.
- Role slug: `anchor_event_{event_id}`. Role display name: `Event: {post_title}`. **No capabilities.**
- Room URL: `trailingslashit( get_permalink( $event_id ) ) . 'live/'`.
- Login-token query arg: `aek`. Token expiry: **last session `end_ts` + 7 days**.
- Modality vocabulary: events/sessions `in_person|virtual|hybrid`; tiers `in_person|virtual` only.
- Defaults that preserve today's behaviour: `stream_default_modality='in_person'`, `in_person_includes_stream=true`, `stream_open_before_minutes=15`, `stream_close_after_minutes=30`, `required_roles=[]`, `required_roles_mode='any'`, `stream_embed=[]`.
- New hooks, exact names: `anchor_events_access_granted`, `anchor_events_access_revoked`, `anchor_events_can_access_stream`, `anchor_events_embed_providers`, `anchor_events_create_account`, `anchor_events_room_denied_message`, `anchor_events_seat_created`.
- Test command for every task (CLAUDE.md):
  ```bash
  export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
  vendor/bin/phpunit --filter <TestClass>
  ```
- **No version bump in this plan.** Anchor-Tools 3.31.0 is released from `main` by the owner per CLAUDE.md's release process; do not touch `Version:` in `anchor-tools.php` and do not tag.

---

## Spec deviations

Each of these is a place the spec assumes something the code does not do. Evidence, then the decision taken in this plan.

**D1 — `Events_Log::info()` does not exist (spec §4.2).**
`class-events-log.php` exposes only `error()` (:89), `archive_and_clear()` (:201), `order()` (:231), `flag_review()` (:264), `clear_review()` (:298). Its header docblock (:8–14) records that the per-event activity roll-up was *deliberately removed* (REG-D30) because an empty `Events_Log::event()` made callers look like they were recording activity. **Decision:** no `info()` is added. The audit trail for a grant is the `_anchor_event_grants[event_id] = {source, at, by}` user-meta row the spec already requires, plus the `anchor_events_access_granted` / `anchor_events_access_revoked` actions. Only *failures* log, through `Events_Log::error( 'access_user_create_failed', … )`.

**D2 — seat creation never fires `anchor_events_seat_status_changed` (spec §4.2 calls it "the single transition point").**
`Registrations::create_seat()` (class-registrations.php:283–350) writes `_anchor_event_reg_status` straight into post meta and returns; the action fires **only** inside `update_status()`, and only when `$from !== $to` (class-registrations.php:445–454). Free registrations, comped roster adds and completed-at-checkout WooCommerce seats are *born* `confirmed`, so a listener on the transition alone grants nothing to the majority of attendees. **Decision:** add `\do_action( 'anchor_events_seat_created', $seat_id, $status );` at the end of `create_seat()` (Task 7) and hook both actions.

**D3 — `Module::can_view_virtual_link()` is `private` and has a public branch delegation would destroy (spec §4.5/§6.3).**
Signature is `private function can_view_virtual_link( $post_id, $meta )` (anchor-events-manager.php:9156), and its first branch (9162–9165) returns **true for everyone** when `registration_enabled` is false and the event is not WC-linked — an informational public virtual event. `can_access_stream()` returns false for a logged-out visitor, so wholesale delegation would remove the "Join here" link from those events. **Decision:** keep the informational-public branch, delegate everything below it to `Entitlements::can_access_stream()`. Signature and visibility unchanged; no caller is touched.

**D4 — adding `prerequisite` to `capacity_decision()` changes a load-bearing vocabulary and makes it user-dependent (spec §4.6).**
`capacity_decision()` returns a flat string `open|closed|full|waitlist` (class-registrations.php:909–970) and its own docblock (:891–903) says the mixed vocabulary is "DELIBERATELY UNCHANGED" and that changing the interface is out of scope for that batch. Three consumers break on an unknown value: `Module::bookability()` returns `$seats` verbatim through its final `return $seats;` (anchor-events-manager.php:12587); `Event_Schema::omits_offer()` omits the Offer for anything not in `[open, waitlist, full, disabled]` (class-event-schema.php:164), so an unhandled value would silently strip price markup from every gated event; `WooCommerce::bookability_message()` falls through to "Registration for this event is closed." (class-woocommerce.php:1405–1412). It is also the first time the decision depends on *who is asking*. **Decision:** implement it, with four guards — (a) the branch runs only when `required_roles` is non-empty, so every existing event's answer is bit-identical; (b) `bookability()` returns `'prerequisite'` explicitly, beside `closed`/`full`; (c) `Event_Schema::availability_for('prerequisite')` returns `https://schema.org/InStock` and `omits_offer()` keeps publishing the Offer — seats exist, eligibility is a policy not inventory, so anonymous/crawler markup is unchanged; (d) `bookability_message()` gets its own arm.

**D5 — `anchor_events_manager_form_fields` is an ACTION at the bottom of the console form, not a filter (task brief + spec §7).**
`\do_action( 'anchor_events_manager_form_fields', $event_id, $this );` (anchor-events-manager.php:8529) fires *after* the gallery section, inside `data-step="3"`, and its docblock says it exists so a **theme** can print its own inputs. It takes `($event_id, $module)` and returns nothing — there is no value to filter. Using it would render the Livestream and Access fields in the wrong step, outside their sections, in the wrong order. **Decision:** achieve parity the way the module already achieves it — a shared render partial called inline from both surfaces, exactly like `render_ticket_types_fields()` (metabox at :2937, console at :8213) and the shared `event_authoring_input()` sanitiser. The action is left untouched.

**D6 — new keys are not sanitised in `persist_event_authoring()` (spec §3 preamble).**
`persist_event_authoring()` (anchor-events-manager.php:5343) only runs sub-savers; it sanitises nothing. The shared allow-list sanitiser both save paths use is `event_authoring_input()` (:5156), and the slash-domain array keys go through `sanitize_event_type_input()` (:5497), which `wp_slash()`es its whole return. **Decision:** scalars (`stream_default_modality`, `in_person_includes_stream`, the two minute values, `required_roles_mode`) go in `event_authoring_input()`; the array-valued `stream_embed`, `sessions` and `required_roles` go in `sanitize_event_type_input()`.

**D7 — `Module::get_sessions()` returns no timestamps, no modality, and `[]` for a single event (spec §3.2).**
Current body (anchor-events-manager.php:11209–11231) returns only `{date,start_time,end_time,label}` and reads nothing for a non-multisession event. Making it synthesise an implicit session would put a one-row "Sessions" table on every single event via `render_sessions_list()` (:9279). **Decision:** `get_sessions()` gains `modality`, `stream_embed`, `start_ts`, `end_ts` **additively** (existing keys and the empty-for-single behaviour untouched, so `render_sessions_list()` and both repeaters keep working); the implicit `[0]` session for a `single` event lives in a new `Module::resolved_sessions( $event_id )`, which is what `Stream_State` and the room consume.

**D8 — `Roster::handle_add()` does not call `create_seat()` (spec §4.3).**
It calls `$this->registrations->claim_seats(...)` (class-roster.php:718) and reads `$result['created']` / `$result['waitlisted']`. **Decision:** `ensure_user()` is called on the returned seat ids after the claim, not inside the add form.

**D9 — no module-version rewrite flush exists (spec §5.1 "existing pattern").**
Events only flushes when the slug setting changes: `handle_settings_update()` (anchor-events-manager.php:10865–10869). The repo's actual signature-flush pattern is `Anchor_Locations::maybe_flush()` (anchor-locations/anchor-locations.php:194–203). **Decision:** copy that shape with option `anchor_events_rw_sig`.

**D10 — this module registers no REST routes at all.**
`grep -rn register_rest_route anchor-events-manager/` returns nothing; the only model in the repo is `Anchor_Compliance_Rest` (anchor-compliance/includes/class-rest.php:15, :32). **Decision:** add `rest_api_init` in `Module::__construct()` and a `anchor-events/v1` namespace modelled on that class.

**D11 — there is no "sitemap filter the noindexed LPs use" (spec §5.2).**
`grep -rn sitemap` over the plugin finds one comment (includes/class-anchor-groups.php:6) and no filter. Core's sitemap lists post permalinks; a rewrite *endpoint* on a permalink is never enumerated. **Decision:** no sitemap filter is added. `nocache_headers()`, `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, nofollow` and a `<meta name="robots">` are the whole of the exclusion, and that is documented in EVENTS.md.

**D12 — `_anchor_event_customer_id` already exists on seats and overlaps `_anchor_event_user_id` (spec §3.5).**
`create_seat()` writes `_anchor_event_customer_id` from the WC order (class-registrations.php:330; WooCommerce.php:2469, 2767). **Decision:** keep both with distinct meanings — `customer_id` stays "the WooCommerce order's customer (0 = guest)", `user_id` is "the account this seat entitles", written by `ensure_user()` on every path including free and manual. `user_has_active_seat()` (class-registrations.php:1174) gains `_anchor_event_user_id` to its OR identity block, checked first as the spec requires.

**D13 — `wc_create_new_customer()` *does* send a new-account email.**
WooCommerce fires `woocommerce_created_customer` → `WC_Emails` "Customer new account". `wp_insert_user()` sends nothing on its own. **Decision:** `ensure_user()` wraps creation in a one-shot `add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' )` that is removed in a `finally`, which is what makes the spec's "no WordPress new-user mail" assertion testable.

**Unresolved:** none. Every item above is decided and planned against reality.

---

## File Structure

**Created**
| File | Responsibility |
|---|---|
| `anchor-events-manager/class-embed.php` | `\Anchor\Events\Embed` — provider table, `normalize()`, `render()`. No hooks, no state. |
| `anchor-events-manager/class-stream-state.php` | `\Anchor\Events\Stream_State` — pure state machine over resolved sessions. No hooks, no WP calls except `Module` reads in the one convenience wrapper. |
| `anchor-events-manager/class-entitlements.php` | `\Anchor\Events\Entitlements` — roles, grants, account resolution, `can_access_stream()`, login tokens. The only class that writes roles. |
| `anchor-events-manager/templates/live-event.php` | Theme-overridable room shell. Delegates the body to `Module::render_room()`. |
| `anchor-events-manager/assets/room.js` | jQuery IIFE — countdown, skew correction, REST refresh, 5-minute live poll. |
| `anchor-events-manager/assets/room.css` | Room layout, 16:9 embed box, schedule list, badges. |
| `tests/test-embed.php`, `tests/test-stream-state.php`, `tests/test-entitlements.php`, `tests/test-room.php`, `tests/test-prerequisites.php`, `tests/test-login-token.php` | New suites. |
| `e2e/live-room.spec.js` | Playwright. |

**Modified** — `anchor-events-manager/anchor-events-manager.php` (meta model, sanitisers, sessions, metabox, console, room routing/render, REST, email tokens, admin column), `class-registrations.php` (seat-created action, `user_id` meta, prerequisite branch), `class-ticket-types.php` (tier `modality`), `class-product-sync.php` (variation modality meta), `class-occurrences.php` (inherited keys), `class-roster.php` (access column, grant/revoke, add-by-email, export scope), `class-event-schema.php` (prerequisite availability, VirtualLocation room URL), `class-woocommerce.php` (prerequisite message, attendee-capture `ensure_user`), `EVENTS.md`, `EMAILS.md`, plus the existing test files named per task.

---

### Task 1: Event-level stream meta keys and scalar sanitisers

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php:2811` (`get_meta_schema()` tail, after `'recurrence'`)
- Modify: `anchor-events-manager/anchor-events-manager.php:2880` (`get_meta_defaults()` tail, after `'recurrence' => []`)
- Modify: `anchor-events-manager/anchor-events-manager.php:5196` (`event_authoring_input()`, after `'labels' => …`)
- Modify: `anchor-events-manager/anchor-events-manager.php:5507` (`sanitize_event_type_input()` return array)
- Test: `tests/test-event-model.php`

**Interfaces:**
- Consumes: `Module::meta_key()`, `Module::get_meta()`, `Module::get_meta_defaults()`.
- Produces: `Module::sanitize_modality( $raw, $fallback = 'in_person' ): string` (values `in_person|virtual|hybrid`); `Module::sanitize_role_slugs( $raw ): string[]`; seven meta keys — `stream_embed` (array), `stream_default_modality` (string), `in_person_includes_stream` (bool), `stream_open_before_minutes` (int), `stream_close_after_minutes` (int), `required_roles` (string[]), `required_roles_mode` (string `any|all`).

- [ ] **Step 1: Write the failing test**

Append to `tests/test-event-model.php`:

```php
	/** New stream keys default to previous behaviour. */
	public function test_stream_meta_defaults() {
		$event_id = $this->make_event();
		$meta     = $this->module()->get_meta( $event_id );

		$this->assertSame( [], $meta['stream_embed'] );
		$this->assertSame( 'in_person', $meta['stream_default_modality'] );
		$this->assertTrue( $meta['in_person_includes_stream'] );
		$this->assertSame( 15, $meta['stream_open_before_minutes'] );
		$this->assertSame( 30, $meta['stream_close_after_minutes'] );
		$this->assertSame( [], $meta['required_roles'] );
		$this->assertSame( 'any', $meta['required_roles_mode'] );
	}

	/** A garbage modality falls back; minutes are clamped to >= 0. */
	public function test_sanitize_modality_and_minutes() {
		$m = $this->module();
		$this->assertSame( 'hybrid', $m->sanitize_modality( 'hybrid' ) );
		$this->assertSame( 'in_person', $m->sanitize_modality( '<script>' ) );
		$this->assertSame( 'virtual', $m->sanitize_modality( '', 'virtual' ) );
		$this->assertSame( [ 'anchor_event_12', 'subscriber' ], $m->sanitize_role_slugs( [ 'anchor_event_12', 'Subscriber', '' ] ) );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Event_Model
```
Expected: FAIL — `Undefined array key "stream_embed"` and `Call to undefined method …::sanitize_modality()`.

- [ ] **Step 3: Write minimal implementation**

In `get_meta_schema()`, immediately after the `'recurrence' => [ … ]` entry:

```php
            // Hosted livestream (virtual-events spec §3.1). Metabox/console
            // owned, same reason as `sessions` above: show_in_rest=false keeps
            // a block-editor autosave from racing the metabox save.
            'stream_embed' => [ 'type' => 'array', 'show_in_rest' => false ],
            'stream_default_modality' => [ 'type' => 'string', 'show_in_rest' => false ],
            'in_person_includes_stream' => [ 'type' => 'boolean', 'show_in_rest' => false ],
            'stream_open_before_minutes' => [ 'type' => 'integer', 'show_in_rest' => false ],
            'stream_close_after_minutes' => [ 'type' => 'integer', 'show_in_rest' => false ],
            // Prerequisites (§4.6) — role slugs the registrant must already hold.
            'required_roles' => [ 'type' => 'array', 'show_in_rest' => false ],
            'required_roles_mode' => [ 'type' => 'string', 'show_in_rest' => false ],
```

In `get_meta_defaults()`, after `'recurrence' => [],`:

```php
            'stream_embed' => [],
            'stream_default_modality' => 'in_person',
            'in_person_includes_stream' => true,
            'stream_open_before_minutes' => 15,
            'stream_close_after_minutes' => 30,
            'required_roles' => [],
            'required_roles_mode' => 'any',
```

In `event_authoring_input()`, after `'labels' => $this->labels_input( $src ),`:

```php
            // Livestream scalars (spec §3.1). Never unslashed above, so they are
            // already in the slashed domain and must NOT be wp_slash()ed again.
            'stream_default_modality' => $this->sanitize_modality( $src['anchor_event_stream_default_modality'] ?? '' ),
            'in_person_includes_stream' => ! empty( $src['anchor_event_in_person_includes_stream'] ),
            'stream_open_before_minutes' => max( 0, (int) ( $src['anchor_event_stream_open_before_minutes'] ?? 15 ) ),
            'stream_close_after_minutes' => max( 0, (int) ( $src['anchor_event_stream_close_after_minutes'] ?? 30 ) ),
            'required_roles_mode' => ( ( $src['anchor_event_required_roles_mode'] ?? '' ) === 'all' ) ? 'all' : 'any',
```

In `sanitize_event_type_input()`'s returned array, after `'sessions' => …`:

```php
            'required_roles' => $this->sanitize_role_slugs( \wp_unslash( $src['anchor_event_required_roles'] ?? [] ) ),
```

And two new helpers, placed directly after `sanitize_registration_mode()` (~:5546):

```php
    /**
     * Validate a posted modality against the event/session vocabulary.
     *
     * A tier may only be in_person|virtual (Ticket_Types::normalize()); this
     * one also accepts `hybrid`, which is an EVENT/SESSION-level statement
     * ("both ways to attend exist"), never a price.
     *
     * @param mixed  $raw
     * @param string $fallback Used for an empty or unrecognised value.
     * @return string One of in_person|virtual|hybrid.
     */
    public function sanitize_modality( $raw, $fallback = 'in_person' ) {
        $valid = [ 'in_person', 'virtual', 'hybrid' ];
        $value = \sanitize_key( (string) $raw );
        if ( \in_array( $value, $valid, true ) ) {
            return $value;
        }
        return \in_array( $fallback, $valid, true ) ? $fallback : 'in_person';
    }

    /**
     * Sanitize a posted list of role slugs (the prerequisites picker).
     * sanitize_key() lowercases, which is what WP_Roles keys are.
     *
     * @param mixed $raw
     * @return string[] De-duplicated, re-indexed, empties dropped.
     */
    public function sanitize_role_slugs( $raw ) {
        $out = [];
        foreach ( (array) $raw as $slug ) {
            $slug = \sanitize_key( (string) $slug );
            if ( $slug !== '' && ! \in_array( $slug, $out, true ) ) {
                $out[] = $slug;
            }
        }
        return $out;
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Event_Model
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php tests/test-event-model.php
git commit -m "feat(events): add livestream + prerequisite event meta keys and sanitisers"
```

---

### Task 2: Session-row modality and resolved sessions

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php:5558` (`sanitize_sessions_rows()`)
- Modify: `anchor-events-manager/anchor-events-manager.php:11209` (`get_sessions()`) and add `resolved_sessions()` after it
- Test: `tests/test-event-model.php`

**Interfaces:**
- Consumes: `Module::sanitize_modality()`, `Module::to_timestamp()`, `Module::event_timezone()` (all existing/Task 1).
- Produces: `Module::get_sessions( $event_id ): array<int,array{date,start_time,end_time,label,modality,stream_embed,start_ts,end_ts}>` (still `[]` for non-multisession — see D7); `Module::resolved_sessions( $event_id ): array` — same row shape, and for a non-multisession event exactly one implicit row spanning the event's own `start_ts`/`end_ts` with `label = ''`.

- [ ] **Step 1: Write the failing test**

Append to `tests/test-event-model.php`:

```php
	/** Session rows resolve modality from the event default and get timestamps. */
	public function test_sessions_resolve_modality_and_timestamps() {
		$event_id = $this->make_event( [
			'type'                    => 'multisession',
			'timezone'                => 'UTC',
			'stream_default_modality' => 'hybrid',
			'sessions'                => [
				[ 'date' => '2027-03-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 1' ],
				[ 'date' => '2027-03-02', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 2', 'modality' => 'in_person' ],
			],
		] );

		$rows = $this->module()->get_sessions( $event_id );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'hybrid', $rows[0]['modality'], 'An empty row modality inherits the event default.' );
		$this->assertSame( 'in_person', $rows[1]['modality'] );
		$this->assertSame( strtotime( '2027-03-01 09:00:00 UTC' ), $rows[0]['start_ts'] );
		$this->assertSame( strtotime( '2027-03-01 11:00:00 UTC' ), $rows[0]['end_ts'] );
	}

	/** A single event resolves to exactly one implicit session spanning its own bounds. */
	public function test_resolved_sessions_single_event_implicit_row() {
		$event_id = $this->make_event( [
			'type'       => 'single',
			'timezone'   => 'UTC',
			'start_date' => '2027-04-10',
			'start_time' => '14:00',
			'end_date'   => '2027-04-10',
			'end_time'   => '16:00',
			'start_ts'   => strtotime( '2027-04-10 14:00:00 UTC' ),
			'end_ts'     => strtotime( '2027-04-10 16:00:00 UTC' ),
			'stream_default_modality' => 'virtual',
		] );

		$this->assertSame( [], $this->module()->get_sessions( $event_id ), 'get_sessions() stays empty for a single event.' );

		$rows = $this->module()->resolved_sessions( $event_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'virtual', $rows[0]['modality'] );
		$this->assertSame( strtotime( '2027-04-10 14:00:00 UTC' ), $rows[0]['start_ts'] );
		$this->assertSame( strtotime( '2027-04-10 16:00:00 UTC' ), $rows[0]['end_ts'] );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Event_Model
```
Expected: FAIL — `Undefined array key "modality"` and `Call to undefined method …::resolved_sessions()`.

- [ ] **Step 3: Write minimal implementation**

Replace the row-building loop inside `sanitize_sessions_rows()` (the `$sessions[] = [ … ];` block) with:

```php
            $row_out = [
                'date' => $date,
                'start_time' => \sanitize_text_field( $row['start_time'] ?? '' ),
                'end_time' => \sanitize_text_field( $row['end_time'] ?? '' ),
                'label' => \sanitize_text_field( $row['label'] ?? '' ),
            ];
            // Optional per-session modality. '' is MEANINGFUL: it means "use the
            // event's stream_default_modality", resolved on read in
            // get_sessions() — so it is stored as '' rather than defaulted here.
            $modality = \sanitize_key( (string) ( $row['modality'] ?? '' ) );
            $row_out['modality'] = \in_array( $modality, [ 'in_person', 'virtual', 'hybrid' ], true ) ? $modality : '';
            // Per-session embed override. Already a normalized {provider,src,raw}
            // array by the time it reaches here (Task 4 normalizes the raw input);
            // anything else is dropped rather than stored half-formed.
            $embed = $row['stream_embed'] ?? [];
            $row_out['stream_embed'] = ( \is_array( $embed ) && ! empty( $embed['src'] ) ) ? [
                'provider' => \sanitize_key( (string) ( $embed['provider'] ?? '' ) ),
                'kind'     => ( ( $embed['kind'] ?? 'iframe' ) === 'link' ) ? 'link' : 'iframe',
                'src'      => \esc_url_raw( (string) $embed['src'] ),
                'raw'      => \sanitize_textarea_field( (string) ( $embed['raw'] ?? '' ) ),
            ] : [];
            $sessions[] = $row_out;
```

Replace the body of `get_sessions()` from `$sessions = [];` onward:

```php
        $meta       = $this->get_meta( $event_id );
        $default_mo = $this->sanitize_modality( $meta['stream_default_modality'] ?? '' );
        $event_embed = \is_array( $meta['stream_embed'] ?? null ) ? $meta['stream_embed'] : [];
        $tz         = $this->event_timezone( $meta );

        $sessions = [];
        foreach ( $stored as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $date = \sanitize_text_field( $row['date'] ?? '' );
            if ( $date === '' ) {
                continue;
            }
            $start_time = \sanitize_text_field( $row['start_time'] ?? '' );
            $end_time   = \sanitize_text_field( $row['end_time'] ?? '' );
            $row_embed  = ( \is_array( $row['stream_embed'] ?? null ) && ! empty( $row['stream_embed']['src'] ) )
                ? $row['stream_embed']
                : $event_embed;
            $sessions[] = [
                'date' => $date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'label' => \sanitize_text_field( $row['label'] ?? '' ),
                // Resolved, never empty (spec §3.2): the room, the theme and the
                // state machine read ONE shape.
                'modality' => $this->sanitize_modality( $row['modality'] ?? '', $default_mo ),
                'stream_embed' => \is_array( $row_embed ) ? $row_embed : [],
                'start_ts' => $this->to_timestamp( $date, $start_time !== '' ? $start_time : '00:00', $tz ),
                'end_ts' => $this->to_timestamp( $date, $end_time !== '' ? $end_time : '23:59', $tz ),
            ];
        }
        return $sessions;
```

Add directly after `get_sessions()`:

```php
    /**
     * The sessions the ROOM reasons about — always at least one row.
     *
     * A multisession event returns get_sessions(). Everything else returns one
     * implicit session [0] spanning the event's own start_ts..end_ts with the
     * event's default modality and embed, which is what lets Stream_State treat
     * a single event, a group child and a three-day course identically
     * (spec §3.2). get_sessions() itself deliberately stays empty for a
     * non-multisession event — render_sessions_list() would otherwise print a
     * one-row "Sessions" table on every single event.
     *
     * @param int $event_id
     * @return array<int,array{date:string,start_time:string,end_time:string,label:string,modality:string,stream_embed:array,start_ts:int,end_ts:int}>
     */
    public function resolved_sessions( $event_id ) {
        $event_id = (int) $event_id;
        if ( $this->event_type( $event_id ) === 'multisession' ) {
            $rows = $this->get_sessions( $event_id );
            if ( ! empty( $rows ) ) {
                return $rows;
            }
        }

        $meta = $this->get_meta( $event_id );
        return [ [
            'date' => (string) $meta['start_date'],
            'start_time' => (string) $meta['start_time'],
            'end_time' => (string) $meta['end_time'],
            'label' => '',
            'modality' => $this->sanitize_modality( $meta['stream_default_modality'] ?? '' ),
            'stream_embed' => \is_array( $meta['stream_embed'] ?? null ) ? $meta['stream_embed'] : [],
            'start_ts' => (int) $meta['start_ts'],
            'end_ts' => (int) $meta['end_ts'],
        ] ];
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Event_Model
```
Then the two suites that read sessions, to prove the additive change broke nothing:
```bash
vendor/bin/phpunit --filter Test_Event_Grouping_Frontend
vendor/bin/phpunit --filter Test_Event_Save
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php tests/test-event-model.php
git commit -m "feat(events): resolve session modality, embed and timestamps; add resolved_sessions()"
```

---

### Task 3: `Embed` normaliser

**Files:**
- Create: `anchor-events-manager/class-embed.php`
- Modify: `anchor-events-manager/anchor-events-manager.php:401` (`require_once` block in `__construct()`)
- Test: `tests/test-embed.php`

**Interfaces:**
- Produces: `\Anchor\Events\Embed::normalize( string $input ): array{provider:string,kind:string,src:string,raw:string}|\WP_Error` (error code `anchor_events_embed_unknown_host`); `Embed::providers(): array<string,array{hosts:string[],kind:string,transform:callable}>` filtered through `anchor_events_embed_providers`; `Embed::render( array $embed, string $title ): string`.

- [ ] **Step 1: Write the failing test**

Create `tests/test-embed.php`:

```php
<?php
/**
 * Embed normaliser tests (virtual-events spec §5.4). No WooCommerce required.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Embed;

/**
 * @group embed
 */
class Test_Embed extends Anchor_Events_TestCase {

	/** Vimeo: plain video, player URL with an unlisted hash, and an event URL. */
	public function test_vimeo_variants() {
		$this->assertSame(
			'https://player.vimeo.com/video/123456',
			Embed::normalize( 'https://vimeo.com/123456' )['src']
		);
		$this->assertSame(
			'https://player.vimeo.com/video/123456?h=abc123',
			Embed::normalize( 'https://player.vimeo.com/video/123456?h=abc123' )['src'],
			'The unlisted-video hash must survive normalisation.'
		);
		$this->assertSame(
			'https://player.vimeo.com/event/456/embed',
			Embed::normalize( 'https://vimeo.com/event/456' )['src']
		);
	}

	/** YouTube: all four input shapes collapse to the nocookie embed. */
	public function test_youtube_variants() {
		foreach ( [
			'https://youtu.be/dQw4w9WgXcQ',
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			'https://www.youtube.com/live/dQw4w9WgXcQ',
			'https://www.youtube.com/embed/dQw4w9WgXcQ',
		] as $input ) {
			$this->assertSame(
				'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
				Embed::normalize( $input )['src'],
				"Failed for {$input}"
			);
		}
	}

	/** Pasted iframe HTML has its src extracted. */
	public function test_pasted_iframe_html() {
		$html = '<iframe src="https://player.vimeo.com/video/987?h=zz" width="640" allowfullscreen></iframe>';
		$out  = Embed::normalize( $html );
		$this->assertSame( 'vimeo', $out['provider'] );
		$this->assertSame( 'https://player.vimeo.com/video/987?h=zz', $out['src'] );
		$this->assertSame( $html, $out['raw'], 'The author input is kept verbatim for the metabox.' );
	}

	/** Zoom forbids framing, so it normalises to a link. */
	public function test_zoom_is_a_link() {
		$out = Embed::normalize( 'https://us02web.zoom.us/j/8412345678?pwd=xyz' );
		$this->assertSame( 'zoom', $out['provider'] );
		$this->assertSame( 'link', $out['kind'] );
	}

	/** An unknown host is refused, not guessed at. */
	public function test_unknown_host_is_wp_error() {
		$out = Embed::normalize( 'https://evil.example.com/stream' );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'anchor_events_embed_unknown_host', $out->get_error_code() );
	}

	/** A site may add its own host through the filter. */
	public function test_provider_filter_adds_a_host() {
		$add = function ( $providers ) {
			$providers['generic'] = [
				'hosts'     => [ 'stream.example.org' ],
				'kind'      => 'iframe',
				'transform' => static function ( $url ) { return $url; },
			];
			return $providers;
		};
		add_filter( 'anchor_events_embed_providers', $add );
		$out = Embed::normalize( 'https://stream.example.org/live/1' );
		remove_filter( 'anchor_events_embed_providers', $add );

		$this->assertSame( 'generic', $out['provider'] );
		$this->assertSame( 'https://stream.example.org/live/1', $out['src'] );
	}

	/** Non-https and javascript: payloads never become a src. */
	public function test_xss_payloads_rejected() {
		foreach ( [
			'javascript:alert(1)',
			'<iframe src="javascript:alert(1)"></iframe>',
			'http://vimeo.com/1',
			'data:text/html;base64,PHNjcmlwdD4=',
		] as $payload ) {
			$this->assertInstanceOf( WP_Error::class, Embed::normalize( $payload ), "Accepted: {$payload}" );
		}
	}

	/** Rendering an iframe carries the hardening attributes. */
	public function test_render_iframe_attributes() {
		$html = Embed::render( Embed::normalize( 'https://vimeo.com/5' ), 'My "Event"' );
		$this->assertStringContainsString( 'allowfullscreen', $html );
		$this->assertStringContainsString( 'referrerpolicy="strict-origin-when-cross-origin"', $html );
		$this->assertStringContainsString( 'title="My &quot;Event&quot;"', $html );
		$this->assertStringContainsString( 'loading="eager"', $html );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Embed
```
Expected: FAIL — `Class "Anchor\Events\Embed" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `anchor-events-manager/class-embed.php`:

```php
<?php
/**
 * Provider-agnostic stream embed normaliser (virtual-events spec §5.4).
 *
 * The ONE place a pasted iframe or a bare URL becomes the `{provider, kind,
 * src, raw}` shape stored in `_anchor_event_stream_embed`. Nothing else in the
 * module is allowed to build that array, and nothing ever stores raw embed
 * HTML — the room re-renders the iframe itself from `src`, so a provider
 * changing its markup is a change here and nowhere else.
 *
 * Pure and static: no hooks, no post meta, no WordPress state beyond the
 * `anchor_events_embed_providers` filter and the escaping helpers.
 *
 * @package AnchorTools\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Embed {

    /** Error code for an input whose host is on no provider's list. */
    const ERR_UNKNOWN_HOST = 'anchor_events_embed_unknown_host';

    /**
     * The provider table: slug => { hosts[], kind, transform }.
     *
     * `kind` is `iframe` (frameable) or `link` (a button — Zoom sends
     * X-Frame-Options and refuses to be framed at all). `transform` receives
     * the parsed URL parts and returns the final src, or '' to refuse.
     *
     * @return array<string,array{hosts:string[],kind:string,transform:callable}>
     */
    public static function providers() {
        $providers = [
            'vimeo' => [
                'hosts'     => [ 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com' ],
                'kind'      => 'iframe',
                'transform' => static function ( $path, $query ) {
                    // /event/456 and /event/456/embed both become the event embed.
                    if ( \preg_match( '#^/event/([A-Za-z0-9]+)#', $path, $m ) ) {
                        return 'https://player.vimeo.com/event/' . $m[1] . '/embed' . self::query_suffix( $query );
                    }
                    // /video/123 (player URL) or /123 (share URL).
                    if ( \preg_match( '#^/(?:video/)?(\d+)#', $path, $m ) ) {
                        return 'https://player.vimeo.com/video/' . $m[1] . self::query_suffix( $query );
                    }
                    return '';
                },
            ],
            'youtube' => [
                'hosts'     => [ 'youtube.com', 'www.youtube.com', 'youtu.be', 'm.youtube.com', 'www.youtube-nocookie.com' ],
                'kind'      => 'iframe',
                'transform' => static function ( $path, $query ) {
                    $id = '';
                    if ( \preg_match( '#^/(?:embed|live|v|shorts)/([A-Za-z0-9_-]{6,})#', $path, $m ) ) {
                        $id = $m[1];
                    } elseif ( \preg_match( '#^/([A-Za-z0-9_-]{6,})$#', $path, $m ) ) {
                        $id = $m[1]; // youtu.be/<id>
                    } elseif ( ! empty( $query['v'] ) ) {
                        $id = \preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $query['v'] );
                    }
                    return $id === '' ? '' : 'https://www.youtube-nocookie.com/embed/' . $id;
                },
            ],
            'zoom' => [
                'hosts'     => [ 'zoom.us' ],
                'kind'      => 'link',
                'transform' => static function ( $path, $query, $url ) {
                    return $url;
                },
            ],
        ];

        /**
         * The stream providers an event may embed.
         *
         * Add `'generic' => [ 'hosts' => [ 'stream.example.org' ], 'kind' =>
         * 'iframe', 'transform' => fn( $path, $query, $url ) => $url ]` to allow
         * a host this table does not know. Hosts are matched exactly OR as a
         * suffix (`us02web.zoom.us` matches `zoom.us`).
         *
         * @param array $providers slug => { hosts[], kind, transform }.
         */
        return (array) \apply_filters( 'anchor_events_embed_providers', $providers );
    }

    /**
     * Normalise pasted iframe HTML or a bare URL into the stored embed shape.
     *
     * @param string $input
     * @return array{provider:string,kind:string,src:string,raw:string}|\WP_Error
     */
    public static function normalize( $input ) {
        $raw = \trim( (string) $input );
        if ( $raw === '' ) {
            return new \WP_Error( self::ERR_UNKNOWN_HOST, \__( 'Paste a stream URL or the provider\'s iframe embed code.', 'anchor-schema' ) );
        }

        $url = $raw;
        if ( \stripos( $raw, '<iframe' ) !== false && \preg_match( '#<iframe[^>]+src=["\']([^"\']+)["\']#i', $raw, $m ) ) {
            $url = \html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
        }

        $parts = \wp_parse_url( $url );
        // https only. A scheme-relative, http, javascript: or data: input is
        // refused outright rather than coerced — the value ends up in an
        // iframe src, so "probably fine" is not a standard we can apply.
        if ( empty( $parts['scheme'] ) || \strtolower( $parts['scheme'] ) !== 'https' || empty( $parts['host'] ) ) {
            return new \WP_Error(
                self::ERR_UNKNOWN_HOST,
                \__( 'A stream link must be a full https:// URL from a supported provider.', 'anchor-schema' )
            );
        }

        $host  = \strtolower( $parts['host'] );
        $path  = '/' . \ltrim( (string) ( $parts['path'] ?? '' ), '/' );
        $query = [];
        if ( ! empty( $parts['query'] ) ) {
            \parse_str( (string) $parts['query'], $query );
        }

        foreach ( self::providers() as $slug => $provider ) {
            foreach ( (array) ( $provider['hosts'] ?? [] ) as $candidate ) {
                $candidate = \strtolower( (string) $candidate );
                if ( $host !== $candidate && \substr( $host, - ( \strlen( $candidate ) + 1 ) ) !== '.' . $candidate ) {
                    continue;
                }
                $src = \call_user_func( $provider['transform'], $path, $query, $url );
                $src = \esc_url_raw( (string) $src, [ 'https' ] );
                if ( $src === '' ) {
                    return new \WP_Error(
                        self::ERR_UNKNOWN_HOST,
                        /* translators: %s: provider slug, e.g. vimeo. */
                        \sprintf( \__( 'That looks like a %s link, but no video or event id could be read from it.', 'anchor-schema' ), $slug )
                    );
                }
                return [
                    'provider' => (string) $slug,
                    'kind'     => ( ( $provider['kind'] ?? 'iframe' ) === 'link' ) ? 'link' : 'iframe',
                    'src'      => $src,
                    'raw'      => $raw,
                ];
            }
        }

        return new \WP_Error(
            self::ERR_UNKNOWN_HOST,
            /* translators: %s: the host that was rejected. */
            \sprintf( \__( '%s is not a supported stream provider. Supported: Vimeo, YouTube, Zoom — or add your own host with the anchor_events_embed_providers filter.', 'anchor-schema' ), $host )
        );
    }

    /**
     * The player markup for a normalised embed. `link` providers render a
     * button; everything else renders a 16:9 iframe.
     *
     * @param array  $embed {provider,kind,src,raw}
     * @param string $title Event title, used as the iframe's accessible name.
     * @return string Escaped HTML, '' when there is nothing to render.
     */
    public static function render( array $embed, $title = '' ) {
        $src = (string) ( $embed['src'] ?? '' );
        if ( $src === '' ) {
            return '';
        }
        if ( ( $embed['kind'] ?? 'iframe' ) === 'link' ) {
            return '<p class="anchor-room-join"><a class="anchor-event-button anchor-room-join-link" href="'
                . \esc_url( $src ) . '" target="_blank" rel="noopener">'
                /* translators: %s: provider name, e.g. Zoom. */
                . \esc_html( \sprintf( \__( 'Join on %s', 'anchor-schema' ), \ucfirst( (string) ( $embed['provider'] ?? 'the provider' ) ) ) )
                . '</a></p>';
        }
        return '<div class="anchor-room-player"><iframe src="' . \esc_url( $src ) . '"'
            . ' allow="autoplay; fullscreen; picture-in-picture; encrypted-media"'
            . ' allowfullscreen referrerpolicy="strict-origin-when-cross-origin" loading="eager"'
            . ' title="' . \esc_attr( $title ) . '"></iframe></div>';
    }

    /** Re-attach a query string, preserving only the keys a provider needs. */
    private static function query_suffix( array $query ) {
        $keep = \array_intersect_key( $query, \array_flip( [ 'h', 'badge', 'autopause', 'player_id', 'app_id' ] ) );
        return empty( $keep ) ? '' : '?' . \http_build_query( $keep );
    }
}
```

In `Module::__construct()`, after `require_once $dir . 'class-event-schema.php';`:

```php
        // Stream embed normaliser (virtual-events spec §5.4) — static, no
        // instance: it holds no state and hooks nothing.
        require_once $dir . 'class-embed.php';
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Embed
```
Expected: PASS (9 assertions groups).

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-embed.php anchor-events-manager/anchor-events-manager.php tests/test-embed.php
git commit -m "feat(events): add provider-agnostic stream Embed normaliser"
```

---

### Task 4: Persist `stream_embed`, force `virtual=1`, surface the error

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php:5497` (`sanitize_event_type_input()`)
- Modify: `anchor-events-manager/anchor-events-manager.php:6301` (`group_notice_map()`)
- Test: `tests/test-event-save.php`

**Interfaces:**
- Consumes: `Embed::normalize()`, `Module::queue_group_notice( $code, $post_id, $detail )`, `Module::sanitize_sessions_rows()`.
- Produces: `Module::stream_embed_input( array $src, $post_id, array &$sessions ): array` — returns the normalised event-level embed, mutates the already-sanitised `$sessions` rows in place with their own normalised overrides, and queues `stream_embed_invalid` on refusal. A refused value **keeps the previous stored one**.

- [ ] **Step 1: Write the failing test**

Append to `tests/test-event-save.php`:

```php
	/** A pasted Vimeo URL is normalised on save and forces virtual=1. */
	public function test_stream_embed_saved_and_forces_virtual() {
		$event_id = $this->make_event();
		$_POST    = [
			'anchor_event_start_date'   => '2027-06-01',
			'anchor_event_stream_embed' => 'https://vimeo.com/424242',
		];
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->module()->save_meta( $event_id );
		$meta = $this->module()->get_meta( $event_id );

		$this->assertSame( 'vimeo', $meta['stream_embed']['provider'] );
		$this->assertSame( 'https://player.vimeo.com/video/424242', $meta['stream_embed']['src'] );
		$this->assertTrue( $meta['virtual'], 'A saved stream forces the legacy virtual flag on.' );
		$_POST = [];
	}

	/** A refused host keeps the previous embed rather than blanking it. */
	public function test_bad_stream_embed_keeps_previous_value() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_stream_embed', [
			'provider' => 'vimeo', 'kind' => 'iframe',
			'src' => 'https://player.vimeo.com/video/1', 'raw' => 'https://vimeo.com/1',
		] );

		$_POST = [
			'anchor_event_start_date'   => '2027-06-01',
			'anchor_event_stream_embed' => 'https://evil.example.com/x',
		];
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->module()->save_meta( $event_id );
		$meta = $this->module()->get_meta( $event_id );

		$this->assertSame( 'https://player.vimeo.com/video/1', $meta['stream_embed']['src'] );
		$_POST = [];
	}

	/** Clearing the field clears the embed (and leaves `virtual` to the checkbox). */
	public function test_empty_stream_embed_clears_it() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_stream_embed', [
			'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/1', 'raw' => '',
		] );

		$_POST = [ 'anchor_event_start_date' => '2027-06-01', 'anchor_event_stream_embed' => '' ];
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->module()->save_meta( $event_id );
		$this->assertSame( [], $this->module()->get_meta( $event_id )['stream_embed'] );
		$_POST = [];
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Event_Save
```
Expected: FAIL — `stream_embed` stays `[]`.

- [ ] **Step 3: Write minimal implementation**

Replace the body of `sanitize_event_type_input()`'s sessions line and add the embed, so the returned array reads:

```php
    private function sanitize_event_type_input( array $src, $registration_mode_fallback, $post_id = 0 ) {
        $sessions_raw = isset( $src['anchor_event_sessions'] ) && is_array( $src['anchor_event_sessions'] )
            ? \wp_unslash( $src['anchor_event_sessions'] )
            : [];
        $sessions = $this->sanitize_sessions_rows( $sessions_raw );

        // Normalises the event-level embed AND each session override in place,
        // queuing a notice (and keeping the stored value) for anything refused.
        $stream_embed = $this->stream_embed_input( $src, (int) $post_id, $sessions );

        return \wp_slash( [
            'type' => $this->sanitize_event_type( \wp_unslash( $src['anchor_event_type'] ?? '' ) ),
            'registration_mode' => $this->sanitize_registration_mode( \wp_unslash( $src['anchor_event_registration_mode'] ?? '' ), $registration_mode_fallback ),
            'sessions' => $sessions,
            'stream_embed' => $stream_embed,
            'required_roles' => $this->sanitize_role_slugs( \wp_unslash( $src['anchor_event_required_roles'] ?? [] ) ),
            'external_url' => esc_url_raw( \wp_unslash( $src['anchor_event_external_url'] ?? '' ) ),
            'external_embed' => $this->sanitize_external_embed( \wp_unslash( $src['anchor_event_external_embed'] ?? '' ), $this->meta_key( 'external_embed' ), self::CPT ),
            'external_display_price' => sanitize_text_field( \wp_unslash( $src['anchor_event_external_display_price'] ?? '' ) ),
        ] );
    }

    /**
     * Normalise the posted stream embeds (spec §3.1/§3.2/§5.4).
     *
     * Event level plus one optional override per session row. Every refusal
     * queues `stream_embed_invalid` and KEEPS whatever is already stored —
     * blanking an author's working stream because they pasted the wrong thing
     * into the box is the one outcome that must not happen. An EMPTY field is
     * not a refusal: it clears the embed, which is how "this session uses the
     * event's stream" is expressed.
     *
     * @param array $src      Raw $_POST-shaped input (still slashed).
     * @param int   $post_id  0 when the event does not exist yet (console "new").
     * @param array $sessions Already-sanitised session rows, mutated in place.
     * @return array The event-level {provider,kind,src,raw}, or [].
     */
    private function stream_embed_input( array $src, $post_id, array &$sessions ) {
        $stored = ( $post_id > 0 ) ? \get_post_meta( $post_id, $this->meta_key( 'stream_embed' ), true ) : [];
        $stored = \is_array( $stored ) ? $stored : [];

        $event_embed = $this->normalize_one_embed(
            \wp_unslash( $src['anchor_event_stream_embed'] ?? '' ),
            $stored,
            $post_id,
            ''
        );

        $raw_rows = isset( $src['anchor_event_sessions'] ) && is_array( $src['anchor_event_sessions'] )
            ? \wp_unslash( $src['anchor_event_sessions'] )
            : [];
        $stored_rows = ( $post_id > 0 ) ? \get_post_meta( $post_id, $this->meta_key( 'sessions' ), true ) : [];
        $stored_rows = \is_array( $stored_rows ) ? \array_values( $stored_rows ) : [];

        $i = 0;
        foreach ( \array_values( $raw_rows ) as $row ) {
            if ( ! \is_array( $row ) || \sanitize_text_field( $row['date'] ?? '' ) === '' ) {
                continue; // Dropped by sanitize_sessions_rows() too — indexes stay aligned.
            }
            if ( ! isset( $sessions[ $i ] ) ) {
                break;
            }
            $prev = ( \is_array( $stored_rows[ $i ]['stream_embed'] ?? null ) ) ? $stored_rows[ $i ]['stream_embed'] : [];
            $sessions[ $i ]['stream_embed'] = $this->normalize_one_embed(
                (string) ( $row['stream_embed'] ?? '' ),
                $prev,
                $post_id,
                (string) ( $sessions[ $i ]['label'] !== '' ? $sessions[ $i ]['label'] : $sessions[ $i ]['date'] )
            );
            $i++;
        }

        return $event_embed;
    }

    /**
     * One field's worth of the rule above.
     *
     * @param string $raw      Author input.
     * @param array  $previous Currently stored value for this field.
     * @param int    $post_id  For the queued notice.
     * @param string $where    '' for the event field, else the session's name.
     * @return array
     */
    private function normalize_one_embed( $raw, array $previous, $post_id, $where ) {
        $raw = \trim( (string) $raw );
        if ( $raw === '' ) {
            return [];
        }
        $normalized = Embed::normalize( $raw );
        if ( \is_wp_error( $normalized ) ) {
            $detail = $where === ''
                ? $normalized->get_error_message()
                : $where . ': ' . $normalized->get_error_message();
            $this->queue_group_notice( 'stream_embed_invalid', (int) $post_id, $detail );
            return $previous;
        }
        return $normalized;
    }
```

Update the two `sanitize_event_type_input()` call sites to pass the post id (`save_meta()` at :5237 → `$this->sanitize_event_type_input( $_POST, $current_registration_mode, $post_id )` via `event_authoring_input( $_POST, $current_registration_mode, null, $post_id )`; the console's `save_event_manager_fields()` likewise). Add the matching `$post_id = 0` parameter to `event_authoring_input()` and forward it.

In `event_authoring_input()`, after building `$input` and before the `array_merge`, add the legacy bridge:

```php
        $merged = array_merge( $input, $this->sanitize_event_type_input( $src, $current_registration_mode, $post_id ) );

        // Legacy bridge (spec §3.1): a saved stream IS a virtual event, so
        // Event_Schema::location_fields(), the "Online" email venue line, the
        // archive badge and the moved_online status all keep working through
        // the one flag they already read, with no second branch anywhere.
        if ( ! empty( $merged['stream_embed']['src'] ) ) {
            $merged['virtual'] = true;
        }
        return $merged;
```

In `group_notice_map()`, add:

```php
            // Not a guard: the rest of the save went through. The refused field
            // kept the value it already had — see normalize_one_embed().
            'stream_embed_invalid' => [
                'level' => 'warning',
                'message' => \__( 'That stream link was not recognised, so the previous stream was kept. Paste a Vimeo, YouTube or Zoom link (or the provider\'s iframe embed code).', 'anchor-schema' ),
            ],
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Event_Save
vendor/bin/phpunit --filter Test_Event_Manager_Save
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php tests/test-event-save.php
git commit -m "feat(events): normalise and persist stream embeds; force virtual on a saved stream"
```

---

### Task 5: `Stream_State`

**Files:**
- Create: `anchor-events-manager/class-stream-state.php`
- Modify: `anchor-events-manager/anchor-events-manager.php:401` (require block)
- Test: `tests/test-stream-state.php`

**Interfaces:**
- Consumes: `Module::resolved_sessions()`, `Module::get_meta()`, `Module::registration_mode()`.
- Produces: `Stream_State::decide( array $sessions, int $open_before, int $close_after, int $now, bool $capable ): array{state:string,session_index:int,target_ts:int,embed:array}` (pure); `Stream_State::for_event( int $event_id, int $now = 0 ): array`; constants `UNAVAILABLE`, `PENDING`, `COUNTDOWN`, `LIVE`, `BETWEEN`, `ENDED`.

- [ ] **Step 1: Write the failing test**

Create `tests/test-stream-state.php`:

```php
<?php
/**
 * Stream_State table tests (virtual-events spec §5.3). Pure function — no
 * WooCommerce, no posts for the decide() cases.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Stream_State;

/**
 * @group stream-state
 */
class Test_Stream_State extends Anchor_Events_TestCase {

	/** One virtual session, an embed, and the standard 15/30 windows. */
	private function sessions( array $overrides = [] ) {
		return [ array_merge( [
			'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 1',
			'modality' => 'virtual',
			'stream_embed' => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/1', 'raw' => '' ],
			'start_ts' => 1000000, 'end_ts' => 1007200,
		], $overrides ) ];
	}

	public function test_unavailable_when_not_stream_capable() {
		$out = Stream_State::decide( $this->sessions(), 900, 1800, 1000000, false );
		$this->assertSame( Stream_State::UNAVAILABLE, $out['state'] );
		$this->assertSame( [], $out['embed'] );
	}

	public function test_unavailable_when_every_session_is_in_person() {
		$out = Stream_State::decide( $this->sessions( [ 'modality' => 'in_person' ] ), 900, 1800, 1, true );
		$this->assertSame( Stream_State::UNAVAILABLE, $out['state'] );
	}

	public function test_pending_when_a_virtual_session_has_no_embed() {
		$out = Stream_State::decide( $this->sessions( [ 'stream_embed' => [] ] ), 900, 1800, 1, true );
		$this->assertSame( Stream_State::PENDING, $out['state'] );
	}

	public function test_countdown_targets_the_open_instant() {
		$out = Stream_State::decide( $this->sessions(), 900, 1800, 1000000 - 5000, true );
		$this->assertSame( Stream_State::COUNTDOWN, $out['state'] );
		$this->assertSame( 1000000 - 900, $out['target_ts'] );
		$this->assertSame( [], $out['embed'], 'The embed is never exposed before the window opens.' );
	}

	/** Both boundary instants are INSIDE the live window. */
	public function test_live_boundaries_inclusive() {
		foreach ( [ 1000000 - 900, 1000000, 1007200, 1007200 + 1800 ] as $now ) {
			$out = Stream_State::decide( $this->sessions(), 900, 1800, $now, true );
			$this->assertSame( Stream_State::LIVE, $out['state'], "now={$now}" );
			$this->assertSame( 'https://player.vimeo.com/video/1', $out['embed']['src'] );
			$this->assertSame( 1007200 + 1800, $out['target_ts'] );
		}
	}

	public function test_between_two_days() {
		$day1 = $this->sessions()[0];
		$day2 = array_merge( $day1, [ 'date' => '2027-05-02', 'label' => 'Day 2', 'start_ts' => 1086400, 'end_ts' => 1093600 ] );
		$out  = Stream_State::decide( [ $day1, $day2 ], 900, 1800, 1007200 + 1801, true );

		$this->assertSame( Stream_State::BETWEEN, $out['state'] );
		$this->assertSame( 1, $out['session_index'] );
		$this->assertSame( 1086400 - 900, $out['target_ts'] );
		$this->assertSame( [], $out['embed'] );
	}

	public function test_ended_after_the_last_close() {
		$out = Stream_State::decide( $this->sessions(), 900, 1800, 1007200 + 1801, true );
		$this->assertSame( Stream_State::ENDED, $out['state'] );
	}

	/** A hybrid session is streamable too. */
	public function test_hybrid_session_is_live() {
		$out = Stream_State::decide( $this->sessions( [ 'modality' => 'hybrid' ] ), 900, 1800, 1000001, true );
		$this->assertSame( Stream_State::LIVE, $out['state'] );
	}

	/** A single event with no sessions rows resolves through its own bounds. */
	public function test_for_event_single_event_uses_implicit_session() {
		$start    = time() + DAY_IN_SECONDS;
		$event_id = $this->make_event( [
			'timezone'   => 'UTC',
			'start_date' => gmdate( 'Y-m-d', $start ),
			'start_time' => gmdate( 'H:i', $start ),
			'end_date'   => gmdate( 'Y-m-d', $start + 3600 ),
			'end_time'   => gmdate( 'H:i', $start + 3600 ),
			'start_ts'   => $start,
			'end_ts'     => $start + 3600,
			'registration_mode'       => 'free',
			'stream_default_modality' => 'virtual',
			'stream_embed'            => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/9', 'raw' => '' ],
		] );

		$out = Stream_State::for_event( $event_id, time() );
		$this->assertSame( Stream_State::COUNTDOWN, $out['state'] );
	}

	/** An external-registration event is never stream-capable. */
	public function test_for_event_external_is_unavailable() {
		$event_id = $this->make_event( [
			'registration_mode'       => 'external',
			'stream_default_modality' => 'virtual',
			'stream_embed'            => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/9', 'raw' => '' ],
		] );
		$this->assertSame( Stream_State::UNAVAILABLE, Stream_State::for_event( $event_id, time() )['state'] );
	}

	/** A session crossing midnight in the event zone keeps one contiguous window. */
	public function test_session_crossing_midnight() {
		$row = $this->sessions( [ 'start_ts' => 1000000, 'end_ts' => 1000000 + ( 4 * HOUR_IN_SECONDS ) ] );
		$out = Stream_State::decide( $row, 900, 1800, 1000000 + ( 3 * HOUR_IN_SECONDS ), true );
		$this->assertSame( Stream_State::LIVE, $out['state'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Stream_State
```
Expected: FAIL — `Class "Anchor\Events\Stream_State" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `anchor-events-manager/class-stream-state.php`:

```php
<?php
/**
 * The room's state machine (virtual-events spec §5.3).
 *
 * decide() is a PURE function of resolved sessions, two window widths and a
 * clock. That is deliberate: the room renders it, the REST endpoint re-renders
 * it and the admin list column prints it, and all three must agree to the
 * second. for_event() is the thin WordPress-aware wrapper that reads the event
 * and calls it.
 *
 * `embed` is populated ONLY in the `live` state — the room's whole reason for
 * existing is that the stream URL is not in the page before its window opens.
 *
 * @package AnchorTools\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Stream_State {

    const UNAVAILABLE = 'unavailable';
    const PENDING     = 'pending';
    const COUNTDOWN   = 'countdown';
    const LIVE        = 'live';
    const BETWEEN     = 'between';
    const ENDED       = 'ended';

    /** Modalities that can carry a stream. */
    const STREAMABLE = [ 'virtual', 'hybrid' ];

    /**
     * Resolve the state for an event at an instant.
     *
     * @param int $event_id
     * @param int $now      Unix timestamp; 0 = time().
     * @return array{state:string,session_index:int,target_ts:int,embed:array}
     */
    public static function for_event( $event_id, $now = 0 ) {
        $event_id = (int) $event_id;
        $now      = $now > 0 ? (int) $now : \time();
        $module   = Module::instance();
        if ( ! $module ) {
            return self::result( self::UNAVAILABLE, 0, 0, [] );
        }

        $meta = $module->get_meta( $event_id );
        return self::decide(
            $module->resolved_sessions( $event_id ),
            \max( 0, (int) $meta['stream_open_before_minutes'] ) * \MINUTE_IN_SECONDS,
            \max( 0, (int) $meta['stream_close_after_minutes'] ) * \MINUTE_IN_SECONDS,
            $now,
            $module->stream_capable( $event_id )
        );
    }

    /**
     * The pure core. Unit-tested as a table.
     *
     * @param array $sessions     Rows from Module::resolved_sessions().
     * @param int   $open_before  Seconds before start_ts the player opens.
     * @param int   $close_after  Seconds after end_ts the player closes.
     * @param int   $now          Unix timestamp.
     * @param bool  $capable      Whether the event may hold a stream at all.
     * @return array{state:string,session_index:int,target_ts:int,embed:array}
     */
    public static function decide( array $sessions, $open_before, $close_after, $now, $capable = true ) {
        $open_before = \max( 0, (int) $open_before );
        $close_after = \max( 0, (int) $close_after );
        $now         = (int) $now;

        if ( ! $capable ) {
            return self::result( self::UNAVAILABLE, 0, 0, [] );
        }

        // Only streamable sessions with a real instant are windows at all.
        $windows = [];
        $any_streamable = false;
        foreach ( $sessions as $index => $row ) {
            if ( ! \in_array( (string) ( $row['modality'] ?? '' ), self::STREAMABLE, true ) ) {
                continue;
            }
            $any_streamable = true;
            $start = (int) ( $row['start_ts'] ?? 0 );
            $end   = (int) ( $row['end_ts'] ?? 0 );
            if ( $start <= 0 ) {
                continue;
            }
            $windows[] = [
                'index' => (int) $index,
                'open'  => $start - $open_before,
                'close' => \max( $start, $end ) + $close_after,
                'embed' => \is_array( $row['stream_embed'] ?? null ) ? $row['stream_embed'] : [],
            ];
        }

        if ( ! $any_streamable ) {
            return self::result( self::UNAVAILABLE, 0, 0, [] );
        }
        // A streamable session exists but nothing resolves an embed, or no
        // session has a date yet: the room says "details will appear here".
        $has_embed = false;
        foreach ( $windows as $w ) {
            if ( ! empty( $w['embed']['src'] ) ) {
                $has_embed = true;
                break;
            }
        }
        if ( empty( $windows ) || ! $has_embed ) {
            return self::result( self::PENDING, 0, 0, [] );
        }

        \usort( $windows, static function ( $a, $b ) {
            return $a['open'] <=> $b['open'];
        } );

        // live — boundaries inclusive on both sides.
        foreach ( $windows as $w ) {
            if ( $now >= $w['open'] && $now <= $w['close'] ) {
                return self::result( self::LIVE, $w['index'], $w['close'], $w['embed'] );
            }
        }
        // countdown / between — the next window that has not opened yet. Which
        // of the two it is depends on whether anything has already CLOSED.
        $closed_any = false;
        foreach ( $windows as $w ) {
            if ( $now > $w['close'] ) {
                $closed_any = true;
                continue;
            }
            if ( $now < $w['open'] ) {
                return self::result( $closed_any ? self::BETWEEN : self::COUNTDOWN, $w['index'], $w['open'], [] );
            }
        }

        $last = \end( $windows );
        return self::result( self::ENDED, (int) $last['index'], (int) $last['close'], [] );
    }

    /** @return array{state:string,session_index:int,target_ts:int,embed:array} */
    private static function result( $state, $index, $target_ts, array $embed ) {
        return [
            'state'         => (string) $state,
            'session_index' => (int) $index,
            'target_ts'     => (int) $target_ts,
            'embed'         => $embed,
        ];
    }
}
```

Add to `Module::__construct()` after the Embed require:

```php
        // Room state machine (virtual-events spec §5.3) — pure, static.
        require_once $dir . 'class-stream-state.php';
```

Add to `Module`, next to `registration_mode()` (~:11270):

```php
    /**
     * May this event hold a hosted stream at all? (spec §2 decision 1)
     *
     * Only events registered THROUGH this plugin: we only know who someone is
     * when we took the registration. External-registration events never get a
     * room, and neither does a group PARENT — each of its dates has its own.
     *
     * @param int $event_id
     * @return bool
     */
    public function stream_capable( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 || \get_post_type( $event_id ) !== self::CPT ) {
            return false;
        }
        if ( $this->occurrences && $this->occurrences->is_group_parent( $event_id ) ) {
            return false;
        }
        return \in_array( $this->registration_mode( $event_id ), [ 'wc', 'free' ], true );
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Stream_State
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-stream-state.php anchor-events-manager/anchor-events-manager.php tests/test-stream-state.php
git commit -m "feat(events): add Stream_State room state machine and stream_capable()"
```

---

### Task 6: `Entitlements` core — role minting, grant, revoke

**Files:**
- Create: `anchor-events-manager/class-entitlements.php`
- Modify: `anchor-events-manager/anchor-events-manager.php:296` (property block), `:401` (require + construct)
- Test: `tests/test-entitlements.php`

**Interfaces:**
- Produces, on `$module->entitlements`:
  - `role_slug( int $event_id ): string`
  - `role_for( int $event_id, bool $create = true ): string` — `''` when absent and `$create` is false
  - `role_name( int $event_id ): string`
  - `rename_role( int $post_id, \WP_Post $post = null ): void`
  - `delete_role( int $event_id ): int` — holders stripped
  - `role_members( int $event_id ): int`
  - `grant( int $event_id, int $user_id, string $source = 'seat' ): bool`
  - `revoke( int $event_id, int $user_id, string $source = 'seat' ): bool`
  - `grants_for_user( int $user_id ): array<int,array{source:string,at:int,by:int}>`
  - `grant_record( int $event_id, int $user_id ): array`
  - `holds_role( int $event_id, int $user_id ): bool`
- Fires: `anchor_events_access_granted( $event_id, $user_id, $source )`, `anchor_events_access_revoked( $event_id, $user_id, $source )`.
- User meta key const: `Entitlements::GRANTS_META = '_anchor_event_grants'`.

- [ ] **Step 1: Write the failing test**

Create `tests/test-entitlements.php`:

```php
<?php
/**
 * Entitlements: roles, grants, accounts, access (virtual-events spec §4).
 *
 * Roles live in the `wp_user_roles` option and in the $wp_roles GLOBAL, and the
 * global survives the per-test transaction rollback — so every test that mints
 * one deletes it again in tearDown.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Entitlements;

/**
 * @group entitlements
 */
class Test_Entitlements extends Anchor_Events_TestCase {

	/** @var int[] Event ids whose roles this test minted. */
	private $minted = [];

	/** @return Entitlements */
	protected function ent() {
		return $this->module()->entitlements;
	}

	private function event( array $meta = [] ) {
		$id             = $this->make_event( array_merge( [ 'registration_mode' => 'free' ], $meta ) );
		$this->minted[] = $id;
		return $id;
	}

	public function tear_down() {
		foreach ( $this->minted as $event_id ) {
			remove_role( 'anchor_event_' . $event_id );
		}
		$this->minted = [];
		parent::tear_down();
	}

	/** Roles are minted lazily, named after the event, and hold no capabilities. */
	public function test_role_minted_lazily_with_no_caps() {
		$event_id = $this->event( [ 'title' => 'Laser Bootcamp' ] );

		$this->assertSame( '', $this->ent()->role_for( $event_id, false ), 'No grant yet, no role.' );

		$slug = $this->ent()->role_for( $event_id );
		$this->assertSame( 'anchor_event_' . $event_id, $slug );

		$role = get_role( $slug );
		$this->assertNotNull( $role );
		$this->assertSame( [], array_filter( (array) $role->capabilities ), 'The role is a membership tag, not a permission.' );
		$this->assertSame( 'Event: Laser Bootcamp', wp_roles()->roles[ $slug ]['name'] );
	}

	/** Granting adds the role additively and records why. */
	public function test_grant_is_additive_and_recorded() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertTrue( $this->ent()->grant( $event_id, $user_id, 'manual' ) );

		$user = new WP_User( $user_id );
		$this->assertContains( 'subscriber', $user->roles, 'The existing role is untouched.' );
		$this->assertContains( 'anchor_event_' . $event_id, $user->roles );

		$record = $this->ent()->grant_record( $event_id, $user_id );
		$this->assertSame( 'manual', $record['source'] );
		$this->assertGreaterThan( 0, $record['at'] );
	}

	/** Both actions fire with the documented arguments. */
	public function test_grant_and_revoke_actions_fire() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$seen     = [];

		$grab = function ( $e, $u, $s ) use ( &$seen ) { $seen[] = [ $e, $u, $s ]; };
		add_action( 'anchor_events_access_granted', $grab, 10, 3 );
		add_action( 'anchor_events_access_revoked', $grab, 10, 3 );

		$this->ent()->grant( $event_id, $user_id, 'seat' );
		$this->ent()->revoke( $event_id, $user_id, 'seat' );

		remove_action( 'anchor_events_access_granted', $grab, 10 );
		remove_action( 'anchor_events_access_revoked', $grab, 10 );

		$this->assertSame( [ [ $event_id, $user_id, 'seat' ], [ $event_id, $user_id, 'seat' ] ], $seen );
	}

	/** Renaming the event renames the role. */
	public function test_role_renames_with_the_title() {
		$event_id = $this->event( [ 'title' => 'Old Name' ] );
		$slug     = $this->ent()->role_for( $event_id );

		wp_update_post( [ 'ID' => $event_id, 'post_title' => 'New Name' ] );

		$this->assertSame( 'Event: New Name', wp_roles()->roles[ $slug ]['name'] );
	}

	/** The role survives the event being trashed. */
	public function test_role_survives_trash() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$this->ent()->grant( $event_id, $user_id, 'seat' );

		wp_trash_post( $event_id );

		$this->assertNotNull( get_role( 'anchor_event_' . $event_id ) );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ) );
	}

	/** Deleting the role strips it from every holder and reports the count. */
	public function test_delete_role_strips_holders() {
		$event_id = $this->event();
		$a = self::factory()->user->create();
		$b = self::factory()->user->create();
		$this->ent()->grant( $event_id, $a, 'seat' );
		$this->ent()->grant( $event_id, $b, 'manual' );

		$this->assertSame( 2, $this->ent()->role_members( $event_id ) );
		$this->assertSame( 2, $this->ent()->delete_role( $event_id ) );

		$this->assertNull( get_role( 'anchor_event_' . $event_id ) );
		$this->assertNotContains( 'anchor_event_' . $event_id, ( new WP_User( $a ) )->roles );
		$this->assertSame( [], $this->ent()->grants_for_user( $a ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Entitlements
```
Expected: FAIL — `Undefined property …Module::$entitlements`.

- [ ] **Step 3: Write minimal implementation**

Create `anchor-events-manager/class-entitlements.php`:

```php
<?php
/**
 * Who holds access to an event, and why (virtual-events spec §4).
 *
 * One responsibility. Every event that anybody is ever granted access to mints
 * a capability-less WordPress role `anchor_event_{id}`; holding that role is
 * the fact the Anchor Private File Manager, the courses module and anything
 * else that understands roles can gate on, with no knowledge of this plugin.
 *
 * Two things must stay true:
 *   - Roles are minted LAZILY and deleted NEVER (except by an explicit
 *     operator action). An event nobody registers for creates no role; a
 *     finished, trashed or deleted event keeps its role so the owner can go on
 *     granting after the fact.
 *   - A role is a membership tag, not a permission. It carries no capabilities
 *     at all, so adding one to a user can never widen what they may do.
 *
 * `_anchor_event_grants` (user meta) records WHY somebody holds a role, so a
 * seat cancellation can never strip a manual grant.
 *
 * @package AnchorTools\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Entitlements {

    /** User meta: [ event_id => { source: seat|manual, at: int, by: int } ]. */
    const GRANTS_META = '_anchor_event_grants';

    /** Seat meta: the account this seat entitles (0 = unresolved). */
    const SEAT_USER_META = '_anchor_event_user_id';

    const SOURCE_SEAT   = 'seat';
    const SOURCE_MANUAL = 'manual';

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;

        // The role's display name follows the event title. save_post (not
        // save_post_event alone) because a quick-edit title change does not run
        // the metabox save; wp_update_post fires this for every path.
        \add_action( 'save_post_' . Module::CPT, [ $this, 'rename_role' ], 10, 2 );

        // The two seat lifecycle entry points (see Task 7 for why BOTH are
        // needed: a seat is usually BORN confirmed and never transitions).
        \add_action( 'anchor_events_seat_created', [ $this, 'on_seat_created' ], 10, 2 );
        \add_action( 'anchor_events_seat_status_changed', [ $this, 'on_seat_status_changed' ], 10, 4 );
    }

    /* ---------------------------------------------------------------------
     * Roles
     * ------------------------------------------------------------------- */

    /** @return string The role slug for an event — never creates anything. */
    public function role_slug( $event_id ) {
        return 'anchor_event_' . (int) $event_id;
    }

    /** @return string The role's display name. */
    public function role_name( $event_id ) {
        /* translators: %s: event title. */
        return \sprintf( \__( 'Event: %s', 'anchor-schema' ), \get_the_title( (int) $event_id ) );
    }

    /**
     * The event's role, minting it on first use.
     *
     * @param int  $event_id
     * @param bool $create   false = "does it exist yet?", returns '' if not.
     * @return string Slug, or '' when absent and $create is false.
     */
    public function role_for( $event_id, $create = true ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) {
            return '';
        }
        $slug = $this->role_slug( $event_id );
        if ( \get_role( $slug ) ) {
            return $slug;
        }
        if ( ! $create ) {
            return '';
        }
        // No capabilities: membership, not permission.
        \add_role( $slug, $this->role_name( $event_id ), [] );
        return \get_role( $slug ) ? $slug : '';
    }

    /**
     * Keep the role's display name in step with the event title.
     *
     * Only ever RENAMES an existing role — it never mints one, so saving an
     * event nobody has registered for still creates nothing.
     *
     * @param int       $post_id
     * @param \WP_Post  $post
     */
    public function rename_role( $post_id, $post = null ) {
        $post_id = (int) $post_id;
        if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        $slug = $this->role_for( $post_id, false );
        if ( $slug === '' ) {
            return;
        }
        $roles = \wp_roles();
        $name  = $this->role_name( $post_id );
        if ( isset( $roles->roles[ $slug ] ) && $roles->roles[ $slug ]['name'] !== $name ) {
            $roles->roles[ $slug ]['name'] = $name;
            $roles->role_names[ $slug ]    = $name;
            \update_option( $roles->role_key, $roles->roles, false );
        }
    }

    /**
     * How many users hold the event's role.
     *
     * @param int $event_id
     * @return int
     */
    public function role_members( $event_id ) {
        $slug = $this->role_for( (int) $event_id, false );
        if ( $slug === '' ) {
            return 0;
        }
        $q = new \WP_User_Query( [ 'role' => $slug, 'fields' => 'ID', 'number' => 0 ] );
        return \count( (array) $q->get_results() );
    }

    /**
     * Operator-only: remove the role and strip it from every holder.
     *
     * The ONE path that deletes a role. Nothing automatic ever calls it —
     * trashing or deleting an event deliberately leaves the role behind.
     *
     * @param int $event_id
     * @return int Holders the role was stripped from.
     */
    public function delete_role( $event_id ) {
        $event_id = (int) $event_id;
        $slug     = $this->role_for( $event_id, false );
        if ( $slug === '' ) {
            return 0;
        }
        $q       = new \WP_User_Query( [ 'role' => $slug, 'fields' => 'ID', 'number' => 0 ] );
        $holders = \array_map( 'intval', (array) $q->get_results() );
        foreach ( $holders as $user_id ) {
            $this->revoke( $event_id, $user_id, 'delete_role' );
        }
        \remove_role( $slug );
        return \count( $holders );
    }

    /* ---------------------------------------------------------------------
     * Grants
     * ------------------------------------------------------------------- */

    /** @return bool Whether the user currently holds the event's role. */
    public function holds_role( $event_id, $user_id ) {
        $slug = $this->role_for( (int) $event_id, false );
        if ( $slug === '' || (int) $user_id <= 0 ) {
            return false;
        }
        $user = \get_userdata( (int) $user_id );
        return $user instanceof \WP_User && \in_array( $slug, (array) $user->roles, true );
    }

    /** @return array<int,array{source:string,at:int,by:int}> */
    public function grants_for_user( $user_id ) {
        $stored = \get_user_meta( (int) $user_id, self::GRANTS_META, true );
        return \is_array( $stored ) ? $stored : [];
    }

    /** @return array{source:string,at:int,by:int}|array Empty when no record. */
    public function grant_record( $event_id, $user_id ) {
        $grants = $this->grants_for_user( $user_id );
        return $grants[ (int) $event_id ] ?? [];
    }

    /**
     * Give a user the event's role and record why.
     *
     * add_role() is additive — a customer/subscriber keeps everything they had.
     *
     * @param int    $event_id
     * @param int    $user_id
     * @param string $source   seat|manual.
     * @return bool
     */
    public function grant( $event_id, $user_id, $source = self::SOURCE_SEAT ) {
        $event_id = (int) $event_id;
        $user_id  = (int) $user_id;
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return false;
        }
        $user = \get_userdata( $user_id );
        if ( ! $user instanceof \WP_User ) {
            return false;
        }
        $slug = $this->role_for( $event_id );
        if ( $slug === '' ) {
            Events_Log::error( 'access_role_mint_failed', [ 'event' => $event_id ] );
            return false;
        }

        if ( ! \in_array( $slug, (array) $user->roles, true ) ) {
            $user->add_role( $slug );
        }

        $grants = $this->grants_for_user( $user_id );
        // A manual grant OUTRANKS a seat grant and is never downgraded by one:
        // that is what makes "a cancellation cannot strip a comp" true.
        $existing = $grants[ $event_id ]['source'] ?? '';
        if ( $existing !== self::SOURCE_MANUAL || $source === self::SOURCE_MANUAL ) {
            $grants[ $event_id ] = [
                'source' => ( $source === self::SOURCE_MANUAL ) ? self::SOURCE_MANUAL : self::SOURCE_SEAT,
                'at'     => \time(),
                'by'     => (int) \get_current_user_id(),
            ];
            \update_user_meta( $user_id, self::GRANTS_META, $grants );
        }

        /**
         * A user just gained access to an event.
         *
         * @param int    $event_id
         * @param int    $user_id
         * @param string $source   seat|manual.
         */
        \do_action( 'anchor_events_access_granted', $event_id, $user_id, (string) $source );
        return true;
    }

    /**
     * Take the role away and clear the grant record.
     *
     * @param int    $event_id
     * @param int    $user_id
     * @param string $source   Reported to the action; not a permission check.
     * @return bool
     */
    public function revoke( $event_id, $user_id, $source = self::SOURCE_SEAT ) {
        $event_id = (int) $event_id;
        $user_id  = (int) $user_id;
        $user     = \get_userdata( $user_id );
        if ( $event_id <= 0 || ! $user instanceof \WP_User ) {
            return false;
        }
        $slug = $this->role_slug( $event_id );
        if ( \in_array( $slug, (array) $user->roles, true ) ) {
            $user->remove_role( $slug );
        }

        $grants = $this->grants_for_user( $user_id );
        if ( isset( $grants[ $event_id ] ) ) {
            unset( $grants[ $event_id ] );
            if ( empty( $grants ) ) {
                \delete_user_meta( $user_id, self::GRANTS_META );
            } else {
                \update_user_meta( $user_id, self::GRANTS_META, $grants );
            }
        }

        /**
         * A user just lost access to an event.
         *
         * @param int    $event_id
         * @param int    $user_id
         * @param string $source
         */
        \do_action( 'anchor_events_access_revoked', $event_id, $user_id, (string) $source );
        return true;
    }
}
```

In `Module`, after the `$event_schema` property (~:317):

```php
    /** @var Entitlements|null Event roles + access (spec §4; always loaded). */
    public $entitlements = null;
```

In `Module::__construct()`, after `$this->event_schema = new Event_Schema( $this );`:

```php
        // Event roles / access (virtual-events spec §4) — free + paid, no
        // WooCommerce dependency. Constructed like Registrations/Roster so
        // $module->entitlements is the one handle every surface uses.
        require_once $dir . 'class-entitlements.php';
        $this->entitlements = new Entitlements( $this );
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Entitlements
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-entitlements.php anchor-events-manager/anchor-events-manager.php tests/test-entitlements.php
git commit -m "feat(events): add Entitlements — per-event roles, grants and revocations"
```

---

### Task 7: Seat lifecycle hooks (including the missing `seat_created` action)

**Files:**
- Modify: `anchor-events-manager/class-registrations.php:349` (end of `create_seat()`)
- Modify: `anchor-events-manager/class-entitlements.php` (add `on_seat_created()`, `on_seat_status_changed()`, `maybe_revoke_seat_grant()`)
- Test: `tests/test-entitlements.php`

**Interfaces:**
- Produces: action `anchor_events_seat_created( int $seat_id, string $status )`; `Entitlements::on_seat_created( int $seat_id, string $status ): void`; `Entitlements::on_seat_status_changed( int $seat_id, string $from, string $to, string $actor ): void`; `Entitlements::maybe_revoke_seat_grant( int $event_id, int $user_id ): void`.
- Consumes (Task 8 supplies it, so `on_seat_created` is written to tolerate 0): `Entitlements::ensure_user( array $seat ): int`. **For this task**, resolve the user with `get_user_by('email', …)` only; Task 8 replaces that one line with `ensure_user()`.

- [ ] **Step 1: Write the failing test**

Append to `tests/test-entitlements.php`:

```php
	/** A seat BORN confirmed grants access — create_seat() never transitions. */
	public function test_seat_created_confirmed_grants() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'ada@example.test' ] );

		$this->make_seat( $event_id, [ 'email' => 'ada@example.test' ] );

		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ) );
		$this->assertSame( 'seat', $this->ent()->grant_record( $event_id, $user_id )['source'] );
	}

	/** A pending seat grants nothing until it is confirmed. */
	public function test_pending_seat_grants_nothing_then_promotes() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'bob@example.test' ] );

		$seat_id = $this->make_seat( $event_id, [
			'email'  => 'bob@example.test',
			'status' => \Anchor\Events\Registrations::STATUS_PENDING,
		] );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $user_id ) );

		$this->registrations()->update_status( $seat_id, \Anchor\Events\Registrations::STATUS_CONFIRMED );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ) );
	}

	/** Cancelling the only confirmed seat revokes. */
	public function test_cancel_revokes() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'cara@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'cara@example.test' ] );

		$this->registrations()->update_status( $seat_id, \Anchor\Events\Registrations::STATUS_CANCELLED );

		$this->assertFalse( $this->ent()->holds_role( $event_id, $user_id ) );
	}

	/** A second confirmed seat keeps access alive. */
	public function test_cancel_with_a_second_confirmed_seat_keeps_access() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'dee@example.test' ] );
		$first    = $this->make_seat( $event_id, [ 'email' => 'dee@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'dee@example.test', 'seat_index' => 2 ] );

		$this->registrations()->update_status( $first, \Anchor\Events\Registrations::STATUS_CANCELLED );

		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ) );
	}

	/** A manual grant survives a seat cancellation. */
	public function test_cancel_never_strips_a_manual_grant() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'eve@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'eve@example.test' ] );
		$this->ent()->grant( $event_id, $user_id, 'manual' );

		$this->registrations()->update_status( $seat_id, \Anchor\Events\Registrations::STATUS_REFUNDED );

		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ) );
		$this->assertSame( 'manual', $this->ent()->grant_record( $event_id, $user_id )['source'] );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Entitlements
```
Expected: FAIL — `holds_role()` returns false after `make_seat()`.

- [ ] **Step 3: Write minimal implementation**

In `Registrations::create_seat()`, replace the final two lines (`$this->bust_cache( $event_id ); return (int) $seat_id;`) with:

```php
        $this->bust_cache( $event_id );

        /**
         * A seat was just created.
         *
         * The companion to `anchor_events_seat_status_changed`, and NOT a
         * duplicate of it: a seat is usually BORN in its final status
         * (a free registration, a comped roster add and a completed-at-checkout
         * WooCommerce line are all created `confirmed`) and never transitions
         * at all, so a listener that only watches transitions sees nothing for
         * the majority of attendees. Fires after every meta write, so a
         * listener reading the seat back gets the finished record.
         *
         * @param int    $seat_id
         * @param string $status  The status the seat was created in.
         */
        \do_action( 'anchor_events_seat_created', (int) $seat_id, (string) $status );

        return (int) $seat_id;
```

Append to `Entitlements`:

```php
    /* ---------------------------------------------------------------------
     * Seat lifecycle (spec §4.2)
     * ------------------------------------------------------------------- */

    /**
     * A seat was created. Grant when it is born confirmed.
     *
     * `pending` grants nothing: a WooCommerce on-hold order and a waitlist seat
     * are both "maybe". Promotion to confirmed comes through
     * on_seat_status_changed() and grants normally.
     *
     * @param int    $seat_id
     * @param string $status
     */
    public function on_seat_created( $seat_id, $status ) {
        if ( (string) $status !== Registrations::STATUS_CONFIRMED ) {
            return;
        }
        $this->grant_for_seat( (int) $seat_id );
    }

    /**
     * A seat moved. Grant on entering confirmed; consider revoking on leaving it.
     *
     * @param int    $seat_id
     * @param string $from
     * @param string $to
     * @param string $actor
     */
    public function on_seat_status_changed( $seat_id, $from, $to, $actor = '' ) {
        $seat_id = (int) $seat_id;
        if ( (string) $to === Registrations::STATUS_CONFIRMED ) {
            $this->grant_for_seat( $seat_id );
            return;
        }
        if ( (string) $to === Registrations::STATUS_PENDING ) {
            return; // Reserved, not entitled. Nothing granted, nothing taken.
        }
        $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );
        $user_id  = (int) \get_post_meta( $seat_id, self::SEAT_USER_META, true );
        if ( $user_id <= 0 ) {
            $user = \get_user_by( 'email', (string) \get_post_meta( $seat_id, '_anchor_event_email', true ) );
            $user_id = $user ? (int) $user->ID : 0;
        }
        $this->maybe_revoke_seat_grant( $event_id, $user_id );
    }

    /**
     * Revoke a SEAT grant only when nothing else entitles the user: no other
     * confirmed seat on this event, and no manual grant on record.
     *
     * @param int $event_id
     * @param int $user_id
     */
    public function maybe_revoke_seat_grant( $event_id, $user_id ) {
        $event_id = (int) $event_id;
        $user_id  = (int) $user_id;
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return;
        }
        if ( ( $this->grant_record( $event_id, $user_id )['source'] ?? '' ) === self::SOURCE_MANUAL ) {
            return; // A comp is not undone by a refund.
        }
        if ( $this->has_confirmed_seat( $event_id, $user_id ) ) {
            return; // Another seat still entitles them.
        }
        $this->revoke( $event_id, $user_id, self::SOURCE_SEAT );
    }

    /**
     * Whether the user holds at least one CONFIRMED seat on the event.
     *
     * Deliberately narrower than Registrations::user_has_active_seat(), which
     * also counts `pending` — a reserved seat is not an entitlement.
     *
     * @param int $event_id
     * @param int $user_id
     * @return bool
     */
    public function has_confirmed_seat( $event_id, $user_id ) {
        $user = \get_userdata( (int) $user_id );
        if ( ! $user instanceof \WP_User ) {
            return false;
        }
        $identity = [
            'relation' => 'OR',
            [ 'key' => self::SEAT_USER_META, 'value' => (int) $user_id, 'compare' => '=', 'type' => 'NUMERIC' ],
            [ 'key' => '_anchor_event_customer_id', 'value' => (int) $user_id, 'compare' => '=', 'type' => 'NUMERIC' ],
            [ 'key' => '_anchor_event_email', 'value' => (string) $user->user_email, 'compare' => '=' ],
        ];
        $q = new \WP_Query( [
            'post_type'      => Module::REG_CPT,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_anchor_event_id', 'value' => (int) $event_id, 'compare' => '=', 'type' => 'NUMERIC' ],
                [ 'key' => '_anchor_event_reg_status', 'value' => Registrations::STATUS_CONFIRMED, 'compare' => '=' ],
                $identity,
            ],
        ] );
        return ! empty( $q->posts );
    }

    /**
     * Resolve the seat's user and grant. Task 8 replaces the resolution with
     * ensure_user(); until then an existing account by email is enough.
     *
     * @param int $seat_id
     */
    private function grant_for_seat( $seat_id ) {
        $seat_id  = (int) $seat_id;
        $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );
        if ( $event_id <= 0 ) {
            return;
        }
        $user_id = (int) \get_post_meta( $seat_id, self::SEAT_USER_META, true );
        if ( $user_id <= 0 ) {
            $user    = \get_user_by( 'email', (string) \get_post_meta( $seat_id, '_anchor_event_email', true ) );
            $user_id = $user ? (int) $user->ID : 0;
        }
        if ( $user_id > 0 ) {
            $this->grant( $event_id, $user_id, self::SOURCE_SEAT );
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Entitlements
vendor/bin/phpunit --filter Test_Status_Transitions
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-registrations.php anchor-events-manager/class-entitlements.php tests/test-entitlements.php
git commit -m "feat(events): grant and revoke event access from the seat lifecycle"
```

---

### Task 8: `ensure_user()` — four branches, no new-user mail

**Files:**
- Modify: `anchor-events-manager/class-entitlements.php` (add `ensure_user()`, rewrite `grant_for_seat()`)
- Modify: `anchor-events-manager/class-registrations.php:330` (write `_anchor_event_user_id`), `:1184` (`user_has_active_seat()` identity block)
- Modify: `anchor-events-manager/class-roster.php:718` (after `claim_seats()`)
- Modify: `anchor-events-manager/class-woocommerce.php:3087` (after `create_seat()` returns a seat id)
- Test: `tests/test-entitlements.php`

**Interfaces:**
- Produces: `Entitlements::ensure_user( array $seat ): int` — `$seat` is a `Registrations::get_seat()` DTO **or** `[ 'id' => int ]`; returns the resolved user id or 0. Filter `anchor_events_create_account` (bool, default `true`, args `$create, $event_id, $email`).

- [ ] **Step 1: Write the failing test**

Append to `tests/test-entitlements.php`:

```php
	/** Branch 1: the seat already names a user. */
	public function test_ensure_user_uses_stored_user_id() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'nobody@example.test' ] );
		update_post_meta( $seat_id, '_anchor_event_user_id', $user_id );

		$this->assertSame( $user_id, $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) ) );
	}

	/** Branch 2: an order seat with a customer id. */
	public function test_ensure_user_uses_order_customer_id() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'guest@example.test', 'customer_id' => $user_id ] );

		$this->assertSame( $user_id, $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) ) );
		$this->assertSame( $user_id, (int) get_post_meta( $seat_id, '_anchor_event_user_id', true ) );
	}

	/** Branch 3: an existing account matched by email. */
	public function test_ensure_user_matches_by_email() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'known@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'known@example.test' ] );

		$this->assertSame( $user_id, $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) ) );
	}

	/** Branch 4: a new account, with the seat's name, and NO WordPress new-user mail. */
	public function test_ensure_user_creates_account_without_emailing() {
		$event_id = $this->event();
		$seat_id  = $this->make_seat( $event_id, [ 'name' => 'Grace Hopper', 'email' => 'grace@example.test' ] );

		$mails = [];
		$spy   = function ( $args ) use ( &$mails ) { $mails[] = $args; return $args; };
		add_filter( 'wp_mail', $spy );
		$user_id = $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) );
		remove_filter( 'wp_mail', $spy );

		$this->assertGreaterThan( 0, $user_id );
		$this->assertSame( 'grace@example.test', ( new WP_User( $user_id ) )->user_email );
		$this->assertSame( 'Grace Hopper', ( new WP_User( $user_id ) )->display_name );
		$this->assertSame( [], $mails, 'Account creation must not send a WordPress new-user email.' );
	}

	/** A site may opt out of account creation entirely. */
	public function test_create_account_filter_opts_out() {
		$event_id = $this->event();
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'nope@example.test' ] );

		add_filter( 'anchor_events_create_account', '__return_false' );
		$user_id = $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) );
		remove_filter( 'anchor_events_create_account', '__return_false' );

		$this->assertSame( 0, $user_id );
		$this->assertNull( get_user_by( 'email', 'nope@example.test' ) ?: null );
	}

	/** user_has_active_seat() matches on the resolved user id first. */
	public function test_user_has_active_seat_matches_user_id() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'renamed@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'old-address@example.test' ] );
		update_post_meta( $seat_id, '_anchor_event_user_id', $user_id );

		$this->assertTrue( $this->registrations()->user_has_active_seat( $event_id, $user_id, 'renamed@example.test' ) );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Entitlements
```
Expected: FAIL — `Call to undefined method …Entitlements::ensure_user()`.

- [ ] **Step 3: Write minimal implementation**

Append to `Entitlements`:

```php
    /* ---------------------------------------------------------------------
     * Accounts (spec §4.3)
     * ------------------------------------------------------------------- */

    /**
     * The account a seat entitles, creating one if there isn't one.
     *
     * Four branches, in order:
     *   1. `_anchor_event_user_id` already set and the user still exists.
     *   2. An order seat with `customer_id > 0`.
     *   3. An existing account with the seat's email.
     *   4. Create one — wc_create_new_customer() when WooCommerce is active, so
     *      My Account works, else wp_insert_user() with the site's default role.
     *
     * NO WordPress (or WooCommerce) new-account email is ever sent: our own
     * confirmation carries the one-click sign-in link (§6.2), and a second
     * "here is your new password" mail from a course registration is noise the
     * attendee did not ask for.
     *
     * Always writes the resolution back to the seat so the next caller takes
     * branch 1.
     *
     * @param array $seat Registrations::get_seat() DTO, or [ 'id' => int ].
     * @return int User id, or 0 (no email on the seat, or the site opted out).
     */
    public function ensure_user( array $seat ) {
        $seat_id = (int) ( $seat['id'] ?? 0 );
        if ( $seat_id <= 0 ) {
            return 0;
        }
        $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );

        // 1 — already resolved.
        $stored = (int) \get_post_meta( $seat_id, self::SEAT_USER_META, true );
        if ( $stored > 0 && \get_userdata( $stored ) ) {
            return $stored;
        }

        // 2 — the order's customer.
        $customer_id = (int) ( $seat['customer_id'] ?? \get_post_meta( $seat_id, '_anchor_event_customer_id', true ) );
        if ( $customer_id > 0 && \get_userdata( $customer_id ) ) {
            return $this->remember_seat_user( $seat_id, $customer_id );
        }

        $email = \sanitize_email( (string) ( $seat['email'] ?? \get_post_meta( $seat_id, '_anchor_event_email', true ) ) );
        if ( $email === '' ) {
            return 0;
        }

        // 3 — an existing account.
        $existing = \get_user_by( 'email', $email );
        if ( $existing instanceof \WP_User ) {
            return $this->remember_seat_user( $seat_id, (int) $existing->ID );
        }

        /**
         * Whether this site creates accounts for registrants who have none.
         *
         * Returning false means guests get no room access; the confirmation
         * email says so rather than pointing at a room they cannot enter.
         *
         * @param bool   $create
         * @param int    $event_id
         * @param string $email
         */
        if ( ! \apply_filters( 'anchor_events_create_account', true, $event_id, $email ) ) {
            return 0;
        }

        // 4 — create.
        $name     = \sanitize_text_field( (string) ( $seat['name'] ?? \get_post_meta( $seat_id, '_anchor_event_name', true ) ) );
        $username = $this->unique_username( $email );
        $password = \wp_generate_password( 24, true, true );

        // Suppress WooCommerce's "New account" email for the duration of the
        // create — wc_create_new_customer() fires woocommerce_created_customer,
        // which WC_Emails turns into a mail. wp_insert_user() sends nothing of
        // its own, so the plain branch needs no suppression.
        \add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
        try {
            if ( \function_exists( 'wc_create_new_customer' ) ) {
                $user_id = \wc_create_new_customer( $email, $username, $password, [ 'display_name' => $name ] );
            } else {
                $user_id = \wp_insert_user( [
                    'user_login'   => $username,
                    'user_email'   => $email,
                    'user_pass'    => $password,
                    'display_name' => $name !== '' ? $name : $username,
                    'role'         => (string) \get_option( 'default_role', 'subscriber' ),
                ] );
            }
        } finally {
            \remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
        }

        if ( \is_wp_error( $user_id ) || ! $user_id ) {
            Events_Log::error( 'access_user_create_failed', [
                'event' => $event_id,
                'seat'  => $seat_id,
                'to'    => \substr( \md5( $email ), 0, 8 ), // redacted identity (ERROR_IDENTITY_KEYS).
            ] );
            return 0;
        }

        if ( $name !== '' ) {
            \wp_update_user( [ 'ID' => (int) $user_id, 'display_name' => $name ] );
        }
        return $this->remember_seat_user( $seat_id, (int) $user_id );
    }

    /** Write the resolution onto the seat and return it. */
    private function remember_seat_user( $seat_id, $user_id ) {
        \update_post_meta( (int) $seat_id, self::SEAT_USER_META, (int) $user_id );
        return (int) $user_id;
    }

    /** A login that is not taken, derived from the email local part. */
    private function unique_username( $email ) {
        $base = \sanitize_user( \current( \explode( '@', $email ) ), true );
        if ( $base === '' ) {
            $base = 'attendee';
        }
        $candidate = $base;
        $n         = 1;
        while ( \username_exists( $candidate ) ) {
            $candidate = $base . '-' . ( ++$n );
        }
        return $candidate;
    }
```

Rewrite `grant_for_seat()` to use it:

```php
    private function grant_for_seat( $seat_id ) {
        $seat_id  = (int) $seat_id;
        $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );
        if ( $event_id <= 0 ) {
            return;
        }
        $user_id = $this->ensure_user( [ 'id' => $seat_id ] );
        if ( $user_id > 0 ) {
            $this->grant( $event_id, $user_id, self::SOURCE_SEAT );
        }
    }
```

In `Registrations::create_seat()`'s `$meta` array, after `'_anchor_event_customer_id' => …`:

```php
            // The account this seat ENTITLES, as distinct from the WooCommerce
            // order's customer above (0 = guest). Resolved by
            // Entitlements::ensure_user() on every path — free, manual, paid.
            '_anchor_event_user_id'       => max( 0, (int) ( $args['user_id'] ?? 0 ) ),
```

In `Registrations::user_has_active_seat()`, add to the `$identity` block before the customer-id clause:

```php
        if ( $user_id > 0 ) {
            // Checked first (spec §3.5): the resolved account is authoritative,
            // an attendee who changed their email address still matches.
            $identity[] = [ 'key' => '_anchor_event_user_id', 'value' => $user_id, 'compare' => '=', 'type' => 'NUMERIC' ];
        }
```

In `Registrations::seat_dto()`, after `'customer_id' => …`:

```php
            'user_id'       => (int) $g( '_anchor_event_user_id' ),
```

In `Roster::handle_add()`, immediately after the `$result = $this->registrations->claim_seats( … );` call:

```php
        // Comped adds resolve (and if necessary create) an account too, so a
        // hand-added attendee reaches the room exactly like a paid one.
        if ( $this->module->entitlements ) {
            foreach ( \array_merge( (array) ( $result['created'] ?? [] ), (array) ( $result['waitlisted'] ?? [] ) ) as $new_seat_id ) {
                $this->module->entitlements->ensure_user( [ 'id' => (int) $new_seat_id ] );
            }
        }
```

In `WooCommerce`, inside the `if ( $seat_id ) { $created[] = $seat_id; }` block (class-woocommerce.php:3088):

```php
                            if ( $seat_id ) {
                                $created[] = $seat_id;
                                // Resolve the attendee's account at capture time,
                                // not at grant time: the attendee's email on the
                                // line may differ from the order's customer.
                                if ( $this->module->entitlements ) {
                                    $this->module->entitlements->ensure_user( [ 'id' => (int) $seat_id ] );
                                }
                            }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Entitlements
vendor/bin/phpunit --filter Test_Roster
vendor/bin/phpunit --filter Test_Woocommerce_Line_Item_Snapshot
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-entitlements.php anchor-events-manager/class-registrations.php anchor-events-manager/class-roster.php anchor-events-manager/class-woocommerce.php tests/test-entitlements.php
git commit -m "feat(events): resolve or create an account for every seat (ensure_user)"
```

---

### Task 9: `can_access_stream()` and the `can_view_virtual_link()` delegation

**Files:**
- Modify: `anchor-events-manager/class-entitlements.php` (add `can_access_stream()`, `tier_modality_for_user()`)
- Modify: `anchor-events-manager/anchor-events-manager.php:9156` (`can_view_virtual_link()`)
- Test: `tests/test-entitlements.php`

**Interfaces:**
- Produces: `Entitlements::can_access_stream( int $event_id, int $session_index = 0, int $user_id = 0 ): bool`, filtered through `anchor_events_can_access_stream( $allowed, $event_id, $session_index, $user_id )`.
- Consumes: `Module::stream_capable()`, `Module::resolved_sessions()`, `Roster::current_user_can_manage()`, `Ticket_Types::find()`.

- [ ] **Step 1: Write the failing test**

Append to `tests/test-entitlements.php`:

```php
	/** Build a streamable event with one virtual tier and one in-person tier. */
	private function stream_event( array $meta = [] ) {
		$start    = time() + HOUR_IN_SECONDS;
		$event_id = $this->event( array_merge( [
			'timezone'   => 'UTC',
			'start_date' => gmdate( 'Y-m-d', $start ),
			'start_time' => gmdate( 'H:i', $start ),
			'start_ts'   => $start,
			'end_ts'     => $start + 3600,
			'stream_default_modality' => 'hybrid',
			'stream_embed' => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/1', 'raw' => '' ],
		], $meta ) );
		$tiers = $this->ticket_types()->save( $event_id, [
			[ 'label' => 'In person', 'price' => '0', 'active' => 1, 'modality' => 'in_person' ],
			[ 'label' => 'Livestream', 'price' => '0', 'active' => 1, 'modality' => 'virtual' ],
		] );
		return [ $event_id, $tiers[0]['id'], $tiers[1]['id'] ];
	}

	public function test_logged_out_is_denied() {
		[ $event_id ] = $this->stream_event();
		wp_set_current_user( 0 );
		$this->assertFalse( $this->ent()->can_access_stream( $event_id ) );
	}

	public function test_staff_always_allowed() {
		[ $event_id ] = $this->stream_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( $this->ent()->can_access_stream( $event_id ) );
	}

	public function test_external_event_denied_even_for_a_holder() {
		[ $event_id, , $virtual ] = $this->stream_event( [ 'registration_mode' => 'external' ] );
		$user_id = self::factory()->user->create( [ 'user_email' => 'x@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'x@example.test', 'ticket_type_id' => $virtual ] );
		$this->assertFalse( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );
	}

	public function test_in_person_session_denies_everyone() {
		[ $event_id, , $virtual ] = $this->stream_event( [ 'stream_default_modality' => 'in_person' ] );
		$user_id = self::factory()->user->create( [ 'user_email' => 'y@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'y@example.test', 'ticket_type_id' => $virtual ] );
		$this->assertFalse( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );
	}

	public function test_virtual_tier_allowed() {
		[ $event_id, , $virtual ] = $this->stream_event();
		$user_id = self::factory()->user->create( [ 'user_email' => 'v@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'v@example.test', 'ticket_type_id' => $virtual ] );
		$this->assertTrue( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );
	}

	public function test_in_person_tier_follows_the_toggle() {
		[ $on_id, $in_person_on ] = $this->stream_event( [ 'in_person_includes_stream' => true ] );
		$a = self::factory()->user->create( [ 'user_email' => 'a2@example.test' ] );
		$this->make_seat( $on_id, [ 'email' => 'a2@example.test', 'ticket_type_id' => $in_person_on ] );
		$this->assertTrue( $this->ent()->can_access_stream( $on_id, 0, $a ) );

		[ $off_id, $in_person_off ] = $this->stream_event( [ 'in_person_includes_stream' => false ] );
		$b = self::factory()->user->create( [ 'user_email' => 'b2@example.test' ] );
		$this->make_seat( $off_id, [ 'email' => 'b2@example.test', 'ticket_type_id' => $in_person_off ] );
		$this->assertFalse( $this->ent()->can_access_stream( $off_id, 0, $b ) );
	}

	public function test_manual_grant_allowed_with_no_seat() {
		[ $event_id ] = $this->stream_event();
		$user_id = self::factory()->user->create();
		$this->ent()->grant( $event_id, $user_id, 'manual' );
		$this->assertTrue( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );
	}

	public function test_filter_can_veto() {
		[ $event_id, , $virtual ] = $this->stream_event();
		$user_id = self::factory()->user->create( [ 'user_email' => 'veto@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'veto@example.test', 'ticket_type_id' => $virtual ] );

		add_filter( 'anchor_events_can_access_stream', '__return_false' );
		$allowed = $this->ent()->can_access_stream( $event_id, 0, $user_id );
		remove_filter( 'anchor_events_can_access_stream', '__return_false' );

		$this->assertFalse( $allowed );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Entitlements
```
Expected: FAIL — `Call to undefined method …::can_access_stream()`.

- [ ] **Step 3: Write minimal implementation**

Append to `Entitlements`:

```php
    /* ---------------------------------------------------------------------
     * The one question (spec §4.5)
     * ------------------------------------------------------------------- */

    /**
     * May this person watch this session's stream?
     *
     * Resolution order, first hit wins (spec §4.5):
     *   1. Roster staff (current user only) — yes.
     *   2. Not logged in — no.
     *   3. Not stream-capable, session is in_person, or no embed resolves — no.
     *   4. A `manual` grant on record — yes.
     *   5. Holds the role AND a confirmed seat on a `virtual` tier — yes.
     *   6. Holds the role AND a confirmed `in_person` seat, with the event's
     *      "in-person registrants also get the stream" toggle on — yes.
     *   7. Otherwise no.
     *
     * @param int $event_id
     * @param int $session_index Index into Module::resolved_sessions().
     * @param int $user_id       0 = the current user.
     * @return bool
     */
    public function can_access_stream( $event_id, $session_index = 0, $user_id = 0 ) {
        $event_id      = (int) $event_id;
        $session_index = (int) $session_index;
        $for_current   = ( (int) $user_id === 0 );
        $user_id       = $for_current ? (int) \get_current_user_id() : (int) $user_id;

        $allowed = $this->resolve_access( $event_id, $session_index, $user_id, $for_current );

        /**
         * The final say on stream access.
         *
         * The courses module uses this to veto ("finish the pre-work first").
         *
         * @param bool $allowed
         * @param int  $event_id
         * @param int  $session_index
         * @param int  $user_id
         */
        return (bool) \apply_filters( 'anchor_events_can_access_stream', $allowed, $event_id, $session_index, $user_id );
    }

    /** The unfiltered decision — kept separate so the filter wraps it once. */
    private function resolve_access( $event_id, $session_index, $user_id, $for_current ) {
        // 1 — staff. Only meaningful for the CURRENT user: current_user_can()
        // cannot answer for somebody else without switching user context.
        if ( $for_current && Roster::current_user_can_manage() ) {
            return true;
        }
        // 2.
        if ( $user_id <= 0 ) {
            return false;
        }
        // 3.
        if ( ! $this->module->stream_capable( $event_id ) ) {
            return false;
        }
        $sessions = $this->module->resolved_sessions( $event_id );
        $session  = $sessions[ $session_index ] ?? null;
        if ( ! \is_array( $session ) ) {
            return false;
        }
        if ( ! \in_array( (string) $session['modality'], Stream_State::STREAMABLE, true ) ) {
            return false;
        }
        if ( empty( $session['stream_embed']['src'] ) ) {
            return false;
        }
        // 4.
        if ( ( $this->grant_record( $event_id, $user_id )['source'] ?? '' ) === self::SOURCE_MANUAL ) {
            return true;
        }
        // 5 / 6.
        if ( ! $this->holds_role( $event_id, $user_id ) ) {
            return false;
        }
        $modality = $this->seat_tier_modality( $event_id, $user_id );
        if ( $modality === 'virtual' ) {
            return true;
        }
        if ( $modality === 'in_person' ) {
            $meta = $this->module->get_meta( $event_id );
            return ! empty( $meta['in_person_includes_stream'] );
        }
        return false; // Role but no confirmed seat: a stale role is not access.
    }

    /**
     * The best tier modality across the user's confirmed seats on an event.
     *
     * "Best" because a virtual seat always wins: somebody holding both an
     * in-person and a livestream ticket is entitled by the livestream one
     * regardless of the event toggle.
     *
     * @param int $event_id
     * @param int $user_id
     * @return string in_person|virtual|'' (no confirmed seat).
     */
    public function seat_tier_modality( $event_id, $user_id ) {
        $user = \get_userdata( (int) $user_id );
        if ( ! $user instanceof \WP_User ) {
            return '';
        }
        $q = new \WP_Query( [
            'post_type'      => Module::REG_CPT,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 50,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_anchor_event_id', 'value' => (int) $event_id, 'compare' => '=', 'type' => 'NUMERIC' ],
                [ 'key' => '_anchor_event_reg_status', 'value' => Registrations::STATUS_CONFIRMED, 'compare' => '=' ],
                [
                    'relation' => 'OR',
                    [ 'key' => self::SEAT_USER_META, 'value' => (int) $user_id, 'compare' => '=', 'type' => 'NUMERIC' ],
                    [ 'key' => '_anchor_event_customer_id', 'value' => (int) $user_id, 'compare' => '=', 'type' => 'NUMERIC' ],
                    [ 'key' => '_anchor_event_email', 'value' => (string) $user->user_email, 'compare' => '=' ],
                ],
            ],
        ] );

        $best = '';
        foreach ( $q->posts as $seat_id ) {
            $tier_id = (string) \get_post_meta( (int) $seat_id, '_anchor_event_ticket_type_id', true );
            $tier    = $this->module->ticket_types ? $this->module->ticket_types->find( (int) $event_id, $tier_id ) : null;
            // A tier with no modality is an in-person tier — the meaning every
            // pre-upgrade tier already had (spec §3.3).
            $modality = \is_array( $tier ) ? (string) ( $tier['modality'] ?? 'in_person' ) : 'in_person';
            if ( $modality === 'virtual' ) {
                return 'virtual';
            }
            $best = 'in_person';
        }
        return $best;
    }
```

Rewrite `Module::can_view_virtual_link()` (keeping the informational-public branch — D3):

```php
    private function can_view_virtual_link( $post_id, $meta ) {
        $post_id   = (int) $post_id;
        $is_linked = ( $this->woocommerce && $this->woocommerce->event_is_linked( $post_id ) );

        if ( empty( $meta['registration_enabled'] ) && ! $is_linked ) {
            // Informational public event — nothing is gated behind the link, so
            // it stays visible to everyone. DELIBERATELY kept ahead of the
            // delegation below: can_access_stream() answers false for a
            // logged-out visitor, and this branch is precisely the case where
            // that is the wrong answer.
            return true;
        }

        // Everything else is the ONE access question (spec §4.5), so the event
        // page's "Join here" and the room can never disagree.
        if ( $this->entitlements ) {
            return $this->entitlements->can_access_stream( $post_id, 0, 0 );
        }

        if ( Roster::current_user_can_manage() ) {
            return true;
        }
        if ( ! \is_user_logged_in() ) {
            return false;
        }
        $user = \wp_get_current_user();
        return $this->registrations->user_has_active_seat( $post_id, (int) $user->ID, (string) $user->user_email );
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Entitlements
vendor/bin/phpunit --filter Test_Event_Frontend_Render
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-entitlements.php anchor-events-manager/anchor-events-manager.php tests/test-entitlements.php
git commit -m "feat(events): add can_access_stream() and route the join-link gate through it"
```

---

### Task 10: Prerequisites inside `capacity_decision()`

**Files:**
- Modify: `anchor-events-manager/class-registrations.php:909` (`capacity_decision()`)
- Modify: `anchor-events-manager/anchor-events-manager.php:12578` (`bookability()`), and `render_registration_form()`'s state switch
- Modify: `anchor-events-manager/class-woocommerce.php:1397` (`bookability_message()`)
- Modify: `anchor-events-manager/class-event-schema.php:132` (`availability_for()`), `:164` (`omits_offer()`)
- Modify: `anchor-events-manager/class-entitlements.php` (add `meets_prerequisites()`, `prerequisite_message()`)
- Test: `tests/test-prerequisites.php`

**Interfaces:**
- Produces: `Entitlements::meets_prerequisites( int $event_id, int $user_id = 0 ): bool`; `Entitlements::prerequisite_message( int $event_id ): string`; new `capacity_decision()` return value `'prerequisite'`; `Module::bookability()` return value `'prerequisite'`.

- [ ] **Step 1: Write the failing test**

Create `tests/test-prerequisites.php`:

```php
<?php
/**
 * Prerequisite roles inside the capacity authority (virtual-events spec §4.6).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Registrations;

/**
 * @group prerequisites
 */
class Test_Prerequisites extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		add_role( 'anchor_course_intro', 'Course: Intro', [] );
		add_role( 'anchor_course_safety', 'Course: Safety', [] );
	}

	public function tear_down() {
		remove_role( 'anchor_course_intro' );
		remove_role( 'anchor_course_safety' );
		parent::tear_down();
	}

	private function gated( array $roles, $mode = 'any' ) {
		return $this->make_event( [
			'capacity'             => 0,
			'registration_enabled' => true,
			'required_roles'       => $roles,
			'required_roles_mode'  => $mode,
		] );
	}

	/** No required roles: the decision is bit-identical to before. */
	public function test_no_prerequisite_is_unchanged() {
		$event_id = $this->make_event( [ 'capacity' => 0 ] );
		wp_set_current_user( 0 );
		$this->assertSame( 'open', $this->registrations()->capacity_decision( $event_id, $this->module()->get_meta( $event_id ) ) );
	}

	/** mode=any: holding one of the listed roles is enough. */
	public function test_mode_any() {
		$event_id = $this->gated( [ 'anchor_course_intro', 'anchor_course_safety' ], 'any' );
		$user_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$meta = $this->module()->get_meta( $event_id );

		$this->assertSame( 'prerequisite', $this->registrations()->capacity_decision( $event_id, $meta ) );

		( new WP_User( $user_id ) )->add_role( 'anchor_course_intro' );
		$this->assertSame( 'open', $this->registrations()->capacity_decision( $event_id, $meta ) );
	}

	/** mode=all: every listed role is required. */
	public function test_mode_all() {
		$event_id = $this->gated( [ 'anchor_course_intro', 'anchor_course_safety' ], 'all' );
		$user_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$meta = $this->module()->get_meta( $event_id );

		( new WP_User( $user_id ) )->add_role( 'anchor_course_intro' );
		$this->assertSame( 'prerequisite', $this->registrations()->capacity_decision( $event_id, $meta ) );

		( new WP_User( $user_id ) )->add_role( 'anchor_course_safety' );
		$this->assertSame( 'open', $this->registrations()->capacity_decision( $event_id, $meta ) );
	}

	/** Staff bypass the gate. */
	public function test_staff_bypass() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'open', $this->registrations()->capacity_decision( $event_id, $this->module()->get_meta( $event_id ) ) );
	}

	/** An anonymous visitor is refused with the sign-in wording. */
	public function test_anonymous_is_refused_and_told_to_sign_in() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( 0 );

		$this->assertSame( 'prerequisite', $this->registrations()->capacity_decision( $event_id, $this->module()->get_meta( $event_id ) ) );
		$this->assertStringContainsString(
			'Sign in to check eligibility',
			$this->module()->entitlements->prerequisite_message( $event_id )
		);
	}

	/** The named role appears in the refusal for a signed-in visitor. */
	public function test_message_names_the_roles() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertStringContainsString( 'Course: Intro', $this->module()->entitlements->prerequisite_message( $event_id ) );
	}

	/** bookability() surfaces it and is_bookable() refuses it. */
	public function test_bookability_refuses() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 'prerequisite', $this->module()->bookability( $event_id ) );
		$this->assertFalse( $this->module()->is_bookable( $this->module()->bookability( $event_id ) ) );
	}

	/** JSON-LD keeps publishing an InStock Offer — eligibility is not inventory. */
	public function test_schema_still_publishes_an_offer() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame(
			'https://schema.org/InStock',
			\Anchor\Events\Event_Schema::availability_for( 'prerequisite' )
		);
	}

	/** A gated product is not purchasable. */
	public function test_purchasability_refuses() {
		$this->require_wc();
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		$this->ticket_types()->save( $event_id, [ [ 'label' => 'GA', 'price' => '100', 'active' => 1 ] ] );
		$this->product_sync()->sync_event( $event_id );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$product_id = (int) get_post_meta( $event_id, '_anchor_event_managed_product', true );
		$this->assertGreaterThan( 0, $product_id );
		$this->assertFalse( $this->woocommerce()->filter_is_purchasable( true, wc_get_product( $product_id ) ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Prerequisites
```
Expected: FAIL — `'open'` returned where `'prerequisite'` is expected.

- [ ] **Step 3: Write minimal implementation**

Append to `Entitlements`:

```php
    /* ---------------------------------------------------------------------
     * Prerequisites (spec §4.6)
     * ------------------------------------------------------------------- */

    /**
     * Does the viewer hold the roles this event requires?
     *
     * Staff bypass — the gate exists to stop the public booking a course they
     * are not ready for, not to stop the owner adding somebody by hand.
     *
     * @param int $event_id
     * @param int $user_id  0 = the current user.
     * @return bool True when there is nothing to check.
     */
    public function meets_prerequisites( $event_id, $user_id = 0 ) {
        $meta     = $this->module->get_meta( (int) $event_id );
        $required = \is_array( $meta['required_roles'] ?? null ) ? $meta['required_roles'] : [];
        if ( empty( $required ) ) {
            return true;
        }
        $for_current = ( (int) $user_id === 0 );
        if ( $for_current && Roster::current_user_can_manage() ) {
            return true;
        }
        $user_id = $for_current ? (int) \get_current_user_id() : (int) $user_id;
        $user    = \get_userdata( $user_id );
        if ( ! $user instanceof \WP_User ) {
            return false;
        }
        $held = (array) $user->roles;
        $hits = \count( \array_intersect( $required, $held ) );
        return ( (string) ( $meta['required_roles_mode'] ?? 'any' ) === 'all' )
            ? ( $hits === \count( $required ) )
            : ( $hits > 0 );
    }

    /**
     * The refusal wording for a prerequisite-gated event.
     *
     * An anonymous visitor gets "Sign in to check eligibility" rather than a
     * list of role names they cannot act on.
     *
     * @param int $event_id
     * @return string
     */
    public function prerequisite_message( $event_id ) {
        if ( ! \is_user_logged_in() ) {
            return \__( 'Sign in to check eligibility for this course.', 'anchor-schema' );
        }
        $meta     = $this->module->get_meta( (int) $event_id );
        $required = \is_array( $meta['required_roles'] ?? null ) ? $meta['required_roles'] : [];
        $names    = [];
        $roles    = \wp_roles();
        foreach ( $required as $slug ) {
            $names[] = isset( $roles->role_names[ $slug ] )
                ? \translate_user_role( $roles->role_names[ $slug ] )
                : $slug;
        }
        if ( empty( $names ) ) {
            return '';
        }
        return \sprintf(
            /* translators: %s: comma-separated list of role names. */
            \__( 'This course requires %s.', 'anchor-schema' ),
            \implode( ', ', $names )
        );
    }
```

In `Registrations::capacity_decision()`, insert directly after the past-event branch and before the window check:

```php
        // Prerequisites (spec §4.6). Placed in the single registration
        // authority so the date picker, the CTA, the storefront row and
        // WooCommerce::filter_is_purchasable() refuse together instead of each
        // re-deciding. Guarded on required_roles being non-empty, which is the
        // default, so every existing event's answer is unchanged — and this is
        // the one branch that depends on WHO is asking, so keeping it inert for
        // ungated events keeps the decision cacheable everywhere else.
        if ( ! empty( $meta['required_roles'] ) ) {
            $entitlements = $this->module->entitlements ?? null;
            if ( $entitlements && ! $entitlements->meets_prerequisites( (int) $event_id ) ) {
                return 'prerequisite';
            }
        }
```

In `Module::bookability()`, change the seat-layer branch:

```php
        if ( $seats === 'closed' || $seats === 'full' || $seats === 'prerequisite' ) {
            return $seats;
        }
```

In `WooCommerce::bookability_message()`, add before `case 'parent':`:

```php
            case 'prerequisite':
                $entitlements = $this->module->entitlements ?? null;
                $message      = $entitlements ? $entitlements->prerequisite_message( $this->last_event_id ) : '';
                return $message !== ''
                    ? $message
                    : \__( 'You are not yet eligible to register for this course.', 'anchor-schema' );
```

(`bookability_message()` is called from `ajax_add_to_cart()` with the event in scope; pass the event id through as a third parameter `$event_id = 0` rather than adding state — update the two call sites at class-woocommerce.php:1334 and its sibling to pass `$event_id`, and read `$event_id` instead of `$this->last_event_id`.)

In `Event_Schema::availability_for()`, add before `default:`:

```php
            case 'prerequisite':
                // Eligibility is a policy, not inventory: seats exist, and an
                // anonymous crawler — the only reader whose markup is ever
                // cached — is never the one being refused. Keeping InStock here
                // is what stops the JSON-LD varying by viewer.
                return 'https://schema.org/InStock';
```

In `Event_Schema::omits_offer()`:

```php
        return ! \in_array( (string) $bookability, [ 'open', 'waitlist', 'full', 'disabled', 'prerequisite' ], true );
```

In `Module::render_registration_form()`, in the state switch that renders the non-bookable message, add:

```php
            if ( $state === 'prerequisite' ) {
                $message = $this->entitlements ? $this->entitlements->prerequisite_message( $post_id ) : '';
                return '<div class="anchor-event-registration anchor-event-registration-blocked">'
                    . '<p class="anchor-event-notice">' . esc_html( $message ) . '</p>'
                    . ( \is_user_logged_in() ? '' : '<p><a class="anchor-event-button" href="'
                        . esc_url( \wp_login_url( \get_permalink( $post_id ) ) ) . '">'
                        . esc_html__( 'Sign in', 'anchor-schema' ) . '</a></p>' )
                    . '</div>';
            }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Prerequisites
vendor/bin/phpunit --filter Test_Capacity
vendor/bin/phpunit --filter Test_Storefront_Bookability
vendor/bin/phpunit --filter Test_Schema_Availability
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-registrations.php anchor-events-manager/anchor-events-manager.php anchor-events-manager/class-woocommerce.php anchor-events-manager/class-event-schema.php anchor-events-manager/class-entitlements.php tests/test-prerequisites.php
git commit -m "feat(events): refuse registration on unmet prerequisite roles"
```

---

### Task 11: The room — rewrite endpoint, headers, template, `render_room()`

**Files:**
- Create: `anchor-events-manager/templates/live-event.php`, `anchor-events-manager/assets/room.css`
- Modify: `anchor-events-manager/anchor-events-manager.php` — constructor hooks (~:455), `template_include()` (:6990), plus new methods
- Modify: `anchor-events-manager/class-event-schema.php:592` (`VirtualLocation.url`)
- Test: `tests/test-room.php`

**Interfaces:**
- Produces: `Module::room_url( int $event_id ): string`; `Module::is_room_request(): bool`; `Module::render_room( int $event_id ): string`; `Module::room_state_block( int $event_id, array $state ): string`; `Module::room_headers(): void` (on `template_redirect`); `Module::maybe_flush_rewrites(): void` (on `init`, priority 99).

- [ ] **Step 1: Write the failing test**

Create `tests/test-room.php`:

```php
<?php
/**
 * The /live/ room: routing, headers, render branches, admin column
 * (virtual-events spec §5, §7).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Stream_State;

/**
 * @group room
 */
class Test_Room extends Anchor_Events_TestCase {

	private $minted = [];

	public function tear_down() {
		foreach ( $this->minted as $id ) {
			remove_role( 'anchor_event_' . $id );
		}
		$this->minted = [];
		parent::tear_down();
	}

	private function stream_event() {
		$start    = time() + ( 2 * HOUR_IN_SECONDS );
		$event_id = $this->make_event( [
			'registration_mode' => 'free',
			'timezone'          => 'UTC',
			'start_date'        => gmdate( 'Y-m-d', $start ),
			'start_time'        => gmdate( 'H:i', $start ),
			'start_ts'          => $start,
			'end_ts'            => $start + 3600,
			'stream_default_modality' => 'virtual',
			'stream_embed'      => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/77', 'raw' => '' ],
		] );
		$this->minted[] = $event_id;
		return $event_id;
	}

	public function test_room_url_is_the_live_endpoint() {
		$event_id = $this->stream_event();
		$this->assertSame(
			trailingslashit( get_permalink( $event_id ) ) . 'live/',
			$this->module()->room_url( $event_id )
		);
	}

	/** The `live` endpoint is registered against the event permalink. */
	public function test_live_endpoint_registered() {
		global $wp_rewrite;
		$this->assertArrayHasKey( 'live', $wp_rewrite->endpoints ? array_column( $wp_rewrite->endpoints, 1, 1 ) : [] );
	}

	/** A group parent has no room of its own. */
	public function test_group_parent_has_no_room() {
		$parent_id = $this->make_event( [ 'type' => 'offering', 'registration_mode' => 'free' ] );
		update_post_meta( $parent_id, '_anchor_event_group_role', 'parent' );
		$this->assertSame( '', $this->module()->room_url( $parent_id ) );
	}

	/** Logged out: the sign-in branch, and never the embed. */
	public function test_render_room_logged_out() {
		$event_id = $this->stream_event();
		wp_set_current_user( 0 );

		$html = $this->module()->render_room( $event_id );
		$this->assertStringContainsString( 'anchor-room--locked', $html );
		$this->assertStringContainsString( 'Sign in to join', $html );
		$this->assertStringNotContainsString( 'player.vimeo.com', $html );
	}

	/** Logged in but not entitled: the denial branch and its filter. */
	public function test_render_room_denied_and_filter() {
		$event_id = $this->stream_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$html = $this->module()->render_room( $event_id );
		$this->assertStringContainsString( "isn't registered", $html );

		$custom = function () { return 'Ring the front desk.'; };
		add_filter( 'anchor_events_room_denied_message', $custom );
		$html = $this->module()->render_room( $event_id );
		remove_filter( 'anchor_events_room_denied_message', $custom );

		$this->assertStringContainsString( 'Ring the front desk.', $html );
	}

	/** Entitled, before the window: countdown markup, no embed. */
	public function test_render_room_entitled_countdown_hides_the_embed() {
		$event_id = $this->stream_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'room@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'room@example.test' ] );
		wp_set_current_user( $user_id );

		$html = $this->module()->render_room( $event_id );
		$this->assertStringContainsString( 'anchor-room-state', $html );
		$this->assertStringContainsString( 'data-state="countdown"', $html );
		$this->assertStringContainsString( 'data-target-ts="', $html );
		$this->assertStringContainsString( 'data-server-now="', $html );
		$this->assertStringNotContainsString( 'player.vimeo.com', $html );
	}

	/** Entitled, inside the window: the embed is present. */
	public function test_render_room_live_shows_the_embed() {
		$event_id = $this->stream_event();
		update_post_meta( $event_id, '_anchor_event_start_ts', time() );
		update_post_meta( $event_id, '_anchor_event_end_ts', time() + 3600 );
		$user_id = self::factory()->user->create( [ 'user_email' => 'live@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'live@example.test' ] );
		wp_set_current_user( $user_id );

		$html = $this->module()->render_room( $event_id );
		$this->assertStringContainsString( 'data-state="live"', $html );
		$this->assertStringContainsString( 'https://player.vimeo.com/video/77', $html );
		$this->assertStringContainsString( 'allowfullscreen', $html );
	}

	/** The admin Live column reports the state. */
	public function test_admin_live_column() {
		$event_id = $this->stream_event();
		ob_start();
		$this->module()->render_column( 'anchor_event_live', $event_id );
		$this->assertStringContainsString( 'countdown', strtolower( ob_get_clean() ) );
	}

	/** JSON-LD points VirtualLocation at the room. */
	public function test_schema_virtual_location_is_the_room() {
		$event_id = $this->stream_event();
		update_post_meta( $event_id, '_anchor_event_virtual', 1 );
		$node = $this->module()->event_schema->for_event( $event_id );
		$this->assertSame( $this->module()->room_url( $event_id ), $node['location']['url'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Room
```
Expected: FAIL — `Call to undefined method …::room_url()`.

- [ ] **Step 3: Write minimal implementation**

In `Module::__construct()`, after `\add_action( 'init', [ $this, 'register_meta' ] );`:

```php
        // The room endpoint (spec §5.1): <event permalink>/live/.
        \add_action( 'init', [ $this, 'register_room_endpoint' ] );
        // Rewrite flush on a signature change — the pattern Anchor Locations
        // uses (anchor-locations.php::maybe_flush()), because this module has
        // no activation hook of its own that survives a PUC upgrade.
        \add_action( 'init', [ $this, 'maybe_flush_rewrites' ], 99 );
        \add_action( 'template_redirect', [ $this, 'room_headers' ], 1 );
```

Add to `Module` (next to `template_include()`):

```php
    /** Register the room endpoint. EP_PERMALINK = singular post URLs only. */
    public function register_room_endpoint() {
        \add_rewrite_endpoint( 'live', EP_PERMALINK );
    }

    /**
     * Flush rewrites when the room endpoint's signature changes.
     *
     * Same shape as Anchor_Locations::maybe_flush(): a stored signature, no
     * activation hook (Plugin Update Checker upgrades never fire one), and a
     * non-hard flush so .htaccess is left alone.
     */
    public function maybe_flush_rewrites() {
        $sig = 'live|v1|' . ( $this->get_settings()['event_slug'] ?? '' );
        if ( \get_option( 'anchor_events_rw_sig' ) !== $sig ) {
            $this->register_room_endpoint();
            \flush_rewrite_rules( false );
            \update_option( 'anchor_events_rw_sig', $sig, false );
        }
    }

    /**
     * Is this request the room? Checked on the QUERY VAR's PRESENCE, not its
     * value: an endpoint with no trailing value resolves to '' and
     * get_query_var('live') cannot tell that from "absent".
     *
     * @return bool
     */
    public function is_room_request() {
        global $wp_query;
        return \is_singular( self::CPT )
            && $wp_query instanceof \WP_Query
            && \array_key_exists( 'live', (array) $wp_query->query_vars );
    }

    /**
     * The room URL for an event, or '' when the event can never have one.
     *
     * @param int $event_id
     * @return string
     */
    public function room_url( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $this->stream_capable( $event_id ) ) {
            return '';
        }
        $permalink = (string) \get_permalink( $event_id );
        if ( $permalink === '' ) {
            return '';
        }
        // Plain permalinks have no path to append to.
        if ( \strpos( $permalink, '?' ) !== false ) {
            return \add_query_arg( 'live', '1', $permalink );
        }
        return \trailingslashit( $permalink ) . 'live/';
    }

    /**
     * Private, uncacheable, unindexed — and a parent redirects to itself
     * (its dates each have their own room).
     */
    public function room_headers() {
        if ( ! $this->is_room_request() ) {
            return;
        }
        $event_id = (int) \get_queried_object_id();
        if ( ! $this->stream_capable( $event_id ) ) {
            \wp_safe_redirect( (string) \get_permalink( $event_id ), 302 );
            exit;
        }
        \nocache_headers();
        \header( 'Cache-Control: private, no-store, max-age=0' );
        \header( 'X-Robots-Tag: noindex, nofollow', true );
        \add_action( 'wp_head', static function () {
            echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
        }, 1 );
    }
```

In `template_include()`, before the existing `is_singular` branch:

```php
        if ( $this->is_room_request() ) {
            return $this->locate_template( 'live-event.php' );
        }
```

Add the renderer (next to `render_single_event_body()`):

```php
    /**
     * The room's body. Three branches (spec §5.5): locked-out, denied, entitled.
     *
     * @param int $event_id
     * @return string
     */
    public function render_room( $event_id ) {
        $event_id = (int) $event_id;
        $title    = \get_the_title( $event_id );

        if ( ! \is_user_logged_in() ) {
            \ob_start();
            \wp_login_form( [ 'redirect' => $this->room_url( $event_id ), 'echo' => true ] );
            $form = (string) \ob_get_clean();
            return '<div class="anchor-room anchor-room--locked">'
                . '<h1 class="anchor-room-title">' . \esc_html( $title ) . '</h1>'
                . '<p class="anchor-room-lede">' . \esc_html__( 'Sign in to join', 'anchor-schema' ) . '</p>'
                . $form
                . '<p class="anchor-room-hint">' . \esc_html__( 'Registered but no account? Use the link in your confirmation email.', 'anchor-schema' ) . '</p>'
                . '</div>';
        }

        $state = Stream_State::for_event( $event_id );
        if ( ! $this->entitlements || ! $this->entitlements->can_access_stream( $event_id, (int) $state['session_index'], 0 ) ) {
            $default = \__( "This account isn't registered for this event.", 'anchor-schema' );
            /**
             * The wording shown to a signed-in visitor with no entitlement.
             *
             * @param string $message
             * @param int    $event_id
             */
            $message = (string) \apply_filters( 'anchor_events_room_denied_message', $default, $event_id );
            return '<div class="anchor-room anchor-room--denied">'
                . '<h1 class="anchor-room-title">' . \esc_html( $title ) . '</h1>'
                . '<p class="anchor-room-lede">' . \esc_html( $message ) . '</p>'
                . '<p><a class="anchor-event-button-secondary" href="' . \esc_url( (string) \get_permalink( $event_id ) ) . '">'
                . \esc_html__( 'View the event page', 'anchor-schema' ) . '</a></p>'
                . '<p class="anchor-room-hint">' . \esc_html__( 'Registered under a different email? Contact us.', 'anchor-schema' ) . '</p>'
                . '</div>';
        }

        $staff = '';
        if ( Roster::current_user_can_manage() ) {
            $staff = '<p class="anchor-room-staff">'
                . '<a href="' . \esc_url( \add_query_arg( 'anchor_room_preview', '1', $this->room_url( $event_id ) ) ) . '">'
                . \esc_html__( 'Preview as attendee', 'anchor-schema' ) . '</a> · '
                . '<a href="' . \esc_url( $this->roster->roster_url( $event_id ) ) . '">'
                . \esc_html__( 'Open event console', 'anchor-schema' ) . '</a></p>';
        }

        return '<div class="anchor-room anchor-room--open">'
            . '<h1 class="anchor-room-title">' . \esc_html( $title ) . '</h1>'
            . $this->room_state_block( $event_id, $state )
            . $this->render_room_schedule( $event_id, $state )
            . $staff
            . '</div>';
    }

    /**
     * The one block the REST endpoint re-renders (spec §5.6). Carries the
     * countdown's target and the server clock so room.js can correct for skew.
     *
     * @param int   $event_id
     * @param array $state Stream_State result.
     * @return string
     */
    public function room_state_block( $event_id, array $state ) {
        $copy = [
            Stream_State::UNAVAILABLE => \__( 'This event is in person.', 'anchor-schema' ),
            Stream_State::PENDING     => \__( 'Stream details will appear here before the session.', 'anchor-schema' ),
            Stream_State::COUNTDOWN   => \__( "You're registered. The stream opens in", 'anchor-schema' ),
            Stream_State::LIVE        => \__( 'Live now', 'anchor-schema' ),
            Stream_State::BETWEEN     => \__( 'This session has ended. The next one starts in', 'anchor-schema' ),
            Stream_State::ENDED       => \__( 'This session has ended.', 'anchor-schema' ),
        ];
        $state_key = (string) $state['state'];

        $body = '';
        if ( $state_key === Stream_State::LIVE && ! empty( $state['embed'] ) ) {
            $body = Embed::render( (array) $state['embed'], (string) \get_the_title( $event_id ) );
        } elseif ( \in_array( $state_key, [ Stream_State::COUNTDOWN, Stream_State::BETWEEN ], true ) ) {
            $body = '<p class="anchor-room-countdown" role="timer" aria-live="polite"></p>';
        } elseif ( $state_key === Stream_State::UNAVAILABLE ) {
            $meta = $this->get_meta( $event_id );
            $body = $meta['venue'] !== ''
                ? '<p class="anchor-room-venue">' . \esc_html( $meta['venue'] ) . '</p>'
                : '';
            $body .= '<p><a class="anchor-event-button-secondary" href="' . \esc_url( (string) \get_permalink( $event_id ) ) . '">'
                . \esc_html__( 'View the event page', 'anchor-schema' ) . '</a></p>';
        } elseif ( $state_key === Stream_State::ENDED ) {
            $body = '<p><a class="anchor-event-button-secondary" href="' . \esc_url( (string) \get_permalink( $event_id ) ) . '">'
                . \esc_html__( 'View the event page', 'anchor-schema' ) . '</a></p>';
        }

        return '<div class="anchor-room-state" data-state="' . \esc_attr( $state_key ) . '"'
            . ' data-event-id="' . (int) $event_id . '"'
            . ' data-session-index="' . (int) $state['session_index'] . '"'
            . ' data-target-ts="' . (int) $state['target_ts'] . '"'
            . ' data-server-now="' . (int) \time() . '">'
            . '<p class="anchor-room-status">' . \esc_html( $copy[ $state_key ] ?? '' ) . '</p>'
            . $body
            . '</div>';
    }

    /**
     * The schedule list: each session's label, local time in the event's zone,
     * and a modality badge. The live one is marked.
     *
     * @param int   $event_id
     * @param array $state
     * @return string
     */
    private function render_room_schedule( $event_id, array $state ) {
        $sessions = $this->resolved_sessions( $event_id );
        if ( \count( $sessions ) < 1 ) {
            return '';
        }
        $meta   = $this->get_meta( $event_id );
        $tz     = $this->event_timezone( $meta );
        $badges = [
            'in_person' => \__( 'In person', 'anchor-schema' ),
            'virtual'   => \__( 'Livestream', 'anchor-schema' ),
            'hybrid'    => \__( 'In person + livestream', 'anchor-schema' ),
        ];

        $out = '<ol class="anchor-room-schedule">';
        foreach ( $sessions as $i => $row ) {
            $is_live = ( (string) $state['state'] === Stream_State::LIVE && (int) $state['session_index'] === (int) $i );
            $when    = $row['start_ts']
                ? \wp_date( \get_option( 'date_format' ) . ' ' . \get_option( 'time_format' ), (int) $row['start_ts'], $tz )
                : '';
            $out .= '<li class="anchor-room-session' . ( $is_live ? ' is-live' : '' ) . '">'
                . '<span class="anchor-room-session-label">' . \esc_html( $row['label'] !== '' ? $row['label'] : \get_the_title( $event_id ) ) . '</span> '
                . '<time datetime="' . \esc_attr( \gmdate( 'c', (int) $row['start_ts'] ) ) . '">' . \esc_html( $when ) . '</time> '
                . '<span class="anchor-room-badge anchor-room-badge--' . \esc_attr( $row['modality'] ) . '">'
                . \esc_html( $badges[ $row['modality'] ] ?? '' ) . '</span>'
                . '</li>';
        }
        return $out . '</ol>';
    }
```

Create `anchor-events-manager/templates/live-event.php`:

```php
<?php
/**
 * The room. Theme-overridable at events/live-event.php (or live-event.php)
 * through Module::locate_template(). Deliberately theme-agnostic: header,
 * one <main>, footer, and the body delegated to the module.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$module = \Anchor\Events\Module::instance();
if ( $module ) {
    $module->enqueue_room_assets();
}
?>
<main class="anchor-event-room">
    <?php
    if ( $module ) {
        echo $module->render_room( get_the_ID() );
    }
    ?>
</main>
<?php
get_footer();
```

Create `anchor-events-manager/assets/room.css`:

```css
/* Anchor Events — the /live/ room. */
.anchor-event-room{max-width:1000px;margin:0 auto;padding:24px 16px}
.anchor-room-title{margin:0 0 8px;font-size:1.75rem;line-height:1.2}
.anchor-room-lede{margin:0 0 16px;font-size:1.125rem}
.anchor-room-status{margin:0 0 12px;font-weight:600}
.anchor-room-countdown{margin:0 0 16px;font-size:2rem;font-variant-numeric:tabular-nums;line-height:1}
.anchor-room-player{position:relative;width:100%;padding-top:56.25%;background:#000;border-radius:8px;overflow:hidden}
.anchor-room-player iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
.anchor-room-join{margin:16px 0}
.anchor-room-schedule{list-style:none;margin:24px 0 0;padding:0;display:grid;gap:8px}
.anchor-room-session{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;padding:10px 12px;border:1px solid rgba(0,0,0,.1);border-radius:6px}
.anchor-room-session.is-live{border-color:var(--anchor-event-accent,#0f766e);box-shadow:0 0 0 1px var(--anchor-event-accent,#0f766e)}
.anchor-room-session-label{font-weight:600}
.anchor-room-badge{font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;padding:2px 8px;border-radius:999px;background:rgba(0,0,0,.06)}
.anchor-room-badge--virtual{background:var(--anchor-event-accent,#0f766e);color:var(--anchor-event-accent-fg,#fff)}
.anchor-room-hint,.anchor-room-staff{font-size:.875rem;opacity:.75}
.anchor-room-error{margin:12px 0;padding:10px 12px;border-left:3px solid #b32d2e;background:rgba(179,45,46,.06)}
```

Add to `Module` beside `enqueue_frontend_assets()`:

```php
    /** Room-only assets. Enqueued from templates/live-event.php. */
    public function enqueue_room_assets() {
        $this->enqueue_frontend_assets();
        \wp_enqueue_style(
            'anchor-events-room',
            \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/room.css' ),
            [ 'anchor-events-frontend' ],
            $this->asset_version( 'anchor-events-manager/assets/room.css' )
        );
    }
```

In `Event_Schema::location_fields()`, replace the `VirtualLocation` url line:

```php
        $virtual_node = null;
        if ( $virtual ) {
            // The room is the canonical place to attend (spec §5.2), so that is
            // what the markup names. Robots keep it out of results; schema is
            // allowed to point at it. Falls back to the legacy virtual_url, then
            // the event page, for events with no room.
            $room = $this->module->room_url( $event_id );
            $url  = $room !== '' ? $room
                : ( ! empty( $meta['virtual_url'] ) ? (string) $meta['virtual_url'] : (string) \get_permalink( $event_id ) );
            $virtual_node = [ '@type' => 'VirtualLocation', 'url' => $url ];
        }
```

Add the admin column (in `columns()`, `render_column()`, after the capacity arms):

```php
        $columns['anchor_event_live'] = __( 'Live', 'anchor-schema' );
```
```php
            case 'anchor_event_live':
                if ( ! $this->stream_capable( $post_id ) ) {
                    echo '&mdash;';
                    break;
                }
                $state = Stream_State::for_event( $post_id );
                $label = [
                    Stream_State::UNAVAILABLE => __( 'in person', 'anchor-schema' ),
                    Stream_State::PENDING     => __( 'no stream yet', 'anchor-schema' ),
                    Stream_State::LIVE        => __( 'LIVE', 'anchor-schema' ),
                    Stream_State::ENDED       => __( 'ended', 'anchor-schema' ),
                ][ $state['state'] ] ?? '';
                if ( in_array( $state['state'], [ Stream_State::COUNTDOWN, Stream_State::BETWEEN ], true ) ) {
                    $label = sprintf(
                        /* translators: %s: human time difference, e.g. "3 days". */
                        __( 'countdown in %s', 'anchor-schema' ),
                        human_time_diff( time(), (int) $state['target_ts'] )
                    );
                }
                $room = $this->room_url( $post_id );
                echo $room !== ''
                    ? '<a href="' . esc_url( $room ) . '">' . esc_html( $label ) . '</a>'
                    : esc_html( $label );
                break;
```

- [ ] **Step 4: Run test to verify it passes**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit --filter Test_Room
vendor/bin/phpunit --filter Test_Event_Schema_Emit
vendor/bin/phpunit --filter Test_Locations_Rewrite
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/templates/live-event.php anchor-events-manager/assets/room.css anchor-events-manager/anchor-events-manager.php anchor-events-manager/class-event-schema.php tests/test-room.php
git commit -m "feat(events): add the /live/ room — endpoint, headers, template, render and Live column"
```

---

### Task 12: REST room endpoint and `room.js`

**Files:**
- Create: `anchor-events-manager/assets/room.js`
- Modify: `anchor-events-manager/anchor-events-manager.php` — constructor (`rest_api_init`), new `register_rest_routes()` / `rest_room()`, `enqueue_room_assets()`
- Test: `tests/test-room.php`, `tests/test-assets.php`

**Interfaces:**
- Produces: `GET /wp-json/anchor-events/v1/events/{id}/room` → `{ state, session_index, target_ts, server_now, html }`; 401 `rest_forbidden` logged out, 403 `anchor_events_room_denied` not entitled. `Module::register_rest_routes(): void`, `Module::rest_room( \WP_REST_Request $r )`. JS global `ANCHOR_EVENTS_ROOM` = `{ restUrl, nonce, eventId, pollSeconds: 300 }`.

- [ ] **Step 1: Write the failing test** — append to `tests/test-room.php`:

```php
	private function room_request( $event_id ) {
		$req = new WP_REST_Request( 'GET', '/anchor-events/v1/events/' . $event_id . '/room' );
		return rest_get_server()->dispatch( $req );
	}

	public function test_rest_401_logged_out() {
		do_action( 'rest_api_init' );
		$event_id = $this->stream_event();
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->room_request( $event_id )->get_status() );
	}

	public function test_rest_403_not_entitled() {
		do_action( 'rest_api_init' );
		$event_id = $this->stream_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$res = $this->room_request( $event_id );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'anchor_events_room_denied', $res->as_error()->get_error_code() );
	}

	public function test_rest_omits_the_embed_outside_the_window_and_includes_it_inside() {
		do_action( 'rest_api_init' );
		$event_id = $this->stream_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'rest@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'rest@example.test' ] );
		wp_set_current_user( $user_id );

		$out = $this->room_request( $event_id )->get_data();
		$this->assertSame( 'countdown', $out['state'] );
		$this->assertStringNotContainsString( 'player.vimeo.com', $out['html'] );

		update_post_meta( $event_id, '_anchor_event_start_ts', time() );
		update_post_meta( $event_id, '_anchor_event_end_ts', time() + 3600 );
		$out = $this->room_request( $event_id )->get_data();
		$this->assertSame( 'live', $out['state'] );
		$this->assertStringContainsString( 'player.vimeo.com/video/77', $out['html'] );
	}
```

And to `tests/test-assets.php`:

```php
	/** room.js is source, jQuery-IIFE, and ships no ES module syntax. */
	public function test_room_js_is_source_jquery() {
		$path = dirname( __DIR__ ) . '/anchor-events-manager/assets/room.js';
		$this->assertFileExists( $path );
		$src = file_get_contents( $path );
		$this->assertStringContainsString( '(jQuery)', $src );
		$this->assertStringNotContainsString( 'export ', $src );
		$this->assertStringNotContainsString( 'import ', $src );
	}
```

- [ ] **Step 2: Run** `vendor/bin/phpunit --filter Test_Room` → FAIL (404 `rest_no_route`).

- [ ] **Step 3: Implement.** Constructor: `\add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );`. Then:

```php
    const REST_NS = 'anchor-events/v1';

    public function register_rest_routes() {
        \register_rest_route( self::REST_NS, '/events/(?P<id>\d+)/room', [
            'methods'  => 'GET',
            'args'     => [ 'id' => [ 'required' => true, 'validate_callback' => static function ( $v ) {
                return \is_numeric( $v ) && (int) $v > 0;
            } ] ],
            // Cookie auth only. The room is per-person, so an anonymous or
            // application-password caller has no business here.
            'permission_callback' => static function () {
                return \is_user_logged_in()
                    ? true
                    : new \WP_Error( 'rest_forbidden', \__( 'Sign in to join.', 'anchor-schema' ), [ 'status' => 401 ] );
            },
            'callback' => [ $this, 'rest_room' ],
        ] );
    }

    /**
     * The room's state block, re-decided server-side. The embed is in the
     * response ONLY when the window is open — that is the whole reason this
     * endpoint exists rather than shipping the URL in the page.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_room( $request ) {
        $event_id = (int) $request['id'];
        if ( \get_post_type( $event_id ) !== self::CPT || ! $this->stream_capable( $event_id ) ) {
            return new \WP_Error( 'anchor_events_room_missing', \__( 'No room for that event.', 'anchor-schema' ), [ 'status' => 404 ] );
        }
        $state = Stream_State::for_event( $event_id );
        if ( ! $this->entitlements || ! $this->entitlements->can_access_stream( $event_id, (int) $state['session_index'], 0 ) ) {
            return new \WP_Error( 'anchor_events_room_denied', \__( 'This account is not registered for this event.', 'anchor-schema' ), [ 'status' => 403 ] );
        }
        $response = new \WP_REST_Response( [
            'state'         => (string) $state['state'],
            'session_index' => (int) $state['session_index'],
            'target_ts'     => (int) $state['target_ts'],
            'server_now'    => \time(),
            'html'          => $this->room_state_block( $event_id, $state ),
        ] );
        $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
        return $response;
    }
```

In `enqueue_room_assets()`, after the style:

```php
        \wp_enqueue_script(
            'anchor-events-room',
            \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/room.js' ),
            [ 'jquery' ],
            $this->asset_version( 'anchor-events-manager/assets/room.js' ),
            true
        );
        \wp_localize_script( 'anchor-events-room', 'ANCHOR_EVENTS_ROOM', [
            'restUrl'     => \rest_url( self::REST_NS . '/events/' ),
            'nonce'       => \wp_create_nonce( 'wp_rest' ),
            'eventId'     => (int) \get_the_ID(),
            'pollSeconds' => 300,
            'i18n'        => [
                'refresh' => \__( 'Something went wrong. Refresh the page.', 'anchor-schema' ),
                'now'     => \__( 'now', 'anchor-schema' ),
            ],
        ] );
```

Create `anchor-events-manager/assets/room.js`:

```js
/* Anchor Events — the /live/ room countdown and state refresh. */
(function ($) {
  'use strict';

  var cfg = window.ANCHOR_EVENTS_ROOM || {};
  var timer = null;
  var poll = null;
  var skew = 0; // serverNow - clientNow, in seconds.

  function $block() {
    return $('.anchor-room-state').first();
  }

  function now() {
    return Math.floor(Date.now() / 1000) + skew;
  }

  function readSkew($el) {
    var serverNow = parseInt($el.attr('data-server-now'), 10);
    if (serverNow) {
      skew = serverNow - Math.floor(Date.now() / 1000);
    }
  }

  function format(seconds) {
    if (seconds <= 0) { return cfg.i18n ? cfg.i18n.now : 'now'; }
    var d = Math.floor(seconds / 86400);
    var h = Math.floor((seconds % 86400) / 3600);
    var m = Math.floor((seconds % 3600) / 60);
    var s = seconds % 60;
    var pad = function (n) { return n < 10 ? '0' + n : String(n); };
    return (d > 0 ? d + 'd ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s);
  }

  function showError() {
    var $el = $block();
    if (!$el.length || $el.find('.anchor-room-error').length) { return; }
    $el.append(
      $('<p class="anchor-room-error" role="status" aria-live="polite"></p>')
        .text(cfg.i18n ? cfg.i18n.refresh : 'Refresh the page.')
    );
  }

  function refresh() {
    if (!cfg.restUrl || !cfg.eventId) { return; }
    $.ajax({
      url: cfg.restUrl + cfg.eventId + '/room',
      method: 'GET',
      dataType: 'json',
      beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', cfg.nonce); }
    }).done(function (res) {
      if (!res || typeof res.html !== 'string') { showError(); return; }
      $block().replaceWith(res.html);
      start();
    }).fail(showError);
  }

  function tick() {
    var $el = $block();
    if (!$el.length) { return; }
    var target = parseInt($el.attr('data-target-ts'), 10) || 0;
    var remaining = target - now();
    var $out = $el.find('.anchor-room-countdown');
    if ($out.length) { $out.text(format(remaining)); }
    if (target && remaining <= 0) {
      window.clearInterval(timer);
      timer = null;
      refresh();
    }
  }

  function start() {
    var $el = $block();
    if (!$el.length) { return; }
    readSkew($el);

    if (timer) { window.clearInterval(timer); timer = null; }
    if (poll) { window.clearInterval(poll); poll = null; }

    var state = $el.attr('data-state');
    if (state === 'countdown' || state === 'between') {
      tick();
      timer = window.setInterval(tick, 1000);
    }
    if (state === 'live') {
      // Catch `ended` / `between` without a reload (spec §5.6).
      poll = window.setInterval(refresh, (cfg.pollSeconds || 300) * 1000);
    }
  }

  $(start);
})(jQuery);
```

- [ ] **Step 4: Run** `vendor/bin/phpunit --filter Test_Room` and `--filter Test_Assets` → PASS.
- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/assets/room.js anchor-events-manager/anchor-events-manager.php tests/test-room.php tests/test-assets.php
git commit -m "feat(events): add the room REST endpoint and room.js countdown/refresh"
```

---

### Task 13: Stateless one-click sign-in token

**Files:** Modify `class-entitlements.php` (token methods), `anchor-events-manager.php` (`room_headers()` gains the `aek` branch). Test: `tests/test-login-token.php`.

**Interfaces:** `Entitlements::login_token( int $user_id, int $event_id ): string`; `Entitlements::verify_login_token( string $token, int $event_id ): int` (0 = invalid); `Entitlements::room_url_for( int $user_id, int $event_id ): string`; `Entitlements::token_expiry( int $event_id ): int`.

- [ ] **Step 1: Failing test** — `tests/test-login-token.php`:

```php
<?php
/**
 * Stateless room sign-in tokens (virtual-events spec §6.2).
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group login-token
 */
class Test_Login_Token extends Anchor_Events_TestCase {

	private function event() {
		return $this->make_event( [
			'registration_mode' => 'free',
			'timezone'          => 'UTC',
			'start_ts'          => time() + DAY_IN_SECONDS,
			'end_ts'            => time() + DAY_IN_SECONDS + 3600,
			'stream_default_modality' => 'virtual',
			'stream_embed' => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/3', 'raw' => '' ],
		] );
	}

	public function test_valid_token_verifies() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$ent      = $this->module()->entitlements;

		$this->assertSame( $user_id, $ent->verify_login_token( $ent->login_token( $user_id, $event_id ), $event_id ) );
	}

	public function test_room_url_for_carries_the_token() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$url      = $this->module()->entitlements->room_url_for( $user_id, $event_id );

		$this->assertStringContainsString( '/live/?aek=', $url );
	}

	public function test_expiry_is_last_session_end_plus_seven_days() {
		$event_id = $this->event();
		$this->assertSame(
			(int) get_post_meta( $event_id, '_anchor_event_end_ts', true ) + ( 7 * DAY_IN_SECONDS ),
			$this->module()->entitlements->token_expiry( $event_id )
		);
	}

	public function test_tampered_token_is_rejected() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$ent      = $this->module()->entitlements;
		$token    = $ent->login_token( $user_id, $event_id );

		$this->assertSame( 0, $ent->verify_login_token( $token . 'x', $event_id ) );
		$this->assertSame( 0, $ent->verify_login_token( $token, $event_id + 1 ), 'A token for one event must not open another.' );
	}

	public function test_expired_token_is_rejected() {
		$event_id = $this->event();
		update_post_meta( $event_id, '_anchor_event_end_ts', time() - ( 30 * DAY_IN_SECONDS ) );
		$user_id = self::factory()->user->create();
		$ent     = $this->module()->entitlements;

		$this->assertSame( 0, $ent->verify_login_token( $ent->login_token( $user_id, $event_id ), $event_id ) );
	}

	public function test_password_change_invalidates_the_token() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$ent      = $this->module()->entitlements;
		$token    = $ent->login_token( $user_id, $event_id );

		wp_set_password( 'a-brand-new-password', $user_id );
		clean_user_cache( $user_id );

		$this->assertSame( 0, $ent->verify_login_token( $token, $event_id ) );
	}
}
```

- [ ] **Step 2: Run** `vendor/bin/phpunit --filter Test_Login_Token` → FAIL (`login_token()` undefined).

- [ ] **Step 3: Implement.** Append to `Entitlements`:

```php
    /* ---------------------------------------------------------------------
     * One-click sign-in (spec §6.2)
     * ------------------------------------------------------------------- */

    /** The query arg the room reads. */
    const TOKEN_ARG = 'aek';

    /**
     * When a token for this event stops working: the last session's end plus a
     * week. Stored nowhere — the expiry is INSIDE the signed payload.
     *
     * @param int $event_id
     * @return int
     */
    public function token_expiry( $event_id ) {
        $sessions = $this->module->resolved_sessions( (int) $event_id );
        $last     = 0;
        foreach ( $sessions as $row ) {
            $last = \max( $last, (int) $row['end_ts'], (int) $row['start_ts'] );
        }
        if ( $last <= 0 ) {
            $last = \time();
        }
        return $last + ( 7 * \DAY_IN_SECONDS );
    }

    /**
     * Mint a stateless sign-in token.
     *
     * HMAC over user|event|expiry|password-fragment, with wp_hash()'s
     * site-secret key. Stored nowhere: nothing to leak, nothing to clean up,
     * and changing the password invalidates every outstanding token because
     * the fragment is part of what is signed.
     *
     * @param int $user_id
     * @param int $event_id
     * @return string '' when the user does not exist.
     */
    public function login_token( $user_id, $event_id ) {
        $user = \get_userdata( (int) $user_id );
        if ( ! $user instanceof \WP_User ) {
            return '';
        }
        $expiry = $this->token_expiry( $event_id );
        return (int) $user_id . '.' . $expiry . '.' . $this->token_signature( (int) $user_id, (int) $event_id, $expiry, $user->user_pass );
    }

    /**
     * Verify a token for an event.
     *
     * @param string $token
     * @param int    $event_id
     * @return int User id, or 0.
     */
    public function verify_login_token( $token, $event_id ) {
        $parts = \explode( '.', (string) $token );
        if ( \count( $parts ) !== 3 ) {
            return 0;
        }
        [ $user_id, $expiry, $signature ] = $parts;
        $user_id = (int) $user_id;
        $expiry  = (int) $expiry;
        if ( $user_id <= 0 || $expiry <= \time() ) {
            return 0;
        }
        $user = \get_userdata( $user_id );
        if ( ! $user instanceof \WP_User ) {
            return 0;
        }
        $expected = $this->token_signature( $user_id, (int) $event_id, $expiry, $user->user_pass );
        return \hash_equals( $expected, (string) $signature ) ? $user_id : 0;
    }

    /** The room URL carrying a per-recipient sign-in token. */
    public function room_url_for( $user_id, $event_id ) {
        $room = $this->module->room_url( (int) $event_id );
        if ( $room === '' ) {
            return '';
        }
        $token = $this->login_token( (int) $user_id, (int) $event_id );
        return $token === '' ? $room : \add_query_arg( self::TOKEN_ARG, \rawurlencode( $token ), $room );
    }

    /** The signed half. Only the first 12 chars of the stored hash are used. */
    private function token_signature( $user_id, $event_id, $expiry, $user_pass ) {
        return \wp_hash( $user_id . '|' . $event_id . '|' . $expiry . '|' . \substr( (string) $user_pass, 8, 12 ), 'auth' );
    }
```

In `Module::room_headers()`, immediately after the `stream_capable` redirect guard:

```php
        // One-click sign-in (spec §6.2). Only for a LOGGED-OUT visitor — a
        // token must never silently switch an already-signed-in account — and
        // the query arg is stripped on the redirect so it never reaches the
        // browser history, a referrer header, or an analytics hit.
        if ( ! \is_user_logged_in() && ! empty( $_GET[ Entitlements::TOKEN_ARG ] ) && $this->entitlements ) {
            $token   = \sanitize_text_field( \wp_unslash( $_GET[ Entitlements::TOKEN_ARG ] ) );
            $user_id = $this->entitlements->verify_login_token( $token, $event_id );
            if ( $user_id > 0 ) {
                \wp_set_current_user( $user_id );
                \wp_set_auth_cookie( $user_id, false );
                \wp_safe_redirect( $this->room_url( $event_id ), 302 );
                exit;
            }
            // Invalid or expired: fall through to the sign-in form with a notice.
            \add_filter( 'anchor_events_room_denied_message', static function ( $message ) {
                return \__( 'That sign-in link has expired. Sign in below, or ask us for a new link.', 'anchor-schema' );
            } );
        }
```

- [ ] **Step 4: Run** `vendor/bin/phpunit --filter Test_Login_Token` and `--filter Test_Room` → PASS.
- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-entitlements.php anchor-events-manager/anchor-events-manager.php tests/test-login-token.php
git commit -m "feat(events): stateless one-click room sign-in tokens"
```

---

### Task 14: `{room_link}` token and the CTA default order

**Files:** Modify `anchor-events-manager.php` — `email_tokens()` (:13572), `build_registration_email_html()` token map (~:14430), `wording_email_tokens()`/`template_email_tokens()` (:4136), `preview_sample_scalars()` (:4251), `default_email_cta()` (:4475), the scalar allow-list at ~:14478, the settings help text at :10388/:10420. Test: `tests/test-email-templates.php`.

**Interfaces:** new scalar token `room_link`; `default_email_cta()` gains a stream branch above the virtual one.

- [ ] **Step 1: Failing test** — append to `tests/test-email-templates.php`:

```php
	/** {room_link} resolves per recipient and carries their own token. */
	public function test_room_link_token_is_per_recipient() {
		$event_id = $this->make_event( [
			'registration_mode' => 'free',
			'timezone'          => 'UTC',
			'start_ts'          => time() + DAY_IN_SECONDS,
			'end_ts'            => time() + DAY_IN_SECONDS + 3600,
			'stream_default_modality' => 'virtual',
			'stream_embed' => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/8', 'raw' => '' ],
		] );
		$a = self::factory()->user->create( [ 'user_email' => 'one@example.test' ] );
		$b = self::factory()->user->create( [ 'user_email' => 'two@example.test' ] );
		$seat_a = $this->registrations()->get_seat( $this->make_seat( $event_id, [ 'email' => 'one@example.test' ] ) );
		$seat_b = $this->registrations()->get_seat( $this->make_seat( $event_id, [ 'email' => 'two@example.test', 'seat_index' => 2 ] ) );

		$link_a = $this->module()->email_tokens( [ 'event_id' => $event_id, 'seat' => $seat_a ] )['room_link'];
		$link_b = $this->module()->email_tokens( [ 'event_id' => $event_id, 'seat' => $seat_b ] )['room_link'];

		$this->assertStringContainsString( 'aek=', $link_a );
		$this->assertNotSame( $link_a, $link_b, 'Each recipient gets their own token.' );
		remove_role( 'anchor_event_' . $event_id );
	}

	/** A waitlist seat never gets a room link. */
	public function test_waitlist_seat_gets_no_room_link() {
		$event_id = $this->make_event( [
			'registration_mode' => 'free',
			'stream_default_modality' => 'virtual',
			'stream_embed' => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/8', 'raw' => '' ],
		] );
		$seat = [ 'id' => 0, 'name' => 'W', 'email' => 'w@example.test', 'status' => 'waitlist' ];
		$this->assertSame( '', $this->module()->email_tokens( [ 'event_id' => $event_id, 'seat' => $seat ] )['room_link'] );
	}

	/** CTA default order: stream > virtual > event page. */
	public function test_default_cta_order() {
		$page_only = $this->make_event();
		$this->assertSame( 'View event details', $this->module()->default_email_cta( $page_only )['label'] );

		$virtual = $this->make_event( [ 'virtual' => true, 'virtual_url' => 'https://zoom.example/j/1' ] );
		$this->assertSame( 'Join the event', $this->module()->default_email_cta( $virtual )['label'] );

		$stream = $this->make_event( [
			'registration_mode' => 'free',
			'virtual' => true,
			'virtual_url' => 'https://zoom.example/j/1',
			'stream_default_modality' => 'virtual',
			'stream_embed' => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/8', 'raw' => '' ],
		] );
		$cta = $this->module()->default_email_cta( $stream );
		$this->assertSame( 'Join the livestream', $cta['label'] );
		$this->assertSame( '{room_link}', $cta['url'] );
	}
```

- [ ] **Step 2: Run** `vendor/bin/phpunit --filter Test_Email_Templates` → FAIL (`room_link` key missing).

- [ ] **Step 3: Implement.**

`default_email_cta()` — insert the new branch **above** the virtual one, and make `default_email_cta()` public (it is called by the test and by the CTA resolver already in-class):

```php
    public function default_email_cta( $event_id, array $fallback = [] ) {
        $meta = $event_id ? $this->get_meta( (int) $event_id ) : [];

        // Stream first (spec §6.2): a hosted room beats a raw provider link,
        // because the room is where identity, the countdown and the close-out
        // all live. The URL is the {room_link} TOKEN, not a resolved link —
        // the CTA is rendered once per recipient and the token expands there.
        if ( $event_id && $this->stream_capable( (int) $event_id ) && ! empty( $meta['stream_embed']['src'] ) ) {
            return [ 'label' => __( 'Join the livestream', 'anchor-schema' ), 'url' => '{room_link}' ];
        }
        if ( ! empty( $meta['virtual'] ) && ! empty( $meta['virtual_url'] ) ) {
            return [ 'label' => __( 'Join the event', 'anchor-schema' ), 'url' => (string) $meta['virtual_url'] ];
        }
        return [
            'label' => (string) ( $fallback['label'] ?? __( 'View event details', 'anchor-schema' ) ),
            'url'   => (string) ( $fallback['url'] ?? ( $event_id ? \get_permalink( $event_id ) : \home_url() ) ),
        ];
    }
```

`email_tokens()` — after the `$join` block:

```php
        // The room, tokenised for THIS recipient (spec §6.2). Confirmed seats
        // only, exactly like {join_link}: a waitlisted person has no seat to
        // sign in for. {join_link} keeps meaning the raw provider URL so legacy
        // templates are untouched.
        $room_link = '';
        if ( $event_id && $this->entitlements && ( ! $seat || ( $seat['status'] ?? '' ) === Registrations::STATUS_CONFIRMED ) ) {
            $seat_user = (int) ( $seat['user_id'] ?? 0 );
            if ( $seat_user <= 0 && ! empty( $seat['id'] ) ) {
                $seat_user = $this->entitlements->ensure_user( $seat );
            }
            if ( $seat_user > 0 ) {
                $room_link = $this->entitlements->room_url_for( $seat_user, $event_id );
            }
        }
```
…and `'room_link' => $room_link,` into the returned array beside `'join_link'`.

`build_registration_email_html()` — after the `$join_url` block:

```php
        $room_link = '';
        if ( $event_id && $status === Registrations::STATUS_CONFIRMED && $this->entitlements ) {
            $seat_id = (int) ( $ctx['seat_id'] ?? 0 );
            $user_id = $seat_id > 0 ? $this->entitlements->ensure_user( [ 'id' => $seat_id ] ) : 0;
            if ( $user_id > 0 ) {
                $room_link = $this->entitlements->room_url_for( $user_id, $event_id );
            }
        }
```
…add `'room_link' => esc_url( $room_link ),` to the `$tokens` map beside `'join_link'`, add `'room_link'` to the `$scalars` allow-list (~:14478) and to the preview's URL-escaped key list (~:14470), add `'seat_id' => 0,` to the `wp_parse_args()` defaults, and add `'room_link' => \home_url( '/sample-event/live/?aek=sample' ),` to `preview_sample_scalars()`.

Add `'room_link'` to `wording_email_tokens()` so the palette and the two settings help strings (:10388, :10420) list it. Pass `'seat_id' => (int) $seat_id` from `send_registration_emails()`, `send_reminder_email()` and `WooCommerce::send_customer_email()` into their `$ctx`.

- [ ] **Step 4: Run** `vendor/bin/phpunit --filter Test_Email_Templates`, `--filter Test_Email_Builder`, `--filter Test_Reminders` → PASS.
- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php tests/test-email-templates.php
git commit -m "feat(events): add the {room_link} email token and the stream-first CTA default"
```

---

### Task 15: Tier modality and the managed variation

**Files:** Modify `class-ticket-types.php` (`normalize()` :296, `save()` :102, `implicit_primary()`), `class-product-sync.php` (`$specs[]` build ~:660, `write_variation()` :813), `class-woocommerce.php` (attendee-capture label). Tests: `tests/test-ticket-types.php`, `tests/test-product-sync.php`.

**Interfaces:** tier key `modality` (`in_person|virtual`, default `in_person`); `Product_Sync::VARIATION_MODALITY_META = '_anchor_evt_modality'`; `Ticket_Types::modality_label( string $modality ): string`.

- [ ] **Step 1: Failing test** — `tests/test-ticket-types.php`:

```php
	/** Every tier carries a modality; a missing or bad one is in_person. */
	public function test_tier_modality_defaults_and_clamps() {
		$event_id = $this->make_event();
		$tiers    = $this->ticket_types()->save( $event_id, [
			[ 'label' => 'GA', 'price' => '0', 'active' => 1 ],
			[ 'label' => 'Stream', 'price' => '0', 'active' => 1, 'modality' => 'virtual' ],
			[ 'label' => 'Odd', 'price' => '0', 'active' => 1, 'modality' => 'hybrid' ],
		] );

		$this->assertSame( 'in_person', $tiers[0]['modality'], 'A tier with no modality keeps the previous meaning.' );
		$this->assertSame( 'virtual', $tiers[1]['modality'] );
		$this->assertSame( 'in_person', $tiers[2]['modality'], 'A tier is never hybrid — that is an event-level statement.' );
	}

	/** The implicit primary tier is in_person. */
	public function test_implicit_primary_modality() {
		$event_id = $this->make_event( [ 'price' => '50' ] );
		$this->assertSame( 'in_person', $this->ticket_types()->get( $event_id )[0]['modality'] );
	}

	public function test_modality_labels() {
		$this->assertSame( 'In-person', \Anchor\Events\Ticket_Types::modality_label( 'in_person' ) );
		$this->assertSame( 'Livestream', \Anchor\Events\Ticket_Types::modality_label( 'virtual' ) );
	}
```

`tests/test-product-sync.php`:

```php
	/** The managed variation carries the tier's modality. */
	public function test_variation_carries_modality() {
		$this->require_wc();
		$event_id = $this->make_event();
		$tiers    = $this->ticket_types()->save( $event_id, [
			[ 'label' => 'Livestream', 'price' => '99', 'active' => 1, 'modality' => 'virtual' ],
		] );
		$this->product_sync()->sync_event( $event_id );

		$variation_id = $this->product_sync()->variation_for_tier( $event_id, $tiers[0]['id'] );
		$this->assertGreaterThan( 0, $variation_id );
		$this->assertSame( 'virtual', wc_get_product( $variation_id )->get_meta( '_anchor_evt_modality' ) );
	}
```

- [ ] **Step 2: Run** `--filter Test_Ticket_Types` and `--filter Test_Product_Sync` → FAIL.

- [ ] **Step 3: Implement.** In `Ticket_Types::normalize()`'s returned array, after `'active'`:

```php
            // A tier is a PRICE, so it is one way in or the other — never
            // `hybrid`, which is an event-level statement ("both exist"). An
            // absent value is `in_person`: the meaning every pre-upgrade tier
            // already had (spec §3.3).
            'modality'        => ( ( $row['modality'] ?? '' ) === 'virtual' ) ? 'virtual' : 'in_person',
```
The same line goes into `implicit_primary()` and into `save()`'s per-row `$clean[]` build (reading `$row['modality']`). Add:

```php
    /**
     * The shopper-facing name for a tier modality — used on the attendee
     * capture block, the storefront row and the order line item.
     *
     * @param string $modality
     * @return string
     */
    public static function modality_label( $modality ) {
        return ( (string) $modality === 'virtual' )
            ? \__( 'Livestream', 'anchor-schema' )
            : \__( 'In-person', 'anchor-schema' );
    }
```

In `Product_Sync`: add `const VARIATION_MODALITY_META = '_anchor_evt_modality';`, add `'modality' => (string) $tier['modality'],` to the `$specs[]` entry for paid+active tiers, and in `write_variation()` after the tier-id meta block:

```php
        // Modality, so the order line and the attendee capture can say
        // "Livestream ticket" without re-reading the event's tiers.
        if ( (string) $variation->get_meta( self::VARIATION_MODALITY_META ) !== (string) ( $spec['modality'] ?? 'in_person' ) ) {
            $variation->update_meta_data( self::VARIATION_MODALITY_META, (string) ( $spec['modality'] ?? 'in_person' ) );
            $dirty = true;
        }
```

In `WooCommerce`'s attendee-capture block renderer, append the tier's modality to each ticket heading via `Ticket_Types::modality_label( $tier['modality'] ?? 'in_person' )`, and add the same as an order item meta line (`\__( 'Attendance', 'anchor-schema' )`) where the tier label is already written.

- [ ] **Step 4: Run** `--filter Test_Ticket_Types`, `--filter Test_Product_Sync`, `--filter Test_Woocommerce_Checkout_Block` → PASS.
- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-ticket-types.php anchor-events-manager/class-product-sync.php anchor-events-manager/class-woocommerce.php tests/test-ticket-types.php tests/test-product-sync.php
git commit -m "feat(events): per-tier modality on tiers, variations and the attendee capture"
```

---

### Task 16: Admin metabox — Livestream group, session fields, tier select, Access section

**Files:** Modify `anchor-events-manager.php` — `render_meta_box()` Location section (~:3683, after the virtual-URL field), `event_session_row_html()` (:3059), `render_ticket_types_fields()` row template (~:3000), Registration section (~:3720); add `render_livestream_fields()` and `render_access_fields()`. Test: `tests/test-event-save.php`.

**Interfaces:** `Module::render_livestream_fields( int $event_id, array $meta, bool $admin ): string`; `Module::render_access_fields( int $event_id, array $meta, bool $admin ): string`; `Module::prerequisite_role_choices(): array<string,array<string,string>>` (grouped: Site roles / Events / Courses).

- [ ] **Step 1: Failing test:**

```php
	/** The metabox renders the Livestream group and the Access picker. */
	public function test_metabox_renders_livestream_and_access() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$meta     = $this->module()->get_meta( $event_id );

		$html = $this->module()->render_livestream_fields( $event_id, $meta, true );
		$this->assertStringContainsString( 'name="anchor_event_stream_embed"', $html );
		$this->assertStringContainsString( 'name="anchor_event_stream_default_modality"', $html );
		$this->assertStringContainsString( 'name="anchor_event_in_person_includes_stream"', $html );
		$this->assertStringContainsString( 'name="anchor_event_stream_open_before_minutes"', $html );
		$this->assertStringContainsString( 'name="anchor_event_stream_close_after_minutes"', $html );
		$this->assertStringContainsString( $this->module()->room_url( $event_id ), $html );

		$access = $this->module()->render_access_fields( $event_id, $meta, true );
		$this->assertStringContainsString( 'name="anchor_event_required_roles[]"', $access );
		$this->assertStringContainsString( 'name="anchor_event_required_roles_mode"', $access );
	}

	/** An external event gets no Livestream group at all. */
	public function test_external_event_has_no_livestream_group() {
		$event_id = $this->make_event( [ 'registration_mode' => 'external' ] );
		$this->assertSame( '', $this->module()->render_livestream_fields( $event_id, $this->module()->get_meta( $event_id ), true ) );
	}

	/** The session row carries a modality select and a stream override. */
	public function test_session_row_has_modality_and_override() {
		$html = $this->module()->event_session_row_html_public( 0, null, true );
		$this->assertStringContainsString( 'anchor_event_sessions[__INDEX__][modality]', $html );
		$this->assertStringContainsString( 'anchor_event_sessions[__INDEX__][stream_embed]', $html );
	}
```
(Add a thin public wrapper `event_session_row_html_public()` that forwards to the private renderer, so the test does not reach into private state.)

- [ ] **Step 2: Run** `--filter Test_Event_Save` → FAIL.

- [ ] **Step 3: Implement.**

```php
    /**
     * The Livestream group, shared by the wp-admin Location section and the
     * front-end console (spec §7). One renderer, so the two surfaces cannot
     * drift on field names — the same pattern render_ticket_types_fields()
     * already uses.
     *
     * @param int   $event_id
     * @param array $meta
     * @param bool  $admin True for the metabox styling, false for the console.
     * @return string '' when the event can never hold a stream.
     */
    public function render_livestream_fields( $event_id, array $meta, $admin = true ) {
        if ( $this->registration_mode( (int) $event_id ) === 'external' ) {
            return '';
        }
        $hint  = $admin ? 'description' : 'anchor-event-hint';
        $embed = \is_array( $meta['stream_embed'] ?? null ) ? $meta['stream_embed'] : [];
        $raw   = (string) ( $embed['raw'] ?? ( $embed['src'] ?? '' ) );
        $room  = $this->room_url( (int) $event_id );

        \ob_start();
        ?>
        <div class="anchor-event-section anchor-event-livestream" data-step="3">
            <h3><?php echo esc_html__( 'Livestream', 'anchor-schema' ); ?></h3>
            <div class="anchor-event-grid">
                <div class="anchor-event-field" style="grid-column:1/-1;">
                    <label for="anchor_event_stream_embed"><?php echo esc_html__( 'Stream link or embed code', 'anchor-schema' ); ?></label>
                    <textarea id="anchor_event_stream_embed" name="anchor_event_stream_embed" rows="3" class="widefat"><?php echo esc_textarea( $raw ); ?></textarea>
                    <p class="<?php echo esc_attr( $hint ); ?>"><?php echo esc_html__( 'Paste a Vimeo, YouTube or Zoom link, or the provider\'s iframe embed code. Vimeo domain privacy is set on Vimeo — allow this site\'s domain there.', 'anchor-schema' ); ?></p>
                </div>
                <div class="anchor-event-field">
                    <label for="anchor_event_stream_default_modality"><?php echo esc_html__( 'Default attendance', 'anchor-schema' ); ?></label>
                    <select id="anchor_event_stream_default_modality" name="anchor_event_stream_default_modality">
                        <?php foreach ( [
                            'in_person' => __( 'In person', 'anchor-schema' ),
                            'virtual'   => __( 'Livestream only', 'anchor-schema' ),
                            'hybrid'    => __( 'In person + livestream', 'anchor-schema' ),
                        ] as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $meta['stream_default_modality'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="anchor-event-field anchor-event-field--check">
                    <label>
                        <input type="checkbox" id="anchor_event_in_person_includes_stream" name="anchor_event_in_person_includes_stream" value="1" <?php checked( $meta['in_person_includes_stream'] ); ?> />
                        <?php echo esc_html__( 'In-person registrants also get the stream', 'anchor-schema' ); ?>
                    </label>
                </div>
                <div class="anchor-event-field">
                    <label for="anchor_event_stream_open_before_minutes"><?php echo esc_html__( 'Open the room (minutes before)', 'anchor-schema' ); ?></label>
                    <input type="number" min="0" step="1" id="anchor_event_stream_open_before_minutes" name="anchor_event_stream_open_before_minutes" value="<?php echo esc_attr( (int) $meta['stream_open_before_minutes'] ); ?>" />
                </div>
                <div class="anchor-event-field">
                    <label for="anchor_event_stream_close_after_minutes"><?php echo esc_html__( 'Close the room (minutes after)', 'anchor-schema' ); ?></label>
                    <input type="number" min="0" step="1" id="anchor_event_stream_close_after_minutes" name="anchor_event_stream_close_after_minutes" value="<?php echo esc_attr( (int) $meta['stream_close_after_minutes'] ); ?>" />
                </div>
                <?php if ( $room !== '' ) : ?>
                    <div class="anchor-event-field" style="grid-column:1/-1;">
                        <span class="anchor-event-field-heading"><?php echo esc_html__( 'Room URL', 'anchor-schema' ); ?></span>
                        <code><?php echo esc_html( $room ); ?></code>
                        <a class="<?php echo $admin ? 'button' : 'anchor-event-button-secondary'; ?>" href="<?php echo esc_url( $room ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Open room', 'anchor-schema' ); ?></a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /**
     * Prerequisite roles the picker offers, grouped (spec §4.6): the site's own
     * editable roles, every event role, and every course role — so a past
     * event or a completed course is a one-click prerequisite.
     *
     * @return array<string,array<string,string>> Group label => slug => name.
     */
    public function prerequisite_role_choices() {
        $groups = [
            __( 'Site roles', 'anchor-schema' ) => [],
            __( 'Events', 'anchor-schema' )     => [],
            __( 'Courses', 'anchor-schema' )    => [],
        ];
        foreach ( \wp_roles()->role_names as $slug => $name ) {
            $name = \translate_user_role( $name );
            if ( \strpos( $slug, 'anchor_event_' ) === 0 ) {
                $groups[ __( 'Events', 'anchor-schema' ) ][ $slug ] = $name;
            } elseif ( \strpos( $slug, 'anchor_course_' ) === 0 ) {
                $groups[ __( 'Courses', 'anchor-schema' ) ][ $slug ] = $name;
            } elseif ( isset( \get_editable_roles()[ $slug ] ) ) {
                $groups[ __( 'Site roles', 'anchor-schema' ) ][ $slug ] = $name;
            }
        }
        return \array_filter( $groups );
    }

    /** The Access section (prerequisite roles + any/all), both surfaces. */
    public function render_access_fields( $event_id, array $meta, $admin = true ) {
        $selected = \is_array( $meta['required_roles'] ?? null ) ? $meta['required_roles'] : [];
        \ob_start();
        ?>
        <div class="anchor-event-section anchor-event-access" data-step="4">
            <h3><?php echo esc_html__( 'Access', 'anchor-schema' ); ?></h3>
            <p class="<?php echo $admin ? 'description' : 'anchor-event-hint anchor-event-hint--section'; ?>">
                <?php echo esc_html__( 'Require somebody to have attended an earlier event, or completed a course, before they can register for this one.', 'anchor-schema' ); ?>
            </p>
            <div class="anchor-event-grid">
                <div class="anchor-event-field" style="grid-column:1/-1;">
                    <label for="anchor_event_required_roles"><?php echo esc_html__( 'Prerequisites', 'anchor-schema' ); ?></label>
                    <select id="anchor_event_required_roles" name="anchor_event_required_roles[]" multiple size="8" class="widefat">
                        <?php foreach ( $this->prerequisite_role_choices() as $group => $roles ) : ?>
                            <optgroup label="<?php echo esc_attr( $group ); ?>">
                                <?php foreach ( $roles as $slug => $name ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( in_array( $slug, $selected, true ) ); ?>><?php echo esc_html( $name ); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="anchor-event-field">
                    <label for="anchor_event_required_roles_mode"><?php echo esc_html__( 'Require', 'anchor-schema' ); ?></label>
                    <select id="anchor_event_required_roles_mode" name="anchor_event_required_roles_mode">
                        <option value="any" <?php selected( $meta['required_roles_mode'], 'any' ); ?>><?php echo esc_html__( 'Any of them', 'anchor-schema' ); ?></option>
                        <option value="all" <?php selected( $meta['required_roles_mode'], 'all' ); ?>><?php echo esc_html__( 'All of them', 'anchor-schema' ); ?></option>
                    </select>
                </div>
            </div>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /** Test seam for the private session-row renderer. */
    public function event_session_row_html_public( $index, $session = null, $template = false ) {
        return $this->event_session_row_html( $index, $session, $template );
    }
```

Call both renderers from `render_meta_box()`: `echo $this->render_livestream_fields( $post->ID, $meta, true );` immediately after the Location `</div>`, and `echo $this->render_access_fields( $post->ID, $meta, true );` at the end of the Registration section.

Add two `<td>`s to `event_session_row_html()` (and matching `<th>`s in both repeater tables):

```php
            <td>
                <select name="<?php echo esc_attr( $base . '[modality]' ); ?>" class="anchor-session-modality">
                    <option value=""><?php echo esc_html__( 'Use event default', 'anchor-schema' ); ?></option>
                    <option value="in_person" <?php selected( $session['modality'] ?? '', 'in_person' ); ?>><?php echo esc_html__( 'In person', 'anchor-schema' ); ?></option>
                    <option value="virtual" <?php selected( $session['modality'] ?? '', 'virtual' ); ?>><?php echo esc_html__( 'Livestream only', 'anchor-schema' ); ?></option>
                    <option value="hybrid" <?php selected( $session['modality'] ?? '', 'hybrid' ); ?>><?php echo esc_html__( 'In person + livestream', 'anchor-schema' ); ?></option>
                </select>
            </td>
            <td>
                <label class="anchor-session-override-toggle">
                    <input type="checkbox" class="anchor-session-override" <?php checked( ! empty( $session['stream_embed']['src'] ) ); ?> />
                    <?php echo esc_html__( 'Use a different stream for this session', 'anchor-schema' ); ?>
                </label>
                <input type="text" class="anchor-session-stream widefat" name="<?php echo esc_attr( $base . '[stream_embed]' ); ?>"
                    value="<?php echo esc_attr( (string) ( $session['stream_embed']['raw'] ?? ( $session['stream_embed']['src'] ?? '' ) ) ); ?>"
                    <?php echo empty( $session['stream_embed']['src'] ) ? 'hidden' : ''; ?> />
            </td>
```

Add a modality select to the ticket-tier row (`anchor_event_tickets[<i>][modality]`, options In-person / Livestream) in `render_ticket_types_fields()`.

Add to `assets/admin.js`, inside the existing IIFE:

```js
  // Session "use a different stream" toggle (virtual-events spec §7).
  $(document).on('change', '.anchor-session-override', function () {
    var $input = $(this).closest('td').find('.anchor-session-stream');
    $input.prop('hidden', !this.checked);
    if (!this.checked) { $input.val(''); }
  });
```

- [ ] **Step 4: Run** `--filter Test_Event_Save` and `--filter Test_Event_Manager_Save` → PASS.
- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php anchor-events-manager/assets/admin.js tests/test-event-save.php
git commit -m "feat(events): Livestream metabox group, session modality/override and Access section"
```

---

### Task 17: Console parity

**Files:** Modify `anchor-events-manager.php` — `render_event_manager_form()` Location section (~:8118) and Registration section (~:8216); `save_event_manager_fields()` (forwards `$post_id` into the sanitiser, done in Task 4). Test: `tests/test-event-manager-save.php`.

- [ ] **Step 1: Failing test:**

```php
	/** The console saves the livestream and access fields identically. */
	public function test_console_saves_livestream_and_access() {
		add_role( 'anchor_course_intro', 'Course: Intro', [] );
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->module()->save_event_manager_fields( $event_id, [
			'anchor_event_title'                       => 'Console Event',
			'anchor_event_start_date'                  => '2027-07-01',
			'anchor_event_stream_embed'                => 'https://vimeo.com/31337',
			'anchor_event_stream_default_modality'     => 'hybrid',
			'anchor_event_in_person_includes_stream'   => '1',
			'anchor_event_stream_open_before_minutes'  => '20',
			'anchor_event_stream_close_after_minutes'  => '45',
			'anchor_event_required_roles'              => [ 'anchor_course_intro' ],
			'anchor_event_required_roles_mode'         => 'all',
		] );

		$meta = $this->module()->get_meta( $event_id );
		$this->assertSame( 'https://player.vimeo.com/video/31337', $meta['stream_embed']['src'] );
		$this->assertSame( 'hybrid', $meta['stream_default_modality'] );
		$this->assertSame( 20, $meta['stream_open_before_minutes'] );
		$this->assertSame( 45, $meta['stream_close_after_minutes'] );
		$this->assertSame( [ 'anchor_course_intro' ], $meta['required_roles'] );
		$this->assertSame( 'all', $meta['required_roles_mode'] );
		remove_role( 'anchor_course_intro' );
	}

	/** The console form prints the same field names as the metabox. */
	public function test_console_form_renders_the_same_fields() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$meta     = $this->module()->get_meta( $event_id );
		$this->assertStringContainsString(
			'name="anchor_event_stream_embed"',
			$this->module()->render_livestream_fields( $event_id, $meta, false )
		);
	}
```

- [ ] **Step 2: Run** `--filter Test_Event_Manager_Save` → FAIL.
- [ ] **Step 3: Implement.** In `render_event_manager_form()`, after the Location section's closing `</div>`:

```php
            <?php echo $this->render_livestream_fields( $event_id, $meta, false ); // already escaped ?>
```
…and after the Registration/tickets section:
```php
            <?php echo $this->render_access_fields( $event_id, $meta, false ); // already escaped ?>
```
Confirm `save_event_manager_fields()` passes `$post_id` to `event_authoring_input()` (Task 4) so the "keep the previous embed on refusal" rule works on this surface too.

- [ ] **Step 4: Run** `--filter Test_Event_Manager_Save`, `--filter Test_Group_Authoring_Save` → PASS.
- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php tests/test-event-manager-save.php
git commit -m "feat(events): console parity for the Livestream and Access fields"
```

---

### Task 18: Roster — Access column, grant/revoke, add-by-email, export scope

**Files:** Modify `class-roster.php` — constructor handlers (:130), list-table `get_columns()`/`column_access()` (:2178), `render_add_form()`/`frontend_add_form()`, `handle_export()` scope (:1091), `export_columns()`/`export_row_cells()`, new `handle_grant()` / `handle_revoke()` / `handle_add_access()`. Test: `tests/test-roster.php`.

**Interfaces:** admin-post actions `anchor_roster_grant`, `anchor_roster_revoke`, `anchor_roster_add_access` (nonces `anchor_roster_edit_{event_id}`); `Roster::access_state( int $event_id, array $seat ): string` → `yes|manual|no`; export scope `access`.

- [ ] **Step 1: Failing test:**

```php
	/** The Access column reports how a seat holder stands. */
	public function test_access_state_column() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$user_id  = self::factory()->user->create( [ 'user_email' => 'acc@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'acc@example.test' ] );
		$seat     = $this->registrations()->get_seat( $seat_id );

		$this->assertSame( 'yes', $this->module()->roster->access_state( $event_id, $seat ) );

		$this->module()->entitlements->revoke( $event_id, $user_id );
		$this->assertSame( 'no', $this->module()->roster->access_state( $event_id, $this->registrations()->get_seat( $seat_id ) ) );

		$this->module()->entitlements->grant( $event_id, $user_id, 'manual' );
		$this->assertSame( 'manual', $this->module()->roster->access_state( $event_id, $this->registrations()->get_seat( $seat_id ) ) );
		remove_role( 'anchor_event_' . $event_id );
	}

	/** Add-by-email creates the account, grants manually, and mints no seat. */
	public function test_add_person_by_email_grants_without_a_seat() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$user_id = $this->module()->roster->add_access_by_email( $event_id, 'Comped Person', 'comp@example.test' );

		$this->assertGreaterThan( 0, $user_id );
		$this->assertTrue( $this->module()->entitlements->holds_role( $event_id, $user_id ) );
		$this->assertSame( 'manual', $this->module()->entitlements->grant_record( $event_id, $user_id )['source'] );
		$this->assertSame( 0, $this->count_seats( $event_id ), 'Manual access is not a seat and never touches capacity.' );
		remove_role( 'anchor_event_' . $event_id );
	}

	/** Revoking a manual grant on a live seat holder leaves the role and says so. */
	public function test_manual_revoke_with_a_live_seat_keeps_access() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$user_id  = self::factory()->user->create( [ 'user_email' => 'both@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'both@example.test' ] );
		$this->module()->entitlements->grant( $event_id, $user_id, 'manual' );

		$outcome = $this->module()->roster->revoke_access( $event_id, $user_id );

		$this->assertTrue( $this->module()->entitlements->holds_role( $event_id, $user_id ) );
		$this->assertSame( 'kept_seat', $outcome );
		remove_role( 'anchor_event_' . $event_id );
	}

	/** The export offers a scope that includes manual-access holders. */
	public function test_export_access_scope_includes_manual_holders() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$user_id  = self::factory()->user->create( [ 'user_email' => 'only-access@example.test', 'display_name' => 'Only Access' ] );
		$this->module()->entitlements->grant( $event_id, $user_id, 'manual' );

		$table = $this->module()->roster->export_table_public( $event_id, 'access' );
		$flat  = wp_json_encode( $table );

		$this->assertStringContainsString( 'only-access@example.test', $flat );
		remove_role( 'anchor_event_' . $event_id );
	}
```
(Add a public `export_table_public()` seam forwarding to the private `export_table()`.)

- [ ] **Step 2: Run** `--filter Test_Roster` → FAIL.
- [ ] **Step 3: Implement.** In `Roster::__construct()`:

```php
        // Manual access (spec §4.4). Same per-event nonce as the seat actions.
        \add_action( 'admin_post_anchor_roster_grant', [ $this, 'handle_grant' ] );
        \add_action( 'admin_post_anchor_roster_revoke', [ $this, 'handle_revoke' ] );
        \add_action( 'admin_post_anchor_roster_add_access', [ $this, 'handle_add_access' ] );
```

Add:

```php
    /**
     * How a seat's holder stands on this event's role.
     *
     * @param int   $event_id
     * @param array $seat Registrations::get_seat() DTO.
     * @return string yes|manual|no
     */
    public function access_state( $event_id, array $seat ) {
        $ent = $this->module->entitlements;
        if ( ! $ent ) {
            return 'no';
        }
        $user_id = (int) ( $seat['user_id'] ?? 0 );
        if ( $user_id <= 0 ) {
            $user    = \get_user_by( 'email', (string) ( $seat['email'] ?? '' ) );
            $user_id = $user ? (int) $user->ID : 0;
        }
        if ( $user_id <= 0 || ! $ent->holds_role( (int) $event_id, $user_id ) ) {
            return 'no';
        }
        return ( ( $ent->grant_record( (int) $event_id, $user_id )['source'] ?? '' ) === 'manual' ) ? 'manual' : 'yes';
    }

    /**
     * Give somebody access with no seat at all (spec §4.4) — a comp, a
     * speaker, a late add. Creates the account when there isn't one.
     *
     * @param int    $event_id
     * @param string $name
     * @param string $email
     * @return int User id, or 0.
     */
    public function add_access_by_email( $event_id, $name, $email ) {
        $email = \sanitize_email( (string) $email );
        $ent   = $this->module->entitlements;
        if ( $email === '' || ! $ent ) {
            return 0;
        }
        $user = \get_user_by( 'email', $email );
        if ( $user instanceof \WP_User ) {
            $user_id = (int) $user->ID;
        } else {
            // Reuse ensure_user()'s account creation by handing it a
            // seat-shaped array with no seat id — it needs only name + email.
            $user_id = $ent->create_account( \sanitize_text_field( (string) $name ), $email, (int) $event_id );
        }
        if ( $user_id <= 0 ) {
            return 0;
        }
        $ent->grant( (int) $event_id, $user_id, 'manual' );
        return $user_id;
    }

    /**
     * Take a manual grant away.
     *
     * A holder who still has a confirmed seat KEEPS the role — the seat
     * entitles them independently — and the caller is told so rather than
     * being shown "Access revoked." for a change that did not happen.
     *
     * @param int $event_id
     * @param int $user_id
     * @return string revoked|kept_seat|none
     */
    public function revoke_access( $event_id, $user_id ) {
        $ent = $this->module->entitlements;
        if ( ! $ent || ! $ent->holds_role( (int) $event_id, (int) $user_id ) ) {
            return 'none';
        }
        if ( $ent->has_confirmed_seat( (int) $event_id, (int) $user_id ) ) {
            // Downgrade the RECORD to 'seat' so a later cancellation can clear
            // it, but leave the role in place.
            $ent->grant( (int) $event_id, (int) $user_id, 'seat' );
            return 'kept_seat';
        }
        $ent->revoke( (int) $event_id, (int) $user_id, 'manual' );
        return 'revoked';
    }
```

Factor the account-creation half of `Entitlements::ensure_user()` into a public `create_account( string $name, string $email, int $event_id ): int` and have `ensure_user()` call it, so the roster path reuses the same no-mail suppression.

Handlers follow the existing `guard()` → validate → `redirect()` shape:

```php
    public function handle_grant() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        $this->guard( 'anchor_roster_edit_' . $event_id );
        $seat_id = isset( $_POST['seat_id'] ) ? (int) \wp_unslash( $_POST['seat_id'] ) : 0;
        if ( ! self::seat_belongs_to_event( $seat_id, $event_id ) ) {
            $this->redirect( $event_id, 'error', \__( 'That seat is not on this event.', 'anchor-schema' ), 'invalid' );
        }
        $user_id = $this->module->entitlements->ensure_user( [ 'id' => $seat_id ] );
        if ( $user_id <= 0 ) {
            $this->redirect( $event_id, 'error', \__( 'That seat has no usable email address, so no account could be resolved.', 'anchor-schema' ), 'invalid' );
        }
        $this->module->entitlements->grant( $event_id, $user_id, 'manual' );
        $this->redirect( $event_id, 'success', \__( 'Access granted.', 'anchor-schema' ) );
    }

    public function handle_revoke() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        $this->guard( 'anchor_roster_edit_' . $event_id );
        $user_id = isset( $_POST['user_id'] ) ? (int) \wp_unslash( $_POST['user_id'] ) : 0;
        $result  = $this->revoke_access( $event_id, $user_id );
        $message = [
            'revoked'   => \__( 'Access revoked.', 'anchor-schema' ),
            'kept_seat' => \__( 'The manual grant was removed, but this person still holds a confirmed seat — so they keep access. Cancel the seat to remove it.', 'anchor-schema' ),
            'none'      => \__( 'That person did not hold access.', 'anchor-schema' ),
        ][ $result ];
        $this->redirect( $event_id, $result === 'none' ? 'error' : 'success', $message );
    }

    public function handle_add_access() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        $this->guard( 'anchor_roster_edit_' . $event_id );
        $name  = \sanitize_text_field( \wp_unslash( $_POST['access_name'] ?? '' ) );
        $email = \sanitize_email( \wp_unslash( $_POST['access_email'] ?? '' ) );
        if ( $email === '' ) {
            $this->redirect( $event_id, 'error', \__( 'An email address is required.', 'anchor-schema' ), 'invalid' );
        }
        $user_id = $this->add_access_by_email( $event_id, $name, $email );
        $this->redirect(
            $event_id,
            $user_id > 0 ? 'success' : 'error',
            $user_id > 0
                ? \__( 'Access granted. This person has no seat and does not count toward capacity.', 'anchor-schema' )
                : \__( 'Could not create or find an account for that address.', 'anchor-schema' )
        );
    }
```

List table: add `'access' => __( 'Access', 'anchor-schema' )` to `get_columns()` and

```php
            public function column_access( $item ) {
                $state = $this->roster->access_state( $this->event_id, $item );
                $label = [
                    'yes'    => \__( 'Yes', 'anchor-schema' ),
                    'manual' => \__( 'Manual', 'anchor-schema' ),
                    'no'     => \__( 'No', 'anchor-schema' ),
                ][ $state ];
                $nonce   = \wp_create_nonce( 'anchor_roster_edit_' . $this->event_id );
                $action  = ( $state === 'no' ) ? 'anchor_roster_grant' : 'anchor_roster_revoke';
                $caption = ( $state === 'no' ) ? \__( 'Grant access', 'anchor-schema' ) : \__( 'Revoke access', 'anchor-schema' );
                return '<span class="anchor-roster-access anchor-roster-access--' . \esc_attr( $state ) . '">' . \esc_html( $label ) . '</span>'
                    . '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" class="anchor-roster-access-form">'
                    . '<input type="hidden" name="action" value="' . \esc_attr( $action ) . '" />'
                    . '<input type="hidden" name="event_id" value="' . (int) $this->event_id . '" />'
                    . '<input type="hidden" name="seat_id" value="' . (int) $item['id'] . '" />'
                    . '<input type="hidden" name="user_id" value="' . (int) ( $item['user_id'] ?? 0 ) . '" />'
                    . '<input type="hidden" name="_wpnonce" value="' . \esc_attr( $nonce ) . '" />'
                    . '<button type="submit" class="button-link">' . \esc_html( $caption ) . '</button>'
                    . '</form>';
            }
```

Add the "Add person by email" form (name + email + nonce, `action=anchor_roster_add_access`) to `render_add_form()` and `frontend_add_form()`. Extend `handle_export()`'s scope to accept `access` and `export_table()` to append one row per manual-access holder (`Seat ID` blank, `Source` = `manual access`, `Status` = `access only`), with an "Export confirmed + manual access" link beside the existing export links.

- [ ] **Step 4: Run** `--filter Test_Roster`, `--filter Test_Roster_Add_Emails` → PASS.
- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-roster.php anchor-events-manager/class-entitlements.php tests/test-roster.php
git commit -m "feat(events): roster access column, manual grant/revoke, add-by-email and export scope"
```

---

### Task 19: Console Basics — the "Event role" panel

**Files:** Modify `anchor-events-manager.php` — `render_event_manager_form()` Basics section (~:7975), constructor (`admin_post_anchor_events_delete_role`), new `render_event_role_panel()` / `handle_delete_role()`. Test: `tests/test-entitlements.php`.

- [ ] **Step 1: Failing test:**

```php
	/** The Basics panel shows the role, its members, and a Delete action. */
	public function test_event_role_panel() {
		$event_id = $this->event( [ 'title' => 'Panel Event' ] );
		$this->ent()->grant( $event_id, self::factory()->user->create(), 'seat' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->module()->render_event_role_panel( $event_id );
		$this->assertStringContainsString( 'anchor_event_' . $event_id, $html );
		$this->assertStringContainsString( 'Event: Panel Event', $html );
		$this->assertStringContainsString( '1', $html );
		$this->assertStringContainsString( 'anchor_events_delete_role', $html );
	}

	/** An event with no role yet says so and offers nothing to delete. */
	public function test_event_role_panel_before_any_grant() {
		$event_id = $this->event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$html = $this->module()->render_event_role_panel( $event_id );
		$this->assertStringContainsString( 'No role yet', $html );
		$this->assertStringNotContainsString( 'anchor_events_delete_role', $html );
	}
```

- [ ] **Step 2: Run** `--filter Test_Entitlements` → FAIL.
- [ ] **Step 3: Implement.**

```php
    /**
     * The "Event role" panel on the console's Basics step (spec §7).
     *
     * Read-only except for one destructive action, which is why it is a POST
     * with a nonce and a confirm dialog rather than a link.
     *
     * @param int $event_id
     * @return string
     */
    public function render_event_role_panel( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 || ! $this->entitlements || ! Roster::current_user_can_manage() ) {
            return '';
        }
        $slug = $this->entitlements->role_for( $event_id, false );
        \ob_start();
        ?>
        <div class="anchor-event-field anchor-event-role-panel" style="grid-column:1/-1;">
            <span class="anchor-event-field-heading"><?php echo esc_html__( 'Event role', 'anchor-schema' ); ?></span>
            <?php if ( $slug === '' ) : ?>
                <p class="anchor-event-hint"><?php echo esc_html__( 'No role yet — one is created the first time somebody is granted access.', 'anchor-schema' ); ?></p>
            <?php else : ?>
                <p>
                    <code><?php echo esc_html( $slug ); ?></code> —
                    <strong><?php echo esc_html( $this->entitlements->role_name( $event_id ) ); ?></strong>
                    <?php
                    $members = $this->entitlements->role_members( $event_id );
                    printf(
                        /* translators: %d: number of people holding the role. */
                        esc_html( _n( '· %d holder', '· %d holders', $members, 'anchor-schema' ) ),
                        (int) $members
                    );
                    ?>
                </p>
                <p class="anchor-event-hint"><?php echo esc_html__( 'The role is kept after the event runs, so you can keep granting access later. Deleting it removes it from everyone who holds it.', 'anchor-schema' ); ?></p>
                <form method="post" action="<?php echo esc_url( \admin_url( 'admin-post.php' ) ); ?>"
                      onsubmit="return confirm('<?php echo esc_js( __( 'Delete this event role and remove it from every holder? This cannot be undone.', 'anchor-schema' ) ); ?>');">
                    <input type="hidden" name="action" value="anchor_events_delete_role" />
                    <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url( \remove_query_arg( 'event_manager_notice' ) ); ?>" />
                    <?php \wp_nonce_field( 'anchor_events_delete_role_' . $event_id ); ?>
                    <button type="submit" class="anchor-event-button-secondary"><?php echo esc_html__( 'Delete role', 'anchor-schema' ); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /** Delete an event role (console Basics panel). */
    public function handle_delete_role() {
        $event_id = isset( $_POST['event_id'] ) ? (int) \wp_unslash( $_POST['event_id'] ) : 0;
        \check_admin_referer( 'anchor_events_delete_role_' . $event_id );
        if ( ! Roster::current_user_can_manage() || \get_post_type( $event_id ) !== self::CPT ) {
            \wp_die( \esc_html__( 'Unauthorized', 'anchor-schema' ) );
        }
        $stripped = $this->entitlements ? $this->entitlements->delete_role( $event_id ) : 0;
        $redirect = \wp_validate_redirect( \wp_unslash( $_POST['redirect_to'] ?? '' ), \admin_url() );
        \wp_safe_redirect( \add_query_arg( 'anchor_events_role_deleted', (string) $stripped, $redirect ) );
        exit;
    }
```

Constructor: `\add_action( 'admin_post_anchor_events_delete_role', [ $this, 'handle_delete_role' ] );`. Call `echo $this->render_event_role_panel( $event_id );` inside the console's Basics `.anchor-event-grid`.

- [ ] **Step 4: Run** `--filter Test_Entitlements` → PASS.
- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php tests/test-entitlements.php
git commit -m "feat(events): Event role panel with an explicit Delete role action"
```

---

### Task 20: Occurrence inheritance for the stream keys

**Files:** Modify `class-occurrences.php:155` (`INHERITED_KEYS`). Test: `tests/test-inheritance.php`.

- [ ] **Step 1: Failing test:**

```php
	/** Children inherit every §3.1 stream key from their parent. */
	public function test_children_inherit_stream_keys() {
		$parent_id = $this->make_event( [
			'type' => 'offering',
			'stream_default_modality'   => 'hybrid',
			'in_person_includes_stream' => false,
			'stream_open_before_minutes' => 20,
			'stream_close_after_minutes' => 45,
			'required_roles'             => [ 'subscriber' ],
			'required_roles_mode'        => 'all',
			'stream_embed' => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/55', 'raw' => '' ],
		] );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-08-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'A' ],
		] );
		$children = $this->module()->occurrences->reconcile( $parent_id );
		$child    = $this->module()->get_meta( $children[0] );

		$this->assertSame( 'hybrid', $child['stream_default_modality'] );
		$this->assertFalse( $child['in_person_includes_stream'] );
		$this->assertSame( 20, $child['stream_open_before_minutes'] );
		$this->assertSame( 45, $child['stream_close_after_minutes'] );
		$this->assertSame( [ 'subscriber' ], $child['required_roles'] );
		$this->assertSame( 'all', $child['required_roles_mode'] );
		$this->assertSame( 'https://player.vimeo.com/video/55', $child['stream_embed']['src'] );
	}

	/** A child's own stream override survives the next reconcile. */
	public function test_child_stream_override_survives_reconcile() {
		$parent_id = $this->make_event( [
			'type' => 'offering',
			'stream_embed' => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/55', 'raw' => '' ],
		] );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-08-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'A' ],
		] );
		$children = $this->module()->occurrences->reconcile( $parent_id );
		update_post_meta( $children[0], '_anchor_event_stream_embed', [
			'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/66', 'raw' => '',
		] );

		$this->module()->occurrences->reconcile( $parent_id );

		$this->assertSame(
			'https://player.vimeo.com/video/55',
			$this->module()->get_meta( $children[0] )['stream_embed']['src'],
			'Inheritance is symmetric: the parent re-asserts the shared stream on every reconcile.'
		);
	}
```

- [ ] **Step 2: Run** `--filter Test_Inheritance` → FAIL on the first test.
- [ ] **Step 3: Implement.** Add to `INHERITED_KEYS`, after `'virtual_url',`:

```php
        // Hosted livestream (virtual-events spec §3.4). Shared facts about the
        // OFFERING — every date streams the same way unless the author says
        // otherwise on the parent. Inheritance is symmetric (a value cleared on
        // the parent is cleared on its dates), which is why the second test
        // asserts the parent re-asserts the stream: a per-date override lives
        // in the DATE's own session row, not in a key the parent also owns.
        'stream_embed',
        'stream_default_modality',
        'in_person_includes_stream',
        'stream_open_before_minutes',
        'stream_close_after_minutes',
        'required_roles',
        'required_roles_mode',
```
(`sessions` stays in `NEVER_COPY_KEYS` — a child's sessions are its own.)

- [ ] **Step 4: Run** `--filter Test_Inheritance`, `--filter Test_Occurrences`, `--filter Test_Reconcile` → PASS.
- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-occurrences.php tests/test-inheritance.php
git commit -m "feat(events): occurrence children inherit the livestream and prerequisite keys"
```

---

### Task 21: Playwright end-to-end

**Files:** Create `e2e/live-room.spec.js`; modify `bin/e2e-seed.sh` (two fixtures + `.seed.json` keys); modify `anchor-events-manager.php` (test-only clock filter). Test: the spec itself.

**Interfaces:** filter `anchor_events_stream_now` (int) consumed by `Stream_State::for_event()`, so the E2E can advance the clock without sleeping; seed keys `stream_event_url`, `stream_event_room_url`, `stream_event_token_url`, `in_person_toggle_off_room_url`.

- [ ] **Step 1: Write the failing spec** — `e2e/live-room.spec.js`:

```js
// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * The hosted stream room (virtual-events spec §8).
 *
 * FIXTURES (bin/e2e-seed.sh -> e2e/.seed.json):
 *   stream_event_token_url        — room URL carrying a valid ?aek= token.
 *   stream_event_room_url         — the same room, clean.
 *   in_person_toggle_off_room_url — an in-person seat on an event whose
 *                                   "in-person also get the stream" is OFF.
 * Run the seed first: `npm run env:seed`.
 */
const SEED_PATH = path.join(__dirname, '.seed.json');
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(`Missing ${SEED_PATH}. Run the seed first: npm run env:seed`);
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.stream_event_token_url, 'seed stream_event_token_url').toBeTruthy();
});

test('tokenised link signs a logged-out registrant in and lands them in the room', async ({ page }) => {
  await page.goto(new URL(seed.stream_event_token_url).pathname + new URL(seed.stream_event_token_url).search);

  // The token is consumed and stripped.
  await expect(page).toHaveURL(new RegExp('/live/$'));
  const state = page.locator('.anchor-room-state');
  await expect(state).toHaveAttribute('data-state', 'countdown');
  await expect(page.locator('.anchor-room-countdown')).toBeVisible();
  await expect(page.locator('iframe[src*="player.vimeo.com"]')).toHaveCount(0);
});

test('the room goes live, then ends, as the clock advances', async ({ page }) => {
  await page.goto(new URL(seed.stream_event_token_url).pathname + new URL(seed.stream_event_token_url).search);

  await page.goto(new URL(seed.stream_event_room_url).pathname + '?anchor_stream_now=start');
  await expect(page.locator('.anchor-room-state')).toHaveAttribute('data-state', 'live');
  await expect(page.locator('iframe[src*="player.vimeo.com"]')).toBeVisible();

  await page.goto(new URL(seed.stream_event_room_url).pathname + '?anchor_stream_now=after');
  await expect(page.locator('.anchor-room-state')).toHaveAttribute('data-state', 'ended');
  await expect(page.locator('.anchor-room-status')).toContainText('has ended');
});

test('an in-person seat is denied when the toggle is off and allowed when it is on', async ({ page }) => {
  await page.goto(new URL(seed.in_person_toggle_off_room_url).pathname + new URL(seed.in_person_toggle_off_room_url).search);
  await expect(page.locator('.anchor-room--denied')).toBeVisible();

  await page.goto(new URL(seed.in_person_toggle_on_room_url).pathname + new URL(seed.in_person_toggle_on_room_url).search);
  await expect(page.locator('.anchor-room-state')).toBeVisible();
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
npm run wp-env start && npm run env:seed && npx playwright test e2e/live-room.spec.js
```
Expected: FAIL — missing seed keys.

- [ ] **Step 3: Implement.** In `Stream_State::for_event()`, replace `$now = $now > 0 ? (int) $now : \time();` with:

```php
        /**
         * The instant the room reasons about.
         *
         * Exists so an end-to-end test can advance the clock without sleeping
         * through a real countdown. Nothing in the plugin filters it; a site
         * that does is choosing to lie to its own room.
         *
         * @param int $now
         * @param int $event_id
         */
        $now = (int) \apply_filters( 'anchor_events_stream_now', $now > 0 ? (int) $now : \time(), (int) $event_id );
```

Add to `bin/e2e-seed.sh` (WP-CLI, idempotent, mirroring the existing fixtures): create a published `event` with `registration_mode=free`, `stream_default_modality=virtual`, a Vimeo `stream_embed`, `start_ts` two hours out; create a confirmed seat for a seeded user; register an mu-plugin `anchor-e2e-stream-clock.php` that maps `?anchor_stream_now=start|after` onto `anchor_events_stream_now`; mint the `aek` token with `wp eval` via `Entitlements::room_url_for()`; create the two in-person-toggle variants; write all five URLs into `.seed.json`.

- [ ] **Step 4: Run** `npx playwright test e2e/live-room.spec.js` → PASS.
- [ ] **Step 5: Commit**

```bash
git add e2e/live-room.spec.js bin/e2e-seed.sh anchor-events-manager/class-stream-state.php
git commit -m "test(events): Playwright coverage for the room countdown, live switch and toggle"
```

---

### Task 22: Documentation — `EVENTS.md` and `EMAILS.md`

**Files:** Modify `anchor-events-manager/EVENTS.md` (Key Meta Keys :269, Main Public API :294, Filters :451, plus a new "Hosted livestream room" section after "Front End"), `anchor-events-manager/EMAILS.md` (token table :142, Overview :3).

No test — documentation. Verify by reading.

- [ ] **Step 1: Add the meta keys to `EVENTS.md`'s table**

```markdown
| `stream_embed` | array | Normalised stream `{provider, kind, src, raw}` — output of `Embed::normalize()`, never raw HTML. Saving a non-empty value forces `virtual=1`. Inherited by occurrence children. |
| `stream_default_modality` | string | `in_person` \| `virtual` \| `hybrid` — the seed for new session rows and new tiers. Inherited. |
| `in_person_includes_stream` | bool | "In-person registrants also get the stream". Default `true`. Inherited. |
| `stream_open_before_minutes` | int | Room switches countdown → player this many minutes before a session starts. Default `15`. Inherited. |
| `stream_close_after_minutes` | int | Room keeps the player this long after a session's `end_ts`. Default `30`. Inherited. |
| `required_roles` | string[] | Prerequisite role slugs — checked inside `Registrations::capacity_decision()`. Inherited. |
| `required_roles_mode` | string | `any` \| `all`. Inherited. |
| `sessions[].modality` | string | Per-session `in_person` \| `virtual` \| `hybrid`; empty inherits `stream_default_modality`. |
| `sessions[].stream_embed` | array | Per-session stream override; empty inherits the event's. |
```
…and to the seat meta list: `_anchor_event_user_id` (int) — the account a seat entitles, resolved by `Entitlements::ensure_user()`; distinct from `_anchor_event_customer_id` (the WooCommerce order's customer, 0 = guest).
…and to the tier shape: `modality` — `in_person` \| `virtual`, default `in_person`; mirrored on the managed variation as `_anchor_evt_modality`.

- [ ] **Step 2: Add the "Hosted livestream room" section** after "Front End"

```markdown
## Hosted livestream room

Every event registered **through this plugin** (`registration_mode` `wc` or `free`;
never `external`, never a group parent) has a private room at
`<event permalink>/live/` — an `add_rewrite_endpoint( 'live', EP_PERMALINK )`
endpoint, so it is a URL on the event, not a second post.

The room is `private, no-store`, carries `X-Robots-Tag: noindex, nofollow` and a
robots meta tag, and is not in the sitemap (core sitemaps list post permalinks;
a rewrite endpoint is never enumerated). `Event_Schema` publishes the room URL
as the `VirtualLocation.url` — schema points at it, robots keep it out of
results.

**States** (`Anchor\Events\Stream_State`, a pure function of the resolved
sessions, the two window widths and the clock): `unavailable`, `pending`,
`countdown`, `live`, `between`, `ended`. The embed is in the markup **only**
during `live`.

**Template:** `templates/live-event.php`, overridable by a theme at
`events/live-event.php` or `live-event.php`. The body is
`Module::render_room( $event_id )`, which renders one of: sign-in form (logged
out), denial (logged in, not entitled), or the state block + schedule.

**Refresh:** `assets/room.js` ticks the countdown, corrects for client clock
skew from `data-server-now`, and calls
`GET /wp-json/anchor-events/v1/events/{id}/room` when the target passes — and
every 5 minutes while `live`. 401 logged out, 403 not entitled.

**Access** is a capability-less WordPress role `anchor_event_{id}`
("Event: {title}"), minted lazily on the first grant, renamed with the title,
and **never deleted automatically** — trashing the event leaves it. The console's
Basics step has an explicit **Delete role** action.

**Sign-in link:** `Entitlements::room_url_for( $user_id, $event_id )` appends a
stateless `?aek=` HMAC (user | event | expiry | password fragment), expiring at
the last session's `end_ts` + 7 days. A logged-out visitor with a valid token is
signed in and redirected to the clean room URL; the token is never shown in
admin or logs.
```

- [ ] **Step 3: Add the API and hooks**

To "Main Public API", under `Module`:

```markdown
- `room_url( $event_id ): string` — `''` when the event can never have a room.
- `render_room( $event_id ): string`, `room_state_block( $event_id, array $state ): string`
- `resolved_sessions( $event_id ): array` — always at least one row (a single event resolves to one implicit session).
- `stream_capable( $event_id ): bool`
```
…and a new block:

```markdown
**`Anchor\Events\Entitlements`** (`$module->entitlements`)
- `can_access_stream( $event_id, $session_index = 0, $user_id = 0 ): bool` — the one access question.
- `role_for( $event_id, $create = true ): string` / `role_name()` / `role_members()` / `delete_role()`
- `grant( $event_id, $user_id, $source = 'seat' )` / `revoke( … )` — `$source` is `seat` or `manual`; a manual grant is never downgraded by a seat cancellation.
- `ensure_user( array $seat ): int` — resolve or create the account a seat entitles.
- `login_token( $user_id, $event_id )` / `verify_login_token( $token, $event_id )` / `room_url_for( $user_id, $event_id )`
- `meets_prerequisites( $event_id, $user_id = 0 ): bool` / `prerequisite_message( $event_id ): string`

**`Anchor\Events\Stream_State`** — `for_event( $event_id, $now = 0 )`, `decide( $sessions, $open_before, $close_after, $now, $capable )`.

**`Anchor\Events\Embed`** — `normalize( $input )` (array or `WP_Error`), `render( array $embed, $title )`, `providers()`.
```

To the Filters table:

```markdown
| `anchor_events_can_access_stream` | `$allowed, $event_id, $session_index, $user_id` | The final say on room access. The courses module vetoes here ("finish the pre-work first"). |
| `anchor_events_embed_providers` | `$providers` | The stream provider table — `slug => { hosts[], kind: iframe\|link, transform }`. Add a host to allow it. |
| `anchor_events_create_account` | `$create, $event_id, $email` | Return `false` to stop the module creating accounts for registrants who have none. Opting out means guests get no room access. |
| `anchor_events_room_denied_message` | `$message, $event_id` | The wording a signed-in but unentitled visitor sees in the room. |
| `anchor_events_stream_now` | `$now, $event_id` | The instant `Stream_State` reasons about. Exists for end-to-end tests; filtering it in production lies to the room. |
```

And an **Actions** table (new — the file has none):

```markdown
## Actions

| Action | Args | When |
|---|---|---|
| `anchor_events_seat_created` | `$seat_id, $status` | A seat was created, after every meta write. The companion to `anchor_events_seat_status_changed`, and not a duplicate: a seat is usually BORN in its final status and never transitions. |
| `anchor_events_seat_status_changed` | `$seat_id, $from, $to, $actor` | An actual status transition only — a same-status note-only call never fires it. |
| `anchor_events_access_granted` | `$event_id, $user_id, $source` | A user gained the event role. `$source` is `seat` or `manual`. |
| `anchor_events_access_revoked` | `$event_id, $user_id, $source` | A user lost the event role. |
```

- [ ] **Step 4: `EMAILS.md`** — add to the body-template token table:

```markdown
| `{room_link}` | The hosted room URL **for this recipient**, carrying their own one-click sign-in token (`?aek=`). Confirmed seats only — a waitlisted recipient gets an empty string, exactly like `{join_link}`. Expires at the last session's end + 7 days. Requires an account; when `anchor_events_create_account` is filtered off and the recipient has none, it is empty. |
```
…and a note under "Overview":

> **CTA default order.** With no per-event override, the button resolves
> **stream > virtual > event page**: a stream-capable event with a saved embed
> gets "Join the livestream" pointing at `{room_link}`; a legacy virtual event
> gets "Join the event" pointing at `virtual_url`; everything else gets
> "View event details". `{join_link}` still means the raw provider URL, so
> existing templates are untouched.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/EVENTS.md anchor-events-manager/EMAILS.md
git commit -m "docs(events): document the room, entitlements, new hooks and {room_link}"
```

---

### Task 23: Full-suite green and rollout note

- [ ] **Step 1: Run the whole suite the way CI does**

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
composer test
```
Expected: PASS, both passes (the second is `--group ajax`).

- [ ] **Step 2: Run the E2E suite**

```bash
npm run wp-env start && npm run env:seed && npx playwright test
```
Expected: PASS.

- [ ] **Step 3: Record the rollout steps in the PR description (not in code)**

```
New keys default to previous behaviour, so there is no data migration.
`virtual_url`-only events get a room automatically through the fallback embed
(a Zoom link renders as a "Join on Zoom" button).

On deploy: rewrite rules flush themselves on the first request
(Module::maybe_flush_rewrites(), signature option `anchor_events_rw_sig`).
Spot-check one legacy virtual event's /live/ and then author the first hybrid
event.

NOT part of this branch: the Anchor-Tools 3.31.0 version bump and tag. Per
CLAUDE.md the release is cut from `main` by the owner — merge first, bump
`Version:` on `main`, then tag from `main`.

Theme follow-up (separate, in deka-context): `events/live-event.php` override
and a modality badge on `events/single-event.php`.
```

- [ ] **Step 4: Commit**

```bash
git commit --allow-empty -m "chore(events): virtual-events stream room complete; release is cut from main"
```

---

## Self-Review

**Spec coverage.** §3.1 → T1, T4; §3.2 → T2, T4, T16; §3.3 → T15; §3.4 → T20; §3.5 → T8 (`_anchor_event_user_id`, `_anchor_event_grants`); §4.1 → T6, T19; §4.2 → T6, T7; §4.3 → T8; §4.4 → T18; §4.5 → T9; §4.6 → T10, T16; §5.1 → T11; §5.2 → T11; §5.3 → T5; §5.4 → T3; §5.5 → T11; §5.6 → T12; §6.1 → T15; §6.2 → T13, T14; §6.3 → T9, T11 (schema `VirtualLocation`); §7 → T16, T17, T18, T19, T11 (Live column); §8 → every task's tests plus T21; §10 → T23 (with the version bump explicitly excluded).

**Placeholder scan.** No "TBD"/"similar to Task N"/"add error handling". Every code step carries real PHP or JS. Two deliberate forward references are named and closed: T7's email-only user resolution is replaced by `ensure_user()` in T8 (stated in T7's Interfaces), and T4's session embed sanitiser consumes `Embed` from T3.

**Type consistency.** `stream_embed` is `{provider, kind, src, raw}` everywhere (T3 produces it, T2/T4 store it, T5 passes it, T11 renders it). `Stream_State::decide()`/`for_event()` both return `{state, session_index, target_ts, embed}` — the same four keys the REST response and `room_state_block()` read. `Entitlements::can_access_stream( $event_id, $session_index = 0, $user_id = 0 )` has one signature in T9, T11, T12 and `can_view_virtual_link()`. `has_confirmed_seat()` (T7) is the name T9's `resolve_access()` and T18's `revoke_access()` both call — not `user_has_confirmed_seat`. `Ticket_Types` tier key is `modality` in T15 and in T9's `seat_tier_modality()`.
