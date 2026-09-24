<?php
/**
 * Anchor Courses - curriculum builder save path and its AJAX helpers.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\Curriculum;

/** @group courses */
class Test_Courses_Curriculum_Builder extends Anchor_Courses_TestCase {

	private function submit( int $course_id, string $json ): void {
		$_POST = [
			CourseEditor::NONCE        => wp_create_nonce( CourseEditor::NONCE ),
			'anchor_course_curriculum' => wp_slash( $json ),
		];
		( new CourseEditor() )->save_curriculum( $course_id );
		$_POST = [];
	}

	public function test_posted_json_becomes_a_canonical_curriculum() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course();
		$lesson = $this->make_lesson();
		$quiz   = $this->make_quiz();

		$this->submit(
			$course,
			wp_json_encode(
				[ [ 'title' => 'Module 1', 'items' => [
					[ 'type' => 'lesson', 'id' => $lesson ],
					[ 'type' => 'quiz', 'id' => $quiz ],
				] ] ]
			)
		);

		$modules = Curriculum::get( $course );
		$this->assertCount( 1, $modules );
		$this->assertSame( [ $lesson, $quiz ], array_column( $modules[0]['items'], 'id' ) );
		$this->assertNotSame( '', $modules[0]['id'] );
	}

	public function test_malformed_json_leaves_the_existing_curriculum_alone() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course();
		$lesson = $this->make_lesson();
		Curriculum::save( $course, [ [ 'title' => 'Keep me', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );

		$this->submit( $course, '{not json' );

		$this->assertSame( 'Keep me', Curriculum::get( $course )[0]['title'] );
	}

	public function test_a_learner_cannot_rewrite_the_curriculum() {
		$course = $this->make_course();
		$lesson = $this->make_lesson();
		Curriculum::save( $course, [ [ 'title' => 'Keep me', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );

		wp_set_current_user( $this->make_learner() );
		$this->submit( $course, wp_json_encode( [ [ 'title' => 'Hijacked', 'items' => [] ] ] ) );

		$this->assertSame( 'Keep me', Curriculum::get( $course )[0]['title'] );
	}

	public function test_search_returns_matching_lessons_only_for_the_lesson_type() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->make_lesson( [], 'Anatomy Basics' );
		$this->make_quiz( [], 'Anatomy Quiz' );

		$results = CourseEditor::search_items( 'Anatomy', 'lesson' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Anatomy Basics', $results[0]['title'] );
		$this->assertSame( 'lesson', $results[0]['type'] );
	}

	public function test_create_item_makes_a_draft_of_the_requested_type() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$created = CourseEditor::create_item( 'New Quiz', 'quiz' );

		$this->assertSame( 'anchor_quiz', get_post_type( $created['id'] ) );
		$this->assertSame( 'draft', get_post_status( $created['id'] ) );
		$this->assertStringContainsString( 'post.php', $created['edit_url'] );
	}

	public function test_create_item_refuses_an_unknown_type() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( [], CourseEditor::create_item( 'Nope', 'video' ) );
	}

	public function test_builder_script_is_registered_with_the_sortable_dependency() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'anchor_course' );
		$GLOBALS['post'] = get_post( $this->make_course() );
		( new CourseEditor() )->assets( 'post.php' );

		$script = wp_scripts()->registered['anchor-courses-curriculum'] ?? null;
		$this->assertNotNull( $script, 'The curriculum builder script was not enqueued.' );
		$this->assertContains( 'jquery-ui-sortable', $script->deps );
	}

	/* ---------------------------------------------------------------------
	 * Hook wiring: save() at the default priority (10), save_curriculum()
	 * one tick later (11), so a module/item edit always sees the settings
	 * save that ran first. Mirrors the convention in
	 * tests/test-woocommerce-checkout-hook-timing.php.
	 * ------------------------------------------------------------------- */

	public function test_settings_and_curriculum_saves_are_wired_at_the_documented_priorities() {
		$editor = new CourseEditor();

		$this->assertSame(
			10,
			has_action( 'save_post_' . CoursePostType::CPT, [ $editor, 'save' ] ),
			'CourseEditor::save() must stay on the default priority so it runs before save_curriculum().'
		);
		$this->assertSame(
			11,
			has_action( 'save_post_' . CoursePostType::CPT, [ $editor, 'save_curriculum' ] ),
			'CourseEditor::save_curriculum() must run one tick after save() (priority 11).'
		);
	}

	/** Fails if the `save_post_anchor_course` -> save_curriculum() wiring is ever removed. */
	public function test_curriculum_save_runs_through_the_real_save_post_hook() {
		new CourseEditor();
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course();
		$lesson = $this->make_lesson();

		$_POST = [
			CourseEditor::NONCE        => wp_create_nonce( CourseEditor::NONCE ),
			'anchor_course_curriculum' => wp_slash(
				wp_json_encode( [ [ 'title' => 'Via hook', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] )
			),
		];
		do_action( 'save_post_' . CoursePostType::CPT, $course, get_post( $course ), true );
		$_POST = [];

		$modules = Curriculum::get( $course );
		$this->assertSame( 'Via hook', $modules[0]['title'] ?? null );
		$this->assertSame( [ $lesson ], array_column( $modules[0]['items'] ?? [], 'id' ) );
	}

	/* ---------------------------------------------------------------------
	 * Revision guard: WordPress's own update_post_meta()/add_post_meta()
	 * already redirect a revision's post id to its PARENT
	 * (wp-includes/post.php: "Make sure meta is added to the post, not a
	 * revision."). So if save()/save_curriculum() is ever invoked with a
	 * revision's id - a compatibility shim, a bulk/quick-edit path, or a
	 * plugin re-dispatching save_post while restoring a revision - the write
	 * does NOT land harmlessly on the revision row; it silently overwrites
	 * the REAL course's meta instead. wp_is_post_revision() must bail before
	 * that happens.
	 * ------------------------------------------------------------------- */

	public function test_saving_a_revision_id_writes_no_curriculum() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course();
		$lesson = $this->make_lesson();
		Curriculum::save( $course, [ [ 'title' => 'Keep me', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );

		$revision_id = wp_save_post_revision( $course );
		$this->assertIsInt( $revision_id, 'wp_save_post_revision() must return a revision id for this test to be meaningful.' );
		$this->assertNotSame( 0, $revision_id );

		$this->submit(
			$revision_id,
			wp_json_encode( [ [ 'title' => 'Hijacked via revision id', 'items' => [] ] ] )
		);

		$this->assertSame( 'Keep me', Curriculum::get( $course )[0]['title'], 'A revision id must never overwrite the real course\'s curriculum.' );
		$this->assertSame( [], Curriculum::get( $revision_id ), 'Nothing may be written against the revision id itself either.' );
	}

	public function test_saving_a_revision_id_writes_no_settings() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$course = $this->make_course( [ 'instructor' => 'Original' ] );

		$revision_id = wp_save_post_revision( $course );
		$this->assertIsInt( $revision_id, 'wp_save_post_revision() must return a revision id for this test to be meaningful.' );
		$this->assertNotSame( 0, $revision_id );

		$_POST = [
			CourseEditor::NONCE => wp_create_nonce( CourseEditor::NONCE ),
			'anchor_course'     => [ 'instructor' => 'Hijacked via revision id' ],
		];
		( new CourseEditor() )->save( $revision_id );
		$_POST = [];

		$this->assertSame( 'Original', get_post_meta( $course, '_anchor_course_instructor', true ) );
		$this->assertSame( '', get_post_meta( $revision_id, '_anchor_course_instructor', true ) );
	}
}
