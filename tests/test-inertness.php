<?php
/**
 * Switch semantics for `access_role_enabled` (spec §2 row 8a, §3.1, §4
 * preamble, §8).
 *
 * The owner's ruling, 2026-09-23 (superseding "inert by default"): EVERY
 * plugin-registered event grants its confirmed attendees the event role and
 * the account that carries it, in person or not, because event materials are
 * assigned by role. The checkbox is a real, reversible switch — un-ticking it
 * stops future grants and never strips existing holders — and the ROOM is a
 * separate question that also needs a resolvable stream.
 *
 * Every guard that makes those rules true lives in a different class, so this
 * suite is the one place they are asserted as a whole. Spec §8's "Switch
 * semantics" bullet, (a) to (f), is the table of contents; (f) — the backfill
 * — is asserted in Task 19's Test_Entitlements, next to the action it tests.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group inertness
 */
class Test_Inertness extends Anchor_Events_TestCase {

	/** @var int[] Events whose roles a test may have minted. */
	private $minted = [];

	public function tear_down() {
		foreach ( $this->minted as $event_id ) {
			remove_role( 'anchor_event_' . $event_id );
		}
		$this->minted = [];
		parent::tear_down();
	}

	/**
	 * An ordinary in-person event registered through the plugin.
	 *
	 * `access_role_enabled` is NOT passed: the point of most of this suite is
	 * what the DEFAULT does, and the default is true.
	 */
	private function plain_event( $mode = 'free', array $meta = [] ) {
		$start          = time() + DAY_IN_SECONDS;
		$event_id       = $this->make_event( array_merge( [
			'registration_mode' => $mode,
			'timezone'          => 'UTC',
			'start_date'        => gmdate( 'Y-m-d', $start ),
			'start_time'        => gmdate( 'H:i', $start ),
			'start_ts'          => $start,
			'end_ts'            => $start + 3600,
			'venue'             => 'The Grand Ballroom',
		], $meta ) );
		$this->minted[] = $event_id;
		return $event_id;
	}

	/** The same event with the operator's switch explicitly off. */
	private function switched_off_event( $mode = 'free' ) {
		return $this->plain_event( $mode, [ 'access_role_enabled' => false ] );
	}

	/** Module::room_url(), landed in Task 11. */
	private function room_url( $event_id ) {
		return (string) $this->module()->room_url( (int) $event_id );
	}

	/* -----------------------------------------------------------------
	 * (a) The default: role and account, no room.
	 * --------------------------------------------------------------- */

	/**
	 * Spec §8 (a). A fresh free/wc event with nothing else set grants the role
	 * and creates an account on a confirmed seat — and still has no room,
	 * because no stream resolves.
	 *
	 * @dataProvider registration_modes
	 * @param string $mode
	 */
	public function test_default_grants_the_role_and_creates_the_account( $mode ) {
		$event_id = $this->plain_event( $mode );

		$this->assertTrue(
			$this->module()->get_meta( $event_id )['access_role_enabled'],
			'Every plugin-registered event grants the role by default (owner decision 2026-09-23).'
		);
		$this->assertTrue( $this->module()->entitlements->enabled( $event_id ) );

		// A free/wc seat is BORN confirmed (D2) — the path most attendees take.
		$seat_id = $this->make_seat( $event_id, [ 'name' => 'Plain Attendee', 'email' => 'plain@example.test' ] );
		$seat    = $this->registrations()->get_seat( $seat_id );

		// --- The account exists and the seat names it. ---
		$user = get_user_by( 'email', 'plain@example.test' );
		$this->assertInstanceOf( 'WP_User', $user, 'A confirmed attendee gets an account.' );
		$this->assertSame( (int) $user->ID, (int) get_post_meta( $seat_id, '_anchor_event_user_id', true ) );

		// --- The role exists, is held, and is recorded as a seat grant. ---
		$slug = 'anchor_event_' . $event_id;
		$this->assertArrayHasKey( $slug, wp_roles()->roles, 'The event role is minted on the first grant.' );
		$this->assertTrue( $this->module()->entitlements->holds_role( $event_id, (int) $user->ID ) );
		$this->assertSame( 'seat', $this->module()->entitlements->grant_record( $event_id, (int) $user->ID )['source'] );
		$this->assertSame( 'yes', $this->module()->roster->access_state( $event_id, $seat ) );

		// --- But there is NO room: the role is not a stream. ---
		$this->assertSame( '', $this->room_url( $event_id ), 'No stream resolves, so there is no room.' );

		$tokens = $this->module()->email_tokens( [ 'event_id' => $event_id, 'seat' => $seat ] );
		// `room_link` joins the token map in Task 14; until then the key is
		// simply absent, which is the same empty string this asserts.
		$this->assertSame( '', (string) ( $tokens['room_link'] ?? '' ), '{room_link} is empty with no stream to join.' );

		$this->assertFalse(
			$this->module()->entitlements->can_access_stream( $event_id, 0, (int) $user->ID ),
			'Holding the role is not stream access.'
		);

		// The REST room endpoint does not exist until Task 12 (404) and
		// answers 403/404 for an event with no room after it. "Not 200" is the
		// permanent assertion, correct on both sides of that task.
		$response = rest_do_request( new WP_REST_Request( 'GET', '/anchor-events/v1/events/' . $event_id . '/room' ) );
		$this->assertNotSame( 200, $response->get_status(), 'A stream-less event reports no room state.' );
	}

	/* -----------------------------------------------------------------
	 * (b) Switched off: the whole feature is absent.
	 * --------------------------------------------------------------- */

	/**
	 * Spec §8 (b). With the switch explicitly false: confirm a seat, cancel
	 * it, render its confirmation email, request /live/, open the roster.
	 * Nothing anywhere.
	 *
	 * @dataProvider registration_modes
	 * @param string $mode
	 */
	public function test_a_switched_off_event_is_untouched( $mode ) {
		$event_id = $this->switched_off_event( $mode );
		$before   = count_users()['total_users'];

		$this->assertFalse( $this->module()->get_meta( $event_id )['access_role_enabled'] );
		$this->assertFalse( $this->module()->entitlements->enabled( $event_id ) );

		// 1. Confirm a seat.  2. Cancel it.
		$seat_id = $this->make_seat( $event_id, [ 'name' => 'Off Attendee', 'email' => 'off@example.test' ] );
		$seat    = $this->registrations()->get_seat( $seat_id );
		$this->registrations()->update_status( $seat_id, \Anchor\Events\Registrations::STATUS_CANCELLED );

		// 3. Render its confirmation email.  4. Ask for the room.
		$tokens = $this->module()->email_tokens( [ 'event_id' => $event_id, 'seat' => $seat ] );
		$room   = $this->room_url( $event_id );

		// 5. Hit the REST room endpoint.  6. Open the roster.
		$response = rest_do_request( new WP_REST_Request( 'GET', '/anchor-events/v1/events/' . $event_id . '/room' ) );
		$access   = $this->module()->roster->access_state( $event_id, $seat );

		// --- No account was created, on any path. ---
		$this->assertSame( $before, count_users()['total_users'], 'No account is created for a switched-off event.' );
		$this->assertNull( get_user_by( 'email', 'off@example.test' ) ?: null );
		$this->assertSame( 0, (int) get_post_meta( $seat_id, '_anchor_event_user_id', true ) );

		// --- No role was minted. ---
		$this->assertArrayNotHasKey( 'anchor_event_' . $event_id, wp_roles()->roles );
		$this->assertNull( get_role( 'anchor_event_' . $event_id ) );

		// --- No room, no link, no Access column. ---
		$this->assertSame( '', $room, 'A switched-off event has no room URL.' );
		$this->assertNotSame( 200, $response->get_status(), 'A switched-off event reports no room state.' );
		$this->assertSame( '', (string) ( $tokens['room_link'] ?? '' ), '{room_link} is empty for a switched-off event.' );
		$this->assertSame( 'off', $access, 'The roster reports the whole feature as off, not "no access".' );
		// The CTA's own "no livestream button" assertion lives in Task 14's
		// Test_Email_Templates, where default_email_cta() becomes public;
		// duplicating it here would only test a private method.
	}

	/** @return array<string,string[]> */
	public function registration_modes() {
		return [ 'free' => [ 'free' ], 'woocommerce' => [ 'wc' ] ];
	}

	/* -----------------------------------------------------------------
	 * (c) The un-tick looks forward only.
	 * --------------------------------------------------------------- */

	/** Spec §8 (c). Un-ticking keeps every existing holder and stops the next grant. */
	public function test_unticking_keeps_holders_and_stops_future_grants() {
		$event_id = $this->plain_event();

		// One attendee registers while the switch is on (the default).
		$this->make_seat( $event_id, [ 'name' => 'First In', 'email' => 'first@example.test' ] );
		$first = get_user_by( 'email', 'first@example.test' );
		$this->assertInstanceOf( 'WP_User', $first );
		$this->assertTrue( $this->module()->entitlements->holds_role( $event_id, (int) $first->ID ) );

		// The operator unticks the box. The hidden companion Task 16 renders
		// means the field is PRESENT with value 0 — a deliberate untick, not
		// a form that simply did not carry the control.
		//
		// wp_set_current_user() MUST run before wp_create_nonce(): a nonce is
		// bound to the acting user, so creating it under whatever user was
		// active before this line and switching afterward mints a nonce
		// save_meta()'s own wp_verify_nonce() rejects — the save then no-ops
		// silently and every assertion below fails for the wrong reason.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST = [
			'anchor_event_start_date'          => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
			'anchor_event_access_role_enabled' => '0',
		];
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );
		$this->module()->save_meta( $event_id );
		$_POST = [];

		$this->assertFalse( $this->module()->get_meta( $event_id )['access_role_enabled'] );

		// The existing holder is untouched: only Delete role removes people.
		$this->assertTrue(
			$this->module()->entitlements->holds_role( $event_id, (int) $first->ID ),
			'Un-ticking never strips an existing holder — that is Delete role.'
		);
		$this->assertNotNull( get_role( 'anchor_event_' . $event_id ), 'The role itself survives the untick.' );

		// The NEXT confirmed seat gets nothing at all.
		$before  = count_users()['total_users'];
		$seat_id = $this->make_seat( $event_id, [ 'name' => 'Too Late', 'email' => 'second@example.test', 'seat_index' => 2 ] );

		$this->assertNull( get_user_by( 'email', 'second@example.test' ) ?: null );
		$this->assertSame( $before, count_users()['total_users'] );
		$this->assertSame( 0, (int) get_post_meta( $seat_id, '_anchor_event_user_id', true ) );
	}

	/* -----------------------------------------------------------------
	 * (d) An absent field is not an answer.
	 * --------------------------------------------------------------- */

	/** Spec §8 (d). A save whose form did not carry the field keeps the stored value — both ways. */
	public function test_a_save_without_the_field_keeps_the_stored_value() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post = function ( $event_id ) {
			$_POST = [ 'anchor_event_start_date' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) ];
			$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );
			$this->module()->save_meta( $event_id );
			$_POST = [];
		};

		$off = $this->switched_off_event();
		$post( $off );
		$this->assertFalse(
			$this->module()->get_meta( $off )['access_role_enabled'],
			'A partial save must not resurrect an event the operator switched off.'
		);

		$on = $this->plain_event();
		$post( $on );
		$this->assertTrue(
			$this->module()->get_meta( $on )['access_role_enabled'],
			'A partial save must not strip the role from attendees who hold it.'
		);
	}

	/* -----------------------------------------------------------------
	 * (e) The three force-true flips.
	 * --------------------------------------------------------------- */

	/** Spec §8 (e), flip 1: saving a stream forces the switch back on. */
	public function test_saving_a_stream_forces_the_switch_on() {
		$event_id = $this->switched_off_event();

		// wp_set_current_user() before wp_create_nonce() — see the comment in
		// test_unticking_keeps_holders_and_stops_future_grants().
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST = [
			'anchor_event_start_date'          => '2027-06-01',
			'anchor_event_stream_embed'        => 'https://vimeo.com/909090',
			'anchor_event_access_role_enabled' => '0', // Unticked, and overruled.
		];
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );
		$this->module()->save_meta( $event_id );
		$_POST = [];

		$this->assertTrue( $this->module()->get_meta( $event_id )['access_role_enabled'] );
		$this->assertTrue( $this->module()->entitlements->enabled( $event_id ) );

		$this->assertNotSame( '', $this->room_url( $event_id ), 'A stream is what creates the room.' );
	}

	/** Spec §8 (e), flip 2: a virtual/hybrid SESSION forces it on, with no embed anywhere. */
	public function test_saving_a_virtual_session_forces_the_switch_on() {
		$event_id = $this->switched_off_event();

		// wp_set_current_user() before wp_create_nonce() — see the comment in
		// test_unticking_keeps_holders_and_stops_future_grants().
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST = [
			'anchor_event_start_date'          => '2027-06-01',
			'anchor_event_type'                => 'multisession',
			'anchor_event_access_role_enabled' => '0',
			'anchor_event_sessions'            => [
				[ 'date' => '2027-06-01', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Day 1', 'modality' => 'in_person' ],
				[ 'date' => '2027-06-02', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Day 2', 'modality' => 'virtual' ],
			],
		];
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );
		$this->module()->save_meta( $event_id );
		$_POST = [];

		$this->assertTrue( $this->module()->get_meta( $event_id )['access_role_enabled'] );
		$this->assertSame( [], $this->module()->get_meta( $event_id )['stream_embed'] );
	}

	/** Spec §8 (e), flip 3: a virtual TIER forces it on, through the one tier write point. */
	public function test_saving_a_virtual_tier_forces_the_switch_on() {
		$event_id = $this->switched_off_event();

		$this->ticket_types()->save( $event_id, [
			[ 'label' => 'Livestream', 'price' => '0', 'active' => 1, 'modality' => 'virtual' ],
		] );

		$this->assertTrue( $this->module()->get_meta( $event_id )['access_role_enabled'] );
		$this->assertSame( [], $this->module()->get_meta( $event_id )['stream_embed'] );
	}

	/** Clearing the stream again does NOT flip it back — that takes an untick. */
	public function test_clearing_the_stream_alone_leaves_the_switch_on() {
		$event_id = $this->plain_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST = [
			'anchor_event_start_date'   => '2027-06-01',
			'anchor_event_stream_embed' => 'https://vimeo.com/909090',
		];
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );
		$this->module()->save_meta( $event_id );

		$_POST = [
			'anchor_event_start_date'   => '2027-06-01',
			'anchor_event_stream_embed' => '',
		];
		$_POST[ \Anchor\Events\Module::NONCE ] = wp_create_nonce( \Anchor\Events\Module::NONCE );
		$this->module()->save_meta( $event_id );
		$_POST = [];

		$meta = $this->module()->get_meta( $event_id );
		$this->assertSame( [], $meta['stream_embed'], 'The stream is gone…' );
		$this->assertSame( '', $this->room_url( $event_id ), '…so the room is gone with it…' );
		$this->assertTrue( $meta['access_role_enabled'], '…but the attendees who hold the role keep it.' );
	}

	/* -----------------------------------------------------------------
	 * (f) The backfill is asserted in Task 19's Test_Entitlements.
	 * --------------------------------------------------------------- */

	/**
	 * Spec §8 (f) — "the backfill action grants every confirmed seat exactly
	 * once" — lives in Task 19's Test_Entitlements
	 * (test_backfill_grants_every_confirmed_seat_once and its siblings), next
	 * to Entitlements::backfill() and the admin-post handler it exercises.
	 * Named here so the §8 checklist reads complete in one file.
	 *
	 * @doesNotPerformAssertions
	 */
	public function test_backfill_is_covered_in_test_entitlements() {
	}

	/** An event that is never stream-capable is out of the feature whatever the switch says. */
	public function test_external_registration_is_never_in_the_feature() {
		$event_id = $this->plain_event( 'external' );

		$this->assertFalse( $this->module()->entitlements->enabled( $event_id ) );

		$before = count_users()['total_users'];
		$this->make_seat( $event_id, [ 'email' => 'external@example.test' ] );

		$this->assertSame( $before, count_users()['total_users'] );
		$this->assertNull( get_role( 'anchor_event_' . $event_id ) );
	}
}
