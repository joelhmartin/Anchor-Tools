<?php
/**
 * REST writes to the public date meta must recompute the derived rows.
 *
 * `_anchor_event_start_date` and its five siblings are in REST_PUBLIC_META, so
 * any client with `edit_post` can move an event's date through
 * `/wp/v2/event/<id>` — a path that touches neither save path that maintains
 * `start_ts`/`end_ts`/`status`. save_meta() bails on the missing metabox nonce,
 * and persist_status_on_transition() runs on `transition_post_status`, which
 * WordPress fires from wp_update_post() BEFORE the REST controller saves meta —
 * so it recomputes from the OLD dates. Only `rest_after_insert_event`, which
 * fires after the meta write, sees the new values.
 *
 * @package Anchor\Events\Tests
 */

/** @group rest */
class Test_Rest_Writes extends Anchor_Events_TestCase {

	/**
	 * WP_UnitTestCase_Base::tear_down() unregisters every meta key after each
	 * test while register_meta() only ran once at bootstrap — so without this
	 * the `meta` request param is silently dropped and the test passes/fails
	 * for the wrong reason. Same fix as Test_Rest_Exposure::set_up().
	 */
	public function set_up() {
		parent::set_up();
		$this->module()->register_meta();
	}

	/** @return int Editor user id, set as the current user. */
	private function login_as_editor() {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user );
		return $user;
	}

	private function update_meta_over_rest( $event_id, array $meta ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/event/' . $event_id );
		$request->set_body_params( [ 'meta' => $meta ] );
		return rest_get_server()->dispatch( $request );
	}

	public function test_rest_date_write_recomputes_the_timestamp_rows() {
		$this->login_as_editor();
		$event = $this->make_event( [ 'start_date' => '2030-01-01' ] );
		$stale = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		update_post_meta( $event, '_anchor_event_start_ts', $stale['start'] );
		update_post_meta( $event, '_anchor_event_end_ts', $stale['end'] );

		$response = $this->update_meta_over_rest( $event, [ '_anchor_event_start_date' => '2031-06-15' ] );
		$this->assertSame( 200, $response->get_status(), 'The REST meta write itself must succeed.' );

		$meta = $this->module()->get_meta( $event );
		$this->assertSame( '2031-06-15', $meta['start_date'], 'Precondition: REST wrote the new date.' );

		$expected = $this->module()->compute_timestamps( $meta );
		$this->assertSame( $expected['start'], (int) get_post_meta( $event, '_anchor_event_start_ts', true ) );
		$this->assertSame( $expected['end'], (int) get_post_meta( $event, '_anchor_event_end_ts', true ) );
		$this->assertNotSame( $stale['start'], (int) get_post_meta( $event, '_anchor_event_start_ts', true ) );
	}

	public function test_rest_date_write_stamps_the_timestamp_schema_version() {
		$this->login_as_editor();
		$event = $this->make_event( [ 'start_date' => '2030-01-01' ] );
		delete_post_meta( $event, '_anchor_event_ts_version' );

		$this->update_meta_over_rest( $event, [ '_anchor_event_start_date' => '2031-06-15' ] );

		$this->assertSame(
			\Anchor\Events\Module::TS_SCHEMA_VERSION,
			(int) get_post_meta( $event, '_anchor_event_ts_version', true )
		);
	}

	public function test_rest_date_write_recomputes_auto_status() {
		$this->login_as_editor();
		$event = $this->make_event( [ 'start_date' => '2030-01-01', 'status_mode' => 'auto', 'status' => 'upcoming' ] );

		$this->update_meta_over_rest( $event, [ '_anchor_event_start_date' => '2001-01-01' ] );

		$this->assertSame( 'past', get_post_meta( $event, '_anchor_event_status', true ) );
	}

	public function test_rest_date_write_leaves_a_manual_status_alone() {
		$this->login_as_editor();
		$event = $this->make_event( [ 'start_date' => '2030-01-01', 'status_mode' => 'manual', 'status' => 'upcoming' ] );

		$this->update_meta_over_rest( $event, [ '_anchor_event_start_date' => '2001-01-01' ] );

		$this->assertSame( 'upcoming', get_post_meta( $event, '_anchor_event_status', true ) );
	}

	/**
	 * finding-17 — `_anchor_event_status` is ALSO in REST_PUBLIC_META, so a
	 * REST PATCH straight to postponed/moved_online used to bypass
	 * persist_event_authoring() (and its maybe_persist_previous_start() call)
	 * entirely: WordPress's generic registered-meta REST handling writes the
	 * meta directly. Event_Schema published EventPostponed with no
	 * previousStartDate to go with it. persist_after_rest_write() must now
	 * capture the date being moved away from, exactly like a metabox/console
	 * save transitioning the same event into postponed would.
	 */
	public function test_rest_status_write_to_postponed_captures_previous_start() {
		$this->login_as_editor();
		// status_mode is not REST_PUBLIC_META (not REST-writable), so the
		// event must already be in manual mode — exactly what an author does
		// in wp-admin before a REST-driven client (e.g. a headless editor)
		// PATCHes the status itself.
		$event = $this->make_event( [
			'start_date'  => '2030-01-01',
			'status_mode' => 'manual',
			'status'      => 'upcoming',
		] );
		delete_post_meta( $event, '_anchor_event_previous_start' );

		$response = $this->update_meta_over_rest( $event, [ '_anchor_event_status' => 'postponed' ] );

		$this->assertSame( 200, $response->get_status(), 'The REST meta write itself must succeed.' );
		$this->assertSame( 'postponed', get_post_meta( $event, '_anchor_event_status', true ), 'Precondition: REST wrote the new status.' );
		$this->assertSame(
			'2030-01-01',
			get_post_meta( $event, '_anchor_event_previous_start', true ),
			'A REST PATCH to postponed must capture the date being moved away from, same as the metabox/console saves.'
		);
	}

	/** The companion status, same rule: moved_online also captures the date it is leaving. */
	public function test_rest_status_write_to_moved_online_captures_previous_start() {
		$this->login_as_editor();
		$event = $this->make_event( [
			'start_date'  => '2030-03-15',
			'status_mode' => 'manual',
			'status'      => 'upcoming',
		] );
		delete_post_meta( $event, '_anchor_event_previous_start' );

		$this->update_meta_over_rest( $event, [ '_anchor_event_status' => 'moved_online' ] );

		$this->assertSame(
			'2030-03-15',
			get_post_meta( $event, '_anchor_event_previous_start', true )
		);
	}

	/** A REST write that never touches status at all must not fabricate a previous_start row. */
	public function test_rest_write_unrelated_to_status_does_not_capture_previous_start() {
		$this->login_as_editor();
		$event = $this->make_event( [ 'start_date' => '2030-01-01' ] );
		delete_post_meta( $event, '_anchor_event_previous_start' );

		$this->update_meta_over_rest( $event, [ '_anchor_event_venue' => 'New venue' ] );

		$this->assertSame( '', get_post_meta( $event, '_anchor_event_previous_start', true ) );
	}
}
