<?php
/**
 * Events feed — REST routes (spec "Endpoints", "Caching", "Errors").
 *
 * @package Anchor\Events\Tests
 */

/** @group events-feed */
class Test_Events_Feed_Rest extends Anchor_Events_TestCase {

	/** @var WP_REST_Server */
	private $server;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
		add_role( 'centre', 'Centre', [ 'read' => true ] );
	}

	public function tear_down() {
		remove_role( 'centre' );
		parent::tear_down();
	}

	private function get( $route, array $params = [], array $headers = [] ) {
		$request = new WP_REST_Request( 'GET', '/anchor-events/v1' . $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		foreach ( $headers as $k => $v ) {
			$request->set_header( $k, $v );
		}
		return $this->server->dispatch( $request );
	}

	private function upcoming( $title, $days_ahead, array $meta = [] ) {
		$date = wp_date( 'Y-m-d', time() + $days_ahead * DAY_IN_SECONDS );
		$id = $this->make_event( array_merge( [
			'title' => $title, 'start_date' => $date, 'end_date' => $date,
			'start_time' => '09:00', 'end_time' => '17:00', 'registration_mode' => 'free',
		], $meta ) );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $id ) );
		update_post_meta( $id, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $id, '_anchor_event_end_ts', $ts['end'] );
		return $id;
	}

	public function test_nocache_headers_are_suppressed_for_feed_routes_only() {
		foreach ( [ '/events', '/categories', '/me' ] as $route ) {
			$this->get( $route );
			$this->assertFalse( apply_filters( 'rest_send_nocache_headers', true ), $route );
		}
		$this->server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/types' ) );
		$this->assertTrue( apply_filters( 'rest_send_nocache_headers', true ) );
		$this->server->dispatch( new WP_REST_Request( 'GET', '/anchor-events/v1/events/5/room' ) );
		$this->assertTrue( apply_filters( 'rest_send_nocache_headers', true ) );
		$this->get( '/events' );
		$this->assertFalse( apply_filters( 'rest_send_nocache_headers', false ) );
	}

	public function test_events_default_window_and_shape() {
		$soon = $this->upcoming( 'Soon', 10 );
		$far  = $this->upcoming( 'Far', 500 );
		$res  = $this->get( '/events' );
		$this->assertSame( 200, $res->get_status() );
		$ids = wp_list_pluck( $res->get_data()['events'], 'id' );
		$this->assertContains( $soon, $ids );
		$this->assertNotContains( $far, $ids );
		$this->assertSame( 'private, max-age=300', $res->get_headers()['Cache-Control'] );
		$this->assertMatchesRegularExpression( '/^W\/"[a-f0-9]{32}"$/', $res->get_headers()['ETag'] );
		$this->assertSame( count( $ids ), (int) $res->get_headers()['X-WP-Total'] );
	}

	public function test_bad_range_errors() {
		foreach ( [
			[ 'from' => '2030-13-01' ],
			[ 'from' => 'tomorrow' ],
			[ 'from' => '2030-05-01', 'to' => '2030-04-01' ],
			[ 'from' => '2030-01-01', 'to' => '2032-01-02' ],
		] as $params ) {
			$res = $this->get( '/events', $params );
			$this->assertSame( 400, $res->get_status(), wp_json_encode( $params ) );
			$this->assertSame( 'anchor_events_bad_range', $res->get_data()['code'] );
		}
	}

	public function test_unknown_category_errors() {
		$res = $this->get( '/events', [ 'category' => 'nope' ] );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'anchor_events_bad_category', $res->get_data()['code'] );
	}

	public function test_app_categories_default_and_explicit_override() {
		$courses = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'courses' ] );
		$socials = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'socials' ] );
		$a = $this->upcoming( 'Course', 5 );
		$b = $this->upcoming( 'Social', 6 );
		wp_set_object_terms( $a, [ $courses ], 'event_category' );
		wp_set_object_terms( $b, [ $socials ], 'event_category' );

		update_option( 'anchor_events_settings', [ 'app_categories' => [ $courses ] ], false );
		$this->assertSame( [ $a ], wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' ) );
		$this->assertSame( [ $b ], wp_list_pluck( $this->get( '/events', [ 'category' => 'socials' ] )->get_data()['events'], 'id' ) );

		update_option( 'anchor_events_settings', [ 'app_categories' => [] ], false );
		$this->assertEqualsCanonicalizing( [ $a, $b ], wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' ) );
	}

	public function test_pagination() {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->upcoming( "E$i", $i );
		}
		$res = $this->get( '/events', [ 'per_page' => 2, 'page' => 2 ] );
		$data = $res->get_data();
		$this->assertCount( 1, $data['events'] );
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 2, $data['total_pages'] );
		$this->assertSame( 400, $this->get( '/events', [ 'per_page' => 101 ] )->get_status() );
	}

	public function test_restricted_visibility_through_rest() {
		$gated = $this->upcoming( 'Centres only', 7, [ 'required_roles' => [ 'centre' ] ] );
		wp_set_current_user( 0 );
		$this->assertNotContains( $gated, wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'centre' ] ) );
		$rows = $this->get( '/events' )->get_data()['events'];
		$this->assertContains( $gated, wp_list_pluck( $rows, 'id' ) );
		$audience = null;
		foreach ( $rows as $row ) {
			if ( $row['id'] === $gated ) {
				$audience = $row['audience'];
			}
		}
		$this->assertSame( 'restricted', $audience );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertContains( $gated, wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' ), 'staff bypass prerequisites' );
	}

	public function test_etag_304_and_changes_on_sell_out() {
		$id   = $this->upcoming( 'Small', 3, [ 'capacity' => 1 ] );
		$etag = $this->get( '/events' )->get_headers()['ETag'];

		$again = $this->get( '/events', [], [ 'If-None-Match' => $etag ] );
		$this->assertSame( 304, $again->get_status() );
		$this->assertNull( $again->get_data() );

		$this->make_seat( $id ); // sells out; clear_caches() is NOT called
		$after = $this->get( '/events', [], [ 'If-None-Match' => $etag ] );
		$this->assertSame( 200, $after->get_status() );
		$this->assertNotSame( $etag, $after->get_headers()['ETag'] );
	}

	public function test_categories_hide_categories_with_only_gated_events_from_public() {
		$open   = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'courses', 'name' => 'Courses' ] );
		$secret = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'centre-only', 'name' => 'Centre only' ] );
		wp_set_object_terms( $this->upcoming( 'Course', 4 ), [ $open ], 'event_category' );
		wp_set_object_terms( $this->upcoming( 'Gated', 4, [ 'required_roles' => [ 'centre' ] ] ), [ $secret ], 'event_category' );

		wp_set_current_user( 0 );
		$this->assertSame( [ [ 'slug' => 'courses', 'name' => 'Courses', 'count' => 1 ] ], $this->get( '/categories' )->get_data()['categories'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'centre' ] ) );
		$this->assertCount( 2, $this->get( '/categories' )->get_data()['categories'] );
	}

	public function test_me() {
		wp_set_current_user( 0 );
		$res = $this->get( '/me' );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'anchor_events_not_signed_in', $res->get_data()['code'] );

		$this->upcoming( 'Gated', 9, [ 'required_roles' => [ 'centre' ] ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'centre', 'display_name' => 'Dr Centre' ] ) );
		$this->assertSame( [ 'name' => 'Dr Centre', 'sees_restricted' => true ], $this->get( '/me' )->get_data() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Pat' ] ) );
		$this->assertSame( [ 'name' => 'Pat', 'sees_restricted' => false ], $this->get( '/me' )->get_data() );
	}

	public function test_no_personal_data_in_rows() {
		$id = $this->upcoming( 'With seats', 3 );
		$this->make_seat( $id, [ 'email' => 'private@example.test', 'name' => 'Secret Person' ] );
		$json = wp_json_encode( $this->get( '/events' )->get_data() );
		$this->assertStringNotContainsString( 'private@example.test', $json );
		$this->assertStringNotContainsString( 'Secret Person', $json );
	}

	/* ── Round 2 ─────────────────────────────────────────────── */

	public function test_hide_from_archive_is_a_website_switch_only() {
		$open  = $this->upcoming( 'Hidden but open', 5, [ 'hide_from_archive' => '1' ] );
		$gated = $this->upcoming( 'Hidden centre', 6, [ 'hide_from_archive' => '1', 'required_roles' => [ 'centre' ] ] );

		wp_set_current_user( 0 );
		$ids = wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' );
		$this->assertContains( $open, $ids );
		$this->assertNotContains( $gated, $ids );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'centre' ] ) );
		$rows = $this->get( '/events' )->get_data()['events'];
		$this->assertContains( $gated, wp_list_pluck( $rows, 'id' ) );
		foreach ( $rows as $row ) {
			if ( $row['id'] === $gated ) {
				$this->assertSame( 'restricted', $row['audience'] );
			}
		}
	}

	public function test_soft_closed_child_stays_out_even_with_hidden_allowed() {
		$date   = wp_date( 'Y-m-d', time() + 6 * DAY_IN_SECONDS );
		$parent = $this->make_event( [ 'title' => 'Offering', 'type' => 'offering' ] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => $date, 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'A', 'capacity' => 10 ],
			[ 'date' => wp_date( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ), 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'B', 'capacity' => 10 ],
		] );
		$kids = $this->module()->occurrences->reconcile( $parent );
		$this->assertCount( 2, $kids );
		update_post_meta( $kids[0], '_anchor_event_occurrence_closed', '1' );
		$this->module()->clear_caches();
		$ids = wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' );
		$this->assertNotContains( (int) $kids[0], $ids );
		$this->assertContains( (int) $kids[1], $ids );
	}

	public function test_password_on_group_parent_hides_its_children() {
		$parent = $this->make_event( [ 'title' => 'Offering', 'type' => 'offering' ] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => wp_date( 'Y-m-d', time() + 6 * DAY_IN_SECONDS ), 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'A', 'capacity' => 10 ],
		] );
		$kids = $this->module()->occurrences->reconcile( $parent );
		$this->module()->clear_caches();
		$this->assertContains( (int) $kids[0], wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' ) );

		wp_update_post( [ 'ID' => $parent, 'post_password' => 'secret' ] );
		$this->module()->clear_caches();
		$this->assertSame( '', get_post_field( 'post_password', $kids[0] ), 'premise: child does not inherit the password' );
		$this->assertNotContains( (int) $kids[0], wp_list_pluck( $this->get( '/events' )->get_data()['events'], 'id' ) );
	}

	/** In range by its meta, but no start the schema can use: yields no row. */
	private function rowless( $title, $term ) {
		$id = $this->upcoming( $title, 4 );
		update_post_meta( $id, '_anchor_event_start_ts', 0 );
		update_post_meta( $id, '_anchor_event_start_date', '' );
		update_post_meta( $id, '_anchor_event_start_time', '' );
		wp_set_object_terms( $id, [ $term ], 'event_category' );
		$this->module()->clear_caches();
		return $id;
	}

	public function test_categories_and_total_count_only_events_that_yield_a_row() {
		$term = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'courses', 'name' => 'Courses' ] );
		wp_set_object_terms( $this->upcoming( 'Real', 3 ), [ $term ], 'event_category' );
		$ghost = $this->rowless( 'Ghost', $term );

		$data = $this->get( '/events' )->get_data();
		$this->assertNotContains( $ghost, wp_list_pluck( $data['events'], 'id' ) );
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 1, $this->get( '/categories' )->get_data()['categories'][0]['count'] );
	}
}
