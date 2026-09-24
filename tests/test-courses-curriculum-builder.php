<?php
/**
 * Anchor Courses - curriculum builder save path and its AJAX helpers.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
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
}
