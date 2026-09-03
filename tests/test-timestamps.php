<?php
/** @group time */
class Test_Timestamps extends Anchor_Events_TestCase {
	public function test_date_only_event_ends_at_end_of_day() {
		$event = $this->make_event( [ 'start_date' => '2030-12-05', 'end_date' => '', 'start_time' => '', 'end_time' => '', 'all_day' => false ] );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		$this->assertSame( 23 * 3600 + 59 * 60, $ts['end'] - $ts['start'] );
	}
	public function test_start_time_without_end_time_still_ends_at_end_of_day() {
		$event = $this->make_event( [ 'start_date' => '2030-12-05', 'start_time' => '09:00', 'end_time' => '', 'all_day' => false ] );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		$this->assertGreaterThan( $ts['start'], $ts['end'] );
	}

	/**
	 * RENDER-D31: an event whose `end_ts` meta row was never written is UNDATED,
	 * not past — build_visibility_clause() must not drop it from a
	 * "hide past events" listing.
	 *
	 * `start_ts` is written here because every listing query still orders by it
	 * (meta_key + orderby=meta_value_num INNER-JOINs postmeta); the legacy event
	 * that has neither row is MODEL-D2's case, covered by the back-fill test below.
	 */
	public function test_event_with_no_end_ts_row_is_listed_as_upcoming() {
		$event = $this->make_event( [ 'title' => 'Undated End Event', 'start_date' => '2030-12-05' ] );
		$ts    = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		update_post_meta( $event, '_anchor_event_start_ts', $ts['start'] );
		delete_post_meta( $event, '_anchor_event_end_ts' );

		$this->module()->clear_caches();
		$html = do_shortcode( '[events_list show_past="no"]' );

		$this->assertStringContainsString( esc_url( get_permalink( $event ) ), $html );
	}

	/**
	 * MODEL-D2: legacy events never had `_ts` rows written, so the one-time
	 * back-fill has to mint them — otherwise the event is absent from every
	 * plugin-rendered list, not merely unsorted.
	 */
	public function test_backfill_writes_ts_for_legacy_events() {
		$event = $this->make_event( [ 'title' => 'Legacy Event', 'start_date' => '2030-12-05' ] );
		delete_post_meta( $event, '_anchor_event_start_ts' );
		delete_post_meta( $event, '_anchor_event_end_ts' );
		delete_option( 'anchor_events_ts_backfilled' );

		$this->module()->backfill_timestamps();

		$this->assertNotEmpty( get_post_meta( $event, '_anchor_event_start_ts', true ) );
		$this->assertNotEmpty( get_post_meta( $event, '_anchor_event_end_ts', true ) );
		$this->assertSame( '1', get_option( 'anchor_events_ts_backfilled' ) );

		// The visible symptom: the back-filled event now appears in a listing.
		$this->module()->clear_caches();
		$this->assertStringContainsString(
			esc_url( get_permalink( $event ) ),
			do_shortcode( '[events_list show_past="no"]' )
		);
	}
}
