<?php
/**
 * Anchor Courses - no metabox may nest an admin-post <form> inside the post
 * edit form (CodeRabbit PR #29, Task 32 fix wave).
 *
 * Every metabox this module registers on the course edit screen is rendered
 * inside WordPress's own post-edit <form>. A metabox that prints its own
 * <form> for an admin-post handler (add/revoke a learner, manage an
 * enrolment, delete a course role) gets that <form> start tag silently
 * dropped by the browser, so its controls submit to post.php instead and its
 * extra _wpnonce field can shadow the post form's own nonce. Admin\MetaboxForms
 * is the fix: the real <form> prints from admin_footer, outside every post
 * form, and the metabox itself only prints a control bound to it via
 * `form="<id>"`.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Admin\EnrollmentManager;
use Anchor\Courses\Admin\LearnerReports;
use Anchor\Courses\Admin\MetaboxForms;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Metabox_Forms extends Anchor_Courses_TestCase {

	private int $admin;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->course = $this->make_course( [], 'Metabox Forms Course' );
		wp_set_current_user( $this->admin );
		MetaboxForms::reset();
	}

	public function tear_down() {
		MetaboxForms::reset();
		parent::tear_down();
	}

	/** @return array<int,array{0:string}> */
	private function render_every_course_metabox(): array {
		$post = get_post( $this->course );

		$editor = new CourseEditor();

		return [
			'settings'    => $this->capture( fn() => $editor->render_settings( $post ) ),
			'curriculum'  => $this->capture( fn() => $editor->render_curriculum( $post ) ),
			'role_panel'  => $this->capture( fn() => $editor->render_role_panel( $post ) ),
			'learners'    => $this->capture( fn() => ( new LearnerReports() )->render_learners( $post ) ),
		];
	}

	private function capture( callable $fn ): string {
		\ob_start();
		$fn();
		return (string) \ob_get_clean();
	}

	public function test_no_course_metabox_renders_a_nested_form_tag() {
		// A learner enrolled with a real progress row exercises the Learners
		// table's per-row revoke control too, not just the empty-state markup.
		$learner = $this->make_learner();
		Roles::grant_access( $learner, $this->course, 'manual' );

		foreach ( $this->render_every_course_metabox() as $name => $html ) {
			$this->assertStringNotContainsStringIgnoringCase(
				'<form',
				$html,
				"The {$name} metabox must not print a <form> - it renders inside the post-edit form."
			);
		}
	}

	public function test_the_add_learner_control_is_bound_to_its_footer_form_by_id() {
		$html = $this->capture( fn() => ( new LearnerReports() )->render_add_learner_form( $this->course ) );

		$this->assertStringNotContainsStringIgnoringCase( '<form', $html );
		\preg_match( '/form="([^"]+)"/', $html, $m );
		$this->assertNotEmpty( $m, 'The add-learner control must carry a form="…" attribute.' );

		$footer = $this->render_footer();
		$this->assertStringContainsString( '<form id="' . $m[1] . '"', $footer );
		$this->assertStringContainsString( 'anchor_courses_add_learner', $footer );
		$this->assertStringContainsString( '_wpnonce', $footer );
	}

	public function test_the_revoke_control_is_bound_to_its_own_per_row_footer_form() {
		$learner = $this->make_learner();
		Roles::grant_access( $learner, $this->course, 'manual' );

		$html = $this->capture( fn() => ( new LearnerReports() )->revoke_button( $this->course, $learner ) );

		$this->assertStringNotContainsStringIgnoringCase( '<form', $html );
		\preg_match( '/form="([^"]+)"/', $html, $m );
		$this->assertNotEmpty( $m, 'The revoke control must carry a form="…" attribute.' );

		$footer = $this->render_footer();
		$this->assertStringContainsString( '<form id="' . $m[1] . '"', $footer );
		$this->assertStringContainsString( (string) $learner, $footer );
		$this->assertStringContainsString( '_wpnonce', $footer );
	}

	public function test_the_manage_enrollment_controls_are_bound_to_their_footer_form() {
		$html = $this->capture( fn() => ( new EnrollmentManager() )->render_form( $this->course ) );

		$this->assertStringNotContainsStringIgnoringCase( '<form', $html );
		\preg_match_all( '/form="([^"]+)"/', $html, $m );
		$this->assertNotEmpty( $m[1], 'The enrolment controls must carry a form="…" attribute.' );
		$id = $m[1][0];
		foreach ( $m[1] as $form_id ) {
			$this->assertSame( $id, $form_id, 'Every enrolment control must point at the SAME footer form.' );
		}

		$footer = $this->render_footer();
		$this->assertStringContainsString( '<form id="' . $id . '"', $footer );
		$this->assertStringContainsString( 'anchor_courses_manage_enrollment', $footer );
		$this->assertStringContainsString( '_wpnonce', $footer );
	}

	public function test_the_delete_role_control_is_bound_to_its_own_footer_form() {
		// Publish mints the access role; render_role_panel() only prints a
		// delete-role control for a role that already exists.
		wp_publish_post( $this->course );

		$html = $this->capture( fn() => ( new CourseEditor() )->render_role_panel( get_post( $this->course ) ) );

		$this->assertStringNotContainsStringIgnoringCase( '<form', $html );
		\preg_match_all( '/form="([^"]+)"/', $html, $m );
		$this->assertNotEmpty( $m[1], 'The delete-role control must carry a form="…" attribute.' );

		$footer = $this->render_footer();
		foreach ( $m[1] as $form_id ) {
			$this->assertStringContainsString( '<form id="' . $form_id . '"', $footer );
		}
		$this->assertStringContainsString( 'anchor_courses_delete_role', $footer );
		$this->assertStringContainsString( '_wpnonce', $footer );
	}

	/** admin_footer only prints MetaboxForms's queue on the course edit screen. */
	private function render_footer(): string {
		set_current_screen( 'anchor_course' );
		\ob_start();
		\do_action( 'admin_footer' );
		return (string) \ob_get_clean();
	}
}
