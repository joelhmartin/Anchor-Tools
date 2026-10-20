<?php
/**
 * The console list shows a main event with dates as ONE expandable item, and
 * the editors tell a date and its main event about each other.
 *
 * Live (2026-10-20) listed 1 of 8 events: the theme renders
 * [event_manager limit="-1"] and the list clamped with max( 1, … ).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group occurrences
 */
class Test_Events_Console_Groups extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/** @return array{0:int,1:int[]} */
	private function group( $count = 2, $title = 'Workshop' ) {
		$rows = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$rows[] = [ 'date' => sprintf( '2027-11-%02d', 13 + $i * 7 ), 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session ' . $i, 'capacity' => 10 ];
		}
		$main = $this->make_event( [ 'title' => $title, 'timezone' => 'UTC', 'start_date' => '2027-11-13' ] );
		update_post_meta( $main, '_anchor_event_offering_dates', $rows );
		$dates = array_map( 'intval', $this->module()->occurrences->reconcile( $main ) );
		$this->assertCount( $count, $dates );
		return [ $main, $dates ];
	}

	private function render_list( array $atts = [] ) {
		$method = new ReflectionMethod( Module::class, 'render_event_manager_list' );
		$method->setAccessible( true );
		return (string) $method->invoke( $this->module(), array_merge( [ 'show_past' => 'yes', 'limit' => 50, 'order' => 'ASC' ], $atts ) );
	}

	private function render_form( $event_id ) {
		$method = new ReflectionMethod( Module::class, 'render_event_manager_form' );
		$method->setAccessible( true );
		return (string) $method->invoke( $this->module(), $event_id );
	}

	/** Number of top-level list items. */
	private function count_items( $html ) {
		return preg_match_all( '#<details class="anchor-event-admin-item[" ]#', $html );
	}

	public function test_parent_lists_dates_nested_children_not_top_level() {
		list( $main, $dates ) = $this->group( 2 );
		update_post_meta( $dates[1], '_anchor_event_occurrence_closed', true );

		$html = $this->render_list();

		$this->assertSame( 1, $this->count_items( $html ), 'One item for the main event; its dates are not separate items.' );
		$this->assertStringContainsString( 'anchor-event-admin-item--group', $html );
		$this->assertStringContainsString( '2 dates', $html );
		$this->assertMatchesRegularExpression(
			'#<summary[^>]*>.*?<a[^>]+href="[^"]*event_action=edit[^"]*event_id=' . $main . '[^"]*"[^>]*>Workshop</a>#s',
			$html,
			'The summary title links to the main event\'s editor.'
		);
		foreach ( $dates as $date ) {
			$this->assertMatchesRegularExpression( '#<li class="anchor-event-admin-date-row[^"]*"[^>]*>.*?event_action=edit(&amp;|&\#038;|&)event_id=' . $date . '#s', $html );
			$this->assertStringContainsString( 'event_action=roster&#038;event_id=' . $date, $html );
		}
		$this->assertMatchesRegularExpression( '#Nov 13, 2027#', $html );
		$this->assertMatchesRegularExpression( '#<li class="anchor-event-admin-date-row is-closed">.*?Closed.*?event_id=' . $dates[1] . '#s', $html, 'A closed date is listed and marked.' );
	}

	public function test_orphan_child_still_listed() {
		list( $main, $dates ) = $this->group( 1 );
		update_post_meta( $dates[0], '_anchor_event_group_id', 999999 );

		$html = $this->render_list();

		$this->assertSame( 2, $this->count_items( $html ), 'A date whose main event is gone is never hidden.' );
		$this->assertStringContainsString( 'event_action=edit&#038;event_id=' . $dates[0], $html );
	}

	public function test_limit_minus_one_lists_all() {
		// The list orders by start_ts, so each event needs one.
		foreach ( [ 'One' => '2027-01-01', 'Two' => '2027-02-01', 'Three' => '2027-03-01' ] as $title => $day ) {
			$this->make_event( [ 'title' => $title, 'start_date' => $day, 'start_ts' => strtotime( $day . ' 09:00 UTC' ) ] );
		}

		$this->assertSame( 3, $this->count_items( $this->render_list( [ 'limit' => '-1' ] ) ) );
		$this->assertSame( 3, $this->count_items( $this->render_list( [ 'limit' => '0' ] ) ) );
		$this->assertSame( 2, $this->count_items( $this->render_list( [ 'limit' => '2' ] ) ) );
	}

	public function test_date_editor_shows_inherited_placeholder_and_main_link() {
		list( $main, $dates ) = $this->group( 1, 'Adjunct Mini Residency' );
		update_post_meta( $main, '_anchor_event_email_subject_confirmation', 'Thank You For Registering!' );

		$html = $this->render_form( $dates[0] );

		$this->assertStringContainsString( 'This is one date of Adjunct Mini Residency.', $html );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*event_action=edit(&amp;|&\#038;|&)event_id=' . $main . '"[^>]*>Edit main event</a>#', $html );
		$this->assertStringContainsString( 'placeholder="Thank You For Registering!"', $html );
	}

	public function test_main_editor_lists_date_overrides() {
		list( $main, $dates ) = $this->group( 2 );
		update_post_meta( $dates[0], '_anchor_event_email_subject_confirmation', 'Date subject' );
		update_post_meta( $dates[0], '_anchor_event_email_intro_confirmation', 'Date intro' );

		$html = $this->render_form( $main );

		$this->assertMatchesRegularExpression(
			'#<a href="[^"]*event_id=' . $dates[0] . '"[^>]*>Nov 13, 2027</a> has its own Confirmation subject, Confirmation opening lines#',
			$html
		);
		$this->assertStringNotContainsString( 'Nov 20, 2027</a> has its own', $html, 'A date with no overrides is not listed.' );
	}
}
