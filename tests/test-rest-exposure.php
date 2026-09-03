<?php
/**
 * Task 7 — Nothing private over REST (COORD-D2, COORD-D4, REG-D19).
 *
 * @package Anchor\Events\Tests
 */

/** @group rest */
class Test_Rest_Exposure extends Anchor_Events_TestCase {
	public function test_registrations_are_not_a_rest_route() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayNotHasKey( '/wp/v2/anchor_event_reg', $routes );
	}
	public function test_virtual_url_and_organizer_email_are_not_in_rest_meta() {
		$event = $this->make_event( [ 'virtual_url' => 'https://zoom.example/x', 'organizer_email' => 'o@example.com', 'start_date' => '2030-01-01' ] );
		$req = new WP_REST_Request( 'GET', '/wp/v2/event/' . $event );
		$data = rest_get_server()->dispatch( $req )->get_data();
		$this->assertArrayNotHasKey( '_anchor_event_virtual_url', $data['meta'] ?? [] );
		$this->assertArrayNotHasKey( '_anchor_event_organizer_email', $data['meta'] ?? [] );
	}
}
