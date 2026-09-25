<?php
/**
 * Anchor Courses - shortcodes and template resolution (brief 22, 23).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Frontend\Templates;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

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
		( new EnrollmentService() )->enroll( $this->user, $this->course );

		$html = do_shortcode( '[anchor_course id="' . $this->course . '"]' );

		$this->assertStringNotContainsString( 'Ask us about access', $html );
	}

	public function test_progress_shortcode_reflects_completion() {
		wp_set_current_user( $this->user );
		( new EnrollmentService() )->enroll( $this->user, $this->course );

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
}
