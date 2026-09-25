<?php
/**
 * Anchor Courses - shortcodes and template resolution (brief 22, 23).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Frontend\Actions;
use Anchor\Courses\Frontend\Templates;
use Anchor\Courses\Module;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Roles;

/** @group courses */
class Test_Courses_Shortcodes extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;
	private int $lesson;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course( [ 'progression_mode' => 'free', 'instructor' => 'Dr Vega' ], 'Laser Safety' );
		$this->lesson = $this->make_lesson( [], 'Optics' );
		Curriculum::save( $this->course, [ [ 'title' => 'Fundamentals', 'items' => [ [ 'type' => 'lesson', 'id' => $this->lesson ] ] ] ] );
	}

	public function test_every_phase_one_shortcode_is_registered() {
		foreach ( [ 'anchor_courses', 'anchor_course', 'anchor_course_progress', 'anchor_my_courses', 'anchor_my_credits', 'anchor_my_certificates' ] as $tag ) {
			$this->assertTrue( shortcode_exists( $tag ), "Missing shortcode [{$tag}]" );
		}
	}

	public function test_courses_index_lists_published_courses() {
		$html = do_shortcode( '[anchor_courses]' );
		$this->assertStringContainsString( 'Laser Safety', $html );
		$this->assertStringContainsString( 'anchor-courses-list', $html );
	}

	public function test_single_course_shows_curriculum_and_instructor() {
		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );
		$this->assertStringContainsString( 'Fundamentals', $html );
		$this->assertStringContainsString( 'Optics', $html );
		$this->assertStringContainsString( 'Dr Vega', $html );
	}

	/** No product, no self-enrolment: the page says how to ask, and offers no button. */
	public function test_a_course_with_no_product_shows_the_ask_us_message() {
		wp_set_current_user( $this->user );
		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringContainsString( 'Ask us about access', $html );
		$this->assertStringNotContainsString( 'anchor_courses_enroll', $html, 'There is no self-enrolment endpoint to post to.' );
		$this->assertStringNotContainsString( '<button', $html );
	}

	/**
	 * Task 18 review fix round, ruling (d) / LOW finding 4: no logged-out
	 * `[anchor_course]` coverage existed. A logged-out visitor has no user
	 * id at all, not merely an inactive enrolment - both must land on the
	 * same "ask us" CTA, never a progress bar.
	 */
	public function test_a_logged_out_visitor_sees_the_access_cta_and_no_progress_bar() {
		wp_set_current_user( 0 );

		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringContainsString( 'Ask us about access', $html );
		$this->assertStringNotContainsString( 'anchor-courses-progress', $html );
	}

	public function test_the_no_access_message_is_filterable() {
		wp_set_current_user( $this->user );
		add_filter( 'anchor_courses_no_access_message', static fn() => 'Call the Academy on 555-0100.' );

		$this->assertStringContainsString( 'Call the Academy on 555-0100.', do_shortcode( '[anchor_course id="' . $this->course . '"]' ) );

		remove_all_filters( 'anchor_courses_no_access_message' );
	}

	/** An integration (in production, WooCommerce) may supply a real CTA link. */
	public function test_a_filtered_cta_renders_as_a_link_not_a_form() {
		wp_set_current_user( $this->user );
		add_filter(
			'anchor_courses_access_cta',
			static fn( $cta ) => [ 'url' => 'https://example.test/product/laser-safety/', 'label' => 'Enrol', 'message' => '' ],
			10,
			3
		);

		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringContainsString( 'https://example.test/product/laser-safety/', $html );
		$this->assertStringContainsString( 'Enrol', $html );
		$this->assertStringNotContainsString( '<form', $html );

		remove_all_filters( 'anchor_courses_access_cta' );
	}

	/** An enrolled learner is offered neither: they get on with the course. */
	public function test_an_enrolled_learner_sees_no_access_cta() {
		wp_set_current_user( $this->user );
		Roles::grant_access( $this->user, $this->course );

		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringNotContainsString( 'Ask us about access', $html );
	}

	/**
	 * Task 18 review fix round, ruling (c). `EnrollmentService::get()`
	 * returns a cancelled/expired row too (it is still "the" row for that
	 * user/course), so gating the CTA on the row's mere truthiness hid the
	 * "ask us" message from exactly the learners who most need it, while
	 * gating the progress bar the same way showed a tracker to someone with
	 * no access at all.
	 */
	public function test_a_cancelled_learner_sees_the_access_cta_and_no_progress_bar() {
		wp_set_current_user( $this->user );
		$enrollments = new EnrollmentService();
		$enrollments->enroll( $this->user, $this->course );
		$enrollments->cancel( $this->user, $this->course );

		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringContainsString( 'Ask us about access', $html, 'A cancelled learner is not enrolled - they get the CTA back.' );
		$this->assertStringNotContainsString( 'anchor-courses-progress', $html, 'A cancelled learner has no active enrolment to show progress against.' );
	}

	public function test_an_expired_learner_sees_the_access_cta_and_no_progress_bar() {
		wp_set_current_user( $this->user );
		$enrollments = new EnrollmentService();
		$enrollments->enroll( $this->user, $this->course );
		$enrollments->expire( $this->user, $this->course );

		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringContainsString( 'Ask us about access', $html );
		$this->assertStringNotContainsString( 'anchor-courses-progress', $html );
	}

	public function test_progress_shortcode_reflects_completion() {
		wp_set_current_user( $this->user );
		Roles::grant_access( $this->user, $this->course );

		$before = do_shortcode( '[anchor_course_progress course_id="' . $this->course . '"]' );
		$this->assertStringContainsString( '0%', $before );

		( new ProgressService() )->complete_lesson( $this->user, $this->course, $this->lesson );

		$after = do_shortcode( '[anchor_course_progress course_id="' . $this->course . '"]' );
		$this->assertStringContainsString( '100%', $after );
	}

	public function test_my_courses_requires_login_and_then_lists_enrolments() {
		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'sign in', strtolower( do_shortcode( '[anchor_my_courses]' ) ) );

		wp_set_current_user( $this->user );
		( new EnrollmentService() )->enroll( $this->user, $this->course );
		$this->assertStringContainsString( 'Laser Safety', do_shortcode( '[anchor_my_courses]' ) );
	}

	public function test_output_is_escaped() {
		$nasty = $this->make_course( [], '<script>alert(1)</script>' );
		$html  = do_shortcode( '[anchor_course id="' . $nasty . '"]' );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_an_unknown_course_id_renders_nothing_loud() {
		$this->assertSame( '', trim( do_shortcode( '[anchor_course id="999999"]' ) ) );
	}

	public function test_templates_locate_prefers_a_theme_override() {
		$plugin_path = Templates::locate( 'course' );
		$this->assertStringContainsString( 'anchor-courses/templates/course.php', $plugin_path );
		$this->assertFileExists( $plugin_path );
	}

	public function test_frontend_assets_enqueue_on_a_course_page() {
		$this->go_to( get_permalink( $this->course ) );
		( new \Anchor\Courses\Frontend\Assets() )->enqueue();
		$this->assertTrue( wp_style_is( 'anchor-courses-frontend', 'enqueued' ) );
	}

	/** A theme override at anchor-courses/{name}.php wins over the plugin's bundled copy. */
	public function test_templates_locate_prefers_a_real_theme_override() {
		$theme_dir = get_stylesheet_directory() . '/anchor-courses';
		// Not wp_mkdir_p(): the test theme root is itself registered via a path
		// containing a literal ".." segment, which trips WP's anti-traversal
		// guard in wp_mkdir_p() and silently no-ops. Plain mkdir() has no such
		// guard and is what the sibling "events" fixture directory (already
		// present under this same theme root) was created with.
		if ( ! is_dir( $theme_dir ) ) {
			mkdir( $theme_dir, 0755, true );
		}
		$theme_file = $theme_dir . '/course.php';
		$this->assertFileDoesNotExist( $theme_file, 'A stray fixture from another test would invalidate this one.' );
		file_put_contents( $theme_file, '<?php // theme override fixture' );

		try {
			$result = Templates::locate( 'course' );
		} finally {
			unlink( $theme_file );
		}

		$this->assertSame( $theme_file, $result, 'A theme-supplied anchor-courses/course.php must win over the plugin template.' );
	}

	/**
	 * Task 18 review fix round, ruling (d): render_lesson()/lesson.php had
	 * zero test coverage. These cover every combination the finding named -
	 * non-enrolled (logged in and out), enrolled + available, and enrolled
	 * but locked by sequential progression - and assert the body/form only
	 * ever appear together, never the form alone (Actions::NONCE_COMPLETE
	 * is the field that lets a POST through Actions::handle_complete_lesson()
	 * at all, so its absence is what "no way to mark complete" means here).
	 */
	public function test_render_lesson_for_a_non_enrolled_logged_in_user_shows_notice_only() {
		wp_set_current_user( $this->user );
		global $post;
		$post = get_post( $this->lesson );

		$html = Module::instance()->shortcodes->render_lesson( $this->lesson );

		$this->assertStringContainsString( 'Finish the earlier lessons to unlock this one.', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( Actions::NONCE_COMPLETE, $html );
	}

	public function test_render_lesson_for_a_logged_out_user_shows_notice_only() {
		wp_set_current_user( 0 );
		global $post;
		$post = get_post( $this->lesson );

		$html = Module::instance()->shortcodes->render_lesson( $this->lesson );

		$this->assertStringContainsString( 'Finish the earlier lessons to unlock this one.', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( Actions::NONCE_COMPLETE, $html );
	}

	public function test_render_lesson_for_an_enrolled_available_lesson_shows_the_body_and_the_complete_form() {
		wp_set_current_user( $this->user );
		Roles::grant_access( $this->user, $this->course );
		wp_update_post( [ 'ID' => $this->lesson, 'post_content' => 'Real lesson body text.', 'post_excerpt' => '' ] );
		global $post;
		$post = get_post( $this->lesson );

		$html = Module::instance()->shortcodes->render_lesson( $this->lesson );

		$this->assertStringContainsString( 'Real lesson body text.', $html );
		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( Actions::NONCE_COMPLETE, $html );
	}

	public function test_render_lesson_for_an_enrolled_but_sequentially_locked_lesson_shows_notice_no_form() {
		$course = $this->make_course( [ 'progression_mode' => 'sequential' ], 'Sequential Course' );
		$first  = $this->make_lesson( [], 'First' );
		$second = $this->make_lesson( [], 'Second' );
		Curriculum::save(
			$course,
			[
				[
					'title' => 'Module',
					'items' => [
						[ 'type' => 'lesson', 'id' => $first ],
						[ 'type' => 'lesson', 'id' => $second ],
					],
				],
			]
		);

		wp_set_current_user( $this->user );
		( new EnrollmentService() )->enroll( $this->user, $course );
		global $post;
		$post = get_post( $second );

		$html = Module::instance()->shortcodes->render_lesson( $second );

		$this->assertStringContainsString( 'Finish the earlier lessons to unlock this one.', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( Actions::NONCE_COMPLETE, $html );
	}
}
