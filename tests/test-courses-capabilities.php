<?php
/**
 * Anchor Courses - capability minting (brief section 24).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Support\Capabilities;

/** @group courses */
class Test_Courses_Capabilities extends Anchor_Courses_TestCase {

	public function set_up() {
		parent::set_up();
		// Task 3 runs before Task 2's migration exists, so nothing else in this
		// process calls sync() yet. Every test starts from the post-migration
		// state it will actually run under in production.
		Capabilities::sync();
	}

	public function tear_down() {
		// add_cap() mutates the in-memory WP_Roles singleton, not just the DB
		// option; the per-test transaction rollback does not touch that global.
		// Without this, a capability minted here would leak into every test
		// file that runs later in the same PHPUnit process.
		Capabilities::remove();
		parent::tear_down();
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
