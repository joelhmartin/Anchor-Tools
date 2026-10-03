<?php
/**
 * Admin lists and the console stop pointing at a roster that does not exist.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group roster
 */
class Test_External_Signup_Console extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function ext() {
		return $this->make_event( [ 'registration_mode' => 'external', 'external_url' => 'https://form.example/x' ] );
	}

	private function item( $id ) {
		$m = new ReflectionMethod( Module::class, 'render_event_manager_item' );
		$m->setAccessible( true );
		return (string) $m->invoke( $this->module(), get_post( $id ) );
	}

	public function test_no_roster_row_action() {
		$this->assertArrayNotHasKey( 'anchor_roster', $this->module()->event_row_actions( [], get_post( $this->ext() ) ) );
		$this->assertArrayHasKey( 'anchor_roster', $this->module()->event_row_actions( [], get_post( $this->make_event() ) ) );
	}

	public function test_console_list_item_says_external_and_offers_no_attendees_or_export() {
		$html = $this->item( $this->ext() );
		$this->assertStringContainsString( 'External signup form', $html );
		$this->assertStringNotContainsString( 'event_action=roster', $html );
		$this->assertStringNotContainsString( 'anchor_event_export', $html );
		$this->assertStringContainsString( 'event_action=roster', $this->item( $this->make_event() ), 'Control: native events keep the link.' );
	}

	public function test_no_event_role_panel() {
		$this->assertSame( '', $this->module()->render_event_role_panel( $this->ext() ) );
	}
	public function test_registrants_list_shortcode_has_no_export_or_empty_state_for_external() {
		$ext = $this->ext();
		$nat = $this->make_event();
		$html = $this->module()->shortcode_event_registrants_list( [ 'orderby' => 'title' ] );
		$this->assertStringContainsString( 'External signup form', $html );
		// Isolate each event's <details> block.
		preg_match_all( '#<details.*?</details>#s', $html, $m );
		$by = [];
		foreach ( $m[0] as $block ) {
			$by[ strpos( $block, 'External signup form' ) !== false ? 'ext' : 'nat' ] = $block;
		}
		$this->assertStringNotContainsString( 'anchor_event_export', $by['ext'] );
		$this->assertStringNotContainsString( 'No registrants yet.', $by['ext'] );
		$this->assertStringContainsString( 'external form', $by['ext'] );
		$this->assertStringContainsString( 'anchor_event_export', $by['nat'], 'Control: native keeps Export CSV.' );
	}

	public function test_export_handler_refuses_external_event() {
		$id = $this->ext();
		$_GET['event_id']  = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'anchor_event_export' );
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'external' );
		try {
			$this->module()->roster->handle_export();
		} finally {
			unset( $_GET['event_id'], $_REQUEST['_wpnonce'] );
		}
	}

	public function test_all_dates_export_skips_external_children() {
		$parent = $this->make_event( [ 'title' => 'Workshop', 'venue' => 'Hall', 'timezone' => 'UTC' ] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'A', 'capacity' => 5 ],
			[ 'date' => '2027-05-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'B', 'capacity' => 5 ],
		] );
		$kids = $this->module()->occurrences->reconcile( $parent );
		$this->assertCount( 2, $kids );
		$this->assertNotEmpty( $this->module()->roster->visible_group_children( $parent ), 'Control: native children listed.' );
		foreach ( $kids as $k ) {
			update_post_meta( $k, '_anchor_event_registration_mode', 'external' );
		}
		$this->assertSame( [], $this->module()->roster->visible_group_children( $parent ), 'All-external group has no exportable dates.' );
	}
}
