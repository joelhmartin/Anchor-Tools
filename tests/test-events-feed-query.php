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
}
