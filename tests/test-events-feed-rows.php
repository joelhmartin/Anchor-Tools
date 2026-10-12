<?php
/**
 * Events feed — row mapping (spec "Row" + "Registration vocabulary").
 *
 * @package Anchor\Events\Tests
 */

/** @group events-feed */
class Test_Events_Feed_Rows extends Anchor_Events_TestCase {

	/** The feed reports the event's own zone only when timezone_mode=event (default 'site' resolves every event to the site zone). */
	public function set_up() {
		parent::set_up();
		$settings                  = get_option( \Anchor\Events\Module::OPTION_KEY, [] );
		$settings                  = is_array( $settings ) ? $settings : [];
		$settings['timezone_mode'] = 'event';
		update_option( \Anchor\Events\Module::OPTION_KEY, $settings );
		$this->module()->clear_caches();
	}

	private function event( array $meta = [], array $tiers = [] ) {
		$id = $this->make_event( array_merge( [
			'title'           => 'Mini Residency &#8211; Toronto',
			'start_date'      => '2030-11-13',
			'end_date'        => '2030-11-15',
			'start_time'      => '08:00',
			'end_time'        => '17:00',
			'timezone'        => 'America/Toronto',
			'venue'           => 'Hilton',
			'address_city'    => 'Toronto',
			'address_state'   => 'ON',
			'address_country' => 'CA',
		], $meta ), $tiers );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $id ) );
		update_post_meta( $id, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $id, '_anchor_event_end_ts', $ts['end'] );
		return $id;
	}

	public function test_row_core_fields_come_from_schema_node() {
		$id   = $this->event( [ 'registration_mode' => 'free' ] );
		$node = $this->module()->event_schema->for_event( $id );
		$row  = $this->module()->feed->row( $id );

		$this->assertSame( $id, $row['id'] );
		$this->assertSame( 0, $row['parent_id'] );
		$this->assertSame( 'Mini Residency – Toronto', $row['title'] );
		$this->assertSame( $node['startDate'], $row['start'] );
		$this->assertSame( $node['endDate'], $row['end'] );
		$this->assertSame( '2030-11-13T08:00:00-05:00', $row['start'] );
		$this->assertFalse( $row['all_day'] );
		$this->assertSame( 'America/Toronto', $row['timezone'] );
		$this->assertSame( get_permalink( $id ), $row['url'] );
		$this->assertSame( get_permalink( $id ), $row['registration']['register_url'] );
		$this->assertSame( [ 'venue' => 'Hilton', 'city' => 'Toronto', 'state' => 'ON', 'country' => 'CA', 'online' => false ], $row['location'] );
		$this->assertSame( 'public', $row['audience'] );
	}

	public function test_summary_is_plain_text_and_capped() {
		$id = $this->event();
		wp_update_post( [ 'ID' => $id, 'post_excerpt' => '<b>Bold</b> ' . str_repeat( 'word ', 100 ) ] );
		$row = $this->module()->feed->row( $id );
		$this->assertStringNotContainsString( '<', $row['summary'] );
		$this->assertLessThanOrEqual( 300, mb_strlen( $row['summary'] ) );
		$this->assertStringStartsWith( 'Bold word', $row['summary'] );
	}

	public function test_registration_mode_and_status_vocabulary() {
		$free = $this->event( [ 'registration_mode' => 'free' ] );
		$row  = $this->module()->feed->row( $free );
		$this->assertSame( 'free', $row['registration']['mode'] );
		$this->assertSame( 'open', $row['registration']['status'] );

		$full = $this->event( [ 'registration_mode' => 'free', 'capacity' => 1 ] );
		$this->make_seat( $full );
		$this->assertSame( 'full', $this->module()->bookability( $full ) );
		$this->assertSame( 'sold_out', $this->module()->feed->row( $full )['registration']['status'] );

		$off = $this->event( [ 'registration_mode' => 'free', 'registration_enabled' => false ] );
		$this->assertSame( 'unavailable', $this->module()->feed->row( $off )['registration']['status'] );

		$ext = $this->event( [ 'registration_mode' => 'external', 'registration_url' => 'https://example.test/form', 'registration_enabled' => false ] );
		$row = $this->module()->feed->row( $ext );
		$this->assertSame( 'external', $row['registration']['mode'] );
		$this->assertSame( 'open', $row['registration']['status'] );
		$this->assertSame( get_permalink( $ext ), $row['registration']['register_url'] );
	}

	public function test_status_matches_bookability_mapping_table() {
		$feed = $this->module()->feed;
		$this->assertSame( 'open', $feed->map_status( 'open', 'free' ) );
		$this->assertSame( 'waitlist', $feed->map_status( 'waitlist', 'wc' ) );
		$this->assertSame( 'sold_out', $feed->map_status( 'full', 'wc' ) );
		$this->assertSame( 'unavailable', $feed->map_status( 'disabled', 'free' ) );
		$this->assertSame( 'closed', $feed->map_status( 'closed', 'free' ) );
		$this->assertSame( 'closed', $feed->map_status( 'prerequisite', 'free' ) );
		$this->assertSame( 'open', $feed->map_status( 'disabled', 'external' ) );
		$this->assertSame( 'closed', $feed->map_status( 'closed', 'external' ) );
	}

	public function test_paid_price_is_lowest_offer() {
		$this->require_wc();
		$id = $this->event( [ 'registration_mode' => 'wc' ], [
			[ 'label' => 'Early', 'price' => 950, 'active' => true ],
			[ 'label' => 'Late', 'price' => 1200, 'active' => true ],
		] );
		$row = $this->module()->feed->row( $id );
		$this->assertSame( 'paid', $row['registration']['mode'] );
		$this->assertSame( '950.00', $row['registration']['price'] );
		$this->assertSame( get_woocommerce_currency(), $row['registration']['currency'] );
	}

	public function test_closed_event_has_null_price() {
		$id = $this->event( [ 'registration_mode' => 'free', 'status_mode' => 'manual', 'status' => 'cancelled' ] );
		$row = $this->module()->feed->row( $id );
		$this->assertSame( 'closed', $row['registration']['status'] );
		$this->assertNull( $row['registration']['price'] );
		$this->assertNull( $row['registration']['currency'] );
	}

	public function test_online_event_never_leaks_room_url() {
		$id = $this->event( [ 'virtual' => true, 'venue' => '', 'address_city' => '', 'address_state' => '', 'address_country' => '', 'virtual_url' => 'https://zoom.example/secret' ] );
		$row = $this->module()->feed->row( $id );
		$this->assertTrue( $row['location']['online'] );
		$this->assertStringNotContainsString( 'zoom.example', wp_json_encode( $row ) );
	}

	public function test_all_day_dates_are_date_only() {
		$id  = $this->event( [ 'all_day' => true ] );
		$row = $this->module()->feed->row( $id );
		$this->assertTrue( $row['all_day'] );
		$this->assertSame( '2030-11-13', $row['start'] );
	}

	public function test_restricted_audience_and_categories() {
		$term = self::factory()->term->create( [ 'taxonomy' => 'event_category', 'slug' => 'centre-events', 'name' => 'Centre Events' ] );
		$id   = $this->event( [ 'required_roles' => [ 'centre' ] ] );
		wp_set_object_terms( $id, [ $term ], 'event_category' );
		$row = $this->module()->feed->row( $id );
		$this->assertSame( 'restricted', $row['audience'] );
		$this->assertSame( [ [ 'slug' => 'centre-events', 'name' => 'Centre Events' ] ], $row['categories'] );
	}

	public function test_event_without_start_returns_null() {
		$id = $this->make_event( [ 'title' => 'Undated' ] );
		$this->assertNull( $this->module()->feed->row( $id ) );
	}
}
