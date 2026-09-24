<?php
/**
 * Anchor Courses - curriculum persistence and navigation (brief section 6.2).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;

/** @group courses */
class Test_Courses_Curriculum extends Anchor_Courses_TestCase {

	private int $course;
	private int $lesson_a;
	private int $lesson_b;
	private int $quiz;

	public function set_up() {
		parent::set_up();
		$this->course   = $this->make_course();
		$this->lesson_a = $this->make_lesson( [], 'Lesson A' );
		$this->lesson_b = $this->make_lesson( [], 'Lesson B' );
		$this->quiz     = $this->make_quiz( [], 'Quiz A' );

		Curriculum::save(
			$this->course,
			[
				[ 'title' => 'Module 1', 'items' => [
					[ 'type' => 'lesson', 'id' => $this->lesson_a ],
					[ 'type' => 'quiz', 'id' => $this->quiz ],
				] ],
				[ 'title' => 'Module 2', 'items' => [
					[ 'type' => 'lesson', 'id' => $this->lesson_b, 'required' => false ],
				] ],
			]
		);
	}

	public function test_save_round_trips_through_post_meta() {
		$modules = Curriculum::get( $this->course );
		$this->assertCount( 2, $modules );
		$this->assertSame( 'Module 1', $modules[0]['title'] );
		$this->assertCount( 2, $modules[0]['items'] );
	}

	public function test_items_are_flattened_in_curriculum_order() {
		$items = Curriculum::items( $this->course );
		$this->assertSame(
			[ [ 'lesson', $this->lesson_a ], [ 'quiz', $this->quiz ], [ 'lesson', $this->lesson_b ] ],
			array_map( static fn( $i ) => [ $i['type'], $i['id'] ], $items )
		);
		$this->assertSame( [ 0, 1, 2 ], array_column( $items, 'index' ) );
	}

	public function test_required_items_excludes_optional_ones() {
		$required = Curriculum::required_items( $this->course );
		$this->assertCount( 2, $required );
		$this->assertSame( [ $this->lesson_a, $this->quiz ], array_column( $required, 'id' ) );
	}

	public function test_position_and_items_before() {
		$this->assertSame( 0, Curriculum::position( $this->course, $this->lesson_a, 'lesson' ) );
		$this->assertSame( 2, Curriculum::position( $this->course, $this->lesson_b, 'lesson' ) );
		$this->assertSame( -1, Curriculum::position( $this->course, 999999, 'lesson' ) );

		$before = Curriculum::items_before( $this->course, $this->quiz, 'quiz' );
		$this->assertSame( [ $this->lesson_a ], array_column( $before, 'id' ) );
	}

	public function test_course_for_item_resolves_the_owning_course() {
		$this->assertSame( $this->course, Curriculum::course_for_item( $this->lesson_b, 'lesson' ) );
		$this->assertSame( 0, Curriculum::course_for_item( $this->make_lesson(), 'lesson' ) );
	}

	/**
	 * Nothing stops the same item appearing in two courses' curricula. When
	 * that happens `course_for_item()` must resolve deterministically to the
	 * lowest course id - not to whichever course happened to save last.
	 */
	public function test_course_for_item_resolves_to_the_lowest_course_id_when_shared() {
		$lower_course  = $this->course;
		$higher_course = $this->make_course( [], 'Higher Course' );
		$this->assertGreaterThan( $lower_course, $higher_course );

		$shared = $this->make_lesson( [], 'Shared Lesson' );

		// Save the HIGHER-id course's curriculum first, to prove the result
		// is not insertion order.
		Curriculum::save( $higher_course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $shared ] ] ] ] );
		Curriculum::save(
			$lower_course,
			[ [ 'title' => 'Module 1', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->lesson_a ],
				[ 'type' => 'quiz', 'id' => $this->quiz ],
				[ 'type' => 'lesson', 'id' => $shared ],
			] ] ]
		);

		$this->assertSame( $lower_course, Curriculum::course_for_item( $shared, 'lesson' ) );
	}

	/** Brief section 21.1: removing an item from a course must not delete the post. */
	public function test_removing_an_item_leaves_the_lesson_post_intact() {
		Curriculum::save(
			$this->course,
			[ [ 'title' => 'Module 1', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson_a ] ] ] ]
		);
		$this->assertInstanceOf( WP_Post::class, get_post( $this->quiz ) );
		$this->assertSame( 'publish', get_post_status( $this->lesson_b ) );
		$this->assertFalse( Curriculum::contains( $this->course, $this->quiz, 'quiz' ) );
	}

	public function test_module_uuids_are_stable_across_a_resave() {
		$before = array_column( Curriculum::get( $this->course ), 'id' );
		Curriculum::save( $this->course, Curriculum::get( $this->course ) );
		$this->assertSame( $before, array_column( Curriculum::get( $this->course ), 'id' ) );
	}
}
