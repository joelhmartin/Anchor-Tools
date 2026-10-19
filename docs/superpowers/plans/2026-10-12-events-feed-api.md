# Events Feed API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only REST feed (`anchor-events/v1/events`, `/categories`, `/me`) that the member app reads. It is built on the events module's existing query, schema, bookability and prerequisite logic.

**Architecture:** One new class, `Anchor\Events\Feed` (`anchor-events-manager/class-feed.php`). It owns row selection, row mapping and the three REST callbacks; `Module` constructs it like `Event_Schema`/`Entitlements` and exposes it as `$module->feed`. The month calendar's range query is extracted into `Module::range_query_args()`, so the calendar and the feed share one query builder. The new "App categories" setting lives in the existing `anchor_events_settings` option.

**Tech Stack:** PHP 7.4+ WordPress plugin, WP REST API, PHPUnit with the WP test lib (`Anchor_Events_TestCase`).

**Spec:** `docs/superpowers/specs/2026-10-12-events-feed-api-design.md` (read it first; its "Registration vocabulary" bullets are binding).

## Global Constraints

- Namespace: `Module::REST_NS` (`anchor-events/v1`). All new routes are `GET` only.
- Text domain `'anchor-schema'`. `update_option()` always passes `autoload=false` as the third argument.
- No personal data in any response: no rosters, seat counts, registrant data, emails or room/stream URLs.
- Error codes are exact: `anchor_events_bad_range` (400), `anchor_events_bad_category` (400), `anchor_events_not_signed_in` (401).
- `per_page` is 1–100 (default 50). `page` ≥ 1. The range may not exceed 24 months.
- Responses carry `Cache-Control: private, max-age=300` and a weak ETag `W/"<md5 of the JSON body>"`.
- Never re-implement: visibility = `Entitlements::meets_prerequisites( $id, 0 )`; row data = `Event_Schema::for_event()`; status = `Module::bookability()`; mode = `Module::registration_mode()`; timezone = `Module::event_timezone()`.
- The month calendar's HTML output must not change.
- Run tests with: `export WP_TESTS_DIR=$TMPDIR/wordpress-tests-lib WP_CORE_DIR=$TMPDIR/wordpress && vendor/bin/phpunit --group events-feed` (the full suite is `composer test`). If `vendor/bin/phpunit` errors on autoload, run `composer dump-autoload` and `git checkout -- vendor` before committing.

## Review Focus

- **A course that started before `from`.** A 3-day course that began yesterday must still appear (overlap, not start-in-range). Pinned in Task 2.
- **A group child with no categories of its own.** Filtering by `courses` must find a Pick-one date whose parent is in `courses`. Pinned in Task 2.
- **An admin calling the feed.** Staff bypass prerequisites (`meets_prerequisites( $id, 0 )`), so they see restricted rows marked `audience: restricted`, not a 500 or a missing event. Pinned in Task 4.
- **A sell-out between two app refreshes.** The ETag must change when a row's status changes, even though `clear_caches()` was not called. Pinned in Task 4.
- **A deleted category still saved in App categories.** It is ignored (not a 400, not "show nothing"). Pinned in Task 3.

---

## File Structure

| File | Responsibility |
|---|---|
| `anchor-events-manager/anchor-events-manager.php` | Modify: `range_query_args()` (extracted from `render_calendar_month()`); construct `Feed`; `register_rest_routes()` delegates to `Feed::register_routes()`; App categories settings field + sanitize + default. |
| `anchor-events-manager/class-feed.php` | Create: selection, mapping, REST callbacks. |
| `tests/test-events-feed-query.php` | Create: Tasks 1–2 tests (calendar regression, selection). |
| `tests/test-events-feed-rows.php` | Create: row mapping tests (Task 2). |
| `tests/test-events-feed-settings.php` | Create: App categories setting tests (Task 3). |
| `tests/test-events-feed-rest.php` | Create: REST tests (Task 4). |
| `anchor-events-manager/EVENTS.md` | Modify: "REST feed" section (Task 5). |

All new test classes carry `@group events-feed`.

---

### Task 1: Extract the range query

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php` (`render_calendar_month()`, about line 13987; add the new method directly above it)
- Test: `tests/test-events-feed-query.php`

**Interfaces:**
- Produces: `Module::range_query_args( int $start_ts, int $end_ts, bool $overlap = false ): array`. This returns WP_Query args for published events, with `fields` unset. `$overlap = false` is the calendar's form (`start_ts BETWEEN [start, end]`). `$overlap = true` is the feed's form (`start_ts <= end` AND (`end_ts >= start` OR (`end_ts` NOT EXISTS AND `start_ts >= start`))). Both include `build_hide_clause()` and order by `start_ts` ascending.
- Produces: `Module::event_ids_in_range( int $start_ts, int $end_ts, bool $overlap = false ): int[]`, i.e. `get_cached_ids( range_query_args(...) )`. The feed calls this; `get_cached_ids()` stays private.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Events feed — the shared range query (spec "Reuse", row 1).
 *
 * @package Anchor\Events\Tests
 */

/** @group events-feed */
class Test_Events_Feed_Query extends Anchor_Events_TestCase {

	private function dated( $title, $start, $end, array $meta = [] ) {
		$id = $this->make_event( array_merge( [
			'title'      => $title,
			'start_date' => substr( $start, 0, 10 ),
			'end_date'   => substr( $end, 0, 10 ),
			'start_time' => substr( $start, 11, 5 ),
			'end_time'   => substr( $end, 11, 5 ),
			'timezone'   => 'UTC',
			'start_ts'   => strtotime( $start . ' UTC' ),
			'end_ts'     => strtotime( $end . ' UTC' ),
		], $meta ) );
		return $id;
	}

	public function test_between_form_matches_start_in_range_only() {
		$in      = $this->dated( 'In', '2030-05-10 09:00', '2030-05-10 17:00' );
		$started = $this->dated( 'Started before', '2030-04-29 09:00', '2030-05-02 17:00' );
		$ids = $this->module()->event_ids_in_range( strtotime( '2030-05-01 UTC' ), strtotime( '2030-06-01 UTC' ) );
		$this->assertContains( $in, $ids );
		$this->assertNotContains( $started, $ids );
	}

	public function test_overlap_form_includes_event_that_started_before_range() {
		$started = $this->dated( 'Started before', '2030-04-29 09:00', '2030-05-02 17:00' );
		$ended   = $this->dated( 'Ended before', '2030-04-20 09:00', '2030-04-21 17:00' );
		$after   = $this->dated( 'After', '2030-06-05 09:00', '2030-06-05 17:00' );
		$ids = $this->module()->event_ids_in_range( strtotime( '2030-05-01 UTC' ), strtotime( '2030-06-01 UTC' ), true );
		$this->assertContains( $started, $ids );
		$this->assertNotContains( $ended, $ids );
		$this->assertNotContains( $after, $ids );
	}

	public function test_hidden_and_draft_events_are_excluded() {
		$hidden = $this->dated( 'Hidden', '2030-05-10 09:00', '2030-05-10 17:00', [ 'hide_from_archive' => '1' ] );
		$draft  = $this->dated( 'Draft', '2030-05-11 09:00', '2030-05-11 17:00' );
		wp_update_post( [ 'ID' => $draft, 'post_status' => 'draft' ] );
		$ids = $this->module()->event_ids_in_range( strtotime( '2030-05-01 UTC' ), strtotime( '2030-06-01 UTC' ), true );
		$this->assertNotContains( $hidden, $ids );
		$this->assertNotContains( $draft, $ids );
	}

	public function test_calendar_output_unchanged_by_extraction() {
		$this->dated( 'Calendar Course', '2030-05-10 09:00', '2030-05-10 17:00' );
		$html = do_shortcode( '[anchor_events_calendar month="2030-05"]' );
		$this->assertStringContainsString( 'Calendar Course', $html );
		$this->assertMatchesSnapshotOfCalendar( $html );
	}

	/**
	 * The calendar's markup before the extraction, captured in Step 2 and
	 * pasted into tests/fixtures/events-feed-calendar-2030-05.html.
	 */
	private function assertMatchesSnapshotOfCalendar( $html ) {
		$fixture = __DIR__ . '/fixtures/events-feed-calendar-2030-05.html';
		$normalise = static function ( $s ) {
			// Post IDs and nonces differ per run; compare structure + text.
			$s = preg_replace( '/\b\d{2,}\b/', 'N', $s );
			$s = preg_replace( '/value="[a-f0-9]{10}"/', 'value="NONCE"', $s );
			return trim( preg_replace( '/\s+/', ' ', $s ) );
		};
		$this->assertSame( $normalise( file_get_contents( $fixture ) ), $normalise( $html ) );
	}
}
```

- [ ] **Step 2: Capture the calendar snapshot BEFORE changing any code**

First confirm the calendar shortcode's real tag: `grep -n "add_shortcode" anchor-events-manager/anchor-events-manager.php`. Use whichever tag routes to `render_calendar_month()` (if it is not `anchor_events_calendar`, fix the tag in the test). Then write a throwaway test, or temporarily print `$html` from the regression test, run it on the unmodified code, and save the output to `tests/fixtures/events-feed-calendar-2030-05.html`.

Run: `vendor/bin/phpunit --group events-feed`
Expected: the calendar test PASSES against the fixture; the three `event_ids_in_range` tests FAIL with "Call to undefined method".

- [ ] **Step 3: Implement**

Add above `render_calendar_month()`:

```php
    /**
     * WP_Query args for published, listable events in a time range — the ONE
     * range query (feed spec "Reuse"). The month calendar uses the BETWEEN
     * form (an event belongs to the month it starts in); the app feed uses
     * the overlap form, so a course that started yesterday and runs through
     * tomorrow is still "on" today.
     *
     * Events with no end_ts are treated as instants at start_ts in the
     * overlap form rather than being dropped.
     *
     * @param int  $start_ts Range start (inclusive).
     * @param int  $end_ts   Range end.
     * @param bool $overlap  False = start_ts BETWEEN [start, end]; true = overlaps.
     * @return array
     */
    public function range_query_args( $start_ts, $end_ts, $overlap = false ) {
        $start_ts = (int) $start_ts;
        $end_ts   = (int) $end_ts;
        if ( $overlap ) {
            $range = [
                'relation' => 'AND',
                [ 'key' => $this->meta_key( 'start_ts' ), 'value' => $end_ts, 'compare' => '<=', 'type' => 'NUMERIC' ],
                [
                    'relation' => 'OR',
                    [ 'key' => $this->meta_key( 'end_ts' ), 'value' => $start_ts, 'compare' => '>=', 'type' => 'NUMERIC' ],
                    [
                        'relation' => 'AND',
                        [ 'key' => $this->meta_key( 'end_ts' ), 'compare' => 'NOT EXISTS' ],
                        [ 'key' => $this->meta_key( 'start_ts' ), 'value' => $start_ts, 'compare' => '>=', 'type' => 'NUMERIC' ],
                    ],
                ],
            ];
        } else {
            $range = [
                'key' => $this->meta_key( 'start_ts' ),
                'value' => [ $start_ts, $end_ts ],
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC',
            ];
        }

        return [
            'post_type' => self::CPT,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [ $range, $this->build_hide_clause() ],
            'orderby' => 'meta_value_num',
            'meta_key' => $this->meta_key( 'start_ts' ),
            'order' => 'ASC',
        ];
    }

    /**
     * Cached event IDs for range_query_args() — the feed's entry point.
     *
     * @return int[]
     */
    public function event_ids_in_range( $start_ts, $end_ts, $overlap = false ) {
        return array_map( 'intval', (array) $this->get_cached_ids( $this->range_query_args( $start_ts, $end_ts, $overlap ) ) );
    }
```

In `render_calendar_month()`, replace the `$meta_query = [...]` through `$args = [...];` block with:

```php
        $args = $this->range_query_args( $start, $end );
        if ( $show_past === 'no' ) {
            $args['meta_query'][] = $this->build_visibility_clause();
        }
```

Leave the following `apply_filters( 'anchor_events_query_args', ... )` and `get_cached_ids()` lines as they are. The array keys and their order are identical to the old literal, so the transient key (md5 of the args) and the output are unchanged.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --group events-feed` then `composer test`
Expected: all pass, including the existing calendar/cache suites.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php tests/test-events-feed-query.php tests/fixtures/events-feed-calendar-2030-05.html
git commit -m "refactor(events): extract range_query_args() from the month calendar"
```

---

### Task 2: `Feed` class — selection and row mapping

**Files:**
- Create: `anchor-events-manager/class-feed.php`
- Modify: `anchor-events-manager/anchor-events-manager.php` (constructor: `require_once $dir . 'class-feed.php';` after `class-entitlements.php`, and `$this->feed = new Feed( $this );` after `$this->entitlements = ...`; declare `public $feed;` next to the other public handles)
- Test: `tests/test-events-feed-query.php` (append), `tests/test-events-feed-rows.php`

**Interfaces:**
- Consumes: `Module::event_ids_in_range()` (Task 1).
- Produces:
  - `Feed::visible_ids( int $from_ts, int $to_ts, int[] $term_ids ): int[]`: overlap range, minus group parents, minus events the current user may not see, filtered to `$term_ids` when non-empty. Order is `start_ts` ASC, then ID ASC.
  - `Feed::category_terms( int $event_id ): WP_Term[]`: the event's `event_category` terms, or its parent's for a group child.
  - `Feed::row( int $event_id ): ?array`: the spec row, or `null` when the schema node is empty.
  - `Feed::is_restricted( int $event_id ): bool`: true when `required_roles` is non-empty.

- [ ] **Step 1: Write failing selection tests** (append to `Test_Events_Feed_Query`)

```php
	public function test_group_parent_never_selected_children_are() {
		$parent = $this->make_event( [ 'title' => 'Offering', 'timezone' => 'UTC', 'event_type' => 'offering' ] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-05-03', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'A', 'capacity' => 10 ],
			[ 'date' => '2030-05-10', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'B', 'capacity' => 10 ],
		] );
		$children = $this->module()->occurrences->reconcile( $parent );
		$ids = $this->module()->feed->visible_ids( strtotime( '2030-05-01 UTC' ), strtotime( '2030-06-01 UTC' ), [] );
		$this->assertNotContains( $parent, $ids );
		foreach ( $children as $child ) {
			$this->assertContains( (int) $child, $ids );
		}
	}

	public function test_child_filtered_by_parent_category() {
		$term   = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'courses' ] );
		$parent = $this->make_event( [ 'title' => 'Offering', 'timezone' => 'UTC', 'event_type' => 'offering' ] );
		wp_set_object_terms( $parent, [ $term ], 'event_category' );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-05-03', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'A', 'capacity' => 10 ],
		] );
		$children = $this->module()->occurrences->reconcile( $parent );
		$other = $this->dated( 'Uncategorised', '2030-05-04 09:00', '2030-05-04 10:00' );
		$ids = $this->module()->feed->visible_ids( strtotime( '2030-05-01 UTC' ), strtotime( '2030-06-01 UTC' ), [ $term ] );
		$this->assertSame( [ (int) $children[0] ], $ids );
		$this->assertNotContains( $other, $ids );
	}

	public function test_prerequisite_events_hidden_from_public_shown_to_role() {
		add_role( 'centre', 'Centre', [ 'read' => true ] );
		$gated  = $this->dated( 'Centres only', '2030-05-10 09:00', '2030-05-10 17:00', [ 'required_roles' => [ 'centre' ] ] );
		$public = $this->dated( 'Open course', '2030-05-11 09:00', '2030-05-11 17:00' );
		$range  = [ strtotime( '2030-05-01 UTC' ), strtotime( '2030-06-01 UTC' ), [] ];

		wp_set_current_user( 0 );
		$ids = $this->module()->feed->visible_ids( ...$range );
		$this->assertNotContains( $gated, $ids );
		$this->assertContains( $public, $ids );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' === get_role( 'customer' ) ? 'customer' : 'subscriber' ] ) );
		$this->assertNotContains( $gated, $this->module()->feed->visible_ids( ...$range ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'centre' ] ) );
		$this->assertContains( $gated, $this->module()->feed->visible_ids( ...$range ) );
		remove_role( 'centre' );
	}

	public function test_same_start_orders_by_id() {
		$a = $this->dated( 'A', '2030-05-10 09:00', '2030-05-10 17:00' );
		$b = $this->dated( 'B', '2030-05-10 09:00', '2030-05-10 17:00' );
		$ids = $this->module()->feed->visible_ids( strtotime( '2030-05-01 UTC' ), strtotime( '2030-06-01 UTC' ), [] );
		$this->assertSame( [ $a, $b ], array_values( array_intersect( $ids, [ $a, $b ] ) ) );
	}
```

Fix the customer line while implementing: use `'subscriber'` (always present) instead of the conditional.

Verify the occurrence meta key names against `tests/test-occurrences.php::make_parent()` and the `event_type` meta used there. If a reconcile needs other meta (for example `event_type = offering`), copy what `make_parent()` does.

- [ ] **Step 2: Write failing row tests** (`tests/test-events-feed-rows.php`)

```php
<?php
/**
 * Events feed — row mapping (spec "Row" + "Registration vocabulary").
 *
 * @package Anchor\Events\Tests
 */

/** @group events-feed */
class Test_Events_Feed_Rows extends Anchor_Events_TestCase {

	private function event( array $meta = [], array $tiers = [] ) {
		$id = $this->make_event( array_merge( [
			'title'           => 'Mini Residency &#8211; Toronto',
			'start_date'      => '2030-11-13',
			'end_date'        => '2030-11-15',
			'start_time'      => '08:00',
			'end_time'        => '17:00',
			'timezone'        => 'America/Toronto',
			'venue'           => 'Hilton',
			'address_city'    => 'Toronto',
			'address_state'   => 'ON',
			'address_country' => 'CA',
		], $meta ), $tiers );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $id ) );
		update_post_meta( $id, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $id, '_anchor_event_end_ts', $ts['end'] );
		return $id;
	}

	public function test_row_core_fields_come_from_schema_node() {
		$id   = $this->event( [ 'registration_mode' => 'free' ] );
		$node = $this->module()->event_schema->for_event( $id );
		$row  = $this->module()->feed->row( $id );

		$this->assertSame( $id, $row['id'] );
		$this->assertSame( 0, $row['parent_id'] );
		$this->assertSame( 'Mini Residency – Toronto', $row['title'] );
		$this->assertSame( $node['startDate'], $row['start'] );
		$this->assertSame( $node['endDate'], $row['end'] );
		$this->assertSame( '2030-11-13T08:00:00-05:00', $row['start'] );
		$this->assertFalse( $row['all_day'] );
		$this->assertSame( 'America/Toronto', $row['timezone'] );
		$this->assertSame( get_permalink( $id ), $row['url'] );
		$this->assertSame( get_permalink( $id ), $row['registration']['register_url'] );
		$this->assertSame( [ 'venue' => 'Hilton', 'city' => 'Toronto', 'state' => 'ON', 'country' => 'CA', 'online' => false ], $row['location'] );
		$this->assertSame( 'public', $row['audience'] );
	}

	public function test_summary_is_plain_text_and_capped() {
		$id = $this->event();
		wp_update_post( [ 'ID' => $id, 'post_excerpt' => '<b>Bold</b> ' . str_repeat( 'word ', 100 ) ] );
		$row = $this->module()->feed->row( $id );
		$this->assertStringNotContainsString( '<', $row['summary'] );
		$this->assertLessThanOrEqual( 300, mb_strlen( $row['summary'] ) );
		$this->assertStringStartsWith( 'Bold word', $row['summary'] );
	}

	public function test_registration_mode_and_status_vocabulary() {
		$free = $this->event( [ 'registration_mode' => 'free' ] );
		$row  = $this->module()->feed->row( $free );
		$this->assertSame( 'free', $row['registration']['mode'] );
		$this->assertSame( 'open', $row['registration']['status'] );

		$full = $this->event( [ 'registration_mode' => 'free', 'capacity' => 1 ] );
		$this->make_seat( $full );
		$this->assertSame( 'full', $this->module()->bookability( $full ) );
		$this->assertSame( 'sold_out', $this->module()->feed->row( $full )['registration']['status'] );

		$off = $this->event( [ 'registration_mode' => 'free', 'registration_enabled' => false ] );
		$this->assertSame( 'unavailable', $this->module()->feed->row( $off )['registration']['status'] );

		$ext = $this->event( [ 'registration_mode' => 'external', 'registration_url' => 'https://example.test/form', 'registration_enabled' => false ] );
		$row = $this->module()->feed->row( $ext );
		$this->assertSame( 'external', $row['registration']['mode'] );
		$this->assertSame( 'open', $row['registration']['status'] );
		$this->assertSame( get_permalink( $ext ), $row['registration']['register_url'] );
	}

	public function test_status_matches_bookability_mapping_table() {
		$feed = $this->module()->feed;
		$this->assertSame( 'open', $feed->map_status( 'open', 'free' ) );
		$this->assertSame( 'waitlist', $feed->map_status( 'waitlist', 'wc' ) );
		$this->assertSame( 'sold_out', $feed->map_status( 'full', 'wc' ) );
		$this->assertSame( 'unavailable', $feed->map_status( 'disabled', 'free' ) );
		$this->assertSame( 'closed', $feed->map_status( 'closed', 'free' ) );
		$this->assertSame( 'closed', $feed->map_status( 'prerequisite', 'free' ) );
		$this->assertSame( 'open', $feed->map_status( 'disabled', 'external' ) );
		$this->assertSame( 'closed', $feed->map_status( 'closed', 'external' ) );
	}

	public function test_paid_price_is_lowest_offer() {
		$this->require_wc();
		$id = $this->event( [ 'registration_mode' => 'wc' ], [
			[ 'label' => 'Early', 'price' => 950, 'active' => true ],
			[ 'label' => 'Late', 'price' => 1200, 'active' => true ],
		] );
		$row = $this->module()->feed->row( $id );
		$this->assertSame( 'paid', $row['registration']['mode'] );
		$this->assertSame( '950.00', $row['registration']['price'] );
		$this->assertSame( get_woocommerce_currency(), $row['registration']['currency'] );
	}

	public function test_closed_event_has_null_price() {
		$id = $this->event( [ 'registration_mode' => 'free', 'status_mode' => 'manual', 'status' => 'cancelled' ] );
		$row = $this->module()->feed->row( $id );
		$this->assertSame( 'closed', $row['registration']['status'] );
		$this->assertNull( $row['registration']['price'] );
		$this->assertNull( $row['registration']['currency'] );
	}

	public function test_online_event_never_leaks_room_url() {
		$id = $this->event( [ 'virtual' => true, 'venue' => '', 'address_city' => '', 'address_state' => '', 'address_country' => '', 'virtual_url' => 'https://zoom.example/secret' ] );
		$row = $this->module()->feed->row( $id );
		$this->assertTrue( $row['location']['online'] );
		$this->assertStringNotContainsString( 'zoom.example', wp_json_encode( $row ) );
	}

	public function test_all_day_dates_are_date_only() {
		$id  = $this->event( [ 'all_day' => true ] );
		$row = $this->module()->feed->row( $id );
		$this->assertTrue( $row['all_day'] );
		$this->assertSame( '2030-11-13', $row['start'] );
	}

	public function test_restricted_audience_and_categories() {
		$term = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'centre-events', 'name' => 'Centre Events' ] );
		$id   = $this->event( [ 'required_roles' => [ 'centre' ] ] );
		wp_set_object_terms( $id, [ $term ], 'event_category' );
		$row = $this->module()->feed->row( $id );
		$this->assertSame( 'restricted', $row['audience'] );
		$this->assertSame( [ [ 'slug' => 'centre-events', 'name' => 'Centre Events' ] ], $row['categories'] );
	}

	public function test_event_without_start_returns_null() {
		$id = $this->make_event( [ 'title' => 'Undated' ] );
		$this->assertNull( $this->module()->feed->row( $id ) );
	}
}
```

The meta keys for virtual URL, status pinning and cancelled state are best guesses. Before running, check them against `get_meta_defaults()` (grep `'virtual'`, `'status_mode'`, the stream URL key) and fix them in the test. The test's purpose (no room URL anywhere in the row; a cancelled event reads closed with no price) is the requirement. If a ticket-tier row needs more keys for `Ticket_Types::save()`, copy them from `tests/test-capacity.php`.

- [ ] **Step 3: Run to verify they fail**

Run: `vendor/bin/phpunit --group events-feed`
Expected: FAIL; `$module->feed` is null / class `Anchor\Events\Feed` not found.

- [ ] **Step 4: Implement `class-feed.php`**

```php
<?php
/**
 * Read-only REST feed for the member app (spec 2026-10-12-events-feed-api).
 *
 * The app mirrors the site, so this class decides nothing new: which events
 * exist in a range is Module::range_query_args(); what a row says is
 * Event_Schema::for_event(); who may see a gated event is
 * Entitlements::meets_prerequisites(); registration state is
 * Module::bookability(). It only selects, maps and serves.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Feed {

    const SUMMARY_MAX = 300;

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
    }

    /* ── Selection ─────────────────────────────────────────────── */

    /**
     * Event IDs the current user may see in [from, to], one per actual date.
     *
     * @param int   $from_ts
     * @param int   $to_ts
     * @param int[] $term_ids event_category term IDs; [] = no filter.
     * @return int[]
     */
    public function visible_ids( $from_ts, $to_ts, array $term_ids ) {
        $term_ids = array_map( 'intval', $term_ids );
        $rows     = [];
        foreach ( $this->module->event_ids_in_range( $from_ts, $to_ts, true ) as $id ) {
            if ( $this->is_container( $id ) ) {
                continue;
            }
            if ( ! $this->module->entitlements->meets_prerequisites( $id, 0 ) ) {
                continue;
            }
            if ( $term_ids && ! array_intersect( $term_ids, wp_list_pluck( $this->category_terms( $id ), 'term_id' ) ) ) {
                continue;
            }
            $rows[] = [ (int) get_post_meta( $id, $this->module->meta_key( 'start_ts' ), true ), $id ];
        }
        usort( $rows, static function ( $a, $b ) {
            return [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ];
        } );
        return array_column( $rows, 1 );
    }

    /**
     * A group parent (or an offering/recurring event not yet reconciled) is a
     * container, never a date — the feed's rows are its children.
     */
    private function is_container( $id ) {
        $occ = $this->module->occurrences;
        if ( $occ->is_group_child( $id ) ) {
            return false;
        }
        return $occ->is_group_parent( $id ) || in_array( $this->module->event_type( $id ), [ 'offering', 'recurring' ], true );
    }

    /**
     * The categories a row belongs to. Reconcile does not copy event_category
     * to children, so a child answers with its parent's terms.
     *
     * @return \WP_Term[]
     */
    public function category_terms( $event_id ) {
        $source = (int) $event_id;
        if ( $this->module->occurrences->is_group_child( $source ) ) {
            $parent = (int) $this->module->occurrences->parent_of( $source );
            if ( $parent > 0 ) {
                $source = $parent;
            }
        }
        $terms = get_the_terms( $source, 'event_category' );
        return is_array( $terms ) ? array_values( $terms ) : [];
    }

    public function is_restricted( $event_id ) {
        $meta = $this->module->get_meta( (int) $event_id );
        return ! empty( $meta['required_roles'] ) && is_array( $meta['required_roles'] );
    }

    /* ── Mapping ───────────────────────────────────────────────── */

    /**
     * One feed row, or null when the event has no usable schema node.
     *
     * @return array|null
     */
    public function row( $event_id ) {
        $id   = (int) $event_id;
        $node = $this->module->event_schema->for_event( $id );
        if ( empty( $node['startDate'] ) ) {
            return null;
        }
        $meta = $this->module->get_meta( $id );
        $mode = (string) $this->module->registration_mode( $id );
        list( $price, $currency ) = $this->price( $node['offers'] ?? [] );

        return [
            'id'           => $id,
            'parent_id'    => $this->module->occurrences->is_group_child( $id ) ? (int) $this->module->occurrences->parent_of( $id ) : 0,
            'title'        => $this->plain( $node['name'] ?? get_the_title( $id ) ),
            'summary'      => $this->summary( $node['description'] ?? '' ),
            'url'          => (string) get_permalink( $id ),
            'image'        => isset( $node['image'] ) && is_string( $node['image'] ) ? $node['image'] : null,
            'categories'   => array_map( static function ( $t ) {
                return [ 'slug' => (string) $t->slug, 'name' => html_entity_decode( (string) $t->name, ENT_QUOTES, 'UTF-8' ) ];
            }, $this->category_terms( $id ) ),
            'start'        => (string) $node['startDate'],
            'end'          => (string) ( $node['endDate'] ?? $node['startDate'] ),
            'all_day'      => ! empty( $meta['all_day'] ),
            'timezone'     => $this->module->event_timezone( $meta )->getName(),
            'location'     => $this->location( $node ),
            'registration' => [
                'mode'         => [ 'wc' => 'paid', 'free' => 'free', 'external' => 'external' ][ $mode ] ?? 'free',
                'status'       => $this->map_status( (string) $this->module->bookability( $id ), $mode ),
                'price'        => $price,
                'currency'     => $currency,
                'register_url' => (string) get_permalink( $id ),
            ],
            'audience'     => $this->is_restricted( $id ) ? 'restricted' : 'public',
        ];
    }

    /**
     * bookability() → the feed's status vocabulary (spec "Registration
     * vocabulary"). An external-signup event takes sign-ups elsewhere, so the
     * native switch/seat states say nothing about it — only "closed" does.
     */
    public function map_status( $bookability, $mode ) {
        if ( $mode === 'external' ) {
            return $bookability === 'closed' ? 'closed' : 'open';
        }
        $map = [ 'open' => 'open', 'waitlist' => 'waitlist', 'full' => 'sold_out', 'disabled' => 'unavailable' ];
        return $map[ $bookability ] ?? 'closed';
    }

    /** @return array{0:?string,1:?string} Lowest offer price + its currency. */
    private function price( $offers ) {
        if ( isset( $offers['@type'] ) ) {
            $offers = [ $offers ];
        }
        $best = null;
        foreach ( (array) $offers as $offer ) {
            if ( ! is_array( $offer ) || ! isset( $offer['price'] ) || ! is_numeric( $offer['price'] ) ) {
                continue;
            }
            if ( $best === null || (float) $offer['price'] < (float) $best['price'] ) {
                $best = $offer;
            }
        }
        if ( $best === null ) {
            return [ null, null ];
        }
        return [ number_format( (float) $best['price'], 2, '.', '' ), isset( $best['priceCurrency'] ) ? (string) $best['priceCurrency'] : null ];
    }

    /** Venue/city/state/country from the node's Place; online from its VirtualLocation. Never the URL. */
    private function location( array $node ) {
        $loc   = $node['location'] ?? [];
        $nodes = isset( $loc['@type'] ) ? [ $loc ] : (array) $loc;
        $out   = [ 'venue' => '', 'city' => '', 'state' => '', 'country' => '', 'online' => false ];
        foreach ( $nodes as $n ) {
            if ( ! is_array( $n ) ) {
                continue;
            }
            if ( ( $n['@type'] ?? '' ) === 'VirtualLocation' ) {
                $out['online'] = true;
            } elseif ( ( $n['@type'] ?? '' ) === 'Place' ) {
                $out['venue']   = $this->plain( $n['name'] ?? '' );
                $out['city']    = (string) ( $n['address']['addressLocality'] ?? '' );
                $out['state']   = (string) ( $n['address']['addressRegion'] ?? '' );
                $out['country'] = (string) ( $n['address']['addressCountry'] ?? '' );
            }
        }
        return $out;
    }

    private function plain( $text ) {
        return trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
    }

    private function summary( $text ) {
        $text = $this->plain( $text );
        if ( mb_strlen( $text ) <= self::SUMMARY_MAX ) {
            return $text;
        }
        return rtrim( mb_substr( $text, 0, self::SUMMARY_MAX - 1 ) ) . '…';
    }
}
```

`place_node()` falls back to the post title when there is no venue. If `test_row_core_fields_come_from_schema_node` passes with a venue, keep that fallback; the spec's "venue" is what the page shows.

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit --group events-feed` then `composer test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add anchor-events-manager/class-feed.php anchor-events-manager/anchor-events-manager.php tests/test-events-feed-query.php tests/test-events-feed-rows.php
git commit -m "feat(events): Feed selection + row mapping for the member app"
```

---

### Task 3: App categories setting

**Files:**
- Modify: `anchor-events-manager/anchor-events-manager.php` (`default_settings()` about line 16693, `sanitize_settings()` about line 12174, `register_settings()` about line 11752)
- Modify: `anchor-events-manager/class-feed.php` (add `app_term_ids()`)
- Test: `tests/test-events-feed-settings.php`

**Interfaces:**
- Produces: setting key `app_categories` (int[] term IDs, default `[]`) in `anchor_events_settings`.
- Produces: `Feed::app_term_ids(): int[]`, the saved IDs that still name an existing `event_category` term.

- [ ] **Step 1: Write failing tests**

```php
<?php
/**
 * Events feed — the App categories setting (spec "App categories setting").
 *
 * @package Anchor\Events\Tests
 */

/** @group events-feed */
class Test_Events_Feed_Settings extends Anchor_Events_TestCase {

	public function test_default_is_empty() {
		delete_option( 'anchor_events_settings' );
		$this->assertSame( [], $this->module()->get_settings()['app_categories'] );
		$this->assertSame( [], $this->module()->feed->app_term_ids() );
	}

	public function test_sanitize_keeps_only_real_event_category_ids() {
		$cat   = self::factory()->term->create( [ 'taxonomy' => 'event_category' ] );
		$other = self::factory()->term->create( [ 'taxonomy' => 'category' ] );
		$out = $this->module()->sanitize_settings( [ 'app_categories' => [ (string) $cat, (string) $other, 'abc', '999999' ] ] );
		$this->assertSame( [ $cat ], $out['app_categories'] );
	}

	public function test_sanitize_missing_key_saves_empty() {
		$out = $this->module()->sanitize_settings( [] );
		$this->assertSame( [], $out['app_categories'] );
	}

	public function test_deleted_term_is_ignored_at_read_time() {
		$keep = self::factory()->term->create( [ 'taxonomy' => 'event_category' ] );
		$gone = self::factory()->term->create( [ 'taxonomy' => 'event_category' ] );
		update_option( 'anchor_events_settings', [ 'app_categories' => [ $keep, $gone ] ], false );
		wp_delete_term( $gone, 'event_category' );
		$this->assertSame( [ $keep ], $this->module()->feed->app_term_ids() );
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --group events-feed`
Expected: FAIL; undefined index `app_categories` / undefined method `app_term_ids`.

- [ ] **Step 3: Implement**

In `default_settings()` add `'app_categories' => [],`.

In `sanitize_settings()` add to `$output`:

```php
            // Member-app feed default filter (feed spec "App categories setting").
            // Term IDs, not slugs, so renaming a category doesn't silently drop it.
            'app_categories' => array_values( array_filter(
                array_map( 'absint', (array) ( $input['app_categories'] ?? [] ) ),
                static function ( $id ) {
                    $term = $id ? \get_term( $id, 'event_category' ) : null;
                    return $term instanceof \WP_Term;
                }
            ) ),
```

In `register_settings()`, after the `template_source` field:

```php
        \add_settings_field( 'app_categories', __( 'App categories', 'anchor-schema' ), function() {
            $selected = array_map( 'intval', (array) $this->get_settings()['app_categories'] );
            $terms    = \get_terms( [ 'taxonomy' => 'event_category', 'hide_empty' => false ] );
            if ( empty( $terms ) || \is_wp_error( $terms ) ) {
                echo '<p class="description">' . esc_html__( 'No event categories yet.', 'anchor-schema' ) . '</p>';
                return;
            }
            echo '<fieldset>';
            foreach ( $terms as $term ) {
                printf(
                    '<label style="display:block"><input type="checkbox" name="%1$s[app_categories][]" value="%2$d" %3$s /> %4$s</label>',
                    esc_attr( self::OPTION_KEY ),
                    (int) $term->term_id,
                    checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
                    esc_html( $term->name )
                );
            }
            echo '</fieldset><p class="description">' . esc_html__( 'Categories the member app shows. Leave empty to show all.', 'anchor-schema' ) . '</p>';
        }, 'anchor_events_settings', 'anchor_events_main' );
```

In `class-feed.php`:

```php
    /**
     * The App categories setting, minus terms deleted since it was saved.
     *
     * @return int[]
     */
    public function app_term_ids() {
        $ids = array_map( 'intval', (array) ( $this->module->get_settings()['app_categories'] ?? [] ) );
        return array_values( array_filter( $ids, static function ( $id ) {
            return $id > 0 && get_term( $id, 'event_category' ) instanceof \WP_Term;
        } ) );
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --group events-feed` then `composer test`
Expected: all pass, including any existing settings-sanitize tests (`grep -ln sanitize_settings tests/`).

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/anchor-events-manager.php anchor-events-manager/class-feed.php tests/test-events-feed-settings.php
git commit -m "feat(events): App categories setting for the member-app feed"
```

---

### Task 4: REST routes `/events`, `/categories`, `/me`

**Files:**
- Modify: `anchor-events-manager/class-feed.php` (routes + callbacks)
- Modify: `anchor-events-manager/anchor-events-manager.php` (`register_rest_routes()`: append `$this->feed->register_routes();`)
- Test: `tests/test-events-feed-rest.php`

**Interfaces:**
- Consumes: `visible_ids()`, `row()`, `app_term_ids()`, `category_terms()`, `is_restricted()`.
- Produces: `Feed::register_routes(): void`, `Feed::rest_events( WP_REST_Request )`, `Feed::rest_categories( WP_REST_Request )`, `Feed::rest_me( WP_REST_Request )`.

Behaviour (from the spec):
- **`/events`:**
  - `from` defaults to `wp_date( 'Y-m-d' )`; `to` defaults to `from` + 12 months.
  - Both must match `YYYY-MM-DD` and be real dates. `to >= from` and `to <= from + 24 months`, otherwise 400 `anchor_events_bad_range`.
  - The window is `[to_timestamp( from, '00:00', '' ), to_timestamp( to + 1 day, '00:00', '' ) - 1]`, in the site zone.
  - `category`: comma-separated slugs → term IDs. An unknown slug gives 400 `anchor_events_bad_category`. Missing or empty uses `app_term_ids()`.
  - Paging: `page` ≥ 1, `per_page` 1–100 (default 50). WP arg schema clamps or rejects them.
  - Rows with a null `row()` are skipped *before* paging, so `total` counts real rows.
  - Body is `{events, page, per_page, total, total_pages}`. Headers: `X-WP-Total`, `X-WP-TotalPages`, `Cache-Control`, `ETag`. A matching `If-None-Match` returns 304 with an empty body.
- **`/categories`:** builds rows for the default window (today to +12 months) limited to `app_term_ids()`, then lists terms with counts. Only terms that appear on a visible row are listed, sorted by name, with the same cache headers.
- **`/me`:**
  - Signed out: 401 `anchor_events_not_signed_in`.
  - Signed in: `{ name: display_name, sees_restricted }`. `sees_restricted` is true when any row in `visible_ids( today, today + 24 months, [] )` passes `is_restricted()`.
  - Same cache headers.
- **Permissions:** all three routes use `permission_callback => '__return_true'` (`/me` refuses inside the callback so the error code is ours). Core resolves Application Password auth before the callback.

- [ ] **Step 1: Write failing tests**

```php
<?php
/**
 * Events feed — REST routes (spec "Endpoints", "Caching", "Errors").
 *
 * @package Anchor\Events\Tests
 */

/** @group events-feed */
class Test_Events_Feed_Rest extends Anchor_Events_TestCase {

	/** @var WP_REST_Server */
	private $server;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
		add_role( 'centre', 'Centre', [ 'read' => true ] );
	}

	public function tear_down() {
		remove_role( 'centre' );
		parent::tear_down();
	}

	private function get( $route, array $params = [], array $headers = [] ) {
		$request = new WP_REST_Request( 'GET', '/anchor-events/v1' . $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		foreach ( $headers as $k => $v ) {
			$request->set_header( $k, $v );
		}
		return $this->server->dispatch( $request );
	}

	private function upcoming( $title, $days_ahead, array $meta = [] ) {
		$date = wp_date( 'Y-m-d', time() + $days_ahead * DAY_IN_SECONDS );
		$id = $this->make_event( array_merge( [
			'title' => $title, 'start_date' => $date, 'end_date' => $date,
			'start_time' => '09:00', 'end_time' => '17:00', 'registration_mode' => 'free',
		], $meta ) );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $id ) );
		update_post_meta( $id, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $id, '_anchor_event_end_ts', $ts['end'] );
		return $id;
	}

	public function test_events_default_window_and_shape() {
		$soon = $this->upcoming( 'Soon', 10 );
		$far  = $this->upcoming( 'Far', 500 );
		$res  = $this->get( '/events' );
		$this->assertSame( 200, $res->get_status() );
		$ids = wp_list_pluck( $res->get_data()['events'], 'id' );
		$this->assertContains( $soon, $ids );
		$this->assertNotContains( $far, $ids );
		$this->assertSame( 'private, max-age=300', $res->get_headers()['Cache-Control'] );
		$this->assertMatchesRegularExpression( '/^W\/"[a-f0-9]{32}"$/', $res->get_headers()['ETag'] );
		$this->assertSame( count( $ids ), (int) $res->get_headers()['X-WP-Total'] );
	}

	public function test_bad_range_errors() {
		foreach ( [
			[ 'from' => '2030-13-01' ],
			[ 'from' => 'tomorrow' ],
			[ 'from' => '2030-05-01', 'to' => '2030-04-01' ],
			[ 'from' => '2030-01-01', 'to' => '2032-01-02' ],
		] as $params ) {
			$res = $this->get( '/events', $params );
			$this->assertSame( 400, $res->get_status(), wp_json_encode( $params ) );
			$this->assertSame( 'anchor_events_bad_range', $res->get_data()['code'] );
		}
	}

	public function test_unknown_category_errors() {
		$res = $this->get( '/events', [ 'category' => 'nope' ] );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'anchor_events_bad_category', $res->get_data()['code'] );
	}

	public function test_app_categories_default_and_explicit_override() {
		$courses = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'courses' ] );
		$socials = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'socials' ] );
		$a = $this->upcoming( 'Course', 5 );
		$b = $this->upcoming( 'Social', 6 );
		wp_set_object_terms( $a, [ $courses ], 'event_category' );
		wp_set_object_terms( $b, [ $socials ], 'event_category' );

		update_option( 'anchor_events_settings', [ 'app_categories' => [ $courses ] ], false );
		$this->assertSame( [ $a ], wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' ) );
		$this->assertSame( [ $b ], wp_list_pluck( $this->get( '/events', [ 'category' => 'socials' ] )->get_data()['events'], 'id' ) );

		update_option( 'anchor_events_settings', [ 'app_categories' => [] ], false );
		$this->assertEqualsCanonicalizing( [ $a, $b ], wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' ) );
	}

	public function test_pagination() {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->upcoming( "E$i", $i );
		}
		$res = $this->get( '/events', [ 'per_page' => 2, 'page' => 2 ] );
		$data = $res->get_data();
		$this->assertCount( 1, $data['events'] );
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 2, $data['total_pages'] );
		$this->assertSame( 400, $this->get( '/events', [ 'per_page' => 101 ] )->get_status() );
	}

	public function test_restricted_visibility_through_rest() {
		$gated = $this->upcoming( 'Centres only', 7, [ 'required_roles' => [ 'centre' ] ] );
		wp_set_current_user( 0 );
		$this->assertNotContains( $gated, wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'centre' ] ) );
		$rows = $this->get( '/events' )->get_data()['events'];
		$this->assertContains( $gated, wp_list_pluck( $rows, 'id' ) );
		$this->assertSame( 'restricted', wp_list_filter( $rows, [ 'id' => $gated ] )[ array_key_first( wp_list_filter( $rows, [ 'id' => $gated ] ) ) ]['audience'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertContains( $gated, wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' ), 'staff bypass prerequisites' );
	}

	public function test_etag_304_and_changes_on_sell_out() {
		$id   = $this->upcoming( 'Small', 3, [ 'capacity' => 1 ] );
		$etag = $this->get( '/events' )->get_headers()['ETag'];

		$again = $this->get( '/events', [], [ 'If-None-Match' => $etag ] );
		$this->assertSame( 304, $again->get_status() );
		$this->assertNull( $again->get_data() );

		$this->make_seat( $id ); // sells out; clear_caches() is NOT called
		$after = $this->get( '/events', [], [ 'If-None-Match' => $etag ] );
		$this->assertSame( 200, $after->get_status() );
		$this->assertNotSame( $etag, $after->get_headers()['ETag'] );
	}

	public function test_categories_hide_categories_with_only_gated_events_from_public() {
		$open   = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'courses', 'name' => 'Courses' ] );
		$secret = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'centre-only', 'name' => 'Centre only' ] );
		wp_set_object_terms( $this->upcoming( 'Course', 4 ), [ $open ], 'event_category' );
		wp_set_object_terms( $this->upcoming( 'Gated', 4, [ 'required_roles' => [ 'centre' ] ] ), [ $secret ], 'event_category' );

		wp_set_current_user( 0 );
		$this->assertSame( [ [ 'slug' => 'courses', 'name' => 'Courses', 'count' => 1 ] ], $this->get( '/categories' )->get_data()['categories'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'centre' ] ) );
		$this->assertCount( 2, $this->get( '/categories' )->get_data()['categories'] );
	}

	public function test_me() {
		wp_set_current_user( 0 );
		$res = $this->get( '/me' );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'anchor_events_not_signed_in', $res->get_data()['code'] );

		$this->upcoming( 'Gated', 9, [ 'required_roles' => [ 'centre' ] ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'centre', 'display_name' => 'Dr Centre' ] ) );
		$this->assertSame( [ 'name' => 'Dr Centre', 'sees_restricted' => true ], $this->get( '/me' )->get_data() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Pat' ] ) );
		$this->assertSame( [ 'name' => 'Pat', 'sees_restricted' => false ], $this->get( '/me' )->get_data() );
	}

	public function test_no_personal_data_in_rows() {
		$id = $this->upcoming( 'With seats', 3 );
		$this->make_seat( $id, [ 'email' => 'private@example.test', 'name' => 'Secret Person' ] );
		$json = wp_json_encode( $this->get( '/events' )->get_data() );
		$this->assertStringNotContainsString( 'private@example.test', $json );
		$this->assertStringNotContainsString( 'Secret Person', $json );
	}
}
```

Simplify the `audience` assertion in `test_restricted_visibility_through_rest` while implementing (find the row with a `foreach`). The logic is what matters: the row's `audience` is `restricted`.

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --group events-feed`
Expected: FAIL; 404 `rest_no_route`.

- [ ] **Step 3: Implement** (append to `class-feed.php`)

```php
    /* ── REST ──────────────────────────────────────────────────── */

    const DEFAULT_MONTHS = 12;
    const MAX_MONTHS     = 24;

    public function register_routes() {
        $public = '__return_true';
        register_rest_route( Module::REST_NS, '/events', [
            'methods'             => 'GET',
            'permission_callback' => $public,
            'callback'            => [ $this, 'rest_events' ],
            'args'                => [
                'from'     => [ 'type' => 'string' ],
                'to'       => [ 'type' => 'string' ],
                'category' => [ 'type' => 'string' ],
                'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'per_page' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            ],
        ] );
        register_rest_route( Module::REST_NS, '/categories', [
            'methods'             => 'GET',
            'permission_callback' => $public,
            'callback'            => [ $this, 'rest_categories' ],
        ] );
        register_rest_route( Module::REST_NS, '/me', [
            'methods'             => 'GET',
            // Refused inside the callback so the 401 carries our own code.
            'permission_callback' => $public,
            'callback'            => [ $this, 'rest_me' ],
        ] );
    }

    public function rest_events( \WP_REST_Request $request ) {
        $range = $this->parse_range( $request['from'], $request['to'] );
        if ( is_wp_error( $range ) ) {
            return $range;
        }
        $terms = $this->parse_categories( $request['category'] );
        if ( is_wp_error( $terms ) ) {
            return $terms;
        }

        $rows     = $this->rows( $this->visible_ids( $range[0], $range[1], $terms ) );
        $per_page = (int) $request['per_page'];
        $page     = (int) $request['page'];
        $total    = count( $rows );
        $pages    = (int) ceil( $total / $per_page );

        $response = $this->respond( $request, [
            'events'      => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $pages,
        ] );
        $response->header( 'X-WP-Total', (string) $total );
        $response->header( 'X-WP-TotalPages', (string) $pages );
        return $response;
    }

    public function rest_categories( \WP_REST_Request $request ) {
        $range  = $this->parse_range( null, null );
        $counts = [];
        $terms  = [];
        foreach ( $this->visible_ids( $range[0], $range[1], $this->app_term_ids() ) as $id ) {
            foreach ( $this->category_terms( $id ) as $term ) {
                $counts[ $term->term_id ] = ( $counts[ $term->term_id ] ?? 0 ) + 1;
                $terms[ $term->term_id ]  = $term;
            }
        }
        $allowed = $this->app_term_ids();
        $out     = [];
        foreach ( $terms as $term_id => $term ) {
            if ( $allowed && ! in_array( (int) $term_id, $allowed, true ) ) {
                continue;
            }
            $out[] = [ 'slug' => (string) $term->slug, 'name' => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ), 'count' => (int) $counts[ $term_id ] ];
        }
        usort( $out, static function ( $a, $b ) {
            return strcasecmp( $a['name'], $b['name'] );
        } );
        return $this->respond( $request, [ 'categories' => $out ] );
    }

    public function rest_me( \WP_REST_Request $request ) {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return new \WP_Error( 'anchor_events_not_signed_in', __( 'Sign in to see your events.', 'anchor-schema' ), [ 'status' => 401 ] );
        }
        $from = $this->day_start( wp_date( 'Y-m-d' ) );
        $to   = $this->day_start( wp_date( 'Y-m-d', strtotime( '+' . self::MAX_MONTHS . ' months' ) ) ) - 1;
        $sees = false;
        foreach ( $this->visible_ids( $from, $to, [] ) as $id ) {
            if ( $this->is_restricted( $id ) ) {
                $sees = true;
                break;
            }
        }
        return $this->respond( $request, [ 'name' => (string) $user->display_name, 'sees_restricted' => $sees ] );
    }

    /** Rows for IDs, skipping any event whose schema node is unusable. */
    private function rows( array $ids ) {
        $rows = [];
        foreach ( $ids as $id ) {
            $row = $this->row( $id );
            if ( $row !== null ) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @return int[]|\WP_Error [from_ts, to_ts] in the site zone, inclusive.
     */
    private function parse_range( $from, $to ) {
        $bad  = new \WP_Error( 'anchor_events_bad_range', __( 'Use from/to as YYYY-MM-DD, with to on or after from and no more than 24 months apart.', 'anchor-schema' ), [ 'status' => 400 ] );
        $from = ( $from === null || $from === '' ) ? wp_date( 'Y-m-d' ) : (string) $from;
        if ( ! $this->is_date( $from ) ) {
            return $bad;
        }
        $to = ( $to === null || $to === '' ) ? gmdate( 'Y-m-d', strtotime( $from . ' +' . self::DEFAULT_MONTHS . ' months' ) ) : (string) $to;
        if ( ! $this->is_date( $to ) || $to < $from || $to > gmdate( 'Y-m-d', strtotime( $from . ' +' . self::MAX_MONTHS . ' months' ) ) ) {
            return $bad;
        }
        return [ $this->day_start( $from ), $this->day_start( gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) ) - 1 ];
    }

    private function is_date( $s ) {
        return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    /** Midnight of a YYYY-MM-DD in the site zone, through the module's own converter. */
    private function day_start( $date ) {
        return (int) $this->module->to_timestamp( $date, '00:00', '' );
    }

    /** @return int[]|\WP_Error */
    private function parse_categories( $param ) {
        $param = trim( (string) $param );
        if ( $param === '' ) {
            return $this->app_term_ids();
        }
        $ids = [];
        foreach ( array_filter( array_map( 'trim', explode( ',', $param ) ) ) as $slug ) {
            $term = get_term_by( 'slug', sanitize_title( $slug ), 'event_category' );
            if ( ! $term instanceof \WP_Term ) {
                return new \WP_Error( 'anchor_events_bad_category', sprintf( __( 'Unknown event category: %s', 'anchor-schema' ), $slug ), [ 'status' => 400 ] );
            }
            $ids[] = (int) $term->term_id;
        }
        return $ids;
    }

    /**
     * 200 with private caching + a body-hash ETag, or 304 when the client
     * already has it. Body hash, not the cache generation: a seat sale moves
     * registration.status without bumping the generation.
     */
    private function respond( \WP_REST_Request $request, array $data ) {
        $etag     = 'W/"' . md5( (string) wp_json_encode( $data ) ) . '"';
        $match    = trim( (string) $request->get_header( 'if_none_match' ) );
        $response = ( $match !== '' && $match === $etag )
            ? new \WP_REST_Response( null, 304 )
            : new \WP_REST_Response( $data, 200 );
        $response->header( 'ETag', $etag );
        $response->header( 'Cache-Control', 'private, max-age=300' );
        return $response;
    }
```

`to_timestamp( $date, $time, '' )` resolves the site zone (`render_calendar_month()` passes `''` for exactly this). Confirm by reading `to_timestamp()` at about line 13966.

In `Module::register_rest_routes()` append:

```php
        // The member-app feed (spec 2026-10-12-events-feed-api).
        $this->feed->register_routes();
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --group events-feed` then `composer test`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add anchor-events-manager/class-feed.php anchor-events-manager/anchor-events-manager.php tests/test-events-feed-rest.php
git commit -m "feat(events): anchor-events/v1 events, categories and me routes"
```

---

### Task 5: Docs, plus a live check against the local TMJ site

**Files:**
- Modify: `anchor-events-manager/EVENTS.md` (new `## REST feed (member app)` section before `## Filters`)

- [ ] **Step 1: Write the docs section.** It covers:
  - the three endpoints with params and defaults, the row JSON (copy the spec's example with the corrected vocabulary), and the status/mode mapping table;
  - auth: Application Passwords as HTTP Basic, signed out = public only, plus a one-line pointer to `wp_is_application_passwords_available()`;
  - the App categories setting and where it lives;
  - ETag/304 and `Cache-Control`;
  - the error codes;
  - a "Reuse" note naming `range_query_args()`, `Event_Schema::for_event()`, `bookability()` and `meets_prerequisites()`, so future edits keep one implementation.

- [ ] **Step 2: Live check (no code change).** Copy the branch's `anchor-events-manager/` into `/Users/bif/DevKinsta/public/tmjtherapycentreinternational/wp-content/plugins/Anchor-Tools/anchor-events-manager/`, after backing up the existing folder in the scratchpad. Then run:

```bash
curl -s "https://tmjtherapycentreinternational.local/wp-json/anchor-events/v1/events?per_page=3" -k | python3 -m json.tool | head -60
curl -s -o /dev/null -w "%{http_code}\n" "https://tmjtherapycentreinternational.local/wp-json/anchor-events/v1/me" -k
```

Expected: a 200 with real TMJ rows and no group parents, and a 401 for `/me`. Check the site URL with `wp option get home` (via the DevKinsta site's WP-CLI) if `.local` is wrong. Record what you saw in the report. Do not create Application Passwords or change data on the site.

- [ ] **Step 3: Commit**

```bash
git add anchor-events-manager/EVENTS.md
git commit -m "docs(events): REST feed section for the member app"
```

---

## After the plan

The usual Anchor Tools flow follows, outside this plan's tasks:
1. A CodeRabbit capacity check, then one PR (well under 150 files).
2. The user's sign-off before merge.
3. Release N.N.N from `main`: `Version:` bump, `readme.txt` changelog and Stable tag.
