<?php
/**
 * "Display dates": an optional free-text override for the dates shown on the
 * site ("February – June 2027"), for a course whose schedule is too long to
 * list. The real dates keep driving everything else.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Occurrences;

/**
 * @group event-save
 */
class Test_Display_Dates extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		unset( $_POST );
		parent::tear_down();
	}

	public function test_metabox_save_persists_display_dates() {
		$event_id = $this->make_event( [ 'start_date' => '2027-02-05', 'end_date' => '2027-06-04' ] );

		$_POST = [
			Module::NONCE                => wp_create_nonce( Module::NONCE ),
			'anchor_event_type'          => 'single',
			'anchor_event_start_date'    => '2027-02-05',
			'anchor_event_end_date'      => '2027-06-04',
			'anchor_event_display_dates' => '  February – June 2027 <b>x</b> ',
		];
		$this->module()->save_meta( $event_id );

		$this->assertSame( 'February – June 2027 x', get_post_meta( $event_id, '_anchor_event_display_dates', true ) );
		$this->assertSame( 'February – June 2027 x', anchor_event_display_dates( $event_id ) );
	}

	public function test_empty_display_dates_falls_back_to_real_dates_in_the_single_view() {
		$event_id = $this->make_event( [ 'start_date' => '2027-02-05', 'end_date' => '2027-06-04' ] );

		$this->assertSame( '', anchor_event_display_dates( $event_id ) );
		$html = $this->module()->render_single_content( $event_id );
		$this->assertStringContainsString( '2027', $html );
		$this->assertStringNotContainsString( 'February – June', $html );

		update_post_meta( $event_id, '_anchor_event_display_dates', 'February – June 2027' );
		$html = $this->module()->render_single_content( $event_id );
		$this->assertStringContainsString( 'February – June 2027', $html );
	}

	public function test_display_dates_is_inherited_by_a_date_under_a_main_event() {
		$this->assertContains( 'display_dates', Occurrences::INHERITED_KEYS );

		$parent_id = $this->make_event( [ 'title' => 'Hybrid', 'display_dates' => 'February – June 2027' ] );
		update_post_meta(
			$parent_id,
			'_anchor_event_offering_dates',
			[ [ 'date' => '2027-02-05', 'end_date' => '2027-06-04', 'start_time' => '', 'end_time' => '', 'label' => '', 'capacity' => 0 ] ]
		);
		$live = $this->module()->occurrences->reconcile( $parent_id );
		$this->assertCount( 1, $live );

		$this->assertSame( 'February – June 2027', anchor_event_display_dates( (int) $live[0] ) );
	}
}
