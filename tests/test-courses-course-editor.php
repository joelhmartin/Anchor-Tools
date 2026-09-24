<?php
/**
 * Anchor Courses - course settings persistence (brief section 6.3, design spec 4).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;

/** @group courses */
class Test_Courses_Course_Editor extends Anchor_Courses_TestCase {

	private function post_course_settings( int $course_id, array $fields ): void {
		$_POST = [
			CourseEditor::NONCE => wp_create_nonce( CourseEditor::NONCE ),
			'anchor_course'     => $fields,
		];
		( new CourseEditor() )->save( $course_id );
		$_POST = [];
	}

	public function test_defaults_match_the_design_spec() {
		$d = CourseEditor::defaults();
		$this->assertSame( 'sequential', $d['progression_mode'] );
		$this->assertSame( 'all_required_items', $d['completion_mode'] );
		$this->assertSame( 100, $d['completion_percentage'] );
		$this->assertArrayNotHasKey( 'access_type', $d, 'There is no access type: holding anchor_course_{id} IS enrolment (design spec 3.1).' );
		$this->assertArrayNotHasKey( 'auto_enroll_roles', $d, 'There is no auto-enrol picker.' );
	}

	public function test_save_persists_every_brief_meta_key() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		add_role( 'anchor_course_9_completed', 'Completed: Prereq', [] );
		$course = $this->make_course();

		$this->post_course_settings(
			$course,
			[
				'duration' => '3 hours', 'difficulty' => 'intermediate', 'instructor' => 'Dr Vega',
				'ce_credits' => '2.5', 'ce_type' => 'Dental CE', 'ce_provider_name' => 'DEKA Academy',
				'ce_provider_number' => 'AGD-1234', 'ce_expires_days' => '730',
				'prerequisites' => [ 'anchor_course_9_completed' ], 'completion_mode' => 'minimum_percentage',
				'completion_percentage' => '80', 'progression_mode' => 'free',
				'certificate_enabled' => '1', 'certificate_template' => 'default',
				'expiration_days' => '365', 'available_from' => '2026-01-01', 'available_until' => '2026-12-31',
			]
		);

		$this->assertSame( '3 hours', get_post_meta( $course, '_anchor_course_duration', true ) );
		$this->assertSame( 2.5, (float) get_post_meta( $course, '_anchor_course_ce_credits', true ) );
		$this->assertSame( [ 'anchor_course_9_completed' ], get_post_meta( $course, '_anchor_course_prerequisites', true ) );
		$this->assertSame( 80, (int) get_post_meta( $course, '_anchor_course_completion_percentage', true ) );
		$this->assertSame( '2026-12-31', get_post_meta( $course, '_anchor_course_available_until', true ) );

		remove_role( 'anchor_course_9_completed' );
	}

	/**
	 * Prerequisites accept completion roles and event roles, and nothing else.
	 *
	 * Not an exclusion list - a shape test. `customer`, `subscriber` and a
	 * course's own ACCESS role (`anchor_course_{id}`, no `_completed`) all fail
	 * it, the first two because set_user_role fires on every new account, the
	 * third because "you must already be enrolled" is not a prerequisite.
	 */
	public function test_prerequisites_accept_only_completion_and_event_roles() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		add_role( 'anchor_event_12', 'Event: Test', [] );
		add_role( 'anchor_course_77_completed', 'Completed: Other', [] );
		add_role( 'anchor_course_77', 'Course: Other', [] );
		$course = $this->make_course();

		$this->post_course_settings( $course, [
			'prerequisites' => [
				'customer', 'subscriber', 'administrator', 'shop_manager',
				'anchor_course_77',            // an ACCESS role: not a prerequisite.
				'anchor_event_12',
				'anchor_course_77_completed',
			],
		] );

		$this->assertSame(
			[ 'anchor_event_12', 'anchor_course_77_completed' ],
			get_post_meta( $course, '_anchor_course_prerequisites', true ),
			'A crafted POST must be dropped at the sanitiser, not merely hidden in the UI.'
		);

		$choices = CourseEditor::prerequisite_role_choices();
		$this->assertArrayHasKey( 'anchor_event_12', $choices );
		$this->assertArrayHasKey( 'anchor_course_77_completed', $choices );
		$this->assertArrayNotHasKey( 'customer', $choices );
		$this->assertArrayNotHasKey( 'subscriber', $choices );
		$this->assertArrayNotHasKey( 'anchor_course_77', $choices );

		remove_role( 'anchor_event_12' );
		remove_role( 'anchor_course_77_completed' );
		remove_role( 'anchor_course_77' );
	}

	public function test_invalid_enum_values_fall_back_to_the_default() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course();

		$this->post_course_settings( $course, [ 'progression_mode' => 'nope', 'completion_mode' => 'x' ] );

		$this->assertSame( 'sequential', get_post_meta( $course, '_anchor_course_progression_mode', true ) );
		$this->assertSame( 'all_required_items', get_post_meta( $course, '_anchor_course_completion_mode', true ) );
	}

	public function test_save_bails_without_a_valid_nonce() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course( [ 'instructor' => 'Original' ] );

		$_POST = [ 'anchor_course' => [ 'instructor' => 'Injected' ] ];
		( new CourseEditor() )->save( $course );
		$_POST = [];

		$this->assertSame( 'Original', get_post_meta( $course, '_anchor_course_instructor', true ) );
	}

	public function test_save_bails_without_the_edit_capability() {
		$course = $this->make_course( [ 'instructor' => 'Original' ] );
		wp_set_current_user( $this->make_learner() );

		$this->post_course_settings( $course, [ 'instructor' => 'Injected' ] );

		$this->assertSame( 'Original', get_post_meta( $course, '_anchor_course_instructor', true ) );
	}

	public function test_dates_are_stored_as_iso_or_discarded() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course();
		$this->post_course_settings( $course, [ 'available_from' => 'tomorrow-ish' ] );
		$this->assertSame( '', get_post_meta( $course, '_anchor_course_available_from', true ) );
	}

	public function test_the_metabox_is_registered_on_the_course_screen() {
		global $wp_meta_boxes;
		set_current_screen( 'anchor_course' );
		( new CourseEditor() )->add_metaboxes();
		$this->assertArrayHasKey( 'anchor_courses_settings', $wp_meta_boxes[ CoursePostType::CPT ]['normal']['high'] );
	}
}
