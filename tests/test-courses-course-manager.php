<?php
/**
 * Anchor Courses - front-end Course Manager shortcode.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Frontend\CourseManager;

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
}
