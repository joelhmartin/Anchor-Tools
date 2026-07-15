<?php
/**
 * Front-end manager-form save-path tests (Task 1.5).
 *
 * The public admin-post entry point, Module::handle_event_manager_save(), ends
 * in wp_safe_redirect() + a raw exit; that terminates the PHP process and can't
 * be exercised directly by PHPUnit. The actual field-persistence logic (Date &
 * Time / Location / Registration / the six event-type-authoring keys / gallery
 * / featured image / registration-shortcode append) was extracted verbatim —
 * no behavior change — into Module::save_event_manager_fields(), which
 * handle_event_manager_save() now calls right before the redirect. These tests
 * drive that extracted method directly, which is exactly the front-end save
 * path minus the exit()-terminated request wrapper (nonce check, capability
 * check, wp_insert_post()/wp_update_post()).
 *
 * The critical assertion mirrors Test_Event_Save (the Task 1.3 metabox save
 * test): `external_embed` goes through the SAME shared sanitizer
 * (Module::sanitize_event_type_input(), which itself calls
 * sanitize_external_embed()) as the metabox save path — an allowed <iframe>
 * survives, a <script> is stripped — proving the front-end save path is not
 * storing the field raw and that the two save paths share one sanitizer.
 *
 * A separate test drives the real handle_event_manager_save() entry point for
 * the invalid-nonce guard, since wp_die() (unlike a raw exit) is intercepted
 * by WP's test suite and thrown as a WPDieException — proving the nonce check
 * was not weakened by the Task 1.5 refactor.
 *
 * save_event_manager_fields() is `protected` (Task 1.5 review fix — it writes
 * meta given only an int with no capability/nonce re-check of its own, so it
 * must not be a public entry point). These tests reach it via
 * ReflectionMethod::setAccessible() rather than a direct call, since the real
 * public entry point's success path ends in exit() and can't be driven here.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group event-save
 */
class Test_Event_Manager_Save extends Anchor_Events_TestCase {

	/** @var int */
	private $admin_id;

	public function set_up() {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down() {
		unset( $_POST );
		parent::tear_down();
	}

	/**
	 * Invoke the now-protected Module::save_event_manager_fields() via
	 * Reflection. This is the front-end save path minus the exit()-terminated
	 * request wrapper (nonce check, capability check, wp_insert_post()/
	 * wp_update_post()) — see the class docblock.
	 *
	 * @return array The sanitized meta values written, same as the method's
	 *               own return value.
	 */
	private function call_save_event_manager_fields( $saved_id, $start_date, $current_registration_mode ) {
		$method = new ReflectionMethod( Module::class, 'save_event_manager_fields' );
		$method->setAccessible( true );
		return $method->invoke( $this->module(), $saved_id, $start_date, $current_registration_mode );
	}

	/**
	 * Build a valid $_POST payload for save_event_manager_fields(), covering
	 * just the six fields this task brought to parity. Field names are
	 * IDENTICAL to the metabox's (anchor_event_type, anchor_event_sessions[n][...],
	 * etc.) — both forms POST the same shape into the same shared sanitizer.
	 */
	private function post_payload( array $overrides = [] ) {
		return array_merge(
			[
				'anchor_event_type' => 'multisession',
				'anchor_event_registration_mode' => 'external',
				'anchor_event_sessions' => [
					[ 'date' => '2026-08-01', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Day 1' ],
					// Empty date — must be dropped, same as the metabox save.
					[ 'date' => '', 'start_time' => '11:00', 'end_time' => '12:00', 'label' => 'Bad row' ],
				],
				'anchor_event_external_url' => 'https://example.test/register',
				'anchor_event_external_embed' => '<iframe src="https://ok.example"></iframe><script>alert(1)</script>',
				'anchor_event_external_display_price' => '$495',
			],
			$overrides
		);
	}

	/** RED-before-GREEN baseline: the front-end save persists the new type/mode/sessions/external meta. */
	public function test_manager_save_persists_type_mode_sessions_and_external_fields() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload();
		$fallback = $this->module()->registration_mode( $event_id );
		$this->call_save_event_manager_fields( $event_id, '2026-08-01', $fallback );

		$this->assertSame( 'multisession', get_post_meta( $event_id, '_anchor_event_type', true ) );
		$this->assertSame( 'external', get_post_meta( $event_id, '_anchor_event_registration_mode', true ) );
		$this->assertSame( 'https://example.test/register', get_post_meta( $event_id, '_anchor_event_external_url', true ) );
		$this->assertSame( '$495', get_post_meta( $event_id, '_anchor_event_external_display_price', true ) );
	}

	/** The session repeater drops rows with an empty date and keeps valid rows intact, matching the metabox save. */
	public function test_manager_save_sessions_drops_empty_date_rows() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload();
		$fallback = $this->module()->registration_mode( $event_id );
		$this->call_save_event_manager_fields( $event_id, '2026-08-01', $fallback );

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
	 * Proves the front-end save path reuses the SAME shared sanitizer
	 * (sanitize_event_type_input() -> sanitize_external_embed()) as the
	 * metabox save path, rather than storing external_embed raw. An allowed
	 * <iframe> survives; the <script> is stripped entirely.
	 */
	public function test_manager_save_sanitizes_external_embed_strips_script_keeps_iframe() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload();
		$fallback = $this->module()->registration_mode( $event_id );
		$this->call_save_event_manager_fields( $event_id, '2026-08-01', $fallback );

		$stored = get_post_meta( $event_id, '_anchor_event_external_embed', true );

		$this->assertStringContainsString( '<iframe', $stored, 'Allowed <iframe> must survive the front-end save.' );
		$this->assertStringContainsString( 'src="https://ok.example"', $stored );
		$this->assertStringNotContainsString( '<script', $stored, 'A <script> tag must be stripped by the front-end save, matching the metabox and REST write paths.' );
	}

	/** An invalid posted `type` falls back to 'single', same as the metabox save. */
	public function test_manager_save_type_falls_back_to_single_on_invalid_value() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload( [ 'anchor_event_type' => 'not-a-real-type' ] );
		$fallback = $this->module()->registration_mode( $event_id );
		$this->call_save_event_manager_fields( $event_id, '2026-08-01', $fallback );

		$this->assertSame( 'single', get_post_meta( $event_id, '_anchor_event_type', true ) );
	}

	/** An invalid posted `registration_mode` falls back to the caller-supplied pre-resolved value, same as the metabox save. */
	public function test_manager_save_registration_mode_falls_back_to_current_derived_value_on_invalid_post() {
		// No explicit registration_mode meta yet, but a paid active tier derives 'wc'.
		$event_id = $this->make_event(
			[],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);
		$this->assertSame( 'wc', $this->module()->registration_mode( $event_id ), 'Sanity: this event should derive wc before the save under test.' );

		$_POST = $this->post_payload( [ 'anchor_event_registration_mode' => 'not-a-real-mode' ] );
		$fallback = $this->module()->registration_mode( $event_id );
		$this->call_save_event_manager_fields( $event_id, '2026-08-01', $fallback );

		$this->assertSame( 'wc', get_post_meta( $event_id, '_anchor_event_registration_mode', true ) );
	}

	/**
	 * The real admin-post entry point still enforces its nonce check after the
	 * Task 1.5 refactor (behavior-preserving extraction, not a security change).
	 * wp_die() is intercepted by WP's test suite and thrown as a WPDieException
	 * instead of terminating the process, so — unlike the exit()-terminated
	 * success path — this guard IS directly testable through the real
	 * handle_event_manager_save() entry point.
	 */
	public function test_handle_event_manager_save_dies_on_invalid_nonce() {
		$event_id = $this->make_event();

		$_POST = array_merge(
			$this->post_payload(),
			[
				'anchor_event_manager_nonce' => 'invalid-nonce',
				'event_id' => $event_id,
				'anchor_event_title' => 'Updated Title',
				'anchor_event_start_date' => '2026-08-01',
			]
		);

		$this->expectException( WPDieException::class );
		$this->module()->handle_event_manager_save();
	}
}
