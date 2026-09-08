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

	/* ------------------------------------------------------------------
	 * 8. Stored-XSS regression: literal </script> in the title must not
	 *    break out of the wrapping <script type="application/ld+json">
	 *    element (JSON_UNESCAPED_SLASHES disables the default `/` -> `\/`
	 *    escaping that makes JSON safe to inline in HTML).
	 * ------------------------------------------------------------------ */

	public function test_script_breakout_in_title_does_not_escape_the_script_tag() {
		$breakout = '</script><script>alert(1)</script>';

		// As of the events-audit JSON-LD polish (Task 43, item 1), `name`
		// is decoded through plain_text() — get_the_title() ->
		// wp_strip_all_tags() -> html_entity_decode() — so a literal
		// <script> injected into the title is stripped before it ever
		// reaches the JSON; that's asserted below as defense-in-depth, but
		// it means the title can no longer be the vector that drives this
		// test red/green. external_url is raw admin-set meta that
		// Event_Schema::build_external_offer() still embeds unescaped
		// (untouched by this task, by design — see its class docblock), so
		// it is the vector that still proves JSON slash-escaping alone —
		// not sanitization — is what defuses a breakout that DOES reach
		// the JSON body.
		//
		// unfiltered_html (administrators by default on a single-site
		// install) is still needed for the title half of this test: without
		// it, wp_insert_post()/wp_update_post() run post_title through
		// wp_kses and strip the <script> tags before they ever reach the
		// database, masking that part of the assertion.
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$event_id = $this->make_event( [
			'title'             => 'Evil ' . $breakout,
			'start_date'        => '2027-03-01',
			'timezone'          => 'UTC',
			'registration_mode' => 'external',
			'external_url'      => 'https://example.com/register ' . $breakout,
		] );

		// For good measure: same payload via the description path
		// (post_excerpt). description() already ran (and still runs) every
		// value through wp_strip_all_tags() before it ever reaches the
		// JSON, so this documents that path is independently safe too — it
		// is NOT expected to drive this test red/green on its own.
		wp_update_post( [
			'ID'           => $event_id,
			'post_excerpt' => 'Summary ' . $breakout,
		] );

		$html = $this->module()->render_event_schema( $event_id );
		$this->assertNotSame( '', $html );

		// The ONLY "</script>" substring anywhere in the rendered string
		// must be the legitimate closing tag of the wrapping <script>
		// element. A browser's HTML parser terminates a <script> element
		// on the FIRST "</script>" it sees, full stop — it does not know
		// or care that the bytes came from inside a JSON string. Any extra
		// occurrence means the attacker's injected markup becomes a live
		// sibling <script>alert(1)</script> element that executes.
		$this->assertSame(
			1,
			substr_count( $html, '</script>' ),
			'A raw "</script>" leaked into the JSON-LD body — this is a stored-XSS <script> tag breakout.'
		);

		$this->assertSame(
			1,
			\preg_match( '#<script type="application/ld\+json">(.*)</script>#s', $html, $m ),
			'Expected exactly one ld+json script tag to be extractable.'
		);
		$data = json_decode( $m[1], true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), 'Emitted JSON must decode without error: ' . json_last_error_msg() );

		// name: stripped of the injected markup (defense in depth, item 1) —
		// no trace of the breakout survives.
		$this->assertSame( 'Evil', $data['name'] );
		$this->assertStringNotContainsString( '<script', $data['name'] );

		// offers[0].url: still raw, byte-for-byte the original un-mangled
		// value — proving THIS field is defused by JSON escaping alone, not
		// sanitization (the escaping is transparent to JSON parsers: still
		// valid JSON, and the decoded value is byte-for-byte the original).
		$this->assertArrayHasKey( 'offers', $data );
		$this->assertSame( 'https://example.com/register ' . $breakout, $data['offers'][0]['url'] );

		// Confirm the actual fix mechanism: forward slashes are escaped,
		// so the dangerous sequence survives only as the harmless `<\/script>`.
		$this->assertStringContainsString( '<\/script>', $html );
	}

	/**
	 * plain_text() (item 1, Task 43) runs wp_strip_all_tags() BEFORE
	 * html_entity_decode() — an ENTITY-ENCODED "<script>" (e.g.
	 * "&lt;script&gt;") is not a raw tag yet when the strip step runs, so it
	 * survives untouched, and only becomes literal "<script>" TEXT once
	 * decode runs afterward. That ordering could in principle reopen the
	 * breakout the tag-stripping in the test above closes for a raw
	 * "<script>" title — this proves it doesn't: the decoded name's literal
	 * "</script>" still reaches the JSON body only in its JSON-escaped
	 * `<\/script>` form, same defense as the always-raw external_url vector.
	 */
	public function test_entity_encoded_breakout_in_title_decodes_but_stays_json_escaped() {
		$encoded_breakout = '&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;';
		$decoded_breakout = '</script><script>alert(1)</script>';

		$event_id = $this->make_event( [
			'title'      => 'Evil ' . $encoded_breakout,
			'start_date' => '2027-03-01',
			'timezone'   => 'UTC',
		] );

		$html = $this->module()->render_event_schema( $event_id );
		$this->assertNotSame( '', $html );

		$this->assertSame(
			1,
			\preg_match( '#<script type="application/ld\+json">(.*)</script>#s', $html, $m ),
			'Expected exactly one ld+json script tag to be extractable.'
		);

		// The literal "</script>" substring must not appear ANYWHERE inside
		// the ld+json script body — only the JSON-escaped `<\/script>` form
		// is safe there.
		$this->assertStringNotContainsString(
			'</script>',
			$m[1],
			'A raw "</script>" appeared inside the ld+json body — the entity-decoded breakout was not escaped.'
		);

		$data = json_decode( $m[1], true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), 'Emitted JSON must decode without error: ' . json_last_error_msg() );

		// name: the entities DID decode to a literal breakout string...
		$this->assertSame( 'Evil ' . $decoded_breakout, $data['name'] );

		// ...but it only ever reached the ld+json body as the harmless,
		// JSON-slash-escaped `<\/script>` — the defence that matters here.
		$this->assertStringContainsString( '<\/script>', $m[1] );
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
