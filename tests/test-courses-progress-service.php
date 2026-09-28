<?php
/**
 * Anchor Courses - progression rules and lesson completion (brief 9, 10).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Database\ProgressRepository;
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

		// Through the one door: is_enrolled() needs the row AND the access role.
		\Anchor\Courses\Support\Roles::grant_access( $this->user, $this->course );
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

	/**
	 * Task 24 review, ruling R2: record_item() is the single write path for
	 * both the lesson flow and the quiz service (QuizService::start_attempt()
	 * calls it with 'in_progress' on every retake). A retake's bookkeeping
	 * write must never regress an already-completed row.
	 */
	public function test_record_item_never_downgrades_a_completed_row_to_in_progress() {
		$this->progress->record_item( $this->user, $this->course, $this->l1, 'lesson', 'completed' );

		$after = $this->progress->record_item( $this->user, $this->course, $this->l1, 'lesson', 'in_progress' );

		$this->assertSame( 'completed', $after->status );
		$this->assertContains( 'lesson:' . $this->l1, $this->progress->get_completed_items( $this->user, $this->course ) );
	}

	/**
	 * Final review I5 reverses T24 R2's fail branch: a later 'failed' outcome
	 * never replaces a completed row either - the best attempt counts.
	 */
	public function test_record_item_never_downgrades_a_completed_row_to_failed() {
		$this->progress->record_item( $this->user, $this->course, $this->l1, 'lesson', 'completed' );

		$after = $this->progress->record_item( $this->user, $this->course, $this->l1, 'lesson', 'failed' );

		$this->assertSame( 'completed', $after->status );
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
		\Anchor\Courses\Support\Roles::grant_access( $this->user, $course );

		$refused = $this->progress->complete_lesson( $this->user, $course, $lesson );

		$this->assertSame( 'quiz_required', $refused->get_error_code() );
	}

	public function test_start_lesson_records_a_view_and_fires_lesson_started_once() {
		$fired = 0;
		add_action( 'anchor_courses_lesson_started', function () use ( &$fired ) { $fired++; }, 10, 4 );

		$this->progress->start_lesson( $this->user, $this->course, $this->l1 );
		$this->progress->start_lesson( $this->user, $this->course, $this->l1 );

		$this->assertSame( 1, $fired );
		$row = ProgressRepository::find( $this->user, $this->course, $this->l1, 'lesson' );
		$this->assertNotNull( $row, 'start_lesson() must write a progress row for the lesson.' );
		$this->assertSame( 'in_progress', $row->status );
		$this->assertNotNull( $this->enrollments->get( $this->user, $this->course )->started_at );
	}

	public function test_a_view_completion_lesson_completes_on_start() {
		$lesson = $this->make_lesson( [ 'completion_mode' => 'view' ], 'Viewable' );
		$course = $this->make_course( [ 'progression_mode' => 'free' ] );
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		\Anchor\Courses\Support\Roles::grant_access( $this->user, $course );

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

	/* ---------------------------------------------------------------------
	 * PR36 bot review finding e - an unpublished curriculum item never gates
	 * progression, and never counts toward the completion percentage.
	 * ------------------------------------------------------------------- */

	public function test_a_draft_required_lesson_never_gates_sequential_progression() {
		$draft = self::factory()->post->create(
			[ 'post_type' => \Anchor\Courses\Content\LessonPostType::CPT, 'post_status' => 'draft', 'post_title' => 'Draft Lesson' ]
		);

		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M1', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->l1 ],
				[ 'type' => 'lesson', 'id' => $draft ],
				[ 'type' => 'lesson', 'id' => $this->l2 ],
			] ] ]
		);

		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );

		$this->assertTrue(
			$this->progress->is_item_available( $this->user, $this->course, $this->l2 ),
			'A draft required lesson must never gate sequential progression - it cannot be completed through any public route.'
		);
	}

	public function test_a_draft_required_quiz_never_gates_sequential_progression() {
		$draft_quiz = self::factory()->post->create(
			[ 'post_type' => \Anchor\Courses\Content\QuizPostType::CPT, 'post_status' => 'draft', 'post_title' => 'Draft Quiz' ]
		);

		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M1', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->l1 ],
				[ 'type' => 'quiz', 'id' => $draft_quiz ],
				[ 'type' => 'lesson', 'id' => $this->l2 ],
			] ] ]
		);

		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );

		$this->assertTrue(
			$this->progress->is_item_available( $this->user, $this->course, $this->l2 ),
			'A draft required quiz must never gate sequential progression.'
		);
	}

	public function test_a_draft_required_item_does_not_count_toward_the_completion_percentage() {
		$draft = self::factory()->post->create(
			[ 'post_type' => \Anchor\Courses\Content\LessonPostType::CPT, 'post_status' => 'draft', 'post_title' => 'Draft Lesson' ]
		);

		Curriculum::save(
			$this->course,
			[ [ 'title' => 'M1', 'items' => [
				[ 'type' => 'lesson', 'id' => $this->l1 ],
				[ 'type' => 'lesson', 'id' => $draft ],
			] ] ]
		);

		$this->progress->complete_lesson( $this->user, $this->course, $this->l1 );

		$p = $this->progress->get_course_progress( $this->user, $this->course );
		$this->assertSame( 1, $p->total_required, 'A draft required item must not count toward the total - a learner cannot complete it either.' );
		$this->assertSame( 100.0, $p->percent );
		$this->assertTrue( $p->complete );
	}

	/**
	 * PR36 round 2, CodeRabbit (Major): a required curriculum that is
	 * NON-EMPTY but made ENTIRELY of drafts must not complete the course
	 * under `minimum_percentage` - published_only() leaves $total at 0,
	 * and percent()'s own zero-total short-circuit returns 100.0, which is
	 * correct for a genuinely empty curriculum (nothing to do) but wrong
	 * here: this course has real required work, it is simply all
	 * unpublished right now.
	 */
	public function test_a_draft_only_required_curriculum_does_not_complete_under_minimum_percentage() {
		update_post_meta( $this->course, '_anchor_course_completion_mode', 'minimum_percentage' );
		update_post_meta( $this->course, '_anchor_course_completion_percentage', 50 );

		$draft = self::factory()->post->create(
			[ 'post_type' => \Anchor\Courses\Content\LessonPostType::CPT, 'post_status' => 'draft', 'post_title' => 'Only Draft' ]
		);

		Curriculum::save( $this->course, [ [ 'title' => 'M1', 'items' => [ [ 'type' => 'lesson', 'id' => $draft ] ] ] ] );

		$p = $this->progress->get_course_progress( $this->user, $this->course );

		$this->assertSame( 0, $p->total_required, 'The only required item is a draft - it must not count toward the total.' );
		$this->assertFalse(
			$p->complete,
			'A non-empty required curriculum with zero published items must not complete the course, unlike a genuinely EMPTY curriculum.'
		);
	}

	/** The genuinely empty case (no required items at all) is unaffected: still 100% and complete. */
	public function test_a_genuinely_empty_required_curriculum_still_completes_under_minimum_percentage() {
		update_post_meta( $this->course, '_anchor_course_completion_mode', 'minimum_percentage' );
		update_post_meta( $this->course, '_anchor_course_completion_percentage', 50 );

		Curriculum::save( $this->course, [ [ 'title' => 'M1', 'items' => [] ] ] );

		$p = $this->progress->get_course_progress( $this->user, $this->course );

		$this->assertSame( 0, $p->total_required );
		$this->assertSame( 100.0, $p->percent );
		$this->assertTrue( $p->complete, 'A genuinely empty curriculum has nothing left to do.' );
	}
}
