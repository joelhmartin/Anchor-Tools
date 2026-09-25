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

	/**
	 * Task 18 review fix round, ruling (a). `anchor_lesson` used to ship
	 * `show_in_rest => true` with no permission callback, so
	 * `GET /wp-json/wp/v2/anchor_lesson/{id}` returned the full lesson body -
	 * video/live-session markup included - to any anonymous caller, and the
	 * lesson leaked into feeds and search excerpts too. Task 26 ships its own
	 * permission-checked REST routes for lesson/quiz data, so core REST must
	 * never carry either post type; `publicly_queryable` stays true so the
	 * single template can still serve the gated page (Frontend\ContentGuard
	 * enforces the notice there, not the post type).
	 */
	public function test_lessons_and_quizzes_are_excluded_from_rest_and_lessons_from_search() {
		$lesson = get_post_type_object( LessonPostType::CPT );
		$quiz   = get_post_type_object( QuizPostType::CPT );

		$this->assertFalse( $lesson->show_in_rest, 'anchor_lesson must not ride core REST (Task 26 owns permission-checked routes).' );
		$this->assertFalse( $quiz->show_in_rest, 'anchor_quiz must not ride core REST (quiz questions/answers must never leak).' );
		$this->assertTrue( $lesson->exclude_from_search, 'A lesson body must not surface in search excerpts.' );
		$this->assertTrue( $lesson->publicly_queryable, 'The gated single-lesson template must still resolve.' );
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

	/**
	 * Fix round 1, defect 1. `capability_type` is a custom array, so
	 * `map_meta_cap` derives a capability name (e.g.
	 * `delete_published_anchor_courses`) for every primitive the
	 * `capabilities` array does not name explicitly, and `Capabilities::sync()`
	 * never grants a derived name to anyone. Before the fix, this left NO
	 * role - administrator included - able to edit or delete a private post,
	 * or delete a published one, of any of the three types.
	 */
	public function test_administrator_can_edit_and_delete_published_and_private_posts_of_each_type() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		foreach ( $this->post_types() as $cpt ) {
			foreach ( [ 'publish', 'private' ] as $status ) {
				$id = self::factory()->post->create( [ 'post_type' => $cpt, 'post_status' => $status ] );
				$this->assertTrue( current_user_can( 'edit_post', $id ), "administrator edit_post on {$cpt}/{$status}" );
				$this->assertTrue( current_user_can( 'delete_post', $id ), "administrator delete_post on {$cpt}/{$status}" );
			}
		}
	}

	public function test_a_learner_cannot_edit_or_delete_published_or_private_posts_of_any_type() {
		$ids = [];
		foreach ( $this->post_types() as $cpt ) {
			foreach ( [ 'publish', 'private' ] as $status ) {
				$ids[ "{$cpt}/{$status}" ] = self::factory()->post->create( [ 'post_type' => $cpt, 'post_status' => $status ] );
			}
		}

		wp_set_current_user( $this->make_learner() );

		foreach ( $ids as $label => $id ) {
			$this->assertFalse( current_user_can( 'edit_post', $id ), "learner edit_post on {$label}" );
			$this->assertFalse( current_user_can( 'delete_post', $id ), "learner delete_post on {$label}" );
		}
	}

	/** @return string[] */
	private function post_types(): array {
		return [ CoursePostType::CPT, LessonPostType::CPT, QuizPostType::CPT ];
	}
}
