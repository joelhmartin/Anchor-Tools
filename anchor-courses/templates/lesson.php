<?php
/**
 * Lesson page body.
 *
 * Variables: $lesson_id, $course_id, $user_id, $available (bool),
 * $complete (bool), $outline (array - Frontend\CourseOutline::build(), drawn
 * by lesson-nav.php here and by course-outline.php in lesson-layout.php),
 * $previous (int), $next (int) - the neighbouring step's id when it is a
 * LESSON, 0 when there is none or it is a quiz; kept for older overrides
 * (the bar itself now walks quizzes too).
 * Every lesson link carries the course context (Access::lesson_url()).
 *
 * The footer (the completion form, its notice and the previous/next bar) has
 * the id CourseOutline::FOOTER_ID, and "Mark complete" returns there, so
 * after completing a lesson the learner lands on the Next button.
 *
 * Theme override: anchor-courses/lesson.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Frontend\Access;
use Anchor\Courses\Frontend\Actions;
use Anchor\Courses\Frontend\CourseOutline;
use Anchor\Courses\Frontend\Templates;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$notice = Actions::notice();
?>
<div class="anchor-lesson" data-lesson="<?php echo esc_attr( (string) $lesson_id ); ?>" data-course="<?php echo esc_attr( (string) $course_id ); ?>">

	<nav class="anchor-lesson-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'anchor-schema' ); ?>">
		<a href="<?php echo esc_url( (string) get_permalink( $course_id ) ); ?>"><?php echo esc_html( get_the_title( $course_id ) ); ?></a>
		<span class="anchor-lesson-breadcrumb-sep">/</span>
		<span aria-current="page"><?php echo esc_html( get_the_title( $lesson_id ) ); ?></span>
	</nav>

	<h1 class="anchor-lesson-title"><?php echo esc_html( get_the_title( $lesson_id ) ); ?></h1>

	<?php
	/*
	 * The body always goes through the_content - never a second
	 * `if ( ! $available )` branch here. Frontend\ContentGuard hooks that
	 * same filter and substitutes the access notice for anyone
	 * Access::can_view_lesson() refuses, so this template, a feed, and a
	 * search excerpt can never disagree about who sees the real content
	 * (Task 18 review fix round, ruling (b)).
	 *
	 * Echoed as the_content returns it, NOT through wp_kses_post() (final
	 * review I3): kses strips <iframe>, so every Vimeo/YouTube embed in a
	 * lesson vanished. The body was authored by somebody allowed to edit
	 * lessons and is already filtered exactly as core filters any post
	 * body; ContentGuard is the gate.
	 */
	?>
	<div class="anchor-lesson-content"><?php echo apply_filters( 'the_content', get_post_field( 'post_content', $lesson_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- the_content output, gated by ContentGuard. ?></div>

	<div class="anchor-lesson-footer" id="<?php echo esc_attr( CourseOutline::FOOTER_ID ); ?>">
		<?php if ( '' !== $notice ) : ?>
			<p class="anchor-courses-notice" role="status"><?php echo esc_html( Actions::notice_text( $notice ) ); ?></p>
		<?php endif; ?>

		<?php if ( $available ) : ?>
			<?php if ( ! $complete ) : ?>
				<form class="anchor-lesson-complete" method="post" action="<?php echo esc_url( Actions::complete_url( $course_id, $lesson_id ) ); ?>">
					<?php wp_nonce_field( Actions::NONCE_COMPLETE . '_' . $lesson_id ); ?>
					<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $course_id ); ?>" />
					<input type="hidden" name="lesson_id" value="<?php echo esc_attr( (string) $lesson_id ); ?>" />
					<input type="hidden" name="_redirect" value="<?php echo esc_url( Access::lesson_url( $lesson_id, $course_id ) . '#' . CourseOutline::FOOTER_ID ); ?>" />
					<button type="submit" class="anchor-courses-button"><?php esc_html_e( 'Mark complete', 'anchor-schema' ); ?></button>
				</form>
			<?php else : ?>
				<p class="anchor-lesson-done"><?php esc_html_e( 'Completed', 'anchor-schema' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php echo Templates::render( 'lesson-nav', [ 'outline' => $outline ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- the template escapes its own output. ?>
	</div>
</div>
