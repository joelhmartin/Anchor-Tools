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
}
