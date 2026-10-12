<?php
/**
 * Events feed — the shared range query (spec "Reuse", row 1).
 *
 * @package Anchor\Events\Tests
 */

/** @group events-feed */
class Test_Events_Feed_Query extends Anchor_Events_TestCase {

	public function tear_down() {
		remove_role( 'centre' );
		parent::tear_down();
	}

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
		$this->dated( 'Second Course', '2030-05-20 10:00', '2030-05-21 16:00' );
		$html = do_shortcode( '[event_calendar month="2030-05"]' );
		$this->assertStringContainsString( 'Calendar Course', $html );
		$this->assertMatchesSnapshotOfCalendar( $html );
	}

	/**
	 * The calendar's markup before the extraction (captured on the unmodified
	 * code) in tests/fixtures/events-feed-calendar-2030-05.html.
	 */
	private function assertMatchesSnapshotOfCalendar( $html ) {
		$fixture = __DIR__ . '/fixtures/events-feed-calendar-2030-05.html';
		// The markup carries no post IDs or nonces (links are slug-based), so
		// only whitespace is normalised — dates, days and titles are compared
		// verbatim, which keeps the snapshot sensitive to moved or lost events.
		$normalise = static function ( $s ) {
			return trim( preg_replace( '/\s+/', ' ', $s ) );
		};
		$this->assertSame( $normalise( file_get_contents( $fixture ) ), $normalise( $html ) );
	}

	public function test_group_parent_never_selected_children_are() {
		$parent = $this->make_event( [ 'title' => 'Offering', 'timezone' => 'UTC', 'type' => 'offering' ] );
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
		$parent = $this->make_event( [ 'title' => 'Offering', 'timezone' => 'UTC', 'type' => 'offering' ] );
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

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertNotContains( $gated, $this->module()->feed->visible_ids( ...$range ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'centre' ] ) );
		$this->assertContains( $gated, $this->module()->feed->visible_ids( ...$range ) );
	}

	public function test_same_start_orders_by_id() {
		$a = $this->dated( 'A', '2030-05-10 09:00', '2030-05-10 17:00' );
		$b = $this->dated( 'B', '2030-05-10 09:00', '2030-05-10 17:00' );
		$ids = $this->module()->feed->visible_ids( strtotime( '2030-05-01 UTC' ), strtotime( '2030-06-01 UTC' ), [] );
		$this->assertSame( [ $a, $b ], array_values( array_intersect( $ids, [ $a, $b ] ) ) );
	}
}
