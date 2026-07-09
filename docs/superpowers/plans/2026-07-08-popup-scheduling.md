# Popup Scheduling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each popup an optional start/end date range so it goes live and goes dormant on its own.

**Architecture:** All schedule logic lives in one pure static class, `Anchor_UP_Schedule`, with no database access and the timezone injected as a parameter — so it unit-tests with no WordPress bootstrap. Two gates consume it: PHP decides which popups *ship* into the `UP_SNIPPETS` payload (a cache envelope), and JS decides which popups *fire* (authoritative on stale cached HTML). No cron, no options, no migration.

**Tech Stack:** PHP 8.1+, WordPress post meta, plain-PHP assertion tests (no PHPUnit — `vendor/bin` has no phpunit installed and the class needs no WP), vanilla JS (no build step; `bin/build-assets.mjs` minifies).

**Spec:** `docs/superpowers/specs/2026-07-08-popup-scheduling-design.md`

## Global Constraints

- Text domain for translatable strings: `anchor-schema`.
- Meta key prefix for this module: `up_`. `defaults()` keys are *unprefixed*; `get_meta()` prefixes them.
- Asset URLs go through `Anchor_Asset_Loader::url()`. Asset cache-busting uses `filemtime()`, so **no version string needs bumping**.
- Schedule storage format is the local wall-clock string `Y-m-d\TH:i` — exactly what `<input type="datetime-local">` produces. Empty string means unbounded.
- `state()` returns exactly one of: `unscheduled`, `pending`, `active`, `expired`, `invalid`.
- `state()` evaluation order is fixed: `invalid` → `unscheduled` → `pending` → `expired` → `active`. Checking `invalid` first matters; a reversed range would otherwise read as `expired`.
- An `unscheduled` popup is **active** and **always ships**. Existing popups have no schedule meta, so they must behave exactly as they do today.
- Draft always wins: `get_published_popups()` keeps `'post_status' => 'publish'`. A schedule can only subtract.
- `now` is always an absolute UTC epoch from `time()`. Never `current_time('timestamp')`, which is local-shifted.
- Cache-envelope grace: `(int) apply_filters( 'anchor_popup_schedule_cache_grace', DAY_IN_SECONDS )`.
- Envelope boundaries are inclusive: a popup expired *exactly* `$grace` ago still ships; one second more does not. A popup starting *exactly* `$grace` from now still ships; one second further does not.

---

### Task 1: Pure schedule logic + tests

**Files:**
- Create: `anchor-universal-popups/includes/class-up-schedule.php`
- Test: `tests/up-schedule-test.php`

**Interfaces:**
- Consumes: nothing.
- Produces, all `public static` on `Anchor_UP_Schedule`:
  - `sanitize_local( mixed $raw ): string` — `''` or a normalized `Y-m-d\TH:i`
  - `to_epoch( mixed $local, DateTimeZone $tz ): ?int`
  - `state( ?int $start, ?int $end, int $now ): string`
  - `is_active( ?int $start, ?int $end, int $now ): bool`
  - `should_ship( ?int $start, ?int $end, int $now, int $grace ): bool`

- [ ] **Step 1: Write the failing test**

Create `tests/up-schedule-test.php`:

```php
<?php
// Standalone logic test for Anchor_UP_Schedule.
// Run: php tests/up-schedule-test.php
// No WordPress needed — the class is pure and takes its timezone as a parameter.

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../anchor-universal-popups/includes/class-up-schedule.php';

$fail = 0;
function check( $cond, $msg ) {
    global $fail;
    if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fail++; }
}

$utc     = new DateTimeZone( 'UTC' );
$chicago = new DateTimeZone( 'America/Chicago' );
$DAY     = 86400;
$NOW     = 1000000;   // arbitrary fixed "now"

// --- sanitize_local -------------------------------------------------------
check( Anchor_UP_Schedule::sanitize_local( '' ) === '', 'empty string stays empty' );
check( Anchor_UP_Schedule::sanitize_local( '   ' ) === '', 'whitespace stays empty' );
check( Anchor_UP_Schedule::sanitize_local( '2026-07-15T09:00' ) === '2026-07-15T09:00', 'minute precision round-trips' );
check( Anchor_UP_Schedule::sanitize_local( '2026-07-15T09:00:30' ) === '2026-07-15T09:00', 'seconds are truncated' );
check( Anchor_UP_Schedule::sanitize_local( 'garbage' ) === '', 'garbage rejected' );
check( Anchor_UP_Schedule::sanitize_local( '2026-13-45T99:99' ) === '', 'impossible date rejected' );
check( Anchor_UP_Schedule::sanitize_local( '<script>' ) === '', 'markup rejected' );

// --- to_epoch -------------------------------------------------------------
check( Anchor_UP_Schedule::to_epoch( '', $utc ) === null, 'empty -> null' );
check( Anchor_UP_Schedule::to_epoch( 'garbage', $utc ) === null, 'garbage -> null' );
check( Anchor_UP_Schedule::to_epoch( '1970-01-01T00:00', $utc ) === 0, 'epoch zero parses' );
check( Anchor_UP_Schedule::to_epoch( '2026-07-15T09:00', $utc ) === 1784106000, 'known UTC epoch' );

// Seconds must be zeroed, not inherited from the current clock.
check( Anchor_UP_Schedule::to_epoch( '2026-07-15T09:00', $utc ) % 60 === 0, 'seconds zeroed' );

// --- DST correctness ------------------------------------------------------
// US spring-forward is 2026-03-08. 09:00 local on Mar 7 -> 09:00 local on Mar 8
// is only 23 real hours.
$mar7 = Anchor_UP_Schedule::to_epoch( '2026-03-07T09:00', $chicago );
$mar8 = Anchor_UP_Schedule::to_epoch( '2026-03-08T09:00', $chicago );
check( $mar8 - $mar7 === 23 * 3600, 'spring-forward day is 23h' );

// US fall-back is 2026-11-01. Oct 31 09:00 -> Nov 1 09:00 is 25 real hours.
$oct31 = Anchor_UP_Schedule::to_epoch( '2026-10-31T09:00', $chicago );
$nov1  = Anchor_UP_Schedule::to_epoch( '2026-11-01T09:00', $chicago );
check( $nov1 - $oct31 === 25 * 3600, 'fall-back day is 25h' );

// --- state ----------------------------------------------------------------
check( Anchor_UP_Schedule::state( null, null, $NOW ) === 'unscheduled', 'no bounds -> unscheduled' );
check( Anchor_UP_Schedule::state( $NOW + 10, null, $NOW ) === 'pending', 'before start -> pending' );
check( Anchor_UP_Schedule::state( $NOW - 10, null, $NOW ) === 'active', 'after open-ended start -> active' );
check( Anchor_UP_Schedule::state( null, $NOW + 10, $NOW ) === 'active', 'before end, no start -> active' );
check( Anchor_UP_Schedule::state( null, $NOW - 10, $NOW ) === 'expired', 'after end -> expired' );
check( Anchor_UP_Schedule::state( $NOW - 10, $NOW + 10, $NOW ) === 'active', 'inside both bounds -> active' );
check( Anchor_UP_Schedule::state( $NOW + 10, $NOW + 20, $NOW ) === 'pending', 'before both bounds -> pending' );
check( Anchor_UP_Schedule::state( $NOW - 20, $NOW - 10, $NOW ) === 'expired', 'after both bounds -> expired' );

// Boundaries: start is inclusive, end is exclusive.
check( Anchor_UP_Schedule::state( $NOW, null, $NOW ) === 'active', 'exactly at start -> active' );
check( Anchor_UP_Schedule::state( null, $NOW, $NOW ) === 'expired', 'exactly at end -> expired' );

// invalid wins over expired.
check( Anchor_UP_Schedule::state( $NOW + 10, $NOW - 10, $NOW ) === 'invalid', 'reversed range -> invalid' );
check( Anchor_UP_Schedule::state( $NOW, $NOW, $NOW ) === 'invalid', 'zero-length range -> invalid' );
check( Anchor_UP_Schedule::state( 500, 100, 9999 ) === 'invalid', 'reversed range not mistaken for expired' );

// --- is_active ------------------------------------------------------------
check( Anchor_UP_Schedule::is_active( null, null, $NOW ) === true, 'unscheduled is active' );
check( Anchor_UP_Schedule::is_active( $NOW - 10, $NOW + 10, $NOW ) === true, 'in-window is active' );
check( Anchor_UP_Schedule::is_active( $NOW + 10, null, $NOW ) === false, 'pending is not active' );
check( Anchor_UP_Schedule::is_active( null, $NOW - 10, $NOW ) === false, 'expired is not active' );
check( Anchor_UP_Schedule::is_active( $NOW + 10, $NOW - 10, $NOW ) === false, 'invalid is not active' );

// --- should_ship (the cache envelope) -------------------------------------
check( Anchor_UP_Schedule::should_ship( null, null, $NOW, $DAY ) === true, 'unscheduled always ships' );
check( Anchor_UP_Schedule::should_ship( $NOW - 10, $NOW + 10, $NOW, $DAY ) === true, 'active ships' );
check( Anchor_UP_Schedule::should_ship( $NOW + 10, $NOW - 10, $NOW, $DAY ) === false, 'invalid never ships' );

// Recently expired still ships, so a stale cached page can close its window.
check( Anchor_UP_Schedule::should_ship( null, $NOW - 10, $NOW, $DAY ) === true, 'just-expired still ships' );
check( Anchor_UP_Schedule::should_ship( null, $NOW - $DAY, $NOW, $DAY ) === true, 'expired exactly at grace ships' );
check( Anchor_UP_Schedule::should_ship( null, $NOW - $DAY - 1, $NOW, $DAY ) === false, 'expired one second past grace does not ship' );

// Near-future still ships, so a stale cached page can open its window.
check( Anchor_UP_Schedule::should_ship( $NOW + 10, null, $NOW, $DAY ) === true, 'imminent start ships' );
check( Anchor_UP_Schedule::should_ship( $NOW + $DAY, null, $NOW, $DAY ) === true, 'start exactly at grace ships' );
check( Anchor_UP_Schedule::should_ship( $NOW + $DAY + 1, null, $NOW, $DAY ) === false, 'start one second beyond grace does not ship' );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/up-schedule-test.php`
Expected: FAIL — `Failed opening required '.../class-up-schedule.php'`

- [ ] **Step 3: Write minimal implementation**

Create `anchor-universal-popups/includes/class-up-schedule.php`:

```php
<?php
/**
 * Pure schedule logic for Anchor Universal Popups.
 *
 * No database access, no global state. The timezone is passed in rather than
 * read from wp_timezone() so every method stays pure and unit-testable with no
 * WordPress bootstrap. See tests/up-schedule-test.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_UP_Schedule {

    /** Storage + display format: what <input type="datetime-local"> produces. */
    const FMT = 'Y-m-d\TH:i';

    /** Parse format. The leading "!" zeroes all unspecified fields, including seconds. */
    const FMT_PARSE = '!Y-m-d\TH:i';

    /**
     * Normalize a local datetime string. Returns '' for empty or malformed
     * input, which the rest of the module reads as "unbounded on this side".
     */
    public static function sanitize_local( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return '';

        // Browsers may or may not include seconds depending on step attribute.
        foreach ( [ '!Y-m-d\TH:i', '!Y-m-d\TH:i:s' ] as $fmt ) {
            $d   = DateTimeImmutable::createFromFormat( $fmt, $raw, new DateTimeZone( 'UTC' ) );
            $err = DateTimeImmutable::getLastErrors();
            $ok  = $d instanceof DateTimeImmutable
                && ( ! $err || ( empty( $err['warning_count'] ) && empty( $err['error_count'] ) ) );
            if ( $ok ) return $d->format( self::FMT );
        }
        return '';
    }

    /** Local wall-clock string -> absolute UTC epoch, or null when unbounded. */
    public static function to_epoch( $local, DateTimeZone $tz ) {
        $local = self::sanitize_local( $local );
        if ( $local === '' ) return null;

        $d = DateTimeImmutable::createFromFormat( self::FMT_PARSE, $local, $tz );
        return $d instanceof DateTimeImmutable ? $d->getTimestamp() : null;
    }

    /**
     * Exactly one of: invalid, unscheduled, pending, expired, active.
     * Order matters — a reversed range must not read as "expired".
     * Start is inclusive, end is exclusive.
     */
    public static function state( $start, $end, $now ) {
        if ( $start !== null && $end !== null && $start >= $end ) return 'invalid';
        if ( $start === null && $end === null )                   return 'unscheduled';
        if ( $start !== null && $now <  $start )                   return 'pending';
        if ( $end   !== null && $now >= $end )                     return 'expired';
        return 'active';
    }

    /** An unscheduled popup is active. */
    public static function is_active( $start, $end, $now ) {
        return in_array( self::state( $start, $end, $now ), [ 'unscheduled', 'active' ], true );
    }

    /**
     * Should this popup be shipped into the UP_SNIPPETS payload?
     *
     * Wider than is_active() on purpose. Popups reach the browser inlined in
     * HTML that may be served from a full-page cache, so the payload must also
     * carry popups whose window is about to open or has just closed — otherwise
     * the JS gate has nothing to reveal or hide. $grace should be >= the max
     * page-cache TTL. Boundaries are inclusive.
     */
    public static function should_ship( $start, $end, $now, $grace ) {
        $state = self::state( $start, $end, $now );
        if ( $state === 'invalid' )     return false;
        if ( $state === 'unscheduled' ) return true;

        if ( $end   !== null && $now   > $end + $grace )   return false; // long dead
        if ( $start !== null && $start > $now + $grace )   return false; // far future
        return true;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/up-schedule-test.php`
Expected: every line `PASS:`, final line `ALL PASSED`, exit code 0.

If `known UTC epoch` fails, do not "fix" it by editing the expected number until it matches — verify independently first: `php -r 'echo (new DateTimeImmutable("2026-07-15T09:00", new DateTimeZone("UTC")))->getTimestamp();'` should print `1784106000`.

- [ ] **Step 5: Commit**

```bash
git add anchor-universal-popups/includes/class-up-schedule.php tests/up-schedule-test.php
git commit -m "feat(popups): pure Anchor_UP_Schedule date-range logic + tests"
```

---

### Task 2: Persist the two meta keys

**Files:**
- Modify: `anchor-universal-popups/anchor-universal-popups.php` — top-of-file `require_once` (after the `ABSPATH` guard, line 6); `defaults()` (line ~213); `save_meta()` `$fields` (line ~826) and its sanitize branch (line ~831)

**Interfaces:**
- Consumes: `Anchor_UP_Schedule::sanitize_local()` from Task 1.
- Produces: meta keys `up_schedule_start` / `up_schedule_end`; `get_meta()` array keys `schedule_start` / `schedule_end`, both `''` when unset.

- [ ] **Step 1: Load the class**

In `anchor-universal-popups/anchor-universal-popups.php`, immediately after `if (!defined('ABSPATH')) exit;` (line 6) and before `class Anchor_Universal_Popups_Module {`, add:

```php
require_once __DIR__ . '/includes/class-up-schedule.php';
```

- [ ] **Step 2: Add the defaults**

In `defaults()`, the array currently ends with the exclusions block. Change:

```php
            // exclusions
            'exclude_urls' => '',           // comma separated list, full or relative
            'exclude_cats' => '',           // comma separated list of slugs or IDs
        ];
```

to:

```php
            // exclusions
            'exclude_urls' => '',           // comma separated list, full or relative
            'exclude_cats' => '',           // comma separated list of slugs or IDs

            // schedule (optional date range; '' on either side means unbounded)
            'schedule_start' => '',         // local 'Y-m-d\TH:i', blank = live immediately
            'schedule_end'   => '',         // local 'Y-m-d\TH:i', blank = runs forever
        ];
```

`get_meta()` iterates `defaults()`, so it now returns both keys with no further change.

- [ ] **Step 3: Persist them on save**

In `save_meta()`, add the two fields to the `$fields` allowlist. Change:

```php
            'exclude_urls','exclude_cats'
        ];
```

to:

```php
            'exclude_urls','exclude_cats',
            'schedule_start','schedule_end'
        ];
```

Then, in the `foreach ($fields as $f)` loop, the datetime fields need format validation rather than `sanitize_text_field()`. Change:

```php
            if (in_array($f, ['html','shortcode','css','js'], true)){
                // allow markup in these fields in admin
                update_post_meta($post_id, $key, $val);
            } else {
                update_post_meta($post_id, $key, sanitize_text_field($val));
            }
```

to:

```php
            if (in_array($f, ['html','shortcode','css','js'], true)){
                // allow markup in these fields in admin
                update_post_meta($post_id, $key, $val);
            } elseif (in_array($f, ['schedule_start','schedule_end'], true)){
                // strict format validation; anything malformed stores as '' (unbounded)
                update_post_meta($post_id, $key, Anchor_UP_Schedule::sanitize_local($val));
            } else {
                update_post_meta($post_id, $key, sanitize_text_field($val));
            }
```

- [ ] **Step 4: Verify syntax and that nothing regressed**

Run: `php -l anchor-universal-popups/anchor-universal-popups.php`
Expected: `No syntax errors detected`

Run: `php tests/up-schedule-test.php`
Expected: `ALL PASSED` (still green — this task changed no logic)

- [ ] **Step 5: Commit**

```bash
git add anchor-universal-popups/anchor-universal-popups.php
git commit -m "feat(popups): persist up_schedule_start / up_schedule_end meta"
```

---

### Task 3: PHP gate — the cache envelope

**Files:**
- Modify: `anchor-universal-popups/anchor-universal-popups.php` — `get_published_popups()` (line ~923) and `get_renderable_popup()` (line ~1021)

**Interfaces:**
- Consumes: `Anchor_UP_Schedule::to_epoch()`, `::should_ship()` from Task 1; `schedule_start` / `schedule_end` from Task 2.
- Produces: each entry of the localized `UP_SNIPPETS` array gains
  `'schedule' => [ 'starts' => ?int, 'ends' => ?int ]` — absolute UTC epoch seconds or `null`.

- [ ] **Step 1: Add a shared helper for the two epochs**

Add this private method to `Anchor_Universal_Popups_Module`, directly above `get_published_popups()`:

```php
    /**
     * Resolve a popup's schedule bounds to absolute UTC epochs, plus the
     * cache-envelope grace. Returns [ ?int $start, ?int $end, int $grace ].
     */
    private function schedule_bounds(array $m){
        $tz    = wp_timezone();
        $grace = (int) apply_filters('anchor_popup_schedule_cache_grace', DAY_IN_SECONDS);
        return [
            Anchor_UP_Schedule::to_epoch($m['schedule_start'], $tz),
            Anchor_UP_Schedule::to_epoch($m['schedule_end'], $tz),
            $grace,
        ];
    }
```

- [ ] **Step 2: Gate the payload**

In `get_published_popups()`, the loop begins:

```php
        foreach ($q->posts as $p){
            $m = $this->get_meta($p->ID);

            // For shortcode mode, process the shortcode content server-side
```

Insert the envelope check immediately after `$m` is populated:

```php
        foreach ($q->posts as $p){
            $m = $this->get_meta($p->ID);

            // Schedule: drop popups outside the cache envelope. Popups that are
            // merely pending or just-expired still ship — the JS gate opens and
            // closes their window on pages served from the full-page cache.
            list($sched_start, $sched_end, $sched_grace) = $this->schedule_bounds($m);
            if (!Anchor_UP_Schedule::should_ship($sched_start, $sched_end, time(), $sched_grace)) continue;

            // For shortcode mode, process the shortcode content server-side
```

- [ ] **Step 3: Ship the bounds to JS**

Still in `get_published_popups()`, the per-popup array currently ends:

```php
                'exclude_urls' => $m['exclude_urls'],
                'exclude_cats' => $m['exclude_cats'],
            ];
```

Change it to:

```php
                'exclude_urls' => $m['exclude_urls'],
                'exclude_cats' => $m['exclude_cats'],
                'schedule' => [
                    // Absolute UTC epochs, so the visitor's timezone is irrelevant.
                    'starts' => $sched_start,
                    'ends'   => $sched_end,
                ],
            ];
```

- [ ] **Step 4: Gate the shortcode-rendered card too**

`get_renderable_popup()` backs the `[anchor_popup id="..."]` shortcode. It currently opens:

```php
    private function get_renderable_popup($post_id){
        $post = get_post((int) $post_id);
        if (!$post || $post->post_type !== self::CPT || $post->post_status !== 'publish') {
            return null;
        }
```

Add the same envelope check right after that guard:

```php
    private function get_renderable_popup($post_id){
        $post = get_post((int) $post_id);
        if (!$post || $post->post_type !== self::CPT || $post->post_status !== 'publish') {
            return null;
        }

        // Schedule: same cache envelope as get_published_popups().
        $sched_meta = $this->get_meta($post->ID);
        list($sched_start, $sched_end, $sched_grace) = $this->schedule_bounds($sched_meta);
        if (!Anchor_UP_Schedule::should_ship($sched_start, $sched_end, time(), $sched_grace)) {
            return null;
        }
```

- [ ] **Step 5: Verify syntax**

Run: `php -l anchor-universal-popups/anchor-universal-popups.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add anchor-universal-popups/anchor-universal-popups.php
git commit -m "feat(popups): cache-envelope schedule gate on the UP_SNIPPETS payload"
```

---

### Task 4: JS gate — authoritative at fire time

**Files:**
- Modify: `anchor-universal-popups/assets/frontend.js` — new helper near the top of the IIFE; `snippetMap` build (line ~690); `DOMContentLoaded` attach loop (line ~745)

**Interfaces:**
- Consumes: `sn.schedule.starts` / `sn.schedule.ends` from Task 3 (UTC epoch seconds or `null`).
- Produces: nothing consumed by later tasks.

There are **two** places that read `UP_SNIPPETS`. Gating only the attach loop would leave shortcode-rendered video cards clickable after their window closed. Both must be gated.

- [ ] **Step 1: Add the guard**

In `anchor-universal-popups/assets/frontend.js`, immediately above the `// Build a lookup map for snippets by ID` comment, add:

```js
  // Schedule gate. UP_SNIPPETS is inlined into HTML that may be served from a
  // full-page cache, so the PHP gate alone cannot be trusted at fire time.
  // Bounds are absolute UTC epoch seconds, so the visitor's timezone does not
  // matter — only clock skew, which is immaterial at day-scale windows.
  function withinSchedule(sn){
    var s = (sn && sn.schedule) || {};
    var now = Math.floor(Date.now() / 1000);
    if (s.starts && now <  s.starts) return false;
    if (s.ends   && now >= s.ends)   return false;
    return true;
  }
```

- [ ] **Step 2: Gate the shortcode-card lookup map**

Change:

```js
  // Build a lookup map for snippets by ID
  var snippetMap = {};
  UP_SNIPPETS.forEach(function(sn) {
    snippetMap[sn.id] = sn;
  });
```

to:

```js
  // Build a lookup map for snippets by ID. Out-of-window popups are omitted, so
  // a cached shortcode-rendered card finds no snippet and its click does nothing.
  var snippetMap = {};
  UP_SNIPPETS.forEach(function(sn) {
    if (!withinSchedule(sn)) return;
    snippetMap[sn.id] = sn;
  });
```

- [ ] **Step 3: Gate the trigger-attach loop**

Change:

```js
  document.addEventListener('DOMContentLoaded', function(){
    UP_SNIPPETS.forEach(function(sn){
      try{ attach(sn); }catch(e){}
    });
    processPreloadQueue();
  });
```

to:

```js
  document.addEventListener('DOMContentLoaded', function(){
    UP_SNIPPETS.forEach(function(sn){
      if (!withinSchedule(sn)) return;
      try{ attach(sn); }catch(e){}
    });
    processPreloadQueue();
  });
```

- [ ] **Step 4: Verify the JS parses and the gate is correct**

Run: `node --check anchor-universal-popups/assets/frontend.js`
Expected: no output, exit 0.

Now prove the guard logic in isolation. Run:

```bash
node -e '
function withinSchedule(sn){
  var s = (sn && sn.schedule) || {};
  var now = Math.floor(Date.now()/1000);
  if (s.starts && now <  s.starts) return false;
  if (s.ends   && now >= s.ends)   return false;
  return true;
}
var now = Math.floor(Date.now()/1000), f = 0;
function check(c,m){ console.log((c?"PASS: ":"FAIL: ")+m); if(!c) f++; }
check(withinSchedule({}) === true, "no schedule key -> fires");
check(withinSchedule({schedule:{starts:null,ends:null}}) === true, "unscheduled -> fires");
check(withinSchedule({schedule:{starts:now-10,ends:now+10}}) === true, "in window -> fires");
check(withinSchedule({schedule:{starts:now+10,ends:null}}) === false, "pending -> blocked");
check(withinSchedule({schedule:{starts:null,ends:now-10}}) === false, "expired -> blocked");
check(withinSchedule({schedule:{starts:null,ends:now}}) === false, "exactly at end -> blocked");
process.exit(f?1:0);
'
```
Expected: six `PASS:` lines, exit 0.

- [ ] **Step 5: Rebuild the minified asset**

Run: `node bin/build-assets.mjs`
Expected: `anchor-universal-popups/assets/frontend.min.js` listed as `built`, summary line reports `0 failed`.

`frontend.js` is cache-busted with `filemtime()`, so no version string needs bumping.

- [ ] **Step 6: Commit**

```bash
git add anchor-universal-popups/assets/frontend.js
git commit -m "feat(popups): cache-proof JS schedule gate on both UP_SNIPPETS passes"
```

(`*.min.js` is gitignored and regenerated at release; do not add it.)

---

### Task 5: Admin metabox + list column

**Files:**
- Modify: `anchor-universal-popups/anchor-universal-popups.php` — `admin_columns()` (line ~61), `admin_column_content()` (line ~73), `add_metaboxes()` (line ~476), and a new `render_box_schedule()` placed after `render_box_preview()` (line ~805)

**Interfaces:**
- Consumes: `Anchor_UP_Schedule::to_epoch()`, `::state()` from Task 1; `schedule_start` / `schedule_end` from Task 2.
- Produces: nothing consumed by later tasks.

The schedule state is **computed on read and never stored**. That is what keeps the "runtime gate, not status mutation" decision honest.

- [ ] **Step 1: Register the metabox**

In `add_metaboxes()`, after the existing `up_popup_settings` line and before `up_popup_preview`, add:

```php
        add_meta_box('up_popup_schedule', 'Schedule', [$this, 'render_box_schedule'], self::CPT, 'side');
```

The nonce field is already rendered by `render_box_settings()`, and `save_meta()` verifies it, so this box needs no nonce of its own.

- [ ] **Step 2: Add a shared state-label helper**

Add this private method directly above `admin_columns()`:

```php
    /**
     * Human-readable schedule state for a popup. Computed, never stored.
     * Returns [ string $state, string $label ].
     */
    private function schedule_status($post_id){
        $tz    = wp_timezone();
        $start = Anchor_UP_Schedule::to_epoch(get_post_meta($post_id, 'up_schedule_start', true), $tz);
        $end   = Anchor_UP_Schedule::to_epoch(get_post_meta($post_id, 'up_schedule_end', true), $tz);
        $state = Anchor_UP_Schedule::state($start, $end, time());

        switch ($state) {
            case 'pending':
                $label = sprintf('Scheduled · starts %s', wp_date('M j', $start));
                break;
            case 'active':
                $label = $end !== null
                    ? sprintf('Active · ends %s', wp_date('M j', $end))
                    : 'Active';
                break;
            case 'expired':
                $label = sprintf('Expired %s', wp_date('M j', $end));
                break;
            case 'invalid':
                $label = 'Invalid range';
                break;
            default:
                $label = '—';
        }
        return [$state, $label];
    }
```

- [ ] **Step 3: Add the list column**

In `admin_columns()`, change:

```php
            if ($k === 'title') {
                $new['up_shortcode'] = 'Shortcode';
                $new['up_mode'] = 'Mode';
            }
```

to:

```php
            if ($k === 'title') {
                $new['up_shortcode'] = 'Shortcode';
                $new['up_mode'] = 'Mode';
                $new['up_schedule'] = 'Schedule';
            }
```

In `admin_column_content()`, change:

```php
        } elseif ($column === 'up_mode') {
            $mode = get_post_meta($post_id, 'up_mode', true);
            if (in_array($mode, ['youtube', 'vimeo'], true)) $mode = 'video';
            echo esc_html(ucfirst($mode ?: 'html'));
        }
```

to:

```php
        } elseif ($column === 'up_mode') {
            $mode = get_post_meta($post_id, 'up_mode', true);
            if (in_array($mode, ['youtube', 'vimeo'], true)) $mode = 'video';
            echo esc_html(ucfirst($mode ?: 'html'));
        } elseif ($column === 'up_schedule') {
            list($state, $label) = $this->schedule_status($post_id);
            $dim = in_array($state, ['expired', 'invalid'], true);
            printf(
                '<span style="%s">%s</span>',
                $dim ? 'color:#b32d2e;' : '',
                esc_html($label)
            );
        }
```

- [ ] **Step 4: Render the metabox**

Add this method directly after `render_box_preview()`:

```php
    public function render_box_schedule($post){
        $m = $this->get_meta($post->ID);
        list($state, $label) = $this->schedule_status($post->ID);

        $badge_bg = '#f0f0f1';
        if ($state === 'active')  $badge_bg = '#d5e5d5';
        if ($state === 'pending') $badge_bg = '#fcf3d7';
        if ($state === 'expired' || $state === 'invalid') $badge_bg = '#f7d9d9';
        ?>
        <p style="margin-top:0;">
          <span style="display:inline-block;padding:3px 8px;border-radius:3px;background:<?php echo esc_attr($badge_bg); ?>;">
            <?php echo esc_html($label); ?>
          </span>
        </p>

        <p>
          <label for="up_schedule_start"><strong>Start</strong></label><br/>
          <input type="datetime-local" id="up_schedule_start" name="up_schedule_start"
                 value="<?php echo esc_attr($m['schedule_start']); ?>" style="width:100%;"/>
          <span class="description">Leave blank to go live immediately.</span>
        </p>

        <p>
          <label for="up_schedule_end"><strong>End</strong></label><br/>
          <input type="datetime-local" id="up_schedule_end" name="up_schedule_end"
                 value="<?php echo esc_attr($m['schedule_end']); ?>" style="width:100%;"/>
          <span class="description">Leave blank to run forever.</span>
        </p>

        <p class="description">
          Times use the site timezone (<?php echo esc_html(wp_timezone_string()); ?>).
          A schedule only ever hides a published popup — it never publishes a draft.
        </p>
        <?php
    }
```

- [ ] **Step 5: Verify syntax**

Run: `php -l anchor-universal-popups/anchor-universal-popups.php`
Expected: `No syntax errors detected`

Run: `php tests/up-schedule-test.php`
Expected: `ALL PASSED`

- [ ] **Step 6: Commit**

```bash
git add anchor-universal-popups/anchor-universal-popups.php
git commit -m "feat(popups): Schedule metabox + computed admin list column"
```

---

### Task 6: Full verification

**Files:** none modified.

- [ ] **Step 1: Static checks**

```bash
php -l anchor-universal-popups/anchor-universal-popups.php
php -l anchor-universal-popups/includes/class-up-schedule.php
php -l tests/up-schedule-test.php
node --check anchor-universal-popups/assets/frontend.js
php tests/up-schedule-test.php
node bin/build-assets.mjs
```
Expected: no syntax errors; `ALL PASSED`; build reports `0 failed`.

- [ ] **Step 2: Confirm no accidental cron, option, or migration crept in**

```bash
git diff main --stat
grep -rn "wp_schedule_event\|update_option\|add_option" anchor-universal-popups/
```
Expected: the diff touches only the four files named in this plan (plus the two docs). The grep returns **nothing** — the design adds no cron and no options.

- [ ] **Step 3: Confirm backward compatibility**

```bash
grep -n "'schedule_start' => ''" anchor-universal-popups/anchor-universal-popups.php
```
Expected: one hit. An existing popup has no `up_schedule_start` meta, so `get_meta()` falls back to `''`, which `to_epoch()` maps to `null`, which `state()` reports as `unscheduled` — always active, always shipped. **Zero behavior change for existing popups.**

- [ ] **Step 4: Manual verification in WordPress**

There is no WordPress test harness for this module, so the render path is verified by hand. Create three published popups:

| Popup | Start | End | Expected column | Expected frontend |
|---|---|---|---|---|
| A | blank | blank | `—` | fires (as today) |
| B | yesterday | tomorrow | `Active · ends <date>` | fires |
| C | last week | yesterday | `Expired <date>` (red) | does not fire |
| D | tomorrow | next week | `Scheduled · starts <date>` | does not fire |
| E | tomorrow | yesterday | `Invalid range` (red) | does not fire |

Then confirm the cache-proofing: with popup B live, load a page so it caches; edit B's end date to a moment in the past; reload the **cached** page (do not purge). B must not fire, even though the cached HTML still contains it — this is the JS gate doing its job.

Finally confirm draft-wins: set popup B to Draft while inside its window. It must not fire.

- [ ] **Step 5: Push and open the PR**

```bash
git push -u origin feature/popup-scheduling
gh pr create --base main --title "Popup date-range scheduling" \
  --body "Implements docs/superpowers/specs/2026-07-08-popup-scheduling-design.md"
```

---

## Notes for the implementer

- **Do not add a cron.** `events_manager` has `anchor_events_status_sweep`; this module deliberately has no equivalent. The runtime gate makes it unnecessary. If you find yourself reaching for `wp_schedule_event`, re-read decision 1 in the spec.
- **Do not mutate `post_status`.** Despite the feature being described colloquially as "auto-drafting", nothing in this plan writes a post status. The Schedule column is what makes a dormant popup legible in the admin list.
- **`now` is `time()`**, never `current_time('timestamp')` — the latter is local-shifted and would double-apply the timezone offset against epochs produced by `to_epoch()`.
- **Pre-existing bug, out of scope:** `save_meta()`'s `$fields` allowlist omits `'shortcode'`, so `up_shortcode` is never persisted even though the branch below it special-cases that key. Do not fix it here; it belongs in its own change.
