<?php
/**
 * Anchor Courses - front-end Course Manager shortcode.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Admin\LearnerReports;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Frontend\CourseManager;
use Anchor\Courses\Services\CompletionService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

/** Thrown from wp_redirect so the handler's exit() never runs. */
class Anchor_Courses_Manager_Redirected extends \Exception {}

/** @group courses */
class Test_Courses_Course_Manager extends Anchor_Courses_TestCase {

	private int $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Courses_Manager_Redirected( (string) $location );
	}

	/** Post the form as the current user; returns the redirect URL ('' if none). */
	private function submit( int $course, array $fields, ?string $nonce = null ): string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = [
			'acm_course'            => (string) $course,
			'_wpnonce'              => $nonce ?? wp_create_nonce( CourseManager::NONCE ),
			'anchor_course_manager' => wp_slash( $fields ),
		];
		try {
			( new CourseManager() )->handle_save();
		} catch ( Anchor_Courses_Manager_Redirected $e ) {
			return $e->getMessage();
		}
		return '';
	}

	public function test_shortcode_registered() {
		$this->assertTrue( shortcode_exists( 'anchor_course_manager' ) );
	}

	public function test_renders_nothing_for_non_admin() {
		$this->make_course();
		wp_set_current_user( $this->make_learner() );
		$this->assertSame( '', do_shortcode( '[anchor_course_manager]' ) );
		wp_set_current_user( 0 );
		$this->assertSame( '', do_shortcode( '[anchor_course_manager]' ) );
	}

	public function test_list_shows_courses_with_status() {
		$this->make_course( [], 'Published Alpha' );
		$draft = $this->make_course( [], 'Drafty Beta' );
		wp_update_post( [ 'ID' => $draft, 'post_status' => 'draft' ] );
		$trash = $this->make_course( [], 'Trashed Gamma' );
		wp_trash_post( $trash );

		$html = do_shortcode( '[anchor_course_manager]' );
		$this->assertStringContainsString( 'Published Alpha', $html );
		$this->assertStringContainsString( 'Drafty Beta', $html );
		$this->assertStringContainsString( 'Draft', $html );
		$this->assertStringNotContainsString( 'Trashed Gamma', $html );
	}

	public function test_edit_form_shows_saved_values_and_no_ce_type_default() {
		$id = $this->make_course( [ 'ce_credits' => '4' ] );
		$_GET['acm_course'] = (string) $id;

		$html = do_shortcode( '[anchor_course_manager]' );
		$this->assertStringContainsString( 'value="4"', $html );
		$this->assertMatchesRegularExpression( '/name="anchor_course_manager\[ce_type\]"[^>]*value=""/', $html );
		$this->assertStringContainsString( 'name="anchor_course_manager[certificate_enabled]" value="0"', $html );
	}

	public function test_save_updates_frontend_keys() {
		$id  = $this->make_course();
		$url = $this->submit( $id, [ 'ce_credits' => '6', 'ce_provider_name' => 'Acme' ] );

		$this->assertSame( '6', (string) (int) CourseEditor::setting( $id, 'ce_credits' ) );
		$this->assertSame( 'Acme', CourseEditor::setting( $id, 'ce_provider_name' ) );
		$this->assertStringContainsString( 'acm_saved=1', $url );
		$this->assertStringContainsString( 'acm_course=' . $id, $url );
	}

	public function test_unchecking_certificate_turns_it_off() {
		$id = $this->make_course( [ 'certificate_enabled' => 1 ] );
		$this->submit( $id, [ 'certificate_enabled' => '0' ] );
		$this->assertFalse( (bool) CourseEditor::setting( $id, 'certificate_enabled' ) );
	}

	public function test_non_frontend_keys_are_ignored() {
		$id = $this->make_course( [ 'completion_mode' => 'all_required_items' ] );
		$this->submit( $id, [ 'prerequisites' => [ 'x' ], 'completion_mode' => 'manual', 'ce_credits' => '1' ] );

		$this->assertSame( 'all_required_items', CourseEditor::setting( $id, 'completion_mode' ) );
		$this->assertSame( [], CourseEditor::setting( $id, 'prerequisites' ) );
	}

	public function test_save_rejects_bad_nonce_and_non_admin() {
		$id = $this->make_course( [ 'ce_credits' => '2' ] );

		$this->submit( $id, [ 'ce_credits' => '9' ], 'badnonce' );
		$this->assertSame( '2', (string) get_post_meta( $id, '_anchor_course_ce_credits', true ) );

		wp_set_current_user( $this->make_learner() );
		$this->submit( $id, [ 'ce_credits' => '9' ] );
		$this->assertSame( '2', (string) get_post_meta( $id, '_anchor_course_ce_credits', true ) );
	}

	public function test_bad_course_id_falls_back_to_list() {
		$this->make_course( [], 'Listed One' );
		$page = $this->factory->post->create( [ 'post_type' => 'page' ] );
		$_GET['acm_course'] = (string) $page;
		$html               = do_shortcode( '[anchor_course_manager]' );
		$this->assertStringContainsString( 'Listed One', $html );
		$this->assertStringNotContainsString( '<form', $html );

		$trash = $this->make_course( [], 'Gone' );
		wp_trash_post( $trash );
		$_GET['acm_course'] = (string) $trash;
		$html               = do_shortcode( '[anchor_course_manager]' );
		$this->assertStringContainsString( 'Listed One', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/** A free-progression course with one lesson, so a learner can finish it. */
	private function course_with_lesson( array $meta = [] ): array {
		$course = $this->make_course( $meta + [ 'progression_mode' => 'free', 'ce_credits' => '2', 'certificate_enabled' => 1 ], 'Laser Safety' );
		$lesson = $this->make_lesson();
		Curriculum::save( $course, [ [ 'title' => 'M', 'items' => [ [ 'type' => 'lesson', 'id' => $lesson ] ] ] ] );
		return [ $course, $lesson ];
	}

	private function complete_learner( int $user, int $course, int $lesson ): void {
		\Anchor\Courses\Support\Roles::grant_access( $user, $course, 'manual' );
		$enrollments = new EnrollmentService();
		$progress    = new ProgressService( $enrollments );
		$completion  = new CompletionService( $enrollments, $progress );
		$progress->set_completion_service( $completion );
		$progress->complete_lesson( $user, $course, $lesson );
		$completion->complete( $user, $course );
	}

	/** The learners section of the edit screen for $course. */
	private function learners_html( int $course ): string {
		$_GET['acm_course'] = (string) $course;
		$html               = do_shortcode( '[anchor_course_manager]' );
		$this->assertSame( 1, preg_match( '#<section class="acm-learners".*?</section>#s', $html, $m ), 'learners section present' );
		return $m[0];
	}

	public function test_learners_table_lists_enrolled_and_completed() {
		[ $course, $lesson ] = $this->course_with_lesson();
		$a = $this->make_learner( [ 'user_email' => 'ada-learner@example.com' ] );
		$b = $this->make_learner( [ 'user_email' => 'bob-learner@example.com' ] );
		$svc = new EnrollmentService();
		$svc->enroll( $a, $course );
		$svc->enroll( $b, $course );
		$this->complete_learner( $a, $course, $lesson );

		$html = $this->learners_html( $course );
		$this->assertStringContainsString( 'ada-learner@example.com', $html );
		$this->assertStringContainsString( 'bob-learner@example.com', $html );
		$done = LearnerReports::rows( $course );
		$completed = array_values( array_filter( $done, static fn( $r ) => 'completed' === $r['status'] ) )[0];
		$this->assertStringContainsString( date_i18n( get_option( 'date_format' ), strtotime( $completed['completed_at'] ) ), $html );
		$this->assertStringContainsString( number_format_i18n( 2.0, 1 ), $html );
	}

	public function test_certificate_links_to_certificate_page() {
		[ $course, $lesson ] = $this->course_with_lesson();
		$a = $this->make_learner();
		( new EnrollmentService() )->enroll( $a, $course );
		$this->complete_learner( $a, $course, $lesson );

		$row  = LearnerReports::rows( $course )[0];
		$html = $this->learners_html( $course );
		$this->assertNotSame( '', $row['certificate_number'] );
		$this->assertStringContainsString( '<a href="' . esc_url( $row['certificate_url'] ) . '">' . esc_html( $row['certificate_number'] ) . '</a>', $html );
	}

	public function test_no_actions_in_learner_table() {
		[ $course, $lesson ] = $this->course_with_lesson();
		$a = $this->make_learner();
		( new EnrollmentService() )->enroll( $a, $course );
		$this->complete_learner( $a, $course, $lesson );

		$html = $this->learners_html( $course );
		$this->assertStringContainsString( '<table', $html );
		$this->assertStringNotContainsString( '<form', $html );
		foreach ( [ 'Revoke', 'Reset progress', 'Mark complete' ] as $label ) {
			$this->assertStringNotContainsString( $label, $html );
		}
	}

	public function test_no_provider_or_type_beside_credits() {
		[ $course, $lesson ] = $this->course_with_lesson( [ 'ce_type' => 'AGD PACE', 'ce_provider_name' => 'Acme' ] );
		$a = $this->make_learner();
		( new EnrollmentService() )->enroll( $a, $course );
		$this->complete_learner( $a, $course, $lesson );

		$html = $this->learners_html( $course );
		$this->assertStringContainsString( number_format_i18n( 2.0, 1 ), $html );
		$this->assertStringNotContainsString( 'AGD PACE', $html );
		$this->assertStringNotContainsString( 'Acme', $html );
	}

	public function test_learners_paginate_at_50() {
		[ $course ] = $this->course_with_lesson();
		$svc = new EnrollmentService();
		for ( $i = 0; $i < 51; $i++ ) {
			$svc->enroll( $this->make_learner(), $course );
		}

		$page1 = $this->learners_html( $course );
		$this->assertSame( 50, substr_count( $page1, '<tr class="acm-learner">' ) );
		$this->assertStringContainsString( 'Showing 1–50 of 51', $page1 );
		$this->assertStringContainsString( 'acm_page=2', $page1 );

		$_GET['acm_page'] = '2';
		$page2 = $this->learners_html( $course );
		$this->assertSame( 1, substr_count( $page2, '<tr class="acm-learner">' ) );
		$this->assertStringContainsString( 'Showing 51–51 of 51', $page2 );
	}

	public function test_learners_hidden_without_reports_cap() {
		[ $course ] = $this->course_with_lesson();
		( new EnrollmentService() )->enroll( $this->make_learner(), $course );
		$cap = \Anchor\Courses\Support\Capabilities::cap( 'reports' );
		add_filter( 'user_has_cap', $f = static function ( $caps ) use ( $cap ) { unset( $caps[ $cap ] ); return $caps; } );
		if ( \current_user_can( $cap ) ) {
			remove_filter( 'user_has_cap', $f );
			$this->markTestSkipped( 'reports cap not removable here' );
		}
		$_GET['acm_course'] = (string) $course;
		$html = do_shortcode( '[anchor_course_manager]' );
		remove_filter( 'user_has_cap', $f );
		$this->assertStringNotContainsString( 'acm-learners', $html );
	}
}
