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

use Anchor\Events\Events_Log;
use Anchor\Events\Module;
use Anchor\Events\Ticket_Types;

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
	 * Render the private front-end manager form via Reflection (Task 17 —
	 * same pattern Test_Group_Authoring_Save uses to prove the Location/
	 * Registration sections actually call the shared partials, not just
	 * that the partials themselves render correctly in isolation).
	 */
	private function render_front_end_form( $event_id ) {
		$method = new ReflectionMethod( Module::class, 'render_event_manager_form' );
		$method->setAccessible( true );
		return $method->invoke( $this->module(), $event_id );
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
				// Additive fields (Task 2): unposted modality/embed persist as
				// empty — '' means "inherit the event default", resolved on read.
				'modality' => '',
				'stream_embed' => [],
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

	public function test_manager_save_persists_full_custom_field_set() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload(
			[
				'anchor_event_registration_mode' => 'wc',
				'anchor_event_address_country' => 'USA',
				'anchor_event_price' => '$25',
				'anchor_event_hide_from_archive' => '1',
				'anchor_event_featured' => '1',
				'anchor_event_priority' => '7',
				'anchor_event_reminder_offsets' => '14,3,1',
				'anchor_event_status' => 'cancelled',
				'anchor_event_tickets' => [
					[
						'label' => 'General Admission',
						'price' => '25',
						'quota' => '30',
						'sale_start' => '2026-07-01',
						'sale_end' => '2026-07-31',
						'active' => '1',
					],
				],
				'anchor_email_tpl_confirmation' => '<p>Custom {event_title}</p><script>alert(1)</script>',
			]
		);

		$fallback = $this->module()->registration_mode( $event_id );
		$input = $this->call_save_event_manager_fields( $event_id, '2026-08-01', $fallback );

		$this->assertSame( 'USA', get_post_meta( $event_id, '_anchor_event_address_country', true ) );
		$this->assertSame( '$25', get_post_meta( $event_id, '_anchor_event_price', true ) );
		$this->assertTrue( (bool) get_post_meta( $event_id, '_anchor_event_hide_from_archive', true ) );
		$this->assertTrue( (bool) get_post_meta( $event_id, '_anchor_event_featured', true ) );
		$this->assertSame( 7, (int) get_post_meta( $event_id, '_anchor_event_priority', true ) );
		$this->assertSame( '14,3,1', get_post_meta( $event_id, '_anchor_event_reminder_offsets', true ) );
		$this->assertSame( 'manual', get_post_meta( $event_id, '_anchor_event_status_mode', true ) );
		$this->assertSame( 'cancelled', get_post_meta( $event_id, '_anchor_event_status', true ) );
		$this->assertSame( 'cancelled', $input['status'] );

		$tiers = get_post_meta( $event_id, Ticket_Types::META_KEY, true );
		$this->assertCount( 1, $tiers );
		$this->assertSame( 'General Admission', $tiers[0]['label'] );
		$this->assertSame( 25.0, $tiers[0]['price'] );
		$this->assertSame( 30, $tiers[0]['quota'] );

		$template = get_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', true );
		$this->assertStringContainsString( 'Custom {event_title}', $template );
		$this->assertStringNotContainsString( '<script', $template );
	}

	/* ------------------------------------------------------------------
	 * Task 17 — console parity: the front-end console renders and saves the
	 * SAME Livestream/Access partials (Module::render_livestream_fields() /
	 * render_access_fields(), Task 16) as the wp-admin metabox. The save
	 * side needed no change (event_authoring_input() already takes
	 * $post_id, forwarded here since Task 4) — this is a render-parity plus
	 * regression guard for the console surface specifically.
	 * ------------------------------------------------------------------ */

	/** The console saves the livestream and access fields identically to the metabox. */
	public function test_console_saves_livestream_and_access() {
		add_role( 'anchor_course_intro', 'Course: Intro', [] );
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );

		$_POST = [
			'anchor_event_type'                       => 'single',
			'anchor_event_registration_mode'          => 'free',
			'anchor_event_stream_embed'                => 'https://vimeo.com/31337',
			'anchor_event_stream_default_modality'     => 'hybrid',
			'anchor_event_in_person_includes_stream'   => '1',
			'anchor_event_stream_open_before_minutes'  => '20',
			'anchor_event_stream_close_after_minutes'  => '45',
			'anchor_event_required_roles'              => [ 'anchor_course_intro' ],
			'anchor_event_required_roles_mode'         => 'all',
		];
		$fallback = $this->module()->registration_mode( $event_id );
		$this->call_save_event_manager_fields( $event_id, '2027-07-01', $fallback );

		$meta = $this->module()->get_meta( $event_id );
		$this->assertSame( 'https://player.vimeo.com/video/31337', $meta['stream_embed']['src'] );
		$this->assertTrue( $meta['access_role_enabled'], 'The console save flips the switch the same way the metabox does — a saved stream forces it on.' );
		$this->assertSame( 'hybrid', $meta['stream_default_modality'] );
		$this->assertSame( 20, $meta['stream_open_before_minutes'] );
		$this->assertSame( 45, $meta['stream_close_after_minutes'] );
		$this->assertSame( [ 'anchor_course_intro' ], $meta['required_roles'] );
		$this->assertSame( 'all', $meta['required_roles_mode'] );
		remove_role( 'anchor_course_intro' );
	}

	/** The console form prints the same field names as the metabox (same shared partials, $admin = false). */
	public function test_console_form_renders_the_same_fields() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$meta     = $this->module()->get_meta( $event_id );
		$this->assertStringContainsString(
			'name="anchor_event_stream_embed"',
			$this->module()->render_livestream_fields( $event_id, $meta, false )
		);
		$console_access = $this->module()->render_access_fields( $event_id, $meta, false );
		$this->assertStringContainsString( 'name="anchor_event_access_role_enabled"', $console_access );
		// The hidden companion has to be on the CONSOLE form too, or a console
		// save silently becomes "field absent" and the switch is one-way there
		// (Task 4). Same renderer, so this is a regression guard.
		$this->assertStringContainsString( 'type="hidden" name="anchor_event_access_role_enabled" value="0"', $console_access );
	}

	/**
	 * Proves the WIRING, not just the partials: the full console form (as
	 * rendered by render_event_manager_form(), Location + Registration
	 * sections) actually includes both shared partials — not merely that
	 * render_livestream_fields()/render_access_fields() work when called
	 * directly, which the test above already covers.
	 */
	public function test_console_form_includes_livestream_and_access_sections() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$html     = $this->render_front_end_form( $event_id );

		$this->assertStringContainsString( 'anchor-event-livestream', $html, 'The Livestream partial must be wired into the console form.' );
		$this->assertStringContainsString( 'name="anchor_event_stream_embed"', $html );
		$this->assertStringContainsString( 'anchor-event-access', $html, 'The Access partial must be wired into the console form.' );
		$this->assertStringContainsString( 'type="hidden" name="anchor_event_access_role_enabled" value="0"', $html );

		// Location precedes Livestream, and Registration precedes Access —
		// mirrors the metabox's own section order (Task 17 brief).
		$this->assertLessThan(
			strpos( $html, 'anchor-event-livestream' ),
			strpos( $html, '>Location<' ),
			'Livestream must render after Location, matching the metabox.'
		);
		$this->assertLessThan(
			strpos( $html, 'anchor-event-access' ),
			strpos( $html, '>Registration<' ),
			'Access must render after Registration, matching the metabox.'
		);
	}

	/** The console can untick the switch, and it persists — same rule as the metabox. */
	public function test_console_can_untick_the_access_switch() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$this->assertTrue( $this->module()->get_meta( $event_id )['access_role_enabled'], 'On by default.' );

		// What the hidden companion posts when the box is unticked.
		$_POST    = [ 'anchor_event_access_role_enabled' => '0' ];
		$fallback = $this->module()->registration_mode( $event_id );
		$this->call_save_event_manager_fields( $event_id, '2027-07-01', $fallback );

		$this->assertFalse( $this->module()->get_meta( $event_id )['access_role_enabled'] );
	}

	/** And tick it back on for an in-person event whose handouts are role-gated. */
	public function test_console_can_tick_the_access_switch_back_on() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free', 'access_role_enabled' => false ] );

		$_POST    = [ 'anchor_event_access_role_enabled' => '1' ];
		$fallback = $this->module()->registration_mode( $event_id );
		$this->call_save_event_manager_fields( $event_id, '2027-07-01', $fallback );

		$meta = $this->module()->get_meta( $event_id );
		$this->assertTrue( $meta['access_role_enabled'] );
		$this->assertSame( [], $meta['stream_embed'], 'No stream — the file manager gates the handouts.' );
	}

	/** A console save that never rendered the Access section leaves it alone. */
	public function test_console_save_without_the_access_field_keeps_the_stored_value() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free', 'access_role_enabled' => false ] );

		$_POST    = [];
		$fallback = $this->module()->registration_mode( $event_id );
		$this->call_save_event_manager_fields( $event_id, '2027-07-01', $fallback );

		$this->assertFalse( $this->module()->get_meta( $event_id )['access_role_enabled'] );
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

	/**
	 * finding-10 — post_title/post_content are built via an unslash+sanitize
	 * pass and then handed straight to wp_insert_post(), which unslashes its
	 * postarr AGAIN internally (the same mechanism as update_post_meta()) —
	 * so a literal backslash was silently eaten (`Room A\B` stored as
	 * `Room AB`). Drives the REAL entry point (not the extracted
	 * save_event_manager_fields()), since the post itself is inserted in
	 * handle_event_manager_save() before that method is even called.
	 */
	public function test_handle_event_manager_save_preserves_a_literal_backslash_in_title_and_content() {
		$_POST = wp_slash( [
			'anchor_event_manager_nonce' => wp_create_nonce( 'anchor_event_manager_save' ),
			'event_id'                => 0,
			'redirect_to'             => 'https://example.org/manager/',
			'anchor_event_title'      => 'Room A\B',
			'anchor_event_content'    => '<p>Suite C:\Rooms</p>',
			'anchor_event_start_date' => '2026-09-01',
		] );
		$_REQUEST = $_POST;

		$trap = function ( $location ) {
			throw new Anchor_Lostpass_Redirected( (string) $location );
		};
		add_filter( 'wp_redirect', $trap );
		try {
			$this->module()->handle_event_manager_save();
			$this->fail( 'handle_event_manager_save() did not redirect.' );
		} catch ( Anchor_Lostpass_Redirected $e ) {
			// Expected — the success path redirects.
		} finally {
			remove_filter( 'wp_redirect', $trap );
		}

		$events = get_posts( [
			'post_type'      => Module::CPT,
			'post_status'    => 'any',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'posts_per_page' => 1,
		] );
		$this->assertNotEmpty( $events, 'The save must have created an event.' );
		$this->assertSame( 'Room A\B', $events[0]->post_title, 'The event title must round-trip a literal backslash.' );
		$this->assertStringContainsString( 'Suite C:\Rooms', $events[0]->post_content );
	}

	/* ------------------------------------------------------------------
	 * REG-D47 — the lost-password form answers the same way every time
	 * ------------------------------------------------------------------ */

	/** POST the lost-password form and return where it redirected. */
	private function post_lostpass( $login ) {
		$_POST = [
			'_anchor_lostpass_nonce' => wp_create_nonce( 'anchor_event_manager_lostpass' ),
			'redirect_to'            => 'https://example.org/manager/',
			'user_login'             => $login,
		];
		$_REQUEST = $_POST;

		$trap = function ( $location ) {
			throw new Anchor_Lostpass_Redirected( (string) $location );
		};
		add_filter( 'wp_redirect', $trap );
		try {
			$this->module()->handle_event_manager_lostpass();
		} catch ( Anchor_Lostpass_Redirected $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $trap );
		}

		$this->fail( 'handle_event_manager_lostpass() did not redirect.' );
	}

	/** The codes recorded in the site-wide error log. @return string[] */
	private function logged_codes() {
		$log = get_option( Events_Log::ERROR_OPTION, [] );
		return is_array( $log ) ? array_column( $log, 'code' ) : [];
	}

	/**
	 * A real account whose reset is denied must be indistinguishable from an
	 * account that does not exist. It used to answer `lostpass_error` while a
	 * missing account answered `lostpass_sent`, so submitting a list of
	 * candidate logins told an attacker which ones were real.
	 */
	public function test_a_denied_reset_answers_exactly_like_an_unknown_account() {
		delete_option( Events_Log::ERROR_OPTION );
		self::factory()->user->create( [ 'user_login' => 'realmanager', 'role' => 'editor' ] );

		$unknown = $this->post_lostpass( 'nobody-here' );
		$this->assertStringContainsString( 'lostpass_sent', $unknown );

		$deny = '__return_false';
		add_filter( 'allow_password_reset', $deny );
		try {
			$denied = $this->post_lostpass( 'realmanager' );
		} finally {
			remove_filter( 'allow_password_reset', $deny );
		}

		$this->assertStringContainsString( 'lostpass_sent', $denied );
		$this->assertStringNotContainsString( 'lostpass_error', $denied );
		// The operator still learns what happened — from the error log, not the page.
		$this->assertContains( 'lostpass_reset_denied', $this->logged_codes() );

		delete_option( Events_Log::ERROR_OPTION );
	}

	/** The option the Livestream "Default attendance" select pre-selects. */
	private function selected_default_modality( $html ) {
		$this->assertSame( 1, preg_match( '#<select[^>]*name="anchor_event_stream_default_modality"[^>]*>(.*?)</select>#s', $html, $select ), 'The select renders.' );
		$this->assertSame( 1, preg_match( '#<option value="([a-z_]+)"\s+selected#', $select[1], $opt ), 'One option is pre-selected.' );
		return $opt[1];
	}

	/** A pre-modality virtual event: virtual=1, a Vimeo link, NO stored stream_default_modality. */
	private function legacy_virtual_event() {
		$event_id = $this->make_event( [
			'registration_mode'   => 'free',
			'access_role_enabled' => true,
			'virtual'             => true,
			'virtual_url'         => 'https://vimeo.com/76979871',
		] );
		$this->assertFalse( metadata_exists( 'post', $event_id, '_anchor_event_stream_default_modality' ), 'Sanity: legacy shape.' );
		$this->assertSame( 'virtual', $this->module()->resolved_sessions( $event_id )[0]['modality'], 'Sanity: the bridge reads it as virtual.' );
		return $event_id;
	}

	/** Final review I1, console surface: same shared partial, same round-trip. */
	public function test_console_first_save_of_a_legacy_virtual_event_keeps_it_virtual() {
		$event_id = $this->legacy_virtual_event();
		$selected = $this->selected_default_modality( $this->render_front_end_form( $event_id ) );
		$this->assertSame( 'virtual', $selected );

		$_POST = [
			'anchor_event_type'                    => 'single',
			'anchor_event_registration_mode'       => 'free',
			'anchor_event_virtual'                 => '1',
			'anchor_event_virtual_url'             => 'https://vimeo.com/76979871',
			'anchor_event_stream_embed'            => '',
			'anchor_event_stream_default_modality' => $selected,
			'anchor_event_access_role_enabled'     => '1',
		];
		$this->call_save_event_manager_fields( $event_id, '2027-07-01', $this->module()->registration_mode( $event_id ) );
		$_POST = [];

		$this->assertSame( 'virtual', $this->module()->resolved_sessions( $event_id )[0]['modality'] );
		$this->assertNotSame( '', $this->module()->room_url( $event_id ) );
	}
}

/** Thrown from the wp_redirect filter so the handler's exit never runs. */
class Anchor_Lostpass_Redirected extends \Exception {}
