<?php
/**
 * Anchor Courses - the access and completion roles (design spec 3.1).
 *
 * Roles live in the `wp_user_roles` option AND in the $wp_roles global, and the
 * global survives the per-test transaction rollback - so anything that mints a
 * role removes it again. Anchor_Courses_TestCase::tear_down() does that for
 * every course these helpers create; tests that mint a role by hand clean up
 * after themselves.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Support\Roles;

/** Thrown from the wp_redirect filter so the delete handler's exit never runs. */
class Anchor_Courses_Role_Redirect_Signal extends \Exception {}

/** @group courses */
class Test_Courses_Roles extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [], 'Laser Safety' );
		\add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
	}

	public function tear_down() {
		\remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Courses_Role_Redirect_Signal( (string) $location );
	}

	/** Drive handle_delete_role() through the real admin_post hook. */
	private function post_delete_role( int $course_id, string $slug, bool $can_manage, bool $valid_nonce ): string {
		\wp_set_current_user(
			$can_manage
				? self::factory()->user->create( [ 'role' => 'administrator' ] )
				: $this->user
		);

		$_POST = [
			'course_id' => $course_id,
			'role'      => $slug,
			'_wpnonce'  => $valid_nonce ? \wp_create_nonce( 'anchor_courses_delete_role_' . $course_id ) : 'bad-nonce',
		];
		$_REQUEST = $_POST;

		try {
			\do_action( 'admin_post_anchor_courses_delete_role' );
		} catch ( Anchor_Courses_Role_Redirect_Signal $e ) {
			return $e->getMessage();
		}
		$this->fail( 'handle_delete_role() did not redirect.' );
	}

	public function test_the_slugs_and_names_follow_the_spec() {
		$this->assertSame( 'anchor_course_' . $this->course, Roles::access_slug( $this->course ) );
		$this->assertSame( 'anchor_course_' . $this->course . '_completed', Roles::completion_slug( $this->course ) );
		$this->assertSame( 'Course: Laser Safety', Roles::access_name( $this->course ) );
		$this->assertSame( 'Completed: Laser Safety', Roles::completion_name( $this->course ) );
	}

	/** The access role exists the moment the course is published - before anyone needs it. */
	public function test_the_access_role_is_minted_eagerly_on_publish() {
		$this->assertTrue( Roles::exists( Roles::access_slug( $this->course ) ) );
		$this->assertSame( [], get_role( Roles::access_slug( $this->course ) )->capabilities, 'A role is a tag, not a permission.' );
		$this->assertSame( 'Course: Laser Safety', wp_roles()->roles[ Roles::access_slug( $this->course ) ]['name'] );
	}

	/** A draft mints nothing; publishing it does. */
	public function test_a_draft_course_mints_no_role_until_it_is_published() {
		$draft = self::factory()->post->create( [ 'post_type' => 'anchor_course', 'post_status' => 'draft', 'post_title' => 'Later' ] );

		$this->assertFalse( Roles::exists( Roles::access_slug( $draft ) ) );

		wp_update_post( [ 'ID' => $draft, 'post_status' => 'publish' ] );

		$this->assertTrue( Roles::exists( Roles::access_slug( $draft ) ) );
		remove_role( Roles::access_slug( $draft ) );
	}

	/** The completion role waits until somebody actually completes something. */
	public function test_the_completion_role_is_minted_lazily() {
		$this->assertFalse( Roles::exists( Roles::completion_slug( $this->course ) ) );

		Roles::grant_completed( $this->user, $this->course );

		$this->assertTrue( Roles::exists( Roles::completion_slug( $this->course ) ) );
		$this->assertTrue( Roles::user_has( $this->user, Roles::completion_slug( $this->course ) ) );
		$this->assertContains( 'subscriber', get_userdata( $this->user )->roles, 'The grant is additive.' );
	}

	public function test_granting_completion_twice_is_harmless() {
		Roles::grant_completed( $this->user, $this->course );
		Roles::grant_completed( $this->user, $this->course );

		$this->assertSame( 1, Roles::holders( Roles::completion_slug( $this->course ) ) );
	}

	/**
	 * is_access_slug() is the listener's whole safety net: it must recognise an
	 * access slug and reject everything else, especially the completion
	 * variant, or completing a course would re-enrol the learner.
	 */
	public function test_is_access_slug_matches_the_access_role_and_nothing_else() {
		$this->assertSame( $this->course, Roles::is_access_slug( 'anchor_course_' . $this->course ) );

		foreach ( [
			'anchor_course_' . $this->course . '_completed',
			'anchor_event_12',
			'anchor_course_',
			'anchor_course_abc',
			'anchor_course_12x',
			'xanchor_course_12',
			'customer',
			'subscriber',
			'',
		] as $slug ) {
			$this->assertNull( Roles::is_access_slug( $slug ), "{$slug} must not read as an access role." );
		}
	}

	/** An id with no course behind it is still a well-formed slug, and still refused. */
	public function test_is_access_slug_refuses_an_id_that_is_not_a_course() {
		$lesson = $this->make_lesson();

		$this->assertNull( Roles::is_access_slug( 'anchor_course_' . $lesson ) );
		$this->assertNull( Roles::is_access_slug( 'anchor_course_999999' ) );
	}

	public function test_renaming_the_course_renames_both_roles() {
		Roles::grant_completed( $this->user, $this->course );

		wp_update_post( [ 'ID' => $this->course, 'post_title' => 'Advanced Laser Safety' ] );

		$this->assertSame( 'Course: Advanced Laser Safety', wp_roles()->roles[ Roles::access_slug( $this->course ) ]['name'] );
		$this->assertSame( 'Completed: Advanced Laser Safety', wp_roles()->roles[ Roles::completion_slug( $this->course ) ]['name'] );
	}

	public function test_trashing_the_course_leaves_both_roles_alone() {
		Roles::grant_completed( $this->user, $this->course );

		wp_trash_post( $this->course );

		$this->assertTrue( Roles::exists( Roles::access_slug( $this->course ) ), 'A role must survive its course (events spec 4.1).' );
		$this->assertTrue( Roles::exists( Roles::completion_slug( $this->course ) ) );
		$this->assertTrue( Roles::user_has( $this->user, Roles::completion_slug( $this->course ) ) );
	}

	public function test_deleting_a_role_strips_it_from_every_holder() {
		$second = $this->make_learner();
		Roles::grant_completed( $this->user, $this->course );
		Roles::grant_completed( $second, $this->course );

		$this->assertSame( 2, Roles::delete_role( Roles::completion_slug( $this->course ) ) );

		$this->assertFalse( Roles::exists( Roles::completion_slug( $this->course ) ) );
		$this->assertFalse( Roles::user_has( $this->user, Roles::completion_slug( $this->course ) ) );
		$this->assertContains( 'subscriber', get_userdata( $this->user )->roles, 'Only the one role goes.' );
	}

	public function test_missing_reports_unheld_roles_by_display_name() {
		add_role( 'anchor_event_42', 'Event: Summit', [] );
		Roles::ensure_completion_role( $this->course );

		$required = [ 'anchor_event_42', Roles::completion_slug( $this->course ) ];

		$this->assertSame( [ 'Event: Summit', 'Completed: Laser Safety' ], Roles::missing( $this->user, $required ) );

		get_user_by( 'id', $this->user )->add_role( 'anchor_event_42' );
		$this->assertSame( [ 'Completed: Laser Safety' ], Roles::missing( $this->user, $required ) );

		remove_role( 'anchor_event_42' );
	}

	/** A completion role is exactly what another course names as a prerequisite. */
	public function test_a_completion_role_works_as_another_courses_prerequisite() {
		$advanced = $this->make_course( [], 'Advanced' );
		update_post_meta( $advanced, '_anchor_course_prerequisites', [ Roles::completion_slug( $this->course ) ] );

		$service = new \Anchor\Courses\Services\EnrollmentService();
		$this->assertSame( 'missing_prerequisite', $service->can_enroll( $this->user, $advanced )->get_error_code() );

		Roles::grant_completed( $this->user, $this->course );

		$this->assertTrue( $service->can_enroll( $this->user, $advanced ) );
	}

	public function test_the_role_panel_shows_both_roles_and_two_delete_actions() {
		Roles::grant_completed( $this->user, $this->course );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		ob_start();
		( new CourseEditor() )->render_role_panel( get_post( $this->course ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( Roles::access_slug( $this->course ), $html );
		$this->assertStringContainsString( Roles::completion_slug( $this->course ), $html );
		// The metabox body itself carries no <form> (CodeRabbit PR #29 - a
		// nested <form> is dropped by the browser); it prints one delete
		// button per role, each bound by form="…" to the real admin-post
		// form MetaboxForms queues for admin_footer.
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertSame( 2, substr_count( $html, 'anchor-courses-delete-role-' . $this->course . '-' ) );
	}

	public function test_the_role_panel_says_when_nobody_has_completed_yet() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		ob_start();
		( new CourseEditor() )->render_role_panel( get_post( $this->course ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Not created yet', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertSame(
			1,
			substr_count( $html, 'anchor-courses-delete-role-' . $this->course . '-' ),
			'Only the access role is deletable so far.'
		);
	}

	/* ---------------------------------------------------------------------
	 * The delete handler, through the real admin_post hook.
	 * ------------------------------------------------------------------- */

	public function test_the_delete_action_is_wired_to_admin_post() {
		$editor = new CourseEditor();

		$this->assertNotFalse(
			has_action( 'admin_post_anchor_courses_delete_role', [ $editor, 'handle_delete_role' ] ),
			'CourseEditor must wire handle_delete_role() to admin_post_anchor_courses_delete_role.'
		);
	}

	public function test_the_delete_handler_deletes_exactly_the_named_role_through_the_real_hook() {
		new CourseEditor();
		Roles::grant_completed( $this->user, $this->course );
		$slug = Roles::completion_slug( $this->course );

		$location = $this->post_delete_role( $this->course, $slug, true, true );

		$this->assertFalse( Roles::exists( $slug ), 'The named role must be gone.' );
		$this->assertTrue( Roles::exists( Roles::access_slug( $this->course ) ), 'The access role must be untouched.' );
		$this->assertStringContainsString( 'anchor_courses_admin_notice=role_deleted', $location );
	}

	public function test_the_delete_handler_refuses_a_bad_nonce_and_deletes_nothing() {
		new CourseEditor();
		$slug = Roles::access_slug( $this->course );

		$location = $this->post_delete_role( $this->course, $slug, true, false );

		$this->assertTrue( Roles::exists( $slug ) );
		$this->assertStringContainsString( 'anchor_courses_admin_notice=forbidden', $location );
	}

	public function test_the_delete_handler_refuses_a_user_without_the_manage_capability() {
		new CourseEditor();
		$slug = Roles::access_slug( $this->course );

		$location = $this->post_delete_role( $this->course, $slug, false, true );

		$this->assertTrue( Roles::exists( $slug ) );
		$this->assertStringContainsString( 'anchor_courses_admin_notice=forbidden', $location );
	}

	/** A crafted POST cannot delete a role belonging to a different course (or anything else). */
	public function test_the_delete_handler_refuses_a_role_that_does_not_belong_to_the_course() {
		new CourseEditor();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$other = $this->make_course( [], 'Other Course' );

		$_POST = [
			'course_id' => $this->course,
			'role'      => Roles::access_slug( $other ),
			'_wpnonce'  => wp_create_nonce( 'anchor_courses_delete_role_' . $this->course ),
		];
		$_REQUEST = $_POST;

		try {
			\do_action( 'admin_post_anchor_courses_delete_role' );
		} catch ( Anchor_Courses_Role_Redirect_Signal $e ) {
			$this->assertStringContainsString( 'anchor_courses_admin_notice=error', $e->getMessage() );
			$this->assertTrue( Roles::exists( Roles::access_slug( $other ) ), 'The other course\'s role must survive.' );
			return;
		}
		$this->fail( 'handle_delete_role() did not redirect.' );
	}

	/* ---------------------------------------------------------------------
	 * set_user_role: WP_User::set_role() replaces the whole roles array, so
	 * an admin changing a learner's PRIMARY role (e.g. wp-admin's "Change
	 * role to..." bulk action) must not silently un-enrol them.
	 * ------------------------------------------------------------------- */

	public function test_changing_a_users_primary_role_does_not_strip_a_held_course_role() {
		// Each mutation re-fetches: WP_User caches its roles at construction
		// time, so reusing one stale handle across grant_completed()'s own
		// internal fetch would only prove half of this (a real risk this test
		// tripped over while being written).
		get_userdata( $this->user )->add_role( Roles::access_slug( $this->course ) );
		Roles::grant_completed( $this->user, $this->course );

		get_userdata( $this->user )->set_role( 'editor' );

		$refreshed = get_userdata( $this->user );
		$this->assertContains( 'editor', $refreshed->roles );
		$this->assertContains( Roles::access_slug( $this->course ), $refreshed->roles, 'The access role must be re-applied.' );
		$this->assertContains( Roles::completion_slug( $this->course ), $refreshed->roles, 'The completion role must be re-applied.' );
	}

	public function test_changing_a_role_with_no_course_roles_held_is_a_harmless_no_op() {
		$user = get_userdata( $this->user );

		$user->set_role( 'editor' );

		$refreshed = get_userdata( $this->user );
		$this->assertSame( [ 'editor' ], $refreshed->roles );
	}
}
