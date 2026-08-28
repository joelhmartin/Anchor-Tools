<?php
/**
 * The event archive must show the events that exist.
 *
 * Reproductions for the defect report filed against the DEKA install
 * (2026-08-28). Each test drives the REAL main query through go_to() so
 * filter_archive_query() runs as a pre_get_posts callback exactly the way it
 * does on a live site — asserting on hand-built WP_Query args would prove
 * nothing about the hook.
 *
 * The install's symptom was an archive returning 2 posts out of 19. Two
 * independent causes compound, and both are about a meta key that is missing
 * rather than a value that is wrong:
 *
 *   - orderby => meta_value_num + meta_key => start_ts is an INNER JOIN on
 *     that key, so posts without it are excluded. Sorting becomes filtering.
 *   - the archive_hide_past clause compares end_ts numerically, and a
 *     meta_query comparison also requires the key to exist.
 *
 * Note on WHY the key goes missing, because the fix depends on it: both save
 * paths DO write start_ts/end_ts for every event (save_meta() and
 * save_event_manager_fields() both call calculate_timestamps()). What has
 * never existed is a backfill for events that predate the field or arrived by
 * import — which is every event on an install that was migrated in. So the
 * repair is two-sided: make the query resilient, AND backfill the existing
 * rows.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group archive
 */
class Test_Archive_Visibility extends Anchor_Events_TestCase {

	/**
	 * An event as an import or a pre-_ts-feature install left it: real dates
	 * in the date fields, no timestamp mirrors at all.
	 *
	 * @param string $start Y-m-d
	 * @param string $end   Y-m-d, defaults to $start.
	 * @return int
	 */
	private function make_untouched_event( $title, $start, $end = '' ) {
		$event_id = $this->make_event(
			[
				'title'      => $title,
				'start_date' => $start,
				'end_date'   => $end ?: $start,
			]
		);
		delete_post_meta( $event_id, '_anchor_event_start_ts' );
		delete_post_meta( $event_id, '_anchor_event_end_ts' );
		return $event_id;
	}

	/** Post IDs the archive's main query actually returns. */
	private function archive_ids() {
		$this->go_to( get_post_type_archive_link( Module::CPT ) );
		return wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
	}

	private function set_hide_past( $on ) {
		$settings = $this->module()->get_settings();
		$settings['archive_hide_past'] = (bool) $on;
		update_option( Module::OPTION_KEY, $settings, false );
	}

	public function tear_down() {
		delete_option( Module::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * AE-3. The headline defect: events without the start_ts mirror are not
	 * sorted last, they are gone.
	 */
	public function test_archive_includes_events_that_have_no_start_ts() {
		$future = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );

		$with_ts = $this->make_event( [ 'title' => 'Reconciled Course', 'start_date' => $future ] );
		update_post_meta( $with_ts, '_anchor_event_start_ts', strtotime( $future ) );
		update_post_meta( $with_ts, '_anchor_event_end_ts', strtotime( $future ) + HOUR_IN_SECONDS );

		$without_ts = $this->make_untouched_event( 'Imported Course', $future );

		$ids = $this->archive_ids();

		$this->assertContains( $with_ts, $ids );
		$this->assertContains(
			$without_ts,
			$ids,
			'An event with no start_ts was dropped by the orderby join — sorting is filtering.'
		);
	}

	/**
	 * AE-2. "Archive past events" is supposed to hide PAST events. It was
	 * hiding every event without an end_ts, which on a migrated install is
	 * nearly all of them.
	 */
	public function test_hide_past_keeps_future_events_that_have_no_end_ts() {
		$this->set_hide_past( true );

		$future  = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
		$upcoming = $this->make_untouched_event( 'Upcoming, No Timestamps', $future );

		$this->assertContains(
			$upcoming,
			$this->archive_ids(),
			'A future event was hidden by archive_hide_past because it lacked end_ts, not because it had passed.'
		);
	}

	/** AE-2, the other half: the setting must still do its actual job. */
	public function test_hide_past_still_hides_genuinely_past_events() {
		$this->set_hide_past( true );

		$past = $this->make_untouched_event( 'Last Year', gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) );

		$this->assertNotContains( $past, $this->archive_ids() );
	}

	/** AE-2, third case: an undated event must survive rather than vanish. */
	public function test_hide_past_keeps_undated_events() {
		$this->set_hide_past( true );

		$undated = $this->make_event( [ 'title' => 'Undated' ] );
		delete_post_meta( $undated, '_anchor_event_start_ts' );
		delete_post_meta( $undated, '_anchor_event_end_ts' );
		delete_post_meta( $undated, '_anchor_event_start_date' );
		delete_post_meta( $undated, '_anchor_event_end_date' );

		$this->assertContains( $undated, $this->archive_ids() );
	}

	/**
	 * The archive must still be in DATE order, not just complete.
	 *
	 * This is the assertion the first version of these tests lacked, and the
	 * gap a reviewer caught: every case above checks membership, so an
	 * ordering clause that WP silently discarded would have passed all of
	 * them while the catalog fell back to post-date order.
	 */
	public function test_archive_is_ordered_by_event_date() {
		$base = time() + 10 * DAY_IN_SECONDS;

		// Created in deliberately the wrong order, so passing by accident
		// (post_date order == insertion order) is not possible.
		$third  = $this->make_untouched_event( 'Third',  gmdate( 'Y-m-d', $base + 60 * DAY_IN_SECONDS ) );
		$first  = $this->make_untouched_event( 'First',  gmdate( 'Y-m-d', $base ) );
		$second = $this->make_untouched_event( 'Second', gmdate( 'Y-m-d', $base + 30 * DAY_IN_SECONDS ) );

		$ids = $this->archive_ids();

		$this->assertSame(
			[ $first, $second, $third ],
			$ids,
			'The archive is not in event-date order — the named ordering clause was discarded.'
		);
	}

	/** get_events() carries the same ordering, since it shares the args. */
	public function test_get_events_is_ordered_by_event_date() {
		$base = time() + 10 * DAY_IN_SECONDS;

		$later   = $this->make_untouched_event( 'Later',   gmdate( 'Y-m-d', $base + 45 * DAY_IN_SECONDS ) );
		$earlier = $this->make_untouched_event( 'Earlier', gmdate( 'Y-m-d', $base ) );

		$ids = wp_list_pluck( $this->module()->get_events(), 'ID' );

		$this->assertSame( [ $earlier, $later ], $ids );
	}

	/**
	 * The admin list table: quick-filtering by status AND sorting a column at
	 * the same time must keep both.
	 *
	 * admin_sorting() and apply_quick_filters() are hooked on the same
	 * pre_get_posts priority. Now that the ordering lives in meta_query, a
	 * quick filter that REPLACES meta_query silently drops the ordering clause
	 * while orderby still points at it. The old meta_key approach was immune,
	 * so this failure mode is new and needs its own guard.
	 */
	public function test_quick_filter_and_column_sort_survive_each_other() {
		set_current_screen( 'edit-event' );

		$query = new WP_Query();
		$query->init();
		$query->set( 'post_type', Module::CPT );
		$query->set( 'orderby', 'anchor_event_start' );

		// is_main_query() is identity against wp_the_query.
		$previous                 = $GLOBALS['wp_the_query'] ?? null;
		$GLOBALS['wp_the_query']  = $query;
		$_GET['event_status']     = 'upcoming';

		$this->module()->admin_sorting( $query );
		$this->module()->apply_quick_filters( $query );

		$meta_query = $query->get( 'meta_query' );
		$flat       = wp_json_encode( $meta_query );

		unset( $_GET['event_status'] );
		$GLOBALS['wp_the_query'] = $previous;
		set_current_screen( 'front' );

		$this->assertStringContainsString(
			'anchor_event_order',
			$flat,
			'The quick filter overwrote meta_query and took the ordering clause with it.'
		);
		$this->assertStringContainsString(
			'_anchor_event_status',
			$flat,
			'The status quick filter was lost.'
		);
	}

	/**
	 * Composing must not dissolve the caller's own filter.
	 *
	 * The ordering group is EXISTS-or-NOT-EXISTS, i.e. a tautology. Appended
	 * to a meta_query whose ROOT relation is OR, it satisfies the whole thing
	 * for every post — the caller's filter stops filtering and the query
	 * returns the entire CPT. Anything added alongside an existing meta_query
	 * has to be AND-ed with it, not dropped in beside it.
	 */
	public function test_ordering_does_not_dissolve_a_root_or_meta_query() {
		$future = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );

		$wanted   = $this->make_untouched_event( 'Wanted', $future );
		$unwanted = $this->make_untouched_event( 'Unwanted', $future );
		update_post_meta( $wanted, '_theme_featured', 'yes' );

		$args = $this->module()->apply_event_ordering(
			[
				'post_type'      => Module::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'OR',
					[ 'key' => '_theme_featured', 'value' => 'yes', 'compare' => '=' ],
				],
			],
			'start_date',
			'ASC'
		);

		$ids = get_posts( $args );

		$this->assertContains( $wanted, $ids );
		$this->assertNotContains(
			$unwanted,
			$ids,
			"The tautological ordering group satisfied the caller's root OR, so the filter stopped filtering."
		);
	}

	/**
	 * get_events() must not let a caller's meta_query silently replace the
	 * visibility rules — losing past-filtering and child-exclusion is the very
	 * problem this API exists to stop themes from having.
	 */
	public function test_get_events_keeps_visibility_rules_alongside_a_caller_meta_query() {
		$future = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );

		$parent = $this->make_event( [ 'title' => 'Parent', 'start_date' => $future ] );
		update_post_meta( $parent, '_anchor_event_group_role', 'parent' );
		update_post_meta( $parent, '_theme_featured', 'yes' );

		$child = $this->make_event( [ 'title' => 'Parent — Date', 'start_date' => $future ] );
		update_post_meta( $child, '_anchor_event_group_role', 'child' );
		update_post_meta( $child, '_theme_featured', 'yes' );

		$ids = wp_list_pluck(
			$this->module()->get_events( [
				'meta_query' => [
					[ 'key' => '_theme_featured', 'value' => 'yes', 'compare' => '=' ],
				],
			] ),
			'ID'
		);

		$this->assertContains( $parent, $ids );
		$this->assertNotContains(
			$child,
			$ids,
			"A caller's meta_query replaced the visibility clauses, so group children came back."
		);
	}

	/**
	 * AE-1. EVENTS.md documents the collapse rule for the series archive:
	 * a group shows as ONE row, the parent. The CPT archive never implemented
	 * it, so a catalog lists the parent AND every child date.
	 */
	public function test_archive_collapses_a_group_to_its_parent() {
		$future = gmdate( 'Y-m-d', time() + 60 * DAY_IN_SECONDS );

		$parent = $this->make_event( [ 'title' => 'Offering Parent', 'start_date' => $future ] );
		update_post_meta( $parent, '_anchor_event_group_role', 'parent' );

		$child = $this->make_event( [ 'title' => 'Offering Parent — Date 1', 'start_date' => $future ] );
		update_post_meta( $child, '_anchor_event_group_role', 'child' );
		update_post_meta( $child, '_anchor_event_group_id', $parent );

		$ids = $this->archive_ids();

		$this->assertContains( $parent, $ids, 'The group parent is the row a catalog should show.' );
		$this->assertNotContains( $child, $ids, 'A child occurrence duplicates its parent in the catalog.' );
	}
}
