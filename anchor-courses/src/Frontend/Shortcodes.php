<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Module;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CreditService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The Phase 1 shortcodes (brief section 23). Presentation only. */
final class Shortcodes {

	public function __construct(
		private ProgressService $progress,
		private EnrollmentService $enrollments,
		private CreditService $credits,
		private CertificateService $certificates
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
				'course_id'   => $course_id,
				'user_id'     => $user_id,
				'modules'     => Curriculum::get( $course_id ),
				'progress'    => $user_id > 0 ? $this->progress->get_course_progress( $user_id, $course_id ) : null,
				// EnrollmentService::get() returns a cancelled/expired row too
				// (it is still "the" row for this user/course) - the template
				// must not treat that as access. is_enrolled() is the boolean
				// authority for "does this learner currently have access"
				// (Task 18 review fix round, ruling (c)).
				'is_enrolled' => $user_id > 0 && $this->enrollments->is_enrolled( $user_id, $course_id ),
				'service'     => $this->progress,
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
				'enrollments' => $this->enrollments->get_for_user( $user_id ),
				'progress'    => $this->progress,
			]
		);
	}

	/**
	 * [anchor_my_credits] - the signed-in learner's own CE credits.
	 *
	 * `CreditService::for_user()`/`::total_for_user()`, scoped to
	 * `get_current_user_id()`, are the only Task 27 call sites this method
	 * uses - never `Database\CreditRepository` directly. The Task 18
	 * layering exception (a Frontend shortcode reading a repository because
	 * the owning service was locked mid-review) is closed here on purpose,
	 * not repeated (progress.md ruling).
	 */
	public function my_credits(): string {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your CE credits.', 'anchor-schema' ) . '</p>';
		}

		$credits = $this->credits->for_user( $user_id );
		if ( [] === $credits ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'No CE credits yet.', 'anchor-schema' ) . '</p>';
		}

		\ob_start();
		echo '<table class="anchor-courses-credits-table"><thead><tr>';
		\printf(
			'<th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>',
			\esc_html__( 'Course', 'anchor-schema' ),
			\esc_html__( 'Credits', 'anchor-schema' ),
			\esc_html__( 'Type', 'anchor-schema' ),
			\esc_html__( 'Awarded', 'anchor-schema' )
		);
		foreach ( $credits as $credit ) {
			\printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				\esc_html( (string) \get_the_title( $credit->course_id ) ),
				\esc_html( \number_format_i18n( $credit->credits, 1 ) ),
				\esc_html( $credit->credit_type ),
				\esc_html( \mysql2date( (string) \get_option( 'date_format' ), $credit->awarded_at ) )
			);
		}
		\printf(
			'</tbody><tfoot><tr><th>%s</th><th colspan="3">%s</th></tr></tfoot></table>',
			\esc_html__( 'Total', 'anchor-schema' ),
			\esc_html( \number_format_i18n( $this->credits->total_for_user( $user_id ), 1 ) )
		);
		return (string) \ob_get_clean();
	}

	/**
	 * [anchor_my_certificates] - the signed-in learner's own certificates,
	 * each linking to its public `/certificate/{token}/` verification page
	 * (`CertificatePage`). Scoped the same way as `my_credits()` above:
	 * `CertificateService::for_user( $user_id )` only.
	 */
	public function my_certificates(): string {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your certificates.', 'anchor-schema' ) . '</p>';
		}

		$certificates = $this->certificates->for_user( $user_id );
		if ( [] === $certificates ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'No certificates yet.', 'anchor-schema' ) . '</p>';
		}

		\ob_start();
		echo '<ul class="anchor-courses-certificates-list">';
		foreach ( $certificates as $certificate ) {
			\printf(
				'<li><a href="%s">%s</a> <span class="anchor-certificate-number">%s</span> <span>%s</span></li>',
				\esc_url( $certificate->url() ),
				\esc_html( (string) \get_the_title( $certificate->course_id ) ),
				\esc_html( $certificate->certificate_number ),
				\esc_html( \mysql2date( (string) \get_option( 'date_format' ), $certificate->issued_at ) )
			);
		}
		echo '</ul>';
		return (string) \ob_get_clean();
	}

	/**
	 * Rendered by single-lesson.php; not a public shortcode.
	 *
	 * The course is resolved by Access::course_for_lesson() - the course on
	 * the URL, else the one the learner is enrolled in (final review I2) -
	 * the same answer ContentGuard reaches for the body.
	 */
	public function render_lesson( int $lesson_id ): string {
		$user_id   = \get_current_user_id();
		$course_id = Access::course_for_lesson( $lesson_id, $user_id );

		if ( $course_id <= 0 ) {
			return '';
		}

		// Next/Prev walk LESSONS only: a quiz has no URL of its own (it
		// renders inside the course page), so linking to one would 404.
		$lessons  = \array_values(
			\array_map(
				static fn( array $item ): int => (int) $item['id'],
				\array_filter( Curriculum::items( $course_id ), static fn( array $item ): bool => 'lesson' === $item['type'] )
			)
		);
		$position = (int) \array_search( $lesson_id, $lessons, true );
		$previous = $position > 0 ? $lessons[ $position - 1 ] : 0;
		$next     = $lessons[ $position + 1 ] ?? 0;

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

	/**
	 * Rendered inside a course's curriculum item loop, never as a public
	 * shortcode (QuizPostType::CPT is not publicly_queryable - a quiz has no
	 * URL of its own; see QuizPostType.php).
	 *
	 * `templates/course.php` only calls this once its own $available check
	 * (ProgressService::is_item_available() - the same authority
	 * Frontend\Access delegates to for a lesson) is true, so a non-enrolled or
	 * sequentially-locked learner never reaches this method at all. can_start()
	 * below is a second, independent check on top of that: it also covers
	 * exhausted attempts and an active retry delay, and it self-gates a
	 * theme/integration that calls render_quiz() directly without checking
	 * $available first - either way, a learner who may not start sees the
	 * notice from can_start()'s WP_Error message, never the quiz.
	 *
	 * $course_id is the course this quiz is being rendered FOR (Task 26
	 * review, IMPORTANT): a quiz may be shared by more than one course
	 * (Curriculum::course_for_item()'s tie-break is deterministic but
	 * arbitrary - lowest course id), so a caller that already knows which
	 * course it is rendering must say so, or a learner enrolled only in the
	 * second course would be evaluated (and would start attempts) against
	 * the first. Left at 0 (the default), this falls back to
	 * course_for_item() exactly as before, for any caller that genuinely has
	 * no course context of its own.
	 */
	public function render_quiz( int $quiz_id, int $course_id = 0 ): string {
		$course_id = $course_id > 0 ? $course_id : Curriculum::course_for_item( $quiz_id, 'quiz' );
		$user_id   = \get_current_user_id();

		if ( $course_id <= 0 || $user_id <= 0 ) {
			return '';
		}

		$module = Module::instance();
		if ( ! $module instanceof Module ) {
			return '';
		}

		return Templates::render(
			'quiz',
			[
				'quiz_id'            => $quiz_id,
				'course_id'          => $course_id,
				'user_id'            => $user_id,
				'can_start'          => $module->quizzes->can_start( $user_id, $quiz_id, $course_id ),
				'attempts_remaining' => $module->quizzes->attempts_remaining( $user_id, $quiz_id ),
				'best'               => $module->quizzes->best_attempt( $user_id, $quiz_id ),
				// Gate the "Best score" line the same way the REST payload
				// gates score/points_earned/passed (Task 26 review, MINOR).
				'show_score'         => 1 === (int) $module->quizzes->settings( $quiz_id )['show_score'],
			]
		);
	}
}
