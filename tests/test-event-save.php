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
				// Additive fields (Task 2): unposted modality/embed persist as
				// empty — '' means "inherit the event default", resolved on read.
				'modality' => '',
				'stream_embed' => [],
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

	/* ---------------------------------------------------------------------
	 * NEW-D6 (plugin half) — the auto-append shortcode respects the theme's
	 * own registration UI
	 * ------------------------------------------------------------------- */

	/** Baseline: registration enabled + no theme support declared still auto-appends. */
	public function test_save_meta_appends_registration_shortcode_when_enabled() {
		$event_id = $this->make_event();
		wp_update_post( [ 'ID' => $event_id, 'post_content' => 'Course description.' ] );

		$_POST = $this->post_payload( [ 'anchor_event_registration_enabled' => '1' ] );
		$this->module()->save_meta( $event_id );

		$this->assertStringContainsString( '[event_registration]', get_post( $event_id )->post_content );
	}

	/** A theme declaring add_theme_support( 'anchor-events-registration' ) suppresses the auto-append entirely. */
	public function test_save_meta_skips_auto_append_when_theme_declares_support() {
		$event_id = $this->make_event();
		wp_update_post( [ 'ID' => $event_id, 'post_content' => 'Course description.' ] );

		add_theme_support( 'anchor-events-registration' );
		try {
			$_POST = $this->post_payload( [ 'anchor_event_registration_enabled' => '1' ] );
			$this->module()->save_meta( $event_id );
		} finally {
			remove_theme_support( 'anchor-events-registration' );
		}

		$this->assertStringNotContainsString( '[event_registration]', get_post( $event_id )->post_content );
	}

	/** anchor_events_auto_append_registration returning false is the escape hatch for anything that can't add theme support. */
	public function test_save_meta_skips_auto_append_when_filter_returns_false() {
		$event_id = $this->make_event();
		wp_update_post( [ 'ID' => $event_id, 'post_content' => 'Course description.' ] );

		$suppress = static function () {
			return false;
		};
		add_filter( 'anchor_events_auto_append_registration', $suppress );
		try {
			$_POST = $this->post_payload( [ 'anchor_event_registration_enabled' => '1' ] );
			$this->module()->save_meta( $event_id );
		} finally {
			remove_filter( 'anchor_events_auto_append_registration', $suppress );
		}

		$this->assertStringNotContainsString( '[event_registration]', get_post( $event_id )->post_content );
	}

	/** A pasted Vimeo URL is normalised on save and forces virtual=1. */
	public function test_stream_embed_saved_and_forces_virtual() {
		$event_id = $this->make_event();
		$_POST    = [
			'anchor_event_start_date'   => '2027-06-01',
			'anchor_event_stream_embed' => 'https://vimeo.com/424242',
		];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );
		$meta = $this->module()->get_meta( $event_id );

		$this->assertSame( 'vimeo', $meta['stream_embed']['provider'] );
		$this->assertSame( 'https://player.vimeo.com/video/424242', $meta['stream_embed']['src'] );
		$this->assertTrue( $meta['virtual'], 'A saved stream forces the legacy virtual flag on.' );
		$this->assertTrue( $meta['access_role_enabled'], 'A saved stream opts the event in to accounts and the event role.' );
		$_POST = [];
	}

	/** A fresh event saves with the switch ON — that is the default (§3.1). */
	public function test_fresh_event_saves_with_the_switch_on() {
		$event_id = $this->make_event();
		$_POST    = [ 'anchor_event_start_date' => '2027-06-01' ];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );

		$this->assertTrue(
			$this->module()->get_meta( $event_id )['access_role_enabled'],
			'Every plugin-registered event grants the role by default (owner decision 2026-09-23).'
		);
		$this->assertSame( [], $this->module()->get_meta( $event_id )['stream_embed'], 'No stream, so no room — the role is the whole of it.' );
		$_POST = [];
	}

	/** Un-ticking the box is a real, persisted OFF. */
	public function test_unticking_the_switch_persists_false() {
		$event_id = $this->make_event();
		$_POST    = [
			'anchor_event_start_date'          => '2027-06-01',
			// The hidden companion Task 16 renders beside the checkbox: the
			// field is PRESENT with value 0, which is how a deliberate untick
			// is told apart from a form that never carried the control.
			'anchor_event_access_role_enabled' => '0',
		];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );

		$this->assertFalse( $this->module()->get_meta( $event_id )['access_role_enabled'] );
		$_POST = [];
	}

	/** A save whose form did not carry the field keeps a stored FALSE. */
	public function test_absent_field_keeps_a_stored_false() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_access_role_enabled', false );

		$_POST = [ 'anchor_event_start_date' => '2027-06-01' ]; // No Access section on this form.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );

		$this->assertFalse(
			$this->module()->get_meta( $event_id )['access_role_enabled'],
			'A partial save must not resurrect an event the operator switched off.'
		);
		$_POST = [];
	}

	/** And it keeps a stored TRUE — the symmetric half of the same rule. */
	public function test_absent_field_keeps_a_stored_true() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_access_role_enabled', true );

		$_POST = [ 'anchor_event_start_date' => '2027-06-01' ];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );

		$this->assertTrue(
			$this->module()->get_meta( $event_id )['access_role_enabled'],
			'A partial save must not strip the role from attendees who hold it.'
		);
		$_POST = [];
	}

	/** Saving a stream forces the switch on, even over an untick on the same POST. */
	public function test_embed_forces_the_switch_on_over_an_untick() {
		$event_id = $this->make_event();
		$_POST    = [
			'anchor_event_start_date'          => '2027-06-01',
			'anchor_event_stream_embed'        => 'https://vimeo.com/515151',
			'anchor_event_access_role_enabled' => '0',
		];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );

		$this->assertTrue(
			$this->module()->get_meta( $event_id )['access_role_enabled'],
			'A room nobody can be granted access to is not a state this plugin ships.'
		);
		$_POST = [];
	}

	/** A virtual/hybrid session flips the master switch with no embed at all. */
	public function test_virtual_session_flips_the_access_switch() {
		$event_id = $this->make_event();
		$_POST    = [
			'anchor_event_start_date' => '2027-06-01',
			'anchor_event_type'       => 'multisession',
			// Unticked on this POST, so the assertion below is about the flip
			// and not about the default.
			'anchor_event_access_role_enabled' => '0',
			'anchor_event_sessions'   => [
				[ 'date' => '2027-06-01', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Day 1', 'modality' => 'in_person' ],
				[ 'date' => '2027-06-02', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Day 2', 'modality' => 'hybrid' ],
			],
		];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );

		$this->assertTrue( $this->module()->get_meta( $event_id )['access_role_enabled'] );
		$_POST = [];
	}

	/** So does the event's own default modality — it IS the implicit session's. */
	public function test_virtual_default_modality_flips_the_access_switch() {
		$event_id = $this->make_event();
		$_POST    = [
			'anchor_event_start_date'              => '2027-06-01',
			'anchor_event_stream_default_modality' => 'virtual',
			'anchor_event_access_role_enabled'     => '0',
		];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );

		$this->assertTrue( $this->module()->get_meta( $event_id )['access_role_enabled'] );
		$_POST = [];
	}

	/** A virtual TIER flips it back on, through the one tier write point. */
	public function test_virtual_tier_flips_the_access_switch() {
		// Explicitly OFF, so the assertion is about the tier flip and not
		// about the default, which is on.
		$event_id = $this->make_event( [ 'registration_mode' => 'free', 'access_role_enabled' => false ] );
		$this->assertFalse( $this->module()->get_meta( $event_id )['access_role_enabled'] );

		$this->ticket_types()->save( $event_id, [
			[ 'label' => 'Livestream', 'price' => '0', 'active' => 1, 'modality' => 'virtual' ],
		] );

		$this->assertTrue( $this->module()->get_meta( $event_id )['access_role_enabled'] );
	}

	/** An in-person-only tier save leaves a switched-off event switched off. */
	public function test_in_person_tier_does_not_flip_the_access_switch() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free', 'access_role_enabled' => false ] );

		$this->ticket_types()->save( $event_id, [
			[ 'label' => 'General admission', 'price' => '0', 'active' => 1 ],
		] );

		$this->assertFalse( $this->module()->get_meta( $event_id )['access_role_enabled'] );
	}

	/** Clearing the stream does not by itself turn the switch off. */
	public function test_clearing_the_embed_alone_leaves_the_switch_on() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_access_role_enabled', true );
		update_post_meta( $event_id, '_anchor_event_stream_embed', [
			'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/1', 'raw' => '',
		] );

		// The stream goes away; the Access field is NOT on this POST. Removing
		// a stream is not a statement about the role — attendees already hold
		// it and the file manager is gating files on it, so only an explicit
		// untick (or Delete role) may take it away.
		$_POST = [
			'anchor_event_start_date'   => '2027-06-01',
			'anchor_event_stream_embed' => '',
		];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );
		$meta = $this->module()->get_meta( $event_id );

		$this->assertSame( [], $meta['stream_embed'], 'The stream itself is cleared.' );
		$this->assertTrue( $meta['access_role_enabled'], 'Losing the stream is not an untick.' );
		$_POST = [];
	}

	/** Clearing the stream AND unticking does turn it off — nothing forces it. */
	public function test_clearing_the_embed_with_an_untick_turns_it_off() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_access_role_enabled', true );
		update_post_meta( $event_id, '_anchor_event_stream_embed', [
			'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/1', 'raw' => '',
		] );

		$_POST = [
			'anchor_event_start_date'          => '2027-06-01',
			'anchor_event_stream_embed'        => '',
			'anchor_event_access_role_enabled' => '0',
		];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );
		$meta = $this->module()->get_meta( $event_id );

		$this->assertSame( [], $meta['stream_embed'] );
		$this->assertFalse( $meta['access_role_enabled'], 'With no stream left to force it, the untick stands.' );
		$_POST = [];
	}

	/** Re-ticking the box turns a switched-off event back on. */
	public function test_author_can_tick_the_switch_back_on() {
		$event_id = $this->make_event( [ 'access_role_enabled' => false ] );
		$_POST    = [
			'anchor_event_start_date'          => '2027-06-01',
			'anchor_event_access_role_enabled' => '1',
		];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );

		$this->assertTrue( $this->module()->get_meta( $event_id )['access_role_enabled'] );
		$this->assertSame( [], $this->module()->get_meta( $event_id )['stream_embed'], 'No stream needed — the file manager gates the files.' );
		$_POST = [];
	}

	/** A refused host keeps the previous embed rather than blanking it. */
	public function test_bad_stream_embed_keeps_previous_value() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_stream_embed', [
			'provider' => 'vimeo', 'kind' => 'iframe',
			'src' => 'https://player.vimeo.com/video/1', 'raw' => 'https://vimeo.com/1',
		] );

		$_POST = [
			'anchor_event_start_date'   => '2027-06-01',
			'anchor_event_stream_embed' => 'https://evil.example.com/x',
		];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );
		$meta = $this->module()->get_meta( $event_id );

		$this->assertSame( 'https://player.vimeo.com/video/1', $meta['stream_embed']['src'] );
		$_POST = [];
	}

	/** Clearing the field clears the embed (and leaves `virtual` to the checkbox). */
	public function test_empty_stream_embed_clears_it() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_stream_embed', [
			'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/1', 'raw' => '',
		] );

		$_POST = [ 'anchor_event_start_date' => '2027-06-01', 'anchor_event_stream_embed' => '' ];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );

		$this->module()->save_meta( $event_id );
		$this->assertSame( [], $this->module()->get_meta( $event_id )['stream_embed'] );
		$_POST = [];
	}
}
