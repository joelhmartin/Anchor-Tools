<?php
/**
 * Metabox save-path tests for Task 1.3+1.4 (event-type / registration-mode
 * authoring UI).
 *
 * Exercises Module::save_meta() directly by simulating a metabox POST
 * (nonce + the new anchor_event_* fields) exactly as the admin form in
 * render_meta_box() submits them. The critical assertion is that
 * `external_embed` goes through the SAME wp_kses() allowlist sanitizer as the
 * REST write path (sanitize_external_embed()) — an allowed <iframe> survives,
 * a <script> is stripped — proving the metabox save path is not storing the
 * field raw.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group event-save
 */
class Test_Event_Save extends Anchor_Events_TestCase {

	/** @var int */
	private $admin_id;

	public function set_up() {
		parent::set_up();
		// save_meta() requires current_user_can( 'edit_post', $post_id ).
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down() {
		unset( $_POST );
		parent::tear_down();
	}

	/**
	 * Build a valid $_POST payload for save_meta(), covering just the fields
	 * this task added. Other allow-listed fields (dates, etc.) are omitted on
	 * purpose — save_meta() must tolerate a partial post ('' defaults) since
	 * this test isolates the new type/mode/sessions/external fields.
	 */
	private function post_payload( array $overrides = [] ) {
		return array_merge(
			[
				Module::NONCE => wp_create_nonce( Module::NONCE ),
				'anchor_event_type' => 'multisession',
				'anchor_event_registration_mode' => 'external',
				'anchor_event_sessions' => [
					[ 'date' => '2026-08-01', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Day 1' ],
					// Empty date — must be dropped by save_meta().
					[ 'date' => '', 'start_time' => '11:00', 'end_time' => '12:00', 'label' => 'Bad row' ],
				],
				'anchor_event_external_url' => 'https://example.test/register',
				'anchor_event_external_embed' => '<iframe src="https://ok.example"></iframe><script>alert(1)</script>',
				'anchor_event_external_display_price' => '$495',
			],
			$overrides
		);
	}

	/** RED-before-GREEN baseline: save_meta() persists the new type/mode/sessions/external meta. */
	public function test_save_meta_persists_type_mode_sessions_and_external_fields() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload();
		$this->module()->save_meta( $event_id );

		$this->assertSame( 'multisession', get_post_meta( $event_id, '_anchor_event_type', true ) );
		$this->assertSame( 'external', get_post_meta( $event_id, '_anchor_event_registration_mode', true ) );
		$this->assertSame( 'https://example.test/register', get_post_meta( $event_id, '_anchor_event_external_url', true ) );
		$this->assertSame( '$495', get_post_meta( $event_id, '_anchor_event_external_display_price', true ) );
	}

	/** The session repeater drops rows with an empty date and keeps valid rows intact. */
	public function test_save_meta_sessions_drops_empty_date_rows() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload();
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_sessions', true );

		$this->assertCount( 1, $stored, 'The row with an empty date must be dropped.' );
		$this->assertSame(
			[
				'date' => '2026-08-01',
				'start_time' => '09:00',
				'end_time' => '10:00',
				'label' => 'Day 1',
			],
			$stored[0]
		);
	}

	/**
	 * Proves the metabox save path reuses sanitize_external_embed() — the SAME
	 * iframe-only wp_kses() allowlist as the REST write path — rather than
	 * storing external_embed raw. An allowed <iframe> survives; the <script>
	 * is stripped entirely (its tag is off the default allowlist).
	 */
	public function test_save_meta_sanitizes_external_embed_strips_script_keeps_iframe() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload();
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_external_embed', true );

		$this->assertStringContainsString( '<iframe', $stored, 'Allowed <iframe> must survive the metabox save.' );
		$this->assertStringContainsString( 'src="https://ok.example"', $stored );
		$this->assertStringNotContainsString( '<script', $stored, 'A <script> tag must be stripped by the metabox save, matching the REST write path.' );
	}

	/** An invalid posted `type` falls back to 'single'. */
	public function test_save_meta_type_falls_back_to_single_on_invalid_value() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload( [ 'anchor_event_type' => 'not-a-real-type' ] );
		$this->module()->save_meta( $event_id );

		$this->assertSame( 'single', get_post_meta( $event_id, '_anchor_event_type', true ) );
	}

	/** An invalid posted `registration_mode` falls back to the event's currently-resolved mode, not a hardcoded default. */
	public function test_save_meta_registration_mode_falls_back_to_current_derived_value_on_invalid_post() {
		// No explicit registration_mode meta yet, but a paid active tier derives 'wc'.
		$event_id = $this->make_event(
			[],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);
		$this->assertSame( 'wc', $this->module()->registration_mode( $event_id ), 'Sanity: this event should derive wc before the save under test.' );

		$_POST = $this->post_payload( [ 'anchor_event_registration_mode' => 'not-a-real-mode' ] );
		$this->module()->save_meta( $event_id );

		$this->assertSame( 'wc', get_post_meta( $event_id, '_anchor_event_registration_mode', true ) );
	}

	/** save_meta() is a no-op when the nonce is missing/invalid (existing guard, unchanged). */
	public function test_save_meta_noop_without_valid_nonce() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload( [ Module::NONCE => 'invalid-nonce' ] );
		$this->module()->save_meta( $event_id );

		$this->assertSame( '', get_post_meta( $event_id, '_anchor_event_type', true ) );
	}
}
