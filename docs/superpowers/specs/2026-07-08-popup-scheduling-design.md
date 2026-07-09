# Popup Scheduling — Design

**Date:** 2026-07-08
**Module:** `universal_popups` (`Anchor_Universal_Popups_Module`, CPT `anchor_popup`)
**Status:** Approved for planning

## Problem

Popups are all-or-nothing: a popup is either published and live, or drafted and
dark. Running a time-boxed promotion means an admin must remember to publish on
the start date and draft on the end date, by hand. We want a popup to go live
and go dormant on its own, driven by an optional date range.

## Decisions

Four decisions shape the design. Each was chosen deliberately over the
alternatives listed.

### 1. Runtime gate, not status mutation

Outside its window, a popup keeps `post_status = publish` in the database. It is
filtered out at render time instead.

*Rejected:* flipping `post_status` (`future` → `publish` → `draft`) via cron.
That mutates author intent, cannot distinguish "drafted by the schedule" from
"drafted by a human", and introduces up to a full sweep-interval of drift.

*Consequence:* no cron is required for correctness. This module adds no
scheduled events, unlike `events_manager`'s `anchor_events_status_sweep`.

*Consequence:* the admin list would otherwise show a dormant popup as plain
"Published" with no explanation, so we add a computed **Schedule** column.

### 2. Optional start + optional end. Nothing more.

Two datetime fields. Blank start means "live immediately"; blank end means "runs
forever"; both blank means unscheduled, i.e. today's behavior.

*Rejected:* recurring weekly windows (dayparting) and blackout dates. No
concrete use case today. YAGNI.

### 3. Draft always wins

The frontend query keeps `post_status => 'publish'`. A schedule can only ever
*subtract* from what is published; it can never publish a draft.

| `post_status` | Inside window | Outside window |
|---|---|---|
| `draft`   | never renders | never renders |
| `publish` | renders       | does not render |

*Rejected:* letting a start date publish a draft. That inverts the universal
WordPress expectation that Draft means not-live.

### 4. Two gates, because of full-page caching

The site runs behind Kinsta full-page caching. Popups reach the browser through
`wp_localize_script('up-frontend', 'UP_SNIPPETS', $snippets)`, which is inlined
into the cached HTML. A PHP-only gate would therefore bake the in/out decision
into the cache at generation time: a window could open or close without the
cached page noticing.

So responsibility splits:

- **PHP decides what ships.** Bounds payload size; never leaks long-dead popups.
- **JS decides what fires.** Authoritative at trigger time; correct on stale HTML.

*Rejected:* PHP-only gate (stale until purge). *Rejected:* purging the page
cache at window boundaries (reintroduces cron, and an all-pages purge is a blunt
instrument on a busy site).

## Architecture

### New unit: `Anchor_UP_Schedule`

`anchor-universal-popups/includes/class-up-schedule.php`

A static class with no database access and no global state. The timezone is
passed in as a parameter rather than read from `wp_timezone()` internally, which
keeps every method pure and unit-testable with no WordPress bootstrap — the same
approach as `tests/apd-css-builder-test.php`.

```php
to_epoch( ?string $local, DateTimeZone $tz ): ?int
state( ?int $start, ?int $end, int $now ): string
is_active( ?int $start, ?int $end, int $now ): bool
should_ship( ?int $start, ?int $end, int $now, int $grace ): bool
```

`state()` returns exactly one of:

| State | Meaning |
|---|---|
| `unscheduled` | both bounds absent |
| `pending`     | `now < start` |
| `active`      | within bounds |
| `expired`     | `now >= end` |
| `invalid`     | both bounds set and `start >= end` |

The states are mutually exclusive and evaluated in this order: `invalid` first,
then `unscheduled`, then `pending`, `expired`, `active`. Checking `invalid`
first matters — a reversed range would otherwise read as `expired`.

`to_epoch()` parses via `new DateTimeImmutable( $local, $tz )`, so the result is
DST-correct. It returns `null` for an empty or malformed string.

### Data model

Two post meta keys, both on the existing `up_` prefix:

- `up_schedule_start`
- `up_schedule_end`

Stored as the local `Y-m-d\TH:i` string that `<input type="datetime-local">`
produces natively. Empty string means unbounded on that side.

Both keys are added to `defaults()` — so `get_meta()` picks them up with no
further change — and to the `$fields` allowlist in `save_meta()`. They are
validated against the expected format on save rather than passed through bare
`sanitize_text_field()`; anything malformed is stored as `''` (unbounded).

Absent meta on existing popups reads as `''` → `unscheduled` → always ships,
always active. **No migration, and zero behavior change for existing popups.**

Storing the local wall-clock string (rather than a UTC epoch) means the schedule
follows the site timezone if it is later changed. That matches how WordPress
treats `post_date`, and matches what the admin typed.

### PHP gate — the cache envelope

In `get_published_popups()`, alongside the existing `is_excluded_for_request()`
filtering, drop any popup for which `should_ship()` is false:

```php
$grace = (int) apply_filters( 'anchor_popup_schedule_cache_grace', DAY_IN_SECONDS );
```

`should_ship()` is true when the popup has **not** expired more than `$grace`
ago, and does **not** start more than `$grace` from now. `invalid` never ships.

The envelope exists because of caching. A page cached at time *T* must already
contain a popup that starts at *T + 2h*, or the JS gate has nothing to reveal
when the window opens. `$grace` should therefore be at least the maximum
page-cache TTL. `DAY_IN_SECONDS` is a safe default; the filter exists for sites
with longer TTLs.

Each shipped snippet gains one key:

```php
'schedule' => [ 'starts' => ?int, 'ends' => ?int ]   // absolute UTC epoch seconds
```

Absolute UTC epochs mean the visitor's timezone is irrelevant. Only the
visitor's *clock skew* matters, which is immaterial at day-scale windows.

### JS gate — authoritative at fire time

In `anchor-universal-popups/assets/frontend.js`:

```js
function withinSchedule(sn){
  var s = sn.schedule || {};
  var now = Math.floor(Date.now() / 1000);
  if (s.starts && now <  s.starts) return false;
  if (s.ends   && now >= s.ends)   return false;
  return true;
}
```

Both passes over `UP_SNIPPETS` — the trigger-binding pass and the
`DOMContentLoaded` pass — bail early when this returns false. Because
`UP_SNIPPETS` is inlined in cached HTML, this guard is what makes a window open
and close correctly on a stale page.

### Admin surface

**Metabox.** A new side metabox `up_popup_schedule`, titled *Schedule*,
registered after the existing settings box. Two `datetime-local` inputs, help
text stating that a blank start means live immediately and a blank end means
runs forever, and a badge showing the current computed state.

Scheduling is given its own metabox rather than being appended to the existing
`Trigger, Frequency, Exclusions` box: that box is already dense, and
`anchor-universal-popups.php` is 1282 lines.

**List column.** A `Schedule` column after `Mode`, rendering the computed state:

```
—                       (unscheduled)
Scheduled · starts Jul 15
Active · ends Jul 31
Expired Jul 1
Invalid range
```

Computed on read, never persisted. This is what keeps decision (1) honest.

### Invalid ranges

`start >= end` with both set is `invalid`: never ships, never fires, flagged in
the list column. The save is **not** blocked — no data is lost, and the admin can
correct it. Blocking the save would risk discarding the rest of the edit.

## Files

| File | Change |
|---|---|
| `anchor-universal-popups/includes/class-up-schedule.php` | new — pure schedule logic |
| `anchor-universal-popups/anchor-universal-popups.php` | `require_once`; `defaults()`; `save_meta()`; `get_published_popups()`; `add_metaboxes()` + `render_box_schedule()`; `admin_columns()`; `admin_column_content()` |
| `anchor-universal-popups/assets/frontend.js` | `withinSchedule()` guard on both `UP_SNIPPETS` passes |
| `tests/up-schedule-test.php` | new — pure unit tests |

No changes to `anchor-tools.php`. No new options. No cron. No migration.

## Testing

`tests/up-schedule-test.php`, plain PHP assertions, run with
`php tests/up-schedule-test.php`, mirroring `tests/apd-css-builder-test.php`.
Because `Anchor_UP_Schedule` is pure and takes its timezone as a parameter, the
tests need no WordPress bootstrap.

Cases:

- unscheduled (both bounds empty) → `unscheduled`, active, ships
- open start (end only) → active before end, expired after
- open end (start only) → pending before start, active after
- both bounds → pending / active / expired across the three regions
- `start == end` and `start > end` → `invalid`, never active, never ships
- malformed input strings → `to_epoch()` returns `null`
- DST spring-forward and fall-back boundaries resolve to correct epochs
- envelope: expired exactly at `$grace` ships; one second past does not
- envelope: starts exactly at `$grace` ships; one second beyond does not

Manual verification, since there is no WordPress test harness: create a popup
with a window in the past, one in the future, one live; confirm the list column
states, confirm only the live one renders, then confirm a cached page whose
window has just closed no longer fires the popup.

## Out of scope

- Recurring / dayparting windows and blackout dates (decision 2).
- Any `post_status` mutation, and therefore any cron (decision 1).
- Pre-existing bug, noted while reading: `save_meta()`'s `$fields` allowlist
  omits `'shortcode'`, so `up_shortcode` is never persisted despite the
  special-case branch below it that expects the key. Unrelated to scheduling;
  left alone.
