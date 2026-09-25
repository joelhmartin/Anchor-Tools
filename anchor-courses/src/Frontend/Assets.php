<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Front-end enqueues. Source files only; CI minifies.
 *
 * jQuery only (brief rule), and only on the screens that need it: a course or
 * lesson singular, the course archive, or any page carrying one of the Phase 1
 * shortcodes. Task 26 enqueues quiz.js behind the same gate - unconditionally
 * once that gate passes, since a quiz is never reached through its own
 * shortcode (there isn't one; QuizPostType::CPT has no public URL and
 * `render_quiz()` is called from templates/course.php's item loop, not from
 * do_shortcode()) so there is no `[anchor_quiz]` tag for is_courses_screen()
 * to look for.
 */
final class Assets {

	/** Shortcode tags that mean "this page needs our front-end assets". */
	private const SHORTCODES = [
		'anchor_courses', 'anchor_course', 'anchor_course_progress',
		'anchor_my_courses', 'anchor_my_credits', 'anchor_my_certificates',
	];

	public function __construct() {
		\add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		if ( ! $this->is_courses_screen() ) {
			return;
		}

		\wp_enqueue_style( 'anchor-courses-frontend', Module::assets_url() . 'frontend.css', [], Module::VERSION );
		\wp_enqueue_script(
			'anchor-courses-frontend',
			Module::assets_url() . 'frontend.js',
			[ 'jquery' ],
			Module::VERSION,
			true
		);
		\wp_localize_script(
			'anchor-courses-frontend',
			'anchorCourses',
			[
				'restUrl' => \esc_url_raw( \rest_url( 'anchor-courses/v1/' ) ),
				'nonce'   => \wp_create_nonce( 'wp_rest' ),
			]
		);

		\wp_enqueue_script(
			'anchor-courses-quiz',
			Module::assets_url() . 'quiz.js',
			[ 'jquery' ],
			Module::VERSION,
			true
		);
		\wp_localize_script(
			'anchor-courses-quiz',
			'anchorCoursesQuizRuntime',
			[
				'restUrl' => \esc_url_raw( \rest_url( 'anchor-courses/v1/' ) ),
				'nonce'   => \wp_create_nonce( 'wp_rest' ),
				'strings' => [
					'submit'    => \__( 'Submit quiz', 'anchor-schema' ),
					'passed'    => \__( 'Passed.', 'anchor-schema' ),
					'failed'    => \__( 'Not passed.', 'anchor-schema' ),
					'submitted' => \__( 'Quiz submitted.', 'anchor-schema' ),
					'timeUp'    => \__( 'Time is up - submitting your saved answers.', 'anchor-schema' ),
					'error'     => \__( 'Something went wrong. Please refresh and try again.', 'anchor-schema' ),
				],
			]
		);
	}

	/** Courses content, or a page carrying one of our shortcodes. */
	private function is_courses_screen(): bool {
		if ( \is_singular( [ CoursePostType::CPT, LessonPostType::CPT ] ) || \is_post_type_archive( CoursePostType::CPT ) ) {
			return true;
		}

		$post = \get_post();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		foreach ( self::SHORTCODES as $tag ) {
			if ( \has_shortcode( (string) $post->post_content, $tag ) ) {
				return true;
			}
		}
		return false;
	}
}
