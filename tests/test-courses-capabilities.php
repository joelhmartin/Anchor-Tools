<?php
/**
 * Anchor Courses - capability minting (brief section 24).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Support\Capabilities;

/** @group courses */
class Test_Courses_Capabilities extends Anchor_Courses_TestCase {

	/** Per affected role slug, which of the eight caps it already held before set_up() ran sync(). */
	private $pre_sync_caps = [];

	public function set_up() {
		parent::set_up();

		// Task 3 runs before Task 2's migration exists, so nothing else in this
		// process calls sync() yet; every test starts from the post-migration
		// state it will actually run under in production. Snapshot what each
		// affected role holds BEFORE sync() so tear_down() can undo only what
		// THIS test added. A blanket Capabilities::remove() in tear_down()
		// would be wrong once Task 2 wires sync() into the bootstrap-run
		// migration: that migration runs once per PHPUnit process, and the
		// WP_Roles singleton is never reloaded from the DB between tests (the
		// per-test transaction rollback reverts the wp_user_roles option, but
		// not the in-memory global) — tests/bootstrap.php ~78-85 works around
		// the same issue for WooCommerce's roles. Removing caps this test
		// didn't grant would strip the migration's real work and leave every
		// later test in the run looking at an administrator without them.
		$this->pre_sync_caps = [];
		foreach ( $this->affected_roles() as $role_slug => $role ) {
			foreach ( Capabilities::all() as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$this->pre_sync_caps[ $role_slug ][] = $cap;
				}
			}
		}

		Capabilities::sync();
	}

	public function tear_down() {
		foreach ( $this->affected_roles() as $role_slug => $role ) {
			$already_had = $this->pre_sync_caps[ $role_slug ] ?? [];
			foreach ( Capabilities::all() as $cap ) {
				if ( ! in_array( $cap, $already_had, true ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		// Force a fresh read of the WP_Roles singleton so later tests in this
		// process see accurate role state (same workaround tests/bootstrap.php
		// applies after WC_Install::install() adds WooCommerce's roles/caps).
		$GLOBALS['wp_roles'] = null;
		if ( function_exists( 'wp_roles' ) ) {
			wp_roles();
		}

		parent::tear_down();
	}

	/** @return array<string, WP_Role> Role slug => role object, for every role sync() would touch. */
	private function affected_roles(): array {
		$roles  = (array) apply_filters( 'anchor_courses_capability_roles', [ 'administrator' ] );
		$result = [];
		foreach ( $roles as $role_slug ) {
			$role = get_role( (string) $role_slug );
			if ( $role instanceof WP_Role ) {
				$result[ (string) $role_slug ] = $role;
			}
		}
		return $result;
	}

	public function test_the_eight_brief_capabilities_are_declared() {
		$this->assertSame(
			[ 'manage_anchor_courses', 'edit_anchor_courses', 'edit_anchor_lessons',
			  'edit_anchor_quizzes', 'view_anchor_course_reports', 'manage_anchor_enrollments',
			  'manage_anchor_credits', 'manage_anchor_certificates' ],
			Capabilities::all()
		);
	}

	public function test_administrator_holds_every_capability_after_migration() {
		$admin = get_role( 'administrator' );
		foreach ( Capabilities::all() as $cap ) {
			$this->assertTrue( $admin->has_cap( $cap ), "administrator is missing {$cap}" );
		}
	}

	public function test_subscriber_holds_none_of_them() {
		$subscriber = get_role( 'subscriber' );
		foreach ( Capabilities::all() as $cap ) {
			$this->assertFalse( $subscriber->has_cap( $cap ), "subscriber should not hold {$cap}" );
		}
	}

	public function test_cap_resolves_keys_and_refuses_unknown_ones() {
		$this->assertSame( 'manage_anchor_courses', Capabilities::cap( 'manage' ) );
		$this->assertSame( 'view_anchor_course_reports', Capabilities::cap( 'reports' ) );
		$this->assertSame( 'do_not_allow', Capabilities::cap( 'nope' ) );
	}

	public function test_current_user_can_follows_the_logged_in_user() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( Capabilities::current_user_can( 'manage' ) );
		wp_set_current_user( $this->make_learner() );
		$this->assertFalse( Capabilities::current_user_can( 'manage' ) );
	}

	public function test_sync_is_idempotent() {
		Capabilities::sync();
		Capabilities::sync();
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_anchor_courses' ) );
	}
}
