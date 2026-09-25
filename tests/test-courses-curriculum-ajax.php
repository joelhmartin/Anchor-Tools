<?php
/**
 * Anchor Courses - the two curriculum-builder AJAX endpoints
 * (anchor_courses_search_items, anchor_courses_create_item), exercised the
 * way admin-ajax.php actually dispatches them.
 *
 * Mirrors the WP_Ajax_UnitTestCase pattern in tests/test-add-to-cart-ajax.php:
 * POST into $_POST/$_REQUEST, run the real `wp_ajax_*` action via
 * _handleAjax(), and read the JSON envelope (or the raw '-1' die) back out.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Content\QuizPostType;
use Anchor\Courses\Support\Capabilities;

/**
 * @group courses
 * @group ajax
 */
class Test_Courses_Curriculum_Ajax extends WP_Ajax_UnitTestCase {

	const SEARCH_ACTION = 'anchor_courses_search_items';
	const CREATE_ACTION = 'anchor_courses_create_item';

	public function set_up() {
		parent::set_up();
		// The module only constructs CourseEditor when is_admin() is true
		// (anchor-courses/anchor-courses.php), which the CLI test process
		// never is - so the wp_ajax_* hooks must be registered explicitly,
		// exactly as tests/test-courses-curriculum-builder.php already does
		// for the non-AJAX methods.
		new CourseEditor();
		$_POST = [];
	}

	public function tear_down() {
		$_POST = [];
		parent::tear_down();
	}

	private function make_admin(): int {
		return (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function make_lesson( string $title = 'Test Lesson' ): int {
		return (int) self::factory()->post->create( [
			'post_type'  => LessonPostType::CPT,
			'post_title' => $title,
		] );
	}

	private function make_quiz( string $title = 'Test Quiz' ): int {
		return (int) self::factory()->post->create( [
			'post_type'  => QuizPostType::CPT,
			'post_title' => $title,
		] );
	}

	/**
	 * POST to an action and return the decoded JSON envelope. Assumes the
	 * handler completes via wp_send_json_*(), i.e. a WPAjaxDieStopException
	 * (nothing buffered before the die) or WPAjaxDieContinueException
	 * (the JSON was already echoed) - never a bare "no exception at all".
	 *
	 * @param string $action
	 * @param array  $data
	 * @return array Decoded { success: bool, data: {...} }.
	 */
	private function post( string $action, array $data ): array {
		$this->_last_response = '';
		$_GET                 = [];
		$_POST                = $data;

		try {
			$this->_handleAjax( $action );
			$this->fail( 'wp_send_json_*() always ends in wp_die(); no exception means the handler never responded.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected: the JSON was already emitted, then wp_die() ran.
		}

		$decoded = json_decode( $this->_last_response, true );
		$this->assertIsArray(
			$decoded,
			'The endpoint returned no valid JSON. Raw: ' . substr( (string) $this->_last_response, 0, 800 )
		);
		return $decoded;
	}

	/* ---------------------------------------------------------------------
	 * anchor_courses_search_items
	 * ------------------------------------------------------------------- */

	public function test_search_success_returns_the_expected_shape() {
		wp_set_current_user( $this->make_admin() );
		$lesson = $this->make_lesson( 'Anatomy Basics' );

		$decoded = $this->post( self::SEARCH_ACTION, [
			'nonce' => wp_create_nonce( CourseEditor::NONCE ),
			'term'  => 'Anatomy',
			'type'  => 'lesson',
		] );

		$this->assertTrue( $decoded['success'] );
		$this->assertCount( 1, $decoded['data'] );
		$this->assertSame( $lesson, $decoded['data'][0]['id'] );
		$this->assertSame( 'Anatomy Basics', $decoded['data'][0]['title'] );
		$this->assertSame( 'lesson', $decoded['data'][0]['type'] );
	}

	/** A plain WP post (neither lesson nor quiz) must never surface in results. */
	public function test_search_excludes_posts_of_other_types() {
		wp_set_current_user( $this->make_admin() );
		$this->make_lesson( 'Shared Title' );
		self::factory()->post->create( [ 'post_type' => 'post', 'post_title' => 'Shared Title' ] );

		$decoded = $this->post( self::SEARCH_ACTION, [
			'nonce' => wp_create_nonce( CourseEditor::NONCE ),
			'term'  => 'Shared Title',
			'type'  => 'lesson',
		] );

		$this->assertTrue( $decoded['success'] );
		$this->assertCount( 1, $decoded['data'] );
		$this->assertSame( 'lesson', $decoded['data'][0]['type'] );
	}

	public function test_search_with_a_missing_nonce_dies_with_negative_one() {
		wp_set_current_user( $this->make_admin() );
		$_GET  = [];
		$_POST = [ 'term' => 'Anything', 'type' => 'lesson' ];

		try {
			$this->_handleAjax( self::SEARCH_ACTION );
			$this->fail( 'check_ajax_referer() must die on a missing nonce.' );
		} catch ( WPAjaxDieStopException $e ) {
			$this->assertSame( '-1', $e->getMessage() );
		}
	}

	public function test_search_with_an_invalid_nonce_dies_with_negative_one() {
		wp_set_current_user( $this->make_admin() );
		$_GET  = [];
		$_POST = [ 'nonce' => 'not-a-real-nonce', 'term' => '', 'type' => 'lesson' ];

		try {
			$this->_handleAjax( self::SEARCH_ACTION );
			$this->fail( 'check_ajax_referer() must die on an invalid nonce.' );
		} catch ( WPAjaxDieStopException $e ) {
			$this->assertSame( '-1', $e->getMessage() );
		}
	}

	/** ajax_search_items() gates on edit_courses; a learner has none of the eight courses caps. */
	public function test_search_without_edit_courses_capability_is_refused() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$decoded = $this->post( self::SEARCH_ACTION, [
			'nonce' => wp_create_nonce( CourseEditor::NONCE ),
			'term'  => 'Anything',
			'type'  => 'lesson',
		] );

		$this->assertFalse( $decoded['success'] );
	}

	/* ---------------------------------------------------------------------
	 * anchor_courses_create_item
	 * ------------------------------------------------------------------- */

	public function test_create_success_returns_the_expected_shape() {
		wp_set_current_user( $this->make_admin() );

		$decoded = $this->post( self::CREATE_ACTION, [
			'nonce' => wp_create_nonce( CourseEditor::NONCE ),
			'title' => 'New Quiz',
			'type'  => 'quiz',
		] );

		$this->assertTrue( $decoded['success'], 'Response: ' . wp_json_encode( $decoded ) );
		$this->assertSame( 'New Quiz', $decoded['data']['title'] );
		$this->assertSame( 'quiz', $decoded['data']['type'] );
		$this->assertSame( 'anchor_quiz', get_post_type( $decoded['data']['id'] ) );
		$this->assertSame( 'draft', get_post_status( $decoded['data']['id'] ) );
		$this->assertArrayHasKey( 'edit_url', $decoded['data'] );
	}

	public function test_create_with_a_missing_nonce_dies_with_negative_one() {
		wp_set_current_user( $this->make_admin() );
		$_GET  = [];
		$_POST = [ 'title' => 'New Quiz', 'type' => 'quiz' ];

		try {
			$this->_handleAjax( self::CREATE_ACTION );
			$this->fail( 'check_ajax_referer() must die on a missing nonce.' );
		} catch ( WPAjaxDieStopException $e ) {
			$this->assertSame( '-1', $e->getMessage() );
		}
	}

	/**
	 * create_item() gates on edit_lessons/edit_quizzes, not edit_courses -
	 * a user with the course-editing cap but neither item cap is still
	 * refused. ajax_create_item() has no capability check of its own; the
	 * refusal comes back as the generic "could not create" 400, not a 403.
	 */
	public function test_create_without_the_relevant_item_capability_is_refused() {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		( new WP_User( $user_id ) )->add_cap( Capabilities::cap( 'edit_courses' ) );
		wp_set_current_user( $user_id );

		$decoded = $this->post( self::CREATE_ACTION, [
			'nonce' => wp_create_nonce( CourseEditor::NONCE ),
			'title' => 'Should not be created',
			'type'  => 'quiz',
		] );

		$this->assertFalse( $decoded['success'] );
		$this->assertSame( 0, self::count_posts_titled( 'Should not be created' ) );
	}

	/**
	 * Not a refusal: CourseEditor::create_item() deliberately falls back to
	 * "Untitled" for an empty title (anchor-courses/src/Admin/CourseEditor.php,
	 * '' === $title ? __('Untitled') : $title - also the documented plan,
	 * docs/superpowers/plans/2026-09-23-anchor-courses.md:3059). The AJAX
	 * endpoint must pass that fallback through rather than refusing.
	 */
	public function test_create_with_an_empty_title_falls_back_to_untitled() {
		wp_set_current_user( $this->make_admin() );

		$decoded = $this->post( self::CREATE_ACTION, [
			'nonce' => wp_create_nonce( CourseEditor::NONCE ),
			'title' => '',
			'type'  => 'quiz',
		] );

		$this->assertTrue( $decoded['success'], 'Response: ' . wp_json_encode( $decoded ) );
		$this->assertSame( 'Untitled', $decoded['data']['title'] );
	}

	/** How many posts of any type carry this exact title (0 means "none created"). */
	private static function count_posts_titled( string $title ): int {
		return count( get_posts( [
			'post_type'      => [ LessonPostType::CPT, QuizPostType::CPT ],
			'post_status'    => 'any',
			'title'          => $title,
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		] ) );
	}
}
