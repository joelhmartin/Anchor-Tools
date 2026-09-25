<?php
/**
 * Entitlements: roles, grants, accounts, access (virtual-events spec §4).
 *
 * Roles live in the `wp_user_roles` option and in the $wp_roles GLOBAL, and the
 * global survives the per-test transaction rollback — so every test that mints
 * one deletes it again in tearDown.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Entitlements;

/**
 * @group entitlements
 */
class Test_Entitlements extends Anchor_Events_TestCase {

	/** @var int[] Event ids whose roles this test minted. */
	private $minted = [];

	/** @return Entitlements */
	protected function ent() {
		return $this->module()->entitlements;
	}

	private function event( array $meta = [] ) {
		$id             = $this->make_event( array_merge( [ 'registration_mode' => 'free' ], $meta ) );
		$this->minted[] = $id;
		return $id;
	}

	public function tear_down() {
		foreach ( $this->minted as $event_id ) {
			remove_role( 'anchor_event_' . $event_id );
		}
		$this->minted = [];
		$_POST        = [];
		$_REQUEST     = [];
		parent::tear_down();
	}

	/** A streamable event with the switch on — the shape every §4 test needs. */
	private function enabled_event( array $meta = [] ) {
		return $this->event( array_merge( [ 'access_role_enabled' => true ], $meta ) );
	}

	/**
	 * A streamable event with the switch explicitly OFF.
	 *
	 * The default is TRUE (spec §3.1, owner decision 2026-09-23), so every
	 * test whose POINT is the off state has to say so — `$this->event()` on
	 * its own is now an ON event.
	 */
	private function disabled_event( array $meta = [] ) {
		return $this->event( array_merge( [ 'access_role_enabled' => false ], $meta ) );
	}

	/** enabled() is stream_capable() AND the master switch (spec §4 preamble). */
	public function test_enabled_is_capability_and_the_switch() {
		$default = $this->event();
		$this->assertTrue( $this->module()->stream_capable( $default ), 'A free event IS stream-capable…' );
		$this->assertTrue( $this->ent()->enabled( $default ), '…and by default it is in the feature too.' );

		$off = $this->disabled_event();
		$this->assertTrue( $this->module()->stream_capable( $off ), 'Still stream-capable…' );
		$this->assertFalse( $this->ent()->enabled( $off ), '…but the operator switched it off.' );

		$on = $this->enabled_event();
		$this->assertTrue( $this->ent()->enabled( $on ) );

		$external = $this->event( [ 'registration_mode' => 'external', 'access_role_enabled' => true ] );
		$this->assertFalse( $this->ent()->enabled( $external ), 'The switch cannot override decision 1.' );
	}

	/** Roles are minted lazily, named after the event, and hold no capabilities. */
	public function test_role_minted_lazily_with_no_caps() {
		$event_id = $this->enabled_event( [ 'title' => 'Laser Bootcamp' ] );

		$this->assertSame( '', $this->ent()->role_for( $event_id, false ), 'No grant yet, no role.' );

		$slug = $this->ent()->role_for( $event_id );
		$this->assertSame( 'anchor_event_' . $event_id, $slug );

		$role = get_role( $slug );
		$this->assertNotNull( $role );
		$this->assertSame( [], array_filter( (array) $role->capabilities ), 'The role is a membership tag, not a permission.' );
		$this->assertSame( 'Event: Laser Bootcamp', wp_roles()->roles[ $slug ]['name'] );
	}

	/** Granting adds the role additively and records why. */
	public function test_grant_is_additive_and_recorded() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertTrue( $this->ent()->grant( $event_id, $user_id, 'manual' ) );

		$user = new WP_User( $user_id );
		$this->assertContains( 'subscriber', $user->roles, 'The existing role is untouched.' );
		$this->assertContains( 'anchor_event_' . $event_id, $user->roles );

		$record = $this->ent()->grant_record( $event_id, $user_id );
		$this->assertSame( 'manual', $record['source'] );
		$this->assertGreaterThan( 0, $record['at'] );
	}

	/** Both actions fire with the documented arguments. */
	public function test_grant_and_revoke_actions_fire() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$seen     = [];

		$grab = function ( $e, $u, $s ) use ( &$seen ) { $seen[] = [ $e, $u, $s ]; };
		add_action( 'anchor_events_access_granted', $grab, 10, 3 );
		add_action( 'anchor_events_access_revoked', $grab, 10, 3 );

		$this->ent()->grant( $event_id, $user_id, 'seat' );
		$this->ent()->revoke( $event_id, $user_id, 'seat' );

		remove_action( 'anchor_events_access_granted', $grab, 10 );
		remove_action( 'anchor_events_access_revoked', $grab, 10 );

		$this->assertSame( [ [ $event_id, $user_id, 'seat' ], [ $event_id, $user_id, 'seat' ] ], $seen );
	}

	/** A repeat grant with the same source is a no-op: no rewrite, no action. */
	public function test_repeat_grant_same_source_is_a_noop() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$fired    = 0;

		$count = function () use ( &$fired ) { $fired++; };
		add_action( 'anchor_events_access_granted', $count );

		$this->assertTrue( $this->ent()->grant( $event_id, $user_id, 'seat' ) );
		$first = $this->ent()->grant_record( $event_id, $user_id );

		$this->assertFalse( $this->ent()->grant( $event_id, $user_id, 'seat' ), 'Already held, already recorded — nothing changed.' );
		$second = $this->ent()->grant_record( $event_id, $user_id );

		remove_action( 'anchor_events_access_granted', $count );

		$this->assertSame( 1, $fired, 'The action fires once, not on the repeat call.' );
		$this->assertSame( $first, $second, 'The record — including its `at` — is untouched by the repeat.' );
	}

	/** A seat grant, later upgraded to manual, rewrites the record and fires again. */
	public function test_grant_seat_then_manual_upgrades_and_fires_again() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$fired    = 0;

		$count = function () use ( &$fired ) { $fired++; };
		add_action( 'anchor_events_access_granted', $count );

		$this->assertTrue( $this->ent()->grant( $event_id, $user_id, 'seat' ) );
		$this->assertTrue( $this->ent()->grant( $event_id, $user_id, 'manual' ), 'The upgrade IS a change.' );

		remove_action( 'anchor_events_access_granted', $count );

		$this->assertSame( 'manual', $this->ent()->grant_record( $event_id, $user_id )['source'] );
		$this->assertSame( 2, $fired, 'Both the original grant and the upgrade fire.' );
	}

	/** A manual grant can never be downgraded by a later seat grant. */
	public function test_grant_manual_then_seat_does_not_downgrade() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$fired    = 0;

		$count = function () use ( &$fired ) { $fired++; };
		add_action( 'anchor_events_access_granted', $count );

		$this->assertTrue( $this->ent()->grant( $event_id, $user_id, 'manual' ) );
		$first = $this->ent()->grant_record( $event_id, $user_id );

		$this->assertFalse( $this->ent()->grant( $event_id, $user_id, 'seat' ), 'A seat grant cannot downgrade a comp.' );
		$second = $this->ent()->grant_record( $event_id, $user_id );

		remove_action( 'anchor_events_access_granted', $count );

		$this->assertSame( 'manual', $second['source'], 'Still manual.' );
		$this->assertSame( $first, $second, 'No rewrite at all — not even a refreshed timestamp.' );
		$this->assertSame( 1, $fired, 'The seat attempt fires nothing.' );
	}

	/** A seat grant restoring a role stripped externally must not rewrite a
	 * manual grant record to seat (CodeRabbit PR #27, class-entitlements.php:303). */
	public function test_seat_grant_restoring_a_stripped_role_keeps_the_manual_source() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$slug     = $this->ent()->role_for( $event_id );

		$this->assertTrue( $this->ent()->grant( $event_id, $user_id, 'manual' ) );

		// Something outside Entitlements strips the role by writing the
		// capabilities meta directly (e.g. a raw import/reset) — NOT through
		// WP_User::remove_role(), which fires `remove_user_role` and would
		// have Entitlements' own on_remove_user_role() listener put it right
		// back (see test_remove_role_of_a_granted_event_role_is_reapplied);
		// this is the actually-realistic "role missing" trigger the finding
		// describes, one the listener never sees.
		global $wpdb;
		\update_user_meta( $user_id, $wpdb->get_blog_prefix() . 'capabilities', [] );
		\clean_user_cache( $user_id );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $user_id ) );

		$this->assertTrue( $this->ent()->grant( $event_id, $user_id, 'seat' ), 'The role is restored — that IS a change.' );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ), 'Role is back.' );
		$this->assertSame( 'manual', $this->ent()->grant_record( $event_id, $user_id )['source'], 'Record is still manual.' );

		// A later seat cancellation must not revoke the (still-manual) grant.
		$seat_id = $this->make_seat( $event_id, [ 'user_id' => $user_id ] );
		$this->registrations()->update_status( $seat_id, \Anchor\Events\Registrations::STATUS_CANCELLED );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ), 'A seat cancellation cannot strip a comp.' );
	}

	/** Renaming the event renames the role. */
	public function test_role_renames_with_the_title() {
		$event_id = $this->event( [ 'title' => 'Old Name' ] );
		$slug     = $this->ent()->role_for( $event_id );

		wp_update_post( [ 'ID' => $event_id, 'post_title' => 'New Name' ] );

		$this->assertSame( 'Event: New Name', wp_roles()->roles[ $slug ]['name'] );

		// Core's roles option is autoloaded on every request; the rename must
		// not flip it to autoload=off (final review minor).
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare(
			"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
			wp_roles()->role_key
		) );
		$this->assertNotContains( $autoload, [ 'no', 'off' ], 'rename_role() must not change the autoload flag of core\'s wp_user_roles.' );
	}

	/** The role survives the event being trashed. */
	public function test_role_survives_trash() {
		$event_id = $this->event();
		$user_id  = self::factory()->user->create();
		$this->ent()->grant( $event_id, $user_id, 'seat' );

		wp_trash_post( $event_id );

		$this->assertNotNull( get_role( 'anchor_event_' . $event_id ) );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ) );
	}

	/** Deleting the role strips it from every holder and reports the count. */
	public function test_delete_role_strips_holders() {
		$event_id = $this->event();
		$a = self::factory()->user->create();
		$b = self::factory()->user->create();
		$this->ent()->grant( $event_id, $a, 'seat' );
		$this->ent()->grant( $event_id, $b, 'manual' );

		$this->assertSame( 2, $this->ent()->role_members( $event_id ) );
		$this->assertSame( 2, $this->ent()->delete_role( $event_id ) );

		$this->assertNull( get_role( 'anchor_event_' . $event_id ) );
		$this->assertNotContains( 'anchor_event_' . $event_id, ( new WP_User( $a ) )->roles );
		$this->assertSame( [], $this->ent()->grants_for_user( $a ) );
	}

	/** A seat on a switch-off event grants nothing and mints nothing. */
	public function test_disabled_event_seat_grants_nothing() {
		$event_id = $this->disabled_event(); // The default is TRUE, so say so.
		$user_id  = self::factory()->user->create( [ 'user_email' => 'inert@example.test' ] );

		$seat_id = $this->make_seat( $event_id, [ 'email' => 'inert@example.test' ] );

		$this->assertFalse( $this->ent()->holds_role( $event_id, $user_id ) );
		$this->assertNull( get_role( 'anchor_event_' . $event_id ), 'No role is minted for a switched-off event.' );
		$this->assertSame( [], $this->ent()->grants_for_user( $user_id ) );

		// And the cancellation half is just as inert — it must not reach
		// maybe_revoke_seat_grant() and start querying seats either.
		$this->registrations()->update_status( $seat_id, \Anchor\Events\Registrations::STATUS_CANCELLED );
		$this->assertSame( [], $this->ent()->grants_for_user( $user_id ) );
	}

	/** A seat BORN confirmed grants access — create_seat() never transitions. */
	public function test_seat_created_confirmed_grants() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'ada@example.test' ] );

		$this->make_seat( $event_id, [ 'email' => 'ada@example.test' ] );

		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ) );
		$this->assertSame( 'seat', $this->ent()->grant_record( $event_id, $user_id )['source'] );
	}

	/** A pending seat grants nothing until it is confirmed. */
	public function test_pending_seat_grants_nothing_then_promotes() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'bob@example.test' ] );

		$seat_id = $this->make_seat( $event_id, [
			'email'  => 'bob@example.test',
			'status' => \Anchor\Events\Registrations::STATUS_PENDING,
		] );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $user_id ) );

		$this->registrations()->update_status( $seat_id, \Anchor\Events\Registrations::STATUS_CONFIRMED );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ) );
	}

	/** Cancelling the only confirmed seat revokes. */
	public function test_cancel_revokes() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'cara@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'cara@example.test' ] );

		$this->registrations()->update_status( $seat_id, \Anchor\Events\Registrations::STATUS_CANCELLED );

		$this->assertFalse( $this->ent()->holds_role( $event_id, $user_id ) );
	}

	/** A second confirmed seat keeps access alive. */
	public function test_cancel_with_a_second_confirmed_seat_keeps_access() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'dee@example.test' ] );
		$first    = $this->make_seat( $event_id, [ 'email' => 'dee@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'dee@example.test', 'seat_index' => 2 ] );

		$this->registrations()->update_status( $first, \Anchor\Events\Registrations::STATUS_CANCELLED );

		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ) );
	}

	/** A manual grant survives a seat cancellation. */
	public function test_cancel_never_strips_a_manual_grant() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'eve@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'eve@example.test' ] );
		$this->ent()->grant( $event_id, $user_id, 'manual' );

		$this->registrations()->update_status( $seat_id, \Anchor\Events\Registrations::STATUS_REFUNDED );

		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ) );
		$this->assertSame( 'manual', $this->ent()->grant_record( $event_id, $user_id )['source'] );
	}

	/** Branch 0: a switched-off event resolves nothing and creates nothing. */
	public function test_ensure_user_is_inert_when_the_switch_is_off() {
		$event_id = $this->disabled_event(); // The default is TRUE, so say so.
		$seat_id  = $this->make_seat( $event_id, [ 'name' => 'Nobody', 'email' => 'never@example.test' ] );
		$before   = count_users()['total_users'];

		$this->assertSame( 0, $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) ) );
		$this->assertNull( get_user_by( 'email', 'never@example.test' ) ?: null );
		$this->assertSame( $before, count_users()['total_users'] );
		$this->assertSame( 0, (int) get_post_meta( $seat_id, '_anchor_event_user_id', true ) );
	}

	/** Branch 1: the seat already names a user. */
	public function test_ensure_user_uses_stored_user_id() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create();
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'nobody@example.test' ] );
		update_post_meta( $seat_id, '_anchor_event_user_id', $user_id );

		$this->assertSame( $user_id, $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) ) );
	}

	/**
	 * Branch 2: an order seat with a customer id resolves to that customer
	 * when the seat IS the customer's — its email matches the account's,
	 * compared case-insensitively.
	 */
	public function test_ensure_user_uses_order_customer_id() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'buyer@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'Buyer@Example.test', 'customer_id' => $user_id ] );

		$this->assertSame( $user_id, $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) ) );
		$this->assertSame( $user_id, (int) get_post_meta( $seat_id, '_anchor_event_user_id', true ) );
	}

	/** Branch 2: a seat with no email of its own belongs to the order's customer. */
	public function test_ensure_user_uses_order_customer_id_for_a_seat_with_no_email() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'buyer2@example.test' ] );
		// Pending: no lifecycle grant, so ensure_user() below is the first resolution.
		$seat_id  = $this->make_seat( $event_id, [
			'email'       => 'placeholder@example.test',
			'customer_id' => $user_id,
			'status'      => \Anchor\Events\Registrations::STATUS_PENDING,
		] );
		delete_post_meta( $seat_id, '_anchor_event_email' );

		$this->assertSame( $user_id, $this->ent()->ensure_user( [ 'id' => $seat_id ] ) );
	}

	/**
	 * Final review C1: an attendee seat on a logged-in buyer's order carries
	 * the BUYER's customer id, but it is somebody else's seat. It must resolve
	 * to the attendee's own account (here: a new one), never to the buyer —
	 * otherwise every attendee email mints the buyer's sign-in token.
	 */
	public function test_ensure_user_ignores_a_customer_id_whose_email_differs() {
		$event_id = $this->enabled_event();
		$buyer_id = self::factory()->user->create( [ 'user_email' => 'payer@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'colleague@example.test', 'customer_id' => $buyer_id ] );

		$resolved = $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) );
		$attendee = get_user_by( 'email', 'colleague@example.test' );

		$this->assertInstanceOf( 'WP_User', $attendee, 'The attendee gets their own account.' );
		$this->assertSame( (int) $attendee->ID, $resolved );
		$this->assertNotSame( $buyer_id, $resolved );
		$this->assertTrue( $this->ent()->holds_role( $event_id, (int) $attendee->ID ) );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $buyer_id ), 'The buyer is not the attendee.' );
	}

	/** ...and an existing account with the attendee's email wins over the customer id. */
	public function test_ensure_user_prefers_the_attendee_email_match_over_the_customer_id() {
		$event_id    = $this->enabled_event();
		$buyer_id    = self::factory()->user->create( [ 'user_email' => 'payer2@example.test' ] );
		$attendee_id = self::factory()->user->create( [ 'user_email' => 'known-attendee@example.test' ] );
		$seat_id     = $this->make_seat( $event_id, [ 'email' => 'known-attendee@example.test', 'customer_id' => $buyer_id ] );

		$this->assertSame( $attendee_id, $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) ) );
	}

	/** Branch 3: an existing account matched by email. */
	public function test_ensure_user_matches_by_email() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'known@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'known@example.test' ] );

		$this->assertSame( $user_id, $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) ) );
	}

	/**
	 * Branch 4: a new account, with the seat's name, and NO WordPress new-user
	 * mail. The spy goes up BEFORE make_seat(): a confirmed seat on an enabled
	 * event already triggers account creation via the seat-lifecycle hook
	 * (on_seat_created -> grant_for_seat() -> ensure_user()), so the explicit
	 * ensure_user() call below is only confirming idempotency (branch 1). The
	 * mail assertion has to watch the seat's birth, or it is not testing the
	 * creation path at all.
	 */
	public function test_ensure_user_creates_account_without_emailing() {
		$event_id = $this->enabled_event();

		$mails = [];
		$spy   = function ( $args ) use ( &$mails ) { $mails[] = $args; return $args; };
		add_filter( 'wp_mail', $spy );
		$seat_id = $this->make_seat( $event_id, [ 'name' => 'Grace Hopper', 'email' => 'grace@example.test' ] );
		$user_id = $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) );
		remove_filter( 'wp_mail', $spy );

		$this->assertGreaterThan( 0, $user_id );
		$this->assertSame( 'grace@example.test', ( new WP_User( $user_id ) )->user_email );
		$this->assertSame( 'Grace Hopper', ( new WP_User( $user_id ) )->display_name );
		$this->assertSame( [], $mails, 'Account creation must not send a WordPress new-user email.' );
	}

	/**
	 * A site may opt out of account creation entirely. The filter goes up
	 * BEFORE make_seat(): a confirmed seat on an enabled event resolves an
	 * account immediately via the seat-lifecycle hook, so the opt-out has to
	 * be in effect for that automatic call too, not just the explicit one
	 * below, or this test would pass by accident (asserting 0 against an
	 * account that was already created).
	 */
	public function test_create_account_filter_opts_out() {
		$event_id = $this->enabled_event();

		add_filter( 'anchor_events_create_account', '__return_false' );
		$seat_id = $this->make_seat( $event_id, [ 'email' => 'nope@example.test' ] );
		$user_id = $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) );
		remove_filter( 'anchor_events_create_account', '__return_false' );

		$this->assertSame( 0, $user_id );
		$this->assertNull( get_user_by( 'email', 'nope@example.test' ) ?: null );
	}

	/** user_has_active_seat() matches on the resolved user id first. */
	public function test_user_has_active_seat_matches_user_id() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create( [ 'user_email' => 'renamed@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'old-address@example.test' ] );
		update_post_meta( $seat_id, '_anchor_event_user_id', $user_id );

		$this->assertTrue( $this->registrations()->user_has_active_seat( $event_id, $user_id, 'renamed@example.test' ) );
	}

	/* ---------------------------------------------------------------------
	 * can_access_stream() — spec §4.5, the one question.
	 * ------------------------------------------------------------------- */

	/** Build a streamable event with one virtual tier and one in-person tier. */
	private function stream_event( array $meta = [] ) {
		$start    = time() + HOUR_IN_SECONDS;
		$event_id = $this->event( array_merge( [
			'access_role_enabled' => true,
			'timezone'   => 'UTC',
			'start_date' => gmdate( 'Y-m-d', $start ),
			'start_time' => gmdate( 'H:i', $start ),
			'start_ts'   => $start,
			'end_ts'     => $start + 3600,
			'stream_default_modality' => 'hybrid',
			'stream_embed' => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/1', 'raw' => '' ],
		], $meta ) );
		$tiers = $this->ticket_types()->save( $event_id, [
			[ 'label' => 'In person', 'price' => '0', 'active' => 1, 'modality' => 'in_person' ],
			[ 'label' => 'Livestream', 'price' => '0', 'active' => 1, 'modality' => 'virtual' ],
		] );
		return [ $event_id, $tiers[0]['id'], $tiers[1]['id'] ];
	}

	public function test_logged_out_is_denied() {
		[ $event_id ] = $this->stream_event();
		wp_set_current_user( 0 );
		$this->assertFalse( $this->ent()->can_access_stream( $event_id ) );
	}

	public function test_staff_always_allowed() {
		[ $event_id ] = $this->stream_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( $this->ent()->can_access_stream( $event_id ) );
	}

	public function test_external_event_denied_even_for_a_holder() {
		[ $event_id, , $virtual ] = $this->stream_event( [ 'registration_mode' => 'external' ] );
		$user_id = self::factory()->user->create( [ 'user_email' => 'x@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'x@example.test', 'ticket_type_id' => $virtual ] );
		$this->assertFalse( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );
	}

	/** Step 3 also refuses an event whose master switch is off (spec §4.5). */
	public function test_switch_off_denies_even_a_role_holder() {
		[ $event_id, , $virtual ] = $this->stream_event();
		$user_id = self::factory()->user->create( [ 'user_email' => 'off@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'off@example.test', 'ticket_type_id' => $virtual ] );
		$this->assertTrue( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );

		// Turn the switch off behind the event's back (only an operator can do
		// this; a save cannot). The stale role is no longer a key to anything.
		update_post_meta( $event_id, '_anchor_event_access_role_enabled', false );

		$this->assertFalse( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );
	}

	/** A manual grant does not outrank the master switch either. */
	public function test_switch_off_denies_a_manual_grant() {
		[ $event_id ] = $this->stream_event();
		$user_id = self::factory()->user->create();
		$this->ent()->grant( $event_id, $user_id, 'manual' );
		update_post_meta( $event_id, '_anchor_event_access_role_enabled', false );

		$this->assertFalse( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );
	}

	public function test_in_person_session_denies_everyone() {
		[ $event_id, , $virtual ] = $this->stream_event( [ 'stream_default_modality' => 'in_person' ] );
		$user_id = self::factory()->user->create( [ 'user_email' => 'y@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'y@example.test', 'ticket_type_id' => $virtual ] );
		$this->assertFalse( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );
	}

	public function test_virtual_tier_allowed() {
		[ $event_id, , $virtual ] = $this->stream_event();
		$user_id = self::factory()->user->create( [ 'user_email' => 'v@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'v@example.test', 'ticket_type_id' => $virtual ] );
		$this->assertTrue( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );
	}

	/** A retired virtual tier's seat still resolves virtual: seat_tier_modality()
	 * falls back to the frozen variation modality when ticket_types->find()
	 * returns null (CodeRabbit PR #27, class-entitlements.php:1006). */
	public function test_seat_tier_modality_falls_back_to_frozen_variation_meta_for_a_retired_tier() {
		[ $event_id, $in_person_id, $virtual_id ] = $this->stream_event();
		$user_id = self::factory()->user->create( [ 'user_email' => 'retired@example.test' ] );
		$seat_id = $this->make_seat( $event_id, [ 'email' => 'retired@example.test', 'ticket_type_id' => $virtual_id ] );

		// Simulate Product_Sync freezing modality on the variation before the
		// tier disappears (class-product-sync.php:692-698).
		$variation_id = self::factory()->post->create();
		update_post_meta( $variation_id, '_anchor_evt_modality', 'virtual' );
		update_post_meta( $seat_id, '_anchor_event_variation_id', $variation_id );

		// Remove the virtual tier — ticket_types()->find() now returns null for it.
		$this->ticket_types()->save( $event_id, [
			[ 'id' => $in_person_id, 'label' => 'In person', 'price' => '0', 'active' => 1, 'modality' => 'in_person' ],
		] );

		$this->assertNull( $this->ticket_types()->find( $event_id, $virtual_id ), 'Sanity: the tier is really gone.' );
		$this->assertSame( 'virtual', $this->ent()->seat_tier_modality( $event_id, $user_id ) );
	}

	/** seat_tier_modality() must match the seat OWNER, not the order's buyer:
	 * a buyer with their own in-person seat plus a colleague's virtual seat
	 * (both stamped with the buyer's customer id) resolves to in_person
	 * (CodeRabbit PR #27, class-registrations.php:1307). */
	public function test_seat_tier_modality_uses_owner_identity_not_the_order_customer_id() {
		[ $event_id, $in_person_id, $virtual_id ] = $this->stream_event();
		$buyer_id = self::factory()->user->create( [ 'user_email' => 'buyer@example.test' ] );

		// The buyer's own in-person seat.
		$this->make_seat( $event_id, [
			'email'           => 'buyer@example.test',
			'user_id'         => $buyer_id,
			'customer_id'     => $buyer_id,
			'ticket_type_id'  => $in_person_id,
		] );
		// A colleague's virtual seat, same order (customer_id = the buyer).
		$this->make_seat( $event_id, [
			'email'          => 'colleague@example.test',
			'customer_id'    => $buyer_id,
			'ticket_type_id' => $virtual_id,
			'seat_index'     => 2,
		] );

		$this->assertSame( 'in_person', $this->ent()->seat_tier_modality( $event_id, $buyer_id ) );
	}

	/**
	 * CodeRabbit PR #32 (audit F01 re-review): the same owner-only identity
	 * match must not fall back to email when a DIFFERENT account is bound to
	 * the seat. A virtual seat bound to account A, whose stored email later
	 * equals account B's, entitles A to the stream and gives B nothing.
	 */
	public function test_seat_tier_modality_ignores_an_email_match_against_a_seat_bound_to_someone_else() {
		[ $event_id, , $virtual_id ] = $this->stream_event();
		$owner = self::factory()->user->create( [ 'user_email' => 'f01-modality-owner@example.test' ] );
		$other = self::factory()->user->create( [ 'user_email' => 'f01-modality-other@example.test' ] );

		$this->make_seat( $event_id, [
			'email'          => 'f01-modality-other@example.test',
			'user_id'        => $owner,
			'ticket_type_id' => $virtual_id,
		] );

		$this->assertSame( 'virtual', $this->ent()->seat_tier_modality( $event_id, $owner ) );
		$this->assertSame( '', $this->ent()->seat_tier_modality( $event_id, $other ), 'A matching email must not resolve to a seat bound to someone else.' );
	}

	public function test_in_person_tier_follows_the_toggle() {
		[ $on_id, $in_person_on ] = $this->stream_event( [ 'in_person_includes_stream' => true ] );
		$a = self::factory()->user->create( [ 'user_email' => 'a2@example.test' ] );
		$this->make_seat( $on_id, [ 'email' => 'a2@example.test', 'ticket_type_id' => $in_person_on ] );
		$this->assertTrue( $this->ent()->can_access_stream( $on_id, 0, $a ) );

		[ $off_id, $in_person_off ] = $this->stream_event( [ 'in_person_includes_stream' => false ] );
		$b = self::factory()->user->create( [ 'user_email' => 'b2@example.test' ] );
		$this->make_seat( $off_id, [ 'email' => 'b2@example.test', 'ticket_type_id' => $in_person_off ] );
		$this->assertFalse( $this->ent()->can_access_stream( $off_id, 0, $b ) );
	}

	public function test_manual_grant_allowed_with_no_seat() {
		[ $event_id ] = $this->stream_event();
		$user_id = self::factory()->user->create();
		$this->ent()->grant( $event_id, $user_id, 'manual' );
		$this->assertTrue( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );
	}

	public function test_filter_can_veto() {
		[ $event_id, , $virtual ] = $this->stream_event();
		$user_id = self::factory()->user->create( [ 'user_email' => 'veto@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'veto@example.test', 'ticket_type_id' => $virtual ] );

		add_filter( 'anchor_events_can_access_stream', '__return_false' );
		$allowed = $this->ent()->can_access_stream( $event_id, 0, $user_id );
		remove_filter( 'anchor_events_can_access_stream', '__return_false' );

		$this->assertFalse( $allowed );
	}

	/** The filter is the final say in both directions: it can force true too. */
	public function test_filter_can_force_true() {
		// Otherwise-false: a plain event, no seat, no grant, not staff.
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create();
		$this->assertFalse( $this->ent()->can_access_stream( $event_id, 0, $user_id ), 'Sanity: this case is false unfiltered.' );

		add_filter( 'anchor_events_can_access_stream', '__return_true' );
		$allowed = $this->ent()->can_access_stream( $event_id, 0, $user_id );
		remove_filter( 'anchor_events_can_access_stream', '__return_true' );

		$this->assertTrue( $allowed );
	}

	/**
	 * Spec §3.1 bridge (amended 2026-09-24): a legacy virtual event with no
	 * stored `stream_default_modality` still grants the stream to a confirmed
	 * in-person-tier seat holder when the toggle is on — the session-level
	 * modality check (branch 3) must not block it just because this event
	 * predates the new modality field.
	 */
	public function test_legacy_virtual_event_grants_stream_via_the_modality_bridge() {
		$start    = time() + HOUR_IN_SECONDS;
		$event_id = $this->event( [
			'access_role_enabled'       => true,
			'in_person_includes_stream' => true,
			'timezone'                  => 'UTC',
			'start_date'                => gmdate( 'Y-m-d', $start ),
			'start_time'                => gmdate( 'H:i', $start ),
			'start_ts'                  => $start,
			'end_ts'                    => $start + 3600,
			'virtual'                   => true,
			'virtual_url'               => 'https://us02web.zoom.us/j/123456789',
			// stream_default_modality deliberately NOT set — the legacy case.
		] );
		$tiers = $this->ticket_types()->save( $event_id, [
			[ 'label' => 'In person', 'price' => '0', 'active' => 1, 'modality' => 'in_person' ],
		] );
		$user_id = self::factory()->user->create( [ 'user_email' => 'legacy@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'legacy@example.test', 'ticket_type_id' => $tiers[0]['id'] ] );

		$this->assertTrue( $this->ent()->can_access_stream( $event_id, 0, $user_id ) );
	}

	/* -----------------------------------------------------------------
	 * Console Basics: the "Event role" panel (Task 19)
	 * --------------------------------------------------------------- */

	/** The Basics panel shows the role, its members, the backfill and Delete. */
	public function test_event_role_panel() {
		$event_id = $this->enabled_event( [ 'title' => 'Panel Event' ] );
		$this->ent()->grant( $event_id, self::factory()->user->create(), 'seat' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->module()->render_event_role_panel( $event_id );
		$this->assertStringContainsString( 'anchor_event_' . $event_id, $html );
		$this->assertStringContainsString( 'Event: Panel Event', $html );
		$this->assertStringContainsString( '1', $html );
		$this->assertStringContainsString( 'anchor_events_delete_role', $html );
		$this->assertStringContainsString( 'anchor_events_backfill_role', $html );
		$this->assertStringContainsString( 'Grant role to current attendees', $html );
	}

	/**
	 * An event with no role yet still offers the backfill — that is precisely
	 * the event that needs it (one that was selling before this shipped).
	 */
	public function test_event_role_panel_before_any_grant() {
		$event_id = $this->enabled_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$html = $this->module()->render_event_role_panel( $event_id );
		$this->assertStringContainsString( 'No role yet', $html );
		$this->assertStringNotContainsString( 'anchor_events_delete_role', $html );
		$this->assertStringContainsString( 'anchor_events_backfill_role', $html );
	}

	/** A switched-off event gets one line pointing at the switch, and nothing else. */
	public function test_event_role_panel_when_the_switch_is_off() {
		$event_id = $this->disabled_event(); // The default is TRUE, so say so.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->module()->render_event_role_panel( $event_id );

		$this->assertStringContainsString( 'Attendee access is off for this event', $html );
		$this->assertStringNotContainsString( 'anchor_events_delete_role', $html );
		$this->assertStringNotContainsString( 'anchor_events_backfill_role', $html );
		$this->assertStringNotContainsString( 'anchor_event_' . $event_id, $html, 'No slug is advertised for a role that does not exist.' );
	}

	/* -----------------------------------------------------------------
	 * Backfill (spec §4.4, §8 (f))
	 * --------------------------------------------------------------- */

	/** Every confirmed seat is granted, once, and accounts are created as needed. */
	public function test_backfill_grants_every_confirmed_seat_once() {
		// Built switched-OFF so the seats exist without having been granted —
		// the exact shape of an event that was selling before this shipped.
		$event_id = $this->disabled_event();
		$known    = self::factory()->user->create( [ 'user_email' => 'known-bf@example.test' ] );
		$this->make_seat( $event_id, [ 'name' => 'Known', 'email' => 'known-bf@example.test' ] );
		$this->make_seat( $event_id, [ 'name' => 'Guest', 'email' => 'guest-bf@example.test', 'seat_index' => 2 ] );

		$this->assertFalse( $this->ent()->holds_role( $event_id, $known ) );
		$this->assertNull( get_user_by( 'email', 'guest-bf@example.test' ) ?: null );

		// The operator ticks Access back on, then runs the backfill.
		update_post_meta( $event_id, '_anchor_event_access_role_enabled', true );

		$this->assertSame( 2, $this->ent()->backfill( $event_id ) );

		$guest = get_user_by( 'email', 'guest-bf@example.test' );
		$this->assertInstanceOf( 'WP_User', $guest, 'The backfill creates the accounts it needs.' );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $known ) );
		$this->assertTrue( $this->ent()->holds_role( $event_id, (int) $guest->ID ) );
		$this->assertSame( 'seat', $this->ent()->grant_record( $event_id, $known )['source'] );
	}

	/** Idempotent: a second run grants nobody. */
	public function test_backfill_is_idempotent() {
		$event_id = $this->enabled_event();
		$this->make_seat( $event_id, [ 'name' => 'Already', 'email' => 'already-bf@example.test' ] );

		// The seat was born confirmed, so the hook already granted it.
		$this->assertSame( 0, $this->ent()->backfill( $event_id ) );
		$this->assertSame( 0, $this->ent()->backfill( $event_id ) );
	}

	/** Pending, waitlisted and cancelled seats are skipped. */
	public function test_backfill_skips_seats_that_are_not_confirmed() {
		$event_id = $this->disabled_event();
		$this->make_seat( $event_id, [
			'name'   => 'Pending',
			'email'  => 'pending-bf@example.test',
			'status' => \Anchor\Events\Registrations::STATUS_PENDING,
		] );
		$cancelled = $this->make_seat( $event_id, [ 'name' => 'Gone', 'email' => 'gone-bf@example.test', 'seat_index' => 2 ] );
		$this->registrations()->update_status( $cancelled, \Anchor\Events\Registrations::STATUS_CANCELLED );

		update_post_meta( $event_id, '_anchor_event_access_role_enabled', true );

		$this->assertSame( 0, $this->ent()->backfill( $event_id ) );
		$this->assertNull( get_user_by( 'email', 'pending-bf@example.test' ) ?: null );
		$this->assertNull( get_user_by( 'email', 'gone-bf@example.test' ) ?: null );
	}

	/** It refuses outright when the switch is off, and says so. */
	public function test_backfill_refuses_when_the_switch_is_off() {
		$event_id = $this->disabled_event();
		$this->make_seat( $event_id, [ 'name' => 'Nope', 'email' => 'nope-bf@example.test' ] );
		$before = count_users()['total_users'];

		$this->assertSame( 0, $this->ent()->backfill( $event_id ) );
		$this->assertSame( $before, count_users()['total_users'] );
		$this->assertNull( get_role( 'anchor_event_' . $event_id ) );
		$this->assertStringContainsString(
			'Attendee access is off',
			$this->module()->backfill_notice_message( 0, false ),
			'The operator is told WHY nothing happened, not just that nothing happened.'
		);
	}

	/* -----------------------------------------------------------------
	 * Handlers: admin-post entry points for Delete role / backfill
	 *
	 * wp_die() is intercepted by WP's test suite and thrown as a
	 * WPDieException instead of terminating the process (same technique as
	 * Test_Event_Manager_Save), so the nonce/capability/object guards are
	 * directly testable through the real handler entry points. The success
	 * paths end in wp_safe_redirect()+exit, so those are driven through the
	 * wp_redirect trap below (mirrors Test_Roster).
	 * --------------------------------------------------------------- */

	public function trap_redirect( $location ) {
		throw new Anchor_Entitlements_Redirect_Signal( (string) $location );
	}

	public function test_handle_delete_role_dies_on_invalid_nonce() {
		$event_id = $this->enabled_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST = [
			'event_id' => $event_id,
			'_wpnonce' => 'invalid-nonce',
		];
		$_REQUEST = $_POST;

		$this->expectException( WPDieException::class );
		$this->module()->handle_delete_role();
	}

	public function test_handle_delete_role_dies_for_a_non_manager() {
		$event_id = $this->enabled_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST = [
			'event_id' => $event_id,
			'_wpnonce' => wp_create_nonce( 'anchor_events_delete_role_' . $event_id ),
		];
		$_REQUEST = $_POST;

		$this->expectException( WPDieException::class );
		$this->module()->handle_delete_role();
	}

	public function test_handle_delete_role_dies_for_a_non_event_post() {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST = [
			'event_id' => $page_id,
			'_wpnonce' => wp_create_nonce( 'anchor_events_delete_role_' . $page_id ),
		];
		$_REQUEST = $_POST;

		$this->expectException( WPDieException::class );
		$this->module()->handle_delete_role();
	}

	/** The success path strips every holder and reports the count via redirect. */
	public function test_handle_delete_role_strips_holders_and_reports_the_count() {
		$event_id = $this->enabled_event();
		$this->ent()->grant( $event_id, self::factory()->user->create(), 'seat' );
		$this->ent()->grant( $event_id, self::factory()->user->create(), 'seat' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST = [
			'event_id'    => $event_id,
			'redirect_to' => 'https://example.org/manager/',
			'_wpnonce'    => wp_create_nonce( 'anchor_events_delete_role_' . $event_id ),
		];
		$_REQUEST = $_POST;

		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		try {
			$this->module()->handle_delete_role();
			$this->fail( 'handle_delete_role() did not redirect.' );
		} catch ( Anchor_Entitlements_Redirect_Signal $e ) {
			$this->assertStringContainsString( 'anchor_events_role_deleted=2', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		}

		$this->assertNull( get_role( 'anchor_event_' . $event_id ) );
	}

	public function test_handle_backfill_role_dies_on_invalid_nonce() {
		$event_id = $this->enabled_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST = [
			'event_id' => $event_id,
			'_wpnonce' => 'invalid-nonce',
		];
		$_REQUEST = $_POST;

		$this->expectException( WPDieException::class );
		$this->module()->handle_backfill_role();
	}

	public function test_handle_backfill_role_dies_for_a_non_manager() {
		$event_id = $this->enabled_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST = [
			'event_id' => $event_id,
			'_wpnonce' => wp_create_nonce( 'anchor_events_backfill_role_' . $event_id ),
		];
		$_REQUEST = $_POST;

		$this->expectException( WPDieException::class );
		$this->module()->handle_backfill_role();
	}

	public function test_handle_backfill_role_dies_for_a_non_event_post() {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST = [
			'event_id' => $page_id,
			'_wpnonce' => wp_create_nonce( 'anchor_events_backfill_role_' . $page_id ),
		];
		$_REQUEST = $_POST;

		$this->expectException( WPDieException::class );
		$this->module()->handle_backfill_role();
	}

	/** The success path reports the granted count and whether the switch was on. */
	public function test_handle_backfill_role_reports_the_granted_count() {
		$event_id = $this->disabled_event();
		$this->make_seat( $event_id, [ 'name' => 'Bf', 'email' => 'bf-handler@example.test' ] );
		update_post_meta( $event_id, '_anchor_event_access_role_enabled', true );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST = [
			'event_id'    => $event_id,
			'redirect_to' => 'https://example.org/manager/',
			'_wpnonce'    => wp_create_nonce( 'anchor_events_backfill_role_' . $event_id ),
		];
		$_REQUEST = $_POST;

		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		try {
			$this->module()->handle_backfill_role();
			$this->fail( 'handle_backfill_role() did not redirect.' );
		} catch ( Anchor_Entitlements_Redirect_Signal $e ) {
			$this->assertStringContainsString( 'anchor_events_role_backfilled=1', $e->getMessage() );
			$this->assertStringContainsString( 'anchor_events_role_enabled=1', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		}
	}

	/* -----------------------------------------------------------------
	 * Final review I5: core role changes must not silently revoke access.
	 * The grant record is the source of truth for re-application.
	 * --------------------------------------------------------------- */

	/** An admin changing a user's primary role keeps their event role. */
	public function test_set_role_keeps_a_granted_event_role() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->ent()->grant( $event_id, $user_id, 'seat' );

		( new WP_User( $user_id ) )->set_role( 'editor' );

		$roles = get_userdata( $user_id )->roles;
		$this->assertContains( 'editor', $roles );
		$this->assertNotContains( 'subscriber', $roles );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ), 'set_role() must not strip a granted event role.' );
	}

	/** Only a grant record re-applies — a stray role with no record is left gone. */
	public function test_set_role_does_not_resurrect_an_unrecorded_event_role() {
		$event_id = $this->enabled_event();
		$slug     = $this->ent()->role_for( $event_id );
		$user_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		( new WP_User( $user_id ) )->add_role( $slug );

		( new WP_User( $user_id ) )->set_role( 'editor' );

		$this->assertFalse( $this->ent()->holds_role( $event_id, $user_id ) );
	}

	/** A direct remove_role() of a granted event role is put back. */
	public function test_remove_role_of_a_granted_event_role_is_reapplied() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create();
		$this->ent()->grant( $event_id, $user_id, 'manual' );

		( new WP_User( $user_id ) )->remove_role( $this->ent()->role_slug( $event_id ) );

		$this->assertTrue( $this->ent()->holds_role( $event_id, $user_id ) );
	}

	/** An explicit revoke() still removes the role — the listener does not re-add it. */
	public function test_revoke_still_removes_the_role_despite_the_listener() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create();
		$this->ent()->grant( $event_id, $user_id, 'manual' );

		$this->assertTrue( $this->ent()->revoke( $event_id, $user_id, 'manual' ) );

		$this->assertFalse( $this->ent()->holds_role( $event_id, $user_id ) );
		$this->assertSame( [], $this->ent()->grant_record( $event_id, $user_id ) );
	}
}

/** Thrown from the wp_redirect filter so the handlers' exit never runs. */
class Anchor_Entitlements_Redirect_Signal extends \Exception {}
