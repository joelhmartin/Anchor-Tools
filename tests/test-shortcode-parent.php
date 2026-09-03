<?php
/**
 * `[event_registration]` on a group-parent event id (COORD-D1).
 *
 * A landing page that names a group PARENT with [event_registration id=<parent>]
 * used to get render_registration_form()'s output for a parent, which is
 * always '' (a container is never itself bookable) — the shortcode rendered
 * nothing. It must instead fall back to the same choose-your-date picker
 * [event_dates]/render_choose_date_list() renders, while a single (non-group)
 * event id keeps rendering the normal registration form.
 *
 * @package Anchor\Events\Tests
 */

/** @group shortcode */
class Test_Shortcode_Parent extends Anchor_Events_TestCase {

	public function test_shortcode_renders_date_picker_for_group_parent() {
		$parent = $this->make_event( [
			'type'                 => 'offering',
			'registration_enabled' => true,
			'registration_mode'    => 'free',
			'timezone'             => 'UTC',
		] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-10-23', 'end_date' => '2030-10-24', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0, 'tier_id' => '' ],
			[ 'date' => '2030-11-13', 'end_date' => '2030-11-14', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'November', 'capacity' => 0, 'tier_id' => '' ],
		] );
		$this->module()->occurrences->reconcile( $parent );
		$this->assertTrue( $this->module()->occurrences->is_group_parent( $parent ) );

		$html = do_shortcode( '[event_registration id="' . $parent . '"]' );

		$this->assertStringContainsString( 'anchor-event-choose-date-list', $html );
		$this->assertSame( 2, substr_count( $html, 'anchor-event-choose-date-row' ) );
	}

	public function test_shortcode_still_renders_form_for_single_event() {
		$event = $this->make_event( [ 'registration_enabled' => true, 'registration_mode' => 'free', 'start_date' => '2030-10-23' ] );
		$html  = do_shortcode( '[event_registration id="' . $event . '"]' );
		$this->assertStringNotContainsString( 'anchor-event-choose-date', $html );
		$this->assertStringContainsString( 'anchor-event-registration', $html );
	}
}
