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

	public function tear_down() {
		delete_option( Module::OPTION_KEY );
		parent::tear_down();
	}

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

	public function test_description_decodes_html_entities() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		wp_update_post( [
			'ID'           => $event_id,
			'post_excerpt' => 'Tom &amp; Jerry&#8217;s Big Adventure',
		] );

		$node = $this->schema()->for_event( $event_id );

		// &#8217; decodes to a curly right single quote (U+2019 ’), not a
		// straight apostrophe — assert the actual decoded character.
		$this->assertStringContainsString( 'Tom & Jerry' . "\u{2019}" . 's Big Adventure', $node['description'] );
		$this->assertStringNotContainsString( '&amp;', $node['description'] );
		$this->assertStringNotContainsString( '&#8217;', $node['description'] );
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

	/**
	 * Exercises resolve_timezone()'s 'event' branch (as opposed to 'site',
	 * which every other test in this file uses). Module::get_settings()
	 * defaults `timezone_mode` to 'site' (-> UTC in the WP test env), so
	 * without this test the 'event' branch — and a UTC-fallback regression
	 * in it — would never be exercised by the suite.
	 *
	 * 2027-07-15 is within US DST, so America/New_York is EDT (-04:00) on
	 * that date. Asserting the EXACT offset (not just "some offset exists")
	 * means a broken/UTC-fallback resolve_timezone() fails this test:
	 * verified by temporarily forcing resolve_timezone() to always take the
	 * 'site' branch, which flips the produced offset to '+00:00' and fails
	 * both assertions below.
	 */
	public function test_per_event_timezone_mode_uses_event_timezone_offset() {
		update_option( Module::OPTION_KEY, [ 'timezone_mode' => 'event' ], false );

		$event_id = $this->make_event( [
			'start_date' => '2027-07-15',
			'end_date'   => '2027-07-15',
			'start_time' => '09:00',
			'end_time'   => '11:00',
			'timezone'   => 'America/New_York',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertStringEndsWith( '-04:00', $node['startDate'], 'Expected America/New_York summer (EDT) offset, not UTC/site fallback.' );
		$this->assertStringEndsWith( '-04:00', $node['endDate'], 'Expected America/New_York summer (EDT) offset, not UTC/site fallback.' );
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

	/* ------------------------------------------------------------------
	 * 6. Task 43 — JSON-LD polish (name/place decoding, date-only
	 *    start_time, description cleanup, organizer url)
	 * ------------------------------------------------------------------ */

	/**
	 * Item 1: `name` is decoded the same way description() is (wp_strip_all_tags
	 * + html_entity_decode) so a title carrying literal entities (as WordPress
	 * stores them, e.g. from the block editor's own "&" -> "&#038;"
	 * auto-conversion) doesn't leak raw entity text into the JSON-LD.
	 */
	public function test_event_name_decodes_html_entities_in_title() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );
		wp_update_post( [
			'ID'         => $event_id,
			'post_title' => 'Nd:YAG Basics, Perio, &#038; Hygiene &#8211; Oct 17, 2026',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( 'Nd:YAG Basics, Perio, & Hygiene ' . "\u{2013}" . ' Oct 17, 2026', $node['name'] );
	}

	/**
	 * Item 2: no venue set -> the Place `name` falls back to the address,
	 * comma-joined from whatever parts exist ("Centennial, CO" — evidence
	 * event 7531).
	 */
	public function test_place_name_falls_back_to_city_state_when_no_venue() {
		$event_id = $this->make_event( [
			'start_date'    => '2027-03-01',
			'timezone'      => 'UTC',
			'venue'         => '',
			'address_city'  => 'Centennial',
			'address_state' => 'CO',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( 'Centennial, CO', $node['location']['name'] );
	}

	/**
	 * Item 2: same fallback with a full address (evidence event 7531's
	 * mailing address).
	 */
	public function test_place_name_falls_back_to_full_address_when_no_venue() {
		$event_id = $this->make_event( [
			'start_date'      => '2027-03-01',
			'timezone'        => 'UTC',
			'address_street'  => '5290 E Arapahoe Rd',
			'address_city'    => 'Centennial',
			'address_state'   => 'CO',
			'address_zip'     => '80122',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( '5290 E Arapahoe Rd, Centennial, CO 80122', $node['location']['name'] );
	}

	/**
	 * Item 2: no venue AND no address part at all -> `name` is omitted
	 * entirely from the Place node rather than falling back to the event
	 * title.
	 */
	public function test_place_name_omitted_when_no_venue_and_no_address() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertArrayNotHasKey( 'name', $node['location'] );
	}

	/**
	 * Item 3: an empty start_time with `all_day` unset used to still render
	 * a full midnight datetime — treat it as date-only instead, exactly like
	 * the existing all_day branch (evidence event 7531).
	 */
	public function test_missing_start_time_renders_date_only_dates() {
		$event_id = $this->make_event( [
			'start_date' => '2026-10-17',
			'end_date'   => '2026-10-17',
			'start_time' => '',
			'end_time'   => '',
			'timezone'   => 'UTC',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( '2026-10-17', $node['startDate'] );
		$this->assertSame( '2026-10-17', $node['endDate'] );
	}

	/**
	 * Item 3: a start_time IS present -> full datetimes are kept (not
	 * regressed to date-only).
	 */
	public function test_present_start_time_still_renders_full_datetime() {
		$event_id = $this->make_event( [
			'start_date' => '2026-10-17',
			'start_time' => '08:00',
			'end_time'   => '16:00',
			'timezone'   => 'UTC',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assert_iso8601_with_tz( $node['startDate'] );
		$this->assertStringStartsWith( '2026-10-17T08:00', $node['startDate'] );
	}

	/**
	 * Item 3: same date-only treatment for a multisession session row with
	 * no time of its own.
	 */
	public function test_multisession_session_without_time_is_date_only() {
		$event_id = $this->make_event( [
			'title'      => 'Bootcamp',
			'type'       => 'multisession',
			'start_date' => '2027-04-01',
			'timezone'   => 'UTC',
		] );
		update_post_meta( $event_id, '_anchor_event_sessions', [
			[ 'date' => '2027-04-01', 'start_time' => '', 'end_time' => '', 'label' => 'Day 1' ],
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( '2027-04-01', $node['subEvent'][0]['startDate'] );
		$this->assertSame( '2027-04-01', $node['subEvent'][0]['endDate'] );
	}

	/**
	 * Item 4: description() runs strip_shortcodes(), turns <br> variants and
	 * closing block tags into a single space (so adjacent block text doesn't
	 * run together), then strips remaining tags, decodes entities, and
	 * collapses whitespace — evidence event 7531's rendered description.
	 */
	public function test_description_strips_shortcodes_breaks_and_block_tags() {
		add_shortcode( 'anchor_test_evidence_shortcode', function () {
			return '';
		} );

		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );
		wp_update_post( [
			'ID'           => $event_id,
			'post_excerpt' => '<p>5290 E Arapahoe Rd</p><p>Centennial, CO 80122</p><br>8:00 AM &#8211; 4:00 PM<br />[anchor_test_evidence_shortcode]8 CE Credits</p>',
		] );

		$node = $this->schema()->for_event( $event_id );

		remove_shortcode( 'anchor_test_evidence_shortcode' );

		$this->assertSame(
			'5290 E Arapahoe Rd Centennial, CO 80122 8:00 AM ' . "\u{2013}" . ' 4:00 PM 8 CE Credits',
			$node['description']
		);
	}

	/**
	 * Item 5: the Organizer node carries a `url` pointing at the site root.
	 */
	public function test_organizer_node_includes_home_url() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		$node = $this->schema()->for_event( $event_id );

		$this->assertSame( home_url( '/' ), $node['organizer']['url'] );
	}
}
