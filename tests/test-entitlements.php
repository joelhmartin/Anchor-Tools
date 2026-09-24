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

	/** Renaming the event renames the role. */
	public function test_role_renames_with_the_title() {
		$event_id = $this->event( [ 'title' => 'Old Name' ] );
		$slug     = $this->ent()->role_for( $event_id );

		wp_update_post( [ 'ID' => $event_id, 'post_title' => 'New Name' ] );

		$this->assertSame( 'Event: New Name', wp_roles()->roles[ $slug ]['name'] );
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

	/** Branch 2: an order seat with a customer id. */
	public function test_ensure_user_uses_order_customer_id() {
		$event_id = $this->enabled_event();
		$user_id  = self::factory()->user->create();
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'guest@example.test', 'customer_id' => $user_id ] );

		$this->assertSame( $user_id, $this->ent()->ensure_user( $this->registrations()->get_seat( $seat_id ) ) );
		$this->assertSame( $user_id, (int) get_post_meta( $seat_id, '_anchor_event_user_id', true ) );
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
}
