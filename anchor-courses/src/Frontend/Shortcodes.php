<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The Phase 1 shortcodes (brief section 23). Presentation only. */
final class Shortcodes {

	public function __construct(
		private ProgressService $progress,
		private EnrollmentService $enrollments
	) {
		\add_shortcode( 'anchor_courses', [ $this, 'courses_index' ] );
		\add_shortcode( 'anchor_course', [ $this, 'single_course' ] );
		\add_shortcode( 'anchor_course_progress', [ $this, 'course_progress' ] );
		\add_shortcode( 'anchor_my_courses', [ $this, 'my_courses' ] );
		\add_shortcode( 'anchor_my_credits', [ $this, 'my_credits' ] );
		\add_shortcode( 'anchor_my_certificates', [ $this, 'my_certificates' ] );
	}

	public function courses_index( $atts = [] ): string {
		$atts = \shortcode_atts( [ 'limit' => 20 ], (array) $atts, 'anchor_courses' );

		$courses = \get_posts(
			[
				'post_type'      => CoursePostType::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => \max( 1, (int) $atts['limit'] ),
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		\ob_start();
		echo '<ul class="anchor-courses-list">';
		foreach ( $courses as $course ) {
			\printf(
				'<li class="anchor-courses-list-item"><a href="%s">%s</a>%s</li>',
				\esc_url( (string) \get_permalink( $course ) ),
				\esc_html( (string) $course->post_title ),
				$this->credits_badge( (int) $course->ID )
			);
		}
		echo '</ul>';
		return (string) \ob_get_clean();
	}

	private function credits_badge( int $course_id ): string {
		$credits = (float) CourseEditor::setting( $course_id, 'ce_credits' );
		if ( $credits <= 0 ) {
			return '';
		}
		return \sprintf(
			' <span class="anchor-courses-credits">%s</span>',
			\esc_html(
				\sprintf(
					/* translators: %s: number of CE credits. */
					\__( '%s CE credits', 'anchor-schema' ),
					\number_format_i18n( $credits, 1 )
				)
			)
		);
	}

	public function single_course( $atts = [] ): string {
		$atts      = \shortcode_atts( [ 'id' => 0 ], (array) $atts, 'anchor_course' );
		$course_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : (int) \get_the_ID();

		if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			return '';
		}

		$user_id = \get_current_user_id();

		return Templates::render(
			'course',
			[
				'course_id'  => $course_id,
				'user_id'    => $user_id,
				'modules'    => Curriculum::get( $course_id ),
				'progress'   => $user_id > 0 ? $this->progress->get_course_progress( $user_id, $course_id ) : null,
				'enrollment' => $user_id > 0 ? $this->enrollments->get( $user_id, $course_id ) : null,
				'service'    => $this->progress,
			]
		);
	}

	public function course_progress( $atts = [] ): string {
		$atts      = \shortcode_atts( [ 'course_id' => 0 ], (array) $atts, 'anchor_course_progress' );
		$course_id = (int) $atts['course_id'] > 0 ? (int) $atts['course_id'] : (int) \get_the_ID();
		$user_id   = \get_current_user_id();

		if ( $user_id <= 0 || CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			return '';
		}

		$progress = $this->progress->get_course_progress( $user_id, $course_id );

		\ob_start();
		\printf(
			'<div class="anchor-courses-progress"><div class="anchor-courses-bar"><span style="width:%1$s%%"></span></div>'
			. '<p class="anchor-courses-progress-label">%2$s</p></div>',
			\esc_attr( (string) $progress->percent ),
			\esc_html(
				\sprintf(
					/* translators: 1: percent complete, 2: completed items, 3: total items. */
					\__( '%1$s%% complete (%2$d of %3$d)', 'anchor-schema' ),
					\number_format_i18n( $progress->percent, 0 ),
					$progress->completed_required,
					$progress->total_required
				)
			)
		);
		return (string) \ob_get_clean();
	}

	public function my_courses(): string {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your courses.', 'anchor-schema' ) . '</p>';
		}

		return Templates::render(
			'dashboard',
			[
				'user_id'     => $user_id,
				// EnrollmentService owns writes to this table (brief 15/16/26) but
				// exposes no read-all-for-user method; Task 19/20 (role listener)
				// is concurrently editing that class, so this reads the same
				// public, side-effect-free repository method the service itself
				// calls. Fold into EnrollmentService::get_for_user() once that
				// file is free to touch again (see task-18-report.md).
				'enrollments' => EnrollmentRepository::for_user( $user_id ),
				'progress'    => $this->progress,
			]
		);
	}

	/** Filled in by Task 30 (CreditService). */
	public function my_credits(): string {
		if ( \get_current_user_id() <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your CE credits.', 'anchor-schema' ) . '</p>';
		}
		return '<p class="anchor-courses-notice">' . \esc_html__( 'No CE credits yet.', 'anchor-schema' ) . '</p>';
	}

	/** Filled in by Task 30 (CertificateService). */
	public function my_certificates(): string {
		if ( \get_current_user_id() <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your certificates.', 'anchor-schema' ) . '</p>';
		}
		return '<p class="anchor-courses-notice">' . \esc_html__( 'No certificates yet.', 'anchor-schema' ) . '</p>';
	}

	/** Rendered by single-lesson.php; not a public shortcode. */
	public function render_lesson( int $lesson_id ): string {
		$course_id = Curriculum::course_for_item( $lesson_id, 'lesson' );
		$user_id   = \get_current_user_id();

		if ( $course_id <= 0 ) {
			return '';
		}

		$items    = Curriculum::items( $course_id );
		$position = Curriculum::position( $course_id, $lesson_id, 'lesson' );
		$previous = $position > 0 ? (int) $items[ $position - 1 ]['id'] : 0;
		$next     = isset( $items[ $position + 1 ] ) ? (int) $items[ $position + 1 ]['id'] : 0;

		$available = $user_id > 0 && $this->progress->is_item_available( $user_id, $course_id, $lesson_id, 'lesson' );
		if ( $available ) {
			$this->progress->start_lesson( $user_id, $course_id, $lesson_id );
		}

		$course_progress = $user_id > 0 ? $this->progress->get_course_progress( $user_id, $course_id ) : null;

		return Templates::render(
			'lesson',
			[
				'lesson_id' => $lesson_id,
				'course_id' => $course_id,
				'user_id'   => $user_id,
				'available' => $available,
				'complete'  => $course_progress && \in_array( 'lesson:' . $lesson_id, $course_progress->completed_item_keys, true ),
				'previous'  => $previous,
				'next'      => $next,
			]
		);
	}
}
