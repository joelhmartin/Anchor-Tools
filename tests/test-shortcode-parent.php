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
		// The opening tag, not the bare class name: each row also carries a
		// state modifier (anchor-event-choose-date-row--bookable/--unavailable/
		// --past, MODEL-D4) which the bare substring would count twice.
		$this->assertSame( 2, substr_count( $html, '<li class="anchor-event-choose-date-row' ) );
	}

	public function test_shortcode_still_renders_form_for_single_event() {
		$event = $this->make_event( [ 'registration_enabled' => true, 'registration_mode' => 'free', 'start_date' => '2030-10-23' ] );
		$html  = do_shortcode( '[event_registration id="' . $event . '"]' );
		$this->assertStringNotContainsString( 'anchor-event-choose-date', $html );
		$this->assertStringContainsString( 'anchor-event-registration', $html );
	}

	/**
	 * dates="inline" books every live date where the visitor already is,
	 * instead of linking out to each occurrence's own page. One complete
	 * registration block per child — the picker's outbound links must be gone.
	 */
	public function test_shortcode_renders_inline_forms_for_group_parent() {
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

		$html = do_shortcode( '[event_registration id="' . $parent . '" dates="inline"]' );

		$this->assertStringContainsString( 'anchor-event-inline-dates', $html );
		$this->assertSame( 2, substr_count( $html, '<div class="anchor-event-inline-date"' ) );
		// Each child contributes its own bookable block, not a link to one.
		$this->assertSame( 2, substr_count( $html, 'anchor-event-registration' ) );
		$this->assertStringNotContainsString( 'anchor-event-choose-date-list', $html );
	}

	/**
	 * The default is unchanged, so no existing site's parent page silently
	 * turns into a booking wall when this ships.
	 */
	public function test_shortcode_defaults_to_picker_for_group_parent() {
		$parent = $this->make_event( [
			'type'                 => 'offering',
			'registration_enabled' => true,
			'registration_mode'    => 'free',
			'timezone'             => 'UTC',
		] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-10-23', 'end_date' => '2030-10-24', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0, 'tier_id' => '' ],
		] );
		$this->module()->occurrences->reconcile( $parent );

		$html = do_shortcode( '[event_registration id="' . $parent . '"]' );

		$this->assertStringContainsString( 'anchor-event-choose-date-list', $html );
		$this->assertStringNotContainsString( 'anchor-event-inline-dates', $html );
	}
}
