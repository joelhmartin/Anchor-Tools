<?php
/**
 * Console "Registered" list: search box and sortable-column markup that
 * assets/roster-table.js drives.
 *
 * @package Anchor_Tools
 */

class Test_Roster_Search_Sort extends Anchor_Events_TestCase {

	private function render_roster() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$event_id = $this->make_event( [
			'title'      => 'Roster Search Fixture',
			'start_date' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
			'start_ts'   => time() + DAY_IN_SECONDS,
		] );
		$this->make_seat( $event_id, [ 'name' => 'Zed Last', 'email' => 'zed@example.org' ] );
		$this->make_seat( $event_id, [ 'name' => 'Amy First', 'email' => 'amy@example.org' ] );
		$_GET     = [];
		$_REQUEST = [];
		return [ $event_id, (string) $this->module()->roster->render_frontend( $event_id, home_url( '/manage/' ) ) ];
	}

	public function test_registered_list_has_a_labelled_search_box_and_count() {
		list( $event_id, $html ) = $this->render_roster();
		$this->assertMatchesRegularExpression( '/<input type="search" id="roster_search-' . $event_id . '"[^>]*data-roster-search/', $html );
		$this->assertStringContainsString( '<label for="roster_search-' . $event_id . '"', $html );
		$this->assertStringContainsString( 'data-roster-count', $html );
	}

	public function test_sortable_columns_are_marked_and_actions_is_not() {
		list( , $html ) = $this->render_roster();
		$this->assertStringContainsString( 'data-roster-table', $html );
		$this->assertMatchesRegularExpression( '/<th scope="col" data-sort="text">Attendee<\/th>/', $html );
		$this->assertMatchesRegularExpression( '/<th scope="col" data-sort="number">Party<\/th>/', $html );
		$this->assertMatchesRegularExpression( '/<th scope="col">Actions<\/th>/', $html );
	}

	public function test_cells_carry_plain_sort_values() {
		list( , $html ) = $this->render_roster();
		$this->assertStringContainsString( 'data-sort-value="Amy First"', $html );
		$this->assertStringContainsString( 'data-sort-value="1"', $html, 'Party sorts on the number, not the cell text.' );
	}

	public function test_i18n_strings_are_valid_json() {
		list( , $html ) = $this->render_roster();
		$this->assertSame( 1, preg_match( '/data-i18n="([^"]*)"/', $html, $m ) );
		$json = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		$this->assertIsArray( $json );
		$this->assertArrayHasKey( 'some', $json );
		$this->assertStringContainsString( '%1$d', $json['some'] );
	}

	public function test_no_search_box_when_nobody_is_registered() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$event_id = $this->make_event( [ 'title' => 'Empty Roster', 'start_date' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), 'start_ts' => time() + DAY_IN_SECONDS ] );
		$_GET     = [];
		$_REQUEST = [];
		$html     = (string) $this->module()->roster->render_frontend( $event_id, home_url( '/manage/' ) );
		$this->assertStringContainsString( 'Nobody is registered yet.', $html );
		$this->assertStringNotContainsString( 'data-roster-search', $html );
	}
}
