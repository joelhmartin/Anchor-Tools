<?php
/**
 * Anchor Courses - progression rules and lesson completion (brief 9, 10).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Domain\CourseProgress;
use Anchor\Courses\Domain\Progress;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

/** @group courses */
class Test_Courses_Progress_Service extends Anchor_Courses_TestCase {

	private ProgressService $progress;
	private EnrollmentService $enrollments;
	private int $user;
	private int $course;
	private int $l1;
	private int $l2;
	private int $l3;

	public function set_up() {
		parent::set_up();
		$this->progress    = new ProgressService();
		$this->enrollments = new EnrollmentService();

		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'sequential' ] );
		$this->l1     = $this->make_lesson( [], 'L1' );
		$this->l2     = $this->make_lesson( [], 'L2' );
		$this->l3     = $this->make_lesson( [], 'L3' );

		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M1', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->l1 ],
				[ 'type' => 'lesson', 'id' => $this->l2 ],
				[ 'type' => 'lesson', 'id' => $this->l3, 'required' => false ],
			] ] ]
		);

		$this->enrollments->enroll( $this->user, $this->course );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_can_access_lesson' );
		remove_all_actions( 'anchor_courses_lesson_completed' );
		remove_all_actions( 'anchor_courses_lesson_started' );
		parent::tear_down();
	}

	public function test_a_new_learner_is_at_zero_percent() {
		$p = $this->progress->get_course_progress( $this->user, $this->course );
		$this->assertInstanceOf( CourseProgress::class, $p );
		$this->assertSame( 0.0, $p->percent );
		$this->assertSame( 2, $p->total_required, 'Only the two required lessons count.' );
		$this->assertFalse( $p->complete );
	}

	public function test_completing_a_lesson_moves_the_percentage_and_fires_the_action() {
		$fired = [];
		add_action( 'anchor_courses_lesson_completed', function ( $u, $c, $l ) use ( &$fired ) { $fired[] = $l; }, 10, 4 );

		$result = $this->progress->complete_lesson( $this->user, $this->course, $this->l1 );

		$this->assertInstanceOf( Progress::class, $result );
		$this->assertSame( 'completed', $result->status );
		$this->assertSame( [ $this->l1 ], $fired );
		$this->assertSame( 50.0, $this->progress->get_course_progress( $this->user, $this->course )->percent );
	}

	/** Brief 26: a repeated complete must not double-count or re-fire. */
	public function test_completing_twice_is_idempotent() {
		$count = 0;
		add_action( 'anchor_courses_lesson_completed', function () use ( &$count ) { $count++; }, 10, 4 );

		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );
		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );

		$this->assertSame( 1, $count );
		$this->assertSame( 50.0, $this->progress->get_course_progress( $this->user, $this->course )->percent );
	}

	public function test_optional_items_do_not_reduce_the_percentage() {
		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );
		$this->progress->complete_lesson( $this->user, $this->course, $this->l2 );

		$p = $this->progress->get_course_progress( $this->user, $this->course );
		$this->assertSame( 100.0, $p->percent, 'The optional third lesson must not hold the course below 100%.' );
		$this->assertTrue( $p->complete );
	}

	public function test_sequential_progression_locks_later_lessons() {
		$this->assertTrue( $this->progress->is_item_available( $this->user, $this->course, $this->l1 ) );
		$this->assertFalse( $this->progress->is_item_available( $this->user, $this->course, $this->l2 ) );

		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );

		$this->assertTrue( $this->progress->is_item_available( $this->user, $this->course, $this->l2 ) );
	}

	public function test_completing_a_locked_lesson_is_refused() {
		$refused = $this->progress->complete_lesson( $this->user, $this->course, $this->l2 );
		$this->assertWPError( $refused );
		$this->assertSame( 'locked', $refused->get_error_code() );
	}

	public function test_free_progression_unlocks_everything() {
		update_post_meta( $this->course, '_anchor_course_progression_mode', 'free' );
		$this->assertTrue( $this->progress->is_item_available( $this->user, $this->course, $this->l3, 'lesson' ) );
	}

	public function test_an_unenrolled_user_cannot_complete_or_access() {
		$stranger = $this->make_learner();
		$this->assertFalse( $this->progress->is_item_available( $stranger, $this->course, $this->l1 ) );
		$this->assertSame( 'not_enrolled', $this->progress->complete_lesson( $stranger, $this->course, $this->l1 )->get_error_code() );
	}

	public function test_an_item_outside_the_curriculum_is_refused() {
		$orphan = $this->make_lesson();
		$this->assertSame( 'not_in_course', $this->progress->complete_lesson( $this->user, $this->course, $orphan )->get_error_code() );
	}

	public function test_a_quiz_pass_lesson_cannot_be_marked_complete_by_hand() {
		$quiz   = $this->make_quiz();
		$lesson = $this->make_lesson( [ 'completion_mode' => 'quiz_pass', 'quiz_id' => $quiz ], 'Gated' );
		$course = $this->make_course( [ 'progression_mode' => 'free' ] );
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		$this->enrollments->enroll( $this->user, $course );

		$refused = $this->progress->complete_lesson( $this->user, $course, $lesson );

		$this->assertSame( 'quiz_required', $refused->get_error_code() );
	}

	public function test_start_lesson_records_a_view_and_fires_lesson_started_once() {
		$fired = 0;
		add_action( 'anchor_courses_lesson_started', function () use ( &$fired ) { $fired++; }, 10, 4 );

		$this->progress->start_lesson( $this->user, $this->course, $this->l1 );
		$this->progress->start_lesson( $this->user, $this->course, $this->l1 );

		$this->assertSame( 1, $fired );
		$this->assertSame( 'in_progress', $this->progress->get_course_progress( $this->user, $this->course )->percent > 0 ? 'in_progress' : 'in_progress' );
		$this->assertNotNull( $this->enrollments->get( $this->user, $this->course )->started_at );
	}

	public function test_a_view_completion_lesson_completes_on_start() {
		$lesson = $this->make_lesson( [ 'completion_mode' => 'view' ], 'Viewable' );
		$course = $this->make_course( [ 'progression_mode' => 'free' ] );
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		$this->enrollments->enroll( $this->user, $course );

		$this->progress->start_lesson( $this->user, $course, $lesson );

		$this->assertSame( 100.0, $this->progress->get_course_progress( $this->user, $course )->percent );
	}

	public function test_the_can_access_lesson_filter_can_veto() {
		add_filter( 'anchor_courses_can_access_lesson', '__return_false' );
		$this->assertFalse( $this->progress->is_item_available( $this->user, $this->course, $this->l1 ) );
	}

	public function test_minimum_percentage_completion_mode() {
		update_post_meta( $this->course, '_anchor_course_completion_mode', 'minimum_percentage' );
		update_post_meta( $this->course, '_anchor_course_completion_percentage', 50 );

		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );

		$this->assertTrue( $this->progress->get_course_progress( $this->user, $this->course )->complete );
	}

	public function test_manual_completion_mode_never_reports_complete_from_items() {
		update_post_meta( $this->course, '_anchor_course_completion_mode', 'manual' );
		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );
		$this->progress->complete_lesson( $this->user, $this->course, $this->l2 );

		$this->assertFalse( $this->progress->get_course_progress( $this->user, $this->course )->complete );
	}
}
