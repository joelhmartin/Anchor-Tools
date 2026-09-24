<?php
/**
 * Anchor Courses - CPT registration (brief section 6.1).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Content\QuizPostType;
use Anchor\Courses\Support\Capabilities;

/** @group courses */
class Test_Courses_Cpt extends Anchor_Courses_TestCase {

	public function test_all_three_post_types_are_registered() {
		$this->assertTrue( post_type_exists( 'anchor_course' ) );
		$this->assertTrue( post_type_exists( 'anchor_lesson' ) );
		$this->assertTrue( post_type_exists( 'anchor_quiz' ) );
	}

	public function test_courses_and_lessons_are_public_and_quizzes_are_private() {
		$this->assertTrue( get_post_type_object( 'anchor_course' )->public );
		$this->assertTrue( get_post_type_object( 'anchor_course' )->has_archive );
		$this->assertTrue( get_post_type_object( 'anchor_lesson' )->public );
		$this->assertFalse( get_post_type_object( 'anchor_lesson' )->has_archive );
		$this->assertFalse( get_post_type_object( 'anchor_quiz' )->public );
		$this->assertFalse( get_post_type_object( 'anchor_quiz' )->publicly_queryable );
	}

	public function test_each_post_type_uses_its_own_edit_capability() {
		$this->assertSame(
			Capabilities::cap( 'edit_courses' ),
			get_post_type_object( 'anchor_course' )->cap->edit_posts
		);
		$this->assertSame(
			Capabilities::cap( 'edit_lessons' ),
			get_post_type_object( 'anchor_lesson' )->cap->edit_posts
		);
		$this->assertSame(
			Capabilities::cap( 'edit_quizzes' ),
			get_post_type_object( 'anchor_quiz' )->cap->edit_posts
		);
	}

	public function test_administrator_can_edit_all_three_but_a_learner_cannot() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( current_user_can( Capabilities::cap( 'edit_courses' ) ) );
		wp_set_current_user( $this->make_learner() );
		$this->assertFalse( current_user_can( Capabilities::cap( 'edit_courses' ) ) );
	}

	public function test_parent_menu_filter_controls_show_in_menu() {
		add_filter( 'anchor_courses_parent_menu', '__return_false' );
		LessonPostType::register();
		$this->assertFalse( get_post_type_object( 'anchor_lesson' )->show_in_menu );
		remove_all_filters( 'anchor_courses_parent_menu' );
		LessonPostType::register();
		$this->assertSame(
			'edit.php?post_type=anchor_course',
			get_post_type_object( 'anchor_lesson' )->show_in_menu
		);
	}

	public function test_meta_key_helpers_prefix_consistently() {
		$this->assertSame( '_anchor_course_ce_credits', CoursePostType::meta_key( 'ce_credits' ) );
		$this->assertSame( '_anchor_lesson_completion_mode', LessonPostType::meta_key( 'completion_mode' ) );
		$this->assertSame( '_anchor_quiz_settings', QuizPostType::meta_key( 'settings' ) );
	}

	public function test_fixtures_create_usable_posts() {
		$course = $this->make_course( [ 'ce_credits' => '2' ] );
		$lesson = $this->make_lesson();
		$quiz   = $this->make_quiz();
		$this->assertSame( 'anchor_course', get_post_type( $course ) );
		$this->assertSame( 'anchor_lesson', get_post_type( $lesson ) );
		$this->assertSame( 'anchor_quiz', get_post_type( $quiz ) );
		$this->assertSame( '2', get_post_meta( $course, '_anchor_course_ce_credits', true ) );
	}
}
