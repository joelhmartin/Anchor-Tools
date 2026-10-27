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
		update_option( \Anchor\Events\Module::OPTION_KEY, $settings, false );
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

	public function test_all_day_follows_the_schema_nodes_date_only_shape() {
		$id   = $this->event( [ 'registration_mode' => 'free' ] );
		$trim = static function ( $node ) {
			$node['startDate'] = substr( $node['startDate'], 0, 10 );
			$node['endDate']   = substr( $node['endDate'], 0, 10 );
			return $node;
		};
		add_filter( 'anchor_events_schema_node', $trim );
		$row = $this->module()->feed->row( $id );
		remove_filter( 'anchor_events_schema_node', $trim );

		$this->assertSame( '2030-11-13', $row['start'] );
		$this->assertTrue( $row['all_day'] );
		$this->assertFalse( $this->module()->feed->row( $id )['all_day'] );
	}

	public function test_password_protected_events_are_not_in_the_feed() {
		$id = $this->event();
		$ts = (int) get_post_meta( $id, '_anchor_event_start_ts', true );
		$this->assertContains( $id, $this->module()->feed->visible_ids( $ts - 60, $ts + 60, [] ) );
		wp_update_post( [ 'ID' => $id, 'post_password' => 'secret' ] );
		$this->module()->clear_caches();
		$this->assertNotContains( $id, $this->module()->feed->visible_ids( $ts - 60, $ts + 60, [] ) );
	}

	public function test_recurring_schedule_expands_to_dated_rows_and_never_the_parent() {
		$parent = $this->make_event( [
			'title' => 'Weekly Class', 'type' => 'recurring', 'timezone' => 'UTC',
			'start_date' => '2030-03-04', 'registration_mode' => 'free',
		] );
		update_post_meta( $parent, '_anchor_event_recurrence', [
			'freq' => 'weekly', 'interval' => 1, 'count' => 3, 'start_time' => '09:00', 'end_time' => '10:00',
		] );
		$kids = $this->module()->occurrences->reconcile( $parent );
		$this->assertCount( 3, $kids );
		$this->module()->clear_caches();

		$ids = $this->module()->feed->visible_ids( strtotime( '2030-03-01 UTC' ), strtotime( '2030-04-30 UTC' ), [] );
		$this->assertNotContains( $parent, $ids );
		$this->assertEqualsCanonicalizing( $kids, $ids );
		$row = $this->module()->feed->row( $kids[0] );
		$this->assertSame( $parent, $row['parent_id'] );
	}

	public function test_summary_is_plain_text_and_capped() {
		$id = $this->event();
		wp_update_post( [ 'ID' => $id, 'post_excerpt' => '<b>Bold</b> ' . str_repeat( 'word ', 100 ) ] );
		$row = $this->module()->feed->row( $id );
		$this->assertStringNotContainsString( '<', $row['summary'] );
		$this->assertLessThanOrEqual( 300, mb_strlen( $row['summary'] ) );
		$this->assertStringStartsWith( 'Bold word', $row['summary'] );
	}

	public function test_summary_drops_shortcodes_including_unregistered_ones() {
		$id = $this->event();
		wp_update_post( [ 'ID' => $id, 'post_excerpt' => 'Earn CE credits. [event_registration] [gallery ids="1,2"] [old-form id=3] Join us [sic] now[/b].' ] );
		$row = $this->module()->feed->row( $id );
		$this->assertSame( 'Earn CE credits. Join us [sic] now.', $row['summary'] );
	}

	public function test_encoded_tags_are_not_resurrected_as_markup() {
		$id = $this->event( [ 'title' => '&lt;b&gt;Text&lt;/b&gt; &amp; more' ] );
		wp_update_post( [ 'ID' => $id, 'post_excerpt' => '&lt;b&gt;Bold&lt;/b&gt; summary' ] );
		$row = $this->module()->feed->row( $id );
		$this->assertStringNotContainsString( '<', $row['title'] );
		$this->assertStringNotContainsString( '<', $row['summary'] );
		$this->assertSame( 'Bold summary', $row['summary'] );
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

	public function test_price_max_is_highest_managed_tier() {
		$this->require_wc();
		$id = $this->event( [ 'registration_mode' => 'wc' ], [
			[ 'label' => 'A', 'price' => 2850, 'active' => true ],
			[ 'label' => 'B', 'price' => 8685, 'active' => true ],
			[ 'label' => 'C', 'price' => 4485, 'active' => true ],
		] );
		$row = $this->module()->feed->row( $id );
		$this->assertSame( '2850.00', $row['registration']['price'] );
		$this->assertSame( '8685.00', $row['registration']['price_max'] );
	}

	public function test_single_price_has_equal_min_and_max() {
		$this->require_wc();
		$id  = $this->event( [ 'registration_mode' => 'wc' ], [ [ 'label' => 'Only', 'price' => 495, 'active' => true ] ] );
		$reg = $this->module()->feed->row( $id )['registration'];
		$this->assertSame( '495.00', $reg['price'] );
		$this->assertSame( '495.00', $reg['price_max'] );
	}

	public function test_free_event_reports_zero_for_both() {
		$id  = $this->event( [ 'registration_mode' => 'free' ] );
		$reg = $this->module()->feed->row( $id )['registration'];
		$this->assertSame( '0.00', $reg['price'] );
		$this->assertSame( '0.00', $reg['price_max'] );
	}

	public function test_no_offer_means_null_price_and_price_max() {
		$id  = $this->event( [ 'registration_mode' => 'free', 'status_mode' => 'manual', 'status' => 'cancelled' ] );
		$reg = $this->module()->feed->row( $id )['registration'];
		$this->assertNull( $reg['price'] );
		$this->assertNull( $reg['price_max'] );
	}

	/** Link a variable product (parent link, variation_id 0) with the given variation prices to an event. */
	private function link_variable_product( $event_id, array $prices ) {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Residency' );
		$parent->set_status( 'publish' );
		$parent->update_meta_data( '_anchor_evt_link_enabled', '1' );
		$parent->update_meta_data( '_anchor_evt_link_event_id', $event_id );
		$parent->save();
		$ids = [];
		foreach ( $prices as $price ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $parent->get_id() );
			$v->set_status( 'publish' );
			$v->set_regular_price( (string) $price );
			$v->save();
			$ids[] = $v->get_id();
		}
		WC_Product_Variable::sync( $parent->get_id() );
		wc_delete_product_transients( $parent->get_id() );
		return [ $parent->get_id(), $ids ];
	}

	public function test_linked_variable_product_without_tiers_prices_from_its_variations() {
		$this->require_wc();
		$id = $this->event( [ 'registration_mode' => 'wc' ] );
		$this->link_variable_product( $id, [ 950, 1695, 1995, 3495 ] );

		$node   = $this->module()->event_schema->for_event( $id );
		$prices = array_map( function ( $o ) { return (float) $o['price']; }, $node['offers'] );
		sort( $prices );
		$this->assertSame( [ 950.0, 1695.0, 1995.0, 3495.0 ], $prices );

		$reg = $this->module()->feed->row( $id )['registration'];
		$this->assertSame( '950.00', $reg['price'] );
		$this->assertSame( '3495.00', $reg['price_max'] );
	}

	public function test_variation_linked_both_ways_is_counted_once() {
		$this->require_wc();
		$id = $this->event( [ 'registration_mode' => 'wc' ] );
		list( , $vids ) = $this->link_variable_product( $id, [ 950, 3495 ] );
		update_post_meta( $vids[0], '_anchor_evt_link_event_id', $id );

		$node = $this->module()->event_schema->for_event( $id );
		$this->assertCount( 2, $node['offers'] );
	}

	public function test_linked_products_with_no_price_emit_no_offer_not_zero() {
		$this->require_wc();
		$id = $this->event( [ 'registration_mode' => 'wc' ] );
		$p  = new WC_Product_Simple();
		$p->set_name( 'Unpriced' );
		$p->set_status( 'publish' );
		$p->update_meta_data( '_anchor_evt_link_enabled', '1' );
		$p->update_meta_data( '_anchor_evt_link_event_id', $id );
		$p->save();

		$reg = $this->module()->feed->row( $id )['registration'];
		$this->assertNull( $reg['price'] );
		$this->assertNull( $reg['price_max'] );
	}

	public function test_stored_tiers_win_over_linked_products() {
		$this->require_wc();
		$id = $this->event( [ 'registration_mode' => 'wc' ], [ [ 'label' => 'Tier', 'price' => 100, 'active' => true ] ] );
		$this->link_variable_product( $id, [ 950, 3495 ] );
		$reg = $this->module()->feed->row( $id )['registration'];
		$this->assertSame( '100.00', $reg['price'] );
		$this->assertSame( '100.00', $reg['price_max'] );
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

	public function test_venue_is_blank_without_a_venue_of_its_own() {
		$id  = $this->event( [ 'venue' => '', 'address_city' => 'Toronto' ] );
		$row = $this->module()->feed->row( $id );
		$this->assertSame( '', $row['location']['venue'] );
		$this->assertSame( 'Toronto', $row['location']['city'] );
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
