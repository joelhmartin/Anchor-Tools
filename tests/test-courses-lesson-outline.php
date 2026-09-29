<?php
/**
 * Anchor Courses - the lesson page's course navigation: the curriculum
 * outline (Frontend\CourseOutline, templates/course-outline.php) and the
 * previous/next bar (templates/lesson-nav.php), on both lesson types.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Frontend\Access;
use Anchor\Courses\Frontend\CourseOutline;
use Anchor\Courses\Frontend\Templates;
use Anchor\Courses\Module;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Lesson_Outline extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $one;
	private int $quiz;
	private int $two;
	private int $three;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'sequential' ], 'Airway Basics' );
		$this->one    = $this->make_lesson( [], 'Lesson One' );
		$this->quiz   = $this->make_quiz( [], 'Checkpoint Quiz' );
		$this->two    = $this->make_lesson( [], 'Lesson Two' );
		$this->three  = $this->make_lesson( [], 'Lesson Three' );
		Curriculum::save(
			$this->course,
			[
				[
					'title' => 'Module A',
					'items' => [
						[ 'type' => 'lesson', 'id' => $this->one ],
						[ 'type' => 'quiz', 'id' => $this->quiz, 'required' => false ],
					],
				],
				[
					'title' => 'Module B',
					'items' => [
						[ 'type' => 'lesson', 'id' => $this->two ],
						[ 'type' => 'lesson', 'id' => $this->three ],
					],
				],
			]
		);
		Roles::grant_access( $this->user, $this->course );
		wp_set_current_user( $this->user );
	}

	private function progress(): ProgressService {
		return $this->courses()->progress;
	}

	private function build( int $lesson_id, int $user_id = -1 ): array {
		$user_id  = $user_id < 0 ? $this->user : $user_id;
		$progress = $user_id > 0 ? $this->progress()->get_course_progress( $user_id, $this->course ) : null;
		return ( new CourseOutline( $this->progress() ) )->build( $this->course, $user_id, $lesson_id, $progress );
	}

	/** @return array<string,array> Items keyed "type:id". */
	private function items( array $outline ): array {
		$items = [];
		foreach ( $outline['modules'] as $module ) {
			foreach ( $module['items'] as $item ) {
				$items[ $item['type'] . ':' . $item['id'] ] = $item;
			}
		}
		return $items;
	}

	private function render( int $lesson_id ): string {
		global $post;
		$post = get_post( $lesson_id );
		return Module::instance()->shortcodes->render_lesson( $lesson_id );
	}

	/* ------------------------------------------------------------------ data */

	public function test_outline_lists_every_module_and_item_in_curriculum_order() {
		$outline = $this->build( $this->one );

		$this->assertSame( [ 'Module A', 'Module B' ], array_column( $outline['modules'], 'title' ) );
		$this->assertSame( [ 'lesson:' . $this->one, 'quiz:' . $this->quiz, 'lesson:' . $this->two, 'lesson:' . $this->three ], array_keys( $this->items( $outline ) ) );
		$this->assertSame( 'Airway Basics', $outline['course_title'] );
		$this->assertSame( get_permalink( $this->course ), $outline['course_url'] );
	}

	public function test_states_are_current_done_available_and_locked() {
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );

		$items = $this->items( $this->build( $this->two ) );

		$this->assertSame( CourseOutline::STATE_DONE, $items[ 'lesson:' . $this->one ]['state'] );
		$this->assertTrue( $items[ 'lesson:' . $this->one ]['complete'] );
		// Optional, after a completed lesson: open but not done.
		$this->assertSame( CourseOutline::STATE_AVAILABLE, $items[ 'quiz:' . $this->quiz ]['state'] );
		$this->assertSame( CourseOutline::STATE_CURRENT, $items[ 'lesson:' . $this->two ]['state'] );
		$this->assertTrue( $items[ 'lesson:' . $this->two ]['current'] );
		// Sequential: lesson two is not complete, so three is locked.
		$this->assertSame( CourseOutline::STATE_LOCKED, $items[ 'lesson:' . $this->three ]['state'] );
		$this->assertFalse( $items[ 'lesson:' . $this->three ]['available'] );
	}

	public function test_a_locked_item_has_no_link_and_an_open_one_carries_the_course_context() {
		$items = $this->items( $this->build( $this->one ) );

		$this->assertSame( '', $items[ 'lesson:' . $this->two ]['url'], 'A locked lesson is not linked.' );
		$this->assertSame( Access::lesson_url( $this->one, $this->course ), $items[ 'lesson:' . $this->one ]['url'] );
	}

	public function test_a_quiz_links_to_its_item_on_the_course_page() {
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );

		$items = $this->items( $this->build( $this->two ) );
		$url   = $items[ 'quiz:' . $this->quiz ]['url'];

		$this->assertSame( get_permalink( $this->course ) . '#anchor-course-item-quiz-' . $this->quiz, $url );
		$this->assertStringNotContainsString( (string) get_permalink( $this->quiz ), $url );

		// The course page carries that anchor.
		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );
		$this->assertStringContainsString( 'id="' . CourseOutline::item_anchor( 'quiz', $this->quiz ) . '"', $html );
	}

	public function test_previous_and_next_walk_lessons_only_skipping_the_quiz() {
		$outline = $this->build( $this->one );
		$this->assertNull( $outline['previous'] );
		$this->assertSame( $this->two, $outline['next']['id'], 'The quiz between them is skipped.' );
		$this->assertSame( 'Lesson Two', $outline['next']['title'] );

		$outline = $this->build( $this->two );
		$this->assertSame( $this->one, $outline['previous']['id'] );
		$this->assertSame( $this->three, $outline['next']['id'] );

		$outline = $this->build( $this->three );
		$this->assertSame( $this->two, $outline['previous']['id'] );
		$this->assertNull( $outline['next'] );
	}

	public function test_next_is_locked_until_the_current_lesson_is_complete() {
		$this->assertFalse( $this->build( $this->one )['next']['available'] );

		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );

		$this->assertTrue( $this->build( $this->one )['next']['available'] );
	}

	public function test_unpublished_items_are_left_out_of_the_outline_and_the_walk() {
		wp_update_post( [ 'ID' => $this->two, 'post_status' => 'draft' ] );

		$outline = $this->build( $this->one );

		$this->assertArrayNotHasKey( 'lesson:' . $this->two, $this->items( $outline ) );
		$this->assertSame( $this->three, $outline['next']['id'] );
	}

	public function test_a_visitor_sees_every_item_locked_and_no_progress() {
		$outline = $this->build( $this->one, 0 );

		$this->assertNull( $outline['progress'] );
		foreach ( $this->items( $outline ) as $key => $item ) {
			if ( 'lesson:' . $this->one === $key ) {
				$this->assertSame( CourseOutline::STATE_CURRENT, $item['state'] );
				continue;
			}
			$this->assertSame( CourseOutline::STATE_LOCKED, $item['state'], $key );
			$this->assertSame( '', $item['url'], $key );
		}
	}

	/* -------------------------------------------------------------- markup */

	public function test_the_lesson_page_renders_the_outline_with_landmarks_and_states() {
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );

		$html = $this->render( $this->two );

		$this->assertStringContainsString( 'anchor-lesson-layout', $html );
		$this->assertMatchesRegularExpression( '/<nav class="anchor-course-outline" aria-label="Course outline">/', $html );
		$this->assertMatchesRegularExpression( '/<nav class="anchor-lesson-nav" aria-label="Lesson navigation">/', $html );
		$this->assertStringContainsString( '<details class="anchor-course-outline-toggle" open>', $html );
		$this->assertSame( 2, substr_count( $html, 'aria-current="page"' ), 'The breadcrumb and exactly one outline item.' );
		$this->assertMatchesRegularExpression( '/href="[^"]*' . preg_quote( esc_url( Access::lesson_url( $this->two, $this->course ) ), '/' ) . '" aria-current="page"/', $html );
		$this->assertStringContainsString( '(Locked)', $html, 'Locked items are announced as locked.' );
		$this->assertStringContainsString( '(Completed)', $html );
		// Progress through the shared renderer: 1 of 3 required.
		$this->assertStringContainsString( '(1 of 3)', $html );
	}

	public function test_the_nav_bar_names_the_neighbouring_lessons_and_next_is_the_primary_button() {
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );
		$this->progress()->complete_lesson( $this->user, $this->course, $this->two );

		$html = $this->render( $this->two );

		$this->assertMatchesRegularExpression( '/<a class="anchor-lesson-nav__link anchor-lesson-nav__link--prev[^"]*" href="[^"]+" rel="prev">\s*<span class="anchor-lesson-nav__label">Previous:<\/span>\s*<span class="anchor-lesson-nav__title">Lesson One<\/span>/', $html );
		$this->assertMatchesRegularExpression( '/<a class="anchor-courses-button anchor-lesson-nav__link anchor-lesson-nav__link--next[^"]*" href="[^"]+" rel="next">\s*<span class="anchor-lesson-nav__label">Next:<\/span>\s*<span class="anchor-lesson-nav__title">Lesson Three<\/span>/', $html );
	}

	public function test_a_locked_next_lesson_is_shown_but_not_linked() {
		$html = $this->render( $this->one );

		$this->assertMatchesRegularExpression( '/<span class="anchor-courses-button [^"]*anchor-lesson-nav__link--next[^"]*is-locked" aria-disabled="true">/', $html );
		$this->assertStringNotContainsString( 'rel="next"', $html );
	}

	public function test_after_the_last_lesson_the_bar_offers_back_to_course() {
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );
		$this->progress()->complete_lesson( $this->user, $this->course, $this->two );

		$html = $this->render( $this->three );

		$this->assertStringContainsString( 'Back to course', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $this->course ) ) . '"', $html );
		$this->assertStringNotContainsString( 'rel="next"', $html );
	}

	public function test_mark_complete_returns_to_the_footer_where_next_is() {
		$html = $this->render( $this->one );

		$this->assertStringContainsString( 'id="' . CourseOutline::FOOTER_ID . '"', $html );
		$this->assertStringContainsString(
			'name="_redirect" value="' . esc_url( Access::lesson_url( $this->one, $this->course ) . '#' . CourseOutline::FOOTER_ID ) . '"',
			$html
		);
	}

	public function test_a_view_completion_lesson_offers_next_on_its_first_render() {
		update_post_meta( $this->one, '_anchor_lesson_completion_mode', 'view' );

		$html = $this->render( $this->one );

		$this->assertStringContainsString( 'rel="next"', $html, 'Viewing completed the lesson, so Next is open straight away.' );
	}

	public function test_a_live_session_lesson_gets_the_same_outline_and_bar() {
		update_post_meta( $this->two, '_anchor_lesson_type', 'live_session' );
		$this->progress()->complete_lesson( $this->user, $this->course, $this->one );

		$html = $this->render( $this->two );

		$this->assertStringContainsString( 'anchor-live-session', $html );
		$this->assertStringContainsString( 'aria-label="Course outline"', $html );
		$this->assertStringContainsString( 'aria-label="Lesson navigation"', $html );
		$this->assertStringContainsString( 'Lesson One', $html );
		$this->assertStringContainsString( '#' . CourseOutline::FOOTER_ID, $html );
	}

	public function test_the_new_templates_are_theme_overridable() {
		foreach ( [ 'lesson-layout', 'course-outline', 'lesson-nav' ] as $name ) {
			$this->assertFileExists( Templates::locate( $name ), $name );
			$this->assertStringContainsString( 'anchor-courses/templates/' . $name . '.php', Templates::locate( $name ) );
		}
	}
}
