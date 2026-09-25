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
 * shortcodes. Task 26 adds a quiz.js enqueue behind the same gate plus a
 * `[anchor_quiz]` check; nothing here needs to change to admit it.
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
