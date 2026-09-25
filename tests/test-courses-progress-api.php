<?php
/**
 * Anchor Courses - public progress API and the no-JS completion form.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Domain\CourseProgress;
use Anchor\Courses\Domain\Progress;
use Anchor\Courses\Frontend\Actions;
use Anchor\Courses\Services\EnrollmentService;

/** Thrown from the wp_redirect filter so the handler's exit() never runs. */
class Anchor_Courses_Redirected extends \Exception {}

/** @group courses */
class Test_Courses_Progress_Api extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free' ] );
		$this->lesson = $this->make_lesson();
		Curriculum::save( $this->course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
		// Through the one door: is_enrolled() needs the row AND the access role.
		\Anchor\Courses\Support\Roles::grant_access( $this->user, $this->course );

		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Courses_Redirected( (string) $location );
	}

	public function test_complete_lesson_api_delegates_to_the_service() {
		$result = anchor_courses_complete_lesson( $this->user, $this->course, $this->lesson );
		$this->assertInstanceOf( Progress::class, $result );
		$this->assertSame( 'completed', $result->status );
	}

	public function test_get_progress_api_returns_a_course_progress() {
		$before = anchor_courses_get_progress( $this->user, $this->course );
		$this->assertInstanceOf( CourseProgress::class, $before );
		$this->assertSame( 0.0, $before->percent );

		anchor_courses_complete_lesson( $this->user, $this->course, $this->lesson );

		$this->assertSame( 100.0, anchor_courses_get_progress( $this->user, $this->course )->percent );
	}

	public function test_the_form_handler_is_wired_to_both_admin_post_hooks() {
		$actions = new Actions();
		$this->assertNotFalse( has_action( 'admin_post_anchor_courses_complete_lesson', [ $actions, 'handle_complete_lesson' ] ) );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_anchor_courses_complete_lesson', [ $actions, 'handle_complete_lesson' ] ) );
	}

	public function test_the_form_handler_completes_the_lesson_and_redirects() {
		wp_set_current_user( $this->user );
		$_POST = [
			'course_id' => (string) $this->course,
			'lesson_id' => (string) $this->lesson,
			'_wpnonce'  => wp_create_nonce( Actions::NONCE_COMPLETE . '_' . $this->lesson ),
			'_redirect' => get_permalink( $this->lesson ),
		];

		try {
			( new Actions() )->handle_complete_lesson();
			$this->fail( 'Expected a redirect.' );
		} catch ( Anchor_Courses_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_notice=completed', $e->getMessage() );
		}

		$this->assertSame( 100.0, anchor_courses_get_progress( $this->user, $this->course )->percent );
	}

	public function test_the_form_handler_refuses_a_bad_nonce() {
		wp_set_current_user( $this->user );
		$_POST = [
			'course_id' => (string) $this->course,
			'lesson_id' => (string) $this->lesson,
			'_wpnonce'  => 'not-a-nonce',
		];

		try {
			( new Actions() )->handle_complete_lesson();
			$this->fail( 'Expected a redirect.' );
		} catch ( Anchor_Courses_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_notice=bad_nonce', $e->getMessage() );
		}

		$this->assertSame( 0.0, anchor_courses_get_progress( $this->user, $this->course )->percent );
	}

	public function test_the_form_handler_refuses_a_logged_out_visitor() {
		wp_set_current_user( 0 );
		$_POST = [
			'course_id' => (string) $this->course,
			'lesson_id' => (string) $this->lesson,
			'_wpnonce'  => wp_create_nonce( Actions::NONCE_COMPLETE . '_' . $this->lesson ),
		];

		try {
			( new Actions() )->handle_complete_lesson();
			$this->fail( 'Expected a redirect.' );
		} catch ( Anchor_Courses_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_notice=login_required', $e->getMessage() );
		}
	}

	public function test_a_service_error_becomes_a_notice_not_a_fatal() {
		$stranger = $this->make_learner();
		wp_set_current_user( $stranger );
		$_POST = [
			'course_id' => (string) $this->course,
			'lesson_id' => (string) $this->lesson,
			'_wpnonce'  => wp_create_nonce( Actions::NONCE_COMPLETE . '_' . $this->lesson ),
		];

		try {
			( new Actions() )->handle_complete_lesson();
			$this->fail( 'Expected a redirect.' );
		} catch ( Anchor_Courses_Redirected $e ) {
			$this->assertStringContainsString( 'anchor_courses_notice=not_enrolled', $e->getMessage() );
		}
	}

	public function test_complete_url_carries_the_action_and_ids() {
		$url = Actions::complete_url( $this->course, $this->lesson );
		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=anchor_courses_complete_lesson', $url );
	}

	/** Self-enrolment does not exist, so neither does an enrol endpoint. */
	public function test_there_is_no_self_enrolment_endpoint() {
		new Actions();

		$this->assertFalse( has_action( 'admin_post_anchor_courses_enroll' ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_anchor_courses_enroll' ) );
		$this->assertFalse( method_exists( Actions::class, 'handle_enroll' ) );
		$this->assertFalse( method_exists( Actions::class, 'enroll_url' ) );
	}
}
