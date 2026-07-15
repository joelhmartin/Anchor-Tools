<?php
/**
 * Event JSON-LD front-end EMISSION tests (Phase 4, Task 4.2).
 *
 * Task 4.1 (Test_Event_Schema / class-event-schema.php) covers the DATA
 * builder (Event_Schema::for_event()). This file covers the wrapping +
 * output layer: Module::render_event_schema() — a testable method that
 * returns the `<script type="application/ld+json">...</script>` string (or
 * '' when nothing should be emitted) — plus its thin wp_head wrapper
 * (Module::output_event_schema(), smoke-tested only; the interesting
 * assertions all go through render_event_schema() directly since it's the
 * pure, testable surface).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group event-schema
 * @group event-schema-emit
 */
class Test_Event_Schema_Emit extends Anchor_Events_TestCase {

	public function tear_down() {
		delete_option( Module::OPTION_KEY );
		remove_all_filters( 'anchor_events_emit_event_schema' );
		parent::tear_down();
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Decode a rendered `<script type="application/ld+json">...</script>`
	 * string, asserting the wrapper shape along the way.
	 *
	 * @param string $html
	 * @return array
	 */
	protected function decode_schema_script( $html ) {
		$this->assertStringContainsString( '<script type="application/ld+json">', $html );
		$this->assertStringContainsString( '</script>', $html );

		$this->assertSame(
			1,
			\preg_match( '#<script type="application/ld\+json">(.*)</script>#s', $html, $m ),
			'Expected exactly one ld+json script tag to be extractable.'
		);

		$json = $m[1];
		$data = json_decode( $json, true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), 'Emitted JSON must decode without error: ' . json_last_error_msg() );
		$this->assertIsArray( $data );

		return $data;
	}

	/* ------------------------------------------------------------------
	 * 1. Single event
	 * ------------------------------------------------------------------ */

	public function test_single_event_emits_valid_script_with_context_and_start_date() {
		$event_id = $this->make_event( [
			'title'      => 'Spring Workshop',
			'start_date' => '2027-03-01',
			'end_date'   => '2027-03-01',
			'start_time' => '09:00',
			'end_time'   => '11:00',
			'timezone'   => 'UTC',
		] );

		$html = $this->module()->render_event_schema( $event_id );
		$data = $this->decode_schema_script( $html );

		$this->assertSame( 'https://schema.org', $data['@context'] );
		$this->assertSame( 'Event', $data['@type'] );
		$this->assertStringStartsWith( '2027-03-01T09:00', $data['startDate'] );
	}

	/* ------------------------------------------------------------------
	 * 2. Multisession -> subEvent sessions
	 * ------------------------------------------------------------------ */

	public function test_multisession_event_emits_subevent_sessions() {
		$event_id = $this->make_event( [
			'title'      => 'Bootcamp',
			'type'       => 'multisession',
			'start_date' => '2027-04-01',
			'timezone'   => 'UTC',
		] );
		update_post_meta( $event_id, '_anchor_event_sessions', [
			[ 'date' => '2027-04-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 1' ],
			[ 'date' => '2027-04-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 2' ],
		] );

		$html = $this->module()->render_event_schema( $event_id );
		$data = $this->decode_schema_script( $html );

		$this->assertArrayHasKey( 'subEvent', $data );
		$this->assertCount( 2, $data['subEvent'] );
	}

	/* ------------------------------------------------------------------
	 * 3. Group PARENT -> subEvent = every live child (core assertion)
	 * ------------------------------------------------------------------ */

	public function test_group_parent_emits_subevent_for_every_live_child_date() {
		$parent_id = $this->make_event( [
			'title'    => 'Workshop Series',
			'type'     => 'offering',
			'timezone' => 'UTC',
			'venue'    => 'Main Hall',
		] );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A' ],
			[ 'date' => '2027-05-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B' ],
			[ 'date' => '2027-05-15', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session C' ],
		] );
		$this->module()->occurrences->reconcile( $parent_id );

		$html = $this->module()->render_event_schema( $parent_id );
		$data = $this->decode_schema_script( $html );

		// THE core "Google sees every date" assertion — every live child date
		// present in the parent page's JSON-LD, not just one.
		$this->assertArrayHasKey( 'subEvent', $data );
		$this->assertCount( 3, $data['subEvent'], 'Every live child date must appear in the parent page JSON-LD.' );

		$starts = wp_list_pluck( $data['subEvent'], 'startDate' );
		sort( $starts );
		$this->assertStringStartsWith( '2027-05-01T09:00', $starts[0] );
		$this->assertStringStartsWith( '2027-05-08T09:00', $starts[1] );
		$this->assertStringStartsWith( '2027-05-15T09:00', $starts[2] );
	}

	/* ------------------------------------------------------------------
	 * 4. Empty data -> no emission
	 * ------------------------------------------------------------------ */

	public function test_no_start_date_emits_nothing() {
		$event_id = $this->make_event( [ 'start_date' => '' ] );

		$html = $this->module()->render_event_schema( $event_id );

		$this->assertSame( '', $html );
	}

	/* ------------------------------------------------------------------
	 * 5. anchor_events_emit_event_schema filter suppresses output
	 * ------------------------------------------------------------------ */

	public function test_emit_filter_returning_false_suppresses_output() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		add_filter( 'anchor_events_emit_event_schema', '__return_false' );

		$html = $this->module()->render_event_schema( $event_id );

		$this->assertSame( '', $html );
	}

	public function test_emit_filter_receives_should_emit_and_event_id() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		$seen = [];
		add_filter( 'anchor_events_emit_event_schema', function ( $should_emit, $id ) use ( &$seen ) {
			$seen[] = [ $should_emit, $id ];
			return $should_emit;
		}, 10, 2 );

		$this->module()->render_event_schema( $event_id );

		$this->assertCount( 1, $seen );
		$this->assertTrue( $seen[0][0] );
		$this->assertSame( $event_id, $seen[0][1] );
	}

	/* ------------------------------------------------------------------
	 * 6. De-dupe vs the parent Anchor Schema plugin
	 * ------------------------------------------------------------------ */

	/**
	 * The parent Anchor Schema plugin (Anchor_Schema_Render::output_active_schemas())
	 * only ever emits for a post that has an ENABLED item in its
	 * `_anchor_schema_items` post meta (Anchor_Schema_Admin::META_KEY) — it
	 * never auto-maps the `event` CPT. Simulating that meta with an enabled
	 * Event-type item is therefore the real, direct way to trigger the
	 * parent plugin's own emission path for this post; ours must defer to it.
	 */
	public function test_manual_event_schema_on_parent_plugin_suppresses_our_emission() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		update_post_meta( $event_id, \Anchor_Schema_Admin::META_KEY, [
			[
				'id'      => 'test-item',
				'type'    => 'Event',
				'enabled' => true,
				'json'    => '{"@context":"https://schema.org","@type":"Event","name":"Manually configured"}',
			],
		] );

		$html = $this->module()->render_event_schema( $event_id );

		$this->assertSame( '', $html );
	}

	public function test_disabled_manual_schema_item_does_not_suppress_our_emission() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		update_post_meta( $event_id, \Anchor_Schema_Admin::META_KEY, [
			[
				'id'      => 'test-item',
				'type'    => 'Event',
				'enabled' => false, // disabled -> parent plugin itself would not print this.
				'json'    => '{"@context":"https://schema.org","@type":"Event","name":"Manually configured"}',
			],
		] );

		$html = $this->module()->render_event_schema( $event_id );

		$this->assertNotSame( '', $html );
	}

	public function test_manual_non_event_schema_type_does_not_suppress_our_emission() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		// An enabled item for an unrelated @type (e.g. FAQPage) is not a
		// conflicting Event node — no reason to suppress ours.
		update_post_meta( $event_id, \Anchor_Schema_Admin::META_KEY, [
			[
				'id'      => 'test-item',
				'type'    => 'FAQPage',
				'enabled' => true,
				'json'    => '{"@context":"https://schema.org","@type":"FAQPage"}',
			],
		] );

		$html = $this->module()->render_event_schema( $event_id );

		$this->assertNotSame( '', $html );
	}

	/* ------------------------------------------------------------------
	 * 7. wp_head wrapper smoke test
	 * ------------------------------------------------------------------ */

	public function test_output_event_schema_echoes_render_event_schema_on_singular_event() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		$this->go_to( get_permalink( $event_id ) );
		$this->assertTrue( is_singular( Module::CPT ) );

		ob_start();
		$this->module()->output_event_schema();
		$html = ob_get_clean();

		$this->decode_schema_script( $html );
	}

	public function test_output_event_schema_is_silent_off_single_event_views() {
		$event_id = $this->make_event( [
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		$this->go_to( home_url( '/' ) );
		$this->assertFalse( is_singular( Module::CPT ) );

		ob_start();
		$this->module()->output_event_schema();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}
}
