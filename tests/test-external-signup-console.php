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
}
