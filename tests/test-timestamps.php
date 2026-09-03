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
}
