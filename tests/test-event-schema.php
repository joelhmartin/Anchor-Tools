<?php
/**
 * Event_Schema data builder tests (Phase 4, Task 4.1).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Event_Schema;
use Anchor\Events\Module;

/**
 * @group event-schema
 */
class Test_Event_Schema extends Anchor_Events_TestCase {

	/** @return Event_Schema */
	protected function schema() {
		return $this->module()->event_schema;
	}

	/** @return \Anchor\Events\Occurrences */
	protected function occurrences() {
		return $this->module()->occurrences;
	}

	/**
	 * Assert a string is a valid ISO 8601 datetime WITH a timezone offset
	 * (e.g. "2027-03-01T09:00:00-05:00" or "...Z"), or a date-only string
	 * (all-day events).
	 *
	 * @param string $value
	 */
	protected function assert_iso8601_with_tz( $value ) {
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)$/',
			$value,
			"Expected ISO 8601 with timezone offset, got: {$value}"
		);
	}

	/* ------------------------------------------------------------------
	 * 1. Single event — base fields
	 * ------------------------------------------------------------------ */

	public function test_single_event_has_core_fields() {
		$event_id = $this->make_event( [
			'title'           => 'Spring Workshop',
			'start_date'      => '2027-03-01',
			'end_date'        => '2027-03-01',
			'start_time'      => '09:00',
			'end_time'        => '11:00',
			'timezone'        => 'America/New_York',
			'venue'           => 'Main Hall',
			'address_street'  => '123 Main St',
			'address_city'    => 'Springfield',
			'address_state'   => 'IL',
			'address_zip'     => '62704',
			'address_country' => 'US',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( 'Event', $node['@type'] );
		$this->assertSame( 'Spring Workshop', $node['name'] );
		$this->assertSame( get_permalink( $event_id ), $node['url'] );
		$this->assert_iso8601_with_tz( $node['startDate'] );
		$this->assert_iso8601_with_tz( $node['endDate'] );

		$this->assertSame( 'Place', $node['location']['@type'] );
		$this->assertSame( 'Main Hall', $node['location']['name'] );
		$this->assertSame( 'PostalAddress', $node['location']['address']['@type'] );
		$this->assertSame( '123 Main St', $node['location']['address']['streetAddress'] );
		$this->assertSame( 'Springfield', $node['location']['address']['addressLocality'] );

		$this->assertSame( 'https://schema.org/OfflineEventAttendanceMode', $node['eventAttendanceMode'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $node['eventStatus'] );
	}

	public function test_single_event_includes_image_when_thumbnail_set() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		$attachment_id = self::factory()->attachment->create_object( [
			'file'      => 'test-image.jpg',
			'post_parent' => $event_id,
			'post_mime_type' => 'image/jpeg',
		] );
		set_post_thumbnail( $event_id, $attachment_id );

		$node = $this->schema()->for_event( $event_id );

		$this->assertArrayHasKey( 'image', $node );
		$this->assertNotSame( '', $node['image'] );
	}

	public function test_no_start_date_returns_empty_array() {
		$event_id = $this->make_event( [ 'start_date' => '' ] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( [], $node );
	}

	public function test_cancelled_status_maps_to_event_cancelled() {
		$event_id = $this->make_event( [
			'start_date'  => '2027-03-01',
			'timezone'    => 'UTC',
			'status_mode' => 'manual',
			'status'      => 'cancelled',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( 'https://schema.org/EventCancelled', $node['eventStatus'] );
	}

	/* ------------------------------------------------------------------
	 * 2. Offers by registration_mode
	 * ------------------------------------------------------------------ */

	public function test_wc_mode_with_two_active_tiers_produces_two_offers() {
		$event_id = $this->make_event(
			[
				'start_date'        => '2027-03-01',
				'timezone'          => 'UTC',
				'registration_mode' => 'wc',
				'capacity'          => 0, // unlimited
			],
			[
				[ 'label' => 'General', 'price' => '25', 'active' => 1 ],
				[ 'label' => 'VIP', 'price' => '75', 'active' => 1 ],
			]
		);

		$node = $this->schema()->for_event( $event_id );

		$this->assertArrayHasKey( 'offers', $node );
		$this->assertCount( 2, $node['offers'] );

		$prices = wp_list_pluck( $node['offers'], 'price' );
		sort( $prices );
		$this->assertSame( [ 25, 75 ], $prices );

		foreach ( $node['offers'] as $offer ) {
			$this->assertSame( 'Offer', $offer['@type'] );
			$this->assertArrayHasKey( 'priceCurrency', $offer );
			$this->assertSame( 'https://schema.org/InStock', $offer['availability'] );
			$this->assertSame( get_permalink( $event_id ), $offer['url'] );
		}
	}

	public function test_wc_mode_with_no_active_tiers_has_no_offers_key() {
		$event_id = $this->make_event(
			[
				'start_date'        => '2027-03-01',
				'timezone'          => 'UTC',
				'registration_mode' => 'wc',
			],
			[
				[ 'label' => 'General', 'price' => '25', 'active' => 0 ],
			]
		);

		$node = $this->schema()->for_event( $event_id );

		$this->assertArrayNotHasKey( 'offers', $node );
	}

	public function test_wc_mode_sold_out_when_capacity_reached() {
		$event_id = $this->make_event(
			[
				'start_date'        => '2027-03-01',
				'timezone'          => 'UTC',
				'registration_mode' => 'wc',
				'capacity'          => 1,
			],
			[
				[ 'label' => 'General', 'price' => '25', 'active' => 1 ],
			]
		);
		$this->make_seat( $event_id );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( 'https://schema.org/SoldOut', $node['offers'][0]['availability'] );
	}

	public function test_external_mode_parses_numeric_price_from_display_string() {
		$event_id = $this->make_event( [
			'start_date'              => '2027-03-01',
			'timezone'                => 'UTC',
			'registration_mode'       => 'external',
			'external_url'            => 'https://example.com/register',
			'external_display_price'  => '$495',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertCount( 1, $node['offers'] );
		$this->assertSame( 495, $node['offers'][0]['price'] );
		$this->assertSame( 'https://example.com/register', $node['offers'][0]['url'] );
	}

	public function test_external_mode_omits_price_when_unparseable() {
		$event_id = $this->make_event( [
			'start_date'             => '2027-03-01',
			'timezone'               => 'UTC',
			'registration_mode'      => 'external',
			'external_url'           => 'https://example.com/register',
			'external_display_price' => 'Contact us',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertCount( 1, $node['offers'] );
		$this->assertArrayNotHasKey( 'price', $node['offers'][0] );
		$this->assertSame( 'https://example.com/register', $node['offers'][0]['url'] );
	}

	public function test_free_mode_emits_zero_price_offer() {
		$event_id = $this->make_event( [
			'start_date'        => '2027-03-01',
			'timezone'          => 'UTC',
			'registration_mode' => 'free',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertCount( 1, $node['offers'] );
		$this->assertSame( 0, $node['offers'][0]['price'] );
		$this->assertSame( 'https://schema.org/InStock', $node['offers'][0]['availability'] );
	}

	/* ------------------------------------------------------------------
	 * 3. Virtual events
	 * ------------------------------------------------------------------ */

	public function test_virtual_event_uses_online_attendance_mode_and_virtual_location() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
			'virtual'    => true,
			'virtual_url' => 'https://zoom.example.com/j/123',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( 'https://schema.org/OnlineEventAttendanceMode', $node['eventAttendanceMode'] );
		$this->assertSame( 'VirtualLocation', $node['location']['@type'] );
		$this->assertSame( 'https://zoom.example.com/j/123', $node['location']['url'] );
	}

	public function test_mixed_attendance_mode_when_virtual_and_physical_both_set() {
		$event_id = $this->make_event( [
			'start_date'  => '2027-03-01',
			'timezone'    => 'UTC',
			'virtual'     => true,
			'virtual_url' => 'https://zoom.example.com/j/123',
			'venue'       => 'Main Hall',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( 'https://schema.org/MixedEventAttendanceMode', $node['eventAttendanceMode'] );
		$this->assertIsArray( $node['location'] );
		$this->assertCount( 2, $node['location'] );
	}

	/* ------------------------------------------------------------------
	 * 4. Multisession
	 * ------------------------------------------------------------------ */

	public function test_multisession_event_has_three_subevents_with_own_dates() {
		$event_id = $this->make_event( [
			'title'      => 'Bootcamp',
			'type'       => 'multisession',
			'start_date' => '2027-04-01',
			'timezone'   => 'UTC',
		] );
		update_post_meta( $event_id, '_anchor_event_sessions', [
			[ 'date' => '2027-04-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 1' ],
			[ 'date' => '2027-04-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 2' ],
			[ 'date' => '2027-04-15', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 3' ],
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertArrayHasKey( 'subEvent', $node );
		$this->assertCount( 3, $node['subEvent'] );

		$names = wp_list_pluck( $node['subEvent'], 'name' );
		$this->assertSame( [ 'Day 1', 'Day 2', 'Day 3' ], $names );

		foreach ( $node['subEvent'] as $sub ) {
			$this->assertSame( 'Event', $sub['@type'] );
			$this->assert_iso8601_with_tz( $sub['startDate'] );
			$this->assert_iso8601_with_tz( $sub['endDate'] );
		}

		// Parent spans earliest session start -> latest session end.
		$this->assertStringStartsWith( '2027-04-01T09:00', $node['startDate'] );
		$this->assertStringStartsWith( '2027-04-15T11:00', $node['endDate'] );
	}

	/* ------------------------------------------------------------------
	 * 5. Group parent / child (Pick-one offerings)
	 * ------------------------------------------------------------------ */

	protected function make_group_parent( array $rows, array $meta = [] ) {
		$parent_id = $this->make_event( array_merge( [
			'title'    => 'Workshop Series',
			'type'     => 'offering',
			'timezone' => 'UTC',
			'venue'    => 'Main Hall',
		], $meta ) );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', $rows );
		return $parent_id;
	}

	public function test_group_parent_subevent_carries_every_live_child_date() {
		$parent_id = $this->make_group_parent( [
			[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A' ],
			[ 'date' => '2027-05-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B' ],
		] );
		$this->occurrences()->reconcile( $parent_id );

		$node = $this->schema()->for_event( $parent_id );

		$this->assertArrayHasKey( 'subEvent', $node );
		$this->assertCount( 2, $node['subEvent'] );

		foreach ( $node['subEvent'] as $sub ) {
			$this->assertSame( 'Event', $sub['@type'] );
			$this->assert_iso8601_with_tz( $sub['startDate'] );
			$this->assertArrayHasKey( 'offers', $sub );
		}

		// Parent's own startDate = earliest live child.
		$this->assertStringStartsWith( '2027-05-01T09:00', $node['startDate'] );
	}

	public function test_group_parent_excludes_soft_closed_child() {
		$parent_id = $this->make_group_parent( [
			[ 'date' => '2027-06-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A' ],
			[ 'date' => '2027-06-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B' ],
		] );
		$live = $this->occurrences()->reconcile( $parent_id );
		$this->assertCount( 2, $live );

		// Seat one child so its later removal soft-closes (not trashes) it.
		$this->make_seat( $live[0] );

		// Remove that occurrence's date from the parent's offering_dates and
		// reconcile again -> that child becomes soft-closed.
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-06-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B' ],
		] );
		$this->occurrences()->reconcile( $parent_id );

		$this->assertTrue( (bool) get_post_meta( $live[0], '_anchor_event_occurrence_closed', true ), 'Precondition: child should be soft-closed, not trashed.' );

		$node = $this->schema()->for_event( $parent_id );

		$this->assertCount( 1, $node['subEvent'] );
		$this->assertStringStartsWith( '2027-06-08T09:00', $node['subEvent'][0]['startDate'] );
	}

	public function test_group_parent_with_zero_live_children_returns_empty_array() {
		$parent_id = $this->make_group_parent( [] );
		$this->occurrences()->reconcile( $parent_id );

		$node = $this->schema()->for_event( $parent_id );

		$this->assertSame( [], $node );
	}

	public function test_group_child_is_its_own_standalone_node() {
		$parent_id = $this->make_group_parent( [
			[ 'date' => '2027-07-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A' ],
		] );
		$live = $this->occurrences()->reconcile( $parent_id );
		$child_id = $live[0];

		$node = $this->schema()->for_event( $child_id );

		$this->assertArrayNotHasKey( 'subEvent', $node );
		$this->assertSame( get_the_title( $child_id ), $node['name'] );
		$this->assertSame( get_permalink( $child_id ), $node['url'] );
		$this->assert_iso8601_with_tz( $node['startDate'] );
	}
}
